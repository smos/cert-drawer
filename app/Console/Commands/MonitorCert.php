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

        foreach ($newLogs as $newLog) {
            // Find the previous log for this domain and IP
            $oldLog = CertHealthLog::where('domain_id', $newLog->domain_id)
                ->where('ip_address', $newLog->ip_address)
                ->where('id', '<', $newLog->id)
                ->latest()
                ->first();

            if (!$oldLog) {
                // First time check, we can consider it a "change" if it's an error?
                // Or maybe just skip. User said "when certificate changes are detected".
                // Let's only alert on changes from a previous state.
                continue;
            }

            $hasChange = false;
            
            // Check for thumbprint change
            if ($newLog->thumbprint_sha256 !== $oldLog->thumbprint_sha256) {
                $hasChange = true;
            }
            
            // Check for error status change
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

        if (empty($changes)) {
            return;
        }

        try {
            Mail::to($recipients)->send(new CertHealthReport($changes));
            $this->info("Notification email sent to " . implode(', ', $recipients));
        } catch (\Exception $e) {
            $this->error("Failed to send Certificate notification: " . $e->getMessage());
        }
    }
}
