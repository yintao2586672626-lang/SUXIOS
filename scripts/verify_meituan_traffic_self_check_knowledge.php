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
function decodeMeituanTrafficSelfCheckContent(mixed $value): array
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

/** @return array<string,array<string,mixed>> */
function indexMeituanTrafficSelfCheckMappings(array $content): array
{
    $rows = is_array($content['metric_mapping_boundary'] ?? null)
        ? $content['metric_mapping_boundary']
        : [];
    $indexed = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = trim((string)($row['visible_label'] ?? ''));
        if ($label !== '') {
            $indexed[$label] = $row;
        }
    }
    return $indexed;
}

$root = dirname(__DIR__);
$unitName = '美团酒店流量自检（截图参考）';
$source = 'user_meituan_traffic_self_check_screenshot';
$stableKey = 'global:meituan_traffic_self_check_reference';
$seedOwner = 'suxios.meituan_traffic_self_check_reference';
$seedVersion = '2026-08-31.1';
$expectedHash = 'A1EB608EA9BB8DF34624C61629E40A602F0C3B6531B3875879128178CE8A2F67';
$expectedTypes = [
    'meituan_traffic_self_check_visible_reference',
    'meituan_traffic_self_check_mechanism_candidate',
    'meituan_traffic_self_check_metric_boundary',
];
$expectedSeedKeys = array_map(
    static fn(string $type): string => 'meituan_traffic_self_check_reference:' . $type,
    $expectedTypes
);
$errors = [];
$summary = [];

try {
    $sourcePath = $root . '/docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png';
    if (!is_file($sourcePath)) {
        $errors[] = 'source_file_missing';
    } elseif (strtoupper((string)hash_file('sha256', $sourcePath)) !== $expectedHash) {
        $errors[] = 'source_hash_mismatch';
    }

    $manifestPath = $root . '/docs/knowledge/meituan-traffic-self-check/source-manifest.json';
    $referencePath = $root . '/docs/knowledge/meituan-traffic-self-check/reference-pack.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string)file_get_contents($manifestPath), true)
        : null;
    $reference = is_file($referencePath)
        ? json_decode((string)file_get_contents($referencePath), true)
        : null;
    if (!is_array($manifest)
        || ($manifest['task_mode'] ?? '') !== 'storage_only'
        || ($manifest['disposition'] ?? '') !== 'absorption_candidate'
        || ($manifest['maturity'] ?? '') !== 'observed'
        || ($manifest['source_currentness'] ?? '') !== 'not_assumed_current'
        || ($manifest['gates']['mechanism'] ?? '') !== 'indeterminate'
        || ($manifest['gates']['value'] ?? '') !== 'pass'
        || ($manifest['gates']['reproduction'] ?? '') !== 'fail'
    ) {
        $errors[] = 'source_manifest_contract_mismatch';
    }
    if (!is_array($reference)
        || ($reference['usage_policy'] ?? '') !== 'reference_only'
        || ($reference['platforms'] ?? null) !== ['meituan']
        || ($reference['contains_current_hotel_fact'] ?? null) !== false
        || ($reference['contains_current_ota_fact'] ?? null) !== false
        || ($reference['external_write_authorized'] ?? null) !== false
    ) {
        $errors[] = 'reference_pack_contract_mismatch';
    }

    $units = Db::name('knowledge_units')
        ->where('stable_key', $stableKey)
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
        || (string)($unit['name'] ?? '') !== $unitName
        || (string)($unit['source'] ?? '') !== $source
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
    $seedKeyCounts = [];
    $seededRawCount = 0;
    $gate = new KnowledgeDecisionGateService();
    foreach ($rows as $row) {
        $content = decodeMeituanTrafficSelfCheckContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }
        $seededRawCount++;
        $type = (string)($row['type'] ?? '');
        $seedKey = (string)($content['seed_key'] ?? '');
        $seedKeyCounts[$seedKey] = ($seedKeyCounts[$seedKey] ?? 0) + 1;
        if (isset($seeded[$type])) {
            $errors[] = 'duplicate_seeded_type:' . $type;
        }
        $seeded[$type] = $content;

        $assessment = $gate->assess($unit, $content, '2026-08-31 12:00:00');
        if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['decision_safe'] ?? null) !== false
            || ($content['task_draft_safe'] ?? null) !== false
            || ($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['contains_confirmed_current_platform_rule'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'truth_or_execution_boundary_mismatch:' . $type;
        }
        if (($content['task_mode'] ?? '') !== 'storage_only'
            || ($content['disposition'] ?? '') !== 'absorption_candidate'
            || ($content['maturity'] ?? '') !== 'observed'
            || ($content['platforms'] ?? null) !== ['meituan']
        ) {
            $errors[] = 'disposition_or_platform_mismatch:' . $type;
        }
        $digest = strtoupper(trim((string)($row['content_digest'] ?? '')));
        if (preg_match('/^[A-F0-9]{64}$/D', $digest) !== 1) {
            $errors[] = 'content_digest_missing_or_invalid:' . $type;
        }

        $sourceManifest = is_array($content['source_manifest'] ?? null)
            ? $content['source_manifest']
            : [];
        $documents = is_array($sourceManifest['sources'] ?? null)
            ? $sourceManifest['sources']
            : [];
        $manifestHashes = array_values(array_filter(array_map(
            static fn(mixed $document): string => is_array($document)
                ? strtoupper(trim((string)($document['sha256'] ?? '')))
                : '',
            $documents
        )));
        if (!in_array($expectedHash, $manifestHashes, true)) {
            $errors[] = 'source_manifest_hash_missing:' . $type;
        }
    }

    if ($seededRawCount !== count($expectedTypes)) {
        $errors[] = 'seeded_raw_row_count:' . $seededRawCount;
    }
    foreach ($expectedTypes as $type) {
        if (!isset($seeded[$type])) {
            $errors[] = 'seeded_chunk_missing:' . $type;
        }
    }
    if (array_values(array_diff(array_keys($seeded), $expectedTypes)) !== []) {
        $errors[] = 'unexpected_seeded_chunk';
    }
    foreach ($expectedSeedKeys as $seedKey) {
        if (($seedKeyCounts[$seedKey] ?? 0) !== 1) {
            $errors[] = 'seed_key_multiplicity:' . $seedKey . ':' . ($seedKeyCounts[$seedKey] ?? 0);
        }
    }
    if (array_values(array_diff(array_keys($seedKeyCounts), $expectedSeedKeys)) !== []) {
        $errors[] = 'unexpected_seed_key';
    }

    $visible = $seeded['meituan_traffic_self_check_visible_reference'] ?? [];
    $visibleContract = is_array($visible['verified_visible'] ?? null)
        ? $visible['verified_visible']
        : [];
    if (($visibleContract['guidance_card_labels'] ?? null)
            !== ['流量排名', '基础曝光', '奖励曝光', '广告曝光']
        || ($visibleContract['self_check_columns'] ?? null)
            !== ['流量类型', '细分指标', '有没有', '我的数据（近七天）', '同行标杆（近七天）', '差距', '运营提升']
        || ($visibleContract['traffic_structure'][0]['items'] ?? null) !== ['基础曝光', '加权曝光']
        || ($visibleContract['traffic_structure'][1]['items'] ?? null) !== ['奖励曝光', '付费曝光']
    ) {
        $errors[] = 'visible_structure_mismatch';
    }

    $mechanism = $seeded['meituan_traffic_self_check_mechanism_candidate'] ?? [];
    if (($mechanism['gates']['mechanism'] ?? '') !== 'indeterminate'
        || ($mechanism['gates']['value'] ?? '') !== 'pass'
        || ($mechanism['gates']['reproduction'] ?? '') !== 'fail'
        || ($mechanism['future_reproduction_contract']['evidence_status'] ?? '')
            !== 'future_golden_sample_contract_not_source_reproduction_evidence'
    ) {
        $errors[] = 'mechanism_gate_mismatch';
    }

    $boundary = $seeded['meituan_traffic_self_check_metric_boundary'] ?? [];
    $mappings = indexMeituanTrafficSelfCheckMappings($boundary);
    if (!array_key_exists('canonical_metric', $mappings['基础曝光'] ?? [])
        || $mappings['基础曝光']['canonical_metric'] !== null
        || ($mappings['基础曝光']['mapping_status'] ?? '') !== 'must_not_be_silently_mapped_to_organic_exposure'
        || ($mappings['广告曝光']['canonical_metric'] ?? '') !== 'ad_exposure'
        || !str_starts_with((string)($mappings['广告曝光']['mapping_status'] ?? ''), 'candidate_only')
        || ($mappings['同行标杆（近七天）']['canonical_metric'] ?? '') !== 'peer_avg_value'
    ) {
        $errors[] = 'metric_mapping_boundary_mismatch';
    }

    $mirrors = Db::name('knowledge_base')
        ->where('tenant_id', 0)
        ->where('hotel_id', 0)
        ->where('title', $unitName)
        ->where('is_enabled', 1)
        ->select()
        ->toArray();
    if (count($mirrors) !== 1) {
        $errors[] = 'knowledge_base_mirror_count:' . count($mirrors);
    }

    $retrievalService = new OperatingQuestionKnowledgeRetrievalService();
    $meituanRetrieval = $retrievalService->retrieve(
        80,
        1,
        'meituan',
        '美团流量自检 基础曝光 加权曝光 奖励曝光 付费曝光 同行标杆近七天'
    );
    $ctripRetrieval = $retrievalService->retrieve(
        80,
        1,
        'ctrip',
        '携程流量自检 基础曝光 加权曝光 奖励曝光 付费曝光 同行标杆近七天'
    );
    $meituanMatches = array_values(array_filter(
        (array)($meituanRetrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
            && (string)($item['usage_policy'] ?? '') === 'reference_only'
            && ($item['platforms'] ?? null) === ['meituan']
    ));
    $ctripLeaks = array_values(array_filter(
        (array)($ctripRetrieval['items'] ?? []),
        static fn(mixed $item): bool => is_array($item)
            && (string)($item['name'] ?? '') === $unitName
    ));
    if ($meituanMatches === []) {
        $errors[] = 'meituan_reference_retrieval_missing';
    }
    if ($ctripLeaks !== []) {
        $errors[] = 'ctrip_retrieval_leaked_meituan_reference';
    }

    $summary = [
        'unit_id' => $unitId,
        'unit_readback_count' => count($units),
        'seeded_raw_row_count' => $seededRawCount,
        'chunk_types' => array_keys($seeded),
        'seed_key_counts' => $seedKeyCounts,
        'source_hash' => $expectedHash,
        'knowledge_base_mirror_count' => count($mirrors),
        'meituan_retrieval_status' => (string)($meituanRetrieval['status'] ?? ''),
        'meituan_reference_match_count' => count($meituanMatches),
        'ctrip_reference_leak_count' => count($ctripLeaks),
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
