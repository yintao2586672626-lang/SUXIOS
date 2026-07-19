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
        self::assertSame('self', $result['target_role']);
        self::assertNull($result['target_platform_hotel_id']);
        self::assertSame(0, $result['evidence_coverage']['observed_dimension_count']);
        self::assertSame('2026-07-15', $result['latest_available_date']);
        self::assertCount(12, array_filter($result['dimensions'], static fn(array $row): bool => $row['status'] === 'unknown'));
        self::assertStringContainsString('最新可用日期为 2026-07-15', $result['next_action']);
    }

    public function testDiagnosisUsesLatestSelfAttemptAndNeverBorrowsCompetitorFacts(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'ctrip', '2026-07-17', [
            [
                'platform' => 'ctrip',
                'ota_hotel_id' => '3456814',
                'role' => 'self',
                'snapshot_id' => 901,
                'data_date' => '2026-07-17',
                'collected_at' => '2026-07-17 09:00:00',
                'capture_status' => 'available',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'persistence_readback_verified' => true,
                'source_validation_status' => 'source_verified',
                'fields' => ['name' => 'old self fact'],
                'field_statuses' => ['name' => 'available'],
                'evidence_paths' => ['name' => 'html:h1'],
            ],
            [
                'platform' => 'ctrip',
                'ota_hotel_id' => '9999999',
                'role' => 'competitor',
                'snapshot_id' => 902,
                'data_date' => '2026-07-17',
                'collected_at' => '2026-07-17 11:00:00',
                'capture_status' => 'available',
                'source_url' => 'https://hotels.ctrip.com/hotels/9999999.html',
                'persistence_readback_verified' => true,
                'source_validation_status' => 'source_verified',
                'fields' => ['rating' => 4.9],
                'field_statuses' => ['rating' => 'available'],
                'evidence_paths' => ['rating' => 'html:review_score'],
            ],
            [
                'platform' => 'ctrip',
                'ota_hotel_id' => '3456814',
                'role' => 'self',
                'snapshot_id' => 901,
                'data_date' => '2026-07-17',
                'collected_at' => '2026-07-17 12:00:00',
                'capture_status' => 'collection_failed',
                'failure_reason' => 'http_failure',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'persistence_readback_verified' => true,
                'source_validation_status' => 'collection_failed',
                'fields' => [],
                'field_statuses' => [],
                'evidence_paths' => [],
            ],
        ]);

        self::assertSame(0, $result['evidence_coverage']['observed_field_count']);
        self::assertCount(1, $result['sources']);
        self::assertSame('self', $result['target_role']);
        self::assertSame('3456814', $result['target_platform_hotel_id']);
        self::assertSame('3456814', $result['sources'][0]['platform_hotel_id']);
        self::assertSame('self', $result['sources'][0]['role']);
        self::assertSame('collection_failed', $result['sources'][0]['capture_status']);
        self::assertContains('rating', $result['dimensions'][2]['unknown_fields']);
    }

    public function testBuildExecutionIntentDraftPreservesEvidenceIdentityAndGapState(): void
    {
        $service = new OtaPublicPageDiagnosisService();
        $diagnosis = $service->build(80, 'ctrip', '2026-07-17', [[
            'platform' => 'ctrip',
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
            'fields' => ['name' => '证据酒店'],
            'field_statuses' => ['name' => 'available'],
            'evidence_paths' => ['name' => 'html:h1'],
        ]]);

        $schedule = [
            'assignee_id' => 9,
            'due_at' => '2099-07-18T18:00',
            'review_at' => '2099-07-19T10:00',
        ];
        $draft = $service->buildExecutionIntentDraft($diagnosis, $schedule);
        $input = $draft['input'];

        self::assertSame('ota_diagnosis', $input['source_module']);
        self::assertSame('data_collection', $input['object_type']);
        self::assertSame('complete_public_page_evidence', $input['action_type']);
        self::assertSame(80, $input['hotel_id']);
        self::assertSame('ctrip', $input['platform']);
        self::assertSame('2026-07-17', $input['date_start']);
        self::assertSame('pending_approval', $input['status']);
        self::assertSame('public_page_verified_field_count', $input['expected_metric']);
        self::assertSame(36.0, (float)$input['expected_delta']);
        self::assertContains('ota_ctrip_entity_snapshots#901', $input['evidence']['evidence_refs']);
        self::assertContains('platform_basics:name:partial', $input['evidence']['data_gaps']);
        self::assertContains('platform_basics:address:missing', $input['evidence']['data_gaps']);
        self::assertSame('https://hotels.ctrip.com/hotels/3456814.html', $input['evidence']['sources'][0]['source_url']);
        self::assertSame(9, $input['target_value']['workflow_schedule']['assignee_id']);
        self::assertSame('2099-07-18 18:00:00', $input['target_value']['workflow_schedule']['due_at']);
        self::assertSame('2099-07-19 10:00:00', $input['evidence']['workflow_schedule']['review_at']);
        self::assertSame(OtaPublicPageDiagnosisService::EXECUTION_IDENTITY_VERSION, $input['evidence']['identity_version']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $input['evidence']['diagnosis_fingerprint']);
        self::assertMatchesRegularExpression('/^ota_diagnosis_action_[a-f0-9]{32}:attempt:1$/', $draft['idempotency_key']);
        self::assertGreaterThan(4294967295, $draft['source_record_id']);
        self::assertLessThan(9007199254740991, $draft['source_record_id']);
        self::assertLessThanOrEqual(268435456, $draft['legacy_source_record_id']);
        self::assertNotSame($draft['idempotency_key'], $draft['legacy_idempotency_key']);
        self::assertSame($draft, $service->buildExecutionIntentDraft($diagnosis, $schedule));

        $otherDate = $diagnosis;
        $otherDate['business_date'] = '2026-07-18';
        self::assertNotSame(
            $draft['idempotency_key'],
            $service->buildExecutionIntentDraft($otherDate, $schedule)['idempotency_key']
        );
        $otherSchedule = $schedule;
        $otherSchedule['due_at'] = '2099-07-18T20:00';
        self::assertSame(
            $draft['idempotency_key'],
            $service->buildExecutionIntentDraft($diagnosis, $otherSchedule)['idempotency_key']
        );
    }

    public function testCompleteEvidenceCreatesReviewTaskWithoutInventingScore(): void
    {
        $draft = (new OtaPublicPageDiagnosisService())->buildExecutionIntentDraft([
            'status' => 'evidence_complete',
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'business_date' => '2026-07-17',
            'platform_source_status' => 'persisted_public_profile_snapshots',
            'evidence_coverage' => [
                'observed_field_count' => 36,
                'verified_field_count' => 36,
                'expected_field_count' => 36,
                'coverage_rate' => 100,
            ],
            'score_status' => 'not_calculated_no_validated_scoring_rule',
            'dimensions' => [[
                'key' => 'platform_basics',
                'status' => 'verified',
                'facts' => [],
                'unknown_fields' => [],
            ]],
            'sources' => [[
                'platform_hotel_id' => '3456814',
                'response_ref' => 'ota_ctrip_entity_snapshots#901',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'source_validation_status' => 'source_verified',
                'persistence_readback_status' => 'readback_verified',
            ]],
            'source_policy' => 'persisted_public_page_facts_only_no_default_score_no_ota_write',
            'scope_notice' => '仅为 OTA 公开页证据目录。',
        ], [
            'assignee_id' => 9,
            'due_at' => '2099-07-18 18:00:00',
            'review_at' => '2099-07-19 10:00:00',
        ]);

        self::assertSame('review_public_page_evidence', $draft['input']['action_type']);
        self::assertSame('low', $draft['input']['risk_level']);
        self::assertSame(0.0, (float)$draft['input']['expected_delta']);
        self::assertSame([], $draft['input']['evidence']['data_gaps']);
        self::assertStringContainsString('不得据此外推全酒店经营结论', $draft['input']['target_value']['action_text']);
        self::assertSame('not_calculated_no_validated_scoring_rule', $draft['input']['current_value']['score_status']);
    }

    public function testVersionTwoIdentitySeparatesKnownLegacyHashCollision(): void
    {
        $service = new OtaPublicPageDiagnosisService();
        $diagnosis = [
            'status' => 'insufficient_evidence',
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'business_date' => '2026-07-17',
            'platform_source_status' => 'persisted_public_profile_snapshots',
            'evidence_coverage' => [
                'observed_field_count' => 1,
                'verified_field_count' => 0,
                'expected_field_count' => 36,
                'coverage_rate' => 2.78,
            ],
            'dimensions' => [[
                'key' => 'platform_basics',
                'status' => 'partial',
                'unknown_fields' => ['address'],
                'facts' => [['field_key' => 'name', 'quality_status' => 'partial']],
            ]],
            'sources' => [[
                'platform_hotel_id' => '3456814',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'response_ref' => 'fixture#18584',
                'persistence_readback_status' => 'readback_verified',
                'source_validation_status' => 'partial',
            ]],
            'next_action' => 'complete evidence',
            'score_status' => 'not_calculated_no_validated_scoring_rule',
            'source_policy' => 'persisted_public_page_facts_only_no_default_score_no_ota_write',
            'scope_notice' => 'OTA only',
        ];
        $schedule = [
            'assignee_id' => 9,
            'due_at' => '2099-07-18 18:00:00',
            'review_at' => '2099-07-19 10:00:00',
        ];

        $first = $service->buildExecutionIntentDraft($diagnosis, $schedule);
        $diagnosis['sources'][0]['response_ref'] = 'fixture#24690';
        $second = $service->buildExecutionIntentDraft($diagnosis, $schedule);

        self::assertSame($first['legacy_source_record_id'], $second['legacy_source_record_id']);
        self::assertNotSame($first['version_two_source_record_id'], $second['version_two_source_record_id']);
        self::assertNotSame($first['version_two_idempotency_key'], $second['version_two_idempotency_key']);
        self::assertSame($first['source_record_id'], $second['source_record_id']);
        self::assertSame($first['idempotency_key'], $second['idempotency_key']);
        self::assertSame($first['task_identity_fingerprint'], $second['task_identity_fingerprint']);
        self::assertNotSame($first['full_evidence_fingerprint'], $second['full_evidence_fingerprint']);
        self::assertGreaterThan(4294967295, $first['source_record_id']);
        self::assertGreaterThan(4294967295, $second['source_record_id']);
    }

    public function testExecutionIntentScheduleRejectsRelativePastAndNonSequentialTimes(): void
    {
        $service = new OtaPublicPageDiagnosisService();
        $diagnosis = $service->build(80, 'ctrip', '2026-07-17', []);
        $invalidSchedules = [
            [
                'assignee_id' => 9,
                'due_at' => 'tomorrow 18:00',
                'review_at' => '2099-07-19 10:00:00',
            ],
            [
                'assignee_id' => 9,
                'due_at' => '2000-01-01 18:00:00',
                'review_at' => '2000-01-02 10:00:00',
            ],
            [
                'assignee_id' => 9,
                'due_at' => '2099-07-18 18:00:00',
                'review_at' => '2099-07-18 18:00:00',
            ],
        ];

        foreach ($invalidSchedules as $schedule) {
            try {
                $service->buildExecutionIntentDraft($diagnosis, $schedule);
                self::fail('Invalid public-page task schedule must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testMeituanMissingPublicSourceIsExplicitInsteadOfBorrowingCtripEvidence(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'meituan', '2026-07-17', []);

        self::assertSame('manual_public_profile_entry_available', $result['platform_source_status']);
        self::assertSame([], $result['sources']);
        self::assertSame(0, $result['evidence_coverage']['observed_field_count']);
        self::assertStringContainsString('录入刚核对的美团公开页字段', $result['next_action']);
        self::assertStringContainsString('商家后台数据不能替代', $result['next_action']);
    }

    public function testMeituanManualPublicPageObservationCountsAsObservedButNotVerified(): void
    {
        $result = (new OtaPublicPageDiagnosisService())->build(80, 'meituan', '2026-07-17', [[
            'platform' => 'meituan',
            'system_hotel_id' => 80,
            'ota_hotel_id' => '24588',
            'role' => 'self',
            'snapshot_id' => 902,
            'response_ref' => 'online_daily_data#902',
            'data_date' => '2026-07-17',
            'collected_at' => '2026-07-17 10:30:00',
            'capture_status' => 'available',
            'source_method' => 'manual_public_page_observation',
            'source_url' => 'https://i.meituan.com/awp/hfe/block/hotel.html?poiId=24588',
            'persistence_readback_verified' => true,
            'source_validation_status' => 'source_observed',
            'fields' => [
                'name' => '页面可见酒店名',
                'platform_grade' => '高档型',
                'rating' => 4.8,
            ],
            'field_statuses' => [
                'name' => 'available',
                'platform_grade' => 'available',
                'rating' => 'available',
            ],
            'evidence_paths' => [
                'name' => '页面标题',
                'platform_grade' => '基础信息区',
                'rating' => '评分区域',
            ],
        ]]);

        self::assertSame('persisted_manual_public_page_observations', $result['platform_source_status']);
        self::assertSame(3, $result['evidence_coverage']['observed_field_count']);
        self::assertSame(0, $result['evidence_coverage']['verified_field_count']);
        self::assertSame('online_daily_data#902', $result['sources'][0]['response_ref']);
        $facts = array_merge(...array_column($result['dimensions'], 'facts'));
        $nameFact = array_values(array_filter($facts, static fn(array $fact): bool => $fact['field_key'] === 'name'))[0];
        self::assertSame('observed', $nameFact['quality_status']);
        self::assertSame('source_observed', $nameFact['source_validation_status']);
        self::assertSame('readback_verified', $nameFact['persistence_readback_status']);
        self::assertSame('online_daily_data#902', $nameFact['evidence_ref']);
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
