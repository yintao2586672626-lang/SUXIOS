<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class CtripHotelOperatingRadarKnowledgeTest extends TestCase
{
    public function testKnowledgePackagePreservesFiveDimensionsSourceFingerprintsAndRolloutBoundaries(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/ctrip_hotel_operating_radar_knowledge.md';
        $compatibilityPath = $root . '/database/migrations/20260811_0_expand_knowledge_chunk_type_for_radar.sql';
        $migrationPath = $root . '/database/migrations/20260811_absorb_ctrip_hotel_operating_radar_knowledge.sql';
        $repairPath = $root . '/database/migrations/20260811_b_repair_ctrip_hotel_operating_radar_chunk_type.sql';
        $restorePath = $root . '/database/migrations/20260811_c_restore_ctrip_hotel_operating_radar_seed_identity.sql';
        $strictVerifierPath = $root . '/scripts/verify_ctrip_hotel_operating_radar_migrations.php';
        self::assertFileExists($documentPath);
        self::assertFileExists($compatibilityPath);
        self::assertFileExists($migrationPath);
        self::assertFileExists($repairPath);
        self::assertFileExists($restorePath);
        self::assertFileExists($strictVerifierPath);

        $document = (string)file_get_contents($documentPath);
        $compatibility = (string)file_get_contents($compatibilityPath);
        $migration = (string)file_get_contents($migrationPath);
        $repair = (string)file_get_contents($repairPath);
        $restore = (string)file_get_contents($restorePath);
        $strictVerifier = (string)file_get_contents($strictVerifierPath);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertSame(
            'E2A4FC333E47BC8D6F1B8E572ED44857E2A37872E1EA5DFC65F997DBCA6E3D4F',
            strtoupper((string)hash_file('sha256', $documentPath))
        );

        foreach ([
            '携程酒店经营雷达图（规划期）五维知识合同',
            "SET @ctrip_radar_seed_owner := 'suxios.ctrip_hotel_operating_radar_knowledge'",
            "SET @ctrip_radar_version := '2026-08-11.1'",
            'E2A4FC333E47BC8D6F1B8E572ED44857E2A37872E1EA5DFC65F997DBCA6E3D4F',
            'D09793D1C72F785E289EEDE37F265ACAB89F59A6050AD2A48D8AE8BD098D937C',
            'A0970684ABA0154389CDA502230586D1523C544C4AD74B6409B41CCEAFF05025',
            '0835567A1C2C5052054FCEE5F806736A9F5468C6DF15B7512842DE2FCF204EAB',
            "'rollout_status', 'planned_gradual_rollout'",
            "'preview_timing_status', 'source_says_expected_in_september_year_unknown'",
            "'radar_penalty_causal_link_status', 'causal_link_unverified'",
            '国市监处罚〔2026〕29号',
            'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html',
            "'$.module_id', 'ctrip_hotel_operating_radar'",
            "'hotel_score_calculation'",
            "'ranking_prediction'",
            "'operation_task_creation'",
            "'automatic_pricing'",
            "'$.external_write_authorized', false",
            '图片/视频质量',
            '设施描述完整',
            '酒店政策准确',
            '信息真实',
            '价格合理',
            '房态准确/充足',
            '取消政策灵活',
            '订单即时确认',
            '用户投诉',
            '点评分',
            '用户权益',
            '六大类服务缺陷',
            '历史订单与销售额',
            '历史成交率',
            '避免虚假交易和恶意刷单',
            '合理的技术服务费',
            '无逾期账单',
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        foreach ([
            '信息分',
            '友好度',
            '品质度',
            '欢迎度',
            '平台技术服务费',
            '信息浏览',
            '预订决策',
            '到店入住',
            '长期价值',
            '单一维度不决定最终结果',
            'causal_link_unverified',
            'planned_gradual_rollout',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        foreach ([
            'ctrip_radar_source_scope_and_rollout_reference',
            'ctrip_antitrust_regulatory_context_fact',
            'ctrip_radar_model_principles_reference',
            'ctrip_radar_five_dimension_semantics_reference',
            'ctrip_radar_user_journey_and_platform_focus_reference',
            'ctrip_radar_usage_and_rollout_guard',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }

        self::assertSame(6, substr_count($migration, 'INSERT INTO `tmp_ctrip_radar_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertGreaterThan(50, strlen('ctrip_radar_user_journey_and_platform_focus_reference'));
        self::assertLessThanOrEqual(80, strlen('ctrip_radar_user_journey_and_platform_focus_reference'));
        self::assertStringContainsString('MODIFY COLUMN `type` VARCHAR(80)', $compatibility);
        self::assertStringContainsString("`type` = 'ctrip_radar_user_journey_reference'", $repair);
        self::assertStringContainsString('ctrip_radar_user_journey_and_platform_focus_refere', $repair);
        self::assertStringContainsString(
            "`type` = 'ctrip_radar_user_journey_and_platform_focus_reference'",
            $restore
        );
        self::assertStringContainsString(
            "'$.seed_key', 'ctrip_hotel_operating_radar:ctrip_radar_user_journey_and_platform_focus_reference'",
            $restore
        );
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $compatibility);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $repair);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $repair);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $restore);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $restore);
        self::assertStringContainsString('STRICT_TRANS_TABLES', $strictVerifier);
        self::assertStringContainsString('$runSequence();', $strictVerifier);
        self::assertSame(2, substr_count($strictVerifier, '$runSequence();'));
        self::assertStringContainsString("'chunk_count' => 6", $strictVerifier);
        self::assertStringContainsString("'distinct_seed_key_count' => 6", $strictVerifier);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260811_absorb_ctrip_hotel_operating_radar_knowledge.sql',
            $initFull
        );
    }

    public function testStructuredReaderKeepsPlannedRadarReferenceOnlyWhileRetainingVerifiedPenaltyFact(): void
    {
        $unit = [[
            'unit_id' => 811,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '携程酒店经营雷达图（规划期）五维知识合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'planned rollout reference',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-11 00:00:00',
            'review_due_at' => '2026-09-30 00:00:00',
            'known_knowns' => ['材料描述五维模型'],
            'known_unknowns' => ['权重公式和当前开放范围未知'],
            'truth_profile_version' => '2026-08-11.1',
        ]];

        $types = [
            'ctrip_radar_source_scope_and_rollout_reference',
            'ctrip_antitrust_regulatory_context_fact',
            'ctrip_radar_model_principles_reference',
            'ctrip_radar_five_dimension_semantics_reference',
            'ctrip_radar_user_journey_and_platform_focus_reference',
            'ctrip_radar_usage_and_rollout_guard',
        ];
        $chunks = [];
        foreach ($types as $index => $type) {
            $isRegulatoryFact = $type === 'ctrip_antitrust_regulatory_context_fact';
            $chunks[] = [
                'chunk_id' => 8110 + $index,
                'unit_id' => 811,
                'type' => $type,
                'content' => [
                    'scope' => $isRegulatoryFact
                        ? 'ctrip_antitrust_regulatory_context'
                        : 'ctrip_hotel_operating_radar_reference',
                    'evidence_level' => $isRegulatoryFact
                        ? 'official_current_penalty_decision'
                        : 'user_provided_branded_reference',
                    'evidence_grade' => $isRegulatoryFact ? 'A' : 'C',
                    'source_refs' => [$isRegulatoryFact
                        ? 'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html'
                        : 'repo-doc://docs/ctrip_hotel_operating_radar_knowledge.md#sha256=E2A4FC33'],
                    'content_key' => 'ctrip_hotel_operating_radar:' . $type,
                    'content_type' => 'platform_operating_knowledge_contract',
                    'module_id' => 'ctrip_hotel_operating_radar',
                    'platforms' => ['ctrip'],
                    'roles' => ['owner', 'revenue_manager', 'ota_operator'],
                    'scenes' => ['ctrip_knowledge_retrieval', 'future_radar_field_mapping'],
                    'reviewed_at' => '2026-08-11 00:00:00',
                    'review_due_at' => '2026-09-30 00:00:00',
                    'review_interval_days' => 50,
                    'blocked_uses' => [
                        'hotel_score_calculation',
                        'ranking_prediction',
                        'operation_task_creation',
                        'automatic_pricing',
                        'automatic_ota_write',
                    ],
                    'seed_owner' => 'suxios.ctrip_hotel_operating_radar_knowledge',
                    'seed_key' => 'ctrip_hotel_operating_radar:' . $type,
                    'seed_version' => '2026-08-11.1',
                    'lifecycle_status' => 'active',
                    'contains_current_hotel_fact' => false,
                    'contains_current_ota_fact' => false,
                    'external_write_authorized' => false,
                ],
            ];
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
        self::assertSame(1, $context['unit_count']);
        self::assertSame(1, $context['selected_unit_count']);
        self::assertSame(6, $context['entry_count']);
        self::assertSame(6, $context['eligible_entry_count']);
        self::assertSame(1, $context['decision_safe_entry_count']);
        self::assertSame(0, $context['excluded_decision_gate_count']);

        $gateStatuses = array_count_values(array_map(
            static fn(array $entry): string => (string)$entry['knowledge_gate']['status'],
            $context['entries']
        ));
        self::assertSame(1, $gateStatuses['approved'] ?? 0);
        self::assertSame(5, $gateStatuses['reference_only'] ?? 0);

        foreach ($context['entries'] as $entry) {
            self::assertSame(0, $entry['unit_hotel_id']);
            self::assertSame('ctrip_hotel_operating_radar', $entry['module_id']);
            self::assertTrue($entry['knowledge_gate']['retrieval_safe']);
            self::assertFalse($entry['knowledge_gate']['task_draft_safe']);
            self::assertFalse($entry['content']['contains_current_hotel_fact']);
            self::assertFalse($entry['content']['contains_current_ota_fact']);
            self::assertFalse($entry['content']['external_write_authorized']);
        }
    }
}
