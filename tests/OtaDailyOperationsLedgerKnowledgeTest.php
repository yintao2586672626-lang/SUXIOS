<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OtaDailyOperationsLedgerKnowledgeTest extends TestCase
{
    public function testWorkbookMethodIsPersistedAsBoundedOtaReferenceKnowledge(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $referencePath = $root . '/.agents/skills/suxi-ota-ops/references/ota-daily-operations-ledger.md';
        $skillPath = $root . '/.agents/skills/suxi-ota-ops/SKILL.md';
        $migrationPath = $root . '/database/migrations/20260726_seed_ota_daily_operations_ledger_knowledge.sql';

        self::assertFileExists($referencePath);
        self::assertFileExists($skillPath);
        self::assertFileExists($migrationPath);

        $reference = (string)file_get_contents($referencePath);
        $skill = (string)file_get_contents($skillPath);
        $migration = (string)file_get_contents($migrationPath);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('OTA 每日经营台账与晨报闭环', $reference);
        self::assertStringContainsString(
            '9379BAC806CE041375CC56D89B119ECE17225A5105B8CC19C6D4A5F8522C70D5',
            $reference
        );
        self::assertStringContainsString('历史数值、平台后台来源、门店身份、采集日期、保存回读及公式结果', $reference);
        self::assertStringContainsString('昨日 OTA 事实 → 本店与商圈/同行对比 → 漏斗瓶颈 → 今日动作 → 次日与 7 日复盘', $reference);
        self::assertStringContainsString('P=N/L', $reference);
        self::assertStringContainsString('AE=AC/Y', $reference);
        self::assertStringContainsString('J=F/D', $reference);
        self::assertStringContainsString('M=I/G', $reference);
        self::assertStringContainsString('分母为空、为零或质量不合格时返回“不可计算”', $reference);
        self::assertStringContainsString('#REF!', $reference);
        self::assertStringContainsString('AAAA', $reference);
        self::assertStringContainsString('不得返回 `0`、旧值或默认值', $reference);
        self::assertStringContainsString('不输出全酒店出租率、ADR、RevPAR、利润或投资结论', $reference);
        self::assertStringNotContainsString('RWTemp', $reference);
        self::assertStringNotContainsString('wxid_', $reference);

        self::assertStringContainsString('## Daily Operations Ledger', $skill);
        self::assertStringContainsString('references/ota-daily-operations-ledger.md', $skill);
        self::assertStringContainsString('Excel 公式结果不作为权威数据', $skill);

        self::assertStringContainsString(
            "SET @ota_daily_ledger_seed_owner := 'suxios.ota_daily_operations_ledger_knowledge'",
            $migration
        );
        self::assertStringContainsString(
            "SET @ota_daily_ledger_source := 'ota_daily_operations_ledger_reference'",
            $migration
        );
        self::assertStringContainsString('historical_user_workbook_structure_reviewed_values_unverified', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_units`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_chunks`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringContainsString('UPDATE `knowledge_chunks` AS `existing`', $migration);
        self::assertStringContainsString("'$.seed_key', CONCAT(`unit`.`source`, ':', `seed`.`type`)", $migration);
        self::assertStringContainsString("'$.seed_version', @ota_daily_ledger_version", $migration);
        self::assertSame(7, substr_count($migration, 'INSERT INTO `tmp_ota_daily_ledger_seed_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(0, substr_count($migration, 'DELETE FROM `knowledge_chunks`'));
        self::assertStringContainsString('P=N/L', $migration);
        self::assertStringContainsString('AE=AC/Y', $migration);
        self::assertStringContainsString('J=F/D', $migration);
        self::assertStringContainsString('M=I/G', $migration);
        self::assertStringContainsString('forbidden_fallbacks', $migration);
        self::assertStringContainsString('unknown、unverified或blocked', $migration);
        self::assertStringNotContainsString('RWTemp', $migration);
        self::assertStringNotContainsString('wxid_', $migration);

        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString(
            '20260726_seed_ota_daily_operations_ledger_knowledge.sql',
            $initFull
        );
    }

    public function testSeedContractPreservesManualAndOlderVersionChunks(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260726_seed_ota_daily_operations_ledger_knowledge.sql'
        );

        $safeExistingJson = 'CASE WHEN JSON_VALID(`existing`.`content`) = 1 THEN `existing`.`content` ELSE JSON_OBJECT() END';
        self::assertSame(6, substr_count($migration, $safeExistingJson));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_owner')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_key')"));
        self::assertSame(2, substr_count($migration, "JSON_EXTRACT({$safeExistingJson}, '$.seed_version')"));
        self::assertStringNotContainsString("JSON_EXTRACT(`existing`.`content`, '$.seed_", $migration);
        self::assertStringNotContainsString('ALTER TABLE `knowledge_chunks`', $migration);

        $manualChunk = [
            'type' => 'operator_note',
            'content' => ['note' => '人工复盘结论'],
        ];
        $olderSeedChunk = [
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => 'suxios.ota_daily_operations_ledger_knowledge',
                'seed_key' => 'ota_daily_operations_ledger_reference:source_boundary',
                'seed_version' => '2026-07-26.0',
            ],
        ];
        $currentSeedChunk = [
            'type' => 'source_boundary',
            'content' => [
                'seed_owner' => 'suxios.ota_daily_operations_ledger_knowledge',
                'seed_key' => 'ota_daily_operations_ledger_reference:source_boundary',
                'seed_version' => '2026-07-26.1',
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
        self::assertSame('人工复盘结论', $rows[0]['content']['note']);
        self::assertSame('2026-07-26.0', $rows[1]['content']['seed_version']);
        self::assertSame('2026-07-26.1', $rows[2]['content']['seed_version']);
    }
}
