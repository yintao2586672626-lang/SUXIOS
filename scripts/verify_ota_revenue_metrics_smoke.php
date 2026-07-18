<?php
declare(strict_types=1);

use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\OnlineDataTrustStatusService;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
$endpoint = '/api/ota-standard/revenue-metrics';
$sourceTable = 'online_daily_data';
$checks = [];
$issues = [];

function add_check(array &$checks, string $code, string $status, string $message, array $details = []): void
{
    $row = [
        'code' => $code,
        'status' => $status,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $checks[] = $row;
}

function add_issue(array &$issues, string $severity, string $code, string $message, array $details = []): void
{
    $row = [
        'severity' => $severity,
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    $issues[] = $row;
}

function parse_options(array $argv): array
{
    $options = [
        'source' => null,
        'data_type' => null,
        'hotel_id' => null,
        'system_hotel_id' => null,
        'start_date' => null,
        'end_date' => null,
        'limit' => 1000,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = $key === 'limit' ? max(1, min(5000, (int)$value)) : trim($value);
    }

    return array_filter($options, static fn($value): bool => $value !== null && $value !== '');
}

function read_project_file(string $relative): string
{
    global $root;
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($path)) {
        return '';
    }
    $source = file_get_contents($path);
    return $source === false ? '' : $source;
}

function db_type(): string
{
    $envType = Env::get('DB_TYPE');
    if (is_string($envType) && $envType !== '') {
        return strtolower($envType);
    }
    return strtolower((string)Config::get('database.default', 'mysql'));
}

function table_exists(string $table, string $dbType): bool
{
    if ($dbType === 'sqlite') {
        return Db::query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            [$table]
        ) !== [];
    }

    return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
}

/**
 * @return array<string, array<string, mixed>>
 */
function table_columns(string $table, string $dbType): array
{
    $columns = [];
    if ($dbType === 'sqlite') {
        foreach (Db::query("PRAGMA table_info({$table})") as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name !== '') {
                $columns[$name] = $row;
            }
        }
        return $columns;
    }

    foreach (Db::query("SHOW COLUMNS FROM `{$table}`") as $row) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') {
            $columns[$name] = $row;
        }
    }
    return $columns;
}

/**
 * @return array<int, array<string, mixed>>
 */
function query_source_rows(array $columns, array $filters): array
{
    $candidateFields = array_values(array_unique(array_merge(
        core_columns(),
        recommended_columns(),
        all_field_group_columns()
    )));
    $fields = array_values(array_intersect($candidateFields, array_keys($columns)));
    $query = Db::name('online_daily_data')->field($fields ?: '*');

    if (isset($columns['readback_verified'])) {
        $query->where('readback_verified', 1);
    }
    if (isset($columns['validation_status'])) {
        $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingValidationStatuses());
        $query->whereRaw("(`validation_status` IS NULL OR LOWER(TRIM(`validation_status`)) NOT IN ({$blocked}))");
    }
    if (isset($columns['status'])) {
        $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingRowStatuses());
        $query->whereRaw("(`status` IS NULL OR LOWER(TRIM(`status`)) NOT IN ({$blocked}))");
    }

    if (!empty($filters['source']) && isset($columns['source'])) {
        $query->where('source', (string)$filters['source']);
    }
    if (!empty($filters['data_type']) && isset($columns['data_type'])) {
        $query->where('data_type', (string)$filters['data_type']);
    }
    if (!empty($filters['hotel_id']) && isset($columns['hotel_id'])) {
        $query->where('hotel_id', (string)$filters['hotel_id']);
    }
    if (!empty($filters['system_hotel_id']) && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
    }
    if (!empty($filters['start_date']) && isset($columns['data_date'])) {
        $query->where('data_date', '>=', (string)$filters['start_date']);
    }
    if (!empty($filters['end_date']) && isset($columns['data_date'])) {
        $query->where('data_date', '<=', (string)$filters['end_date']);
    }

    $limit = (int)($filters['limit'] ?? 1000);
    return $query
        ->order('data_date', 'desc')
        ->order('id', 'desc')
        ->limit(max(1, min(5000, $limit)))
        ->select()
        ->toArray();
}

function core_columns(): array
{
    return [
        'id',
        'hotel_id',
        'data_date',
        'source',
        'amount',
        'quantity',
        'book_order_num',
        'raw_data',
        'data_type',
    ];
}

function recommended_columns(): array
{
    return [
        'system_hotel_id',
        'hotel_name',
        'data_value',
        'dimension',
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
        'validation_status',
        'validation_flags',
        'readback_verified',
        'readback_verified_at',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
    ];
}

function has_bounded_metric_scope(array $filters): bool
{
    foreach (['system_hotel_id', 'hotel_id', 'start_date', 'end_date'] as $field) {
        if (trim((string)($filters[$field] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** @return array<string, mixed> */
function latest_trusted_metric_scope(array $columns, array $filters): array
{
    $fields = array_values(array_intersect([
        'id', 'system_hotel_id', 'hotel_id', 'data_date', 'source', 'data_type', 'update_time', 'create_time',
    ], array_keys($columns)));
    $query = Db::name('online_daily_data')->field($fields ?: '*');

    if (isset($columns['readback_verified'])) {
        $query->where('readback_verified', 1);
    }
    if (isset($columns['validation_status'])) {
        $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingValidationStatuses());
        $query->whereRaw("(`validation_status` IS NULL OR LOWER(TRIM(`validation_status`)) NOT IN ({$blocked}))");
    }
    if (isset($columns['status'])) {
        $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingRowStatuses());
        $query->whereRaw("(`status` IS NULL OR LOWER(TRIM(`status`)) NOT IN ({$blocked}))");
    }
    if (isset($columns['data_date'])) {
        $query->where('data_date', '<=', date('Y-m-d'));
    }
    if (isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', '>', 0);
    }
    if (!empty($filters['source']) && isset($columns['source'])) {
        $query->where('source', (string)$filters['source']);
    }
    if (!empty($filters['data_type']) && isset($columns['data_type'])) {
        $query->where('data_type', (string)$filters['data_type']);
    } elseif (isset($columns['data_type'])) {
        $query->whereIn('data_type', ['business', 'order']);
    }
    if (isset($columns['compare_type'])) {
        $query->whereNotIn('compare_type', ['competitor', 'competitor_avg', 'peer']);
    }

    $query->order('data_date', 'desc');
    if (isset($columns['update_time'])) {
        $query->order('update_time', 'desc');
    } elseif (isset($columns['create_time'])) {
        $query->order('create_time', 'desc');
    }
    if (isset($columns['id'])) {
        $query->order('id', 'desc');
    }
    $row = $query->find();
    if (!is_array($row) || trim((string)($row['data_date'] ?? '')) === '') {
        return [];
    }

    $scope = [
        'start_date' => (string)$row['data_date'],
        'end_date' => (string)$row['data_date'],
    ];
    if ((int)($row['system_hotel_id'] ?? 0) > 0) {
        $scope['system_hotel_id'] = (int)$row['system_hotel_id'];
    } elseif (trim((string)($row['hotel_id'] ?? '')) !== '') {
        $scope['hotel_id'] = (string)$row['hotel_id'];
    }
    return $scope;
}

function field_groups(): array
{
    return [
        'available_room_nights' => [
            'gap_missing' => 'available_room_nights_missing',
            'gap_partial' => 'available_room_nights_partial',
            'columns' => [
                'available_room_nights',
                'availableRoomNights',
                'salable_room_nights',
                'salableRoomNights',
                'available_rooms',
                'availableRooms',
                'salable_rooms',
                'salableRooms',
                'total_rooms_count',
                'totalRoomsCount',
                'rooms_total',
                'roomsTotal',
            ],
        ],
        'commission' => [
            'gap_missing' => 'commission_fields_missing',
            'gap_partial' => 'commission_fields_partial',
            'columns' => [
                'commission_amount',
                'commissionAmount',
                'commission',
                'ota_commission',
                'otaCommission',
                'commission_rate',
                'commissionRate',
                'ota_commission_rate',
                'otaCommissionRate',
            ],
        ],
        'net_revenue' => [
            'gap_missing' => 'net_revenue_fields_missing',
            'gap_partial' => 'net_revenue_fields_partial',
            'columns' => [
                'net_revenue',
                'netRevenue',
                'net_amount',
                'netAmount',
                'after_commission_revenue',
                'afterCommissionRevenue',
                'settlement_amount',
                'settlementAmount',
            ],
        ],
        'cancellation' => [
            'gap_missing' => 'cancellation_fields_missing',
            'gap_partial' => 'cancellation_fields_partial',
            'columns' => [
                'cancel_order_num',
                'cancelOrderNum',
                'cancel_orders',
                'cancelOrders',
                'cancel_rate',
                'cancelRate',
                'cancellation_rate',
                'cancellationRate',
            ],
        ],
        'competitor_price' => [
            'gap_missing' => 'competitor_price_fields_missing',
            'gap_partial' => null,
            'columns' => [
                'our_price',
                'ourPrice',
                'hotel_price',
                'hotelPrice',
                'competitor_price',
                'competitorPrice',
                'market_price',
                'marketPrice',
                'price_gap',
                'priceGap',
                'price_difference',
                'priceDifference',
            ],
        ],
    ];
}

function all_field_group_columns(): array
{
    $columns = [];
    foreach (field_groups() as $group) {
        $columns = array_merge($columns, $group['columns']);
    }
    return array_values(array_unique($columns));
}

function normalized_key(string $key): string
{
    return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $key));
}

/**
 * @return array<string, mixed>
 */
function decode_raw(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<string, bool>
 */
function raw_key_map(mixed $value): array
{
    $keys = [];
    if (!is_array($value)) {
        return $keys;
    }
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[normalized_key($key)] = true;
        }
        if (is_array($child)) {
            $keys += raw_key_map($child);
        }
    }
    return $keys;
}

function row_has_value(array $row, array $columns): bool
{
    foreach ($columns as $column) {
        if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
            return true;
        }
    }
    return false;
}

function row_raw_has_group(array $row, array $groupColumns): bool
{
    $rawKeys = raw_key_map(decode_raw($row['raw_data'] ?? null));
    foreach ($groupColumns as $column) {
        if (isset($rawKeys[normalized_key($column)])) {
            return true;
        }
    }
    return false;
}

/**
 * @return array<string, array<string, mixed>>
 */
function source_field_coverage(array $rows, array $tableColumns): array
{
    $coverage = [];
    foreach (field_groups() as $groupName => $group) {
        $physical = array_values(array_intersect($group['columns'], array_keys($tableColumns)));
        $rowsWithPhysicalValue = 0;
        $rowsWithRawKey = 0;
        foreach ($rows as $row) {
            if (row_has_value($row, $physical)) {
                $rowsWithPhysicalValue++;
            }
            if (row_raw_has_group($row, $group['columns'])) {
                $rowsWithRawKey++;
            }
        }
        $coverage[$groupName] = [
            'physical_columns' => $physical,
            'rows_with_physical_value' => $rowsWithPhysicalValue,
            'rows_with_raw_key' => $rowsWithRawKey,
            'source_rows' => count($rows),
            'gap_missing' => $group['gap_missing'],
            'gap_partial' => $group['gap_partial'],
        ];
    }
    return $coverage;
}

function rows_list(mixed $rows): array
{
    return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
}

function sum_rows(array $rows, string $key): float
{
    return array_reduce($rows, static fn(float $carry, array $row): float => $carry + (float)($row[$key] ?? 0), 0.0);
}

function sum_rows_with_fallback(array $rows, string $key, string $fallback): float
{
    return array_reduce($rows, static function (float $carry, array $row) use ($key, $fallback): float {
        return $carry + (float)(has_numeric_value($row, $key) ? $row[$key] : ($row[$fallback] ?? 0));
    }, 0.0);
}

function has_numeric_value(array $row, string $key): bool
{
    return array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key]);
}

function metric_at(array $data, string $path): mixed
{
    $current = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }
    return $current;
}

function assert_metric(array &$issues, array $metrics, string $path, mixed $expected, string $code): void
{
    $actual = metric_at($metrics, $path);
    if ($expected === null) {
        if ($actual !== null) {
            add_issue($issues, 'error', $code, "Metric {$path} must stay null when source evidence is missing.", [
                'expected' => null,
                'actual' => $actual,
            ]);
        }
        return;
    }

    if (!is_numeric($actual) || abs(round((float)$actual, 2) - round((float)$expected, 2)) > 0.01) {
        add_issue($issues, 'error', $code, "Metric {$path} does not match the independently recalculated caliber.", [
            'expected' => round((float)$expected, 2),
            'actual' => $actual,
        ]);
    }
}

function require_gap(array &$issues, array $gapCodes, string $gapCode, string $message): void
{
    if (!in_array($gapCode, $gapCodes, true)) {
        add_issue($issues, 'error', 'caliber_mismatch', $message, ['required_gap_code' => $gapCode]);
    }
}

function expected_metrics(array $daily): array
{
    $revenue = sum_rows($daily, 'revenue');
    $roomRevenue = sum_rows_with_fallback($daily, 'room_revenue', 'revenue');
    $roomNights = sum_rows($daily, 'room_nights');
    $availableRows = array_values(array_filter($daily, static fn(array $row): bool => has_numeric_value($row, 'available_room_nights') && (float)$row['available_room_nights'] > 0));
    $availableRoomNights = sum_rows($availableRows, 'available_room_nights');
    $revparRoomRevenue = sum_rows_with_fallback($availableRows, 'room_revenue', 'revenue');
    $occupancyRows = array_values(array_filter($daily, static function (array $row): bool {
        return has_numeric_value($row, 'available_room_nights')
            && (float)$row['available_room_nights'] > 0
            && has_numeric_value($row, 'occupied_room_nights');
    }));
    $occupiedRoomNights = sum_rows($occupancyRows, 'occupied_room_nights');
    $occupancyAvailableRoomNights = sum_rows($occupancyRows, 'available_room_nights');
    $commissionRows = array_values(array_filter($daily, static fn(array $row): bool => has_numeric_value($row, 'commission_amount')));
    $commissionAmount = sum_rows($commissionRows, 'commission_amount');
    $commissionGrossRevenue = sum_rows_with_fallback($commissionRows, 'gross_revenue', 'revenue');
    $netRows = array_values(array_filter($daily, static fn(array $row): bool => has_numeric_value($row, 'net_revenue')));
    $netRevenue = sum_rows($netRows, 'net_revenue');
    $netRevparRows = array_values(array_filter($daily, static function (array $row): bool {
        return has_numeric_value($row, 'net_revenue')
            && has_numeric_value($row, 'available_room_nights')
            && (float)$row['available_room_nights'] > 0;
    }));
    $netRevparNetRevenue = sum_rows($netRevparRows, 'net_revenue');
    $netRevparAvailableRoomNights = sum_rows($netRevparRows, 'available_room_nights');

    return [
        'revenue' => round($revenue, 2),
        'room_revenue' => round($roomRevenue, 2),
        'room_nights' => round($roomNights, 2),
        'available_room_nights' => $availableRows ? round($availableRoomNights, 2) : null,
        'occupied_room_nights' => $occupancyRows ? round($occupiedRoomNights, 2) : null,
        'adr' => $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
        'occ' => $occupancyRows && $occupancyAvailableRoomNights > 0 ? round($occupiedRoomNights / $occupancyAvailableRoomNights * 100, 2) : null,
        'revpar' => $availableRows && $availableRoomNights > 0 ? round($revparRoomRevenue / $availableRoomNights, 2) : null,
        'commission_amount' => $commissionRows ? round($commissionAmount, 2) : null,
        'commission_rate' => $commissionRows && $commissionGrossRevenue > 0 ? round($commissionAmount / $commissionGrossRevenue * 100, 2) : null,
        'net_revenue' => $netRows ? round($netRevenue, 2) : null,
        'net_revpar' => $netRevparRows && $netRevparAvailableRoomNights > 0 ? round($netRevparNetRevenue / $netRevparAvailableRoomNights, 2) : null,
        'available_rows' => count($availableRows),
        'occupancy_rows' => count($occupancyRows),
        'commission_rows' => count($commissionRows),
        'net_rows' => count($netRows),
    ];
}

$filters = parse_options($argv);
$result = [
    'endpoint' => $endpoint,
    'source_table' => $sourceTable,
    'filters' => $filters,
    'dependencies' => [
        'route' => 'route/app.php',
        'controller' => 'app/controller/OtaStandard.php::revenueMetrics',
        'etl_service' => OtaStandardEtlService::class . '::buildDataset',
        'metric_service' => OtaRevenueMetricService::class . '::summarizeDataset',
    ],
    'checks' => &$checks,
    'facts' => [],
    'issues' => &$issues,
];

try {
    $routeSource = read_project_file('route/app.php');
    if (
        str_contains($routeSource, "Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics')")
        && str_contains($routeSource, "Route::post('/revenue-metrics', 'OtaStandard/revenueMetrics')")
    ) {
        add_check($checks, 'route_registered', 'ok', "{$endpoint} is registered for GET and POST.");
    } else {
        add_issue($issues, 'error', 'route_missing', "{$endpoint} route is not registered for both GET and POST.");
    }

    if (class_exists(OtaStandardEtlService::class) && method_exists(OtaStandardEtlService::class, 'buildDataset')) {
        add_check($checks, 'etl_service_present', 'ok', 'OtaStandardEtlService::buildDataset is available.');
    } else {
        add_issue($issues, 'error', 'service_missing', 'OtaStandardEtlService::buildDataset is not available.');
    }

    if (class_exists(OtaRevenueMetricService::class) && method_exists(OtaRevenueMetricService::class, 'summarizeDataset')) {
        add_check($checks, 'metric_service_present', 'ok', 'OtaRevenueMetricService::summarizeDataset is available.');
    } else {
        add_issue($issues, 'error', 'service_missing', 'OtaRevenueMetricService::summarizeDataset is not available.');
    }

    $app = new App();
    $app->initialize();
    add_check($checks, 'app_bootstrap', 'ok', 'ThinkPHP application initialized.');

    $dbType = db_type();
    $result['facts']['db_type'] = $dbType;
    if (!table_exists($sourceTable, $dbType)) {
        add_issue($issues, 'error', 'missing_table', "{$sourceTable} table does not exist.");
        throw new RuntimeException('Cannot continue without source table.');
    }
    add_check($checks, 'source_table_present', 'ok', "{$sourceTable} exists.");

    $columns = table_columns($sourceTable, $dbType);
    $result['facts']['column_count'] = count($columns);
    $missingCore = array_values(array_diff(core_columns(), array_keys($columns)));
    if ($missingCore !== []) {
        add_issue($issues, 'error', 'missing_column', "{$sourceTable} is missing required revenue metric columns.", [
            'missing_columns' => $missingCore,
        ]);
        throw new RuntimeException('Cannot continue without required columns.');
    }
    add_check($checks, 'required_columns_present', 'ok', 'Core revenue source columns are present.', [
        'columns' => core_columns(),
    ]);

    $missingRecommended = array_values(array_diff(recommended_columns(), array_keys($columns)));
    if ($missingRecommended !== []) {
        add_issue($issues, 'warning', 'missing_recommended_column', "{$sourceTable} is missing non-blocking trace or enrichment columns.", [
            'missing_columns' => $missingRecommended,
        ]);
    }

    if (!has_bounded_metric_scope($filters)) {
        $autoScope = latest_trusted_metric_scope($columns, $filters);
        if ($autoScope === []) {
            add_issue($issues, 'error', 'bounded_scope_missing', 'No trusted hotel/date scope is available for the default smoke run.');
            throw new RuntimeException('Cannot run an unbounded revenue metric smoke.');
        }
        $filters = array_merge($filters, $autoScope);
        $result['filters'] = $filters;
        $result['facts']['auto_scope'] = $autoScope;
        add_check($checks, 'bounded_scope_selected', 'ok', 'Default smoke automatically selected the latest trusted hotel/date scope.', $autoScope);
    }

    $sourceRows = query_source_rows($columns, $filters);
    $result['facts']['source_rows_sampled'] = count($sourceRows);
    if ($sourceRows === []) {
        add_issue($issues, 'error', 'empty_data', "{$sourceTable} has no rows for the requested revenue metric scope.", [
            'filters' => $filters,
        ]);
        throw new RuntimeException('Cannot continue without source rows.');
    }
    add_check($checks, 'source_rows_present', 'ok', 'Source rows exist for the requested scope.', [
        'sampled_rows' => count($sourceRows),
    ]);

    $coverage = source_field_coverage($sourceRows, $columns);
    $result['facts']['source_field_coverage'] = $coverage;
    foreach ($coverage as $groupName => $groupCoverage) {
        if ($groupCoverage['physical_columns'] === [] && $groupCoverage['rows_with_raw_key'] === 0) {
            add_issue($issues, 'warning', 'missing_field_group', "No source field evidence found for {$groupName}.", [
                'group' => $groupName,
                'expected_gap_code' => $groupCoverage['gap_missing'],
            ]);
        } elseif ($groupCoverage['rows_with_physical_value'] === 0 && $groupCoverage['rows_with_raw_key'] === 0) {
            add_issue($issues, 'warning', 'empty_field_group', "Source field group {$groupName} exists but has no values in sampled rows.", [
                'group' => $groupName,
                'expected_gap_code' => $groupCoverage['gap_missing'],
            ]);
        }
    }

    $dataset = (new OtaStandardEtlService())->buildDataset($filters);
    $daily = rows_list($dataset['fact_ota_daily'] ?? []);
    $traffic = rows_list($dataset['fact_ota_traffic'] ?? []);
    $advertising = rows_list($dataset['fact_ota_advertising'] ?? []);
    $quality = rows_list($dataset['fact_ota_quality'] ?? []);
    $result['facts']['etl'] = [
        'status' => $dataset['status'] ?? null,
        'accepted_rows' => $dataset['data_quality']['accepted_rows'] ?? null,
        'daily_facts' => count($daily),
        'traffic_facts' => count($traffic),
        'advertising_facts' => count($advertising),
        'quality_facts' => count($quality),
        'rejected_rows' => count($dataset['data_quality']['rejected_rows'] ?? []),
    ];
    if (($dataset['status'] ?? '') !== 'ready') {
        add_issue($issues, 'error', 'empty_data', 'ETL dataset is not ready for the requested scope.', [
            'etl_status' => $dataset['status'] ?? null,
            'data_quality' => $dataset['data_quality'] ?? [],
        ]);
        throw new RuntimeException('Cannot continue without ready ETL dataset.');
    }
    if ($daily === []) {
        add_issue($issues, 'error', 'empty_revenue_facts', 'ETL produced no fact_ota_daily rows; revenue metrics would be a non-revenue smoke.');
    }

    $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
    $expected = expected_metrics($daily);
    $result['facts']['metrics'] = [
        'status' => $metrics['status'] ?? null,
        'fact_table' => $metrics['fact_table'] ?? null,
        'totals' => $metrics['totals'] ?? null,
        'data_gap_codes' => array_values(array_filter(array_column($metrics['data_gaps'] ?? [], 'code'), 'is_string')),
    ];

    if (($metrics['fact_table']['name'] ?? '') !== 'fact_ota_daily' || ($metrics['fact_table']['source_table'] ?? '') !== $sourceTable) {
        add_issue($issues, 'error', 'caliber_mismatch', 'Metric output is not anchored to fact_ota_daily from online_daily_data.', [
            'fact_table' => $metrics['fact_table'] ?? null,
        ]);
    }
    foreach ($daily as $index => $fact) {
        if (($fact['metric_scope'] ?? null) !== 'ota_channel') {
            add_issue($issues, 'error', 'caliber_mismatch', 'Daily fact metric_scope must remain ota_channel.', [
                'index' => $index,
                'metric_scope' => $fact['metric_scope'] ?? null,
            ]);
        }
    }

    assert_metric($issues, $metrics, 'totals.revenue', $expected['revenue'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.room_revenue', $expected['room_revenue'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.room_nights', $expected['room_nights'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.available_room_nights', $expected['available_room_nights'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.occupied_room_nights', $expected['occupied_room_nights'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.adr', $expected['adr'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.occ', $expected['occ'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.revpar', $expected['revpar'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.commission_amount', $expected['commission_amount'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.commission_rate', $expected['commission_rate'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.net_revenue', $expected['net_revenue'], 'caliber_mismatch');
    assert_metric($issues, $metrics, 'totals.net_revpar', $expected['net_revpar'], 'caliber_mismatch');

    $gapCodes = $result['facts']['metrics']['data_gap_codes'];
    if (count($daily) > 0 && $expected['available_rows'] === 0) {
        require_gap($issues, $gapCodes, 'available_room_nights_missing', 'Missing available room nights must be exposed through data_gaps.');
    } elseif ($expected['available_rows'] > 0 && $expected['available_rows'] < count($daily)) {
        require_gap($issues, $gapCodes, 'available_room_nights_partial', 'Partial available room nights must be exposed through data_gaps.');
    }
    if (count($daily) > 0 && $expected['commission_rows'] === 0) {
        require_gap($issues, $gapCodes, 'commission_fields_missing', 'Missing commission fields must be exposed through data_gaps.');
    } elseif ($expected['commission_rows'] > 0 && $expected['commission_rows'] < count($daily)) {
        require_gap($issues, $gapCodes, 'commission_fields_partial', 'Partial commission fields must be exposed through data_gaps.');
    }
    if (count($daily) > 0 && $expected['net_rows'] === 0) {
        require_gap($issues, $gapCodes, 'net_revenue_fields_missing', 'Missing net revenue fields must be exposed through data_gaps.');
    } elseif ($expected['net_rows'] > 0 && $expected['net_rows'] < count($daily)) {
        require_gap($issues, $gapCodes, 'net_revenue_fields_partial', 'Partial net revenue fields must be exposed through data_gaps.');
    }

    $currentErrors = count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'error'));
    if ($currentErrors === 0) {
        add_check($checks, 'revenue_metric_smoke', 'ok', 'Revenue metrics are traceable to live source rows and service caliber.');
    }
} catch (Throwable $e) {
    if ($issues === []) {
        add_issue($issues, 'error', 'verifier_runtime_error', $e->getMessage());
    } elseif (!in_array($e->getMessage(), [
        'Cannot continue without source table.',
        'Cannot continue without required columns.',
        'Cannot continue without source rows.',
        'Cannot continue without ready ETL dataset.',
    ], true)) {
        add_issue($issues, 'error', 'verifier_runtime_error', $e->getMessage());
    }
}

$errorCount = count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'error'));
$warningCount = count(array_filter($issues, static fn(array $issue): bool => ($issue['severity'] ?? '') === 'warning'));
$result['status'] = $errorCount > 0 ? 'failed' : 'passed';
$result['summary'] = [
    'errors' => $errorCount,
    'warnings' => $warningCount,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($errorCount > 0 ? 1 : 0);
