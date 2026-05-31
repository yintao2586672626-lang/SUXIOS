<?php

declare(strict_types=1);

function parse_args(array $argv): array
{
    $args = [
        'profile_dir' => '',
        'output' => '',
    ];
    foreach (array_slice($argv, 1) as $item) {
        if (str_starts_with($item, '--profile-dir=')) {
            $args['profile_dir'] = substr($item, strlen('--profile-dir='));
        } elseif (str_starts_with($item, '--output=')) {
            $args['output'] = substr($item, strlen('--output='));
        }
    }
    return $args;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

function find_cookie_db(string $profileDir): string
{
    foreach ([
        $profileDir . DIRECTORY_SEPARATOR . 'Default' . DIRECTORY_SEPARATOR . 'Network' . DIRECTORY_SEPARATOR . 'Cookies',
        $profileDir . DIRECTORY_SEPARATOR . 'Network' . DIRECTORY_SEPARATOR . 'Cookies',
        $profileDir . DIRECTORY_SEPARATOR . 'Default' . DIRECTORY_SEPARATOR . 'Cookies',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function decrypt_chrome_master_key(string $profileDir): string
{
    $localStatePath = $profileDir . DIRECTORY_SEPARATOR . 'Local State';
    if (!is_file($localStatePath)) {
        fail('Local State not found in profile dir');
    }
    $state = json_decode((string)file_get_contents($localStatePath), true);
    if (!is_array($state) || empty($state['os_crypt']['encrypted_key'])) {
        fail('Chrome encrypted key not found');
    }
    $encrypted = base64_decode((string)$state['os_crypt']['encrypted_key'], true);
    if ($encrypted === false || strlen($encrypted) <= 5) {
        fail('Chrome encrypted key is invalid');
    }
    $dpapiPayload = substr($encrypted, 5);
    $payloadB64 = base64_encode($dpapiPayload);
    $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command '
        . escapeshellarg(
            "Add-Type -AssemblyName System.Security; "
            . "\$b=[Convert]::FromBase64String('{$payloadB64}'); "
            . "\$u=[System.Security.Cryptography.ProtectedData]::Unprotect(\$b,\$null,[System.Security.Cryptography.DataProtectionScope]::CurrentUser); "
            . "[Convert]::ToBase64String(\$u)"
        );
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || empty($output)) {
        fail('DPAPI master key decrypt failed');
    }
    $key = base64_decode(trim((string)$output[0]), true);
    if ($key === false || strlen($key) !== 32) {
        fail('DPAPI master key length is invalid');
    }
    return $key;
}

function chrome_cookie_plain_value(array $row, string $masterKey): string
{
    $value = (string)($row['value'] ?? '');
    if ($value !== '') {
        return $value;
    }
    $encrypted = (string)($row['encrypted_value'] ?? '');
    if ($encrypted === '' || (substr($encrypted, 0, 3) !== 'v10' && substr($encrypted, 0, 3) !== 'v11')) {
        return '';
    }
    if (strlen($encrypted) <= 31) {
        return '';
    }
    $nonce = substr($encrypted, 3, 12);
    $ciphertext = substr($encrypted, 15, -16);
    $tag = substr($encrypted, -16);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $masterKey, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        return '';
    }
    $hostHash = hash('sha256', (string)($row['host_key'] ?? ''), true);
    if (strlen($plain) > 32 && hash_equals($hostHash, substr($plain, 0, 32))) {
        $plain = substr($plain, 32);
    }
    return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $plain) ?? '');
}

function is_cookie_header_safe(string $name, string $value): bool
{
    if ($name === '' || preg_match('/[=\s;,]/', $name)) {
        return false;
    }
    return !preg_match('/[^\x20-\x7E]/', $value);
}

$args = parse_args($argv);
$profileDir = trim((string)$args['profile_dir']);
$outputPath = trim((string)$args['output']);
if ($profileDir === '' || !is_dir($profileDir)) {
    fail('profile dir not found');
}
if ($outputPath === '') {
    fail('missing output path');
}
if (!extension_loaded('pdo_sqlite')) {
    fail('pdo_sqlite extension is required');
}
if (!extension_loaded('openssl')) {
    fail('openssl extension is required');
}

$cookieDb = find_cookie_db($profileDir);
if ($cookieDb === '') {
    fail('Chromium Cookies DB not found');
}

$masterKey = decrypt_chrome_master_key($profileDir);
$tmpDb = tempnam(sys_get_temp_dir(), 'ctrip_cookie_db_');
if ($tmpDb === false || !copy($cookieDb, $tmpDb)) {
    fail('failed to copy Cookies DB');
}

$pairs = [];
$names = [];
$skipped = [];
try {
    $pdo = new PDO('sqlite:' . $tmpDb);
    $stmt = $pdo->query(
        "SELECT host_key, name, value, encrypted_value FROM cookies "
        . "WHERE host_key LIKE '%ctrip%' OR host_key LIKE '%ctripbiz%'"
    );
    if (!$stmt) {
        fail('failed to query Cookies DB');
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)($row['name'] ?? ''));
        $value = chrome_cookie_plain_value($row, $masterKey);
        if ($value === '') {
            $skipped[] = $name;
            continue;
        }
        if (!is_cookie_header_safe($name, $value)) {
            $skipped[] = $name;
            continue;
        }
        $pairs[] = $name . '=' . $value;
        $names[] = $name;
    }
} finally {
    @unlink($tmpDb);
}

if ($pairs === []) {
    fail('no usable Ctrip cookies found in profile');
}

$outputDir = dirname($outputPath);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fail('failed to create output dir');
}
file_put_contents($outputPath, implode('; ', $pairs), LOCK_EX);

echo json_encode([
    'status' => 'ok',
    'output' => $outputPath,
    'cookie_count' => count($pairs),
    'skipped_count' => count($skipped),
    'cookie_names_sample' => array_slice($names, 0, 12),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
