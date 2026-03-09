<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateCertificatesToTimestampedFolders extends Command
{
    protected $signature = 'certificates:migrate-folders';
    protected $description = 'Migrate existing certificates from Y-m to Y-m-d_H-i-s folder structure';

    public function handle()
    {
        $certificates = Certificate::with('domain')->get();
        $disk = Storage::disk('local');

        foreach ($certificates as $cert) {
            $oldPath = "certificates/" . $cert->domain->name . "/" . $cert->created_at->format('Y-m');
            $newPath = "certificates/" . $cert->domain->name . "/" . $cert->created_at->format('Y-m-d_H-i-s');

            if ($disk->exists($oldPath)) {
                $this->info("Migrating {$cert->domain->name} ({$cert->id}) from {$oldPath} to {$newPath}");
                
                // Ensure parent directory exists
                if (!$disk->exists($newPath)) {
                    // move() in Laravel handles directory moves if supported by the underlying flysystem driver
                    // For local, we'll check if target exists and if not, move everything.
                    // If multiple certs shared the same Y-m folder (collision), 
                    // we need to be careful not to move other certs files.
                    
                    // Actually, if they shared a folder, they shared the SAME files (collision!).
                    // So we'll just copy the current state of that folder to the new one.
                    
                    // First, list all files in old folder
                    $files = $disk->files($oldPath);
                    foreach ($files as $file) {
                        $filename = basename($file);
                        $disk->copy($file, $newPath . '/' . $filename);
                    }
                    $this->info("  Copied " . count($files) . " files.");
                }
            } else {
                $this->warn("  Old path {$oldPath} not found for cert {$cert->id}");
            }
        }

        $this->info("Migration complete. (Old folders were NOT deleted to be safe, please verify and delete manually)");
        return 0;
    }
}
