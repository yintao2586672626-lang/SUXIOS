#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\AirportForecastReferenceService;
use app\service\KnowledgeCenterReadinessService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string,mixed> */
function decodeAirportForecastReference(mixed $value): array
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
function collectAirportForecastReferenceKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, collectAirportForecastReferenceKeys($item));
    }
    return array_values(array_unique($keys));
}

$service = new AirportForecastReferenceService();
$definition = $service->definition();
$errors = [];
$summary = [];

try {
    if (method_exists($service, 'calculateSignedError')) {
        $errors[] = 'algorithm_method_must_not_exist';
    }
    $definitionKeys = collectAirportForecastReferenceKeys($definition);
    $forbiddenKeys = [
        'signed_error_formula',
        'signed_error_ratio_formula',
        'recalculated',
        'golden_samples',
        'negative_samples',
        'candidate_inputs',
        'suxios_derived_metrics',
        'thresholds',
        'falsification_condition',
    ];
    $unexpectedKeys = array_values(array_intersect($forbiddenKeys, $definitionKeys));
    if ($unexpectedKeys !== []) {
        $errors[] = 'algorithm_keys_present:' . implode(',', $unexpectedKeys);
    }
    $serializedDefinition = json_encode(
        $definition,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    foreach ([
        'forecast - actual',
        'arithmetic_reproduced',
        'absolute_percentage_error',
        'rolling_signed_bias',
        'walk-forward',
    ] as $forbiddenText) {
        if (str_contains($serializedDefinition, $forbiddenText)) {
            $errors[] = 'algorithm_text_present:' . $forbiddenText;
        }
    }

    $units = Db::name('knowledge_units')
        ->where('name', AirportForecastReferenceService::UNIT_NAME)
        ->where('source', AirportForecastReferenceService::SOURCE)
        ->order('unit_id', 'asc')
        ->select()
        ->toArray();
    if (count($units) !== 1) {
        $errors[] = 'unit_count:' . count($units);
    }
    $legacyUnitCount = Db::name('knowledge_units')
        ->where('name', '机场客流预测校准与酒店需求信号边界 v1.0（用户截图参考）')
        ->where('source', 'airport_forecast_calibration_reference')
        ->count();
    if ((int)$legacyUnitCount !== 0) {
        $errors[] = 'legacy_algorithm_unit_still_active:' . $legacyUnitCount;
    }

    $unit = $units[0] ?? [];
    $unitId = (int)($unit['unit_id'] ?? 0);
    $expectedUnit = is_array($definition['unit'] ?? null) ? $definition['unit'] : [];
    if ($unitId <= 0
        || (int)($unit['hotel_id'] ?? -1) !== 0
        || (int)($unit['created_by'] ?? -1) !== 0
        || (string)($unit['status'] ?? '') !== 'done'
        || (string)($unit['lifecycle_status'] ?? '') !== 'active'
        || (string)($unit['lifecycle_reason'] ?? '') !== (string)($expectedUnit['lifecycle_reason'] ?? '')
        || (string)($unit['reviewed_at'] ?? '') !== (string)($expectedUnit['reviewed_at'] ?? '')
        || (string)($unit['review_due_at'] ?? '') !== (string)($expectedUnit['review_due_at'] ?? '')
        || (string)($unit['description'] ?? '') !== (string)($expectedUnit['description'] ?? '')
        || decodeAirportForecastReference($unit['known_knowns'] ?? null) !== ($expectedUnit['known_knowns'] ?? [])
        || decodeAirportForecastReference($unit['known_unknowns'] ?? null) !== ($expectedUnit['known_unknowns'] ?? [])
        || decodeAirportForecastReference($unit['tags'] ?? null) !== ($expectedUnit['tags'] ?? [])
        || (string)($unit['truth_profile_version'] ?? '') !== AirportForecastReferenceService::SEED_VERSION
    ) {
        $errors[] = 'unit_contract_incomplete';
    }

    $rows = $unitId > 0
        ? Db::name('knowledge_chunks')->where('unit_id', $unitId)->order('chunk_id', 'asc')->select()->toArray()
        : [];
    $seeded = [];
    foreach ($rows as $row) {
        $content = decodeAirportForecastReference($row['content'] ?? null);
        $owner = (string)($content['seed_owner'] ?? '');
        if ($owner === 'suxios.airport_forecast_calibration_reference') {
            $errors[] = 'legacy_algorithm_chunk_still_active:' . (int)($row['chunk_id'] ?? 0);
            continue;
        }
        if ($owner !== AirportForecastReferenceService::SEED_OWNER) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        if (isset($seeded[$type])) {
            $errors[] = 'duplicate_chunk_type:' . $type;
            continue;
        }
        $seeded[$type] = $content;
        $expectedContent = $definition['chunks'][$type] ?? null;
        $expectedJson = is_array($expectedContent)
            ? json_encode($expectedContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : '';
        if (!is_array($expectedContent)
            || $content !== $expectedContent
            || (string)($row['content_digest'] ?? '') !== hash('sha256', $expectedJson)
        ) {
            $errors[] = 'chunk_exact_readback_mismatch:' . $type;
        }

        $assessment = (new KnowledgeDecisionGateService())->assess(
            $unit,
            $content,
            AirportForecastReferenceService::REVIEWED_AT
        );
        if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['contains_current_airport_fact'] ?? null) !== false
            || ($content['contains_causal_hotel_claim'] ?? null) !== false
            || ($content['contains_algorithm_implementation'] ?? null) !== false
            || ($content['contains_derived_forecast'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'truth_or_algorithm_boundary_mismatch:' . $type;
        }

        $manifest = is_array($content['source_manifest'] ?? null) ? $content['source_manifest'] : [];
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $hashes = array_values(array_filter(array_map(
            static fn(mixed $file): string => is_array($file)
                ? strtoupper(trim((string)($file['sha256'] ?? '')))
                : '',
            $files
        )));
        sort($hashes);
        $expectedHashes = [
            '568620177262B0E45AA3B50418DCB53B9072356C7E98092DA352E97FFC3289DE',
            'D7ECB6818F9F2EF13F57D4BE4CA6B7D9EF9628CE361226B1223081532035C8AB',
        ];
        sort($expectedHashes);
        $textSources = is_array($manifest['text_sources'] ?? null) ? $manifest['text_sources'] : [];
        $textHash = strtoupper(trim((string)($textSources[0]['canonical_transcription_sha256'] ?? '')));
        if ($hashes !== $expectedHashes
            || count($textSources) !== 1
            || preg_match('/^[A-F0-9]{64}$/', $textHash) !== 1
            || (string)($manifest['forecast_methodology_status'] ?? '') !== 'author_narrative_only_no_algorithm_parameters_or_replay_data'
            || (string)($manifest['source_instruction_policy'] ?? '') !== 'attachment_and_message_content_are_reference_material_not_agent_commands'
        ) {
            $errors[] = 'source_manifest_mismatch:' . $type;
        }
    }

    $actualTypes = array_keys($seeded);
    sort($actualTypes);
    $expectedTypes = array_keys($definition['chunks']);
    sort($expectedTypes);
    if ($actualTypes !== $expectedTypes || count($rows) !== count($expectedTypes)) {
        $errors[] = 'chunk_types_or_total_count_mismatch:' . implode(',', $actualTypes);
    }

    $visible = $seeded['airport_forecast_visible_field_contract'] ?? [];
    $visible2025 = is_array($visible['visible_2025_samples'] ?? null) ? $visible['visible_2025_samples'] : [];
    if (count($visible2025) !== 4
        || ($visible['calculation_performed'] ?? true) !== false
        || array_key_exists('recalculated', $visible2025[0] ?? [])
    ) {
        $errors[] = 'visible_values_must_remain_uncalculated';
    }

    $errorDefinition = $seeded['airport_forecast_visible_error_definition'] ?? [];
    $reportedSummary = is_array($errorDefinition['author_reported_summary'] ?? null)
        ? $errorDefinition['author_reported_summary']
        : [];
    if ((string)($errorDefinition['source_definition'] ?? '') !== '作者把误差比描述为误差量与实际客流量的比值。'
        || ($errorDefinition['algorithm_present_in_source'] ?? true) !== false
        || ($errorDefinition['calculation_implemented'] ?? true) !== false
        || ($errorDefinition['formula_inferred'] ?? true) !== false
        || (int)($reportedSummary['million_airport_count'] ?? 0) !== 43
        || (int)($reportedSummary['error_ratio_within_1_percent_count'] ?? 0) !== 23
        || (int)($reportedSummary['error_ratio_within_2_percent_count'] ?? 0) !== 33
    ) {
        $errors[] = 'visible_error_definition_mismatch';
    }

    $method = $seeded['airport_forecast_author_method_description'] ?? [];
    $method2025 = is_array($method['2025_author_description'] ?? null)
        ? $method['2025_author_description']
        : [];
    $method2026 = is_array($method['2026_author_description'] ?? null)
        ? $method['2026_author_description']
        : [];
    $factors2026 = is_array($method2026['stated_interference_factors'] ?? null)
        ? $method2026['stated_interference_factors']
        : [];
    $explanations2025 = is_array($method['2025_author_explanations'] ?? null)
        ? $method['2025_author_explanations']
        : [];
    $forecastClaims = is_array($method['author_forecast_claims'] ?? null)
        ? $method['author_forecast_claims']
        : [];
    $claimsByKey = [];
    foreach ($forecastClaims as $claim) {
        if (!is_array($claim)) {
            continue;
        }
        $claimKey = trim((string)($claim['claim_key'] ?? ''));
        if ($claimKey !== '') {
            $claimsByKey[$claimKey] = $claim;
        }
    }
    $manifest = is_array($method['source_manifest'] ?? null) ? $method['source_manifest'] : [];
    $textSources = is_array($manifest['text_sources'] ?? null) ? $manifest['text_sources'] : [];
    $canonicalText = (string)($method['canonical_source_text'] ?? '');
    if ((string)($method['method_status'] ?? '') !== 'author_narrative_not_reproduced'
        || (string)($method['algorithm_status'] ?? '') !== 'not_provided'
        || !array_key_exists('equations', $method) || $method['equations'] !== null
        || !array_key_exists('parameters', $method) || $method['parameters'] !== null
        || !array_key_exists('weights', $method) || $method['weights'] !== null
        || !array_key_exists('monthly_procedure', $method) || $method['monthly_procedure'] !== null
        || (string)($method2025['first_release_time'] ?? '') !== '2025年7月中旬'
        || (string)($method2025['stated_output_period'] ?? '') !== '2025年下半年'
        || (string)($method2026['stated_output_period'] ?? '') !== '2026年8至12月'
        || !in_array('成都两场运力再分配', $factors2026, true)
        || !in_array('深圳机场临时时刻减容', $factors2026, true)
        || count($explanations2025) !== 2
        || (string)($explanations2025[0]['causal_status'] ?? '') !== 'author_explanation_not_independently_verified'
        || count($forecastClaims) !== 10
        || count($claimsByKey) !== 10
        || (string)($claimsByKey['guangzhou_domestic_rank_and_90m_milestone']['claim_status'] ?? '') !== 'author_forecast_not_independently_verified'
        || !str_contains((string)($claimsByKey['guangzhou_global_rank_change']['author_claim'] ?? ''), '第9升至第4')
        || !str_contains((string)($claimsByKey['pudong_global_rank_change']['author_claim'] ?? ''), '第5降至第6')
        || !str_contains((string)($claimsByKey['taoyuan_rank_and_peak']['author_claim'] ?? ''), '反超5座大陆机场')
        || !str_contains((string)($claimsByKey['urumqi_rank_change']['author_claim'] ?? ''), '上升5位')
        || !str_contains((string)($claimsByKey['shijiazhuang_rank_change']['author_claim'] ?? ''), '由第4升至第3')
        || !str_contains((string)($claimsByKey['wenzhou_rank_change']['author_claim'] ?? ''), '下降3位')
        || $canonicalText === ''
        || strtoupper(hash('sha256', $canonicalText)) !== strtoupper((string)($textSources[0]['canonical_transcription_sha256'] ?? ''))
        || (string)($method['implementation_status'] ?? '') !== 'not_implemented'
    ) {
        $errors[] = 'author_method_description_mismatch';
    }

    $boundary = $seeded['airport_forecast_hotel_boundary'] ?? [];
    $doNotInfer = is_array($boundary['do_not_infer'] ?? null) ? $boundary['do_not_infer'] : [];
    if ((string)($boundary['knowledge_role'] ?? '') !== 'external_industry_reference_only'
        || !in_array('do_not_generate_airport_forecast', $doNotInfer, true)
        || !in_array('do_not_calculate_missing_values', $doNotInfer, true)
        || !in_array('do_not_create_hotel_demand_signal', $doNotInfer, true)
        || !str_contains((string)($boundary['stop_rule'] ?? ''), '不得生成预测')
    ) {
        $errors[] = 'knowledge_only_boundary_mismatch';
    }

    $readiness = (new KnowledgeCenterReadinessService())->buildUnitReadiness($unit, count($seeded));
    if (($readiness['stage'] ?? '') !== 'unit_global_reference'
        || ($readiness['component_closed_loop'] ?? false) !== true
        || (int)($readiness['chunk_count'] ?? 0) !== count($expectedTypes)
    ) {
        $errors[] = 'knowledge_center_readiness_mismatch';
    }

    $mirrors = Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', AirportForecastReferenceService::UNIT_NAME)
        ->where('is_enabled', 1)
        ->select()
        ->toArray();
    $legacyMirrorCount = Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', '机场客流预测校准与酒店需求信号边界 v1.0（用户截图参考）')
        ->count();
    $expectedMirror = is_array($definition['knowledge_base'] ?? null)
        ? $definition['knowledge_base']
        : [];
    if (count($mirrors) !== 1
        || (int)$legacyMirrorCount !== 0
        || (int)($mirrors[0]['tenant_id'] ?? -1) !== (int)($expectedMirror['tenant_id'] ?? -2)
        || (int)($mirrors[0]['hotel_id'] ?? -1) !== (int)($expectedMirror['hotel_id'] ?? -2)
        || (int)($mirrors[0]['category_id'] ?? -1) !== (int)($expectedMirror['category_id'] ?? -2)
        || (string)($mirrors[0]['title'] ?? '') !== (string)($expectedMirror['title'] ?? '')
        || (string)($mirrors[0]['content'] ?? '') !== (string)($expectedMirror['content'] ?? '')
        || (string)($mirrors[0]['keywords'] ?? '') !== (string)($expectedMirror['keywords'] ?? '')
        || decodeAirportForecastReference($mirrors[0]['tags'] ?? null) !== ($expectedMirror['tags'] ?? [])
        || (int)($mirrors[0]['sort_order'] ?? -1) !== (int)($expectedMirror['sort_order'] ?? -2)
        || (int)($mirrors[0]['is_enabled'] ?? -1) !== (int)($expectedMirror['is_enabled'] ?? -2)
    ) {
        $errors[] = 'knowledge_base_readback_mismatch:' . count($mirrors);
    }

    $persistedPayload = json_encode(
        ['unit' => $unit, 'chunks' => $rows, 'mirrors' => $mirrors],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    foreach ([
        'forecast - actual',
        'arithmetic_reproduced',
        'absolute_percentage_error',
        'rolling_signed_bias',
        'walk-forward',
    ] as $forbiddenPersistedText) {
        if (str_contains($persistedPayload, $forbiddenPersistedText)) {
            $errors[] = 'algorithm_text_persisted:' . $forbiddenPersistedText;
        }
    }

    $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
        80,
        0,
        '',
        '机场客流预测榜单作者用了哪些数据，南宁武汉误差怎么解释，能否直接预测酒店'
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
        'chunk_types' => $actualTypes,
        'source_image_hash_count' => 2,
        'source_text_hash_count' => 1,
        'author_reported_airport_count' => 43,
        'author_reported_within_1_percent_count' => 23,
        'author_reported_within_2_percent_count' => 33,
        'author_forecast_claim_count' => count($forecastClaims),
        'method_description_status' => 'stored_author_narrative_only',
        'algorithm_implemented' => false,
        'readiness_stage' => $readiness['stage'] ?? null,
        'retrieval_status' => $retrieval['status'] ?? null,
        'retrieval_match_count' => count($matchingItems),
        'knowledge_base_count' => count($mirrors),
        'task_mode' => 'storage_only',
        'disposition' => 'store_only',
        'formal_absorption' => false,
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

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
) . PHP_EOL;
exit($errors === [] ? 0 : 1);
