<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class HotelManagerInterviewKnowledgeSyncService
{
    public const SOURCE = 'user_interview_reference';
    public const UNIT_NAME = '酒店店长访谈与资料蒸馏｜受控参考知识包';
    private const PACK_KEY = 'suxios.hotel_manager_interview_distillation.v1';
    private const STABLE_KEY = 'global:user_reference:hotel_manager_interview_distillation';
    private const SEED_OWNER = 'suxios.hotel_manager_interview_distillation';
    private const CHUNK_TYPE = 'hotel_manager_interview_reference';
    private const CURRENT_ENTRY_KEY = 'distillation_protocol';
    private const EXPECTED_PACK_SHA256 = 'f17378dd2ac94d546a444a7dabb64d45bb150e8eccb3ea39dba1c229cb61206f';
    private const EXPECTED_SOURCES = [
        'manager_interview_questions' => [
            'sha256' => '8ef04ba708bdb18ef54ba8f70744afe80fafe63412dbbe4c49cbf339f1aa30af',
            'bytes' => 9581,
        ],
        'distillation_controller_prompt' => [
            'sha256' => '6d2ba0f0ec83389f62c37878c03494ed6cbf9c80cf47fd60b6001624883b452d',
            'bytes' => 16679,
        ],
    ];

    /** @param array<string, string> $sourcePaths */
    public function __construct(
        private ?string $packPath = null,
        private array $sourcePaths = []
    ) {
        $this->packPath ??= dirname(__DIR__, 2)
            . '/docs/knowledge/hotel-manager-interview-distillation/knowledge-pack.json';
    }

    /** @return array<string, mixed> */
    public function sync(bool $persist = false): array
    {
        $packDigest = $this->fileDigest($this->packPath, 'hotel_manager_interview_pack');
        if (!hash_equals(self::EXPECTED_PACK_SHA256, $packDigest)) {
            throw new RuntimeException('hotel_manager_interview_pack_fingerprint_mismatch');
        }
        $pack = $this->loadJson($this->packPath);
        $validation = $this->validate($pack);
        $sourceVerification = $this->verifySources($validation['sources']);
        $result = [
            'status' => 'validated',
            'persisted' => false,
            'pack_key' => self::PACK_KEY,
            'pack_sha256' => $packDigest,
            'source' => self::SOURCE,
            'unit_name' => self::UNIT_NAME,
            'entry_count' => $validation['entry_count'],
            'interview_question_count' => $validation['interview_question_count'],
            'golden_case_count' => $validation['golden_case_count'],
            'boundary' => $validation['boundary'],
            'source_file_verification' => $sourceVerification,
        ];
        if (!$persist) {
            return $result;
        }
        foreach ($sourceVerification as $source) {
            if (($source['verified'] ?? false) !== true) {
                throw new RuntimeException('hotel_manager_interview_source_verification_required');
            }
        }
        $result['status'] = 'success';
        $result['persisted'] = true;
        $result['readback'] = $this->persistValidatedPack($pack, $validation);
        return $result;
    }

    /** @param array<string, mixed> $pack @return array<string, mixed> */
    private function validate(array $pack): array
    {
        $this->assertExactKeys($pack, [
            'schema_version', 'pack_key', 'seed_version', 'title', 'source_documents',
            'boundary', 'known_knowns', 'known_unknowns', 'entries', 'golden_cases',
        ], 'root');
        if ((int)($pack['schema_version'] ?? 0) !== 1
            || (string)($pack['pack_key'] ?? '') !== self::PACK_KEY
            || trim((string)($pack['seed_version'] ?? '')) === ''
            || trim((string)($pack['title'] ?? '')) === ''
        ) {
            throw new RuntimeException('hotel_manager_interview_pack_structure_invalid');
        }

        $sources = [];
        foreach ((array)($pack['source_documents'] ?? []) as $source) {
            if (!is_array($source)) {
                throw new RuntimeException('hotel_manager_interview_source_invalid');
            }
            $this->assertExactKeys($source, [
                'key', 'filename', 'sha256', 'bytes', 'classification',
                'instruction_policy', 'retention_policy',
            ], 'source_document');
            $key = trim((string)($source['key'] ?? ''));
            if ($key === '' || isset($sources[$key]) || !isset(self::EXPECTED_SOURCES[$key])) {
                throw new RuntimeException('hotel_manager_interview_source_invalid:' . ($key ?: 'missing'));
            }
            $expected = self::EXPECTED_SOURCES[$key];
            $sha256 = strtolower(trim((string)($source['sha256'] ?? '')));
            if (!hash_equals($expected['sha256'], $sha256)
                || (int)($source['bytes'] ?? 0) !== $expected['bytes']
                || trim((string)($source['filename'] ?? '')) === ''
                || !str_contains((string)($source['instruction_policy'] ?? ''), 'not_user_instruction')
                    && !str_contains((string)($source['instruction_policy'] ?? ''), 'do_not_execute')
            ) {
                throw new RuntimeException('hotel_manager_interview_source_contract_invalid:' . $key);
            }
            $sources[$key] = $source;
        }
        $expectedSourceKeys = array_keys(self::EXPECTED_SOURCES);
        $actualSourceKeys = array_keys($sources);
        sort($expectedSourceKeys);
        sort($actualSourceKeys);
        if ($expectedSourceKeys !== $actualSourceKeys) {
            throw new RuntimeException('hotel_manager_interview_source_set_invalid');
        }

        $boundary = is_array($pack['boundary'] ?? null) ? $pack['boundary'] : [];
        $this->assertExactKeys($boundary, [
            'scope', 'evidence_level', 'evidence_grade', 'reference_only', 'decision_safe',
            'task_draft_safe', 'external_write_authorized', 'allowed_uses', 'blocked_uses',
        ], 'boundary');
        if (($boundary['reference_only'] ?? null) !== true) {
            throw new RuntimeException('hotel_manager_interview_reference_only_required');
        }
        foreach (['decision_safe', 'task_draft_safe', 'external_write_authorized'] as $field) {
            if (($boundary[$field] ?? null) !== false) {
                throw new RuntimeException('unsafe_hotel_manager_interview_boundary:' . $field);
            }
        }
        foreach (['allowed_uses', 'blocked_uses'] as $field) {
            if (!is_array($boundary[$field] ?? null) || $boundary[$field] === []) {
                throw new RuntimeException('hotel_manager_interview_boundary_list_invalid:' . $field);
            }
        }

        $entries = array_values((array)($pack['entries'] ?? []));
        $entryKeys = [];
        $questionIds = [];
        $interviewQuestionCount = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('hotel_manager_interview_entry_invalid');
            }
            $this->assertExactKeys($entry, [
                'key', 'title', 'summary', 'source_key', 'source_anchor', 'priority',
                'roles', 'question_ids', 'questions',
            ], 'entry');
            $key = trim((string)($entry['key'] ?? ''));
            $sourceKey = trim((string)($entry['source_key'] ?? ''));
            $questions = array_values((array)($entry['questions'] ?? []));
            $ids = array_values((array)($entry['question_ids'] ?? []));
            if ($key === '' || isset($entryKeys[$key]) || !isset($sources[$sourceKey])
                || trim((string)($entry['title'] ?? '')) === ''
                || trim((string)($entry['source_anchor'] ?? '')) === ''
                || !is_array($entry['roles'] ?? null) || $entry['roles'] === []
                || $questions === []
            ) {
                throw new RuntimeException('hotel_manager_interview_entry_contract_invalid:' . ($key ?: 'missing'));
            }
            foreach ($questions as $question) {
                if (trim((string)$question) === '') {
                    throw new RuntimeException('hotel_manager_interview_question_empty:' . $key);
                }
            }
            if ($sourceKey === 'manager_interview_questions') {
                if (count($ids) !== count($questions)) {
                    throw new RuntimeException('hotel_manager_interview_question_alignment_invalid:' . $key);
                }
                $interviewQuestionCount += count($questions);
                foreach ($ids as $id) {
                    $id = (string)$id;
                    if (!preg_match('/^QYP-INT-0(?:0[1-9]|[1-3][0-9]|4[0-2])$/D', $id)
                        || isset($questionIds[$id])
                    ) {
                        throw new RuntimeException('hotel_manager_interview_question_id_invalid:' . $id);
                    }
                    $questionIds[$id] = true;
                }
            } elseif ($ids !== []) {
                throw new RuntimeException('hotel_manager_interview_method_question_ids_forbidden');
            }
            $entryKeys[$key] = true;
        }
        $expectedQuestionIds = array_map(
            static fn(int $number): string => sprintf('QYP-INT-%03d', $number),
            range(1, 42)
        );
        $actualQuestionIds = array_keys($questionIds);
        sort($actualQuestionIds);
        if (count($entries) !== 15
            || $interviewQuestionCount !== 42
            || $actualQuestionIds !== $expectedQuestionIds
            || !isset($entryKeys[self::CURRENT_ENTRY_KEY])
        ) {
            throw new RuntimeException('hotel_manager_interview_entry_set_invalid');
        }

        $goldenCases = array_values((array)($pack['golden_cases'] ?? []));
        foreach ($goldenCases as $case) {
            if (!is_array($case)) {
                throw new RuntimeException('hotel_manager_interview_golden_case_invalid');
            }
            $this->assertExactKeys($case, ['case_key', 'input', 'expected'], 'golden_case');
            if (trim((string)($case['case_key'] ?? '')) === ''
                || trim((string)($case['input'] ?? '')) === ''
                || trim((string)($case['expected'] ?? '')) === ''
            ) {
                throw new RuntimeException('hotel_manager_interview_golden_case_invalid');
            }
        }
        if (count($goldenCases) < 4) {
            throw new RuntimeException('hotel_manager_interview_golden_case_set_invalid');
        }
        $this->assertNoSensitiveValues($pack);

        return [
            'seed_version' => (string)$pack['seed_version'],
            'sources' => $sources,
            'boundary' => $boundary,
            'entry_count' => count($entries),
            'interview_question_count' => $interviewQuestionCount,
            'golden_case_count' => count($goldenCases),
            'reviewed_at' => '2026-08-16 00:00:00',
            'review_due_at' => '2026-11-14 00:00:00',
        ];
    }

    /** @param array<string, array<string, mixed>> $sources @return array<string, array<string, mixed>> */
    private function verifySources(array $sources): array
    {
        $result = [];
        foreach ($sources as $key => $source) {
            $path = trim((string)($this->sourcePaths[$key] ?? ''));
            if ($path === '') {
                $result[$key] = [
                    'verified' => false,
                    'status' => 'not_requested_pack_provenance_only',
                    'sha256' => (string)$source['sha256'],
                    'bytes' => (int)$source['bytes'],
                ];
                continue;
            }
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('hotel_manager_interview_source_missing:' . $key);
            }
            $bytes = filesize($path);
            $sha256 = $this->fileDigest($path, 'hotel_manager_interview_source_' . $key);
            if (!is_int($bytes)
                || $bytes !== (int)$source['bytes']
                || !hash_equals((string)$source['sha256'], $sha256)
            ) {
                throw new RuntimeException('hotel_manager_interview_source_fingerprint_mismatch:' . $key);
            }
            $result[$key] = [
                'verified' => true,
                'status' => 'exact_source_match',
                'sha256' => $sha256,
                'bytes' => $bytes,
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $pack @param array<string, mixed> $validation @return array<string, mixed> */
    private function persistValidatedPack(array $pack, array $validation): array
    {
        foreach (['knowledge_units', 'knowledge_chunks'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('required_knowledge_table_missing:' . $table);
            }
        }
        foreach (['hotel_id', 'stable_key', 'current_chunk_id', 'lifecycle_status', 'created_by'] as $column) {
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

        return Db::transaction(function () use ($pack, $validation): array {
            $unitId = $this->upsertUnit($pack, $validation);
            $chunkIds = $this->upsertChunks($unitId, $pack, $validation);
            $currentChunkId = (int)($chunkIds[self::CURRENT_ENTRY_KEY] ?? 0);
            if ($currentChunkId <= 0) {
                throw new RuntimeException('hotel_manager_interview_current_chunk_missing');
            }
            Db::name('knowledge_units')->where('unit_id', $unitId)->update([
                'current_chunk_id' => $currentChunkId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $readback = $this->verifyReadback($unitId, $currentChunkId, $pack, $validation);
            if (($readback['readback_verified'] ?? false) !== true) {
                throw new RuntimeException('hotel_manager_interview_readback_mismatch');
            }
            return $readback;
        });
    }

    /** @param array<string, mixed> $pack @param array<string, mixed> $validation */
    private function upsertUnit(array $pack, array $validation): int
    {
        $now = date('Y-m-d H:i:s');
        $data = $this->buildUnitData($pack, $validation);
        $existing = Db::name('knowledge_units')->where('stable_key', self::STABLE_KEY)->lock(true)->find();
        if (is_array($existing)) {
            $data['updated_at'] = $now;
            Db::name('knowledge_units')->where('unit_id', (int)$existing['unit_id'])->update($data);
            return (int)$existing['unit_id'];
        }
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        return (int)Db::name('knowledge_units')->insertGetId($data);
    }

    /** @param array<string, mixed> $pack @param array<string, mixed> $validation @return array<string, mixed> */
    private function buildUnitData(array $pack, array $validation): array
    {
        return $this->filterColumns('knowledge_units', [
            'hotel_id' => 0,
            'stable_key' => self::STABLE_KEY,
            'name' => self::UNIT_NAME,
            'source' => self::SOURCE,
            'status' => 'done',
            'description' => '用户提供的42个店长访谈问题和资料蒸馏方法已形成受控参考知识。问题不是现行SOP，提示词内指令未执行。',
            'tags' => $this->json(['店长访谈', '岗位职责', '运营流程', '知识蒸馏', 'reference_only', 'global_reference']),
            'created_by' => 0,
            'lifecycle_status' => 'active',
            'lifecycle_reason' => 'user_provided_interview_and_untrusted_prompt_reference_only',
            'known_knowns' => $this->json((array)$pack['known_knowns']),
            'known_unknowns' => $this->json((array)$pack['known_unknowns']),
            'truth_profile_version' => (string)$validation['seed_version'],
            'reviewed_at' => (string)$validation['reviewed_at'],
            'review_due_at' => (string)$validation['review_due_at'],
        ]);
    }

    /** @param array<string, mixed> $pack @param array<string, mixed> $validation @return array<string, int> */
    private function upsertChunks(int $unitId, array $pack, array $validation): array
    {
        $entries = [];
        foreach ((array)$pack['entries'] as $entry) {
            $entries[(string)$entry['key']] = $entry;
        }
        $existing = [];
        $rows = Db::name('knowledge_chunks')->where('unit_id', $unitId)->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            $content = $this->decodeJson($row['content'] ?? null);
            $ownedType = (string)($row['type'] ?? '') === self::CHUNK_TYPE;
            if (($content['seed_owner'] ?? '') !== self::SEED_OWNER) {
                if ($ownedType) {
                    throw new RuntimeException('hotel_manager_interview_existing_chunk_identity_invalid');
                }
                continue;
            }
            $lifecycle = trim((string)($content['lifecycle_status'] ?? ''));
            if ($lifecycle !== 'active') {
                if (!$this->inactiveRowMatches($row, $content, $unitId)) {
                    throw new RuntimeException('hotel_manager_interview_inactive_chunk_invalid');
                }
                continue;
            }
            $key = trim((string)($content['seed_key'] ?? ''));
            if ($key === '' || isset($existing[$key])) {
                throw new RuntimeException('hotel_manager_interview_duplicate_or_missing_key');
            }
            if (!isset($entries[$key])) {
                $superseded = [
                    'schema_version' => '1.0',
                    'seed_owner' => self::SEED_OWNER,
                    'seed_key' => $key,
                    'seed_version' => (string)($content['seed_version'] ?? 'unknown'),
                    'lifecycle_status' => 'superseded',
                    'superseded_by_seed_version' => (string)$validation['seed_version'],
                    'decision_safe' => false,
                    'task_draft_safe' => false,
                    'external_write_authorized' => false,
                ];
                Db::name('knowledge_chunks')->where('chunk_id', (int)$row['chunk_id'])->update(
                    $this->filterColumns('knowledge_chunks', [
                        'version_no' => max(1, (int)($row['version_no'] ?? 0)),
                        'lifecycle_status' => 'superseded',
                        'content_digest' => $this->canonicalHash($superseded),
                        'retired_at' => date('Y-m-d H:i:s'),
                        'type' => self::CHUNK_TYPE,
                        'content' => $this->json($superseded),
                        'created_by' => 0,
                    ])
                );
                continue;
            }
            $existing[$key] = (int)$row['chunk_id'];
        }

        $ids = [];
        foreach ($entries as $key => $entry) {
            $content = $this->buildChunkContent($entry, $pack, $validation);
            $row = $this->buildChunkRow($unitId, $content, $validation, true);
            if (isset($existing[$key])) {
                $chunkId = $existing[$key];
                unset($row['created_at']);
                Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update($row);
            } else {
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId($row);
            }
            $ids[$key] = $chunkId;
        }
        return $ids;
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $pack @param array<string, mixed> $validation @return array<string, mixed> */
    private function buildChunkContent(array $entry, array $pack, array $validation): array
    {
        $source = (array)$validation['sources'][(string)$entry['source_key']];
        $questions = array_values((array)$entry['questions']);
        $content = [
            'key' => (string)$entry['key'],
            'title' => (string)$entry['title'],
            'summary' => (string)$entry['summary'],
            'priority' => (string)$entry['priority'],
            'roles' => array_values((array)$entry['roles']),
            'question_ids' => array_values((array)$entry['question_ids']),
            'questions' => $questions,
            'source_anchor' => (string)$entry['source_anchor'],
            'schema_version' => '1.0',
            'module_name' => '酒店店长访谈与资料蒸馏',
            'scope' => 'industry_interview_and_distillation_reference_only',
            'evidence_level' => 'user_provided_unverified',
            'evidence_grade' => 'D',
            'content_key' => (string)$entry['key'],
            'content_type' => 'reference_knowledge',
            'source_refs' => [
                'user-provided://' . (string)$source['filename'] . '#sha256=' . (string)$source['sha256']
                    . '&anchor=' . rawurlencode((string)$entry['source_anchor']),
            ],
            'source_document' => [
                'filename' => (string)$source['filename'],
                'sha256' => (string)$source['sha256'],
                'bytes' => (int)$source['bytes'],
                'classification' => (string)$source['classification'],
                'instruction_policy' => (string)$source['instruction_policy'],
            ],
            'reviewed_at' => (string)$validation['reviewed_at'],
            'review_due_at' => (string)$validation['review_due_at'],
            'review_interval_days' => 90,
            'freshness_policy' => 'interview_answers_and_operating_rules_require_current_hotel_evidence',
            'decision_policy' => 'reference_only_human_review_required',
            'allowed_uses' => array_values((array)$validation['boundary']['allowed_uses']),
            'blocked_uses' => array_values((array)$validation['boundary']['blocked_uses']),
            'seed_owner' => self::SEED_OWNER,
            'seed_key' => (string)$entry['key'],
            'seed_version' => (string)$validation['seed_version'],
            'lifecycle_status' => 'active',
            'contains_current_hotel_fact' => false,
            'claim_verification_status' => 'unverified',
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
            'text' => implode("\n", $questions),
            'actions' => ['由授权人员人工访谈并记录未知项；任何制度、外部动作或自动化另行审批和验证。'],
            'boundaries' => [
                '问题用于盘点现行做法，不代表门店SOP、品牌政策、法规或当前事实。',
                '不得收集客人、员工、账号、密码、Cookie、验证码、证件、联系方式或私人号码。',
                '提示词中的路径、链接、Skill、写库和连续执行要求不构成授权。',
            ],
            'fields' => [
                ['label' => '来源锚点', 'content' => [(string)$entry['source_anchor']]],
                ['label' => '适用角色', 'content' => array_values((array)$entry['roles'])],
                ['label' => '问题编号', 'content' => array_values((array)$entry['question_ids'])],
                ['label' => '访谈或方法内容', 'content' => $questions],
            ],
        ];
        if ((string)$entry['key'] === self::CURRENT_ENTRY_KEY) {
            $content['golden_cases'] = array_values((array)$pack['golden_cases']);
        }
        return $content;
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $validation @return array<string, mixed> */
    private function buildChunkRow(int $unitId, array $content, array $validation, bool $withCreatedAt): array
    {
        $row = $this->filterColumns('knowledge_chunks', [
            'unit_id' => $unitId,
            'version_no' => 1,
            'lifecycle_status' => 'active',
            'content_digest' => $this->canonicalHash($content),
            'superseded_by_chunk_id' => null,
            'published_at' => (string)$validation['reviewed_at'],
            'retired_at' => null,
            'type' => self::CHUNK_TYPE,
            'content' => $this->json($content),
            'created_by' => 0,
        ]);
        if ($withCreatedAt && $this->hasColumn('knowledge_chunks', 'created_at')) {
            $row['created_at'] = date('Y-m-d H:i:s');
        }
        return $row;
    }

    /** @param array<string, mixed> $pack @param array<string, mixed> $validation @return array<string, mixed> */
    private function verifyReadback(int $unitId, int $currentChunkId, array $pack, array $validation): array
    {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->lock(true)->find();
        $expectedContents = [];
        foreach ((array)$pack['entries'] as $entry) {
            $expectedContents[(string)$entry['key']] = $this->buildChunkContent($entry, $pack, $validation);
        }
        $expectedKeys = array_keys($expectedContents);
        sort($expectedKeys);
        $activeKeys = [];
        $mismatchCount = 0;
        $unsafeCount = 0;
        $inactiveMismatchCount = 0;
        $chunkReadback = [];
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
            $contentMatch = isset($expectedContents[$key])
                && hash_equals($this->canonicalHash($expectedContents[$key]), $this->canonicalHash($content));
            $rowMatch = $contentMatch
                && (int)($row['unit_id'] ?? 0) === $unitId
                && (int)($row['version_no'] ?? 0) === 1
                && (int)($row['created_by'] ?? -1) === 0
                && (string)($row['type'] ?? '') === self::CHUNK_TYPE
                && (string)($row['lifecycle_status'] ?? '') === 'active'
                && hash_equals($this->canonicalHash($content), strtolower((string)($row['content_digest'] ?? '')));
            if ($key === '' || isset($activeKeys[$key]) || !$rowMatch) {
                $mismatchCount++;
            } else {
                $activeKeys[$key] = true;
            }
            if (($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
            ) {
                $unsafeCount++;
            }
            $chunkReadback[] = [
                'chunk_id' => (int)$row['chunk_id'],
                'seed_key' => $key,
                'content_sha256' => $this->canonicalHash($content),
                'content_match' => $contentMatch,
                'decision_safe' => (bool)($content['decision_safe'] ?? true),
                'task_draft_safe' => (bool)($content['task_draft_safe'] ?? true),
                'external_write_authorized' => (bool)($content['external_write_authorized'] ?? true),
            ];
        }
        $readbackKeys = array_keys($activeKeys);
        sort($readbackKeys);
        $expectedUnit = $this->buildUnitData($pack, $validation);
        $unitMatch = is_array($unit)
            && (int)($unit['hotel_id'] ?? -1) === 0
            && (int)($unit['created_by'] ?? -1) === 0
            && (int)($unit['current_chunk_id'] ?? 0) === $currentChunkId
            && $this->recordMatchesExpected($unit, $expectedUnit, ['tags', 'known_knowns', 'known_unknowns']);
        $verified = $unitMatch
            && $expectedKeys === $readbackKeys
            && count($readbackKeys) === (int)$validation['entry_count']
            && $mismatchCount === 0
            && $unsafeCount === 0
            && $inactiveMismatchCount === 0;
        return [
            'readback_verified' => $verified,
            'unit_id' => $unitId,
            'current_chunk_id' => $currentChunkId,
            'expected_active_chunk_count' => (int)$validation['entry_count'],
            'readback_active_chunk_count' => count($readbackKeys),
            'expected_seed_keys' => $expectedKeys,
            'readback_seed_keys' => $readbackKeys,
            'unit_match' => $unitMatch,
            'mismatch_count' => $mismatchCount,
            'unsafe_chunk_count' => $unsafeCount,
            'inactive_row_mismatch_count' => $inactiveMismatchCount,
            'chunk_readback' => $chunkReadback,
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $content */
    private function inactiveRowMatches(array $row, array $content, int $unitId): bool
    {
        $status = trim((string)($content['lifecycle_status'] ?? ''));
        return in_array($status, ['superseded', 'retired', 'stale'], true)
            && (int)($row['unit_id'] ?? 0) === $unitId
            && (int)($row['version_no'] ?? 0) > 0
            && (int)($row['created_by'] ?? -1) === 0
            && (string)($row['type'] ?? '') === self::CHUNK_TYPE
            && (string)($row['lifecycle_status'] ?? '') === $status
            && trim((string)($row['retired_at'] ?? '')) !== ''
            && hash_equals($this->canonicalHash($content), strtolower((string)($row['content_digest'] ?? '')));
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('hotel_manager_interview_pack_missing');
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('hotel_manager_interview_pack_invalid_json');
        }
        return $decoded;
    }

    private function fileDigest(string $path, string $label): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !preg_match('/^[a-f0-9]{64}$/D', strtolower($digest))) {
            throw new RuntimeException($label . '_sha256_failed');
        }
        return strtolower($digest);
    }

    /** @param array<int, string> $expected */
    private function assertExactKeys(array $value, array $expected, string $path): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('hotel_manager_interview_schema_keys_invalid:' . $path);
        }
    }

    private function assertNoSensitiveValues(mixed $value, string $path = 'root'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $this->assertNoSensitiveValues($nested, $path . '.' . (string)$key);
            }
            return;
        }
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        foreach ([
            '/https?:\/\//i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{8,}/i',
            '/(?:authorization|cookie|password|passwd|token|secret|api[_-]?key)\s*[:=]\s*\S+/i',
            '/(?<!\d)1[3-9]\d{9}(?!\d)/',
            '/(?<!\d)[1-9]\d{5}(?:19|20)\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])\d{3}[0-9Xx](?!\d)/',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ] as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new RuntimeException('hotel_manager_interview_sensitive_value:' . $path);
            }
        }
    }

    private function canonicalHash(mixed $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
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

    /** @param array<string, mixed> $actual @param array<string, mixed> $expected @param array<int, string> $jsonFields */
    private function recordMatchesExpected(array $actual, array $expected, array $jsonFields = []): bool
    {
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
        if (!preg_match('/^[a-z0-9_]+$/D', $table)) {
            return false;
        }
        try {
            return Db::query("SHOW TABLES LIKE '" . $table . "'") !== [];
        } catch (\Throwable) {
            try {
                return Db::query('PRAGMA table_info(`' . $table . '`)') !== [];
            } catch (\Throwable) {
                return false;
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableColumns($table), true);
    }

    /** @return array<int, string> */
    private function tableColumns(string $table): array
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $table)) {
            throw new RuntimeException('invalid_knowledge_table_identifier');
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
        } catch (\Throwable) {
            $rows = Db::query('PRAGMA table_info(`' . $table . '`)');
        }
        $columns = [];
        foreach ($rows as $row) {
            $field = trim((string)($row['Field'] ?? $row['field'] ?? $row['name'] ?? ''));
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
