<?php
declare(strict_types=1);

// Offline contract/readback verification only. No app bootstrap or database calls.
$root = dirname(__DIR__, 3);
require_once $root . '/app/service/KnowledgeDecisionGateService.php';
require_once $root . '/app/service/SemanticGlossarySyncService.php';
require_once $root . '/app/service/OperatingQuestionKnowledgeRetrievalService.php';
$packPath = __DIR__ . '/knowledge-pack.json';
$before = hash_file('sha256', $packPath);
$pack = json_decode((string)file_get_contents($packPath), true, 512, JSON_THROW_ON_ERROR);
if ($pack['source_ref'] !== 'ac07b6b6a79a6a3a1c489866ec2959d8d1b778a7'
    || $pack['usage_policy'] !== 'reference_only' || $pack['automatic_application_authorized'] !== false
    || $pack['knowledge_center_database_ingested'] !== false || count($pack['entries']) !== 8) {
    throw new RuntimeException('pack_identity_failed');
}
$gate = new \app\service\KnowledgeDecisionGateService();
$retrieval = new \app\service\OperatingQuestionKnowledgeRetrievalService();
$cases = [];
$assert = static function(bool $condition, string $id) use (&$cases): void {
    if (!$condition) throw new RuntimeException($id);
    $cases[] = ['id' => $id, 'status' => 'passed'];
};
foreach ($pack['entries'] as $index => $entry) {
    $id = 900000 + $index; // In-memory fixture IDs only.
    $unit = ['unit_id' => $id, 'hotel_id' => 0, 'created_by' => 0, 'source' => 'geo_repository_reference_fixture',
        'status' => 'done', 'lifecycle_status' => 'active', 'name' => $entry['title'], 'description' => $entry['summary'],
        'reviewed_at' => $pack['reviewed_at'], 'current_chunk_id' => $id];
    $content = $entry['content'];
    $a = $gate->assess($unit, $content, '2026-11-02 12:00:00');
    $assert($a['status'] === 'reference_only' && $a['retrieval_safe'] && !$a['decision_safe'] && !$a['task_draft_safe'], $entry['key'] . ':reference_gate');
    foreach (['source_missing' => ['source_refs' => []], 'archived' => ['lifecycle_status' => 'archived'],
        'expired' => ['valid_until' => '2026-11-01 00:00:00']] as $label => $invalid) {
        $bad = $gate->assess($unit, array_replace($content, $invalid), '2026-11-02 12:00:00');
        $assert($bad['retrieval_safe'] === false, $entry['key'] . ':' . $label);
    }
    $row = ['chunk_id' => $id, 'unit_id' => $id, 'lifecycle_status' => 'active',
        'type' => 'geo_source_reference', 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    $result = $retrieval->buildFromRows([$unit], [$row], ['hotel_id' => 777001, 'user_id' => 777002,
        'platform' => 'ctrip', 'question' => $entry['title']]);
    $hits = array_values(array_filter($result['items'], static fn(array $r): bool => $r['unit_id'] === $id));
    $assert(count($hits) === 1 && $hits[0]['usage_policy'] === 'reference_only' && count($hits[0]['source_refs']) > 0,
        $entry['key'] . ':in_memory_existing_retrieval');
    $foreign = array_replace($unit, ['hotel_id' => 777003, 'created_by' => 777002]);
    $foreignResult = $retrieval->buildFromRows([$foreign], [$row], ['hotel_id' => 777001, 'user_id' => 777002,
        'platform' => 'ctrip', 'question' => $entry['title']]);
    $assert(count($foreignResult['items']) === 0, $entry['key'] . ':foreign_hotel_excluded');
}
$assert(hash_equals($before, (string)hash_file('sha256', $packPath)), 'pack_exact_readback_hash_unchanged');
$receipt = ['status' => 'passed', 'source_ref' => $pack['source_ref'], 'pack_sha256' => $before,
    'entries' => 8, 'passed_cases' => count($cases), 'cases' => $cases,
    'database_written' => false, 'database_readback_verified' => false, 'business_integrated' => false,
    'evidence_ceiling' => 'Current KnowledgeDecisionGateService and OperatingQuestionKnowledgeRetrievalService exercised with in-memory synthetic rows; no database, UI, real hotel, model, or OTA actions'];
$out = $root . '/output/learning/geo-repository-20261102';
file_put_contents($out . '/reference-verification.json', json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
echo json_encode(['status' => 'passed', 'entries' => 8, 'passed_cases' => count($cases), 'database_written' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
