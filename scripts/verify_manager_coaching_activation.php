<?php
declare(strict_types=1);
// Read-only verification: schema metadata and row counts; no personnel records.
require dirname(__DIR__) . '/vendor/autoload.php';
use think\App;
use think\facade\Db;

$root = dirname(__DIR__);
foreach (spl_autoload_functions() ?: [] as $autoload) {
    $loader = is_array($autoload) ? ($autoload[0] ?? null) : null;
    if ($loader instanceof Composer\Autoload\ClassLoader) $loader->setPsr4('app\\', [$root . '/app']);
}
(new App($root))->initialize();
try {
    $file = '20260926_create_manager_coaching.sql';
    $sql = file_get_contents($root . '/database/migrations/' . $file);
    $migration = Db::name('schema_versions')->where('migration', $file)
        ->field('migration,version,checksum,execution_kind')->find();
    if (!$migration || !hash_equals(hash('sha256', $sql), (string)$migration['checksum'])) throw new RuntimeException('migration_checksum_mismatch');
    preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`\s*\((.*?)\) ENGINE=/s', $sql, $definitions, PREG_SET_ORDER);
    if (count($definitions) !== 2) throw new RuntimeException('unexpected_migration_table_count');
    $tables = [];
    foreach ($definitions as $definition) {
        $table = $definition[1];
        preg_match_all('/^\s*`([^`]+)`\s+/m', $definition[2], $matches);
        $columns = Db::query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
        if (array_column($columns, 'COLUMN_NAME') !== $matches[1]) throw new RuntimeException('column_readback_mismatch:' . $table);
        preg_match_all('/UNIQUE KEY `([^`]+)` \(([^)]+)\)/', $definition[2], $unique, PREG_SET_ORDER);
        foreach ($unique as $index) {
            preg_match_all('/`([^`]+)`/', $index[2], $fields);
            $actual = Db::query('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX', [$table, $index[1]]);
            if (array_column($actual, 'COLUMN_NAME') !== $fields[1]) throw new RuntimeException('unique_index_readback_mismatch:' . $table);
        }
        $metadata = Db::query('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])[0] ?? [];
        if (($metadata['ENGINE'] ?? '') !== 'InnoDB') throw new RuntimeException('unexpected_table_engine:' . $table);
        $tables[] = ['table' => $table, 'column_count' => count($columns), 'unique_indexes_verified' => array_column($unique, 1),
            'row_count' => (int)Db::name($table)->count(), 'engine' => $metadata['ENGINE'], 'collation' => $metadata['TABLE_COLLATION']];
    }
    $result = ['status' => 'passed', 'evidence_tier' => 'local_mysql_schema_readback', 'migration' => $migration,
        'tables' => $tables, 'writes_performed' => false, 'shared_approval_env_cleared' => getenv('SUXI_LINKED_WORKTREE_SHARED_DB_MIGRATION_APPROVED') === false,
        'account_page_verified' => false, 'field_effect_verified' => false];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'reason' => preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $e->getMessage())]) . PHP_EOL);
    exit(1);
}
