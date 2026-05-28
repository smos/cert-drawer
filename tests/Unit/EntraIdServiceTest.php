<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\EntraIdService;
use App\Models\EntraApp;
use App\Models\EntraAppSecret;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EntraIdServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntraIdService();
    }

    public function test_get_expiring_items_respects_ignore_threshold()
    {
        Setting::updateOrCreate(['key' => 'entra_ignore_expired_days'], ['value' => '30']);
        
        $app = EntraApp::create([
            'app_id' => 'test-app',
            'object_id' => 'test-object-id',
            'display_name' => 'Test App',
            'type' => 'enterprise_app',
            'is_enabled' => true,
        ]);

        // 1. Expiring soon (10 days from now) - should be included
        $expiringSoon = EntraAppSecret::create([
            'entra_app_id' => $app->id,
            'key_id' => 'soon',
            'display_name' => 'Soon',
            'type' => 'secret',
            'end_date' => now()->addDays(10),
        ]);

        // 2. Recently expired (5 days ago) - should be included
        $recentlyExpired = EntraAppSecret::create([
            'entra_app_id' => $app->id,
            'key_id' => 'recent',
            'display_name' => 'Recent',
            'type' => 'certificate',
            'end_date' => now()->subDays(5),
        ]);

        // 3. Long expired (40 days ago) - should be ignored
        $longExpired = EntraAppSecret::create([
            'entra_app_id' => $app->id,
            'key_id' => 'old',
            'display_name' => 'Old',
            'type' => 'secret-old', // different type to avoid "most recent" logic filtering it for other reasons
            'end_date' => now()->subDays(40),
        ]);

        $items = $this->service->getExpiringItems(30);

        $this->assertTrue($items->contains('id', $expiringSoon->id), "Expiring soon item should be included");
        $this->assertTrue($items->contains('id', $recentlyExpired->id), "Recently expired item should be included");
        $this->assertFalse($items->contains('id', $longExpired->id), "Long expired item should be ignored");
    }

    public function test_get_expiring_items_with_zero_threshold_does_not_ignore()
    {
        Setting::updateOrCreate(['key' => 'entra_ignore_expired_days'], ['value' => '0']);
        
        $app = EntraApp::create([
            'app_id' => 'test-app',
            'object_id' => 'test-object-id',
            'display_name' => 'Test App',
            'type' => 'enterprise_app',
            'is_enabled' => true,
        ]);

        $longExpired = EntraAppSecret::create([
            'entra_app_id' => $app->id,
            'key_id' => 'old',
            'display_name' => 'Old',
            'type' => 'secret',
            'end_date' => now()->subDays(40),
        ]);

        $items = $this->service->getExpiringItems(30);

        $this->assertTrue($items->contains('id', $longExpired->id), "Long expired item should be included when threshold is 0");
    }
}
