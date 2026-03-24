<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Domain;
use App\Models\Certificate;
use App\Models\Setting;
use App\Mail\CertHealthReport;
use Illuminate\Support\Facades\Mail;

class TestCertMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cert:test-mail {email? : The recipient email address} {--real : Use real expiring certificates from the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test certificate health report email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $recipient = $this->argument('email') ?? Setting::where('key', 'cert_mail_recipients')->value('value');
        
        if (!$recipient) {
            $this->error("No recipient configured. Provide one as an argument or set 'cert_mail_recipients' in settings.");
            return;
        }

        $this->info("Preparing test certificate health report for: {$recipient}");

        $changes = [];
        $expiryAlerts = [];

        if ($this->option('real')) {
            $this->info("Fetching real expiring certificates...");
            
            // Fetch some certificates that are expiring soon
            $certificates = Certificate::with('domain')
                ->where('is_ca', false)
                ->whereNotNull('expiry_date')
                ->orderBy('expiry_date', 'asc')
                ->limit(5)
                ->get();

            if ($certificates->isEmpty()) {
                $this->warn("No certificates found in the database. Falling back to simulated data.");
                $this->generateSimulatedData($changes, $expiryAlerts);
            } else {
                foreach ($certificates as $cert) {
                    $days = (int) ceil(now()->diffInDays($cert->expiry_date, false));
                    $expiryAlerts[] = [
                        'domain' => $cert->domain ?? new Domain(['name' => 'Unknown Domain']),
                        'ip' => 'N/A (Database Record)',
                        'days' => $days,
                        'expiry' => $cert->expiry_date->format('Y-m-d'),
                        'reason' => "TEST: Certificate from database expires in $days days.",
                    ];
                }
            }
        } else {
            $this->generateSimulatedData($changes, $expiryAlerts);
        }

        try {
            Mail::to($recipient)->send(new CertHealthReport($changes, $expiryAlerts));
            $this->info("Test email sent successfully to {$recipient}!");
        } catch (\Exception $e) {
            $this->error("Failed to send test email: " . $e->getMessage());
        }
    }

    protected function generateSimulatedData(&$changes, &$expiryAlerts)
    {
        $domain = Domain::first() ?? new Domain(['name' => 'example.com']);

        $changes[] = [
            'domain' => $domain,
            'ip' => '1.2.3.4',
            'old' => [
                'thumbprint_sha256' => 'old_thumbprint_simulated_1234567890',
                'error' => null,
                'issuer' => 'Old Authority',
                'expiry_date' => now()->addDays(60)->toDateTimeString(),
            ],
            'new' => [
                'thumbprint_sha256' => 'new_thumbprint_simulated_0987654321',
                'error' => null,
                'issuer' => 'New Authority',
                'expiry_date' => now()->addDays(395)->toDateTimeString(),
            ]
        ];

        $expiryAlerts[] = [
            'domain' => $domain,
            'ip' => '1.2.3.4',
            'days' => 5,
            'expiry' => now()->addDays(5)->format('Y-m-d'),
            'reason' => 'Simulated Alert: Certificate is BELOW Critical threshold (5 days remaining).',
        ];

        $expiryAlerts[] = [
            'domain' => $domain,
            'ip' => '8.8.8.8',
            'days' => 30,
            'expiry' => now()->addDays(30)->format('Y-m-d'),
            'reason' => 'Simulated Alert: Certificate matches Yellow threshold (30 days).',
        ];
    }
}
