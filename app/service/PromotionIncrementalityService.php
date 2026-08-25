<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/**
 * Estimates promotion incrementality with a two-group, two-period
 * difference-in-differences calculation.
 *
 * A supported or contradicted verdict is a bounded directional assessment.
 * It is not, by itself, proof that the promotion caused the observed result.
 */
final class PromotionIncrementalityService
{
    public const CONTRACT_VERSION = 'promotion_incrementality.v1';
    public const MINIMUM_SAMPLE_SIZE = 30;

    private const EPSILON = 0.000000001;

    /** @var array<int, string> */
    private const REQUIRED_FIELDS = [
        'promotion_name',
        'business_date',
        'treated_before',
        'treated_after',
        'control_before',
        'control_after',
        'discount_cost',
        'contribution_per_incremental_room_night',
        'design_quality',
        'pretrend_status',
        'sample_size',
        'source_quality',
    ];

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $this->assertRequiredFields($input);

        $promotionName = $this->requiredString($input['promotion_name'], 'promotion_name');
        $businessDate = $this->businessDate($input['business_date']);
        $treatedBefore = $this->nonNegativeNumber($input['treated_before'], 'treated_before');
        $treatedAfter = $this->nonNegativeNumber($input['treated_after'], 'treated_after');
        $controlBefore = $this->nonNegativeNumber($input['control_before'], 'control_before');
        $controlAfter = $this->nonNegativeNumber($input['control_after'], 'control_after');
        $discountCost = $this->nonNegativeNumber($input['discount_cost'], 'discount_cost');
        $contributionPerRoomNight = $this->nonNegativeNumber(
            $input['contribution_per_incremental_room_night'],
            'contribution_per_incremental_room_night'
        );
        $designQuality = $this->normalizedStatus($input['design_quality'], 'design_quality');
        $pretrendStatus = $this->normalizedStatus($input['pretrend_status'], 'pretrend_status');
        $sampleSize = $this->positiveInteger($input['sample_size'], 'sample_size');
        $sourceQuality = $this->normalizedStatus($input['source_quality'], 'source_quality');

        $treatedChange = $treatedAfter - $treatedBefore;
        $controlChange = $controlAfter - $controlBefore;
        $incrementalRoomNights = $treatedChange - $controlChange;
        $incrementalContribution = $incrementalRoomNights * $contributionPerRoomNight;
        $netIncrementalProfit = $incrementalContribution - $discountCost;

        $gateReasons = $this->evidenceGateReasons(
            $designQuality,
            $pretrendStatus,
            $sampleSize,
            $sourceQuality
        );
        $evidenceThresholdMet = $gateReasons === [];

        if (!$evidenceThresholdMet) {
            $verdict = 'indeterminate';
            $reasonCodes = $gateReasons;
        } elseif ($incrementalRoomNights > self::EPSILON && $netIncrementalProfit > self::EPSILON) {
            $verdict = 'supported';
            $reasonCodes = ['positive_increment_and_net_profit_estimate'];
        } elseif ($netIncrementalProfit < -self::EPSILON) {
            $verdict = 'contradicted';
            $reasonCodes = [$incrementalRoomNights > self::EPSILON
                ? 'positive_increment_but_negative_net_profit'
                : 'negative_net_profit_estimate'];
        } elseif ($incrementalRoomNights < -self::EPSILON) {
            $verdict = 'contradicted';
            $reasonCodes = ['negative_increment_estimate'];
        } else {
            $verdict = 'indeterminate';
            $reasonCodes = [abs($netIncrementalProfit) <= self::EPSILON
                ? 'net_incremental_profit_zero'
                : 'incremental_room_nights_zero'];
        }

        return [
            'promotion_name' => $promotionName,
            'business_date' => $businessDate,
            'verdict' => $verdict,
            'reason_codes' => $reasonCodes,
            'treated_change' => $treatedChange,
            'control_change' => $controlChange,
            'incremental_room_nights' => $incrementalRoomNights,
            'incremental_contribution' => $incrementalContribution,
            'discount_cost' => $discountCost,
            'net_incremental_profit' => $netIncrementalProfit,
            'design_assessment' => [
                'design_quality' => $designQuality,
                'eligible_design' => in_array(
                    $designQuality,
                    ['randomized', 'validated_matched'],
                    true
                ),
                'pretrend_status' => $pretrendStatus,
                'pretrend_passed' => in_array($pretrendStatus, ['passed', 'parallel'], true),
                'sample_size' => $sampleSize,
                'minimum_sample_size' => self::MINIMUM_SAMPLE_SIZE,
                'sample_size_sufficient' => $sampleSize >= self::MINIMUM_SAMPLE_SIZE,
                'source_quality' => $sourceQuality,
                'source_verified' => in_array(
                    $sourceQuality,
                    ['verified', 'readback_verified'],
                    true
                ),
                'evidence_threshold_met' => $evidenceThresholdMet,
            ],
            'platform_attribution_distinction' => [
                'platform_attribution_equals_incrementality' => false,
                'platform_attribution' => '平台归因是按平台规则归入活动的成交或间夜。',
                'true_incrementality' => '真实增量是相对未开展活动这一反事实新增的间夜；本服务仅以双重差分作估计。',
            ],
            'evidence_boundary' => [
                'estimator' => 'difference_in_differences',
                'formula' => '(treated_after - treated_before) - (control_after - control_before)',
                'causality_claimed' => false,
                'statistical_significance_tested' => false,
                'statement' => $this->evidenceStatement($verdict),
            ],
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }

    /** @param array<string, mixed> $input */
    private function assertRequiredFields(array $input): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === null) {
                throw new InvalidArgumentException('promotion_incrementality_missing_field:' . $field);
            }
        }
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('promotion_incrementality_string_required:' . $field);
        }

        return trim($value);
    }

    private function businessDate(mixed $value): string
    {
        $date = $this->requiredString($value, 'business_date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new InvalidArgumentException('promotion_incrementality_date_invalid:business_date');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('promotion_incrementality_date_invalid:business_date');
        }

        return $date;
    }

    private function nonNegativeNumber(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                'promotion_incrementality_non_negative_number_required:' . $field
            );
        }

        $number = (float)$value;
        if (!is_finite($number) || $number < 0.0) {
            throw new InvalidArgumentException(
                'promotion_incrementality_non_negative_number_required:' . $field
            );
        }

        return $number;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(
                'promotion_incrementality_positive_integer_required:' . $field
            );
        }

        $number = (float)$value;
        if (!is_finite($number)
            || $number < 1.0
            || $number > PHP_INT_MAX
            || floor($number) !== $number
        ) {
            throw new InvalidArgumentException(
                'promotion_incrementality_positive_integer_required:' . $field
            );
        }

        return (int)$number;
    }

    private function normalizedStatus(mixed $value, string $field): string
    {
        return strtolower($this->requiredString($value, $field));
    }

    /** @return array<int, string> */
    private function evidenceGateReasons(
        string $designQuality,
        string $pretrendStatus,
        int $sampleSize,
        string $sourceQuality
    ): array {
        $reasons = [];

        if ($designQuality === 'unverified') {
            $reasons[] = 'design_quality_unverified';
        } elseif (!in_array($designQuality, ['randomized', 'validated_matched'], true)) {
            $reasons[] = 'design_quality_unrecognized';
        }

        if (in_array($pretrendStatus, ['failed', 'non_parallel'], true)) {
            $reasons[] = 'pretrend_failed';
        } elseif ($pretrendStatus === 'unverified') {
            $reasons[] = 'pretrend_unverified';
        } elseif (!in_array($pretrendStatus, ['passed', 'parallel'], true)) {
            $reasons[] = 'pretrend_status_unrecognized';
        }

        if ($sampleSize < self::MINIMUM_SAMPLE_SIZE) {
            $reasons[] = 'sample_size_below_minimum';
        }

        if (in_array($sourceQuality, ['partial', 'stale'], true)) {
            $reasons[] = 'source_quality_insufficient';
        } elseif ($sourceQuality === 'unverified') {
            $reasons[] = 'source_quality_unverified';
        } elseif (!in_array($sourceQuality, ['verified', 'readback_verified'], true)) {
            $reasons[] = 'source_quality_unrecognized';
        }

        return $reasons;
    }

    private function evidenceStatement(string $verdict): string
    {
        return match ($verdict) {
            'supported' => '设计与证据门槛内同时估计出正增量间夜和正净增量利润，仅有限支持活动赚钱方向，不证明促销导致结果。',
            'contradicted' => '设计与证据门槛内估计的净增量利润为负，不能把活动称为赚钱；该估计仍不证明促销造成结果。',
            default => '设计、证据门槛或净利润方向证据不足，不能判断活动是否赚钱，也不能作因果判断。',
        };
    }
}
