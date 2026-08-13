<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;
use think\facade\Db;

final class OperatingMemoryService
{
    public const TABLE = 'hotel_operating_memories';

    private const IDEMPOTENCY_WRITE_ATTEMPTS = 3;

    private const IDEMPOTENCY_READBACK_ATTEMPTS = 3;

    private const IDEMPOTENCY_RETRY_DELAY_MICROSECONDS = 10000;

    /** @var list<string> */
    private const MEMORY_LAYERS = ['fact', 'analysis', 'judgement', 'decision', 'execution_review', 'milestone', 'sop'];

    /** @var list<string> */
    private const GROWTH_EVENT_KINDS = [
        'fact',
        'analysis',
        'judgement',
        'decision',
        'execution',
        'review',
        'milestone',
        'manual_background',
    ];

    /** @var list<string> */
    private const MANUAL_EVENT_KINDS = [
        'fact',
        'analysis',
        'judgement',
        'decision',
        'execution',
        'review',
        'manual_background',
    ];

    /** @var list<string> */
    private const SOURCE_SCOPES = ['ota_channel', 'pms', 'whole_hotel', 'manual_background', 'other'];

    /** @var list<string> */
    private const QUALITY_STATUSES = ['verified', 'partial', 'unverified', 'conflicted', 'expired'];

    /** @var list<string> */
    private const USAGE_LEVELS = ['archive_only', 'reference', 'decision_support', 'sop_template'];

    public function __construct(
        private readonly OperationManagementService $operationService = new OperationManagementService()
    ) {
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(int $callerTenantId, array $hotelIds, ?int $hotelId = null, array $filters = []): array
    {
        $hotelIds = $this->normalizeHotelIds($hotelIds);
        if ($hotelIds === []) {
            throw new InvalidArgumentException('经营记忆查询缺少可访问酒店');
        }
        if ($hotelId !== null && ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true))) {
            throw new RuntimeException('无权查看该酒店经营记忆');
        }

        if (!$this->tableExists()) {
            return $this->missingMigrationResult();
        }

        $hotelIds = $this->currentTenantHotelIds($callerTenantId, $hotelIds, $hotelId);

        $query = Db::name(self::TABLE)->alias('memory')
            ->join('hotels hotel', 'hotel.id = memory.hotel_id AND hotel.tenant_id = memory.tenant_id')
            ->field('memory.*')
            ->whereIn('memory.hotel_id', $hotelIds)
            ->whereNull('memory.deleted_at');
        if ($callerTenantId > 0) {
            $query->where('memory.tenant_id', $callerTenantId)
                ->where('hotel.tenant_id', $callerTenantId);
        }
        if ($hotelId !== null) {
            $query->where('memory.hotel_id', $hotelId);
        }

        foreach ([
            'memory_layer' => self::MEMORY_LAYERS,
            'quality_status' => self::QUALITY_STATUSES,
            'usage_level' => self::USAGE_LEVELS,
        ] as $field => $allowed) {
            $value = strtolower(trim((string)($filters[$field] ?? '')));
            if ($value === '') {
                continue;
            }
            if (!in_array($value, $allowed, true)) {
                throw new InvalidArgumentException('经营记忆筛选条件无效：' . $field);
            }
            $query->where('memory.' . $field, $value);
        }

        $matchedTotal = (int)(clone $query)->count('memory.id');
        $rows = $query
            ->order('memory.occurred_at', 'desc')
            ->order('memory.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        return [
            'data_status' => 'ok',
            'list' => array_map([$this, 'normalizeRow'], $rows),
            'count' => count($rows),
            'matched_total' => $matchedTotal,
            'returned_count' => count($rows),
            'truncated' => $matchedTotal > count($rows),
            'supported_layers' => self::MEMORY_LAYERS,
            'supported_usage_levels' => self::USAGE_LEVELS,
            'data_gaps' => [],
            'source_policy' => 'reference_existing_facts_without_ota_write',
        ];
    }

    /**
     * Growth archive timeline for one exact hotel. Existing operating memories
     * stay the source of truth; this method only shapes and filters the archive.
     *
     * @param list<int> $hotelIds
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function growthTimeline(
        int $callerTenantId,
        array $hotelIds,
        int $hotelId,
        array $filters = []
    ): array {
        $hotelIds = $this->normalizeHotelIds($hotelIds);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权查看该酒店经营成长档案');
        }
        if (!$this->tableExists()) {
            $missing = $this->missingMigrationResult();
            $missing['supported_event_kinds'] = self::GROWTH_EVENT_KINDS;
            return $missing;
        }
        $hotelIds = $this->currentTenantHotelIds($callerTenantId, $hotelIds, $hotelId);

        $dateStart = $this->optionalDate($filters['date_start'] ?? null, '开始日期');
        $dateEnd = $this->optionalDate($filters['date_end'] ?? null, '结束日期');
        if ($dateStart !== null && $dateEnd !== null && $dateStart > $dateEnd) {
            throw new InvalidArgumentException('结束日期不能早于开始日期');
        }
        $layer = strtolower(trim((string)($filters['memory_layer'] ?? $filters['layer'] ?? '')));
        if ($layer !== '' && !in_array($layer, self::MEMORY_LAYERS, true)) {
            throw new InvalidArgumentException('经营成长档案筛选条件无效：memory_layer');
        }
        $eventKind = strtolower(trim((string)($filters['event_kind'] ?? '')));
        if ($eventKind !== '' && !in_array($eventKind, self::GROWTH_EVENT_KINDS, true)) {
            throw new InvalidArgumentException('经营成长档案筛选条件无效：event_kind');
        }

        $query = Db::name(self::TABLE)->alias('memory')
            ->join('hotels hotel', 'hotel.id = memory.hotel_id AND hotel.tenant_id = memory.tenant_id')
            ->field('memory.*')
            ->where('memory.hotel_id', $hotelId)
            ->whereNull('memory.deleted_at');
        if ($callerTenantId > 0) {
            $query->where('memory.tenant_id', $callerTenantId)
                ->where('hotel.tenant_id', $callerTenantId);
        }
        if ($dateStart !== null) {
            $query->where('memory.business_date', '>=', $dateStart);
        }
        if ($dateEnd !== null) {
            $query->where('memory.business_date', '<=', $dateEnd);
        }
        if ($layer !== '') {
            $query->where('memory.memory_layer', $layer);
        }
        $includeVersions = filter_var($filters['include_versions'] ?? false, FILTER_VALIDATE_BOOL);
        if (!$includeVersions) {
            $query->where('memory.lifecycle_status', 'active');
        }
        if ($eventKind !== '') {
            $this->applyGrowthEventKindFilter($query, $eventKind);
        }

        $matchedTotal = (int)(clone $query)->count('memory.id');
        $rows = $query
            ->order('memory.occurred_at', 'desc')
            ->order('memory.id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $timeline = array_map([$this, 'normalizeGrowthRow'], $rows);

        $reviewed = 0;
        $observing = 0;
        foreach ($timeline as $row) {
            if ((string)($row['event_kind'] ?? '') !== 'review') {
                continue;
            }
            $reviewStatus = strtolower(trim((string)($row['context']['review_status'] ?? '')));
            if ($reviewStatus === 'observing') {
                $observing++;
            } elseif (in_array($reviewStatus, ['success', 'near_success', 'failed'], true)) {
                $reviewed++;
            }
        }

        return [
            'data_status' => 'ok',
            'hotel_id' => $hotelId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'list' => $timeline,
            'count' => count($timeline),
            'matched_total' => $matchedTotal,
            'returned_count' => count($timeline),
            'truncated' => $matchedTotal > count($timeline),
            'overview' => [
                'archive_count' => count($timeline),
                'completed_review_count' => $reviewed,
                'observing_count' => $observing,
                'repeated_problem_count' => null,
                'repeated_problem_status' => 'not_available',
            ],
            'supported_layers' => self::MEMORY_LAYERS,
            'supported_event_kinds' => self::GROWTH_EVENT_KINDS,
            'data_gaps' => [[
                'code' => 'repeated_problem_detection_not_available',
                'message' => '当前没有可核验的重复问题识别结果',
            ]],
            'source_policy' => 'reference_existing_facts_without_ota_write',
            'write_boundaries' => [
                'ota_write' => false,
                'external_message' => false,
            ],
        ];
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createManualGrowthEvent(
        int $callerTenantId,
        array $hotelIds,
        int $hotelId,
        array $input,
        int $recordedBy
    ): array {
        $tenantId = $this->assertGrowthWriteScope($callerTenantId, $hotelIds, $hotelId, $recordedBy);
        $eventKind = strtolower(trim((string)($input['event_kind'] ?? $input['event_type'] ?? '')));
        if (!in_array($eventKind, self::MANUAL_EVENT_KINDS, true)) {
            throw new InvalidArgumentException('经营事件类型无效');
        }
        $memoryLayer = $this->manualMemoryLayer($eventKind);
        $title = $this->requiredText($input['title'] ?? null, '事件标题', 191);
        $summary = $this->requiredText($input['summary'] ?? null, '实际发生的事情', 2000);
        $occurredAt = $this->requiredOccurredAt($input['occurred_at'] ?? null);
        $businessDate = $this->optionalDate($input['business_date'] ?? null, '业务日期')
            ?? substr($occurredAt, 0, 10);
        if ($businessDate !== substr($occurredAt, 0, 10)) {
            throw new InvalidArgumentException('业务日期必须与发生时间属于同一天');
        }
        [$platform, $sourceScope] = $this->normalizeManualSource(
            $input['platform'] ?? '',
            $input['source_scope'] ?? null
        );
        $ownerJudgement = trim((string)($input['owner_judgement'] ?? $input['judgement'] ?? ''));
        if (mb_strlen($ownerJudgement) > 2000) {
            throw new InvalidArgumentException('当时的判断或处理方式不能超过2000字');
        }
        $evidenceRefs = $this->normalizeEvidenceRefs($input['evidence_refs'] ?? []);
        $context = [
            'event_kind' => $eventKind,
            'manual_record' => true,
            'owner_judgement' => $ownerJudgement !== '' ? $ownerJudgement : null,
            'evidence_count' => count($evidenceRefs),
            'verification_status' => 'unverified',
            'recorded_at' => date('Y-m-d H:i:s'),
            'recorded_by' => $recordedBy,
        ];
        $digestPayload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_layer' => $memoryLayer,
            'event_kind' => $eventKind,
            'title' => $title,
            'summary' => $summary,
            'business_date' => $businessDate,
            'occurred_at' => $occurredAt,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'owner_judgement' => $ownerJudgement,
            'evidence_refs' => $evidenceRefs,
        ];
        $contentDigest = hash('sha256', $this->encodeJson($digestPayload));
        $requestKey = $this->idempotencyKey($input['client_request_id'] ?? null, $contentDigest);
        $record = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_key' => 'manual-event:' . $requestKey,
            'memory_layer' => $memoryLayer,
            'title' => $title,
            'summary' => $summary,
            'business_date' => $businessDate,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'source_module' => 'operating_growth_archive',
            'source_record_type' => 'manual_operating_event',
            'source_record_id' => 0,
            'evidence_refs_json' => $this->encodeJson($evidenceRefs),
            'context_json' => $this->encodeJson($context),
            'quality_status' => 'unverified',
            'usage_level' => 'archive_only',
            'lifecycle_status' => 'active',
            'content_digest' => $contentDigest,
            'previous_memory_id' => null,
            'recorded_by' => $recordedBy,
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ];

        return $this->persistGrowthRecord($record, $callerTenantId, $hotelIds, [
            'memory_layer' => $memoryLayer,
            'event_kind' => $eventKind,
            'business_date' => $businessDate,
            'occurred_at' => $occurredAt,
            'source_scope' => $sourceScope,
            'platform' => $platform,
        ]);
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addOwnerAnnotation(
        int $memoryId,
        int $callerTenantId,
        array $hotelIds,
        array $input,
        int $recordedBy
    ): array {
        $parent = $this->read($memoryId, $callerTenantId, $hotelIds);
        $hotelId = (int)$parent['hotel_id'];
        $tenantId = $this->assertGrowthWriteScope($callerTenantId, $hotelIds, $hotelId, $recordedBy);
        if ($tenantId !== (int)$parent['tenant_id']) {
            throw new RuntimeException('无权批注该酒店经营档案');
        }
        $annotation = $this->requiredText($input['annotation'] ?? $input['content'] ?? null, '老板批注', 2000);
        $annotatedAt = date('Y-m-d H:i:s');
        $context = [
            'event_kind' => 'judgement',
            'relation_type' => 'owner_annotation',
            'parent_memory_id' => $memoryId,
            'parent_quality_status' => (string)$parent['quality_status'],
            'annotated_at' => $annotatedAt,
            'annotated_by' => $recordedBy,
        ];
        $digestPayload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'parent_memory_id' => $memoryId,
            'annotation' => $annotation,
            'recorded_by' => $recordedBy,
        ];
        $contentDigest = hash('sha256', $this->encodeJson($digestPayload));
        $requestKey = $this->idempotencyKey($input['client_request_id'] ?? null, $contentDigest);
        $record = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_key' => 'owner-annotation:' . $memoryId . ':' . $requestKey,
            'memory_layer' => 'judgement',
            'title' => mb_substr('老板批注 · ' . (string)$parent['title'], 0, 191),
            'summary' => $annotation,
            'business_date' => $parent['business_date'] ?: null,
            'platform' => (string)$parent['platform'],
            'source_scope' => (string)$parent['source_scope'],
            'source_module' => 'operating_growth_archive',
            'source_record_type' => 'hotel_operating_memory',
            'source_record_id' => $memoryId,
            'evidence_refs_json' => $this->encodeJson([['type' => 'hotel_operating_memory', 'id' => $memoryId]]),
            'context_json' => $this->encodeJson($context),
            'quality_status' => 'unverified',
            'usage_level' => 'archive_only',
            'lifecycle_status' => 'active',
            'content_digest' => $contentDigest,
            'previous_memory_id' => $memoryId,
            'recorded_by' => $recordedBy,
            'occurred_at' => $annotatedAt,
            'created_at' => $annotatedAt,
            'updated_at' => $annotatedAt,
            'deleted_at' => null,
        ];

        return $this->persistGrowthRecord($record, $callerTenantId, $hotelIds, [
            'memory_layer' => 'judgement',
            'event_kind' => 'judgement',
            'previous_memory_id' => $memoryId,
            'source_record_id' => $memoryId,
        ]);
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function markMilestone(
        int $memoryId,
        int $callerTenantId,
        array $hotelIds,
        array $input,
        int $recordedBy
    ): array {
        $parent = $this->read($memoryId, $callerTenantId, $hotelIds);
        $hotelId = (int)$parent['hotel_id'];
        $tenantId = $this->assertGrowthWriteScope($callerTenantId, $hotelIds, $hotelId, $recordedBy);
        if ($tenantId !== (int)$parent['tenant_id']) {
            throw new RuntimeException('无权设置该酒店经营里程碑');
        }
        if ((string)$parent['memory_layer'] === 'milestone') {
            throw new InvalidArgumentException('里程碑记录不能再次设为里程碑');
        }
        $note = trim((string)($input['note'] ?? ''));
        if (mb_strlen($note) > 2000) {
            throw new InvalidArgumentException('里程碑说明不能超过2000字');
        }
        $markedAt = date('Y-m-d H:i:s');
        $summary = $note !== '' ? $note : '已由操作者设为经营里程碑';
        $context = [
            'event_kind' => 'milestone',
            'relation_type' => 'milestone',
            'parent_memory_id' => $memoryId,
            'parent_quality_status' => (string)$parent['quality_status'],
            'marked_at' => $markedAt,
            'marked_by' => $recordedBy,
        ];
        $digestPayload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'parent_memory_id' => $memoryId,
            'note' => $note,
            'recorded_by' => $recordedBy,
        ];
        $contentDigest = hash('sha256', $this->encodeJson($digestPayload));
        $requestKey = $this->idempotencyKey($input['client_request_id'] ?? null, $contentDigest);
        $record = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_key' => 'milestone:' . $memoryId . ':' . $requestKey,
            'memory_layer' => 'milestone',
            'title' => mb_substr('经营里程碑 · ' . (string)$parent['title'], 0, 191),
            'summary' => $summary,
            'business_date' => $parent['business_date'] ?: null,
            'platform' => (string)$parent['platform'],
            'source_scope' => (string)$parent['source_scope'],
            'source_module' => 'operating_growth_archive',
            'source_record_type' => 'hotel_operating_memory',
            'source_record_id' => $memoryId,
            'evidence_refs_json' => $this->encodeJson([['type' => 'hotel_operating_memory', 'id' => $memoryId]]),
            'context_json' => $this->encodeJson($context),
            'quality_status' => (string)$parent['quality_status'],
            'usage_level' => 'archive_only',
            'lifecycle_status' => 'active',
            'content_digest' => $contentDigest,
            'previous_memory_id' => $memoryId,
            'recorded_by' => $recordedBy,
            'occurred_at' => $markedAt,
            'created_at' => $markedAt,
            'updated_at' => $markedAt,
            'deleted_at' => null,
        ];

        return $this->persistGrowthRecord($record, $callerTenantId, $hotelIds, [
            'memory_layer' => 'milestone',
            'event_kind' => 'milestone',
            'source_record_id' => $memoryId,
        ], true);
    }

    /** @param list<int> $hotelIds @return array<string, mixed> */
    public function read(int $id, int $callerTenantId, array $hotelIds): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('经营记忆ID无效');
        }
        if (!$this->tableExists()) {
            throw new RuntimeException('经营记忆功能尚未启用：请先执行数据库迁移');
        }

        $hotelIds = $this->normalizeHotelIds($hotelIds);
        if ($hotelIds === []) {
            throw new RuntimeException('operating memory not found');
        }
        $hotelIds = $this->currentTenantHotelIds($callerTenantId, $hotelIds);
        if ($hotelIds === []) {
            throw new RuntimeException('operating memory not found in the current tenant scope');
        }
        $query = Db::name(self::TABLE)->alias('memory')
            ->join('hotels hotel', 'hotel.id = memory.hotel_id AND hotel.tenant_id = memory.tenant_id')
            ->field('memory.*')
            ->where('memory.id', $id)
            ->whereIn('memory.hotel_id', $hotelIds)
            ->whereNull('memory.deleted_at');
        if ($callerTenantId > 0) {
            $query->where('memory.tenant_id', $callerTenantId)
                ->where('hotel.tenant_id', $callerTenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('operating memory not found');
        }

        return $this->normalizeRow($row);
    }

    /**
     * Turn one completed execution review into one immutable memory version.
     * The operation service remains the source of truth; this table only stores
     * a traceable index and quality/usage classification.
     *
     * @param list<int> $hotelIds
     * @return array<string, mixed>
     */
    public function createFromExecutionTask(
        int $taskId,
        int $callerTenantId,
        array $hotelIds,
        int $recordedBy
    ): array {
        if ($taskId <= 0) {
            throw new InvalidArgumentException('执行任务ID无效');
        }
        if (!$this->tableExists()) {
            throw new RuntimeException('经营记忆功能尚未启用：请先执行数据库迁移');
        }

        $hotelIds = $this->normalizeHotelIds($hotelIds);
        $task = $this->operationService->readExecutionTask($taskId, $hotelIds);
        $intentId = (int)($task['intent_id'] ?? 0);
        if ($intentId <= 0) {
            throw new RuntimeException('经营记忆保存失败：执行任务缺少来源意图');
        }
        $intent = $this->operationService->readExecutionIntent($intentId, $hotelIds);
        $this->assertExecutionIdentity($task, $intent, $hotelIds, $callerTenantId);

        $this->assertExecutionReviewReady($task, $intent);

        $record = $this->buildExecutionReviewRecord($task, $intent, $recordedBy);
        $write = $this->convergeIdempotentWrite($record, function () use ($record, $taskId, $hotelIds): array {
            return $this->operationService->withExecutionTaskMutationAuthorization(
                $taskId,
                $hotelIds,
                function (array $authorization) use ($record, $hotelIds): array {
                $writeRecord = $this->authorizedExecutionReviewRecord($authorization, $record, $hotelIds);
                $sameContent = Db::name(self::TABLE)
                    ->where('tenant_id', (int)$writeRecord['tenant_id'])
                    ->where('hotel_id', (int)$writeRecord['hotel_id'])
                    ->where('memory_key', (string)$writeRecord['memory_key'])
                    ->whereNull('deleted_at')
                    ->lock(true)
                    ->find();
                if (is_array($sameContent)) {
                    return ['id' => (int)$sameContent['id'], 'created' => false];
                }

                $previous = Db::name(self::TABLE)
                    ->where('tenant_id', (int)$writeRecord['tenant_id'])
                    ->where('hotel_id', (int)$writeRecord['hotel_id'])
                    ->where('memory_layer', 'execution_review')
                    ->where('source_record_type', 'operation_execution_task')
                    ->where('source_record_id', (int)$writeRecord['source_record_id'])
                    ->whereNull('deleted_at')
                    ->order('id', 'desc')
                    ->lock(true)
                    ->find();
                if (is_array($previous)) {
                    $writeRecord['previous_memory_id'] = (int)$previous['id'];
                }

                $id = (int)Db::name(self::TABLE)->insertGetId($writeRecord);
                if ($id <= 0) {
                    throw new RuntimeException('经营记忆保存失败：未取得记录ID');
                }
                if (is_array($previous)) {
                    Db::name(self::TABLE)
                        ->where('id', (int)$previous['id'])
                        ->where('tenant_id', (int)$writeRecord['tenant_id'])
                        ->where('hotel_id', (int)$writeRecord['hotel_id'])
                        ->update([
                            'lifecycle_status' => 'superseded',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }

                return ['id' => $id, 'created' => true];
                }
            );
        }, function (array $existing) use ($record, $taskId, $hotelIds): array {
            return $this->operationService->withExecutionTaskMutationAuthorization(
                $taskId,
                $hotelIds,
                function (array $authorization) use ($existing, $record, $hotelIds): array {
                    $lockedRecord = $this->authorizedExecutionReviewRecord($authorization, $record, $hotelIds);
                    foreach (['tenant_id', 'hotel_id', 'memory_key', 'content_digest'] as $field) {
                        if ((string)($existing[$field] ?? '') !== (string)$lockedRecord[$field]) {
                            throw new RuntimeException(
                                'idempotent operating memory no longer matches the current execution task'
                            );
                        }
                    }
                    return ['id' => (int)$existing['id'], 'created' => false];
                }
            );
        });
        $memoryId = (int)$write['id'];
        $created = (bool)$write['created'];

        $memory = $this->read($memoryId, $callerTenantId, $hotelIds);
        if ((int)($memory['id'] ?? 0) !== $memoryId
            || (int)($memory['hotel_id'] ?? 0) !== (int)$record['hotel_id']
            || (int)($memory['source_record_id'] ?? 0) !== $taskId
            || (string)($memory['memory_layer'] ?? '') !== 'execution_review'
            || (string)($memory['content_digest'] ?? '') !== (string)$record['content_digest']
        ) {
            throw new RuntimeException('经营记忆已写入但严格回读校验失败');
        }

        return [
            'memory' => $memory,
            'created' => $created,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => [
                'ota_write' => false,
                'external_message' => false,
            ],
        ];
    }

    public function tableExists(): bool
    {
        return $this->operationService->tableExists(self::TABLE);
    }

    /** @return array<string, mixed> */
    private function buildExecutionReviewRecord(array $task, array $intent, int $recordedBy): array
    {
        $tenantId = (int)($task['tenant_id'] ?? 0);
        $hotelId = (int)($task['hotel_id'] ?? 0);
        $taskId = (int)($task['id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $businessDate = $this->businessDate($intent);
        $evidenceRows = is_array($task['evidence'] ?? null) ? $task['evidence'] : [];
        $effectReviews = is_array($task['effect_reviews'] ?? null) ? $task['effect_reviews'] : [];
        $evidenceRefs = [
            ['type' => 'operation_execution_intent', 'id' => $intentId],
            ['type' => 'operation_execution_task', 'id' => $taskId],
        ];
        foreach ($evidenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $evidenceId = (int)($row['id'] ?? 0);
            if ($evidenceId > 0) {
                $evidenceRefs[] = ['type' => 'operation_execution_evidence', 'id' => $evidenceId];
            }
        }
        foreach ($effectReviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            $reviewId = (int)($review['id'] ?? 0);
            if ($reviewId > 0) {
                $evidenceRefs[] = ['type' => 'operation_effect_review', 'id' => $reviewId];
            }
        }
        $originalSourceModule = trim((string)($intent['source_module'] ?? ''));
        $originalSourceRecordId = (int)($intent['source_record_id'] ?? 0);
        if ($originalSourceModule !== '' && $originalSourceRecordId > 0) {
            $evidenceRefs[] = ['type' => $this->safeReferenceType($originalSourceModule), 'id' => $originalSourceRecordId];
        }

        $truthContext = is_array($task['truth_context'] ?? null) ? $task['truth_context'] : [];
        $evidenceTruth = is_array($task['evidence_truth'] ?? null) ? $task['evidence_truth'] : [];
        $outcomeTruth = is_array($task['outcome_truth'] ?? null) ? $task['outcome_truth'] : [];
        $sopCandidate = is_array($task['sop_candidate'] ?? null) ? $task['sop_candidate'] : [];
        $activeEffectReview = is_array($task['active_effect_review'] ?? null)
            ? $task['active_effect_review']
            : [];
        $activeEffectReviewVerified = $activeEffectReview !== []
            && ($activeEffectReview['readback_verified'] ?? false) === true
            && ($activeEffectReview['outcome']['source_verified'] ?? false) === true
            && ($activeEffectReview['outcome']['outcome_verified'] ?? false) === true
            && ($activeEffectReview['causality_claimed'] ?? true) === false
            && (string)($activeEffectReview['result_status'] ?? '') === (string)($task['result_status'] ?? '')
            && (string)($activeEffectReview['result_summary'] ?? '') === (string)($task['result_summary'] ?? '');
        $requiresSeparateEffectReview = strtolower($originalSourceModule) === 'ota_diagnosis_saved';
        $qualityStatus = $this->qualityStatus($truthContext);
        if ($requiresSeparateEffectReview && !$activeEffectReviewVerified && $qualityStatus === 'verified') {
            $qualityStatus = 'partial';
        }
        $usageLevel = match ($qualityStatus) {
            'verified' => 'decision_support',
            'partial' => 'reference',
            default => 'archive_only',
        };
        $context = [
            'review_status' => strtolower(trim((string)($task['result_status'] ?? 'observing'))),
            'truth_status' => (string)($truthContext['status'] ?? 'unverified'),
            'truth_failure_reason' => $truthContext['failure_reason'] ?? null,
            'evidence_status' => (string)($evidenceTruth['status'] ?? 'unverified'),
            'evidence_count' => count($evidenceRows),
            'execution_evidence_count' => count((array)($task['execution_evidence'] ?? [])),
            'effect_source_evidence_count' => count((array)($task['effect_source_evidence'] ?? [])),
            'effect_review_count' => count($effectReviews),
            'active_effect_review_id' => (int)($activeEffectReview['id'] ?? 0),
            'active_effect_review_digest' => (string)($activeEffectReview['content_digest'] ?? ''),
            'separate_effect_review_required' => $requiresSeparateEffectReview,
            'separate_effect_review_verified' => !$requiresSeparateEffectReview || $activeEffectReviewVerified,
            'operator_attested' => ($evidenceTruth['operator_attested'] ?? false) === true,
            'source_verified' => ($evidenceTruth['source_verified'] ?? false) === true,
            'outcome_status' => (string)($outcomeTruth['status'] ?? 'unverified'),
            'outcome_verified' => ($outcomeTruth['outcome_verified'] ?? false) === true,
            'positive_outcome_verified' => ($outcomeTruth['positive_outcome_verified'] ?? false) === true,
            'sop_candidate_ready' => ($sopCandidate['ready'] ?? false) === true
                || (string)($sopCandidate['status'] ?? '') === 'candidate',
            'sop_candidate_status' => (string)($sopCandidate['status'] ?? 'not_ready'),
            'original_source_module' => $originalSourceModule,
            'original_source_record_id' => $originalSourceRecordId,
        ];
        $summary = mb_substr(trim((string)$task['result_summary']), 0, 2000);
        $titleParts = ['经营复盘'];
        if ($platform !== '') {
            $titleParts[] = strtoupper($platform);
        }
        $actionType = trim((string)($intent['action_type'] ?? $intent['object_type'] ?? ''));
        if ($actionType !== '') {
            $titleParts[] = mb_substr($actionType, 0, 60);
        }
        if ($businessDate !== null) {
            $titleParts[] = $businessDate;
        }
        $title = mb_substr(implode(' · ', $titleParts), 0, 191);
        $sourceScope = $this->sourceScope($intent, $platform);
        $digestPayload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_layer' => 'execution_review',
            'summary' => $summary,
            'business_date' => $businessDate,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'source_record_id' => $taskId,
            'evidence_refs' => $evidenceRefs,
            'quality_status' => $qualityStatus,
            'usage_level' => $usageLevel,
            'context' => $context,
        ];
        $contentDigest = hash('sha256', $this->encodeJson($digestPayload));

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_key' => 'execution-review:' . $taskId . ':' . substr($contentDigest, 0, 24),
            'memory_layer' => 'execution_review',
            'title' => $title,
            'summary' => $summary,
            'business_date' => $businessDate,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'source_module' => 'operation_execution',
            'source_record_type' => 'operation_execution_task',
            'source_record_id' => $taskId,
            'evidence_refs_json' => $this->encodeJson($evidenceRefs),
            'context_json' => $this->encodeJson($context),
            'quality_status' => $qualityStatus,
            'usage_level' => $usageLevel,
            'lifecycle_status' => 'active',
            'content_digest' => $contentDigest,
            'previous_memory_id' => null,
            'recorded_by' => max(0, $recordedBy),
            'occurred_at' => $this->occurredAt($task),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ];
    }

    /** @param list<int> $hotelIds */
    private function assertExecutionIdentity(
        array $task,
        array $intent,
        array $hotelIds,
        int $callerTenantId
    ): void {
        $taskHotelId = (int)($task['hotel_id'] ?? 0);
        $intentHotelId = (int)($intent['hotel_id'] ?? 0);
        $taskTenantId = (int)($task['tenant_id'] ?? 0);
        $intentTenantId = (int)($intent['tenant_id'] ?? 0);
        if ($taskHotelId <= 0
            || $taskHotelId !== $intentHotelId
            || !in_array($taskHotelId, $hotelIds, true)
        ) {
            throw new RuntimeException('经营记忆保存失败：执行任务酒店身份不一致');
        }
        if ($taskTenantId <= 0 || $taskTenantId !== $intentTenantId) {
            throw new RuntimeException('经营记忆保存失败：执行任务租户身份缺失或不一致');
        }
        if ($callerTenantId > 0 && $callerTenantId !== $taskTenantId) {
            throw new RuntimeException('无权保存该酒店经营记忆');
        }
    }

    /** @param array<string, mixed> $task @param array<string, mixed> $intent */
    private function assertExecutionReviewReady(array $task, array $intent): void
    {
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) === 'canonical_ota_investigation'
            || strtolower(trim((string)($task['execution_mode'] ?? ''))) === 'analysis_only'
            || strtolower(trim((string)($intent['status'] ?? ''))) === 'system_authorized_analysis'
        ) {
            throw new InvalidArgumentException('system-authorized analysis task cannot become an operating memory');
        }
        if (strtolower(trim((string)($task['status'] ?? ''))) !== 'executed') {
            throw new InvalidArgumentException('只有已执行并完成复盘的任务才能沉淀经营记忆');
        }
        $reviewStatus = strtolower(trim((string)($task['result_status'] ?? '')));
        if (!in_array($reviewStatus, ['observing', 'success', 'near_success', 'failed'], true)
            || trim((string)($task['result_summary'] ?? '')) === ''
        ) {
            throw new InvalidArgumentException('请先保存复盘结论，再沉淀经营记忆');
        }
    }

    /**
     * @param array<string, mixed> $authorization
     * @param array<string, mixed> $expected
     * @param list<int> $hotelIds
     * @return array<string, mixed>
     */
    private function authorizedExecutionReviewRecord(
        array $authorization,
        array $expected,
        array $hotelIds
    ): array {
        $task = is_array($authorization['task'] ?? null) ? $authorization['task'] : [];
        $intent = is_array($authorization['intent'] ?? null) ? $authorization['intent'] : [];
        $this->assertExecutionIdentity($task, $intent, $hotelIds, (int)$expected['tenant_id']);
        $this->assertExecutionReviewReady($task, $intent);
        $record = $this->buildExecutionReviewRecord($task, $intent, (int)$expected['recorded_by']);
        foreach (['tenant_id', 'hotel_id', 'memory_key', 'content_digest'] as $field) {
            if ((string)$record[$field] !== (string)$expected[$field]) {
                throw new InvalidArgumentException('execution task changed; refresh before saving operating memory');
            }
        }
        return $record;
    }

    /** @return array<string, mixed> */
    private function normalizeRow(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'source_record_id', 'previous_memory_id', 'recorded_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        $row['evidence_refs'] = $this->decodeJson($row['evidence_refs_json'] ?? null);
        $row['context'] = $this->decodeJson($row['context_json'] ?? null);
        unset($row['evidence_refs_json'], $row['context_json']);
        return $row;
    }

    /** @return array<string, mixed> */
    private function normalizeGrowthRow(array $row): array
    {
        if (!array_key_exists('context', $row)) {
            $row = $this->normalizeRow($row);
        }
        $context = is_array($row['context'] ?? null) ? $row['context'] : [];
        $eventKind = strtolower(trim((string)($context['event_kind'] ?? '')));
        if (!in_array($eventKind, self::GROWTH_EVENT_KINDS, true)) {
            $eventKind = match ((string)($row['memory_layer'] ?? '')) {
                'fact' => 'fact',
                'analysis', 'sop' => 'analysis',
                'judgement' => 'judgement',
                'decision' => 'decision',
                'execution_review' => 'review',
                'milestone' => 'milestone',
                default => 'analysis',
            };
        }
        $row['event_kind'] = $eventKind;
        $row['parent_memory_id'] = max(0, (int)($context['parent_memory_id'] ?? 0));
        $row['is_owner_annotation'] = (string)($context['relation_type'] ?? '') === 'owner_annotation';
        $row['is_milestone'] = $eventKind === 'milestone';
        $row['source_reference'] = [
            'module' => (string)($row['source_module'] ?? ''),
            'record_type' => (string)($row['source_record_type'] ?? ''),
            'record_id' => (int)($row['source_record_id'] ?? 0),
        ];
        return $row;
    }

    /**
     * Apply the normalized event-kind semantics in SQL so pagination is over
     * matches, not over an unrelated pre-filtered window.
     */
    private function applyGrowthEventKindFilter(object $query, string $eventKind): void
    {
        $driver = strtolower((string)Db::connect()->getConfig('type'));
        $jsonEvent = $driver === 'sqlite'
            ? "LOWER(TRIM(COALESCE(CASE WHEN json_valid(memory.context_json) "
                . "THEN json_extract(memory.context_json, '$.event_kind') ELSE '' END, '')))"
            : "LOWER(TRIM(COALESCE(CASE WHEN JSON_VALID(memory.context_json) "
                . "THEN JSON_UNQUOTE(JSON_EXTRACT(memory.context_json, '$.event_kind')) ELSE '' END, '')))";
        $validKinds = "'fact','analysis','judgement','decision','execution','review','milestone','manual_background'";
        $fallback = "CASE memory.memory_layer "
            . "WHEN 'fact' THEN 'fact' WHEN 'analysis' THEN 'analysis' WHEN 'sop' THEN 'analysis' "
            . "WHEN 'judgement' THEN 'judgement' WHEN 'decision' THEN 'decision' "
            . "WHEN 'execution_review' THEN 'review' WHEN 'milestone' THEN 'milestone' ELSE 'analysis' END";
        $normalized = "CASE WHEN {$jsonEvent} IN ({$validKinds}) THEN {$jsonEvent} ELSE {$fallback} END";
        $query->whereRaw("{$normalized} = :growth_event_kind", [
            'growth_event_kind' => $eventKind,
        ]);
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string, mixed> $record
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function persistGrowthRecord(
        array $record,
        int $callerTenantId,
        array $hotelIds,
        array $expected,
        bool $supersedePreviousVersion = false
    ): array {
        if (!$this->tableExists()) {
            throw new RuntimeException('经营记忆功能尚未启用：请先执行数据库迁移');
        }
        $hotelIds = $this->normalizeHotelIds($hotelIds);
        $result = null;
        for ($attempt = 1; $attempt <= self::IDEMPOTENCY_WRITE_ATTEMPTS; $attempt++) {
            try {
                $result = Db::transaction(function () use (
                    $record,
                    $callerTenantId,
                    $hotelIds,
                    $expected,
                    $supersedePreviousVersion
                ): array {
                    $writeRecord = $record;
                    $this->lockGrowthHotelScope($writeRecord, $callerTenantId, $hotelIds);

                    $sourceRecordId = (int)($writeRecord['source_record_id'] ?? 0);
                    if ((string)($writeRecord['source_record_type'] ?? '') === 'hotel_operating_memory'
                        && $sourceRecordId > 0
                    ) {
                        $parent = Db::name(self::TABLE)
                            ->where('id', $sourceRecordId)
                            ->where('tenant_id', (int)$writeRecord['tenant_id'])
                            ->where('hotel_id', (int)$writeRecord['hotel_id'])
                            ->whereNull('deleted_at')
                            ->lock(true)
                            ->find();
                        if (!is_array($parent)) {
                            throw new RuntimeException('growth archive parent is unavailable in the current tenant scope');
                        }
                    }

                    $duplicate = Db::name(self::TABLE)
                        ->where('tenant_id', (int)$writeRecord['tenant_id'])
                        ->where('hotel_id', (int)$writeRecord['hotel_id'])
                        ->where('memory_key', (string)$writeRecord['memory_key'])
                        ->whereNull('deleted_at')
                        ->lock(true)
                        ->find();
                    if (is_array($duplicate)) {
                        if (!hash_equals(
                            (string)$writeRecord['content_digest'],
                            (string)($duplicate['content_digest'] ?? '')
                        )) {
                            throw new RuntimeException('growth archive idempotency key conflicts with different content');
                        }
                        $memory = $this->assertGrowthReadback($duplicate, $writeRecord, $expected);
                        return ['memory' => $memory, 'created' => false];
                    }

                    $previous = null;
                    if ($supersedePreviousVersion) {
                        $previous = Db::name(self::TABLE)
                            ->where('tenant_id', (int)$writeRecord['tenant_id'])
                            ->where('hotel_id', (int)$writeRecord['hotel_id'])
                            ->where('memory_layer', (string)$writeRecord['memory_layer'])
                            ->where('source_record_type', (string)$writeRecord['source_record_type'])
                            ->where('source_record_id', (int)$writeRecord['source_record_id'])
                            ->where('lifecycle_status', 'active')
                            ->whereNull('deleted_at')
                            ->order('id', 'desc')
                            ->lock(true)
                            ->find();
                        if (is_array($previous)) {
                            $writeRecord['previous_memory_id'] = (int)$previous['id'];
                        }
                    }

                    $memoryId = (int)Db::name(self::TABLE)->insertGetId($writeRecord);
                    if ($memoryId <= 0) {
                        throw new RuntimeException('经营成长档案保存失败：未取得记录ID');
                    }
                    if (is_array($previous)) {
                        $updated = Db::name(self::TABLE)
                            ->where('id', (int)$previous['id'])
                            ->where('tenant_id', (int)$writeRecord['tenant_id'])
                            ->where('hotel_id', (int)$writeRecord['hotel_id'])
                            ->where('lifecycle_status', 'active')
                            ->update([
                                'lifecycle_status' => 'superseded',
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                        if ($updated !== 1) {
                            throw new RuntimeException('growth archive supersede did not update the locked version');
                        }
                    }

                    $readback = Db::name(self::TABLE)
                        ->where('id', $memoryId)
                        ->where('tenant_id', (int)$writeRecord['tenant_id'])
                        ->where('hotel_id', (int)$writeRecord['hotel_id'])
                        ->where('memory_key', (string)$writeRecord['memory_key'])
                        ->where('content_digest', (string)$writeRecord['content_digest'])
                        ->whereNull('deleted_at')
                        ->find();
                    if (!is_array($readback)) {
                        throw new RuntimeException('经营成长档案已写入但严格回读校验失败');
                    }
                    $memory = $this->assertGrowthReadback($readback, $writeRecord, $expected);
                    return ['memory' => $memory, 'created' => true];
                });
                break;
            } catch (\Throwable $exception) {
                if ($this->idempotencyConflictKind($exception) === null
                    || $attempt >= self::IDEMPOTENCY_WRITE_ATTEMPTS
                ) {
                    throw $exception;
                }
                usleep(self::IDEMPOTENCY_RETRY_DELAY_MICROSECONDS * $attempt);
            }
        }
        if (!is_array($result) || !is_array($result['memory'] ?? null)) {
            throw new RuntimeException('经营成长档案保存失败：并发写入未收敛');
        }

        return [
            'memory' => $result['memory'],
            'created' => (bool)$result['created'],
            'persistence_status' => 'readback_verified',
            'write_boundaries' => [
                'ota_write' => false,
                'external_message' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $record @param list<int> $hotelIds */
    private function lockGrowthHotelScope(array $record, int $callerTenantId, array $hotelIds): void
    {
        $hotelId = (int)($record['hotel_id'] ?? 0);
        $tenantId = (int)($record['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('growth archive write is outside the permitted hotel scope');
        }
        $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || ($callerTenantId > 0 && $callerTenantId !== $tenantId)
        ) {
            throw new RuntimeException('growth archive hotel is unavailable in the current tenant scope');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $record
     * @param array<string,mixed> $expected
     * @return array<string,mixed>
     */
    private function assertGrowthReadback(array $row, array $record, array $expected): array
    {
        $memory = $this->normalizeGrowthRow($row);
        foreach ($expected as $field => $value) {
            $actual = $field === 'event_kind' ? ($memory['event_kind'] ?? null) : ($memory[$field] ?? null);
            if ((string)$actual !== (string)$value) {
                throw new RuntimeException('经营成长档案已写入但严格回读校验失败：' . $field);
            }
        }
        if ((int)($memory['id'] ?? 0) <= 0
            || (int)($memory['tenant_id'] ?? 0) !== (int)$record['tenant_id']
            || (int)($memory['hotel_id'] ?? 0) !== (int)$record['hotel_id']
            || (string)($memory['memory_key'] ?? '') !== (string)$record['memory_key']
            || (string)($memory['content_digest'] ?? '') !== (string)$record['content_digest']
        ) {
            throw new RuntimeException('经营成长档案已写入但严格回读校验失败');
        }
        return $memory;
    }

    /**
     * Converge only known idempotency races. Every recovery read happens after
     * Db::transaction has rolled back, and is scoped to the exact unique key.
     *
     * @param array<string, mixed> $record
     * @param callable():array{id:int,created:bool} $transactionWrite
     * @param null|callable(array<string,mixed>):array{id:int,created:bool} $authorizeExisting
     * @return array{id:int,created:bool}
     */
    private function convergeIdempotentWrite(
        array $record,
        callable $transactionWrite,
        ?callable $authorizeExisting = null
    ): array
    {
        for ($attempt = 1; $attempt <= self::IDEMPOTENCY_WRITE_ATTEMPTS; $attempt++) {
            $existing = $this->findIdempotentRecord($record);
            if (is_array($existing)) {
                return $authorizeExisting !== null
                    ? $authorizeExisting($existing)
                    : ['id' => (int)$existing['id'], 'created' => false];
            }

            try {
                $write = $transactionWrite();
                if ((int)($write['id'] ?? 0) <= 0 || !array_key_exists('created', $write)) {
                    throw new RuntimeException('经营记忆保存失败：事务结果无效');
                }
                return ['id' => (int)$write['id'], 'created' => (bool)$write['created']];
            } catch (\Throwable $exception) {
                $conflictKind = $this->idempotencyConflictKind($exception);
                if ($conflictKind === null) {
                    throw $exception;
                }

                $winner = $this->awaitIdempotentRecord($record);
                if (is_array($winner)) {
                    return $authorizeExisting !== null
                        ? $authorizeExisting($winner)
                        : ['id' => (int)$winner['id'], 'created' => false];
                }
                if ($attempt >= self::IDEMPOTENCY_WRITE_ATTEMPTS) {
                    throw $exception;
                }

                usleep(self::IDEMPOTENCY_RETRY_DELAY_MICROSECONDS * $attempt);
            }
        }

        throw new RuntimeException('经营记忆保存失败：并发写入未收敛');
    }

    /** @param array<string, mixed> $record @return array<string, mixed>|null */
    private function findIdempotentRecord(array $record): ?array
    {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('memory_key', (string)$record['memory_key'])
            ->whereNull('deleted_at')
            ->find();

        return is_array($row) && (int)($row['id'] ?? 0) > 0 ? $row : null;
    }

    /** @param array<string, mixed> $record @return array<string, mixed>|null */
    private function awaitIdempotentRecord(array $record): ?array
    {
        for ($attempt = 1; $attempt <= self::IDEMPOTENCY_READBACK_ATTEMPTS; $attempt++) {
            $row = $this->findIdempotentRecord($record);
            if (is_array($row)) {
                return $row;
            }
            if ($attempt < self::IDEMPOTENCY_READBACK_ATTEMPTS) {
                usleep(self::IDEMPOTENCY_RETRY_DELAY_MICROSECONDS * $attempt);
            }
        }

        return null;
    }

    private function idempotencyConflictKind(\Throwable $exception): ?string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \PDOException
                && !$current instanceof \think\db\exception\PDOException
            ) {
                continue;
            }

            $sqlState = '';
            $driverCode = 0;
            if ($current instanceof \PDOException && is_array($current->errorInfo ?? null)) {
                $sqlState = strtoupper(trim((string)($current->errorInfo[0] ?? '')));
                $driverCode = (int)($current->errorInfo[1] ?? 0);
            } elseif (method_exists($current, 'getData')) {
                $errorInfo = $current->getData()['PDO Error Info'] ?? [];
                if (is_array($errorInfo)) {
                    $sqlState = strtoupper(trim((string)($errorInfo['SQLSTATE'] ?? '')));
                    $driverCode = (int)($errorInfo['Driver Error Code'] ?? 0);
                }
            }

            $message = strtolower($current->getMessage());
            if ($sqlState === '40001'
                || $driverCode === 1213
                || str_contains($message, 'sqlstate[40001]')
                || str_contains($message, 'deadlock found when trying to get lock')
            ) {
                return 'transaction_retry';
            }

            $hasDuplicateMarker = str_contains($message, 'duplicate entry')
                || str_contains($message, 'unique constraint failed')
                || str_contains($message, 'uniq_operating_memory_identity');
            if ($driverCode === 1062 || ($sqlState === '23000' && $hasDuplicateMarker)) {
                return 'duplicate_key';
            }
        }

        return null;
    }

    /** @param list<int> $hotelIds */
    private function assertGrowthWriteScope(
        int $callerTenantId,
        array $hotelIds,
        int $hotelId,
        int $recordedBy
    ): int {
        if (!$this->tableExists()) {
            throw new RuntimeException('经营记忆功能尚未启用：请先执行数据库迁移');
        }
        if ($recordedBy <= 0) {
            throw new RuntimeException('经营成长档案写入缺少有效操作者');
        }
        $hotelIds = $this->normalizeHotelIds($hotelIds);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权保存该酒店经营档案');
        }
        try {
            $hotelTenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        } catch (\Throwable) {
            throw new RuntimeException('酒店租户映射不可用，无法安全保存经营档案');
        }
        if ($hotelTenantId <= 0) {
            throw new RuntimeException('酒店租户身份缺失，无法安全保存经营档案');
        }
        if ($callerTenantId > 0 && $callerTenantId !== $hotelTenantId) {
            throw new RuntimeException('无权保存该酒店经营档案');
        }
        return $hotelTenantId;
    }

    private function manualMemoryLayer(string $eventKind): string
    {
        return match ($eventKind) {
            'manual_background', 'fact' => 'fact',
            'analysis' => 'analysis',
            'judgement' => 'judgement',
            'decision' => 'decision',
            'execution', 'review' => 'execution_review',
            default => throw new InvalidArgumentException('经营事件类型无效'),
        };
    }

    /** @return array{0:string,1:string} */
    private function normalizeManualSource(mixed $platformInput, mixed $sourceScopeInput): array
    {
        $platform = strtolower(trim((string)$platformInput));
        $sourceScope = strtolower(trim((string)$sourceScopeInput));
        if (!in_array($sourceScope, self::SOURCE_SCOPES, true)) {
            throw new InvalidArgumentException('数据范围无效');
        }
        $otaPlatforms = ['ctrip', 'meituan', 'all_ota'];
        if ($sourceScope === 'ota_channel' && !in_array($platform, $otaPlatforms, true)) {
            throw new InvalidArgumentException('OTA渠道记录必须明确携程、美团或全部OTA来源');
        }
        if (in_array($platform, $otaPlatforms, true) && $sourceScope !== 'ota_channel') {
            throw new InvalidArgumentException('OTA渠道来源不能升级为全酒店或其他经营范围');
        }
        if ($platform === 'pms' && $sourceScope !== 'pms') {
            throw new InvalidArgumentException('PMS来源必须保持PMS数据范围');
        }
        if ($sourceScope === 'pms' && $platform !== 'pms') {
            throw new InvalidArgumentException('PMS数据范围必须明确PMS来源');
        }
        if ($sourceScope === 'whole_hotel' && !in_array($platform, ['', 'manual', 'other'], true)) {
            throw new InvalidArgumentException('全酒店人工背景不能绑定为OTA渠道事实');
        }
        if ($sourceScope === 'manual_background' && !in_array($platform, ['', 'manual', 'other'], true)) {
            throw new InvalidArgumentException('人工背景不能冒充平台来源');
        }
        if (!in_array($platform, ['', 'ctrip', 'meituan', 'all_ota', 'pms', 'manual', 'other'], true)) {
            throw new InvalidArgumentException('平台来源无效');
        }
        return [$platform, $sourceScope];
    }

    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            throw new InvalidArgumentException($label . '不能为空');
        }
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . '不能超过' . $maxLength . '字');
        }
        return $text;
    }

    private function optionalDate(mixed $value, string $label): ?string
    {
        $date = trim((string)$value);
        if ($date === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException($label . '格式不正确');
        }
        return $date;
    }

    private function requiredOccurredAt(mixed $value): string
    {
        $occurredAt = trim((string)$value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d\\TH:i:s', '!Y-m-d\\TH:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $occurredAt);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('发生时间格式不正确');
    }

    /** @return list<array<string, int|string>> */
    private function normalizeEvidenceRefs(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('证据引用必须是数组');
        }
        $refs = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('证据引用格式无效');
            }
            $type = $this->safeReferenceType((string)($entry['type'] ?? ''));
            $id = (int)($entry['id'] ?? 0);
            $url = trim((string)($entry['url'] ?? ''));
            if ($id <= 0 && ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false)) {
                throw new InvalidArgumentException('证据引用必须包含有效记录ID或链接');
            }
            $ref = ['type' => $type];
            if ($id > 0) {
                $ref['id'] = $id;
            }
            if ($url !== '') {
                if (!str_starts_with(strtolower($url), 'https://') && !str_starts_with(strtolower($url), 'http://')) {
                    throw new InvalidArgumentException('证据链接只允许HTTP或HTTPS');
                }
                $ref['url'] = mb_substr($url, 0, 1000);
            }
            $refs[] = $ref;
            if (count($refs) >= 20) {
                break;
            }
        }
        return $refs;
    }

    private function idempotencyKey(mixed $value, string $contentDigest): string
    {
        $key = trim((string)$value);
        if ($key === '') {
            return substr($contentDigest, 0, 32);
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D', $key) !== 1) {
            throw new InvalidArgumentException('client_request_id格式无效');
        }
        return hash('sha256', $key);
    }

    /** @return array<string, mixed> */
    private function missingMigrationResult(): array
    {
        return [
            'data_status' => 'migration_required',
            'list' => [],
            'count' => 0,
            'matched_total' => 0,
            'returned_count' => 0,
            'truncated' => false,
            'supported_layers' => self::MEMORY_LAYERS,
            'supported_usage_levels' => self::USAGE_LEVELS,
            'data_gaps' => [[
                'code' => 'operating_memory_table_missing',
                'message' => '经营记忆表尚未创建，请先执行本地数据库迁移',
            ]],
            'source_policy' => 'reference_existing_facts_without_ota_write',
        ];
    }

    /** @param list<int> $hotelIds @return list<int> */
    private function normalizeHotelIds(array $hotelIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
    }

    /** @param list<int> $hotelIds @return list<int> */
    private function currentTenantHotelIds(
        int $callerTenantId,
        array $hotelIds,
        ?int $requiredHotelId = null
    ): array {
        try {
            $query = Db::name('hotels')->whereIn('id', $hotelIds);
            if ($callerTenantId > 0) {
                $query->where('tenant_id', $callerTenantId);
            }
            $currentHotelIds = $this->normalizeHotelIds($query->column('id'));
        } catch (\Throwable) {
            throw new RuntimeException('current hotel tenant scope is unavailable');
        }
        if ($requiredHotelId !== null && !in_array($requiredHotelId, $currentHotelIds, true)) {
            throw new RuntimeException('operating memory not found in the current tenant scope');
        }
        return $currentHotelIds;
    }

    private function qualityStatus(array $truthContext): string
    {
        $status = strtolower(trim((string)($truthContext['status'] ?? 'unverified')));
        return in_array($status, ['verified', 'partial'], true) ? $status : 'unverified';
    }

    private function businessDate(array $intent): ?string
    {
        foreach (['date_end', 'date_start'] as $field) {
            $value = trim((string)($intent[$field] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                return $value;
            }
        }
        return null;
    }

    private function occurredAt(array $task): ?string
    {
        foreach (['updated_at', 'executed_at', 'created_at'] as $field) {
            $value = trim((string)($task[$field] ?? ''));
            if ($value !== '' && strtotime($value) !== false) {
                return date('Y-m-d H:i:s', (int)strtotime($value));
            }
        }
        return null;
    }

    private function sourceScope(array $intent, string $platform): string
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $candidate = $evidence['source_scope'] ?? '';
        if (is_string($candidate) && trim($candidate) !== '') {
            return mb_substr(strtolower(trim($candidate)), 0, 80);
        }
        return $platform !== '' ? 'ota_channel' : 'operation_execution';
    }

    private function safeReferenceType(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value) ?? '';
        return mb_substr(trim($value, '_'), 0, 80) ?: 'source_record';
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
