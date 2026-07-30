#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeDecisionGateService;
use app\service\RevenueOperationsKnowledgeService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string, mixed> */
function decodeXyosKernelContent(mixed $value): array
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

/** @return array<int, string> */
function normalizeXyosKernelList(mixed $value): array
{
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $value = $decoded;
        }
    }
    if (!is_array($value)) {
        return [];
    }
    return array_values(array_filter(array_map(
        static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
        $value
    ), static fn(string $item): bool => $item !== ''));
}

$unitName = 'XYOS学习内核吸收与安全演进合同';
$source = RevenueOperationsKnowledgeService::SOURCE;
$seedOwner = 'suxios.xyos_learning_kernel_knowledge';
$seedVersion = '2026-07-31.1';
$archiveSha256 = '3CFAD4FD3168839B404E84157C421818E8551EDE71CEB780C01493824DDB3802';
$expectedTypes = [
    'xyos_source_scope_reference',
    'candidate_knowledge_promotion_contract',
    'knowledge_state_consistency_contract',
    'decision_snapshot_action_gateway_contract',
    'evaluation_autonomy_gate_contract',
    'outcome_learning_contract',
];
$errors = [];
$summary = [
    'unit' => [],
    'chunks' => [],
    'knowledge_base_mirror' => [],
    'retrieval' => [],
];

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
    if ($unitId <= 0) {
        $errors[] = 'unit_missing';
    }
    if ((int)($unit['hotel_id'] ?? -1) !== 0
        || (int)($unit['created_by'] ?? -1) !== 0
        || (string)($unit['status'] ?? '') !== 'done'
        || (string)($unit['lifecycle_status'] ?? '') !== 'active'
        || (string)($unit['truth_profile_version'] ?? '') !== $seedVersion
    ) {
        $errors[] = 'unit_contract_incomplete';
    }
    if (normalizeXyosKernelList($unit['known_knowns'] ?? []) === []) {
        $errors[] = 'unit_known_knowns_missing';
    }
    if (normalizeXyosKernelList($unit['known_unknowns'] ?? []) === []) {
        $errors[] = 'unit_known_unknowns_missing';
    }
    $summary['unit'] = [
        'unit_id' => $unitId,
        'hotel_id' => (int)($unit['hotel_id'] ?? -1),
        'created_by' => (int)($unit['created_by'] ?? -1),
        'status' => (string)($unit['status'] ?? ''),
        'lifecycle_status' => (string)($unit['lifecycle_status'] ?? ''),
        'truth_profile_version' => (string)($unit['truth_profile_version'] ?? ''),
        'reviewed_at' => (string)($unit['reviewed_at'] ?? ''),
        'review_due_at' => (string)($unit['review_due_at'] ?? ''),
        'known_known_count' => count(normalizeXyosKernelList($unit['known_knowns'] ?? [])),
        'known_unknown_count' => count(normalizeXyosKernelList($unit['known_unknowns'] ?? [])),
    ];

    $rows = $unitId > 0
        ? Db::name('knowledge_chunks')
            ->field('chunk_id,unit_id,type,content')
            ->where('unit_id', $unitId)
            ->order('chunk_id', 'asc')
            ->select()
            ->toArray()
        : [];
    $seeded = [];
    $gate = new KnowledgeDecisionGateService();
    foreach ($rows as $row) {
        $content = decodeXyosKernelContent($row['content'] ?? null);
        if ((string)($content['seed_owner'] ?? '') !== $seedOwner
            || (string)($content['seed_version'] ?? '') !== $seedVersion
        ) {
            continue;
        }
        $type = (string)($row['type'] ?? '');
        $seeded[$type][] = $content;
        foreach ([
            'scope',
            'evidence_level',
            'source_refs',
            'content_key',
            'content_type',
            'module_id',
            'roles',
            'scenes',
            'platforms',
            'source_manifest',
            'seed_key',
            'lifecycle_status',
            'blocked_uses',
        ] as $requiredKey) {
            $value = $content[$requiredKey] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $errors[] = 'chunk_required_field_missing:' . $type . ':' . $requiredKey;
            }
        }
        $manifest = is_array($content['source_manifest'] ?? null)
            ? $content['source_manifest']
            : [];
        if ((string)($manifest['archive_sha256'] ?? '') !== $archiveSha256
            || (string)($manifest['execution_status'] ?? '') !== 'static_review_only'
            || ($manifest['source_code_copied'] ?? null) !== false
        ) {
            $errors[] = 'chunk_source_manifest_mismatch:' . $type;
        }
        $blockedUses = normalizeXyosKernelList($content['blocked_uses'] ?? []);
        foreach ([
            'operation_task_creation',
            'operation_execution',
            'automatic_operation_task',
            'automatic_ota_write',
        ] as $blockedUse) {
            if (!in_array($blockedUse, $blockedUses, true)) {
                $errors[] = 'chunk_blocked_use_missing:' . $type . ':' . $blockedUse;
            }
        }
        if (($content['contains_current_hotel_fact'] ?? null) !== false
            || ($content['external_write_authorized'] ?? null) !== false
        ) {
            $errors[] = 'chunk_boundary_invalid:' . $type;
        }
        $decision = $gate->assess($unit, $content, '2026-07-31 12:00:00');
        if (($decision['status'] ?? '') !== 'approved'
            || ($decision['evidence_grade'] ?? '') !== 'B'
            || ($decision['retrieval_safe'] ?? false) !== true
            || ($decision['decision_safe'] ?? false) !== true
            || ($decision['task_draft_safe'] ?? true) !== false
        ) {
            $errors[] = 'chunk_decision_gate_mismatch:' . $type;
        }
    }
    foreach ($expectedTypes as $type) {
        $count = count($seeded[$type] ?? []);
        if ($count !== 1) {
            $errors[] = 'seeded_chunk_count:' . $type . ':' . $count;
        }
    }
    $unexpectedTypes = array_values(array_diff(array_keys($seeded), $expectedTypes));
    if ($unexpectedTypes !== []) {
        $errors[] = 'unexpected_seeded_types:' . implode(',', $unexpectedTypes);
    }
    $summary['chunks'] = [
        'seed_owner' => $seedOwner,
        'seed_version' => $seedVersion,
        'expected_count' => count($expectedTypes),
        'readback_count' => array_sum(array_map('count', $seeded)),
        'types' => array_keys($seeded),
        'archive_sha256' => $archiveSha256,
    ];

    $mirrors = Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', $unitName)
        ->where('is_enabled', 1)
        ->order('id', 'asc')
        ->select()
        ->toArray();
    if (count($mirrors) !== 1) {
        $errors[] = 'knowledge_base_mirror_count:' . count($mirrors);
    }
    $summary['knowledge_base_mirror'] = [
        'count' => count($mirrors),
        'id' => (int)($mirrors[0]['id'] ?? 0),
        'enabled' => (int)($mirrors[0]['is_enabled'] ?? 0),
    ];

    $context = (new RevenueOperationsKnowledgeService())->load([
        'hotel_id' => 0,
        'module_id' => 'xyos_learning_kernel',
        'limit' => 20,
        'as_of' => '2026-07-31 12:00:00',
    ]);
    if ((int)($context['entry_count'] ?? 0) !== count($expectedTypes)
        || (int)($context['decision_safe_entry_count'] ?? 0) !== count($expectedTypes)
        || (int)($context['excluded_decision_gate_count'] ?? 0) !== 0
    ) {
        $errors[] = 'structured_retrieval_mismatch';
    }
    foreach ($context['entries'] ?? [] as $entry) {
        if (($entry['knowledge_gate']['task_draft_safe'] ?? true) !== false) {
            $errors[] = 'structured_retrieval_task_boundary_leak:' . (string)($entry['knowledge_type'] ?? '');
        }
    }
    $summary['retrieval'] = [
        'status' => (string)($context['status'] ?? ''),
        'entry_count' => (int)($context['entry_count'] ?? 0),
        'eligible_entry_count' => (int)($context['eligible_entry_count'] ?? 0),
        'decision_safe_entry_count' => (int)($context['decision_safe_entry_count'] ?? 0),
        'excluded_decision_gate_count' => (int)($context['excluded_decision_gate_count'] ?? 0),
        'data_gap_count' => count($context['data_gaps'] ?? []),
    ];
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
}

$result = [
    'status' => $errors === [] ? 'ok' : 'failed',
    'knowledge_unit' => $unitName,
    'summary' => $summary,
    'errors' => $errors,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
