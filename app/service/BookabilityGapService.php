<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;

/**
 * Pure comparison between PMS expected availability and supplied guest-side
 * journey observations. It performs no collection, booking, persistence or
 * OTA mutation.
 *
 * Canonical input:
 * - business_date: Y-m-d
 * - pms_expected_sellable: non-negative integer room nights
 * - platform: OTA platform name
 * - observations: list of guest conditions with adults, children, benefits,
 *   search/detail/pre_checkout statuses, observed_at, source_quality and
 *   evidence_ref
 * - real_demand_estimate: optional non-negative demand for the complete
 *   evaluated condition set
 */
final class BookabilityGapService
{
    public const CONTRACT_VERSION = 'bookability_gap.v2';

    private const STAGES = ['search', 'detail', 'pre_checkout'];
    private const STAGE_RANK = [
        'search' => 0,
        'detail' => 1,
        'pre_checkout' => 2,
    ];
    private const PASS_STATUSES = [
        'available',
        'bookable',
        'found',
        'pass',
        'passed',
        'reachable',
        'reached',
        'ready',
        'shown',
        'success',
        'visible',
    ];
    private const FAIL_STATUSES = [
        'blocked',
        'closed',
        'error',
        'fail',
        'failed',
        'hidden',
        'not_available',
        'not_bookable',
        'not_found',
        'sold_out',
        'unavailable',
        'unreachable',
    ];
    private const VERIFIED_SOURCE_QUALITIES = [
        'direct_verified',
        'guest_journey_verified',
        'live_verified',
        'manual_verified',
        'readback_verified',
        'verified',
        'verified_live',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $businessDate = $this->text($input['business_date'] ?? null);
        $platform = $this->status($input['platform'] ?? null);
        $pmsRaw = $input['pms_expected_sellable']
            ?? $input['pms_expected_sellable_count']
            ?? null;
        $pmsExpectedSellable = $this->nonNegativeInteger($pmsRaw);
        $observationsRaw = $input['observations'] ?? $input['guest_observations'] ?? null;
        $missingEvidence = [];

        if (!$this->validBusinessDate($businessDate)) {
            $missingEvidence[] = $this->missing('business_date_invalid', 'business_date');
        }
        if ($platform === '' || in_array($platform, ['backend', 'internal', 'pms'], true)) {
            $missingEvidence[] = $this->missing('guest_platform_invalid', 'platform');
        }
        if ($pmsExpectedSellable === null) {
            $missingEvidence[] = $this->missing(
                'pms_expected_sellable_invalid',
                'pms_expected_sellable'
            );
        }
        if (!is_array($observationsRaw) || $observationsRaw === []) {
            $missingEvidence[] = $this->missing('guest_observations_missing', 'observations');
        }

        $observations = is_array($observationsRaw) ? array_values($observationsRaw) : [];
        $evaluatedConditions = [];
        $conditionIds = [];

        foreach ($observations as $index => $observation) {
            $fallbackId = 'condition_' . ($index + 1);
            if (!is_array($observation)) {
                $missingEvidence[] = $this->missing(
                    'observation_invalid',
                    'observations.' . $index,
                    $fallbackId
                );
                continue;
            }

            [$condition, $conditionMissing] = $this->condition($observation, $index);
            $conditionId = (string)$condition['condition_id'];
            foreach ($conditionMissing as $field) {
                $missingEvidence[] = $this->missing(
                    'condition_' . $field . '_invalid',
                    $field,
                    $conditionId
                );
            }
            if (isset($conditionIds[$conditionId])) {
                $conditionMissing[] = 'condition_id';
                $missingEvidence[] = $this->missing(
                    'condition_id_duplicate',
                    'condition_id',
                    $conditionId
                );
            }
            $conditionIds[$conditionId] = true;

            $observedAt = $this->text($observation['observed_at'] ?? null);
            if (!$this->validObservedAt($observedAt)) {
                $conditionMissing[] = 'observed_at';
                $missingEvidence[] = $this->missing(
                    'observed_at_invalid',
                    'observed_at',
                    $conditionId
                );
            } elseif ($this->observedBusinessDate($observedAt) !== $businessDate) {
                $conditionMissing[] = 'observed_at';
                $missingEvidence[] = $this->missing(
                    'observed_at_business_date_mismatch',
                    'observed_at',
                    $conditionId
                );
            }

            $sourceQuality = $this->status($observation['source_quality'] ?? null);
            if (!in_array($sourceQuality, self::VERIFIED_SOURCE_QUALITIES, true)) {
                $conditionMissing[] = 'source_quality';
                $missingEvidence[] = $this->missing(
                    'source_quality_not_verified',
                    'source_quality',
                    $conditionId
                );
            }

            $evidenceRef = $this->text($observation['evidence_ref'] ?? null);
            if ($evidenceRef === '') {
                $conditionMissing[] = 'evidence_ref';
                $missingEvidence[] = $this->missing(
                    'evidence_ref_missing',
                    'evidence_ref',
                    $conditionId
                );
            }

            $journey = $this->journey($observation);
            if ($journey['outcome'] === null) {
                $conditionMissing[] = (string)$journey['missing_stage'];
                $missingEvidence[] = $this->missing(
                    'stage_status_missing_or_unknown',
                    (string)$journey['missing_stage'],
                    $conditionId
                );
            }

            if ($conditionMissing !== [] || $journey['outcome'] === null) {
                continue;
            }

            $evaluatedConditions[] = array_merge($condition, [
                'outcome' => $journey['outcome'],
                'failure_stage' => $journey['failure_stage'],
                'observed_at' => $observedAt,
                'source_quality' => $sourceQuality,
                'evidence_ref' => $evidenceRef,
            ]);
        }

        $topLevelValid = $this->validBusinessDate($businessDate)
            && $platform !== ''
            && !in_array($platform, ['backend', 'internal', 'pms'], true)
            && $pmsExpectedSellable !== null
            && $observations !== [];
        $affectedConditions = [];

        if ($topLevelValid) {
            foreach ($evaluatedConditions as $condition) {
                $mismatchType = null;
                if ($pmsExpectedSellable > 0 && $condition['outcome'] === 'blocked') {
                    $mismatchType = 'pms_sellable_guest_blocked';
                } elseif ($pmsExpectedSellable === 0 && $condition['outcome'] === 'bookable') {
                    $mismatchType = 'pms_unavailable_guest_bookable';
                }
                if ($mismatchType === null) {
                    continue;
                }
                $affectedConditions[] = array_merge($condition, [
                    'mismatch_type' => $mismatchType,
                ]);
            }
        }

        $blockedByMissingEvidence = $missingEvidence !== [];
        $gapDetected = $affectedConditions !== [];
        $aligned = $topLevelValid
            && !$blockedByMissingEvidence
            && count($evaluatedConditions) === count($observations)
            && !$gapDetected;
        $earliestFailureStage = $this->earliestFailureStage($affectedConditions);
        $potentialLoss = $this->potentialLoss(
            $input,
            $pmsExpectedSellable,
            $gapDetected,
            $blockedByMissingEvidence,
            $evaluatedConditions,
            $affectedConditions
        );

        return [
            'aligned' => $aligned,
            'gap_detected' => $gapDetected,
            'blocked_by_missing_evidence' => $blockedByMissingEvidence,
            'earliest_failure_stage' => $earliestFailureStage,
            'affected_conditions' => $affectedConditions,
            'missing_evidence' => $missingEvidence,
            'potential_loss' => $potentialLoss['value'],
            'potential_loss_unit' => $potentialLoss['value'] === null ? null : 'room_nights',
            'potential_loss_basis' => [
                'status' => $potentialLoss['value'] === null ? 'not_calculated' : 'calculated',
                'reason' => $potentialLoss['reason'],
                'formula' => $potentialLoss['value'] === null
                    ? null
                    : 'min(pms_expected_sellable, real_demand_estimate)',
            ],
            'retest_requirements' => $this->retestRequirements(
                $missingEvidence,
                $affectedConditions,
                $platform,
                $businessDate
            ),
            'source_boundary' => $this->sourceBoundary($platform, $businessDate),
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }

    /**
     * @param array<string, mixed> $observation
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function condition(array $observation, int $index): array
    {
        $missing = [];
        $conditionId = $this->text($observation['condition_id'] ?? null);
        if ($conditionId === '') {
            $conditionId = 'condition_' . ($index + 1);
        }

        $party = is_array($observation['party'] ?? null) ? $observation['party'] : [];
        $adults = $this->nonNegativeInteger($observation['adults'] ?? $party['adults'] ?? null);
        if ($adults === null || $adults < 1) {
            $missing[] = 'adults';
            $adults = null;
        }

        $childrenPresent = array_key_exists('children', $observation)
            || array_key_exists('children', $party);
        $childrenRaw = $observation['children'] ?? $party['children'] ?? null;
        $childAges = null;
        if (is_array($childrenRaw)) {
            $childAges = [];
            foreach ($childrenRaw as $age) {
                $normalizedAge = $this->nonNegativeInteger($age);
                if ($normalizedAge === null) {
                    $missing[] = 'children';
                    $childAges = null;
                    break;
                }
                $childAges[] = $normalizedAge;
            }
            $children = $childAges === null ? null : count($childAges);
        } else {
            $children = $this->nonNegativeInteger($childrenRaw);
        }
        if (!$childrenPresent || $children === null) {
            $missing[] = 'children';
            $children = null;
        }

        [$benefitsPresent, $benefitsRaw] = $this->firstPresent(
            $observation,
            ['benefits', 'entitlements', 'rights']
        );
        $benefits = $this->benefits($benefitsRaw);
        if (!$benefitsPresent || $benefits === null) {
            $missing[] = 'benefits';
            $benefits = null;
        }

        return [[
            'condition_id' => $conditionId,
            'adults' => $adults,
            'children' => $children,
            'child_ages' => $childAges,
            'benefits' => $benefits,
        ], array_values(array_unique($missing))];
    }

    /**
     * The first explicit failure is enough evidence for the break point; no
     * downstream stage is required after the guest journey has already failed.
     *
     * @param array<string, mixed> $observation
     * @return array{outcome: ?string, failure_stage: ?string, missing_stage: ?string}
     */
    private function journey(array $observation): array
    {
        foreach (self::STAGES as $stage) {
            $classification = $this->stageClassification($this->stageValue($observation, $stage));
            if ($classification === 'pass') {
                continue;
            }
            if ($classification === 'fail') {
                return [
                    'outcome' => 'blocked',
                    'failure_stage' => $stage,
                    'missing_stage' => null,
                ];
            }
            return [
                'outcome' => null,
                'failure_stage' => null,
                'missing_stage' => $stage,
            ];
        }

        return [
            'outcome' => 'bookable',
            'failure_stage' => null,
            'missing_stage' => null,
        ];
    }

    /** @param array<string, mixed> $observation */
    private function stageValue(array $observation, string $stage): mixed
    {
        $stages = is_array($observation['stages'] ?? null) ? $observation['stages'] : [];
        $value = $observation[$stage]
            ?? $observation[$stage . '_status']
            ?? $stages[$stage]
            ?? null;
        if (is_array($value)) {
            return $value['status'] ?? $value['state'] ?? null;
        }
        return $value;
    }

    private function stageClassification(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'pass' : 'fail';
        }
        if (!is_string($value)) {
            return null;
        }
        $status = $this->status($value);
        if (in_array($status, self::PASS_STATUSES, true)) {
            return 'pass';
        }
        if (in_array($status, self::FAIL_STATUSES, true)) {
            return 'fail';
        }
        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $affectedConditions
     */
    private function earliestFailureStage(array $affectedConditions): ?string
    {
        $earliest = null;
        $earliestRank = PHP_INT_MAX;
        foreach ($affectedConditions as $condition) {
            $stage = $condition['failure_stage'] ?? null;
            if (!is_string($stage) || !isset(self::STAGE_RANK[$stage])) {
                continue;
            }
            if (self::STAGE_RANK[$stage] < $earliestRank) {
                $earliest = $stage;
                $earliestRank = self::STAGE_RANK[$stage];
            }
        }
        return $earliest;
    }

    /**
     * A single aggregate demand estimate is safe only when every evaluated
     * condition belongs to the verified forward gap. Otherwise condition-level
     * demand would be required and this service deliberately returns null.
     *
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $evaluatedConditions
     * @param array<int, array<string, mixed>> $affectedConditions
     * @return array{value: int|float|null, reason: string}
     */
    private function potentialLoss(
        array $input,
        ?int $pmsExpectedSellable,
        bool $gapDetected,
        bool $blockedByMissingEvidence,
        array $evaluatedConditions,
        array $affectedConditions
    ): array {
        if (!$gapDetected) {
            return ['value' => null, 'reason' => 'no_verified_bookability_gap'];
        }
        if ($blockedByMissingEvidence || $pmsExpectedSellable === null) {
            return ['value' => null, 'reason' => 'missing_core_evidence'];
        }
        $forwardGaps = array_values(array_filter(
            $affectedConditions,
            static fn(array $condition): bool => ($condition['mismatch_type'] ?? '')
                === 'pms_sellable_guest_blocked'
        ));
        if ($pmsExpectedSellable <= 0 || count($forwardGaps) !== count($affectedConditions)) {
            return ['value' => null, 'reason' => 'not_a_guest_blockage_loss'];
        }
        if (!array_key_exists('real_demand_estimate', $input)) {
            return ['value' => null, 'reason' => 'real_demand_estimate_missing'];
        }
        $realDemandEstimate = $this->nonNegativeNumber($input['real_demand_estimate']);
        if ($realDemandEstimate === null) {
            return ['value' => null, 'reason' => 'real_demand_estimate_invalid'];
        }
        if (count($forwardGaps) !== count($evaluatedConditions)) {
            return [
                'value' => null,
                'reason' => 'demand_scope_not_limited_to_affected_conditions',
            ];
        }

        return [
            'value' => $this->normalizedNumber(min($pmsExpectedSellable, $realDemandEstimate)),
            'reason' => 'verified_gap_with_complete_demand_input',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $missingEvidence
     * @param array<int, array<string, mixed>> $affectedConditions
     * @return array<int, string>
     */
    private function retestRequirements(
        array $missingEvidence,
        array $affectedConditions,
        string $platform,
        string $businessDate
    ): array {
        $requirements = [];
        foreach ($missingEvidence as $missing) {
            $conditionId = $this->text($missing['condition_id'] ?? null);
            $field = $this->text($missing['field'] ?? null);
            if ($conditionId === '') {
                $requirements[] = '补齐并校验 ' . $field . ' 后重新评估。';
                continue;
            }
            $requirements[] = sprintf(
                '条件 %s：补齐 %s，并在同一平台、业务日期、人数、儿童和权益条件下重走客人端路径。',
                $conditionId,
                $field
            );
        }
        foreach ($affectedConditions as $condition) {
            $conditionId = (string)($condition['condition_id'] ?? '');
            $failureStage = $condition['failure_stage'] ?? null;
            $mismatchType = (string)($condition['mismatch_type'] ?? '');
            if (is_string($failureStage) && $failureStage !== '') {
                $requirements[] = sprintf(
                    '条件 %s：在 %s、%s 及相同游客条件下从 search 复测至 %s，记录新的 observed_at、已核验 source_quality 和 evidence_ref。',
                    $conditionId,
                    $platform,
                    $businessDate,
                    $failureStage
                );
            } elseif ($mismatchType === 'pms_unavailable_guest_bookable') {
                $requirements[] = sprintf(
                    '条件 %s：复核 %s、%s 的 PMS 可售数与客人端预结算路径是否属于同一库存时点。',
                    $conditionId,
                    $platform,
                    $businessDate
                );
            }
        }
        return array_values(array_unique($requirements));
    }

    private function sourceBoundary(string $platform, string $businessDate): string
    {
        $scope = ($platform === '' ? '指定OTA平台' : $platform)
            . '、'
            . ($businessDate === '' ? '指定业务日期' : $businessDate);
        return '仅比较调用方提供的 ' . $scope
            . ' 客人端 search/detail/pre_checkout 观察与 PMS 预期；'
            . 'source_quality与evidence_ref仅是输入证据标签，本服务不独立核验外部页面；'
            . 'PMS或后台成功不构成客人可订证据，pre_checkout可达也不代表已下单。'
            . '本服务不抓站、不下单、不保存或修改OTA。';
    }

    /** @return array{code: string, field: string, condition_id: ?string} */
    private function missing(string $code, string $field, ?string $conditionId = null): array
    {
        return [
            'code' => $code,
            'field' => $field,
            'condition_id' => $conditionId,
        ];
    }

    private function validBusinessDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function validObservedAt(string $value): bool
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D',
            $value
        ) !== 1) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors === false
                || ((int)($errors['warning_count'] ?? 0) === 0
                    && (int)($errors['error_count'] ?? 0) === 0);
        } catch (\Throwable) {
            return false;
        }
    }

    private function observedBusinessDate(string $value): ?string
    {
        if (!$this->validObservedAt($value)) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai')))
                ->setTimezone(new \DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 && floor($value) === $value
                ? (int)$value
                : null;
        }
        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            return (int)trim($value);
        }
        return null;
    }

    private function nonNegativeNumber(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value)) {
            return is_finite($value) && $value >= 0 ? $value : null;
        }
        if (is_string($value)
            && preg_match('/^(?:\d+|\d+\.\d+|\.\d+)$/D', trim($value)) === 1
        ) {
            $number = (float)trim($value);
            return is_finite($number) ? $this->normalizedNumber($number) : null;
        }
        return null;
    }

    private function normalizedNumber(int|float $value): int|float
    {
        return is_float($value) && floor($value) === $value ? (int)$value : $value;
    }

    /** @return array{0: bool, 1: mixed} */
    private function firstPresent(array $values, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return [true, $values[$key]];
            }
        }
        return [false, null];
    }

    /** @return array<int, string>|null */
    private function benefits(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return null;
        }
        $benefits = [];
        foreach ($value as $benefit) {
            if (!is_scalar($benefit)) {
                return null;
            }
            $text = trim((string)$benefit);
            if ($text === '') {
                return null;
            }
            $benefits[] = $text;
        }
        $benefits = array_values(array_unique($benefits));
        sort($benefits, SORT_STRING);
        return $benefits;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function status(mixed $value): string
    {
        $status = strtolower($this->text($value));
        return str_replace(['-', ' '], '_', $status);
    }
}
