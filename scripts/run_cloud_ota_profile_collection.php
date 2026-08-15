#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\BrowserProfileProcessOutputSanitizer;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use app\service\platform\TrustedCloudProfileDataSourceAdapter;
use think\App;
use think\facade\Db;

const OTA_CLOUD_COLLECTION_TIMEZONE = 'Asia/Shanghai';

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new App($root))->initialize();
set_exception_handler(static function (Throwable $error): never {
    fail(safeReason($error->getMessage()), false);
});

$options = getopt('', [
    'data-source-id:',
    'owner-user-id:',
    'profile-id:',
    'target-date::',
    'gateway-url::',
    'cdp-url::',
    'control-token-file::',
    'timeout-seconds::',
]);
$today = (new DateTimeImmutable('now', new DateTimeZone(OTA_CLOUD_COLLECTION_TIMEZONE)))
    ->format('Y-m-d');
$sourceId = positiveInt($options['data-source-id'] ?? null, 'data_source_id_invalid');
$ownerUserId = positiveInt($options['owner-user-id'] ?? null, 'owner_user_id_invalid');
$profileId = opaqueId((string)($options['profile-id'] ?? ''), 'cbp_', 'profile_id_invalid');
$targetDate = trim((string)($options['target-date'] ?? $today));
$gatewayUrl = rtrim(trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')), '/');
$cdpUrl = rtrim(trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')), '/');
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token'));
$timeoutSeconds = max(60, min(900, (int)($options['timeout-seconds'] ?? 600)));

if (!validDate($targetDate)
    || $targetDate !== $today
    || $gatewayUrl !== 'http://127.0.0.1:8787'
    || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{1,4}$#D', $cdpUrl) !== 1
    || !in_array($tokenFile, [
        '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token',
        '/run/credentials/suxios-cloud-three-source-queue.service/control-token',
        '/etc/suxios-cloud-browser/control-token',
    ], true)
) {
    fail('cloud_ota_collection_arguments_invalid');
}

$source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
if (!is_array($source)) {
    fail('cloud_ota_data_source_missing');
}
$source['config'] = decodeJson((string)($source['config_json'] ?? ''));
$platform = strtolower(trim((string)($source['platform'] ?? '')));
$tenantId = (int)($source['tenant_id'] ?? 0);
$hotelId = (int)($source['system_hotel_id'] ?? 0);
if (!in_array($platform, ['ctrip', 'meituan'], true)
    || $tenantId <= 0
    || $hotelId <= 0
    || (int)($source['user_id'] ?? 0) !== $ownerUserId
    || (int)($source['enabled'] ?? 0) !== 1
    || !in_array(strtolower(trim((string)($source['ingestion_method'] ?? ''))), ['browser_profile', 'profile_browser'], true)
) {
    fail('cloud_ota_data_source_scope_invalid');
}
$platformHotelId = platformHotelId($platform, $source['config']);
$profileBindingKey = profileBindingKey($platform, $source['config']);
if ($platformHotelId === '' || $profileBindingKey === '') {
    fail('cloud_ota_data_source_binding_missing');
}

$validated = (new CloudBrowserProfileService())->validateOtaDataSourceCollectionProfile(
    $profileId,
    $sourceId,
    $tenantId,
    $hotelId,
    $ownerUserId,
    $targetDate,
    $platform
);
if (($validated['validated'] ?? false) !== true
    || (int)($validated['data_source_id'] ?? 0) !== $sourceId
    || trim((string)($validated['platform_hotel_id'] ?? '')) !== $platformHotelId
) {
    fail('cloud_ota_collection_preflight_unverified');
}

$controlToken = trim((string)@file_get_contents($tokenFile));
if (strlen($controlToken) < 32) {
    fail('cloud_ota_collection_control_token_unavailable');
}

$collectionSessionId = null;
$closeOutcome = 'cancelled';
$businessDataPersisted = false;
$failureReason = null;
$result = null;
try {
    $opened = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/open', [
        'profile_id' => $profileId,
        'platform' => $platform,
        'data_source_id' => $sourceId,
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'owner_user_id' => $ownerUserId,
        'target_date' => $targetDate,
        'collection_kind' => 'ota_channel_profile',
        'access_mode' => 'read_only',
    ]);
    if (($opened['status'] ?? '') !== 'collection_open'
        || ($opened['collector_read_only_contract'] ?? null) !== true
        || ($opened['network_freshness_control']['http_cache_disabled'] ?? null) !== true
        || ($opened['network_freshness_control']['service_worker_bypassed'] ?? null) !== true
        || (int)($opened['data_source_id'] ?? 0) !== $sourceId
        || (string)($opened['platform'] ?? '') !== $platform
        || (string)($opened['target_date'] ?? '') !== $targetDate
        || ($opened['browser_started'] ?? null) !== true
    ) {
        throw new RuntimeException('cloud_ota_gateway_open_unverified');
    }
    $collectionSessionId = opaqueId(
        (string)($opened['collection_session_id'] ?? ''),
        'cbcs_',
        'cloud_ota_collection_session_invalid'
    );

    $captureOptions = [
        'cdp_url' => $cdpUrl,
        'data_date' => $targetDate,
        'data_period' => 'realtime_snapshot',
        'snapshot_time' => (new DateTimeImmutable(
            'now',
            new DateTimeZone(OTA_CLOUD_COLLECTION_TIMEZONE)
        ))->format('Y-m-d H:i:s'),
        'interactive_browser' => false,
        'timeout_seconds' => $timeoutSeconds,
        'profile_status' => 'ready',
        'required_platform_hotel_id' => $platformHotelId,
        'require_current_run_session_probe' => true,
        'trigger_type' => 'cloud_browser_profile',
    ];
    if ($platform === 'ctrip') {
        $captureOptions['collector_flow'] = 'realtime';
        $captureOptions['capture_plan'] = 'realtime';
        $captureOptions['capture_sections'] = 'business_overview,traffic_report';
        $captureOptions['bounded_capture_sections'] = 'business_overview,traffic_report';
        $captureOptions['ctrip_section_concurrency'] = 2;
        $captureAdapter = new CtripBrowserProfileDataSourceAdapter($root);
    } else {
        // Match the product's existing current-day Meituan temporal contract:
        // collect the real-time business/traffic snapshot without traversing
        // yesterday, 7-day, 30-day and every peer-ranking tab.
        $captureOptions['capture_sections'] = 'traffic';
        $captureOptions['capture_mode'] = 'temporal_summary';
        $captureOptions['temporal_scope'] = 'current_day';
        $captureAdapter = new MeituanBrowserProfileDataSourceAdapter($root);
    }

    $captureResult = $captureAdapter->fetch($source, $captureOptions);
    $capturePayload = is_array($captureResult['payload'] ?? null) ? $captureResult['payload'] : [];
    assertCurrentCaptureEvidence($captureResult, $capturePayload, $platformHotelId, $platform);

    $proofService = new OtaProfileSessionProofService();
    $proofService->recordCollectionPreflightVerified(
        $sourceId,
        $hotelId,
        $platform,
        $profileBindingKey,
        true,
        (array)$capturePayload['auth_status'],
        (array)$capturePayload['platform_identity_validation']
    );
    $proofSource = Db::name('platform_data_sources')
        ->where('id', $sourceId)
        ->where('tenant_id', $tenantId)
        ->where('system_hotel_id', $hotelId)
        ->find();
    if (!is_array($proofSource) || !$proofService->isCurrentVerified($proofSource)) {
        throw new RuntimeException('cloud_ota_current_session_proof_readback_unverified');
    }

    $trustedAdapter = new TrustedCloudProfileDataSourceAdapter(
        $sourceId,
        $platform,
        $platformHotelId,
        $captureResult
    );
    $syncUser = new class($ownerUserId) {
        public function __construct(public int $id) {}
        public function isSuperAdmin(): bool { return true; }
    };
    // The loopback CDP endpoint is a collection-control address, not OTA
    // source metadata. The one-shot adapter already owns the captured result,
    // so never pass that local URL into the persistence pipeline's OTA URL
    // allowlist checks.
    $syncOptions = $captureOptions;
    unset($syncOptions['cdp_url']);
    $syncResult = (new PlatformDataSyncService([$trustedAdapter], null, $proofService))
        ->syncDataSource($syncUser, $sourceId, $syncOptions);
    $savedCount = (int)($syncResult['saved_count'] ?? 0);
    $readbackCount = (int)($syncResult['readback_count'] ?? 0);
    if (!in_array((string)($syncResult['status'] ?? ''), ['success', 'partial_success'], true)
        || $savedCount <= 0
        || $readbackCount !== $savedCount
        || ($syncResult['readback_verified'] ?? null) !== true
        || ($syncResult['rolled_back'] ?? false) === true
    ) {
        throw new RuntimeException('cloud_ota_formal_readback_unverified');
    }
    $businessDataPersisted = true;
    $closeOutcome = 'completed';
    $result = [
        'status' => 'saved_and_readback_verified',
        'platform' => $platform,
        'data_source_id' => $sourceId,
        'hotel_id' => $hotelId,
        'target_date' => $targetDate,
        'task_id' => (int)($syncResult['task_id'] ?? 0),
        'normalized_count' => (int)($syncResult['normalized_count'] ?? 0),
        'saved_count' => $savedCount,
        'readback_count' => $readbackCount,
        'readback_verified' => true,
        'current_session_proof_readback_verified' => true,
        'business_data_persisted' => true,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ];
} catch (Throwable $error) {
    $reason = safeReason($error->getMessage());
    if (str_contains($reason, 'login') || str_contains($reason, 'session')) {
        $closeOutcome = 'session_expired';
    } elseif (str_contains($reason, 'policy') || str_contains($reason, 'scope')) {
        $closeOutcome = 'policy_blocked';
    }
    $failureReason = $reason;
} finally {
    if ($collectionSessionId !== null) {
        try {
            $closed = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/close', [
                'collection_session_id' => $collectionSessionId,
                'profile_id' => $profileId,
                'platform' => $platform,
                'outcome' => $closeOutcome,
            ]);
            if (($closed['status'] ?? '') !== 'collection_closed'
                || ($closed['profile_sealed'] ?? null) !== true
                || ($closed['browser_started'] ?? null) !== false
            ) {
                throw new RuntimeException('cloud_ota_collection_profile_close_unverified');
            }
        } catch (Throwable $closeError) {
            $failureReason = $failureReason ?? safeReason($closeError->getMessage());
            $failureReason .= '_profile_close_failed';
            $result = null;
        }
    }
    $controlToken = str_repeat("\0", strlen($controlToken));
}
if ($failureReason !== null || !is_array($result)) {
    fail($failureReason, $businessDataPersisted);
}
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;

/** @param array<string,mixed> $result @param array<string,mixed> $payload */
function assertCurrentCaptureEvidence(
    array $result,
    array $payload,
    string $platformHotelId,
    string $platform
): void
{
    if (($result['status'] ?? '') !== 'success') {
        $statusCode = trim((string)($result['status_code'] ?? $result['error_code'] ?? ''));
        if ($statusCode === '') {
            $sanitizedMessage = BrowserProfileProcessOutputSanitizer::sanitizeMessage(
                (string)($result['message'] ?? ''),
                400
            );
            $messageParts = array_values(array_filter(array_map(
                'trim',
                explode(' | ', $sanitizedMessage)
            )));
            $statusCode = (string)($messageParts[count($messageParts) - 1] ?? $sanitizedMessage);
        }
        throw new RuntimeException(
            'cloud_ota_' . $platform . '_capture_' . safeReason($statusCode ?: 'failed')
        );
    }
    $freshness = is_array($payload['network_freshness'] ?? null) ? $payload['network_freshness'] : [];
    $auth = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
    $identity = is_array($payload['platform_identity_validation'] ?? null)
        ? $payload['platform_identity_validation']
        : [];
    $evidence = strtolower(trim((string)($identity['evidence_source'] ?? '')));
    if (strtolower(trim((string)($freshness['status'] ?? ''))) !== 'ready'
        || ($freshness['http_cache_disabled'] ?? null) !== true
        || ($freshness['service_worker_bypassed'] ?? null) !== true
        || ($freshness['sensitive_values_exposed'] ?? null) !== false
        || ($auth['ok'] ?? null) !== true
        || !in_array(strtolower(trim((string)($auth['status'] ?? ''))), ['logged_in', 'authorized'], true)
        || (int)($identity['schema_version'] ?? 0) !== 1
        || strtolower(trim((string)($identity['status'] ?? ''))) !== 'matched'
        || !in_array($evidence, ['ota_request', 'ota_request_or_own_response', 'trusted_ota_page_state'], true)
        || ($identity['sensitive_values_exposed'] ?? false) === true
        || !hash_equals($platformHotelId, trim((string)($identity['validated_identifier'] ?? '')))
    ) {
        throw new RuntimeException('cloud_ota_current_capture_evidence_invalid');
    }
}

/** @return array<string,mixed> */
function gatewayRequest(string $baseUrl, string $token, string $path, array $body): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($baseUrl . $path, false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        throw new RuntimeException(is_array($decoded)
            ? (string)($decoded['reason'] ?? 'cloud_ota_gateway_failed')
            : 'cloud_ota_gateway_failed');
    }
    return $decoded;
}

/** @return array<string,mixed> */
function decodeJson(string $json): array
{
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $config */
function platformHotelId(string $platform, array $config): string
{
    $keys = $platform === 'meituan'
        ? ['platform_hotel_id', 'poi_id', 'poiId', 'store_id', 'storeId']
        : ['platform_hotel_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'node_id', 'nodeId'];
    return firstValue($config, $keys);
}

/** @param array<string,mixed> $config */
function profileBindingKey(string $platform, array $config): string
{
    return firstValue($config, $platform === 'meituan'
        ? ['profile_binding_key', 'profileBindingKey', 'stable_profile_id', 'stableProfileId', 'store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
        : ['profile_binding_key', 'profileBindingKey', 'stable_profile_id', 'stableProfileId', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId']);
}

/** @param array<string,mixed> $values @param array<int,string> $keys */
function firstValue(array $values, array $keys): string
{
    foreach ($keys as $key) {
        if (is_scalar($values[$key] ?? null) && trim((string)$values[$key]) !== '') {
            return trim((string)$values[$key]);
        }
    }
    return '';
}

function positiveInt(mixed $value, string $reason): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if (!is_int($parsed) || $parsed <= 0) {
        fail($reason);
    }
    return $parsed;
}

function opaqueId(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        fail($reason);
    }
    return $value;
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function safeReason(string $value): string
{
    $normalized = trim(
        (string)preg_replace('/[^a-zA-Z0-9_-]+/', '_', strtolower($value)),
        '_'
    );
    return substr($normalized, 0, 100) ?: 'cloud_ota_collection_failed';
}

function fail(string $reason, bool $persisted = false): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'business_data_persisted' => $persisted,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
