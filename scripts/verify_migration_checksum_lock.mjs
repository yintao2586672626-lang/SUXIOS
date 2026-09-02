#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  buildAnchoredMigrationBootstrapLock,
  buildMigrationChecksumLockAtRef,
  inspectMigrationChecksumLock,
  inspectMigrationChecksumLockSnapshot,
  readMigrationChecksumLock,
  readMigrationChecksumLockAtRef,
} from './lib/migration_checksum_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const baseArgument = process.argv.find((argument) => argument.startsWith('--base-ref='));
const baseRef = baseArgument?.slice('--base-ref='.length)
  || process.env.SUXI_MIGRATION_LOCK_BASE_REF
  || 'origin/main';
let baseLock;
let baseLockSource = 'committed_lock';
let baseFailures = [];
let repairedBaseEntries = [];
try {
  const baseTreeLock = buildMigrationChecksumLockAtRef(repoRoot, baseRef);
  baseLock = readMigrationChecksumLockAtRef(repoRoot, baseRef);
  if (baseLock) {
    baseFailures = inspectMigrationChecksumLockSnapshot(baseLock, baseTreeLock)
      .map((failure) => `base_${failure}`);
  } else {
    const currentLock = readMigrationChecksumLock(repoRoot);
    if (currentLock?.schema_version === 2 && currentLock?.bootstrap) {
      const anchored = buildAnchoredMigrationBootstrapLock(repoRoot, currentLock, baseRef, baseTreeLock);
      baseFailures = anchored.failures;
      baseLock = anchored.baseLock;
      repairedBaseEntries = anchored.repairedBaseEntries;
      baseLockSource = 'anchored_ancestor_bootstrap';
    } else {
      baseLock = baseTreeLock;
      baseLockSource = 'computed_base_tree_bootstrap';
    }
  }
} catch (error) {
  baseFailures = [`migration_checksum_base_unreadable:${error.message}`];
}
const result = inspectMigrationChecksumLock(repoRoot, { baseLock });
result.failures = [...new Set([...baseFailures, ...result.failures])].sort();
result.metrics.base_lock_source = baseLockSource;
result.metrics.repaired_base_entry_count = repairedBaseEntries.length;
result.metrics.repaired_base_entries = repairedBaseEntries;

if (result.failures.length > 0) {
  console.error(JSON.stringify({ ...result, base_ref: baseRef, status: 'failed' }, null, 2));
  process.exit(1);
}

console.log(JSON.stringify({ ...result, base_ref: baseRef, status: 'passed' }, null, 2));
