#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

function joydoJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function joydoCheck(bool $ok, string $code): void
{
    if (!$ok) {
        throw new RuntimeException($code);
    }
}
/** Fail on conflicts; do not overwrite existing user edits. */
function joydoReadback(array $definitions, KnowledgeContentDigestService $digest): array
{
    $result = [];
    foreach ($definitions as $definition) {
        $expected = $definition['unit'];
        $units = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->select()->toArray();
        joydoCheck(count($units) === 1, 'unit_identity_conflict');
        $unit = $units[0];
        foreach ($expected as $field => $value) {
            if (in_array($field, ['tags', 'known_knowns', 'known_unknowns'], true)) {
                $actual = is_array($unit[$field]) ? $unit[$field] : json_decode((string)$unit[$field], true, 512, JSON_THROW_ON_ERROR);
                joydoCheck($digest->digest($actual) === $digest->digest(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), 'unit_metadata_conflict:' . $field);
            } else {
                joydoCheck((string)($unit[$field] ?? '') === (string)$value, 'unit_metadata_conflict:' . $field);
            }
        }
        $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
        joydoCheck(count($chunks) === 1, 'chunk_count_conflict');
        $chunk = $chunks[0];
        $content = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true, 512, JSON_THROW_ON_ERROR);
        joydoCheck($digest->digest($content) === $digest->digest($definition['content']), 'content_conflict');
        joydoCheck($digest->matches((string)$chunk['content_digest'], $content), 'digest_conflict');
        joydoCheck((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id'], 'current_chunk_conflict');
        joydoCheck($chunk['type'] === $definition['type'] && $chunk['lifecycle_status'] === 'active' && (int)$chunk['created_by'] === 0, 'chunk_metadata_conflict');
        $result[] = ['key' => $definition['key'], 'unit' => $unit, 'chunk' => $chunk];
    }
    return $result;
}

try {
    $options = getopt('', ['persist', 'verify', 'profile:']);
    joydoCheck(!(isset($options['persist']) && isset($options['verify'])), 'choose_one_mode');
    $root = dirname(__DIR__);
    $profile = $options['profile'] ?? 'overview';
    joydoCheck(is_string($profile) && in_array($profile, ['overview', 'cost', 'other'], true), 'unknown_profile');
    $isCost = $profile === 'cost';
    $isOther = $profile === 'other';
    $relative = $isOther ? 'docs/knowledge/joydo-jhira-other-20261001/' : ($isCost ? 'docs/knowledge/joydo-jhira-cost-20261001/' : 'docs/knowledge/joydo-jhira-v28-20261001/');
    $packVersion = $isOther ? 'joydo-jhira-other-reference-20261001-v1' : ($isCost ? 'joydo-jhira-cost-reference-20261001-v1' : 'joydo-jhira-v28-reference-20261001-v1');
    $allowedKeys = $isOther ? ['foundation_readiness', 'debt_service', 'target_contract', 'ota_comparability', 'report_snapshot', 'geo_evidence']
        : ($isCost ? ['consumption_basis', 'denominators_dedup', 'selection_review'] : ['cashflow_payback', 'ota_visibility', 'report_readiness']);
    $unitSource = $isOther ? 'joydo_jhira_other_modules_reference' : ($isCost ? 'joydo_jhira_hotel_cost_reference' : 'joydo_jhira_website_reference');
    $stablePrefix = $isOther ? 'global:joydo_jhira:other:v28:20261001:' : ($isCost ? 'global:joydo_jhira:cost:v28:20261001:' : 'global:joydo_jhira:v28:20261001:');
    $seedOwner = $isOther ? 'suxios.joydo_jhira_other_reference' : ($isCost ? 'suxios.joydo_jhira_cost_reference' : 'suxios.joydo_jhira_reference');
    $tag = $isOther ? '酒店板块深学' : ($isCost ? '酒店成本深学' : '经营初筛');
    $truthVersion = $isOther ? 'joydo-other-ref-20261001-v1' : ($isCost ? 'joydo-cost-ref-20261001-v1' : 'joydo-v28-ref-20261001-v1');
    $known = $isOther ? '可见基础、融资回本、OTA与报告及GEO公开页面；本地合同回放；源站保存和非零融资缺证，正式功能未吸纳。'
        : ($isCost ? '合作伙伴可见成本页面观察与本地条件回放；耗用基准及切换传播仍缺证；未正式集成。' : '合作伙伴角色可见页面观察；来源与保留证据指纹可追溯；未正式吸纳。');
    $base = $root . '/' . $relative;
    $manifest = json_decode((string)file_get_contents($base . 'source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $pack = json_decode((string)file_get_contents($base . 'knowledge-pack.json'), true, 512, JSON_THROW_ON_ERROR);
    joydoCheck($manifest['source_url'] === 'https://joydo.us.ci/index.full' && $manifest['version_label'] === 'V2.8 / JHIRA-100', 'source_identity_conflict');
    joydoCheck($pack['version'] === $packVersion && array_column($pack['entries'], 'key') === $allowedKeys, 'pack_identity_conflict');
    $allowedFiles = $isOther ? ['sources/observations.md', 'sources/report-readiness.jpg', 'sources/geo-public.jpg', 'sources/replay-fixture.json', 'README.md', 'knowledge-pack.json']
        : ($isCost ? ['sources/observations.md', 'sources/cost-switch-observed.jpg', 'sources/replay-fixture.json', 'README.md', 'knowledge-pack.json']
        : ['sources/observations.md', 'sources/visible-page.jpg', 'README.md', 'knowledge-pack.json']);
    joydoCheck(array_column($manifest['retained_files'], 'file') === $allowedFiles, 'source_paths_conflict');
    $refs = [$manifest['source_url']];
    foreach ($manifest['retained_files'] as $source) {
        joydoCheck(is_file($base . $source['file']) && strtoupper((string)hash_file('sha256', $base . $source['file'])) === $source['sha256'], 'source_hash_conflict');
        $refs[] = 'repo://' . $relative . $source['file'] . '#sha256=' . $source['sha256'];
    }
    $costReplay = null;
    if ($isCost) {
        $replayScript = $manifest['local_replay_script'];
        joydoCheck($replayScript['file'] === 'scripts/verify_joydo_hotel_cost_reference.php'
            && strtoupper((string)hash_file('sha256', $root . '/' . $replayScript['file'])) === $replayScript['sha256'], 'replay_script_hash_conflict');
        $refs[] = 'repo://' . $replayScript['file'] . '#sha256=' . $replayScript['sha256'];
        $supportingRefs = [
            'https://www.ifrs.org/issued-standards/list-of-standards/ias-2-inventories/',
            'https://www.costar.com/products/str-benchmark/resources/data-insights-blog/what-revenue-available-room-revpar-and-how',
        ];
        joydoCheck($manifest['supporting_definition_refs'] === $supportingRefs, 'supporting_refs_conflict');
        $refs = array_merge($refs, $supportingRefs);
        require_once $root . '/' . $replayScript['file'];
        $costReplay = joydoHotelCostReplay($root);
        joydoCheck($costReplay['status'] === 'passed' && !$costReplay['business_integration'] && !$costReplay['source_switch_propagation_verified'], 'cost_replay_scope_conflict');
    }
    $otherReplay = null;
    if ($isOther) {
        $replayScript = $manifest['local_replay_script'];
        joydoCheck($replayScript['file'] === 'scripts/verify_joydo_other_modules_reference.php'
            && strtoupper((string)hash_file('sha256', $root . '/' . $replayScript['file'])) === $replayScript['sha256'], 'replay_script_hash_conflict');
        $refs[] = 'repo://' . $replayScript['file'] . '#sha256=' . $replayScript['sha256'];
        $relatedRefs = ['https://geo.joydorms.com/', 'https://www.spglobal.com/ratings/en/regulatory/article/-/view/sourceId/11884994/revId/1'];
        joydoCheck($manifest['related_and_supporting_refs'] === $relatedRefs && $manifest['geo_access'] === 'public_landing_only', 'supporting_refs_conflict');
        $refs = array_merge($refs, $relatedRefs);
        require_once $root . '/' . $replayScript['file'];
        $otherReplay = joydoOtherModulesReplay($root);
        joydoCheck($otherReplay['status'] === 'passed' && !$otherReplay['business_integration'] && !$otherReplay['source_nonzero_finance_verified']
            && !$otherReplay['source_exports_verified'] && !$otherReplay['geo_backend_verified'], 'other_replay_scope_conflict');
    }
    // Equivalent local arithmetic only: no source inputs, pricing or facts are written.
    $available = 50 * 30;
    $sold = $available * 0.65;
    $contribution = 160 - 27.28;
    $profit = $sold * $contribution - 92000;
    joydoCheck($sold === 975.0 && round($sold * 160, 2) === 156000.0 && round($sold * 27.28, 2) === 26598.0 && round($profit, 2) === 37402.0, 'source_arithmetic_mismatch');
    joydoCheck(round(92000 / ($available * $contribution) * 100, 2) === 46.21 && round(3000000 / ($profit * 12), 2) === 6.68, 'source_arithmetic_mismatch');
    $targetThree = (92000 + 3000000 / 36) / ($available * $contribution);
    $targetOne = (92000 + 3000000 / 12) / ($available * $contribution);
    joydoCheck(round($targetThree * 100, 2) === 88.07 && round($targetOne * 100, 2) === 171.79 && $targetOne > 1, 'source_boundary_mismatch');

    require $root . '/vendor/autoload.php';
    (new App())->initialize();
    $connectionName = (string)config('database.default', 'mysql');
    $connection = (array)config('database.connections.' . $connectionName, []);
    joydoCheck(in_array((string)($connection['hostname'] ?? ''), ['127.0.0.1', 'localhost', '::1'], true) && ($connection['database'] ?? '') === 'hotelx', 'local_database_required');
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $definitions = [];
    foreach ($pack['entries'] as $entry) {
        $key = $entry['key'];
        joydoCheck(in_array($key, $allowedKeys, true) && !isset($definitions[$key]), 'entry_identity_conflict');
        $content = [
            'title' => $entry['title'], 'summary' => $entry['summary'], 'method' => $entry['method'],
            'raw_text' => $entry['title'] . "\n" . $entry['summary'] . "\n" . implode("\n", $entry['method']),
            'scope' => 'global_hotel_knowledge_method_reference', 'evidence_level' => 'user_authorized_website_observation_method_reference', 'evidence_grade' => 'C',
            'source_refs' => $refs, 'source_section' => $entry['source_section'], 'source_version' => $manifest['version_label'],
            'observation_fingerprint_scope' => 'local_paraphrase_and_screenshot_not_upstream_source',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'], 'lifecycle_status' => 'active',
            'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false,
            'contains_current_hotel_fact' => false, 'contains_current_ota_fact' => false, 'external_write_authorized' => false,
            'disposition' => 'store_only', 'capability_disposition' => 'absorption_candidate', 'capability_maturity' => $entry['maturity'],
            'learning_gates' => $entry['gates'], 'known_unknowns' => $entry['known_unknowns'], 'platforms' => [],
            'blocked_uses' => ['hotel_fact_ingestion', 'automatic_grading', 'pricing_action', 'operation_task_creation', 'external_message', 'source_code_copy', 'third_party_training'],
            'seed_owner' => $seedOwner, 'seed_key' => $key, 'seed_version' => $pack['version'],
        ];
        $unit = [
            'stable_key' => $stablePrefix . $key, 'hotel_id' => 0, 'created_by' => 0,
            'name' => $entry['title'], 'source' => $unitSource, 'status' => 'done', 'description' => $entry['summary'],
            'tags' => joydoJson(['JHIRA', '网站学习', $tag, 'reference_only', 'global_reference']),
            'lifecycle_status' => 'active', 'lifecycle_reason' => 'user_authorized_website_reference_ingestion',
            'reviewed_at' => $pack['reviewed_at'], 'review_due_at' => $pack['review_due_at'], 'truth_profile_version' => $truthVersion,
            'known_knowns' => joydoJson([$known]),
            'known_unknowns' => joydoJson($entry['known_unknowns']),
        ];
        $assessed = $gate->assess($unit, $content);
        joydoCheck($assessed['status'] === 'reference_only' && $assessed['retrieval_safe'] && !$assessed['decision_safe'] && !$assessed['task_draft_safe'], 'reference_gate_failed');
        foreach ([['source_refs' => []], ['lifecycle_status' => 'archived'], ['valid_until' => '2026-09-30 23:59:59']] as $invalid) {
            joydoCheck(!$gate->assess($unit, array_replace($content, $invalid))['retrieval_safe'], 'invalid_reference_not_blocked');
        }
        $definitions[$key] = ['key' => $key, 'unit' => $unit, 'content' => $content, 'type' => 'joydo_' . $key . '_reference', 'query' => $entry['query']];
    }
    $baseResult = ['status' => 'validated', 'mode' => 'preview', 'profile' => $profile, 'entries' => count($definitions), 'retained_hashes_verified' => count($allowedFiles) + ($isCost || $isOther ? 1 : 0),
        'source_arithmetic' => 'matched_visible_simplified_sample', 'unreachable_target' => 'matched_171_79_percent',
        'gate_cases_passed' => count($definitions) * 4, 'usage_policy' => 'reference_only', 'capability_disposition' => 'absorption_candidate', 'business_integration' => false];
    if ($isCost) {
        $baseResult['cost_replay'] = $costReplay;
    }
    if ($isOther) {
        $baseResult['other_replay'] = $otherReplay;
    }
    if (!isset($options['persist']) && !isset($options['verify'])) {
        $prior = Db::name('knowledge_units')->where('source', $unitSource)->field('unit_id,stable_key,name')->select()->toArray();
        echo joydoJson($baseResult + ['existing_same_source_units' => $prior]) . PHP_EOL;
        exit(0);
    }
    $actions = [];
    if (isset($options['persist'])) {
        $lockName = 'suxios.joydo_jhira_reference.20261001';
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        joydoCheck((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $actions = Db::transaction(function () use ($definitions, $digest): array {
                $actions = [];
                foreach ($definitions as $definition) {
                    $expected = $definition['unit'];
                    $existing = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->lock(true)->find();
                    if ($existing) {
                        joydoReadback([$definition], $digest);
                        $actions[$definition['key']] = 'unchanged';
                        continue;
                    }
                    $now = date('Y-m-d H:i:s');
                    $unitId = (int)Db::name('knowledge_units')->insertGetId($expected + ['created_at' => $now, 'updated_at' => $now]);
                    $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $unitId, 'type' => $definition['type'], 'content' => joydoJson($definition['content']),
                        'content_digest' => $digest->digest($definition['content']), 'lifecycle_status' => 'active', 'created_by' => 0, 'created_at' => $now,
                    ]);
                    Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
                    $actions[$definition['key']] = 'inserted';
                }
                joydoReadback($definitions, $digest);
                return $actions;
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    $rows = joydoReadback($definitions, $digest);
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    joydoCheck($hotelId > 0, 'retrieval_hotel_context_missing');
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $receipts = [];
    foreach ($rows as $row) {
        $definition = $definitions[$row['key']];
        $reply = $retrieval->retrieve($hotelId, 0, '', $definition['query']);
        $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === (int)$row['unit']['unit_id']));
        joydoCheck(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && $hits[0]['source_refs'] !== [], 'runtime_retrieval_failed');
        $private = array_replace($row['unit'], ['hotel_id' => $hotelId, 'created_by' => 987654321]);
        $foreign = $retrieval->buildFromRows([$private], [$row['chunk']], ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => $definition['query']]);
        joydoCheck(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
        $receipts[] = ['key' => $row['key'], 'unit_id' => (int)$row['unit']['unit_id'], 'chunk_id' => (int)$row['chunk']['chunk_id'],
            'content_digest' => $row['chunk']['content_digest'], 'readback' => 'exact', 'retrieval' => 'matched',
            'decision_safe' => false, 'task_draft_safe' => false, 'external_write_authorized' => false];
    }
    echo joydoJson(array_replace($baseResult, ['status' => 'success', 'mode' => isset($options['persist']) ? 'persist' : 'verify',
        'actions' => $actions, 'readback' => $receipts, 'cross_hotel_cases_passed' => count($definitions),
        'verification_scope' => 'local_reference_database_and_existing_retrieval_service_no_llm_or_ota_call'])) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, joydoJson(['status' => 'failed', 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
