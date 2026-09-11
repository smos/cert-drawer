<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use App\Models\CertHealthLog;
use App\Models\Setting;
use App\Services\CertHealthService;
use App\Mail\CertHealthReport;
use Illuminate\Support\Facades\Mail;

class MonitorCert extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cert:monitor {domain_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform TLS certificate health checks for enabled domains';

    /**
     * Execute the console command.
     */
    public function handle(CertHealthService $certService)
    {
        $domainId = $this->argument('domain_id');
        $externalUrl = Setting::where('key', 'external_poller_url')->value('value');

        if ($domainId) {
            $domain = Domain::findOrFail($domainId);
            if ($externalUrl) {
                $this->info("Checking Certificate for {$domain->name} via external poller: {$externalUrl}");
            } else {
                $this->info("Checking Certificate for {$domain->name} locally...");
            }
            $certService->monitorDomain($domain);
            $this->info("Done.");
            return;
        }

        $startTime = now();

        $interval = (int) (Setting::where('key', 'dns_check_interval')->value('value') ?? 1);
        $threshold = now()->subHours($interval);

        $domains = Domain::where('is_enabled', true)
            ->where('cert_monitored', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_cert_check')
                  ->orWhere('last_cert_check', '<=', $threshold);
            })
            ->get();

        $this->info("Monitoring Certificate Health for " . $domains->count() . " domains...");

        $bar = $this->output->createProgressBar($domains->count());
        $bar->start();

        foreach ($domains as $domain) {
            $certService->monitorDomain($domain);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->sendNotification($startTime);

        $this->info("Certificate monitoring completed.");
    }

    protected function triggerWebhooks($changes, $expiryAlerts)
    {
        $webhookService = new \App\Services\WebhookService();
        $webhookService->sendAlert($changes, $expiryAlerts);
    }

    protected function sendNotification($startTime)
    {
        $recipientsString = Setting::where('key', 'cert_mail_recipients')->value('value');
        $webhookUrl = Setting::where('key', 'cert_webhook_url')->value('value') ?? Setting::where('key', 'alert_webhook_url')->value('value');

        if (empty($recipientsString) && empty($webhookUrl)) {
            return;
        }

        // Get all logs created in this run
        $newLogs = CertHealthLog::with('domain')
            ->where('created_at', '>=', $startTime)
            ->get();

        $groupedChanges = [];
        $groupedExpiryAlerts = [];

        $yellowThreshold = (int) (Setting::where('key', 'expiry_yellow')->value('value') ?? 30);
        $redThreshold = (int) (Setting::where('key', 'expiry_red')->value('value') ?? 10);
        $notifyConnectionIssues = in_array(
            Setting::where('key', 'notify_connection_issues')->value('value'),
            ['1', 1, true, 'true'],
            true
        );

        foreach ($newLogs as $newLog) {
            // Check for thumbprint/error changes
            $oldLog = CertHealthLog::where('domain_id', $newLog->domain_id)
                ->where('check_type', $newLog->check_type)
                ->where('ip_address', $newLog->ip_address)
                ->where('id', '<', $newLog->id)
                ->latest()
                ->first();

            if ($oldLog) {
                $hasChange = false;
                $changeOldRecord = $oldLog->toArray();

                if (!$notifyConnectionIssues) {
                    // Suppress connection issues and recoveries unless there is a real certificate change
                    if ($newLog->error) {
                        // Connection failed (timeout, refused, reset, unreachable, etc.) - suppress notification
                        $hasChange = false;
                    } elseif ($oldLog->error) {
                        // Current check is healthy, but previous check had an error (recovery)
                        // Only alert if the certificate actually changed compared to the last healthy check
                        $lastHealthyLog = CertHealthLog::where('domain_id', $newLog->domain_id)
                            ->where('check_type', $newLog->check_type)
                            ->where('ip_address', $newLog->ip_address)
                            ->where('id', '<', $newLog->id)
                            ->whereNotNull('thumbprint_sha256')
                            ->latest()
                            ->first();

                        if ($lastHealthyLog && $lastHealthyLog->thumbprint_sha256 !== $newLog->thumbprint_sha256) {
                            $hasChange = true;
                            $changeOldRecord = $lastHealthyLog->toArray();
                        } else {
                            // Certificate matches previous healthy check or no prior cert; suppress recovery alert
                            $hasChange = false;
                        }
                    } else {
                        // Both old and new checks succeeded - check for certificate thumbprint change
                        if ($newLog->thumbprint_sha256 !== $oldLog->thumbprint_sha256) {
                            $hasChange = true;
                        }
                    }
                } else {
                    // Notifications on connection issues are enabled
                    if ($newLog->thumbprint_sha256 !== $oldLog->thumbprint_sha256) {
                        $hasChange = true;
                    }
                    if ($newLog->error !== $oldLog->error) {
                        $hasChange = true;
                    }
                }

                if ($hasChange) {
                    $changeKey = $newLog->domain_id . '_' .
                        $newLog->check_type . '_' .
                        ($changeOldRecord['thumbprint_sha256'] ?? '') . '_' .
                        ($newLog->thumbprint_sha256 ?? '') . '_' .
                        ($changeOldRecord['error'] ?? '') . '_' .
                        ($newLog->error ?? '');

                    if (!isset($groupedChanges[$changeKey])) {
                        $groupedChanges[$changeKey] = [
                            'domain' => $newLog->domain,
                            'check_type' => $newLog->check_type,
                            'ips' => [$newLog->ip_address],
                            'ip' => $newLog->ip_address,
                            'old' => $changeOldRecord,
                            'new' => $newLog->toArray(),
                        ];
                    } else {
                        if (!in_array($newLog->ip_address, $groupedChanges[$changeKey]['ips'])) {
                            $groupedChanges[$changeKey]['ips'][] = $newLog->ip_address;
                            $groupedChanges[$changeKey]['ip'] = implode(', ', $groupedChanges[$changeKey]['ips']);
                        }
                    }
                }
            }

            // Check for expiry alerts
            if ($newLog->expiry_date) {
                $days = (int) ceil(now()->diffInDays($newLog->expiry_date, false));
                $domain = $newLog->domain;
                $thumbprint = $newLog->thumbprint_sha256 ?? 'no_thumb';
                
                // We use a cache key to track if we've already alerted for this specific threshold today
                // Key format: expiry_alert_{domain_id}_{thumbprint}_{threshold_type}_{date}
                
                $shouldAlert = false;
                $reason = "";
                $thresholdType = null;

                if ($days == $yellowThreshold) {
                    $shouldAlert = true;
                    $reason = "Certificate matches Yellow threshold ($yellowThreshold days).";
                    $thresholdType = "yellow";
                } elseif ($days == $redThreshold) {
                    $shouldAlert = true;
                    $reason = "Certificate matches Critical threshold ($redThreshold days).";
                    $thresholdType = "critical";
                } elseif ($days < $redThreshold) {
                    $shouldAlert = true;
                    $reason = "Certificate is BELOW Critical threshold ($days days remaining).";
                    $thresholdType = "below_red";
                }

                if ($shouldAlert) {
                    $alertKey = "{$domain->id}_" . substr($thumbprint, 0, 8) . "_{$thresholdType}";
                    $cacheKey = "expiry_alert_{$alertKey}_" . now()->toDateString();
                    
                    if (isset($groupedExpiryAlerts[$alertKey])) {
                        if (!in_array($newLog->ip_address, $groupedExpiryAlerts[$alertKey]['ips'])) {
                            $groupedExpiryAlerts[$alertKey]['ips'][] = $newLog->ip_address;
                            $groupedExpiryAlerts[$alertKey]['ip'] = implode(', ', $groupedExpiryAlerts[$alertKey]['ips']);
                        }
                    } elseif (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                        $groupedExpiryAlerts[$alertKey] = [
                            'domain' => $domain,
                            'ips' => [$newLog->ip_address],
                            'ip' => $newLog->ip_address,
                            'days' => $days,
                            'expiry' => $newLog->expiry_date->format('Y-m-d'),
                            'reason' => $reason,
                        ];
                        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->endOfDay());

                        // If the certificate is expired (days <= 0), trigger Palo Alto cleanup if configured
                        if ($days <= 0) {
                            $automations = $domain->automations()->where('type', 'paloalto')->get();
                            foreach ($automations as $automation) {
                                try {
                                    $this->info("Triggering Palo Alto cleanup for {$domain->name} due to expiry...");
                                    app(\App\Services\PaloAltoService::class)->cleanupExpiredCerts($automation, $domain->name);
                                } catch (\Exception $e) {
                                    $this->error("Palo Alto cleanup failed: " . $e->getMessage());
                                }
                            }
                        }
                    }
                }
            }
        }

        // Check for cross-IP thumbprint mismatches across healthy IPs WITHIN THE SAME check_type for each monitored domain
        $domainsInRun = $newLogs->pluck('domain')->unique('id');
        foreach ($domainsInRun as $domain) {
            foreach (['internal', 'external'] as $checkType) {
                $latestLogsPerIp = $newLogs->where('domain_id', $domain->id)
                    ->where('check_type', $checkType)
                    ->whereNull('error')
                    ->whereNotNull('thumbprint_sha256')
                    ->groupBy('ip_address')
                    ->map(fn($logs) => $logs->last());

                $distinctThumbs = $latestLogsPerIp->pluck('thumbprint_sha256')->unique();

                if ($distinctThumbs->count() > 1) {
                    // Mismatch detected across IPs WITHIN THIS check_type (e.g. within internal, or within external)
                    foreach ($latestLogsPerIp->groupBy('thumbprint_sha256') as $thumb => $logsForThumb) {
                        $mismatchIps = $logsForThumb->pluck('ip_address')->unique()->values()->all();
                        $firstLog = $logsForThumb->first();

                        // Check if this thumbprint group is already captured in $groupedChanges
                        $alreadyCaptured = false;
                        foreach ($groupedChanges as $gk => $gc) {
                            if ($gc['domain']->id === $domain->id 
                                && ($gc['check_type'] ?? null) === $checkType 
                                && ($gc['new']['thumbprint_sha256'] ?? '') === $thumb) {
                                $alreadyCaptured = true;
                                $groupedChanges[$gk]['has_mismatch'] = true;
                                break;
                            }
                        }

                        if (!$alreadyCaptured) {
                            $changeKey = $domain->id . '_' . $checkType . '_mismatch_' . $thumb;
                            $groupedChanges[$changeKey] = [
                                'domain' => $domain,
                                'check_type' => $checkType,
                                'ips' => $mismatchIps,
                                'ip' => implode(', ', $mismatchIps),
                                'old' => [
                                    'thumbprint_sha256' => null,
                                    'error' => null,
                                ],
                                'new' => $firstLog->toArray(),
                                'has_mismatch' => true,
                                'is_divergent' => true,
                            ];
                        }
                    }

                    // Mark all changes for this domain and check_type as having a mismatch
                    foreach ($groupedChanges as $gk => $gc) {
                        if ($gc['domain']->id === $domain->id && ($gc['check_type'] ?? null) === $checkType) {
                            $groupedChanges[$gk]['has_mismatch'] = true;
                        }
                    }
                }
            }
        }

        $changes = array_values($groupedChanges);
        $expiryAlerts = array_values($groupedExpiryAlerts);

        if (empty($changes) && empty($expiryAlerts)) {
            return;
        }

        $this->triggerWebhooks($changes, $expiryAlerts);

        if (empty($recipientsString)) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
        if (empty($recipients)) {
            return;
        }

        try {
            Mail::to($recipients)->send(new CertHealthReport($changes, $expiryAlerts));
            $this->info("Notification email sent to " . implode(', ', $recipients));
        } catch (\Exception $e) {
            $this->error("Failed to send Certificate notification: " . $e->getMessage());
        }
    }
}
