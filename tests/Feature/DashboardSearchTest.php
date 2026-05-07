<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Domain;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class DashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_filters_dashboard_calendar_by_search()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $expiryDate = Carbon::now()->addDays(5);

        $domain1 = Domain::create(['name' => 'matched.test', 'is_enabled' => true]);
        Certificate::create([
            'domain_id' => $domain1->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'expiry_date' => $expiryDate,
            'issuer' => 'Matched Issuer'
        ]);

        $domain2 = Domain::create(['name' => 'hidden.test', 'is_enabled' => true]);
        Certificate::create([
            'domain_id' => $domain2->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'expiry_date' => $expiryDate,
            'issuer' => 'Hidden Issuer'
        ]);

        // No search - see both
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('matched.test');
        $response->assertSee('hidden.test');

        // Search for matched - only see matched
        $response = $this->get('/?search=matched');
        $response->assertStatus(200);
        $response->assertSee('matched.test');
        $response->assertDontSee('hidden.test');

        // Search for specific issuer
        $response = $this->get('/?search=Hidden%20Issuer');
        $response->assertStatus(200);
        $response->assertSee('hidden.test');
        $response->assertDontSee('matched.test');
    }
}
