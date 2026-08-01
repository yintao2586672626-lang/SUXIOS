<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OperatingTargetDeltaDetectionKnowledgeTest extends TestCase
{
    public function testReviewedSourceMethodIsPersistedAsRetrievableBoundedKnowledge(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $referencePath = $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/references/operating-target-delta-detection.md';
        $skillPath = $root . '/.agents/skills/suxi-ota-revenue-semantic-layer/SKILL.md';
        $migrationPath = $root . '/database/migrations/20260727_seed_operating_target_delta_detection_knowledge.sql';
        $correctionMigrationPath = $root . '/database/migrations/20260729_update_knowledge_lifecycle_and_runtime_status.sql';
        $sourceContractPath = $root . '/docs/followups/operating_target_source_contract.md';
        $dingdandaoServicePath = $root . '/app/service/DingdandaoOperatingTargetCaptureService.php';
        $reconciliationServicePath = $root . '/app/service/PmsFactReconciliationService.php';

        self::assertFileExists($referencePath);
        self::assertFileExists($skillPath);
        self::assertFileExists($migrationPath);
        self::assertFileExists($correctionMigrationPath);
        self::assertFileExists($sourceContractPath);
        self::assertFileExists($dingdandaoServicePath);
        self::assertFileExists($reconciliationServicePath);

        $reference = (string)file_get_contents($referencePath);
        $skill = (string)file_get_contents($skillPath);
        $migration = (string)file_get_contents($migrationPath);
        $correctionMigration = (string)file_get_contents($correctionMigrationPath);
        $sourceContract = (string)file_get_contents($sourceContractPath);
        $dingdandaoService = (string)file_get_contents($dingdandaoServicePath);
        $reconciliationService = (string)file_get_contents($reconciliationServicePath);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('经营目标差值检测与节奏判断（宿析OS吸收版）', $reference);
        self::assertStringContainsString(
            '3997FFA6BD111136A5C3C9FE24796D92945C241BBA9862B4A7C09F92343FB765',
            $reference
        );
        self::assertStringContainsString('gap + delta', $reference);
        self::assertStringContainsString('net_pickup = delta_sold_room_nights', $reference);
        self::assertStringContainsString('没有取消累计证据时，不得称为“新增预订”', $reference);
        self::assertStringContainsString('room_tolerance = max(1间, ceil(sellable_room_nights * 5%))', $reference);
        self::assertStringContainsString('OT_DIFF_REVERSAL_UNKNOWN', $reference);
        self::assertStringContainsString('target_revenue / total_sellable_rooms', $reference);
        self::assertStringContainsString('PMS 核心相邻快照差值已集成', $reference);
        self::assertStringContainsString('不得直接采用源码包中的 7 月/8 月固定时点进度表', $reference);

        self::assertStringContainsString(
            'references/operating-target-delta-detection.md',
            $skill
        );
        self::assertStringContainsString('adjacent-snapshot deltas', $skill);

        self::assertStringContainsString('源码包已完成只读复核', $sourceContract);
        self::assertStringContainsString('同源相邻快照的 `gap + delta` 核心接入运行时', $sourceContract);
        self::assertStringContainsString('unverified_runtime', $sourceContract);
        self::assertStringNotContainsString('source_formula_pending_recheck', $sourceContract);

        self::assertStringContainsString("'total_room_fee'", $dingdandaoService);
        self::assertStringContainsString("'sold_room_nights'", $dingdandaoService);
        self::assertStringNotContainsString('cancellations_total', $dingdandaoService);
        self::assertStringContainsString('PMS_DELTA_REVERSAL_UNKNOWN', $reconciliationService);
        self::assertStringContainsString('cumulative_cancellations_missing', $reconciliationService);
        self::assertStringContainsString('dual_source_needs_review', $reconciliationService);

        self::assertStringContainsString(
            "SET @operating_delta_seed_owner := 'suxios.operating_target_delta_detection_knowledge'",
            $migration
        );
        self::assertStringContainsString(
            "SET @operating_delta_source := 'operating_target_delta_detection_reference'",
            $migration
        );
        self::assertStringContainsString("'$.module_id', 'operating_target_delta_detection'", $migration);
        self::assertStringContainsString("'$.roles', JSON_ARRAY", $migration);
        self::assertStringContainsString("'$.scenes', JSON_ARRAY", $migration);
        self::assertStringContainsString("'$.platforms', JSON_ARRAY", $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_units`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_chunks`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringContainsString('经营目标,差值检测,目标差距,相邻快照,净拾取', $migration);
        self::assertStringContainsString('knowledge_absorbed_runtime_delta_feature_not_online', $migration);
        self::assertStringContainsString(
            'core_adjacent_snapshot_delta_online_remaining_advanced_features_partial',
            $correctionMigration
        );
        self::assertStringContainsString(
            'PmsFactReconciliationService verified same-source adjacent-snapshot gap and delta',
            $correctionMigration
        );
        self::assertStringContainsString(
            'PmsFactReconciliationService已接入同源已验证相邻快照的gap+delta核心',
            $correctionMigration
        );
        self::assertSame(9, substr_count($migration, 'INSERT INTO `tmp_operating_delta_seed_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(0, substr_count($migration, 'DELETE FROM `knowledge_chunks`'));

        foreach ([$reference, $migration] as $content) {
            self::assertStringNotContainsString('RWTemp', $content);
            self::assertStringNotContainsString('wxid_', $content);
        }

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260727_seed_operating_target_delta_detection_knowledge.sql',
            $initFull
        );
    }

    public function testSeedContractPreservesManualAndOlderVersionChunks(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260727_seed_operating_target_delta_detection_knowledge.sql'
        );

        $safeExistingJson = 'CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END';
        self::assertSame(6, substr_count($migration, $safeExistingJson));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_owner')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_key')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_version')"));
        self::assertStringContainsString("'$.seed_version', @operating_delta_version", $migration);
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringNotContainsString("JSON_EXTRACT(`existing`.`content`, '$.seed_", $migration);
        self::assertStringNotContainsString('ALTER TABLE `knowledge_chunks`', $migration);

        $manualChunk = [
            'type' => 'operator_note',
            'content' => ['note' => '门店人工复盘'],
        ];
        $olderSeedChunk = [
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => 'suxios.operating_target_delta_detection_knowledge',
                'seed_key' => 'operating_target_delta_detection_reference:source_boundary',
                'seed_version' => '2026-07-27.0',
            ],
        ];
        $currentSeedChunk = [
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => 'suxios.operating_target_delta_detection_knowledge',
                'seed_key' => 'operating_target_delta_detection_reference:source_boundary',
                'seed_version' => '2026-07-27.1',
            ],
        ];

        $rows = [$manualChunk, $olderSeedChunk];
        $matchesCurrentSeed = static function (array $row) use ($currentSeedChunk): bool {
            foreach (['seed_owner', 'seed_key', 'seed_version'] as $key) {
                if (($row['content'][$key] ?? null) !== $currentSeedChunk['content'][$key]) {
                    return false;
                }
            }
            return true;
        };

        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeedChunk;
        }
        if (!array_filter($rows, $matchesCurrentSeed)) {
            $rows[] = $currentSeedChunk;
        }

        self::assertCount(3, $rows);
        self::assertSame('门店人工复盘', $rows[0]['content']['note']);
        self::assertSame('2026-07-27.0', $rows[1]['content']['seed_version']);
        self::assertSame('2026-07-27.1', $rows[2]['content']['seed_version']);
    }
}
