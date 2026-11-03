#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

function jhiraDeepJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function jhiraDeepCheck(bool $ok, string $code): void
{
    if (!$ok) {
        throw new RuntimeException($code);
    }
}
/** Existing edits are conflicts, never silently overwritten by a reference refresh. */
function jhiraDeepReadback(array $definitions, KnowledgeContentDigestService $digest): array
{
    $rows = [];
    foreach ($definitions as $key => $definition) {
        $expected = $definition['unit'];
        $units = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->select()->toArray();
        jhiraDeepCheck(count($units) === 1, 'unit_identity_conflict');
        $unit = $units[0];
        foreach ($expected as $field => $value) {
            if (in_array($field, ['tags', 'known_knowns', 'known_unknowns'], true)) {
                $actual = is_array($unit[$field]) ? $unit[$field] : json_decode((string)$unit[$field], true, 512, JSON_THROW_ON_ERROR);
                jhiraDeepCheck($digest->digest($actual) === $digest->digest(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), 'unit_metadata_conflict_' . $field);
            } else {
                jhiraDeepCheck((string)($unit[$field] ?? '') === (string)$value, 'unit_metadata_conflict_' . $field);
            }
        }
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
        jhiraDeepCheck(count($chunks) === 1, 'chunk_identity_conflict');
        $chunk = $chunks[0];
        $content = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true, 512, JSON_THROW_ON_ERROR);
        jhiraDeepCheck($digest->digest($content) === $digest->digest($definition['content'])
            && $digest->matches((string)$chunk['content_digest'], $content), 'content_digest_conflict');
        jhiraDeepCheck((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id']
            && $chunk['type'] === $definition['type'] && $chunk['lifecycle_status'] === 'active'
            && (int)$chunk['created_by'] === 0, 'current_chunk_conflict');
        $rows[$key] = ['unit' => $unit, 'chunk' => $chunk];
    }
    return $rows;
}

try {
    $options = getopt('', ['persist', 'verify']);
    jhiraDeepCheck(!(isset($options['persist']) && isset($options['verify'])), 'choose_one_mode');
    $root = dirname(__DIR__);
    $relative = 'docs/knowledge/joydo-jhira-field-code-review-20261002/';
    $base = $root . '/' . $relative;
    $manifest = json_decode((string)file_get_contents($base . 'source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    jhiraDeepCheck($manifest['version'] === 'joydo-jhira-field-code-review-20261002-v1'
        && $manifest['usage_policy'] === 'reference_only' && $manifest['automatic_application_authorized'] === false, 'manifest_identity_conflict');
    $refs = ['https://joydo.us.ci/index.full'];
    foreach ($manifest['retained_files'] as $file) {
        jhiraDeepCheck(preg_match('~^(?:sources/[a-z-]+\.json|knowledge-pack\.json)$~D', $file['file']) === 1, 'manifest_path_conflict');
        jhiraDeepCheck(strtoupper((string)hash_file('sha256', $base . $file['file'])) === $file['sha256'], 'source_hash_conflict');
        $refs[] = 'repo://' . $relative . $file['file'] . '#sha256=' . $file['sha256'];
    }
    jhiraDeepCheck(count($manifest['retained_files']) === 8, 'manifest_coverage_conflict');
    $replayPath = 'scripts/verify_joydo_field_code_review.php';
    jhiraDeepCheck($manifest['local_replay_script']['file'] === $replayPath
        && strtoupper((string)hash_file('sha256', $root . '/' . $replayPath)) === $manifest['local_replay_script']['sha256'], 'replay_hash_conflict');
    require_once $root . '/' . $replayPath;
    $replay = joydoFieldCodeReplay();
    $pack = json_decode((string)file_get_contents($base . 'knowledge-pack.json'), true, 512, JSON_THROW_ON_ERROR);
    jhiraDeepCheck($pack['version'] === $manifest['version'] && count($pack['entries']) === 12
        && $pack['usage_policy'] === 'reference_only' && $pack['automatic_application_authorized'] === false, 'pack_identity_conflict');
    // The manifest itself is kept first so bounded retrieval can expose the current source-pack identity.
    array_splice($refs, 1, 0, ['repo://' . $relative . 'source-manifest.json#sha256=' . strtoupper((string)hash_file('sha256', $base . 'source-manifest.json'))]);
    require $root . '/vendor/autoload.php';
    (new App())->initialize();
    $connection = (array)config('database.connections.' . (string)config('database.default', 'mysql'), []);
    jhiraDeepCheck(in_array((string)($connection['hostname'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true)
        && ($connection['database'] ?? '') === 'hotelx', 'local_database_required');
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $definitions = [];
    foreach ($pack['entries'] as $entry) {
        $key = $entry['key'];
        jhiraDeepCheck(preg_match('/^[a-z_]+$/D', $key) === 1 && !isset($definitions[$key]), 'entry_identity_conflict');
        $content = [
            'title' => $entry['title'], 'summary' => $entry['summary'], 'method' => $entry['method'],
            'interpretation_limits' => $entry['known_unknowns'], 'local_disposition' => $entry['local_disposition'],
            'scope' => 'global_hotel_knowledge_method_reference', 'evidence_level' => 'user_authorized_frontend_static_review_reference',
            'evidence_grade' => 'C', 'source_refs' => $refs, 'source_section' => $entry['source_section'],
            'source_functions' => $entry['source_functions'], 'source_version' => $pack['version'],
            'observation_fingerprint_scope' => 'retained_derived_metadata_and_authored_methods_not_entire_website',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'], 'lifecycle_status' => 'active',
            'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false,
            'contains_current_hotel_fact' => false, 'contains_current_ota_fact' => false,
            'external_write_authorized' => false, 'business_integration' => false,
            'capability_disposition' => 'absorption_candidate', 'capability_maturity' => 'static_reference_reviewed',
            'known_unknowns' => $entry['known_unknowns'],
            'blocked_uses' => ['hotel_fact_ingestion', 'automatic_grading', 'pricing_action', 'operation_task_creation',
                'external_message', 'source_code_copy', 'third_party_training', 'automatic_cost_adoption'],
            'seed_owner' => 'joydo_jhira_deep_reference_20261002', 'seed_key' => $key, 'seed_version' => $pack['version'],
        ];
        if ($key === 'field_ui_semantics') {
            $content['source_material_findings'] = [];
            foreach (['field-review.json', 'function-review.json', 'core-observations.json'] as $reviewFile) {
                $review = json_decode((string)file_get_contents($base . 'sources/' . $reviewFile), true, 512, JSON_THROW_ON_ERROR);
                foreach ($review['material_findings'] ?? $review['findings'] ?? [] as $finding) {
                    $content['source_material_findings'][] = ['source_file' => $reviewFile] + $finding;
                }
            }
            jhiraDeepCheck(count($content['source_material_findings']) === 31, 'finding_coverage_conflict');
        }
        $unit = [
            'stable_key' => 'global:joydo_jhira_deep_20261002:' . $key, 'hotel_id' => 0, 'created_by' => 0,
            'name' => $entry['title'], 'source' => 'JHIRA 字段与前端代码深度学习 20261002', 'status' => 'done',
            'description' => $entry['summary'], 'tags' => jhiraDeepJson(['JHIRA', '字段代码学习', 'reference_only', 'global_reference', $key]),
            'lifecycle_status' => 'active', 'lifecycle_reason' => 'user_authorized_website_reference_ingestion',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'], 'truth_profile_version' => 'jhira-deep-20261002-v1',
            'known_knowns' => jhiraDeepJson(['仅为来源前端静态审阅和独立参考反例，不是当前酒店或 OTA 事实']),
            'known_unknowns' => jhiraDeepJson($entry['known_unknowns']),
        ];
        $assessment = $gate->assess($unit, $content);
        jhiraDeepCheck($assessment['status'] === 'reference_only' && $assessment['retrieval_safe']
            && !$assessment['decision_safe'] && !$assessment['task_draft_safe'], 'reference_gate_failed');
        foreach ([['source_refs' => []], ['lifecycle_status' => 'archived'], ['valid_until' => '2026-09-30 23:59:59']] as $invalid) {
            jhiraDeepCheck(!$gate->assess($unit, array_replace($content, $invalid))['retrieval_safe'], 'invalid_reference_not_blocked');
        }
        $definitions[$key] = ['unit' => $unit, 'content' => $content, 'type' => 'joydo_deep_' . $key . '_reference', 'query' => $entry['query']];
    }
    $result = ['status' => 'preview', 'version' => $pack['version'], 'units' => count($definitions),
        'retained_hashes_verified' => count($manifest['retained_files']), 'negative_gate_cases_passed' => count($definitions) * 3,
        'reference_replay' => $replay, 'real_hotel_or_ota_fact_written' => false, 'external_write_authorized' => false];
    if (!isset($options['persist']) && !isset($options['verify'])) {
        echo jhiraDeepJson($result) . PHP_EOL;
        exit(0);
    }
    $actions = [];
    if (isset($options['persist'])) {
        $lockName = 'suxios.joydo_jhira_deep_reference.20261002';
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        jhiraDeepCheck((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $actions = Db::transaction(function () use ($definitions, $digest): array {
                $actions = [];
                foreach ($definitions as $key => $definition) {
                    $existing = Db::name('knowledge_units')->where('stable_key', $definition['unit']['stable_key'])->lock(true)->find();
                    if ($existing) {
                        jhiraDeepReadback([$key => $definition], $digest);
                        $actions[$key] = 'unchanged';
                        continue;
                    }
                    $now = date('Y-m-d H:i:s');
                    $unitId = (int)Db::name('knowledge_units')->insertGetId($definition['unit'] + ['created_at' => $now, 'updated_at' => $now]);
                    $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $unitId, 'type' => $definition['type'], 'content' => jhiraDeepJson($definition['content']),
                        'content_digest' => $digest->digest($definition['content']), 'lifecycle_status' => 'active',
                        'created_by' => 0, 'created_at' => $now,
                    ]);
                    Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
                    $actions[$key] = 'inserted';
                }
                jhiraDeepReadback($definitions, $digest);
                return $actions;
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    $rows = jhiraDeepReadback($definitions, $digest);
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    jhiraDeepCheck($hotelId > 0, 'retrieval_context_missing');
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $receipts = [];
    foreach ($rows as $key => $row) {
        $reply = $retrieval->retrieve($hotelId, 0, '', $definitions[$key]['query']);
        $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === (int)$row['unit']['unit_id']));
        jhiraDeepCheck(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && count($hits[0]['source_refs']) > 0, 'runtime_retrieval_failed');
        $private = array_replace($row['unit'], ['hotel_id' => $hotelId, 'created_by' => 987654321]);
        $foreign = $retrieval->buildFromRows([$private], [$row['chunk']],
            ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => $definitions[$key]['query']]);
        jhiraDeepCheck(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
        $receipts[] = ['key' => $key, 'unit_id' => (int)$row['unit']['unit_id'], 'chunk_id' => (int)$row['chunk']['chunk_id'],
            'content_digest' => $row['chunk']['content_digest'], 'readback' => 'exact', 'retrieval' => 'matched',
            'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false];
    }
    echo jhiraDeepJson(array_replace($result, ['status' => 'success', 'mode' => isset($options['persist']) ? 'persist' : 'verify',
        'actions' => $actions, 'readback' => $receipts, 'cross_hotel_cases_passed' => count($receipts),
        'verification_scope' => 'local_knowledge_database_and_existing_retrieval_service_no_llm_or_ota_call'])) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, jhiraDeepJson(['status' => 'failed', 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
