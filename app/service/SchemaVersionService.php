<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use RuntimeException;
use Throwable;

final class SchemaVersionService
{
    private const REGISTRY_TABLE = 'schema_versions';
    private const BASELINE_REGISTRY_TABLE = 'schema_baseline_sources';
    private const FAILURE_TABLE = 'schema_migration_failures';
    private const MIGRATION_PATTERN = '/^\d{8}_[a-z0-9_]+\.sql$/D';
    private const MYSQL_LOCK_PREFIX = 'suxios_schema_versions_';

    private PDO $pdo;
    private string $root;
    private string $driver;

    public function __construct(PDO $pdo, string $root)
    {
        $resolvedRoot = realpath($root);
        if (!is_string($resolvedRoot)) {
            throw new RuntimeException("Project root does not exist: {$root}");
        }

        $this->pdo = $pdo;
        $this->root = rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
        $this->driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param array<string, mixed> $config */
    public static function fromDatabaseConfig(array $config, string $root): self
    {
        return new self(self::createPdo($config), $root);
    }

    /** @param array<string, mixed> $config */
    public static function createPdo(array $config, bool $withoutDatabase = false): PDO
    {
        $type = strtolower(trim((string)($config['type'] ?? 'mysql')));
        if ($type !== 'mysql') {
            throw new RuntimeException('Schema version governance currently requires MySQL/MariaDB.');
        }

        $host = trim((string)($config['hostname'] ?? $config['host'] ?? '127.0.0.1'));
        $port = (int)($config['hostport'] ?? $config['port'] ?? 3306);
        $database = trim((string)($config['database'] ?? 'hotelx'));
        $charset = trim((string)($config['charset'] ?? 'utf8mb4'));
        $username = (string)($config['username'] ?? $config['user'] ?? 'root');
        $password = (string)($config['password'] ?? '');

        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Database host or port is invalid.');
        }
        if (strtolower($charset) !== 'utf8mb4') {
            throw new RuntimeException('Database charset must be utf8mb4.');
        }
        if (!$withoutDatabase && preg_match('/^[A-Za-z0-9_]+$/D', $database) !== 1) {
            throw new RuntimeException('Database name must contain only letters, numbers, and underscores.');
        }

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if (!$withoutDatabase) {
            $dsn .= ";dbname={$database}";
        }

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @param array<string, string|int|null> $overrides
     * @return array<string, mixed>
     */
    public static function databaseConfigFromEnvironment(string $root, array $overrides = []): array
    {
        $fileValues = self::readEnvFile(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env');
        $read = static function (string $key, string $default = '') use ($fileValues, $overrides): string {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null && $overrides[$key] !== '') {
                return (string)$overrides[$key];
            }
            $processValue = getenv($key);
            if ($processValue !== false) {
                return (string)$processValue;
            }
            return (string)($fileValues[$key] ?? $default);
        };

        return [
            'type' => $read('DB_TYPE', 'mysql'),
            'hostname' => $read('DB_HOST', '127.0.0.1'),
            'hostport' => $read('DB_PORT', '3306'),
            'database' => $read('DB_NAME', 'hotelx'),
            'username' => $read('DB_USER', 'root'),
            'password' => $read('DB_PASS', ''),
            'charset' => $read('DB_CHARSET', 'utf8mb4'),
        ];
    }

    /** @return list<array{migration: string, version: string, checksum: string, path: string}> */
    public static function migrationCatalog(string $root): array
    {
        $directory = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'migrations';
        $paths = glob($directory . DIRECTORY_SEPARATOR . '*.sql');
        if ($paths === false) {
            throw new RuntimeException("Unable to read migration directory: {$directory}");
        }

        usort($paths, static fn(string $left, string $right): int => strcmp(basename($left), basename($right)));
        $catalog = [];
        $versions = [];
        foreach ($paths as $path) {
            $migration = basename($path);
            if (preg_match(self::MIGRATION_PATTERN, $migration) !== 1) {
                throw new RuntimeException("Invalid migration filename: {$migration}");
            }

            $version = substr($migration, 0, -4);
            if (isset($versions[$version])) {
                throw new RuntimeException("Duplicate migration version: {$version}");
            }
            $versions[$version] = true;
            $checksum = hash_file('sha256', $path);
            if (!is_string($checksum)) {
                throw new RuntimeException("Unable to checksum migration: {$migration}");
            }
            $catalog[] = [
                'migration' => $migration,
                'version' => $version,
                'checksum' => $checksum,
                'path' => $path,
            ];
        }

        if ($catalog === []) {
            throw new RuntimeException('No database migrations were found.');
        }

        return $catalog;
    }

    /**
     * @return array{
     *   ready: bool,
     *   registry_exists: bool,
     *   current_version: ?string,
     *   required_version: ?string,
     *   applied_count: int,
     *   required_count: int,
     *   pending: list<string>,
     *   version_mismatches: list<string>,
     *   checksum_mismatches: list<string>,
     *   missing_checksums: list<string>,
     *   unknown_registrations: list<string>,
     *   baseline_checksum_mismatches: list<string>,
     *   baseline_missing: list<string>,
     *   baseline_unknown: list<string>,
     *   unresolved_failures: list<string>,
     *   application_table_count: int
     * }
     */
    public function status(): array
    {
        $catalog = self::migrationCatalog($this->root);
        $requiredByMigration = [];
        foreach ($catalog as $migration) {
            $requiredByMigration[$migration['migration']] = [
                'version' => $migration['version'],
                'checksum' => $migration['checksum'],
            ];
        }

        $registryExists = $this->registryExists();
        $rows = $registryExists ? $this->registeredRows() : [];
        $checksumSupported = $registryExists && $this->registryColumnExists('checksum');
        $registered = [];
        $mismatches = [];
        $checksumMismatches = [];
        $missingChecksums = [];
        $unknown = [];
        foreach ($rows as $row) {
            $migration = (string)$row['migration'];
            $version = (string)$row['version'];
            $checksum = trim((string)($row['checksum'] ?? ''));
            $registered[$migration] = ['version' => $version, 'checksum' => $checksum];
            if (!isset($requiredByMigration[$migration])) {
                $unknown[] = $migration;
                continue;
            }
            if (!hash_equals($requiredByMigration[$migration]['version'], $version)) {
                $mismatches[] = $migration;
            }
            if ($checksumSupported) {
                if ($checksum === '') {
                    $missingChecksums[] = $migration;
                } elseif (!hash_equals($requiredByMigration[$migration]['checksum'], $checksum)) {
                    $checksumMismatches[] = $migration;
                }
            }
        }

        $pending = [];
        $currentVersion = null;
        $contiguous = true;
        foreach ($catalog as $migration) {
            $row = $registered[$migration['migration']] ?? null;
            if ($row === null) {
                $pending[] = $migration['migration'];
                $contiguous = false;
                continue;
            }
            $validVersion = hash_equals($migration['version'], $row['version']);
            $validChecksum = !$checksumSupported
                || ($row['checksum'] !== '' && hash_equals($migration['checksum'], $row['checksum']));
            if ($contiguous && $validVersion && $validChecksum) {
                $currentVersion = $migration['version'];
            } else {
                $contiguous = false;
            }
        }

        sort($mismatches);
        sort($checksumMismatches);
        sort($missingChecksums);
        sort($unknown);
        $requiredVersion = $catalog[count($catalog) - 1]['version'] ?? null;
        $applicationTableCount = $this->applicationTableCount();
        $baselineStatus = $this->baselineSourceStatus();
        $unresolvedFailures = $this->unresolvedMigrationFailures();
        $executionKindSupported = $registryExists && $this->registryColumnExists('execution_kind');

        return [
            'ready' => $registryExists
                && $checksumSupported
                && $executionKindSupported
                && $pending === []
                && $mismatches === []
                && $checksumMismatches === []
                && $missingChecksums === []
                && $unknown === []
                && $baselineStatus['registry_exists']
                && $baselineStatus['missing'] === []
                && $baselineStatus['checksum_mismatches'] === []
                && $baselineStatus['unknown'] === []
                && $unresolvedFailures === [],
            'registry_exists' => $registryExists,
            'registry_checksum_supported' => $checksumSupported,
            'registry_execution_kind_supported' => $executionKindSupported,
            'current_version' => $currentVersion,
            'required_version' => $requiredVersion,
            'applied_count' => count($registered),
            'required_count' => count($catalog),
            'pending' => $pending,
            'version_mismatches' => $mismatches,
            'checksum_mismatches' => $checksumMismatches,
            'missing_checksums' => $missingChecksums,
            'unknown_registrations' => $unknown,
            'baseline_registry_exists' => $baselineStatus['registry_exists'],
            'baseline_required_count' => $baselineStatus['required_count'],
            'baseline_registered_count' => $baselineStatus['registered_count'],
            'baseline_missing' => $baselineStatus['missing'],
            'baseline_checksum_mismatches' => $baselineStatus['checksum_mismatches'],
            'baseline_unknown' => $baselineStatus['unknown'],
            'unresolved_failures' => $unresolvedFailures,
            'application_table_count' => $applicationTableCount,
        ];
    }

    public function requiresLegacyBaseline(): bool
    {
        if ($this->applicationTableCount() === 0) {
            return false;
        }

        return !$this->registryExists() || $this->registeredRows() === [];
    }

    public function ensureRegistryTable(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_versions ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'migration VARCHAR(191) NOT NULL UNIQUE,'
                . 'version VARCHAR(191) NOT NULL UNIQUE,'
                . 'checksum CHAR(64) NULL,'
                . "execution_kind VARCHAR(32) NOT NULL DEFAULT 'executed',"
                . 'executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migration_failures ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'migration VARCHAR(191) NOT NULL,'
                . 'version VARCHAR(191) NOT NULL,'
                . 'checksum CHAR(64) NOT NULL,'
                . 'error_message TEXT NOT NULL,'
                . 'failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'resolved_at DATETIME NULL'
                . ')'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_baseline_sources ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
                . 'source VARCHAR(255) NOT NULL UNIQUE,'
                . 'checksum CHAR(64) NOT NULL,'
                . 'registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `schema_versions` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`migration` VARCHAR(191) NOT NULL,'
            . '`version` VARCHAR(191) NOT NULL,'
            . '`checksum` CHAR(64) DEFAULT NULL,'
            . "`execution_kind` VARCHAR(32) NOT NULL DEFAULT 'executed',"
            . '`executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uk_schema_versions_migration` (`migration`),'
            . 'UNIQUE KEY `uk_schema_versions_version` (`version`),'
            . 'KEY `idx_schema_versions_executed_at` (`executed_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `schema_migration_failures` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`migration` VARCHAR(191) NOT NULL,'
            . '`version` VARCHAR(191) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`error_message` TEXT NOT NULL,'
            . '`failed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . '`resolved_at` DATETIME(6) DEFAULT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'KEY `idx_schema_migration_failure_open` (`migration`, `resolved_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS `schema_baseline_sources` ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`source` VARCHAR(255) NOT NULL,'
            . '`checksum` CHAR(64) NOT NULL,'
            . '`registered_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `uk_schema_baseline_sources_source` (`source`),'
            . 'KEY `idx_schema_baseline_sources_registered_at` (`registered_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array{
     *   ready: bool,
     *   missing_tables: list<string>,
     *   missing_columns: list<string>,
     *   column_mismatches: list<string>,
     *   column_mismatch_details: array<string,array{expected:array<string,mixed>,actual:array<string,mixed>}>,
     *   missing_indexes: list<string>,
     *   index_mismatches: list<string>
     * }
     */
    public function baselineCompatibilityReport(): array
    {
        $resources = SqlSchemaResourceInspector::parse($this->root, $this->initFullSources());
        $actual = $this->databaseContract();
        $missingTables = array_values(array_diff(array_keys($resources['schema']), array_keys($actual['columns'])));
        sort($missingTables);
        $missingColumns = [];
        $columnMismatches = [];
        $columnMismatchDetails = [];
        foreach ($resources['schema'] as $table => $columns) {
            if (!isset($actual['columns'][$table])) {
                continue;
            }
            foreach ($columns as $column) {
                if (!isset($actual['columns'][$table][$column])) {
                    $missingColumns[] = $table . '.' . $column;
                    continue;
                }
                $definition = $resources['columns'][$table][$column] ?? '';
                $expected = $this->expectedColumnContract((string)$definition);
                if (!$this->columnContractsCompatible($expected, $actual['columns'][$table][$column])) {
                    $key = $table . '.' . $column;
                    $columnMismatches[] = $key;
                    $columnMismatchDetails[$key] = [
                        'expected' => $expected,
                        'actual' => $actual['columns'][$table][$column],
                    ];
                }
            }
        }
        sort($missingColumns);
        sort($columnMismatches);

        $missingIndexes = [];
        $indexMismatches = [];
        foreach ($resources['indexes'] as $table => $indexes) {
            if (!isset($actual['columns'][$table])) {
                continue;
            }
            foreach ($indexes as $name => $definition) {
                if (!isset($actual['indexes'][$table][$name])) {
                    $missingIndexes[] = $table . '.' . $name;
                    continue;
                }
                $expected = $this->expectedIndexContract((string)$definition);
                if ($expected !== $actual['indexes'][$table][$name]) {
                    $indexMismatches[] = $table . '.' . $name;
                }
            }
        }
        sort($missingIndexes);
        sort($indexMismatches);

        return [
            'ready' => $missingTables === []
                && $missingColumns === []
                && $columnMismatches === []
                && $missingIndexes === []
                && $indexMismatches === [],
            'missing_tables' => $missingTables,
            'missing_columns' => $missingColumns,
            'column_mismatches' => $columnMismatches,
            'column_mismatch_details' => $columnMismatchDetails,
            'missing_indexes' => $missingIndexes,
            'index_mismatches' => $indexMismatches,
        ];
    }

    public function baselineInitFullSources(): int
    {
        if ($this->applicationTableCount() === 0) {
            throw new RuntimeException(
                'An empty database cannot adopt the frozen baseline. Run "php scripts/init_database.php" instead.'
            );
        }

        $preflight = $this->baselineCompatibilityReport();
        if (!$preflight['ready']) {
            $details = array_slice(array_merge(
                array_map(static fn(string $table): string => 'table:' . $table, $preflight['missing_tables']),
                array_map(static fn(string $column): string => 'column:' . $column, $preflight['missing_columns']),
                array_map(
                    static fn(string $column): string => 'column_contract:' . $column . '='
                        . json_encode($preflight['column_mismatch_details'][$column], JSON_UNESCAPED_SLASHES),
                    $preflight['column_mismatches']
                ),
                array_map(static fn(string $index): string => 'index:' . $index, $preflight['missing_indexes']),
                array_map(static fn(string $index): string => 'index_contract:' . $index, $preflight['index_mismatches'])
            ), 0, 12);
            throw new RuntimeException(
                'Legacy baseline preflight failed; no migration history was registered. Missing: '
                . implode(', ', $details)
            );
        }

        $this->ensureRegistryTable();
        $this->registerMissingBaselineSources();
        $catalog = [];
        foreach (self::migrationCatalog($this->root) as $migration) {
            $catalog[$migration['migration']] = $migration;
        }

        $registered = 0;
        foreach ($this->initFullSources() as $source) {
            if (!str_starts_with($source, 'database/migrations/')) {
                continue;
            }
            $migration = basename($source);
            if (!isset($catalog[$migration])) {
                throw new RuntimeException("init_full.sql references an unknown migration: {$migration}");
            }
            if ($this->registerMigration(
                $migration,
                $catalog[$migration]['version'],
                $catalog[$migration]['checksum'],
                'baseline_adopted'
            )) {
                $registered++;
            }
        }

        return $registered;
    }

    /** @return array{executed: list<string>, status: array<string, mixed>} */
    public function migrate(): array
    {
        if ($this->applicationTableCount() === 0 && !$this->registryExists()) {
            throw new RuntimeException(
                'Database is empty. Run "php scripts/init_database.php" for a complete fresh initialization.'
            );
        }
        if ($this->requiresLegacyBaseline()) {
            throw new RuntimeException(
                'Legacy database has no migration history. Run "php think db:migrate --baseline" once after verifying the existing schema.'
            );
        }

        $this->ensureRegistryTable();
        $locked = $this->acquireLock();
        try {
            $status = $this->status();
            if ($status['version_mismatches'] !== []) {
                throw new RuntimeException(
                    'Registered migration versions do not match the code: '
                    . implode(', ', $status['version_mismatches'])
                );
            }
            if ($status['checksum_mismatches'] !== []) {
                throw new RuntimeException(
                    'Applied migration contents no longer match their registered checksums: '
                    . implode(', ', $status['checksum_mismatches'])
                );
            }
            if ($status['baseline_checksum_mismatches'] !== [] || $status['baseline_unknown'] !== []) {
                throw new RuntimeException(
                    'Frozen baseline source checksums do not match the registered database: '
                    . implode(', ', array_merge(
                        $status['baseline_checksum_mismatches'],
                        $status['baseline_unknown']
                    ))
                );
            }
            if ($status['unknown_registrations'] !== []) {
                throw new RuntimeException(
                    'Database contains migration registrations not present in the code: '
                    . implode(', ', $status['unknown_registrations'])
                );
            }

            $pendingLookup = array_fill_keys($status['pending'], true);
            $executed = [];
            foreach (self::migrationCatalog($this->root) as $migration) {
                if (!isset($pendingLookup[$migration['migration']])) {
                    continue;
                }

                try {
                    $this->executeSqlFile($migration['path']);
                    $this->registerMigration(
                        $migration['migration'],
                        $migration['version'],
                        $migration['checksum']
                    );
                } catch (Throwable $exception) {
                    $this->rollbackFailedMigrationTransaction();
                    $this->recordMigrationFailure($migration, $exception);
                    throw new RuntimeException(
                        $exception->getMessage()
                        . ' The failed attempt was recorded; partial MySQL DDL may remain. Fix the cause and rerun db:migrate.',
                        0,
                        $exception
                    );
                }
                $executed[] = $migration['migration'];
            }

            $this->registerMissingBaselineSources();
            $this->backfillMissingChecksums();
            $this->resolveRegisteredMigrationFailures();

            $finalStatus = $this->status();
            if (!$finalStatus['ready']) {
                throw new RuntimeException('Migration run ended without reaching the required database version.');
            }

            return ['executed' => $executed, 'status' => $finalStatus];
        } finally {
            if ($locked) {
                $this->releaseLock();
            }
        }
    }

    /** @return array{baseline_registered: int, executed: list<string>, status: array<string, mixed>} */
    public function initializeFreshFromInitFull(): array
    {
        if ($this->applicationTableCount() > 0 || $this->registryExists()) {
            throw new RuntimeException('Fresh initialization requires an empty database; existing tables were found.');
        }

        $this->ensureRegistryTable();
        $catalog = [];
        foreach (self::migrationCatalog($this->root) as $migration) {
            $catalog[$migration['migration']] = $migration;
        }

        $baselineRegistered = 0;
        foreach ($this->initFullSources() as $source) {
            $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source);
            $this->executeSqlFile($path);
            if (!str_starts_with($source, 'database/migrations/')) {
                $this->registerBaselineSource($source, $this->checksumFile($path, $source));
                continue;
            }

            $migration = basename($source);
            if (!isset($catalog[$migration])) {
                throw new RuntimeException("init_full.sql references an unknown migration: {$migration}");
            }
            if ($this->registerMigration(
                $migration,
                $catalog[$migration]['version'],
                $catalog[$migration]['checksum'],
                'executed'
            )) {
                $baselineRegistered++;
            }
        }

        $result = $this->migrate();
        return [
            'baseline_registered' => $baselineRegistered,
            'executed' => $result['executed'],
            'status' => $result['status'],
        ];
    }

    public function applicationTableCount(): int
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
                . "AND name NOT IN ('schema_versions', 'schema_migration_failures', 'schema_baseline_sources')"
            );
            return (int)$statement->fetchColumn();
        }

        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME NOT IN ('schema_versions', 'schema_migration_failures', 'schema_baseline_sources')"
        );
        return (int)$statement->fetchColumn();
    }

    /**
     * @return array{
     *   columns: array<string,array<string,array{type:string,nullable:bool,default:?string,auto_increment:bool}>>,
     *   indexes: array<string,array<string,array{unique:bool,columns:string}>>
     * }
     */
    private function databaseContract(): array
    {
        $columns = [];
        $indexes = [];
        if ($this->driver === 'sqlite') {
            $tables = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $name = (string)$table;
                $escaped = str_replace("'", "''", $name);
                $rows = $this->pdo->query("PRAGMA table_info('{$escaped}')")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $columns[$name][(string)$row['name']] = [
                        'type' => $this->normalizeColumnType((string)$row['type']),
                        'nullable' => (int)$row['notnull'] === 0 && (int)$row['pk'] === 0,
                        'default' => $this->normalizeDefaultValue($row['dflt_value'] ?? null),
                        'auto_increment' => false,
                    ];
                }
                $indexRows = $this->pdo->query("PRAGMA index_list('{$escaped}')")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($indexRows as $indexRow) {
                    $indexName = (string)$indexRow['name'];
                    if (str_starts_with($indexName, 'sqlite_autoindex_')) {
                        continue;
                    }
                    $indexEscaped = str_replace("'", "''", $indexName);
                    $indexColumns = $this->pdo->query("PRAGMA index_info('{$indexEscaped}')")
                        ->fetchAll(PDO::FETCH_ASSOC);
                    $indexes[$name][$indexName] = [
                        'unique' => (int)($indexRow['unique'] ?? 0) === 1,
                        'columns' => implode(',', array_map(
                            static fn(array $column): string => strtolower((string)$column['name']),
                            $indexColumns
                        )),
                    ];
                }
            }
            return ['columns' => $columns, 'indexes' => $indexes];
        }

        $rows = $this->pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $columns[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']] = [
                'type' => $this->normalizeColumnType((string)$row['COLUMN_TYPE']),
                'nullable' => strtoupper((string)$row['IS_NULLABLE']) === 'YES',
                'default' => $this->normalizeDefaultValue($row['COLUMN_DEFAULT'] ?? null),
                'auto_increment' => str_contains(strtolower((string)$row['EXTRA']), 'auto_increment'),
            ];
        }

        $rows = $this->pdo->query(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, COLLATION '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . "AND INDEX_NAME <> 'PRIMARY' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $table = (string)$row['TABLE_NAME'];
            $name = (string)$row['INDEX_NAME'];
            $column = strtolower((string)$row['COLUMN_NAME']);
            if ($row['SUB_PART'] !== null) {
                $column .= '(' . (int)$row['SUB_PART'] . ')';
            }
            if (strtoupper((string)($row['COLLATION'] ?? 'A')) === 'D') {
                $column .= ' desc';
            }
            $indexes[$table][$name]['unique'] = (int)$row['NON_UNIQUE'] === 0;
            $indexes[$table][$name]['parts'][] = $column;
        }
        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $name => $index) {
                $indexes[$table][$name] = [
                    'unique' => (bool)$index['unique'],
                    'columns' => implode(',', $index['parts']),
                ];
            }
        }
        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /** @return array{type:string,nullable:bool,default:?string,auto_increment:bool} */
    private function expectedColumnContract(string $definition): array
    {
        $body = preg_replace('/^(?:`[^`]+`|[A-Za-z_][A-Za-z0-9_]*)\s+/', '', trim($definition), 1);
        $body = is_string($body) ? $body : trim($definition);
        if (preg_match('/^([A-Za-z]+(?:\s*\([^)]*\))?(?:\s+UNSIGNED)?)/i', $body, $type) !== 1) {
            throw new RuntimeException("Unable to parse baseline column definition: {$definition}");
        }
        $default = null;
        $defaultSpecified = preg_match(
            '/\bDEFAULT\s+(NULL|CURRENT_TIMESTAMP(?:\(\d+\))?|[+-]?[0-9]+(?:\.[0-9]+)?|\'(?:\'\'|[^\'])*\'|"(?:""|[^"])*")/i',
            $body,
            $defaultMatch
        ) === 1;
        if ($defaultSpecified) {
            $default = $this->normalizeDefaultValue($defaultMatch[1]);
        }
        $nullable = preg_match('/\b(?:NOT\s+NULL|PRIMARY\s+KEY)\b/i', $body) !== 1;
        if (str_starts_with($this->normalizeColumnType($type[1]), 'timestamp')
            && preg_match('/\bNULL\b/i', preg_replace('/\bNOT\s+NULL\b/i', '', $body) ?? $body) !== 1
        ) {
            $nullable = false;
        }
        return [
            'type' => $this->normalizeColumnType($type[1]),
            'nullable' => $nullable,
            'default' => $default,
            'default_specified' => $defaultSpecified,
            'auto_increment' => preg_match('/\bAUTO_INCREMENT\b/i', $body) === 1,
        ];
    }

    /**
     * @param array{type:string,nullable:bool,default:?string,auto_increment:bool,default_specified?:bool} $expected
     * @param array{type:string,nullable:bool,default:?string,auto_increment:bool} $actual
     */
    private function columnContractsCompatible(array $expected, array $actual): bool
    {
        $typeMatches = $expected['type'] === $actual['type']
            || ($expected['type'] === 'json' && $actual['type'] === 'longtext');
        if (!$typeMatches
            || $expected['nullable'] !== $actual['nullable']
            || $expected['auto_increment'] !== $actual['auto_increment']
        ) {
            return false;
        }
        if (($expected['default_specified'] ?? false)
            && !$this->defaultValuesCompatible($expected['default'], $actual['default'], $expected['type'])
        ) {
            return false;
        }
        if (!($expected['default_specified'] ?? false) && $actual['default'] !== null) {
            return false;
        }
        return true;
    }

    private function defaultValuesCompatible(?string $expected, ?string $actual, string $type): bool
    {
        if ($expected === $actual) {
            return true;
        }
        if ($expected === null || $actual === null) {
            return false;
        }
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real)\b/', $type) !== 1
            || preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $expected) !== 1
            || preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $actual) !== 1
        ) {
            return false;
        }
        return $this->normalizeNumericLiteral($expected) === $this->normalizeNumericLiteral($actual);
    }

    private function normalizeNumericLiteral(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        $normalized = $fraction === '' ? $whole : $whole . '.' . $fraction;
        return $negative && $normalized !== '0' ? '-' . $normalized : $normalized;
    }

    /** @return array{unique:bool,columns:string} */
    private function expectedIndexContract(string $definition): array
    {
        if (preg_match('/\((.*)\)/s', $definition, $columns) !== 1) {
            throw new RuntimeException("Unable to parse baseline index definition: {$definition}");
        }
        return [
            'unique' => preg_match('/\bUNIQUE\b/i', $definition) === 1,
            'columns' => $this->normalizeIndexColumns($columns[1]),
        ];
    }

    private function normalizeColumnType(string $type): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $type) ?? $type));
        $normalized = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $normalized) ?? $normalized;
        $normalized = preg_replace('/^integer\b/', 'int', $normalized) ?? $normalized;
        return trim($normalized);
    }

    private function normalizeDefaultValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string)$value);
        if (strcasecmp($normalized, 'NULL') === 0) {
            return null;
        }
        if (strlen($normalized) >= 2
            && (($normalized[0] === "'" && str_ends_with($normalized, "'"))
                || ($normalized[0] === '"' && str_ends_with($normalized, '"')))
        ) {
            $normalized = substr($normalized, 1, -1);
        }
        $normalized = strtolower($normalized);
        return preg_replace('/^current_timestamp(?:\(\d*\))?$/', 'current_timestamp', $normalized) ?? $normalized;
    }

    private function normalizeIndexColumns(string $columns): string
    {
        $normalized = strtolower(str_replace('`', '', trim($columns)));
        $normalized = preg_replace('/\s*,\s*/', ',', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return trim($normalized);
    }

    /** @return list<string> */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $delimiter = ';';
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            $third = $index + 2 < $length ? $sql[$index + 2] : '';

            if ($quote === null && !$lineComment && !$blockComment && trim($buffer) === '') {
                $remaining = substr($sql, $index);
                if (preg_match('/\A[ \t]*DELIMITER[ \t]+([^\s]+)[ \t]*(?:\r\n|\r|\n|$)/i', $remaining, $matches) === 1) {
                    $delimiter = $matches[1];
                    if ($delimiter === '') {
                        throw new RuntimeException('SQL DELIMITER directive cannot be empty.');
                    }
                    $buffer = '';
                    $index += strlen($matches[0]) - 1;
                    continue;
                }
            }

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $buffer .= ' ';
                    $index++;
                }
                continue;
            }

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $index + 1 < $length) {
                    $buffer .= $sql[++$index];
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '-' && $next === '-' && ($third === '' || ctype_space($third))) {
                $lineComment = true;
                $index++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if (substr($sql, $index, strlen($delimiter)) === $delimiter) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                $index += strlen($delimiter) - 1;
                continue;
            }

            $buffer .= $char;
        }

        if ($quote !== null || $blockComment) {
            throw new RuntimeException('SQL file contains an unterminated quote or block comment.');
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function registryExists(): bool
    {
        return $this->tableExists(self::REGISTRY_TABLE);
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $statement->execute([$table]);
            return (int)$statement->fetchColumn() > 0;
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    private function registryColumnExists(string $column): bool
    {
        if (!$this->registryExists()) {
            return false;
        }
        if ($this->driver === 'sqlite') {
            $rows = $this->pdo->query("PRAGMA table_info('schema_versions')")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ((string)$row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([self::REGISTRY_TABLE, $column]);
        return (int)$statement->fetchColumn() > 0;
    }

    /** @return list<array{migration: string, version: string, checksum: ?string, execution_kind: string, executed_at: string}> */
    private function registeredRows(): array
    {
        $checksumExpression = $this->registryColumnExists('checksum') ? 'checksum' : 'NULL AS checksum';
        $executionKindExpression = $this->registryColumnExists('execution_kind')
            ? 'execution_kind'
            : "'executed' AS execution_kind";
        $statement = $this->pdo->query(
            'SELECT migration, version, ' . $checksumExpression . ', '
            . $executionKindExpression . ', executed_at FROM schema_versions ORDER BY id ASC'
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    private function registerMigration(
        string $migration,
        string $version,
        string $checksum,
        string $executionKind = 'executed'
    ): bool
    {
        if (!in_array($executionKind, ['executed', 'baseline_adopted'], true)) {
            throw new RuntimeException("Unsupported migration execution kind: {$executionKind}");
        }
        $checksumSupported = $this->registryColumnExists('checksum');
        $executionKindSupported = $this->registryColumnExists('execution_kind');
        $statement = $this->pdo->prepare(
            'SELECT version' . ($checksumSupported ? ', checksum' : '')
            . ($executionKindSupported ? ', execution_kind' : '')
            . ' FROM schema_versions WHERE migration = ? LIMIT 1'
        );
        $statement->execute([$migration]);
        $existing = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['version'], $version)) {
                throw new RuntimeException("Migration {$migration} is already registered with a different version.");
            }
            $storedChecksum = trim((string)($existing['checksum'] ?? ''));
            if ($checksumSupported && $storedChecksum !== '' && !hash_equals($storedChecksum, $checksum)) {
                throw new RuntimeException("Migration {$migration} is already registered with a different checksum.");
            }
            if ($checksumSupported && $storedChecksum === '') {
                $update = $this->pdo->prepare('UPDATE schema_versions SET checksum = ? WHERE migration = ?');
                $update->execute([$checksum, $migration]);
            }
            if ($executionKindSupported
                && trim((string)($existing['execution_kind'] ?? '')) !== $executionKind
            ) {
                throw new RuntimeException(
                    "Migration {$migration} is already registered with a different execution kind."
                );
            }
            return false;
        }

        if ($checksumSupported && $executionKindSupported) {
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_versions '
                . '(migration, version, checksum, execution_kind, executed_at) '
                . 'VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $insert->execute([$migration, $version, $checksum, $executionKind]);
        } elseif ($checksumSupported) {
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_versions (migration, version, checksum, executed_at) '
                . 'VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $insert->execute([$migration, $version, $checksum]);
        } else {
            $insert = $this->pdo->prepare(
                'INSERT INTO schema_versions (migration, version, executed_at) VALUES (?, ?, CURRENT_TIMESTAMP)'
            );
            $insert->execute([$migration, $version]);
        }
        return true;
    }

    /**
     * @return array{
     *   registry_exists: bool,
     *   required_count: int,
     *   registered_count: int,
     *   missing: list<string>,
     *   checksum_mismatches: list<string>,
     *   unknown: list<string>
     * }
     */
    private function baselineSourceStatus(): array
    {
        $catalog = [];
        foreach ($this->baselineSourceCatalog() as $source) {
            $catalog[$source['source']] = $source['checksum'];
        }
        $registryExists = $this->tableExists(self::BASELINE_REGISTRY_TABLE);
        $registered = [];
        if ($registryExists) {
            $rows = $this->pdo->query(
                'SELECT source, checksum FROM schema_baseline_sources ORDER BY id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $registered[(string)$row['source']] = trim((string)$row['checksum']);
            }
        }

        $missing = [];
        $mismatches = [];
        foreach ($catalog as $source => $checksum) {
            if (!isset($registered[$source])) {
                $missing[] = $source;
            } elseif (!hash_equals($checksum, $registered[$source])) {
                $mismatches[] = $source;
            }
        }
        $unknown = array_values(array_diff(array_keys($registered), array_keys($catalog)));
        sort($missing);
        sort($mismatches);
        sort($unknown);
        return [
            'registry_exists' => $registryExists,
            'required_count' => count($catalog),
            'registered_count' => count($registered),
            'missing' => $missing,
            'checksum_mismatches' => $mismatches,
            'unknown' => $unknown,
        ];
    }

    private function registerMissingBaselineSources(): void
    {
        $status = $this->baselineSourceStatus();
        if ($status['checksum_mismatches'] !== [] || $status['unknown'] !== []) {
            throw new RuntimeException(
                'Frozen baseline source registry drift detected: '
                . implode(', ', array_merge($status['checksum_mismatches'], $status['unknown']))
            );
        }
        if ($status['missing'] === []) {
            return;
        }
        $preflight = $this->baselineCompatibilityReport();
        if (!$preflight['ready']) {
            throw new RuntimeException(
                'Frozen baseline sources cannot be registered because the current schema is incompatible.'
            );
        }
        $missing = array_fill_keys($status['missing'], true);
        foreach ($this->baselineSourceCatalog() as $source) {
            if (isset($missing[$source['source']])) {
                $this->registerBaselineSource($source['source'], $source['checksum']);
            }
        }
    }

    private function registerBaselineSource(string $source, string $checksum): void
    {
        $statement = $this->pdo->prepare(
            'SELECT checksum FROM schema_baseline_sources WHERE source = ? LIMIT 1'
        );
        $statement->execute([$source]);
        $existing = $statement->fetchColumn();
        if ($existing !== false) {
            if (!hash_equals(trim((string)$existing), $checksum)) {
                throw new RuntimeException("Frozen baseline source checksum mismatch: {$source}");
            }
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO schema_baseline_sources (source, checksum, registered_at) '
            . 'VALUES (?, ?, CURRENT_TIMESTAMP)'
        );
        $insert->execute([$source, $checksum]);
    }

    /** @return list<string> */
    private function unresolvedMigrationFailures(): array
    {
        if (!$this->tableExists(self::FAILURE_TABLE)) {
            return [];
        }
        $rows = $this->pdo->query(
            'SELECT DISTINCT migration FROM schema_migration_failures '
            . 'WHERE resolved_at IS NULL ORDER BY migration ASC'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map('strval', is_array($rows) ? $rows : []));
    }

    private function backfillMissingChecksums(): void
    {
        if (!$this->registryColumnExists('checksum')) {
            return;
        }
        $catalog = [];
        foreach (self::migrationCatalog($this->root) as $migration) {
            $catalog[$migration['migration']] = $migration['checksum'];
        }
        $update = $this->pdo->prepare(
            "UPDATE schema_versions SET checksum = ? WHERE migration = ? AND (checksum IS NULL OR checksum = '')"
        );
        foreach ($this->registeredRows() as $row) {
            $migration = (string)$row['migration'];
            if (isset($catalog[$migration]) && trim((string)($row['checksum'] ?? '')) === '') {
                $update->execute([$catalog[$migration], $migration]);
            }
        }
    }

    /** @param array{migration:string,version:string,checksum:string,path:string} $migration */
    private function recordMigrationFailure(array $migration, Throwable $exception): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO schema_migration_failures '
                . '(migration, version, checksum, error_message, failed_at) '
                . 'VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                $migration['migration'],
                $migration['version'],
                $migration['checksum'],
                substr($exception->getMessage(), 0, 4000),
            ]);
        } catch (Throwable) {
            // Preserve the original migration error if diagnostics storage is unavailable.
        }
    }

    private function rollbackFailedMigrationTransaction(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                return;
            }
        } catch (Throwable) {
            // Fall through to a native rollback attempt.
        }

        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // MySQL DDL may already have committed; failure recording must still proceed.
        }
    }

    private function resolveRegisteredMigrationFailures(): void
    {
        if (!$this->tableExists(self::FAILURE_TABLE)) {
            return;
        }
        $registered = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['migration'],
            $this->registeredRows()
        )));
        if ($registered === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($registered), '?'));
        $statement = $this->pdo->prepare(
            'UPDATE schema_migration_failures SET resolved_at = CURRENT_TIMESTAMP '
            . "WHERE resolved_at IS NULL AND migration IN ({$placeholders})"
        );
        $statement->execute($registered);
    }

    /** @return list<string> */
    private function initFullSources(): array
    {
        $manifestPath = $this->root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init_full.sql';
        $sql = file_get_contents($manifestPath);
        if (!is_string($sql)) {
            throw new RuntimeException('Unable to read database/init_full.sql.');
        }
        if (preg_match_all('/^\s*SOURCE\s+(.+?);/im', $sql, $matches) < 1) {
            throw new RuntimeException('database/init_full.sql does not declare any SOURCE files.');
        }

        $sources = [];
        foreach ($matches[1] as $rawSource) {
            $source = trim((string)$rawSource, " \t\r\n'\"");
            $source = str_replace('\\', '/', $source);
            $source = ltrim($source, './');
            if ($source === '' || str_contains($source, '..')) {
                throw new RuntimeException("Unsafe SOURCE path in init_full.sql: {$rawSource}");
            }
            $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source);
            if (!is_file($path)) {
                throw new RuntimeException("Missing SQL source: {$source}");
            }
            $sources[] = $source;
        }

        return array_values(array_unique($sources));
    }

    /** @return list<array{source:string,checksum:string,path:string}> */
    private function baselineSourceCatalog(): array
    {
        $catalog = [];
        foreach ($this->initFullSources() as $source) {
            if (str_starts_with($source, 'database/migrations/')) {
                continue;
            }
            $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $source);
            $catalog[] = [
                'source' => $source,
                'checksum' => $this->checksumFile($path, $source),
                'path' => $path,
            ];
        }
        return $catalog;
    }

    private function checksumFile(string $path, string $label): string
    {
        $checksum = hash_file('sha256', $path);
        if (!is_string($checksum)) {
            throw new RuntimeException("Unable to checksum SQL source: {$label}");
        }
        return $checksum;
    }

    private function executeSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if (!is_string($sql)) {
            throw new RuntimeException("Unable to read SQL file: {$path}");
        }

        $statements = self::splitSqlStatements($sql);
        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $exception) {
                $number = $index + 1;
                throw new RuntimeException(
                    'Migration statement failed in ' . basename($path) . " at statement {$number}: " . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    }

    private function acquireLock(): bool
    {
        if ($this->driver !== 'mysql') {
            return false;
        }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$this->mysqlLockName()]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another database migration is already running.');
        }
        return true;
    }

    private function releaseLock(): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$this->mysqlLockName()]);
    }

    private function mysqlLockName(): string
    {
        $database = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        return self::MYSQL_LOCK_PREFIX . substr(hash('sha256', $database), 0, 32);
    }

    /** @return array<string, string> */
    private static function readEnvFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if (!is_string($content)) {
            return [];
        }

        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/D', $line, $match) !== 1) {
                continue;
            }
            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $values[$match[1]] = $value;
        }

        return $values;
    }
}
