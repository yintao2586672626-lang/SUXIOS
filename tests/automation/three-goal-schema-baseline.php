<?php

declare(strict_types=1);

/**
 * Shared-three-goal isolated schema baseline helper.
 *
 * This helper is deliberately fail-closed. The offline guard runs before any
 * project bootstrap, PDO construction, directory creation, or configuration
 * mutation. It is not a hotel-80 fixture and does not prove forecast, XLSX,
 * promotion, production, or field outcomes.
 */

const THREE_GOAL_SCHEMA_CONTRACT_VERSION = 'suxi.three_goal_schema.v1';
const THREE_GOAL_EXPECTED_DATABASE = 'hotelx_three_goals_e2e';
const THREE_GOAL_EXPECTED_CATALOG_COUNT = 137;
const THREE_GOAL_EXECUTION_LEASE_VALUE = 'approved';
const THREE_GOAL_FIELD_EVIDENCE_BOUNDARY = 'SCHEMA_UNIT_ONLY_NOT_INTEGRATION_NOT_FIELD_VALIDATED';

const THREE_GOAL_REQUIRED_MIGRATIONS = [
    '20260803_allow_unquantified_operation_targets.sql',
    '20260803_create_knowledge_promotion_workflow.sql',
    '20260803_create_temporal_forecast_trials.sql',
    '20260803_enforce_single_active_temporal_forecast_trial.sql',
];

const THREE_GOAL_LEGACY_DEMO_TABLES = [
    'tenants',
    'users',
    'hotels',
    'user_hotel_permissions',
    'online_daily_data',
    'monthly_tasks',
    'operation_logs',
];

const THREE_GOAL_PROTECTED_SYSTEM_TABLES = [
    'schema_versions',
    'roles',
    'system_configs',
    'field_configs',
    'report_configs',
    'knowledge_units',
    'knowledge_chunks',
    'hotel_operating_sop_versions',
];

const THREE_GOAL_ALLOWED_ACTIONS = [
    'guard',
    'init',
    'status',
    'snapshot',
    'assert-clean',
];

const THREE_GOAL_MAX_TABLE_ROWS = 100000;
const THREE_GOAL_MAX_TOTAL_ROWS = 250000;

function threeGoalRepoRoot(): string
{
    return dirname(__DIR__, 2);
}

function threeGoalEnv(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function threeGoalTruthy(?string $value): bool
{
    if ($value === null) {
        return false;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'approved'], true);
}

function threeGoalSafeError(string $message): string
{
    $secrets = [
        threeGoalEnv('DB_PASSWORD'),
        threeGoalEnv('DB_PASS'),
        threeGoalEnv('MYSQL_PWD'),
    ];

    foreach ($secrets as $secret) {
        if ($secret !== null && $secret !== '') {
            $message = str_replace($secret, '[REDACTED]', $message);
        }
    }

    return preg_replace('/[\r\n\t]+/', ' ', $message) ?? 'three-goal schema helper failed';
}

function threeGoalEmit(array $payload, int $exitCode = 0): never
{
    $payload['contract_version'] = THREE_GOAL_SCHEMA_CONTRACT_VERSION;
    $payload['database'] = THREE_GOAL_EXPECTED_DATABASE;
    $payload['catalog_required'] = THREE_GOAL_EXPECTED_CATALOG_COUNT;
    $payload['field_evidence_boundary'] = THREE_GOAL_FIELD_EVIDENCE_BOUNDARY;
    $payload['automatic_execution'] = false;
    $payload['ota_write'] = false;
    $payload['external_message'] = false;

    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    fwrite($exitCode === 0 ? STDOUT : STDERR, $encoded . PHP_EOL);
    exit($exitCode);
}

function threeGoalParseArgs(array $argv): array
{
    $args = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new RuntimeException('Every argument must use --name=value form.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || array_key_exists($name, $args)) {
            throw new RuntimeException('Argument names must be non-empty and unique.');
        }
        $args[$name] = $value;
    }

    $allowed = ['action', 'snapshot', 'ledger', 'owner-marker', 'run-id'];
    foreach (array_keys($args) as $name) {
        if (!in_array($name, $allowed, true)) {
            throw new RuntimeException('Unsupported argument: ' . $name);
        }
    }

    $action = $args['action'] ?? '';
    if (!in_array($action, THREE_GOAL_ALLOWED_ACTIONS, true)) {
        throw new RuntimeException('Action must be one of guard/init/status/snapshot/assert-clean.');
    }

    $args['action'] = $action;
    return $args;
}

/**
 * This is intentionally the first operational gate. It reads environment
 * strings only; it does not load .env, create a PDO, or touch the filesystem.
 */
function threeGoalOfflineSafetyGate(string $action): array
{
    $host = threeGoalEnv('DB_HOST');
    $dbName = threeGoalEnv('DB_NAME');
    $e2eName = threeGoalEnv('SUXI_E2E_DB_NAME');
    $override = threeGoalEnv('SUXI_E2E_DB_OVERRIDE');
    $isolatedRunner = threeGoalEnv('SUXI_E2E_ISOLATED_RUNNER');

    if ($host !== '127.0.0.1') {
        throw new RuntimeException('DB_HOST must be exactly 127.0.0.1; remote and ambiguous hosts are refused.');
    }
    if ($dbName !== THREE_GOAL_EXPECTED_DATABASE || $e2eName !== THREE_GOAL_EXPECTED_DATABASE) {
        throw new RuntimeException(
            'DB_NAME and SUXI_E2E_DB_NAME must both equal the dedicated hotelx_three_goals_e2e database.'
        );
    }
    if ($dbName === 'hotelx' || $dbName === 'hotelx_e2e') {
        throw new RuntimeException('Shared hotelx and legacy hotelx_e2e are permanently refused.');
    }
    if ($override !== '1' || $isolatedRunner !== '1') {
        throw new RuntimeException('Dedicated E2E override and isolated-runner markers must both equal 1.');
    }
    if (
        threeGoalTruthy(threeGoalEnv('SUXI_E2E_ALLOW_SHARED_DB'))
        || threeGoalTruthy(threeGoalEnv('SUXI_E2E_ALLOW_REMOTE_TEST_DB'))
    ) {
        throw new RuntimeException('Shared-database and remote-test overrides are refused.');
    }

    if ($action !== 'guard' && threeGoalEnv('SUXI_THREE_GOAL_SCHEMA_EXECUTION_LEASE') !== THREE_GOAL_EXECUTION_LEASE_VALUE) {
        throw new RuntimeException('A separate approved schema execution lease is required before any DB action.');
    }

    return [
        'host' => $host,
        'database' => $dbName,
        'override' => $override,
        'isolated_runner' => $isolatedRunner,
        'connection_attempted' => false,
        'configuration_loaded' => false,
    ];
}

function threeGoalCatalogManifest(): array
{
    $migrationDir = threeGoalRepoRoot() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    $files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql');
    if ($files === false) {
        throw new RuntimeException('Unable to enumerate the migration catalog.');
    }

    $names = array_map('basename', $files);
    sort($names, SORT_STRING);
    if (count($names) !== THREE_GOAL_EXPECTED_CATALOG_COUNT) {
        throw new RuntimeException(
            'Migration catalog count mismatch: expected '
            . THREE_GOAL_EXPECTED_CATALOG_COUNT
            . ', got '
            . count($names)
            . '.'
        );
    }

    foreach (THREE_GOAL_REQUIRED_MIGRATIONS as $required) {
        if (!in_array($required, $names, true)) {
            throw new RuntimeException('Required shared-137 migration is missing: ' . $required);
        }
    }

    $entries = [];
    foreach ($names as $name) {
        $path = $migrationDir . DIRECTORY_SEPARATOR . $name;
        $entries[] = [
            'version' => $name,
            'sha256' => strtoupper(hash_file('sha256', $path)),
        ];
    }

    return [
        'count' => count($entries),
        'entries' => $entries,
        'digest' => strtoupper(hash(
            'sha256',
            json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        )),
        'required_migrations' => THREE_GOAL_REQUIRED_MIGRATIONS,
    ];
}

function threeGoalValidateRunId(?string $runId): string
{
    if ($runId === null || !preg_match('/^three-goal-[a-z0-9-]{12,80}$/', $runId)) {
        throw new RuntimeException('run-id must be a random three-goal-* identifier.');
    }

    return $runId;
}

function threeGoalRuntimePath(string $path, string $label): string
{
    if ($path === '' || str_contains($path, "\0")) {
        throw new RuntimeException($label . ' path is missing or invalid.');
    }

    $normalizedInput = str_replace('\\', '/', $path);
    if (preg_match('#(^|/)\.\.(/|$)#', $normalizedInput)) {
        throw new RuntimeException($label . ' path traversal is refused.');
    }

    $isAbsolute = preg_match('#^[A-Za-z]:/#', $normalizedInput) === 1 || str_starts_with($normalizedInput, '/');
    $absolute = $isAbsolute
        ? $normalizedInput
        : str_replace('\\', '/', threeGoalRepoRoot()) . '/' . ltrim($normalizedInput, '/');
    $absolute = preg_replace('#/+#', '/', $absolute) ?? $absolute;

    $allowedRoot = rtrim(str_replace('\\', '/', threeGoalRepoRoot()), '/')
        . '/runtime/three-goal-e2e/';
    if (stripos($absolute, $allowedRoot) !== 0) {
        throw new RuntimeException($label . ' must stay under runtime/three-goal-e2e/.');
    }

    return str_replace('/', DIRECTORY_SEPARATOR, $absolute);
}

function threeGoalEnsureRuntimeDirectory(string $path): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the dedicated runtime evidence directory.');
    }
}

function threeGoalServerPdo(): PDO
{
    $port = threeGoalEnv('DB_PORT') ?? '3306';
    if (!preg_match('/^[0-9]{2,5}$/', $port)) {
        throw new RuntimeException('DB_PORT must be numeric.');
    }

    $user = threeGoalEnv('DB_USER');
    if ($user === null) {
        throw new RuntimeException('DB_USER is required.');
    }

    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = getenv('DB_PASS');
    }
    if ($password === false) {
        $password = '';
    }

    return new PDO(
        'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
        $user,
        (string) $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function threeGoalDatabasePdo(): PDO
{
    $port = threeGoalEnv('DB_PORT') ?? '3306';
    $user = threeGoalEnv('DB_USER');
    if ($user === null) {
        throw new RuntimeException('DB_USER is required.');
    }

    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = getenv('DB_PASS');
    }
    if ($password === false) {
        $password = '';
    }

    return new PDO(
        'mysql:host=127.0.0.1;port=' . $port . ';dbname='
        . THREE_GOAL_EXPECTED_DATABASE
        . ';charset=utf8mb4',
        $user,
        (string) $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function threeGoalDatabaseExists(PDO $server): bool
{
    $statement = $server->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :schema_name'
    );
    $statement->execute(['schema_name' => THREE_GOAL_EXPECTED_DATABASE]);
    return (int) $statement->fetchColumn() === 1;
}

function threeGoalLoadProjectServices(): void
{
    $root = threeGoalRepoRoot();
    $autoload = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    $files = [
        $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service'
            . DIRECTORY_SEPARATOR . 'SchemaVersionService.php',
        $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service'
            . DIRECTORY_SEPARATOR . 'FreshDatabaseInitializerService.php',
    ];
    foreach ($files as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('Required project service file is missing.');
        }
        require_once $file;
    }

    if (
        !class_exists(\app\service\FreshDatabaseInitializerService::class)
        || !class_exists(\app\service\SchemaVersionService::class)
    ) {
        throw new RuntimeException('Expected project schema services could not be resolved.');
    }
}

/** @return array<string,mixed> */
function threeGoalFreshDatabaseConfig(): array
{
    $port = threeGoalEnv('DB_PORT') ?? '3306';
    if (!preg_match('/^[0-9]{2,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('DB_PORT must be a valid numeric port.');
    }

    $user = threeGoalEnv('DB_USER');
    if ($user === null) {
        throw new RuntimeException('DB_USER is required.');
    }

    $password = getenv('DB_PASSWORD');
    if ($password === false) {
        $password = getenv('DB_PASS');
    }

    return [
        'type' => 'mysql',
        'hostname' => '127.0.0.1',
        'hostport' => (int)$port,
        'database' => THREE_GOAL_EXPECTED_DATABASE,
        'username' => $user,
        'password' => $password === false ? '' : (string)$password,
        'charset' => 'utf8mb4',
    ];
}

function threeGoalRunFreshInitializer(): array
{
    threeGoalLoadProjectServices();
    $config = threeGoalFreshDatabaseConfig();
    $result = \app\service\FreshDatabaseInitializerService::initialize(
        $config,
        threeGoalRepoRoot()
    );

    return [
        'service' => 'FreshDatabaseInitializerService',
        'method' => 'initialize',
        'result_type' => get_debug_type($result),
        'status_ready' => is_array($result['status'] ?? null)
            && ($result['status']['ready'] ?? false) === true,
    ];
}

/** @return array{database_absent:bool,owner_marker_absent:bool} */
function threeGoalCleanupFailedFreshInit(PDO $server, string $markerPath): array
{
    if (THREE_GOAL_EXPECTED_DATABASE !== 'hotelx_three_goals_e2e') {
        throw new RuntimeException('Exact failed-init cleanup is pinned to the dedicated database literal.');
    }

    $server->exec('DROP DATABASE IF EXISTS `hotelx_three_goals_e2e`');
    if (threeGoalDatabaseExists($server)) {
        throw new RuntimeException('Exact failed-init cleanup did not remove the dedicated database.');
    }

    if (is_link($markerPath)) {
        throw new RuntimeException('Owner marker cleanup refuses symbolic links.');
    }
    if (is_file($markerPath) && !unlink($markerPath)) {
        throw new RuntimeException('Unable to remove the failed-init owner marker file.');
    }
    if (is_file($markerPath) || is_link($markerPath)) {
        throw new RuntimeException('Failed-init owner marker still exists after exact cleanup.');
    }

    return [
        'database_absent' => true,
        'owner_marker_absent' => true,
    ];
}

function threeGoalVersionColumn(array $columns, array $candidates, string $label): string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    throw new RuntimeException('schema_versions lacks a recognized ' . $label . ' column.');
}

function threeGoalSchemaStatus(PDO $pdo, array $catalog): array
{
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'schema_versions'");
    if ($tableCheck->fetchColumn() === false) {
        throw new RuntimeException('schema_versions table is missing.');
    }

    $rows = $pdo->query('SELECT * FROM schema_versions')->fetchAll();
    $columns = $rows === []
        ? array_map(
            static fn (array $row): string => (string) $row['Field'],
            $pdo->query('SHOW COLUMNS FROM schema_versions')->fetchAll()
        )
        : array_keys($rows[0]);

    $versionColumn = threeGoalVersionColumn(
        $columns,
        ['migration', 'filename', 'migration_name', 'name', 'version'],
        'version'
    );
    $checksumColumn = threeGoalVersionColumn(
        $columns,
        ['checksum', 'sha256', 'file_hash'],
        'checksum'
    );
    $statusColumn = null;
    foreach (['status', 'state', 'execution_kind'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $statusColumn = $candidate;
            break;
        }
    }

    $expected = [];
    foreach ($catalog['entries'] as $entry) {
        $expected[$entry['version']] = $entry['sha256'];
    }

    $seen = [];
    $duplicates = [];
    $unknown = [];
    $mismatch = [];
    $unresolved = [];
    foreach ($rows as $row) {
        $version = basename((string) ($row[$versionColumn] ?? ''));
        if ($version === '') {
            $unresolved[] = '[blank-version]';
            continue;
        }
        if (!str_ends_with(strtolower($version), '.sql') && array_key_exists($version . '.sql', $expected)) {
            $version .= '.sql';
        }
        if (isset($seen[$version])) {
            $duplicates[] = $version;
        }
        $seen[$version] = true;

        if (!array_key_exists($version, $expected)) {
            $unknown[] = $version;
            continue;
        }

        $storedChecksum = strtoupper(trim((string) ($row[$checksumColumn] ?? '')));
        if ($storedChecksum === '' || !hash_equals($expected[$version], $storedChecksum)) {
            $mismatch[] = $version;
        }

        if ($statusColumn !== null) {
            $state = strtolower(trim((string) ($row[$statusColumn] ?? '')));
            if (!in_array($state, ['executed', 'baseline_adopted', 'applied', 'success'], true)) {
                $unresolved[] = $version . ':' . $state;
            }
        }
    }

    $pending = array_values(array_diff(array_keys($expected), array_keys($seen)));
    sort($pending, SORT_STRING);
    sort($unknown, SORT_STRING);
    sort($mismatch, SORT_STRING);
    sort($duplicates, SORT_STRING);
    sort($unresolved, SORT_STRING);

    $current = count($expected) - count($pending);
    $ready = $current === THREE_GOAL_EXPECTED_CATALOG_COUNT
        && $pending === []
        && $unknown === []
        && $mismatch === []
        && $duplicates === []
        && $unresolved === [];

    return [
        'ready' => $ready,
        'current' => $current,
        'required' => THREE_GOAL_EXPECTED_CATALOG_COUNT,
        'pending' => $pending,
        'unknown' => $unknown,
        'checksum_mismatch' => $mismatch,
        'duplicates' => $duplicates,
        'unresolved_failure' => $unresolved,
        'catalog_digest' => $catalog['digest'],
    ];
}

function threeGoalQuoteIdentifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Unsafe SQL identifier refused.');
    }
    $quote = chr(96);
    return $quote . $identifier . $quote;
}

function threeGoalBaseTables(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables '
        . 'WHERE table_schema = :schema_name AND table_type = :table_type ORDER BY table_name'
    );
    $statement->execute([
        'schema_name' => THREE_GOAL_EXPECTED_DATABASE,
        'table_type' => 'BASE TABLE',
    ]);
    return array_map(
        static fn (array $row): string => (string) $row['table_name'],
        $statement->fetchAll()
    );
}

function threeGoalCanonicalRow(array $row): string
{
    ksort($row, SORT_STRING);
    foreach ($row as $key => $value) {
        if (is_resource($value)) {
            throw new RuntimeException('Resource-valued baseline columns are unsupported.');
        }
        if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
            $row[$key] = ['binary_sha256' => strtoupper(hash('sha256', $value))];
        } elseif ($value !== null && !is_array($value)) {
            $row[$key] = (string) $value;
        }
    }

    return json_encode(
        $row,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
}

function threeGoalTableDigest(PDO $pdo, string $table): array
{
    $quoted = threeGoalQuoteIdentifier($table);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
    if ($count > THREE_GOAL_MAX_TABLE_ROWS) {
        throw new RuntimeException('Baseline table exceeds the bounded snapshot row limit: ' . $table);
    }

    $rows = [];
    $statement = $pdo->query('SELECT * FROM ' . $quoted);
    while (($row = $statement->fetch()) !== false) {
        $rows[] = threeGoalCanonicalRow($row);
    }
    sort($rows, SORT_STRING);

    return [
        'count' => $count,
        'digest' => strtoupper(hash('sha256', implode("\n", $rows))),
        'classification' => in_array($table, THREE_GOAL_LEGACY_DEMO_TABLES, true)
            ? 'legacy_demo_non_three_goal_fact'
            : 'protected_system_or_empty_baseline',
    ];
}

function threeGoalSnapshotData(PDO $pdo, array $catalog): array
{
    $status = threeGoalSchemaStatus($pdo, $catalog);
    if (!$status['ready']) {
        throw new RuntimeException('Snapshot requires an exact 137/137 schema with no registry failures.');
    }

    $tables = [];
    $totalRows = 0;
    foreach (threeGoalBaseTables($pdo) as $table) {
        $tables[$table] = threeGoalTableDigest($pdo, $table);
        $totalRows += $tables[$table]['count'];
        if ($totalRows > THREE_GOAL_MAX_TOTAL_ROWS) {
            throw new RuntimeException('Baseline exceeds the bounded total-row snapshot limit.');
        }
    }

    $demoCounts = [];
    foreach (THREE_GOAL_LEGACY_DEMO_TABLES as $table) {
        $demoCounts[$table] = $tables[$table]['count'] ?? null;
    }

    return [
        'contract_version' => THREE_GOAL_SCHEMA_CONTRACT_VERSION,
        'database' => THREE_GOAL_EXPECTED_DATABASE,
        'catalog_digest' => $catalog['digest'],
        'schema_status' => $status,
        'tables' => $tables,
        'protected_system_tables' => THREE_GOAL_PROTECTED_SYSTEM_TABLES,
        'legacy_demo_tables' => THREE_GOAL_LEGACY_DEMO_TABLES,
        'legacy_demo_counts' => $demoCounts,
        'legacy_demo_is_three_goal_fact' => false,
        'after_zero_definition' => 'run_owned_exact_primary_keys_and_foreign_references_only',
        'field_evidence_boundary' => THREE_GOAL_FIELD_EVIDENCE_BOUNDARY,
    ];
}

function threeGoalWriteJson(string $path, array $payload): void
{
    threeGoalEnsureRuntimeDirectory($path);
    $encoded = json_encode(
        $payload,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write dedicated runtime evidence.');
    }
}

function threeGoalReadJson(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException($label . ' file is missing.');
    }
    $decoded = json_decode(
        (string) file_get_contents($path),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)) {
        throw new RuntimeException($label . ' must contain a JSON object.');
    }
    return $decoded;
}

function threeGoalAssertIdentifierExists(PDO $pdo, string $table, string $column): void
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns '
        . 'WHERE table_schema = :schema_name AND table_name = :table_name AND column_name = :column_name'
    );
    $statement->execute([
        'schema_name' => THREE_GOAL_EXPECTED_DATABASE,
        'table_name' => $table,
        'column_name' => $column,
    ]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('Ledger references an unknown table or column.');
    }
}

function threeGoalCountExactValues(PDO $pdo, string $table, string $column, array $values): int
{
    if ($values === []) {
        return 0;
    }
    $unique = array_values(array_unique(array_map(
        static fn ($value): string => (string) $value,
        $values
    )));
    if (count($unique) !== count($values) || count($unique) > 500) {
        throw new RuntimeException('Ledger values must be unique and bounded to 500 exact identifiers.');
    }

    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM '
        . threeGoalQuoteIdentifier($table)
        . ' WHERE '
        . threeGoalQuoteIdentifier($column)
        . ' IN ('
        . $placeholders
        . ')'
    );
    $statement->execute($unique);
    return (int) $statement->fetchColumn();
}

function threeGoalAssertExactLedgerZero(PDO $pdo, array $ledger): array
{
    if (($ledger['contract_version'] ?? null) !== THREE_GOAL_SCHEMA_CONTRACT_VERSION) {
        throw new RuntimeException('Ledger contract version mismatch.');
    }
    if (($ledger['database'] ?? null) !== THREE_GOAL_EXPECTED_DATABASE) {
        throw new RuntimeException('Ledger database identity mismatch.');
    }
    threeGoalValidateRunId(isset($ledger['run_id']) ? (string) $ledger['run_id'] : null);

    $owned = $ledger['run_owned_exact_ids'] ?? null;
    $references = $ledger['foreign_reference_exact_ids'] ?? null;
    if (!is_array($owned) || !is_array($references)) {
        throw new RuntimeException('Ledger requires exact owned-ID and foreign-reference arrays.');
    }
    if ($owned === [] && ($ledger['purpose'] ?? null) !== 'schema_only_no_business_rows') {
        throw new RuntimeException('A business run may not use an empty exact-ID ledger.');
    }

    $checks = [];
    foreach (array_merge($owned, $references) as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException('Every ledger entry must be an object.');
        }
        $table = (string) ($entry['table'] ?? '');
        $column = (string) ($entry['primary_key'] ?? $entry['foreign_key'] ?? '');
        $ids = $entry['ids'] ?? null;
        if (!is_array($ids) || $table === '' || $column === '') {
            throw new RuntimeException('Ledger entries require table, exact key column, and IDs.');
        }
        threeGoalAssertIdentifierExists($pdo, $table, $column);
        $remaining = threeGoalCountExactValues($pdo, $table, $column, $ids);
        if ($remaining !== 0) {
            throw new RuntimeException('Run-owned exact IDs remain after cleanup.');
        }
        $checks[] = [
            'table' => $table,
            'column' => $column,
            'id_count' => count($ids),
            'remaining' => 0,
        ];
    }

    return $checks;
}

function threeGoalAssertSnapshotEqual(array $expected, array $actual): void
{
    foreach (['contract_version', 'database', 'catalog_digest'] as $field) {
        if (($expected[$field] ?? null) !== ($actual[$field] ?? null)) {
            throw new RuntimeException('Baseline snapshot identity drifted: ' . $field);
        }
    }

    $expectedTables = $expected['tables'] ?? null;
    $actualTables = $actual['tables'] ?? null;
    if (!is_array($expectedTables) || !is_array($actualTables)) {
        throw new RuntimeException('Baseline snapshot tables are missing.');
    }
    if (array_keys($expectedTables) !== array_keys($actualTables)) {
        throw new RuntimeException('Baseline table set drifted.');
    }
    foreach ($expectedTables as $table => $expectedState) {
        $actualState = $actualTables[$table] ?? null;
        if (
            !is_array($actualState)
            || ($expectedState['count'] ?? null) !== ($actualState['count'] ?? null)
            || ($expectedState['digest'] ?? null) !== ($actualState['digest'] ?? null)
        ) {
            throw new RuntimeException('Protected/demo baseline row state drifted for table: ' . $table);
        }
    }
}

function threeGoalOwnerMarker(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    return threeGoalReadJson($path, 'owner marker');
}

function threeGoalAssertOwnerMarker(array $marker, array $catalog): void
{
    if (
        ($marker['contract_version'] ?? null) !== THREE_GOAL_SCHEMA_CONTRACT_VERSION
        || ($marker['database'] ?? null) !== THREE_GOAL_EXPECTED_DATABASE
        || ($marker['catalog_digest'] ?? null) !== $catalog['digest']
        || ($marker['owner'] ?? null) !== 'shared-three-goal-isolated-runner'
    ) {
        throw new RuntimeException('Existing database is not provably owned by this exact runner/catalog.');
    }
}

function threeGoalHandleInit(array $args, array $catalog): array
{
    $runId = threeGoalValidateRunId($args['run-id'] ?? null);
    $markerPath = threeGoalRuntimePath((string) ($args['owner-marker'] ?? ''), 'owner-marker');
    $server = threeGoalServerPdo();
    $exists = threeGoalDatabaseExists($server);
    $marker = threeGoalOwnerMarker($markerPath);
    $initiallyUnownedAndAbsent = !$exists && $marker === null;

    if ($exists) {
        if ($marker === null) {
            throw new RuntimeException('Existing dedicated database lacks an exact runner owner marker.');
        }
        threeGoalAssertOwnerMarker($marker, $catalog);
        $pdo = threeGoalDatabasePdo();
        $status = threeGoalSchemaStatus($pdo, $catalog);
        if (!$status['ready']) {
            throw new RuntimeException('Owned dedicated database exists but is not exact 137/137; no in-place migration is allowed.');
        }
        return [
            'action' => 'init',
            'created' => false,
            'reused_owned_baseline' => true,
            'status' => $status,
            'run_id' => $runId,
        ];
    }

    if ($marker !== null) {
        throw new RuntimeException('Owner marker exists while the dedicated database is absent.');
    }

    $initializerReturned = false;
    try {
        $initializer = threeGoalRunFreshInitializer();
        $initializerReturned = true;
        if (($initializer['status_ready'] ?? false) !== true) {
            throw new RuntimeException('Fresh initializer returned without an exact ready schema status.');
        }

        $pdo = threeGoalDatabasePdo();
        $status = threeGoalSchemaStatus($pdo, $catalog);
        if (!$status['ready']) {
            throw new RuntimeException('Fresh initialization did not produce exact 137/137; automatic retry is refused.');
        }

        $marker = [
            'contract_version' => THREE_GOAL_SCHEMA_CONTRACT_VERSION,
            'database' => THREE_GOAL_EXPECTED_DATABASE,
            'catalog_digest' => $catalog['digest'],
            'owner' => 'shared-three-goal-isolated-runner',
            'created_by_run_id' => $runId,
            'created_at' => gmdate('c'),
            'restore_requires_new_exclusive_db_lease' => true,
            'legacy_demo_is_three_goal_fact' => false,
            'field_evidence_boundary' => THREE_GOAL_FIELD_EVIDENCE_BOUNDARY,
        ];
        threeGoalWriteJson($markerPath, $marker);
    } catch (Throwable $error) {
        if ($initiallyUnownedAndAbsent && $initializerReturned) {
            try {
                $cleanup = threeGoalCleanupFailedFreshInit($server, $markerPath);
            } catch (RuntimeException $cleanupError) {
                throw new RuntimeException(
                    $error->getMessage()
                    . ' Exact post-initializer cleanup failed: '
                    . $cleanupError->getMessage(),
                    0,
                    $error
                );
            }
            throw new RuntimeException(
                $error->getMessage()
                . ' Exact post-initializer cleanup completed: database_absent='
                . ($cleanup['database_absent'] ? 'true' : 'false')
                . ', owner_marker_absent='
                . ($cleanup['owner_marker_absent'] ? 'true' : 'false')
                . '.',
                0,
                $error
            );
        }
        throw $error;
    }

    return [
        'action' => 'init',
        'created' => true,
        'reused_owned_baseline' => false,
        'initializer' => $initializer,
        'status' => $status,
        'run_id' => $runId,
    ];
}

function threeGoalMain(array $argv): never
{
    $args = threeGoalParseArgs($argv);
    $action = $args['action'];
    $safety = threeGoalOfflineSafetyGate($action);
    $catalog = threeGoalCatalogManifest();

    if ($action === 'guard') {
        threeGoalEmit([
            'ok' => true,
            'action' => 'guard',
            'safety' => $safety,
            'catalog' => [
                'count' => $catalog['count'],
                'digest' => $catalog['digest'],
                'required_migrations' => $catalog['required_migrations'],
            ],
            'legacy_demo_is_three_goal_fact' => false,
            'database_action_attempted' => false,
        ]);
    }

    if ($action === 'init') {
        threeGoalEmit(['ok' => true] + threeGoalHandleInit($args, $catalog));
    }

    $pdo = threeGoalDatabasePdo();
    $status = threeGoalSchemaStatus($pdo, $catalog);
    if ($action === 'status') {
        threeGoalEmit([
            'ok' => $status['ready'],
            'action' => 'status',
            'status' => $status,
            'database_action_attempted' => true,
        ], $status['ready'] ? 0 : 1);
    }

    if ($action === 'snapshot') {
        $runId = threeGoalValidateRunId($args['run-id'] ?? null);
        $snapshotPath = threeGoalRuntimePath((string) ($args['snapshot'] ?? ''), 'snapshot');
        $snapshot = threeGoalSnapshotData($pdo, $catalog);
        $snapshot['run_id'] = $runId;
        $snapshot['captured_at'] = gmdate('c');
        threeGoalWriteJson($snapshotPath, $snapshot);
        threeGoalEmit([
            'ok' => true,
            'action' => 'snapshot',
            'run_id' => $runId,
            'snapshot_sha256' => strtoupper(hash_file('sha256', $snapshotPath)),
            'table_count' => count($snapshot['tables']),
            'total_rows' => array_sum(array_column($snapshot['tables'], 'count')),
            'legacy_demo_counts' => $snapshot['legacy_demo_counts'],
            'database_action_attempted' => true,
        ]);
    }

    if ($action === 'assert-clean') {
        $runId = threeGoalValidateRunId($args['run-id'] ?? null);
        $snapshotPath = threeGoalRuntimePath((string) ($args['snapshot'] ?? ''), 'snapshot');
        $ledgerPath = threeGoalRuntimePath((string) ($args['ledger'] ?? ''), 'ledger');
        $expected = threeGoalReadJson($snapshotPath, 'snapshot');
        $ledger = threeGoalReadJson($ledgerPath, 'ledger');
        if (($expected['run_id'] ?? null) !== $runId || ($ledger['run_id'] ?? null) !== $runId) {
            throw new RuntimeException('Snapshot, ledger, and requested run-id must match exactly.');
        }
        $ledgerChecks = threeGoalAssertExactLedgerZero($pdo, $ledger);
        $actual = threeGoalSnapshotData($pdo, $catalog);
        threeGoalAssertSnapshotEqual($expected, $actual);
        threeGoalEmit([
            'ok' => true,
            'action' => 'assert-clean',
            'run_id' => $runId,
            'status' => $status,
            'ledger_checks' => $ledgerChecks,
            'protected_and_demo_baseline_unchanged' => true,
            'after_zero' => true,
            'database_action_attempted' => true,
        ]);
    }

    throw new RuntimeException('Unreachable action state.');
}

try {
    threeGoalMain($argv);
} catch (Throwable $error) {
    threeGoalEmit([
        'ok' => false,
        'error' => threeGoalSafeError($error->getMessage()),
        'error_type' => get_class($error),
        'fail_closed' => true,
        'automatic_retry' => false,
        'database_action_attempted' => false,
    ], 1);
}
