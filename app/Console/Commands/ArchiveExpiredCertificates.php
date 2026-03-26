<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Setting;
use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ArchiveExpiredCertificates extends Command
{
    protected $signature = 'certificates:archive {--dry-run : Show what would be archived}';
    protected $description = 'Archive expired certificates that exceed the threshold setting.';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $threshold = (int) (Setting::where('key', 'archive_threshold_days')->first()?->value ?? 180);

        $expiredCerts = Certificate::where('status', 'issued')
            ->whereNull('archived_at')
            ->where('expiry_date', '<', now()->subDays($threshold))
            ->get();

        if ($expiredCerts->isEmpty()) {
            $this->info("No certificates found to archive.");
            return 0;
        }

        $this->info("Found " . $expiredCerts->count() . " certificates to archive (expired > {$threshold} days).");

        foreach ($expiredCerts as $cert) {
            $this->comment("Archiving ID {$cert->id} for domain {$cert->domain->name} (Expired: {$cert->expiry_date})");

            if ($dryRun) {
                $this->info("  [DRY RUN] Would archive DB record and purge private key files.");
                continue;
            }

            // 1. Mark as archived in DB
            $cert->update(['archived_at' => now()]);

            // 2. Purge private key and PFX password from DB
            $cert->update([
                'private_key' => null,
                'pfx_password' => null,
            ]);

            // 3. Purge sensitive files from disk (Key, PFX)
            $path = "certificates/" . $cert->domain->name . "/" . $cert->created_at->format('Y-m-d_H-i-s');
            $disk = Storage::disk('local');
            
            if ($disk->exists($path . "/private.key")) {
                $disk->delete($path . "/private.key");
                $this->info("  Deleted private.key");
            }
            if ($disk->exists($path . "/certificate.pfx")) {
                $disk->delete($path . "/certificate.pfx");
                $this->info("  Deleted certificate.pfx");
            }

            AuditLog::log('cert_archive', "Archived expired certificate ID {$cert->id} for domain {$cert->domain->name}");
        }

        $this->info("Archiving complete.");
        return 0;
    }
}
