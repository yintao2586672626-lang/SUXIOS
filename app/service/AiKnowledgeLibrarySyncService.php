<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class AiKnowledgeLibrarySyncService
{
    public const SOURCE = 'ai_knowledge_library';
    public const UNIT_NAME = 'AI知识库资料｜酒店收益与OTA方法库';
    private const SEED_OWNER = 'suxios.ai_knowledge_library';

    public function __construct(
        private ?string $manifestPath = null,
        private ?string $methodPackPath = null,
        private ?string $priorityPackPath = null,
        private ?string $integratedModelPath = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->manifestPath ??= $root . '/docs/knowledge/ai-library/source-manifest.json';
        $this->methodPackPath ??= $root . '/docs/knowledge/ai-library/method-pack.json';
        $this->priorityPackPath ??= $root . '/docs/knowledge/ai-library/priority-pack.json';
        $this->integratedModelPath ??= $root . '/docs/knowledge/ai-library/integrated-model.json';
    }

    /** @return array<string, mixed> */
    public function sync(bool $persist = false): array
    {
        $manifest = $this->loadJson($this->manifestPath, 'source_manifest');
        $pack = $this->loadJson($this->methodPackPath, 'method_pack');
        $priorityPack = $this->loadJson($this->priorityPackPath, 'priority_pack');
        $integratedModel = $this->loadJson($this->integratedModelPath, 'integrated_model');
        $validation = $this->validate($manifest, $pack, $priorityPack, $integratedModel);

        $result = [
            'status' => 'validated',
            'persisted' => false,
            'source' => self::SOURCE,
            'unit_name' => self::UNIT_NAME,
            'seed_version' => $validation['seed_version'],
            'manifest_sha256' => hash_file('sha256', $this->manifestPath),
            'method_pack_sha256' => hash_file('sha256', $this->methodPackPath),
            'priority_pack_sha256' => hash_file('sha256', $this->priorityPackPath),
            'integrated_model_sha256' => hash_file('sha256', $this->integratedModelPath),
            'top_level_file_count' => $validation['top_level_file_count'],
            'method_entry_count' => $validation['method_entry_count'],
            'priority_entry_count' => $validation['priority_entry_count'],
            'integrated_model_entry_count' => $validation['integrated_model_entry_count'],
            'total_entry_count' => $validation['total_entry_count'],
            'boundary' => $validation['boundary'],
        ];
        if (!$persist) {
            $result['semantic_glossary'] = (new SemanticGlossarySyncService())->sync(false);
            return $result;
        }

        foreach (['knowledge_units', 'knowledge_chunks', 'knowledge_base'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('required_knowledge_table_missing:' . $table);
            }
        }

        $readback = Db::transaction(function () use ($manifest, $pack, $priorityPack, $integratedModel, $validation): array {
            $unitId = $this->upsertUnit($validation);
            $chunkIds = $this->upsertChunks($unitId, $manifest, $pack, $priorityPack, $integratedModel, $validation);
            $mirrorId = $this->upsertMirror($pack, $priorityPack, $integratedModel, $validation);
            if ($this->hasColumn('knowledge_units', 'current_chunk_id') && $chunkIds !== []) {
                Db::name('knowledge_units')->where('unit_id', $unitId)->update([
                    'current_chunk_id' => $chunkIds[count($chunkIds) - 1],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }

            return $this->verifyReadback($unitId, $mirrorId, $validation);
        });

        $result['status'] = ($readback['readback_verified'] ?? false) === true ? 'success' : 'partial_success';
        $result['persisted'] = true;
        $result['readback'] = $readback;
        $result['semantic_glossary'] = (new SemanticGlossarySyncService())->sync(true);
        if (($result['semantic_glossary']['status'] ?? '') !== 'success') {
            $result['status'] = 'partial_success';
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function validate(array $manifest, array $pack, array $priorityPack, array $integratedModel): array
    {
        $summary = is_array($manifest['summary'] ?? null) ? $manifest['summary'] : [];
        $records = is_array($manifest['records'] ?? null) ? array_values($manifest['records']) : [];
        $entries = is_array($pack['entries'] ?? null) ? array_values($pack['entries']) : [];
        $boundary = is_array($pack['boundary'] ?? null) ? $pack['boundary'] : [];
        $priorityBoundary = is_array($priorityPack['boundary'] ?? null) ? $priorityPack['boundary'] : [];
        $prioritySources = is_array($priorityPack['sources'] ?? null) ? array_values($priorityPack['sources']) : [];
        $priorityEntries = is_array($priorityPack['entries'] ?? null) ? array_values($priorityPack['entries']) : [];
        $integratedBoundary = is_array($integratedModel['boundary'] ?? null) ? $integratedModel['boundary'] : [];
        $integratedContract = is_array($integratedModel['source_contract'] ?? null) ? $integratedModel['source_contract'] : [];
        $seedVersion = trim((string)($pack['seed_version'] ?? ''));
        $topLevelCount = (int)($summary['top_level_file_count'] ?? 0);
        if ((int)($manifest['summary']['schema_version'] ?? 0) !== 1
            || (int)($pack['schema_version'] ?? 0) !== 1
            || $seedVersion === ''
            || $topLevelCount < 1
            || count($records) !== $topLevelCount
            || count($entries) !== (int)($pack['entry_count'] ?? -1)
            || $entries === []
        ) {
            throw new RuntimeException('ai_knowledge_pack_structure_invalid');
        }
        if ((int)($priorityPack['schema_version'] ?? 0) !== 1
            || (string)($priorityPack['seed_version'] ?? '') !== $seedVersion
            || count($prioritySources) !== (int)($priorityPack['source_count'] ?? -1)
            || count($priorityEntries) !== (int)($priorityPack['entry_count'] ?? -1)
            || $priorityEntries === []
        ) {
            throw new RuntimeException('ai_priority_pack_structure_invalid');
        }
        if ((int)($integratedModel['schema_version'] ?? 0) !== 1
            || trim((string)($integratedModel['model_key'] ?? '')) === ''
            || trim((string)($integratedModel['model_version'] ?? '')) === ''
            || trim((string)($integratedModel['title'] ?? '')) === ''
            || trim((string)($integratedModel['body_markdown'] ?? '')) === ''
            || count((array)($integratedModel['golden_cases'] ?? [])) < 3
        ) {
            throw new RuntimeException('ai_integrated_model_structure_invalid');
        }
        $manifestDigest = hash_file('sha256', $this->manifestPath);
        if (!is_string($manifestDigest)
            || !hash_equals(strtolower($manifestDigest), strtolower(trim((string)($pack['source_manifest_sha256'] ?? ''))))
        ) {
            throw new RuntimeException('ai_knowledge_manifest_digest_mismatch');
        }
        foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
            if (($boundary[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_ai_knowledge_boundary:' . $field);
            }
            if (($priorityBoundary[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_ai_priority_boundary:' . $field);
            }
            if (($integratedBoundary[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_ai_integrated_model_boundary:' . $field);
            }
        }
        $keys = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('ai_knowledge_entry_invalid');
            }
            $key = trim((string)($entry['key'] ?? ''));
            $sourceSha = strtolower(trim((string)($entry['source_sha256'] ?? '')));
            $body = trim((string)($entry['body_markdown'] ?? ''));
            if ($key === '' || isset($keys[$key]) || !preg_match('/^[a-f0-9]{64}$/', $sourceSha) || $body === '') {
                throw new RuntimeException('ai_knowledge_entry_contract_invalid');
            }
            if (($entry['decision_safe'] ?? null) !== false || ($entry['external_write_authorized'] ?? null) !== false) {
                throw new RuntimeException('unsafe_ai_knowledge_entry:' . $key);
            }
            $keys[$key] = true;
        }
        $prioritySourceHashes = [];
        foreach ($prioritySources as $source) {
            if (!is_array($source)) {
                throw new RuntimeException('ai_priority_source_invalid');
            }
            $sourceSha = strtolower(trim((string)($source['source_sha256'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $sourceSha)) {
                throw new RuntimeException('ai_priority_source_contract_invalid');
            }
            $prioritySourceHashes[$sourceSha] = true;
        }
        foreach ($priorityEntries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('ai_priority_entry_invalid');
            }
            $key = trim((string)($entry['key'] ?? ''));
            $sourceSha = strtolower(trim((string)($entry['source_sha256'] ?? '')));
            $body = trim((string)($entry['body_markdown'] ?? ''));
            if ($key === '' || isset($keys[$key]) || !isset($prioritySourceHashes[$sourceSha]) || $body === '') {
                throw new RuntimeException('ai_priority_entry_contract_invalid');
            }
            foreach ((array)($entry['source_sha256_refs'] ?? []) as $referencedSha) {
                if (!isset($prioritySourceHashes[strtolower(trim((string)$referencedSha))])) {
                    throw new RuntimeException('ai_priority_entry_source_ref_invalid:' . $key);
                }
            }
            if (($entry['decision_safe'] ?? null) !== false || ($entry['external_write_authorized'] ?? null) !== false) {
                throw new RuntimeException('unsafe_ai_priority_entry:' . $key);
            }
            $keys[$key] = true;
        }
        $integratedExpectedDigests = [
            'source_manifest_sha256' => hash_file('sha256', $this->manifestPath),
            'method_pack_sha256' => hash_file('sha256', $this->methodPackPath),
            'priority_pack_sha256' => hash_file('sha256', $this->priorityPackPath),
        ];
        foreach ($integratedExpectedDigests as $field => $digest) {
            if (!is_string($digest)
                || !hash_equals(strtolower($digest), strtolower(trim((string)($integratedContract[$field] ?? ''))))
            ) {
                throw new RuntimeException('ai_integrated_model_digest_mismatch:' . $field);
            }
        }
        if ((int)($integratedContract['method_entry_count'] ?? -1) !== count($entries)
            || (int)($integratedContract['priority_entry_count'] ?? -1) !== count($priorityEntries)
        ) {
            throw new RuntimeException('ai_integrated_model_entry_count_mismatch');
        }

        return [
            'seed_version' => $seedVersion,
            'top_level_file_count' => $topLevelCount,
            'method_entry_count' => count($entries),
            'priority_entry_count' => count($priorityEntries),
            'integrated_model_entry_count' => 1,
            'total_entry_count' => count($entries) + count($priorityEntries) + 1,
            'integrated_model_key' => (string)$integratedModel['model_key'],
            'integrated_model_title' => (string)$integratedModel['title'],
            'boundary' => $boundary,
            'reviewed_at' => '2026-08-15 00:00:00',
            'review_due_at' => '2027-02-11 00:00:00',
        ];
    }

    private function upsertUnit(array $validation): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'hotel_id' => 0,
            'stable_key' => 'global:ai_knowledge_library:hotel_revenue_ota',
            'name' => self::UNIT_NAME,
            'source' => self::SOURCE,
            'status' => 'done',
            'description' => '用户本地AI知识库资料及重点补充材料的可追溯方法与案例参考。仅用于知识中心检索和人工分析，不代表当前酒店、平台或业务日事实，不授权任何外部写入。',
            'tags' => $this->json(['AI知识库资料', '收益管理', 'OTA运营', '预订进度', '内容运营', 'reference_only', 'global_reference']),
            'created_by' => 0,
            'lifecycle_status' => 'active',
            'lifecycle_reason' => 'user_provided_library_reference_with_explicit_decision_and_write_boundaries',
            'reviewed_at' => $validation['reviewed_at'],
            'review_due_at' => $validation['review_due_at'],
            'known_knowns' => $this->json([
                '全部顶层源文件均已记录路径、SHA-256、类型和解析状态。',
                '规范方法、专题与案例Markdown已形成可检索方法片段。',
                '重复文件按原始字节哈希合并，同时保留全部来源路径。',
                '用户指定的预订进度、单店案例和小红书资料已形成独立重点条目。',
                '全量方法与重点资料已整合为证据—诊断—动作—回读—学习统一模型。',
            ]),
            'known_unknowns' => $this->json([
                '四份扫描型PDF仅完成页面视觉核读，未证明每个像素与小字均被识别。',
                '旧DOC/XLS仅完成降级文本恢复，表格和版式未被完整证明。',
                '案例、合同、订单和历史报告未被核验为任何当前酒店事实。',
            ]),
            'truth_profile_version' => $validation['seed_version'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $data = $this->filterColumns('knowledge_units', $data);
        $existing = Db::name('knowledge_units')->where('name', self::UNIT_NAME)->where('source', self::SOURCE)->find();
        if (is_array($existing)) {
            unset($data['created_at']);
            Db::name('knowledge_units')->where('unit_id', (int)$existing['unit_id'])->update($data);
            return (int)$existing['unit_id'];
        }
        return (int)Db::name('knowledge_units')->insertGetId($data);
    }

    /** @return array<int, int> */
    private function upsertChunks(
        int $unitId,
        array $manifest,
        array $pack,
        array $priorityPack,
        array $integratedModel,
        array $validation
    ): array
    {
        $summary = (array)$manifest['summary'];
        $catalog = [];
        foreach ((array)$manifest['records'] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $catalog[] = [
                'title' => (string)($record['title'] ?? ''),
                'sha256' => (string)($record['sha256'] ?? ''),
                'extension' => (string)($record['extension'] ?? ''),
                'status' => (string)($record['extraction_status'] ?? ''),
                'classification' => (string)($record['classification'] ?? ''),
                'topic' => (string)($record['topic'] ?? ''),
            ];
        }
        $payloads = [[
            'type' => 'ai_knowledge_source_catalog',
            'seed_key' => 'ai_library:source_catalog',
            'title' => 'AI知识库资料全量来源目录',
            'source_refs' => ['user-folder://AI知识库资料#manifest-sha256=' . hash_file('sha256', $this->manifestPath)],
            'body_markdown' => '全量顶层资料目录；正文与重复来源见Obsidian知识库。',
            'topic' => '资料目录',
            'classification' => 'source_catalog',
            'catalog_summary' => $summary,
            'catalog' => $catalog,
        ]];
        foreach ((array)$pack['entries'] as $entry) {
            $payloads[] = [
                'type' => 'ai_knowledge_method_reference',
                'seed_key' => (string)$entry['key'],
                'title' => (string)$entry['title'],
                'source_refs' => ['user-file://' . (string)$entry['source_filename'] . '#sha256=' . (string)$entry['source_sha256']],
                'body_markdown' => (string)$entry['body_markdown'],
                'topic' => (string)$entry['topic'],
                'classification' => (string)$entry['classification'],
            ];
        }
        foreach ((array)$priorityPack['entries'] as $entry) {
            $sourceHashes = array_values(array_unique(array_merge(
                [(string)$entry['source_sha256']],
                array_map('strval', (array)($entry['source_sha256_refs'] ?? []))
            )));
            $payloads[] = [
                'type' => 'ai_knowledge_priority_reference',
                'seed_key' => (string)$entry['key'],
                'title' => (string)$entry['title'],
                'source_refs' => array_map(
                    static fn(string $sha): string => 'user-priority-file://sha256=' . $sha,
                    $sourceHashes
                ),
                'body_markdown' => (string)$entry['body_markdown'],
                'topic' => (string)$entry['topic'],
                'classification' => (string)$entry['classification'],
                'priority' => 'user_high',
            ];
        }
        $payloads[] = [
            'type' => 'ai_knowledge_integrated_model',
            'seed_key' => (string)$integratedModel['model_key'],
            'title' => (string)$integratedModel['title'],
            'source_refs' => [
                'derived-pack://method-pack#sha256=' . hash_file('sha256', $this->methodPackPath),
                'derived-pack://priority-pack#sha256=' . hash_file('sha256', $this->priorityPackPath),
            ],
            'body_markdown' => (string)$integratedModel['body_markdown'],
            'topic' => '酒店经营统一模型',
            'classification' => 'cross_document_guarded_synthesis',
            'priority' => 'user_highest',
            'model_version' => (string)$integratedModel['model_version'],
            'integrated_model' => $integratedModel,
        ];

        $existingByKey = [];
        foreach (Db::name('knowledge_chunks')->where('unit_id', $unitId)->select()->toArray() as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            if (($content['seed_owner'] ?? '') === self::SEED_OWNER && trim((string)($content['seed_key'] ?? '')) !== '') {
                $existingByKey[(string)$content['seed_key']] = (int)$row['chunk_id'];
            }
        }

        $ids = [];
        foreach ($payloads as $payload) {
            $content = array_merge($payload, [
                'scope' => 'industry_general_and_case_reference',
                'evidence_level' => 'user_provided_curated_reference',
                'evidence_grade' => 'D',
                'content_key' => (string)$payload['seed_key'],
                'content_type' => 'reference_knowledge',
                'module_id' => 'knowledge_center',
                'platforms' => ['suxios_internal'],
                'roles' => ['owner', 'operator', 'revenue_manager', 'knowledge_reviewer'],
                'scenes' => ['knowledge_search', 'revenue_analysis_reference', 'ota_operations_reference'],
                'reviewed_at' => $validation['reviewed_at'],
                'review_due_at' => $validation['review_due_at'],
                'review_interval_days' => 180,
                'freshness_policy' => 'review_due_reference_only',
                'decision_policy' => 'reference_only_human_review_required',
                'allowed_uses' => ['knowledge_search', 'manual_analysis_reference', 'sop_draft_reference'],
                'blocked_uses' => ['current_hotel_fact', 'automatic_pricing', 'automatic_inventory_change', 'automatic_ota_write', 'automatic_pms_write', 'automatic_content_publish', 'automatic_wecom_send'],
                'seed_owner' => self::SEED_OWNER,
                'seed_version' => $validation['seed_version'],
                'lifecycle_status' => 'active',
                'contains_current_hotel_fact' => false,
                'decision_safe' => false,
                'task_draft_safe' => false,
                'external_write_authorized' => false,
            ]);
            $row = [
                'unit_id' => $unitId,
                'type' => (string)$payload['type'],
                'content' => $this->json($content),
                'created_by' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $seedKey = (string)$payload['seed_key'];
            if (isset($existingByKey[$seedKey])) {
                $chunkId = $existingByKey[$seedKey];
                unset($row['created_at']);
                Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($row);
            } else {
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($row);
            }
            $ids[] = $chunkId;
        }
        return $ids;
    }

    private function upsertMirror(array $pack, array $priorityPack, array $integratedModel, array $validation): int
    {
        $methodTitles = array_values(array_map(
            static fn(array $entry): string => trim((string)($entry['title'] ?? '')),
            (array)$pack['entries']
        ));
        $priorityTitles = array_values(array_map(
            static fn(array $entry): string => trim((string)($entry['title'] ?? '')),
            (array)$priorityPack['entries']
        ));
        $integratedTitle = trim((string)($integratedModel['title'] ?? ''));
        $titles = array_merge([$integratedTitle], $priorityTitles, $methodTitles);
        $content = implode("\n", [
            '# ' . self::UNIT_NAME,
            '',
            '本知识来自用户本地AI知识库资料，已完成全量来源登记、重复合并和方法条目提取。',
            '仅作知识检索和人工分析参考，不代表当前酒店、平台或业务日事实，不授权OTA、PMS或企微写入。',
            '',
            '## 深度整合模型',
            '',
            '- ' . $integratedTitle,
            '',
            '## 用户重点资料',
            '',
            ...array_map(static fn(string $title): string => '- ' . $title, $priorityTitles),
            '',
            '## 全量方法目录',
            '',
            ...array_map(static fn(string $title): string => '- ' . $title, $methodTitles),
            '',
            '## 使用前置',
            '',
            '进入经营判断前必须补齐系统酒店、平台/数据源、业务日期、新鲜采集或已验证导入、保存回读和人工复核。',
        ]);
        $data = $this->filterColumns('knowledge_base', [
            'tenant_id' => 0,
            'hotel_id' => 0,
            'category_id' => 7,
            'title' => self::UNIT_NAME,
            'content' => $content,
            'keywords' => mb_substr(implode(',', array_merge(['AI知识库资料', '收益管理', 'OTA运营', '预订进度', '小红书', '内容运营'], $titles)), 0, 255),
            'tags' => $this->json(['AI知识库资料', '收益管理', 'OTA运营', '预订进度', '小红书', 'reference_only']),
            'sort_order' => 0,
            'is_enabled' => 1,
            'view_count' => 0,
            'like_count' => 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $existing = Db::name('knowledge_base')->where('hotel_id', 0)->where('title', self::UNIT_NAME)->find();
        if (is_array($existing)) {
            unset($data['create_time']);
            Db::name('knowledge_base')->where('id', (int)$existing['id'])->update($data);
            return (int)$existing['id'];
        }
        return (int)Db::name('knowledge_base')->insertGetId($data);
    }

    /** @return array<string, mixed> */
    private function verifyReadback(int $unitId, int $mirrorId, array $validation): array
    {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unitId)->select()->toArray();
        $activeSeedKeys = [];
        $unsafeCount = 0;
        $integratedModelFound = false;
        $integratedGoldenCaseCount = 0;
        foreach ($chunks as $chunk) {
            $content = $this->decodeJson($chunk['content'] ?? null);
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER
                || ($content['seed_version'] ?? '') !== $validation['seed_version']
                || ($content['lifecycle_status'] ?? '') !== 'active'
            ) {
                continue;
            }
            $activeSeedKeys[(string)($content['seed_key'] ?? '')] = true;
            if (($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
            ) {
                $unsafeCount++;
            }
            if (($content['seed_key'] ?? '') === $validation['integrated_model_key']
                && ($content['type'] ?? '') === 'ai_knowledge_integrated_model'
            ) {
                $integrated = is_array($content['integrated_model'] ?? null) ? $content['integrated_model'] : [];
                $integratedGoldenCaseCount = count((array)($integrated['golden_cases'] ?? []));
                $integratedModelFound = (string)($content['title'] ?? '') === $validation['integrated_model_title']
                    && $integratedGoldenCaseCount >= 3
                    && (($integrated['boundary']['decision_safe'] ?? null) === false)
                    && (($integrated['boundary']['external_write_authorized'] ?? null) === false);
            }
        }
        $mirror = Db::name('knowledge_base')->where('id', $mirrorId)->where('is_enabled', 1)->find();
        $expectedChunkCount = 1 + (int)$validation['total_entry_count'];
        $verified = is_array($unit)
            && (string)($unit['status'] ?? '') === 'done'
            && count($activeSeedKeys) === $expectedChunkCount
            && $unsafeCount === 0
            && $integratedModelFound
            && is_array($mirror);
        return [
            'readback_verified' => $verified,
            'unit_id' => $unitId,
            'mirror_id' => $mirrorId,
            'expected_active_chunk_count' => $expectedChunkCount,
            'readback_active_chunk_count' => count($activeSeedKeys),
            'unsafe_chunk_count' => $unsafeCount,
            'integrated_model_found' => $integratedModelFound,
            'integrated_golden_case_count' => $integratedGoldenCaseCount,
        ];
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path, string $label): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException($label . '_missing');
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException($label . '_invalid_json');
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
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

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return false;
        }
        try {
            // ThinkPHP's raw-query positional binding is not accepted by this
            // MySQL driver for SHOW TABLES. The strict identifier allow-list
            // above keeps the literal query non-user-controlled.
            return Db::query("SHOW TABLES LIKE '" . $table . "'") !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($table), true);
    }

    /** @return array<int, string> */
    private function tableColumns(string $table): array
    {
        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
            $field = trim((string)($row['Field'] ?? $row['field'] ?? ''));
            if ($field !== '') {
                $columns[] = $field;
            }
        }
        return $columns;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function filterColumns(string $table, array $data): array
    {
        $allowed = array_flip($this->tableColumns($table));
        return array_intersect_key($data, $allowed);
    }
}
