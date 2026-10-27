<?php

namespace App\Services;

use App\Models\Automation;
use App\Models\Certificate;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KempService
{
    /**
     * Deploy a certificate to a Kemp Loadmaster using API v2.
     * Uploads the certificate with a unique name (auto_<safename>_<id>),
     * re-binds referring Virtual Services, and removes expired unbound certificates.
     */
    public function deploy(Automation $automation, Certificate $certificate)
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";

        // 1. Prepare Full Combined PEM data (End-Entity + Private Key + Intermediates + Root)
        $privateKey = decrypt($certificate->private_key);

        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate->certificate, $matches);
        $endEntityCert = $matches[0] ?? $certificate->certificate;

        $pemBundle = $endEntityCert . "\n" . $privateKey . "\n";

        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate->certificate, $allCerts);
        if (isset($allCerts[0]) && count($allCerts[0]) > 1) {
            for ($i = 1; $i < count($allCerts[0]); $i++) {
                $pemBundle .= $allCerts[0][$i] . "\n";
            }
        }

        $curr = $certificate;
        while ($curr && $curr->issuer_certificate_id) {
            $curr = Certificate::find($curr->issuer_certificate_id);
            if ($curr) {
                if (!str_contains($pemBundle, $curr->certificate)) {
                    $pemBundle .= $curr->certificate . "\n";
                }
            } else {
                break;
            }
        }

        $base64Data = base64_encode($pemBundle);

        // 2. Check if this specific certificate ID already exists on device to set 'replace'
        $existingCerts = $this->listCerts($automation);
        $exists = false;
        foreach ($existingCerts as $c) {
            if (($c['name'] ?? '') === $certName) {
                $exists = true;
                break;
            }
        }

        $url = "https://{$hostname}/accessv2";

        // 3. Upload Certificate
        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'addcert',
                'apikey' => $apiKey,
                'cert' => $certName,
                'data' => $base64Data,
                'replace' => $exists ? 1 : 0
            ]);

        if (!$response->successful() || ($response->json()['status'] ?? '') === 'fail') {
            Log::error("Kemp AddCert Failed for {$certName}: " . $response->body());
            $msg = $response->json()['error'] ?? $response->json()['message'] ?? $response->body();
            throw new Exception("Failed to upload certificate to Kemp: " . $msg);
        }

        Log::info("Successfully uploaded cert {$certName} to Kemp at {$hostname} (Replace: " . ($exists ? 'Yes' : 'No') . ")");

        $errors = [];

        // 4. Update Virtual Services referencing the certificate naming convention
        try {
            $this->updateVirtualServices($automation, $certName, $certificate->domain->name);
        } catch (Exception $e) {
            $errors[] = "Virtual Services: " . $e->getMessage();
        }

        // 5. Cleanup expired old certificates (only if expired, unbound, and not the active certificate)
        try {
            $this->cleanupExpiredCerts($automation, $certificate->domain->name, $certName);
        } catch (Exception $e) {
            Log::warning("Kemp Expired Certificate Cleanup failed: " . $e->getMessage());
        }

        if (!empty($errors)) {
            throw new Exception("Certificate uploaded as '{$certName}', but failed to apply some changes: " . implode('; ', $errors));
        }

        return true;
    }

    /**
     * List all Virtual Services on the Kemp device.
     */
    public function listVirtualServices(Automation $automation): array
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/accessv2";

        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'listvs',
                'apikey' => $apiKey
            ]);

        if (!$response->successful()) {
            $error = $response->json()['error'] ?? $response->json()['message'] ?? $response->body();
            throw new Exception("Failed to list Virtual Services: " . $error);
        }

        $data = $response->json();
        if (($data['status'] ?? '') === 'fail') {
            throw new Exception("Kemp API Error: " . ($data['error'] ?? $data['message'] ?? 'Unknown error'));
        }

        return $data['VS'] ?? [];
    }

    /**
     * Update Virtual Services referring to the old certificate to use the new certificate.
     */
    public function updateVirtualServices(Automation $automation, string $newCertName, string $domainName): array
    {
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $prefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        $virtualServices = $this->listVirtualServices($automation);
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/accessv2";

        $updated = [];
        $errors = [];

        foreach ($virtualServices as $vs) {
            $vsIndex = $vs['Index'] ?? null;
            $vsNickName = $vs['NickName'] ?? "VS #{$vsIndex}";
            $currentCert = $vs['CertFile'] ?? '';

            // Check if this VS refers to this certificate family (versioned or legacy)
            $isManaged = ($currentCert === $legacyName) || str_starts_with($currentCert, $prefix);

            if ($isManaged) {
                if ($currentCert !== $newCertName) {
                    Log::info("Updating Kemp Virtual Service #{$vsIndex} ('{$vsNickName}') to use '{$newCertName}' (was '{$currentCert}')");

                    $response = Http::withoutVerifying()->post($url, [
                        'cmd' => 'modvs',
                        'apikey' => $apiKey,
                        'vs' => $vsIndex,
                        'CertFile' => $newCertName,
                    ]);

                    if (!$response->successful() || ($response->json()['status'] ?? '') === 'fail') {
                        $msg = $response->json()['message'] ?? $response->json()['error'] ?? $response->body();
                        Log::error("Failed to update Kemp VS #{$vsIndex} ('{$vsNickName}'): {$msg}");
                        $errors[] = "VS #{$vsIndex} ('{$vsNickName}'): {$msg}";
                    } else {
                        $updated[] = [
                            'index' => $vsIndex,
                            'name' => $vsNickName,
                            'previous_cert' => $currentCert,
                            'new_cert' => $newCertName,
                        ];
                    }
                } else {
                    Log::info("Kemp Virtual Service #{$vsIndex} ('{$vsNickName}') already using '{$newCertName}'");
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception(implode('; ', $errors));
        }

        return $updated;
    }

    /**
     * Remove expired certificates for the given domain from the Kemp device.
     * Ensures certificates are only deleted if expired, not bound to any Virtual Service,
     * and not the currently active or latest issued certificate for this domain.
     */
    public function cleanupExpiredCerts(Automation $automation, string $domainName, ?string $excludeCertName = null): int
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/accessv2";

        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $prefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        // Find the latest issued certificate for this domain in the database
        $domain = $automation->domain ?? \App\Models\Domain::where('name', $domainName)->first();
        $latestCert = $domain ? $domain->certificates()->where('status', 'issued')->latest()->first() : null;
        $latestCertName = $latestCert ? "auto_{$safeName}_{$latestCert->id}" : null;

        // 1. Re-query all virtual services to ensure we NEVER delete a certificate that is actively bound!
        $virtualServices = $this->listVirtualServices($automation);
        $boundCerts = [];
        foreach ($virtualServices as $vs) {
            if (!empty($vs['CertFile'])) {
                $boundCerts[$vs['CertFile']] = true;
            }
        }

        $allCerts = $this->listCerts($automation);
        $deleted = 0;

        foreach ($allCerts as $cert) {
            $name = $cert['name'] ?? '';

            // Check if the certificate belongs to this domain family
            if (str_starts_with($name, $prefix) || $name === $legacyName) {
                // NEVER delete the certificate currently being deployed
                if ($excludeCertName && $name === $excludeCertName) {
                    Log::info("Skipping Kemp certificate '{$name}' - currently being deployed.");
                    continue;
                }

                // NEVER delete the latest issued certificate for the domain in the database
                if ($latestCertName && $name === $latestCertName) {
                    Log::info("Skipping Kemp certificate '{$name}' - latest issued certificate for this domain.");
                    continue;
                }

                // If actively bound to any Virtual Service, do NOT delete
                if (isset($boundCerts[$name])) {
                    Log::info("Skipping Kemp certificate '{$name}' - currently bound to a Virtual Service.");
                    continue;
                }

                // Check if the certificate is expired
                $pem = $this->getCert($automation, $name);
                if ($this->isCertExpired($name, $pem)) {
                    Log::info("Cleaning up EXPIRED unbound Kemp certificate: {$name}");

                    $response = Http::withoutVerifying()->post($url, [
                        'cmd' => 'delcert',
                        'apikey' => $apiKey,
                        'cert' => $name,
                    ]);

                    if ($response->successful() && ($response->json()['status'] ?? '') !== 'fail') {
                        $deleted++;
                        Log::info("Successfully deleted expired Kemp certificate: {$name}");
                    } else {
                        $msg = $response->json()['message'] ?? $response->json()['error'] ?? $response->body();
                        Log::warning("Failed to delete Kemp certificate '{$name}': {$msg}");
                    }
                } else {
                    Log::info("Skipping Kemp certificate '{$name}' - it is not expired.");
                }
            }
        }

        return $deleted;
    }

    /**
     * Check if a certificate is expired based on local DB record or device PEM.
     */
    protected function isCertExpired(string $name, ?string $pem = null): bool
    {
        $parts = explode('_', $name);
        $id = end($parts);

        if (is_numeric($id)) {
            $localCert = Certificate::find($id);
            if ($localCert) {
                if ($localCert->archived_at || ($localCert->expiry_date && $localCert->expiry_date->isPast())) {
                    return true;
                }
                return false;
            }
        }

        // Fallback: parse certificate PEM if available
        if ($pem) {
            $certService = app(CertificateService::class);
            $info = $certService->getCertInfo($pem);
            if ($info && isset($info['validTo_time_t'])) {
                return $info['validTo_time_t'] < time();
            }
        }

        return false;
    }

    /**
     * List certificates on the Kemp device.
     */
    public function listCerts(Automation $automation)
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/accessv2";

        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'listcert',
                'apikey' => $apiKey
            ]);

        if (!$response->successful()) {
            $error = $response->json()['error'] ?? $response->json()['message'] ?? $response->body();
            throw new Exception("Failed to list certificates: " . $error);
        }

        $data = $response->json();
        if (($data['status'] ?? '') === 'fail') {
            throw new Exception("Kemp API Error: " . ($data['error'] ?? $data['message'] ?? 'Unknown error'));
        }

        return $data['cert'] ?? [];
    }

    /**
     * Check the status of the automation on the device.
     */
    public function checkStatus(Automation $automation, Certificate $certificate)
    {
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";
        $prefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        $existing = $this->getCert($automation, $certName);

        $status = [
            'cert_name' => $certName,
            'exists_on_device' => !is_null($existing),
            'needs_update' => is_null($existing),
            'message' => '',
            'details' => [
                'virtual_services' => [],
            ],
        ];

        if ($existing) {
            $certService = app(CertificateService::class);
            $existingInfo = $certService->getCertInfo($existing);
            $localInfo = $certService->getCertInfo($certificate->certificate);

            $existingSerial = $existingInfo ? ($existingInfo['serialNumber'] ?? 'unknown') : 'unparseable';
            $localSerial = $localInfo ? ($localInfo['serialNumber'] ?? 'unknown') : 'unparseable';
            $existingThumb = $certService->extractThumbprint($existing, 'sha256');
            $localThumb = $certificate->thumbprint_sha256;

            $status['details']['device_cert'] = [
                'serial' => $existingSerial,
                'thumbprint' => $existingThumb,
                'expiry' => ($existingInfo && isset($existingInfo['validTo_time_t'])) ? date('Y-m-d H:i:s', $existingInfo['validTo_time_t']) : 'unknown',
            ];

            $status['details']['local_cert'] = [
                'serial' => $localSerial,
                'thumbprint' => $localThumb,
                'expiry' => $certificate->expiry_date,
            ];
        }

        // Check Virtual Services
        try {
            $virtualServices = $this->listVirtualServices($automation);
            $matchedCount = 0;
            $upToDateCount = 0;

            foreach ($virtualServices as $vs) {
                $currentCert = $vs['CertFile'] ?? '';
                $isManaged = ($currentCert === $certName) || ($currentCert === $legacyName) || str_starts_with($currentCert, $prefix);

                if ($isManaged) {
                    $matchedCount++;
                    $isUpToDate = ($currentCert === $certName);
                    if ($isUpToDate) {
                        $upToDateCount++;
                    } else {
                        $status['needs_update'] = true;
                    }

                    $status['details']['virtual_services'][] = [
                        'index' => $vs['Index'] ?? null,
                        'name' => $vs['NickName'] ?? ("VS #" . ($vs['Index'] ?? '')),
                        'current_cert' => $currentCert,
                        'up_to_date' => $isUpToDate,
                    ];
                }
            }

            if (!$status['exists_on_device']) {
                $status['message'] = "Certificate '{$certName}' not found on device.";
            } elseif ($matchedCount === 0) {
                $status['message'] = "Certificate '{$certName}' is installed on device, but no matching Virtual Services were found referencing this certificate.";
            } elseif ($upToDateCount === $matchedCount) {
                $status['message'] = "Certificate '{$certName}' is installed and all {$matchedCount} matching Virtual Service(s) are up to date.";
            } else {
                $outdatedCount = $matchedCount - $upToDateCount;
                $status['message'] = "Certificate '{$certName}' is installed on device, but {$outdatedCount} of {$matchedCount} Virtual Service(s) still point to an older certificate version.";
            }
        } catch (Exception $e) {
            $status['details']['vs_error'] = $e->getMessage();
            if ($status['exists_on_device']) {
                $status['message'] = "Certificate '{$certName}' found on device, but failed to inspect Virtual Services: " . $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Get a specific certificate from the Kemp device.
     */
    public function getCert(Automation $automation, string $certName)
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/accessv2";

        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'readcert',
                'apikey' => $apiKey,
                'cert' => $certName
            ]);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        if (($data['status'] ?? '') === 'ok' && isset($data['certificate'])) {
            return $data['certificate'];
        }

        return null;
    }
}
