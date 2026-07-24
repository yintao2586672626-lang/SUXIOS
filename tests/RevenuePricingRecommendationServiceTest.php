<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\RevenuePricingRecommendationService;
use app\service\TrustedOtaFactRepository;
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

    public function testSuggestionReadinessKeepsPendingSuggestionOutOfExecutionClosure(): void
    {
        $service = new RevenuePricingRecommendationService();
        $recommendation = $service->recommendFromSignals([
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
        ], [
            'demand_forecast' => ['data_status' => 'ok', 'predicted_occupancy' => 92, 'confidence_score' => 0.86],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 140],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -0.4],
            'competitor' => ['data_status' => 'ok', 'gap_percent' => 12, 'avg_price' => 226],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => true, 'is_in_holiday' => false],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 94],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 76, 'sample_count' => 20],
            'data_gaps' => [],
        ]);

        $readiness = $service->buildSuggestionReadiness([
            'id' => 10,
            'status' => 1,
            'confidence_score' => $recommendation['confidence_score'],
            'risk_level' => $recommendation['risk_level'],
            'factors' => $recommendation['factors'],
        ]);

        self::assertSame('pending_approval', $readiness['stage']);
        self::assertFalse($readiness['pricing_ready']);
        self::assertContains('manual_approval', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('execution_intent', array_column($readiness['missing_evidence'], 'code'));
        self::assertContains('execution_evidence', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testSuggestionReadinessRequiresEvidenceAndRoiForPricingClosure(): void
    {
        $service = new RevenuePricingRecommendationService();
        $recommendation = $service->recommendFromSignals([
            'base_price' => 200,
            'min_price' => 160,
            'max_price' => 260,
            'room_count' => 20,
        ], [
            'demand_forecast' => ['data_status' => 'ok', 'predicted_occupancy' => 92, 'confidence_score' => 0.86],
            'pickup' => ['data_status' => 'ok', 'pace_index' => 140],
            'elasticity' => ['data_status' => 'ok', 'elasticity' => -0.4],
            'competitor' => ['data_status' => 'ok', 'gap_percent' => 12, 'avg_price' => 226],
            'holiday' => ['data_status' => 'ok', 'is_holiday_window' => true, 'is_in_holiday' => false],
            'inventory' => ['data_status' => 'ok', 'utilization_percent' => 94],
            'backtest' => ['data_status' => 'ok', 'hit_rate' => 76, 'sample_count' => 20],
            'data_gaps' => [],
        ]);

        $readiness = $service->buildSuggestionReadiness([
            'id' => 10,
            'status' => 4,
            'current_price' => 200,
            'suggested_price' => 230,
            'confidence_score' => $recommendation['confidence_score'],
            'risk_level' => $recommendation['risk_level'],
            'factors' => $recommendation['factors'],
        ], [
            'id' => 99,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed'],
            'evidence' => ['count' => 2],
            'roi' => ['status' => 'ready', 'value' => 2.1],
        ]);

        self::assertSame('pricing_ready', $readiness['stage']);
        self::assertTrue($readiness['pricing_ready']);
        self::assertSame(100, $readiness['score']);
        self::assertSame([], $readiness['missing_evidence']);

        $enriched = $service->enrichSuggestionRows([[
            'id' => 10,
            'status' => 4,
            'suggestion_type' => 1,
            'current_price' => 200,
            'suggested_price' => 230,
            'factors' => $recommendation['factors'],
        ]], [
            10 => ['stage' => 'reviewed', 'evidence' => ['count' => 2], 'roi' => ['status' => 'ready']],
        ]);
        self::assertSame(15.0, $enriched[0]['price_change_percent']);
        self::assertSame('pricing_ready', $enriched[0]['pricing_readiness']['stage']);
        self::assertSame('P1', $enriched[0]['decision_recommendation']['priority']);
        self::assertSame('ota_channel', $enriched[0]['decision_recommendation']['data_basis']['scope']);
        self::assertSame('ota_revenue', $enriched[0]['decision_recommendation']['expected_effect']['metric']);
        self::assertNotSame('', $enriched[0]['decision_recommendation']['risk']['summary']);
        self::assertArrayHasKey('recommendation_quality', $enriched[0]);
    }

    public function testApprovedSuggestionWithFreshBoundHistoryPassesDecisionQualityV2(): void
    {
        $rows = [];
        $start = new \DateTimeImmutable('2026-06-03');
        for ($i = 0; $i < 28; $i++) {
            $price = 180 + ($i * 2);
            $quantity = 70 - $i;
            $rows[] = [
                'data_date' => $start->modify('+' . $i . ' days')->format('Y-m-d'),
                'amount' => $price * $quantity,
                'quantity' => $quantity,
                'book_order_num' => max(1, (int)floor($quantity / 2)),
                'source' => 'ctrip',
                'metric_scope' => 'ota_channel',
            ];
        }
        $repository = $this->createMock(TrustedOtaFactRepository::class);
        $repository->method('pricingHistory')->willReturn([
            'data_status' => 'ready',
            'rows' => $rows,
            'data_gaps' => [],
            'source_policy' => ['readback_policy' => 'readback_verified_required_equals_1'],
            'data_quality' => ['trusted_rows' => count($rows)],
        ]);
        $service = new RevenuePricingRecommendationService($repository);

        $enriched = $service->enrichSuggestionRows([[
            'id' => 18,
            'hotel_id' => 7,
            'status' => 2,
            'suggestion_type' => 1,
            'suggestion_date' => '2026-06-30',
            'room_type_name' => '高级大床房',
            'current_price' => 260,
            'suggested_price' => 288,
            'reason' => '携程历史拾取速度与价格弹性共同支持人工调价复核。',
            'factors' => [
                'decision_boundary' => 'manual_review_required_no_auto_rate_write',
                'primary_signal_count' => 2,
                'confidence_score' => 0.82,
                'risk_level' => 'low',
                'review_checklist' => ['保留原价并在7天后按同房型同价盘口径复核。'],
                'drivers' => [
                    ['signal' => 'pickup_curve', 'rule' => 'pace_index>=110'],
                    ['signal' => 'price_elasticity', 'rule' => 'elasticity_supports_increase'],
                ],
                'signals' => ['data_gaps' => []],
            ],
        ]]);

        $recommendation = $enriched[0]['decision_recommendation'];
        self::assertSame('approved_pending_execution', $enriched[0]['pricing_readiness']['stage']);
        self::assertTrue($enriched[0]['pricing_readiness']['execution_intent_ready']);
        self::assertSame('verified', $recommendation['data_basis']['status']);
        self::assertSame('ctrip', $recommendation['data_basis']['platform']);
        self::assertSame('server_policy_verification_target', $recommendation['expected_effect']['origin']);
        self::assertSame(AiDecisionQualityService::CONTRACT_VERSION, $recommendation['decision_quality']['contract_version']);
        self::assertTrue($recommendation['decision_quality']['execution_ready']);
        self::assertTrue($recommendation['can_create_execution_intent']);

        $trusted = $enriched[0]['trusted_decision'];
        self::assertSame(RevenuePricingRecommendationService::TRUSTED_DECISION_CONTRACT_VERSION, $trusted['contract_version']);
        self::assertSame(7, $trusted['store']['hotel_id']);
        self::assertSame('ctrip', $trusted['platform']['key']);
        self::assertSame('2026-06-30', $trusted['date']['value']);
        self::assertSame('verified', $trusted['sources']['status']);
        self::assertGreaterThan(0, $trusted['sources']['ref_count']);
        self::assertSame('(建议价 - 当前价) ÷ 当前价 × 100%', $trusted['metric_formula']['expression']);
        self::assertSame('calculable', $trusted['metric_formula']['status']);
        self::assertSame(10.77, $trusted['metric_formula']['value']);
        self::assertSame('verified', $trusted['data_quality']['status']);
        self::assertTrue($trusted['data_quality']['decision_eligible']);
        self::assertSame('82%', $trusted['confidence']['display']);
        self::assertSame([], $trusted['gaps']);
        self::assertNotSame('', $trusted['recommended_action']['summary']);
        self::assertSame('server_policy_verification_target', $trusted['expected_effect']['origin']);
        self::assertFalse($trusted['human_confirmation']['can_confirm']);
        self::assertTrue($trusted['human_confirmation']['can_transfer_to_operation_task']);

        $pendingInput = $enriched[0];
        $pendingInput['id'] = 19;
        $pendingInput['status'] = 1;
        unset(
            $pendingInput['pricing_readiness'],
            $pendingInput['decision_recommendation'],
            $pendingInput['recommendation_quality'],
            $pendingInput['trusted_decision']
        );
        $pending = $service->enrichSuggestionRows([$pendingInput])[0];
        self::assertTrue($pending['trusted_decision']['human_confirmation']['can_confirm']);
        self::assertFalse($pending['trusted_decision']['human_confirmation']['can_transfer_to_operation_task']);
        self::assertTrue($pending['decision_recommendation']['can_human_confirm']);

        $missingDenominatorInput = $pendingInput;
        $missingDenominatorInput['id'] = 20;
        $missingDenominatorInput['current_price'] = null;
        $missingDenominator = $service->enrichSuggestionRows([$missingDenominatorInput])[0]['trusted_decision'];
        self::assertSame('not_calculable', $missingDenominator['metric_formula']['status']);
        self::assertSame('不可计算', $missingDenominator['metric_formula']['display']);
        self::assertSame('current_price_denominator_missing', $missingDenominator['metric_formula']['reason']);
        self::assertFalse($missingDenominator['human_confirmation']['can_confirm']);
        self::assertContains('current_price_denominator_missing', array_column($missingDenominator['gaps'], 'code'));
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

    public function testCtripTrafficTrendBuildsExplicitDemandForecastSource(): void
    {
        $service = new RevenuePricingRecommendationService();
        $daily = [];
        for ($i = 1; $i <= 14; $i++) {
            $daily[] = [
                'date' => sprintf('2026-06-%02d', $i),
                'metrics' => [
                    'order_submit_num' => $i <= 11 ? 100 : 130,
                    'order_filling_num' => 0,
                    'detail_exposure' => 0,
                    'list_exposure' => 0,
                ],
            ];
        }

        $forecast = $service->buildCtripTrafficDemandForecastSignal(
            $daily,
            '2026-06-15',
            '2026-06-01',
            '2026-06-14',
            94
        );

        self::assertSame('ok', $forecast['data_status']);
        self::assertSame('ctrip_historical_traffic_trend', $forecast['source']);
        self::assertSame(0, $forecast['id']);
        self::assertSame('order_submit_num', $forecast['primary_metric']);
        self::assertSame('rising', $forecast['trend_direction']);
        self::assertGreaterThan(100, $forecast['predicted_demand']);
        self::assertGreaterThan(50, $forecast['predicted_occupancy']);
        self::assertSame('ctrip_ota_channel', $forecast['source_metadata']['source_scope']);
        self::assertFalse($forecast['source_metadata']['auto_write_ota']);
        self::assertSame(
            'traffic_trend_score_0_100_for_Ctrip_channel_demand_trend_not_whole_hotel_occupancy_50_means_history_baseline',
            $forecast['source_metadata']['field_semantics']['predicted_occupancy']
        );
        self::assertSame([], $forecast['data_gaps']);
    }

    public function testEffectReviewWaitsForCompleteWindow(): void
    {
        $service = new RevenuePricingRecommendationService();

        $readiness = $service->buildEffectReviewReadiness([
            'status' => 4,
            'applied_time' => '2026-06-14 10:00:00',
        ], [
            'data_status' => 'ok',
            'sample_count' => 7,
            'start_date' => '2026-06-07',
            'end_date' => '2026-06-13',
        ], [
            'data_status' => 'ok',
            'sample_count' => 1,
            'start_date' => '2026-06-14',
            'end_date' => '2026-06-20',
        ], '2026-06-14');

        self::assertSame('effect_review_window_open', $readiness['stage']);
        self::assertFalse($readiness['review_ready']);
        self::assertSame(['review_window'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testEffectReviewReadyRequiresBeforeAndAfterSamples(): void
    {
        $service = new RevenuePricingRecommendationService();

        $readiness = $service->buildEffectReviewReadiness([
            'status' => 4,
            'applied_time' => '2026-06-01 10:00:00',
        ], [
            'data_status' => 'ok',
            'sample_count' => 7,
            'start_date' => '2026-05-25',
            'end_date' => '2026-05-31',
        ], [
            'data_status' => 'ok',
            'sample_count' => 7,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-07',
        ], '2026-06-14');

        self::assertSame('effect_review_ready', $readiness['stage']);
        self::assertTrue($readiness['review_ready']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testPricingSummaryPropagatesTrustedHistoryGapsAndSourcePolicy(): void
    {
        $repository = new class extends TrustedOtaFactRepository {
            /** @var array<int, array{hotel_id:int,start_date:string,end_date:string}> */
            public array $calls = [];

            public function pricingHistory(int $systemHotelId, string $startDate, string $endDate): array
            {
                $this->calls[] = [
                    'hotel_id' => $systemHotelId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];

                return [
                    'data_status' => 'blocked',
                    'rows' => [],
                    'data_gaps' => ['pricing_history_readback_verified_column_missing'],
                    'source_policy' => [
                        'hotel_scope' => 'system_hotel_id_strict_exact_only',
                        'readback_policy' => 'readback_verified_required_equals_1',
                    ],
                    'data_quality' => ['queried_rows' => 0, 'trusted_rows' => 0],
                ];
            }
        };
        $service = new RevenuePricingRecommendationService($repository);

        $summary = $service->hotelPricingModelSummary(80, '2026-07-17');
        $cachedSummary = $service->hotelPricingModelSummary(80, '2026-07-17');

        self::assertSame('blocked', $summary['history_data_status']);
        self::assertContains('pricing_history_readback_verified_column_missing', $summary['data_gaps']);
        self::assertContains('online_daily_history_missing', $summary['data_gaps']);
        self::assertSame('system_hotel_id_strict_exact_only', $summary['source_policy']['hotel_scope']);
        self::assertSame('readback_verified_required_equals_1', $summary['source_policy']['readback_policy']);
        self::assertSame($summary, $cachedSummary);
        self::assertCount(1, $repository->calls);
        self::assertSame(80, $repository->calls[0]['hotel_id']);
    }
}
