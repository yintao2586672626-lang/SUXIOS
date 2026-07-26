#!/usr/bin/env php
<?php
declare(strict_types=1);

$options = getopt('', [
    'profile-id:',
    'session-id:',
    'platform:',
    'gateway-url::',
    'control-token-file::',
]);
$profileId = trim((string)($options['profile-id'] ?? ''));
$sessionId = trim((string)($options['session-id'] ?? ''));
$platform = strtolower(trim((string)($options['platform'] ?? '')));
$gatewayUrl = rtrim(trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')), '/');
$tokenFile = trim((string)($options['control-token-file'] ?? '/etc/suxios-cloud-browser/control-token'));

if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
    || preg_match('/^cbls_[A-Za-z0-9_-]{16,64}$/D', $sessionId) !== 1
    || !in_array($platform, ['ctrip', 'meituan', 'dingdandao'], true)
    || $gatewayUrl !== 'http://127.0.0.1:8787'
    || !in_array($tokenFile, [
        '/etc/suxios-cloud-browser/control-token',
        '/run/credentials/suxios-cloud-browser-login-complete.service/control-token',
    ], true)
) {
    fail('cloud_browser_login_complete_arguments_invalid', 2);
}

$controlToken = @file_get_contents($tokenFile);
$controlToken = is_string($controlToken) ? trim($controlToken) : '';
if (strlen($controlToken) < 32) {
    fail('cloud_browser_control_token_unavailable');
}

try {
    $body = json_encode([
        'profile_id' => $profileId,
        'session_id' => $sessionId,
        'platform' => $platform,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$controlToken}\r\n",
            'content' => $body,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($gatewayUrl . '/v1/login/complete', false, $context);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($result)
        || (string)($result['profile_id'] ?? '') !== $profileId
        || (string)($result['status'] ?? '') !== 'ready_to_collect'
    ) {
        $reason = is_array($result) ? (string)($result['reason'] ?? '') : '';
        throw new RuntimeException($reason !== '' ? $reason : 'cloud_browser_login_complete_failed');
    }
    echo json_encode([
        'status' => 'ready_to_collect',
        'profile_id' => $profileId,
        'platform' => $platform,
        'browser_started' => false,
        'profile_encrypted_at_rest' => true,
        'receipt_id' => (string)($result['receipt_id'] ?? ''),
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fail($error->getMessage() !== '' ? $error->getMessage() : 'cloud_browser_login_complete_failed');
} finally {
    $controlToken = str_repeat("\0", strlen($controlToken));
}

function fail(string $reason, int $exitCode = 1): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
            ?: 'cloud_browser_login_complete_failed',
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
