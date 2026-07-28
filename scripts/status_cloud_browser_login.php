#!/usr/bin/env php
<?php
declare(strict_types=1);

const CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION =
    'suxios_cloud_browser_gateway.v2';

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
$gatewayUrl = rtrim(trim((string)(
    $options['gateway-url'] ?? 'http://127.0.0.1:8787'
)), '/');
$tokenFile = trim((string)(
    $options['control-token-file']
        ?? '/etc/suxios-cloud-browser/control-token'
));

if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
    || preg_match('/^cbls_[A-Za-z0-9_-]{16,64}$/D', $sessionId) !== 1
    || !in_array($platform, ['ctrip', 'meituan', 'dingdandao'], true)
    || $gatewayUrl !== 'http://127.0.0.1:8787'
    || !in_array($tokenFile, [
        '/etc/suxios-cloud-browser/control-token',
        '/run/credentials/suxios-cloud-browser-login-status.service/control-token',
    ], true)
) {
    statusFail('cloud_browser_login_status_arguments_invalid', 2);
}

$controlToken = @file_get_contents($tokenFile);
$controlToken = is_string($controlToken) ? trim($controlToken) : '';
if (strlen($controlToken) < 32) {
    statusFail('cloud_browser_control_token_unavailable');
}

try {
    $gatewayBuildSha256 = assertCloudBrowserGatewayProtocol($gatewayUrl);
    $body = json_encode([
        'profile_id' => $profileId,
        'session_id' => $sessionId,
        'platform' => $platform,
        'expected_gateway_build_sha256' => $gatewayBuildSha256,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n"
                . "Authorization: Bearer {$controlToken}\r\n",
            'content' => $body,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents(
        $gatewayUrl . '/v1/login/status',
        false,
        $context
    );
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($result)
        || (string)($result['profile_id'] ?? '') !== $profileId
        || (string)($result['session_id'] ?? '') !== $sessionId
        || (string)($result['platform'] ?? '') !== $platform
        || (string)($result['protocol_version'] ?? '')
            !== CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION
        || !hash_equals(
            $gatewayBuildSha256,
            (string)($result['build_sha256'] ?? '')
        )
        || ($result['sensitive_values_exposed'] ?? null) !== false
    ) {
        $reason = is_array($result)
            ? (string)($result['reason'] ?? '')
            : '';
        throw new RuntimeException(
            $reason !== ''
                ? $reason
                : 'cloud_browser_login_status_failed'
        );
    }

    echo json_encode([
        'status' => (string)($result['status'] ?? 'unknown'),
        'protocol_version' => CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION,
        'profile_id' => $profileId,
        'session_id' => $sessionId,
        'platform' => $platform,
        'expires_at' => $result['expires_at'] ?? null,
        'browser_started' => $result['browser_started'] ?? null,
        'identity_verified' => $result['identity_verified'] ?? null,
        'binding_required' => $result['binding_required'] ?? null,
        'terminal' => $result['terminal'] ?? null,
        'profile_encrypted_at_rest' =>
            $result['profile_encrypted_at_rest'] ?? null,
        'owned_browser_only' => $result['owned_browser_only'] ?? null,
        'user_browser_closed' => $result['user_browser_closed'] ?? null,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    statusFail(
        $error->getMessage() !== ''
            ? $error->getMessage()
            : 'cloud_browser_login_status_failed'
    );
} finally {
    $controlToken = str_repeat("\0", strlen($controlToken));
}

function statusFail(string $reason, int $exitCode = 1): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => preg_replace(
            '/[^a-zA-Z0-9_-]+/',
            '_',
            $reason
        ) ?: 'cloud_browser_login_status_failed',
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}

function assertCloudBrowserGatewayProtocol(string $gatewayUrl): string
{
    $expectedBuild = expectedCloudBrowserGatewayBuild();
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($gatewayUrl . '/health', false, $context);
    $health = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($health)
        || ($health['status'] ?? '') !== 'ok'
        || ($health['bind'] ?? '') !== '127.0.0.1'
        || ($health['protocol_version'] ?? '')
            !== CLOUD_BROWSER_GATEWAY_PROTOCOL_VERSION
        || !hash_equals(
            $expectedBuild,
            (string)($health['build_sha256'] ?? '')
        )
        || !hash_equals(
            $expectedBuild,
            (string)(
                $health['active_release_gateway_sha256'] ?? ''
            )
        )
        || ($health['active_release_build_match'] ?? null) !== true
    ) {
        throw new RuntimeException(
            'cloud_browser_gateway_build_mismatch'
        );
    }
    return $expectedBuild;
}

function expectedCloudBrowserGatewayBuild(): string
{
    $path = dirname(__DIR__)
        . '/deploy/remote-browser/cloud_browser_gateway.mjs';
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    if (!is_string($hash)
        || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
    ) {
        throw new RuntimeException(
            'cloud_browser_gateway_build_unavailable'
        );
    }
    return $hash;
}
