<?php
declare(strict_types=1);

use app\service\FreshDatabaseInitializerService;
use app\service\SchemaVersionService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$overrides = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(host|port|database|user|charset)=(.*)$/D', $argument, $match) !== 1) {
        continue;
    }
    $keyMap = [
        'host' => 'DB_HOST',
        'port' => 'DB_PORT',
        'database' => 'DB_NAME',
        'user' => 'DB_USER',
        'charset' => 'DB_CHARSET',
    ];
    $overrides[$keyMap[$match[1]]] = $match[2];
}

try {
    $config = SchemaVersionService::databaseConfigFromEnvironment($root, $overrides);
    $result = FreshDatabaseInitializerService::initialize($config, $root);
    $status = $result['status'];
    echo sprintf(
        "Database initialized: baseline=%d, newly_applied=%d, version=%s, registered=%d/%d.\n",
        (int)$result['baseline_registered'],
        count($result['executed']),
        (string)($status['required_version'] ?? 'unknown'),
        (int)$status['applied_count'],
        (int)$status['required_count']
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database initialization failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
