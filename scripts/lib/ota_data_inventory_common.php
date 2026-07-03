<?php
declare(strict_types=1);

function ota_inventory_read_env_map(string $path): array
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
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            $map[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }
    }

    return $map;
}

function ota_inventory_env_value(array $env, array $names, string $default = ''): string
{
    foreach ($names as $name) {
        if (isset($env[$name]) && $env[$name] !== '') {
            return (string)$env[$name];
        }
    }

    return $default;
}

function ota_inventory_connect(string $root): PDO
{
    $env = ota_inventory_read_env_map($root . DIRECTORY_SEPARATOR . '.env');
    $host = ota_inventory_env_value($env, ['DB_HOST', 'DATABASE_HOSTNAME', 'HOSTNAME'], '127.0.0.1');
    $port = ota_inventory_env_value($env, ['DB_PORT', 'DATABASE_PORT', 'HOSTPORT'], '3306');
    $database = ota_inventory_env_value($env, ['DB_NAME', 'DB_DATABASE', 'DATABASE']);
    $user = ota_inventory_env_value($env, ['DB_USER', 'DB_USERNAME', 'USERNAME'], 'root');
    $password = ota_inventory_env_value($env, ['DB_PASS', 'DB_PASSWORD', 'PASSWORD']);
    if ($database === '') {
        throw new RuntimeException('DB_NAME is empty');
    }

    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function ota_inventory_parse_options(array $argv, array $defaults): array
{
    $options = $defaults;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $options['format'] = 'json';
            continue;
        }
        if ($arg === '--markdown') {
            $options['format'] = 'markdown';
            continue;
        }
        if ($arg === '--strict') {
            $options['strict'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $options[$key] = trim($value);
    }

    return $options;
}

function ota_inventory_query_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function ota_inventory_query_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ota_inventory_scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function ota_inventory_table_exists(PDO $pdo, string $table): bool
{
    return ota_inventory_scalar($pdo, 'SHOW TABLES LIKE ?', [$table]) !== false;
}

function ota_inventory_count_table(PDO $pdo, string $table): ?int
{
    if (!ota_inventory_table_exists($pdo, $table)) {
        return null;
    }

    return (int)ota_inventory_scalar($pdo, "SELECT COUNT(*) FROM `{$table}`");
}

function ota_inventory_online_duplicate_summary(PDO $pdo): array
{
    if (!ota_inventory_table_exists($pdo, 'online_daily_data')) {
        return ['duplicate_groups' => 0, 'rows_in_duplicate_groups' => 0, 'extra_rows' => 0];
    }

    return ota_inventory_query_one($pdo, "
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
    ");
}

function ota_inventory_online_anomaly_summary(PDO $pdo): array
{
    if (!ota_inventory_table_exists($pdo, 'online_daily_data')) {
        return [];
    }

    return ota_inventory_query_one($pdo, "
        SELECT SUM(source IS NULL OR TRIM(source) = '') AS missing_source,
               SUM(data_type IS NULL OR TRIM(data_type) = '') AS missing_data_type,
               SUM(hotel_id IS NULL OR TRIM(hotel_id) = '') AS missing_hotel_id,
               SUM(system_hotel_id IS NULL) AS missing_system_hotel_id,
               SUM(data_date > CURDATE()) AS future_date_rows,
               SUM(raw_data IS NULL OR TRIM(raw_data) = '') AS missing_raw_data,
               SUM(raw_data IS NOT NULL AND TRIM(raw_data) <> '' AND JSON_VALID(raw_data) = 0) AS invalid_raw_json,
               SUM(amount < 0 OR quantity < 0 OR book_order_num < 0 OR data_value < 0
                   OR list_exposure < 0 OR detail_exposure < 0 OR flow_rate < 0
                   OR order_filling_num < 0 OR order_submit_num < 0) AS negative_numeric_rows,
               SUM(flow_rate > 100) AS flow_rate_over_100_rows,
               SUM(comment_score < 0 OR comment_score > 5 OR qunar_comment_score < 0 OR qunar_comment_score > 5) AS score_out_of_range_rows,
               SUM(data_type = 'business' AND amount > 0 AND quantity = 0) AS business_amount_without_quantity_rows
        FROM online_daily_data
    ");
}

function ota_inventory_markdown_table(array $headers, array $rows): string
{
    $escape = static function (mixed $value): string {
        $text = trim((string)($value ?? ''));
        $text = str_replace(["\r", "\n"], ' ', $text);
        return str_replace('|', '\\|', $text === '' ? '-' : $text);
    };

    $lines = [];
    $lines[] = '| ' . implode(' | ', array_map($escape, $headers)) . ' |';
    $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';
    foreach ($rows as $row) {
        $cells = [];
        foreach ($headers as $header) {
            $cells[] = $escape(is_array($row) ? ($row[$header] ?? '') : '');
        }
        $lines[] = '| ' . implode(' | ', $cells) . ' |';
    }

    return implode(PHP_EOL, $lines);
}

function ota_inventory_date_list(string $startDate, string $endDate): array
{
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, new DateTimeZone('Asia/Shanghai'));
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate, new DateTimeZone('Asia/Shanghai'));
    if (!$start || !$end || $start > $end) {
        return [];
    }

    $dates = [];
    for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
        $dates[] = $cursor->format('Y-m-d');
    }

    return $dates;
}
