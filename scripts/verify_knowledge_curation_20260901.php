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
function decodeKnowledgeCurationContent(mixed $value): array
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

$errors = [];
$summary = [];
$gate = new KnowledgeDecisionGateService();

try {
    $reviewedConfigs = [
        42 => [
            'name' => 'OTA每日经营台账与晨报闭环',
            'seed_owner' => 'suxios.ota_daily_operations_ledger_knowledge',
            'expected_count' => 7,
            'query' => 'OTA每日经营台账与晨报闭环',
        ],
        57 => [
            'name' => '酒店门店与房型命名优化知识',
            'seed_owner' => 'suxios.hotel_naming_knowledge',
            'expected_count' => 5,
            'query' => '酒店门店与房型命名优化知识',
        ],
    ];

    foreach ($reviewedConfigs as $unitId => $config) {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        if (!is_array($unit)
            || (string)($unit['name'] ?? '') !== $config['name']
            || (string)($unit['lifecycle_status'] ?? '') !== 'active'
            || (string)($unit['reviewed_at'] ?? '') !== '2026-09-01 00:00:00'
            || (string)($unit['review_due_at'] ?? '') !== '2026-12-01 00:00:00'
        ) {
            $errors[] = 'reviewed_unit_contract_mismatch:' . $unitId;
            continue;
        }
        $rows = Db::name('knowledge_chunks')
            ->where('unit_id', $unitId)
            ->where('lifecycle_status', 'active')
            ->order('chunk_id', 'asc')
            ->select()
            ->toArray();
        if (count($rows) !== $config['expected_count']) {
            $errors[] = 'reviewed_chunk_count:' . $unitId . ':' . count($rows);
        }
        $retrievalSafeCount = 0;
        foreach ($rows as $row) {
            $content = decodeKnowledgeCurationContent($row['content'] ?? null);
            if ((string)($content['seed_owner'] ?? '') !== $config['seed_owner']
                || (string)($content['evidence_grade'] ?? '') !== 'C'
                || (string)($content['curation_review']['status'] ?? '') !== 'reviewed_reference_method_only'
                || ($content['decision_safe'] ?? null) !== false
                || ($content['task_draft_safe'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
            ) {
                $errors[] = 'reviewed_chunk_contract_mismatch:' . $unitId . ':' . ($row['chunk_id'] ?? 0);
            }
            $assessment = $gate->assess($unit, $content, '2026-09-01 12:00:00');
            if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
                || ($assessment['retrieval_safe'] ?? false) !== true
                || ($assessment['decision_safe'] ?? true) !== false
                || ($assessment['task_draft_safe'] ?? true) !== false
            ) {
                $errors[] = 'reviewed_gate_mismatch:' . $unitId . ':' . ($row['chunk_id'] ?? 0);
            } else {
                $retrievalSafeCount++;
            }
            $digest = strtolower(trim((string)($row['content_digest'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                $errors[] = 'reviewed_digest_invalid:' . $unitId . ':' . ($row['chunk_id'] ?? 0);
            }
        }

        $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
            80,
            1,
            'all_ota',
            $config['query']
        );
        $matches = array_values(array_filter(
            (array)($retrieval['items'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && (int)($item['unit_id'] ?? 0) === $unitId
                && (string)($item['usage_policy'] ?? '') === 'reference_only'
        ));
        if ($matches === []) {
            $errors[] = 'reviewed_retrieval_missing:' . $unitId;
        }
        $summary['reviewed'][$unitId] = [
            'chunk_count' => count($rows),
            'retrieval_safe_count' => $retrievalSafeCount,
            'retrieval_match_count' => count($matches),
        ];
    }

    foreach ([
        62 => 'global:user_training:hotel_bd_new_store',
        64 => 'global:user_reference:hotel_manager_interview_distillation',
    ] as $unitId => $stableKey) {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        if (!is_array($unit)
            || (string)($unit['stable_key'] ?? '') !== $stableKey
            || (string)($unit['lifecycle_status'] ?? '') !== 'stale'
            || !str_contains((string)($unit['lifecycle_reason'] ?? ''), 'unavailable_for_exact_reverification_20260901')
        ) {
            $errors[] = 'paused_unit_contract_mismatch:' . $unitId;
            continue;
        }
        $chunkCount = (int)Db::name('knowledge_chunks')->where('unit_id', $unitId)->count();
        $expectedCount = $unitId === 62 ? 6 : 15;
        if ($chunkCount !== $expectedCount) {
            $errors[] = 'paused_unit_chunk_count:' . $unitId . ':' . $chunkCount;
        }
        $retrieval = (new OperatingQuestionKnowledgeRetrievalService())->retrieve(
            80,
            1,
            'all_ota',
            $unitId === 62 ? '酒店BD与新店运营实战' : '酒店店长访谈与资料蒸馏'
        );
        $leaks = array_values(array_filter(
            (array)($retrieval['items'] ?? []),
            static fn(mixed $item): bool => is_array($item)
                && (int)($item['unit_id'] ?? 0) === $unitId
        ));
        if ($leaks !== []) {
            $errors[] = 'paused_unit_retrieval_leak:' . $unitId;
        }
        $summary['paused'][$unitId] = [
            'chunk_count' => $chunkCount,
            'retrieval_leak_count' => count($leaks),
        ];
    }

    $humanOnly = Db::name('knowledge_units')->where('unit_id', 36)->find();
    $humanOnlyRows = Db::name('knowledge_chunks')->where('unit_id', 36)->select()->toArray();
    $humanOnlyMirrorCount = (int)Db::name('knowledge_base')
        ->where('hotel_id', 0)
        ->where('title', '房型经营分析报告解读话术库')
        ->where('is_enabled', 1)
        ->count();
    if (!is_array($humanOnly)
        || (string)($humanOnly['lifecycle_status'] ?? '') !== 'active'
        || count($humanOnlyRows) !== 8
        || $humanOnlyMirrorCount !== 1
    ) {
        $errors[] = 'human_only_unit_contract_mismatch';
    }

    foreach ([59, 60] as $unitId) {
        $unit = Db::name('knowledge_units')->where('unit_id', $unitId)->find();
        if (!is_array($unit)
            || (string)($unit['lifecycle_status'] ?? '') !== 'active'
            || (string)($unit['review_due_at'] ?? '') >= '2026-09-01 00:00:00'
        ) {
            $errors[] = 'ctrip_review_blocker_not_preserved:' . $unitId;
        }
    }

    $publicRows = Db::name('knowledge_units')
        ->whereIn('unit_id', [46, 47, 48, 49])
        ->order('unit_id', 'asc')
        ->select()
        ->toArray();
    if (count($publicRows) !== 4) {
        $errors[] = 'public_monitor_unit_count:' . count($publicRows);
    }
    foreach ($publicRows as $unit) {
        $unitId = (int)($unit['unit_id'] ?? 0);
        $row = Db::name('knowledge_chunks')->where('unit_id', $unitId)->find();
        $content = decodeKnowledgeCurationContent($row['content'] ?? null);
        if ((string)($content['last_attempt_status'] ?? '') !== 'verified'
            || (int)($content['item_count'] ?? 0) <= 0
            || !str_starts_with((string)($content['retrieved_at'] ?? ''), '2026-09-01')
        ) {
            $errors[] = 'public_monitor_refresh_mismatch:' . $unitId;
        }
    }

    $duplicates = Db::query(
        'SELECT `name`, `source`, COUNT(*) AS `row_count` '
        . 'FROM `knowledge_units` WHERE `hotel_id` = 0 AND `created_by` = 0 '
        . 'GROUP BY `name`, `source` HAVING COUNT(*) > 1'
    );
    if ($duplicates !== []) {
        $errors[] = 'duplicate_global_units';
    }
} catch (Throwable $exception) {
    $errors[] = 'exception:' . $exception->getMessage();
}

echo json_encode([
    'status' => $errors === [] ? 'ok' : 'failed',
    'summary' => $summary,
    'errors' => $errors,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errors === [] ? 0 : 1);
