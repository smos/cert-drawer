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
                Log::info("Certificate content already exists on Fortigate. We will try to update references using the predicted name: {$certName}");
            } else {
                Log::error("Fortigate Import Failed for {$certName}: " . $response->body());
                throw new Exception("Failed to import certificate on Fortigate: " . ($errorData['message'] ?? $response->body()));
            }
        } else {
            Log::info("Successfully imported cert {$certName} on Fortigate at {$host}");

            // Update references
            $this->updateReferences($automation, $certificate, $certName);

            return true;
        }
    }

    /**
     * Update all references from old certificates of this domain to the new one.
     */
    protected function updateReferences(Automation $automation, Certificate $certificate, string $newCertName)
    {
        $roles = $automation->config['roles'] ?? [];

        // 1. Update SSL VPN if role is enabled
        if (!empty($roles['vpn_ssl'])) {
            $this->updateSslVpnReference($automation, $newCertName);
        }

        // 2. Update Admin GUI if role is enabled
        if (!empty($roles['web_ui'])) {
            $this->updateAdminGuiReference($automation, $newCertName);
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
            Log::error("Failed to update Fortigate SSL VPN reference: " . $response->body());
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
            Log::error("Failed to update Fortigate Admin GUI reference: " . $response->body());
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
