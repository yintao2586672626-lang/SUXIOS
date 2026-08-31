<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds one comparable evidence result without merging platform semantics.
 *
 * The caller remains responsible for platform-specific field extraction. This
 * service only checks whether two already persisted facts are safe to compare
 * and whether an executed action has one source-backed review observation.
 */
final class LongitudinalEvidenceLearningService
{
    public const CONTRACT_VERSION = 'longitudinal_evidence_learning.v1';

    private const COMPARISON_MODES = [
        'same_day_realtime',
        'same_length_period',
        'target_stay_observation',
    ];

    private const COMPARABLE_SCOPE_FIELDS = [
        'system_hotel_id',
        'platform',
        'platform_hotel_id',
        'business_module',
        'subject',
        'metric_key',
        'unit',
        'source_method',
        'date_role',
        'fact_scope',
    ];

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $followup
     * @return array<string, mixed>
     */
    public function compareSnapshots(
        array $baseline,
        array $followup,
        string $comparisonMode = 'same_length_period'
    ): array {
        $comparisonMode = strtolower(trim($comparisonMode));
        if (!in_array($comparisonMode, self::COMPARISON_MODES, true)) {
            return $this->notComparable('comparison_mode_invalid', $comparisonMode);
        }

        [$baseline, $baselineGaps] = $this->normalizeSnapshot($baseline, 'baseline');
        [$followup, $followupGaps] = $this->normalizeSnapshot($followup, 'followup');
        $gaps = array_values(array_unique(array_merge($baselineGaps, $followupGaps)));
        if ($gaps !== []) {
            return $this->notComparable($gaps[0], $comparisonMode, $gaps);
        }

        foreach (self::COMPARABLE_SCOPE_FIELDS as $field) {
            if ((string)$baseline[$field] !== (string)$followup[$field]) {
                return $this->notComparable(
                    'scope_mismatch:' . $field,
                    $comparisonMode,
                    ['scope_mismatch:' . $field]
                );
            }
        }

        $baselineCapturedAt = $this->timestamp((string)$baseline['captured_at']);
        $followupCapturedAt = $this->timestamp((string)$followup['captured_at']);
        if ($baselineCapturedAt === null
            || $followupCapturedAt === null
            || $followupCapturedAt <= $baselineCapturedAt
        ) {
            return $this->notComparable(
                'capture_time_not_ascending',
                $comparisonMode,
                ['capture_time_not_ascending']
            );
        }

        $periodCheck = $this->validatePeriods($baseline, $followup, $comparisonMode);
        if ($periodCheck !== '') {
            return $this->notComparable($periodCheck, $comparisonMode, [$periodCheck]);
        }

        $baselineValue = (float)$baseline['value'];
        $followupValue = (float)$followup['value'];
        $delta = round($followupValue - $baselineValue, 6);
        $movement = $delta > 0.000001
            ? 'increase'
            : ($delta < -0.000001 ? 'decrease' : 'unchanged');
        $relativePercent = abs($baselineValue) > 0.000001
            ? round($delta / abs($baselineValue) * 100, 4)
            : null;
        $comparisonKey = $this->comparisonKey($baseline);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'verified',
            'reason_code' => '',
            'comparison_mode' => $comparisonMode,
            'comparison_key' => $comparisonKey,
            'learning_stage' => 'observation',
            'baseline' => $this->publicSnapshot($baseline),
            'followup' => $this->publicSnapshot($followup),
            'delta' => [
                'absolute' => $delta,
                'relative_percent' => $relativePercent,
                'movement' => $movement,
            ],
            'causality_claimed' => false,
            'promotion' => [
                'eligible' => false,
                'next_stage' => 'pattern_candidate',
                'reason_code' => 'single_comparison_is_observation_only',
            ],
            'data_gaps' => [],
        ];
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $followup
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function reviewAction(
        array $baseline,
        array $followup,
        array $action,
        string $comparisonMode = 'same_length_period'
    ): array {
        $comparison = $this->compareSnapshots($baseline, $followup, $comparisonMode);
        if (($comparison['status'] ?? '') !== 'verified') {
            return $comparison;
        }

        $actionRef = trim((string)($action['action_ref'] ?? ''));
        $executionStatus = strtolower(trim((string)($action['execution_status'] ?? '')));
        $executedAt = trim((string)($action['executed_at'] ?? ''));
        $executionEvidenceRefs = $this->evidenceRefs($action['evidence_refs'] ?? []);
        if ($actionRef === ''
            || $executionStatus !== 'executed'
            || $executionEvidenceRefs === []
            || $this->timestamp($executedAt) === null
        ) {
            return $this->notComparable(
                'execution_evidence_incomplete',
                $comparisonMode,
                ['execution_evidence_incomplete']
            );
        }

        $baselineCapturedAt = $this->timestamp((string)($comparison['baseline']['captured_at'] ?? ''));
        $followupCapturedAt = $this->timestamp((string)($comparison['followup']['captured_at'] ?? ''));
        $executedTimestamp = $this->timestamp($executedAt);
        if ($baselineCapturedAt === null
            || $followupCapturedAt === null
            || $executedTimestamp === null
            || $executedTimestamp < $baselineCapturedAt
            || $executedTimestamp >= $followupCapturedAt
        ) {
            return $this->notComparable(
                'execution_time_outside_evidence_window',
                $comparisonMode,
                ['execution_time_outside_evidence_window']
            );
        }

        $expectedDirection = strtolower(trim((string)($action['expected_direction'] ?? '')));
        if (!in_array($expectedDirection, ['increase', 'decrease', 'unchanged', ''], true)) {
            return $this->notComparable(
                'expected_direction_invalid',
                $comparisonMode,
                ['expected_direction_invalid']
            );
        }
        $actionType = strtolower(trim((string)($action['action_type'] ?? '')));
        if ($actionType === '' || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $actionType) !== 1) {
            return $this->notComparable(
                'action_identity_incomplete',
                $comparisonMode,
                ['action_identity_incomplete']
            );
        }
        $movement = (string)($comparison['delta']['movement'] ?? 'unchanged');
        $expectationStatus = $expectedDirection === ''
            ? 'not_declared'
            : ($movement === $expectedDirection ? 'aligned' : 'contradicted');
        $patternKey = 'pattern:' . hash('sha256', implode('|', [
            (string)$comparison['comparison_key'],
            $actionType,
            $expectedDirection,
        ]));

        $comparison['learning_stage'] = 'action_reviewed';
        $comparison['pattern_key'] = $patternKey;
        $comparison['action'] = [
            'action_ref' => $actionRef,
            'action_type' => $actionType,
            'execution_status' => 'executed',
            'executed_at' => $executedAt,
            'evidence_refs' => $executionEvidenceRefs,
            'expected_direction' => $expectedDirection,
            'expectation_status' => $expectationStatus,
        ];
        $comparison['promotion'] = [
            'eligible' => false,
            'next_stage' => 'candidate_sop',
            'reason_code' => 'one_review_cannot_become_sop',
        ];

        return $comparison;
    }

    /**
     * Groups persisted action reviews by their strict comparison identity.
     *
     * A repeated, contradiction-free signal may become a pattern candidate,
     * but this method never promotes it to an SOP and never claims causality.
     *
     * @param array<int, mixed> $reviews
     * @return array<string, mixed>
     */
    public function summarizeReviews(array $reviews, int $minimumSamples = 3): array
    {
        $minimumSamples = max(3, min(20, $minimumSamples));
        $groups = [];
        $seenActions = [];
        $seenFollowups = [];
        $rejectedCount = 0;
        $duplicateCount = 0;
        $indeterminateCount = 0;

        foreach ($reviews as $review) {
            if (!is_array($review)
                || ($review['status'] ?? '') !== 'verified'
                || ($review['learning_stage'] ?? '') !== 'action_reviewed'
                || ($review['causality_claimed'] ?? true) !== false
            ) {
                $rejectedCount++;
                continue;
            }
            $comparisonKey = trim((string)($review['comparison_key'] ?? ''));
            $action = is_array($review['action'] ?? null) ? $review['action'] : [];
            $baseline = is_array($review['baseline'] ?? null) ? $review['baseline'] : [];
            $followup = is_array($review['followup'] ?? null) ? $review['followup'] : [];
            $actionRef = trim((string)($action['action_ref'] ?? ''));
            $actionType = strtolower(trim((string)($action['action_type'] ?? '')));
            $expectedDirection = strtolower(trim((string)($action['expected_direction'] ?? '')));
            $expectationStatus = strtolower(trim((string)($action['expectation_status'] ?? '')));
            $actionEvidenceRefs = $this->evidenceRefs($action['evidence_refs'] ?? []);
            $validated = $this->compareSnapshots(
                $baseline,
                $followup,
                (string)($review['comparison_mode'] ?? 'same_length_period')
            );
            $strictScopeVerified = ($validated['status'] ?? '') === 'verified'
                && hash_equals((string)($validated['comparison_key'] ?? ''), $comparisonKey)
                && $actionEvidenceRefs !== [];
            $validatedFollowup = $strictScopeVerified && is_array($validated['followup'] ?? null)
                ? $validated['followup']
                : $followup;
            $capturedAt = trim((string)($validatedFollowup['captured_at'] ?? ''));
            $periodStart = $this->date((string)($validatedFollowup['period_start'] ?? ''));
            $periodEnd = $this->date((string)($validatedFollowup['period_end'] ?? ''));
            $followupRefs = $this->evidenceRefs($validatedFollowup['evidence_refs'] ?? []);
            $movement = $strictScopeVerified
                ? (string)($validated['delta']['movement'] ?? 'unknown')
                : (string)($review['delta']['movement'] ?? 'unknown');
            $expectedExpectationStatus = $movement === $expectedDirection
                ? 'aligned'
                : 'contradicted';
            if (!in_array($expectationStatus, ['aligned', 'contradicted'], true)
                || ($expectationStatus === 'aligned' && $expectedExpectationStatus !== 'aligned')
            ) {
                $expectationStatus = 'indeterminate';
                $indeterminateCount++;
            }
            if (preg_match('/^longitudinal:[a-f0-9]{64}$/D', $comparisonKey) !== 1
                || $actionRef === ''
                || preg_match('/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $actionType) !== 1
                || !in_array($expectedDirection, ['increase', 'decrease', 'unchanged'], true)
                || $this->timestamp($capturedAt) === null
                || $periodStart === ''
                || $periodEnd === ''
                || $followupRefs === []
            ) {
                $rejectedCount++;
                continue;
            }
            $patternKey = 'pattern:' . hash('sha256', implode('|', [
                $comparisonKey,
                $actionType,
                $expectedDirection,
            ]));
            $actionFingerprint = hash('sha256', $patternKey . '|' . $actionRef);
            $followupFingerprint = hash('sha256', implode('|', [
                $patternKey,
                $periodStart,
                $periodEnd,
                $capturedAt,
                implode(',', $followupRefs),
            ]));
            if (isset($seenActions[$actionFingerprint]) || isset($seenFollowups[$followupFingerprint])) {
                $duplicateCount++;
                continue;
            }
            $seenActions[$actionFingerprint] = true;
            $seenFollowups[$followupFingerprint] = true;
            $groups[$patternKey]['comparison_key'] = $comparisonKey;
            $groups[$patternKey]['action_type'] = $actionType;
            $groups[$patternKey]['expected_direction'] = $expectedDirection;
            $groups[$patternKey]['strict_scope_verified'] =
                (bool)($groups[$patternKey]['strict_scope_verified'] ?? true)
                && $strictScopeVerified;
            if (($groups[$patternKey]['strict_scope_verified'] ?? false) === true) {
                $groups[$patternKey]['scope'] = $this->patternScope(
                    is_array($validated['baseline'] ?? null) ? $validated['baseline'] : []
                );
            } else {
                $groups[$patternKey]['scope'] = [];
            }
            $groups[$patternKey]['samples'][] = [
                'action_ref' => $actionRef,
                'captured_at' => $capturedAt,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'expectation_status' => $expectationStatus,
                'movement' => $movement,
                'evidence_refs' => array_values(array_unique(array_merge(
                    $actionEvidenceRefs,
                    $followupRefs
                ))),
            ];
        }

        $items = [];
        foreach ($groups as $patternKey => $group) {
            $samples = is_array($group['samples'] ?? null) ? $group['samples'] : [];
            $comparisonKey = (string)($group['comparison_key'] ?? '');
            usort(
                $samples,
                fn(array $left, array $right): int => ($this->timestamp($left['captured_at']) ?? 0)
                    <=> ($this->timestamp($right['captured_at']) ?? 0)
            );
            $aligned = count(array_filter(
                $samples,
                static fn(array $sample): bool => $sample['expectation_status'] === 'aligned'
            ));
            $contradicted = count(array_filter(
                $samples,
                static fn(array $sample): bool => $sample['expectation_status'] === 'contradicted'
            ));
            $notDeclared = count($samples) - $aligned - $contradicted;
            $patternReady = count($samples) >= $minimumSamples
                && $contradicted === 0
                && $notDeclared === 0;
            $outcomeTieBreakReady = $patternReady
                && ($group['strict_scope_verified'] ?? false) === true;
            $status = $patternReady
                ? 'pattern_candidate'
                : ($contradicted > 0 ? 'contradictory_evidence' : 'accumulating');
            $evidenceRefs = [];
            foreach ($samples as $sample) {
                $evidenceRefs = array_merge($evidenceRefs, $sample['evidence_refs']);
            }
            $evidenceRefs = array_values(array_unique($evidenceRefs));
            sort($evidenceRefs, SORT_STRING);

            $items[] = [
                'pattern_key' => $patternKey,
                'comparison_key' => $comparisonKey,
                'action_type' => (string)($group['action_type'] ?? ''),
                'expected_direction' => (string)($group['expected_direction'] ?? ''),
                'scope' => (array)($group['scope'] ?? []),
                'strict_scope_verified' => (bool)($group['strict_scope_verified'] ?? false),
                'status' => $status,
                'learning_stage' => $patternReady ? 'pattern_candidate' : 'action_reviewed',
                'sample_count' => count($samples),
                'minimum_samples' => $minimumSamples,
                'aligned_count' => $aligned,
                'contradicted_count' => $contradicted,
                'not_declared_count' => $notDeclared,
                'last_reviewed_at' => (string)($samples[count($samples) - 1]['captured_at'] ?? ''),
                'evidence_refs' => $evidenceRefs,
                'causality_claimed' => false,
                'candidate_sop_eligible' => false,
                'outcome_tie_break_eligible' => $outcomeTieBreakReady,
                'next_action' => $patternReady
                    ? '由人工复核适用范围、反例和停止条件后，再决定是否进入候选SOP。'
                    : ($contradicted > 0
                        ? '保留冲突样本，复核执行差异、口径变化和外部环境，不继续晋级。'
                        : '继续积累同店、同平台、同对象、同指标和同口径的独立复盘样本。'),
            ];
        }
        usort(
            $items,
            static fn(array $left, array $right): int => strcmp(
                (string)$left['comparison_key'],
                (string)$right['comparison_key']
            )
        );
        $patternCandidateCount = count(array_filter(
            $items,
            static fn(array $item): bool => $item['learning_stage'] === 'pattern_candidate'
        ));
        $outcomeTieBreakCandidateCount = count(array_filter(
            $items,
            static fn(array $item): bool => ($item['outcome_tie_break_eligible'] ?? false) === true
        ));
        $contradictoryCount = count(array_filter(
            $items,
            static fn(array $item): bool => $item['status'] === 'contradictory_evidence'
        ));

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $items === []
                ? 'missing'
                : ($patternCandidateCount > 0
                    ? 'pattern_candidate'
                    : ($contradictoryCount > 0 ? 'contradictory_evidence' : 'accumulating')),
            'reviewed_observation_count' => count($seenActions),
            'rejected_review_count' => $rejectedCount,
            'duplicate_review_count' => $duplicateCount,
            'indeterminate_review_count' => $indeterminateCount,
            'pattern_candidate_count' => $patternCandidateCount,
            'outcome_tie_break_candidate_count' => $outcomeTieBreakCandidateCount,
            'outcome_tie_break_status' => $outcomeTieBreakCandidateCount > 0
                ? 'eligible'
                : 'not_eligible',
            'contradictory_pattern_count' => $contradictoryCount,
            'items' => $items,
            'causality_claimed' => false,
            'automatic_sop_promotion' => false,
            'outcome_tie_break_policy' => [
                'minimum_independent_samples' => $minimumSamples,
                'requires_same_comparison_key' => true,
                'requires_zero_contradictions' => true,
                'requires_zero_indeterminate_samples' => true,
                'position' => 'after_exact_four_dimension_tie_before_stable_candidate_key',
                'changes_fact_or_eligibility' => false,
                'changes_approval_or_execution_authority' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,int|string> */
    private function patternScope(array $snapshot): array
    {
        return [
            'system_hotel_id' => max(0, (int)($snapshot['system_hotel_id'] ?? 0)),
            'platform' => strtolower(trim((string)($snapshot['platform'] ?? ''))),
            'platform_hotel_id' => trim((string)($snapshot['platform_hotel_id'] ?? '')),
            'business_module' => strtolower(trim((string)($snapshot['business_module'] ?? ''))),
            'subject' => mb_strtolower(trim((string)($snapshot['subject'] ?? ''))),
            'metric_key' => strtolower(trim((string)($snapshot['metric_key'] ?? ''))),
            'unit' => strtolower(trim((string)($snapshot['unit'] ?? ''))),
            'source_method' => strtolower(trim((string)($snapshot['source_method'] ?? ''))),
            'date_role' => strtolower(trim((string)($snapshot['date_role'] ?? ''))),
            'fact_scope' => strtolower(trim((string)($snapshot['fact_scope'] ?? ''))),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{0:array<string, mixed>,1:array<int, string>}
     */
    private function normalizeSnapshot(array $snapshot, string $role): array
    {
        $normalized = [
            'system_hotel_id' => max(0, (int)($snapshot['system_hotel_id'] ?? 0)),
            'platform' => strtolower(trim((string)($snapshot['platform'] ?? ''))),
            'platform_hotel_id' => trim((string)($snapshot['platform_hotel_id'] ?? '')),
            'business_module' => strtolower(trim((string)($snapshot['business_module'] ?? ''))),
            'subject' => mb_strtolower(trim((string)($snapshot['subject'] ?? ''))),
            'metric_key' => strtolower(trim((string)($snapshot['metric_key'] ?? ''))),
            'unit' => strtolower(trim((string)($snapshot['unit'] ?? ''))),
            'source_method' => strtolower(trim((string)($snapshot['source_method'] ?? ''))),
            'date_role' => strtolower(trim((string)($snapshot['date_role'] ?? ''))),
            'fact_scope' => strtolower(trim((string)($snapshot['fact_scope'] ?? ''))),
            'period_start' => $this->date((string)($snapshot['period_start'] ?? $snapshot['data_date'] ?? '')),
            'period_end' => $this->date((string)($snapshot['period_end'] ?? $snapshot['data_date'] ?? '')),
            'target_stay_date' => $this->date((string)($snapshot['target_stay_date'] ?? '')),
            'captured_at' => trim((string)($snapshot['captured_at'] ?? '')),
            'quality_status' => strtolower(trim((string)($snapshot['quality_status'] ?? ''))),
            'readback_status' => strtolower(trim((string)($snapshot['readback_status'] ?? ''))),
            'value' => is_numeric($snapshot['value'] ?? null) ? (float)$snapshot['value'] : null,
            'evidence_refs' => $this->evidenceRefs($snapshot['evidence_refs'] ?? []),
        ];

        $gaps = [];
        foreach ([
            'system_hotel_id',
            'platform',
            'platform_hotel_id',
            'business_module',
            'subject',
            'metric_key',
            'unit',
            'source_method',
            'date_role',
            'fact_scope',
            'period_start',
            'period_end',
            'captured_at',
        ] as $field) {
            if ($normalized[$field] === '' || $normalized[$field] === 0) {
                $gaps[] = $role . '_' . $field . '_missing';
            }
        }
        if ($normalized['value'] === null) {
            $gaps[] = $role . '_value_missing';
        }
        if ($normalized['quality_status'] !== 'verified') {
            $gaps[] = $role . '_quality_unverified';
        }
        if ($normalized['readback_status'] !== 'readback_verified') {
            $gaps[] = $role . '_readback_unverified';
        }
        if ($normalized['evidence_refs'] === []) {
            $gaps[] = $role . '_evidence_ref_missing';
        }
        if ($this->timestamp($normalized['captured_at']) === null) {
            $gaps[] = $role . '_captured_at_invalid';
        }

        return [$normalized, $gaps];
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $followup
     */
    private function validatePeriods(array $baseline, array $followup, string $mode): string
    {
        $baselineStart = $this->dateObject((string)$baseline['period_start']);
        $baselineEnd = $this->dateObject((string)$baseline['period_end']);
        $followupStart = $this->dateObject((string)$followup['period_start']);
        $followupEnd = $this->dateObject((string)$followup['period_end']);
        if ($baselineStart === null
            || $baselineEnd === null
            || $followupStart === null
            || $followupEnd === null
            || $baselineEnd < $baselineStart
            || $followupEnd < $followupStart
        ) {
            return 'period_invalid';
        }

        if ($mode === 'same_day_realtime') {
            return $baselineStart == $baselineEnd
                && $followupStart == $followupEnd
                && $baselineStart == $followupStart
                ? ''
                : 'same_day_scope_mismatch';
        }

        if ($mode === 'target_stay_observation') {
            $baselineTarget = (string)($baseline['target_stay_date'] ?? '');
            $followupTarget = (string)($followup['target_stay_date'] ?? '');
            return $baselineTarget !== ''
                && $baselineTarget === $followupTarget
                ? ''
                : 'target_stay_date_mismatch';
        }

        $baselineDays = (int)$baselineStart->diff($baselineEnd)->format('%a') + 1;
        $followupDays = (int)$followupStart->diff($followupEnd)->format('%a') + 1;
        if ($baselineDays !== $followupDays) {
            return 'period_length_mismatch';
        }
        return $followupStart > $baselineEnd ? '' : 'followup_period_not_after_baseline';
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'system_hotel_id' => $snapshot['system_hotel_id'],
            'platform' => $snapshot['platform'],
            'platform_hotel_id' => $snapshot['platform_hotel_id'],
            'business_module' => $snapshot['business_module'],
            'subject' => $snapshot['subject'],
            'metric_key' => $snapshot['metric_key'],
            'unit' => $snapshot['unit'],
            'source_method' => $snapshot['source_method'],
            'date_role' => $snapshot['date_role'],
            'fact_scope' => $snapshot['fact_scope'],
            'period_start' => $snapshot['period_start'],
            'period_end' => $snapshot['period_end'],
            'target_stay_date' => $snapshot['target_stay_date'] !== ''
                ? $snapshot['target_stay_date']
                : null,
            'captured_at' => $snapshot['captured_at'],
            'value' => $snapshot['value'],
            'evidence_refs' => $snapshot['evidence_refs'],
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function comparisonKey(array $snapshot): string
    {
        $parts = array_map(
            static fn(string $field): string => (string)$snapshot[$field],
            self::COMPARABLE_SCOPE_FIELDS
        );
        return 'longitudinal:' . hash('sha256', implode('|', $parts));
    }

    /**
     * @param array<int, string> $gaps
     * @return array<string, mixed>
     */
    private function notComparable(
        string $reasonCode,
        string $comparisonMode,
        array $gaps = []
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'not_comparable',
            'reason_code' => $reasonCode,
            'comparison_mode' => $comparisonMode,
            'comparison_key' => null,
            'learning_stage' => 'observation',
            'baseline' => null,
            'followup' => null,
            'delta' => [
                'absolute' => null,
                'relative_percent' => null,
                'movement' => 'unknown',
            ],
            'causality_claimed' => false,
            'promotion' => [
                'eligible' => false,
                'next_stage' => null,
                'reason_code' => 'comparison_not_verified',
            ],
            'data_gaps' => $gaps !== [] ? array_values(array_unique($gaps)) : [$reasonCode],
        ];
    }

    /** @return array<int, string> */
    private function evidenceRefs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $refs = array_values(array_unique(array_filter(
            array_map(static fn(mixed $ref): string => trim((string)$ref), $value),
            static fn(string $ref): bool => preg_match(
                '/^(?:online_daily_data|dingdandao_operating_target_captures|meituan_cloud_pms_captures|operation_execution_task)[#:][A-Za-z0-9._-]+$/D',
                $ref
            ) === 1
        )));
        sort($refs, SORT_STRING);
        return $refs;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
            && $this->dateObject($value) !== null
            ? $value
            : '';
    }

    private function dateObject(string $value): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
