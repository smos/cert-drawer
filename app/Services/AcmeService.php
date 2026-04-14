<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Setting;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\TestMail; // We'll use a generic alert or just simple mail for now if needed

class AcmeService
{
    protected ?string $accountKey = null;
    protected ?string $nonce = null;
    protected ?string $accountUrl = null;
    protected ?string $lastLocation = null;
    protected $lastResponse;

    public function issueCertificate(Certificate $certificate)
    {
        $settings = Setting::all()->pluck('value', 'key');
        $kid = $settings['acme_kid'] ?? '';
        $hmac = $settings['acme_hmac'] ?? '';
        
        $certService = app(CertificateService::class);
        $info = $certService->getCertInfoFromCsr($certificate->csr);
        $commonName = $info['commonName'] ?? $certificate->domain->name;

        // Sanitize commonName
        if (is_array($commonName)) $commonName = $commonName[0];
        $commonName = preg_replace('/[^a-zA-Z0-9-\._\* ]/', '_', $commonName);

        $sans = $certService->extractSansFromCsr($certificate->csr);
        $allDomains = array_unique(array_merge([$commonName], $sans));

        $isWildcard = false;
        foreach ($allDomains as $d) if (str_starts_with($d, '*.')) $isWildcard = true;

        $directoryUrl = $settings['acme_url_dv'] ?? '';
        if ($isWildcard) {
            $directoryUrl = $settings['acme_url_wildcard'] ?? '';
        } elseif (count($allDomains) > 1) {
            $directoryUrl = $settings['acme_url_san'] ?? '';
        }

        if (empty($directoryUrl)) {
            throw new Exception("ACME Directory URL not configured for this request type.");
        }

        Log::info("Starting native ACME fulfillment for {$commonName} using {$directoryUrl}");

        try {
            // 1. Get Directory
            $directory = $this->request('GET', $directoryUrl);

            // 2. Setup Account Key
            $this->accountKey = $this->getAccountKey();

            // 3. Register/Get Account
            $this->newAccount($directory['newAccount'], $kid, $hmac);

            // 4. Create Order
            $order = $this->newOrder($directory['newOrder'], $allDomains);
            $orderUrl = $this->lastLocation;

            // 5. Authorizations
            foreach ($order['authorizations'] as $authUrl) {
                $auth = $this->getAuthorization($authUrl);
                
                if ($auth['status'] === 'pending') {
                    // Look for DNS challenge
                    $dnsChallenge = collect($auth['challenges'])->where('type', 'dns-01')->first();
                    if ($dnsChallenge) {
                        $token = $dnsChallenge['token'];
                        $keyAuth = $this->getKeyAuthorization($token);
                        $txtValue = $this->base64UrlEncode(hash('sha256', $keyAuth, true));
                        $host = "_acme-challenge." . $auth['identifier']['value'];
                        
                        throw new Exception("ACME DNS Challenge required for {$auth['identifier']['value']}. Please deploy TXT record: {$host} with value: {$txtValue}");
                    }
                    
                    throw new Exception("ACME Authorization for {$auth['identifier']['value']} is PENDING. Manual challenge deployment required.");
                }
                
                if ($auth['status'] !== 'valid') {
                    $errorMsg = "ACME Authorization for {$auth['identifier']['value']} is {$auth['status']}.";
                    foreach ($auth['challenges'] as $challenge) {
                        if (isset($challenge['error'])) {
                            $errorMsg .= " Challenge ({$challenge['type']}) Error: " . json_encode($challenge['error']);
                        }
                    }
                    throw new Exception($errorMsg);
                }
            }

            // 6. Finalize
            $csrRaw = trim(str_replace(['-----BEGIN CERTIFICATE REQUEST-----', '-----END CERTIFICATE REQUEST-----', "\n", "\r"], '', $certificate->csr));
            $this->request('POST', $order['finalize'], ['csr' => $this->base64UrlEncode(base64_decode($csrRaw))]);
            
            // 7. Poll for success
            $order = $this->pollStatus($orderUrl, 'valid');

            // 8. Download
            $issuedCert = $this->downloadCertificate($order['certificate']);

            // 9. Process and Save
            return $this->finalizeIssuedCertificate($certificate, $issuedCert);

        } catch (Exception $e) {
            Log::error("ACME native fulfillment failed: " . $e->getMessage());
            AuditLog::log('acme_error', "ACME fulfillment failed for {$commonName}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function finalizeIssuedCertificate(Certificate $certificate, string $issuedCert)
    {
        $certService = app(CertificateService::class);
        $certInfo = $certService->getCertInfo($issuedCert);
        $expiry = isset($certInfo['validTo_time_t']) ? date('Y-m-d H:i:s', $certInfo['validTo_time_t']) : null;
        $issuer = $certInfo['issuer']['CN'] ?? 'Unknown';

        $certificate->update([
            'certificate' => $issuedCert,
            'status' => 'issued',
            'request_type' => 'acme',
            'expiry_date' => $expiry,
            'issuer' => $issuer,
            'thumbprint_sha1' => $certService->extractThumbprint($issuedCert, 'sha1'),
            'thumbprint_sha256' => $certService->extractThumbprint($issuedCert, 'sha256'),
        ]);

        $certificate->linkIssuer();

        AuditLog::log('acme_fulfill_success', "Fulfilled ACME certificate for domain: {$certificate->domain->name}");

        $appPath = "certificates/" . $certificate->domain->name . "/" . $certificate->created_at->format('Y-m-d_H-i-s');
        Storage::disk('local')->put($appPath . "/certificate.cer", $issuedCert);
        
        $dn = $certService->extractDnFromCert($certInfo);
        $sans = $certService->extractSansFromCert($certInfo);
        $certService->saveSslConfig($appPath, $dn, $sans);

        return true;
    }

    protected function getAccountKey()
    {
        $path = 'acme_account_key.pem';
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        $res = openssl_pkey_new([
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $key);
        Storage::disk('local')->put($path, $key);
        return $key;
    }

    protected function newAccount(string $url, string $kid = '', string $hmac = '')
    {
        $payload = ['termsOfServiceAgreed' => true, 'contact' => []];
        if ($kid && $hmac) {
            $payload['externalAccountBinding'] = $this->generateEab($url, $kid, $hmac);
        }
        $this->request('POST', $url, $payload);
        $this->accountUrl = $this->lastLocation;
    }

    protected function newOrder(string $url, array $domains)
    {
        $identifiers = [];
        foreach ($domains as $d) $identifiers[] = ['type' => 'dns', 'value' => $d];
        return $this->request('POST', $url, ['identifiers' => $identifiers]);
    }

    protected function getAuthorization(string $url)
    {
        return $this->request('POST', $url, null);
    }

    protected function downloadCertificate(string $url)
    {
        $this->request('POST', $url, null);
        return $this->lastResponse;
    }

    protected function pollStatus(string $url, string $targetStatus, int $maxRetries = 60)
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            $res = $this->request('POST', $url, null);
            if ($res['status'] === $targetStatus) return $res;
            
            if ($res['status'] === 'invalid') {
                $errorMsg = "ACME Order became invalid.";
                if (isset($res['error'])) {
                    $errorMsg .= " Order Error: " . json_encode($res['error']);
                }
                
                // Try to find the specific challenge error
                if (isset($res['authorizations'])) {
                    foreach ($res['authorizations'] as $authUrl) {
                        $auth = $this->request('POST', $authUrl, null);
                        if ($auth['status'] === 'invalid') {
                            foreach ($auth['challenges'] as $challenge) {
                                if (isset($challenge['error'])) {
                                    $errorMsg .= " Challenge ({$challenge['type']}) Error: " . json_encode($challenge['error']);
                                }
                            }
                        }
                    }
                }
                throw new Exception($errorMsg);
            }
            sleep(5);
        }
        throw new Exception("Timed out waiting for ACME status {$targetStatus}");
    }

    public function getKeyAuthorization(string $token)
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->accountKey));
        $jwk = [
            'e' => $this->base64UrlEncode($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
        ];
        return $token . '.' . $this->base64UrlEncode(hash('sha256', json_encode($jwk), true));
    }

    protected function generateEab(string $url, string $kid, string $hmac)
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->accountKey));
        $jwk = [
            'e' => $this->base64UrlEncode($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
        ];

        $protected = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'kid' => $kid,
            'url' => $url,
        ]));

        $payload = $this->base64UrlEncode(json_encode($jwk));
        $signature = hash_hmac('sha256', "{$protected}.{$payload}", base64_decode(str_replace(['-', '_'], ['+', '/'], $hmac)), true);

        return [
            'protected' => $protected,
            'payload' => $payload,
            'signature' => $this->base64UrlEncode($signature)
        ];
    }

    protected function base64UrlFix($data)
    {
        return str_replace(['-', '_'], ['+', '/'], $data);
    }

    protected function request(string $method, string $url, $payload = null)
    {
        if ($method === 'GET') return $this->curl($url);

        if (!$this->nonce) {
            $directory = $this->request('GET', $this->lastLocation ?: $url); // Just to get a nonce
            $this->curl($directory['newNonce'] ?? $url, null, 'HEAD');
        }

        $protected = ['alg' => 'RS256', 'nonce' => $this->nonce, 'url' => $url];

        if ($this->accountUrl) {
            $protected['kid'] = $this->accountUrl;
        } else {
            $details = openssl_pkey_get_details(openssl_pkey_get_private($this->accountKey));
            $protected['jwk'] = [
                'e' => $this->base64UrlEncode($details['rsa']['e']),
                'kty' => 'RSA',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
            ];
        }

        $protectedStr = $this->base64UrlEncode(json_encode($protected));
        $payloadStr = $payload === null ? '' : $this->base64UrlEncode(json_encode($payload));
        
        openssl_sign("{$protectedStr}.{$payloadStr}", $signature, $this->accountKey, OPENSSL_ALGO_SHA256);
        
        $body = json_encode([
            'protected' => $protectedStr,
            'payload' => $payloadStr,
            'signature' => $this->base64UrlEncode($signature)
        ]);

        return $this->curl($url, $body);
    }

    protected function curl(string $url, $body = null, $method = null)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/jose+json']);
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        }

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);

        if (preg_match('/Replay-Nonce: (.*)/i', $header, $matches)) $this->nonce = trim($matches[1]);
        if (preg_match('/Location: (.*)/i', $header, $matches)) $this->lastLocation = trim($matches[1]);

        $this->lastResponse = json_decode($body, true) ?: $body;
        return $this->lastResponse;
    }

    protected function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
