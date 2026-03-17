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
        
        // Use a unique name with timestamp since Fortigate doesn't allow replacing in-use certs easily
        $timestamp = date('Ymd_Hi');
        $baseName = "auto_" . str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "{$baseName}_{$timestamp}";

        $privateKey = decrypt($certificate->private_key);
        
        // Use CMDB POST to create a new local certificate
        $url = "https://{$host}/api/v2/cmdb/certificate/local?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->post($url, [
                'name' => $certName,
                'certificate' => $certificate->certificate,
                'private-key' => $privateKey
            ]);

        if (!$response->successful()) {
            Log::error("Fortigate CMDB Create Failed for {$certName}: " . $response->body());
            throw new Exception("Failed to create certificate on Fortigate: " . ($response->json()['message'] ?? $response->body()));
        }

        Log::info("Successfully created cert {$certName} on Fortigate at {$host}");

        // Now update references. 
        // For now we'll support SSL VPN settings. We could expand this to Admin GUI, etc.
        $this->updateSslVpnReference($automation, $certName);

        return true;
    }

    /**
     * Update SSL VPN settings to use the new certificate.
     */
    protected function updateSslVpnReference(Automation $automation, string $certName)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/vpn.ssl/settings?vdom=root";

        $response = Http::withoutVerifying()
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->put($url, [
                'servercert' => $certName
            ]);

        if (!$response->successful()) {
            Log::error("Failed to update Fortigate SSL VPN reference: " . $response->body());
            // We don't throw here to avoid failing the whole deployment if just the reference fails
            // but we should probably log it clearly.
        } else {
            Log::info("Successfully updated Fortigate SSL VPN reference to {$certName}");
        }
    }

    /**
     * List local certificates on the Fortigate device.
     */
    public function listCerts(Automation $automation)
    {
        $host = $automation->hostname;
        $token = $automation->getDecryptedPassword();
        $url = "https://{$host}/api/v2/cmdb/certificate/local";

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
        $url = "https://{$host}/api/v2/cmdb/certificate/local/" . urlencode($certName);

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
