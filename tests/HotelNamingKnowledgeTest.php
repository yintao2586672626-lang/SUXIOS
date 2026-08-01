<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class HotelNamingKnowledgeTest extends TestCase
{
    public function testSkillContainsFactBoundariesScreenshotWorkflowAndConversionGuard(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $skill = (string)file_get_contents($root . '/.agents/skills/suxi-hotel-naming/SKILL.md');
        $roomReference = (string)file_get_contents($root . '/.agents/skills/suxi-hotel-naming/references/room-type-naming.md');
        $storeReference = (string)file_get_contents($root . '/.agents/skills/suxi-hotel-naming/references/hotel-store-naming.md');

        foreach ([
            '酒店、民宿、门店和房型起名',
            '截图改名',
            '已确认事实',
            '截图未确认',
            '不自动修改 OTA、PMS',
            '曝光到详情点击率',
            '详情到预订转化率',
        ] as $expected) {
            self::assertStringContainsString($expected, $skill);
        }

        self::assertStringContainsString('459F49569DE5AD1154631BF35B2C91EB4D7095BC67E2E744A430EAF61AF981DA', $roomReference);
        self::assertStringContainsString('一个最强且真实的卖点 + 标准房型', $roomReference);
        self::assertStringContainsString('诗意前缀不能代替标准品类', $skill);
        self::assertStringContainsString('商标、工商名称、平台重名、禁限词和品牌授权必须另行核验', $storeReference);
    }

    public function testTriggerAndQualityEvalsAreValidAndBalanced(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $trigger = json_decode(
            (string)file_get_contents($root . '/.agents/skills/suxi-hotel-naming/evals/trigger-evals.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('suxi-hotel-naming', $trigger['skill']);
        self::assertCount(10, $trigger['cases']);
        self::assertCount(5, array_filter($trigger['cases'], static fn (array $case): bool => $case['should_trigger'] === true));
        self::assertCount(5, array_filter($trigger['cases'], static fn (array $case): bool => $case['should_trigger'] === false));

        $quality = json_decode(
            (string)file_get_contents($root . '/.agents/skills/suxi-hotel-naming/evals/evals.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('suxi-hotel-naming', $quality['skill']);
        self::assertCount(5, $quality['cases']);
        foreach ($quality['cases'] as $case) {
            self::assertNotEmpty($case['assertions']);
        }
    }

    public function testMigrationIsGlobalIdempotentAndDoesNotGrantHotelOrWriteAuthority(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $path = $root . '/database/migrations/20260801_seed_hotel_naming_optimization_knowledge.sql';
        self::assertFileExists($path);
        $migration = (string)file_get_contents($path);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');

        foreach ([
            '酒店门店与房型命名优化知识',
            "SET @hotel_naming_seed_owner := 'suxios.hotel_naming_knowledge'",
            "SET @hotel_naming_version := '2026-08-01.1'",
            '459F49569DE5AD1154631BF35B2C91EB4D7095BC67E2E744A430EAF61AF981DA',
            "'scope', 'global_industry_reference_unverified'",
            "'evidence_level', 'user_provided_unverified_reference'",
            "'evidence_grade', 'D'",
            "'$.module_id', 'hotel_naming'",
            "'current_hotel_fact'",
            "'operation_task_creation'",
            "'automatic_ota_write'",
            "'automatic_pms_write'",
            "'conversion_uplift_claim'",
            "'$.contains_current_hotel_fact', false",
            "'$.external_write_authorized', false",
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }

        foreach ([
            'hotel_naming_source_scope_reference',
            'room_type_naming_taxonomy',
            'hotel_store_naming_contract',
            'screenshot_naming_optimization_contract',
            'naming_conversion_evaluation_contract',
        ] as $type) {
            self::assertStringContainsString("'{$type}'", $migration);
        }

        self::assertSame(5, substr_count($migration, 'INSERT INTO `tmp_hotel_naming_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString('G:/Downloads', $migration);
        self::assertStringNotContainsString('G:\\Downloads', $migration);
        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString('20260801_seed_hotel_naming_optimization_knowledge.sql', $initFull);
    }
}
