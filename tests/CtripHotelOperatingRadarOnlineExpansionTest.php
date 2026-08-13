<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class CtripHotelOperatingRadarOnlineExpansionTest extends TestCase
{
    public function testOnlineResearchMigrationPreservesSourcesCommitmentScopeAndRolloutGuards(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/ctrip_hotel_operating_radar_online_research_20260811.md';
        $migrationPath = $root . '/database/migrations/20260811_d_expand_ctrip_hotel_operating_radar_online_knowledge.sql';
        $scopeFixPath = $root . '/database/migrations/20260811_e_correct_ctrip_radar_ranking_disclosure_scope.sql';
        $runtimeVerifierPath = $root . '/scripts/verify_ctrip_hotel_operating_radar_knowledge.php';
        $strictVerifierPath = $root . '/scripts/verify_ctrip_hotel_operating_radar_migrations.php';
        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);
        self::assertFileExists($scopeFixPath);
        self::assertFileExists($runtimeVerifierPath);
        self::assertFileExists($strictVerifierPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);
        $scopeFix = (string)file_get_contents($scopeFixPath);
        $runtimeVerifier = (string)file_get_contents($runtimeVerifierPath);
        $strictVerifier = (string)file_get_contents($strictVerifierPath);

        self::assertSame(
            'AB721257E58A17ECF714586571D5BAB58F8AD95A95A315D2E0993568E655763B',
            strtoupper((string)hash_file('sha256', $documentPath))
        );

        foreach ([
            '五方面、十九项整改措施',
            'directionally_aligned_direct_causality_unverified',
            '本轮公开索引未找到',
            '取消不合理流量安排，建立新的流量分配机制',
            '免费开放数据中心 VIP 服务',
            '可确认“已公告”，不可替代独立完成验收',
            '《互联网平台价格行为规则》',
            '《网络交易平台规则监督管理办法》',
            '《互联网平台反垄断合规指引》',
            '一般性合规指引，本身不具有强制性',
            '该义务对象不是所有酒店，也不能证明普通推荐算法或雷达权重必须公开',
            '2025-11-03',
            'eBooking 实装验收清单',
            '技术服务费、佣金、营销费、保证金、订单储备金分别记录',
        ] as $expected) {
            self::assertStringContainsString($expected, $document);
        }

        foreach ([
            "SET @ctrip_radar_online_version := '2026-08-11.2'",
            "SET @ctrip_radar_online_seed_owner := 'suxios.ctrip_hotel_operating_radar_online_expansion'",
            'B5408CEF32FB096040984519122C95CB48BB541D11CBC74B6B990A8036E9415D',
            'https://www.samr.gov.cn/xw/zj/art/2026/art_46d2c74cbd7249f189622dd030e3c3a7.html',
            'https://jingji.cctv.com/2026/07/25/ARTI43yXusLYVp6aGHhJUNAS260725.shtml',
            'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/jjjzs/art/2025/art_eef66659c9624c5091bd3acd050b1710.html',
            'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fgs/art/2026/art_85b474fc5a08494bb60ca6a280b98d7d.html',
            'https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fldzfys/art/2026/art_ad10c5301fcb426cb839153ca9f5a274.html',
            'https://pages.ctrip.com/hotels/IBU/pages/hotelspecification.html',
            'ctrip_radar_online_source_audit_reference',
            'ctrip_rectification_19_measures_commitment_reference',
            'ctrip_radar_regulatory_operating_boundaries_fact',
            'ctrip_radar_public_rule_20251103_historical_reference',
            'ctrip_radar_live_rollout_verification_checklist',
            "'measure_count', 19",
            "'退还相关订单储备金122781078元'",
            "'legal_effect', 'general_guidance_not_mandatory'",
            "'current_verification_status', 'historical_page_only'",
            "'radar_direct_reference_status', 'not_mentioned_in_the_19_measures_announcement'",
            "'operation_task_creation'",
            "'automatic_pricing'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            "'$.external_write_authorized', false",
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        self::assertSame(5, substr_count($migration, 'INSERT INTO `tmp_ctrip_radar_online_chunks`'));
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringContainsString("'$.seed_owner'", $migration);
        self::assertStringContainsString("'$.seed_key'", $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);

        foreach ([
            "SET @ctrip_radar_scope_fix_version := '2026-08-11.3'",
            'AB721257E58A17ECF714586571D5BAB58F8AD95A95A315D2E0993568E655763B',
            "'$.price_rule.ranking_disclosure_scope', 'platform_merchants_participating_in_bidding'",
            "'$.price_rule.ranking_inference_guard', '不得据此推导普通推荐算法或雷达公式权重必须向所有酒店披露'",
            '排序规则告知义务的对象是参与竞价的平台内经营者',
        ] as $expected) {
            self::assertStringContainsString($expected, $scopeFix);
        }
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $scopeFix);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $scopeFix);
        self::assertStringContainsString('$actualRectificationItemCount = array_sum', $runtimeVerifier);
        self::assertStringContainsString('$actualRectificationGroups === $expectedRectificationGroups', $runtimeVerifier);

        self::assertStringContainsString(
            '20260811_d_expand_ctrip_hotel_operating_radar_online_knowledge.sql',
            $strictVerifier
        );
        self::assertStringContainsString(
            '20260811_e_correct_ctrip_radar_ranking_disclosure_scope.sql',
            $strictVerifier
        );
        self::assertStringContainsString(
            '20260811_f_absorb_ctrip_flow_rules_pdf_reference.sql',
            $strictVerifier
        );
        self::assertStringContainsString('$unitCount = (int)$databasePdo->query(', $strictVerifier);
        self::assertStringContainsString("'online_chunk_count' => 5", $strictVerifier);
        self::assertStringContainsString("'pdf_chunk_count' => 2", $strictVerifier);
        self::assertStringContainsString("'total_chunk_count' => 13", $strictVerifier);
        self::assertStringContainsString("'truth_profile_version' => '2026-08-11.4'", $strictVerifier);
    }

    public function testOnlineExpansionSeparatesOfficialFactsCommitmentsHistoryAndKnownUnknowns(): void
    {
        $unit = [[
            'unit_id' => 812,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => '携程酒店经营雷达图（规划期）五维知识合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'online expanded planned rollout reference',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-11 00:00:00',
            'review_due_at' => '2026-09-30 00:00:00',
            'known_knowns' => ['处罚、法规和整改公告可追溯'],
            'known_unknowns' => ['雷达原始发布页和门店实装未取得'],
            'truth_profile_version' => '2026-08-11.3',
        ]];

        $definitions = [
            [
                'type' => 'ctrip_radar_online_source_audit_reference',
                'scope' => 'ctrip_hotel_operating_radar_online_source_audit',
                'evidence_level' => 'bounded_public_search_audit',
                'evidence_grade' => 'C',
                'unknowns' => ['original_url', 'live_ebooking_availability'],
            ],
            [
                'type' => 'ctrip_rectification_19_measures_commitment_reference',
                'scope' => 'ctrip_antitrust_rectification_announcement',
                'evidence_level' => 'platform_announced_commitment_republished_by_cctv',
                'evidence_grade' => 'B',
            ],
            [
                'type' => 'ctrip_radar_regulatory_operating_boundaries_fact',
                'scope' => 'ctrip_radar_platform_regulatory_operating_boundaries',
                'evidence_level' => 'official_current_regulation_and_guidance',
                'evidence_grade' => 'A',
            ],
            [
                'type' => 'ctrip_radar_public_rule_20251103_historical_reference',
                'scope' => 'ctrip_hotel_public_rule_historical_reference',
                'evidence_level' => 'official_historical_public_rule_requires_current_verification',
                'evidence_grade' => 'B',
                'requires_current_verification' => true,
                'current_verification_status' => 'historical_page_only',
            ],
            [
                'type' => 'ctrip_radar_live_rollout_verification_checklist',
                'scope' => 'ctrip_radar_live_ebooking_verification_contract',
                'evidence_level' => 'derived_verification_contract_from_traceable_sources',
                'evidence_grade' => 'C',
            ],
        ];

        $chunks = [];
        foreach ($definitions as $index => $definition) {
            $content = [
                'scope' => $definition['scope'],
                'evidence_level' => $definition['evidence_level'],
                'evidence_grade' => $definition['evidence_grade'],
                'source_refs' => ['https://example.test/source/' . $index],
                'content_key' => 'ctrip_hotel_operating_radar:' . $definition['type'],
                'content_type' => 'platform_operating_knowledge_contract',
                'module_id' => 'ctrip_hotel_operating_radar',
                'platforms' => ['ctrip'],
                'roles' => ['owner', 'revenue_manager', 'ota_operator'],
                'scenes' => ['ctrip_knowledge_retrieval', 'radar_rollout_acceptance'],
                'reviewed_at' => '2026-08-11 00:00:00',
                'review_due_at' => '2026-09-30 00:00:00',
                'review_interval_days' => 50,
                'blocked_uses' => [
                    'hotel_score_calculation',
                    'ranking_prediction',
                    'operation_task_creation',
                    'automatic_pricing',
                    'automatic_ota_write',
                    'automatic_pms_write',
                ],
                'seed_owner' => 'suxios.ctrip_hotel_operating_radar_online_expansion',
                'seed_key' => 'ctrip_hotel_operating_radar:' . $definition['type'],
                'seed_version' => '2026-08-11.3',
                'lifecycle_status' => 'active',
                'contains_current_hotel_fact' => false,
                'contains_current_ota_fact' => false,
                'external_write_authorized' => false,
            ];
            foreach (['unknowns', 'requires_current_verification', 'current_verification_status'] as $optionalKey) {
                if (array_key_exists($optionalKey, $definition)) {
                    $content[$optionalKey] = $definition[$optionalKey];
                }
            }
            $chunks[] = [
                'chunk_id' => 8120 + $index,
                'unit_id' => 812,
                'type' => $definition['type'],
                'content' => $content,
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
        self::assertSame(5, $context['entry_count']);
        self::assertSame(5, $context['eligible_entry_count']);
        self::assertSame(2, $context['decision_safe_entry_count']);
        self::assertSame(1, $context['known_unknown_entry_count']);

        $gateStatuses = array_count_values(array_map(
            static fn(array $entry): string => (string)$entry['knowledge_gate']['status'],
            $context['entries']
        ));
        self::assertSame(2, $gateStatuses['approved'] ?? 0);
        self::assertSame(2, $gateStatuses['reference_only'] ?? 0);
        self::assertSame(1, $gateStatuses['known_unknown'] ?? 0);

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
