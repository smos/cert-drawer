<?php
/**
 * External Certificate & DNS Poller for Cert Drawer
 * 
 * This script is designed to be hosted independently. It receives requests,
 * verifies the domain with the main Cert Drawer instance, and performs
 * health checks.
 */

// --- CONFIGURATION ---
// Set this to the base URL of your Cert Drawer instance
$CERT_DRAWER_URL = "https://certdrawer.domain.local";
// If set in Cert Drawer settings, provide the API Key here
$POLLER_API_KEY = "";
// ---------------------

header('Content-Type: application/json');

// 1. Basic Input Validation
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$domain = $input['domain'] ?? '';
$type = $input['type'] ?? ''; // 'dns' or 'certificate'
$resolver = $input['resolver'] ?? null;

if (empty($domain) || !in_array($type, ['dns', 'certificate'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing domain or invalid type']);
    exit;
}

// Sanitize domain
$domain = filter_var($domain, FILTER_SANITIZE_URL);
if (!preg_match('/^[a-z0-9.-]+$/i', $domain)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid domain format']);
    exit;
}

// 2. Verify Domain with Cert Drawer (Anti-Abuse)
$verifyUrl = rtrim($CERT_DRAWER_URL, '/') . "/domaintest?domain=" . urlencode($domain);

// Create a stream context to disable SSL verification for the callback if needed
$contextOptions = [
    "http" => [
        "ignore_errors" => true,
        "timeout" => 5,
        "header" => "X-Poller-Key: " . $POLLER_API_KEY . "\r\n"
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
    ],
];
$context = stream_context_create($contextOptions);
$verifyRes = @file_get_contents($verifyUrl, false, $context);

if ($verifyRes === false) {
    $error = error_get_last();
    http_response_code(500);
    echo json_encode([
        'error' => 'Could not connect to Cert Drawer for verification.',
        'details' => $error['message'] ?? 'Unknown connection error'
    ]);
    exit;
}

$verifyData = json_decode($verifyRes, true);

if (!$verifyData || !isset($verifyData['exists']) || $verifyData['exists'] !== true) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Domain verification failed.',
        'server_response' => $verifyData
    ]);
    exit;
}

// 3. Perform the requested check
if ($type === 'dns') {
    performDnsCheck($domain, $resolver);
} else {
    performCertCheck($domain, $resolver);
}

// --- HELPERS ---

function getResolverPart($resolver) {
    if (!$resolver) return "";
    return "@" . escapeshellarg($resolver);
}

function performDnsCheck($domain, $resolver) {
    $types = ['A', 'AAAA', 'TXT', 'NS', 'CNAME'];
    $records = [];
    $resPart = getResolverPart($resolver);

    foreach ($types as $t) {
        $output = [];
        $result = 0;
        exec("dig {$resPart} +noall +answer " . escapeshellarg($domain) . " " . escapeshellarg($t) . " +tries=2 +time=2", $output, $result);
        
        // Fallback to local DNS if resolver failed OR returned no results
        if (($result !== 0 || empty($output)) && $resolver) {
            $output = [];
            exec("dig +noall +answer " . escapeshellarg($domain) . " " . escapeshellarg($t) . " +tries=2 +time=2", $output);
        }

        $cleaned = [];
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5 && strtoupper($parts[3]) === $t) {
                $value = implode(' ', array_slice($parts, 4));
                $cleaned[] = trim($value, '"');
            }
        }
        if ($t === 'TXT' || $t === 'CNAME') sort($cleaned);
        $records[$t] = array_values($cleaned);
    }

    $records['SPF'] = array_values(array_filter($records['TXT'], fn($r) => stripos($r, 'v=spf1') === 0));

    // DMARC
    $dmarcOut = [];
    exec("dig {$resPart} +noall +answer _dmarc." . escapeshellarg($domain) . " TXT +tries=2 +time=2", $dmarcOut, $resD);
    if ((empty($dmarcOut) || $resD !== 0) && $resolver) {
        $dmarcOut = [];
        exec("dig +noall +answer _dmarc." . escapeshellarg($domain) . " TXT +tries=2 +time=2", $dmarcOut);
    }
    
    $dmarcValues = [];
    foreach ($dmarcOut as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 5 && strtoupper($parts[3]) === 'TXT') {
            $val = trim(implode(' ', array_slice($parts, 4)), '"');
            if (stripos($val, 'v=DMARC1') !== false) $dmarcValues[] = $val;
        }
    }
    $records['DMARC'] = $dmarcValues;

    // DKIM (Limited selectors)
    $dkim_selectors = ['default', 'selector1', 'selector2'];
    $dkim_records = [];
    foreach ($dkim_selectors as $selector) {
        $fqdn = "{$selector}._domainkey.{$domain}";
        $out = [];
        exec("dig {$resPart} +noall +answer " . escapeshellarg($fqdn) . " TXT +tries=2 +time=2", $out, $resK);
        if ((empty($out) || $resK !== 0) && $resolver) {
            $out = [];
            exec("dig +noall +answer " . escapeshellarg($fqdn) . " TXT +tries=2 +time=2", $out);
        }
        foreach ($out as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 5 && strtoupper($parts[3]) === 'TXT') {
                $val = trim(implode(' ', array_slice($parts, 4)), '"');
                if (stripos($val, 'v=DKIM1') !== false) $dkim_records[] = "{$selector}: {$val}";
            }
        }
    }
    $records['DKIM'] = array_values(array_filter($dkim_records));
    sort($records['DKIM']);

    echo json_encode($records);
}

function performCertCheck($domain, $resolver) {
    $ips = [];
    $resPart = getResolverPart($resolver);
    
    // Resolve IPv4
    $out4 = [];
    $res4 = 0;
    exec("dig {$resPart} +short " . escapeshellarg($domain) . " A +tries=2 +time=2", $out4, $res4);
    if (($res4 !== 0 || empty($out4)) && $resolver) { 
        $out4 = []; 
        exec("dig +short " . escapeshellarg($domain) . " A +tries=2 +time=2", $out4); 
    }
    
    foreach (array_filter(array_map('trim', $out4)) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[] = ['ip' => $ip, 'version' => 'v4'];
    }

    // Resolve IPv6
    $out6 = [];
    $res6 = 0;
    exec("dig {$resPart} +short " . escapeshellarg($domain) . " AAAA +tries=2 +time=2", $out6, $res6);
    if (($res6 !== 0 || empty($out6)) && $resolver) { 
        $out6 = []; 
        exec("dig +short " . escapeshellarg($domain) . " AAAA +tries=2 +time=2", $out6); 
    }

    foreach (array_filter(array_map('trim', $out6)) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) $ips[] = ['ip' => $ip, 'version' => 'v6'];
    }

    $results = [];
    foreach ($ips as $ipData) {
        $results[] = checkSsl($domain, $ipData['ip'], $ipData['version']);
    }

    echo json_encode($results);
}

function checkSsl($host, $ip, $version) {
    $port = 443;
    $remote = ($version === 'v6') ? "ssl://[{$ip}]:{$port}" : "ssl://{$ip}:{$port}";
    
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ],
    ]);

    $res = [
        'ip_address' => $ip,
        'ip_version' => $version,
        'thumbprint_sha256' => null,
        'issuer' => null,
        'expiry_date' => null,
        'error' => null,
    ];

    $fp = @stream_socket_client($remote, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

    if ($fp) {
        $params = stream_context_get_params($fp);
        if (isset($params['options']['ssl']['peer_certificate'])) {
            $cert = $params['options']['ssl']['peer_certificate'];
            $info = openssl_x509_parse($cert);
            if ($info) {
                $res['issuer'] = $info['issuer']['CN'] ?? 'Unknown';
                $res['expiry_date'] = date('Y-m-d H:i:s', $info['validTo_time_t']);
                openssl_x509_export($cert, $pem);
                $res['thumbprint_sha256'] = hash('sha256', base64_decode(
                    preg_replace('/\-+BEGIN CERTIFICATE\-+|\-+END CERTIFICATE\-+|\n|\r/', '', $pem)
                ));
            }
        }
        fclose($fp);
    } else {
        $res['error'] = "Connection failed: {$errstr} ({$errno})";
    }

    return $res;
}
