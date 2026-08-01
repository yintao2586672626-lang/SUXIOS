#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\model\User;
use app\service\CollectionResultContractService;
use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoPmsIntegrationService;
use app\service\PermissionService;
use think\App;
use think\facade\Db;

const DINGDANDAO_LOCAL_MAX_COLLECTOR_OUTPUT_BYTES = 2_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'target-date::',
    'cdp-url::',
    'node-binary::',
    'sandbox-id::',
    'collection-mode::',
    'require-sandbox',
    'push',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
$hotelId = localPositiveInt(
    $options['hotel-id'] ?? null,
    'dingdandao_local_hotel_id_invalid'
);
$ownerUserId = localPositiveInt(
    $options['owner-user-id'] ?? null,
    'dingdandao_local_owner_user_id_invalid'
);
$targetDate = trim((string)($options['target-date'] ?? $today));
$cdpUrl = rtrim(
    trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')),
    '/'
);
$nodeBinary = resolveLocalNodeBinary(
    isset($options['node-binary']) ? (string)$options['node-binary'] : null
);
$sandboxId = trim((string)($options['sandbox-id'] ?? ''));
$collectionMode = localCollectionMode(
    (string)($options['collection-mode']
        ?? ($targetDate < $today ? 'operating_indicators' : 'full_diagnostic'))
);
$requireSandbox = array_key_exists('require-sandbox', $options);
$pushRequested = array_key_exists('push', $options);
$historicalCollection = localValidDate($targetDate) && $targetDate < $today;
$expectedSourceScope = $historicalCollection
    ? DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE
    : DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE;

if (!localValidDate($targetDate) || !localValidCdpUrl($cdpUrl)
    || ($sandboxId !== '' && !localValidSandboxId($sandboxId))
    || ($requireSandbox && $sandboxId === '')
) {
    localFail('dingdandao_local_collection_arguments_invalid', 2);
}
if ($targetDate > $today) {
    localFail('dingdandao_local_target_date_in_future', 2);
}
if ($historicalCollection && $collectionMode !== 'operating_indicators') {
    localFail('dingdandao_local_historical_collection_mode_invalid', 2);
}
if ($historicalCollection && $pushRequested) {
    localFail('dingdandao_local_historical_direct_push_not_allowed', 2);
}

$hotel = Db::name('hotels')
    ->field('id,tenant_id,name,status')
    ->where('id', $hotelId)
    ->find();
if (!is_array($hotel)
    || (int)($hotel['tenant_id'] ?? 0) <= 0
    || (int)($hotel['status'] ?? 0) !== 1
    || trim((string)($hotel['name'] ?? '')) === ''
) {
    localFail('dingdandao_local_hotel_scope_invalid');
}

$tenantId = (int)$hotel['tenant_id'];
$hotelName = trim((string)$hotel['name']);
$ownerUser = User::where('id', $ownerUserId)->find();
if (!$ownerUser instanceof User
    || (int)($ownerUser->tenant_id ?? 0) !== $tenantId
    || (int)($ownerUser->status ?? 0) !== User::STATUS_ENABLED
) {
    localFail('dingdandao_local_owner_scope_invalid');
}
$authorization = (new PermissionService())->authorize(
    $ownerUser,
    'ota.collect',
    $hotelId
);
if (($authorization['allowed'] ?? false) !== true) {
    localFail('dingdandao_local_owner_permission_denied');
}
$integrationService = new DingdandaoPmsIntegrationService();
$captureExpectation = $integrationService->captureExpectation(
    $tenantId,
    $hotelId,
    $hotelName
);
if (($captureExpectation['configured'] ?? false) !== true) {
    localFail('dingdandao_local_provider_binding_missing');
}
$expectedProviderHotelName = trim(
    (string)($captureExpectation['expected_provider_hotel_name'] ?? '')
);
$expectedProviderHotelId = trim(
    (string)($captureExpectation['expected_provider_hotel_id']
        ?? latestLocalProviderHotelId($tenantId, $hotelId)
        ?? '')
);
if ($expectedProviderHotelName === '' || $expectedProviderHotelId === '') {
    localFail('dingdandao_local_provider_identity_incomplete');
}

$lockDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'suxios-dingdandao-local';
if (!is_dir($lockDirectory)
    && !mkdir($lockDirectory, 0700, true)
    && !is_dir($lockDirectory)
) {
    localFail('dingdandao_local_lock_directory_unavailable');
}
$lockPath = $lockDirectory . DIRECTORY_SEPARATOR . 'hotel-' . $hotelId . '.lock';
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    localFail('dingdandao_local_collection_already_running');
}

$businessDataPersisted = false;
$persistedCapture = null;
$failureStage = 'collector';
$result = null;
$mainError = null;
try {
    $collector = runLocalDingdandaoCollector(
        $nodeBinary,
        $root . '/scripts/dingdandao_cloud_capture.mjs',
        $cdpUrl,
        $targetDate,
        $expectedProviderHotelName,
        $sandboxId,
        $collectionMode
    );
    if (($collector['status'] ?? '') !== 'captured_unverified'
        || ($collector['collection_mode'] ?? '') !== $collectionMode
        || !is_array($collector['capture'] ?? null)
        || (string)($collector['capture']['source_scope'] ?? '')
            !== $expectedSourceScope
        || ($collector['raw_response_exposed'] ?? null) !== false
        || ($collector['session_material_exposed'] ?? null) !== false
        || ($sandboxId !== ''
            && (
                ($collector['sandbox_id'] ?? null) !== $sandboxId
                || ($collector['sandbox_selection'] ?? '') !== 'explicit_marker'
            ))
    ) {
        throw new RuntimeException('dingdandao_local_collection_payload_invalid');
    }

    $failureStage = 'persistence';
    $capture = (new DingdandaoOperatingTargetCaptureService())->save(
        $tenantId,
        $hotelId,
        $ownerUserId,
        $expectedProviderHotelName,
        $collector['capture'],
        true,
        $expectedProviderHotelId
    );
    $businessDataPersisted = true;
    $persistedCapture = $capture;
    $failureStage = 'readback_validation';
    if (($capture['quality_status'] ?? '') !== 'verified'
        || ($capture['capture_status'] ?? '') !== 'verified'
        || ($capture['readback_status'] ?? '') !== 'readback_verified'
        || ($capture['identity_status'] ?? '') !== 'matched'
        || ($capture['reconciliation_status'] ?? '') !== 'matched'
        || (string)($capture['business_date'] ?? '') !== $targetDate
        || (string)($capture['source_scope'] ?? '') !== $expectedSourceScope
        || (int)($capture['hotel_id'] ?? 0) !== $hotelId
    ) {
        throw new RuntimeException('dingdandao_local_collection_readback_not_verified');
    }
    $collectionValidation =
        (new CollectionResultContractService())->validateDingdandaoCaptureClaim(
            $capture,
            [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $targetDate,
                'provider_hotel_id' => $expectedProviderHotelId,
                'source_scope' => $expectedSourceScope,
            ]
        );
    if (($collectionValidation['allowed'] ?? false) !== true) {
        throw new RuntimeException(
            'dingdandao_local_collection_contract_not_verified'
        );
    }

    $failureStage = 'prefill';
    $prefill = $integrationService->prefill(
        $tenantId,
        $hotelId,
        $ownerUserId,
        $targetDate
    );
    if (($prefill['status'] ?? '') !== 'verified'
        || (int)($prefill['capture']['id'] ?? 0) !== (int)$capture['id']
    ) {
        throw new RuntimeException('dingdandao_local_collection_prefill_readback_failed');
    }

    $failureStage = 'operating_target_sync';
    $targetSync = $integrationService->syncVerifiedCapture(
        $tenantId,
        $hotelId,
        $ownerUserId,
        (int)$capture['id']
    );
    if (($targetSync['sync_status'] ?? '') === 'blocked') {
        throw new RuntimeException('dingdandao_local_collection_target_sync_blocked');
    }

    $failureStage = 'push';
    $push = [
        'delivery_status' => 'not_requested',
        'delivery_attempted' => false,
        'error_summary' => null,
    ];
    if ($pushRequested) {
        try {
            $push = $integrationService->dispatchVerifiedCapture(
                $tenantId,
                $hotelId,
                $ownerUserId,
                $hotelName,
                $capture,
                'manual'
            );
        } catch (Throwable $pushError) {
            $push = [
                'delivery_status' => 'orchestration_failed',
                'delivery_attempted' => false,
                'error_summary' => localSafeReason($pushError->getMessage()),
            ];
        }
    }
    $messageSent = in_array(
        (string)($push['delivery_status'] ?? ''),
        ['sent', 'already_sent'],
        true
    ) && (string)($push['result_code'] ?? '') === 'wecom_business_success';

    $result = [
        'status' => 'saved_and_readback_verified',
        'collection_success' => true,
        'business_data_persisted' => true,
        'execution_mode' => 'local_cdp',
        'collection_mode' => $collectionMode,
        'sandbox_id' => $sandboxId !== '' ? $sandboxId : null,
        'sandbox_selection' => $sandboxId !== ''
            ? 'explicit_marker'
            : 'legacy_cookie_scan',
        'sandbox_isolated' => $sandboxId !== '',
        'hotel_id' => $hotelId,
        'target_date' => $targetDate,
        'capture_id' => (int)$capture['id'],
        'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
        'provider_hotel_name' => (string)($capture['provider_hotel_name'] ?? ''),
        'identity_status' => (string)$capture['identity_status'],
        'reconciliation_status' => (string)$capture['reconciliation_status'],
        'quality_status' => (string)$capture['quality_status'],
        'readback_status' => (string)$capture['readback_status'],
        'collection_contract_status' => 'verified',
        'source_scope' => (string)($capture['source_scope'] ?? ''),
        'summary' => (array)($capture['summary'] ?? []),
        'room_fee_summary_row_count' => count(
            (array)($capture['room_fee_summary_rows'] ?? [])
        ),
        'detail_row_count' => (int)($capture['detail_row_count'] ?? 0),
        'trend_point_counts' => localTrendPointCounts(
            (array)($capture['trend'] ?? [])
        ),
        'regional_benchmark' => localRegionalSummary(
            (array)($capture['county_context'] ?? [])
        ),
        'forward_room_status' => localForwardRoomStatusSummary(
            (array)($capture['forward_room_status'] ?? [])
        ),
        'component_coverage' => is_array($capture['component_coverage'] ?? null)
            ? $capture['component_coverage']
            : [],
        'operating_target_sync' => [
            'status' => (string)($targetSync['status'] ?? 'partial'),
            'sync_status' => (string)($targetSync['sync_status'] ?? 'unknown'),
            'record_id' => (int)($targetSync['record_id'] ?? 0),
            'revision_no' => (int)($targetSync['revision_no'] ?? 0),
            'send_eligible' => ($targetSync['send_eligible'] ?? false) === true,
        ],
        'push' => [
            'requested' => $pushRequested,
            'delivery_status' => (string)($push['delivery_status'] ?? 'blocked'),
            'delivery_attempted' => ($push['delivery_attempted'] ?? false) === true,
            'dispatch_id' => (int)($push['id'] ?? 0),
            'robot_id' => (int)($push['robot_id'] ?? 0),
            'message_sent' => $messageSent,
            'delivery_satisfied' => !$pushRequested || $messageSent,
            'result_code' => $push['result_code'] ?? null,
            'response_reference' => $push['response_reference'] ?? null,
            'payload_bytes' => isset($push['payload_bytes'])
                ? (int)$push['payload_bytes']
                : null,
            'error_summary' => isset($push['error_summary'])
                ? localSafeReason((string)$push['error_summary'])
                : null,
            'blocker_codes' => localBlockerCodes((array)($push['blockers'] ?? [])),
        ],
        'raw_response_exposed' => false,
        'session_material_exposed' => false,
        'sensitive_values_exposed' => false,
    ];
} catch (Throwable $error) {
    $mainError = localSafeReason($error->getMessage());
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($mainError !== null || !is_array($result)) {
    localFail(
        $mainError ?? 'dingdandao_local_collection_failed',
        1,
        $businessDataPersisted,
        [
            'failure_stage' => $failureStage,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'collection_mode' => $collectionMode,
            'sandbox_id' => $sandboxId !== '' ? $sandboxId : null,
            'capture_id' => (int)($persistedCapture['id'] ?? 0),
            'identity_status' => $persistedCapture['identity_status'] ?? null,
            'reconciliation_status' =>
                $persistedCapture['reconciliation_status'] ?? null,
            'quality_status' => $persistedCapture['quality_status'] ?? null,
            'readback_status' => $persistedCapture['readback_status'] ?? null,
        ]
    );
}

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

/** @return array<string,mixed> */
function runLocalDingdandaoCollector(
    string $nodeBinary,
    string $script,
    string $cdpUrl,
    string $targetDate,
    string $hotelName,
    string $sandboxId = '',
    string $collectionMode = 'full_diagnostic'
): array {
    $command = [
        $nodeBinary,
        $script,
        '--cdp-url=' . $cdpUrl,
        '--target-date=' . $targetDate,
        '--expected-hotel-name=' . $hotelName,
        '--collection-mode=' . $collectionMode,
        '--timeout-ms=15000',
    ];
    if ($sandboxId !== '') {
        $command[] = '--sandbox-id=' . $sandboxId;
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname($script),
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('dingdandao_local_collector_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents(
        $pipes[1],
        DINGDANDAO_LOCAL_MAX_COLLECTOR_OUTPUT_BYTES + 1
    );
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!is_string($stdout)
        || strlen($stdout) > DINGDANDAO_LOCAL_MAX_COLLECTOR_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $error = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        $reason = is_array($error) ? (string)($error['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'dingdandao_local_collector_failed'
        );
    }
    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('dingdandao_local_collector_output_invalid');
    }
    return $decoded;
}

function latestLocalProviderHotelId(int $tenantId, int $hotelId): ?string
{
    $value = Db::name('dingdandao_operating_target_captures')
        ->where('tenant_id', $tenantId)
        ->where('hotel_id', $hotelId)
        ->where('provider', DingdandaoOperatingTargetCaptureService::PROVIDER)
        ->where('identity_status', 'matched')
        ->where('quality_status', 'verified')
        ->where('readback_status', 'readback_verified')
        ->order('id', 'desc')
        ->value('provider_hotel_id');
    $value = trim((string)($value ?? ''));
    return $value !== '' ? $value : null;
}

function resolveLocalNodeBinary(?string $requested): string
{
    $candidates = [];
    if (is_string($requested) && trim($requested) !== '') {
        $candidates[] = trim($requested);
    }
    $configured = trim((string)(getenv('SUXI_NODE') ?: ''));
    if ($configured !== '') {
        $candidates[] = $configured;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'C:\\Program Files\\nodejs\\node.exe';
        $candidates[] = 'C:\\Program Files (x86)\\nodejs\\node.exe';
    } else {
        $candidates[] = '/usr/bin/node';
        $candidates[] = '/usr/local/bin/node';
    }
    foreach (array_values(array_unique($candidates)) as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            continue;
        }
        if (!in_array(strtolower(basename($resolved)), ['node', 'node.exe'], true)) {
            continue;
        }
        return $resolved;
    }
    localFail('dingdandao_local_node_binary_unavailable', 2);
}

function localValidCdpUrl(string $value): bool
{
    if (preg_match(
        '#^http://127\.0\.0\.1:([1-9][0-9]{1,4})$#D',
        $value,
        $matches
    ) !== 1) {
        return false;
    }
    $port = (int)$matches[1];
    return $port > 0 && $port <= 65535;
}

function localValidSandboxId(string $value): bool
{
    return preg_match('/^sbx_[A-Za-z0-9_-]{8,64}$/D', $value) === 1;
}

function localCollectionMode(string $value): string
{
    $normalized = strtolower(trim($value));
    if (!in_array(
        $normalized,
        ['operating_indicators', 'full_diagnostic'],
        true
    )) {
        localFail('dingdandao_local_collection_mode_invalid', 2);
    }
    return $normalized;
}

function localPositiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        localFail($reason, 2);
    }
    return $validated;
}

function localValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable
        && $date->format('Y-m-d') === $value;
}

/** @param array<int,mixed> $blockers @return array<int,string> */
function localBlockerCodes(array $blockers): array
{
    $codes = [];
    foreach ($blockers as $blocker) {
        if (!is_array($blocker)) {
            continue;
        }
        $code = localSafeReason((string)($blocker['code'] ?? ''));
        if ($code !== '' && $code !== 'dingdandao_local_collection_failed') {
            $codes[] = $code;
        }
    }
    return array_values(array_unique($codes));
}

/** @param array<string,mixed> $trend @return array<string,int> */
function localTrendPointCounts(array $trend): array
{
    $result = [];
    foreach ([
        'total_room_fee',
        'adr',
        'occupancy_rate_percent',
        'revpar',
        'sold_room_nights',
    ] as $metric) {
        $result[$metric] = is_array($trend[$metric] ?? null)
            ? count($trend[$metric])
            : 0;
    }
    return $result;
}

/** @param array<string,mixed> $county @return array<string,mixed> */
function localRegionalSummary(array $county): array
{
    return [
        'fact_scope' => (string)($county['fact_scope'] ?? ''),
        'data_status' => (string)($county['data_status'] ?? 'partial'),
        'region_name' => $county['region_name'] ?? null,
        'summary' => (array)($county['summary'] ?? []),
        'trend_point_counts' => localTrendPointCounts(
            (array)($county['trend'] ?? [])
        ),
    ];
}

/** @param array<string,mixed> $forward @return array<string,mixed> */
function localForwardRoomStatusSummary(array $forward): array
{
    $horizons = [];
    foreach ((array)($forward['horizons'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $horizons[] = [
            'horizon_days' => (int)($row['horizon_days'] ?? 0),
            'date_from' => $row['date_from'] ?? null,
            'date_to' => $row['date_to'] ?? null,
            'covered_days' => (int)($row['covered_days'] ?? 0),
            'expected_days' => (int)($row['expected_days'] ?? 0),
            'booked_room_nights' => $row['booked_room_nights'] ?? null,
            'remaining_sellable_room_nights' =>
                $row['remaining_sellable_room_nights'] ?? null,
            'oversold_room_nights' => $row['oversold_room_nights'] ?? null,
            'occupancy_rate_percent' => $row['occupancy_rate_percent'] ?? null,
            'adr' => $row['adr'] ?? null,
            'revpar' => $row['revpar'] ?? null,
            'quality_status' => (string)($row['quality_status'] ?? 'partial'),
            'gap_codes' => (array)($row['gap_codes'] ?? []),
        ];
    }
    return [
        'fact_scope' => (string)(
            $forward['fact_scope'] ?? 'whole_hotel_forward_room_status'
        ),
        'data_status' => (string)($forward['data_status'] ?? 'partial'),
        'readback_status' => (string)($forward['readback_status'] ?? 'not_verified'),
        'as_of_date' => $forward['as_of_date'] ?? null,
        'range_start_date' => $forward['range_start_date'] ?? null,
        'range_end_date' => $forward['range_end_date'] ?? null,
        'requested_range_end_date' => $forward['requested_range_end_date'] ?? null,
        'source_day_count' => (int)($forward['source_day_count'] ?? 0),
        'display_day_count' => (int)($forward['display_day_count'] ?? 0),
        'source_coverage_status' => (string)(
            $forward['source_coverage_status'] ?? 'missing'
        ),
        'source_gap_codes' => (array)($forward['source_gap_codes'] ?? []),
        'source_room_type_count' => (int)($forward['source_room_type_count'] ?? 0),
        'total_room_count' => $forward['total_room_count'] ?? null,
        'display_horizons' => (array)($forward['display_horizons'] ?? []),
        'horizons' => $horizons,
        'gap_codes' => (array)($forward['gap_codes'] ?? []),
        'anomaly_count' => count((array)($forward['anomalies'] ?? [])),
        'anomalies' => (array)($forward['anomalies'] ?? []),
    ];
}

function localSafeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'dingdandao_local_collection_failed';
}

function localFail(
    string $reason,
    int $exitCode = 1,
    bool $businessDataPersisted = false,
    array $context = []
): never {
    $readbackVerified = $businessDataPersisted
        && (string)($context['readback_status'] ?? '') === 'readback_verified'
        && (int)($context['capture_id'] ?? 0) > 0;
    fwrite(STDERR, json_encode([
        'status' => $readbackVerified ? 'saved_downstream_blocked' : 'blocked',
        'reason' => localSafeReason($reason),
        'collection_success' => $readbackVerified,
        'business_data_persisted' => $businessDataPersisted,
        'failure_stage' => isset($context['failure_stage'])
            ? localSafeReason((string)$context['failure_stage'])
            : null,
        'hotel_id' => (int)($context['hotel_id'] ?? 0),
        'target_date' => $context['target_date'] ?? null,
        'collection_mode' => $context['collection_mode'] ?? null,
        'sandbox_id' => $context['sandbox_id'] ?? null,
        'sandbox_selection' => trim((string)($context['sandbox_id'] ?? '')) !== ''
            ? 'explicit_marker'
            : null,
        'capture_id' => (int)($context['capture_id'] ?? 0),
        'identity_status' => $context['identity_status'] ?? null,
        'reconciliation_status' => $context['reconciliation_status'] ?? null,
        'quality_status' => $context['quality_status'] ?? null,
        'readback_status' => $context['readback_status'] ?? null,
        'message_sent' => false,
        'raw_response_exposed' => false,
        'session_material_exposed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
