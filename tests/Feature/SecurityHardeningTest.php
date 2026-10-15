<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_responses(): void
    {
        $response = $this->get('/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        // 10 attempts allowed per minute
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', [
                'email' => "attempt{$i}@test.local",
                'password' => 'wrong-password',
            ]);
        }

        // 11th attempt must be throttled
        $throttledResponse = $this->post('/login', [
            'email' => 'attempt11@test.local',
            'password' => 'wrong-password',
        ]);

        $throttledResponse->assertStatus(429);
    }

    public function test_session_cookie_security_defaults(): void
    {
        $this->assertTrue(config('session.http_only'), 'Session cookie should have HttpOnly set to true.');
        $this->assertContains(config('session.same_site'), ['lax', 'strict'], 'Session SameSite should be lax or strict.');
    }
}
