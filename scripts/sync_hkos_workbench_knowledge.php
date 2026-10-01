#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

function hkosJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function hkosAssert(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function hkosReadJson(string $path): array
{
    $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    hkosAssert(is_array($value), 'invalid_json_object');
    return $value;
}

/** Existing rows must be identical; this importer never overwrites user edits. */
function hkosReadback(array $definitions, KnowledgeContentDigestService $digest): array
{
    $rows = [];
    foreach ($definitions as $definition) {
        $expectedUnit = $definition['unit'];
        $matches = Db::name('knowledge_units')->where('stable_key', $expectedUnit['stable_key'])->select()->toArray();
        hkosAssert(count($matches) === 1, 'unit_identity_mismatch:' . $definition['key']);
        $unit = $matches[0];
        foreach ($expectedUnit as $field => $expected) {
            $actual = $unit[$field] ?? null;
            if (in_array($field, ['tags', 'known_knowns', 'known_unknowns'], true)) {
                $actual = is_array($actual) ? $actual : json_decode((string)$actual, true);
                $expected = json_decode((string)$expected, true);
                hkosAssert($digest->digest($actual) === $digest->digest($expected), 'unit_json_changed:' . $field);
            } else {
                hkosAssert((string)$actual === (string)$expected, 'unit_changed:' . $definition['key'] . ':' . $field);
            }
        }
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
        hkosAssert(count($chunks) === 1, 'chunk_count_changed:' . $definition['key']);
        $chunk = $chunks[0];
        $content = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true);
        hkosAssert($digest->digest($content) === $digest->digest($definition['content']), 'content_changed:' . $definition['key']);
        hkosAssert($digest->matches((string)$chunk['content_digest'], $content), 'content_digest_mismatch');
        hkosAssert((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id'], 'current_chunk_mismatch');
        hkosAssert((int)$chunk['created_by'] === 0 && $chunk['lifecycle_status'] === 'active'
            && $chunk['type'] === $definition['type'], 'chunk_metadata_mismatch');
        $rows[] = ['key' => $definition['key'], 'unit' => $unit, 'chunk' => $chunk];
    }
    return $rows;
}

try {
    $options = getopt('', ['persist', 'verify', 'package-version:']);
    hkosAssert(!(isset($options['persist']) && isset($options['verify'])), 'choose_persist_or_verify');
    $version = (string)($options['package-version'] ?? '1.3.56');
    hkosAssert(in_array($version, ['1.3.56', '1.3.66'], true), 'unsupported_package_version');
    $isUpdate = $version === '1.3.66';
    $relativeBase = 'docs/knowledge/hkos-workbench' . ($isUpdate ? '-v1.3.66' : '') . '/';
    $base = dirname(__DIR__) . '/' . $relativeBase;
    $manifest = hkosReadJson($base . 'source-manifest.json');
    $pack = hkosReadJson($base . 'knowledge-pack.json');
    hkosAssert($manifest['package_version'] === $version, 'package_version_mismatch');
    hkosAssert($isUpdate
        ? $manifest['archive_sha256'] === 'D73EBA08A59021ED1CB0479273C1AE1635C70306471B545F9538F4959BDA0DA2'
        : ($manifest['archive_sha256'] === '02EBA16E643BDB37A88450D4354574C69846516F532A006AE45C371D429FE34D'
            && $manifest['guide_sha256'] === '000D43A10E38A3FADED7A79FBD4C54054B0FAC1698583D0388C95A3A3E343BB1'), 'attachment_identity_mismatch');
    $sources = [];
    foreach ($manifest['retained_sources'] as $source) {
        $file = (string)$source['file'];
        hkosAssert(preg_match('~^sources/[a-zA-Z0-9._-]+$~D', $file) === 1, 'unsafe_source_path');
        hkosAssert(is_file($base . $file) && strtoupper((string)hash_file('sha256', $base . $file)) === $source['sha256'], 'source_hash_mismatch:' . $file);
        $sources[$file] = $source;
    }
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $definitions = [];
    foreach ($pack['entries'] as $entry) {
        $key = (string)$entry['key'];
        hkosAssert(preg_match('/^[a-z_]+$/D', $key) === 1 && !isset($definitions[$key]), 'invalid_or_duplicate_entry');
        hkosAssert(isset($sources[$entry['source_file']]), 'entry_source_missing');
        $content = [
            'title' => $entry['title'], 'summary' => $entry['summary'],
            'usage_notice' => '外部方法参考；不是本店正式制度、当前经营事实或执行授权。',
            'method' => $entry['steps'] ?? $entry['template'] ?? $entry['catalog'],
            'provenance' => $entry['provenance'], 'boundary' => $entry['boundary'],
            'scope' => 'global_hotel_knowledge_method_reference',
            'evidence_level' => 'user_provided_document_method_reference', 'evidence_grade' => 'C',
            'source_refs' => ['repo://' . $relativeBase . $entry['source_file']
                . '#sha256=' . $sources[$entry['source_file']]['sha256']],
            'source_section' => $entry['source_section'],
            'package_version' => $manifest['package_version'], 'guide_version' => $manifest['guide_version'] ?? 'bundled_document_version_not_verified',
            'source_archive_sha256' => $manifest['archive_sha256'],
            'source_guide_sha256' => $manifest['guide_sha256'] ?? $sources['sources/customer-guide.txt']['sha256'],
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'],
            'review_note' => $pack['review_note'], 'platforms' => [], 'lifecycle_status' => 'active',
            'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false,
            'contains_current_hotel_fact' => false, 'contains_current_ota_fact' => false,
            'external_write_authorized' => false,
            'blocked_uses' => ['current_hotel_fact', 'current_ota_fact', 'operation_task_creation', 'operation_execution', 'automatic_ota_write', 'external_message'],
            'seed_owner' => 'suxios.hkos_workbench_reference', 'seed_key' => $key, 'seed_version' => $pack['version'],
        ];
        if ($isUpdate) {
            foreach ($entry['source_evidence'] as $locator) {
                $sourceFile = explode(':', $locator, 2)[0];
                hkosAssert(isset($sources[$sourceFile]), 'supporting_source_missing');
                $content['source_refs'][] = 'repo://' . $relativeBase . $sourceFile
                    . '#sha256=' . $sources[$sourceFile]['sha256'];
            }
            $content['source_refs'] = array_values(array_unique($content['source_refs']));
            $content['raw_text'] = $entry['title'] . "\n" . $entry['summary'] . "\n"
                . implode("\n", $entry['steps']) . "\n使用边界：" . $entry['boundary'];
            $content['source_evidence'] = $entry['source_evidence'];
            $content['disposition'] = 'store_only';
            $content['capability_disposition'] = 'absorption_candidate';
        }
        $unit = [
            'stable_key' => 'global:hkos_workbench:' . ($isUpdate ? 'v1366:' : '') . $key, 'hotel_id' => 0, 'created_by' => 0,
            'name' => $entry['title'], 'source' => 'hkos_workbench_reference', 'status' => 'done',
            'description' => $entry['summary'] . ' 仅供参考，不属于本店正式制度。',
            'tags' => hkosJson(['HKOS', '知识管理', 'SOP', 'reference_only', 'global_reference']),
            'lifecycle_status' => 'active', 'lifecycle_reason' => 'user_authorized_hkos_reference_ingestion',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'],
            'truth_profile_version' => $pack['version'],
            'known_knowns' => hkosJson([$entry['provenance']]),
            'known_unknowns' => hkosJson(['未取得本店正式制度或现场效果；包与指南版本不同。']),
        ];
        $assessment = $gate->assess($unit, $content, $pack['reviewed_at']);
        hkosAssert($assessment['status'] === 'reference_only' && $assessment['retrieval_safe']
            && !$assessment['decision_safe'] && !$assessment['task_draft_safe'], 'unsafe_reference_gate');
        foreach ([['valid_until' => '2026-09-25 23:59:59'], ['lifecycle_status' => 'archived'], ['source_refs' => []]] as $invalid) {
            hkosAssert(!$gate->assess($unit, array_replace($content, $invalid), $pack['reviewed_at'])['retrieval_safe'], 'invalid_source_not_blocked');
        }
        $definitions[$key] = ['key' => $key, 'unit' => $unit, 'content' => $content,
            'type' => 'hkos_' . $key . '_reference', 'query' => $entry['query']];
    }
    hkosAssert(count($definitions) === ($isUpdate ? 2 : 6), 'unexpected_knowledge_entry_count');
    $result = ['status' => 'validated', 'mode' => 'preview', 'entries' => count($definitions),
        'source_files_verified' => count($sources), 'gate_cases_passed' => count($definitions) * 4,
        'usage_policy' => 'reference_only', 'formal_hotel_sop_created' => false];
    if (!array_key_exists('persist', $options) && !array_key_exists('verify', $options)) {
        echo hkosJson($result) . PHP_EOL;
        exit(0);
    }
    $actions = [];
    if (array_key_exists('persist', $options)) {
        $lockName = 'suxios.hkos_workbench_reference.20260926';
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        hkosAssert((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $actions = Db::transaction(function () use ($definitions, $digest): array {
                $actions = [];
                foreach ($definitions as $definition) {
                    $unitData = $definition['unit'];
                    $existing = Db::name('knowledge_units')->where('stable_key', $unitData['stable_key'])->lock(true)->find();
                    if ($existing) {
                        hkosReadback([$definition], $digest);
                        $actions[$definition['key']] = 'unchanged';
                        continue;
                    }
                    $now = date('Y-m-d H:i:s');
                    $unitId = (int)Db::name('knowledge_units')->insertGetId($unitData + ['created_at' => $now, 'updated_at' => $now]);
                    $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $unitId, 'type' => $definition['type'], 'content' => hkosJson($definition['content']),
                        'content_digest' => $digest->digest($definition['content']), 'lifecycle_status' => 'active',
                        'created_by' => 0, 'created_at' => $now,
                    ]);
                    Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
                    $actions[$definition['key']] = 'inserted';
                }
                hkosReadback($definitions, $digest);
                return $actions;
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    $rows = hkosReadback($definitions, $digest);
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    hkosAssert($hotelId > 0, 'hotel_context_missing_for_retrieval');
    $receipts = [];
    foreach ($rows as $row) {
        $definition = $definitions[$row['key']];
        $unitId = (int)$row['unit']['unit_id'];
        $reply = $retrieval->retrieve($hotelId, 0, '', $definition['query']);
        $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === $unitId));
        hkosAssert(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && $hits[0]['source_refs'] !== [], 'runtime_retrieval_missing:' . $row['key']);
        $private = array_replace($row['unit'], ['hotel_id' => $hotelId, 'created_by' => 987654321]);
        $foreign = $retrieval->buildFromRows([$private], [$row['chunk']], ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => $definition['query']]);
        hkosAssert(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
        $receipts[] = ['key' => $row['key'], 'title' => $definition['unit']['name'], 'unit_id' => $unitId,
            'chunk_id' => (int)$row['chunk']['chunk_id'], 'content_digest' => $row['chunk']['content_digest'],
            'readback' => 'exact', 'retrieval' => 'matched', 'usage_policy' => 'reference_only'];
    }
    echo hkosJson(array_replace($result, ['status' => 'success',
        'mode' => array_key_exists('persist', $options) ? 'persist' : 'verify',
        'actions' => $actions, 'readback' => $receipts, 'cross_hotel_cases_passed' => count($rows),
        'verification_scope' => 'local_database_and_existing_retrieval_service_no_llm_call'])) . PHP_EOL;
} catch (Throwable $error) {
    // Do not print SQL, connection details or raw exception messages.
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+(?::[a-z_]+)*$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, hkosJson(['status' => 'failed', 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
