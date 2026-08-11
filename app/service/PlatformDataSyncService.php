<?php
declare(strict_types=1);

namespace app\service;

use app\contract\DataSourceAdapter;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\LocalCollectorDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

final class PlatformDataSyncService
{
    use \app\service\concern\PlatformDataSourceExecutionConcern;
    use \app\service\concern\PlatformSyncTaskConcern;
    use \app\service\concern\PlatformDataPersistenceConcern;

    private const RAW_RECORD_PAYLOAD_LIMIT_BYTES = 262144;
    private const COLLECTION_RESOURCE_FRESH_HOURS = 24;
    // Profile captures are bounded and report no heartbeat while executing.
    // Five minutes is long enough for the bounded section capture, but avoids
    // leaving a vanished worker as an active task for a full hour.
    // Browser adapters permit a bounded capture of up to 900 seconds. Keep a
    // five-minute persistence/finalization margin so the maximum legal capture
    // cannot become stale while its rows and exact readback are being committed.
    // The 14-minute retry remains non-interrupting; the 28-minute slot can still
    // recover a genuinely abandoned task.
    private const STALE_RUNNING_TASK_SECONDS = 1200;
    private const IMPORT_XLSX_MAX_ARCHIVE_ENTRIES = 256;
    private const IMPORT_XLSX_MAX_ENTRY_BYTES = 8388608;
    private const IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES = 20971520;
    private const IMPORT_XLSX_MAX_ROWS = 10000;
    private const IMPORT_XLSX_MAX_COLUMNS = 256;
    private const IMPORT_XLSX_MAX_SHARED_STRINGS = 20000;
    private const MANUAL_IMPORT_METHODS = ['manual', 'import_json', 'import_csv', 'import_excel'];
    private const MANUAL_IMPORT_SOURCE_CONTRACT = 'user_provided_unverified.v1';
    private const ACTIVE_SYNC_TASK_STATUSES = ['pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login'];
    private const COLLECTION_RESOURCE_DEFINITIONS = [
        [
            'resource' => 'businessData',
            'data_type' => 'business',
            'priority' => 'P0',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel',
            'default_enabled' => true,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_business_metrics_only',
            'aliases' => ['business', 'business_data', 'businessdata', 'tradeData', 'trade_data', 'overview', 'summary'],
            'periods' => ['realtime', 'yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'amount', 'storage_table' => 'online_daily_data', 'storage_field' => 'amount', 'missing_state' => 'field_missing'],
                ['field' => 'quantity', 'storage_table' => 'online_daily_data', 'storage_field' => 'quantity', 'missing_state' => 'field_missing'],
                ['field' => 'book_order_num', 'storage_table' => 'online_daily_data', 'storage_field' => 'book_order_num', 'missing_state' => 'field_missing'],
                ['field' => 'data_value', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'peerRank',
            'data_type' => 'peer_rank',
            'priority' => 'P0',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_competition',
            'default_enabled' => true,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'competitor_aggregate_only',
            'aliases' => ['peer_rank', 'peerrank', 'competitor_rank', 'competitorRank', 'competition', 'ranking', 'rankings'],
            'periods' => ['realtime', 'yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'rank', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value/raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'hotel_name', 'storage_table' => 'online_daily_data', 'storage_field' => 'hotel_name', 'missing_state' => 'field_missing'],
                ['field' => 'vip_status', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'optional_missing'],
                ['field' => 'rank_type', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data/compare_type', 'missing_state' => 'field_missing'],
            ],
        ],
        [
            'resource' => 'flowData',
            'data_type' => 'traffic',
            'priority' => 'P0',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_traffic',
            'default_enabled' => true,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_traffic_metrics_only',
            'aliases' => ['flow', 'flow_data', 'flowdata', 'traffic', 'traffic_data', 'trafficdata'],
            'periods' => ['realtime', 'yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'list_exposure', 'storage_table' => 'online_daily_data', 'storage_field' => 'list_exposure', 'missing_state' => 'field_missing'],
                ['field' => 'detail_exposure', 'storage_table' => 'online_daily_data', 'storage_field' => 'detail_exposure', 'missing_state' => 'field_missing'],
                ['field' => 'flow_rate', 'storage_table' => 'online_daily_data', 'storage_field' => 'flow_rate', 'missing_state' => 'field_missing'],
                ['field' => 'order_submit_num', 'storage_table' => 'online_daily_data', 'storage_field' => 'order_submit_num', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'trafficForecast',
            'data_type' => 'traffic_forecast',
            'priority' => 'P1',
            'platforms' => ['meituan'],
            'scope' => 'ota_channel_future_demand_signal',
            'default_enabled' => false,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_forecast_only',
            'aliases' => ['traffic_forecast', 'trafficForecast', 'flow_forecast', 'flowForecast', 'forecast'],
            'periods' => ['next_30_days'],
            'fields' => [
                ['field' => 'forecast_type', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'current', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value/raw_data', 'missing_state' => 'optional_missing'],
                ['field' => 'peer_avg', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'flowAnalysis',
            'data_type' => 'traffic_analysis',
            'priority' => 'P1',
            'platforms' => ['meituan'],
            'scope' => 'ota_channel_traffic_analysis',
            'default_enabled' => false,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_traffic_analysis_only',
            'aliases' => ['flow_analysis', 'flowAnalysis', 'traffic_analysis', 'trafficAnalysis', 'flowConversion', 'flowTrend', 'flowTrendDetail'],
            'periods' => ['realtime', 'yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'analysis_type', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'data_value', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value/raw_data', 'missing_state' => 'optional_missing'],
                ['field' => 'peer_rank', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'searchKeywords',
            'data_type' => 'search_keyword',
            'priority' => 'P1',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_search',
            'default_enabled' => true,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'keyword_aggregate_only',
            'aliases' => ['search_keyword', 'search_keywords', 'searchkeyword', 'searchkeywords', 'searchKeyWords', 'keyword', 'keywords'],
            'periods' => ['yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'keyword', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension/raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'exposure', 'storage_table' => 'online_daily_data', 'storage_field' => 'list_exposure/raw_data', 'missing_state' => 'optional_missing'],
                ['field' => 'clicks', 'storage_table' => 'online_daily_data', 'storage_field' => 'detail_exposure/raw_data', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'orderData',
            'data_type' => 'order',
            'priority' => 'P1',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_order_aggregate',
            'default_enabled' => false,
            'requires_explicit_authorization' => true,
            'privacy_boundary' => 'aggregate_order_metrics_only_redacted_pii',
            'aliases' => ['order', 'orders', 'order_data', 'orderdata', 'order_list'],
            'periods' => ['yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'book_order_num', 'storage_table' => 'online_daily_data', 'storage_field' => 'book_order_num', 'missing_state' => 'field_missing'],
                ['field' => 'quantity', 'storage_table' => 'online_daily_data', 'storage_field' => 'quantity', 'missing_state' => 'field_missing'],
                ['field' => 'amount', 'storage_table' => 'online_daily_data', 'storage_field' => 'amount', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'orderFlowData',
            'data_type' => 'order_flow',
            'priority' => 'P1',
            'platforms' => ['meituan'],
            'scope' => 'ota_channel_demand_flow',
            'default_enabled' => false,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_demand_flow_only_no_order_pii',
            'aliases' => ['order_flow', 'orderFlow', 'loss_order', 'lossOrder', 'loss_orders', 'lossOrders', 'inflow_order', 'inflowOrder'],
            'periods' => ['yesterday', 'last_7_days', 'last_30_days', 'custom'],
            'fields' => [
                ['field' => 'order_flow_direction', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.order_flow_direction', 'missing_state' => 'field_missing'],
                ['field' => 'order_flow_period', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.order_flow_period', 'missing_state' => 'field_missing'],
                ['field' => 'order_count', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.order_count', 'missing_state' => 'field_missing'],
                ['field' => 'room_nights', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.room_nights', 'missing_state' => 'field_missing'],
                ['field' => 'amount', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.amount', 'missing_state' => 'field_missing'],
                ['field' => 'order_ratio', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'reviewData',
            'data_type' => 'review',
            'priority' => 'P2',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_review_summary',
            'default_enabled' => false,
            'requires_explicit_authorization' => true,
            'privacy_boundary' => 'score_and_tags_only_no_review_text',
            'aliases' => ['review', 'reviews', 'comment', 'comments', 'review_data', 'reviewdata'],
            'periods' => ['yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'comment_score', 'storage_table' => 'online_daily_data', 'storage_field' => 'comment_score', 'missing_state' => 'field_missing'],
                ['field' => 'quantity', 'storage_table' => 'online_daily_data', 'storage_field' => 'quantity', 'missing_state' => 'optional_missing'],
                ['field' => 'tags', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'advertisingData',
            'data_type' => 'advertising',
            'priority' => 'P1',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_advertising',
            'default_enabled' => false,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'aggregate_campaign_metrics_only',
            'aliases' => ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns', 'ad_data', 'adData'],
            'periods' => ['realtime', 'yesterday', 'last_7_days', 'last_30_days'],
            'fields' => [
                ['field' => 'advertising_spend', 'storage_table' => 'online_daily_data', 'storage_field' => 'amount', 'missing_state' => 'field_missing'],
                ['field' => 'advertising_order_amount', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.order_amount', 'missing_state' => 'optional_missing'],
                ['field' => 'advertising_order_count', 'storage_table' => 'online_daily_data', 'storage_field' => 'book_order_num', 'missing_state' => 'optional_missing'],
                ['field' => 'advertising_impressions', 'storage_table' => 'online_daily_data', 'storage_field' => 'list_exposure', 'missing_state' => 'optional_missing'],
                ['field' => 'advertising_clicks', 'storage_table' => 'online_daily_data', 'storage_field' => 'detail_exposure', 'missing_state' => 'optional_missing'],
                ['field' => 'advertising_roas', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing'],
                ['field' => 'advertising_ctr', 'storage_table' => 'online_daily_data', 'storage_field' => 'flow_rate', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'roomTypes',
            'data_type' => 'room_type',
            'priority' => 'P1',
            'platforms' => ['meituan', 'ctrip'],
            'scope' => 'ota_channel_product_catalog',
            'default_enabled' => false,
            'requires_explicit_authorization' => false,
            'privacy_boundary' => 'room_type_catalog_only_no_room_status_or_mapping',
            'aliases' => ['room_type', 'room_types', 'roomtype', 'roomtypes', 'product', 'products'],
            'periods' => ['realtime', 'yesterday'],
            'fields' => [
                ['field' => 'room_type_name', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension/raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'price', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value/raw_data', 'missing_state' => 'optional_missing'],
                ['field' => 'product_status', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'optional_missing'],
            ],
        ],
        [
            'resource' => 'platformIdentity',
            'data_type' => 'platform_identity',
            'priority' => 'P1',
            'platforms' => ['meituan'],
            'scope' => 'ota_channel_platform_identity',
            'default_enabled' => false,
            'requires_explicit_authorization' => true,
            'privacy_boundary' => 'platform_identifier_only_no_cookie_no_token',
            'aliases' => ['platform_identity', 'platformIdentity', 'identity', 'partner_id', 'partnerId', 'poi_id', 'poiId'],
            'periods' => ['realtime'],
            'fields' => [
                ['field' => 'partner_id', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data', 'missing_state' => 'field_missing'],
                ['field' => 'poi_id', 'storage_table' => 'online_daily_data', 'storage_field' => 'hotel_id/raw_data', 'missing_state' => 'field_missing'],
            ],
        ],
    ];

    private const NORMALIZED_FIELD_FACT_DEFINITIONS = [
        'business' => [
            [
                'metric_key' => 'order_amount',
                'normalized_field' => 'amount',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'amount',
                'missing_state' => 'field_missing',
                'source_keys' => ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount'],
            ],
            [
                'metric_key' => 'room_nights',
                'normalized_field' => 'quantity',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'quantity',
                'missing_state' => 'field_missing',
                'source_keys' => ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'checkoutRoomNights', 'checkout_room_nights', 'checkOutQuantity', 'bookQuantity'],
            ],
            [
                'metric_key' => 'order_count',
                'normalized_field' => 'book_order_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'book_order_num',
                'missing_state' => 'field_missing',
                'source_keys' => ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount'],
            ],
            [
                'metric_key' => 'data_value',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
                'missing_state' => 'optional_missing',
                'source_keys' => ['data_value', 'dataValue', 'value', 'metric_value', 'averagePrice', 'avgPrice', 'avg_price'],
            ],
            [
                'metric_key' => 'lead_price',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.lead_price',
                'missing_state' => 'optional_missing',
                'source_keys' => ['lead_price', 'leadPrice', 'startingPrice', 'realtimeStartingPrice', 'minPrice', 'DAY_ROOM_LOWEST_PRICE_AVG'],
            ],
            [
                'metric_key' => 'sales_avg_price',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
                'missing_state' => 'optional_missing',
                'source_keys' => ['sales_avg_price', 'salesAvgPrice', 'avg_price', 'avgPrice', 'averagePrice', 'PAY_ADR'],
            ],
            [
                'metric_key' => 'exposure_users',
                'normalized_field' => 'list_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'list_exposure',
                'missing_state' => 'optional_missing',
                'source_keys' => ['exposure_users', 'exposureUsers', 'listExposure', 'list_exposure', 'exposureUV'],
            ],
            [
                'metric_key' => 'detail_visitors',
                'normalized_field' => 'detail_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'detail_exposure',
                'missing_state' => 'optional_missing',
                'source_keys' => ['detail_visitors', 'detailVisitors', 'detailExposure', 'detail_exposure', 'intentionUV'],
            ],
            [
                'metric_key' => 'browse_to_pay_rate',
                'normalized_field' => 'flow_rate',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'flow_rate',
                'missing_state' => 'optional_missing',
                'source_keys' => ['browse_to_pay_rate', 'browsePayRate', 'browse_pay_rate', 'payOrderPerIntention', 'flowRate', 'flow_rate'],
            ],
        ],
        'order' => [
            [
                'metric_key' => 'order_amount',
                'normalized_field' => 'amount',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'amount',
                'missing_state' => 'field_missing',
                'source_keys' => ['totalAmount', 'orderAmount', 'payAmount', 'roomAmount', 'amount', 'order_amount', 'room_revenue', 'revenue'],
            ],
            [
                'metric_key' => 'room_nights',
                'normalized_field' => 'quantity',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'quantity',
                'missing_state' => 'field_missing',
                'source_keys' => ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'nightCount'],
            ],
            [
                'metric_key' => 'order_count',
                'normalized_field' => 'book_order_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'book_order_num',
                'missing_state' => 'field_missing',
                'source_keys' => ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount'],
            ],
        ],
        'peer_rank' => [
            [
                'metric_key' => 'peer_rank_value',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.rank',
                'missing_state' => 'field_missing',
                'source_keys' => ['data_value', 'dataValue', 'rank', 'ranking', 'rankValue', 'rank_value', 'rankPercent', 'rank_percent', 'value'],
            ],
            [
                'metric_key' => 'peer_rank_dimension',
                'normalized_field' => 'dimension',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'dimension',
                'missing_state' => 'field_missing',
                'source_keys' => ['dimension', 'dim_name', '_dimName', 'rank_type', 'rankType', 'compare_type', 'compareType'],
            ],
            [
                'metric_key' => 'peer_rank_compare_type',
                'normalized_field' => 'compare_type',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'compare_type',
                'missing_state' => 'field_missing',
                'source_keys' => ['compare_type', 'compareType', 'rank_type', 'rankType'],
            ],
        ],
        'quality' => [
            [
                'metric_key' => 'quality_score',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
                'missing_state' => 'field_missing',
                'source_keys' => ['data_value', 'dataValue', 'serviceScore', 'psiScore', 'imScore', 'score'],
            ],
            [
                'metric_key' => 'quality_dimension',
                'normalized_field' => 'dimension',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'dimension',
                'missing_state' => 'field_missing',
                'source_keys' => ['dimension', 'dim_name', '_dimName', 'metric_key', 'metricKey'],
            ],
            [
                'metric_key' => 'quality_compare_type',
                'normalized_field' => 'compare_type',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'compare_type',
                'missing_state' => 'field_missing',
                'source_keys' => ['compare_type', 'compareType'],
            ],
        ],
        'traffic' => [
            [
                'metric_key' => 'list_exposure',
                'normalized_field' => 'list_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'list_exposure',
                'missing_state' => 'field_missing',
                'source_keys' => ['mt_exposure', 'list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv'],
            ],
            [
                'metric_key' => 'mt_exposure',
                'normalized_field' => 'list_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'list_exposure',
                'missing_state' => 'field_missing',
                'source_keys' => ['mt_exposure', 'exposure_count', 'exposureCount', 'listExposure', 'exposureUV', 'exposure_uv'],
            ],
            [
                'metric_key' => 'detail_exposure',
                'normalized_field' => 'detail_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'detail_exposure',
                'missing_state' => 'field_missing',
                'source_keys' => ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount', 'visitors', 'visitorTotal', 'pv', 'uv'],
            ],
            [
                'metric_key' => 'mt_intention_uv',
                'normalized_field' => 'detail_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'detail_exposure',
                'missing_state' => 'field_missing',
                'source_keys' => ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detailExposure', 'visitors', 'visitorTotal', 'uv'],
            ],
            [
                'metric_key' => 'flow_rate',
                'normalized_field' => 'flow_rate',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'flow_rate',
                'missing_state' => 'field_missing',
                'source_keys' => ['browse_to_pay_rate', 'browsePayRate', 'browse_pay_rate', 'payOrderPerIntention', 'flow_rate', 'flowRate', 'cvr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate'],
            ],
            [
                'metric_key' => 'meituan_detail_to_paid_rate',
                'normalized_field' => 'raw_data.row.payOrderPerIntention',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.row.payOrderPerIntention',
                'missing_state' => 'optional_missing',
                'source_keys' => ['payOrderPerIntention'],
            ],
            [
                'metric_key' => 'order_filling_num',
                'normalized_field' => 'order_filling_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'order_filling_num',
                'missing_state' => 'optional_missing',
                'source_keys' => ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'clickCount', 'clicks'],
            ],
            [
                'metric_key' => 'order_submit_num',
                'normalized_field' => 'order_submit_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'order_submit_num',
                'missing_state' => 'optional_missing',
                'source_keys' => ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'order_submit_num', 'orderSubmitNum', 'bookings', 'bookingCount', 'orderCount', 'orderQuantity', 'orderNum', 'orders'],
            ],
            [
                'metric_key' => 'mt_pay_orders',
                'normalized_field' => 'order_submit_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'order_submit_num',
                'missing_state' => 'optional_missing',
                'source_keys' => ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'orderSubmitNum', 'orderNum', 'orders'],
            ],
            [
                'metric_key' => 'mt_pay_rooms',
                'normalized_field' => 'quantity',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'quantity',
                'missing_state' => 'optional_missing',
                'source_keys' => ['mt_pay_rooms', 'pay_rooms', 'payRooms', 'payRoomNum', 'pay_room_num', 'roomNights', 'room_nights', 'quantity'],
            ],
        ],
        'advertising' => [
            [
                'metric_key' => 'advertising_spend',
                'normalized_field' => 'amount',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'amount',
                'missing_state' => 'field_missing',
                'source_keys' => ['amount', 'todayCost', 'cost', 'cashCost', 'bonusCost', 'ad_cost', 'adCost', 'spend', 'consume', 'consumption'],
            ],
            [
                'metric_key' => 'advertising_order_amount',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.order_amount',
                'missing_state' => 'optional_missing',
                'source_keys' => ['order_amount', 'orderAmount', 'saleAmount', 'salesAmount', 'revenue', 'gmv'],
            ],
            [
                'metric_key' => 'advertising_order_count',
                'normalized_field' => 'book_order_num',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'book_order_num',
                'missing_state' => 'optional_missing',
                'source_keys' => ['book_order_num', 'bookOrderNum', 'orderNum', 'order_count', 'orders', 'booking_count', 'bookingCount'],
            ],
            [
                'metric_key' => 'advertising_impressions',
                'normalized_field' => 'list_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'list_exposure',
                'missing_state' => 'optional_missing',
                'source_keys' => ['exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure'],
            ],
            [
                'metric_key' => 'advertising_clicks',
                'normalized_field' => 'detail_exposure',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'detail_exposure',
                'missing_state' => 'optional_missing',
                'source_keys' => ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'],
            ],
            [
                'metric_key' => 'advertising_roas',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
                'missing_state' => 'optional_missing',
                'source_keys' => ['roas', 'roi'],
            ],
            [
                'metric_key' => 'advertising_ctr',
                'normalized_field' => 'flow_rate',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'flow_rate',
                'missing_state' => 'optional_missing',
                'source_keys' => ['flow_rate', 'flowRate', 'ctr'],
            ],
        ],
        'order_flow' => [
            [
                'metric_key' => 'order_flow_direction',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.order_flow_direction',
                'missing_state' => 'field_missing',
                'source_keys' => ['order_flow_direction', 'orderFlowDirection', 'direction'],
            ],
            [
                'metric_key' => 'order_flow_row_type',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.order_flow_row_type',
                'missing_state' => 'field_missing',
                'source_keys' => ['order_flow_row_type', 'orderFlowRowType', 'row_type', 'rowType'],
            ],
            [
                'metric_key' => 'order_flow_period',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.order_flow_period',
                'missing_state' => 'field_missing',
                'source_keys' => ['order_flow_period', 'orderFlowPeriod', 'period'],
            ],
            [
                'metric_key' => 'order_flow_order_count',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.order_count',
                'missing_state' => 'field_missing',
                'source_keys' => ['order_count', 'orderCount', 'lossTotalCnt', 'lossOrderCount'],
            ],
            [
                'metric_key' => 'order_flow_room_nights',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.room_nights',
                'missing_state' => 'field_missing',
                'source_keys' => ['room_nights', 'roomNights', 'lossTotalPayRoomNight'],
            ],
            [
                'metric_key' => 'order_flow_amount',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.amount',
                'missing_state' => 'field_missing',
                'source_keys' => ['amount', 'lossTotalPayAmount', 'lossSinglePayAmount'],
            ],
            [
                'metric_key' => 'order_flow_ratio',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
                'missing_state' => 'optional_missing',
                'source_keys' => ['order_ratio', 'orderRatio', 'lossOrderRatio'],
            ],
        ],
        'search_keyword' => [
            ['metric_key' => 'search_keyword', 'normalized_field' => 'dimension', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension', 'missing_state' => 'field_missing', 'source_keys' => ['keyword', 'search_keyword', 'searchKeyword', 'word']],
            ['metric_key' => 'search_exposure', 'normalized_field' => 'list_exposure', 'storage_table' => 'online_daily_data', 'storage_field' => 'list_exposure', 'missing_state' => 'optional_missing', 'source_keys' => ['exposure', 'exposures', 'impression', 'impressions', 'listExposure']],
            ['metric_key' => 'search_clicks', 'normalized_field' => 'detail_exposure', 'storage_table' => 'online_daily_data', 'storage_field' => 'detail_exposure', 'missing_state' => 'optional_missing', 'source_keys' => ['clicks', 'click', 'clickCount', 'detailExposure']],
        ],
        'traffic_forecast' => [
            ['metric_key' => 'forecast_type', 'normalized_field' => 'dimension', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension', 'missing_state' => 'field_missing', 'source_keys' => ['forecast_type', 'forecastType', 'type', 'dimension']],
            ['metric_key' => 'forecast_current', 'normalized_field' => 'data_value', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing', 'source_keys' => ['current', 'currentValue', 'value', 'data_value', 'dataValue']],
            ['metric_key' => 'forecast_peer_average', 'normalized_field' => 'raw_data', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.peer_avg', 'missing_state' => 'optional_missing', 'source_keys' => ['peer_avg', 'peerAvg', 'peerAverage', 'competitor_avg', 'competitorAverage', 'average']],
        ],
        'traffic_analysis' => [
            ['metric_key' => 'analysis_type', 'normalized_field' => 'dimension', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension', 'missing_state' => 'field_missing', 'source_keys' => ['analysis_type', 'analysisType', 'type', 'dimension']],
            ['metric_key' => 'analysis_value', 'normalized_field' => 'data_value', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing', 'source_keys' => ['data_value', 'dataValue', 'value', 'currentValue']],
            ['metric_key' => 'analysis_peer_rank', 'normalized_field' => 'raw_data', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.peer_rank', 'missing_state' => 'optional_missing', 'source_keys' => ['peer_rank', 'peerRank', 'rank', 'ranking']],
        ],
        'review' => [
            ['metric_key' => 'review_score', 'normalized_field' => 'comment_score', 'storage_table' => 'online_daily_data', 'storage_field' => 'comment_score', 'missing_state' => 'field_missing', 'source_keys' => ['comment_score', 'commentScore', 'score', 'rating']],
            ['metric_key' => 'review_count', 'normalized_field' => 'quantity', 'storage_table' => 'online_daily_data', 'storage_field' => 'quantity', 'missing_state' => 'optional_missing', 'source_keys' => ['review_count', 'reviewCount', 'comment_count', 'commentCount', 'count', 'quantity']],
            ['metric_key' => 'review_tags', 'normalized_field' => 'raw_data', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.tags', 'missing_state' => 'optional_missing', 'source_keys' => ['tags', 'tagList', 'labels']],
        ],
        'room_type' => [
            ['metric_key' => 'room_type_name', 'normalized_field' => 'dimension', 'storage_table' => 'online_daily_data', 'storage_field' => 'dimension', 'missing_state' => 'field_missing', 'source_keys' => ['room_type_name', 'roomTypeName', 'room_name', 'roomName', 'name']],
            ['metric_key' => 'room_type_price', 'normalized_field' => 'data_value', 'storage_table' => 'online_daily_data', 'storage_field' => 'data_value', 'missing_state' => 'optional_missing', 'source_keys' => ['price', 'roomPrice', 'sellPrice', 'data_value', 'dataValue']],
            ['metric_key' => 'room_type_status', 'normalized_field' => 'raw_data', 'storage_table' => 'online_daily_data', 'storage_field' => 'raw_data.product_status', 'missing_state' => 'optional_missing', 'source_keys' => ['product_status', 'productStatus', 'status', 'saleStatus']],
        ],
        'platform_identity' => [
            [
                'metric_key' => 'meituan_partner_id',
                'normalized_field' => 'raw_data',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'raw_data.platform_identity.partner_id',
                'missing_state' => 'field_missing',
                'source_keys' => ['partner_id', 'partnerId'],
            ],
            [
                'metric_key' => 'meituan_poi_id',
                'normalized_field' => 'hotel_id',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'hotel_id',
                'missing_state' => 'field_missing',
                'source_keys' => ['poi_id', 'poiId', 'store_id', 'storeId', 'hotel_id', 'hotelId'],
            ],
        ],
    ];

    /** @var array<int, DataSourceAdapter> */
    private array $adapters;

    private ?OtaCredentialVault $credentialVault;

    private OtaProfileSessionProofService $profileSessionProofService;

    private PlatformNormalizedRowPersistenceService $normalizedRowPersistence;

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /**
     * @param array<int, DataSourceAdapter>|null $adapters
     */
    public function __construct(
        ?array $adapters = null,
        ?OtaCredentialVault $credentialVault = null,
        ?OtaProfileSessionProofService $profileSessionProofService = null
    )
    {
        $this->adapters = $adapters ?? [
            new ManualImportDataSourceAdapter(),
            new LocalCollectorDataSourceAdapter(),
            new CtripBrowserProfileDataSourceAdapter(),
            new MeituanBrowserProfileDataSourceAdapter(),
            new ApiDataSourceAdapter(),
        ];
        $this->credentialVault = $credentialVault;
        $this->profileSessionProofService = $profileSessionProofService ?? new OtaProfileSessionProofService();
        $this->normalizedRowPersistence = new PlatformNormalizedRowPersistenceService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectionResourceDefinitions(): array
    {
        return array_values(self::COLLECTION_RESOURCE_DEFINITIONS);
    }

    /**
     * Read the exact persisted rows that an ordered Profile run may use as
     * evidence for its next missing section. Keeping this query beside the
     * persistence/sync service prevents the scheduler from becoming another
     * online_daily_data access path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readStoredRowsForCollectionPlan(
        int $hotelId,
        int $sourceId,
        string $platform,
        string $dataDate
    ): array {
        $platform = strtolower(trim($platform));
        $dataDate = substr(trim($dataDate), 0, 10);
        if ($hotelId <= 0 || $sourceId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataDate) !== 1
        ) {
            return [];
        }
        try {
            return Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('data_date', $dataDate)
                ->where(static function ($query) use ($platform): void {
                    $query->where('platform', $platform)->whereOr('source', $platform);
                })
                ->limit(500)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function collectionResourceCatalog($user, array $filters = []): array
    {
        $definitions = $this->collectionResourceDefinitions();
        $platformFilter = strtolower(trim((string)($filters['platform'] ?? '')));
        $resourceFilter = trim((string)($filters['resource'] ?? ''));
        $dataTypeFilter = trim((string)($filters['data_type'] ?? $filters['dataType'] ?? ''));
        $normalizedDataTypeFilter = $dataTypeFilter !== '' ? $this->normalizeDataType($dataTypeFilter) : '';

        $accessIssues = [];
        $sources = $this->catalogDataSources($user, $filters, $accessIssues);
        $tasks = $this->catalogSyncTasks($user, $filters, $accessIssues);
        $latestRows = $this->catalogLatestStoredRows($user, $filters, $accessIssues);

        $resources = [];
        foreach ($definitions as $definition) {
            if ($resourceFilter !== '' && strcasecmp((string)$definition['resource'], $resourceFilter) !== 0) {
                continue;
            }
            if ($normalizedDataTypeFilter !== '' && $this->normalizeDataType((string)$definition['data_type']) !== $normalizedDataTypeFilter) {
                continue;
            }

            $platforms = [];
            foreach ($definition['platforms'] as $platform) {
                $platform = strtolower((string)$platform);
                if ($platformFilter !== '' && $platform !== $platformFilter) {
                    continue;
                }
                $platforms[] = $this->buildResourcePlatformStatus($definition, $platform, $sources, $tasks, $latestRows);
            }

            if ($platforms === []) {
                continue;
            }

            $resources[] = array_merge($definition, [
                'platform_statuses' => $platforms,
                'evidence_contract' => [
                    'resource' => $definition['resource'],
                    'data_type' => $definition['data_type'],
                    'scope' => $definition['scope'],
                    'fields' => $definition['fields'],
                    'must_record' => ['source', 'platform', 'data_type', 'data_period', 'update_time', 'missing_reason'],
                ],
            ]);
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'freshness_threshold_hours' => self::COLLECTION_RESOURCE_FRESH_HOURS,
            'resources' => $resources,
            'task_endpoints' => [
                'data_sources' => '/api/online-data/data-sources',
                'sync_tasks' => '/api/online-data/sync-tasks',
                'sync_logs' => '/api/online-data/sync-logs',
            ],
            'policy' => [
                'captcha_or_platform_limit' => 'manual_intervention_required',
                'review_data' => 'disabled_by_default',
                'privacy_scope' => 'ota_channel_aggregate_only',
                'ota_collection_mainline' => 'account_owned_local_collector_or_browser_profile',
                'ota_password_custody' => 'not_supported',
                'cookie_api_role' => 'p1_profile_derived_fast_path_or_backfill',
                'profile_login_state' => 'profile_available_attempt_first',
            ],
            'access_issues' => $accessIssues,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRowsFromPayload(array $payload, array $source, ?int $syncTaskId = null): array
    {
        $rows = $this->extractBusinessRows($payload);
        if (empty($rows)) {
            return [];
        }

        $collectionStatus = strtolower(trim((string)($payload['collection_status'] ?? $payload['collectionStatus'] ?? '')));
        if (in_array($collectionStatus, ['failed', 'failure', 'collection_failed', 'request_failed', 'auth_failed'], true)) {
            return [];
        }

        $tenantId = $this->resolveSourceTenantId($source);
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $this->normalizeDate(
                $row['data_date']
                    ?? $row['dataDate']
                    ?? $row['date']
                    ?? $row['stat_date']
                    ?? $row['statDate']
                    ?? $row['biz_date']
                    ?? $row['bizDate']
                    ?? $row['orderDate']
                    ?? $row['createTime']
                    ?? $payload['data_date']
                    ?? $payload['dataDate']
                    ?? null
            );
            if ($date === null) {
                continue;
            }

            $platform = strtolower((string)($source['platform'] ?? $row['source'] ?? 'custom'));
            $sourceDataType = (string)($source['data_type'] ?? '');
            $rowDataType = (string)($row['data_type'] ?? '');
            $sourceIngestionMethod = (string)($source['ingestion_method'] ?? '');
            $preserveMissingMetrics = true;
            $dataType = $this->normalizeDataType(
                in_array($sourceIngestionMethod, ['browser_profile', 'profile_browser', 'local_collector'], true) && $rowDataType !== ''
                    ? $rowDataType
                    : ($sourceDataType !== '' ? $sourceDataType : ($rowDataType !== '' ? $rowDataType : 'business'))
            );
            $row = $this->reconcileCtripCatalogStructuredMetricFacts($row, $source);
            if ($this->isCommentDataType($dataType) && !$this->isReviewCollectionAllowed($source, $payload, $dataType)) {
                continue;
            }
            $validationFlags = $dataType === 'review'
                ? $this->reviewValidationFlags($row, $payload, $date, $collectionStatus)
                : [];
            $reviewValidationStatus = $this->reviewValidationStatus($validationFlags);
            $periodMeta = $this->resolveDataPeriodMetadata($row, $payload, $source, $date);
            $traceId = trim((string)($row['source_trace_id'] ?? ''));
            if ($traceId === '' || ($periodMeta['data_period'] === 'realtime_snapshot' && $periodMeta['snapshot_bucket'] !== '')) {
                $traceId = $this->buildTraceId($source, $row, $date, $syncTaskId, $periodMeta['snapshot_bucket']);
            }
            $allowReviewSummary = $dataType === 'review'
                && $this->payloadRequestsReviewDetailStorage($payload)
                && $this->isReviewCollectionAllowed($source, $payload, $dataType);
            $sanitizedRow = $dataType === 'review'
                ? $this->sanitizeReviewPayloadForStorage($row, $allowReviewSummary)
                : $this->sanitizePayloadForStorage($row, $dataType);
            $platformIdentifierEvidence = $this->platformHotelIdentifierEvidence($platform, $row, $source);
            $raw = [
                'row' => $sanitizedRow,
                'data_source_id' => $source['id'] ?? null,
                'data_source_name' => $source['name'] ?? '',
                'sync_task_id' => $syncTaskId,
                'source_trace_id' => $traceId,
                'ingested_at' => date('Y-m-d H:i:s'),
                'data_period' => $periodMeta['data_period'],
                'snapshot_time' => $periodMeta['snapshot_time'],
                'snapshot_bucket' => $periodMeta['snapshot_bucket'],
            ];
            $collectorBindingEvidence = $this->collectorBindingEvidence($source);
            if ($collectorBindingEvidence !== []) {
                $raw['collector_binding'] = $collectorBindingEvidence;
            }
            $rowCaptureEvidence = $this->fieldFactCaptureEvidence($row, $traceId);
            if ($this->fieldFactHasDesensitizedCaptureEvidence($rowCaptureEvidence)) {
                $rowSourceUrlHash = strtolower(trim((string)($rowCaptureEvidence['source_url_hash'] ?? '')));
                $raw['source_url_hash'] = $rowSourceUrlHash;
                // `fieldFactCaptureEvidence()` is already a strict safe-field
                // projection. Keep the structured-response method/path/contract
                // beside trace/hash so exact-run review can classify the saved
                // row without reopening the original platform response.
                $raw['capture_evidence'] = array_replace($rowCaptureEvidence, [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $rowSourceUrlHash,
                ]);
            }
            $capturedAt = $this->normalizeCaptureDateTime($this->firstCaptureDateTimeValue([
                $row['collected_at'] ?? null,
                $row['collectedAt'] ?? null,
                $row['captured_at'] ?? null,
                $row['capturedAt'] ?? null,
                $payload['collected_at'] ?? null,
                $payload['collectedAt'] ?? null,
                $payload['captured_at'] ?? null,
                $payload['capturedAt'] ?? null,
            ]));
            if ($capturedAt !== null) {
                $raw['captured_at'] = $capturedAt;
            }
            if (($platformIdentifierEvidence['present'] ?? false) === true) {
                $raw['platform_hotel_identifier_present'] = true;
                $raw['platform_hotel_identifier_source'] = (string)$platformIdentifierEvidence['source'];
                $raw['platform_hotel_identifier_proof'] = (string)$platformIdentifierEvidence['proof'];
            }
            $bindingEvidence = is_array($payload['_ota_binding_evidence'] ?? null)
                ? $payload['_ota_binding_evidence']
                : [];
            if ($bindingEvidence !== []) {
                $raw['platform_hotel_binding_status'] = (string)($bindingEvidence['status'] ?? 'unverified');
                $raw['platform_hotel_binding_proof'] = (string)($bindingEvidence['proof'] ?? 'missing');
            }
            $rowDateSource = $this->stringValue($row, ['date_source', 'dateSource', 'data_date_source', 'dataDateSource', '_date_source', '_data_date_source']);
            if ($rowDateSource !== '') {
                $raw['date_source'] = $rowDateSource;
            }

            $normalizedHotelId = $this->stringValue($row, ['hotel_id', 'hotelId', 'poi_id', 'poiId', 'external_hotel_id']) ?: (string)($source['external_hotel_id'] ?? '');
            $normalizedCompareType = $this->stringValue($row, ['compare_type', 'compareType', 'rank_type', 'rankType']);
            if ($normalizedHotelId === '-1') {
                $normalizedCompareType = 'competitor_avg';
            }

            $isCtripCheckoutOverview = $this->isCtripCheckoutOverviewRow($row, $platform, $dataType);
            $isDirectCtripCheckoutOverview = $isCtripCheckoutOverview
                && !str_starts_with(strtolower(trim((string)($row['dimension'] ?? ''))), 'catalog:');
            $normalizedRow = [
                'hotel_id' => $normalizedHotelId,
                'hotel_name' => $this->stringValue($row, ['hotel_name', 'hotelName', 'poi_name', 'poiName', 'name']) ?: (string)($source['hotel_name'] ?? $source['name'] ?? ''),
                'data_date' => $date,
                // Ctrip exposes checkout and booking families in the same
                // response. The verified target-day revenue contract is the
                // checkout pair amount/quantity; book* remains a distinct
                // booking scope and must not overwrite this daily fact.
                'amount' => $isCtripCheckoutOverview
                    ? $this->nullableNumericValue($row, ['amount'])
                    : $this->amountValue($row, $dataType, $preserveMissingMetrics),
                'quantity' => $isCtripCheckoutOverview
                    ? $this->nullableRoundedInteger($row, ['quantity'])
                    : $this->quantityValue($row, $dataType, $preserveMissingMetrics),
                'book_order_num' => $isCtripCheckoutOverview
                    ? null
                    : $this->orderCountValue($row, $dataType, $preserveMissingMetrics),
                'comment_score' => $this->commentScoreValue($row, $dataType, $preserveMissingMetrics),
                'qunar_comment_score' => $this->nullableNumericValue($row, ['qunar_comment_score', 'qunar_score']),
                'system_hotel_id' => (int)($source['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0) ?: null,
                'tenant_id' => $tenantId,
                'data_value' => $this->dataValue($row, $dataType, $preserveMissingMetrics),
                'source' => $platform,
                'dimension' => $this->stringValue($row, ['dimension', 'dim_name', '_dimName']) ?: ($dataType === 'review' ? $this->reviewDimensionValue($sanitizedRow) : ''),
                'data_type' => $dataType,
                'platform' => $this->stringValue($row, ['platform']) ?: $platform,
                'compare_type' => $normalizedCompareType,
                'list_exposure' => $isCtripCheckoutOverview
                    ? null
                    : $this->integerMetricValue($row, ['mt_exposure', 'list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv'], $preserveMissingMetrics),
                'detail_exposure' => $isCtripCheckoutOverview
                    ? null
                    : $this->integerMetricValue($row, ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount', 'visitors', 'visitorTotal', 'pv', 'uv'], $preserveMissingMetrics),
                'flow_rate' => $isCtripCheckoutOverview
                    ? null
                    : $this->flowRateValue($row, $dataType, $preserveMissingMetrics),
                'order_filling_num' => $isCtripCheckoutOverview
                    ? null
                    : $this->integerMetricValue($row, ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'clickCount', 'clicks'], $preserveMissingMetrics),
                'order_submit_num' => $isCtripCheckoutOverview
                    ? null
                    : $this->integerMetricValue($row, ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'order_submit_num', 'orderSubmitNum', 'bookings', 'bookingCount', 'orderCount', 'orderQuantity', 'orderNum', 'orders'], $preserveMissingMetrics),
                'validation_status' => $reviewValidationStatus,
                'validation_flags' => '[]',
                'data_source_id' => isset($source['id']) ? (int)$source['id'] : null,
                'sync_task_id' => $syncTaskId,
                'ingestion_method' => (string)($source['ingestion_method'] ?? 'manual'),
                'source_trace_id' => $traceId,
                'data_period' => $periodMeta['data_period'],
                'snapshot_time' => $periodMeta['snapshot_time'],
                'snapshot_bucket' => $periodMeta['snapshot_bucket'],
                'is_final' => $periodMeta['is_final'],
            ];

            $fieldFacts = $this->buildNormalizedFieldFacts(
                $row,
                $dataType,
                $normalizedRow,
                $traceId,
                $isCtripCheckoutOverview,
                $platform,
                $isDirectCtripCheckoutOverview ? 'checkout' : ''
            );
            if ($fieldFacts !== []) {
                $raw['field_facts'] = $fieldFacts;
                $raw['field_fact_summary'] = $this->summarizeNormalizedFieldFacts($fieldFacts);
                foreach ($fieldFacts as $fieldFact) {
                    if (!is_array($fieldFact)
                        || (string)($fieldFact['missing_state'] ?? '') !== 'field_missing'
                        || (($fieldFact['status'] ?? '') === 'captured' && ($fieldFact['stored_value_present'] ?? false) === true)
                    ) {
                        continue;
                    }
                    $metricKey = trim((string)($fieldFact['metric_key'] ?? ''));
                    if ($metricKey !== '') {
                        $validationFlags[] = 'field_missing:' . $metricKey;
                    }
                }
            }
            $browserAssistBindingReady = $this->isOtaBrowserAssistSource($source)
                && ($bindingEvidence['status'] ?? '') === 'operator_confirmed'
                && ($bindingEvidence['proof'] ?? '') === 'authenticated_page_header';
            $isManualImportSource = $this->isManualImportSource($source);
            $isGenericOtaSource = $this->isOtaPlatform($platform)
                && !$this->isOtaBrowserProfileSource($source)
                && !$this->isOtaLocalCollectorSource($source)
                && !$browserAssistBindingReady;
            if ($isManualImportSource) {
                $validationFlags[] = 'manual_import_provenance_unverified';
            }
            if ($isGenericOtaSource) {
                $validationFlags[] = 'source_ingestion_method_unverified';
                if (($bindingEvidence['status'] ?? '') !== 'matched') {
                    $validationFlags[] = 'hotel_binding_unverified';
                }
            }
            $validationFlags = array_values(array_unique($validationFlags));
            $normalizedRow['validation_status'] = in_array($reviewValidationStatus, ['abnormal', 'quarantined', 'stale'], true)
                ? $reviewValidationStatus
                : ($isManualImportSource
                    ? 'unverified'
                    : ($isGenericOtaSource
                        ? 'unverified'
                        : (array_filter($validationFlags, static fn(string $flag): bool => str_starts_with($flag, 'field_missing:')) !== []
                            ? 'partial'
                            : $reviewValidationStatus)));
            $normalizedRow['validation_flags'] = json_encode($validationFlags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $normalizedRow['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $normalizedRow['persistence_identity_hash'] = $this->persistenceIdentityHash($normalizedRow);
            $normalized[] = $normalizedRow;

            if ($isDirectCtripCheckoutOverview) {
                $bookingProjection = $this->buildCtripMarketOverviewBookingProjection(
                    $row,
                    $normalizedRow,
                    $raw,
                    $traceId,
                    $platform
                );
                if ($bookingProjection !== null) {
                    $normalized[] = $bookingProjection;
                }
            }
        }

        return $normalized;
    }

    /**
     * Ctrip catalog rows historically initialized every structured column to
     * zero before applying facts. Treat the collector's per-field facts as
     * authoritative so an unsupported placeholder cannot become captured 0.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function reconcileCtripCatalogStructuredMetricFacts(array $row, array $source): array
    {
        if ((!$this->isOtaBrowserProfileSource($source) && !$this->isOtaLocalCollectorSource($source))
            || strtolower(trim((string)($source['platform'] ?? $row['platform'] ?? $row['source'] ?? ''))) !== 'ctrip'
        ) {
            return $row;
        }

        $rawData = $row['raw_data'] ?? null;
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rawData)
            || strtolower(trim((string)($rawData['source'] ?? ''))) !== 'ctrip_catalog_facts'
            || !is_array($rawData['field_facts'] ?? null)
        ) {
            return $row;
        }

        $structuredFields = [
            'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        ];
        $structuredFieldMap = array_fill_keys($structuredFields, true);
        $capturedFields = [];
        foreach ($rawData['field_facts'] as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? false) !== true
            ) {
                continue;
            }
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            if (str_starts_with($storageField, 'online_daily_data.')) {
                $storageField = substr($storageField, strlen('online_daily_data.'));
            }
            if (isset($structuredFieldMap[$storageField])) {
                $capturedFields[$storageField] = true;
            }
        }

        foreach ($structuredFields as $field) {
            $value = $row[$field] ?? null;
            $placeholder = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_numeric($value) && (float)$value === 0.0);
            if (!isset($capturedFields[$field]) && $placeholder) {
                $row[$field] = null;
            }
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $normalizedRow
     * @return array<int, array<string, mixed>>
     */
    private function buildNormalizedFieldFacts(
        array $row,
        string $dataType,
        array $normalizedRow,
        string $rowSourceTraceId = '',
        bool $strictCtripCheckoutFields = false,
        string $platform = '',
        string $ctripMarketOverviewMetricFamily = ''
    ): array
    {
        $dataType = $this->normalizeDataType($dataType);
        $definitions = self::NORMALIZED_FIELD_FACT_DEFINITIONS[$dataType] ?? [];
        if ($definitions === []) {
            return [];
        }

        $facts = [];
        foreach ($definitions as $definition) {
            $metricKey = strtolower(trim((string)($definition['metric_key'] ?? '')));
            if (strtolower(trim($platform)) !== 'meituan'
                && (
                    str_starts_with($metricKey, 'mt_')
                    || str_starts_with($metricKey, 'meituan_')
                )
            ) {
                continue;
            }
            $sourceKeys = is_array($definition['source_keys'] ?? null) ? $definition['source_keys'] : [];
            $missingState = (string)$definition['missing_state'];
            if ($ctripMarketOverviewMetricFamily === 'booking') {
                $sourceKeys = $metricKey === 'order_count' ? ['bookOrderNum'] : [];
                if ($metricKey !== 'order_count') {
                    $missingState = 'optional_missing';
                }
            } elseif ($strictCtripCheckoutFields) {
                $sourceKeys = match ((string)($definition['metric_key'] ?? '')) {
                    'order_amount' => ['amount'],
                    'room_nights' => ['quantity'],
                    'order_count' => [],
                    default => $sourceKeys,
                };
                if ($ctripMarketOverviewMetricFamily === 'checkout' && $metricKey === 'order_count') {
                    $missingState = 'optional_missing';
                }
            }
            $sourceKey = $this->firstPresentSourceKey($row, $sourceKeys);
            $normalizedField = (string)($definition['normalized_field'] ?? '');
            $status = $sourceKey !== '' ? 'captured' : 'missing';
            $fact = [
                'metric_key' => (string)$definition['metric_key'],
                'data_type' => $dataType,
                'source_key' => $sourceKey,
                'source_path' => $sourceKey !== '' ? $this->fieldFactSourcePath($row, $sourceKey) : '',
                'storage_table' => (string)$definition['storage_table'],
                'storage_field' => (string)$definition['storage_field'],
                'normalized_field' => $normalizedField,
                'status' => $status,
                'missing_state' => $missingState,
                'stored_value_present' => $sourceKey !== '' && (
                    str_starts_with((string)($definition['storage_field'] ?? ''), 'raw_data')
                    || $this->normalizedFieldHasStoredValue($normalizedRow, $normalizedField)
                ),
            ];
            $fact['storage_field'] = $this->normalizedStorageField($definition);
            if ($sourceKey !== '') {
                $fact['capture_evidence'] = $this->fieldFactCaptureEvidence($row, $rowSourceTraceId);
            }
            $facts[] = $fact;
        }

        return $facts;
    }

    /**
     * The market-overview response contains two different metric families:
     * checkout amount/room nights and booking order count. Persist the latter
     * as its own fact row so downstream revenue aggregation cannot imply that
     * bookOrderNum shares the checkout grain merely because the API returned
     * both families in one object.
     *
     * @param array<string,mixed> $sourceRow
     * @param array<string,mixed> $checkoutRow
     * @param array<string,mixed> $checkoutRaw
     * @return array<string,mixed>|null
     */
    private function buildCtripMarketOverviewBookingProjection(
        array $sourceRow,
        array $checkoutRow,
        array $checkoutRaw,
        string $traceId,
        string $platform
    ): ?array {
        if (!array_key_exists('bookOrderNum', $sourceRow)) {
            return null;
        }
        $value = $this->nullableNumericValue($sourceRow, ['bookOrderNum']);
        if ($value === null || $value < 0.0 || floor($value) !== $value) {
            return null;
        }

        $projection = $checkoutRow;
        foreach ([
            'amount', 'quantity', 'data_value', 'list_exposure', 'detail_exposure',
            'flow_rate', 'order_filling_num', 'order_submit_num',
        ] as $field) {
            $projection[$field] = null;
        }
        $projection['book_order_num'] = (int)$value;
        $projection['dimension'] = 'semantic:ctrip_business_market_overview:booking_order_count';

        $facts = $this->buildNormalizedFieldFacts(
            $sourceRow,
            'business',
            $projection,
            $traceId,
            false,
            $platform,
            'booking'
        );
        foreach ($facts as &$fact) {
            if (($fact['metric_key'] ?? '') !== 'order_count') {
                continue;
            }
            $fact['semantic_contract_version'] = 'ota_metric_semantic_binding.v1';
            $fact['semantic_key'] = 'ctrip_market_overview_booking_order_count';
            $fact['unit'] = 'orders';
            $fact['value_type'] = 'non_negative_integer';
            $fact['source_endpoint_id'] = 'business_market_overview';
        }
        unset($fact);

        $raw = $checkoutRaw;
        $raw['row'] = $this->ctripMarketOverviewBookingSourceRow(
            is_array($checkoutRaw['row'] ?? null) ? $checkoutRaw['row'] : $sourceRow
        );
        $orderFact = null;
        foreach ($facts as $fact) {
            if (($fact['metric_key'] ?? '') === 'order_count') {
                $orderFact = $fact;
                break;
            }
        }
        if (!is_array($orderFact)
            || ($orderFact['status'] ?? '') !== 'captured'
            || ($orderFact['stored_value_present'] ?? false) !== true
        ) {
            return null;
        }
        $raw['field_facts'] = $facts;
        $raw['field_fact_summary'] = $this->summarizeNormalizedFieldFacts($facts);
        $raw['metric_projection'] = [
            'contract_version' => 'ctrip_market_overview_metric_projection.v1',
            'metric_family' => 'booking',
            'metric_key' => 'order_count',
            'semantic_key' => 'ctrip_market_overview_booking_order_count',
            'unit' => 'orders',
            'source_endpoint_id' => 'business_market_overview',
            'source_key' => 'bookOrderNum',
            'source_path' => (string)($orderFact['source_path'] ?? ''),
            'business_date' => (string)($projection['data_date'] ?? ''),
            'separate_from_metric_family' => 'checkout',
        ];
        $projection['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $projection['persistence_identity_hash'] = $this->persistenceIdentityHash($projection);

        return $projection;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function ctripMarketOverviewBookingSourceRow(array $row): array
    {
        $allowed = [
            'hotel_id', 'hotelId', 'masterHotelId', 'master_hotel_id', 'system_hotel_id',
            'hotel_name', 'hotelName', 'data_date', 'dataDate', 'date', 'date_source',
            'data_type', 'platform', 'source', 'endpoint_id', 'section', 'dimension',
            'bookOrderNum', '_source_path', '_capture_source', 'source_trace_id',
            'source_url_hash', 'capture_evidence',
        ];

        return array_intersect_key($row, array_fill_keys($allowed, true));
    }

    /** @param array<string, mixed> $row */
    private function isCtripCheckoutOverviewRow(array $row, string $platform, string $dataType): bool
    {
        if (strtolower(trim($platform)) !== 'ctrip' || $this->normalizeDataType($dataType) !== 'business') {
            return false;
        }

        return strtolower(trim((string)($row['endpoint_id'] ?? ''))) === 'business_market_overview'
            && strtolower(trim((string)($row['section'] ?? ''))) === 'business_overview';
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private function nullableRoundedInteger(array $row, array $keys): ?int
    {
        $value = $this->nullableNumericValue($row, $keys);
        return $value === null ? null : (int)round($value);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function normalizedStorageField(array $definition): string
    {
        $table = trim((string)($definition['storage_table'] ?? ''));
        $field = trim((string)($definition['storage_field'] ?? ''));
        if ($table === 'online_daily_data'
            && $field !== ''
            && !str_contains($field, '.')
            && !str_contains($field, '/')
            && !str_contains($field, ' ')
        ) {
            return $table . '.' . $field;
        }
        return $field;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $source
     * @return array{present: bool, source: string, proof: string}
     */
    private function platformHotelIdentifierEvidence(string $platform, array $row, array $source): array
    {
        $sourceFamily = strtolower(trim($platform)) === 'meituan' ? 'poi_id_family' : 'hotel_id_family';
        $keys = strtolower(trim($platform)) === 'meituan'
            ? ['poiId', 'poi_id', 'storeId', 'store_id', 'shopId', 'shop_id', 'mtPoiId', 'mt_poi_id', 'partnerId', 'partner_id']
            : ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'masterHotelId', 'master_hotel_id', 'nodeId', 'node_id', 'ctrip_hotel_id', 'external_hotel_id'];
        $sourceConfig = is_array($source['config'] ?? null)
            ? (array)$source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        foreach ([
            'row_field_present' => $row,
            'source_field_present' => $source,
            'source_config_field_present' => $sourceConfig,
        ] as $proof => $candidate) {
            $identifier = trim($this->stringValue($candidate, $keys));
            if ($identifier !== ''
                && !in_array(
                    strtolower($identifier),
                    ['-1', '0', 'null', 'unknown', 'n/a'],
                    true
                )
            ) {
                return [
                    'present' => true,
                    'source' => $sourceFamily,
                    'proof' => $proof,
                ];
            }
        }

        return [
            'present' => false,
            'source' => $sourceFamily,
            'proof' => 'missing',
        ];
    }

    /**
     * Generic API/manual OTA sources do not have the Profile adapters' own
     * merchant-identity gate. Require explicit response evidence before any
     * raw or normalized row is persisted under a system hotel.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function assertGenericOtaPayloadBinding(array $source, array $payload): array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if (!$this->isOtaPlatform($platform) || $this->isOtaBrowserProfileSource($source)) {
            return [];
        }
        if ($this->isOtaBrowserAssistSource($source)) {
            return $this->assertBrowserAssistPayloadBinding($source, $payload);
        }
        if ($this->isManualImportSource($source)) {
            $sourceHotelId = (int)($source['system_hotel_id'] ?? 0);
            if ($sourceHotelId <= 0) {
                throw new RuntimeException('manual_import_system_hotel_binding_missing', 422);
            }
            $rows = $this->extractBusinessRows($payload);
            if ($rows === []) {
                throw new RuntimeException('manual_import_business_rows_missing', 422);
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowHotelId = (int)($row['system_hotel_id'] ?? 0);
                if ($rowHotelId > 0 && $rowHotelId !== $sourceHotelId) {
                    throw new RuntimeException('manual_import_system_hotel_binding_mismatch', 409);
                }
                $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
                if ($rowPlatform !== '' && $rowPlatform !== $platform) {
                    throw new RuntimeException('manual_import_platform_binding_mismatch', 409);
                }
            }
            return [
                'status' => 'user_selected_system_hotel',
                'proof' => 'tenant_scoped_manual_import_source',
            ];
        }

        $keys = $this->otaHotelIdentifierKeys($platform);
        $config = is_array($source['config'] ?? null)
            ? (array)$source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $expected = $this->stringValue($source, $keys);
        if ($expected === '') {
            $expected = $this->stringValue($config, $keys);
        }
        if ($expected === '') {
            throw new RuntimeException('binding_missing', 422);
        }

        $identityValidation = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $identityStatus = strtolower(trim((string)($identityValidation['status'] ?? '')));
        $validatedIdentifier = trim((string)($identityValidation['validated_identifier'] ?? ''));
        if ($identityStatus === 'mismatch') {
            throw new RuntimeException('binding_mismatch', 409);
        }
        if ($identityStatus === 'matched') {
            if (($identityValidation['source_validation'] ?? false) !== true || $validatedIdentifier === '') {
                throw new RuntimeException('binding_unverified', 422);
            }
            if (!$this->otaHotelIdentifiersMatch($expected, $validatedIdentifier)) {
                throw new RuntimeException('binding_mismatch', 409);
            }
            return ['status' => 'matched', 'proof' => 'platform_identity_validation'];
        }

        // A local collector row can contain request-scoped hotel identifiers.
        // Only the collector adapter's response-derived identity proof may bind
        // those rows to a system hotel; never treat a row echo as that proof.
        if ($this->isOtaLocalCollectorSource($source)) {
            throw new RuntimeException('binding_unverified', 422);
        }

        $observed = [];
        foreach ($this->extractBusinessRows($payload) as $row) {
            if (!is_array($row) || $this->isCompetitorOtaIdentityRow($row, $keys)) {
                continue;
            }
            $identifier = $this->stringValue($row, $keys);
            if ($identifier !== '') {
                $observed[strtolower($identifier)] = $identifier;
            }
        }
        $observed = array_values($observed);
        if ($observed === []) {
            throw new RuntimeException('binding_unverified', 422);
        }
        if (count($observed) !== 1 || !$this->otaHotelIdentifiersMatch($expected, $observed[0])) {
            throw new RuntimeException('binding_mismatch', 409);
        }

        return ['status' => 'matched', 'proof' => 'single_self_row_identifier'];
    }

    /**
     * Browser assist has no reusable credential or response-derived hotel id.
     * Accept only an explicit operator-confirmed mapping from an authenticated
     * page header, bound to the authoritative hotel and exact row date.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     * @return array{status:string,proof:string}
     */
    private function assertBrowserAssistPayloadBinding(array $source, array $payload): array
    {
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = (int)($source['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0) {
            throw new RuntimeException('binding_missing', 422);
        }
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->field('id,name')
            ->find();
        if (!is_array($hotel) || trim((string)($hotel['name'] ?? '')) === '') {
            throw new RuntimeException('binding_missing', 422);
        }
        $rows = $this->extractBusinessRows($payload);
        if ($rows === []) {
            throw new RuntimeException('binding_unverified', 422);
        }
        $observedNames = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || trim((string)($row['data_date'] ?? '')) === ''
            ) {
                throw new RuntimeException('binding_mismatch', 409);
            }
            $identity = is_array($row['browser_assist_identity'] ?? null)
                ? $row['browser_assist_identity']
                : [];
            $confirmedAt = trim((string)($identity['confirmed_at'] ?? ''));
            $dataDate = trim((string)$row['data_date']);
            if ((string)($identity['status'] ?? '') !== 'operator_confirmed'
                || (string)($identity['evidence_type'] ?? '') !== 'authenticated_page_header'
                || (string)($identity['source_contract'] ?? '') !== 'ota_browser_assist_collection_contract.v1'
                || (int)($identity['system_hotel_id'] ?? 0) !== $hotelId
                || trim((string)($identity['expected_hotel_name'] ?? ''))
                    !== trim((string)$hotel['name'])
                || trim((string)($identity['observed_hotel_name'] ?? '')) === ''
                || substr($confirmedAt, 0, 10) !== $dataDate
            ) {
                throw new RuntimeException('binding_unverified', 422);
            }
            $observedNames[trim((string)$identity['observed_hotel_name'])] = true;
        }
        if (count($observedNames) !== 1) {
            throw new RuntimeException('binding_mismatch', 409);
        }

        return [
            'status' => 'operator_confirmed',
            'proof' => 'authenticated_page_header',
        ];
    }

    /** @return array<int, string> */
    private function otaHotelIdentifierKeys(string $platform): array
    {
        return strtolower(trim($platform)) === 'meituan'
            ? ['platform_hotel_id', 'external_hotel_id', 'poi_id', 'poiId', 'store_id', 'storeId']
            : ['platform_hotel_id', 'external_hotel_id', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_id', 'hotelId', 'node_id', 'nodeId'];
    }

    /** @param array<string, mixed> $row @param array<int, string> $identifierKeys */
    private function isCompetitorOtaIdentityRow(array $row, array $identifierKeys): bool
    {
        $compareType = strtolower($this->stringValue($row, ['compare_type', 'compareType', 'rank_type', 'rankType']));
        if (in_array($compareType, ['competitor', 'competitor_avg', 'peer', 'peer_avg', 'competition_circle'], true)) {
            return true;
        }
        $identifier = $this->stringValue($row, $identifierKeys);
        if ($identifier === '-1') {
            return true;
        }
        foreach (['is_self', 'isSelf'] as $key) {
            if (array_key_exists($key, $row) && !$this->truthy($row[$key])) {
                return true;
            }
        }
        return false;
    }

    private function otaHotelIdentifiersMatch(string $expected, string $observed): bool
    {
        return strtolower(trim($expected)) === strtolower(trim($observed));
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function summarizeNormalizedFieldFacts(array $facts): array
    {
        $captured = [];
        $missing = [];
        $captureEvidenceCount = 0;
        $desensitizedCaptureEvidenceCount = 0;
        foreach ($facts as $fact) {
            $metricKey = trim((string)($fact['metric_key'] ?? ''));
            if ($metricKey === '') {
                continue;
            }
            $captureEvidence = $fact['capture_evidence'] ?? null;
            if ((is_array($captureEvidence) && $captureEvidence !== [])
                || (is_scalar($captureEvidence) && trim((string)$captureEvidence) !== '')
            ) {
                $captureEvidenceCount++;
            }
            if (is_array($captureEvidence) && $this->fieldFactHasDesensitizedCaptureEvidence($captureEvidence)) {
                $desensitizedCaptureEvidenceCount++;
            }
            if (($fact['status'] ?? '') === 'captured') {
                $captured[] = $metricKey;
            } else {
                $missing[] = $metricKey;
            }
        }

        return [
            'captured_count' => count($captured),
            'missing_count' => count($missing),
            'capture_evidence_count' => $captureEvidenceCount,
            'desensitized_capture_evidence_count' => $desensitizedCaptureEvidenceCount,
            'captured_metric_keys' => array_values(array_unique($captured)),
            'missing_metric_keys' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @param array<string, mixed> $captureEvidence
     */
    private function fieldFactHasDesensitizedCaptureEvidence(array $captureEvidence): bool
    {
        $traceId = trim((string)($captureEvidence['source_trace_id'] ?? $captureEvidence['_source_trace_id'] ?? ''));
        $sourceUrlHash = trim((string)($captureEvidence['source_url_hash'] ?? $captureEvidence['_source_url_hash'] ?? $captureEvidence['url_hash'] ?? $captureEvidence['_url_hash'] ?? ''));

        return $traceId !== '' && preg_match('/^[a-f0-9]{64}$/iD', $sourceUrlHash) === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, mixed> $sourceKeys
     */
    private function firstPresentSourceKey(array $row, array $sourceKeys): string
    {
        foreach ($sourceKeys as $key) {
            $key = (string)$key;
            if ($key === '' || !array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            if (is_array($value) && $value === []) {
                continue;
            }
            return $key;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fieldFactSourcePath(array $row, string $sourceKey): string
    {
        $basePath = trim((string)($row['_source_path'] ?? $row['source_path'] ?? $row['sourcePath'] ?? $row['json_path'] ?? $row['jsonPath'] ?? ''));
        if ($basePath === '') {
            $basePath = trim((string)($row['_capture_source'] ?? ''));
        }
        $sourceKey = trim($sourceKey);
        if ($basePath === '') {
            return $sourceKey === '' ? '' : '$.' . $sourceKey;
        }
        if ($sourceKey === '') {
            return $basePath;
        }
        return rtrim($basePath, '.') . '.' . $sourceKey;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fieldFactCaptureEvidence(array $row, string $rowSourceTraceId = ''): array
    {
        $evidence = [];
        foreach (['_source_path', '_capture_source'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && trim((string)$row[$key]) !== '') {
                $evidence[ltrim($key, '_')] = mb_substr((string)$row[$key], 0, 300);
            }
        }
        if (is_array($row['capture_evidence'] ?? null)) {
            $this->appendSafeFieldFactCaptureEvidence($evidence, (array)$row['capture_evidence']);
        }
        $this->appendSafeFieldFactCaptureEvidence($evidence, $row);
        if (isset($row['_source_url']) && is_scalar($row['_source_url']) && trim((string)$row['_source_url']) !== '') {
            $evidence['source_url_hash'] = hash('sha256', (string)$row['_source_url']);
        }
        if ($rowSourceTraceId !== '') {
            $evidence['source_trace_id'] = mb_substr($rowSourceTraceId, 0, 300);
        }
        return $evidence;
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $row
     */
    private function appendSafeFieldFactCaptureEvidence(array &$evidence, array $row): void
    {
        $aliases = [
            'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
            'request_hash' => ['request_hash', '_request_hash'],
            'payload_hash' => ['payload_hash', '_payload_hash'],
            'method' => ['method', 'http_method', '_method'],
            'source_path' => ['source_path', '_source_path', 'json_path'],
            'capture_source' => ['capture_source', '_capture_source'],
            'capture_strategy' => ['capture_strategy'],
            'response_evidence_type' => ['response_evidence_type'],
            'contract_version' => ['contract_version'],
        ];
        foreach ($aliases as $target => $keys) {
            if (isset($evidence[$target]) && $this->safeFieldFactCaptureEvidenceValue($evidence[$target]) !== '') {
                continue;
            }
            foreach ($keys as $key) {
                $value = $this->safeFieldFactCaptureEvidenceValue($row[$key] ?? null);
                if ($value !== '') {
                    $evidence[$target] = $value;
                    break;
                }
            }
        }
    }

    private function safeFieldFactCaptureEvidenceValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $text = trim((string)$value);
        if ($text === ''
            || preg_match('/\b(cookie|authorization|bearer|token|password|secret)\b/i', $text)
        ) {
            return '';
        }
        return mb_substr($text, 0, 300);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function normalizedFieldHasStoredValue(array $row, string $field): bool
    {
        if ($field === '' || !array_key_exists($field, $row)) {
            return false;
        }
        $value = $row[$field];
        if ($value === null) {
            return false;
        }
        if (is_numeric($value)) {
            return true;
        }
        return trim((string)$value) !== '';
    }

    public function listDataSources($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sources')->withoutField('secret_json')->order('id', 'desc');
        $this->applySourceScope($query, $user);
        if (!empty($filters['platform'])) {
            $query->where('platform', (string)$filters['platform']);
        }
        if (!empty($filters['data_type'])) {
            $query->where('data_type', (string)$filters['data_type']);
        }
        if (!empty($filters['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
        }
        $rows = $query->select()->toArray();
        if (!$user->isSuperAdmin()) {
            $rows = array_values(array_filter($rows, function (array $row) use ($user): bool {
                try {
                    $this->assertStoredSourceTenantForActor($row, $user);
                    return true;
                } catch (RuntimeException) {
                    return false;
                }
            }));
        }
        $customIds = [];
        foreach ($rows as $row) {
            if (!$this->isOtaPlatform((string)($row['platform'] ?? '')) && (int)($row['id'] ?? 0) > 0) {
                $customIds[] = (int)$row['id'];
            }
        }
        $customSecrets = [];
        if ($customIds !== []) {
            $secretQuery = Db::name('platform_data_sources')->field('id,secret_json')->whereIn('id', $customIds);
            $this->applySourceScope($secretQuery, $user);
            foreach ($secretQuery->select()->toArray() as $secretRow) {
                $customSecrets[(int)($secretRow['id'] ?? 0)] = $secretRow['secret_json'] ?? null;
            }
        }
        foreach ($rows as &$row) {
            $rowId = (int)($row['id'] ?? 0);
            if (array_key_exists($rowId, $customSecrets)) {
                $row['secret_json'] = $customSecrets[$rowId];
            }
        }
        unset($row);
        return array_map([$this, 'sanitizeSourceRow'], $rows);
    }

    public function saveDataSource($user, array $payload): array
    {
        return $this->saveDataSourceInternal($user, $payload, false);
    }

    /**
     * Internal collector-only write path. The public data-source API must not
     * be able to promote user-supplied metadata into verified hotel identity
     * evidence.
     */
    public function saveVerifiedLocalCollectorDataSource($user, array $payload): array
    {
        $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
        $identitySource = trim((string)($config['platform_hotel_identity_source'] ?? ''));
        $identityCheckedAt = trim((string)($config['platform_hotel_identity_checked_at'] ?? ''));
        $identityCheckedTimestamp = $identityCheckedAt !== '' ? strtotime($identityCheckedAt) : false;
        if (strtolower(trim((string)($payload['ingestion_method'] ?? ''))) !== 'local_collector'
            || $identitySource !== 'local_collector_verified_capture'
            || $identityCheckedTimestamp === false
            || abs(time() - $identityCheckedTimestamp) > 300
            || ($config['current_session_verified'] ?? false) !== true
            || trim((string)($config['platform_hotel_id'] ?? '')) === ''
        ) {
            throw new RuntimeException('Verified local collector identity evidence is invalid.', 422);
        }
        return $this->saveDataSourceInternal($user, $payload, true);
    }

    private function saveDataSourceInternal($user, array $payload, bool $allowManagedLocalIdentityEvidence): array
    {
        $id = (int)($payload['id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $existingQuery = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $id);
            $this->applySourceTenantScope($existingQuery, $user);
            $existing = $existingQuery->find();
            if (!$existing) {
                throw new RuntimeException('Data source not found.', 404);
            }
            $this->assertStoredSourceTenantForActor($existing, $user);
            $this->assertCanUseHotel($user, (int)($existing['system_hotel_id'] ?? 0), 'can_fetch_online_data');
        }

        $source = $this->normalizeSourcePayload($payload);
        $this->assertCanUseHotel($user, (int)$source['system_hotel_id'], 'can_fetch_online_data');

        if ($this->isOtaPlatform((string)$source['platform'])) {
            return $this->saveOtaDataSource(
                $user,
                $source,
                $existing,
                $id,
                $allowManagedLocalIdentityEvidence
            );
        }

        $hasSecretInput = $this->credentialPayloadHasValue($source['secret']);
        $now = date('Y-m-d H:i:s');
        $data = [
            'system_hotel_id' => $source['system_hotel_id'],
            'user_id' => (int)($user->id ?? 0) ?: null,
            'name' => $source['name'],
            'platform' => $source['platform'],
            'data_type' => $source['data_type'],
            'ingestion_method' => $source['ingestion_method'],
            'status' => $source['status'],
            'enabled' => $source['enabled'],
            'config_json' => json_encode($source['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => json_encode($source['secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => (int)($user->id ?? 0) ?: null,
            'update_time' => $now,
        ];
        $targetTenantId = $this->resolveHotelTenantId((int)$source['system_hotel_id']);
        $data['tenant_id'] = $targetTenantId;

        if ($id > 0) {
            if (!$hasSecretInput) {
                unset($data['secret_json']);
            }
            $updateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($updateQuery, $existing);
            $updateQuery->update($data);
        } else {
            $data['created_by'] = (int)($user->id ?? 0) ?: null;
            $data['create_time'] = $now;
            $id = (int)Db::name('platform_data_sources')->insertGetId($data);
        }

        $row = Db::name('platform_data_sources')
            ->where('id', $id)
            ->where('tenant_id', $targetTenantId)
            ->where('system_hotel_id', (int)$source['system_hotel_id'])
            ->find();
        return $this->sanitizeSourceRow($row ?: []);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function saveOtaDataSource(
        $user,
        array $source,
        ?array $existing,
        int $id,
        bool $allowManagedLocalIdentityEvidence = false
    ): array
    {
        $hotelId = (int)$source['system_hotel_id'];
        $tenantId = $this->resolveHotelTenantId($hotelId);
        $platform = strtolower((string)$source['platform']);
        $existingConfig = $existing ? $this->decodeConfig($existing['config_json'] ?? []) : [];
        $secretPayload = $this->normalizeOtaCredentialPayload($source['secret']);
        $hasSecretInput = $this->credentialPayloadHasValue($secretPayload);
        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $isBrowserProfile = in_array(
            $ingestionMethod,
            ['browser_profile', 'profile_browser'],
            true
        );
        $isLocalCollector = $ingestionMethod === 'local_collector';
        $isBrowserAssist = $ingestionMethod === 'browser_assist_dom';
        $isCredentiallessSource = $isBrowserProfile || $isLocalCollector || $isBrowserAssist;
        if ($existing) {
            $existingMethod = strtolower(trim((string)($existing['ingestion_method'] ?? '')));
            $existingAuthorizationModel = in_array($existingMethod, ['browser_profile', 'profile_browser'], true)
                ? 'browser_profile'
                : ($existingMethod === 'local_collector'
                    ? 'local_collector'
                    : ($existingMethod === 'browser_assist_dom' ? 'browser_assist_dom' : 'credential_vault'));
            $authorizationModel = $isBrowserProfile
                ? 'browser_profile'
                : ($isLocalCollector
                    ? 'local_collector'
                    : ($isBrowserAssist ? 'browser_assist_dom' : 'credential_vault'));
            if ($existingAuthorizationModel !== $authorizationModel) {
                throw new RuntimeException(
                    'OTA data source cannot switch authorization model in place; create a separate data source.',
                    422
                );
            }
        }
        if ($isCredentiallessSource && $hasSecretInput) {
            throw new RuntimeException(
                $isBrowserProfile
                    ? 'Browser Profile data source must not store reusable OTA credentials; use a separate API/manual source.'
                    : ($isLocalCollector
                        ? 'Local collector data source must not store reusable OTA credentials; keep the session on the account owner device.'
                        : 'Browser assist data source must not store reusable OTA credentials.'),
                422
            );
        }
        if (!$isCredentiallessSource && !$existing && !$hasSecretInput) {
            throw new RuntimeException('New OTA data source requires a reusable credential.', 422);
        }
        $configId = $this->resolveOtaDataSourceConfigId($source['config'], $existingConfig, $platform, $id);

        if ($existing && !$hasSecretInput) {
            $existingPlatform = strtolower(trim((string)($existing['platform'] ?? '')));
            $existingHotelId = (int)($existing['system_hotel_id'] ?? 0);
            $existingConfigId = trim((string)($existingConfig['config_id'] ?? ''));
            $locatorMismatch = $existingConfigId === '' || $existingConfigId !== $configId;
            if ($existingPlatform !== $platform
                || $existingHotelId !== $hotelId
                || (!$isCredentiallessSource && $locatorMismatch)
                || ($isCredentiallessSource && $existingConfigId !== '' && $existingConfigId !== $configId)
            ) {
                throw new RuntimeException('Replacing an OTA credential locator requires a new credential payload.', 422);
            }
        }

        $actorId = (int)($user->id ?? 0);
        $safeConfig = $this->allowlistedOtaSourceConfig(
            $source['config'],
            $platform,
            $allowManagedLocalIdentityEvidence
        );
        if (!$allowManagedLocalIdentityEvidence) {
            $existingManagedIdentity = trim((string)($existingConfig['platform_hotel_identity_source'] ?? '')) !== ''
                && strtotime((string)($existingConfig['platform_hotel_identity_checked_at'] ?? '')) !== false;
            foreach (['platform_hotel_identity_source', 'platform_hotel_identity_checked_at'] as $managedKey) {
                if (array_key_exists($managedKey, $existingConfig)) {
                    $safeConfig[$managedKey] = $existingConfig[$managedKey];
                }
            }
            if ($existingManagedIdentity && array_key_exists('platform_hotel_id', $existingConfig)) {
                $safeConfig['platform_hotel_id'] = $existingConfig['platform_hotel_id'];
            }
        }
        $profileKey = $isBrowserProfile ? $this->otaBrowserProfileKey($platform, $safeConfig) : '';
        if ($isBrowserProfile && $profileKey === '') {
            throw new RuntimeException('Browser Profile binding key is missing.', 422);
        }
        if ($isLocalCollector) {
            $accountId = (int)($safeConfig['local_collector_account_id'] ?? 0);
            $profileKeyHash = strtolower(trim((string)($safeConfig['profile_key_hash'] ?? '')));
            $deviceIdHash = strtolower(trim((string)($safeConfig['collector_device_id_hash'] ?? '')));
            if ($accountId <= 0
                || preg_match('/^[a-f0-9]{64}$/D', $profileKeyHash) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', $deviceIdHash) !== 1
            ) {
                throw new RuntimeException('Local collector account, device or Profile proof is incomplete.', 422);
            }
        }
        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $source,
            $existing,
            $existingConfig,
            $secretPayload,
            $hasSecretInput,
            $isBrowserProfile,
            $isLocalCollector,
            $isBrowserAssist,
            $safeConfig,
            $profileKey,
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            $actorId,
            $now,
            &$id
        ): array {
            if ($isBrowserProfile && $id <= 0) {
                $reusableSource = $this->findReusableBrowserProfileSource(
                    $tenantId,
                    $hotelId,
                    $platform,
                    $profileKey
                );
                if (is_array($reusableSource)) {
                    $this->assertStoredSourceTenant($reusableSource);
                    $id = (int)($reusableSource['id'] ?? 0);
                    $existing = $reusableSource;
                    $existingConfig = $this->decodeConfig($reusableSource['config_json'] ?? []);
                    $configId = $this->resolveOtaDataSourceConfigId(
                        $source['config'],
                        $existingConfig,
                        $platform,
                        $id
                    );
                }
            }

            if ($id > 0) {
                $lockedQuery = Db::name('platform_data_sources')->withoutField('secret_json');
                $this->applyStoredSourceIdentity($lockedQuery, $existing);
                $lockedExisting = $lockedQuery->lock(true)->find();
                if (!$lockedExisting) {
                    throw new RuntimeException('Data source not found.', 404);
                }
                $lockedConfig = $this->decodeConfig($lockedExisting['config_json'] ?? []);
                if (
                    strtolower(trim((string)($lockedExisting['platform'] ?? ''))) !== strtolower(trim((string)($existing['platform'] ?? '')))
                    || (int)($lockedExisting['system_hotel_id'] ?? 0) !== (int)($existing['system_hotel_id'] ?? 0)
                    || strtolower(trim((string)($lockedExisting['ingestion_method'] ?? ''))) !== strtolower(trim((string)($existing['ingestion_method'] ?? '')))
                    || trim((string)($lockedConfig['config_id'] ?? '')) !== trim((string)($existingConfig['config_id'] ?? ''))
                ) {
                    throw new RuntimeException('OTA data source changed concurrently; reload before saving.', 409);
                }
            }

            if ($isBrowserProfile) {
                (new OtaProfileBindingService())->claim($hotelId, $platform, $profileKey, $actorId);
                $config = array_merge($safeConfig, [
                    'config_id' => $configId,
                    'credential_usage' => 'not_required_for_browser_profile',
                    'credential_status' => 'not_required',
                    'status' => 'not_required',
                    'has_secret' => false,
                    'has_cookies' => false,
                    'profile_execution_policy' => 'profile_session_metadata_only_no_vault_decrypt',
                ]);
            } elseif ($isLocalCollector) {
                $config = array_merge($safeConfig, [
                    'config_id' => $configId,
                    'credential_usage' => 'not_required_for_local_collector',
                    'credential_status' => 'not_required',
                    'status' => 'not_required',
                    'has_secret' => false,
                    'has_cookies' => false,
                    'profile_execution_policy' => 'account_owner_device_only',
                ]);
            } elseif ($isBrowserAssist) {
                $config = array_merge($safeConfig, [
                    'config_id' => $configId,
                    'credential_usage' => 'not_required_for_browser_assist',
                    'credential_status' => 'not_required',
                    'status' => 'not_required',
                    'has_secret' => false,
                    'has_cookies' => false,
                    'profile_execution_policy' => 'authorized_page_observation_only',
                ]);
            } else {
                $credential = ($hasSecretInput || !$existing)
                    ? $this->otaCredentialVault()->store($tenantId, $hotelId, $platform, $configId, $secretPayload, $actorId)
                    : $this->otaCredentialVault()->metadata($tenantId, $hotelId, $platform, $configId);
                $hasCookies = $hasSecretInput || !$existing
                    ? $this->otaCredentialPayloadHasCookies($secretPayload)
                    : $this->truthy($existingConfig['has_cookies'] ?? false);
                $hasSecret = $hasSecretInput || !$existing
                    ? $this->credentialPayloadHasValue($secretPayload)
                    : $this->truthy($existingConfig['has_secret'] ?? ((int)($existingConfig['credential_ref'] ?? 0) > 0));
                $credentialStatus = trim((string)($credential['credential_status'] ?? ''));
                if ($credentialStatus === '' || (int)($credential['credential_ref'] ?? 0) <= 0) {
                    throw new RuntimeException('OTA credential metadata is incomplete.', 422);
                }
                $config = array_merge($safeConfig, [
                    'config_id' => $configId,
                    'credential_ref' => (int)$credential['credential_ref'],
                    'credential_status' => $credentialStatus,
                    'status' => $credentialStatus,
                    'has_secret' => $hasSecret,
                    'has_cookies' => $hasCookies,
                ]);
            }
            if ($isBrowserProfile && $id > 0) {
                $config = array_merge(
                    $config,
                    $this->managedCloudCollectorBindingConfig($lockedConfig)
                );
            }
            $data = [
                'system_hotel_id' => $hotelId,
                'user_id' => $actorId ?: null,
                'name' => $source['name'],
                'platform' => $platform,
                'data_type' => $source['data_type'],
                'ingestion_method' => $source['ingestion_method'],
                'status' => $source['status'],
                'enabled' => $source['enabled'],
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'secret_json' => '{}',
                'updated_by' => $actorId ?: null,
                'update_time' => $now,
            ];
            $data['tenant_id'] = $tenantId;

            if ($id > 0) {
                $updateQuery = Db::name('platform_data_sources');
                $this->applyStoredSourceIdentity($updateQuery, $existing);
                $updateQuery->update($data);
            } else {
                $data['created_by'] = $actorId ?: null;
                $data['create_time'] = $now;
                $id = (int)Db::name('platform_data_sources')->insertGetId($data);
            }

            $row = Db::name('platform_data_sources')
                ->withoutField('secret_json')
                ->where('id', $id)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->find();
            return $this->sanitizeSourceRow($row ?: []);
        });
    }

    private function resolveHotelTenantId(int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new RuntimeException('OTA credential hotel scope is missing.', 422);
        }
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new RuntimeException('OTA credential tenant scope is missing.', 422);
        }
        return $tenantId;
    }

    /** @param array<string, mixed> $source */
    private function resolveSourceTenantId(array $source): int
    {
        $authoritativeTenantId = $this->resolveHotelTenantId((int)($source['system_hotel_id'] ?? 0));
        $storedTenantId = (int)($source['tenant_id'] ?? 0);
        if ($storedTenantId > 0 && $storedTenantId !== $authoritativeTenantId) {
            throw new RuntimeException('Data source tenant scope does not match its hotel.', 409);
        }

        return $authoritativeTenantId;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $existingConfig
     */
    private function resolveOtaDataSourceConfigId(array $config, array $existingConfig, string $platform, int $id): string
    {
        $configId = trim((string)($config['config_id'] ?? $existingConfig['config_id'] ?? ''));
        if ($configId === '') {
            $configId = $id > 0
                ? $platform . '-source-' . $id
                : $platform . '-source-' . bin2hex(random_bytes(8));
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) !== 1) {
            throw new RuntimeException('Invalid OTA data source config_id.', 422);
        }
        return $configId;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function allowlistedOtaSourceConfig(
        array $config,
        string $platform,
        bool $allowManagedLocalIdentityEvidence = false
    ): array
    {
        $allowed = [
            'url', 'request_url', 'method', 'allowed_hosts', 'headers', 'payload', 'payload_json',
            'external_hotel_id', 'hotel_name', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId',
            'stable_profile_id', 'stableProfileId', 'profile_binding_key', 'profileBindingKey',
            'profile_reuse_scope', 'profileReuseScope',
            'hotel_id', 'hotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId',
            'platform_hotel_id', 'platformHotelId', 'hotel_code', 'hotelCode', 'node_id', 'nodeId',
            'store_id', 'storeId', 'store_name', 'storeName', 'poi_id', 'poiId', 'poi_name', 'poiName', 'partner_id', 'partnerId',
            'ads_url', 'adsUrl', 'capture_sections', 'captureSections', 'sections', 'profile_sections',
            'profileSections',
            'section_concurrency', 'sectionConcurrency', 'ctrip_section_concurrency', 'ctripSectionConcurrency',
            'sequential_sections', 'sequentialSections', 'section_sequential', 'sectionSequential',
            'not_applicable_sections', 'notApplicableSections', 'excluded_sections', 'excludedSections',
            'allow_review', 'authorized_review_collection', 'review_collection_enabled',
            'manual_login_state_verified', 'profile_status', 'login_status', 'last_login_verified_at',
            'lastLoginVerifiedAt', 'login_verified_at', 'loginVerifiedAt', 'last_verified_at', 'lastVerifiedAt',
            'profile_login_verified_at', 'last_profile_login_at', 'profile_daily_reuse_enabled', 'profileDailyReuseEnabled',
            'local_collector_account_id', 'collector_device_id_hash', 'profile_key_hash', 'source_method',
            'current_session_verified',
            'data_date', 'dataDate', 'data_period', 'dataPeriod', 'snapshot_time', 'snapshotTime',
        ];
        if ($allowManagedLocalIdentityEvidence) {
            $allowed[] = 'platform_hotel_identity_source';
            $allowed[] = 'platform_hotel_identity_checked_at';
        }
        $safe = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            if (str_contains(strtolower($key), 'url')) {
                $this->assertOtaMetadataUrlsAreSafe($config[$key], $platform);
            }
            if ($key === 'allowed_hosts') {
                $safe[$key] = $this->normalizeOtaAllowedHosts($config[$key], $platform);
                continue;
            }
            $safe[$key] = $this->sanitizeOtaMetadataNode($config[$key]);
        }
        return $safe;
    }

    /**
     * Collector bindings are managed only by the explicit bind/unbind command.
     * Normal data-source edits may change Profile metadata but cannot forge,
     * rotate or silently remove an existing complete device binding.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function managedCloudCollectorBindingConfig(array $config): array
    {
        $keys = [
            'source_method',
            'collector_binding_mode',
            'collector_device_id',
            'collector_device_id_hash',
            'collector_user_id',
            'collector_tenant_id',
            'collector_hotel_id',
            'collector_platform',
            'collector_bound_at',
        ];
        $managed = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $config)) {
                $managed[$key] = $config[$key];
            }
        }
        if (strtolower(trim((string)($managed['source_method'] ?? ''))) !== 'single_user_local'
            || strtolower(trim((string)($managed['collector_binding_mode'] ?? ''))) !== 'single_user_local'
            || preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D',
                trim((string)($managed['collector_device_id'] ?? ''))
            ) !== 1
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                strtolower(trim((string)($managed['collector_device_id_hash'] ?? '')))
            ) !== 1
            || (int)($managed['collector_user_id'] ?? 0) <= 0
            || (int)($managed['collector_tenant_id'] ?? 0) <= 0
            || (int)($managed['collector_hotel_id'] ?? 0) <= 0
            || !in_array(
                strtolower(trim((string)($managed['collector_platform'] ?? ''))),
                ['ctrip', 'meituan'],
                true
            )
            || trim((string)($managed['collector_bound_at'] ?? '')) === ''
        ) {
            return [];
        }
        return $managed;
    }

    private function assertOtaMetadataUrlsAreSafe(mixed $value, string $platform): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertOtaMetadataUrlsAreSafe($item, $platform);
            }
            return;
        }
        $this->assertOtaMetadataUrlIsSafe($value, $platform);
    }

    private function assertOtaMetadataUrlIsSafe(mixed $value, string $platform): void
    {
        if (!is_scalar($value)) {
            throw new RuntimeException('OTA data source URL metadata must be a string.', 422);
        }
        $url = trim((string)$value);
        if ($url === '') {
            return;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RuntimeException('OTA data source URL is invalid.', 422);
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if (
            $scheme !== 'https'
            || $host === ''
            || $port !== 443
            || !empty($parts['user'])
            || !empty($parts['pass'])
        ) {
            throw new RuntimeException('OTA data source URL must use HTTPS port 443 without embedded credentials.', 422);
        }
        if (!$this->isAllowedOtaPlatformHost($host, $platform)) {
            throw new RuntimeException('OTA data source URL host is outside the platform allowlist.', 422);
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if ($this->isSensitiveConfigKey((string)$key)) {
                throw new RuntimeException('OTA data source URL must not contain credential query parameters.', 422);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeOtaAllowedHosts(mixed $value, string $platform): array
    {
        $hosts = is_string($value) ? explode(',', $value) : $value;
        if (!is_array($hosts)) {
            throw new RuntimeException('OTA allowed_hosts metadata must be a string or list.', 422);
        }
        $safe = [];
        foreach ($hosts as $host) {
            if (!is_scalar($host)) {
                throw new RuntimeException('OTA allowed_hosts metadata contains an unsupported value.', 422);
            }
            $host = strtolower(rtrim(ltrim(trim((string)$host), '.'), '.'));
            if ($host === '') {
                continue;
            }
            if (str_contains($host, '://') || str_contains($host, '/') || !$this->isAllowedOtaPlatformHost($host, $platform)) {
                throw new RuntimeException('OTA allowed_hosts contains a host outside the platform allowlist.', 422);
            }
            $safe[$host] = $host;
        }
        return array_values($safe);
    }

    private function isAllowedOtaPlatformHost(string $host, string $platform): bool
    {
        $suffixes = match (strtolower(trim($platform))) {
            'ctrip' => ['ctrip.com', 'ctripbiz.com', 'ctripbiz.cn'],
            'meituan' => ['meituan.com', 'dianping.com'],
            default => [],
        };
        foreach ($suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeOtaMetadataNode(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_scalar($value) || $value === null) {
                if (is_string($value) && $this->stringContainsCredentialMaterial($value)) {
                    throw new RuntimeException('OTA data source metadata must not contain credential material.', 422);
                }
                return $value;
            }
            throw new RuntimeException('OTA data source metadata contains an unsupported value.', 422);
        }

        $safe = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveConfigKey($key)) {
                throw new RuntimeException('OTA data source metadata contains a credential field; move it to the secret payload.', 422);
            }
            $safe[$key] = $this->sanitizeOtaMetadataNode($item);
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeOtaCredentialPayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string)$key) === 'cookie' ? 'cookies' : (string)$key;
            if (is_array($value)) {
                $value = $this->normalizeOtaCredentialPayload($value);
                if ($value === []) {
                    continue;
                }
            } elseif ($value === null || (is_scalar($value) && trim((string)$value) === '')) {
                continue;
            } elseif (!is_scalar($value)) {
                throw new RuntimeException('OTA credential payload contains an unsupported value.', 422);
            }
            $normalized[$normalizedKey] = $value;
        }
        return $normalized;
    }

    private function credentialPayloadHasValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->credentialPayloadHasValue($item)) {
                    return true;
                }
            }
            return false;
        }
        return $value !== null && is_scalar($value) && trim((string)$value) !== '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function otaCredentialPayloadHasCookies(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string)$key), ['cookie', 'cookies'], true) && $this->credentialPayloadHasValue($value)) {
                return true;
            }
            if (is_array($value) && $this->otaCredentialPayloadHasCookies($value)) {
                return true;
            }
        }
        return false;
    }

    private function isOtaPlatform(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), ['ctrip', 'meituan'], true);
    }

    private function otaCredentialVault(): OtaCredentialVault
    {
        return $this->credentialVault ??= new OtaCredentialVault();
    }

    public function deleteDataSource($user, int $id): bool
    {
        $rowQuery = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $id);
        $this->applySourceTenantScope($rowQuery, $user);
        $row = $rowQuery->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        [$tenantId, $hotelId] = $this->assertStoredSourceTenantForActor($row, $user);
        $this->assertCanUseHotel($user, (int)($row['system_hotel_id'] ?? 0), 'can_delete_online_data');
        return Db::transaction(function () use ($user, $id, $row, $tenantId, $hotelId): bool {
            $lockedQuery = Db::name('platform_data_sources')->withoutField('secret_json');
            $this->applyStoredSourceIdentity($lockedQuery, $row);
            $locked = $lockedQuery->lock(true)->find();
            if (!$locked) {
                throw new RuntimeException('Data source not found.', 404);
            }

            $update = [
                'enabled' => 0,
                'status' => 'disabled',
                'updated_by' => (int)($user->id ?? 0) ?: null,
                'update_time' => date('Y-m-d H:i:s'),
            ];
            $platform = strtolower(trim((string)($locked['platform'] ?? '')));
            if ($this->isOtaPlatform($platform)) {
                $update['secret_json'] = '{}';
                $config = $this->decodeConfig($locked['config_json'] ?? []);
                $configId = trim((string)($config['config_id'] ?? ''));
                $credentialRef = (int)($config['credential_ref'] ?? 0);
                if ($credentialRef > 0 && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) === 1
                    && !$this->otherEnabledOtaSourceUsesCredential($id, $tenantId, $hotelId, $platform, $configId)
                ) {
                    $credential = $this->otaCredentialVault()->revoke($tenantId, $hotelId, $platform, $configId);
                    if ((int)($credential['credential_ref'] ?? 0) !== $credentialRef) {
                        throw new RuntimeException('OTA data source credential reference does not match its locator.', 409);
                    }
                    $config['credential_status'] = (string)($credential['credential_status'] ?? 'revoked');
                    $config['status'] = $config['credential_status'];
                    $update['config_json'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }

            $updateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($updateQuery, $locked);
            return $updateQuery->update($update) >= 0;
        });
    }

    private function otherEnabledOtaSourceUsesCredential(int $excludedId, int $tenantId, int $hotelId, string $platform, string $configId): bool
    {
        $rows = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('enabled', 1)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $excludedId) {
                continue;
            }
            $candidate = $this->decodeConfig($row['config_json'] ?? []);
            if (hash_equals($configId, trim((string)($candidate['config_id'] ?? '')))) {
                return true;
            }
        }
        return false;
    }

    public function syncDataSource($user, int $id, array $options = []): array
    {
        $syncStartedAt = microtime(true);
        $timing = $this->emptySyncTiming();
        $source = $this->loadSource($id, $user);
        $isOtaSource = $this->isOtaPlatform((string)($source['platform'] ?? ''));

        if ((int)($source['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('Data source is disabled.', 422);
        }
        $this->assertRequiredCollectorBinding($source, $options);

        $taskAcquisition = $this->acquireSyncTask(
            $source,
            $user,
            (string)($options['trigger_type'] ?? 'manual'),
            $options
        );
        $taskId = (int)$taskAcquisition['task_id'];
        if (($taskAcquisition['reused_active_task'] ?? false) === true) {
            return $this->reusedActiveSyncTaskResult(
                $source,
                is_array($taskAcquisition['task'] ?? null)
                    ? $taskAcquisition['task']
                    : []
            );
        }
        try {
            $adapter = $this->resolveAdapter($source);
            $this->assertBrowserProfileBackgroundSyncLoginVerified($source, $options);
            $phaseStartedAt = microtime(true);
            if ($this->isManualImportSource($source)) {
                $result = $adapter->fetch($source, $this->manualImportAdapterOptions($options));
            } elseif ($isOtaSource) {
                $result = $this->isOtaBrowserProfileSource($source)
                    ? $this->fetchOtaBrowserProfileSource($adapter, $source, $options)
                    : ($this->isOtaLocalCollectorSource($source)
                        ? $this->fetchOtaLocalCollectorSource($adapter, $source, $options)
                        : ($this->isOtaBrowserAssistSource($source)
                            ? $this->fetchOtaBrowserAssistSource($adapter, $source, $options)
                            : $this->fetchOtaSourceInsideVault($adapter, $source, $options)));
            } else {
                $result = $adapter->fetch($source, $options);
            }
            if ($this->isOtaBrowserProfileSource($source)
                && $this->recordBrowserProfileCollectionPreflight($source, $result)
            ) {
                $source = $this->loadSource($id, $user);
            }
            $this->assertRequiredCurrentRunProfileSessionProbe($source, $options, $result);
            $timing['capture_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $this->refreshDatabaseConnectionAfterExternalFetch();
            $payload = $this->applySyncOptionPeriodMetadata($result['payload'] ?? [], $options);
            if (($result['status'] ?? '') !== 'success') {
                $payload['sync_diagnostics'] = $this->buildSyncDiagnostics([], 0, $source, $options, $payload, (string)$result['status'], (string)$result['message']);
                return $this->finishTask($taskId, $source, (string)$result['status'], (string)$result['message'], 0, 0, $payload, $timing, $syncStartedAt);
            }

            $bindingEvidence = $this->assertGenericOtaPayloadBinding($source, is_array($payload) ? $payload : []);
            if ($bindingEvidence !== []) {
                $payload['_ota_binding_evidence'] = $bindingEvidence;
            }
            $collectorBindingEvidence = $this->collectorBindingEvidence($source);
            if ($collectorBindingEvidence !== []) {
                $payload['_collector_binding_evidence'] = $collectorBindingEvidence;
            }

            $phaseStartedAt = microtime(true);
            $this->storeRawRecord($source, $taskId, $payload, $result['http_status'] ?? null);
            $timing['raw_store_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $phaseStartedAt = microtime(true);
            $rows = $this->normalizeRowsFromPayload(is_array($payload) ? $payload : [], $source, $taskId);
            $timing['normalize_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $phaseStartedAt = microtime(true);
            $saveReceipt = $this->saveNormalizedRows($rows);
            $saved = (int)$saveReceipt['saved_count'];
            $payload['_save_receipt'] = $saveReceipt;
            $timing['daily_rows_save_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);

            if (($saveReceipt['readback_verified'] ?? false) !== true) {
                $message = (string)($saveReceipt['failure_reason'] ?? 'normalized_rows_readback_mismatch_rolled_back');
                $payload['sync_diagnostics'] = $this->buildSyncDiagnostics(
                    $rows,
                    0,
                    $source,
                    $options,
                    $payload,
                    'failed',
                    $message
                );
                return $this->finishTask(
                    $taskId,
                    $source,
                    'failed',
                    $message,
                    count($rows),
                    0,
                    $payload,
                    $timing,
                    $syncStartedAt
                );
            }

            $confirmedEmpty = $this->isAuthoritativeEmptySyncPayload($payload);
            $status = (($saved > 0 && !empty($saveReceipt['readback_verified'])) || $confirmedEmpty) ? 'success' : 'partial_success';
            $message = $saved > 0
                ? sprintf(
                    'Platform data synchronized: %d inserted, %d updated, %d read back.',
                    (int)$saveReceipt['inserted_count'],
                    (int)$saveReceipt['updated_count'],
                    (int)$saveReceipt['readback_count']
                )
                : ($confirmedEmpty ? 'platform_returned_authoritative_empty' : 'No business rows were found in payload.');
            $diagnostics = $this->buildSyncDiagnostics($rows, $saved, $source, $options, $payload, $status, $message);
            if ((string)($diagnostics['p0_status'] ?? '') !== 'ready' && !empty($diagnostics['requires_target_date_traffic'])) {
                $status = 'partial_success';
                $message = (string)($diagnostics['operator_message'] ?? 'profile_reused_but_target_date_traffic_not_ready');
            }
            $payload['sync_diagnostics'] = $diagnostics;
            return $this->finishTask($taskId, $source, $status, $message, count($rows), $saved, $payload, $timing, $syncStartedAt);
        } catch (\Throwable $e) {
            $this->refreshDatabaseConnectionAfterExternalFetch();
            $failureMessage = $isOtaSource ? $this->safeOtaExecutionFailureCode($e) : $e->getMessage();
            $payload = [
                'sync_diagnostics' => $this->buildSyncDiagnostics([], 0, $source, $options, [], 'failed', $failureMessage),
            ];
            return $this->finishTask($taskId, $source, 'failed', $failureMessage, 0, 0, $payload, $timing, $syncStartedAt);
        }
    }

    public function importRows($user, array $payload): array
    {
        $selectedSourceId = (int)($payload['data_source_id'] ?? $payload['source_id'] ?? 0);
        $ingestionMethod = strtolower(trim((string)($payload['ingestion_method'] ?? 'manual')));
        if (!in_array(
            $ingestionMethod,
            ['manual', 'import_json', 'import_csv', 'import_excel', 'browser_assist_dom'],
            true
        )) {
            $ingestionMethod = 'manual';
        }

        $selectedSource = $selectedSourceId > 0
            ? $this->loadSource($selectedSourceId, $user)
            : [];
        $effectiveSourceId = 0;

        if ($ingestionMethod === 'browser_assist_dom') {
            if ($selectedSource !== [] && $this->isOtaBrowserAssistSource($selectedSource)) {
                $effectiveSourceId = (int)$selectedSource['id'];
            } else {
                $effectiveSourceId = $this->resolveBrowserAssistImportSourceId($user, $selectedSource, $payload);
            }
        } elseif ($selectedSource !== []) {
            $effectiveSourceId = $this->isManualImportSource($selectedSource)
                ? (int)$selectedSource['id']
                : $this->resolveDedicatedManualImportSourceId($user, $selectedSource, $payload);
        }

        if ($effectiveSourceId <= 0 && $selectedSource === []) {
            $platform = strtolower(trim((string)($payload['platform'] ?? 'custom')));
            if ($this->isOtaPlatform($platform)) {
                $effectiveSourceId = $this->resolveDedicatedManualImportSourceId($user, [], $payload);
            }
        }

        if ($effectiveSourceId <= 0) {
            $sourcePayload = [
                'name' => $payload['name'] ?? 'Manual import',
                'platform' => $payload['platform'] ?? 'custom',
                'data_type' => $payload['data_type'] ?? 'business',
                'system_hotel_id' => $payload['system_hotel_id'] ?? 0,
                'ingestion_method' => $ingestionMethod,
            ];
            foreach (['external_hotel_id', 'hotel_id', 'hotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'poi_id', 'poiId', 'store_id', 'storeId'] as $bindingKey) {
                if (array_key_exists($bindingKey, $payload) && trim((string)$payload[$bindingKey]) !== '') {
                    $sourcePayload[$bindingKey] = $payload[$bindingKey];
                }
            }
            $source = $this->saveDataSource($user, $sourcePayload);
            $effectiveSourceId = (int)$source['id'];
        }

        $result = $this->syncDataSource($user, $effectiveSourceId, [
            'trigger_type' => 'manual_import',
            'payload' => ['rows' => $payload['rows'] ?? $payload['data'] ?? []],
        ]);
        $result['selected_data_source_id'] = $selectedSourceId > 0
            ? $selectedSourceId
            : $effectiveSourceId;
        $result['effective_import_source_id'] = $effectiveSourceId;
        if ($ingestionMethod !== 'browser_assist_dom') {
            $result['import_provenance_status'] = 'user_provided_unverified';
            $result['analysis_eligible_count'] = 0;
        }
        return $result;
    }

    /**
     * Read the exact aggregate rows written by one manual import. The caller
     * receives the sanitized canonical input rows only after value-level
     * persistence readback has succeeded for every saved row.
     *
     * @param mixed $user
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    public function readImportedRows($user, array $result): array
    {
        $taskId = (int)($result['task_id'] ?? 0);
        $sourceId = (int)($result['effective_import_source_id'] ?? $result['data_source_id'] ?? 0);
        $savedCount = (int)($result['saved_count'] ?? 0);
        $readbackCount = (int)($result['readback_count'] ?? 0);
        if ($taskId <= 0 || $sourceId <= 0 || $savedCount <= 0
            || $readbackCount !== $savedCount
            || ($result['readback_verified'] ?? false) !== true
        ) {
            throw new RuntimeException('manual_import_exact_readback_not_verified', 422);
        }

        $source = $this->loadSource($sourceId, $user);
        if (!$this->isManualImportSource($source)) {
            throw new RuntimeException('manual_import_source_scope_invalid', 409);
        }
        [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        $columns = $this->tableColumns('online_daily_data');
        foreach (['id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id', 'raw_data'] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException('manual_import_readback_column_missing:' . $required, 500);
            }
        }

        $fields = array_values(array_filter([
            'id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id',
            'platform', 'source', 'data_type', 'data_date', 'readback_verified', 'raw_data',
        ], static fn(string $field): bool => isset($columns[$field])));
        $query = Db::name('online_daily_data')
            ->field(implode(',', $fields))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $taskId);
        if (isset($columns['readback_verified'])) {
            $query->where('readback_verified', 1);
        }
        $persistedRows = $query->order('id', 'asc')->select()->toArray();
        $persistedRows = array_values(array_filter($persistedRows, 'is_array'));
        if (count($persistedRows) !== $savedCount) {
            throw new RuntimeException('manual_import_exact_readback_count_mismatch', 409);
        }

        $rows = [];
        foreach ($persistedRows as $persistedRow) {
            $raw = $this->decodeConfig($persistedRow['raw_data'] ?? []);
            $canonical = is_array($raw['row'] ?? null) ? $raw['row'] : [];
            if ($canonical === []
                || (int)($canonical['system_hotel_id'] ?? 0) !== $hotelId
                || trim((string)($canonical['data_date'] ?? '')) !== trim((string)($persistedRow['data_date'] ?? ''))
            ) {
                throw new RuntimeException('manual_import_exact_readback_identity_mismatch', 409);
            }
            $canonical['_persisted_row_id'] = (int)($persistedRow['id'] ?? 0);
            $canonical['_readback_verified'] = true;
            $rows[] = $canonical;
        }
        return $rows;
    }

    /** @param array<string, mixed> $source */
    private function isManualImportSource(array $source): bool
    {
        return in_array(
            strtolower(trim((string)($source['ingestion_method'] ?? ''))),
            self::MANUAL_IMPORT_METHODS,
            true
        );
    }

    /**
     * Manual imports must never inherit an execution option that could invoke
     * a source-side request. Only the submitted rows reach the import adapter.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function manualImportAdapterOptions(array $options): array
    {
        return [
            'trigger_type' => (string)($options['trigger_type'] ?? 'manual_import'),
            'payload' => is_array($options['payload'] ?? null) ? $options['payload'] : [],
        ];
    }

    /**
     * A selected capture/API source is an identity reference only. Browser
     * assist keeps its own credentialless source and never executes the
     * selected source's adapter.
     *
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveBrowserAssistImportSourceId($user, array $selectedSource, array $payload): int
    {
        $scope = $selectedSource !== [] ? $selectedSource : $payload;
        $lookup = [
            'system_hotel_id' => (int)($scope['system_hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'data_type' => strtolower(trim((string)($scope['data_type'] ?? 'business'))),
        ];
        $sourceId = $this->reusableBrowserAssistSourceId($user, $lookup);
        if ($sourceId > 0) {
            $this->loadSource($sourceId, $user);
            return $sourceId;
        }

        $source = $this->saveDataSource($user, [
            'name' => 'Browser assist import',
            'platform' => $lookup['platform'] !== '' ? $lookup['platform'] : 'custom',
            'data_type' => $lookup['data_type'] !== '' ? $lookup['data_type'] : 'business',
            'system_hotel_id' => $lookup['system_hotel_id'],
            'ingestion_method' => 'browser_assist_dom',
        ]);
        return (int)$source['id'];
    }

    /**
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveDedicatedManualImportSourceId($user, array $selectedSource, array $payload): int
    {
        $scope = $selectedSource !== [] ? $selectedSource : $payload;
        $hotelId = (int)($scope['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($scope['platform'] ?? 'custom')));
        $dataType = strtolower(trim((string)($scope['data_type'] ?? 'business')));
        if ($platform === '') {
            $platform = 'custom';
        }
        if ($dataType === '') {
            $dataType = 'business';
        }
        $this->assertCanUseHotel($user, $hotelId, 'can_fetch_online_data');
        $tenantId = $this->resolveHotelTenantId($hotelId);
        $platformHotelId = $this->resolveManualImportPlatformHotelId($selectedSource, $payload, $platform);

        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->whereIn('ingestion_method', self::MANUAL_IMPORT_METHODS)
            ->where('enabled', 1)
            ->order('id', 'desc');
        $this->applySourceTenantScope($query, $user);
        foreach ($query->select()->toArray() as $candidate) {
            $config = $this->decodeConfig($candidate['config_json'] ?? []);
            if ((string)($config['manual_import_contract'] ?? '') !== self::MANUAL_IMPORT_SOURCE_CONTRACT) {
                continue;
            }
            $candidatePlatformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
            if ($this->isOtaPlatform($platform)
                && !$this->otaHotelIdentifiersMatch($platformHotelId, $candidatePlatformHotelId)
            ) {
                continue;
            }
            $this->loadSource((int)$candidate['id'], $user);
            return (int)$candidate['id'];
        }

        $config = [
            'manual_import_contract' => self::MANUAL_IMPORT_SOURCE_CONTRACT,
            'source_method' => 'manual_import',
        ];
        if ($platformHotelId !== '') {
            $config['platform_hotel_id'] = $platformHotelId;
        }
        $actorId = (int)($user->id ?? 0);
        $now = date('Y-m-d H:i:s');
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'user_id' => $actorId ?: null,
            'name' => sprintf('%s manual import - %s', $platform, $dataType),
            'platform' => $platform,
            'data_type' => $dataType,
            'ingestion_method' => 'manual',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => '{}',
            'created_by' => $actorId ?: null,
            'updated_by' => $actorId ?: null,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        $readback = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->where('ingestion_method', 'manual')
            ->find();
        if (!is_array($readback) || (int)($readback['id'] ?? 0) !== $sourceId) {
            throw new RuntimeException('manual_import_source_readback_failed', 500);
        }
        $this->loadSource($sourceId, $user);
        return $sourceId;
    }

    /**
     * @param array<string, mixed> $selectedSource
     * @param array<string, mixed> $payload
     */
    private function resolveManualImportPlatformHotelId(array $selectedSource, array $payload, string $platform): string
    {
        if (!$this->isOtaPlatform($platform)) {
            return '';
        }
        $keys = $this->otaHotelIdentifierKeys($platform);
        $sourceConfig = $this->decodeConfig($selectedSource['config'] ?? $selectedSource['config_json'] ?? []);
        $expected = $this->stringValue($selectedSource, $keys);
        if ($expected === '') {
            $expected = $this->stringValue($sourceConfig, $keys);
        }
        if ($expected === '') {
            $expected = $this->stringValue($payload, $keys);
        }

        $observed = [];
        foreach ($this->extractBusinessRows($payload) as $row) {
            if (!is_array($row) || $this->isCompetitorOtaIdentityRow($row, $keys)) {
                continue;
            }
            $identifier = trim($this->stringValue($row, $keys));
            if ($identifier !== '') {
                $observed[strtolower($identifier)] = $identifier;
            }
        }
        $observed = array_values($observed);
        if (count($observed) !== 1) {
            throw new RuntimeException($observed === [] ? 'binding_unverified' : 'binding_mismatch', 422);
        }
        if ($expected === '') {
            $expected = $observed[0];
        }
        if (!$this->otaHotelIdentifiersMatch($expected, $observed[0])) {
            throw new RuntimeException('binding_mismatch', 409);
        }
        return $expected;
    }

    /** @param array<string, mixed> $payload */
    private function reusableBrowserAssistSourceId($user, array $payload): int
    {
        $hotelId = (int)($payload['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($payload['platform'] ?? '')));
        $dataType = strtolower(trim((string)($payload['data_type'] ?? '')));
        if ($hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || $dataType === ''
        ) {
            return 0;
        }
        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_type', $dataType)
            ->where('ingestion_method', 'browser_assist_dom')
            ->where('enabled', 1);
        $this->applySourceTenantScope($query, $user);
        return max(0, (int)($query->order('id', 'desc')->value('id') ?? 0));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseImportFile(string $path, string $originalName): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Import file not found.', 422);
        }
        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        $maxBytes = in_array($extension, ['xls', 'xlsx'], true)
            ? 20 * 1024 * 1024
            : 5 * 1024 * 1024;
        if ((int)filesize($path) > $maxBytes) {
            throw new RuntimeException('Import file exceeds ' . ($maxBytes / 1024 / 1024) . 'MB.', 422);
        }

        $rows = match ($extension) {
            'json' => $this->parseJsonImportFile($path),
            'csv' => $this->parseCsvImportFile($path),
            'xls' => (new CtripOrderExportImportService())->parseLegacyXls($path, $originalName),
            'xlsx' => $this->parseXlsxImportFile($path),
            default => throw new RuntimeException('Only JSON, CSV, XLS and XLSX imports are supported.', 422),
        };

        if (empty($rows)) {
            throw new RuntimeException('Import file has no business rows.', 422);
        }

        return $rows;
    }

    public function listSyncTasks($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sync_tasks')->order('id', 'desc');
        $this->applyTaskScope($query, $user);
        if (!empty($filters['data_source_id'])) {
            $query->where('data_source_id', (int)$filters['data_source_id']);
        }
        if (!empty($filters['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
        }
        if (!empty($filters['platform'])) {
            $query->where('platform', strtolower((string)$filters['platform']));
        }
        if (!empty($filters['data_type'])) {
            $query->where('data_type', $this->normalizeDataType((string)$filters['data_type']));
        }
        if (!empty($filters['status'])) {
            $query->where('status', (string)$filters['status']);
        }
        $rows = $query->limit(max(1, min(200, (int)($filters['limit'] ?? 50))))->select()->toArray();
        foreach ($rows as &$row) {
            $effectiveStatus = self::effectiveSyncTaskStatus(is_array($row) ? $row : []);
            $row = $this->sanitizeSyncTaskRowForResponse(is_array($row) ? $row : []);
            $row['effective_status'] = $effectiveStatus;
            $row['is_stale_running'] = $effectiveStatus === 'stale_running';
            $row['stale_age_seconds'] = self::syncTaskAgeSeconds(is_array($row) ? $row : []);
        }
        unset($row);

        return $rows;
    }

    public function listSyncLogs($user, array $filters = []): array
    {
        $query = Db::name('platform_data_sync_logs')->order('id', 'desc');
        $this->applyTaskScope($query, $user);
        if (!empty($filters['sync_task_id'])) {
            $query->where('sync_task_id', (int)$filters['sync_task_id']);
        }
        if (!empty($filters['data_source_id'])) {
            $query->where('data_source_id', (int)$filters['data_source_id']);
        }
        $rows = $query->limit(max(1, min(200, (int)($filters['limit'] ?? 50))))->select()->toArray();
        return array_values(array_map(
            fn(array $row): array => $this->sanitizeSyncLogRowForResponse($row),
            array_values(array_filter($rows, 'is_array'))
        ));
    }

    /**
     * Converts a stored task row into the safe response contract used by task
     * lists and collection-status projections. External error text never
     * crosses this boundary.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeSyncTaskRowForResponse(array $row): array
    {
        $status = (string)($row['status'] ?? '');
        $row['message'] = $this->safeSyncTaskMessage($status, (string)($row['message'] ?? ''));
        $stats = $this->sanitizeSyncTaskStats($this->decodeConfig($row['stats_json'] ?? []), $status);
        $row['stats_json'] = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitizeSyncLogRowForResponse(array $row): array
    {
        $context = $this->decodeConfig($row['context_json'] ?? []);
        $adapterStatus = (string)($context['sync_diagnostics']['adapter_status'] ?? '');
        $row['message'] = $this->safeSyncTaskMessage($adapterStatus, (string)($row['message'] ?? ''));
        $safeContext = $this->sanitizeSyncTaskStats($context, $adapterStatus);
        $row['context_json'] = json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return $row;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function sanitizeSyncTaskStats(array $stats, string $status): array
    {
        $safe = [
            'normalized_count' => max(0, (int)($stats['normalized_count'] ?? 0)),
            'saved_count' => max(0, (int)($stats['saved_count'] ?? 0)),
            'attempted_count' => max(0, (int)($stats['attempted_count'] ?? 0)),
            'inserted_count' => max(0, (int)($stats['inserted_count'] ?? 0)),
            'updated_count' => max(0, (int)($stats['updated_count'] ?? 0)),
            'deduplicated_count' => max(0, (int)($stats['deduplicated_count'] ?? 0)),
            'readback_count' => max(0, (int)($stats['readback_count'] ?? 0)),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => mb_substr(trim((string)($stats['failure_reason'] ?? '')), 0, 120),
            'mismatch_field' => mb_substr(trim((string)($stats['mismatch_field'] ?? '')), 0, 80),
            'predecessor_task_id' => max(0, (int)($stats['predecessor_task_id'] ?? 0)),
            'recovery_context_status' => mb_substr(trim((string)($stats['recovery_context_status'] ?? '')), 0, 120),
            'payload_keys' => $this->sanitizeSyncTaskPayloadKeys($stats['payload_keys'] ?? []),
        ];
        $flowStats = $this->syncTaskFlowStatsFromOptions($stats);
        if ($flowStats !== []) {
            $safe = array_merge($safe, $flowStats);
        }

        if (is_array($stats['sync_diagnostics'] ?? null)) {
            $safe['sync_diagnostics'] = $this->sanitizeSyncDiagnosticsForResponse($stats['sync_diagnostics'], $status);
        }
        if (is_array($stats['collection_quality'] ?? null)) {
            $safe['collection_quality'] = $this->sanitizeSyncTaskCollectionQuality($stats['collection_quality']);
        }
        if (is_array($stats['run_readback'] ?? null)) {
            $safe['run_readback'] = $this->sanitizeRunReadbackReceipt($stats['run_readback']);
        }
        $readFallbackSummary = $this->sanitizeOtaReadFallbackSummary($stats['read_fallback_summary'] ?? null);
        if ($readFallbackSummary !== []) {
            $safe['read_fallback_summary'] = $readFallbackSummary;
        }
        if (is_array($stats['ordered_collection'] ?? null)) {
            $ordered = $stats['ordered_collection'];
            $safeOrdered = $this->sanitizeOrderedCollectionTaskPlan($ordered, [
                'id' => (int)($ordered['data_source_id'] ?? 0),
                'system_hotel_id' => (int)($ordered['system_hotel_id'] ?? 0),
                'platform' => (string)($ordered['platform'] ?? ''),
            ]);
            if ($safeOrdered !== []) {
                $safe['ordered_collection'] = $safeOrdered;
            }
        }

        $period = $this->normalizeDataPeriod($stats['data_period'] ?? '');
        if ($period !== '') {
            $safe['data_period'] = $period;
        }
        $snapshotTime = $this->normalizeDateTime($stats['snapshot_time'] ?? '');
        if ($snapshotTime !== null) {
            $safe['snapshot_time'] = $snapshotTime;
        }
        $snapshotBucket = trim((string)($stats['snapshot_bucket'] ?? ''));
        if (preg_match('/^\d{8,12}$/', $snapshotBucket) === 1) {
            $safe['snapshot_bucket'] = $snapshotBucket;
        }

        $timing = $this->normalizeSyncTiming(is_array($stats['timing'] ?? null) ? $stats['timing'] : $stats);
        $safe['timing'] = $timing;
        foreach ($timing as $key => $value) {
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function sanitizeOtaReadFallbackSummary(mixed $value): array
    {
        if (!is_array($value) || ($value['sensitive_values_exposed'] ?? true) !== false) {
            return [];
        }

        $responseObserved = min(20, max(0, (int)($value['response_observed_count'] ?? 0)));
        $blocked = min(20, max(0, (int)($value['blocked_count'] ?? 0)));
        $failed = min(20, max(0, (int)($value['failed_count'] ?? 0)));
        $diagnosticCount = min(20, $responseObserved + $blocked + $failed);
        $attemptedCount = min(20, $responseObserved + $failed);
        $status = $responseObserved > 0
            ? (($blocked + $failed) > 0 ? 'partial' : 'response_observed')
            : ($failed > 0 ? 'failed' : ($blocked > 0 ? 'blocked' : 'not_needed'));

        return [
            'status' => $status,
            'diagnostic_count' => $diagnosticCount,
            'attempted_count' => $attemptedCount,
            'response_observed_count' => $responseObserved,
            'blocked_count' => $blocked,
            'failed_count' => $failed,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function otaReadFallbackSummaryFromPayload(array $payload): array
    {
        $candidates = [
            $payload['read_fallback_summary'] ?? null,
            is_array($payload['sync_summary'] ?? null)
                ? ($payload['sync_summary']['read_fallback_summary'] ?? null)
                : null,
            is_array($payload['data_source_capture'] ?? null)
                ? ($payload['data_source_capture']['read_fallback_summary'] ?? null)
                : null,
        ];
        $notNeeded = [];
        foreach ($candidates as $candidate) {
            $summary = $this->sanitizeOtaReadFallbackSummary($candidate);
            if ($summary === []) {
                continue;
            }
            if (($summary['status'] ?? '') !== 'not_needed') {
                return $summary;
            }
            $notNeeded = $summary;
        }
        return $notNeeded;
    }

    /** @param array<string, mixed> $receipt @return array<string, mixed> */
    private function sanitizeRunReadbackReceipt(array $receipt): array
    {
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int)$value),
            is_array($receipt['row_ids'] ?? null) ? $receipt['row_ids'] : []
        ))));
        $traceIds = [];
        foreach (is_array($receipt['source_trace_ids'] ?? null) ? $receipt['source_trace_ids'] : [] as $traceId) {
            $traceId = trim((string)$traceId);
            if (preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $traceId) === 1) {
                $traceIds[] = $traceId;
            }
        }
        $metricKeys = array_values(array_intersect(
            ['revenue', 'room_nights', 'adr'],
            array_values(array_unique(array_map(
                static fn($value): string => strtolower(trim((string)$value)),
                is_array($receipt['verified_metric_keys'] ?? null) ? $receipt['verified_metric_keys'] : []
            )))
        ));
        $platform = strtolower(trim((string)($receipt['platform'] ?? '')));
        $targetDate = $this->normalizeDate($receipt['target_date'] ?? null) ?? '';
        $dataPeriod = $this->normalizeDataPeriod($receipt['data_period'] ?? '');
        $startedAt = $this->normalizeDateTime($receipt['started_at'] ?? '') ?? '';
        $dispatcherRunId = $this->normalizeSyncDispatcherRunId(
            $receipt['dispatcher_run_id'] ?? ''
        );
        $triggerType = strtolower(trim((string)($receipt['trigger_type'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $triggerType) !== 1) {
            $triggerType = '';
        }
        $observedPlatformHotelId = trim((string)($receipt['observed_platform_hotel_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $observedPlatformHotelId) !== 1) {
            $observedPlatformHotelId = '';
        }

        $readbackCount = max(0, (int)($receipt['readback_count'] ?? 0));
        $rowIdLimitExceeded = count($rowIds) > CloudOtaBundleCodec::MAX_ROWS;
        $rowIds = array_slice($rowIds, 0, CloudOtaBundleCodec::MAX_ROWS);
        $failureReason = mb_substr(trim((string)($receipt['failure_reason'] ?? '')), 0, 120);
        if ($rowIdLimitExceeded) {
            $failureReason = 'run_readback_row_limit_exceeded';
        }
        $p0Status = strtolower(trim((string)($receipt['p0_status'] ?? 'blocked')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded'], true)) {
            $p0Status = 'blocked';
        }
        $fieldFactStatus = strtolower(trim((string)($receipt['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $platformHotelIdentifierStatus = strtolower(trim((string)(
            $receipt['platform_hotel_identifier_status'] ?? 'unverified'
        )));
        if (!in_array($platformHotelIdentifierStatus, ['ready', 'unverified'], true)) {
            $platformHotelIdentifierStatus = 'unverified';
        }
        $pageFieldFactStatus = strtolower(trim((string)($receipt['page_field_fact_status'] ?? 'partial')));
        if (!in_array($pageFieldFactStatus, ['ready', 'partial'], true)) {
            $pageFieldFactStatus = 'partial';
        }
        $captureStrategy = strtolower(trim((string)($receipt['capture_strategy'] ?? 'not_recorded')));
        if (!in_array(
            $captureStrategy,
            ['verified_endpoint_recipe', 'browser_response', 'dom_fallback', 'not_recorded'],
            true
        )) {
            $captureStrategy = 'not_recorded';
        }
        $fallbackFrom = strtolower(trim((string)($receipt['fallback_from'] ?? '')));
        if (!in_array(
            $fallbackFrom,
            ['verified_endpoint_recipe', 'browser_response'],
            true
        )) {
            $fallbackFrom = '';
        }
        $fallbackReason = strtolower(trim((string)($receipt['fallback_reason'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_:-]{0,119}$/D', $fallbackReason) !== 1) {
            $fallbackReason = '';
        }
        $responseEvidenceType = strtolower(trim((string)($receipt['response_evidence_type'] ?? '')));
        if (!in_array($responseEvidenceType, ['structured_json', 'dom_fields'], true)) {
            $responseEvidenceType = '';
        }
        $recipePlanHash = strtolower(trim((string)($receipt['recipe_plan_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $recipePlanHash) !== 1) {
            $recipePlanHash = '';
        }
        $recipeCount = isset($receipt['recipe_count']) && is_numeric($receipt['recipe_count'])
            ? max(0, (int)$receipt['recipe_count'])
            : null;

        $safeReceipt = [
            'readback_verified' => ($receipt['readback_verified'] ?? false) === true
                && !$rowIdLimitExceeded
                && $readbackCount === count($rowIds),
            'sync_task_id' => max(0, (int)($receipt['sync_task_id'] ?? 0)),
            'data_source_id' => max(0, (int)($receipt['data_source_id'] ?? 0)),
            'system_hotel_id' => max(0, (int)($receipt['system_hotel_id'] ?? 0)),
            'platform' => in_array($platform, ['ctrip', 'meituan'], true) ? $platform : '',
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'started_at' => $startedAt,
            'row_ids' => $rowIds,
            'source_trace_ids' => array_slice(array_values(array_unique($traceIds)), 0, 50),
            'observed_platform_hotel_id' => $observedPlatformHotelId,
            'verified_metric_keys' => $metricKeys,
            'capture_strategy' => $captureStrategy,
            'fallback_from' => $fallbackFrom !== '' ? $fallbackFrom : null,
            'fallback_reason' => $fallbackReason !== '' ? $fallbackReason : null,
            'response_evidence_type' => $responseEvidenceType !== ''
                ? $responseEvidenceType
                : null,
            'recipe_plan_hash' => $recipePlanHash !== '' ? $recipePlanHash : null,
            'recipe_count' => $recipeCount,
            'p0_status' => $p0Status,
            'field_fact_status' => $fieldFactStatus,
            'required_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys(
                $receipt['required_traffic_metric_keys'] ?? []
            ),
            'complete_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys(
                $receipt['complete_traffic_metric_keys'] ?? []
            ),
            'missing_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys(
                $receipt['missing_traffic_metric_keys'] ?? []
            ),
            'nonzero_required_metric_rows' => max(0, (int)($receipt['nonzero_required_metric_rows'] ?? 0)),
            'platform_hotel_identifier_status' => $platformHotelIdentifierStatus,
            'page_field_fact_status' => $pageFieldFactStatus,
            'readback_count' => $readbackCount,
            'failure_reason' => $failureReason,
        ];
        if ($dispatcherRunId !== '') {
            $safeReceipt['dispatcher_run_id'] = $dispatcherRunId;
        }
        if ($triggerType !== '') {
            $safeReceipt['trigger_type'] = $triggerType;
        }
        return $safeReceipt;
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeSyncTaskPayloadKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key) {
            $key = trim((string)$key);
            if ($key === '' || $this->isSensitiveConfigKey($key) || preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $key) !== 1) {
                continue;
            }
            $keys[] = $key;
        }

        return array_values(array_slice(array_unique($keys), 0, 30));
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private function sanitizeSyncDiagnosticsForResponse(array $diagnostics, string $fallbackStatus): array
    {
        if ($diagnostics === []) {
            return [];
        }

        $fieldFactStatus = strtolower(trim((string)($diagnostics['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $p0Status = strtolower(trim((string)($diagnostics['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded'], true)) {
            $p0Status = 'unknown';
        }
        $confirmedEmpty = $this->truthy($diagnostics['confirmed_empty'] ?? false);
        $adapterStatus = strtolower(trim((string)($diagnostics['adapter_status'] ?? $fallbackStatus)));
        if (!in_array($adapterStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied', 'not_applicable'], true)) {
            $adapterStatus = 'unknown';
        }

        return [
            'target_date' => $this->normalizeDate($diagnostics['target_date'] ?? null) ?? '',
            'requires_target_date_traffic' => $this->truthy($diagnostics['requires_target_date_traffic'] ?? false),
            'target_date_rows' => max(0, (int)($diagnostics['target_date_rows'] ?? 0)),
            'target_date_traffic_rows' => max(0, (int)($diagnostics['target_date_traffic_rows'] ?? 0)),
            'target_date_traffic_field_fact_ready_count' => max(0, (int)($diagnostics['target_date_traffic_field_fact_ready_count'] ?? 0)),
            'target_date_traffic_field_fact_missing_count' => max(0, (int)($diagnostics['target_date_traffic_field_fact_missing_count'] ?? 0)),
            'required_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['required_traffic_metric_keys'] ?? []),
            'complete_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['complete_traffic_metric_keys'] ?? []),
            'missing_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['missing_traffic_metric_keys'] ?? []),
            'nonzero_required_metric_rows' => max(0, (int)($diagnostics['nonzero_required_metric_rows'] ?? 0)),
            'platform_hotel_identifier_status' => in_array(
                strtolower(trim((string)($diagnostics['platform_hotel_identifier_status'] ?? ''))),
                ['ready', 'unverified'],
                true
            ) ? strtolower(trim((string)$diagnostics['platform_hotel_identifier_status'])) : 'unverified',
            'page_field_fact_status' => in_array(
                strtolower(trim((string)($diagnostics['page_field_fact_status'] ?? ''))),
                ['ready', 'partial'],
                true
            ) ? strtolower(trim((string)$diagnostics['page_field_fact_status'])) : 'partial',
            'field_fact_status' => $fieldFactStatus,
            'p0_status' => $p0Status,
            'capability_states' => $this->sanitizeSyncTaskCapabilityStates($diagnostics['capability_states'] ?? null),
            'capture_section_statuses' => $this->sanitizeSyncCaptureSectionStatuses($diagnostics['capture_section_statuses'] ?? null),
            'missing_inputs' => $this->syncTaskQualityMissingInputFlags($diagnostics['missing_inputs'] ?? []),
            'operator_message' => $this->safeSyncTaskMessage($adapterStatus ?: $fallbackStatus, (string)($diagnostics['operator_message'] ?? '')),
            'adapter_status' => $adapterStatus,
            'confirmed_empty' => $confirmedEmpty,
        ];
    }

    /** @return array<int, string> */
    private function sanitizeSyncDiagnosticMetricKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        return array_values(array_unique(array_filter(array_map(
            static fn($item): string => strtolower(trim((string)$item)),
            $value
        ), static fn(string $item): bool => in_array($item, $allowed, true))));
    }

    /**
     * @return array<string, string>
     */
    private function sanitizeSyncTaskCapabilityStates(mixed $value): array
    {
        $states = [
            'business' => 'unverified',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ];
        if (!is_array($value)) {
            return $states;
        }

        $allowed = ['verified', 'permission_denied', 'capability_unavailable', 'unverified', 'collection_failed'];
        foreach (array_keys($states) as $capability) {
            $candidate = strtolower(trim((string)($value[$capability] ?? '')));
            if (in_array($candidate, $allowed, true)) {
                $states[$capability] = $candidate;
            }
        }

        return $states;
    }

    /**
     * @return array<string, string>
     */
    private function sanitizeSyncCaptureSectionStatuses(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowedSections = ['traffic', 'order_flow', 'orders', 'ads', 'reviews', 'room_types'];
        $allowedStatuses = ['captured', 'empty_confirmed', 'not_applicable', 'not_captured'];
        $statuses = [];
        foreach ($value as $section => $status) {
            $section = strtolower(trim((string)$section));
            $status = strtolower(trim((string)$status));
            if (in_array($section, $allowedSections, true) && in_array($status, $allowedStatuses, true)) {
                $statuses[$section] = $status;
            }
        }
        return $statuses;
    }

    private function safeSyncTaskMessage(string $status, string $message): string
    {
        $message = strtolower(trim($message));
        $knownMessages = [
            'platform data synchronized.' => 'platform_data_synchronized',
            'platform_data_synchronized' => 'platform_data_synchronized',
            'platform_returned_authoritative_empty' => 'platform_returned_authoritative_empty',
            'no business rows were found in payload.' => 'sync_completed_without_saved_rows',
            'sync_completed_without_saved_rows' => 'sync_completed_without_saved_rows',
            'target_date_traffic_ready' => 'target_date_traffic_ready',
            'manual_login_state_not_verified' => 'manual_login_state_not_verified',
            'profile_reused_no_target_date_traffic_rows' => 'profile_reused_no_target_date_traffic_rows',
            'traffic_field_facts_missing' => 'traffic_field_facts_missing',
            'permission_denied' => 'permission_denied',
            'credential_execution_failed' => 'credential_execution_failed',
            'credential_locator_missing' => 'credential_locator_missing',
            'credential_not_ready' => 'credential_not_ready',
            'credential_not_found' => 'credential_not_found',
            'credential_revoked' => 'credential_revoked',
            'credential_scope_invalid' => 'credential_scope_invalid',
            'ota_source_url_not_allowed' => 'ota_source_url_not_allowed',
            'ota_source_inline_secret_requires_migration' => 'ota_source_inline_secret_requires_migration',
            'collection_failed' => 'collection_failed',
            'collection_partial' => 'collection_partial',
            'ads_service_not_opened' => 'ads_service_not_opened',
            'ads_collection_failed' => 'ads_collection_failed',
            'profile_session_unverified' => 'profile_session_unverified',
            'profile_session_expired' => 'profile_session_expired',
            'stale_running_task' => 'stale_running_task',
        ];
        if (isset($knownMessages[$message])) {
            return $knownMessages[$message];
        }

        return match (strtolower(trim($status))) {
            'success' => 'platform_data_synchronized',
            'partial_success' => 'collection_partial',
            'not_applicable' => $message === 'ads_service_not_opened' ? 'ads_service_not_opened' : 'not_applicable',
            'permission_denied', 'unauthorized', 'forbidden' => 'permission_denied',
            'login_expired', 'waiting_login', 'session_expired' => 'login_state_unverified',
            'stale_running' => 'stale_running_task',
            default => 'collection_failed',
        };
    }

    /** @param array<string, mixed> $payload */
    private function isAuthoritativeEmptySyncPayload(array $payload): bool
    {
        return ($payload['sync_summary']['confirmed_empty'] ?? null) === true;
    }

    private function safeOtaExecutionFailureCode(\Throwable $error): string
    {
        $message = strtolower($error->getMessage());
        return match (true) {
            str_contains($message, 'binding_mismatch') => 'binding_mismatch',
            str_contains($message, 'binding_missing') => 'binding_missing',
            str_contains($message, 'binding_unverified') => 'binding_unverified',
            str_contains($message, 'profile_session_expired') => 'profile_session_expired',
            str_contains($message, 'profile_session_unverified') => 'profile_session_unverified',
            str_contains($message, 'current_session_verified'),
            str_contains($message, 'current session proof') => 'current_session_not_verified',
            str_contains($message, 'url host is outside'),
            str_contains($message, 'url must use https'),
            str_contains($message, 'allowed_hosts contains') => 'ota_source_url_not_allowed',
            str_contains($message, 'inline credentials'),
            str_contains($message, 'inline credential'),
            str_contains($message, 'require migration') => 'ota_source_inline_secret_requires_migration',
            str_contains($message, 'locator is missing'),
            str_contains($message, 'invalid credential locator') => 'credential_locator_missing',
            str_contains($message, 'credential is not ready') => 'credential_not_ready',
            str_contains($message, 'credential revoked') => 'credential_revoked',
            str_contains($message, 'credential not found') => 'credential_not_found',
            str_contains($message, 'hotel scope'),
            str_contains($message, 'tenant scope'),
            str_contains($message, 'scope not found'),
            str_contains($message, 'reference does not match') => 'credential_scope_invalid',
            default => 'credential_execution_failed',
        };
    }

    /**
     * @param array<string, mixed> $quality
     * @return array<string, mixed>
     */
    private function sanitizeSyncTaskCollectionQuality(array $quality): array
    {
        $states = ['available', 'partial', 'stale', 'unverified', 'binding_missing', 'permission_denied', 'collection_failed'];
        $state = strtolower(trim((string)($quality['primary_quality_state'] ?? '')));
        if (!in_array($state, $states, true)) {
            $state = 'unverified';
        }
        $allowedFlags = [
            'current_session_verified', 'manual_login_state_verified', 'profile_status_logged_in', 'last_login_verified_at', 'target_date_traffic_rows',
            'traffic_field_facts', 'system_hotel_id_missing', 'data_source_id_missing', 'ota_store_id_missing',
            'profile_id_missing', 'non_ota_platform_source', 'platform_permission_denied', 'task_status_failed',
            'manual_import_provenance_unverified', 'source_ingestion_method_unverified', 'platform_session_not_verified',
            'target_date_missing', 'p0_target_date_evidence_not_ready', 'saved_rows_missing', 'target_date_rows_missing',
            'target_date_traffic_rows_missing', 'target_date_field_facts_partial', 'task_partial_success', 'task_quality_not_verified',
        ];
        $flags = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($quality['quality_flags'] ?? [])
        ), static fn(string $flag): bool => in_array($flag, $allowedFlags, true))));
        $metricScope = strtolower(trim((string)($quality['metric_scope'] ?? '')));
        if (!in_array($metricScope, ['ota_channel', 'unknown'], true)) {
            $metricScope = 'unknown';
        }
        $evidence = is_array($quality['evidence'] ?? null) ? $quality['evidence'] : [];
        $taskStatus = strtolower(trim((string)($evidence['task_status'] ?? '')));
        if (!in_array($taskStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied', 'unknown'], true)) {
            $taskStatus = 'unknown';
        }
        $ingestionMethod = strtolower(trim((string)($evidence['ingestion_method'] ?? '')));
        if (!in_array($ingestionMethod, ['browser_profile', 'profile_browser', 'local_collector', 'manual', 'api', 'unknown'], true)) {
            $ingestionMethod = 'unknown';
        }
        $p0Status = strtolower(trim((string)($evidence['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded', 'unknown'], true)) {
            $p0Status = 'unknown';
        }
        $fieldFactStatus = strtolower(trim((string)($evidence['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded', 'unknown'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $confirmedEmpty = $this->truthy($evidence['confirmed_empty'] ?? false);

        return [
            'primary_quality_state' => $state,
            'quality_flags' => $flags,
            'metric_scope' => $metricScope,
            'evidence_scope' => 'sync_task',
            'target_date' => $this->normalizeDate($quality['target_date'] ?? null) ?? '',
            'data_as_of' => $this->normalizeDate($quality['data_as_of'] ?? null) ?? '',
            'collected_at' => $this->normalizeDateTime($quality['collected_at'] ?? null) ?? '',
            'evidence' => [
                'task_status' => $taskStatus,
                'ingestion_method' => $ingestionMethod,
                'p0_status' => $p0Status,
                'target_date_rows' => max(0, (int)($evidence['target_date_rows'] ?? 0)),
                'target_date_traffic_rows' => max(0, (int)($evidence['target_date_traffic_rows'] ?? 0)),
                'field_fact_status' => $fieldFactStatus,
                'normalized_count' => max(0, (int)($evidence['normalized_count'] ?? 0)),
                'saved_count' => max(0, (int)($evidence['saved_count'] ?? 0)),
                'confirmed_empty' => $confirmedEmpty,
            ],
            'next_action' => $this->sanitizeSyncTaskCollectionQualityAction($quality['next_action'] ?? ''),
        ];
    }

    private function sanitizeSyncTaskCollectionQualityAction(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        $allowed = [
            '',
            'complete_hotel_poi_binding',
            'restore_platform_permission',
            'inspect_collection_failure',
            'verify_task_source_scope',
            'verify_manual_import_provenance',
            'verify_collection_method',
            'verify_platform_login_state',
            'select_target_date',
            'verify_target_date_evidence',
            'collect_target_date_data',
            'complete_missing_target_date_evidence',
        ];
        return in_array($value, $allowed, true) ? $value : 'verify_target_date_evidence';
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array<string, string>> $accessIssues
     * @return array<int, array<string, mixed>>
     */
    private function catalogDataSources($user, array $filters, array &$accessIssues): array
    {
        $scopeFilters = [];
        if (!empty($filters['system_hotel_id'])) {
            $scopeFilters['system_hotel_id'] = (int)$filters['system_hotel_id'];
        }

        try {
            return $this->listDataSources($user, $scopeFilters);
        } catch (\Throwable $e) {
            $accessIssues[] = [
                'area' => 'platform_data_sources',
                'reason' => $e->getMessage(),
            ];
            return [];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array<string, string>> $accessIssues
     * @return array<int, array<string, mixed>>
     */
    private function catalogSyncTasks($user, array $filters, array &$accessIssues): array
    {
        $scopeFilters = ['limit' => 200];
        if (!empty($filters['system_hotel_id'])) {
            $scopeFilters['system_hotel_id'] = (int)$filters['system_hotel_id'];
        }

        try {
            return $this->listSyncTasks($user, $scopeFilters);
        } catch (\Throwable $e) {
            $accessIssues[] = [
                'area' => 'platform_data_sync_tasks',
                'reason' => $e->getMessage(),
            ];
            return [];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array<string, string>> $accessIssues
     * @return array<string, array<string, mixed>>
     */
    private function catalogLatestStoredRows($user, array $filters, array &$accessIssues): array
    {
        try {
            $columns = $this->tableColumns('online_daily_data');
            if (!isset($columns['source'], $columns['data_type'])) {
                $accessIssues[] = [
                    'area' => 'online_daily_data',
                    'reason' => 'source/data_type columns are missing.',
                ];
                return [];
            }

            $fields = ['source', 'data_type'];
            if (isset($columns['system_hotel_id'])) {
                $fields[] = 'system_hotel_id';
            }
            if (isset($columns['update_time'])) {
                $fields[] = 'MAX(update_time) AS last_stored_at';
            }
            if (isset($columns['data_date'])) {
                $fields[] = 'MAX(data_date) AS latest_data_date';
            }
            $fields[] = 'COUNT(*) AS stored_row_count';

            $query = Db::name('online_daily_data')->field(implode(',', $fields));
            if (!empty($filters['system_hotel_id']) && isset($columns['system_hotel_id'])) {
                $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
            }
            $this->applyOnlineDailyScope($query, $user, $columns);

            $groupFields = ['source', 'data_type'];
            if (isset($columns['system_hotel_id'])) {
                $groupFields[] = 'system_hotel_id';
            }
            $rows = $query->group(implode(',', $groupFields))->select()->toArray();
        } catch (\Throwable $e) {
            $accessIssues[] = [
                'area' => 'online_daily_data',
                'reason' => $e->getMessage(),
            ];
            return [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            $platform = strtolower((string)($row['source'] ?? ''));
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? ''));
            if ($platform === '' || $dataType === '') {
                continue;
            }

            $key = $platform . ':' . $dataType;
            $storedCount = (int)($row['stored_row_count'] ?? 0);
            if (!isset($indexed[$key])) {
                $indexed[$key] = [
                    'source' => $platform,
                    'data_type' => $dataType,
                    'stored_row_count' => 0,
                    'last_stored_at' => (string)($row['last_stored_at'] ?? ''),
                    'latest_data_date' => (string)($row['latest_data_date'] ?? ''),
                    'system_hotel_ids' => [],
                ];
            }
            $indexed[$key]['stored_row_count'] += $storedCount;
            if (!empty($row['system_hotel_id'])) {
                $indexed[$key]['system_hotel_ids'][] = (int)$row['system_hotel_id'];
            }
            foreach (['last_stored_at', 'latest_data_date'] as $timeKey) {
                $value = (string)($row[$timeKey] ?? '');
                if ($value !== '' && strcmp($value, (string)$indexed[$key][$timeKey]) > 0) {
                    $indexed[$key][$timeKey] = $value;
                }
            }
        }

        foreach ($indexed as &$row) {
            $row['system_hotel_ids'] = array_values(array_unique(array_map('intval', $row['system_hotel_ids'])));
        }
        unset($row);

        return $indexed;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @param array<string, array<string, mixed>> $latestRows
     * @return array<string, mixed>
     */
    private function buildResourcePlatformStatus(array $definition, string $platform, array $sources, array $tasks, array $latestRows): array
    {
        $dataType = $this->normalizeDataType((string)$definition['data_type']);
        $matchingSources = array_values(array_filter($sources, function (array $source) use ($platform, $dataType): bool {
            return strtolower((string)($source['platform'] ?? '')) === $platform
                && $this->normalizeDataType((string)($source['data_type'] ?? '')) === $dataType
                && (int)($source['enabled'] ?? 1) === 1;
        }));
        $matchingTasks = array_values(array_filter($tasks, function (array $task) use ($platform, $dataType): bool {
            return strtolower((string)($task['platform'] ?? '')) === $platform
                && $this->normalizeDataType((string)($task['data_type'] ?? '')) === $dataType;
        }));

        $latestTask = $this->latestCatalogTask($matchingTasks);
        $latestStored = $latestRows[$platform . ':' . $dataType] ?? null;
        $stats = $latestTask ? $this->decodeConfig($latestTask['stats_json'] ?? []) : [];
        $savedCount = (int)($stats['saved_count'] ?? 0);
        $normalizedCount = (int)($stats['normalized_count'] ?? 0);
        $latestSource = $this->latestCatalogSource($matchingSources);

        $lastSyncTime = (string)($latestTask['finished_at'] ?? $latestTask['started_at'] ?? $latestSource['last_sync_time'] ?? '');
        $lastStoredAt = is_array($latestStored) ? (string)($latestStored['last_stored_at'] ?? '') : '';
        $freshness = $this->catalogFreshness($lastStoredAt);
        $sourceStatus = (string)($latestSource['status'] ?? '');
        $rawTaskStatus = (string)($latestTask['status'] ?? '');
        $taskStatus = self::effectiveSyncTaskStatus($latestTask);
        $taskStale = self::isStaleRunningSyncTask($latestTask);
        $message = (string)($latestTask['message'] ?? $latestSource['last_error'] ?? '');
        if ($taskStale && trim($message) === '') {
            $message = 'stale_running_task';
        }

        $bindingStatus = $matchingSources === [] ? 'unbound' : 'bound';
        $loginStatus = $this->catalogLoginStatus($sourceStatus, $taskStatus, $message, $matchingSources);
        $collectionStatus = $this->catalogCollectionStatus($bindingStatus, $loginStatus, $taskStatus, $freshness, $latestStored !== null);
        $etlStatus = $this->catalogEtlStatus($latestTask, $latestStored, $normalizedCount, $savedCount);

        return [
            'platform' => $platform,
            'resource' => (string)$definition['resource'],
            'data_type' => $dataType,
            'binding_status' => $bindingStatus,
            'login_status' => $loginStatus,
            'collection_status' => $collectionStatus,
            'etl_status' => $etlStatus,
            'freshness' => $freshness,
            'missing_reason' => $this->catalogMissingReason($bindingStatus, $loginStatus, $taskStatus, $etlStatus, $freshness, $message),
            'source_count' => count($matchingSources),
            'ready_source_count' => count(array_filter($matchingSources, static function (array $source): bool {
                return in_array((string)($source['status'] ?? ''), ['ready', 'success'], true);
            })),
            'primary_source_id' => isset($latestSource['id']) ? (int)$latestSource['id'] : null,
            'last_sync_time' => $lastSyncTime,
            'last_stored_at' => $lastStoredAt,
            'latest_data_date' => is_array($latestStored) ? (string)($latestStored['latest_data_date'] ?? '') : '',
            'stored_row_count' => is_array($latestStored) ? (int)($latestStored['stored_row_count'] ?? 0) : 0,
            'latest_task' => $latestTask ? [
                'id' => (int)($latestTask['id'] ?? 0),
                'status' => $taskStatus,
                'raw_status' => $rawTaskStatus,
                'is_stale_running' => $taskStale,
                'stale_age_seconds' => self::syncTaskAgeSeconds($latestTask),
                'started_at' => (string)($latestTask['started_at'] ?? ''),
                'finished_at' => (string)($latestTask['finished_at'] ?? ''),
                'message' => $this->safeSyncTaskMessage($rawTaskStatus ?: $sourceStatus, $message),
                'normalized_count' => $normalizedCount,
                'saved_count' => $savedCount,
            ] : null,
        ];
    }

    /**
     * @param array<string, bool> $columns
     */
    private function applyOnlineDailyScope($query, $user, array $columns): void
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }
        if (!isset($columns['system_hotel_id'])) {
            $query->whereRaw('1=0');
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function latestCatalogTask(array $tasks): ?array
    {
        $latest = null;
        $latestTimestamp = null;
        foreach ($tasks as $task) {
            $timestamp = self::syncTaskLatestTimestamp($task, ['finished_at', 'update_time', 'updated_at', 'started_at', 'create_time', 'created_at']);
            if ($latest === null || ($timestamp !== null && ($latestTimestamp === null || $timestamp > $latestTimestamp))) {
                $latest = $task;
                $latestTimestamp = $timestamp;
            }
        }
        return $latest;
    }

    /**
     * @param array<string, mixed>|null $task
     */
    public static function effectiveSyncTaskStatus(?array $task): string
    {
        $status = strtolower(trim((string)($task['status'] ?? '')));
        if ($status === '') {
            return '';
        }

        return self::isStaleRunningSyncTask($task) ? 'stale_running' : $status;
    }

    /**
     * @param array<string, mixed>|null $task
     */
    public static function isStaleRunningSyncTask(?array $task, int $staleSeconds = self::STALE_RUNNING_TASK_SECONDS): bool
    {
        if (empty($task)) {
            return false;
        }

        $status = strtolower(trim((string)($task['status'] ?? '')));
        if (!in_array($status, self::ACTIVE_SYNC_TASK_STATUSES, true)) {
            return false;
        }

        $ageSeconds = self::syncTaskAgeSeconds($task);
        return $ageSeconds !== null && $ageSeconds > max(60, $staleSeconds);
    }

    /**
     * @param array<string, mixed>|null $task
     */
    public static function syncTaskAgeSeconds(?array $task): ?int
    {
        if (empty($task)) {
            return null;
        }

        $timestamp = self::syncTaskLatestTimestamp($task, ['update_time', 'updated_at', 'started_at', 'create_time', 'created_at']);
        if ($timestamp === null) {
            return null;
        }

        return max(0, time() - $timestamp);
    }

    /**
     * @param array<string, mixed>|null $task
     * @param array<int, string> $keys
     */
    private static function syncTaskLatestTimestamp(?array $task, array $keys): ?int
    {
        if (empty($task)) {
            return null;
        }

        $latest = null;
        foreach ($keys as $key) {
            $timeText = trim((string)($task[$key] ?? ''));
            if ($timeText === '') {
                continue;
            }
            $timestamp = strtotime($timeText);
            if ($timestamp === false) {
                continue;
            }
            if ($latest === null || $timestamp > $latest) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<string, mixed>|null
     */
    private function latestCatalogSource(array $sources): ?array
    {
        $latest = null;
        $latestTime = '';
        foreach ($sources as $source) {
            $time = (string)($source['last_sync_time'] ?? $source['update_time'] ?? $source['create_time'] ?? '');
            if ($latest === null || strcmp($time, $latestTime) > 0) {
                $latest = $source;
                $latestTime = $time;
            }
        }
        return $latest;
    }

    private function catalogFreshness(string $lastStoredAt): string
    {
        if ($lastStoredAt === '') {
            return 'missing';
        }
        $timestamp = strtotime($lastStoredAt);
        if ($timestamp === false) {
            return 'unknown';
        }
        return (time() - $timestamp) <= self::COLLECTION_RESOURCE_FRESH_HOURS * 3600 ? 'fresh' : 'stale';
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     */
    private function catalogLoginStatus(string $sourceStatus, string $taskStatus, string $message, array $sources): string
    {
        $text = strtolower($sourceStatus . ' ' . $taskStatus . ' ' . $message);
        if ($sources === []) {
            return 'unbound';
        }
        if ($taskStatus === 'stale_running') {
            return 'task_stale_running';
        }
        if (str_contains($text, 'waiting_config')
            || str_contains($text, 'login_required')
            || str_contains($text, 'login expired')
            || str_contains($text, 'login session is not ready')
            || str_contains($text, 'profile is not prepared')
        ) {
            return 'login_required';
        }
        if (str_contains($text, 'captcha') || str_contains($text, 'verification') || str_contains($text, 'limit')) {
            return 'manual_intervention_required';
        }
        if ($taskStatus === 'running') {
            return 'collecting';
        }
        if (in_array($sourceStatus, ['ready', 'success'], true)) {
            return 'authorized';
        }
        if ($sourceStatus === 'failed') {
            return 'unknown';
        }
        return 'configured';
    }

    private function catalogCollectionStatus(string $bindingStatus, string $loginStatus, string $taskStatus, string $freshness, bool $hasStoredRows): string
    {
        if ($bindingStatus === 'unbound') {
            return 'unbound';
        }
        if ($taskStatus === 'stale_running') {
            return 'stale_running';
        }
        if (in_array($loginStatus, ['login_required', 'manual_intervention_required'], true)) {
            return $loginStatus;
        }
        if ($taskStatus === 'running') {
            return 'collecting';
        }
        if ($taskStatus === 'failed') {
            return 'failed';
        }
        if ($taskStatus === 'partial_success') {
            return 'partial_success';
        }
        if ($hasStoredRows && $freshness === 'fresh') {
            return 'ready';
        }
        if ($hasStoredRows && $freshness === 'stale') {
            return 'stale';
        }
        return 'ready_to_sync';
    }

    /**
     * @param array<string, mixed>|null $latestTask
     * @param array<string, mixed>|null $latestStored
     */
    private function catalogEtlStatus(?array $latestTask, ?array $latestStored, int $normalizedCount, int $savedCount): string
    {
        if ($latestTask === null && $latestStored === null) {
            return 'not_started';
        }
        if (self::isStaleRunningSyncTask($latestTask)) {
            return 'stale_running';
        }
        if ($latestTask !== null && (string)($latestTask['status'] ?? '') === 'running') {
            return 'pending';
        }
        if ($latestTask !== null && (string)($latestTask['status'] ?? '') === 'failed') {
            return 'capture_failed';
        }
        if ($savedCount > 0 && $latestStored !== null) {
            return 'stored_displayable';
        }
        if ($normalizedCount > 0 && $savedCount === 0) {
            return 'normalized_not_stored';
        }
        if ($latestTask !== null && (string)($latestTask['status'] ?? '') === 'success' && $savedCount === 0) {
            return 'capture_success_not_stored';
        }
        if ($latestStored !== null) {
            return 'stored_from_previous_task';
        }
        return 'not_stored';
    }

    private function catalogMissingReason(string $bindingStatus, string $loginStatus, string $taskStatus, string $etlStatus, string $freshness, string $message): string
    {
        if ($bindingStatus === 'unbound') {
            return 'data_source_not_bound';
        }
        if ($taskStatus === 'stale_running' || $loginStatus === 'task_stale_running' || $etlStatus === 'stale_running') {
            return 'stale_running_task';
        }
        if ($loginStatus === 'login_required') {
            return 'profile_login_required';
        }
        if ($loginStatus === 'manual_intervention_required') {
            return 'manual_intervention_required';
        }
        if ($taskStatus === 'failed') {
            return $message !== '' ? $this->safeSyncTaskMessage($taskStatus, $message) : 'latest_task_failed';
        }
        if (in_array($etlStatus, ['capture_success_not_stored', 'normalized_not_stored', 'not_stored'], true)) {
            if ($message !== '' && $taskStatus !== 'success') {
                return $this->safeSyncTaskMessage($taskStatus, $message);
            }
            return $etlStatus;
        }
        if ($freshness === 'stale') {
            return 'data_older_than_' . self::COLLECTION_RESOURCE_FRESH_HOURS . 'h';
        }
        if ($freshness === 'missing') {
            return 'no_displayable_rows';
        }
        return '';
    }

}
