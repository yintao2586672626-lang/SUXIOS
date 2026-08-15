#!/usr/bin/env php
<?php
declare(strict_types=1);

const OTA_BATCH_TOKEN_FILE = '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token';
const OTA_BATCH_MAX_TRANSIENT_RETRIES = 2;
const OTA_BATCH_RETRY_DELAYS_SECONDS = [30, 90];
const OTA_BATCH_CHILD_DEADLINE_SECONDS = 660;
const OTA_BATCH_MAX_OUTPUT_BYTES = 262144;
const OTA_BATCH_TRANSIENT_REASON_CODES = [
    'gateway_collection_capacity_busy',
    'gateway_temporarily_unavailable',
    'gateway_connection_timeout',
    'gateway_connection_refused',
    'cloud_ota_gateway_temporarily_unavailable',
    'cloud_ota_gateway_connection_timeout',
    'cloud_ota_gateway_connection_refused',
];

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
    $finalReceipt = null;
    $attemptCount = 0;
    $retryCount = 0;
    $transientRetryExhausted = false;

    while ($attemptCount <= OTA_BATCH_MAX_TRANSIENT_RETRIES) {
        $attemptCount++;
        $result = runCollectionChild($root, $scope, $ownerUserId);
        $receipt = sanitizeChildReceipt(
            (string)$scope['platform'],
            $result['exit_code'],
            $result['child']
        );
        $verified = collectionVerified($receipt, $result['exit_code']);
        if ($verified) {
            $finalReceipt = $receipt;
            break;
        }

        $retryable = transientFailure(
            $receipt,
            $result['stdout'],
            $result['stderr']
        );
        if (!$retryable || $attemptCount > OTA_BATCH_MAX_TRANSIENT_RETRIES) {
            $transientRetryExhausted = $retryable;
            $finalReceipt = $receipt;
            break;
        }

        $delay = OTA_BATCH_RETRY_DELAYS_SECONDS[$retryCount];
        $retryCount++;
        sleep($delay);
    }

    if (!is_array($finalReceipt)) {
        $finalReceipt = blockedReceipt(
            (string)$scope['platform'],
            'cloud_ota_batch_attempt_state_invalid'
        );
    }
    $finalReceipt['attempt_count'] = $attemptCount;
    $finalReceipt['retry_count'] = $retryCount;
    $finalReceipt['transient_retry_exhausted'] = $transientRetryExhausted;
    $receipts[] = $finalReceipt;
    if (!collectionVerified($finalReceipt, (int)$finalReceipt['exit_code'])) {
        $allVerified = false;
    }
}

echo json_encode([
    'status' => $allVerified ? 'all_sources_saved_and_readback_verified' : 'partial_or_blocked',
    'execution_mode' => 'serial',
    'retry_policy' => [
        'max_transient_retries' => OTA_BATCH_MAX_TRANSIENT_RETRIES,
        'backoff_seconds' => OTA_BATCH_RETRY_DELAYS_SECONDS,
        'explicit_transient_only' => true,
        'requires_explicit_no_persistence' => true,
    ],
    'sources' => $receipts,
    'message_sent' => false,
    'sensitive_values_exposed' => false,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($allVerified ? 0 : 1);

/**
 * @param array{platform:string,source_id:int,profile_id:string} $scope
 * @return array{exit_code:int,stdout:string,stderr:string,child:array<string,mixed>}
 */
function runCollectionChild(string $root, array $scope, int $ownerUserId): array
{
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
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => '',
            'child' => blockedReceipt(
                (string)$scope['platform'],
                'cloud_ota_child_start_failed'
            ),
        ];
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadlineAt = microtime(true) + OTA_BATCH_CHILD_DEADLINE_SECONDS;
    $observedExitCode = null;
    $timedOut = false;
    while (true) {
        $stdout = appendOutputTail($stdout, (string)stream_get_contents($pipes[1]));
        $stderr = appendOutputTail($stderr, (string)stream_get_contents($pipes[2]));
        $status = proc_get_status($process);
        if (($status['running'] ?? false) !== true) {
            $observedExitCode = is_int($status['exitcode'] ?? null)
                ? (int)$status['exitcode']
                : null;
            break;
        }
        if (microtime(true) >= $deadlineAt) {
            $timedOut = true;
            proc_terminate($process);
            usleep(250000);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(100000);
    }
    $stdout = appendOutputTail($stdout, (string)stream_get_contents($pipes[1]));
    $stderr = appendOutputTail($stderr, (string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeExitCode = proc_close($process);
    $exitCode = $timedOut
        ? 124
        : (($observedExitCode !== null && $observedExitCode >= 0)
            ? $observedExitCode
            : $closeExitCode);
    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'child' => $timedOut
            ? blockedReceipt(
                (string)$scope['platform'],
                'cloud_ota_child_timeout',
                null
            )
            : lastJsonObject($stdout . "\n" . $stderr),
    ];
}

/** @param array<string,mixed> $receipt */
function collectionVerified(array $receipt, int $exitCode): bool
{
    return ($receipt['status'] ?? '') === 'saved_and_readback_verified'
        && ($receipt['readback_verified'] ?? false) === true
        && ($receipt['gateway_receipt_readback_verified'] ?? false) === true
        && ($receipt['business_data_persisted'] ?? false) === true
        && $exitCode === 0;
}

/**
 * Only a small, explicit gateway-transient allowlist may retry. The child
 * currently reduces a loopback connection failure to cloud_ota_gateway_failed,
 * so that generic code is retryable only when the non-persisted process output
 * also contains an unambiguous connection timeout/refusal marker. Raw process
 * output is never returned or persisted by this batch boundary.
 *
 * @param array<string,mixed> $receipt
 */
function transientFailure(array $receipt, string $stdout, string $stderr): bool
{
    if (($receipt['business_data_persisted'] ?? null) !== false) {
        return false;
    }
    $reason = safeReason((string)($receipt['reason'] ?? ''));
    if (in_array($reason, OTA_BATCH_TRANSIENT_REASON_CODES, true)) {
        return true;
    }
    if ($reason !== 'cloud_ota_gateway_failed') {
        return false;
    }
    return preg_match(
        '/(?:connection\s+(?:timed\s*out|refused)|connect(?:ion)?[_ -]?timeout|failed\s+to\s+open\s+stream[^\r\n]*(?:timed\s*out|refused))/i',
        $stdout . "\n" . $stderr
    ) === 1;
}

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
    $businessDataPersisted = array_key_exists('business_data_persisted', $child)
        && is_bool($child['business_data_persisted'])
            ? $child['business_data_persisted']
            : null;
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
        'gateway_receipt_readback_verified' => ($child['gateway_receipt_readback_verified'] ?? false) === true,
        'business_data_persisted' => $businessDataPersisted,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ];
}

/** @return array<string,mixed> */
function blockedReceipt(
    string $platform,
    string $reason,
    ?bool $businessDataPersisted = false
): array
{
    return [
        'platform' => $platform,
        'exit_code' => 1,
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'saved_count' => 0,
        'readback_count' => 0,
        'readback_verified' => false,
        'gateway_receipt_readback_verified' => false,
        'business_data_persisted' => $businessDataPersisted,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ];
}

function appendOutputTail(string $current, string $chunk): string
{
    if ($chunk === '') {
        return $current;
    }
    $combined = $current . $chunk;
    if (strlen($combined) <= OTA_BATCH_MAX_OUTPUT_BYTES) {
        return $combined;
    }
    return substr($combined, -OTA_BATCH_MAX_OUTPUT_BYTES);
}

function safeReason(string $value): string
{
    $value = trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)), '_');
    return substr($value, 0, 100);
}
