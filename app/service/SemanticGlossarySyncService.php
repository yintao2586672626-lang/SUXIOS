<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Traceable, version-preserving sync into the existing Knowledge Center path.
 */
final class SemanticGlossarySyncService
{
    public const SOURCE = 'semantic_glossary';
    public const UNIT_NAME = '宿析OS统一语义词库';
    public const STABLE_KEY = 'global:semantic_glossary:unified';
    private const SEED_OWNER = 'suxios.semantic_glossary';
    private const CHUNK_TYPE = 'semantic_glossary_reference';
    private const BATCH_SIZE = 25;

    public function __construct(
        private ?string $packPath = null,
        private ?string $manifestPath = null,
        private ?string $exportPath = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->packPath ??= $root . '/docs/knowledge/semantic-glossary/semantic-glossary-pack.json';
        $this->manifestPath ??= $root . '/docs/knowledge/semantic-glossary/source-manifest.json';
        $this->exportPath ??= $root . '/docs/knowledge/semantic-glossary/exports/Typeless_语音简洁词库_2026-08-25.csv';
    }

    /** @return array<string,mixed> */
    public function sync(bool $persist = false): array
    {
        $pack = $this->loadJson((string)$this->packPath, 'semantic_glossary_pack');
        $manifest = $this->loadJson((string)$this->manifestPath, 'semantic_glossary_manifest');
        $validation = $this->validate($pack, $manifest);
        $result = [
            'status' => 'validated',
            'persisted' => false,
            'source' => self::SOURCE,
            'unit_name' => self::UNIT_NAME,
            'glossary_version' => $validation['glossary_version'],
            'revision_no' => $validation['revision_no'],
            'source_term_count' => $validation['source_term_count'],
            'recognition_term_count' => $validation['recognition_term_count'],
            'concept_count' => $validation['concept_count'],
            'export_term_count' => $validation['export_term_count'],
            'batch_count' => $validation['batch_count'],
            'source_sha256' => $validation['source_sha256'],
            'pack_sha256' => $validation['pack_sha256'],
            'export_sha256' => $validation['export_sha256'],
            'category_counts' => $validation['category_counts'],
            'exact_duplicate_count' => $validation['exact_duplicate_count'],
            'normalization_collision_count' => $validation['normalization_collision_count'],
            'ambiguous_alias_count' => $validation['ambiguous_alias_count'],
            'failed_entry_count' => $validation['failed_entry_count'],
            'change_summary' => $validation['change_summary'],
            'boundary' => $validation['boundary'],
        ];
        if (!$persist) {
            return $result;
        }

        $this->assertSchema();
        $readback = Db::transaction(function () use ($pack, $manifest, $validation): array {
            $unitId = $this->upsertUnit($validation);
            $chunks = $this->upsertChunks($unitId, $pack, $manifest, $validation);
            $currentChunkId = (int)($chunks['ids'][$validation['manifest_seed_key']] ?? 0);
            if ($currentChunkId <= 0) {
                throw new RuntimeException('semantic_glossary_current_chunk_missing');
            }
            Db::name('knowledge_units')->where('unit_id', $unitId)->update($this->filterColumns('knowledge_units', [
                'current_chunk_id' => $currentChunkId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            $mirrorId = $this->upsertMirror($pack, $validation);
            $verified = $this->verifyReadback($unitId, $currentChunkId, $mirrorId, $pack, $manifest, $validation);
            if (($verified['readback_verified'] ?? false) !== true) {
                throw new RuntimeException('semantic_glossary_readback_mismatch');
            }
            $verified['operation'] = $chunks['operation'];
            $verified['inserted_chunk_count'] = $chunks['inserted'];
            $verified['reused_chunk_count'] = $chunks['reused'];
            $verified['updated_chunk_count'] = $chunks['updated'];
            $verified['superseded_chunk_count'] = $chunks['superseded'];
            return $verified;
        });

        $result['status'] = 'success';
        $result['persisted'] = true;
        $result['readback'] = $readback;
        return $result;
    }

    /** @return array<string,mixed> */
    private function validate(array $pack, array $manifest): array
    {
        if ((int)($pack['schema_version'] ?? 0) !== 1
            || (string)($pack['pack_key'] ?? '') !== 'suxios.semantic_glossary.v1'
            || trim((string)($pack['glossary_version'] ?? '')) === ''
            || !is_array($pack['concepts'] ?? null)
            || !is_array($pack['summary'] ?? null)
            || !is_array($pack['boundary'] ?? null)
        ) {
            throw new RuntimeException('semantic_glossary_pack_structure_invalid');
        }
        $packBytes = file_get_contents((string)$this->packPath);
        $exportBytes = file_get_contents((string)$this->exportPath);
        if (!is_string($packBytes) || !is_string($exportBytes)) {
            throw new RuntimeException('semantic_glossary_artifact_unreadable');
        }
        $packSha = hash('sha256', $packBytes);
        $exportSha = hash('sha256', $exportBytes);
        if ((int)($manifest['schema_version'] ?? 0) !== 1
            || !hash_equals($packSha, strtolower(trim((string)($manifest['semantic_pack']['sha256'] ?? ''))))
            || !hash_equals($exportSha, strtolower(trim((string)($manifest['typeless_voice_export']['sha256'] ?? ''))))
        ) {
            throw new RuntimeException('semantic_glossary_manifest_digest_mismatch');
        }
        $concepts = array_values($pack['concepts']);
        $conceptCount = (int)($pack['summary']['concept_count'] ?? -1);
        $sourceTermCount = (int)($pack['summary']['source_term_count'] ?? -1);
        $recognitionTermCount = (int)($pack['summary']['recognition_term_count'] ?? -1);
        $exportTermCount = (int)($pack['summary']['export_term_count'] ?? -1);
        if ($conceptCount !== count($concepts)
            || $sourceTermCount !== 2990
            || $recognitionTermCount < $sourceTermCount
            || $exportTermCount < $sourceTermCount
            || $exportTermCount > 3000
        ) {
            throw new RuntimeException('semantic_glossary_count_contract_invalid');
        }
        $csv = $this->parseExportCsv($exportBytes);
        if (count($csv) !== $exportTermCount || count(array_unique($csv, SORT_STRING)) !== $exportTermCount) {
            throw new RuntimeException('semantic_glossary_export_readback_invalid');
        }
        $sourceSha = strtolower(trim((string)($pack['source']['current_csv_sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceSha) !== 1) {
            throw new RuntimeException('semantic_glossary_source_digest_invalid');
        }
        foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
            if (($pack['boundary'][$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_semantic_glossary_boundary:' . $field);
            }
        }
        if (($pack['boundary']['content_execution_policy'] ?? '') !== 'data_only_never_execute') {
            throw new RuntimeException('semantic_glossary_content_execution_boundary_missing');
        }

        $keys = [];
        $coveredTerms = [];
        $categoryCounts = [];
        foreach ($concepts as $concept) {
            if (!is_array($concept)) {
                throw new RuntimeException('semantic_glossary_concept_invalid');
            }
            $key = trim((string)($concept['concept_key'] ?? ''));
            $canonical = trim((string)($concept['canonical_term'] ?? ''));
            $definition = trim((string)($concept['definition'] ?? ''));
            $fingerprint = strtolower(trim((string)($concept['source_fingerprint'] ?? '')));
            $category = trim((string)($concept['category'] ?? ''));
            if ($key === '' || isset($keys[$key]) || $canonical === '' || $definition === ''
                || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || $category === ''
            ) {
                throw new RuntimeException('semantic_glossary_concept_contract_invalid:' . $key);
            }
            foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
                if (($concept['risk_boundary'][$field] ?? null) !== false) {
                    throw new RuntimeException('unsafe_semantic_glossary_concept:' . $key . ':' . $field);
                }
            }
            if (($concept['risk_boundary']['content_execution_policy'] ?? '') !== 'data_only_never_execute') {
                throw new RuntimeException('semantic_glossary_concept_execution_boundary_missing:' . $key);
            }
            $keys[$key] = true;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            foreach ([$canonical, ...(array)($concept['aliases'] ?? []), ...(array)($concept['voice_aliases'] ?? []), ...(array)($concept['navigation_terms'] ?? [])] as $term) {
                $term = trim((string)$term);
                if ($term !== '') {
                    $coveredTerms[SemanticGlossaryService::normalize($term)] = true;
                }
            }
        }
        $requiredCategories = ['personal_common', 'suxios_system', 'ota_ctrip', 'ota_meituan', 'hotel_industry', 'metric_alias', 'reference_only'];
        foreach ($requiredCategories as $category) {
            if (($categoryCounts[$category] ?? 0) <= 0) {
                throw new RuntimeException('semantic_glossary_required_category_empty:' . $category);
            }
        }
        if (count($coveredTerms) !== $recognitionTermCount) {
            throw new RuntimeException('semantic_glossary_recognition_count_mismatch');
        }
        $this->verifyLocalSourceFingerprints($manifest);

        $revisionNo = max(1, (int)($pack['revision_no'] ?? 1));
        $batchCount = (int)ceil($conceptCount / self::BATCH_SIZE);
        $version = (string)$pack['glossary_version'];
        return [
            'glossary_version' => $version,
            'revision_no' => $revisionNo,
            'source_term_count' => $sourceTermCount,
            'recognition_term_count' => $recognitionTermCount,
            'concept_count' => $conceptCount,
            'export_term_count' => $exportTermCount,
            'batch_count' => $batchCount,
            'source_sha256' => $sourceSha,
            'pack_sha256' => $packSha,
            'export_sha256' => $exportSha,
            'category_counts' => (array)$pack['summary']['category_counts'],
            'exact_duplicate_count' => (int)($pack['summary']['exact_duplicate_count'] ?? -1),
            'normalization_collision_count' => (int)($pack['summary']['normalization_collision_count'] ?? -1),
            'ambiguous_alias_count' => (int)($pack['summary']['ambiguous_alias_count'] ?? -1),
            'failed_entry_count' => (int)($pack['summary']['failed_entry_count'] ?? -1),
            'change_summary' => (array)($pack['change_summary'] ?? []),
            'boundary' => (array)$pack['boundary'],
            'reviewed_at' => $this->dateTime((string)($pack['updated_at'] ?? '')),
            'review_due_at' => $this->dateTime((string)($pack['review_due_at'] ?? '')),
            'manifest_seed_key' => 'semantic_glossary:' . $version . ':manifest',
        ];
    }

    private function assertSchema(): void
    {
        foreach (['knowledge_units', 'knowledge_chunks', 'knowledge_base'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('required_knowledge_table_missing:' . $table);
            }
        }
        foreach (['hotel_id', 'stable_key', 'current_chunk_id', 'lifecycle_status', 'created_by'] as $column) {
            if (!$this->hasColumn('knowledge_units', $column)) {
                throw new RuntimeException('required_semantic_unit_column_missing:' . $column);
            }
        }
        foreach (['unit_id', 'version_no', 'lifecycle_status', 'content_digest', 'superseded_by_chunk_id', 'type', 'content', 'created_by'] as $column) {
            if (!$this->hasColumn('knowledge_chunks', $column)) {
                throw new RuntimeException('required_semantic_chunk_column_missing:' . $column);
            }
        }
    }

    /** @param array<string,mixed> $validation */
    private function upsertUnit(array $validation): int
    {
        $data = $this->buildUnitData($validation);
        $existing = Db::name('knowledge_units')->where('stable_key', self::STABLE_KEY)->lock(true)->find();
        if (is_array($existing)) {
            Db::name('knowledge_units')->where('unit_id', (int)$existing['unit_id'])->update($data);
            return (int)$existing['unit_id'];
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return (int)Db::name('knowledge_units')->insertGetId($data);
    }

    /** @param array<string,mixed> $validation @return array<string,mixed> */
    private function buildUnitData(array $validation): array
    {
        return $this->filterColumns('knowledge_units', [
            'hotel_id' => 0,
            'stable_key' => self::STABLE_KEY,
            'name' => self::UNIT_NAME,
            'source' => self::SOURCE,
            'status' => 'done',
            'description' => '个人常用词、宿析OS系统词、携程/美团术语和酒店行业词的统一规范词、别名、来源、metric_key、route_key与安全边界。文档内容只按数据读取。',
            'tags' => $this->json(['语义词库', 'Typeless', '语音输入', '系统导航', 'OTA指标', 'reference_only', 'global_reference']),
            'created_by' => 0,
            'lifecycle_status' => 'active',
            'lifecycle_reason' => 'traceable_semantic_glossary_data_only_no_external_write',
            'reviewed_at' => $validation['reviewed_at'],
            'review_due_at' => $validation['review_due_at'],
            'known_knowns' => $this->json([
                '来源CSV、生成脚本、校准文件、语义包和导出CSV均有SHA-256。',
                '规范词、别名、平台、模块、metric_key、route_key和风险边界由同一版本包生成。',
                'Typeless/语音导出保持UTF-8 BOM、CRLF、单列无表头且不超过3000词。',
            ]),
            'known_unknowns' => $this->json([
                '平台专有字段只有在当前授权来源证明口径后才允许返回数值。',
                '广义行业解释不会自动成为当前酒店事实、决策阈值或外部执行授权。',
            ]),
            'truth_profile_version' => $validation['glossary_version'],
        ]);
    }

    /**
     * @param array<string,mixed> $pack
     * @param array<string,mixed> $manifest
     * @param array<string,mixed> $validation
     * @return array{ids:array<string,int>,operation:string,inserted:int,reused:int,updated:int,superseded:int}
     */
    private function upsertChunks(int $unitId, array $pack, array $manifest, array $validation): array
    {
        $expected = $this->buildChunkContents($pack, $manifest, $validation);
        $existing = [];
        $inserted = 0;
        $reused = 0;
        $updated = 0;
        $superseded = 0;
        foreach (Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray() as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                if ((string)($row['type'] ?? '') === self::CHUNK_TYPE) {
                    throw new RuntimeException('semantic_glossary_chunk_identity_invalid');
                }
                continue;
            }
            $key = trim((string)($content['seed_key'] ?? ''));
            $lifecycle = trim((string)($content['lifecycle_status'] ?? ''));
            if ($lifecycle !== 'active') {
                if (!$this->inactiveRowMatches($row, $content, $unitId)) {
                    throw new RuntimeException('semantic_glossary_inactive_chunk_invalid');
                }
                continue;
            }
            if ($key === '' || isset($existing[$key])) {
                throw new RuntimeException('semantic_glossary_duplicate_or_missing_seed_key');
            }
            if (!isset($expected[$key])) {
                $content['lifecycle_status'] = 'superseded';
                $content['superseded_by_seed_version'] = $validation['glossary_version'];
                $rowData = $this->filterColumns('knowledge_chunks', [
                    'version_no' => max(1, (int)($row['version_no'] ?? 1)),
                    'lifecycle_status' => 'superseded',
                    'content_digest' => $this->canonicalHash($content),
                    'retired_at' => date('Y-m-d H:i:s'),
                    'content' => $this->json($content),
                ]);
                Db::name('knowledge_chunks')->where('chunk_id', (int)$row['chunk_id'])->update($rowData);
                $superseded++;
                continue;
            }
            $existing[$key] = $row;
        }

        $ids = [];
        foreach ($expected as $key => $content) {
            $row = $this->buildChunkRow($unitId, $content, $validation);
            if (isset($existing[$key])) {
                $current = $existing[$key];
                $expectedDigest = (string)$row['content_digest'];
                $currentDigest = strtolower(trim((string)($current['content_digest'] ?? '')));
                if ($currentDigest !== '' && hash_equals($expectedDigest, $currentDigest)
                    && (string)($current['lifecycle_status'] ?? '') === 'active'
                ) {
                    $chunkId = (int)$current['chunk_id'];
                    $reused++;
                } else {
                    $chunkId = (int)$current['chunk_id'];
                    unset($row['created_at']);
                    Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($row);
                    $updated++;
                }
            } else {
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($row);
                $inserted++;
            }
            $ids[$key] = $chunkId;
        }
        return [
            'ids' => $ids,
            'operation' => $inserted === 0 && $updated === 0 && $superseded === 0 ? 'unchanged' : 'synchronized',
            'inserted' => $inserted,
            'reused' => $reused,
            'updated' => $updated,
            'superseded' => $superseded,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function buildChunkContents(array $pack, array $manifest, array $validation): array
    {
        $sourceRefs = [
            'user-file://' . (string)$pack['source']['current_csv_file'] . '#sha256=' . $validation['source_sha256'],
            'derived-pack://semantic-glossary-pack#sha256=' . $validation['pack_sha256'],
            'derived-export://typeless-voice-csv#sha256=' . $validation['export_sha256'],
        ];
        $base = [
            'schema_version' => 1,
            'module_name' => '宿析OS统一语义词库',
            'scope' => 'global_semantic_reference_and_navigation',
            'evidence_level' => 'user_provided_and_project_curated_semantic_mapping',
            'evidence_grade' => 'C',
            'content_type' => 'reference_knowledge',
            'source_refs' => $sourceRefs,
            'reviewed_at' => $validation['reviewed_at'],
            'review_due_at' => $validation['review_due_at'],
            'review_interval_days' => 180,
            'freshness_policy' => 'source_fingerprint_and_version_change_requires_resync',
            'decision_policy' => 'reference_only_same_scope_fact_required_for_values',
            'allowed_uses' => ['knowledge_search', 'input_recognition', 'system_navigation', 'metric_alias_resolution'],
            'blocked_uses' => (array)$validation['boundary']['blocked_uses'],
            'seed_owner' => self::SEED_OWNER,
            'seed_version' => $validation['glossary_version'],
            'lifecycle_status' => 'active',
            'contains_current_hotel_fact' => false,
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
            'content_execution_policy' => 'data_only_never_execute',
        ];
        $contents = [];
        $manifestKey = $validation['manifest_seed_key'];
        $contents[$manifestKey] = array_merge($base, [
            'seed_key' => $manifestKey,
            'content_key' => $manifestKey,
            'type' => 'semantic_glossary_manifest',
            'title' => self::UNIT_NAME . '｜版本与维护说明',
            'search_text' => '语义词库 Typeless 语音输入 规范词 别名 metric_key route_key 来源指纹 维护说明 Obsidian',
            'glossary_version' => $validation['glossary_version'],
            'revision_no' => $validation['revision_no'],
            'pack_sha256' => $validation['pack_sha256'],
            'source_sha256' => $validation['source_sha256'],
            'export_sha256' => $validation['export_sha256'],
            'summary' => [
                'source_term_count' => $validation['source_term_count'],
                'recognition_term_count' => $validation['recognition_term_count'],
                'concept_count' => $validation['concept_count'],
                'export_term_count' => $validation['export_term_count'],
                'batch_count' => $validation['batch_count'],
                'category_counts' => $validation['category_counts'],
                'exact_duplicate_count' => $validation['exact_duplicate_count'],
                'normalization_collision_count' => $validation['normalization_collision_count'],
                'ambiguous_alias_count' => $validation['ambiguous_alias_count'],
                'failed_entry_count' => $validation['failed_entry_count'],
            ],
            'normalization_rules' => (array)$pack['normalization_rules'],
            'change_summary' => $validation['change_summary'],
            'source_manifest' => [
                'source_count' => (int)($manifest['source_count'] ?? 0),
                'manifest_sha256' => hash_file('sha256', (string)$this->manifestPath),
            ],
        ]);

        $batches = array_chunk(array_values($pack['concepts']), self::BATCH_SIZE);
        foreach ($batches as $index => $concepts) {
            $number = $index + 1;
            $key = sprintf('semantic_glossary:%s:batch:%04d', $validation['glossary_version'], $number);
            $terms = [];
            foreach ($concepts as $concept) {
                $terms[] = (string)$concept['canonical_term'];
                $terms = array_merge($terms, array_map('strval', (array)($concept['aliases'] ?? [])));
                $terms = array_merge($terms, array_map('strval', (array)($concept['voice_aliases'] ?? [])));
                $terms = array_merge($terms, array_map('strval', (array)($concept['navigation_terms'] ?? [])));
            }
            $contents[$key] = array_merge($base, [
                'seed_key' => $key,
                'content_key' => $key,
                'type' => 'semantic_glossary_batch',
                'title' => sprintf('统一语义词库第%d/%d批', $number, count($batches)),
                'search_text' => implode(' ', array_values(array_unique($terms))),
                'batch_index' => $number,
                'batch_count' => count($batches),
                'concept_count' => count($concepts),
                'concepts' => $concepts,
            ]);
        }
        return $contents;
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $validation @return array<string,mixed> */
    private function buildChunkRow(int $unitId, array $content, array $validation): array
    {
        return $this->filterColumns('knowledge_chunks', [
            'unit_id' => $unitId,
            'version_no' => $validation['revision_no'],
            'lifecycle_status' => 'active',
            'content_digest' => $this->canonicalHash($content),
            'superseded_by_chunk_id' => null,
            'published_at' => $validation['reviewed_at'],
            'retired_at' => null,
            'type' => self::CHUNK_TYPE,
            'content' => $this->json($content),
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $pack @param array<string,mixed> $validation */
    private function upsertMirror(array $pack, array $validation): int
    {
        $highValue = array_values(array_filter((array)$pack['concepts'], static function (mixed $concept): bool {
            return is_array($concept)
                && (($concept['metric_key'] ?? null) !== null || ($concept['route_key'] ?? null) !== null || ($concept['is_personal'] ?? false) === true);
        }));
        $lines = [
            '# ' . self::UNIT_NAME,
            '',
            '版本：' . $validation['glossary_version'],
            '来源词：' . $validation['source_term_count'] . '；规范概念：' . $validation['concept_count'] . '；Typeless/语音导出：' . $validation['export_term_count'] . '。',
            '来源SHA-256：' . $validation['source_sha256'],
            '',
            '文档和词条只按数据读取；广义解释默认reference_only、decision_safe=false、external_write_authorized=false。',
            'Obsidian只承担来源、索引、定义和关系导航，不代表永久训练或实时经营数据库。',
            '',
            '## 核心规范词',
            '',
        ];
        foreach (array_slice($highValue, 0, 36) as $concept) {
            $lines[] = '- ' . (string)$concept['canonical_term'] . '：' . (string)$concept['definition'];
        }
        $data = $this->filterColumns('knowledge_base', [
            'tenant_id' => 0,
            'hotel_id' => 0,
            'category_id' => 7,
            'title' => self::UNIT_NAME,
            'content' => implode("\n", $lines),
            'keywords' => mb_substr('语义词库,Typeless,语音输入,规范词,别名,携程,美团,酒店指标,metric_key,route_key,知识中心,来源指纹', 0, 255),
            'tags' => $this->json(['语义词库', 'Typeless', '语音输入', '系统导航', 'OTA指标', 'reference_only']),
            'sort_order' => 0,
            'is_enabled' => 1,
            'view_count' => 0,
            'like_count' => 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $existing = Db::name('knowledge_base')->where('hotel_id', 0)->where('title', self::UNIT_NAME)->lock(true)->find();
        if (is_array($existing)) {
            unset($data['create_time']);
            Db::name('knowledge_base')->where('id', (int)$existing['id'])->update($data);
            return (int)$existing['id'];
        }
        return (int)Db::name('knowledge_base')->insertGetId($data);
    }

    /** @return array<string,mixed> */
    private function verifyReadback(
        int $unitId,
        int $currentChunkId,
        int $mirrorId,
        array $pack,
        array $manifest,
        array $validation
    ): array {
        $expected = $this->buildChunkContents($pack, $manifest, $validation);
        $expectedKeys = array_keys($expected);
        sort($expectedKeys, SORT_STRING);
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->lock(true)->find();
        $mirror = Db::name('knowledge_base')->where('id', $mirrorId)->where('is_enabled', 1)->lock(true)->find();
        $activeKeys = [];
        $chunkIds = [];
        $mismatchCount = 0;
        $unsafeCount = 0;
        $inactiveMismatchCount = 0;
        $readbackConceptCount = 0;
        $headerMatched = false;
        foreach (Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray() as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                if ((string)($row['type'] ?? '') === self::CHUNK_TYPE) {
                    $mismatchCount++;
                }
                continue;
            }
            if (($content['lifecycle_status'] ?? '') !== 'active') {
                if (!$this->inactiveRowMatches($row, $content, $unitId)) {
                    $inactiveMismatchCount++;
                }
                continue;
            }
            $key = trim((string)($content['seed_key'] ?? ''));
            $contentMatch = isset($expected[$key])
                && hash_equals($this->canonicalHash($expected[$key]), $this->canonicalHash($content));
            $rowMatch = $contentMatch
                && (int)($row['unit_id'] ?? 0) === $unitId
                && (int)($row['version_no'] ?? 0) === $validation['revision_no']
                && (int)($row['created_by'] ?? -1) === 0
                && (string)($row['type'] ?? '') === self::CHUNK_TYPE
                && (string)($row['lifecycle_status'] ?? '') === 'active'
                && hash_equals($this->canonicalHash($content), strtolower(trim((string)($row['content_digest'] ?? ''))));
            if ($key === '' || isset($activeKeys[$key]) || !$rowMatch) {
                $mismatchCount++;
            } else {
                $activeKeys[$key] = true;
                $chunkIds[$key] = (int)$row['chunk_id'];
            }
            if (($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
            ) {
                $unsafeCount++;
            }
            if (($content['type'] ?? '') === 'semantic_glossary_batch') {
                $readbackConceptCount += (int)($content['concept_count'] ?? 0);
            }
            if ($key === $validation['manifest_seed_key']) {
                $headerMatched = ($content['pack_sha256'] ?? '') === $validation['pack_sha256']
                    && ($content['source_sha256'] ?? '') === $validation['source_sha256']
                    && (int)($content['summary']['source_term_count'] ?? 0) === $validation['source_term_count'];
            }
        }
        $actualKeys = array_keys($activeKeys);
        sort($actualKeys, SORT_STRING);
        $expectedUnit = $this->buildUnitData($validation);
        $unitMatch = is_array($unit)
            && (int)($unit['hotel_id'] ?? -1) === 0
            && (int)($unit['created_by'] ?? -1) === 0
            && (string)($unit['stable_key'] ?? '') === self::STABLE_KEY
            && (int)($unit['current_chunk_id'] ?? 0) === $currentChunkId
            && (string)($unit['source'] ?? '') === self::SOURCE
            && (string)($unit['status'] ?? '') === 'done'
            && (string)($unit['lifecycle_status'] ?? '') === 'active'
            && (string)($unit['truth_profile_version'] ?? '') === $validation['glossary_version'];
        $mirrorMatch = is_array($mirror)
            && (string)($mirror['title'] ?? '') === self::UNIT_NAME
            && mb_strlen((string)($mirror['keywords'] ?? '')) <= 255
            && str_contains((string)($mirror['content'] ?? ''), $validation['source_sha256'])
            && str_contains((string)($mirror['content'] ?? ''), $validation['glossary_version']);
        $verified = $unitMatch
            && $mirrorMatch
            && $expectedKeys === $actualKeys
            && count($actualKeys) === 1 + $validation['batch_count']
            && $readbackConceptCount === $validation['concept_count']
            && $headerMatched
            && $mismatchCount === 0
            && $unsafeCount === 0
            && $inactiveMismatchCount === 0;
        return [
            'readback_verified' => $verified,
            'unit_id' => $unitId,
            'current_chunk_id' => $currentChunkId,
            'mirror_id' => $mirrorId,
            'expected_active_chunk_count' => 1 + $validation['batch_count'],
            'readback_active_chunk_count' => count($actualKeys),
            'expected_concept_count' => $validation['concept_count'],
            'readback_concept_count' => $readbackConceptCount,
            'expected_seed_keys' => $expectedKeys,
            'readback_seed_keys' => $actualKeys,
            'chunk_ids' => $chunkIds,
            'unit_match' => $unitMatch,
            'mirror_match' => $mirrorMatch,
            'header_match' => $headerMatched,
            'mismatch_count' => $mismatchCount,
            'unsafe_chunk_count' => $unsafeCount,
            'inactive_row_mismatch_count' => $inactiveMismatchCount,
            'source_sha256' => $validation['source_sha256'],
            'pack_sha256' => $validation['pack_sha256'],
            'export_sha256' => $validation['export_sha256'],
            'category_counts' => $validation['category_counts'],
            'exact_duplicate_count' => $validation['exact_duplicate_count'],
            'failed_entry_count' => $validation['failed_entry_count'],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $content */
    private function inactiveRowMatches(array $row, array $content, int $unitId): bool
    {
        return in_array((string)($content['lifecycle_status'] ?? ''), ['superseded', 'retired', 'stale'], true)
            && (int)($row['unit_id'] ?? 0) === $unitId
            && (int)($row['version_no'] ?? 0) > 0
            && (int)($row['created_by'] ?? -1) === 0
            && (string)($row['type'] ?? '') === self::CHUNK_TYPE
            && (string)($row['lifecycle_status'] ?? '') !== 'active'
            && hash_equals($this->canonicalHash($content), strtolower(trim((string)($row['content_digest'] ?? ''))));
    }

    /** @param array<string,mixed> $manifest */
    private function verifyLocalSourceFingerprints(array $manifest): void
    {
        foreach ((array)($manifest['sources'] ?? []) as $source) {
            if (!is_array($source) || ($source['path'] ?? null) === null || ($source['upstream_record'] ?? false) === true) {
                continue;
            }
            $relative = str_replace('/', DIRECTORY_SEPARATOR, (string)$source['path']);
            $path = $this->resolveLocalSourcePath($relative);
            if ($path === null
                || !hash_equals(strtolower((string)$source['sha256']), strtolower((string)hash_file('sha256', $path)))
                || (int)filesize($path) !== (int)$source['bytes']
            ) {
                throw new RuntimeException('semantic_glossary_source_fingerprint_mismatch:' . (string)($source['role'] ?? 'unknown'));
            }
        }
    }

    private function resolveLocalSourcePath(string $relative): ?string
    {
        $repoRoot = dirname(__DIR__, 2);
        $candidates = [$repoRoot . DIRECTORY_SEPARATOR . $relative];
        $hotelPrefix = 'HOTEL' . DIRECTORY_SEPARATOR;
        if (str_starts_with($relative, $hotelPrefix)) {
            $candidates[] = $repoRoot . DIRECTORY_SEPARATOR . substr($relative, strlen($hotelPrefix));
        }

        $ancestor = dirname($repoRoot);
        for ($depth = 0; $depth < 4; $depth++) {
            $candidates[] = $ancestor . DIRECTORY_SEPARATOR . $relative;
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function parseExportCsv(string $bytes): array
    {
        if (!str_starts_with($bytes, "\xEF\xBB\xBF")) {
            throw new RuntimeException('semantic_glossary_export_bom_missing');
        }
        $text = substr($bytes, 3);
        if (preg_match('/[^\r]\n|\r[^\n]/', $text) === 1) {
            throw new RuntimeException('semantic_glossary_export_line_ending_invalid');
        }
        $lines = explode("\r\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }
        $result = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '"')) {
                if (!str_ends_with($line, '"')) {
                    throw new RuntimeException('semantic_glossary_export_quote_invalid');
                }
                $line = str_replace('""', '"', substr($line, 1, -1));
            }
            $line = trim($line);
            if ($line === '') {
                throw new RuntimeException('semantic_glossary_export_empty_term');
            }
            $result[] = $line;
        }
        return $result;
    }

    private function dateTime(string $value): string
    {
        $time = strtotime($value);
        if ($time === false) {
            throw new RuntimeException('semantic_glossary_datetime_invalid');
        }
        return date('Y-m-d H:i:s', $time);
    }

    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
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

    private function canonicalHash(mixed $value): string
    {
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
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

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($table), true);
    }

    /** @return list<string> */
    private function tableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $field = 'Field';
        } catch (\Throwable) {
            try {
                $rows = Db::query('PRAGMA table_info(' . $table . ')');
                $field = 'name';
            } catch (\Throwable) {
                return [];
            }
        }
        $columns = [];
        foreach ($rows as $row) {
            $name = trim((string)($row[$field] ?? ''));
            if ($name !== '') {
                $columns[] = $name;
            }
        }
        return $columns;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function filterColumns(string $table, array $data): array
    {
        return array_intersect_key($data, array_flip($this->tableColumns($table)));
    }
}
