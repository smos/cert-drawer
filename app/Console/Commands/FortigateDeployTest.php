<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Services\FortigateService;
use Illuminate\Console\Command;

class FortigateDeployTest extends Command
{
    protected $signature = 'fortigate:deploy-test {automation_id}';
    protected $description = 'Test certificate deployment to Fortigate';

    public function handle(FortigateService $fortigateService)
    {
        $automation = Automation::with('domain')->find($this->argument('automation_id'));

        if (!$automation) {
            $this->error("Automation not found.");
            return 1;
        }

        $this->info("Testing Fortigate deployment for {$automation->domain->name}");

        $latestCert = $automation->domain->certificates()
            ->where('status', 'issued')
            ->whereNotNull('certificate')
            ->whereNotNull('private_key')
            ->latest()
            ->first();

        if (!$latestCert) {
            $this->error("No issued certificate found.");
            return 1;
        }

        try {
            $this->info("Deploying...");
            $fortigateService->deploy($automation, $latestCert);
            $this->info("SUCCESS!");
        } catch (\Exception $e) {
            $this->error("FAILED: " . $e->getMessage());
        }

        return 0;
    }
}
