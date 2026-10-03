import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const php = [process.env.PHP_BINARY, process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : null, 'php']
  .find((candidate) => candidate && (candidate === 'php' || existsSync(candidate)));
const runPhp = (code, ...args) => JSON.parse(execFileSync(php, ['-r', code, ...args], {
  cwd: root, encoding: 'utf8', windowsHide: true,
}));
const files = {
  '20261002_z_create_business_workspace.sql': ['hotel_business_workspace_snapshots'],
  '20261002_create_campaign_operation_versions.sql': ['campaign_operation_versions'],
  '20261002_z_create_guest_operations.sql': ['guest_operation_records', 'guest_operation_heads', 'guest_operation_requests'],
};
const tables = Object.values(files).flat();
const registryPath = path.join(root, 'scripts/cloud_hotel_id_column_registry.php');

test('workspace migrations retain complete table and trigger statements in the production parser', () => {
  for (const [file, tableNames] of Object.entries(files)) {
    const statements = runPhp(
      'require $argv[1]; echo json_encode(\\app\\service\\SchemaVersionService::splitSqlStatements(file_get_contents($argv[2])), JSON_THROW_ON_ERROR);',
      path.join(root, 'app/service/SchemaVersionService.php'), path.join(root, 'database/migrations', file),
    );
    assert.equal(statements.length, tableNames.length + (file.includes('guest_operations') ? 4 : 0), file);
    for (const table of tableNames) {
      const ddl = statements.find((statement) => statement.includes(`CREATE TABLE IF NOT EXISTS \`${table}\``));
      assert.ok(ddl, `${table} must be an independent CREATE TABLE statement`);
      assert.match(ddl, /`tenant_id` BIGINT UNSIGNED NOT NULL/);
      assert.match(ddl, /`hotel_id` BIGINT UNSIGNED NOT NULL/);
      assert.match(ddl, /`source_hotel_id` BIGINT UNSIGNED/);
    }
    for (const statement of statements) assert.doesNotMatch(statement, /\bDELIMITER\b|\$\$/);
    for (const statement of statements.filter((entry) => /CREATE TRIGGER.*BEFORE UPDATE/.test(entry))) {
      assert.match(statement, /COALESCE\(@suxi_cloud_hotel_id_migration, 0\) = 1/);
      assert.match(statement, /NEW\.`source_hotel_id` <=> OLD\.`source_hotel_id`/);
      assert.match(statement, /SIGNAL SQLSTATE '45000'[\s\S]*END IF;\s*END$/);
    }
  }
});

test('workspace identities migrate only canonical columns and preserve immutable evidence', () => {
  const registry = runPhp('require $argv[1]; echo json_encode(cloudHotelIdColumnRegistry(), JSON_THROW_ON_ERROR);', registryPath);
  const policies = runPhp('require $argv[1]; echo json_encode(cloudHotelIdJsonPolicyRegistry(), JSON_THROW_ON_ERROR);', registryPath);
  for (const table of tables) {
    assert.equal(registry.find((entry) => entry.table === table && entry.column === 'hotel_id')?.classification, 'positive_system_hotel_id', table);
    const source = registry.find((entry) => entry.table === table && entry.column === 'source_hotel_id');
    assert.equal(source?.classification, 'negative_non_system_hotel_id', table);
    assert.equal(source?.alias, 'immutable_source_hotel_id_evidence', table);
  }
  for (const [table, column] of [
    ['hotel_business_workspace_snapshots', 'payload_json'], ['campaign_operation_versions', 'payload_json'],
    ['guest_operation_records', 'content_json'], ['guest_operation_requests', 'record_ids_json'],
  ]) {
    const policy = policies.find((entry) => entry.table === table && entry.column === column);
    assert.equal(policy?.policy, 'immutable_digest_bound_evidence', `${table}.${column}`);
    assert.equal(policy?.selector, 'all_rows_preserved');
    assert.deepEqual(policy?.identity_keys, []);
  }
});

test('three workspace migrations have exact source hashes in the checksum lock', () => {
  const lock = JSON.parse(readFileSync(path.join(root, 'database/migration_checksums.lock.json'), 'utf8'));
  for (const file of Object.keys(files)) {
    const hash = createHash('sha256').update(readFileSync(path.join(root, 'database/migrations', file))).digest('hex');
    assert.equal(lock.migrations[file], hash, file);
  }
});
