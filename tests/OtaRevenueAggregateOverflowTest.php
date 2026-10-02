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

    public function testFiniteCountsBeyondIntegerRangeStayPositiveAcrossPublicProjections(): void
    {
        $dataset = $this->countDataset(1e20);
        $original = $dataset;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        foreach ($this->countPaths() as [$section, $key]) {
            self::assertSame(2e20, $summary[$section][$key], $section . '.' . $key);
        }
        foreach (['by_platform', 'by_hotel'] as $section) {
            self::assertSame(2e20, $summary[$section][0]['order_count']);
        }
        self::assertSame(2e20, $summary['booking_window_adr']['buckets'][0]['order_count']);
        self::assertSame(2e20, $summary['channel_booking_window_month']['cells'][0]['order_count']);
        self::assertSame(100.0, $summary['channel_booking_window_month']['cells'][0]['order_share']);
        self::assertSame($original, $dataset);
        json_encode($summary, JSON_THROW_ON_ERROR);
    }

    public function testOverflowedCountsCannotBecomeZeroOrSupportedBookingSamples(): void
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($this->countDataset(1e308));
        foreach ($this->countPaths() as [$section, $key]) {
            self::assertNull($summary[$section][$key], $section . '.' . $key);
            self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust'][$section . '.' . $key]['failure_reasons']);
        }
        foreach (['by_platform', 'by_hotel'] as $section) {
            self::assertNull($summary[$section][0]['order_count']);
        }
        self::assertNull($summary['booking_window_adr']['buckets'][0]['order_count']);
        $cell = $summary['channel_booking_window_month']['cells'][0];
        foreach (['order_count', 'channel_month_order_count', 'order_share'] as $key) self::assertNull($cell[$key]);
        self::assertSame('not_calculable', $cell['sample_status']);
        self::assertSame('not_calculable', $summary['channel_booking_window_month']['status']);
        self::assertSame('numeric_aggregate_nonfinite', $summary['channel_booking_window_month']['reason']);
        self::assertSame(0, $summary['channel_booking_window_month']['supported_cell_count']);
        self::assertSame(200.0, $summary['totals']['room_revenue']);
        json_encode($summary, JSON_THROW_ON_ERROR);
    }

    public function testOverflowedCancellationDenominatorsAreNotVerifiedZero(): void
    {
        foreach ([false, true] as $directRate) {
            $dataset = $this->countDataset(1e308);
            foreach ($dataset['fact_ota_daily'] as &$row) {
                $row['gross_order_count'] = 1e308;
                $row['unknown_status_order_count'] = 0;
                $row['cancel_rate_basis'] = 'cancelled_orders_over_gross_orders_complete_classification';
                if ($directRate) $row['cancel_rate'] = 10;
                else $row['cancel_order_num'] = 100;
            }
            unset($row);
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertNull($summary['totals']['gross_order_count']);
            self::assertNull($summary['totals']['cancellation_rate']);
            self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['totals.cancellation_rate']['failure_reasons']);
            self::assertNotContains('cancellation_gross_order_base_zero', array_column($summary['data_gaps'], 'code'));
            if (!$directRate) self::assertSame(200, $summary['totals']['cancel_order_count']);
            json_encode($summary, JSON_THROW_ON_ERROR);
        }
    }

    public function testHugeFiniteLeadTimeCannotWrapIntoTheSameDayBucket(): void
    {
        $dataset = $this->countDataset(10);
        foreach ($dataset['fact_ota_daily'] as &$row) $row['lead_time_days'] = 1e308;
        unset($row);
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame('days_31_plus', $summary['booking_window_adr']['buckets'][0]['key']);
        self::assertSame('days_31_plus', $summary['channel_booking_window_month']['cells'][0]['booking_window_key']);
    }

    public function testPublicEtlReadPreservesFiniteLargeCountsBeforeAggregation(): void
    {
        foreach ([10.0, 0.0, 1e20, 1e308] as $count) {
            $rows = [];
            foreach (['business', 'traffic', 'advertising'] as $type) {
                for ($index = 0; $index < 2; $index++) {
                    $rows[] = ['id' => 'synthetic-count-' . $type . '-' . $index, 'source' => 'ctrip',
                        'hotel_id' => 'synthetic-hotel-80', 'system_hotel_id' => 80, 'data_date' => '2026-07-28', 'data_type' => $type,
                        'raw_data' => json_encode(['book_order_num' => $count, 'gross_order_count' => $count,
                            'unknown_status_order_count' => $count, 'room_revenue' => 100, 'room_nights' => 1,
                            'list_exposure' => $count, 'detail_exposure' => $count, 'order_filling_num' => $count,
                            'order_submit_num' => $count, 'lead_time_days' => 1e308, 'checkin_date' => '2026-07-29'], JSON_THROW_ON_ERROR)];
                }
            }
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($rows);
            foreach (['order_count', 'gross_order_count', 'unknown_status_order_count'] as $key) {
                self::assertEquals($count, $dataset['fact_ota_daily'][0][$key], $key);
            }
            self::assertSame(1e308, $dataset['fact_ota_daily'][0]['lead_time_days']);
            foreach (['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'] as $key) {
                self::assertEquals($count, $dataset['fact_ota_traffic'][0][$key], $key);
            }
            foreach (['bookings', 'impressions', 'clicks'] as $key) {
                self::assertEquals($count, $dataset['fact_ota_advertising'][0][$key], $key);
            }
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            $expected = $count === 1e308 ? null : $count * 2;
            foreach ([['totals', 'order_count'], ['traffic', 'list_exposure'], ['advertising', 'bookings']] as [$section, $key]) {
                if ($expected === null) self::assertNull($summary[$section][$key]);
                else self::assertEquals($expected, $summary[$section][$key]);
            }
            json_encode($summary, JSON_THROW_ON_ERROR);
        }
        $method = new \ReflectionMethod(OtaStandardEtlService::class, 'nonNegativeIntegerValue');
        self::assertNull($method->invoke(new OtaStandardEtlService(), (float)PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, $method->invoke(new OtaStandardEtlService(), PHP_INT_MAX));
        self::assertSame(PHP_INT_MAX, $method->invoke(new OtaStandardEtlService(), (string)PHP_INT_MAX));
        self::assertNull($method->invoke(new OtaStandardEtlService(), (string)PHP_INT_MAX . '0'));
        self::assertSame(10, $method->invoke(new OtaStandardEtlService(), '+00010'));
        self::assertSame(10, $method->invoke(new OtaStandardEtlService(), 10));
    }

    public function testFiniteBookingCellsCannotReportZeroShareWhenTheChannelTotalOverflows(): void
    {
        $dataset = $this->countDataset(1e308);
        $dataset['fact_ota_daily'][1]['lead_time_days'] = 8;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertCount(2, $summary['channel_booking_window_month']['cells']);
        foreach ($summary['channel_booking_window_month']['cells'] as $cell) {
            self::assertSame(1e308, $cell['order_count']);
            self::assertNull($cell['channel_month_order_count']);
            self::assertNull($cell['order_share']);
            self::assertSame('not_calculable', $cell['sample_status']);
        }
        self::assertSame(0, $summary['channel_booking_window_month']['supported_cell_count']);
        self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['channel_booking_window_month.cells']['failure_reasons']);
        json_encode($summary, JSON_THROW_ON_ERROR);
    }

    public function testAttestedDigestCannotCollapseALargeFiniteFactIntoZero(): void
    {
        $service = new \app\service\RevenueDecisionViewModelAttestationService();
        foreach ([1e20, 1e308, (float)PHP_INT_MAX] as $value) {
            self::assertNotSame($service->issuedDigest(['value' => 0]), $service->issuedDigest(['value' => $value]));
        }
        self::assertSame($service->issuedDigest(['value' => 10]), $service->issuedDigest(['value' => 10.0]));
        $method = new \ReflectionMethod($service, 'canonicalize');
        self::assertSame(1e308, $method->invoke($service, ['value' => 1e308])['value']);
        $sameValue = new \ReflectionMethod($service, 'sameValue');
        self::assertFalse($sameValue->invoke($service, 0, 1e308));
    }

    public function testBookingSignalPreservesNumericFailureAndSortsFiniteCountsWithoutWrapping(): void
    {
        $service = new RevenueAiOverviewService();
        $method = new \ReflectionMethod($service, 'channelBookingWindowMonthSignal');
        $metrics = new OtaRevenueMetricService();
        $failed = $method->invoke($service, $metrics->summarizeDataset($this->countDataset(1e308)), ['ctrip']);
        self::assertSame('not_calculable', $failed['status']);
        self::assertSame('numeric_aggregate_nonfinite', $failed['reason']);
        self::assertStringNotContainsString('低于最小样本', $failed['detail']);
        $dataset = $this->countDataset(1e20);
        $dataset['fact_ota_daily'][1]['order_count'] = 2e20;
        $dataset['fact_ota_daily'][1]['lead_time_days'] = 8;
        $summary = $metrics->summarizeDataset($dataset);
        // This pure sorting case supplies synthetic verified-source evidence.
        // The current-main source/readback guard must not be weakened for it.
        $summary['metric_trust']['channel_booking_window_month.cells'] = [
            'saved_success' => true, 'failure_reasons' => [],
            'truth' => ['status' => 'verified', 'persistence' => [
                'stored' => true, 'readback_verified' => true,
            ]],
        ];
        $signal = $method->invoke($service, $summary, ['ctrip']);
        self::assertStringStartsWith('2026-07 携程 8-14天', $signal['value']);
    }

    private function countPaths(): array
    {
        return [['totals', 'order_count'], ['traffic', 'list_exposure'], ['traffic', 'detail_exposure'],
            ['advertising', 'bookings'], ['advertising', 'impressions'], ['advertising', 'clicks'], ['quality', 'hotel_collect']];
    }

    private function countDataset(float $count): array
    {
        $row = ['platform_key' => 'ctrip', 'hotel_key' => 'synthetic-hotel-80', 'date_key' => '2026-07-28',
            'room_revenue' => 100.0, 'revenue' => 100.0, 'room_nights' => 1,
            'order_count' => $count, 'lead_time_days' => 1, 'checkin_date' => '2026-07-29'];
        return ['fact_ota_daily' => [$row, $row],
            'fact_ota_traffic' => array_fill(0, 2, ['list_exposure' => $count, 'detail_exposure' => $count]),
            'fact_ota_advertising' => array_fill(0, 2, ['bookings' => $count, 'impressions' => $count, 'clicks' => $count]),
            'fact_ota_quality' => array_fill(0, 2, ['hotel_collect' => $count])];
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
