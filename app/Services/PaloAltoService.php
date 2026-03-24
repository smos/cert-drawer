<?php

namespace App\Services;

use App\Models\Automation;
use App\Models\Certificate;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaloAltoService
{
    /**
     * Deploy a certificate to a Palo Alto firewall.
     */
    public function deploy(Automation $automation, Certificate $certificate)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        // Consistent naming convention
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";

        $privateKey = decrypt($certificate->private_key);
        
        // Build Full CA chain (Intermediates + Root)
        // Palo Alto requires the full bundle in a single PEM for SSL Decryption
        $pemBundle = $certificate->certificate . "\n" . $privateKey . "\n";
        
        $curr = $certificate;
        while ($curr && $curr->issuer_certificate_id) {
            $curr = \App\Models\Certificate::find($curr->issuer_certificate_id);
            if ($curr) {
                $pemBundle .= $curr->certificate . "\n";
            } else {
                break;
            }
        }

        // Palo Alto Import API
        // type=import, category=certificate, format=pem
        // CRITICAL: We include Cert + Key + All Chain Certs in one PEM for SSL Decryption support
        $url = "https://{$host}/api/?type=import&category=certificate&certificate-name=" . urlencode($certName) . "&format=pem";

        // Palo Alto requires a multipart file upload
        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->attach('file', $pemBundle, "{$certName}.pem")
            ->post($url);

        if (!$response->successful()) {
            Log::error("Palo Alto Import Failed for {$certName}: " . $response->body());
            throw new Exception("Failed to import certificate on Palo Alto: " . $response->body());
        }

        // Check if XML response indicates success
        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success') {
            $msg = $xml ? (string)$xml->msg : "Unknown error";
            Log::error("Palo Alto API Error for {$certName}: " . $response->body());
            throw new Exception("Palo Alto API Error: " . $msg);
        }

        Log::info("Successfully imported cert {$certName} on Palo Alto at {$host}");

        // Optional: Update SSL/TLS Service Profile if configured
        $this->updateProfileReferences($automation, $certName);

        return true;
    }

    /**
     * Update SSL/TLS Service Profiles to use the new certificate.
     */
    protected function updateProfileReferences(Automation $automation, string $certName)
    {
        $profilesString = $automation->config['profiles_string'] ?? '';
        if (empty($profilesString)) {
            return;
        }

        $profiles = array_filter(array_map('trim', explode(',', $profilesString)));
        if (empty($profiles)) {
            return;
        }

        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();

        foreach ($profiles as $profileName) {
            if (empty($profileName)) continue;

            // type=config, action=set, xpath=/config/shared/ssl-tls-service-profile/entry[@name='PROFILE']/certificate
            $xpath = "/config/shared/ssl-tls-service-profile/entry[@name='{$profileName}']/certificate";
            $url = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($xpath) . "&element=" . urlencode("<certificate>{$certName}</certificate>");

            $response = Http::withoutVerifying()
                ->withHeaders(['X-PAN-KEY' => $key])
                ->post($url);

            if (!$response->successful()) {
                Log::error("Failed to update Palo Alto Profile {$profileName}: " . $response->body());
            } else {
                Log::info("Successfully updated Palo Alto Profile {$profileName} to use {$certName}");
            }
        }
    }

    /**
     * List certificates on the Palo Alto device.
     */
    public function listCerts(Automation $automation)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        // type=config, action=get, xpath=/config/shared/certificate
        $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode("/config/shared/certificate");

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->get($url);

        if (!$response->successful()) {
            throw new Exception("Failed to list Palo Alto certificates: " . $response->status());
        }

        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success') {
            throw new Exception("Palo Alto API Error: " . ($xml ? (string)$xml->msg : "Invalid XML"));
        }

        $certs = [];
        if (isset($xml->result->certificate->entry)) {
            foreach ($xml->result->certificate->entry as $entry) {
                $certs[] = [
                    'name' => (string)$entry['name'],
                    'common_name' => (string)$entry->common_name,
                    'expiry' => (string)$entry->not_valid_after,
                ];
            }
        }

        return $certs;
    }

    /**
     * Get a specific certificate from the Palo Alto device.
     */
    public function getCert(Automation $automation, string $certName)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $xpath = "/config/shared/certificate/entry[@name='{$certName}']";
        $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->get($url);

        if (!$response->successful()) {
            return null;
        }

        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success' || !isset($xml->result->entry)) {
            return null;
        }

        return (array)$xml->result->entry;
    }
}
