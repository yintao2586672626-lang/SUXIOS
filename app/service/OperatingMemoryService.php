<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class OperatingMemoryService
{
    public const TABLE = 'hotel_operating_memories';

    /** @var list<string> */
    private const MEMORY_LAYERS = ['fact', 'analysis', 'decision', 'execution_review', 'sop'];

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

        $query = Db::name(self::TABLE)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($callerTenantId > 0) {
            $query->where('tenant_id', $callerTenantId);
        }
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
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
            $query->where($field, $value);
        }

        $rows = $query
            ->order('occurred_at', 'desc')
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        return [
            'data_status' => 'ok',
            'list' => array_map([$this, 'normalizeRow'], $rows),
            'count' => count($rows),
            'supported_layers' => self::MEMORY_LAYERS,
            'supported_usage_levels' => self::USAGE_LEVELS,
            'data_gaps' => [],
            'source_policy' => 'reference_existing_facts_without_ota_write',
        ];
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
        $query = Db::name(self::TABLE)
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($callerTenantId > 0) {
            $query->where('tenant_id', $callerTenantId);
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

        if (strtolower(trim((string)($task['status'] ?? ''))) !== 'executed') {
            throw new InvalidArgumentException('只有已执行并完成复盘的任务才能沉淀经营记忆');
        }
        $reviewStatus = strtolower(trim((string)($task['result_status'] ?? '')));
        $reviewSummary = trim((string)($task['result_summary'] ?? ''));
        if (!in_array($reviewStatus, ['observing', 'success', 'near_success', 'failed'], true)
            || $reviewSummary === ''
        ) {
            throw new InvalidArgumentException('请先保存复盘结论，再沉淀经营记忆');
        }

        $record = $this->buildExecutionReviewRecord($task, $intent, $recordedBy);
        $existing = Db::name(self::TABLE)
            ->where('tenant_id', (int)$record['tenant_id'])
            ->where('hotel_id', (int)$record['hotel_id'])
            ->where('memory_key', (string)$record['memory_key'])
            ->whereNull('deleted_at')
            ->find();

        $created = false;
        if (is_array($existing)) {
            $memoryId = (int)$existing['id'];
        } else {
            $memoryId = Db::transaction(function () use ($record): int {
                $sameContent = Db::name(self::TABLE)
                    ->where('tenant_id', (int)$record['tenant_id'])
                    ->where('hotel_id', (int)$record['hotel_id'])
                    ->where('memory_key', (string)$record['memory_key'])
                    ->whereNull('deleted_at')
                    ->lock(true)
                    ->find();
                if (is_array($sameContent)) {
                    return (int)$sameContent['id'];
                }

                $previous = Db::name(self::TABLE)
                    ->where('tenant_id', (int)$record['tenant_id'])
                    ->where('hotel_id', (int)$record['hotel_id'])
                    ->where('memory_layer', 'execution_review')
                    ->where('source_record_type', 'operation_execution_task')
                    ->where('source_record_id', (int)$record['source_record_id'])
                    ->whereNull('deleted_at')
                    ->order('id', 'desc')
                    ->lock(true)
                    ->find();
                if (is_array($previous)) {
                    $record['previous_memory_id'] = (int)$previous['id'];
                }

                $id = (int)Db::name(self::TABLE)->insertGetId($record);
                if ($id <= 0) {
                    throw new RuntimeException('经营记忆保存失败：未取得记录ID');
                }
                if (is_array($previous)) {
                    Db::name(self::TABLE)
                        ->where('id', (int)$previous['id'])
                        ->where('tenant_id', (int)$record['tenant_id'])
                        ->where('hotel_id', (int)$record['hotel_id'])
                        ->update([
                            'lifecycle_status' => 'superseded',
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }

                return $id;
            });
            $created = true;
        }

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
        $originalSourceModule = trim((string)($intent['source_module'] ?? ''));
        $originalSourceRecordId = (int)($intent['source_record_id'] ?? 0);
        if ($originalSourceModule !== '' && $originalSourceRecordId > 0) {
            $evidenceRefs[] = ['type' => $this->safeReferenceType($originalSourceModule), 'id' => $originalSourceRecordId];
        }

        $truthContext = is_array($task['truth_context'] ?? null) ? $task['truth_context'] : [];
        $evidenceTruth = is_array($task['evidence_truth'] ?? null) ? $task['evidence_truth'] : [];
        $outcomeTruth = is_array($task['outcome_truth'] ?? null) ? $task['outcome_truth'] : [];
        $sopCandidate = is_array($task['sop_candidate'] ?? null) ? $task['sop_candidate'] : [];
        $qualityStatus = $this->qualityStatus($truthContext);
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
    private function missingMigrationResult(): array
    {
        return [
            'data_status' => 'migration_required',
            'list' => [],
            'count' => 0,
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
