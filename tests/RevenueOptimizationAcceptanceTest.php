<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAnalysisDiagnosticsService;
use app\service\RevenueForecastReadinessService;
use PHPUnit\Framework\TestCase;

/** Synthetic, read-only regression inputs; no OTA, database or operating action. */
final class RevenueOptimizationAcceptanceTest extends TestCase
{
    public function testMissingOrInvalidConfidenceCannotBecomeExecutionReady(): void
    {
        foreach ([null, '', 'unknown', false, -1, 101, INF, NAN] as $confidence) {
            $result = (new RevenueForecastReadinessService())->buildForecastReadiness(
                $this->forecast(['confidence_score' => $confidence]), $this->applied()
            );
            self::assertSame('forecast_confidence_missing', $result['stage']);
            self::assertFalse($result['execution_ready']);
            self::assertNull($result['confidence_percent']);
            self::assertFalse($result['component_closed_loop']);
        }
        $zero = (new RevenueForecastReadinessService())->buildForecastReadiness(
            $this->forecast(['confidence_score' => 0]), $this->applied()
        );
        self::assertSame('forecast_low_confidence', $zero['stage']);
        self::assertSame(0.0, $zero['confidence_percent']);
        self::assertFalse($zero['execution_ready']);
    }

    public function testInvalidForecastDateAndNumbersCannotBecomeExecutionReady(): void
    {
        foreach (['2026-02-30', 'not-a-date', '2026-10-02garbage', '2026-13-01'] as $date) {
            $result = (new RevenueForecastReadinessService())->buildForecastReadiness(
                $this->forecast(['forecast_date' => $date]), $this->applied()
            );
            self::assertSame('forecast_metric_missing', $result['stage']);
            self::assertFalse($result['execution_ready']);
        }
        foreach ([null, '', -1, 101, INF, NAN, false, 'unknown'] as $occupancy) {
            $result = (new RevenueForecastReadinessService())->buildForecastReadiness(
                $this->forecast(['predicted_occupancy' => $occupancy]), $this->applied()
            );
            self::assertSame('forecast_metric_missing', $result['stage']);
            self::assertFalse($result['execution_ready']);
        }
        $missing = (new RevenueForecastReadinessService())->buildForecastReadiness(
            $this->forecast(['predicted_demand' => null])
        );
        self::assertNull($missing['predicted_demand']);
    }

    public function testVerifiedZeroOccupancyAndDemandStayUsable(): void
    {
        $result = (new RevenueForecastReadinessService())->buildForecastReadiness(
            $this->forecast(['actual_occupancy' => 0]), $this->applied()
        );
        self::assertSame('forecast_pricing_closed', $result['stage']);
        self::assertTrue($result['component_closed_loop']);
        self::assertFalse($result['closed_loop']);
        $zeroForecast = (new RevenueForecastReadinessService())->buildForecastReadiness(
            $this->forecast([
                'forecast_date' => date('Y-m-d', strtotime('+1 day')),
                'predicted_occupancy' => 0,
                'predicted_demand' => 0,
            ])
        );
        self::assertSame('forecast_not_priced', $zeroForecast['stage']);
        self::assertSame(0.0, $zeroForecast['predicted_demand']);
        foreach ([null, '', 'unknown', false, -1, 101, INF, NAN] as $actual) {
            $missing = (new RevenueForecastReadinessService())->buildForecastReadiness(
                $this->forecast(['actual_occupancy' => $actual]), $this->applied()
            );
            self::assertSame('forecast_backtest_missing', $missing['stage']);
            self::assertFalse($missing['component_closed_loop']);
        }
    }

    public function testDiagnosticFailedChecksOverrideContradictoryReadyFlag(): void
    {
        foreach (['identity', 'date', 'alignment', 'source', 'policy'] as $failure) {
            $layer = $this->factLayer();
            if ($failure === 'identity') $layer['hotel']['tenant_id'] = 0;
            if ($failure === 'date') $layer['business_date'] = '2026-02-30';
            if ($failure === 'alignment') $layer['date_alignment']['status'] = 'mismatch';
            if ($failure === 'source') $layer['source_completeness']['meituan_ota'] = 'missing';
            if ($failure === 'policy') $layer['aggregation_policy']['missing_source_value'] = 0;
            $result = (new RevenueAnalysisDiagnosticsService())->build($layer);
            self::assertFalse($result['decision_use']['revenue_analysis']['allowed'], $failure);
            self::assertFalse($result['decision_use']['ai_manual_review']['allowed'], $failure);
            self::assertSame('needs_revision', $result['overall_assessment']);
            self::assertNotSame('ready', $result['decision_use']['revenue_analysis']['status']);
            self::assertNotEmpty($result['issues']);
            self::assertNotSame('', $result['next_action']);
            self::assertSame(0.0, $result['metric_diagnostics'][0]['value']);
        }
    }

    public function testVerifiedDiagnosticsKeepRealZeroAndProviderScope(): void
    {
        $result = (new RevenueAnalysisDiagnosticsService())->build($this->factLayer());
        self::assertTrue($result['decision_use']['revenue_analysis']['allowed']);
        self::assertTrue($result['decision_use']['ai_manual_review']['allowed']);
        self::assertSame('ready_to_share', $result['overall_assessment']);
        self::assertSame(0.0, $result['metric_diagnostics'][0]['value']);
        self::assertSame(80, $result['scope']['system_hotel_id']);
        self::assertFalse($result['decision_use']['whole_hotel_generalization']['allowed']);
    }

    public function testMissingMetricDoesNotTurnAnOkLabelIntoShareableEvidence(): void
    {
        foreach ([null, '', 'unknown', false, INF, NAN] as $invalid) {
            $layer = $this->factLayer();
            $layer['analysis_metrics']['ota_room_revenue']['value'] = $invalid;
            $result = (new RevenueAnalysisDiagnosticsService())->build($layer);
            self::assertSame('not_calculable', $result['metric_diagnostics'][0]['status']);
            self::assertNull($result['metric_diagnostics'][0]['value']);
            self::assertSame('share_with_caveats', $result['overall_assessment']);
            self::assertFalse($result['decision_use']['ai_manual_review']['allowed']);
            self::assertTrue($result['decision_use']['revenue_analysis']['allowed']);
            self::assertNotEmpty($result['issues']);
            json_encode($result, JSON_THROW_ON_ERROR);
            $layer['analysis_metrics'] = [];
            $empty = (new RevenueAnalysisDiagnosticsService())->build($layer);
            self::assertFalse($empty['decision_use']['revenue_analysis']['allowed']);
            self::assertSame('needs_revision', $empty['overall_assessment']);
        }
    }

    public function testForecastLegacyDatetimeAndConfidenceFormatsStayCompatible(): void
    {
        foreach ([0.82, 82, '82', 1] as $confidence) {
            $row = $this->forecast(['confidence_score' => $confidence]);
            $row['forecast_date'] .= ' 00:00:00';
            $result = (new RevenueForecastReadinessService())->buildForecastReadiness($row, $this->applied());
            self::assertSame('forecast_pricing_closed', $result['stage']);
            self::assertSame($confidence == 1 ? 100.0 : 82.0, $result['confidence_percent']);
        }
    }

    private function forecast(array $override = []): array
    {
        return array_replace([
            'forecast_date' => date('Y-m-d', strtotime('-1 day')),
            'predicted_occupancy' => 72, 'predicted_demand' => 24,
            'confidence_score' => 0.82, 'actual_occupancy' => 68,
        ], $override);
    }

    private function applied(): array
    {
        return ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1];
    }

    private function factLayer(): array
    {
        $test = new RevenueAnalysisDiagnosticsServiceTest('fixture');
        $method = new \ReflectionMethod($test, 'factLayer');
        $method->setAccessible(true);
        return $method->invoke($test);
    }
}
