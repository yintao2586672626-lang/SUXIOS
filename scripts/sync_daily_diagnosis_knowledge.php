#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

function ddJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function ddCheck(bool $ok, string $code): void
{
    if (!$ok) {
        throw new RuntimeException($code);
    }
}
function ddReadback(array $expected, array $content, KnowledgeContentDigestService $digest): array
{
    $rows = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->select()->toArray();
    ddCheck(count($rows) === 1, 'unit_identity_mismatch');
    $unit = $rows[0];
    foreach ($expected as $key => $value) {
        if (in_array($key, ['tags', 'known_knowns', 'known_unknowns'], true)) {
            $actual = is_array($unit[$key]) ? $unit[$key] : json_decode((string)$unit[$key], true, 512, JSON_THROW_ON_ERROR);
            ddCheck($digest->digest($actual) === $digest->digest(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), 'unit_metadata_conflict');
        } else {
            ddCheck((string)($unit[$key] ?? '') === (string)$value, 'unit_metadata_conflict');
        }
    }
    $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
    ddCheck(count($chunks) === 1, 'chunk_count_conflict');
    $chunk = $chunks[0];
    $actual = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true, 512, JSON_THROW_ON_ERROR);
    ddCheck($digest->digest($actual) === $digest->digest($content), 'content_conflict');
    ddCheck($digest->matches((string)$chunk['content_digest'], $actual), 'digest_mismatch');
    ddCheck((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id'], 'current_chunk_mismatch');
    ddCheck($chunk['lifecycle_status'] === 'active' && $chunk['type'] === 'daily_diagnosis_reference' && (int)$chunk['created_by'] === 0, 'chunk_metadata_conflict');
    return [$unit, $chunk];
}

try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    (new App())->initialize();
    $root = dirname(__DIR__);
    $base = $root . '/docs/knowledge/daily-diagnosis-20260930/';
    $options = getopt('', ['persist', 'verify']);
    ddCheck(!(isset($options['persist']) && isset($options['verify'])), 'choose_one_mode');
    $manifest = json_decode((string)file_get_contents($base . 'source-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    ddCheck($manifest['source_sha256'] === '56FB49531A7BF1BBB22E52F3802CB2F12D8E4BD599E36779AFF7BA331FF8F5B6', 'source_identity_mismatch');
    $allowed = ['docs/knowledge/daily-diagnosis-20260930/sources/report.html.txt', '.agents/skills/suxi-ai-report/references/daily-operating-diagnosis.md', 'docs/knowledge/daily-diagnosis-20260930/verification.json', 'scripts/verify_daily_diagnosis_reference.py'];
    ddCheck(array_column($manifest['retained_files'], 'path') === $allowed, 'manifest_paths_changed');
    $refs = [];
    foreach ($manifest['retained_files'] as $file) {
        ddCheck(strtoupper((string)hash_file('sha256', $root . '/' . $file['path'])) === $file['sha256'], 'retained_hash_mismatch');
        $refs[] = 'repo://' . $file['path'] . '#sha256=' . $file['sha256'];
    }
    ddCheck(strtoupper((string)hash_file('sha256', $root . '/' . $allowed[0])) === $manifest['source_sha256'], 'original_hash_mismatch');
    $method = (string)file_get_contents($root . '/' . $allowed[1]);
    $summary = '逐日证据卡、独立验收、在手提前期对齐、价差与真实损失区分；含9月30日缺参照和标签冲突反例。仅方法参考，自动诊断仍为候选。';
    $content = [
        'title' => $manifest['title'], 'summary' => $summary, 'raw_text' => $method,
        'scope' => 'global_hotel_knowledge_method_reference',
        'evidence_level' => 'user_provided_document_method_reference', 'evidence_grade' => 'C',
        'source_refs' => $refs, 'source_section' => 'HTML L156-274; DATA.daily/date; DATA.fwd/date',
        'source_sha256' => $manifest['source_sha256'], 'reviewed_at' => $manifest['reviewed_at'],
        'review_due_at' => $manifest['review_due_at'], 'lifecycle_status' => 'active',
        'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false,
        'contains_current_hotel_fact' => false, 'contains_current_ota_fact' => false,
        'external_write_authorized' => false, 'disposition' => 'store_only',
        'capability_disposition' => 'absorption_candidate', 'capability_maturity' => 'understood',
        'blocked_uses' => ['hotel_fact_ingestion', 'automatic_grading', 'pricing_action', 'operation_task_creation', 'external_message'],
        'seed_owner' => 'suxios.daily_diagnosis_reference', 'seed_key' => '20260930', 'seed_version' => '1.0',
    ];
    $unit = [
        'stable_key' => 'global:daily_diagnosis:20260930:method', 'hotel_id' => 0, 'created_by' => 0,
        'name' => $manifest['title'], 'description' => $summary, 'source' => 'daily_diagnosis_reference',
        'status' => 'done', 'tags' => ddJson(['逐日经营诊断', '在手预订', '收益复盘', 'reference_only', 'global_reference']),
        'lifecycle_status' => 'active', 'lifecycle_reason' => 'user_authorized_reference_ingestion',
        'reviewed_at' => $manifest['reviewed_at'], 'review_due_at' => $manifest['review_due_at'],
        'truth_profile_version' => '1.0',
        'known_knowns' => ddJson(['附件身份与内部算术已复核；方法及失败反例已保留。']),
        'known_unknowns' => ddJson(['原工作簿、酒店平台身份、独立真值、模型生成入口与现场效果未验证。']),
    ];
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $assessment = $gate->assess($unit, $content, $manifest['reviewed_at']);
    ddCheck($assessment['status'] === 'reference_only' && $assessment['retrieval_safe'] && !$assessment['decision_safe'] && !$assessment['task_draft_safe'], 'reference_gate_failed');
    foreach ([['source_refs' => []], ['lifecycle_status' => 'archived'], ['valid_until' => '2026-09-29 23:59:59']] as $invalid) {
        ddCheck(!$gate->assess($unit, array_replace($content, $invalid), $manifest['reviewed_at'])['retrieval_safe'], 'invalid_reference_not_blocked');
    }
    $result = ['status' => 'validated', 'mode' => 'preview', 'source_files_verified' => count($refs), 'gate_cases_passed' => 4];
    if (!isset($options['persist']) && !isset($options['verify'])) {
        echo ddJson($result) . PHP_EOL;
        exit(0);
    }
    $action = 'read_only';
    if (isset($options['persist'])) {
        $lockName = 'suxios.daily_diagnosis_reference.20260930';
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        ddCheck((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $action = Db::transaction(function () use ($unit, $content, $digest): string {
                if (Db::name('knowledge_units')->where('stable_key', $unit['stable_key'])->lock(true)->find()) {
                    ddReadback($unit, $content, $digest);
                    return 'unchanged';
                }
                $now = date('Y-m-d H:i:s');
                $id = (int)Db::name('knowledge_units')->insertGetId($unit + ['created_at' => $now, 'updated_at' => $now]);
                $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                    'unit_id' => $id, 'type' => 'daily_diagnosis_reference', 'content' => ddJson($content),
                    'content_digest' => $digest->digest($content), 'lifecycle_status' => 'active', 'created_by' => 0, 'created_at' => $now,
                ]);
                Db::name('knowledge_units')->where('unit_id', $id)->update(['current_chunk_id' => $chunkId]);
                ddReadback($unit, $content, $digest);
                return 'inserted';
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    [$savedUnit, $savedChunk] = ddReadback($unit, $content, $digest);
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    ddCheck($hotelId > 0, 'retrieval_context_missing');
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $reply = $retrieval->retrieve($hotelId, 0, '', '逐日经营诊断');
    $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === (int)$savedUnit['unit_id']));
    ddCheck(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && $hits[0]['source_refs'] !== [], 'retrieval_missing');
    $private = array_replace($savedUnit, ['hotel_id' => $hotelId, 'created_by' => 987654321]);
    $foreign = $retrieval->buildFromRows([$private], [$savedChunk], ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => '逐日经营诊断']);
    ddCheck(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
    $result = array_replace($result, ['status' => 'success', 'mode' => isset($options['persist']) ? 'persist' : 'verify',
        'action' => $action, 'unit_id' => (int)$savedUnit['unit_id'], 'chunk_id' => (int)$savedChunk['chunk_id'],
        'title' => $unit['name'], 'content_digest' => $savedChunk['content_digest'], 'readback' => 'exact',
        'retrieval' => 'matched', 'cross_hotel_fixture' => 'blocked', 'usage_policy' => 'reference_only',
        'decision_safe' => false, 'task_draft_safe' => false, 'external_write_authorized' => false,
        'verification_scope' => 'local_database_and_existing_retrieval_service_no_llm_call']);
    echo ddJson($result) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, ddJson(['status' => 'failed', 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
