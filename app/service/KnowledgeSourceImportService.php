<?php
declare(strict_types=1);
namespace app\service;

use app\model\KnowledgeUnit;
use app\model\KnowledgeChunk;
use RuntimeException;
use think\facade\Db;

/** Scoped source identity and retry history for the existing import endpoint. */
final class KnowledgeSourceImportService
{
    public function identity(int $hotelId, int $actorId, string $raw, string $mode, string $model, array $context): string
    {
        if ($hotelId <= 0 || $actorId <= 0) throw new RuntimeException('知识来源范围冲突：酒店与创建者无效', 409);
        $source = $context['source_document']['sha256'] ?? hash('sha256', $raw);
        return 'material:' . $hotelId . ':' . $actorId . ':' . hash('sha256', $source . '|' . $mode . '|' . $model . '|source-trace-v1');
    }

    public function completed(string $key): ?array
    {
        $unit = KnowledgeUnit::where('stable_key', $key)->where('status', 'done')->find();
        if (!$unit) return null;
        $this->assertUnitScope($unit->toArray(), $key);
        $chunk = KnowledgeChunk::where('unit_id', $unit->unit_id)->where('chunk_id', $unit->current_chunk_id)->find();
        if (!$chunk) throw new RuntimeException('既有知识来源当前版本缺失');
        $row = $chunk->toArray();
        if ((int)($row['created_by'] ?? 0) !== (int)$unit->created_by
            || ($row['lifecycle_status'] ?? 'active') !== 'active'
            || ($row['content']['lifecycle_status'] ?? 'active') !== 'active'
            || ($row['content']['ingestion']['identity'] ?? '') !== $key) {
            throw new RuntimeException('知识来源版本状态冲突，请重新核对当前来源', 409);
        }
        if (!(new KnowledgeContentDigestService())->matches((string)($row['content_digest'] ?? ''), $row['content'])) throw new RuntimeException('既有知识来源指纹校验失败');
        return ['unit' => $unit->toArray(), 'chunk' => $row, 'reused' => true];
    }

    public function persist(array $unitData, array $content, string $key): array
    {
        $this->assertUnitScope($unitData + ['stable_key' => $key], $key);
        return Db::transaction(function () use ($unitData, $content, $key) {
            Db::name('hotels')->where('id', $unitData['hotel_id'])->lock(true)->find();
            $completed = $this->completed($key);
            if ($completed) return $completed;
            $unit = KnowledgeUnit::where('stable_key', $key)->lock(true)->find();
            $attempt = 1;
            $digest = new KnowledgeContentDigestService();
            if ($unit) {
                $this->assertUnitScope($unit->toArray(), $key);
                $attempt = (int)KnowledgeChunk::where('unit_id', $unit->unit_id)->count() + 1;
                $prior = KnowledgeChunk::where('unit_id', $unit->unit_id)->where('chunk_id', $unit->current_chunk_id)->find();
                if ((int)$unit->current_chunk_id > 0 && !$prior) {
                    throw new RuntimeException('既有知识来源当前版本缺失或范围不一致', 409);
                }
                if ($prior) {
                    if ((int)$prior->created_by !== (int)$unit->created_by) {
                        throw new RuntimeException('知识来源当前版本范围不一致', 409);
                    }
                    $priorContent = $prior->content;
                    if (($prior->lifecycle_status ?? 'active') !== 'active'
                        || ($priorContent['lifecycle_status'] ?? 'active') !== 'active'
                        || !$digest->matches((string)$prior->content_digest, $priorContent)) {
                        throw new RuntimeException('知识来源当前版本状态或指纹不一致', 409);
                    }
                    $priorContent['lifecycle_status'] = 'superseded';
                    $prior->save(['content' => $priorContent, 'content_digest' => $digest->digest($priorContent), 'lifecycle_status' => 'superseded']);
                }
                $unit->save($unitData);
            } else {
                $unit = KnowledgeUnit::create($unitData + ['stable_key' => $key, 'lifecycle_status' => 'active']);
            }
            $content['source_segments'] = (new KnowledgeReferenceService())->segments((string)$content['raw_text']);
            $content['ingestion'] = ['identity' => $key, 'attempt' => $attempt, 'parser_version' => 'source-trace-v1',
                'stage' => $unitData['status'] === 'done' ? 'ready_for_reference_review' : ($content['failure_stage'] ?? 'analysis_failed'),
                'failure_code' => $unitData['status'] === 'done' ? null : ($content['failure_code'] ?? 'ANALYSIS_FAILED'),
                'claims_source_status' => 'not_individually_verified_use_reference_editor'];
            $content['lifecycle_status'] = 'active';
            $chunk = KnowledgeChunk::create(['unit_id' => $unit->unit_id, 'type' => 'AI资料蒸馏', 'content' => $content,
                'content_digest' => $digest->digest($content), 'lifecycle_status' => 'active', 'created_by' => $unitData['created_by']]);
            $unit->save(['current_chunk_id' => $chunk->chunk_id]);
            $actualUnit = KnowledgeUnit::find($unit->unit_id);
            $actualChunk = KnowledgeChunk::where('unit_id', $unit->unit_id)->where('chunk_id', $chunk->chunk_id)->find();
            if (!$actualUnit || !$actualChunk
                || (int)$actualUnit->current_chunk_id !== (int)$actualChunk->chunk_id
                || (string)$actualUnit->stable_key !== $key
                || ($actualUnit->lifecycle_status ?? 'active') !== 'active'
                || (int)$actualChunk->created_by !== (int)$unitData['created_by']
                || ($actualChunk->lifecycle_status ?? 'active') !== 'active'
                || !hash_equals($digest->digest($content), (string)$actualChunk->content_digest)
                || !$digest->matches((string)$actualChunk->content_digest, $actualChunk->content)) {
                throw new RuntimeException('资料保存独立回读不一致');
            }
            foreach ($unitData as $field => $expected) {
                $actual = $actualUnit->toArray()[$field] ?? null;
                if (is_array($expected) ? $digest->digest($expected) !== $digest->digest($actual) : (string)$expected !== (string)$actual) {
                    throw new RuntimeException('资料单元保存回读不一致：' . $field);
                }
            }
            return ['unit' => $actualUnit->toArray(), 'chunk' => $actualChunk->toArray(), 'reused' => false];
        });
    }

    private function assertUnitScope(array $unit, string $key): void
    {
        if (preg_match('/^material:([1-9][0-9]*):([1-9][0-9]*):[a-f0-9]{64}$/D', $key, $scope) !== 1
            || (string)($unit['stable_key'] ?? '') !== $key
            || (int)($unit['hotel_id'] ?? 0) !== (int)$scope[1]
            || (int)($unit['created_by'] ?? 0) !== (int)$scope[2]) {
            throw new RuntimeException('知识来源范围冲突：酒店或创建者已变化', 409);
        }
        if (($unit['lifecycle_status'] ?? 'active') !== 'active') {
            throw new RuntimeException('知识来源版本状态冲突：来源已失效', 409);
        }
    }
}
