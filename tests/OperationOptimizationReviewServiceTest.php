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
    public function testBuildsNextDaySourceVerifiedPayloadFromExactMetricReadback(): void
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $reviewDate = (new DateTimeImmutable('today', $timezone))->format('Y-m-d');
        $executedAt = (new DateTimeImmutable('yesterday 10:00:00', $timezone))->format('Y-m-d H:i:s');
        $etlService = new class($reviewDate) extends OtaStandardEtlService {
            /** @var array<string, mixed> */
            public array $filters = [];

            public function __construct(private readonly string $reviewDate)
            {
            }

            public function buildDataset(array $filters = []): array
            {
                $this->filters = $filters;
                return [
                    'fact_ota_search_keyword' => [],
                    'fact_ota_advertising' => [[
                        'date_key' => $this->reviewDate,
                        'platform_key' => 'meituan',
                        'impressions' => 300,
                        'clicks' => 30,
                        'bookings' => 3,
                        'spend' => 50,
                        'order_amount' => 300,
                        'raw_data' => ['keyword' => '敦煌酒店'],
                        'source_trace' => [
                            'table' => 'online_daily_data',
                            'row_id' => 52,
                            'source_trace_id' => 'trace-52',
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
                'expected_metric' => 'advertising_roas',
                'date_start' => substr($executedAt, 0, 10),
                'date_end' => substr($executedAt, 0, 10),
                'current_value' => ['roas' => 5],
                'target_value' => ['keyword' => '敦煌酒店'],
            ]
        );

        self::assertNotNull($payload);
        self::assertSame(['advertising_roas' => 5.0], $payload['before']);
        self::assertSame(['advertising_roas' => 6.0], $payload['after']);
        self::assertSame('source_verified_metric_readback', $payload['evidence_type']);
        self::assertSame($reviewDate, $payload['platform_response']['review_date']);
        self::assertSame('online_daily_data#52', $payload['platform_response']['source_ref']);
        self::assertSame('source_verified', $payload['platform_response']['source_validation_status']);
        self::assertFalse($payload['platform_response']['causality_claimed']);
        self::assertSame($reviewDate, $etlService->filters['start_date']);
        self::assertSame($reviewDate, $etlService->filters['end_date']);
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

        $intent['platform'] = 'ctrip';
        self::assertNull((new OperationOptimizationReviewService())->metricSnapshot($workbench, $intent));
    }
}
