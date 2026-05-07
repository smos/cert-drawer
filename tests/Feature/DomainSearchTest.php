<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Domain;
use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class DomainSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_by_serial_and_issuer()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $domain1 = Domain::create(['name' => 'serial.test', 'is_enabled' => true]);
        $cert1 = Certificate::create([
            'domain_id' => $domain1->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'serial_number' => '123456789',
            'issuer' => 'Test Issuer A',
            'expiry_date' => now()->addYear()
        ]);

        $domain2 = Domain::create(['name' => 'other.test', 'is_enabled' => true]);
        $cert2 = Certificate::create([
            'domain_id' => $domain2->id,
            'status' => 'issued',
            'request_type' => 'manual',
            'serial_number' => '987654321',
            'issuer' => 'Other Issuer B',
            'expiry_date' => now()->addYear()
        ]);

        // Search by serial
        $response = $this->get('/domains?search=123456789');
        $response->assertStatus(200);
        $response->assertSee('serial.test');
        $response->assertDontSee('other.test');

        // Search by issuer
        $response = $this->get('/domains?search=Other%20Issuer');
        $response->assertStatus(200);
        $response->assertSee('other.test');
        $response->assertDontSee('serial.test');
    }
}
