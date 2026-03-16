<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use App\Models\DnsLog;
use App\Models\Setting;
use App\Services\DnsService;
use App\Mail\DnsHealthReport;
use Illuminate\Support\Facades\Mail;

class MonitorDns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dns:monitor {domain_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor DNS records for all enabled domains';

    /**
     * Execute the console command.
     */
    public function handle(DnsService $dnsService)
    {
        $domainId = $this->argument('domain_id');

        if ($domainId) {
            $domain = Domain::findOrFail($domainId);
            $this->info("Monitoring DNS for {$domain->name}...");
            $dnsService->monitorDomain($domain);
            $this->info("Done.");
            return;
        }

        $startTime = now();

        $interval = (int) (Setting::where('key', 'dns_check_interval')->value('value') ?? 1);
        $threshold = now()->subHours($interval);

        $domains = Domain::where('dns_monitored', true)
            ->where('is_enabled', true)
            ->where('name', 'not like', '*.%')
            ->whereDoesntHave('certificates', function ($q) {
                $q->where('is_ca', true);
            })
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_dns_check')
                  ->orWhere('last_dns_check', '<=', $threshold);
            })
            ->get();

        $this->info("Monitoring DNS for " . $domains->count() . " domains...");

        $bar = $this->output->createProgressBar($domains->count());
        $bar->start();

        foreach ($domains as $domain) {
            $dnsService->monitorDomain($domain);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->sendNotification($startTime);

        $this->info("DNS monitoring completed.");
    }

    protected function sendNotification($startTime)
    {
        $recipientsString = Setting::where('key', 'dns_mail_recipients')->value('value');
        if (empty($recipientsString)) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
        if (empty($recipients)) {
            return;
        }

        $changes = DnsLog::with('domain')
            ->where('created_at', '>=', $startTime)
            ->get();

        if ($changes->isEmpty()) {
            $this->info("No DNS changes detected. Skipping notification email.");
            return;
        }

        try {
            Mail::to($recipients)->send(new DnsHealthReport($changes));
            $this->info("Notification email sent to " . implode(', ', $recipients));
        } catch (\Exception $e) {
            $this->error("Failed to send DNS notification: " . $e->getMessage());
        }
    }
}
