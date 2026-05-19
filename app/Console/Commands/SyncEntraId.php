<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EntraIdService;
use App\Models\Setting;
use App\Mail\EntraExpiryReport;
use Illuminate\Support\Facades\Mail;

class SyncEntraId extends Command
{
    protected $signature = 'cert:sync-entra';
    protected $description = 'Daily synchronization of Entra ID applications and expiry monitoring';

    public function handle(EntraIdService $service)
    {
        $this->info("Starting Entra ID synchronization...");
        
        try {
            $service->syncApplications();
            $this->info("Synchronization completed.");

            $this->checkExpiries($service);
        } catch (\Exception $e) {
            $this->error("Sync failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function checkExpiries(EntraIdService $service)
    {
        $yellowThreshold = (int) (Setting::where('key', 'expiry_yellow')->value('value') ?? 30);
        $expiringItems = $service->getExpiringItems($yellowThreshold);

        if ($expiringItems->isEmpty()) {
            $this->info("No expiring Entra ID items found.");
            return;
        }

        $recipientsString = Setting::where('key', 'entra_mail_recipients')->value('value');
        $webhookUrl = Setting::where('key', 'entra_webhook_url')->value('value');

        if (empty($recipientsString) && empty($webhookUrl)) {
            $this->warn("No notification recipients (email or webhook) configured for Entra ID alerts.");
            return;
        }

        // Trigger Webhooks
        try {
            $webhookService = new \App\Services\WebhookService();
            $webhookService->sendEntraAlert($expiringItems);
        } catch (\Exception $e) {
            $this->error("Failed to trigger Entra ID webhooks: " . $e->getMessage());
        }

        if (empty($recipientsString)) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', $recipientsString)));
        if (empty($recipients)) {
            return;
        }

        try {
            Mail::to($recipients)->send(new EntraExpiryReport($expiringItems));
            $this->info("Expiry notification email sent to " . implode(', ', $recipients));
        } catch (\Exception $e) {
            $this->error("Failed to send Entra ID notification: " . $e->getMessage());
        }
    }
}
