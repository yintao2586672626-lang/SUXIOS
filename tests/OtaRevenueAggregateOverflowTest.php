<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class OtaRevenueAggregateOverflowTest extends TestCase
{
    public function testFinitePublicEtlFactsCannotMakeTheIncomeSummaryUnserializable(): void
    {
        $dataset = $this->dataset(1e308);
        $original = $dataset;
        self::assertTrue(is_finite($dataset['fact_ota_daily'][0]['room_revenue']));
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $json = json_encode($summary, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        self::assertSame($summary, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertNull($summary['totals']['room_revenue']);
        self::assertSame($original, $dataset);
    }

    public function testThePublicOverviewCannotTreatAnOverflowedIncomeAsAUsableNumber(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $this->dataset(1e308)], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertNotSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testNormalZeroAndMissingIncomeStayDistinct(): void
    {
        foreach ([[100.0, 200.0], [0.0, 0.0], [null, null]] as [$input, $expected]) {
            $summary = (new OtaRevenueMetricService())->summarizeDataset($this->dataset($input));
            self::assertSame($expected, $summary['totals']['room_revenue']);
            json_encode($summary, JSON_THROW_ON_ERROR);
        }
    }

    public function testOverflowReasonsReachTheWholePublicOverviewAndChannelGate(): void
    {
        $dataset = $this->dataset(1e308);
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $dataset], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertSame($overview, json_decode(json_encode($overview, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        foreach (['ota_room_revenue', 'ota_adr'] as $key) {
            self::assertNull($overview['metrics'][$key]['value']);
            self::assertSame('--', $overview['metrics'][$key]['display']);
            self::assertSame('numeric_aggregate_nonfinite', $overview['metrics'][$key]['reason']);
            self::assertSame('not_calculable', $overview['metrics'][$key]['status']);
            self::assertNotSame('verified', $overview['metrics'][$key]['truth']['status']);
            self::assertNotSame('collection_failed', $overview['metrics'][$key]['truth']['status']);
        }
        self::assertSame('numeric_aggregate_nonfinite', $overview['channel_metric_statuses']['ctrip']['metrics']['room_revenue']['reason']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        self::assertStringContainsString('不可计算', $overview['metrics']['ota_room_revenue']['display_reason']);
    }

    public function testMonetaryRatiosAndBookingBucketsDoNotLeakInfinityOrDenominatorZero(): void
    {
        $dataset = $this->dataset(1e308, ['available_room_nights' => 1, 'occupied_room_nights' => 1,
            'net_revenue' => 1e308, 'commission_amount' => 1e308, 'lead_time_days' => 1]);
        $original = $dataset;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame($summary, json_decode(json_encode($summary, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        foreach (['room_revenue', 'net_revenue', 'commission_amount', 'commission_rate', 'adr', 'revpar', 'net_revpar'] as $key) {
            self::assertNull($summary['totals'][$key]);
            self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['totals.' . $key]['failure_reasons']);
            self::assertFalse($summary['metric_trust']['totals.' . $key]['saved_success']);
        }
        self::assertSame(2.0, $summary['totals']['room_nights']);
        self::assertSame(100.0, $summary['totals']['occ']);
        self::assertNotEmpty($summary['booking_window_adr']['buckets']);
        self::assertNull($summary['booking_window_adr']['buckets'][0]['adr']);
        self::assertSame('numeric_aggregate_nonfinite', $summary['booking_window_adr']['reason']);
        self::assertSame($original, $dataset);
    }

    public function testOnlyTheCombinedAmountFailsWhenIndividualChannelsRemainFinite(): void
    {
        $channels = ['ctrip' => $this->dataset(1e308, [], 'ctrip', 1),
            'meituan' => $this->dataset(1e308, [], 'meituan', 1)];
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], $channels, [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip', 'meituan']]);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('numeric_aggregate_nonfinite', $overview['metrics']['ota_room_revenue']['reason']);
        foreach (array_keys($channels) as $channel) {
            self::assertSame(1e308, $overview['channel_metric_statuses'][$channel]['metrics']['room_revenue']['value']);
            self::assertNotSame('numeric_aggregate_nonfinite', $overview['channel_metric_statuses'][$channel]['metrics']['room_revenue']['reason']);
        }
        json_encode($overview, JSON_THROW_ON_ERROR);
        $service = new OtaRevenueMetricService();
        $service->summarizeDataset($this->dataset(1e308));
        $restored = $service->summarizeDataset($this->dataset(100));
        self::assertSame(200.0, $restored['totals']['room_revenue']);
        self::assertNotContains('numeric_aggregate_nonfinite', $restored['metric_trust']['totals.room_revenue']['failure_reasons']);
    }

    public function testAdvertisingOverflowCannotMasqueradeAsZeroRoas(): void
    {
        foreach ([[1e308, 100.0, null], [10.0, 0.0, 0.0], [10.0, 100.0, 10.0]] as [$spend, $income, $expected]) {
            $dataset = $this->dataset(100);
            $dataset['fact_ota_advertising'] = array_fill(0, 2, ['spend' => $spend, 'order_amount' => $income]);
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertSame($expected, $summary['advertising']['roas']);
            if ($expected === null) {
                self::assertNull($summary['advertising']['spend']);
                foreach (['spend', 'roas'] as $key) {
                    self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['advertising.' . $key]['failure_reasons']);
                }
            }
            json_encode($summary, JSON_THROW_ON_ERROR);
        }
    }

    private function dataset(?float $income, array $extra = [], string $platform = 'ctrip', int $count = 2): array
    {
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $rows[] = ['id' => 'synthetic-income-overflow-' . $platform . '-' . $index, 'source' => $platform,
                'hotel_id' => 'synthetic-hotel-80', 'system_hotel_id' => 80,
                'data_date' => '2026-07-28', 'data_type' => 'business',
                'raw_data' => json_encode(array_replace(['room_revenue' => $income, 'revenue' => $income,
                    'room_nights' => 1], $extra), JSON_THROW_ON_ERROR)];
        }
        return (new OtaStandardEtlService())->buildDatasetFromRows($rows);
    }
}
