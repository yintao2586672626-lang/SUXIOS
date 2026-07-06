<?php
declare(strict_types=1);

use app\service\RevenueAiOverviewService;
use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,limit:int}
 */
function ctrip_pricing_sources_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'limit' => '200',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (array_key_exists($key, $options)) {
            $options[$key] = trim($value);
        }
    }

    foreach (['business-date', 'business_date'] as $dateKey) {
        if ($options[$dateKey] !== '') {
            $options['date'] = $options[$dateKey];
        }
    }
    if ($options['hotel-id'] === '' && $options['hotel_id'] !== '') {
        $options['hotel-id'] = $options['hotel_id'];
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['date'])) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $hotelId = null;
    if ($options['hotel-id'] !== '') {
        if (!ctype_digit($options['hotel-id']) || (int)$options['hotel-id'] <= 0) {
            throw new InvalidArgumentException('Invalid --hotel-id, expected a positive integer.');
        }
        $hotelId = (int)$options['hotel-id'];
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'limit' => max(1, min(1000, (int)$options['limit'])),
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_sources_finish(array $payload, int $exitCode): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, bool>
 */
function ctrip_pricing_sources_table_columns(string $table): array
{
    $columns = [];
    foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }
    return $columns;
}

function ctrip_pricing_sources_table_exists(string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    try {
        return Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'") !== [];
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_map(mixed $value): array
{
    return is_array($value) ? $value : [];
}

/**
 * @param mixed $value
 * @return array<int, mixed>
 */
function ctrip_pricing_sources_list(mixed $value): array
{
    return is_array($value) ? array_values($value) : [];
}

/**
 * @param array<int, mixed> $values
 * @return array<int, int>
 */
function ctrip_pricing_sources_positive_ints(array $values): array
{
    return array_values(array_filter(
        array_map('intval', $values),
        static fn(int $value): bool => $value > 0
    ));
}

/**
 * @return array<int, string>
 */
function ctrip_pricing_sources_source_aliases(): array
{
    return ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'];
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_decode_raw(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }
    $text = trim((string)($raw ?? ''));
    if ($text === '') {
        return [];
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<int, mixed>
 */
function ctrip_pricing_sources_field_facts(array $raw): array
{
    foreach ([
        $raw['facts'] ?? null,
        $raw['field_facts'] ?? null,
        $raw['raw_data']['facts'] ?? null,
        $raw['raw_data']['field_facts'] ?? null,
        $raw['row']['facts'] ?? null,
        $raw['row']['field_facts'] ?? null,
        $raw['row']['raw_data']['facts'] ?? null,
        $raw['row']['raw_data']['field_facts'] ?? null,
    ] as $candidate) {
        $rows = ctrip_pricing_sources_list($candidate);
        if ($rows !== []) {
            return $rows;
        }
    }

    return [];
}

/**
 * @param array<string, int> $counts
 */
function ctrip_pricing_sources_count(array &$counts, string $key): void
{
    if ($key === '') {
        return;
    }
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}

/**
 * @param array<string, int> $counts
 * @return array<int, array{key:string,count:int}>
 */
function ctrip_pricing_sources_top_counts(array $counts, int $limit = 40): array
{
    arsort($counts);
    $rows = [];
    foreach (array_slice($counts, 0, $limit, true) as $key => $count) {
        $rows[] = ['key' => (string)$key, 'count' => (int)$count];
    }
    return $rows;
}

/**
 * @param array<string, int> $counts
 * @param array<string, bool> $samples
 */
function ctrip_pricing_sources_scan_raw_keys(mixed $value, string $path, array &$counts, array &$samples, int $depth = 0): void
{
    if ($depth > 8 || !is_array($value)) {
        return;
    }
    foreach ($value as $key => $child) {
        $keyText = (string)$key;
        $nextPath = $path === '$' ? '$.' . $keyText : $path . '.' . $keyText;
        ctrip_pricing_sources_count($counts, $keyText);
        $lower = strtolower($keyText . ' ' . $nextPath);
        foreach (['room', 'room_type', 'base_price', 'min_price', 'floor', 'price', 'rate', 'competitor', 'demand', 'forecast'] as $needle) {
            if (str_contains($lower, $needle)) {
                $samples[$nextPath] = true;
                break;
            }
        }
        if (is_array($child)) {
            ctrip_pricing_sources_scan_raw_keys($child, $nextPath, $counts, $samples, $depth + 1);
        }
    }
}

/**
 * @param list<string> $needles
 * @param list<string> $paths
 */
function ctrip_pricing_sources_collect_matching_paths(mixed $value, string $path, array $needles, array &$paths, int $depth = 0): void
{
    if ($depth > 8 || !is_array($value) || count($paths) >= 8) {
        return;
    }
    foreach ($value as $key => $child) {
        if (count($paths) >= 8) {
            return;
        }
        $keyText = (string)$key;
        $nextPath = $path === '$' ? '$.' . $keyText : $path . '.' . $keyText;
        $haystack = strtolower($keyText . ' ' . $nextPath);
        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                $paths[] = $nextPath;
                break;
            }
        }
        if (is_array($child)) {
            ctrip_pricing_sources_collect_matching_paths($child, $nextPath, $needles, $paths, $depth + 1);
        }
    }
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_locator_row(array $row, array $matchedPaths): array
{
    return array_filter([
        'id' => $row['id'] ?? null,
        'data_date' => $row['data_date'] ?? null,
        'source' => $row['source'] ?? null,
        'data_type' => $row['data_type'] ?? null,
        'dimension' => $row['dimension'] ?? null,
        'system_hotel_id' => $row['system_hotel_id'] ?? null,
        'data_source_id' => $row['data_source_id'] ?? null,
        'sync_task_id' => $row['sync_task_id'] ?? null,
        'ingestion_method' => $row['ingestion_method'] ?? null,
        'validation_status' => $row['validation_status'] ?? null,
        'source_trace_id' => $row['source_trace_id'] ?? null,
        'matched_path_count' => count($matchedPaths),
        'matched_paths' => array_values(array_unique(array_slice($matchedPaths, 0, 8))),
    ], static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== []);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_operator_input_locators(array $rows): array
{
    $categories = [
        'room_types_enabled' => [
            'needles' => ['room', 'room_type', 'roomtype', 'roomname', 'productname', 'rateplan', '房型', 'top_hot_room'],
            'operator_use' => 'Use these metadata rows to locate Ctrip room type/rate-plan evidence; confirm values manually before filling room_types.',
        ],
        'floor_price_or_min_rate_guard' => [
            'needles' => ['price', 'rate', 'base_price', 'min_price', 'floor', 'bottom', 'avg_price', '价格', 'rateplan'],
            'operator_use' => 'Use these metadata rows to locate Ctrip price/rate evidence; do not infer floor guards from averages alone.',
        ],
        'demand_forecast' => [
            'needles' => ['demand', 'forecast', 'predicted', '预测'],
            'operator_use' => 'No Ctrip field locator proves a demand forecast by itself; provide an operator/model forecast source.',
        ],
        'competitor_price_samples' => [
            'needles' => ['competitor', 'peer', '竞对', '竞争'],
            'operator_use' => 'Use these metadata rows to locate Ctrip competitor comparison evidence; confirm competitor_name, our_price, and competitor_price manually.',
        ],
    ];

    $locators = [];
    foreach ($categories as $code => $config) {
        $items = [];
        foreach ($rows as $row) {
            $raw = ctrip_pricing_sources_decode_raw($row['raw_data'] ?? null);
            $matchedPaths = [];
            ctrip_pricing_sources_collect_matching_paths($raw, '$', $config['needles'], $matchedPaths);
            $dimensionText = strtolower((string)($row['data_type'] ?? '') . ' ' . (string)($row['dimension'] ?? ''));
            foreach ($config['needles'] as $needle) {
                if (str_contains($dimensionText, strtolower($needle))) {
                    $matchedPaths[] = '$.dimension';
                    break;
                }
            }
            $matchedPaths = array_values(array_unique($matchedPaths));
            if ($matchedPaths === []) {
                continue;
            }
            $items[] = ctrip_pricing_sources_locator_row($row, $matchedPaths);
            if (count($items) >= 5) {
                break;
            }
        }
        $locators[$code] = [
            'status' => $items === [] ? 'no_metadata_locator' : 'metadata_locator_available',
            'raw_values_exposed' => false,
            'database_written' => false,
            'locator_count' => count($items),
            'operator_use' => $config['operator_use'],
            'locators' => $items,
        ];
    }

    return [
        'source_policy' => 'metadata_locators_only_no_raw_values_no_import',
        'raw_values_exposed' => false,
        'database_written' => false,
        'items' => $locators,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_sources_query_rows(array $columns, string $businessDate, ?int $hotelId, int $limit): array
{
    $fields = array_values(array_intersect([
        'id',
        'system_hotel_id',
        'hotel_id',
        'hotel_name',
        'source',
        'data_date',
        'data_type',
        'dimension',
        'amount',
        'quantity',
        'book_order_num',
        'data_value',
        'validation_status',
        'validation_flags',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
        'raw_data',
        'create_time',
        'update_time',
    ], array_keys($columns)));

    $query = Db::name('online_daily_data')->field(implode(',', $fields));
    if (isset($columns['source'])) {
        $query->whereIn('source', ctrip_pricing_sources_source_aliases());
    }
    if (isset($columns['data_date'])) {
        $query->where('data_date', $businessDate);
    }
    if ($hotelId !== null && isset($columns['system_hotel_id'])) {
        $query->where('system_hotel_id', $hotelId);
    }

    return $query->order('id', 'desc')->limit($limit)->select()->toArray();
}

/**
 * @param array<int, mixed> $params
 */
function ctrip_pricing_sources_scalar_count(string $sql, array $params = []): int
{
    try {
        $rows = Db::query($sql, $params);
        $first = is_array($rows[0] ?? null) ? array_values($rows[0])[0] ?? 0 : 0;

        return max(0, (int)$first);
    } catch (Throwable) {
        return 0;
    }
}

/**
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_candidate_source_audit(string $businessDate, ?int $hotelId): array
{
    $hotelWhere = $hotelId === null ? '' : ' AND hotel_id = ?';
    $hotelParams = $hotelId === null ? [] : [$hotelId];
    $systemHotelWhere = $hotelId === null ? '' : ' AND system_hotel_id = ?';
    $systemHotelParams = $hotelId === null ? [] : [$hotelId];

    $roomLikeOnlineSql = "SELECT COUNT(*) FROM online_daily_data WHERE LOWER(source) LIKE ?"
        . $systemHotelWhere
        . " AND (LOWER(data_type) LIKE ? OR LOWER(dimension) LIKE ? OR dimension LIKE ? OR LOWER(raw_data) LIKE ? OR raw_data LIKE ?)";
    $roomLikeOnlineParams = array_merge(['%ctrip%'], $systemHotelParams, ['%room%', '%room%', '%房%', '%room%', '%房%']);

    $targetRoomLikeOnlineSql = "SELECT COUNT(*) FROM online_daily_data WHERE data_date = ? AND LOWER(source) LIKE ?"
        . $systemHotelWhere
        . " AND (LOWER(data_type) LIKE ? OR LOWER(dimension) LIKE ? OR dimension LIKE ? OR LOWER(raw_data) LIKE ? OR raw_data LIKE ?)";
    $targetRoomLikeOnlineParams = array_merge([$businessDate, '%ctrip%'], $systemHotelParams, ['%room%', '%room%', '%房%', '%room%', '%房%']);

    $roomLikeFactsSql = "SELECT COUNT(*) FROM ota_ctrip_metric_facts WHERE 1=1"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(data_type) LIKE ? OR LOWER(metric_key) LIKE ? OR metric_label LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(source_path) LIKE ? OR LOWER(source_key) LIKE ?)";
    $roomLikeFactsParams = array_merge($systemHotelParams, ['%room%', '%room%', '%room%', '%房%', '%room%', '%房%', '%room%', '%room%']);

    $targetRoomLikeFactsSql = "SELECT COUNT(*) FROM ota_ctrip_metric_facts WHERE data_date = ?"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(data_type) LIKE ? OR LOWER(metric_key) LIKE ? OR metric_label LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(source_path) LIKE ? OR LOWER(source_key) LIKE ?)";
    $targetRoomLikeFactsParams = array_merge([$businessDate], $systemHotelParams, ['%room%', '%room%', '%room%', '%房%', '%room%', '%房%', '%room%', '%room%']);

    $roomLikeSnapshotsSql = "SELECT COUNT(*) FROM ota_ctrip_entity_snapshots WHERE 1=1"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(attributes_json) LIKE ? OR attributes_json LIKE ?)";
    $roomLikeSnapshotsParams = array_merge($systemHotelParams, ['%room%', '%room%', '%房%', '%room%', '%房%']);

    $targetRoomLikeSnapshotsSql = "SELECT COUNT(*) FROM ota_ctrip_entity_snapshots WHERE data_date = ?"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(attributes_json) LIKE ? OR attributes_json LIKE ?)";
    $targetRoomLikeSnapshotsParams = array_merge([$businessDate], $systemHotelParams, ['%room%', '%room%', '%房%', '%room%', '%房%']);

    $roomTypeNeedles = ['%room_type%', '%roomtype%', '%roomname%', '%productname%', '%rateplan%', '%房型%'];
    $priceGuardNeedles = ['%base_price%', '%min_price%', '%floor_price%', '%bottom_price%', '%room_price%', '%avg_price%', '%rateplan%', '%价格%'];
    $onlineNeedleSql = "SELECT COUNT(*) FROM online_daily_data WHERE LOWER(source) LIKE ?"
        . $systemHotelWhere
        . " AND (LOWER(data_type) LIKE ? OR LOWER(dimension) LIKE ? OR dimension LIKE ? OR LOWER(raw_data) LIKE ? OR raw_data LIKE ? OR LOWER(raw_data) LIKE ?)";
    $targetOnlineNeedleSql = "SELECT COUNT(*) FROM online_daily_data WHERE data_date = ? AND LOWER(source) LIKE ?"
        . $systemHotelWhere
        . " AND (LOWER(data_type) LIKE ? OR LOWER(dimension) LIKE ? OR dimension LIKE ? OR LOWER(raw_data) LIKE ? OR raw_data LIKE ? OR LOWER(raw_data) LIKE ?)";
    $factsNeedleSql = "SELECT COUNT(*) FROM ota_ctrip_metric_facts WHERE 1=1"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(data_type) LIKE ? OR LOWER(metric_key) LIKE ? OR metric_label LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(source_path) LIKE ? OR LOWER(source_key) LIKE ? OR LOWER(raw_data) LIKE ?)";
    $targetFactsNeedleSql = "SELECT COUNT(*) FROM ota_ctrip_metric_facts WHERE data_date = ?"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(data_type) LIKE ? OR LOWER(metric_key) LIKE ? OR metric_label LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(source_path) LIKE ? OR LOWER(source_key) LIKE ? OR LOWER(raw_data) LIKE ?)";
    $snapshotsNeedleSql = "SELECT COUNT(*) FROM ota_ctrip_entity_snapshots WHERE 1=1"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(attributes_json) LIKE ? OR attributes_json LIKE ? OR LOWER(attributes_json) LIKE ?)";
    $targetSnapshotsNeedleSql = "SELECT COUNT(*) FROM ota_ctrip_entity_snapshots WHERE data_date = ?"
        . $systemHotelWhere
        . " AND (LOWER(capture_section) LIKE ? OR LOWER(entity_type) LIKE ? OR entity_name LIKE ? OR LOWER(attributes_json) LIKE ? OR attributes_json LIKE ? OR LOWER(attributes_json) LIKE ?)";

    $countOnlineNeedle = static function (string $needle, bool $targetDate) use (
        $businessDate,
        $onlineNeedleSql,
        $targetOnlineNeedleSql,
        $systemHotelParams
    ): int {
        $params = array_merge(['%ctrip%'], $systemHotelParams, [$needle, $needle, $needle, $needle, $needle, $needle]);
        if ($targetDate) {
            $params = array_merge([$businessDate], $params);
        }
        return ctrip_pricing_sources_scalar_count($targetDate ? $targetOnlineNeedleSql : $onlineNeedleSql, $params);
    };
    $countFactsNeedle = static function (string $needle, bool $targetDate) use (
        $businessDate,
        $factsNeedleSql,
        $targetFactsNeedleSql,
        $systemHotelParams
    ): int {
        $params = array_merge($systemHotelParams, [$needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle, $needle]);
        if ($targetDate) {
            $params = array_merge([$businessDate], $params);
        }
        return ctrip_pricing_sources_scalar_count($targetDate ? $targetFactsNeedleSql : $factsNeedleSql, $params);
    };
    $countSnapshotsNeedle = static function (string $needle, bool $targetDate) use (
        $businessDate,
        $snapshotsNeedleSql,
        $targetSnapshotsNeedleSql,
        $systemHotelParams
    ): int {
        $params = array_merge($systemHotelParams, [$needle, $needle, $needle, $needle, $needle, $needle]);
        if ($targetDate) {
            $params = array_merge([$businessDate], $params);
        }
        return ctrip_pricing_sources_scalar_count($targetDate ? $targetSnapshotsNeedleSql : $snapshotsNeedleSql, $params);
    };
    $sumNeedles = static function (array $needles, callable $counter, bool $targetDate): int {
        $count = 0;
        foreach ($needles as $needle) {
            $count += $counter($needle, $targetDate);
        }
        return $count;
    };

    $roomTypes = ctrip_pricing_sources_scalar_count('SELECT COUNT(*) FROM room_types WHERE is_enabled = 1' . $hotelWhere, $hotelParams);
    $roomTypesWithPriceGuards = ctrip_pricing_sources_scalar_count(
        'SELECT COUNT(*) FROM room_types WHERE is_enabled = 1 AND base_price > 0 AND min_price > 0 AND max_price >= base_price' . $hotelWhere,
        $hotelParams
    );
    $demandForecasts = ctrip_pricing_sources_scalar_count(
        'SELECT COUNT(*) FROM demand_forecasts WHERE forecast_date = ?' . $hotelWhere,
        array_merge([$businessDate], $hotelParams)
    );
    $ctripCompetitorSamples = ctrip_pricing_sources_scalar_count(
        'SELECT COUNT(*) FROM competitor_analysis WHERE analysis_date >= ? AND ota_platform = 1' . $hotelWhere,
        array_merge([date('Y-m-d', strtotime($businessDate . ' -7 days'))], $hotelParams)
    );

    $requiredTablesReady = $roomTypes > 0
        && $roomTypesWithPriceGuards > 0
        && $demandForecasts > 0
        && $ctripCompetitorSamples > 0;

    return [
        'source_policy' => 'metadata_counts_only_no_raw_values_no_import',
        'raw_values_exposed' => false,
        'database_written' => false,
        'required_input_table_counts' => [
            'room_types_enabled' => $roomTypes,
            'room_types_with_price_guards' => $roomTypesWithPriceGuards,
            'demand_forecasts_target_date' => $demandForecasts,
            'competitor_analysis_ctrip_recent_7d' => $ctripCompetitorSamples,
        ],
        'ctrip_room_pricing_evidence_counts' => [
            'online_daily_data_room_like_target_date' => ctrip_pricing_sources_scalar_count($targetRoomLikeOnlineSql, $targetRoomLikeOnlineParams),
            'online_daily_data_room_like_all_dates' => ctrip_pricing_sources_scalar_count($roomLikeOnlineSql, $roomLikeOnlineParams),
            'online_daily_data_room_type_key_target_date' => $sumNeedles($roomTypeNeedles, $countOnlineNeedle, true),
            'online_daily_data_room_type_key_all_dates' => $sumNeedles($roomTypeNeedles, $countOnlineNeedle, false),
            'online_daily_data_price_guard_key_target_date' => $sumNeedles($priceGuardNeedles, $countOnlineNeedle, true),
            'online_daily_data_price_guard_key_all_dates' => $sumNeedles($priceGuardNeedles, $countOnlineNeedle, false),
            'ota_ctrip_metric_facts_room_like_target_date' => ctrip_pricing_sources_scalar_count($targetRoomLikeFactsSql, $targetRoomLikeFactsParams),
            'ota_ctrip_metric_facts_room_like_all_dates' => ctrip_pricing_sources_scalar_count($roomLikeFactsSql, $roomLikeFactsParams),
            'ota_ctrip_metric_facts_room_type_key_target_date' => $sumNeedles($roomTypeNeedles, $countFactsNeedle, true),
            'ota_ctrip_metric_facts_room_type_key_all_dates' => $sumNeedles($roomTypeNeedles, $countFactsNeedle, false),
            'ota_ctrip_metric_facts_price_guard_key_target_date' => $sumNeedles($priceGuardNeedles, $countFactsNeedle, true),
            'ota_ctrip_metric_facts_price_guard_key_all_dates' => $sumNeedles($priceGuardNeedles, $countFactsNeedle, false),
            'ota_ctrip_entity_snapshots_room_like_target_date' => ctrip_pricing_sources_scalar_count($targetRoomLikeSnapshotsSql, $targetRoomLikeSnapshotsParams),
            'ota_ctrip_entity_snapshots_room_like_all_dates' => ctrip_pricing_sources_scalar_count($roomLikeSnapshotsSql, $roomLikeSnapshotsParams),
            'ota_ctrip_entity_snapshots_room_type_key_target_date' => $sumNeedles($roomTypeNeedles, $countSnapshotsNeedle, true),
            'ota_ctrip_entity_snapshots_room_type_key_all_dates' => $sumNeedles($roomTypeNeedles, $countSnapshotsNeedle, false),
            'ota_ctrip_entity_snapshots_price_guard_key_target_date' => $sumNeedles($priceGuardNeedles, $countSnapshotsNeedle, true),
            'ota_ctrip_entity_snapshots_price_guard_key_all_dates' => $sumNeedles($priceGuardNeedles, $countSnapshotsNeedle, false),
        ],
        'prefill_eligibility' => [
            'status' => $requiredTablesReady ? 'ready_from_required_tables' : 'blocked_by_missing_required_tables',
            'can_generate_operator_file_from_existing_db' => false,
            'reason' => $requiredTablesReady
                ? 'required_tables_have_counts_but_operator_file_still_requires_explicit_review'
                : 'required_tables_or_ctrip_room_price_evidence_missing',
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_rows_summary(array $rows): array
{
    $dataTypes = [];
    $hotelIds = [];
    $sources = [];
    $rawObjectRows = 0;
    $rowsWithFacts = 0;
    $factMetricCounts = [];
    $rawKeyCounts = [];
    $pricingKeySamples = [];
    $sampleRows = [];

    foreach ($rows as $row) {
        ctrip_pricing_sources_count($dataTypes, trim((string)($row['data_type'] ?? '')));
        ctrip_pricing_sources_count($sources, trim((string)($row['source'] ?? '')));
        $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
        if ($systemHotelId > 0) {
            ctrip_pricing_sources_count($hotelIds, (string)$systemHotelId);
        }

        $raw = ctrip_pricing_sources_decode_raw($row['raw_data'] ?? null);
        if ($raw !== []) {
            $rawObjectRows++;
            ctrip_pricing_sources_scan_raw_keys($raw, '$', $rawKeyCounts, $pricingKeySamples);
        }
        $facts = ctrip_pricing_sources_field_facts($raw);
        if ($facts !== []) {
            $rowsWithFacts++;
        }
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metricKey = trim((string)($fact['metric_key'] ?? $fact['field_key'] ?? $fact['field'] ?? ''));
            ctrip_pricing_sources_count($factMetricCounts, $metricKey);
        }

        if (count($sampleRows) < 8) {
            $sampleRows[] = array_filter([
                'id' => $row['id'] ?? null,
                'source' => $row['source'] ?? null,
                'data_type' => $row['data_type'] ?? null,
                'dimension' => $row['dimension'] ?? null,
                'system_hotel_id' => $row['system_hotel_id'] ?? null,
                'data_source_id' => $row['data_source_id'] ?? null,
                'sync_task_id' => $row['sync_task_id'] ?? null,
                'ingestion_method' => $row['ingestion_method'] ?? null,
                'validation_status' => $row['validation_status'] ?? null,
                'source_trace_id' => $row['source_trace_id'] ?? null,
            ], static fn(mixed $value): bool => $value !== null && $value !== '');
        }
    }

    $pricingFieldPaths = array_keys($pricingKeySamples);
    sort($pricingFieldPaths);

    return [
        'row_count' => count($rows),
        'source_counts' => ctrip_pricing_sources_top_counts($sources),
        'system_hotel_id_counts' => ctrip_pricing_sources_top_counts($hotelIds),
        'data_type_counts' => ctrip_pricing_sources_top_counts($dataTypes),
        'raw_object_rows' => $rawObjectRows,
        'rows_with_field_facts' => $rowsWithFacts,
        'fact_metric_keys' => ctrip_pricing_sources_top_counts($factMetricCounts, 80),
        'raw_key_samples_matching_pricing_terms' => array_slice($pricingFieldPaths, 0, 80),
        'raw_key_counts_top' => ctrip_pricing_sources_top_counts($rawKeyCounts, 60),
        'sample_rows_without_raw_values' => $sampleRows,
    ];
}

/**
 * @param array<string, mixed> $summary
 * @return array<string, mixed>
 */
function ctrip_pricing_sources_operator_gap_summary(array $summary, array $preflight): array
{
    $paths = array_map('strtolower', ctrip_pricing_sources_list($summary['raw_key_samples_matching_pricing_terms'] ?? []));
    $joined = implode("\n", $paths);
    $hasRoomTypeHint = str_contains($joined, 'room');
    $hasPriceHint = str_contains($joined, 'price') || str_contains($joined, 'rate');
    $hasCompetitorHint = str_contains($joined, 'competitor');
    $hasDemandHint = str_contains($joined, 'demand') || str_contains($joined, 'forecast');

    return [
        'can_prefill_import_file' => false,
        'prefill_policy' => 'read_only_field_audit_no_values_no_import',
        'reason' => 'operator_verified_pricing_inputs_required',
        'observed_hints' => [
            'room_type_key_or_name' => $hasRoomTypeHint,
            'price_or_rate_key' => $hasPriceHint,
            'competitor_key' => $hasCompetitorHint,
            'demand_or_forecast_key' => $hasDemandHint,
        ],
        'required_before_execute' => [
            'room_types_enabled',
            'floor_price_or_min_rate_guard',
            'demand_forecast',
            'competitor_price_samples',
        ],
        'current_preflight' => [
            'status' => $preflight['status'] ?? null,
            'reason' => $preflight['reason'] ?? null,
            'target_hotel_ids' => $preflight['target_hotel_ids'] ?? [],
            'target_date_rows' => $preflight['target_date_rows'] ?? null,
            'room_type_count' => $preflight['room_type_count'] ?? null,
            'pending_suggestion_count' => $preflight['pending_suggestion_count'] ?? null,
            'create_candidate_count' => $preflight['create_candidate_count'] ?? null,
        ],
    ];
}

function ctrip_pricing_sources_hotel_arg(?int $hotelId): string
{
    return $hotelId === null ? '' : ' --hotel-id=' . $hotelId;
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    ctrip_pricing_sources_finish([
        'status' => 'failed',
        'error' => 'vendor/autoload.php is missing.',
    ], 1);
}

require $autoload;

try {
    $options = ctrip_pricing_sources_parse_args($argv);
    $app = new App($root);
    $app->initialize();

    $overviewFilters = [
        'business_date' => $options['date'],
        'platform' => 'ctrip',
        'enabled_channels' => ['ctrip'],
    ];
    if ($options['hotel_id'] !== null) {
        $overviewFilters['hotel_id'] = $options['hotel_id'];
    }
    $overview = (new RevenueAiOverviewService())->overview($overviewFilters);
    $preflight = ctrip_pricing_sources_map($overview['pricing_generation_preflight'] ?? []);
    $targetHotelIds = ctrip_pricing_sources_positive_ints(ctrip_pricing_sources_list($preflight['target_hotel_ids'] ?? []));
    $hotelId = $options['hotel_id'] ?? ($targetHotelIds[0] ?? null);

    if (!ctrip_pricing_sources_table_exists('online_daily_data')) {
        ctrip_pricing_sources_finish([
            'status' => 'failed',
            'error' => 'online_daily_data table does not exist.',
        ], 1);
    }
    $columns = ctrip_pricing_sources_table_columns('online_daily_data');
    foreach (['source', 'data_date', 'data_type', 'raw_data'] as $required) {
        if (!isset($columns[$required])) {
            ctrip_pricing_sources_finish([
                'status' => 'failed',
                'error' => 'online_daily_data is missing required column: ' . $required,
            ], 1);
        }
    }

    $rows = ctrip_pricing_sources_query_rows($columns, $options['date'], $hotelId, $options['limit']);
    $summary = ctrip_pricing_sources_rows_summary($rows);
    $operatorGap = ctrip_pricing_sources_operator_gap_summary($summary, $preflight);
    $candidateSourceAudit = ctrip_pricing_sources_candidate_source_audit($options['date'], $hotelId);
    $operatorInputLocators = ctrip_pricing_sources_operator_input_locators($rows);
    $hotelArg = ctrip_pricing_sources_hotel_arg($hotelId);
    $scopeText = strtolower(json_encode([$summary, $candidateSourceAudit, $operatorInputLocators], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    $hasMeituan = str_contains($scopeText, 'meituan');

    ctrip_pricing_sources_finish([
        'status' => 'passed',
        'scope' => [
            'business_date' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'source_policy' => 'read_existing_online_daily_data_metadata_and_field_keys_only',
            'raw_values_exposed' => false,
            'database_written' => false,
            'auto_write_ota' => false,
        ],
        'summary' => $summary,
        'candidate_source_audit' => $candidateSourceAudit,
        'operator_input_locators' => $operatorInputLocators,
        'operator_gap_summary' => $operatorGap,
        'next_commands' => [
            'inspect_current_ota_evidence' => 'npm.cmd run inspect:revenue-ai-ctrip-pricing-sources -- --date=' . $options['date'] . $hotelArg,
            'export_template' => 'npm.cmd run export:revenue-ai-ctrip-pricing-template -- --date=' . $options['date'] . $hotelArg . ' --output=<draft-json-path>',
            'pre_execute_gate' => 'npm.cmd run verify:revenue-ai-ctrip-pricing-file -- --file=<filled-json-path> --date=' . $options['date'] . $hotelArg,
            'verify_current_scope' => 'npm.cmd run verify:revenue-ai-ctrip-scope -- --date=' . $options['date'] . $hotelArg,
        ],
        'checks' => [
            [
                'code' => 'read_only_scope_ctrip',
                'status' => $hasMeituan ? 'failed' : 'passed',
                'message' => 'Audit output is scoped to Ctrip metadata and field keys only.',
            ],
            [
                'code' => 'operator_inputs_still_required',
                'status' => ($operatorGap['can_prefill_import_file'] ?? true) === false ? 'passed' : 'failed',
                'message' => 'Audit does not convert OTA rows into importable room types, forecasts, or competitor prices.',
            ],
            [
                'code' => 'candidate_sources_metadata_only',
                'status' => ($candidateSourceAudit['raw_values_exposed'] ?? true) === false
                    && ($candidateSourceAudit['database_written'] ?? true) === false
                    ? 'passed'
                    : 'failed',
                'message' => 'Candidate source audit exposes counts only and does not write data.',
            ],
            [
                'code' => 'operator_input_locators_metadata_only',
                'status' => ($operatorInputLocators['raw_values_exposed'] ?? true) === false
                    && ($operatorInputLocators['database_written'] ?? true) === false
                    ? 'passed'
                    : 'failed',
                'message' => 'Operator input locators expose metadata row ids and field paths only.',
            ],
        ],
    ], $hasMeituan ? 1 : 0);
} catch (Throwable $e) {
    ctrip_pricing_sources_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], 1);
}
