<?php
declare(strict_types=1);

namespace app\service;

use app\model\KnowledgeChunk;
use RuntimeException;
use think\exception\ValidateException;
use think\facade\Db;

/** Append-only revisions inside existing knowledge_chunks; never publishes a SOP. */
final class KnowledgeRevisionService
{
    public function save(array $unit, array $data, array $input, array $context): array
    {
        $unitId = (int)$unit['unit_id'];
        $parentId = max(0, (int)($input['replaces_chunk_id'] ?? 0));
        $expected = strtolower(trim((string)($input['expected_digest'] ?? '')));
        if ($parentId > 0 && !(new KnowledgeContentDigestService())->isValid($expected)) {
            throw new ValidateException('expected_digest is required for a revision');
        }
        $requestId = trim((string)($input['request_id'] ?? ''));
        if ($requestId !== '' && preg_match('/^[A-Za-z0-9._-]{8,96}$/D', $requestId) !== 1) {
            throw new ValidateException('request_id is invalid');
        }
        $digestService = new KnowledgeContentDigestService();
        $requestDigest = $digestService->digest([$data, $parentId, $expected]);
        return Db::transaction(function () use ($unit, $unitId, $parentId, $expected, $requestId, $requestDigest, $digestService, $data, $context): array {
            // Serializes concurrent writers in MySQL; SQLite serializes its writes.
            $locked = Db::name('knowledge_units')->where('unit_id', $unitId)->lock(true)->find();
            if (!$locked || (int)($locked['hotel_id'] ?? 0) !== (int)($unit['hotel_id'] ?? 0)
                || (int)($locked['created_by'] ?? 0) !== (int)($unit['created_by'] ?? 0)) {
                throw new RuntimeException('knowledge_scope_changed', 409);
            }
            $rows = KnowledgeChunk::where('unit_id', $unitId)->order('chunk_id', 'asc')->select()->toArray();
            foreach ($rows as $row) {
                $revision = self::content($row)['knowledge_revision'] ?? [];
                if ($requestId !== '' && ($revision['request_id'] ?? '') === $requestId) {
                    if (!hash_equals((string)($revision['request_digest'] ?? ''), $requestDigest)) {
                        throw new RuntimeException('knowledge_request_id_conflict', 409);
                    }
                    $storedContent = self::content($row); unset($storedContent['knowledge_revision']);
                    $storedData = ['unit_id' => $unitId, 'type' => $row['type'], 'content' => $storedContent, 'created_by' => (int)$row['created_by']];
                    if (!$digestService->matches($requestDigest, [$storedData, $parentId, $expected])) throw new RuntimeException('knowledge_exact_readback_failed');
                    $savedId = (int)$row['chunk_id'];
                    $before = array_values(array_filter($rows, static fn($candidate) => (int)$candidate['chunk_id'] < $savedId));
                    return [
                        'chunk' => $row, 'readback_verified' => true, 'replayed' => true,
                        'reevaluation' => (new KnowledgeRetrievalEvaluationService())->compare(
                            [is_array($revision['evaluation_unit'] ?? null) ? $revision['evaluation_unit'] : $unit],
                            $before, [...$before, $row],
                            is_array($revision['evaluation_context'] ?? null) ? $revision['evaluation_context'] : $context
                        ),
                    ];
                }
            }
            $parent = null;
            foreach ($rows as $row) {
                if ((int)$row['chunk_id'] === $parentId) {
                    $parent = $row;
                }
            }
            if ($parentId > 0) {
                if (!$parent || !$digestService->matches($expected, self::content($parent)) || isset(self::supersededIds($rows)[$parentId])) {
                    throw new RuntimeException('knowledge_revision_conflict_reload_required', 409);
                }
                if (!empty($parent['operating_sop_version_id']) || !empty($parent['promotion_candidate_id']) || ($parent['type'] ?? '') === 'formal_operating_sop') {
                    throw new ValidateException('Formal SOPs must use the existing promotion workflow');
                }
            }
            $content = $data['content'];
            $content['knowledge_revision'] = [
                'number' => $parent ? (int)(self::content($parent)['knowledge_revision']['number'] ?? 1) + 1 : 1,
                'parent_chunk_id' => $parentId ?: null,
                'parent_digest' => $parentId ? $expected : null,
                'request_id' => $requestId,
                'request_digest' => $requestDigest,
                // Preserve the evaluation scope and unit metadata for a lost
                // response retry, even after later versions or filter changes.
                'evaluation_context' => array_intersect_key($context, array_flip(['hotel_id', 'user_id', 'tenant_id', 'platform', 'as_of', 'business_date', 'hotel_conditions'])),
                'evaluation_unit' => array_intersect_key($unit, array_flip(['unit_id', 'hotel_id', 'tenant_id', 'created_by', 'name', 'description', 'status', 'source', 'stable_key', 'lifecycle_status', 'reviewed_at', 'review_due_at', 'current_chunk_id'])),
            ];
            $created = KnowledgeChunk::create(array_replace($data, ['content' => $content]));
            $readback = KnowledgeChunk::where('unit_id', $unitId)->where('chunk_id', $created->chunk_id)->find();
            if (!$readback || !$digestService->matches($digestService->digest($content), self::content($readback->toArray()))
                || (string)$readback->type !== (string)$data['type']) {
                throw new RuntimeException('knowledge_exact_readback_failed');
            }
            $after = [...$rows, $readback->toArray()];
            return [
                'chunk' => $readback->toArray(), 'readback_verified' => true, 'replayed' => false,
                'reevaluation' => (new KnowledgeRetrievalEvaluationService())->compare([$unit], $rows, $after, $context),
            ];
        });
    }

    public static function content(array $row): array
    {
        $value = $row['content'] ?? [];
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }

    /** A valid child supersedes a parent even if the child is now blocked/expired. */
    public static function supersededIds(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(int)($row['chunk_id'] ?? 0)] = $row;
        }
        $superseded = [];
        foreach ($rows as $row) {
            $revision = self::content($row)['knowledge_revision'] ?? [];
            $parentId = (int)($revision['parent_chunk_id'] ?? 0);
            $parent = $map[$parentId] ?? null;
            if ($parent && (int)$parent['unit_id'] === (int)$row['unit_id']
                && $parentId < (int)$row['chunk_id']
                && (new KnowledgeContentDigestService())->matches((string)($revision['parent_digest'] ?? ''), self::content($parent))) {
                $superseded[$parentId] = (int)$row['chunk_id'];
            }
        }
        return $superseded;
    }
}
