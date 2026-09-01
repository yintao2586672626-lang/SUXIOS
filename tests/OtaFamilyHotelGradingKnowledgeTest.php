<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OtaFamilyHotelGradingKnowledgeTest extends TestCase
{
    public function testReferencePackagePreservesPlatformSeparatedVisibleFactsAndExecutionBoundary(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $manifestPath = $root . '/docs/knowledge/ota-family-hotel-grading/source-manifest.json';
        $packPath = $root . '/docs/knowledge/ota-family-hotel-grading/reference-pack.json';
        $documentPath = $root . '/docs/capability-absorption/2026-08-31-ota-family-hotel-grading.md';
        $migrationPath = $root . '/database/migrations/20260831_seed_ota_family_hotel_grading_reference.sql';
        $verifierPath = $root . '/scripts/verify_ota_family_hotel_grading_knowledge.php';

        foreach ([$manifestPath, $packPath, $documentPath, $migrationPath, $verifierPath] as $path) {
            self::assertFileExists($path);
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $pack = json_decode((string)file_get_contents($packPath), true);
        self::assertIsArray($manifest);
        self::assertIsArray($pack);
        self::assertSame('storage_only', $manifest['task_mode']);
        self::assertSame('absorption_candidate', $manifest['disposition']);
        self::assertSame('not_assumed_current', $manifest['source_currentness']);
        self::assertSame('reference_only', $pack['usage_policy']);
        self::assertTrue($pack['platform_identity_required']);
        self::assertFalse($pack['grade_conversion_allowed']);
        self::assertFalse($pack['contains_current_hotel_fact']);
        self::assertFalse($pack['contains_current_ota_fact']);
        self::assertFalse($pack['external_write_authorized']);

        $sources = [];
        foreach ($manifest['sources'] as $source) {
            self::assertIsArray($source);
            $sources[(string)$source['platform']] = $source;
            $sourcePath = dirname($manifestPath) . '/' . (string)$source['file'];
            self::assertFileExists($sourcePath);
            self::assertSame(
                strtoupper((string)$source['sha256']),
                strtoupper((string)hash_file('sha256', $sourcePath))
            );
        }
        self::assertSame(
            '5028E4CC12199787D3F2C5DF40A8E4E6DCF52AB3B94DEE1180603E2CDD52405D',
            $sources['ctrip']['sha256']
        );
        self::assertSame(
            '7B19CC9DFBE08F74E8D6CD5885BB2849D09A8EDB9A3E30CAEF4349B2221117BE',
            $sources['meituan']['sha256']
        );

        $ctrip = $pack['platforms']['ctrip'];
        $meituan = $pack['platforms']['meituan'];
        self::assertSame(['亲子酒店', 'A级', 'A+级'], $ctrip['visible_levels']);
        self::assertSame(['A级', 'S级'], $meituan['visible_levels']);
        self::assertSame(
            ['亲子设施', '亲子活动', '亲子服务', '亲子认可度', '3公里内的景点'],
            array_column($ctrip['visible_dimensions'], 'label')
        );
        self::assertSame(
            ['居住体验', '饮食体验', '亲子设施', '亲子活动'],
            array_column($meituan['visible_dimensions'], 'label')
        );
        self::assertSame(['入住保障', '退订保障', '专业客服'], $meituan['service_guarantees_visible_but_not_rating_dimensions']);
        self::assertSame(['亲子设施', '亲子活动'], $pack['cross_platform_boundary']['shared_labels_are_not_shared_metrics']);
        self::assertNotEmpty($pack['unverified_items']);
        self::assertNotEmpty($pack['upgrade_triggers']);

        $migration = (string)file_get_contents($migrationPath);
        foreach ([
            "SET @family_grading_version := '2026-08-31.1'",
            "SET @family_grading_seed_owner := 'suxios.ota_family_hotel_grading_reference'",
            '5028E4CC12199787D3F2C5DF40A8E4E6DCF52AB3B94DEE1180603E2CDD52405D',
            '7B19CC9DFBE08F74E8D6CD5885BB2849D09A8EDB9A3E30CAEF4349B2221117BE',
            'ctrip_family_hotel_grading_visible_reference',
            'meituan_family_hotel_grading_visible_reference',
            'family_hotel_grading_cross_platform_boundary',
            "'$.external_write_authorized', false",
            'UPDATE knowledge_chunks AS existing',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }
        self::assertSame(3, substr_count($migration, 'INSERT INTO tmp_ota_family_hotel_grading_chunks'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO knowledge_units'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO knowledge_chunks'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO knowledge_base'));
        self::assertStringNotContainsString('DELETE FROM knowledge_chunks', $migration);
        self::assertStringNotContainsString('DELETE FROM knowledge_units', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);
    }
}
