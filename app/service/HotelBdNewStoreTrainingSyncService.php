<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class HotelBdNewStoreTrainingSyncService
{
    public const SOURCE = 'user_training_reference';
    public const UNIT_NAME = '酒店BD与新店运营实战｜受控参考知识包';
    private const PACK_KEY = 'suxios.hotel_bd_new_store_training.v1';
    private const SEED_OWNER = 'suxios.hotel_bd_new_store_training';
    private const STABLE_KEY = 'global:user_training:hotel_bd_new_store';
    private const CURRENT_ENTRY_KEY = 'guarded_new_store_launch_workflow';
    private const EXPECTED_SOURCE_SHA256 = 'e6a07ed97562862e06d9a58b228d658e2f8eec85299e948b121821dc9b5191e7';
    private const EXPECTED_SOURCE_BYTES = 19541;
    private const EXPECTED_PACK_SHA256 = 'd1a935d4d1bcfa025819836afa2c8eaaff4104b44b1168737f8d670b324ec2a1';
    private bool $usesDefaultPack;

    public function __construct(
        private ?string $packPath = null,
        private ?string $sourcePath = null
    ) {
        $root = dirname(__DIR__, 2);
        $this->usesDefaultPack = $this->packPath === null;
        $this->packPath ??= $root . '/docs/knowledge/hotel-bd-new-store-training/knowledge-pack.json';
    }

    /** @return array<string, mixed> */
    public function sync(bool $persist = false): array
    {
        $packDigest = $this->fileDigest($this->packPath, 'hotel_bd_training_pack');
        if ($this->usesDefaultPack && !hash_equals(self::EXPECTED_PACK_SHA256, $packDigest)) {
            throw new RuntimeException('hotel_bd_training_pack_fingerprint_mismatch');
        }
        $pack = $this->loadJson($this->packPath, 'hotel_bd_training_pack');
        $validation = $this->validate($pack);
        $sourceVerification = $this->verifyOptionalSource($validation['source_sha256'], $validation['source_bytes']);

        $result = [
            'status' => 'validated',
            'persisted' => false,
            'source' => self::SOURCE,
            'unit_name' => self::UNIT_NAME,
            'pack_key' => self::PACK_KEY,
            'seed_version' => $validation['seed_version'],
            'pack_sha256' => $packDigest,
            'source_sha256' => $validation['source_sha256'],
            'source_file_verification' => $sourceVerification,
            'entry_count' => $validation['entry_count'],
            'golden_case_count' => $validation['golden_case_count'],
            'boundary' => $validation['boundary'],
        ];
        if (!$persist) {
            return $result;
        }
        if (!$this->usesDefaultPack) {
            throw new RuntimeException('hotel_bd_training_untrusted_pack_persist_forbidden');
        }
        if (($sourceVerification['verified'] ?? false) !== true) {
            throw new RuntimeException('hotel_bd_training_source_verification_required');
        }

        foreach (['knowledge_units', 'knowledge_chunks'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('required_knowledge_table_missing:' . $table);
            }
        }
        foreach (['hotel_id', 'created_by', 'stable_key', 'current_chunk_id', 'lifecycle_status'] as $column) {
            if (!$this->hasColumn('knowledge_units', $column)) {
                throw new RuntimeException('required_global_knowledge_column_missing:' . $column);
            }
        }
        foreach ([
            'unit_id', 'version_no', 'lifecycle_status', 'content_digest', 'superseded_by_chunk_id',
            'published_at', 'retired_at', 'type', 'content', 'created_by',
        ] as $column) {
            if (!$this->hasColumn('knowledge_chunks', $column)) {
                throw new RuntimeException('required_knowledge_chunk_column_missing:' . $column);
            }
        }

        $readback = Db::transaction(function () use ($pack, $validation): array {
            $unitId = $this->upsertUnit($pack, $validation);
            $chunkIds = $this->upsertChunks($unitId, $pack, $validation);
            $currentChunkId = (int)($chunkIds[self::CURRENT_ENTRY_KEY] ?? 0);
            if ($currentChunkId <= 0) {
                throw new RuntimeException('current_training_entry_missing');
            }
            if ($this->hasColumn('knowledge_units', 'current_chunk_id')) {
                Db::name('knowledge_units')->where('unit_id', $unitId)->update([
                    'current_chunk_id' => $currentChunkId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $verified = $this->verifyReadback($unitId, $currentChunkId, $pack, $validation);
            if (($verified['readback_verified'] ?? false) !== true) {
                throw new RuntimeException('hotel_bd_training_readback_mismatch');
            }
            return $verified;
        });

        $result['status'] = 'success';
        $result['persisted'] = true;
        $result['readback'] = $readback;
        return $result;
    }

    /** @return array<string, mixed> */
    private function validate(array $pack): array
    {
        $source = is_array($pack['source_document'] ?? null) ? $pack['source_document'] : [];
        $sourceContract = is_array($pack['source_contract'] ?? null) ? $pack['source_contract'] : [];
        $boundary = is_array($pack['boundary'] ?? null) ? $pack['boundary'] : [];
        $entries = is_array($pack['entries'] ?? null) ? array_values($pack['entries']) : [];
        $goldenCases = is_array($pack['golden_cases'] ?? null) ? array_values($pack['golden_cases']) : [];
        $sourceSha = strtolower(trim((string)($source['sha256'] ?? '')));
        $seedVersion = trim((string)($pack['seed_version'] ?? ''));

        $this->assertExactKeys($pack, [
            'schema_version', 'pack_key', 'seed_version', 'title', 'source_document', 'source_contract',
            'boundary', 'known_knowns', 'known_unknowns', 'entries', 'golden_cases',
        ], 'root');
        $this->assertExactKeys($source, [
            'filename', 'sha256', 'bytes', 'recording_date', 'document_created_at', 'document_page_count',
            'page_count_basis', 'paragraph_count', 'table_count', 'extraction_status', 'visual_render_status',
            'raw_document_retained', 'author_metadata_retained', 'signed_remote_asset_url_retained',
        ], 'source_document');
        $this->assertExactKeys($sourceContract, [
            'material_type', 'original_recording_available', 'speaker_identity_verified',
            'case_attribution_verified', 'platform_rule_currency_verified', 'claim_verification_status',
            'sensitive_content_policy',
        ], 'source_contract');
        $this->assertExactKeys($boundary, [
            'scope', 'evidence_level', 'evidence_grade', 'reference_only', 'decision_safe',
            'task_draft_safe', 'external_write_authorized', 'allowed_uses', 'blocked_uses',
        ], 'boundary');

        if ((int)($pack['schema_version'] ?? 0) !== 1
            || (string)($pack['pack_key'] ?? '') !== self::PACK_KEY
            || $seedVersion === ''
            || trim((string)($pack['title'] ?? '')) === ''
            || !preg_match('/^[a-f0-9]{64}$/', $sourceSha)
            || !hash_equals(self::EXPECTED_SOURCE_SHA256, $sourceSha)
            || (int)($source['bytes'] ?? 0) !== self::EXPECTED_SOURCE_BYTES
            || count($entries) < 5
            || count($goldenCases) < 2
        ) {
            throw new RuntimeException('hotel_bd_training_pack_structure_invalid');
        }

        foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
            if (($boundary[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_hotel_bd_training_boundary:' . $field);
            }
        }
        if (($boundary['reference_only'] ?? null) !== true) {
            throw new RuntimeException('hotel_bd_training_reference_only_required');
        }
        foreach (['raw_document_retained', 'author_metadata_retained', 'signed_remote_asset_url_retained'] as $field) {
            if (($source[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_hotel_bd_training_source_retention:' . $field);
            }
        }
        if (($sourceContract['claim_verification_status'] ?? '') !== 'unverified'
            || ($sourceContract['original_recording_available'] ?? null) !== false
        ) {
            throw new RuntimeException('hotel_bd_training_source_contract_invalid');
        }

        $this->assertNoSensitiveMaterial($pack);

        $entryKeys = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('hotel_bd_training_entry_invalid');
            }
            $key = trim((string)($entry['key'] ?? ''));
            $contract = is_array($entry['contract'] ?? null) ? $entry['contract'] : [];
            $this->assertExactKeys($entry, [
                'key', 'title', 'summary', 'classification', 'module_id', 'roles', 'scenes', 'platforms',
                'source_sha256', 'body_markdown', 'contract', 'decision_safe', 'task_draft_safe',
                'external_write_authorized',
            ], 'entry:' . ($key !== '' ? $key : 'missing_key'));
            $this->assertExactKeys($contract, [
                'role', 'trigger', 'required_inputs', 'steps', 'completion_evidence', 'exception_paths',
                'status_feedback', 'effect_review',
            ], 'entry_contract:' . ($key !== '' ? $key : 'missing_key'));
            if ($key === ''
                || isset($entryKeys[$key])
                || trim((string)($entry['title'] ?? '')) === ''
                || trim((string)($entry['body_markdown'] ?? '')) === ''
                || strtolower(trim((string)($entry['source_sha256'] ?? ''))) !== $sourceSha
            ) {
                throw new RuntimeException('hotel_bd_training_entry_contract_invalid:' . ($key !== '' ? $key : 'missing_key'));
            }
            foreach (['role', 'trigger'] as $field) {
                if (trim((string)($contract[$field] ?? '')) === '') {
                    throw new RuntimeException('hotel_bd_training_entry_contract_invalid:' . $key . ':' . $field);
                }
            }
            foreach (['required_inputs', 'steps', 'completion_evidence', 'exception_paths', 'status_feedback', 'effect_review'] as $field) {
                if (!is_array($contract[$field] ?? null) || $contract[$field] === []) {
                    throw new RuntimeException('hotel_bd_training_entry_contract_invalid:' . $key . ':' . $field);
                }
            }
            foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
                if (($entry[$field] ?? null) !== false) {
                    throw new RuntimeException('unsafe_hotel_bd_training_entry:' . $key . ':' . $field);
                }
            }
            $entryKeys[$key] = true;
        }
        if (!isset($entryKeys[self::CURRENT_ENTRY_KEY])) {
            throw new RuntimeException('hotel_bd_training_current_entry_missing');
        }

        foreach ($goldenCases as $case) {
            if (!is_array($case)
                || trim((string)($case['case_key'] ?? '')) === ''
                || !is_array($case['input'] ?? null)
                || !is_array($case['expected'] ?? null)
            ) {
                throw new RuntimeException('hotel_bd_training_golden_case_invalid');
            }
            $this->assertExactKeys($case, ['case_key', 'input', 'expected'], 'golden_case');
            $expected = $case['expected'];
            foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized', 'automatic_promotion_action'] as $field) {
                if (array_key_exists($field, $expected) && $expected[$field] !== false) {
                    throw new RuntimeException('unsafe_hotel_bd_training_golden_case:' . (string)$case['case_key']);
                }
            }
        }

        return [
            'seed_version' => $seedVersion,
            'source_sha256' => $sourceSha,
            'source_bytes' => (int)$source['bytes'],
            'entry_count' => count($entries),
            'golden_case_count' => count($goldenCases),
            'boundary' => $boundary,
            'reviewed_at' => '2026-08-16 00:00:00',
            'review_due_at' => '2026-09-15 00:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function verifyOptionalSource(string $expectedSha, int $expectedBytes): array
    {
        if ($this->sourcePath === null || trim($this->sourcePath) === '') {
            return [
                'verified' => false,
                'status' => 'not_requested_pack_provenance_only',
                'sha256' => $expectedSha,
                'bytes' => $expectedBytes,
            ];
        }
        if (!is_file($this->sourcePath) || !is_readable($this->sourcePath)) {
            throw new RuntimeException('hotel_bd_training_source_document_missing');
        }
        $actualSha = $this->fileDigest($this->sourcePath, 'hotel_bd_training_source_document');
        $actualBytes = filesize($this->sourcePath);
        if (!is_int($actualBytes)
            || $actualBytes !== $expectedBytes
            || !hash_equals($expectedSha, strtolower($actualSha))
        ) {
            throw new RuntimeException('hotel_bd_training_source_fingerprint_mismatch');
        }
        return [
            'verified' => true,
            'status' => 'exact_source_match',
            'sha256' => $actualSha,
            'bytes' => $actualBytes,
        ];
    }

    private function upsertUnit(array $pack, array $validation): int
    {
        $now = date('Y-m-d H:i:s');
        $data = $this->buildUnitData($pack, $validation);
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $data = $this->filterColumns('knowledge_units', $data);

        $existing = $this->hasColumn('knowledge_units', 'stable_key')
            ? Db::name('knowledge_units')->where('stable_key', self::STABLE_KEY)->lock(true)->find()
            : Db::name('knowledge_units')->where('source', self::SOURCE)->where('name', self::UNIT_NAME)->lock(true)->find();
        if (is_array($existing)) {
            unset($data['created_at']);
            Db::name('knowledge_units')->where('unit_id', (int)$existing['unit_id'])->update($data);
            return (int)$existing['unit_id'];
        }

        return (int)Db::name('knowledge_units')->insertGetId($data);
    }

    /** @return array<string, mixed> */
    private function buildUnitData(array $pack, array $validation): array
    {
        return [
            'hotel_id' => 0,
            'stable_key' => self::STABLE_KEY,
            'name' => self::UNIT_NAME,
            'source' => self::SOURCE,
            'status' => 'done',
            'description' => '用户提供的酒店BD与新店运营培训方法已转成受控参考合同。案例数字与平台规则未独立核验，不代表当前酒店事实，不授权任何外部写入。',
            'tags' => $this->json(['酒店BD', '新店运营', '美团', '投资假设', 'OTA信息', 'reference_only', 'global_reference']),
            'created_by' => 0,
            'lifecycle_status' => 'active',
            'lifecycle_reason' => 'user_provided_training_reference_with_unverified_claim_and_external_write_gates',
            'known_knowns' => $this->json((array)($pack['known_knowns'] ?? [])),
            'known_unknowns' => $this->json((array)($pack['known_unknowns'] ?? [])),
            'truth_profile_version' => $validation['seed_version'],
            'reviewed_at' => $validation['reviewed_at'],
            'review_due_at' => $validation['review_due_at'],
        ];
    }

    /** @return array<string, int> */
    private function upsertChunks(int $unitId, array $pack, array $validation): array
    {
        $entriesByKey = [];
        foreach ((array)$pack['entries'] as $entry) {
            $entriesByKey[(string)$entry['key']] = $entry;
        }

        $existingByKey = [];
        $rows = Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            $ownedType = (string)($row['type'] ?? '') === 'hotel_bd_new_store_training_reference';
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                if ($ownedType) {
                    throw new RuntimeException('hotel_bd_training_existing_chunk_identity_invalid');
                }
                continue;
            }
            $this->assertNoSensitiveMaterial($content, 'existing_chunk');
            $contentLifecycle = trim((string)($content['lifecycle_status'] ?? ''));
            if ($contentLifecycle !== 'active') {
                if (!$this->isSupportedInactiveLifecycle($contentLifecycle)
                    || !$this->inactiveSeedRowMatches($row, $content, $unitId)
                ) {
                    throw new RuntimeException('hotel_bd_training_inactive_chunk_invalid');
                }
                continue;
            }
            $seedKey = $this->contentSeedKey($content);
            if ($seedKey === '') {
                throw new RuntimeException('hotel_bd_training_existing_chunk_key_missing');
            }
            if (isset($existingByKey[$seedKey])) {
                throw new RuntimeException('hotel_bd_training_duplicate_active_chunk:' . $seedKey);
            }
            if (isset($entriesByKey[$seedKey])) {
                $existingByKey[$seedKey] = (int)$row['chunk_id'];
                continue;
            }

            $superseded = [
                'schema_version' => '1.0',
                'seed_owner' => self::SEED_OWNER,
                'seed_key' => $seedKey,
                'seed_version' => (string)($content['seed_version'] ?? 'unknown'),
                'lifecycle_status' => 'superseded',
                'superseded_by_seed_version' => $validation['seed_version'],
                'decision_safe' => false,
                'task_draft_safe' => false,
                'external_write_authorized' => false,
            ];
            Db::name('knowledge_chunks')->where('chunk_id', (int)$row['chunk_id'])->update(
                $this->filterColumns('knowledge_chunks', [
                    'version_no' => max(1, (int)($row['version_no'] ?? 0)),
                    'content' => $this->json($superseded),
                    'content_digest' => $this->canonicalHash($superseded),
                    'lifecycle_status' => 'superseded',
                    'type' => 'hotel_bd_new_store_training_reference',
                    'created_by' => 0,
                    'retired_at' => date('Y-m-d H:i:s'),
                ])
            );
        }

        $ids = [];
        foreach ($entriesByKey as $seedKey => $entry) {
            $content = $this->buildChunkContent($entry, $pack, $validation);
            $row = $this->filterColumns(
                'knowledge_chunks',
                $this->buildChunkRowData($unitId, $content, $validation, true)
            );
            if (isset($existingByKey[$seedKey])) {
                $chunkId = $existingByKey[$seedKey];
                unset($row['created_at']);
                Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($row);
            } else {
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($row);
            }
            $ids[$seedKey] = $chunkId;
        }
        return $ids;
    }

    /** @return array<string, mixed> */
    private function buildChunkRowData(
        int $unitId,
        array $content,
        array $validation,
        bool $withCreatedAt
    ): array {
        $row = [
            'unit_id' => $unitId,
            'version_no' => 1,
            'lifecycle_status' => 'active',
            'content_digest' => $this->canonicalHash($content),
            'superseded_by_chunk_id' => null,
            'published_at' => $validation['reviewed_at'],
            'retired_at' => null,
            'type' => 'hotel_bd_new_store_training_reference',
            'content' => $this->json($content),
            'created_by' => 0,
        ];
        if ($withCreatedAt) {
            $row['created_at'] = date('Y-m-d H:i:s');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function buildChunkContent(array $entry, array $pack, array $validation): array
    {
        $seedKey = (string)$entry['key'];
        $sourceContract = (array)$entry['contract'];
        $contract = [
            'role' => (string)$sourceContract['role'],
            'trigger' => (string)$sourceContract['trigger'],
            'required_inputs' => array_values((array)$sourceContract['required_inputs']),
            'steps' => array_values((array)$sourceContract['steps']),
            'completion_evidence' => array_values((array)$sourceContract['completion_evidence']),
            'exception_paths' => array_values((array)$sourceContract['exception_paths']),
            'status_feedback' => array_values((array)$sourceContract['status_feedback']),
            'effect_review' => array_values((array)$sourceContract['effect_review']),
        ];
        $content = [
            'key' => $seedKey,
            'title' => (string)$entry['title'],
            'summary' => (string)$entry['summary'],
            'classification' => (string)$entry['classification'],
            'module_id' => (string)$entry['module_id'],
            'roles' => array_values((array)$entry['roles']),
            'scenes' => array_values((array)$entry['scenes']),
            'platforms' => array_values((array)$entry['platforms']),
            'source_sha256' => (string)$entry['source_sha256'],
            'body_markdown' => (string)$entry['body_markdown'],
            'contract' => $contract,
            'schema_version' => '1.0',
            'module_name' => '酒店BD与新店运营培训参考',
            'scope' => 'industry_training_reference_only',
            'evidence_level' => 'user_provided_unverified',
            'evidence_grade' => 'D',
            'content_key' => $seedKey,
            'content_type' => 'reference_knowledge',
            'source_refs' => [
                'user-provided://酒店行业BD与新店运营实战培训分享.docx#sha256=' . $validation['source_sha256'],
            ],
            'source_document' => [
                'filename' => (string)$pack['source_document']['filename'],
                'sha256' => $validation['source_sha256'],
                'recording_date' => (string)$pack['source_document']['recording_date'],
                'raw_document_retained' => false,
                'author_metadata_retained' => false,
                'signed_remote_asset_url_retained' => false,
            ],
            'reviewed_at' => $validation['reviewed_at'],
            'review_due_at' => $validation['review_due_at'],
            'review_interval_days' => 30,
            'freshness_policy' => 'platform_and_metric_claims_require_current_reverification',
            'decision_policy' => 'reference_only_human_review_required',
            'allowed_uses' => array_values((array)$validation['boundary']['allowed_uses']),
            'blocked_uses' => array_values((array)$validation['boundary']['blocked_uses']),
            'seed_owner' => self::SEED_OWNER,
            'seed_key' => $seedKey,
            'seed_version' => $validation['seed_version'],
            'lifecycle_status' => 'active',
            'contains_current_hotel_fact' => false,
            'claim_verification_status' => 'unverified',
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
            'text' => (string)$entry['body_markdown'],
            'actions' => array_values((array)$contract['steps']),
            'boundaries' => [
                '仅供行业培训和人工分析参考，不代表当前酒店、平台门店或业务日事实。',
                '培训案例数字、平台规则、审核时长和流量阈值必须用当前证据重新验证。',
                '不授权投资决策、OTA修改、推广、评价、内容发布、消息发送或其他外部写入。',
            ],
            'fields' => [
                ['label' => '角色', 'content' => [(string)$contract['role']]],
                ['label' => '触发条件', 'content' => [(string)$contract['trigger']]],
                ['label' => '必要输入', 'content' => $contract['required_inputs']],
                ['label' => '执行步骤', 'content' => $contract['steps']],
                ['label' => '完成证据', 'content' => $contract['completion_evidence']],
                ['label' => '异常分支', 'content' => $contract['exception_paths']],
                ['label' => '状态回显', 'content' => $contract['status_feedback']],
                ['label' => '效果复盘', 'content' => $contract['effect_review']],
            ],
        ];
        if ($seedKey === self::CURRENT_ENTRY_KEY) {
            $content['golden_cases'] = array_values((array)$pack['golden_cases']);
        }
        return $content;
    }

    /** @return array<string, mixed> */
    private function verifyReadback(
        int $unitId,
        int $currentChunkId,
        array $pack,
        array $validation
    ): array {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->lock(true)->find();
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray();
        $expectedContents = [];
        foreach ((array)$pack['entries'] as $entry) {
            $expectedContents[(string)$entry['key']] = $this->buildChunkContent($entry, $pack, $validation);
        }
        $expectedKeys = array_keys($expectedContents);
        sort($expectedKeys);

        $activeKeys = [];
        $activeRowCount = 0;
        $unsafeCount = 0;
        $sourceMismatchCount = 0;
        $duplicateActiveKeyCount = 0;
        $unexpectedActiveKeyCount = 0;
        $contentMismatchCount = 0;
        $rowMismatchCount = 0;
        $inactiveRowMismatchCount = 0;
        $currentEntryFound = false;
        $goldenCaseCount = 0;
        $chunkReadback = [];
        foreach ($chunks as $chunk) {
            $content = $this->decodeJson($chunk['content'] ?? null);
            $ownedType = (string)($chunk['type'] ?? '') === 'hotel_bd_new_store_training_reference';
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                if ($ownedType) {
                    $rowMismatchCount++;
                }
                continue;
            }
            $contentLifecycle = trim((string)($content['lifecycle_status'] ?? ''));
            if ($contentLifecycle !== 'active') {
                if (!$this->isSupportedInactiveLifecycle($contentLifecycle)
                    || !$this->inactiveSeedRowMatches($chunk, $content, $unitId)
                ) {
                    $inactiveRowMismatchCount++;
                }
                continue;
            }
            $activeRowCount++;
            $seedKey = $this->contentSeedKey($content);
            if ($seedKey === '' || isset($activeKeys[$seedKey])) {
                $duplicateActiveKeyCount++;
            } else {
                $activeKeys[$seedKey] = true;
            }
            if (!isset($expectedContents[$seedKey])) {
                $unexpectedActiveKeyCount++;
            } else {
                if (!hash_equals(
                    $this->canonicalHash($expectedContents[$seedKey]),
                    $this->canonicalHash($content)
                )) {
                    $contentMismatchCount++;
                }
                $expectedRow = $this->filterColumns(
                    'knowledge_chunks',
                    $this->buildChunkRowData($unitId, $expectedContents[$seedKey], $validation, false)
                );
                if (!$this->recordMatchesExpected(
                    $chunk,
                    $expectedRow,
                    ['content'],
                    ['unit_id', 'created_by', 'version_no']
                )) {
                    $rowMismatchCount++;
                }
            }
            if (($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
            ) {
                $unsafeCount++;
            }
            $sourceDocument = is_array($content['source_document'] ?? null)
                ? $content['source_document']
                : [];
            if (($sourceDocument['sha256'] ?? '') !== $validation['source_sha256']) {
                $sourceMismatchCount++;
            }
            if ($seedKey === self::CURRENT_ENTRY_KEY && (int)$chunk['chunk_id'] === $currentChunkId) {
                $currentEntryFound = true;
                $goldenCaseCount = count((array)($content['golden_cases'] ?? []));
            }
            $chunkReadback[] = [
                'chunk_id' => (int)$chunk['chunk_id'],
                'seed_key' => $seedKey,
                'content_sha256' => $this->canonicalHash($content),
                'content_match' => isset($expectedContents[$seedKey])
                    && hash_equals($this->canonicalHash($expectedContents[$seedKey]), $this->canonicalHash($content)),
                'decision_safe' => (bool)($content['decision_safe'] ?? true),
                'task_draft_safe' => (bool)($content['task_draft_safe'] ?? true),
                'external_write_authorized' => (bool)($content['external_write_authorized'] ?? true),
            ];
        }
        $readbackKeys = array_keys($activeKeys);
        sort($readbackKeys);
        $expectedUnit = $this->filterColumns('knowledge_units', $this->buildUnitData($pack, $validation));
        $unitMatches = is_array($unit) && $this->recordMatchesExpected(
            $unit,
            $expectedUnit,
            ['tags', 'known_knowns', 'known_unknowns'],
            ['hotel_id', 'created_by']
        );
        $currentUnitChunkMatches = !$this->hasColumn('knowledge_units', 'current_chunk_id')
            || (int)($unit['current_chunk_id'] ?? 0) === $currentChunkId;
        $verified = $unitMatches
            && $expectedKeys === $readbackKeys
            && $activeRowCount === $validation['entry_count']
            && $unsafeCount === 0
            && $sourceMismatchCount === 0
            && $duplicateActiveKeyCount === 0
            && $unexpectedActiveKeyCount === 0
            && $contentMismatchCount === 0
            && $rowMismatchCount === 0
            && $inactiveRowMismatchCount === 0
            && $currentEntryFound
            && $goldenCaseCount === $validation['golden_case_count']
            && $currentUnitChunkMatches;

        return [
            'readback_verified' => $verified,
            'unit_id' => $unitId,
            'current_chunk_id' => $currentChunkId,
            'expected_active_chunk_count' => $validation['entry_count'],
            'readback_active_chunk_count' => $activeRowCount,
            'expected_seed_keys' => $expectedKeys,
            'readback_seed_keys' => $readbackKeys,
            'unit_match' => $unitMatches,
            'unsafe_chunk_count' => $unsafeCount,
            'source_mismatch_count' => $sourceMismatchCount,
            'duplicate_active_key_count' => $duplicateActiveKeyCount,
            'unexpected_active_key_count' => $unexpectedActiveKeyCount,
            'content_mismatch_count' => $contentMismatchCount,
            'row_mismatch_count' => $rowMismatchCount,
            'inactive_row_mismatch_count' => $inactiveRowMismatchCount,
            'current_entry_found' => $currentEntryFound,
            'golden_case_count' => $goldenCaseCount,
            'chunk_readback' => $chunkReadback,
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

    private function fileDigest(string $path, string $label): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/', strtolower($digest))) {
            throw new RuntimeException($label . '_sha256_failed');
        }
        return strtolower($digest);
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

    /** @param array<string, mixed> $content */
    private function contentSeedKey(array $content): string
    {
        foreach (['seed_key', 'content_key', 'key'] as $field) {
            $value = trim((string)($content[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function isSupportedInactiveLifecycle(string $status): bool
    {
        return in_array($status, ['superseded', 'retired', 'stale'], true);
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $content */
    private function inactiveSeedRowMatches(array $row, array $content, int $unitId): bool
    {
        $status = trim((string)($content['lifecycle_status'] ?? ''));
        return $this->isSupportedInactiveLifecycle($status)
            && (int)($row['unit_id'] ?? 0) === $unitId
            && (int)($row['created_by'] ?? -1) === 0
            && (int)($row['version_no'] ?? 0) > 0
            && (string)($row['type'] ?? '') === 'hotel_bd_new_store_training_reference'
            && (string)($row['lifecycle_status'] ?? '') === $status
            && trim((string)($row['retired_at'] ?? '')) !== ''
            && hash_equals(
                $this->canonicalHash($content),
                strtolower(trim((string)($row['content_digest'] ?? '')))
            );
    }

    /** @param array<int, string> $expected */
    private function assertExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('hotel_bd_training_schema_keys_invalid:' . $path);
        }
    }

    private function assertNoSensitiveMaterial(mixed $value, string $path = 'root'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $normalizedKey = strtolower((string)$key);
                if (preg_match(
                    '/(?:authorization|password|passwd|credential|api[_-]?key|access[_-]?token|refresh[_-]?token|session[_-]?cookie|raw[_-]?(?:transcript|response)|id[_-]?card|identity[_-]?number|phone[_-]?number|email[_-]?address)/i',
                    $normalizedKey
                )) {
                    throw new RuntimeException('hotel_bd_training_sensitive_key:' . $path);
                }
                $this->assertNoSensitiveMaterial($nested, $path . '.' . (string)$key);
            }
            return;
        }
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        $patterns = [
            '/https?:\/\//i',
            '/(?:OSSAccessKeyId|X-Amz-Signature|Signature=|get-notes\.umiwi\.com)/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{8,}/i',
            '/(?:authorization|cookie|password|passwd|token|secret|api[_-]?key)\s*[:=]\s*\S+/i',
            '/(?<!\d)1[3-9]\d{9}(?!\d)/',
            '/(?<!\d)[1-9]\d{5}(?:19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{3}[0-9Xx](?!\d)/',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new RuntimeException('hotel_bd_training_sensitive_value:' . $path);
            }
        }
    }

    private function canonicalHash(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $expected
     * @param array<int, string> $jsonFields
     * @param array<int, string> $integerFields
     */
    private function recordMatchesExpected(
        array $actual,
        array $expected,
        array $jsonFields = [],
        array $integerFields = []
    ): bool {
        foreach ($expected as $field => $expectedValue) {
            if (!array_key_exists($field, $actual)) {
                return false;
            }
            if (in_array($field, $jsonFields, true)) {
                if (!hash_equals(
                    $this->canonicalHash($this->decodeJson($expectedValue)),
                    $this->canonicalHash($this->decodeJson($actual[$field]))
                )) {
                    return false;
                }
                continue;
            }
            if (in_array($field, $integerFields, true)) {
                if ((int)$actual[$field] !== (int)$expectedValue) {
                    return false;
                }
                continue;
            }
            if ((string)$actual[$field] !== (string)$expectedValue) {
                return false;
            }
        }
        return true;
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
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new RuntimeException('invalid_knowledge_table_identifier');
        }
        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
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
        return array_intersect_key($data, array_flip($this->tableColumns($table)));
    }
}
