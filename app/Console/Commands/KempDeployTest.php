<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Services\KempService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class KempDeployTest extends Command
{
    protected $signature = 'kemp:deploy-test {automation_id}';
    protected $description = 'Test certificate deployment to Kemp';

    public function handle(KempService $kempService)
    {
        $automation = Automation::with('domain')->find($this->argument('automation_id'));

        if (!$automation) {
            $this->error("Automation not found.");
            return 1;
        }

        $this->info("Testing deployment for {$automation->domain->name} to {$automation->hostname}");

        $latestCert = $automation->domain->certificates()
            ->where('status', 'issued')
            ->whereNotNull('certificate')
            ->whereNotNull('private_key')
            ->latest()
            ->first();

        if (!$latestCert) {
            $this->error("No issued certificate with private key found for this domain.");
            return 1;
        }

        $this->info("Found latest cert: ID {$latestCert->id}, Issued at {$latestCert->created_at}");

        try {
            $this->info("Attempting deployment via KempService...");
            $kempService->deploy($automation, $latestCert);
            $this->info("Deployment SUCCESSFUL!");
        } catch (\Exception $e) {
            $this->error("Deployment FAILED: " . $e->getMessage());
            
            // If it's a Kemp API error, let's see if we can get more info by listing certs first
            $this->info("\nFetching current certificates from Kemp to debug...");
            try {
                $certs = $kempService->listCerts($automation);
                $this->info("Current certificates on device:");
                foreach ($certs as $c) {
                    $this->line(" - " . ($c['name'] ?? 'Unknown'));
                }
            } catch (\Exception $le) {
                $this->error("Failed to list certs: " . $le->getMessage());
            }
        }

        return 0;
    }
}
