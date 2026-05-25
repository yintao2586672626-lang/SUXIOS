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
