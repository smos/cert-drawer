<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Domain;
use App\Services\PaloAltoService;
use App\Services\KempService;
use App\Services\FortigateService;
use Illuminate\Console\Command;
use Exception;

class TestExpiredCleanup extends Command
{
    protected $signature = 'certificates:test-expired-cleanup {domain : The domain name to test with} {--dry-run : Only show what would happen}';
    protected $description = 'Simulate an expired certificate to verify cleanup and automation triggers.';

    public function handle()
    {
        $domainName = $this->argument('domain');
        $dryRun = $this->option('dry-run');

        $domain = Domain::where('name', $domainName)->first();

        if (!$domain) {
            $this->error("Domain '{$domainName}' not found.");
            return 1;
        }

        // We need a recent issued certificate to simulate with
        $cert = $domain->certificates()
            ->where('status', 'issued')
            ->orderBy('expiry_date', 'desc')
            ->first();

        if (!$cert) {
            $this->error("No issued certificate found for '{$domainName}'.");
            return 1;
        }

        $this->info("Simulating expiry scenario for '{$domain->name}'...");

        // 1. Verification of Cleanup Logic (Palo Alto specific)
        $paloAutomations = $domain->automations()->where('type', 'paloalto')->get();
        if ($paloAutomations->isEmpty()) {
            $this->comment("No Palo Alto automations found for cleanup testing.");
        } else {
            $this->info("Checking Palo Alto device(s) for cleanup targets...");
            $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domain->name);
            $prefix = "auto_{$safeName}_";
            $latestCertName = "auto_{$safeName}_{$cert->id}";

            foreach ($paloAutomations as $automation) {
                $this->info("Connecting to Palo Alto: {$automation->hostname}...");
                try {
                    $paloService = app(PaloAltoService::class);
                    $deviceCerts = $paloService->listCerts($automation);
                    
                    $matchingCerts = array_filter($deviceCerts, function($c) use ($prefix) {
                        return str_starts_with($c['name'], $prefix);
                    });

                    if (empty($matchingCerts)) {
                        $this->comment("  No certificates matching '{$prefix}*' found on device.");
                        continue;
                    }

                    $this->info("  Found " . count($matchingCerts) . " certificate(s) matching '{$prefix}*':");
                    foreach ($matchingCerts as $mCert) {
                        $name = $mCert['name'];
                        $expiry = $mCert['expiry'] ?? 'unknown';
                        $isLatest = ($name === $latestCertName);
                        
                        $statusText = $isLatest ? "[LATEST VERSION]" : "";
                        
                        // Determine if it needs removal
                        $parts = explode('_', $name);
                        $id = end($parts);
                        $localCert = Certificate::find($id);
                        
                        $expiryTime = !empty($mCert['expiry']) ? strtotime($mCert['expiry']) : null;
                        $isExpiredOnDevice = $expiryTime && $expiryTime < time();
                        $isOldInDb = !$localCert || $localCert->archived_at || ($localCert->expiry_date && $localCert->expiry_date->isPast());

                        if ($isLatest) {
                            $this->line("  - {$name} (Expiry: {$expiry}) {$statusText} -> KEEP");
                        } elseif ($isExpiredOnDevice || $isOldInDb) {
                            $reason = $isExpiredOnDevice ? "EXPIRED ON DEVICE" : "ARCHIVED/EXPIRED IN DB";
                            $this->warn("  - {$name} (Expiry: {$expiry}) -> REMOVE ({$reason})");
                        } else {
                            $this->line("  - {$name} (Expiry: {$expiry}) -> KEEP (Valid/Active)");
                        }
                    }

                    if (!$dryRun) {
                        $this->comment("  Executing cleanup logic...");
                        $deletedCount = $paloService->cleanupExpiredCerts($automation, $domain->name);
                        $this->info("  Successfully removed {$deletedCount} certificate(s).");
                    } else {
                        $this->info("  [DRY RUN] Would call PaloAltoService::cleanupExpiredCerts to remove identified certificates.");
                    }
                } catch (Exception $e) {
                    $this->error("  Palo Alto Error: " . $e->getMessage());
                }
            }
        }

        // 2. Verification of Automation Logic (General Deployment)
        $allAutomations = $domain->automations;
        if ($allAutomations->isEmpty()) {
            $this->comment("No general automations found for deployment testing.");
        } else {
            $this->info("\nVerifying deployment logic for all automations...");
            foreach ($allAutomations as $automation) {
                $this->comment("Target: {$automation->type} at {$automation->hostname}");
                if (!$dryRun) {
                    try {
                        if ($automation->type === 'kemp') {
                            app(KempService::class)->deploy($automation, $cert);
                        } elseif ($automation->type === 'fortigate') {
                            app(FortigateService::class)->deploy($automation, $cert);
                        } elseif ($automation->type === 'paloalto') {
                            app(PaloAltoService::class)->deploy($automation, $cert);
                        }
                        $this->info("  Deployment successful.");
                    } catch (Exception $e) {
                        $this->error("  Deployment failed: " . $e->getMessage());
                    }
                } else {
                    $this->info("  [DRY RUN] Would call {$automation->type} deploy service for cert ID {$cert->id}");
                }
            }
        }

        $this->info("\nTest simulation complete.");
        return 0;
    }
}
