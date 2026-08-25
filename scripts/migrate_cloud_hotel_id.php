#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

const CLOUD_HOTEL_ID_MIGRATION_DATABASE = 'hotelx_cloud';
const CLOUD_HOTEL_ID_MIGRATION_CONFIRMATION = 'RENAME_CLOUD_HOTEL_ID';

/**
 * This is an intentionally explicit production migration plan. Every entry was
 * verified by the read-only dependency inspector before the pilot hotel rename.
 * Historical JSON evidence is not rewritten because its fingerprints describe
 * the identity at capture time. New records use the new relational hotel ID.
 *
 * @return array<int,array{table:string,column:string}>
 */
function cloudHotelIdMigrationPlan(): array
{
    return [
        ['table' => 'ai_daily_reports', 'column' => 'hotel_id'],
        ['table' => 'cloud_browser_profiles', 'column' => 'system_hotel_id'],
        ['table' => 'cloud_collection_tasks', 'column' => 'system_hotel_id'],
        ['table' => 'dingdandao_operating_target_captures', 'column' => 'hotel_id'],
        ['table' => 'dingdandao_pms_integrations', 'column' => 'hotel_id'],
        ['table' => 'dingdandao_room_fee_capture_details', 'column' => 'hotel_id'],
        ['table' => 'hotel_collection_plans', 'column' => 'system_hotel_id'],
        ['table' => 'manual_notifications', 'column' => 'hotel_id'],
        ['table' => 'manual_notification_dispatch_attempts', 'column' => 'hotel_id'],
        ['table' => 'manual_notification_schedule_dispatches', 'column' => 'hotel_id'],
        ['table' => 'manual_notification_schedule_runs', 'column' => 'scope_hotel_id'],
        ['table' => 'manual_notification_schedule_run_scopes', 'column' => 'hotel_id'],
        ['table' => 'meituan_cloud_pms_integrations', 'column' => 'hotel_id'],
        ['table' => 'online_daily_data', 'column' => 'system_hotel_id'],
        ['table' => 'operating_target_daily_records', 'column' => 'hotel_id'],
        ['table' => 'operating_target_daily_snapshots', 'column' => 'hotel_id'],
        ['table' => 'operation_logs', 'column' => 'hotel_id'],
        ['table' => 'ota_ctrip_metric_facts', 'column' => 'system_hotel_id'],
        ['table' => 'ota_profile_bindings', 'column' => 'system_hotel_id'],
        ['table' => 'platform_data_raw_records', 'column' => 'system_hotel_id'],
        ['table' => 'platform_data_sources', 'column' => 'system_hotel_id'],
        ['table' => 'platform_data_sync_logs', 'column' => 'system_hotel_id'],
        ['table' => 'platform_data_sync_tasks', 'column' => 'system_hotel_id'],
        ['table' => 'users', 'column' => 'hotel_id'],
        ['table' => 'users', 'column' => 'default_hotel_id'],
        ['table' => 'user_hotel_permissions', 'column' => 'hotel_id'],
        ['table' => 'hotels', 'column' => 'id'],
    ];
}

/** @return string */
function cloudHotelIdMigrationIdentifier(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
        throw new RuntimeException('unsafe_database_identifier');
    }
    return '`' . $value . '`';
}

/** @return array{from_count:int,to_count:int,total_rows:int} */
function cloudHotelIdMigrationCounts(string $table, string $column, int $fromHotelId, int $toHotelId): array
{
    $rows = Db::query(
        'SELECT COUNT(*) AS total_rows,'
        . 'SUM(' . cloudHotelIdMigrationIdentifier($column) . '=' . $fromHotelId . ') AS from_count,'
        . 'SUM(' . cloudHotelIdMigrationIdentifier($column) . '=' . $toHotelId . ') AS to_count '
        . 'FROM ' . cloudHotelIdMigrationIdentifier($table)
    );
    $row = $rows[0] ?? [];
    return [
        'total_rows' => (int)($row['total_rows'] ?? $row['TOTAL_ROWS'] ?? 0),
        'from_count' => (int)($row['from_count'] ?? $row['FROM_COUNT'] ?? 0),
        'to_count' => (int)($row['to_count'] ?? $row['TO_COUNT'] ?? 0),
    ];
}

/** @return array<string,mixed>|null */
function cloudHotelIdMigrationHotel(int $hotelId): ?array
{
    $row = Db::name('hotels')
        ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
        ->where('id', $hotelId)
        ->find();
    return is_array($row) ? $row : null;
}

/** @return array<string,array{table:string,column:string,engine:string,from_count:int,to_count:int,total_rows:int}> */
function cloudHotelIdMigrationPreflight(int $fromHotelId, int $toHotelId): array
{
    $databaseRows = Db::query('SELECT DATABASE() AS database_name');
    $database = trim((string)($databaseRows[0]['database_name'] ?? $databaseRows[0]['DATABASE_NAME'] ?? ''));
    if ($database !== CLOUD_HOTEL_ID_MIGRATION_DATABASE) {
        throw new RuntimeException('unexpected_database:' . $database);
    }

    $plan = cloudHotelIdMigrationPlan();
    $plannedKeys = [];
    $preflight = [];
    foreach ($plan as $entry) {
        $table = $entry['table'];
        $column = $entry['column'];
        $key = $table . '.' . $column;
        $plannedKeys[$key] = true;

        $metadata = Db::query(
            'SELECT c.DATA_TYPE AS data_type,t.ENGINE AS engine '
            . 'FROM information_schema.COLUMNS c '
            . 'INNER JOIN information_schema.TABLES t '
            . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME '
            . 'WHERE c.TABLE_SCHEMA=? AND c.TABLE_NAME=? AND c.COLUMN_NAME=? AND t.TABLE_TYPE=\'BASE TABLE\'',
            [$database, $table, $column]
        );
        if ($metadata === []) {
            throw new RuntimeException('planned_column_missing:' . $key);
        }
        $engine = strtoupper(trim((string)($metadata[0]['engine'] ?? $metadata[0]['ENGINE'] ?? '')));
        if ($engine !== 'INNODB') {
            throw new RuntimeException('non_transactional_table:' . $key . ':' . $engine);
        }
        $counts = cloudHotelIdMigrationCounts($table, $column, $fromHotelId, $toHotelId);
        if ($counts['to_count'] !== 0) {
            throw new RuntimeException('target_id_already_referenced:' . $key);
        }
        $preflight[$key] = [
            'table' => $table,
            'column' => $column,
            'engine' => $engine,
            ...$counts,
        ];
    }

    $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric'];
    $discovered = Db::query(
        'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type '
        . 'FROM information_schema.COLUMNS c '
        . 'INNER JOIN information_schema.TABLES t '
        . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
        . 'WHERE c.TABLE_SCHEMA=? '
        . 'AND (c.COLUMN_NAME=\'id\' AND c.TABLE_NAME=\'hotels\' '
        . 'OR c.COLUMN_NAME REGEXP \'(^|_)hotel_id$\') '
        . 'ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION',
        [$database]
    );
    foreach ($discovered as $columnRow) {
        $table = trim((string)($columnRow['table_name'] ?? $columnRow['TABLE_NAME'] ?? ''));
        $column = trim((string)($columnRow['column_name'] ?? $columnRow['COLUMN_NAME'] ?? ''));
        $dataType = strtolower(trim((string)($columnRow['data_type'] ?? $columnRow['DATA_TYPE'] ?? '')));
        if ($table === '' || $column === '' || !in_array($dataType, $numericTypes, true)) {
            continue;
        }
        $key = $table . '.' . $column;
        if (isset($plannedKeys[$key])) {
            continue;
        }
        $counts = cloudHotelIdMigrationCounts($table, $column, $fromHotelId, $toHotelId);
        if ($counts['from_count'] > 0) {
            throw new RuntimeException('unplanned_hotel_id_reference:' . $key . ':' . $counts['from_count']);
        }
    }

    $foreignKeys = Db::query(
        'SELECT k.TABLE_NAME AS table_name,k.COLUMN_NAME AS column_name,k.CONSTRAINT_NAME AS constraint_name '
        . 'FROM information_schema.KEY_COLUMN_USAGE k '
        . 'WHERE k.CONSTRAINT_SCHEMA=? AND k.REFERENCED_TABLE_SCHEMA=? '
        . 'AND k.REFERENCED_TABLE_NAME=\'hotels\' AND k.REFERENCED_COLUMN_NAME=\'id\'',
        [$database, $database]
    );
    if ($foreignKeys !== []) {
        throw new RuntimeException('foreign_keys_to_hotels_require_explicit_review');
    }

    return $preflight;
}

$configuredAppDir = trim((string)getenv('SUXIOS_APP_DIR'));
$appDir = $configuredAppDir !== '' ? rtrim($configuredAppDir, '/\\') : dirname(__DIR__);
$autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app_autoload_missing\n");
    exit(1);
}

$fromHotelId = 0;
$toHotelId = 0;
$expectedTenantId = 0;
$expectedHotelName = '';
$mode = 'plan';
$confirmation = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--from=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $fromHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--to=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $toHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--expected-tenant-id=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $expectedTenantId = (int)$matches[1];
        continue;
    }
    if (str_starts_with((string)$argument, '--expected-hotel-name=')) {
        $expectedHotelName = trim(substr((string)$argument, strlen('--expected-hotel-name=')));
        continue;
    }
    if (preg_match('/^--mode=(plan|apply)$/D', (string)$argument, $matches) === 1) {
        $mode = $matches[1];
        continue;
    }
    if (str_starts_with((string)$argument, '--confirm=')) {
        $confirmation = trim(substr((string)$argument, strlen('--confirm=')));
        continue;
    }
    fwrite(STDERR, "usage: php scripts/migrate_cloud_hotel_id.php --from=<id> --to=<id> --expected-tenant-id=<id> --expected-hotel-name=<name> [--mode=plan|apply] [--confirm=RENAME_CLOUD_HOTEL_ID]\n");
    exit(2);
}
if ($fromHotelId <= 0 || $toHotelId <= 0 || $fromHotelId === $toHotelId
    || $expectedTenantId <= 0 || $expectedHotelName === '') {
    fwrite(STDERR, "complete_distinct_hotel_scope_required\n");
    exit(2);
}
if ($mode === 'apply' && $confirmation !== CLOUD_HOTEL_ID_MIGRATION_CONFIRMATION) {
    fwrite(STDERR, "explicit_apply_confirmation_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

$sourceHotel = cloudHotelIdMigrationHotel($fromHotelId);
$targetHotel = cloudHotelIdMigrationHotel($toHotelId);
if (!is_array($sourceHotel)
    || (int)($sourceHotel['tenant_id'] ?? 0) !== $expectedTenantId
    || trim((string)($sourceHotel['name'] ?? '')) !== $expectedHotelName) {
    throw new RuntimeException('source_hotel_identity_mismatch');
}
if (is_array($targetHotel)) {
    throw new RuntimeException('target_hotel_id_already_exists');
}

$preflight = cloudHotelIdMigrationPreflight($fromHotelId, $toHotelId);
$receiptBase = [
    'contract_version' => 'suxios.cloud_hotel_id_migration.v1',
    'database' => CLOUD_HOTEL_ID_MIGRATION_DATABASE,
    'from_hotel_id' => $fromHotelId,
    'to_hotel_id' => $toHotelId,
    'tenant_id' => $expectedTenantId,
    'hotel_name' => $expectedHotelName,
    'preflight' => array_values($preflight),
    'historical_json_policy' => 'preserve_fingerprinted_capture_time_identity',
];

if ($mode === 'plan') {
    echo json_encode([
        'status' => 'ready_to_apply',
        'mode' => 'plan',
        'inspected_at' => date(DATE_ATOM),
        ...$receiptBase,
        'plan_sha256' => hash('sha256', (string)json_encode($receiptBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'database_write_performed' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$updated = [];
$postflight = [];
Db::startTrans();
try {
    foreach (cloudHotelIdMigrationPlan() as $entry) {
        $table = $entry['table'];
        $column = $entry['column'];
        $key = $table . '.' . $column;
        $expectedCount = (int)$preflight[$key]['from_count'];
        $affected = Db::execute(
            'UPDATE ' . cloudHotelIdMigrationIdentifier($table)
            . ' SET ' . cloudHotelIdMigrationIdentifier($column) . '=?'
            . ' WHERE ' . cloudHotelIdMigrationIdentifier($column) . '=?',
            [$toHotelId, $fromHotelId]
        );
        if ($affected !== $expectedCount) {
            throw new RuntimeException('updated_row_count_mismatch:' . $key . ':' . $affected . ':' . $expectedCount);
        }
        $updated[$key] = $affected;
    }

    foreach (cloudHotelIdMigrationPlan() as $entry) {
        $table = $entry['table'];
        $column = $entry['column'];
        $key = $table . '.' . $column;
        $counts = cloudHotelIdMigrationCounts($table, $column, $fromHotelId, $toHotelId);
        if ($counts['from_count'] !== 0 || $counts['to_count'] !== (int)$preflight[$key]['from_count']) {
            throw new RuntimeException('postflight_count_mismatch:' . $key);
        }
        $postflight[$key] = $counts;
    }
    $migratedHotel = cloudHotelIdMigrationHotel($toHotelId);
    if (!is_array($migratedHotel)
        || (int)($migratedHotel['tenant_id'] ?? 0) !== $expectedTenantId
        || trim((string)($migratedHotel['name'] ?? '')) !== $expectedHotelName
        || cloudHotelIdMigrationHotel($fromHotelId) !== null) {
        throw new RuntimeException('postflight_hotel_identity_mismatch');
    }

    Db::commit();
} catch (Throwable $exception) {
    Db::rollback();
    throw $exception;
}

$receipt = [
    'status' => 'migrated_and_readback_verified',
    'mode' => 'apply',
    'migrated_at' => date(DATE_ATOM),
    ...$receiptBase,
    'updated_rows' => $updated,
    'postflight' => $postflight,
    'database_write_performed' => true,
];
$receipt['receipt_sha256'] = hash(
    'sha256',
    (string)json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
echo json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
