<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$allowCreate = strtolower(trim((string)(getenv('SUXIOS_TEST_DB_ALLOW_CREATE') ?: '')));
if (!in_array($allowCreate, ['1', 'true', 'yes'], true)) {
    throw new RuntimeException(
        'Refusing to create a temporary database without SUXIOS_TEST_DB_ALLOW_CREATE=1.'
    );
}

$host = trim((string)(getenv('SUXIOS_TEST_DB_HOST') ?: ''));
if (!in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('SUXIOS_TEST_DB_HOST must be an explicit loopback host.');
}
$port = trim((string)(getenv('SUXIOS_TEST_DB_PORT') ?: '3306'));
$user = trim((string)(getenv('SUXIOS_TEST_DB_USER') ?: ''));
if ($user === '' || preg_match('/^\d{1,5}$/D', $port) !== 1 || (int)$port > 65535) {
    throw new RuntimeException('Explicit local MariaDB test port and user are required.');
}

$config = [
    'host' => $host,
    'port' => (int)$port,
    'database' => '',
    'username' => $user,
    'password' => (string)(getenv('SUXIOS_TEST_DB_PASS') ?: ''),
    'charset' => 'utf8mb4',
];
$server = SchemaVersionService::createPdo($config, true);
$serverVersion = (string)$server->getAttribute(PDO::ATTR_SERVER_VERSION);
$versionMatched = preg_match('/^(\d+)\.(\d+)/', $serverVersion, $versionParts) === 1;
$serverMajor = $versionMatched ? (int)$versionParts[1] : 0;
$serverMinor = $versionMatched ? (int)$versionParts[2] : 0;
if (
    stripos($serverVersion, 'mariadb') === false
    || !$versionMatched
    || $serverMajor < 10
    || ($serverMajor === 10 && $serverMinor < 4)
) {
    throw new RuntimeException("MariaDB 10.4+ is required, got: {$serverVersion}");
}
$database = 'suxios_owner_bootstrap_test_' . getmypid() . '_' . bin2hex(random_bytes(3));

if (preg_match('/^suxios_owner_bootstrap_test_[a-z0-9_]+$/D', $database) !== 1) {
    throw new RuntimeException('Unsafe temporary database name.');
}

$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $config['database'] = $database;
    $pdo = SchemaVersionService::createPdo($config);
    $pdo->exec(
        'CREATE TABLE tenants (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name varchar(120) NOT NULL,
            status tinyint unsigned NOT NULL DEFAULT 1,
            plan_id int unsigned NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE roles (
            id int unsigned NOT NULL PRIMARY KEY,
            name varchar(50) NOT NULL,
            level int NOT NULL,
            permissions text,
            status tinyint NOT NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE users (
            id int unsigned NOT NULL PRIMARY KEY,
            tenant_id int unsigned NULL,
            username varchar(50) NOT NULL UNIQUE,
            realname varchar(100),
            role_id int unsigned NOT NULL,
            hotel_id int unsigned NULL,
            status tinyint NOT NULL,
            update_time datetime NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE hotels (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id int unsigned NULL,
            name varchar(100),
            owner_user_id int unsigned NOT NULL DEFAULT 0,
            created_by int unsigned NOT NULL DEFAULT 0
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE user_hotel_permissions (
            id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id int unsigned NOT NULL,
            hotel_id int unsigned NOT NULL
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'INSERT INTO roles (id, name, level, permissions, status)
         VALUES (8, \'VIPUser\', 2, \'["all","can_manage_own_hotels"]\', 1)'
    );

    $fixtures = [
        [127, 'VIP003', '测试业主003'],
        [162, 'VIP015', '测试业主015'],
        [165, 'VIP018', '测试业主018'],
        [167, 'VIP020', '测试业主020'],
        [170, 'VIP023', '测试业主023'],
        [172, 'VIP024', '测试业主024'],
        [173, 'VIP025', '测试业主025'],
        [223, 'VIP026', '测试业主026'],
        [261, 'VIP027', '测试业主027'],
        [999, 'VIP999', '不在治理清单'],
    ];
    $insertUser = $pdo->prepare(
        'INSERT INTO users (id, tenant_id, username, realname, role_id, hotel_id, status)
         VALUES (?, NULL, ?, ?, 8, NULL, 1)'
    );
    foreach ($fixtures as [$id, $username, $realname]) {
        $insertUser->execute([$id, $username, $realname]);
    }

    $migrationPath = $root
        . '/database/migrations/20260723_tenant_bootstrap_unassigned_owner_accounts.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration)) {
        throw new RuntimeException('Unable to read owner tenant bootstrap migration.');
    }
    $statements = SchemaVersionService::splitSqlStatements($migration);
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $targetUsernames = "'VIP003','VIP015','VIP018','VIP020','VIP023','VIP024','VIP025','VIP026','VIP027'";
    $firstRun = [
        'tenant_count' => (int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
        'mapped_targets' => (int)$pdo->query(
            "SELECT COUNT(*) FROM users WHERE username IN ({$targetUsernames}) AND tenant_id > 0"
        )->fetchColumn(),
        'distinct_target_tenants' => (int)$pdo->query(
            "SELECT COUNT(DISTINCT tenant_id) FROM users WHERE username IN ({$targetUsernames})"
        )->fetchColumn(),
        'untargeted_tenant' => $pdo->query(
            "SELECT tenant_id FROM users WHERE username = 'VIP999'"
        )->fetchColumn(),
        'hotel_count' => (int)$pdo->query('SELECT COUNT(*) FROM hotels')->fetchColumn(),
        'permission_count' => (int)$pdo->query(
            'SELECT COUNT(*) FROM user_hotel_permissions'
        )->fetchColumn(),
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
    $secondRunTenantCount = (int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();

    $validationPath = $root
        . '/database/migrations/20260723_validate_owner_tenant_bootstrap_targets.sql';
    $validationMigration = file_get_contents($validationPath);
    if (!is_string($validationMigration)) {
        throw new RuntimeException('Unable to read owner tenant bootstrap validation migration.');
    }
    $validationStatements = SchemaVersionService::splitSqlStatements($validationMigration);
    foreach ($validationStatements as $statement) {
        $pdo->exec($statement);
    }

    $expected = [
        'tenant_count' => 9,
        'mapped_targets' => 9,
        'distinct_target_tenants' => 9,
        'untargeted_tenant' => null,
        'hotel_count' => 0,
        'permission_count' => 0,
    ];
    if ($firstRun !== $expected || $secondRunTenantCount !== 9) {
        throw new RuntimeException(
            'Owner tenant bootstrap migration verification failed: '
            . json_encode([$firstRun, $secondRunTenantCount], JSON_UNESCAPED_UNICODE)
        );
    }

    $pdo->exec(
        "UPDATE users SET tenant_id = NULL, status = 0 WHERE id = 127 AND username = 'VIP003'"
    );
    $driftRejected = false;
    try {
        foreach ($validationStatements as $statement) {
            $pdo->exec($statement);
        }
    } catch (PDOException $e) {
        $driftRejected = str_contains(
            strtolower($e->getMessage()),
            'owner tenant bootstrap postcondition is incomplete'
        );
    }
    if (!$driftRejected) {
        throw new RuntimeException(
            'Owner tenant bootstrap validation did not reject a drifted target account.'
        );
    }

    echo json_encode([
        'status' => 'passed',
        'first_run' => $firstRun,
        'second_run_tenant_count' => $secondRunTenantCount,
        'drifted_target_rejected' => true,
        'mariadb_version' => $serverVersion,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
