<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Shanghai');

$root = dirname(__DIR__);
require $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'ota_data_inventory_common.php';

function ota_daily_source_condition(string $platform): string
{
    return match ($platform) {
        'ctrip' => "(LOWER(source) = 'ctrip' OR LOWER(platform) = 'ctrip')",
        'meituan' => "(LOWER(source) = 'meituan' OR LOWER(platform) = 'meituan')",
        'qunar' => "(LOWER(source) = 'qunar' OR LOWER(platform) = 'qunar')",
        default => '1 = 0',
    };
}

function ota_daily_configured_hotels(PDO $pdo, string $platform): array
{
    if (!ota_inventory_table_exists($pdo, 'platform_data_sources')) {
        return [];
    }

    $rows = ota_inventory_query_all($pdo, "
        SELECT DISTINCT system_hotel_id
        FROM platform_data_sources
        WHERE enabled = 1
          AND system_hotel_id IS NOT NULL
          AND LOWER(platform) = ?
          AND status IN ('ready', 'success', 'partial_success')
        ORDER BY system_hotel_id
    ", [$platform]);

    return array_values(array_map(static fn(array $row): int => (int)$row['system_hotel_id'], $rows));
}

function ota_daily_observed_hotels(PDO $pdo, string $platform, string $startDate, string $endDate): array
{
    if (!ota_inventory_table_exists($pdo, 'online_daily_data')) {
        return [];
    }

    $rows = ota_inventory_query_all($pdo, "
        SELECT DISTINCT system_hotel_id
        FROM online_daily_data
        WHERE " . ota_daily_source_condition($platform) . "
          AND system_hotel_id IS NOT NULL
          AND data_date BETWEEN ? AND ?
        ORDER BY system_hotel_id
    ", [$startDate, $endDate]);

    return array_values(array_map(static fn(array $row): int => (int)$row['system_hotel_id'], $rows));
}

try {
    $options = ota_inventory_parse_options($argv, [
        'format' => 'markdown',
        'date' => '',
        'days' => '30',
        'strict' => false,
    ]);
    if (!in_array((string)$options['format'], ['json', 'markdown'], true)) {
        throw new InvalidArgumentException('Invalid --format, expected json or markdown.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    $targetDate = (string)$options['date'];
    if ($targetDate === '') {
        $targetDate = $now->modify('-1 day')->format('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        throw new InvalidArgumentException('Invalid --date, expected YYYY-MM-DD.');
    }

    $days = max(1, min(180, (int)$options['days']));
    $endDate = $targetDate;
    $startDate = (new DateTimeImmutable($targetDate, new DateTimeZone('Asia/Shanghai')))
        ->modify('-' . ($days - 1) . ' days')
        ->format('Y-m-d');
    $dateList = ota_inventory_date_list($startDate, $endDate);
    $platforms = ['ctrip', 'meituan'];
    $pdo = ota_inventory_connect($root);

    $issues = [];
    if (!ota_inventory_table_exists($pdo, 'online_daily_data')) {
        $issues[] = ['severity' => 'error', 'code' => 'online_daily_data_missing', 'message' => 'online_daily_data table does not exist.'];
    }

    $targetRows = [];
    $gapRows = [];
    foreach ($platforms as $platform) {
        $configuredHotels = ota_daily_configured_hotels($pdo, $platform);
        $observedHotels = ota_daily_observed_hotels($pdo, $platform, $startDate, $endDate);
        $hotelIds = array_values(array_unique(array_merge($configuredHotels, $observedHotels)));
        sort($hotelIds);

        $condition = ota_daily_source_condition($platform);
        $target = ota_inventory_query_one($pdo, "
            SELECT COUNT(*) AS rows_count,
                   COUNT(DISTINCT system_hotel_id) AS hotel_count,
                   SUM(data_type = 'traffic') AS traffic_rows,
                   SUM(validation_status = 'abnormal') AS abnormal_rows,
                   SUM(validation_status = 'warning') AS warning_rows,
                   MAX(update_time) AS latest_update_time
            FROM online_daily_data
            WHERE {$condition}
              AND data_period = 'historical_daily'
              AND is_final = 1
              AND data_date = ?
        ", [$targetDate]);
        $targetRows[] = [
            'platform' => $platform,
            'target_date' => $targetDate,
            'configured_hotels' => count($configuredHotels),
            'observed_hotels_in_window' => count($observedHotels),
            'target_hotels' => (int)($target['hotel_count'] ?? 0),
            'target_rows' => (int)($target['rows_count'] ?? 0),
            'traffic_rows' => (int)($target['traffic_rows'] ?? 0),
            'warning_rows' => (int)($target['warning_rows'] ?? 0),
            'abnormal_rows' => (int)($target['abnormal_rows'] ?? 0),
            'latest_update_time' => (string)($target['latest_update_time'] ?? ''),
        ];

        $targetHotelCount = (int)($target['hotel_count'] ?? 0);
        $configuredHotelCount = count($configuredHotels);
        if ($configuredHotelCount > 0 && (int)($target['rows_count'] ?? 0) === 0) {
            $issues[] = [
                'severity' => $now->format('H') < 8 ? 'warning' : 'error',
                'code' => $platform . '_target_historical_missing',
                'message' => $platform . ' has configured sources but no historical_daily rows for target date ' . $targetDate . '.',
            ];
        }
        if ($configuredHotelCount > 0 && $targetHotelCount > 0 && $targetHotelCount < $configuredHotelCount) {
            $issues[] = [
                'severity' => $now->format('H') < 8 ? 'warning' : 'error',
                'code' => $platform . '_target_historical_partial',
                'message' => $platform . ' target date historical rows cover ' . $targetHotelCount . ' of ' . $configuredHotelCount . ' configured hotels.',
            ];
        }

        foreach ($hotelIds as $hotelId) {
            $savedDates = ota_inventory_query_all($pdo, "
                SELECT DISTINCT data_date
                FROM online_daily_data
                WHERE {$condition}
                  AND system_hotel_id = ?
                  AND data_period = 'historical_daily'
                  AND is_final = 1
                  AND data_date BETWEEN ? AND ?
                ORDER BY data_date
            ", [$hotelId, $startDate, $endDate]);
            $saved = array_fill_keys(array_map(static fn(array $row): string => (string)$row['data_date'], $savedDates), true);
            $missing = array_values(array_filter($dateList, static fn(string $date): bool => !isset($saved[$date])));
            if ($missing === []) {
                continue;
            }
            $gapRows[] = [
                'platform' => $platform,
                'system_hotel_id' => $hotelId,
                'checked_days' => count($dateList),
                'saved_days' => count($saved),
                'missing_days' => count($missing),
                'first_missing' => $missing[0],
                'last_missing' => $missing[count($missing) - 1],
                'gap_type' => in_array($hotelId, $configuredHotels, true) ? 'configured_source_gap' : 'observed_history_gap',
            ];
        }
    }

    $duplicates = ota_inventory_online_duplicate_summary($pdo);
    $anomalies = ota_inventory_online_anomaly_summary($pdo);
    if ((int)($duplicates['extra_rows'] ?? 0) > 0) {
        $issues[] = ['severity' => 'error', 'code' => 'business_key_duplicates', 'message' => 'online_daily_data contains duplicate business-key rows.'];
    }
    foreach (['invalid_raw_json', 'future_date_rows'] as $key) {
        if ((int)($anomalies[$key] ?? 0) > 0) {
            $issues[] = ['severity' => 'error', 'code' => $key, 'message' => 'online_daily_data has ' . $key . '.'];
        }
    }

    $result = [
        'checked_at' => $now->format('Y-m-d H:i:s'),
        'scope' => 'ota_channel_only',
        'target_date' => $targetDate,
        'history_window' => ['start_date' => $startDate, 'end_date' => $endDate, 'days' => $days],
        'early_morning_policy' => [
            'active' => (int)$now->format('H') < 8,
            'rule' => 'Before 08:00, yesterday final OTA data may be unavailable; display latest previously saved historical_daily rows with collection time.',
        ],
        'target_rows' => $targetRows,
        'history_gap_sample' => array_slice($gapRows, 0, 50),
        'history_gap_total' => count($gapRows),
        'duplicates' => $duplicates,
        'anomalies' => $anomalies,
        'issues' => $issues,
    ];

    if ($options['format'] === 'json') {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        echo '# OTA Daily Save Plan Check' . PHP_EOL . PHP_EOL;
        echo '- checked_at: `' . $result['checked_at'] . '`' . PHP_EOL;
        echo '- scope: `ota_channel_only`' . PHP_EOL;
        echo '- target_date: `' . $targetDate . '`' . PHP_EOL;
        echo '- history_window: `' . $startDate . '` to `' . $endDate . '`' . PHP_EOL;
        echo '- early_morning_policy_active: `' . ($result['early_morning_policy']['active'] ? 'yes' : 'no') . '`' . PHP_EOL . PHP_EOL;

        echo '## Target Historical Save' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(['platform', 'target_date', 'configured_hotels', 'observed_hotels_in_window', 'target_hotels', 'target_rows', 'traffic_rows', 'warning_rows', 'abnormal_rows', 'latest_update_time'], $targetRows) . PHP_EOL . PHP_EOL;

        echo '## History Gap Sample' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(['platform', 'system_hotel_id', 'checked_days', 'saved_days', 'missing_days', 'first_missing', 'last_missing', 'gap_type'], array_slice($gapRows, 0, 20)) . PHP_EOL . PHP_EOL;

        echo '## Quality Gate' . PHP_EOL . PHP_EOL;
        echo ota_inventory_markdown_table(['metric', 'value'], array_merge(
            array_map(static fn(string $key, mixed $value): array => ['metric' => 'duplicate_' . $key, 'value' => $value], array_keys($duplicates), array_values($duplicates)),
            array_map(static fn(string $key, mixed $value): array => ['metric' => $key, 'value' => $value], array_keys($anomalies), array_values($anomalies)),
            [['metric' => 'history_gap_total', 'value' => count($gapRows)]]
        )) . PHP_EOL . PHP_EOL;

        echo '## Issues' . PHP_EOL . PHP_EOL;
        echo $issues === []
            ? 'No blocking issues found.' . PHP_EOL
            : ota_inventory_markdown_table(['severity', 'code', 'message'], $issues) . PHP_EOL;
    }

    $hasError = array_reduce($issues, static fn(bool $carry, array $issue): bool => $carry || ($issue['severity'] ?? '') === 'error', false);
    exit($hasError && !empty($options['strict']) ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ota daily save plan check failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
