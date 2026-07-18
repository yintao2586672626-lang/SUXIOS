<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaPublicPageDiagnosisService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OtaPublicPageDiagnosisServiceTest extends TestCase
{
    public function testBuildsTwelveEvidenceDimensionsWithoutInventingScore(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'ota_hotel_id' => '3456814',
            'role' => 'self',
            'snapshot_id' => 901,
            'data_date' => '2026-07-17',
            'collected_at' => '2026-07-17 09:30:00',
            'capture_status' => 'partial',
            'source_method' => 'ctrip_public_page',
            'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html?trace=must-strip',
            'persistence_readback_verified' => true,
            'source_validation_status' => 'partial',
            'fields' => [
                'name' => '证据酒店',
                'rating' => 4.8,
                'cover_image_url' => 'https://images.example.test/cover.jpg',
                'gallery_image_urls' => [],
                'description' => '公开页酒店简介',
            ],
            'field_statuses' => [
                'name' => 'available',
                'rating' => 'available',
                'images' => 'available',
                'description' => 'available',
            ],
            'evidence_paths' => [
                'name' => 'html:h1',
                'rating' => 'html:review_score',
                'images' => 'next_flight:hotel_images',
                'description' => 'next_flight:description',
            ],
        ]]);

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertCount(12, $result['dimensions']);
        self::assertNull($result['diagnosis_score']);
        self::assertSame('not_calculated_no_validated_scoring_rule', $result['score_status']);
        self::assertSame(4, $result['evidence_coverage']['observed_field_count']);
        self::assertSame(36, $result['evidence_coverage']['expected_field_count']);
        self::assertSame('https://hotels.ctrip.com/hotels/3456814.html', $result['sources'][0]['source_url']);
        self::assertSame('ota_ctrip_entity_snapshots#901', $result['sources'][0]['response_ref']);
        self::assertSame('readback_verified', $result['sources'][0]['persistence_readback_status']);
        self::assertSame('partial', $result['sources'][0]['source_validation_status']);

        $nameFact = $result['dimensions'][0]['facts'][0];
        self::assertSame('partial', $nameFact['quality_status']);
        self::assertSame('low', $nameFact['confidence']);
        self::assertSame('html:h1', $nameFact['source_locator']);
        self::assertSame('ota_ctrip_entity_snapshots#901', $nameFact['evidence_ref']);
    }

    public function testSelectedDateWithoutSnapshotKeepsAllDimensionsUnknownAndShowsLatestDate(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
            'data_date' => '2026-07-15',
            'collected_at' => '2026-07-15 09:30:00',
        ]]);

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertSame(0, $result['evidence_coverage']['observed_field_count']);
        self::assertSame(0, $result['evidence_coverage']['observed_dimension_count']);
        self::assertSame('2026-07-15', $result['latest_available_date']);
        self::assertCount(12, array_filter($result['dimensions'], static fn(array $row): bool => $row['status'] === 'unknown'));
        self::assertStringContainsString('最新可用日期为 2026-07-15', $result['next_action']);
    }

    public function testMeituanMissingPublicSourceIsExplicitInsteadOfBorrowingCtripEvidence(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'meituan', '2026-07-17', []);

        self::assertSame('public_profile_source_not_connected', $result['platform_source_status']);
        self::assertSame([], $result['sources']);
        self::assertSame(0, $result['evidence_coverage']['observed_field_count']);
        self::assertStringContainsString('不使用携程或内部经营数据替代', $result['next_action']);
    }

    public function testAvailableValueWithoutSourceLocatorDoesNotCountAsEvidence(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
            'ota_hotel_id' => '1',
            'data_date' => '2026-07-17',
            'source_url' => 'https://hotels.ctrip.com/hotels/1.html',
            'snapshot_id' => 1,
            'persistence_readback_verified' => true,
            'source_validation_status' => 'source_observed',
            'fields' => ['name' => '无定位证据'],
            'field_statuses' => ['name' => 'available'],
            'evidence_paths' => [],
        ]]);

        self::assertSame(0, $result['evidence_coverage']['observed_field_count']);
        self::assertContains('name', $result['dimensions'][0]['unknown_fields']);
    }

    public function testDatabaseReadbackDoesNotPromoteObservedSourceToVerified(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
            'ota_hotel_id' => '1',
            'snapshot_id' => 2,
            'data_date' => '2026-07-17',
            'capture_status' => 'available',
            'source_url' => 'https://hotels.ctrip.com/hotels/1.html',
            'persistence_readback_verified' => true,
            'source_validation_status' => 'source_observed',
            'fields' => ['name' => '仅观察来源'],
            'field_statuses' => ['name' => 'available'],
            'evidence_paths' => ['name' => 'html:h1'],
        ]]);

        $fact = $result['dimensions'][0]['facts'][0];
        self::assertSame('observed', $fact['quality_status']);
        self::assertSame('medium', $fact['confidence']);
        self::assertSame('readback_verified', $fact['persistence_readback_status']);
        self::assertSame('source_observed', $fact['source_validation_status']);
    }

    public function testCompleteStaleCoverageRemainsInsufficientAndLowConfidence(): void
    {
        $dimensions = (new ReflectionClass(OtaPublicPageDiagnosisService::class))->getConstant('DIMENSIONS');
        self::assertIsArray($dimensions);
        $fields = [];
        $statuses = [];
        $paths = [];
        foreach ($dimensions as $dimension) {
            foreach ($dimension['fields'] as $field) {
                $statuses[$field] = 'available';
                $paths[$field] = 'fixture:' . $field;
                $fields[$field] = 'value-' . $field;
            }
        }
        $fields['grade_label'] = '高档型';
        $fields['city_name'] = '重庆';
        $fields['cover_image_url'] = 'https://images.example.test/stale.jpg';

        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
            'ota_hotel_id' => '1',
            'snapshot_id' => 3,
            'data_date' => '2026-07-17',
            'capture_status' => 'stale',
            'source_url' => 'https://hotels.ctrip.com/hotels/1.html',
            'persistence_readback_verified' => true,
            'source_validation_status' => 'stale',
            'fields' => $fields,
            'field_statuses' => $statuses,
            'evidence_paths' => $paths,
        ]]);

        self::assertSame(36, $result['evidence_coverage']['observed_field_count']);
        self::assertSame(0, $result['evidence_coverage']['verified_field_count']);
        self::assertSame('insufficient_evidence', $result['status']);
        self::assertCount(12, array_filter($result['dimensions'], static fn(array $row): bool => $row['status'] === 'partial'));
        self::assertSame('stale', $result['dimensions'][0]['facts'][0]['quality_status']);
        self::assertSame('low', $result['dimensions'][0]['facts'][0]['confidence']);
        self::assertSame('stale', $result['sources'][0]['source_validation_status']);
        self::assertStringContainsString('字段覆盖已齐全', $result['next_action']);
        self::assertStringContainsString('过期', $result['next_action']);
    }
}
