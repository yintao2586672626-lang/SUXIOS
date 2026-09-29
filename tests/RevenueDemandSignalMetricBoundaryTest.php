<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueDemandSignalMetricBoundaryTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'hotel_id' => 7, 'room_type_id' => 501, 'forecast_date' => '2026-09-27',
            'predicted_occupancy' => 70, 'predicted_demand' => 10, 'confidence_score' => 0.8,
        ], $overrides);
    }

    private function signal(array $rows): array
    {
        return (new RevenueAiOverviewService())->buildDemandForecastSignal($rows, '2026-09-27', '2026-10-03', 7);
    }

    public function testRealZeroOccupancyAndDemandRemainAvailable(): void
    {
        $signal = $this->signal([$this->row(['predicted_occupancy' => '0', 'predicted_demand' => 0])]);
        self::assertSame('ok', $signal['status']);
        self::assertSame(0.0, $signal['detail_metrics']['avg_predicted_occupancy']);
        self::assertSame(0.0, $signal['detail_metrics']['total_predicted_demand']);
        self::assertStringContainsString('0间夜', $signal['value']);
        self::assertFalse($signal['auto_write_ota']);
    }

    public function testZeroParticipatesInMeanAndSameDateRoomTypesCountAsOneHighDemandDay(): void
    {
        $signal = $this->signal([
            $this->row(['predicted_occupancy' => 0]),
            $this->row(['room_type_id' => 502, 'predicted_occupancy' => 90]),
            $this->row(['room_type_id' => 503, 'predicted_occupancy' => 90]),
        ]);
        self::assertSame(60.0, $signal['detail_metrics']['avg_predicted_occupancy']);
        self::assertSame('高需求 1天', $signal['value']);
        self::assertSame(['2026-09-27'], $signal['detail_metrics']['high_demand_dates']);
    }

    public function testZeroConfidenceIsLowConfidenceAndBlocksThePricingGate(): void
    {
        $signal = $this->signal([$this->row(['confidence_score' => 0])]);
        self::assertSame(0.0, $signal['detail_metrics']['avg_confidence']);
        self::assertSame('partial', $signal['status']);
        self::assertSame('demand_forecasts_low_confidence', $signal['reason']);
        $this->assertGateBlocked($signal);
    }

    public function testMissingDemandStaysNullWhenOccupancyIsAvailable(): void
    {
        $signal = $this->signal([$this->row(['predicted_demand' => null])]);
        self::assertNull($signal['detail_metrics']['total_predicted_demand']);
        self::assertSame('平均入住 70.0%', $signal['value']);
        self::assertSame('ok', $signal['status']);
    }

    public function testInvalidOccupancyCannotManufactureAHighDemandDay(): void
    {
        foreach ([101, -1, INF, NAN, 'unknown', true, []] as $invalid) {
            $signal = $this->signal([$this->row(['predicted_occupancy' => $invalid])]);
            self::assertSame('partial', $signal['status']);
            self::assertSame('demand_forecasts_invalid_metrics', $signal['reason']);
            self::assertNull($signal['detail_metrics']['avg_predicted_occupancy']);
            self::assertSame([], $signal['detail_metrics']['high_demand_dates']);
            self::assertSame(10.0, $signal['detail_metrics']['total_predicted_demand']);
            self::assertNotFalse(json_encode($signal, JSON_THROW_ON_ERROR));
            $this->assertGateBlocked($signal);
        }
    }

    public function testPartialSamplesKeepObservedValuesWithoutClaimingCompleteDemand(): void
    {
        $signal = $this->signal([
            $this->row(),
            $this->row(['forecast_date' => '2026-09-28', 'predicted_occupancy' => null, 'predicted_demand' => null]),
        ]);
        self::assertSame('partial', $signal['status']);
        self::assertSame('demand_forecasts_partial_metrics', $signal['reason']);
        self::assertSame(10.0, $signal['detail_metrics']['total_predicted_demand']);
        self::assertStringContainsString('有效样本', $signal['value']);
        self::assertSame(1, $signal['detail_metrics']['demand_sample_rows']);
        $this->assertGateBlocked($signal);
    }

    public function testAllMissingAndAllInvalidRemainDifferentUnavailableStates(): void
    {
        $missing = $this->signal([$this->row(['predicted_occupancy' => null, 'predicted_demand' => ''])]);
        $invalid = $this->signal([$this->row(['predicted_occupancy' => 101, 'predicted_demand' => -1])]);
        self::assertSame('not_calculable', $missing['status']);
        self::assertSame('demand_forecasts_metric_missing', $missing['reason']);
        self::assertSame('not_calculable', $invalid['status']);
        self::assertSame('demand_forecasts_invalid_metrics', $invalid['reason']);
        self::assertSame('--', $invalid['value']);
        self::assertNull($invalid['detail_metrics']['total_predicted_demand']);
    }

    public function testMissingInvalidAndLegacyPercentConfidenceAreExplicit(): void
    {
        $missing = $this->signal([$this->row(['confidence_score' => null])]);
        self::assertSame('partial', $missing['status']);
        self::assertSame('demand_forecasts_confidence_missing', $missing['reason']);
        $this->assertGateBlocked($missing);
        $invalid = $this->signal([$this->row(['confidence_score' => 101])]);
        self::assertSame('partial', $invalid['status']);
        self::assertSame('demand_forecasts_invalid_metrics', $invalid['reason']);
        $this->assertGateBlocked($invalid);
        $legacy = $this->signal([$this->row(['confidence_score' => 80]), $this->row(['confidence_score' => 0.6])]);
        self::assertSame(0.7, $legacy['detail_metrics']['avg_confidence']);
    }

    public function testDemandAndConfidenceRejectInvalidValuesWithoutDiscardingUsableOccupancy(): void
    {
        foreach (['predicted_demand', 'confidence_score'] as $field) {
            foreach ([-1, INF, -INF, NAN, 'unknown', true, []] as $invalid) {
                $signal = $this->signal([$this->row([$field => $invalid])]);
                self::assertSame('partial', $signal['status']);
                self::assertSame('demand_forecasts_invalid_metrics', $signal['reason']);
                self::assertSame(70.0, $signal['detail_metrics']['avg_predicted_occupancy']);
                self::assertSame(1, $signal['detail_metrics']['invalid_metrics'][$field]);
                self::assertNotFalse(json_encode($signal, JSON_THROW_ON_ERROR));
            }
        }
        $demandOnly = $this->signal([$this->row(['predicted_occupancy' => null, 'predicted_demand' => 0])]);
        self::assertSame('ok', $demandOnly['status']);
        self::assertNull($demandOnly['detail_metrics']['avg_predicted_occupancy']);
        self::assertSame(0.0, $demandOnly['detail_metrics']['total_predicted_demand']);
        $boundary = $this->signal([$this->row(['predicted_occupancy' => 100, 'confidence_score' => 1])]);
        self::assertSame('warning', $boundary['status']);
        self::assertSame(100.0, $boundary['detail_metrics']['max_predicted_occupancy']);
    }

    public function testPartialOrUncalculableStatusCannotBeOverriddenByAnAvailableReason(): void
    {
        foreach (['partial', 'not_calculable'] as $status) {
            foreach (['demand_forecasts_available', 'demand_forecasts_high_demand'] as $reason) {
                $this->assertGateBlocked(array_merge($this->signal([$this->row()]), ['status' => $status, 'reason' => $reason]));
            }
        }
    }

    public function testConfidenceRoundingCannotPromoteBelowThresholdSamples(): void
    {
        foreach ([[0.59, 0.60], [59, 60]] as [$first, $second]) {
            $signal = $this->signal([$this->row(['confidence_score' => $first]), $this->row(['confidence_score' => $second])]);
            self::assertSame(0.6, $signal['detail_metrics']['avg_confidence'], 'Display rounding remains compatible');
            self::assertSame('partial', $signal['status'], 'The unrounded 0.595 confidence is still below 0.6');
            self::assertSame('demand_forecasts_low_confidence', $signal['reason']);
            $this->assertGateBlocked($signal);
        }
        self::assertSame('ok', $this->signal([$this->row(['confidence_score' => 0.6])])['status']);
    }

    private function assertGateBlocked(array $signal): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], [], [], [
            'business_date' => '2026-09-27', 'hotel_id' => 7, 'market_signals' => ['demand_7d' => $signal],
        ]);
        $gates = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('blocked', $gates['demand_signal_7d']['status']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }
}
