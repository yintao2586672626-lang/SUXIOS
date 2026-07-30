#!/usr/bin/env php
<?php
declare(strict_types=1);

const MOLANXIN_THREE_SOURCE_HOTEL_ID = 5;
const MOLANXIN_THREE_SOURCE_PLATFORMS = ['ctrip', 'meituan'];
const MOLANXIN_THREE_SOURCE_RECEIPT_PREFIX = 'SUXIOS_AUTO_FETCH_RECEIPT=';
const MOLANXIN_THREE_SOURCE_MAX_OUTPUT_BYTES = 4_000_000;
const MOLANXIN_THREE_SOURCE_TOKEN_FILE =
    '/run/credentials/suxios-molanxin-three-source-collection.service/control-token';
const MOLANXIN_THREE_SOURCE_RUNTIME_DIRECTORY =
    '/run/suxios-molanxin-three-source-collection';

$root = dirname(__DIR__);
$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'pms-profile-id:',
    'ota-collector-user-id:',
    'ota-collector-device-id:',
    'ota-source-ids:',
    'target-date::',
    'control-token-file::',
    'runtime-directory::',
    'php-binary::',
    'node-binary::',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
$hotelId = molanxinThreeSourcePositiveInt(
    $options['hotel-id'] ?? null,
    'hotel_id_invalid'
);
$ownerUserId = molanxinThreeSourcePositiveInt(
    $options['owner-user-id'] ?? null,
    'owner_user_id_invalid'
);
$pmsProfileId = trim((string)($options['pms-profile-id'] ?? ''));
$otaCollectorUserId = molanxinThreeSourcePositiveInt(
    $options['ota-collector-user-id'] ?? null,
    'ota_collector_user_id_invalid'
);
$otaCollectorDeviceId = trim((string)(
    $options['ota-collector-device-id'] ?? ''
));
$otaSourceIds = molanxinThreeSourceIds(
    (string)($options['ota-source-ids'] ?? '')
);
$targetDate = trim((string)($options['target-date'] ?? $today));
$tokenFile = trim((string)(
    $options['control-token-file'] ?? MOLANXIN_THREE_SOURCE_TOKEN_FILE
));
$runtimeDirectory = trim((string)(
    $options['runtime-directory'] ?? MOLANXIN_THREE_SOURCE_RUNTIME_DIRECTORY
));
$phpBinary = trim((string)($options['php-binary'] ?? '/usr/bin/php'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));

if ($hotelId !== MOLANXIN_THREE_SOURCE_HOTEL_ID
    || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $pmsProfileId) !== 1
    || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $otaCollectorDeviceId) !== 1
    || !molanxinThreeSourceValidDate($targetDate)
    || $targetDate !== $today
    || $tokenFile !== MOLANXIN_THREE_SOURCE_TOKEN_FILE
    || $runtimeDirectory !== MOLANXIN_THREE_SOURCE_RUNTIME_DIRECTORY
    || $phpBinary !== '/usr/bin/php'
    || $nodeBinary !== '/usr/bin/node'
) {
    molanxinThreeSourceFail('molanxin_three_source_arguments_invalid', 2);
}

$sourceIdArgument = implode(',', $otaSourceIds);
$platformArgument = implode(',', MOLANXIN_THREE_SOURCE_PLATFORMS);
$otaProcess = null;
$otaReceipt = null;
$otaFailureReason = '';

try {
    $previousControlTokenFile = getenv(
        'SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE'
    );
    putenv('SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE=' . $tokenFile);
    $otaProcess = molanxinThreeSourceRunProcess([
        $phpBinary,
        $root . '/think',
        'online-data:auto-fetch',
        '--realtime-only',
        '--collector-mode=single_user_local',
        '--collector-user-id=' . $otaCollectorUserId,
        '--collector-device-id=' . $otaCollectorDeviceId,
        '--hotel-id=' . MOLANXIN_THREE_SOURCE_HOTEL_ID,
        '--source-ids=' . $sourceIdArgument,
        '--platforms=' . $platformArgument,
        '--no-interaction',
    ], $root, 1_800);
    $otaReceipt = molanxinThreeSourceReceipt(
        (string)$otaProcess['stdout'],
        (string)$otaProcess['stderr']
    );
    if ((int)$otaProcess['exit_code'] !== 0) {
        $otaFailureReason = 'auto_fetch_exit_nonzero';
    } elseif (!is_array($otaReceipt)) {
        $otaFailureReason = 'auto_fetch_receipt_missing';
    }
} catch (Throwable) {
    $otaFailureReason = 'auto_fetch_process_failed';
} finally {
    if ($previousControlTokenFile === false) {
        putenv('SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE');
    } else {
        putenv(
            'SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE='
            . $previousControlTokenFile
        );
    }
}

$otaReady = is_array($otaReceipt)
    && ($otaProcess['exit_code'] ?? 1) === 0
    && molanxinThreeSourceReceiptReady(
        $otaReceipt,
        $hotelId,
        $targetDate,
        $otaSourceIds
    );
if (!$otaReady && $otaFailureReason === '') {
    $otaFailureReason = 'auto_fetch_receipt_unverified';
}

// PMS collection is deliberately attempted even when either OTA collection
// fails. A verified PMS snapshot can still produce the base operating brief;
// the combined run remains partial until both OTA receipts are ready.
$pmsProcess = null;
$pmsOutput = null;
$pmsFailureReason = '';
try {
    $pmsProcess = molanxinThreeSourceRunProcess([
        $phpBinary,
        $root . '/scripts/run_molanxin_collection_preview.php',
        '--hotel-id=' . MOLANXIN_THREE_SOURCE_HOTEL_ID,
        '--owner-user-id=' . $ownerUserId,
        '--profile-id=' . $pmsProfileId,
        '--target-date=' . $targetDate,
        '--control-token-file=' . $tokenFile,
        '--runtime-directory=' . $runtimeDirectory,
        '--php-binary=' . $phpBinary,
        '--node-binary=' . $nodeBinary,
    ], $root, 600);
    $pmsOutput = molanxinThreeSourceJsonOutput((string)$pmsProcess['stdout']);
    if (!is_array($pmsOutput)) {
        $pmsFailureReason = 'pms_preview_output_invalid';
    } elseif ((int)$pmsProcess['exit_code'] !== 0) {
        $pmsFailureReason = 'pms_preview_exit_nonzero';
    }
} catch (Throwable) {
    $pmsFailureReason = 'pms_preview_process_failed';
}

$sourceStatuses = is_array($pmsOutput['source_statuses'] ?? null)
    ? $pmsOutput['source_statuses']
    : [];
$sourceReadiness = is_array($pmsOutput['source_readiness'] ?? null)
    ? $pmsOutput['source_readiness']
    : [];
$pmsBaseReady = is_array($pmsOutput)
    && ($pmsProcess['exit_code'] ?? 1) === 0
    && (int)($pmsOutput['hotel_id'] ?? 0) === MOLANXIN_THREE_SOURCE_HOTEL_ID
    && (string)($pmsOutput['business_date'] ?? '') === $targetDate
    && (int)($pmsOutput['run_id'] ?? 0) > 0
    && (int)($pmsOutput['capture_id'] ?? 0) > 0
    && (string)($pmsOutput['run_readback_status'] ?? '') === 'completed'
    && ($pmsOutput['preview_only'] ?? null) === true
    && ($pmsOutput['message_sent'] ?? null) === false
    && ($pmsOutput['source_gate_passed'] ?? null) === true
    && (string)($sourceStatuses['pms'] ?? '') === 'ready'
    && trim((string)($pmsOutput['message_preview'] ?? '')) !== '';
$digestThreeSourcesReady = ($sourceReadiness['pms'] ?? false) === true
    && ($sourceReadiness['ctrip'] ?? false) === true
    && ($sourceReadiness['meituan'] ?? false) === true;
$strictReady = $pmsBaseReady && $otaReady && $digestThreeSourcesReady;
$overallStatus = $strictReady
    ? 'strict_ready'
    : ($pmsBaseReady ? 'partial' : 'blocked');

$output = [
    'contract_version' => 'suxios.molanxin_three_source_collection.v1',
    'status' => $overallStatus,
    'strict_ready' => $strictReady,
    'hotel_id' => MOLANXIN_THREE_SOURCE_HOTEL_ID,
    'business_date' => $targetDate,
    'sequence' => ['ota_realtime_collection', 'pms_collection_and_preview'],
    'ota' => [
        'status' => $otaReady
            ? 'ready'
            : (is_array($otaReceipt) ? 'partial' : 'failed'),
        'exit_code' => is_array($otaProcess)
            ? (int)$otaProcess['exit_code']
            : null,
        'reason_code' => $otaReady ? '' : $otaFailureReason,
        'execution_diagnostics' => molanxinThreeSourceProcessDiagnostics(
            $otaProcess
        ),
        'source_ids' => $otaSourceIds,
        'platforms' => MOLANXIN_THREE_SOURCE_PLATFORMS,
        'receipt' => molanxinThreeSourceReceiptSummary($otaReceipt),
    ],
    'pms' => [
        'status' => $pmsBaseReady ? 'ready' : 'blocked',
        'exit_code' => is_array($pmsProcess)
            ? (int)$pmsProcess['exit_code']
            : null,
        'reason_code' => $pmsBaseReady ? '' : $pmsFailureReason,
        'run_id' => (int)($pmsOutput['run_id'] ?? 0),
        'run_readback_status' => (string)(
            $pmsOutput['run_readback_status'] ?? ''
        ),
        'capture_id' => (int)($pmsOutput['capture_id'] ?? 0),
    ],
    'source_statuses' => [
        'pms' => (string)($sourceStatuses['pms'] ?? 'missing'),
        'ctrip' => (string)($sourceStatuses['ctrip'] ?? 'missing'),
        'meituan' => (string)($sourceStatuses['meituan'] ?? 'missing'),
    ],
    'source_readiness' => [
        'pms' => ($sourceReadiness['pms'] ?? false) === true,
        'ctrip' => ($sourceReadiness['ctrip'] ?? false) === true,
        'meituan' => ($sourceReadiness['meituan'] ?? false) === true,
    ],
    'source_lineage' => is_array($pmsOutput['source_lineage'] ?? null)
        ? $pmsOutput['source_lineage']
        : [],
    'digest_status' => (string)($pmsOutput['digest_status'] ?? 'blocked'),
    'preview_status' => (string)($pmsOutput['preview_status'] ?? 'blocked'),
    'preview_fingerprint' => (string)(
        $pmsOutput['preview_fingerprint'] ?? ''
    ),
    'message_preview' => (string)($pmsOutput['message_preview'] ?? ''),
    'dispatch_requested' => false,
    'preview_only' => true,
    'message_sent' => false,
    'webhook_read' => false,
    'sensitive_values_exposed' => false,
];

echo json_encode(
    $output,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
exit($strictReady ? 0 : ($pmsBaseReady ? 2 : 1));

/**
 * @param array<int,string> $command
 * @return array{exit_code:int,stdout:string,stderr:string}
 */
function molanxinThreeSourceRunProcess(
    array $command,
    string $workingDirectory,
    int $timeoutSeconds
): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('child_process_start_failed');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $lastStatus = ['exitcode' => -1, 'running' => true];

    while (true) {
        $read = [];
        if (!feof($pipes[1])) {
            $read[] = $pipes[1];
        }
        if (!feof($pipes[2])) {
            $read[] = $pipes[2];
        }
        if ($read !== []) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200_000);
            foreach ($read as $stream) {
                $chunk = fread($stream, 65_536);
                if (!is_string($chunk) || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    molanxinThreeSourceAppendOutput($stdout, $chunk);
                } else {
                    molanxinThreeSourceAppendOutput($stderr, $chunk);
                }
            }
        }
        $lastStatus = proc_get_status($process);
        if (($lastStatus['running'] ?? false) !== true) {
            molanxinThreeSourceAppendOutput(
                $stdout,
                (string)stream_get_contents($pipes[1])
            );
            molanxinThreeSourceAppendOutput(
                $stderr,
                (string)stream_get_contents($pipes[2])
            );
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('owned_child_process_timeout');
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = (int)($lastStatus['exitcode'] ?? -1);
    if ($exitCode < 0) {
        $exitCode = $closeCode;
    }

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function molanxinThreeSourceAppendOutput(string &$buffer, string $chunk): void
{
    $buffer .= $chunk;
    if (strlen($buffer) > MOLANXIN_THREE_SOURCE_MAX_OUTPUT_BYTES) {
        $buffer = substr($buffer, -MOLANXIN_THREE_SOURCE_MAX_OUTPUT_BYTES);
    }
}

/** @return array<string,mixed>|null */
function molanxinThreeSourceReceipt(string $stdout, string $stderr): ?array
{
    $lines = preg_split('/\R/u', $stdout . "\n" . $stderr) ?: [];
    for ($index = count($lines) - 1; $index >= 0; --$index) {
        $line = trim((string)$lines[$index]);
        if (!str_starts_with($line, MOLANXIN_THREE_SOURCE_RECEIPT_PREFIX)) {
            continue;
        }
        $decoded = json_decode(
            substr($line, strlen(MOLANXIN_THREE_SOURCE_RECEIPT_PREFIX)),
            true
        );
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/** @return array<string,mixed>|null */
function molanxinThreeSourceJsonOutput(string $stdout): ?array
{
    $decoded = json_decode(trim($stdout), true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $lines = preg_split('/\R/u', $stdout) ?: [];
    for ($index = count($lines) - 1; $index >= 0; --$index) {
        $decoded = json_decode(trim((string)$lines[$index]), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $receipt
 * @param array<int,int> $sourceIds
 */
function molanxinThreeSourceReceiptReady(
    array $receipt,
    int $hotelId,
    string $targetDate,
    array $sourceIds
): bool {
    $receiptSources = array_values(array_unique(array_filter(array_map(
        'intval',
        is_array($receipt['source_ids'] ?? null) ? $receipt['source_ids'] : []
    ), static fn(int $id): bool => $id > 0)));
    sort($receiptSources, SORT_NUMERIC);
    $receiptPlatforms = array_values(array_unique(array_map(
        static fn($value): string => strtolower(trim((string)$value)),
        is_array($receipt['required_platforms'] ?? null)
            ? $receipt['required_platforms']
            : []
    )));
    sort($receiptPlatforms, SORT_STRING);
    $expectedPlatforms = MOLANXIN_THREE_SOURCE_PLATFORMS;
    sort($expectedPlatforms, SORT_STRING);
    if ((int)($receipt['schema_version'] ?? 0) < 3
        || (int)($receipt['hotel_id'] ?? 0) !== $hotelId
        || (string)($receipt['target_date'] ?? '') !== $targetDate
        || (string)($receipt['data_period'] ?? '') !== 'realtime_snapshot'
        || $receiptSources !== $sourceIds
        || $receiptPlatforms !== $expectedPlatforms
        || ($receipt['collection_complete'] ?? null) !== true
        || ($receipt['exportable_snapshot_complete'] ?? null) !== true
        || ($receipt['dual_ota_p0_complete'] ?? null) !== true
    ) {
        return false;
    }

    $readyPlatforms = [];
    $taskSourceIds = [];
    foreach (is_array($receipt['source_tasks'] ?? null)
        ? $receipt['source_tasks']
        : [] as $task
    ) {
        if (!is_array($task)
            || (int)($task['data_source_id'] ?? 0) <= 0
            || (int)($task['sync_task_id'] ?? 0) <= 0
            || (string)($task['collection_status'] ?? '') !== 'success'
            || !in_array(
                (string)($task['p0_status'] ?? ''),
                ['ready', 'not_required'],
                true
            )
            || array_values(array_filter(
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : [],
                static fn($value): bool => (int)$value > 0
            )) === []
        ) {
            continue;
        }
        $platform = strtolower(trim((string)($task['platform'] ?? '')));
        if (in_array($platform, MOLANXIN_THREE_SOURCE_PLATFORMS, true)) {
            $readyPlatforms[$platform] = true;
            $taskSourceIds[] = (int)$task['data_source_id'];
        }
    }
    $readyPlatformNames = array_keys($readyPlatforms);
    sort($readyPlatformNames, SORT_STRING);
    $taskSourceIds = array_values(array_unique($taskSourceIds));
    sort($taskSourceIds, SORT_NUMERIC);

    return $readyPlatformNames === $expectedPlatforms
        && $taskSourceIds === $sourceIds;
}

/** @return array<string,mixed>|null */
function molanxinThreeSourceReceiptSummary(?array $receipt): ?array
{
    if (!is_array($receipt)) {
        return null;
    }
    $tasks = [];
    foreach (is_array($receipt['source_tasks'] ?? null)
        ? $receipt['source_tasks']
        : [] as $task
    ) {
        if (!is_array($task)) {
            continue;
        }
        $tasks[] = [
            'data_source_id' => (int)($task['data_source_id'] ?? 0),
            'sync_task_id' => (int)($task['sync_task_id'] ?? 0),
            'platform' => (string)($task['platform'] ?? ''),
            'collection_status' => (string)($task['collection_status'] ?? ''),
            'p0_status' => (string)($task['p0_status'] ?? ''),
            'row_ids' => array_values(array_map(
                'intval',
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : []
            )),
        ];
    }

    return [
        'schema_version' => (int)($receipt['schema_version'] ?? 0),
        'hotel_id' => (int)($receipt['hotel_id'] ?? 0),
        'target_date' => (string)($receipt['target_date'] ?? ''),
        'data_period' => (string)($receipt['data_period'] ?? ''),
        'source_ids' => array_values(array_map(
            'intval',
            is_array($receipt['source_ids'] ?? null)
                ? $receipt['source_ids']
                : []
        )),
        'required_platforms' => array_values(array_map(
            'strval',
            is_array($receipt['required_platforms'] ?? null)
                ? $receipt['required_platforms']
                : []
        )),
        'status' => (string)($receipt['status'] ?? ''),
        'collection_complete' => ($receipt['collection_complete'] ?? false) === true,
        'exportable_snapshot_complete' =>
            ($receipt['exportable_snapshot_complete'] ?? false) === true,
        'dual_ota_p0_complete' =>
            ($receipt['dual_ota_p0_complete'] ?? false) === true,
        'source_tasks' => $tasks,
    ];
}

/**
 * Return only fixed diagnostic markers and an output digest. Child output can
 * contain platform facts, so it must never be echoed into the service journal.
 *
 * @param array{exit_code:int,stdout:string,stderr:string}|null $process
 * @return array{
 *   output_sha256:string,
 *   markers:array<int,string>,
 *   exception_types:array<int,string>,
 *   reason_codes:array<int,string>,
 *   php_locations:array<int,string>,
 *   path_scopes:array<int,string>
 * }
 */
function molanxinThreeSourceProcessDiagnostics(?array $process): array
{
    if (!is_array($process)) {
        return [
            'output_sha256' => '',
            'markers' => ['process_unavailable'],
            'exception_types' => [],
            'reason_codes' => [],
            'php_locations' => [],
            'path_scopes' => [],
        ];
    }
    $output = (string)($process['stdout'] ?? '')
        . "\n"
        . (string)($process['stderr'] ?? '');
    $markers = [];
    $patterns = [
        'schedule_started' => 'Start online data auto-fetch schedule check.',
        'schedule_finished' => 'Online data auto-fetch schedule check finished.',
        'retry_cooldown' => 'retry cooldown, skipped.',
        'retry_exhausted' => 'retry exhausted, skipped.',
        'profile_lock_active' => 'skipped_locked:',
        'cloud_scope_blocked' => 'Cloud OTA collector blocked:',
        'hotel_scope_missing' => 'hotel-id was not found or is disabled.',
        'receipt_emitted' => MOLANXIN_THREE_SOURCE_RECEIPT_PREFIX,
        'runtime_exception' => 'Uncaught ',
        'php_fatal_error' => 'PHP Fatal error:',
        'permission_denied' => 'Permission denied',
        'open_stream_failed' => 'Failed to open stream',
        'undefined_value' => 'Undefined ',
        'mkdir_warning' => 'mkdir(',
        'file_write_warning' => 'file_put_contents(',
        'file_read_warning' => 'file_get_contents(',
    ];
    foreach ($patterns as $marker => $needle) {
        if (str_contains($output, $needle)) {
            $markers[] = $marker;
        }
    }
    if ($markers === []) {
        $markers[] = trim($output) === ''
            ? 'no_child_output'
            : 'unclassified_child_output';
    }
    preg_match_all(
        '/\b(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*'
            . '[A-Za-z_][A-Za-z0-9_]*(?:Exception|Error)\b/',
        $output,
        $exceptionMatches
    );
    $exceptionTypes = array_values(array_unique(array_slice(
        array_map('strval', $exceptionMatches[0] ?? []),
        0,
        10
    )));
    preg_match_all(
        '/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)*_'
            . '(?:failed|blocked|invalid|missing|unverified|expired|required)\b/',
        strtolower($output),
        $reasonMatches
    );
    $reasonCodes = array_values(array_unique(array_slice(
        array_map('strval', $reasonMatches[0] ?? []),
        0,
        20
    )));
    preg_match_all(
        '/([A-Za-z0-9_.-]+\.php)(?:[:(]|\s+on\s+line\s+)(\d+)/i',
        $output,
        $locationMatches,
        PREG_SET_ORDER
    );
    $locations = [];
    foreach (array_slice($locationMatches, 0, 30) as $match) {
        $locations[] = basename((string)($match[1] ?? ''))
            . ':'
            . (string)($match[2] ?? '');
    }
    $locations = array_values(array_unique(array_filter(
        $locations,
        static fn(string $value): bool => $value !== ':'
    )));
    preg_match_all(
        '/(?:file_put_contents|file_get_contents|fopen|mkdir)\(([^),\r\n]+)/i',
        $output,
        $pathMatches
    );
    $pathScopes = [];
    foreach (array_slice($pathMatches[1] ?? [], 0, 20) as $matchedPath) {
        $path = trim((string)$matchedPath, " \t\n\r\0\x0B'\"");
        $pathScopes[] = match (true) {
            str_starts_with($path, '/var/lib/suxios/app-cache/') =>
                'app_cache',
            str_starts_with($path, '/var/lib/suxios/app-locks/') =>
                'app_locks',
            str_contains($path, '/runtime/') => 'release_runtime',
            str_starts_with(
                $path,
                '/run/suxios-molanxin-three-source-collection/'
            ) => 'service_runtime',
            str_starts_with($path, '/tmp/') => 'private_tmp',
            default => 'other:' . substr(hash('sha256', $path), 0, 12),
        };
    }
    $pathScopes = array_values(array_unique($pathScopes));

    return [
        'output_sha256' => hash('sha256', $output),
        'markers' => $markers,
        'exception_types' => $exceptionTypes,
        'reason_codes' => $reasonCodes,
        'php_locations' => array_slice($locations, 0, 20),
        'path_scopes' => $pathScopes,
    ];
}

/** @return array<int,int> */
function molanxinThreeSourceIds(string $value): array
{
    $ids = [];
    foreach (explode(',', $value) as $candidate) {
        $validated = filter_var(trim($candidate), FILTER_VALIDATE_INT);
        if (!is_int($validated) || $validated <= 0) {
            molanxinThreeSourceFail('ota_source_ids_invalid', 2);
        }
        $ids[] = $validated;
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    if (count($ids) !== count(MOLANXIN_THREE_SOURCE_PLATFORMS)) {
        molanxinThreeSourceFail('ota_source_ids_must_contain_two_sources', 2);
    }

    return $ids;
}

function molanxinThreeSourcePositiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        molanxinThreeSourceFail($reason, 2);
    }

    return $validated;
}

function molanxinThreeSourceValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $value;
}

function molanxinThreeSourceFail(string $reason, int $exitCode): never
{
    fwrite(STDERR, json_encode([
        'contract_version' => 'suxios.molanxin_three_source_collection.v1',
        'status' => 'blocked',
        'reason_code' => $reason,
        'hotel_id' => MOLANXIN_THREE_SOURCE_HOTEL_ID,
        'strict_ready' => false,
        'dispatch_requested' => false,
        'preview_only' => true,
        'message_sent' => false,
        'webhook_read' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
