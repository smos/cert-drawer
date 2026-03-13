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
        $records = $this->fetchRecords($domain, $resolver);
        
        // Check if we got anything useful (A, AAAA, TXT or NS)
        $hasAnyData = !empty($records['A']) || !empty($records['AAAA']) || !empty($records['TXT']) || !empty($records['NS']);

        // Fallback to local DNS if resolver was used and failed to return major record types
        if ($resolver && !$hasAnyData) {
            Log::info("DNS check for {$domain} failed using resolver {$resolver}, falling back to local system DNS.");
            $records = $this->fetchRecords($domain, null);
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
            exec("dig {$resolverPart} +short " . escapeshellarg($domain) . " " . escapeshellarg($type) . " +tries=5", $output);
            $cleaned = array_filter(array_map('trim', $output));
            
            if ($type === 'TXT') {
                sort($cleaned);
            }
            $records[$type] = array_values($cleaned);
        }

        $records['SPF'] = array_values(array_filter($records['TXT'], fn($r) => stripos($r, 'v=spf1') === 0));
        
        // DMARC
        $dmarc = [];
        exec("dig {$resolverPart} +short _dmarc." . escapeshellarg($domain) . " TXT +tries=5", $dmarc);
        $records['DMARC'] = array_values(array_filter(array_map('trim', $dmarc)));

        // DKIM
        $dkim_selectors = ['default', 'selector1', 'selector2'];
        $dkim_records = [];
        foreach ($dkim_selectors as $selector) {
            $fqdn = "{$selector}._domainkey.{$domain}";
            $out = [];
            exec("dig {$resolverPart} +short " . escapeshellarg($fqdn) . " TXT +tries=5", $out);
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
