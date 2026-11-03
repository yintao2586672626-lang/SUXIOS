<?php
declare(strict_types=1);

use app\service\DatabaseMigrationExecutionGuard;
use app\service\SchemaVersionService;
use app\service\SchemaVersionStatusCache;
use app\service\SqlSchemaResourceInspector;

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
(new think\App($root))->initialize();
date_default_timezone_set('Asia/Shanghai');
$reviewed = [
    '20261002_create_ai_report_presentation_reviews.sql' => '32dadf8990bf355050f601972e36defa0fb9da625b64f388f923b869a57edb23',
    '20261002_create_operating_evidence_snapshots.sql' => '87bb58c2d139b12f9abd9cdf195c8b03c4d843d193b5611a26593b6992b200f3',
    '20261002_create_room_type_on_books_snapshots.sql' => '9dc6db2d06d14655b381265abc20fb077c11ae4b4d0d6caa92ccfa79834ba779',
];
$assertSources = static function () use ($root, $reviewed): void {
    foreach ($reviewed as $name => $hash) {
        if (!hash_equals($hash, (string)hash_file('sha256', $root . '/database/migrations/' . $name))) throw new RuntimeException('reviewed_migration_source_changed');
    }
};
$writeEvidence = static function (array $evidence) use ($root): void {
    $path = $root . '/output/validation/five-enhancements-20261002/authorized-migration.json';
    file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
};
try {
    $assertSources();
    $defaultConnection = (string)config('database.default', 'mysql');
    if ((string)config('database.connections.' . $defaultConnection . '.prefix', '') !== '') throw new RuntimeException('prefixed_database_requires_separate_migration_review');
    $config = SchemaVersionService::databaseConfigFromEnvironment($root);
    if (!in_array($config['hostname'] ?? '', ['127.0.0.1', 'localhost'], true) || (int)($config['hostport'] ?? 0) !== 3306 || ($config['database'] ?? '') !== 'hotelx') throw new RuntimeException('local_database_identity_mismatch');
    $pdo = SchemaVersionService::createPdo($config);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'hotelx') throw new RuntimeException('selected_database_identity_mismatch');
    $service = new SchemaVersionService($pdo, $root);
    $before = $service->status();
    foreach (['version_mismatches','checksum_mismatches','missing_checksums','unknown_registrations','baseline_checksum_mismatches','baseline_unknown','unresolved_failures'] as $key) {
        if (($before[$key] ?? []) !== []) throw new RuntimeException('migration_history_requires_review');
    }
    $evidence = ['checked_at' => date(DATE_ATOM), 'mode' => 'read_only_preflight', 'database' => 'hotelx', 'reviewed_sha256' => $reviewed,
        'selected' => array_keys($reviewed), 'pending_before' => $before['pending'], 'schema_ready_before' => $before['ready']];
    if (!in_array('--execute', $argv, true)) {
        echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    DatabaseMigrationExecutionGuard::assertAllowed($root, 'hotelx', 'mysql');
    $metadataArgument = array_values(array_filter($argv, static fn(string $arg): bool => str_starts_with($arg, '--backup-metadata=')));
    if (count($metadataArgument) !== 1) throw new RuntimeException('verified_backup_metadata_required');
    $backupDirectory = realpath('C:/Users/Administrator/Documents/Codex/2026-10-02/suxios-local-db-backup');
    $metadataPath = realpath(substr($metadataArgument[0], strlen('--backup-metadata=')));
    if (!$backupDirectory || !$metadataPath || dirname($metadataPath) !== $backupDirectory || !preg_match('/^authorized-five-enhancements-[0-9-]+\.json$/D', basename($metadataPath))) throw new RuntimeException('backup_metadata_path_invalid');
    $metadata = json_decode(ltrim((string)file_get_contents($metadataPath), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
    $backupPath = realpath((string)($metadata['backup']['backup_path'] ?? ''));
    if (($metadata['status'] ?? '') !== 'verified' || ($metadata['backup']['database'] ?? '') !== 'hotelx'
        || ($metadata['verification']['status'] ?? '') !== 'all_encrypted_chunks_authenticated'
        || !$backupPath || dirname($backupPath) !== $backupDirectory || !str_ends_with($backupPath, '.sql.enc')
        || !hash_equals((string)($metadata['backup']['sha256'] ?? ''), (string)hash_file('sha256', $backupPath))) throw new RuntimeException('verified_backup_identity_invalid');
    $assertSources();
    $cache = new SchemaVersionStatusCache($config, $root);
    if (!$cache->clear()) throw new RuntimeException('schema_cache_invalidation_failed');
    $result = $service->migrate(array_keys($reviewed));
    if (array_diff($result['executed'], array_keys($reviewed)) !== []) throw new RuntimeException('unexpected_migration_executed');
    $assertSources();
    $resources = SqlSchemaResourceInspector::parse($root, array_map(static fn(string $name): string => 'database/migrations/' . $name, array_keys($reviewed)));
    $tables = [];
    foreach ($resources['schema'] as $table => $columns) {
        $query = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $query->execute([$table]);
        if (array_values($columns) !== $query->fetchAll(PDO::FETCH_COLUMN)) throw new RuntimeException('migrated_table_columns_mismatch');
        $tables[$table] = ['columns_match_source' => true, 'row_count' => (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()];
    }
    $triggers = $pdo->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='hotel_room_type_on_books_snapshots' ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN);
    if ($triggers !== ['trg_room_books_snapshot_no_delete','trg_room_books_snapshot_no_update']) throw new RuntimeException('migrated_trigger_scope_mismatch');
    $normalizeSql = static fn(string $sql): string => trim((string)preg_replace('/\s+/', ' ', rtrim(trim($sql), ';')));
    $triggerDefinitions = [];
    foreach (SchemaVersionService::splitSqlStatements((string)file_get_contents($root . '/database/migrations/20261002_create_room_type_on_books_snapshots.sql')) as $statement) {
        if (!preg_match('/CREATE\s+TRIGGER\s+IF\s+NOT\s+EXISTS\s+`([^`]+)`.*?FOR\s+EACH\s+ROW\s+(.+)$/si', $statement, $match)) continue;
        $query = $pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $query->execute([$match[1]]);
        if ($normalizeSql($match[2]) !== $normalizeSql((string)$query->fetchColumn())) throw new RuntimeException('migrated_trigger_definition_mismatch');
        $triggerDefinitions[$match[1]] = ['definition_matches_source' => true, 'normalized_sha256' => hash('sha256', $normalizeSql($match[2]))];
    }
    if (count($triggerDefinitions) !== 2) throw new RuntimeException('trigger_definition_verification_incomplete');
    $after = $service->status();
    $evidence += ['executed' => $result['executed'], 'pending_after' => $after['pending'], 'schema_ready' => $after['ready'],
        'applied_count' => $after['applied_count'], 'required_count' => $after['required_count'], 'cache_primed' => $cache->put($after),
        'tables' => $tables, 'triggers' => $triggers, 'trigger_definitions' => $triggerDefinitions, 'backup_metadata' => $metadataPath, 'business_rows_written' => 0];
    $evidence['mode'] = 'explicitly_authorized_selected_migrations';
    $writeEvidence($evidence);
    echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    $message = $error->getMessage();
    $safe = preg_match('/^[a-z_]+$/D', $message) ? $message : 'migration_failed_see_database_status';
    fwrite(STDERR, $safe . PHP_EOL);
    exit(1);
}
