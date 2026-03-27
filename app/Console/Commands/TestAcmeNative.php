<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class TestAcmeNative extends Command
{
    protected $signature = 'test:acme-native {cert_id}';
    protected $description = 'Debug script for native PHP ACME fulfillment (EAB supported)';

    public function handle()
    {
        $certId = $this->argument('cert_id');
        $certificate = Certificate::findOrFail($certId);
        $settings = Setting::all()->pluck('value', 'key');

        $directoryUrl = $settings['acme_url_dv'] ?? '';
        $kid = $settings['acme_kid'] ?? '';
        $hmac = $settings['acme_hmac'] ?? '';

        if (!$directoryUrl) {
            $this->error("ACME Directory URL not set.");
            return 1;
        }

        $this->info("Starting native ACME debug for ID {$certId} ({$certificate->domain->name})");
        $this->info("Directory: {$directoryUrl}");

        try {
            $client = new AcmeNativeClient($directoryUrl, $this);
            
            // 1. Get Directory
            $this->comment("Fetching directory...");
            $directory = $client->getDirectory();
            $this->info("Directory endpoints: " . json_encode($directory, JSON_PRETTY_PRINT));

            // 2. Setup Account Key
            $this->comment("Generating/Loading account key...");
            $accountKey = $this->getAccountKey();
            $client->setAccountKey($accountKey);

            // 3. Register/Get Account
            $this->comment("Registering/Retrieving account (EAB: " . ($kid ? 'Yes' : 'No') . ")...");
            $accountUrl = $client->newAccount($directory['newAccount'], $kid, $hmac);
            $this->info("Account URL: {$accountUrl}");

            // 4. Create Order
            $this->comment("Creating new order for {$certificate->domain->name}...");
            $order = $client->newOrder($directory['newOrder'], [$certificate->domain->name]);
            $orderUrl = $client->getLastLocation();
            $this->info("Order URL: {$orderUrl}");
            $this->info("Order Response: " . json_encode($order, JSON_PRETTY_PRINT));

            // 5. Authorizations
            foreach ($order['authorizations'] as $authUrl) {
                $auth = $client->getAuthorization($authUrl);
                $this->info("Authorization for {$auth['identifier']['value']} status: {$auth['status']}");
                
                if ($auth['status'] === 'pending') {
                    $dnsChallenge = collect($auth['challenges'])->where('type', 'dns-01')->first();
                    $token = $dnsChallenge['token'];
                    $keyAuth = $client->getKeyAuthorization($token);
                    $txtValue = $client->base64UrlEncode(hash('sha256', $keyAuth, true));

                    $this->warn("DNS CHALLENGE REQUIRED:");
                    $this->warn("Record: _acme-challenge.{$auth['identifier']['value']}");
                    $this->warn("Value:  {$txtValue}");
                    
                    if (!$this->confirm("Have you deployed this DNS record?")) {
                        $this->error("Aborted."); return 1;
                    }

                    $this->comment("Notifying CA of challenge completion...");
                    $client->respondToChallenge($dnsChallenge['url']);
                    
                    $this->comment("Polling for authorization success...");
                    $client->pollStatus($authUrl, 'valid');
                } elseif ($auth['status'] === 'invalid') {
                    $this->error("Authorization is INVALID.");
                    $this->line(json_encode($auth, JSON_PRETTY_PRINT));
                    throw new Exception("Authorization failed.");
                }
            }

            // 6. Finalize
            $this->comment("Finalizing order with CSR...");
            $csrDer = $this->getDerCsr($certificate->csr);
            $this->info("CSR (DER) length: " . strlen($csrDer) . " bytes");
            
            $res = $client->finalizeOrder($order['finalize'], $csrDer);
            $this->info("Finalize Response: " . json_encode($res, JSON_PRETTY_PRINT));
            
            $this->comment("Polling for order valid status...");
            $order = $client->pollStatus($orderUrl, 'valid');

            // 7. Download Cert
            $this->comment("Downloading certificate from {$order['certificate']}...");
            $certPem = $client->downloadCertificate($order['certificate']);
            
            $this->info("CERTIFICATE RECEIVED:");
            $this->line($certPem);

        } catch (Exception $e) {
            $this->error("ACME Error: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function getDerCsr($pem)
    {
        $pem = trim($pem);
        $lines = explode("\n", $pem);
        $base64 = "";
        foreach ($lines as $line) {
            if (str_contains($line, '-----')) continue;
            $base64 .= trim($line);
        }
        return base64_decode($base64);
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
}

/**
 * Minimal ACME v2 Client implementation
 */
class AcmeNativeClient
{
    protected string $directoryUrl;
    protected ?string $accountKey = null;
    protected ?string $nonce = null;
    protected ?string $accountUrl = null;
    protected ?string $lastLocation = null;
    protected $lastResponse;
    protected $command;

    public function __construct(string $directoryUrl, $command)
    {
        $this->directoryUrl = $directoryUrl;
        $this->command = $command;
    }

    public function setAccountKey(string $key) { $this->accountKey = $key; }
    public function getLastLocation() { return $this->lastLocation; }
    public function getLastResponse() { return $this->lastResponse; }

    public function getDirectory()
    {
        return $this->request('GET', $this->directoryUrl);
    }

    public function newAccount(string $url, string $kid = '', string $hmac = '')
    {
        $payload = [
            'termsOfServiceAgreed' => true,
            'contact' => []
        ];

        if ($kid && $hmac) {
            $payload['externalAccountBinding'] = $this->generateEab($url, $kid, $hmac);
        }

        $res = $this->request('POST', $url, $payload);
        $this->accountUrl = $this->lastLocation;
        return $this->accountUrl;
    }

    public function newOrder(string $url, array $domains)
    {
        $identifiers = [];
        foreach ($domains as $d) {
            $identifiers[] = ['type' => 'dns', 'value' => $d];
        }
        return $this->request('POST', $url, ['identifiers' => $identifiers]);
    }

    public function getAuthorization(string $url)
    {
        return $this->request('POST', $url, null);
    }

    public function respondToChallenge(string $url)
    {
        return $this->request('POST', $url, (object)[]);
    }

    public function finalizeOrder(string $url, string $csrBinary)
    {
        return $this->request('POST', $url, ['csr' => $this->base64UrlEncode($csrBinary)]);
    }

    public function downloadCertificate(string $url)
    {
        $this->request('POST', $url, null);
        return $this->lastResponse;
    }

    public function pollStatus(string $url, string $targetStatus, int $maxRetries = 60)
    {
        for ($i = 0; $i < $maxRetries; $i++) {
            $res = $this->request('POST', $url, null);
            if ($res['status'] === $targetStatus) return $res;
            
            if ($res['status'] === 'invalid') {
                $this->command->error("Order/Auth status became INVALID.");
                if (isset($res['error'])) {
                    $this->command->error("Error: " . json_encode($res['error'], JSON_PRETTY_PRINT));
                }
                
                if (isset($res['authorizations'])) {
                    foreach ($res['authorizations'] as $authUrl) {
                        $auth = $this->request('POST', $authUrl, null);
                        if ($auth['status'] === 'invalid') {
                            $this->command->error("Auth for {$auth['identifier']['value']} is invalid.");
                            foreach ($auth['challenges'] as $challenge) {
                                if (isset($challenge['error'])) {
                                    $this->command->error("Challenge {$challenge['type']} error: " . json_encode($challenge['error'], JSON_PRETTY_PRINT));
                                }
                            }
                        }
                    }
                }
                throw new Exception("Status became invalid.");
            }
            $this->command->info("Current status: {$res['status']}... waiting 5s");
            sleep(5);
        }
        throw new Exception("Timed out waiting for status {$targetStatus}");
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

        $protected = [
            'alg' => 'HS256',
            'kid' => $kid,
            'url' => $url,
        ];

        $payload = json_encode($jwk);
        $protectedStr = $this->base64UrlEncode(json_encode($protected));
        $payloadStr = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', "{$protectedStr}.{$payloadStr}", base64_decode($this->base64UrlFix($hmac)), true);

        return [
            'protected' => $protectedStr,
            'payload' => $payloadStr,
            'signature' => $this->base64UrlEncode($signature)
        ];
    }

    protected function request(string $method, string $url, $payload = null)
    {
        if ($method === 'GET') {
            return $this->curl($url);
        }

        if (!$this->nonce) $this->updateNonce();

        $protected = [
            'alg' => 'RS256',
            'nonce' => $this->nonce,
            'url' => $url,
        ];

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

    protected function updateNonce()
    {
        $directory = $this->getDirectory();
        $this->curl($directory['newNonce'], null, 'HEAD');
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

        if (preg_match('/Replay-Nonce: (.*)/i', $header, $matches)) {
            $this->nonce = trim($matches[1]);
        }

        if (preg_match('/Location: (.*)/i', $header, $matches)) {
            $this->lastLocation = trim($matches[1]);
        }

        $this->lastResponse = json_decode($body, true) ?: $body;
        return $this->lastResponse;
    }

    public function base64UrlEncode($data)
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    protected function base64UrlFix($data)
    {
        return str_replace(['-', '_'], ['+', '/'], $data);
    }
}
