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
use App\Mail\AcmeRenewalSuccess;
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

        // Get the latest issued ACME certificate for each domain that is not archived
        $latestCertIds = Certificate::where('request_type', 'acme')
            ->where('status', 'issued')
            ->whereNull('archived_at')
            ->selectRaw('MAX(id) as id')
            ->groupBy('domain_id');

        // Filter those latest certificates by expiry date and ensure domain is enabled
        $expiringCerts = Certificate::whereIn('id', $latestCertIds)
            ->where('expiry_date', '<', now()->addDays($thresholdDays))
            ->whereHas('domain', function($query) {
                $query->where('is_enabled', true);
            })
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

                // Archive the old certificate since it's now superseded
                $cert->update(['archived_at' => now()]);

                // Send success email
                if (!empty($recipients)) {
                    try {
                        Mail::to($recipients)->send(new AcmeRenewalSuccess($newCert));
                    } catch (Exception $me) {
                        $this->error("  Failed to send success email alert: " . $me->getMessage());
                    }
                }

                // Trigger Success Webhook
                try {
                    app(\App\Services\WebhookService::class)->sendAcmeRenewalAlert($newCert, 'success');
                } catch (Exception $we) {
                    $this->error("  Failed to trigger success webhook: " . $we->getMessage());
                }

                // 4. Trigger Automations
                $automations = $cert->domain->automations;
                foreach ($automations as $automation) {
                    if (!$automation->is_active) {
                        $this->comment("  Skipping inactive automation: {$automation->type} for {$automation->hostname}");
                        continue;
                    }

                    $this->comment("  Triggering {$automation->type} automation for {$automation->hostname}...");
                    try {
                        if ($automation->type === 'kemp') {
                            app(KempService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'fortigate') {
                            app(FortigateService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'paloalto') {
                            app(PaloAltoService::class)->deploy($automation, $newCert);
                        }
                        
                        $warnings = session('automation_warnings', []);
                        $msg = 'Auto-renewal deployment successful';
                        if (!empty($warnings)) {
                            $msg .= ' (with warnings: ' . implode('; ', $warnings) . ')';
                        }

                        $automation->logs()->create([
                            'status' => 'success',
                            'message' => $msg,
                            'details' => !empty($warnings) ? ['warnings' => $warnings] : null
                        ]);

                        AuditLog::log('automation_auto_run_success', "Auto-triggered {$automation->type} deployment for: {$automation->domain->name}");

                        // Trigger Automation Success Webhook
                        try {
                            app(\App\Services\WebhookService::class)->sendAutomationAlert($automation, $newCert, 'success', $msg, !empty($warnings) ? ['warnings' => $warnings] : null);
                        } catch (Exception $we) {
                            $this->error("  Failed to trigger automation success webhook: " . $we->getMessage());
                        }
                    } catch (Exception $ae) {
                        $this->error("  Automation failed: " . $ae->getMessage());
                        
                        $automation->logs()->create([
                            'status' => 'failure',
                            'message' => 'Auto-renewal deployment failed',
                            'details' => ['error' => $ae->getMessage()]
                        ]);

                        AuditLog::log('automation_auto_run_failed', "Auto-triggered {$automation->type} deployment FAILED for: {$automation->domain->name}. Error: " . $ae->getMessage());

                        // Send Automation Failure Email
                        $autoRecipientsString = Setting::where('key', 'automation_mail_recipients')->value('value') ?? '';
                        $autoRecipients = array_filter(array_map('trim', explode(',', $autoRecipientsString)));
                        if (!empty($autoRecipients)) {
                            try {
                                Mail::to($autoRecipients)->send(new \App\Mail\AutomationFailed($automation, $newCert, $ae->getMessage()));
                            } catch (Exception $me) {
                                $this->error("  Failed to send automation email: " . $me->getMessage());
                            }
                        }

                        // Trigger Automation Failure Webhook
                        try {
                            app(\App\Services\WebhookService::class)->sendAutomationAlert($automation, $newCert, 'failure', $ae->getMessage());
                        } catch (Exception $we) {
                            $this->error("  Failed to trigger automation failure webhook: " . $we->getMessage());
                        }
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

                // Trigger Failure Webhook
                try {
                    app(\App\Services\WebhookService::class)->sendAcmeRenewalAlert($cert, 'failure', $e->getMessage());
                } catch (Exception $we) {
                    $this->error("  Failed to trigger failure webhook: " . $we->getMessage());
                }
            }
        }

        return 0;
    }
}
