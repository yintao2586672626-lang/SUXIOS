#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\SemanticGlossaryService;
use app\service\SemanticGlossarySyncService;
use think\App;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

$options = getopt('', ['persist']);
$persist = array_key_exists('persist', $options);
$root = dirname(__DIR__);
$fixturePath = $root . '/tests/fixtures/semantic_glossary_acceptance_cases.json';

try {
    $glossary = new SemanticGlossaryService();
    $sync = (new SemanticGlossarySyncService())->sync($persist);
    $cases = json_decode((string)file_get_contents($fixturePath), true);
    if (!is_array($cases) || count($cases) < 50) {
        throw new RuntimeException('semantic_glossary_acceptance_fixture_invalid');
    }
    $failures = [];
    foreach ($cases as $index => $case) {
        if (!is_array($case)) {
            $failures[] = ['index' => $index, 'reason' => 'case_not_array'];
            continue;
        }
        $result = $glossary->resolve((string)($case['query'] ?? ''), (string)($case['platform'] ?? ''));
        $actual = [
            'status' => $result['status'] ?? null,
            'canonical' => is_array($result['primary'] ?? null) ? ($result['primary']['canonical_term'] ?? null) : null,
            'category' => is_array($result['primary'] ?? null) ? ($result['primary']['category'] ?? null) : null,
            'metric_key' => is_array($result['primary'] ?? null) ? ($result['primary']['metric_key'] ?? null) : null,
            'route_key' => is_array($result['primary'] ?? null) ? ($result['primary']['route_key'] ?? null) : null,
            'candidate_count' => count((array)($result['candidates'] ?? [])),
        ];
        foreach (['status', 'canonical', 'category', 'metric_key', 'route_key', 'candidate_count'] as $field) {
            if (array_key_exists($field, $case) && $case[$field] !== $actual[$field]) {
                $failures[] = [
                    'index' => $index,
                    'query' => (string)($case['query'] ?? ''),
                    'field' => $field,
                    'expected' => $case[$field],
                    'actual' => $actual[$field],
                ];
            }
        }
        if (($result['decision_safe'] ?? true) !== false || ($result['external_write_authorized'] ?? true) !== false) {
            $failures[] = ['index' => $index, 'query' => (string)($case['query'] ?? ''), 'reason' => 'unsafe_resolution_boundary'];
        }
    }

    $readbackVerified = !$persist || (($sync['readback']['readback_verified'] ?? false) === true);
    $passed = $failures === []
        && in_array((string)($sync['status'] ?? ''), ['validated', 'success'], true)
        && (int)($sync['source_term_count'] ?? 0) === 2990
        && (int)($sync['export_term_count'] ?? 0) <= 3000
        && (int)($sync['exact_duplicate_count'] ?? -1) === 0
        && (int)($sync['failed_entry_count'] ?? -1) === 0
        && $readbackVerified;
    $result = [
        'status' => $passed ? 'passed' : 'failed',
        'persisted' => $persist,
        'source_term_count' => $sync['source_term_count'] ?? null,
        'recognition_term_count' => $sync['recognition_term_count'] ?? null,
        'concept_count' => $sync['concept_count'] ?? null,
        'export_term_count' => $sync['export_term_count'] ?? null,
        'source_sha256' => $sync['source_sha256'] ?? null,
        'pack_sha256' => $sync['pack_sha256'] ?? null,
        'export_sha256' => $sync['export_sha256'] ?? null,
        'category_counts' => $sync['category_counts'] ?? [],
        'exact_duplicate_count' => $sync['exact_duplicate_count'] ?? null,
        'normalization_collision_count' => $sync['normalization_collision_count'] ?? null,
        'ambiguous_alias_count' => $sync['ambiguous_alias_count'] ?? null,
        'failed_entry_count' => $sync['failed_entry_count'] ?? null,
        'acceptance_case_count' => count($cases),
        'acceptance_passed_count' => count($cases) - count($failures),
        'acceptance_failed_count' => count($failures),
        'failures' => $failures,
        'readback' => $sync['readback'] ?? null,
        'boundary' => $sync['boundary'] ?? [],
    ];
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit($passed ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'reason' => preg_replace('/[^\p{L}\p{N}:._-]+/u', '_', $exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
