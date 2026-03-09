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
        $apiKey = $automation->getDecryptedPassword(); // We store API Key in the password field
        
        $certName = "auto_" . str_replace('*', 'wildcard', $certificate->domain->name);

        // 1. Prepare PFX data
        $tempPassword = bin2hex(random_bytes(8));
        $pfxData = app(CertificateService::class)->generatePfx(
            $certificate->certificate,
            decrypt($certificate->private_key),
            $tempPassword
        );

        $url = "https://{$hostname}/access/show?v=2";

        // 2. Upload/Replace Certificate
        // Note: Kemp API v2 uses a single endpoint with JSON body
        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'addcert',
                'apikey' => $apiKey,
                'cert' => $certName,
                'data' => base64_encode($pfxData),
                'pass' => $tempPassword,
                'replace' => 1
            ]);

        if (!$response->successful() || ($response->json()['status'] ?? '') === 'fail') {
            Log::error("Kemp AddCert Failed: " . $response->body());
            $msg = $response->json()['error'] ?? $response->body();
            throw new Exception("Failed to upload certificate to Kemp: " . $msg);
        }

        Log::info("Successfully deployed cert {$certName} to Kemp at {$hostname}");
        return true;
    }

    /**
     * List certificates on the Kemp device.
     */
    public function listCerts(Automation $automation)
    {
        $hostname = $automation->hostname;
        $apiKey = $automation->getDecryptedPassword();
        $url = "https://{$hostname}/access/show?v=2";

        $response = Http::withoutVerifying()
            ->post($url, [
                'cmd' => 'listcert',
                'apikey' => $apiKey
            ]);

        if (!$response->successful()) {
            throw new Exception("Failed to list certificates: " . $response->body());
        }

        return $response->json()['data'] ?? [];
    }
}
