#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use think\App;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', ['hotel-id:', 'owner-user-id:', 'platform:', 'gateway-url::']);
$hotelId = (int)($options['hotel-id'] ?? 0);
$ownerUserId = (int)($options['owner-user-id'] ?? 0);
$platform = strtolower(trim((string)($options['platform'] ?? '')));
$gatewayUrl = rtrim(trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')), '/');
if ($hotelId <= 0 || $ownerUserId <= 0 || !in_array($platform, ['ctrip', 'meituan', 'dingdandao'], true)
    || $gatewayUrl !== 'http://127.0.0.1:8787'
) {
    fwrite(STDERR, "cloud_browser_login_arguments_invalid\n");
    exit(2);
}

try {
    $entry = (new CloudBrowserProfileService())->requestLoginEntry($hotelId, $ownerUserId, $platform);
    $profile = is_array($entry['profile'] ?? null) ? $entry['profile'] : [];
    $login = is_array($entry['login_entry'] ?? null) ? $entry['login_entry'] : [];
    $body = json_encode([
        'profile_id' => (string)($profile['profile_id'] ?? ''),
        'session_id' => (string)($login['session_id'] ?? ''),
        'ticket' => (string)($login['ticket'] ?? ''),
        'platform' => $platform,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($gatewayUrl . '/v1/login/open', false, $context);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($result) || (string)($result['status'] ?? '') !== 'awaiting_login') {
        $gatewayReason = is_array($result)
            ? preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)($result['reason'] ?? ''))
            : '';
        throw new RuntimeException('cloud_browser_gateway_open_failed_' . ($gatewayReason ?: 'unknown'));
    }
    echo json_encode([
        'status' => 'login_window_open',
        'platform' => $platform,
        'hotel_id' => $hotelId,
        'owner_user_id' => $ownerUserId,
        'profile_id' => (string)($profile['profile_id'] ?? ''),
        'session_id' => (string)($login['session_id'] ?? ''),
        'expires_at' => (string)($login['expires_at'] ?? ''),
        'viewer_url' => (string)($result['viewer_url'] ?? 'http://127.0.0.1:6080/vnc.html?autoconnect=1'),
        'browser_started' => true,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => preg_replace('/[^a-zA-Z0-9_-]+/', '_', $exception->getMessage()) ?: 'cloud_browser_login_open_failed',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
