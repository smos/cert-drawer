<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_settings_update_handles_arrays_correctly()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withoutMiddleware()->post(route('auth.settings.update'), [
            'ldap_host' => 'ldap.example.com',
            'access_groups_dns' => ['cn=dns-admins,dc=example,dc=com', 'cn=net-admins,dc=example,dc=com'],
            'some_custom_array' => ['value1', 'value2']
        ]);

        $response->assertRedirect(route('auth.settings.index'));
        $response->assertSessionHas('success');

        $this->assertEquals('ldap.example.com', Setting::where('key', 'ldap_host')->value('value'));
        
        $dnsGroups = Setting::where('key', 'access_groups_dns')->value('value');
        $this->assertJson($dnsGroups);
        $this->assertEquals(['cn=dns-admins,dc=example,dc=com', 'cn=net-admins,dc=example,dc=com'], json_decode($dnsGroups, true));

        $customArray = Setting::where('key', 'some_custom_array')->value('value');
        $this->assertJson($customArray);
        $this->assertEquals(['value1', 'value2'], json_decode($customArray, true));
    }

    public function test_general_settings_update_handles_arrays_correctly()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->withoutMiddleware()->post(route('settings.update'), [
            'app_name' => 'Cert Drawer Test',
            'notification_emails' => ['admin@example.com', 'tech@example.com']
        ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('success');

        $this->assertEquals('Cert Drawer Test', Setting::where('key', 'app_name')->value('value'));
        
        $emails = Setting::where('key', 'notification_emails')->value('value');
        $this->assertJson($emails);
        $this->assertEquals(['admin@example.com', 'tech@example.com'], json_decode($emails, true));
    }
}
