<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,format:string}
 */
function ctrip_pricing_candidates_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
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

    $format = strtolower($options['format']);
    if (!in_array($format, ['json', 'markdown', 'csv'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json, markdown, or csv.');
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'format' => $format,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_candidates_finish(array $payload, int $exitCode, string $format = 'json'): void
{
    if ($format === 'markdown') {
        echo ctrip_pricing_candidates_markdown($payload) . PHP_EOL;
        exit($exitCode);
    }
    if ($format === 'csv') {
        echo ctrip_pricing_candidates_csv($payload);
        exit($exitCode);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

/**
 * @return array<string, bool>
 */
function ctrip_pricing_candidates_table_columns(string $table): array
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

function ctrip_pricing_candidates_table_exists(string $table): bool
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
function ctrip_pricing_candidates_source_aliases(): array
{
    return ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'];
}

/**
 * @param mixed $raw
 * @return array<string, mixed>
 */
function ctrip_pricing_candidates_decode_raw(mixed $raw): array
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

function ctrip_pricing_candidates_number(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return is_finite((float)$value) ? (float)$value : null;
    }
    $text = str_replace([',', '，', '%'], '', trim((string)$value));
    if ($text === '' || !is_numeric($text)) {
        return null;
    }

    return (float)$text;
}

/**
 * @param array<string, mixed> $value
 * @param array<int, string> $path
 */
function ctrip_pricing_candidates_path_value(array $value, array $path): mixed
{
    $cursor = $value;
    foreach ($path as $part) {
        if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
            return null;
        }
        $cursor = $cursor[$part];
    }

    return $cursor;
}

/**
 * @param array<string, mixed> $raw
 */
function ctrip_pricing_candidates_metric(array $raw, string $key): mixed
{
    foreach ([
        ['row', 'raw_data', 'metrics', $key],
        ['raw_data', 'metrics', $key],
        ['metrics', $key],
        ['row', $key],
        [$key],
    ] as $path) {
        $value = ctrip_pricing_candidates_path_value($raw, $path);
        if ($value !== null && $value !== '') {
            return $value;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 */
function ctrip_pricing_candidates_source_note(array $row, string $sourcePath): string
{
    return 'online_daily_data#' . (string)($row['id'] ?? '')
        . ';source=ctrip;path=' . $sourcePath
        . ';data_date=' . (string)($row['data_date'] ?? '');
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_candidates_room_types(array $rows): array
{
    $candidates = [];
    $seen = [];
    foreach ($rows as $row) {
        $raw = ctrip_pricing_candidates_decode_raw($row['raw_data'] ?? null);
        $name = ctrip_pricing_candidates_metric($raw, 'top_hot_room');
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $candidates[] = [
            'candidate_status' => 'candidate_requires_operator_confirmation',
            'room_type_name_candidate' => $name,
            'room_nights_observed' => ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'top_hot_room_nights')),
            'sale_percent_observed' => ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'top_hot_room_sale_percent')),
            'source_ref' => 'online_daily_data#' . (string)($row['id'] ?? ''),
            'source_note' => ctrip_pricing_candidates_source_note($row, '$.row.raw_data.metrics.top_hot_room'),
            'importable_value' => false,
            'missing_for_import' => [
                'operator room_type_key',
                'operator-confirmed sellable room_count',
                'operator-approved base_price',
                'operator-approved min_price',
                'operator-approved max_price',
            ],
        ];
    }

    return $candidates;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_candidates_price_observations(array $rows): array
{
    $keys = [
        'avg_price' => '$.row.raw_data.metrics.avg_price',
        'avg_price_last_week' => '$.row.raw_data.metrics.avg_price_last_week',
        'last_week_checkout_room_price' => '$.row.raw_data.metrics.last_week_checkout_room_price',
        'averagePrice' => '$.row.averagePrice',
        'synchronizationAveragePrice' => '$.row.synchronizationAveragePrice',
    ];
    $candidates = [];
    $seen = [];
    foreach ($rows as $row) {
        $raw = ctrip_pricing_candidates_decode_raw($row['raw_data'] ?? null);
        foreach ($keys as $key => $sourcePath) {
            $value = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, $key));
            if ($value === null || $value <= 0) {
                continue;
            }
            $seenKey = $key . ':' . (string)$value . ':' . (string)($row['id'] ?? '');
            if (isset($seen[$seenKey])) {
                continue;
            }
            $seen[$seenKey] = true;
            $candidates[] = [
                'candidate_status' => 'observed_ctrip_price_metric_not_floor_guard',
                'metric_key' => $key,
                'observed_value' => round($value, 2),
                'source_ref' => 'online_daily_data#' . (string)($row['id'] ?? ''),
                'source_note' => ctrip_pricing_candidates_source_note($row, $sourcePath),
                'importable_value' => false,
                'operator_action' => 'Use as Ctrip price context only; operator must approve base_price, min_price, and max_price.',
                'missing_for_import' => [
                    'operator-approved base_price',
                    'operator-approved floor/protection min_price',
                    'operator-approved max_price',
                ],
            ];
        }
    }

    usort($candidates, static fn(array $a, array $b): int => strcmp((string)$a['metric_key'], (string)$b['metric_key']));
    return array_slice($candidates, 0, 20);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_candidates_competitor_aggregates(array $rows): array
{
    $candidates = [];
    foreach ($rows as $row) {
        $raw = ctrip_pricing_candidates_decode_raw($row['raw_data'] ?? null);
        $revenue = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_revenue'));
        $orders = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_orders'));
        $visitors = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_visitor'));
        $occupiedRooms = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_avg_occupied_rooms'));
        $listExposure = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_list_exposure'));
        $detailVisitor = ctrip_pricing_candidates_number(ctrip_pricing_candidates_metric($raw, 'competitor_detail_visitor'));

        if ($revenue === null && $orders === null && $visitors === null && $occupiedRooms === null && $listExposure === null && $detailVisitor === null) {
            continue;
        }
        $derivedRevenuePerOrder = ($revenue !== null && $orders !== null && $orders > 0)
            ? round($revenue / $orders, 2)
            : null;

        $candidates[] = [
            'candidate_status' => 'competitor_aggregate_not_price_sample',
            'competitor_name' => null,
            'competitor_revenue_aggregate' => $revenue,
            'competitor_orders_aggregate' => $orders,
            'competitor_revenue_per_order_aggregate' => $derivedRevenuePerOrder,
            'competitor_visitor_aggregate' => $visitors,
            'competitor_avg_occupied_rooms' => $occupiedRooms,
            'competitor_list_exposure' => $listExposure,
            'competitor_detail_visitor' => $detailVisitor,
            'source_ref' => 'online_daily_data#' . (string)($row['id'] ?? ''),
            'source_note' => ctrip_pricing_candidates_source_note($row, '$.row.raw_data.metrics.competitor_*'),
            'importable_value' => false,
            'operator_action' => 'Use only to locate/review Ctrip competitor context; still need competitor_name, our_price, and competitor_price from a Ctrip price sample.',
            'missing_for_import' => [
                'competitor_name',
                'our_price for comparable Ctrip room/date',
                'competitor_price for comparable Ctrip room/date',
                'room_type_key mapping',
            ],
        ];
    }

    return array_slice($candidates, 0, 20);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_pricing_candidates_query_rows(array $columns, string $startDate, string $endDate, ?int $hotelId): array
{
    foreach (['source', 'data_date', 'raw_data'] as $field) {
        if (!isset($columns[$field])) {
            throw new RuntimeException('online_daily_data missing required Ctrip candidate column: ' . $field);
        }
    }
    if ($hotelId !== null && !isset($columns['system_hotel_id'])) {
        throw new RuntimeException('online_daily_data missing system_hotel_id; cannot keep hotel-scoped candidate extraction.');
    }

    $fields = array_values(array_intersect([
        'id',
        'system_hotel_id',
        'hotel_id',
        'source',
        'data_date',
        'data_type',
        'dimension',
        'raw_data',
        'data_source_id',
        'sync_task_id',
        'ingestion_method',
        'source_trace_id',
        'validation_status',
    ], array_keys($columns)));

    $query = Db::name('online_daily_data')
        ->field(implode(',', $fields))
        ->whereIn('source', ctrip_pricing_candidates_source_aliases())
        ->where('data_date', '>=', $startDate)
        ->where('data_date', '<=', $endDate);

    if ($hotelId !== null) {
        $query->where('system_hotel_id', $hotelId);
    }

    return $query->order('data_date', 'desc')->order('id', 'asc')->limit(500)->select()->toArray();
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_candidates_markdown(array $payload): string
{
    $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
    $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $lines = [];
    $lines[] = '# Ctrip Pricing Evidence Candidates';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? '') . '`';
    $lines[] = '- business_date: `' . (string)($scope['business_date'] ?? '') . '`';
    $lines[] = '- hotel_id: `' . (string)($scope['hotel_id'] ?? 'unknown') . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- importable_without_operator_review: `false`';
    $lines[] = '- row_count: `' . (string)($summary['row_count'] ?? 0) . '`';
    $lines[] = '';
    $lines[] = '## Room Type Candidates';
    $lines[] = '';
    $lines[] = '| room_type_name_candidate | observed room nights | sale percent | source_note | importable |';
    $lines[] = '|---|---:|---:|---|---|';
    foreach ($candidates['room_type_candidates'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = '| ' . ctrip_pricing_candidates_md((string)($row['room_type_name_candidate'] ?? ''))
            . ' | ' . ctrip_pricing_candidates_md((string)($row['room_nights_observed'] ?? ''))
            . ' | ' . ctrip_pricing_candidates_md((string)($row['sale_percent_observed'] ?? ''))
            . ' | `' . ctrip_pricing_candidates_md((string)($row['source_note'] ?? '')) . '`'
            . ' | `false` |';
    }
    $lines[] = '';
    $lines[] = '## Price Observations';
    $lines[] = '';
    $lines[] = '| metric_key | observed_value | source_note | operator_action | importable |';
    $lines[] = '|---|---:|---|---|---|';
    foreach ($candidates['price_observation_candidates'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = '| `' . ctrip_pricing_candidates_md((string)($row['metric_key'] ?? '')) . '`'
            . ' | ' . ctrip_pricing_candidates_md((string)($row['observed_value'] ?? ''))
            . ' | `' . ctrip_pricing_candidates_md((string)($row['source_note'] ?? '')) . '`'
            . ' | ' . ctrip_pricing_candidates_md((string)($row['operator_action'] ?? ''))
            . ' | `false` |';
    }
    $lines[] = '';
    $lines[] = '## Competitor Aggregates';
    $lines[] = '';
    $lines[] = '| source_note | revenue | orders | revenue_per_order | operator_action | importable |';
    $lines[] = '|---|---:|---:|---:|---|---|';
    foreach ($candidates['competitor_aggregate_candidates'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = '| `' . ctrip_pricing_candidates_md((string)($row['source_note'] ?? '')) . '`'
            . ' | ' . ctrip_pricing_candidates_md((string)($row['competitor_revenue_aggregate'] ?? ''))
            . ' | ' . ctrip_pricing_candidates_md((string)($row['competitor_orders_aggregate'] ?? ''))
            . ' | ' . ctrip_pricing_candidates_md((string)($row['competitor_revenue_per_order_aggregate'] ?? ''))
            . ' | ' . ctrip_pricing_candidates_md((string)($row['operator_action'] ?? ''))
            . ' | `false` |';
    }
    $lines[] = '';
    $lines[] = '## Boundaries';
    $lines[] = '';
    $lines[] = '- Candidate values are Ctrip evidence aids, not importable pricing inputs.';
    $lines[] = '- Price observations are not floor/protection prices.';
    $lines[] = '- Competitor aggregates are not named competitor price samples.';
    $lines[] = '- Keep AI suggestion, operation intent, and ROI evidence manual-review gated.';

    return implode(PHP_EOL, $lines);
}

function ctrip_pricing_candidates_md(string $value): string
{
    return str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $value);
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_pricing_candidates_csv(array $payload): string
{
    $headers = [
        'candidate_group',
        'candidate_status',
        'field',
        'value',
        'source_note',
        'operator_action',
        'importable_value',
        'missing_for_import',
    ];
    $rows = [$headers];
    $candidates = is_array($payload['candidates'] ?? null) ? $payload['candidates'] : [];
    foreach ($candidates as $group => $items) {
        if (!is_array($items)) {
            continue;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($item as $field => $value) {
                if (in_array($field, ['candidate_status', 'source_note', 'operator_action', 'importable_value', 'missing_for_import', 'source_ref'], true)) {
                    continue;
                }
                if (is_array($value) || $value === null || $value === '') {
                    continue;
                }
                $rows[] = [
                    (string)$group,
                    (string)($item['candidate_status'] ?? ''),
                    (string)$field,
                    (string)$value,
                    (string)($item['source_note'] ?? ''),
                    (string)($item['operator_action'] ?? ''),
                    (($item['importable_value'] ?? true) === false) ? 'false' : 'true',
                    implode('; ', array_map('strval', is_array($item['missing_for_import'] ?? null) ? $item['missing_for_import'] : [])),
                ];
            }
        }
    }

    return implode('', array_map(static fn(array $row): string => ctrip_pricing_candidates_csv_row($row), $rows));
}

/**
 * @param array<int, string> $row
 */
function ctrip_pricing_candidates_csv_row(array $row): string
{
    return implode(',', array_map(static function (string $value): string {
        if (str_contains($value, '"') || str_contains($value, ',') || str_contains($value, "\n") || str_contains($value, "\r")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }, $row)) . PHP_EOL;
}

try {
    $options = ctrip_pricing_candidates_parse_args($argv);
    $root = dirname(__DIR__);
    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    $app = new App($root);
    $app->initialize();

    $date = $options['date'];
    $hotelId = $options['hotel_id'];
    $startDate = date('Y-m-d', strtotime($date . ' -6 days'));

    $basePayload = [
        'scope' => [
            'business_date' => $date,
            'analysis_start_date' => $startDate,
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $hotelId,
            'source_scope' => 'ctrip_ota_channel',
            'meituan_scope_included' => false,
        ],
        'source_policy' => 'read_existing_online_daily_data_ctrip_candidate_values_only',
        'raw_rows_exposed' => false,
        'candidate_values_exposed' => true,
        'database_written' => false,
        'auto_write_ota' => false,
        'importable_without_operator_review' => false,
    ];

    if (!ctrip_pricing_candidates_table_exists('online_daily_data')) {
        ctrip_pricing_candidates_finish(array_merge($basePayload, [
            'status' => 'blocked_by_missing_online_daily_data',
            'summary' => ['row_count' => 0],
            'candidates' => [],
        ]), 0, $options['format']);
    }

    $columns = ctrip_pricing_candidates_table_columns('online_daily_data');
    $rows = ctrip_pricing_candidates_query_rows($columns, $startDate, $date, $hotelId);
    $targetRows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => (string)($row['data_date'] ?? '') === $date
    ));

    $roomTypeCandidates = ctrip_pricing_candidates_room_types($targetRows);
    $priceObservationCandidates = ctrip_pricing_candidates_price_observations($targetRows);
    $competitorAggregateCandidates = ctrip_pricing_candidates_competitor_aggregates($rows);
    $candidateCount = count($roomTypeCandidates) + count($priceObservationCandidates) + count($competitorAggregateCandidates);

    ctrip_pricing_candidates_finish(array_merge($basePayload, [
        'status' => $candidateCount > 0 ? 'passed' : 'blocked_by_no_candidate_values',
        'summary' => [
            'row_count' => count($rows),
            'target_date_row_count' => count($targetRows),
            'candidate_count' => $candidateCount,
            'room_type_candidate_count' => count($roomTypeCandidates),
            'price_observation_candidate_count' => count($priceObservationCandidates),
            'competitor_aggregate_candidate_count' => count($competitorAggregateCandidates),
            'remaining_required_real_inputs' => [
                'operator-confirmed room_type_key',
                'operator-confirmed room_count',
                'operator-approved floor/protection min_price',
                'named Ctrip competitor price samples',
            ],
        ],
        'candidates' => [
            'room_type_candidates' => $roomTypeCandidates,
            'price_observation_candidates' => $priceObservationCandidates,
            'competitor_aggregate_candidates' => $competitorAggregateCandidates,
        ],
    ]), 0, $options['format']);
} catch (Throwable $e) {
    ctrip_pricing_candidates_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'database_written' => false,
        'auto_write_ota' => false,
    ], 1, 'json');
}
