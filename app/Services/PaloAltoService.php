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

        $errors = [];

        // 1. Update SSL/TLS Service Profiles
        try {
            $this->updateProfileReferences($automation, $certName);
        } catch (Exception $e) {
            $errors[] = "Profiles: " . $e->getMessage();
        }

        // 2. Update Decryption Rules
        try {
            $this->updateDecryptionRules($automation, $certName, $certificate->domain->name);
        } catch (Exception $e) {
            $errors[] = "Decryption Rules: " . $e->getMessage();
        }

        // 3. Cleanup old/expired certificates for this domain
        try {
            $this->cleanupExpiredCerts($automation, $certificate->domain->name);
        } catch (Exception $e) {
            // Cleanup failure is a warning, not a critical error
            Log::warning("Palo Alto Cleanup failed: " . $e->getMessage());
        }

        // 4. Final Commit
        try {
            $this->commit($automation);
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

        // Basic polling for results (up to 30s)
        $attempts = 0;
        while ($attempts < 6) { // 6 * 5s = 30s
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
                            // We don't throw exception for warnings on success, but we log them.
                            // To make them visible in Automation Log, we could append them to the status message.
                            session()->flash('automation_warnings', $warnings);
                        }
                        
                        return true;
                    }
                }
            }
        }

        Log::info("Palo Alto Commit Job #{$jobId} is still running after 30s. Continuing...");
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
        $errors = [];

        foreach ($profiles as $profileName) {
            if (empty($profileName)) continue;

            // type=config, action=set, xpath=/config/shared/ssl-tls-service-profile/entry[@name='PROFILE']/certificate
            $xpath = "/config/shared/ssl-tls-service-profile/entry[@name='{$profileName}']/certificate";
            $url = "https://{$host}/api/?type=config&action=set&xpath=" . urlencode($xpath) . "&element=" . urlencode("<certificate>{$certName}</certificate>");

            $response = Http::withoutVerifying()
                ->withHeaders(['X-PAN-KEY' => $key])
                ->post($url);

            if (!$response->successful()) {
                $errors[] = "{$profileName} (HTTP {$response->status()})";
            } else {
                $xml = simplexml_load_string($response->body());
                if (!$xml || (string)$xml['status'] !== 'success') {
                    $errors[] = "{$profileName} (" . ($xml ? (string)$xml->msg : "XML Error") . ")";
                } else {
                    Log::info("Successfully updated Palo Alto Profile {$profileName} to use {$certName}");
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
                if (str_starts_with($currentCert, $oldCertPrefix)) {
                    $isManaged = true;
                }
            } 
            // Handle SSL Inbound Inspection (Can be multiple certs)
            elseif (isset($rule->type->{'ssl-inbound-inspection'}->certificates->member)) {
                foreach ($rule->type->{'ssl-inbound-inspection'}->certificates->member as $member) {
                    $mName = (string)$member;
                    $existingMembers[] = $mName;
                    if (str_starts_with($mName, $oldCertPrefix)) {
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
            $errors[] = "Rule '{$ruleName}' (HTTP {$response->status()})";
        } else {
            $xml = simplexml_load_string($response->body());
            if (!$xml || (string)$xml['status'] !== 'success') {
                $errors[] = "Rule '{$ruleName}' (" . ($xml ? (string)$xml->msg : "XML Error") . ")";
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
            // Never cleanup the one we JUST imported in this session
            if ($name === "auto_{$safeName}_" . $automation->domain->certificates()->where('status', 'issued')->latest()->first()?->id) {
                continue;
            }

            if (str_starts_with($name, $prefix)) {
                // Extract the ID from the name auto_domain_ID
                $parts = explode('_', $name);
                $id = end($parts);
                
                $localCert = \App\Models\Certificate::find($id);
                // If the certificate is not found in our DB OR is marked as expired/archived in our DB
                // OR if the expiry on the device is in the past (only if expiry is actually present)
                $expiryTime = !empty($cert['expiry']) ? strtotime($cert['expiry']) : null;
                $isExpiredOnDevice = $expiryTime && $expiryTime < time();
                
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
                'name' => $existing['name'],
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

        // Check Profiles
        $profilesString = $automation->config['profiles_string'] ?? '';
        if (!empty($profilesString)) {
            $profiles = array_filter(array_map('trim', explode(',', $profilesString)));
            foreach ($profiles as $p) {
                $xpath = "/config/shared/ssl-tls-service-profile/entry[@name='{$p}']/certificate";
                $url = "https://{$host}/api/?type=config&action=get&xpath=" . urlencode($xpath);
                $resp = Http::withoutVerifying()->withHeaders(['X-PAN-KEY' => $key])->get($url);
                $current = 'unknown';
                if ($resp->successful()) {
                    $xml = simplexml_load_string($resp->body());
                    if ($xml && (string)$xml['status'] === 'success') {
                        // The certificate element can be at different levels depending on API version/response structure
                        if (isset($xml->result->{'ssl-tls-service-profile'}->entry->certificate)) {
                            $current = (string)$xml->result->{'ssl-tls-service-profile'}->entry->certificate;
                        } elseif (isset($xml->result->entry->certificate)) {
                            $current = (string)$xml->result->entry->certificate;
                        } elseif (isset($xml->result->certificate)) {
                            $current = (string)$xml->result->certificate;
                        }
                    }
                }
                $status['details']['profiles'][$p] = [
                    'current_value' => $current,
                    'up_to_date' => $current === $certName
                ];
            }
        }

        // Check Decryption Rules
        $oldCertPrefix = "auto_{$safeName}_";
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
                        if (str_starts_with($currentCertValue, $oldCertPrefix)) {
                            $isManaged = true;
                            $upToDate = ($currentCertValue === $certName);
                        }
                    } elseif (isset($rule->type->{'ssl-inbound-inspection'}->certificates->member)) {
                        $members = [];
                        foreach ($rule->type->{'ssl-inbound-inspection'}->certificates->member as $member) {
                            $mName = (string)$member;
                            $members[] = $mName;
                            if (str_starts_with($mName, $oldCertPrefix)) {
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
                    }
                }
            }
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
