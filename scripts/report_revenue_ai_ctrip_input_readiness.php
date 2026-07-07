<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;
use app\service\RevenuePricingRecommendationService;

date_default_timezone_set('Asia/Shanghai');

/**
 * @param array<int, string> $argv
 * @return array{date:string,hotel_id:int|null,limit:int,format:string}
 */
function ctrip_input_readiness_parse_args(array $argv): array
{
    $options = [
        'date' => date('Y-m-d'),
        'business-date' => '',
        'business_date' => '',
        'hotel-id' => '',
        'hotel_id' => '',
        'limit' => '80',
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

    if (!ctype_digit($options['limit']) || (int)$options['limit'] <= 0) {
        throw new InvalidArgumentException('Invalid --limit, expected a positive integer.');
    }
    $limit = min((int)$options['limit'], 500);

    $format = strtolower($options['format']);
    if (!in_array($format, ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    return [
        'date' => $options['date'],
        'hotel_id' => $hotelId,
        'limit' => $limit,
        'format' => $format,
    ];
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_input_readiness_finish(array $payload, int $exitCode, string $format): void
{
    if ($format === 'markdown') {
        echo ctrip_input_readiness_markdown($payload) . PHP_EOL;
        exit($exitCode);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($exitCode);
}

function ctrip_input_readiness_table_exists(string $table): bool
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
 * @return array<string, bool>
 */
function ctrip_input_readiness_columns(string $table): array
{
    $columns = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return $columns;
    }
    foreach (Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

/**
 * @return array<int, string>
 */
function ctrip_input_readiness_source_aliases(): array
{
    return ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'];
}

function ctrip_input_readiness_apply_ctrip_platform_filter(mixed $query): void
{
    $query->whereIn('ota_platform', [1, '1', 'ctrip']);
}

/**
 * @return array<string, mixed>
 */
function ctrip_input_readiness_table_summary(): array
{
    $summary = [];
    foreach (['online_daily_data', 'room_types', 'demand_forecasts', 'competitor_analysis', 'competitor_price_log'] as $table) {
        if (!ctrip_input_readiness_table_exists($table)) {
            $summary[$table] = ['exists' => false, 'count' => null];
            continue;
        }
        $summary[$table] = [
            'exists' => true,
            'count' => (int)Db::name($table)->count(),
        ];
    }

    return $summary;
}

/**
 * @return array{room_types_enabled:int,room_price_guards:int}
 */
function ctrip_input_readiness_room_counts(int $hotelId): array
{
    if (!ctrip_input_readiness_table_exists('room_types')) {
        return ['room_types_enabled' => 0, 'room_price_guards' => 0];
    }
    $columns = ctrip_input_readiness_columns('room_types');
    if (!isset($columns['hotel_id'])) {
        return ['room_types_enabled' => 0, 'room_price_guards' => 0];
    }

    $base = Db::name('room_types')->where('hotel_id', $hotelId);
    if (isset($columns['is_enabled'])) {
        $base->where('is_enabled', 1);
    }
    if (isset($columns['deleted_at'])) {
        $base->whereNull('deleted_at');
    }
    $enabled = (int)$base->count();

    $guarded = Db::name('room_types')->where('hotel_id', $hotelId);
    if (isset($columns['is_enabled'])) {
        $guarded->where('is_enabled', 1);
    }
    if (isset($columns['deleted_at'])) {
        $guarded->whereNull('deleted_at');
    }
    foreach (['base_price', 'min_price', 'max_price'] as $field) {
        if (isset($columns[$field])) {
            $guarded->where($field, '>', 0);
        }
    }

    return [
        'room_types_enabled' => $enabled,
        'room_price_guards' => (int)$guarded->count(),
    ];
}

function ctrip_input_readiness_demand_count(int $hotelId, string $date): int
{
    if (!ctrip_input_readiness_table_exists('demand_forecasts')) {
        return 0;
    }
    $columns = ctrip_input_readiness_columns('demand_forecasts');
    if (!isset($columns['hotel_id'])) {
        return 0;
    }
    $query = Db::name('demand_forecasts')->where('hotel_id', $hotelId);
    if (isset($columns['forecast_date'])) {
        $query->where('forecast_date', $date);
    }
    if (isset($columns['deleted_at'])) {
        $query->whereNull('deleted_at');
    }

    return (int)$query->count();
}

function ctrip_input_readiness_competitor_count(int $hotelId, string $date): int
{
    $startDate = date('Y-m-d', strtotime($date . ' -6 days'));
    $count = 0;
    if (ctrip_input_readiness_table_exists('competitor_analysis')) {
        $columns = ctrip_input_readiness_columns('competitor_analysis');
        if (isset($columns['hotel_id'])) {
            $query = Db::name('competitor_analysis')->where('hotel_id', $hotelId);
            if (isset($columns['analysis_date'])) {
                $query->where('analysis_date', '>=', $startDate)->where('analysis_date', '<=', $date);
            }
            if (isset($columns['ota_platform'])) {
                ctrip_input_readiness_apply_ctrip_platform_filter($query);
            }
            if (isset($columns['deleted_at'])) {
                $query->whereNull('deleted_at');
            }
            $count += (int)$query->count();
        }
    }
    if (ctrip_input_readiness_table_exists('competitor_price_log')) {
        $columns = ctrip_input_readiness_columns('competitor_price_log');
        if (isset($columns['hotel_id'])) {
            $query = Db::name('competitor_price_log')->where('hotel_id', $hotelId);
            if (isset($columns['analysis_date'])) {
                $query->where('analysis_date', '>=', $startDate)->where('analysis_date', '<=', $date);
            } elseif (isset($columns['created_at'])) {
                $query->where('created_at', '>=', $startDate . ' 00:00:00')->where('created_at', '<=', $date . ' 23:59:59');
            }
            if (isset($columns['ota_platform'])) {
                ctrip_input_readiness_apply_ctrip_platform_filter($query);
            }
            if (isset($columns['deleted_at'])) {
                $query->whereNull('deleted_at');
            }
            $count += (int)$query->count();
        }
    }

    return $count;
}

/**
 * @return array<int, array<string, mixed>>
 */
function ctrip_input_readiness_scan(string $date, ?int $hotelId, int $limit): array
{
    if (!ctrip_input_readiness_table_exists('online_daily_data')) {
        return [];
    }
    $columns = ctrip_input_readiness_columns('online_daily_data');
    $hotelColumn = isset($columns['system_hotel_id']) ? 'system_hotel_id' : (isset($columns['hotel_id']) ? 'hotel_id' : '');
    if ($hotelColumn === '' || !isset($columns['source']) || !isset($columns['data_date'])) {
        return [];
    }

    $query = Db::name('online_daily_data')
        ->field($hotelColumn . ' AS hotel_id,data_date,COUNT(*) AS ctrip_rows')
        ->whereIn('source', ctrip_input_readiness_source_aliases())
        ->where('data_date', '<=', $date)
        ->group($hotelColumn . ',data_date')
        ->order('data_date', 'desc')
        ->limit($limit);
    if ($hotelId !== null) {
        $query->where($hotelColumn, $hotelId);
    }

    $rows = $query->select()->toArray();
    $pricingService = new RevenuePricingRecommendationService();
    $candidates = [];
    foreach ($rows as $row) {
        $rowHotelId = (int)($row['hotel_id'] ?? 0);
        $rowDate = (string)($row['data_date'] ?? '');
        if ($rowHotelId <= 0 || $rowDate === '') {
            continue;
        }
        $roomCounts = ctrip_input_readiness_room_counts($rowHotelId);
        $demandCount = ctrip_input_readiness_demand_count($rowHotelId, $rowDate);
        $trafficForecast = [];
        $trafficDemandReady = false;
        if ($demandCount <= 0) {
            try {
                $trafficForecast = $pricingService->ctripTrafficDemandForecastSignal($rowHotelId, $rowDate);
                $trafficDemandReady = ($trafficForecast['data_status'] ?? '') === 'ok';
            } catch (Throwable) {
                $trafficForecast = ['data_status' => 'failed'];
                $trafficDemandReady = false;
            }
        }
        $competitorCount = ctrip_input_readiness_competitor_count($rowHotelId, $rowDate);
        $missing = [];
        if ($roomCounts['room_types_enabled'] <= 0) {
            $missing[] = 'room_types_enabled';
        }
        if ($roomCounts['room_price_guards'] <= 0) {
            $missing[] = 'floor_price_or_min_rate_guard';
        }
        if ($demandCount <= 0 && !$trafficDemandReady) {
            $missing[] = 'demand_forecast';
        }
        if ($competitorCount <= 0) {
            $missing[] = 'competitor_price_samples';
        }

        $candidates[] = [
            'hotel_id' => $rowHotelId,
            'date' => $rowDate,
            'ctrip_rows' => (int)($row['ctrip_rows'] ?? 0),
            'room_types_enabled' => $roomCounts['room_types_enabled'],
            'room_price_guards' => $roomCounts['room_price_guards'],
            'demand_forecasts' => $demandCount,
            'demand_forecast_source' => $demandCount > 0 ? 'demand_forecasts' : ($trafficDemandReady ? 'ctrip_historical_traffic_trend' : 'missing'),
            'ctrip_traffic_demand_forecast_status' => (string)($trafficForecast['data_status'] ?? 'not_checked'),
            'ctrip_traffic_demand_primary_metric' => (string)($trafficForecast['primary_metric'] ?? ''),
            'competitor_samples_recent_7d' => $competitorCount,
            'complete_input_candidate' => $missing === [],
            'missing_inputs' => $missing,
        ];
    }

    usort($candidates, static function (array $a, array $b): int {
        $scoreA = (($a['complete_input_candidate'] ?? false) ? 1000 : 0)
            + (((int)($a['room_types_enabled'] ?? 0) > 0) ? 100 : 0)
            + (((int)($a['room_price_guards'] ?? 0) > 0) ? 100 : 0)
            + (((string)($a['demand_forecast_source'] ?? '') !== 'missing') ? 100 : 0)
            + (((int)($a['competitor_samples_recent_7d'] ?? 0) > 0) ? 100 : 0)
            + (int)($a['ctrip_rows'] ?? 0);
        $scoreB = (($b['complete_input_candidate'] ?? false) ? 1000 : 0)
            + (((int)($b['room_types_enabled'] ?? 0) > 0) ? 100 : 0)
            + (((int)($b['room_price_guards'] ?? 0) > 0) ? 100 : 0)
            + (((string)($b['demand_forecast_source'] ?? '') !== 'missing') ? 100 : 0)
            + (((int)($b['competitor_samples_recent_7d'] ?? 0) > 0) ? 100 : 0)
            + (int)($b['ctrip_rows'] ?? 0);

        return $scoreB <=> $scoreA;
    });

    return $candidates;
}

/**
 * @param array<string, mixed> $payload
 */
function ctrip_input_readiness_markdown(array $payload): string
{
    $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    $rows = is_array($payload['top_candidates'] ?? null) ? $payload['top_candidates'] : [];
    $lines = [];
    $lines[] = '# Ctrip Revenue AI Input Readiness';
    $lines[] = '';
    $lines[] = '- status: `' . (string)($payload['status'] ?? '') . '`';
    $lines[] = '- source_scope: `ctrip_ota_channel`';
    $lines[] = '- source_policy: `read_current_database_counts_and_ctrip_traffic_trend_only_no_raw_rows_no_import`';
    $lines[] = '- database_written: `false`';
    $lines[] = '- auto_write_ota: `false`';
    $lines[] = '- scan_status: `' . (string)($summary['scan_status'] ?? 'not_checked') . '`';
    $lines[] = '- scan_scope_note: `' . (string)($summary['scan_scope_note'] ?? '') . '`';
    $lines[] = '- complete_input_candidates: `' . (string)($summary['complete_input_candidates'] ?? 0) . '`';
    $lines[] = '- traffic_trend_demand_source_candidates: `' . (string)($summary['traffic_trend_demand_source_candidates'] ?? 0) . '`';
    $bestMissing = is_array($summary['best_available_missing_inputs'] ?? null)
        ? implode(', ', array_map('strval', $summary['best_available_missing_inputs']))
        : '';
    $operatorAction = is_array($summary['operator_action_required'] ?? null)
        ? implode('; ', array_map('strval', $summary['operator_action_required']))
        : '';
    if ($bestMissing !== '') {
        $lines[] = '- best_available_missing_inputs: `' . $bestMissing . '`';
    }
    if ($operatorAction !== '') {
        $lines[] = '- operator_action_required: `' . $operatorAction . '`';
    }
    $target = is_array($summary['target_date_candidate'] ?? null) ? $summary['target_date_candidate'] : [];
    $targetMissing = is_array($summary['current_required_real_inputs_before_execute'] ?? null)
        ? implode(', ', array_map('strval', $summary['current_required_real_inputs_before_execute']))
        : '';
    if ($target !== []) {
        $lines[] = '- target_date_demand_source: `' . (string)($target['demand_forecast_source'] ?? 'missing') . '`';
        $lines[] = '- target_date_missing_inputs: `' . ($targetMissing === '' ? 'none' : $targetMissing) . '`';
    }
    $lines[] = '';
    $lines[] = '| hotel_id | date | Ctrip rows | room types | price guards | demand source | trend status | competitor samples | complete | missing inputs |';
    $lines[] = '|---:|---|---:|---:|---:|---|---|---:|---|---|';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $missing = is_array($row['missing_inputs'] ?? null) ? implode(', ', array_map('strval', $row['missing_inputs'])) : '';
        $lines[] = '| ' . (string)($row['hotel_id'] ?? '')
            . ' | ' . (string)($row['date'] ?? '')
            . ' | ' . (string)($row['ctrip_rows'] ?? 0)
            . ' | ' . (string)($row['room_types_enabled'] ?? 0)
            . ' | ' . (string)($row['room_price_guards'] ?? 0)
            . ' | ' . (string)($row['demand_forecast_source'] ?? 'missing')
            . ' | ' . (string)($row['ctrip_traffic_demand_forecast_status'] ?? 'not_checked')
            . ' | ' . (string)($row['competitor_samples_recent_7d'] ?? 0)
            . ' | `' . (((bool)($row['complete_input_candidate'] ?? false)) ? 'true' : 'false') . '`'
            . ' | ' . ($missing === '' ? 'none' : $missing) . ' |';
    }
    $lines[] = '';
    $lines[] = '## Boundary';
    $lines[] = '';
    $lines[] = '- This report reads counts and aggregate Ctrip traffic trend only; it does not expose raw Ctrip rows.';
    $lines[] = '- A complete candidate still must pass the operator bundle preflight before AI suggestions can be generated.';
    $lines[] = '- No OTA price write is allowed by this report.';

    return implode(PHP_EOL, $lines);
}

try {
    $options = ctrip_input_readiness_parse_args($argv);
    $root = dirname(__DIR__);
    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    $app = new App($root);
    $app->initialize();

    $candidates = ctrip_input_readiness_scan($options['date'], $options['hotel_id'], $options['limit']);
    $completeCount = count(array_filter($candidates, static fn(array $row): bool => (bool)($row['complete_input_candidate'] ?? false)));
    $trafficTrendDemandSourceCount = count(array_filter(
        $candidates,
        static fn(array $row): bool => (string)($row['demand_forecast_source'] ?? '') === 'ctrip_historical_traffic_trend'
    ));
    $scanStatus = count($candidates) === 0
        ? 'no_ctrip_candidate_rows_in_current_scan'
        : ($completeCount === 0 ? 'no_complete_candidate_in_current_scan' : 'complete_candidate_available_in_current_scan');
    $scanScopeNote = $options['hotel_id'] === null
        ? 'all_scanned_ctrip_hotel_dates_limited_by_business_date_and_limit'
        : 'target_hotel_scanned_ctrip_dates_limited_by_business_date_and_limit';
    $bestAvailableCandidate = is_array($candidates[0] ?? null) ? $candidates[0] : [];
    $bestAvailableMissingInputs = is_array($bestAvailableCandidate['missing_inputs'] ?? null)
        ? array_values(array_map('strval', $bestAvailableCandidate['missing_inputs']))
        : [];
    $operatorActionRequired = $completeCount === 0 ? [
        'fill room_types_enabled with operator-verified room inventory',
        'fill floor_price_or_min_rate_guard with operator-approved price guard',
        'provide named Ctrip competitor_price_samples',
        'rerun operator bundle preflight before execute/generate',
    ] : [];
    $targetDateCandidate = [];
    foreach ($candidates as $candidate) {
        if ((string)($candidate['date'] ?? '') !== $options['date']) {
            continue;
        }
        if ($options['hotel_id'] !== null && (int)($candidate['hotel_id'] ?? 0) !== $options['hotel_id']) {
            continue;
        }
        $targetDateCandidate = $candidate;
        break;
    }
    $currentMissingInputs = is_array($targetDateCandidate['missing_inputs'] ?? null)
        ? array_values(array_map('strval', $targetDateCandidate['missing_inputs']))
        : [];

    ctrip_input_readiness_finish([
        'status' => 'passed',
        'scope' => [
            'business_date_lte' => $options['date'],
            'platform' => 'ctrip',
            'enabled_channels' => ['ctrip'],
            'hotel_id' => $options['hotel_id'],
            'source_scope' => 'ctrip_ota_channel',
            'meituan_scope_included' => false,
        ],
        'source_policy' => 'read_current_database_counts_and_ctrip_traffic_trend_only_no_raw_rows_no_import',
        'raw_rows_exposed' => false,
        'database_written' => false,
        'auto_write_ota' => false,
        'summary' => [
            'scan_status' => $scanStatus,
            'scan_scope_note' => $scanScopeNote,
            'candidate_row_limit' => $options['limit'],
            'candidate_rows' => count($candidates),
            'complete_input_candidates' => $completeCount,
            'traffic_trend_demand_source_candidates' => $trafficTrendDemandSourceCount,
            'accepted_demand_forecast_sources' => [
                'demand_forecasts',
                'ctrip_historical_traffic_trend',
            ],
            'required_before_execute' => [
                'room_types_enabled',
                'floor_price_or_min_rate_guard',
                'demand_forecast_source (demand_forecasts row or ctrip_historical_traffic_trend)',
                'competitor_price_samples',
            ],
            'best_available_candidate' => $bestAvailableCandidate,
            'best_available_missing_inputs' => $bestAvailableMissingInputs,
            'operator_action_required' => $operatorActionRequired,
            'target_date_candidate' => $targetDateCandidate,
            'current_required_real_inputs_before_execute' => $currentMissingInputs,
            'table_counts' => ctrip_input_readiness_table_summary(),
        ],
        'top_candidates' => array_slice($candidates, 0, min($options['limit'], 50)),
    ], 0, $options['format']);
} catch (Throwable $e) {
    ctrip_input_readiness_finish([
        'status' => 'failed',
        'error' => $e->getMessage(),
        'database_written' => false,
        'auto_write_ota' => false,
    ], 1, 'json');
}
