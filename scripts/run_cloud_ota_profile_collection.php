#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\CloudBrowserProfileService;
use app\service\OtaHistoricalCoreReadbackVerifier;
use app\service\OtaOrderedCollectionPlanner;
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
    'run-mode::',
    'gateway-url::',
    'cdp-url::',
    'control-token-file::',
    'timeout-seconds::',
    'dispatcher-run-id::',
]);
$now = new DateTimeImmutable('now', new DateTimeZone(OTA_CLOUD_COLLECTION_TIMEZONE));
$today = $now->format('Y-m-d');
$previousBusinessDay = $now->modify('-1 day')->format('Y-m-d');
$sourceId = positiveInt($options['data-source-id'] ?? null, 'data_source_id_invalid');
$ownerUserId = positiveInt($options['owner-user-id'] ?? null, 'owner_user_id_invalid');
$profileId = opaqueId((string)($options['profile-id'] ?? ''), 'cbp_', 'profile_id_invalid');
$targetDate = trim((string)($options['target-date'] ?? $today));
$runModeInput = strtolower(trim((string)($options['run-mode'] ?? '')));
$runMode = $runModeInput;
if ($runMode === '') {
    $runMode = $targetDate === $previousBusinessDay ? 'daily' : 'realtime';
}
$historicalCollection = $runMode === 'daily';
$dataPeriod = $historicalCollection ? 'historical_daily' : 'realtime_snapshot';
$gatewayUrl = rtrim(trim((string)($options['gateway-url'] ?? 'http://127.0.0.1:8787')), '/');
$cdpUrl = rtrim(trim((string)($options['cdp-url'] ?? 'http://127.0.0.1:9223')), '/');
$tokenFile = trim((string)($options['control-token-file']
    ?? '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token'));
$timeoutSeconds = max(60, min(900, (int)($options['timeout-seconds'] ?? 600)));
$dispatcherRunIdInput = strtolower(trim((string)($options['dispatcher-run-id'] ?? '')));
$dispatcherRunId = preg_match(
    '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
    $dispatcherRunIdInput
) === 1 ? $dispatcherRunIdInput : '';

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
$syncUser = new class($ownerUserId) {
    public function __construct(public int $id) {}
    public function isSuperAdmin(): bool { return true; }
};
$failureReceiptService = new PlatformDataSyncService();
$collectionSessionId = null;
$gatewayOpenAccepted = false;
$closeOutcome = 'cancelled';
$businessDataPersisted = false;
$syncTaskRecorded = false;
$postSyncFailure = false;
$failureReason = null;
$result = null;
$closeReceiptId = null;
$closeReceiptHash = null;
$controlToken = '';
$failureOptions = [
    'data_date' => validDate($targetDate) ? $targetDate : '',
    'target_date' => validDate($targetDate) ? $targetDate : '',
    'data_period' => $dataPeriod,
    'trigger_type' => $dispatcherRunId !== '' ? 'daily_profile_reuse' : 'cloud_browser_profile',
];
if ($dispatcherRunId !== '') {
    $failureOptions['dispatcher_run_id'] = $dispatcherRunId;
}
try {
    if (!validDate($targetDate)
        || !in_array($targetDate, [$today, $previousBusinessDay], true)
        || !in_array($runMode, ['daily', 'realtime'], true)
        || ($runMode === 'daily' && $targetDate !== $previousBusinessDay)
        || ($runMode === 'realtime' && $targetDate !== $today)
        || ($dispatcherRunId !== '' && $runModeInput === '')
        || ($dispatcherRunIdInput !== '' && $dispatcherRunId === '')
        || $gatewayUrl !== 'http://127.0.0.1:8787'
        || preg_match('#^http://127\.0\.0\.1:[1-9][0-9]{1,4}$#D', $cdpUrl) !== 1
        || !in_array($tokenFile, [
            '/run/credentials/suxios-cloud-ota-profile-collection.service/control-token',
            '/run/credentials/suxios-cloud-three-source-queue.service/control-token',
            '/etc/suxios-cloud-browser/control-token',
        ], true)
    ) {
        throw new RuntimeException('cloud_ota_collection_arguments_invalid');
    }
    $platformHotelId = platformHotelId($platform, $source['config']);
    $profileBindingKey = profileBindingKey($platform, $source['config']);
    if ($platformHotelId === '' || $profileBindingKey === '') {
        throw new RuntimeException('cloud_ota_data_source_binding_missing');
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
        throw new RuntimeException('cloud_ota_collection_preflight_unverified');
    }

    $controlToken = trim((string)@file_get_contents($tokenFile));
    if (strlen($controlToken) < 32) {
        throw new RuntimeException('cloud_ota_collection_control_token_unavailable');
    }

    $opened = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/open', [
        'profile_id' => $profileId,
        'platform' => $platform,
        'data_source_id' => $sourceId,
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'owner_user_id' => $ownerUserId,
        'target_date' => $targetDate,
        'collection_kind' => 'ota_channel_profile',
        'data_period' => $dataPeriod,
        'access_mode' => 'read_only',
    ], 90);
    $gatewayOpenAccepted = ($opened['status'] ?? '') === 'collection_open';
    if ($gatewayOpenAccepted) {
        // Claim the session identity before checking the remaining response
        // contract. A version-skewed or malformed open response may still
        // have started Chromium and occupied the single gateway slot; the
        // finally block must then close or abort that exact Profile instead
        // of leaking capacity until the gateway TTL expires.
        $collectionSessionId = opaqueIdOrThrow(
            (string)($opened['collection_session_id'] ?? ''),
            'cbcs_',
            'cloud_ota_collection_session_invalid'
        );
    }
    if (($opened['status'] ?? '') !== 'collection_open'
        || ($opened['collector_read_only_contract'] ?? null) !== true
        || ($opened['read_only_enforced'] ?? null) !== true
        || ($opened['network_freshness_control']['http_cache_disabled'] ?? null) !== true
        || ($opened['network_freshness_control']['service_worker_bypassed'] ?? null) !== true
        || (int)($opened['data_source_id'] ?? 0) !== $sourceId
        || (string)($opened['platform'] ?? '') !== $platform
        || (string)($opened['target_date'] ?? '') !== $targetDate
        || (string)($opened['collection_kind'] ?? '') !== 'ota_channel_profile'
        || (string)($opened['data_period'] ?? '') !== $dataPeriod
        || (string)($opened['source_scope'] ?? '') !== 'ota_channel'
        || ($opened['browser_started'] ?? null) !== true
    ) {
        throw new RuntimeException('cloud_ota_gateway_open_unverified');
    }

    $captureOptions = [
        'cdp_url' => $cdpUrl,
        'data_date' => $targetDate,
        'data_period' => $dataPeriod,
        'snapshot_time' => (new DateTimeImmutable(
            'now',
            new DateTimeZone(OTA_CLOUD_COLLECTION_TIMEZONE)
        ))->format('Y-m-d H:i:s'),
        'interactive_browser' => false,
        'timeout_seconds' => $timeoutSeconds,
        'profile_status' => 'ready',
        'required_platform_hotel_id' => $platformHotelId,
        'require_current_run_session_probe' => true,
        'trigger_type' => $dispatcherRunId !== '' ? 'daily_profile_reuse' : 'cloud_browser_profile',
    ];
    if ($dispatcherRunId !== '') {
        $captureOptions['dispatcher_run_id'] = $dispatcherRunId;
    }
    if ($platform === 'ctrip') {
        $captureOptions['collector_flow'] = $historicalCollection ? 'historical' : 'realtime';
        $captureOptions['capture_plan'] = $historicalCollection ? 'historical_review' : 'realtime';
        $captureOptions['capture_sections'] = 'business_overview,traffic_report';
        $captureOptions['bounded_capture_sections'] = 'business_overview,traffic_report';
        // The cloud gateway guards exactly one page target. Keep formal cloud
        // collection on that page so no unguarded popup/parallel target can
        // issue a request outside the gateway Fetch policy.
        $captureOptions['ctrip_section_concurrency'] = 1;
        $captureOptions['sequential_sections'] = true;
        $captureAdapter = new CtripBrowserProfileDataSourceAdapter($root);
    } else {
        // Read exactly one policy-approved period; never traverse unrelated
        // 7-day, 30-day or peer-ranking tabs in the formal queue.
        $captureOptions['capture_sections'] = $historicalCollection
            ? implode(',', OtaOrderedCollectionPlanner::defaultSections('meituan'))
            : 'traffic';
        $captureOptions['capture_mode'] = 'temporal_summary';
        $captureOptions['temporal_scope'] = $historicalCollection ? 'yesterday' : 'current_day';
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
    // The loopback CDP endpoint is a collection-control address, not OTA
    // source metadata. The one-shot adapter already owns the captured result,
    // so never pass that local URL into the persistence pipeline's OTA URL
    // allowlist checks.
    $syncOptions = $captureOptions;
    unset($syncOptions['cdp_url']);
    $syncResult = (new PlatformDataSyncService([$trustedAdapter], null, $proofService))
        ->syncDataSource($syncUser, $sourceId, $syncOptions);
    $syncTaskRecorded = true;
    $savedCount = (int)($syncResult['saved_count'] ?? 0);
    $readbackCount = (int)($syncResult['readback_count'] ?? 0);
    $businessDataPersisted = $savedCount > 0
        && $readbackCount === $savedCount
        && ($syncResult['readback_verified'] ?? null) === true
        && ($syncResult['rolled_back'] ?? false) !== true;
    if (!$businessDataPersisted
        || $readbackCount !== $savedCount
    ) {
        throw new RuntimeException('cloud_ota_formal_readback_unverified');
    }
    $runReadback = is_array($syncResult['run_readback'] ?? null)
        ? $syncResult['run_readback']
        : [];
    $diagnostics = is_array($syncResult['sync_diagnostics'] ?? null)
        ? $syncResult['sync_diagnostics']
        : [];
    $quality = is_array($syncResult['collection_quality'] ?? null)
        ? $syncResult['collection_quality']
        : [];
    $taskId = (int)($syncResult['task_id'] ?? 0);
    if ((string)($syncResult['status'] ?? '') !== 'success'
        || (string)($quality['primary_quality_state'] ?? '') !== 'available'
        || (string)($diagnostics['p0_status'] ?? '') !== 'ready'
        || (string)($diagnostics['field_fact_status'] ?? '') !== 'ready'
        || (array)($diagnostics['missing_traffic_metric_keys'] ?? []) !== []
        || ($runReadback['readback_verified'] ?? null) !== true
        || (int)($runReadback['sync_task_id'] ?? 0) !== $taskId
        || (int)($runReadback['data_source_id'] ?? 0) !== $sourceId
        || (int)($runReadback['system_hotel_id'] ?? 0) !== $hotelId
        || (string)($runReadback['platform'] ?? '') !== $platform
        || (string)($runReadback['target_date'] ?? '') !== $targetDate
        || (string)($runReadback['data_period'] ?? '') !== $dataPeriod
        || (string)($runReadback['p0_status'] ?? '') !== 'ready'
        || (string)($runReadback['field_fact_status'] ?? '') !== 'ready'
        || (string)($runReadback['page_field_fact_status'] ?? '') !== 'ready'
        || (string)($runReadback['platform_hotel_identifier_status'] ?? '') !== 'ready'
        || (array)($runReadback['missing_traffic_metric_keys'] ?? []) !== []
        || sanitizedTraceIds($runReadback['source_trace_ids'] ?? []) === []
        || (int)($runReadback['readback_count'] ?? 0) <= 0
        || count((array)($runReadback['row_ids'] ?? [])) !== (int)($runReadback['readback_count'] ?? 0)
    ) {
        $postSyncFailure = true;
        throw new RuntimeException('cloud_ota_required_fields_or_exact_run_readback_incomplete');
    }
    $historicalCoreContractStatus = $historicalCollection
        && (new OtaHistoricalCoreReadbackVerifier())->verify(
            $platform,
            $tenantId,
            $sourceId,
            $hotelId,
            $targetDate,
            $dataPeriod,
            $runReadback
        )
        ? 'ready'
        : ($historicalCollection ? 'blocked' : 'not_required');
    if ($historicalCollection && $historicalCoreContractStatus !== 'ready') {
        $postSyncFailure = true;
        throw new RuntimeException('cloud_ota_historical_core_contract_incomplete');
    }
    $fieldFactsHash = hash('sha256', json_encode([
        'p0_status' => $runReadback['p0_status'],
        'field_fact_status' => $runReadback['field_fact_status'],
        'required_traffic_metric_keys' => $runReadback['required_traffic_metric_keys'] ?? [],
        'complete_traffic_metric_keys' => $runReadback['complete_traffic_metric_keys'] ?? [],
        'missing_traffic_metric_keys' => [],
        'row_ids' => $runReadback['row_ids'],
        'data_period' => $dataPeriod,
        'historical_core_contract_status' => $historicalCoreContractStatus,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $closeOutcome = 'completed';
    $result = [
        'status' => 'saved_and_readback_verified',
        'platform' => $platform,
        'data_source_id' => $sourceId,
        'hotel_id' => $hotelId,
        'target_date' => $targetDate,
        'run_mode' => $runMode,
        'data_period' => $dataPeriod,
        'collection_kind' => 'ota_channel_profile',
        'historical_core_contract_status' => $historicalCoreContractStatus,
        'task_id' => $taskId,
        'normalized_count' => (int)($syncResult['normalized_count'] ?? 0),
        'saved_count' => $savedCount,
        'readback_count' => $readbackCount,
        'readback_verified' => true,
        'run_readback' => $runReadback,
        'sync_diagnostics' => $diagnostics,
        'collection_quality' => $quality,
        'field_facts_sha256' => $fieldFactsHash,
        'dispatcher_run_id' => $dispatcherRunId !== '' ? $dispatcherRunId : null,
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
                || (string)($closed['collection_session_id'] ?? '') !== $collectionSessionId
                || ($closed['profile_sealed'] ?? null) !== true
                || ($closed['browser_started'] ?? null) !== false
            ) {
                throw new RuntimeException('cloud_ota_collection_profile_close_unverified');
            }
            $closeReceiptId = opaqueId(
                (string)($closed['receipt_id'] ?? ''),
                'cbr_',
                'cloud_ota_collection_close_receipt_id_invalid'
            );
            $closeReceiptHash = strtolower(trim((string)($closed['receipt_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $closeReceiptHash) !== 1) {
                throw new RuntimeException('cloud_ota_collection_close_receipt_hash_invalid');
            }
        } catch (Throwable $closeError) {
            $failureReason = $failureReason ?? safeReason($closeError->getMessage());
            $failureReason = safeReason($failureReason . '_profile_close_failed');
            if (!abortGatewayCollection($gatewayUrl, $controlToken, $profileId, $collectionSessionId)) {
                $failureReason = safeReason($failureReason . '_gateway_abort_unverified');
            }
            $postSyncFailure = $syncTaskRecorded;
            $result = null;
        }
    } elseif ($gatewayOpenAccepted && $controlToken !== '') {
        if (!abortGatewayCollection($gatewayUrl, $controlToken, $profileId)) {
            $failureReason = $failureReason ?? 'cloud_ota_collection_profile_abort_unverified';
            $failureReason = safeReason($failureReason . '_profile_close_failed');
            $postSyncFailure = $syncTaskRecorded;
            $result = null;
        }
    }
}
if ($failureReason !== null || !is_array($result)) {
    $failureReceiptPersisted = $syncTaskRecorded;
    if (!$syncTaskRecorded || $postSyncFailure) {
        $failureReceiptPersisted = persistCloudProfileFailure(
            $failureReceiptService,
            $syncUser,
            $sourceId,
            safeReason((string)$failureReason),
            $failureOptions
        );
    }
    $controlToken = str_repeat("\0", strlen($controlToken));
    fail($failureReason, $businessDataPersisted, $failureReceiptPersisted);
}
$taskPublicId = 'cct_task_' . str_pad((string)$result['task_id'], 8, '0', STR_PAD_LEFT);
try {
    $gatewayReceipt = gatewayRequest($gatewayUrl, $controlToken, '/v1/collection/receipt', [
        'task_id' => $taskPublicId,
        'collection_session_id' => $collectionSessionId,
        'profile_id' => $profileId,
        'platform' => $platform,
        'tenant_id' => $tenantId,
        'hotel_id' => $hotelId,
        'owner_user_id' => $ownerUserId,
        'data_source_id' => $sourceId,
        'target_date' => $targetDate,
        'data_period' => $dataPeriod,
        'collection_kind' => 'ota_channel_profile',
        'close_receipt_id' => $closeReceiptId,
        'close_receipt_hash' => $closeReceiptHash,
        'source_method' => 'cloud_browser_profile',
        'status' => 'saved',
        'identity_verified' => true,
        'saved_count' => (int)$result['saved_count'],
        'readback_count' => (int)$result['readback_count'],
        'field_facts_sha256' => (string)$result['field_facts_sha256'],
    ]);
    $gatewayReceiptId = opaqueId(
        (string)($gatewayReceipt['receipt_id'] ?? ''),
        'cbr_',
        'cloud_ota_gateway_receipt_id_invalid'
    );
    $gatewayReceiptHash = strtolower(trim((string)($gatewayReceipt['receipt_hash'] ?? '')));
    $gatewayReceiptReadback = gatewayReadReceipt(
        $gatewayUrl,
        $controlToken,
        $gatewayReceiptId
    );
    $gatewayReceiptPayload = $gatewayReceiptReadback['payload'] ?? null;
    if (($gatewayReceipt['status'] ?? '') !== 'accepted'
        || preg_match('/^[a-f0-9]{64}$/D', $gatewayReceiptHash) !== 1
        || !hash_equals($gatewayReceiptHash, strtolower(trim((string)($gatewayReceiptReadback['receipt_hash'] ?? ''))))
        || (string)($gatewayReceiptReadback['kind'] ?? '') !== 'collection_result'
        || !is_array($gatewayReceiptPayload)
        || (string)($gatewayReceiptPayload['task_id'] ?? '') !== $taskPublicId
        || (string)($gatewayReceiptPayload['collection_session_id'] ?? '') !== $collectionSessionId
        || (string)($gatewayReceiptPayload['profile_id'] ?? '') !== $profileId
        || (string)($gatewayReceiptPayload['platform'] ?? '') !== $platform
        || (int)($gatewayReceiptPayload['tenant_id'] ?? 0) !== $tenantId
        || (int)($gatewayReceiptPayload['hotel_id'] ?? 0) !== $hotelId
        || (int)($gatewayReceiptPayload['owner_user_id'] ?? 0) !== $ownerUserId
        || (int)($gatewayReceiptPayload['data_source_id'] ?? 0) !== $sourceId
        || (string)($gatewayReceiptPayload['target_date'] ?? '') !== $targetDate
        || (string)($gatewayReceiptPayload['data_period'] ?? '') !== $dataPeriod
        || (string)($gatewayReceiptPayload['collection_kind'] ?? '') !== 'ota_channel_profile'
        || (string)($gatewayReceiptPayload['close_receipt_id'] ?? '') !== $closeReceiptId
        || !hash_equals($closeReceiptHash, strtolower(trim((string)($gatewayReceiptPayload['close_receipt_hash'] ?? ''))))
        || (string)($gatewayReceiptPayload['status'] ?? '') !== 'saved'
        || ($gatewayReceiptPayload['identity_verified'] ?? null) !== true
        || (int)($gatewayReceiptPayload['saved_count'] ?? -1) !== (int)$result['saved_count']
        || (int)($gatewayReceiptPayload['readback_count'] ?? -1) !== (int)$result['readback_count']
        || !hash_equals(
            (string)$result['field_facts_sha256'],
            strtolower(trim((string)($gatewayReceiptPayload['field_facts_sha256'] ?? '')))
        )
    ) {
        throw new RuntimeException('cloud_ota_gateway_receipt_readback_unverified');
    }
    $result['gateway_receipt_id'] = $gatewayReceiptId;
    $result['gateway_receipt_hash'] = $gatewayReceiptHash;
    $result['gateway_receipt_readback_verified'] = true;
} catch (Throwable $receiptError) {
    $reason = safeReason($receiptError->getMessage());
    $failureReceiptPersisted = persistCloudProfileFailure(
        $failureReceiptService,
        $syncUser,
        $sourceId,
        $reason,
        $failureOptions
    );
    $controlToken = str_repeat("\0", strlen($controlToken));
    fail($reason, true, $failureReceiptPersisted);
}
$controlToken = str_repeat("\0", strlen($controlToken));
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
            $statusCode = (string)($messageParts[0] ?? $sanitizedMessage);
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
function gatewayRequest(
    string $baseUrl,
    string $token,
    string $path,
    array $body,
    int $timeoutSeconds = 30
): array
{
    $timeoutSeconds = max(1, min(120, $timeoutSeconds));
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'timeout' => $timeoutSeconds,
        'ignore_errors' => true,
    ]]);
    error_clear_last();
    $raw = @file_get_contents($baseUrl . $path, false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        throw new RuntimeException(is_array($decoded)
            ? (string)($decoded['reason'] ?? 'cloud_ota_gateway_failed')
            : gatewayTransportFailureCode('cloud_ota_gateway_failed'));
    }
    return $decoded;
}

function abortGatewayCollection(
    string $baseUrl,
    string $token,
    string $profileId,
    ?string $collectionSessionId = null
): bool
{
    if ($token === '' || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1) {
        return false;
    }
    try {
        $payload = [
            'profile_public_id' => $profileId,
        ];
        if ($collectionSessionId !== null) {
            $payload['collection_session_id'] = $collectionSessionId;
        }
        $aborted = gatewayRequest($baseUrl, $token, '/v1/collection/abort', $payload);
        return in_array((string)($aborted['status'] ?? ''), ['aborted', 'no_active_collection'], true)
            && ($aborted['cleanup_verified'] ?? null) === true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed> */
function gatewayReadReceipt(string $baseUrl, string $token, string $receiptId): array
{
    $receiptId = opaqueId($receiptId, 'cbr_', 'cloud_ota_gateway_receipt_id_invalid');
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    error_clear_last();
    $raw = @file_get_contents($baseUrl . '/v1/receipts/' . rawurlencode($receiptId), false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || ($decoded['status'] ?? '') === 'failed') {
        throw new RuntimeException(is_array($decoded)
            ? 'cloud_ota_gateway_receipt_readback_failed'
            : gatewayTransportFailureCode('cloud_ota_gateway_receipt_readback_failed'));
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

/** @param array<string,mixed> $options */
function persistCloudProfileFailure(
    PlatformDataSyncService $service,
    object $user,
    int $sourceId,
    string $failureCode,
    array $options
): bool {
    try {
        $receipt = $service->recordCloudProfileCollectionFailure(
            $user,
            $sourceId,
            $failureCode,
            $options
        );
        return (string)($receipt['status'] ?? '') === 'failed'
            && (int)($receipt['data_source_id'] ?? 0) === $sourceId
            && (int)($receipt['task_id'] ?? 0) > 0;
    } catch (Throwable) {
        return false;
    }
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

/** @return list<string> */
function sanitizedTraceIds(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }
    $traceIds = [];
    foreach ($values as $value) {
        $traceId = trim((string)$value);
        if (preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $traceId) === 1) {
            $traceIds[$traceId] = true;
        }
    }
    return array_slice(array_keys($traceIds), 0, 50);
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

function opaqueIdOrThrow(string $value, string $prefix, string $reason): string
{
    $value = trim($value);
    if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
        throw new RuntimeException($reason);
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

function fail(
    string $reason,
    bool $persisted = false,
    bool $failureReceiptPersisted = false
): never
{
    fwrite(STDERR, json_encode([
        'status' => 'blocked',
        'reason' => safeReason($reason),
        'business_data_persisted' => $persisted,
        'failure_receipt_persisted' => $failureReceiptPersisted,
        'message_sent' => false,
        'sensitive_values_exposed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
