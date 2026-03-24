<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;

class CertificateService
{
    public function generateCsr(array $dn, array $altNames = [])
    {
        $configContent = $this->getOpenSslConfig($dn, $altNames);
        return $this->generateCsrWithConfig($configContent, $dn);
    }

    public function generateCsrWithConfig(string $configContent, array $dn = [])
    {
        $tmpConfigFile = tempnam(sys_get_temp_dir(), 'openssl_conf_');
        $tmpKeyFile = tempnam(sys_get_temp_dir(), 'openssl_key_');
        $tmpCsrFile = tempnam(sys_get_temp_dir(), 'openssl_csr_');
        
        file_put_contents($tmpConfigFile, $configContent);

        // Use CLI openssl as it's much more reliable with custom configs than PHP's openssl_csr_new
        // -nodes means no password on the private key
        $cmd = "openssl req -new -newkey rsa:4096 -nodes -keyout " . escapeshellarg($tmpKeyFile) . 
               " -out " . escapeshellarg($tmpCsrFile) . " -config " . escapeshellarg($tmpConfigFile) . " 2>&1";
        
        $output = shell_exec($cmd);
        
        $csrOut = (file_exists($tmpCsrFile) && filesize($tmpCsrFile) > 0) ? file_get_contents($tmpCsrFile) : null;
        $keyOut = (file_exists($tmpKeyFile) && filesize($tmpKeyFile) > 0) ? file_get_contents($tmpKeyFile) : null;

        unlink($tmpConfigFile);
        if (file_exists($tmpKeyFile)) unlink($tmpKeyFile);
        if (file_exists($tmpCsrFile)) unlink($tmpCsrFile);

        if (!$csrOut || !$keyOut) {
            throw new Exception("OpenSSL CSR generation failed. Output: " . $output);
        }

        return [
            'csr' => $csrOut,
            'private_key' => $keyOut,
        ];
    }

    public function generateSelfSignedCert(array $dn, int $days = 3650)
    {
        $configArgs = [
            "digest_alg" => "sha256",
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ];

        $privKey = openssl_pkey_new($configArgs);
        
        $tmpConfigFile = tempnam(sys_get_temp_dir(), 'openssl_');
        $configContent = "[ req ]
distinguished_name = req_distinguished_name
x509_extensions = v3_ca
prompt = no

[ req_distinguished_name ]
C = " . ($dn['countryName'] ?? 'NL') . "
ST = " . ($dn['stateOrProvinceName'] ?? 'State') . "
L = " . ($dn['localityName'] ?? 'Locality') . "
O = " . ($dn['organizationName'] ?? 'Organization') . "
OU = " . ($dn['organizationalUnitName'] ?? 'OU') . "
CN = " . ($dn['commonName'] ?? 'Root CA') . "

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign
";
        file_put_contents($tmpConfigFile, $configContent);

        $csr = openssl_csr_new($dn, $privKey, [
            "digest_alg" => "sha256",
            "config" => $tmpConfigFile,
        ]);

        $x509 = openssl_csr_sign($csr, null, $privKey, $days, [
            "digest_alg" => "sha256",
            "config" => $tmpConfigFile,
            "x509_extensions" => "v3_ca",
        ]);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privKey, $keyOut);
        
        unlink($tmpConfigFile);

        return [
            'certificate' => $certOut,
            'private_key' => $keyOut,
        ];
    }

    public function signCsr(string $csrPem, string $issuerCertPem, string $issuerKeyPem, int $days = 365, bool $isCa = false, array $altNames = [])
    {
        $privKey = openssl_pkey_get_private($issuerKeyPem);
        $issuerCert = openssl_x509_read($issuerCertPem);

        $tmpConfigFile = tempnam(sys_get_temp_dir(), 'openssl_');
        $configContent = "[ req ]
distinguished_name = req_distinguished_name
x509_extensions = " . ($isCa ? "v3_ca" : "v3_req") . "

[ req_distinguished_name ]

[ v3_ca ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = critical, CA:true
keyUsage = critical, digitalSignature, cRLSign, keyCertSign

[ v3_req ]
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
basicConstraints = CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
subjectAltName = @alt_names

[ alt_names ]
";
        if (empty($altNames)) {
            $configContent .= "DNS.1 = certkast.local\n";
        } else {
            foreach ($altNames as $i => $name) {
                if (filter_var($name, FILTER_VALIDATE_IP)) {
                    $configContent .= "IP." . ($i + 1) . " = " . $name . "\n";
                } else {
                    $configContent .= "DNS." . ($i + 1) . " = " . $name . "\n";
                }
            }
        }
        
        file_put_contents($tmpConfigFile, $configContent);

        $x509 = openssl_csr_sign($csrPem, $issuerCert, $privKey, $days, [
            "digest_alg" => "sha256",
            "config" => $tmpConfigFile,
            "x509_extensions" => $isCa ? "v3_ca" : "v3_req",
        ], rand(10000, 999999));

        openssl_x509_export($x509, $certOut);
        unlink($tmpConfigFile);

        return $certOut;
    }

    public function saveSslConfig(string $path, array $dn, array $altNames = [])
    {
        $config = $this->getOpenSslConfig($dn, $altNames);
        Storage::disk('local')->put($path . "/ssl.conf", $config);
        return $config;
    }

    public function extractDnFromCert(array $info)
    {
        return [
            "countryName" => $info['subject']['C'] ?? '',
            "stateOrProvinceName" => $info['subject']['ST'] ?? '',
            "localityName" => $info['subject']['L'] ?? '',
            "organizationName" => $info['subject']['O'] ?? '',
            "organizationalUnitName" => $info['subject']['OU'] ?? '',
            "commonName" => $info['subject']['CN'] ?? '',
        ];
    }

    public function extractSansFromCert(array $info)
    {
        $sans = [];
        if (isset($info['extensions']['subjectAltName'])) {
            $parts = explode(',', $info['extensions']['subjectAltName']);
            foreach ($parts as $part) {
                $sans[] = trim(str_replace(['DNS:', 'IP Address:'], '', $part));
            }
        }
        return $sans;
    }

    public function getOpenSslConfig(array $dn, array $altNames)
    {
        $sanitize = function($val) {
            return str_replace(["\r", "\n", "[", "]"], '', $val);
        };

        $config = "[ req ]
default_bits        = 4096
distinguished_name = req_distinguished_name
req_extensions     = req_ext
prompt             = no

[ req_distinguished_name ]
C  = " . substr($sanitize($dn['countryName'] ?? 'NL'), 0, 2) . "
ST = " . $sanitize($dn['stateOrProvinceName'] ?? '') . "
L  = " . $sanitize($dn['localityName'] ?? '') . "
O  = " . $sanitize($dn['organizationName'] ?? '') . "
OU = " . $sanitize($dn['organizationalUnitName'] ?? '') . "
CN = " . $sanitize($dn['commonName'] ?? 'localhost') . "

[ req_ext ]
subjectAltName = @alt_names

[alt_names]
";
        if (empty($altNames)) {
            $altNames[] = $dn['commonName'] ?? 'localhost';
        }

        $dnsCount = 1;
        $ipCount = 1;
        foreach ($altNames as $name) {
            $name = $sanitize($name);
            if (filter_var($name, FILTER_VALIDATE_IP)) {
                $config .= "IP." . ($ipCount++) . " = " . $name . "\n";
            } else {
                $config .= "DNS." . ($dnsCount++) . " = " . $name . "\n";
            }
        }

        return $config;
    }

    public function generatePfx(string $cert, string $privateKey, string $password, array $chain = [])
    {
        if (openssl_pkcs12_export($cert, $pfxOut, $privateKey, $password, ['extracerts' => $chain])) {
            return $pfxOut;
        }

        throw new Exception("Failed to generate PFX: " . openssl_error_string());
    }

    public function generateLegacyPfx(string $cert, string $privateKey, string $password, array $chain = [])
    {
        $tmpCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tmpKey = tempnam(sys_get_temp_dir(), 'key_');
        $tmpPfx = tempnam(sys_get_temp_dir(), 'pfx_');
        
        file_put_contents($tmpCert, $cert . "\n" . implode("\n", $chain));
        file_put_contents($tmpKey, $privateKey);
        
        // -macalg sha1 -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES
        $cmd = "openssl pkcs12 -export -out " . escapeshellarg($tmpPfx) . " -inkey " . escapeshellarg($tmpKey) . " -in " . escapeshellarg($tmpCert) . " -passout pass:" . escapeshellarg($password) . " -macalg sha1 -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES 2>&1";
        
        $output = shell_exec($cmd);
        
        if (file_exists($tmpPfx) && filesize($tmpPfx) > 0) {
            $pfxData = file_get_contents($tmpPfx);
            unlink($tmpCert);
            unlink($tmpKey);
            unlink($tmpPfx);
            return $pfxData;
        }
        
        unlink($tmpCert);
        unlink($tmpKey);
        if (file_exists($tmpPfx)) unlink($tmpPfx);
        
        throw new Exception("Failed to generate Legacy PFX: " . $output);
    }

    public function parsePfx(string $pfxData, string $password)
    {
        // Try native first
        if (openssl_pkcs12_read($pfxData, $certs, $password)) {
            return [
                'cert' => $certs['cert'],
                'private_key' => $certs['pkey'],
                'chain' => $certs['extracerts'] ?? [],
                'info' => openssl_x509_parse($certs['cert']),
            ];
        }

        // Fallback to CLI for legacy PFX formats (OpenSSL 3.0 incompatibility)
        $tmpPfx = tempnam(sys_get_temp_dir(), 'pfx_');
        file_put_contents($tmpPfx, $pfxData);
        
        $tmpPem = tempnam(sys_get_temp_dir(), 'pem_');
        $cmd = "openssl pkcs12 -in " . escapeshellarg($tmpPfx) . " -out " . escapeshellarg($tmpPem) . " -nodes -passin pass:" . escapeshellarg($password) . " -legacy 2>&1";
        $output = shell_exec($cmd);
        
        if (file_exists($tmpPem) && $pemData = file_get_contents($tmpPem)) {
            unlink($tmpPfx);
            unlink($tmpPem);
            
            // Separate private key and certs
            preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $pemData, $keyMatch);
            preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemData, $certMatches);
            
            if (!empty($keyMatch) && !empty($certMatches[0])) {
                return [
                    'cert' => $certMatches[0][0],
                    'private_key' => $keyMatch[0],
                    'chain' => array_slice($certMatches[0], 1),
                    'info' => openssl_x509_parse($certMatches[0][0]),
                ];
            }
        }

        unlink($tmpPfx);
        if (file_exists($tmpPem)) unlink($tmpPem);

        throw new Exception("Failed to parse PFX: " . openssl_error_string() . " | CLI Output: " . $output);
    }

    public function getCertInfo(string $certPem)
    {
        return @openssl_x509_parse($certPem);
    }

    public function getCertInfoFromCsr(string $csrPem)
    {
        return @openssl_csr_get_subject($csrPem, false);
    }

    public function extractSansFromCsr(string $csrPem)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csr_');
        file_put_contents($tmp, $csrPem);
        
        $cmd = "openssl req -in " . escapeshellarg($tmp) . " -noout -text 2>&1";
        $output = shell_exec($cmd);
        unlink($tmp);

        $sans = [];
        if (preg_match('/Subject Alternative Name:.*?\n\s*(.*?)\n/s', $output, $matches)) {
            $parts = explode(',', $matches[1]);
            foreach ($parts as $part) {
                $sans[] = trim(str_replace(['DNS:', 'IP Address:'], '', $part));
            }
        }
        return $sans;
    }

    public function extractThumbprint(string $certPem, string $algo = 'sha1')
    {
        $res = @openssl_x509_read($certPem);
        if (!$res) return null;
        
        openssl_x509_export($res, $out, false);
        $data = base64_decode(preg_replace('/\-+BEGIN CERTIFICATE\-+|-+END CERTIFICATE\-+|\r|\n/', '', $out));
        
        return hash($algo, $data);
    }

    public function extractSubjectKeyIdentifier(string $certPem)
    {
        $info = $this->getCertInfo($certPem);
        $skid = $info['extensions']['subjectKeyIdentifier'] ?? null;
        if ($skid) {
            return strtolower(str_replace([':', ' '], '', $skid));
        }
        return null;
    }

    public function extractAuthorityKeyIdentifier(string $certPem)
    {
        $info = $this->getCertInfo($certPem);
        $akid = $info['extensions']['authorityKeyIdentifier'] ?? null;
        if ($akid) {
            // Usually looks like "keyid:XX:XX..."
            if (preg_match('/keyid:([0-9A-Fa-f: ]+)/', $akid, $matches)) {
                return strtolower(str_replace([':', ' '], '', $matches[1]));
            }
        }
        return null;
    }

    public function extractFullSubjectDn(string $certPem)
    {
        $info = $this->getCertInfo($certPem);
        return $info ? json_encode($info['subject']) : null;
    }

    public function extractFullIssuerDn(string $certPem)
    {
        $info = $this->getCertInfo($certPem);
        return $info ? json_encode($info['issuer']) : null;
    }
}
