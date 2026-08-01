<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

try {
    $config = SchemaVersionService::databaseConfigFromEnvironment($root);
    $service = SchemaVersionService::fromDatabaseConfig($config, $root);
    $status = $service->status();
    if (!$status['ready']) {
        fwrite(STDERR, '[FAIL] database migration catalog is not current; run php think db:migrate' . PHP_EOL);
        exit(1);
    }

    $pdo = SchemaVersionService::createPdo($config);
    $database = (string)$config['database'];
    $columnStatement = $pdo->prepare(
        'SELECT IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH '
        . 'FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $columnStatement->execute([
        'schema' => $database,
        'table' => 'competitor_price_log',
        'column' => 'report_fingerprint',
    ]);
    $column = $columnStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($column)
        || strtoupper((string)($column['IS_NULLABLE'] ?? '')) !== 'YES'
        || (int)($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 64) {
        fwrite(STDERR, '[FAIL] competitor report_fingerprint column contract is missing or invalid' . PHP_EOL);
        exit(1);
    }

    $indexStatement = $pdo->prepare(
        'SELECT NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX '
        . 'FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND INDEX_NAME = :index_name '
        . 'ORDER BY SEQ_IN_INDEX'
    );
    $indexStatement->execute([
        'schema' => $database,
        'table' => 'competitor_price_log',
        'index_name' => 'uniq_competitor_report_fingerprint',
    ]);
    $indexes = $indexStatement->fetchAll(PDO::FETCH_ASSOC);
    if (count($indexes) !== 1
        || (int)($indexes[0]['NON_UNIQUE'] ?? 1) !== 0
        || (int)($indexes[0]['SEQ_IN_INDEX'] ?? 0) !== 1
        || (string)($indexes[0]['COLUMN_NAME'] ?? '') !== 'report_fingerprint') {
        fwrite(STDERR, '[FAIL] competitor report fingerprint unique index is missing or invalid' . PHP_EOL);
        exit(1);
    }

    fwrite(STDOUT, '[PASS] competitor report fingerprint schema is durable and unique' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] competitor report schema verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
