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

        if ($domainId) {
            $domain = Domain::findOrFail($domainId);
            $this->info("Checking Certificate for {$domain->name}...");
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

    protected function sendNotification($startTime)
    {
        $recipientsString = Setting::where('key', 'cert_mail_recipients')->value('value');
        if (empty($recipientsString)) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
        if (empty($recipients)) {
            return;
        }

        // Get all logs created in this run
        $newLogs = CertHealthLog::with('domain')
            ->where('created_at', '>=', $startTime)
            ->get();

        $changes = [];
        $expiryAlerts = [];

        $yellowThreshold = (int) (Setting::where('key', 'expiry_yellow')->value('value') ?? 30);
        $redThreshold = (int) (Setting::where('key', 'expiry_red')->value('value') ?? 10);

        foreach ($newLogs as $newLog) {
            // Check for thumbprint/error changes
            $oldLog = CertHealthLog::where('domain_id', $newLog->domain_id)
                ->where('ip_address', $newLog->ip_address)
                ->where('id', '<', $newLog->id)
                ->latest()
                ->first();

            if ($oldLog) {
                $hasChange = false;
                if ($newLog->thumbprint_sha256 !== $oldLog->thumbprint_sha256) {
                    $hasChange = true;
                }
                if ($newLog->error !== $oldLog->error) {
                    $hasChange = true;
                }

                if ($hasChange) {
                    $changes[] = [
                        'domain' => $newLog->domain,
                        'ip' => $newLog->ip_address,
                        'old' => $oldLog->toArray(),
                        'new' => $newLog->toArray(),
                    ];
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
                    $cacheKey = "expiry_alert_{$domain->id}_" . substr($thumbprint, 0, 8) . "_{$thresholdType}_" . now()->toDateString();
                    if (!\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                        $expiryAlerts[] = [
                            'domain' => $domain,
                            'ip' => $newLog->ip_address,
                            'days' => $days,
                            'expiry' => $newLog->expiry_date->format('Y-m-d'),
                            'reason' => $reason,
                        ];
                        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->endOfDay());
                    }
                }
            }
        }

        if (empty($changes) && empty($expiryAlerts)) {
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
