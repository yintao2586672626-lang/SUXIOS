import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

export const MIGRATION_CHECKSUM_LOCK_PATH = 'database/migration_checksums.lock.json';
const SHA256 = /^[a-f0-9]{64}$/;
const COMMIT_SHA = /^[a-f0-9]{40}$/;

const normalizedPath = (value) => String(value || '').replaceAll('\\', '/');
const digest = (buffer) => createHash('sha256').update(buffer).digest('hex');
const sortedRecord = (value) => Object.fromEntries(Object.entries(
  value && typeof value === 'object' && !Array.isArray(value) ? value : {},
).sort(([left], [right]) => left.localeCompare(right)));

export const migrationChecksumLockCatalogDigest = (lock) => digest(Buffer.from(JSON.stringify({
  migrations: sortedRecord(lock?.migrations),
  frozen_sources: sortedRecord(lock?.frozen_sources),
})));

const buildLock = (migrationPaths, readFile) => {
  const migrations = {};
  for (const migrationPath of [...migrationPaths].sort()) {
    migrations[path.posix.basename(migrationPath)] = digest(readFile(migrationPath));
  }

  const initRelativePath = 'database/init_full.sql';
  const initSource = readFile(initRelativePath).toString('utf8');
  const sourcePaths = new Set([initRelativePath]);
  for (const match of initSource.matchAll(/^SOURCE\s+\.\/(database\/[^;]+);\s*$/gmi)) {
    const sourcePath = normalizedPath(match[1]);
    if (sourcePath.includes('..')) throw new Error(`Unsafe frozen SQL source: ${sourcePath}`);
    if (!sourcePath.startsWith('database/migrations/')) sourcePaths.add(sourcePath);
  }
  const frozenSources = {};
  for (const sourcePath of [...sourcePaths].sort()) {
    frozenSources[sourcePath] = digest(readFile(sourcePath));
  }

  return {
    schema_version: 1,
    algorithm: 'sha256',
    migrations,
    frozen_sources: frozenSources,
  };
};

export function buildMigrationChecksumLock(repoRoot) {
  const migrationRoot = path.join(repoRoot, 'database', 'migrations');
  const migrationPaths = fs.readdirSync(migrationRoot)
    .filter((entry) => entry.endsWith('.sql'))
    .map((entry) => `database/migrations/${entry}`);
  return buildLock(migrationPaths, (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath)));
}

const runGit = (repoRoot, args, encoding = 'utf8') => {
  const result = spawnSync('git', args, {
    cwd: repoRoot,
    encoding: encoding === null ? null : encoding,
    windowsHide: true,
    maxBuffer: 64 * 1024 * 1024,
  });
  if (result.status !== 0) {
    throw new Error(`git_${args[0]}_failed:${String(result.stderr || '').trim() || result.status}`);
  }
  return result.stdout;
};

export function buildMigrationChecksumLockAtRef(repoRoot, ref) {
  if (!String(ref || '').trim()) throw new Error('base_ref_missing');
  try {
    runGit(repoRoot, ['rev-parse', '--verify', `${ref}^{commit}`]);
  } catch {
    throw new Error(`base_ref_unavailable:${ref}`);
  }
  const migrationPaths = runGit(
    repoRoot,
    ['ls-tree', '-r', '--name-only', '-z', ref, '--', 'database/migrations'],
    null,
  ).toString('utf8').split('\0').filter((entry) => entry.endsWith('.sql')).map(normalizedPath);
  if (migrationPaths.length === 0) throw new Error(`base_migration_catalog_missing:${ref}`);
  return buildLock(migrationPaths, (relativePath) => Buffer.from(runGit(
    repoRoot,
    ['show', `${ref}:${relativePath}`],
    null,
  )));
}

export function readMigrationChecksumLock(repoRoot) {
  const lockPath = path.join(repoRoot, MIGRATION_CHECKSUM_LOCK_PATH);
  const lock = JSON.parse(fs.readFileSync(lockPath, 'utf8'));
  return lock;
}

export function readMigrationChecksumLockAtRef(repoRoot, ref) {
  if (!ref) throw new Error('base_ref_missing');
  runGit(repoRoot, ['rev-parse', '--verify', `${ref}^{commit}`]);
  const result = spawnSync('git', ['show', `${ref}:${MIGRATION_CHECKSUM_LOCK_PATH}`], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.status !== 0 || !result.stdout.trim()) return null;
  return JSON.parse(result.stdout);
}

export function buildAnchoredMigrationBootstrapLock(repoRoot, currentLock, baseRef, baseTreeLock = null) {
  const failures = [];
  const bootstrap = currentLock?.bootstrap;
  const commit = String(bootstrap?.commit || '').trim().toLowerCase();
  const catalogSha256 = String(bootstrap?.catalog_sha256 || '').trim().toLowerCase();
  if (!COMMIT_SHA.test(commit)) failures.push('migration_checksum_bootstrap_commit_invalid');
  if (!SHA256.test(catalogSha256)) failures.push('migration_checksum_bootstrap_catalog_digest_invalid');
  if (failures.length > 0) return { failures, baseLock: null, repairedBaseEntries: [] };

  const ancestor = spawnSync('git', ['merge-base', '--is-ancestor', commit, baseRef], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  if (ancestor.status !== 0) {
    failures.push(ancestor.status === 1
      ? 'migration_checksum_bootstrap_not_base_ancestor'
      : `migration_checksum_bootstrap_ancestry_unreadable:${String(ancestor.stderr || '').trim() || ancestor.status}`);
    return { failures, baseLock: null, repairedBaseEntries: [] };
  }

  let anchorLock;
  let effectiveBaseTreeLock = baseTreeLock;
  try {
    anchorLock = buildMigrationChecksumLockAtRef(repoRoot, commit);
    effectiveBaseTreeLock ||= buildMigrationChecksumLockAtRef(repoRoot, baseRef);
  } catch (error) {
    return {
      failures: [`migration_checksum_bootstrap_unreadable:${error.message}`],
      baseLock: null,
      repairedBaseEntries: [],
    };
  }
  if (migrationChecksumLockCatalogDigest(anchorLock) !== catalogSha256) {
    failures.push('migration_checksum_bootstrap_catalog_digest_mismatch');
  }

  const baseLock = {
    schema_version: 1,
    algorithm: 'sha256',
    migrations: {},
    frozen_sources: {},
  };
  const repairedBaseEntries = [];
  for (const [section, label] of [
    ['migrations', 'migration'],
    ['frozen_sources', 'frozen_source'],
  ]) {
    const anchorRows = anchorLock[section] || {};
    const baseRows = effectiveBaseTreeLock[section] || {};
    for (const [name, checksum] of Object.entries(anchorRows)) {
      baseLock[section][name] = checksum;
      if (!(name in baseRows)) repairedBaseEntries.push(`${label}_removed_in_base:${name}`);
      else if (baseRows[name] !== checksum) repairedBaseEntries.push(`${label}_changed_in_base:${name}`);
    }
    for (const [name, checksum] of Object.entries(baseRows)) {
      if (!(name in anchorRows)) baseLock[section][name] = checksum;
    }
  }

  return {
    failures: [...new Set(failures)].sort(),
    baseLock,
    repairedBaseEntries: [...new Set(repairedBaseEntries)].sort(),
  };
}

const compareSection = (failures, current, expected, section, label) => {
  const currentRows = current && typeof current === 'object' && !Array.isArray(current) ? current : {};
  const expectedRows = expected && typeof expected === 'object' && !Array.isArray(expected) ? expected : {};
  for (const name of Object.keys(expectedRows)) {
    if (!(name in currentRows)) failures.push(`${label}_missing:${name}`);
    else if (currentRows[name] !== expectedRows[name]) failures.push(`${label}_checksum_mismatch:${name}`);
  }
  for (const name of Object.keys(currentRows)) {
    if (!(name in expectedRows)) failures.push(`${label}_unknown:${name}`);
    if (!SHA256.test(String(currentRows[name] || ''))) failures.push(`${label}_checksum_invalid:${name}`);
  }
};

export function inspectMigrationChecksumLockSnapshot(currentLock, expected) {
  const failures = [];
  if (![1, 2].includes(currentLock?.schema_version)) failures.push('migration_checksum_lock_schema_version_invalid');
  if (currentLock?.algorithm !== 'sha256') failures.push('migration_checksum_lock_algorithm_invalid');
  if (currentLock?.schema_version === 2) {
    if (!COMMIT_SHA.test(String(currentLock?.bootstrap?.commit || ''))) {
      failures.push('migration_checksum_bootstrap_commit_invalid');
    }
    if (!SHA256.test(String(currentLock?.bootstrap?.catalog_sha256 || ''))) {
      failures.push('migration_checksum_bootstrap_catalog_digest_invalid');
    }
  }
  compareSection(failures, currentLock?.migrations, expected?.migrations, 'migrations', 'migration');
  compareSection(failures, currentLock?.frozen_sources, expected?.frozen_sources, 'frozen_sources', 'frozen_source');
  return [...new Set(failures)].sort();
}

export function inspectMigrationChecksumLock(repoRoot, { baseLock = null } = {}) {
  const failures = [];
  let currentLock = null;
  let expected = null;
  try {
    currentLock = readMigrationChecksumLock(repoRoot);
    expected = buildMigrationChecksumLock(repoRoot);
  } catch (error) {
    return {
      failures: [`migration_checksum_lock_unreadable:${error.message}`],
      metrics: { migration_count: 0, frozen_source_count: 0, base_lock_checked: false },
    };
  }

  failures.push(...inspectMigrationChecksumLockSnapshot(currentLock, expected));

  if (baseLock) {
    for (const [section, label] of [
      ['migrations', 'migration'],
      ['frozen_sources', 'frozen_source'],
    ]) {
      const previous = baseLock[section] && typeof baseLock[section] === 'object'
        ? baseLock[section]
        : {};
      const current = currentLock[section] && typeof currentLock[section] === 'object'
        ? currentLock[section]
        : {};
      for (const [name, checksum] of Object.entries(previous)) {
        if (!(name in current)) failures.push(`${label}_lock_entry_removed:${name}`);
        else if (current[name] !== checksum) failures.push(`${label}_lock_entry_changed:${name}`);
      }
    }
    if (baseLock.bootstrap
      && JSON.stringify(currentLock.bootstrap || null) !== JSON.stringify(baseLock.bootstrap)) {
      failures.push('migration_checksum_bootstrap_changed');
    }
  }

  return {
    failures: [...new Set(failures)].sort(),
    metrics: {
      migration_count: Object.keys(expected.migrations).length,
      frozen_source_count: Object.keys(expected.frozen_sources).length,
      base_lock_checked: Boolean(baseLock),
    },
  };
}
