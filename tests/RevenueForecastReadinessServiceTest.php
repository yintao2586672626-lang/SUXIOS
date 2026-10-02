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
            'actual_occupancy' => null,
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
        self::assertFalse($readiness['closed_loop']);
        self::assertTrue($readiness['component_closed_loop']);
        self::assertSame('diagnostic_only', $readiness['authority_status']);
        self::assertTrue($readiness['execution_ready']);
        self::assertSame(2, $readiness['suggestion_count']);
    }

    public function testKnownZeroActualCanCompleteOnlyTheForecastComponent(): void
    {
        foreach ([0, 0.0, '0.00', 100, '100.00'] as $actual) {
            $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
                'forecast_date' => date('Y-m-d', strtotime('-1 day')),
                'predicted_occupancy' => 72,
                'confidence_score' => 0.82,
                'actual_occupancy' => $actual,
            ], ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);

            self::assertSame('forecast_pricing_closed', $readiness['stage']);
            self::assertTrue($readiness['component_closed_loop']);
            self::assertTrue($readiness['execution_ready']);
            self::assertSame([], $readiness['missing_evidence']);
            self::assertSame('diagnostic_only', $readiness['authority_status']);
            self::assertFalse($readiness['closed_loop']);
        }
    }

    public function testMissingActualRemainsARefillableBacktestGap(): void
    {
        $missing = [[], ['actual_occupancy' => null], ['actual_occupancy' => ''], ['actual_occupancy' => ' ']];
        foreach ($missing as $actual) {
            $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness(array_merge([
                'forecast_date' => date('Y-m-d', strtotime('-1 day')),
                'predicted_occupancy' => 72,
                'confidence_score' => 0.82,
            ], $actual), ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);

            self::assertSame('forecast_backtest_missing', $readiness['stage']);
            self::assertSame('缺回测', $readiness['status_label']);
            self::assertSame(['actual_occupancy'], array_column($readiness['missing_evidence'], 'code'));
            self::assertSame([], $readiness['invalid_evidence']);
            self::assertStringContainsString('仍缺', $readiness['notice']);
            self::assertFalse($readiness['component_closed_loop']);
            self::assertFalse($readiness['execution_ready']);
            self::assertFalse($readiness['closed_loop']);
            self::assertSame('diagnostic_only', $readiness['authority_status']);
        }
    }

    public function testReportedInvalidActualIsNotMisrepresentedAsMissing(): void
    {
        $service = new RevenueForecastReadinessService();
        $base = [
            'forecast_date' => date('Y-m-d', strtotime('-1 day')),
            'predicted_occupancy' => 72,
            'confidence_score' => 0.82,
        ];

        foreach ([101, -1, 'unknown', NAN, INF, -INF, false, true, []] as $actual) {
            $readiness = $service->buildForecastReadiness(array_merge($base, [
                'actual_occupancy' => $actual,
            ]), ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);

            self::assertSame('forecast_backtest_missing', $readiness['stage']);
            self::assertSame('实绩待核', $readiness['status_label']);
            self::assertSame([], $readiness['missing_evidence']);
            self::assertSame(['actual_occupancy_invalid'], array_column($readiness['invalid_evidence'], 'code'));
            self::assertStringContainsString('核对', $readiness['next_action']);
            self::assertStringContainsString('待核', $readiness['notice']);
            self::assertFalse($readiness['component_closed_loop']);
            self::assertFalse($readiness['execution_ready']);
            self::assertFalse($readiness['closed_loop']);
        }

        $lowConfidence = $service->buildForecastReadiness(array_merge($base, [
            'confidence_score' => 0.52,
            'actual_occupancy' => 101,
        ]));
        self::assertSame('forecast_low_confidence', $lowConfidence['stage']);
        self::assertSame(['confidence_score'], array_column($lowConfidence['missing_evidence'], 'code'));
        self::assertSame(['actual_occupancy_invalid'], array_column($lowConfidence['invalid_evidence'], 'code'));
        self::assertStringContainsString('已记录数据待核', $lowConfidence['notice']);
    }

    public function testKnownZeroDoesNotSkipPricingApprovalOrFutureResultBoundaries(): void
    {
        $service = new RevenueForecastReadinessService();
        $row = ['forecast_date' => date('Y-m-d', strtotime('-1 day')), 'predicted_occupancy' => 72,
            'confidence_score' => 0.82, 'actual_occupancy' => 0];
        $unpriced = $service->buildForecastReadiness($row);
        self::assertSame('forecast_not_priced', $unpriced['stage']);
        self::assertFalse($unpriced['execution_ready']);
        $linked = $service->buildForecastReadiness($row, ['suggestion_count' => 1]);
        self::assertSame('forecast_pricing_linked', $linked['stage']);
        $approved = $service->buildForecastReadiness($row, ['suggestion_count' => 1, 'approved_count' => 1]);
        self::assertSame('forecast_pricing_approved', $approved['stage']);
        foreach ([$unpriced, $linked, $approved] as $readiness) {
            self::assertFalse($readiness['component_closed_loop']);
            self::assertFalse($readiness['closed_loop']);
            self::assertSame('diagnostic_only', $readiness['authority_status']);
        }
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))] as $date) {
            $future = $service->buildForecastReadiness(array_merge($row, ['forecast_date' => $date]),
                ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);
            self::assertSame('forecast_pricing_applied', $future['stage']);
            self::assertSame(['actual_result'], array_column($future['missing_evidence'], 'code'));
            self::assertTrue($future['execution_ready']);
            self::assertFalse($future['component_closed_loop']);
            self::assertFalse($future['closed_loop']);
            self::assertSame('diagnostic_only', $future['authority_status']);
        }
    }

    public function testShanghaiYesterdayForecastWithActualCanCloseAcrossProcessTimezones(): void
    {
        $shanghaiNow = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $yesterday = $shanghaiNow->modify('-1 day')->format('Y-m-d');
        $originalTimezone = date_default_timezone_get();

        try {
            foreach (['Pacific/Honolulu', 'Pacific/Kiritimati'] as $timezone) {
                date_default_timezone_set($timezone);
                if (date('Y-m-d') !== $shanghaiNow->format('Y-m-d')) {
                    break;
                }
            }
            self::assertNotSame($shanghaiNow->format('Y-m-d'), date('Y-m-d'));

            $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
                'forecast_date' => $yesterday,
                'predicted_occupancy' => 72,
                'confidence_score' => 0.82,
                'actual_occupancy' => 84,
            ], ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);

            self::assertSame('forecast_pricing_closed', $readiness['stage']);
            self::assertTrue($readiness['component_closed_loop']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}
