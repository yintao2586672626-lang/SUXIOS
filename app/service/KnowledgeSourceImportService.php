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
        $source = $context['source_document']['sha256'] ?? hash('sha256', $raw);
        return 'material:' . $hotelId . ':' . $actorId . ':' . hash('sha256', $source . '|' . $mode . '|' . $model . '|source-trace-v1');
    }

    public function completed(string $key): ?array
    {
        $unit = KnowledgeUnit::where('stable_key', $key)->where('status', 'done')->find();
        if (!$unit) return null;
        $chunk = KnowledgeChunk::where('unit_id', $unit->unit_id)->where('chunk_id', $unit->current_chunk_id)->find();
        if (!$chunk) throw new RuntimeException('既有知识来源当前版本缺失');
        $row = $chunk->toArray();
        if (!(new KnowledgeContentDigestService())->matches((string)($row['content_digest'] ?? ''), $row['content'])) throw new RuntimeException('既有知识来源指纹校验失败');
        return ['unit' => $unit->toArray(), 'chunk' => $row, 'reused' => true];
    }

    public function persist(array $unitData, array $content, string $key): array
    {
        return Db::transaction(function () use ($unitData, $content, $key) {
            Db::name('hotels')->where('id', $unitData['hotel_id'])->lock(true)->find();
            $completed = $this->completed($key);
            if ($completed) return $completed;
            $unit = KnowledgeUnit::where('stable_key', $key)->lock(true)->find();
            $attempt = 1;
            $digest = new KnowledgeContentDigestService();
            if ($unit) {
                $attempt = (int)KnowledgeChunk::where('unit_id', $unit->unit_id)->count() + 1;
                $prior = KnowledgeChunk::where('chunk_id', $unit->current_chunk_id)->find();
                if ($prior) {
                    $priorContent = $prior->content;
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
            if (!$actualUnit || !$actualChunk || !$digest->matches($digest->digest($content), $actualChunk->content)) throw new RuntimeException('资料保存独立回读不一致');
            foreach ($unitData as $field => $expected) {
                $actual = $actualUnit->toArray()[$field] ?? null;
                if (is_array($expected) ? $digest->digest($expected) !== $digest->digest($actual) : (string)$expected !== (string)$actual) {
                    throw new RuntimeException('资料单元保存回读不一致：' . $field);
                }
            }
            return ['unit' => $actualUnit->toArray(), 'chunk' => $actualChunk->toArray(), 'reused' => false];
        });
    }
}
