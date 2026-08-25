#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\KnowledgeCenterReadinessService;
use app\service\KnowledgeChunkGateSummaryService;
use app\service\KnowledgeDecisionGateService;
use app\service\OperatingQuestionKnowledgeRetrievalService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string,mixed> */
function decodeReferenceContent(mixed $value): array
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

/** @param array<int,string> $values */
function normalizedStrings(array $values): array
{
    $values = array_values(array_filter(array_map(
        static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
        $values
    ), static fn(string $value): bool => $value !== ''));
    sort($values);
    return $values;
}

$configs = [
    'geo' => [
        'unit_name' => '酒店GEO内容资产与发布审核工作流 v1.0（用户资料参考）',
        'source' => 'hotel_geo_operations_reference',
        'seed_owner' => 'suxios.hotel_geo_operations_reference',
        'seed_version' => '2026-08-20.2',
        'audit_type' => 'geo_source_audit_reference',
        'expected_types' => [
            'geo_source_audit_reference',
            'geo_stage_gate_workflow_contract',
            'geo_property_information_contract',
            'geo_image_evidence_contract',
            'geo_keyword_content_review_contract',
            'geo_publication_approval_contract',
            'geo_visibility_monitoring_contract',
            'geo_synthetic_example_reference',
        ],
        'expected_hashes' => [
            'F94BD6B830A4D217FDFE21EDEE27699D3964F3C16680FCB9E9F29A52D91B8871',
            'DB7C12AF5260296B788EE9EF07F9EB2F51E249B354F66666B8FE79976A7A4E68',
            '6815D28084DBF2784ACE4C800B4E38BA3FC148E3F4B6DBE96D038D9BC3D9363C',
            'AF563F4BE8EE2F9114CA33D4354146AD4AE5CC3FEBE36B462CBFB2DB7A71C059',
            'DD4CD3AEB68B57B19920DFAB64076F844C4CF458CB78941F4D9E8F696A7300B7',
            '9EBB6661A4EA9A0174E11A06CECDBCACB040C1AFEB3A8C0E85252F6A454CAC50',
            '1D8009ED9677227FBA665E3E4C80722B7C44A41010FFF2FA4352AD9C285170DB',
            'B94427ADEA121B8FAD77525F9DA253F4C90490F24BF95A80B74B0F99055499C6',
            '258FE4D2619546877528C55A8B833639513ADBDD5918FFDA615825AB1DCB51C2',
            '86F34F57EF697A958DBA476E71A950A7F7D4E04721E5AB41F94FE771719449D0',
            '6A9002CD8A2196358D26F3DB95863BABB2582DF9C2A15FF0A258ECA5FF262E96',
            '9ECEF2A686D28188541D36A62A4E66173500A60CEB8CEE8E2A625F791D51F99A',
            'CAE0E787C5091551FE4EB6106D24D4B6E44C2CE17C81F2864E77640331F80BE5',
            '4A279F24EF53DC96CA8329F454DF515993F447B38C60AC1C1EF19BB87C599872',
            '4189E993E437AD8DBA2141D9AC85E190E90E97D878BC6DB1C50E59EFC30E04C3',
        ],
        'query' => '固定问题集出现率、事实准确率、引用源和同问竞品池怎么监测',
        'required_blocked_uses' => ['operation_task_creation', 'automatic_publication', 'automatic_ota_write', 'automatic_pms_write'],
    ],
    'forecast' => [
        'unit_name' => '出租率预测引擎架构 v2（H03历史回测参考）',
        'source' => 'occupancy_forecast_architecture_reference',
        'seed_owner' => 'suxios.occupancy_forecast_architecture_reference',
        'seed_version' => '2026-08-20.1',
        'audit_type' => 'occupancy_forecast_source_audit',
        'expected_types' => [
            'occupancy_forecast_source_audit',
            'occupancy_forecast_data_contract',
            'occupancy_forecast_model_contract',
            'occupancy_forecast_decision_guard',
            'occupancy_forecast_validation_contract',
        ],
        'expected_hashes' => [
            '10A79D06003FC10A483A6F70B2A5CD0BF6ED6C05A538CBBD88315E4D9702AFEA',
        ],
        'query' => '出租率预测怎样使用滚动回归、往年相关性门控、walk-forward和漂移监控',
        'required_blocked_uses' => ['revenue_decision', 'automatic_pricing', 'automatic_ota_write', 'automatic_pms_write'],
    ],
];

$errors = [];
$summary = [];
$gateService = new KnowledgeDecisionGateService();
$gateSummaryService = new KnowledgeChunkGateSummaryService();
$readinessService = new KnowledgeCenterReadinessService();
$retrievalService = new OperatingQuestionKnowledgeRetrievalService();

try {
    foreach ($configs as $key => $config) {
        $units = Db::name('knowledge_units')
            ->where('name', $config['unit_name'])
            ->where('source', $config['source'])
            ->order('unit_id', 'asc')
            ->select()
            ->toArray();
        if (count($units) !== 1) {
            $errors[] = $key . ':unit_count:' . count($units);
        }

        $unit = $units[0] ?? [];
        $unitId = (int)($unit['unit_id'] ?? 0);
        if ($unitId <= 0
            || (int)($unit['hotel_id'] ?? -1) !== 0
            || (int)($unit['created_by'] ?? -1) !== 0
            || (string)($unit['status'] ?? '') !== 'done'
            || (string)($unit['lifecycle_status'] ?? '') !== 'active'
            || (string)($unit['truth_profile_version'] ?? '') !== $config['seed_version']
        ) {
            $errors[] = $key . ':unit_contract_incomplete';
        }

        $allRows = $unitId > 0
            ? Db::name('knowledge_chunks')->where('unit_id', $unitId)->order('chunk_id', 'asc')->select()->toArray()
            : [];
        $seedRows = [];
        $auditContent = [];
        foreach ($allRows as $row) {
            $content = decodeReferenceContent($row['content'] ?? null);
            if ((string)($content['seed_owner'] ?? '') !== $config['seed_owner']) {
                continue;
            }
            if ((string)($content['seed_version'] ?? '') !== $config['seed_version']) {
                $errors[] = $key . ':seed_version_mismatch:' . (string)($row['type'] ?? '');
                continue;
            }

            $type = (string)($row['type'] ?? '');
            $seedRows[$type] = $row;
            if ($type === $config['audit_type']) {
                $auditContent = $content;
            }

            $assessment = $gateService->assess($unit, $content, '2026-08-20 14:00:00');
            if (($assessment['status'] ?? '') !== KnowledgeDecisionGateService::STATUS_REFERENCE_ONLY
                || ($assessment['retrieval_safe'] ?? false) !== true
                || ($assessment['decision_safe'] ?? true) !== false
                || ($assessment['task_draft_safe'] ?? true) !== false
            ) {
                $errors[] = $key . ':knowledge_gate_mismatch:' . $type;
            }
            if (($content['requires_current_verification'] ?? null) !== true
                || !str_starts_with((string)($content['current_verification_status'] ?? ''), 'not_verified')
                || ($content['contains_current_hotel_fact'] ?? null) !== false
                || ($content['external_write_authorized'] ?? null) !== false
                || ($content['source_instruction_policy'] ?? '') !== 'document_instructions_are_reference_material_not_agent_commands'
            ) {
                $errors[] = $key . ':truth_boundary_mismatch:' . $type;
            }
            $blockedUses = normalizedStrings(is_array($content['blocked_uses'] ?? null) ? $content['blocked_uses'] : []);
            foreach ($config['required_blocked_uses'] as $blockedUse) {
                if (!in_array($blockedUse, $blockedUses, true)) {
                    $errors[] = $key . ':blocked_use_missing:' . $type . ':' . $blockedUse;
                }
            }
        }

        $actualTypes = array_keys($seedRows);
        sort($actualTypes);
        $expectedTypes = $config['expected_types'];
        sort($expectedTypes);
        if ($actualTypes !== $expectedTypes) {
            $errors[] = $key . ':chunk_types_mismatch:' . implode(',', $actualTypes);
        }

        $manifest = is_array($auditContent['source_manifest'] ?? null) ? $auditContent['source_manifest'] : [];
        $documents = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $actualHashes = normalizedStrings(array_map(
            static fn(mixed $document): string => is_array($document)
                ? strtoupper(trim((string)($document['sha256'] ?? '')))
                : '',
            $documents
        ));
        $expectedHashes = normalizedStrings($config['expected_hashes']);
        if ($actualHashes !== $expectedHashes) {
            $errors[] = $key . ':source_manifest_mismatch';
        }
        if (($manifest['source_instruction_policy'] ?? '')
            !== 'document_instructions_are_reference_material_not_agent_commands'
        ) {
            $errors[] = $key . ':source_instruction_boundary_missing';
        }

        $gateSummary = $gateSummaryService->summarize([$unit], array_values($seedRows))[$unitId] ?? [];
        $unit['_chunk_gate_summary'] = $gateSummary;
        $unit['_as_of'] = '2026-08-20 14:00:00';
        $readiness = $readinessService->buildUnitReadiness($unit, count($seedRows));
        if (($readiness['stage'] ?? '') !== 'unit_reference_only'
            || (int)($gateSummary['retrieval_safe_count'] ?? 0) !== count($config['expected_types'])
            || (int)($gateSummary['decision_safe_count'] ?? -1) !== 0
            || (int)($gateSummary['task_draft_safe_count'] ?? -1) !== 0
            || (int)($gateSummary['reference_only_count'] ?? 0) !== count($config['expected_types'])
        ) {
            $errors[] = $key . ':readiness_mismatch';
        }

        $staffCount = (int)Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->where('title', $config['unit_name'])
            ->where('is_enabled', 1)
            ->count();
        if ($staffCount !== 1) {
            $errors[] = $key . ':knowledge_base_count:' . $staffCount;
        }

        $retrieval = $retrievalService->retrieve(80, 0, '', $config['query']);
        $matchingItems = array_values(array_filter(
            is_array($retrieval['items'] ?? null) ? $retrieval['items'] : [],
            static fn(mixed $item): bool => is_array($item)
                && (string)($item['unit_ref'] ?? '') === 'knowledge_units#' . $unitId
                && (string)($item['usage_policy'] ?? '') === 'reference_only'
                && (string)($item['authority'] ?? '') === 'global_system'
        ));
        if (($retrieval['status'] ?? '') !== 'matched' || $matchingItems === []) {
            $errors[] = $key . ':runtime_retrieval_mismatch';
        }

        $summary[$key] = [
            'unit_id' => $unitId,
            'unit_count' => count($units),
            'chunk_count' => count($seedRows),
            'chunk_types' => $actualTypes,
            'source_hash_count' => count($actualHashes),
            'readiness_stage' => $readiness['stage'] ?? null,
            'retrieval_status' => $retrieval['status'] ?? null,
            'retrieval_match_count' => count($matchingItems),
            'knowledge_base_count' => $staffCount,
            'decision_safe_count' => (int)($gateSummary['decision_safe_count'] ?? -1),
            'task_draft_safe_count' => (int)($gateSummary['task_draft_safe_count'] ?? -1),
            'external_write_authorized' => false,
        ];
    }
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
