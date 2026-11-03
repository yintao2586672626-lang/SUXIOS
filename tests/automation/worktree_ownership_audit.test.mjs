import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const audit = fs.readFileSync(path.join(root, 'scripts/audit_worktree_ownership.ps1'));

test('ownership audit resolves its own Unicode checkout and fails closed for dirty state', { skip: process.platform !== 'win32' }, () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-ownership-'));
  const checkout = path.join(directory, '中文项目');
  fs.mkdirSync(path.join(checkout, 'scripts'), { recursive: true });
  const invokeGit = (...args) => execFileSync('git', ['-C', checkout, ...args], { encoding: 'utf8', windowsHide: true });
  const invokeAudit = (...args) => JSON.parse(execFileSync('powershell.exe', [
    '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(checkout, 'scripts/audit_worktree_ownership.ps1'),
    '-Format', 'Json', ...args,
  ], { cwd: directory, encoding: 'utf8', windowsHide: true }));
  try {
    fs.writeFileSync(path.join(checkout, 'scripts/audit_worktree_ownership.ps1'), audit);
    invokeGit('init', '-q');
    invokeGit('add', 'scripts/audit_worktree_ownership.ps1');
    invokeGit('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture');
    const clean = invokeAudit();
    assert.equal(clean.active.changed_entries, 0);
    assert.equal(clean.active.upstream, null);
    assert.equal(clean.active.decision, 'write_allowed_after_target_scope_check_upstream_unverified');
    assert.equal(clean.worktrees.registered, 1);
    assert.equal(path.basename(clean.repository), '中文项目');
    assert.equal(fs.statSync(path.join(clean.repository, '.git')).ino, fs.statSync(path.join(checkout, '.git')).ino);
    const explicit = invokeAudit('-RepositoryPath', checkout);
    assert.equal(explicit.active.head, clean.active.head);
    invokeGit('checkout', '--detach', '-q');
    const detached = invokeAudit();
    assert.equal(detached.active.branch, '');
    assert.equal(detached.active.head, clean.active.head);
    fs.writeFileSync(path.join(checkout, 'dirty.txt'), 'synthetic');
    const dirty = invokeAudit();
    assert.equal(dirty.active.changed_entries, 1);
    assert.equal(dirty.active.decision, 'read_only_until_target_diff_ownership_is_confirmed');
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
