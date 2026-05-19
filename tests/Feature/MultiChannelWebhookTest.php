<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Setting;
use App\Models\User;
use App\Models\Domain;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MultiChannelWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_routes_dns_webhook_to_correct_url()
    {
        Http::fake();
        
        Setting::create(['key' => 'dns_webhook_url', 'value' => 'https://hooks.example.com/dns-test']);
        
        $service = new WebhookService();
        $service->sendDnsAlert(collect([]));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/dns-test' &&
                   $request['event'] === 'dns_health_alert';
        });
    }

    public function test_it_routes_entra_webhook_to_correct_url()
    {
        Http::fake();
        
        Setting::create(['key' => 'entra_webhook_url', 'value' => 'https://hooks.example.com/entra-test']);
        
        $service = new WebhookService();
        $service->sendEntraAlert(collect([]));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/entra-test' &&
                   $request['event'] === 'entra_expiry_alert';
        });
    }

    public function test_it_routes_automation_webhook_to_correct_url()
    {
        Http::fake();
        
        Setting::create(['key' => 'automation_webhook_url', 'value' => 'https://hooks.example.com/auto-test']);
        
        $domain = Domain::create(['name' => 'test.com']);
        $cert = $domain->certificates()->create(['status' => 'issued', 'request_type' => 'custom']);
        $automation = $domain->automations()->create(['type' => 'kemp', 'hostname' => 'kemp1', 'config' => []]);

        $service = new WebhookService();
        $service->sendAutomationAlert($automation, $cert, 'success', 'Deployed');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/auto-test' &&
                   $request['event'] === 'automation_alert';
        });
    }

    public function test_it_falls_back_to_alert_webhook_for_cert_type()
    {
        Http::fake();
        
        Setting::create(['key' => 'alert_webhook_url', 'value' => 'https://hooks.example.com/legacy-cert']);
        
        $service = new WebhookService();
        $service->sendAlert([], []);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/legacy-cert' &&
                   $request['event'] === 'cert_health_alert';
        });
    }

    public function test_it_prefers_cert_webhook_over_alert_webhook()
    {
        Http::fake();
        
        Setting::create(['key' => 'alert_webhook_url', 'value' => 'https://hooks.example.com/legacy-cert']);
        Setting::create(['key' => 'cert_webhook_url', 'value' => 'https://hooks.example.com/new-cert']);
        
        $service = new WebhookService();
        $service->sendAlert([], []);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/new-cert';
        });
    }
}
