<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Domain;
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

class RenewDomainAcme extends Command
{
    protected $signature = 'certificates:renew-domain {domain : The domain name to renew} {--dry-run : Only show what would be renewed} {--force-renew : Force renewal even if not expiring soon} {--force-automation : Force triggering automations even if they might otherwise be skipped}';
    protected $description = 'Renew ACME certificate for a specific domain.';

    public function handle()
    {
        $domainName = $this->argument('domain');
        $dryRun = $this->option('dry-run');
        $forceRenew = $this->option('force-renew');
        $forceAutomation = $this->option('force-automation');

        $domain = Domain::where('name', $domainName)->first();

        if (!$domain) {
            $this->error("Domain '{$domainName}' not found.");
            return 1;
        }

        if (!$domain->is_enabled) {
            $this->error("Domain '{$domainName}' is disabled.");
            return 1;
        }

        $latestCert = Certificate::where('domain_id', $domain->id)
            ->where('request_type', 'acme')
            ->where('status', 'issued')
            ->whereNull('archived_at')
            ->orderBy('expiry_date', 'desc')
            ->first();

        if (!$latestCert) {
            $this->error("No active ACME certificate found for '{$domainName}'.");
            return 1;
        }

        $thresholdDays = (int) (Setting::where('key', 'acme_renewal_days')->value('value') ?? 30);
        $expiryDate = \Carbon\Carbon::parse($latestCert->expiry_date);
        $daysUntilExpiry = now()->diffInDays($expiryDate, false);

        $this->info("Current certificate for {$domainName} expires on {$latestCert->expiry_date} ({$daysUntilExpiry} days left).");

        if ($daysUntilExpiry > $thresholdDays && !$forceRenew) {
            $this->info("Certificate is not yet due for renewal (threshold: {$thresholdDays} days). Use --force-renew to override.");
            return 0;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would proceed with renewal for {$domainName}.");
            $automations = $domain->automations;
            if ($automations->isNotEmpty()) {
                $this->info("[DRY RUN] Would trigger " . $automations->count() . " automation(s)" . ($forceAutomation ? " (FORCED)" : "") . ":");
                foreach ($automations as $automation) {
                    $this->info("[DRY RUN]   - {$automation->type} for {$automation->hostname}");
                }
            } else {
                $this->info("[DRY RUN] No automations would be triggered.");
            }
            return 0;
        }

        $this->info("Proceeding with renewal for {$domainName}...");

        try {
            // 1. Generate new CSR using existing certificate's properties
            $certService = app(CertificateService::class);
            $this->comment("Generating new CSR...");
            $csrData = $certService->generateRenewalCsr($latestCert);

            // 2. Create new Certificate record (CSR status)
            $newCert = $domain->certificates()->create([
                'request_type' => 'acme',
                'status' => 'requested',
                'csr' => $csrData['csr'],
                'private_key' => encrypt($csrData['private_key']),
            ]);

            // 3. Fulfill via AcmeService
            $this->comment("Fulfilling certificate via ACME...");
            $acmeService = app(AcmeService::class);
            $acmeService->issueCertificate($newCert);

            $this->info("Successfully renewed certificate for {$domain->name}");
            AuditLog::log('acme_manual_renewal_success', "Successfully manually renewed certificate for domain: {$domain->name}");

            // Send success email
            $recipientsString = Setting::where('key', 'cert_mail_recipients')->value('value') ?? '';
            $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
            if (!empty($recipients)) {
                try {
                    Mail::to($recipients)->send(new AcmeRenewalSuccess($newCert));
                } catch (Exception $me) {
                    $this->error("Failed to send success email alert: " . $me->getMessage());
                }
            }

            // 4. Trigger Automations
            $automations = $domain->automations;
            if ($automations->isEmpty()) {
                $this->info("No automations linked to this domain.");
            } else {
                $this->info("Triggering " . $automations->count() . " automation(s)...");
                foreach ($automations as $automation) {
                    if (!$automation->is_active && !$forceAutomation) {
                        $this->comment("Skipping inactive automation: {$automation->type} for {$automation->hostname} (use --force-automation to override)");
                        continue;
                    }

                    $this->comment("Triggering {$automation->type} automation for {$automation->hostname}...");
                    try {
                        if ($automation->type === 'kemp') {
                            app(KempService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'fortigate') {
                            app(FortigateService::class)->deploy($automation, $newCert);
                        } elseif ($automation->type === 'paloalto') {
                            app(PaloAltoService::class)->deploy($automation, $newCert);
                        }
                        
                        $warnings = session('automation_warnings', []);
                        $msg = 'Manual renewal deployment successful';
                        if (!empty($warnings)) {
                            $msg .= ' (with warnings: ' . implode('; ', $warnings) . ')';
                        }

                        $automation->logs()->create([
                            'status' => 'success',
                            'message' => $msg,
                            'details' => !empty($warnings) ? ['warnings' => $warnings] : null
                        ]);

                        AuditLog::log('automation_manual_run_success', "Manually triggered {$automation->type} deployment for: {$automation->domain->name}");
                        $this->info("  Automation successful.");
                    } catch (Exception $ae) {
                        $this->error("  Automation failed: " . $ae->getMessage());
                        
                        $automation->logs()->create([
                            'status' => 'failure',
                            'message' => 'Manual renewal deployment failed',
                            'details' => ['error' => $ae->getMessage()]
                        ]);

                        AuditLog::log('automation_manual_run_failed', "Manually triggered {$automation->type} deployment FAILED for: {$automation->domain->name}. Error: " . $ae->getMessage());

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
                    }
                }
            }

        } catch (Exception $e) {
            $this->error("Renewal failed for {$domain->name}: " . $e->getMessage());
            AuditLog::log('acme_manual_renewal_failed', "Manual renewal FAILED for {$domain->name}. Error: " . $e->getMessage());

            // Send failure email
            $recipientsString = Setting::where('key', 'cert_mail_recipients')->value('value') ?? '';
            $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
            if (!empty($recipients) && isset($latestCert)) {
                try {
                    Mail::to($recipients)->send(new AcmeRenewalFailed($latestCert, $e->getMessage()));
                } catch (Exception $me) {
                    $this->error("Failed to send email alert: " . $me->getMessage());
                }
            }
            return 1;
        }

        return 0;
    }
}
