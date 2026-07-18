<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

function read_env_map(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('.env not found: ' . $path);
    }
    $map = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '[')) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            continue;
        }
        $map[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
    }
    return $map;
}

function env_value(array $env, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        if (isset($env[$name]) && $env[$name] !== '') {
            return (string)$env[$name];
        }
    }
    return $default;
}

function query_one(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    if (!$stmt) {
        throw new RuntimeException('SQL failed: ' . $sql);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function query_all(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    if (!$stmt) {
        throw new RuntimeException('SQL failed: ' . $sql);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$strict = in_array('--strict', $argv, true);
$json = in_array('--json', $argv, true);

try {
    $root = dirname(__DIR__);
    $env = read_env_map($root . DIRECTORY_SEPARATOR . '.env');
    $host = env_value($env, ['DB_HOST', 'DATABASE_HOSTNAME', 'HOSTNAME'], '127.0.0.1');
    $port = env_value($env, ['DB_PORT', 'DATABASE_PORT', 'HOSTPORT'], '3306');
    $database = env_value($env, ['DB_NAME', 'DB_DATABASE', 'DATABASE']);
    $user = env_value($env, ['DB_USER', 'DB_USERNAME', 'USERNAME'], 'root');
    $password = env_value($env, ['DB_PASS', 'DB_PASSWORD', 'PASSWORD']);
    if ($database === '') {
        throw new RuntimeException('DB_NAME is empty');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $columns = query_all($pdo, "
        SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'online_daily_data'
    ");
    if (!$columns) {
        throw new RuntimeException('online_daily_data table does not exist');
    }
    $columnMap = [];
    foreach ($columns as $column) {
        $columnMap[(string)$column['COLUMN_NAME']] = $column;
    }
    $dimensionLength = (int)($columnMap['dimension']['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    $validationStatusLength = (int)($columnMap['validation_status']['CHARACTER_MAXIMUM_LENGTH'] ?? 0);

    $summary = [
        'checked_at' => date('Y-m-d H:i:s'),
        'database' => $database,
        'strict' => $strict,
        'column_lengths' => [
            'dimension' => $dimensionLength,
            'validation_status' => $validationStatusLength,
        ],
        'counts' => query_one($pdo, "
            SELECT COUNT(*) AS total_rows,
                   COUNT(DISTINCT system_hotel_id) AS system_hotels,
                   COUNT(DISTINCT data_date) AS data_dates,
                   MIN(data_date) AS min_data_date,
                   MAX(data_date) AS max_data_date,
                   MAX(update_time) AS latest_update_time
            FROM online_daily_data
        "),
        'periods' => query_all($pdo, "
            SELECT data_period, COALESCE(NULLIF(snapshot_bucket, ''), '(empty)') AS snapshot_bucket,
                   is_final, COUNT(*) AS rows_count, MIN(data_date) AS min_data_date,
                   MAX(data_date) AS max_data_date, MAX(update_time) AS latest_update_time
            FROM online_daily_data
            GROUP BY data_period, COALESCE(NULLIF(snapshot_bucket, ''), '(empty)'), is_final
            ORDER BY rows_count DESC
            LIMIT 20
        "),
        'duplicate_source_trace' => query_one($pdo, "
            SELECT COUNT(*) AS duplicate_groups,
                   COALESCE(SUM(cnt), 0) AS rows_in_duplicate_groups,
                   COALESCE(SUM(cnt - 1), 0) AS extra_rows
            FROM (
              SELECT source_trace_id, COUNT(*) AS cnt
              FROM online_daily_data
              WHERE source_trace_id IS NOT NULL AND TRIM(source_trace_id) <> ''
              GROUP BY source_trace_id
              HAVING COUNT(*) > 1
            ) x
        "),
        'duplicate_business_key' => query_one($pdo, "
            SELECT COUNT(*) AS duplicate_groups,
                   COALESCE(SUM(cnt), 0) AS rows_in_duplicate_groups,
                   COALESCE(SUM(cnt - 1), 0) AS extra_rows
            FROM (
              SELECT COALESCE(source, '') AS source_key,
                     COALESCE(platform, '') AS platform_key,
                     COALESCE(data_type, '') AS data_type_key,
                     COALESCE(dimension, '') AS dimension_key,
                     COALESCE(compare_type, '') AS compare_type_key,
                     data_date,
                     COALESCE(data_period, '') AS period_key,
                     COALESCE(snapshot_bucket, '') AS bucket_key,
                     COALESCE(system_hotel_id, 0) AS system_hotel_key,
                     COALESCE(NULLIF(hotel_id, ''), CONCAT('name:', COALESCE(hotel_name, ''))) AS hotel_key,
                     COUNT(*) AS cnt
              FROM online_daily_data
              GROUP BY source_key, platform_key, data_type_key, dimension_key, compare_type_key,
                       data_date, period_key, bucket_key, system_hotel_key, hotel_key
              HAVING COUNT(*) > 1
            ) x
        "),
        'anomalies' => query_one($pdo, "
            SELECT SUM(source IS NULL OR TRIM(source) = '') AS missing_source,
                   SUM(data_type IS NULL OR TRIM(data_type) = '') AS missing_data_type,
                   SUM(hotel_id IS NULL OR TRIM(hotel_id) = '') AS missing_hotel_id,
                   SUM(system_hotel_id IS NULL) AS missing_system_hotel_id,
                    SUM(data_date > CURDATE()) AS future_date_rows,
                    SUM(data_date > CURDATE() AND (
                        data_type = 'traffic_forecast'
                        OR data_period IN ('next_7_days', 'next_30_days', 'forecast', 'future_forecast')
                    )) AS allowed_future_forecast_rows,
                    SUM(data_date > CURDATE() AND NOT (
                        data_type = 'traffic_forecast'
                        OR data_period IN ('next_7_days', 'next_30_days', 'forecast', 'future_forecast')
                    )) AS invalid_future_date_rows,
                    SUM(data_period = 'next_30_days'
                        AND COALESCE(snapshot_time, create_time, update_time) IS NOT NULL
                        AND data_date > DATE_ADD(DATE(COALESCE(snapshot_time, create_time, update_time)), INTERVAL 30 DAY)
                    ) AS forecast_rows_beyond_declared_window,
                   SUM(data_date = CURDATE()) AS today_rows,
                   SUM(raw_data IS NULL OR TRIM(raw_data) = '') AS missing_raw_data,
                   SUM(raw_data IS NOT NULL AND TRIM(raw_data) <> '' AND JSON_VALID(raw_data) = 0) AS invalid_raw_json,
                   SUM(amount < 0 OR quantity < 0 OR book_order_num < 0 OR data_value < 0
                       OR list_exposure < 0 OR detail_exposure < 0 OR flow_rate < 0
                       OR order_filling_num < 0 OR order_submit_num < 0) AS negative_numeric_rows,
                   SUM(flow_rate > 100) AS flow_rate_over_100_rows,
                   SUM(comment_score < 0 OR comment_score > 5 OR qunar_comment_score < 0 OR qunar_comment_score > 5) AS score_out_of_range_rows,
                   SUM(data_type = 'business' AND amount > 0 AND quantity = 0) AS business_amount_without_quantity_rows,
                   SUM(CHAR_LENGTH(COALESCE(dimension, '')) >= {$dimensionLength}) AS dimension_at_limit_rows,
                   SUM(CHAR_LENGTH(COALESCE(validation_status, '')) >= {$validationStatusLength}) AS validation_status_at_limit_rows
            FROM online_daily_data
        "),
    ];

    $errors = [];
    if ($dimensionLength < 255) {
        $errors[] = 'online_daily_data.dimension must be at least varchar(255).';
    }
    if ($validationStatusLength < 60) {
        $errors[] = 'online_daily_data.validation_status must be at least varchar(60).';
    }
    if ((int)($summary['anomalies']['invalid_raw_json'] ?? 0) > 0) {
        $errors[] = 'online_daily_data contains invalid raw_data JSON.';
    }
    if ((int)($summary['anomalies']['invalid_future_date_rows'] ?? 0) > 0) {
        $errors[] = 'online_daily_data contains non-forecast future data_date rows.';
    }
    if ($strict && (int)($summary['duplicate_business_key']['extra_rows'] ?? 0) > 0) {
        $errors[] = 'online_daily_data contains duplicate business-key rows.';
    }

    $summary['errors'] = $errors;
    if ($json) {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo 'online_daily_data health: ' . ($errors ? 'failed' : 'ok') . PHP_EOL;
        echo 'total_rows=' . ($summary['counts']['total_rows'] ?? 0)
            . ' data_dates=' . ($summary['counts']['data_dates'] ?? 0)
            . ' latest_update=' . ($summary['counts']['latest_update_time'] ?? '-') . PHP_EOL;
        echo 'dimension_length=' . $dimensionLength
            . ' validation_status_length=' . $validationStatusLength . PHP_EOL;
        echo 'business_duplicate_extra_rows=' . ($summary['duplicate_business_key']['extra_rows'] ?? 0)
            . ' trace_duplicate_extra_rows=' . ($summary['duplicate_source_trace']['extra_rows'] ?? 0) . PHP_EOL;
        echo 'invalid_raw_json=' . ($summary['anomalies']['invalid_raw_json'] ?? 0)
            . ' future_date_rows=' . ($summary['anomalies']['future_date_rows'] ?? 0)
            . ' allowed_future_forecast_rows=' . ($summary['anomalies']['allowed_future_forecast_rows'] ?? 0)
            . ' invalid_future_date_rows=' . ($summary['anomalies']['invalid_future_date_rows'] ?? 0)
            . ' forecast_rows_beyond_declared_window=' . ($summary['anomalies']['forecast_rows_beyond_declared_window'] ?? 0)
            . ' dimension_at_limit_rows=' . ($summary['anomalies']['dimension_at_limit_rows'] ?? 0) . PHP_EOL;
        foreach ($errors as $error) {
            echo 'ERROR: ' . $error . PHP_EOL;
        }
    }
    exit($errors ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'online_daily_data health check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
