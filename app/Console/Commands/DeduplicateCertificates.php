<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DeduplicateCertificates extends Command
{
    protected $signature = 'certificates:deduplicate {--dry-run : Only show what would be deleted}';
    protected $description = 'Remove duplicate certificate records and their files, keeping the most complete version.';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $disk = Storage::disk('local');

        // Group by domain and thumbprint to find duplicates
        // Note: We only care about issued certificates with thumbprints
        $duplicates = Certificate::whereNotNull('thumbprint_sha1')
            ->get()
            ->groupBy(function($cert) {
                return $cert->domain_id . '_' . $cert->thumbprint_sha1;
            })
            ->filter(function($group) {
                return $group->count() > 1;
            });

        if ($duplicates->isEmpty()) {
            $this->info("No duplicate certificates found.");
            return 0;
        }

        $this->info("Found " . $duplicates->count() . " groups of duplicate certificates.");

        foreach ($duplicates as $key => $group) {
            // Logic to choose which one to keep:
            // 1. Has certificate blob
            // 2. Has private key
            // 3. Newest created_at
            $sorted = $group->sort(function($a, $b) {
                if ($a->certificate && !$b->certificate) return -1;
                if (!$a->certificate && $b->certificate) return 1;
                if ($a->private_key && !$b->private_key) return -1;
                if (!$a->private_key && $b->private_key) return 1;
                return $b->created_at->timestamp <=> $a->created_at->timestamp;
            });

            $keep = $sorted->first();
            $toDelete = $sorted->slice(1);

            $this->comment("Group {$key}: Keeping ID {$keep->id} (Created: {$keep->created_at})");
            
            $keepPath = "certificates/" . $keep->domain->name . "/" . $keep->created_at->format('Y-m-d_H-i-s');

            foreach ($toDelete as $cert) {
                $deletePath = "certificates/" . $cert->domain->name . "/" . $cert->created_at->format('Y-m-d_H-i-s');
                
                $this->warn("  -> Deleting ID {$cert->id} (Created: {$cert->created_at})");

                if ($dryRun) {
                    $this->info("     [DRY RUN] Would delete DB record and folder: {$deletePath}");
                    continue;
                }

                // Check if the path is identical to the one we are keeping (collision safety)
                if ($deletePath === $keepPath) {
                    $this->info("     Storage path matches kept record (collision), skipping directory purge.");
                } else {
                    if ($disk->exists($deletePath)) {
                        $disk->deleteDirectory($deletePath);
                        $this->info("     Deleted directory: {$deletePath}");
                    }
                }

                $cert->delete();
                $this->info("     Deleted DB record.");
            }
        }

        $this->info("Deduplication complete.");
        return 0;
    }
}
