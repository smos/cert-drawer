<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Services\PaloAltoService;
use Illuminate\Console\Command;

class PaloAltoDeployTest extends Command
{
    protected $signature = 'paloalto:deploy-test {automation_id}';
    protected $description = 'Test certificate deployment to Palo Alto';

    public function handle(PaloAltoService $paloAltoService)
    {
        $automation = Automation::with('domain')->find($this->argument('automation_id'));

        if (!$automation) {
            $this->error("Automation not found.");
            return 1;
        }

        $this->info("Testing Palo Alto deployment for {$automation->domain->name}");

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
            $this->info("Attempting deployment via PaloAltoService...");
            $paloAltoService->deploy($automation, $latestCert);
            $this->info("Deployment SUCCESSFUL!");
        } catch (\Exception $e) {
            $this->error("Deployment FAILED: " . $e->getMessage());
            
            $this->info("\nFetching current certificates from Palo Alto to debug...");
            try {
                $certs = $paloAltoService->listCerts($automation);
                $this->info("Current certificates on device:");
                foreach ($certs as $c) {
                    $this->line(" - " . ($c['name'] ?? 'Unknown') . " (CN: " . ($c['common_name'] ?? 'N/A') . ", Expiry: " . ($c['expiry'] ?? 'N/A') . ")");
                }
            } catch (\Exception $le) {
                $this->error("Failed to list certs: " . $le->getMessage());
            }
        }

        return 0;
    }
}
