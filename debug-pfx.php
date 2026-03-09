<?php

// Usage: php debug-pfx.php <path_to_pfx> <password>

if ($argc < 3) {
    echo "Usage: php debug-pfx.php <path_to_pfx> <password>\n";
    exit(1);
}

$path = $argv[1];
$password = $argv[2];

if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(1);
}

$pfxData = file_get_contents($path);

echo "Attempting to read PFX: $path\n";

if (openssl_pkcs12_read($pfxData, $certs, $password)) {
    echo "SUCCESS: PFX read correctly via native PHP.\n";
    echo "Subject: " . openssl_x509_parse($certs['cert'])['name'] . "\n";
} else {
    echo "NATIVE FAILURE: " . openssl_error_string() . "\n";
    echo "Attempting CLI fallback with -legacy...\n";
    
    $tmpPfx = tempnam(sys_get_temp_dir(), 'pfx_');
    file_put_contents($tmpPfx, $pfxData);
    $tmpPem = tempnam(sys_get_temp_dir(), 'pem_');
    
    $cmd = "openssl pkcs12 -in " . escapeshellarg($tmpPfx) . " -out " . escapeshellarg($tmpPem) . " -nodes -passin pass:" . escapeshellarg($password) . " -legacy 2>&1";
    $output = shell_exec($cmd);
    
    if (file_exists($tmpPem) && $pemData = file_get_contents($tmpPem)) {
        preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $pemData, $keyMatch);
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemData, $certMatches);
        
        if (!empty($keyMatch) && !empty($certMatches[0])) {
            echo "CLI SUCCESS!\n";
            $info = openssl_x509_parse($certMatches[0][0]);
            echo "Subject: " . $info['name'] . "\n";
            echo "Key found: Yes\n";
            echo "Certificates found: " . count($certMatches[0]) . "\n";
        } else {
            echo "CLI extraction failed to find keys/certs in output.\n";
            echo "Output: $output\n";
        }
    } else {
        echo "CLI command failed to produce PEM file.\n";
        echo "Output: $output\n";
    }
    
    @unlink($tmpPfx);
    @unlink($tmpPem);
}
