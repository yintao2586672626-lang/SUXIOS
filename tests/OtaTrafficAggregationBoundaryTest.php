<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class OtaTrafficAggregationBoundaryTest extends TestCase
{
    private function traffic(string $date, array $values): array
    {
        return array_merge([
            'date_key' => $date, 'hotel_key' => 'system:80', 'platform_key' => 'meituan',
            'compare_type' => 'self', 'source_trace' => [],
        ], $values);
    }

    public function testMultiDayRatesUseSummedAlignedCountsInsteadOfAveragePercentages(): void
    {
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic' => [
            $this->traffic('2026-08-01', ['list_exposure' => 100, 'detail_exposure' => 10, 'flow_rate' => 10,
                'order_filling_num' => 10, 'order_submit_num' => 1, 'submit_rate' => 10]),
            $this->traffic('2026-08-02', ['list_exposure' => 1000, 'detail_exposure' => 900, 'flow_rate' => 90,
                'order_filling_num' => 100, 'order_submit_num' => 90, 'submit_rate' => 90]),
        ]]);
        self::assertSame(82.73, $result['traffic']['avg_flow_rate']);
        self::assertSame(82.73, $result['traffic']['avg_submit_rate']);
    }

    public function testMultiDayRateWithoutItsDenominatorsIsNotCalculable(): void
    {
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic' => [
            $this->traffic('2026-08-01', ['flow_rate' => 10]),
            $this->traffic('2026-08-02', ['flow_rate' => 90]),
        ]]);
        self::assertNull($result['traffic']['avg_flow_rate']);
        self::assertContains('traffic_flow_rate_counts_missing', array_column($result['data_gaps'], 'code'));
    }

    public function testSingleSourceRateCanRemainDirectWhenNoCountsWereSupplied(): void
    {
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic' => [
            $this->traffic('2026-08-01', ['flow_rate' => 10.25]),
        ]]);
        self::assertSame(10.25, $result['traffic']['avg_flow_rate']);
    }

    public function testMissingRateDayCannotDisappearFromTheAggregationScope(): void
    {
        $rows = [
            $this->traffic('2026-08-01', ['flow_rate' => 10, 'list_exposure' => 100, 'detail_exposure' => 10]),
            $this->traffic('2026-08-02', ['flow_rate' => null, 'list_exposure' => 1000, 'detail_exposure' => 900]),
        ];
        $service = new OtaRevenueMetricService();
        self::assertSame(82.73, $service->summarizeDataset(['fact_ota_traffic' => $rows])['traffic']['avg_flow_rate']);
        $rows[1]['detail_exposure'] = null;
        $missing = $service->summarizeDataset(['fact_ota_traffic' => $rows]);
        self::assertNull($missing['traffic']['avg_flow_rate']);
        self::assertContains('traffic_flow_rate_counts_missing', array_column($missing['data_gaps'], 'code'));
    }

    public function testEmptyLatestBatchDoesNotBorrowPreviousBatchTraffic(): void
    {
        $rows = [];
        foreach ([11 => 30, 12 => null] as $taskId => $exposure) {
            $rows[] = $this->traffic('2026-08-01', [
                'dimension' => 'flow_conversion', 'list_exposure' => $exposure,
                'source_trace' => ['row_id' => $taskId, 'sync_task_id' => $taskId, 'data_period' => 'realtime_snapshot'],
                'raw_data' => ['row' => ['_capture_source' => 'xhr:traffic:traffic', '_source_path' => 'data.myHotel']],
            ]);
        }
        $result = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic' => $rows]);
        self::assertNull($result['traffic']['list_exposure']);
    }

    public function testMeituanDefaultDateTrafficCannotBecomeRevenueMetricOrBorrowOlderBatch(): void
    {
        $rows = [
            $this->traffic('2026-08-01', [
                'list_exposure' => 300,
                'dimension' => 'flow_conversion',
                'source_trace' => ['row_id' => 11, 'sync_task_id' => 11],
                'raw_data' => ['date_source' => 'page.business_period_selection.readback',
                    'row' => ['_capture_source' => 'xhr:traffic:traffic', '_source_path' => 'data.myHotel']],
            ]),
            $this->traffic('2026-08-01', [
                'list_exposure' => 900,
                'dimension' => 'flow_conversion',
                'source_trace' => ['row_id' => 12, 'sync_task_id' => 12],
                'raw_data' => ['date_source' => 'capture_context.default_data_date',
                    'row' => ['_capture_source' => 'xhr:traffic:traffic', '_source_path' => 'data.myHotel']],
            ]),
        ];

        $service = new OtaRevenueMetricService();
        self::assertNull($service->summarizeDataset(['fact_ota_traffic' => $rows])['traffic']['list_exposure']);

        $rows[1]['raw_data']['date_source'] = 'page.business_period_selection.readback';
        self::assertSame(900, $service->summarizeDataset(['fact_ota_traffic' => $rows])['traffic']['list_exposure']);
    }

    public function testInvalidDateSourceDayCannotMakeMultiDayTrafficLookComplete(): void
    {
        $rows = [
            $this->traffic('2026-08-01', [
                'list_exposure' => 100, 'detail_exposure' => 10, 'flow_rate' => 10,
                'raw_data' => ['date_source' => 'page.business_period_selection.readback'],
            ]),
            $this->traffic('2026-08-02', [
                'list_exposure' => 200, 'detail_exposure' => 40, 'flow_rate' => 20,
                'raw_data' => ['date_source' => 'capture_context.default_data_date'],
            ]),
        ];
        $traffic = (new OtaRevenueMetricService())->summarizeDataset(['fact_ota_traffic' => $rows])['traffic'];
        self::assertNull($traffic['list_exposure']);
        self::assertNull($traffic['avg_flow_rate']);
    }

    public function testBadMeituanDateDoesNotHideIndependentCtripChannelMetric(): void
    {
        $ctrip = $this->traffic('2026-08-01', [
            'platform_key' => 'ctrip', 'list_exposure' => 70,
            'raw_data' => ['date_source' => 'row.statDate'],
        ]);
        $meituan = $this->traffic('2026-08-01', [
            'list_exposure' => 900,
            'raw_data' => ['date_source' => 'capture_context.default_data_date'],
        ]);
        $summary = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_traffic' => [$ctrip, $meituan],
        ]);
        self::assertNull($summary['traffic']['list_exposure']);
        $trafficMetrics = array_values(array_filter(
            $summary['channel_metrics'],
            static fn(array $metric): bool => $metric['resource'] === 'traffic'
                && $metric['metric_key'] === 'list_exposure'
        ));
        self::assertCount(1, $trafficMetrics);
        self::assertSame('ctrip', $trafficMetrics[0]['platform']);
        self::assertSame(70.0, $trafficMetrics[0]['value']);
    }
}
