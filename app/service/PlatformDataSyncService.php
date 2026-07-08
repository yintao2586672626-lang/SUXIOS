<?php
declare(strict_types=1);

namespace app\service;

use app\contract\DataSourceAdapter;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Db;

final class PlatformDataSyncService
{
    private const RAW_RECORD_PAYLOAD_LIMIT_BYTES = 262144;
    private const COLLECTION_RESOURCE_FRESH_HOURS = 24;
    private const STALE_RUNNING_TASK_SECONDS = 3600;
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
                'source_keys' => ['order_id', 'orderId', 'bookingId', 'book_order_num', 'order_count', 'orderCount', 'orders'],
            ],
        ],
        'peer_rank' => [
            [
                'metric_key' => 'peer_rank_value',
                'normalized_field' => 'data_value',
                'storage_table' => 'online_daily_data',
                'storage_field' => 'data_value',
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
                'source_keys' => ['flow_rate', 'flowRate', 'cvr', 'ctr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate'],
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

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /**
     * @param array<int, DataSourceAdapter>|null $adapters
     */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            new ManualImportDataSourceAdapter(),
            new CtripBrowserProfileDataSourceAdapter(),
            new MeituanBrowserProfileDataSourceAdapter(),
            new ApiDataSourceAdapter(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function collectionResourceDefinitions(): array
    {
        return array_values(self::COLLECTION_RESOURCE_DEFINITIONS);
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
                'ota_collection_mainline' => 'browser_profile_authorization',
                'ota_password_custody' => 'not_supported',
                'cookie_api_role' => 'p1_profile_derived_fast_path_or_backfill',
                'profile_login_state' => 'manual_login_state_verified_required',
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
            $dataType = $this->normalizeDataType(
                in_array($sourceIngestionMethod, ['browser_profile', 'profile_browser'], true) && $rowDataType !== ''
                    ? $rowDataType
                    : ($sourceDataType !== '' ? $sourceDataType : ($rowDataType !== '' ? $rowDataType : 'business'))
            );
            if ($this->isCommentDataType($dataType) && !$this->isReviewCollectionAllowed($source, $payload, $dataType)) {
                continue;
            }
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
            if (($platformIdentifierEvidence['present'] ?? false) === true) {
                $raw['platform_hotel_identifier_present'] = true;
                $raw['platform_hotel_identifier_source'] = (string)$platformIdentifierEvidence['source'];
                $raw['platform_hotel_identifier_proof'] = (string)$platformIdentifierEvidence['proof'];
            }
            $rowDateSource = $this->stringValue($row, ['date_source', 'dateSource', 'data_date_source', 'dataDateSource', '_date_source', '_data_date_source']);
            if ($rowDateSource !== '') {
                $raw['date_source'] = $rowDateSource;
            }

            $normalizedRow = [
                'hotel_id' => $this->stringValue($row, ['hotel_id', 'hotelId', 'poi_id', 'poiId', 'external_hotel_id']) ?: (string)($source['external_hotel_id'] ?? ''),
                'hotel_name' => $this->stringValue($row, ['hotel_name', 'hotelName', 'poi_name', 'poiName', 'name']) ?: (string)($source['hotel_name'] ?? $source['name'] ?? ''),
                'data_date' => $date,
                'amount' => $this->amountValue($row, $dataType),
                'quantity' => $this->quantityValue($row, $dataType),
                'book_order_num' => $this->orderCountValue($row, $dataType),
                'comment_score' => $this->numericValue($row, ['comment_score', 'rating', 'score']),
                'qunar_comment_score' => $this->numericValue($row, ['qunar_comment_score', 'qunar_score']),
                'system_hotel_id' => (int)($source['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0) ?: null,
                'tenant_id' => (int)($source['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0) ?: null,
                'data_value' => $this->dataValue($row, $dataType),
                'source' => $platform,
                'dimension' => $this->stringValue($row, ['dimension', 'dim_name', '_dimName']) ?: ($dataType === 'review' ? $this->reviewDimensionValue($row) : ''),
                'data_type' => $dataType,
                'platform' => $this->stringValue($row, ['platform']) ?: $platform,
                'compare_type' => $this->stringValue($row, ['compare_type', 'compareType', 'rank_type', 'rankType']),
                'list_exposure' => (int)$this->numericValue($row, ['mt_exposure', 'list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv']),
                'detail_exposure' => (int)$this->numericValue($row, ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount', 'visitors', 'visitorTotal', 'pv', 'uv']),
                'flow_rate' => $this->numericValue($row, ['flow_rate', 'flowRate', 'cvr', 'ctr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate']),
                'order_filling_num' => (int)$this->numericValue($row, ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'clickCount', 'clicks']),
                'order_submit_num' => (int)$this->numericValue($row, ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'order_submit_num', 'orderSubmitNum', 'bookings', 'bookingCount', 'orderCount', 'orderQuantity', 'orderNum', 'orders']),
                'validation_status' => 'normal',
                'validation_flags' => json_encode([], JSON_UNESCAPED_UNICODE),
                'data_source_id' => isset($source['id']) ? (int)$source['id'] : null,
                'sync_task_id' => $syncTaskId,
                'ingestion_method' => (string)($source['ingestion_method'] ?? 'manual'),
                'source_trace_id' => $traceId,
                'data_period' => $periodMeta['data_period'],
                'snapshot_time' => $periodMeta['snapshot_time'],
                'snapshot_bucket' => $periodMeta['snapshot_bucket'],
                'is_final' => $periodMeta['is_final'],
            ];

            $fieldFacts = $this->buildNormalizedFieldFacts($row, $dataType, $normalizedRow, $traceId);
            if ($fieldFacts !== []) {
                $raw['field_facts'] = $fieldFacts;
                $raw['field_fact_summary'] = $this->summarizeNormalizedFieldFacts($fieldFacts);
            }
            $normalizedRow['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $normalized[] = $normalizedRow;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $normalizedRow
     * @return array<int, array<string, mixed>>
     */
    private function buildNormalizedFieldFacts(array $row, string $dataType, array $normalizedRow, string $rowSourceTraceId = ''): array
    {
        $dataType = $this->normalizeDataType($dataType);
        $definitions = self::NORMALIZED_FIELD_FACT_DEFINITIONS[$dataType] ?? [];
        if ($definitions === []) {
            return [];
        }

        $facts = [];
        foreach ($definitions as $definition) {
            $sourceKeys = is_array($definition['source_keys'] ?? null) ? $definition['source_keys'] : [];
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
                'missing_state' => (string)$definition['missing_state'],
                'stored_value_present' => $sourceKey !== '' && $this->normalizedFieldHasStoredValue($normalizedRow, $normalizedField),
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
            if ($this->stringValue($candidate, $keys) !== '') {
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

        return $traceId !== '' && $sourceUrlHash !== '';
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
        $query = Db::name('platform_data_sources')->order('id', 'desc');
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
        return array_map([$this, 'sanitizeSourceRow'], $rows);
    }

    public function saveDataSource($user, array $payload): array
    {
        $source = $this->normalizeSourcePayload($payload);
        $this->assertCanUseHotel($user, (int)$source['system_hotel_id'], 'can_fetch_online_data');
        $hasSecretInput = false;
        foreach (['secret', 'secret_json', 'cookies', 'cookie', 'token', 'api_key', 'authorization', 'password', 'spidertoken', 'mtgsig'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_array($value) ? !empty($value) : trim((string)$value) !== '') {
                $hasSecretInput = true;
                break;
            }
        }

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
        if (isset($this->tableColumns('platform_data_sources')['tenant_id'])) {
            $data['tenant_id'] = (int)$source['system_hotel_id'] ?: null;
        }

        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            $existing = Db::name('platform_data_sources')->where('id', $id)->find();
            if (!$existing) {
                throw new RuntimeException('Data source not found.', 404);
            }
            $this->assertCanUseHotel($user, (int)($existing['system_hotel_id'] ?? 0), 'can_fetch_online_data');
            if (!$hasSecretInput) {
                unset($data['secret_json']);
            }
            Db::name('platform_data_sources')->where('id', $id)->update($data);
        } else {
            $data['created_by'] = (int)($user->id ?? 0) ?: null;
            $data['create_time'] = $now;
            $id = (int)Db::name('platform_data_sources')->insertGetId($data);
        }

        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        return $this->sanitizeSourceRow($row ?: []);
    }

    public function deleteDataSource($user, int $id): bool
    {
        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $this->assertCanUseHotel($user, (int)($row['system_hotel_id'] ?? 0), 'can_delete_online_data');
        Db::name('platform_data_sources')->where('id', $id)->update([
            'enabled' => 0,
            'status' => 'disabled',
            'updated_by' => (int)($user->id ?? 0) ?: null,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function syncDataSource($user, int $id, array $options = []): array
    {
        $syncStartedAt = microtime(true);
        $timing = $this->emptySyncTiming();
        $source = $this->loadSource($id);
        $this->assertCanUseHotel($user, (int)($source['system_hotel_id'] ?? 0), 'can_fetch_online_data');

        if ((int)($source['enabled'] ?? 0) !== 1) {
            throw new RuntimeException('Data source is disabled.', 422);
        }

        $taskId = $this->createTask($source, $user, (string)($options['trigger_type'] ?? 'manual'));
        try {
            $adapter = $this->resolveAdapter($source);
            $this->assertBrowserProfileBackgroundSyncLoginVerified($source, $options);
            $phaseStartedAt = microtime(true);
            $result = $adapter->fetch($source, $options);
            $timing['capture_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $this->refreshDatabaseConnectionAfterExternalFetch();
            $payload = $this->applySyncOptionPeriodMetadata($result['payload'] ?? [], $options);
            if (($result['status'] ?? '') !== 'success') {
                $payload['sync_diagnostics'] = $this->buildSyncDiagnostics([], 0, $source, $options, $payload, (string)$result['status'], (string)$result['message']);
                return $this->finishTask($taskId, $source, (string)$result['status'], (string)$result['message'], 0, 0, $payload, $timing, $syncStartedAt);
            }

            $phaseStartedAt = microtime(true);
            $this->storeRawRecord($source, $taskId, $payload, $result['http_status'] ?? null);
            $timing['raw_store_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $phaseStartedAt = microtime(true);
            $rows = $this->normalizeRowsFromPayload(is_array($payload) ? $payload : [], $source, $taskId);
            $timing['normalize_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);
            $phaseStartedAt = microtime(true);
            $saved = $this->saveNormalizedRows($rows);
            $timing['daily_rows_save_elapsed_ms'] = $this->elapsedMilliseconds($phaseStartedAt);

            $status = $saved > 0 ? 'success' : 'partial_success';
            $message = $saved > 0 ? 'Platform data synchronized.' : 'No business rows were found in payload.';
            $diagnostics = $this->buildSyncDiagnostics($rows, $saved, $source, $options, $payload, $status, $message);
            if ((string)($diagnostics['p0_status'] ?? '') !== 'ready' && !empty($diagnostics['requires_target_date_traffic'])) {
                $status = 'partial_success';
                $message = (string)($diagnostics['operator_message'] ?? 'profile_reused_but_target_date_traffic_not_ready');
            }
            $payload['sync_diagnostics'] = $diagnostics;
            return $this->finishTask($taskId, $source, $status, $message, count($rows), $saved, $payload, $timing, $syncStartedAt);
        } catch (\Throwable $e) {
            $this->refreshDatabaseConnectionAfterExternalFetch();
            $payload = [
                'sync_diagnostics' => $this->buildSyncDiagnostics([], 0, $source, $options, [], 'failed', $e->getMessage()),
            ];
            return $this->finishTask($taskId, $source, 'failed', $e->getMessage(), 0, 0, $payload, $timing, $syncStartedAt);
        }
    }

    public function importRows($user, array $payload): array
    {
        $sourceId = (int)($payload['data_source_id'] ?? $payload['source_id'] ?? 0);
        if ($sourceId <= 0) {
            $source = $this->saveDataSource($user, [
                'name' => $payload['name'] ?? 'Manual import',
                'platform' => $payload['platform'] ?? 'custom',
                'data_type' => $payload['data_type'] ?? 'business',
                'system_hotel_id' => $payload['system_hotel_id'] ?? 0,
                'ingestion_method' => 'manual',
            ]);
            $sourceId = (int)$source['id'];
        }

        return $this->syncDataSource($user, $sourceId, [
            'trigger_type' => 'manual_import',
            'payload' => ['rows' => $payload['rows'] ?? $payload['data'] ?? []],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseImportFile(string $path, string $originalName): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Import file not found.', 422);
        }
        if ((int)filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('Import file exceeds 5MB.', 422);
        }

        $extension = strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));
        $rows = match ($extension) {
            'json' => $this->parseJsonImportFile($path),
            'csv' => $this->parseCsvImportFile($path),
            'xlsx' => $this->parseXlsxImportFile($path),
            default => throw new RuntimeException('Only JSON, CSV and XLSX imports are supported.', 422),
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
        return $query->limit(max(1, min(200, (int)($filters['limit'] ?? 50))))->select()->toArray();
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
                'message' => $message,
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
        $latestTime = '';
        foreach ($tasks as $task) {
            $time = (string)($task['finished_at'] ?? $task['started_at'] ?? $task['update_time'] ?? $task['create_time'] ?? '');
            if ($latest === null || strcmp($time, $latestTime) > 0) {
                $latest = $task;
                $latestTime = $time;
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

        $timeText = trim((string)(
            $task['update_time']
            ?? $task['updated_at']
            ?? $task['started_at']
            ?? $task['create_time']
            ?? $task['created_at']
            ?? ''
        ));
        if ($timeText === '') {
            return null;
        }

        $timestamp = strtotime($timeText);
        if ($timestamp === false) {
            return null;
        }

        return max(0, time() - $timestamp);
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
            return $message !== '' ? $message : 'latest_task_failed';
        }
        if (in_array($etlStatus, ['capture_success_not_stored', 'normalized_not_stored', 'not_stored'], true)) {
            return $message !== '' ? $message : $etlStatus;
        }
        if ($freshness === 'stale') {
            return 'data_older_than_' . self::COLLECTION_RESOURCE_FRESH_HOURS . 'h';
        }
        if ($freshness === 'missing') {
            return 'no_displayable_rows';
        }
        return '';
    }

    private function normalizeSourcePayload(array $payload): array
    {
        $config = $this->decodeConfig($payload['config_json'] ?? $payload['config'] ?? []);
        $secret = $this->decodeConfig($payload['secret_json'] ?? $payload['secret'] ?? []);
        foreach (['cookies', 'cookie', 'token', 'api_key', 'authorization', 'password', 'spidertoken', 'mtgsig'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $secret[$key === 'cookie' ? 'cookies' : $key] = (string)$payload[$key];
            }
        }
        foreach (['url', 'request_url', 'method', 'allowed_hosts', 'payload', 'payload_json', 'headers', 'external_hotel_id', 'hotel_name', 'profile_id', 'profileId', 'browser_profile_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'store_id', 'storeId', 'poi_id', 'poiId', 'poi_name', 'poiName', 'partner_id', 'partnerId', 'ads_url', 'adsUrl', 'capture_sections', 'captureSections', 'profile_sections', 'section_concurrency', 'sectionConcurrency', 'ctrip_section_concurrency', 'ctripSectionConcurrency', 'not_applicable_sections', 'notApplicableSections', 'excluded_sections', 'excludedSections', 'allow_review', 'authorized_review_collection', 'review_collection_enabled'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $config[$key] = $payload[$key];
            }
        }

        $method = (string)($payload['ingestion_method'] ?? 'manual');
        $platform = strtolower(trim((string)($payload['platform'] ?? 'custom'))) ?: 'custom';
        $this->assertNoOtaPasswordCustody($platform, $secret);
        $status = in_array($method, ['manual', 'import_json', 'import_csv', 'import_excel'], true) || !empty($config) || !empty($secret)
            ? 'ready'
            : 'waiting_config';
        $dataType = $this->normalizeDataType(trim((string)($payload['data_type'] ?? 'business')) ?: 'business');
        $sourceForPolicy = [
            'data_type' => $dataType,
            'ingestion_method' => $method,
            'config' => $config,
            'secret' => $secret,
        ];
        if ($this->isCommentDataType($dataType) && !$this->isReviewCollectionAllowed($sourceForPolicy, $payload, $dataType)) {
            throw new RuntimeException('Comment/review detail storage requires explicit authorization; aggregate metrics are allowed.', 422);
        }

        return [
            'name' => trim((string)($payload['name'] ?? '')) ?: 'Platform data source',
            'system_hotel_id' => is_numeric($payload['system_hotel_id'] ?? $payload['hotel_id'] ?? null) ? (int)($payload['system_hotel_id'] ?? $payload['hotel_id']) : 0,
            'platform' => $platform,
            'data_type' => $dataType,
            'ingestion_method' => $method,
            'status' => $status,
            'enabled' => (int)($payload['enabled'] ?? 1),
            'config' => $config,
            'secret' => $secret,
        ];
    }

    /**
     * @param array<string, mixed> $secret
     */
    private function assertNoOtaPasswordCustody(string $platform, array $secret): void
    {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return;
        }
        if (!array_key_exists('password', $secret)) {
            return;
        }
        $password = $secret['password'];
        if (!is_scalar($password) || trim((string)$password) === '') {
            return;
        }

        throw new RuntimeException('OTA account password custody is not supported. Use browser Profile login and manual_login_state_verified instead.', 422);
    }

    private function loadSource(int $id): array
    {
        $row = Db::name('platform_data_sources')->where('id', $id)->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $row['config'] = $this->decodeConfig($row['config_json'] ?? []);
        $row['secret'] = $this->decodeConfig($row['secret_json'] ?? []);
        return $row;
    }

    private function resolveAdapter(array $source): DataSourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($source)) {
                return $adapter;
            }
        }
        throw new RuntimeException('No adapter is available for this data source.', 422);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     */
    private function assertBrowserProfileBackgroundSyncLoginVerified(array $source, array $options): void
    {
        $missing = $this->browserProfileBackgroundSyncLoginMissingRequirements($source, $options);
        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'browser_profile background sync requires manual_login_state_verified, profile_status=logged_in, and last_login_verified_at before capture.',
            422
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    private function browserProfileBackgroundSyncLoginMissingRequirements(array $source, array $options): array
    {
        if (!$this->isOtaBrowserProfileSource($source) || $this->browserProfileSyncIsInteractive($options)) {
            return [];
        }

        $config = $this->decodeConfig($source['config'] ?? $source['config_json'] ?? []);
        $missing = [];
        if (!$this->truthy($config['manual_login_state_verified'] ?? null)) {
            $missing[] = 'manual_login_state_verified';
        }

        $profileStatus = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
        if (!in_array($profileStatus, ['logged_in', 'authorized'], true)) {
            $missing[] = 'profile_status_logged_in';
        }

        $lastVerifiedAt = trim((string)(
            $config['last_login_verified_at']
            ?? $config['profile_login_verified_at']
            ?? $config['last_profile_login_at']
            ?? ''
        ));
        if ($lastVerifiedAt === '') {
            $missing[] = 'last_login_verified_at';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function isOtaBrowserProfileSource(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        return in_array($platform, ['ctrip', 'meituan'], true)
            && in_array($method, ['browser_profile', 'profile_browser'], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function browserProfileSyncIsInteractive(array $options): bool
    {
        return $this->truthy($options['interactive_browser'] ?? $options['interactiveBrowser'] ?? false);
    }

    private function refreshDatabaseConnectionAfterExternalFetch(): void
    {
        try {
            Db::connect()->close();
            Db::connect(null, true);
        } catch (\Throwable) {
            // Let the next write expose any real database failure.
        }
    }

    /**
     * @return array<string, int>
     */
    private function emptySyncTiming(): array
    {
        return [
            'capture_elapsed_ms' => 0,
            'raw_store_elapsed_ms' => 0,
            'normalize_elapsed_ms' => 0,
            'daily_rows_save_elapsed_ms' => 0,
            'finish_task_elapsed_ms' => 0,
            'total_elapsed_ms' => 0,
        ];
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<string, mixed> $timing
     * @return array<string, int>
     */
    private function normalizeSyncTiming(array $timing): array
    {
        $normalized = $this->emptySyncTiming();
        foreach ($normalized as $key => $_) {
            $normalized[$key] = max(0, (int)($timing[$key] ?? 0));
        }
        return $normalized;
    }

    private function createTask(array $source, $user, string $triggerType): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'data_source_id' => (int)$source['id'],
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'platform' => (string)$source['platform'],
            'data_type' => (string)$source['data_type'],
            'ingestion_method' => (string)$source['ingestion_method'],
            'trigger_type' => $triggerType,
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'started_at' => $now,
            'requested_by' => (int)($user->id ?? 0) ?: null,
            'create_time' => $now,
            'update_time' => $now,
        ];
        if (isset($this->tableColumns('platform_data_sync_tasks')['tenant_id'])) {
            $data['tenant_id'] = (int)($source['system_hotel_id'] ?? 0) ?: null;
        }

        return (int)Db::name('platform_data_sync_tasks')->insertGetId($data);
    }

    private function finishTask(int $taskId, array $source, string $status, string $message, int $normalizedCount, int $savedCount, array $payload, array $timing = [], ?float $syncStartedAt = null): array
    {
        $finishStartedAt = microtime(true);
        $now = date('Y-m-d H:i:s');
        $timing = $this->normalizeSyncTiming($timing);
        $stats = [
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'payload_keys' => array_slice(array_keys($payload), 0, 30),
        ];
        if (!empty($payload['error_summary'])) {
            $stats['error_summary'] = mb_substr((string)$payload['error_summary'], 0, 500);
        }
        if (is_array($payload['sync_diagnostics'] ?? null)) {
            $stats['sync_diagnostics'] = $payload['sync_diagnostics'];
        }
        foreach (['data_period', 'snapshot_time', 'snapshot_bucket'] as $periodKey) {
            if (!empty($payload[$periodKey])) {
                $stats[$periodKey] = (string)$payload[$periodKey];
            }
        }
        $nextRetryAt = in_array($status, ['failed', 'partial_success'], true) ? date('Y-m-d H:i:s', time() + 900) : null;

        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'status' => $status,
            'finished_at' => $now,
            'next_retry_at' => $nextRetryAt,
            'message' => $message,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ]);
        Db::name('platform_data_sources')->where('id', (int)$source['id'])->update([
            'last_sync_time' => $now,
            'last_sync_status' => $status,
            'last_error' => in_array($status, ['success'], true) ? null : $message,
            'status' => $status === 'success' ? 'success' : $status,
            'update_time' => $now,
        ]);
        $timing['finish_task_elapsed_ms'] = $this->elapsedMilliseconds($finishStartedAt);
        if ($syncStartedAt !== null) {
            $timing['total_elapsed_ms'] = $this->elapsedMilliseconds($syncStartedAt);
        }
        $stats = array_merge($stats, $timing, ['timing' => $timing]);
        Db::name('platform_data_sync_tasks')->where('id', $taskId)->update([
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $this->logSync($taskId, $source, $status === 'success' ? 'info' : 'warning', 'sync_finished', $message, $stats);

        return [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $message,
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'next_retry_at' => $nextRetryAt,
            'timing' => $timing,
            'sync_diagnostics' => is_array($payload['sync_diagnostics'] ?? null) ? $payload['sync_diagnostics'] : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildSyncDiagnostics(array $rows, int $savedCount, array $source, array $options, array $payload, string $adapterStatus, string $adapterMessage): array
    {
        $targetDate = $this->syncTargetDate($options, $payload);
        $dataTypes = [];
        $targetRows = 0;
        $targetTrafficRows = 0;
        $targetTrafficFieldFactReady = 0;
        $targetTrafficFieldFactMissing = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowDate = $this->normalizeDate($row['data_date'] ?? $row['dataDate'] ?? null);
            if ($rowDate !== $targetDate) {
                continue;
            }
            $targetRows++;
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $row['dataType'] ?? $source['data_type'] ?? ''));
            if ($dataType !== '') {
                $dataTypes[$dataType] = true;
            }
            if ($dataType === 'traffic') {
                $targetTrafficRows++;
                if ($this->normalizedRowHasFieldFactEvidence($row)) {
                    $targetTrafficFieldFactReady++;
                } else {
                    $targetTrafficFieldFactMissing++;
                }
            }
        }

        $requiresTraffic = $this->syncRequiresTargetDateTrafficEvidence($source, $options, $payload);
        $fieldFactStatus = $targetTrafficRows <= 0
            ? 'not_loaded'
            : ($targetTrafficFieldFactReady > 0 && $targetTrafficFieldFactMissing === 0 ? 'ready' : ($targetTrafficFieldFactReady > 0 ? 'partial' : 'missing'));
        $missingInputs = [];
        if ($requiresTraffic && $targetTrafficRows <= 0) {
            $missingInputs[] = 'target_date_traffic_rows';
        }
        if ($requiresTraffic && $targetTrafficRows > 0 && $targetTrafficFieldFactReady <= 0) {
            $missingInputs[] = 'traffic_field_facts';
        }
        foreach ($this->browserProfileBackgroundSyncLoginMissingRequirements($source, $options) as $missingLoginRequirement) {
            if (!in_array($missingLoginRequirement, $missingInputs, true)) {
                $missingInputs[] = $missingLoginRequirement;
            }
        }
        $p0Status = $missingInputs !== []
            ? 'blocked'
            : ($requiresTraffic ? 'ready' : ($savedCount > 0 ? 'not_required' : 'not_loaded'));
        $operatorMessage = 'target_date_traffic_ready';
        if (in_array('manual_login_state_verified', $missingInputs, true)
            || in_array('profile_status_logged_in', $missingInputs, true)
            || in_array('last_login_verified_at', $missingInputs, true)
        ) {
            $operatorMessage = 'manual_login_state_not_verified';
        } elseif (in_array('target_date_traffic_rows', $missingInputs, true)) {
            $operatorMessage = 'profile_reused_no_target_date_traffic_rows';
        } elseif (in_array('traffic_field_facts', $missingInputs, true)) {
            $operatorMessage = 'traffic_field_facts_missing';
        } elseif ($adapterStatus !== 'success') {
            $operatorMessage = $adapterMessage !== '' ? $adapterMessage : $adapterStatus;
        }

        return [
            'target_date' => $targetDate,
            'requires_target_date_traffic' => $requiresTraffic,
            'target_date_rows' => $targetRows,
            'target_date_traffic_rows' => $targetTrafficRows,
            'target_date_data_types' => array_keys($dataTypes),
            'target_date_traffic_field_fact_ready_count' => $targetTrafficFieldFactReady,
            'target_date_traffic_field_fact_missing_count' => $targetTrafficFieldFactMissing,
            'field_fact_status' => $fieldFactStatus,
            'p0_status' => $p0Status,
            'missing_inputs' => $missingInputs,
            'operator_message' => $operatorMessage,
            'adapter_status' => $adapterStatus,
            'adapter_message' => mb_substr($adapterMessage, 0, 240),
        ];
    }

    private function syncTargetDate(array $options, array $payload): string
    {
        $date = $this->normalizeDate(
            $options['target_date']
            ?? $options['targetDate']
            ?? $options['data_date']
            ?? $options['dataDate']
            ?? $payload['target_date']
            ?? $payload['targetDate']
            ?? $payload['data_date']
            ?? $payload['dataDate']
            ?? ($payload['data_source_capture']['data_date'] ?? null)
        );
        return $date ?? date('Y-m-d');
    }

    private function syncRequiresTargetDateTrafficEvidence(array $source, array $options, array $payload): bool
    {
        if (!$this->isOtaBrowserProfileSource($source)) {
            return false;
        }

        $trigger = strtolower(trim((string)($options['trigger_type'] ?? $options['triggerType'] ?? '')));
        if (in_array($trigger, ['daily_profile_reuse', 'profile_login_after_login', 'profile_login_after_sync', 'profile_login_verified_sync'], true)) {
            return true;
        }
        $dataType = $this->normalizeDataType((string)($source['data_type'] ?? $options['data_type'] ?? $options['dataType'] ?? ''));
        if ($dataType === 'traffic') {
            return true;
        }

        $sectionText = strtolower(json_encode([
            $options['capture_sections'] ?? null,
            $options['captureSections'] ?? null,
            $options['sections'] ?? null,
            $payload['data_source_capture']['capture_sections'] ?? null,
            $payload['sync_summary'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return preg_match('/traffic|flow|core|default|business_overview|traffic_report/', $sectionText) === 1;
    }

    private function normalizedRowHasFieldFactEvidence(array $row): bool
    {
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return false;
        }

        $summary = is_array($raw['field_fact_summary'] ?? null) ? $raw['field_fact_summary'] : [];
        if ((int)($summary['captured_count'] ?? 0) > 0 || (int)($summary['capture_evidence_count'] ?? 0) > 0) {
            return true;
        }
        $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            if (($fact['status'] ?? '') === 'captured' || !empty($fact['stored_value_present']) || !empty($fact['capture_evidence'])) {
                return true;
            }
        }
        return false;
    }

    private function storeRawRecord(array $source, int $taskId, array $payload, ?int $httpStatus): void
    {
        $dataType = $this->normalizeDataType((string)($source['data_type'] ?? ''));
        if ($dataType === 'review') {
            $allowReviewSummary = $this->payloadRequestsReviewDetailStorage($payload)
                && $this->isReviewCollectionAllowed($source, $payload, $dataType);
            $payload = $this->sanitizeReviewPayloadForStorage($payload, $allowReviewSummary);
        } else {
            $payload = $this->sanitizePayloadForStorage($payload, $dataType);
        }
        $rawRecord = $this->buildRawRecordPayload($payload);
        $data = [
            'data_source_id' => (int)$source['id'],
            'sync_task_id' => $taskId,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'platform' => (string)$source['platform'],
            'data_type' => (string)$source['data_type'],
            'ingestion_method' => (string)$source['ingestion_method'],
            'payload_hash' => $rawRecord['payload_hash'],
            'raw_payload' => $rawRecord['raw_payload'],
            'http_status' => $httpStatus,
            'received_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
        ];
        if (isset($this->tableColumns('platform_data_raw_records')['tenant_id'])) {
            $data['tenant_id'] = (int)($source['system_hotel_id'] ?? 0) ?: null;
        }

        Db::name('platform_data_raw_records')->insert($data);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload_hash: string, raw_payload: string}
     */
    private function buildRawRecordPayload(array $payload): array
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($raw === false) {
            $raw = json_encode([
                '_raw_payload_encoding_failed' => true,
                'json_error' => json_last_error_msg(),
                'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $payloadHash = hash('sha256', $raw);
        if (strlen($raw) <= self::RAW_RECORD_PAYLOAD_LIMIT_BYTES) {
            return ['payload_hash' => $payloadHash, 'raw_payload' => $raw];
        }

        $summary = $this->summarizeLargeRawPayload($payload, strlen($raw), $payloadHash);
        $boundedRaw = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($boundedRaw === false || strlen($boundedRaw) > self::RAW_RECORD_PAYLOAD_LIMIT_BYTES) {
            $boundedRaw = json_encode([
                '_raw_payload_truncated' => true,
                'reason' => 'raw_payload_exceeds_db_packet_safe_limit',
                'original_payload_bytes' => strlen($raw),
                'stored_payload_limit_bytes' => self::RAW_RECORD_PAYLOAD_LIMIT_BYTES,
                'payload_hash' => $payloadHash,
                'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return ['payload_hash' => $payloadHash, 'raw_payload' => $boundedRaw];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summarizeLargeRawPayload(array $payload, int $originalBytes, string $payloadHash): array
    {
        $trace = [];
        foreach (['profile_id', 'hotel_id', 'hotel_name', 'system_hotel_id', 'source', 'mode', 'captured_at', 'default_data_date', 'data_period', 'snapshot_time', 'snapshot_bucket', 'output'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string)$payload[$key]) !== '') {
                $trace[$key] = mb_substr((string)$payload[$key], 0, 500);
            }
        }
        if (isset($payload['outputs']) && is_array($payload['outputs'])) {
            $trace['outputs'] = array_slice(array_values(array_filter(array_map(
                static fn($item): string => is_scalar($item) ? (string)$item : '',
                $payload['outputs']
            ), static fn(string $item): bool => $item !== '')), 0, 20);
        }

        $meta = [];
        foreach (['data_source_capture', 'sync_summary', 'auth_status', 'capture_gate', 'capture_gate_warning', 'capture_execution', 'cookie_injection'] as $key) {
            if (array_key_exists($key, $payload)) {
                $meta[$key] = $this->compactRawPayloadMetaValue($payload[$key]);
            }
        }

        return [
            '_raw_payload_truncated' => true,
            'reason' => 'raw_payload_exceeds_db_packet_safe_limit',
            'original_payload_bytes' => $originalBytes,
            'stored_payload_limit_bytes' => self::RAW_RECORD_PAYLOAD_LIMIT_BYTES,
            'payload_hash' => $payloadHash,
            'payload_keys' => array_slice(array_map('strval', array_keys($payload)), 0, 80),
            'payload_counts' => $this->rawPayloadCollectionCounts($payload),
            'trace' => $trace,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    private function rawPayloadCollectionCounts(array $payload): array
    {
        $counts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $counts[(string)$key] = count($value);
            }
        }
        return $counts;
    }

    private function compactRawPayloadMetaValue(mixed $value, int $depth = 0): mixed
    {
        if (is_scalar($value) || $value === null) {
            return is_string($value) && mb_strlen($value) > 500 ? mb_substr($value, 0, 500) : $value;
        }
        if (!is_array($value)) {
            return null;
        }
        if ($depth >= 3) {
            return ['_array_count' => count($value)];
        }

        $compact = [];
        $index = 0;
        foreach ($value as $key => $item) {
            if ($index >= 30) {
                $compact['_truncated_item_count'] = count($value) - $index;
                break;
            }
            $compact[$key] = $this->compactRawPayloadMetaValue($item, $depth + 1);
            $index++;
        }
        return $compact;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function saveNormalizedRows(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $columns = $this->tableColumns('online_daily_data');
        $saved = 0;
        foreach ($rows as $row) {
            $data = array_intersect_key($row, $columns);
            if (empty($data)) {
                continue;
            }
            $existing = null;
            $rowDataPeriod = (string)($row['data_period'] ?? 'historical_daily');
            $rowSnapshotBucket = (string)($row['snapshot_bucket'] ?? '');
            if (($row['source_trace_id'] ?? '') !== '' && isset($columns['source_trace_id']) && !($rowDataPeriod === 'realtime_snapshot' && $rowSnapshotBucket !== '')) {
                $existing = Db::name('online_daily_data')->where('source_trace_id', (string)$row['source_trace_id'])->find();
            }
            if (!$existing) {
                $query = Db::name('online_daily_data')
                    ->where('data_date', $row['data_date'])
                    ->where('source', $row['source'])
                    ->where('data_type', $row['data_type']);
                if (isset($columns['data_period'])) {
                    $query->where('data_period', $rowDataPeriod);
                }
                if ($rowDataPeriod === 'realtime_snapshot' && isset($columns['snapshot_bucket'])) {
                    $query->where('snapshot_bucket', $rowSnapshotBucket);
                }
                if (!empty($row['system_hotel_id']) && isset($columns['system_hotel_id'])) {
                    $query->where('system_hotel_id', (int)$row['system_hotel_id']);
                }
                if (($row['hotel_id'] ?? '') !== '' && isset($columns['hotel_id'])) {
                    $query->where('hotel_id', (string)$row['hotel_id']);
                }
                if (($row['dimension'] ?? '') !== '' && isset($columns['dimension'])) {
                    $query->where('dimension', (string)$row['dimension']);
                }
                $existing = $query->find();
            }
            if ($existing) {
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                Db::name('online_daily_data')->where('id', (int)$existing['id'])->update($data);
            } else {
                if (isset($columns['create_time'])) {
                    $data['create_time'] = date('Y-m-d H:i:s');
                }
                if (isset($columns['update_time'])) {
                    $data['update_time'] = date('Y-m-d H:i:s');
                }
                Db::name('online_daily_data')->insert($data);
            }
            $saved++;
        }
        return $saved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractBusinessRows(array $payload): array
    {
        $rows = $payload['rows']
            ?? $payload['list']
            ?? $payload['items']
            ?? $payload['records']
            ?? $payload['orderList']
            ?? $payload['campaignList']
            ?? null;
        if ($rows === null && isset($payload['data']) && is_array($payload['data'])) {
            $rows = $payload['data']['rows']
                ?? $payload['data']['list']
                ?? $payload['data']['items']
                ?? $payload['data']['records']
                ?? $payload['data']['orderList']
                ?? $payload['data']['campaignList']
                ?? $payload['data'];
        }
        if (!is_array($rows)) {
            return [];
        }
        if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
            $rows = [$rows];
        }
        return $rows;
    }

    private function normalizeDataType(string $value): string
    {
        $value = trim($value);
        $value = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower((string)preg_replace('/[\s\-.]+/', '_', $value));
        $value = (string)preg_replace('/_+/', '_', $value);
        $value = trim($value, '_');
        if (in_array($value, ['business', 'business_data', 'businessdata', 'trade_data', 'tradedata', 'overview', 'summary', 'core'], true)) {
            return 'business';
        }
        if (in_array($value, ['peer_rank', 'peerrank', 'competitor_rank', 'competitorrank', 'competition', 'rank', 'ranking', 'rankings', 'peer'], true)) {
            return 'peer_rank';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['review_data', 'reviewdata'], true)) {
            return 'review';
        }
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['search_keyword', 'search_keywords', 'searchkeyword', 'searchkeywords', 'search_key_word', 'search_key_words', 'keyword', 'keywords', 'search_word', 'search_words', 'hot_word', 'hot_words'], true)) {
            return 'search_keyword';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['flow', 'flow_data', 'flowdata', 'traffic', 'traffic_data', 'trafficdata'], true)) {
            return 'traffic';
        }
        if (in_array($value, ['traffic_analysis', 'trafficanalysis', 'flow_analysis', 'flowanalysis'], true)) {
            return 'traffic_analysis';
        }
        if (in_array($value, ['traffic_forecast', 'trafficforecast', 'flow_forecast', 'flowforecast', 'forecast'], true)) {
            return 'traffic_forecast';
        }
        if (in_array($value, ['room_type', 'room_types', 'roomtype', 'roomtypes', 'product', 'products'], true)) {
            return 'room_type';
        }
        if (in_array($value, ['platform_identity', 'platformidentity', 'identity', 'partner_id', 'partnerid', 'poi_id', 'poiid'], true)) {
            return 'platform_identity';
        }
        return $value !== '' ? $value : 'business';
    }

    private function isCommentDataType(string $dataType): bool
    {
        return $this->normalizeDataType($dataType) === 'review';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     */
    private function isReviewCollectionAllowed(array $source, array $payload = [], string $dataType = ''): bool
    {
        $effectiveDataType = $dataType !== '' ? $dataType : (string)($source['data_type'] ?? '');
        if (!$this->isCommentDataType($effectiveDataType)) {
            return true;
        }

        if (!$this->payloadRequestsReviewDetailStorage($payload)) {
            return true;
        }

        $config = $this->decodeConfig($source['config_json'] ?? $source['config'] ?? []);
        foreach (['allow_review', 'authorized_review_collection', 'review_collection_enabled'] as $key) {
            if ($this->truthy($payload[$key] ?? null) || $this->truthy($config[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function payloadRequestsReviewDetailStorage(array $payload): bool
    {
        foreach (['review_detail_collection', 'reviewDetailCollection', 'store_review_text', 'storeReviewText', 'store_comment_text', 'storeCommentText'] as $key) {
            if ($this->truthy($payload[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        return in_array($text, ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function amountValue(array $row, string $dataType): float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'advertising') {
            return $this->numericValue($row, ['todayCost', 'cost', 'cashCost', 'bonusCost', 'ad_cost', 'adCost', 'spend', 'amount']);
        }
        if ($dataType === 'order') {
            return $this->numericValue($row, ['totalAmount', 'orderAmount', 'payAmount', 'roomAmount', 'amount', 'order_amount', 'room_revenue', 'revenue']);
        }
        return $this->numericValue($row, ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantityValue(array $row, string $dataType): int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'order') {
            $roomCount = $this->numericValue($row, ['roomCount', 'room_count']);
            $nights = $this->numericValue($row, ['nights', 'night_count', 'nightCount']);
            if ($roomCount > 0 && $nights > 0) {
                return (int)round($roomCount * $nights);
            }
        }
        if ($dataType === 'review') {
            $count = $this->numericValue($row, ['review_count', 'reviewCount', 'comment_count', 'commentCount', 'count', 'quantity']);
            return $count > 0 ? (int)round($count) : 1;
        }

        return (int)$this->numericValue($row, [
            'quantity',
            'mt_pay_rooms',
            'pay_rooms',
            'payRooms',
            'payRoomNum',
            'pay_room_num',
            'room_nights',
            'roomNights',
            'nights',
            'night_count',
            'checkoutRoomNights',
            'checkout_room_nights',
            'checkOutQuantity',
            'bookQuantity',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dataValue(array $row, string $dataType): float
    {
        $explicit = $this->numericValue($row, ['data_value', 'dataValue', 'value', 'metric_value', 'averagePrice', 'avgPrice', 'avg_price']);
        if ($explicit > 0) {
            return $explicit;
        }

        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'quality') {
            return $this->numericValue($row, ['serviceScore', 'psiScore', 'imScore', 'score']);
        }
        if ($dataType === 'peer_rank') {
            return $this->numericValue($row, ['rank', 'ranking', 'rankValue', 'rank_value', 'rankPercent', 'rank_percent']);
        }
        if ($dataType === 'advertising') {
            return $this->numericValue($row, ['roas', 'roi', 'ecpc', 'ctr', 'cvr']);
        }
        if ($dataType === 'review') {
            return $this->numericValue($row, ['comment_score', 'rating', 'score', 'data_value', 'dataValue']);
        }
        if ($dataType === 'order') {
            $quantity = $this->quantityValue($row, $dataType);
            $amount = $this->amountValue($row, $dataType);
            return $quantity > 0 ? round($amount / $quantity, 2) : 0.0;
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderCountValue(array $row, string $dataType): int
    {
        $count = (int)$this->numericValue($row, ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount']);
        if ($count > 0) {
            return $count;
        }
        if ($this->normalizeDataType($dataType) === 'order' && $this->firstOrderIdentifier($row) !== '') {
            return 1;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForStorage(array $payload, string $dataType = ''): array
    {
        return $this->sanitizePayloadNode($payload, $this->normalizeDataType($dataType) === 'order');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeReviewPayloadForStorage(array $payload, bool $allowSummary = false): array
    {
        $sanitized = $this->removeReviewPrivateFields($this->sanitizePayloadForStorage($payload, 'review'));
        if ($allowSummary) {
            $summary = $this->reviewSummaryText($payload);
            if ($summary !== '') {
                $sanitized['review_summary'] = $summary;
            }
        }
        return $sanitized;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function removeReviewPrivateFields(array $node): array
    {
        $clean = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitizeReviewArray($value) : $value;
        }
        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizeReviewArray(array $value): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($item) ? $this->sanitizeReviewArray($item) : $item;
        }
        return $clean;
    }

    private function isReviewPrivateKey(string $key): bool
    {
        return preg_match('/content|commentContent|comment_text|review_text|review[_-]?id|comment[_-]?id|reply|guest|customer|userName|username|nick|phone|mobile|tel|certificate|idcard|id_card|identity|openid|avatar|order[_-]?(id|no|number)|room(type|name)|photo|image|pic/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reviewSummaryText(array $payload): string
    {
        $text = $this->stringValue($payload, ['review_summary', 'summary', 'content', 'commentContent', 'comment_text', 'review_text']);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b1[3-9]\d{9}\b/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b\d{6,}\b/', '[redacted]', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_substr($text, 0, 120);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function reviewDimensionValue(array $row): string
    {
        $tags = $row['tags'] ?? $row['labels'] ?? $row['tag_list'] ?? null;
        if (is_array($tags)) {
            $values = array_values(array_filter(array_map(static fn(mixed $item): string => trim((string)$item), $tags), static fn(string $item): bool => $item !== ''));
            return implode(',', array_slice($values, 0, 8));
        }
        return $this->stringValue($row, ['tag', 'label', 'sentiment']);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function sanitizePayloadNode(array $node, bool $orderContext): array
    {
        $sanitized = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isSensitiveConfigKey($keyText)) {
                continue;
            }

            $childOrderContext = $orderContext || $this->isOrderContainerKey($keyText);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayloadArray($value, $childOrderContext);
                continue;
            }

            if ($childOrderContext || $this->isOrderPiiKey($keyText)) {
                $this->appendRedactedOrderField($sanitized, $keyText, $value);
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizePayloadArray(array $value, bool $orderContext): array
    {
        if ($value === []) {
            return [];
        }
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizePayloadNode($item, $orderContext);
            } else {
                $keyText = (string)$key;
                if ($this->isSensitiveConfigKey($keyText)) {
                    continue;
                }
                if ($orderContext || $this->isOrderPiiKey($keyText)) {
                    $this->appendRedactedOrderField($sanitized, $keyText, $item);
                } else {
                    $sanitized[$key] = $item;
                }
            }
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $target
     */
    private function appendRedactedOrderField(array &$target, string $key, mixed $value): void
    {
        if ($this->isOrderIdKey($key)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedFieldName($key, 'hash')] = hash('sha256', 'ota_order|' . $text);
            }
            return;
        }
        if ($this->isPhoneKey($key)) {
            $masked = $this->maskPhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isGuestNameKey($key)) {
            $masked = $this->maskName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOrderContainerKey(string $key): bool
    {
        return preg_match('/order[_-]?(list|rows|items|data|detail|details|info)|orders/i', $key) === 1;
    }

    private function isOrderPiiKey(string $key): bool
    {
        return $this->isOrderIdKey($key)
            || $this->isPhoneKey($key)
            || $this->isGuestNameKey($key)
            || $this->isSensitiveOrderTextKey($key);
    }

    private function isOrderIdKey(string $key): bool
    {
        return preg_match('/^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i', $key) === 1;
    }

    private function isPhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/i', $key) === 1;
    }

    private function isGuestNameKey(string $key): bool
    {
        return preg_match('/(guest|customer|contact|user|traveller|passenger)[_-]?name$/i', $key) === 1;
    }

    private function isSensitiveOrderTextKey(string $key): bool
    {
        return preg_match('/(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function firstOrderIdentifier(array $row): string
    {
        foreach (['orderId', 'order_id', 'orderNo', 'order_no', 'orderNumber', 'bookingId', 'booking_id'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function redactedFieldName(string $key, string $suffix): string
    {
        if ($this->isOrderIdKey($key)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    private function maskName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 1) . '***';
    }

    private function sanitizeSourceRow(array $row): array
    {
        $config = $this->decodeConfig($row['config_json'] ?? []);
        $secret = $this->decodeConfig($row['secret_json'] ?? []);
        unset($row['config_json']);
        unset($row['secret_json']);
        $row['config'] = $this->sanitizeConfigForResponse($config);
        $row['has_secret'] = !empty($secret);
        $row['has_cookies'] = isset($secret['cookies']) && trim((string)$secret['cookies']) !== '';
        $row['cookies_preview'] = $row['has_cookies'] ? $this->maskSecret((string)$secret['cookies']) : '';
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonImportFile(string $path): array
    {
        $content = file_get_contents($path);
        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON import file is invalid.', 422);
        }
        if ($decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1)) {
            return array_values(array_filter($decoded, 'is_array'));
        }
        return $this->extractBusinessRows($decoded);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsvImportFile(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV import file cannot be read.', 422);
        }

        $headers = [];
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            $cells = array_map(static fn($value): string => trim((string)$value), $cells);
            if ($this->isBlankRow($cells)) {
                continue;
            }
            if ($headers === []) {
                $headers = $this->normalizeHeaderRow($cells);
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseXlsxImportFile(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('XLSX import requires PHP ZipArchive extension.', 422);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX import file cannot be opened.', 422);
        }

        try {
            $sharedStrings = $this->readXlsxSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('XLSX sheet1.xml was not found.', 422);
            }
            $sheet = simplexml_load_string((string)$sheetXml, 'SimpleXMLElement', LIBXML_NONET);
            if (!$sheet) {
                throw new RuntimeException('XLSX sheet1.xml is invalid.', 422);
            }

            $matrix = [];
            foreach ($sheet->sheetData->row as $rowNode) {
                $row = [];
                foreach ($rowNode->c as $cellNode) {
                    $ref = (string)($cellNode['r'] ?? '');
                    $columnIndex = $this->xlsxColumnIndex($ref);
                    if ($columnIndex < 0) {
                        continue;
                    }
                    $type = (string)($cellNode['t'] ?? '');
                    $value = (string)($cellNode->v ?? '');
                    if ($type === 's') {
                        $value = $sharedStrings[(int)$value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)($cellNode->is->t ?? '');
                    }
                    $row[$columnIndex] = trim($value);
                }
                if (!$this->isBlankRow($row)) {
                    ksort($row);
                    $matrix[] = $row;
                }
            }
        } finally {
            $zip->close();
        }

        if (empty($matrix)) {
            return [];
        }

        $headers = $this->normalizeHeaderRow(array_values(array_shift($matrix)));
        $rows = [];
        foreach ($matrix as $cells) {
            $cells = array_values($cells);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = $cells[$index] ?? '';
            }
            if (!$this->isBlankRow(array_values($row))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function readXlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $shared = simplexml_load_string((string)$xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$shared) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string)$item->t;
                continue;
            }
            $text = '';
            foreach ($item->r as $run) {
                $text .= (string)($run->t ?? '');
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return -1;
        }
        $letters = strtoupper($matches[1]);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return $index - 1;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<int, string>
     */
    private function normalizeHeaderRow(array $cells): array
    {
        return array_map(static function ($value): string {
            $header = trim((string)$value);
            return preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        }, $cells);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function sanitizeConfigForResponse(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($this->isSensitiveConfigKey((string)$key)) {
                $config[$key] = is_string($value) ? $this->maskSecret($value) : '[configured]';
                continue;
            }
            if (is_array($value)) {
                $config[$key] = $this->sanitizeConfigForResponse($value);
            } elseif (strtolower((string)$key) === 'headers' && is_string($value)) {
                $config[$key] = $this->sanitizeHeaderString($value);
            }
        }
        return $config;
    }

    private function sanitizeHeaderString(string $headers): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        $sanitized = [];
        foreach ($lines as $line) {
            [$name] = array_pad(explode(':', (string)$line, 2), 2, '');
            $sanitized[] = $this->isSensitiveConfigKey($name) ? trim($name) . ': ' . '[configured]' : $line;
        }
        return implode("\n", $sanitized);
    }

    private function isSensitiveConfigKey(string $key): bool
    {
        return preg_match('/cookie|authorization|token|api[-_]?key|secret|password|spidertoken|mtgsig/i', $key) === 1;
    }

    private function decodeConfig($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDate($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d', $time);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function numericValue(array $row, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = str_replace([',', '%', '￥', '¥', ' '], '', (string)$row[$key]);
            if ($value === '') {
                continue;
            }
            return is_numeric($value) ? (float)$value : 0.0;
        }
        return 0.0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function stringValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    private function buildTraceId(array $source, array $row, string $date, ?int $syncTaskId, string $snapshotBucket = ''): string
    {
        $parts = [
            $source['id'] ?? '',
            $source['platform'] ?? '',
            $source['data_type'] ?? '',
            $date,
            $row['hotel_id'] ?? $row['hotelId'] ?? $row['poi_id'] ?? $row['poiId'] ?? '',
            $row['dimension'] ?? $row['_dimName'] ?? '',
            $snapshotBucket,
            $syncTaskId ?? '',
        ];
        return substr(hash('sha256', implode('|', array_map('strval', $parts))), 0, 64);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $source
     * @return array{data_period: string, snapshot_time: ?string, snapshot_bucket: string, is_final: int}
     */
    private function resolveDataPeriodMetadata(array $row, array $payload, array $source, string $date): array
    {
        $period = $this->normalizeDataPeriod(
            $row['data_period']
            ?? $row['dataPeriod']
            ?? $payload['data_period']
            ?? $payload['dataPeriod']
            ?? $source['data_period']
            ?? ''
        );

        if ($period === '') {
            $period = $this->looksLikeRealtimeRow($row, $payload, $source, $date) ? 'realtime_snapshot' : 'historical_daily';
        }

        $snapshotTime = null;
        $snapshotBucket = '';
        if ($period === 'realtime_snapshot') {
            $snapshotTime = $this->normalizeDateTime(
                $row['snapshot_time']
                ?? $row['snapshotTime']
                ?? $row['captured_at']
                ?? $row['capturedAt']
                ?? $payload['snapshot_time']
                ?? $payload['snapshotTime']
                ?? $payload['captured_at']
                ?? $payload['capturedAt']
                ?? null
            ) ?? date('Y-m-d H:i:s');
            $snapshotBucket = date('YmdH', strtotime($snapshotTime) ?: time());
        }

        return [
            'data_period' => $period,
            'snapshot_time' => $snapshotTime,
            'snapshot_bucket' => $snapshotBucket,
            'is_final' => $period === 'historical_daily' ? 1 : 0,
        ];
    }

    private function normalizeDataPeriod($value): string
    {
        $value = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($value) {
            'realtime', 'real_time', 'realtime_snapshot', 'today_realtime', 'live', 'snapshot' => 'realtime_snapshot',
            'historical', 'history', 'historical_daily', 'daily', 'fixed', 'final' => 'historical_daily',
            default => '',
        };
    }

    private function normalizeDateTime($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private function applySyncOptionPeriodMetadata($payload, array $options): array
    {
        $payload = is_array($payload) ? $payload : [];
        $period = $this->normalizeDataPeriod($options['data_period'] ?? $options['dataPeriod'] ?? '');
        if ($period !== '' && empty($payload['data_period'])) {
            $payload['data_period'] = $period;
        }

        $dataDate = $this->normalizeDate($options['data_date'] ?? $options['dataDate'] ?? $options['target_date'] ?? $options['targetDate'] ?? null);
        if ($dataDate !== null && empty($payload['data_date'])) {
            $payload['data_date'] = $dataDate;
        }

        $snapshotTime = $this->normalizeDateTime($options['snapshot_time'] ?? $options['snapshotTime'] ?? null);
        if ($snapshotTime !== null && empty($payload['snapshot_time'])) {
            $payload['snapshot_time'] = $snapshotTime;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $source
     */
    private function looksLikeRealtimeRow(array $row, array $payload, array $source, string $date): bool
    {
        if ($date !== date('Y-m-d')) {
            return false;
        }

        $signals = [
            $row['endpoint_id'] ?? '',
            $row['_endpoint_id'] ?? '',
            $row['source_url'] ?? '',
            $row['_source_url'] ?? '',
            $row['dimension'] ?? '',
            $payload['endpoint_id'] ?? '',
            $payload['source_url'] ?? '',
            $source['data_type'] ?? '',
        ];
        $text = strtolower(implode('|', array_map(static fn($value): string => (string)$value, $signals)));
        foreach (['realtime', 'real_time', 'today', 'current', 'rank', 'inventory', 'price'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 4) . '...' . mb_substr($value, -4);
    }

    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $this->columns[$table] = array_fill_keys(array_column($rows, 'Field'), true);
        return $this->columns[$table];
    }

    private function assertCanUseHotel($user, int $hotelId, string $permission): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        if ($hotelId <= 0 || !method_exists($user, 'hasHotelPermission') || !$user->hasHotelPermission($hotelId, $permission)) {
            throw new RuntimeException('Forbidden.', 403);
        }
    }

    private function applySourceScope($query, $user): void
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function applyTaskScope($query, $user): void
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function logSync(int $taskId, array $source, string $level, string $event, string $message, array $context = []): void
    {
        $data = [
            'sync_task_id' => $taskId,
            'data_source_id' => (int)($source['id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0) ?: null,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'create_time' => date('Y-m-d H:i:s'),
        ];
        if (isset($this->tableColumns('platform_data_sync_logs')['tenant_id'])) {
            $data['tenant_id'] = (int)($source['system_hotel_id'] ?? 0) ?: null;
        }

        Db::name('platform_data_sync_logs')->insert($data);
    }
}
