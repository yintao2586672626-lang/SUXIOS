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

const DINGDANDAO_IAB_MAX_INPUT_BYTES = 2_000_000;
const DINGDANDAO_IAB_MAX_NORMALIZER_OUTPUT_BYTES = 2_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'target-date:',
    'node-binary::',
]);

$hotelId = iabPositiveInt(
    $options['hotel-id'] ?? null,
    'dingdandao_iab_hotel_id_invalid'
);
$ownerUserId = iabPositiveInt(
    $options['owner-user-id'] ?? null,
    'dingdandao_iab_owner_user_id_invalid'
);
$targetDate = trim((string)($options['target-date'] ?? ''));
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
if (!iabValidDate($targetDate) || $targetDate > $today) {
    iabFail('dingdandao_iab_target_date_invalid', 2);
}
$stdin = stream_get_contents(STDIN, DINGDANDAO_IAB_MAX_INPUT_BYTES + 1);
if (!is_string($stdin)
    || trim($stdin) === ''
    || strlen($stdin) > DINGDANDAO_IAB_MAX_INPUT_BYTES
) {
    iabFail('dingdandao_iab_input_invalid', 2);
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
    iabFail('dingdandao_iab_hotel_scope_invalid');
}
$tenantId = (int)$hotel['tenant_id'];
$hotelName = trim((string)$hotel['name']);

$ownerUser = User::where('id', $ownerUserId)->find();
if (!$ownerUser instanceof User
    || (int)($ownerUser->tenant_id ?? 0) !== $tenantId
    || (int)($ownerUser->status ?? 0) !== User::STATUS_ENABLED
) {
    iabFail('dingdandao_iab_owner_scope_invalid');
}
$authorization = (new PermissionService())->authorize(
    $ownerUser,
    'ota.collect',
    $hotelId
);
if (($authorization['allowed'] ?? false) !== true) {
    iabFail('dingdandao_iab_owner_permission_denied');
}

$integrationService = new DingdandaoPmsIntegrationService();
$expectation = $integrationService->captureExpectation(
    $tenantId,
    $hotelId,
    $hotelName
);
$expectedProviderHotelName = trim((string)(
    $expectation['expected_provider_hotel_name'] ?? ''
));
$expectedProviderHotelId = trim((string)(
    $expectation['expected_provider_hotel_id'] ?? ''
));
if (($expectation['configured'] ?? false) !== true
    || $expectedProviderHotelName === ''
    || $expectedProviderHotelId === ''
) {
    iabFail('dingdandao_iab_provider_binding_missing');
}

$nodeBinary = iabResolveNodeBinary(
    isset($options['node-binary']) ? (string)$options['node-binary'] : null
);
$sourceScope = $targetDate < $today
    ? DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE
    : DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE;

$lockDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'suxios-dingdandao-iab';
if (!is_dir($lockDirectory)
    && !mkdir($lockDirectory, 0700, true)
    && !is_dir($lockDirectory)
) {
    iabFail('dingdandao_iab_lock_directory_unavailable');
}
$lock = fopen(
    $lockDirectory . DIRECTORY_SEPARATOR . 'hotel-' . $hotelId . '.lock',
    'c+'
);
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    iabFail('dingdandao_iab_collection_already_running');
}

$failureStage = 'normalization';
$businessDataPersisted = false;
$captureId = 0;
try {
    $normalized = iabRunNormalizer(
        $nodeBinary,
        $root . '/scripts/normalize_dingdandao_iab_capture.mjs',
        $stdin,
        $targetDate,
        $expectedProviderHotelName,
        $expectedProviderHotelId
    );
    unset($stdin);
    if (($normalized['status'] ?? '')
            !== 'normalized_browser_response_supplement'
        || ($normalized['record_count'] ?? 0) !== 6
        || ($normalized['raw_response_exposed'] ?? null) !== false
        || ($normalized['session_material_exposed'] ?? null) !== false
        || ($normalized['sensitive_values_exposed'] ?? null) !== false
        || !is_array($normalized['capture'] ?? null)
    ) {
        throw new RuntimeException('dingdandao_iab_normalized_contract_invalid');
    }
    $captureInput = $normalized['capture'];
    if (($captureInput['capture_method'] ?? '') !== 'network_response'
        || ($captureInput['capture_evidence']['capture_strategy'] ?? '')
            !== 'browser_response_supplement'
        || ($captureInput['capture_evidence']['capture_source'] ?? '')
            !== 'operator_supplied_browser_response'
        || (string)($captureInput['business_date'] ?? '') !== $targetDate
        || (string)($captureInput['source_scope'] ?? '') !== $sourceScope
        || (string)($captureInput['provider_hotel_id'] ?? '')
            !== $expectedProviderHotelId
        || (string)($captureInput['provider_hotel_name'] ?? '')
            !== $expectedProviderHotelName
    ) {
        throw new RuntimeException('dingdandao_iab_capture_scope_invalid');
    }

    $failureStage = 'persistence';
    $capture = (new DingdandaoOperatingTargetCaptureService())->save(
        $tenantId,
        $hotelId,
        $ownerUserId,
        $expectedProviderHotelName,
        $captureInput,
        false,
        $expectedProviderHotelId,
        false
    );
    $businessDataPersisted = true;
    $captureId = (int)($capture['id'] ?? 0);
    unset($captureInput, $normalized);

    $failureStage = 'readback_validation';
    if ($captureId <= 0
        || ($capture['quality_status'] ?? '') !== 'unverified'
        || ($capture['capture_status'] ?? '') !== 'identity_unverified'
        || ($capture['readback_status'] ?? '') !== 'readback_verified'
        || ($capture['identity_status'] ?? '') !== 'matched'
        || ($capture['reconciliation_status'] ?? '') !== 'matched'
        || (string)($capture['business_date'] ?? '') !== $targetDate
        || (string)($capture['source_scope'] ?? '') !== $sourceScope
        || (int)($capture['hotel_id'] ?? 0) !== $hotelId
    ) {
        throw new RuntimeException('dingdandao_iab_readback_not_verified');
    }
    $claim = (new CollectionResultContractService())->validateDingdandaoCaptureClaim(
        $capture,
        [
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $targetDate,
            'provider_hotel_id' => $expectedProviderHotelId,
            'source_scope' => $sourceScope,
        ]
    );
    if (($claim['allowed'] ?? true) !== false
        || !in_array(
            'dingdandao_trusted_collection_required',
            (array)($claim['reason_codes'] ?? []),
            true
        )
    ) {
        throw new RuntimeException('dingdandao_iab_collection_contract_overpromoted');
    }

    echo json_encode(
        [
            'status' => 'saved_unverified_browser_response_supplement',
            'collection_success' => false,
            'business_data_persisted' => true,
            'execution_mode' => 'iab_browser_response_supplement',
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'capture_id' => $captureId,
            'identity_status' => (string)$capture['identity_status'],
            'reconciliation_status' => (string)$capture['reconciliation_status'],
            'quality_status' => (string)$capture['quality_status'],
            'readback_status' => (string)$capture['readback_status'],
            'collection_contract_status' => 'supplemental_unverified',
            'source_scope' => (string)$capture['source_scope'],
            'capture_strategy' => (string)(
                $capture['capture_evidence']['capture_strategy'] ?? ''
            ),
            'summary' => (array)($capture['summary'] ?? []),
            'detail_row_count' => (int)($capture['detail_row_count'] ?? 0),
            'operating_target_sync' => [
                'status' => 'not_attempted',
                'sync_status' => 'blocked_by_unverified_source',
                'record_id' => 0,
                'revision_no' => 0,
            ],
            'trust_boundary' =>
                'external_browser_execution_receipt_required_for_verified_use',
            'push' => [
                'requested' => false,
                'delivery_attempted' => false,
                'delivery_status' => 'not_requested',
            ],
            'raw_response_exposed' => false,
            'session_material_exposed' => false,
            'sensitive_values_exposed' => false,
        ],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
    ) . PHP_EOL;
} catch (Throwable $error) {
    iabFail(
        iabSafeReason($error->getMessage()),
        1,
        [
            'failure_stage' => $failureStage,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'business_data_persisted' => $businessDataPersisted,
            'capture_id' => $captureId,
        ]
    );
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** @return array<string,mixed> */
function iabRunNormalizer(
    string $nodeBinary,
    string $script,
    string $input,
    string $targetDate,
    string $expectedHotelName,
    string $expectedProviderHotelId
): array {
    if (!is_file($script)) {
        throw new RuntimeException('dingdandao_iab_normalizer_missing');
    }
    $command = [
        $nodeBinary,
        $script,
        '--target-date=' . $targetDate,
        '--expected-hotel-name=' . $expectedHotelName,
        '--expected-provider-hotel-id=' . $expectedProviderHotelId,
        '--collection-mode=operating_indicators',
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
        throw new RuntimeException('dingdandao_iab_normalizer_start_failed');
    }
    try {
        $written = fwrite($pipes[0], $input);
        if ($written === false || $written !== strlen($input)) {
            throw new RuntimeException('dingdandao_iab_normalizer_input_failed');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents(
            $pipes[1],
            DINGDANDAO_IAB_MAX_NORMALIZER_OUTPUT_BYTES + 1
        );
        $stderr = stream_get_contents($pipes[2], 4096);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    } catch (Throwable $error) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process);
        proc_close($process);
        throw $error;
    }
    if (!is_string($stdout)
        || strlen($stdout) > DINGDANDAO_IAB_MAX_NORMALIZER_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $safe = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        throw new RuntimeException(
            is_array($safe) && is_string($safe['reason'] ?? null)
                ? iabSafeReason($safe['reason'])
                : 'dingdandao_iab_normalizer_failed'
        );
    }
    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('dingdandao_iab_normalizer_output_invalid');
    }
    return $decoded;
}

function iabResolveNodeBinary(?string $requested): string
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
        if ($resolved !== false && is_file($resolved)) {
            return $resolved;
        }
    }
    $path = trim((string)(getenv('PATH') ?: ''));
    foreach (explode(PATH_SEPARATOR, $path) as $directory) {
        $directory = trim($directory);
        if ($directory === '') {
            continue;
        }
        foreach (PHP_OS_FAMILY === 'Windows' ? ['node.exe', 'node.cmd'] : ['node'] as $name) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }
    iabFail('dingdandao_iab_node_binary_missing', 2);
}

function iabPositiveInt(mixed $value, string $reason): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
        iabFail($reason, 2);
    }
    return (int)$value;
}

function iabValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function iabSafeReason(string $value): string
{
    $value = strtolower(trim($value));
    return preg_match('/^[a-z0-9_]{1,120}$/D', $value) === 1
        ? $value
        : 'dingdandao_iab_collection_failed';
}

/** @param array<string,mixed> $context */
function iabFail(string $reason, int $exitCode = 1, array $context = []): never
{
    fwrite(
        STDERR,
        json_encode(
            [
                'status' => 'blocked',
                'reason' => iabSafeReason($reason),
                'raw_response_exposed' => false,
                'session_material_exposed' => false,
                'sensitive_values_exposed' => false,
            ] + $context,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        ) . PHP_EOL
    );
    exit($exitCode);
}
