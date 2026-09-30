<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Human-authored reference cards; never a formal SOP or a business fact. */
final class KnowledgeReferenceService
{
    public function segments(string $text): array
    {
        $result = [];
        $offset = 0;
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $line => $value) {
            if (trim($value) !== '') {
                $result[] = ['id' => 's' . ($line + 1) . '-' . substr(hash('sha256', $value), 0, 10),
                    'locator' => '提取文本第 ' . ($line + 1) . ' 行', 'start' => $offset,
                    'length' => mb_strlen($value), 'quote' => $value];
            }
            $offset += mb_strlen($value) + 1;
        }
        return $result;
    }

    public function source(int $chunkId, int $hotelId, int $actorId): array
    {
        $row = Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->find();
        $unit = $row ? Db::name('knowledge_units')->where('unit_id', $row['unit_id'])->find() : null;
        $policy = new KnowledgeSourceAccessPolicy();
        $tenantId = $unit && (int)($unit['tenant_id'] ?? 0) > 0
            ? (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id') : 0;
        if (!$unit || !$row || !$policy->canReadUnit($unit, $actorId, [$hotelId], $tenantId)) {
            throw new RuntimeException('无权读取该知识来源');
        }
        $content = $this->decode($row['content']);
        if (($content['entry']['disposition'] ?? '') === 'reject_or_quarantine') throw new InvalidArgumentException('该来源已隔离，不能引用为带教或参考稿');
        $storedDigest = trim((string)($row['content_digest'] ?? ''));
        if ($storedDigest !== '' && !(new KnowledgeContentDigestService())->matches($storedDigest, $content)) {
            throw new RuntimeException('知识来源内容校验失败，请重新核对原始版本');
        }
        $formal = $policy->isSharedFormalUnit($unit);
        if ($unit['status'] !== 'done' || ($unit['lifecycle_status'] ?? 'active') !== 'active'
            || (int)($unit['current_chunk_id'] ?? 0) !== $chunkId
            || (!$formal && (int)($row['created_by'] ?? 0) !== (int)$unit['created_by'])
            || ($row['lifecycle_status'] ?? 'active') !== 'active'
            || ($content['lifecycle_status'] ?? 'active') !== 'active') {
            throw new InvalidArgumentException('知识来源已失效，请选择当前有效版本');
        }
        $text = (string)($content['raw_text'] ?? $content['summary'] ?? $content['text'] ?? '');
        if ($text === '') $text = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        return ['unit_id' => (int)$unit['unit_id'], 'chunk_id' => $chunkId, 'title' => $unit['name'],
            'version_no' => isset($row['version_no']) ? (int)$row['version_no'] : null,
            'digest' => (new KnowledgeContentDigestService())->digest($content),
            'source_document' => $content['source_document'] ?? ['text_sha256' => hash('sha256', $text)],
            'source_segments' => $this->segments($text), 'content' => $content,
            'policy' => 'reference_only_human_adaptation_not_verified_hotel_fact'];
    }

    /** Immutable, bounded citation material; the complete source remains in its knowledge chunk. */
    public function coachingSnapshot(int $chunkId, int $hotelId, int $actorId, ?array $selectedSegmentIds = null): array
    {
        $source = $this->source($chunkId, $hotelId, $actorId);
        $segments = $source['source_segments'];
        if ($selectedSegmentIds !== null) {
            if (!$selectedSegmentIds || count($selectedSegmentIds) > 4
                || count(array_unique($selectedSegmentIds, SORT_REGULAR)) !== count($selectedSegmentIds)) {
                throw new InvalidArgumentException('每个知识引用请选择 1–4 个不同摘录片段');
            }
            $selected = [];
            foreach ($selectedSegmentIds as $id) {
                $match = is_string($id) ? array_values(array_filter($segments, fn($segment) => $segment['id'] === $id)) : [];
                if (!$match) throw new InvalidArgumentException('知识摘录片段不存在，请重新选择当前版本');
                $selected[] = $match[0];
            }
            $segments = $selected;
        }
        $budget = 4096;
        $excerpts = [];
        $truncated = count($segments) > 4;
        foreach (array_slice($segments, 0, 4) as $segment) {
            if ($budget <= 0) { $truncated = true; break; }
            $quote = mb_strcut($segment['quote'], 0, $budget, 'UTF-8');
            $partial = strlen($quote) < strlen($segment['quote']);
            $truncated = $truncated || $partial;
            if ($quote === '') { $truncated = true; break; }
            $excerpts[] = array_merge($segment, ['quote' => $quote,
                'locator' => $segment['locator'] . ($partial ? '（节选）' : ''),
                'segment_sha256' => hash('sha256', $segment['quote']), 'excerpt_sha256' => hash('sha256', $quote),
                'excerpt_length' => mb_strlen($quote), 'excerpt_truncated' => $partial]);
            $budget -= strlen($quote);
        }
        if ($truncated) {
            foreach ($excerpts as &$excerpt) {
                if (!str_contains($excerpt['locator'], '（节选）')) $excerpt['locator'] .= '（节选）';
            }
            unset($excerpt);
        }
        $text = (string)($source['content']['raw_text'] ?? $source['content']['summary'] ?? $source['content']['text'] ?? '');
        if ($text === '') $text = json_encode($source['content'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $snapshot = ['snapshot_schema_version' => 'manager_coaching.knowledge_excerpt.v1',
            'unit_id' => $source['unit_id'], 'chunk_id' => $source['chunk_id'], 'version_no' => $source['version_no'],
            'title' => mb_strcut((string)$source['title'], 0, 1024, 'UTF-8'), 'digest' => $source['digest'],
            'source_text_sha256' => hash('sha256', $text), 'source_text_bytes' => strlen($text),
            'source_document_digest' => (new KnowledgeContentDigestService())->digest($source['source_document']),
            'source_segments' => $excerpts, 'excerpt_truncated' => $truncated,
            'excerpt_selection' => $selectedSegmentIds === null ? 'leading_non_empty_segments' : 'explicit_segment_selection',
            'excerpt_limit_bytes' => 4096, 'policy' => $source['policy']];
        if (strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 16384) {
            throw new InvalidArgumentException('知识引用摘录超过保存上限');
        }
        return $snapshot;
    }

    public function save(int $hotelId, int $actorId, array $input): array
    {
        $title = $this->text($input['title'] ?? '', '标题', 180);
        $fields = [];
        foreach (['objective' => '目标', 'steps' => '步骤', 'applicability' => '适用条件',
            'stop_conditions' => '停止条件', 'acceptance_criteria' => '验收方法'] as $key => $label) {
            $fields[$key] = $this->text($input[$key] ?? '', $label, 6000);
        }
        $citations = array_values((array)($input['citations'] ?? []));
        if (!$citations || count($citations) > 30) throw new InvalidArgumentException('请选择 1–30 条可核对的原文引用');
        $sources = [];
        $validated = [];
        foreach ($citations as $citation) {
            $id = (int)($citation['chunk_id'] ?? 0);
            $source = $sources[$id] ??= $this->source($id, $hotelId, $actorId);
            $segment = array_values(array_filter($source['source_segments'], static fn($s) => $s['id'] === ($citation['segment_id'] ?? '')))[0] ?? null;
            if (!$segment || !hash_equals($source['digest'], (string)($citation['source_digest'] ?? ''))
                || (string)($citation['quote'] ?? '') !== $segment['quote']) {
                throw new InvalidArgumentException('原文引用不匹配或来源版本已变化，请重新读取来源');
            }
            $paths = array_values(array_unique((array)($citation['field_paths'] ?? [])));
            if (!$paths || array_diff($paths, array_keys($fields))) throw new InvalidArgumentException('引用必须关联到参考稿的具体字段');
            $validated[] = ['chunk_id' => $id, 'source_digest' => $source['digest'], 'segment_id' => $segment['id'],
                'locator' => $segment['locator'], 'quote' => $segment['quote'], 'field_paths' => $paths,
                'relationship' => 'human_adaptation_reference_not_semantic_verification'];
        }
        foreach (array_keys($fields) as $key) {
            if (!array_filter($validated, static fn($c) => in_array($key, $c['field_paths'], true))) {
                throw new InvalidArgumentException('每个参考稿字段都需要原文依据：' . $key);
            }
        }
        $content = $this->referenceContent($title, $fields, $validated);
        return $this->persist($hotelId, $actorId, $content, $input);
    }

    public function referenceContent(string $title, array $fields, array $citations): array
    {
        return ['content_type' => 'reference_sop', 'title' => $title, 'summary' => $fields['objective'] ?? $title,
            'fields' => array_map(static fn($key, $value) => ['label' => ['objective' => '目标', 'steps' => '步骤', 'applicability' => '适用条件', 'stop_conditions' => '停止条件', 'acceptance_criteria' => '验收方法'][$key] ?? $key, 'content' => $value], array_keys($fields), array_values($fields)),
            'reference_fields' => $fields, 'citations' => $citations,
            'scope' => 'reference_only', 'evidence_level' => 'user_provided_unverified', 'evidence_grade' => 'C',
            'source_refs' => array_values(array_unique(array_map(static fn($c) => 'knowledge_chunks#' . $c['chunk_id'], $citations))),
            'boundaries' => ['人工整理的参考稿；引用位置已核对，适用性尚需人工验证。', '不作为当前门店事实或自动执行依据。'],
            'blocked_uses' => ['operation_task_creation', 'operation_execution', 'automatic_operation_task', 'automatic_ota_write'],
            'lifecycle_status' => 'active'];
    }

    public function persist(int $hotelId, int $actorId, array $content, array $input): array
    {
        $key = $this->text($input['idempotency_key'] ?? '', '重试标识', 100);
        if ((int)($input['unit_id'] ?? 0) > 0) {
            $content['revision_request'] = ['key_digest' => hash('sha256', $key), 'base_chunk_id' => (int)($input['expected_chunk_id'] ?? 0)];
        }
        $digestService = new KnowledgeContentDigestService();
        $digest = $digestService->digest($content);
        return Db::transaction(function () use ($hotelId, $actorId, $content, $input, $key, $digestService, $digest) {
            // Serialize new cards per authorized container; no cross-owner deduplication.
            Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            $unitId = (int)($input['unit_id'] ?? 0);
            $stableKey = 'reference:' . $hotelId . ':' . $actorId . ':' . hash('sha256', $key);
            $unit = $unitId > 0 ? Db::name('knowledge_units')->where('unit_id', $unitId)->lock(true)->find()
                : Db::name('knowledge_units')->where('stable_key', $stableKey)->lock(true)->find();
            if ($unit && ((int)$unit['hotel_id'] !== $hotelId || (int)$unit['created_by'] !== $actorId || !str_starts_with((string)$unit['stable_key'], 'reference:'))) {
                throw new RuntimeException('无权修订该参考知识');
            }
            if ($unitId > 0 && !$unit) throw new InvalidArgumentException('参考知识不存在');
            if ($unit) {
                $current = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])
                    ->where('chunk_id', $unit['current_chunk_id'])->lock(true)->find();
                if (!$current || (int)($current['created_by'] ?? 0) !== $actorId) {
                    throw new RuntimeException('参考知识当前版本范围不一致', 409);
                }
                $currentContent = $this->decode($current['content'] ?? '');
                if ($unit['status'] !== 'done' || ($unit['lifecycle_status'] ?? 'active') !== 'active'
                    || ($current['lifecycle_status'] ?? 'active') !== 'active'
                    || ($currentContent['lifecycle_status'] ?? 'active') !== 'active'
                    || !$digestService->matches((string)($current['content_digest'] ?? ''), $currentContent)) {
                    throw new RuntimeException('参考知识当前版本状态或指纹不一致', 409);
                }
                if ($unitId === 0) {
                    if (!$this->sameReferenceContent($currentContent, $content)) throw new InvalidArgumentException('重试标识已用于其他内容');
                    return $this->readback((int)$unit['unit_id'], (int)$unit['current_chunk_id'], $digestService->digest($currentContent), $hotelId, $actorId, (string)$unit['stable_key']);
                }
                if ((int)($input['expected_chunk_id'] ?? 0) !== (int)$unit['current_chunk_id']) {
                    if ($this->sameReferenceContent($currentContent, $content)) {
                        return $this->readback((int)$unit['unit_id'], (int)$unit['current_chunk_id'], $digestService->digest($currentContent), $hotelId, $actorId, (string)$unit['stable_key']);
                    }
                    throw new RuntimeException('版本冲突：参考稿已有新版本，当前草稿已保留，请读取最新版本后比较');
                }
                // History remains readable, while runtime gates exclude superseded versions.
                $currentContent['lifecycle_status'] = 'superseded';
                Db::name('knowledge_chunks')->where('chunk_id', $current['chunk_id'])->update([
                    'lifecycle_status' => 'superseded', 'content' => $this->json($currentContent),
                    'content_digest' => $digestService->digest($currentContent),
                ]);
                $unitId = (int)$unit['unit_id'];
            } else {
                $unitId = (int)Db::name('knowledge_units')->insertGetId(['name' => $content['title'], 'source' => 'manual_reference_sop',
                    'status' => 'done', 'hotel_id' => $hotelId, 'created_by' => $actorId, 'stable_key' => $stableKey,
                    'description' => mb_substr($content['summary'], 0, 1000), 'tags' => $this->json(['参考SOP', '待验证']),
                    'lifecycle_status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $chunkId = (int)Db::name('knowledge_chunks')->insertGetId(['unit_id' => $unitId, 'type' => '参考SOP',
                'content' => $this->json($content), 'content_digest' => $digest, 'lifecycle_status' => 'active',
                'created_by' => $actorId, 'created_at' => date('Y-m-d H:i:s')]);
            Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId,
                'name' => $content['title'], 'description' => mb_substr($content['summary'], 0, 1000), 'updated_at' => date('Y-m-d H:i:s')]);
            return $this->readback($unitId, $chunkId, $digest, $hotelId, $actorId, (string)($unit['stable_key'] ?? $stableKey));
        });
    }

    private function readback(int $unitId, int $chunkId, string $digest, int $hotelId, int $actorId, string $stableKey): array
    {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        $chunk = Db::name('knowledge_chunks')->where('unit_id', $unitId)->where('chunk_id', $chunkId)->find();
        $content = $this->decode($chunk['content'] ?? '');
        if (!$unit || !$chunk || (int)$unit['current_chunk_id'] !== $chunkId
            || (int)$unit['hotel_id'] !== $hotelId || (int)$unit['created_by'] !== $actorId
            || (string)$unit['stable_key'] !== $stableKey || $unit['status'] !== 'done'
            || ($unit['lifecycle_status'] ?? 'active') !== 'active'
            || (int)$chunk['created_by'] !== $actorId || ($chunk['lifecycle_status'] ?? 'active') !== 'active'
            || !hash_equals($digest, (string)($chunk['content_digest'] ?? ''))
            || !(new KnowledgeContentDigestService())->matches((string)$chunk['content_digest'], $content)) {
            throw new RuntimeException('参考知识保存回读不一致');
        }
        $chunk['content'] = $content;
        return ['unit' => $unit, 'chunk' => $chunk, 'persistence_status' => 'readback_verified', 'formal_knowledge' => false];
    }

    private function sameReferenceContent(array $stored, array $requested): bool
    {
        $labels = ['objective' => '目标', 'steps' => '步骤', 'applicability' => '适用条件',
            'stop_conditions' => '停止条件', 'acceptance_criteria' => '验收方法'];
        foreach (['stored', 'requested'] as $side) {
            $value = &${$side};
            $keys = array_keys($value['reference_fields'] ?? []);
            foreach ($value['fields'] ?? [] as $index => $field) {
                $key = $keys[$index] ?? '';
                if (isset($labels[$key]) && ($field['label'] ?? '') === $key) {
                    $value['fields'][$index]['label'] = $labels[$key];
                }
            }
            unset($value);
        }
        return (new KnowledgeContentDigestService())->digest($stored) === (new KnowledgeContentDigestService())->digest($requested);
    }

    private function text(mixed $value, string $label, int $limit): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) throw new InvalidArgumentException($label . '不能为空且不能超过 ' . $limit . ' 字');
        return trim($value);
    }
    private function decode(mixed $value): array { return is_array($value) ? $value : (json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR) ?: []); }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
