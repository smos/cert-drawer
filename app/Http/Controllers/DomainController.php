<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        return $this->handleIndex($request, false);
    }

    public function caIndex(Request $request)
    {
        return $this->handleIndex($request, true);
    }

    protected function handleIndex(Request $request, bool $isCa)
    {
        $search = $request->input('search');
        $user = Auth::user();
        $showDisabled = $request->boolean('show_disabled', false);

        $query = Domain::query();

        if ($isCa) {
            // In CA view, only show domains that HAVE at least one CA certificate
            $query->whereHas('certificates', function($q) {
                $q->where('is_ca', true);
            });
        } else {
            // In normal view, show domains that have NO CA certificates 
            // OR have no certificates at all (new domains)
            $query->where(function($q) {
                $q->whereDoesntHave('certificates', function($sq) {
                    $sq->where('is_ca', true);
                })->orWhereDoesntHave('certificates');
            });
        }

        $query->with(['tags', 'certificates' => function($q) use ($isCa) {
            $q->where('is_ca', $isCa)->where('status', 'issued')->whereNotNull('expiry_date')->orderByDesc('expiry_date');
        }]);

        if (!$showDisabled) {
            $query->where('is_enabled', true);
        }

        if ($search) {
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

        $settings = \App\Models\Setting::all()->pluck('value', 'key');
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
                $latestCert = $domain->certificates->first();
            } else {
                $latestCert = $domain->certificates->where('is_ca', false)->first() ?? $domain->certificates->first();
            }

            if ($latestCert && $latestCert->expiry_date) {
                $days = now()->diffInDays($latestCert->expiry_date, false);
                $domain->latest_expiry = $latestCert->expiry_date->format('Y-m-d');
                $domain->expiry_human = $latestCert->expiry_date->diffForHumans(['parts' => 2, 'join' => true]);
                
                if ($days <= 0) {
                    $domain->health_color = '#e74c3c'; // Expired
                    $domain->health_status = 'expired';
                } elseif ($days <= $red) {
                    $domain->health_color = '#c0392b'; // Critical (Deep Red)
                    $domain->health_status = 'critical';
                } elseif ($days <= $orange) {
                    $domain->health_color = '#e67e22'; // Urgent (Orange)
                    $domain->health_status = 'urgent';
                } elseif ($days <= $yellow) {
                    $domain->health_color = '#f1c40f'; // Warning (Yellow)
                    $domain->health_status = 'warning';
                } else {
                    $domain->health_color = '#2ecc71'; // Healthy (Green)
                    $domain->health_status = 'healthy';
                }
            } else {
                $domain->health_color = '#95a5a6'; // No cert (Gray)
                $domain->health_status = 'none';
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|unique:domains,name',
            'notes' => 'nullable|string',
            'dns_monitored' => 'nullable|boolean',
        ]);

        if (!isset($validated['dns_monitored'])) {
            $validated['dns_monitored'] = !str_starts_with($validated['name'], '*.');
        }

        $domain = Domain::create($validated);
        AuditLog::log('domain_create', "Added new domain: {$domain->name}");

        return redirect()->route('domains.index')->with('success', 'Domain added successfully.');
    }

    public function show(Domain $domain)
    {
        $this->authorizeAccess($domain);

        $domain->load(['certificates' => function($query) {
            $query->orderByRaw('expiry_date DESC NULLS LAST')->orderBy('created_at', 'desc');
        }, 'tags']);

        // Enrich certificates with valid_from for history view
        foreach ($domain->certificates as $cert) {
            if ($cert->certificate) {
                $info = $this->certService->getCertInfo($cert->certificate);
                $cert->valid_from = isset($info['validFrom_time_t']) ? date('Y-m-d', $info['validFrom_time_t']) : null;
                $cert->expiry_fmt = $cert->expiry_date ? $cert->expiry_date->format('Y-m-d') : null;
            }
        }

        $settings = \App\Models\Setting::all()->pluck('value', 'key');
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

    public function importCert(Request $request, \App\Services\CertificateService $certService)
    {
        $request->validate([
            'certificate_file' => 'required|file',
        ]);

        $certData = file_get_contents($request->file('certificate_file')->getRealPath());
        $info = $certService->getCertInfo($certData);

        if (!$info) {
            return back()->withErrors(['error' => 'Failed to parse certificate file.']);
        }

        $cn = $info['subject']['commonName'] ?? $info['subject']['CN'] ?? null;
        if (!$cn) {
            return back()->withErrors(['error' => 'Could not extract Common Name from certificate.']);
        }

        $domain = Domain::firstOrCreate(['name' => $cn]);
        
        $isCa = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));

        $certificate = $domain->certificates()->create([
            'request_type' => 'manual',
            'certificate' => $certData,
            'status' => 'issued',
            'expiry_date' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
            'issuer' => $info['issuer']['CN'] ?? 'Unknown',
            'is_ca' => $isCa,
            'thumbprint_sha1' => $certService->extractThumbprint($certData, 'sha1'),
            'thumbprint_sha256' => $certService->extractThumbprint($certData, 'sha256'),
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $certData);

        AuditLog::log('cert_import', "Imported certificate for domain: {$cn}");

        $route = $isCa ? 'domains.authorities' : 'domains.index';
        return redirect()->route($route)->with('success', 'Certificate imported successfully.');
    }

    public function importPfx(Request $request, \App\Services\CertificateService $certService)
    {
        $request->validate([
            'pfx_file' => 'required|file',
            'password' => 'required|string',
        ]);

        $pfxData = file_get_contents($request->file('pfx_file')->getRealPath());
        
        try {
            $res = $certService->parsePfx($pfxData, $request->input('password'));
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to parse PFX. Ensure the password is correct.']);
        }

        $cn = $res['info']['subject']['CN'] ?? null;
        if (!$cn) {
            return back()->withErrors(['error' => 'Could not extract Common Name from PFX.']);
        }

        $domain = Domain::firstOrCreate(['name' => $cn]);
        
        // If domain already exists, check access
        if ($domain->wasRecentlyCreated === false) {
            if (!Auth::user()->canAccess($domain)) {
                return back()->withErrors(['error' => 'You do not have permission to update this domain.']);
            }
        }
        
        $isCa = (isset($res['info']['extensions']['basicConstraints']) && str_contains($res['info']['extensions']['basicConstraints'], 'CA:TRUE'));

        $certificate = $domain->certificates()->create([
            'request_type' => 'manual',
            'certificate' => $res['cert'],
            'private_key' => encrypt($res['private_key']),
            'pfx_password' => encrypt($request->input('password')),
            'status' => 'issued',
            'expiry_date' => isset($res['info']['validTo_time_t']) ? date('Y-m-d H:i:s', $res['info']['validTo_time_t']) : null,
            'issuer' => $res['info']['issuer']['CN'] ?? 'Unknown',
            'is_ca' => $isCa,
            'thumbprint_sha1' => $certService->extractThumbprint($res['cert'], 'sha1'),
            'thumbprint_sha256' => $certService->extractThumbprint($res['cert'], 'sha256'),
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $res['cert']);
        
        // Save ssl.conf template based on imported cert
        $dn = $certService->extractDnFromCert($res['info']);
        $sans = $certService->extractSansFromCert($res['info']);
        $certService->saveSslConfig($path, $dn, $sans);

        AuditLog::log('pfx_import', "Imported PFX for domain: {$cn}");

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

    public function destroy(Domain $domain)
    {
        $this->authorizeAccess($domain);
        
        if (!Auth::user()->canAccessDomainManagement()) {
            abort(403, 'You do not have permission to delete domains.');
        }

        $domainName = $domain->name;
        
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
