#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\RevenueOperationsKnowledgeService;
use app\service\KnowledgeCenterReadinessService;
use app\service\KnowledgeChunkGateSummaryService;
use app\service\KnowledgeDecisionGateService;
use app\controller\Agent;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string, mixed> */
function decodeKnowledgeContent(mixed $value): array
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
function normalizedStrings(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        $item = mb_strtolower(trim((string)$item));
        if ($item !== '') {
            $items[$item] = $item;
        }
    }
    return array_values($items);
}

$requiredUnits = [
    '携程订单履约与结算官方语义合同' => [
        'platforms' => ['ctrip'],
        'truth_profile_version' => '2026-07-30.3',
    ],
    '订单来了PMS当前版本官方语义合同' => [
        'platforms' => ['pms', 'dingdandao'],
        'truth_profile_version' => '2026-07-30.3',
    ],
    '大众点评独立评价规则官方语义合同' => [
        'platforms' => ['dianping'],
        'truth_profile_version' => '2026-07-30.4',
    ],
];
$errors = [];
$summary = [
    'required_units' => [],
    'domestic_public_monitors' => [],
    'retrieval' => [],
    'agent_retrieval' => [],
    'decision_gate' => [],
];

try {
    $readinessService = new KnowledgeCenterReadinessService();
    $decisionGate = new KnowledgeDecisionGateService();
    foreach ($requiredUnits as $name => $requiredContract) {
        $requiredPlatforms = (array)($requiredContract['platforms'] ?? []);
        $requiredTruthProfileVersion = (string)($requiredContract['truth_profile_version'] ?? '');
        $unit = Db::name('knowledge_units')
            ->where('name', $name)
            ->where('source', RevenueOperationsKnowledgeService::SOURCE)
            ->order('unit_id', 'asc')
            ->find();
        if (!is_array($unit)) {
            $errors[] = 'missing_unit:' . $name;
            continue;
        }

        $unitId = (int)($unit['unit_id'] ?? 0);
        $chunks = Db::name('knowledge_chunks')
            ->field('chunk_id,unit_id,type,content')
            ->where('unit_id', $unitId)
            ->select()
            ->toArray();
        $activeChunkCount = 0;
        foreach ($chunks as $chunk) {
            $content = decodeKnowledgeContent($chunk['content'] ?? null);
            if (strtolower(trim((string)($content['lifecycle_status'] ?? 'active'))) !== 'active') {
                continue;
            }
            $activeChunkCount++;
            if (!is_array($content['source_refs'] ?? null) || $content['source_refs'] === []) {
                $errors[] = 'missing_source_refs:' . $name . ':' . (string)($chunk['type'] ?? '');
            }
            if (trim((string)($content['module_id'] ?? '')) === '') {
                $errors[] = 'missing_module_id:' . $name . ':' . (string)($chunk['type'] ?? '');
            }
            if (trim((string)($content['seed_version'] ?? '')) !== '2026-07-30.3') {
                $errors[] = 'wrong_seed_version:' . $name . ':' . (string)($chunk['type'] ?? '');
            }
            $platforms = normalizedStrings($content['platforms'] ?? []);
            foreach ($requiredPlatforms as $platform) {
                if (!in_array($platform, $platforms, true)) {
                    $errors[] = 'missing_platform:' . $name . ':' . $platform;
                }
            }
        }
        if ($activeChunkCount !== 5) {
            $errors[] = 'active_chunk_count:' . $name . ':' . $activeChunkCount;
        }
        if ((string)($unit['status'] ?? '') !== 'done'
            || (string)($unit['lifecycle_status'] ?? '') !== 'active'
            || (string)($unit['truth_profile_version'] ?? '') !== $requiredTruthProfileVersion
        ) {
            $errors[] = 'unit_contract_incomplete:' . $name;
        }

        $mirror = Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->where('title', $name)
            ->where('is_enabled', 1)
            ->find();
        if (!is_array($mirror)) {
            $errors[] = 'missing_knowledge_base_mirror:' . $name;
        }
        $chunkGateSummary = (new KnowledgeChunkGateSummaryService())->summarize(
            [$unit],
            $chunks
        )[$unitId] ?? [];
        $unit['_chunk_gate_summary'] = $chunkGateSummary;
        $readiness = $readinessService->buildUnitReadiness($unit, $activeChunkCount);
        if (($readiness['stage'] ?? '') !== 'unit_global_reference'
            || ($readiness['closed_loop'] ?? false) !== true
        ) {
            $errors[] = 'knowledge_center_readiness_incomplete:' . $name;
        }

        $summary['required_units'][] = [
            'name' => $name,
            'unit_id' => $unitId,
            'active_chunk_count' => $activeChunkCount,
            'mirror_id' => (int)($mirror['id'] ?? 0),
            'readiness_stage' => (string)($readiness['stage'] ?? ''),
            'readiness_score' => (int)($readiness['score'] ?? 0),
            'chunk_gate_summary' => $chunkGateSummary,
        ];
    }

    $legacyRevenue = Db::name('knowledge_chunks')
        ->alias('kc')
        ->join('knowledge_units ku', 'ku.unit_id = kc.unit_id')
        ->field('kc.content')
        ->where('ku.name', 'OTA标准指标与推荐公式清单')
        ->where('ku.source', 'ota')
        ->where('kc.type', '交易收益指标')
        ->find();
    $legacyRevenueContent = decodeKnowledgeContent($legacyRevenue['content'] ?? null);
    $formula = trim((string)($legacyRevenueContent['rows'][2]['formula'] ?? ''));
    if ($formula !== 'sum(room_revenue)') {
        $errors[] = 'paid_amount_room_revenue_fallback_not_removed';
    }

    $legacyCapture = Db::name('knowledge_chunks')
        ->alias('kc')
        ->join('knowledge_units ku', 'ku.unit_id = kc.unit_id')
        ->field('kc.content')
        ->where('ku.name', '美团 eBooking 浏览器自动化采集方法')
        ->where('kc.type', '采集方法')
        ->find();
    $legacyCaptureContent = decodeKnowledgeContent($legacyCapture['content'] ?? null);
    if (str_contains(
        (string)($legacyCaptureContent['profile_rule'] ?? ''),
        'meituan_profile_{store_id}'
    )) {
        $errors[] = 'legacy_per_store_profile_still_active';
    }
    if (($legacyCaptureContent['review_collection_boundary']['standard_automatic_etl'] ?? '')
        !== 'disabled'
    ) {
        $errors[] = 'review_collection_boundary_missing';
    }

    $strategyRows = Db::name('knowledge_chunks')
        ->alias('kc')
        ->join('knowledge_units ku', 'ku.unit_id = kc.unit_id')
        ->field('kc.type,kc.content')
        ->where('ku.name', 'OTA手动与自动获取策略')
        ->whereIn('kc.type', ['携程差异', '美团差异'])
        ->select()
        ->toArray();
    foreach ($strategyRows as $strategyRow) {
        $content = decodeKnowledgeContent($strategyRow['content'] ?? null);
        if (in_array('点评', $content['automatic_priority'] ?? [], true)) {
            $errors[] = 'legacy_review_automatic_priority_still_active:'
                . (string)($strategyRow['type'] ?? '');
        }
        if (($content['review_collection_boundary'] ?? '') === '') {
            $errors[] = 'strategy_review_boundary_missing:'
                . (string)($strategyRow['type'] ?? '');
        }
    }

    foreach ([
        'OTA标准指标与推荐公式清单' => [
            '若暂无房费收入则 `sum(paid_amount)`',
            '携程数据中心更可能直接提供',
        ],
        '美团 eBooking 浏览器自动化采集方法' => [
            'storage/meituan_profile_{store_id}',
            '依次打开点评、流量、newhb 流量、广告、订单页面',
        ],
        'OTA手动与自动获取策略' => [
            '自动优先：经营概况、流量、订单、点评、房态房价/ARI。',
            '自动优先：点评、数据中心/流量、订单/入住管理、价格库存/直连产品。',
        ],
    ] as $title => $obsoleteTexts) {
        $mirror = Db::name('knowledge_base')
            ->field('content')
            ->where('hotel_id', 0)
            ->where('title', $title)
            ->find();
        $mirrorContent = (string)($mirror['content'] ?? '');
        foreach ($obsoleteTexts as $obsoleteText) {
            if (str_contains($mirrorContent, $obsoleteText)) {
                $errors[] = 'obsolete_staff_mirror_text:' . $title;
            }
        }
    }

    $reviewDueColumn = Db::query("SHOW COLUMNS FROM `knowledge_units` LIKE 'review_due_at'");
    if ($reviewDueColumn === []) {
        $errors[] = 'knowledge_review_due_column_missing';
    }
    $activeUnitMetadata = Db::name('knowledge_units')
        ->fieldRaw('COUNT(*) AS total, SUM(review_due_at IS NULL) AS missing_review_due')
        ->where('lifecycle_status', 'active')
        ->find();
    if ((int)($activeUnitMetadata['missing_review_due'] ?? -1) !== 0) {
        $errors[] = 'active_unit_review_due_missing';
    }
    $activeChunkRows = Db::name('knowledge_chunks')
        ->alias('kc')
        ->join('knowledge_units ku', 'ku.unit_id = kc.unit_id')
        ->field('kc.content')
        ->where('ku.lifecycle_status', 'active')
        ->select()
        ->toArray();
    $missingDecisionMetadata = 0;
    $activeDecisionChunkCount = 0;
    foreach ($activeChunkRows as $row) {
        $content = decodeKnowledgeContent($row['content'] ?? null);
        if (strtolower(trim((string)($content['lifecycle_status'] ?? 'active'))) !== 'active') {
            continue;
        }
        $activeDecisionChunkCount++;
        foreach (['reviewed_at', 'review_due_at', 'evidence_grade', 'decision_policy'] as $field) {
            if (!array_key_exists($field, $content) || $content[$field] === '' || $content[$field] === null) {
                $missingDecisionMetadata++;
                break;
            }
        }
    }
    if ($missingDecisionMetadata !== 0) {
        $errors[] = 'active_chunk_decision_metadata_missing:' . $missingDecisionMetadata;
    }
    $resolvedConflictCount = Db::name('knowledge_chunks')
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.resolution_status')) = 'resolved'")
        ->whereRaw("JSON_EXTRACT(`content`, '$.conflict_key') IS NOT NULL")
        ->count();
    if ((int)$resolvedConflictCount !== 5) {
        $errors[] = 'resolved_conflict_metadata_count:' . (int)$resolvedConflictCount;
    }
    $knownUnknownConflictCount = Db::name('knowledge_chunks')
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.scope')) = 'version_conflict'")
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`content`, '$.conflict_status')) = 'unresolved'")
        ->count();
    if ((int)$knownUnknownConflictCount < 1) {
        $errors[] = 'known_unknown_conflict_contract_missing';
    }
    $expiredGate = $decisionGate->assess([
        'lifecycle_status' => 'active',
    ], [
        'scope' => 'platform_rule',
        'evidence_level' => 'official_current_rule',
        'source_refs' => ['verifier-expired-source'],
        'valid_until' => '2026-07-01 00:00:00',
    ], '2026-07-30 12:00:00');
    if (($expiredGate['status'] ?? '') !== 'blocked'
        || ($expiredGate['retrieval_safe'] ?? true) !== false
    ) {
        $errors[] = 'expired_knowledge_gate_not_blocked';
    }
    $summary['decision_gate'] = [
        'active_unit_count' => (int)($activeUnitMetadata['total'] ?? 0),
        'missing_unit_review_due_count' => (int)($activeUnitMetadata['missing_review_due'] ?? 0),
        'active_chunk_count' => $activeDecisionChunkCount,
        'missing_chunk_decision_metadata_count' => $missingDecisionMetadata,
        'resolved_conflict_count' => (int)$resolvedConflictCount,
        'known_unknown_conflict_count' => (int)$knownUnknownConflictCount,
        'expired_sample_status' => (string)($expiredGate['status'] ?? ''),
    ];

    $domesticRows = Db::name('knowledge_units')
        ->alias('ku')
        ->join('knowledge_chunks kc', 'kc.unit_id = ku.unit_id')
        ->field('ku.unit_id,ku.name,kc.content')
        ->where('ku.source', 'domestic_public_monitor')
        ->where('kc.type', 'domestic_public_source_snapshot')
        ->order('ku.unit_id', 'asc')
        ->select()
        ->toArray();
    if (count($domesticRows) !== 4) {
        $errors[] = 'domestic_public_monitor_count:' . count($domesticRows);
    }
    foreach ($domesticRows as $row) {
        $content = decodeKnowledgeContent($row['content'] ?? null);
        $name = (string)($row['name'] ?? '');
        if (!is_array($content['source_refs'] ?? null) || $content['source_refs'] === []) {
            $errors[] = 'domestic_monitor_source_refs_missing:' . $name;
        }
        if (trim((string)($content['seed_key'] ?? '')) === '') {
            $errors[] = 'domestic_monitor_seed_key_missing:' . $name;
        }
        $mirror = Db::name('knowledge_base')
            ->where('hotel_id', 0)
            ->where('title', $name)
            ->where('is_enabled', 1)
            ->find();
        if (!is_array($mirror)) {
            $errors[] = 'domestic_monitor_mirror_missing:' . $name;
        }
        $summary['domestic_public_monitors'][] = [
            'name' => $name,
            'source_ref_count' => count($content['source_refs'] ?? []),
            'mirror_id' => (int)($mirror['id'] ?? 0),
        ];
    }

    $service = new RevenueOperationsKnowledgeService();
    $context = $service->load(['limit' => 30]);
    if (($context['truncated'] ?? false) !== true
        || (int)($context['eligible_entry_count'] ?? 0) <= (int)($context['entry_count'] ?? 0)
    ) {
        $errors[] = 'retrieval_truncation_not_explicit';
    }
    if ((int)($context['selected_unit_count'] ?? 0) !== (int)($context['unit_count'] ?? 0)) {
        $errors[] = 'retrieval_fair_selection_missing';
    }

    foreach (['ctrip', 'meituan', 'dianping'] as $platform) {
        $platformContext = $service->load(['limit' => 100, 'platform' => $platform]);
        foreach ($platformContext['entries'] ?? [] as $entry) {
            if (($entry['knowledge_gate']['retrieval_safe'] ?? false) !== true) {
                $errors[] = 'retrieval_gate_leak:' . $platform . ':' . (int)($entry['chunk_id'] ?? 0);
            }
            $explicitPlatforms = normalizedStrings($entry['platforms'] ?? []);
            if ($explicitPlatforms !== [] && !in_array($platform, $explicitPlatforms, true)) {
                $errors[] = sprintf(
                    'platform_leak:%s:%s',
                    $platform,
                    (string)($entry['unit_name'] ?? '')
                );
            }
        }
    }
    $summary['retrieval'] = [
        'status' => (string)($context['status'] ?? ''),
        'unit_count' => (int)($context['unit_count'] ?? 0),
        'selected_unit_count' => (int)($context['selected_unit_count'] ?? 0),
        'entry_count' => (int)($context['entry_count'] ?? 0),
        'eligible_entry_count' => (int)($context['eligible_entry_count'] ?? 0),
        'omitted_entry_count' => (int)($context['omitted_entry_count'] ?? 0),
        'truncated' => (bool)($context['truncated'] ?? false),
    ];

    $agentReflection = new ReflectionClass(Agent::class);
    $agent = $agentReflection->newInstanceWithoutConstructor();
    $agentLoader = $agentReflection->getMethod('loadOtaKnowledgeContext');
    $agentContracts = [
        'ctrip' => [
            'required_title' => '携程订单履约与结算官方语义合同',
            'forbidden_title_tokens' => ['美团', '大众点评', '订单来了', '国内PMS'],
        ],
        'meituan' => [
            'required_title' => '美团酒店评价与经营规则官方语义合同',
            'forbidden_title_tokens' => ['携程', '大众点评', '订单来了', '国内PMS'],
        ],
        'dianping' => [
            'required_title' => '大众点评独立评价规则官方语义合同',
            'forbidden_title_tokens' => ['携程', '美团酒店', '订单来了', '国内PMS'],
        ],
        'dingdandao' => [
            'required_title' => '订单来了PMS当前版本官方语义合同',
            'forbidden_title_tokens' => ['携程', '美团酒店', '大众点评'],
        ],
    ];
    foreach ($agentContracts as $platform => $contract) {
        $agentContext = $agentLoader->invoke($agent, $platform, 'business', []);
        $titles = array_values(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['title'] ?? '')),
            is_array($agentContext['items'] ?? null) ? $agentContext['items'] : []
        )));
        if (!in_array($contract['required_title'], $titles, true)) {
            $errors[] = 'agent_required_title_missing:' . $platform;
        }
        foreach ($titles as $title) {
            foreach ($contract['forbidden_title_tokens'] as $token) {
                if (str_contains($title, $token)) {
                    $errors[] = sprintf('agent_platform_title_leak:%s:%s', $platform, $title);
                }
            }
        }
        foreach ($agentContext['items'] ?? [] as $item) {
            if (($item['source'] ?? '') !== 'knowledge_units') {
                $errors[] = 'agent_unstructured_knowledge_fallback:' . $platform;
            }
            if (!is_array($item['knowledge_gate'] ?? null)) {
                $errors[] = 'agent_knowledge_gate_missing:' . $platform;
            }
        }
        $summary['agent_retrieval'][$platform] = [
            'status' => (string)($agentContext['status'] ?? ''),
            'titles' => $titles,
        ];
    }

    $result = [
        'status' => $errors === [] ? 'verified' : 'failed',
        'error_count' => count($errors),
        'errors' => $errors,
        'summary' => $summary,
    ];
    echo json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit($errors === [] ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}
