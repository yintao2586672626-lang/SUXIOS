<?php
declare(strict_types=1);

namespace tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class CtripOfficialHelpSemanticContractKnowledgeTest extends TestCase
{
    public function testKnowledgePackageContainsOfficialSemanticsAndVersionConflict(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $document = (string)file_get_contents(
            $root . '/docs/ctrip_official_help_semantic_contract_knowledge.md'
        );
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260730_write_ctrip_official_help_semantic_contract.sql'
        );
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            '携程点评与数据中心官方帮助语义合同',
            'ctrip_datacenter_list_exposure_uv',
            'ctrip_datacenter_overview_booking_conversion',
            'ctrip_app_funnel_exposure_conversion',
            'ctrip_app_funnel_order_page_conversion',
            'ctrip_app_funnel_submit_conversion',
            'version_conflict',
            '30 天',
            '90 天',
            'APP 漏斗的订单提交人数是行为埋点',
            '全平台订单、销售额或在店间夜可能包含携程、去哪儿和同程旅行',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        self::assertStringContainsString(
            "SET @ctrip_sem_seed_owner := 'suxios.ctrip_official_help_semantic_contract'",
            $migration
        );
        self::assertStringContainsString(
            "SET @ctrip_sem_source := 'revenue_operations_decision_support'",
            $migration
        );
        self::assertStringContainsString("'ctrip_review_feedback_version_conflict'", $migration);
        self::assertStringContainsString("'decision_status', 'unresolved_until_live_help_verified'", $migration);
        self::assertStringContainsString("'performance_reporting_allowed', false", $migration);
        self::assertStringContainsString("'full_platform_as_ctrip_only'", $migration);
        self::assertStringContainsString("'legacy_ctrip_exposure_assumption_replaced'", $migration);
        self::assertStringContainsString("'historical_formula_bound_to_reviewed_ctrip_semantics'", $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertSame(9, substr_count(
            $migration,
            'INSERT INTO `tmp_ctrip_official_semantic_chunks`'
        ));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260730_write_ctrip_official_help_semantic_contract.sql',
            $initFull
        );
    }

    public function testRevenueKnowledgeReaderReturnsSemanticContractsWithoutCaseKey(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $unit = [[
            'unit_id' => 50,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '携程点评与数据中心官方帮助语义合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'known_knowns' => ['列表页曝光是去重浏览人数'],
            'known_unknowns' => ['异常点评自助反馈期限需实时核验'],
            'truth_profile_version' => '2026-07-30.1',
        ]];
        $chunks = [
            [
                'chunk_id' => 5001,
                'unit_id' => 50,
                'type' => 'ctrip_datacenter_overview_contract',
                'content' => [
                    'scope' => 'ctrip_datacenter_metric_semantics',
                    'evidence_level' => 'official_public_course_cross_checked_with_localization_snapshot',
                    'source_refs' => ['ctrip_datacenter_course_2562_2024'],
                    'lifecycle_status' => 'active',
                    'metrics' => [[
                        'semantic_key' => 'ctrip_datacenter_list_exposure_uv',
                    ]],
                ],
            ],
            [
                'chunk_id' => 5002,
                'unit_id' => 50,
                'type' => 'ctrip_review_feedback_version_conflict',
                'content' => [
                    'scope' => 'version_conflict',
                    'evidence_level' => 'two_official_surface_versions_conflict_live_recheck_required',
                    'source_refs' => ['ctrip_review_course_143_2025_page_39'],
                    'lifecycle_status' => 'active',
                    'decision_status' => 'unresolved_until_live_help_verified',
                ],
            ],
        ];

        $result = $service->buildContextFromRows($unit, $chunks);

        self::assertSame('available', $result['status']);
        self::assertSame(2, $result['entry_count']);
        self::assertSame(
            'ctrip_datacenter_list_exposure_uv',
            $result['entries'][0]['content']['metrics'][0]['semantic_key']
        );
        self::assertSame(
            'unresolved_until_live_help_verified',
            $result['entries'][1]['content']['decision_status']
        );
        self::assertSame(
            ['异常点评自助反馈期限需实时核验'],
            $result['entries'][1]['known_unknowns']
        );
    }
}
