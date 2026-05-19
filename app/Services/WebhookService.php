<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function send($type, $payload)
    {
        $webhookUrl = Setting::where('key', "{$type}_webhook_url")->value('value');
        
        // Fallback to legacy alert_webhook_url for 'cert' type to maintain BC
        if (empty($webhookUrl) && $type === 'cert') {
            $webhookUrl = Setting::where('key', 'alert_webhook_url')->value('value');
        }

        if (empty($webhookUrl)) {
            return false;
        }

        $secretKey = "{$type}_webhook_secret";
        if ($type === 'cert' && !Setting::where('key', $secretKey)->exists()) {
            $secretKey = 'alert_webhook_secret';
        }
        $secret = Setting::where('key', $secretKey)->value('value');
        
        return $this->dispatch($webhookUrl, $payload, $secret);
    }

    public function sendAlert($changes, $expiryAlerts)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'cert_health_alert',
            'changes' => $changes,
            'expiry_alerts' => $expiryAlerts,
            // Add a summary for MS Teams and other simple webhook handlers
            'text' => $this->generateSummary($changes, $expiryAlerts),
        ];

        return $this->send('cert', $payload);
    }

    public function sendDnsAlert($changes)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'dns_health_alert',
            'changes' => $changes,
            'text' => $this->generateDnsSummary($changes),
        ];

        return $this->send('dns', $payload);
    }

    public function sendEntraAlert($expiringItems)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'entra_expiry_alert',
            'items' => $expiringItems,
            'text' => $this->generateEntraSummary($expiringItems),
        ];

        return $this->send('entra', $payload);
    }

    public function sendAutomationAlert($automation, $certificate, $status, $message, $details = null)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'automation_alert',
            'status' => $status,
            'message' => $message,
            'automation' => [
                'id' => $automation->id,
                'type' => $automation->type,
                'hostname' => $automation->hostname,
                'domain' => $automation->domain->name,
            ],
            'certificate' => [
                'id' => $certificate->id,
                'serial' => $certificate->serial_number,
                'expiry' => $certificate->expiry_date,
            ],
            'details' => $details,
            'text' => "### 🤖 Automation Alert: {$status}\n\n**Domain:** {$automation->domain->name}\n**Device:** {$automation->hostname} ({$automation->type})\n**Message:** {$message}",
        ];

        return $this->send('automation', $payload);
    }

    public function sendAcmeRenewalAlert($certificate, $status, $message = null)
    {
        $payload = [
            'timestamp' => now()->toIso8601String(),
            'event' => 'acme_renewal_alert',
            'status' => $status,
            'message' => $message,
            'certificate' => [
                'id' => $certificate->id,
                'domain' => $certificate->domain->name,
                'serial' => $certificate->serial_number,
                'expiry' => $certificate->expiry_date,
            ],
            'text' => "### 🔄 ACME Renewal Alert: {$status}\n\n**Domain:** {$certificate->domain->name}\n" . ($message ? "**Message:** {$message}" : ""),
        ];

        return $this->send('automation', $payload);
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

    protected function generateDnsSummary($changes)
    {
        $lines = ["### 🌐 DNS Health Alert"];
        $lines[] = "**Changes detected:** " . count($changes) . " records updated.";
        
        $changesArray = is_array($changes) ? $changes : $changes->all();
        foreach (array_slice($changesArray, 0, 5) as $change) {
            $domainName = $change->domain->name ?? 'Unknown';
            $lines[] = "- **{$domainName}**: DNS records changed.";
        }
        
        if (count($changes) > 5) {
            $lines[] = "- ... and " . (count($changes) - 5) . " more.";
        }
        
        return implode("\n\n", $lines);
    }

    protected function generateEntraSummary($items)
    {
        $lines = ["### 🆔 Entra ID Expiry Alert"];
        $lines[] = "**Expiring items found:** " . count($items);
        
        $itemsArray = is_array($items) ? $items : $items->all();
        foreach (array_slice($itemsArray, 0, 5) as $item) {
            $type = $item['type'] === 'secret' ? 'Secret' : 'Certificate';
            $lines[] = "- **{$item['app_name']}**: {$type} expires on {$item['expiry_date']} ({$item['days_remaining']} days remaining)";
        }
        
        if (count($items) > 5) {
            $lines[] = "- ... and " . (count($items) - 5) . " more.";
        }
        
        return implode("\n\n", $lines);
    }
}
