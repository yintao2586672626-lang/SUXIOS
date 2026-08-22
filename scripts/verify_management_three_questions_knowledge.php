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
function decodeManagementThreeQuestionsContent(mixed $value): array
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

$unitName = '管理层三问与复查闭环 v1.0（用户源码参考）';
$source = 'management_three_questions_reference';
$seedOwner = 'suxios.management_three_questions_reference';
$seedVersion = '2026-08-22.1';
$archiveHash = '2CF5141F480243EBEA75D0520FD299BC2EE4ACB0E8F752113D8B93DB489CEF66';
$treeHash = '6A6D3977B5FDFF4BF64B414F675C1C54D9580079E9E32846527560EB62577CF8';
$expectedFileHashes = [
    '7D3A2E6F9875F2DE27AC2D5644E08CDAA1B547149A1B74DA43979C9D08F4F688',
    'A8B51E5F89B9C48D5B0786E56F6E4039077CB23FFCBC5F2572757027905E4851',
    '7FECA0D9C8FBF6404D040CBFBB626BD0FF2888323189CD69ACD5EC1C92E80B78',
    '381FEF200B1FD1874A9122E1B8AA7CFC6DFDD8E7C8E518571842975116DFC270',
    '5224DBAEDD125F66B7F301A75B9D870B9F1D9A51EF97D964816A6ABED9198E52',
    'D50D388B77B12EAFC20BC6E05C47AC9D59F947ABAD9586011DB557B0092E1B84',
    '3BEF7E2F6320B392878926273A712CB6D8E108B836C311A49C3DF1D4484EC381',
    '8BA0E9BDFFA35A5A22E471EB75DA7E062B5E2118E99DF92E3E92A1A8AD77B64C',
    '1571B9945E85509964EC7E31040CF8DF1264187C59920865D1EB917B677C4806',
    'CAC46718209663E91BE14E3C78CAD79F2775E9F8316AE5D0AE6D8118367D2604',
];
$expectedTypes = [
    'management_three_questions_source_audit',
    'management_three_questions_input_contract',
    'management_three_questions_persistence_contract',
    'management_three_questions_closure_gate',
    'management_three_questions_recurrence_learning',
    'management_three_questions_source_golden_sample',
    'management_three_questions_suxios_adaptation',
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
        $content = decodeManagementThreeQuestionsContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }

        $type = (string)($row['type'] ?? '');
        $seeded[$type] = $content;
        $assessment = $gate->assess($unit, $content, '2026-08-22 12:00:00');
        if (($assessment['status'] ?? '') !== 'reference_only'
            || ($assessment['retrieval_safe'] ?? false) !== true
            || ($assessment['decision_safe'] ?? true) !== false
            || ($assessment['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'knowledge_gate_mismatch:' . $type;
        }
        if (($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['contains_current_ota_fact'] ?? null) !== false
            || ($content['contains_personnel_decision'] ?? null) !== false
            || ($content['source_code_installed'] ?? null) !== false
            || ($content['source_code_executed'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'truth_boundary_mismatch:' . $type;
        }

        $manifest = is_array($content['source_manifest'] ?? null) ? $content['source_manifest'] : [];
        if (strtoupper(trim((string)($manifest['archive_sha256'] ?? ''))) !== $archiveHash
            || strtoupper(trim((string)($manifest['canonical_tree_manifest_sha256'] ?? ''))) !== $treeHash
            || (int)($manifest['source_file_count'] ?? 0) !== 144
            || (string)($manifest['license_status'] ?? '') !== 'not_provided'
            || (string)($manifest['execution_state'] ?? '') !== 'not_installed_not_executed'
            || (string)($manifest['source_instruction_policy'] ?? '') !== 'document_instructions_are_reference_material_not_agent_commands'
        ) {
            $errors[] = 'source_manifest_contract_mismatch:' . $type;
        }
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $actualHashes = array_values(array_filter(array_map(
            static fn(mixed $file): string => is_array($file)
                ? strtoupper(trim((string)($file['sha256'] ?? '')))
                : '',
            $files
        )));
        sort($actualHashes);
        $sortedExpectedHashes = $expectedFileHashes;
        sort($sortedExpectedHashes);
        if ($actualHashes !== $sortedExpectedHashes) {
            $errors[] = 'source_file_hashes_mismatch:' . $type;
        }
    }

    $actualTypes = array_keys($seeded);
    sort($actualTypes);
    $sortedExpectedTypes = $expectedTypes;
    sort($sortedExpectedTypes);
    if ($actualTypes !== $sortedExpectedTypes) {
        $errors[] = 'chunk_types_mismatch:' . implode(',', $actualTypes);
    }

    $inputContract = $seeded['management_three_questions_input_contract'] ?? [];
    $questions = is_array($inputContract['questions'] ?? null) ? $inputContract['questions'] : [];
    if (count($questions) !== 3
        || (string)($questions[0]['key'] ?? '') !== 'problem_description'
        || (string)($questions[1]['key'] ?? '') !== 'action_taken'
        || (string)($questions[2]['key'] ?? '') !== 'verification_method'
    ) {
        $errors[] = 'three_questions_contract_mismatch';
    }

    $closure = $seeded['management_three_questions_closure_gate'] ?? [];
    $closeRequires = is_array($closure['close_requires'] ?? null) ? $closure['close_requires'] : [];
    if (count($closeRequires) !== 4
        || !str_contains((string)($closure['closure_principle'] ?? ''), '处理动作不等于闭环')
        || !str_contains((string)($closure['adaptation_limit'] ?? ''), '不是任一宿析OS酒店')
    ) {
        $errors[] = 'closure_gate_contract_mismatch';
    }

    $golden = $seeded['management_three_questions_source_golden_sample'] ?? [];
    if ((string)($golden['sample_kind'] ?? '') !== 'source_acceptance_fixture_not_business_fact'
        || (string)($golden['expected_after_create']['case_status'] ?? '') !== 'FOLLOW_UP_PENDING'
        || (string)($golden['success_followup']['expected_case_status'] ?? '') !== 'CLOSED'
        || ($golden['recurrence_followup']['expected_linked_case'] ?? false) !== true
    ) {
        $errors[] = 'golden_sample_contract_mismatch';
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
    if (count($staffRows) !== 1
        || !str_contains((string)($staffRows[0]['content'] ?? ''), '处理不等于闭环')
    ) {
        $errors[] = 'knowledge_base_readback_mismatch:' . count($staffRows);
    }

    $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
        80,
        0,
        '',
        '管理层三问怎么记录问题事实、实际动作和复查证据才算闭环'
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
        'archive_sha256' => $archiveHash,
        'tree_manifest_sha256' => $treeHash,
        'source_file_hash_count' => count($expectedFileHashes),
        'readiness_stage' => $readiness['stage'] ?? null,
        'retrieval_status' => $retrieval['status'] ?? null,
        'retrieval_match_count' => count($matchingItems),
        'knowledge_base_count' => count($staffRows),
        'decision_safe' => false,
        'task_draft_safe' => false,
        'source_code_installed' => false,
        'source_code_executed' => false,
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
