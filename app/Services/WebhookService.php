<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function sendAlert($changes, $expiryAlerts)
    {
        $webhookUrl = Setting::where('key', 'alert_webhook_url')->value('value');
        if (empty($webhookUrl)) {
            return false;
        }

        $secret = Setting::where('key', 'alert_webhook_secret')->value('value');
        
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'cert_health_alert',
            'changes' => $changes,
            'expiry_alerts' => $expiryAlerts,
            // Add a summary for MS Teams and other simple webhook handlers
            'text' => $this->generateSummary($changes, $expiryAlerts),
        ];

        return $this->dispatch($webhookUrl, $payload, $secret);
    }

    public function sendTest($url, $secret = null)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'test_notification',
            'message' => 'This is a test notification from Cert Drawer.',
            'text' => '🔔 This is a test notification from Cert Drawer. Your webhook integration is working!',
        ];

        return $this->dispatch($url, $payload, $secret);
    }

    protected function dispatch($url, $payload, $secret)
    {
        $jsonPayload = json_encode($payload);
        
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CertDrawer/1.0',
        ];

        if (!empty($secret)) {
            $headers['X-Hub-Signature-256'] = 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                return true;
            }
            
            Log::error("Webhook failed with status {$response->status()} at {$url}", [
                'response' => $response->body()
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Webhook delivery failed: " . $e->getMessage());
            return false;
        }
    }

    protected function generateSummary($changes, $expiryAlerts)
    {
        $lines = ["### 🔔 Cert Drawer Alert"];
        
        if (!empty($changes)) {
            $lines[] = "**Changes detected:** " . count($changes) . " domains updated.";
            foreach (array_slice($changes, 0, 5) as $change) {
                $domainName = is_object($change['domain']) ? $change['domain']->name : ($change['domain']['name'] ?? 'Unknown');
                $lines[] = "- **{$domainName}** ({$change['ip']}): Fingerprint or status changed.";
            }
            if (count($changes) > 5) {
                $lines[] = "- ... and " . (count($changes) - 5) . " more.";
            }
        }
        
        if (!empty($expiryAlerts)) {
            $lines[] = "**Expiry alerts:** " . count($expiryAlerts) . " certificates.";
            foreach (array_slice($expiryAlerts, 0, 5) as $alert) {
                $domainName = is_object($alert['domain']) ? $alert['domain']->name : ($alert['domain']['name'] ?? 'Unknown');
                $lines[] = "- **{$domainName}**: Expires in {$alert['days']} days ({$alert['expiry']})";
            }
            if (count($expiryAlerts) > 5) {
                $lines[] = "- ... and " . (count($expiryAlerts) - 5) . " more.";
            }
        }
        
        return implode("\n\n", $lines);
    }
}
