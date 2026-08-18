<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\CertHealthLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class CertHealthService
{
    /**
     * Monitor certificate for a domain across all resolved IPs.
     */
    public function monitorDomain(Domain $domain): void
    {
        if (str_starts_with($domain->name, '*.')) {
            return;
        }

        $resolver = Setting::where('key', 'dns_resolver')->value('value') ?? '8.8.8.8';
        $externalUrl = Setting::where('key', 'external_poller_url')->value('value');

        // If External Poller is configured, EVERYTHING must go through it.
        if (!empty($externalUrl)) {
            Log::info("CertHealthService: External Poller configured. Routing ALL checks for {$domain->name} to {$externalUrl}");
            try {
                $apiKey = Setting::where('key', 'poller_api_key')->value('value');
                $response = Http::withoutVerifying()
                    ->withHeaders(['X-Poller-Key' => $apiKey])
                    ->timeout(45) // Increased timeout for dual checks
                    ->post($externalUrl, [
                    'domain' => $domain->name,
                    'type' => 'certificate',
                    'resolvers' => [
                        'internal' => 'external', // Poller local DNS is giving internal IPs
                        'external' => $resolver,   // Resolver setting is giving public IPs
                    ],
                ]);

                if ($response->successful()) {
                    $results = $response->json();
                    if (is_array($results)) {
                        Log::info("CertHealthService: External Cert check successful for {$domain->name}");
                        
                        foreach (['internal', 'external'] as $type) {
                            if (isset($results[$type]) && is_array($results[$type])) {
                                foreach ($results[$type] as $ipResult) {
                                    CertHealthLog::create([
                                        'domain_id' => $domain->id,
                                        'check_type' => $type,
                                        'ip_address' => $ipResult['ip_address'] ?? 'Unknown',
                                        'ip_version' => $ipResult['ip_version'] ?? 'v4',
                                        'thumbprint_sha256' => $ipResult['thumbprint_sha256'] ?? null,
                                        'issuer' => $ipResult['issuer'] ?? null,
                                        'expiry_date' => $ipResult['expiry_date'] ?? null,
                                        'error' => $ipResult['error'] ?? null,
                                    ]);

                                    $thumbprint = $ipResult['thumbprint_sha256'] ?? null;
                                    $error = $ipResult['error'] ?? null;
                                    if ($thumbprint && !$error) {
                                        $existing = \App\Models\Certificate::where('thumbprint_sha256', $thumbprint)->first();
                                        if (!$existing) {
                                            if (!empty($ipResult['pem'])) {
                                                $pem = $ipResult['pem'];
                                                $info = @openssl_x509_parse($pem);
                                                if ($info) {
                                                    $this->autoImportCert($domain, $pem, $info, $thumbprint);
                                                } else {
                                                    $this->fetchAndImportCert($domain, $ipResult['ip_address'] ?? null, $ipResult['ip_version'] ?? null);
                                                }
                                            } else {
                                                $this->fetchAndImportCert($domain, $ipResult['ip_address'] ?? null, $ipResult['ip_version'] ?? null);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        $domain->update(['last_cert_check' => now()]);
                        return;
                    }
                }
                Log::error("CertHealthService: External Cert poller at {$externalUrl} returned status " . $response->status() . " for {$domain->name}. Body: " . $response->body());
            } catch (\Exception $e) {
                Log::error("CertHealthService: External Cert poller at {$externalUrl} error for {$domain->name}: " . $e->getMessage());
            }
            return; // Don't fall back to local if external poller is configured but failed
        }

        // NO External Poller - Perform local (internal) check only
        Log::info("CertHealthService: No external poller, performing local internal check for {$domain->name}");
        
        $ips = [];
        // Resolve using local system DNS (internal DNS)
        $ips = array_merge($ips, $this->resolveIps($domain->name, null));

        // Deduplicate IPs
        $uniqueIps = [];
        foreach ($ips as $ipData) {
            $uniqueIps[$ipData['ip']] = $ipData;
        }
        $ips = array_values($uniqueIps);

        foreach ($ips as $ipData) {
            $this->checkIp($domain, $ipData['ip'], $ipData['version']);
        }

        $domain->update(['last_cert_check' => now()]);
    }

    protected function resolveIps(string $domain, ?string $resolver): array
    {
        $ips = [];
        $resolverPart = $resolver ? "@" . escapeshellarg($resolver) : "";
        
        // Resolve IPv4
        $output4 = [];
        exec("dig {$resolverPart} +short " . escapeshellarg($domain) . " A +tries=3", $output4);
        foreach (array_filter(array_map('trim', $output4)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = ['ip' => $ip, 'version' => 'v4'];
            }
        }

        // Resolve IPv6
        $output6 = [];
        exec("dig {$resolverPart} +short " . escapeshellarg($domain) . " AAAA +tries=3", $output6);
        foreach (array_filter(array_map('trim', $output6)) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ips[] = ['ip' => $ip, 'version' => 'v6'];
            }
        }

        return $ips;
    }

    protected function checkIp(Domain $domain, string $ip, string $version): void
    {
        $port = 443;
        $host = $domain->name;
        
        // For IPv6, we need to wrap the IP in brackets for stream_socket_client
        $remote = ($version === 'v6') ? "ssl://[{$ip}]:{$port}" : "ssl://{$ip}:{$port}";
        
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $error = null;
        $thumbprint = null;
        $issuer = null;
        $expiry = null;

        $fp = @stream_socket_client($remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

        if ($fp) {
            $params = stream_context_get_params($fp);
            if (isset($params['options']['ssl']['peer_certificate'])) {
                $cert = $params['options']['ssl']['peer_certificate'];
                $info = openssl_x509_parse($cert);
                
                if ($info) {
                    $issuer = $info['issuer']['CN'] ?? 'Unknown';
                    $expiry = date('Y-m-d H:i:s', $info['validTo_time_t']);
                    
                    // Generate SHA256 Thumbprint
                    $thumbprint = openssl_x509_fingerprint($cert, 'sha256');

                    // Export PEM and auto-import
                    openssl_x509_export($cert, $pem, true);
                    $this->autoImportCert($domain, $pem, $info, $thumbprint);
                }
            }
            fclose($fp);
        } else {
            $error = "Connection failed: {$errstr} ({$errno})";
        }

        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'internal',
            'ip_address' => $ip,
            'ip_version' => $version,
            'thumbprint_sha256' => $thumbprint,
            'issuer' => $issuer,
            'expiry_date' => $expiry,
            'error' => $error,
        ]);
    }

    protected function autoImportCert(Domain $domain, string $pem, array $info, string $thumbprint): void
    {
        try {
            $existing = \App\Models\Certificate::where('thumbprint_sha256', $thumbprint)->first();
            if ($existing) {
                return;
            }

            $certService = app(\App\Services\CertificateService::class);
            $cn = $info['subject']['commonName'] ?? $info['subject']['CN'] ?? $domain->name;
            if (is_array($cn)) $cn = $cn[0] ?? $domain->name;

            $isCa = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));

            // Check if there is an open CSR for THIS domain that matches the public key of the certificate
            $matchingCsr = \App\Models\Certificate::where('domain_id', $domain->id)
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
                    'thumbprint_sha256' => $thumbprint,
                    'serial_number' => $certService->extractSerialNumber($pem),
                ]);
                $certificate = $matchingCsr;
                $finalDomainName = $domain->name;
            } else {
                $targetDomain = Domain::firstOrCreate(['name' => $cn]);
                
                $certificate = $targetDomain->certificates()->create([
                    'request_type' => 'manual',
                    'certificate' => $pem,
                    'status' => 'issued',
                    'expiry_date' => isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null,
                    'issuer' => $info['issuer']['CN'] ?? 'Unknown',
                    'is_ca' => $isCa,
                    'thumbprint_sha1' => $certService->extractThumbprint($pem, 'sha1'),
                    'thumbprint_sha256' => $thumbprint,
                    'serial_number' => $certService->extractSerialNumber($pem),
                ]);
                $finalDomainName = $targetDomain->name;
            }

            $path = "certificates/" . $finalDomainName . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
            \Illuminate\Support\Facades\Storage::disk('local')->put($path . "/certificate.cer", $pem);
            
            Log::info("CertHealthService: Automatically imported new certificate for {$finalDomainName} (Thumbprint: {$thumbprint})");
        } catch (\Exception $e) {
            Log::error("CertHealthService: Failed to auto-import certificate for {$domain->name}: " . $e->getMessage());
        }
    }

    protected function fetchAndImportCert(Domain $domain, ?string $ip = null, ?string $version = null): void
    {
        try {
            $port = 443;
            $host = $domain->name;
            
            if ($ip) {
                $remote = ($version === 'v6') ? "ssl://[{$ip}]:{$port}" : "ssl://{$ip}:{$port}";
            } else {
                $remote = "ssl://{$host}:{$port}";
            }
            
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);

            $fp = @stream_socket_client($remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
            if ($fp) {
                $params = stream_context_get_params($fp);
                if (isset($params['options']['ssl']['peer_certificate'])) {
                    $cert = $params['options']['ssl']['peer_certificate'];
                    $info = openssl_x509_parse($cert);
                    if ($info) {
                        $thumbprint = openssl_x509_fingerprint($cert, 'sha256');
                        openssl_x509_export($cert, $pem, true);
                        $this->autoImportCert($domain, $pem, $info, $thumbprint);
                    }
                }
                fclose($fp);
            }
        } catch (\Exception $e) {
            Log::error("CertHealthService: Failed to fetch and import certificate for {$domain->name}: " . $e->getMessage());
        }
    }
}
