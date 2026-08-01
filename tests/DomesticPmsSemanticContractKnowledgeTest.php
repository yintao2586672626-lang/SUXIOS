<?php
declare(strict_types=1);

namespace tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class DomesticPmsSemanticContractKnowledgeTest extends TestCase
{
    public function testKnowledgePackageSeparatesOrderFinancialMetricAndReconciliationSemantics(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $document = (string)file_get_contents(
            $root . '/docs/domestic_pms_business_day_order_reconciliation_semantic_contract_knowledge.md'
        );
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260730_y_write_domestic_pms_semantic_contract.sql'
        );
        $sourceInventory = (string)file_get_contents(
            $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/source-inventory.md'
        );
        $pmsReconciliation = (string)file_get_contents(
            $root . '/docs/pms-independent-source-reconciliation.md'
        );
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            '国内 PMS 经营日、订单状态与对账官方语义合同',
            '预订订单金额、夜审过房费、实收、应收、平台账单、核销/结算和会计收入是不同事实',
            '`unmapped_source_status`',
            '`room_charge_posted_amount`',
            '`accounting_revenue_amount`',
            '自然日',
            'PMS 营业日',
            '平均房价 = 客房收入 / 实际出租间夜',
            '实际出租间夜 / 可出租房间天数',
            '`external_only`',
            '`partial_writeoff`',
            'T+2 是该产品功能描述',
            '每七日提供账单',
            '两小时是已核验的上海地方规则',
            '个人信息：未包含',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        self::assertStringContainsString(
            "SET @pms_sem_seed_owner := 'suxios.domestic_pms_semantic_contract'",
            $migration
        );
        self::assertStringContainsString(
            "SET @pms_sem_source := 'revenue_operations_decision_support'",
            $migration
        );
        foreach ([
            "'pms_order_state_contract'",
            "'pms_financial_state_contract'",
            "'pms_business_day_night_audit_contract'",
            "'pms_rooms_metrics_contract'",
            "'pms_revenue_scope_contract'",
            "'pms_ota_prepaid_reconciliation_contract'",
            "'pms_commission_reconciliation_contract'",
            "'pms_payment_reconciliation_contract'",
            "'pms_reversal_audit_contract'",
            "'guest_identity_registration_contract'",
            "'pms_standardization_direction_contract'",
            "'pms_known_unknowns'",
            "'landing_status'",
        ] as $type) {
            self::assertStringContainsString($type, $migration);
        }
        self::assertStringContainsString(
            "'vendor_example_statement_cycle', 'every_7_days_in_described_scenario'",
            $migration
        );
        self::assertStringContainsString(
            "'vendor_feature_cycle', 'T_plus_2_automatic_payment_flow_reconciliation'",
            $migration
        );
        self::assertStringContainsString(
            "'knowledge_store_may_contain_guest_pii', false",
            $migration
        );
        self::assertSame(14, substr_count(
            $migration,
            'INSERT INTO `tmp_domestic_pms_semantic_chunks`'
        ));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);

        foreach ([
            'Foxhis XMS front desk and night audit help',
            'Foxhis XMS OTA, commission and payment reconciliation help',
            'Beijing key lodging statistics',
            'Ministry of Finance revenue standard',
            'National and Shanghai lodging registration rules',
            'CTHA and Shiji 2026 digitalization report',
        ] as $source) {
            self::assertStringContainsString($source, $sourceInventory);
        }
        self::assertStringContainsString(
            '`RevPAR = 同口径客房收入 ÷ 可出租房间天数/可售房晚`',
            $pmsReconciliation
        );
        self::assertStringNotContainsString(
            '`RevPAR ≈ 客房收入 ÷ 总房量`',
            $pmsReconciliation
        );
        self::assertStringContainsString(
            '订单金额、夜审过房费、支付实收、应收账、平台账单、核销/结算和会计收入必须分开保存',
            $pmsReconciliation
        );

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260730_y_write_domestic_pms_semantic_contract.sql',
            $initFull
        );
    }

    public function testRevenueKnowledgeReaderReturnsPmsContractsAndKeepsUnknowns(): void
    {
        $service = new RevenueOperationsKnowledgeService();
        $units = [[
            'unit_id' => 52,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '国内PMS经营日、订单状态与对账官方语义合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'lifecycle_status' => 'active',
            'known_knowns' => ['订单金额、房费、实收、应收、核销和会计收入必须拆分'],
            'known_unknowns' => ['当前营业日、夜审配置和字段映射未知'],
            'truth_profile_version' => '2026-07-30.1',
        ]];
        $chunks = [
            [
                'chunk_id' => 5201,
                'unit_id' => 52,
                'type' => 'pms_financial_state_contract',
                'content' => [
                    'scope' => 'pms_financial_semantics',
                    'evidence_level' => 'official_vendor_help_and_accounting_standard',
                    'source_refs' => [
                        'foxhis_xms_night_audit_2025',
                        'mof_revenue_standard_2017',
                    ],
                    'lifecycle_status' => 'active',
                    'semantic_keys' => [
                        ['key' => 'booking_order_amount'],
                        ['key' => 'accounting_revenue_amount'],
                    ],
                    'blocked_aliases' => [
                        'order_amount_as_revenue',
                        'payment_as_accounting_revenue',
                    ],
                ],
            ],
            [
                'chunk_id' => 5202,
                'unit_id' => 52,
                'type' => 'pms_known_unknowns',
                'content' => [
                    'scope' => 'generic_methodology',
                    'evidence_level' => 'explicit_unknowns_after_public_source_review',
                    'source_refs' => ['foxhis_xms_master_help'],
                    'lifecycle_status' => 'active',
                    'unknowns' => [
                        'current_business_date_night_audit_cutover_status_and_blockers',
                    ],
                    'missing_value_policy' => 'preserve_unknown_null_partial_or_blocked',
                ],
            ],
        ];

        $result = $service->buildContextFromRows($units, $chunks);

        self::assertSame('available', $result['status']);
        self::assertSame(2, $result['entry_count']);
        self::assertSame(
            'accounting_revenue_amount',
            $result['entries'][0]['content']['semantic_keys'][1]['key']
        );
        self::assertContains(
            'order_amount_as_revenue',
            $result['entries'][0]['content']['blocked_aliases']
        );
        self::assertSame(
            'preserve_unknown_null_partial_or_blocked',
            $result['entries'][1]['content']['missing_value_policy']
        );
        self::assertSame(
            ['当前营业日、夜审配置和字段映射未知'],
            $result['entries'][1]['known_unknowns']
        );
        self::assertStringContainsString(
            'never becomes current-hotel fact',
            $result['protected_boundary']
        );
    }
}
