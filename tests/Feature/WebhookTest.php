<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_test_webhook()
    {
        $admin = User::factory()->create(['guid' => null]);
        
        Http::fake([
            'https://hooks.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAs($admin)
            ->from(route('settings.index'))
            ->withoutMiddleware()
            ->post(route('settings.test-webhook'), [
            'alert_webhook_url' => 'https://hooks.example.com/test',
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('success');
        
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.example.com/test' &&
                   $request['event'] === 'test_notification' &&
                   isset($request['text']);
        });
    }

    public function test_it_signs_payload_if_secret_provided()
    {
        $admin = User::factory()->create(['guid' => null]);
        $secret = 'my-secret-key';
        
        Http::fake([
            'https://hooks.example.com/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $response = $this->actingAs($admin)->withoutMiddleware()->post(route('settings.test-webhook'), [
            'alert_webhook_url' => 'https://hooks.example.com/test',
            'alert_webhook_secret' => $secret,
        ]);

        Http::assertSent(function ($request) use ($secret) {
            return $request->hasHeader('X-Hub-Signature-256');
        });
    }
}
