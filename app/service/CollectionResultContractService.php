<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds one evidence-led result envelope without forcing platform collectors
 * to share request, session or persistence implementations.
 */
final class CollectionResultContractService
{
    public const CONTRACT_VERSION = 'suxios.collection_result.v1';

    private const STRATEGIES = [
        'verified_endpoint_recipe',
        'browser_response',
        'dom_fallback',
        'not_recorded',
    ];

    private const DINGDANDAO_METRICS = [
        'total_room_fee' => ['value_type' => 'decimal', 'unit' => 'cny', 'derived' => false],
        'adr' => ['value_type' => 'decimal', 'unit' => 'cny_per_room_night', 'derived' => false],
        'occupancy_rate_percent' => ['value_type' => 'decimal', 'unit' => 'percent', 'derived' => false],
        'revpar' => ['value_type' => 'decimal', 'unit' => 'cny_per_sellable_room', 'derived' => false],
        'sold_room_nights' => ['value_type' => 'integer', 'unit' => 'room_night', 'derived' => false],
        'average_daily_room_nights' => ['value_type' => 'decimal', 'unit' => 'room_night', 'derived' => false],
        'derived_sellable_room_nights' => ['value_type' => 'integer', 'unit' => 'room_night', 'derived' => true],
    ];

    /**
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    public function fromDingdandaoCapture(array $capture): array
    {
        $evidence = $this->dingdandaoCaptureEvidence($capture);
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
        $sourceScope = strtolower(trim((string)($capture['source_scope'] ?? '')));
        $captureMethod = strtolower(trim((string)($capture['capture_method'] ?? '')));
        $tenantId = $this->positiveIntOrNull($capture['tenant_id'] ?? null);
        $hotelId = $this->positiveIntOrNull($capture['hotel_id'] ?? null);
        $captureId = $this->positiveIntOrNull($capture['id'] ?? null);
        $platformHotelId = $this->textOrNull($capture['provider_hotel_id'] ?? null, 120);
        $dataDate = $this->dateOrNull($capture['business_date'] ?? null);
        $collectedAt = $this->dateTimeOrNull($capture['captured_at'] ?? null);
        $sourceTraceId = $this->referenceOrNull(
            $capture['source_trace_id'] ?? $evidence['source_trace_id'] ?? null,
            200
        );
        $sourceFingerprint = $this->sha256OrNull($capture['source_fingerprint'] ?? null);
        $businessModule = $this->textOrNull(
            $evidence['business_module'] ?? null,
            80
        );
        $sourceMethod = $this->textOrNull(
            $evidence['source_method'] ?? null,
            80
        );
        $captureStatus = strtolower(trim((string)($capture['capture_status'] ?? $capture['status'] ?? 'missing')));
        $qualityStatus = strtolower(trim((string)($capture['quality_status'] ?? 'missing')));
        $identityMatched = strtolower(trim((string)($capture['identity_status'] ?? ''))) === 'matched';
        $reconciliationMatched =
            strtolower(trim((string)($capture['reconciliation_status'] ?? ''))) === 'matched';
        $declaredReadbackVerified =
            strtolower(trim((string)($capture['readback_status'] ?? ''))) === 'readback_verified';
        $detailCount = $this->nonNegativeIntOrNull($capture['detail_row_count'] ?? null);
        $readbackVerified = $declaredReadbackVerified
            && $captureId !== null
            && $detailCount !== null;
        $strategy = $this->strategyFromCapture($capture, $evidence);

        $blockers = [];
        foreach (is_array($capture['gaps'] ?? null) ? $capture['gaps'] : [] as $gap) {
            $code = is_array($gap) ? ($gap['code'] ?? null) : $gap;
            $code = $this->reasonCodeOrNull($code);
            if ($code !== null) {
                $blockers[] = $code;
            }
        }
        $operatingCore = is_array(
            $capture['component_coverage']['components']['operating_core'] ?? null
        )
            ? $capture['component_coverage']['components']['operating_core']
            : [];
        if ($operatingCore !== []
            && ($operatingCore['status'] ?? '') !== 'verified'
        ) {
            $blockers[] = 'pms_operating_core_not_verified';
            $blockers = array_merge(
                $blockers,
                $this->safeReasonList($operatingCore['gap_codes'] ?? [])
            );
        }
        if ($tenantId === null || $hotelId === null) {
            $blockers[] = 'collection_scope_missing';
        }
        if ($platformHotelId === null) {
            $blockers[] = 'binding_missing';
        }
        if ($businessModule === null) {
            $blockers[] = 'business_module_missing';
        }
        if ($sourceMethod === null) {
            $blockers[] = 'source_method_missing';
        }
        if ($captureId === null) {
            $blockers[] = 'capture_persistence_missing';
        }
        if ($detailCount === null) {
            $blockers[] = 'detail_row_count_missing';
        }
        if (!$identityMatched) {
            $blockers[] = 'hotel_identity_mismatch';
        }
        if ($dataDate === null) {
            $blockers[] = 'target_date_unverified';
        }
        if ($collectedAt === null) {
            $blockers[] = 'collection_time_missing';
        }
        if ($captureStatus !== 'verified') {
            $blockers[] = $captureStatus === 'missing'
                ? 'collection_missing'
                : 'collection_not_verified';
        }
        if ($qualityStatus !== 'verified') {
            $blockers[] = $qualityStatus === 'missing'
                ? 'field_missing'
                : 'quality_not_verified';
        }
        foreach (array_slice(array_keys(self::DINGDANDAO_METRICS), 0, 6) as $metricKey) {
            if (!is_int($summary[$metricKey] ?? null)
                && !is_float($summary[$metricKey] ?? null)
            ) {
                $blockers[] = 'field_missing';
            }
        }
        if (!$reconciliationMatched) {
            $blockers[] = 'reconciliation_not_matched';
        }
        if (!$readbackVerified) {
            $blockers[] = 'readback_mismatch';
        }
        if ($sourceTraceId === null) {
            $blockers[] = 'source_trace_missing';
        }
        if (!in_array(
            $sourceScope,
            [
                DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
                DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            ],
            true
        )) {
            $blockers[] = 'source_scope_unverified';
        }
        if ($captureMethod !== 'network_response') {
            $blockers[] = 'collection_method_unverified';
        }
        if ($sourceFingerprint === null) {
            $blockers[] = 'source_fingerprint_missing';
        }
        if (($strategy['status'] ?? '') !== 'verified') {
            $blockers[] = 'collection_strategy_unverified';
        }
        if (!$this->dingdandaoSourceEvidenceMatches($capture)) {
            $blockers[] = 'source_evidence_mismatch';
        }
        $blockers = $this->uniqueReasonCodes($blockers);
        $claimAllowed = $blockers === [];

        $metrics = [];
        foreach (self::DINGDANDAO_METRICS as $metricKey => $definition) {
            $value = $summary[$metricKey] ?? null;
            $metrics[] = [
                'metric_key' => $metricKey,
                'value' => is_int($value) || is_float($value) ? $value : null,
                'value_type' => $definition['value_type'],
                'unit' => $definition['unit'],
                'status' => $value === null
                    ? 'missing'
                    : ($claimAllowed
                        ? ($definition['derived'] ? 'derived' : 'verified')
                        : 'unverified'),
                'source_path' => $this->textOrNull(
                    is_array($capture['field_trace'] ?? null)
                        ? ($capture['field_trace'][$metricKey] ?? null)
                        : null,
                    300
                ),
            ];
        }

        $persistedCount = $captureId !== null && $readbackVerified
            ? 1 + (int)$detailCount
            : null;
        $collectionStatus = $claimAllowed
            ? 'verified'
            : ($captureStatus === 'missing'
                ? 'missing'
                : (in_array($qualityStatus, ['partial', 'unverified', 'missing'], true)
                    ? 'partial'
                    : 'blocked'));

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'scope' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'platform' => 'dingdandao_pms',
                'platform_hotel_id' => $platformHotelId,
                'business_module' => $businessModule,
                'data_date' => $dataDate,
                'target_stay_date' => null,
                'date_role' => 'business_date',
                'source_method' => $sourceMethod,
                'source_scope' => $sourceScope !== '' ? $sourceScope : null,
            ],
            'run' => [
                'run_id' => $captureId,
                'collected_at' => $collectedAt,
                'strategy' => $strategy,
            ],
            'identity_status' => $identityMatched ? 'matched' : 'blocked',
            'collection_status' => $collectionStatus,
            'quality_status' => $this->qualityStatus($qualityStatus, $claimAllowed),
            'metrics' => $metrics,
            'counts' => [
                'observed' => count(array_filter(
                    $metrics,
                    static fn(array $metric): bool => $metric['value'] !== null
                )),
                'normalized' => count($metrics),
                'saved' => $persistedCount,
                'readback' => $persistedCount,
            ],
            'saved_count' => $persistedCount,
            'readback_status' => $readbackVerified
                ? 'readback_verified'
                : ($captureId === null ? 'not_attempted' : 'readback_failed'),
            'snapshot_ref' => $captureId === null
                ? null
                : 'dingdandao_operating_target_capture#' . $captureId,
            'previous_comparable_ref' => null,
            'references' => [
                'capture_id' => $captureId,
                'row_ids' => [],
                'source_trace_ids' => $sourceTraceId === null ? [] : [$sourceTraceId],
                'source_fingerprint' => $sourceFingerprint,
            ],
            'blockers' => $blockers,
            'claim' => [
                'allowed' => $claimAllowed,
                'reason_codes' => $blockers,
            ],
            'sensitive_material_exposed' => false,
        ];
    }

    /**
     * Rebuilds the PMS collection contract from the persisted capture and
     * verifies that any declared envelope still describes the same run.
     * Callers may provide the exact scope they are about to consume.
     *
     * Missing collection_result remains compatible with trusted in-memory
     * fixtures because the envelope is rebuilt from the same strict evidence.
     *
     * @param array<string,mixed> $capture
     * @param array<string,mixed> $expectedScope
     * @return array{allowed:bool,reason_codes:list<string>,contract:array<string,mixed>}
     */
    public function validateDingdandaoCaptureClaim(
        array $capture,
        array $expectedScope = []
    ): array {
        $contract = $this->fromDingdandaoCapture($capture);
        $declared = is_array($capture['collection_result'] ?? null)
            ? $capture['collection_result']
            : $contract;
        $reasonCodes = [];

        if (($contract['claim']['allowed'] ?? false) !== true) {
            $reasonCodes = array_merge(
                $reasonCodes,
                $this->safeReasonList(
                    $contract['claim']['reason_codes']
                        ?? $contract['blockers']
                        ?? []
                )
            );
        }
        if (($declared['claim']['allowed'] ?? false) !== true) {
            $reasonCodes[] = 'collection_claim_not_allowed';
            $reasonCodes = array_merge(
                $reasonCodes,
                $this->safeReasonList(
                    $declared['claim']['reason_codes']
                        ?? $declared['blockers']
                        ?? []
                )
            );
        }
        if ($this->dingdandaoCriticalContract($declared)
            !== $this->dingdandaoCriticalContract($contract)
        ) {
            $reasonCodes[] = 'collection_contract_mismatch';
        }

        $scope = is_array($contract['scope'] ?? null)
            ? $contract['scope']
            : [];
        $run = is_array($contract['run'] ?? null)
            ? $contract['run']
            : [];
        $references = is_array($contract['references'] ?? null)
            ? $contract['references']
            : [];
        $expectedTenantId = $this->positiveIntOrNull(
            $expectedScope['tenant_id'] ?? null
        );
        $expectedHotelId = $this->positiveIntOrNull(
            $expectedScope['system_hotel_id']
                ?? $expectedScope['hotel_id']
                ?? null
        );
        $expectedDate = $this->dateOrNull(
            $expectedScope['data_date']
                ?? $expectedScope['business_date']
                ?? null
        );
        $expectedPlatformHotelId = $this->textOrNull(
            $expectedScope['platform_hotel_id']
                ?? $expectedScope['provider_hotel_id']
                ?? null,
            120
        );
        $expectedSourceScope = strtolower(trim((string)(
            $expectedScope['source_scope'] ?? ''
        )));

        if ($expectedTenantId !== null
            && (int)($scope['tenant_id'] ?? 0) !== $expectedTenantId
        ) {
            $reasonCodes[] = 'tenant_scope_mismatch';
        }
        if ($expectedHotelId !== null
            && (int)($scope['system_hotel_id'] ?? 0) !== $expectedHotelId
        ) {
            $reasonCodes[] = 'hotel_scope_mismatch';
        }
        if ($expectedDate !== null
            && (string)($scope['data_date'] ?? '') !== $expectedDate
        ) {
            $reasonCodes[] = 'target_date_scope_mismatch';
        }
        if ($expectedPlatformHotelId !== null
            && !hash_equals(
                $expectedPlatformHotelId,
                (string)($scope['platform_hotel_id'] ?? '')
            )
        ) {
            $reasonCodes[] = 'platform_hotel_scope_mismatch';
        }
        if ($expectedSourceScope !== ''
            && (string)($scope['source_scope'] ?? '') !== $expectedSourceScope
        ) {
            $reasonCodes[] = 'source_scope_mismatch';
        }

        $captureId = $this->positiveIntOrNull($capture['id'] ?? null);
        $sourceFingerprint = $this->sha256OrNull(
            $capture['source_fingerprint'] ?? null
        );
        if (($contract['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || (string)($scope['platform'] ?? '') !== 'dingdandao_pms'
            || (string)($scope['business_module'] ?? '')
                !== 'accommodation_operating'
            || (string)($scope['date_role'] ?? '') !== 'business_date'
            || (string)($scope['source_method'] ?? '')
                !== 'authorized_browser_endpoint'
            || (string)($contract['identity_status'] ?? '') !== 'matched'
            || (string)($contract['collection_status'] ?? '') !== 'verified'
            || (string)($contract['quality_status'] ?? '') !== 'verified'
        ) {
            $reasonCodes[] = 'collection_contract_not_verified';
        }
        if ($captureId === null
            || (int)($run['run_id'] ?? 0) !== $captureId
            || (int)($references['capture_id'] ?? 0) !== $captureId
            || (string)($contract['snapshot_ref'] ?? '')
                !== 'dingdandao_operating_target_capture#' . $captureId
        ) {
            $reasonCodes[] = 'capture_reference_mismatch';
        }
        if ((string)($contract['readback_status'] ?? '')
                !== 'readback_verified'
            || !is_numeric($contract['saved_count'] ?? null)
            || (int)$contract['saved_count'] <= 0
            || (int)($contract['counts']['saved'] ?? 0)
                !== (int)$contract['saved_count']
            || (int)($contract['counts']['readback'] ?? 0)
                !== (int)$contract['saved_count']
        ) {
            $reasonCodes[] = 'readback_mismatch';
        }
        if ($sourceFingerprint === null
            || !hash_equals(
                $sourceFingerprint,
                (string)($references['source_fingerprint'] ?? '')
            )
            || !is_array($references['source_trace_ids'] ?? null)
            || $references['source_trace_ids'] === []
        ) {
            $reasonCodes[] = 'source_reference_mismatch';
        }

        if (!$this->dingdandaoSourceEvidenceMatches($capture)) {
            $reasonCodes[] = 'source_evidence_mismatch';
        }

        $reasonCodes = $this->uniqueReasonCodes($reasonCodes);
        return [
            'allowed' => $reasonCodes === [],
            'reason_codes' => $reasonCodes,
            'contract' => $contract,
        ];
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function dingdandaoCriticalContract(array $contract): array
    {
        return [
            'contract_version' => $contract['contract_version'] ?? null,
            'scope' => $contract['scope'] ?? null,
            'run' => $contract['run'] ?? null,
            'identity_status' => $contract['identity_status'] ?? null,
            'collection_status' => $contract['collection_status'] ?? null,
            'quality_status' => $contract['quality_status'] ?? null,
            'metrics' => $contract['metrics'] ?? null,
            'counts' => $contract['counts'] ?? null,
            'saved_count' => $contract['saved_count'] ?? null,
            'readback_status' => $contract['readback_status'] ?? null,
            'snapshot_ref' => $contract['snapshot_ref'] ?? null,
            'references' => $contract['references'] ?? null,
            'blockers' => $contract['blockers'] ?? null,
            'claim' => $contract['claim'] ?? null,
        ];
    }

    /** @param array<string,mixed> $capture */
    private function dingdandaoSourceEvidenceMatches(array $capture): bool
    {
        $evidence = $this->dingdandaoCaptureEvidence($capture);
        $providerHotelId = $this->textOrNull(
            $capture['provider_hotel_id'] ?? null,
            120
        );
        $businessDate = $this->dateOrNull($capture['business_date'] ?? null);
        $sourceUrl = trim((string)($capture['source_url'] ?? ''));
        $sourceApiPath = trim((string)($capture['source_api_path'] ?? ''));
        $sourceTraceId = $this->referenceOrNull(
            $capture['source_trace_id'] ?? $evidence['source_trace_id'] ?? null,
            200
        );
        $collectionMode = strtolower(trim((string)(
            $capture['collection_mode']
                ?? $evidence['collection_mode']
                ?? ''
        )));
        $sourceScope = strtolower(trim((string)($capture['source_scope'] ?? '')));
        $capturedAt = $this->dateTimeOrNull($capture['captured_at'] ?? null);
        $capturedDate = $capturedAt === null ? null : substr($capturedAt, 0, 10);
        $sourceDateScopeMatches =
            $sourceScope === DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE
                ? $capturedDate === $businessDate
                : (
                    $sourceScope
                        === DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE
                    && $collectionMode === 'operating_indicators'
                    && $capturedDate !== null
                    && $businessDate !== null
                    && $capturedDate >= $businessDate
                );
        $legacyExpectedEvidence =
            DingdandaoOperatingTargetCaptureService::
            expectedLegacyV2CaptureEvidence($capture);
        if (is_array($legacyExpectedEvidence)) {
            return $this->dingdandaoEvidenceExactlyMatches(
                $evidence,
                $legacyExpectedEvidence
            ) && hash_equals(
                (string)$legacyExpectedEvidence['source_trace_id'],
                (string)$sourceTraceId
            );
        }

        $expectedEvidence = $providerHotelId === null
            || $businessDate === null
            ? null
            : DingdandaoOperatingTargetCaptureService::expectedCaptureEvidence(
                $sourceApiPath,
                $businessDate,
                $providerHotelId,
                $collectionMode
            );
        if ((string)($capture['provider'] ?? '')
                !== DingdandaoOperatingTargetCaptureService::PROVIDER
            || $sourceUrl !== DingdandaoOperatingTargetCaptureService::SOURCE_URL
            || !$sourceDateScopeMatches
            || (string)($capture['capture_method'] ?? '') !== 'network_response'
            || $providerHotelId === null
            || $businessDate === null
            || $sourceApiPath === ''
            || !str_starts_with($sourceApiPath, '/')
            || $sourceTraceId === null
            || !in_array(
                $collectionMode,
                ['operating_indicators', 'full_diagnostic'],
                true
            )
            || $expectedEvidence === null
        ) {
            return false;
        }

        return $this->dingdandaoEvidenceExactlyMatches(
            $evidence,
            $expectedEvidence
        ) && hash_equals(
            (string)$expectedEvidence['source_trace_id'],
            $sourceTraceId
        );
    }

    /** @param array<string,mixed> $capture @return array<string,mixed> */
    private function dingdandaoCaptureEvidence(array $capture): array
    {
        $evidence = is_array($capture['capture_evidence'] ?? null)
            ? $capture['capture_evidence']
            : [];
        if ($evidence !== []) {
            return $evidence;
        }
        return DingdandaoOperatingTargetCaptureService::
            expectedLegacyV2CaptureEvidence($capture) ?? [];
    }

    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $expected
     */
    private function dingdandaoEvidenceExactlyMatches(
        array $actual,
        array $expected
    ): bool {
        $actualKeys = array_keys($actual);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }
        foreach ($expected as $key => $expectedValue) {
            $actualValue = $actual[$key] ?? null;
            $matches = is_string($expectedValue)
                ? is_string($actualValue)
                    && hash_equals($expectedValue, $actualValue)
                : $actualValue === $expectedValue;
            if (!$matches) {
                return false;
            }
        }
        return true;
    }

    /**
     * Accepts either an exact run_readback receipt or the outer sync result
     * containing it. Values remain in the authoritative stored rows; this
     * envelope only carries their verified references.
     *
     * @param array<string,mixed> $receiptOrResult
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function fromOtaRunReadback(array $receiptOrResult, array $context = []): array
    {
        $receipt = $receiptOrResult;
        if (is_array($receiptOrResult['run_readback'] ?? null)) {
            $context = array_merge($receiptOrResult, $context);
            $receipt = $receiptOrResult['run_readback'];
        }

        $platform = strtolower(trim((string)($receipt['platform'] ?? '')));
        $taskId = $this->positiveIntOrNull($receipt['sync_task_id'] ?? null);
        $dataSourceId = $this->positiveIntOrNull($receipt['data_source_id'] ?? null);
        $hotelId = $this->positiveIntOrNull($receipt['system_hotel_id'] ?? null);
        $tenantId = $this->positiveIntOrNull($context['tenant_id'] ?? null);
        $targetDate = $this->dateOrNull($receipt['target_date'] ?? null);
        $startedAt = $this->dateTimeOrNull($receipt['started_at'] ?? null);
        $dataPeriod = $this->textOrNull($receipt['data_period'] ?? null, 80);
        $platformHotelId = $this->textOrNull($context['platform_hotel_id'] ?? null, 120);
        $observedPlatformHotelId = $this->textOrNull(
            $receipt['observed_platform_hotel_id'] ?? null,
            120
        );
        $rowIds = $this->positiveUniqueInts($receipt['row_ids'] ?? []);
        $traceIds = $this->safeReferences($receipt['source_trace_ids'] ?? [], 160);
        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        $readbackVerified = ($receipt['readback_verified'] ?? false) === true;
        $declaredIdentityReady =
            strtolower(trim((string)($receipt['platform_hotel_identifier_status'] ?? ''))) === 'ready';
        $identityMatched = $declaredIdentityReady
            && $platformHotelId !== null
            && $observedPlatformHotelId !== null
            && hash_equals($platformHotelId, $observedPlatformHotelId);
        $fieldFactStatus = strtolower(trim((string)($receipt['field_fact_status'] ?? '')));
        $pageFieldFactStatus = strtolower(trim((string)($receipt['page_field_fact_status'] ?? '')));
        $p0Status = strtolower(trim((string)($receipt['p0_status'] ?? '')));
        $missingTraffic = $this->safeReasonList($receipt['missing_traffic_metric_keys'] ?? []);
        $outerStatus = strtolower(trim((string)($context['status'] ?? '')));
        $businessModule = $this->textOrNull($context['business_module'] ?? null, 80);
        $sourceMethod = $this->textOrNull(
            $context['source_method'] ?? $context['ingestion_method'] ?? null,
            80
        );
        $strategy = $this->strategyFromContext([
            'capture_strategy' => $receipt['capture_strategy'] ?? null,
            'fallback_from' => $receipt['fallback_from'] ?? null,
            'fallback_reason' => $receipt['fallback_reason'] ?? null,
            'response_evidence_type' => $receipt['response_evidence_type'] ?? null,
            'recipe_plan_hash' => $receipt['recipe_plan_hash'] ?? null,
            'recipe_count' => $receipt['recipe_count'] ?? null,
        ]);

        $blockers = [];
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            $blockers[] = 'platform_unverified';
        }
        if ($tenantId === null
            || $taskId === null
            || $dataSourceId === null
            || $hotelId === null
        ) {
            $blockers[] = 'collection_scope_missing';
        }
        if ($platformHotelId === null) {
            $blockers[] = 'binding_missing';
        }
        if ($businessModule === null) {
            $blockers[] = 'business_module_missing';
        }
        if ($sourceMethod === null) {
            $blockers[] = 'source_method_missing';
        }
        if (($strategy['status'] ?? '') !== 'verified') {
            $blockers[] = 'collection_strategy_unverified';
        }
        if (!in_array(
            (string)($strategy['selected'] ?? ''),
            ['browser_response', 'verified_endpoint_recipe'],
            true
        ) || (string)($strategy['response_evidence_type'] ?? '')
            !== 'structured_json'
        ) {
            $blockers[] = 'structured_response_required';
        }
        if ($targetDate === null) {
            $blockers[] = 'target_date_unverified';
        }
        if ($startedAt === null) {
            $blockers[] = 'collection_time_missing';
        }
        if ($dataPeriod === null) {
            $blockers[] = 'metric_scope_missing';
        }
        if (!$readbackVerified
            || $rowIds === []
            || $traceIds === []
            || $readbackCount <= 0
            || $readbackCount !== count($rowIds)
        ) {
            $blockers[] = 'readback_mismatch';
        }
        if (!$declaredIdentityReady) {
            $blockers[] = 'hotel_identity_mismatch';
        } elseif ($observedPlatformHotelId === null) {
            $blockers[] = 'platform_hotel_identity_observation_missing';
        } elseif ($platformHotelId !== null
            && !hash_equals($platformHotelId, $observedPlatformHotelId)
        ) {
            $blockers[] = 'hotel_identity_mismatch';
        }
        if ($fieldFactStatus !== 'ready' || $pageFieldFactStatus !== 'ready') {
            $blockers[] = 'field_missing';
        }
        if (!in_array($p0Status, ['ready', 'not_required'], true)) {
            $blockers[] = 'quality_not_verified';
        }
        if ($missingTraffic !== []) {
            $blockers[] = 'response_partial';
        }
        $failureReason = $this->reasonCodeOrNull(
            $receipt['failure_reason'] ?? $context['failure_reason'] ?? null
        );
        if ($failureReason !== null) {
            $blockers[] = $failureReason;
        }
        $this->appendContextMismatch(
            $blockers,
            'task_scope_mismatch',
            $context['task_id'] ?? null,
            $taskId
        );
        $this->appendContextMismatch(
            $blockers,
            'data_source_scope_mismatch',
            $context['data_source_id'] ?? null,
            $dataSourceId
        );
        $this->appendContextMismatch(
            $blockers,
            'hotel_scope_mismatch',
            $context['system_hotel_id'] ?? null,
            $hotelId
        );
        if (isset($context['platform'])
            && strtolower(trim((string)$context['platform'])) !== $platform
        ) {
            $blockers[] = 'platform_scope_mismatch';
        }
        if (isset($context['target_date'])
            && $this->dateOrNull($context['target_date']) !== $targetDate
        ) {
            $blockers[] = 'target_date_scope_mismatch';
        }
        if ($outerStatus !== '' && $outerStatus !== 'success') {
            $blockers[] = $outerStatus === 'partial_success'
                ? 'collection_outcome_partial'
                : 'collection_outcome_not_success';
        }
        $blockers = $this->uniqueReasonCodes($blockers);
        $claimAllowed = $blockers === [];

        $coreMetricKeys = $this->safeMetricKeys($receipt['verified_metric_keys'] ?? []);
        $requiredTrafficMetricKeys = $this->safeTrafficMetricKeys(
            $receipt['required_traffic_metric_keys'] ?? []
        );
        $completeTrafficMetricKeys = $this->safeTrafficMetricKeys(
            $receipt['complete_traffic_metric_keys'] ?? []
        );
        $trafficModule = in_array(
            strtolower((string)$businessModule),
            ['traffic', 'flow', 'conversion'],
            true
        );
        $verifiedMetricKeys = $trafficModule
            ? array_values(array_intersect(
                $requiredTrafficMetricKeys,
                $completeTrafficMetricKeys
            ))
            : $coreMetricKeys;
        $requiredMetricsVerified = $trafficModule
            ? $requiredTrafficMetricKeys !== []
                && array_diff($requiredTrafficMetricKeys, $completeTrafficMetricKeys) === []
            : $verifiedMetricKeys !== [];
        if (!$requiredMetricsVerified) {
            $blockers[] = 'field_missing';
            $blockers = $this->uniqueReasonCodes($blockers);
            $claimAllowed = false;
        }
        $metrics = array_map(static fn(string $metricKey): array => [
            'metric_key' => $metricKey,
            'value' => null,
            'value_type' => null,
            'unit' => null,
            'status' => 'verified_reference',
            'value_in_envelope' => false,
        ], $verifiedMetricKeys);

        $savedCount = isset($context['saved_count']) && is_numeric($context['saved_count'])
            ? max(0, (int)$context['saved_count'])
            : ($readbackVerified ? $readbackCount : null);
        if ($savedCount !== null && $savedCount < $readbackCount) {
            $blockers[] = 'persistence_count_mismatch';
            $blockers = $this->uniqueReasonCodes($blockers);
            $claimAllowed = false;
        }
        $collectionStatus = $claimAllowed
            ? 'verified'
            : ($readbackVerified && $rowIds !== [] ? 'partial' : 'blocked');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'scope' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'platform' => in_array($platform, ['ctrip', 'meituan'], true)
                    ? $platform
                    : null,
                'platform_hotel_id' => $platformHotelId,
                'business_module' => $businessModule,
                'data_date' => $targetDate,
                'target_stay_date' => null,
                'date_role' => 'business_date',
                'source_method' => $sourceMethod,
                'data_period' => $dataPeriod,
            ],
            'run' => [
                'run_id' => $taskId,
                'collected_at' => $startedAt,
                'strategy' => $strategy,
            ],
            'identity_status' => $identityMatched ? 'matched' : 'blocked',
            'collection_status' => $collectionStatus,
            'quality_status' => $claimAllowed
                ? 'verified'
                : ($readbackVerified ? 'partial' : 'blocked'),
            'metrics' => $metrics,
            'counts' => [
                'observed' => count($verifiedMetricKeys),
                'normalized' => isset($context['normalized_count'])
                    ? max(0, (int)$context['normalized_count'])
                    : null,
                'saved' => $savedCount,
                'readback' => $readbackCount,
            ],
            'saved_count' => $savedCount,
            'readback_status' => $readbackVerified
                ? 'readback_verified'
                : 'readback_failed',
            'snapshot_ref' => $taskId === null
                ? null
                : 'online_daily_data#sync_task:' . $taskId,
            'previous_comparable_ref' => null,
            'references' => [
                'capture_id' => null,
                'sync_task_id' => $taskId,
                'data_source_id' => $dataSourceId,
                'row_ids' => $rowIds,
                'source_trace_ids' => $traceIds,
                'observed_platform_hotel_id' => $observedPlatformHotelId,
                'source_fingerprint' => null,
            ],
            'blockers' => $blockers,
            'claim' => [
                'allowed' => $claimAllowed,
                'reason_codes' => $blockers,
            ],
            'sensitive_material_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $capture @param array<string,mixed> $evidence */
    private function strategyFromCapture(array $capture, array $evidence): array
    {
        $selected = strtolower(trim((string)(
            $capture['capture_strategy']
            ?? $evidence['capture_strategy']
            ?? ''
        )));
        if (!in_array($selected, self::STRATEGIES, true) || $selected === 'not_recorded') {
            $selected = ($evidence['source_method'] ?? '') === 'authorized_browser_endpoint'
                && ($evidence['capture_source'] ?? '') === 'existing_session_direct_post'
                ? 'verified_endpoint_recipe'
                : ((string)($capture['capture_method'] ?? '') === 'browser_assist_dom'
                    ? 'dom_fallback'
                    : 'not_recorded');
        }
        $fallbackFrom = $this->strategyOrNull($evidence['fallback_from'] ?? null);
        $fallbackReason = $this->reasonCodeOrNull($evidence['fallback_reason'] ?? null);
        $responseEvidenceType = $this->textOrNull(
            $evidence['response_evidence_type'] ?? null,
            40
        );
        $recipePlanHash = $this->sha256OrNull(
            $evidence['recipe_plan_hash'] ?? null
        );
        $recipeCount = isset($evidence['recipe_count'])
            ? max(0, (int)$evidence['recipe_count'])
            : null;
        return [
            'selected' => $selected,
            'status' => $this->strategyIsVerified(
                $selected,
                $fallbackFrom,
                $fallbackReason,
                $responseEvidenceType,
                $recipePlanHash,
                $recipeCount
            )
                ? 'verified'
                : 'unverified',
            'fallback_from' => $fallbackFrom,
            'fallback_reason' => $fallbackReason,
            'response_evidence_type' => $responseEvidenceType,
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => $recipeCount,
        ];
    }

    /** @param array<string,mixed> $context */
    private function strategyFromContext(array $context): array
    {
        $selected = strtolower(trim((string)($context['capture_strategy'] ?? '')));
        if (!in_array($selected, self::STRATEGIES, true) || $selected === 'not_recorded') {
            $selected = 'not_recorded';
        }
        $fallbackFrom = $this->strategyOrNull($context['fallback_from'] ?? null);
        $fallbackReason = $this->reasonCodeOrNull($context['fallback_reason'] ?? null);
        $responseEvidenceType = $this->textOrNull(
            $context['response_evidence_type'] ?? null,
            40
        );
        $recipePlanHash = $this->sha256OrNull(
            $context['recipe_plan_hash'] ?? null
        );
        $recipeCount = isset($context['recipe_count'])
            ? max(0, (int)$context['recipe_count'])
            : null;
        return [
            'selected' => $selected,
            'status' => $this->strategyIsVerified(
                $selected,
                $fallbackFrom,
                $fallbackReason,
                $responseEvidenceType,
                $recipePlanHash,
                $recipeCount
            )
                ? 'verified'
                : 'unverified',
            'fallback_from' => $fallbackFrom,
            'fallback_reason' => $fallbackReason,
            'response_evidence_type' => $responseEvidenceType,
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => $recipeCount,
        ];
    }

    private function strategyIsVerified(
        string $selected,
        ?string $fallbackFrom,
        ?string $fallbackReason,
        ?string $responseEvidenceType,
        ?string $recipePlanHash,
        ?int $recipeCount
    ): bool {
        if ($selected === 'verified_endpoint_recipe') {
            return $fallbackFrom === null
                && $fallbackReason === null
                && $responseEvidenceType === 'structured_json'
                && $recipePlanHash !== null
                && $recipeCount !== null
                && $recipeCount > 0;
        }
        if ($selected === 'browser_response') {
            return $responseEvidenceType === 'structured_json'
                && (
                    ($fallbackFrom === null && $fallbackReason === null)
                    || (
                        $fallbackFrom === 'verified_endpoint_recipe'
                        && $fallbackReason !== null
                    )
                );
        }
        if ($selected === 'dom_fallback') {
            return $responseEvidenceType === 'dom_fields'
                && in_array(
                    $fallbackFrom,
                    ['verified_endpoint_recipe', 'browser_response'],
                    true
                )
                && $fallbackReason !== null;
        }
        return false;
    }

    private function qualityStatus(string $value, bool $claimAllowed): string
    {
        if ($claimAllowed) {
            return 'verified';
        }
        if (in_array($value, ['partial', 'unverified', 'missing'], true)) {
            return $value === 'missing' ? 'unverified' : $value;
        }
        return 'blocked';
    }

    /** @param array<int,string> $blockers */
    private function appendContextMismatch(
        array &$blockers,
        string $code,
        mixed $contextValue,
        ?int $receiptValue
    ): void {
        if ($contextValue === null || $contextValue === '') {
            return;
        }
        if ((int)$contextValue !== (int)$receiptValue) {
            $blockers[] = $code;
        }
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^\d+$/D', (string)$value)
        ) {
            return null;
        }
        return (int)$value;
    }

    /** @return list<int> */
    private function positiveUniqueInts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $values = [];
        foreach ($value as $item) {
            $item = $this->positiveIntOrNull($item);
            if ($item !== null) {
                $values[$item] = $item;
            }
        }
        return array_values($values);
    }

    /** @return list<string> */
    private function safeReferences(mixed $value, int $limit): array
    {
        if (!is_array($value)) {
            return [];
        }
        $values = [];
        foreach ($value as $item) {
            $item = $this->referenceOrNull($item, $limit);
            if ($item !== null) {
                $values[$item] = $item;
            }
        }
        return array_values($values);
    }

    private function referenceOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === ''
            || mb_strlen($value) > $limit
            || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1
        ) {
            return null;
        }
        return $value;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || mb_strlen($value) > $limit) {
            return null;
        }
        return $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value !== '' && strtotime($value) !== false ? $value : null;
    }

    private function sha256OrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    private function reasonCodeOrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return preg_match('/^[a-z][a-z0-9_:-]{0,119}$/D', $value) === 1
            ? $value
            : null;
    }

    private function strategyOrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        return in_array($value, array_slice(self::STRATEGIES, 0, 3), true)
            ? $value
            : null;
    }

    /** @return list<string> */
    private function safeReasonList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = $this->reasonCodeOrNull($item);
            if ($item !== null) {
                $result[] = $item;
            }
        }
        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function safeMetricKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = ['revenue', 'room_nights', 'adr'];
        $result = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string)$item));
            if (in_array($item, $allowed, true)) {
                $result[] = $item;
            }
        }
        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function safeTrafficMetricKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $result = [];
        foreach ($value as $item) {
            $item = strtolower(trim((string)$item));
            if (in_array($item, $allowed, true)) {
                $result[] = $item;
            }
        }
        return array_values(array_unique($result));
    }

    /** @param list<string> $codes @return list<string> */
    private function uniqueReasonCodes(array $codes): array
    {
        return array_values(array_unique(array_filter(
            $codes,
            static fn(string $code): bool => $code !== ''
        )));
    }
}
