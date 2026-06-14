<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueForecastReadinessService;
use PHPUnit\Framework\TestCase;

final class RevenueForecastReadinessServiceTest extends TestCase
{
    public function testInvalidForecastMetricRequiresRecheck(): void
    {
        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('+1 day')),
            'predicted_occupancy' => 0,
            'confidence_score' => 0.8,
        ]);

        self::assertSame('forecast_metric_missing', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['forecast_metric'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testLowConfidenceForecastIsNotExecutionReady(): void
    {
        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('+1 day')),
            'predicted_occupancy' => 68,
            'predicted_demand' => 20,
            'confidence_score' => 0.52,
        ]);

        self::assertSame('forecast_low_confidence', $readiness['stage']);
        self::assertSame(52.0, $readiness['confidence_percent']);
        self::assertFalse($readiness['execution_ready']);
    }

    public function testPastForecastRequiresActualOccupancyBacktest(): void
    {
        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('-1 day')),
            'predicted_occupancy' => 72,
            'predicted_demand' => 24,
            'confidence_score' => 0.82,
            'actual_occupancy' => 0,
        ]);

        self::assertSame('forecast_backtest_missing', $readiness['stage']);
        self::assertSame(['actual_occupancy'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testFutureForecastRequiresPricingSuggestionLink(): void
    {
        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('+1 day')),
            'predicted_occupancy' => 88,
            'predicted_demand' => 30,
            'confidence_score' => 86,
        ]);

        self::assertSame('forecast_not_priced', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['price_suggestion'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testAppliedForecastWithActualResultIsClosed(): void
    {
        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('-1 day')),
            'predicted_occupancy' => 88,
            'predicted_demand' => 30,
            'confidence_score' => 0.86,
            'actual_occupancy' => 84,
        ], [
            'suggestion_count' => 2,
            'approved_count' => 2,
            'applied_count' => 1,
            'latest_suggestion_at' => '2026-06-14 12:00:00',
        ]);

        self::assertSame('forecast_pricing_closed', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertTrue($readiness['execution_ready']);
        self::assertSame(2, $readiness['suggestion_count']);
    }
}
