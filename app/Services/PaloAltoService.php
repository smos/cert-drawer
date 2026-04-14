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
        
        // Build PEM Bundle: [End-Entity Cert] -> [Private Key] -> [Intermediates] -> [Root]
        // 1. Get ONLY the first certificate from the blob (the end-entity)
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate->certificate, $matches);
        $endEntityCert = $matches[0] ?? $certificate->certificate;
        
        $pemBundle = $endEntityCert . "\n" . $privateKey . "\n";
        
        // 2. Add the rest of the certificates in the original blob (if any)
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificate->certificate, $allCerts);
        if (count($allCerts[0]) > 1) {
            for ($i = 1; $i < count($allCerts[0]); $i++) {
                $pemBundle .= $allCerts[0][$i] . "\n";
            }
        }

        // 3. Follow the issuer chain to append other CAs in the database
        $curr = $certificate;
        while ($curr && $curr->issuer_certificate_id) {
            $curr = \App\Models\Certificate::find($curr->issuer_certificate_id);
            if ($curr) {
                if (!str_contains($pemBundle, $curr->certificate)) {
                    $pemBundle .= $curr->certificate . "\n";
                }
            } else {
                break;
            }
        }

        // Palo Alto Import API
        // category=keypair, format=pem
        // CRITICAL: Parameter 'passphrase' is required for keypair imports. 
        // We use a dummy passphrase as the key is not actually encrypted, but the API requires it to be at least 6 chars.
        $url = "https://{$host}/api/?type=import&category=keypair&certificate-name=" . urlencode($certName) . "&format=pem&passphrase=paloalto";

        // Palo Alto requires a multipart file upload
        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->attach('file', $pemBundle, "{$certName}.pem")
            ->post($url);

        if (!$response->successful()) {
            Log::error("Palo Alto Keypair Import Failed for {$certName}: " . $response->body());
            throw new Exception("Failed to import keypair on Palo Alto: " . $response->body());
        }

        // Check if XML response indicates success
        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success') {
            $msg = $xml ? (string)$xml->msg : "Unknown error";
            Log::error("Palo Alto API Error for {$certName}: " . $response->body());
            throw new Exception("Palo Alto API Error: " . $msg);
        }

        Log::info("Successfully imported keypair {$certName} on Palo Alto at {$host}");

        // 1. Update SSL/TLS Service Profiles
        $this->updateProfileReferences($automation, $certName);

        // 2. Update Decryption Rules
        $this->updateDecryptionRules($automation, $certName, $certificate->domain->name);

        // 3. Cleanup old/expired certificates for this domain
        $this->cleanupExpiredCerts($automation, $certificate->domain->name);

        // 4. Final Commit
        $this->commit($automation);

        return true;
    }

    /**
     * Perform a commit on the Palo Alto device.
     */
    public function commit(Automation $automation)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $url = "https://{$host}/api/?type=op&cmd=<commit></commit>";

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->post($url);

        if (!$response->successful()) {
            Log::error("Palo Alto Commit Failed: " . $response->body());
            return false;
        }

        Log::info("Successfully triggered commit on Palo Alto at {$host}");
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
     * Update Decryption Rules to use the new certificate.
     */
    protected function updateDecryptionRules(Automation $automation, string $newCertName, string $domainName)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        // Consistent naming convention: auto_domain_id
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $oldCertPrefix = "auto_{$safeName}_";

        // Fetch all decryption rules
        // Path might vary based on vsys, trying vsys1 as default
        $xpath = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/rulebase/decryption/rules";
        $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->get($url);

        if (!$response->successful()) {
            Log::error("Failed to fetch Palo Alto Decryption Rules: " . $response->body());
            return;
        }

        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success' || !isset($xml->result->rules->entry)) {
            return;
        }

        foreach ($xml->result->rules->entry as $rule) {
            $ruleName = (string)$rule['name'];
            $currentCert = (string)$rule->{'ssl-forward-proxy'}->certificate;
            
            // If the rule uses a certificate that matches our auto-managed pattern for this domain
            if (str_starts_with($currentCert, $oldCertPrefix) && $currentCert !== $newCertName) {
                Log::info("Updating Palo Alto Decryption Rule '{$ruleName}' to use '{$newCertName}' (was '{$currentCert}')");
                
                $setPath = "{$xpath}/entry[@name='{$ruleName}']/ssl-forward-proxy/certificate";
                $setUrl = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($setPath) . "&element=" . urlencode("<certificate>{$newCertName}</certificate>");

                $setResponse = Http::withoutVerifying()
                    ->withHeaders(['X-PAN-KEY' => $key])
                    ->post($setUrl);

                if (!$setResponse->successful()) {
                    Log::error("Failed to update Palo Alto Decryption Rule '{$ruleName}': " . $setResponse->body());
                }
            }
        }
    }

    /**
     * Remove expired certificates for the given domain from the Palo Alto device.
     */
    public function cleanupExpiredCerts(Automation $automation, string $domainName)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $prefix = "auto_{$safeName}_";

        $certs = $this->listCerts($automation);
        $deleted = 0;

        foreach ($certs as $cert) {
            $name = $cert['name'];
            if (str_starts_with($name, $prefix)) {
                // Extract the ID from the name auto_domain_ID
                $parts = explode('_', $name);
                $id = end($parts);
                
                $localCert = \App\Models\Certificate::find($id);
                // If the certificate is not found in our DB OR is marked as expired/archived in our DB
                // OR if the expiry on the device is in the past
                $isExpiredOnDevice = isset($cert['expiry']) && strtotime($cert['expiry']) < time();
                $isOldInDb = !$localCert || $localCert->archived_at || ($localCert->expiry_date && $localCert->expiry_date->isPast());

                if ($isExpiredOnDevice || $isOldInDb) {
                    Log::info("Cleaning up old Palo Alto certificate: {$name}");
                    
                    $xpath = "/config/shared/certificate/entry[@name='{$name}']";
                    $url = "https://{$host}/api/?type=config&action=delete&xpath=" . urlencode($xpath);

                    $response = Http::withoutVerifying()
                        ->withHeaders(['X-PAN-KEY' => $key])
                        ->post($url);

                    if ($response->successful()) {
                        $deleted++;
                    } else {
                        Log::error("Failed to delete Palo Alto certificate {$name}: " . $response->body());
                    }
                }
            }
        }

        if ($deleted > 0) {
            $this->commit($automation);
        }

        return $deleted;
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
