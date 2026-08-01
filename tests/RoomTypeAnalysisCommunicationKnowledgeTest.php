<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RoomTypeAnalysisCommunicationKnowledgeTest extends TestCase
{
    public function testPlaybookAndSeedKeepCommunicationKnowledgeSeparateFromHotelFacts(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $documentPath = $root . '/docs/room_type_operation_analysis_communication_playbook.md';
        $migrationPath = $root . '/database/migrations/20260714_seed_room_type_analysis_communication_knowledge.sql';
        self::assertFileExists($documentPath);
        self::assertFileExists($migrationPath);

        $document = (string)file_get_contents($documentPath);
        $migration = (string)file_get_contents($migrationPath);

        self::assertStringContainsString('房型经营分析报告解读话术库', $document);
        self::assertStringContainsString('原稿中的酒店名称、统计周期和经营数值不进入通用知识事实层', $document);
        self::assertStringContainsString('异常数字先查口径，口径闭合后才谈经营', $document);
        self::assertStringContainsString('不让沟通话术直接触发 OTA 改价', $document);

        self::assertStringContainsString("SET @room_analysis_unit_name := '房型经营分析报告解读话术库'", $migration);
        self::assertStringContainsString('user_provided_unverified', $migration);
        self::assertStringContainsString('communication_reference', $migration);
        self::assertStringContainsString('WHERE NOT EXISTS', $migration);
        self::assertStringContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringContainsString('INSERT INTO `knowledge_base`', $migration);
        self::assertStringNotContainsString('97.70%', $migration);
        self::assertStringNotContainsString('84.99%', $migration);
        self::assertStringNotContainsString('F:/wx/', str_replace('\\', '/', $document . $migration));

        $init = (string)file_get_contents($root . '/database/init_full.sql');
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260714_seed_room_type_analysis_communication_knowledge.sql;',
            $init
        );
    }
}
