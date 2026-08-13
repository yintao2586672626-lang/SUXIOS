#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\RevenueOperationsKnowledgeService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';
(new App())->initialize();

/** @return array<string, mixed> */
function decodeCtripRadarJson(mixed $value): array
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
function addCtripRadarCheck(array &$checks, string $name, bool $passed, mixed $actual = null): void
{
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'actual' => $actual,
    ];
}

$unitName = '携程酒店经营雷达图（规划期）五维知识合同';
$source = RevenueOperationsKnowledgeService::SOURCE;
$seedOwner = 'suxios.ctrip_hotel_operating_radar_knowledge';
$onlineSeedOwner = 'suxios.ctrip_hotel_operating_radar_online_expansion';
$pdfSeedOwner = 'suxios.ctrip_flow_rules_pdf_20260811';
$expectedOnlineDocumentSha256 = 'AB721257E58A17ECF714586571D5BAB58F8AD95A95A315D2E0993568E655763B';
$expectedPdfDocumentSha256 = 'A8056DB215C068C5223346729408A2544E21E1CB229B435D17346C1E97CC55FC';
$expectedPdfSourceSha256 = '6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D';
$expectedTypes = [
    'ctrip_radar_source_scope_and_rollout_reference',
    'ctrip_antitrust_regulatory_context_fact',
    'ctrip_radar_model_principles_reference',
    'ctrip_radar_five_dimension_semantics_reference',
    'ctrip_radar_user_journey_and_platform_focus_reference',
    'ctrip_radar_usage_and_rollout_guard',
];
$expectedDimensionKeys = [
    'information_score',
    'friendliness',
    'quality',
    'welcome',
    'platform_technical_service_fee',
];
$expectedImageHashes = [
    'D09793D1C72F785E289EEDE37F265ACAB89F59A6050AD2A48D8AE8BD098D937C',
    'A0970684ABA0154389CDA502230586D1523C544C4AD74B6409B41CCEAFF05025',
    '0835567A1C2C5052054FCEE5F806736A9F5468C6DF15B7512842DE2FCF204EAB',
];
$expectedOnlineTypes = [
    'ctrip_radar_online_source_audit_reference',
    'ctrip_rectification_19_measures_commitment_reference',
    'ctrip_radar_regulatory_operating_boundaries_fact',
    'ctrip_radar_public_rule_20251103_historical_reference',
    'ctrip_radar_live_rollout_verification_checklist',
];
$expectedPdfTypes = [
    'ctrip_flow_rules_pdf_source_audit_reference',
    'ctrip_flow_rules_pdf_conflict_reference',
];
$expectedRectificationGroups = [
    [
        'group' => '酒店经营自主权',
        'items' => [
            '全面下线一级委托分销特牌合作模式',
            '调整相关合作协议并建立新的商家分级合作模式',
            '取消不合理流量安排并建立新的流量分配机制',
        ],
    ],
    [
        'group' => '酒店自主定价权',
        'items' => [
            '全面下线二级委托分销金牌合作模式并停止全网最低价要求',
            '调整相关合作协议并建立新的商家分级合作模式',
            '取消不合理流量安排并建立新的流量分配机制',
            '自述2026年3月下线AI生意助手并宣布下线挂牌通调价功能',
            '未经商家明确同意业务人员不得擅自调整价格',
            '退还相关订单储备金122781078元',
        ],
    ],
    [
        'group' => '商家权益和经营环境',
        'items' => [
            '取消原一二级委托分销收费安排并建立新佣金模式',
            '取消平台调整商家价格的合同条款并提升规则透明度',
            '坚持自愿选择平等协商并畅通反馈申诉机制',
            '下线智选特惠等促销类别',
            '免费开放数据中心VIP并投入AI赋能商家培训和服务品质提升',
        ],
    ],
    [
        'group' => '消费者权益保护',
        'items' => [
            '提升消费体验和服务满意度',
            '强化个人信息和数据安全保护',
            '防范大数据杀熟',
        ],
    ],
    [
        'group' => '长期合规机制',
        'items' => [
            '建立反垄断合规咨询审查举报和奖惩机制',
            '持续开展反垄断合规培训并融入日常经营全过程',
        ],
    ],
];
$checks = [];

$unit = Db::name('knowledge_units')
    ->where('name', $unitName)
    ->where('source', $source)
    ->order('unit_id', 'asc')
    ->find();

addCtripRadarCheck($checks, 'knowledge_unit_exists', is_array($unit), $unit['unit_id'] ?? null);
addCtripRadarCheck($checks, 'knowledge_unit_global', (int)($unit['hotel_id'] ?? -1) === 0, $unit['hotel_id'] ?? null);
addCtripRadarCheck($checks, 'knowledge_unit_active', ($unit['lifecycle_status'] ?? null) === 'active', $unit['lifecycle_status'] ?? null);
addCtripRadarCheck($checks, 'truth_profile_version', ($unit['truth_profile_version'] ?? null) === '2026-08-11.4', $unit['truth_profile_version'] ?? null);
addCtripRadarCheck(
    $checks,
    'pdf_conflict_reference_lifecycle_reason',
    ($unit['lifecycle_reason'] ?? null) === 'third_party_flow_rules_pdf_absorbed_as_unverified_conflict_reference',
    $unit['lifecycle_reason'] ?? null
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

$actualTypes = array_values(array_map(
    static fn(array $row): string => (string)($row['type'] ?? ''),
    $chunks
));
$sortedExpectedTypes = $expectedTypes;
$sortedActualTypes = $actualTypes;
sort($sortedExpectedTypes);
sort($sortedActualTypes);
addCtripRadarCheck($checks, 'seed_chunk_count', count($chunks) === 6, count($chunks));
addCtripRadarCheck($checks, 'seed_chunk_types', $sortedActualTypes === $sortedExpectedTypes, $actualTypes);

$onlineChunks = $unitId > 0
    ? Db::name('knowledge_chunks')
        ->where('unit_id', $unitId)
        ->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_owner')) = ?",
            [$onlineSeedOwner]
        )
        ->order('chunk_id', 'asc')
        ->select()
        ->toArray()
    : [];
$actualOnlineTypes = array_values(array_map(
    static fn(array $row): string => (string)($row['type'] ?? ''),
    $onlineChunks
));
$sortedExpectedOnlineTypes = $expectedOnlineTypes;
$sortedActualOnlineTypes = $actualOnlineTypes;
sort($sortedExpectedOnlineTypes);
sort($sortedActualOnlineTypes);
addCtripRadarCheck($checks, 'online_seed_chunk_count', count($onlineChunks) === 5, count($onlineChunks));
addCtripRadarCheck(
    $checks,
    'online_seed_chunk_types',
    $sortedActualOnlineTypes === $sortedExpectedOnlineTypes,
    $actualOnlineTypes
);

$pdfChunks = $unitId > 0
    ? Db::name('knowledge_chunks')
        ->where('unit_id', $unitId)
        ->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(`content`) = 1 THEN `content` ELSE JSON_OBJECT() END, '$.seed_owner')) = ?",
            [$pdfSeedOwner]
        )
        ->order('chunk_id', 'asc')
        ->select()
        ->toArray()
    : [];
$actualPdfTypes = array_values(array_map(
    static fn(array $row): string => (string)($row['type'] ?? ''),
    $pdfChunks
));
$sortedExpectedPdfTypes = $expectedPdfTypes;
$sortedActualPdfTypes = $actualPdfTypes;
sort($sortedExpectedPdfTypes);
sort($sortedActualPdfTypes);
addCtripRadarCheck($checks, 'pdf_seed_chunk_count', count($pdfChunks) === 2, count($pdfChunks));
addCtripRadarCheck(
    $checks,
    'pdf_seed_chunk_types',
    $sortedActualPdfTypes === $sortedExpectedPdfTypes,
    $actualPdfTypes
);

$dimensionKeys = [];
$imageHashes = [];
$journeyRowCount = 0;
$journeyRows = [];
$guardFailures = [];
$regulatoryFact = [];
$rectificationCommitment = [];
$regulatoryBoundaries = [];
$historicalRule = [];
$rolloutChecklist = [];
$sourceAudit = [];
$pdfSourceAudit = [];
$pdfConflictReference = [];
foreach (array_merge($chunks, $onlineChunks, $pdfChunks) as $chunk) {
    $type = (string)($chunk['type'] ?? 'unknown');
    $content = decodeCtripRadarJson($chunk['content'] ?? null);

    if ($type === 'ctrip_radar_five_dimension_semantics_reference') {
        foreach ((array)($content['dimensions'] ?? []) as $dimension) {
            if (is_array($dimension) && is_string($dimension['key'] ?? null)) {
                $dimensionKeys[] = $dimension['key'];
            }
        }
    }
    if ($type === 'ctrip_radar_user_journey_and_platform_focus_reference') {
        $journeyRows = (array)($content['journey_rows'] ?? []);
        $journeyRowCount = count($journeyRows);
    }
    if ($type === 'ctrip_antitrust_regulatory_context_fact') {
        $regulatoryFact = $content;
    }
    if ($type === 'ctrip_rectification_19_measures_commitment_reference') {
        $rectificationCommitment = $content;
    }
    if ($type === 'ctrip_radar_regulatory_operating_boundaries_fact') {
        $regulatoryBoundaries = $content;
    }
    if ($type === 'ctrip_radar_public_rule_20251103_historical_reference') {
        $historicalRule = $content;
    }
    if ($type === 'ctrip_radar_live_rollout_verification_checklist') {
        $rolloutChecklist = $content;
    }
    if ($type === 'ctrip_radar_online_source_audit_reference') {
        $sourceAudit = $content;
    }
    if ($type === 'ctrip_flow_rules_pdf_source_audit_reference') {
        $pdfSourceAudit = $content;
    }
    if ($type === 'ctrip_flow_rules_pdf_conflict_reference') {
        $pdfConflictReference = $content;
    }

    $manifestHashes = $content['source_manifest']['image_sha256'] ?? [];
    if (is_array($manifestHashes)) {
        $imageHashes = array_values(array_unique(array_merge($imageHashes, $manifestHashes)));
    }

    $blockedUses = $content['blocked_uses'] ?? [];
    if (!is_array($blockedUses)
        || !in_array('hotel_score_calculation', $blockedUses, true)
        || !in_array('ranking_prediction', $blockedUses, true)
        || !in_array('operation_task_creation', $blockedUses, true)
        || !in_array('automatic_pricing', $blockedUses, true)
        || !in_array('automatic_ota_write', $blockedUses, true)
        || ($content['external_write_authorized'] ?? null) !== false
        || ($content['contains_current_hotel_fact'] ?? null) !== false
        || ($content['contains_current_ota_fact'] ?? null) !== false) {
        $guardFailures[] = $type;
    }
}

addCtripRadarCheck($checks, 'five_dimension_keys_read_back', $dimensionKeys === $expectedDimensionKeys, $dimensionKeys);
addCtripRadarCheck($checks, 'five_journey_rows_read_back', $journeyRowCount === 5, $journeyRowCount);
$expectedJourneyRows = [
    [
        'stage' => '信息浏览',
        'user_questions' => ['这家看起来怎么样？', '带宠物能住吗？'],
        'dimension_key' => 'information_score',
        'platform_focus' => ['图片/视频质量', '设施描述完整', '酒店政策准确', '信息真实'],
    ],
    [
        'stage' => '预订决策',
        'user_questions' => ['预订是否省心？', '退改是否灵活？'],
        'dimension_key' => 'friendliness',
        'platform_focus' => ['价格合理', '房态准确/充足', '取消政策灵活'],
    ],
    [
        'stage' => '到店入住',
        'user_questions' => ['服务怎么样？', '入住体验舒心吗？'],
        'dimension_key' => 'quality',
        'platform_focus' => ['订单即时确认', '用户投诉', '点评分', '用户权益', '六大类服务缺陷'],
    ],
    [
        'stage' => '长期价值',
        'user_questions' => ['是否认可？'],
        'dimension_key' => 'welcome',
        'platform_focus' => ['历史订单与销售额', '历史成交率', '避免虚假交易和恶意刷单'],
    ],
    [
        'stage' => '平台合作',
        'user_questions' => [],
        'dimension_key' => 'platform_technical_service_fee',
        'platform_focus' => ['合理的技术服务费', '无逾期账单'],
    ],
];
addCtripRadarCheck(
    $checks,
    'journey_dimension_and_platform_focus_exact_readback',
    $journeyRows === $expectedJourneyRows,
    $journeyRows
);
sort($imageHashes);
$sortedExpectedImageHashes = $expectedImageHashes;
sort($sortedExpectedImageHashes);
addCtripRadarCheck($checks, 'three_image_hashes_read_back', $imageHashes === $sortedExpectedImageHashes, $imageHashes);
addCtripRadarCheck($checks, 'score_task_price_and_write_guards_on_every_chunk', $guardFailures === [], $guardFailures);
addCtripRadarCheck(
    $checks,
    'official_penalty_fact_read_back',
    ($regulatoryFact['decision_date'] ?? null) === '2026-07-25'
        && ($regulatoryFact['decision_number'] ?? null) === '国市监处罚〔2026〕29号'
        && (int)($regulatoryFact['penalty_total_cny'] ?? 0) === 5179000000,
    [
        'decision_date' => $regulatoryFact['decision_date'] ?? null,
        'decision_number' => $regulatoryFact['decision_number'] ?? null,
        'penalty_total_cny' => $regulatoryFact['penalty_total_cny'] ?? null,
    ]
);
addCtripRadarCheck(
    $checks,
    'radar_penalty_causal_link_not_claimed',
    ($regulatoryFact['radar_causal_link_status'] ?? null) === 'not_established_by_official_penalty_source_or_user_material',
    $regulatoryFact['radar_causal_link_status'] ?? null
);
addCtripRadarCheck(
    $checks,
    'online_source_audit_keeps_missing_original_explicit',
    ($sourceAudit['result'] ?? null) === 'exact_original_radar_publish_page_not_found_in_public_search_index'
        && ($sourceAudit['search_limit'] ?? null) === 'does_not_prove_nonexistence_or_absence_from_authenticated_ebooking'
        && count((array)($sourceAudit['unknowns'] ?? [])) === 4
        && str_contains((string)(($sourceAudit['source_refs'][0] ?? '')), $expectedOnlineDocumentSha256)
        && ($sourceAudit['source_manifest']['research_document_sha256'] ?? null) === $expectedOnlineDocumentSha256,
    $sourceAudit
);
$actualRectificationGroups = (array)($rectificationCommitment['measure_groups'] ?? []);
$actualRectificationGroupItemCounts = array_values(array_map(
    static fn(mixed $group): int => is_array($group) ? count((array)($group['items'] ?? [])) : 0,
    $actualRectificationGroups
));
$actualRectificationItemCount = array_sum($actualRectificationGroupItemCounts);
addCtripRadarCheck(
    $checks,
    'nineteen_rectification_commitments_read_back',
    (int)($rectificationCommitment['measure_group_count'] ?? 0) === 5
        && (int)($rectificationCommitment['measure_count'] ?? 0) === 19
        && count($actualRectificationGroups) === 5
        && $actualRectificationItemCount === 19
        && $actualRectificationGroups === $expectedRectificationGroups
        && ($rectificationCommitment['statement_scope'] ?? null) === 'announcement_proves_commitment_not_independently_verified_completion'
        && ($rectificationCommitment['radar_direct_reference_status'] ?? null) === 'not_mentioned_in_the_19_measures_announcement',
    [
        'measure_group_count' => $rectificationCommitment['measure_group_count'] ?? null,
        'measure_count' => $rectificationCommitment['measure_count'] ?? null,
        'actual_group_count' => count($actualRectificationGroups),
        'actual_group_item_counts' => $actualRectificationGroupItemCounts,
        'actual_item_count' => $actualRectificationItemCount,
        'exact_groups_match' => $actualRectificationGroups === $expectedRectificationGroups,
        'statement_scope' => $rectificationCommitment['statement_scope'] ?? null,
        'radar_direct_reference_status' => $rectificationCommitment['radar_direct_reference_status'] ?? null,
    ]
);
addCtripRadarCheck(
    $checks,
    'regulatory_operating_boundaries_read_back',
    ($regulatoryBoundaries['price_rule']['effective_from'] ?? null) === '2026-04-10'
        && ($regulatoryBoundaries['price_rule']['merchant_autonomous_cross_platform_pricing'] ?? null) === true
        && ($regulatoryBoundaries['price_rule']['ranking_disclosure_scope'] ?? null) === 'platform_merchants_participating_in_bidding'
        && ($regulatoryBoundaries['price_rule']['ranking_inference_guard'] ?? null) === '不得据此推导普通推荐算法或雷达公式权重必须向所有酒店披露'
        && ($regulatoryBoundaries['platform_rule_supervision']['effective_from'] ?? null) === '2026-02-01'
        && ($regulatoryBoundaries['antitrust_compliance_guidance']['legal_effect'] ?? null) === 'general_guidance_not_mandatory',
    $regulatoryBoundaries
);
addCtripRadarCheck(
    $checks,
    'historical_public_rule_requires_current_verification',
    ($historicalRule['page_latest_version_published_at'] ?? null) === '2025-11-03'
        && ($historicalRule['precedes_penalty_and_rectification'] ?? null) === true
        && ($historicalRule['requires_current_verification'] ?? null) === true
        && ($historicalRule['current_verification_status'] ?? null) === 'historical_page_only',
    $historicalRule
);
addCtripRadarCheck(
    $checks,
    'live_rollout_checklist_read_back',
    count((array)($rolloutChecklist['required_checks'] ?? [])) === 10
        && ($rolloutChecklist['failure_state'] ?? null) === 'missing_or_conflicting_fields_remain_unverified_and_cannot_be_replaced_by_zero_history_or_inference',
    count((array)($rolloutChecklist['required_checks'] ?? []))
);
addCtripRadarCheck(
    $checks,
    'third_party_pdf_source_identity_read_back',
    ($pdfSourceAudit['document_identity']['sha256'] ?? null) === $expectedPdfSourceSha256
        && (int)($pdfSourceAudit['document_identity']['page_count'] ?? 0) === 18
        && ($pdfSourceAudit['document_identity']['creator'] ?? null) === 'WPS 演示'
        && ($pdfSourceAudit['document_identity']['visible_signature'] ?? null) === '舒克'
        && ($pdfSourceAudit['document_identity']['visible_watermark'] ?? null) === 'shuke'
        && ($pdfSourceAudit['officiality_status'] ?? null) === 'not_established_as_ctrip_official_publication'
        && ($pdfSourceAudit['current_verification_status'] ?? null) === 'not_verified'
        && ($pdfSourceAudit['source_manifest']['normalized_document_sha256'] ?? null) === $expectedPdfDocumentSha256
        && ($pdfSourceAudit['source_manifest']['source_sha256'] ?? null) === $expectedPdfSourceSha256
        && (int)($pdfSourceAudit['review_coverage']['visually_inspected_page_count'] ?? 0) === 18,
    $pdfSourceAudit
);
$pdfClaims = (array)($pdfConflictReference['new_unverified_claims'] ?? []);
$pdfClaimsByPage = [];
foreach ($pdfClaims as $pdfClaim) {
    if (is_array($pdfClaim)) {
        $pdfClaimsByPage[(int)($pdfClaim['pdf_page'] ?? 0)] = (string)($pdfClaim['status'] ?? '');
    }
}
addCtripRadarCheck(
    $checks,
    'third_party_pdf_conflicts_and_formula_guard_read_back',
    ($pdfConflictReference['conflict_status'] ?? null) === 'unresolved'
        && count((array)($pdfConflictReference['model_conflicts'] ?? [])) === 6
        && count($pdfClaims) === 8
        && ($pdfClaimsByPage[5] ?? null) === 'unverified_exact_threshold'
        && ($pdfClaimsByPage[14] ?? null) === 'unverified_formula_prohibited'
        && ($pdfConflictReference['routed_existing_unit']['module_id'] ?? null) === 'ctrip_commission_reform_watch'
        && ($pdfConflictReference['routed_existing_unit']['routing_rule'] ?? null) === 'reuse_existing_unverified_claims_without_duplicate_or_evidence_upgrade'
        && count((array)($pdfConflictReference['routed_existing_unit']['existing_claim_ids'] ?? [])) === 6
        && ($pdfConflictReference['historical_ladder_evidence']['status'] ?? null) === 'historically_observed_before_pdf_not_current_state',
    $pdfConflictReference
);

$staffRow = Db::name('knowledge_base')
    ->where('hotel_id', 0)
    ->where('title', $unitName)
    ->find();
$staffContent = (string)($staffRow['content'] ?? '');
addCtripRadarCheck($checks, 'staff_knowledge_row_exists', is_array($staffRow), $staffRow['id'] ?? null);
addCtripRadarCheck(
    $checks,
    'staff_readback_keeps_dimensions_and_rollout_guard',
    str_contains($staffContent, '信息分')
        && str_contains($staffContent, '平台技术服务费')
        && str_contains($staffContent, '五方面十九项整改')
        && str_contains($staffContent, '新佣金模式')
        && str_contains($staffContent, '公开携程酒店规则页面最新版本为2025-11-03')
        && str_contains($staffContent, '排序规则告知义务的对象是参与竞价的平台内经营者')
        && !str_contains($staffContent, '要求收费和排序规则透明')
        && str_contains($staffContent, '## PDF补充（第三方待核验）')
        && str_contains($staffContent, '舒克署名并带shuke水印')
        && str_contains($staffContent, '第16至17页佣金和工具主张复用')
        && str_contains($staffContent, '不得据此计算本店得分、预测排序、自动调价、创建任务或写入OTA/PMS'),
    strlen($staffContent)
);

$context = (new RevenueOperationsKnowledgeService())->load([
    'hotel_id' => 0,
    'platform' => 'ctrip',
    'module_id' => 'ctrip_hotel_operating_radar',
    'limit' => 20,
    'as_of' => '2026-08-11 12:00:00',
]);
$gateStatuses = [];
$taskDraftSafeCount = 0;
foreach ((array)($context['entries'] ?? []) as $entry) {
    $status = (string)($entry['knowledge_gate']['status'] ?? 'missing');
    $gateStatuses[$status] = ($gateStatuses[$status] ?? 0) + 1;
    if (($entry['knowledge_gate']['task_draft_safe'] ?? false) === true) {
        $taskDraftSafeCount++;
    }
}
ksort($gateStatuses);
addCtripRadarCheck(
    $checks,
    'runtime_reader_exact_module_readback',
    ($context['status'] ?? null) === 'available'
        && (int)($context['entry_count'] ?? 0) === 13
        && (int)($context['decision_safe_entry_count'] ?? 0) === 3,
    [
        'status' => $context['status'] ?? null,
        'entry_count' => $context['entry_count'] ?? null,
        'decision_safe_entry_count' => $context['decision_safe_entry_count'] ?? null,
        'gate_statuses' => $gateStatuses,
    ]
);
addCtripRadarCheck(
    $checks,
    'runtime_reference_and_task_gate',
    ($gateStatuses['approved'] ?? 0) === 3
        && ($gateStatuses['reference_only'] ?? 0) === 7
        && ($gateStatuses['known_unknown'] ?? 0) === 3
        && $taskDraftSafeCount === 0,
    [
        'gate_statuses' => $gateStatuses,
        'task_draft_safe_count' => $taskDraftSafeCount,
    ]
);

$failed = array_values(array_filter(
    $checks,
    static fn(array $check): bool => ($check['passed'] ?? false) !== true
));

$result = [
    'status' => $failed === [] ? 'pass' : 'fail',
    'unit_id' => $unitId,
    'chunk_count' => count($chunks) + count($onlineChunks) + count($pdfChunks),
    'base_chunk_count' => count($chunks),
    'online_chunk_count' => count($onlineChunks),
    'pdf_chunk_count' => count($pdfChunks),
    'knowledge_base_entry_count' => is_array($staffRow) ? 1 : 0,
    'runtime_entry_count' => (int)($context['entry_count'] ?? 0),
    'decision_safe_entry_count' => (int)($context['decision_safe_entry_count'] ?? 0),
    'task_draft_safe_count' => $taskDraftSafeCount,
    'checks' => $checks,
];

fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit($failed === [] ? 0 : 1);
