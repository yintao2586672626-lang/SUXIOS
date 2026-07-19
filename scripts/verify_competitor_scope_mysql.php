<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing.\n");
    exit(1);
}
require $autoload;

/** @return array<string, mixed> */
function first_row(string $sql): array
{
    $rows = Db::query($sql);
    return is_array($rows[0] ?? null) ? $rows[0] : [];
}

/** @param array<string, mixed> $row */
function int_value(array $row, string $key): int
{
    return (int)($row[$key] ?? 0);
}

try {
    (new App(dirname(__DIR__)))->initialize();

    $connection = first_row('SELECT DATABASE() AS database_name, VERSION() AS server_version');
    $databaseName = trim((string)($connection['database_name'] ?? ''));
    if ($databaseName === '') {
        throw new RuntimeException('No configured MySQL database is selected.');
    }

    $requiredColumns = [
        'competitor_hotel' => ['tenant_id'],
        'competitor_device' => [
            'tenant_id', 'user_id', 'store_id', 'platform', 'token_hash',
            'token_hint', 'token_version', 'revoked_at',
        ],
    ];
    $columnRows = Db::query(<<<'SQL'
        SELECT TABLE_NAME, COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('competitor_hotel', 'competitor_device')
        SQL);
    $actualColumns = [];
    foreach ($columnRows as $row) {
        $actualColumns[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = true;
    }

    $missingColumns = [];
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($actualColumns[$table][$column])) {
                $missingColumns[] = $table . '.' . $column;
            }
        }
    }

    $requiredIndexes = [
        'competitor_hotel.idx_competitor_hotel_tenant_store' => 'tenant_id,store_id,status',
        'competitor_device.uniq_competitor_device_scope' => 'device_id,platform,store_id',
        'competitor_device.idx_competitor_device_tenant_store' => 'tenant_id,store_id,status',
        'competitor_device.idx_competitor_device_user' => 'user_id,status',
    ];
    $indexRows = Db::query(<<<'SQL'
        SELECT TABLE_NAME, INDEX_NAME,
               GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('competitor_hotel', 'competitor_device')
        GROUP BY TABLE_NAME, INDEX_NAME
        SQL);
    $actualIndexes = [];
    foreach ($indexRows as $row) {
        $actualIndexes[(string)$row['TABLE_NAME'] . '.' . (string)$row['INDEX_NAME']]
            = (string)($row['index_columns'] ?? '');
    }
    $invalidIndexes = [];
    foreach ($requiredIndexes as $index => $expectedColumns) {
        if (($actualIndexes[$index] ?? null) !== $expectedColumns) {
            $invalidIndexes[$index] = [
                'expected' => $expectedColumns,
                'actual' => $actualIndexes[$index] ?? null,
            ];
        }
    }

    $hotelScope = $missingColumns === []
        ? first_row(<<<'SQL'
            SELECT COUNT(*) AS total_rows,
                   COALESCE(SUM(h.id IS NOT NULL AND h.tenant_id > 0
                       AND ch.tenant_id = h.tenant_id), 0) AS aligned_rows,
                   COALESCE(SUM(h.id IS NOT NULL AND h.tenant_id > 0
                       AND (ch.tenant_id IS NULL OR ch.tenant_id <> h.tenant_id)), 0) AS misaligned_rows,
                   COALESCE(SUM(h.id IS NULL OR h.tenant_id IS NULL OR h.tenant_id = 0), 0) AS orphan_or_invalid_rows,
                   COALESCE(SUM((h.id IS NULL OR h.tenant_id IS NULL OR h.tenant_id = 0)
                       AND ch.status <> 0), 0) AS active_orphan_or_invalid_rows,
                   COALESCE(SUM((h.id IS NULL OR h.tenant_id IS NULL OR h.tenant_id = 0)
                       AND ch.tenant_id IS NOT NULL), 0) AS bound_orphan_or_invalid_rows
            FROM competitor_hotel ch
            LEFT JOIN hotels h ON h.id = ch.store_id
            SQL)
        : [];

    $deviceScope = $missingColumns === []
        ? first_row(<<<'SQL'
            SELECT COUNT(*) AS total_rows,
                   COALESCE(SUM(status <> 0 AND (
                       tenant_id IS NULL OR user_id IS NULL OR store_id IS NULL
                       OR platform = '' OR token_hash = ''
                   )), 0) AS active_incomplete_binding_rows,
                   COALESCE(SUM(status = 0 AND (
                       tenant_id IS NULL OR user_id IS NULL OR store_id IS NULL
                       OR platform = '' OR token_hash = ''
                   ) AND revoked_at IS NULL), 0) AS disabled_without_revocation_rows
            FROM competitor_device
            SQL)
        : [];

    $activeDeviceScopeMismatch = $missingColumns === []
        ? first_row(<<<'SQL'
            SELECT COUNT(*) AS rows_count
            FROM competitor_device d
            LEFT JOIN hotels h ON h.id = d.store_id
            LEFT JOIN users u ON u.id = d.user_id
            WHERE d.status <> 0
              AND (
                h.id IS NULL OR u.id IS NULL OR d.tenant_id IS NULL
                OR h.tenant_id IS NULL OR u.tenant_id IS NULL
                OR d.tenant_id <> h.tenant_id OR d.tenant_id <> u.tenant_id
              )
            SQL)
        : [];

    $failures = [];
    if ($missingColumns !== []) {
        $failures[] = 'required_columns_missing';
    }
    if ($invalidIndexes !== []) {
        $failures[] = 'required_indexes_missing_or_mismatched';
    }
    foreach ([
        'competitor_hotel_misaligned' => int_value($hotelScope, 'misaligned_rows'),
        'competitor_hotel_active_orphan_or_invalid' => int_value($hotelScope, 'active_orphan_or_invalid_rows'),
        'competitor_hotel_bound_orphan_or_invalid' => int_value($hotelScope, 'bound_orphan_or_invalid_rows'),
        'competitor_device_active_incomplete_binding' => int_value($deviceScope, 'active_incomplete_binding_rows'),
        'competitor_device_disabled_without_revocation' => int_value($deviceScope, 'disabled_without_revocation_rows'),
        'competitor_device_active_scope_mismatch' => int_value($activeDeviceScopeMismatch, 'rows_count'),
    ] as $failure => $count) {
        if ($count > 0) {
            $failures[] = $failure;
        }
    }

    $summary = [
        'status' => $failures === [] ? 'passed' : 'failed',
        'evidence_scope' => 'configured_mysql_postconditions',
        'database' => $databaseName,
        'server_version' => (string)($connection['server_version'] ?? ''),
        'schema' => [
            'missing_columns' => $missingColumns,
            'invalid_indexes' => $invalidIndexes,
        ],
        'competitor_hotel' => array_map('intval', $hotelScope),
        'competitor_device' => array_merge(
            array_map('intval', $deviceScope),
            ['active_scope_mismatch_rows' => int_value($activeDeviceScopeMismatch, 'rows_count')]
        ),
        'failures' => $failures,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($failures === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Competitor scope MySQL verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
