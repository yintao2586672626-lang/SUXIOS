<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiPricingMetricEvidenceGateTest.php';

final class RevenueAiCanonicalRequestedPlatformScopeTest extends TestCase
{
    public function testCtripOnlyRequestCannotDisplayMeituanCanonicalMetricsAsCurrent(): void
    {
        $ctrip = $this->dataset('ctrip');
        $layer = $this->layer($this->dataset('meituan'));
        $overview = $this->overview(['ctrip' => $ctrip], $layer, ['ctrip']);
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            self::assertNull($overview['metrics'][$key]['value'], $key);
            self::assertSame('unverified', $overview['metrics'][$key]['status']);
            self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
        }
    }

    public function testSingleChannelRequestCannotUseTheCombinedTwoChannelCanonicalTotal(): void
    {
        $ctrip = $this->dataset('ctrip');
        $combined = $ctrip;
        $combined['fact_ota_daily'] = array_merge($ctrip['fact_ota_daily'], $this->dataset('meituan')['fact_ota_daily']);
        $overview = $this->overview(['ctrip' => $ctrip], $this->layer($combined), ['ctrip']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
    }

    public function testMatchingSingleChannelCanonicalMetricsStillDisplayPrecisely(): void
    {
        $ctrip = $this->dataset('ctrip');
        $overview = $this->overview(['ctrip' => $ctrip], $this->layer($ctrip), ['ctrip']);
        self::assertSame(100.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(1.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame(100.0, $overview['metrics']['ota_adr']['value']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame(['ctrip'], $overview['metrics']['ota_room_revenue']['source_channels']);
    }

    public function testMatchingTwoChannelCanonicalMetricsKeepTheirOwnCombinedScope(): void
    {
        $ctrip = $this->dataset('ctrip');
        $meituan = $this->dataset('meituan');
        $combined = $ctrip;
        $combined['fact_ota_daily'] = array_merge($ctrip['fact_ota_daily'], $meituan['fact_ota_daily']);
        $overview = $this->overview(['ctrip' => $ctrip, 'meituan' => $meituan], $this->layer($combined), ['ctrip', 'meituan']);
        self::assertSame(200.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(2.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame(['ctrip', 'meituan'], $overview['metrics']['ota_room_revenue']['source_channels']);
    }

    public function testMeituanOnlyRequestCannotUseCtripCanonicalMetrics(): void
    {
        $overview = $this->overview(['meituan' => $this->dataset('meituan')],
            $this->layer($this->dataset('ctrip')), ['meituan']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
        self::assertSame(['meituan'], $overview['actual_source_channels']);
    }

    public function testTwoChannelRequestCannotUseOneChannelCanonicalTotals(): void
    {
        $ctrip = $this->dataset('ctrip');
        $overview = $this->overview(['ctrip' => $ctrip, 'meituan' => $this->dataset('meituan')],
            $this->layer($ctrip), ['ctrip', 'meituan']);
        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
            self::assertNull($overview['metrics'][$key]['value']);
            self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
        }
    }

    public function testMissingForeignOrUnknownSourceLabelsCannotBorrowMatchingProof(): void
    {
        $ctrip = $this->dataset('ctrip');
        foreach ([[], ['meituan'], ['ctrip', 'unknown_platform']] as $labels) {
            $layer = $this->layer($ctrip);
            foreach ($layer['analysis_metrics'] as &$metric) {
                $metric['source_channels'] = $labels;
            }
            unset($metric);
            $overview = $this->overview(['ctrip' => $ctrip], $layer, ['ctrip']);
            foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr'] as $key) {
                self::assertNull($overview['metrics'][$key]['value']);
                self::assertSame('unverified', $overview['metrics'][$key]['status']);
                self::assertSame('metric_scope_mismatch', $overview['metrics'][$key]['reason']);
            }
        }
    }

    public function testRequestLabelsCannotHideForeignTruth(): void
    {
        $ctrip = $this->dataset('ctrip');
        $layer = $this->layer($this->dataset('meituan'));
        foreach ($layer['analysis_metrics'] as &$metric) {
            $metric['source_channels'] = ['ctrip'];
        }
        unset($metric);
        $overview = $this->overview(['ctrip' => $ctrip], $layer, ['ctrip']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
    }

    public function testEquivalentPlatformOrderCasingAndDuplicatesRemainUsable(): void
    {
        $ctrip = $this->dataset('ctrip');
        $meituan = $this->dataset('meituan');
        $combined = $ctrip;
        $combined['fact_ota_daily'] = array_merge($ctrip['fact_ota_daily'], $meituan['fact_ota_daily']);
        $layer = $this->layer($combined);
        foreach ($layer['analysis_metrics'] as &$metric) {
            $metric['source_channels'] = [' MEITUAN ', 'CTRIP', 'ctrip'];
        }
        unset($metric);
        $overview = $this->overview(['ctrip' => $ctrip, 'meituan' => $meituan], $layer, ['meituan', 'ctrip']);
        self::assertSame(200.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('ok', $overview['metrics']['ota_room_revenue']['status']);
    }

    public function testSamePlatformsDoNotOverrideHotelDateOrFailureChecks(): void
    {
        $ctrip = $this->dataset('ctrip');
        foreach (['hotel', 'date', 'collection_failed', 'partial'] as $scenario) {
            $layer = $this->layer($ctrip);
            $metric = &$layer['analysis_metrics']['ota_room_revenue'];
            if ($scenario === 'hotel') {
                $metric['truth']['hotels'] = [['system_hotel_id' => 81]];
            } elseif ($scenario === 'date') {
                $metric['truth']['date_range'] = ['start' => '2026-07-27', 'end' => '2026-07-27'];
            } else {
                $metric['truth']['status'] = $scenario;
            }
            unset($metric);
            $overview = $this->overview(['ctrip' => $ctrip], $layer, ['ctrip']);
            if (in_array($scenario, ['hotel', 'date'], true)) {
                self::assertNull($overview['metrics']['ota_room_revenue']['value']);
                self::assertSame('metric_scope_mismatch', $overview['metrics']['ota_room_revenue']['reason']);
            } else {
                self::assertSame($scenario, $overview['metrics']['ota_room_revenue']['status']);
                self::assertSame('metric_truth_' . $scenario, $overview['metrics']['ota_room_revenue']['reason']);
            }
        }
    }

    public function testOneForeignMetricDoesNotInvalidateOtherMatchingMetrics(): void
    {
        $ctrip = $this->dataset('ctrip');
        $layer = $this->layer($ctrip);
        $layer['analysis_metrics']['ota_room_revenue'] = $this->layer($this->dataset('meituan'))['analysis_metrics']['ota_room_revenue'];
        $overview = $this->overview(['ctrip' => $ctrip], $layer, ['ctrip']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(1.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame(100.0, $overview['metrics']['ota_adr']['value']);
    }

    private function dataset(string $platform): array
    {
        $fixture = new RevenueAiPricingMetricEvidenceGateTest('testVerifiedSameScopeIncomeAndNightsRemainReady');
        return (new \ReflectionMethod($fixture, 'verifiedDataset'))->invoke($fixture, $platform);
    }

    private function layer(array $dataset): array
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $metrics = [];
        foreach (['ota_room_revenue' => ['room_revenue', 'CNY'], 'ota_room_nights' => ['room_nights', 'room_nights'],
            'ota_adr' => ['adr', 'CNY']] as $key => [$field, $unit]) {
            $truth = $summary['metric_trust']['totals.' . $field]['truth'];
            $metrics[$key] = ['value' => $summary['totals'][$field], 'unit' => $unit, 'status' => 'ok',
                'scope' => 'ota_channel', 'date_basis' => 'data_date', 'source_channels' => $truth['platforms'], 'truth' => $truth];
        }
        return ['all_three_sources_readback_verified' => true, 'analysis_metrics' => $metrics];
    }

    private function overview(array $channels, array $layer, array $enabled): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset([], $channels, [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => $enabled, 'revenue_fact_layer' => $layer]);
    }
}
