#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

$configuredAppDir = trim((string)getenv('SUXIOS_APP_DIR'));
$appDir = $configuredAppDir !== '' ? rtrim($configuredAppDir, '/\\') : dirname(__DIR__);
$autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "app_autoload_missing\n");
    exit(1);
}

$fromHotelId = 0;
$toHotelId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--from=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $fromHotelId = (int)$matches[1];
        continue;
    }
    if (preg_match('/^--to=([1-9][0-9]*)$/D', (string)$argument, $matches) === 1) {
        $toHotelId = (int)$matches[1];
        continue;
    }
    fwrite(STDERR, "usage: php scripts/inspect_cloud_hotel_id_dependencies.php --from=<id> --to=<id>\n");
    exit(2);
}
if ($fromHotelId <= 0 || $toHotelId <= 0 || $fromHotelId === $toHotelId) {
    fwrite(STDERR, "distinct_positive_hotel_ids_required\n");
    exit(2);
}

require $autoload;
(new App($appDir))->initialize();

/** @return string */
function dependencyIdentifier(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
        throw new RuntimeException('unsafe_database_identifier');
    }
    return '`' . $value . '`';
}

/** @param array<string,mixed>|null $row */
function dependencyProjectRow(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $result = [];
    foreach ($row as $key => $value) {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        $result[(string)$key] = $value;
    }
    return $result;
}

$databaseRow = Db::query('SELECT DATABASE() AS database_name');
$database = trim((string)($databaseRow[0]['database_name'] ?? $databaseRow[0]['DATABASE_NAME'] ?? ''));
if ($database === '') {
    throw new RuntimeException('database_name_unavailable');
}

$sourceHotel = Db::name('hotels')
    ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
    ->where('id', $fromHotelId)
    ->find();
$targetHotel = Db::name('hotels')
    ->field('id,tenant_id,name,status,owner_user_id,ota_channel_strategy')
    ->where('id', $toHotelId)
    ->find();

$columnRows = Db::query(
    'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type,'
    . 'c.COLUMN_TYPE AS column_type,c.IS_NULLABLE AS is_nullable,c.COLUMN_KEY AS column_key '
    . 'FROM information_schema.COLUMNS c '
    . 'INNER JOIN information_schema.TABLES t '
    . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
    . 'WHERE c.TABLE_SCHEMA=? '
    . 'AND (c.COLUMN_NAME=\'id\' AND c.TABLE_NAME=\'hotels\' '
    . 'OR c.COLUMN_NAME REGEXP \'(^|_)hotel_id$\') '
    . 'ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION',
    [$database]
);

$numericTypes = [
    'tinyint' => true,
    'smallint' => true,
    'mediumint' => true,
    'int' => true,
    'integer' => true,
    'bigint' => true,
    'decimal' => true,
    'numeric' => true,
];
$columnCounts = [];
foreach ($columnRows as $columnRow) {
    $table = trim((string)($columnRow['table_name'] ?? $columnRow['TABLE_NAME'] ?? ''));
    $column = trim((string)($columnRow['column_name'] ?? $columnRow['COLUMN_NAME'] ?? ''));
    $dataType = strtolower(trim((string)($columnRow['data_type'] ?? $columnRow['DATA_TYPE'] ?? '')));
    if ($table === '' || $column === '' || !isset($numericTypes[$dataType])) {
        continue;
    }
    $sql = 'SELECT COUNT(*) AS total_rows,'
        . 'SUM(' . dependencyIdentifier($column) . '=' . $fromHotelId . ') AS from_count,'
        . 'SUM(' . dependencyIdentifier($column) . '=' . $toHotelId . ') AS to_count '
        . 'FROM ' . dependencyIdentifier($table);
    $countRows = Db::query($sql);
    $count = $countRows[0] ?? [];
    $columnCounts[] = [
        'table' => $table,
        'column' => $column,
        'data_type' => $dataType,
        'column_type' => (string)($columnRow['column_type'] ?? $columnRow['COLUMN_TYPE'] ?? ''),
        'nullable' => (string)($columnRow['is_nullable'] ?? $columnRow['IS_NULLABLE'] ?? ''),
        'key' => (string)($columnRow['column_key'] ?? $columnRow['COLUMN_KEY'] ?? ''),
        'total_rows' => (int)($count['total_rows'] ?? $count['TOTAL_ROWS'] ?? 0),
        'from_count' => (int)($count['from_count'] ?? $count['FROM_COUNT'] ?? 0),
        'to_count' => (int)($count['to_count'] ?? $count['TO_COUNT'] ?? 0),
    ];
}

$foreignKeys = Db::query(
    'SELECT k.TABLE_NAME AS table_name,k.COLUMN_NAME AS column_name,'
    . 'k.CONSTRAINT_NAME AS constraint_name,k.REFERENCED_TABLE_NAME AS referenced_table,'
    . 'k.REFERENCED_COLUMN_NAME AS referenced_column,r.UPDATE_RULE AS update_rule,r.DELETE_RULE AS delete_rule '
    . 'FROM information_schema.KEY_COLUMN_USAGE k '
    . 'LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
    . 'ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME '
    . 'WHERE k.CONSTRAINT_SCHEMA=? AND k.REFERENCED_TABLE_SCHEMA=? '
    . 'AND k.REFERENCED_TABLE_NAME=\'hotels\' AND k.REFERENCED_COLUMN_NAME=\'id\' '
    . 'ORDER BY k.TABLE_NAME,k.COLUMN_NAME',
    [$database, $database]
);

$jsonRows = Db::query(
    'SELECT c.TABLE_NAME AS table_name,c.COLUMN_NAME AS column_name,c.DATA_TYPE AS data_type '
    . 'FROM information_schema.COLUMNS c '
    . 'INNER JOIN information_schema.TABLES t '
    . 'ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE=\'BASE TABLE\' '
    . 'WHERE c.TABLE_SCHEMA=? '
    . 'AND (c.DATA_TYPE=\'json\' OR c.COLUMN_NAME LIKE \'%\\_json\') '
    . 'ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION',
    [$database]
);
$jsonReferences = [];
$jsonPattern = '\\"(system_hotel_id|hotel_id|default_hotel_id|collector_hotel_id|source_system_hotel_id|destination_system_hotel_id)\\"[[:space:]]*:[[:space:]]*\\"?'
    . $fromHotelId . '\\"?([,}[:space:]]|$)';
foreach ($jsonRows as $jsonRow) {
    $table = trim((string)($jsonRow['table_name'] ?? $jsonRow['TABLE_NAME'] ?? ''));
    $column = trim((string)($jsonRow['column_name'] ?? $jsonRow['COLUMN_NAME'] ?? ''));
    if ($table === '' || $column === '') {
        continue;
    }
    $sql = 'SELECT COUNT(*) AS reference_count FROM ' . dependencyIdentifier($table)
        . ' WHERE ' . dependencyIdentifier($column) . ' IS NOT NULL'
        . ' AND CAST(' . dependencyIdentifier($column) . ' AS CHAR) REGEXP ?';
    $referenceRows = Db::query($sql, [$jsonPattern]);
    $referenceCount = (int)($referenceRows[0]['reference_count'] ?? $referenceRows[0]['REFERENCE_COUNT'] ?? 0);
    if ($referenceCount > 0) {
        $jsonReferences[] = [
            'table' => $table,
            'column' => $column,
            'reference_count' => $referenceCount,
        ];
    }
}

$jsonReferenceTableColumns = [];
$jsonReferenceTables = array_values(array_unique(array_map(
    static fn(array $reference): string => (string)$reference['table'],
    $jsonReferences
)));
foreach ($jsonReferenceTables as $table) {
    $metadataRows = Db::query(
        'SELECT COLUMN_NAME AS column_name,DATA_TYPE AS data_type,COLUMN_TYPE AS column_type,'
        . 'IS_NULLABLE AS is_nullable,COLUMN_KEY AS column_key '
        . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',
        [$database, $table]
    );
    $jsonReferenceTableColumns[$table] = array_map('dependencyProjectRow', $metadataRows);
}

echo json_encode([
    'status' => 'ok',
    'mode' => 'read_only_dependency_audit',
    'inspected_at' => date(DATE_ATOM),
    'database' => $database,
    'from_hotel_id' => $fromHotelId,
    'to_hotel_id' => $toHotelId,
    'source_hotel' => dependencyProjectRow(is_array($sourceHotel) ? $sourceHotel : null),
    'target_hotel' => dependencyProjectRow(is_array($targetHotel) ? $targetHotel : null),
    'numeric_hotel_id_columns' => $columnCounts,
    'foreign_keys_to_hotels' => array_map('dependencyProjectRow', $foreignKeys),
    'json_reference_columns' => $jsonReferences,
    'json_reference_table_columns' => $jsonReferenceTableColumns,
    'database_write_performed' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
