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
    public const DAILY_SOURCE_MODULE = 'daily_one_thing';

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

    public function __construct(
        private ?DailyOneThingService $dailyOneThing = null,
        private ?DailyOneThingInputService $dailyInput = null,
        private ?DailyOneThingPersonalizationService $dailyPersonalization = null,
        private ?OperatingOutcomeLearningRuntimeService $outcomeLearningRuntime = null
    )
    {
        $this->dailyOneThing ??= new DailyOneThingService();
        $this->dailyInput ??= new DailyOneThingInputService();
        $this->dailyPersonalization ??= new DailyOneThingPersonalizationService(
            $this->dailyOneThing
        );
        $this->outcomeLearningRuntime ??= new OperatingOutcomeLearningRuntimeService();
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
        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $actorUserId,
            $businessDate,
            $idempotencyKey
        ): array {
        $lockedHotel = Db::name('hotels')
            ->field('id,tenant_id,status')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->lock(true)
            ->find();
        if (!is_array($lockedHotel) || (int)($lockedHotel['status'] ?? 0) !== 1) {
            throw new RuntimeException('每日唯一事项酒店范围锁定失败');
        }
        $canonical = $this->latestDailyPriorityRun($tenantId, $hotelId, $businessDate);
        if (is_array($canonical)) {
            $intent = $this->readDailyExecutionIntent($tenantId, $hotelId, (int)$canonical['id'])
                ?? $this->ensureDailyExecutionIntent(
                    $canonical,
                    (array)($canonical['result'] ?? []),
                    $actorUserId
                );
            return [
                'run' => $canonical,
                'replayed' => true,
                'readback_verified' => true,
                'execution_intent' => $intent,
                'execution_intent_id' => (int)($intent['id'] ?? 0),
                'execution_task_count' => count((array)($intent['tasks'] ?? [])),
                'lifecycle_status' => (string)($intent['action_management']['lifecycle']['status'] ?? 'pending_approval'),
                'external_action_triggered' => false,
                'external_write_count' => 0,
            ];
        }
        unset($idempotencyKey);
        $sourceInput = $this->dailyInput->build(
            $tenantId,
            $hotelId,
            $businessDate,
            $actorUserId
        );
        $this->assertDailySourceReady($sourceInput);
        $outcomeLearning = $this->outcomeLearningRuntime->load($tenantId, $hotelId);
        $reviewedObservations = ($outcomeLearning['usable_for_tie_break'] ?? false) === true
            ? array_values((array)($outcomeLearning['reviewed_observations'] ?? []))
            : [];
        $candidates = $this->outcomeLearningRuntime->bindDailyCandidates(
            (array)($sourceInput['candidates'] ?? []),
            $reviewedObservations
        );
        $priority = $this->dailyOneThing->select($candidates, $businessDate, $reviewedObservations);
        $selected = is_array($priority['selected'] ?? null) ? $priority['selected'] : [];
        if ($selected === []) {
            throw new RuntimeException('当前严格事实、已保存问题和明确缺口均未形成可保存的每日事项');
        }
        $input = [
            'business_date' => $businessDate,
            'source_contract' => DailyOneThingInputService::CONTRACT_VERSION,
            'source_digest' => (string)$sourceInput['source_digest'],
            'candidate_digests' => array_values((array)($sourceInput['candidate_digests'] ?? [])),
            'source_errors' => array_values((array)($sourceInput['source_errors'] ?? [])),
            'source_snapshot' => (array)($sourceInput['source_snapshot'] ?? []),
            'selection_contract' => DailyOneThingService::CONTRACT_VERSION,
            'outcome_learning_runtime' => [
                'contract_version' => OperatingOutcomeLearningRuntimeService::CONTRACT_VERSION,
                'status' => (string)($outcomeLearning['status'] ?? 'missing'),
                'reviewed_observation_count' => (int)($outcomeLearning['reviewed_observation_count'] ?? 0),
                'evidence_refs' => array_values((array)($outcomeLearning['evidence_refs'] ?? [])),
                'data_gaps' => array_values((array)($outcomeLearning['data_gaps'] ?? [])),
                'external_write_count' => 0,
            ],
            'selected_candidate_digest' => (string)$selected['content_digest'],
            'external_write_boundary' => (array)($sourceInput['boundary'] ?? []),
        ];
        $priority['feature_key'] = 'daily_one_thing';
        $priority['feature_label'] = (string)self::FEATURES['daily_one_thing']['label'];
        $priority['external_write_allowed'] = false;
        $serverIdempotencyKey = 'daily_one_thing_' . substr(hash('sha256', implode('|', [
            'daily_one_thing_execution_bridge.v1',
            (string)$sourceInput['source_digest'],
            (string)$selected['content_digest'],
        ])), 0, 40);
        $existingRun = $this->latestDailyPriorityRun($tenantId, $hotelId, $businessDate);
        $saved = is_array($existingRun)
            && $this->sameDailyMaterialIdentity(
                (array)($existingRun['result']['selected'] ?? []),
                $selected
            )
                ? ['run' => $existingRun, 'replayed' => true, 'readback_verified' => true]
                : $this->saveRun(
                    $tenantId,
                    $hotelId,
                    $actorUserId,
                    'daily_one_thing',
                    $businessDate,
                    'readback_verified',
                    (string)($selected['source']['record_ref'] ?? '') ?: null,
                    $serverIdempotencyKey,
                    $input,
                    $priority
                );
        $intent = $this->ensureDailyExecutionIntent(
            (array)$saved['run'],
            (array)($saved['run']['result'] ?? []),
            $actorUserId
        );
        return $saved + [
            'execution_intent' => $intent,
            'execution_intent_id' => (int)($intent['id'] ?? 0),
            'execution_task_count' => count((array)($intent['tasks'] ?? [])),
            'lifecycle_status' => (string)($intent['action_management']['lifecycle']['status'] ?? 'pending_approval'),
            'external_action_triggered' => false,
            'external_write_count' => 0,
        ];
        });
    }

    /**
     * Background preparation may create the first pending-approval item, but
     * it must never replace an already frozen item for the same business day.
     * Later fact changes are reported for the operator to inspect.
     *
     * @return array<string,mixed>
     */
    public function ensureDailyPriorityForAutomation(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        string $businessDate
    ): array {
        $this->assertScope($tenantId, $hotelId, $actorUserId);
        $this->assertSchemaReady();
        $businessDate = $this->validDate($businessDate);
        $existing = $this->latestDailyPriorityRun($tenantId, $hotelId, $businessDate);
        if ($existing === null) {
            $created = $this->saveDailyPriority(
                $tenantId,
                $hotelId,
                $actorUserId,
                $businessDate,
                'automation'
            );
            return $created + [
                'automation_status' => 'created_pending_approval',
                'existing_item_preserved' => false,
                'source_changed' => false,
            ];
        }

        $intent = $this->readDailyExecutionIntent(
            $tenantId,
            $hotelId,
            (int)$existing['id']
        );
        if ($intent === null) {
            $intent = $this->ensureDailyExecutionIntent(
                $existing,
                (array)($existing['result'] ?? []),
                $actorUserId
            );
        }

        $savedSourceDigest = strtolower(trim((string)($existing['input']['source_digest'] ?? '')));
        $currentSourceDigest = '';
        $sourceStatus = 'source_unavailable';
        try {
            $current = $this->dailyInput->build(
                $tenantId,
                $hotelId,
                $businessDate,
                $actorUserId
            );
            $currentSourceDigest = strtolower(trim((string)($current['source_digest'] ?? '')));
            $sourceStatus = (string)($current['strict_fact_status'] ?? 'source_unavailable');
        } catch (\Throwable) {
            // The frozen item remains the canonical daily instruction. Source
            // availability is reported without rewriting its lineage.
        }
        $sourceChanged = preg_match('/^[a-f0-9]{64}$/D', $savedSourceDigest) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $currentSourceDigest) === 1
            && !hash_equals($savedSourceDigest, $currentSourceDigest);

        return [
            'run' => $existing,
            'replayed' => true,
            'readback_verified' => true,
            'execution_intent' => $intent,
            'execution_intent_id' => (int)($intent['id'] ?? 0),
            'execution_task_count' => count((array)($intent['tasks'] ?? [])),
            'lifecycle_status' => (string)($intent['action_management']['lifecycle']['status'] ?? 'pending_approval'),
            'automation_status' => $sourceChanged
                ? 'existing_item_preserved_source_changed'
                : 'existing_item_restored',
            'existing_item_preserved' => true,
            'source_changed' => $sourceChanged,
            'saved_source_digest' => $savedSourceDigest !== '' ? $savedSourceDigest : null,
            'current_source_digest' => $currentSourceDigest !== '' ? $currentSourceDigest : null,
            'current_source_status' => $sourceStatus,
            'external_action_triggered' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public function overview(int $tenantId, int $hotelId, string $businessDate, int $ownerId): array
    {
        $this->assertScope($tenantId, $hotelId, $ownerId);
        $this->assertSchemaReady();
        $businessDate = $this->validDate($businessDate);
        $sourceInput = $this->dailyInput->build($tenantId, $hotelId, $businessDate, $ownerId);
        $strictFactReady = $this->dailySourceReady($sourceInput);
        $outcomeLearning = $this->outcomeLearningRuntime->load($tenantId, $hotelId);
        $reviewedObservations = ($outcomeLearning['usable_for_tie_break'] ?? false) === true
            ? array_values((array)($outcomeLearning['reviewed_observations'] ?? []))
            : [];
        $dailyCandidates = $this->outcomeLearningRuntime->bindDailyCandidates(
            $strictFactReady ? (array)($sourceInput['candidates'] ?? []) : [],
            $reviewedObservations
        );
        $priority = $this->dailyOneThing->select(
            $dailyCandidates,
            $businessDate,
            $reviewedObservations
        );
        $personalizedPriority = $this->dailyPersonalization->select(
            $dailyCandidates,
            $businessDate,
            $tenantId,
            $ownerId,
            $hotelId,
            $reviewedObservations
        );
        if (!$strictFactReady) {
            $priority['status'] = 'blocked_by_source_unavailable';
            $priority['headline'] = '严格事实来源暂不可读取，已阻止保存和送审';
            $priority['requires_human_approval'] = false;
            $personalizedPriority['status'] = 'blocked_by_source_unavailable';
            $personalizedPriority['headline'] = '严格事实来源暂不可读取，个性化预览未生成';
            $personalizedPriority['requires_human_approval'] = false;
        }
        $savedPriorityRun = $this->latestDailyPriorityRun($tenantId, $hotelId, $businessDate);
        $currentSourceDigest = (string)($sourceInput['source_digest'] ?? '');
        $savedSourceDigest = (string)($savedPriorityRun['input']['source_digest'] ?? '');
        $currentSelectionDigest = (string)($priority['selected']['content_digest'] ?? '');
        $savedSelectionDigest = (string)($savedPriorityRun['result']['selected']['content_digest'] ?? '');
        $savedPriorityIsCurrent = $strictFactReady && $savedPriorityRun !== null
            && ($this->sameDailyMaterialIdentity(
                (array)($savedPriorityRun['result']['selected'] ?? []),
                (array)($priority['selected'] ?? [])
            ) || (preg_match('/^[a-f0-9]{64}$/D', $currentSourceDigest) === 1
                && hash_equals($currentSourceDigest, $savedSourceDigest)
                && preg_match('/^[a-f0-9]{64}$/D', $currentSelectionDigest) === 1
                && hash_equals($currentSelectionDigest, $savedSelectionDigest)));
        $latestRuns = $this->latestFeatureRuns($tenantId, $hotelId, $businessDate);
        $dailyIntent = $savedPriorityRun === null
            ? null
            : $this->readDailyExecutionIntent(
                $tenantId,
                $hotelId,
                (int)$savedPriorityRun['id']
            );
        $today = $savedPriorityIsCurrent
            ? $this->withDailyIntentProjection((array)$savedPriorityRun['result'], $dailyIntent)
            : $priority;
        $historyRows = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('feature_key', 'daily_one_thing')
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();
        $historyReadback = $this->projectDailyHistoryRows($historyRows);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'catalog' => $this->catalog(),
            'today' => $today,
            'today_preview' => $priority,
            'personalized_today_preview' => $personalizedPriority,
            'personalization_receipt' => (array)($personalizedPriority['personalization_receipt'] ?? []),
            'today_saved_run' => $savedPriorityRun,
            'today_execution_intent' => $dailyIntent,
            'today_execution_intent_id' => (int)($dailyIntent['id'] ?? 0),
            'today_execution_task_id' => (int)($dailyIntent['tasks'][0]['id'] ?? 0),
            'today_lifecycle_status' => (string)($dailyIntent['action_management']['lifecycle']['status'] ?? ($savedPriorityIsCurrent ? 'pending_approval' : 'draft')),
            'today_state' => !$strictFactReady
                ? 'source_unavailable'
                : ($savedPriorityRun === null
                    ? 'not_saved'
                    : ($savedPriorityIsCurrent
                        ? ($dailyIntent === null ? 'saved_without_lifecycle' : 'saved_current')
                        : 'saved_stale')),
            'latest_runs' => array_values($latestRuns),
            'history' => $historyReadback['rows'],
            'history_readback_errors' => $historyReadback['errors'],
            'source_contract' => DailyOneThingInputService::CONTRACT_VERSION,
            'source_digest' => $currentSourceDigest,
            'strict_fact_status' => (string)($sourceInput['strict_fact_status'] ?? 'source_unavailable'),
            'source_errors' => array_values((array)($sourceInput['source_errors'] ?? [])),
            'scope_notice' => '每日候选只来自当前酒店、当前营业日的严格事实、已保存问题或明确缺口；个人预览只在最高四维基础并列组内调整，不改写酒店共享正式事项。',
            'boundaries' => [
                'automatic_approval' => false,
                'automatic_execution' => false,
                'automatic_ctrip_write' => false,
                'automatic_meituan_write' => false,
                'automatic_pms_write' => false,
                'automatic_wecom_message' => false,
                'external_write_count_before_approval' => 0,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function recordDailyPreviewFeedback(
        int $tenantId,
        int $hotelId,
        int $actorUserId,
        string $businessDate,
        string $expectedSelectionDigest,
        string $expectedContextDigest,
        string $expectedDecisionDigest,
        string $feedbackStatus,
        string $reasonCode,
        string $idempotencyKey
    ): array {
        $this->assertScope($tenantId, $hotelId, $actorUserId);
        $this->assertSchemaReady();
        $businessDate = $this->validDate($businessDate);
        foreach ([
            'expected_selection_digest' => $expectedSelectionDigest,
            'expected_context_digest' => $expectedContextDigest,
            'expected_decision_digest' => $expectedDecisionDigest,
        ] as $field => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($digest))) !== 1) {
                throw new InvalidArgumentException($field . ' 无效');
            }
        }
        $sourceInput = $this->dailyInput->build(
            $tenantId,
            $hotelId,
            $businessDate,
            $actorUserId
        );
        $this->assertDailySourceReady($sourceInput);
        $outcomeLearning = $this->outcomeLearningRuntime->load($tenantId, $hotelId);
        $reviewedObservations = ($outcomeLearning['usable_for_tie_break'] ?? false) === true
            ? array_values((array)($outcomeLearning['reviewed_observations'] ?? []))
            : [];
        $dailyCandidates = $this->outcomeLearningRuntime->bindDailyCandidates(
            (array)($sourceInput['candidates'] ?? []),
            $reviewedObservations
        );
        $preview = $this->dailyPersonalization->select(
            $dailyCandidates,
            $businessDate,
            $tenantId,
            $actorUserId,
            $hotelId,
            $reviewedObservations
        );
        $selected = is_array($preview['selected'] ?? null) ? $preview['selected'] : [];
        $receipt = is_array($preview['personalization_receipt'] ?? null)
            ? $preview['personalization_receipt']
            : [];
        if ($selected === []
            || !hash_equals(
                strtolower(trim($expectedSelectionDigest)),
                (string)($selected['content_digest'] ?? '')
            )
            || !hash_equals(
                strtolower(trim($expectedContextDigest)),
                (string)($receipt['context_digest'] ?? '')
            )
            || !hash_equals(
                strtolower(trim($expectedDecisionDigest)),
                (string)($receipt['decision_digest'] ?? '')
            )
        ) {
            throw new RuntimeException('每日事项个性化预览已变化，请刷新后重新反馈', 409);
        }
        $feedback = $this->dailyPersonalization->recordFeedback(
            $tenantId,
            $actorUserId,
            $hotelId,
            $businessDate,
            $selected,
            $receipt,
            (string)$sourceInput['source_digest'],
            $feedbackStatus,
            $reasonCode,
            $idempotencyKey
        );
        if (($feedback['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('每日事项个性化反馈未完成精确回读');
        }
        return $feedback + [
            'system_hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'selected_candidate_key' => (string)$selected['candidate_key'],
            'selection_digest' => (string)$selected['content_digest'],
            'context_digest' => (string)$receipt['context_digest'],
            'decision_digest' => (string)$receipt['decision_digest'],
            'hotel_shared_daily_item_changed' => false,
            'execution_intent_created' => false,
            'external_write_count' => 0,
        ];
    }

    /** @param array<string,mixed> $sourceInput */
    private function dailySourceReady(array $sourceInput): bool
    {
        if ((string)($sourceInput['strict_fact_status'] ?? '') !== 'readback_ready') {
            return false;
        }
        foreach ((array)($sourceInput['source_errors'] ?? []) as $sourceError) {
            if (is_array($sourceError)
                && (string)($sourceError['code'] ?? '') === 'strict_fact_layer_unavailable'
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $sourceInput */
    private function assertDailySourceReady(array $sourceInput): void
    {
        if (!$this->dailySourceReady($sourceInput)) {
            throw new RuntimeException('每日事项严格事实来源暂不可用，不能保存或送审', 503);
        }
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

    /**
     * Revalidate the immutable daily source. Before execution starts, the
     * selected current source must still match; after the task starts, the
     * source is expected to change and only the frozen lineage is checked.
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    public function assertDailyIntentCurrent(array $intent): array
    {
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $runId = (int)($intent['source_record_id'] ?? 0);
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) !== self::DAILY_SOURCE_MODULE
            || $tenantId <= 0 || $hotelId <= 0 || $runId <= 0
        ) {
            throw new InvalidArgumentException('每日一件事执行意图来源身份无效');
        }
        $run = $this->readRun($tenantId, $hotelId, $runId);
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $selected = is_array($result['selected'] ?? null) ? $result['selected'] : [];
        $target = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $card = is_array($target['action_card'] ?? null)
            ? $target['action_card']
            : (is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : []);
        if ((string)($card['contract_version'] ?? '') !== OperationActionLifecycleService::DAILY_CARD_CONTRACT_VERSION
            || (int)($card['source']['record_id'] ?? 0) !== $runId
            || (string)($card['source']['module'] ?? '') !== self::DAILY_SOURCE_MODULE
            || !hash_equals((string)$run['input_digest'], strtolower(trim((string)($card['trace']['daily_run_input_digest'] ?? ''))))
            || !hash_equals((string)$run['result_digest'], strtolower(trim((string)($card['trace']['daily_run_result_digest'] ?? ''))))
            || !hash_equals((string)($selected['content_digest'] ?? ''), strtolower(trim((string)($card['trace']['daily_selection_digest'] ?? ''))))
            || !hash_equals((string)($selected['content_digest'] ?? ''), DailyOneThingService::digest($selected))
        ) {
            throw new InvalidArgumentException('每日一件事行动卡与保存快照不一致');
        }
        (new OperationActionLifecycleService())->assertPendingCardCurrent($intent);

        $task = Db::name('operation_execution_tasks')
            ->where('intent_id', (int)($intent['id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->find();
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        if (!in_array($taskStatus, ['executing', 'executed', 'failed', 'blocked'], true)) {
            $currentInput = $this->dailyInput->build(
                $tenantId,
                $hotelId,
                (string)$run['business_date'],
                (int)($card['responsibility']['owner_id'] ?? $intent['created_by'] ?? 0)
            );
            $current = $this->dailyOneThing->select(
                (array)($currentInput['candidates'] ?? []),
                (string)$run['business_date']
            );
            if (!$this->sameDailyMaterialIdentity(
                $selected,
                (array)($current['selected'] ?? [])
            )) {
                throw new InvalidArgumentException('每日一件事来源事实已变化，请刷新后重新生成');
            }
        }
        return $selected;
    }

    /**
     * Build a system-only follow-up readback for the data-completeness item.
     * It returns null until a later accepted receipt for the same hotel,
     * platform, target date and metric exists.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|null
     */
    public function buildDailyStrictFactCountReadback(array $task, array $intent): ?array
    {
        $selected = $this->assertDailyIntentCurrent($intent);
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        if ($metricKey !== 'ctrip_strict_core_fact_count') {
            return null;
        }
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $businessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $executedAt = trim((string)($task['executed_at'] ?? ''));
        $executedTimestamp = strtotime($executedAt);
        if ($hotelId <= 0 || $tenantId <= 0 || $businessDate === '' || $executedTimestamp === false) {
            return null;
        }
        $closure = (new DualOtaFieldClosureService())->build($hotelId, $businessDate);
        if ((int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
        ) {
            return null;
        }
        $platform = is_array($closure['platforms']['ctrip'] ?? null)
            ? $closure['platforms']['ctrip'] : [];
        $fields = [];
        foreach ((array)($platform['fields'] ?? []) as $field) {
            if (is_array($field) && trim((string)($field['key'] ?? '')) !== '') {
                $fields[(string)$field['key']] = $field;
            }
        }
        $collectedAt = trim((string)($fields['collected_at']['value'] ?? ''));
        $collectedTimestamp = $collectedAt !== '' ? strtotime($collectedAt) : false;
        if ($collectedTimestamp === false || $collectedTimestamp <= $executedTimestamp) {
            return null;
        }
        $coreKeys = ['revenue', 'order_count', 'room_nights', 'exposure', 'visits', 'conversion'];
        $afterValue = count(array_filter($coreKeys, static function (string $key) use ($fields): bool {
            $field = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            return in_array((string)($field['status'] ?? ''), ['strict_readback', 'verified_calculation'], true)
                && ($field['identity_binding_verified'] ?? false) === true
                && ($field['strict_final_gate'] ?? false) === true;
        }));
        $beforeValue = (float)($selected['expected_observation_metric']['baseline_value'] ?? 0);
        $sourceIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($platform['current_receipt_record_ids'] ?? [])
        ), static fn(int $id): bool => $id > 0)));
        if ($sourceIds === [] || $afterValue <= $beforeValue) {
            return null;
        }
        sort($sourceIds, SORT_NUMERIC);
        $sourceRef = 'online_daily_data#' . implode(',', $sourceIds);
        return [
            'task_id' => (int)($task['id'] ?? 0),
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$metricKey => $beforeValue],
            'after' => [$metricKey => (float)$afterValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'dual_ota_field_closure',
                'source_ref' => $sourceRef,
                'baseline_source_ref' => OperatingOpportunityLabService::RUN_TABLE . '#' . (int)$intent['source_record_id'],
                'followup_source_ref' => $sourceRef,
                'system_hotel_id' => $hotelId,
                'platform' => 'ctrip',
                'object_type' => 'data_collection',
                'date_start' => $businessDate,
                'date_end' => $businessDate,
                'baseline_date' => $businessDate,
                'review_date' => $businessDate,
                'metric_key' => $metricKey,
                'metric_unit' => 'verified_fields',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($sourceIds),
                'readback_at' => date('Y-m-d H:i:s', $collectedTimestamp),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'causality_claimed' => false,
                'effect_evidence_status' => 'observed_not_attributed',
                'measurement_policy' => 'same_hotel_same_platform_same_target_date_strict_fact_count_after_manual_execution',
                'closure_digest' => (string)($closure['closure_digest'] ?? ''),
            ],
            'remark' => 'system-generated strict Ctrip fact-count readback; change is observational and not causal',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $priority @return array<string,mixed> */
    private function ensureDailyExecutionIntent(array $run, array $priority, int $actorUserId): array
    {
        $selected = is_array($priority['selected'] ?? null) ? $priority['selected'] : [];
        $lifecycle = new OperationActionLifecycleService();
        $card = $lifecycle->buildDailyOneThingPendingCard($run, $selected, $actorUserId);
        $target = $lifecycle->alignManualTaskProjection([
            'title' => (string)$selected['recommended_action']['title'],
            'action_text' => (string)$selected['recommended_action']['description'],
            'action_object' => (string)$selected['recommended_action']['object'],
            'assignee_id' => $actorUserId,
            'workflow_schedule' => [
                'assignee_id' => $actorUserId,
                'due_at' => (string)$selected['responsibility']['due_at'],
                'review_at' => (string)$selected['responsibility']['review_at'],
                'source_policy' => 'daily_one_thing_human_schedule_requires_explicit_confirmation',
            ],
        ], $card);
        $target['collection_scope'] = (string)$selected['recommended_action']['object'];
        $target['target_date'] = (string)$run['business_date'];
        $metricKey = (string)$selected['expected_observation_metric']['key'];
        $baselineValue = (float)$selected['expected_observation_metric']['baseline_value'];
        $sourceRefs = array_values(array_unique(array_filter(array_merge(
            [self::RUN_TABLE . '#' . (int)$run['id']],
            (array)($selected['source']['fact_refs'] ?? [])
        ))));
        $operations = new OperationManagementService();
        $intent = $operations->createExecutionIntent(
            [(int)$run['system_hotel_id']],
            (int)$run['system_hotel_id'],
            [
                'source_module' => self::DAILY_SOURCE_MODULE,
                'source_record_id' => (int)$run['id'],
                'hotel_id' => (int)$run['system_hotel_id'],
                'platform' => (string)$selected['scope']['platform'],
                'object_type' => (string)$selected['source_type'] === 'explicit_data_gap'
                    ? 'data_collection' : 'operation_checklist',
                'action_type' => (string)$selected['recommended_action']['type'],
                'date_start' => (string)$run['business_date'],
                'date_end' => (string)$run['business_date'],
                'current_value' => [
                    $metricKey => $baselineValue,
                    'daily_one_thing_run_id' => (int)$run['id'],
                    'daily_selection_digest' => (string)$selected['content_digest'],
                ],
                'target_value' => $target,
                'evidence' => [
                    'contract_version' => DailyOneThingService::CONTRACT_VERSION,
                    'source_policy' => 'strict_fact_or_saved_question_or_explicit_gap_then_human_confirmation',
                    'daily_one_thing_run_ref' => self::RUN_TABLE . '#' . (int)$run['id'],
                    'daily_run_input_digest' => (string)$run['input_digest'],
                    'daily_run_result_digest' => (string)$run['result_digest'],
                    'source_snapshot_digest' => (string)$selected['source']['snapshot_digest'],
                    'daily_selection_digest' => (string)$selected['content_digest'],
                    'evidence_refs' => $sourceRefs,
                    'source_refs' => $sourceRefs,
                    'data_gaps' => (array)($selected['source']['gap_codes'] ?? []),
                    'workflow_schedule' => (array)$target['workflow_schedule'],
                    'action_card' => $card,
                    'automatic_collection' => false,
                    'automatic_approval' => false,
                    'automatic_execution' => false,
                    'automatic_ota_write' => false,
                    'automatic_pms_write' => false,
                    'external_message' => false,
                    'automatic_wecom_message' => false,
                ],
                'expected_metric' => $metricKey,
                'expected_delta' => null,
                'risk_level' => (string)$selected['risk']['level'],
                'status' => 'pending_approval',
            ],
            $actorUserId,
            false,
            'daily_one_thing_action_' . substr($lifecycle->actionIdentityDigest($card), 0, 32),
            true
        );
        $this->assertDailyIntentReadback($intent, $run, $selected);
        return $intent;
    }

    /** @return ?array<string,mixed> */
    private function readDailyExecutionIntent(int $tenantId, int $hotelId, int $runId): ?array
    {
        if (!$this->tableExists('operation_execution_intents')) {
            return null;
        }
        $ids = Db::name('operation_execution_intents')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('source_module', self::DAILY_SOURCE_MODULE)
            ->where('source_record_id', $runId)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->column('id');
        if ($ids === []) {
            return null;
        }
        $run = $this->readRun($tenantId, $hotelId, $runId);
        $selected = (array)($run['result']['selected'] ?? []);
        $operations = new OperationManagementService();
        foreach (array_map('intval', $ids) as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $intent = $operations->readExecutionIntent($id, [$hotelId]);
                $this->assertDailyIntentReadback($intent, $run, $selected);
                return $intent;
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $run @param array<string,mixed> $selected */
    private function assertDailyIntentReadback(array $intent, array $run, array $selected): void
    {
        $tasks = array_values((array)($intent['tasks'] ?? []));
        $status = strtolower(trim((string)($intent['status'] ?? '')));
        $card = is_array($intent['action_management']['action_card'] ?? null)
            ? $intent['action_management']['action_card'] : [];
        if ((int)($intent['tenant_id'] ?? 0) !== (int)$run['tenant_id']
            || (int)($intent['hotel_id'] ?? 0) !== (int)$run['system_hotel_id']
            || (string)($intent['source_module'] ?? '') !== self::DAILY_SOURCE_MODULE
            || (int)($intent['source_record_id'] ?? 0) !== (int)$run['id']
            || (string)($card['contract_version'] ?? '') !== OperationActionLifecycleService::DAILY_CARD_CONTRACT_VERSION
            || !hash_equals((string)$selected['content_digest'], (string)($card['trace']['daily_selection_digest'] ?? ''))
            || !in_array($status, ['pending_approval', 'approved'], true)
            || ($status === 'pending_approval' && $tasks !== [])
            || ($status === 'approved' && count($tasks) !== 1)
        ) {
            throw new RuntimeException('每日一件事保存后生命周期精确回读失败');
        }
    }

    /** @param array<string,mixed> $priority @param ?array<string,mixed> $intent @return array<string,mixed> */
    private function withDailyIntentProjection(array $priority, ?array $intent): array
    {
        if (!is_array($intent)) {
            return $priority;
        }
        $lifecycleStatus = (string)($intent['action_management']['lifecycle']['status'] ?? 'pending_approval');
        if (is_array($priority['selected'] ?? null)) {
            $priority['selected']['approval_status'] = $lifecycleStatus;
            $priority['selected']['execution_intent_id'] = (int)($intent['id'] ?? 0);
            $priority['selected']['execution_task_id'] = (int)($intent['tasks'][0]['id'] ?? 0);
        }
        $priority['status'] = $lifecycleStatus;
        $priority['execution_intent_id'] = (int)($intent['id'] ?? 0);
        $priority['execution_task_id'] = (int)($intent['tasks'][0]['id'] ?? 0);
        $priority['lifecycle'] = (array)($intent['action_management']['lifecycle'] ?? []);
        $priority['task_count'] = count((array)($intent['tasks'] ?? []));
        $priority['external_write_performed_by_system'] = false;
        return $priority;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameDailyMaterialIdentity(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return false;
        }
        return hash_equals(
            DailyOneThingService::materialIdentityDigest($left),
            DailyOneThingService::materialIdentityDigest($right)
        );
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

    /**
     * One damaged historical row must remain visible as an integrity error but
     * cannot hide a newer verified daily item or the rest of the history.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{rows:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
     */
    private function projectDailyHistoryRows(array $rows): array
    {
        $projected = [];
        $errors = [];
        foreach ($rows as $row) {
            try {
                $projected[] = $this->publicRun($row);
            } catch (\Throwable) {
                $errors[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'business_date' => (string)($row['business_date'] ?? ''),
                    'status' => 'integrity_failed',
                    'reason_code' => 'daily_one_thing_digest_mismatch',
                ];
            }
        }
        return ['rows' => $projected, 'errors' => $errors];
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

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
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
