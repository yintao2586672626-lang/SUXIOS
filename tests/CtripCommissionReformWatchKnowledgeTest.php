<?php
declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;

final class CtripCommissionReformWatchKnowledgeTest extends TestCase
{
    public function testMigrationKeepsClaimLevelEvidenceAndWriteBoundaries(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $path = $root . '/database/migrations/20260809_b_absorb_ctrip_commission_reform_watch.sql';
        self::assertFileExists($path);
        $migration = (string)file_get_contents($path);
        $repairMigration = (string)file_get_contents(
            $root . '/database/migrations/20260809_c_repair_ctrip_reform_watch_retrieval_traceability.sql'
        );
        $checklistRepairMigration = (string)file_get_contents(
            $root . '/database/migrations/20260809_d_repair_ctrip_reform_watch_checklist_evidence_label.sql'
        );
        $verifier = (string)file_get_contents(
            $root . '/scripts/verify_ctrip_commission_reform_watch.php'
        );

        foreach ([
            '携程佣金与流量排序新规观察（2026-08）',
            "SET @ctrip_reform_version := '2026-08-09.1'",
            "SET @ctrip_reform_review_due_at := '2026-08-18 00:00:00'",
            "SET @ctrip_reform_seed_owner := 'suxios.ctrip_commission_reform_watch'",
            'samr_ctrip_antitrust_penalty_20260725',
            'ctrip_19_rectification_measures_20260725',
            'mixed_official_public_sources_and_user_provided_internal_message',
            'official_support_is_required_before_any_claim_becomes_current_platform_rule',
            'officially_corrected_plus_unverified_future_date',
            '携程已于2026年7月25日宣布特牌和金牌合作模式全面下线',
            '10%至15%范围',
            '30天窗口',
            '80%间夜口径',
            'no_public_official_text_found_for_exact_commission_range_factor_weights_or_announced_launch_dates',
            "'commission_change'",
            "'ranking_prediction'",
            "'promotion_enrollment_change'",
            "'subsidy_opt_in_or_out'",
            "'automatic_ota_write'",
            "'$.contains_current_hotel_fact', false",
            "'$.contains_confirmed_current_contract_term', false",
            "'$.external_write_authorized', false",
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        for ($claim = 1; $claim <= 15; $claim++) {
            self::assertStringContainsString(
                sprintf('ctrip_reform_claim_%02d', $claim),
                $migration
            );
        }

        foreach ([
            'ctrip_reform_source_and_evidence_boundary',
            'ctrip_reform_claim_assessment_01_08',
            'ctrip_reform_claim_assessment_09_15',
            'ctrip_reform_hotel_action_checklist',
            'ctrip_reform_reverification_schedule',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }

        self::assertSame(5, substr_count($migration, 'INSERT INTO `tmp_ctrip_reform_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
        self::assertStringContainsString("'$.source_refs'", $repairMigration);
        self::assertStringContainsString("'$.evidence_grade', 'C'", $repairMigration);
        self::assertStringContainsString("'reviewed_policy_watch_reference_only'", $repairMigration);
        self::assertStringContainsString("'$.decision_safe', false", $repairMigration);
        self::assertStringContainsString("'$.task_draft_safe', false", $repairMigration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $repairMigration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $repairMigration);
        self::assertStringContainsString(
            "'$.evidence_level', 'reviewed_policy_watch_operator_checklist'",
            $checklistRepairMigration
        );
        self::assertStringContainsString("'$.decision_safe', false", $checklistRepairMigration);
        self::assertStringContainsString("'$.task_draft_safe', false", $checklistRepairMigration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $checklistRepairMigration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $checklistRepairMigration);
        self::assertStringContainsString("'all_15_claims_read_back'", $verifier);
        self::assertStringContainsString("'claim_12_official_correction_preserved'", $verifier);
        self::assertStringContainsString("'write_and_ranking_guards_on_every_chunk'", $verifier);
        self::assertStringContainsString("'revenue_operations_reader_returns_policy_watch'", $verifier);
    }
}
