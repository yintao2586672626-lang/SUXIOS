#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

function qiJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function qiCheck(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

function qiReadback(array $expected, array $content, KnowledgeContentDigestService $digest): array
{
    $rows = Db::name('knowledge_units')->where('stable_key', $expected['stable_key'])->select()->toArray();
    qiCheck(count($rows) === 1, 'unit_identity_conflict');
    $unit = $rows[0];
    foreach ($expected as $key => $value) {
        if (in_array($key, ['tags', 'known_knowns', 'known_unknowns'], true)) {
            $actual = is_array($unit[$key]) ? $unit[$key] : json_decode((string)$unit[$key], true, 512, JSON_THROW_ON_ERROR);
            qiCheck($digest->digest($actual) === $digest->digest(json_decode($value, true, 512, JSON_THROW_ON_ERROR)), 'unit_metadata_conflict');
        } else {
            qiCheck((string)($unit[$key] ?? '') === (string)$value, 'unit_metadata_conflict');
        }
    }
    $chunks = Db::name('knowledge_chunks')->where('unit_id', $unit['unit_id'])->select()->toArray();
    qiCheck(count($chunks) === 1, 'chunk_count_conflict');
    $chunk = $chunks[0];
    $actual = is_array($chunk['content']) ? $chunk['content'] : json_decode((string)$chunk['content'], true, 512, JSON_THROW_ON_ERROR);
    qiCheck($digest->digest($actual) === $digest->digest($content), 'content_conflict');
    qiCheck($digest->matches((string)$chunk['content_digest'], $actual), 'saved_digest_conflict');
    qiCheck((int)$unit['current_chunk_id'] === (int)$chunk['chunk_id'], 'current_chunk_conflict');
    qiCheck($chunk['lifecycle_status'] === 'active' && $chunk['type'] === 'investment_calculation_reference' && (int)$chunk['created_by'] === 0, 'chunk_metadata_conflict');
    return [$unit, $chunk];
}

$phase = 'source_preview';
try {
    $root = dirname(__DIR__);
    $base = $root . '/docs/knowledge/qingyuan-investment-20261001/';
    $options = getopt('', ['persist', 'verify']);
    qiCheck(!(isset($options['persist']) && isset($options['verify'])), 'choose_one_mode');
    require $root . '/vendor/autoload.php';
    (new App())->initialize();
    $pack = json_decode((string)file_get_contents($base . 'knowledge-pack.json'), true, 512, JSON_THROW_ON_ERROR);
    qiCheck($pack['source_sha256'] === '49b5af7ae8655bb10a505b01da17da1db995314420ad6a45de7a90cac51f94f7', 'source_identity_changed');
    qiCheck(count($pack['units']) === 8 && $pack['expected_unit_count'] === 8, 'unit_count_changed');
    qiCheck(count($pack['retained_files']) === 10, 'retained_count_changed');
    foreach ($pack['retained_files'] as $file) {
        qiCheck(str_starts_with($file['path'], 'docs/knowledge/qingyuan-investment-20261001/') && !str_contains($file['path'], '..'), 'retained_path_invalid');
        qiCheck(hash_file('sha256', $root . '/' . $file['path']) === $file['sha256'], 'retained_hash_changed');
    }
    qiCheck(hash_file('sha256', $base . 'source.xlsx') === $pack['source_sha256'], 'source_hash_changed');
    $digest = new KnowledgeContentDigestService();
    $gate = new KnowledgeDecisionGateService();
    $entries = [];
    $seen = [];
    foreach ($pack['units'] as $item) {
        qiCheck(str_starts_with($item['stable_key'], 'global:qingyuan_investment:49b5af7ae865:') && !isset($seen[$item['stable_key']]), 'stable_key_conflict');
        $seen[$item['stable_key']] = true;
        $content = $item['content'];
        $unit = [
            'stable_key' => $item['stable_key'], 'hotel_id' => 0, 'created_by' => 0,
            'name' => $item['name'], 'description' => $item['description'], 'source' => 'qingyuan_investment_reference',
            'status' => 'done', 'tags' => qiJson($item['tags']), 'lifecycle_status' => 'active',
            'lifecycle_reason' => 'user_authorized_reference_ingestion',
            'reviewed_at' => $content['reviewed_at'], 'review_due_at' => $content['review_due_at'], 'truth_profile_version' => '1.0',
            'known_knowns' => qiJson(['原工作簿指纹、125条公式重放、确定公式缺陷与方法边界已保留。']),
            'known_unknowns' => qiJson($content['evidence_gaps']),
        ];
        $assessment = $gate->assess($unit, $content, $content['reviewed_at']);
        qiCheck($assessment['status'] === 'reference_only' && $assessment['retrieval_safe'] && !$assessment['decision_safe'] && !$assessment['task_draft_safe'], 'reference_gate_failed');
        qiCheck($content['external_write_authorized'] === false && $content['contains_current_hotel_fact'] === false && $content['source_instructions_active'] === false, 'reference_boundary_failed');
        foreach ([['source_refs' => []], ['lifecycle_status' => 'archived'], ['valid_until' => '2026-09-30 23:59:59']] as $invalid) {
            qiCheck(!$gate->assess($unit, array_replace($content, $invalid), $content['reviewed_at'])['retrieval_safe'], 'invalid_reference_not_blocked');
        }
        $entries[] = ['unit' => $unit, 'content' => $content, 'query' => $item['query']];
    }
    $result = ['status' => 'validated', 'mode' => 'preview', 'unit_count' => count($entries), 'source_files_verified' => count($pack['retained_files']), 'gate_cases_passed' => count($entries) * 4];
    if (!isset($options['persist']) && !isset($options['verify'])) {
        echo qiJson($result) . PHP_EOL;
        exit(0);
    }
    $phase = 'local_database_guard';
    $connection = Db::connect();
    $hostname = strtolower(trim((string)$connection->getConfig('hostname')));
    qiCheck(in_array($hostname, ['127.0.0.1', 'localhost', '::1'], true), 'local_database_required');
    $result['database_scope'] = 'local_loopback';
    $actions = [];
    if (isset($options['persist'])) {
        $phase = 'persist';
        $lockName = 'suxios.qingyuan_investment_reference.49b5af7ae865';
        $lock = Db::query('SELECT GET_LOCK(?, 5) AS acquired', [$lockName]);
        qiCheck((int)($lock[0]['acquired'] ?? 0) === 1, 'import_busy');
        try {
            $actions = Db::transaction(function () use ($entries, $digest): array {
                $changed = [];
                foreach ($entries as $entry) {
                    $unit = $entry['unit'];
                    $content = $entry['content'];
                    if (Db::name('knowledge_units')->where('stable_key', $unit['stable_key'])->lock(true)->find()) {
                        qiReadback($unit, $content, $digest);
                        $changed[$unit['stable_key']] = 'unchanged';
                        continue;
                    }
                    $now = date('Y-m-d H:i:s');
                    $id = (int)Db::name('knowledge_units')->insertGetId($unit + ['created_at' => $now, 'updated_at' => $now]);
                    $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
                        'unit_id' => $id, 'type' => 'investment_calculation_reference', 'content' => qiJson($content),
                        'content_digest' => $digest->digest($content), 'lifecycle_status' => 'active', 'created_by' => 0, 'created_at' => $now,
                    ]);
                    Db::name('knowledge_units')->where('unit_id', $id)->update(['current_chunk_id' => $chunkId]);
                    qiReadback($unit, $content, $digest);
                    $changed[$unit['stable_key']] = 'inserted';
                }
                return $changed;
            });
        } finally {
            Db::query('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
    $phase = 'exact_readback_and_retrieval';
    $hotelId = (int)Db::name('hotels')->order('id', 'asc')->value('id');
    qiCheck($hotelId > 0, 'retrieval_context_missing');
    $retrieval = new OperatingQuestionKnowledgeRetrievalService();
    $saved = [];
    foreach ($entries as $entry) {
        [$unit, $chunk] = qiReadback($entry['unit'], $entry['content'], $digest);
        $reply = $retrieval->retrieve($hotelId, 0, '', $entry['query']);
        $hits = array_values(array_filter($reply['items'] ?? [], static fn(array $hit): bool => (int)$hit['unit_id'] === (int)$unit['unit_id']));
        qiCheck(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && $hits[0]['source_refs'] !== [], 'retrieval_missing');
        $private = array_replace($unit, ['hotel_id' => $hotelId, 'created_by' => 987654321]);
        $foreign = $retrieval->buildFromRows([$private], [$chunk], ['hotel_id' => $hotelId + 1000000, 'user_id' => 987654321, 'question' => $entry['query']]);
        qiCheck(($foreign['items'] ?? []) === [], 'cross_hotel_fixture_leaked');
        $saved[] = ['unit_id' => (int)$unit['unit_id'], 'chunk_id' => (int)$chunk['chunk_id'], 'stable_key' => $unit['stable_key'], 'title' => $unit['name'],
            'action' => $actions[$unit['stable_key']] ?? 'read_only', 'content_digest' => $chunk['content_digest'], 'query' => $entry['query'], 'readback' => 'exact', 'retrieval' => 'matched'];
    }
    $result = array_replace($result, [
        'status' => 'success', 'mode' => isset($options['persist']) ? 'persist' : 'verify', 'units' => $saved,
        'readback' => 'exact', 'retrieval' => 'matched_all_eight_topics', 'cross_hotel_fixture' => 'blocked_all_eight_topics',
        'usage_policy' => 'reference_only', 'decision_safe' => false, 'task_draft_safe' => false, 'external_write_authorized' => false,
        'verification_scope' => 'local_database_and_existing_retrieval_service_no_llm_or_authenticated_ui',
    ]);
    echo qiJson($result) . PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+$/D', $message) === 1 ? $message : 'runtime_validation_failed';
    fwrite(STDERR, qiJson(['status' => 'failed', 'phase' => $phase, 'code' => $safe, 'exception' => get_class($error)]) . PHP_EOL);
    exit(2);
}
