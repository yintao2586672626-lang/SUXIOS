#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string,mixed> */
function decodeHotelSelfServiceContent(mixed $value): array
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

$unitName = '酒店自助服务知识模型 v0.1（历史SOP参考）';
$source = 'hotel_service_operations_reference';
$seedOwner = 'suxios.hotel_self_service_sop_reference';
$seedVersion = '2026-08-16.1';
$expectedTypes = [
    'hotel_self_service_model_index_reference',
    'historical_sop_cross_type_boundary',
    'hotel_self_service_distillation_report_limits',
];
$expectedHashes = [
    'B9EBD8FA76BA67632431914BCE29363AADAD809207B9BD7F8D5F5308834111AF',
    'A15D215083911EE4686FF7D604486AFB26AD2ECED87A06A89FE51435B73CB043',
    '176F54192094541D93F4B0702867800B74CDEE193E365F016C4EE7FBF2088DB0',
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
        || (int)($unit['created_by'] ?? -1) !== 0
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
        $content = decodeHotelSelfServiceContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        $seeded[$type] = $content;
        $assessment = $gate->assess($unit, $content, '2026-08-16 12:00:00');
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
        $documents = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $actualHashes = array_values(array_filter(array_map(
            static fn(mixed $document): string => is_array($document) ? strtoupper(trim((string)($document['sha256'] ?? ''))) : '',
            $documents
        )));
        if (array_values(array_diff($expectedHashes, $actualHashes)) !== []) {
            $errors[] = 'source_manifest_hash_missing:' . $type;
        }
    }
    foreach ($expectedTypes as $type) {
        if (!isset($seeded[$type])) {
            $errors[] = 'seeded_chunk_missing:' . $type;
        }
    }
    if (array_values(array_diff(array_keys($seeded), $expectedTypes)) !== []) {
        $errors[] = 'unexpected_seeded_chunk';
    }

    $mirrors = Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', $unitName)
        ->where('is_enabled', 1)
        ->select()
        ->toArray();
    if (count($mirrors) !== 1) {
        $errors[] = 'knowledge_base_mirror_count:' . count($mirrors);
    }

    $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
        80,
        1,
        '',
        '酒店自助服务知识模型 六环 三层 八维 七闸门 历史SOP跨类型边界'
    );
    $matched = array_values(array_filter(
        (array)($retrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
    ));
    if ($matched === []) {
        $errors[] = 'reference_retrieval_missing';
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_readback_count' => count($units),
        'chunk_readback_count' => count($seeded),
        'chunk_types' => array_keys($seeded),
        'source_hashes' => $expectedHashes,
        'knowledge_base_mirror_count' => count($mirrors),
        'retrieval_status' => (string)($retrieval['status'] ?? ''),
        'reference_match_count' => count($matched),
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
}

echo json_encode([
    'status' => $errors === [] ? 'ok' : 'failed',
    'knowledge_unit' => $unitName,
    'summary' => $summary,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
