import assert from 'node:assert/strict';
import {
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const verifierPath = path.join(repoRoot, 'scripts', 'verify_pr_base_freshness.mjs');

function run(command, args, cwd, expectSuccess = true) {
  const result = spawnSync(command, args, {
    cwd,
    encoding: 'utf8',
    timeout: 30_000,
  });
  assert.equal(result.error, undefined, result.error?.message || `${command} could not start`);
  if (expectSuccess) {
    assert.equal(result.status, 0, `${command} failed:\n${result.stdout}\n${result.stderr}`);
  } else {
    assert.notEqual(result.status, 0, `${command} was expected to fail`);
  }
  return result;
}

function git(cwd, ...args) {
  return run('git', args, cwd);
}

function commitFile(cwd, name, content, message) {
  writeFileSync(path.join(cwd, name), content, 'utf8');
  git(cwd, 'add', '--', name);
  git(cwd, 'commit', '-m', message);
}

function verify(cwd, expectSuccess) {
  const result = run('node', [
    verifierPath,
    '--base-ref=main',
    '--head-ref=HEAD',
    `--repo-root=${cwd}`,
  ], repoRoot, expectSuccess);
  return JSON.parse(result.stdout.trim());
}

test('PR freshness blocks a branch after main advances and recovers after sync', () => {
  const fixture = mkdtempSync(path.join(tmpdir(), 'suxios-pr-freshness-'));
  try {
    git(fixture, 'init', '--initial-branch=main');
    git(fixture, 'config', 'user.name', 'SUXIOS Test');
    git(fixture, 'config', 'user.email', 'suxios-test@example.invalid');
    commitFile(fixture, 'base.txt', 'base\n', 'base');
    git(fixture, 'checkout', '-b', 'feature');
    commitFile(fixture, 'feature.txt', 'feature\n', 'feature');

    const initiallyFresh = verify(fixture, true);
    assert.equal(initiallyFresh.status, 'ready');
    assert.equal(initiallyFresh.base_only_count, 0);

    git(fixture, 'checkout', 'main');
    commitFile(fixture, 'main-next.txt', 'main next\n', 'advance main');
    git(fixture, 'checkout', 'feature');

    const stale = verify(fixture, false);
    assert.equal(stale.status, 'blocked');
    assert.equal(stale.reason, 'head_missing_base_commits');
    assert.equal(stale.base_only_count, 1);

    git(fixture, 'merge', '--no-ff', 'main', '-m', 'sync main');
    const resynced = verify(fixture, true);
    assert.equal(resynced.status, 'ready');
    assert.equal(resynced.base_only_count, 0);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('GitHub verification checks the exact PR head against the latest base', () => {
  const workflow = readFileSync(path.join(repoRoot, '.github', 'workflows', 'php.yml'), 'utf8');
  const packageJson = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));

  assert.equal(
    packageJson.scripts['verify:pr-base-freshness'],
    'node scripts/verify_pr_base_freshness.mjs'
  );
  assert.match(workflow, /branch_freshness:\s*\n\s*name: Branch freshness/);
  assert.match(workflow, /ref: \$\{\{ github\.event\.pull_request\.head\.sha \|\| github\.sha \}\}/);
  assert.match(workflow, /refs\/heads\/\$\{BASE_REF\}:refs\/remotes\/origin\/\$\{BASE_REF\}/);
  assert.match(workflow, /npm run verify:pr-base-freshness/);
  assert.match(workflow, /needs:[\s\S]*- branch_freshness/);
  assert.match(workflow, /BRANCH_FRESHNESS_RESULT: \$\{\{ needs\.branch_freshness\.result \}\}/);
});
