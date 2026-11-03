<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiBusinessSignalEvidenceTest.php';

final class RevenueAiCompetitorSignalEvidenceTest extends TestCase
{
    public function testUntracedPricesCannotBecomeAUsableCompetitorWarning(): void
    {
        $overview = $this->overview('no_trace');
        self::assertSame('--', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('unverified', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame('blocked', $this->gate($overview)['status']);
    }

    public function testUnverifiedReadbackCannotMarkTheCompetitorPricingGateReady(): void
    {
        $overview = $this->overview('no_readback');
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertSame('metric_truth_unverified', $this->gate($overview)['reason']);
        self::assertSame('--', $overview['signals']['competitor_price_warning']['value']);
    }

    public function testFailedCollectionCannotMarkTheCompetitorPricingGateReady(): void
    {
        $overview = $this->overview('collection_failed');
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertSame('collection_failed', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame('metric_truth_collection_failed', $this->gate($overview)['reason']);
    }

    public function testVerifiedSameScopePriceObservationRemainsManualAndUsable(): void
    {
        $overview = $this->overview('normal');
        self::assertSame('本店高于竞对 ¥40.00', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('warning', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame('ok', $this->gate($overview)['status']);
        self::assertSame(140.0, $overview['signals']['competitor_price_warning']['detail_metrics']['avg_our_price']);
        self::assertSame(100.0, $overview['signals']['competitor_price_warning']['detail_metrics']['avg_competitor_price']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testPartiallyReadBackPricesDoNotMakeTheCombinedGateReady(): void
    {
        $overview = $this->overview('partial_readback');
        self::assertSame('--', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('partial', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertSame('metric_truth_partial', $this->gate($overview)['reason']);
        self::assertStringContainsString('来源', $this->gate($overview)['display_reason']);
        self::assertStringContainsString('精确回读', $this->gate($overview)['display_reason']);
        self::assertSame(1, $overview['signals']['competitor_price_warning']['truth']['persistence']['readback_verified_count']);
    }

    public function testMissingPricesKeepThePreciseFieldGapAndOriginalRows(): void
    {
        $overview = $this->overview('no_prices');
        $signal = $overview['signals']['competitor_price_warning'];
        self::assertSame('--', $signal['value']);
        self::assertSame('not_loaded', $signal['status']);
        self::assertSame('competitor_price_fields_missing', $signal['reason']);
        self::assertSame(0, $signal['detail_metrics']['sample_rows']);
        self::assertNull($signal['detail_metrics']['avg_our_price']);
        self::assertSame('blocked', $this->gate($overview)['status']);
        self::assertSame('competitor_price_fields_missing', $this->gate($overview)['reason']);
    }

    public function testVerifiedBelowAndEqualPricesAreStillUsableForManualReview(): void
    {
        foreach (['below' => '本店低于竞对 ¥10.00', 'equal' => '接近竞对均价'] as $scenario => $value) {
            $overview = $this->overview($scenario);
            self::assertSame($value, $overview['signals']['competitor_price_warning']['value']);
            self::assertSame('ok', $this->gate($overview)['status']);
            self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        }
    }

    public function testMissingRevenueDoesNotInvalidateIndependentlyProvenPriceSamples(): void
    {
        $overview = $this->overview('independent_revenue_missing');
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('本店高于竞对 ¥40.00', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('ok', $this->gate($overview)['status']);
        self::assertSame('verified', $overview['signals']['competitor_price_warning']['truth']['status'] ?? null);
    }

    public function testAProvenPartialPriceSampleRetainsItsExactObservedScope(): void
    {
        $overview = $this->overview('partial_prices');
        $signal = $overview['signals']['competitor_price_warning'];
        self::assertSame('本店高于竞对 ¥40.00', $signal['value']);
        self::assertSame(1, $signal['detail_metrics']['sample_rows']);
        self::assertSame(1, $signal['truth']['persistence']['record_count'] ?? null);
        self::assertSame(['ctrip'], $signal['source_channels']);
        self::assertSame('ok', $this->gate($overview)['status']);
    }

    public function testRestoringPriceEvidenceRecoversTheExactAmountAndExplainsFailures(): void
    {
        $bad = $this->overview('no_readback');
        $good = $this->overview('restored_exact');
        self::assertSame('本店高于竞对 ¥25.45', $good['signals']['competitor_price_warning']['value']);
        self::assertSame(125.45, $good['signals']['competitor_price_warning']['detail_metrics']['avg_our_price']);
        self::assertSame(25.45, $good['signals']['competitor_price_warning']['detail_metrics']['avg_price_gap']);
        self::assertSame('ok', $this->gate($good)['status']);
        foreach ([$bad['signals']['competitor_price_warning'], $this->gate($bad)] as $item) {
            self::assertSame('online-data', $item['target_page'] ?? null);
            self::assertSame('data-health', $item['target_tab'] ?? null);
            self::assertStringContainsString('精确回读', $item['next_action'] ?? '');
        }
        self::assertStringContainsString('来源', $this->gate($bad)['display_reason']);
        self::assertFalse($good['pricing_readiness']['can_auto_write_ota']);
    }

    public function testPriceProofScopeAndPersistenceCannotBeReplacedByVerifiedLabels(): void
    {
        foreach (['hotel', 'date', 'platform', 'source_missing', 'readback'] as $scenario) {
            $summary = (new \app\service\OtaRevenueMetricService())->summarizeDataset($this->dataset('normal'));
            $trust = &$summary['metric_trust']['competitor_price.rows'];
            if ($scenario === 'hotel') $trust['source']['hotels'] = [['system_hotel_id' => 81]];
            if ($scenario === 'date') $trust['source']['date_range'] = ['start' => '2026-07-27', 'end' => '2026-07-27'];
            if ($scenario === 'platform') $trust['source']['platforms'] = ['meituan'];
            if ($scenario === 'source_missing') unset($trust['source']);
            if ($scenario === 'readback') $trust['source']['readback_verified_count'] = 0;
            unset($trust);
            $service = new RevenueAiOverviewService();
            $signals = (new \ReflectionMethod($service, 'signals'))->invoke($service, $summary, ['ctrip'], [], '2026-07-28', 80);
            $signal = $signals['competitor_price_warning'];
            self::assertSame('--', $signal['value'], $scenario);
            self::assertSame('unverified', $signal['status']);
            self::assertSame($summary['metric_trust']['competitor_price.rows']['truth'], $signal['truth']);
        }
    }

    private function dataset(string $scenario): array
    {
        $fixture = new RevenueAiBusinessSignalEvidenceTest('testUntracedNumbersCannotBecomeVerifiedBookingSignals');
        $dataset = (new \ReflectionMethod($fixture, 'dataset'))->invoke($fixture, $scenario);
        $ourPrice = match ($scenario) { 'below' => 90.0, 'equal' => 100.0, 'restored_exact' => 125.45, default => 140.0 };
        foreach ($dataset['fact_ota_daily'] as $index => &$row) {
            $row['our_price'] = $ourPrice;
            $row['competitor_price'] = 100.0;
            $row['price_gap'] = round($ourPrice - 100, 2);
            $row['price_gap_rate'] = round($ourPrice - 100, 2);
            if ($scenario === 'independent_revenue_missing') $row['room_revenue'] = null;
            if ($scenario === 'no_prices') {
                $row['our_price'] = null;
                $row['competitor_price'] = null;
                $row['price_gap'] = null;
                $row['price_gap_rate'] = null;
            }
            if ($scenario === 'partial_prices' && $index === 1) {
                $row['competitor_price'] = null;
                $row['price_gap'] = null;
                $row['price_gap_rate'] = null;
            }
        }
        unset($row);
        return $dataset;
    }

    private function overview(string $scenario): array
    {
        $dataset = $this->dataset($scenario);
        return (new RevenueAiOverviewService())->buildOverviewFromDataset([], ['ctrip' => $dataset], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
    }

    private function gate(array $overview): array
    {
        foreach ($overview['pricing_readiness']['gates'] as $gate) if ($gate['key'] === 'competitor_price') return $gate;
        throw new \RuntimeException('Competitor gate missing');
    }
}
