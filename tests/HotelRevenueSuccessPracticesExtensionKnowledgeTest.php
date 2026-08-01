<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class HotelRevenueSuccessPracticesExtensionKnowledgeTest extends TestCase
{
    public function testKnowledgeExtensionAddsOnlyReviewedGapsAndProtectsExternalCases(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/hotel_revenue_success_practices_extension_knowledge.md';
        $migrationPath = $root . '/database/migrations/20260730_seed_hotel_revenue_success_practices_extension.sql';
        $recentMigrationPath = $root . '/database/migrations/20260730_update_hotel_revenue_success_practices_recent_sources.sql';
        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);
        self::assertFileExists($recentMigrationPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);
        $recentMigration = (string)file_get_contents($recentMigrationPath);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('酒店收益成功实践延伸知识', $document);
        self::assertStringContainsString('预订曲线与预测误差学习', $document);
        self::assertStringContainsString('稀缺库存的订单总价值与挤出判断', $document);
        self::assertStringContainsString('体验产品与总收益', $document);
        self::assertStringContainsString('去重结论', $document);
        self::assertStringContainsString('明确拒绝吸收', $document);
        self::assertStringContainsString('资料新鲜度纠偏（2026-07-30）', $document);
        self::assertStringContainsString('2025—2026 活跃证据目录', $document);
        self::assertStringContainsString('对账先于诊断', $document);
        self::assertStringContainsString('平台定价自主权保护', $document);
        self::assertStringContainsString('evidence_state = historical_superseded', $document);

        foreach ([
            'meituan_luoyang_hanfu_hotel_2024',
            'tripcom_wyndham_919_campaign_2021',
            'duetto_nh_hotel_group_2017',
            'duetto_nira_caledonia_2017',
        ] as $caseKey) {
            self::assertStringContainsString($caseKey, $document);
            self::assertStringContainsString($caseKey, $migration);
        }

        foreach ([
            'shiji_shenzhen_mgm_ota_reconciliation_2025',
            'shiji_poly_business_finance_data_2026',
            'tripcom_resorts_world_genting_api_2025',
            'meituan_hms_current_capability_2025',
            'siteminder_booking_trends_2025',
            'cloudbeds_independent_hotels_2026',
            'china_hotel_hci_2025_12',
            'duetto_jannah_2025',
            'mews_terrace_bay_2025',
        ] as $caseKey) {
            self::assertStringContainsString($caseKey, $document);
            self::assertStringContainsString($caseKey, $recentMigration);
        }

        self::assertStringContainsString(
            "SET @success_ext_source := 'revenue_operations_decision_support'",
            $migration
        );
        self::assertStringContainsString(
            "SET @success_ext_seed_owner := 'suxios.hotel_revenue_success_practices_extension'",
            $migration
        );
        self::assertStringContainsString("'booking_curve_forecast_learning'", $migration);
        self::assertStringContainsString("'constrained_inventory_value'", $migration);
        self::assertStringContainsString("'total_revenue_experience_product'", $migration);
        self::assertStringContainsString("'external_case_transfer_policy'", $migration);
        self::assertStringContainsString("'scope', 'generic_methodology'", $migration);
        self::assertSame(4, substr_count($migration, "'scope', 'case_reference'"));
        self::assertSame(4, substr_count($migration, "'requires_explicit_case_key', true"));
        self::assertSame(10, substr_count(
            $migration,
            'INSERT INTO `tmp_success_ext_seed_chunks`'
        ));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringContainsString(
            'existing_suxios_knowledge_extended_without_promoting_external_case_numbers_to_current_hotel_facts',
            $migration
        );
        self::assertSame(
            '2b5b2a92b16f8d87922e776917f369f4c86b6c616319faaa07c35a89667a3da1',
            hash_file('sha256', $migrationPath),
            'The already-applied seed migration must remain immutable; use a forward migration.'
        );

        self::assertStringContainsString(
            "SET @recent_seed_owner := 'suxios.hotel_revenue_success_practices_recent_sources'",
            $recentMigration
        );
        self::assertStringContainsString(
            "SET @prior_seed_owner := 'suxios.hotel_revenue_success_practices_extension'",
            $recentMigration
        );
        self::assertStringContainsString("'$.lifecycle_status', 'stale'", $recentMigration);
        self::assertStringContainsString("'$.evidence_state', 'historical_superseded'", $recentMigration);
        self::assertStringContainsString("'ota_pms_reconciliation_contract'", $recentMigration);
        self::assertStringContainsString("'data_standardization_exception_action'", $recentMigration);
        self::assertStringContainsString("'human_hotel_autonomy_guardrail'", $recentMigration);
        self::assertStringContainsString('https://www.samr.gov.cn/', $recentMigration);
        self::assertStringContainsString('2025_2026_sources_activated_without_promoting_capability_or_vendor_case_numbers_to_current_hotel_facts', $recentMigration);
        self::assertSame(9, substr_count($recentMigration, "'scope', 'case_reference'"));
        self::assertSame(9, substr_count($recentMigration, "'requires_explicit_case_key', true"));
        self::assertSame(18, substr_count(
            $recentMigration,
            'INSERT INTO `tmp_recent_success_practice_chunks`'
        ));
        self::assertSame(1, substr_count($recentMigration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $recentMigration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $recentMigration);

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260730_seed_hotel_revenue_success_practices_extension.sql',
            $initFull
        );
    }

    public function testSeedContractPreservesManualAndOlderVersionChunks(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260730_seed_hotel_revenue_success_practices_extension.sql'
        );

        $safeExistingJson = 'CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END';
        self::assertSame(6, substr_count($migration, $safeExistingJson));
        self::assertStringContainsString("'$.seed_version', @success_ext_version", $migration);
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringNotContainsString("JSON_EXTRACT(`existing`.`content`, '$.seed_", $migration);
        self::assertStringNotContainsString('ALTER TABLE `knowledge_chunks`', $migration);

        $rows = [
            [
                'type' => 'operator_note',
                'content' => ['note' => '门店人工补充'],
            ],
            [
                'type' => 'booking_curve_forecast_learning',
                'content' => [
                    'seed_owner' => 'suxios.hotel_revenue_success_practices_extension',
                    'seed_key' => 'revenue_operations_decision_support:酒店收益成功实践延伸知识:booking_curve_forecast_learning',
                    'seed_version' => '2026-07-30.0',
                ],
            ],
        ];
        $currentSeed = [
            'type' => 'booking_curve_forecast_learning',
            'content' => [
                'seed_owner' => 'suxios.hotel_revenue_success_practices_extension',
                'seed_key' => 'revenue_operations_decision_support:酒店收益成功实践延伸知识:booking_curve_forecast_learning',
                'seed_version' => '2026-07-30.1',
            ],
        ];
        $matchesCurrentSeed = static function (array $row) use ($currentSeed): bool {
            foreach (['seed_owner', 'seed_key', 'seed_version'] as $key) {
                if (($row['content'][$key] ?? null) !== $currentSeed['content'][$key]) {
                    return false;
                }
            }
            return true;
        };

        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeed;
        }
        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeed;
        }

        self::assertCount(3, $rows);
        self::assertSame('门店人工补充', $rows[0]['content']['note']);
        self::assertSame('2026-07-30.0', $rows[1]['content']['seed_version']);
        self::assertSame('2026-07-30.1', $rows[2]['content']['seed_version']);
    }

    public function testExistingRevenueKnowledgeReaderExcludesStaleCasesAndReturnsOnlyAnExplicitActiveKey(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 130,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '酒店收益成功实践延伸知识',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'known_knowns' => ['OTA成交进入收益分析前先完成订单金额结算与PMS入住事实对账'],
            'known_unknowns' => ['当前门店携程美团与PMS字段是否完成同经营日对账'],
            'truth_profile_version' => '2026-07-30.2',
        ]];
        $chunks = [];
        $genericTypes = [
            'booking_curve_forecast_learning',
            'constrained_inventory_value',
            'total_revenue_experience_product',
            'ota_pms_reconciliation_contract',
            'data_standardization_exception_action',
            'human_hotel_autonomy_guardrail',
            'external_case_transfer_policy',
        ];
        foreach ($genericTypes as $index => $type) {
            $chunks[] = [
                'chunk_id' => 1301 + $index,
                'unit_id' => 130,
                'type' => $type,
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'reviewed_method',
                    'source_refs' => ['reviewed_source_' . $index],
                    'lifecycle_status' => 'active',
                ],
            ];
        }

        $activeCaseKeys = [
            'shiji_shenzhen_mgm_ota_reconciliation_2025',
            'shiji_poly_business_finance_data_2026',
            'tripcom_resorts_world_genting_api_2025',
            'meituan_hms_current_capability_2025',
            'siteminder_booking_trends_2025',
            'cloudbeds_independent_hotels_2026',
            'china_hotel_hci_2025_12',
            'duetto_jannah_2025',
            'mews_terrace_bay_2025',
        ];
        foreach ($activeCaseKeys as $index => $caseKey) {
            $chunks[] = [
                'chunk_id' => 1311 + $index,
                'unit_id' => 130,
                'type' => 'external_success_case_' . $index,
                'content' => [
                    'scope' => RevenueOperationsKnowledgeService::CASE_SCOPE,
                    'case_key' => $caseKey,
                    'evidence_level' => 'external_case_reference',
                    'source_refs' => ['external_case_source_' . $index],
                    'lifecycle_status' => 'active',
                ],
            ];
        }

        foreach ([
            'meituan_luoyang_hanfu_hotel_2024',
            'tripcom_wyndham_919_campaign_2021',
            'duetto_nh_hotel_group_2017',
            'duetto_nira_caledonia_2017',
        ] as $index => $caseKey) {
            $chunks[] = [
                'chunk_id' => 1331 + $index,
                'unit_id' => 130,
                'type' => 'historical_external_success_case_' . $index,
                'content' => [
                    'scope' => RevenueOperationsKnowledgeService::CASE_SCOPE,
                    'case_key' => $caseKey,
                    'evidence_level' => 'historical_external_case_reference',
                    'source_refs' => ['historical_case_source_' . $index],
                    'lifecycle_status' => 'stale',
                    'evidence_state' => 'historical_superseded',
                ],
            ];
        }

        $default = $service->buildContextFromRows($units, $chunks);
        self::assertSame('available', $default['status']);
        self::assertSame(7, $default['entry_count']);
        self::assertSame(9, $default['excluded_case_reference_count']);
        self::assertSame($genericTypes, array_column($default['entries'], 'knowledge_type'));

        $withMgmCase = $service->buildContextFromRows($units, $chunks, [
            'case_key' => 'shiji_shenzhen_mgm_ota_reconciliation_2025',
        ]);
        self::assertSame('available', $withMgmCase['status']);
        self::assertSame(8, $withMgmCase['entry_count']);
        self::assertSame(8, $withMgmCase['excluded_case_reference_count']);
        self::assertSame(
            'shiji_shenzhen_mgm_ota_reconciliation_2025',
            $withMgmCase['entries'][7]['content']['case_key']
        );

        $withHistoricalCase = $service->buildContextFromRows($units, $chunks, [
            'case_key' => 'meituan_luoyang_hanfu_hotel_2024',
        ]);
        self::assertSame('partial', $withHistoricalCase['status']);
        self::assertSame(7, $withHistoricalCase['entry_count']);
        self::assertSame(
            'revenue_operations_case_reference_not_found',
            $withHistoricalCase['data_gaps'][0]['code']
        );

        $missingCase = $service->buildContextFromRows($units, $chunks, [
            'case_key' => 'not_a_real_case',
        ]);
        self::assertSame('partial', $missingCase['status']);
        self::assertSame(7, $missingCase['entry_count']);
        self::assertSame(
            'revenue_operations_case_reference_not_found',
            $missingCase['data_gaps'][0]['code']
        );
    }
}
