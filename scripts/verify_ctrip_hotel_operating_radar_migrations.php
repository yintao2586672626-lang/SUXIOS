#!/usr/bin/env php
<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require dirname(__DIR__) . '/vendor/autoload.php';

if (getenv('SUXI_CI_MYSQL_VERIFY') !== '1') {
    fwrite(STDERR, "SUXI_CI_MYSQL_VERIFY=1 is required.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'suxi_ctrip_radar_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_e2e';
$server = null;
$databasePdo = null;
$exitCode = 0;
$summary = [];

try {
    if (preg_match('/^suxi_ctrip_radar_[a-f0-9_]+_e2e$/D', $database) !== 1) {
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
        throw new RuntimeException('Radar migration verifier refused a non-loopback database host.');
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
  `type` VARCHAR(50) DEFAULT NULL,
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

    $migrationNames = [
        '20260811_0_expand_knowledge_chunk_type_for_radar.sql',
        '20260811_absorb_ctrip_hotel_operating_radar_knowledge.sql',
        '20260811_b_repair_ctrip_hotel_operating_radar_chunk_type.sql',
        '20260811_c_restore_ctrip_hotel_operating_radar_seed_identity.sql',
        '20260811_d_expand_ctrip_hotel_operating_radar_online_knowledge.sql',
        '20260811_e_correct_ctrip_radar_ranking_disclosure_scope.sql',
        '20260811_f_absorb_ctrip_flow_rules_pdf_reference.sql',
    ];
    $runSequence = static function () use ($databasePdo, $root, $migrationNames): void {
        foreach ($migrationNames as $migrationName) {
            $path = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR
                . 'migrations' . DIRECTORY_SEPARATOR . $migrationName;
            $sql = file_get_contents($path);
            if (!is_string($sql)) {
                throw new RuntimeException('Cannot read migration: ' . $migrationName);
            }
            $databasePdo->exec($sql);
        }
    };

    $snapshot = static function () use ($databasePdo): array {
        $unitCount = (int)$databasePdo->query(
            "SELECT COUNT(*) FROM knowledge_units "
            . "WHERE name = '携程酒店经营雷达图（规划期）五维知识合同' "
            . "AND source = 'revenue_operations_decision_support'"
        )->fetchColumn();
        $unitId = (int)$databasePdo->query(
            "SELECT unit_id FROM knowledge_units WHERE name = '携程酒店经营雷达图（规划期）五维知识合同' "
            . "AND source = 'revenue_operations_decision_support' ORDER BY unit_id LIMIT 1"
        )->fetchColumn();
        $seedOwner = 'suxios.ctrip_hotel_operating_radar_knowledge';
        $chunkStatement = $databasePdo->prepare(
            "SELECT type, JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_key')) AS seed_key "
            . 'FROM knowledge_chunks WHERE unit_id = ? '
            . "AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_owner')) = ? ORDER BY chunk_id"
        );
        $chunkStatement->execute([$unitId, $seedOwner]);
        $chunks = $chunkStatement->fetchAll();
        $onlineSeedOwner = 'suxios.ctrip_hotel_operating_radar_online_expansion';
        $onlineChunkStatement = $databasePdo->prepare(
            "SELECT type, JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_key')) AS seed_key "
            . 'FROM knowledge_chunks WHERE unit_id = ? '
            . "AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_owner')) = ? ORDER BY chunk_id"
        );
        $onlineChunkStatement->execute([$unitId, $onlineSeedOwner]);
        $onlineChunks = $onlineChunkStatement->fetchAll();
        $pdfSeedOwner = 'suxios.ctrip_flow_rules_pdf_20260811';
        $pdfChunkStatement = $databasePdo->prepare(
            "SELECT type, JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_key')) AS seed_key "
            . 'FROM knowledge_chunks WHERE unit_id = ? '
            . "AND JSON_UNQUOTE(JSON_EXTRACT(content, '$.seed_owner')) = ? ORDER BY chunk_id"
        );
        $pdfChunkStatement->execute([$unitId, $pdfSeedOwner]);
        $pdfChunks = $pdfChunkStatement->fetchAll();
        $longType = 'ctrip_radar_user_journey_and_platform_focus_reference';
        $longKey = 'ctrip_hotel_operating_radar:' . $longType;
        $longRows = array_values(array_filter(
            $chunks,
            static fn(array $row): bool => ($row['type'] ?? null) === $longType
                && ($row['seed_key'] ?? null) === $longKey
        ));
        $distinctKeys = array_values(array_unique(array_map(
            static fn(array $row): string => (string)($row['seed_key'] ?? ''),
            $chunks
        )));
        $onlineDistinctKeys = array_values(array_unique(array_map(
            static fn(array $row): string => (string)($row['seed_key'] ?? ''),
            $onlineChunks
        )));
        $pdfDistinctKeys = array_values(array_unique(array_map(
            static fn(array $row): string => (string)($row['seed_key'] ?? ''),
            $pdfChunks
        )));
        $typeLength = (int)$databasePdo->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'knowledge_chunks' AND COLUMN_NAME = 'type'"
        )->fetchColumn();
        $staffCount = (int)$databasePdo->query(
            "SELECT COUNT(*) FROM knowledge_base WHERE hotel_id = 0 "
            . "AND title = '携程酒店经营雷达图（规划期）五维知识合同'"
        )->fetchColumn();
        $truthProfileVersion = (string)$databasePdo->query(
            "SELECT truth_profile_version FROM knowledge_units WHERE unit_id = {$unitId}"
        )->fetchColumn();
        $pdfStaffMarkerCount = (int)$databasePdo->query(
            "SELECT (CHAR_LENGTH(content) - CHAR_LENGTH(REPLACE(content, '## PDF补充（第三方待核验）', ''))) "
            . "/ CHAR_LENGTH('## PDF补充（第三方待核验）') FROM knowledge_base "
            . "WHERE hotel_id = 0 AND title = '携程酒店经营雷达图（规划期）五维知识合同' LIMIT 1"
        )->fetchColumn();

        return [
            'unit_count' => $unitCount,
            'chunk_count' => count($chunks),
            'distinct_seed_key_count' => count($distinctKeys),
            'online_chunk_count' => count($onlineChunks),
            'online_distinct_seed_key_count' => count($onlineDistinctKeys),
            'pdf_chunk_count' => count($pdfChunks),
            'pdf_distinct_seed_key_count' => count($pdfDistinctKeys),
            'total_chunk_count' => count($chunks) + count($onlineChunks) + count($pdfChunks),
            'long_identity_count' => count($longRows),
            'knowledge_base_count' => $staffCount,
            'pdf_staff_marker_count' => $pdfStaffMarkerCount,
            'type_max_length' => $typeLength,
            'truth_profile_version' => $truthProfileVersion,
        ];
    };

    $runSequence();
    $first = $snapshot();
    $runSequence();
    $second = $snapshot();
    $expected = [
        'unit_count' => 1,
        'chunk_count' => 6,
        'distinct_seed_key_count' => 6,
        'online_chunk_count' => 5,
        'online_distinct_seed_key_count' => 5,
        'pdf_chunk_count' => 2,
        'pdf_distinct_seed_key_count' => 2,
        'total_chunk_count' => 13,
        'long_identity_count' => 1,
        'knowledge_base_count' => 1,
        'pdf_staff_marker_count' => 1,
        'type_max_length' => 80,
        'truth_profile_version' => '2026-08-11.4',
    ];
    if ($first !== $expected || $second !== $expected) {
        throw new RuntimeException('Strict fresh/replay migration contract failed.');
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
        && preg_match('/^suxi_ctrip_radar_[a-f0-9_]+_e2e$/D', $database) === 1
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

fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
exit($exitCode);
