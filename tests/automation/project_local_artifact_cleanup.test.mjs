import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, mkdirSync, writeFileSync, existsSync, rmSync, symlinkSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
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

test('capture screenshots require an explicit destructive cleanup opt-in', () => {
  assert.match(cleaner, /\[switch\]\$IncludeCaptureAssets/);
  assert.match(cleaner, /if \(\$IncludeCaptureAssets\)/);
  assert.doesNotMatch(audit, /candidates\.push\(path\.join\('reports', '(?:ctrip|meituan)_capture_assets'\)\)/);
});

function withCleanupFixture(run) {
  const fixture = mkdtempSync(path.join(os.tmpdir(), 'suxi-cleanup-contract-'));
  try {
    const workspace = path.join(fixture, 'workspace');
    mkdirSync(workspace);
    const put = (relative) => {
      const file = path.join(workspace, relative);
      mkdirSync(path.dirname(file), { recursive: true });
      writeFileSync(file, 'synthetic cleanup contract');
      return file;
    };
    const execute = (...args) => spawnSync('powershell', [
      '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
      path.join(repoRoot, 'scripts/clean_project_local_artifacts.ps1'), ...args,
    ], { cwd: workspace, encoding: 'utf8' });
    run({ fixture, workspace, put, execute });
  } finally {
    assert.ok(path.resolve(fixture).startsWith(path.resolve(os.tmpdir()) + path.sep));
    assert.ok(path.basename(fixture).startsWith('suxi-cleanup-contract-'));
    rmSync(fixture, { recursive: true, force: true });
  }
}

test('actual cleanup removes disposable caches while preserving recovery, captures and profile fixtures', { skip: process.platform !== 'win32' }, () => {
  withCleanupFixture(({ put, execute }) => {
    const disposable = ['test-results/cache.txt', 'runtime/cache/cache.txt', 'runtime/static-gzip/cache.txt'].map(put);
    const durable = [
      'output/payback-verification/recovery.aes256gcm', 'output/handoffs/current.md',
      'reports/ctrip_browser_capture_fixture.json', 'reports/ctrip_capture_assets/fixture.png',
      'storage/ctrip_profile_fixture/Default/Cache/fixture.txt',
      'runtime/manual_fetch_tasks/state.json', 'database/backups/recovery.sql',
    ].map(put);
    const preview = execute();
    assert.equal(preview.status, 0, preview.stderr + preview.stdout);
    for (const file of [...disposable, ...durable]) assert.ok(existsSync(file));
    const apply = execute('-Apply');
    assert.equal(apply.status, 0, apply.stderr + apply.stdout);
    for (const file of disposable) assert.equal(existsSync(file), false, file);
    for (const file of durable) assert.ok(existsSync(file), file);
  });
});

test('cleanup rejects a junction before deleting any selected target', { skip: process.platform !== 'win32' }, () => {
  withCleanupFixture(({ fixture, workspace, put, execute }) => {
    const cache = put('runtime/cache/cache.txt');
    const outside = path.join(fixture, 'outside');
    mkdirSync(outside);
    const canary = path.join(outside, 'canary.txt');
    writeFileSync(canary, 'synthetic outside boundary');
    mkdirSync(path.join(workspace, 'test-results'));
    symlinkSync(outside, path.join(workspace, 'test-results', 'linked'), 'junction');
    const apply = execute('-Apply');
    assert.notEqual(apply.status, 0);
    assert.ok(existsSync(canary));
    assert.ok(existsSync(cache));
  });
});

test('cleanup rejects a junction workspace root before deleting its physical caches', { skip: process.platform !== 'win32' }, () => {
  withCleanupFixture(({ fixture, workspace, put }) => {
    const cache = put('runtime/cache/canary.txt');
    const alias = path.join(fixture, 'workspace-alias');
    assert.ok(path.resolve(alias).startsWith(path.resolve(fixture) + path.sep));
    symlinkSync(workspace, alias, 'junction');
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const apply = spawnSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
      `Set-Location -LiteralPath ${quote(alias)}\n& ${quote(path.join(repoRoot, 'scripts/clean_project_local_artifacts.ps1'))} -Apply`,
    ], { cwd: fixture, encoding: 'utf8', windowsHide: true });
    assert.notEqual(apply.status, 0);
    assert.match(apply.stderr + apply.stdout, /Refusing linked cleanup path/);
    assert.ok(existsSync(cache));
  });
});

test('cleanup rejects a junction ancestor of an ordinary workspace root', { skip: process.platform !== 'win32' }, () => {
  withCleanupFixture(({ fixture }) => {
    const physicalParent = path.join(fixture, 'physical-parent');
    const workspace = path.join(physicalParent, 'workspace');
    const cache = path.join(workspace, 'runtime/cache/canary.txt');
    mkdirSync(path.dirname(cache), { recursive: true });
    writeFileSync(cache, 'synthetic ancestor boundary');
    const alias = path.join(fixture, 'parent-alias');
    assert.ok(path.resolve(alias).startsWith(path.resolve(fixture) + path.sep));
    symlinkSync(physicalParent, alias, 'junction');
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const apply = spawnSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
      `Set-Location -LiteralPath ${quote(path.join(alias, 'workspace'))}\n& ${quote(path.join(repoRoot, 'scripts/clean_project_local_artifacts.ps1'))} -Apply`,
    ], { cwd: fixture, encoding: 'utf8', windowsHide: true });
    assert.notEqual(apply.status, 0, apply.stderr + apply.stdout);
    assert.match(apply.stderr + apply.stdout, /Refusing linked cleanup path/);
    assert.ok(existsSync(cache));
  });
});

test('generic cleaner refuses financial backup opt-in and self-audit excludes secret content', { skip: process.platform !== 'win32' }, () => {
  withCleanupFixture(({ put, execute }) => {
    const recovery = put('database/backups/recovery.sql');
    const apply = execute('-Apply', '-IncludeSensitiveBackups');
    assert.notEqual(apply.status, 0);
    assert.ok(existsSync(recovery));
  });
  assert.doesNotMatch(audit, /textExtensions[^\n]*'\.env'/);
  assert.match(audit, /if \(isProtectedAuditPath\(relativePath\)\) continue/);
  assert.doesNotMatch(audit, /collectProfileCacheCandidates/);
});
