<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Immutable operating SOP versions and same-tenant replication drafts.
 *
 * A single review may create a candidate. Verification needs at least three
 * independent, positive, source-readback execution memories plus an explicit
 * human decision. Replication never copies source-hotel facts and never marks
 * the target as verified or executed.
 */
final class OperatingSopService
{
    public const VERSION_TABLE = 'hotel_operating_sop_versions';
    public const REPLICATION_TABLE = 'hotel_operating_sop_replications';
    public const CONTRACT_VERSION = 'hotel_operating_sop.v1';
    private const MIN_VERIFICATION_MEMORIES = 3;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createCandidate(
        int $tenantId,
        int $hotelId,
        array $sourceMemoryIds,
        array $input,
        int $createdBy
    ): array {
        $this->assertTablesReady();
        $this->assertHotelIdentity($tenantId, $hotelId);
        $sourceMemoryIds = $this->ids($sourceMemoryIds);
        if ($sourceMemoryIds === []) {
            throw new InvalidArgumentException('候选SOP至少需要一条执行复盘记忆');
        }
        $memories = $this->memories($tenantId, $hotelId, $sourceMemoryIds);
        foreach ($memories as $memory) {
            $context = $this->decode($memory['context_json'] ?? null);
            if (($memory['memory_layer'] ?? '') !== 'execution_review'
                || ($memory['lifecycle_status'] ?? '') !== 'active'
                || ($memory['quality_status'] ?? '') !== 'verified'
                || ($memory['usage_level'] ?? '') !== 'decision_support'
                || ($context['outcome_verified'] ?? false) !== true
                || ($context['positive_outcome_verified'] ?? false) !== true
                || ($context['sop_candidate_ready'] ?? false) !== true
            ) {
                throw new InvalidArgumentException('候选SOP只能引用当前有效、正向且已核验的执行复盘记忆');
            }
        }

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('SOP标题不能为空且不能超过191字');
        }
        $objective = mb_substr(trim((string)($input['objective'] ?? '')), 0, 1000);
        $steps = $this->textList($input['steps'] ?? [], 'SOP步骤', true);
        $stopConditions = $this->textList($input['stop_conditions'] ?? [], '停止条件', false);
        $platform = strtolower(trim((string)($memories[0]['platform'] ?? '')));
        $sourceScope = strtolower(trim((string)($memories[0]['source_scope'] ?? '')));
        foreach ($memories as $memory) {
            if (strtolower(trim((string)($memory['platform'] ?? ''))) !== $platform
                || strtolower(trim((string)($memory['source_scope'] ?? ''))) !== $sourceScope
            ) {
                throw new InvalidArgumentException('候选SOP来源记忆的平台或事实范围不一致');
            }
        }
        $businessDates = array_values(array_unique(array_filter(array_map(
            static fn(array $memory): string => trim((string)($memory['business_date'] ?? '')),
            $memories
        ), static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1)));
        sort($businessDates, SORT_STRING);
        $applicableDataTypes = $this->normalizedScopeList($input['applicable_data_types'] ?? []);
        $metricDefinitions = $this->textList($input['metric_definitions'] ?? [], '指标定义', false);
        $scope = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'evidence_date_start' => $businessDates[0] ?? null,
            'evidence_date_end' => $businessDates === [] ? null : $businessDates[array_key_last($businessDates)],
            'applicable_data_types' => $applicableDataTypes,
            'metric_definitions' => $metricDefinitions,
            'replication_scope' => 'same_tenant_draft_only',
        ];
        $sopKey = $this->sopKey($tenantId, $hotelId, $title, $scope);
        $evidenceRefs = array_map(
            static fn(int $id): string => 'hotel_operating_memories#' . $id,
            $sourceMemoryIds
        );

        return $this->createVersion([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'sop_key' => $sopKey,
            'title' => $title,
            'objective' => $objective,
            'steps' => $steps,
            'stop_conditions' => $stopConditions,
            'scope' => $scope,
            'source_memory_ids' => $sourceMemoryIds,
            'evidence_refs' => $evidenceRefs,
            'validation_status' => 'candidate',
            'validation_note' => '',
            'created_by' => max(0, $createdBy),
            'validated_by' => 0,
            'validated_at' => null,
            'expected_candidate_id' => 0,
            'expected_candidate_digest' => '',
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function validateVersion(
        int $versionId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $validatedBy
    ): array {
        $current = $this->readVersion($versionId, $tenantId, $hotelIds);
        if (($current['validation_status'] ?? '') !== 'candidate'
            || ($current['lifecycle_status'] ?? '') !== 'active'
        ) {
            throw new InvalidArgumentException('只有当前有效的候选SOP可以进入人工验证');
        }
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if (!in_array($decision, ['verify', 'reject'], true)) {
            throw new InvalidArgumentException('SOP验证决定必须是 verify 或 reject');
        }
        $note = mb_substr(trim((string)($input['validation_note'] ?? '')), 0, 1000);
        if ($note === '') {
            throw new InvalidArgumentException('SOP人工验证必须填写验证说明');
        }

        $sourceMemoryIds = $this->ids($current['source_memory_ids'] ?? []);
        $evidenceRefs = is_array($current['evidence_refs'] ?? null) ? $current['evidence_refs'] : [];
        $scope = is_array($current['scope'] ?? null) ? $current['scope'] : [];
        if ($decision === 'verify') {
            $sourceMemoryIds = $this->ids(array_merge(
                $sourceMemoryIds,
                is_array($input['evidence_memory_ids'] ?? null) ? $input['evidence_memory_ids'] : []
            ));
            $memories = $this->verificationMemories(
                (int)$current['tenant_id'],
                (int)$current['hotel_id'],
                $sourceMemoryIds,
                is_array($current['scope'] ?? null) ? $current['scope'] : []
            );
            $sourceMemoryIds = array_map(static fn(array $row): int => (int)$row['id'], $memories);
            $evidenceRefs = array_map(
                static fn(int $id): string => 'hotel_operating_memories#' . $id,
                $sourceMemoryIds
            );
            $businessDates = array_values(array_unique(array_filter(array_map(
                static fn(array $memory): string => trim((string)($memory['business_date'] ?? '')),
                $memories
            ), static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1)));
            sort($businessDates, SORT_STRING);
            $scope['evidence_date_start'] = $businessDates[0] ?? null;
            $scope['evidence_date_end'] = $businessDates === [] ? null : $businessDates[array_key_last($businessDates)];
        }

        return $this->createVersion([
            'tenant_id' => (int)$current['tenant_id'],
            'hotel_id' => (int)$current['hotel_id'],
            'sop_key' => (string)$current['sop_key'],
            'title' => (string)$current['title'],
            'objective' => (string)$current['objective'],
            'steps' => $current['steps'],
            'stop_conditions' => $current['stop_conditions'],
            'scope' => $scope,
            'source_memory_ids' => $sourceMemoryIds,
            'evidence_refs' => $evidenceRefs,
            'validation_status' => $decision === 'verify' ? 'verified' : 'rejected',
            'validation_note' => $note,
            'created_by' => max(0, $validatedBy),
            'validated_by' => max(0, $validatedBy),
            'validated_at' => date('Y-m-d H:i:s'),
            'expected_candidate_id' => (int)$current['id'],
            'expected_candidate_digest' => (string)$current['content_digest'],
        ]);
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function readVersion(int $id, int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->ids($hotelIds);
        if ($id <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('SOP版本ID或酒店范围无效');
        }
        $query = Db::name(self::VERSION_TABLE)
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('operating SOP version not found');
        }
        return $this->normalizeVersion($row);
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function listVersions(int $tenantId, array $hotelIds, ?int $hotelId = null): array
    {
        if (!$this->tableExists(self::VERSION_TABLE)) {
            return [
                'data_status' => 'migration_required',
                'list' => [],
                'count' => 0,
                'data_gaps' => [['code' => 'operating_sop_table_missing']],
            ];
        }
        $hotelIds = $this->ids($hotelIds);
        if ($hotelIds === []) {
            throw new InvalidArgumentException('SOP查询缺少可访问酒店');
        }
        if ($hotelId !== null && !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权查看该酒店SOP');
        }
        $query = Db::name(self::VERSION_TABLE)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
        }
        $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        return [
            'data_status' => 'ok',
            'list' => array_map([$this, 'normalizeVersion'], $rows),
            'count' => count($rows),
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    public function replicate(
        int $versionId,
        int $tenantId,
        array $accessibleHotelIds,
        int $targetHotelId,
        int $createdBy
    ): array {
        $this->assertTablesReady();
        $accessibleHotelIds = $this->ids($accessibleHotelIds);
        $source = $this->readVersion($versionId, $tenantId, $accessibleHotelIds);
        $sourceHotelId = (int)$source['hotel_id'];
        if (($source['validation_status'] ?? '') !== 'verified'
            || ($source['lifecycle_status'] ?? '') !== 'active'
        ) {
            throw new InvalidArgumentException('只有当前有效且已人工验证的SOP才能创建跨店复制草稿');
        }
        if ($targetHotelId <= 0 || $targetHotelId === $sourceHotelId) {
            throw new InvalidArgumentException('跨店复制必须选择另一家目标酒店');
        }
        if (!in_array($sourceHotelId, $accessibleHotelIds, true)
            || !in_array($targetHotelId, $accessibleHotelIds, true)
        ) {
            throw new RuntimeException('跨店复制来源或目标酒店不在当前可访问范围');
        }
        $this->assertHotelIdentity($tenantId, $targetHotelId);

        $scope = is_array($source['scope'] ?? null) ? $source['scope'] : [];
        $targetFactScope = $this->targetFactScope($tenantId, $targetHotelId, $scope);
        $targetFactRefs = $targetFactScope['refs'];
        $scopeReady = $targetFactScope['scope_ready'];
        $hasTargetFacts = $targetFactRefs !== [];
        if (!$scopeReady) {
            $status = 'blocked_source_scope_incomplete';
            $targetValidationStatus = 'blocked_scope_comparability_unconfirmed';
            $dataGaps = $targetFactScope['data_gaps'];
        } elseif (!$hasTargetFacts) {
            $status = 'blocked_missing_target_facts';
            $targetValidationStatus = 'blocked_missing_target_facts';
            $dataGaps = [[
                'code' => 'target_hotel_comparable_fact_missing',
                'message' => '目标酒店缺少同平台、同证据日期范围和同数据类型的严格回读事实，复制草稿不能进入验证。',
            ]];
        } else {
            $status = 'draft_pending_target_validation';
            $targetValidationStatus = 'facts_available_review_required';
            $dataGaps = [];
        }
        $draft = [
            'contract_version' => self::CONTRACT_VERSION,
            'source_sop_version_id' => $versionId,
            'source_hotel_id' => $sourceHotelId,
            'target_hotel_id' => $targetHotelId,
            'title' => (string)$source['title'],
            'objective' => (string)$source['objective'],
            'steps' => $source['steps'],
            'stop_conditions' => $source['stop_conditions'],
            'scope' => array_merge($scope, [
                'hotel_id' => $targetHotelId,
                'source_hotel_id' => $sourceHotelId,
                'target_validation_required' => true,
            ]),
            'source_evidence_policy' => 'reference_only_not_reused_as_target_fact',
            'target_fact_refs' => $targetFactRefs,
            'target_fact_comparison_contract' => $targetFactScope['comparison_contract'],
            'boundaries' => [
                'status_is_draft' => true,
                'target_verified' => false,
                'automatic_execution' => false,
                'ota_write' => false,
                'external_message' => false,
            ],
        ];
        $digest = $this->digest([$draft, $status, $targetValidationStatus, $dataGaps]);
        $existing = Db::name(self::REPLICATION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('source_sop_version_id', $versionId)
            ->where('target_hotel_id', $targetHotelId)
            ->whereNull('deleted_at')
            ->find();
        $created = false;
        if (is_array($existing)) {
            $id = (int)$existing['id'];
            if (!hash_equals((string)($existing['content_digest'] ?? ''), $digest)) {
                Db::name(self::REPLICATION_TABLE)->where('id', $id)->update([
                    'status' => $status,
                    'target_validation_status' => $targetValidationStatus,
                    'draft_json' => $this->encode($draft),
                    'target_fact_refs_json' => $this->encode($targetFactRefs),
                    'data_gaps_json' => $this->encode($dataGaps),
                    'content_digest' => $digest,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            $now = date('Y-m-d H:i:s');
            try {
                $id = (int)Db::name(self::REPLICATION_TABLE)->insertGetId([
                    'tenant_id' => $tenantId,
                    'source_sop_version_id' => $versionId,
                    'source_hotel_id' => $sourceHotelId,
                    'target_hotel_id' => $targetHotelId,
                    'status' => $status,
                    'target_validation_status' => $targetValidationStatus,
                    'draft_json' => $this->encode($draft),
                    'target_fact_refs_json' => $this->encode($targetFactRefs),
                    'data_gaps_json' => $this->encode($dataGaps),
                    'content_digest' => $digest,
                    'created_by' => max(0, $createdBy),
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                if ($id <= 0) {
                    throw new RuntimeException('跨店复制草稿保存失败：未取得记录ID');
                }
                $created = true;
            } catch (\Throwable $e) {
                // Recover an identical unique-key race by reading the exact
                // tenant/source-version/target row. Other failures remain visible.
                $concurrent = Db::name(self::REPLICATION_TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('source_sop_version_id', $versionId)
                    ->where('target_hotel_id', $targetHotelId)
                    ->whereNull('deleted_at')
                    ->find();
                if (!is_array($concurrent)) {
                    throw $e;
                }
                $id = (int)$concurrent['id'];
                if (!hash_equals((string)($concurrent['content_digest'] ?? ''), $digest)) {
                    Db::name(self::REPLICATION_TABLE)->where('id', $id)->update([
                        'status' => $status,
                        'target_validation_status' => $targetValidationStatus,
                        'draft_json' => $this->encode($draft),
                        'target_fact_refs_json' => $this->encode($targetFactRefs),
                        'data_gaps_json' => $this->encode($dataGaps),
                        'content_digest' => $digest,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
        $replication = $this->readReplication($id, $tenantId, $accessibleHotelIds);
        if ((int)$replication['source_sop_version_id'] !== $versionId
            || (int)$replication['target_hotel_id'] !== $targetHotelId
            || (string)$replication['content_digest'] !== $digest
        ) {
            throw new RuntimeException('跨店复制草稿已写入但严格回读校验失败');
        }
        return [
            'replication' => $replication,
            'created' => $created,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => $draft['boundaries'],
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function readReplication(int $id, int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->ids($hotelIds);
        $query = Db::name(self::REPLICATION_TABLE)
            ->where('id', $id)
            ->whereIn('source_hotel_id', $hotelIds)
            ->whereIn('target_hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('operating SOP replication not found');
        }
        return $this->normalizeReplication($row);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function createVersion(array $record): array
    {
        $result = Db::transaction(function () use ($record): array {
            $validationStatus = (string)($record['validation_status'] ?? '');
            if (!in_array($validationStatus, ['candidate', 'verified', 'rejected'], true)) {
                throw new InvalidArgumentException('SOP版本状态无效');
            }
            $expectedCandidateId = (int)($record['expected_candidate_id'] ?? 0);
            if ($expectedCandidateId > 0) {
                $lockedCandidate = Db::name(self::VERSION_TABLE)
                    ->where('id', $expectedCandidateId)
                    ->where('tenant_id', (int)$record['tenant_id'])
                    ->where('hotel_id', (int)$record['hotel_id'])
                    ->where('sop_key', (string)$record['sop_key'])
                    ->where('validation_status', 'candidate')
                    ->where('lifecycle_status', 'active')
                    ->whereNull('deleted_at')
                    ->lock(true)
                    ->find();
                if (!is_array($lockedCandidate)
                    || !hash_equals(
                        (string)($lockedCandidate['content_digest'] ?? ''),
                        (string)($record['expected_candidate_digest'] ?? '')
                    )
                ) {
                    throw new InvalidArgumentException('候选SOP已被处理或已不是当前有效候选，请刷新后重试');
                }
            }

            $previous = Db::name(self::VERSION_TABLE)
                ->where('tenant_id', (int)$record['tenant_id'])
                ->where('hotel_id', (int)$record['hotel_id'])
                ->where('sop_key', (string)$record['sop_key'])
                ->whereNull('deleted_at')
                ->order('version_no', 'desc')
                ->lock(true)
                ->find();
            $versionNo = is_array($previous) ? (int)$previous['version_no'] + 1 : 1;
            $previousId = is_array($previous) ? (int)$previous['id'] : null;
            $digest = $this->digest($this->versionContent($record));
            if (is_array($previous) && hash_equals((string)$previous['content_digest'], $digest)) {
                return [
                    'id' => (int)$previous['id'],
                    'version_no' => (int)$previous['version_no'],
                    'previous_version_id' => (int)($previous['previous_version_id'] ?? 0),
                    'content_digest' => $digest,
                    'lifecycle_status' => (string)$previous['lifecycle_status'],
                    'created' => false,
                ];
            }
            $now = date('Y-m-d H:i:s');
            $lifecycleStatus = $validationStatus === 'rejected' ? 'closed' : 'active';
            $id = (int)Db::name(self::VERSION_TABLE)->insertGetId([
                'tenant_id' => (int)$record['tenant_id'],
                'hotel_id' => (int)$record['hotel_id'],
                'sop_key' => (string)$record['sop_key'],
                'version_no' => $versionNo,
                'previous_version_id' => $previousId,
                'title' => (string)$record['title'],
                'objective' => (string)$record['objective'],
                'steps_json' => $this->encode($record['steps']),
                'stop_conditions_json' => $this->encode($record['stop_conditions']),
                'scope_json' => $this->encode($record['scope']),
                'source_memory_ids_json' => $this->encode($record['source_memory_ids']),
                'evidence_refs_json' => $this->encode($record['evidence_refs']),
                'validation_status' => $validationStatus,
                'validation_note' => (string)$record['validation_note'],
                'content_digest' => $digest,
                'lifecycle_status' => $lifecycleStatus,
                'created_by' => (int)$record['created_by'],
                'validated_by' => (int)$record['validated_by'],
                'validated_at' => $record['validated_at'],
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            if ($id <= 0) {
                throw new RuntimeException('SOP版本保存失败：未取得记录ID');
            }
            $superseded = Db::name(self::VERSION_TABLE)
                ->where('tenant_id', (int)$record['tenant_id'])
                ->where('hotel_id', (int)$record['hotel_id'])
                ->where('sop_key', (string)$record['sop_key'])
                ->where('id', '<>', $id)
                ->where('lifecycle_status', 'active')
                ->whereNull('deleted_at');
            if ($validationStatus === 'verified') {
                $superseded->whereIn('validation_status', ['candidate', 'verified']);
            } else {
                // A new candidate replaces only an older candidate. A rejected
                // decision closes its candidate but leaves the last verified
                // operating version available until another version is verified.
                $superseded->where('validation_status', 'candidate');
            }
            $superseded->update([
                    'lifecycle_status' => 'superseded',
                    'updated_at' => $now,
                ]);
            return [
                'id' => $id,
                'version_no' => $versionNo,
                'previous_version_id' => $previousId ?? 0,
                'content_digest' => $digest,
                'lifecycle_status' => $lifecycleStatus,
                'created' => true,
            ];
        });

        $version = $this->readVersion(
            (int)$result['id'],
            (int)$record['tenant_id'],
            [(int)$record['hotel_id']]
        );
        $readbackDigest = $this->digest($this->versionContent($version));
        if ((int)$version['tenant_id'] !== (int)$record['tenant_id']
            || (int)$version['hotel_id'] !== (int)$record['hotel_id']
            || (string)$version['sop_key'] !== (string)$record['sop_key']
            || (int)$version['version_no'] !== (int)$result['version_no']
            || (int)$version['previous_version_id'] !== (int)$result['previous_version_id']
            || (string)$version['validation_status'] !== (string)$record['validation_status']
            || (string)$version['lifecycle_status'] !== (string)$result['lifecycle_status']
            || !hash_equals((string)$result['content_digest'], (string)$version['content_digest'])
            || !hash_equals((string)$result['content_digest'], $readbackDigest)
        ) {
            throw new RuntimeException('SOP版本已写入但严格回读校验失败');
        }
        return [
            'version' => $version,
            'created' => (bool)$result['created'],
            'persistence_status' => 'readback_verified',
            'write_boundaries' => [
                'automatic_publish' => false,
                'automatic_execution' => false,
                'ota_write' => false,
                'external_message' => false,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function verificationMemories(int $tenantId, int $hotelId, array $ids, array $scope): array
    {
        if (count($ids) < self::MIN_VERIFICATION_MEMORIES) {
            throw new InvalidArgumentException('SOP验证至少需要3条独立的正向执行复盘记忆');
        }
        $memories = $this->memories($tenantId, $hotelId, $ids);
        $taskIds = [];
        $businessDates = [];
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $sourceScope = strtolower(trim((string)($scope['source_scope'] ?? '')));
        foreach ($memories as $memory) {
            $context = $this->decode($memory['context_json'] ?? null);
            if (($memory['memory_layer'] ?? '') !== 'execution_review'
                || ($memory['quality_status'] ?? '') !== 'verified'
                || ($memory['usage_level'] ?? '') !== 'decision_support'
                || ($memory['lifecycle_status'] ?? '') !== 'active'
                || ($context['outcome_verified'] ?? false) !== true
                || ($context['positive_outcome_verified'] ?? false) !== true
                || ($context['sop_candidate_ready'] ?? false) !== true
            ) {
                throw new InvalidArgumentException('SOP验证证据必须是已核验、正向且可进入候选的执行复盘记忆');
            }
            if (strtolower(trim((string)($memory['platform'] ?? ''))) !== $platform
                || strtolower(trim((string)($memory['source_scope'] ?? ''))) !== $sourceScope
            ) {
                throw new InvalidArgumentException('SOP验证证据的平台或事实范围不一致');
            }
            $taskIds[(int)$memory['source_record_id']] = true;
            $businessDate = trim((string)($memory['business_date'] ?? ''));
            if ($businessDate !== '') {
                $businessDates[$businessDate] = true;
            }
        }
        if (count($taskIds) < self::MIN_VERIFICATION_MEMORIES || count($businessDates) < 2) {
            throw new InvalidArgumentException('SOP验证需要至少3个独立任务且覆盖至少2个经营日期');
        }
        return $memories;
    }

    /** @return list<array<string,mixed>> */
    private function memories(int $tenantId, int $hotelId, array $ids): array
    {
        if (!$this->tableExists(OperatingMemoryService::TABLE)) {
            throw new RuntimeException('经营记忆表不存在');
        }
        $rows = Db::name(OperatingMemoryService::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if (count($rows) !== count($ids)) {
            throw new RuntimeException('SOP来源记忆不存在、跨酒店或跨租户');
        }
        return $rows;
    }

    /** @return array{scope_ready:bool,refs:list<string>,comparison_contract:array<string,mixed>,data_gaps:list<array<string,string>>} */
    private function targetFactScope(int $tenantId, int $hotelId, array $scope): array
    {
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $sourceScope = strtolower(trim((string)($scope['source_scope'] ?? '')));
        $dateStart = trim((string)($scope['evidence_date_start'] ?? ''));
        $dateEnd = trim((string)($scope['evidence_date_end'] ?? ''));
        $dataTypes = $this->normalizedScopeList($scope['applicable_data_types'] ?? []);
        $comparisonContract = [
            'tenant_id' => $tenantId,
            'target_hotel_id' => $hotelId,
            'platform' => $platform,
            'source_scope' => $sourceScope,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'data_types' => $dataTypes,
            'metric_definitions' => is_array($scope['metric_definitions'] ?? null)
                ? array_values($scope['metric_definitions'])
                : [],
            'readback_verified' => true,
            'validation_status' => 'normal',
        ];
        $dataGaps = [];
        if ($sourceScope !== 'ota_channel') {
            $dataGaps[] = [
                'code' => 'source_scope_not_replication_compatible',
                'message' => '当前最小版本只支持 OTA 渠道范围内的跨店可比事实。',
            ];
        }
        if ($platform === '' || $platform === 'all_ota') {
            $dataGaps[] = [
                'code' => 'source_platform_scope_missing',
                'message' => '跨店比较必须明确单一 OTA 平台。',
            ];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateEnd) !== 1
            || $dateEnd < $dateStart
        ) {
            $dataGaps[] = [
                'code' => 'source_evidence_date_scope_missing',
                'message' => '来源SOP缺少可用于目标店比较的证据日期范围。',
            ];
        }
        if ($dataTypes === []) {
            $dataGaps[] = [
                'code' => 'source_data_type_scope_missing',
                'message' => '来源SOP未声明适用的数据类型，不能把任意目标店事实判为可比。',
            ];
        }
        if (!$this->tableExists('online_daily_data')) {
            $dataGaps[] = [
                'code' => 'target_fact_table_missing',
                'message' => '目标店严格回读事实表不存在。',
            ];
        }
        if ($dataGaps !== []) {
            return [
                'scope_ready' => false,
                'refs' => [],
                'comparison_contract' => $comparisonContract,
                'data_gaps' => $dataGaps,
            ];
        }

        $query = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereBetween('data_date', [$dateStart, $dateEnd])
            ->whereIn('data_type', $dataTypes)
            ->where('readback_verified', 1)
            ->where('validation_status', 'normal')
            ->whereRaw(
                "LOWER(COALESCE(NULLIF(`platform`, ''), `source`, '')) = :sop_target_platform",
                ['sop_target_platform' => $platform]
            );
        $ids = array_map('intval', $query->order('data_date', 'desc')->order('id', 'desc')->limit(10)->column('id'));
        return [
            'scope_ready' => true,
            'refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                array_values(array_filter($ids))
            ),
            'comparison_contract' => $comparisonContract,
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function versionContent(array $record): array
    {
        return [
            'tenant_id' => (int)($record['tenant_id'] ?? 0),
            'hotel_id' => (int)($record['hotel_id'] ?? 0),
            'sop_key' => (string)($record['sop_key'] ?? ''),
            'title' => (string)($record['title'] ?? ''),
            'objective' => (string)($record['objective'] ?? ''),
            'steps' => is_array($record['steps'] ?? null) ? $record['steps'] : [],
            'stop_conditions' => is_array($record['stop_conditions'] ?? null) ? $record['stop_conditions'] : [],
            'scope' => is_array($record['scope'] ?? null) ? $record['scope'] : [],
            'source_memory_ids' => $this->ids(is_array($record['source_memory_ids'] ?? null) ? $record['source_memory_ids'] : []),
            'evidence_refs' => is_array($record['evidence_refs'] ?? null) ? array_values($record['evidence_refs']) : [],
            'validation_status' => (string)($record['validation_status'] ?? ''),
            'validation_note' => (string)($record['validation_note'] ?? ''),
            'created_by' => (int)($record['created_by'] ?? 0),
            'validated_by' => (int)($record['validated_by'] ?? 0),
            'validated_at' => $record['validated_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeVersion(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'version_no', 'previous_version_id', 'created_by', 'validated_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        foreach ([
            'steps_json' => 'steps',
            'stop_conditions_json' => 'stop_conditions',
            'scope_json' => 'scope',
            'source_memory_ids_json' => 'source_memory_ids',
            'evidence_refs_json' => 'evidence_refs',
        ] as $jsonField => $publicField) {
            $row[$publicField] = $this->decode($row[$jsonField] ?? null);
            unset($row[$jsonField]);
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeReplication(array $row): array
    {
        foreach (['id', 'tenant_id', 'source_sop_version_id', 'source_hotel_id', 'target_hotel_id', 'created_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        foreach ([
            'draft_json' => 'draft',
            'target_fact_refs_json' => 'target_fact_refs',
            'data_gaps_json' => 'data_gaps',
        ] as $jsonField => $publicField) {
            $row[$publicField] = $this->decode($row[$jsonField] ?? null);
            unset($row[$jsonField]);
        }
        return $row;
    }

    private function sopKey(int $tenantId, int $hotelId, string $title, array $scope): string
    {
        // Evidence dates, applicable metrics, steps and review references evolve
        // with each version. Keep only the stable business identity in the key
        // so later evidence extends the same immutable version chain.
        $stableIdentity = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'title' => mb_strtolower(trim($title)),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($scope['source_scope'] ?? ''))),
        ];
        return 'operating-sop:' . substr($this->digest($stableIdentity), 0, 48);
    }

    /** @return list<string> */
    private function textList(mixed $value, string $label, bool $required): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException($label . '必须是数组');
        }
        $items = array_values(array_filter(array_map(
            static fn(mixed $item): string => mb_substr(trim((string)$item), 0, 500),
            $value
        ), static fn(string $item): bool => $item !== ''));
        if (($required && $items === []) || count($items) > 30) {
            throw new InvalidArgumentException($label . ($required ? '不能为空且' : '') . '不能超过30项');
        }
        return $items;
    }

    /** @return list<string> */
    private function normalizedScopeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = array_map(
            static fn(mixed $item): string => mb_substr(strtolower(trim((string)$item)), 0, 80),
            $value
        );
        return array_values(array_unique(array_filter($items, static fn(string $item): bool => $item !== '')));
    }

    private function assertHotelIdentity(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0 || !$this->tableExists('hotels')) {
            throw new InvalidArgumentException('SOP缺少有效的租户或酒店身份');
        }
        $actualTenant = (int)Db::name('hotels')->where('id', $hotelId)->where('status', 1)->value('tenant_id');
        if ($actualTenant <= 0 || $actualTenant !== $tenantId) {
            throw new RuntimeException('SOP酒店与租户身份不一致');
        }
    }

    private function assertTablesReady(): void
    {
        if (!$this->tableExists(self::VERSION_TABLE) || !$this->tableExists(self::REPLICATION_TABLE)) {
            throw new RuntimeException('经营SOP功能尚未启用：请先执行本地数据库迁移');
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::name($table)->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    private function digest(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
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
            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }
}
