<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$pdo = SchemaVersionService::createPdo(
    SchemaVersionService::databaseConfigFromEnvironment($root)
);
$requiredColumns = [
    'operation_alerts.monitor_dedupe_key',
    'ota_failure_wecom_deliveries.dedupe_key',
];
$requiredIndexes = [
    'operation_alerts.uq_operation_alerts_monitor_dedupe',
    'ota_failure_wecom_deliveries.uq_ota_failure_wecom_delivery_dedupe',
];

$columns = $pdo->query(
    "SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS identity
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'operation_alerts' AND COLUMN_NAME = 'monitor_dedupe_key')
         OR (TABLE_NAME = 'ota_failure_wecom_deliveries' AND COLUMN_NAME = 'dedupe_key'))"
)->fetchAll(PDO::FETCH_COLUMN);
$indexes = $pdo->query(
    "SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS identity
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND INDEX_NAME IN (
         'uq_operation_alerts_monitor_dedupe',
         'uq_ota_failure_wecom_delivery_dedupe'
       )
       AND NON_UNIQUE = 0
     GROUP BY TABLE_NAME, INDEX_NAME"
)->fetchAll(PDO::FETCH_COLUMN);
sort($columns);
sort($indexes);
sort($requiredColumns);
sort($requiredIndexes);
if ($columns !== $requiredColumns || $indexes !== $requiredIndexes) {
    fwrite(STDERR, json_encode([
        'status' => 'schema_readback_failed',
        'columns' => $columns,
        'indexes' => $indexes,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'readback_verified',
    'columns' => $columns,
    'unique_indexes' => $indexes,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
