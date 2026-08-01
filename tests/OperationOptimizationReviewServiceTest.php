<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationOptimizationReviewService;
use app\service\OperationOptimizationWorkbenchService;
use app\service\OtaStandardEtlService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class OperationOptimizationReviewServiceTest extends TestCase
{
    public function testBuildsSameLengthSourceVerifiedPayloadFromExactMetricReadback(): void
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $reviewDate = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
        $baselineDate = (new DateTimeImmutable('yesterday', $timezone))->format('Y-m-d');
        $executedAt = (new DateTimeImmutable('yesterday 10:00:00', $timezone))->format('Y-m-d H:i:s');
        $etlService = new class($baselineDate, $reviewDate) extends OtaStandardEtlService {
            /** @var array<int, array<string, mixed>> */
            public array $filters = [];

            public function __construct(
                private readonly string $baselineDate,
                private readonly string $reviewDate
            ) {
            }

            public function buildDataset(array $filters = []): array
            {
                $this->filters[] = $filters;
                $date = (string)($filters['start_date'] ?? '');
                $isBaseline = $date === $this->baselineDate;
                return [
                    'fact_ota_search_keyword' => [],
                    'fact_ota_advertising' => [[
                        'date_key' => $date,
                        'platform_key' => 'meituan',
                        'impressions' => 300,
                        'clicks' => 30,
                        'bookings' => 3,
                        'spend' => 50,
                        'order_amount' => $isBaseline ? 250 : 300,
                        'raw_data' => ['keyword' => '敦煌酒店'],
                        'source_trace' => [
                            'table' => 'online_daily_data',
                            'row_id' => $isBaseline ? 51 : 52,
                            'source_trace_id' => $isBaseline ? 'trace-51' : 'trace-52',
                            'platform_hotel_id' => 'meituan-hotel-77',
                            'ingestion_method' => 'profile_capture',
                            'collected_at' => $date . ($isBaseline ? ' 08:00:00' : ' 01:00:00'),
                            'stored' => true,
                            'readback_verified' => true,
                            'saved_success' => true,
                        ],
                    ]],
                    'fact_ota_daily' => [],
                    'fact_ota_traffic' => [],
                ];
            }
        };
        $service = new OperationOptimizationReviewService(
            $etlService,
            new OperationOptimizationWorkbenchService()
        );

        $payload = $service->buildSourceVerifiedMetricReadbackPayload(
            ['id' => 91, 'executed_at' => $executedAt],
            [
                'source_module' => 'operation_optimizer',
                'hotel_id' => 77,
                'platform' => 'meituan',
                'object_type' => 'campaign',
                'action_type' => 'advertising_budget_review',
                'expected_metric' => 'advertising_roas',
                'date_start' => $baselineDate,
                'date_end' => $baselineDate,
                'current_value' => ['roas' => 5],
                'target_value' => [
                    'keyword' => '敦煌酒店',
                    'expected_direction' => 'increase',
                ],
                'evidence' => [
                    'metric_scope' => 'ota_channel',
                    'evidence_refs' => ['online_daily_data#51', 'source_trace:trace-51'],
                    'platform_hotel_id' => 'meituan-hotel-77',
                    'identity_status' => 'matched',
                    'business_module' => 'keyword_workbench',
                    'fact_scope' => 'ota_channel_advertising',
                    'date_role' => 'business_date',
                    'source_method' => 'profile_capture',
                    'metric_unit' => 'ratio',
                    'expected_direction' => 'increase',
                ],
            ]
        );

        self::assertNotNull($payload);
        self::assertSame(['advertising_roas' => 5.0], $payload['before']);
        self::assertSame(['advertising_roas' => 6.0], $payload['after']);
        self::assertSame('source_verified_metric_readback', $payload['evidence_type']);
        self::assertSame($reviewDate, $payload['platform_response']['review_date']);
        self::assertSame('online_daily_data#52', $payload['platform_response']['source_ref']);
        self::assertSame('online_daily_data#51', $payload['platform_response']['baseline_source_ref']);
        self::assertSame('online_daily_data#52', $payload['platform_response']['followup_source_ref']);
        self::assertSame('source_verified', $payload['platform_response']['source_validation_status']);
        self::assertFalse($payload['platform_response']['causality_claimed']);
        self::assertSame('action_reviewed', $payload['platform_response']['learning_stage']);
        self::assertSame('aligned', $payload['platform_response']['expectation_status']);
        self::assertFalse($payload['platform_response']['candidate_sop_eligible']);
        self::assertSame($baselineDate, $etlService->filters[0]['start_date']);
        self::assertSame($baselineDate, $etlService->filters[0]['end_date']);
        self::assertSame($reviewDate, $etlService->filters[1]['start_date']);
        self::assertSame($reviewDate, $etlService->filters[1]['end_date']);
    }

    public function testMetricSnapshotRequiresSamePlatformObjectAndVerifiedSourceFacts(): void
    {
        $workbench = (new OperationOptimizationWorkbenchService())->build([
            'fact_ota_search_keyword' => [],
            'fact_ota_advertising' => [[
                'date_key' => '2026-07-28',
                'platform_key' => 'meituan',
                'impressions' => 300,
                'clicks' => 30,
                'bookings' => 3,
                'spend' => 50,
                'order_amount' => 250,
                'raw_data' => ['keyword' => '敦煌酒店'],
                'source_trace' => [
                    'table' => 'online_daily_data',
                    'row_id' => 52,
                    'source_trace_id' => 'trace-52',
                    'platform_hotel_id' => 'meituan-hotel-77',
                    'ingestion_method' => 'profile_capture',
                    'collected_at' => '2026-07-28 08:00:00',
                    'stored' => true,
                    'readback_verified' => true,
                    'saved_success' => true,
                ],
            ]],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ], [
            'hotel_id' => 77,
            'start_date' => '2026-07-28',
            'end_date' => '2026-07-28',
        ]);
        $intent = [
            'platform' => 'meituan',
            'object_type' => 'campaign',
            'expected_metric' => 'advertising_roas',
            'target_value' => ['keyword' => '敦煌酒店'],
        ];

        $snapshot = (new OperationOptimizationReviewService())->metricSnapshot($workbench, $intent);

        self::assertNotNull($snapshot);
        self::assertSame(5.0, $snapshot['value']);
        self::assertSame('敦煌酒店', $snapshot['subject']);
        self::assertContains('online_daily_data#52', $snapshot['evidence_refs']);
        self::assertSame('meituan-hotel-77', $snapshot['platform_hotel_id']);
        self::assertSame('2026-07-28 08:00:00', $snapshot['captured_at']);

        $intent['platform'] = 'ctrip';
        self::assertNull((new OperationOptimizationReviewService())->metricSnapshot($workbench, $intent));
    }

    public function testMultiDayBaselineWaitsForAnEqualLengthFollowupWindow(): void
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $executed = new DateTimeImmutable('yesterday 10:00:00', $timezone);
        $etlService = new class extends OtaStandardEtlService {
            public int $calls = 0;

            public function buildDataset(array $filters = []): array
            {
                $this->calls++;
                return [];
            }
        };
        $service = new OperationOptimizationReviewService(
            $etlService,
            new OperationOptimizationWorkbenchService()
        );

        $payload = $service->buildSourceVerifiedMetricReadbackPayload(
            ['id' => 92, 'executed_at' => $executed->format('Y-m-d H:i:s')],
            [
                'source_module' => 'operation_optimizer',
                'hotel_id' => 77,
                'platform' => 'meituan',
                'object_type' => 'campaign',
                'expected_metric' => 'advertising_roas',
                'date_start' => $executed->modify('-6 days')->format('Y-m-d'),
                'date_end' => $executed->format('Y-m-d'),
                'current_value' => ['roas' => 5],
                'target_value' => [
                    'keyword' => '敦煌酒店',
                    'expected_direction' => 'increase',
                ],
                'evidence' => [
                    'metric_scope' => 'ota_channel',
                    'evidence_refs' => ['online_daily_data#51'],
                    'platform_hotel_id' => 'meituan-hotel-77',
                    'identity_status' => 'matched',
                    'business_module' => 'keyword_workbench',
                    'fact_scope' => 'ota_channel_advertising',
                    'date_role' => 'business_date',
                    'source_method' => 'profile_capture',
                    'metric_unit' => 'ratio',
                ],
            ]
        );

        self::assertNull($payload);
        self::assertSame(0, $etlService->calls);
    }
}
