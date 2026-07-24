<?php
declare(strict_types=1);

namespace app\service;

class OtaDataCredibilityGateService
{
    private const DEFAULT_CRITICAL_METRICS = ['totals.revenue', 'totals.room_nights', 'totals.adr'];
    private const BLOCKING_COLLECTION_QUALITY_STATES = [
        'stale',
        'unverified',
        'binding_missing',
        'permission_denied',
        'collection_failed',
    ];
    private const KNOWN_COLLECTION_QUALITY_STATES = [
        'available',
        'partial',
        ...self::BLOCKING_COLLECTION_QUALITY_STATES,
    ];

    /**
     * @param array<string, mixed> $dataset
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function evaluate(array $dataset, array $metrics, array $options = []): array
    {
        $criticalMetrics = $this->stringList($options['critical_metrics'] ?? self::DEFAULT_CRITICAL_METRICS);
        if ($criticalMetrics === []) {
            $criticalMetrics = self::DEFAULT_CRITICAL_METRICS;
        }

        $qualityPresent = is_array($dataset['data_quality'] ?? null);
        $quality = $qualityPresent ? $dataset['data_quality'] : [];
        $factCount = $this->factCount($dataset);
        $inputRows = (int)($quality['input_rows'] ?? $factCount);
        $acceptedRows = (int)($quality['accepted_rows'] ?? $factCount);
        $rejectedRows = $this->list($quality['rejected_rows'] ?? []);
        $datasetStatus = trim((string)($dataset['status'] ?? ''));
        $metricStatus = trim((string)($metrics['status'] ?? ''));
        $collectionQuality = $this->collectionQuality($dataset);
        $collectionQualityState = (string)($collectionQuality['primary_quality_state'] ?? '');
        $reasonCodes = [];
        $warnings = [];

        if ($datasetStatus === '' || $datasetStatus === 'empty' || $metricStatus === 'empty' || $factCount <= 0) {
            $reasonCodes[] = 'ota_dataset_empty';
        }
        if (in_array($datasetStatus, ['failed', 'error'], true)) {
            $reasonCodes[] = 'ota_dataset_failed';
        }
        if (in_array($datasetStatus, ['not_loaded', 'not_loaded_current', 'not_loaded_for_target_date'], true)) {
            $reasonCodes[] = 'ota_dataset_not_loaded';
        }
        if ($datasetStatus === 'incomplete') {
            $reasonCodes[] = 'ota_dataset_incomplete';
        }
        if (in_array($datasetStatus, ['partial', 'sample', 'sample_only', 'sample_scope', 'sampled'], true)) {
            $warnings[] = 'ota_dataset_partial';
        }
        if ($acceptedRows <= 0) {
            $reasonCodes[] = 'accepted_rows_missing';
        }
        if (($collectionQuality['provided'] ?? false) === true) {
            if ($collectionQualityState === 'unknown') {
                $reasonCodes[] = 'ota_collection_quality_state_unknown';
            } elseif (in_array($collectionQualityState, self::BLOCKING_COLLECTION_QUALITY_STATES, true)) {
                $reasonCodes[] = 'ota_collection_quality:' . $collectionQualityState;
            } elseif ($collectionQualityState === 'partial') {
                $warnings[] = 'ota_collection_quality_partial';
            }
            if (($collectionQuality['metric_scope'] ?? '') !== 'ota_channel') {
                $reasonCodes[] = 'ota_collection_quality_scope_invalid';
            }
        }

        $metricTrust = is_array($metrics['metric_trust'] ?? null) ? $metrics['metric_trust'] : [];
        $failedCriticalMetrics = [];
        foreach ($criticalMetrics as $metricKey) {
            $trust = is_array($metricTrust[$metricKey] ?? null) ? $metricTrust[$metricKey] : null;
            if ($trust === null) {
                $code = 'critical_metric_missing:' . $metricKey;
                $reasonCodes[] = $code;
                $failedCriticalMetrics[] = $code;
                continue;
            }
            $failureReasons = $this->stringList($trust['failure_reasons'] ?? []);
            if (($trust['saved_success'] ?? false) !== true || $failureReasons !== []) {
                $code = 'critical_metric_untrusted:' . $metricKey;
                $reasonCodes[] = $code;
                $failedCriticalMetrics[] = $code;
            }
        }

        $dataGapCodes = array_values(array_filter(array_map(
            static fn(array $gap): string => trim((string)($gap['code'] ?? '')),
            $this->list($metrics['data_gaps'] ?? [])
        )));
        if ($dataGapCodes !== []) {
            $warnings[] = 'data_gaps_present';
        }
        if (!$qualityPresent) {
            $warnings[] = 'data_quality_missing';
        }
        if ($rejectedRows !== []) {
            $warnings[] = 'rejected_rows_present';
        }

        $wholeHotelEvidence = $this->boolValue($options['whole_hotel_evidence'] ?? false);
        if (!$wholeHotelEvidence) {
            $warnings[] = 'whole_hotel_scope_not_proved';
        }

        $p0DownstreamGate = $this->p0DownstreamGate(
            $options['p0_downstream_gate']
            ?? $dataset['p0_downstream_gate']
            ?? $metrics['downstream_gate']
            ?? null
        );
        $p0Blocked = ($p0DownstreamGate['status'] ?? '') === 'blocked_by_p0_ota_gate';
        if ($p0Blocked) {
            $reasonCodes[] = 'p0_ota_gate_not_ready';
            foreach ($this->stringList($p0DownstreamGate['blocking_missing_inputs'] ?? []) as $missingInput) {
                $reasonCodes[] = 'p0_ota_gate_missing:' . $missingInput;
            }
        }

        $reasonCodes = array_values(array_unique($reasonCodes));
        $warnings = array_values(array_unique($warnings));
        $status = $reasonCodes !== []
            ? 'blocked'
            : ($this->hasOperationalWarnings($warnings) ? 'warning' : 'ready');
        $dataUsable = $status !== 'blocked';
        $collectionQualityBlocked = ($collectionQuality['provided'] ?? false) === true
            && (
                $collectionQualityState === 'unknown'
                || in_array($collectionQualityState, self::BLOCKING_COLLECTION_QUALITY_STATES, true)
                || ($collectionQuality['metric_scope'] ?? '') !== 'ota_channel'
            );
        $blockedDecisionStatus = $p0Blocked
            ? 'blocked_by_p0_ota_gate'
            : ($collectionQualityBlocked ? 'blocked_by_collection_quality' : 'blocked');

        return [
            'status' => $status,
            'metric_scope' => 'ota_channel',
            'reason_codes' => $reasonCodes,
            'warnings' => $warnings,
            'human_review_required' => $status !== 'ready',
            'decision_use' => [
                'revenue_analysis' => [
                    'allowed' => $dataUsable,
                    'status' => $dataUsable ? ($status === 'warning' ? 'allowed_with_data_warnings' : 'allowed') : $blockedDecisionStatus,
                ],
                'ai_decision_support' => [
                    'allowed' => $dataUsable,
                    'status' => $dataUsable ? ($status === 'warning' ? 'allowed_with_human_review' : 'allowed_with_governance') : $blockedDecisionStatus,
                ],
                'operation_management' => [
                    'allowed' => $dataUsable,
                    'status' => $dataUsable ? 'manual_review_required_before_execution' : $blockedDecisionStatus,
                ],
                'investment_decision' => [
                    'allowed' => $dataUsable && $wholeHotelEvidence,
                    'status' => $dataUsable && $wholeHotelEvidence ? 'allowed_with_whole_hotel_evidence' : ($p0Blocked ? 'blocked_by_p0_ota_gate' : 'blocked_scope'),
                ],
            ],
            'evidence' => [
                'dataset_status' => $datasetStatus,
                'metric_status' => $metricStatus,
                'input_rows' => $inputRows,
                'accepted_rows' => $acceptedRows,
                'rejected_rows' => count($rejectedRows),
                'fact_rows' => $factCount,
                'data_quality_present' => $qualityPresent,
                'data_gap_codes' => $dataGapCodes,
                'critical_metrics' => $criticalMetrics,
                'failed_critical_metrics' => $failedCriticalMetrics,
                'collection_quality' => $collectionQuality,
                'p0_downstream_gate' => $p0DownstreamGate,
            ],
        ];
    }

    /**
     * The phase-one closure gate must not treat a syntactically ready metric
     * payload as usable when its critical OTA metrics are not trusted.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function evaluateRevenueAiReadiness(array $metrics, array $options = []): array
    {
        $scope = $this->revenueAiReadinessScope($options);
        $metricStatus = trim((string)($metrics['status'] ?? ''));
        $gate = is_array($metrics['credibility_gate'] ?? null) ? $metrics['credibility_gate'] : [];
        $gateStatus = trim((string)($gate['status'] ?? ''));
        $revenueUse = is_array($gate['decision_use']['revenue_analysis'] ?? null)
            ? $gate['decision_use']['revenue_analysis']
            : [];
        $aiUse = is_array($gate['decision_use']['ai_decision_support'] ?? null)
            ? $gate['decision_use']['ai_decision_support']
            : [];
        $evidence = is_array($gate['evidence'] ?? null) ? $gate['evidence'] : [];
        $criticalMetricsEvidencePresent = array_key_exists('critical_metrics', $evidence)
            && is_array($evidence['critical_metrics']);
        $failedCriticalMetricsEvidencePresent = array_key_exists('failed_critical_metrics', $evidence)
            && is_array($evidence['failed_critical_metrics']);
        $criticalMetrics = $criticalMetricsEvidencePresent
            ? $this->stringList($evidence['critical_metrics'])
            : [];
        $failedCriticalMetrics = $failedCriticalMetricsEvidencePresent
            ? $this->stringList($evidence['failed_critical_metrics'])
            : [];
        $revenueAllowed = ($revenueUse['allowed'] ?? false) === true;
        $aiAllowed = ($aiUse['allowed'] ?? false) === true;
        $commonReasonCodes = $scope['reason_codes'];

        if ($metricStatus !== 'ready') {
            $commonReasonCodes[] = 'revenue_metric_status_not_ready';
        }
        if ($gateStatus === '') {
            $commonReasonCodes[] = 'credibility_gate_missing';
        } elseif (!in_array($gateStatus, ['ready', 'warning'], true)) {
            $commonReasonCodes[] = $gateStatus === 'blocked'
                ? 'credibility_gate_blocked'
                : 'credibility_gate_not_ready';
        }
        $gateMetricScope = strtolower(trim((string)($gate['metric_scope'] ?? '')));
        if ($gateMetricScope !== 'ota_channel'
            || (($scope['metric_scope_valid'] ?? false) === true && $gateMetricScope !== $scope['metric_scope'])
        ) {
            $commonReasonCodes[] = 'credibility_gate_scope_invalid';
        }
        if (!$criticalMetricsEvidencePresent) {
            $commonReasonCodes[] = 'critical_metrics_evidence_missing';
        } else {
            foreach (self::DEFAULT_CRITICAL_METRICS as $metricKey) {
                if (!in_array($metricKey, $criticalMetrics, true)) {
                    $commonReasonCodes[] = 'critical_metrics_evidence_incomplete:' . $metricKey;
                }
            }
        }
        if (!$failedCriticalMetricsEvidencePresent) {
            $commonReasonCodes[] = 'failed_critical_metrics_evidence_missing';
        }
        if ($failedCriticalMetrics !== []) {
            $commonReasonCodes[] = 'critical_metrics_untrusted';
        }

        $metricTrust = is_array($metrics['metric_trust'] ?? null) ? $metrics['metric_trust'] : [];
        $criticalMetricValueKeys = [];
        foreach (self::DEFAULT_CRITICAL_METRICS as $metricKey) {
            $metricValue = $this->nestedMetricValue($metrics, $metricKey);
            if (!$this->isFiniteMetricNumber($metricValue)) {
                $commonReasonCodes[] = 'critical_metric_value_missing_or_invalid:' . $metricKey;
            } else {
                $criticalMetricValueKeys[] = $metricKey;
            }
            $trust = is_array($metricTrust[$metricKey] ?? null) ? $metricTrust[$metricKey] : null;
            if ($trust === null) {
                $commonReasonCodes[] = 'critical_metric_trust_missing:' . $metricKey;
                continue;
            }
            if (($trust['saved_success'] ?? false) !== true || $this->stringList($trust['failure_reasons'] ?? []) !== []) {
                $commonReasonCodes[] = 'critical_metric_untrusted:' . $metricKey;
            }

            $source = is_array($trust['source'] ?? null) ? $trust['source'] : null;
            if ($source === null) {
                $commonReasonCodes[] = 'critical_metric_source_missing:' . $metricKey;
                continue;
            }
            if (($scope['system_hotel_id_valid'] ?? false) === true
                && !$this->criticalMetricSourceMatchesHotel($source, $scope['system_hotel_id'])
            ) {
                $commonReasonCodes[] = 'critical_metric_hotel_scope_mismatch:' . $metricKey;
            }
            if (($scope['target_date_valid'] ?? false) === true
                && !$this->criticalMetricSourceMatchesDate($source, $scope['target_date'])
            ) {
                $commonReasonCodes[] = 'critical_metric_date_scope_mismatch:' . $metricKey;
            }
            if (($scope['platform_valid'] ?? false) === true
                && !$this->criticalMetricSourceMatchesPlatform($source, $scope['platform'])
            ) {
                $commonReasonCodes[] = 'critical_metric_platform_scope_mismatch:' . $metricKey;
            }
            if (!$this->criticalMetricSourceHasReadbackProof($source)) {
                $commonReasonCodes[] = 'critical_metric_storage_readback_unverified:' . $metricKey;
            }
        }

        $revenueReasonCodes = $commonReasonCodes;
        if (!$revenueAllowed) {
            $revenueReasonCodes[] = 'revenue_analysis_not_allowed';
        }
        $aiReasonCodes = $commonReasonCodes;
        if (!$aiAllowed) {
            $aiReasonCodes[] = 'ai_decision_support_not_allowed';
        }
        $revenueReasonCodes = array_values(array_unique($revenueReasonCodes));
        $aiReasonCodes = array_values(array_unique($aiReasonCodes));
        $revenueReady = $revenueReasonCodes === [];
        $aiReady = $aiReasonCodes === [];
        $reasonCodes = array_values(array_unique(array_merge($revenueReasonCodes, $aiReasonCodes)));

        return [
            'ready' => $revenueReady && $aiReady,
            'revenue_ready' => $revenueReady,
            'ai_ready' => $aiReady,
            'metric_status' => $metricStatus !== '' ? $metricStatus : 'unknown',
            'credibility_gate_status' => $gateStatus !== '' ? $gateStatus : 'missing',
            'revenue_analysis_allowed' => $revenueAllowed,
            'revenue_analysis_status' => trim((string)($revenueUse['status'] ?? '')),
            'ai_decision_support_allowed' => $aiAllowed,
            'ai_decision_support_status' => trim((string)($aiUse['status'] ?? '')),
            'scope' => [
                'system_hotel_id' => $scope['system_hotel_id'],
                'target_date' => $scope['target_date'],
                'platform' => $scope['platform'],
                'metric_scope' => $scope['metric_scope'],
            ],
            'critical_metrics' => $criticalMetrics,
            'critical_metric_value_keys' => $criticalMetricValueKeys,
            'failed_critical_metrics' => $failedCriticalMetrics,
            'common_reason_codes' => array_values(array_unique($commonReasonCodes)),
            'revenue_reason_codes' => $revenueReasonCodes,
            'ai_reason_codes' => $aiReasonCodes,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function revenueAiReadinessScope(array $options): array
    {
        $reasonCodes = [];
        $rawSystemHotelId = $options['system_hotel_id'] ?? null;
        $systemHotelId = $this->strictInteger($rawSystemHotelId);
        $systemHotelIdValid = $systemHotelId !== null && $systemHotelId > 0;
        if (!$systemHotelIdValid) {
            $reasonCodes[] = array_key_exists('system_hotel_id', $options)
                ? 'readiness_scope_system_hotel_id_invalid'
                : 'readiness_scope_system_hotel_id_missing';
        }

        $targetDate = trim((string)($options['target_date'] ?? ''));
        $targetDateValid = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $targetDate, $dateParts) === 1
            && checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1]);
        if (!$targetDateValid) {
            $reasonCodes[] = array_key_exists('target_date', $options)
                ? 'readiness_scope_target_date_invalid'
                : 'readiness_scope_target_date_missing';
        }

        $platform = strtolower(trim((string)($options['platform'] ?? '')));
        $platformValid = $platform !== '' && preg_match('/^[a-z0-9_-]+$/D', $platform) === 1;
        if (!$platformValid) {
            $reasonCodes[] = array_key_exists('platform', $options)
                ? 'readiness_scope_platform_invalid'
                : 'readiness_scope_platform_missing';
        }

        $metricScope = strtolower(trim((string)($options['metric_scope'] ?? '')));
        $metricScopeValid = $metricScope === 'ota_channel';
        if (!$metricScopeValid) {
            $reasonCodes[] = array_key_exists('metric_scope', $options)
                ? 'readiness_scope_metric_scope_invalid'
                : 'readiness_scope_metric_scope_missing';
        }

        return [
            'system_hotel_id' => $systemHotelIdValid ? $systemHotelId : null,
            'system_hotel_id_valid' => $systemHotelIdValid,
            'target_date' => $targetDateValid ? $targetDate : null,
            'target_date_valid' => $targetDateValid,
            'platform' => $platformValid ? $platform : null,
            'platform_valid' => $platformValid,
            'metric_scope' => $metricScopeValid ? $metricScope : null,
            'metric_scope_valid' => $metricScopeValid,
            'reason_codes' => $reasonCodes,
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function criticalMetricSourceMatchesHotel(array $source, int $systemHotelId): bool
    {
        $hotels = $this->list($source['hotels'] ?? []);
        if ($hotels === []) {
            return false;
        }
        foreach ($hotels as $hotel) {
            if (!is_array($hotel) || $this->strictInteger($hotel['system_hotel_id'] ?? null) !== $systemHotelId) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function criticalMetricSourceMatchesDate(array $source, string $targetDate): bool
    {
        $dateRange = is_array($source['date_range'] ?? null) ? $source['date_range'] : [];
        return trim((string)($dateRange['start'] ?? '')) === $targetDate
            && trim((string)($dateRange['end'] ?? '')) === $targetDate;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function criticalMetricSourceMatchesPlatform(array $source, string $platform): bool
    {
        $platforms = array_values(array_unique(array_map(
            static fn(string $value): string => strtolower($value),
            $this->stringList($source['platforms'] ?? [])
        )));
        return $platforms === [$platform];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function criticalMetricSourceHasReadbackProof(array $source): bool
    {
        $counts = [];
        foreach (['row_count', 'stored_count', 'readback_verified_count'] as $key) {
            if (!array_key_exists($key, $source)) {
                return false;
            }
            $counts[$key] = $this->strictInteger($source[$key]);
            if ($counts[$key] === null || $counts[$key] < 0) {
                return false;
            }
        }
        $rowCount = $counts['row_count'];
        return $rowCount > 0
            && $counts['stored_count'] === $rowCount
            && $counts['readback_verified_count'] === $rowCount;
    }

    private function strictInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int)$value;
        }
        return null;
    }

    /** @param array<string, mixed> $metrics */
    private function nestedMetricValue(array $metrics, string $metricKey): mixed
    {
        $cursor = $metrics;
        foreach (explode('.', $metricKey) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    private function isFiniteMetricNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float)$value);
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function factCount(array $dataset): int
    {
        $count = 0;
        foreach ([
            'fact_ota_daily',
            'fact_ota_traffic',
            'fact_ota_advertising',
            'fact_ota_quality',
            'fact_ota_search_keyword',
            'fact_ota_peer_rank',
            'fact_ota_traffic_analysis',
            'fact_ota_traffic_forecast',
            'fact_ota_comment',
        ] as $key) {
            $count += count($this->list($dataset[$key] ?? []));
        }
        return $count;
    }

    /**
     * @return array<int, mixed>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }

    /**
     * @param array<int, string> $warnings
     */
    private function hasOperationalWarnings(array $warnings): bool
    {
        return array_values(array_diff($warnings, ['whole_hotel_scope_not_proved'])) !== [];
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    private function collectionQuality(array $dataset): array
    {
        $raw = $dataset['collection_quality'] ?? $dataset['quality'] ?? null;
        if (!is_array($raw)) {
            return [
                'provided' => false,
                'primary_quality_state' => '',
                'quality_flags' => [],
                'metric_scope' => '',
            ];
        }

        $state = strtolower(trim((string)($raw['primary_quality_state'] ?? $raw['quality_state'] ?? '')));
        if (!in_array($state, self::KNOWN_COLLECTION_QUALITY_STATES, true)) {
            $state = 'unknown';
        }

        return [
            'provided' => true,
            'primary_quality_state' => $state,
            'quality_flags' => $this->stringList($raw['quality_flags'] ?? []),
            'metric_scope' => strtolower(trim((string)($raw['metric_scope'] ?? ''))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function p0DownstreamGate(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return [
            'status' => trim((string)($value['status'] ?? '')),
            'current_upstream_status' => trim((string)($value['current_upstream_status'] ?? '')),
            'required_upstream_status' => trim((string)($value['required_upstream_status'] ?? '')),
            'required_gate_command' => trim((string)($value['required_gate_command'] ?? '')),
            'scope_policy' => trim((string)($value['scope_policy'] ?? '')),
            'blocking_missing_inputs' => $this->stringList($value['blocking_missing_inputs'] ?? []),
            'blocked_stage_keys' => $this->stringList($value['blocked_stage_keys'] ?? []),
            'allowed_claims' => $this->stringList($value['allowed_claims'] ?? []),
        ];
    }
}
