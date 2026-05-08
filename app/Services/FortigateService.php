<?php

namespace App\Services;

use App\Models\Automation;
use App\Models\Certificate;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FortigateService
{
    /**
     * Deploy a certificate to a Fortigate firewall.
     */
    public function deploy(Automation $automation, Certificate $certificate)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        
        // Use a more predictable name: domain_name_certID
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";

        $privateKey = decrypt($certificate->private_key);
        
        // Build CA chain
        $chain = [];
        $curr = $certificate;
        while ($curr && $curr->issuer_certificate_id) {
            $curr = \App\Models\Certificate::find($curr->issuer_certificate_id);
            if ($curr) {
                $chain[] = $curr->certificate;
            } else {
                break;
            }
        }

        // Generate PFX
        $pfxPassword = bin2hex(random_bytes(8));
        $pfxData = app(CertificateService::class)->generatePfx($certificate->certificate, $privateKey, $pfxPassword, $chain);

        // Use the MONITOR endpoint which works for v7+ imports
        $url = "https://{$host}/api/v2/monitor/vpn-certificate/local/import";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post($url, [
                'type' => 'pkcs12',
                'scope' => 'global',
                'certname' => $certName,
                'file_content' => base64_encode($pfxData),
                'password' => $pfxPassword,
            ]);

        if (!$response->successful()) {
            $errorData = $response->json();
            // Error -328 usually means the certificate content already exists on the device
            if (($errorData['error'] ?? 0) === -328) {
                Log::info("Certificate content already exists on Fortigate. Finding existing certificate name...");
                
                // Try to find the name of the certificate that already exists
                $existingName = $this->findExistingCertName($automation, $certificate);
                if ($existingName) {
                    Log::info("Found existing certificate '{$existingName}' matching local certificate. Updating references...");
                    $this->updateReferences($automation, $certificate, $existingName);
                    return true;
                } else {
                    // Fallback to predicted name if we can't find it, maybe it matches by name already
                    Log::warning("Could not find certificate by content, but Fortigate says it exists. Trying references with {$certName}");
                    $this->updateReferences($automation, $certificate, $certName);
                    return true;
                }
            } else {
                Log::error("Fortigate Import Failed for {$certName}: " . $response->body());
                throw new Exception("Failed to import certificate on Fortigate: " . ($errorData['message'] ?? $response->body()));
            }
        }

        Log::info("Successfully imported cert {$certName} on Fortigate at {$host}");

        // Update references
        $this->updateReferences($automation, $certificate, $certName);

        return true;
    }

    /**
     * Try to find the name of a certificate on the device that matches the local one.
     */
    protected function findExistingCertName(Automation $automation, Certificate $certificate)
    {
        $certs = $this->listCerts($automation);
        $localSerial = strtolower(str_replace(' ', '', $certificate->serial_number));
        
        foreach ($certs as $c) {
            $name = $c['name'] ?? '';
            // We need to fetch details for each to get the serial
            try {
                $details = $this->getCert($automation, $name);
                if ($details && isset($details['serial'])) {
                    $devSerial = strtolower(str_replace(' ', '', $details['serial']));
                    if ($devSerial === $localSerial && !empty($localSerial)) {
                        return $name;
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Update all references from old certificates of this domain to the new one.
     */
    protected function updateReferences(Automation $automation, Certificate $certificate, string $newCertName)
    {
        $roles = $automation->config['roles'] ?? [];
        $errors = [];

        // 1. Update SSL VPN if role is enabled
        if (!empty($roles['vpn_ssl'])) {
            try {
                $this->updateSslVpnReference($automation, $newCertName);
            } catch (Exception $e) {
                $errors[] = "SSL VPN: " . $e->getMessage();
            }
        }

        // 2. Update Admin GUI if role is enabled
        if (!empty($roles['web_ui'])) {
            try {
                $this->updateAdminGuiReference($automation, $newCertName);
            } catch (Exception $e) {
                $errors[] = "Admin GUI: " . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new Exception("Certificate imported, but failed to update some references: " . implode('; ', $errors));
        }
    }

    /**
     * Update SSL VPN settings to use the new certificate.
     */
    protected function updateSslVpnReference(Automation $automation, string $certName)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/vpn.ssl/settings/?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->put($url, [
                'servercert' => $certName
            ]);

        if (!$response->successful()) {
            $msg = $response->json()['message'] ?? $response->body();
            Log::error("Failed to update Fortigate SSL VPN reference: " . $msg);
            throw new Exception($msg);
        } else {
            Log::info("Successfully updated Fortigate SSL VPN reference to {$certName}");
        }
    }

    /**
     * Update System Global Admin GUI certificate.
     */
    protected function updateAdminGuiReference(Automation $automation, string $certName)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/system/global/?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->put($url, [
                'admin-server-cert' => $certName
            ]);

        if (!$response->successful()) {
            $msg = $response->json()['message'] ?? $response->body();
            Log::error("Failed to update Fortigate Admin GUI reference: " . $msg);
            throw new Exception($msg);
        } else {
            Log::info("Successfully updated Fortigate Admin GUI reference to {$certName}");
        }
    }

    /**
     * List local certificates on the Fortigate device.
     */
    public function listCerts(Automation $automation)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/certificate/local/?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get($url);

        if (!$response->successful()) {
            throw new Exception("Failed to list Fortigate certificates: " . $response->status());
        }

        $data = $response->json();
        return $data['results'] ?? [];
    }

    /**
     * Remove expired certificates for the given domain from the Fortigate device.
     */
    public function cleanupExpiredCerts(Automation $automation, string $domainName)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $prefix = "auto_{$safeName}_";

        $certs = $this->listCerts($automation);
        $deleted = 0;

        foreach ($certs as $cert) {
            $name = $cert['name'] ?? '';
            
            // Never cleanup the one we JUST imported (latest in DB)
            $latestId = $automation->domain->certificates()->where('status', 'issued')->latest()->first()?->id;
            if ($name === "auto_{$safeName}_{$latestId}") {
                continue;
            }

            if (str_starts_with($name, $prefix)) {
                // Extract the ID from the name auto_domain_ID
                $parts = explode('_', $name);
                $id = end($parts);
                
                $localCert = \App\Models\Certificate::find($id);
                
                // Get details for expiry check
                $details = $this->getCert($automation, $name);
                $expiryTime = !empty($details['valid_to']) ? strtotime($details['valid_to']) : null;
                $isExpiredOnDevice = $expiryTime && $expiryTime < time();
                
                $isOldInDb = !$localCert || $localCert->archived_at || ($localCert->expiry_date && $localCert->expiry_date->isPast());

                if ($isExpiredOnDevice || $isOldInDb) {
                    Log::info("Cleaning up old Fortigate certificate: {$name}");
                    
                    $url = "https://{$host}/api/v2/cmdb/certificate/local/" . urlencode($name) . "/?vdom=root";

                    $response = Http::withoutVerifying()
                        ->withHeaders(['Authorization' => "Bearer {$token}"])
                        ->delete($url);

                    if ($response->successful()) {
                        $deleted++;
                    } else {
                        // Fortigate might block deletion if it is still in use
                        Log::warning("Failed to delete Fortigate certificate {$name} (might still be in use): " . $response->body());
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Check the status of the automation on the device.
     */
    public function checkStatus(Automation $automation, Certificate $certificate)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        
        // Predict the name we would use if we deployed now
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";

        $certs = $this->listCerts($automation);
        $existing = null;
        foreach ($certs as $c) {
            if (($c['name'] ?? '') === $certName) {
                $existing = $c;
                break;
            }
        }

        $status = [
            'cert_name' => $certName,
            'exists_on_device' => !is_null($existing),
            'needs_update' => is_null($existing),
            'message' => $existing ? "Exact certificate version '{$certName}' found on Fortigate." : "Certificate '{$certName}' not found on Fortigate.",
            'details' => []
        ];

        if ($existing) {
            $deviceCert = $this->getCert($automation, $certName);
            $status['details']['device_cert'] = [
                'name' => $existing['name'],
                'serial' => $deviceCert['serial'] ?? 'unknown',
                'expiry' => $deviceCert['valid_to'] ?? 'unknown',
                // Fortigate API returns some of these directly in the object
            ];
            $status['details']['local_cert'] = [
                'serial' => $certificate->serial_number,
                'thumbprint' => $certificate->thumbprint_sha256,
                'expiry' => $certificate->expiry_date,
            ];

            // If we have serials, we can compare them for extra certainty
            if (isset($deviceCert['serial']) && $certificate->serial_number) {
                 // Simple cleanup of serial comparison
                 $devSerial = strtolower(str_replace(' ', '', $deviceCert['serial']));
                 $locSerial = strtolower(str_replace(' ', '', $certificate->serial_number));
                 if ($devSerial !== $locSerial) {
                     $status['needs_update'] = true;
                     $status['message'] = "Certificate '{$certName}' version mismatch (Serial: {$deviceCert['serial']} vs {$certificate->serial_number}).";
                 }
            }
        }

        // Check roles
        $roles = $automation->config['roles'] ?? [];
        if (!empty($roles['vpn_ssl'])) {
            $vpnUrl = "https://{$host}/api/v2/cmdb/vpn.ssl/settings/?vdom=root";
            $vpnResp = Http::withoutVerifying()->withHeaders(['Authorization' => "Bearer {$token}"])->get($vpnUrl);
            $current = $vpnResp->json()['results']['servercert'] ?? 'unknown';
            $status['details']['vpn_ssl'] = [
                'configured' => true,
                'current_value' => $current,
                'up_to_date' => $current === $certName
            ];
        }
        if (!empty($roles['web_ui'])) {
            $sysUrl = "https://{$host}/api/v2/cmdb/system/global/?vdom=root";
            $sysResp = Http::withoutVerifying()->withHeaders(['Authorization' => "Bearer {$token}"])->get($sysUrl);
            $current = $sysResp->json()['results']['admin-server-cert'] ?? 'unknown';
            $status['details']['web_ui'] = [
                'configured' => true,
                'current_value' => $current,
                'up_to_date' => $current === $certName
            ];
        }

        return $status;
    }

    /**
     * Get a specific certificate from the Fortigate device.
     */
    public function getCert(Automation $automation, string $certName)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/certificate/local/" . urlencode($certName) . "/?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->get($url);

        if (!$response->successful()) {
            return null;
        }

        $data = $response->json();
        return $data['results'][0] ?? null;
    }
}
