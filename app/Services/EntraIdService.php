<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\EntraApp;
use App\Models\EntraAppSecret;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EntraIdService
{
    protected $tenantId;
    protected $clientId;
    protected $clientSecret;
    protected $accessToken;

    public function __construct()
    {
        $this->tenantId = Setting::where('key', 'entra_tenant_id')->value('value');
        $this->clientId = Setting::where('key', 'entra_client_id')->value('value');
        $this->clientSecret = Setting::where('key', 'entra_client_secret')->value('value');
    }

    protected function getAccessToken()
    {
        if ($this->accessToken) return $this->accessToken;

        if (!$this->tenantId || !$this->clientId || !$this->clientSecret) {
            throw new \Exception("Entra ID configuration missing in settings.");
        }

        $response = Http::asForm()->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if (!$response->successful()) {
            Log::error("Entra ID Token Error: " . $response->body());
            throw new \Exception("Failed to get Entra ID access token.");
        }

        $this->accessToken = $response->json()['access_token'];
        return $this->accessToken;
    }

    public function syncApplications()
    {
        $token = $this->getAccessToken();

        $beforeApps = EntraApp::pluck('display_name', 'app_id')->toArray();
        
        // Sync App Registrations
        $this->syncAppRegistrations($token);

        // Sync Enterprise Apps (Service Principals)
        $this->syncEnterpriseApps($token);

        // Prune apps with no secrets and no notes/tags
        $this->pruneBoringApps();

        $afterApps = EntraApp::pluck('display_name', 'app_id')->toArray();

        // Change Logging for Apps
        foreach (array_diff_key($afterApps, $beforeApps) as $appId => $name) {
            $app = EntraApp::where('app_id', $appId)->first();
            AuditLog::log('entra_app_added', "New Entra App discovered: {$name}", [], $app->id);
        }
    }

    protected function syncAppRegistrations($token)
    {
        $url = "https://graph.microsoft.com/v1.0/applications";
        
        while ($url) {
            $response = Http::withToken($token)->get($url);
            
            if ($response->successful()) {
                $data = $response->json();
                foreach ($data['value'] as $appData) {
                    $app = EntraApp::updateOrCreate(
                        ['app_id' => $appData['appId']],
                        [
                            'display_name' => $appData['displayName'],
                            'object_id' => $appData['id'], // Prefer App Reg ID for App Regs
                            'type' => 'app_registration',
                            'last_sync' => now(),
                        ]
                    );

                    $this->syncSecrets($app, $appData);
                }
                $url = $data['@odata.nextLink'] ?? null;
            } else {
                Log::error("Failed to sync App Registrations: " . $response->body());
                $url = null;
            }
        }
    }

    protected function syncEnterpriseApps($token)
    {
        $url = "https://graph.microsoft.com/v1.0/servicePrincipals";
        
        while ($url) {
            $response = Http::withToken($token)->get($url);
            
            if ($response->successful()) {
                $data = $response->json();
                foreach ($data['value'] as $appData) {
                    if ($appData['appOwnerOrganizationId'] !== 'f8cdef31-a31e-4b4a-93e4-5f571e91255a') {
                        $app = EntraApp::updateOrCreate(
                            ['app_id' => $appData['appId']],
                            [
                                'display_name' => $appData['displayName'],
                                'object_id' => $appData['id'], // Overwrite with SP ID as it's often more relevant for SSO
                                'type' => 'enterprise_app',
                                'last_sync' => now(),
                            ]
                        );

                        $this->syncSecrets($app, $appData);
                    }
                }
                $url = $data['@odata.nextLink'] ?? null;
            } else {
                Log::error("Failed to sync Enterprise Apps: " . $response->body());
                $url = null;
            }
        }
    }

    protected function pruneBoringApps()
    {
        // Delete apps that have no secrets, no notes, and no tags
        EntraApp::doesntHave('secrets')
            ->whereNull('notes')
            ->whereDoesntHave('tags')
            ->delete();
    }

    protected function syncSecrets(EntraApp $app, array $data)
    {
        $beforeSecrets = $app->secrets()->pluck('display_name', 'key_id')->toArray();
        $presentKeyIds = [];

        // Secrets
        if (isset($data['passwordCredentials'])) {
            foreach ($data['passwordCredentials'] as $secret) {
                $keyId = $secret['keyId'];
                $presentKeyIds[] = $keyId;
                EntraAppSecret::updateOrCreate(
                    ['entra_app_id' => $app->id, 'key_id' => $keyId],
                    [
                        'display_name' => $secret['displayName'],
                        'hint' => $secret['hint'] ?? null,
                        'type' => 'secret',
                        'start_date' => isset($secret['startDateTime']) ? now()->parse($secret['startDateTime']) : null,
                        'end_date' => isset($secret['endDateTime']) ? now()->parse($secret['endDateTime']) : null,
                    ]
                );
            }
        }

        // Certificates
        if (isset($data['keyCredentials'])) {
            foreach ($data['keyCredentials'] as $key) {
                $keyId = $key['keyId'];
                $thumbprint = isset($key['customKeyIdentifier']) ? bin2hex(base64_decode($key['customKeyIdentifier'])) : null;
                $endDate = isset($key['endDateTime']) ? now()->parse($key['endDateTime']) : null;

                // Check for existing certificate with same thumbprint and end date to avoid duplicates
                // (Sometimes Entra ID has multiple key IDs for the same actual certificate)
                $existing = $app->secrets()
                    ->where('type', 'certificate')
                    ->where('thumbprint', $thumbprint)
                    ->where('end_date', $endDate)
                    ->first();

                $secretModel = EntraAppSecret::updateOrCreate(
                    ['entra_app_id' => $app->id, 'key_id' => $existing->key_id ?? $keyId],
                    [
                        'display_name' => $key['displayName'],
                        'type' => 'certificate',
                        'thumbprint' => $thumbprint,
                        'start_date' => isset($key['startDateTime']) ? now()->parse($key['startDateTime']) : null,
                        'end_date' => $endDate,
                    ]
                );
                $presentKeyIds[] = $secretModel->key_id;
            }
        }

        $afterSecrets = $app->secrets()->whereIn('key_id', $presentKeyIds)->pluck('display_name', 'key_id')->toArray();

        // Detect Remediations (expired secret replaced by a new one)
        $expiredBefore = $app->secrets()->where('end_date', '<=', now())->pluck('key_id')->toArray();
        $hasNewSecret = count(array_diff_key($afterSecrets, $beforeSecrets)) > 0;

        // Logging for Secrets
        foreach (array_diff_key($afterSecrets, $beforeSecrets) as $keyId => $name) {
            $action = 'entra_secret_added';
            $msg = "New secret/cert added to app {$app->display_name}: {$name}";
            
            if (!empty($expiredBefore)) {
                $action = 'entra_secret_remediated';
                $msg = "Secret/cert remediated (replaced expired) for app {$app->display_name}: {$name}";
            }
            
            AuditLog::log($action, $msg, [], $app->id);
        }

        foreach (array_diff_key($beforeSecrets, $afterSecrets) as $keyId => $name) {
            AuditLog::log('entra_secret_removed', "Secret/cert removed from app {$app->display_name}: {$name}", [], $app->id);
        }

        // Remediations (if an expired secret was replaced by a new one, we might log that as remediation)
        // For now, just deleting non-present ones is fine.
        $app->secrets()->whereNotIn('key_id', $presentKeyIds)->delete();
    }

    public function getExpiringItems($daysThreshold = 30)
    {
        $thresholdDate = now()->addDays($daysThreshold);
        $now = now();
        
        $allExpiringOrExpired = EntraAppSecret::with('app')
            ->where('end_date', '<=', $thresholdDate)
            ->whereHas('app', function($q) {
                $q->where('is_enabled', true);
            })
            ->get();

        $filtered = $allExpiringOrExpired->filter(function($item) use ($now) {
            // If it's expiring soon but not yet expired, always include it
            if ($item->end_date > $now) {
                return true;
            }

            // If it's already expired, check if there's an active replacement of the same type
            $hasActiveReplacement = $item->app->secrets()
                ->where('type', $item->type)
                ->where('end_date', '>', $now)
                ->exists();

            // If there's an active replacement, ignore this expired one
            if ($hasActiveReplacement) {
                return false;
            }

            // If there's no active replacement, we want to report only the MOST RECENT expired one
            $mostRecentExpired = $item->app->secrets()
                ->where('type', $item->type)
                ->where('end_date', '<=', $now)
                ->orderBy('end_date', 'desc')
                ->first();

            return $item->id === $mostRecentExpired->id;
        });

        return $filtered;
    }
}
