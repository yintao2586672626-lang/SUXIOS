#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;
use app\service\RevenueOperationsKnowledgeService;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string, mixed> */
function decodeCtripReformJson(mixed $value): array
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

/** @param array<int, array<string, mixed>> $checks */
function addCtripReformCheck(array &$checks, string $name, bool $passed, mixed $actual = null): void
{
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'actual' => $actual,
    ];
}

$unitName = '携程佣金与流量排序新规观察（2026-08）';
$source = 'revenue_operations_decision_support';
$seedOwner = 'suxios.ctrip_commission_reform_watch';
$checks = [];

$unit = Db::name('knowledge_units')
    ->where('name', $unitName)
    ->where('source', $source)
    ->order('unit_id', 'asc')
    ->find();

addCtripReformCheck($checks, 'knowledge_unit_exists', is_array($unit), $unit['unit_id'] ?? null);
addCtripReformCheck($checks, 'knowledge_unit_active', ($unit['lifecycle_status'] ?? null) === 'active', $unit['lifecycle_status'] ?? null);
addCtripReformCheck($checks, 'truth_profile_version', ($unit['truth_profile_version'] ?? null) === '2026-08-09.1', $unit['truth_profile_version'] ?? null);
addCtripReformCheck(
    $checks,
    'review_due_at',
    str_starts_with((string)($unit['review_due_at'] ?? ''), '2026-08-18'),
    $unit['review_due_at'] ?? null
);

$unitId = (int)($unit['unit_id'] ?? 0);
$chunks = $unitId > 0
    ? Db::name('knowledge_chunks')
        ->where('unit_id', $unitId)
        ->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_owner')) = ?",
            [$seedOwner]
        )
        ->order('chunk_id', 'asc')
        ->select()
        ->toArray()
    : [];

addCtripReformCheck($checks, 'seed_chunk_count', count($chunks) === 5, count($chunks));

$claimStatuses = [];
$sourceCount = 0;
$blockedUseFailures = [];
foreach ($chunks as $chunk) {
    $content = decodeCtripReformJson($chunk['content'] ?? null);
    foreach ((array)($content['claims'] ?? []) as $claim) {
        if (!is_array($claim)) {
            continue;
        }
        $claimId = (string)($claim['claim_id'] ?? '');
        if ($claimId !== '') {
            $claimStatuses[$claimId] = (string)($claim['verification_status'] ?? '');
        }
    }

    $publicSources = $content['source_manifest']['public_sources'] ?? [];
    if (is_array($publicSources)) {
        $sourceCount = max($sourceCount, count($publicSources));
    }

    $blockedUses = $content['blocked_uses'] ?? [];
    if (!is_array($blockedUses)
        || !in_array('automatic_ota_write', $blockedUses, true)
        || !in_array('commission_change', $blockedUses, true)
        || !in_array('ranking_prediction', $blockedUses, true)) {
        $blockedUseFailures[] = (string)($chunk['type'] ?? 'unknown');
    }
}

ksort($claimStatuses);
addCtripReformCheck($checks, 'all_15_claims_read_back', count($claimStatuses) === 15, array_keys($claimStatuses));
addCtripReformCheck(
    $checks,
    'claim_02_exact_mechanics_unverified',
    ($claimStatuses['ctrip_reform_claim_02'] ?? null) === 'confirmed_direction_exact_mechanics_unverified',
    $claimStatuses['ctrip_reform_claim_02'] ?? null
);
addCtripReformCheck(
    $checks,
    'claim_12_official_correction_preserved',
    ($claimStatuses['ctrip_reform_claim_12'] ?? null) === 'officially_corrected_plus_unverified_future_date',
    $claimStatuses['ctrip_reform_claim_12'] ?? null
);
addCtripReformCheck($checks, 'five_public_sources_read_back', $sourceCount === 5, $sourceCount);
addCtripReformCheck($checks, 'write_and_ranking_guards_on_every_chunk', $blockedUseFailures === [], $blockedUseFailures);

$staffRow = Db::name('knowledge_base')
    ->where('hotel_id', 0)
    ->where('title', $unitName)
    ->find();
$staffContent = (string)($staffRow['content'] ?? '');
addCtripReformCheck($checks, 'staff_knowledge_row_exists', is_array($staffRow), $staffRow['id'] ?? null);
addCtripReformCheck(
    $checks,
    'staff_readback_keeps_confirmed_and_unverified_sections',
    str_contains($staffContent, '## 已确认')
        && str_contains($staffContent, '## 待官方确认')
        && str_contains($staffContent, '不调佣、不改补贴、不承诺排名提升'),
    strlen($staffContent)
);

$decisionContext = (new RevenueOperationsKnowledgeService())->load([
    'hotel_id' => 0,
    'module_id' => 'ctrip_commission_reform_watch',
    'limit' => 10,
    'as_of' => '2026-08-09 12:00:00',
]);
addCtripReformCheck(
    $checks,
    'revenue_operations_reader_returns_policy_watch',
    ($decisionContext['status'] ?? null) === 'available'
        && (int)($decisionContext['entry_count'] ?? 0) === 5,
    [
        'status' => $decisionContext['status'] ?? null,
        'entry_count' => $decisionContext['entry_count'] ?? null,
        'eligible_entry_count' => $decisionContext['eligible_entry_count'] ?? null,
        'excluded_decision_gate_count' => $decisionContext['excluded_decision_gate_count'] ?? null,
        'data_gaps' => $decisionContext['data_gaps'] ?? null,
        'knowledge_types' => array_values(array_map(
            static fn(array $entry): string => (string)($entry['knowledge_type'] ?? ''),
            (array)($decisionContext['entries'] ?? [])
        )),
    ]
);

$failed = array_values(array_filter(
    $checks,
    static fn(array $check): bool => ($check['passed'] ?? false) !== true
));

$result = [
    'status' => $failed === [] ? 'pass' : 'fail',
    'unit_id' => $unitId,
    'chunk_count' => count($chunks),
    'claim_count' => count($claimStatuses),
    'checks' => $checks,
];

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit($failed === [] ? 0 : 1);
