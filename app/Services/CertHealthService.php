<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\CertHealthLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

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
        
        // Resolve A and AAAA records
        $ips = $this->resolveIps($domain->name, $resolver);

        // Fallback to local DNS if resolver was used and failed to return records
        if (empty($ips) && $resolver) {
            Log::info("Cert health DNS check for {$domain->name} returned no IPs using resolver {$resolver}, falling back to local system DNS.");
            $ips = $this->resolveIps($domain->name, null);
        }

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
            'ip_address' => $ip,
            'ip_version' => $version,
            'thumbprint_sha256' => $thumbprint,
            'issuer' => $issuer,
            'expiry_date' => $expiry,
            'error' => $error,
        ]);
    }
}
