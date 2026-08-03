<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Binds a taskable knowledge SOP to one immutable source snapshot.
 *
 * A draft is not authority to execute forever. Approval and execution must
 * re-read the same unit/chunk, target hotel and platform, then pass the shared
 * knowledge decision gate again.
 */
final class KnowledgeSopExecutionProvenanceService
{
    public const CONTRACT_VERSION = 'knowledge_sop_execution_provenance_v1';

    /**
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $chunk
     * @return array<string, mixed>
     */
    public function validateSnapshot(
        array $unit,
        array $chunk,
        int $targetHotelId,
        string $requestedPlatform,
        mixed $asOf = null
    ): array {
        $unitId = (int)($unit['unit_id'] ?? 0);
        $chunkId = (int)($chunk['chunk_id'] ?? 0);
        if ($unitId <= 0 || $chunkId <= 0 || (int)($chunk['unit_id'] ?? 0) !== $unitId) {
            throw new \InvalidArgumentException('knowledge SOP source identity is invalid');
        }
        if ($targetHotelId <= 0) {
            throw new \InvalidArgumentException('knowledge SOP target hotel is required');
        }
        if (strtolower(trim((string)($unit['status'] ?? ''))) !== 'done') {
            throw new \InvalidArgumentException('knowledge SOP unit must be completed before task creation');
        }

        $unitHotelId = (int)($unit['hotel_id'] ?? 0);
        $unitCreatedBy = (int)($unit['created_by'] ?? 0);
        if ($unitHotelId > 0 && $unitHotelId !== $targetHotelId) {
            throw new \InvalidArgumentException('knowledge SOP hotel does not match the task target hotel');
        }
        if ($unitHotelId === 0 && $unitCreatedBy !== 0) {
            throw new \InvalidArgumentException('only system global knowledge may be used across hotels');
        }

        $content = $this->content($chunk['content'] ?? []);
        $formalAuthority = $this->assertFormalKnowledgeCurrent(
            $unit,
            $chunk,
            $content,
            $targetHotelId
        );
        $template = is_array($content['task_template'] ?? null) ? $content['task_template'] : [];
        if (strtolower(trim((string)($content['content_type'] ?? ''))) !== 'sop_card') {
            throw new \InvalidArgumentException('knowledge chunk is not a taskable SOP card');
        }
        if (trim((string)($template['title'] ?? '')) === ''
            || $this->nonEmptyList($template['steps'] ?? []) === []
            || $this->nonEmptyList($template['acceptance_criteria'] ?? []) === []
        ) {
            throw new \InvalidArgumentException('knowledge SOP task template is incomplete');
        }

        $declaredPlatforms = $this->platforms($content['platforms'] ?? []);
        $platform = $this->resolveTargetPlatform($requestedPlatform, $declaredPlatforms);
        $knowledgeGate = (new KnowledgeDecisionGateService())->assess($unit, $content, $asOf);
        if (($knowledgeGate['task_draft_safe'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'knowledge SOP is stale, conflicting, unverified, or otherwise unsafe for a task draft'
            );
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'content_digest' => $this->digest($content),
            'unit_authority_digest' => $this->digest($this->unitAuthority($unit)),
            'formal_authority_digest' => $formalAuthority === null
                ? ''
                : $this->digest($formalAuthority),
            'target_hotel_id' => $targetHotelId,
            'unit_hotel_id' => $unitHotelId,
            'resolved_platform' => $platform,
            'declared_platforms' => $declaredPlatforms,
            'gate_status' => (string)($knowledgeGate['status'] ?? 'blocked'),
            'evidence_grade' => (string)($knowledgeGate['evidence_grade'] ?? 'U'),
            'gate_as_of' => (string)($knowledgeGate['as_of'] ?? ''),
            'knowledge_gate' => $knowledgeGate,
        ];
    }

    /**
     * Re-read and validate a stored operation intent before approval/execution.
     *
     * @param array<string, mixed> $intent normalized execution intent
     * @return array<string, mixed>
     */
    public function assertIntentCurrent(array $intent, bool $lockSource = false): array
    {
        if (strtolower(trim((string)($intent['source_module'] ?? ''))) !== 'knowledge_sop') {
            return [];
        }

        $stored = $this->storedProvenance($intent);
        $unitId = (int)$stored['knowledge_unit_id'];
        $chunkId = (int)$stored['knowledge_chunk_id'];
        $unitQuery = Db::name('knowledge_units')->where('unit_id', $unitId);
        $chunkQuery = Db::name('knowledge_chunks')
            ->where('chunk_id', $chunkId)
            ->where('unit_id', $unitId);
        if ($lockSource) {
            $unitQuery->lock(true);
            $chunkQuery->lock(true);
        }
        $unit = $unitQuery->find();
        $chunk = $chunkQuery->find();
        if (!is_array($unit) || !is_array($chunk)) {
            throw new \InvalidArgumentException(
                'knowledge SOP source was removed; create a new execution intent'
            );
        }

        return $this->assertSnapshotMatches($intent, $unit, $chunk);
    }

    /**
     * DB-free provenance comparison used by the transaction path and tests.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $chunk
     * @return array<string, mixed>
     */
    public function assertSnapshotMatches(
        array $intent,
        array $unit,
        array $chunk,
        mixed $asOf = null
    ): array {
        $stored = $this->storedProvenance($intent);
        $unitId = (int)$stored['knowledge_unit_id'];
        $chunkId = (int)$stored['knowledge_chunk_id'];
        if ((int)($unit['unit_id'] ?? 0) !== $unitId
            || (int)($chunk['chunk_id'] ?? 0) !== $chunkId
            || (int)($chunk['unit_id'] ?? 0) !== $unitId
        ) {
            throw new \InvalidArgumentException(
                'knowledge SOP provenance identity changed; create a new execution intent'
            );
        }

        $current = $this->validateSnapshot(
            $unit,
            $chunk,
            (int)($intent['hotel_id'] ?? 0),
            (string)($intent['platform'] ?? ''),
            $asOf
        );
        $digestFields = ['content_digest', 'unit_authority_digest'];
        if (trim((string)($current['formal_authority_digest'] ?? '')) !== ''
            || trim((string)($stored['formal_authority_digest'] ?? '')) !== ''
        ) {
            $digestFields[] = 'formal_authority_digest';
        }
        foreach ($digestFields as $field) {
            $storedDigest = strtolower(trim((string)($stored[$field] ?? '')));
            $currentDigest = strtolower(trim((string)($current[$field] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $currentDigest) !== 1
                || !hash_equals($storedDigest, $currentDigest)
            ) {
                throw new \InvalidArgumentException(
                    'knowledge SOP source changed; create a new execution intent'
                );
            }
        }
        foreach (['target_hotel_id', 'unit_hotel_id', 'resolved_platform'] as $field) {
            if (!array_key_exists($field, $stored)
                || (string)$stored[$field] !== (string)$current[$field]
            ) {
                throw new \InvalidArgumentException(
                    'knowledge SOP source changed; create a new execution intent'
                );
            }
        }

        return $current;
    }

    /** @param array<string, mixed> $intent @return array<string, mixed> */
    private function storedProvenance(array $intent): array
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $stored = is_array($evidence['knowledge_provenance'] ?? null)
            ? $evidence['knowledge_provenance']
            : [];
        if (($stored['contract_version'] ?? '') !== self::CONTRACT_VERSION) {
            throw new \InvalidArgumentException(
                'knowledge SOP provenance is missing; create a new execution intent'
            );
        }

        $unitId = (int)($stored['knowledge_unit_id'] ?? 0);
        $chunkId = (int)($stored['knowledge_chunk_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        if ($unitId <= 0 || $chunkId <= 0 || $chunkId !== $sourceRecordId) {
            throw new \InvalidArgumentException(
                'knowledge SOP provenance identity changed; create a new execution intent'
            );
        }

        return $stored;
    }

    /**
     * @param array<int, string> $declaredPlatforms
     */
    private function resolveTargetPlatform(string $requestedPlatform, array $declaredPlatforms): string
    {
        if ($declaredPlatforms === []) {
            throw new \InvalidArgumentException('knowledge SOP platform scope is missing');
        }

        $requested = $this->normalizePlatform($requestedPlatform);
        $wildcardDeclared = array_intersect($declaredPlatforms, ['all', 'all_ota', 'ota']) !== [];
        if ($requested === '' || in_array($requested, ['ota', 'all', 'all_ota'], true)) {
            $specific = array_values(array_diff($declaredPlatforms, ['all', 'all_ota', 'ota']));
            if (count($specific) === 1) {
                return $specific[0];
            }
            if ($wildcardDeclared) {
                return 'all_ota';
            }
            throw new \InvalidArgumentException(
                'select one platform because this knowledge SOP declares multiple platform scopes'
            );
        }
        if ($requested !== '' && ($wildcardDeclared || in_array($requested, $declaredPlatforms, true))) {
            return $requested;
        }

        throw new \InvalidArgumentException(
            'knowledge SOP platform does not match the task target platform'
        );
    }

    /**
     * Formal knowledge is managed only by the promotion/version workflow. A
     * historical or retired chunk remains readable for audit, but can never be
     * used to create, approve, or execute a task.
     *
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $chunk
     * @param array<string, mixed> $content
     * @return array<string, mixed>|null
     */
    private function assertFormalKnowledgeCurrent(
        array $unit,
        array $chunk,
        array $content,
        int $targetHotelId
    ): ?array {
        $candidateId = (int)($chunk['promotion_candidate_id'] ?? $content['promotion_candidate_id'] ?? 0);
        $versionId = (int)($chunk['operating_sop_version_id'] ?? $content['operating_sop_version_id'] ?? 0);
        $formal = strtolower(trim((string)($unit['source'] ?? ''))) === 'formal_operating_sop'
            || strtolower(trim((string)($chunk['type'] ?? ''))) === 'formal_operating_sop'
            || strtolower(trim((string)($content['formal_record_type'] ?? ''))) === 'operating_sop'
            || $candidateId > 0
            || $versionId > 0;
        if (!$formal) {
            return null;
        }

        $unitId = (int)($unit['unit_id'] ?? 0);
        $chunkId = (int)($chunk['chunk_id'] ?? 0);
        $revisionId = (int)($content['promotion_revision_id'] ?? 0);
        $digestService = new KnowledgeContentDigestService();
        if ($candidateId <= 0 || $versionId <= 0 || $revisionId <= 0
            || (int)($content['promotion_candidate_id'] ?? 0) !== $candidateId
            || (int)($content['operating_sop_version_id'] ?? 0) !== $versionId
            || (int)($content['knowledge_unit_id'] ?? 0) !== $unitId
            || (int)($unit['hotel_id'] ?? 0) !== $targetHotelId
            || strtolower(trim((string)($unit['source'] ?? ''))) !== 'formal_operating_sop'
            || strtolower(trim((string)($unit['lifecycle_status'] ?? ''))) !== 'active'
            || (int)($unit['current_chunk_id'] ?? 0) !== $chunkId
            || strtolower(trim((string)($chunk['type'] ?? ''))) !== 'formal_operating_sop'
            || strtolower(trim((string)($chunk['lifecycle_status'] ?? ''))) !== 'active'
            || strtolower(trim((string)($content['lifecycle_status'] ?? ''))) !== 'active'
            || !$digestService->matches((string)($chunk['content_digest'] ?? ''), $content)
        ) {
            throw new \InvalidArgumentException(
                'formal knowledge is not the current active verified content; create a new execution intent'
            );
        }

        $candidate = Db::name(KnowledgePromotionService::CANDIDATE_TABLE)
            ->where('id', $candidateId)
            ->where('hotel_id', $targetHotelId)
            ->where('workflow_status', 'approved')
            ->where('promoted_sop_version_id', $versionId)
            ->where('promoted_knowledge_unit_id', $unitId)
            ->where('promoted_knowledge_chunk_id', $chunkId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($candidate)) {
            throw new \InvalidArgumentException(
                'formal knowledge approval record is no longer current; create a new execution intent'
            );
        }
        $tenantId = (int)($candidate['tenant_id'] ?? 0);
        if ($tenantId <= 0 || (int)($candidate['current_revision_id'] ?? 0) !== $revisionId) {
            throw new \InvalidArgumentException('formal knowledge approval identity is invalid');
        }

        $revision = Db::name(KnowledgePromotionService::REVISION_TABLE)
            ->where('id', $revisionId)
            ->where('candidate_id', $candidateId)
            ->find();
        $version = Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', $versionId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $targetHotelId)
            ->where('validation_status', 'verified')
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($revision) || !is_array($version)) {
            throw new \InvalidArgumentException(
                'formal knowledge revision or verified SOP version is no longer active'
            );
        }

        $revisionContent = [
            'title' => (string)($revision['title'] ?? ''),
            'objective' => (string)($revision['objective'] ?? ''),
            'steps' => $this->content($revision['steps_json'] ?? []),
            'stop_conditions' => $this->content($revision['stop_conditions_json'] ?? []),
            'applicability' => $this->content($revision['applicability_json'] ?? []),
            'scope' => $this->content($revision['scope_json'] ?? []),
            'evidence_refs' => $this->content($revision['evidence_refs_json'] ?? []),
            'outcome_refs' => $this->content($revision['outcome_refs_json'] ?? []),
            'conflict_refs' => $this->content($revision['conflict_refs_json'] ?? []),
        ];
        $versionContent = [
            'tenant_id' => (int)($version['tenant_id'] ?? 0),
            'hotel_id' => (int)($version['hotel_id'] ?? 0),
            'sop_key' => (string)($version['sop_key'] ?? ''),
            'title' => (string)($version['title'] ?? ''),
            'objective' => (string)($version['objective'] ?? ''),
            'steps' => $this->content($version['steps_json'] ?? []),
            'stop_conditions' => $this->content($version['stop_conditions_json'] ?? []),
            'scope' => $this->content($version['scope_json'] ?? []),
            'source_memory_ids' => array_values(array_unique(array_filter(array_map(
                'intval',
                $this->content($version['source_memory_ids_json'] ?? [])
            ), static fn(int $id): bool => $id > 0))),
            'evidence_refs' => array_values($this->content($version['evidence_refs_json'] ?? [])),
            'validation_status' => (string)($version['validation_status'] ?? ''),
            'validation_note' => (string)($version['validation_note'] ?? ''),
            'created_by' => (int)($version['created_by'] ?? 0),
            'validated_by' => (int)($version['validated_by'] ?? 0),
            'validated_at' => $version['validated_at'] ?? null,
        ];
        if (!$digestService->matches((string)($revision['content_digest'] ?? ''), $revisionContent)
            || !$digestService->matches((string)($version['content_digest'] ?? ''), $versionContent)
            || !hash_equals(
                (string)($revision['content_digest'] ?? ''),
                (string)($content['promotion_revision_digest'] ?? '')
            )
            || !hash_equals(
                (string)($version['content_digest'] ?? ''),
                (string)($content['operating_sop_content_digest'] ?? '')
            )
        ) {
            throw new \InvalidArgumentException(
                'formal knowledge approval content failed immutable digest verification'
            );
        }
        $applicability = is_array($revisionContent['applicability'] ?? null)
            ? $revisionContent['applicability']
            : [];
        $versionScope = is_array($versionContent['scope'] ?? null) ? $versionContent['scope'] : [];
        $reviewedBusiness = [
            'title' => $revisionContent['title'],
            'objective' => $revisionContent['objective'],
            'steps' => $revisionContent['steps'],
            'stop_conditions' => $revisionContent['stop_conditions'],
            'platform' => strtolower(trim((string)($applicability['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($applicability['source_scope'] ?? ''))),
            'applicable_data_types' => array_values((array)($applicability['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($applicability['metric_definitions'] ?? [])),
            'replication_scope' => (string)($applicability['replication_scope'] ?? ''),
        ];
        $versionBusiness = [
            'title' => $versionContent['title'],
            'objective' => $versionContent['objective'],
            'steps' => $versionContent['steps'],
            'stop_conditions' => $versionContent['stop_conditions'],
            'platform' => strtolower(trim((string)($versionScope['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($versionScope['source_scope'] ?? ''))),
            'applicable_data_types' => array_values((array)($versionScope['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($versionScope['metric_definitions'] ?? [])),
            'replication_scope' => (string)($versionScope['replication_scope'] ?? ''),
        ];
        $projectedBusiness = [
            'title' => (string)($content['title'] ?? ''),
            'objective' => (string)($content['objective'] ?? ''),
            'steps' => array_values((array)($content['steps'] ?? [])),
            'stop_conditions' => array_values((array)($content['stop_conditions'] ?? [])),
            'platform' => strtolower(trim((string)($content['platform'] ?? ''))),
            'source_scope' => strtolower(trim((string)($content['source_scope'] ?? ''))),
            'applicable_data_types' => array_values((array)($content['scope_details']['applicable_data_types'] ?? [])),
            'metric_definitions' => array_values((array)($content['scope_details']['metric_definitions'] ?? [])),
            'replication_scope' => (string)($content['scope_details']['replication_scope'] ?? ''),
        ];
        if (!hash_equals($digestService->digest($reviewedBusiness), $digestService->digest($versionBusiness))
            || !hash_equals($digestService->digest($versionBusiness), $digestService->digest($projectedBusiness))
        ) {
            throw new \InvalidArgumentException(
                'formal knowledge business content differs from its reviewed SOP revision'
            );
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $targetHotelId,
            'candidate_id' => $candidateId,
            'revision_id' => $revisionId,
            'revision_digest' => (string)$revision['content_digest'],
            'sop_version_id' => $versionId,
            'sop_version_digest' => (string)$version['content_digest'],
            'knowledge_unit_id' => $unitId,
            'knowledge_chunk_id' => $chunkId,
            'knowledge_content_digest' => (string)$chunk['content_digest'],
            'lifecycle_status' => 'active',
        ];
    }

    /** @return array<int, string> */
    private function platforms(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string)$value);
        $platforms = [];
        foreach ((array)$values as $item) {
            $platform = $this->normalizePlatform((string)$item);
            if ($platform !== '') {
                $platforms[] = $platform;
            }
        }
        $platforms = array_values(array_unique($platforms));
        sort($platforms, SORT_STRING);
        return $platforms;
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        return match ($platform) {
            'trip', 'xiecheng' => 'ctrip',
            '*' => 'all',
            default => preg_match('/^[a-z0-9_-]{1,40}$/D', $platform) === 1 ? $platform : '',
        };
    }

    /** @return array<int, mixed> */
    private function nonEmptyList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn(mixed $item): bool => is_array($item)
                ? $item !== []
                : trim((string)$item) !== ''
        ));
    }

    /** @return array<string, mixed> */
    private function content(mixed $value): array
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

    /** @param array<string, mixed> $unit @return array<string, mixed> */
    private function unitAuthority(array $unit): array
    {
        $authority = [];
        foreach ([
            'unit_id',
            'hotel_id',
            'stable_key',
            'current_chunk_id',
            'source',
            'created_by',
            'status',
            'lifecycle_status',
            'lifecycle_reason',
            'reviewed_at',
            'review_due_at',
            'truth_profile_version',
        ] as $field) {
            if (array_key_exists($field, $unit)) {
                $authority[$field] = $unit[$field];
            }
        }
        return $authority;
    }

    private function digest(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }
}
