<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Certificate;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\CertificateService;
use App\Services\CertHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DomainController extends Controller
{
    protected $certService;

    public function __construct(CertificateService $certService)
    {
        $this->certService = $certService;
    }

    protected function authorizeAccess(Domain $domain)
    {
        if (!Auth::user()->canAccess($domain)) {
            abort(403, 'Unauthorized access to this domain.');
        }
    }

    public function index(Request $request)
    {
        return $this->listDomains($request, false);
    }

    public function caIndex(Request $request)
    {
        return $this->listDomains($request, true);
    }

    protected function listDomains(Request $request, $isCa = false)
    {
        $user = Auth::user();
        $query = Domain::query();

        if ($isCa) {
            $query->whereHas('certificates', function($q) {
                $q->where('is_ca', true);
            });
        } else {
            if (!$request->has('show_disabled') || !$request->input('show_disabled')) {
                $query->where('is_enabled', true);
            }
            $query->where(function($q) {
                $q->whereDoesntHave('certificates', function($sq) {
                    $sq->where('is_ca', true);
                })->orWhereDoesntHave('certificates');
            });
        }

        if ($search = $request->input('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('tags', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('certificates', function($q) use ($search) {
                      $q->where('thumbprint_sha1', 'like', "%{$search}%")
                        ->orWhere('thumbprint_sha256', 'like', "%{$search}%");
                  });
            });
        }

        $domains = $query->orderBy('name')->get();

        $settings = Setting::all()->pluck('value', 'key');
        $yellow = (int) ($settings['expiry_yellow'] ?? 30);
        $orange = (int) ($settings['expiry_orange'] ?? 20);
        $red = (int) ($settings['expiry_red'] ?? 10);

        $filteredDomains = [];
        $statusFilter = $request->input('status');

        foreach ($domains as $domain) {
            // Check visibility permission
            if (!$user->canAccess($domain)) {
                continue;
            }

            if ($isCa) {
                $relevantCerts = $domain->certificates;
            } else {
                $relevantCerts = $domain->certificates->where('is_ca', false);
                if ($relevantCerts->isEmpty()) {
                    $relevantCerts = $domain->certificates;
                }
            }

            $activeCerts = [];
            
            foreach ($relevantCerts as $cert) {
                if ($cert->status === 'issued' && $cert->expiry_date) {
                    $days = (int) ceil(now()->diffInDays($cert->expiry_date, false));
                    $color = '#2ecc71'; // Healthy (Green)
                    $status = 'healthy';
                    
                    if ($days <= 0) {
                        $color = '#e74c3c'; // Expired
                        $status = 'expired';
                    } elseif ($days <= $red) {
                        $color = '#c0392b'; // Critical (Deep Red)
                        $status = 'critical';
                    } elseif ($days <= $orange) {
                        $color = '#e67e22'; // Urgent (Orange)
                        $status = 'urgent';
                    } elseif ($days <= $yellow) {
                        $color = '#f1c40f'; // Warning (Yellow)
                        $status = 'warning';
                    }
                    
                    $activeCerts[] = [
                        'expiry' => $cert->expiry_date->format('Y-m-d'),
                        'expiry_human' => $cert->expiry_date->diffForHumans(['parts' => 1, 'join' => true]),
                        'days' => $days,
                        'health_color' => $color,
                        'health_status' => $status,
                        'issuer' => $cert->issuer ?: 'Unknown',
                        'ca_color' => $this->getIssuerColor($cert->issuer ?: 'Unknown'),
                        'is_ca' => $cert->is_ca
                    ];
                }
            }
            $domain->active_certs = $activeCerts;

            // Determine primary health based on the "best" certificate (max days remaining)
            if (!empty($domain->active_certs)) {
                $primaryCert = collect($domain->active_certs)->sortByDesc('days')->first();
                $domain->latest_expiry = $primaryCert['expiry'];
                $domain->expiry_human = $primaryCert['expiry_human'];
                $domain->health_color = $primaryCert['health_color'];
                $domain->health_status = $primaryCert['health_status'];
            } else {
                $domain->health_color = '#95a5a6'; // No cert (Gray)
                $domain->health_status = 'none';
                $domain->latest_expiry = null;
            }

            // Apply health status filter if requested
            if (!$statusFilter || $statusFilter === $domain->health_status || ($statusFilter === 'expiring' && in_array($domain->health_status, ['warning', 'urgent', 'critical']))) {
                $filteredDomains[] = $domain;
            }
        }

        $domains = collect($filteredDomains);
        $pageTitle = $isCa ? 'Authority Certificates (Root/Intermediate)' : 'Managed Domains';

        return view('domains.index', compact('domains', 'pageTitle', 'isCa'));
    }

    protected function getIssuerColor($issuer) {
        if (!$issuer || $issuer === 'N/A' || $issuer === 'Unknown') return '#95a5a6';
        $name = trim(explode(',', $issuer)[0]);
        $name = str_replace('CN=', '', $name);
        
        $sum = 0;
        for ($i = 0; $i < strlen($name); $i++) {
            $sum += ord($name[$i]) * ($i + 1);
        }
        
        $colors = [
            '#3498db', '#9b59b6', '#2c3e50', '#e91e63', '#00bcd4',
            '#673ab7', '#3f51b5', '#2196f3', '#795548', '#607d8b'
        ];
        
        return $colors[$sum % count($colors)];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'unique:domains,name',
                'regex:#^(\*\.)?([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$#'
            ],
            'notes' => ['nullable', 'string'],
            'dns_monitored' => ['nullable', 'boolean'],
            'cert_monitored' => ['nullable', 'boolean'],
        ], [
            'name.regex' => 'The domain name format is invalid. Only alphanumeric characters, dots, and hyphens are allowed.'
        ]);

        if (!isset($validated['dns_monitored'])) {
            $validated['dns_monitored'] = !str_starts_with($validated['name'], '*.');
        }

        if (!isset($validated['cert_monitored'])) {
            $validated['cert_monitored'] = !str_starts_with($validated['name'], '*.');
        }

        $domain = Domain::create($validated);
        AuditLog::log('domain_create', "Added new domain: {$domain->name}");

        return redirect()->route('domains.index')->with('success', 'Domain added successfully.');
    }

    public function show(Domain $domain)
    {
        $this->authorizeAccess($domain);

        $domain->load(['certificates' => function($query) {
            $query->orderByRaw("CASE WHEN status != 'issued' THEN 0 ELSE 1 END")
                  ->orderBy('expiry_date', 'desc')
                  ->orderBy('created_at', 'desc');
        }, 'tags']);

        // Rebuild authority tree for all CAs and link domain certs
        $this->rebuildAuthorityTree();

        $allCas = Certificate::where('is_ca', true)->whereNotNull('certificate')->get();

        // Enrich certificates and follow chain for the drawer view
        foreach ($domain->certificates as $cert) {
            if ($cert->certificate) {
                $info = $this->certService->getCertInfo($cert->certificate);
                $cert->valid_from = isset($info['validFrom_time_t']) ? date('Y-m-d', $info['validFrom_time_t']) : null;
                $cert->expiry_fmt = $cert->expiry_date ? $cert->expiry_date->format('Y-m-d') : null;

                // Attempt to link end-entity to its immediate issuer if not already linked
                if (!$cert->issuer_certificate_id && !$cert->is_ca) {
                    $issuer = $this->findIssuer($cert, $allCas);
                    if ($issuer) {
                        Certificate::where('id', $cert->id)->update(['issuer_certificate_id' => $issuer->id]);
                        $cert->issuer_certificate_id = $issuer->id;
                    }
                }

                // Follow chain to find the top-most CA and check if it's a proper root
                $curr = $cert;
                $safety = 0;
                $chainComplete = false;
                $rootCa = null;

                while ($curr && $curr->issuer_certificate_id && $safety < 20) {
                    $safety++;
                    $issuerCert = $allCas->where('id', $curr->issuer_certificate_id)->first() ?? Certificate::find($curr->issuer_certificate_id);
                    if ($issuerCert) {
                        $rootCa = $issuerCert;
                        $curr = $issuerCert;
                        
                        // Check if this issuer is self-signed (Root)
                        $akid = $this->certService->extractAuthorityKeyIdentifier($curr->certificate);
                        $skid = $this->certService->extractSubjectKeyIdentifier($curr->certificate);
                        if ($akid && $skid) {
                            if ($akid === $skid) {
                                $chainComplete = true;
                                break;
                            }
                        } else {
                            // Fallback to Name matching if no SKID/AKID
                            $sub = $this->certService->extractFullSubjectDn($curr->certificate);
                            $iss = $this->certService->extractFullIssuerDn($curr->certificate);
                            if ($sub === $iss) {
                                $chainComplete = true;
                                break;
                            }
                        }
                    } else {
                        break;
                    }
                }

                $cert->root_ca_name = $rootCa ? ($rootCa->issuer ?? $rootCa->domain->name) : $cert->issuer;
                $cert->chain_incomplete = !$chainComplete;
                
                // If it's a self-signed CA itself, it's complete
                if ($cert->is_ca) {
                    $akid = $this->certService->extractAuthorityKeyIdentifier($cert->certificate);
                    $skid = $this->certService->extractSubjectKeyIdentifier($cert->certificate);
                    if ($akid && $skid && $akid === $skid) {
                        $cert->chain_incomplete = false;
                    } else {
                        $sub = $this->certService->extractFullSubjectDn($cert->certificate);
                        $iss = $this->certService->extractFullIssuerDn($cert->certificate);
                        if ($sub === $iss) {
                            $cert->chain_incomplete = false;
                        }
                    }
                }
            }
        }

        $settings = Setting::all()->pluck('value', 'key');
        $globalAllowedGroups = json_decode($settings['ldap_allowed_groups'] ?? '[]', true);

        $isAdmin = empty(Auth::user()->guid) || Auth::user()->canAccessDomainManagement();
        $isCaDomain = $domain->certificates->where('is_ca', true)->count() > 0;

        return response()->json([
            'domain' => $domain,
            'global_groups' => $globalAllowedGroups,
            'is_admin' => $isAdmin,
            'is_ca_domain' => $isCaDomain
        ]);
    }

    protected function findIssuer($cert, $cas)
    {
        $akid = $this->certService->extractAuthorityKeyIdentifier($cert->certificate);
        if ($akid) {
            $match = $cas->filter(function($ca) use ($akid) {
                return $this->certService->extractSubjectKeyIdentifier($ca->certificate) === $akid;
            })->first();
            if ($match) return $match;
        }

        // Fallback to Full DN matching
        $issuerDn = $this->certService->extractFullIssuerDn($cert->certificate);
        return $cas->filter(function($ca) use ($issuerDn) {
            return $this->certService->extractFullSubjectDn($ca->certificate) === $issuerDn;
        })->first();
    }

    protected function rebuildAuthorityTree()
    {
        $allCas = Certificate::where('is_ca', true)->whereNotNull('certificate')->get();
        
        foreach ($allCas as $ca) {
            // Re-evaluate issuer for every CA to handle newly imported roots
            $issuer = $this->findIssuer($ca, $allCas);
            
            if ($issuer && $issuer->id !== $ca->id) {
                if ($ca->issuer_certificate_id !== $issuer->id) {
                    Certificate::where('id', $ca->id)->update(['issuer_certificate_id' => $issuer->id]);
                    $ca->issuer_certificate_id = $issuer->id;
                }
            }
        }
    }

    public function addTag(Request $request, Domain $domain)
    {
        $this->authorizeAccess($domain);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:server,client',
        ]);

        $tag = $domain->tags()->create($validated);
        AuditLog::log('tag_add', "Added tag '{$tag->name}' to domain: {$domain->name}");

        return response()->json($tag);
    }

    public function removeTag(\App\Models\Tag $tag)
    {
        $this->authorizeAccess($tag->domain);

        $tagName = $tag->name;
        $domainName = $tag->domain->name;
        $tag->delete();
        AuditLog::log('tag_remove', "Removed tag '{$tagName}' from domain: {$domainName}");
        return response()->json(['success' => true]);
    }

    public function importCert(Request $request)
    {
        $request->validate([
            'certificate_file' => 'required|file',
        ]);

        $certData = file_get_contents($request->file('certificate_file')->getRealPath());
        $info = $this->certService->getCertInfo($certData);

        if (!$info) {
            return back()->withErrors(['error' => 'Failed to parse certificate file.']);
        }

        $isCa = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));
        
        $cn = $info['subject']['commonName'] ?? $info['subject']['CN'] ?? null;
        if (is_array($cn)) $cn = $cn[0] ?? null;

        // For CAs, allow a much broader name (spaces, etc.). For domains, keep it closer to hostname rules but still allow some flexibility.
        $regex = $isCa ? '#^[a-zA-Z0-9- \._\(\),&]+$#' : '#^(\*\.)?([a-zA-Z0-9- \._]+\.)*[a-zA-Z0-9- \._]+$#';

        if (!$cn || !preg_match($regex, $cn)) {
            return back()->withErrors(['error' => 'Could not extract a valid Common Name from certificate. Current: ' . ($cn ?: 'None')]);
        }

        $domain = Domain::firstOrCreate(['name' => $cn]);

        $thumbprint = $this->certService->extractThumbprint($certData, 'sha1');
        $existing = Certificate::where('domain_id', $domain->id)
            ->where('thumbprint_sha1', $thumbprint)
            ->first();

        if ($existing) {
            return back()->withErrors(['error' => "Certificate with thumbprint {$thumbprint} already exists for domain {$cn}."]);
        }
        
        $certificate = $domain->certificates()->create([
            'request_type' => 'manual',
            'certificate' => $certData,
            'status' => 'issued',
            'expiry_date' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
            'issuer' => $info['issuer']['CN'] ?? 'Unknown',
            'is_ca' => $isCa,
            'thumbprint_sha1' => $thumbprint,
            'thumbprint_sha256' => $this->certService->extractThumbprint($certData, 'sha256'),
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $certData);

        AuditLog::log('cert_import', "Imported certificate for domain: {$cn}");

        // Rebuild tree immediately after import
        $this->rebuildAuthorityTree();

        $route = $isCa ? 'domains.authorities' : 'domains.index';
        return redirect()->route($route)->with('success', 'Certificate imported successfully.');
    }

    public function importPfx(Request $request)
    {
        $request->validate([
            'pfx_file' => 'required|file',
            'password' => 'required|string',
        ]);

        $pfxData = file_get_contents($request->file('pfx_file')->getRealPath());
        
        try {
            $res = $this->certService->parsePfx($pfxData, $request->input('password'));
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to parse PFX. Ensure the password is correct.']);
        }

        $isCa = (isset($res['info']['extensions']['basicConstraints']) && str_contains($res['info']['extensions']['basicConstraints'], 'CA:TRUE'));

        $cn = $res['info']['subject']['commonName'] ?? $res['info']['subject']['CN'] ?? null;
        if (is_array($cn)) $cn = $cn[0] ?? null;

        $regex = $isCa ? '#^[a-zA-Z0-9- \._\(\),&]+$#' : '#^(\*\.)?([a-zA-Z0-9- \._]+\.)*[a-zA-Z0-9- \._]+$#';

        if (!$cn || !preg_match($regex, $cn)) {
            return back()->withErrors(['error' => 'Could not extract a valid Common Name from PFX. Current: ' . ($cn ?: 'None')]);
        }

        $domain = Domain::firstOrCreate(['name' => $cn]);
        
        $thumbprint = $this->certService->extractThumbprint($res['cert'], 'sha1');
        $existing = Certificate::where('domain_id', $domain->id)
            ->where('thumbprint_sha1', $thumbprint)
            ->first();

        if ($existing) {
            return back()->withErrors(['error' => "Certificate with thumbprint {$thumbprint} already exists for domain {$cn}."]);
        }
        
        // If domain already exists, check access
        if ($domain->wasRecentlyCreated === false) {
            if (!Auth::user()->canAccess($domain)) {
                return back()->withErrors(['error' => 'You do not have permission to update this domain.']);
            }
        }
        
        $certificate = $domain->certificates()->create([
            'request_type' => 'manual',
            'certificate' => $res['cert'],
            'private_key' => encrypt($res['private_key']),
            'pfx_password' => encrypt($request->input('password')),
            'status' => 'issued',
            'expiry_date' => isset($res['info']['validTo_time_t']) ? date('Y-m-d H:i:s', $res['info']['validTo_time_t']) : null,
            'issuer' => $res['info']['issuer']['CN'] ?? 'Unknown',
            'is_ca' => $isCa,
            'thumbprint_sha1' => $thumbprint,
            'thumbprint_sha256' => $this->certService->extractThumbprint($res['cert'], 'sha256'),
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $res['cert']);
        
        // Save ssl.conf template based on imported cert
        $dn = $this->certService->extractDnFromCert($res['info']);
        $sans = $this->certService->extractSansFromCert($res['info']);
        $this->certService->saveSslConfig($path, $dn, $sans);

        AuditLog::log('pfx_import', "Imported PFX for domain: {$cn}");

        // Rebuild tree immediately after import
        $this->rebuildAuthorityTree();

        $route = $isCa ? 'domains.authorities' : 'domains.index';
        return redirect()->route($route)->with('success', 'PFX imported successfully for ' . $cn);
    }

    public function updateGroups(Request $request, Domain $domain)
    {
        if (!Auth::user()->canAccessDomainManagement()) {
            abort(403, 'You do not have permission to modify domain access groups.');
        }

        $validated = $request->validate([
            'allowed_groups' => 'nullable|array',
        ]);

        $domain->update(['allowed_groups' => $validated['allowed_groups'] ?? []]);
        AuditLog::log('domain_groups_update', "Updated access groups for domain: {$domain->name}");

        return response()->json(['success' => true]);
    }

    public function updateNotes(Request $request, Domain $domain)
    {
        $this->authorizeAccess($domain);

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $domain->update(['notes' => $validated['notes']]);
        AuditLog::log('domain_notes_update', "Updated notes for domain: {$domain->name}");

        return response()->json(['success' => true]);
    }

    public function toggleStatus(Domain $domain)
    {
        $this->authorizeAccess($domain);

        $domain->update(['is_enabled' => !$domain->is_enabled]);
        $status = $domain->is_enabled ? 'enabled' : 'disabled';
        AuditLog::log('domain_toggle_status', "Domain {$domain->name} was {$status}");

        return response()->json(['success' => true, 'is_enabled' => $domain->is_enabled]);
    }

    public function toggleDnsMonitoring(Domain $domain)
    {
        $this->authorizeAccess($domain);

        if (str_starts_with($domain->name, '*.')) {
            return response()->json(['success' => false, 'message' => 'DNS monitoring is not available for wildcard domains.'], 400);
        }

        $domain->update(['dns_monitored' => !$domain->dns_monitored]);
        $status = $domain->dns_monitored ? 'enabled' : 'disabled';
        AuditLog::log('domain_toggle_dns', "DNS monitoring for {$domain->name} was {$status}");

        return response()->json(['success' => true, 'dns_monitored' => $domain->dns_monitored]);
    }

    public function toggleCertMonitoring(Domain $domain)
    {
        $this->authorizeAccess($domain);

        if (str_starts_with($domain->name, '*.')) {
            return response()->json(['success' => false, 'message' => 'Certificate health monitoring is not available for wildcard domains.'], 400);
        }

        $domain->update(['cert_monitored' => !$domain->cert_monitored]);
        $status = $domain->cert_monitored ? 'enabled' : 'disabled';
        AuditLog::log('domain_toggle_cert_health', "Certificate monitoring for {$domain->name} was {$status}");

        return response()->json(['success' => true, 'cert_monitored' => $domain->cert_monitored]);
    }

    public function destroy(Domain $domain)
    {
        $this->authorizeAccess($domain);
        
        if (!Auth::user()->canAccessDomainManagement()) {
            abort(403, 'You do not have permission to delete domains.');
        }

        $domainName = $domain->name;
        
        // Safety check: ensure domain name is not malicious before purging files
        if (str_contains($domainName, '..') || str_contains($domainName, '/') || str_contains($domainName, '\\')) {
            Log::error("Attempted to delete a domain with a malicious name: {$domainName}");
            return response()->json(['success' => false, 'message' => 'Invalid domain name for file purging.'], 400);
        }

        // Purge files
        $path = "certificates/" . $domainName;
        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($path)) {
            \Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($path);
        }

        $domain->delete();
        
        AuditLog::log('domain_delete', "Deleted domain and purged files: {$domainName}");

        return response()->json(['success' => true]);
    }
}
