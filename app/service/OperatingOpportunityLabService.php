<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class OperatingOpportunityLabService
{
    public const CONTRACT_VERSION = 'operating_opportunity_lab.v2';
    public const RUN_TABLE = 'operating_opportunity_runs';
    private const MAX_INPUT_JSON_BYTES = 262144;
    private const MAX_OBSERVATIONS = 100;
    private const MAX_REFERENCES = 50;
    private const MAX_TEXT_LENGTH = 1000;

    private const SOURCE_QUALITY_STATUSES = [
        'available',
        'authorized_observation',
        'direct_verified',
        'guest_journey_verified',
        'live_verified',
        'manual_unverified',
        'manual_verified',
        'partial',
        'readback_verified',
        'stale',
        'unverified',
        'verified',
        'verified_live',
    ];

    /** @var array<string,array<string,mixed>> */
    private const FEATURES = [
        'daily_one_thing' => [
            'label' => '今日一件事',
            'question' => '今天最该先处理什么？',
            'description' => '从本页已经保存并回读的结果中，只选一项最值得打断老板的事项。',
            'input_mode' => 'derived_from_saved_runs',
        ],
        'service_promise_risk' => [
            'label' => '权益履约预警',
            'question' => '明天哪些订单可能接不住？',
            'description' => '核对提前入住、延迟退房、早餐、升房等承诺是否超过真实履约容量。',
            'input_mode' => 'manual_or_verified_fact_packet',
        ],
        'promotion_incrementality' => [
            'label' => '促销真实增量',
            'question' => '这个活动到底赚没赚？',
            'description' => '用处理组和对照组区分平台归因订单与可识别的真实增量。',
            'input_mode' => 'controlled_comparison',
        ],
        'bookability_gap' => [
            'label' => '客人端真实可售',
            'question' => '明明有房，客人为什么订不到？',
            'description' => '对照PMS预期与搜索、详情、提交前的游客条件结果，定位最早断点。',
            'input_mode' => 'authorized_observation',
        ],
        'ai_guest_acquisition' => [
            'label' => 'AI客源检测',
            'question' => 'AI为什么不推荐我？',
            'description' => '汇总重复观测，检查识别、事实、匹配和可订交接四关。',
            'input_mode' => 'manual_or_authorized_observation',
        ],
    ];

    public function __construct(private ?DailyOneThingService $dailyOneThing = null)
    {
        $this->dailyOneThing ??= new DailyOneThingService();
    }

    /** @return array<int,array<string,mixed>> */
    public function catalog(): array
    {
        $items = [];
        foreach (self::FEATURES as $key => $definition) {
            $items[] = ['key' => $key] + $definition + [
                'contract_version' => self::CONTRACT_VERSION,
                'external_write_allowed' => false,
            ];
        }
        return $items;
    }

    public function hotelTenantId(int $hotelId): int
    {
        if ($hotelId <= 0) throw new InvalidArgumentException('请选择单个酒店');
        $hotel = Db::name('hotels')->where('id', $hotelId)->where('status', 1)->field('id,tenant_id')->find();
        if (!is_array($hotel)) throw new RuntimeException('酒店不存在或已停用');
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) throw new RuntimeException('酒店租户边界未就绪');
        return $tenantId;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function evaluateAndSave(int $tenantId, int $hotelId, int $actorUserId, array $input): array
    {
        $this->assertScope($tenantId, $hotelId, $actorUserId);
        $this->assertInputBudget($input);
        $this->assertSchemaReady();
        $featureKey = trim((string)($input['feature_key'] ?? ''));
        if ($featureKey === 'daily_one_thing') {
            throw new InvalidArgumentException('今日一件事必须从已保存结果生成');
        }
        if (!isset(self::FEATURES[$featureKey])) {
            throw new InvalidArgumentException('未知经营机会功能');
        }

        $businessDate = $this->validDate((string)($input['business_date'] ?? ''));
        $sourceQuality = $this->manualInputSourceQuality(
            $input['source_quality_status'] ?? $input['source_quality'] ?? 'unverified'
        );
        $sourceReference = $this->optionalText($input['source_reference'] ?? '', 1000);
        $idempotencyKey = $this->requiredText($input['idempotency_key'] ?? '', '幂等键', 8, 128);
        $payload = $input;
        unset($payload['feature_key'], $payload['idempotency_key']);
        $payload['business_date'] = $businessDate;
        $payload['source_quality'] = $sourceQuality;
        $payload['source_quality_status'] = $payload['source_quality'];
        if (!array_key_exists('source_references', $payload)) {
            $payload['source_references'] = $sourceReference !== null ? [$sourceReference] : [];
        }
        $this->assertObservationSourceQualityMatches($featureKey, $payload['source_quality'], $payload);

        $result = $this->evaluateFeature($featureKey, $payload);
        $result = $this->withManualEstimate($featureKey, $payload, $result);
        $result['feature_key'] = $featureKey;
        $result['feature_label'] = (string)self::FEATURES[$featureKey]['label'];
        $result['business_date'] = $businessDate;
        $result['source_quality_status'] = $payload['source_quality'];
        $result['source_reference'] = $sourceReference;
        $result['external_write_allowed'] = false;
        $result['requires_human_approval'] = true;

        return $this->saveRun(
            $tenantId,
            $hotelId,
            $actorUserId,
            $featureKey,
            $businessDate,
            $payload['source_quality'],
            $sourceReference,
            $idempotencyKey,
            $payload,
            $result
        );
    }

    /** @return array<string,mixed> */
    public function saveDailyPriority(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        string $businessDate,
        string $idempotencyKey
    ): array {
        $this->assertScope($tenantId, $hotelId, $actorUserId);
        $this->assertSchemaReady();
        $businessDate = $this->validDate($businessDate);
        $idempotencyKey = $this->requiredText($idempotencyKey, '幂等键', 8, 128);
        $latestRuns = $this->latestFeatureRuns($tenantId, $hotelId, $businessDate);
        $priority = $this->dailyOneThing->select($latestRuns, $businessDate);
        $input = [
            'business_date' => $businessDate,
            'source_run_ids' => array_values(array_filter(array_map(
                static fn(array $run): int => (int)($run['id'] ?? 0),
                $latestRuns
            ))),
            'selection_contract' => DailyOneThingService::CONTRACT_VERSION,
        ];
        $priority['feature_key'] = 'daily_one_thing';
        $priority['feature_label'] = (string)self::FEATURES['daily_one_thing']['label'];
        $priority['external_write_allowed'] = false;

        return $this->saveRun(
            $tenantId,
            $hotelId,
            $actorUserId,
            'daily_one_thing',
            $businessDate,
            'derived_from_saved_runs',
            null,
            $idempotencyKey,
            $input,
            $priority
        );
    }

    /** @return array<string,mixed> */
    public function overview(int $tenantId, int $hotelId, string $businessDate): array
    {
        $this->assertScope($tenantId, $hotelId, 1);
        $this->assertSchemaReady();
        $businessDate = $this->validDate($businessDate);
        $latestRuns = $this->latestFeatureRuns($tenantId, $hotelId, $businessDate);
        $priority = $this->dailyOneThing->select($latestRuns, $businessDate);
        $savedPriorityRun = $this->latestDailyPriorityRun($tenantId, $hotelId, $businessDate);
        $currentSourceRunIds = $this->sourceRunIds($latestRuns);
        $savedSourceRunIds = $savedPriorityRun === null
            ? []
            : array_values(array_map('intval', (array)($savedPriorityRun['input']['source_run_ids'] ?? [])));
        sort($savedSourceRunIds, SORT_NUMERIC);
        $savedPriorityIsCurrent = $savedPriorityRun !== null
            && $savedSourceRunIds === $currentSourceRunIds;
        $historyRows = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'catalog' => $this->catalog(),
            'today' => $savedPriorityIsCurrent ? $savedPriorityRun['result'] : $priority,
            'today_preview' => $priority,
            'today_saved_run' => $savedPriorityRun,
            'today_state' => $savedPriorityRun === null
                ? 'not_saved'
                : ($savedPriorityIsCurrent ? 'saved_current' : 'saved_stale'),
            'latest_runs' => array_values($latestRuns),
            'history' => array_map(fn(array $row): array => $this->publicRun($row), $historyRows),
            'scope_notice' => '所有结果只属于当前酒店、当前业务日期与所标注来源；人工输入不自动升级为已验证事实。',
        ];
    }

    /** @return array<string,mixed> */
    public function readRun(int $tenantId, int $hotelId, int $runId): array
    {
        $this->assertScope($tenantId, $hotelId, 1);
        if ($runId <= 0) throw new InvalidArgumentException('运行记录ID无效');
        $row = Db::name(self::RUN_TABLE)
            ->where('id', $runId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) throw new RuntimeException('经营机会运行记录不存在');
        return $this->publicRun($row);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function evaluateFeature(string $featureKey, array $payload): array
    {
        $result = match ($featureKey) {
            'service_promise_risk' => (new ServicePromiseRiskService())->evaluate($payload),
            'promotion_incrementality' => (new PromotionIncrementalityService())->evaluate($payload),
            'bookability_gap' => (new BookabilityGapService())->evaluate($payload),
            'ai_guest_acquisition' => (new AiGuestAcquisitionRadarService())->evaluate($payload),
            default => throw new InvalidArgumentException('未知经营机会功能'),
        };
        if (!is_array($result)) throw new RuntimeException('经营机会计算未返回有效结果');
        return $result;
    }

    /**
     * Manual observations are useful for an immediate, user-checkable estimate,
     * but they are not verified business facts. Keep the calculator's formal
     * result fail-closed and expose a separate numeric-only estimate layer. The
     * trusted marker below exists only inside the pure calculator invocation;
     * it is never persisted or returned as source evidence.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $formalResult
     * @return array<string,mixed>
     */
    private function withManualEstimate(
        string $featureKey,
        array $payload,
        array $formalResult
    ): array {
        $calculationInput = $payload;
        if ($featureKey === 'service_promise_risk') {
            $calculationInput['source_quality'] = 'available';
        } elseif (in_array($featureKey, ['bookability_gap', 'ai_guest_acquisition'], true)) {
            foreach (['observations', 'guest_observations'] as $field) {
                if (!is_array($calculationInput[$field] ?? null)) {
                    continue;
                }
                $calculationInput[$field] = array_map(
                    static function (mixed $observation): mixed {
                        if (!is_array($observation)) {
                            return $observation;
                        }
                        $observation['source_quality'] = 'manual_verified';
                        return $observation;
                    },
                    $calculationInput[$field]
                );
            }
        }

        $calculated = $featureKey === 'promotion_incrementality'
            ? $formalResult
            : $this->evaluateFeature($featureKey, $calculationInput);
        $metrics = $this->manualEstimateMetrics($featureKey, $payload, $calculated);
        $available = $this->manualEstimateAvailable($featureKey, $calculated, $metrics);

        return array_replace($formalResult, [
            'calculation_status' => $available
                ? 'provisional_manual_estimate'
                : 'blocked_by_missing_facts',
            'metric_provenance' => 'manual_estimate',
            'manual_estimate' => true,
            'provisional_metrics' => $metrics,
            'formal_conclusion' => null,
            'decision_eligible' => false,
            'can_execute' => false,
        ]);
    }

    /**
     * Return only user-checkable numbers. Never return the temporary calculator
     * input or its source-quality marker through the provisional layer.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $calculated
     * @return array<string,mixed>
     */
    private function manualEstimateMetrics(
        string $featureKey,
        array $payload,
        array $calculated
    ): array {
        if ($featureKey === 'service_promise_risk') {
            return $this->pickNumeric($calculated, [
                'shortage_quantity',
                'surplus_quantity',
                'risk_amount',
            ]);
        }
        if ($featureKey === 'promotion_incrementality') {
            return $this->pickNumeric($calculated, [
                'treated_change',
                'control_change',
                'treated_rate_before',
                'treated_rate_after',
                'control_rate_before',
                'control_rate_after',
                'treated_rate_change',
                'control_rate_change',
                'incremental_rate',
                'incremental_room_nights',
                'incremental_contribution',
                'discount_cost',
                'net_incremental_profit',
            ]);
        }
        if ($featureKey === 'bookability_gap') {
            $observations = is_array($payload['observations'] ?? null)
                ? $payload['observations']
                : (is_array($payload['guest_observations'] ?? null)
                    ? $payload['guest_observations']
                    : []);
            $metrics = [
                'observation_count' => count($observations),
                'affected_condition_count' => count((array)($calculated['affected_conditions'] ?? [])),
            ];
            if (is_numeric($payload['pms_expected_sellable'] ?? null)) {
                $metrics['pms_expected_sellable'] = (int)$payload['pms_expected_sellable'];
            }
            if (is_numeric($calculated['potential_loss'] ?? null)) {
                $metrics['potential_loss'] = (float)$calculated['potential_loss'];
            }
            return $metrics;
        }
        if ($featureKey === 'ai_guest_acquisition') {
            $summary = is_array($calculated['summary'] ?? null) ? $calculated['summary'] : [];
            $metrics = $this->pickNumeric($summary, [
                'received_observation_count',
                'eligible_observation_count',
                'blocked_observation_count',
                'intent_count',
            ]);
            $gateRates = [];
            foreach ((array)($calculated['gate_pass_rates'] ?? []) as $gate => $rate) {
                if (!is_array($rate)) {
                    continue;
                }
                $gateRates[(string)$gate] = $this->pickNumeric($rate, [
                    'eligible_count',
                    'passed_count',
                    'not_evaluated_count',
                    'pass_rate_percent',
                ]);
            }
            if ($gateRates !== []) {
                $metrics['gate_pass_rates'] = $gateRates;
            }
            return $metrics;
        }
        return [];
    }

    /** @param array<string,mixed> $source @param array<int,string> $keys @return array<string,int|float> */
    private function pickNumeric(array $source, array $keys): array
    {
        $metrics = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || !is_numeric($source[$key])) {
                continue;
            }
            $metrics[$key] = is_int($source[$key])
                ? $source[$key]
                : (float)$source[$key];
        }
        return $metrics;
    }

    /** @param array<string,mixed> $calculated @param array<string,mixed> $metrics */
    private function manualEstimateAvailable(
        string $featureKey,
        array $calculated,
        array $metrics
    ): bool
    {
        return match ($featureKey) {
            'service_promise_risk' => in_array(
                (string)($calculated['status'] ?? ''),
                ['risk_detected', 'capacity_available'],
                true
            ) && $metrics !== [],
            'promotion_incrementality' => is_numeric($calculated['incremental_rate'] ?? null)
                && is_numeric($calculated['incremental_room_nights'] ?? null),
            'bookability_gap' => ($calculated['blocked_by_missing_evidence'] ?? true) === false
                && (int)($metrics['observation_count'] ?? 0) > 0,
            'ai_guest_acquisition' => (int)($metrics['eligible_observation_count'] ?? 0) > 0,
            default => false,
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function latestFeatureRuns(int $tenantId, int $hotelId, string $businessDate): array
    {
        $rows = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->whereIn('feature_key', array_keys(array_filter(
                self::FEATURES,
                static fn(array $item, string $key): bool => $key !== 'daily_one_thing',
                ARRAY_FILTER_USE_BOTH
            )))
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $latest = [];
        foreach ($rows as $row) {
            $key = (string)($row['feature_key'] ?? '');
            if ($key === '' || isset($latest[$key])) continue;
            $latest[$key] = $this->publicRun($row);
        }
        return array_values($latest);
    }

    /** @return ?array<string,mixed> */
    private function latestDailyPriorityRun(int $tenantId, int $hotelId, string $businessDate): ?array
    {
        $row = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->where('feature_key', 'daily_one_thing')
            ->order('id', 'desc')
            ->find();
        return is_array($row) ? $this->publicRun($row) : null;
    }

    /** @param array<int,array<string,mixed>> $runs @return array<int,int> */
    private function sourceRunIds(array $runs): array
    {
        $ids = array_values(array_filter(array_map(
            static fn(array $run): int => (int)($run['id'] ?? 0),
            $runs
        ), static fn(int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $result @return array<string,mixed> */
    private function saveRun(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        string $featureKey,
        string $businessDate,
        string $sourceQuality,
        ?string $sourceReference,
        string $idempotencyKey,
        array $input,
        array $result
    ): array {
        $inputDigest = $this->digest($input);
        $resultDigest = $this->digest($result);
        $now = $this->now();

        $saved = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $saved = Db::transaction(function () use (
                    $tenantId, $hotelId, $actorUserId, $featureKey, $businessDate,
                    $sourceQuality, $sourceReference, $idempotencyKey, $input, $result,
                    $inputDigest, $resultDigest, $now
                ): array {
                    $existing = $this->findIdempotentRun($tenantId, $actorUserId, $idempotencyKey, true);
                    if (is_array($existing)) {
                        return $this->replayDescriptor(
                            $existing,
                            $hotelId,
                            $featureKey,
                            $businessDate,
                            $inputDigest,
                            $resultDigest
                        );
                    }
                    $id = (int)Db::name(self::RUN_TABLE)->insertGetId([
                        'tenant_id' => $tenantId,
                        'system_hotel_id' => $hotelId,
                        'feature_key' => $featureKey,
                        'business_date' => $businessDate,
                        'source_quality_status' => $sourceQuality,
                        'source_reference' => $sourceReference,
                        'input_json' => $this->encodeJson($input),
                        'result_json' => $this->encodeJson($result),
                        'input_digest' => $inputDigest,
                        'result_digest' => $resultDigest,
                        'idempotency_key' => $idempotencyKey,
                        'created_by' => $actorUserId,
                        'created_at' => $now,
                    ]);
                    if ($id <= 0) throw new RuntimeException('经营机会计算保存失败');
                    return ['id' => $id, 'replayed' => false];
                });
                break;
            } catch (\Throwable $error) {
                if ($this->isDuplicateKeyConflict($error)) {
                    $existing = $this->findIdempotentRun(
                        $tenantId,
                        $actorUserId,
                        $idempotencyKey,
                        false
                    );
                    if (is_array($existing)) {
                        $saved = $this->replayDescriptor(
                            $existing,
                            $hotelId,
                            $featureKey,
                            $businessDate,
                            $inputDigest,
                            $resultDigest
                        );
                        break;
                    }
                }
                if ($attempt >= 3 || !$this->isRetryableWriteConflict($error)) {
                    throw $error;
                }
                usleep(20000 * $attempt);
            }
        }
        if (!is_array($saved)) {
            throw new RuntimeException('经营机会计算保存失败');
        }

        $readback = $this->readRun($tenantId, $hotelId, (int)$saved['id']);
        $this->assertReadbackIntegrity(
            $readback,
            $tenantId,
            $hotelId,
            $featureKey,
            $businessDate,
            $sourceQuality,
            $sourceReference,
            $actorUserId,
            $inputDigest,
            $resultDigest
        );
        return [
            'run' => $readback,
            'replayed' => (bool)$saved['replayed'],
            'readback_verified' => true,
        ];
    }

    /** @return ?array<string,mixed> */
    private function findIdempotentRun(
        int $tenantId,
        int $actorUserId,
        string $idempotencyKey,
        bool $lock
    ): ?array {
        $query = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('created_by', $actorUserId)
            ->where('idempotency_key', $idempotencyKey);
        if ($lock) $query->lock(true);
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    /** @return array{id:int,replayed:bool} */
    private function replayDescriptor(
        array $existing,
        int $hotelId,
        string $featureKey,
        string $businessDate,
        string $inputDigest,
        string $resultDigest
    ): array {
        if ((int)($existing['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($existing['feature_key'] ?? '') !== $featureKey
            || (string)($existing['business_date'] ?? '') !== $businessDate
            || !hash_equals((string)($existing['input_digest'] ?? ''), $inputDigest)
            || !hash_equals((string)($existing['result_digest'] ?? ''), $resultDigest)
        ) {
            throw new InvalidArgumentException('幂等键已用于不同的经营机会计算');
        }
        return ['id' => (int)$existing['id'], 'replayed' => true];
    }

    private function isDuplicateKeyConflict(\Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());
            if ((string)$current->getCode() === '23000'
                || str_contains($message, 'duplicate entry')
                || str_contains($message, '1062')
            ) return true;
        }
        return false;
    }

    private function isRetryableWriteConflict(\Throwable $error): bool
    {
        if ($this->isDuplicateKeyConflict($error)) {
            return true;
        }
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $code = (string)$current->getCode();
            $message = strtolower($current->getMessage());
            if ($code === '40001'
                || $code === '1213'
                || $code === '1205'
                || str_contains($message, 'deadlock found')
                || str_contains($message, 'lock wait timeout')
                || str_contains($message, 'serialization failure')
            ) return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function publicRun(array $row): array
    {
        $run = [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'feature_key' => (string)$row['feature_key'],
            'feature_label' => (string)(self::FEATURES[(string)$row['feature_key']]['label'] ?? $row['feature_key']),
            'business_date' => (string)$row['business_date'],
            'source_quality_status' => (string)$row['source_quality_status'],
            'source_reference' => isset($row['source_reference']) && (string)$row['source_reference'] !== ''
                ? (string)$row['source_reference']
                : null,
            'input' => $this->decodeJson((string)$row['input_json']),
            'result' => $this->decodeJson((string)$row['result_json']),
            'input_digest' => (string)$row['input_digest'],
            'result_digest' => (string)$row['result_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
        ];
        $this->assertStoredRunDigestIntegrity($run);
        $run['record_readback_status'] = 'readback_verified';
        return $run;
    }

    /** @param array<string,mixed> $run */
    private function assertStoredRunDigestIntegrity(array $run): void
    {
        $input = $run['input'] ?? null;
        $result = $run['result'] ?? null;
        $inputDigest = strtolower(trim((string)($run['input_digest'] ?? '')));
        $resultDigest = strtolower(trim((string)($run['result_digest'] ?? '')));
        if (!is_array($input)
            || !is_array($result)
            || preg_match('/^[a-f0-9]{64}$/D', $inputDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $resultDigest) !== 1
            || !hash_equals($inputDigest, $this->digest($input))
            || !hash_equals($resultDigest, $this->digest($result))
        ) {
            throw new RuntimeException('经营机会记录摘要与保存内容不一致', 409);
        }
    }

    /** @param array<string,mixed> $readback */
    private function assertReadbackIntegrity(
        array $readback,
        int $tenantId,
        int $hotelId,
        string $featureKey,
        string $businessDate,
        string $sourceQuality,
        ?string $sourceReference,
        int $actorUserId,
        string $expectedInputDigest,
        string $expectedResultDigest
    ): void {
        $input = $readback['input'] ?? null;
        $result = $readback['result'] ?? null;
        if (!is_array($input) || !is_array($result)) {
            throw new RuntimeException('经营机会计算保存后精确回读失败');
        }
        $readbackInputDigest = $this->digest($input);
        $readbackResultDigest = $this->digest($result);
        if (!hash_equals($expectedInputDigest, (string)($readback['input_digest'] ?? ''))
            || !hash_equals($expectedResultDigest, (string)($readback['result_digest'] ?? ''))
            || !hash_equals($expectedInputDigest, $readbackInputDigest)
            || !hash_equals($expectedResultDigest, $readbackResultDigest)
            || !hash_equals((string)($readback['input_digest'] ?? ''), $readbackInputDigest)
            || !hash_equals((string)($readback['result_digest'] ?? ''), $readbackResultDigest)
            || (int)($readback['tenant_id'] ?? 0) !== $tenantId
            || (int)($readback['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($readback['feature_key'] ?? '') !== $featureKey
            || (string)($readback['business_date'] ?? '') !== $businessDate
            || (string)($readback['source_quality_status'] ?? '') !== $sourceQuality
            || ($readback['source_reference'] ?? null) !== $sourceReference
            || (int)($readback['created_by'] ?? 0) !== $actorUserId
        ) {
            throw new RuntimeException('经营机会计算保存后精确回读失败');
        }
    }

    private function assertSchemaReady(): void
    {
        try {
            Db::query('SELECT 1 FROM `' . self::RUN_TABLE . '` WHERE 1 = 0');
        } catch (\Throwable) {
            throw new RuntimeException('经营机会数据表未就绪，请先执行数据库迁移');
        }
    }

    /** @param array<int|string,mixed> $input */
    private function assertInputBudget(array $input): void
    {
        try {
            $encoded = $this->encodeJson($input);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('经营机会输入必须是可编码的JSON结构', 0, $error);
        }
        if (strlen($encoded) > self::MAX_INPUT_JSON_BYTES) {
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
        if (!is_array($value)) {
            return;
        }
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

    private function assertScope(int $tenantId, int $hotelId, int $actorUserId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) throw new InvalidArgumentException('租户和酒店范围无效');
        if ($actorUserId <= 0) throw new RuntimeException('未登录');
    }

    private function validDate(string $date): string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) throw new InvalidArgumentException('业务日期必须是有效的YYYY-MM-DD日期');
        return $date;
    }

    private function requiredText(mixed $value, string $label, int $min, int $max): string
    {
        $text = trim((string)$value);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length < $min) throw new InvalidArgumentException($label . '至少需要' . $min . '个字符');
        if ($length > $max) throw new InvalidArgumentException($label . '不能超过' . $max . '个字符');
        return $text;
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        $text = trim((string)$value);
        if ($text === '') return null;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $max) throw new InvalidArgumentException('来源引用不能超过' . $max . '个字符');
        return $text;
    }

    private function sourceQuality(mixed $value): string
    {
        $quality = strtolower(trim((string)$value));
        if (!in_array($quality, self::SOURCE_QUALITY_STATUSES, true)) {
            throw new InvalidArgumentException('数据状态不在允许范围内');
        }
        return $quality;
    }

    /**
     * This endpoint accepts user-entered observations, not a signed collector
     * receipt. A client-provided label can therefore never promote the input
     * to a verified system fact. Verified/readback statuses are reserved for
     * server-side ingestion paths that bind and validate their own evidence.
     */
    private function manualInputSourceQuality(mixed $value): string
    {
        $quality = $this->sourceQuality($value);
        if (!in_array($quality, ['manual_unverified', 'unverified'], true)) {
            throw new InvalidArgumentException('人工录入不能自行声明已验证或已回读');
        }
        return 'manual_unverified';
    }

    /** @param array<string,mixed> $payload */
    private function assertObservationSourceQualityMatches(
        string $featureKey,
        string $sourceQuality,
        array $payload
    ): void {
        if (!in_array($featureKey, ['bookability_gap', 'ai_guest_acquisition'], true)) {
            return;
        }
        $observations = $payload['observations'] ?? null;
        if (!is_array($observations)) {
            return;
        }
        foreach ($observations as $observation) {
            if (!is_array($observation) || !array_key_exists('source_quality', $observation)) {
                continue;
            }
            $nested = strtolower(trim((string)$observation['source_quality']));
            if ($nested !== '' && $nested !== $sourceQuality) {
                throw new InvalidArgumentException('观察证据的数据状态必须与本次数据状态一致');
            }
        }
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->encodeJson($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
    private function encodeJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
    /** @return array<int|string,mixed> */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('经营机会计算记录JSON损坏或被截断', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('经营机会计算记录JSON必须是对象或数组');
        }
        return $decoded;
    }
    private function now(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s');
    }
}
