#!/usr/bin/env php
<?php
declare(strict_types=1);

use think\App;
use think\facade\Db;

const CLOUD_HOTEL_ID_MARIADB_VERIFY_OPT_IN = 'SUXI_CLOUD_HOTEL_ID_MARIADB_VERIFY';

/** @throws RuntimeException */
function cloudHotelIdMariaDbVerifyAssert(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

/** @param callable():mixed $callback */
function cloudHotelIdMariaDbVerifyExpectFailure(callable $callback, string $expectedCode): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        cloudHotelIdMariaDbVerifyAssert(
            str_contains($exception->getMessage(), $expectedCode),
            'unexpected_failure_code:' . $expectedCode
        );
        return;
    }
    throw new RuntimeException('expected_failure_not_observed:' . $expectedCode);
}

function cloudHotelIdMariaDbVerifyIdentifier(string $value): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/D', $value) !== 1) {
        throw new RuntimeException('unsafe_verify_database_identifier');
    }
    return '`' . $value . '`';
}

/** @return array<string,mixed> */
function cloudHotelIdMariaDbVerifyRunInspector(int $expectedExitCode): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open_required_for_inspector_behavior_verify');
    }
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            __DIR__ . DIRECTORY_SEPARATOR . 'inspect_cloud_hotel_id_dependencies.php',
            '--from=5',
            '--to=80',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('inspector_process_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    cloudHotelIdMariaDbVerifyAssert($exitCode === $expectedExitCode, 'inspector_exit_code_mismatch:' . $exitCode);
    try {
        $receipt = json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('inspector_receipt_decode_failed', 0, $exception);
    }
    cloudHotelIdMariaDbVerifyAssert(is_array($receipt), 'inspector_receipt_not_object');
    cloudHotelIdMariaDbVerifyAssert(trim((string)$stderr) === '', 'inspector_stderr_not_empty');
    cloudHotelIdMariaDbVerifyAssert(
        !str_contains((string)$stdout, 'immutable-sentinel'),
        'inspector_receipt_leaked_raw_json'
    );
    return $receipt;
}

$result = [
    'contract_version' => 'suxios.cloud_hotel_id_mariadb_behavior_verify.v1',
    'status' => 'failed',
    'database_scope' => 'not_created',
    'cleanup_remaining' => null,
    'checks' => [],
];
$serverPdo = null;
$databaseName = '';
$lockConnection = null;
$lockAcquired = false;
$lockReleased = false;
$transactionOpen = false;
$failure = null;

try {
    if (trim((string)getenv(CLOUD_HOTEL_ID_MARIADB_VERIFY_OPT_IN)) !== '1') {
        throw new RuntimeException('explicit_mariadb_verify_opt_in_required');
    }
    $host = trim((string)(getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1'));
    $port = trim((string)(getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306'));
    $user = (string)(getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
    $password = (string)(getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    if ($host !== '127.0.0.1' || preg_match('/^[1-9][0-9]{0,4}$/D', $port) !== 1) {
        throw new RuntimeException('local_mariadb_only');
    }

    $databaseName = 'suxios_cloud_hotel_id_' . getmypid() . '_'
        . bin2hex(random_bytes(4)) . '_e2e';
    cloudHotelIdMariaDbVerifyAssert(
        preg_match('/^suxios_cloud_hotel_id_[1-9][0-9]*_[a-f0-9]{8}_e2e$/D', $databaseName) === 1,
        'unsafe_generated_database_name'
    );

    $serverPdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $serverPdo->exec(
        'CREATE DATABASE ' . cloudHotelIdMariaDbVerifyIdentifier($databaseName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $result['database_scope'] = 'disposable_local_e2e_database_created';

    putenv('SUXI_E2E_DB_OVERRIDE=1');
    putenv('SUXI_E2E_DB_NAME=' . $databaseName);
    putenv('DB_TYPE=mysql');
    putenv('DB_HOST=' . $host);
    putenv('DB_PORT=' . $port);
    putenv('DB_USER=' . $user);
    putenv('DB_PASS=' . $password);
    putenv('DB_CHARSET=utf8mb4');
    putenv('APP_DEBUG=0');

    define('CLOUD_HOTEL_ID_MIGRATION_DATABASE', $databaseName);
    define('CLOUD_HOTEL_ID_MIGRATION_LIBRARY_ONLY', true);
    $appDir = dirname(__DIR__);
    $autoload = $appDir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    cloudHotelIdMariaDbVerifyAssert(is_file($autoload), 'app_autoload_missing');
    require $autoload;
    (new App($appDir))->initialize();
    require __DIR__ . DIRECTORY_SEPARATOR . 'migrate_cloud_hotel_id.php';

    $schema = [
        'CREATE TABLE `hotels` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`tenant_id` INT UNSIGNED NOT NULL,`name` VARCHAR(120) NOT NULL,'
            . '`status` VARCHAR(30) NOT NULL DEFAULT \'active\','
            . '`owner_user_id` INT UNSIGNED NULL,`ota_channel_strategy` VARCHAR(30) NULL,'
            . 'PRIMARY KEY (`id`)) ENGINE=InnoDB',
        'CREATE TABLE `daily_reports` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`hotel_id` INT UNSIGNED NULL,'
            . 'PRIMARY KEY (`id`)) ENGINE=InnoDB',
        'CREATE TABLE `competitor_price_log` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`store_id` INT UNSIGNED NULL,'
            . '`hotel_id` INT UNSIGNED NULL,`ota_hotel_id` VARCHAR(64) NULL,'
            . 'PRIMARY KEY (`id`)) ENGINE=InnoDB',
        'CREATE TABLE `ota_local_collector_account_hotels` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`system_hotel_id` INT UNSIGNED NULL,'
            . '`platform_hotel_id` VARCHAR(64) NULL,`status` VARCHAR(20) NOT NULL DEFAULT \'active\','
            . '`active_system_hotel_id` INT UNSIGNED AS '
            . '(CASE WHEN `status`=\'active\' THEN `system_hotel_id` ELSE NULL END) STORED,'
            . '`active_platform_hotel_id` VARCHAR(64) AS '
            . '(CASE WHEN `status`=\'active\' THEN `platform_hotel_id` ELSE NULL END) STORED,'
            . 'PRIMARY KEY (`id`)) ENGINE=InnoDB',
        'CREATE TABLE `system_configs` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`config_key` VARCHAR(80) NOT NULL,'
            . '`config_value` LONGTEXT NULL,PRIMARY KEY (`id`),UNIQUE KEY (`config_key`)) ENGINE=InnoDB',
        'CREATE TABLE `platform_data_sources` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`system_hotel_id` INT UNSIGNED NULL,'
            . '`config_json` LONGTEXT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB',
        'CREATE TABLE `platform_data_raw_records` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`system_hotel_id` INT UNSIGNED NULL,'
            . '`raw_payload` LONGTEXT NOT NULL,PRIMARY KEY (`id`)) ENGINE=InnoDB',
    ];
    foreach ($schema as $sql) {
        Db::execute($sql);
    }

    Db::execute(
        'INSERT INTO `hotels` (`id`,`tenant_id`,`name`,`status`,`owner_user_id`,`ota_channel_strategy`) '
        . 'VALUES (5,7,\'Disposable Migration Hotel\',\'active\',9,\'ota_only\')'
    );
    Db::execute('INSERT INTO `daily_reports` (`hotel_id`) VALUES (5),(5)');
    Db::execute(
        'INSERT INTO `competitor_price_log` (`store_id`,`hotel_id`,`ota_hotel_id`) VALUES (5,5,\'5\')'
    );
    Db::execute(
        'INSERT INTO `ota_local_collector_account_hotels` '
        . '(`system_hotel_id`,`platform_hotel_id`,`status`) VALUES (5,\'5\',\'active\')'
    );
    $systemConfigBefore = json_encode([
        'system_hotel_id' => 5,
        'hotel_id' => '5',
        'platform_hotel_id' => '5',
        'nested' => [['hotel_id' => '5']],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $meituanConfigBefore = json_encode([
        'system_hotel_id' => 5,
        'hotel_id' => '5',
        'platform_hotel_id' => '5',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    Db::execute(
        'INSERT INTO `system_configs` (`config_key`,`config_value`) VALUES (?,?),(?,?)',
        ['ctrip_config_list', $systemConfigBefore, 'meituan_config_list', $meituanConfigBefore]
    );
    $platformConfigBefore = json_encode([
        'collector_hotel_id' => 5,
        'system_hotel_id' => '5',
        'hotel_id' => '5',
        'platform_hotel_id' => '5',
        'nested' => ['source_system_hotel_id' => '5'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    Db::execute(
        'INSERT INTO `platform_data_sources` (`system_hotel_id`,`config_json`) VALUES (?,?)',
        [5, $platformConfigBefore]
    );
    $immutableRaw = '{"system_hotel_id":5,"hotel_id":"5","evidence":"immutable-sentinel"}';
    $immutableSha256 = hash('sha256', $immutableRaw);
    Db::execute(
        'INSERT INTO `platform_data_raw_records` (`system_hotel_id`,`raw_payload`) VALUES (?,?)',
        [5, $immutableRaw]
    );

    Db::execute(
        'CREATE TABLE `future_competitor_link` ('
        . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`store_id` INT UNSIGNED NULL,'
        . 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationPreflight(5, 80),
        'unregistered_store_id_column_requires_review'
    );
    Db::execute('DROP TABLE `future_competitor_link`');
    $result['checks']['unregistered_future_store_id_blocked'] = true;

    Db::execute(
        'ALTER TABLE `daily_reports` ADD CONSTRAINT `fk_verify_reports_hotel` '
        . 'FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`)'
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationPreflight(5, 80),
        'foreign_keys_to_hotels_require_explicit_review'
    );
    $foreignKeyInspector = cloudHotelIdMariaDbVerifyRunInspector(3);
    cloudHotelIdMariaDbVerifyAssert(
        $foreignKeyInspector['status'] === 'review_required'
        && count($foreignKeyInspector['foreign_keys_to_hotels'] ?? []) === 1,
        'inspector_foreign_key_review_missing'
    );
    Db::execute('ALTER TABLE `daily_reports` DROP FOREIGN KEY `fk_verify_reports_hotel`');
    $result['checks']['foreign_key_plan_and_inspector_blocked'] = true;

    Db::execute(
        'CREATE TABLE `future_runtime_configs` ('
        . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`config_json` LONGTEXT NULL,'
        . 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    Db::execute(
        'INSERT INTO `future_runtime_configs` (`config_json`) VALUES (?)',
        ['{"source_system_hotel_id":5}']
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationJsonPreflight(5, 80, false),
        'unknown_non_historical_json_reference_requires_review'
    );
    $unknownInspector = cloudHotelIdMariaDbVerifyRunInspector(3);
    cloudHotelIdMariaDbVerifyAssert(
        $unknownInspector['status'] === 'review_required'
        && count($unknownInspector['blocking_unknown_json_references'] ?? []) === 1,
        'inspector_unknown_json_review_missing'
    );
    Db::execute('DROP TABLE `future_runtime_configs`');
    $result['checks']['unknown_active_json_reference_plan_and_inspector_blocked'] = true;

    $targetConflict = json_encode([
        'system_hotel_id' => 5,
        'nested' => ['hotel_id' => '80'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    Db::execute(
        'UPDATE `system_configs` SET `config_value`=? WHERE `config_key`=\'ctrip_config_list\'',
        [$targetConflict]
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationJsonPreflight(5, 80, false),
        'mutable_active_config_target_id_already_present'
    );
    Db::execute(
        'UPDATE `system_configs` SET `config_value`=? WHERE `config_key`=\'ctrip_config_list\'',
        [$systemConfigBefore]
    );
    $result['checks']['mutable_source_target_collision_blocked'] = true;

    $readyInspector = cloudHotelIdMariaDbVerifyRunInspector(3);
    cloudHotelIdMariaDbVerifyAssert(
        $readyInspector['status'] === 'migration_required'
        && count($readyInspector['mutable_active_config_references'] ?? []) === 3
        && count($readyInspector['preserved_immutable_evidence_references'] ?? []) === 1
        && ($readyInspector['blocking_unknown_json_references'] ?? []) === [],
        'inspector_migration_required_projection_mismatch'
    );
    $result['checks']['inspector_active_and_immutable_json_projection_verified'] = true;

    $lockConnection = Db::connect(null, true);
    $lockAcquired = cloudHotelIdMigrationAcquireLock($lockConnection);
    cloudHotelIdMariaDbVerifyAssert($lockAcquired, 'named_migration_lock_unavailable');

    Db::connect(null, true);
    $transactionConnectionId = cloudHotelIdMigrationConnectionId();
    Db::execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    Db::startTrans();
    $transactionOpen = true;
    cloudHotelIdMigrationAssertIdentity(5, 80, 7, 'Disposable Migration Hotel');
    $preflight = cloudHotelIdMigrationPreflight(5, 80);
    cloudHotelIdMariaDbVerifyAssert(!isset($preflight['monthly_tasks.hotel_id']), 'absent_optional_table_planned');
    cloudHotelIdMariaDbVerifyAssert(isset($preflight['hotels.id']), 'required_hotels_id_not_planned');
    $jsonPreflight = cloudHotelIdMigrationJsonPreflight(5, 80, true);
    $updated = cloudHotelIdMigrationApplyRelational($preflight, 5, 80);
    $updatedJson = cloudHotelIdMigrationApplyMutableJson($jsonPreflight);
    $postflight = cloudHotelIdMigrationAssertRelationalPostflight(
        $preflight,
        5,
        80,
        7,
        'Disposable Migration Hotel'
    );
    $transactionJsonReadback = cloudHotelIdMigrationAssertJsonReadback($jsonPreflight, 5, 80);
    Db::commit();
    $transactionOpen = false;

    Db::execute(
        'CREATE TABLE `future_fk_link` ('
        . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`hotel_id` INT UNSIGNED NOT NULL,'
        . 'PRIMARY KEY (`id`),CONSTRAINT `fk_verify_postcommit_hotel` '
        . 'FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`)) ENGINE=InnoDB'
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationPostCommitAudit(
            5,
            80,
            7,
            'Disposable Migration Hotel',
            $transactionConnectionId,
            $preflight,
            $jsonPreflight
        ),
        'postcommit_foreign_keys_to_hotels_require_explicit_review'
    );
    Db::execute('DROP TABLE `future_fk_link`');
    $result['checks']['postcommit_new_foreign_key_rediscovery_blocked'] = true;

    Db::execute(
        'CREATE TABLE `strategy_data_snapshots` ('
        . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`raw_json` LONGTEXT NOT NULL,'
        . 'PRIMARY KEY (`id`)) ENGINE=InnoDB'
    );
    Db::execute(
        'INSERT INTO `strategy_data_snapshots` (`raw_json`) VALUES (?)',
        ['{"system_hotel_id":5}']
    );
    cloudHotelIdMariaDbVerifyExpectFailure(
        static fn() => cloudHotelIdMigrationPostCommitAudit(
            5,
            80,
            7,
            'Disposable Migration Hotel',
            $transactionConnectionId,
            $preflight,
            $jsonPreflight
        ),
        'postcommit_immutable_json_reference_set_changed'
    );
    Db::execute('DROP TABLE `strategy_data_snapshots`');
    $result['checks']['postcommit_new_immutable_json_location_rediscovery_blocked'] = true;

    $postCommitAudit = cloudHotelIdMigrationPostCommitAudit(
        5,
        80,
        7,
        'Disposable Migration Hotel',
        $transactionConnectionId,
        $preflight,
        $jsonPreflight
    );
    cloudHotelIdMariaDbVerifyAssert($postCommitAudit['new_connection_verified'] === true, 'new_connection_audit_missing');
    cloudHotelIdMariaDbVerifyAssert(
        (int)$postCommitAudit['hotels_next_auto_increment'] > 80,
        'postcommit_hotels_auto_increment_not_above_target'
    );
    $lockReleased = cloudHotelIdMigrationReleaseLock($lockConnection);
    cloudHotelIdMariaDbVerifyAssert($lockReleased, 'named_migration_lock_release_failed');

    $positiveRows = Db::query(
        'SELECT '
        . '(SELECT COUNT(*) FROM `hotels` WHERE `id`=80) AS hotel_count,'
        . '(SELECT COUNT(*) FROM `daily_reports` WHERE `hotel_id`=80) AS report_count,'
        . '(SELECT COUNT(*) FROM `competitor_price_log` WHERE `store_id`=80) AS store_count,'
        . '(SELECT COUNT(*) FROM `ota_local_collector_account_hotels` '
        . 'WHERE `system_hotel_id`=80 AND `active_system_hotel_id`=80) AS derived_count'
    );
    $positive = $positiveRows[0] ?? [];
    cloudHotelIdMariaDbVerifyAssert((int)($positive['hotel_count'] ?? 0) === 1, 'hotel_not_migrated');
    cloudHotelIdMariaDbVerifyAssert((int)($positive['report_count'] ?? 0) === 2, 'optional_positive_not_migrated');
    cloudHotelIdMariaDbVerifyAssert((int)($positive['store_count'] ?? 0) === 1, 'store_id_not_migrated');
    cloudHotelIdMariaDbVerifyAssert((int)($positive['derived_count'] ?? 0) === 1, 'derived_identity_not_refreshed');

    $negativeRows = Db::query(
        'SELECT `hotel_id`,`ota_hotel_id` FROM `competitor_price_log` LIMIT 1'
    );
    cloudHotelIdMariaDbVerifyAssert((int)($negativeRows[0]['hotel_id'] ?? 0) === 5, 'negative_hotel_id_changed');
    cloudHotelIdMariaDbVerifyAssert((string)($negativeRows[0]['ota_hotel_id'] ?? '') === '5', 'ota_hotel_id_changed');
    $collectorRows = Db::query(
        'SELECT `platform_hotel_id`,`active_platform_hotel_id` '
        . 'FROM `ota_local_collector_account_hotels` LIMIT 1'
    );
    cloudHotelIdMariaDbVerifyAssert(
        (string)($collectorRows[0]['platform_hotel_id'] ?? '') === '5'
        && (string)($collectorRows[0]['active_platform_hotel_id'] ?? '') === '5',
        'platform_hotel_identity_changed'
    );

    $configRows = Db::query(
        'SELECT `config_key`,`config_value` FROM `system_configs` ORDER BY `config_key`'
    );
    foreach ($configRows as $row) {
        $decoded = json_decode((string)$row['config_value'], true, 512, JSON_THROW_ON_ERROR);
        cloudHotelIdMariaDbVerifyAssert($decoded['system_hotel_id'] === 80, 'system_config_int_not_migrated');
        cloudHotelIdMariaDbVerifyAssert($decoded['hotel_id'] === '80', 'system_config_string_not_migrated');
        cloudHotelIdMariaDbVerifyAssert($decoded['platform_hotel_id'] === '5', 'system_config_external_id_changed');
    }
    $platformRows = Db::query('SELECT `system_hotel_id`,`config_json` FROM `platform_data_sources` LIMIT 1');
    $platformDecoded = json_decode((string)$platformRows[0]['config_json'], true, 512, JSON_THROW_ON_ERROR);
    cloudHotelIdMariaDbVerifyAssert((int)$platformRows[0]['system_hotel_id'] === 80, 'platform_scope_not_migrated');
    cloudHotelIdMariaDbVerifyAssert($platformDecoded['collector_hotel_id'] === 80, 'collector_hotel_id_not_migrated');
    cloudHotelIdMariaDbVerifyAssert($platformDecoded['system_hotel_id'] === '80', 'platform_system_hotel_id_not_migrated');
    cloudHotelIdMariaDbVerifyAssert(
        $platformDecoded['nested']['source_system_hotel_id'] === '80',
        'nested_source_system_hotel_id_not_migrated'
    );
    cloudHotelIdMariaDbVerifyAssert($platformDecoded['hotel_id'] === '5', 'platform_external_hotel_id_changed');
    cloudHotelIdMariaDbVerifyAssert($platformDecoded['platform_hotel_id'] === '5', 'platform_hotel_id_changed');

    $immutableRows = Db::query('SELECT `raw_payload` FROM `platform_data_raw_records` LIMIT 1');
    $immutableAfter = (string)($immutableRows[0]['raw_payload'] ?? '');
    cloudHotelIdMariaDbVerifyAssert(
        hash('sha256', $immutableAfter) === $immutableSha256,
        'immutable_raw_payload_changed'
    );

    $receipt = [
        'status' => 'local_disposable_mariadb_behavior_verified',
        ...cloudHotelIdMigrationReceiptBase(5, 80, 7, 'Disposable Migration Hotel', $preflight, $jsonPreflight),
        'updated_rows' => $updated,
        'updated_mutable_json_rows' => $updatedJson,
        'postflight' => $postflight,
        'execution_evidence' => [
            'scope' => 'local_disposable_mariadb_only',
            'external_runtime_config_verified' => false,
            'operator_all_writer_quiescence_verified' => false,
            'transaction_json_readback' => $transactionJsonReadback,
            'postcommit_new_connection_audit' => $postCommitAudit,
            'named_lock_released' => $lockReleased,
        ],
        'database_write_performed' => true,
    ];
    $receiptJson = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    cloudHotelIdMariaDbVerifyAssert(!str_contains($receiptJson, 'immutable-sentinel'), 'receipt_leaked_raw_json');
    cloudHotelIdMariaDbVerifyAssert(!str_contains($receiptJson, 'before_raw'), 'receipt_leaked_before_raw');
    cloudHotelIdMariaDbVerifyAssert(!str_contains($receiptJson, 'after_raw'), 'receipt_leaked_after_raw');

    $result['checks'] = [
        ...$result['checks'],
        'optional_missing_table_not_updated' => true,
        'registered_positive_5_to_80' => true,
        'negative_and_external_ids_unchanged' => true,
        'derived_readonly_identity_verified' => true,
        'mutable_json_policy_specific_migration_verified' => true,
        'immutable_json_raw_digest_preserved' => true,
        'new_connection_postcommit_audit_verified' => true,
        'named_lock_held_through_postcommit_audit' => true,
        'hotels_auto_increment_above_80' => true,
        'receipt_raw_json_not_disclosed' => true,
    ];
    $result['status'] = 'passed';
} catch (Throwable $exception) {
    $failure = $exception;
    $result['error_code'] = $exception->getMessage();
} finally {
    if ($transactionOpen && class_exists(Db::class)) {
        try {
            Db::rollback();
        } catch (Throwable) {
            // Cleanup continues through the disposable database boundary.
        }
    }
    if ($lockAcquired && !$lockReleased && is_object($lockConnection)) {
        try {
            $lockReleased = cloudHotelIdMigrationReleaseLock($lockConnection);
        } catch (Throwable) {
            // Dropping the disposable database remains mandatory.
        }
    }
    if ($serverPdo instanceof PDO && $databaseName !== '') {
        try {
            $serverPdo->exec('DROP DATABASE IF EXISTS ' . cloudHotelIdMariaDbVerifyIdentifier($databaseName));
            $statement = $serverPdo->prepare(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'
            );
            $statement->execute([$databaseName]);
            $result['cleanup_remaining'] = (int)$statement->fetchColumn();
        } catch (Throwable $cleanupException) {
            $result['cleanup_remaining'] = -1;
            if (!($failure instanceof Throwable)) {
                $failure = $cleanupException;
                $result['status'] = 'failed';
                $result['error_code'] = 'disposable_database_cleanup_failed';
            }
        }
    }
}

if ($databaseName !== '' && $result['cleanup_remaining'] !== 0) {
    $result['status'] = 'failed';
    $result['error_code'] = 'disposable_database_cleanup_not_verified';
}
if ($databaseName !== '' && $result['cleanup_remaining'] === 0) {
    $result['database_scope'] = 'disposable_local_e2e_database_dropped';
} elseif ($databaseName !== '') {
    $result['database_scope'] = 'disposable_local_e2e_database_cleanup_unverified';
}
$result['credentials_disclosed'] = false;
$result['raw_json_values_disclosed'] = false;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['status'] === 'passed' ? 0 : 1);
