<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\CertHealthLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class MonitorCertWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_triggers_webhook_on_alert()
    {
        Http::fake();

        // Setup settings
        Setting::create(['key' => 'alert_webhook_url', 'value' => 'https://hooks.example.com/test']);
        Setting::create(['key' => 'alert_webhook_secret', 'value' => 'secret123']);
        Setting::create(['key' => 'expiry_yellow', 'value' => '30']);
        
        // Setup domain and log
        $domain = Domain::create(['name' => 'webhook.test', 'is_enabled' => true, 'cert_monitored' => true]);
        
        // Simulate a new log that triggers a yellow alert (30 days)
        $expiryDate = now()->addDays(30);
        
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'tcp',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'expiry_date' => $expiryDate,
            'thumbprint_sha256' => 'abc123...',
            'created_at' => now(),
        ]);

        Artisan::call('cert:monitor');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/test' &&
                   $request['event'] === 'cert_health_alert' &&
                   count($request['expiry_alerts']) > 0 &&
                   $request->hasHeader('X-Hub-Signature-256');
        });
    }

    public function test_it_does_not_trigger_webhook_if_no_url()
    {
        Http::fake();

        Setting::create(['key' => 'alert_webhook_url', 'value' => '']);
        
        $domain = Domain::create(['name' => 'nowebhook.test', 'is_enabled' => true, 'cert_monitored' => true]);
        $expiryDate = now()->addDays(30);
        
        CertHealthLog::create([
            'domain_id' => $domain->id,
            'check_type' => 'tcp',
            'ip_address' => '1.2.3.4',
            'ip_version' => 'v4',
            'expiry_date' => $expiryDate,
            'created_at' => now(),
        ]);

        Artisan::call('cert:monitor');

        Http::assertNothingSent();
    }
}
