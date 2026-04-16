<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Certificate;
use App\Services\KempService;
use App\Services\FortigateService;
use App\Services\PaloAltoService;
use Illuminate\Console\Command;

class CheckAutomations extends Command
{
    protected $signature = 'automation:check {id? : The ID of the automation to check}';
    protected $description = 'Dry-run automation check to see if devices are up to date';

    public function handle()
    {
        $id = $this->argument('id');
        $query = Automation::with('domain');
        
        if ($id) {
            $query->where('id', $id);
        }

        $automations = $query->get();

        if ($automations->isEmpty()) {
            $this->error("No automations found.");
            return 1;
        }

        foreach ($automations as $auto) {
            $this->info("--- Checking Automation #{$auto->id} ({$auto->type} for {$auto->domain->name}) ---");
            
            $latestCert = $auto->domain->certificates()->where('status', 'issued')->latest()->first();
            
            if (!$latestCert) {
                $this->warn("  No issued certificate found for domain.");
                continue;
            }

            $this->line("  Latest Local Cert: ID #{$latestCert->id}, Serial: " . ($latestCert->serial_number ?? 'N/A') . ", Expires: {$latestCert->expiry_date}");

            try {
                $status = [];
                if ($auto->type === 'kemp') {
                    $status = app(KempService::class)->checkStatus($auto, $latestCert);
                } elseif ($auto->type === 'fortigate') {
                    $status = app(FortigateService::class)->checkStatus($auto, $latestCert);
                } elseif ($auto->type === 'paloalto') {
                    $status = app(PaloAltoService::class)->checkStatus($auto, $latestCert);
                }

                $this->line("  Predicted Device Cert Name: " . ($status['cert_name'] ?? 'N/A'));
                
                if ($status['exists_on_device']) {
                    $this->info("  [CONFIRMED] Certificate exists on device.");
                } else {
                    $this->error("  [MISSING] Certificate NOT found on device.");
                }

                if ($status['needs_update']) {
                    $this->warn("  [UPDATE NEEDED] Device is NOT using the latest certificate version.");
                } else {
                    $this->info("  [UP TO DATE] Device is using the latest certificate version.");
                }

                if (isset($status['details']['device_cert']) && isset($status['details']['local_cert'])) {
                    $dc = $status['details']['device_cert'];
                    $lc = $status['details']['local_cert'];
                    $this->line("  Certificate Comparison:");
                    $this->line("    Property           Device                                    Local (Latest)");
                    $this->line("    ------------------ ----------------------------------------  ----------------------------------------");
                    $this->line(sprintf("    Serial             %-40s  %-40s", $dc['serial'] ?? 'N/A', $lc['serial'] ?? 'N/A'));
                    $this->line(sprintf("    Thumbprint (256)   %-40s  %-40s", substr($dc['thumbprint'] ?? 'N/A', 0, 36) . '...', substr($lc['thumbprint'] ?? 'N/A', 0, 36) . '...'));
                    $this->line(sprintf("    Expires            %-40s  %-40s", $dc['expiry'] ?? 'N/A', $lc['expiry'] ?? 'N/A'));
                }

                if (!empty($status['details'])) {
                    $this->line("  Details:");
                    foreach ($status['details'] as $key => $val) {
                        if ($key === 'device_cert' || $key === 'local_cert') {
                            continue; // Show these in a separate comparison block or just skip
                        }
                        
                        if ($key === 'profiles' || $key === 'decryption_rules') {
                            $this->line("    " . str_replace('_', ' ', ucfirst($key)) . ":");
                            foreach ($val as $name => $info) {
                                $marker = $info['up_to_date'] ? "<info>✔</info>" : "<error>✘</error>";
                                $this->line("      - {$name}: Current='{$info['current_value']}' {$marker}");
                            }
                        } elseif (is_array($val)) {
                            $marker = ($val['up_to_date'] ?? false) ? "<info>✔</info>" : "<error>✘</error>";
                            $this->line("    - " . str_replace('_', ' ', ucfirst($key)) . ": Current='{$val['current_value']}' {$marker}");
                        }
                    }
                }

                if ($status['message']) {
                    $this->comment("  Message: " . $status['message']);
                }

            } catch (\Exception $e) {
                $this->error("  Error during check: " . $e->getMessage());
            }
            
            $this->line("");
        }

        return 0;
    }
}
