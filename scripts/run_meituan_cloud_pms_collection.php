#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\MeituanCloudPmsCaptureService;
use app\service\MeituanCloudPmsIntegrationService;
use think\App;
use think\facade\Db;

const MAX_COLLECTOR_OUTPUT_BYTES = 1_000_000;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();

$options = getopt('', [
    'hotel-id:',
    'owner-user-id:',
    'profile-id:',
    'target-date::',
    'gateway-url::',
    'cdp-url::',
    'control-token-file::',
    'node-binary::',
    'no-push',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->format('Y-m-d');
$hotelId = positiveInt($options['hotel-id'] ?? null, 'hotel_id_invalid');
$ownerUserId = positiveInt($options['owner-user-id'] ?? null, 'owner_user_id_invalid');
$profileId = opaqueId((string)($options['profile-id'] ?? ''), 'cbp_', 'profile_id_invalid');
$targetDate = trim((string)($options['target-date'] ?? $today));
$gatewayUrl = rtrim(trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')), '/');
$cdpUrl = rtrim(trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')), '/');
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-meituan-cloud-pms-collection.service/control-token'));
$nodeBinary = trim((string)($options['node-binary'] ?? '/usr/bin/node'));
$noPush = array_key_exists('no-push', $options);

if (!validDate($targetDate)
    || $targetDate !== $today
    || $gatewayUrl !== 'http://127.0.0.1:8787'
    || !preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{1,4}$#D', $cdpUrl)
    || !in_array($tokenFile, [
        '/run/credentials/suxios-meituan-cloud-pms-collection.service/control-token',
        '/run/credentials/suxios-cloud-three-source-queue.service/control-token',
        '/etc/suxios-cloud-browser/control-token',
    ], true)
    || $nodeBinary !== '/usr/bin/node'
) {
    fail('meituan_cloud_collection_arguments_invalid', 2);
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
    fail('meituan_cloud_collection_hotel_scope_invalid');
}
$tenantId = (int)$hotel['tenant_id'];
$hotelName = (string)$hotel['name'];
$integration = new MeituanCloudPmsIntegrationService();
$expectation = $integration->captureExpectation($tenantId, $hotelId, $hotelName);
if (($expectation['configured'] ?? false) !== true) {
    fail('meituan_cloud_integration_not_configured');
}
$expectedProviderHotelName = (string)$expectation['expected_provider_hotel_name'];
$expectedProviderHotelId = $expectation['expected_provider_hotel_id'] ?? null;

$lockPath = '/run/suxios-meituan-cloud-pms-collection/hotel-' . $hotelId . '.lock';
if (!is_dir(dirname($lockPath))) {
    fail('meituan_cloud_collection_lock_directory_missing');
}
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    fail('meituan_cloud_collection_already_running');
}

$controlToken = @file_get_contents($tokenFile);
$controlToken = is_string($controlToken) ? trim($controlToken) : '';
if (strlen($controlToken) < 32) {
    fail('meituan_cloud_collection_control_token_unavailable');
}

$collectionSessionId = null;
$closeOutcome = 'cancelled';
$mainError = null;
$result = null;
$businessDataPersisted = false;
try {
    $opened = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/open', [
        'profile_id' => $profileId,
        'platform' => MeituanCloudPmsCaptureService::PROFILE_PLATFORM,
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'owner_user_id' => $ownerUserId,
        'target_date' => $targetDate,
        'collection_kind' => 'operating_target_today',
        'access_mode' => 'read_only',
    ]);
    if (($opened['status'] ?? '') !== 'collection_open'
        || ($opened['read_only_enforced'] ?? null) !== true
        || ($opened['browser_started'] ?? null) !== true
        || (string)($opened['platform'] ?? '') !== MeituanCloudPmsCaptureService::PROFILE_PLATFORM
        || (int)($opened['tenant_id'] ?? 0) !== $tenantId
        || (int)($opened['hotel_id'] ?? 0) !== $hotelId
        || (int)($opened['owner_user_id'] ?? 0) !== $ownerUserId
        || (string)($opened['target_date'] ?? '') !== $targetDate
        || (string)($opened['source_scope'] ?? '') !== MeituanCloudPmsCaptureService::SOURCE_SCOPE
    ) {
        throw new RuntimeException('meituan_cloud_collection_gateway_open_unverified');
    }
    $collectionSessionId = opaqueId(
        (string)($opened['collection_session_id'] ?? ''),
        'cbcs_',
        'meituan_cloud_collection_session_invalid'
    );

    $collector = runCollector(
        $nodeBinary,
        $root . '/scripts/meituan_cloud_pms_capture.mjs',
        $cdpUrl,
        $targetDate,
        $expectedProviderHotelName
    );
    if (($collector['status'] ?? '') !== 'captured_unverified'
        || !is_array($collector['capture'] ?? null)
        || ($collector['raw_response_exposed'] ?? null) !== false
        || ($collector['session_material_exposed'] ?? null) !== false
    ) {
        throw new RuntimeException('meituan_cloud_collection_payload_invalid');
    }

    $capture = (new MeituanCloudPmsCaptureService())->save(
        $tenantId,
        $hotelId,
        $ownerUserId,
        $expectedProviderHotelName,
        $collector['capture'],
        true,
        is_string($expectedProviderHotelId) ? $expectedProviderHotelId : null
    );
    $businessDataPersisted = true;
    $integration->recordCapture($tenantId, $hotelId, $ownerUserId, $capture);
    if (($capture['quality_status'] ?? '') !== 'verified'
        || ($capture['capture_status'] ?? '') !== 'verified'
        || ($capture['readback_status'] ?? '') !== 'readback_verified'
        || ($capture['identity_status'] ?? '') !== 'matched'
        || ($capture['date_status'] ?? '') !== 'matched'
        || ($capture['reconciliation_status'] ?? '') !== 'matched'
        || (string)($capture['business_date'] ?? '') !== $targetDate
        || (int)($capture['hotel_id'] ?? 0) !== $hotelId
    ) {
        throw new RuntimeException('meituan_cloud_collection_readback_not_verified');
    }
    $prefill = $integration->prefill(
        $tenantId,
        $hotelId,
        $ownerUserId,
        $targetDate
    );
    if (($prefill['status'] ?? '') !== 'verified'
        || (int)($prefill['capture']['id'] ?? 0) !== (int)$capture['id']
    ) {
        throw new RuntimeException('meituan_cloud_collection_prefill_readback_failed');
    }

    $closeOutcome = 'completed';
    $result = [
        'status' => 'saved_and_readback_verified',
        'provider' => MeituanCloudPmsCaptureService::PROVIDER,
        'hotel_id' => $hotelId,
        'target_date' => $targetDate,
        'capture_id' => (int)$capture['id'],
        'identity_status' => 'matched',
        'date_status' => 'matched',
        'reconciliation_status' => 'matched',
        'quality_status' => 'verified',
        'readback_status' => 'readback_verified',
        'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
        'room_type_count' => (int)($capture['room_type_count'] ?? 0),
        'message_sent' => false,
        'push_orchestration' => [
            'disabled_by_invocation' => $noPush,
            'delivery_status' => 'not_supported',
            'delivery_attempted' => false,
        ],
        'sensitive_values_exposed' => false,
    ];
} catch (Throwable $error) {
    $reason = safeReason($error->getMessage());
    if (str_contains($reason, 'session_not_authenticated')
        || str_contains($reason, 'session_expired')
    ) {
        $closeOutcome = 'session_expired';
    } elseif (str_contains($reason, 'policy')) {
        $closeOutcome = 'policy_blocked';
    }
    $mainError = $reason;
} finally {
    if ($collectionSessionId !== null) {
        try {
            $closed = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/close', [
                'collection_session_id' => $collectionSessionId,
                'profile_id' => $profileId,
                'platform' => MeituanCloudPmsCaptureService::PROFILE_PLATFORM,
                'outcome' => $closeOutcome,
            ]);
            if (($closed['status'] ?? '') !== 'collection_closed'
                || ($closed['profile_sealed'] ?? null) !== true
                || ($closed['browser_started'] ?? null) !== false
            ) {
                throw new RuntimeException('meituan_cloud_collection_profile_close_unverified');
            }
        } catch (Throwable $closeError) {
            $mainError = $mainError ?? safeReason($closeError->getMessage());
            $mainError .= '_profile_close_failed';
            $result = null;
        }
    }
    $controlToken = str_repeat("\0", strlen($controlToken));
    flock($lock, LOCK_UN);
    fclose($lock);
}

if ($mainError !== null || !is_array($result)) {
    fail($mainError ?? 'meituan_cloud_collection_failed', 1, $businessDataPersisted);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/** @return array<string,mixed> */
function gatewayRequest(string $baseUrl, string $token, string $path, array $body): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => $json,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    error_clear_last();
    $raw = @file_get_contents($baseUrl . $path, false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        $reason = is_array($decoded) ? (string)($decoded['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== ''
                ? $reason
                : gatewayTransportFailureCode('meituan_cloud_collection_gateway_failed')
        );
    }
    return $decoded;
}

function gatewayTransportFailureCode(string $fallback): string
{
    $lastError = error_get_last();
    $message = is_array($lastError) ? (string)($lastError['message'] ?? '') : '';
    if (preg_match('/(?:connection\s+refused|actively\s+refused)/i', $message) === 1) {
        return 'gateway_connection_refused';
    }
    if (preg_match('/(?:connection\s+timed\s*out|operation\s+timed\s*out|read\s+timed\s*out)/i', $message) === 1) {
        return 'gateway_connection_timeout';
    }
    return $fallback;
}

/** @return array<string,mixed> */
function runCollector(
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
        throw new RuntimeException('meituan_cloud_collector_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1], MAX_COLLECTOR_OUTPUT_BYTES + 1);
    $stderr = stream_get_contents($pipes[2], 4096);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!is_string($stdout)
        || strlen($stdout) > MAX_COLLECTOR_OUTPUT_BYTES
        || $exitCode !== 0
    ) {
        $error = is_string($stderr) ? json_decode(trim($stderr), true) : null;
        $reason = is_array($error) ? (string)($error['reason'] ?? '') : '';
        throw new RuntimeException(
            $reason !== '' ? $reason : 'meituan_cloud_collector_failed'
        );
    }
    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('meituan_cloud_collector_output_invalid');
    }
    return $decoded;
}

function positiveInt(mixed $value, string $reason): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($validated) || $validated <= 0) {
        fail($reason, 2);
    }
    return $validated;
}

function opaqueId(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        fail($reason, 2);
    }
    return $value;
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function safeReason(string $reason): string
{
    return preg_replace('/[^a-zA-Z0-9_-]+/', '_', $reason)
        ?: 'meituan_cloud_collection_failed';
}

function fail(string $reason, int $exitCode = 1, bool $businessDataPersisted = false): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'business_data_persisted' => $businessDataPersisted,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}
