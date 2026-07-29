<?php
declare(strict_types=1);

namespace tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class MeituanOfficialRulesSemanticContractKnowledgeTest extends TestCase
{
    public function testKnowledgePackageContainsOfficialRulesUnknownsAndLegacyCorrections(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $document = (string)file_get_contents(
            $root . '/docs/meituan_official_rules_semantic_contract_knowledge.md'
        );
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260730_x_write_meituan_official_rules_semantic_contract.sql'
        );
        $semanticLayer = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/semantic-layer.md'
        );
        $sourceInventory = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md'
        );
        $reviewKnowledge = (string)file_get_contents(
            $root . '/docs/ota_review_platform_rules_knowledge.md'
        );
        $metricKnowledge = (string)file_get_contents(
            $root . '/docs/hotel_ota_metric_professional_knowledge.md'
        );
        $mindmapKnowledge = (string)file_get_contents(
            $root . '/docs/ota_operation_mindmap_knowledge.md'
        );
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            '美团酒店评价与经营规则官方语义合同',
            '美团评价与大众点评评价必须拆开',
            '评价用户专业度、评价质量、评价时间、诚信度和评价数量',
            '每条评价限申诉 1 次',
            '3 个工作日内',
            '不得以礼物、折扣、抽奖等利益换评',
            '拒单订单量 / 支付订单量',
            'official_versioned_rule',
            'quarantined_legacy_conflict',
            '最多 180 间夜',
            '最多 29 间夜',
            'product_capability_claim',
            '当前 HMS 租户模块',
            '不自动点击提交',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        self::assertStringContainsString(
            "SET @meituan_sem_seed_owner := 'suxios.meituan_official_rules_semantic_contract'",
            $migration
        );
        self::assertStringContainsString(
            "SET @meituan_sem_source := 'revenue_operations_decision_support'",
            $migration
        );
        self::assertStringContainsString("'meituan_review_scope_rating_contract'", $migration);
        self::assertStringContainsString("'meituan_hotel_review_complaint_contract'", $migration);
        self::assertStringContainsString("'meituan_hotel_service_rule_2023_contract'", $migration);
        self::assertStringContainsString("'meituan_hms_product_capability_contract'", $migration);
        self::assertStringContainsString("'meituan_legacy_direct_connect_conflict'", $migration);
        self::assertStringContainsString("'legacy_meituan_rejection_denominator_replaced'", $migration);
        self::assertStringContainsString("'legacy_meituan_hos_formula_quarantined'", $migration);
        self::assertStringContainsString("'legacy_incentivized_review_advice_removed'", $migration);
        self::assertStringContainsString("'rejected_orders / paid_orders'", $migration);
        self::assertStringContainsString("'current_formula_status', 'unverified'", $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertSame(11, substr_count(
            $migration,
            'INSERT INTO `tmp_meituan_official_semantic_chunks`'
        ));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);

        self::assertStringContainsString(
            'the reviewed DataCenter module whose official help defines it as deduplicated viewers',
            $semanticLayer
        );
        self::assertStringContainsString(
            'ctrip_datacenter_list_exposure_uv',
            $semanticLayer
        );
        self::assertStringContainsString(
            'Meituan evaluation general and detailed rules',
            $sourceInventory
        );
        self::assertStringContainsString(
            'Meituan legacy hotel direct-connect FAQ',
            $sourceInventory
        );
        self::assertStringContainsString(
            'Ctrip DataCenter course',
            $sourceInventory
        );

        self::assertStringContainsString(
            '美团（2025-10-31 现行评价规则）',
            $reviewKnowledge
        );
        self::assertStringContainsString(
            '大众点评（独立规则，待复核）',
            $reviewKnowledge
        );
        self::assertStringNotContainsString(
            '少于 5 条或少于 40 条展示阈值',
            $reviewKnowledge
        );
        self::assertStringNotContainsString(
            '仅支持半年内点评申诉',
            $reviewKnowledge
        );
        self::assertStringContainsString(
            '美团酒店服务规则拒单率（2023 版）',
            $metricKnowledge
        );
        self::assertStringNotContainsString(
            '`rejected_orders / created_orders`',
            $metricKnowledge
        );
        self::assertStringContainsString(
            '美团酒店（不含大众点评）',
            $mindmapKnowledge
        );
        self::assertStringNotContainsString(
            '活动或礼物激励，但不得诱导虚假评价',
            $mindmapKnowledge
        );

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260730_x_write_meituan_official_rules_semantic_contract.sql',
            $initFull
        );
    }

    public function testRevenueKnowledgeReaderReturnsMeituanContractsWithoutCurrentHotelFacts(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $unit = [[
            'unit_id' => 51,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '美团酒店评价与经营规则官方语义合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'known_knowns' => ['美团评价与大众点评必须拆分'],
            'known_unknowns' => ['当前HOS算法与账户权限未知'],
            'truth_profile_version' => '2026-07-30.1',
        ]];
        $chunks = [
            [
                'chunk_id' => 5101,
                'unit_id' => 51,
                'type' => 'meituan_review_scope_rating_contract',
                'content' => [
                    'scope' => 'meituan_review_metric_semantics',
                    'evidence_level' => 'official_current_rule',
                    'source_refs' => ['meituan_review_general_v4_2025'],
                    'lifecycle_status' => 'active',
                    'platform_scope' => 'meituan',
                    'explicitly_excluded_platforms' => ['dianping'],
                    'rating_contract' => [
                        'exact_weights' => 'unknown_private_algorithm',
                    ],
                ],
            ],
            [
                'chunk_id' => 5102,
                'unit_id' => 51,
                'type' => 'meituan_legacy_direct_connect_conflict',
                'content' => [
                    'scope' => 'version_conflict',
                    'evidence_level' => 'official_legacy_page_internal_conflict',
                    'source_refs' => ['meituan_hotel_direct_connect_faq_legacy'],
                    'lifecycle_status' => 'active',
                    'source_lifecycle' => 'quarantined',
                    'conflicts' => [[
                        'claim' => 'maximum_length_of_stay',
                        'decision_status' => 'unresolved_do_not_use',
                    ]],
                ],
            ],
        ];

        $result = $service->buildContextFromRows($unit, $chunks);

        self::assertSame('available', $result['status']);
        self::assertSame(2, $result['entry_count']);
        self::assertSame(
            ['dianping'],
            $result['entries'][0]['content']['explicitly_excluded_platforms']
        );
        self::assertSame(
            'unknown_private_algorithm',
            $result['entries'][0]['content']['rating_contract']['exact_weights']
        );
        self::assertSame(
            'quarantined',
            $result['entries'][1]['content']['source_lifecycle']
        );
        self::assertSame(
            'unresolved_do_not_use',
            $result['entries'][1]['content']['conflicts'][0]['decision_status']
        );
        self::assertSame(
            ['当前HOS算法与账户权限未知'],
            $result['entries'][1]['known_unknowns']
        );
        self::assertStringContainsString(
            'never becomes current-hotel fact',
            $result['protected_boundary']
        );
    }
}
