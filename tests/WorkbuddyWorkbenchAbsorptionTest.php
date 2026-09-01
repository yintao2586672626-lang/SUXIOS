<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class WorkbuddyWorkbenchAbsorptionTest extends TestCase
{
    public function testSourceFingerprintAndReferenceOnlyMigrationStayBounded(): void
    {
        $root = dirname(__DIR__);
        $sourcePath = $root . '/docs/knowledge/workbuddy-workbench/source-extract.md';
        $migrationPath = $root . '/database/migrations/20260901_z_seed_workbench_reverse_interview_reference.sql';
        $source = (string)file_get_contents($sourcePath);
        $sourceDigest = hash('sha256', str_replace(["\r\n", "\r"], "\n", $source));
        $migration = (string)file_get_contents($migrationPath);

        self::assertSame(
            '32a9e5649786b4587768d2bb9545a55f14ab74dc8f0abb1b8bcd10790d064132',
            $sourceDigest
        );
        self::assertStringContainsString($sourceDigest, $migration);
        self::assertStringContainsString(
            'user-message://2026-09-01/workbuddy-workbench-awards',
            $migration
        );
        self::assertSame(
            4,
            substr_count(
                $migration,
                'INSERT INTO `tmp_workbench_reverse_interview_reference`'
            )
        );
        self::assertStringContainsString("'$.decision_safe', false", $migration);
        self::assertStringContainsString("'$.task_draft_safe', false", $migration);
        self::assertStringContainsString("'$.external_write_authorized', false", $migration);
        self::assertStringContainsString("'business_write_performed', false", $migration);
        self::assertStringContainsString("'source_case_claims_verified', false", $migration);
        self::assertStringNotContainsString('INSERT INTO `operation_tasks`', $migration);
        self::assertStringNotContainsString('INSERT INTO `online_daily_data`', $migration);
    }
}
