<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class AdcsService
{
    public function requestCertificate(string $endpoint, string $template, string $csr, string $username, string $password)
    {
        if (empty($endpoint) || empty($template)) {
            throw new Exception("ADCS endpoint or template not configured.");
        }

        $msca = parse_url($endpoint, PHP_URL_HOST);

        // Clean and prepare CSR
        $csrClean = str_replace(["\r", "\n"], '', $csr);
        // Matching the shell script logic for encoding:
        $csrEncoded = str_replace('+', '%2B', $csrClean);
        $csrEncoded = str_replace(' ', '+', $csrEncoded);

        // Template attributes
        $certAttrib = "CertificateTemplate:{$template}";
        $certAttribEncoded = urlencode($certAttrib);

        $data = "Mode=newreq&CertRequest={$csrEncoded}&CertAttrib={$certAttribEncoded}&TargetStoreFlags=0&SaveCert=yes&ThumbPrint=";

        // Stage 1: Submit CSR
        $submitUrl = rtrim($endpoint, '/') . "/certfnsh.asp";
        
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive',
            "Host: {$msca}",
            'Referer: ' . rtrim($endpoint, '/') . '/certrqxt.asp',
            'User-Agent: Mozilla/5.0 (Windows NT 6.3; WOW64; Trident/7.0; rv:11.0) like Gecko',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        Log::info("ADCS Request Stage 1: Submitting CSR to {$submitUrl}");
        $output1 = $this->makeRequest($submitUrl, $username, $password, $headers, $data);

        // Improved regex to find the download link (certnew.cer?ReqID=...)
        if (preg_match('/certnew\.cer\?ReqID=[0-9]+&amp;Enc=b64/', $output1, $matches)) {
            $certLink = html_entity_decode($matches[0]);
        } elseif (preg_match('/certnew\.cer\?ReqID=[0-9]+/', $output1, $matches)) {
            $certLink = $matches[0];
        } else {
            preg_match('/<a href="(certnew\.cer\?[^"]+)">/', $output1, $matches);
            $certLink = isset($matches[1]) ? html_entity_decode($matches[1]) : null;
        }

        if (!$certLink) {
            $errorDetail = 'Could not find certificate download link in ADCS response. ';
            if (str_contains($output1, 'Access is denied')) {
                $errorDetail .= 'Check username/password or permissions.';
            } elseif (str_contains($output1, 'template')) {
                $errorDetail .= 'Check certificate template name.';
            } else {
                $errorDetail .= 'Raw response: ' . substr($output1, 0, 500) . '...';
            }
            throw new Exception("ADCS Request Failed: {$errorDetail}");
        }

        $fullCertLink = rtrim($endpoint, '/') . "/{$certLink}";

        // Stage 2: Retrieve Certificate
        $headers2 = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive',
            "Host: {$msca}",
            'Referer: ' . rtrim($endpoint, '/') . '/certfnsh.asp',
            'User-Agent: Mozilla/5.0 (Windows NT 6.3; WOW64; Trident/7.0; rv:11.0) like Gecko',
        ];

        Log::info("ADCS Request Stage 2: Retrieving Certificate from {$fullCertLink}");
        $output2 = $this->makeRequest($fullCertLink, $username, $password, $headers2);

        // Check if output2 contains a PEM certificate
        if (str_contains($output2, '-----BEGIN CERTIFICATE-----')) {
            return $output2; // Return the PEM certificate
        } else {
            throw new Exception("ADCS Request Failed: Did not receive a valid certificate. Raw response: " . substr($output2, 0, 500) . "...");
        }
    }

    private function makeRequest(string $url, string $username, string $password, array $headers, $postData = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, ""); // Support gzip/deflate and decode automatically
        
        if ($postData) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $output = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("CURL Error: " . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            Log::error("ADCS Request failed with HTTP code: {$httpCode}");
            throw new Exception("ADCS HTTP Error: {$httpCode}");
        }

        return $output;
    }
}
