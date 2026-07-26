#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use app\service\DingdandaoCloudCollectionService;
use think\App;

const MAX_GATEWAY_INPUT_BYTES = 8192;

$appDir = dirname(__DIR__);
$autoload = $appDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fail('app_autoload_missing');
}

$raw = stream_get_contents(STDIN, MAX_GATEWAY_INPUT_BYTES + 1);
if (!is_string($raw) || $raw === '' || strlen($raw) > MAX_GATEWAY_INPUT_BYTES) {
    fail('gateway_input_invalid');
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    fail('gateway_input_invalid');
}

require $autoload;
(new App($appDir))->initialize();

$action = strtolower(trim((string)($input['action'] ?? '')));
$service = new CloudBrowserProfileService();
$dingdandaoCollection = new DingdandaoCloudCollectionService();

try {
    $result = match ($action) {
        'validate_login' => $service->validateLoginEntry(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredTicket($input)
        ),
        'complete_login' => $service->completeGatewayLogin(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'session_id', 'cbls_'),
            requiredTicket($input),
            requiredText($input, 'session_expires_at', 32)
        ),
        'expire_profile' => $service->markSessionExpired(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredText($input, 'reason', 80)
        ),
        'validate_dingdandao_collection' => $service->validateDingdandaoCollectionProfile(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredPositiveInt($input, 'tenant_id'),
            requiredPositiveInt($input, 'hotel_id'),
            requiredPositiveInt($input, 'owner_user_id'),
            requiredDate($input, 'target_date')
        ),
        'claim_dingdandao_collection' => $dingdandaoCollection->claim(
            requiredId($input, 'profile_id', 'cbp_'),
            requiredId($input, 'collection_session_id', 'cbcs_'),
            requiredPositiveInt($input, 'tenant_id'),
            requiredPositiveInt($input, 'hotel_id'),
            requiredPositiveInt($input, 'owner_user_id'),
            requiredDate($input, 'target_date'),
            requiredText($input, 'collection_kind', 40),
            requiredText($input, 'access_mode', 20),
            requiredText($input, 'window_expires_at', 40)
        ),
        'complete_dingdandao_collection' => $dingdandaoCollection->completeLifecycle(
            requiredId($input, 'claim_id', 'cct_'),
            requiredId($input, 'collection_session_id', 'cbcs_'),
            requiredId($input, 'profile_id', 'cbp_'),
            requiredText($input, 'outcome', 32)
        ),
        default => throw new RuntimeException('gateway_action_unsupported'),
    };
} catch (Throwable $e) {
    fail($e->getMessage() !== '' ? $e->getMessage() : 'gateway_action_failed');
}

echo json_encode(
    ['status' => 'ok', 'action' => $action, 'result' => $result],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

/** @param array<string,mixed> $input */
function requiredId(array $input, string $key, string $prefix): string
{
    $value = trim((string)($input[$key] ?? ''));
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredTicket(array $input): string
{
    $ticket = trim((string)($input['ticket'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{32,96}$/D', $ticket) !== 1) {
        throw new RuntimeException('gateway_ticket_invalid');
    }
    return $ticket;
}

/** @param array<string,mixed> $input */
function requiredText(array $input, string $key, int $maxLength): string
{
    $value = trim((string)($input[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredPositiveInt(array $input, string $key): int
{
    $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($value) || $value <= 0) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

/** @param array<string,mixed> $input */
function requiredDate(array $input, string $key): string
{
    $value = trim((string)($input[$key] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException('gateway_' . $key . '_invalid');
    }
    return $value;
}

function fail(string $reason): never
{
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason) ?: 'gateway_action_failed',
    ]) . PHP_EOL);
    exit(1);
}
