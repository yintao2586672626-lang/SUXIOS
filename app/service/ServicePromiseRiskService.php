<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Evaluates one declared service-benefit promise without I/O or side effects.
 *
 * The result is limited to the supplied business date, benefit type and source
 * references. It does not generalize the result to the whole hotel and never
 * grants authority to change an OTA or execute an operating action.
 */
final class ServicePromiseRiskService
{
    public const CONTRACT_VERSION = 'service_promise_risk.v1';
    private const MAX_INPUT_JSON_BYTES = 262144;
    private const MAX_OBSERVATIONS = 100;
    private const MAX_REFERENCES = 50;
    private const MAX_TEXT_LENGTH = 1000;

    /** @var list<string> */
    private const CALCULABLE_SOURCE_QUALITIES = [
        'verified',
        'readback_verified',
        'available',
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $this->assertInputBudget($input);
        $missingFacts = [];

        $businessDate = $this->businessDate($input, $missingFacts);
        $benefitType = $this->requiredText($input, 'benefit_type', $missingFacts);
        $promisedQuantity = $this->quantity($input, 'promised_quantity', $missingFacts);
        $fulfillableCapacity = $this->quantity($input, 'fulfillable_capacity', $missingFacts);
        $breachCostPerUnit = $this->money($input, 'breach_cost_per_unit', $missingFacts);
        $sourceQuality = $this->sourceQuality($input, $missingFacts);
        $sourceReferences = $this->sourceReferences($input, $missingFacts);

        if ($sourceQuality !== null
            && !in_array($sourceQuality, self::CALCULABLE_SOURCE_QUALITIES, true)
        ) {
            $missingFacts[] = 'source_quality_not_verified';
        }

        $missingFacts = array_values(array_unique($missingFacts));
        $sourceBoundary = $this->sourceBoundary(
            $businessDate,
            $benefitType,
            $sourceQuality,
            $sourceReferences
        );

        if ($missingFacts !== []) {
            return $this->result(
                'blocked_by_missing_facts',
                null,
                null,
                null,
                $this->recommendationDraft('blocked_by_missing_facts', null, null, $missingFacts),
                $sourceBoundary,
                $missingFacts
            );
        }

        // The missing-fact branch above proves these required facts are present.
        if ($promisedQuantity === null
            || $fulfillableCapacity === null
            || $breachCostPerUnit === null
        ) {
            throw new InvalidArgumentException('required facts are unavailable');
        }

        if ($promisedQuantity > $fulfillableCapacity) {
            $shortageQuantity = $promisedQuantity - $fulfillableCapacity;
            $rawRiskAmount = $shortageQuantity * $breachCostPerUnit;
            if (!is_finite($rawRiskAmount)) {
                throw new InvalidArgumentException('risk_amount is outside the supported range');
            }

            return $this->result(
                'risk_detected',
                $shortageQuantity,
                null,
                round($rawRiskAmount, 2),
                $this->recommendationDraft('risk_detected', $shortageQuantity, null, []),
                $sourceBoundary,
                []
            );
        }

        $surplusQuantity = $fulfillableCapacity - $promisedQuantity;

        return $this->result(
            'capacity_available',
            null,
            $surplusQuantity,
            0.0,
            $this->recommendationDraft('capacity_available', null, $surplusQuantity, []),
            $sourceBoundary,
            []
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     */
    private function businessDate(array $input, array &$missingFacts): ?string
    {
        $value = $this->requiredText($input, 'business_date', $missingFacts);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('business_date must use a valid YYYY-MM-DD date');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     */
    private function requiredText(
        array $input,
        string $field,
        array &$missingFacts
    ): ?string {
        if (!array_key_exists($field, $input)
            || $input[$field] === null
            || (is_string($input[$field]) && trim($input[$field]) === '')
        ) {
            $missingFacts[] = $field;
            return null;
        }

        if (!is_string($input[$field])) {
            throw new InvalidArgumentException($field . ' must be a string');
        }

        return trim($input[$field]);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     */
    private function quantity(
        array $input,
        string $field,
        array &$missingFacts
    ): ?int {
        if (!array_key_exists($field, $input)
            || $input[$field] === null
            || $input[$field] === ''
        ) {
            $missingFacts[] = $field;
            return null;
        }

        $value = $input[$field];
        if (is_int($value)) {
            $quantity = $value;
        } elseif (is_float($value)
            && is_finite($value)
            && floor($value) === $value
            && $value <= PHP_INT_MAX
        ) {
            $quantity = (int)$value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', trim($value)) === 1) {
            $validated = filter_var(
                trim($value),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if ($validated === false) {
                throw new InvalidArgumentException($field . ' is outside the supported integer range');
            }
            $quantity = $validated;
        } else {
            throw new InvalidArgumentException($field . ' must be a non-negative integer');
        }

        if ($quantity < 0) {
            throw new InvalidArgumentException($field . ' must be a non-negative integer');
        }

        return $quantity;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     */
    private function money(
        array $input,
        string $field,
        array &$missingFacts
    ): ?float {
        if (!array_key_exists($field, $input)
            || $input[$field] === null
            || $input[$field] === ''
        ) {
            $missingFacts[] = $field;
            return null;
        }

        $value = $input[$field];
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || (is_string($value) && !is_numeric(trim($value)))
        ) {
            throw new InvalidArgumentException($field . ' must be a non-negative finite number');
        }

        $amount = (float)$value;
        if (!is_finite($amount) || $amount < 0.0) {
            throw new InvalidArgumentException($field . ' must be a non-negative finite number');
        }

        return $amount;
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     */
    private function sourceQuality(array $input, array &$missingFacts): ?string
    {
        $quality = $this->requiredText($input, 'source_quality', $missingFacts);
        return $quality === null ? null : strtolower($quality);
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $missingFacts
     * @return null|list<string>
     */
    private function sourceReferences(array $input, array &$missingFacts): ?array
    {
        if (!array_key_exists('source_references', $input)
            || $input['source_references'] === null
            || $input['source_references'] === []
        ) {
            $missingFacts[] = 'source_references';
            return null;
        }

        $references = $input['source_references'];
        if (!is_array($references) || !array_is_list($references)) {
            throw new InvalidArgumentException('source_references must be a non-empty list of strings');
        }

        $normalized = [];
        foreach ($references as $reference) {
            if (!is_string($reference) || trim($reference) === '') {
                throw new InvalidArgumentException('source_references must contain only non-empty strings');
            }
            $normalized[] = trim($reference);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param null|list<string> $sourceReferences
     * @return array<string,mixed>
     */
    private function sourceBoundary(
        ?string $businessDate,
        ?string $benefitType,
        ?string $sourceQuality,
        ?array $sourceReferences
    ): array {
        return [
            'fact_scope' => 'declared_service_promise_only',
            'business_date' => $businessDate,
            'benefit_type' => $benefitType,
            'source_quality' => $sourceQuality,
            'source_references' => $sourceReferences,
            'whole_hotel_fact' => false,
        ];
    }

    /**
     * @param list<string> $missingFacts
     * @return array<string,mixed>
     */
    private function recommendationDraft(
        string $status,
        ?int $shortageQuantity,
        ?int $surplusQuantity,
        array $missingFacts
    ): array {
        if ($status === 'risk_detected') {
            $summary = '当前输入范围内存在 ' . $shortageQuantity
                . ' 份权益履约缺口，建议人工复核容量并拟定补足或补偿方案。';
            $steps = [
                '人工复核承诺数量、真实可履约容量与来源引用。',
                '人工拟定补足、替代权益或补偿方案。',
                '如需修改OTA，另行发起明确审批并在执行后留存回执。',
            ];
        } elseif ($status === 'capacity_available') {
            $summary = '当前输入范围内尚有 ' . $surplusQuantity
                . ' 份可履约余量，建议保持只读观察并在事实变化后重新评估。';
            $steps = [
                '继续核对承诺量与可履约容量的同日变化。',
                '容量或承诺变化后重新运行只读评估。',
            ];
        } else {
            $summary = '缺少可信事实，暂不计算权益履约缺口、余量或风险金额。';
            $steps = [
                '补齐并验证以下事实后重新评估：' . implode(', ', $missingFacts) . '。',
            ];
        }

        return [
            'mode' => 'read_only',
            'summary' => $summary,
            'suggested_steps' => $steps,
            'execution_authorized' => false,
            'ota_write_allowed' => false,
            'external_action' => 'none',
        ];
    }

    /**
     * @param array<string,mixed> $recommendationDraft
     * @param array<string,mixed> $sourceBoundary
     * @param list<string> $missingFacts
     * @return array<string,mixed>
     */
    private function result(
        string $status,
        ?int $shortageQuantity,
        ?int $surplusQuantity,
        ?float $riskAmount,
        array $recommendationDraft,
        array $sourceBoundary,
        array $missingFacts
    ): array {
        return [
            'status' => $status,
            'shortage_quantity' => $shortageQuantity,
            'surplus_quantity' => $surplusQuantity,
            'risk_amount' => $riskAmount,
            'recommendation_draft' => $recommendationDraft,
            'source_boundary' => $sourceBoundary,
            'missing_facts' => $missingFacts,
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }

    /** @param array<int|string,mixed> $input */
    private function assertInputBudget(array $input): void
    {
        try {
            $encoded = json_encode(
                $input,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('经营机会输入必须是可编码的JSON结构', 0, $error);
        }
        if (strlen((string)$encoded) > self::MAX_INPUT_JSON_BYTES) {
            throw new InvalidArgumentException('经营机会输入不能超过256KB');
        }
        $this->assertNodeBudget($input);
    }

    private function assertNodeBudget(mixed $value, string $field = ''): void
    {
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length > self::MAX_TEXT_LENGTH) {
                throw new InvalidArgumentException('经营机会单条文本不能超过1000字符');
            }
            return;
        }
        if (!is_array($value)) return;
        if (in_array($field, ['observations', 'guest_observations'], true)
            && count($value) > self::MAX_OBSERVATIONS
        ) {
            throw new InvalidArgumentException('经营机会观察记录不能超过100条');
        }
        if (preg_match('/(?:^|_)(?:refs|references)$/D', $field) === 1
            && count($value) > self::MAX_REFERENCES
        ) {
            throw new InvalidArgumentException('经营机会来源引用不能超过50条');
        }
        foreach ($value as $key => $item) {
            $this->assertNodeBudget($item, is_string($key) ? strtolower($key) : '');
        }
    }
}
