<?php
declare(strict_types=1);

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
    $service = SchemaVersionService::fromDatabaseConfig($config, $root);
    $status = $service->status();

    if ($status['ready']) {
        echo sprintf(
            "[OK] Database schema version %s is current (%d/%d migrations registered).\n",
            (string)($status['required_version'] ?? 'unknown'),
            (int)$status['applied_count'],
            (int)$status['required_count']
        );
        exit(0);
    }

    fwrite(STDERR, sprintf(
        "[UPGRADE REQUIRED] Database schema current=%s, required=%s, pending=%d.\n",
        (string)($status['current_version'] ?? 'unregistered'),
        (string)($status['required_version'] ?? 'unknown'),
        count($status['pending'])
    ));
    if ((int)$status['application_table_count'] === 0) {
        fwrite(STDERR, "Empty database: run php scripts/init_database.php\n");
    } elseif ($status['version_mismatches'] !== []
        || $status['checksum_mismatches'] !== []
        || $status['missing_checksums'] !== []
        || $status['unknown_registrations'] !== []
        || $status['baseline_checksum_mismatches'] !== []
        || $status['baseline_unknown'] !== []
    ) {
        fwrite(STDERR, "Migration evidence drift detected; inspect schema_versions, schema_baseline_sources, and the SQL catalog.\n");
    } elseif (!$status['registry_exists']) {
        fwrite(STDERR, "Legacy database: run the baseline preflight with php think db:migrate --baseline\n");
    } elseif ($status['unresolved_failures'] !== []) {
        fwrite(STDERR, 'Unresolved migration failure(s): ' . implode(', ', $status['unresolved_failures']) . PHP_EOL);
        fwrite(STDERR, "Fix the recorded cause, then run: php think db:migrate\n");
    } elseif (!$status['registry_checksum_supported']
        || !$status['registry_execution_kind_supported']
        || !$status['baseline_registry_exists']
        || $status['baseline_missing'] !== []
    ) {
        fwrite(STDERR, "Migration evidence tables are incomplete. Run: php think db:migrate\n");
    } else {
        fwrite(STDERR, "Run: php think db:migrate\n");
    }
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ERROR] Database schema check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
