<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Select exactly one auditable daily operating item.
 *
 * Candidates are prepared server-side from strict facts, saved questions, or
 * explicit data gaps. Ranking only allocates attention; it never authorizes an
 * OTA/PMS write, an external message, or an execution claim.
 */
final class DailyOneThingService
{
    public const CONTRACT_VERSION = 'daily_one_thing.v2';
    public const EXPERIENCE_VERSION = 'daily_one_thing.explainable.v3';

    /** @var list<string> */
    private const SOURCE_TYPES = ['strict_fact_signal', 'saved_question', 'explicit_data_gap'];

    /** @var list<string> */
    private const RANKING_DIMENSIONS = [
        'impact',
        'urgency',
        'evidence_strength',
        'execution_cost',
    ];

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function select(array $candidates, string $businessDate): array
    {
        $prepared = $this->prepare($candidates, $businessDate);
        $businessDate = (string)$prepared['business_date'];
        $eligible = (array)$prepared['eligible'];
        $rejectedCount = (int)$prepared['rejected_candidate_count'];
        $sourceCounts = (array)$prepared['source_counts'];

        usort($eligible, static function (array $left, array $right): int {
            foreach (['impact', 'urgency', 'evidence_strength'] as $dimension) {
                $order = (int)$right['ranking'][$dimension] <=> (int)$left['ranking'][$dimension];
                if ($order !== 0) {
                    return $order;
                }
            }
            $costOrder = (int)$left['ranking']['execution_cost']
                <=> (int)$right['ranking']['execution_cost'];
            if ($costOrder !== 0) {
                return $costOrder;
            }
            return strcmp((string)$left['candidate_key'], (string)$right['candidate_key']);
        });

        $selected = $eligible[0] ?? null;
        $baseTieGroupSize = $selected === null
            ? 0
            : count(array_filter(
                $eligible,
                static fn(array $candidate): bool => self::sameBaseRank(
                    $candidate,
                    $selected
                )
            ));
        if ($selected !== null) {
            $selected = $this->withRecommendationExplanation(
                $selected,
                $baseTieGroupSize
            );
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'experience_version' => self::EXPERIENCE_VERSION,
            'business_date' => $businessDate,
            'status' => $selected === null ? 'no_eligible_item' : 'draft',
            'headline' => $selected === null
                ? '当前没有通过来源边界的每日事项'
                : (string)$selected['problem'],
            'selected' => $selected,
            'candidate_count' => count($eligible),
            'rejected_candidate_count' => $rejectedCount,
            'source_counts' => $sourceCounts,
            'selection_policy' => [
                'dimensions' => self::RANKING_DIMENSIONS,
                'order' => 'impact_desc_then_urgency_desc_then_evidence_strength_desc_then_execution_cost_asc_then_candidate_key',
                'base_tie_group_size' => $baseTieGroupSize,
                'personalization_position' => 'after_exact_base_rank_tie_before_candidate_key',
                'returns_exactly_one_action' => $selected !== null,
                'full_candidate_list_exposed' => false,
            ],
            'selection_boundary' => '候选只来自严格事实、已保存问题或明确数据缺口；排序只分配注意力，不是收入、概率或因果指标。',
            'can_execute' => false,
            'requires_human_approval' => true,
            'external_write_performed' => false,
        ];
    }

    /**
     * Internal preparation contract for server-owned rankers. Controllers must
     * never expose the returned eligible list to clients.
     *
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    public function prepare(array $candidates, string $businessDate): array
    {
        $businessDate = $this->validDate($businessDate);
        $eligible = [];
        $rejectedCount = 0;
        $sourceCounts = array_fill_keys(self::SOURCE_TYPES, 0);

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                $rejectedCount++;
                continue;
            }
            try {
                $normalized = $this->normalizeCandidate($candidate, $businessDate);
            } catch (InvalidArgumentException) {
                $rejectedCount++;
                continue;
            }
            $sourceCounts[(string)$normalized['source_type']]++;
            $eligible[] = $normalized;
        }
        return [
            'contract_version' => 'daily_one_thing_prepared.v1',
            'business_date' => $businessDate,
            'eligible' => $eligible,
            'rejected_candidate_count' => $rejectedCount,
            'source_counts' => $sourceCounts,
            'server_internal_only' => true,
        ];
    }

    /** @return array{impact:int,urgency:int,evidence_strength:int,execution_cost:int} */
    public static function baseRankKey(array $candidate): array
    {
        $ranking = is_array($candidate['ranking'] ?? null)
            ? $candidate['ranking']
            : [];
        return [
            'impact' => (int)($ranking['impact'] ?? -1),
            'urgency' => (int)($ranking['urgency'] ?? -1),
            'evidence_strength' => (int)($ranking['evidence_strength'] ?? -1),
            'execution_cost' => (int)($ranking['execution_cost'] ?? 101),
        ];
    }

    public static function sameBaseRank(array $left, array $right): bool
    {
        return self::baseRankKey($left) === self::baseRankKey($right);
    }

    /** @return array<string,mixed> */
    public function explainPreparedCandidate(array $candidate, int $baseTieGroupSize): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', (string)($candidate['content_digest'] ?? '')) !== 1
            || !hash_equals((string)$candidate['content_digest'], self::digest($candidate))
        ) {
            throw new InvalidArgumentException('每日事项内部候选摘要无效');
        }
        return $this->withRecommendationExplanation(
            $candidate,
            max(1, $baseTieGroupSize)
        );
    }

    /** @return array<string,mixed> */
    private function withRecommendationExplanation(
        array $selected,
        int $baseTieGroupSize
    ): array {
        $factBasis = array_values(array_filter(
            is_array($selected['fact_basis'] ?? null) ? $selected['fact_basis'] : [],
            'is_array'
        ));
        $ranking = is_array($selected['ranking'] ?? null) ? $selected['ranking'] : [];
        $reasons = is_array($ranking['reasons'] ?? null) ? $ranking['reasons'] : [];
        $sourceRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge(
                (array)($selected['source']['fact_refs'] ?? []),
                array_map(
                    static fn(array $row): string => (string)($row['evidence_ref'] ?? ''),
                    $factBasis
                )
            )
        ))));
        $sourceType = (string)($selected['source_type'] ?? '');
        $sourceLabel = match ($sourceType) {
            'explicit_data_gap' => '明确数据缺口',
            'saved_question' => '已保存经营问题',
            default => '严格事实信号',
        };
        $selected['recommendation_explanation'] = [
            'contract_version' => 'daily_one_thing_explanation.v1',
            'why_now' => [
                'summary' => (string)($factBasis[0]['statement'] ?? (
                    $sourceLabel . '需要在当前营业日优先处理。'
                )),
                'reason_code' => $sourceType === 'explicit_data_gap'
                    ? 'current_business_date_explicit_data_gap'
                    : ($sourceType === 'saved_question'
                        ? 'current_business_date_saved_question'
                        : 'current_business_date_strict_fact_signal'),
                'source_refs' => $sourceRefs,
            ],
            'why_recommended' => [
                'summary' => $baseTieGroupSize > 1
                    ? '该事项位于最高四维业务并列组；公共基础结果使用稳定候选键确定唯一事项。'
                    : '该事项在影响、紧迫性、证据强度和执行成本四维基础排序中最高。',
                'reason_code' => $baseTieGroupSize > 1
                    ? 'highest_base_rank_stable_tie_break'
                    : 'highest_base_business_rank',
                'base_rank' => self::baseRankKey($selected),
                'base_tie_group_size' => max(1, $baseTieGroupSize),
                'dimension_reasons' => [
                    'impact' => (string)($reasons['impact'] ?? '按业务影响范围排序'),
                    'urgency' => (string)($reasons['urgency'] ?? '按当前营业日紧迫程度排序'),
                    'evidence_strength' => (string)($reasons['evidence_strength'] ?? '按来源证据强度排序'),
                    'execution_cost' => (string)($reasons['execution_cost'] ?? '按人工执行成本排序'),
                ],
                'source_refs' => $sourceRefs,
            ],
            'personalization' => [
                'status' => 'not_applied',
                'reason_code' => 'hotel_shared_base_selection',
                'summary' => '酒店共享正式事项只使用公共事实与基础业务排序；个人偏好不会被静默写入共享任务。',
                'preference_refs' => [],
                'feedback_refs' => [],
                'effect_scope' => 'none',
                'selection_changed' => false,
                'facts_changed' => false,
                'eligibility_changed' => false,
                'permissions_changed' => false,
                'approval_changed' => false,
                'external_write_authorized' => false,
            ],
        ];
        $selected['content_digest'] = self::digest($selected);
        return $selected;
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function normalizeCandidate(array $candidate, string $businessDate): array
    {
        $sourceType = strtolower(trim((string)($candidate['source_type'] ?? '')));
        $candidateKey = trim((string)($candidate['candidate_key'] ?? ''));
        if (!in_array($sourceType, self::SOURCE_TYPES, true)
            || preg_match('/^[a-z0-9][a-z0-9:_-]{5,159}$/D', $candidateKey) !== 1
        ) {
            throw new InvalidArgumentException('每日事项来源或稳定候选键无效');
        }

        $scope = is_array($candidate['scope'] ?? null) ? $candidate['scope'] : [];
        $scopeDate = substr(trim((string)($scope['business_date'] ?? '')), 0, 10);
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $metricScope = strtolower(trim((string)($scope['metric_scope'] ?? '')));
        if ((int)($scope['tenant_id'] ?? 0) <= 0
            || (int)($scope['hotel_id'] ?? 0) <= 0
            || $scopeDate !== $businessDate
            || !in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)
            || !in_array($metricScope, ['ota_channel', 'ota_channel_data_quality'], true)
        ) {
            throw new InvalidArgumentException('每日事项酒店、平台、日期或指标范围无效');
        }

        $problem = $this->requiredText($candidate['problem'] ?? '', '问题', 4, 500);
        $factBasis = array_values(array_filter(
            (array)($candidate['fact_basis'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && trim((string)($item['statement'] ?? '')) !== ''
                && trim((string)($item['evidence_ref'] ?? '')) !== ''
        ));
        if ($factBasis === []) {
            throw new InvalidArgumentException('每日事项必须保留事实或缺口依据');
        }

        $action = is_array($candidate['recommended_action'] ?? null)
            ? $candidate['recommended_action']
            : [];
        $steps = array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            (array)($action['steps'] ?? [])
        )));
        if ($steps === []) {
            throw new InvalidArgumentException('每日事项必须包含可执行步骤');
        }

        $metric = is_array($candidate['expected_observation_metric'] ?? null)
            ? $candidate['expected_observation_metric']
            : [];
        $metricKey = strtolower(trim((string)($metric['key'] ?? '')));
        $metricUnit = trim((string)($metric['unit'] ?? ''));
        if ($metricKey === '' || $metricUnit === '' || !is_numeric($metric['baseline_value'] ?? null)) {
            throw new InvalidArgumentException('每日事项必须包含可比较的观察指标基线');
        }

        $risk = is_array($candidate['risk'] ?? null) ? $candidate['risk'] : [];
        $riskControls = array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            (array)($risk['controls'] ?? [])
        )));
        $stopConditions = array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            (array)($risk['stop_conditions'] ?? [])
        )));
        if ($riskControls === [] || $stopConditions === []) {
            throw new InvalidArgumentException('每日事项必须包含风险控制和停止条件');
        }

        $responsibility = is_array($candidate['responsibility'] ?? null)
            ? $candidate['responsibility']
            : [];
        $ownerId = (int)($responsibility['owner_id'] ?? 0);
        $dueAt = $this->validDateTime((string)($responsibility['due_at'] ?? ''), '截止时间');
        $reviewAt = $this->validDateTime((string)($responsibility['review_at'] ?? ''), '复盘时间');
        if ($ownerId <= 0 || $reviewAt <= $dueAt) {
            throw new InvalidArgumentException('每日事项负责人或复盘时间无效');
        }

        $ranking = is_array($candidate['ranking'] ?? null) ? $candidate['ranking'] : [];
        foreach (self::RANKING_DIMENSIONS as $dimension) {
            if (!is_numeric($ranking[$dimension] ?? null)) {
                throw new InvalidArgumentException('每日事项排序维度不完整');
            }
            $ranking[$dimension] = max(0, min(100, (int)round((float)$ranking[$dimension])));
        }
        $ranking['reasons'] = is_array($ranking['reasons'] ?? null) ? $ranking['reasons'] : [];

        $source = is_array($candidate['source'] ?? null) ? $candidate['source'] : [];
        $sourceRecordId = (int)($source['record_id'] ?? 0);
        $sourceSnapshotDigest = strtolower(trim((string)($source['snapshot_digest'] ?? '')));
        if ($sourceRecordId < 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSnapshotDigest) !== 1
        ) {
            throw new InvalidArgumentException('每日事项来源快照身份无效');
        }

        $boundaries = is_array($candidate['external_write_boundary'] ?? null)
            ? $candidate['external_write_boundary']
            : [];
        foreach ([
            'automatic_ctrip_write',
            'automatic_meituan_write',
            'automatic_pms_write',
            'automatic_wecom_message',
            'automatic_execution',
        ] as $field) {
            if (($boundaries[$field] ?? null) !== false) {
                throw new InvalidArgumentException('每日事项越过外部写入或自动执行边界');
            }
        }

        $normalized = [
            'candidate_key' => $candidateKey,
            'source_type' => $sourceType,
            'problem' => $problem,
            'fact_basis' => $factBasis,
            'recommended_action' => [
                'type' => $this->requiredToken($action['type'] ?? '', '动作类型', 80),
                'object' => $this->requiredText($action['object'] ?? '', '动作对象', 2, 180),
                'title' => $this->requiredText($action['title'] ?? '', '建议动作标题', 2, 180),
                'description' => $this->requiredText($action['description'] ?? '', '建议动作', 4, 1000),
                'steps' => $steps,
            ],
            'expected_observation_metric' => [
                'key' => $metricKey,
                'label' => $this->requiredText($metric['label'] ?? $metricKey, '观察指标', 1, 120),
                'unit' => $metricUnit,
                'baseline_value' => round((float)$metric['baseline_value'], 6),
                'aggregation' => in_array((string)($metric['aggregation'] ?? ''), ['sum', 'average', 'latest'], true)
                    ? (string)$metric['aggregation']
                    : 'latest',
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'target_value' => null,
                'expected_delta' => null,
            ],
            'scope' => [
                'tenant_id' => (int)$scope['tenant_id'],
                'hotel_id' => (int)$scope['hotel_id'],
                'platform' => $platform,
                'business_date' => $businessDate,
                'metric_scope' => $metricScope,
                'scope_note' => $this->requiredText($scope['scope_note'] ?? '', '适用范围', 4, 500),
            ],
            'risk' => [
                'level' => in_array((string)($risk['level'] ?? ''), ['low', 'medium', 'high'], true)
                    ? (string)$risk['level']
                    : 'medium',
                'summary' => $this->requiredText($risk['summary'] ?? '', '风险说明', 4, 500),
                'controls' => $riskControls,
                'stop_conditions' => $stopConditions,
            ],
            'responsibility' => [
                'owner_id' => $ownerId,
                'owner_label' => $this->requiredText($responsibility['owner_label'] ?? '当前确认人', '负责人', 2, 80),
                'due_at' => $dueAt->format('Y-m-d H:i:s'),
                'review_at' => $reviewAt->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Shanghai',
            ],
            'approval_status' => 'draft',
            'external_write_boundary' => $boundaries + [
                'human_confirmation_required' => true,
                'external_write_count_before_approval' => 0,
                'causality_claimed' => false,
            ],
            'ranking' => $ranking,
            'source' => [
                'record_id' => $sourceRecordId,
                'record_ref' => trim((string)($source['record_ref'] ?? '')),
                'snapshot_digest' => $sourceSnapshotDigest,
                'fact_refs' => array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($source['fact_refs'] ?? [])
                )))),
                'gap_codes' => array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($source['gap_codes'] ?? [])
                )))),
            ],
        ];
        $normalized['material_identity_digest'] = self::materialIdentityDigest($normalized);
        $normalized['content_digest'] = self::digest($normalized);
        return $normalized;
    }

    /** @param array<string,mixed> $value */
    public static function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', self::canonicalJson($value));
    }

    /**
     * Stable business identity. Volatile collection/runtime receipts remain in
     * the saved source snapshot, but do not manufacture a replacement action
     * while the hotel/platform/date/gap or saved-question fact identity is the
     * same.
     *
     * @param array<string,mixed> $candidate
     */
    public static function materialIdentityDigest(array $candidate): string
    {
        $sourceType = strtolower(trim((string)($candidate['source_type'] ?? '')));
        $factRefs = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($candidate['source']['fact_refs'] ?? [])
        ))));
        $gapCodes = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($candidate['source']['gap_codes'] ?? [])
        ))));
        sort($factRefs, SORT_STRING);
        sort($gapCodes, SORT_STRING);
        return hash('sha256', self::canonicalJson([
            'contract_version' => self::CONTRACT_VERSION,
            'candidate_key' => trim((string)($candidate['candidate_key'] ?? '')),
            'source_type' => $sourceType,
            'scope' => [
                'tenant_id' => (int)($candidate['scope']['tenant_id'] ?? 0),
                'hotel_id' => (int)($candidate['scope']['hotel_id'] ?? 0),
                'platform' => strtolower(trim((string)($candidate['scope']['platform'] ?? ''))),
                'business_date' => substr(trim((string)($candidate['scope']['business_date'] ?? '')), 0, 10),
                'metric_scope' => strtolower(trim((string)($candidate['scope']['metric_scope'] ?? ''))),
            ],
            'action' => [
                'type' => strtolower(trim((string)($candidate['recommended_action']['type'] ?? ''))),
                'object' => trim((string)($candidate['recommended_action']['object'] ?? '')),
            ],
            'metric' => [
                'key' => strtolower(trim((string)($candidate['expected_observation_metric']['key'] ?? ''))),
                'unit' => trim((string)($candidate['expected_observation_metric']['unit'] ?? '')),
                'baseline_value' => is_numeric($candidate['expected_observation_metric']['baseline_value'] ?? null)
                    ? round((float)$candidate['expected_observation_metric']['baseline_value'], 6)
                    : null,
            ],
            'source' => [
                'record_id' => max(0, (int)($candidate['source']['record_id'] ?? 0)),
                'snapshot_digest' => $sourceType === 'explicit_data_gap'
                    ? null
                    : strtolower(trim((string)($candidate['source']['snapshot_digest'] ?? ''))),
                'fact_refs' => $factRefs,
                'gap_codes' => $gapCodes,
            ],
        ]));
    }

    private static function canonicalJson(mixed $value): string
    {
        return (string)json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    private function requiredText(mixed $value, string $label, int $min, int $max): string
    {
        $text = trim((string)$value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException($label . '长度无效');
        }
        return $text;
    }

    private function requiredToken(mixed $value, string $label, int $max): string
    {
        $token = strtolower(trim((string)$value));
        if ($token === '' || strlen($token) > $max || preg_match('/^[a-z0-9][a-z0-9:_-]*$/D', $token) !== 1) {
            throw new InvalidArgumentException($label . '无效');
        }
        return $token;
    }

    private function validDate(string $date): string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('业务日期必须是有效的YYYY-MM-DD日期');
        }
        return $date;
    }

    private function validDateTime(string $value, string $label): DateTimeImmutable
    {
        $value = trim(str_replace('T', ' ', $value));
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new InvalidArgumentException($label . '必须是有效时间');
        }
        return $parsed;
    }
}
