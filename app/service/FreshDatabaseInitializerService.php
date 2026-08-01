<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use RuntimeException;
use Throwable;

final class FreshDatabaseInitializerService
{
    private const GOVERNANCE_TABLES = [
        'schema_versions',
        'schema_migration_failures',
        'schema_baseline_sources',
    ];

    /**
     * @param array<string,mixed> $config
     * @return array{baseline_registered:int,executed:list<string>,status:array<string,mixed>}
     */
    public static function initialize(array $config, string $root): array
    {
        $database = trim((string)($config['database'] ?? ''));
        $charset = trim((string)($config['charset'] ?? 'utf8mb4'));
        if (preg_match('/^[A-Za-z0-9_]+$/D', $database) !== 1) {
            throw new RuntimeException('Database name must contain only letters, numbers, and underscores.');
        }
        if (strtolower($charset) !== 'utf8mb4') {
            throw new RuntimeException('Database charset must be utf8mb4.');
        }

        $server = SchemaVersionService::createPdo($config, true);
        $lockName = 'suxios_fresh_init_' . substr(hash('sha256', $database), 0, 32);
        self::acquireLock($server, $lockName);

        try {
            $exists = self::databaseExists($server, $database);
            $initialObjects = $exists ? self::databaseObjects($server, $database) : [];
            if ($initialObjects !== [] && self::onlyGovernanceObjects($initialObjects)) {
                self::cleanupDatabaseObjects($server, $database);
                $initialObjects = [];
            }
            if (!$exists) {
                $server->exec(
                    'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
                );
            }

            try {
                return SchemaVersionService::fromDatabaseConfig($config, $root)
                    ->initializeFreshFromInitFull();
            } catch (Throwable $exception) {
                if (!$exists) {
                    $cleanup = self::dropCreatedDatabase($server, $database);
                } elseif ($initialObjects === []) {
                    $cleanup = self::cleanupDatabaseObjects($server, $database);
                } else {
                    $cleanup = 'The pre-existing non-empty database was not modified by cleanup.';
                }
                throw new RuntimeException(
                    $exception->getMessage() . ' ' . $cleanup,
                    0,
                    $exception
                );
            }
        } finally {
            self::releaseLock($server, $lockName);
        }
    }

    private static function databaseExists(PDO $server, string $database): bool
    {
        $statement = $server->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $statement->execute([$database]);
        return (int)$statement->fetchColumn() > 0;
    }

    private static function dropCreatedDatabase(PDO $server, string $database): string
    {
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
            return 'The newly created database was removed after the failed initialization.';
        } catch (Throwable $cleanupException) {
            return 'Cleanup of the newly created database failed: ' . $cleanupException->getMessage();
        }
    }

    private static function acquireLock(PDO $server, string $lockName): void
    {
        $statement = $server->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$lockName]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another fresh database initialization is already running.');
        }
    }

    private static function releaseLock(PDO $server, string $lockName): void
    {
        try {
            $statement = $server->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (Throwable) {
            // The connection itself releases the lock; preserve the primary result.
        }
    }

    /** @return list<array{name:string,type:string}> */
    private static function databaseObjects(PDO $server, string $database): array
    {
        $statement = $server->prepare(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE DESC, TABLE_NAME ASC'
        );
        $statement->execute([$database]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_map(
            static fn(array $row): array => [
                'name' => (string)$row['name'],
                'type' => (string)$row['type'],
            ],
            is_array($rows) ? $rows : []
        ));
    }

    /** @param list<array{name:string,type:string}> $objects */
    private static function onlyGovernanceObjects(array $objects): bool
    {
        foreach ($objects as $object) {
            if (!in_array($object['name'], self::GOVERNANCE_TABLES, true)) {
                return false;
            }
        }
        return $objects !== [];
    }

    private static function cleanupDatabaseObjects(PDO $server, string $database): string
    {
        try {
            $objects = self::databaseObjects($server, $database);
            $server->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($objects as $object) {
                    $name = str_replace('`', '``', $object['name']);
                    $qualified = '`' . $database . '`.`' . $name . '`';
                    $kind = strtoupper($object['type']) === 'VIEW' ? 'VIEW' : 'TABLE';
                    $server->exec("DROP {$kind} IF EXISTS {$qualified}");
                }
            } finally {
                $server->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            return 'The pre-existing empty database was restored after the failed initialization.';
        } catch (Throwable $cleanupException) {
            return 'Cleanup of the pre-existing empty database failed: ' . $cleanupException->getMessage();
        }
    }
}
