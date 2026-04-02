<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportFolder extends Command
{
    protected $signature = 'import:folder {path : Path to the folder containing certs} {--password= : Optional password for PFX files}';
    protected $description = 'Mass import certificates from a folder structure';

    public function handle()
    {
        $basePath = $this->argument('path');
        $pfxPassword = $this->option('password');
        $service = app(CertificateService::class);

        if (!is_dir($basePath)) {
            $this->error("Directory not found: {$basePath}");
            return 1;
        }

        $this->info("Scanning folder: {$basePath} (Recursive)");

        // Use RecursiveDirectoryIterator to find files up to 5 levels deep
        $directory = new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);
        $iterator->setMaxDepth(5);

        // Group files by their parent directory to treat each folder as a potential certificate set
        $groups = [];
        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            
            $path = $file->getPath();
            if (!isset($groups[$path])) {
                $groups[$path] = [];
            }
            $groups[$path][] = $file->getPathname();
        }

        foreach ($groups as $path => $files) {
            $folderName = basename($path);
            $this->comment("Processing group in: {$folderName}");

            $certData = [
                'cert' => null,
                'csr' => null,
                'key' => null,
                'conf' => null,
                'commonName' => null,
                'sans' => [],
                'expiry' => null,
                'issuer' => null,
                'is_ca' => false,
            ];

            foreach ($files as $file) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $content = file_get_contents($file);

                if ($ext === 'cer' || $ext === 'crt') {
                    $content = $service->ensurePem($content);
                    $info = $service->getCertInfo($content);
                    if ($info) {
                        $certData['cert'] = $content;
                        // Robust CN extraction
                        $subject = $info['subject'] ?? [];
                        $certData['commonName'] = $subject['commonName'] ?? $subject['CN'] ?? $certData['commonName'];

                        $certData['sans'] = array_unique(array_merge($certData['sans'], $service->extractSansFromCert($info)));
                        $certData['expiry'] = isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null;
                        $certData['issuer'] = $info['issuer']['CN'] ?? 'Unknown';
                        $certData['is_ca'] = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));
                    }
                } elseif ($ext === 'csr') {
                    $content = $service->ensureCsrPem($content);
                    $certData['csr'] = $content;
                    $info = $service->getCertInfoFromCsr($content);
                    if ($info) {
                        $certData['commonName'] = $info['commonName'] ?? $info['CN'] ?? $certData['commonName'];
                        $certData['sans'] = array_unique(array_merge($certData['sans'], $service->extractSansFromCsr($content)));
                    }
                }
 elseif ($ext === 'key') {
                    $certData['key'] = $content;
                } elseif ($ext === 'pfx' && $pfxPassword) {
                    try {
                        $res = $service->parsePfx($content, $pfxPassword);
                        $certData['cert'] = $res['cert'];
                        $certData['key'] = $res['private_key'];
                        $info = $res['info'];
                        $subject = $info['subject'] ?? [];
                        $certData['commonName'] = $subject['commonName'] ?? $subject['CN'] ?? $certData['commonName'];
                        $certData['sans'] = array_unique(array_merge($certData['sans'], $service->extractSansFromCert($info)));
                        $certData['expiry'] = isset($info['validTo_time_t']) ? date('Y-m-d H:i:s', $info['validTo_time_t']) : null;
                        $certData['issuer'] = $info['issuer']['CN'] ?? 'Unknown';
                        $certData['is_ca'] = (isset($info['extensions']['basicConstraints']) && str_contains($info['extensions']['basicConstraints'], 'CA:TRUE'));
                    } catch (\Exception $e) {
                        $this->warn("  Failed to parse PFX in {$folderName}: " . $e->getMessage());
                    }
                }
            }

            if ($certData['cert']) {
                $this->importToDatabase($certData, $folderName);
            } elseif ($certData['csr']) {
                $this->warn("  Folder only contains a CSR, skipping: {$folderName}");
            } else {
                $this->warn("  No certificate found in {$folderName}");
            }
        }

        $this->info("Import complete.");
        return 0;
    }

    protected function importToDatabase(array $data, string $fallbackName)
    {
        $name = $data['commonName'];
        if (is_array($name)) $name = $name[0] ?? null;

        // Dedup Check: If we have a cert, check if thumbprint already exists
        if ($data['cert']) {
            $sha1 = app(CertificateService::class)->extractThumbprint($data['cert'], 'sha1');
            $existing = Certificate::where('thumbprint_sha1', $sha1)->first();
            if ($existing) {
                $this->comment("  Skipping: Certificate already exists in database ({$name}).");
                return;
            }
        }

        // CRITICAL FIX: For CAs, always use the internal CN
        // For End-Entity, prioritize internal CN but allow folder name as fallback if CN is missing/mangled
        if ($data['is_ca']) {
            if (!$name) {
                $this->warn("  Skipping CA in '{$fallbackName}': No Common Name found in metadata.");
                return;
            }
        } else {
            $ignoredNames = ['ca', 'old', 'certs', 'certificate', 'certificates', 'new', '2020', '2021', '2022', '2023', '2024', '2025', '2026', 'archive', 'bak', 'backup'];
            
            // Validation: If name is generic or missing, try fallback but validate it too
            if (!$name || in_array(strtolower($name), $ignoredNames) || (!str_contains($name, '.') && !str_contains($name, ' '))) {
                if ($fallbackName && !in_array(strtolower($fallbackName), $ignoredNames) && (str_contains($fallbackName, '.') || str_contains($fallbackName, ' '))) {
                    $name = $fallbackName;
                } else if (!$name) {
                    $this->warn("  Skipping group '{$fallbackName}': No valid domain name found in certificate/CSR metadata.");
                    return;
                }
            }
        }

        // Validate name to prevent directory traversal or other injection
        $regex = $data['is_ca'] ? '#^[a-zA-Z0-9- \._\(\),&]+$#' : '#^(\*\.)?([a-zA-Z0-9- \._]+\.)*[a-zA-Z0-9- \._]+$#';

        if (!$name || !preg_match($regex, $name)) {
            $this->warn("  Skipping group '{$fallbackName}': Invalid domain name format '{$name}'");
            return;
        }

        $domain = Domain::firstOrCreate(['name' => $name]);
        
        $certificate = $domain->certificates()->create([
            'request_type' => 'manual',
            'csr' => $data['csr'],
            'certificate' => $data['cert'],
            'private_key' => $data['key'] ? encrypt($data['key']) : null,
            'issuer' => $data['issuer'],
            'expiry_date' => $data['expiry'],
            'status' => 'issued',
            'is_ca' => $data['is_ca'],
            'thumbprint_sha1' => app(CertificateService::class)->extractThumbprint($data['cert'], 'sha1'),
            'thumbprint_sha256' => app(CertificateService::class)->extractThumbprint($data['cert'], 'sha256'),
        ]);

        $path = "certificates/" . $domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        
        Storage::disk('local')->put($path . "/certificate.cer", $data['cert']);
        if ($data['csr']) Storage::disk('local')->put($path . "/request.csr", $data['csr']);
        
        $dn = [
            'commonName' => $domain->name,
            'countryName' => 'NL',
            'organizationName' => 'Organization',
        ];
        app(CertificateService::class)->saveSslConfig($path, $dn, $data['sans']);

        $this->info("  Imported: {$domain->name} (CA: " . ($data['is_ca'] ? 'YES' : 'NO') . ")");
    }
}
