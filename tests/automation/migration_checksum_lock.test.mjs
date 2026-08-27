import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import {
  buildAnchoredMigrationBootstrapLock,
  buildMigrationChecksumLock,
  buildMigrationChecksumLockAtRef,
  inspectMigrationChecksumLock,
  migrationChecksumLockCatalogDigest,
  MIGRATION_CHECKSUM_LOCK_PATH,
} from '../../scripts/lib/migration_checksum_lock.mjs';

const write = (root, relativePath, content) => {
  const target = path.join(root, relativePath);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content);
};

test('repository migration checksum lock covers every migration and frozen baseline source', () => {
  const repoRoot = path.resolve(import.meta.dirname, '../..');
  const result = inspectMigrationChecksumLock(repoRoot);
  assert.deepEqual(result.failures, []);
  assert.ok(result.metrics.migration_count >= 190);
  assert.ok(result.metrics.frozen_source_count >= 5);
});

test('migration checksum lock rejects content drift, missing entries, and rewritten history', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-migration-lock-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'database/migrations/20260101_first.sql', 'SELECT 1;\n');
  write(root, 'database/base.sql', 'CREATE TABLE base (id INT);\n');
  write(root, 'database/init_full.sql', 'SOURCE ./database/base.sql;\n');
  const baseline = buildMigrationChecksumLock(root);
  write(root, MIGRATION_CHECKSUM_LOCK_PATH, `${JSON.stringify(baseline, null, 2)}\n`);
  assert.deepEqual(inspectMigrationChecksumLock(root).failures, []);

  write(root, 'database/migrations/20260101_first.sql', 'SELECT 2;\n');
  assert.ok(inspectMigrationChecksumLock(root).failures.includes(
    'migration_checksum_mismatch:20260101_first.sql',
  ));

  write(root, 'database/migrations/20260101_first.sql', 'SELECT 1;\n');
  write(root, 'database/migrations/20260102_second.sql', 'SELECT 2;\n');
  assert.ok(inspectMigrationChecksumLock(root).failures.includes(
    'migration_missing:20260102_second.sql',
  ));

  const rewritten = buildMigrationChecksumLock(root);
  rewritten.migrations['20260101_first.sql'] = 'f'.repeat(64);
  write(root, MIGRATION_CHECKSUM_LOCK_PATH, `${JSON.stringify(rewritten, null, 2)}\n`);
  assert.ok(inspectMigrationChecksumLock(root, { baseLock: baseline }).failures.includes(
    'migration_lock_entry_changed:20260101_first.sql',
  ));
});

test('base tree identity protects the first lock bootstrap and missing refs fail closed', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-migration-base-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'database/migrations/20260101_first.sql', 'SELECT 1;\n');
  write(root, 'database/base.sql', 'CREATE TABLE base (id INT);\n');
  write(root, 'database/init_full.sql', 'SOURCE ./database/base.sql;\n');
  const run = (args) => spawnSync('git', args, { cwd: root, encoding: 'utf8', windowsHide: true });
  assert.equal(run(['init']).status, 0);
  assert.equal(run(['config', 'user.email', 'migration-lock@example.invalid']).status, 0);
  assert.equal(run(['config', 'user.name', 'Migration Lock Test']).status, 0);
  assert.equal(run(['add', 'database']).status, 0);
  assert.equal(run(['commit', '-m', 'baseline']).status, 0);
  const baseline = buildMigrationChecksumLockAtRef(root, 'HEAD');

  write(root, 'database/migrations/20260101_first.sql', 'SELECT 2;\n');
  const rewritten = buildMigrationChecksumLock(root);
  write(root, MIGRATION_CHECKSUM_LOCK_PATH, `${JSON.stringify(rewritten, null, 2)}\n`);
  assert.ok(inspectMigrationChecksumLock(root, { baseLock: baseline }).failures.includes(
    'migration_lock_entry_changed:20260101_first.sql',
  ));
  assert.throws(
    () => buildMigrationChecksumLockAtRef(root, 'refs/heads/definitely-missing'),
    /base_ref_unavailable/,
  );
});

test('anchored first lock repairs an upstream registered migration drift without weakening later history', (t) => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-migration-anchored-bootstrap-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  write(root, 'database/migrations/20260101_first.sql', 'SELECT 1;\n');
  write(root, 'database/base.sql', 'CREATE TABLE base (id INT);\n');
  write(root, 'database/init_full.sql', 'SOURCE ./database/base.sql;\n');
  const run = (args) => spawnSync('git', args, { cwd: root, encoding: 'utf8', windowsHide: true });
  assert.equal(run(['init']).status, 0);
  assert.equal(run(['config', 'user.email', 'migration-lock@example.invalid']).status, 0);
  assert.equal(run(['config', 'user.name', 'Migration Lock Test']).status, 0);
  assert.equal(run(['add', 'database']).status, 0);
  assert.equal(run(['commit', '-m', 'trusted baseline']).status, 0);
  const anchorCommit = run(['rev-parse', 'HEAD']).stdout.trim();
  const anchorLock = buildMigrationChecksumLockAtRef(root, anchorCommit);

  write(root, 'database/migrations/20260101_first.sql', 'SELECT 2;\n');
  write(root, 'database/migrations/20260102_second.sql', 'SELECT 2;\n');
  assert.equal(run(['add', 'database']).status, 0);
  assert.equal(run(['commit', '-m', 'base drift plus new migration']).status, 0);
  const baseCommit = run(['rev-parse', 'HEAD']).stdout.trim();

  write(root, 'database/migrations/20260101_first.sql', 'SELECT 1;\n');
  const currentLock = buildMigrationChecksumLock(root);
  currentLock.schema_version = 2;
  currentLock.bootstrap = {
    commit: anchorCommit,
    catalog_sha256: migrationChecksumLockCatalogDigest(anchorLock),
  };
  write(root, MIGRATION_CHECKSUM_LOCK_PATH, `${JSON.stringify(currentLock, null, 2)}\n`);
  const anchored = buildAnchoredMigrationBootstrapLock(root, currentLock, baseCommit);
  assert.deepEqual(anchored.failures, []);
  assert.deepEqual(anchored.repairedBaseEntries, [
    'migration_changed_in_base:20260101_first.sql',
  ]);
  assert.deepEqual(inspectMigrationChecksumLock(root, { baseLock: anchored.baseLock }).failures, []);

  write(root, 'database/migrations/20260102_second.sql', 'SELECT 3;\n');
  const rewritten = buildMigrationChecksumLock(root);
  rewritten.schema_version = 2;
  rewritten.bootstrap = currentLock.bootstrap;
  write(root, MIGRATION_CHECKSUM_LOCK_PATH, `${JSON.stringify(rewritten, null, 2)}\n`);
  assert.ok(inspectMigrationChecksumLock(root, { baseLock: anchored.baseLock }).failures.includes(
    'migration_lock_entry_changed:20260102_second.sql',
  ));
});
