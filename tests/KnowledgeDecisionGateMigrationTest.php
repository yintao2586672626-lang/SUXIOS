<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class KnowledgeDecisionGateMigrationTest extends TestCase
{
    public function testForwardMigrationAddsReviewEvidenceAndConflictContracts(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $path = $root . '/database/migrations/20260730_zz_add_knowledge_decision_gate.sql';
        self::assertFileExists($path);
        $migration = (string)file_get_contents($path);

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `review_due_at`', $migration);
        self::assertStringContainsString("'$.evidence_grade'", $migration);
        self::assertStringContainsString("'$.review_interval_days'", $migration);
        self::assertStringContainsString("'$.decision_policy'", $migration);
        self::assertStringContainsString("'known_unknown_only'", $migration);
        self::assertStringContainsString("'room_revenue_source_basis'", $migration);
        self::assertStringContainsString("'ota_browser_profile_ownership'", $migration);
        self::assertStringContainsString("'$.resolution_status', 'resolved'", $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
    }
}
