<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Certificate;
use App\Services\CertificateService;

class ReindexCertificates extends Command
{
    protected $signature = 'certificates:reindex';
    protected $description = 'Recalculate all certificate thumbprints, serial numbers and issuers in the database.';

    public function handle(CertificateService $certService)
    {
        $certificates = Certificate::whereNotNull('certificate')->orWhereNotNull('csr')->get();
        $total = $certificates->count();
        $updated = 0;
        $restoredFromDisk = 0;
        $restoredToDisk = 0;

        $this->info("Found {$total} certificate/CSR records. Starting reindex from disk...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($certificates as $cert) {
            $domainName = $cert->domain->name;
            $timestamp = $cert->created_at->format('Y-m-d_H-i-s');
            $folderPath = "certificates/{$domainName}/{$timestamp}";
            $certPath = "{$folderPath}/certificate.cer";
            $csrPath = "{$folderPath}/request.csr";

            $pem = $cert->certificate;
            $csr = $cert->csr;

            // 1. Check Disk vs DB for Certificate
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($certPath)) {
                $diskPem = \Illuminate\Support\Facades\Storage::disk('local')->get($certPath);
                if ($diskPem && $diskPem !== $pem) {
                    $pem = $diskPem;
                    $cert->certificate = $pem;
                    $restoredFromDisk++;
                }
            } elseif ($pem) {
                // Restore to disk if missing but in DB
                \Illuminate\Support\Facades\Storage::disk('local')->put($certPath, $pem);
                $restoredToDisk++;
            }

            // 2. Check Disk vs DB for CSR
            if (\Illuminate\Support\Facades\Storage::disk('local')->exists($csrPath)) {
                $diskCsr = \Illuminate\Support\Facades\Storage::disk('local')->get($csrPath);
                if ($diskCsr && $diskCsr !== $csr) {
                    $csr = $diskCsr;
                    $cert->csr = $csr;
                    $restoredFromDisk++;
                }
            } elseif ($csr) {
                \Illuminate\Support\Facades\Storage::disk('local')->put($csrPath, $csr);
                $restoredToDisk++;
            }

            // 3. Re-calculate metadata if it's a certificate
            if ($pem) {
                $newSha1 = $certService->extractThumbprint($pem, 'sha1');
                $newSha256 = $certService->extractThumbprint($pem, 'sha256');
                $newSerial = $certService->extractSerialNumber($pem);
                $newIssuer = $certService->extractIssuer($pem);

                if ($cert->thumbprint_sha1 !== $newSha1 || 
                    $cert->thumbprint_sha256 !== $newSha256 || 
                    $cert->serial_number !== $newSerial || 
                    $cert->issuer !== $newIssuer ||
                    $cert->isDirty('certificate') ||
                    $cert->isDirty('csr')) {
                    
                    $cert->update([
                        'thumbprint_sha1' => $newSha1,
                        'thumbprint_sha256' => $newSha256,
                        'serial_number' => $newSerial,
                        'issuer' => $newIssuer,
                    ]);
                    $updated++;
                }
            } elseif ($cert->isDirty('csr')) {
                $cert->save();
                $updated++;
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Reindex complete.");
        $this->info("- Updated/Fixed metadata for {$updated} records.");
        $this->info("- Restored from disk to DB: {$restoredFromDisk} files.");
        $this->info("- Restored from DB to disk: {$restoredToDisk} files.");
    }
}
