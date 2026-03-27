<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\AcmeService;
use App\Services\CertificateService;
use App\Services\KempService;
use App\Services\FortigateService;
use App\Services\PaloAltoService;
use App\Mail\AcmeRenewalFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Exception;

class RenewAcmeCertificates extends Command
{
    protected $signature = 'certificates:renew-acme {--dry-run : Only show what would be renewed}';
    protected $description = 'Automatically renew ACME certificates that are close to expiry.';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $thresholdDays = (int) (Setting::where('key', 'acme_renewal_days')->value('value') ?? 30);
        $recipientsString = Setting::where('key', 'cert_mail_recipients')->value('value') ?? '';
        $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));

        // Get issued ACME certificates that are not archived and expiring soon
        $expiringCerts = Certificate::where('request_type', 'acme')
            ->where('status', 'issued')
            ->whereNull('archived_at')
            ->where('expiry_date', '<', now()->addDays($thresholdDays))
            ->get();

        if ($expiringCerts->isEmpty()) {
            $this->info("No ACME certificates need renewal.");
            return 0;
        }

        $this->info("Found " . $expiringCerts->count() . " certificates for renewal.");

        foreach ($expiringCerts as $cert) {
            $this->comment("Renewing certificate for {$cert->domain->name} (Expires: {$cert->expiry_date})");
            
            if ($dryRun) {
                $this->info("  [DRY RUN] Would create new CSR and fulfillment for {$cert->domain->name}");
                continue;
            }

            try {
                // 1. Generate new CSR using existing certificate's properties
                $certService = app(CertificateService::class);
                $csrData = $certService->generateRenewalCsr($cert);

                // 2. Create new Certificate record (CSR status)
                $newCert = $cert->domain->certificates()->create([
                    'request_type' => 'acme',
                    'status' => 'requested',
                    'csr' => $csrData['csr'],
                    'private_key' => encrypt($csrData['private_key']),
                ]);

                // 3. Fulfill via AcmeService
                $acmeService = app(AcmeService::class);
                $acmeService->issueCertificate($newCert);

                $this->info("  Successfully renewed certificate for {$cert->domain->name}");
                AuditLog::log('acme_auto_renewal_success', "Successfully auto-renewed certificate for domain: {$cert->domain->name}");

                // 4. Trigger Automations
                $automations = $cert->domain->automations;
                foreach ($automations as $automation) {
                    $this->comment("  Triggering {$automation->type} automation for {$automation->hostname}...");
                    try {
                        if ($automation->type === 'kemp') {
                            app(KempService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'fortigate') {
                            app(FortigateService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'paloalto') {
                            app(PaloAltoService::class)->deploy($automation, $newCert);
                        }
                        AuditLog::log('automation_auto_run_success', "Auto-triggered {$automation->type} deployment for: {$automation->domain->name}");
                    } catch (Exception $ae) {
                        $this->error("  Automation failed: " . $ae->getMessage());
                        AuditLog::log('automation_auto_run_failed', "Auto-triggered {$automation->type} deployment FAILED for: {$automation->domain->name}. Error: " . $ae->getMessage());
                    }
                }

            } catch (Exception $e) {
                $this->error("  Renewal failed for {$cert->domain->name}: " . $e->getMessage());
                AuditLog::log('acme_auto_renewal_failed', "Auto-renewal FAILED for {$cert->domain->name}. Error: " . $e->getMessage());

                if (!empty($recipients)) {
                    try {
                        Mail::to($recipients)->send(new AcmeRenewalFailed($cert, $e->getMessage()));
                        $this->info("  Email alert sent to recipients.");
                    } catch (Exception $me) {
                        $this->error("  Failed to send email alert: " . $me->getMessage());
                    }
                }
            }
        }

        return 0;
    }
}
