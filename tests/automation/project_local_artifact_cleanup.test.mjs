import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const cleaner = readFileSync(path.join(repoRoot, 'scripts', 'clean_project_local_artifacts.ps1'), 'utf8');
const audit = readFileSync(path.join(repoRoot, 'scripts', 'project_self_audit.mjs'), 'utf8');

test('generic cleanup and self-audit share the same positive runtime cache whitelist', () => {
  const allowed = [
    'cache',
    'static-gzip',
    'static-html',
    'log',
    'codex-runner-contract',
    'test_ctrip_mapping',
  ];
  for (const runtimeName of allowed) {
    assert.match(cleaner, new RegExp(`"${runtimeName}"`));
    assert.match(audit, new RegExp(`'${runtimeName}'`));
  }

  assert.match(cleaner, /\$runtimeCleanupNames\s*=\s*@\(/);
  assert.match(audit, /const runtimeCleanupNames\s*=\s*\[/);
  assert.doesNotMatch(cleaner, /Get-ChildItem\s+-LiteralPath\s+["']runtime["']/);
  assert.doesNotMatch(audit, /readdirSync\(runtimePath/);
});

test('generic cleanup never targets durable or unknown runtime state', () => {
  const forbidden = [
    'manual_fetch_tasks',
    'upload',
    'migration_backups',
    'locks',
    'competitor-task-locks',
    'phase2_daily_workbench_patrol',
    'phase3_operation_effect_loop',
  ];
  for (const runtimeName of forbidden) {
    assert.doesNotMatch(cleaner, new RegExp(`runtime[/\\\\]${runtimeName}`));
    assert.doesNotMatch(audit, new RegExp(`runtime[/\\\\]${runtimeName}`));
  }
  assert.doesNotMatch(cleaner, /\$candidatePaths\s*\+=\s*["']runtime["']/);
  assert.doesNotMatch(audit, /candidates\.push\(["']runtime["']\)/);
});
