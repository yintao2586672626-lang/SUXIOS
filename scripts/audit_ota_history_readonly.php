<?php
declare(strict_types=1);

// Read-only, aggregate-only diagnostics. Never print raw_data, account material,
// guest data or database configuration. No source requests or business writes.
require dirname(__DIR__) . '/vendor/autoload.php';

use think\App;
use think\facade\Db;

(new App(dirname(__DIR__)))->initialize();
$columns = array_fill_keys(Db::name('online_daily_data')->getTableFields(), true);
if (in_array('--order-scope', $argv, true)) {
    $options = getopt('', ['order-scope', 'hotel:', 'date-from:', 'date-to:']);
    $hotelId = (int)($options['hotel'] ?? 0);
    $from = (string)($options['date-from'] ?? '');
    $to = (string)($options['date-to'] ?? '');
    $hotel = $hotelId > 0 ? Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id')->find() : null;
    if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('--hotel must name an existing scoped hotel');
    }
    // The existing read service validates the range. Output only aggregate metadata.
    $analysis = (new \app\service\CtripOrderAnalysisService())->analyzeStoredRange(
        $hotelId, (int)$hotel['tenant_id'], $from, $to
    );
    $orderQuery = Db::name('online_daily_data')
        ->where('tenant_id', (int)$hotel['tenant_id'])->where('system_hotel_id', $hotelId)
        ->where('source', 'ctrip')->where('data_type', 'order')->whereBetween('data_date', [$from, $to]);
    $groups = (clone $orderQuery)
        ->field('ingestion_method,readback_verified,COUNT(*) AS stored_rows,MIN(data_date) AS earliest,MAX(data_date) AS latest')
        ->group('ingestion_method,readback_verified')->select()->toArray();
    $shapeFields = ['id', 'platform', 'data_date', 'sync_task_id', 'data_source_id',
        "JSON_KEYS(JSON_EXTRACT(IF(JSON_VALID(raw_data),raw_data,'{}'),'$.row')) AS canonical_keys",
        "JSON_KEYS(JSON_EXTRACT(IF(JSON_VALID(raw_data),raw_data,'{}'),'$.row.raw_data')) AS detail_keys",
    ];
    foreach ([
        'outer_contract' => '$.import_contract', 'wrapped_contract' => '$.row.raw_data.import_contract',
        'canonical_contract' => '$.raw_data.import_contract', 'canonical_platform' => '$.row.platform',
        'canonical_type' => '$.row.data_type', 'amount_semantics' => '$.row.raw_data.amount_semantics',
        'pii_policy' => '$.row.raw_data.pii_policy', 'record_kind' => '$.row.raw_data.record_kind',
        'fixture_status' => '$.row.raw_data.fixture_status', 'channel_key' => '$.row.raw_data.channel_key',
        'gross_orders' => '$.row.gross_order_num', 'active_orders' => '$.row.book_order_num',
        'room_nights' => '$.row.quantity', 'date_basis' => '$.row.raw_data.business_date_basis',
    ] as $alias => $path) {
        $shapeFields[] = "JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(raw_data),raw_data,'{}'),'{$path}')) AS {$alias}";
    }
    $shapes = (clone $orderQuery)->field(implode(',', $shapeFields))->limit(10)->select()->toArray();
    echo json_encode([
        'mode' => 'read_only_order_scope', 'hotel_id' => $hotelId, 'platform' => 'ctrip',
        'date_from' => $from, 'date_to' => $to, 'stored_groups' => $groups, 'contract_shapes' => $shapes,
        'detailed_analysis' => array_intersect_key($analysis, array_flip(['status', 'date_range', 'batch', 'note'])),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
if (in_array('--snapshot-query', $argv, true)) {
    $options = getopt('', ['snapshot-query', 'hotel:']);
    $hotelId = filter_var($options['hotel'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($hotelId === false || $hotelId === null) {
        throw new InvalidArgumentException('--hotel requires a positive system hotel ID');
    }
    $subject = new class { use \app\controller\concern\OnlineDataHistoryConcern; };
    $pageMethod = new ReflectionMethod($subject, 'buildOnlineHistoryDatabasePagination');
    $scopeMethod = new ReflectionMethod($subject, 'applyOnlineHistoryGroupKeyScope');
    $mergeMethod = new ReflectionMethod($subject, 'mergeOnlineHistoryRows');
    $keyMethod = new ReflectionMethod($subject, 'buildOnlineHistoryMergeKey');
    $report = [];
    foreach (['ctrip', 'meituan'] as $platform) {
        $stage = 'legacy_count';
        try {
        $query = Db::name('online_daily_data')->where('system_hotel_id', $hotelId)->where('source', $platform)
            ->whereBetween('data_date', [date('Y-m-d', strtotime('-45 days')), date('Y-m-d')]);
        $before = microtime(true);
        $legacyCount = isset($columns['history_group_key'])
            ? (int)(clone $query)->count('DISTINCT history_group_key') : null;
        $legacyMs = round((microtime(true) - $before) * 1000, 1);
        $before = microtime(true);
        $stage = 'snapshot_pagination';
        $plan = $pageMethod->invoke($subject, clone $query, $columns, 1, 20);
        $stage = 'snapshot_hydration';
        $rows = $scopeMethod->invoke($subject, clone $query,
            $plan['group_key_expression'], $plan['group_keys'])->order('id', 'desc')->select()->toArray();
        $groups = $mergeMethod->invoke($subject, $rows, []);
        $actualKeys = array_map(fn(array $row): string => $keyMethod->invoke($subject, $row), $groups);
        if (isset($columns['history_group_key'])) {
            $actualKeys = array_map(static function (string $key): string {
                $parts = explode('|snapshot@', $key, 2);
                return hash('sha256', $parts[0]) . (isset($parts[1]) ? '|snapshot@' . $parts[1] : '');
            }, $actualKeys);
        }
        $expectedKeys = $plan['group_keys'];
        sort($actualKeys);
        sort($expectedKeys);
        $report[] = [
            'system_hotel_id' => $hotelId, 'platform' => $platform,
            'start_date' => date('Y-m-d', strtotime('-45 days')), 'end_date' => date('Y-m-d'),
            'legacy_daily_groups' => $legacyCount, 'legacy_count_ms' => $legacyMs,
            'snapshot_groups' => $plan['total'], 'page_groups' => count($groups),
            'page_raw_rows' => count($rows), 'sql_php_group_keys_match' => $actualKeys === $expectedKeys,
            'key_mismatch_sample' => $actualKeys === $expectedKeys ? [] : [
                'sql' => array_slice(array_values(array_diff($expectedKeys, $actualKeys)), 0, 2),
                'php' => array_slice(array_values(array_diff($actualKeys, $expectedKeys)), 0, 2),
            ],
            'query_and_readback_ms' => round((microtime(true) - $before) * 1000, 1),
            'sample_identity' => array_map(static fn(array $row): array => array_intersect_key($row,
                array_flip(['id', 'system_hotel_id', 'source', 'data_date', 'data_period', 'sync_task_id', 'snapshot_time', 'history_status'])),
                array_slice($rows, 0, 3)),
        ];
        } catch (Throwable $error) {
            $report[] = ['platform' => $platform, 'stage' => $stage, 'status' => 'FAIL',
                'message' => (new \app\service\OperationAuditSanitizerService())->sanitizeText($error->getMessage(), 200)];
        }
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    $failures = array_filter($report, static fn(array $item): bool => ($item['status'] ?? '') === 'FAIL'
        || ($item['sql_php_group_keys_match'] ?? false) !== true);
    exit($failures === [] ? 0 : 1);
}
$select = ['system_hotel_id', 'source', 'data_type'];
$group = $select;
foreach (['data_period', 'is_final', 'readback_verified', 'history_status'] as $field) {
    if (isset($columns[$field])) {
        $select[] = $field;
        $group[] = $field;
    }
}
$query = Db::name('online_daily_data')->whereIn('source', ['ctrip', 'meituan']);
$bounds = (clone $query)->field('source, MIN(data_date) AS first_date, MAX(data_date) AS last_date, COUNT(*) AS row_count')
    ->group('source')->select()->toArray();
$recent = (clone $query)->where('data_date', '>=', date('Y-m-d', strtotime('-45 days')))
    ->field(implode(',', $select) . ', MIN(data_date) AS first_date, MAX(data_date) AS last_date, COUNT(DISTINCT data_date) AS days, COUNT(*) AS rows_count')
    ->group(implode(',', $group))->order('system_hotel_id,source,data_type')->limit(150)->select()->toArray();
echo json_encode([
    'generated_at' => date(DATE_ATOM), 'mode' => 'read_only_aggregate',
    'all_history_bounds' => $bounds, 'recent_45_day_groups_limit' => 150,
    'recent_groups' => $recent,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
