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
function decodeOtaFamilyHotelGradingContent(mixed $value): array
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

/** @return list<string> */
function otaFamilyHotelGradingDimensionLabels(array $content): array
{
    $dimensions = is_array($content['visible_dimensions'] ?? null)
        ? $content['visible_dimensions']
        : [];
    return array_values(array_map(
        static fn(mixed $dimension): string => is_array($dimension)
            ? trim((string)($dimension['label'] ?? ''))
            : '',
        $dimensions
    ));
}

$root = dirname(__DIR__);
$unitName = '携程与美团亲子酒店分级（截图参考）';
$source = 'user_provided_ota_family_hotel_grading_screenshots';
$seedOwner = 'suxios.ota_family_hotel_grading_reference';
$seedVersion = '2026-08-31.1';
$expectedTypes = [
    'ctrip_family_hotel_grading_visible_reference',
    'meituan_family_hotel_grading_visible_reference',
    'family_hotel_grading_cross_platform_boundary',
];
$expectedHashes = [
    '5028E4CC12199787D3F2C5DF40A8E4E6DCF52AB3B94DEE1180603E2CDD52405D',
    '7B19CC9DFBE08F74E8D6CD5885BB2849D09A8EDB9A3E30CAEF4349B2221117BE',
];
$sourceFiles = [
    $root . '/docs/knowledge/ota-family-hotel-grading/sources/ctrip-family-hotel-grading-visible-reference.jpg'
        => $expectedHashes[0],
    $root . '/docs/knowledge/ota-family-hotel-grading/sources/meituan-family-hotel-grading-visible-reference.png'
        => $expectedHashes[1],
];
$errors = [];
$summary = [];

try {
    foreach ($sourceFiles as $path => $hash) {
        if (!is_file($path)) {
            $errors[] = 'source_file_missing:' . basename($path);
            continue;
        }
        $actual = strtoupper((string)hash_file('sha256', $path));
        if ($actual !== $hash) {
            $errors[] = 'source_hash_mismatch:' . basename($path);
        }
    }

    $manifestPath = $root . '/docs/knowledge/ota-family-hotel-grading/source-manifest.json';
    $referencePath = $root . '/docs/knowledge/ota-family-hotel-grading/reference-pack.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string)file_get_contents($manifestPath), true)
        : null;
    $reference = is_file($referencePath)
        ? json_decode((string)file_get_contents($referencePath), true)
        : null;
    if (!is_array($manifest)
        || ($manifest['task_mode'] ?? '') !== 'storage_only'
        || ($manifest['disposition'] ?? '') !== 'absorption_candidate'
        || ($manifest['source_currentness'] ?? '') !== 'not_assumed_current'
    ) {
        $errors[] = 'source_manifest_contract_mismatch';
    }
    if (!is_array($reference)
        || ($reference['usage_policy'] ?? '') !== 'reference_only'
        || ($reference['platform_identity_required'] ?? null) !== true
        || ($reference['grade_conversion_allowed'] ?? null) !== false
    ) {
        $errors[] = 'reference_pack_contract_mismatch';
    }

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
        ? Db::name('knowledge_chunks')
            ->where('unit_id', $unitId)
            ->order('chunk_id', 'asc')
            ->select()
            ->toArray()
        : [];
    $seeded = [];
    $gate = new KnowledgeDecisionGateService();
    foreach ($rows as $row) {
        $content = decodeOtaFamilyHotelGradingContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        $seeded[$type] = $content;

        $assessment = $gate->assess($unit, $content, '2026-08-31 12:00:00');
        if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
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
        if (($content['task_mode'] ?? '') !== 'storage_only'
            || ($content['disposition'] ?? '') !== 'absorption_candidate'
            || ($content['maturity'] ?? '') !== 'understood_visible_structure'
        ) {
            $errors[] = 'disposition_mismatch:' . $type;
        }

        $sourceManifest = is_array($content['source_manifest'] ?? null)
            ? $content['source_manifest']
            : [];
        $documents = is_array($sourceManifest['sources'] ?? null)
            ? $sourceManifest['sources']
            : [];
        $actualHashes = array_values(array_filter(array_map(
            static fn(mixed $document): string => is_array($document)
                ? strtoupper(trim((string)($document['sha256'] ?? '')))
                : '',
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

    $ctrip = $seeded['ctrip_family_hotel_grading_visible_reference'] ?? [];
    $meituan = $seeded['meituan_family_hotel_grading_visible_reference'] ?? [];
    $boundary = $seeded['family_hotel_grading_cross_platform_boundary'] ?? [];
    if (($ctrip['platforms'] ?? null) !== ['ctrip']
        || ($ctrip['visible_levels'] ?? null) !== ['亲子酒店', 'A级', 'A+级']
        || otaFamilyHotelGradingDimensionLabels($ctrip)
            !== ['亲子设施', '亲子活动', '亲子服务', '亲子认可度', '3公里内的景点']
    ) {
        $errors[] = 'ctrip_visible_contract_mismatch';
    }
    if (($meituan['platforms'] ?? null) !== ['meituan']
        || ($meituan['visible_levels'] ?? null) !== ['A级', 'S级']
        || otaFamilyHotelGradingDimensionLabels($meituan)
            !== ['居住体验', '饮食体验', '亲子设施', '亲子活动']
        || ($meituan['service_guarantees_visible_but_not_rating_dimensions'] ?? null)
            !== ['入住保障', '退订保障', '专业客服']
    ) {
        $errors[] = 'meituan_visible_contract_mismatch';
    }
    if (($boundary['platform_identity_required'] ?? null) !== true
        || ($boundary['grade_conversion_allowed'] ?? null) !== false
        || ($boundary['shared_labels_are_not_shared_metrics'] ?? null) !== ['亲子设施', '亲子活动']
    ) {
        $errors[] = 'cross_platform_boundary_mismatch';
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

    $retrievalService = new OperatingQuestionKnowledgeRetrievalService();
    $ctripRetrieval = $retrievalService->retrieve(
        80,
        1,
        'ctrip',
        '携程亲子酒店评级 A+级 亲子认可度 3公里景点'
    );
    $meituanRetrieval = $retrievalService->retrieve(
        80,
        1,
        'meituan',
        '美团亲子酒店分级 S级 居住体验 饮食体验'
    );
    $crossRetrieval = $retrievalService->retrieve(
        80,
        1,
        'all_ota',
        '携程美团亲子酒店A级能否直接换算 亲子设施 亲子活动'
    );

    $ctripMatches = array_values(array_filter(
        (array)($ctripRetrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
    ));
    $meituanMatches = array_values(array_filter(
        (array)($meituanRetrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
    ));
    $crossMatches = array_values(array_filter(
        (array)($crossRetrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['knowledge_type'] ?? '') === 'family_hotel_grading_cross_platform_boundary'
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
    ));
    if ($ctripMatches === []) {
        $errors[] = 'ctrip_reference_retrieval_missing';
    }
    if ($meituanMatches === []) {
        $errors[] = 'meituan_reference_retrieval_missing';
    }
    if ($crossMatches === []) {
        $errors[] = 'cross_platform_reference_retrieval_missing';
    }
    foreach ($ctripMatches as $item) {
        if ((string)($item['knowledge_type'] ?? '') === 'meituan_family_hotel_grading_visible_reference') {
            $errors[] = 'ctrip_retrieval_leaked_meituan_specific_chunk';
        }
    }
    foreach ($meituanMatches as $item) {
        if ((string)($item['knowledge_type'] ?? '') === 'ctrip_family_hotel_grading_visible_reference') {
            $errors[] = 'meituan_retrieval_leaked_ctrip_specific_chunk';
        }
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_readback_count' => count($units),
        'chunk_readback_count' => count($seeded),
        'chunk_types' => array_keys($seeded),
        'source_hashes' => $expectedHashes,
        'knowledge_base_mirror_count' => count($mirrors),
        'ctrip_retrieval_status' => (string)($ctripRetrieval['status'] ?? ''),
        'ctrip_reference_match_count' => count($ctripMatches),
        'meituan_retrieval_status' => (string)($meituanRetrieval['status'] ?? ''),
        'meituan_reference_match_count' => count($meituanMatches),
        'cross_retrieval_status' => (string)($crossRetrieval['status'] ?? ''),
        'cross_reference_match_count' => count($crossMatches),
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
