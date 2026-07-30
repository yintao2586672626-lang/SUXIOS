<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeDecisionGateService;
use app\service\RevenueOperationsKnowledgeService;
use PHPUnit\Framework\TestCase;

final class XyosLearningKernelKnowledgeTest extends TestCase
{
    public function testMigrationAbsorbsTheReviewedKernelWithoutCopyingCodeOrCreatingHotelFacts(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $path = $root . '/database/migrations/20260731_absorb_xyos_learning_kernel_knowledge.sql';
        self::assertFileExists($path);
        $migration = (string)file_get_contents($path);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            'XYOS学习内核吸收与安全演进合同',
            "SET @xyos_kernel_seed_owner := 'suxios.xyos_learning_kernel_knowledge'",
            "SET @xyos_kernel_version := '2026-07-31.1'",
            '3CFAD4FD3168839B404E84157C421818E8551EDE71CEB780C01493824DDB3802',
            "'version_status', 'filename_dated_unversioned_snapshot'",
            "'commit_status', 'git_metadata_not_present_in_archive'",
            "'license_status', 'license_file_not_found_in_archive'",
            "'execution_status', 'static_review_only'",
            "'reuse_mode', 'behavioral_rebuild'",
            "'source_code_copied', false",
            "'evidence_level', 'external_source_code_reviewed_reference'",
            "'$.content_type', 'governance_contract'",
            "'$.module_id', 'xyos_learning_kernel'",
            "'current_hotel_fact'",
            "'operation_task_creation'",
            "'automatic_operation_task'",
            "'automatic_ota_write'",
            'approval_binds_to_decision_snapshot_hash',
            'one_business_intent_one_stable_idempotency_key_one_canonical_result',
            'projection_revision_must_equal_canonical_revision_or_result_is_excluded',
            'status_change_alone_never_promotes_knowledge',
            'evaluation_pass_plus_current_policy_gate_plus_bounded_scope_required',
            'post_action_movement_is_not_causality_without_comparable_baseline_and_confound_review',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        foreach ([
            'xyos_source_scope_reference',
            'candidate_knowledge_promotion_contract',
            'knowledge_state_consistency_contract',
            'decision_snapshot_action_gateway_contract',
            'evaluation_autonomy_gate_contract',
            'outcome_learning_contract',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }

        self::assertSame(
            6,
            substr_count($migration, 'INSERT INTO `tmp_xyos_learning_kernel_chunks`')
        );
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString("'source_code_copied', true", $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260731_absorb_xyos_learning_kernel_knowledge.sql',
            $initFull
        );
    }

    public function testStructuredReaderKeepsTheKernelGlobalDecisionSafeAndActionBlocked(): void
    {
        $unit = [
            'unit_id' => 731,
            'hotel_id' => 0,
            'created_by' => 0,
            'name' => 'XYOS学习内核吸收与安全演进合同',
            'source' => RevenueOperationsKnowledgeService::SOURCE,
            'status' => 'done',
            'description' => 'global architecture reference',
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-31 00:00:00',
            'review_due_at' => '2026-10-29 00:00:00',
            'known_knowns' => ['审批、执行和结果验证是不同证据层'],
            'known_unknowns' => ['归档未包含Git提交与许可证证据'],
            'truth_profile_version' => '2026-07-31.1',
        ];
        $types = [
            'xyos_source_scope_reference',
            'candidate_knowledge_promotion_contract',
            'knowledge_state_consistency_contract',
            'decision_snapshot_action_gateway_contract',
            'evaluation_autonomy_gate_contract',
            'outcome_learning_contract',
        ];
        $chunks = [];
        foreach ($types as $index => $type) {
            $chunks[] = [
                'chunk_id' => 7310 + $index,
                'unit_id' => 731,
                'type' => $type,
                'content' => [
                    'scope' => 'global_architecture_reference',
                    'evidence_level' => 'external_source_code_reviewed_reference',
                    'evidence_grade' => 'B',
                    'source_refs' => ['archive://ota_watchdog_deliver_20260730.zip#' . $type],
                    'content_key' => 'xyos_learning_kernel:' . $type,
                    'content_type' => 'governance_contract',
                    'module_id' => 'xyos_learning_kernel',
                    'platforms' => ['suxios_internal'],
                    'roles' => ['owner', 'knowledge_reviewer'],
                    'scenes' => ['knowledge_review'],
                    'reviewed_at' => '2026-07-31 00:00:00',
                    'review_due_at' => '2026-10-29 00:00:00',
                    'review_interval_days' => 90,
                    'blocked_uses' => [
                        'operation_task_creation',
                        'operation_execution',
                        'automatic_operation_task',
                        'automatic_ota_write',
                    ],
                    'seed_owner' => 'suxios.xyos_learning_kernel_knowledge',
                    'seed_key' => 'xyos_learning_kernel:' . $type,
                    'seed_version' => '2026-07-31.1',
                    'lifecycle_status' => 'active',
                    'contains_current_hotel_fact' => false,
                    'external_write_authorized' => false,
                ],
            ];
        }

        $context = (new RevenueOperationsKnowledgeService())->buildContextFromRows(
            [$unit],
            $chunks,
            [
                'hotel_id' => 80,
                'module_id' => 'xyos_learning_kernel',
                'limit' => 10,
                'as_of' => '2026-07-31 12:00:00',
            ]
        );

        self::assertSame('available', $context['status']);
        self::assertSame(1, $context['unit_count']);
        self::assertSame(1, $context['selected_unit_count']);
        self::assertSame(6, $context['entry_count']);
        self::assertSame(6, $context['eligible_entry_count']);
        self::assertSame(6, $context['decision_safe_entry_count']);
        self::assertSame(0, $context['known_unknown_entry_count']);
        self::assertSame(0, $context['excluded_decision_gate_count']);

        foreach ($context['entries'] as $entry) {
            self::assertSame(0, $entry['unit_hotel_id']);
            self::assertSame('xyos_learning_kernel', $entry['module_id']);
            self::assertSame('B', $entry['evidence_grade']);
            self::assertSame('approved', $entry['knowledge_gate']['status']);
            self::assertTrue($entry['knowledge_gate']['retrieval_safe']);
            self::assertTrue($entry['knowledge_gate']['decision_safe']);
            self::assertFalse($entry['knowledge_gate']['task_draft_safe']);
            self::assertFalse($entry['content']['contains_current_hotel_fact']);
            self::assertFalse($entry['content']['external_write_authorized']);
            self::assertContains('归档未包含Git提交与许可证证据', $entry['known_unknowns']);
        }
    }

    public function testStaticSourceReviewIsGradeBButCannotCreateOperationTasks(): void
    {
        $gate = (new KnowledgeDecisionGateService())->assess([
            'lifecycle_status' => 'active',
            'reviewed_at' => '2026-07-31 00:00:00',
            'review_due_at' => '2026-10-29 00:00:00',
        ], [
            'lifecycle_status' => 'active',
            'scope' => 'global_architecture_reference',
            'evidence_level' => 'external_source_code_reviewed_reference',
            'evidence_grade' => 'B',
            'source_refs' => ['archive://ota_watchdog_deliver_20260730.zip'],
            'blocked_uses' => [
                'operation_task_creation',
                'operation_execution',
                'automatic_ota_write',
            ],
        ], '2026-07-31 12:00:00');

        self::assertSame('approved', $gate['status']);
        self::assertSame('B', $gate['evidence_grade']);
        self::assertTrue($gate['retrieval_safe']);
        self::assertTrue($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
    }
}
