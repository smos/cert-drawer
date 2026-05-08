<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Services\PaloAltoService;
use App\Services\FortigateService;
use Illuminate\Console\Command;
use Exception;

class AutomationCleanup extends Command
{
    protected $signature = 'certificates:automation-cleanup {--dry-run : Only show what would be removed}';
    protected $description = 'Cleanup expired certificates on devices for all active automations (Palo Alto, Fortigate).';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $automations = Automation::whereIn('type', ['paloalto', 'fortigate'])
            ->where('is_active', true)
            ->get();

        if ($automations->isEmpty()) {
            $this->info("No active Palo Alto or Fortigate automations found.");
            return 0;
        }

        $this->info("Starting Automation cleanup for " . $automations->count() . " automations...");

        foreach ($automations as $automation) {
            $this->comment("Processing {$automation->type} at {$automation->hostname} for domain {$automation->domain->name}...");
            
            try {
                if ($dryRun) {
                    $this->info("  [DRY RUN] Would call cleanupExpiredCerts for {$automation->domain->name}");
                    continue;
                }

                $deleted = 0;
                if ($automation->type === 'paloalto') {
                    $deleted = app(PaloAltoService::class)->cleanupExpiredCerts($automation, $automation->domain->name);
                } elseif ($automation->type === 'fortigate') {
                    $deleted = app(FortigateService::class)->cleanupExpiredCerts($automation, $automation->domain->name);
                }

                $this->info("  Deleted {$deleted} certificate(s).");
                
                if ($deleted > 0) {
                    $automation->logs()->create([
                        'status' => 'success',
                        'message' => "Automated cleanup removed {$deleted} certificate(s).",
                    ]);
                }

            } catch (Exception $e) {
                $this->error("  Error: " . $e->getMessage());
                $automation->logs()->create([
                    'status' => 'error',
                    'message' => "Automated cleanup failed: " . $e->getMessage(),
                ]);
            }
        }

        $this->info("Cleanup complete.");
        return 0;
    }
}
