<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class KnowledgeTruthProfilePruningTest extends TestCase
{
    public function testMigrationMapsFourteenActiveUnitsAndPrunesOnlyExactTargets(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migrationPath = $root . '/database/migrations/20260729_update_knowledge_truth_profiles_and_prune.sql';
        self::assertFileExists($migrationPath);
        $migration = (string)file_get_contents($migrationPath);

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `known_knowns`', $migration);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `known_unknowns`', $migration);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `truth_profile_version`', $migration);
        self::assertStringContainsString("'2026-07-29.1'", $migration);

        self::assertSame(1, preg_match(
            '/INSERT INTO `tmp_knowledge_truth_profiles`.*?VALUES(?<rows>.*?)UPDATE `knowledge_units`/s',
            $migration,
            $truthMatch
        ));
        self::assertSame(14, substr_count((string)$truthMatch['rows'], "\n  ("));

        self::assertSame(1, preg_match(
            '/INSERT INTO `tmp_knowledge_prune_targets`.*?VALUES(?<rows>.*?)DELETE `kc`/s',
            $migration,
            $pruneMatch
        ));
        self::assertSame(7, substr_count((string)$pruneMatch['rows'], "\n  ("));

        self::assertStringContainsString(
            'legacy_revenue_research_snapshot_missing_current_readiness_contract',
            (string)file_get_contents(
                $root . '/database/migrations/20260729_update_knowledge_lifecycle_and_runtime_status.sql'
            )
        );
        self::assertStringContainsString('JOIN `tmp_knowledge_prune_targets` AS `target`', $migration);
        self::assertStringContainsString('empty_legacy_experience_placeholder', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
    }

    public function testTruthProfilesAreExposedToKnowledgeReaders(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $model = (string)file_get_contents($root . '/app/model/KnowledgeUnit.php');
        $mapper = (string)file_get_contents($root . '/app/service/KnowledgePayloadMapper.php');
        $agent = (string)file_get_contents($root . '/app/controller/Agent.php');
        $operations = (string)file_get_contents($root . '/app/service/RevenueOperationsKnowledgeService.php');

        foreach ([$model, $mapper, $agent, $operations] as $source) {
            self::assertStringContainsString('known_knowns', $source);
            self::assertStringContainsString('known_unknowns', $source);
        }
        self::assertStringContainsString('已确认：', $agent);
        self::assertStringContainsString('待验证：', $agent);
        self::assertStringContainsString('禁止用0、旧数据或默认值补齐', $agent);
    }
}
