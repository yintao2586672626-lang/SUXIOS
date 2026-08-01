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
        foreach (['content_digest', 'unit_authority_digest'] as $field) {
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
            'created_by',
            'status',
            'lifecycle_status',
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
        $json = json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        );
        return hash('sha256', $json);
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
}
