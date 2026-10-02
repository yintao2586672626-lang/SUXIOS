#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require dirname(__DIR__) . '/tests/bootstrap.php';

if (getenv('SUXI_CI_MYSQL_VERIFY') !== '1') {
    fwrite(STDERR, "SUXI_CI_MYSQL_VERIFY=1 is required.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'suxi_master_lens_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_e2e';
$server = null;
$databasePdo = null;
$exitCode = 0;
$summary = [];

try {
    if (preg_match('/^suxi_master_lens_[a-f0-9_]+_e2e$/D', $database) !== 1) {
        throw new RuntimeException('Unsafe temporary database name.');
    }

    $config = SchemaVersionService::databaseConfigFromEnvironment($root, [
        'DB_HOST' => getenv('DB_HOST') !== false ? getenv('DB_HOST') : null,
        'DB_PORT' => getenv('DB_PORT') !== false ? getenv('DB_PORT') : null,
        'DB_USER' => getenv('DB_USER') !== false ? getenv('DB_USER') : null,
        'DB_CHARSET' => 'utf8mb4',
    ]);
    $host = strtolower(trim((string)($config['hostname'] ?? '')));
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
        && getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB') !== '1'
    ) {
        throw new RuntimeException('Master perspectives verifier refused a non-loopback database host.');
    }

    $server = SchemaVersionService::createPdo($config, true);
    $server->exec(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)$config['hostname'],
        (string)$config['hostport'],
        $database
    );
    $databasePdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $databasePdo->exec(
        "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
    );

    $databasePdo->exec(<<<'SQL'
CREATE TABLE `knowledge_units` (
  `unit_id` INT NOT NULL AUTO_INCREMENT,
  `hotel_id` INT NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL,
  `source` VARCHAR(50) DEFAULT NULL,
  `status` ENUM('pending','done','error') NOT NULL DEFAULT 'pending',
  `description` TEXT DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `lifecycle_status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `lifecycle_reason` VARCHAR(255) DEFAULT NULL,
  `reviewed_at` DATETIME DEFAULT NULL,
  `review_due_at` DATETIME DEFAULT NULL,
  `known_knowns` JSON DEFAULT NULL,
  `known_unknowns` JSON DEFAULT NULL,
  `truth_profile_version` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `knowledge_chunks` (
  `chunk_id` INT NOT NULL AUTO_INCREMENT,
  `unit_id` INT NOT NULL,
  `type` VARCHAR(80) DEFAULT NULL,
  `content` JSON DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`chunk_id`),
  KEY `idx_knowledge_chunks_unit_id` (`unit_id`),
  KEY `idx_knowledge_chunks_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `knowledge_base` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `hotel_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `category_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL,
  `content` LONGTEXT NOT NULL,
  `keywords` TEXT DEFAULT NULL,
  `tags` JSON DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `view_count` INT NOT NULL DEFAULT 0,
  `like_count` INT NOT NULL DEFAULT 0,
  `create_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $migrationPath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR
        . 'migrations' . DIRECTORY_SEPARATOR
        . '20260820_b_absorb_master_perspectives_multi_lens_knowledge.sql';
    $migration = file_get_contents($migrationPath);
    if (!is_string($migration)) {
        throw new RuntimeException('Cannot read master perspectives migration.');
    }
    $runMigration = static function () use ($databasePdo, $migration): void {
        $databasePdo->exec($migration);
    };

    $snapshot = static function () use ($databasePdo): array {
        $unitName = '酒店经营多视角审视与反证方法';
        $source = 'revenue_operations_decision_support';
        $unitStatement = $databasePdo->prepare(
            'SELECT unit_id, hotel_id, status, lifecycle_status, truth_profile_version '
            . 'FROM knowledge_units WHERE name = ? AND source = ? ORDER BY unit_id'
        );
        $unitStatement->execute([$unitName, $source]);
        $units = $unitStatement->fetchAll();
        $unitId = isset($units[0]['unit_id']) ? (int)$units[0]['unit_id'] : 0;
        $seedOwner = 'suxios.master_perspectives_multi_lens_knowledge';
        $chunkStatement = $databasePdo->prepare(
            "SELECT type, JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_key')) AS seed_key, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.evidence_grade')) AS evidence_grade, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.decision_policy')) AS decision_policy, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.decision_safe')) AS decision_safe, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.task_draft_safe')) AS task_draft_safe, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.contains_current_hotel_fact')) AS contains_current_hotel_fact, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.external_write_authorized')) AS external_write_authorized, "
            . "JSON_UNQUOTE(JSON_EXTRACT(content, '$.source_manifest.sha256')) AS source_sha256 "
            . 'FROM knowledge_chunks WHERE unit_id = ? '
            . "AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_owner')) = ? ORDER BY type"
        );
        $chunkStatement->execute([$unitId, $seedOwner]);
        $chunks = $chunkStatement->fetchAll();
        $distinctKeys = array_values(array_unique(array_map(
            static fn(array $row): string => (string)($row['seed_key'] ?? ''),
            $chunks
        )));
        $allRowsMatchBoundaries = count(array_filter(
            $chunks,
            static fn(array $row): bool => ($row['evidence_grade'] ?? null) === 'C'
                && ($row['decision_policy'] ?? null) === 'reference_only_human_review'
                && ($row['decision_safe'] ?? null) === 'false'
                && ($row['task_draft_safe'] ?? null) === 'false'
                && ($row['contains_current_hotel_fact'] ?? null) === 'false'
                && ($row['external_write_authorized'] ?? null) === 'false'
                && ($row['source_sha256'] ?? null)
                    === '32C06DE45983119EFD6F7CFA9B1E8CA5CE59F8A4E5339267DC383A5FC0EE3970'
        )) === count($chunks);
        $knowledgeBaseStatement = $databasePdo->prepare(
            'SELECT COUNT(*) FROM knowledge_base WHERE tenant_id = 0 AND hotel_id = 0 '
            . 'AND title = ? AND is_enabled = 1 '
            . "AND content LIKE '%缺酒店、来源、日期或指标口径时返回not_ready%'"
        );
        $knowledgeBaseStatement->execute([$unitName]);

        return [
            'unit_count' => count($units),
            'unit_hotel_id' => isset($units[0]['hotel_id']) ? (int)$units[0]['hotel_id'] : null,
            'unit_status' => $units[0]['status'] ?? null,
            'unit_lifecycle_status' => $units[0]['lifecycle_status'] ?? null,
            'truth_profile_version' => $units[0]['truth_profile_version'] ?? null,
            'chunk_count' => count($chunks),
            'distinct_seed_key_count' => count($distinctKeys),
            'all_chunks_reference_only_and_fail_closed' => $allRowsMatchBoundaries,
            'knowledge_base_count' => (int)$knowledgeBaseStatement->fetchColumn(),
        ];
    };

    $runMigration();
    $first = $snapshot();
    $runMigration();
    $second = $snapshot();
    $expected = [
        'unit_count' => 1,
        'unit_hotel_id' => 0,
        'unit_status' => 'done',
        'unit_lifecycle_status' => 'active',
        'truth_profile_version' => '2026-08-20.1',
        'chunk_count' => 10,
        'distinct_seed_key_count' => 10,
        'all_chunks_reference_only_and_fail_closed' => true,
        'knowledge_base_count' => 1,
    ];
    if ($first !== $expected || $second !== $expected) {
        throw new RuntimeException('Strict fresh/replay save-readback contract failed: '
            . json_encode(['first' => $first, 'second' => $second], JSON_UNESCAPED_UNICODE));
    }

    $summary = [
        'status' => 'pass',
        'sql_mode' => (string)$databasePdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(),
        'first_run' => $first,
        'second_run' => $second,
    ];
} catch (Throwable $exception) {
    $exitCode = 1;
    $summary = [
        'status' => 'fail',
        'error' => $exception->getMessage(),
    ];
} finally {
    $databasePdo = null;
    if ($server instanceof PDO
        && preg_match('/^suxi_master_lens_[a-f0-9_]+_e2e$/D', $database) === 1
    ) {
        $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
}

if ($server instanceof PDO) {
    $remaining = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $remaining->execute([$database]);
    $summary['temporary_databases_remaining'] = (int)$remaining->fetchColumn();
    if ($summary['temporary_databases_remaining'] !== 0) {
        $summary['status'] = 'fail';
        $summary['error'] = 'Temporary database cleanup failed.';
        $exitCode = 1;
    }
}

fwrite(STDOUT, json_encode(
    $summary,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
) . PHP_EOL);
exit($exitCode);
