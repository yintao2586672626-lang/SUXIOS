<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\TestCase;

final class RevenuePricingRecommendationServiceTest extends TestCase
{
    public function testRecommendationUsesRevenueSignalsAndStaysAdvisoryOnly(): void
    {
        $service = new RevenuePricingRecommendationService();

        $result = $service->recommendFromSignals([
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 230,
            'room_count' => 20,
        ], [
            'demand_forecast' => ['data_status' => 'ok', 'predicted_occupancy' => 93, 'confidence_score' => 0.86],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 145],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -0.3],
            'competitor' => ['data_status' => 'ok', 'gap_percent' => 18, 'avg_price' => 236],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => true, 'is_in_holiday' => false],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 98],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 78, 'sample_count' => 18],
            'data_gaps' => [],
        ]);

        self::assertTrue($result['should_create']);
        self::assertTrue($result['advisory_only']);
        self::assertSame('increase', $result['action']);
        self::assertSame(230.0, $result['suggested_price']);
        self::assertSame('manual_review_required_no_auto_rate_write', $result['factors']['decision_boundary']);
        self::assertSame('low', $result['risk_level']);
        self::assertContains('Confirm this is advisory-only before any OTA execution.', $result['review_checklist']);
        self::assertContains('demand_forecast:occupancy>=90', $result['factor_notes']);
        self::assertContains('pickup_curve:pace_index>=130', $result['factor_notes']);
        self::assertContains('competitor_price:avg>=current+10%', $result['factor_notes']);
        self::assertGreaterThanOrEqual(2, $result['primary_signal_count']);
        self::assertNotEmpty($result['drivers']);
        self::assertArrayHasKey('applied_max_price', $result['factors']['constraints']);
    }

    public function testRecommendationHonorsInventoryAndPriceFloorsForDiscounts(): void
    {
        $service = new RevenuePricingRecommendationService();

        $result = $service->recommendFromSignals([
            'base_price' => 300,
            'min_price' => 260,
            'max_price' => 420,
            'room_count' => 30,
        ], [
            'demand_forecast' => ['data_status' => 'ok', 'predicted_occupancy' => 38, 'confidence_score' => 0.72],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 62],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -1.2],
            'competitor' => ['data_status' => 'ok', 'gap_percent' => -18, 'avg_price' => 246],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => false, 'is_in_holiday' => false],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 40],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 65, 'sample_count' => 14],
            'data_gaps' => ['competitor_room_type_missing_using_hotel_scope'],
        ]);

        self::assertTrue($result['should_create']);
        self::assertSame('decrease', $result['action']);
        self::assertSame(260.0, $result['suggested_price']);
        self::assertSame('medium', $result['risk_level']);
        self::assertContains('price_elasticity:sensitive_support_discount', $result['factor_notes']);
        self::assertContains('Check competitor snapshot date and price comparability.', $result['review_checklist']);
        self::assertArrayHasKey('applied_min_price', $result['factors']['constraints']);
        self::assertLessThan(0.8, $result['confidence_score']);
    }

    public function testRecommendationSkipsWeakSingleCalendarSignal(): void
    {
        $service = new RevenuePricingRecommendationService();

        $result = $service->recommendFromSignals([
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
        ], [
            'demand_forecast' => ['data_status' => 'missing', 'predicted_occupancy' => null],
            'pickup' => ['data_status' => 'insufficient', 'pace_index' => null],
            'elasticity' => ['data_status' => 'insufficient', 'elasticity' => null],
            'competitor' => ['data_status' => 'missing', 'sample_count' => 0],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => true, 'is_in_holiday' => true],
            'inventory' => ['data_status' => 'missing', 'utilization_percent' => null],
            'backtest' => ['data_status' => 'insufficient', 'hit_rate' => null, 'sample_count' => 0],
            'data_gaps' => ['demand_forecast_missing', 'competitor_price_missing'],
        ]);

        self::assertFalse($result['should_create']);
        self::assertSame('primary_signal_count_insufficient', $result['skip_reason']);
        self::assertSame('high', $result['risk_level']);
        self::assertSame(0, $result['primary_signal_count']);
        self::assertContains('holiday:in_holiday', $result['factor_notes']);
        self::assertContains('Do not approve until blocking data gaps are resolved.', $result['review_checklist']);
    }

    public function testElasticityEstimateReturnsBacktestHitRate(): void
    {
        $service = new RevenuePricingRecommendationService();
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $price = 100 + $i * 5;
            $quantity = 130 - $i * 6;
            $rows[] = [
                'data_date' => sprintf('2026-05-%02d', $i),
                'amount' => $price * $quantity,
                'quantity' => $quantity,
                'book_order_num' => max(1, (int)floor($quantity / 2)),
            ];
        }

        $elasticity = $service->estimatePriceElasticity($rows);

        self::assertSame('ok', $elasticity['data_status']);
        self::assertLessThan(0, $elasticity['elasticity']);
        self::assertSame('ok', $elasticity['backtest']['data_status']);
        self::assertGreaterThanOrEqual(70, $elasticity['backtest']['hit_rate']);
    }
}
