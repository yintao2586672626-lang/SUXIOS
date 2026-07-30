<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class KnowledgeLifecycleOptimizationTest extends TestCase
{
    public function testLifecycleMigrationRetainsHistoryAndBackfillsTraceability(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migrationPath = $root . '/database/migrations/20260729_update_knowledge_lifecycle_and_runtime_status.sql';
        self::assertFileExists($migrationPath);

        $migration = (string)file_get_contents($migrationPath);

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `lifecycle_status`', $migration);
        self::assertStringContainsString("`ku`.`lifecycle_status` = 'quarantined'", $migration);
        self::assertStringContainsString("`ku`.`source` = 'revenue_research'", $migration);
        self::assertStringContainsString("`ku`.`source` = 'ml_distillation'", $migration);
        self::assertStringContainsString("'$.scope'", $migration);
        self::assertStringContainsString("'$.evidence_level'", $migration);
        self::assertStringContainsString("'$.source_refs'", $migration);
        self::assertStringContainsString(
            'core_adjacent_snapshot_delta_online_remaining_advanced_features_partial',
            $migration
        );
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
    }

    public function testDecisionReadersRequireActiveKnowledge(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $agent = (string)file_get_contents($root . '/app/controller/Agent.php');
        $research = (string)file_get_contents($root . '/app/service/RevenueResearchService.php');
        $operations = (string)file_get_contents($root . '/app/service/RevenueOperationsKnowledgeService.php');
        $knowledgeController = (string)file_get_contents($root . '/app/controller/Knowledge.php');

        self::assertStringContainsString("->where('lifecycle_status', 'active')", $agent);
        self::assertStringContainsString("->where('lifecycle_status', 'active')", $research);
        self::assertStringContainsString("->where('lifecycle_status', 'active')", $operations);
        self::assertStringContainsString("\$lifecycleStatus !== 'active'", $operations);
        self::assertStringContainsString('KnowledgeDecisionGateService', $agent);
        self::assertStringContainsString('KnowledgeDecisionGateService', $operations);
        self::assertStringContainsString("'task_draft_safe'", $knowledgeController);
    }
}
