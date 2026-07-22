<?php
declare(strict_types=1);

namespace app\service;

use app\contract\DataSourceAdapter;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Cache;
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
                'source_keys' => ['flow_rate', 'flowRate', 'intentionPerExposure', 'cvr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate'],
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

    private ?OtaCredentialVault $credentialVault;

    private OtaProfileSessionProofService $profileSessionProofService;

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
            new CtripBrowserProfileDataSourceAdapter(),
            new MeituanBrowserProfileDataSourceAdapter(),
            new ApiDataSourceAdapter(),
        ];
        $this->credentialVault = $credentialVault;
        $this->profileSessionProofService = $profileSessionProofService ?? new OtaProfileSessionProofService();
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
            $preserveMissingMetrics = $this->isOtaBrowserProfileSource($source);
            $dataType = $this->normalizeDataType(
                in_array($sourceIngestionMethod, ['browser_profile', 'profile_browser'], true) && $rowDataType !== ''
                    ? $rowDataType
                    : ($sourceDataType !== '' ? $sourceDataType : ($rowDataType !== '' ? $rowDataType : 'business'))
            );
            if ($this->isCommentDataType($dataType) && !$this->isReviewCollectionAllowed($source, $payload, $dataType)) {
                continue;
            }
            $reviewValidationFlags = $dataType === 'review'
                ? $this->reviewValidationFlags($row, $payload, $date, $collectionStatus)
                : [];
            $reviewValidationStatus = $this->reviewValidationStatus($reviewValidationFlags);
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
            $capturedAt = $this->normalizeDateTime(
                $row['collected_at']
                    ?? $row['collectedAt']
                    ?? $row['captured_at']
                    ?? $row['capturedAt']
                    ?? $payload['collected_at']
                    ?? $payload['collectedAt']
                    ?? $payload['captured_at']
                    ?? $payload['capturedAt']
                    ?? null
            );
            if ($capturedAt !== null) {
                $raw['captured_at'] = $capturedAt;
            }
            if (($platformIdentifierEvidence['present'] ?? false) === true) {
                $raw['platform_hotel_identifier_present'] = true;
                $raw['platform_hotel_identifier_source'] = (string)$platformIdentifierEvidence['source'];
                $raw['platform_hotel_identifier_proof'] = (string)$platformIdentifierEvidence['proof'];
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

            $normalizedRow = [
                'hotel_id' => $normalizedHotelId,
                'hotel_name' => $this->stringValue($row, ['hotel_name', 'hotelName', 'poi_name', 'poiName', 'name']) ?: (string)($source['hotel_name'] ?? $source['name'] ?? ''),
                'data_date' => $date,
                'amount' => $this->amountValue($row, $dataType, $preserveMissingMetrics),
                'quantity' => $this->quantityValue($row, $dataType, $preserveMissingMetrics),
                'book_order_num' => $this->orderCountValue($row, $dataType, $preserveMissingMetrics),
                'comment_score' => $this->commentScoreValue($row, $dataType, $preserveMissingMetrics),
                'qunar_comment_score' => $this->nullableNumericValue($row, ['qunar_comment_score', 'qunar_score'])
                    ?? ($preserveMissingMetrics ? null : 0.0),
                'system_hotel_id' => (int)($source['system_hotel_id'] ?? $row['system_hotel_id'] ?? 0) ?: null,
                'tenant_id' => $tenantId,
                'data_value' => $this->dataValue($row, $dataType, $preserveMissingMetrics),
                'source' => $platform,
                'dimension' => $this->stringValue($row, ['dimension', 'dim_name', '_dimName']) ?: ($dataType === 'review' ? $this->reviewDimensionValue($sanitizedRow) : ''),
                'data_type' => $dataType,
                'platform' => $this->stringValue($row, ['platform']) ?: $platform,
                'compare_type' => $normalizedCompareType,
                'list_exposure' => $this->integerMetricValue($row, ['mt_exposure', 'list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv'], $preserveMissingMetrics),
                'detail_exposure' => $this->integerMetricValue($row, ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount', 'visitors', 'visitorTotal', 'pv', 'uv'], $preserveMissingMetrics),
                'flow_rate' => $this->flowRateValue($row, $dataType, $preserveMissingMetrics),
                'order_filling_num' => $this->integerMetricValue($row, ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'clickCount', 'clicks'], $preserveMissingMetrics),
                'order_submit_num' => $this->integerMetricValue($row, ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'order_submit_num', 'orderSubmitNum', 'bookings', 'bookingCount', 'orderCount', 'orderQuantity', 'orderNum', 'orders'], $preserveMissingMetrics),
                'validation_status' => $reviewValidationStatus,
                'validation_flags' => json_encode($reviewValidationFlags, JSON_UNESCAPED_UNICODE),
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
            return $this->saveOtaDataSource($user, $source, $existing, $id);
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
    private function saveOtaDataSource($user, array $source, ?array $existing, int $id): array
    {
        $hotelId = (int)$source['system_hotel_id'];
        $tenantId = $this->resolveHotelTenantId($hotelId);
        $platform = strtolower((string)$source['platform']);
        $existingConfig = $existing ? $this->decodeConfig($existing['config_json'] ?? []) : [];
        $secretPayload = $this->normalizeOtaCredentialPayload($source['secret']);
        $hasSecretInput = $this->credentialPayloadHasValue($secretPayload);
        $isBrowserProfile = in_array(
            strtolower(trim((string)($source['ingestion_method'] ?? ''))),
            ['browser_profile', 'profile_browser'],
            true
        );
        if ($existing) {
            $existingIsBrowserProfile = in_array(
                strtolower(trim((string)($existing['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser'],
                true
            );
            if ($existingIsBrowserProfile !== $isBrowserProfile) {
                throw new RuntimeException(
                    'OTA data source cannot switch authorization model in place; create a separate data source.',
                    422
                );
            }
        }
        if ($isBrowserProfile && $hasSecretInput) {
            throw new RuntimeException(
                'Browser Profile data source must not store reusable OTA credentials; use a separate API/manual source.',
                422
            );
        }
        if (!$isBrowserProfile && !$existing && !$hasSecretInput) {
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
                || (!$isBrowserProfile && $locatorMismatch)
                || ($isBrowserProfile && $existingConfigId !== '' && $existingConfigId !== $configId)
            ) {
                throw new RuntimeException('Replacing an OTA credential locator requires a new credential payload.', 422);
            }
        }

        $actorId = (int)($user->id ?? 0);
        $safeConfig = $this->allowlistedOtaSourceConfig($source['config'], $platform);
        $profileKey = $isBrowserProfile ? $this->otaBrowserProfileKey($platform, $safeConfig) : '';
        if ($isBrowserProfile && $profileKey === '') {
            throw new RuntimeException('Browser Profile binding key is missing.', 422);
        }
        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $source,
            $existing,
            $existingConfig,
            $secretPayload,
            $hasSecretInput,
            $isBrowserProfile,
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
    private function allowlistedOtaSourceConfig(array $config, string $platform): array
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
            'data_date', 'dataDate', 'data_period', 'dataPeriod', 'snapshot_time', 'snapshotTime',
        ];
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

        $taskId = $this->createTask($source, $user, (string)($options['trigger_type'] ?? 'manual'));
        try {
            $adapter = $this->resolveAdapter($source);
            $this->assertBrowserProfileBackgroundSyncLoginVerified($source, $options);
            $phaseStartedAt = microtime(true);
            if ($isOtaSource) {
                $result = $this->isOtaBrowserProfileSource($source)
                    ? $this->fetchOtaBrowserProfileSource($adapter, $source, $options)
                    : $this->fetchOtaSourceInsideVault($adapter, $source, $options);
            } else {
                $result = $adapter->fetch($source, $options);
            }
            if ($this->isOtaBrowserProfileSource($source)
                && $this->recordBrowserProfileCollectionPreflight($source, $result)
            ) {
                $source = $this->loadSource($id, $user);
            }
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

        if (is_array($stats['sync_diagnostics'] ?? null)) {
            $safe['sync_diagnostics'] = $this->sanitizeSyncDiagnosticsForResponse($stats['sync_diagnostics'], $status);
        }
        if (is_array($stats['collection_quality'] ?? null)) {
            $safe['collection_quality'] = $this->sanitizeSyncTaskCollectionQuality($stats['collection_quality']);
        }
        if (is_array($stats['run_readback'] ?? null)) {
            $safe['run_readback'] = $this->sanitizeRunReadbackReceipt($stats['run_readback']);
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

        return [
            'readback_verified' => ($receipt['readback_verified'] ?? false) === true,
            'sync_task_id' => max(0, (int)($receipt['sync_task_id'] ?? 0)),
            'data_source_id' => max(0, (int)($receipt['data_source_id'] ?? 0)),
            'system_hotel_id' => max(0, (int)($receipt['system_hotel_id'] ?? 0)),
            'platform' => in_array($platform, ['ctrip', 'meituan'], true) ? $platform : '',
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'started_at' => $startedAt,
            'row_ids' => array_slice($rowIds, 0, 50),
            'source_trace_ids' => array_slice(array_values(array_unique($traceIds)), 0, 50),
            'verified_metric_keys' => $metricKeys,
            'readback_count' => max(0, (int)($receipt['readback_count'] ?? 0)),
            'failure_reason' => mb_substr(trim((string)($receipt['failure_reason'] ?? '')), 0, 120),
        ];
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

        $allowedSections = ['traffic', 'order_flow', 'orders', 'ads', 'reviews'];
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
        if (!in_array($ingestionMethod, ['browser_profile', 'profile_browser', 'manual', 'api', 'unknown'], true)) {
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

    private function normalizeSourcePayload(array $payload): array
    {
        $config = $this->decodeConfig($payload['config_json'] ?? $payload['config'] ?? []);
        $secret = $this->decodeConfig($payload['secret_json'] ?? $payload['secret'] ?? []);
        foreach (['cookies', 'cookie', 'token', 'api_key', 'authorization', 'authorization_header', 'password', 'spidertoken', 'spider_token', 'spiderkey', 'spider_key', 'mtgsig', 'auth_data', 'usertoken', 'usersign', '_mtsi_eb_u', 'access_token', 'refresh_token', 'set_cookie'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $secret[$key === 'cookie' ? 'cookies' : $key] = is_array($payload[$key])
                    ? $payload[$key]
                    : (string)$payload[$key];
            }
        }
        foreach (['config_id', 'url', 'request_url', 'method', 'allowed_hosts', 'payload', 'payload_json', 'headers', 'headers_json', 'external_hotel_id', 'hotel_name', 'profile_id', 'profileId', 'browser_profile_id', 'hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'store_id', 'storeId', 'poi_id', 'poiId', 'poi_name', 'poiName', 'partner_id', 'partnerId', 'ads_url', 'adsUrl', 'capture_sections', 'captureSections', 'profile_sections', 'section_concurrency', 'sectionConcurrency', 'ctrip_section_concurrency', 'ctripSectionConcurrency', 'not_applicable_sections', 'notApplicableSections', 'excluded_sections', 'excludedSections', 'allow_review', 'authorized_review_collection', 'review_collection_enabled'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== '') {
                $config[$key] = $payload[$key];
            }
        }

        $method = (string)($payload['ingestion_method'] ?? 'manual');
        $platform = strtolower(trim((string)($payload['platform'] ?? 'custom'))) ?: 'custom';
        if ($this->isOtaPlatform($platform)) {
            $this->moveOtaConfigCredentialsToSecret($config, $secret);
        }
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
     * @param array<string, mixed> $config
     * @param array<string, mixed> $secret
     */
    private function moveOtaConfigCredentialsToSecret(array &$config, array &$secret): void
    {
        foreach (array_keys($config) as $key) {
            $stringKey = (string)$key;
            $lowerKey = strtolower($stringKey);
            if (in_array($lowerKey, ['headers', 'headers_json'], true)) {
                [$safeHeaders, $secretHeaders] = $this->splitOtaHeaders($config[$key]);
                unset($config[$key]);
                if ($safeHeaders !== []) {
                    $config['headers'] = array_merge(is_array($config['headers'] ?? null) ? $config['headers'] : [], $safeHeaders);
                }
                foreach ($secretHeaders as $headerName => $headerValue) {
                    $normalizedName = strtolower($headerName);
                    if ($normalizedName === 'cookie') {
                        $secret['cookies'] = $headerValue;
                    } elseif ($normalizedName === 'authorization') {
                        $secret['authorization'] = $headerValue;
                    } elseif (in_array($normalizedName, ['x-api-key', 'api-key'], true)) {
                        $secret['api_key'] = $headerValue;
                    } else {
                        $secret['headers'][$headerName] = $headerValue;
                    }
                }
                continue;
            }
            if (!$this->isSensitiveConfigKey($stringKey)) {
                continue;
            }
            $targetKey = $lowerKey === 'cookie' ? 'cookies' : $stringKey;
            $secret[$targetKey] = $config[$key];
            unset($config[$key]);
        }
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function splitOtaHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            $decoded = json_decode($headers, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            } else {
                $lines = preg_split('/\r?\n/', $headers) ?: [];
                $headers = [];
                foreach ($lines as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    if (!str_contains($line, ':')) {
                        throw new RuntimeException('OTA header metadata must use Name: Value syntax.', 422);
                    }
                    [$name, $value] = explode(':', $line, 2);
                    $headers[trim($name)] = trim($value);
                }
            }
        }
        if (!is_array($headers)) {
            throw new RuntimeException('OTA header metadata must be an object or header string.', 422);
        }

        $safe = [];
        $secret = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value) && str_contains($value, ':')) {
                [$name, $value] = explode(':', $value, 2);
            }
            $name = trim((string)$name);
            if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,100}$/D', $name) !== 1 || !is_scalar($value)) {
                throw new RuntimeException('OTA header metadata contains an unsupported entry.', 422);
            }
            $value = trim((string)$value);
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException('OTA header metadata contains an invalid value.', 422);
            }
            if ($this->isSensitiveConfigKey($name)) {
                $secret[$name] = $value;
            } else {
                $safe[$name] = $value;
            }
        }
        return [$safe, $secret];
    }

    /**
     * @param array<string, mixed> $secret
     */
    private function assertNoOtaPasswordCustody(string $platform, array $secret): void
    {
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return;
        }
        if (!$this->credentialPayloadContainsPassword($secret)) {
            return;
        }

        throw new RuntimeException('OTA account password custody is not supported. Use the browser Profile login task and its current-session proof instead.', 422);
    }

    /**
     * @param array<string, mixed> $secret
     */
    private function credentialPayloadContainsPassword(array $secret): bool
    {
        foreach ($secret as $key => $value) {
            if (strtolower((string)$key) === 'password' && $this->credentialPayloadHasValue($value)) {
                return true;
            }
            if (is_array($value) && $this->credentialPayloadContainsPassword($value)) {
                return true;
            }
        }
        return false;
    }

    private function loadSource(int $id, $user): array
    {
        $query = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $id);
        $this->applySourceTenantScope($query, $user);
        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('Data source not found.', 404);
        }
        $this->assertStoredSourceTenantForActor($row, $user);
        $this->assertCanUseHotel($user, (int)($row['system_hotel_id'] ?? 0), 'can_fetch_online_data');
        $row['config'] = $this->decodeConfig($row['config_json'] ?? []);
        if (!$this->isOtaPlatform((string)($row['platform'] ?? ''))) {
            $secretQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($secretQuery, $row);
            $row['secret'] = $this->decodeConfig($secretQuery->value('secret_json'));
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaSourceInsideVault(DataSourceAdapter $adapter, array $source, array $options): array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = (int)($source['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            $tenantId = $this->resolveHotelTenantId($hotelId);
        }
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $this->assertOtaExecutionConfigSafe($config, $platform);
        $configId = trim((string)($config['config_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) !== 1) {
            throw new RuntimeException('OTA data source credential locator is missing.', 422);
        }
        if (trim((string)($config['credential_status'] ?? '')) !== 'ready') {
            throw new RuntimeException('OTA data source credential is not ready.', 422);
        }

        return $this->otaCredentialVault()->withPayloadForExecution(
            $tenantId,
            $hotelId,
            $platform,
            $configId,
            function (array $credentialPayload) use ($adapter, $source, $options): array {
                $executionSource = $source;
                unset($executionSource['secret_json']);
                $executionSource['secret'] = $credentialPayload;
                try {
                    $result = $adapter->fetch($executionSource, $options);
                    return $this->sanitizeAdapterResultForCredentialBoundary($result, $credentialPayload);
                } finally {
                    unset($executionSource['secret']);
                    $credentialPayload = [];
                }
            }
        );
    }

    /**
     * Browser Profile collection reuses the authorized local browser session.
     * It must never decrypt or inject a reusable Cookie/API credential.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchOtaBrowserProfileSource(DataSourceAdapter $adapter, array $source, array $options): array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $this->assertNoInlineOtaCredentialOptions($options, $platform);
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $this->assertOtaExecutionConfigSafe($config, $platform);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($profileKey === '') {
            throw new RuntimeException('Browser Profile binding key is missing.', 422);
        }
        (new OtaProfileBindingService())->assertBound($hotelId, $platform, $profileKey);

        $executionSource = $source;
        unset($executionSource['secret'], $executionSource['secret_json']);

        return $this->sanitizeAdapterResultForCredentialBoundary(
            $adapter->fetch($executionSource, $options),
            []
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $result
     */
    private function recordBrowserProfileCollectionPreflight(array $source, array $result): bool
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $authCode = strtolower(trim((string)($authStatus['status'] ?? '')));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($profileKey === '') {
            return false;
        }
        $sessionProbe = is_array($payload['session_probe'] ?? null) ? $payload['session_probe'] : [];
        $probeStatus = strtolower(trim((string)($sessionProbe['status'] ?? '')));
        $identityValidation = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $identityStatus = strtolower(trim((string)($identityValidation['status'] ?? '')));

        $probeBlockStatuses = [
            'anti_bot' => 'anti_bot',
            'cookies_incomplete' => 'cookies_incomplete',
            'platform_contract_drift' => 'platform_contract_drift',
            'permission_denied' => 'permission_denied',
            'weak_evidence' => 'capture_failed',
            'probe_failed' => 'capture_failed',
        ];
        $authBlockStatuses = [
            'anti_bot' => 'anti_bot',
            'cookies_incomplete' => 'cookies_incomplete',
            'platform_contract_drift' => 'platform_contract_drift',
            'permission_denied' => 'permission_denied',
            'capture_failed' => 'capture_failed',
        ];
        $sessionBlockStatus = $probeBlockStatuses[$probeStatus]
            ?? $authBlockStatuses[$authCode]
            ?? '';
        if ($sessionBlockStatus !== '') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                $sessionBlockStatus,
                trim((string)($sessionProbe['next_retry_at'] ?? ''))
            );
            return true;
        }
        if (($authStatus['ok'] ?? null) === false
            && in_array($authCode, ['login_required', 'session_expired', 'login_expired', 'not_logged_in', 'unauthorized'], true)
        ) {
            $this->profileSessionProofService->recordCollectionPreflightFailed(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                $authStatus
            );
            return true;
        }
        $authVerified = ($authStatus['ok'] ?? null) === true
            && in_array($authCode, ['logged_in', 'authorized'], true);
        if ($identityStatus === 'mismatch') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'identity_mismatch'
            );
            return true;
        }
        if (in_array($identityStatus, ['unverified', 'not_configured'], true)) {
            $currentSourceQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($currentSourceQuery, $source);
            $currentConfig = $this->decodeConfig(
                $currentSourceQuery->value('config_json')
            );
            $priorIdentityStatus = strtolower(trim((string)($currentConfig['current_session_probe_identity_status'] ?? '')));
            if ($authVerified
                && $this->truthy($currentConfig['current_session_verified'] ?? null)
                && $priorIdentityStatus === 'matched'
            ) {
                return false;
            }
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'identity_unverified'
            );
            return true;
        }
        $identityMatched = $identityStatus === 'matched';
        if ($authVerified && $identityMatched) {
            $this->profileSessionProofService->recordCollectionPreflightVerified(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                true,
                $authStatus,
                $identityValidation
            );
            return true;
        }
        if (($result['status'] ?? '') !== 'success') {
            $this->profileSessionProofService->recordProfileSessionBlocked(
                (int)($source['id'] ?? 0),
                (int)($source['system_hotel_id'] ?? 0),
                $platform,
                $profileKey,
                'capture_failed'
            );
            return true;
        }
        if (!$authVerified) {
            return false;
        }
        if (!$identityMatched) {
            return false;
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    private function otaBrowserProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @return array<string, mixed>|null */
    private function findReusableBrowserProfileSource(
        int $tenantId,
        int $systemHotelId,
        string $platform,
        string $profileKey
    ): ?array {
        $canonicalProfileKey = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        if ($canonicalProfileKey === '' || $canonicalProfileKey === 'default') {
            return null;
        }

        $query = Db::name('platform_data_sources')
            ->withoutField('secret_json')
            ->where('system_hotel_id', $systemHotelId)
            ->where('platform', strtolower(trim($platform)))
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->order('id', 'desc')
            ->lock(true);
        if (isset($this->tableColumns('platform_data_sources')['tenant_id'])) {
            $query->where('tenant_id', $tenantId);
        }

        foreach ($query->select()->toArray() as $row) {
            $candidateConfig = $this->decodeConfig($row['config_json'] ?? []);
            $candidateKey = BrowserProfileCaptureRequestService::safeFilePart(
                $this->otaBrowserProfileKey($platform, $candidateConfig)
            );
            if ($candidateKey !== '' && hash_equals($canonicalProfileKey, $candidateKey)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertNoInlineOtaCredentialOptions(array $options, string $platform): void
    {
        foreach ($options as $key => $value) {
            $key = (string)$key;
            if ($this->isSensitiveConfigKey($key) && $this->credentialPayloadHasValue($value)) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (str_contains(strtolower($key), 'url')) {
                $this->assertOtaMetadataUrlsAreSafe($value, $platform);
            }
            if (strtolower($key) === 'headers' && is_string($value)
                && preg_match('/(?:^|\r?\n)\s*(?:cookie|authorization|x-api-key|token)\s*:/i', $value) === 1
            ) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (is_string($value) && $this->stringContainsCredentialMaterial($value)) {
                throw new RuntimeException('Inline OTA credentials are not allowed for data source sync.', 422);
            }
            if (is_array($value)) {
                $this->assertNoInlineOtaCredentialOptions($value, $platform);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertOtaExecutionConfigSafe(array $config, string $platform): void
    {
        foreach (['url', 'request_url', 'ads_url', 'adsUrl'] as $urlKey) {
            if (array_key_exists($urlKey, $config)) {
                $this->assertOtaMetadataUrlsAreSafe($config[$urlKey], $platform);
            }
        }
        if (array_key_exists('allowed_hosts', $config)) {
            $this->normalizeOtaAllowedHosts($config['allowed_hosts'], $platform);
        }

        $safeCredentialMetadata = [
            'config_id', 'credential_ref', 'credential_status', 'status',
            'has_secret', 'has_cookies', 'secret_mask', 'key_id', 'payload_version', 'rotated_at',
        ];
        foreach ($config as $key => $value) {
            $key = (string)$key;
            if (in_array($key, $safeCredentialMetadata, true)) {
                continue;
            }
            if (in_array(strtolower($key), ['headers', 'headers_json'], true)) {
                [, $secretHeaders] = $this->splitOtaHeaders($value);
                if ($secretHeaders !== []) {
                    throw new RuntimeException('Legacy OTA source headers contain inline credentials and require migration.', 422);
                }
                continue;
            }
            if ($this->isSensitiveConfigKey($key) && $this->credentialPayloadHasValue($value)) {
                throw new RuntimeException('Legacy OTA source config contains inline credentials and requires migration.', 422);
            }
            $this->sanitizeOtaMetadataNode($value);
        }
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $credentialPayload
     * @return array<string, mixed>
     */
    private function sanitizeAdapterResultForCredentialBoundary(array $result, array $credentialPayload): array
    {
        $status = strtolower(trim((string)($result['status'] ?? 'failed')));
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $status) !== 1) {
            $status = 'failed';
        }
        $secretValues = $this->credentialScalarValues($credentialPayload);
        $safe = [
            'status' => $status,
            'message' => $this->safeSyncTaskMessage($status, (string)($result['message'] ?? '')),
            'payload' => is_array($result['payload'] ?? null)
                ? $this->redactCredentialBoundValue($result['payload'], $secretValues)
                : [],
        ];
        if (isset($result['http_status']) && is_numeric($result['http_status'])) {
            $safe['http_status'] = max(0, min(599, (int)$result['http_status']));
        }
        foreach (['status_code', 'error_code'] as $key) {
            if (!isset($result[$key]) || !is_scalar($result[$key])) {
                continue;
            }
            $value = (string)$this->redactCredentialBoundValue((string)$result[$key], $secretValues);
            if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $value) === 1) {
                $safe[$key] = $value;
            }
        }
        return $safe;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function credentialScalarValues(array $payload): array
    {
        $values = [];
        foreach ($payload as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->credentialScalarValues($value));
                continue;
            }
            if (is_scalar($value)) {
                $value = (string)$value;
                if (strlen($value) >= 4) {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $secretValues
     */
    private function redactCredentialBoundValue(mixed $value, array $secretValues): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && ($this->isSensitiveConfigKey($key) || $this->containsCredentialScalar($key, $secretValues))) {
                    continue;
                }
                $safe[$key] = $this->redactCredentialBoundValue($item, $secretValues);
            }
            return $safe;
        }
        if (is_string($value)) {
            foreach ($secretValues as $secret) {
                $value = str_replace($secret, '[redacted]', $value);
            }
            return $value;
        }
        return is_scalar($value) || $value === null ? $value : null;
    }

    /**
     * @param array<int, string> $secretValues
     */
    private function containsCredentialScalar(string $value, array $secretValues): bool
    {
        foreach ($secretValues as $secret) {
            if ($secret !== '' && str_contains($value, $secret)) {
                return true;
            }
        }
        return false;
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
            'browser_profile synchronization requires ' . $missing[0] . ' before capture.',
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
        if (!$this->isOtaBrowserProfileSource($source)
            || !empty($options['interactive_browser'])
        ) {
            return [];
        }
        if ($this->browserProfileRiskControlReviewRequired($source)) {
            return ['profile_risk_control_manual_review_required'];
        }
        $triggerType = strtolower(trim((string)($options['trigger_type'] ?? '')));
        $blockingStatus = $this->profileSessionProofService->currentSessionBlockingStatus($source);
        if ($triggerType === 'profile_login_after_login' && $blockingStatus === 'identity_unverified') {
            return [];
        }
        if ($blockingStatus !== '') {
            return [match ($blockingStatus) {
                'platform_contract_drift' => 'profile_platform_contract_drift',
                'permission_denied' => 'profile_permission_denied',
                'cookies_incomplete' => 'profile_session_cookies_incomplete',
                'identity_mismatch' => 'profile_hotel_identity_mismatch',
                'identity_unverified' => 'profile_hotel_identity_unverified',
                'capture_failed' => 'profile_session_probe_failed',
                'session_expired', 'login_expired' => 'profile_session_expired',
                default => 'profile_session_unverified',
            }];
        }
        if ($triggerType === 'profile_login_after_login') {
            return [];
        }
        $reuseState = $this->profileSessionProofService->profileReuseState($source);
        if (!empty($reuseState['is_reusable'])) {
            return [];
        }
        return [($reuseState['status'] ?? '') === 'expired'
            ? 'profile_session_expired'
            : 'profile_session_unverified'];
    }

    /** @param array<string, mixed> $source */
    private function browserProfileRiskControlReviewRequired(array $source): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $config = is_array($source['config'] ?? null) ? $source['config'] : $this->decodeConfig($source['config_json'] ?? []);
        if (strtolower(trim((string)($config['current_session_status'] ?? ''))) === 'anti_bot') {
            return true;
        }
        if ($this->profileSessionProofService->currentSessionBlockingStatus($source) !== '') {
            return false;
        }
        $profileKey = $this->otaBrowserProfileKey($platform, $config);
        if ($hotelId <= 0 || $profileKey === '') {
            return false;
        }
        $cacheKey = 'platform_profile_status_' . $platform . '_' . $hotelId . '_'
            . BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        try {
            $cached = Cache::get($cacheKey, []);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($cached)
            || strtolower(trim((string)($cached['status_code'] ?? ''))) !== 'anti_bot'
        ) {
            return false;
        }
        $cacheCheckedAt = trim((string)($cached['checked_at'] ?? ''));
        $currentProbeAt = trim((string)($config['current_session_probe_at'] ?? ''));
        if ($cacheCheckedAt !== '' && $currentProbeAt !== '') {
            $cacheTimestamp = strtotime($cacheCheckedAt);
            $probeTimestamp = strtotime($currentProbeAt);
            if ($cacheTimestamp !== false && $probeTimestamp !== false && $cacheTimestamp < $probeTimestamp) {
                return false;
            }
        }
        return true;
    }

    /**
     * P0 and target-date diagnostics intentionally keep the stricter same-day proof.
     *
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private function browserProfileCurrentSessionProofMissingRequirements(array $source): array
    {
        if (!$this->isOtaBrowserProfileSource($source)) {
            return [];
        }
        return $this->profileSessionProofService->isCurrentVerified($source)
            ? []
            : ['current_session_verified'];
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
        return Db::transaction(function () use ($source, $user, $triggerType): int {
            $now = date('Y-m-d H:i:s');
            $lockedSourceQuery = Db::name('platform_data_sources')
                ->field('id,tenant_id,system_hotel_id');
            $this->applyStoredSourceIdentity($lockedSourceQuery, $source);
            $lockedSource = $lockedSourceQuery->lock(true)->find();
            if (!is_array($lockedSource)) {
                throw new RuntimeException('Data source not found.', 404);
            }
            [$tenantId, $hotelId] = $this->assertStoredSourceTenant($lockedSource);
            $predecessorQuery = Db::name('platform_data_sync_tasks')
                ->whereIn('status', self::ACTIVE_SYNC_TASK_STATUSES)
                ->order('id', 'desc')
                ->lock(true);
            $this->applyTaskSourceIdentity($predecessorQuery, $source, $tenantId, $hotelId);
            $predecessor = $predecessorQuery->find();
            $predecessorId = 0;
            $attemptCount = 1;
            $recoveryContextStatus = '';

            if (is_array($predecessor)) {
                $predecessorId = (int)($predecessor['id'] ?? 0);
                $predecessorStats = $this->decodeConfig($predecessor['stats_json'] ?? []);
                $hasRecoveryContext = $this->syncTaskHasRecoveryContext($predecessorStats);
                if (!self::isStaleRunningSyncTask($predecessor)) {
                    $message = 'data source sync task is already active';
                    if (!$hasRecoveryContext) {
                        $message .= '; recovery_context missing (checkpoint unavailable)';
                    }
                    throw new RuntimeException($message, 409);
                }

                $recoveryContextStatus = $hasRecoveryContext
                    ? 'recovery_context_available'
                    : 'missing recovery_context/checkpoint';
                $predecessorStats['recovery_context_status'] = $recoveryContextStatus;
                $predecessorStats['interrupted_at'] = $now;
                $predecessorUpdate = Db::name('platform_data_sync_tasks')
                    ->where('id', $predecessorId)
                    ->whereIn('status', self::ACTIVE_SYNC_TASK_STATUSES);
                $this->applyTaskSourceIdentity($predecessorUpdate, $source, $tenantId, $hotelId);
                $affected = (int)$predecessorUpdate->update([
                        'status' => 'failed',
                        'finished_at' => $now,
                        'message' => 'stale active sync interrupted before recovered retry',
                        'stats_json' => json_encode($predecessorStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'update_time' => $now,
                    ]);
                if ($affected !== 1) {
                    throw new RuntimeException('stale sync predecessor could not be made terminal before retry', 409);
                }
                $attemptCount = max(1, (int)($predecessor['attempt_count'] ?? 1)) + 1;
            }

            $taskStats = [];
            if ($predecessorId > 0) {
                $taskStats = [
                    'predecessor_task_id' => $predecessorId,
                    'recovery_context_status' => $recoveryContextStatus,
                ];
            }
            $data = [
                'data_source_id' => (int)$source['id'],
                'system_hotel_id' => $hotelId,
                'platform' => (string)$source['platform'],
                'data_type' => (string)$source['data_type'],
                'ingestion_method' => (string)$source['ingestion_method'],
                'trigger_type' => $triggerType,
                'status' => 'running',
                'attempt_count' => $attemptCount,
                'max_attempts' => max(3, $attemptCount),
                'started_at' => $now,
                'requested_by' => (int)($user->id ?? 0) ?: null,
                'stats_json' => $taskStats === []
                    ? null
                    : json_encode($taskStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => $now,
                'update_time' => $now,
            ];
            if (isset($this->tableColumns('platform_data_sync_tasks')['tenant_id'])) {
                $data['tenant_id'] = $tenantId;
            }

            return (int)Db::name('platform_data_sync_tasks')->insertGetId($data);
        });
    }

    /** @param array<string, mixed> $stats */
    private function syncTaskHasRecoveryContext(array $stats): bool
    {
        foreach (['recovery_context', 'checkpoint'] as $key) {
            $value = $stats[$key] ?? null;
            if ((is_array($value) && $value !== []) || (is_string($value) && trim($value) !== '')) {
                return true;
            }
        }
        return false;
    }

    private function finishTask(int $taskId, array $source, string $status, string $message, int $normalizedCount, int $savedCount, array $payload, array $timing = [], ?float $syncStartedAt = null): array
    {
        $finishStartedAt = microtime(true);
        $now = date('Y-m-d H:i:s');
        $timing = $this->normalizeSyncTiming($timing);
        $safeMessage = $this->safeSyncTaskMessage($status, $message);
        $safeDiagnostics = $this->sanitizeSyncDiagnosticsForResponse(
            is_array($payload['sync_diagnostics'] ?? null) ? $payload['sync_diagnostics'] : [],
            $status
        );
        [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        $existingTaskQuery = Db::name('platform_data_sync_tasks')->where('id', $taskId);
        $this->applyTaskSourceIdentity($existingTaskQuery, $source, $tenantId, $hotelId);
        $existingTask = $existingTaskQuery->find();
        $existingTaskStats = is_array($existingTask)
            ? $this->decodeConfig($existingTask['stats_json'] ?? [])
            : [];
        $stats = [
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'payload_keys' => array_slice(array_keys($payload), 0, 30),
        ];
        foreach (['predecessor_task_id', 'recovery_context_status'] as $recoveryKey) {
            if (array_key_exists($recoveryKey, $existingTaskStats)) {
                $stats[$recoveryKey] = $existingTaskStats[$recoveryKey];
            }
        }
        $saveReceipt = is_array($payload['_save_receipt'] ?? null) ? $payload['_save_receipt'] : [];
        foreach (['attempted_count', 'inserted_count', 'updated_count', 'deduplicated_count', 'readback_count', 'readback_verified', 'rolled_back', 'failure_reason', 'mismatch_field'] as $receiptKey) {
            if (array_key_exists($receiptKey, $saveReceipt)) {
                $stats[$receiptKey] = $saveReceipt[$receiptKey];
            }
        }
        $stats['run_readback'] = $this->buildRunReadbackReceipt(
            $taskId,
            $source,
            $saveReceipt,
            $payload,
            is_array($existingTask) ? $existingTask : []
        );
        if ($safeDiagnostics !== []) {
            $stats['sync_diagnostics'] = $safeDiagnostics;
        }
        $stats['collection_quality'] = $this->buildSyncTaskCollectionQualitySnapshot(
            $status,
            $source,
            $safeDiagnostics,
            $normalizedCount,
            $savedCount,
            $now
        );
        foreach (['data_period', 'snapshot_time', 'snapshot_bucket'] as $periodKey) {
            if (!empty($payload[$periodKey])) {
                $stats[$periodKey] = (string)$payload[$periodKey];
            }
        }
        $stats = $this->sanitizeSyncTaskStats($stats, $status);
        $nextRetryAt = in_array($status, ['failed', 'partial_success'], true) ? date('Y-m-d H:i:s', time() + 900) : null;

        $finalizeQuery = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('status', 'running');
        $this->applyTaskSourceIdentity($finalizeQuery, $source, $tenantId, $hotelId);
        $finalized = (int)$finalizeQuery->update([
            'status' => $status,
            'finished_at' => $now,
            'next_retry_at' => $nextRetryAt,
            'message' => $safeMessage,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ]);
        if ($finalized !== 1) {
            $persistedTaskQuery = Db::name('platform_data_sync_tasks')->where('id', $taskId);
            $this->applyTaskSourceIdentity($persistedTaskQuery, $source, $tenantId, $hotelId);
            $persistedTask = $persistedTaskQuery->find();
            return $this->persistedSyncTaskResult($taskId, $source, is_array($persistedTask) ? $persistedTask : []);
        }
        if ($this->shouldPreserveSourceStateForModuleResult($status, $payload)) {
            $this->persistOptionalModuleState($source, $payload, $now);
        } else {
            $sourceUpdateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($sourceUpdateQuery, $source);
            $sourceUpdateQuery->update([
                'last_sync_time' => $now,
                'last_sync_status' => $status,
                'last_error' => in_array($status, ['success'], true) ? null : $safeMessage,
                'status' => $status === 'success' ? 'success' : $status,
                'update_time' => $now,
            ]);
        }
        $timing['finish_task_elapsed_ms'] = $this->elapsedMilliseconds($finishStartedAt);
        if ($syncStartedAt !== null) {
            $timing['total_elapsed_ms'] = $this->elapsedMilliseconds($syncStartedAt);
        }
        $stats = $this->sanitizeSyncTaskStats(array_merge($stats, $timing, ['timing' => $timing]), $status);
        $statsUpdateQuery = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('status', $status);
        $this->applyTaskSourceIdentity($statsUpdateQuery, $source, $tenantId, $hotelId);
        $statsUpdateQuery->update([
                'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        $this->logSync($taskId, $source, $status === 'success' ? 'info' : 'warning', 'sync_finished', $safeMessage, $stats);

        return [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $safeMessage,
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'inserted_count' => (int)($stats['inserted_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'readback_count' => (int)($stats['readback_count'] ?? 0),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'run_readback' => is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [],
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => (string)($stats['failure_reason'] ?? ''),
            'predecessor_task_id' => (int)($stats['predecessor_task_id'] ?? 0),
            'recovery_context_status' => (string)($stats['recovery_context_status'] ?? ''),
            'next_retry_at' => $nextRetryAt,
            'timing' => $timing,
            'sync_diagnostics' => $safeDiagnostics !== [] ? $safeDiagnostics : null,
            'collection_quality' => $stats['collection_quality'],
            'module_status' => is_array($payload['module_status'] ?? null) ? $payload['module_status'] : null,
        ];
    }

    /**
     * Return the task state already persisted by a newer recovery attempt.
     * A late worker must not overwrite the terminal task, source state, or logs.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function persistedSyncTaskResult(int $taskId, array $source, array $task): array
    {
        $status = strtolower(trim((string)($task['status'] ?? '')));
        if ($status === '') {
            $status = 'failed';
        }
        $stats = $this->sanitizeSyncTaskStats($this->decodeConfig($task['stats_json'] ?? []), $status);
        $timing = is_array($stats['timing'] ?? null) ? $stats['timing'] : $this->emptySyncTiming();
        $nextRetryAt = trim((string)($task['next_retry_at'] ?? ''));

        return [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $this->safeSyncTaskMessage(
                $status,
                (string)($task['message'] ?? 'sync task completion ignored because task is no longer active')
            ),
            'normalized_count' => (int)($stats['normalized_count'] ?? 0),
            'saved_count' => (int)($stats['saved_count'] ?? 0),
            'inserted_count' => (int)($stats['inserted_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'readback_count' => (int)($stats['readback_count'] ?? 0),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'run_readback' => is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [],
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => (string)($stats['failure_reason'] ?? ''),
            'predecessor_task_id' => (int)($stats['predecessor_task_id'] ?? 0),
            'recovery_context_status' => (string)($stats['recovery_context_status'] ?? ''),
            'next_retry_at' => $nextRetryAt !== '' ? $nextRetryAt : null,
            'timing' => $timing,
            'sync_diagnostics' => is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : null,
            'collection_quality' => is_array($stats['collection_quality'] ?? null) ? $stats['collection_quality'] : [],
            'module_status' => null,
        ];
    }

    /**
     * Build a current-run receipt from rows that are bound to the exact sync
     * task. Aggregate write counts are deliberately insufficient here.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $saveReceipt
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildRunReadbackReceipt(
        int $taskId,
        array $source,
        array $saveReceipt,
        array $payload,
        array $task
    ): array {
        $sourceId = max(0, (int)($source['id'] ?? 0));
        $hotelId = max(0, (int)($source['system_hotel_id'] ?? 0));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $targetDate = $this->normalizeDate($payload['data_date'] ?? $payload['dataDate'] ?? null) ?? '';
        $dataPeriod = $this->normalizeDataPeriod($payload['data_period'] ?? $payload['dataPeriod'] ?? '');
        $startedAt = $this->normalizeDateTime($task['started_at'] ?? '') ?? '';
        $receipt = [
            'readback_verified' => false,
            'sync_task_id' => max(0, $taskId),
            'data_source_id' => $sourceId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'started_at' => $startedAt,
            'row_ids' => [],
            'source_trace_ids' => [],
            'verified_metric_keys' => [],
            'readback_count' => 0,
            'failure_reason' => '',
        ];

        $expectedReadbackCount = max(0, (int)($saveReceipt['readback_count'] ?? $saveReceipt['saved_count'] ?? 0));
        $expectedRowIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int)$value),
            is_array($saveReceipt['row_ids'] ?? null) ? $saveReceipt['row_ids'] : []
        ))));
        if ($taskId <= 0 || $sourceId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)
            || $targetDate === '' || $dataPeriod === '' || $startedAt === ''
            || ($saveReceipt['readback_verified'] ?? false) !== true || $expectedReadbackCount <= 0
            || $expectedRowIds === []
        ) {
            $receipt['failure_reason'] = 'run_identity_or_persistence_readback_missing';
            return $receipt;
        }

        try {
            $columns = $this->tableColumns('online_daily_data');
            foreach (['id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'data_date', 'data_period', 'readback_verified', 'source_trace_id'] as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    $receipt['failure_reason'] = 'run_readback_column_missing:' . $requiredColumn;
                    return $receipt;
                }
            }
            if (!isset($columns['platform']) && !isset($columns['source'])) {
                $receipt['failure_reason'] = 'run_readback_platform_column_missing';
                return $receipt;
            }

            $fields = array_values(array_filter([
                'id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'data_date', 'data_period',
                'readback_verified', 'source_trace_id', 'platform', 'source', 'hotel_id', 'hotel_name',
                'data_type', 'dimension', 'compare_type', 'amount', 'quantity', 'data_value', 'raw_data',
            ], static fn(string $field): bool => isset($columns[$field])));
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where('sync_task_id', $taskId)
                ->where('data_source_id', $sourceId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $targetDate)
                ->where('data_period', $dataPeriod)
                ->whereIn('id', $expectedRowIds);
            if (isset($columns['platform'])) {
                $query->where('platform', $platform);
            }
            if (isset($columns['source'])) {
                $query->where('source', $platform);
            }
            $rows = $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            $receipt['failure_reason'] = 'run_readback_query_failed';
            return $receipt;
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['id'] ?? 0)),
            $rows
        ))));
        $traceIds = [];
        $allRowsReadbackVerified = $rows !== [];
        $allRowsHaveTrace = $rows !== [];
        foreach ($rows as $row) {
            if ((int)($row['readback_verified'] ?? 0) !== 1) {
                $allRowsReadbackVerified = false;
            }
            $traceId = trim((string)($row['source_trace_id'] ?? ''));
            if ($traceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $traceId) !== 1) {
                $allRowsHaveTrace = false;
                continue;
            }
            $traceIds[] = $traceId;
        }
        $traceIds = array_values(array_unique($traceIds));
        $receipt['row_ids'] = array_slice($rowIds, 0, 50);
        $receipt['source_trace_ids'] = array_slice($traceIds, 0, 50);
        $receipt['verified_metric_keys'] = $this->verifiedCoreMetricKeysFromRunRows($rows, $source);
        $receipt['readback_count'] = count($rows);

        // A Profile run may also persist forecast or realtime rows. Verify the
        // target-day subset against the exact row IDs returned by this save
        // receipt instead of requiring every row from the run to share one
        // date and period.
        $receiptRowsBound = $rows !== []
            && count($rows) <= $expectedReadbackCount
            && count($rowIds) === count($rows)
            && array_diff($rowIds, $expectedRowIds) === [];
        $receipt['readback_verified'] = $receiptRowsBound && $allRowsReadbackVerified && $allRowsHaveTrace;
        if (!$receipt['readback_verified']) {
            $receipt['failure_reason'] = !$receiptRowsBound
                ? 'run_readback_receipt_mismatch'
                : (!$allRowsReadbackVerified ? 'run_row_readback_unverified' : 'run_source_trace_missing');
        }

        return $receipt;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private function verifiedCoreMetricKeysFromRunRows(array $rows, array $source): array
    {
        $config = $this->decodeConfig($source['config_json'] ?? $source['config'] ?? []);
        $ownNames = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            [$source['hotel_name'] ?? '', $source['name'] ?? '', $config['hotel_name'] ?? '', $config['hotelName'] ?? '']
        ))));
        $ownIds = [];
        foreach (['external_hotel_id', 'hotel_id', 'hotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'store_id', 'storeId', 'poi_id', 'poiId'] as $key) {
            foreach ([$source, $config] as $candidate) {
                $value = trim((string)($candidate[$key] ?? ''));
                if ($value !== '') {
                    $ownIds[] = $value;
                }
            }
        }
        // Meituan rows must carry an observed self marker from the adapter.
        // Do not promote a config fallback identifier or source label into
        // current-run self evidence. Ctrip has a separate payload identity
        // gate, so its validated bound identifier remains usable here.
        $isMeituan = strtolower(trim((string)($source['platform'] ?? ''))) === 'meituan';
        if ($isMeituan) {
            $ownNames = [];
            $ownIds = [];
        }
        $operatingRows = OtaOperatingScope::filterOwnOperatingRows($rows, $ownNames, array_values(array_unique($ownIds)));
        if ($isMeituan) {
            $operatingRows = array_values(array_filter($operatingRows, function (array $row): bool {
                $raw = $this->decodeConfig($row['raw_data'] ?? []);
                $observed = is_array($raw['row'] ?? null) ? array_replace($raw['row'], $raw) : $raw;
                $compareType = strtolower(trim((string)($row['compare_type'] ?? $observed['compare_type'] ?? $observed['compareType'] ?? '')));
                return in_array($compareType, ['self', 'own', 'mine', 'current'], true)
                    || ($observed['is_self'] ?? null) === true
                    || ($observed['isSelf'] ?? null) === true
                    || (string)($observed['is_self'] ?? '') === '1'
                    || (string)($observed['isSelf'] ?? '') === '1';
            }));
        }

        $revenueVerified = false;
        $roomNightsVerified = false;
        $adrVerified = false;
        $revenueTotal = 0.0;
        $roomNightsTotal = 0.0;
        foreach ($operatingRows as $row) {
            $raw = $this->decodeConfig($row['raw_data'] ?? []);
            $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
            foreach ($facts as $fact) {
                if (!is_array($fact) || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                    || ($fact['stored_value_present'] ?? false) !== true
                ) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if ($metricKey === 'order_amount' && is_numeric($row['amount'] ?? null)) {
                    $revenueVerified = true;
                }
                if ($metricKey === 'room_nights' && is_numeric($row['quantity'] ?? null)) {
                    $roomNightsVerified = true;
                }
                if ($metricKey === 'data_value' && is_numeric($row['data_value'] ?? null)) {
                    $sourceKey = strtolower((string)preg_replace('/[^a-z0-9]+/', '', (string)($fact['source_key'] ?? '')));
                    if (in_array($sourceKey, ['adr', 'avgprice', 'averageprice', 'averagedailyrate'], true)) {
                        $adrVerified = true;
                    }
                }
            }
            if (is_numeric($row['amount'] ?? null)) {
                $revenueTotal += (float)$row['amount'];
            }
            if (is_numeric($row['quantity'] ?? null)) {
                $roomNightsTotal += (float)$row['quantity'];
            }
        }
        if ($revenueVerified && $roomNightsVerified && $roomNightsTotal > 0) {
            $adrVerified = true;
        }

        return array_values(array_filter([
            $revenueVerified ? 'revenue' : null,
            $roomNightsVerified ? 'room_nights' : null,
            $adrVerified ? 'adr' : null,
        ]));
    }

    private function shouldPreserveSourceStateForModuleResult(string $status, array $payload): bool
    {
        $moduleStatus = is_array($payload['module_status'] ?? null) ? $payload['module_status'] : [];
        return strtolower(trim((string)($moduleStatus['module'] ?? ''))) === 'ads'
            && strtolower(trim($status)) !== 'success';
    }

    private function persistOptionalModuleState(array $expectedSource, array $payload, string $checkedAt): void
    {
        $sourceId = (int)($expectedSource['id'] ?? 0);
        $moduleStatus = is_array($payload['module_status'] ?? null) ? $payload['module_status'] : [];
        $module = strtolower(trim((string)($moduleStatus['module'] ?? '')));
        if ($sourceId <= 0 || $module !== 'ads') {
            return;
        }

        Db::transaction(function () use ($expectedSource, $moduleStatus, $module, $checkedAt): void {
            $sourceQuery = Db::name('platform_data_sources')->field('id,tenant_id,system_hotel_id,config_json');
            $this->applyStoredSourceIdentity($sourceQuery, $expectedSource);
            $source = $sourceQuery->lock(true)->find();
            if (!is_array($source)) {
                return;
            }
            $config = $this->decodeConfig($source['config_json'] ?? []);
            $state = [
                'status' => strtolower(trim((string)($moduleStatus['status'] ?? 'blocked'))) ?: 'blocked',
                'reason' => strtolower(trim((string)($moduleStatus['reason'] ?? 'ads_collection_failed'))) ?: 'ads_collection_failed',
                'checked_at' => $checkedAt,
                'external_action_required' => ($moduleStatus['external_action_required'] ?? false) === true,
            ];
            $states = is_array($config['module_states'] ?? null) ? $config['module_states'] : [];
            $states[$module] = $state;
            $config['module_states'] = $states;
            $config['ads_status'] = $state['status'];
            $config['ads_status_reason'] = $state['reason'];
            $config['ads_status_checked_at'] = $state['checked_at'];
            $entryUrl = trim((string)($moduleStatus['entry_url'] ?? ''));
            if ($entryUrl !== '') {
                try {
                    $this->assertOtaMetadataUrlsAreSafe($entryUrl, 'meituan');
                    $config['ads_url'] = $entryUrl;
                    $config['ads_entry_detected_at'] = $checkedAt;
                } catch (\Throwable) {
                    // Ignore unsafe or malformed optional-module metadata.
                }
            }

            $updateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($updateQuery, $source);
            $updateQuery->update([
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'update_time' => $checkedAt,
            ]);
        });
    }

    /**
     * Builds a safe, task-level collection-quality snapshot for task stats.
     * This is evidence for one synchronization task only; the live platform
     * status remains responsible for cross-task freshness and downstream use.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private function buildSyncTaskCollectionQualitySnapshot(
        string $status,
        array $source,
        array $diagnostics,
        int $normalizedCount,
        int $savedCount,
        string $collectedAt
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $isOtaPlatform = in_array($platform, ['ctrip', 'meituan'], true);
        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $safeIngestionMethod = in_array($ingestionMethod, ['browser_profile', 'profile_browser', 'manual', 'api'], true)
            ? $ingestionMethod
            : 'unknown';
        $isBrowserProfile = in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true);
        $isManualImport = $ingestionMethod === 'manual';
        $config = $this->decodeConfig($source['config'] ?? $source['config_json'] ?? []);
        $taskStatus = strtolower(trim($status));
        if (!in_array($taskStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied'], true)) {
            $taskStatus = 'unknown';
        }
        $targetDate = $this->normalizeDate($diagnostics['target_date'] ?? null) ?? '';
        $targetRows = max(0, (int)($diagnostics['target_date_rows'] ?? 0));
        $targetTrafficRows = max(0, (int)($diagnostics['target_date_traffic_rows'] ?? 0));
        $fieldFactStatus = strtolower(trim((string)($diagnostics['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $p0Status = strtolower(trim((string)($diagnostics['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded'], true)) {
            $p0Status = 'unknown';
        }
        $confirmedEmpty = $this->truthy($diagnostics['confirmed_empty'] ?? false);

        $qualityFlags = $this->syncTaskQualityMissingInputFlags($diagnostics['missing_inputs'] ?? []);
        $bindingFlags = [];
        if ($isOtaPlatform && (int)($source['system_hotel_id'] ?? 0) <= 0) {
            $bindingFlags[] = 'system_hotel_id_missing';
        }
        if ($isOtaPlatform && (int)($source['id'] ?? 0) <= 0) {
            $bindingFlags[] = 'data_source_id_missing';
        }
        if ($isBrowserProfile && $this->syncTaskOtaStoreIdentifier($platform, $config) === '') {
            $bindingFlags[] = 'ota_store_id_missing';
        }
        if ($isBrowserProfile && $this->syncTaskProfileIdentifier($config) === '') {
            $bindingFlags[] = 'profile_id_missing';
        }

        $profileStatus = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
        $permissionDenied = in_array($taskStatus, ['permission_denied'], true)
            || in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true);
        $profileLoginVerified = $isBrowserProfile
            && $this->profileSessionProofService->isCurrentVerified($source);
        $taskFailed = in_array($taskStatus, ['failed', 'capture_failed'], true);

        $state = 'unverified';
        $nextAction = 'verify_target_date_evidence';
        if (!$isOtaPlatform) {
            $qualityFlags[] = 'non_ota_platform_source';
            $nextAction = 'verify_task_source_scope';
        } elseif ($bindingFlags !== []) {
            $qualityFlags = array_merge($qualityFlags, $bindingFlags);
            $state = 'binding_missing';
            $nextAction = 'complete_hotel_poi_binding';
        } elseif ($permissionDenied) {
            $qualityFlags[] = 'platform_permission_denied';
            $state = 'permission_denied';
            $nextAction = 'restore_platform_permission';
        } elseif ($taskFailed) {
            $qualityFlags[] = 'task_status_failed';
            $state = 'collection_failed';
            $nextAction = 'inspect_collection_failure';
        } elseif ($isManualImport) {
            $qualityFlags[] = 'manual_import_provenance_unverified';
            $nextAction = 'verify_manual_import_provenance';
        } elseif (!$isBrowserProfile) {
            $qualityFlags[] = 'source_ingestion_method_unverified';
            $nextAction = 'verify_collection_method';
        } elseif (!$profileLoginVerified) {
            $qualityFlags[] = 'platform_session_not_verified';
            $nextAction = 'verify_platform_login_state';
        } elseif ($targetDate === '') {
            $qualityFlags[] = 'target_date_missing';
            $nextAction = 'select_target_date';
        } elseif ($p0Status === 'not_required'
            && $taskStatus === 'success'
            && (($savedCount > 0 && $targetRows > 0) || $confirmedEmpty)
        ) {
            $state = 'available';
            $nextAction = '';
        } elseif ($p0Status !== 'ready') {
            $qualityFlags[] = 'p0_target_date_evidence_not_ready';
            $nextAction = 'verify_target_date_evidence';
        } elseif ($savedCount <= 0 || $targetRows <= 0 || $targetTrafficRows <= 0) {
            if ($savedCount <= 0) {
                $qualityFlags[] = 'saved_rows_missing';
            }
            if ($targetRows <= 0) {
                $qualityFlags[] = 'target_date_rows_missing';
            }
            if ($targetTrafficRows <= 0) {
                $qualityFlags[] = 'target_date_traffic_rows_missing';
            }
            $nextAction = 'collect_target_date_data';
        } elseif ($fieldFactStatus === 'partial' || $taskStatus === 'partial_success') {
            if ($fieldFactStatus === 'partial') {
                $qualityFlags[] = 'target_date_field_facts_partial';
            }
            if ($taskStatus === 'partial_success') {
                $qualityFlags[] = 'task_partial_success';
            }
            $state = 'partial';
            $nextAction = 'complete_missing_target_date_evidence';
        } elseif ($taskStatus === 'success' && $fieldFactStatus === 'ready') {
            $state = 'available';
            $nextAction = '';
        } else {
            $qualityFlags[] = 'task_quality_not_verified';
        }

        return [
            'primary_quality_state' => $state,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'metric_scope' => $isOtaPlatform ? 'ota_channel' : 'unknown',
            'evidence_scope' => 'sync_task',
            'target_date' => $targetDate,
            'data_as_of' => (($targetRows > 0 && $savedCount > 0) || $confirmedEmpty) ? $targetDate : '',
            'collected_at' => trim($collectedAt),
            'evidence' => [
                'task_status' => $taskStatus,
                'ingestion_method' => $safeIngestionMethod,
                'p0_status' => $p0Status,
                'target_date_rows' => $targetRows,
                'target_date_traffic_rows' => $targetTrafficRows,
                'field_fact_status' => $fieldFactStatus,
                'normalized_count' => max(0, $normalizedCount),
                'saved_count' => max(0, $savedCount),
                'confirmed_empty' => $confirmedEmpty,
            ],
            'next_action' => $nextAction,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function syncTaskQualityMissingInputFlags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = [
            'current_session_verified',
            'manual_login_state_verified',
            'profile_status_logged_in',
            'last_login_verified_at',
            'target_date_traffic_rows',
            'traffic_field_facts',
        ];
        $flags = [];
        foreach ($value as $item) {
            $flag = strtolower(trim((string)$item));
            if (in_array($flag, $allowed, true)) {
                $flags[] = $flag;
            }
        }

        return array_values(array_unique($flags));
    }

    private function syncTaskOtaStoreIdentifier(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'hotelId'];
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function syncTaskProfileIdentifier(array $config): string
    {
        foreach (['profile_id', 'profileId', 'stable_profile_id', 'stableProfileId', 'profile_binding_key', 'profileBindingKey'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
        $confirmedEmpty = $this->isAuthoritativeEmptySyncPayload($payload);
        $captureSectionStatuses = $this->syncCaptureSectionStatuses($options, $payload);
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
        foreach ($this->browserProfileCurrentSessionProofMissingRequirements($source) as $missingLoginRequirement) {
            if (!in_array($missingLoginRequirement, $missingInputs, true)) {
                $missingInputs[] = $missingLoginRequirement;
            }
        }
        $p0Status = $missingInputs !== []
            ? 'blocked'
            : ($requiresTraffic ? 'ready' : (($savedCount > 0 || $confirmedEmpty) ? 'not_required' : 'not_loaded'));
        $capabilityStates = $this->syncTaskCapabilityStates($dataTypes, $savedCount, $adapterStatus);
        if ($confirmedEmpty && $adapterStatus === 'success') {
            $capabilityStates = $this->applyConfirmedEmptyCapabilityStates($capabilityStates, $options, $payload);
        }
        $capabilityStates = $this->applyCaptureSectionCapabilityStates($capabilityStates, $captureSectionStatuses);
        $operatorMessage = 'target_date_traffic_ready';
        if (in_array('current_session_verified', $missingInputs, true)) {
            $operatorMessage = 'current_session_not_verified';
        } elseif (in_array('target_date_traffic_rows', $missingInputs, true)) {
            $operatorMessage = 'profile_reused_no_target_date_traffic_rows';
        } elseif (in_array('traffic_field_facts', $missingInputs, true)) {
            $operatorMessage = 'traffic_field_facts_missing';
        } elseif ($adapterStatus !== 'success') {
            $operatorMessage = $this->safeSyncTaskMessage($adapterStatus, $adapterMessage);
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
            'capability_states' => $capabilityStates,
            'capture_section_statuses' => $captureSectionStatuses,
            'missing_inputs' => $missingInputs,
            'operator_message' => $operatorMessage,
            'adapter_status' => $adapterStatus,
            'confirmed_empty' => $confirmedEmpty,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function syncCaptureSectionStatuses(array $options, array $payload): array
    {
        $captureGate = $payload['capture_gate'] ?? $payload['captureGate'] ?? null;
        if (!is_array($captureGate)) {
            $captureGate = $payload['data_source_capture']['capture_gate'] ?? null;
        }
        $statuses = $this->sanitizeSyncCaptureSectionStatuses(
            is_array($captureGate) ? ($captureGate['section_statuses'] ?? $captureGate['sectionStatuses'] ?? null) : null
        );

        $skippedSections = [
            $options['skipped_sections_no_entry'] ?? null,
            $options['skippedSectionsNoEntry'] ?? null,
            $payload['skipped_sections_no_entry'] ?? null,
            $payload['skippedSectionsNoEntry'] ?? null,
            $payload['data_source_capture']['skipped_sections_no_entry'] ?? null,
        ];
        foreach ($skippedSections as $value) {
            $sections = is_array($value) ? $value : preg_split('/[,\s]+/', trim((string)$value));
            foreach (is_array($sections) ? $sections : [] as $section) {
                $section = strtolower(trim((string)$section));
                if (!in_array($section, ['traffic', 'order_flow', 'orders', 'ads', 'reviews'], true)) {
                    continue;
                }
                if (!isset($statuses[$section]) || $statuses[$section] === 'not_captured') {
                    $statuses[$section] = 'not_applicable';
                }
            }
        }

        return $statuses;
    }

    /**
     * @param array<string, string> $states
     * @param array<string, string> $sectionStatuses
     * @return array<string, string>
     */
    private function applyCaptureSectionCapabilityStates(array $states, array $sectionStatuses): array
    {
        foreach (['orders' => 'orders', 'reviews' => 'reviews'] as $section => $capability) {
            $status = $sectionStatuses[$section] ?? '';
            if ($status === 'empty_confirmed') {
                $states[$capability] = 'verified';
            } elseif ($status === 'not_applicable') {
                $states[$capability] = 'capability_unavailable';
            }
        }
        return $states;
    }

    /**
     * @param array<string, string> $states
     * @return array<string, string>
     */
    private function applyConfirmedEmptyCapabilityStates(array $states, array $options, array $payload): array
    {
        $sectionText = strtolower(implode(',', array_filter(array_map(
            static fn($value): string => is_string($value) ? trim($value) : '',
            [
                $options['capture_sections'] ?? null,
                $options['captureSections'] ?? null,
                $options['sections'] ?? null,
                $payload['data_source_capture']['requested_capture_sections'] ?? null,
                $payload['data_source_capture']['capture_sections'] ?? null,
            ]
        ))));
        if (preg_match('/(^|[,\s])orders?([,\s]|$)/', $sectionText) === 1) {
            $states['orders'] = 'verified';
        }
        if (preg_match('/(^|[,\s])(reviews?|comments?)([,\s]|$)/', $sectionText) === 1) {
            $states['reviews'] = 'verified';
        }
        return $states;
    }

    /**
     * @param array<string, bool> $targetDataTypes
     * @return array<string, string>
     */
    private function syncTaskCapabilityStates(array $targetDataTypes, int $savedCount, string $adapterStatus): array
    {
        $states = [
            'business' => 'unverified',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ];
        if (in_array(strtolower(trim($adapterStatus)), ['permission_denied', 'no_permission', 'unauthorized', 'forbidden'], true)) {
            return array_fill_keys(array_keys($states), 'permission_denied');
        }
        if ($savedCount <= 0) {
            return $states;
        }

        foreach ([
            'business' => 'business',
            'order' => 'orders',
            'review' => 'reviews',
        ] as $dataType => $capability) {
            if (isset($targetDataTypes[$dataType])) {
                $states[$capability] = 'verified';
            }
        }

        return $states;
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

        $explicitSections = array_values(array_filter([
            $options['capture_sections'] ?? null,
            $options['captureSections'] ?? null,
            $options['sections'] ?? null,
            $payload['data_source_capture']['requested_capture_sections'] ?? null,
            $payload['data_source_capture']['capture_sections'] ?? null,
        ], static fn($value): bool => is_string($value) && trim($value) !== ''));
        if ($explicitSections !== []) {
            $explicitSectionText = strtolower(implode(',', $explicitSections));
            return preg_match('/traffic|flow|core|default|business_overview|traffic_report/', $explicitSectionText) === 1;
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
            $data['tenant_id'] = $this->resolveSourceTenantId($source);
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
     * @return array{attempted_count:int,saved_count:int,inserted_count:int,updated_count:int,deduplicated_count:int,readback_count:int,readback_verified:bool,row_ids:array<int,int>}
     */
    private function saveNormalizedRows(array $rows): array
    {
        if (empty($rows)) {
            return [
                'attempted_count' => 0,
                'saved_count' => 0,
                'inserted_count' => 0,
                'updated_count' => 0,
                'deduplicated_count' => 0,
                'readback_count' => 0,
                'readback_verified' => true,
                'row_ids' => [],
            ];
        }

        $failureReceipt = null;
        try {
            return Db::transaction(function () use ($rows, &$failureReceipt): array {
                $columns = $this->tableColumns('online_daily_data');
                $attempted = count($rows);
                $inserted = 0;
                $updated = 0;
                $readback = 0;
                $rowIds = [];
                $readbackRows = [];
                $preparedRows = [];
                foreach ($rows as $row) {
                    $data = array_intersect_key($row, $columns);
                    if ($data === []) {
                        $failureReceipt = $this->normalizedRowsRollbackReceipt($attempted, 'normalized_row_has_no_persistable_columns');
                        throw new RuntimeException('normalized_row_has_no_persistable_columns');
                    }
                    $data = OnlineDailyDataPersistenceService::applyTenantScope($data, $columns);
                    $data = OnlineDailyDataPersistenceService::resetReadbackVerification($data, $columns);
                    $preparedRows[$this->normalizedRowIdentityKey($data, $columns)] = $data;
                }
                $deduplicatedCount = max(0, $attempted - count($preparedRows));

                foreach ($preparedRows as $data) {
                    $existing = $this->findNormalizedRowByCompleteIdentity($data, $columns);
                    if (is_array($existing)) {
                        $rowId = (int)($existing['id'] ?? 0);
                        if (isset($columns['update_time'])) {
                            $data['update_time'] = date('Y-m-d H:i:s');
                        }
                        Db::name('online_daily_data')->where('id', $rowId)->update($data);
                        $updated++;
                    } else {
                        if (isset($columns['create_time'])) {
                            $data['create_time'] = date('Y-m-d H:i:s');
                        }
                        if (isset($columns['update_time'])) {
                            $data['update_time'] = date('Y-m-d H:i:s');
                        }
                        $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
                        if ($rowId > 0) {
                            $inserted++;
                        }
                    }

                    $mismatchField = null;
                    $readbackRow = $rowId > 0
                        ? $this->normalizedRowReadback($rowId, $data, $columns, $mismatchField)
                        : null;
                    if (!is_array($readbackRow)) {
                        $failureReceipt = $this->normalizedRowsRollbackReceipt(
                            $attempted,
                            'normalized_rows_readback_mismatch_rolled_back',
                            $mismatchField
                        );
                        throw new RuntimeException('normalized_rows_readback_mismatch_rolled_back');
                    }
                    $readback++;
                    $rowIds[] = $rowId;
                    $readbackRows[] = $readbackRow;
                }

                if (count($preparedRows) !== $readback
                    || !OnlineDailyDataPersistenceService::markRowsReadbackVerified($readbackRows, $columns)) {
                    $failureReceipt = $this->normalizedRowsRollbackReceipt(
                        $attempted,
                        'normalized_rows_readback_proof_not_persisted_rolled_back'
                    );
                    throw new RuntimeException('normalized_rows_readback_proof_not_persisted_rolled_back');
                }

                return [
                    'attempted_count' => $attempted,
                    'saved_count' => $readback,
                    'inserted_count' => $inserted,
                    'updated_count' => $updated,
                    'deduplicated_count' => $deduplicatedCount,
                    'readback_count' => $readback,
                    'readback_verified' => count($preparedRows) === $readback,
                    'rolled_back' => false,
                    'failure_reason' => '',
                    'row_ids' => array_slice($rowIds, 0, 50),
                ];
            });
        } catch (RuntimeException $e) {
            if (is_array($failureReceipt)) {
                return $failureReceipt;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, bool> $columns
     * @return array<string, mixed>|null
     */
    private function findNormalizedRowByCompleteIdentity(array $row, array $columns): ?array
    {
        $identityFields = [
            'tenant_id', 'system_hotel_id', 'data_source_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ];
        $applyIdentity = static function ($query) use ($row, $columns, $identityFields): void {
            foreach ($identityFields as $field) {
                if (!isset($columns[$field]) || !array_key_exists($field, $row)) {
                    continue;
                }
                if ($row[$field] === null) {
                    $query->whereNull($field);
                } else {
                    $query->where($field, $row[$field]);
                }
            }
        };

        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($traceId !== '' && isset($columns['source_trace_id'])) {
            $traceQuery = Db::name('online_daily_data')->where('source_trace_id', $traceId);
            $applyIdentity($traceQuery);
            $existing = $traceQuery->find();
            if (is_array($existing)) {
                return $existing;
            }
        }

        $query = Db::name('online_daily_data');
        $applyIdentity($query);
        $existing = $query->find();
        return is_array($existing) ? $existing : null;
    }

    /** @param array<string, mixed> $row @param array<string, bool> $columns */
    private function normalizedRowIdentityKey(array $row, array $columns): string
    {
        $identity = [];
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ] as $field) {
            if (!isset($columns[$field]) || !array_key_exists($field, $row)) {
                continue;
            }
            $identity[$field] = $row[$field] === null ? null : (string)$row[$field];
        }
        if ($identity === []) {
            throw new RuntimeException('normalized_row_identity_missing');
        }
        return json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function normalizedRowsRollbackReceipt(int $attempted, string $reason, ?string $mismatchField = null): array
    {
        return [
            'attempted_count' => max(0, $attempted),
            'saved_count' => 0,
            'inserted_count' => 0,
            'updated_count' => 0,
            'deduplicated_count' => 0,
            'readback_count' => 0,
            'readback_verified' => false,
            'rolled_back' => true,
            'failure_reason' => $reason,
            'mismatch_field' => trim((string)$mismatchField),
            'row_ids' => [],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, bool> $columns
     */
    private function normalizedRowReadback(int $rowId, array $expected, array $columns, ?string &$mismatchField = null): ?array
    {
        $mismatchField = null;
        $stored = Db::name('online_daily_data')->where('id', $rowId)->find();
        if (!is_array($stored)) {
            $mismatchField = '__row_missing__';
            return null;
        }
        foreach ($expected as $field => $expectedValue) {
            if (!isset($columns[$field]) || in_array($field, ['id'], true)) {
                continue;
            }
            $storedValue = $stored[$field] ?? null;
            if (!$this->normalizedStoredValueMatches($storedValue, $expectedValue, (string)$field)) {
                $mismatchField = (string)$field;
                return null;
            }
        }
        return $stored;
    }

    private function normalizedStoredValueMatches(mixed $stored, mixed $expected, string $field = ''): bool
    {
        if ($expected === null) {
            return $stored === null;
        }
        if (is_int($expected)) {
            return is_numeric($stored) && (int)$stored === $expected;
        }
        if (is_float($expected)) {
            if (!is_numeric($stored)) {
                return false;
            }
            if (in_array($field, ['comment_score', 'qunar_comment_score'], true)) {
                return abs((float)$stored - round($expected, 1, PHP_ROUND_HALF_UP)) <= 0.000001;
            }
            return abs((float)$stored - $expected) <= 0.005001;
        }
        if (is_bool($expected)) {
            return (bool)$stored === $expected;
        }
        if (is_string($expected) && ($expected !== '') && in_array($expected[0], ['{', '['], true)) {
            $expectedJson = json_decode($expected, true);
            $storedJson = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($expectedJson) && is_array($storedJson)) {
                return $storedJson == $expectedJson;
            }
        }
        return (string)$stored === (string)$expected;
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
    private function amountValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'advertising') {
            return $this->nullableNumericValue($row, ['todayCost', 'cost', 'cashCost', 'bonusCost', 'ad_cost', 'adCost', 'spend', 'amount'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($dataType === 'order') {
            return $this->nullableNumericValue($row, ['totalAmount', 'orderAmount', 'payAmount', 'roomAmount', 'amount', 'order_amount', 'room_revenue', 'revenue'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($preserveMissing && in_array($dataType, ['review', 'peer_rank'], true)) {
            return null;
        }
        $amount = $this->nullableNumericValue($row, ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount']);
        return $amount ?? ($preserveMissing ? null : 0.0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quantityValue(array $row, string $dataType, bool $preserveMissing = false): ?int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($preserveMissing && $dataType === 'peer_rank') {
            return null;
        }
        if ($dataType === 'order') {
            $roomCount = $this->nullableNumericValue($row, ['roomCount', 'room_count']);
            $nights = $this->nullableNumericValue($row, ['nights', 'night_count', 'nightCount']);
            if ($roomCount !== null && $roomCount > 0 && $nights !== null && $nights > 0) {
                return (int)round($roomCount * $nights);
            }
            if ($preserveMissing) {
                $quantity = $this->nullableNumericValue($row, ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'nightCount']);
                return $quantity === null ? null : (int)round($quantity);
            }
        }
        if ($dataType === 'review') {
            $count = $this->nullableNumericValue($row, ['review_count', 'reviewCount', 'comment_count', 'commentCount', 'count', 'quantity']);
            if ($preserveMissing) {
                return $count === null ? null : (int)round($count);
            }
            $count = $count ?? 0.0;
            return $count > 0 ? (int)round($count) : 1;
        }

        $quantity = $this->nullableNumericValue($row, [
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
        return $quantity === null
            ? ($preserveMissing ? null : 0)
            : (int)round($quantity);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function dataValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'review') {
            return $this->nullableNumericValue($row, [
                'bad_review_count',
                'badReviewCount',
                'negativeCommentCount',
                'negativeCount',
                'badCount',
                'lowScoreCount',
                'noRecommendCount',
                'data_value',
                'dataValue',
            ]) ?? ($preserveMissing ? null : 0.0);
        }
        if ($preserveMissing && $dataType === 'peer_rank') {
            return null;
        }

        if ($dataType === 'advertising') {
            return $this->nullableNumericValue($row, ['roas', 'roi', 'data_value', 'dataValue']);
        }

        $explicit = $this->nullableNumericValue($row, ['data_value', 'dataValue', 'value', 'metric_value', 'averagePrice', 'avgPrice', 'avg_price']);
        if ($explicit !== null) {
            return $explicit;
        }
        if ($dataType === 'quality') {
            return $this->nullableNumericValue($row, ['serviceScore', 'psiScore', 'imScore', 'score'])
                ?? ($preserveMissing ? null : 0.0);
        }
        if ($dataType === 'peer_rank') {
            return $this->numericValue($row, ['rank', 'ranking', 'rankValue', 'rank_value', 'rankPercent', 'rank_percent']);
        }
        if ($dataType === 'order') {
            $quantity = $this->quantityValue($row, $dataType, $preserveMissing);
            $amount = $this->amountValue($row, $dataType, $preserveMissing);
            if ($quantity !== null && $quantity > 0 && $amount !== null) {
                return round($amount / $quantity, 2);
            }
            return $preserveMissing ? null : 0.0;
        }

        return $preserveMissing ? null : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function orderCountValue(array $row, string $dataType, bool $preserveMissing = false): ?int
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($preserveMissing && in_array($dataType, ['review', 'peer_rank'], true)) {
            return null;
        }
        $count = $this->nullableNumericValue($row, ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount']);
        if ($count !== null) {
            return (int)round($count);
        }
        if ($preserveMissing) {
            return null;
        }
        if ($dataType === 'order' && $this->firstOrderIdentifier($row) !== '') {
            return 1;
        }
        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function commentScoreValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        if ($this->normalizeDataType($dataType) !== 'review') {
            return $this->nullableNumericValue($row, ['comment_score']);
        }
        return $this->nullableNumericValue($row, [
            'comment_score',
            'commentScore',
            'score',
            'star',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
        ])
            ?? ($preserveMissing ? null : 0.0);
    }

    /**
     * `flow_rate` is a funnel-stage metric. Advertising rows use CTR
     * (impressions to clicks); CVR stays in raw_data as a separate stage.
     *
     * @param array<string, mixed> $row
     */
    private function flowRateValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        $keys = $dataType === 'advertising'
            ? ['flow_rate', 'flowRate', 'ctr']
            : ['flow_rate', 'flowRate', 'intentionPerExposure', 'cvr', 'conversion_rate', 'conversionRate', 'convertionRate', 'avgConversionsRate', 'orderConversionRate', 'dealRate'];
        return $this->nullableNumericValue($row, $keys) ?? ($preserveMissing ? null : 0.0);
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
        $privateValues = $this->reviewPrivateScalarValues($payload);
        $sanitized = $this->removeReviewPrivateFields(
            $this->sanitizePayloadForStorage($payload, 'review'),
            $privateValues
        );
        if ($allowSummary) {
            $summary = $this->reviewSummaryText($payload, $privateValues);
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
    private function removeReviewPrivateFields(array $node, array $privateValues = []): array
    {
        $clean = [];
        foreach ($node as $key => $value) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($value)
                ? $this->sanitizeReviewArray($value, $privateValues)
                : (is_string($value) ? $this->sanitizeReviewScalar($value, $privateValues) : $value);
        }
        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizeReviewArray(array $value, array $privateValues = []): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyText = (string)$key;
            if ($this->isReviewPrivateKey($keyText)) {
                continue;
            }
            $clean[$key] = is_array($item)
                ? $this->sanitizeReviewArray($item, $privateValues)
                : (is_string($item) ? $this->sanitizeReviewScalar($item, $privateValues) : $item);
        }
        return $clean;
    }

    private function isReviewPrivateKey(string $key): bool
    {
        return preg_match('/content|commentContent|comment_text|review_text|reviewer|review[_-]?id|comment[_-]?id|reply|guest|customer|userName|username|nick|phone|mobile|tel|email|certificate|idcard|id_card|identity|openid|avatar|order[_-]?(id|no|number)|room(type|name)|photo|image|pic/i', $key) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reviewSummaryText(array $payload, array $privateValues = []): string
    {
        $text = $this->stringValue($payload, ['review_summary', 'summary', 'content', 'commentContent', 'comment_text', 'review_text']);
        if ($text === '') {
            return '';
        }
        $text = $this->sanitizeReviewScalar($text, $privateValues);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        return mb_substr($text, 0, 120);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<int, string>
     */
    private function reviewPrivateScalarValues(array $node): array
    {
        $values = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->reviewPrivateScalarValues($value));
                continue;
            }
            if (!$this->isReviewIdentityKey((string)$key) || !is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $values[] = $text;
            }
        }
        $values = array_values(array_unique($values));
        usort($values, static fn(string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        return $values;
    }

    private function isReviewIdentityKey(string $key): bool
    {
        return preg_match('/reviewer|guest|customer|user[_-]?name|username|nick|phone|mobile|tel|email|certificate|idcard|id_card|identity|openid|order[_-]?(id|no|number)/i', $key) === 1;
    }

    /** @param array<int, string> $privateValues */
    private function sanitizeReviewScalar(string $value, array $privateValues = []): string
    {
        $sanitized = $value;
        foreach ($privateValues as $privateValue) {
            if ($privateValue !== '') {
                $sanitized = str_ireplace($privateValue, '[redacted]', $sanitized);
            }
        }
        $sanitized = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?<!\d)(?:\+?86[\s-]*)?1[3-9](?:[\s-]*\d){9}(?!\d)/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/(?<!\d)\d{17}[\dXx](?!\d)/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b\d{6,}\b/', '[redacted]', $sanitized) ?? $sanitized;
        return trim($sanitized);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function reviewValidationFlags(array $row, array $payload, string $dataDate, string $collectionStatus): array
    {
        $flags = [];
        $score = $this->nullableNumericValue($row, [
            'comment_score',
            'commentScore',
            'score',
            'star',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
        ]);
        if ($score === null) {
            $flags[] = 'field_missing:comment_score';
        }

        $targetDate = $this->normalizeDate($payload['target_date'] ?? $payload['targetDate'] ?? null);
        if ($targetDate !== null && $dataDate !== $targetDate) {
            $flags[] = 'data_date_stale:' . $dataDate;
        }
        if ($collectionStatus === 'stale') {
            $flags[] = 'collection_status:stale';
        }
        return $flags;
    }

    /** @param array<int, string> $flags */
    private function reviewValidationStatus(array $flags): string
    {
        if ($flags === []) {
            return 'normal';
        }
        $hasStale = count(array_filter($flags, static fn(string $flag): bool => str_contains($flag, 'stale'))) > 0;
        $hasMissing = count(array_filter($flags, static fn(string $flag): bool => str_starts_with($flag, 'field_missing:'))) > 0;
        if ($hasStale && $hasMissing) {
            return 'quarantined';
        }
        return $hasStale ? 'stale' : 'incomplete';
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
        $isOta = $this->isOtaPlatform((string)($row['platform'] ?? ''));
        $profileMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        $row['current_session_verified'] = $isOta
            && in_array($profileMethod, ['browser_profile', 'profile_browser'], true)
            && $this->profileSessionProofService->isCurrentVerified($row);
        $profileReuseState = $isOta && in_array($profileMethod, ['browser_profile', 'profile_browser'], true)
            ? $this->profileSessionProofService->profileReuseState($row)
            : [
                'status' => 'unverified',
                'is_reusable' => false,
                'age_days' => null,
                'days_until_forced_login' => 0,
                'warning' => false,
            ];
        $row['profile_reusable'] = (bool)($profileReuseState['is_reusable'] ?? false);
        $row['profile_reuse_status'] = (string)($profileReuseState['status'] ?? 'unverified');
        $row['profile_reuse_warning'] = (bool)($profileReuseState['warning'] ?? false);
        $row['profile_age_days'] = isset($profileReuseState['age_days']) ? (int)$profileReuseState['age_days'] : null;
        $row['days_until_forced_login'] = max(0, (int)($profileReuseState['days_until_forced_login'] ?? 0));
        $secret = $isOta ? [] : $this->decodeConfig($row['secret_json'] ?? []);
        unset($row['config_json']);
        unset($row['secret_json']);
        $row['config'] = $this->sanitizeConfigForResponse($config);
        if ($isOta) {
            $row['config_id'] = trim((string)($config['config_id'] ?? ''));
            $row['credential_ref'] = (int)($config['credential_ref'] ?? 0) ?: null;
            $row['credential_status'] = trim((string)($config['credential_status'] ?? $config['status'] ?? ''));
            $row['has_secret'] = array_key_exists('has_secret', $config)
                ? $this->truthy($config['has_secret'])
                : (int)($config['credential_ref'] ?? 0) > 0;
            $row['has_cookies'] = $this->truthy($config['has_cookies'] ?? false);
        } else {
            $row['has_secret'] = !empty($secret);
            $row['has_cookies'] = isset($secret['cookies']) && trim((string)$secret['cookies']) !== '';
        }
        unset($row['cookies_preview']);
        if (array_key_exists('last_error', $row)) {
            $row['last_error'] = $this->safeSyncTaskMessage((string)($row['last_sync_status'] ?? $row['status'] ?? ''), (string)$row['last_error']);
        }
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
            $normalized = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', (string)$key), '_'));
            if (in_array($normalized, ['profile_key_hash', 'current_session_probe_profile_key_hash'], true)) {
                unset($config[$key]);
                continue;
            }
            if ($this->isSensitiveConfigKey((string)$key)) {
                $config[$key] = '[configured]';
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
        $normalized = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '_', $key), '_'));
        if (in_array($normalized, [
            'has_secret', 'secret_mask', 'has_cookies', 'cookie_configured',
            'has_profile_cookie_source', 'profile_cookie_source', 'profile_cookie_source_candidate', 'cookie_source',
            'authorization_policy', 'requires_explicit_authorization',
        ], true)) {
            return false;
        }
        return preg_match('/cookie|authorization|auth[-_]?data|token|api[-_]?key|secret|password|spider[-_]?(?:token|key)|mtgsig|user[-_]?(?:token|sign)|_mtsi_eb_u/i', $key) === 1;
    }

    private function stringContainsCredentialMaterial(string $value): bool
    {
        return preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1;
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
    private function nullableNumericValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $value = str_replace([',', '%', ' ', "\u{00A0}", '元', '￥', '¥'], '', (string)$row[$key]);
            if ($value === '') {
                continue;
            }
            return is_numeric($value) ? (float)$value : null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function integerMetricValue(array $row, array $keys, bool $preserveMissing = false): ?int
    {
        $value = $this->nullableNumericValue($row, $keys);
        if ($value === null) {
            return $preserveMissing ? null : 0;
        }
        return (int)round($value);
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

        $dataType = $this->normalizeDataType((string)(
            $row['data_type']
            ?? $row['dataType']
            ?? $payload['data_type']
            ?? $payload['dataType']
            ?? $source['data_type']
            ?? ''
        ));
        if ($dataType === 'traffic_forecast') {
            $period = 'next_30_days';
        } elseif ($date === date('Y-m-d') && $period === 'historical_daily') {
            $period = 'realtime_snapshot';
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
            $snapshotBucket = date('YmdHi', strtotime($snapshotTime) ?: time());
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
            'next_30_days', 'next30days', 'future_forecast', 'forecast', 'forecast_window' => 'next_30_days',
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
        return '[configured]';
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
            $this->resolveHotelTenantId($hotelId);
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0
            || $hotelId <= 0
            || !method_exists($user, 'hasHotelPermission')
            || !$user->hasHotelPermission($hotelId, $permission)
        ) {
            throw new RuntimeException('Forbidden.', 403);
        }
        try {
            $authoritativeTenantId = $this->resolveHotelTenantId($hotelId);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Forbidden.', 403, $exception);
        }
        if ($authoritativeTenantId !== $tenantId) {
            throw new RuntimeException('Forbidden.', 403);
        }
    }

    private function applySourceScope($query, $user): void
    {
        $this->applySourceTenantScope($query, $user);
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    private function applySourceTenantScope($query, $user): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Authenticated tenant context is required.', 403);
        }
        $query->where('tenant_id', $tenantId);
    }

    private function applyTaskScope($query, $user): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return;
        }
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0) {
            throw new RuntimeException('Authenticated tenant context is required.', 403);
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds') ? array_values(array_map('intval', $user->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            $query->whereRaw('1=0');
            return;
        }
        $query->where('tenant_id', $tenantId);
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    /** @param array<string, mixed> $source @return array{0:int,1:int} */
    private function assertStoredSourceTenant(array $source): array
    {
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $tenantId = $this->resolveHotelTenantId($hotelId);
        if ((int)($source['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('Data source tenant scope does not match its hotel.', 409);
        }

        return [$tenantId, $hotelId];
    }

    /** @param array<string, mixed> $source @return array{0:int,1:int} */
    private function assertStoredSourceTenantForActor(array $source, $user): array
    {
        try {
            return $this->assertStoredSourceTenant($source);
        } catch (\Throwable $exception) {
            if (!$user || !method_exists($user, 'isSuperAdmin') || !$user->isSuperAdmin()) {
                throw new RuntimeException('Data source not found.', 404, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $source */
    private function applyStoredSourceIdentity($query, array $source): void
    {
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('Data source identity is missing.', 422);
        }
        [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        $query->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId);
    }

    /** @param array<string, mixed> $source */
    private function applyTaskSourceIdentity($query, array $source, ?int $tenantId = null, ?int $hotelId = null): void
    {
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0) {
            throw new RuntimeException('Data source identity is missing.', 422);
        }
        if ($tenantId === null || $hotelId === null) {
            [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        }
        $query->where('data_source_id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId);
    }

    private function logSync(int $taskId, array $source, string $level, string $event, string $message, array $context = []): void
    {
        $adapterStatus = (string)($context['sync_diagnostics']['adapter_status'] ?? '');
        $message = $this->safeSyncTaskMessage($adapterStatus, $message);
        $context = $this->sanitizeSyncTaskStats($context, $adapterStatus);
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
            $data['tenant_id'] = (int)($source['tenant_id'] ?? 0) ?: null;
        }

        Db::name('platform_data_sync_logs')->insert($data);
    }
}
