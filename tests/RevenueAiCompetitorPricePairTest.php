<?php
declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/RevenueAiCompetitorSignalEvidenceTest.php';

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiCompetitorPricePairTest extends TestCase
{
    public function testPublicEtlInputWithConflictingGapUsesObservedPricePairsInAnalysis(): void
    {
        $dataset = $this->dataset('conflicting_gap');
        self::assertSame(-25.0, $dataset['fact_ota_daily'][0]['price_gap']);
        $original = $dataset;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame(170.0, $summary['competitor_price']['avg_our_price']);
        self::assertSame(100.0, $summary['competitor_price']['avg_competitor_price']);
        self::assertSame(70.0, $summary['competitor_price']['avg_price_gap']);
        self::assertSame($original, $dataset, 'Analysis must preserve collected facts');
    }

    public function testPublicWarningCannotContradictItsSamePairObservedPrices(): void
    {
        $overview = $this->overview('opposite_gap');
        $signal = $overview['signals']['competitor_price_warning'];
        self::assertSame('本店高于竞对 ¥70.00', $signal['value']);
        self::assertSame(170.0, $signal['detail_metrics']['avg_our_price']);
        self::assertSame(100.0, $signal['detail_metrics']['avg_competitor_price']);
        self::assertSame(70.0, $signal['detail_metrics']['avg_price_gap']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testPairedFactsWithMissingDerivedGapDoNotUseOnlyOneRowsGap(): void
    {
        $dataset = $this->dataset('partial_gap');
        self::assertNull($dataset['fact_ota_daily'][0]['price_gap']);
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame(70.0, $summary['competitor_price']['avg_price_gap']);
        self::assertSame(70.0, $summary['competitor_price']['avg_price_gap_rate']);
        self::assertSame(2, $summary['metric_trust']['competitor_price.avg_price_gap']['source']['row_count']);
    }

    public function testNormalConsistentPublicEtlAndOriginalEvidenceRemainUsable(): void
    {
        $overview = $this->overview('normal');
        self::assertSame('本店高于竞对 ¥70.00', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('verified', $overview['signals']['competitor_price_warning']['truth']['status']);
        self::assertSame(['ctrip'], $overview['signals']['competitor_price_warning']['source_channels']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testZeroCompetitorPriceIsRetainedWithoutClaimingAnUndefinedRate(): void
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('zero_competitor'));
        self::assertSame(2, $summary['competitor_price']['rows']);
        self::assertSame(120.0, $summary['competitor_price']['avg_price_gap']);
        self::assertSame(100.0, $summary['competitor_price']['avg_price_gap_rate']);
        self::assertSame(1, $summary['metric_trust']['competitor_price.avg_price_gap_rate']['source']['row_count']);
        self::assertSame(['synthetic-booking-1'], $summary['metric_trust']['competitor_price.avg_price_gap_rate']['source']['row_ids']);
    }

    public function testDifferentDenominatorsRetainMeanOfPerSampleRates(): void
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('different_denominators'));
        self::assertSame(-80.0, $summary['competitor_price']['avg_price_gap']);
        self::assertSame(-5.0, $summary['competitor_price']['avg_price_gap_rate']);
        self::assertSame(2, $summary['metric_trust']['competitor_price.avg_price_gap_rate']['source']['row_count']);
    }

    public function testAllZeroRateDenominatorsStayMissingAndAllMissingDerivedGapsRecoverFromPairs(): void
    {
        $zero = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('zero_competitor_all'));
        self::assertSame(170.0, $zero['competitor_price']['avg_price_gap']);
        self::assertNull($zero['competitor_price']['avg_price_gap_rate']);
        self::assertSame(0, $zero['metric_trust']['competitor_price.avg_price_gap_rate']['source']['row_count']);
        self::assertNotSame('verified', $zero['metric_trust']['competitor_price.avg_price_gap_rate']['truth']['status']);
        $missing = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('missing_all_gaps'));
        self::assertSame(70.0, $missing['competitor_price']['avg_price_gap']);
        self::assertSame(70.0, $missing['competitor_price']['avg_price_gap_rate']);
    }

    public function testOnlyACompleteNumericPricePairContributesToItsOwnEvidence(): void
    {
        foreach (['partial_prices', 'invalid_fact', 'nonfinite_fact'] as $scenario) {
            $dataset = $this->dataset($scenario);
            $before = $dataset;
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertSame(1, $summary['competitor_price']['rows']);
            self::assertSame(200.0, $summary['competitor_price']['avg_our_price']);
            self::assertSame(100.0, $summary['competitor_price']['avg_competitor_price']);
            self::assertSame(100.0, $summary['competitor_price']['avg_price_gap']);
            self::assertSame(['synthetic-booking-1'], $summary['metric_trust']['competitor_price.rows']['source']['row_ids']);
            self::assertSame($before, $dataset);
        }
    }

    public function testNonFinitePriceFactCannotMakeThePublicSummaryUnserializable(): void
    {
        $dataset = $this->dataset('nonfinite_fact');
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $json = json_encode($summary, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        self::assertSame($summary, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('1e999', $dataset['fact_ota_daily'][0]['our_price']);
        $prices = array_values(array_filter($summary['channel_metrics'], static fn(array $row): bool =>
            $row['resource'] === 'competitor_price' && $row['metric_key'] === 'our_price'));
        self::assertCount(1, $prices);
        self::assertSame(200.0, $prices[0]['value']);
    }

    public function testObservedZeroAndMissingPricesRemainDistinct(): void
    {
        $zero = $this->overview('zero_our');
        self::assertSame('接近竞对均价', $zero['signals']['competitor_price_warning']['value']);
        self::assertSame(0.0, $zero['signals']['competitor_price_warning']['detail_metrics']['avg_price_gap']);
        $missing = $this->overview('no_prices');
        self::assertSame('--', $missing['signals']['competitor_price_warning']['value']);
        self::assertSame('competitor_price_fields_missing', $missing['signals']['competitor_price_warning']['reason']);
        $summary = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('no_prices'));
        self::assertNull($summary['competitor_price']['avg_price_gap']);
        self::assertNull($summary['competitor_price']['avg_price_gap_rate']);
    }

    public function testDerivedCaliberAndRateEvidenceDescribeActualInputs(): void
    {
        $summary = (new OtaRevenueMetricService())->summarizeDataset($this->dataset('normal'));
        self::assertSame('avg(fact_ota_daily.our_price - fact_ota_daily.competitor_price)', $summary['metric_trust']['competitor_price.avg_price_gap']['caliber']);
        self::assertSame('avg((fact_ota_daily.our_price - fact_ota_daily.competitor_price) / fact_ota_daily.competitor_price * 100) for positive competitor prices', $summary['metric_trust']['competitor_price.avg_price_gap_rate']['caliber']);
    }

    public function testPriceDerivationCannotReplaceSourceOrExactReadbackEvidence(): void
    {
        foreach (['no_trace', 'no_readback', 'collection_failed'] as $scenario) {
            $overview = $this->overview($scenario);
            self::assertSame('--', $overview['signals']['competitor_price_warning']['value']);
            self::assertNotSame('verified', $overview['signals']['competitor_price_warning']['truth']['status']);
            self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        }
    }

    public function testRestoringPairsPreservesTheExactAmountAndMeituanScope(): void
    {
        foreach (['restored_exact', 'meituan'] as $scenario) {
            $overview = $this->overview($scenario);
            $signal = $overview['signals']['competitor_price_warning'];
            self::assertSame($scenario === 'restored_exact' ? '本店高于竞对 ¥25.45' : '本店高于竞对 ¥70.00', $signal['value']);
            self::assertSame([$scenario === 'meituan' ? 'meituan' : 'ctrip'], $signal['source_channels']);
            self::assertSame('verified', $signal['truth']['status']);
        }
    }

    public function testPairGapOverflowStaysUnavailableWithoutReconstructingTheFailedCalculation(): void
    {
        $dataset = $this->dataset('normal');
        $dataset['fact_ota_daily'][0]['our_price'] = 1e308;
        $dataset['fact_ota_daily'][0]['competitor_price'] = -1e308;
        $dataset['fact_ota_daily'][1]['our_price'] = null;
        $dataset['fact_ota_daily'][1]['competitor_price'] = null;
        $original = $dataset;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame(1e308, $summary['competitor_price']['avg_our_price']);
        self::assertSame(-1e308, $summary['competitor_price']['avg_competitor_price']);
        self::assertNull($summary['competitor_price']['avg_price_gap']);
        self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['competitor_price.avg_price_gap']['failure_reasons']);
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $dataset], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertSame('--', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('numeric_aggregate_nonfinite', $overview['signals']['competitor_price_warning']['reason']);
        self::assertSame('not_calculable', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame($overview, json_decode(json_encode($overview, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($original, $dataset);
    }

    public function testPairRateOverflowKeepsTheUsableGapAndItsMissingRateDistinct(): void
    {
        $dataset = $this->dataset('normal');
        $dataset['fact_ota_daily'][0]['our_price'] = 1e308;
        $dataset['fact_ota_daily'][0]['competitor_price'] = 1e-308;
        $dataset['fact_ota_daily'][1]['our_price'] = null;
        $dataset['fact_ota_daily'][1]['competitor_price'] = null;
        $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertSame(1e308, $summary['competitor_price']['avg_price_gap']);
        self::assertNull($summary['competitor_price']['avg_price_gap_rate']);
        self::assertContains('numeric_aggregate_nonfinite', $summary['metric_trust']['competitor_price.avg_price_gap_rate']['failure_reasons']);
        self::assertSame(1, $summary['metric_trust']['competitor_price.avg_price_gap_rate']['source']['row_count']);
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $dataset], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertSame(1e308, $overview['signals']['competitor_price_warning']['detail_metrics']['avg_price_gap']);
        self::assertNull($overview['signals']['competitor_price_warning']['detail_metrics']['avg_price_gap_rate']);
        self::assertSame($overview, json_decode(json_encode($overview, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    private function dataset(string $scenario): array
    {
        $fixture = new RevenueAiCompetitorSignalEvidenceTest('testVerifiedSameScopePriceObservationRemainsManualAndUsable');
        $dataset = (new \ReflectionMethod($fixture, 'dataset'))->invoke($fixture,
            in_array($scenario, ['no_trace', 'no_readback', 'collection_failed'], true) ? $scenario : 'normal');
        $rawRows = [];
        foreach ([[140.0, 100.0], [200.0, 100.0]] as $index => [$our, $competitor]) {
            if (($scenario === 'zero_competitor' && $index === 0) || $scenario === 'zero_competitor_all') $competitor = 0.0;
            if ($scenario === 'zero_our' && $index === 0) $our = 0.0;
            if ($scenario === 'different_denominators' && $index === 1) $competitor = 400.0;
            if ($scenario === 'restored_exact') $our = 125.45;
            if ($scenario === 'partial_prices' && $index === 0) $competitor = null;
            if ($scenario === 'no_prices') $our = $competitor = null;
            $gap = match ($scenario) {
                'conflicting_gap' => $index === 0 ? -25.0 : null,
                'opposite_gap' => -100.0,
                default => null,
            };
            $rawRows[] = ['id' => 'synthetic-price-pair-' . $index, 'source' => 'ctrip',
                'hotel_id' => 'synthetic-hotel-80', 'system_hotel_id' => 80, 'data_date' => '2026-07-28',
                'data_type' => 'business', 'raw_data' => json_encode(['our_price' => $our,
                    'competitor_price' => $competitor, 'price_gap' => $gap], JSON_THROW_ON_ERROR)];
        }
        $etl = (new OtaStandardEtlService())->buildDatasetFromRows($rawRows);
        self::assertCount(2, $etl['fact_ota_daily']);
        foreach ($etl['fact_ota_daily'] as $index => $fact) {
            foreach (['our_price', 'competitor_price', 'price_gap', 'price_gap_rate'] as $key) {
                $dataset['fact_ota_daily'][$index][$key] = $fact[$key];
            }
            if (($scenario === 'partial_gap' && $index === 0) || $scenario === 'missing_all_gaps') {
                $dataset['fact_ota_daily'][$index]['price_gap'] = null;
                $dataset['fact_ota_daily'][$index]['price_gap_rate'] = null;
            }
            if ($scenario === 'invalid_fact' && $index === 0) $dataset['fact_ota_daily'][$index]['our_price'] = 'unknown';
            if ($scenario === 'nonfinite_fact' && $index === 0) $dataset['fact_ota_daily'][$index]['our_price'] = '1e999';
            if ($scenario === 'meituan') {
                $dataset['fact_ota_daily'][$index]['platform_key'] = 'meituan';
                $dataset['fact_ota_daily'][$index]['source_trace']['platform'] = 'meituan';
            }
        }
        return $dataset;
    }

    private function overview(string $scenario): array
    {
        $platform = $scenario === 'meituan' ? 'meituan' : 'ctrip';
        return (new RevenueAiOverviewService())->buildOverviewFromDataset([], [$platform => $this->dataset($scenario)], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => [$platform]]);
    }
}
