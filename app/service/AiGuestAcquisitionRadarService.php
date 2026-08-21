<?php
declare(strict_types=1);

namespace app\service;

/**
 * Pure calculator for manually or otherwise authorized AI guest-acquisition observations.
 *
 * It does not collect data, invoke a model, persist anything, or create/publish content.
 */
final class AiGuestAcquisitionRadarService
{
    private const CONTRACT_VERSION = 'ai_guest_acquisition_radar.v2';
    private const MINIMUM_DISTINCT_REPEATS = 3;
    private const MAX_INPUT_JSON_BYTES = 262144;
    private const MAX_OBSERVATIONS = 100;
    private const MAX_REFERENCES = 50;
    private const MAX_TEXT_LENGTH = 1000;

    private const GATES = [
        'hotel_identified' => '酒店识别',
        'facts_correct' => '事实核查正确',
        'matched' => '意图匹配',
        'bookable_handoff' => '可预订承接',
    ];

    private const TRUSTED_SOURCE_QUALITIES = [
        'authorized_observation',
        'direct_verified',
        'guest_journey_verified',
        'live_verified',
        'manual_verified',
        'readback_verified',
        'verified',
        'verified_live',
    ];

    /**
     * @param array<int, mixed>|array{observations?: array<int, mixed>} $input
     */
    public function evaluate(array $input): array
    {
        $this->assertInputBudget($input);
        $businessDate = $this->textValue($input['business_date'] ?? null);
        $missingEvidence = [];
        if (!$this->validBusinessDate($businessDate)) {
            $missingEvidence[] = $this->evidenceIssue(null, '', 'business_date', 'business_date_invalid');
        }

        [$observations, $observationIssues] = $this->observationsFromInput($input);
        $missingEvidence = array_merge($missingEvidence, $observationIssues);
        $eligible = [];

        foreach ($observations as $index => $observation) {
            [$normalized, $issues] = $this->normalizeObservation(
                $observation,
                $index + 1,
                $this->validBusinessDate($businessDate) ? $businessDate : null
            );
            $missingEvidence = array_merge($missingEvidence, $issues);
            if ($normalized !== null && $issues === []) {
                $eligible[] = $normalized;
            }
        }

        if ($observations === [] && $missingEvidence === []) {
            $missingEvidence[] = $this->evidenceIssue(null, '', 'observations', 'missing_observations');
        }

        $gatePassRates = $this->gatePassRates($eligible);
        $repeatability = $this->repeatability($eligible);
        $status = 'measured';
        $statusReason = 'all_observations_are_traceable_and_repeatability_threshold_is_met';

        if ($missingEvidence !== []) {
            $status = 'blocked_by_missing_evidence';
            $statusReason = 'one_or_more_observations_lack_required_traceable_evidence';
        } elseif ($repeatability['status'] !== 'sufficient') {
            $status = 'insufficient_repeatability';
            $statusReason = 'one_or_more_groups_lack_three_distinct_repeat_numbers_timestamps_and_evidence_refs';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'status_reason' => $statusReason,
            'summary' => [
                'received_observation_count' => count($observations),
                'eligible_observation_count' => count($eligible),
                'blocked_observation_count' => count($observations) - count($eligible),
                'intent_count' => count(array_unique(array_column($eligible, 'intent'))),
            ],
            'gate_pass_rates' => $gatePassRates,
            'failure_points_by_intent' => $this->failurePointsByIntent($eligible),
            'repeatability' => $repeatability,
            'repairable_fact_gaps' => $this->repairableFactGaps($eligible),
            'missing_evidence' => $missingEvidence,
            'evidence_boundary' => [
                'input_scope' => 'provided_manual_or_authorized_intent_observations_only',
                'calculation_only' => true,
                'network_collection_performed' => false,
                'model_invocation_performed' => false,
                'promotional_content_generated' => false,
                'promotional_content_published' => false,
                'market_fact_claimed' => false,
                'generalization_allowed' => false,
                'single_model_response_is_market_fact' => false,
                'minimum_distinct_repeats' => self::MINIMUM_DISTINCT_REPEATS,
                'repeat_distinctness_fields' => ['repeat_no', 'observed_at', 'evidence_ref'],
                'limitations' => [
                    '通过率只描述输入观测，不代表市场份额、真实客源规模或预订增量。',
                    '服务只检查证据引用和来源质量声明是否齐全，不读取或认证引用材料本身。',
                    '单一模型的一次回答不能形成 measured，也不能作为市场事实。',
                ],
            ],
        ];
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function observationsFromInput(array $input): array
    {
        if (array_key_exists('observations', $input)) {
            if (!is_array($input['observations'])) {
                return [[], [$this->evidenceIssue(null, '', 'observations', 'invalid_observations')]];
            }

            return [array_values($input['observations']), []];
        }

        if (array_is_list($input)) {
            return [$input, []];
        }

        if (array_key_exists('intent', $input)) {
            return [[$input], []];
        }

        return [[], [$this->evidenceIssue(null, '', 'observations', 'missing_observations')]];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: array<int, array<string, mixed>>}
     */
    private function normalizeObservation(
        mixed $observation,
        int $observationNo,
        ?string $businessDate
    ): array
    {
        if (!is_array($observation)) {
            return [null, [$this->evidenceIssue(
                $observationNo,
                '',
                'observation',
                'observation_must_be_array'
            )]];
        }

        $intent = $this->textValue($observation['intent'] ?? null);
        $model = $this->textValue($observation['model'] ?? null);
        $region = $this->textValue($observation['region'] ?? null);
        $observedAt = $this->textValue($observation['observed_at'] ?? null);
        $sourceQuality = $this->textValue($observation['source_quality'] ?? null);
        $evidenceRef = $this->textValue($observation['evidence_ref'] ?? null);
        $canonicalObservedAt = null;
        $issues = [];

        foreach ([
            'intent' => $intent,
            'model' => $model,
            'region' => $region,
            'observed_at' => $observedAt,
            'source_quality' => $sourceQuality,
            'evidence_ref' => $evidenceRef,
        ] as $field => $value) {
            if ($value === '') {
                $issues[] = $this->evidenceIssue(
                    $observationNo,
                    $intent,
                    $field,
                    'missing_' . $field
                );
            }
        }

        if ($observedAt !== '') {
            $observedDateTime = $this->parseObservedAt($observedAt);
            if ($observedDateTime === null) {
                $issues[] = $this->evidenceIssue(
                    $observationNo,
                    $intent,
                    'observed_at',
                    'invalid_observed_at'
                );
            } else {
                $shanghaiObservedAt = $observedDateTime->setTimezone(new \DateTimeZone('Asia/Shanghai'));
                $canonicalObservedAt = $shanghaiObservedAt->format('Y-m-d\TH:i:s.uP');
            }
            if ($canonicalObservedAt !== null
                && $businessDate !== null
                && substr($canonicalObservedAt, 0, 10) !== $businessDate
            ) {
                $issues[] = $this->evidenceIssue(
                    $observationNo,
                    $intent,
                    'observed_at',
                    'observed_at_business_date_mismatch'
                );
            }
        }

        $repeatNo = $this->positiveInteger($observation['repeat_no'] ?? null);
        if ($repeatNo === null) {
            $issues[] = $this->evidenceIssue(
                $observationNo,
                $intent,
                'repeat_no',
                array_key_exists('repeat_no', $observation) ? 'invalid_repeat_no' : 'missing_repeat_no'
            );
        }

        $booleans = [];
        foreach (['hotel_identified', 'facts_checked', 'facts_correct', 'matched', 'bookable_handoff'] as $field) {
            [$value, $valid] = $this->booleanValue($observation, $field);
            if (!$valid) {
                $issues[] = $this->evidenceIssue(
                    $observationNo,
                    $intent,
                    $field,
                    array_key_exists($field, $observation) ? 'invalid_' . $field : 'missing_' . $field
                );
            }
            $booleans[$field] = $value ?? false;
        }

        if ($sourceQuality !== '' && !$this->isTrustedSourceQuality($sourceQuality)) {
            $issues[] = $this->evidenceIssue(
                $observationNo,
                $intent,
                'source_quality',
                'untrusted_source_quality'
            );
        }

        if ($issues !== []) {
            return [null, $issues];
        }

        return [[
            'intent' => $intent,
            'model' => $model,
            'region' => $region,
            'observed_at' => $canonicalObservedAt ?? $observedAt,
            'repeat_no' => $repeatNo,
            'hotel_identified' => $booleans['hotel_identified'],
            'facts_checked' => $booleans['facts_checked'],
            'facts_correct' => $booleans['facts_correct'],
            'matched' => $booleans['matched'],
            'bookable_handoff' => $booleans['bookable_handoff'],
            'source_quality' => $sourceQuality,
            'evidence_ref' => $evidenceRef,
        ], []];
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @return array<string, array<string, mixed>>
     */
    private function gatePassRates(array $observations): array
    {
        $rates = [];
        foreach (self::GATES as $gate => $label) {
            $rates[$gate] = [
                'label' => $label,
                'eligible_count' => 0,
                'passed_count' => 0,
                'not_evaluated_count' => 0,
                'pass_rate_percent' => null,
            ];
        }

        foreach ($observations as $observation) {
            foreach ($this->gateOutcomes($observation) as $gate => $outcome) {
                if ($outcome === null) {
                    continue;
                }
                $rates[$gate]['eligible_count']++;
                if ($outcome) {
                    $rates[$gate]['passed_count']++;
                }
            }
        }

        foreach ($rates as $gate => $rate) {
            $eligibleCount = $rate['eligible_count'];
            $rates[$gate]['not_evaluated_count'] = count($observations) - $eligibleCount;
            $rates[$gate]['pass_rate_percent'] = $eligibleCount > 0
                ? round($rate['passed_count'] / $eligibleCount * 100, 2)
                : null;
        }

        return $rates;
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @return array<int, array<string, mixed>>
     */
    private function failurePointsByIntent(array $observations): array
    {
        $groups = [];
        foreach ($observations as $observation) {
            $key = base64_encode((string)$observation['intent']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'intent' => $observation['intent'],
                    'observation_count' => 0,
                    'passed_all_gates_count' => 0,
                    '_failures' => [],
                ];
            }

            $groups[$key]['observation_count']++;
            $failure = $this->firstFailure($observation);
            if ($failure === null) {
                $groups[$key]['passed_all_gates_count']++;
                continue;
            }

            $code = $failure['code'];
            if (!isset($groups[$key]['_failures'][$code])) {
                $groups[$key]['_failures'][$code] = $failure + ['count' => 0];
            }
            $groups[$key]['_failures'][$code]['count']++;
        }

        $result = [];
        foreach ($groups as $group) {
            $failures = [];
            foreach ($group['_failures'] as $failure) {
                $failure['rate_percent'] = round(
                    $failure['count'] / max(1, $group['observation_count']) * 100,
                    2
                );
                $failures[] = $failure;
            }
            unset($group['_failures']);
            $group['failure_points'] = $failures;
            $result[] = $group;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @return array<string, mixed>
     */
    private function repeatability(array $observations): array
    {
        $groups = [];
        foreach ($observations as $observation) {
            $key = json_encode([
                $observation['intent'],
                $observation['model'],
                $observation['region'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key = $key === false ? sha1(serialize($observation)) : $key;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'intent' => $observation['intent'],
                    'model' => $observation['model'],
                    'region' => $observation['region'],
                    '_repeat_nos' => [],
                    '_observed_ats' => [],
                    '_evidence_refs' => [],
                    '_outcomes' => [],
                ];
            }
            $groups[$key]['_repeat_nos'][(string)$observation['repeat_no']] = $observation['repeat_no'];
            $groups[$key]['_observed_ats'][$observation['observed_at']] = $observation['observed_at'];
            $groups[$key]['_evidence_refs'][$observation['evidence_ref']] = $observation['evidence_ref'];
            $groups[$key]['_outcomes'][] = $this->gateOutcomes($observation);
        }

        $resultGroups = [];
        $sufficientCount = 0;
        foreach ($groups as $group) {
            $repeatNos = array_values($group['_repeat_nos']);
            sort($repeatNos, SORT_NUMERIC);
            $distinctRepeatCount = count($repeatNos);
            $distinctObservedAtCount = count($group['_observed_ats']);
            $distinctEvidenceRefCount = count($group['_evidence_refs']);
            $sufficient = $distinctRepeatCount >= self::MINIMUM_DISTINCT_REPEATS
                && $distinctObservedAtCount >= self::MINIMUM_DISTINCT_REPEATS
                && $distinctEvidenceRefCount >= self::MINIMUM_DISTINCT_REPEATS;
            if ($sufficient) {
                $sufficientCount++;
            }

            $signatures = array_map(
                fn(array $outcomes): string => $this->outcomeSignature($outcomes),
                $group['_outcomes']
            );
            $signatureCounts = array_count_values($signatures);
            $dominantSignatureCount = $signatureCounts === [] ? 0 : max($signatureCounts);
            $observationCount = count($group['_outcomes']);
            $consistencyRate = $sufficient && $observationCount > 0
                ? round($dominantSignatureCount / $observationCount * 100, 2)
                : null;

            $gateConsistency = [];
            foreach (array_keys(self::GATES) as $gate) {
                $values = array_map(
                    fn(array $outcomes): string => $this->outcomeValue($outcomes[$gate]),
                    $group['_outcomes']
                );
                $valueCounts = array_count_values($values);
                $dominantValueCount = $valueCounts === [] ? 0 : max($valueCounts);
                $gateConsistency[$gate] = [
                    'consistent' => $sufficient ? count($valueCounts) <= 1 : null,
                    'agreement_rate_percent' => $sufficient && $observationCount > 0
                        ? round($dominantValueCount / $observationCount * 100, 2)
                        : null,
                ];
            }

            unset($group['_repeat_nos'], $group['_observed_ats'], $group['_evidence_refs'], $group['_outcomes']);
            $group['observation_count'] = $observationCount;
            $group['distinct_repeat_count'] = $distinctRepeatCount;
            $group['distinct_observed_at_count'] = $distinctObservedAtCount;
            $group['distinct_evidence_ref_count'] = $distinctEvidenceRefCount;
            $group['repeat_nos'] = $repeatNos;
            $group['minimum_repeat_count_met'] = $sufficient;
            $group['consistency_status'] = !$sufficient
                ? 'not_measurable'
                : ($consistencyRate === 100.0 ? 'consistent' : 'variable');
            $group['outcome_consistency_rate_percent'] = $consistencyRate;
            $group['gate_consistency'] = $gateConsistency;
            $resultGroups[] = $group;
        }

        $groupCount = count($resultGroups);

        return [
            'status' => $groupCount > 0 && $sufficientCount === $groupCount ? 'sufficient' : 'insufficient',
            'grouping_fields' => ['intent', 'model', 'region'],
            'distinctness_fields' => ['repeat_no', 'observed_at', 'evidence_ref'],
            'minimum_distinct_repeats' => self::MINIMUM_DISTINCT_REPEATS,
            'group_count' => $groupCount,
            'sufficient_group_count' => $sufficientCount,
            'insufficient_group_count' => $groupCount - $sufficientCount,
            'groups' => $resultGroups,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $observations
     * @return array<int, array<string, mixed>>
     */
    private function repairableFactGaps(array $observations): array
    {
        $groups = [];
        foreach ($observations as $observation) {
            if (!$observation['hotel_identified']) {
                continue;
            }

            $code = null;
            if (!$observation['facts_checked']) {
                $code = 'facts_not_checked';
            } elseif (!$observation['facts_correct']) {
                $code = 'facts_incorrect';
            }
            if ($code === null) {
                continue;
            }

            $key = json_encode([
                $observation['intent'],
                $observation['model'],
                $observation['region'],
                $code,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $key = $key === false ? sha1(serialize($observation) . $code) : $key;
            if (!isset($groups[$key])) {
                $definition = $this->factGapDefinition($code);
                $groups[$key] = [
                    'intent' => $observation['intent'],
                    'model' => $observation['model'],
                    'region' => $observation['region'],
                    'code' => $code,
                    'label' => $definition['label'],
                    'repair_action' => $definition['repair_action'],
                    '_observation_count' => 0,
                    '_repeat_nos' => [],
                    '_evidence_refs' => [],
                ];
            }
            $groups[$key]['_observation_count']++;
            $groups[$key]['_repeat_nos'][(string)$observation['repeat_no']] = $observation['repeat_no'];
            $groups[$key]['_evidence_refs'][$observation['evidence_ref']] = $observation['evidence_ref'];
        }

        $result = [];
        foreach ($groups as $group) {
            $repeatNos = array_values($group['_repeat_nos']);
            sort($repeatNos, SORT_NUMERIC);
            $group['affected_repeat_nos'] = $repeatNos;
            $group['observation_count'] = $group['_observation_count'];
            $group['evidence_refs'] = array_values($group['_evidence_refs']);
            unset($group['_observation_count'], $group['_repeat_nos'], $group['_evidence_refs']);
            $result[] = $group;
        }

        return $result;
    }

    /**
     * Each downstream gate is evaluated only after all upstream gates pass.
     *
     * @return array<string, ?bool>
     */
    private function gateOutcomes(array $observation): array
    {
        $hotelIdentified = $observation['hotel_identified'] === true;
        $factsCorrect = $hotelIdentified
            ? $observation['facts_checked'] === true && $observation['facts_correct'] === true
            : null;
        $matched = $factsCorrect === true ? $observation['matched'] === true : null;
        $bookableHandoff = $matched === true ? $observation['bookable_handoff'] === true : null;

        return [
            'hotel_identified' => $hotelIdentified,
            'facts_correct' => $factsCorrect,
            'matched' => $matched,
            'bookable_handoff' => $bookableHandoff,
        ];
    }

    /**
     * @return ?array{code: string, gate: string, label: string}
     */
    private function firstFailure(array $observation): ?array
    {
        if (!$observation['hotel_identified']) {
            return ['code' => 'hotel_not_identified', 'gate' => 'hotel_identified', 'label' => '酒店未被识别'];
        }
        if (!$observation['facts_checked']) {
            return ['code' => 'facts_not_checked', 'gate' => 'facts_correct', 'label' => '事实尚未核查'];
        }
        if (!$observation['facts_correct']) {
            return ['code' => 'facts_incorrect', 'gate' => 'facts_correct', 'label' => '酒店事实错误'];
        }
        if (!$observation['matched']) {
            return ['code' => 'intent_not_matched', 'gate' => 'matched', 'label' => '回答未匹配意图'];
        }
        if (!$observation['bookable_handoff']) {
            return ['code' => 'bookable_handoff_missing', 'gate' => 'bookable_handoff', 'label' => '缺少可预订承接'];
        }

        return null;
    }

    /**
     * @return array{label: string, repair_action: string}
     */
    private function factGapDefinition(string $code): array
    {
        if ($code === 'facts_not_checked') {
            return [
                'label' => '事实尚未核查',
                'repair_action' => '依据授权证据完成人工事实核查后重新观测',
            ];
        }

        return [
            'label' => '酒店事实错误',
            'repair_action' => '修正酒店名称、位置、房型、价格或预订条件等错误事实后重新观测',
        ];
    }

    private function outcomeSignature(array $outcomes): string
    {
        return implode('|', array_map(fn(?bool $value): string => $this->outcomeValue($value), $outcomes));
    }

    private function outcomeValue(?bool $value): string
    {
        return $value === null ? 'not_evaluated' : ($value ? 'pass' : 'fail');
    }

    /**
     * @return array{0: ?bool, 1: bool}
     */
    private function booleanValue(array $observation, string $field): array
    {
        if (!array_key_exists($field, $observation)) {
            return [null, false];
        }

        $value = $observation[$field];
        if (is_bool($value)) {
            return [$value, true];
        }
        if ($value === 1 || $value === '1') {
            return [true, true];
        }
        if ($value === 0 || $value === '0') {
            return [false, true];
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === 'yes') {
                return [true, true];
            }
            if ($normalized === 'false' || $normalized === 'no') {
                return [false, true];
            }
        }

        return [null, false];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value)) === 1) {
            return (int)trim($value);
        }

        return null;
    }

    private function textValue(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        return trim((string)$value);
    }

    private function isTrustedSourceQuality(string $sourceQuality): bool
    {
        $normalized = strtolower(trim($sourceQuality));
        return in_array($normalized, self::TRUSTED_SOURCE_QUALITIES, true);
    }

    private function validBusinessDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false
                || ((int)($errors['warning_count'] ?? 0) === 0
                    && (int)($errors['error_count'] ?? 0) === 0))
            && $date->format('Y-m-d') === $value;
    }

    private function parseObservedAt(string $value): ?\DateTimeImmutable
    {
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D',
            $value
        ) !== 1) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false
                && ((int)($errors['warning_count'] ?? 0) > 0
                    || (int)($errors['error_count'] ?? 0) > 0)
            ) {
                return null;
            }
            return $date;
        } catch (\Throwable) {
            return null;
        }
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
            throw new \InvalidArgumentException('经营机会输入必须是可编码的JSON结构', 0, $error);
        }
        if (strlen((string)$encoded) > self::MAX_INPUT_JSON_BYTES) {
            throw new \InvalidArgumentException('经营机会输入不能超过256KB');
        }
        $this->assertNodeBudget($input);
    }

    private function assertNodeBudget(mixed $value, string $field = ''): void
    {
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length > self::MAX_TEXT_LENGTH) {
                throw new \InvalidArgumentException('经营机会单条文本不能超过1000字符');
            }
            return;
        }
        if (!is_array($value)) return;
        if (($field === 'observations' || ($field === '' && array_is_list($value)))
            && count($value) > self::MAX_OBSERVATIONS
        ) {
            throw new \InvalidArgumentException('经营机会观察记录不能超过100条');
        }
        if (preg_match('/(?:^|_)(?:refs|references)$/D', $field) === 1
            && count($value) > self::MAX_REFERENCES
        ) {
            throw new \InvalidArgumentException('经营机会来源引用不能超过50条');
        }
        foreach ($value as $key => $item) {
            $this->assertNodeBudget($item, is_string($key) ? strtolower($key) : '');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenceIssue(?int $observationNo, string $intent, string $field, string $code): array
    {
        return [
            'observation_no' => $observationNo,
            'intent' => $intent,
            'field' => $field,
            'code' => $code,
        ];
    }
}
