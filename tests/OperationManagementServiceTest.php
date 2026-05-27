<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OperationManagementServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testEffectValidationSummaryCalculatesProductLevelClosedLoopMetrics(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [
                [
                    'action_type' => 'price_adjust',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 1000, 'avg_conversion' => 10],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 1200, 'avg_conversion' => 12],
                    'result' => ['status' => 'success'],
                ],
                [
                    'action_type' => 'price_adjust',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 1000, 'avg_conversion' => 9],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 1050, 'avg_conversion' => 9],
                    'result' => ['status' => 'near_success'],
                ],
                [
                    'action_type' => 'promotion',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 500, 'avg_conversion' => 8],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 450, 'avg_conversion' => 7],
                    'result' => ['status' => 'failed'],
                ],
                [
                    'action_type' => 'room_inventory',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 300, 'avg_conversion' => 5],
                    'after' => ['data_status' => '待接入真实数据'],
                    'result' => ['status' => 'observing'],
                ],
            ],
            ['total' => 5, 'adopted' => 3, 'data_status' => 'ok'],
            ['reviewed' => 4, 'accurate' => 3, 'data_status' => 'ok'],
            [],
        ]);

        self::assertSame('ready', $summary['status']);
        self::assertSame(4, $summary['action_counts']['total']);
        self::assertSame(3, $summary['action_counts']['reviewed']);
        self::assertSame(1, $summary['action_counts']['observing']);

        self::assertSame(8.0, $this->metricValue($summary, 'revenue_lift_rate'));
        self::assertSame(3.7, $this->metricValue($summary, 'conversion_lift_rate'));
        self::assertSame(100.0, $this->metricValue($summary, 'pricing_hit_rate'));
        self::assertSame(60.0, $this->metricValue($summary, 'suggestion_adoption_rate'));
        self::assertSame(75.0, $this->metricValue($summary, 'alert_accuracy_rate'));
    }

    public function testEffectValidationSummaryMarksUnavailableMetricsInsteadOfInventingValues(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [],
            ['total' => 0, 'adopted' => 0, 'data_status' => 'empty'],
            ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'unlabeled'],
            [['code' => 'operation_alerts_accuracy_label_missing', 'message' => '预警缺少准确/误报复盘标签']],
        ]);

        self::assertSame('data_gap', $summary['status']);
        self::assertNull($this->metricValue($summary, 'revenue_lift_rate'));
        self::assertNull($this->metricValue($summary, 'alert_accuracy_rate'));
        self::assertContains('operation_alerts_accuracy_label_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testDailyFinancialExtractorsUseFallbackFieldsWithoutInventingValues(): void
    {
        $service = new OperationManagementService();
        $reportData = [
            'xb_revenue' => '1,200',
            'mt_revenue' => 800,
            'parking_revenue' => 50,
            'xb_rooms' => 4,
            'mt_rooms' => 3,
            'salable_rooms' => 20,
        ];

        self::assertSame(2050.0, $this->invokeNonPublic($service, 'extractRevenue', [[], $reportData]));
        self::assertSame(7.0, $this->invokeNonPublic($service, 'extractRoomNights', [[], $reportData]));
        self::assertSame(20.0, $this->invokeNonPublic($service, 'extractSalableRoomCount', [[], $reportData]));
        self::assertSame(0.0, $this->invokeNonPublic($service, 'extractRevenue', [[], ['xb_revenue' => 'bad']]));
    }

    public function testDashboardSummaryAggregatesDailyAndOnlineRowsWithoutDoubleCountingRevenue(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [[
                'hotel_id' => 7,
                'report_date' => '2026-05-18',
                'report_data' => json_encode([
                    'xb_revenue' => '1,200',
                    'mt_revenue' => 300,
                    'xb_rooms' => 4,
                    'mt_rooms' => 1,
                    'salable_rooms' => 10,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            [[
                'system_hotel_id' => 7,
                'data_date' => '2026-05-18',
                'amount' => 999,
                'quantity' => 9,
                'book_order_num' => 8,
                'raw_data' => json_encode(['bookOrderNum' => 9], JSON_UNESCAPED_UNICODE),
            ]],
            [7],
            7,
            '2026-05-18',
        ]);

        self::assertSame(1500.0, $summary['revenue']);
        self::assertSame(5.0, $summary['room_nights']);
        self::assertSame(9, $summary['orders']);
        self::assertSame(300.0, $summary['adr']);
        self::assertSame(50.0, $summary['occ']);
        self::assertSame(150.0, $summary['revpar']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testRootCauseRulesFlagDataTrafficPriceScoreAndHolidayBoundaries(): void
    {
        $service = new OperationManagementService();

        $result = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => ['orders' => 5, 'exposure' => 0, 'visitors' => 0, 'view_rate' => 2, 'order_rate' => 1],
            'summary' => ['adr' => 330],
            'competitors' => ['avg_price' => 250, 'avg_score' => 4.8],
            'reviews' => ['score' => 4.5],
            'holiday' => ['days_left' => 7, 'data_status' => 'ok'],
        ], ['exposure' => 100], ['view_rate' => 20, 'order_rate' => 10], 'conversion_low']);

        self::assertSame('high', $result['problem_level']);
        self::assertSame('data_abnormal', $result['root_causes'][0]['type']);
        self::assertContains('traffic_down', array_column($result['root_causes'], 'type'));
        self::assertContains('price_high', array_column($result['root_causes'], 'type'));
        self::assertContains('holiday_near', array_column($result['root_causes'], 'type'));

        $empty = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => [],
            'summary' => [],
            'competitors' => [],
            'reviews' => [],
            'holiday' => [],
        ], [], [], '']);

        self::assertSame('data_insufficient', $empty['problem_level']);
        self::assertSame('unknown', $empty['main_problem']);
        self::assertSame([], $empty['root_causes']);
    }

    private function metricValue(array $summary, string $key): mixed
    {
        foreach ($summary['metrics'] as $metric) {
            if (($metric['key'] ?? '') === $key) {
                return $metric['value'];
            }
        }

        self::fail('Metric not found: ' . $key);
    }
}
