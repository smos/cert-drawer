<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DnsLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DnsService
{
    /**
     * Get DNS records for a domain. 
     * If $resolver is specified and fails (exception) or returns no records, falls back to local DNS.
     */
    public function getDnsRecords(string $domain, ?string $resolver = null): array
    {
        $externalUrl = Setting::where('key', 'external_poller_url')->value('value');

        if (!empty($externalUrl)) {
            try {
                $response = Http::timeout(30)->post($externalUrl, [
                    'domain' => $domain,
                    'type' => 'dns',
                    'resolver' => $resolver,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning("External DNS poller at {$externalUrl} failed for {$domain}: " . $response->body() . ". Falling back to local.");
            } catch (\Exception $e) {
                Log::warning("External DNS poller at {$externalUrl} error for {$domain}: " . $e->getMessage() . ". Falling back to local.");
            }
        }

        try {
            $records = $this->fetchRecords($domain, $resolver);
            
            // If we used a resolver but got nothing, try local fallback for internal domains
            $hasAnyData = !empty($records['A']) || !empty($records['AAAA']) || !empty($records['TXT']) || !empty($records['NS']);
            if ($resolver && !$hasAnyData) {
                Log::info("DNS check for {$domain} returned no major records using resolver {$resolver}. Falling back to local system DNS.");
                return $this->fetchRecords($domain, null);
            }
            
            return $records;
        } catch (\Exception $e) {
            if ($resolver) {
                Log::info("DNS check for {$domain} failed using resolver {$resolver}: " . $e->getMessage() . ". Falling back to local system DNS.");
                return $this->fetchRecords($domain, null);
            }
            throw $e;
        }
    }

    /**
     * Internal method to fetch the records using dig.
     */
    protected function fetchRecords(string $domain, ?string $resolver): array
    {
        $types = ['A', 'AAAA', 'TXT', 'NS', 'CNAME'];
        $records = [];
        
        $resolverPart = $resolver ? "@" . escapeshellarg($resolver) : "";
        
        foreach ($types as $type) {
            $output = [];
            $resultCode = 0;
            // Use +noall +answer to get the full record line, which includes the type
            exec("dig {$resolverPart} +noall +answer " . escapeshellarg($domain) . " " . escapeshellarg($type) . " +tries=5 +time=5", $output, $resultCode);
            
            if ($resultCode !== 0) {
                throw new \Exception("DNS query failed for {$domain} type {$type} with exit code {$resultCode}");
            }

            $cleaned = [];
            foreach ($output as $line) {
                // Dig answer format: name.  ttl  IN  TYPE  value
                // We split by whitespace and look for the type in the 4th column (index 3)
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5 && strtoupper($parts[3]) === $type) {
                    // The value is everything from the 5th column onwards
                    $value = implode(' ', array_slice($parts, 4));
                    $cleaned[] = trim($value, '"'); // Consistent trimming for comparisons
                }
            }
            
            if ($type === 'TXT' || $type === 'CNAME') {
                sort($cleaned);
            }
            $records[$type] = array_values($cleaned);
        }

        $records['SPF'] = array_values(array_filter($records['TXT'], fn($r) => stripos($r, 'v=spf1') === 0));
        
        // DMARC
        $dmarcOut = [];
        $resultCode = 0;
        exec("dig {$resolverPart} +noall +answer _dmarc." . escapeshellarg($domain) . " TXT +tries=5 +time=5", $dmarcOut, $resultCode);
        if ($resultCode !== 0) {
            throw new \Exception("DNS query failed for _dmarc.{$domain} TXT with exit code {$resultCode}");
        }
        $dmarcValues = [];
        foreach ($dmarcOut as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5 && strtoupper($parts[3]) === 'TXT') {
                $val = trim(implode(' ', array_slice($parts, 4)), '"');
                if (stripos($val, 'v=DMARC1') !== false) {
                    $dmarcValues[] = $val;
                }
            }
        }
        $records['DMARC'] = $dmarcValues;

        // DKIM
        $dkim_selectors = ['default', 'selector1', 'selector2'];
        $dkim_records = [];
        foreach ($dkim_selectors as $selector) {
            $fqdn = "{$selector}._domainkey.{$domain}";
            $out = [];
            $resultCode = 0;
            exec("dig {$resolverPart} +noall +answer " . escapeshellarg($fqdn) . " TXT +tries=5 +time=5", $out, $resultCode);
            if ($resultCode !== 0) {
                throw new \Exception("DNS query failed for {$fqdn} TXT with exit code {$resultCode}");
            }
            foreach ($out as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 5 && strtoupper($parts[3]) === 'TXT') {
                    $val = trim(implode(' ', array_slice($parts, 4)), '"');
                    if (stripos($val, 'v=DKIM1') !== false) {
                        $dkim_records[] = "{$selector}: {$val}";
                    }
                }
            }
        }
        $records['DKIM'] = array_values(array_filter($dkim_records));
        sort($records['DKIM']);

        return $records;
    }

    /**
     * Run DNS check for a domain and log changes.
     */
    public function monitorDomain(Domain $domain): void
    {
        if (str_starts_with($domain->name, '*.')) {
            return;
        }

        $resolver = Setting::where('key', 'dns_resolver')->value('value') ?? '8.8.8.8';
        
        try {
            $newRecords = $this->getDnsRecords($domain->name, $resolver);
            
            // Define all record types we want to track
            $trackedTypes = ['A', 'AAAA', 'TXT', 'NS', 'CNAME', 'SPF', 'DMARC', 'DKIM'];
            
            foreach ($trackedTypes as $type) {
                $newValues = $newRecords[$type] ?? [];
                
                $latestLog = DnsLog::where('domain_id', $domain->id)
                    ->where('record_type', $type)
                    ->latest()
                    ->first();
                
                $oldValues = $latestLog ? $latestLog->new_value : [];

                // Compare
                sort($oldValues);
                sort($newValues);

                if (json_encode($oldValues) !== json_encode($newValues)) {
                    DnsLog::create([
                        'domain_id' => $domain->id,
                        'record_type' => $type,
                        'old_value' => $oldValues,
                        'new_value' => $newValues,
                    ]);
                }
            }

            $domain->update(['last_dns_check' => now()]);

        } catch (\Exception $e) {
            Log::error("DNS Monitoring failed for {$domain->name}: " . $e->getMessage());
        }
    }
}
