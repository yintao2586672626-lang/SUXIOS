<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class CtripHotelFlowRulesPdfKnowledgeTest extends TestCase
{
    public function testPdfReferencePreservesSourceIdentityPageConflictsRoutingAndExecutionGuards(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/ctrip_hotel_flow_new_rules_pdf_20260811.md';
        $migrationPath = $root . '/database/migrations/20260811_f_absorb_ctrip_flow_rules_pdf_reference.sql';
        $runtimeVerifierPath = $root . '/scripts/verify_ctrip_hotel_operating_radar_knowledge.php';
        $strictVerifierPath = $root . '/scripts/verify_ctrip_hotel_operating_radar_migrations.php';
        $commissionMigrationPath = $root . '/database/migrations/20260809_b_absorb_ctrip_commission_reform_watch.sql';

        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);
        self::assertFileExists($runtimeVerifierPath);
        self::assertFileExists($strictVerifierPath);
        self::assertFileExists($commissionMigrationPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);
        $runtimeVerifier = (string)file_get_contents($runtimeVerifierPath);
        $strictVerifier = (string)file_get_contents($strictVerifierPath);
        $commissionMigration = (string)file_get_contents($commissionMigrationPath);

        self::assertSame(
            'A8056DB215C068C5223346729408A2544E21E1CB229B435D17346C1E97CC55FC',
            strtoupper((string)hash_file('sha256', $documentPath))
        );

        foreach ([
            '第三方待核验',
            '2,153,103',
            '6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D',
            'WPS 演示',
            '舒克',
            '`shuke` 水印',
            '文件名中的“2026.8”和 PDF 元数据只能说明该文件的命名及形成时间',
            '`4.7` 分为流量分水岭',
            '流量权重 = 曝光价值 × 成交能力',
            '`10%-15%` 佣金自选区间',
            '`12%` 携程优选门槛',
            '自然流量池与广告流量池',
            '“服务度”与图中“服务费”并存',
            '五种“能力”不是第 8 页雷达五维',
            '创作者激励',
            '携程佣金与流量排序新规观察（2026-08）',
            '不重复生成第二套佣金规则，也不改变原 claim 的验证状态',
            '2026-06-28 的授权 eBooking 云梯页面与接口历史观测',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        foreach ([
            "SET @ctrip_flow_pdf_version := '2026-08-11.4'",
            "SET @ctrip_flow_pdf_seed_owner := 'suxios.ctrip_flow_rules_pdf_20260811'",
            'A8056DB215C068C5223346729408A2544E21E1CB229B435D17346C1E97CC55FC',
            '6FFA5FB517F418F11E78C6AD221493C83DD94AC0D90B7AC07D25173683F69A7D',
            "'source_size_bytes', 2153103",
            "'page_count', 18",
            "'visually_inspected_page_count', 18",
            "'official_publisher_status', 'not_established'",
            "'scope', 'known_unknown'",
            "'scope', 'conflict'",
            "'evidence_grade', 'D'",
            'ctrip_flow_rules_pdf_source_audit_reference',
            'ctrip_flow_rules_pdf_conflict_reference',
            "'claim', '点评4.7分成为流量分水岭'",
            "'claim', '流量权重等于曝光价值乘以成交能力'",
            "'status', 'unverified_formula_prohibited'",
            "'module_id', 'ctrip_commission_reform_watch'",
            "'routing_rule', 'reuse_existing_unverified_claims_without_duplicate_or_evidence_upgrade'",
            "'status', 'historically_observed_before_pdf_not_current_state'",
            "'hotel_score_calculation'",
            "'traffic_weight_calculation'",
            "'ranking_prediction'",
            "'commission_change'",
            "'operation_task_creation'",
            "'automatic_pricing'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            "'$.external_write_authorized', false",
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        self::assertSame(2, substr_count($migration, 'INSERT INTO `tmp_ctrip_flow_pdf_chunks`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
        self::assertStringContainsString('ctrip_reform_claim_01', $commissionMigration);
        self::assertStringContainsString('ctrip_reform_claim_06', $commissionMigration);
        self::assertStringContainsString(
            '20260811_f_absorb_ctrip_flow_rules_pdf_reference.sql',
            $strictVerifier
        );
        self::assertStringContainsString("'pdf_chunk_count' => 2", $strictVerifier);
        self::assertStringContainsString("'total_chunk_count' => 13", $strictVerifier);
        self::assertStringContainsString("'truth_profile_version' => '2026-08-11.4'", $strictVerifier);
        self::assertStringContainsString('$expectedPdfSourceSha256', $runtimeVerifier);
        self::assertStringContainsString('third_party_pdf_conflicts_and_formula_guard_read_back', $runtimeVerifier);
    }

    public function testThirdPartyPdfChunksRemainKnownUnknownAndCannotCreateTasks(): void
    {
        $unit = [[
            'unit_id' => 813,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '携程酒店经营雷达图（规划期）五维知识合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'third-party PDF conflict reference',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-11 00:00:00',
            'review_due_at' => '2026-08-18 00:00:00',
            'known_knowns' => ['PDF identity and page count preserved'],
            'known_unknowns' => ['formula and current rollout unverified'],
            'truth_profile_version' => '2026-08-11.4',
        ]];

        $types = [
            'ctrip_flow_rules_pdf_source_audit_reference' => 'known_unknown',
            'ctrip_flow_rules_pdf_conflict_reference' => 'conflict',
        ];
        $chunks = [];
        $index = 0;
        foreach ($types as $type => $scope) {
            $chunks[] = [
                'chunk_id' => 8130 + $index,
                'unit_id' => 813,
                'type' => $type,
                'content' => [
                    'scope' => $scope,
                    'evidence_level' => 'user_provided_unverified_third_party_training_deck',
                    'evidence_grade' => 'D',
                    'source_refs' => [
                        'user-file://携程流量新规则2026.8.pdf#sha256=6FFA5FB5',
                    ],
                    'unknowns' => ['official_publisher', 'effective_date'],
                    'conflict_status' => $scope === 'conflict' ? 'unresolved' : '',
                    'requires_current_verification' => true,
                    'current_verification_status' => 'not_verified',
                    'content_key' => 'ctrip_hotel_operating_radar:' . $type,
                    'content_type' => 'platform_operating_knowledge_conflict_reference',
                    'module_id' => 'ctrip_hotel_operating_radar',
                    'platforms' => ['ctrip'],
                    'roles' => ['owner', 'revenue_manager', 'ota_operator'],
                    'scenes' => ['source_conflict_review'],
                    'reviewed_at' => '2026-08-11 00:00:00',
                    'review_due_at' => '2026-08-18 00:00:00',
                    'review_interval_days' => 7,
                    'blocked_uses' => [
                        'hotel_score_calculation',
                        'traffic_weight_calculation',
                        'ranking_prediction',
                        'commission_change',
                        'operation_task_creation',
                        'automatic_pricing',
                        'automatic_ota_write',
                        'automatic_pms_write',
                    ],
                    'seed_owner' => 'suxios.ctrip_flow_rules_pdf_20260811',
                    'seed_key' => 'ctrip_hotel_operating_radar:' . $type,
                    'seed_version' => '2026-08-11.4',
                    'lifecycle_status' => 'active',
                    'contains_current_hotel_fact' => false,
                    'contains_current_ota_fact' => false,
                    'external_write_authorized' => false,
                ],
            ];
            $index++;
        }

        $context = (new RevenueOperationsKnowledgeService())->buildContextFromRows(
            $unit,
            $chunks,
            [
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'module_id' => 'ctrip_hotel_operating_radar',
                'limit' => 10,
                'as_of' => '2026-08-11 12:00:00',
            ]
        );

        self::assertSame('available', $context['status']);
        self::assertSame(2, $context['entry_count']);
        self::assertSame(2, $context['eligible_entry_count']);
        self::assertSame(0, $context['decision_safe_entry_count']);
        self::assertSame(2, $context['known_unknown_entry_count']);

        foreach ($context['entries'] as $entry) {
            self::assertSame('known_unknown', $entry['knowledge_gate']['status']);
            self::assertSame('D', $entry['knowledge_gate']['evidence_grade']);
            self::assertTrue($entry['knowledge_gate']['retrieval_safe']);
            self::assertFalse($entry['knowledge_gate']['decision_safe']);
            self::assertFalse($entry['knowledge_gate']['task_draft_safe']);
            self::assertFalse($entry['content']['external_write_authorized']);
        }
    }
}
