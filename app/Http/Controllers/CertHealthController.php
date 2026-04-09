<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Certificate;
use App\Models\CertHealthLog;
use App\Services\CertHealthService;
use App\Services\CertificateService;
use Illuminate\Http\Request;

class CertHealthController extends Controller
{
    public function index()
    {
        $domains = Domain::where('is_enabled', true)
            ->where('cert_monitored', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->orderBy('name')
            ->get();

        foreach ($domains as $domain) {
            // Get latest check per IP AND check_type
            $latestLogs = CertHealthLog::with('certificate.domain')->where('domain_id', $domain->id)
                ->whereIn('id', function($query) use ($domain) {
                    $query->selectRaw('MAX(id)')
                        ->from('cert_health_logs')
                        ->where('domain_id', $domain->id)
                        ->groupBy('ip_address', 'check_type');
                })->get();

            $domain->health_logs = $latestLogs->groupBy('check_type');
            
            // Check for thumbprint mismatches WITHIN same check type
            $domain->mismatch = false;
            foreach ($domain->health_logs as $type => $logs) {
                $thumbprints = $logs->whereNotNull('thumbprint_sha256')->pluck('thumbprint_sha256')->unique();
                if ($thumbprints->count() > 1) {
                    $domain->mismatch = true;
                    break;
                }
            }

            // Check for mismatch BETWEEN internal and external results
            $internalThumbprints = $latestLogs->where('check_type', 'internal')->whereNotNull('thumbprint_sha256')->pluck('thumbprint_sha256')->unique();
            $externalThumbprints = $latestLogs->where('check_type', 'external')->whereNotNull('thumbprint_sha256')->pluck('thumbprint_sha256')->unique();
            
            $domain->global_mismatch = false;
            if ($internalThumbprints->isNotEmpty() && $externalThumbprints->isNotEmpty()) {
                // If the intersection is empty, it means all internal certs are different from all external certs
                if ($internalThumbprints->intersect($externalThumbprints)->isEmpty()) {
                    $domain->global_mismatch = true;
                }
            }
            
            // Any errors?
            $domain->has_errors = $latestLogs->whereNotNull('error')->count() > 0;

            // Expiry health calculation
            $settings = \App\Models\Setting::all()->pluck('value', 'key');
            $yellow = (int) ($settings['expiry_yellow'] ?? 30);
            $orange = (int) ($settings['expiry_orange'] ?? 20);
            $red = (int) ($settings['expiry_red'] ?? 10);

            $minDays = null;
            foreach ($latestLogs as $log) {
                if ($log->expiry_date) {
                    $days = (int) ceil(now()->diffInDays($log->expiry_date, false));
                    if ($minDays === null || $days < $minDays) {
                        $minDays = $days;
                    }
                }
            }

            $domain->min_days = $minDays;
            if ($domain->has_errors) {
                $domain->health_status = 'critical';
            } elseif ($domain->mismatch) {
                $domain->health_status = 'urgent';
            } elseif ($minDays !== null) {
                if ($minDays <= 0) {
                    $domain->health_status = 'expired';
                } elseif ($minDays <= $red) {
                    $domain->health_status = 'critical';
                } elseif ($minDays <= $orange) {
                    $domain->health_status = 'urgent';
                } elseif ($minDays <= $yellow) {
                    $domain->health_status = 'warning';
                } else {
                    $domain->health_status = 'healthy';
                }
            } else {
                $domain->health_status = $latestLogs->isEmpty() ? 'none' : 'healthy';
            }
        }

        return view('cert_health.index', compact('domains'));
    }

    public function runCheck(CertHealthService $certService)
    {
        $domains = Domain::where('is_enabled', true)
            ->where('cert_monitored', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->get();

        foreach ($domains as $domain) {
            $certService->monitorDomain($domain);
        }

        return back()->with('success', 'Certificate health check completed for all domains.');
    }

    public function runDomainCheck(Domain $domain, CertHealthService $certService)
    {
        if (!auth()->user()->canAccess($domain)) {
            abort(403);
        }

        $certService->monitorDomain($domain);

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Certificate check completed for ' . $domain->name]);
        }

        return back()->with('success', 'Certificate check completed for ' . $domain->name);
    }

    public function purgeLogs(Domain $domain)
    {
        if (!auth()->user()->canAccess($domain)) {
            abort(403);
        }

        CertHealthLog::where('domain_id', $domain->id)->delete();
        $domain->update(['last_cert_check' => null]);

        return back()->with('success', 'Health logs purged for ' . $domain->name);
    }

    public function importFromLog(CertHealthLog $log, CertificateService $certService)
    {
        if (!auth()->user()->canAccess($log->domain)) {
            abort(403);
        }

        if ($log->error || !$log->thumbprint_sha256) {
            return back()->withErrors(['error' => 'Cannot import a log with errors or no certificate data.']);
        }

        // Re-fetch to get full PEM
        $port = 443;
        $remote = ($log->ip_version === 'v6') ? "ssl://[{$log->ip_address}]:{$port}" : "ssl://{$log->ip_address}:{$port}";
        
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $log->domain->name,
            ],
        ]);

        $fp = @stream_socket_client($remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

        if (!$fp) {
            return back()->withErrors(['error' => "Failed to connect to {$log->ip_address} to fetch certificate: {$errstr}"]);
        }

        $params = stream_context_get_params($fp);
        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);

        if (!$certResource) {
            return back()->withErrors(['error' => 'Certificate not found in connection.']);
        }

        openssl_x509_export($certResource, $pem, true);
        $info = openssl_x509_parse($certResource);

        if (!$info) {
            return back()->withErrors(['error' => 'Failed to parse fetched certificate.']);
        }

        $thumb256 = $certService->extractThumbprint($pem, 'sha256');
        $existing = Certificate::where('thumbprint_sha256', $thumb256)->first();
        if ($existing) {
            return back()->with('success', "Certificate with this thumbprint already exists in the database (Domain: {$existing->domain->name}).");
        }

        $cn = $info['subject']['commonName'] ?? $info['subject']['CN'] ?? $log->domain->name;
        if (is_array($cn)) $cn = $cn[0] ?? $log->domain->name;

        $isCa = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));

        // Check if there is an open CSR for THIS log domain that matches the public key of the certificate
        $matchingCsr = Certificate::where('domain_id', $log->domain_id)
            ->where('status', 'requested')
            ->whereNotNull('csr')
            ->get()
            ->filter(function($c) use ($certService, $pem) {
                return $certService->comparePublicKeys($c->csr, $pem);
            })
            ->first();

        if ($matchingCsr) {
            $matchingCsr->update([
                'certificate' => $pem,
                'status' => 'issued',
                'expiry_date' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
                'issuer' => $info['issuer']['CN'] ?? 'Unknown',
                'is_ca' => $isCa,
                'thumbprint_sha1' => $certService->extractThumbprint($pem, 'sha1'),
                'thumbprint_sha256' => $certService->extractThumbprint($pem, 'sha256'),
                'serial_number' => $certService->extractSerialNumber($pem),
            ]);
            $certificate = $matchingCsr;
            $finalDomainName = $log->domain->name;
        } else {
            // Find or create domain for this certificate
            $domain = Domain::firstOrCreate(['name' => $cn]);
            
            $certificate = $domain->certificates()->create([
                'request_type' => 'manual',
                'certificate' => $pem,
                'status' => 'issued',
                'expiry_date' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
                'issuer' => $info['issuer']['CN'] ?? 'Unknown',
                'is_ca' => $isCa,
                'thumbprint_sha1' => $certService->extractThumbprint($pem, 'sha1'),
                'thumbprint_sha256' => $certService->extractThumbprint($pem, 'sha256'),
                'serial_number' => $certService->extractSerialNumber($pem),
            ]);
            $finalDomainName = $domain->name;
        }

        $path = "certificates/" . $finalDomainName . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $pem);

        return back()->with('success', "Certificate for {$cn} imported successfully.");
    }
}
