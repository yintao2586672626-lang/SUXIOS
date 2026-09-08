<?php
declare(strict_types=1);
// Synthetic, pure evaluation. No application initialization or database access.
require dirname(__DIR__) . '/tests/bootstrap.php';
$baselineDir = dirname(__DIR__) . '/output/long-goal/baseline-services';
if (!is_dir($baselineDir)) mkdir($baselineDir, 0777, true);
foreach (['KnowledgeDecisionGateService', 'OperatingQuestionKnowledgeRetrievalService'] as $class) {
    $process = proc_open(['git', 'show', '9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b:app/service/' . $class . '.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) throw new RuntimeException('Cannot open fixed baseline source');
    $source = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !str_starts_with($source, '<?php')) throw new RuntimeException('Fixed baseline source unavailable: ' . $class);
    $source = str_replace(['KnowledgeDecisionGateService', 'OperatingQuestionKnowledgeRetrievalService'], ['L08BaselineKnowledgeDecisionGateService', 'L08BaselineOperatingQuestionKnowledgeRetrievalService'], $source);
    $source = str_replace('$gate->assess($unit, $content)', '$gate->assess($unit, $content, \'2026-09-08 12:00:00\')', $source);
    file_put_contents($baselineDir . '/' . $class . '.php', $source);
}
require $baselineDir . '/KnowledgeDecisionGateService.php';
require $baselineDir . '/OperatingQuestionKnowledgeRetrievalService.php';
$before = new app\service\L08BaselineOperatingQuestionKnowledgeRetrievalService();
$after = new app\service\OperatingQuestionKnowledgeRetrievalService();
$results = []; $oldPass = 0; $newPass = 0;
foreach (Tests\Support\KnowledgeApplicabilityFixture::cases() as $case) {
    $versions = [];
    foreach (['baseline' => $before, 'current' => $after] as $version => $service) {
        $result = $service->buildFromRows($case['units'], $case['chunks'], $case['scope']);
        $ids = array_column($result['items'], 'chunk_id');
        $decisions = array_column(array_filter($result['items'], static fn($item) => $item['decision_safe'] ?? ($item['usage_policy'] === 'decision_support')), 'chunk_id');
        $pass = $ids === $case['expected_ids'] && $decisions === $case['decision_ids'];
        $versions[$version] = ['pass' => $pass, 'citations' => $ids, 'decision_citations' => $decisions];
        if ($pass) { if ($version === 'baseline') $oldPass++; else $newPass++; }
    }
    $results[] = ['id' => $case['id'], 'category' => $case['category'], 'question' => $case['question'], 'expected_citations' => $case['expected_ids'], 'expected_decision_citations' => $case['decision_ids']] + $versions;
}
$report = ['status' => $newPass === count($results) ? 'pass' : 'fail', 'environment' => 'pure_synthetic_no_database_no_llm', 'as_of' => '2026-09-08 12:00:00 Asia/Shanghai', 'baseline_head' => '9fa781fc92baa66b410f4e5d6e8c8c3c1ebae81b', 'metric' => 'exact_expected_citations_and_decision_eligibility', 'question_count' => count($results), 'baseline_pass' => $oldPass, 'current_pass' => $newPass, 'results' => $results, 'limitation' => 'Measures deterministic answer evidence selection, not generated answer quality or hotel operating effect. Baseline clock frozen without changing eligibility/ranking.'];
file_put_contents(dirname(__DIR__) . '/output/long-goal/evaluation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
unset($report['results']); echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($newPass === count($results) ? 0 : 1);
