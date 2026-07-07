<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,history_days:int,format:string}
 */
function ctrip_traffic_trend_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'history-days' => '14',
        'history_days' => '14',
        'format' => 'json',
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
    if ($options['history-days'] === '14' && $options['history_days'] !== '14') {
        $options['history-days'] = $options['history_days'];
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

    if (!ctype_digit($options['history-days'])) {
        throw new InvalidArgumentException('Invalid --history-days, expected a positive integer.');
    }
    $historyDays = max(3, min(60, (int)$options['history-days']));

    $format = strtolower($options['format']);
    if (!in_array($format, ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'history_days' => $historyDays,
        'format' => $format,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_traffic_trend_finish(array $payload, int $exitCode, string $format = 'json'): void
{
    if ($format === 'markdown') {
        echo ctrip_traffic_trend_markdown($payload) . PHP_EOL;
        exit($exitCode);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, bool>
 */
function ctrip_traffic_trend_table_columns(string $table): array
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

function ctrip_traffic_trend_table_exists(string $table): bool
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
 * @return array<int, string>
 */
function ctrip_traffic_trend_source_aliases(): array
{
    return ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'];
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function ctrip_traffic_trend_decode_raw(mixed $raw): array
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

function ctrip_traffic_trend_number(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return is_finite((float)$value) ? (float)$value : null;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    $text = str_replace([',', '，', '%'], '', $text);
    if (!is_numeric($text)) {
        return null;
    }

    return (float)$text;
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $keys
 */
function ctrip_traffic_trend_row_number(array $row, array $keys): ?float
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $value = ctrip_traffic_trend_number($row[$key]);
            if ($value !== null) {
                return $value;
            }
        }
    }

    return null;
}

/**
 * @param array<int, string> $keys
 */
function ctrip_traffic_trend_raw_number(mixed $value, array $keys, int $depth = 0): ?float
{
    if ($depth > 8 || !is_array($value)) {
        return null;
    }
    $wanted = array_fill_keys(array_map('strtolower', $keys), true);
    foreach ($value as $key => $child) {
        $normalized = strtolower((string)$key);
        if (isset($wanted[$normalized])) {
            $number = ctrip_traffic_trend_number($child);
            if ($number !== null) {
                return $number;
            }
        }
        if (is_array($child)) {
            $number = ctrip_traffic_trend_raw_number($child, $keys, $depth + 1);
            if ($number !== null) {
                return $number;
            }
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, float>
 */
function ctrip_traffic_trend_extract_metrics(array $row): array
{
    $raw = ctrip_traffic_trend_decode_raw($row['raw_data'] ?? null);
    $metrics = [
        'list_exposure' => ctrip_traffic_trend_row_number($row, ['list_exposure'])
            ?? ctrip_traffic_trend_raw_number($raw, ['listExposure', 'list_exposure', 'exposure']),
        'detail_exposure' => ctrip_traffic_trend_row_number($row, ['detail_exposure'])
            ?? ctrip_traffic_trend_raw_number($raw, ['detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors']),
        'order_filling_num' => ctrip_traffic_trend_row_number($row, ['order_filling_num'])
            ?? ctrip_traffic_trend_raw_number($raw, ['orderFillingNum', 'order_filling_num', 'orderVisitors']),
        'order_submit_num' => ctrip_traffic_trend_row_number($row, ['order_submit_num'])
            ?? ctrip_traffic_trend_raw_number($raw, ['orderSubmitNum', 'order_submit_num', 'submitUsers']),
        'book_order_num' => ctrip_traffic_trend_row_number($row, ['book_order_num'])
            ?? ctrip_traffic_trend_raw_number($raw, ['bookOrderNum', 'book_order_num', 'orderCount', 'orders']),
        'room_nights' => ctrip_traffic_trend_row_number($row, ['quantity'])
            ?? ctrip_traffic_trend_raw_number($raw, ['roomNights', 'room_nights', 'quantity']),
    ];

    return array_map(static fn(mixed $value): float => max(0.0, (float)($value ?? 0)), $metrics);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_traffic_trend_query_rows(array $columns, string $startDate, string $endDate, ?int $hotelId): array
{
    $required = ['source', 'data_date', 'raw_data'];
    foreach ($required as $field) {
        if (!isset($columns[$field])) {
            throw new RuntimeException('online_daily_data missing required Ctrip trend column: ' . $field);
        }
    }
    if ($hotelId !== null && !isset($columns['system_hotel_id'])) {
        throw new RuntimeException('online_daily_data missing system_hotel_id; cannot keep hotel-scoped Ctrip trend.');
    }

    $fields = array_values(array_intersect([
        'id',
        'system_hotel_id',
        'hotel_id',
        'source',
        'data_date',
        'data_type',
        'dimension',
        'quantity',
        'book_order_num',
        'data_value',
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
        'validation_status',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
        'raw_data',
        'create_time',
        'update_time',
    ], array_keys($columns)));

    $query = Db::name('online_daily_data')
        ->field(implode(',', $fields))
        ->whereIn('source', ctrip_traffic_trend_source_aliases())
        ->where('data_date', '>=', $startDate)
        ->where('data_date', '<=', $endDate);

    if ($hotelId !== null) {
        $query->where('system_hotel_id', $hotelId);
    }
    if (isset($columns['data_type'])) {
        $query->where(function ($q): void {
            $q->whereIn('data_type', ['traffic', 'traffic_analysis', 'flow', 'flow_analysis'])
                ->whereOr('data_type', 'like', '%traffic%')
                ->whereOr('data_type', 'like', '%flow%');
        });
    }

    return $query->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function ctrip_traffic_trend_daily_series(array $rows): array
{
    $daily = [];
    foreach ($rows as $row) {
        $date = (string)($row['data_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        $metrics = ctrip_traffic_trend_extract_metrics($row);
        $trafficSignal = $metrics['list_exposure']
            + $metrics['detail_exposure']
            + $metrics['order_filling_num']
            + $metrics['order_submit_num'];
        if ($trafficSignal <= 0.0) {
            continue;
        }
        if (!isset($daily[$date])) {
            $daily[$date] = [
                'date' => $date,
                'row_count' => 0,
                'source_ref_count' => 0,
                'metrics' => [
                    'list_exposure' => 0.0,
                    'detail_exposure' => 0.0,
                    'order_filling_num' => 0.0,
                    'order_submit_num' => 0.0,
                    'book_order_num' => 0.0,
                    'room_nights' => 0.0,
                ],
            ];
        }
        $daily[$date]['row_count']++;
        $daily[$date]['source_ref_count']++;
        foreach ($daily[$date]['metrics'] as $metric => $_) {
            $daily[$date]['metrics'][$metric] += $metrics[$metric] ?? 0.0;
        }
    }

    return array_values($daily);
}

/**
 * @param array<int, array<string, mixed>> $daily
 */
function ctrip_traffic_trend_primary_metric(array $daily): string
{
    foreach (['order_submit_num', 'order_filling_num', 'detail_exposure', 'list_exposure'] as $metric) {
        $sum = 0.0;
        foreach ($daily as $day) {
            $sum += (float)(($day['metrics'] ?? [])[$metric] ?? 0);
        }
        if ($sum > 0.0) {
            return $metric;
        }
    }

    return '';
}

/**
 * @param array<int, float> $values
 */
function ctrip_traffic_trend_average(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    return array_sum($values) / count($values);
}

/**
 * @param array<int, float> $values
 */
function ctrip_traffic_trend_stddev(array $values): float
{
    if (count($values) < 2) {
        return 0.0;
    }
    $avg = ctrip_traffic_trend_average($values);
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ($value - $avg) ** 2;
    }

    return sqrt($sum / count($values));
}

function ctrip_traffic_trend_clamp(float $value, float $min, float $max): float
{
    return min($max, max($min, $value));
}

/**
 * @param array<int, array<string, mixed>> $daily
 * @return array<string, mixed>
 */
function ctrip_traffic_trend_forecast(string $date, string $startDate, string $endDate, int $historyDays, array $daily): array
{
    $primaryMetric = ctrip_traffic_trend_primary_metric($daily);
    if ($primaryMetric === '') {
        return [
            'status' => 'blocked_by_missing_traffic_metric',
            'can_be_used_as_demand_forecast_source' => false,
            'reason' => 'no_positive_ctrip_traffic_metric_found',
        ];
    }

    $values = [];
    foreach ($daily as $day) {
        $value = (float)(($day['metrics'] ?? [])[$primaryMetric] ?? 0);
        if ($value > 0.0) {
            $values[] = $value;
        }
    }
    $usableDays = count($values);
    if ($usableDays < 3) {
        return [
            'status' => 'blocked_by_insufficient_traffic_history',
            'can_be_used_as_demand_forecast_source' => false,
            'reason' => 'need_at_least_3_history_days_with_positive_ctrip_traffic_metric',
            'primary_metric' => $primaryMetric,
            'usable_history_days' => $usableDays,
        ];
    }

    $baselineAverage = ctrip_traffic_trend_average($values);
    $recentValues = array_slice($values, -min(3, $usableDays));
    $recentAverage = ctrip_traffic_trend_average($recentValues);
    $demandIndex = $baselineAverage > 0.0 ? 100.0 * $recentAverage / $baselineAverage : 100.0;
    $trendDeltaPercent = $demandIndex - 100.0;
    $trendDirection = 'flat';
    if ($trendDeltaPercent >= 5.0) {
        $trendDirection = 'rising';
    } elseif ($trendDeltaPercent <= -5.0) {
        $trendDirection = 'falling';
    }

    $stddev = ctrip_traffic_trend_stddev($values);
    $variation = $baselineAverage > 0.0 ? $stddev / $baselineAverage : 0.0;
    $coverageScore = ctrip_traffic_trend_clamp($usableDays / min(14, $historyDays), 0.0, 1.0);
    $stabilityPenalty = ctrip_traffic_trend_clamp($variation * 0.18, 0.0, 0.2);
    $confidence = ctrip_traffic_trend_clamp(0.45 + ($coverageScore * 0.35) - $stabilityPenalty, 0.2, 0.9);
    $trendScore = ctrip_traffic_trend_clamp(50.0 + ($trendDeltaPercent / 2.0), 1.0, 100.0);
    $sourceNote = sprintf(
        'ctrip_historical_traffic_trend:%s..%s;primary_metric=%s;usable_days=%d;demand_index=%.2f;trend_score_0_100=%.2f;occupancy_is_traffic_trend_score_not_whole_hotel_occupancy',
        $startDate,
        $endDate,
        $primaryMetric,
        $usableDays,
        $demandIndex,
        $trendScore
    );

    return [
        'status' => 'draft_from_ctrip_historical_traffic',
        'can_be_used_as_demand_forecast_source' => true,
        'source_policy' => 'derived_trend_only_no_raw_rows_no_import',
        'forecast_source' => 'ctrip_historical_traffic_trend',
        'forecast_date' => $date,
        'history_window' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'history_days_requested' => $historyDays,
            'usable_history_days' => $usableDays,
        ],
        'primary_metric' => $primaryMetric,
        'trend_direction' => $trendDirection,
        'trend_delta_percent' => round($trendDeltaPercent, 2),
        'predicted_demand_index' => round($demandIndex, 2),
        'trend_score_0_100' => round($trendScore, 2),
        'confidence_score' => round($confidence, 2),
        'import_row_draft' => [
            'room_type_key' => '<operator_room_type_key_required>',
            'forecast_date' => $date,
            'predicted_occupancy' => round($trendScore, 2),
            'predicted_demand' => round($demandIndex, 2),
            'confidence_score' => round($confidence, 2),
            'forecast_method' => 3,
            'source_note' => $sourceNote,
        ],
        'field_semantics' => [
            'predicted_occupancy' => 'traffic_trend_score_0_100_for_Ctrip_channel_demand_trend_not_whole_hotel_occupancy_50_means_history_baseline',
            'predicted_demand' => 'Ctrip historical traffic demand index where 100 means history-window baseline',
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $daily
 * @return array<int, array<string, mixed>>
 */
function ctrip_traffic_trend_daily_preview(array $daily, string $primaryMetric): array
{
    $preview = [];
    foreach ($daily as $day) {
        $preview[] = [
            'date' => $day['date'] ?? null,
            'row_count' => $day['row_count'] ?? 0,
            'primary_metric_value' => $primaryMetric === '' ? null : round((float)(($day['metrics'] ?? [])[$primaryMetric] ?? 0), 2),
        ];
    }

    return array_slice($preview, -14);
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_traffic_trend_markdown(array $payload): string
{
    $forecast = is_array($payload['forecast_draft'] ?? null) ? $payload['forecast_draft'] : [];
    $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
    $lines = [];
    $lines[] = '# Ctrip Traffic Demand Trend Draft';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? '') . '`';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- trend_source: `ctrip_historical_traffic_trend`';
    $lines[] = '';
    $lines[] = '## Forecast Draft';
    $lines[] = '';
    foreach ([
        'status',
        'forecast_date',
        'primary_metric',
        'trend_direction',
        'trend_delta_percent',
        'predicted_demand_index',
        'trend_score_0_100',
        'confidence_score',
    ] as $key) {
        if (array_key_exists($key, $forecast)) {
            $lines[] = '- ' . $key . ': `' . (string)$forecast[$key] . '`';
        }
    }
    $row = is_array($forecast['import_row_draft'] ?? null) ? $forecast['import_row_draft'] : [];
    if ($row !== []) {
        $lines[] = '';
        $lines[] = '## Pricing Input Row Draft';
        $lines[] = '';
        $lines[] = '| field | value |';
        $lines[] = '|---|---|';
        foreach ($row as $key => $value) {
            $lines[] = '| `' . $key . '` | `' . str_replace('|', '\\|', (string)$value) . '` |';
        }
    }
    $lines[] = '';
    $lines[] = '## Boundaries';
    $lines[] = '';
    $lines[] = '- This is Ctrip OTA traffic trend evidence only.';
    $lines[] = '- It does not write demand_forecasts, price_suggestions, operation intents, or OTA prices.';
    $lines[] = '- `room_type_key` still requires operator room mapping before import.';
    $lines[] = '- `predicted_occupancy` is a bounded traffic trend score, not whole-hotel occupancy.';

    return implode(PHP_EOL, $lines);
}

try {
    $options = ctrip_traffic_trend_parse_args($argv);
    $root = dirname(__DIR__);
    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    $app = new App($root);
    $app->initialize();

    $date = $options['date'];
    $endDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $startDate = date('Y-m-d', strtotime($date . ' -' . $options['history_days'] . ' days'));
    $hotelId = $options['hotel_id'];

    $basePayload = [
        'scope' => [
            'business_date' => $date,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'meituan_scope_included' => false,
        ],
        'history_window' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'history_days_requested' => $options['history_days'],
        ],
        'source_policy' => 'read_existing_online_daily_data_ctrip_traffic_aggregates_only',
        'raw_rows_exposed' => false,
        'aggregate_values_exposed' => true,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_without_operator_review' => false,
    ];

    if (!ctrip_traffic_trend_table_exists('online_daily_data')) {
        ctrip_traffic_trend_finish(array_merge($basePayload, [
            'status' => 'blocked_by_missing_online_daily_data',
            'forecast_draft' => [
                'status' => 'blocked_by_missing_online_daily_data',
                'can_be_used_as_demand_forecast_source' => false,
            ],
        ]), 0, $options['format']);
    }

    $columns = ctrip_traffic_trend_table_columns('online_daily_data');
    $rows = ctrip_traffic_trend_query_rows($columns, $startDate, $endDate, $hotelId);
    $daily = ctrip_traffic_trend_daily_series($rows);
    $forecast = ctrip_traffic_trend_forecast($date, $startDate, $endDate, $options['history_days'], $daily);
    $primaryMetric = (string)($forecast['primary_metric'] ?? ctrip_traffic_trend_primary_metric($daily));
    $status = (string)($forecast['status'] ?? '') === 'draft_from_ctrip_historical_traffic'
        ? 'passed'
        : (string)($forecast['status'] ?? 'blocked');

    ctrip_traffic_trend_finish(array_merge($basePayload, [
        'status' => $status,
        'row_count' => count($rows),
        'daily_count' => count($daily),
        'daily_preview_policy' => 'last_14_days_primary_metric_aggregate_only_no_raw_rows',
        'daily_preview' => ctrip_traffic_trend_daily_preview($daily, $primaryMetric),
        'forecast_draft' => $forecast,
    ]), 0, $options['format']);
} catch (Throwable $e) {
    ctrip_traffic_trend_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'database_written' => false,
        'auto_write_ota' => false,
    ], 1, 'json');
}
