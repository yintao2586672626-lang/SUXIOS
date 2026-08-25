#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeCenterReadinessService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string,mixed> */
function decodeJhiraReference(mixed $value): array
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

$unitName = '经营报告双格式交付方法 v1.0（JHIRA仓库参考）';
$source = 'jhira_presentation_reference';
$seedOwner = 'suxios.jhira_presentation_repository_reference';
$seedVersion = '2026-08-23.1';
$expectedTypes = [
    'repo_source_audit',
    'single_spec_method',
    'evidence_delivery_contract',
    'suxios_absorption_boundary',
];
$errors = [];
$summary = [];

try {
    $units = Db::name('knowledge_units')
        ->where('name', $unitName)
        ->where('source', $source)
        ->order('unit_id', 'asc')
        ->select()
        ->toArray();
    if (count($units) !== 1) {
        $errors[] = 'unit_count:' . count($units);
    }
    $unit = $units[0] ?? [];
    $unitId = (int)($unit['unit_id'] ?? 0);
    if ($unitId <= 0
        || (int)($unit['hotel_id'] ?? -1) !== 0
        || (string)($unit['status'] ?? '') !== 'done'
        || (string)($unit['lifecycle_status'] ?? '') !== 'active'
        || (string)($unit['truth_profile_version'] ?? '') !== $seedVersion
    ) {
        $errors[] = 'unit_contract_incomplete';
    }

    $rows = $unitId > 0
        ? Db::name('knowledge_chunks')->where('unit_id', $unitId)->order('chunk_id', 'asc')->select()->toArray()
        : [];
    $seeded = [];
    $gate = new KnowledgeDecisionGateService();
    foreach ($rows as $row) {
        $content = decodeJhiraReference($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        $seeded[$type] = $content;
        $assessment = $gate->assess($unit, $content, '2026-08-23 12:00:00');
        if (($assessment['status'] ?? '') !== 'reference_only'
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'truth_boundary_mismatch:' . $type;
        }
        $manifest = is_array($content['source_manifest'] ?? null) ? $content['source_manifest'] : [];
        if (($manifest['commit'] ?? '') !== '4dc9898c86ef3c4589c903e69ad12f6e398dcf28'
            || ($manifest['repository_tree_sha256'] ?? '') !== '8bfc490509e9fb46a44a81dc0f753355ce3b6c5c9b4e9737e929136431334fdd'
            || ($manifest['direct_reuse'] ?? '') !== 'blocked'
            || ($manifest['package_installed'] ?? null) !== false
            || ($manifest['source_code_copied'] ?? null) !== false
        ) {
            $errors[] = 'source_manifest_mismatch:' . $type;
        }
    }

    $actualTypes = array_keys($seeded);
    sort($actualTypes);
    $sortedExpectedTypes = $expectedTypes;
    sort($sortedExpectedTypes);
    if ($actualTypes !== $sortedExpectedTypes) {
        $errors[] = 'chunk_types_mismatch:' . implode(',', $actualTypes);
    }

    $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness($unit, count($seeded));
    if (($readiness['stage'] ?? '') !== 'unit_global_reference'
        || ($readiness['component_closed_loop'] ?? false) !== true
        || (int)($readiness['chunk_count'] ?? 0) !== count($expectedTypes)
    ) {
        $errors[] = 'knowledge_center_readiness_mismatch';
    }

    $staffRows = Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', $unitName)
        ->where('is_enabled', 1)
        ->select()
        ->toArray();
    if (count($staffRows) !== 1) {
        $errors[] = 'knowledge_base_count:' . count($staffRows);
    }

    $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
        80,
        0,
        '',
        'AI日报演示规格 HTML PPTX 单一规格 精确回读'
    );
    $matches = array_values(array_filter(
        is_array($retrieval['items'] ?? null) ? $retrieval['items'] : [],
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['unit_ref'] ?? '') === 'knowledge_units#' . $unitId
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
            && (string)($item['authority'] ?? '') === 'global_system'
    ));
    if (($retrieval['status'] ?? '') !== 'matched' || $matches === []) {
        $errors[] = 'runtime_retrieval_mismatch';
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_count' => count($units),
        'chunk_count' => count($seeded),
        'chunk_types' => $actualTypes,
        'readiness_stage' => $readiness['stage'] ?? null,
        'knowledge_base_count' => count($staffRows),
        'retrieval_status' => $retrieval['status'] ?? null,
        'retrieval_match_count' => count($matches),
        'source_disposition' => 'reference_only',
        'decision_safe' => false,
        'task_draft_safe' => false,
        'external_write_authorized' => false,
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . get_class($exception) . ':' . $exception->getMessage();
}

$result = [
    'status' => $errors === [] ? 'pass' : 'fail',
    'summary' => $summary,
    'errors' => $errors,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
