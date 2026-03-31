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
                    openssl_x509_export($cert, $pem);
                    $thumbprint = hash('sha256', base64_decode(
                        preg_replace('/\-+BEGIN CERTIFICATE\-+|\-+END CERTIFICATE\-+|\n|\r/', '', $pem)
                    ));
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
}
