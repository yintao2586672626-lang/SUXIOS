import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

const failures = [];
const warnings = [];
const passes = [];
let commandExecutionUnavailable = false;

function addPass(message) {
  passes.push(message);
}

function addFailure(message) {
  failures.push(message);
}

function addWarning(message) {
  warnings.push(message);
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    shell: false,
    ...options,
  });

  return {
    status: result.status,
    stdout: String(result.stdout || '').trim(),
    stderr: String(result.stderr || '').trim(),
    error: result.error,
  };
}

function commandFailureMessage(command, args, result) {
  if (result.error?.code === 'EPERM') {
    commandExecutionUnavailable = true;
    return `external command execution is not permitted for ${command} ${args.join(' ')}`;
  }
  return result.stderr || result.error?.message || 'unknown error';
}

function checkTrackedBackups() {
  const result = run('git', ['ls-files', 'database/backups']);
  if (result.status !== 0) {
    addFailure(`git ls-files database/backups failed: ${commandFailureMessage('git', ['ls-files', 'database/backups'], result)}`);
    return;
  }

  if (result.stdout !== '') {
    addFailure('database/backups contains git-tracked files; backups must not be tracked before release.');
  } else {
    addPass('database/backups has no git-tracked files.');
  }
}

function checkGitWorktree() {
  if (fs.existsSync('.git/index.lock')) {
    addFailure('.git/index.lock exists; local git index is not ready for release operations.');
  } else {
    addPass('.git/index.lock is absent.');
  }

  const result = run('git', ['status', '--short', '--branch']);
  if (result.status !== 0) {
    addFailure(`git status --short --branch failed: ${commandFailureMessage('git', ['status', '--short', '--branch'], result)}`);
    return;
  }

  const lines = result.stdout.split(/\r?\n/).filter(Boolean);
  const changedLines = lines.filter((line) => !line.startsWith('## '));
  if (changedLines.length > 0) {
    addFailure(`local worktree is not clean (${changedLines.length} changed entries). Review, commit, or intentionally isolate them before release.`);
  } else {
    addPass('local worktree is clean.');
  }
}

function checkGitHubPr() {
  const prNumber = process.env.RELEASE_PR_NUMBER || '1';
  const result = run('gh', [
    'pr',
    'view',
    prNumber,
    '--json',
    'number,url,headRefOid,mergeable,statusCheckRollup',
  ]);

  if (result.status !== 0) {
    addFailure(`gh pr view ${prNumber} failed: ${commandFailureMessage('gh', [
      'pr',
      'view',
      prNumber,
      '--json',
      'number,url,headRefOid,mergeable,statusCheckRollup',
    ], result)}`);
    return;
  }

  let pr;
  try {
    pr = JSON.parse(result.stdout);
  } catch (error) {
    addFailure(`gh pr view ${prNumber} did not return valid JSON: ${error.message}`);
    return;
  }

  if (pr.mergeable === 'MERGEABLE') {
    addPass(`PR #${pr.number} is mergeable at ${pr.headRefOid}.`);
  } else {
    addFailure(`PR #${pr.number} is not mergeable; current mergeable state is ${pr.mergeable}.`);
  }

  const checks = Array.isArray(pr.statusCheckRollup) ? pr.statusCheckRollup : [];
  if (checks.length === 0) {
    addFailure(`PR #${pr.number} has no status checks to verify.`);
    return;
  }

  const incomplete = checks.filter((check) => check.status !== 'COMPLETED');
  const failed = checks.filter((check) => check.status === 'COMPLETED' && check.conclusion !== 'SUCCESS');
  if (incomplete.length > 0 || failed.length > 0) {
    addFailure(`PR #${pr.number} checks are not all green (${incomplete.length} incomplete, ${failed.length} failed).`);
  } else {
    addPass(`PR #${pr.number} status checks are all green (${checks.length} checks).`);
  }

  addWarning(`Release external state was checked against ${pr.url}; rerun this command after every PR update.`);
}

checkTrackedBackups();
checkGitWorktree();
checkGitHubPr();

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of warnings) {
  console.warn(`WARN: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}
if (commandExecutionUnavailable) {
  console.error('FAIL: This runtime blocked Node child_process access to external commands. Run `git ls-files database/backups`, `git status --short --branch`, and `gh pr view <PR> --json statusCheckRollup,mergeable,headRefOid` directly before release.');
}

const failureCount = failures.length + (commandExecutionUnavailable ? 1 : 0);
console.log(`Release external-state summary: ${passes.length} passed, ${warnings.length} warnings, ${failureCount} failures.`);

if (failureCount > 0) {
  process.exit(1);
}
