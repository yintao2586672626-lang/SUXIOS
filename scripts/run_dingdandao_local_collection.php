#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoPmsIntegrationService;
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
$pushRequested = array_key_exists('push', $options);

if (!localValidDate($targetDate)
    || $targetDate !== $today
    || !localValidCdpUrl($cdpUrl)
) {
    localFail('dingdandao_local_collection_arguments_invalid', 2);
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
$result = null;
$mainError = null;
try {
    $collector = runLocalDingdandaoCollector(
        $nodeBinary,
        $root . '/scripts/dingdandao_cloud_capture.mjs',
        $cdpUrl,
        $targetDate,
        $expectedProviderHotelName
    );
    if (($collector['status'] ?? '') !== 'captured_unverified'
        || ($collector['collection_mode'] ?? '') !== 'full_diagnostic'
        || !is_array($collector['capture'] ?? null)
        || ($collector['raw_response_exposed'] ?? null) !== false
        || ($collector['session_material_exposed'] ?? null) !== false
    ) {
        throw new RuntimeException('dingdandao_local_collection_payload_invalid');
    }

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
    if (($capture['quality_status'] ?? '') !== 'verified'
        || ($capture['capture_status'] ?? '') !== 'verified'
        || ($capture['readback_status'] ?? '') !== 'readback_verified'
        || ($capture['identity_status'] ?? '') !== 'matched'
        || ($capture['reconciliation_status'] ?? '') !== 'matched'
        || (string)($capture['business_date'] ?? '') !== $targetDate
        || (int)($capture['hotel_id'] ?? 0) !== $hotelId
    ) {
        throw new RuntimeException('dingdandao_local_collection_readback_not_verified');
    }

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

    $targetSync = $integrationService->syncVerifiedCapture(
        $tenantId,
        $hotelId,
        $ownerUserId,
        (int)$capture['id']
    );
    if (($targetSync['sync_status'] ?? '') === 'blocked') {
        throw new RuntimeException('dingdandao_local_collection_target_sync_blocked');
    }

    $push = [
        'delivery_status' => 'not_requested',
        'delivery_attempted' => false,
    ];
    if ($pushRequested) {
        $push = $integrationService->dispatchVerifiedCapture(
            $tenantId,
            $hotelId,
            $ownerUserId,
            $hotelName,
            $capture,
            'manual'
        );
        if (!in_array(
            (string)($push['delivery_status'] ?? ''),
            ['sent', 'already_sent'],
            true
        )) {
            $blockerCodes = localBlockerCodes((array)($push['blockers'] ?? []));
            throw new RuntimeException(
                $blockerCodes !== []
                    ? 'dingdandao_local_push_blocked_' . implode('_', $blockerCodes)
                    : 'dingdandao_local_push_failed'
            );
        }
    }

    $result = [
        'status' => 'saved_and_readback_verified',
        'execution_mode' => 'local_cdp',
        'hotel_id' => $hotelId,
        'target_date' => $targetDate,
        'capture_id' => (int)$capture['id'],
        'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
        'provider_hotel_name' => (string)($capture['provider_hotel_name'] ?? ''),
        'identity_status' => (string)$capture['identity_status'],
        'reconciliation_status' => (string)$capture['reconciliation_status'],
        'quality_status' => (string)$capture['quality_status'],
        'readback_status' => (string)$capture['readback_status'],
        'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
        'summary' => (array)($capture['summary'] ?? []),
        'detail_row_count' => (int)($capture['detail_row_count'] ?? 0),
        'trend_point_counts' => localTrendPointCounts(
            (array)($capture['trend'] ?? [])
        ),
        'regional_benchmark' => localRegionalSummary(
            (array)($capture['county_context'] ?? [])
        ),
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
            'message_sent' => in_array(
                (string)($push['delivery_status'] ?? ''),
                ['sent', 'already_sent'],
                true
            ),
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
        $businessDataPersisted
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
    string $hotelName
): array {
    $command = [
        $nodeBinary,
        $script,
        '--cdp-url=' . $cdpUrl,
        '--target-date=' . $targetDate,
        '--expected-hotel-name=' . $hotelName,
        '--collection-mode=full_diagnostic',
        '--timeout-ms=15000',
    ];
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

function localSafeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'dingdandao_local_collection_failed';
}

function localFail(
    string $reason,
    int $exitCode = 1,
    bool $businessDataPersisted = false
): never {
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => localSafeReason($reason),
        'business_data_persisted' => $businessDataPersisted,
        'message_sent' => false,
        'raw_response_exposed' => false,
        'session_material_exposed' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
