import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import test from 'node:test';
import { resolveOuterContextRoot } from '../../hooks/lib/context_root.mjs';

const runGit = (cwd, args) => execFileSync(
  'git',
  args,
  {
    cwd,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  },
);

const canonicalPath = (value) => fs.realpathSync.native(value).toLowerCase();

test('outer context root resolves through the main Git worktree', (t) => {
  const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-context-root-'));
  t.after(() => fs.rmSync(fixtureRoot, { recursive: true, force: true }));

  const outerRoot = path.join(fixtureRoot, 'project');
  const mainWorktree = path.join(outerRoot, 'HOTEL');
  const linkedWorktree = path.join(fixtureRoot, 'isolated', 'review');
  const linkedParent = path.dirname(linkedWorktree);
  fs.mkdirSync(mainWorktree, { recursive: true });
  fs.writeFileSync(path.join(outerRoot, 'AGENTS.md'), '# Outer instructions\n');
  fs.writeFileSync(path.join(mainWorktree, 'tracked.txt'), 'fixture\n');

  runGit(mainWorktree, ['init']);
  runGit(mainWorktree, ['config', 'user.email', 'context-root@example.invalid']);
  runGit(mainWorktree, ['config', 'user.name', 'Context Root Test']);
  runGit(mainWorktree, ['add', 'tracked.txt']);
  runGit(mainWorktree, ['commit', '-m', 'fixture']);
  runGit(mainWorktree, ['worktree', 'add', '-b', 'review/context-root', linkedWorktree]);
  fs.writeFileSync(
    path.join(linkedParent, 'AGENTS.md'),
    '# Stale shadow instructions that must never override the main outer root\n',
  );

  assert.equal(
    canonicalPath(resolveOuterContextRoot(mainWorktree)),
    canonicalPath(outerRoot),
  );
  assert.notEqual(canonicalPath(path.dirname(linkedWorktree)), canonicalPath(outerRoot));
  assert.equal(
    canonicalPath(resolveOuterContextRoot(linkedWorktree)),
    canonicalPath(outerRoot),
  );
});

test('staged context snapshot copies the authoritative resolved outer instructions', () => {
  const stagedVerifier = fs.readFileSync('hooks/verify-staged-frontend-build.mjs', 'utf8');
  assert.match(stagedVerifier, /resolveOuterContextRoot\(repoRoot\)/);
  assert.doesNotMatch(stagedVerifier, /path\.resolve\(repoRoot, '\.\.', 'AGENTS\.md'\)/);
});
