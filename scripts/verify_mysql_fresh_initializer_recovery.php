<?php
declare(strict_types=1);

use app\service\FreshDatabaseInitializerService;
use app\service\SchemaVersionService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (getenv('SUXI_CI_MYSQL_VERIFY') !== '1') {
    fwrite(STDERR, "SUXI_CI_MYSQL_VERIFY=1 is required.\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'suxi-schema-recovery-' . getmypid() . '-' . bin2hex(random_bytes(4));
$database = 'suxi_recovery_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_e2e';
$server = null;

$removeFixture = static function (string $path): void {
    $resolved = realpath($path);
    $tempRoot = realpath(sys_get_temp_dir());
    if (!is_string($resolved) || !is_string($tempRoot)) {
        return;
    }
    $prefix = rtrim($tempRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'suxi-schema-recovery-';
    if (!str_starts_with($resolved, $prefix)) {
        throw new RuntimeException('Refused to remove a fixture outside the recovery-test directory.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($resolved);
};

try {
    $config = SchemaVersionService::databaseConfigFromEnvironment($projectRoot, [
        'DB_HOST' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : null,
        'DB_PORT' => getenv('DB_PORT') !== false ? getenv('DB_PORT') : null,
        'DB_USER' => getenv('DB_USER') !== false ? getenv('DB_USER') : null,
        'DB_CHARSET' => 'utf8mb4',
    ]);
    $host = strtolower(trim((string)($config['hostname'] ?? '')));
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
        && getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB') !== '1'
    ) {
        throw new RuntimeException('Fresh initializer recovery verifier refused a non-loopback host.');
    }
    $config['database'] = $database;
    $config['charset'] = 'utf8mb4';

    $migrationDirectory = $fixtureRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    if (!mkdir($migrationDirectory, 0777, true) && !is_dir($migrationDirectory)) {
        throw new RuntimeException('Unable to create the schema recovery fixture.');
    }
    file_put_contents(
        $fixtureRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init_full.sql',
        "SOURCE ./database/base.sql;\nSOURCE ./database/migrations/20260101_create_alpha.sql;\n"
    );
    file_put_contents(
        $fixtureRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'base.sql',
        "CREATE TABLE IF NOT EXISTS baseline_meta (id INT NOT NULL PRIMARY KEY);\n"
    );
    file_put_contents(
        $migrationDirectory . DIRECTORY_SEPARATOR . '20260101_create_alpha.sql',
        "CREATE TABLE IF NOT EXISTS alpha_records (id INT NOT NULL PRIMARY KEY);\n"
    );
    $recoverableMigration = $migrationDirectory . DIRECTORY_SEPARATOR . '20260102_create_beta.sql';
    file_put_contents(
        $recoverableMigration,
        "CREATE TABLE IF NOT EXISTS beta_records (id INT NOT NULL PRIMARY KEY);\nBROKEN MIGRATION STATEMENT;\n"
    );

    $server = SchemaVersionService::createPdo($config, true);
    $firstFailure = '';
    try {
        FreshDatabaseInitializerService::initialize($config, $fixtureRoot);
    } catch (Throwable $exception) {
        $firstFailure = $exception->getMessage();
    }
    if ($firstFailure === '' || !str_contains($firstFailure, 'newly created database was removed')) {
        throw new RuntimeException('Failed initialization did not report removal of the new database.');
    }
    $exists = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([$database]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('Failed initialization left the new database behind.');
    }

    file_put_contents(
        $recoverableMigration,
        "CREATE TABLE IF NOT EXISTS beta_records (id INT NOT NULL PRIMARY KEY);\n"
    );
    $result = FreshDatabaseInitializerService::initialize($config, $fixtureRoot);
    $status = $result['status'];
    if (($status['ready'] ?? false) !== true
        || (int)($status['applied_count'] ?? 0) !== 2
        || (int)($status['required_count'] ?? 0) !== 2
        || $result['executed'] !== ['20260102_create_beta.sql']
    ) {
        throw new RuntimeException('Retry did not reach the complete 2/2 schema version.');
    }
    $databasePdo = SchemaVersionService::createPdo($config);
    $tableCount = (int)$databasePdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
        . "AND TABLE_NAME IN ('baseline_meta', 'alpha_records', 'beta_records')"
    )->fetchColumn();
    if ($tableCount !== 3) {
        throw new RuntimeException('Retry did not create every expected application table.');
    }

    $resultSummary = [
        'ok' => true,
        'failed_database_removed' => true,
        'retry_registered' => (int)$status['applied_count'],
        'retry_required' => (int)$status['required_count'],
        'retry_tables' => $tableCount,
    ];
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fresh initializer recovery verification failed: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($server instanceof PDO && preg_match('/^suxi_recovery_[a-f0-9_]+_e2e$/D', $database) === 1) {
        $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
    if (is_dir($fixtureRoot)) {
        $removeFixture($fixtureRoot);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}

$remaining = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$remaining->execute([$database]);
$resultSummary['temporary_databases_remaining'] = (int)$remaining->fetchColumn();
if ($resultSummary['temporary_databases_remaining'] !== 0) {
    fwrite(STDERR, "Recovery verifier cleanup left a temporary database.\n");
    exit(1);
}
echo json_encode($resultSummary, JSON_UNESCAPED_SLASHES) . PHP_EOL;
