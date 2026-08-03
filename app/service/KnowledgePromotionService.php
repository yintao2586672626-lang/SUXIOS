<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Formal, hotel-scoped promotion workflow for reviewed operating SOPs.
 *
 * Runtime JSON is deliberately outside this service. A formal candidate can
 * only start from an existing hotel_operating_sop_versions candidate. Content
 * revisions are append-only, workflow changes are append-only events, and no
 * knowledge row is written until OperatingSopService verifies the source SOP.
 */
final class KnowledgePromotionService
{
    public const CANDIDATE_TABLE = 'knowledge_candidates';
    public const REVISION_TABLE = 'knowledge_candidate_revisions';
    public const EVENT_TABLE = 'knowledge_promotion_events';
    public const SOURCE_RECORD_TYPE = 'hotel_operating_sop_versions';
    public const CONTRACT_VERSION = 'knowledge_promotion.v1';
    public const KNOWLEDGE_CONTRACT_VERSION = 'formal_operating_sop_knowledge.v1';

    private OperatingSopService $sopService;

    public function __construct(?OperatingSopService $sopService = null)
    {
        $this->sopService = $sopService ?? new OperatingSopService();
    }

    /**
     * @param list<int> $hotelIds
     * @return array<string,mixed>
     */
    public function createFromSopCandidate(
        int $sourceVersionId,
        int $tenantId,
        array $hotelIds,
        int $actorId,
        string $idempotencyKey = ''
    ): array {
        $this->assertTablesReady();
        $this->assertActor($actorId);
        $hotelIds = $this->ids($hotelIds);
        if ($sourceVersionId <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('正式知识候选缺少有效的来源SOP或酒店范围');
        }

        // Source identity is also the create idempotency boundary. Read the
        // existing formal candidate first so a valid retry still succeeds after
        // a later content revision supersedes the original SOP candidate.
        $existing = $this->candidateBySource($sourceVersionId, $tenantId, $hotelIds);
        if (is_array($existing)) {
            return $this->candidateResponse(
                $this->readCandidate((int)$existing['id'], $tenantId, $hotelIds),
                false,
                $this->firstEvent((int)$existing['id'], 'candidate_created'),
                'create_replayed'
            );
        }

        $source = $this->sopService->readVersion($sourceVersionId, $tenantId, $hotelIds);
        if (($source['validation_status'] ?? '') !== 'candidate'
            || ($source['lifecycle_status'] ?? '') !== 'active'
        ) {
            throw new InvalidArgumentException('正式知识候选只能来自当前有效的候选SOP版本');
        }
        $actualTenantId = (int)($source['tenant_id'] ?? 0);
        $hotelId = (int)($source['hotel_id'] ?? 0);
        $this->assertHotelIdentity($actualTenantId, $hotelId);

        return Db::transaction(function () use (
            $source,
            $sourceVersionId,
            $actualTenantId,
            $hotelId,
            $hotelIds,
            $actorId,
            $idempotencyKey
        ): array {
            $raced = $this->candidateBySource($sourceVersionId, $actualTenantId, $hotelIds, true);
            if (is_array($raced)) {
                return $this->candidateResponse(
                    $this->readCandidate((int)$raced['id'], $actualTenantId, $hotelIds),
                    false,
                    $this->firstEvent((int)$raced['id'], 'candidate_created'),
                    'create_replayed'
                );
            }

            $now = date('Y-m-d H:i:s');
            $candidateKey = 'formal-sop-promotion:' . substr($this->digest([
                'tenant_id' => $actualTenantId,
                'hotel_id' => $hotelId,
                'source_record_type' => self::SOURCE_RECORD_TYPE,
                'source_record_id' => $sourceVersionId,
            ]), 0, 48);
            $candidateId = (int)Db::name(self::CANDIDATE_TABLE)->insertGetId([
                'tenant_id' => $actualTenantId,
                'hotel_id' => $hotelId,
                'candidate_key' => $candidateKey,
                'candidate_type' => 'operating_sop',
                'source_record_type' => self::SOURCE_RECORD_TYPE,
                'source_record_id' => $sourceVersionId,
                'source_stage' => 'verified_execution_review',
                'current_revision_id' => null,
                'current_revision_no' => 0,
                'workflow_status' => 'draft',
                'assigned_reviewer_id' => null,
                'review_due_at' => null,
                'promoted_sop_version_id' => null,
                'promoted_knowledge_unit_id' => null,
                'promoted_knowledge_chunk_id' => null,
                'row_version' => 1,
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
            if ($candidateId <= 0) {
                throw new RuntimeException('正式知识候选保存失败：未取得记录ID');
            }

            $revisionRecord = $this->revisionRecordFromSop($source, $candidateId, 1, $actorId);
            $revisionId = $this->insertRevision($revisionRecord);
            Db::name(self::CANDIDATE_TABLE)->where('id', $candidateId)->update([
                'current_revision_id' => $revisionId,
                'current_revision_no' => 1,
                'updated_at' => $now,
            ]);

            $eventKey = $this->eventIdempotencyKey(
                $candidateId,
                'candidate_created',
                $actorId,
                $idempotencyKey,
                ['source_version_id' => $sourceVersionId]
            );
            $event = $this->appendEvent(
                $actualTenantId,
                $hotelId,
                $candidateId,
                $revisionId,
                'candidate_created',
                '',
                'draft',
                $actorId,
                '由已保存的候选SOP建立正式晋级候选',
                [
                    'source_record_type' => self::SOURCE_RECORD_TYPE,
                    'source_record_id' => $sourceVersionId,
                    'source_digest' => (string)$revisionRecord['source_digest'],
                    'content_digest' => (string)$revisionRecord['content_digest'],
                    'causality_verified' => false,
                ],
                $eventKey
            );

            $candidate = $this->readCandidate($candidateId, $actualTenantId, $hotelIds);
            $this->assertCandidateRevisionReadback($candidate, $revisionRecord, $revisionId);
            return $this->candidateResponse($candidate, true, $event, 'created');
        });
    }

    /**
     * Append a content revision. Source identity, hotel, platform and evidence
     * memories cannot be supplied by the caller and are inherited from the
     * current SOP candidate. OperatingSopService creates the corresponding new
     * candidate version so its verification gate remains authoritative.
     *
     * @param list<int> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createRevision(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId
    ): array {
        $this->assertTablesReady();
        $this->assertActor($actorId);
        $hotelIds = $this->ids($hotelIds);
        $this->assertCallerDidNotOverrideIdentity($input);

        return Db::transaction(function () use ($candidateId, $tenantId, $hotelIds, $input, $actorId): array {
            $candidateRow = $this->candidateRow($candidateId, $tenantId, $hotelIds, true);
            $fromStatus = (string)$candidateRow['workflow_status'];
            if (!in_array($fromStatus, ['draft', 'changes_requested'], true)) {
                throw new InvalidArgumentException('只有草稿或已退回修改的候选可以新增修订');
            }
            $currentRevision = $this->revisionRow((int)$candidateRow['current_revision_id'], $candidateId);
            $currentSource = $this->sopService->readVersion(
                (int)$currentRevision['source_sop_candidate_version_id'],
                (int)$candidateRow['tenant_id'],
                [(int)$candidateRow['hotel_id']]
            );
            if (($currentSource['validation_status'] ?? '') !== 'candidate'
                || ($currentSource['lifecycle_status'] ?? '') !== 'active'
            ) {
                throw new InvalidArgumentException('当前修订对应的候选SOP已失效，请从新的有效候选重新建立晋级记录');
            }

            $desired = $this->revisionInputFromCurrent($currentRevision, $input);
            $desiredDigest = $this->digest($this->visibleRevisionContent($desired));
            if (hash_equals((string)$currentRevision['content_digest'], $desiredDigest)) {
                return $this->candidateResponse(
                    $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds),
                    false,
                    null,
                    'unchanged_revision_replayed'
                );
            }

            $created = $this->sopService->createCandidate(
                (int)$candidateRow['tenant_id'],
                (int)$candidateRow['hotel_id'],
                $this->ids((array)($currentSource['source_memory_ids'] ?? [])),
                [
                    'title' => $desired['title'],
                    'objective' => $desired['objective'],
                    'steps' => $desired['steps'],
                    'stop_conditions' => $desired['stop_conditions'],
                    'applicable_data_types' => $desired['applicability']['applicable_data_types'] ?? [],
                    'metric_definitions' => $desired['applicability']['metric_definitions'] ?? [],
                ],
                $actorId
            );
            $newSource = is_array($created['version'] ?? null) ? $created['version'] : [];
            if (($newSource['validation_status'] ?? '') !== 'candidate'
                || ($newSource['lifecycle_status'] ?? '') !== 'active'
            ) {
                throw new RuntimeException('内容修订未生成当前有效的候选SOP版本');
            }

            $revisionNo = (int)$candidateRow['current_revision_no'] + 1;
            $revisionRecord = $this->revisionRecordFromSop(
                $newSource,
                $candidateId,
                $revisionNo,
                $actorId,
                $desired['outcome_refs'] ?? [],
                $desired['conflict_refs'] ?? []
            );
            $revisionId = $this->insertRevision($revisionRecord);
            $now = date('Y-m-d H:i:s');
            Db::name(self::CANDIDATE_TABLE)->where('id', $candidateId)->update([
                'current_revision_id' => $revisionId,
                'current_revision_no' => $revisionNo,
                'workflow_status' => 'draft',
                'assigned_reviewer_id' => null,
                'review_due_at' => null,
                'row_version' => (int)$candidateRow['row_version'] + 1,
                'updated_at' => $now,
            ]);

            $eventKey = $this->eventIdempotencyKey(
                $candidateId,
                'revision_created',
                $actorId,
                (string)($input['idempotency_key'] ?? ''),
                ['revision_no' => $revisionNo, 'content_digest' => $revisionRecord['content_digest']]
            );
            $event = $this->appendEvent(
                (int)$candidateRow['tenant_id'],
                (int)$candidateRow['hotel_id'],
                $candidateId,
                $revisionId,
                'revision_created',
                $fromStatus,
                'draft',
                $actorId,
                $this->note($input['note'] ?? '已保存新的候选修订', false),
                [
                    'previous_revision_id' => (int)$currentRevision['id'],
                    'source_sop_candidate_version_id' => (int)$newSource['id'],
                    'content_digest' => (string)$revisionRecord['content_digest'],
                    'causality_verified' => false,
                ],
                $eventKey
            );

            $candidate = $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds);
            $this->assertCandidateRevisionReadback($candidate, $revisionRecord, $revisionId);
            return $this->candidateResponse($candidate, true, $event, 'revision_created');
        });
    }

    /** @param list<int> $hotelIds @param array<string,mixed> $input @return array<string,mixed> */
    public function submit(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId
    ): array {
        return $this->transition(
            $candidateId,
            $tenantId,
            $hotelIds,
            $input,
            $actorId,
            ['draft'],
            'in_review',
            'submitted',
            false
        );
    }

    /**
     * Review decisions are request_changes, reject or approve. Approval is the
     * only path that invokes SOP verification and projects formal knowledge.
     *
     * @param list<int> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function review(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId
    ): array {
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if ($decision === 'request_changes') {
            return $this->transition(
                $candidateId,
                $tenantId,
                $hotelIds,
                $input,
                $actorId,
                ['in_review'],
                'changes_requested',
                'changes_requested',
                true
            );
        }
        if ($decision === 'reject') {
            return $this->transition(
                $candidateId,
                $tenantId,
                $hotelIds,
                $input,
                $actorId,
                ['in_review'],
                'rejected',
                'rejected',
                true
            );
        }
        if ($decision !== 'approve') {
            throw new InvalidArgumentException('审核决定必须是 request_changes、reject 或 approve');
        }

        return $this->approve($candidateId, $tenantId, $hotelIds, $input, $actorId);
    }

    /**
     * Withdraw a pending candidate or retire its currently published formal
     * version. This changes only local SOP/knowledge lifecycle state.
     *
     * @param list<int> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function withdraw(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId
    ): array {
        $this->assertTablesReady();
        $this->assertActor($actorId);
        $hotelIds = $this->ids($hotelIds);
        $note = $this->note($input['note'] ?? null, true);

        return Db::transaction(function () use ($candidateId, $tenantId, $hotelIds, $input, $actorId, $note): array {
            $candidateRow = $this->candidateRow($candidateId, $tenantId, $hotelIds, true);
            $fromStatus = (string)$candidateRow['workflow_status'];
            $eventKey = $this->eventIdempotencyKey(
                $candidateId,
                'withdrawn',
                $actorId,
                (string)($input['idempotency_key'] ?? ''),
                ['target_status' => 'withdrawn', 'note' => $note]
            );
            $replayed = $this->eventByIdempotencyKey($eventKey, (int)$candidateRow['tenant_id'], $hotelIds);
            if (is_array($replayed)) {
                return $this->candidateResponse(
                    $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds),
                    false,
                    $replayed,
                    'withdraw_replayed'
                );
            }
            if (!in_array($fromStatus, ['draft', 'in_review', 'changes_requested', 'approved'], true)) {
                throw new InvalidArgumentException('当前候选状态不能撤回或停用');
            }

            $now = date('Y-m-d H:i:s');
            $retired = null;
            if ($fromStatus === 'approved') {
                $retired = $this->retireProjection($candidateRow, $actorId, $now);
            }
            Db::name(self::CANDIDATE_TABLE)->where('id', $candidateId)->update([
                'workflow_status' => 'withdrawn',
                'row_version' => (int)$candidateRow['row_version'] + 1,
                'updated_at' => $now,
            ]);
            $event = $this->appendEvent(
                (int)$candidateRow['tenant_id'],
                (int)$candidateRow['hotel_id'],
                $candidateId,
                (int)$candidateRow['current_revision_id'],
                'withdrawn',
                $fromStatus,
                'withdrawn',
                $actorId,
                $note,
                [
                    'retired_projection' => $retired,
                    'automatic_execution' => false,
                    'ota_write' => false,
                    'external_message' => false,
                ],
                $eventKey
            );
            $candidate = $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds);
            if (($candidate['workflow_status'] ?? '') !== 'withdrawn') {
                throw new RuntimeException('候选撤回或停用后严格回读失败');
            }
            return $this->candidateResponse($candidate, true, $event, 'withdrawn');
        });
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function listCandidates(
        int $tenantId,
        array $hotelIds,
        ?int $hotelId = null,
        ?string $workflowStatus = null
    ): array {
        $this->assertTablesReady();
        $hotelIds = $this->ids($hotelIds);
        if ($hotelIds === []) {
            throw new InvalidArgumentException('知识晋级工作台缺少可访问酒店');
        }
        if ($hotelId !== null && !in_array($hotelId, $hotelIds, true)) {
            throw new RuntimeException('无权查看该酒店的知识晋级候选');
        }
        $workflowStatus = $workflowStatus === null ? null : strtolower(trim($workflowStatus));
        if ($workflowStatus !== null && $workflowStatus !== ''
            && !in_array($workflowStatus, $this->workflowStatuses(), true)
        ) {
            throw new InvalidArgumentException('知识晋级状态筛选无效');
        }

        $query = Db::name(self::CANDIDATE_TABLE)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($hotelId !== null) {
            $query->where('hotel_id', $hotelId);
        }
        if ($workflowStatus !== null && $workflowStatus !== '') {
            $query->where('workflow_status', $workflowStatus);
        }
        $rows = $query->order('updated_at', 'desc')->order('id', 'desc')->limit(100)->select()->toArray();
        return [
            'data_status' => 'ok',
            'list' => array_map(function (array $row): array {
                $candidate = $this->normalizeCandidate($row);
                $candidate['current_revision'] = $this->revisionRow(
                    (int)$candidate['current_revision_id'],
                    (int)$candidate['id']
                );
                $this->assertRevisionIntegrity($candidate['current_revision']);
                $this->assertSubmissionReceiptIfRequired($candidate);
                $candidate['boundaries'] = $this->boundaries();
                return $candidate;
            }, $rows),
            'count' => count($rows),
            'data_gaps' => [],
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function readCandidate(int $candidateId, int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $row = $this->candidateRow($candidateId, $tenantId, $this->ids($hotelIds));
        $candidate = $this->normalizeCandidate($row);
        $candidate['current_revision'] = $this->revisionRow(
            (int)$candidate['current_revision_id'],
            $candidateId
        );
        $this->assertRevisionIntegrity($candidate['current_revision']);
        $this->assertSubmissionReceiptIfRequired($candidate);
        $candidate['event_count'] = (int)Db::name(self::EVENT_TABLE)
            ->where('tenant_id', (int)$candidate['tenant_id'])
            ->where('hotel_id', (int)$candidate['hotel_id'])
            ->where('candidate_id', $candidateId)
            ->count();
        $candidate['promoted_knowledge'] = $this->readPromotedKnowledge($candidate);
        $candidate['boundaries'] = $this->boundaries();
        return $candidate;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function listEvents(int $candidateId, int $tenantId, array $hotelIds): array
    {
        $candidate = $this->readCandidate($candidateId, $tenantId, $hotelIds);
        $rows = Db::name(self::EVENT_TABLE)
            ->where('tenant_id', (int)$candidate['tenant_id'])
            ->where('hotel_id', (int)$candidate['hotel_id'])
            ->where('candidate_id', $candidateId)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return [
            'data_status' => 'ok',
            'candidate_id' => $candidateId,
            'list' => array_map([$this, 'normalizeEvent'], $rows),
            'count' => count($rows),
            'append_only' => true,
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param list<int> $hotelIds @param array<string,mixed> $input @return array<string,mixed> */
    private function approve(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId
    ): array {
        $this->assertTablesReady();
        $this->assertActor($actorId);
        $hotelIds = $this->ids($hotelIds);
        $note = $this->note($input['note'] ?? $input['validation_note'] ?? null, true);

        return Db::transaction(function () use ($candidateId, $tenantId, $hotelIds, $input, $actorId, $note): array {
            $candidateRow = $this->candidateRow($candidateId, $tenantId, $hotelIds, true);
            $eventKey = $this->eventIdempotencyKey(
                $candidateId,
                'approved',
                $actorId,
                (string)($input['idempotency_key'] ?? ''),
                [
                    'revision_id' => (int)$candidateRow['current_revision_id'],
                    'evidence_memory_ids' => $this->ids((array)($input['evidence_memory_ids'] ?? [])),
                    'note' => $note,
                ]
            );
            $replayed = $this->eventByIdempotencyKey($eventKey, (int)$candidateRow['tenant_id'], $hotelIds);
            if (is_array($replayed)) {
                return $this->candidateResponse(
                    $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds),
                    false,
                    $replayed,
                    'approval_replayed'
                );
            }
            if (($candidateRow['workflow_status'] ?? '') !== 'in_review') {
                throw new InvalidArgumentException('只有审核中的候选可以批准');
            }

            $revision = $this->revisionRow((int)$candidateRow['current_revision_id'], $candidateId);
            $this->assertRevisionIntegrity($revision);
            if ((int)($revision['submitted_by'] ?? 0) <= 0
                || trim((string)($revision['submitted_at'] ?? '')) === ''
            ) {
                throw new RuntimeException('knowledge promotion revision has no immutable submission receipt');
            }
            $sourceVersionId = (int)$revision['source_sop_candidate_version_id'];
            $source = $this->sopService->readVersion(
                $sourceVersionId,
                (int)$candidateRow['tenant_id'],
                [(int)$candidateRow['hotel_id']]
            );
            if (!hash_equals((string)$revision['source_digest'], (string)($source['content_digest'] ?? ''))) {
                throw new InvalidArgumentException('候选SOP来源内容已变化，不能批准当前修订');
            }

            $this->assertSopBusinessContentMatchesRevision($revision, $source, 'candidate');

            // Do not reproduce or weaken OperatingSopService's evidence gate.
            // Its 3-task, 2-business-date, same-platform/scope validation remains
            // the single authority for turning the source candidate into a
            // verified immutable SOP version.
            $verifiedResult = $this->sopService->validateVersion(
                $sourceVersionId,
                (int)$candidateRow['tenant_id'],
                [(int)$candidateRow['hotel_id']],
                [
                    'decision' => 'verify',
                    'validation_note' => $note,
                    'evidence_memory_ids' => $this->ids((array)($input['evidence_memory_ids'] ?? [])),
                ],
                $actorId
            );
            $verifiedVersion = is_array($verifiedResult['version'] ?? null) ? $verifiedResult['version'] : [];
            if (($verifiedVersion['validation_status'] ?? '') !== 'verified'
                || ($verifiedVersion['lifecycle_status'] ?? '') !== 'active'
            ) {
                throw new RuntimeException('候选SOP未生成有效的人工验证版本');
            }

            $this->assertSopBusinessContentMatchesRevision($revision, $verifiedVersion, 'verified');

            $projection = $this->projectVerifiedKnowledge(
                $candidateRow,
                $revision,
                $verifiedVersion,
                $actorId
            );
            $now = date('Y-m-d H:i:s');
            Db::name(self::CANDIDATE_TABLE)->where('id', $candidateId)->update([
                'workflow_status' => 'approved',
                'assigned_reviewer_id' => $actorId,
                'promoted_sop_version_id' => (int)$verifiedVersion['id'],
                'promoted_knowledge_unit_id' => (int)$projection['knowledge_unit_id'],
                'promoted_knowledge_chunk_id' => (int)$projection['knowledge_chunk_id'],
                'row_version' => (int)$candidateRow['row_version'] + 1,
                'updated_at' => $now,
            ]);
            $event = $this->appendEvent(
                (int)$candidateRow['tenant_id'],
                (int)$candidateRow['hotel_id'],
                $candidateId,
                (int)$revision['id'],
                'approved',
                'in_review',
                'approved',
                $actorId,
                $note,
                [
                    'promoted_sop_version_id' => (int)$verifiedVersion['id'],
                    'promoted_sop_digest' => (string)$verifiedVersion['content_digest'],
                    'knowledge_unit_id' => (int)$projection['knowledge_unit_id'],
                    'knowledge_chunk_id' => (int)$projection['knowledge_chunk_id'],
                    'knowledge_content_digest' => (string)$projection['content_digest'],
                    'causality_verified' => false,
                    'automatic_execution' => false,
                    'ota_write' => false,
                    'external_message' => false,
                ],
                $eventKey
            );

            $candidate = $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds);
            if (($candidate['workflow_status'] ?? '') !== 'approved'
                || (int)$candidate['promoted_sop_version_id'] !== (int)$verifiedVersion['id']
                || (int)$candidate['promoted_knowledge_unit_id'] !== (int)$projection['knowledge_unit_id']
                || (int)$candidate['promoted_knowledge_chunk_id'] !== (int)$projection['knowledge_chunk_id']
            ) {
                throw new RuntimeException('正式SOP批准结果已写入但严格回读失败');
            }
            $this->assertProjectionReadback($candidate, $verifiedVersion, $projection);
            $response = $this->candidateResponse($candidate, true, $event, 'approved');
            $response['promoted_sop_version'] = $verifiedVersion;
            $response['knowledge_projection'] = $projection;
            return $response;
        });
    }

    /**
     * @param list<int> $hotelIds
     * @param array<string,mixed> $input
     * @param list<string> $allowedFrom
     * @return array<string,mixed>
     */
    private function transition(
        int $candidateId,
        int $tenantId,
        array $hotelIds,
        array $input,
        int $actorId,
        array $allowedFrom,
        string $toStatus,
        string $eventType,
        bool $noteRequired
    ): array {
        $this->assertTablesReady();
        $this->assertActor($actorId);
        $hotelIds = $this->ids($hotelIds);
        $note = $this->note($input['note'] ?? null, $noteRequired);

        return Db::transaction(function () use (
            $candidateId,
            $tenantId,
            $hotelIds,
            $input,
            $actorId,
            $allowedFrom,
            $toStatus,
            $eventType,
            $note
        ): array {
            $candidateRow = $this->candidateRow($candidateId, $tenantId, $hotelIds, true);
            $fromStatus = (string)$candidateRow['workflow_status'];
            $eventKey = $this->eventIdempotencyKey(
                $candidateId,
                $eventType,
                $actorId,
                (string)($input['idempotency_key'] ?? ''),
                [
                    'to_status' => $toStatus,
                    'note' => $note,
                    'assigned_reviewer_id' => (int)($input['assigned_reviewer_id'] ?? 0),
                    'review_due_at' => trim((string)($input['review_due_at'] ?? '')),
                ]
            );
            $replayed = $this->eventByIdempotencyKey($eventKey, (int)$candidateRow['tenant_id'], $hotelIds);
            if (is_array($replayed)) {
                return $this->candidateResponse(
                    $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds),
                    false,
                    $replayed,
                    $eventType . '_replayed'
                );
            }
            if (!in_array($fromStatus, $allowedFrom, true)) {
                throw new InvalidArgumentException('当前候选状态不能执行该操作');
            }

            $now = date('Y-m-d H:i:s');
            $update = [
                'workflow_status' => $toStatus,
                'row_version' => (int)$candidateRow['row_version'] + 1,
                'updated_at' => $now,
            ];
            if ($toStatus === 'in_review') {
                $reviewerId = (int)($input['assigned_reviewer_id'] ?? 0);
                $update['assigned_reviewer_id'] = $reviewerId > 0 ? $reviewerId : null;
                $dueAt = trim((string)($input['review_due_at'] ?? ''));
                $update['review_due_at'] = $dueAt === '' ? null : $this->dateTime($dueAt, '审核截止时间');
            }
            if ($toStatus === 'in_review') {
                $revisionId = (int)$candidateRow['current_revision_id'];
                $submitted = Db::name(self::REVISION_TABLE)
                    ->where('id', $revisionId)
                    ->where('candidate_id', $candidateId)
                    ->whereNull('submitted_by')
                    ->whereNull('submitted_at')
                    ->update([
                        'submitted_by' => $actorId,
                        'submitted_at' => $now,
                    ]);
                if ((int)$submitted !== 1) {
                    throw new RuntimeException('current knowledge promotion revision was already submitted or changed');
                }
            }
            Db::name(self::CANDIDATE_TABLE)->where('id', $candidateId)->update($update);
            $event = $this->appendEvent(
                (int)$candidateRow['tenant_id'],
                (int)$candidateRow['hotel_id'],
                $candidateId,
                (int)$candidateRow['current_revision_id'],
                $eventType,
                $fromStatus,
                $toStatus,
                $actorId,
                $note,
                [
                    'assigned_reviewer_id' => $update['assigned_reviewer_id'] ?? $candidateRow['assigned_reviewer_id'],
                    'review_due_at' => $update['review_due_at'] ?? $candidateRow['review_due_at'],
                    'submitted_by' => $toStatus === 'in_review' ? $actorId : null,
                    'submitted_at' => $toStatus === 'in_review' ? $now : null,
                    'causality_verified' => false,
                    'knowledge_write' => false,
                ],
                $eventKey
            );
            $candidate = $this->readCandidate($candidateId, (int)$candidateRow['tenant_id'], $hotelIds);
            if (($candidate['workflow_status'] ?? '') !== $toStatus) {
                throw new RuntimeException('知识晋级状态已写入但严格回读失败');
            }
            if ($toStatus === 'in_review') {
                $submittedRevision = is_array($candidate['current_revision'] ?? null)
                    ? $candidate['current_revision']
                    : [];
                if ((int)($submittedRevision['id'] ?? 0) !== (int)$candidateRow['current_revision_id']
                    || (int)($submittedRevision['submitted_by'] ?? 0) !== $actorId
                    || trim((string)($submittedRevision['submitted_at'] ?? '')) !== $now
                ) {
                    throw new RuntimeException('knowledge promotion submission receipt readback failed');
                }
            }
            return $this->candidateResponse($candidate, true, $event, $eventType);
        });
    }

    /** @param array<string,mixed> $candidateRow @param array<string,mixed> $revision @param array<string,mixed> $version @return array<string,mixed> */
    private function projectVerifiedKnowledge(
        array $candidateRow,
        array $revision,
        array $version,
        int $actorId
    ): array {
        $tenantId = (int)$candidateRow['tenant_id'];
        $hotelId = (int)$candidateRow['hotel_id'];
        if ((int)($version['tenant_id'] ?? 0) !== $tenantId
            || (int)($version['hotel_id'] ?? 0) !== $hotelId
            || ($version['validation_status'] ?? '') !== 'verified'
        ) {
            throw new RuntimeException('正式知识投影的SOP版本身份或验证状态无效');
        }

        $stableKey = 'formal-operating-sop:' . substr($this->digest([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'sop_key' => (string)$version['sop_key'],
        ]), 0, 48);
        $now = date('Y-m-d H:i:s');
        $reviewedAt = trim((string)($version['validated_at'] ?? ''));
        if ($reviewedAt === '') {
            throw new RuntimeException('verified SOP is missing its human review timestamp');
        }
        $reviewDueAt = $this->dateTime($reviewedAt . ' +90 days', 'formal knowledge review due date');
        $unit = Db::name('knowledge_units')->where('stable_key', $stableKey)->lock(true)->find();
        $tags = array_values(array_unique(array_filter([
            'formal_sop',
            'human_approved',
            strtolower(trim((string)(($version['scope']['platform'] ?? '')))),
        ])));
        $unitData = [
            'hotel_id' => $hotelId,
            'stable_key' => $stableKey,
            'name' => (string)$version['title'],
            'source' => 'formal_operating_sop',
            'status' => 'done',
            'lifecycle_status' => 'active',
            'lifecycle_reason' => '',
            'description' => (string)$version['objective'],
            'tags' => $this->encode($tags),
            'reviewed_at' => $reviewedAt,
            'review_due_at' => $reviewDueAt,
            'updated_at' => $now,
        ];
        if (is_array($unit)) {
            if ((int)($unit['hotel_id'] ?? 0) !== $hotelId
                || (string)($unit['source'] ?? '') !== 'formal_operating_sop'
            ) {
                throw new RuntimeException('正式知识稳定标识与酒店身份冲突');
            }
            $unitId = (int)$unit['unit_id'];
            $oldChunkId = (int)($unit['current_chunk_id'] ?? 0);
            Db::name('knowledge_units')->where('unit_id', $unitId)->update($unitData);
        } else {
            $unitData['current_chunk_id'] = null;
            $unitData['created_by'] = $actorId;
            $unitData['created_at'] = $now;
            $unitId = (int)Db::name('knowledge_units')->insertGetId($unitData);
            $oldChunkId = 0;
        }
        if ($unitId <= 0) {
            throw new RuntimeException('正式知识单元保存失败：未取得记录ID');
        }

        $existingChunk = Db::name('knowledge_chunks')
            ->where('operating_sop_version_id', (int)$version['id'])
            ->find();
        if (is_array($existingChunk)) {
            $chunkId = (int)$existingChunk['chunk_id'];
            $contentDigest = (string)$existingChunk['content_digest'];
        } else {
            $formalVersionNo = (int)Db::name('knowledge_chunks')
                ->where('unit_id', $unitId)
                ->whereNotNull('version_no')
                ->max('version_no') + 1;
            $formalVersionNo = max(1, $formalVersionNo);
            $content = $this->formalKnowledgeContent(
                $candidateRow,
                $revision,
                $version,
                $unitId,
                $formalVersionNo,
                $stableKey,
                $reviewedAt,
                $reviewDueAt
            );
            $contentDigest = $this->digest($content);
            $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                'unit_id' => $unitId,
                'promotion_candidate_id' => (int)$candidateRow['id'],
                'operating_sop_version_id' => (int)$version['id'],
                'version_no' => $formalVersionNo,
                'lifecycle_status' => 'active',
                'content_digest' => $contentDigest,
                'superseded_by_chunk_id' => null,
                'published_at' => $now,
                'retired_at' => null,
                'type' => 'formal_operating_sop',
                'content' => $this->encode($content),
                'created_by' => $actorId,
                'created_at' => $now,
            ]);
            if ($chunkId <= 0) {
                throw new RuntimeException('正式知识版本保存失败：未取得记录ID');
            }
        }

        // A previous formal version is retired from active use only after the
        // new SOP has passed validation and its versioned chunk exists.
        Db::name('knowledge_chunks')
            ->where('unit_id', $unitId)
            ->where('chunk_id', '<>', $chunkId)
            ->where('lifecycle_status', 'active')
            ->update([
                'lifecycle_status' => 'superseded',
                'superseded_by_chunk_id' => $chunkId,
                'retired_at' => $now,
            ]);
        Db::name('knowledge_units')->where('unit_id', $unitId)->update([
            'current_chunk_id' => $chunkId,
            'updated_at' => $now,
        ]);

        return [
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'previous_active_chunk_id' => $oldChunkId,
            'stable_key' => $stableKey,
            'content_digest' => $contentDigest,
            'persistence_status' => 'pending_outer_transaction_readback',
        ];
    }

    /** @param array<string,mixed> $candidateRow @return array<string,mixed>|null */
    private function retireProjection(array $candidateRow, int $actorId, string $now): ?array
    {
        $versionId = (int)($candidateRow['promoted_sop_version_id'] ?? 0);
        $unitId = (int)($candidateRow['promoted_knowledge_unit_id'] ?? 0);
        $chunkId = (int)($candidateRow['promoted_knowledge_chunk_id'] ?? 0);
        if ($versionId <= 0 || $unitId <= 0 || $chunkId <= 0) {
            throw new RuntimeException('已批准候选缺少可停用的正式SOP或知识版本');
        }
        $unitBefore = Db::name('knowledge_units')
            ->where('unit_id', $unitId)
            ->where('hotel_id', (int)$candidateRow['hotel_id'])
            ->where('source', 'formal_operating_sop')
            ->lock(true)
            ->find();
        if (!is_array($unitBefore)) {
            throw new RuntimeException('formal knowledge unit is missing or has a different hotel identity');
        }
        $currentChunkBefore = (int)($unitBefore['current_chunk_id'] ?? 0);

        $version = Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', $versionId)
            ->where('tenant_id', (int)$candidateRow['tenant_id'])
            ->where('hotel_id', (int)$candidateRow['hotel_id'])
            ->where('validation_status', 'verified')
            ->whereNull('deleted_at')
            ->lock(true)
            ->find();
        if (!is_array($version)) {
            throw new RuntimeException('待停用的正式SOP版本不存在或身份不一致');
        }
        Db::name(OperatingSopService::VERSION_TABLE)->where('id', $versionId)->update([
            'lifecycle_status' => 'retired',
            'retired_by' => $actorId,
            'retired_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('knowledge_chunks')
            ->where('chunk_id', $chunkId)
            ->where('unit_id', $unitId)
            ->where('promotion_candidate_id', (int)$candidateRow['id'])
            ->update([
                'lifecycle_status' => 'retired',
                'retired_at' => $now,
            ]);
        if ($currentChunkBefore === $chunkId) {
            $unitUpdated = Db::name('knowledge_units')
                ->where('unit_id', $unitId)
                ->where('current_chunk_id', $chunkId)
                ->update([
                    'current_chunk_id' => null,
                    'lifecycle_status' => 'stale',
                    'lifecycle_reason' => 'formal_version_withdrawn_no_current_chunk',
                    'updated_at' => $now,
                ]);
            if ((int)$unitUpdated !== 1) {
                throw new RuntimeException('formal knowledge current pointer changed during retirement');
            }
        }
        $versionReadback = Db::name(OperatingSopService::VERSION_TABLE)->where('id', $versionId)->find();
        $chunkReadback = Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->find();
        $unitReadback = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        if (!is_array($versionReadback) || ($versionReadback['lifecycle_status'] ?? '') !== 'retired'
            || !is_array($chunkReadback) || ($chunkReadback['lifecycle_status'] ?? '') !== 'retired'
            || !is_array($unitReadback)
            || ($currentChunkBefore === $chunkId
                ? ((int)($unitReadback['current_chunk_id'] ?? 0) !== 0
                    || ($unitReadback['lifecycle_status'] ?? '') !== 'stale'
                    || ($unitReadback['lifecycle_reason'] ?? '') !== 'formal_version_withdrawn_no_current_chunk')
                : (int)($unitReadback['current_chunk_id'] ?? 0) !== $currentChunkBefore)
        ) {
            throw new RuntimeException('正式SOP停用后严格回读失败');
        }
        return [
            'sop_version_id' => $versionId,
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'lifecycle_status' => 'retired',
        ];
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $version @param array<string,mixed> $projection */
    private function assertProjectionReadback(array $candidate, array $version, array &$projection): void
    {
        $unitId = (int)$projection['knowledge_unit_id'];
        $chunkId = (int)$projection['knowledge_chunk_id'];
        $unit = Db::name('knowledge_units')
            ->where('unit_id', $unitId)
            ->where('hotel_id', (int)$candidate['hotel_id'])
            ->find();
        $chunk = Db::name('knowledge_chunks')
            ->where('chunk_id', $chunkId)
            ->where('unit_id', $unitId)
            ->where('promotion_candidate_id', (int)$candidate['id'])
            ->where('operating_sop_version_id', (int)$version['id'])
            ->find();
        if (!is_array($unit) || !is_array($chunk)
            || (int)($unit['current_chunk_id'] ?? 0) !== $chunkId
            || (string)($unit['stable_key'] ?? '') !== (string)$projection['stable_key']
            || ($unit['status'] ?? '') !== 'done'
            || ($chunk['lifecycle_status'] ?? '') !== 'active'
            || !hash_equals((string)$projection['content_digest'], (string)($chunk['content_digest'] ?? ''))
        ) {
            throw new RuntimeException('正式知识投影已写入但ID或摘要严格回读失败');
        }
        $content = $this->decode($chunk['content'] ?? null);
        if (!hash_equals((string)$projection['content_digest'], $this->digest($content))
            || (int)($content['promotion_candidate_id'] ?? 0) !== (int)$candidate['id']
            || (int)($content['operating_sop_version_id'] ?? 0) !== (int)$version['id']
            || ($content['causality_verified'] ?? null) !== false
        ) {
            throw new RuntimeException('正式知识投影内容摘要严格回读失败');
        }
        $runtimeAuthority = (new KnowledgeSopExecutionProvenanceService())->validateSnapshot(
            $unit,
            $chunk,
            (int)$candidate['hotel_id'],
            (string)($content['platform'] ?? ''),
            (string)($version['validated_at'] ?? '')
        );
        if (($runtimeAuthority['knowledge_gate']['task_draft_safe'] ?? false) !== true
            || trim((string)($runtimeAuthority['formal_authority_digest'] ?? '')) === ''
        ) {
            throw new RuntimeException('formal knowledge was persisted but is not usable by the operation task gate');
        }
        $projection['persistence_status'] = 'readback_verified';
        $projection['knowledge_unit'] = $this->normalizeKnowledgeUnit($unit);
        $projection['knowledge_chunk'] = $this->normalizeKnowledgeChunk($chunk);
        $projection['runtime_authority'] = $runtimeAuthority;
    }

    /** @param array<string,mixed> $candidateRow @param array<string,mixed> $revision @param array<string,mixed> $version @return array<string,mixed> */
    private function formalKnowledgeContent(
        array $candidateRow,
        array $revision,
        array $version,
        int $unitId,
        int $formalVersionNo,
        string $stableKey,
        string $reviewedAt,
        string $reviewDueAt
    ): array {
        $scope = is_array($version['scope'] ?? null) ? $version['scope'] : [];
        $steps = is_array($version['steps'] ?? null) ? array_values($version['steps']) : [];
        $stopConditions = is_array($version['stop_conditions'] ?? null)
            ? array_values($version['stop_conditions'])
            : [];
        return [
            'contract_version' => self::KNOWLEDGE_CONTRACT_VERSION,
            'content_type' => 'sop_card',
            'formal_record_type' => 'operating_sop',
            'formal_version_no' => $formalVersionNo,
            'content_key' => $stableKey,
            'seed_version' => 'formal-operating-sop.v1#' . (int)$version['id'],
            'knowledge_unit_id' => $unitId,
            'promotion_candidate_id' => (int)$candidateRow['id'],
            'promotion_revision_id' => (int)$revision['id'],
            'promotion_revision_digest' => (string)$revision['content_digest'],
            'operating_sop_version_id' => (int)$version['id'],
            'operating_sop_version_no' => (int)$version['version_no'],
            'operating_sop_content_digest' => (string)$version['content_digest'],
            'title' => (string)$version['title'],
            'module_name' => '正式运营SOP',
            'objective' => (string)$version['objective'],
            'steps' => $steps,
            'stop_conditions' => $stopConditions,
            'scope' => 'hotel_specific_verified_execution_review',
            'scope_details' => $scope,
            'platform' => (string)($scope['platform'] ?? ''),
            'platforms' => [(string)($scope['platform'] ?? '')],
            'source_scope' => (string)($scope['source_scope'] ?? ''),
            'business_date_start' => $scope['evidence_date_start'] ?? null,
            'business_date_end' => $scope['evidence_date_end'] ?? null,
            'source_memory_ids' => $this->ids((array)($version['source_memory_ids'] ?? [])),
            'source_refs' => array_values((array)($version['evidence_refs'] ?? [])),
            'evidence_level' => 'verified_execution_review_human_approved',
            'evidence_grade' => 'B',
            'validation_status' => 'human_verified',
            'lifecycle_status' => 'active',
            'reviewed_at' => $reviewedAt,
            'review_due_at' => $reviewDueAt,
            'review_interval_days' => 90,
            'valid_from' => $reviewedAt,
            'causality_verified' => false,
            'causal_claim_allowed' => false,
            'decision_policy' => [
                'human_approval_required' => true,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'causality_verified' => false,
            ],
            'task_template' => [
                'action_type' => 'execute_formal_operating_sop',
                'title' => (string)$version['title'],
                'steps' => $steps,
                'acceptance_criteria' => $stopConditions === []
                    ? ['按步骤执行并保存来源、日期和结果证据']
                    : array_map(static fn(string $item): string => '不得触发停止条件：' . $item, $stopConditions),
            ],
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @param array<string,mixed> $source @param list<mixed> $outcomeRefs @param list<mixed> $conflictRefs @return array<string,mixed> */
    private function revisionRecordFromSop(
        array $source,
        int $candidateId,
        int $revisionNo,
        int $actorId,
        array $outcomeRefs = [],
        array $conflictRefs = []
    ): array {
        $sourceScope = is_array($source['scope'] ?? null) ? $source['scope'] : [];
        $sourceMemoryIds = $this->ids((array)($source['source_memory_ids'] ?? []));
        $evidenceRefs = $this->textRefs((array)($source['evidence_refs'] ?? []));
        $sourceVersionId = (int)($source['id'] ?? 0);
        $applicability = [
            'platform' => strtolower(trim((string)($sourceScope['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($sourceScope['source_scope'] ?? ''))),
            'evidence_date_start' => $sourceScope['evidence_date_start'] ?? null,
            'evidence_date_end' => $sourceScope['evidence_date_end'] ?? null,
            'applicable_data_types' => array_values((array)($sourceScope['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($sourceScope['metric_definitions'] ?? [])),
            'replication_scope' => (string)($sourceScope['replication_scope'] ?? 'same_tenant_draft_only'),
        ];
        $scope = [
            'tenant_id' => (int)($source['tenant_id'] ?? 0),
            'hotel_id' => (int)($source['hotel_id'] ?? 0),
            'platform' => $applicability['platform'],
            'source_scope' => $applicability['source_scope'],
            'business_date_start' => $applicability['evidence_date_start'],
            'business_date_end' => $applicability['evidence_date_end'],
            'source_record_type' => self::SOURCE_RECORD_TYPE,
            'source_record_id' => $sourceVersionId,
            'source_memory_ids' => $sourceMemoryIds,
            'causality_verified' => false,
            'causal_claim_allowed' => false,
        ];
        $record = [
            'candidate_id' => $candidateId,
            'revision_no' => $revisionNo,
            'source_sop_candidate_version_id' => $sourceVersionId,
            'title' => (string)($source['title'] ?? ''),
            'objective' => (string)($source['objective'] ?? ''),
            'steps' => array_values((array)($source['steps'] ?? [])),
            'stop_conditions' => array_values((array)($source['stop_conditions'] ?? [])),
            'applicability' => $applicability,
            'scope' => $scope,
            'evidence_refs' => $evidenceRefs,
            'outcome_refs' => $this->textRefs($outcomeRefs),
            'conflict_refs' => $this->textRefs($conflictRefs),
            'source_digest' => (string)($source['content_digest'] ?? ''),
            'created_by' => $actorId,
        ];
        if ($record['title'] === '' || $sourceVersionId <= 0 || $record['source_digest'] === ''
            || $sourceMemoryIds === [] || $evidenceRefs === []
            || $applicability['platform'] === '' || $applicability['source_scope'] === ''
            || !is_string($applicability['evidence_date_start'])
            || !is_string($applicability['evidence_date_end'])
        ) {
            throw new InvalidArgumentException('候选SOP缺少平台、日期、来源记忆、证据或内容摘要，不能进入正式晋级');
        }
        $record['content_digest'] = $this->digest($this->visibleRevisionContent($record));
        return $record;
    }

    /** @param array<string,mixed> $record */
    private function insertRevision(array $record): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int)Db::name(self::REVISION_TABLE)->insertGetId([
            'candidate_id' => (int)$record['candidate_id'],
            'revision_no' => (int)$record['revision_no'],
            'source_sop_candidate_version_id' => (int)$record['source_sop_candidate_version_id'],
            'title' => (string)$record['title'],
            'objective' => (string)$record['objective'],
            'steps_json' => $this->encode($record['steps']),
            'stop_conditions_json' => $this->encode($record['stop_conditions']),
            'applicability_json' => $this->encode($record['applicability']),
            'scope_json' => $this->encode($record['scope']),
            'evidence_refs_json' => $this->encode($record['evidence_refs']),
            'outcome_refs_json' => $this->encode($record['outcome_refs']),
            'conflict_refs_json' => $this->encode($record['conflict_refs']),
            'source_digest' => (string)$record['source_digest'],
            'content_digest' => (string)$record['content_digest'],
            'created_by' => (int)$record['created_by'],
            'created_at' => $now,
            'submitted_by' => null,
            'submitted_at' => null,
        ]);
        if ($id <= 0) {
            throw new RuntimeException('正式知识候选修订保存失败：未取得记录ID');
        }
        return $id;
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $input @return array<string,mixed> */
    private function revisionInputFromCurrent(array $current, array $input): array
    {
        $applicability = is_array($current['applicability'] ?? null) ? $current['applicability'] : [];
        foreach (['applicable_data_types', 'metric_definitions'] as $field) {
            if (array_key_exists($field, $input)) {
                if (!is_array($input[$field])) {
                    throw new InvalidArgumentException($field . '必须是数组');
                }
                $applicability[$field] = $this->textRefs((array)$input[$field]);
            }
        }
        $steps = array_key_exists('steps', $input) ? $this->contentList($input['steps'], 'SOP步骤', true) : $current['steps'];
        $stopConditions = array_key_exists('stop_conditions', $input)
            ? $this->contentList($input['stop_conditions'], '停止条件', false)
            : $current['stop_conditions'];
        $title = array_key_exists('title', $input) ? trim((string)$input['title']) : (string)$current['title'];
        if ($title === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('SOP标题不能为空且不能超过191字');
        }
        $objective = array_key_exists('objective', $input)
            ? mb_substr(trim((string)$input['objective']), 0, 1000)
            : (string)$current['objective'];
        $desired = [
            'title' => $title,
            'objective' => $objective,
            'steps' => $steps,
            'stop_conditions' => $stopConditions,
            'applicability' => $applicability,
            'scope' => $current['scope'],
            'evidence_refs' => $current['evidence_refs'],
            'outcome_refs' => array_key_exists('outcome_refs', $input)
                ? $this->textRefs((array)$input['outcome_refs'])
                : $current['outcome_refs'],
            'conflict_refs' => array_key_exists('conflict_refs', $input)
                ? $this->textRefs((array)$input['conflict_refs'])
                : $current['conflict_refs'],
        ];
        return $desired;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function visibleRevisionContent(array $record): array
    {
        return [
            'title' => (string)($record['title'] ?? ''),
            'objective' => (string)($record['objective'] ?? ''),
            'steps' => array_values((array)($record['steps'] ?? [])),
            'stop_conditions' => array_values((array)($record['stop_conditions'] ?? [])),
            'applicability' => is_array($record['applicability'] ?? null) ? $record['applicability'] : [],
            'scope' => is_array($record['scope'] ?? null) ? $record['scope'] : [],
            'evidence_refs' => array_values((array)($record['evidence_refs'] ?? [])),
            'outcome_refs' => array_values((array)($record['outcome_refs'] ?? [])),
            'conflict_refs' => array_values((array)($record['conflict_refs'] ?? [])),
        ];
    }

    /** @return array<string,mixed> */
    private function appendEvent(
        int $tenantId,
        int $hotelId,
        int $candidateId,
        int $revisionId,
        string $eventType,
        string $fromStatus,
        string $toStatus,
        int $actorId,
        string $note,
        array $payload,
        string $idempotencyKey
    ): array {
        $existing = $this->eventByIdempotencyKey($idempotencyKey, $tenantId, [$hotelId]);
        if (is_array($existing)) {
            if ((int)$existing['candidate_id'] !== $candidateId
                || (string)$existing['event_type'] !== $eventType
                || (int)$existing['actor_id'] !== $actorId
            ) {
                throw new RuntimeException('知识晋级幂等键与既有事件冲突');
            }
            return $existing;
        }
        $eventId = (int)Db::name(self::EVENT_TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'candidate_id' => $candidateId,
            'revision_id' => $revisionId > 0 ? $revisionId : null,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actorId,
            'note' => $note,
            'payload_json' => $this->encode($payload),
            'idempotency_key' => $idempotencyKey,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($eventId <= 0) {
            throw new RuntimeException('知识晋级事件保存失败：未取得记录ID');
        }
        $row = Db::name(self::EVENT_TABLE)
            ->where('id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('candidate_id', $candidateId)
            ->find();
        if (!is_array($row)
            || (string)($row['idempotency_key'] ?? '') !== $idempotencyKey
            || (string)($row['event_type'] ?? '') !== $eventType
        ) {
            throw new RuntimeException('知识晋级事件已写入但严格回读失败');
        }
        return $this->normalizeEvent($row);
    }

    /** @param list<int> $hotelIds @return array<string,mixed>|null */
    private function eventByIdempotencyKey(string $key, int $tenantId, array $hotelIds): ?array
    {
        $query = Db::name(self::EVENT_TABLE)
            ->where('idempotency_key', $key)
            ->whereIn('hotel_id', $this->ids($hotelIds));
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        return is_array($row) ? $this->normalizeEvent($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function firstEvent(int $candidateId, string $eventType): ?array
    {
        $row = Db::name(self::EVENT_TABLE)
            ->where('candidate_id', $candidateId)
            ->where('event_type', $eventType)
            ->order('id', 'asc')
            ->find();
        return is_array($row) ? $this->normalizeEvent($row) : null;
    }

    /** @param list<int> $hotelIds @return array<string,mixed>|null */
    private function candidateBySource(
        int $sourceVersionId,
        int $tenantId,
        array $hotelIds,
        bool $lock = false
    ): ?array {
        $query = Db::name(self::CANDIDATE_TABLE)
            ->where('source_record_type', self::SOURCE_RECORD_TYPE)
            ->where('source_record_id', $sourceVersionId)
            ->whereIn('hotel_id', $this->ids($hotelIds))
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    private function candidateRow(int $candidateId, int $tenantId, array $hotelIds, bool $lock = false): array
    {
        if ($candidateId <= 0 || $hotelIds === []) {
            throw new InvalidArgumentException('知识晋级候选ID或酒店范围无效');
        }
        $query = Db::name(self::CANDIDATE_TABLE)
            ->where('id', $candidateId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at');
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('knowledge promotion candidate not found');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function revisionRow(int $revisionId, int $candidateId): array
    {
        $row = Db::name(self::REVISION_TABLE)
            ->where('id', $revisionId)
            ->where('candidate_id', $candidateId)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('knowledge promotion revision not found');
        }
        return $this->normalizeRevision($row);
    }

    /** @param array<string,mixed> $candidate */
    private function assertSubmissionReceiptIfRequired(array $candidate): void
    {
        $status = strtolower(trim((string)($candidate['workflow_status'] ?? '')));
        $requiresReceipt = in_array($status, ['in_review', 'changes_requested', 'rejected', 'approved'], true)
            || ($status === 'withdrawn' && (int)($candidate['promoted_sop_version_id'] ?? 0) > 0);
        if (!$requiresReceipt) {
            return;
        }
        $revision = is_array($candidate['current_revision'] ?? null)
            ? $candidate['current_revision']
            : [];
        if ((int)($revision['submitted_by'] ?? 0) <= 0
            || trim((string)($revision['submitted_at'] ?? '')) === ''
        ) {
            throw new RuntimeException('knowledge promotion revision submission receipt is missing');
        }
    }

    /** @param array<string,mixed> $revision */
    private function assertRevisionIntegrity(array $revision): void
    {
        $storedDigest = strtolower(trim((string)($revision['content_digest'] ?? '')));
        if (!(new KnowledgeContentDigestService())->matches(
            $storedDigest,
            $this->visibleRevisionContent($revision)
        )) {
            throw new RuntimeException('knowledge promotion revision content digest mismatch');
        }
    }

    /** @param array<string,mixed> $revision @param array<string,mixed> $version */
    private function assertSopBusinessContentMatchesRevision(
        array $revision,
        array $version,
        string $stage
    ): void {
        $applicability = is_array($revision['applicability'] ?? null)
            ? $revision['applicability']
            : [];
        $scope = is_array($version['scope'] ?? null) ? $version['scope'] : [];
        $reviewed = [
            'title' => (string)($revision['title'] ?? ''),
            'objective' => (string)($revision['objective'] ?? ''),
            'steps' => array_values((array)($revision['steps'] ?? [])),
            'stop_conditions' => array_values((array)($revision['stop_conditions'] ?? [])),
            'platform' => strtolower(trim((string)($applicability['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($applicability['source_scope'] ?? ''))),
            'applicable_data_types' => array_values((array)($applicability['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($applicability['metric_definitions'] ?? [])),
            'replication_scope' => (string)($applicability['replication_scope'] ?? ''),
        ];
        $actual = [
            'title' => (string)($version['title'] ?? ''),
            'objective' => (string)($version['objective'] ?? ''),
            'steps' => array_values((array)($version['steps'] ?? [])),
            'stop_conditions' => array_values((array)($version['stop_conditions'] ?? [])),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($scope['source_scope'] ?? ''))),
            'applicable_data_types' => array_values((array)($scope['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($scope['metric_definitions'] ?? [])),
            'replication_scope' => (string)($scope['replication_scope'] ?? ''),
        ];
        if (!hash_equals($this->digest($reviewed), $this->digest($actual))) {
            throw new RuntimeException('reviewed knowledge content changed in the ' . $stage . ' SOP version');
        }
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $record */
    private function assertCandidateRevisionReadback(array $candidate, array $record, int $revisionId): void
    {
        $revision = is_array($candidate['current_revision'] ?? null) ? $candidate['current_revision'] : [];
        $failures = [];
        if ((int)($candidate['current_revision_id'] ?? 0) !== $revisionId) {
            $failures[] = 'candidate_revision_id';
        }
        if ((int)($revision['id'] ?? 0) !== $revisionId) {
            $failures[] = 'revision_id';
        }
        if ((int)($revision['revision_no'] ?? 0) !== (int)$record['revision_no']) {
            $failures[] = 'revision_no';
        }
        if (!hash_equals((string)$record['source_digest'], (string)($revision['source_digest'] ?? ''))) {
            $failures[] = 'source_digest';
        }
        if (!hash_equals((string)$record['content_digest'], (string)($revision['content_digest'] ?? ''))) {
            $failures[] = 'stored_content_digest';
        }
        if (!hash_equals((string)$record['content_digest'], $this->digest($this->visibleRevisionContent($revision)))) {
            $failures[] = 'recomputed_content_digest';
        }
        if ($failures !== []) {
            throw new RuntimeException('正式知识候选修订已写入但严格回读失败：' . implode(',', $failures));
        }
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    private function candidateResponse(
        array $candidate,
        bool $created,
        ?array $event,
        string $operationStatus
    ): array {
        return [
            'candidate' => $candidate,
            'event' => $event,
            'created' => $created,
            'operation_status' => $operationStatus,
            'persistence_status' => 'readback_verified',
            'write_boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string,bool|string> */
    private function boundaries(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'runtime_json_is_formal_source' => false,
            'causality_verified' => false,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
            'knowledge_write_before_approval' => false,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeCandidate(array $row): array
    {
        foreach ([
            'id', 'tenant_id', 'hotel_id', 'source_record_id', 'current_revision_id',
            'current_revision_no', 'row_version', 'created_by',
        ] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        foreach ([
            'assigned_reviewer_id', 'promoted_sop_version_id',
            'promoted_knowledge_unit_id', 'promoted_knowledge_chunk_id',
        ] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRevision(array $row): array
    {
        foreach (['id', 'candidate_id', 'revision_no', 'source_sop_candidate_version_id', 'created_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        $row['submitted_by'] = isset($row['submitted_by']) ? (int)$row['submitted_by'] : null;
        foreach ([
            'steps_json' => 'steps',
            'stop_conditions_json' => 'stop_conditions',
            'applicability_json' => 'applicability',
            'scope_json' => 'scope',
            'evidence_refs_json' => 'evidence_refs',
            'outcome_refs_json' => 'outcome_refs',
            'conflict_refs_json' => 'conflict_refs',
        ] as $jsonField => $publicField) {
            $row[$publicField] = $this->decode($row[$jsonField] ?? null);
            unset($row[$jsonField]);
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeEvent(array $row): array
    {
        foreach (['id', 'tenant_id', 'hotel_id', 'candidate_id', 'actor_id'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        $row['revision_id'] = isset($row['revision_id']) ? (int)$row['revision_id'] : null;
        $row['payload'] = $this->decode($row['payload_json'] ?? null);
        unset($row['payload_json']);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeKnowledgeUnit(array $row): array
    {
        foreach (['unit_id', 'hotel_id', 'created_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        $row['current_chunk_id'] = isset($row['current_chunk_id']) ? (int)$row['current_chunk_id'] : null;
        $row['tags'] = $this->decode($row['tags'] ?? null);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeKnowledgeChunk(array $row): array
    {
        foreach (['chunk_id', 'unit_id', 'created_by'] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
        }
        foreach ([
            'promotion_candidate_id', 'operating_sop_version_id',
            'version_no', 'superseded_by_chunk_id',
        ] as $field) {
            $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
        }
        $row['content'] = $this->decode($row['content'] ?? null);
        return $row;
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed>|null */
    private function readPromotedKnowledge(array $candidate): ?array
    {
        $unitId = (int)($candidate['promoted_knowledge_unit_id'] ?? 0);
        $chunkId = (int)($candidate['promoted_knowledge_chunk_id'] ?? 0);
        $versionId = (int)($candidate['promoted_sop_version_id'] ?? 0);
        if ($unitId <= 0 && $chunkId <= 0 && $versionId <= 0) {
            return null;
        }
        if ($unitId <= 0 || $chunkId <= 0 || $versionId <= 0) {
            throw new RuntimeException('formal knowledge promotion identity is only partially persisted');
        }
        $unit = Db::name('knowledge_units')
            ->where('unit_id', $unitId)
            ->where('hotel_id', (int)$candidate['hotel_id'])
            ->find();
        $chunk = Db::name('knowledge_chunks')
            ->where('chunk_id', $chunkId)
            ->where('unit_id', $unitId)
            ->where('promotion_candidate_id', (int)$candidate['id'])
            ->where('operating_sop_version_id', $versionId)
            ->find();
        if (!is_array($unit) || !is_array($chunk)) {
            throw new RuntimeException('正式知识投影不存在或酒店身份不一致');
        }
        $version = $this->sopService->readVersion(
            $versionId,
            (int)$candidate['tenant_id'],
            [(int)$candidate['hotel_id']]
        );
        $content = $this->decode($chunk['content'] ?? null);
        $digestService = new KnowledgeContentDigestService();
        if (!$digestService->matches((string)($chunk['content_digest'] ?? ''), $content)) {
            throw new RuntimeException('formal knowledge content digest mismatch');
        }

        $chunkLifecycle = strtolower(trim((string)($chunk['lifecycle_status'] ?? '')));
        $versionLifecycle = strtolower(trim((string)($version['lifecycle_status'] ?? '')));
        $workflowStatus = strtolower(trim((string)($candidate['workflow_status'] ?? '')));
        $unitCurrentChunkId = (int)($unit['current_chunk_id'] ?? 0);
        $isCurrent = $unitCurrentChunkId === $chunkId;
        if (!in_array($workflowStatus, ['approved', 'withdrawn'], true)
            || !in_array($chunkLifecycle, ['active', 'superseded', 'retired'], true)
            || $versionLifecycle !== $chunkLifecycle
            || ($chunkLifecycle === 'active' && !$isCurrent)
            || ($chunkLifecycle !== 'active' && $isCurrent)
            || ($chunkLifecycle === 'superseded' && (int)($chunk['superseded_by_chunk_id'] ?? 0) <= 0)
            || ($workflowStatus === 'withdrawn' && $chunkLifecycle !== 'retired')
            || ($workflowStatus === 'approved' && $chunkLifecycle === 'retired')
        ) {
            throw new RuntimeException('formal knowledge lifecycle or current-version pointer mismatch');
        }
        if (($unit['source'] ?? '') !== 'formal_operating_sop'
            || ($unit['status'] ?? '') !== 'done'
            || ($unitCurrentChunkId > 0 && ($unit['lifecycle_status'] ?? '') !== 'active')
            || ($unitCurrentChunkId === 0 && ($unit['lifecycle_status'] ?? '') !== 'stale')
        ) {
            throw new RuntimeException('formal knowledge unit lifecycle mismatch');
        }

        $revision = is_array($candidate['current_revision'] ?? null)
            ? $candidate['current_revision']
            : [];
        $this->assertRevisionIntegrity($revision);
        $this->assertSopBusinessContentMatchesRevision($revision, $version, 'promoted');
        if ((int)($content['knowledge_unit_id'] ?? 0) !== $unitId
            || (int)($content['promotion_candidate_id'] ?? 0) !== (int)$candidate['id']
            || (int)($content['promotion_revision_id'] ?? 0) !== (int)($revision['id'] ?? 0)
            || !hash_equals(
                (string)($content['promotion_revision_digest'] ?? ''),
                (string)($revision['content_digest'] ?? '')
            )
            || (int)($content['operating_sop_version_id'] ?? 0) !== $versionId
            || !hash_equals(
                (string)($content['operating_sop_content_digest'] ?? ''),
                (string)($version['content_digest'] ?? '')
            )
            || ($content['content_type'] ?? '') !== 'sop_card'
            || ($content['formal_record_type'] ?? '') !== 'operating_sop'
            || ($content['causality_verified'] ?? null) !== false
        ) {
            throw new RuntimeException('formal knowledge projection identity or reviewed content mismatch');
        }

        $normalizedChunk = $this->normalizeKnowledgeChunk($chunk);
        $normalizedChunk['is_current'] = $isCurrent;
        $normalizedChunk['integrity_status'] = 'verified';
        return [
            'knowledge_unit' => $this->normalizeKnowledgeUnit($unit),
            'knowledge_chunk' => $normalizedChunk,
            'operating_sop_version' => $version,
            'integrity_status' => 'verified',
            'is_current' => $isCurrent,
        ];
    }

    /** @param array<string,mixed> $input */
    private function assertCallerDidNotOverrideIdentity(array $input): void
    {
        foreach ([
            'tenant_id', 'hotel_id', 'platform', 'source_scope', 'source_memory_ids',
            'evidence_refs', 'evidence_date_start', 'evidence_date_end', 'causality_verified',
            'source_sop_candidate_version_id',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                throw new InvalidArgumentException('内容修订不能改写来源身份字段：' . $field);
            }
        }
    }

    private function eventIdempotencyKey(
        int $candidateId,
        string $eventType,
        int $actorId,
        string $clientKey,
        mixed $fingerprint
    ): string {
        $clientKey = trim($clientKey);
        if (mb_strlen($clientKey) > 191) {
            throw new InvalidArgumentException('知识晋级幂等键不能超过191字');
        }
        return 'knowledge-promotion:v1:' . $candidateId . ':' . $eventType . ':' . substr($this->digest([
            'actor_id' => $actorId,
            'client_key' => $clientKey,
            'fingerprint' => $clientKey === '' ? $fingerprint : null,
        ]), 0, 48);
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('知识晋级操作缺少有效的登录用户');
        }
    }

    private function assertHotelIdentity(int $tenantId, int $hotelId): void
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('知识晋级缺少有效的租户或酒店身份');
        }
        $actualTenantId = (int)Db::name('hotels')
            ->where('id', $hotelId)
            ->where('status', 1)
            ->value('tenant_id');
        if ($actualTenantId <= 0 || $actualTenantId !== $tenantId) {
            throw new RuntimeException('知识晋级酒店与租户身份不一致');
        }
    }

    private function assertTablesReady(): void
    {
        foreach ([
            self::CANDIDATE_TABLE,
            self::REVISION_TABLE,
            self::EVENT_TABLE,
            OperatingSopService::VERSION_TABLE,
            OperatingSopService::REPLICATION_TABLE,
            OperatingMemoryService::TABLE,
            'hotels',
            'knowledge_units',
            'knowledge_chunks',
        ] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('正式知识晋级功能尚未启用：缺少数据表 ' . $table);
            }
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

    /** @return list<string> */
    private function workflowStatuses(): array
    {
        return ['draft', 'in_review', 'changes_requested', 'rejected', 'approved', 'withdrawn'];
    }

    private function note(mixed $value, bool $required): string
    {
        $note = mb_substr(trim((string)$value), 0, 1000);
        if ($required && $note === '') {
            throw new InvalidArgumentException('审核、拒绝、撤回或停用必须填写说明');
        }
        return $note;
    }

    private function dateTime(string $value, string $label): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /** @return list<string> */
    private function contentList(mixed $value, string $label, bool $required): array
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

    /** @param array<mixed> $values @return list<int> */
    private function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    /** @param array<mixed> $values @return list<string> */
    private function textRefs(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => mb_substr(trim((string)$value), 0, 500),
            $values
        ), static fn(string $value): bool => $value !== '')));
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
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
    }

    private function digest(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }
}
