<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DnsLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class DnsService
{
    /**
     * Get DNS records for a domain. 
     * If $resolver is specified and returns no records, falls back to local DNS.
     */
    public function getDnsRecords(string $domain, ?string $resolver = null): array
    {
        try {
            $records = $this->fetchRecords($domain, $resolver);
        } catch (\Exception $e) {
            if ($resolver) {
                Log::info("DNS check for {$domain} failed using resolver {$resolver}: " . $e->getMessage() . ". Falling back to local system DNS.");
                return $this->fetchRecords($domain, null);
            }
            throw $e;
        }
        
        // Check if we got anything useful (A, AAAA, TXT or NS)
        $hasAnyData = !empty($records['A']) || !empty($records['AAAA']) || !empty($records['TXT']) || !empty($records['NS']);

        // Fallback to local DNS if resolver was used and failed to return major record types (even if no exception was thrown but records are empty)
        if ($resolver && !$hasAnyData) {
            Log::info("DNS check for {$domain} returned no major records using resolver {$resolver}, falling back to local system DNS.");
            try {
                $records = $this->fetchRecords($domain, null);
            } catch (\Exception $e) {
                throw $e;
            }
        }

        return $records;
    }

    /**
     * Internal method to fetch the records using dig.
     */
    protected function fetchRecords(string $domain, ?string $resolver): array
    {
        $types = ['A', 'AAAA', 'TXT', 'NS'];
        $records = [];
        
        $resolverPart = $resolver ? "@" . escapeshellarg($resolver) : "";
        
        foreach ($types as $type) {
            $output = [];
            $resultCode = 0;
            exec("dig {$resolverPart} +short " . escapeshellarg($domain) . " " . escapeshellarg($type) . " +tries=5 +time=5", $output, $resultCode);
            
            if ($resultCode !== 0) {
                throw new \Exception("DNS query failed for {$domain} type {$type} with exit code {$resultCode}");
            }

            $cleaned = array_filter(array_map('trim', $output));
            
            if ($type === 'TXT') {
                sort($cleaned);
            }
            $records[$type] = array_values($cleaned);
        }

        $records['SPF'] = array_values(array_filter($records['TXT'], fn($r) => stripos($r, 'v=spf1') === 0));
        
        // DMARC
        $dmarc = [];
        $resultCode = 0;
        exec("dig {$resolverPart} +short _dmarc." . escapeshellarg($domain) . " TXT +tries=5 +time=5", $dmarc, $resultCode);
        if ($resultCode !== 0) {
            throw new \Exception("DNS query failed for _dmarc.{$domain} TXT with exit code {$resultCode}");
        }
        $records['DMARC'] = array_values(array_filter(array_map('trim', $dmarc)));

        // DKIM
        $dkim_selectors = ['default', 'selector1', 'selector2'];
        $dkim_records = [];
        foreach ($dkim_selectors as $selector) {
            $fqdn = "{$selector}._domainkey.{$domain}";
            $out = [];
            $resultCode = 0;
            exec("dig {$resolverPart} +short " . escapeshellarg($fqdn) . " TXT +tries=5 +time=5", $out, $resultCode);
            if ($resultCode !== 0) {
                throw new \Exception("DNS query failed for {$fqdn} TXT with exit code {$resultCode}");
            }
            foreach ($out as $line) {
                if (stripos($line, 'v=DKIM1') !== false) {
                    $dkim_records[] = "{$selector}: {$line}";
                }
            }
        }
        $records['DKIM'] = $dkim_records;
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
            
            // Get latest records from logs or assume empty if first time
            // A better way would be to store the "current" state in the domain table or a separate column
            // For now, let's compare with the latest log entry per type
            
            foreach ($newRecords as $type => $newValues) {
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
