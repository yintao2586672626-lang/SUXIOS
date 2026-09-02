<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class MeituanTrafficSelfCheckKnowledgeTest extends TestCase
{
    public function testScreenshotReferencePreservesVisibleStructureAndTruthBoundary(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $manifestPath = $root . '/docs/knowledge/meituan-traffic-self-check/source-manifest.json';
        $packPath = $root . '/docs/knowledge/meituan-traffic-self-check/reference-pack.json';
        $sourcePath = $root . '/docs/knowledge/meituan-traffic-self-check/sources/meituan-hotel-traffic-self-check-visible-reference.png';
        $documentPath = $root . '/docs/capability-absorption/2026-08-31-meituan-traffic-self-check.md';
        $migrationPath = $root . '/database/migrations/20260831_z_seed_meituan_traffic_self_check_reference.sql';
        $verifierPath = $root . '/scripts/verify_meituan_traffic_self_check_knowledge.php';

        foreach ([$manifestPath, $packPath, $sourcePath, $documentPath, $migrationPath, $verifierPath] as $path) {
            self::assertFileExists($path);
        }

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $pack = json_decode((string)file_get_contents($packPath), true);
        self::assertIsArray($manifest);
        self::assertIsArray($pack);
        self::assertSame('storage_only', $manifest['task_mode']);
        self::assertSame('absorption_candidate', $manifest['disposition']);
        self::assertSame('observed', $manifest['maturity']);
        self::assertSame([
            'mechanism' => 'indeterminate',
            'value' => 'pass',
            'reproduction' => 'fail',
        ], $manifest['gates']);
        self::assertSame('not_assumed_current', $manifest['source_currentness']);
        self::assertSame(
            'A1EB608EA9BB8DF34624C61629E40A602F0C3B6531B3875879128178CE8A2F67',
            strtoupper((string)hash_file('sha256', $sourcePath))
        );

        self::assertSame('reference_only', $pack['usage_policy']);
        self::assertSame(['meituan'], $pack['platforms']);
        self::assertFalse($pack['contains_current_hotel_fact']);
        self::assertFalse($pack['contains_current_ota_fact']);
        self::assertFalse($pack['contains_confirmed_current_platform_rule']);
        self::assertFalse($pack['external_write_authorized']);
        self::assertSame(
            ['流量排名', '基础曝光', '奖励曝光', '广告曝光'],
            $pack['verified_visible']['guidance_card_labels']
        );
        self::assertSame(
            ['流量类型', '细分指标', '有没有', '我的数据（近七天）', '同行标杆（近七天）', '差距', '运营提升'],
            $pack['verified_visible']['self_check_columns']
        );
        self::assertSame(
            ['基础曝光', '加权曝光'],
            array_column($pack['verified_visible']['traffic_structure'][0]['items'], 'label')
        );
        self::assertSame(
            ['奖励曝光', '付费曝光'],
            array_column($pack['verified_visible']['traffic_structure'][1]['items'], 'label')
        );
        self::assertSame('fail', $pack['mechanism_candidate']['gates']['reproduction']);
        self::assertNotEmpty($pack['unverified_items']);
        self::assertContains('operation_task_creation', $pack['blocked_uses']);
        self::assertContains('automatic_ota_write', $pack['blocked_uses']);

        $mapping = [];
        foreach ($pack['metric_mapping_boundary'] as $item) {
            self::assertIsArray($item);
            $mapping[(string)$item['visible_label']] = $item;
        }
        self::assertNull($mapping['基础曝光']['canonical_metric']);
        self::assertSame('ad_exposure', $mapping['广告曝光']['canonical_metric']);
        self::assertStringContainsString('candidate_only', $mapping['广告曝光']['mapping_status']);

        $migration = (string)file_get_contents($migrationPath);
        foreach ([
            "SET @meituan_traffic_self_check_version := '2026-08-31.1'",
            "SET @meituan_traffic_self_check_seed_owner := 'suxios.meituan_traffic_self_check_reference'",
            "SET @meituan_traffic_self_check_source := 'user_meituan_traffic_self_check_screenshot'",
            'A1EB608EA9BB8DF34624C61629E40A602F0C3B6531B3875879128178CE8A2F67',
            'meituan_traffic_self_check_visible_reference',
            'meituan_traffic_self_check_mechanism_candidate',
            'meituan_traffic_self_check_metric_boundary',
            "'$.external_write_authorized', false",
            'UPDATE `knowledge_chunks` AS `existing`',
        ] as $expected) {
            self::assertStringContainsString($expected, $migration);
        }
        self::assertSame(3, substr_count($migration, 'INSERT INTO `tmp_meituan_traffic_self_check_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_units`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_chunks`'));
        self::assertSame(1, substr_count($migration, 'INSERT INTO `knowledge_base`'));
        self::assertStringNotContainsString('DELETE FROM `knowledge_chunks`', $migration);
        self::assertStringNotContainsString('DELETE FROM `knowledge_units`', $migration);
        self::assertStringNotContainsString("'external_write_authorized', true", $migration);

        self::assertStringNotContainsString(
            'user_provided_meituan_traffic_self_check_screenshot',
            $migration
        );
    }
}
