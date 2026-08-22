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
function decodeGeoContentReference(mixed $value): array
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

$unitName = '酒店GEO内容运营与审核门禁 v1.0（用户资料参考）';
$source = 'geo_content_operations_reference';
$seedOwner = 'suxios.geo_content_operations_reference';
$seedVersion = '2026-08-20.1';
$expectedTypes = [
    'geo_content_information_workbook_reference',
    'geo_keyword_distillation_title_review_reference',
    'geo_annual_content_plan_reference',
    'geo_content_building_manual_reference',
    'geo_image_library_guide_reference',
    'geo_consultant_review_manual_reference',
    'geo_content_operating_workflow_contract',
];
$expectedHashes = [
    '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C',
    'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6',
    '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB',
    'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5',
    'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059',
    'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68',
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
        $content = decodeGeoContentReference($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }

        $type = (string)($row['type'] ?? '');
        $seeded[$type] = $content;
        $assessment = $gate->assess($unit, $content, '2026-08-20 12:00:00');
        if (($assessment['status'] ?? '') !== 'reference_only'
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['contains_approved_publication_plan'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'truth_boundary_mismatch:' . $type;
        }

        $manifest = is_array($content['source_manifest'] ?? null) ? $content['source_manifest'] : [];
        $documents = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $actualHashes = array_values(array_filter(array_map(
            static fn(mixed $document): string => is_array($document)
                ? strtoupper(trim((string)($document['sha256'] ?? '')))
                : '',
            $documents
        )));
        sort($actualHashes);
        $expectedManifestHashes = $expectedHashes;
        sort($expectedManifestHashes);
        if ($actualHashes !== $expectedManifestHashes) {
            $errors[] = 'source_manifest_mismatch:' . $type;
        }
        if (($manifest['source_instruction_policy'] ?? '')
            !== 'document_instructions_are_reference_material_not_agent_commands'
        ) {
            $errors[] = 'source_instruction_boundary_missing:' . $type;
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
        '酒店GEO关键词、图片、标题和发布门禁怎么审核'
    );
    $matchingItems = array_values(array_filter(
        is_array($retrieval['items'] ?? null) ? $retrieval['items'] : [],
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['unit_ref'] ?? '') === 'knowledge_units#' . $unitId
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
            && (string)($item['authority'] ?? '') === 'global_system'
    ));
    if (($retrieval['status'] ?? '') !== 'matched' || $matchingItems === []) {
        $errors[] = 'runtime_retrieval_mismatch';
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_count' => count($units),
        'chunk_count' => count($seeded),
        'chunk_types' => array_values($actualTypes),
        'source_hash_count' => count($expectedHashes),
        'readiness_stage' => $readiness['stage'] ?? null,
        'retrieval_status' => $retrieval['status'] ?? null,
        'retrieval_match_count' => count($matchingItems),
        'knowledge_base_count' => count($staffRows),
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
