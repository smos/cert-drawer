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
     */
    public function deploy(Automation $automation, Certificate $certificate)
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        
        // Use underscores for dots to be safe and consistent with modern naming conventions
        $certName = "auto_" . str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);

        // 1. Prepare Combined PEM data (Certificate + Private Key)
        // Note: Kemp API v2 expects a combined PEM string encoded in Base64 for 'addcert'
        $privateKey = decrypt($certificate->private_key);
        $combinedPem = $certificate->certificate . "\n" . $privateKey;
        $base64Data = base64_encode($combinedPem);

        // 2. Check if certificate already exists to decide on 'replace' flag
        $existingCerts = $this->listCerts($automation);
        $exists = false;
        foreach ($existingCerts as $c) {
            if (($c['name'] ?? '') === $certName) {
                $exists = true;
                break;
            }
        }

        $url = "https://{$hostname}/accessv2";

        // 3. Upload/Replace Certificate
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

        Log::info("Successfully deployed cert {$certName} to Kemp at {$hostname} (Replace: " . ($exists ? 'Yes' : 'No') . ")");
        return true;
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
            $error = $response->json()['error'] ?? $response->body();
            throw new Exception("Failed to list certificates: " . $error);
        }

        $data = $response->json();
        if (($data['status'] ?? '') === 'fail') {
            throw new Exception("Kemp API Error: " . ($data['error'] ?? 'Unknown error'));
        }

        return $data['cert'] ?? [];
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
