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
