<?php
declare(strict_types=1);

namespace app\service;

/**
 * Conservatively judges one prospective operating intervention.
 *
 * A supported or contradicted verdict describes only a same-scope observed
 * association. It never attributes the observed movement to the intervention.
 */
final class OperationInterventionJudgmentService
{
    private const EPSILON = 0.000001;

    private LongitudinalEvidenceLearningService $longitudinalEvidence;

    public function __construct(?LongitudinalEvidenceLearningService $longitudinalEvidence = null)
    {
        $this->longitudinalEvidence = $longitudinalEvidence
            ?? new LongitudinalEvidenceLearningService();
    }

    /**
     * @param array<string, mixed> $goalContract
     * @param array<string, mixed> $intervention
     * @param array<string, mixed> $task
     * @param array<int, mixed> $executionEvidenceRows
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function judge(
        array $goalContract,
        array $intervention,
        array $task,
        array $executionEvidenceRows,
        array $input
    ): array {
        $hardReasons = [];

        $designTiming = strtolower(trim((string)($intervention['design_timing'] ?? '')));
        if ($designTiming !== 'prospective') {
            $hardReasons[] = $designTiming === 'retrospective'
                ? 'intervention_contract_retrospective'
                : 'intervention_contract_not_prospective';
        }

        $taskId = max(0, (int)($task['id'] ?? 0));
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        if ($taskStatus !== 'executed') {
            $hardReasons[] = 'execution_task_not_executed';
        }
        if ($taskId === 0) {
            $hardReasons[] = 'execution_task_identity_missing';
        }
        $hasExecutionEvidence = $this->hasExecutionEvidence($executionEvidenceRows);
        if (!$hasExecutionEvidence) {
            $hardReasons[] = 'execution_evidence_missing';
        }

        $baseline = $this->arrayField($intervention, 'baseline_snapshot', 'baseline_snapshot_json');
        $followup = $this->arrayField($input, 'followup_snapshot', 'followup_snapshot_json');
        $comparisonMode = strtolower(trim((string)($intervention['comparison_mode'] ?? 'same_length_period')));
        $expectedDirection = strtolower(trim((string)($intervention['expected_direction'] ?? '')));
        $targetMetricKey = strtolower(trim((string)($intervention['target_metric_key'] ?? '')));
        $actionType = strtolower(trim((string)($intervention['action_type'] ?? '')));
        $executionRefs = $taskId > 0 && $hasExecutionEvidence
            ? ['operation_execution_task#' . $taskId]
            : [];

        $comparison = $this->longitudinalEvidence->reviewAction(
            $baseline,
            $followup,
            [
                'action_ref' => $taskId > 0 ? 'operation_execution_task#' . $taskId : '',
                'action_type' => $actionType,
                'execution_status' => $taskStatus,
                'executed_at' => trim((string)($task['executed_at'] ?? '')),
                'evidence_refs' => $executionRefs,
                'expected_direction' => $expectedDirection,
            ],
            $comparisonMode
        );
        if (($comparison['status'] ?? '') !== 'verified') {
            $comparisonReason = trim((string)($comparison['reason_code'] ?? ''));
            $hardReasons[] = $comparisonReason !== ''
                ? $comparisonReason
                : 'snapshot_comparison_unverified';
        }

        if ($targetMetricKey === '') {
            $hardReasons[] = 'target_metric_key_missing';
        } else {
            $baselineMetric = strtolower(trim((string)($baseline['metric_key'] ?? '')));
            $followupMetric = strtolower(trim((string)($followup['metric_key'] ?? '')));
            if ($baselineMetric !== $targetMetricKey || $followupMetric !== $targetMetricKey) {
                $hardReasons[] = 'target_metric_scope_mismatch';
            }
        }
        if (!in_array($expectedDirection, ['increase', 'decrease'], true)) {
            $hardReasons[] = 'expected_direction_invalid';
        }

        $expectedDelta = $this->numeric($intervention['expected_delta'] ?? null);
        $expectedDeltaUnit = strtolower(trim((string)($intervention['expected_delta_unit'] ?? '')));
        if ($expectedDelta === null || $expectedDelta <= 0.0) {
            $hardReasons[] = 'expected_delta_invalid';
        }
        if (!in_array($expectedDeltaUnit, ['absolute', 'percent'], true)) {
            $hardReasons[] = 'expected_delta_unit_invalid';
        }

        $windowStart = $this->date((string)($intervention['observation_window_start'] ?? ''));
        $windowEnd = $this->date((string)($intervention['observation_window_end'] ?? ''));
        if ($windowStart === '' || $windowEnd === '' || $windowEnd < $windowStart) {
            $hardReasons[] = 'observation_window_invalid';
        } else {
            $assessmentDate = $this->date((string)(
                $input['assessed_at']
                    ?? $input['assessment_date']
                    ?? $input['as_of_date']
                    ?? ''
            ));
            if ($assessmentDate === '') {
                $hardReasons[] = 'assessment_date_missing';
            } elseif ($assessmentDate < $windowEnd) {
                $hardReasons[] = 'observation_window_not_ended';
            }

            $followupStart = $this->date((string)($followup['period_start'] ?? $followup['data_date'] ?? ''));
            $followupEnd = $this->date((string)($followup['period_end'] ?? $followup['data_date'] ?? ''));
            if ($followupStart !== $windowStart || $followupEnd !== $windowEnd) {
                $hardReasons[] = 'followup_observation_window_mismatch';
            }
        }

        $minimumSampleSize = $this->positiveInteger($intervention['minimum_sample_size'] ?? null);
        if ($minimumSampleSize === null) {
            $hardReasons[] = 'minimum_sample_size_invalid';
        } else {
            $baselineSampleSize = $this->sampleSize($baseline);
            $followupSampleSize = $this->sampleSize($followup);
            if ($baselineSampleSize === null) {
                $hardReasons[] = 'baseline_sample_size_missing';
            } elseif ($baselineSampleSize < $minimumSampleSize) {
                $hardReasons[] = 'baseline_sample_size_insufficient';
            }
            if ($followupSampleSize === null) {
                $hardReasons[] = 'followup_sample_size_missing';
            } elseif ($followupSampleSize < $minimumSampleSize) {
                $hardReasons[] = 'followup_sample_size_insufficient';
            }
        }

        foreach ($this->identityMismatchReasons($goalContract, $intervention, $task) as $reason) {
            $hardReasons[] = $reason;
        }
        foreach ($this->externalInterferenceReasons($input) as $reason) {
            $hardReasons[] = $reason;
        }
        foreach ($this->monitorPreflightReasons($input) as $reason) {
            $hardReasons[] = $reason;
        }

        [$guardResults, $guardHardReasons, $guardBreachReasons] = $this->assessGuards(
            $goalContract,
            $intervention,
            $input,
            $windowStart,
            $windowEnd,
            $minimumSampleSize
        );
        foreach ($guardHardReasons as $reason) {
            $hardReasons[] = $reason;
        }

        $stopTriggered = $this->boolean($input['stop_triggered'] ?? null);
        if ($stopTriggered === null) {
            $hardReasons[] = 'stop_trigger_status_missing';
        } elseif ($stopTriggered && $this->evidenceRefs($input['stop_evidence_refs'] ?? []) === []) {
            $hardReasons[] = 'stop_trigger_evidence_missing';
        }

        $observedProgress = null;
        $thresholdMet = null;
        $movement = strtolower(trim((string)($comparison['delta']['movement'] ?? 'unknown')));
        if (($comparison['status'] ?? '') === 'verified'
            && $expectedDelta !== null
            && $expectedDelta > 0.0
            && in_array($expectedDirection, ['increase', 'decrease'], true)
            && in_array($expectedDeltaUnit, ['absolute', 'percent'], true)
        ) {
            $rawProgress = $expectedDeltaUnit === 'percent'
                ? $this->numeric($comparison['delta']['relative_percent'] ?? null)
                : $this->numeric($comparison['delta']['absolute'] ?? null);
            if ($rawProgress === null) {
                $hardReasons[] = $expectedDeltaUnit === 'percent'
                    ? 'percent_change_not_calculable'
                    : 'target_change_not_calculable';
            } else {
                $observedProgress = $expectedDirection === 'increase' ? $rawProgress : -$rawProgress;
                $thresholdMet = $observedProgress + self::EPSILON >= $expectedDelta;
            }
        }

        $comparison['target_assessment'] = [
            'metric_key' => $targetMetricKey !== '' ? $targetMetricKey : null,
            'expected_direction' => in_array($expectedDirection, ['increase', 'decrease'], true)
                ? $expectedDirection
                : null,
            'expected_delta' => $expectedDelta,
            'expected_delta_unit' => in_array($expectedDeltaUnit, ['absolute', 'percent'], true)
                ? $expectedDeltaUnit
                : null,
            'observed_progress' => $observedProgress,
            'threshold_met' => $thresholdMet,
        ];

        $hardReasons = $this->uniqueReasons($hardReasons);
        if ($hardReasons !== []) {
            return $this->result(
                'indeterminate',
                $hardReasons,
                $comparison,
                $guardResults
            );
        }

        $contradictionReasons = [];
        if ($stopTriggered === true) {
            $contradictionReasons[] = 'stop_condition_triggered';
        }
        foreach ($guardBreachReasons as $reason) {
            $contradictionReasons[] = $reason;
        }
        $movementReversed = ($expectedDirection === 'increase' && $movement === 'decrease')
            || ($expectedDirection === 'decrease' && $movement === 'increase');
        if ($movementReversed) {
            $contradictionReasons[] = 'target_metric_reversed';
        }
        $contradictionReasons = $this->uniqueReasons($contradictionReasons);
        if ($contradictionReasons !== []) {
            return $this->result(
                'contradicted',
                $contradictionReasons,
                $comparison,
                $guardResults
            );
        }

        if ($thresholdMet === true && $movement === $expectedDirection) {
            return $this->result(
                'supported',
                ['target_threshold_met'],
                $comparison,
                $guardResults
            );
        }

        return $this->result(
            'indeterminate',
            [$movement === 'unchanged' ? 'target_metric_unchanged' : 'target_threshold_not_met'],
            $comparison,
            $guardResults
        );
    }

    /** @param array<int, mixed> $rows */
    private function hasExecutionEvidence(array $rows): bool
    {
        foreach ($rows as $row) {
            if (is_array($row) && $row !== [] && empty($row['deleted_at'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $goalContract
     * @param array<string, mixed> $intervention
     * @param array<string, mixed> $task
     * @return array<int, string>
     */
    private function identityMismatchReasons(array $goalContract, array $intervention, array $task): array
    {
        $reasons = [];
        foreach ([['tenant_id', 'tenant_scope_mismatch'], ['hotel_id', 'hotel_scope_mismatch']] as [$field, $reason]) {
            $values = [];
            foreach ([$goalContract, $intervention, $task] as $record) {
                $value = (int)($record[$field] ?? 0);
                if ($value > 0) {
                    $values[] = $value;
                }
            }
            if (count(array_unique($values)) > 1) {
                $reasons[] = $reason;
            }
        }

        $goalId = (int)($goalContract['id'] ?? 0);
        $boundGoalId = (int)($intervention['goal_contract_id'] ?? 0);
        if ($goalId > 0 && $boundGoalId > 0 && $goalId !== $boundGoalId) {
            $reasons[] = 'goal_contract_binding_mismatch';
        }
        $intentId = (int)($intervention['intent_id'] ?? 0);
        $taskIntentId = (int)($task['intent_id'] ?? 0);
        if ($intentId > 0 && $taskIntentId > 0 && $intentId !== $taskIntentId) {
            $reasons[] = 'execution_intent_binding_mismatch';
        }
        return $reasons;
    }

    /** @param array<string, mixed> $input @return array<int, string> */
    private function externalInterferenceReasons(array $input): array
    {
        if (!array_key_exists('external_interferences', $input)
            && !array_key_exists('external_interferences_json', $input)
        ) {
            return ['external_interference_unknown'];
        }
        $items = $this->arrayField($input, 'external_interferences', 'external_interferences_json');
        if ($items === []) {
            return [];
        }
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                return ['external_interference_present'];
            }
            if (!is_array($item)) {
                return ['external_interference_unknown'];
            }
            $status = strtolower(trim((string)($item['status'] ?? 'present')));
            if (!in_array($status, ['absent', 'clear', 'none', 'not_present'], true)) {
                return ['external_interference_present'];
            }
        }
        return [];
    }

    /**
     * Automated monitors may have stronger execution/source checks than the
     * legacy evidence row contract. Preserve those failed gates as explicit
     * indeterminate reasons instead of allowing a non-empty row to imply proof.
     *
     * @param array<string, mixed> $input
     * @return array<int, string>
     */
    private function monitorPreflightReasons(array $input): array
    {
        $raw = $input['monitor_preflight_reason_codes'] ?? [];
        if (!is_array($raw)) {
            return ['monitor_preflight_invalid'];
        }

        $reasons = [];
        foreach ($raw as $reason) {
            $reason = strtolower(trim((string)$reason));
            if ($reason === ''
                || strlen($reason) > 120
                || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $reason) !== 1
            ) {
                return ['monitor_preflight_invalid'];
            }
            $reasons[] = $reason;
        }

        return $this->uniqueReasons($reasons);
    }

    /**
     * @param array<string, mixed> $goalContract
     * @param array<string, mixed> $intervention
     * @param array<string, mixed> $input
     * @return array{0:array<int, array<string, mixed>>,1:array<int, string>,2:array<int, string>}
     */
    private function assessGuards(
        array $goalContract,
        array $intervention,
        array $input,
        string $windowStart,
        string $windowEnd,
        ?int $minimumSampleSize
    ): array {
        $riskKeys = $this->stringList(
            $intervention['risk_metric_keys']
                ?? $intervention['risk_metric_keys_json']
                ?? []
        );
        $definitions = $this->guardDefinitions(
            $goalContract['guard_metrics']
                ?? $goalContract['guard_metrics_json']
                ?? []
        );
        $observations = $this->guardObservations(
            $input['guard_observations']
                ?? $input['guard_observations_json']
                ?? []
        );

        $results = [];
        $hardReasons = [];
        $breachReasons = [];
        foreach ($riskKeys as $metricKey) {
            $definition = $definitions[$metricKey] ?? null;
            $observation = $observations[$metricKey] ?? null;
            $reasons = [];
            $value = null;
            $lower = null;
            $upper = null;

            if (!is_array($definition)) {
                $reasons[] = 'risk_metric_not_in_goal_guard_metrics:' . $metricKey;
            } else {
                $lower = $definition['lower_bound'];
                $upper = $definition['upper_bound'];
                if ($lower === null && $upper === null) {
                    $reasons[] = 'guard_metric_bound_missing:' . $metricKey;
                }
            }
            if (!is_array($observation)) {
                $reasons[] = 'guard_observation_missing:' . $metricKey;
            } else {
                $value = $this->numeric($observation['value'] ?? null);
                if ($value === null) {
                    $reasons[] = 'guard_observation_value_missing:' . $metricKey;
                }
                $quality = strtolower(trim((string)(
                    $observation['quality_status']
                        ?? $observation['validation_status']
                        ?? ''
                )));
                if ($quality !== 'verified') {
                    $reasons[] = 'guard_observation_quality_unverified:' . $metricKey;
                }
                $readbackVerified = strtolower(trim((string)($observation['readback_status'] ?? '')))
                        === 'readback_verified'
                    || $this->boolean($observation['readback_verified'] ?? null) === true;
                if (!$readbackVerified) {
                    $reasons[] = 'guard_observation_readback_unverified:' . $metricKey;
                }
                if ($this->evidenceRefs($observation['evidence_refs'] ?? []) === []) {
                    $reasons[] = 'guard_observation_evidence_missing:' . $metricKey;
                }
                if ($windowStart !== '' && $windowEnd !== '') {
                    $periodStart = $this->date((string)(
                        $observation['period_start'] ?? $observation['data_date'] ?? ''
                    ));
                    $periodEnd = $this->date((string)(
                        $observation['period_end'] ?? $observation['data_date'] ?? ''
                    ));
                    if ($periodStart !== $windowStart || $periodEnd !== $windowEnd) {
                        $reasons[] = 'guard_observation_window_mismatch:' . $metricKey;
                    }
                }
                if ($minimumSampleSize !== null) {
                    $sampleSize = $this->sampleSize($observation);
                    if ($sampleSize === null) {
                        $reasons[] = 'guard_observation_sample_size_missing:' . $metricKey;
                    } elseif ($sampleSize < $minimumSampleSize) {
                        $reasons[] = 'guard_observation_sample_size_insufficient:' . $metricKey;
                    }
                }
            }

            $reasons = $this->uniqueReasons($reasons);
            foreach ($reasons as $reason) {
                $hardReasons[] = $reason;
            }
            $status = $reasons === [] ? 'within_bounds' : 'indeterminate';
            if ($reasons === [] && $value !== null) {
                if (($lower !== null && $value + self::EPSILON < $lower)
                    || ($upper !== null && $value - self::EPSILON > $upper)
                ) {
                    $status = 'breached';
                    $breachReasons[] = 'guard_metric_breached:' . $metricKey;
                }
            }
            $results[] = [
                'metric_key' => $metricKey,
                'status' => $status,
                'value' => $value,
                'lower_bound' => $lower,
                'upper_bound' => $upper,
                'reason_codes' => $reasons,
            ];
        }

        return [
            $results,
            $this->uniqueReasons($hardReasons),
            $this->uniqueReasons($breachReasons),
        ];
    }

    /** @param mixed $raw @return array<string, array{lower_bound:?float,upper_bound:?float}> */
    private function guardDefinitions(mixed $raw): array
    {
        $items = $this->decodeArray($raw);
        $definitions = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $metricKey = is_string($key) && !ctype_digit($key)
                ? strtolower(trim($key))
                : strtolower(trim((string)($item['metric_key'] ?? '')));
            if ($metricKey === '') {
                continue;
            }
            $bounds = is_array($item['bounds'] ?? null) ? $item['bounds'] : [];
            $lower = $this->firstNumeric([$item, $bounds], [
                'lower_bound', 'minimum', 'min_value', 'min_allowed', 'min',
            ]);
            $upper = $this->firstNumeric([$item, $bounds], [
                'upper_bound', 'maximum', 'max_value', 'max_allowed', 'max',
            ]);
            $threshold = $this->numeric($item['threshold'] ?? null);
            $operator = strtolower(trim((string)($item['operator'] ?? $item['comparison'] ?? '')));
            if ($threshold !== null && $lower === null && $upper === null) {
                if (in_array($operator, ['>=', 'gte', 'minimum', 'not_below'], true)) {
                    $lower = $threshold;
                } elseif (in_array($operator, ['<=', 'lte', 'maximum', 'not_above'], true)) {
                    $upper = $threshold;
                }
            }
            $definitions[$metricKey] = [
                'lower_bound' => $lower,
                'upper_bound' => $upper,
            ];
        }
        return $definitions;
    }

    /** @param mixed $raw @return array<string, array<string, mixed>> */
    private function guardObservations(mixed $raw): array
    {
        $items = $this->decodeArray($raw);
        $observations = [];
        foreach ($items as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            if (is_array($item['followup_snapshot'] ?? null)) {
                $item = $item['followup_snapshot'];
            }
            $metricKey = is_string($key) && !ctype_digit($key)
                ? strtolower(trim($key))
                : strtolower(trim((string)($item['metric_key'] ?? '')));
            if ($metricKey !== '') {
                $observations[$metricKey] = $item;
            }
        }
        return $observations;
    }

    /** @param array<int, array<string, mixed>> $sources @param array<int, string> $keys */
    private function firstNumeric(array $sources, array $keys): ?float
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source)) {
                    $value = $this->numeric($source[$key]);
                    if ($value !== null) {
                        return $value;
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function arrayField(array $source, string $field, string $jsonField): array
    {
        if (array_key_exists($field, $source)) {
            return $this->decodeArray($source[$field]);
        }
        return $this->decodeArray($source[$jsonField] ?? []);
    }

    /** @return array<mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** @param mixed $value @return array<int, string> */
    private function stringList(mixed $value): array
    {
        $items = $this->decodeArray($value);
        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = strtolower(trim($item));
                if ($item !== '') {
                    $result[] = $item;
                }
            }
        }
        return array_values(array_unique($result));
    }

    /** @param array<string, mixed> $snapshot */
    private function sampleSize(array $snapshot): ?int
    {
        foreach (['sample_size', 'sample_count', 'row_count', 'source_row_count'] as $field) {
            if (array_key_exists($field, $snapshot)) {
                return $this->positiveInteger($snapshot[$field]);
            }
        }
        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_numeric($value) || (float)$value < 1 || floor((float)$value) !== (float)$value) {
            return null;
        }
        return (int)$value;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }

    private function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        return null;
    }

    /** @param mixed $value @return array<int, string> */
    private function evidenceRefs(mixed $value): array
    {
        $items = $this->decodeArray($value);
        $refs = [];
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $refs[] = trim($item);
            }
        }
        return array_values(array_unique($refs));
    }

    private function date(string $value): string
    {
        $candidate = substr(trim($value), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $candidate) !== 1) {
            return '';
        }
        [$year, $month, $day] = array_map('intval', explode('-', $candidate));
        return checkdate($month, $day, $year) ? $candidate : '';
    }

    /** @param array<int, string> $reasons @return array<int, string> */
    private function uniqueReasons(array $reasons): array
    {
        return array_values(array_unique(array_filter(
            $reasons,
            static fn(mixed $reason): bool => is_string($reason) && $reason !== ''
        )));
    }

    /**
     * @param array<int, string> $reasonCodes
     * @param array<string, mixed> $comparison
     * @param array<int, array<string, mixed>> $guardResults
     * @return array<string, mixed>
     */
    private function result(
        string $verdict,
        array $reasonCodes,
        array $comparison,
        array $guardResults
    ): array {
        $summary = match ($verdict) {
            'supported' => '同口径观察值达到预声明阈值，且保护指标未越界；这仅支持关联性观察，不证明干预导致结果。',
            'contradicted' => '完整观察证据触发停止条件、保护指标越界或目标指标反向；这不证明干预导致不利结果。',
            default => '证据、时间窗、可比性或干扰控制不足，当前不能支持或否定该干预。',
        };
        return [
            'verdict' => $verdict,
            'reason_codes' => $this->uniqueReasons($reasonCodes),
            'comparison' => $comparison,
            'guard_results' => $guardResults,
            'result_summary' => $summary,
            'causality_claimed' => false,
        ];
    }
}
