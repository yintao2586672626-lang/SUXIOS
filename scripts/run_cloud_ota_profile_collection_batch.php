#!/usr/bin/env php
<?php
declare(strict_types=1);

const OTA_BATCH_TOKEN_FILE = '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token';

$root = dirname(__DIR__);
$ownerUserId = positiveEnvironmentInt('SUXIOS_CLOUD_OTA_OWNER_USER_ID');
$scopes = [
    [
        'platform' => 'ctrip',
        'source_id' => positiveEnvironmentInt('SUXIOS_CLOUD_OTA_CTRIP_SOURCE_ID'),
        'profile_id' => profileEnvironmentId('SUXIOS_CLOUD_OTA_CTRIP_PROFILE_ID'),
    ],
    [
        'platform' => 'meituan',
        'source_id' => positiveEnvironmentInt('SUXIOS_CLOUD_OTA_MEITUAN_SOURCE_ID'),
        'profile_id' => profileEnvironmentId('SUXIOS_CLOUD_OTA_MEITUAN_PROFILE_ID'),
    ],
];

$receipts = [];
$allVerified = true;
foreach ($scopes as $scope) {
    $command = [
        PHP_BINARY,
        '-d',
        'memory_limit=384M',
        $root . '/scripts/run_cloud_ota_profile_collection.php',
        '--data-source-id=' . $scope['source_id'],
        '--owner-user-id=' . $ownerUserId,
        '--profile-id=' . $scope['profile_id'],
        '--control-token-file=' . OTA_BATCH_TOKEN_FILE,
        '--timeout-seconds=600',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        $receipts[] = blockedReceipt((string)$scope['platform'], 'cloud_ota_child_start_failed');
        $allVerified = false;
        continue;
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $child = lastJsonObject($stdout . "\n" . $stderr);
    $receipt = sanitizeChildReceipt((string)$scope['platform'], $exitCode, $child);
    $receipts[] = $receipt;
    if (($receipt['status'] ?? '') !== 'saved_and_readback_verified'
        || ($receipt['readback_verified'] ?? false) !== true
        || ($receipt['business_data_persisted'] ?? false) !== true
        || $exitCode !== 0
    ) {
        $allVerified = false;
    }
}

echo json_encode([
    'status' => $allVerified ? 'all_sources_saved_and_readback_verified' : 'partial_or_blocked',
    'execution_mode' => 'serial',
    'sources' => $receipts,
    'message_sent' => false,
    'sensitive_values_exposed' => false,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($allVerified ? 0 : 1);

function positiveEnvironmentInt(string $key): int
{
    $value = filter_var(getenv($key), FILTER_VALIDATE_INT);
    if (!is_int($value) || $value <= 0) {
        fwrite(STDERR, json_encode(blockedReceipt('batch', 'cloud_ota_batch_scope_invalid')) . PHP_EOL);
        exit(1);
    }
    return $value;
}

function profileEnvironmentId(string $key): string
{
    $value = trim((string)getenv($key));
    if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        fwrite(STDERR, json_encode(blockedReceipt('batch', 'cloud_ota_batch_scope_invalid')) . PHP_EOL);
        exit(1);
    }
    return $value;
}

/** @return array<string,mixed> */
function lastJsonObject(string $output): array
{
    $lines = array_reverse(preg_split('/\R+/', trim($output)) ?: []);
    foreach ($lines as $line) {
        $decoded = json_decode(trim((string)$line), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

/** @param array<string,mixed> $child @return array<string,mixed> */
function sanitizeChildReceipt(string $platform, int $exitCode, array $child): array
{
    $status = safeReason((string)($child['status'] ?? 'blocked'));
    $reason = safeReason((string)($child['reason'] ?? ''));
    return [
        'platform' => $platform,
        'exit_code' => $exitCode,
        'status' => $status,
        'reason' => $reason,
        'data_source_id' => max(0, (int)($child['data_source_id'] ?? 0)),
        'hotel_id' => max(0, (int)($child['hotel_id'] ?? 0)),
        'target_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($child['target_date'] ?? '')) === 1
            ? (string)$child['target_date']
            : '',
        'saved_count' => max(0, (int)($child['saved_count'] ?? 0)),
        'readback_count' => max(0, (int)($child['readback_count'] ?? 0)),
        'readback_verified' => ($child['readback_verified'] ?? false) === true,
        'business_data_persisted' => ($child['business_data_persisted'] ?? false) === true,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ];
}

/** @return array<string,mixed> */
function blockedReceipt(string $platform, string $reason): array
{
    return [
        'platform' => $platform,
        'exit_code' => 1,
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'saved_count' => 0,
        'readback_count' => 0,
        'readback_verified' => false,
        'business_data_persisted' => false,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ];
}

function safeReason(string $value): string
{
    $value = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)), '_');
    return substr($value, 0, 100);
}
