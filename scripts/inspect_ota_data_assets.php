<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ota_data_inventory_common.php';

try {
    $options = ota_inventory_parse_options($argv, [
        'format' => 'markdown',
        'limit' => '30',
    ]);
    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }
    $limit = max(5, min(100, (int)$options['limit']));
    $pdo = ota_inventory_connect($root);

    $tables = [
        'online_daily_data',
        'platform_data_sources',
        'platform_data_sync_tasks',
        'platform_data_raw_records',
        'platform_data_sync_logs',
        'ota_ctrip_capture_runs',
        'ota_ctrip_capture_gaps',
        'ota_ctrip_metric_catalog',
        'ota_ctrip_metric_facts',
        'ota_ctrip_entity_snapshots',
        'ota_ctrip_orders',
        'ota_ctrip_reviews',
        'ota_ctrip_review_order_matches',
        'ota_meituan_orders',
        'ota_meituan_reviews',
        'ota_meituan_review_order_matches',
    ];
    $tableRows = [];
    foreach ($tables as $table) {
        $count = ota_inventory_count_table($pdo, $table);
        $tableRows[] = ['table' => $table, 'rows' => $count === null ? 'missing' : $count];
    }

    $summary = [
        'generated_at' => date('Y-m-d H:i:s'),
        'scope' => 'ota_channel_only',
        'tables' => $tableRows,
        'active_hotels' => ota_inventory_table_exists($pdo, 'hotels')
            ? (int)ota_inventory_scalar($pdo, "SELECT COUNT(*) FROM hotels WHERE COALESCE(status, 1) = 1")
            : null,
        'online_daily_summary' => ota_inventory_table_exists($pdo, 'online_daily_data')
            ? ota_inventory_query_one($pdo, "
                SELECT COUNT(*) AS total_rows,
                       COUNT(DISTINCT system_hotel_id) AS system_hotels,
                       COUNT(DISTINCT data_date) AS data_dates,
                       MIN(data_date) AS min_data_date,
                       MAX(data_date) AS max_data_date,
                       MAX(update_time) AS latest_update_time
                FROM online_daily_data
            ")
            : [],
        'online_periods' => ota_inventory_table_exists($pdo, 'online_daily_data')
            ? ota_inventory_query_all($pdo, "
                SELECT source, COALESCE(platform, '') AS platform, data_period, is_final,
                       COUNT(*) AS rows_count, MIN(data_date) AS min_date,
                       MAX(data_date) AS max_date, MAX(update_time) AS latest_update
                FROM online_daily_data
                GROUP BY source, COALESCE(platform, ''), data_period, is_final
                ORDER BY rows_count DESC
                LIMIT {$limit}
            ")
            : [],
        'data_type_dictionary' => ota_inventory_table_exists($pdo, 'online_daily_data')
            ? ota_inventory_query_all($pdo, "
                SELECT source, COALESCE(platform, '') AS platform, COALESCE(NULLIF(data_type, ''), '(missing)') AS data_type,
                       COUNT(*) AS rows_count,
                       SUM(validation_status = 'normal') AS normal_rows,
                       SUM(validation_status = 'warning') AS warning_rows,
                       SUM(validation_status = 'abnormal') AS abnormal_rows,
                       MIN(data_date) AS min_date, MAX(data_date) AS max_date
                FROM online_daily_data
                GROUP BY source, COALESCE(platform, ''), COALESCE(NULLIF(data_type, ''), '(missing)')
                ORDER BY rows_count DESC
                LIMIT {$limit}
            ")
            : [],
        'configured_sources' => ota_inventory_table_exists($pdo, 'platform_data_sources')
            ? ota_inventory_query_all($pdo, "
                SELECT platform, data_type, ingestion_method, enabled, status,
                       COUNT(*) AS source_count, COUNT(DISTINCT system_hotel_id) AS hotel_count,
                       MAX(last_sync_time) AS latest_sync_time,
                       MAX(update_time) AS latest_update_time
                FROM platform_data_sources
                GROUP BY platform, data_type, ingestion_method, enabled, status
                ORDER BY platform, data_type, ingestion_method, status
            ")
            : [],
        'ctrip_facts' => ota_inventory_table_exists($pdo, 'ota_ctrip_metric_facts')
            ? ota_inventory_query_all($pdo, "
                SELECT capture_section, data_type, capture_status, COUNT(*) AS rows_count,
                       COUNT(DISTINCT metric_key) AS metric_count,
                       MIN(data_date) AS min_date, MAX(data_date) AS max_date,
                       MAX(captured_at) AS latest_captured_at
                FROM ota_ctrip_metric_facts
                GROUP BY capture_section, data_type, capture_status
                ORDER BY rows_count DESC
                LIMIT {$limit}
            ")
            : [],
        'duplicates' => ota_inventory_online_duplicate_summary($pdo),
        'anomalies' => ota_inventory_online_anomaly_summary($pdo),
        'save_entries' => [
            ['entry' => 'POST /api/online-data/auto-fetch', 'purpose' => 'manual or page-triggered OTA save', 'writes' => 'online_daily_data'],
            ['entry' => 'php think online-data:auto-fetch', 'purpose' => 'scheduled historical and realtime save', 'writes' => 'online_daily_data + platform sync state'],
            ['entry' => 'POST /api/online-data/capture-ctrip-browser', 'purpose' => 'authorized Ctrip profile capture', 'writes' => 'online_daily_data + ota_ctrip_* facts'],
            ['entry' => 'POST /api/online-data/capture-meituan-browser', 'purpose' => 'authorized Meituan profile capture', 'writes' => 'online_daily_data'],
            ['entry' => 'php scripts/verify_ota_daily_save_plan.php', 'purpose' => 'read-only daily save and backfill readiness check', 'writes' => 'none'],
        ],
    ];

    if ($options['format'] === 'json') {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo '# OTA Data Asset Inventory' . PHP_EOL . PHP_EOL;
    echo '- generated_at: `' . $summary['generated_at'] . '`' . PHP_EOL;
    echo '- scope: `ota_channel_only`; do not treat OTA rows as whole-hotel operating truth.' . PHP_EOL;
    echo '- active_hotels: `' . ($summary['active_hotels'] ?? 'unknown') . '`' . PHP_EOL . PHP_EOL;

    echo '## Main Storage Tables' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['table', 'rows'], $summary['tables']) . PHP_EOL . PHP_EOL;

    echo '## online_daily_data Summary' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['metric', 'value'], array_map(
        static fn(string $key, mixed $value): array => ['metric' => $key, 'value' => $value],
        array_keys($summary['online_daily_summary']),
        array_values($summary['online_daily_summary'])
    )) . PHP_EOL . PHP_EOL;

    echo '## Period Coverage' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['source', 'platform', 'data_period', 'is_final', 'rows_count', 'min_date', 'max_date', 'latest_update'], $summary['online_periods']) . PHP_EOL . PHP_EOL;

    echo '## Data Type Dictionary' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['source', 'platform', 'data_type', 'rows_count', 'normal_rows', 'warning_rows', 'abnormal_rows', 'min_date', 'max_date'], $summary['data_type_dictionary']) . PHP_EOL . PHP_EOL;

    echo '## Configured Sources' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['platform', 'data_type', 'ingestion_method', 'enabled', 'status', 'source_count', 'hotel_count', 'latest_sync_time', 'latest_update_time'], $summary['configured_sources']) . PHP_EOL . PHP_EOL;

    echo '## Ctrip Fact Evidence' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['capture_section', 'data_type', 'capture_status', 'rows_count', 'metric_count', 'min_date', 'max_date', 'latest_captured_at'], $summary['ctrip_facts']) . PHP_EOL . PHP_EOL;

    echo '## Quality Signals' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['metric', 'value'], array_merge(
        array_map(static fn(string $key, mixed $value): array => ['metric' => 'duplicate_' . $key, 'value' => $value], array_keys($summary['duplicates']), array_values($summary['duplicates'])),
        array_map(static fn(string $key, mixed $value): array => ['metric' => $key, 'value' => $value], array_keys($summary['anomalies']), array_values($summary['anomalies']))
    )) . PHP_EOL . PHP_EOL;

    echo '## Save Entries' . PHP_EOL . PHP_EOL;
    echo ota_inventory_markdown_table(['entry', 'purpose', 'writes'], $summary['save_entries']) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ota data asset inspection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
