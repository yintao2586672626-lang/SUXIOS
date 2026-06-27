<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaDataCredibilityGateService;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class OtaDataCredibilityGateServiceTest extends TestCase
{
    public function testGateBlocksWhenDatasetOrCriticalMetricTrustIsMissing(): void
    {
        self::assertTrue(class_exists(OtaDataCredibilityGateService::class), 'OTA data credibility gate service must exist.');

        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'empty',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 0,
                'rejected_rows' => [
                    ['reason' => 'missing_required_fields', 'fields' => ['source', 'data_date']],
                    ['reason' => 'comment_collection_disabled', 'data_type' => 'review'],
                ],
            ],
        ], [
            'status' => 'empty',
            'metric_trust' => [
                'totals.revenue' => [
                    'saved_success' => false,
                    'failure_reasons' => ['source_missing'],
                ],
            ],
            'data_gaps' => [],
        ]);

        self::assertSame('blocked', $gate['status']);
        self::assertSame('ota_channel', $gate['metric_scope']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertFalse($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertContains('ota_dataset_empty', $gate['reason_codes']);
        self::assertContains('accepted_rows_missing', $gate['reason_codes']);
        self::assertContains('critical_metric_untrusted:totals.revenue', $gate['reason_codes']);
        self::assertSame(2, $gate['evidence']['input_rows']);
        self::assertSame(0, $gate['evidence']['accepted_rows']);
        self::assertSame(2, $gate['evidence']['rejected_rows']);
    }

    public function testRevenueMetricsExposeCredibilityGateWithoutPromotingOtaToInvestmentTruth(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 701,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 1,
                'cancel_room_nights' => 1,
                'lead_time_days' => 8,
                'our_price' => 200.0,
                'competitor_price' => 220.0,
                'price_gap' => -20.0,
                'price_gap_rate' => -9.09,
                'source_trace' => [
                    'row_id' => 701,
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'saved_success' => true,
                    'failure_reasons' => [],
                    'updated_at' => '2026-06-16 10:00:00',
                ],
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertArrayHasKey('credibility_gate', $metrics);
        self::assertSame('ready', $metrics['credibility_gate']['status']);
        self::assertSame('ota_channel', $metrics['credibility_gate']['metric_scope']);
        self::assertTrue($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
        self::assertTrue($metrics['credibility_gate']['decision_use']['ai_decision_support']['allowed']);
        self::assertFalse($metrics['credibility_gate']['decision_use']['investment_decision']['allowed']);
        self::assertContains('whole_hotel_scope_not_proved', $metrics['credibility_gate']['warnings']);
        self::assertSame(['totals.revenue', 'totals.room_nights', 'totals.adr'], $metrics['credibility_gate']['evidence']['critical_metrics']);
    }

    public function testGateDoesNotLetReadyMetricsHideFailedDatasetStatus(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'failed',
            'data_quality' => [
                'input_rows' => 3,
                'accepted_rows' => 3,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [
                ['id' => 1],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ]);

        self::assertSame('blocked', $gate['status']);
        self::assertContains('ota_dataset_failed', $gate['reason_codes']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertFalse($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertSame('failed', $gate['evidence']['dataset_status']);
        self::assertSame('ready', $gate['evidence']['metric_status']);
    }

    public function testGateMarksPartialDatasetAsWarningBeforeAiDecisionUse(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'partial',
            'data_quality' => [
                'input_rows' => 5,
                'accepted_rows' => 3,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ], [
            'whole_hotel_evidence' => true,
        ]);

        self::assertSame('warning', $gate['status']);
        self::assertContains('ota_dataset_partial', $gate['warnings']);
        self::assertTrue($gate['human_review_required']);
        self::assertTrue($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('allowed_with_data_warnings', $gate['decision_use']['revenue_analysis']['status']);
        self::assertTrue($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertSame('allowed_with_human_review', $gate['decision_use']['ai_decision_support']['status']);
        self::assertSame('partial', $gate['evidence']['dataset_status']);
    }

    public function testGateDoesNotInferDataQualityAsReadyWhenQualityEvidenceIsMissing(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'ready',
            'fact_ota_daily' => [
                ['id' => 1],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ], [
            'whole_hotel_evidence' => true,
        ]);

        self::assertSame('warning', $gate['status']);
        self::assertContains('data_quality_missing', $gate['warnings']);
        self::assertTrue($gate['human_review_required']);
        self::assertFalse($gate['evidence']['data_quality_present']);
        self::assertSame(1, $gate['evidence']['fact_rows']);
    }

    public function testP0DownstreamGateBlocksDownstreamDecisionUseEvenWhenMetricsLookReady(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'p0_downstream_gate' => [
                'status' => 'blocked_by_p0_ota_gate',
                'current_upstream_status' => 'incomplete',
                'required_upstream_status' => 'ready',
                'scope_policy' => 'ota_channel_gate_before_downstream_claims',
                'blocking_missing_inputs' => ['manual_login_state_verified', 'target_date_traffic_rows'],
                'blocked_stage_keys' => ['revenue_analysis', 'ai_decision_advice', 'operation_closure', 'investment_judgment'],
                'allowed_claims' => ['structure_ready_or_reference_only', 'no_whole_hotel_or_downstream_closure_claim'],
            ],
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 802,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1600.0,
                'room_revenue' => 1600.0,
                'room_nights' => 8.0,
                'source_trace' => [
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ]],
        ]);

        $gate = $metrics['credibility_gate'];
        self::assertSame('blocked', $gate['status']);
        self::assertContains('p0_ota_gate_not_ready', $gate['reason_codes']);
        self::assertContains('p0_ota_gate_missing:manual_login_state_verified', $gate['reason_codes']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['revenue_analysis']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['ai_decision_support']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['operation_management']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['investment_decision']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['evidence']['p0_downstream_gate']['status']);
        self::assertFalse($metrics['p1_revenue_closure']['calculation_allowed']);
        self::assertSame('blocked_by_p0_ota_gate', $metrics['p1_revenue_closure']['decision_use']['status']);
    }
}
