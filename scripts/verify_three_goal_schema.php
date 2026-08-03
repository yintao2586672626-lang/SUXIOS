<?php
declare(strict_types=1);

use app\service\SchemaVersionService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$snapshotOnly = in_array('--snapshot', $arguments, true);
$expectedCountsBase64 = null;
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--expect-counts-base64=')) {
        $expectedCountsBase64 = substr($argument, strlen('--expect-counts-base64='));
    }
}
$targetMigrationNames = [
    '20260803_create_temporal_forecast_trials.sql',
    '20260803_allow_unquantified_operation_targets.sql',
    '20260803_enforce_single_active_temporal_forecast_trial.sql',
    '20260803_create_knowledge_promotion_workflow.sql',
];

/** @param list<string> $issues */
function requireContract(bool $condition, string $message, array &$issues): void
{
    if (!$condition) {
        $issues[] = $message;
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function readColumns(PDO $pdo, string $schema, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, EXTRA, GENERATION_EXPRESSION '
        . 'FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table '
        . 'ORDER BY ORDINAL_POSITION'
    );
    $statement->execute(['schema' => $schema, 'table' => $table]);

    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string)$row['COLUMN_NAME']] = $row;
    }
    return $columns;
}

/**
 * @return array<string, array{non_unique:int,columns:list<string>}>
 */
function readIndexes(PDO $pdo, string $schema, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX '
        . 'FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table '
        . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    $statement->execute(['schema' => $schema, 'table' => $table]);

    $indexes = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string)$row['INDEX_NAME'];
        if (!isset($indexes[$name])) {
            $indexes[$name] = [
                'non_unique' => (int)$row['NON_UNIQUE'],
                'columns' => [],
            ];
        }
        $indexes[$name]['columns'][] = (string)$row['COLUMN_NAME'];
    }
    return $indexes;
}

/**
 * @param array<string, array<string, mixed>> $columns
 * @param list<string> $required
 * @param list<string> $issues
 */
function requireColumns(string $table, array $columns, array $required, array &$issues): void
{
    foreach ($required as $column) {
        requireContract(isset($columns[$column]), "{$table}.{$column} is missing", $issues);
    }
}

/**
 * @param array<string, array{non_unique:int,columns:list<string>}> $indexes
 * @param list<string> $columns
 * @param list<string> $issues
 */
function requireIndex(
    string $table,
    array $indexes,
    string $name,
    array $columns,
    bool $unique,
    array &$issues
): void {
    $actual = $indexes[$name] ?? null;
    requireContract(is_array($actual), "{$table}.{$name} index is missing", $issues);
    if (!is_array($actual)) {
        return;
    }
    requireContract(
        $actual['columns'] === $columns,
        "{$table}.{$name} columns differ from the migration contract",
        $issues
    );
    requireContract(
        (int)$actual['non_unique'] === ($unique ? 0 : 1),
        "{$table}.{$name} uniqueness differs from the migration contract",
        $issues
    );
}

/**
 * @param list<string> $tables
 * @return array<string, int|null>
 */
function readRowCounts(PDO $pdo, string $schema, array $tables): array
{
    $existsStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
    );
    $counts = [];
    foreach ($tables as $table) {
        $existsStatement->execute(['schema' => $schema, 'table' => $table]);
        if ((int)$existsStatement->fetchColumn() !== 1) {
            $counts[$table] = null;
            continue;
        }
        $counts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $counts;
}

try {
    $config = SchemaVersionService::databaseConfigFromEnvironment($root);
    $status = SchemaVersionService::fromDatabaseConfig($config, $root)->status();
    $issues = [];

    $pdo = SchemaVersionService::createPdo($config);
    $schema = (string)($config['database'] ?? '');
    requireContract($schema !== '', 'database schema identity is missing', $issues);
    $registrationStatement = $pdo->prepare(
        'SELECT migration, version, checksum, execution_kind, executed_at '
        . 'FROM schema_versions WHERE migration IN (?,?,?,?) ORDER BY migration'
    );
    $registrationStatement->execute($targetMigrationNames);
    $targetMigrationRegistrations = $registrationStatement->fetchAll(PDO::FETCH_ASSOC);

    $businessRowCounts = readRowCounts($pdo, $schema, [
        'hotels',
        'hotel_operating_memories',
        'hotel_operating_sop_versions',
        'knowledge_units',
        'knowledge_chunks',
        'operation_execution_intents',
        'operation_action_tracks',
        'temporal_forecast_trials',
        'temporal_forecast_trial_points',
        'knowledge_candidates',
        'knowledge_candidate_revisions',
        'knowledge_promotion_events',
    ]);
    if ($snapshotOnly) {
        $snapshotIssues = [];
        requireContract($schema !== '', 'database schema identity is missing', $snapshotIssues);
        foreach ([
            'version_mismatches',
            'checksum_mismatches',
            'missing_checksums',
            'unknown_registrations',
            'baseline_checksum_mismatches',
            'baseline_unknown',
            'baseline_missing',
            'unresolved_failures',
        ] as $field) {
            requireContract(
                ($status[$field] ?? null) === [],
                "schema status {$field} must be empty before migration",
                $snapshotIssues
            );
        }
        foreach ([
            'registry_exists',
            'registry_checksum_supported',
            'registry_execution_kind_supported',
            'baseline_registry_exists',
        ] as $field) {
            requireContract(
                (bool)($status[$field] ?? false),
                "schema status {$field} must be true before migration",
                $snapshotIssues
            );
        }
        foreach ([
            'hotels',
            'hotel_operating_memories',
            'hotel_operating_sop_versions',
            'knowledge_units',
            'knowledge_chunks',
            'operation_execution_intents',
            'operation_action_tracks',
        ] as $existingTable) {
            requireContract(
                $businessRowCounts[$existingTable] !== null,
                "existing table {$existingTable} is missing",
                $snapshotIssues
            );
        }
        fwrite(STDOUT, json_encode([
            'status' => 'snapshot',
            'integrity_status' => $snapshotIssues === [] ? 'pass' : 'fail',
            'connection' => [
                'configured_host' => (string)($config['hostname'] ?? $config['host'] ?? ''),
                'configured_port' => (int)($config['hostport'] ?? $config['port'] ?? 0),
                'configured_database' => $schema,
                'connected_database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
                'charset' => (string)($config['charset'] ?? ''),
            ],
            'migration_ready' => (bool)($status['ready'] ?? false),
            'required_version' => $status['required_version'] ?? null,
            'registered_migrations' => [
                'applied' => (int)($status['applied_count'] ?? 0),
                'required' => (int)($status['required_count'] ?? 0),
                'pending' => $status['pending'] ?? null,
                'version_mismatches' => $status['version_mismatches'] ?? null,
                'checksum_mismatches' => $status['checksum_mismatches'] ?? null,
                'missing_checksums' => $status['missing_checksums'] ?? null,
                'unknown_registrations' => $status['unknown_registrations'] ?? null,
                'unresolved_failures' => $status['unresolved_failures'] ?? null,
            ],
            'target_migration_registrations' => $targetMigrationRegistrations,
            'business_row_counts' => $businessRowCounts,
            'issues' => $snapshotIssues,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit($snapshotIssues === [] ? 0 : 1);
    }

    requireContract((bool)($status['ready'] ?? false), 'migration catalog is not current', $issues);
    requireContract(($status['pending'] ?? null) === [], 'migration catalog still has pending files', $issues);
    foreach ($businessRowCounts as $table => $count) {
        requireContract($count !== null, "required table {$table} is missing", $issues);
    }

    $rowCountComparison = null;
    if ($expectedCountsBase64 !== null) {
        $decoded = base64_decode($expectedCountsBase64, true);
        $expectedCounts = is_string($decoded) ? json_decode($decoded, true) : null;
        requireContract(is_array($expectedCounts), 'expected row-count snapshot is invalid', $issues);
        if (is_array($expectedCounts)) {
            $rowCountComparison = [];
            foreach ($businessRowCounts as $table => $actualCount) {
                $hasExpected = array_key_exists($table, $expectedCounts);
                requireContract($hasExpected, "expected row count for {$table} is missing", $issues);
                if (!$hasExpected) {
                    continue;
                }
                $expectedCount = $expectedCounts[$table];
                $matches = $expectedCount === null
                    ? $actualCount === 0
                    : $actualCount === (int)$expectedCount;
                $rowCountComparison[$table] = [
                    'before' => $expectedCount,
                    'after' => $actualCount,
                    'matched' => $matches,
                ];
                requireContract($matches, "business row count changed for {$table}", $issues);
            }
        }
    }

    $tableContracts = [
        'temporal_forecast_trials' => [
            'columns' => [
                'id', 'tenant_id', 'system_hotel_id', 'trial_version', 'forecast_run_id',
                'metric_scope', 'platform', 'start_date', 'end_date', 'core_metrics_json',
                'source_identity_json', 'immutable_digest', 'maturity_status', 'status',
                'active_slot', 'operation_intent_id', 'final_review_json', 'approved_by',
                'approved_at', 'reviewed_by', 'reviewed_at',
            ],
            'indexes' => [
                ['uniq_temporal_forecast_trial_version', ['trial_version'], true],
                ['uniq_temporal_forecast_trial_run', ['tenant_id', 'system_hotel_id', 'forecast_run_id'], true],
                ['uniq_temporal_forecast_trial_active', ['tenant_id', 'system_hotel_id', 'active_slot'], true],
            ],
        ],
        'temporal_forecast_trial_points' => [
            'columns' => [
                'id', 'trial_id', 'tenant_id', 'system_hotel_id', 'forecast_snapshot_id',
                'metric_key', 'target_date', 'predicted_value', 'lower_bound', 'upper_bound',
                'sample_days', 'source_refs_json', 'point_digest', 'actual_status',
                'actual_value', 'absolute_error', 'within_range', 'actual_readback_json',
                'actual_reason_code', 'actual_readback_at',
            ],
            'indexes' => [
                ['uniq_temporal_forecast_trial_snapshot', ['trial_id', 'forecast_snapshot_id'], true],
                ['uniq_temporal_forecast_trial_metric_date', ['trial_id', 'metric_key', 'target_date'], true],
            ],
        ],
        'knowledge_candidates' => [
            'columns' => [
                'id', 'tenant_id', 'hotel_id', 'candidate_key', 'candidate_type',
                'source_record_type', 'source_record_id', 'current_revision_id',
                'current_revision_no', 'workflow_status', 'assigned_reviewer_id',
                'review_due_at', 'promoted_sop_version_id', 'promoted_knowledge_unit_id',
                'promoted_knowledge_chunk_id', 'row_version', 'created_by', 'deleted_at',
            ],
            'indexes' => [
                ['uniq_knowledge_candidate_key', ['tenant_id', 'hotel_id', 'candidate_key'], true],
                ['uniq_knowledge_candidate_source', ['tenant_id', 'hotel_id', 'source_record_type', 'source_record_id'], true],
            ],
        ],
        'knowledge_candidate_revisions' => [
            'columns' => [
                'id', 'candidate_id', 'revision_no', 'source_sop_candidate_version_id',
                'title', 'objective', 'steps_json', 'stop_conditions_json',
                'applicability_json', 'scope_json', 'evidence_refs_json', 'outcome_refs_json',
                'conflict_refs_json', 'source_digest', 'content_digest', 'created_by',
                'submitted_by', 'submitted_at',
            ],
            'indexes' => [
                ['uniq_knowledge_candidate_revision', ['candidate_id', 'revision_no'], true],
                ['uniq_knowledge_candidate_revision_digest', ['candidate_id', 'content_digest'], true],
            ],
        ],
        'knowledge_promotion_events' => [
            'columns' => [
                'id', 'tenant_id', 'hotel_id', 'candidate_id', 'revision_id',
                'event_type', 'from_status', 'to_status', 'actor_id', 'note',
                'payload_json', 'idempotency_key', 'created_at',
            ],
            'indexes' => [
                ['uniq_knowledge_promotion_event_request', ['idempotency_key'], true],
                ['idx_knowledge_promotion_event_timeline', ['tenant_id', 'hotel_id', 'candidate_id', 'id'], false],
            ],
        ],
    ];

    foreach ($tableContracts as $table => $contract) {
        $columns = readColumns($pdo, $schema, $table);
        requireContract($columns !== [], "{$table} table is missing", $issues);
        requireColumns($table, $columns, $contract['columns'], $issues);
        $indexes = readIndexes($pdo, $schema, $table);
        foreach ($contract['indexes'] as [$name, $indexColumns, $unique]) {
            requireIndex($table, $indexes, $name, $indexColumns, $unique, $issues);
        }
    }

    $unitColumns = readColumns($pdo, $schema, 'knowledge_units');
    requireColumns('knowledge_units', $unitColumns, ['stable_key', 'current_chunk_id'], $issues);
    requireIndex(
        'knowledge_units',
        readIndexes($pdo, $schema, 'knowledge_units'),
        'uniq_knowledge_units_stable_key',
        ['stable_key'],
        true,
        $issues
    );

    $chunkColumns = readColumns($pdo, $schema, 'knowledge_chunks');
    requireColumns('knowledge_chunks', $chunkColumns, [
        'promotion_candidate_id', 'operating_sop_version_id', 'version_no',
        'lifecycle_status', 'content_digest', 'superseded_by_chunk_id',
        'published_at', 'retired_at',
    ], $issues);
    requireIndex(
        'knowledge_chunks',
        readIndexes($pdo, $schema, 'knowledge_chunks'),
        'uniq_knowledge_chunk_operating_sop_version',
        ['operating_sop_version_id'],
        true,
        $issues
    );

    $sopColumns = readColumns($pdo, $schema, 'hotel_operating_sop_versions');
    requireColumns('hotel_operating_sop_versions', $sopColumns, [
        'retired_by', 'retired_at', 'replacement_version_id',
    ], $issues);
    requireIndex(
        'hotel_operating_sop_versions',
        readIndexes($pdo, $schema, 'hotel_operating_sop_versions'),
        'idx_operating_sop_replacement',
        ['replacement_version_id'],
        false,
        $issues
    );

    foreach ([
        ['operation_execution_intents', 'expected_delta'],
        ['operation_action_tracks', 'target_change_rate'],
    ] as [$table, $column]) {
        $columns = readColumns($pdo, $schema, $table);
        requireContract(isset($columns[$column]), "{$table}.{$column} is missing", $issues);
        if (isset($columns[$column])) {
            requireContract(
                strtoupper((string)$columns[$column]['IS_NULLABLE']) === 'YES',
                "{$table}.{$column} must preserve an unquantified target as NULL",
                $issues
            );
        }
    }

    $trialColumns = readColumns($pdo, $schema, 'temporal_forecast_trials');
    if (isset($trialColumns['active_slot'])) {
        requireContract(
            str_contains(strtoupper((string)$trialColumns['active_slot']['EXTRA']), 'STORED GENERATED'),
            'temporal_forecast_trials.active_slot must be a stored generated column',
            $issues
        );
        $expression = strtolower((string)$trialColumns['active_slot']['GENERATION_EXPRESSION']);
        foreach (['draft', 'pending_approval', 'running'] as $activeStatus) {
            requireContract(
                str_contains($expression, $activeStatus),
                "temporal_forecast_trials.active_slot omits {$activeStatus}",
                $issues
            );
        }
    }

    $result = [
        'status' => $issues === [] ? 'pass' : 'fail',
        'database' => $schema,
        'required_version' => $status['required_version'] ?? null,
        'registered_migrations' => [
            'applied' => (int)($status['applied_count'] ?? 0),
            'required' => (int)($status['required_count'] ?? 0),
            'pending' => $status['pending'] ?? null,
        ],
        'target_migration_registrations' => $targetMigrationRegistrations,
        'verified_tables' => array_keys($tableContracts),
        'business_row_counts' => $businessRowCounts,
        'row_count_comparison' => $rowCountComparison,
        'issues' => $issues,
    ];
    fwrite(STDOUT, json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
    exit($issues === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] three-goal schema verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
