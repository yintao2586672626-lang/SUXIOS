<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\model\DemandForecast;
use app\service\RevenueAnalysisDiagnosticsService;
use PHPUnit\Framework\TestCase;

/** Synthetic inputs only; no account, OTA or operating writes. */
final class ForecastInputRefinementTest extends TestCase
{
    private function normalize(array $override = []): array
    {
        $controller = (new \ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'normalizeDemandForecastPayload');
        return $method->invoke($controller, array_replace([
            'hotel_id' => 9001, 'room_type_id' => 100, 'forecast_date' => '2026-10-03',
            'forecast_method' => 3, 'predicted_occupancy' => 75.5,
            'predicted_demand' => 0, 'confidence_score' => .8,
        ], $override));
    }

    public function testExplicitZeroOccupancyAndDemandAreValidManualInputs(): void
    {
        $row = $this->normalize(['predicted_occupancy' => 0]);
        self::assertSame(0.0, $row['predicted_occupancy']);
        self::assertSame(0, $row['predicted_demand']);
        self::assertSame('operator_provided', $row['historical_data']['evidence_status']);
        self::assertFalse($row['historical_data']['auto_write_ota']);
    }

    public function testOccupancyUsesThePersistedTwoDecimalPrecision(): void
    {
        self::assertSame(75.56, $this->normalize(['predicted_occupancy' => 75.5555])['predicted_occupancy']);
        self::assertSame(2.68, $this->normalize(['predicted_occupancy' => 2.675])['predicted_occupancy']);
        self::assertSame(75.55, $this->normalize(['predicted_occupancy' => 75.55499])['predicted_occupancy']);
    }

    public function testInvalidInputsCannotBecomeZeroOrWrapBeforePersistence(): void
    {
        foreach ([
            ['predicted_occupancy' => null], ['predicted_occupancy' => ''],
            ['predicted_occupancy' => false], ['predicted_occupancy' => NAN],
            ['predicted_occupancy' => -0.00001], ['predicted_occupancy' => 100.00001],
            ['predicted_demand' => NAN], ['predicted_demand' => INF],
            ['predicted_demand' => 1e30], ['predicted_demand' => 4294967296],
            ['predicted_demand' => -0.00001],
        ] as $bad) {
            try {
                $this->normalize($bad);
                self::fail('Invalid forecast input was accepted: ' . array_key_first($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString((string)array_key_first($bad), $e->getMessage());
            }
        }
        self::assertSame(4294967295, $this->normalize(['predicted_demand' => 4294967295])['predicted_demand']);
    }

    public function testUnverifiedNumericMetricCannotEnterPricingReview(): void
    {
        $existing = new RevenueAnalysisDiagnosticsServiceTest('testVerifiedThreeSourceFactsProduceShareableDiagnostics');
        $method = new \ReflectionMethod($existing, 'factLayer');
        $layer = $method->invoke($existing);
        $layer['analysis_metrics']['ota_room_revenue']['truth']['status'] = 'unverified';
        $result = (new RevenueAnalysisDiagnosticsService())->build($layer);
        self::assertFalse($result['decision_use']['ai_manual_review']['allowed']);
        self::assertSame('not_calculable', $result['metric_diagnostics'][0]['status']);
        self::assertSame(0.0, $layer['analysis_metrics']['ota_room_revenue']['value']);
        self::assertTrue($result['decision_use']['revenue_analysis']['allowed']);
    }

    public function testConflictingOtaEnvelopeCannotPassReadbackBySummaryFlag(): void
    {
        $existing = new RevenueAnalysisDiagnosticsServiceTest('testVerifiedThreeSourceFactsProduceShareableDiagnostics');
        $layer = (new \ReflectionMethod($existing, 'factLayer'))->invoke($existing);
        $layer['sources']['ctrip_ota']['data_status'] = 'failed';
        $result = (new RevenueAnalysisDiagnosticsService())->build($layer);
        self::assertFalse($result['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('conflict', $result['source_checks'][1]['readback_status']);
    }
}
