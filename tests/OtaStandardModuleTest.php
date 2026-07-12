<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaInsightAnalysisService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class OtaStandardModuleTest extends TestCase
{
    public function testEtlBuildsStarSchemaFromOnlineDailyRows(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());

        self::assertSame('ready', $dataset['status']);
        self::assertCount(2, $dataset['dim_hotel']);
        self::assertCount(2, $dataset['dim_platform']);
        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertCount(1, $dataset['fact_ota_comment']);

        self::assertSame('system:7', $dataset['fact_ota_daily'][0]['hotel_key']);
        self::assertSame('ctrip', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertSame(1200.0, $dataset['fact_ota_daily'][0]['revenue']);
        self::assertSame(6.0, $dataset['fact_ota_daily'][0]['room_nights']);
        self::assertSame(4, $dataset['fact_ota_daily'][0]['order_count']);
        self::assertSame(200.0, $dataset['fact_ota_daily'][0]['adr']);
        self::assertSame('ota_channel', $dataset['fact_ota_daily'][0]['metric_scope']);
        self::assertSame(10.0, $dataset['fact_ota_daily'][0]['available_room_nights']);
        self::assertSame(6.0, $dataset['fact_ota_daily'][0]['occupied_room_nights']);
        self::assertSame(60.0, $dataset['fact_ota_daily'][0]['occ']);
        self::assertSame(120.0, $dataset['fact_ota_daily'][0]['revpar']);
        self::assertSame(180.0, $dataset['fact_ota_daily'][0]['commission_amount']);
        self::assertSame(15.0, $dataset['fact_ota_daily'][0]['commission_rate']);
        self::assertSame(1020.0, $dataset['fact_ota_daily'][0]['net_revenue']);
        self::assertSame('derived_from_commission_amount', $dataset['fact_ota_daily'][0]['net_revenue_basis']);
        self::assertSame('derived_from_commission_rate', $dataset['fact_ota_daily'][0]['commission_amount_basis']);
        self::assertSame(102.0, $dataset['fact_ota_daily'][0]['net_revpar']);
        self::assertSame(8, $dataset['fact_ota_daily'][0]['lead_time_days']);

        self::assertSame(20.0, $dataset['fact_ota_traffic'][0]['flow_rate']);
        self::assertSame(33.33, $dataset['fact_ota_traffic'][0]['submit_rate']);
        self::assertSame('system:8', $dataset['fact_ota_comment'][0]['hotel_key']);
        self::assertSame('meituan', $dataset['fact_ota_comment'][0]['platform_key']);
        self::assertSame('review:meituan', $dataset['fact_ota_comment'][0]['dimension']);
        self::assertSame(3.0, $dataset['fact_ota_comment'][0]['comment_score']);
        self::assertSame(1.0, $dataset['fact_ota_comment'][0]['comment_count']);
        self::assertSame(1.0, $dataset['fact_ota_comment'][0]['bad_review_count']);
        self::assertArrayNotHasKey('content', $dataset['fact_ota_comment'][0]['raw_data']);
        self::assertSame([], $dataset['data_quality']['rejected_rows']);
    }

    public function testRevenueMetricsUseStandardFactsWithoutInventingMissingCancellationData(): void
    {
        $etl = new OtaStandardEtlService();
        $dataset = $etl->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(1200.0, $metrics['totals']['revenue']);
        self::assertSame(1200.0, $metrics['totals']['room_revenue']);
        self::assertSame(1020.0, $metrics['totals']['net_revenue']);
        self::assertSame(180.0, $metrics['totals']['commission_amount']);
        self::assertSame(15.0, $metrics['totals']['commission_rate']);
        self::assertSame(6.0, $metrics['totals']['room_nights']);
        self::assertSame(10.0, $metrics['totals']['available_room_nights']);
        self::assertSame(6.0, $metrics['totals']['occupied_room_nights']);
        self::assertSame(4, $metrics['totals']['order_count']);
        self::assertSame(200.0, $metrics['totals']['adr']);
        self::assertSame(60.0, $metrics['totals']['occ']);
        self::assertSame(120.0, $metrics['totals']['revpar']);
        self::assertSame(102.0, $metrics['totals']['net_revpar']);
        self::assertSame(8.0, $metrics['totals']['avg_lead_time_days']);
        self::assertSame(25.0, $metrics['totals']['cancellation_rate']);
        self::assertSame(16.67, $metrics['totals']['room_night_cancellation_rate']);
        self::assertSame(20.0, $metrics['traffic']['avg_flow_rate']);
        self::assertSame(33.33, $metrics['traffic']['avg_submit_rate']);
        self::assertSame(-20.0, $metrics['competitor_price']['avg_price_gap']);
        self::assertSame(-9.09, $metrics['competitor_price']['avg_price_gap_rate']);
        self::assertSame('fact_ota_daily', $metrics['fact_table']['name']);
        self::assertArrayHasKey('revpar', $metrics['metric_definitions']['metrics']);
        self::assertSame(100.0, $metrics['channel_contribution'][0]['contribution_rate']);
        self::assertSame(100.0, $metrics['channel_contribution'][0]['net_contribution_rate']);

        $missingCancel = (new OtaRevenueMetricService())->summarizeDataset($etl->buildDatasetFromRows([
            array_replace($this->sampleRows()[0], [
                'raw_data' => json_encode(['our_price' => 200, 'competitor_price' => 220], JSON_UNESCAPED_UNICODE),
            ]),
        ]));

        self::assertNull($missingCancel['totals']['cancellation_rate']);
        self::assertContains('cancellation_fields_missing', array_column($missingCancel['data_gaps'], 'code'));

        $partialCancel = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                ['platform_key' => 'ctrip', 'hotel_key' => 'system:7', 'revenue' => 100.0, 'room_nights' => 1.0, 'order_count' => 10, 'cancel_order_num' => 2],
                ['platform_key' => 'ctrip', 'hotel_key' => 'system:7', 'revenue' => 900.0, 'room_nights' => 9.0, 'order_count' => 90, 'cancel_order_num' => null],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(20.0, $partialCancel['totals']['cancellation_rate']);
        self::assertContains('cancellation_fields_partial', array_column($partialCancel['data_gaps'], 'code'));
    }

    public function testP1RevenueClosureUsesVerifiedOtaMetricsOnly(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'price_gap' => -10.0,
                'price_gap_rate' => -4.76,
                'source_trace' => $this->trace(9001, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'resource' => 'traffic',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(9002, 'ctrip', 'traffic', '2026-06-25'),
            ]],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_comment' => [],
        ]);

        $closure = $metrics['p1_revenue_closure'];

        self::assertSame('ready', $closure['status']);
        self::assertSame('ota_channel', $closure['scope']);
        self::assertTrue($closure['calculation_allowed']);
        self::assertSame(1200.0, $closure['sections']['revenue']['value']);
        self::assertSame(4.0, $closure['sections']['orders']['value']);
        self::assertSame(6.0, $closure['sections']['room_nights']['value']);
        self::assertSame(200.0, $closure['sections']['adr_conversion']['metrics']['adr']['value']);
        self::assertSame(20.0, $closure['sections']['adr_conversion']['metrics']['flow_rate']['value']);
        self::assertSame(33.33, $closure['sections']['adr_conversion']['metrics']['submit_rate']['value']);
        self::assertSame('ok', $closure['missing_items']['status']);
        self::assertSame('ok', $closure['anomaly_judgment']['status']);
        self::assertFalse($closure['whole_hotel_guard']['allowed']);
        self::assertSame('whole_hotel_scope_not_proved', $closure['whole_hotel_guard']['reason']);
    }

    public function testP1RevenueClosureBlocksValuesWhenCredibilityGateBlocksDataset(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'failed',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'source_trace' => $this->trace(9011, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(9012, 'ctrip', 'traffic', '2026-06-25'),
            ]],
        ]);

        $closure = $metrics['p1_revenue_closure'];

        self::assertSame('blocked', $closure['status']);
        self::assertFalse($closure['calculation_allowed']);
        self::assertNull($closure['sections']['revenue']['value']);
        self::assertSame('blocked', $closure['sections']['revenue']['status']);
        self::assertContains('blocked_by_data_credibility', $closure['sections']['revenue']['failure_reasons']);
        self::assertContains('ota_dataset_failed', array_column($closure['anomaly_judgment']['items'], 'code'));
    }

    public function testP1RevenueClosureExplainsMissingAdrAndConversionInputs(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 0.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 0.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'source_trace' => $this->trace(9021, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [],
        ]);

        $closure = $metrics['p1_revenue_closure'];
        $missingCodes = array_column($closure['missing_items']['items'], 'code');

        self::assertSame('blocked', $closure['status']);
        self::assertNull($closure['sections']['adr_conversion']['metrics']['adr']['value']);
        self::assertContains('totals.adr:adr_denominator_zero', $missingCodes);
        self::assertContains('traffic.avg_flow_rate:source_rows_missing', $missingCodes);
        self::assertContains('traffic.avg_submit_rate:source_rows_missing', $missingCodes);
    }

    public function testAdvertisingAndQualityFactsDoNotPolluteRevenueMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 31,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 32,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:05:00',
                'amount' => 256.75,
                'quantity' => 23,
                'book_order_num' => 16,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'flow_rate' => 8.5,
                'data_value' => 7.35,
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 33,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'quality',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:10:00',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_advertising']);
        self::assertCount(1, $dataset['fact_ota_quality']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(1200.0, $metrics['totals']['revenue']);
        self::assertSame(6.0, $metrics['totals']['room_nights']);
        self::assertSame(4, $metrics['totals']['order_count']);
        self::assertSame(256.75, $metrics['advertising']['spend']);
        self::assertSame(1888.0, $metrics['advertising']['order_amount']);
        self::assertSame(7.35, $metrics['advertising']['roas']);
        self::assertSame(10000, $metrics['advertising']['impressions']);
        self::assertSame(320, $metrics['advertising']['clicks']);
        self::assertSame(88.6, $metrics['quality']['avg_psi_score']);
        self::assertSame(92.5, $metrics['quality']['avg_service_score']);
    }

    public function testRevenueMetricsExposeTraceableChannelMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 71,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'source_trace_id' => 'trace-business-71',
                'update_time' => '2026-05-27 10:00:00',
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 72,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-27',
                'list_exposure' => 1000,
                'detail_exposure' => 185,
                'flow_rate' => 18.5,
                'order_filling_num' => 40,
                'order_submit_num' => 9,
                'source_trace_id' => 'trace-traffic-72',
                'update_time' => '2026-05-27 10:05:00',
                'raw_data' => '{}',
            ],
            [
                'id' => 73,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'amount' => 256.75,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'book_order_num' => 16,
                'data_value' => 7.35,
                'source_trace_id' => 'trace-ad-73',
                'update_time' => '2026-05-27 10:10:00',
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 74,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'search_keyword',
                'dimension' => 'family hotel',
                'data_date' => '2026-05-27',
                'list_exposure' => 300,
                'detail_exposure' => 45,
                'order_submit_num' => 3,
                'data_value' => 5,
                'source_trace_id' => 'trace-keyword-74',
                'update_time' => '2026-05-27 10:15:00',
                'raw_data' => json_encode(['keyword' => 'family hotel', 'rank' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 75,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:P_RZ:入住间夜',
                'data_date' => '2026-05-27',
                'data_value' => 3,
                'compare_type' => 'competitor',
                'source_trace_id' => 'trace-peer-rank-75',
                'update_time' => '2026-05-27 10:20:00',
                'raw_data' => json_encode(['rankType' => 'P_RZ', 'rank' => 3, 'percent' => 0.12], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 76,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'traffic_analysis',
                'dimension' => 'traffic_analysis:flow_conversion',
                'data_date' => '2026-05-27',
                'data_value' => 18.5,
                'list_exposure' => 800,
                'detail_exposure' => 160,
                'flow_rate' => 20,
                'order_filling_num' => 40,
                'order_submit_num' => 8,
                'source_trace_id' => 'trace-traffic-analysis-76',
                'update_time' => '2026-05-27 10:25:00',
                'raw_data' => json_encode(['analysis_type' => 'conversion_funnel', 'flowRate' => 20], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 77,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'traffic_forecast',
                'dimension' => 'traffic_forecast:detail_uv',
                'data_date' => '2026-05-27',
                'data_value' => 260,
                'compare_type' => 'forecast',
                'source_trace_id' => 'trace-traffic-forecast-77',
                'update_time' => '2026-05-27 10:30:00',
                'raw_data' => json_encode(['forecastType' => 'detail_uv', 'current' => 260, 'peerAvg' => 310], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertCount(1, $dataset['fact_ota_search_keyword']);
        self::assertCount(1, $dataset['fact_ota_peer_rank']);
        self::assertCount(1, $dataset['fact_ota_traffic_analysis']);
        self::assertCount(1, $dataset['fact_ota_traffic_forecast']);
        self::assertSame('peer_rank:P_RZ:入住间夜', $dataset['fact_ota_peer_rank'][0]['dimension']);
        self::assertSame(3.0, $dataset['fact_ota_peer_rank'][0]['rank']);
        self::assertSame(12.0, $dataset['fact_ota_peer_rank'][0]['rank_percent']);
        self::assertSame('traffic_analysis:flow_conversion', $dataset['fact_ota_traffic_analysis'][0]['dimension']);
        self::assertSame(20.0, $dataset['fact_ota_traffic_analysis'][0]['submit_rate']);
        self::assertSame('traffic_forecast:detail_uv', $dataset['fact_ota_traffic_forecast'][0]['dimension']);
        self::assertSame(310.0, $dataset['fact_ota_traffic_forecast'][0]['peer_avg']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertArrayHasKey('channel_metrics', $metrics);
        $trafficFlow = $this->channelMetric($metrics['channel_metrics'], 'traffic', 'flow_rate');
        self::assertSame(['scope', 'platform', 'resource', 'metric_key', 'value', 'denominator', 'data_status', 'source_trace_id', 'updated_at'], array_keys($trafficFlow));
        self::assertSame('ota_channel', $trafficFlow['scope']);
        self::assertSame('ctrip', $trafficFlow['platform']);
        self::assertSame(18.5, $trafficFlow['value']);
        self::assertSame(1000.0, $trafficFlow['denominator']);
        self::assertSame('ok', $trafficFlow['data_status']);
        self::assertSame('trace-traffic-72', $trafficFlow['source_trace_id']);
        self::assertSame('2026-05-27 10:05:00', $trafficFlow['updated_at']);

        $adSpend = $this->channelMetric($metrics['channel_metrics'], 'advertising', 'amount');
        self::assertSame(256.75, $adSpend['value']);
        self::assertSame('trace-ad-73', $adSpend['source_trace_id']);

        $keywordRank = $this->channelMetric($metrics['channel_metrics'], 'search_keyword:family hotel', 'rank');
        self::assertSame(2.0, $keywordRank['value']);
        self::assertSame('trace-keyword-74', $keywordRank['source_trace_id']);

        $peerRank = $this->channelMetric($metrics['channel_metrics'], 'peer_rank:P_RZ:入住间夜', 'rank');
        self::assertSame(3.0, $peerRank['value']);
        self::assertSame('trace-peer-rank-75', $peerRank['source_trace_id']);

        $trafficAnalysis = $this->channelMetric($metrics['channel_metrics'], 'traffic_analysis:flow_conversion', 'order_submit_num');
        self::assertSame(8.0, $trafficAnalysis['value']);
        self::assertSame(40.0, $trafficAnalysis['denominator']);
        self::assertSame('trace-traffic-analysis-76', $trafficAnalysis['source_trace_id']);

        $trafficForecast = $this->channelMetric($metrics['channel_metrics'], 'traffic_forecast:detail_uv', 'forecast_value');
        self::assertSame(260.0, $trafficForecast['value']);
        self::assertSame('trace-traffic-forecast-77', $trafficForecast['source_trace_id']);
        self::assertArrayHasKey('peer_rank_signal', $metrics['metric_definitions']['metrics']);
        self::assertArrayHasKey('traffic_forecast_signal', $metrics['metric_definitions']['metrics']);
    }

    public function testInsightAnalysisIncludesAdvertisingEfficiencyAndServiceQualityModules(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 41,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 42,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:05:00',
                'amount' => 256.75,
                'quantity' => 23,
                'book_order_num' => 16,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'flow_rate' => 8.5,
                'data_value' => 7.35,
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 43,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'quality',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:10:00',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        $modules = array_column($analysis['modules'], null, 'key');

        self::assertArrayHasKey('advertising_efficiency', $modules);
        self::assertSame('available', $modules['advertising_efficiency']['status']);
        self::assertSame('P2', $modules['advertising_efficiency']['priority']);
        self::assertSame(256.75, $modules['advertising_efficiency']['metrics']['spend']);
        self::assertSame(7.35, $modules['advertising_efficiency']['metrics']['roas']);

        self::assertArrayHasKey('service_quality', $modules);
        self::assertSame('available', $modules['service_quality']['status']);
        self::assertSame('P2', $modules['service_quality']['priority']);
        self::assertSame(88.6, $modules['service_quality']['metrics']['avg_psi_score']);
        self::assertSame(92.5, $modules['service_quality']['metrics']['avg_service_score']);
    }

    public function testEtlRejectsInvalidPercentAndNegativeLeadTimeWithoutInventingMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 11,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-19',
                'amount' => 1000,
                'quantity' => 5,
                'book_order_num' => 3,
                'raw_data' => json_encode([
                    'available_rooms' => 10,
                    'commission_rate' => 120,
                    'cancel_rate' => -0.2,
                    'booking_date' => '2026-05-20',
                    'checkin_date' => '2026-05-19',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $fact = $dataset['fact_ota_daily'][0];

        self::assertNull($fact['commission_rate']);
        self::assertNull($fact['commission_amount']);
        self::assertNull($fact['net_revenue']);
        self::assertNull($fact['cancel_rate']);
        self::assertNull($fact['lead_time_days']);
    }

    public function testDirectNetRevenueDoesNotDependOnCommissionFields(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows([
                [
                    'id' => 12,
                    'system_hotel_id' => 7,
                    'hotel_id' => 'ctrip-7',
                    'hotel_name' => 'Hotel Alpha',
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'data_date' => '2026-05-20',
                    'update_time' => '2026-05-20 10:00:00',
                    'amount' => 1000,
                    'quantity' => 5,
                    'book_order_num' => 3,
                    'raw_data' => json_encode([
                        'net_revenue' => 880,
                        'available_rooms' => 10,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ])
        );

        self::assertSame(880.0, $metrics['totals']['net_revenue']);
        self::assertSame(88.0, $metrics['totals']['net_revpar']);
        self::assertSame([], $metrics['metric_trust']['totals.net_revenue']['failure_reasons']);
        self::assertSame([], $metrics['metric_trust']['totals.net_revpar']['failure_reasons']);
        self::assertContains('commission_fields_missing', array_column($metrics['data_gaps'], 'code'));
    }

    public function testRevparUsesOnlyRowsWithAlignedAvailableRoomNightRows(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 100.0,
                    'room_revenue' => 100.0,
                    'net_revenue' => 80.0,
                    'room_nights' => 1.0,
                    'available_room_nights' => 10.0,
                    'occupied_room_nights' => 5.0,
                    'order_count' => 1,
                    'source_trace' => $this->trace(101, 'ctrip', 'business', '2026-05-20'),
                ],
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 900.0,
                    'room_revenue' => 900.0,
                    'net_revenue' => 720.0,
                    'room_nights' => 9.0,
                    'available_room_nights' => null,
                    'occupied_room_nights' => null,
                    'order_count' => 9,
                    'source_trace' => $this->trace(102, 'ctrip', 'business', '2026-05-20'),
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(10.0, $metrics['totals']['revpar']);
        self::assertSame(8.0, $metrics['totals']['net_revpar']);
        self::assertSame(10.0, $metrics['by_platform'][0]['revpar']);
        self::assertSame(8.0, $metrics['by_platform'][0]['net_revpar']);
        self::assertContains('available_room_nights_partial', array_column($metrics['data_gaps'], 'code'));
        self::assertContains('available_room_nights_partial', $metrics['metric_trust']['totals.revpar']['failure_reasons']);
    }

    public function testCommissionRateUsesOnlyRowsWithCommissionFields(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 100.0,
                    'gross_revenue' => 100.0,
                    'room_revenue' => 100.0,
                    'commission_amount' => 10.0,
                    'room_nights' => 1.0,
                    'order_count' => 1,
                    'source_trace' => $this->trace(201, 'ctrip', 'business', '2026-05-21'),
                ],
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 900.0,
                    'gross_revenue' => 900.0,
                    'room_revenue' => 900.0,
                    'commission_amount' => null,
                    'room_nights' => 9.0,
                    'order_count' => 9,
                    'source_trace' => $this->trace(202, 'ctrip', 'business', '2026-05-21'),
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(10.0, $metrics['totals']['commission_rate']);
        self::assertContains('commission_fields_partial', array_column($metrics['data_gaps'], 'code'));
        self::assertContains('commission_fields_partial', $metrics['metric_trust']['totals.commission_rate']['failure_reasons']);
    }

    public function testRevenueMetricsExposeTraceableTrustMetadataForEachMetric(): void
    {
        $rows = $this->sampleRows();
        $rows[0]['update_time'] = '2026-05-18 12:30:00';
        $rows[0]['validation_status'] = 'normal';
        $rows[0]['validation_flags'] = '[]';
        $rows[1]['update_time'] = '2026-05-18 12:35:00';
        $rows[1]['validation_status'] = 'normal';
        $rows[1]['validation_flags'] = '[]';
        $rows[2]['update_time'] = '2026-05-18 12:40:00';
        $rows[2]['validation_status'] = 'normal';
        $rows[2]['validation_flags'] = '[]';

        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows($rows)
        );

        self::assertArrayHasKey('metric_trust', $metrics);
        foreach ([
            'totals.revenue',
            'totals.room_nights',
            'totals.order_count',
            'totals.adr',
            'totals.occ',
            'totals.revpar',
            'totals.commission_amount',
            'totals.net_revenue',
            'totals.net_revpar',
            'totals.avg_lead_time_days',
            'totals.cancellation_rate',
            'totals.room_night_cancellation_rate',
            'totals.review_count',
            'totals.avg_comment_score',
            'traffic.avg_flow_rate',
            'traffic.avg_submit_rate',
            'competitor_price.avg_price_gap',
        ] as $metricKey) {
            self::assertArrayHasKey($metricKey, $metrics['metric_trust']);
            self::assertArrayHasKey('source', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('caliber', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('updated_at', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('failure_reasons', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('saved_success', $metrics['metric_trust'][$metricKey]);
        }

        self::assertSame('online_daily_data', $metrics['metric_trust']['totals.revenue']['source']['table']);
        self::assertSame([1], $metrics['metric_trust']['totals.revenue']['source']['row_ids']);
        self::assertSame(['ctrip'], $metrics['metric_trust']['totals.revenue']['source']['platforms']);
        self::assertSame(['business'], $metrics['metric_trust']['totals.revenue']['source']['data_types']);
        self::assertSame('sum(fact_ota_daily.revenue)', $metrics['metric_trust']['totals.revenue']['caliber']);
        self::assertSame('2026-05-18 12:30:00', $metrics['metric_trust']['totals.revenue']['updated_at']);
        self::assertTrue($metrics['metric_trust']['totals.revenue']['saved_success']);
        self::assertSame([], $metrics['metric_trust']['totals.revenue']['failure_reasons']);

        self::assertSame('sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)', $metrics['metric_trust']['totals.adr']['caliber']);
        self::assertSame('sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)', $metrics['metric_trust']['totals.revpar']['caliber']);
        self::assertSame('avg(fact_ota_daily.lead_time_days)', $metrics['metric_trust']['totals.avg_lead_time_days']['caliber']);
        self::assertSame([2], $metrics['metric_trust']['traffic.avg_flow_rate']['source']['row_ids']);
        self::assertSame('2026-05-18 12:35:00', $metrics['metric_trust']['traffic.avg_flow_rate']['updated_at']);

        $missingCancel = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows([
                array_replace($rows[0], [
                    'raw_data' => json_encode(['our_price' => 200, 'competitor_price' => 220], JSON_UNESCAPED_UNICODE),
                ]),
            ])
        );

        self::assertFalse($missingCancel['metric_trust']['totals.cancellation_rate']['saved_success']);
        self::assertContains('cancellation_fields_missing', $missingCancel['metric_trust']['totals.cancellation_rate']['failure_reasons']);
    }

    public function testEtlUsesPlatformFallbackAndRecursivelySanitizesRawData(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 10,
                'system_hotel_id' => 9,
                'hotel_id' => 'poi-9',
                'hotel_name' => 'Hotel Gamma',
                'source' => '',
                'platform' => 'Meituan',
                'data_type' => 'business',
                'data_date' => '2026-05-19',
                'amount' => 300,
                'quantity' => 2,
                'book_order_num' => 1,
                'raw_data' => json_encode([
                    'headers' => [
                        'Cookie' => 'secret-cookie',
                        'nested' => ['accessToken' => 'secret-token'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame('ready', $dataset['status']);
        self::assertSame('meituan', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertArrayNotHasKey('Cookie', $dataset['fact_ota_daily'][0]['raw_data']['headers']);
        self::assertArrayNotHasKey('accessToken', $dataset['fact_ota_daily'][0]['raw_data']['headers']['nested']);
    }

    public function testEtlRedactsLegacyOrderRawDataBeforeFactsExposeIt(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 12,
                'system_hotel_id' => 9,
                'hotel_id' => 'poi-9',
                'hotel_name' => 'Hotel Gamma',
                'source' => 'meituan',
                'data_type' => 'orders',
                'data_date' => '2026-05-19',
                'amount' => 688,
                'quantity' => 2,
                'raw_data' => json_encode([
                    'orderList' => [
                        [
                            'orderId' => 'MT-ORDER-LEGACY-001',
                            'guestName' => 'Legacy Guest',
                            'phone' => '13700001111',
                            'idCardNo' => 'IDCARD-LEGACY-001',
                            'customerRemark' => 'late arrival needs call',
                            'amount' => 688,
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $raw = $dataset['fact_ota_daily'][0]['raw_data'];
        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString('MT-ORDER-LEGACY-001', (string)$encoded);
        self::assertStringNotContainsString('Legacy Guest', (string)$encoded);
        self::assertStringNotContainsString('13700001111', (string)$encoded);
        self::assertStringNotContainsString('IDCARD-LEGACY-001', (string)$encoded);
        self::assertStringNotContainsString('late arrival', (string)$encoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($raw['orderList'][0]['order_id_hash'] ?? ''));
        self::assertSame('L***', $raw['orderList'][0]['guest_name_masked'] ?? null);
        self::assertSame('*******1111', $raw['orderList'][0]['phone_masked'] ?? null);
        self::assertSame(688, $raw['orderList'][0]['amount'] ?? null);
        self::assertSame('order', $dataset['fact_ota_daily'][0]['source_trace']['data_type'] ?? null);
    }

    public function testEtlRejectsInvalidDateFiltersInsteadOfWideningScope(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'filterDateValue');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid start_date');

        $method->invoke(new OtaStandardEtlService(), '2026/05/19', 'start_date');
    }

    public function testEtlSourceFilterValuesIncludeProjectAliases(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'sourceFilterValues');
        $method->setAccessible(true);

        $values = $method->invoke(new OtaStandardEtlService(), 'meituan');

        self::assertContains('meituan', $values);
        self::assertContains('meituan_rank', $values);
        self::assertContains('meituan_business', $values);
        self::assertContains('meituan_browser_profile', $values);
    }

    public function testInsightAnalysisPrioritizesAdrCancellationTrafficAndCompetitorPriceWithoutLstm(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        self::assertSame('deterministic_rules', $analysis['model_policy']['model_type']);
        self::assertContains('LSTM', $analysis['model_policy']['excluded_models']);

        $keys = array_column($analysis['modules'], 'key');
        self::assertSame(['adr', 'revpar', 'net_revpar', 'cancellation_rate', 'traffic_conversion', 'competitor_price_gap'], $keys);
        self::assertSame('available', $analysis['modules'][0]['status']);
        self::assertSame('available', $analysis['modules'][1]['status']);
        self::assertSame('available', $analysis['modules'][2]['status']);
        self::assertSame('available', $analysis['modules'][3]['status']);
        self::assertSame('watch', $analysis['modules'][5]['status']);
    }

    public function testInsightAnalysisDoesNotPromoteBlockedCredibilityGateToReady(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'failed',
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
                'room_revenue' => 1200.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'order_count' => 4,
                'source_trace' => [
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ]],
        ]);

        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        self::assertSame('blocked_by_data_credibility', $analysis['status']);
        self::assertSame('blocked', $analysis['credibility_gate']['status']);
        self::assertContains('ota_dataset_failed', $analysis['credibility_gate']['reason_codes']);
        self::assertTrue($analysis['human_review_required']);
        foreach ($analysis['modules'] as $module) {
            self::assertSame('blocked_by_data_credibility', $module['status']);
            self::assertFalse($module['actionable']);
            self::assertContains('ota_dataset_failed', $module['blocking_reason_codes']);
        }
    }

    public function testMeituanAdvertisingKeepsExposureBookingsAndRoasInTheirOwnSemantics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8801,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'advertising',
            'data_date' => '2026-07-11',
            'dimension' => 'ads',
            'amount' => 300,
            'quantity' => 9,
            'book_order_num' => 9,
            'list_exposure' => 5000,
            'detail_exposure' => 200,
            'flow_rate' => 4.0,
            'data_value' => 5000,
            'raw_data' => json_encode([
                'spend' => 300,
                'order_amount' => 1800,
                'book_order_num' => 9,
                'roas' => 6.0,
                'exposure_count' => 5000,
                'click_count' => 200,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertCount(1, $dataset['fact_ota_advertising']);
        $fact = $dataset['fact_ota_advertising'][0];

        self::assertSame(300.0, $fact['spend']);
        self::assertSame(1800.0, $fact['order_amount']);
        self::assertSame(9, $fact['bookings']);
        self::assertNull($fact['room_nights']);
        self::assertSame(5000, $fact['impressions']);
        self::assertSame(200, $fact['clicks']);
        self::assertSame(4.0, $fact['ctr']);
        self::assertSame(4.5, $fact['cvr']);
        self::assertSame(6.0, $fact['roas']);
    }

    public function testLegacyMeituanAdvertisingRecomputesPercentScaledRoasFromAmounts(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8802,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'advertising',
            'data_date' => '2026-07-11',
            'dimension' => 'ads',
            'amount' => 100,
            'book_order_num' => 4,
            'list_exposure' => 1000,
            'detail_exposure' => 100,
            'data_value' => 1000,
            'raw_data' => json_encode([
                'spend' => 100,
                'order_amount' => 80,
                'book_order_num' => 4,
                'roas' => 80,
                'exposure_count' => 1000,
                'click_count' => 100,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_advertising'][0];
        self::assertSame(0.8, $fact['roas']);
        self::assertSame(4.0, $fact['cvr']);
        self::assertNull($fact['room_nights']);
    }

    public function testOrderMissingMetricsRemainNullThroughEtl(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8803,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'order',
            'data_date' => '2026-07-11',
            'dimension' => 'order:confirmed:hash',
            'amount' => 500,
            'quantity' => null,
            'book_order_num' => null,
            'data_value' => null,
            'raw_data' => json_encode(['total_amount' => 500], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_daily'][0];
        self::assertNull($fact['room_nights']);
        self::assertNull($fact['order_count']);
        self::assertNull($fact['data_value']);
        self::assertNull($fact['adr']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['room_nights']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertNull($metrics['totals']['adr']);
    }

    public function testReviewMissingCountsDoNotBecomeOneInRevenueMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8804,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'review',
            'data_date' => '2026-07-11',
            'dimension' => 'review:meituan',
            'comment_score' => 3.8,
            'quantity' => null,
            'data_value' => null,
            'raw_data' => json_encode(['comment_score' => 3.8], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_comment'][0];
        self::assertNull($fact['comment_count']);
        self::assertNull($fact['bad_review_count']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['review_count']);
        self::assertSame(3.8, $metrics['totals']['avg_comment_score']);
    }

    public function testEtlUsesLatestRealtimeSnapshotWhenNoFinalRowExists(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-12',
            'dimension' => 'traffic',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 1, 'snapshot_time' => '2026-07-12 09:00:00', 'snapshot_bucket' => '2026-07-12 09:00', 'list_exposure' => 100],
            $base + ['id' => 2, 'snapshot_time' => '2026-07-12 10:00:00', 'snapshot_bucket' => '2026-07-12 10:00', 'list_exposure' => 200],
        ]);

        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertSame(200, $dataset['fact_ota_traffic'][0]['list_exposure']);
        self::assertSame(2, $dataset['data_quality']['source_input_rows']);
        self::assertSame(1, $dataset['data_quality']['input_rows']);
        self::assertSame(1, $dataset['data_quality']['superseded_period_rows']);
    }

    public function testEtlUsesFinalHistoricalRowInsteadOfRealtimeSnapshotsForSameGrain(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-11',
            'dimension' => 'traffic',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 3, 'data_period' => 'realtime_snapshot', 'is_final' => 0, 'snapshot_time' => '2026-07-11 22:00:00', 'list_exposure' => 240],
            $base + ['id' => 4, 'data_period' => 'historical_daily', 'is_final' => 1, 'snapshot_time' => '2026-07-12 01:00:00', 'list_exposure' => 180],
        ]);

        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertSame(180, $dataset['fact_ota_traffic'][0]['list_exposure']);
    }

    public function testEtlCanonicalizesCumulativeOrderAndReviewSnapshotsButKeepsStableEvents(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
        ];

        $cumulativeOrders = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 10,
                'data_type' => 'order',
                'dimension' => 'order:summary',
                'snapshot_time' => '2026-07-12 09:00:00',
                'amount' => 100,
                'book_order_num' => 1,
                'raw_data' => '{}',
            ],
            $base + [
                'id' => 11,
                'data_type' => 'order',
                'dimension' => 'order:summary',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 250,
                'book_order_num' => 2,
                'raw_data' => '{}',
            ],
        ]);
        self::assertCount(1, $cumulativeOrders['fact_ota_daily']);
        self::assertSame(250.0, $cumulativeOrders['fact_ota_daily'][0]['revenue']);
        self::assertSame(1, $cumulativeOrders['data_quality']['superseded_period_rows']);

        $stableOrderEvents = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 12,
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 120,
                'raw_data' => json_encode(['order_id' => 'order-a'], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 13,
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 180,
                'raw_data' => json_encode(['order_id' => 'order-b'], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $stableOrderEvents['fact_ota_daily']);

        $cumulativeReviews = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 14,
                'data_type' => 'review',
                'dimension' => 'review:summary',
                'snapshot_time' => '2026-07-12 09:00:00',
                'quantity' => 20,
                'raw_data' => json_encode(['comment_count' => 20], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 15,
                'data_type' => 'review',
                'dimension' => 'review:summary',
                'snapshot_time' => '2026-07-12 10:00:00',
                'quantity' => 25,
                'raw_data' => json_encode(['comment_count' => 25], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(1, $cumulativeReviews['fact_ota_comment']);
        self::assertSame(25.0, $cumulativeReviews['fact_ota_comment'][0]['comment_count']);
    }

    public function testSnapshotCanonicalizationKeepsDistinctCampaignAndPeerIdentities(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => '2026-07-12 10:00:00',
            'is_final' => 0,
        ];

        $ads = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 20,
                'data_type' => 'advertising',
                'dimension' => 'ads',
                'amount' => 10,
                'raw_data' => json_encode(['campaignId' => 'campaign-a'], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 21,
                'data_type' => 'advertising',
                'dimension' => 'ads',
                'amount' => 20,
                'raw_data' => json_encode(['campaignId' => 'campaign-b'], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $ads['fact_ota_advertising']);
        self::assertSame(['campaign-a', 'campaign-b'], array_column($ads['fact_ota_advertising'], 'campaign_id'));

        $peerRanks = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 22,
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:traffic',
                'raw_data' => json_encode(['poiId' => 'peer-a', 'rank' => 1], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 23,
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:traffic',
                'raw_data' => json_encode(['poiId' => 'peer-b', 'rank' => 2], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $peerRanks['fact_ota_peer_rank']);
    }

    public function testUnknownPeriodsAreNotCollapsedAsSnapshots(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-12',
            'dimension' => 'traffic',
            'data_period' => 'manual_dom_csv',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 30, 'snapshot_time' => '2026-07-12 09:00:00', 'list_exposure' => 100],
            $base + ['id' => 31, 'snapshot_time' => '2026-07-12 10:00:00', 'list_exposure' => 200],
        ]);

        self::assertCount(2, $dataset['fact_ota_traffic']);
        self::assertSame(0, $dataset['data_quality']['superseded_period_rows']);
    }

    public function testMissingNumericFieldsStayNullInFactsAndAggregates(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 40, 'data_type' => 'business', 'dimension' => 'business'],
            $base + ['id' => 41, 'data_type' => 'traffic', 'dimension' => 'traffic'],
            $base + ['id' => 42, 'data_type' => 'advertising', 'dimension' => 'ads'],
        ]);

        $daily = $dataset['fact_ota_daily'][0];
        self::assertNull($daily['revenue']);
        self::assertNull($daily['room_revenue']);
        self::assertNull($daily['room_nights']);
        self::assertNull($daily['order_count']);

        $traffic = $dataset['fact_ota_traffic'][0];
        self::assertNull($traffic['list_exposure']);
        self::assertNull($traffic['detail_exposure']);
        self::assertNull($traffic['flow_rate']);
        self::assertNull($traffic['order_filling_num']);
        self::assertNull($traffic['order_submit_num']);

        $advertising = $dataset['fact_ota_advertising'][0];
        self::assertNull($advertising['spend']);
        self::assertNull($advertising['order_amount']);
        self::assertNull($advertising['bookings']);
        self::assertNull($advertising['impressions']);
        self::assertNull($advertising['clicks']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['room_nights']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertNull($metrics['by_platform'][0]['revenue']);
        self::assertNull($metrics['by_platform'][0]['room_nights']);
        self::assertNull($metrics['by_platform'][0]['order_count']);
        self::assertNull($metrics['by_hotel'][0]['revenue']);
        self::assertNull($metrics['channel_contribution'][0]['revenue']);
        self::assertNull($metrics['channel_contribution'][0]['room_nights']);
        self::assertNull($metrics['channel_contribution'][0]['order_count']);
        self::assertNull($metrics['advertising']['spend']);
        self::assertNull($metrics['advertising']['order_amount']);
        self::assertNull($metrics['advertising']['bookings']);
        self::assertNull($metrics['advertising']['impressions']);
        self::assertNull($metrics['advertising']['clicks']);
    }

    public function testDerivedRevenueMetricsStayNullWhenRevenueEvidenceIsMissing(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 43,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-12',
            'quantity' => 2,
            'book_order_num' => 1,
            'available_room_nights' => 10,
            'raw_data' => '{}',
        ]]);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['adr']);
        self::assertNull($metrics['totals']['revpar']);
        self::assertNull($metrics['by_platform'][0]['adr']);
        self::assertNull($metrics['by_platform'][0]['revpar']);
        self::assertNull($metrics['by_hotel'][0]['adr']);
        self::assertNull($metrics['by_hotel'][0]['revpar']);
    }

    public function testP1ClosureCannotBeReadyWhenAChildMetricIsNotCalculable(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'price_gap' => -10.0,
                'price_gap_rate' => -4.76,
                'source_trace' => $this->trace(9051, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'flow_rate' => null,
                'submit_rate' => 25.0,
                'source_trace' => $this->trace(9052, 'ctrip', 'traffic', '2026-06-25'),
            ]],
        ]);

        self::assertSame('not_calculable', $metrics['p1_revenue_closure']['sections']['adr_conversion']['metrics']['flow_rate']['status']);
        self::assertSame('partial', $metrics['p1_revenue_closure']['sections']['adr_conversion']['status']);
        self::assertContains(
            'traffic.avg_flow_rate:metric_value_missing',
            array_column($metrics['p1_revenue_closure']['missing_items']['items'], 'code')
        );
        self::assertSame('warning', $metrics['p1_revenue_closure']['status']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sampleRows(): array
    {
        return [
            [
                'id' => 1,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-18',
                'update_time' => '2026-05-18 10:00:00',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'cancel_order_num' => 1,
                    'cancel_room_nights' => 1,
                    'our_price' => 200,
                    'competitor_price' => 220,
                    'available_rooms' => 10,
                    'occupied_rooms' => 6,
                    'commission_rate' => 0.15,
                    'booking_date' => '2026-05-10',
                    'checkin_date' => '2026-05-18',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 2,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-18',
                'update_time' => '2026-05-18 10:05:00',
                'list_exposure' => 1000,
                'detail_exposure' => 200,
                'flow_rate' => 20,
                'order_filling_num' => 30,
                'order_submit_num' => 10,
                'raw_data' => '{}',
            ],
            [
                'id' => 3,
                'system_hotel_id' => 8,
                'hotel_id' => 'poi-8',
                'hotel_name' => 'Hotel Beta',
                'source' => 'meituan',
                'data_type' => 'review',
                'data_date' => '2026-05-18',
                'dimension' => 'review:meituan',
                'comment_score' => 3.0,
                'quantity' => 1,
                'data_value' => 1.0,
                'raw_data' => json_encode([
                    'channel' => 'meituan',
                    'comment_score' => 3.0,
                    'comment_count' => 1,
                    'bad_review_count' => 1,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trace(int $rowId, string $platform, string $dataType, string $date): array
    {
        return [
            'table' => 'online_daily_data',
            'row_id' => $rowId,
            'hotel_key' => 'system:7',
            'platform' => $platform,
            'data_type' => $dataType,
            'date_key' => $date,
            'updated_at' => $date . ' 10:00:00',
            'saved_success' => true,
            'failure_reasons' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @return array<string, mixed>
     */
    private function channelMetric(array $metrics, string $resource, string $metricKey): array
    {
        foreach ($metrics as $metric) {
            if (($metric['resource'] ?? '') === $resource && ($metric['metric_key'] ?? '') === $metricKey) {
                return $metric;
            }
        }

        self::fail("Missing channel metric {$resource}.{$metricKey}");
    }
}
