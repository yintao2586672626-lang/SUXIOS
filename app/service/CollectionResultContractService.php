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
        $evidence = is_array($capture['capture_evidence'] ?? null)
            ? $capture['capture_evidence']
            : [];
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
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
        if ($sourceFingerprint === null) {
            $blockers[] = 'source_fingerprint_missing';
        }
        if (($strategy['status'] ?? '') !== 'verified') {
            $blockers[] = 'collection_strategy_unverified';
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
        $rowIds = $this->positiveUniqueInts($receipt['row_ids'] ?? []);
        $traceIds = $this->safeReferences($receipt['source_trace_ids'] ?? [], 160);
        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        $readbackVerified = ($receipt['readback_verified'] ?? false) === true;
        $identityMatched =
            strtolower(trim((string)($receipt['platform_hotel_identifier_status'] ?? ''))) === 'ready';
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
        if (!$identityMatched) {
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
