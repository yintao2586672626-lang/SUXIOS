<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class KnowledgeCuration20260901Test extends TestCase
{
    public function testForwardCurationPromotesOnlyReviewedMethodsAndPausesMissingSources(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migrationPath = $root . '/database/migrations/20260901_refine_reference_knowledge_availability.sql';
        $verifierPath = $root . '/scripts/verify_knowledge_curation_20260901.php';
        $retrievalServicePath = $root . '/app/service/OperatingQuestionKnowledgeRetrievalService.php';
        self::assertFileExists($migrationPath);
        self::assertFileExists($verifierPath);
        self::assertFileExists($retrievalServicePath);

        $migration = (string)file_get_contents($migrationPath);
        foreach ([
            "WHERE `unit_id` = 42",
            "'suxios.ota_daily_operations_ledger_knowledge'",
            "'user_provided_reference_structure_reviewed'",
            "WHERE `unit_id` = 57",
            "'suxios.hotel_naming_knowledge'",
            "'user_provided_reference_method_reviewed'",
            "'global:user_training:hotel_bd_new_store'",
            "'global:user_reference:hotel_manager_interview_distillation'",
            "`lifecycle_status` = 'stale'",
            "'$.decision_safe', false",
            "'$.task_draft_safe', false",
            "'$.external_write_authorized', false",
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        self::assertSame(2, substr_count($migration, "'$.evidence_grade', 'C'"));
        self::assertSame(2, substr_count($migration, "'status', 'reviewed_reference_method_only'"));
        self::assertStringNotContainsString('DELETE FROM', $migration);
        self::assertStringNotContainsString('DROP TABLE', $migration);
        self::assertStringNotContainsString("'$.external_write_authorized', true", $migration);
        self::assertStringNotContainsString('unit_id` = 36', $migration);
        self::assertStringNotContainsString('unit_id` = 59', $migration);
        self::assertStringNotContainsString('unit_id` = 60', $migration);

        $retrievalService = (string)file_get_contents($retrievalServicePath);
        self::assertStringContainsString('private const MAX_CHUNKS = 800;', $retrievalService);
    }
}
