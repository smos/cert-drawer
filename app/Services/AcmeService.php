<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class AcmeService
{
    protected string $acmePath;
    protected string $homeDir;
    protected string $certsDir;

    public function __construct()
    {
        $this->homeDir = config('acme.home');
        $this->acmePath = config('acme.binary');
        $this->certsDir = config('acme.certs');
    }

    protected function getBaseCommand(): array
    {
        return [
            'bash',
            $this->acmePath,
            '--home', $this->homeDir,
            '--config-home', $this->homeDir . '/config',
            '--cert-home', $this->certsDir,
        ];
    }

    public function issueCertificate(Certificate $certificate)
    {
        $settings = Setting::all()->pluck('value', 'key');
        $kid = $settings['acme_kid'] ?? '';
        $hmac = $settings['acme_hmac'] ?? '';
        $email = $settings['admin_email'] ?? config('mail.from.address', 'admin@domain.local');
        
        $certService = app(CertificateService::class);
        $info = $certService->getCertInfoFromCsr($certificate->csr);
        $commonName = $info['commonName'] ?? $certificate->domain->name;
        $sans = $certService->extractSansFromCsr($certificate->csr);
        $allDomains = array_unique(array_merge([$commonName], $sans));

        $isWildcard = false;
        foreach ($allDomains as $d) if (str_starts_with($d, '*.')) $isWildcard = true;

        $directoryUrl = $settings['acme_url_dv'] ?? '';
        if ($isWildcard) {
            $directoryUrl = $settings['acme_url_wildcard'] ?? '';
        } elseif (count($allDomains) > 1) {
            $directoryUrl = $settings['acme_url_san'] ?? '';
        }

        if (empty($directoryUrl)) {
            throw new Exception("ACME Directory URL not configured for this request type.");
        }

        if ($kid && $hmac) {
            // First register the account explicitly with EAB
            $regCmd = $this->getBaseCommand();
            $regCmd[] = '--insecure';
            $regCmd[] = '--register-account';
            $regCmd[] = '--server'; $regCmd[] = $directoryUrl;
            $regCmd[] = '--eab-kid'; $regCmd[] = $kid;
            $regCmd[] = '--eab-hmac-key'; $regCmd[] = $hmac;
            $regCmd[] = '--accountemail'; $regCmd[] = $email;
            
            Log::info("Registering ACME account with EAB...");
            Process::timeout(60)->env(['DEBUG' => '0'])->run($regCmd);
        }

        // 1. Prepare Issue Command
        $cmd = $this->getBaseCommand();
        $cmd[] = '--insecure';
        $cmd[] = '--issue';
        $cmd[] = '--server'; $cmd[] = $directoryUrl;
        $cmd[] = '--accountemail'; $cmd[] = $email;
        $cmd[] = '--log'; $cmd[] = $this->homeDir . '/acme.log';
        
        if ($kid && $hmac) {
            $cmd[] = '--eab-kid'; $cmd[] = $kid;
            $cmd[] = '--eab-hmac-key'; $cmd[] = $hmac;
        }

        foreach ($allDomains as $domain) {
            $cmd[] = '-d'; $cmd[] = $domain;
        }

        $cmd[] = '--keylength'; $cmd[] = '4096'; // Force RSA 4096
        $cmd[] = '--dns'; $cmd[] = 'dns_manual';
        $cmd[] = '--dnssleep'; $cmd[] = '0';

        $sanitizedCmd = array_map(function($arg) use ($kid, $hmac) {
            if ($kid && $arg === $kid) return '********';
            if ($hmac && $arg === $hmac) return '********';
            return $arg;
        }, $cmd);

        Log::info("Running acme.sh command: " . implode(' ', $sanitizedCmd));

        $result = Process::timeout(300)
            ->env(['DEBUG' => '0', 'ACCOUNT_EMAIL' => $email])
            ->run($cmd);
        
        $output = $result->output();
        $errorOutput = $result->errorOutput();

        if ($result->successful() || str_contains($output, 'Cert success') || str_contains($output, 'already verified')) {
            Log::info("acme.sh finished. Output: " . $output);
            return $this->importIssuedCertificate($certificate, $commonName);
        }

        Log::error("acme.sh FAILED. Exit Code: " . $result->exitCode());
        Log::error("acme.sh STDOUT: " . $output);
        Log::error("acme.sh STDERR: " . $errorOutput);
        
        throw new Exception("acme.sh failed (Exit {$result->exitCode()}). See logs for details.");
    }

    protected function importIssuedCertificate(Certificate $certificate, string $commonName)
    {
        $safeName = str_replace('*', '_', $commonName);
        
        // Try multiple potential paths
        $paths = [
            $this->certsDir . '/' . $safeName . '/' . $safeName . '.cer',
            $this->certsDir . '/' . $safeName . '_ecc/' . $safeName . '.cer',
        ];

        $certPath = null;
        foreach ($paths as $p) {
            if (file_exists($p)) {
                $certPath = $p;
                break;
            }
        }
        
        if (!$certPath) {
            throw new Exception("Certificate file not found for {$safeName} in {$this->certsDir} after successful acme.sh run.");
        }

        $fullChainPath = str_replace($safeName . '.cer', 'fullchain.cer', $certPath);
        $keyPath = str_replace($safeName . '.cer', $safeName . '.key', $certPath);

        $issuedCert = file_get_contents(file_exists($fullChainPath) ? $fullChainPath : $certPath);
        $privateKey = file_exists($keyPath) ? file_get_contents($keyPath) : null;

        $certService = app(CertificateService::class);
        
        $certInfo = $certService->getCertInfo($issuedCert);
        $expiry = isset($certInfo['validTo_time_t']) ? date('Y-m-d H:i:s', $certInfo['validTo_time_t']) : null;
        $issuer = $certInfo['issuer']['CN'] ?? 'Unknown';

        $updateData = [
            'certificate' => $issuedCert,
            'status' => 'issued',
            'request_type' => 'acme',
            'expiry_date' => $expiry,
            'issuer' => $issuer,
            'thumbprint_sha1' => $certService->extractThumbprint($issuedCert, 'sha1'),
            'thumbprint_sha256' => $certService->extractThumbprint($issuedCert, 'sha256'),
        ];

        if ($privateKey) {
            $updateData['private_key'] = encrypt($privateKey);
        }

        $certificate->update($updateData);

        AuditLog::log('acme_fulfill_success', "Fulfilled ACME certificate via acme.sh for domain: {$certificate->domain->name}");

        // Also save to our app storage for consistency
        $appPath = "certificates/" . $certificate->domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        Storage::disk('local')->put($appPath . "/certificate.cer", $issuedCert);
        
        // Generate ssl.conf for this new request
        $dn = $certService->extractDnFromCert($certInfo);
        $sans = $certService->extractSansFromCert($certInfo);
        $certService->saveSslConfig($appPath, $dn, $sans);

        return true;
    }
}
