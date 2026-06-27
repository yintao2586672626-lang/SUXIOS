<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiOverviewServiceTest extends TestCase
{
    public function testOverviewBuildsOtaMetricsAndKeepsRevparScopedToWholeHotelDenominator(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([
                $this->dailyFact('ctrip', 1200, 6, 10),
                $this->dailyFact('meituan', 800, 4, 10),
            ]),
            [
                'ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
                'meituan' => $this->dataset([$this->dailyFact('meituan', 800, 4, 10)]),
            ],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00'],
                'meituan' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:10:00'],
            ],
            ['business_date' => '2026-06-25', 'hotel_id' => 7]
        );

        self::assertSame('ok', $overview['data_status']);
        self::assertSame('ota', $overview['scope']);
        self::assertSame('data_date', $overview['date_basis']);
        self::assertStringContainsString('尚未等同于入住日', $overview['date_basis_note']);
        self::assertStringContainsString('booking_date', $overview['date_basis_note']);
        self::assertStringContainsString('settlement_date', $overview['date_basis_note']);
        self::assertSame(['ctrip', 'meituan'], $overview['source_channels']);
        self::assertSame(2000.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(10.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame(200.0, $overview['metrics']['ota_adr']['value']);
        self::assertSame(100.0, $overview['metrics']['ota_contribution_revpar']['value']);
        self::assertSame('hotel', $overview['metrics']['ota_contribution_revpar']['scope']);
        self::assertSame('', $overview['metrics']['ota_contribution_revpar']['reason']);
        self::assertSame('本店低于竞对 ¥10.00', $overview['signals']['competitor_price_warning']['value']);
        self::assertSame('partial', $overview['signals']['competitor_price_warning']['status']);
        self::assertSame('competitor_price_below_competitor_review_required', $overview['signals']['competitor_price_warning']['reason']);
        self::assertSame(2, $overview['signals']['competitor_price_warning']['detail_metrics']['sample_rows']);
        self::assertSame(200.0, $overview['signals']['competitor_price_warning']['detail_metrics']['avg_our_price']);
        self::assertSame(210.0, $overview['signals']['competitor_price_warning']['detail_metrics']['avg_competitor_price']);
        self::assertSame('blocked', $overview['pricing_readiness']['overall_status']);
        self::assertFalse($overview['pricing_readiness']['can_generate_recommendation']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        self::assertTrue($overview['pricing_readiness']['manual_review_required']);
        self::assertContains('demand_forecasts_not_loaded', $overview['pricing_readiness']['blocking_reasons']);
        self::assertContains('floor_price_missing', $overview['pricing_readiness']['blocking_reasons']);
        self::assertContains('manual_review_workflow_not_connected', $overview['pricing_readiness']['blocking_reasons']);
        $gateByKey = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('ok', $gateByKey['ota_metrics']['status']);
        self::assertSame('ok', $gateByKey['competitor_price']['status']);
        self::assertSame('ok', $gateByKey['revpar_denominator']['status']);
        self::assertSame('blocked', $gateByKey['demand_signal_7d']['status']);
        self::assertSame('demand_forecasts_not_loaded', $gateByKey['demand_signal_7d']['reason']);
        self::assertSame('blocked', $gateByKey['floor_price']['status']);
        self::assertSame('pricing_guard', $gateByKey['floor_price']['category']);
        self::assertSame('暂缺最低保护价。', $gateByKey['floor_price']['display_reason']);
        self::assertStringContainsString('最低保护价', $gateByKey['floor_price']['next_action']);
        self::assertSame('blocked', $gateByKey['manual_review_workflow']['status']);
        self::assertSame('blocked', $gateByKey['operation_feedback_input']['status']);
        self::assertSame('operation_execution_not_loaded', $gateByKey['operation_feedback_input']['reason']);
        self::assertStringContainsString('运营执行闭环尚未读取', $gateByKey['operation_feedback_input']['display_reason']);
        self::assertSame('manual_review_only', $overview['pricing_readiness']['review_policy']['mode']);
        self::assertFalse($overview['pricing_readiness']['review_policy']['auto_write_ota']);
        self::assertContains('接入建议版本、批准/拒绝/转执行审计流后再开放调价建议。', $overview['pricing_readiness']['next_actions']);
        self::assertSame('暂无可审核调价建议', $overview['actions'][0]['title']);
        self::assertSame('blocked', $overview['actions'][0]['status']);
        self::assertSame('demand_forecasts_not_loaded', $overview['actions'][0]['reason']);
        self::assertStringContainsString('暂不生成调价建议', $overview['actions'][0]['detail']);
        self::assertFalse($overview['actions'][0]['auto_write_ota']);
        self::assertSame('blocked', $overview['actions'][0]['decision_basis_summary']['status']);
        self::assertSame('判断依据 可用 4 / 待补 4', $overview['actions'][0]['decision_basis_summary']['display']);
        self::assertSame(4, $overview['actions'][0]['decision_basis_summary']['ready_count']);
        self::assertSame(4, $overview['actions'][0]['decision_basis_summary']['blocked_count']);
        self::assertFalse($overview['actions'][0]['decision_basis_summary']['auto_write_ota']);
        self::assertContains('上一轮调价效果输入', $overview['actions'][0]['decision_basis_summary']['blocked_labels']);
        $basisByKey = array_column($overview['actions'][0]['decision_basis_summary']['items'], null, 'key');
        self::assertSame('online-data', $basisByKey['floor_price']['target_page']);
        self::assertSame('data-health', $basisByKey['floor_price']['target_tab']);
        self::assertSame('ops-track', $basisByKey['operation_feedback_input']['target_page']);
        self::assertContains('接入建议版本、批准/拒绝/转执行审计流后再开放调价建议。', $overview['actions'][0]['next_actions']);
    }

    public function testCompetitorPriceSignalWarnsWhenOurPriceIsAboveCompetitorAverage(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([
                $this->dailyFact('ctrip', 260, 1, null, [
                    'our_price' => 260.0,
                    'competitor_price' => 240.0,
                    'price_gap' => 20.0,
                    'price_gap_rate' => 8.33,
                ]),
            ]),
            ['ctrip' => $this->dataset([
                $this->dailyFact('ctrip', 260, 1, null, [
                    'our_price' => 260.0,
                    'competitor_price' => 240.0,
                    'price_gap' => 20.0,
                    'price_gap_rate' => 8.33,
                ]),
            ])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            ['business_date' => '2026-06-25']
        );

        $signal = $overview['signals']['competitor_price_warning'];
        self::assertSame('本店高于竞对 ¥20.00', $signal['value']);
        self::assertSame('warning', $signal['status']);
        self::assertSame('competitor_price_above_competitor', $signal['reason']);
        self::assertSame(1, $signal['detail_metrics']['sample_rows']);
        self::assertSame(260.0, $signal['detail_metrics']['avg_our_price']);
        self::assertSame(240.0, $signal['detail_metrics']['avg_competitor_price']);
        self::assertSame(20.0, $signal['detail_metrics']['avg_price_gap']);
        self::assertSame(8.33, $signal['detail_metrics']['avg_price_gap_rate']);
        self::assertSame('暂无可审核调价建议', $overview['actions'][0]['title']);
        self::assertSame('blocked', $overview['pricing_readiness']['overall_status']);
        self::assertContains('floor_price_missing', $overview['pricing_readiness']['blocking_reasons']);
        self::assertFalse($overview['actions'][0]['auto_write_ota']);
    }

    public function testPricingReadinessBlocksWhenQualityIssuesAreBlocking(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            [
                'ctrip' => $this->dataset([]),
                'meituan' => $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            ],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'login expired'],
                'meituan' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00'],
            ],
            ['business_date' => '2026-06-25']
        );

        $gateByKey = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('blocked', $overview['pricing_readiness']['overall_status']);
        self::assertSame('blocked', $gateByKey['data_quality']['status']);
        self::assertSame('AUTH_EXPIRED', $gateByKey['data_quality']['reason']);
        self::assertSame('auth', $gateByKey['data_quality']['category']);
        self::assertStringContainsString('重新登录', $gateByKey['data_quality']['next_action']);
        self::assertContains('AUTH_EXPIRED', $overview['pricing_readiness']['blocking_reasons']);
        self::assertSame('AUTH_EXPIRED', $overview['actions'][0]['reason']);
        self::assertFalse($overview['pricing_readiness']['can_generate_recommendation']);
    }

    public function testRevparStaysBlankWhenAvailableRoomNightsAreMissing(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, null)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, null)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('partial', $overview['data_status']);
        self::assertNull($overview['metrics']['ota_contribution_revpar']['value']);
        self::assertSame('--', $overview['metrics']['ota_contribution_revpar']['display']);
        self::assertSame('hotel_required', $overview['metrics']['ota_contribution_revpar']['scope']);
        self::assertSame('available_room_nights_missing', $overview['metrics']['ota_contribution_revpar']['reason']);
        self::assertContains('available_room_nights_missing', array_column($overview['missing_datasets'], 'reason'));
        $availableRoomNightGap = $this->findIssue($overview['missing_datasets'], 'available_room_nights_missing');
        self::assertSame('missing_dataset', $availableRoomNightGap['type']);
        self::assertSame('high', $availableRoomNightGap['severity']);
        self::assertSame('denominator', $availableRoomNightGap['category']);
        self::assertSame('online-data', $availableRoomNightGap['target_page']);
        self::assertSame('data-health', $availableRoomNightGap['target_tab']);
        self::assertSame('暂缺可信全酒店可售房晚数据。', $availableRoomNightGap['display_reason']);
    }

    public function testPhase1AOverviewContractKeepsReadonlyMetricsAndQualityReasonsExplicit(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, null)]),
            [
                'ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, null)]),
                'meituan' => $this->dataset([]),
            ],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00'],
                'meituan' => ['status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'captcha required'],
            ],
            ['business_date' => '2026-06-25', 'hotel_id' => 7]
        );

        foreach ([
            'data_status',
            'scope',
            'date_basis',
            'source_channels',
            'last_success_at',
            'missing_datasets',
            'quality_issues',
            'metrics',
        ] as $key) {
            self::assertArrayHasKey($key, $overview);
        }

        self::assertSame('ota', $overview['scope']);
        self::assertSame('data_date', $overview['date_basis']);
        self::assertSame(['ctrip'], $overview['source_channels']);
        self::assertSame('2026-06-25 08:00:00', $overview['last_success_at']);
        self::assertSame('unauthorized', $overview['channel_statuses']['meituan']['status']);
        self::assertSame('CAPTCHA_REQUIRED', $overview['channel_statuses']['meituan']['reason']);
        self::assertContains('CAPTCHA_REQUIRED', array_column($overview['quality_issues'], 'reason'));

        foreach (['ota_room_revenue', 'ota_room_nights', 'ota_adr', 'ota_contribution_revpar'] as $metricKey) {
            self::assertArrayHasKey($metricKey, $overview['metrics']);
            foreach (['value', 'display', 'unit', 'scope', 'date_basis', 'source_channels', 'last_success_at', 'status', 'reason'] as $field) {
                self::assertArrayHasKey($field, $overview['metrics'][$metricKey]);
            }
        }

        self::assertSame(1200.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(6.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertSame(200.0, $overview['metrics']['ota_adr']['value']);
        self::assertNull($overview['metrics']['ota_contribution_revpar']['value']);
        self::assertSame('--', $overview['metrics']['ota_contribution_revpar']['display']);
        self::assertSame('hotel_required', $overview['metrics']['ota_contribution_revpar']['scope']);
        self::assertSame('available_room_nights_missing', $overview['metrics']['ota_contribution_revpar']['reason']);
        self::assertContains('available_room_nights_missing', array_column($overview['missing_datasets'], 'reason'));
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testAdrIsNotCalculatedWhenRoomNightDenominatorIsZero(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 0, null)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 0, null)])],
            [],
            ['business_date' => '2026-06-25']
        );

        self::assertSame(1200.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(0.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertNull($overview['metrics']['ota_adr']['value']);
        self::assertSame('adr_denominator_zero', $overview['metrics']['ota_adr']['reason']);
    }

    public function testEmptyDatasetDoesNotDisplaySyntheticZeroMetrics(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([]),
            ['ctrip' => $this->dataset([]), 'meituan' => $this->dataset([])],
            [],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('unknown', $overview['data_status']);
        self::assertSame([], $overview['source_channels']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('--', $overview['metrics']['ota_room_revenue']['display']);
        self::assertNull($overview['metrics']['ota_room_nights']['value']);
        self::assertSame('--', $overview['metrics']['ota_room_nights']['display']);
        self::assertContains('online_daily_data_empty', array_column($overview['missing_datasets'], 'reason'));
    }

    public function testAuthFailureStatusIsExposedAsUnauthorized(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            [
                'ctrip' => $this->dataset([]),
                'meituan' => $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            ],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'login expired'],
                'meituan' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00'],
            ],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('unauthorized', $overview['data_status']);
        self::assertSame('unauthorized', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('AUTH_EXPIRED', $overview['channel_statuses']['ctrip']['reason']);
        self::assertContains('AUTH_EXPIRED', array_column($overview['quality_issues'], 'reason'));
        $authIssue = $this->findIssue($overview['quality_issues'], 'AUTH_EXPIRED');
        self::assertSame('quality_issue', $authIssue['type']);
        self::assertSame('high', $authIssue['severity']);
        self::assertSame('auth', $authIssue['category']);
        self::assertSame('online-data', $authIssue['target_page']);
        self::assertSame('data-health', $authIssue['target_tab']);
        self::assertStringContainsString('登录或授权已失效', $authIssue['display_reason']);
        self::assertGreaterThanOrEqual(1, $overview['issue_summary']['high_count']);
    }

    public function testSuccessfulSourceWithoutTargetDateRowsIsExposedAsStale(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([]),
            ['ctrip' => $this->dataset([]), 'meituan' => $this->dataset([])],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-24 23:00:00'],
            ],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('stale', $overview['data_status']);
        self::assertSame('stale', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('DATA_STALE', $overview['channel_statuses']['ctrip']['reason']);
        self::assertContains('DATA_STALE', array_column($overview['quality_issues'], 'reason'));
        $staleIssue = $this->findIssue($overview['quality_issues'], 'DATA_STALE');
        self::assertSame('stale', $staleIssue['category']);
        self::assertSame('high', $staleIssue['severity']);
        self::assertStringContainsString('数据过期', $staleIssue['display_reason']);
    }

    public function testConfirmedEmptyChannelsDoNotCreateSyntheticZeroMetrics(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([]),
            ['ctrip' => $this->dataset([]), 'meituan' => $this->dataset([])],
            [
                'ctrip' => ['status' => 'ready', 'last_sync_status' => 'empty_confirmed', 'last_sync_time' => '2026-06-25 08:00:00'],
                'meituan' => ['status' => 'ready', 'last_sync_status' => 'zero_confirmed', 'last_sync_time' => '2026-06-25 08:05:00'],
            ],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('empty_confirmed', $overview['data_status']);
        self::assertSame('empty_confirmed', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('ZERO_CONFIRMED', $overview['channel_statuses']['ctrip']['reason']);
        self::assertSame('empty_confirmed', $overview['channel_statuses']['meituan']['status']);
        self::assertSame('ZERO_CONFIRMED', $overview['channel_statuses']['meituan']['reason']);
        self::assertSame([], $overview['source_channels']);
        self::assertSame('2026-06-25 08:05:00', $overview['last_success_at']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertSame('--', $overview['metrics']['ota_room_revenue']['display']);
        self::assertSame('empty_confirmed', $overview['metrics']['ota_room_revenue']['status']);
        self::assertSame('ZERO_CONFIRMED', $overview['metrics']['ota_room_revenue']['reason']);
        self::assertNull($overview['metrics']['ota_room_nights']['value']);
        self::assertSame('--', $overview['metrics']['ota_room_nights']['display']);
        self::assertSame('empty_confirmed', $overview['metrics']['ota_adr']['status']);
        self::assertSame('ZERO_CONFIRMED', $overview['metrics']['ota_adr']['reason']);
        self::assertContains('ZERO_CONFIRMED', array_column($overview['missing_datasets'], 'reason'));
        self::assertNotContains('ZERO_CONFIRMED', array_column($overview['quality_issues'], 'reason'));
        $confirmedEmptyIssue = $this->findIssue($overview['missing_datasets'], 'ZERO_CONFIRMED');
        self::assertSame('low', $confirmedEmptyIssue['severity']);
        self::assertSame('data', $confirmedEmptyIssue['category']);
        self::assertStringContainsString('明确确认目标经营日期无数据', $confirmedEmptyIssue['display_reason']);
    }

    public function testDisabledSourceIsNeverReportedAsSuccessful(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            [
                'ctrip' => $this->dataset([]),
                'meituan' => $this->dataset([$this->dailyFact('meituan', 500, 2, null)]),
            ],
            [
                'ctrip' => ['enabled' => 0, 'status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00'],
                'meituan' => ['enabled' => 1, 'status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:10:00'],
            ],
            ['business_date' => '2026-06-25']
        );

        self::assertSame('unauthorized', $overview['data_status']);
        self::assertSame('unauthorized', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('source_disabled', $overview['channel_statuses']['ctrip']['reason']);
        self::assertSame('已禁用', $overview['channel_statuses']['ctrip']['label']);
        self::assertContains('source_disabled', array_column($overview['quality_issues'], 'reason'));
        $disabledIssue = $this->findIssue($overview['quality_issues'], 'source_disabled');
        self::assertSame('high', $disabledIssue['severity']);
        self::assertSame('source', $disabledIssue['category']);
        self::assertStringContainsString('已禁用', $disabledIssue['display_reason']);
    }

    public function testPriceSuggestionReviewQueueSummarizesManualReviewState(): void
    {
        $queue = (new RevenueAiOverviewService())->buildPriceSuggestionReviewQueue([
            [
                'id' => 11,
                'hotel_id' => 7,
                'room_type_id' => 3,
                'demand_forecast_id' => 5,
                'suggestion_type' => 2,
                'status' => 1,
                'suggestion_date' => '2026-06-25',
                'current_price' => 280,
                'suggested_price' => 318,
                'min_price' => 220,
                'max_price' => 380,
                'confidence_score' => 0.82,
                'reason' => 'token=secret competitor price higher',
                'factors' => ['high forecast occupancy', 'weekend pace'],
                'competitor_data' => ['avg_price' => 330, 'median_price' => 320, 'min_price' => 299],
                'update_time' => '2026-06-25 09:00:00',
            ],
            ['id' => 12, 'status' => 2, 'update_time' => '2026-06-25 08:00:00'],
            ['id' => 13, 'status' => 3, 'update_time' => '2026-06-25 07:00:00'],
            ['id' => 14, 'status' => 4, 'update_time' => '2026-06-25 06:00:00'],
        ], '2026-06-25', 7);

        self::assertSame('pending_review', $queue['status']);
        self::assertSame('price_suggestions_pending_review', $queue['reason']);
        self::assertSame('price_suggestions', $queue['source_table']);
        self::assertSame('suggestion_date', $queue['date_basis']);
        self::assertSame(4, $queue['total_count']);
        self::assertSame(1, $queue['pending_count']);
        self::assertSame(1, $queue['approved_count']);
        self::assertSame(1, $queue['rejected_count']);
        self::assertSame(1, $queue['applied_count']);
        self::assertSame(2, $queue['approved_or_applied_count']);
        self::assertSame([11], $queue['pending_ids']);
        self::assertSame('2026-06-25 09:00:00', $queue['last_success_at']);
        self::assertStringContainsString('待审核 1', $queue['display']);
        self::assertTrue($queue['manual_review_required']);
        self::assertFalse($queue['auto_write_ota']);
        self::assertCount(1, $queue['pending_items']);
        self::assertCount(4, $queue['recent_items']);
        self::assertSame(11, $queue['pending_items'][0]['id']);
        self::assertSame('pending_review', $queue['pending_items'][0]['status']);
        self::assertSame('待审核', $queue['pending_items'][0]['status_label']);
        self::assertSame(280.0, $queue['pending_items'][0]['current_price']);
        self::assertSame('280元', $queue['pending_items'][0]['current_price_display']);
        self::assertSame('318元', $queue['pending_items'][0]['suggested_price_display']);
        self::assertSame('220元', $queue['pending_items'][0]['min_price_display']);
        self::assertSame('+38元', $queue['pending_items'][0]['price_change_display']);
        self::assertSame('+13.57%', $queue['pending_items'][0]['price_change_rate_display']);
        self::assertSame('82%', $queue['pending_items'][0]['confidence_display']);
        self::assertSame('竞对均价 330元 / 竞对中位价 320元 / 竞对最低价 299元', $queue['pending_items'][0]['competitor_summary']);
        self::assertNull($queue['pending_items'][0]['expected_revpar_impact']);
        self::assertSame('--', $queue['pending_items'][0]['expected_revpar_impact_display']);
        self::assertSame('expected_revpar_impact_missing', $queue['pending_items'][0]['expected_revpar_impact_reason']);
        self::assertSame('high forecast occupancy / weekend pace', $queue['pending_items'][0]['factors_summary']);
        self::assertStringContainsString('token=***', $queue['pending_items'][0]['reason']);
        self::assertStringNotContainsString('secret', $queue['pending_items'][0]['reason']);
        self::assertTrue($queue['pending_items'][0]['can_review']);
        self::assertTrue($queue['pending_items'][0]['manual_review_required']);
        self::assertFalse($queue['pending_items'][0]['auto_write_ota']);
        self::assertSame('去审核', $queue['pending_items'][0]['action_entry']['label']);
        self::assertSame('compass', $queue['pending_items'][0]['action_entry']['target_page']);
        self::assertSame('', $queue['pending_items'][0]['action_entry']['target_agent_tab']);
        self::assertSame('', $queue['pending_items'][0]['action_entry']['target_revenue_tab']);
        self::assertFalse($queue['pending_items'][0]['action_entry']['requires_super_admin']);
        self::assertTrue($queue['pending_items'][0]['action_entry']['requires_hotel_permission']);
        self::assertTrue($queue['pending_items'][0]['action_entry']['homepage_read_only']);
        self::assertSame('/api/revenue-ai/price-suggestions/11/review', $queue['pending_items'][0]['action_entry']['allowed_endpoint']);
        self::assertSame('/api/revenue-ai/price-suggestions/11/review', $queue['pending_items'][0]['action_entry']['allowed_endpoints']['review']);
        self::assertSame('/api/revenue-ai/price-suggestions/11/execution-intent', $queue['pending_items'][0]['action_entry']['allowed_endpoints']['execution_intent']);
        self::assertSame(['approve', 'approve_with_changes', 'reject'], $queue['pending_items'][0]['action_entry']['manual_actions']);
        self::assertContains('apply_price', $queue['pending_items'][0]['action_entry']['forbidden_actions']);
        self::assertContains('ota_write', $queue['pending_items'][0]['action_entry']['forbidden_actions']);
        self::assertSame('--', $queue['recent_items'][1]['current_price_display']);
        self::assertSame('price_suggestion_required_values_missing', $queue['recent_items'][1]['missing_reason']);
        self::assertContains('current_price', $queue['recent_items'][1]['missing_fields']);
    }

    public function testPriceSuggestionReviewQueueExposesExplicitExpectedRevparImpactOnly(): void
    {
        $queue = (new RevenueAiOverviewService())->buildPriceSuggestionReviewQueue([
            [
                'id' => 11,
                'hotel_id' => 7,
                'status' => 1,
                'suggestion_date' => '2026-06-25',
                'current_price' => 280,
                'suggested_price' => 318,
                'min_price' => 220,
                'factors' => [
                    'expected_impact' => ['revpar_delta' => 12.5],
                ],
            ],
            [
                'id' => 12,
                'hotel_id' => 7,
                'status' => 1,
                'suggestion_date' => '2026-06-25',
                'current_price' => 260,
                'suggested_price' => 268,
                'min_price' => 220,
                'factors' => ['demand signal only'],
            ],
        ], '2026-06-25', 7);

        self::assertSame(12.5, $queue['pending_items'][0]['expected_revpar_impact']);
        self::assertSame('+12.5元', $queue['pending_items'][0]['expected_revpar_impact_display']);
        self::assertSame('partial', $queue['pending_items'][0]['expected_revpar_impact_status']);
        self::assertSame('', $queue['pending_items'][0]['expected_revpar_impact_reason']);
        self::assertSame('hotel', $queue['pending_items'][0]['expected_revpar_impact_scope']);
        self::assertSame('suggestion_date', $queue['pending_items'][0]['expected_revpar_impact_date_basis']);
        self::assertNull($queue['pending_items'][1]['expected_revpar_impact']);
        self::assertSame('--', $queue['pending_items'][1]['expected_revpar_impact_display']);
        self::assertSame('not_calculable', $queue['pending_items'][1]['expected_revpar_impact_status']);
        self::assertSame('expected_revpar_impact_missing', $queue['pending_items'][1]['expected_revpar_impact_reason']);
    }

    public function testOverviewExposesPendingReviewQueueWithoutWritingOtaPrices(): void
    {
        $service = new RevenueAiOverviewService();
        $reviewQueue = $service->buildPriceSuggestionReviewQueue([
            ['id' => 21, 'status' => 1, 'update_time' => '2026-06-25 09:00:00'],
        ], '2026-06-25', 7);

        $overview = $service->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            [
                'business_date' => '2026-06-25',
                'hotel_id' => 7,
                'review_queue' => $reviewQueue,
                'execution_summary' => [
                    'reason' => 'operation_effect_review_ready',
                    'effect_review' => [
                        'reason' => 'operation_effect_review_ready',
                        'input_reason' => 'operation_effect_review_ready',
                        'input_ready_count' => 1,
                        'next_day_input_ready' => true,
                    ],
                ],
            ]
        );

        self::assertSame('pending_review', $overview['review_queue']['status']);
        self::assertSame(1, $overview['review_queue']['pending_count']);
        $gateByKey = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('ok', $gateByKey['manual_review_workflow']['status']);
        self::assertSame('ok', $gateByKey['operation_feedback_input']['status']);
        self::assertStringContainsString('ROI/增量收入证据', $gateByKey['operation_feedback_input']['display_reason']);
        self::assertNotContains('manual_review_workflow_not_connected', $overview['pricing_readiness']['blocking_reasons']);
        self::assertNotContains('operation_execution_not_loaded', $overview['pricing_readiness']['blocking_reasons']);
        self::assertSame('待人工审核调价建议', $overview['actions'][0]['title']);
        self::assertSame('pending_review', $overview['actions'][0]['status']);
        self::assertSame('price_suggestions_pending_review', $overview['actions'][0]['reason']);
        self::assertSame(1, $overview['actions'][0]['review_queue']['pending_count']);
        self::assertStringContainsString('待审核 1', $overview['actions'][0]['review_queue_summary']);
        self::assertFalse($overview['actions'][0]['auto_write_ota']);
        self::assertSame('blocked', $overview['actions'][0]['decision_basis_summary']['status']);
        self::assertContains('上一轮调价效果输入', $overview['actions'][0]['decision_basis_summary']['ready_labels']);
        self::assertSame('operation_feedback_input', array_column($overview['actions'][0]['decision_basis_summary']['items'], null, 'key')['operation_feedback_input']['key']);
        self::assertSame('ops-track', array_column($overview['actions'][0]['decision_basis_summary']['items'], null, 'key')['operation_feedback_input']['target_page']);
        self::assertContains('进入定价建议列表完成人工批准、修改后批准、拒绝或转执行；Revenue AI 首页不自动写 OTA。', $overview['actions'][0]['next_actions']);
    }

    public function testDemandForecastSignalSummarizesFutureSevenDaysWithoutAutoPricing(): void
    {
        $signal = (new RevenueAiOverviewService())->buildDemandForecastSignal([
            ['hotel_id' => 7, 'forecast_date' => '2026-06-26', 'predicted_occupancy' => 92, 'predicted_demand' => 18, 'confidence_score' => 0.82, 'event_type' => 1],
            ['hotel_id' => 7, 'forecast_date' => '2026-06-27', 'predicted_occupancy' => 76, 'predicted_demand' => 12, 'confidence_score' => 0.78, 'event_type' => 0],
        ], '2026-06-26', '2026-07-02', 7);

        self::assertSame('warning', $signal['status']);
        self::assertSame('demand_forecasts_high_demand', $signal['reason']);
        self::assertSame('高需求 1天', $signal['value']);
        self::assertSame('demand_forecasts', $signal['source_table']);
        self::assertSame('forecast_date', $signal['date_basis']);
        self::assertSame(2, $signal['detail_metrics']['sample_rows']);
        self::assertSame(84.0, $signal['detail_metrics']['avg_predicted_occupancy']);
        self::assertSame(30.0, $signal['detail_metrics']['total_predicted_demand']);
        self::assertSame(['2026-06-26'], $signal['detail_metrics']['high_demand_dates']);
        self::assertTrue($signal['read_only']);
        self::assertFalse($signal['auto_write_ota']);
    }

    public function testDemandForecastSignalLowConfidenceStillBlocksPricingReadiness(): void
    {
        $service = new RevenueAiOverviewService();
        $demandSignal = $service->buildDemandForecastSignal([
            ['hotel_id' => 7, 'forecast_date' => '2026-06-26', 'predicted_occupancy' => 90, 'predicted_demand' => 18, 'confidence_score' => 0.42],
        ], '2026-06-26', '2026-07-02', 7);
        $overview = $service->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            [
                'business_date' => '2026-06-25',
                'hotel_id' => 7,
                'market_signals' => [
                    'demand_7d' => $demandSignal,
                    'holiday_event' => $service->buildHolidayEventSignal([
                        ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
                    ], '2026-06-26', '2026-06-25', 7),
                ],
            ]
        );

        $gateByKey = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('partial', $overview['signals']['demand_7d']['status']);
        self::assertSame('demand_forecasts_low_confidence', $overview['signals']['demand_7d']['reason']);
        self::assertSame('blocked', $gateByKey['demand_signal_7d']['status']);
        self::assertSame('demand_forecasts_low_confidence', $gateByKey['demand_signal_7d']['reason']);
        self::assertContains('demand_forecasts_low_confidence', $overview['pricing_readiness']['blocking_reasons']);
    }

    public function testOverviewAcceptsReadonlyDemandAndHolidaySignals(): void
    {
        $service = new RevenueAiOverviewService();
        $demandSignal = $service->buildDemandForecastSignal([
            ['hotel_id' => 7, 'forecast_date' => '2026-06-26', 'predicted_occupancy' => 72, 'predicted_demand' => 12, 'confidence_score' => 0.88],
        ], '2026-06-26', '2026-07-02', 7);
        $holidaySignal = $service->buildHolidayEventSignal([
            ['name' => '测试节日', 'start_date' => '2026-07-01', 'end_date' => '2026-07-03'],
        ], '2026-06-26', '2026-06-25', 7);

        $overview = $service->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            [
                'business_date' => '2026-06-25',
                'hotel_id' => 7,
                'market_signals' => [
                    'demand_7d' => $demandSignal,
                    'holiday_event' => $holidaySignal,
                ],
            ]
        );

        $gateByKey = array_column($overview['pricing_readiness']['gates'], null, 'key');
        self::assertSame('demand_forecasts_available', $overview['signals']['demand_7d']['reason']);
        self::assertSame('ok', $gateByKey['demand_signal_7d']['status']);
        self::assertSame('holiday_event_nearby', $overview['signals']['holiday_event']['reason']);
        self::assertSame('测试节日 T-5', $overview['signals']['holiday_event']['value']);
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
        self::assertContains('floor_price_missing', $overview['pricing_readiness']['blocking_reasons']);
    }

    public function testAgentActivitySummarizesRevenueAgentLogsWithoutContextData(): void
    {
        $activity = (new RevenueAiOverviewService())->buildAgentActivity([
            [
                'id' => 31,
                'hotel_id' => 7,
                'agent_type' => 2,
                'action' => 'price_review',
                'message' => '建议已进入人工审核，token=secret-value',
                'log_level' => 2,
                'context_data' => ['token' => 'secret-value'],
                'create_time' => '2026-06-25 10:00:00',
            ],
            [
                'id' => 32,
                'hotel_id' => 7,
                'agent_type' => 2,
                'action' => 'forecast_check',
                'message' => '需求样本不足',
                'log_level' => 3,
                'create_time' => '2026-06-25 09:00:00',
            ],
        ], '2026-06-25', 7);

        self::assertSame('warning', $activity['status']);
        self::assertSame('agent_logs_warning_present', $activity['reason']);
        self::assertSame('agent_logs', $activity['source_table']);
        self::assertSame('create_time', $activity['date_basis']);
        self::assertSame(2, $activity['total_count']);
        self::assertSame(1, $activity['info_count']);
        self::assertSame(1, $activity['warning_count']);
        self::assertSame(0, $activity['error_count']);
        self::assertSame('2026-06-25 10:00:00', $activity['last_success_at']);
        self::assertCount(2, $activity['recent_logs']);
        self::assertSame('收益管理Agent', $activity['recent_logs'][0]['agent_type_label']);
        self::assertSame('price_review', $activity['recent_logs'][0]['action']);
        self::assertStringContainsString('token=***', $activity['recent_logs'][0]['message']);
        self::assertArrayNotHasKey('context_data', $activity['recent_logs'][0]);
        self::assertTrue($activity['read_only']);
        self::assertFalse($activity['auto_write_ota']);
    }

    public function testOverviewExposesAgentActivityAsReadonlyTrace(): void
    {
        $service = new RevenueAiOverviewService();
        $agentActivity = $service->buildAgentActivity([
            [
                'id' => 41,
                'hotel_id' => 7,
                'agent_type' => 2,
                'action' => 'pricing_failed',
                'message' => '最低保护价缺失',
                'log_level' => 4,
                'create_time' => '2026-06-25 11:00:00',
            ],
        ], '2026-06-25', 7);

        $overview = $service->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            ['business_date' => '2026-06-25', 'hotel_id' => 7, 'agent_activity' => $agentActivity]
        );

        self::assertSame('failed', $overview['agent_activity']['status']);
        self::assertSame('agent_logs_error_present', $overview['agent_activity']['reason']);
        self::assertSame(1, $overview['agent_activity']['error_count']);
        self::assertSame('pricing_failed', $overview['agent_activity']['recent_logs'][0]['action']);
        self::assertTrue($overview['agent_activity']['read_only']);
        self::assertFalse($overview['agent_activity']['auto_write_ota']);
    }

    public function testExecutionSummarySeparatesProcessProgressFromEffectReview(): void
    {
        $summary = (new RevenueAiOverviewService())->buildExecutionSummaryFromFlow([
            'data_status' => 'ok',
            'data_gaps' => [],
            'list' => [
                $this->executionFlowItem([
                    'id' => 101,
                    'stage' => 'review',
                    'approval_status' => 'approved',
                    'execution_status' => 'executed',
                    'evidence_count' => 1,
                    'review_status' => 'observing',
                    'roi_status' => 'data_gap',
                    'next_action_label' => '触发效果复盘',
                ]),
            ],
        ], '2026-06-25', 7);

        self::assertSame('review_needed', $summary['status']);
        self::assertSame('operation_execution_review_needed', $summary['reason']);
        self::assertSame(1, $summary['total_count']);
        self::assertSame(1, $summary['approved_count']);
        self::assertSame(1, $summary['executed_count']);
        self::assertSame(1, $summary['evidence_ready_count']);
        self::assertSame(1, $summary['review_needed_count']);
        self::assertSame(0, $summary['reviewed_count']);
        self::assertSame(0, $summary['roi_ready_count']);
        self::assertSame('review_needed', $summary['effect_review']['status']);
        self::assertSame('operation_roi_missing', $summary['effect_review']['reason']);
        self::assertSame('复盘 0 / ROI 0', $summary['effect_review']['display']);
        self::assertSame('partial', $summary['effect_review']['input_status']);
        self::assertSame('operation_roi_missing', $summary['effect_review']['input_reason']);
        self::assertSame(1, $summary['effect_review']['input_total_count']);
        self::assertSame(0, $summary['effect_review']['input_ready_count']);
        self::assertSame(1, $summary['effect_review']['input_partial_count']);
        self::assertSame(0, $summary['effect_review']['input_missing_count']);
        self::assertSame('明日输入 可用 0 / 待补 1 / 缺失 0', $summary['effect_review']['input_display']);
        self::assertFalse($summary['effect_review']['next_day_input_ready']);
        self::assertSame('partial', $summary['effect_review']['inputs'][0]['input_status']);
        self::assertSame('operation_roi_missing', $summary['effect_review']['inputs'][0]['input_reason']);
        self::assertSame('record_roi_evidence', $summary['effect_review']['inputs'][0]['input_action_key']);
        self::assertSame('补录ROI证据', $summary['effect_review']['inputs'][0]['input_action_label']);
        self::assertSame('补齐执行前后收入、成本或平台回执后再判断效果。', $summary['effect_review']['inputs'][0]['input_next_action']);
        self::assertSame('效果复盘', $summary['recent_items'][0]['stage_label']);
        self::assertSame(101, $summary['recent_items'][0]['intent_id']);
        self::assertSame(7, $summary['recent_items'][0]['hotel_id']);
        self::assertSame(201, $summary['recent_items'][0]['task_id']);
        self::assertSame('ops-track', $summary['recent_items'][0]['target_page']);
        self::assertSame('review_effect', $summary['recent_items'][0]['target_action']);
        self::assertSame(201, $summary['recent_items'][0]['target_id']);
        self::assertSame('task', $summary['recent_items'][0]['target_kind']);
        self::assertTrue($summary['read_only']);
        self::assertFalse($summary['auto_write_ota']);
    }

    public function testExecutionSummaryFiltersByBusinessDateAndMarksReviewedEffectReady(): void
    {
        $summary = (new RevenueAiOverviewService())->buildExecutionSummaryFromFlow([
            'data_status' => 'ok',
            'list' => [
                $this->executionFlowItem([
                    'id' => 111,
                    'stage' => 'reviewed',
                    'approval_status' => 'approved',
                    'execution_status' => 'executed',
                    'evidence_count' => 2,
                    'review_status' => 'success',
                    'review_summary' => 'ADR lifted after price adjustment',
                    'roi_status' => 'ready',
                    'roi_value' => 180.5,
                    'roi_unit' => 'amount',
                    'before_revenue' => 1200.0,
                    'after_revenue' => 1380.5,
                    'incremental_revenue' => 180.5,
                    'cost' => 0.0,
                    'profit' => 180.5,
                    'formula' => 'after_revenue - before_revenue',
                    'latest_evidence' => [
                        'evidence_type' => 'manual_roi_evidence',
                        'before' => ['revenue' => 1200.0],
                        'after' => ['revenue' => 1380.5],
                        'platform_response' => ['source' => 'revenue_ai_effect_review_input'],
                        'attachment_path' => '/runtime/evidence/roi-111.png',
                        'created_at' => '2026-06-26 09:00:00',
                    ],
                ]),
                $this->executionFlowItem([
                    'id' => 112,
                    'stage' => 'approval',
                    'date_start' => '2026-06-24',
                    'date_end' => '2026-06-24',
                    'approval_status' => 'pending_approval',
                ]),
            ],
        ], '2026-06-25', 7);

        self::assertSame('reviewed', $summary['status']);
        self::assertSame(1, $summary['total_count']);
        self::assertSame(1, $summary['reviewed_count']);
        self::assertSame(1, $summary['roi_ready_count']);
        self::assertSame('ok', $summary['effect_review']['status']);
        self::assertSame('operation_effect_review_ready', $summary['effect_review']['reason']);
        self::assertSame('ready', $summary['effect_review']['input_status']);
        self::assertSame('operation_effect_review_ready', $summary['effect_review']['input_reason']);
        self::assertSame(1, $summary['effect_review']['input_count']);
        self::assertSame(1, $summary['effect_review']['input_total_count']);
        self::assertSame(1, $summary['effect_review']['input_ready_count']);
        self::assertSame(0, $summary['effect_review']['input_partial_count']);
        self::assertSame(0, $summary['effect_review']['input_missing_count']);
        self::assertSame('明日输入 可用 1 / 待补 0 / 缺失 0', $summary['effect_review']['input_display']);
        self::assertTrue($summary['effect_review']['next_day_input_ready']);
        self::assertSame('ready', $summary['effect_review']['inputs'][0]['input_status']);
        self::assertSame('hotel', $summary['effect_review']['inputs'][0]['scope']);
        self::assertSame('operation_execution_tasks.result_status/result_summary + operation_execution_evidence', $summary['effect_review']['inputs'][0]['date_basis']);
        self::assertSame('¥180.50', $summary['effect_review']['inputs'][0]['roi_display']);
        self::assertSame('manual_roi_evidence', $summary['effect_review']['inputs'][0]['latest_evidence_type']);
        self::assertSame('2026-06-26 09:00:00', $summary['effect_review']['inputs'][0]['latest_evidence_at']);
        self::assertTrue($summary['effect_review']['inputs'][0]['has_revenue_evidence']);
        self::assertFalse($summary['effect_review']['inputs'][0]['has_cost_evidence']);
        self::assertTrue($summary['effect_review']['inputs'][0]['latest_evidence_has_attachment']);
        self::assertTrue($summary['effect_review']['inputs'][0]['evidence_ready_for_next_day']);
        self::assertStringContainsString('最新证据 manual_roi_evidence', $summary['effect_review']['inputs'][0]['evidence_summary']);
        self::assertStringContainsString('收入已具备', $summary['effect_review']['inputs'][0]['evidence_summary']);
        self::assertStringContainsString('可作明日输入', $summary['effect_review']['inputs'][0]['evidence_summary']);
        self::assertSame('ADR lifted after price adjustment', $summary['effect_review']['inputs'][0]['review_summary']);
        self::assertFalse($summary['effect_review']['inputs'][0]['auto_write_ota']);
        self::assertSame(111, $summary['recent_items'][0]['id']);
    }

    public function testExecutionSummaryPrioritizesEffectReviewInputsWithDataGaps(): void
    {
        $summary = (new RevenueAiOverviewService())->buildExecutionSummaryFromFlow([
            'data_status' => 'ok',
            'list' => [
                $this->executionFlowItem([
                    'id' => 201,
                    'stage' => 'reviewed',
                    'approval_status' => 'approved',
                    'execution_status' => 'executed',
                    'evidence_count' => 1,
                    'review_status' => 'success',
                    'roi_status' => 'ready',
                    'roi_value' => 120.0,
                    'roi_unit' => 'amount',
                    'before_revenue' => 1000.0,
                    'after_revenue' => 1120.0,
                    'incremental_revenue' => 120.0,
                    'cost' => 0.0,
                    'profit' => 120.0,
                    'formula' => 'after_revenue - before_revenue',
                ]),
                $this->executionFlowItem([
                    'id' => 202,
                    'stage' => 'review',
                    'approval_status' => 'approved',
                    'execution_status' => 'executed',
                    'evidence_count' => 1,
                    'review_status' => 'observing',
                    'roi_status' => 'data_gap',
                ]),
                $this->executionFlowItem([
                    'id' => 203,
                    'stage' => 'evidence',
                    'approval_status' => 'approved',
                    'execution_status' => 'executed',
                    'evidence_count' => 0,
                    'review_status' => 'observing',
                    'roi_status' => 'data_gap',
                ]),
            ],
        ], '2026-06-25', 7);

        self::assertSame(3, $summary['effect_review']['input_total_count']);
        self::assertSame(1, $summary['effect_review']['input_ready_count']);
        self::assertSame(1, $summary['effect_review']['input_partial_count']);
        self::assertSame(1, $summary['effect_review']['input_missing_count']);
        self::assertSame('明日输入 可用 1 / 待补 1 / 缺失 1', $summary['effect_review']['input_display']);
        self::assertSame(202, $summary['effect_review']['inputs'][0]['id']);
        self::assertSame('operation_roi_missing', $summary['effect_review']['inputs'][0]['input_reason']);
        self::assertSame('record_roi_evidence', $summary['effect_review']['inputs'][0]['input_action_key']);
        self::assertSame(203, $summary['effect_review']['inputs'][1]['id']);
        self::assertSame('operation_execution_evidence_needed', $summary['effect_review']['inputs'][1]['input_reason']);
        self::assertSame('record_execution_evidence', $summary['effect_review']['inputs'][1]['input_action_key']);
        self::assertSame('补执行证据', $summary['effect_review']['inputs'][1]['input_action_label']);
        self::assertSame(201, $summary['effect_review']['inputs'][2]['id']);
        self::assertSame('ready', $summary['effect_review']['inputs'][2]['input_status']);
        self::assertSame('use_next_day_input', $summary['effect_review']['inputs'][2]['input_action_key']);
        self::assertSame('可作明日输入', $summary['effect_review']['inputs'][2]['input_action_label']);
        self::assertSame('将 ROI/增量收入证据作为明日调价判断输入。', $summary['effect_review']['inputs'][2]['input_next_action']);
    }

    public function testExecutionSummaryExposesMissingExecutionTablesWithoutSuccessFallback(): void
    {
        $summary = (new RevenueAiOverviewService())->buildExecutionSummaryFromFlow([
            'data_status' => '待接入真实数据',
            'data_gaps' => [
                ['code' => 'operation_execution_intents_missing', 'message' => 'execution intent table missing'],
            ],
            'list' => [],
        ], '2026-06-25', 7);

        self::assertSame('missing', $summary['status']);
        self::assertSame('operation_execution_intents_missing', $summary['reason']);
        self::assertSame('--', $summary['display']);
        self::assertSame('ops-track', $summary['data_gaps'][0]['target_page']);
        self::assertStringContainsString('执行意图表', $summary['data_gaps'][0]['display_reason']);
        self::assertTrue($summary['read_only']);
        self::assertFalse($summary['auto_write_ota']);
    }

    public function testOverviewExposesExecutionSummaryAsReadonlyState(): void
    {
        $service = new RevenueAiOverviewService();
        $executionSummary = $service->buildExecutionSummaryFromFlow([
            'data_status' => 'ok',
            'list' => [
                $this->executionFlowItem([
                    'id' => 121,
                    'stage' => 'execution',
                    'approval_status' => 'approved',
                    'execution_status' => 'pending_execute',
                ]),
            ],
        ], '2026-06-25', 7);

        $overview = $service->buildOverviewFromDataset(
            $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)]),
            ['ctrip' => $this->dataset([$this->dailyFact('ctrip', 1200, 6, 10)])],
            ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-06-25 08:00:00']],
            ['business_date' => '2026-06-25', 'hotel_id' => 7, 'execution_summary' => $executionSummary]
        );

        self::assertSame('in_progress', $overview['execution_summary']['status']);
        self::assertSame('operation_execution_in_progress', $overview['execution_summary']['reason']);
        self::assertSame(1, $overview['execution_summary']['total_count']);
        self::assertTrue($overview['execution_summary']['read_only']);
        self::assertFalse($overview['execution_summary']['auto_write_ota']);
    }

    /**
     * @param array<int, array<string, mixed>> $dailyFacts
     * @return array<string, mixed>
     */
    private function dataset(array $dailyFacts): array
    {
        $platforms = [];
        foreach ($dailyFacts as $fact) {
            $platform = (string)($fact['platform_key'] ?? '');
            if ($platform !== '') {
                $platforms[$platform] = [
                    'platform_key' => $platform,
                    'platform_name' => $platform,
                ];
            }
        }
        return [
            'status' => $dailyFacts ? 'ready' : 'empty',
            'dim_hotel' => [['hotel_key' => 'system:7', 'system_hotel_id' => 7, 'hotel_name' => 'Hotel Alpha']],
            'dim_platform' => array_values($platforms),
            'fact_ota_daily' => $dailyFacts,
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_comment' => [],
            'data_quality' => [
                'input_rows' => count($dailyFacts),
                'accepted_rows' => count($dailyFacts),
                'rejected_rows' => [],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<string, mixed>
     */
    private function findIssue(array $issues, string $reason): array
    {
        foreach ($issues as $issue) {
            if (($issue['reason'] ?? '') === $reason) {
                return $issue;
            }
        }
        self::fail('Issue reason not found: ' . $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyFact(string $platform, float $roomRevenue, float $roomNights, ?float $availableRoomNights, array $overrides = []): array
    {
        return array_merge([
            'date_key' => '2026-06-25',
            'hotel_key' => 'system:7',
            'platform_key' => $platform,
            'data_type' => 'business',
            'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact',
            'revenue' => $roomRevenue,
            'gross_revenue' => $roomRevenue,
            'room_revenue' => $roomRevenue,
            'net_revenue' => $roomRevenue,
            'commission_amount' => 0.0,
            'commission_rate' => 0.0,
            'room_nights' => $roomNights,
            'available_room_nights' => $availableRoomNights,
            'occupied_room_nights' => $roomNights > 0 ? $roomNights : null,
            'order_count' => $roomNights > 0 ? (int)$roomNights : 0,
            'adr' => $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
            'revpar' => $availableRoomNights !== null && $availableRoomNights > 0 ? round($roomRevenue / $availableRoomNights, 2) : null,
            'our_price' => $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
            'competitor_price' => $roomNights > 0 ? round($roomRevenue / $roomNights + 10, 2) : null,
            'price_gap' => $roomNights > 0 ? -10.0 : null,
            'price_gap_rate' => $roomNights > 0 ? -4.76 : null,
            'cancel_order_num' => 0,
            'cancel_room_nights' => 0,
            'lead_time_days' => 1,
            'source_trace' => [
                'row_id' => $platform . '-1',
                'platform' => $platform,
                'data_type' => 'business',
                'saved_success' => true,
                'failure_reasons' => [],
                'updated_at' => '2026-06-25 08:00:00',
            ],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function executionFlowItem(array $overrides = []): array
    {
        $dateStart = (string)($overrides['date_start'] ?? '2026-06-25');
        $dateEnd = (string)($overrides['date_end'] ?? $dateStart);
        return [
            'id' => (int)($overrides['id'] ?? 100),
            'hotel_id' => 7,
            'stage' => (string)($overrides['stage'] ?? 'approval'),
            'recommendation' => [
                'source' => 'price_suggestion#1',
                'source_module' => 'price_suggestion',
                'source_record_id' => 1,
                'platform' => (string)($overrides['platform'] ?? 'ctrip'),
                'object_type' => 'price',
                'action_type' => 'price_adjust',
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'expected_metric' => 'orders',
                'expected_delta' => 0.0,
                'risk_level' => 'medium',
                'current_value' => [],
                'target_value' => [],
                'evidence' => [],
                'created_at' => '2026-06-25 09:00:00',
            ],
            'approval' => [
                'status' => (string)($overrides['approval_status'] ?? 'pending_approval'),
                'approved_by' => 0,
                'approved_at' => '',
                'remark' => '',
                'blocked_reason' => '',
            ],
            'execution' => [
                'task_id' => 201,
                'mode' => 'manual',
                'status' => (string)($overrides['execution_status'] ?? 'pending_create'),
                'operator_id' => 0,
                'executed_at' => '',
                'blocked_reason' => '',
                'target_value' => [],
                'current_value' => [],
            ],
            'evidence' => [
                'count' => (int)($overrides['evidence_count'] ?? 0),
                'latest' => is_array($overrides['latest_evidence'] ?? null) ? $overrides['latest_evidence'] : [],
            ],
            'review' => [
                'status' => (string)($overrides['review_status'] ?? 'observing'),
                'summary' => (string)($overrides['review_summary'] ?? ''),
                'action_track_id' => 0,
            ],
            'roi' => [
                'status' => (string)($overrides['roi_status'] ?? 'data_gap'),
                'value' => $overrides['roi_value'] ?? null,
                'unit' => (string)($overrides['roi_unit'] ?? ''),
                'before_revenue' => $overrides['before_revenue'] ?? null,
                'after_revenue' => $overrides['after_revenue'] ?? null,
                'incremental_revenue' => $overrides['incremental_revenue'] ?? null,
                'cost' => $overrides['cost'] ?? null,
                'profit' => $overrides['profit'] ?? null,
                'formula' => (string)($overrides['formula'] ?? ''),
            ],
            'next_action' => [
                'key' => 'review_effect',
                'label' => (string)($overrides['next_action_label'] ?? '触发效果复盘'),
                'priority' => 'medium',
                'target_id' => 201,
            ],
        ];
    }
}
