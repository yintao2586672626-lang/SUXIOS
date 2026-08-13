<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\OperationEffectReviewService;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * The single authoritative operating-loop state machine.
 *
 * Domain tables keep their own facts and receipts. This service is the only
 * writer allowed to declare that a cross-module stage is complete. Every
 * transition is append-only, evidence-linked, hash-chained and followed by an
 * exact database readback.
 */
final class OperatingLoopKernelService
{
    public const CONTRACT_VERSION = 'hotel_operating_cycle.v1';
    public const RECORD_TABLE = 'hotel_operating_cycles';
    public const EVENT_TABLE = 'hotel_operating_cycle_events';
    public const EVIDENCE_TABLE = 'hotel_operating_cycle_evidence';

    /** @var array<string,array{index:int,label:string}> */
    private const STAGES = [
        'identity_business_date_confirmed' => ['index' => 0, 'label' => '身份与业务日期确认'],
        'trusted_collection' => ['index' => 1, 'label' => '可信采集'],
        'formal_save_exact_readback' => ['index' => 2, 'label' => '正式保存与精确回读'],
        'operating_facts_established' => ['index' => 3, 'label' => '经营事实成立'],
        'recommendation_human_decision' => ['index' => 4, 'label' => '建议与人工判断'],
        'real_execution_receipt' => ['index' => 5, 'label' => '真实执行与回执'],
        'comparable_outcome_readback' => ['index' => 6, 'label' => '同口径结果回读'],
        'review_experience_promotion' => ['index' => 7, 'label' => '复盘与经验晋级'],
    ];

    /** @var array<string,array{pk?:string,tenant?:string,hotel?:string,date?:string,platform?:string}> */
    private const DIRECT_SOURCE_SCOPES = [
        'hotels' => ['tenant' => 'tenant_id', 'hotel' => 'id'],
        'platform_data_sources' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'platform' => 'platform'],
        'ota_local_collector_account_hotels' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'platform' => 'platform'],
        'dingdandao_pms_integrations' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'platform' => 'provider'],
        'meituan_cloud_pms_integrations' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'platform' => 'provider'],
        'hotel_collection_plan_runs' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'date' => 'business_date'],
        'platform_data_sync_tasks' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'platform' => 'platform'],
        'ota_local_collector_tasks' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'date' => 'data_date', 'platform' => 'platform'],
        'online_daily_data' => ['tenant' => 'tenant_id', 'hotel' => 'system_hotel_id', 'date' => 'data_date', 'platform' => 'source'],
        'daily_reports' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'report_date'],
        'meituan_cloud_pms_captures' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'business_date', 'platform' => 'provider'],
        'dingdandao_operating_target_captures' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'business_date', 'platform' => 'provider'],
        'operation_logs' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
        'price_suggestions' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'suggestion_date'],
        'hotel_operating_questions' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
        'operation_execution_intents' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'date_start', 'platform' => 'platform'],
        'operation_execution_tasks' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
        'operation_effect_reviews' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'baseline_business_date', 'platform' => 'platform'],
        'hotel_operating_memories' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id', 'date' => 'business_date', 'platform' => 'platform'],
        'hotel_operating_sop_versions' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
        'knowledge_units' => ['pk' => 'unit_id', 'tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
        'knowledge_promotion_events' => ['tenant' => 'tenant_id', 'hotel' => 'hotel_id'],
    ];

    /** @var list<string> */
    private const SPECIAL_SOURCE_TABLES = [
        'hotel_collection_plan_run_sources',
        'operation_execution_evidence',
    ];

    /** @var array<string,array<string,array{tables:list<string>,kinds:list<string>}>> */
    private const STAGE_EVIDENCE_CONTRACTS = [
        'identity_business_date_confirmed' => [
            'hotel_identity' => ['tables' => ['hotels'], 'kinds' => ['identity']],
            'source_identity' => ['tables' => [
                'ota_local_collector_account_hotels',
                'platform_data_sources',
                'dingdandao_pms_integrations',
                'meituan_cloud_pms_integrations',
            ], 'kinds' => ['identity']],
        ],
        'trusted_collection' => [
            'collection_source' => ['tables' => [
                'hotel_collection_plan_runs',
                'hotel_collection_plan_run_sources',
            ], 'kinds' => ['pms', 'ota']],
        ],
        'formal_save_exact_readback' => [
            'collection_source' => ['tables' => [
                'hotel_collection_plan_runs',
                'hotel_collection_plan_run_sources',
            ], 'kinds' => ['pms', 'ota']],
            'saved_rows' => ['tables' => [
                'online_daily_data',
                'meituan_cloud_pms_captures',
                'dingdandao_operating_target_captures',
            ], 'kinds' => ['pms', 'ota']],
        ],
        'operating_facts_established' => [
            'pms_fact_rows' => ['tables' => [
                'meituan_cloud_pms_captures',
                'dingdandao_operating_target_captures',
            ], 'kinds' => ['pms']],
            'ota_fact_rows' => ['tables' => ['online_daily_data'], 'kinds' => ['ota']],
        ],
        'recommendation_human_decision' => [
            'recommendation' => ['tables' => [
                'price_suggestions',
                'operation_execution_intents',
            ], 'kinds' => ['decision']],
            'human_decision' => ['tables' => [
                'price_suggestions',
                'operation_execution_intents',
            ], 'kinds' => ['decision', 'approval']],
            'approval' => ['tables' => ['operation_execution_intents'], 'kinds' => ['approval']],
        ],
        'real_execution_receipt' => [
            'execution_intent' => ['tables' => ['operation_execution_intents'], 'kinds' => ['approval']],
            'execution_task' => ['tables' => ['operation_execution_tasks'], 'kinds' => ['execution']],
            'execution_receipt' => ['tables' => ['operation_execution_evidence'], 'kinds' => ['execution']],
        ],
        'comparable_outcome_readback' => [
            'outcome_readback' => ['tables' => ['operation_effect_reviews'], 'kinds' => ['outcome']],
        ],
        'review_experience_promotion' => [
            'operating_memory' => ['tables' => ['hotel_operating_memories'], 'kinds' => ['knowledge']],
            'reusable_experience' => ['tables' => ['hotel_operating_sop_versions'], 'kinds' => ['knowledge']],
            'knowledge' => ['tables' => ['knowledge_units'], 'kinds' => ['knowledge']],
            'promotion_event' => ['tables' => ['knowledge_promotion_events'], 'kinds' => ['knowledge']],
        ],
    ];

    /** @return array<string,mixed> */
    public function open(int $tenantId, int $hotelId, array $input, int $actorId): array
    {
        $this->assertTablesReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('经营闭环缺少有效租户、酒店或确认人');
        }

        $businessDate = $this->date($input['business_date'] ?? null, '业务日期');
        if ($businessDate > date('Y-m-d')) {
            throw new InvalidArgumentException('经营闭环业务日期不能晚于今天');
        }
        $metricVersion = $this->token($input['metric_version'] ?? null, '指标版本', 80);
        $metricDefinition = $this->requiredObject($input['metric_definition'] ?? null, '指标定义');
        $metricDefinitionDigest = $this->digest($metricDefinition);
        $sourceIdentities = $this->normalizeSourceIdentities($input['source_identities'] ?? null);
        $sourceIdentityDigest = $this->digest($sourceIdentities);
        $sourceModule = $this->token($input['source_module'] ?? 'operating_loop_api', '来源模块', 80);
        $authorityKey = hash('sha256', implode("\0", [(string)$tenantId, (string)$hotelId, $businessDate]));
        $commandKey = $this->commandKey(
            $input['command_key'] ?? null,
            'open:' . $authorityKey . ':' . $metricDefinitionDigest . ':' . $sourceIdentityDigest
        );

        $write = Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $actorId,
            $businessDate,
            $metricVersion,
            $metricDefinition,
            $metricDefinitionDigest,
            $sourceIdentities,
            $sourceIdentityDigest,
            $sourceModule,
            $authorityKey,
            $commandKey,
            $input
        ): array {
            $hotel = Db::name('hotels')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->lock(true)
                ->find();
            if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
                throw new RuntimeException('经营闭环酒店不存在、已停用或租户身份不一致');
            }
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelName === '') {
                throw new RuntimeException('经营闭环酒店名称缺失');
            }
            $this->assertSourceIdentitiesMatchActivePlan(
                $sourceIdentities,
                $tenantId,
                $hotelId
            );
            $identityRefs = [[
                'role' => 'hotel_identity',
                'source_kind' => 'identity',
                'table' => 'hotels',
                'row_id' => $hotelId,
                'business_date' => $businessDate,
            ]];
            foreach ($sourceIdentities as $identity) {
                $ref = $identity['evidence_ref'];
                $ref['role'] = 'source_identity';
                $ref['source_kind'] = 'identity';
                $ref['platform'] = $identity['platform'];
                $ref['business_date'] = $businessDate;
                $identityRefs[] = $ref;
            }
            $payload = [
                'hotel' => ['id' => $hotelId, 'name' => $hotelName],
                'business_date' => $businessDate,
                'metric_version' => $metricVersion,
                'metric_definition_digest' => $metricDefinitionDigest,
                'source_identity_digest' => $sourceIdentityDigest,
                'source_count' => count($sourceIdentities),
                'confirmed_by' => $actorId,
                'truth_summary' => $this->optionalText(
                    $input['truth_summary'] ?? null,
                    2000,
                    '酒店、来源身份、业务日期和指标口径已确认'
                ),
            ];
            $identityCommandDigest = $this->commandDigest(
                'identity_business_date_confirmed',
                'completed',
                'human',
                $actorId,
                $sourceModule,
                $payload,
                $identityRefs,
                $businessDate
            );

            $existing = Db::name(self::RECORD_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('business_date', $businessDate)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                if (!hash_equals((string)$existing['metric_definition_digest'], $metricDefinitionDigest)
                    || !hash_equals((string)$existing['source_identity_digest'], $sourceIdentityDigest)
                    || (string)$existing['metric_version'] !== $metricVersion
                ) {
                    throw new InvalidArgumentException('同一酒店同一业务日已经存在另一份权威口径，不能创建并行闭环');
                }
                $identityEvent = Db::name(self::EVENT_TABLE)
                    ->where('cycle_id', (int)$existing['id'])
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('sequence_no', 1)
                    ->find();
                if (!is_array($identityEvent)
                    || (string)($identityEvent['command_key'] ?? '') !== $commandKey
                    || !hash_equals((string)($identityEvent['command_digest'] ?? ''), $identityCommandDigest)
                ) {
                    throw new InvalidArgumentException('经营闭环 open command_key 已被另一份确认载荷使用');
                }
                return ['id' => (int)$existing['id'], 'created' => false];
            }
            $now = date('Y-m-d H:i:s');
            $id = (int)Db::name(self::RECORD_TABLE)->insertGetId([
                'authority_key' => $authorityKey,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'hotel_name_snapshot' => $hotelName,
                'business_date' => $businessDate,
                'metric_version' => $metricVersion,
                'metric_definition_json' => $this->json($metricDefinition),
                'metric_definition_digest' => $metricDefinitionDigest,
                'source_identities_json' => $this->json($sourceIdentities),
                'source_identity_digest' => $sourceIdentityDigest,
                'last_completed_stage' => '',
                'last_completed_stage_index' => -1,
                'next_required_stage' => 'identity_business_date_confirmed',
                'cycle_status' => 'active',
                'truth_summary' => '',
                'priority_issue' => '',
                'next_action' => '',
                'outcome_status' => 'pending',
                'experience_status' => 'not_reviewed',
                'state_version' => 0,
                'last_event_digest' => '',
                'projection_digest' => '',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('经营闭环权威记录保存失败');
            }

            $record = [
                'id' => $id,
                'authority_key' => $authorityKey,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'hotel_name_snapshot' => $hotelName,
                'business_date' => $businessDate,
                'metric_version' => $metricVersion,
                'metric_definition' => $metricDefinition,
                'metric_definition_digest' => $metricDefinitionDigest,
                'source_identities' => $sourceIdentities,
                'source_identity_digest' => $sourceIdentityDigest,
                'last_completed_stage' => '',
                'last_completed_stage_index' => -1,
                'next_required_stage' => 'identity_business_date_confirmed',
                'cycle_status' => 'active',
                'block_code' => '',
                'block_detail' => '',
                'truth_summary' => '',
                'priority_issue' => '',
                'next_action' => '',
                'next_owner' => [],
                'review_due_at' => null,
                'outcome_status' => 'pending',
                'experience_status' => 'not_reviewed',
                'state_version' => 0,
                'last_event_id' => 0,
                'last_event_digest' => '',
            ];
            $evidence = $this->validateEvidenceRefs($identityRefs, $record, 'identity_business_date_confirmed');
            $this->assertSourceIdentityRows($sourceIdentities, $evidence);
            $this->appendEventAndProject(
                $record,
                'identity_business_date_confirmed',
                'completed',
                $commandKey,
                'human',
                $actorId,
                $sourceModule,
                $payload,
                $evidence,
                date('Y-m-d H:i:s'),
                $identityCommandDigest
            );

            return ['id' => $id, 'created' => true];
        });

        $cycle = $this->readVerified((int)$write['id'], $tenantId, [$hotelId]);
        return [
            'cycle' => $cycle,
            'created' => (bool)$write['created'],
            'persistence_status' => 'readback_verified',
        ];
    }

    /** @return array<string,mixed> */
    public function transition(
        int $cycleId,
        int $tenantId,
        array $accessibleHotelIds,
        array $input,
        int $actorId
    ): array {
        $this->assertTablesReady();
        $accessibleHotelIds = $this->positiveIds($accessibleHotelIds);
        if ($cycleId <= 0 || $tenantId <= 0 || $actorId <= 0 || $accessibleHotelIds === []) {
            throw new InvalidArgumentException('经营闭环推进缺少有效记录、操作者或酒店权限');
        }
        $targetStage = $this->stage($input['target_stage'] ?? null);
        $stageStatus = strtolower(trim((string)($input['stage_status'] ?? 'completed')));
        if (!in_array($stageStatus, ['completed', 'blocked'], true)) {
            throw new InvalidArgumentException('经营闭环阶段状态只允许 completed 或 blocked');
        }
        if (!array_key_exists('expected_version', $input) || !is_numeric($input['expected_version'])) {
            throw new InvalidArgumentException('经营闭环推进必须携带精确 expected_version');
        }
        $expectedVersion = (int)$input['expected_version'];
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('经营闭环 expected_version 无效');
        }
        $sourceModule = $this->token($input['source_module'] ?? null, '来源模块', 80);
        $commandKey = $this->commandKey($input['command_key'] ?? null, '');
        $actorKind = strtolower(trim((string)($input['actor_kind'] ?? 'human')));
        if (!in_array($actorKind, ['human', 'system'], true)) {
            throw new InvalidArgumentException('经营闭环操作者类型无效');
        }
        $occurredAt = $this->dateTime($input['occurred_at'] ?? date('Y-m-d H:i:s'), '事件时间');
        $payload = $this->requiredObject($input['payload'] ?? null, '阶段载荷');
        $requestRefs = is_array($input['evidence_refs'] ?? null) ? $input['evidence_refs'] : [];

        $write = Db::transaction(function () use (
            $cycleId,
            $tenantId,
            $accessibleHotelIds,
            $actorId,
            $targetStage,
            $stageStatus,
            $expectedVersion,
            $sourceModule,
            $commandKey,
            $actorKind,
            $occurredAt,
            $payload,
            $requestRefs
        ): array {
            $row = Db::name(self::RECORD_TABLE)
                ->where('id', $cycleId)
                ->where('tenant_id', $tenantId)
                ->whereIn('hotel_id', $accessibleHotelIds)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('经营闭环不存在或不属于当前酒店范围');
            }
            $record = $this->normalizeRecord($row);
            $commandDigest = $this->commandDigest(
                $targetStage,
                $stageStatus,
                $actorKind,
                $actorId,
                $sourceModule,
                $payload,
                $requestRefs,
                (string)$record['business_date']
            );

            $existingCommand = Db::name(self::EVENT_TABLE)
                ->where('cycle_id', $cycleId)
                ->where('command_key', $commandKey)
                ->find();
            if (is_array($existingCommand)) {
                if ((string)$existingCommand['stage_key'] !== $targetStage
                    || (string)$existingCommand['stage_status'] !== $stageStatus
                    || !hash_equals((string)($existingCommand['command_digest'] ?? ''), $commandDigest)
                ) {
                    throw new InvalidArgumentException('经营闭环 command_key 已被另一份迁移载荷使用');
                }
                return ['id' => $cycleId, 'created' => false];
            }

            if ((int)$record['state_version'] !== $expectedVersion) {
                throw new RuntimeException(sprintf(
                    '经营闭环版本冲突：当前 revision=%d，请精确回读后重试',
                    (int)$record['state_version']
                ));
            }
            if ((string)$record['cycle_status'] === 'completed') {
                throw new InvalidArgumentException('已完成的经营闭环不能继续推进；请为下一业务日建立新记录');
            }
            if ((string)$record['next_required_stage'] !== $targetStage) {
                throw new InvalidArgumentException(sprintf(
                    '经营闭环禁止跳级：下一阶段必须是 %s',
                    (string)$record['next_required_stage']
                ));
            }

            $evidence = $this->validateEvidenceRefs($requestRefs, $record, $targetStage);
            $this->assertActorKindForStage($targetStage, $actorKind);
            if ($stageStatus === 'blocked') {
                $this->assertBlockedStage($targetStage, $payload, $evidence, $actorId);
            } else {
                $this->assertCompletedStage($targetStage, $payload, $evidence, $record, $actorId, $occurredAt);
            }

            $this->appendEventAndProject(
                $record,
                $targetStage,
                $stageStatus,
                $commandKey,
                $actorKind,
                $actorId,
                $sourceModule,
                $payload,
                $evidence,
                $occurredAt,
                $commandDigest
            );
            return ['id' => $cycleId, 'created' => true];
        });

        $cycle = $this->readVerified((int)$write['id'], $tenantId, $accessibleHotelIds);
        return [
            'cycle' => $cycle,
            'created' => (bool)$write['created'],
            'persistence_status' => 'readback_verified',
        ];
    }

    /** @return array<string,mixed> */
    public function readVerified(int $cycleId, int $tenantId, array $accessibleHotelIds): array
    {
        $this->assertTablesReady();
        $accessibleHotelIds = $this->positiveIds($accessibleHotelIds);
        if ($cycleId <= 0 || $tenantId <= 0 || $accessibleHotelIds === []) {
            throw new InvalidArgumentException('经营闭环回读缺少有效记录或酒店范围');
        }
        $query = Db::name(self::RECORD_TABLE)
            ->where('id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->whereIn('hotel_id', $accessibleHotelIds);
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('经营闭环不存在或不属于当前酒店范围');
        }
        $record = $this->normalizeRecord($row);
        $this->assertRootIdentityDigests($record);
        $this->assertProjectionDigest($record);

        $events = Db::name(self::EVENT_TABLE)
            ->where('cycle_id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', (int)$record['hotel_id'])
            ->order('sequence_no', 'asc')
            ->select()
            ->toArray();
        $normalizedEvents = $this->verifyEventChain($record, $events);
        $links = Db::name(self::EVIDENCE_TABLE)
            ->where('cycle_id', $cycleId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', (int)$record['hotel_id'])
            ->order('event_id', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $evidence = array_map([$this, 'normalizeEvidenceRow'], $links);
        $this->verifyEvidenceLinks($record, $normalizedEvents, $evidence);
        $evidenceByEvent = [];
        foreach ($evidence as $link) {
            $evidenceByEvent[(int)$link['event_id']][] = $link;
        }
        foreach ($normalizedEvents as &$event) {
            $event['evidence_refs'] = $evidenceByEvent[(int)$event['id']] ?? [];
        }
        unset($event);

        $record['schema_version'] = self::CONTRACT_VERSION;
        $record['kernel_id'] = 'cycle-' . (int)$record['id'];
        $record['revision'] = (int)$record['state_version'];
        $record['authoritative'] = true;
        $record['readback_verified'] = true;
        $record['events'] = $normalizedEvents;
        $record['evidence_refs'] = $evidence;
        $record['stages'] = $this->stageProjection($record, $normalizedEvents);
        $record['details'] = $this->stageDetails($normalizedEvents);
        $record['summary'] = $this->summary($record);
        return $record;
    }

    /** @return array<string,mixed> */
    public function currentForHotelDate(int $tenantId, int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate, '业务日期');
        if ($tenantId <= 0 || $hotelId <= 0) {
            return $this->missingSummary($hotelId, $businessDate, 'kernel_scope_invalid');
        }
        if (!$this->tablesReady()) {
            return $this->missingSummary($hotelId, $businessDate, 'kernel_schema_missing');
        }
        $query = Db::name(self::RECORD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate);
        $row = $query->find();
        if (!is_array($row)) {
            return $this->missingSummary($hotelId, $businessDate, 'kernel_record_not_started');
        }
        try {
            $cycle = $this->readVerified((int)$row['id'], $tenantId, [$hotelId]);
            return $cycle['summary'];
        } catch (Throwable $e) {
            $summary = $this->missingSummary($hotelId, $businessDate, 'kernel_readback_failed');
            $summary['readback_error'] = $this->safeErrorCode($e->getMessage());
            return $summary;
        }
    }

    /** @return array<string,mixed> */
    private function summary(array $record): array
    {
        $nextOwner = is_array($record['next_owner'] ?? null) ? $record['next_owner'] : [];
        $stages = is_array($record['stages'] ?? null) ? $record['stages'] : [];
        $details = is_array($record['details'] ?? null) ? $record['details'] : [];
        $decision = is_array($details['recommendation_human_decision'] ?? null)
            ? $details['recommendation_human_decision']
            : [];
        $execution = is_array($details['real_execution_receipt'] ?? null)
            ? $details['real_execution_receipt']
            : [];
        $outcome = is_array($details['comparable_outcome_readback'] ?? null)
            ? $details['comparable_outcome_readback']
            : [];
        $review = is_array($details['review_experience_promotion'] ?? null)
            ? $details['review_experience_promotion']
            : [];
        $nextAction = trim((string)($record['next_action'] ?? ''));
        if ($nextAction === '') {
            $nextAction = (string)$record['cycle_status'] === 'completed'
                ? '为下一业务日确认酒店、来源身份和指标口径'
                : '完成下一阶段：' . $this->stageLabel((string)$record['next_required_stage']);
        }
        $issue = trim((string)($record['priority_issue'] ?? ''));
        if ($issue === '' && (string)$record['cycle_status'] === 'blocked') {
            $issue = trim((string)($record['block_detail'] ?? '')) ?: '权威闭环当前被阻断';
        }

        return [
            'schema_version' => self::CONTRACT_VERSION,
            'kernel_id' => 'cycle-' . (int)$record['id'],
            'record_id' => (int)$record['id'],
            'revision' => (int)$record['state_version'],
            'authoritative' => true,
            'authoritative_state' => (string)$record['cycle_status'],
            'readback_verified' => true,
            'scope' => [
                'tenant_id' => (int)$record['tenant_id'],
                'system_hotel_id' => (int)$record['hotel_id'],
                'hotel_name' => (string)$record['hotel_name_snapshot'],
                'business_date' => (string)$record['business_date'],
                'metric_version' => (string)$record['metric_version'],
                'metric_definition_digest' => (string)$record['metric_definition_digest'],
                'source_identities' => $record['source_identities'],
                'source_identity_digest' => (string)$record['source_identity_digest'],
            ],
            'last_completed_stage' => (string)$record['last_completed_stage'],
            'next_required_stage' => (string)$record['next_required_stage'],
            'stages' => $stages,
            'what_is_true' => trim((string)($record['truth_summary'] ?? '')),
            'priority_issue' => [
                'code' => (string)($record['block_code'] ?? ''),
                'title' => $issue,
                'detail' => (string)($record['block_detail'] ?? ''),
            ],
            'next_action' => [
                'action_code' => (string)($record['cycle_status'] === 'blocked'
                    ? ($record['block_code'] ?: 'resolve_kernel_block')
                    : 'advance_' . (string)$record['next_required_stage']),
                'priority' => (string)$record['cycle_status'] === 'blocked' ? 'high' : 'medium',
                'status' => (string)$record['cycle_status'],
                'action' => $nextAction,
                'owner' => $nextOwner,
                'due_at' => $record['review_due_at'],
                'entry' => '/api/operating-loop/reconcile',
                'question_key' => (string)$record['next_required_stage'],
                'kernel_id' => 'cycle-' . (int)$record['id'],
                'revision' => (int)$record['state_version'],
            ],
            'yesterday_result' => [
                'status' => (string)$record['outcome_status'],
                'review_due_at' => $record['review_due_at'],
                'same_metric_version_required' => true,
                'result_summary' => trim((string)($outcome['result_summary'] ?? '')),
            ],
            'actors' => [
                'judged_by' => (int)($decision['judged_by'] ?? 0),
                'approved_by' => (int)($decision['approved_by'] ?? 0),
                'executed_by' => (int)($execution['executed_by'] ?? 0),
                'reviewed_by' => (int)($review['reviewed_by'] ?? 0),
            ],
            'decision' => [
                'status' => trim((string)($decision['decision_status'] ?? '')),
                'recommendation' => trim((string)($decision['recommendation'] ?? '')),
                'judgement' => trim((string)($decision['judgement'] ?? '')),
                'outcome_metric_definition_digest' => trim((string)($decision['outcome_metric_definition_digest'] ?? '')),
            ],
            'execution' => [
                'intent_id' => (int)($execution['intent_id'] ?? 0),
                'task_id' => (int)($execution['task_id'] ?? 0),
                'executed_action' => trim((string)($execution['executed_action'] ?? '')),
                'executed_at' => $execution['executed_at'] ?? null,
            ],
            'experience' => [
                'status' => (string)$record['experience_status'],
                'review_summary' => trim((string)($review['review_summary'] ?? '')),
            ],
            'evidence_ref_count' => count((array)($record['evidence_refs'] ?? [])),
            'source_policy' => 'hotel_operating_cycle_kernel_only',
        ];
    }

    /** @return array<string,mixed> */
    private function missingSummary(int $hotelId, string $businessDate, string $reason): array
    {
        $stages = [];
        foreach (self::STAGES as $key => $definition) {
            $stages[] = [
                'key' => $key,
                'label' => $definition['label'],
                'status' => 'missing',
                'blocking_gap_codes' => [$reason],
                'next_action' => $key === 'identity_business_date_confirmed' ? [
                    'action_code' => 'open_operating_cycle',
                    'priority' => 'high',
                    'status' => 'missing',
                    'action' => '确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录',
                    'entry' => '/api/operating-loop/reconcile',
                    'question_key' => $key,
                ] : null,
                'source_policy' => 'hotel_operating_cycle_kernel_only',
            ];
        }
        return [
            'schema_version' => self::CONTRACT_VERSION,
            'kernel_id' => null,
            'record_id' => 0,
            'revision' => 0,
            'authoritative' => true,
            'authoritative_state' => 'not_started',
            'readback_verified' => false,
            'scope' => ['system_hotel_id' => $hotelId, 'business_date' => $businessDate],
            'last_completed_stage' => '',
            'next_required_stage' => 'identity_business_date_confirmed',
            'stages' => $stages,
            'what_is_true' => '',
            'priority_issue' => [
                'code' => $reason,
                'title' => '该酒店该业务日尚无权威经营闭环记录',
                'detail' => '现有模块状态只保留为诊断证据，不能替代权威闭环状态。',
            ],
            'next_action' => [
                'action_code' => 'open_operating_cycle',
                'priority' => 'high',
                'status' => 'missing',
                'action' => '确认酒店、平台门店、业务日期和指标版本后建立权威闭环记录',
                'owner' => [],
                'due_at' => null,
                'entry' => '/api/operating-loop/reconcile',
                'question_key' => 'identity_business_date_confirmed',
                'kernel_id' => null,
                'revision' => 0,
            ],
            'yesterday_result' => ['status' => 'pending', 'review_due_at' => null, 'same_metric_version_required' => true],
            'experience' => ['status' => 'not_reviewed'],
            'evidence_ref_count' => 0,
            'source_policy' => 'hotel_operating_cycle_kernel_only',
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @param list<array<string,mixed>> $evidence
     */
    private function appendEventAndProject(
        array $record,
        string $stage,
        string $status,
        string $commandKey,
        string $actorKind,
        int $actorId,
        string $sourceModule,
        array $payload,
        array $evidence,
        string $occurredAt,
        string $commandDigest
    ): void {
        $sequence = (int)$record['state_version'] + 1;
        $fromVersion = (int)$record['state_version'];
        $fromStage = (string)($record['last_completed_stage'] ?? '');
        $previousDigest = (string)($record['last_event_digest'] ?? '');
        $evidenceDigest = $this->evidenceDigest($evidence);
        $eventPayload = [
            'cycle_id' => (int)$record['id'],
            'tenant_id' => (int)$record['tenant_id'],
            'hotel_id' => (int)$record['hotel_id'],
            'sequence_no' => $sequence,
            'command_key' => $commandKey,
            'command_digest' => $commandDigest,
            'from_stage' => $fromStage,
            'to_stage' => $stage,
            'from_version' => $fromVersion,
            'to_version' => $sequence,
            'stage_key' => $stage,
            'stage_status' => $status,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'source_module' => $sourceModule,
            'payload' => $payload,
            'evidence_digest' => $evidenceDigest,
            'previous_event_digest' => $previousDigest,
            'occurred_at' => $occurredAt,
        ];
        $eventDigest = $this->digest($eventPayload);
        $eventId = (int)Db::name(self::EVENT_TABLE)->insertGetId([
            'cycle_id' => (int)$record['id'],
            'tenant_id' => (int)$record['tenant_id'],
            'hotel_id' => (int)$record['hotel_id'],
            'sequence_no' => $sequence,
            'command_key' => $commandKey,
            'command_digest' => $commandDigest,
            'from_stage' => $fromStage,
            'to_stage' => $stage,
            'from_version' => $fromVersion,
            'to_version' => $sequence,
            'stage_key' => $stage,
            'stage_status' => $status,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'source_module' => $sourceModule,
            'payload_json' => $this->json($payload),
            'evidence_digest' => $evidenceDigest,
            'previous_event_digest' => $previousDigest,
            'event_digest' => $eventDigest,
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($eventId <= 0) {
            throw new RuntimeException('经营闭环事件保存失败');
        }
        foreach ($evidence as $ref) {
            Db::name(self::EVIDENCE_TABLE)->insert([
                'cycle_id' => (int)$record['id'],
                'event_id' => $eventId,
                'tenant_id' => (int)$record['tenant_id'],
                'hotel_id' => (int)$record['hotel_id'],
                'stage_key' => $stage,
                'evidence_role' => (string)$ref['role'],
                'source_kind' => (string)$ref['source_kind'],
                'fact_scope' => (string)$ref['fact_scope'],
                'metric_definition_digest' => (string)$ref['metric_definition_digest'],
                'platform' => (string)$ref['platform'],
                'business_date' => $ref['business_date'],
                'source_table' => (string)$ref['table'],
                'source_row_id' => (int)($ref['row_ids'][0] ?? 0),
                'source_row_ids_json' => $this->json($ref['row_ids']),
                'source_row_count' => count($ref['row_ids']),
                'source_rows_digest' => (string)$ref['rows_digest'],
                'verification_status' => (string)$ref['verification_status'],
                'readback_verified' => $ref['readback_verified'] ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $next = $record;
        $next['state_version'] = $sequence;
        $next['last_event_id'] = $eventId;
        $next['last_event_digest'] = $eventDigest;
        if ($status === 'blocked') {
            $next['cycle_status'] = 'blocked';
            $next['block_code'] = $this->token($payload['block_code'] ?? null, '阻断代码', 120);
            $next['block_detail'] = $this->requiredText($payload['block_detail'] ?? null, '阻断说明', 1000);
            $next['priority_issue'] = $this->optionalText(
                $payload['priority_issue'] ?? null,
                1000,
                $next['block_detail']
            );
            $next['next_action'] = $this->optionalText(
                $payload['next_action'] ?? null,
                1000,
                '解除阻断后继续：' . $this->stageLabel($stage)
            );
            $next['next_owner'] = $this->optionalObject($payload['next_owner'] ?? null);
        } else {
            $index = self::STAGES[$stage]['index'];
            $next['last_completed_stage'] = $stage;
            $next['last_completed_stage_index'] = $index;
            $next['next_required_stage'] = $index >= count(self::STAGES) - 1
                ? 'next_cycle_identity_confirmation'
                : array_keys(self::STAGES)[$index + 1];
            $next['cycle_status'] = $index >= count(self::STAGES) - 1 ? 'completed' : 'active';
            $next['block_code'] = '';
            $next['block_detail'] = '';
            $this->applyStageProjection($next, $stage, $payload);
        }
        $next['projection_digest'] = $this->projectionDigest($next);

        $updated = Db::name(self::RECORD_TABLE)
            ->where('id', (int)$record['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('state_version', (int)$record['state_version'])
            ->where('next_required_stage', $stage)
            ->update([
                'last_completed_stage' => (string)$next['last_completed_stage'],
                'last_completed_stage_index' => (int)$next['last_completed_stage_index'],
                'next_required_stage' => (string)$next['next_required_stage'],
                'cycle_status' => (string)$next['cycle_status'],
                'block_code' => (string)$next['block_code'],
                'block_detail' => (string)$next['block_detail'],
                'truth_summary' => (string)$next['truth_summary'],
                'priority_issue' => (string)$next['priority_issue'],
                'next_action' => (string)$next['next_action'],
                'next_owner_json' => $next['next_owner'] === [] ? null : $this->json($next['next_owner']),
                'review_due_at' => $next['review_due_at'],
                'outcome_status' => (string)$next['outcome_status'],
                'experience_status' => (string)$next['experience_status'],
                'state_version' => $sequence,
                'last_event_id' => $eventId,
                'last_event_digest' => $eventDigest,
                'projection_digest' => (string)$next['projection_digest'],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('经营闭环权威投影更新冲突');
        }
    }

    private function applyStageProjection(array &$next, string $stage, array $payload): void
    {
        if ($stage === 'identity_business_date_confirmed') {
            $next['truth_summary'] = $this->requiredText($payload['truth_summary'] ?? null, '身份确认摘要', 2000);
            $next['next_action'] = '完成可信采集并记录来源回执';
            $next['next_owner'] = [];
            return;
        }
        if ($stage === 'trusted_collection') {
            $next['truth_summary'] = $this->optionalText(
                $payload['truth_summary'] ?? null,
                2000,
                (string)$next['truth_summary']
            );
            $next['next_action'] = '正式保存采集结果并精确回读数据库行';
            return;
        }
        if ($stage === 'formal_save_exact_readback') {
            $next['next_action'] = '按冻结指标口径形成 PMS 与 OTA 分域经营事实';
            return;
        }
        if ($stage === 'operating_facts_established') {
            $next['truth_summary'] = $this->requiredText($payload['truth_summary'] ?? null, '经营事实摘要', 4000);
            $next['priority_issue'] = $this->requiredText($payload['priority_issue'] ?? null, '最重要问题', 1000);
            $next['next_action'] = '基于已成立事实提出建议并完成人工判断';
            return;
        }
        if ($stage === 'recommendation_human_decision') {
            $next['priority_issue'] = $this->requiredText($payload['priority_issue'] ?? null, '最重要问题', 1000);
            $next['next_action'] = $this->requiredText($payload['next_action'] ?? null, '下一动作', 1000);
            $next['next_owner'] = $this->requiredObject($payload['next_owner'] ?? null, '下一动作负责人');
            $next['review_due_at'] = $this->dateTime($payload['review_due_at'] ?? null, '可复盘时间');
            return;
        }
        if ($stage === 'real_execution_receipt') {
            $next['next_action'] = $this->optionalText(
                $payload['next_action'] ?? null,
                1000,
                '等待同口径结果回读'
            );
            return;
        }
        if ($stage === 'comparable_outcome_readback') {
            $next['outcome_status'] = $this->outcomeStatus($payload['outcome_status'] ?? null);
            $next['truth_summary'] = $this->requiredText($payload['result_summary'] ?? null, '结果摘要', 2000);
            $next['next_action'] = '复盘本次判断并决定是否形成可复用经验';
            return;
        }
        if ($stage === 'review_experience_promotion') {
            $next['experience_status'] = $this->experienceStatus($payload['experience_status'] ?? null);
            $next['next_action'] = '为下一业务日确认酒店、来源身份和指标口径';
            $next['next_owner'] = [];
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertCompletedStage(
        string $stage,
        array $payload,
        array $evidence,
        array $record,
        int $actorId,
        string $occurredAt
    ): void {
        if ($evidence === []) {
            throw new InvalidArgumentException('经营闭环阶段完成必须引用可精确回读的数据库行');
        }
        if ($stage === 'trusted_collection') {
            $this->assertRoles($evidence, ['collection_source']);
            $this->assertCollectionCoversSourceIdentities($evidence, $record);
            foreach ($this->refsByRole($evidence, 'collection_source') as $ref) {
                foreach ($ref['_rows'] as $row) {
                    $status = (string)$ref['table'] === 'hotel_collection_plan_runs'
                        ? strtolower(trim((string)($row['pms_status'] ?? '')))
                        : strtolower(trim((string)($row['status'] ?? $row['capture_status'] ?? '')));
                    if (!in_array($status, ['success', 'collected', 'verified', 'available'], true)) {
                        throw new InvalidArgumentException('可信采集证据尚未达到成功或已核验状态');
                    }
                }
            }
            return;
        }
        if ($stage === 'formal_save_exact_readback') {
            $this->assertRoles($evidence, ['collection_source', 'saved_rows']);
            $this->assertCollectionCoversSourceIdentities($evidence, $record);
            foreach ($this->refsByRole($evidence, 'saved_rows') as $ref) {
                if (($ref['readback_verified'] ?? false) !== true) {
                    throw new InvalidArgumentException('正式保存阶段存在未通过精确回读的数据库行');
                }
            }
            $this->assertCollectionReceiptRowIds($evidence);
            return;
        }
        if ($stage === 'operating_facts_established') {
            $this->assertRoles($evidence, ['pms_fact_rows', 'ota_fact_rows']);
            $factRefs = array_values(array_filter(
                $evidence,
                static fn(array $ref): bool => in_array((string)$ref['role'], ['pms_fact_rows', 'ota_fact_rows'], true)
            ));
            if ($factRefs === []) {
                throw new InvalidArgumentException('经营事实阶段至少需要 PMS 或 OTA 的严格事实行');
            }
            foreach ($factRefs as $ref) {
                if (($ref['readback_verified'] ?? false) !== true) {
                    throw new InvalidArgumentException('经营事实只能引用已精确回读的事实行');
                }
            }
            $digest = strtolower(trim((string)($payload['metric_definition_digest'] ?? '')));
            if (!hash_equals((string)$record['metric_definition_digest'], $digest)) {
                throw new InvalidArgumentException('经营事实使用的指标定义与闭环冻结口径不一致');
            }
            $this->requiredText($payload['truth_summary'] ?? null, '经营事实摘要', 4000);
            $this->requiredText($payload['priority_issue'] ?? null, '最重要问题', 1000);
            $scope = $this->requiredObject($payload['fact_scope'] ?? null, '事实范围');
            if (($scope['pms_plus_ota_revenue_addition_allowed'] ?? null) !== false) {
                throw new InvalidArgumentException('经营事实必须明确禁止 PMS 与 OTA 收入口径相加');
            }
            $this->assertFactsCoverSourceIdentities($factRefs, $record);
            return;
        }
        if ($stage === 'recommendation_human_decision') {
            $this->assertRoles($evidence, ['recommendation', 'human_decision']);
            $this->requiredText($payload['recommendation'] ?? null, '建议', 4000);
            $this->requiredText($payload['judgement'] ?? null, '人工判断', 4000);
            if ((int)($payload['judged_by'] ?? 0) !== $actorId) {
                throw new InvalidArgumentException('人工判断人必须与当前已认证操作者一致');
            }
            $decision = strtolower(trim((string)($payload['decision_status'] ?? '')));
            if (!in_array($decision, ['approved', 'approved_with_changes'], true)) {
                throw new InvalidArgumentException('拒绝或暂缓的判断必须记录为 blocked，不能推进到真实执行');
            }
            $approvedBy = (int)($payload['approved_by'] ?? 0);
            if ($approvedBy <= 0) {
                throw new InvalidArgumentException('经营闭环必须记录有效批准人');
            }
            $this->assertDecisionEvidence($evidence, $actorId, $approvedBy, $decision);
            $this->assertDecisionOutcomeMetric($evidence, $payload);
            $reviewDueAt = $this->dateTime($payload['review_due_at'] ?? null, '可复盘时间');
            if ($reviewDueAt <= $occurredAt) {
                throw new InvalidArgumentException('可复盘时间必须晚于人工判断时间');
            }
            $this->requiredObject($payload['next_owner'] ?? null, '下一动作负责人');
            return;
        }
        if ($stage === 'real_execution_receipt') {
            $this->assertRoles($evidence, ['execution_intent', 'execution_task', 'execution_receipt']);
            $this->assertExecutionChain($evidence, $record, $actorId, $payload);
            return;
        }
        if ($stage === 'comparable_outcome_readback') {
            $this->assertRoles($evidence, ['outcome_readback']);
            $this->assertOutcomeReadback($evidence, $record, $payload, $occurredAt, $actorId);
            return;
        }
        if ($stage === 'review_experience_promotion') {
            $this->assertRoles($evidence, ['operating_memory']);
            $experienceStatus = $this->experienceStatus($payload['experience_status'] ?? null);
            if ((int)($payload['reviewed_by'] ?? 0) !== $actorId) {
                throw new InvalidArgumentException('经验复盘人必须与当前已认证操作者一致');
            }
            if ($experienceStatus === 'promoted') {
                $this->assertRoles($evidence, ['reusable_experience', 'knowledge', 'promotion_event']);
            }
            $this->assertExperienceChain($evidence, $record, $experienceStatus, $actorId);
            return;
        }
        throw new InvalidArgumentException('身份确认只能通过 open 创建，不能作为普通推进阶段');
    }

    private function assertBlockedPayload(array $payload): void
    {
        $this->token($payload['block_code'] ?? null, '阻断代码', 120);
        $this->requiredText($payload['block_detail'] ?? null, '阻断说明', 1000);
        $this->optionalText($payload['next_action'] ?? null, 1000, '');
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertBlockedStage(string $stage, array $payload, array $evidence, int $actorId): void
    {
        $this->assertBlockedPayload($payload);
        if ($stage !== 'recommendation_human_decision') {
            return;
        }
        $this->assertRoles($evidence, ['recommendation', 'human_decision']);
        $this->requiredText($payload['recommendation'] ?? null, '建议', 4000);
        $this->requiredText($payload['judgement'] ?? null, '人工判断', 4000);
        if ((int)($payload['judged_by'] ?? 0) !== $actorId) {
            throw new InvalidArgumentException('人工判断人必须与当前已认证操作者一致');
        }
        $decision = strtolower(trim((string)($payload['decision_status'] ?? '')));
        if (!in_array($decision, ['rejected', 'deferred'], true)) {
            throw new InvalidArgumentException('判断阶段 blocked 只允许记录 rejected 或 deferred');
        }
        $this->assertDecisionEvidence($evidence, $actorId, 0, $decision);
    }

    private function assertActorKindForStage(string $stage, string $actorKind): void
    {
        $systemAllowed = [
            'trusted_collection',
            'formal_save_exact_readback',
            'operating_facts_established',
        ];
        if ($actorKind === 'system' && !in_array($stage, $systemAllowed, true)) {
            throw new InvalidArgumentException('该闭环阶段必须由已认证人工操作者确认');
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertDecisionEvidence(
        array $evidence,
        int $actorId,
        int $approvedBy,
        string $decision
    ): void
    {
        $recommendations = $this->refsByRole($evidence, 'recommendation');
        $decisions = $this->refsByRole($evidence, 'human_decision');
        if (count($recommendations) !== 1 || count($decisions) !== 1
            || (string)$recommendations[0]['table'] !== (string)$decisions[0]['table']
            || $recommendations[0]['row_ids'] !== $decisions[0]['row_ids']
            || count($decisions[0]['_rows']) !== 1
        ) {
            throw new InvalidArgumentException('建议与人工判断必须绑定同一条正式建议或执行意图记录');
        }
        $table = (string)$decisions[0]['table'];
        $row = $decisions[0]['_rows'][0];
        if ($table === 'operation_execution_intents') {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $rowApprovedBy = (int)($row['approved_by'] ?? 0);
            $recordedReviewer = $approvedBy > 0 ? $approvedBy : $actorId;
            $expectedStatus = in_array($decision, ['approved', 'approved_with_changes'], true)
                ? 'approved'
                : 'rejected';
            if ($status !== $expectedStatus
                || $rowApprovedBy <= 0
                || $rowApprovedBy !== $recordedReviewer
                || $recordedReviewer !== $actorId
                || trim((string)($row['approved_at'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException('执行意图的人工判断人、批准人或审核状态与判断结论不一致');
            }
            return;
        }
        if ($table !== 'price_suggestions') {
            throw new InvalidArgumentException('人工判断证据表不属于受控建议主线');
        }
        $recordedActor = 0;
        foreach (['applied_by', 'approved_by', 'reviewed_by', 'user_id', 'actor_id', 'updated_by', 'created_by'] as $field) {
            if ((int)($row[$field] ?? 0) > 0) {
                $recordedActor = (int)$row[$field];
                break;
            }
        }
        if ($recordedActor !== $actorId) {
            throw new InvalidArgumentException('人工判断证据行未绑定当前已认证操作者');
        }
        if (in_array($decision, ['approved', 'approved_with_changes'], true)
            && $approvedBy !== $actorId
        ) {
            throw new InvalidArgumentException('定价建议批准人必须与当前已认证操作者一致');
        }
        $expectedStatus = match ($decision) {
            'approved', 'approved_with_changes' => 2,
            'rejected' => 3,
            default => 1,
        };
        $expectedReviewAction = match ($decision) {
            'approved' => 'approve',
            'approved_with_changes' => 'approve_with_changes',
            'rejected' => 'reject',
            default => '',
        };
        $factors = $this->decode($row['factors'] ?? null);
        $review = is_array($factors['manual_review'] ?? null) ? $factors['manual_review'] : [];
        if ((int)($row['status'] ?? 0) !== $expectedStatus
            || strtolower(trim((string)($review['action'] ?? ''))) !== $expectedReviewAction
            || (int)($review['reviewed_by'] ?? 0) !== $actorId
            || ($review['auto_write_ota'] ?? null) !== false
            || ($review['ota_write'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('定价建议状态或人工审核版本与判断结论不一致');
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertDecisionOutcomeMetric(array $evidence, array $payload): void
    {
        $decisions = $this->refsByRole($evidence, 'human_decision');
        if (count($decisions) !== 1 || (string)($decisions[0]['table'] ?? '') !== 'operation_execution_intents') {
            return;
        }
        $row = $decisions[0]['_rows'][0] ?? [];
        $target = $this->decode($row['target_value_json'] ?? null);
        $definition = is_array($target['metric_definition'] ?? null) ? $target['metric_definition'] : [];
        $metricKey = strtolower(trim((string)($row['expected_metric'] ?? $target['expected_metric'] ?? '')));
        $declaredDigest = strtolower(trim((string)($target['metric_definition_digest'] ?? '')));
        $payloadDigest = strtolower(trim((string)($payload['outcome_metric_definition_digest'] ?? '')));
        if ($metricKey === ''
            || $definition === []
            || !$this->isDigest($declaredDigest)
            || !hash_equals($declaredDigest, $this->digest([
                'metric_key' => $metricKey,
                'definition' => $definition,
            ]))
            || !hash_equals($declaredDigest, $payloadDigest)
        ) {
            throw new InvalidArgumentException('执行意图没有冻结可用于同口径结果回读的指标定义');
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertCollectionReceiptRowIds(array $evidence): void
    {
        $receiptRefs = $this->refsByRole($evidence, 'collection_source');
        if ($receiptRefs === []) {
            return;
        }
        $savedByPlatform = [];
        foreach ($this->refsByRole($evidence, 'saved_rows') as $ref) {
            $savedByPlatform[(string)$ref['platform']][] = $ref;
        }
        foreach ($receiptRefs as $ref) {
            if ((string)$ref['table'] === 'hotel_collection_plan_runs') {
                if (count($ref['_rows']) !== 1) {
                    throw new InvalidArgumentException('PMS 正式保存阶段必须逐业务日引用单条采集 run');
                }
                $row = $ref['_rows'][0];
                $savedRefs = $savedByPlatform[(string)$ref['platform']] ?? [];
                if (count($savedRefs) !== 1) {
                    throw new InvalidArgumentException('PMS 采集 run 必须对应一组精确保存行');
                }
                $saved = $savedRefs[0];
                $savedIds = $saved['row_ids'];
                if (count($savedIds) !== 1
                    || (int)($row['pms_capture_id'] ?? 0) !== (int)$savedIds[0]
                    || (int)($row['pms_readback_verified'] ?? 0) !== 1
                    || !in_array(strtolower(trim((string)($row['pms_status'] ?? ''))), ['verified', 'success'], true)
                ) {
                    throw new InvalidArgumentException('PMS 采集 run 没有绑定同一条已精确回读的正式保存行');
                }
                continue;
            }
            if ((string)$ref['table'] !== 'hotel_collection_plan_run_sources' || count($ref['_rows']) !== 1) {
                throw new InvalidArgumentException('正式保存阶段的采集回执必须逐平台引用单条 run source');
            }
            $row = $ref['_rows'][0];
            $platform = $this->normalizePlatform((string)($row['platform'] ?? $ref['platform']));
            $savedRefs = $savedByPlatform[$platform] ?? [];
            if (count($savedRefs) !== 1) {
                throw new InvalidArgumentException('每个平台采集回执必须对应一组精确保存行');
            }
            $saved = $savedRefs[0];
            $ids = $saved['row_ids'];
            $savedCount = (int)($row['saved_row_count'] ?? 0);
            $readbackCount = (int)($row['readback_row_count'] ?? 0);
            if (strtolower(trim((string)($row['status'] ?? ''))) !== 'success'
                || (int)($row['readback_verified'] ?? 0) !== 1
                || $savedCount <= 0
                || $savedCount !== $readbackCount
                || $readbackCount !== count($ids)
            ) {
                throw new InvalidArgumentException('采集回执的保存数量、回读数量或成功状态不一致');
            }
            $expected = hash('sha256', (string)json_encode([
                'platform' => $platform,
                'data_source_id' => (int)($row['data_source_id'] ?? 0),
                'sync_task_id' => (int)($row['platform_sync_task_id'] ?? 0),
                'row_ids' => $ids,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $actual = strtolower(trim((string)($row['evidence_digest'] ?? '')));
            if (!$this->isDigest($actual) || !hash_equals($actual, $expected)) {
                throw new InvalidArgumentException('采集回执没有绑定同一组精确数据库行');
            }
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertCollectionCoversSourceIdentities(array $evidence, array $record): void
    {
        $declared = [];
        $plannedByPlatform = [];
        foreach ((array)($record['source_identities'] ?? []) as $identity) {
            if (is_array($identity)) {
                $platform = $this->normalizePlatform((string)($identity['platform'] ?? ''));
                if ($platform !== '') {
                    $declared[] = $platform;
                    if ((int)($identity['collection_plan_id'] ?? 0) > 0) {
                        $plannedByPlatform[$platform] = $identity;
                    }
                }
            }
        }
        $receipted = [];
        foreach ($this->refsByRole($evidence, 'collection_source') as $ref) {
            foreach ($ref['_rows'] as $row) {
                $platform = $this->normalizePlatform((string)($row['platform'] ?? $row['pms_provider'] ?? ''));
                if ($platform !== '') {
                    $receipted[] = $platform;
                    $planned = $plannedByPlatform[$platform] ?? null;
                    if (is_array($planned)) {
                        $run = (string)($ref['table'] ?? '') === 'hotel_collection_plan_runs'
                            ? $row
                            : Db::name('hotel_collection_plan_runs')
                                ->where('id', (int)($row['run_id'] ?? 0))
                                ->find();
                        if (!is_array($run)
                            || (int)($run['plan_id'] ?? 0) !== (int)$planned['collection_plan_id']
                            || (int)($run['plan_version'] ?? 0) !== (int)$planned['collection_plan_version']
                            || !hash_equals(
                                (string)$planned['collection_plan_hash'],
                                strtolower(trim((string)($run['plan_hash'] ?? '')))
                            )
                        ) {
                            throw new InvalidArgumentException('采集回执未绑定闭环冻结的同一生效采集计划版本');
                        }
                        if ((string)($ref['table'] ?? '') === 'hotel_collection_plan_run_sources'
                            && (int)($row['data_source_id'] ?? 0) !== (int)($planned['data_source_id'] ?? 0)
                        ) {
                            throw new InvalidArgumentException('OTA 采集回执未绑定闭环冻结的指定来源');
                        }
                    }
                }
            }
        }
        $declared = array_values(array_unique($declared));
        $receipted = array_values(array_unique($receipted));
        sort($declared);
        sort($receipted);
        if ($declared === [] || $declared !== $receipted) {
            throw new InvalidArgumentException('采集回执没有逐一覆盖闭环冻结的全部来源身份');
        }
    }

    /** @param list<array<string,mixed>> $factRefs */
    private function assertFactsCoverSourceIdentities(array $factRefs, array $record): void
    {
        $declared = [];
        foreach ((array)($record['source_identities'] ?? []) as $identity) {
            if (!is_array($identity)) {
                continue;
            }
            $platform = $this->normalizePlatform((string)($identity['platform'] ?? ''));
            if ($platform !== '') {
                $declared[] = (string)($identity['source_kind'] ?? '') . ':' . $platform;
            }
        }
        $established = [];
        foreach ($factRefs as $ref) {
            $role = (string)($ref['role'] ?? '');
            $kind = $role === 'pms_fact_rows' ? 'pms' : ($role === 'ota_fact_rows' ? 'ota' : '');
            $platform = $this->normalizePlatform((string)($ref['platform'] ?? ''));
            if ($kind !== '' && $platform !== '') {
                $established[] = $kind . ':' . $platform;
            }
        }
        $declared = array_values(array_unique($declared));
        $established = array_values(array_unique($established));
        sort($declared);
        sort($established);
        if ($declared === [] || $declared !== $established) {
            throw new InvalidArgumentException('经营事实没有逐一覆盖生效采集计划冻结的 PMS 与全部 OTA 来源');
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertExecutionChain(array $evidence, array $record, int $actorId, array $payload): void
    {
        $intentRefs = $this->refsByRole($evidence, 'execution_intent');
        $taskRefs = $this->refsByRole($evidence, 'execution_task');
        $receiptRefs = $this->refsByRole($evidence, 'execution_receipt');
        if (count($intentRefs) !== 1 || count($taskRefs) !== 1 || count($receiptRefs) < 1) {
            throw new InvalidArgumentException('真实执行必须绑定一条批准意图、一条执行任务和至少一条回执');
        }
        $intent = $intentRefs[0]['_rows'][0] ?? [];
        $task = $taskRefs[0]['_rows'][0] ?? [];
        if ((string)$intentRefs[0]['table'] !== 'operation_execution_intents'
            || (string)$taskRefs[0]['table'] !== 'operation_execution_tasks'
            || strtolower(trim((string)($intent['status'] ?? ''))) !== 'approved'
            || strtolower(trim((string)($task['status'] ?? ''))) !== 'executed'
            || (int)($task['intent_id'] ?? 0) !== (int)($intent['id'] ?? 0)
        ) {
            throw new InvalidArgumentException('执行意图与任务没有形成已批准、已执行的同一链路');
        }
        $intentPlatform = $this->normalizePlatform((string)($intent['platform'] ?? ''));
        foreach (array_merge($intentRefs, $taskRefs, $receiptRefs) as $ref) {
            if ($intentPlatform === '' || (string)$ref['platform'] !== $intentPlatform) {
                throw new InvalidArgumentException('执行意图、任务和回执的平台范围不一致');
            }
        }
        $decisionEvent = Db::name(self::EVENT_TABLE)
            ->where('cycle_id', (int)$record['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('stage_key', 'recommendation_human_decision')
            ->where('stage_status', 'completed')
            ->order('sequence_no', 'desc')
            ->find();
        $decisionPayload = is_array($decisionEvent)
            ? $this->decode($decisionEvent['payload_json'] ?? null)
            : [];
        if (!is_array($decisionEvent)
            || (int)($intent['approved_by'] ?? 0) <= 0
            || (int)($intent['approved_by'] ?? 0) !== (int)($decisionPayload['approved_by'] ?? 0)
        ) {
            throw new InvalidArgumentException('执行意图未绑定本闭环的人工批准事件');
        }
        $recommendationLinks = Db::name(self::EVIDENCE_TABLE)
            ->where('event_id', (int)$decisionEvent['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('evidence_role', 'recommendation')
            ->select()
            ->toArray();
        $recommendationIds = [];
        $recommendationTables = [];
        foreach ($recommendationLinks as $link) {
            $recommendationIds = array_merge(
                $recommendationIds,
                $this->positiveIds($this->decode($link['source_row_ids_json'] ?? null))
            );
            $recommendationTables[] = (string)($link['source_table'] ?? '');
        }
        $recommendationTables = array_values(array_unique($recommendationTables));
        $recommendationMatches = in_array('operation_execution_intents', $recommendationTables, true)
            && in_array((int)($intent['id'] ?? 0), $recommendationIds, true);
        if (!$recommendationMatches) {
            $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
            $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
            foreach ($recommendationTables as $table) {
                $aliases = match ($table) {
                    'price_suggestions' => ['price_suggestion', 'price_suggestions', 'revenue_ai'],
                    'hotel_operating_questions' => ['hotel_operating_question', 'hotel_operating_questions', 'operating_question'],
                    'operation_logs' => ['operation_log', 'operation_logs'],
                    default => [],
                };
                if (in_array($sourceModule, $aliases, true)
                    && in_array($sourceRecordId, $recommendationIds, true)
                ) {
                    $recommendationMatches = true;
                    break;
                }
            }
        }
        if (!$recommendationMatches) {
            throw new InvalidArgumentException('执行意图没有引用本闭环获批的建议记录');
        }
        foreach ($receiptRefs as $receiptRef) {
            if ((string)$receiptRef['table'] !== 'operation_execution_evidence') {
                throw new InvalidArgumentException('执行回执必须来自 operation_execution_evidence');
            }
            foreach ($receiptRef['_rows'] as $receipt) {
                if ((int)($receipt['task_id'] ?? 0) !== (int)($task['id'] ?? 0)
                    || !$this->executionEvidenceMeaningful($receipt, (int)($task['operator_id'] ?? 0))
                ) {
                    throw new InvalidArgumentException('执行回执不属于当前执行任务');
                }
            }
        }
        if ((int)($payload['executed_by'] ?? 0) !== (int)($task['operator_id'] ?? 0)
            || (int)($payload['executed_by'] ?? 0) !== $actorId
            || (int)($payload['executed_by'] ?? 0) <= 0
        ) {
            throw new InvalidArgumentException('执行人必须与当前操作者及已保存任务 operator_id 一致');
        }
        if ((int)($payload['intent_id'] ?? 0) !== (int)($intent['id'] ?? 0)
            || (int)($payload['task_id'] ?? 0) !== (int)($task['id'] ?? 0)
            || trim((string)($payload['action_type'] ?? '')) !== trim((string)($intent['action_type'] ?? ''))
            || trim((string)($payload['object_type'] ?? '')) !== trim((string)($intent['object_type'] ?? ''))
        ) {
            throw new InvalidArgumentException('执行内容未绑定已批准意图与已保存任务');
        }
        $intentTarget = $this->decode($intent['target_value_json'] ?? null);
        $taskTarget = $this->decode($task['target_value_json'] ?? null);
        $targetDigest = $this->digest($intentTarget);
        if ($intentTarget === []
            || $taskTarget !== $intentTarget
            || !hash_equals($targetDigest, strtolower(trim((string)($payload['target_value_digest'] ?? ''))))
        ) {
            throw new InvalidArgumentException('实际执行目标与人工批准目标不一致');
        }
        $this->requiredText($payload['executed_action'] ?? null, '实际执行动作', 2000);
        $executedAt = $this->dateTime($payload['executed_at'] ?? null, '执行时间');
        if ($executedAt !== (string)($task['executed_at'] ?? '')) {
            throw new InvalidArgumentException('执行时间必须与任务回读完全一致');
        }
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertOutcomeReadback(
        array $evidence,
        array $record,
        array $payload,
        string $occurredAt,
        int $actorId
    ): void {
        $refs = $this->refsByRole($evidence, 'outcome_readback');
        if (count($refs) !== 1
            || (string)$refs[0]['table'] !== 'operation_effect_reviews'
            || count($refs[0]['_rows']) !== 1
            || ($refs[0]['readback_verified'] ?? false) !== true
        ) {
            throw new InvalidArgumentException('同口径结果必须引用一条通过严格保存回读的 operation_effect_reviews 行');
        }
        $row = $refs[0]['_rows'][0];
        if ((int)($payload['reviewed_by'] ?? 0) !== (int)($row['reviewed_by'] ?? 0)
            || (int)($payload['reviewed_by'] ?? 0) <= 0
            || (int)($payload['reviewed_by'] ?? 0) !== $actorId
        ) {
            throw new InvalidArgumentException('效果回读复盘人必须与正式回读行及当前已认证操作者一致');
        }
        if ((int)($row['causality_claimed'] ?? 1) !== 0
            || !$this->isDigest((string)($row['content_digest'] ?? ''))
        ) {
            throw new InvalidArgumentException('效果回读不是不可变、非因果声明的已核验记录');
        }
        $executionRefs = Db::name(self::EVIDENCE_TABLE)
            ->where('cycle_id', (int)$record['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('stage_key', 'real_execution_receipt')
            ->whereIn('evidence_role', ['execution_intent', 'execution_task'])
            ->select()
            ->toArray();
        $intentIds = [];
        $taskIds = [];
        foreach ($executionRefs as $executionRef) {
            $ids = $this->positiveIds($this->decode($executionRef['source_row_ids_json'] ?? null));
            if ((string)($executionRef['evidence_role'] ?? '') === 'execution_intent'
                && (string)($executionRef['source_table'] ?? '') === 'operation_execution_intents'
            ) {
                $intentIds = array_merge($intentIds, $ids);
            }
            if ((string)($executionRef['evidence_role'] ?? '') === 'execution_task'
                && (string)($executionRef['source_table'] ?? '') === 'operation_execution_tasks'
            ) {
                $taskIds = array_merge($taskIds, $ids);
            }
        }
        if (!in_array((int)($row['intent_id'] ?? 0), $intentIds, true)
            || !in_array((int)($row['task_id'] ?? 0), $taskIds, true)
        ) {
            throw new InvalidArgumentException('效果回读没有绑定本闭环已回执的执行意图与任务');
        }
        $reviewDueAt = (string)($record['review_due_at'] ?? '');
        $reviewedAt = $this->dateTime($row['reviewed_at'] ?? null, '效果回读时间');
        if ($reviewDueAt === '' || $reviewedAt < $reviewDueAt || $occurredAt < $reviewedAt) {
            throw new InvalidArgumentException('同口径结果回读尚未到可复盘时间');
        }
        $baselineDate = $this->date($row['baseline_business_date'] ?? null, '效果基准业务日期');
        $reviewDate = $this->date($row['review_business_date'] ?? null, '效果复盘业务日期');
        if ($baselineDate !== (string)$record['business_date'] || $reviewDate <= $baselineDate) {
            throw new InvalidArgumentException('效果回读的基准与复盘业务日期无效');
        }
        $expectedOutcome = match (strtolower(trim((string)($row['outcome_status'] ?? '')))) {
            'met', 'near' => 'supported',
            'missed', 'adverse' => 'refuted',
            default => 'indeterminate',
        };
        if ($this->outcomeStatus($payload['outcome_status'] ?? null) !== $expectedOutcome) {
            throw new InvalidArgumentException('结果支持/反驳判断与已保存效果回读不一致');
        }
        if (trim((string)($payload['result_summary'] ?? '')) !== trim((string)($row['result_summary'] ?? ''))) {
            throw new InvalidArgumentException('结果摘要必须与效果回读行完全一致');
        }
        $metricDigest = strtolower(trim((string)($payload['metric_definition_digest'] ?? '')));
        $expectedMetricDigest = $this->expectedOutcomeMetricDigest($record);
        if (!hash_equals($expectedMetricDigest, $metricDigest)
            || !hash_equals($expectedMetricDigest, strtolower(trim((string)($row['metric_definition_digest'] ?? ''))))
        ) {
            throw new InvalidArgumentException('结果回读未使用人工判断阶段冻结的同口径指标版本');
        }
    }

    private function executionEvidenceMeaningful(array $row, int $operatorId): bool
    {
        return OperationManagementService::isMeaningfulExecutionReceipt($row, $operatorId);
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasMeaningfulValue($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return $value !== null && $value !== false;
    }

    private function expectedOutcomeMetricDigest(array $record): string
    {
        $event = Db::name(self::EVENT_TABLE)
            ->where('cycle_id', (int)$record['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('stage_key', 'recommendation_human_decision')
            ->where('stage_status', 'completed')
            ->order('sequence_no', 'desc')
            ->find();
        $payload = is_array($event) ? $this->decode($event['payload_json'] ?? null) : [];
        $decisionDigest = strtolower(trim((string)($payload['outcome_metric_definition_digest'] ?? '')));
        return $this->isDigest($decisionDigest)
            ? $decisionDigest
            : (string)$record['metric_definition_digest'];
    }

    /** @param list<array<string,mixed>> $evidence */
    private function assertExperienceChain(
        array $evidence,
        array $record,
        string $experienceStatus,
        int $actorId
    ): void
    {
        $memoryRefs = $this->refsByRole($evidence, 'operating_memory');
        if (count($memoryRefs) !== 1
            || (string)$memoryRefs[0]['table'] !== 'hotel_operating_memories'
            || count($memoryRefs[0]['_rows']) !== 1
        ) {
            throw new InvalidArgumentException('经验复盘必须引用一条正式经营记忆');
        }
        $memory = $memoryRefs[0]['_rows'][0];
        if (strtolower(trim((string)($memory['quality_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($memory['lifecycle_status'] ?? ''))) !== 'active'
            || !$this->isDigest((string)($memory['content_digest'] ?? ''))
            || (int)($memory['recorded_by'] ?? 0) !== $actorId
        ) {
            throw new InvalidArgumentException('经验复盘引用的经营记忆尚未通过正式核验');
        }

        $outcomeLinks = Db::name(self::EVIDENCE_TABLE)
            ->where('cycle_id', (int)$record['id'])
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('stage_key', 'comparable_outcome_readback')
            ->where('evidence_role', 'outcome_readback')
            ->where('source_table', 'operation_effect_reviews')
            ->select()
            ->toArray();
        $reviewIds = [];
        foreach ($outcomeLinks as $link) {
            $reviewIds = array_merge(
                $reviewIds,
                $this->positiveIds($this->decode($link['source_row_ids_json'] ?? null))
            );
        }
        $reviewIds = array_values(array_unique($reviewIds));
        $taskIds = $reviewIds === [] ? [] : array_map(
            'intval',
            Db::name('operation_effect_reviews')->whereIn('id', $reviewIds)->column('task_id')
        );
        $sourceType = strtolower(trim((string)($memory['source_record_type'] ?? '')));
        $sourceId = (int)($memory['source_record_id'] ?? 0);
        $linked = ($sourceType === 'operation_effect_review' && in_array($sourceId, $reviewIds, true))
            || ($sourceType === 'operation_execution_task' && in_array($sourceId, $taskIds, true));
        if (!$linked) {
            throw new InvalidArgumentException('经营记忆没有绑定本闭环的正式效果回读');
        }
        if ($experienceStatus !== 'promoted') {
            return;
        }

        $sopRefs = $this->refsByRole($evidence, 'reusable_experience');
        $knowledgeRefs = $this->refsByRole($evidence, 'knowledge');
        $promotionRefs = $this->refsByRole($evidence, 'promotion_event');
        if (count($sopRefs) !== 1 || count($knowledgeRefs) !== 1 || count($promotionRefs) !== 1
            || count($sopRefs[0]['_rows']) !== 1
            || count($knowledgeRefs[0]['_rows']) !== 1
            || count($promotionRefs[0]['_rows']) !== 1
        ) {
            throw new InvalidArgumentException('经验晋级必须逐一引用 SOP、正式知识和批准事件');
        }
        $sop = $sopRefs[0]['_rows'][0];
        $knowledge = $knowledgeRefs[0]['_rows'][0];
        $promotion = $promotionRefs[0]['_rows'][0];
        if (strtolower(trim((string)($sop['validation_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($sop['lifecycle_status'] ?? ''))) !== 'active'
            || strtolower(trim((string)($knowledge['status'] ?? ''))) !== 'done'
            || (int)($knowledge['current_chunk_id'] ?? 0) <= 0
            || strtolower(trim((string)($promotion['event_type'] ?? ''))) !== 'approved'
            || strtolower(trim((string)($promotion['to_status'] ?? ''))) !== 'approved'
        ) {
            throw new InvalidArgumentException('经验晋级引用的 SOP、知识投影或批准事件尚未正式生效');
        }
        $candidate = Db::name('knowledge_candidates')
            ->where('id', (int)($promotion['candidate_id'] ?? 0))
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->find();
        if (!is_array($candidate)
            || strtolower(trim((string)($candidate['workflow_status'] ?? ''))) !== 'approved'
            || (int)($candidate['promoted_sop_version_id'] ?? 0) !== (int)($sop['id'] ?? 0)
            || (int)($candidate['promoted_knowledge_unit_id'] ?? 0) !== (int)($knowledge['unit_id'] ?? 0)
        ) {
            throw new InvalidArgumentException('经验晋级事件没有绑定同一份已批准知识候选投影');
        }
    }

    /** @param list<array<string,mixed>> $evidence @param list<string> $roles */
    private function assertRoles(array $evidence, array $roles): void
    {
        $actual = array_values(array_unique(array_map(static fn(array $ref): string => (string)$ref['role'], $evidence)));
        foreach ($roles as $role) {
            if (!in_array($role, $actual, true)) {
                throw new InvalidArgumentException('经营闭环阶段缺少证据角色：' . $role);
            }
        }
    }

    /** @param list<array<string,mixed>> $evidence @return list<array<string,mixed>> */
    private function refsByRole(array $evidence, string $role): array
    {
        return array_values(array_filter(
            $evidence,
            static fn(array $ref): bool => (string)$ref['role'] === $role
        ));
    }

    /** @return list<array<string,mixed>> */
    private function validateEvidenceRefs(array $refs, array $record, string $stage): array
    {
        $normalized = [];
        foreach ($refs as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('经营闭环证据引用格式无效');
            }
            $role = $this->token($raw['role'] ?? null, '证据角色', 48);
            $sourceKind = $this->token($raw['source_kind'] ?? null, '证据来源类型', 24);
            $table = strtolower($this->token($raw['table'] ?? null, '证据表', 80));
            if (!isset(self::DIRECT_SOURCE_SCOPES[$table]) && !in_array($table, self::SPECIAL_SOURCE_TABLES, true)) {
                throw new InvalidArgumentException('经营闭环不接受未登记的证据表：' . $table);
            }
            $contract = self::STAGE_EVIDENCE_CONTRACTS[$stage][$role] ?? null;
            if (!is_array($contract)
                || !in_array($table, $contract['tables'], true)
                || !in_array(strtolower($sourceKind), $contract['kinds'], true)
            ) {
                throw new InvalidArgumentException('证据角色、来源类型或业务表不属于当前闭环阶段合同');
            }
            $rowIds = $this->positiveIds($raw['row_ids'] ?? [$raw['row_id'] ?? 0]);
            if ($rowIds === [] || count($rowIds) > 5000) {
                throw new InvalidArgumentException('经营闭环证据必须引用 1-5000 个精确数据库行ID');
            }
            $platform = $this->normalizePlatform((string)($raw['platform'] ?? ''));
            $businessDate = trim((string)($raw['business_date'] ?? ''));
            if ($businessDate !== '') {
                $businessDate = $this->date($businessDate, '证据业务日期');
            } elseif (self::STAGES[$stage]['index'] <= 3) {
                $businessDate = (string)$record['business_date'];
            }
            $sourceScope = self::DIRECT_SOURCE_SCOPES[$table] ?? [];
            $primaryKey = (string)($sourceScope['pk'] ?? 'id');
            $rows = Db::name($table)->whereIn($primaryKey, $rowIds)->order($primaryKey, 'asc')->select()->toArray();
            if (count($rows) !== count($rowIds)) {
                throw new RuntimeException('经营闭环证据行不存在或回读数量不一致：' . $table);
            }
            $this->assertEvidenceScope($table, $rows, $record, $platform, $businessDate, $stage);
            $readbackVerified = $this->rowsReadbackVerified($table, $rows);
            $factScope = $this->factScope($sourceKind, $table);
            $verificationStatus = $this->evidenceVerificationStatus($rows, $readbackVerified);
            $normalized[] = [
                'tenant_id' => (int)$record['tenant_id'],
                'hotel_id' => (int)$record['hotel_id'],
                'role' => $role,
                'source_kind' => $sourceKind,
                'fact_scope' => $factScope,
                'metric_definition_digest' => (string)$record['metric_definition_digest'],
                'platform' => $platform,
                'business_date' => $businessDate !== '' ? $businessDate : null,
                'table' => $table,
                'row_ids' => $rowIds,
                'rows_digest' => $this->digest([
                    'tenant_id' => (int)$record['tenant_id'],
                    'hotel_id' => (int)$record['hotel_id'],
                    'business_date' => $businessDate !== '' ? $businessDate : null,
                    'platform' => $platform,
                    'fact_scope' => $factScope,
                    'metric_definition_digest' => (string)$record['metric_definition_digest'],
                    'source_table' => $table,
                    'source_row_ids' => $rowIds,
                    'source_rows' => $rows,
                ]),
                'verification_status' => $verificationStatus,
                'readback_verified' => $readbackVerified,
                '_rows' => $rows,
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => [
            $left['role'], $left['table'], $left['platform'], $left['row_ids'][0] ?? 0,
        ] <=> [
            $right['role'], $right['table'], $right['platform'], $right['row_ids'][0] ?? 0,
        ]);
        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows */
    private function assertEvidenceScope(
        string $table,
        array $rows,
        array $record,
        string $platform,
        string $businessDate,
        string $stage
    ): void {
        if ($table === 'hotel_collection_plan_runs') {
            foreach ($rows as $row) {
                $rowPlatform = $this->normalizePlatform((string)($row['pms_provider'] ?? ''));
                if ((int)($row['tenant_id'] ?? 0) !== (int)$record['tenant_id']
                    || (int)($row['system_hotel_id'] ?? 0) !== (int)$record['hotel_id']
                    || (string)($row['business_date'] ?? '') !== (string)$record['business_date']
                    || ($platform !== '' && $rowPlatform !== $platform)
                ) {
                    throw new InvalidArgumentException('PMS 采集 run 与闭环酒店、来源或业务日期不一致');
                }
            }
            return;
        }
        if ($table === 'hotel_collection_plan_run_sources') {
            foreach ($rows as $row) {
                $parent = Db::name('hotel_collection_plan_runs')->where('id', (int)($row['run_id'] ?? 0))->find();
                if (!is_array($parent)
                    || (int)($parent['tenant_id'] ?? 0) !== (int)$record['tenant_id']
                    || (int)($parent['system_hotel_id'] ?? 0) !== (int)$record['hotel_id']
                    || (string)($parent['business_date'] ?? '') !== (string)$record['business_date']
                    || ($platform !== '' && $this->normalizePlatform((string)($row['platform'] ?? '')) !== $platform)
                ) {
                    throw new InvalidArgumentException('采集来源回执与闭环酒店、平台或业务日期不一致');
                }
            }
            return;
        }
        if ($table === 'operation_execution_evidence') {
            foreach ($rows as $row) {
                $task = Db::name('operation_execution_tasks')
                    ->where('id', (int)($row['task_id'] ?? 0))
                    ->where('tenant_id', (int)$record['tenant_id'])
                    ->where('hotel_id', (int)$record['hotel_id'])
                    ->find();
                if ((int)($row['tenant_id'] ?? 0) !== (int)$record['tenant_id']
                    || !is_array($task)
                    || (int)($task['tenant_id'] ?? 0) !== (int)$record['tenant_id']
                    || (int)($task['hotel_id'] ?? 0) !== (int)$record['hotel_id']
                ) {
                    throw new InvalidArgumentException('执行证据与闭环租户或酒店不一致');
                }
            }
            return;
        }

        $scope = self::DIRECT_SOURCE_SCOPES[$table];
        foreach ($rows as $row) {
            if (isset($scope['tenant'])
                && (!array_key_exists($scope['tenant'], $row)
                    || (int)$row[$scope['tenant']] !== (int)$record['tenant_id'])
            ) {
                throw new InvalidArgumentException('证据行租户范围不一致：' . $table);
            }
            if (isset($scope['hotel'])
                && (!array_key_exists($scope['hotel'], $row)
                    || (int)$row[$scope['hotel']] !== (int)$record['hotel_id'])
            ) {
                throw new InvalidArgumentException('证据行酒店范围不一致：' . $table);
            }
            if (isset($scope['date'])) {
                $rowDate = $table === 'operation_execution_intents'
                    ? $this->intentBaselineDate($row)
                    : trim((string)($row[$scope['date']] ?? ''));
                $expectedDate = $businessDate !== '' ? $businessDate : (string)$record['business_date'];
                if ($table === 'operation_effect_reviews') {
                    $expectedDate = (string)$record['business_date'];
                }
                if ($rowDate === '' || $rowDate !== $expectedDate) {
                    throw new InvalidArgumentException('证据行业务日期不一致：' . $table);
                }
            }
            if (isset($scope['platform']) && $platform !== '') {
                $rowPlatform = $this->normalizePlatform((string)($row[$scope['platform']] ?? ''));
                if ($rowPlatform !== $platform) {
                    throw new InvalidArgumentException('证据行平台范围不一致：' . $table);
                }
            }
            if ($table === 'online_daily_data') {
                $this->assertOnlineFactRowTrusted($row);
            }
            if ($table === 'meituan_cloud_pms_captures') {
                $this->assertPmsCaptureTrusted($row);
            }
            if ($table === 'dingdandao_operating_target_captures') {
                $this->assertDingdandaoCaptureTrusted($row);
            }
        }
    }

    private function assertOnlineFactRowTrusted(array $row): void
    {
        $historyStatus = strtolower(trim((string)($row['history_status'] ?? '')));
        $validation = strtolower(trim((string)($row['validation_status'] ?? '')));
        if ((int)($row['readback_verified'] ?? 0) !== 1
            || ($historyStatus !== '' && $historyStatus !== 'success')
            || ($historyStatus === '' && !in_array($validation, ['verified', 'normal'], true))
        ) {
            throw new InvalidArgumentException('OTA 事实行未达到来源校验与精确回读要求');
        }
    }

    private function assertPmsCaptureTrusted(array $row): void
    {
        if (!in_array(strtolower(trim((string)($row['quality_status'] ?? ''))), ['verified', 'available'], true)
            || strtolower(trim((string)($row['identity_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($row['date_status'] ?? ''))) !== 'verified'
            || !in_array(strtolower(trim((string)($row['readback_status'] ?? ''))), ['verified', 'readback_verified'], true)
        ) {
            throw new InvalidArgumentException('PMS 事实行未达到身份、日期、质量和回读要求');
        }
    }

    private function assertDingdandaoCaptureTrusted(array $row): void
    {
        if (strtolower(trim((string)($row['quality_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($row['identity_status'] ?? ''))) !== 'matched'
            || strtolower(trim((string)($row['capture_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($row['readback_status'] ?? ''))) !== 'readback_verified'
        ) {
            throw new InvalidArgumentException('订单来了 PMS 事实行未达到身份、质量和精确回读要求');
        }
    }

    /**
     * @param list<array<string,mixed>> $identities
     * @param list<array<string,mixed>> $evidence
     */
    private function assertSourceIdentityRows(array $identities, array $evidence): void
    {
        $identityRefs = $this->refsByRole($evidence, 'source_identity');
        foreach ($identities as $identity) {
            $matched = null;
            foreach ($identityRefs as $ref) {
                if ((string)$ref['table'] === (string)$identity['evidence_ref']['table']
                    && (int)($ref['row_ids'][0] ?? 0) === (int)$identity['evidence_ref']['row_id']
                    && (string)$ref['platform'] === (string)$identity['platform']
                ) {
                    $matched = $ref['_rows'][0] ?? null;
                    break;
                }
            }
            if (!is_array($matched)) {
                throw new InvalidArgumentException('来源身份没有绑定对应的精确回读行');
            }
            if ((int)($identity['data_source_id'] ?? 0) > 0
                && ((string)$identity['evidence_ref']['table'] !== 'platform_data_sources'
                    || (int)$identity['evidence_ref']['row_id'] !== (int)$identity['data_source_id'])
            ) {
                throw new InvalidArgumentException('OTA 来源身份没有绑定采集计划指定的 platform_data_sources 行');
            }
            $config = $this->decode($matched['config_json'] ?? null);
            $actualExternalId = '';
            foreach (['platform_hotel_id', 'provider_hotel_id', 'external_hotel_id', 'ota_hotel_id'] as $key) {
                $candidate = trim((string)($matched[$key] ?? $config[$key] ?? ''));
                if ($candidate !== '') {
                    $actualExternalId = $candidate;
                    break;
                }
            }
            if ($actualExternalId === ''
                || !hash_equals((string)$identity['external_hotel_id'], $actualExternalId)
            ) {
                throw new InvalidArgumentException('平台门店身份与来源绑定回读行不一致');
            }
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function rowsReadbackVerified(string $table, array $rows): bool
    {
        if ($rows === []) {
            return false;
        }
        if ($table === 'online_daily_data') {
            foreach ($rows as $row) {
                if ((int)($row['readback_verified'] ?? 0) !== 1) {
                    return false;
                }
            }
            return true;
        }
        if ($table === 'hotel_collection_plan_run_sources') {
            foreach ($rows as $row) {
                if ((int)($row['readback_verified'] ?? 0) !== 1) {
                    return false;
                }
            }
            return true;
        }
        if ($table === 'hotel_collection_plan_runs') {
            foreach ($rows as $row) {
                if ((int)($row['pms_readback_verified'] ?? 0) !== 1
                    || (int)($row['pms_capture_id'] ?? 0) <= 0
                    || !in_array(strtolower(trim((string)($row['pms_status'] ?? ''))), ['verified', 'success'], true)
                ) {
                    return false;
                }
            }
            return true;
        }
        if (in_array($table, ['meituan_cloud_pms_captures', 'dingdandao_operating_target_captures'], true)) {
            foreach ($rows as $row) {
                if (!in_array(strtolower(trim((string)($row['readback_status'] ?? ''))), ['verified', 'readback_verified'], true)) {
                    return false;
                }
            }
            return true;
        }
        if ($table === 'operation_effect_reviews') {
            foreach ($rows as $row) {
                if (!$this->effectReviewRowVerified($row)) {
                    return false;
                }
            }
            return true;
        }
        return true;
    }

    private function factScope(string $sourceKind, string $table): string
    {
        $sourceKind = strtolower(trim($sourceKind));
        if ($sourceKind === 'identity') {
            return 'identity';
        }
        if ($sourceKind === 'pms' || str_contains($table, '_pms_') || str_contains($table, 'dingdandao_')) {
            return 'whole_hotel_accommodation';
        }
        if ($sourceKind === 'ota' || $table === 'online_daily_data' || str_contains($table, 'ota_')) {
            return 'ota_channel';
        }
        return match ($sourceKind) {
            'identity' => 'identity',
            'decision', 'approval' => 'decision',
            'execution' => 'execution',
            'outcome' => 'outcome',
            'knowledge' => 'knowledge',
            default => 'supporting_evidence',
        };
    }

    private function intentBaselineDate(array $intent): string
    {
        $evidence = $this->decode($intent['evidence_json'] ?? null);
        $approvalTarget = is_array($evidence['approval_target'] ?? null)
            ? $evidence['approval_target']
            : [];
        foreach ([$approvalTarget['baseline_business_date'] ?? null, $intent['date_end'] ?? null, $intent['date_start'] ?? null] as $value) {
            $date = trim((string)$value);
            if ($date !== '') {
                try {
                    return $this->date($date, '执行意图基准业务日期');
                } catch (InvalidArgumentException) {
                }
            }
        }
        return '';
    }

    private function effectReviewRowVerified(array $row): bool
    {
        $metricKey = strtolower(trim((string)($row['metric_key'] ?? '')));
        $metricDefinition = $this->decode($row['metric_definition_json'] ?? null);
        $metricDigest = strtolower(trim((string)($row['metric_definition_digest'] ?? '')));
        $outcomeStatus = strtolower(trim((string)($row['outcome_status'] ?? '')));
        $resultStatus = strtolower(trim((string)($row['result_status'] ?? '')));
        $expectedResultStatus = match ($outcomeStatus) {
            'met' => 'success',
            'near' => 'near_success',
            'missed', 'adverse' => 'failed',
            default => '',
        };
        $outcome = $this->decode($row['outcome_json'] ?? null);
        $baselineRefs = $this->effectRefs($row['baseline_refs_json'] ?? null);
        $followupRefs = $this->effectRefs($row['followup_refs_json'] ?? null);
        $beforeValue = $this->effectDecimal($row['before_value'] ?? null);
        $afterValue = $this->effectDecimal($row['after_value'] ?? null);
        $targetValue = ($row['target_value'] ?? null) === null
            ? null
            : $this->effectDecimal($row['target_value']);
        $expectedDelta = ($row['expected_delta'] ?? null) === null
            ? null
            : $this->effectDecimal($row['expected_delta']);
        $approvalTargetDigest = strtolower(trim((string)($row['approval_target_digest'] ?? '')));
        if ($metricKey === ''
            || $metricDefinition === []
            || !$this->isDigest($metricDigest)
            || !hash_equals($metricDigest, $this->digest($metricDefinition))
            || $beforeValue === null
            || $afterValue === null
            || $baselineRefs === []
            || $followupRefs === []
            || (int)($row['source_readback_evidence_id'] ?? 0) <= 0
            || strtolower(trim((string)($row['expected_delta_status'] ?? ''))) !== 'manual_confirmed'
            || !in_array(strtolower(trim((string)($row['target_type'] ?? ''))), ['absolute', 'delta'], true)
            || $expectedResultStatus === ''
            || $resultStatus !== $expectedResultStatus
            || strtolower(trim((string)($outcome['status'] ?? ''))) !== $outcomeStatus
            || ($outcome['source_verified'] ?? false) !== true
            || ($outcome['outcome_verified'] ?? false) !== true
            || (int)($row['causality_claimed'] ?? 1) !== 0
            || !$this->isDigest($approvalTargetDigest)
            || !hash_equals($approvalTargetDigest, strtolower(trim((string)($outcome['approval_target_digest'] ?? ''))))
        ) {
            return false;
        }
        try {
            (new OperationEffectReviewService())->readVerified(
                (int)($row['id'] ?? 0),
                (int)($row['tenant_id'] ?? 0),
                (int)($row['hotel_id'] ?? 0),
                (int)($row['intent_id'] ?? 0),
                (int)($row['task_id'] ?? 0)
            );
        } catch (Throwable) {
            return false;
        }
        $targetType = strtolower(trim((string)$row['target_type']));
        if (($targetType === 'absolute' && $targetValue === null)
            || ($targetType === 'delta' && ($expectedDelta === null || (float)$expectedDelta <= 0.0))
        ) {
            return false;
        }

        $sourceEvidence = Db::name('operation_execution_evidence')
            ->where('id', (int)$row['source_readback_evidence_id'])
            ->where('tenant_id', (int)($row['tenant_id'] ?? 0))
            ->where('task_id', (int)($row['task_id'] ?? 0))
            ->find();
        if (!is_array($sourceEvidence)
            || !in_array(strtolower(trim((string)($sourceEvidence['evidence_type'] ?? ''))), [
                'source_verified_metric_readback',
                'ota_source_readback',
                'business_metric_readback',
            ], true)
        ) {
            return false;
        }
        $sourceBefore = $this->decode($sourceEvidence['before_json'] ?? null);
        $sourceAfter = $this->decode($sourceEvidence['after_json'] ?? null);
        $sourceContext = $this->decode($sourceEvidence['platform_response_json'] ?? null);
        if ($this->effectDecimal($sourceBefore[$metricKey] ?? null) !== $beforeValue
            || $this->effectDecimal($sourceAfter[$metricKey] ?? null) !== $afterValue
            || strtolower(trim((string)($sourceContext['verification_authority'] ?? ''))) !== 'system_readback'
            || ($sourceContext['database_written'] ?? false) !== true
            || ($sourceContext['readback_verified'] ?? false) !== true
            || (int)($sourceContext['readback_count'] ?? 0) <= 0
            || strtolower(trim((string)($sourceContext['validation_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($sourceContext['source_validation_status'] ?? ''))) !== 'source_verified'
            || (int)($sourceContext['system_hotel_id'] ?? 0) !== (int)($row['hotel_id'] ?? 0)
            || strtolower(trim((string)($sourceContext['platform'] ?? ''))) !== strtolower(trim((string)($row['platform'] ?? '')))
            || strtolower(trim((string)($sourceContext['metric_key'] ?? ''))) !== $metricKey
            || trim((string)($sourceContext['baseline_date'] ?? '')) !== trim((string)($row['baseline_business_date'] ?? ''))
            || trim((string)($sourceContext['review_date'] ?? '')) !== trim((string)($row['review_business_date'] ?? ''))
        ) {
            return false;
        }

        $digestPayload = [
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'intent_id' => (int)($row['intent_id'] ?? 0),
            'task_id' => (int)($row['task_id'] ?? 0),
            'platform' => (string)($row['platform'] ?? ''),
            'baseline_business_date' => (string)($row['baseline_business_date'] ?? ''),
            'review_business_date' => (string)($row['review_business_date'] ?? ''),
            'metric_key' => (string)($row['metric_key'] ?? ''),
            'metric_definition' => $metricDefinition,
            'metric_definition_digest' => $metricDigest,
            'before_value' => $beforeValue,
            'after_value' => $afterValue,
            'expected_direction' => (string)($row['expected_direction'] ?? ''),
            'target_type' => (string)($row['target_type'] ?? ''),
            'target_value' => $targetValue,
            'expected_delta' => $expectedDelta,
            'expected_delta_status' => (string)($row['expected_delta_status'] ?? ''),
            'target_confirmed_by' => (int)($row['target_confirmed_by'] ?? 0),
            'target_confirmed_at' => (string)($row['target_confirmed_at'] ?? ''),
            'baseline_refs' => $baselineRefs,
            'followup_refs' => $followupRefs,
            'source_readback_evidence_id' => (int)$row['source_readback_evidence_id'],
            'outcome_status' => (string)($row['outcome_status'] ?? ''),
            'outcome' => $outcome,
            'result_status' => (string)($row['result_status'] ?? ''),
            'result_summary' => (string)($row['result_summary'] ?? ''),
            'causality_claimed' => false,
            'reviewed_by' => (int)($row['reviewed_by'] ?? 0),
            'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
        ];
        $digestPayload['approval_target_digest'] = $approvalTargetDigest;
        $contentDigest = strtolower(trim((string)($row['content_digest'] ?? '')));
        return $this->isDigest($contentDigest) && hash_equals($contentDigest, $this->digest($digestPayload));
    }

    private function effectDecimal(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (is_nan($number) || is_infinite($number) || abs($number) >= 100000000000000.0) {
            return null;
        }
        return number_format($number, 6, '.', '');
    }

    /** @return list<string> */
    private function effectRefs(mixed $value): array
    {
        $refs = $this->decode($value);
        if (!array_is_list($refs) || $refs === []) {
            return [];
        }
        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_string($ref)
                || preg_match('/^[a-z0-9_.-]{1,80}#[^#\s]{1,400}$/D', trim($ref)) !== 1
            ) {
                return [];
            }
            $normalized[] = strtolower(trim($ref));
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param list<array<string,mixed>> $rows */
    private function evidenceVerificationStatus(array $rows, bool $readbackVerified): string
    {
        if ($readbackVerified) {
            return 'readback_verified';
        }
        foreach ($rows as $row) {
            $statuses = strtolower(implode(' ', array_map(
                static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
                array_intersect_key($row, array_flip([
                    'status', 'history_status', 'validation_status', 'quality_status', 'readback_status',
                ]))
            )));
            if (str_contains($statuses, 'conflict')) {
                return 'conflicted';
            }
            if (str_contains($statuses, 'partial')) {
                return 'partial';
            }
        }
        return 'unverified';
    }

    /** @param list<array<string,mixed>> $evidence */
    private function evidenceDigest(array $evidence): string
    {
        $safe = array_map(static fn(array $ref): array => [
            'tenant_id' => (int)$ref['tenant_id'],
            'hotel_id' => (int)$ref['hotel_id'],
            'role' => (string)$ref['role'],
            'source_kind' => (string)$ref['source_kind'],
            'fact_scope' => (string)$ref['fact_scope'],
            'metric_definition_digest' => (string)$ref['metric_definition_digest'],
            'platform' => (string)$ref['platform'],
            'business_date' => $ref['business_date'],
            'table' => (string)$ref['table'],
            'row_ids' => array_values($ref['row_ids']),
            'rows_digest' => (string)$ref['rows_digest'],
            'verification_status' => (string)$ref['verification_status'],
            'readback_verified' => (bool)$ref['readback_verified'],
        ], $evidence);
        return $this->digest($safe);
    }

    /**
     * @param list<array<string,mixed>> $events
     * @param list<array<string,mixed>> $evidence
     */
    private function verifyEvidenceLinks(array $record, array $events, array $evidence): void
    {
        $eventsById = [];
        foreach ($events as $event) {
            $eventsById[(int)$event['id']] = $event;
        }
        $byEvent = [];
        foreach ($evidence as $ref) {
            $eventId = (int)$ref['event_id'];
            $event = $eventsById[$eventId] ?? null;
            if (!is_array($event)
                || (int)$ref['cycle_id'] !== (int)$record['id']
                || (int)$ref['tenant_id'] !== (int)$record['tenant_id']
                || (int)$ref['hotel_id'] !== (int)$record['hotel_id']
                || (string)$ref['stage_key'] !== (string)$event['stage_key']
                || !hash_equals((string)$record['metric_definition_digest'], (string)$ref['metric_definition_digest'])
                || count($ref['row_ids']) !== (int)$ref['row_count']
                || !$this->isDigest((string)$ref['rows_digest'])
                || ((string)$ref['verification_status'] === 'readback_verified') !== (bool)$ref['readback_verified']
            ) {
                throw new RuntimeException('经营闭环证据引用与权威范围或事件链不一致');
            }
            $byEvent[$eventId][] = $ref;
        }
        foreach ($events as $event) {
            $refs = $byEvent[(int)$event['id']] ?? [];
            usort($refs, static fn(array $left, array $right): int => [
                $left['role'], $left['table'], $left['platform'], $left['row_ids'][0] ?? 0,
            ] <=> [
                $right['role'], $right['table'], $right['platform'], $right['row_ids'][0] ?? 0,
            ]);
            if (!hash_equals((string)$event['evidence_digest'], $this->evidenceDigest($refs))) {
                throw new RuntimeException('经营闭环事件证据摘要校验失败');
            }
            $expectedCommandDigest = $this->commandDigest(
                (string)$event['stage_key'],
                (string)$event['stage_status'],
                (string)$event['actor_kind'],
                (int)$event['actor_id'],
                (string)$event['source_module'],
                (array)$event['payload'],
                $refs,
                (string)$record['business_date']
            );
            if (!hash_equals((string)$event['command_digest'], $expectedCommandDigest)) {
                throw new RuntimeException('经营闭环命令幂等摘要校验失败');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function verifyEventChain(array $record, array $rows): array
    {
        if (count($rows) !== (int)$record['state_version'] || $rows === []) {
            throw new RuntimeException('经营闭环事件数量与 revision 不一致');
        }
        $previous = '';
        $lastCompletedStage = '';
        $normalized = [];
        foreach ($rows as $offset => $row) {
            $event = $this->normalizeEvent($row);
            if ((int)$event['sequence_no'] !== $offset + 1
                || !hash_equals((string)$event['previous_event_digest'], $previous)
            ) {
                throw new RuntimeException('经营闭环事件链顺序或前序摘要已漂移');
            }
            $expected = $this->digest([
                'cycle_id' => (int)$event['cycle_id'],
                'tenant_id' => (int)$event['tenant_id'],
                'hotel_id' => (int)$event['hotel_id'],
                'sequence_no' => (int)$event['sequence_no'],
                'command_key' => (string)$event['command_key'],
                'command_digest' => (string)$event['command_digest'],
                'from_stage' => (string)$event['from_stage'],
                'to_stage' => (string)$event['to_stage'],
                'from_version' => (int)$event['from_version'],
                'to_version' => (int)$event['to_version'],
                'stage_key' => (string)$event['stage_key'],
                'stage_status' => (string)$event['stage_status'],
                'actor_kind' => (string)$event['actor_kind'],
                'actor_id' => (int)$event['actor_id'],
                'source_module' => (string)$event['source_module'],
                'payload' => $event['payload'],
                'evidence_digest' => (string)$event['evidence_digest'],
                'previous_event_digest' => (string)$event['previous_event_digest'],
                'occurred_at' => (string)$event['occurred_at'],
            ]);
            if (!hash_equals((string)$event['event_digest'], $expected)) {
                throw new RuntimeException('经营闭环事件摘要校验失败');
            }
            if ((int)$event['tenant_id'] !== (int)$record['tenant_id']
                || (int)$event['hotel_id'] !== (int)$record['hotel_id']
                || (string)$event['to_stage'] !== (string)$event['stage_key']
                || (string)$event['from_stage'] !== $lastCompletedStage
                || (int)$event['from_version'] !== $offset
                || (int)$event['to_version'] !== $offset + 1
            ) {
                throw new RuntimeException('经营闭环事件作用域、阶段或版本边界已漂移');
            }
            if ((string)$event['stage_status'] === 'completed') {
                $lastCompletedStage = (string)$event['stage_key'];
            }
            $previous = (string)$event['event_digest'];
            $normalized[] = $event;
        }
        if (!hash_equals((string)$record['last_event_digest'], $previous)
            || (int)$record['last_event_id'] !== (int)$normalized[count($normalized) - 1]['id']
        ) {
            throw new RuntimeException('经营闭环权威投影未绑定事件链末端');
        }
        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function stageProjection(array $record, array $events): array
    {
        $eventByStage = [];
        foreach ($events as $event) {
            $eventByStage[(string)$event['stage_key']] = $event;
        }
        $result = [];
        foreach (self::STAGES as $key => $definition) {
            $status = $definition['index'] <= (int)$record['last_completed_stage_index'] ? 'complete' : 'not_proved';
            $blockingCodes = [];
            if ((string)$record['cycle_status'] === 'blocked' && (string)$record['next_required_stage'] === $key) {
                $status = 'missing';
                $blockingCodes = [(string)$record['block_code']];
            }
            $event = $eventByStage[$key] ?? null;
            $result[] = [
                'key' => $key,
                'label' => $definition['label'],
                'status' => $status,
                'event_id' => is_array($event) ? (int)$event['id'] : 0,
                'actor_kind' => is_array($event) ? (string)$event['actor_kind'] : '',
                'actor_id' => is_array($event) ? (int)$event['actor_id'] : 0,
                'occurred_at' => is_array($event) ? (string)$event['occurred_at'] : null,
                'blocking_gap_codes' => array_values(array_filter($blockingCodes)),
                'next_action' => $key === (string)$record['next_required_stage'] ? [
                    'action_code' => (string)$record['block_code'],
                    'priority' => (string)$record['cycle_status'] === 'blocked' ? 'high' : 'medium',
                    'status' => (string)$record['cycle_status'],
                    'action' => (string)$record['next_action'],
                    'entry' => '/api/operating-loop/reconcile',
                    'question_key' => $key,
                ] : null,
                'source_policy' => 'hotel_operating_cycle_kernel_only',
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function stageDetails(array $events): array
    {
        $details = [];
        foreach ($events as $event) {
            // A blocked event is still an authoritative human/system fact: it
            // must remain visible so the product can answer who judged, why it
            // stopped and who owns the next action. A later completed retry
            // naturally overwrites it because events are read in sequence.
            $details[(string)$event['stage_key']] = $event['payload'];
        }
        return $details;
    }

    private function assertProjectionDigest(array $record): void
    {
        $actual = strtolower(trim((string)($record['projection_digest'] ?? '')));
        if (!$this->isDigest($actual) || !hash_equals($actual, $this->projectionDigest($record))) {
            throw new RuntimeException('经营闭环权威投影摘要校验失败');
        }
    }

    private function assertRootIdentityDigests(array $record): void
    {
        $authorityKey = hash('sha256', implode("\0", [
            (string)$record['tenant_id'],
            (string)$record['hotel_id'],
            (string)$record['business_date'],
        ]));
        if (!hash_equals((string)$record['authority_key'], $authorityKey)
            || !hash_equals((string)$record['metric_definition_digest'], $this->digest($record['metric_definition']))
            || !hash_equals((string)$record['source_identity_digest'], $this->digest($record['source_identities']))
        ) {
            throw new RuntimeException('经营闭环身份、指标或来源 JSON 与冻结摘要不一致');
        }
    }

    private function projectionDigest(array $record): string
    {
        return $this->digest([
            'authority_key' => (string)$record['authority_key'],
            'tenant_id' => (int)$record['tenant_id'],
            'hotel_id' => (int)$record['hotel_id'],
            'hotel_name_snapshot' => (string)$record['hotel_name_snapshot'],
            'business_date' => (string)$record['business_date'],
            'metric_version' => (string)$record['metric_version'],
            'metric_definition_digest' => (string)$record['metric_definition_digest'],
            'source_identity_digest' => (string)$record['source_identity_digest'],
            'last_completed_stage' => (string)$record['last_completed_stage'],
            'last_completed_stage_index' => (int)$record['last_completed_stage_index'],
            'next_required_stage' => (string)$record['next_required_stage'],
            'cycle_status' => (string)$record['cycle_status'],
            'block_code' => (string)($record['block_code'] ?? ''),
            'block_detail' => (string)($record['block_detail'] ?? ''),
            'truth_summary' => (string)($record['truth_summary'] ?? ''),
            'priority_issue' => (string)($record['priority_issue'] ?? ''),
            'next_action' => (string)($record['next_action'] ?? ''),
            'next_owner' => is_array($record['next_owner'] ?? null) ? $record['next_owner'] : [],
            'review_due_at' => $record['review_due_at'] ?? null,
            'outcome_status' => (string)$record['outcome_status'],
            'experience_status' => (string)$record['experience_status'],
            'state_version' => (int)$record['state_version'],
            'last_event_id' => (int)($record['last_event_id'] ?? 0),
            'last_event_digest' => (string)($record['last_event_digest'] ?? ''),
        ]);
    }

    /** @return array<string,mixed> */
    private function normalizeRecord(array $row): array
    {
        return [
            ...$row,
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'metric_definition' => $this->decode($row['metric_definition_json'] ?? null),
            'source_identities' => array_values($this->decode($row['source_identities_json'] ?? null)),
            'last_completed_stage_index' => (int)$row['last_completed_stage_index'],
            'next_owner' => $this->decode($row['next_owner_json'] ?? null),
            'state_version' => (int)$row['state_version'],
            'last_event_id' => (int)($row['last_event_id'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeEvent(array $row): array
    {
        return [
            ...$row,
            'id' => (int)$row['id'],
            'cycle_id' => (int)$row['cycle_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'sequence_no' => (int)$row['sequence_no'],
            'from_version' => (int)$row['from_version'],
            'to_version' => (int)$row['to_version'],
            'actor_id' => (int)$row['actor_id'],
            'payload' => $this->decode($row['payload_json'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeEvidenceRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'cycle_id' => (int)$row['cycle_id'],
            'event_id' => (int)$row['event_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'stage_key' => (string)$row['stage_key'],
            'role' => (string)$row['evidence_role'],
            'source_kind' => (string)$row['source_kind'],
            'fact_scope' => (string)$row['fact_scope'],
            'metric_definition_digest' => (string)$row['metric_definition_digest'],
            'platform' => (string)$row['platform'],
            'business_date' => $row['business_date'],
            'table' => (string)$row['source_table'],
            'row_ids' => $this->positiveIds($this->decode($row['source_row_ids_json'] ?? null)),
            'row_count' => (int)$row['source_row_count'],
            'rows_digest' => (string)$row['source_rows_digest'],
            'verification_status' => (string)$row['verification_status'],
            'readback_verified' => (int)$row['readback_verified'] === 1,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function normalizeSourceIdentities(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new InvalidArgumentException('经营闭环至少需要一个 PMS 或 OTA 来源身份');
        }
        $identities = [];
        foreach ($value as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('经营闭环来源身份格式无效');
            }
            $kind = strtolower($this->token($raw['source_kind'] ?? null, '来源类型', 24));
            if (!in_array($kind, ['pms', 'ota'], true)) {
                throw new InvalidArgumentException('经营闭环来源类型只允许 pms 或 ota');
            }
            $platform = $this->normalizePlatform($this->token($raw['platform'] ?? null, '来源平台', 40));
            if ($platform === '') {
                throw new InvalidArgumentException('经营闭环来源平台不能为空');
            }
            $externalHotelId = $this->token(
                $raw['platform_hotel_id'] ?? $raw['provider_hotel_id'] ?? $raw['external_hotel_id'] ?? null,
                '平台门店身份',
                160
            );
            $ref = is_array($raw['evidence_ref'] ?? null) ? $raw['evidence_ref'] : [];
            if ($ref === []) {
                throw new InvalidArgumentException('来源身份必须引用可回读的数据库行');
            }
            $identities[] = [
                'source_kind' => $kind,
                'platform' => $platform,
                'external_hotel_id' => $externalHotelId,
                'evidence_ref' => [
                    'table' => $this->token($ref['table'] ?? null, '身份来源表', 80),
                    'row_id' => (int)($ref['row_id'] ?? 0),
                ],
            ];
            $identityIndex = array_key_last($identities);
            $planId = (int)($raw['collection_plan_id'] ?? 0);
            $planVersion = (int)($raw['collection_plan_version'] ?? 0);
            $planHash = strtolower(trim((string)($raw['collection_plan_hash'] ?? '')));
            $dataSourceId = (int)($raw['data_source_id'] ?? 0);
            if ($planId <= 0 || $planVersion <= 0 || !$this->isDigest($planHash)) {
                throw new InvalidArgumentException('来源身份必须绑定同一个生效采集计划的版本和签名摘要');
            }
            $identities[$identityIndex]['collection_plan_id'] = $planId;
            $identities[$identityIndex]['collection_plan_version'] = $planVersion;
            $identities[$identityIndex]['collection_plan_hash'] = $planHash;
            if ($kind === 'ota') {
                if ($dataSourceId <= 0) {
                    throw new InvalidArgumentException('计划内 OTA 来源身份缺少指定 data_source_id');
                }
                $identities[$identityIndex]['data_source_id'] = $dataSourceId;
            }
        }
        usort($identities, static fn(array $left, array $right): int => [
            $left['source_kind'], $left['platform'], $left['external_hotel_id'],
        ] <=> [
            $right['source_kind'], $right['platform'], $right['external_hotel_id'],
        ]);
        $keys = [];
        foreach ($identities as $identity) {
            $key = $identity['source_kind'] . ':' . $identity['platform'];
            if (isset($keys[$key])) {
                throw new InvalidArgumentException('同一经营闭环不能重复声明同类平台来源身份');
            }
            $keys[$key] = true;
        }
        $planKeys = [];
        $kindCounts = ['pms' => 0, 'ota' => 0];
        foreach ($identities as $identity) {
            $kindCounts[(string)$identity['source_kind']]++;
            $planKeys[] = implode(':', [
                (string)(int)$identity['collection_plan_id'],
                (string)(int)$identity['collection_plan_version'],
                (string)$identity['collection_plan_hash'],
            ]);
        }
        $planKeys = array_values(array_unique($planKeys));
        if (count($planKeys) !== 1) {
            throw new InvalidArgumentException('同一经营闭环的来源身份必须绑定同一个生效采集计划版本');
        }
        if ($kindCounts['pms'] !== 1 || $kindCounts['ota'] < 1) {
            throw new InvalidArgumentException('权威经营闭环必须同时冻结一个 PMS 全酒店来源和至少一个 OTA 渠道来源');
        }
        return $identities;
    }

    /** @param list<array<string,mixed>> $identities */
    private function assertSourceIdentitiesMatchActivePlan(
        array $identities,
        int $tenantId,
        int $hotelId
    ): void {
        $first = $identities[0] ?? [];
        $planId = (int)($first['collection_plan_id'] ?? 0);
        $planVersion = (int)($first['collection_plan_version'] ?? 0);
        $planHash = strtolower(trim((string)($first['collection_plan_hash'] ?? '')));
        $plan = Db::name('hotel_collection_plans')
            ->where('id', $planId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($plan)
            || (int)($plan['plan_version'] ?? 0) !== $planVersion
            || !hash_equals($planHash, strtolower(trim((string)($plan['plan_hash'] ?? ''))))
            || strtolower(trim((string)($plan['plan_status'] ?? ''))) !== 'active'
            || (int)($plan['enabled'] ?? 0) !== 1
            || (int)($plan['active_slot'] ?? 0) !== 1
        ) {
            throw new InvalidArgumentException('来源身份没有绑定当前酒店数据库中唯一生效且精确回读的采集计划');
        }

        $sources = $this->decode($plan['source_plan_json'] ?? null);
        $expected = [];
        foreach ($sources as $key => $source) {
            if (!is_array($source)) {
                continue;
            }
            if (strtolower((string)$key) === 'pms') {
                $platform = $this->normalizePlatform((string)($source['provider'] ?? ''));
                if ($platform !== '') {
                    $expected['pms:' . $platform] = 0;
                }
                continue;
            }
            $platform = $this->normalizePlatform((string)$key);
            $dataSourceId = (int)($source['data_source_id'] ?? 0);
            if ($platform !== '' && $dataSourceId > 0) {
                $expected['ota:' . $platform] = $dataSourceId;
            }
        }

        $actual = [];
        foreach ($identities as $identity) {
            $kind = (string)($identity['source_kind'] ?? '');
            $platform = $this->normalizePlatform((string)($identity['platform'] ?? ''));
            $actual[$kind . ':' . $platform] = $kind === 'ota'
                ? (int)($identity['data_source_id'] ?? 0)
                : 0;
        }
        ksort($expected);
        ksort($actual);
        if ($expected === []
            || count(array_filter(array_keys($expected), static fn(string $key): bool => str_starts_with($key, 'pms:'))) !== 1
            || count(array_filter(array_keys($expected), static fn(string $key): bool => str_starts_with($key, 'ota:'))) < 1
            || $expected !== $actual
        ) {
            throw new InvalidArgumentException('来源身份与生效采集计划冻结的 PMS/OTA 来源或 data_source_id 不一致');
        }
    }

    private function stage(mixed $value): string
    {
        $stage = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        if (!isset(self::STAGES[$stage])) {
            throw new InvalidArgumentException('经营闭环阶段无效');
        }
        return $stage;
    }

    private function stageLabel(string $stage): string
    {
        return self::STAGES[$stage]['label'] ?? $stage;
    }

    private function outcomeStatus(mixed $value): string
    {
        $status = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        if (!in_array($status, ['supported', 'refuted', 'indeterminate'], true)) {
            throw new InvalidArgumentException('结果判断只允许 supported/refuted/indeterminate');
        }
        return $status;
    }

    private function experienceStatus(mixed $value): string
    {
        $status = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        if (!in_array($status, ['not_reusable', 'candidate', 'promoted', 'rejected'], true)) {
            throw new InvalidArgumentException('经验状态无效');
        }
        return $status;
    }

    private function normalizePlatform(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, 'ctrip')) {
            return 'ctrip';
        }
        if (str_starts_with($value, 'meituan')) {
            return str_contains($value, 'pms') ? 'meituan_cloud_pms' : 'meituan';
        }
        if (str_contains($value, 'dingdandao')) {
            return 'dingdandao_pms';
        }
        return preg_replace('/[^a-z0-9_\-]/', '', $value) ?: '';
    }

    /** @param list<mixed> $refs */
    private function commandDigest(
        string $stage,
        string $status,
        string $actorKind,
        int $actorId,
        string $sourceModule,
        array $payload,
        array $refs,
        string $defaultBusinessDate
    ): string {
        $normalizedRefs = [];
        foreach ($refs as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('经营闭环证据引用格式无效');
            }
            $table = strtolower($this->token($raw['table'] ?? null, '证据表', 80));
            $sourceKind = $this->token($raw['source_kind'] ?? null, '证据来源类型', 24);
            $rowIds = $this->positiveIds($raw['row_ids'] ?? [$raw['row_id'] ?? 0]);
            if ($rowIds === []) {
                throw new InvalidArgumentException('经营闭环证据必须引用精确数据库行ID');
            }
            $businessDate = trim((string)($raw['business_date'] ?? ''));
            if ($businessDate === '' && (self::STAGES[$stage]['index'] ?? 99) <= 3) {
                $businessDate = $defaultBusinessDate;
            }
            $normalizedRefs[] = [
                'role' => $this->token($raw['role'] ?? null, '证据角色', 48),
                'source_kind' => $sourceKind,
                'fact_scope' => $this->factScope($sourceKind, $table),
                'platform' => $this->normalizePlatform((string)($raw['platform'] ?? '')),
                'business_date' => $businessDate,
                'table' => $table,
                'row_ids' => $rowIds,
            ];
        }
        usort($normalizedRefs, static fn(array $left, array $right): int => [
            $left['role'], $left['table'], $left['platform'], $left['row_ids'][0] ?? 0,
        ] <=> [
            $right['role'], $right['table'], $right['platform'], $right['row_ids'][0] ?? 0,
        ]);
        return $this->digest([
            'stage' => $stage,
            'status' => $status,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'source_module' => $sourceModule,
            'payload' => $payload,
            'evidence_refs' => $normalizedRefs,
        ]);
    }

    private function commandKey(mixed $value, string $fallback): string
    {
        $key = trim(is_scalar($value) ? (string)$value : '');
        if ($key === '') {
            $key = $fallback;
        }
        if ($key === '' || mb_strlen($key) > 191) {
            throw new InvalidArgumentException('经营闭环 command_key 不能为空且不能超过191字符');
        }
        return $key;
    }

    private function token(mixed $value, string $label, int $limit): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        if ($text === '' || mb_strlen($text) > $limit || preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) {
            throw new InvalidArgumentException($label . '不能为空、超长或包含控制字符');
        }
        return $text;
    }

    private function requiredText(mixed $value, string $label, int $limit): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        if ($text === '' || mb_strlen($text) > $limit) {
            throw new InvalidArgumentException($label . '不能为空且不能超过' . $limit . '字');
        }
        return $text;
    }

    private function optionalText(mixed $value, int $limit, string $fallback): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        if ($text === '') {
            return $fallback;
        }
        if (mb_strlen($text) > $limit) {
            throw new InvalidArgumentException('经营闭环文本不能超过' . $limit . '字');
        }
        return $text;
    }

    /** @return array<string,mixed> */
    private function requiredObject(mixed $value, string $label): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new InvalidArgumentException($label . '必须是非空对象');
        }
        return $this->canonicalize($value);
    }

    /** @return array<string,mixed> */
    private function optionalObject(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('经营闭环对象字段格式无效');
        }
        return $this->canonicalize($value);
    }

    private function date(mixed $value, string $label): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text
        ) {
            throw new InvalidArgumentException($label . '必须使用 YYYY-MM-DD');
        }
        return $text;
    }

    private function dateTime(mixed $value, string $label): string
    {
        $text = trim(is_scalar($value) ? (string)$value : '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $text
        ) {
            throw new InvalidArgumentException($label . '必须使用 YYYY-MM-DD HH:MM:SS');
        }
        return $text;
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function assertTablesReady(): void
    {
        if (!$this->tablesReady()) {
            throw new RuntimeException(
                '经营闭环内核表未就绪，请应用 20260812_z_create_hotel_operating_cycle_kernel.sql'
            );
        }
    }

    private function tablesReady(): bool
    {
        foreach ([
            self::RECORD_TABLE,
            self::EVENT_TABLE,
            self::EVIDENCE_TABLE,
            'hotel_collection_plans',
        ] as $table) {
            try {
                $fields = Db::getTableInfo($table, 'fields');
            } catch (Throwable) {
                return false;
            }
            if (!is_array($fields) || $fields === []) {
                return false;
            }
        }
        return true;
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }

    private function json(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function safeErrorCode(string $message): string
    {
        $message = strtolower(trim($message));
        $message = preg_replace('/[^a-z0-9_\-]+/', '_', $message) ?: 'kernel_readback_failed';
        return substr(trim($message, '_'), 0, 120) ?: 'kernel_readback_failed';
    }
}
