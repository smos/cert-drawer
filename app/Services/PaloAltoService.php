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

        Log::info("Successfully imported keypair {$certName} on Palo Alto at {$host}. Committing certificate to store...");

        // ========================================================
        // PIECE 1: Commit the new certificate into the store
        // ========================================================
        // Committing the certificate first ensures that it is permanently registered
        // in the PAN-OS active config before we link it to any profiles or policies.
        $this->commit($automation);
        Log::info("Certificate {$certName} committed successfully to Palo Alto configuration store.");

        // ========================================================
        // PIECE 2: Auto-detect & Link Profiles, Decryption Rules & Cleanup
        // ========================================================
        $errors = [];

        // 1. Auto-detect & Update SSL/TLS Service Profiles
        try {
            $this->updateProfileReferences($automation, $certName, $certificate->domain->name);
        } catch (Exception $e) {
            $errors[] = "Profiles: " . $e->getMessage();
        }

        // 2. Auto-detect & Update Decryption Rules
        try {
            $this->updateDecryptionRules($automation, $certName, $certificate->domain->name);
        } catch (Exception $e) {
            $errors[] = "Decryption Rules: " . $e->getMessage();
        }

        // 3. Cleanup old/expired certificates for this domain (skip commit here, final commit below)
        try {
            $this->cleanupExpiredCerts($automation, $certificate->domain->name, $certName, false);
        } catch (Exception $e) {
            // Cleanup failure is a warning, not a critical error
            Log::warning("Palo Alto Cleanup failed: " . $e->getMessage());
        }

        // 4. Piece 2 Final Commit
        try {
            $this->commit($automation);
            Log::info("Final commit completed successfully on Palo Alto for {$certName}.");
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }

        if (!empty($errors)) {
            throw new Exception("Certificate imported, but failed to apply some changes: " . implode('; ', $errors));
        }

        return true;
    }

    /**
     * Perform a commit on the Palo Alto device.
     */
    public function commit(Automation $automation)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $url = "https://{$host}/api/?type=commit&cmd=<commit></commit>";

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->post($url);

        $errorCodes = [
            '400' => 'Bad request',
            '403' => 'Forbidden (Invalid API key or insufficient rights)',
            '1'   => 'Unknown command',
            '6'   => 'Bad Xpath',
            '7'   => 'Object not present',
            '8'   => 'Object not unique',
            '10'  => 'Reference count not zero',
            '12'  => 'Invalid object',
            '13'  => 'Object not found',
            '14'  => 'Operation not possible',
            '15'  => 'Operation denied',
            '16'  => 'Unauthorized',
            '17'  => 'Invalid command',
            '18'  => 'Malformed command',
            '22'  => 'Session timed out',
        ];

        if (!$response->successful()) {
            $code = $response->status();
            $msg = $errorCodes[$code] ?? "HTTP Error {$code}";
            Log::error("Palo Alto Commit Failed: " . $response->body());
            throw new Exception("Palo Alto Commit Failed: {$msg}");
        }

        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success') {
            $code = (string)($xml['code'] ?? 'unknown');
            $msg = (string)($xml->msg->line ?? $xml->msg ?? "Unknown error");
            $mappedMsg = $errorCodes[$code] ?? $msg;
            
            Log::error("Palo Alto Commit API Error: " . $response->body());
            throw new Exception("Palo Alto Commit API Error [Code {$code}]: {$mappedMsg}");
        }

        $jobId = (string)($xml->result->job ?? '');
        if (!$jobId) {
            Log::info("Successfully triggered commit on Palo Alto at {$host} (No Job ID)");
            return true;
        }

        Log::info("Palo Alto Commit Job #{$jobId} enqueued. Polling for results...");

        // Polling for results (up to 60s)
        $attempts = 0;
        while ($attempts < 12) { // 12 * 5s = 60s
            sleep(5);
            $attempts++;
            
            $statusUrl = "https://{$host}/api/?type=op&cmd=" . urlencode("<show><jobs><id>{$jobId}</id></jobs></show>");
            $statusResp = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($statusUrl);
            
            if ($statusResp->successful()) {
                $statusXml = simplexml_load_string($statusResp->body());
                if ($statusXml && (string)$statusXml['status'] === 'success') {
                    $job = $statusXml->result->job;
                    $status = (string)$job->status; // ACT, FIN
                    $result = (string)$job->result; // PEND, OK, FAIL
                    
                    if ($status === 'FIN') {
                        $warnings = [];
                        if (isset($job->warnings->line)) {
                            foreach ($job->warnings->line as $line) {
                                $warnings[] = (string)$line;
                            }
                        }

                        if ($result === 'FAIL') {
                            $details = [];
                            if (isset($job->details->line)) {
                                foreach ($job->details->line as $line) {
                                    $details[] = (string)$line;
                                }
                            }
                            throw new Exception("Palo Alto Commit Job #{$jobId} FAILED. " . implode('; ', $details) . " " . implode('; ', $warnings));
                        }

                        if (!empty($warnings)) {
                            Log::warning("Palo Alto Commit Job #{$jobId} succeeded with warnings: " . implode('; ', $warnings));
                            session()->flash('automation_warnings', $warnings);
                        }
                        
                        return true;
                    }
                }
            }
        }

        Log::info("Palo Alto Commit Job #{$jobId} is still running after 60s. Continuing...");
        return true;
    }

    /**
     * List all SSL/TLS service profiles from Shared and VSYS1.
     * Returns an associative array keyed by profile name:
     * [
     *     'Profile-Name' => [
     *         'name' => 'Profile-Name',
     *         'location' => 'shared' | 'vsys1',
     *         'certificate' => 'cert_name',
     *         'xpath' => '...',
     *         'cert_xpath' => '...',
     *     ]
     * ]
     */
    public function listProfiles(Automation $automation): array
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        $profiles = [];

        $locations = [
            'shared' => "/config/shared/ssl-tls-service-profile",
            'vsys1' => "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/ssl-tls-service-profile",
        ];

        foreach ($locations as $locKey => $baseXpath) {
            $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($baseXpath);
            try {
                $response = Http::withoutVerifying()
                    ->withHeaders(['X-PAN-KEY' => $key])
                    ->get($url);

                if (!$response->successful()) {
                    continue;
                }

                $xml = simplexml_load_string($response->body());
                if (!$xml || (string)$xml['status'] !== 'success') {
                    continue;
                }

                $entries = null;
                if (isset($xml->result->{'ssl-tls-service-profile'}->entry)) {
                    $entries = $xml->result->{'ssl-tls-service-profile'}->entry;
                } elseif (isset($xml->result->entry)) {
                    $entries = $xml->result->entry;
                }

                if ($entries) {
                    foreach ($entries as $entry) {
                        $pName = (string)$entry['name'];
                        $cert = (string)($entry->certificate ?? '');
                        $profiles[$pName] = [
                            'name' => $pName,
                            'location' => $locKey,
                            'certificate' => $cert,
                            'xpath' => "{$baseXpath}/entry[@name='{$pName}']",
                            'cert_xpath' => "{$baseXpath}/entry[@name='{$pName}']/certificate",
                        ];
                    }
                }
            } catch (Exception $e) {
                Log::warning("Failed to fetch Palo Alto SSL/TLS profiles from {$locKey}: " . $e->getMessage());
            }
        }

        return $profiles;
    }

    /**
     * Update SSL/TLS Service Profiles to use the new certificate.
     * Auto-detects profiles referencing auto_<domain>_* as well as any explicit profiles.
     */
    protected function updateProfileReferences(Automation $automation, string $newCertName, string $domainName)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        $errors = [];

        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $oldCertPrefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        $profilesString = $automation->config['profiles_string'] ?? '';
        $explicitProfiles = array_filter(array_map('trim', explode(',', $profilesString)));

        // 1. Auto-discover all profiles across Shared and VSYS1
        $allProfiles = $this->listProfiles($automation);
        $matchedProfiles = [];

        foreach ($allProfiles as $pName => $profile) {
            $currentCert = $profile['certificate'];
            $isManaged = false;

            // Auto-detect by naming pattern: auto_<domain>_* or legacy auto_<domain>
            if (str_starts_with($currentCert, $oldCertPrefix) || $currentCert === $legacyName) {
                $isManaged = true;
            }

            // Or if explicitly configured in profiles_string
            if (in_array($pName, $explicitProfiles)) {
                $isManaged = true;
                $matchedProfiles[] = $pName;
            }

            if ($isManaged) {
                if ($currentCert !== $newCertName) {
                    Log::info("Updating Palo Alto SSL/TLS Profile '{$pName}' ({$profile['location']}) to use '{$newCertName}' (was '{$currentCert}')");
                    $certXPath = $profile['cert_xpath'];
                    $setUrl = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($certXPath) . "&element=" . urlencode("<certificate>{$newCertName}</certificate>");
                    $this->executeSet($setUrl, $key, $pName, $errors);
                } else {
                    Log::info("Palo Alto SSL/TLS Profile '{$pName}' ({$profile['location']}) already uses '{$newCertName}'");
                }
            }
        }

        // 2. Fallback for any explicitly listed profiles not returned in listProfiles
        $missingProfiles = array_diff($explicitProfiles, $matchedProfiles);
        foreach ($missingProfiles as $pName) {
            if (empty($pName)) continue;

            // Try SHARED first
            $xpath = "/config/shared/ssl-tls-service-profile/entry[@name='{$pName}']/certificate";
            $url = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($xpath) . "&element=" . urlencode("<certificate>{$newCertName}</certificate>");

            $response = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->post($url);
            
            // If Shared fails with "Object not present" (code 7), try VSYS1
            $xml = simplexml_load_string($response->body());
            if ($xml && (string)$xml['code'] === '7') {
                 Log::info("Profile {$pName} not found in Shared. Trying VSYS1...");
                 $vsysPath = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/ssl-tls-service-profile/entry[@name='{$pName}']/certificate";
                 $vsysUrl = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($vsysPath) . "&element=" . urlencode("<certificate>{$newCertName}</certificate>");
                 $response = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->post($vsysUrl);
            }

            if (!$response->successful()) {
                $msg = "{$pName} (HTTP {$response->status()})";
                Log::error("Palo Alto Profile Update Failed: {$msg}");
                $errors[] = $msg;
            } else {
                $xml = simplexml_load_string($response->body());
                if (!$xml || (string)$xml['status'] !== 'success') {
                    $msg = "{$pName} (" . ($xml ? (string)$xml->msg : "XML Error") . ")";
                    Log::error("Palo Alto Profile Update API Error: {$msg}");
                    $errors[] = $msg;
                } else {
                    Log::info("Successfully updated Palo Alto Profile {$pName} to use {$newCertName}");
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception("Failed to update profiles: " . implode(', ', $errors));
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
        $legacyName = "auto_{$safeName}";

        // Fetch all decryption rules
        $xpath = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/rulebase/decryption/rules";
        $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);

        $response = Http::withoutVerifying()
            ->withHeaders(['X-PAN-KEY' => $key])
            ->get($url);

        if (!$response->successful()) {
            throw new Exception("Failed to fetch decryption rules (HTTP {$response->status()})");
        }

        $xml = simplexml_load_string($response->body());
        if (!$xml || (string)$xml['status'] !== 'success') {
            throw new Exception("Failed to fetch decryption rules: " . ($xml ? (string)$xml->msg : "XML Error"));
        }

        if (!isset($xml->result->rules->entry)) {
            return;
        }

        $errors = [];

        foreach ($xml->result->rules->entry as $rule) {
            $ruleName = (string)$rule['name'];
            $isManaged = false;
            $isForwardProxy = false;
            $existingMembers = [];

            // Handle SSL Forward Proxy (Usually 1 cert)
            if (isset($rule->type->{'ssl-forward-proxy'}->certificate)) {
                $isForwardProxy = true;
                $currentCert = (string)$rule->type->{'ssl-forward-proxy'}->certificate;
                if (str_starts_with($currentCert, $oldCertPrefix) || $currentCert === $legacyName) {
                    $isManaged = true;
                }
            } 
            // Handle SSL Inbound Inspection (Can be multiple certs)
            elseif (isset($rule->type->{'ssl-inbound-inspection'}->certificates->member)) {
                foreach ($rule->type->{'ssl-inbound-inspection'}->certificates->member as $member) {
                    $mName = (string)$member;
                    $existingMembers[] = $mName;
                    if (str_starts_with($mName, $oldCertPrefix) || $mName === $legacyName) {
                         $isManaged = true;
                    }
                }
            }
            
            // If the rule is managed by us for this domain
            if ($isManaged) {
                if ($isForwardProxy) {
                    $currentCert = (string)$rule->type->{'ssl-forward-proxy'}->certificate;
                    if ($currentCert !== $newCertName) {
                        Log::info("Updating Palo Alto Forward Proxy Rule '{$ruleName}' to use '{$newCertName}' (was '{$currentCert}')");
                        $setPath = "{$xpath}/entry[@name='{$ruleName}']/type/ssl-forward-proxy/certificate";
                        $setUrl = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($setPath) . "&element=" . urlencode("<certificate>{$newCertName}</certificate>");
                        $this->executeSet($setUrl, $key, $ruleName, $errors);
                    }
                } else {
                    // Inbound Inspection: Add the NEW certificate to the list if not already there
                    if (!in_array($newCertName, $existingMembers)) {
                        Log::info("Adding certificate '{$newCertName}' to Palo Alto Inbound Inspection Rule '{$ruleName}'");
                        // We set on the parent 'certificates' element to add a new 'member'
                        $setPath = "{$xpath}/entry[@name='{$ruleName}']/type/ssl-inbound-inspection/certificates";
                        $setUrl = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($setPath) . "&element=" . urlencode("<member>{$newCertName}</member>");
                        $this->executeSet($setUrl, $key, $ruleName, $errors);
                    }

                    // Lazy removal: Only remove old members from the rule if they are actually expired.
                    // Valid unexpired certificates are retained to provide a seamless overlap period.
                    foreach ($existingMembers as $member) {
                        if ((str_starts_with($member, $oldCertPrefix) || $member === $legacyName) && $member !== $newCertName) {
                            $memberInfo = $this->getCert($automation, $member);
                            if ($this->isCertExpired($member, $memberInfo)) {
                                Log::info("Removing EXPIRED old certificate '{$member}' from Palo Alto Inbound Inspection Rule '{$ruleName}'");
                                $delPath = "{$xpath}/entry[@name='{$ruleName}']/type/ssl-inbound-inspection/certificates/member[text()='{$member}']";
                                $delUrl = "https://{$host}/api/?type=config&action=delete&xpath=" . urlencode($delPath);
                                $this->executeSet($delUrl, $key, $ruleName, $errors);
                            } else {
                                Log::info("Retaining valid old certificate '{$member}' in Palo Alto Inbound Inspection Rule '{$ruleName}' for graceful overlap.");
                            }
                        }
                    }
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception("Certificate imported, but failed to update some rules: " . implode('; ', $errors));
        }
    }

    /**
     * Helper to execute a set command and capture errors.
     */
    protected function executeSet(string $url, string $key, string $ruleName, array &$errors)
    {
        $response = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->post($url);
        if (!$response->successful()) {
            $msg = "Rule '{$ruleName}' (HTTP {$response->status()})";
            Log::error("Palo Alto Rule Update Failed: {$msg}");
            $errors[] = $msg;
        } else {
            $xml = simplexml_load_string($response->body());
            if (!$xml || (string)$xml['status'] !== 'success') {
                $msg = "Rule '{$ruleName}' (" . ($xml ? (string)$xml->msg : "XML Error") . ")";
                Log::error("Palo Alto Rule Update API Error: {$msg}");
                $errors[] = $msg;
            }
        }
    }

    /**
     * Check if a certificate on the device is expired based on local DB or device info.
     */
    protected function isCertExpired(string $name, ?array $deviceCertInfo = null)
    {
        $parts = explode('_', $name);
        $id = end($parts);
        
        if (is_numeric($id)) {
            $localCert = \App\Models\Certificate::find($id);
            if ($localCert) {
                // If archived or expiry date is past, it's expired
                if ($localCert->archived_at || ($localCert->expiry_date && $localCert->expiry_date->isPast())) {
                    return true;
                }
                return false; // Valid in our DB
            }
        }
        
        // Fallback to device-reported expiry if available
        if ($deviceCertInfo && !empty($deviceCertInfo['expiry']) && $deviceCertInfo['expiry'] !== 'unknown') {
             $expiryTime = strtotime($deviceCertInfo['expiry']);
             $now = time();
             Log::debug("Checking expiry for {$name}: Expiry {$expiryTime} vs Now {$now}");
             if ($expiryTime && $expiryTime < $now) {
                 return true;
             }
        }
        
        return false;
    }

    /**
     * Remove expired certificates for the given domain from the Palo Alto device.
     */
    public function cleanupExpiredCerts(Automation $automation, string $domainName, ?string $excludeCertName = null, bool $doCommit = true)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $domainName);
        $prefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        // Find the latest issued certificate for this domain in the database
        $domain = $automation->domain ?? \App\Models\Domain::where('name', $domainName)->first();
        $latestCert = $domain ? $domain->certificates()->where('status', 'issued')->latest('id')->first() : null;
        $latestCertName = $latestCert ? "auto_{$safeName}_{$latestCert->id}" : null;

        $certs = $this->listCerts($automation);
        $allProfiles = $this->listProfiles($automation);
        $deleted = 0;

        foreach ($certs as $cert) {
            $name = $cert['name'];
            
            if (str_starts_with($name, $prefix) || $name === $legacyName) {
                // NEVER delete the certificate currently being deployed
                if ($excludeCertName && $name === $excludeCertName) {
                    Log::info("Skipping Palo Alto certificate '{$name}' - currently being deployed.");
                    continue;
                }

                // NEVER delete the latest issued certificate for the domain in the database
                if ($latestCertName && $name === $latestCertName) {
                    Log::info("Skipping Palo Alto certificate '{$name}' - latest issued certificate for this domain.");
                    continue;
                }

                $fullInfo = $this->getCert($automation, $name);
                
                if ($this->isCertExpired($name, $fullInfo)) {
                    // Check if any SSL/TLS service profile is still actively referencing this certificate
                    $activeProfiles = [];
                    foreach ($allProfiles as $pName => $profile) {
                        if ($profile['certificate'] === $name) {
                            $activeProfiles[] = $pName;
                        }
                    }

                    if (!empty($activeProfiles)) {
                        Log::warning("Skipping deletion of expired Palo Alto certificate '{$name}' - still actively referenced by SSL/TLS profile(s): " . implode(', ', $activeProfiles));
                        continue;
                    }

                    Log::info("Cleaning up EXPIRED Palo Alto certificate: {$name}");
                    
                    // 1. Explicitly remove references from multi-cert decryption rulebases
                    try {
                        $this->removeCertificateReferences($automation, $name, $domainName);
                    } catch (Exception $e) {
                        Log::warning("Failed to remove references for {$name} before deletion: " . $e->getMessage());
                    }

                    // 2. Delete the certificate
                    $xpath = "/config/shared/certificate/entry[@name='{$name}']";
                    $url = "https://{$host}/api/?type=config&action=delete&xpath=" . urlencode($xpath);

                    $response = Http::withoutVerifying()
                        ->withHeaders(['X-PAN-KEY' => $key])
                        ->post($url);

                    if ($response->successful()) {
                        $xml = simplexml_load_string($response->body());
                        if ($xml && (string)$xml['status'] === 'success') {
                            $deleted++;
                        } else {
                            $msg = $xml ? (string)$xml->msg : "Unknown API error";
                            Log::error("Failed to delete Palo Alto certificate {$name}: " . $msg);
                        }
                    } else {
                        Log::error("Failed to delete Palo Alto certificate {$name} (HTTP {$response->status()}): " . $response->body());
                    }
                } else {
                    Log::info("Skipping Palo Alto certificate {$name} - it is still valid.");
                }
            }
        }

        if ($deleted > 0 && $doCommit) {
            $this->commit($automation);
        }

        return $deleted;
    }

    /**
     * Specifically remove references to an expired certificate from multi-cert decryption rules.
     * Note: We NEVER strip certificates from SSL/TLS service profiles to prevent leaving
     * firewall services (GlobalProtect VPN, etc.) without an SSL certificate.
     */
    protected function removeCertificateReferences(Automation $automation, string $certName, string $domainName)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        $errors = [];

        // Check Decryption Rules (remove member from Inbound Inspection)
        $xpathRules = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/rulebase/decryption/rules";
        $urlRules = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpathRules);
        $respRules = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($urlRules);

        if ($respRules->successful()) {
            $xml = simplexml_load_string($respRules->body());
            if ($xml && isset($xml->result->rules->entry)) {
                foreach ($xml->result->rules->entry as $rule) {
                    $ruleName = (string)$rule['name'];
                    
                    // Handle SSL Inbound Inspection
                    if (isset($rule->type->{'ssl-inbound-inspection'}->certificates->member)) {
                        foreach ($rule->type->{'ssl-inbound-inspection'}->certificates->member as $member) {
                            if ((string)$member === $certName) {
                                Log::info("Removing expired cert {$certName} from Inbound Inspection Rule '{$ruleName}'");
                                $delPath = "{$xpathRules}/entry[@name='{$ruleName}']/type/ssl-inbound-inspection/certificates/member[text()='{$certName}']";
                                $delUrl = "https://{$host}/api/?type=config&action=delete&xpath=" . urlencode($delPath);
                                $this->executeSet($delUrl, $key, $ruleName, $errors);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Check the status of the automation on the device.
     */
    public function checkStatus(Automation $automation, Certificate $certificate)
    {
        $host = $automation->hostname;
        $key = $automation->getDecryptedPassword();
        
        $safeName = str_replace(['*', '.'], ['wildcard', '_'], $certificate->domain->name);
        $certName = "auto_{$safeName}_{$certificate->id}";

        $certs = $this->listCerts($automation);
        $existing = null;
        foreach ($certs as $c) {
            if ($c['name'] === $certName) {
                $existing = $c;
                break;
            }
        }

        $status = [
            'cert_name' => $certName,
            'exists_on_device' => !is_null($existing),
            'needs_update' => is_null($existing),
            'message' => $existing ? "Exact certificate version '{$certName}' found on Palo Alto." : "Certificate '{$certName}' not found on Palo Alto.",
            'details' => [
                'profiles' => [],
                'decryption_rules' => []
            ]
        ];

        if ($existing) {
            $deviceCert = $this->getCert($automation, $certName);
            $status['details']['device_cert'] = [
                'name' => $existing['name'] ?? $certName,
                'serial' => $deviceCert['serial'] ?? 'unknown',
                'thumbprint' => $deviceCert['thumbprint'] ?? 'unknown',
                'expiry' => $deviceCert['expiry'] ?? 'unknown',
            ];
            $status['details']['local_cert'] = [
                'serial' => $certificate->serial_number,
                'thumbprint' => $certificate->thumbprint_sha256,
                'expiry' => $certificate->expiry_date,
            ];
            
            if (isset($deviceCert['serial']) && $certificate->serial_number) {
                 $devSerial = strtolower(str_replace(' ', '', (string)$deviceCert['serial']));
                 $locSerial = strtolower(str_replace(' ', '', (string)$certificate->serial_number));
                 if ($devSerial !== $locSerial) {
                     $status['needs_update'] = true;
                     $status['message'] = "Certificate '{$certName}' version mismatch (Serial: {$deviceCert['serial']} vs {$certificate->serial_number}).";
                 }
            }
        }

        // Check Profiles (Auto-discovery across Shared and VSYS1, plus explicit list)
        $oldCertPrefix = "auto_{$safeName}_";
        $legacyName = "auto_{$safeName}";

        $allProfiles = $this->listProfiles($automation);
        $profilesString = $automation->config['profiles_string'] ?? '';
        $explicitProfiles = array_filter(array_map('trim', explode(',', $profilesString)));
        $checkedProfiles = [];

        foreach ($allProfiles as $p => $profile) {
            $currentCert = $profile['certificate'];
            $isManaged = (str_starts_with($currentCert, $oldCertPrefix) || $currentCert === $legacyName || in_array($p, $explicitProfiles));

            if ($isManaged) {
                $checkedProfiles[$p] = true;
                $isUpToDate = ($currentCert === $certName);
                $status['details']['profiles'][$p] = [
                    'current_value' => $currentCert ?: 'none',
                    'up_to_date' => $isUpToDate,
                    'location' => $profile['location']
                ];
                if (!$isUpToDate) {
                    $status['needs_update'] = true;
                }
            }
        }

        // Fallback for explicit profiles not found in listProfiles
        foreach ($explicitProfiles as $p) {
            if (isset($checkedProfiles[$p])) continue;

            // Try SHARED
            $xpath = "/config/shared/ssl-tls-service-profile/entry[@name='{$p}']/certificate";
            $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);
            $resp = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($url);
            
            $xml = simplexml_load_string($resp->body());
            if (!$resp->successful() || !$xml || (string)$xml['code'] === '7') {
                // Try VSYS1
                $vsysPath = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/ssl-tls-service-profile/entry[@name='{$p}']/certificate";
                $vsysUrl = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($vsysPath);
                $resp = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($vsysUrl);
                $xml = simplexml_load_string($resp->body());
            }

            $current = 'unknown';
            if ($resp->successful() && $xml && (string)$xml['status'] === 'success') {
                if (isset($xml->result->{'ssl-tls-service-profile'}->entry->certificate)) {
                    $current = (string)$xml->result->{'ssl-tls-service-profile'}->entry->certificate;
                } elseif (isset($xml->result->entry->certificate)) {
                    $current = (string)$xml->result->entry->certificate;
                } elseif (isset($xml->result->certificate)) {
                    $current = (string)$xml->result->certificate;
                }
            }
            $isUpToDate = ($current === $certName);
            $status['details']['profiles'][$p] = [
                'current_value' => $current,
                'up_to_date' => $isUpToDate
            ];
            if (!$isUpToDate) {
                $status['needs_update'] = true;
            }
        }

        // Check Decryption Rules
        $xpath = "/config/devices/entry[@name='localhost.localdomain']/vsys/entry[@name='vsys1']/rulebase/decryption/rules";
        $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);
        $resp = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($url);
        if ($resp->successful()) {
            $xml = simplexml_load_string($resp->body());
            if ($xml && (string)$xml['status'] === 'success' && isset($xml->result->rules->entry)) {
                foreach ($xml->result->rules->entry as $rule) {
                    $ruleName = (string)$rule['name'];
                    $isManaged = false;
                    $isForwardProxy = false;
                    $currentCertValue = 'none';
                    $upToDate = false;

                    if (isset($rule->type->{'ssl-forward-proxy'}->certificate)) {
                        $isForwardProxy = true;
                        $currentCertValue = (string)$rule->type->{'ssl-forward-proxy'}->certificate;
                        if (str_starts_with($currentCertValue, $oldCertPrefix) || $currentCertValue === $legacyName) {
                            $isManaged = true;
                            $upToDate = ($currentCertValue === $certName);
                        }
                    } elseif (isset($rule->type->{'ssl-inbound-inspection'}->certificates->member)) {
                        $members = [];
                        foreach ($rule->type->{'ssl-inbound-inspection'}->certificates->member as $member) {
                            $mName = (string)$member;
                            $members[] = $mName;
                            if (str_starts_with($mName, $oldCertPrefix) || $mName === $legacyName) {
                                $isManaged = true;
                            }
                            if ($mName === $certName) {
                                $upToDate = true;
                            }
                        }
                        $currentCertValue = implode(', ', $members);
                    }

                    if ($isManaged) {
                        $status['details']['decryption_rules'][$ruleName] = [
                            'current_value' => $currentCertValue,
                            'up_to_date' => $upToDate
                        ];
                        if (!$upToDate) {
                            $status['needs_update'] = true;
                        }
                    }
                }
            }
        }

        if ($existing && $status['needs_update']) {
            $status['message'] = "Certificate '{$certName}' exists on device, but 1 or more profiles/rules need updating.";
        }

        return $status;
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

        $entry = $xml->result->entry;
        $pem = (string)$entry->{'public-key'};
        
        $expiry = (string)$entry->{'not-valid-after'};
        
        $data = [
            'name' => (string)$entry['name'],
            'common_name' => (string)$entry->common_name,
            'expiry' => $expiry ?: 'unknown',
            'serial' => 'unknown',
            'thumbprint' => 'unknown',
        ];

        if (!empty($pem)) {
            $certService = app(CertificateService::class);
            $pem = $certService->ensurePem($pem);
            $info = $certService->getCertInfo($pem);
            if ($info) {
                $data['serial'] = $info['serialNumber'] ?? $data['serial'];
                $data['thumbprint'] = $certService->extractThumbprint($pem, 'sha256');
                if (isset($info['validTo_time_t'])) {
                    $data['expiry'] = date('Y-m-d H:i:s', $info['validTo_time_t']);
                }
            }
        }

        return $data;
    }
}
