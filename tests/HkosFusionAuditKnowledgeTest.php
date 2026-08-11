<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class HkosFusionAuditKnowledgeTest extends TestCase
{
    public function testMigrationPreservesSourceFingerprintAndTruthBoundaries(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $path = $root . '/database/migrations/20260809_absorb_hkos_fusion_audit_knowledge.sql';
        self::assertFileExists($path);
        $migration = (string)file_get_contents($path);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            'OTA三方融合审计与产品语义决策合同',
            "SET @hkos_audit_seed_owner := 'suxios.hkos_fusion_audit_knowledge'",
            "SET @hkos_audit_version := '2026-08-09.1'",
            '2C3520DFF13517B5717D9B6D93F308E904E309441F5F1F98537CC7AE8960D276',
            "'verification_status', 'user_provided_unverified'",
            "'supporting_documents_status', 'not_provided_in_current_suxios_task'",
            "'dimension_inventory_status', '170_item_inventory_not_provided_in_current_suxios_task'",
            "'evidence_grade', 'C'",
            "'$.module_id', 'ota_product_semantics_audit'",
            'ALREADY_COVERED',
            'ACCEPT_NOW',
            'CONDITIONAL',
            'guest_remark',
            'confirmation_remark',
            'platform_tip',
            'expected_arrival_time_raw',
            'doNotExecuteAutomatically',
            'MIP-0',
            'current_hotel_fact',
            'current_ota_fact',
            'operation_task_creation',
            'automatic_ota_write',
            "'$.external_write_authorized', false",
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        foreach ([
            'hkos_fusion_audit_source_scope_reference',
            'evidence_status_and_dimension_governance_contract',
            'semantic_privacy_and_causality_guard',
            'guest_request_intelligence_contract',
            'minimal_implementation_and_signoff_contract',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }

        self::assertSame(5, substr_count($migration, 'INSERT INTO `tmp_hkos_audit_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('F:/wx/', $migration);
        self::assertStringNotContainsString('F:\\wx\\', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString('20260809_absorb_hkos_fusion_audit_knowledge.sql', $initFull);
    }

    public function testStructuredReaderMakesThePromptRetrievableButNotDecisionOrTaskSafe(): void
    {
        $unit = [
            'unit_id' => 809,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'OTA三方融合审计与产品语义决策合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'global governance reference',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-08-09 00:00:00',
            'review_due_at' => '2027-02-05 00:00:00',
            'known_knowns' => ['候选维度必须逐项治理'],
            'known_unknowns' => ['170项维度清单未随本次任务提供'],
            'truth_profile_version' => '2026-08-09.1',
        ];
        $types = [
            'hkos_fusion_audit_source_scope_reference',
            'evidence_status_and_dimension_governance_contract',
            'semantic_privacy_and_causality_guard',
            'guest_request_intelligence_contract',
            'minimal_implementation_and_signoff_contract',
        ];
        $chunks = [];
        foreach ($types as $index => $type) {
            $chunks[] = [
                'chunk_id' => 8090 + $index,
                'unit_id' => 809,
                'type' => $type,
                'content' => [
                    'scope' => 'global_governance_reference',
                    'evidence_level' => 'user_provided_governance_reference',
                    'evidence_grade' => 'C',
                    'source_refs' => ['user-file://hkos-prompt#sha256=2C3520DF'],
                    'content_key' => 'ota_product_semantics_audit:' . $type,
                    'content_type' => 'governance_contract',
                    'module_id' => 'ota_product_semantics_audit',
                    'platforms' => ['ctrip', 'meituan', 'suxios_internal'],
                    'roles' => ['owner', 'product_owner', 'knowledge_reviewer'],
                    'scenes' => ['dimension_governance', 'guest_request_intelligence_design'],
                    'reviewed_at' => '2026-08-09 00:00:00',
                    'review_due_at' => '2027-02-05 00:00:00',
                    'review_interval_days' => 180,
                    'blocked_uses' => [
                        'operation_task_creation',
                        'operation_execution',
                        'provider_enable',
                        'automatic_ota_write',
                    ],
                    'seed_owner' => 'suxios.hkos_fusion_audit_knowledge',
                    'seed_key' => 'ota_product_semantics_audit:' . $type,
                    'seed_version' => '2026-08-09.1',
                    'lifecycle_status' => 'active',
                    'contains_current_hotel_fact' => false,
                    'contains_current_ota_fact' => false,
                    'external_write_authorized' => false,
                ],
            ];
        }

        $context = (new RevenueOperationsKnowledgeService())->buildContextFromRows(
            [$unit],
            $chunks,
            [
                'hotel_id' => 80,
                'module_id' => 'ota_product_semantics_audit',
                'limit' => 10,
                'as_of' => '2026-08-09 12:00:00',
            ]
        );

        self::assertSame('available', $context['status']);
        self::assertSame(1, $context['unit_count']);
        self::assertSame(1, $context['selected_unit_count']);
        self::assertSame(5, $context['entry_count']);
        self::assertSame(5, $context['eligible_entry_count']);
        self::assertSame(0, $context['decision_safe_entry_count']);
        self::assertSame(0, $context['excluded_decision_gate_count']);

        foreach ($context['entries'] as $entry) {
            self::assertSame(0, $entry['unit_hotel_id']);
            self::assertSame('ota_product_semantics_audit', $entry['module_id']);
            self::assertSame('C', $entry['evidence_grade']);
            self::assertSame('reference_only', $entry['knowledge_gate']['status']);
            self::assertTrue($entry['knowledge_gate']['retrieval_safe']);
            self::assertFalse($entry['knowledge_gate']['decision_safe']);
            self::assertFalse($entry['knowledge_gate']['task_draft_safe']);
            self::assertFalse($entry['content']['contains_current_hotel_fact']);
            self::assertFalse($entry['content']['contains_current_ota_fact']);
            self::assertFalse($entry['content']['external_write_authorized']);
        }
    }
}
