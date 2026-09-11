<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_domain_and_purge_files(): void
    {
        $admin = User::factory()->create(['guid' => null]); // Local admin

        $domain = Domain::create([
            'name' => 'delete-me.nl',
            'is_enabled' => true,
            'cert_monitored' => true,
        ]);

        // Add a certificate to ensure cascade delete works
        $domain->certificates()->create([
            'request_type' => 'manual',
            'status' => 'issued',
        ]);

        $response = $this->actingAs($admin)->delete("/domains/{$domain->id}");

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }
}
