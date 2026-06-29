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

    public function test_sync_does_not_spam_audit_logs_for_readded_secrets()
    {
        Setting::updateOrCreate(['key' => 'entra_tenant_id'], ['value' => 'test-tenant']);
        Setting::updateOrCreate(['key' => 'entra_client_id'], ['value' => 'test-client']);
        Setting::updateOrCreate(['key' => 'entra_client_secret'], ['value' => 'test-secret']);

        \Illuminate\Support\Facades\Http::fake([
            'https://login.microsoftonline.com/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'mock-token']),
            'https://graph.microsoft.com/v1.0/applications*' => \Illuminate\Support\Facades\Http::response([
                'value' => [
                    [
                        'id' => 'obj-123',
                        'appId' => 'app-123',
                        'displayName' => 'Test App',
                        'passwordCredentials' => [
                            [
                                'keyId' => 'cred-1',
                                'displayName' => 'Client Secret 1',
                                'startDateTime' => now()->subDays(10)->toIso8601String(),
                                'endDateTime' => now()->addYear()->toIso8601String(),
                                'hint' => 'abc',
                            ]
                        ],
                        'keyCredentials' => [],
                    ]
                ]
            ]),
            'https://graph.microsoft.com/v1.0/servicePrincipals*' => \Illuminate\Support\Facades\Http::response([
                'value' => [
                    [
                        'id' => 'sp-123',
                        'appId' => 'app-123',
                        'displayName' => 'Test App',
                        'appOwnerOrganizationId' => 'my-org',
                        'passwordCredentials' => [
                            [
                                'keyId' => 'cred-sp-1',
                                'displayName' => 'SP Secret 1',
                                'startDateTime' => now()->subDays(10)->toIso8601String(),
                                'endDateTime' => now()->addYear()->toIso8601String(),
                                'hint' => 'def',
                            ]
                        ],
                        'keyCredentials' => [],
                    ]
                ]
            ]),
            'https://graph.microsoft.com/beta/applications/*' => \Illuminate\Support\Facades\Http::response([
                'onPremisesPublishing' => null
            ]),
        ]);

        $service = new EntraIdService();
        
        // First sync
        $service->syncApplications();

        // Check database
        $app = EntraApp::where('app_id', 'app-123')->first();
        $this->assertNotNull($app);
        $this->assertEquals(2, $app->secrets()->count()); // both client secret and sp secret should coexist
        
        // Count audit logs for secret additions
        $initialLogsCount = \App\Models\AuditLog::where('action', 'entra_secret_added')->count();
        $this->assertEquals(2, $initialLogsCount); // cred-1 and cred-sp-1

        // Sync again
        $service->syncApplications();

        // Check that NO new audit logs for adding/removing secrets were created
        $newAddLogs = \App\Models\AuditLog::where('action', 'entra_secret_added')->count() - $initialLogsCount;
        $removeLogs = \App\Models\AuditLog::where('action', 'entra_secret_removed')->count();

        $this->assertEquals(0, $newAddLogs, "Should not log duplicate secret additions");
        $this->assertEquals(0, $removeLogs, "Should not log incorrect secret removals");
    }

    public function test_sync_removes_deleted_apps_and_logs_them()
    {
        Setting::updateOrCreate(['key' => 'entra_tenant_id'], ['value' => 'test-tenant']);
        Setting::updateOrCreate(['key' => 'entra_client_id'], ['value' => 'test-client']);
        Setting::updateOrCreate(['key' => 'entra_client_secret'], ['value' => 'test-secret']);

        $appsResponse = [
            [
                'id' => 'obj-gisib',
                'appId' => 'app-gisib',
                'displayName' => 'GISIB',
                'passwordCredentials' => [
                    [
                        'keyId' => 'cred-gisib',
                        'displayName' => 'GISIB Secret',
                        'startDateTime' => now()->subDays(10)->toIso8601String(),
                        'endDateTime' => now()->addYear()->toIso8601String(),
                    ]
                ],
                'keyCredentials' => [],
            ],
            [
                'id' => 'obj-sharepoint',
                'appId' => 'app-sharepoint',
                'displayName' => 'SharePoint Online',
                'passwordCredentials' => [
                    [
                        'keyId' => 'cred-sharepoint',
                        'displayName' => 'SharePoint Secret',
                        'startDateTime' => now()->subDays(10)->toIso8601String(),
                        'endDateTime' => now()->addYear()->toIso8601String(),
                    ]
                ],
                'keyCredentials' => [],
            ]
        ];

        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$appsResponse) {
            if (str_contains($request->url(), 'login.microsoftonline.com')) {
                return \Illuminate\Support\Facades\Http::response(['access_token' => 'mock-token']);
            }
            if (str_contains($request->url(), 'v1.0/applications')) {
                return \Illuminate\Support\Facades\Http::response(['value' => $appsResponse]);
            }
            if (str_contains($request->url(), 'v1.0/servicePrincipals')) {
                return \Illuminate\Support\Facades\Http::response(['value' => []]);
            }
            if (str_contains($request->url(), 'beta/applications')) {
                return \Illuminate\Support\Facades\Http::response(['onPremisesPublishing' => null]);
            }
            return \Illuminate\Support\Facades\Http::response([], 404);
        });

        $service = new EntraIdService();
        $service->syncApplications();

        $this->assertEquals(2, EntraApp::count());
        $this->assertEquals(2, EntraAppSecret::count());

        // GISIB is deleted in Entra ID (only SharePoint remains in mock response)
        $appsResponse = [
            [
                'id' => 'obj-sharepoint',
                'appId' => 'app-sharepoint',
                'displayName' => 'SharePoint Online',
                'passwordCredentials' => [
                    [
                        'keyId' => 'cred-sharepoint',
                        'displayName' => 'SharePoint Secret',
                        'startDateTime' => now()->subDays(10)->toIso8601String(),
                        'endDateTime' => now()->addYear()->toIso8601String(),
                    ]
                ],
                'keyCredentials' => [],
            ]
        ];

        $service->syncApplications();

        // GISIB should be deleted
        $this->assertEquals(1, EntraApp::count());
        $this->assertNull(EntraApp::where('app_id', 'app-gisib')->first());
        $this->assertEquals(1, EntraAppSecret::count());
        $this->assertNull(EntraAppSecret::where('key_id', 'cred-gisib')->first());

        // Check that removal audit logs were created
        $appRemovedLog = \App\Models\AuditLog::where('action', 'entra_app_removed')
            ->where('description', 'like', '%GISIB%')
            ->first();
        $secretRemovedLog = \App\Models\AuditLog::where('action', 'entra_secret_removed')
            ->where('description', 'like', '%GISIB%')
            ->first();

        $this->assertNotNull($appRemovedLog, "Should log app removal");
        $this->assertNotNull($secretRemovedLog, "Should log secret removal");
    }
}
