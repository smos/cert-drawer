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

        // Sync App Proxy Certificates (Beta endpoint)
        $this->syncAppProxyCertificates($token);

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
        $url = "https://graph.microsoft.com/v1.0/applications?\$select=id,appId,displayName,passwordCredentials,keyCredentials";
        
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
        $url = "https://graph.microsoft.com/v1.0/servicePrincipals?\$select=id,appId,displayName,appOwnerOrganizationId,passwordCredentials,keyCredentials";
        
        while ($url) {
            $response = Http::withToken($token)->get($url);
            
            if ($response->successful()) {
                $data = $response->json();
                foreach ($data['value'] as $appData) {
                    if ($appData['appOwnerOrganizationId'] !== 'f8cdef31-a31e-4b4a-93e4-5f571e91255a') {
                        $app = EntraApp::where('app_id', $appData['appId'])->first();
                        
                        if ($app) {
                            // Already exists (probably from syncAppRegistrations)
                            // We keep the App Reg object_id as it is needed for App Proxy/Client Secrets
                            $app->update([
                                'display_name' => $appData['displayName'],
                                'last_sync' => now(),
                            ]);
                        } else {
                            // New Enterprise App only
                            $app = EntraApp::create([
                                'app_id' => $appData['appId'],
                                'display_name' => $appData['displayName'],
                                'object_id' => $appData['id'],
                                'type' => 'enterprise_app',
                                'last_sync' => now(),
                            ]);
                        }

                        if (!empty($appData['passwordCredentials']) || !empty($appData['keyCredentials'])) {
                            $this->syncSecrets($app, $appData);
                        }
                    }
                }
                $url = $data['@odata.nextLink'] ?? null;
            } else {
                Log::error("Failed to sync Enterprise Apps: " . $response->body());
                $url = null;
            }
        }
    }

    protected function syncAppProxyCertificates($token)
    {
        // Microsoft Graph list endpoints often omit onPremisesPublishing content.
        // We must fetch it individually for each app that we have already discovered.
        $apps = EntraApp::where('type', 'app_registration')->get();
        
        foreach ($apps as $app) {
            $url = "https://graph.microsoft.com/beta/applications/{$app->object_id}?\$select=onPremisesPublishing";
            $response = Http::withToken($token)->get($url);
            
            if ($response->status() == 403) {
                Log::warning("EntraIdService: No permission to read onPremisesPublishing for {$app->display_name}. Update Entra ID App permissions.");
                continue;
            }

            if ($response->successful()) {
                $appData = $response->json();
                if (isset($appData['onPremisesPublishing']['verifiedCustomDomainCertificatesMetadata'])) {
                    $this->syncSecrets($app, $appData);
                }
            } else {
                Log::error("Failed to sync App Proxy Cert for {$app->display_name}: " . $response->body());
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
        // BUG FIX: Only sync (and delete) secrets if the data actually contains the fields.
        // Graph API might omit them if not requested or if certain permissions are missing.
        if (!array_key_exists('passwordCredentials', $data) && !array_key_exists('keyCredentials', $data) && !isset($data['onPremisesPublishing'])) {
            return;
        }

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

        // App Proxy Certificates (if provided via syncAppProxyCertificates)
        if (isset($data['onPremisesPublishing']['verifiedCustomDomainCertificatesMetadata'])) {
            $certs = $data['onPremisesPublishing']['verifiedCustomDomainCertificatesMetadata'];
            // If it's a single object (has thumbprint directly), wrap it in an array
            if (isset($certs['thumbprint'])) {
                $certs = [$certs];
            }

            foreach ($certs as $index => $cert) {
                $thumbprint = $cert['thumbprint'] ?? null;
                $endDate = isset($cert['expiryDate']) ? now()->parse($cert['expiryDate']) : null;
                
                // We use a synthetic key_id for these as they don't have a stable keyId in Graph
                $keyId = "app_proxy_" . ($thumbprint ?? $index);
                $presentKeyIds[] = $keyId;

                EntraAppSecret::updateOrCreate(
                    ['entra_app_id' => $app->id, 'key_id' => $keyId],
                    [
                        'display_name' => $cert['subjectName'] ?? "App Proxy Certificate",
                        'type' => 'app_proxy_certificate',
                        'thumbprint' => $thumbprint,
                        'start_date' => isset($cert['issueDate']) ? now()->parse($cert['issueDate']) : null,
                        'end_date' => $endDate,
                    ]
                );
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

        // Delete secrets NOT in the current sync source list
        // BUT only if we are syncing the correct source for that type.
        // (App Proxy certs are only in onPremisesPublishing, others are in password/keyCredentials)
        $query = $app->secrets()->whereNotIn('key_id', $presentKeyIds);
        
        if (isset($data['passwordCredentials']) || isset($data['keyCredentials'])) {
            $query->whereIn('type', ['secret', 'certificate']);
        }
        if (isset($data['onPremisesPublishing'])) {
            $query->where('type', 'app_proxy_certificate');
        }
        
        $query->delete();
    }

    public function getExpiringItems($daysThreshold = 30)
    {
        $thresholdDate = now()->addDays($daysThreshold);
        $ignoreExpiredDays = (int) (Setting::where('key', 'entra_ignore_expired_days')->value('value') ?? 30);
        $now = now();
        
        $allExpiringOrExpired = EntraAppSecret::with('app')
            ->where('end_date', '<=', $thresholdDate)
            ->whereHas('app', function($q) {
                $q->where('is_enabled', true);
            })
            ->get();

        $filtered = $allExpiringOrExpired->filter(function($item) use ($now, $ignoreExpiredDays) {
            // Check if there's a valid replacement of the same type that is NOT expiring soon
            // (i.e., its end_date is beyond our yellow threshold)
            $yellowThreshold = (int) (Setting::where('key', 'expiry_yellow')->value('value') ?? 30);
            $replacementThreshold = now()->addDays($yellowThreshold);

            $hasValidReplacement = $item->app->secrets()
                ->where('type', $item->type)
                ->where('end_date', '>', $replacementThreshold)
                ->where('id', '!=', $item->id)
                ->exists();

            // If there's a valid replacement already in place, we don't need to alert on the old one
            if ($hasValidReplacement) {
                return false;
            }

            // If it's expiring soon but not yet expired, include it
            if ($item->end_date > $now) {
                // If there are multiple expiring ones of same type (unlikely but possible), 
                // only show the one that expires SOONEST
                $soonestExpiring = $item->app->secrets()
                    ->where('type', $item->type)
                    ->where('end_date', '>', $now)
                    ->orderBy('end_date', 'asc')
                    ->first();
                
                return $item->id === $soonestExpiring->id;
            }

            // If it's already expired, check if it's too old
            if ($ignoreExpiredDays > 0 && $item->end_date < $now->copy()->subDays($ignoreExpiredDays)) {
                return false;
            }

            // If it's already expired, we only want to report it if there is NO active replacement at all
            $hasAnyActive = $item->app->secrets()
                ->where('type', $item->type)
                ->where('end_date', '>', $now)
                ->exists();

            if ($hasAnyActive) {
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
