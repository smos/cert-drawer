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
        $certificates = Certificate::whereNotNull('certificate')->get();
        $total = $certificates->count();
        $fixed = 0;

        $this->info("Found {$total} certificates. Starting reindex...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($certificates as $cert) {
            $pem = $cert->certificate;
            
            $newSha1 = $certService->extractThumbprint($pem, 'sha1');
            $newSha256 = $certService->extractThumbprint($pem, 'sha256');
            $newSerial = $certService->extractSerialNumber($pem);
            $newIssuer = $certService->extractIssuer($pem);

            $needsUpdate = false;
            if ($cert->thumbprint_sha1 !== $newSha1) $needsUpdate = true;
            if ($cert->thumbprint_sha256 !== $newSha256) $needsUpdate = true;
            if ($cert->serial_number !== $newSerial) $needsUpdate = true;
            if ($cert->issuer !== $newIssuer) $needsUpdate = true;

            if ($needsUpdate) {
                $cert->update([
                    'thumbprint_sha1' => $newSha1,
                    'thumbprint_sha256' => $newSha256,
                    'serial_number' => $newSerial,
                    'issuer' => $newIssuer,
                ]);
                $fixed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Reindex complete. Updated {$fixed} out of {$total} certificates.");
    }
}
