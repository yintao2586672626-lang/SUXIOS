import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const failures = [];
const warnings = [];
const passes = [];
let commandExecutionUnavailable = false;

function resolveOutputPath(outputPath) {
  return path.isAbsolute(outputPath) ? outputPath : path.resolve(outputPath);
}

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

function parseLines(text) {
  return String(text || '').split(/\r?\n/).filter(Boolean);
}

function checkTrackedBackupsOutput(stdout) {
  if (String(stdout || '').trim() !== '') {
    addFailure('database/backups contains git-tracked files; backups must not be tracked before release.');
  } else {
    addPass('database/backups has no git-tracked files.');
  }
}

function checkGitStatusOutput(stdout) {
  const lines = parseLines(stdout);
  const changedLines = lines.filter((line) => !line.startsWith('## '));
  if (changedLines.length > 0) {
    addFailure(`local worktree is not clean (${changedLines.length} changed entries). Review, commit, or intentionally isolate them before release.`);
  } else {
    addPass('local worktree is clean.');
  }
}

function checkPrObject(pr) {
  if (!pr || typeof pr !== 'object') {
    addFailure('PR evidence is missing or invalid.');
    return;
  }

  if (/^[a-f0-9]{40}$/i.test(String(pr.headRefOid || ''))) {
    addPass(`PR head sha is recorded: ${pr.headRefOid}.`);
  } else {
    addFailure('PR headRefOid is missing or not a 40-character commit sha.');
  }

  if (pr.mergeable === 'MERGEABLE') {
    addPass(`PR #${pr.number} is mergeable at ${pr.headRefOid}.`);
  } else {
    addFailure(`PR #${pr.number ?? 'unknown'} is not mergeable; current mergeable state is ${pr.mergeable ?? 'missing'}.`);
  }

  const checks = Array.isArray(pr.statusCheckRollup) ? pr.statusCheckRollup : [];
  if (checks.length === 0) {
    addFailure(`PR #${pr.number ?? 'unknown'} has no status checks to verify.`);
    return;
  }

  const incomplete = checks.filter((check) => check.status !== 'COMPLETED');
  const failed = checks.filter((check) => check.status === 'COMPLETED' && check.conclusion !== 'SUCCESS');
  if (incomplete.length > 0 || failed.length > 0) {
    addFailure(`PR #${pr.number ?? 'unknown'} checks are not all green (${incomplete.length} incomplete, ${failed.length} failed).`);
  } else {
    addPass(`PR #${pr.number} status checks are all green (${checks.length} checks).`);
  }

  if (pr.url) {
    addWarning(`Release external state was checked against ${pr.url}; rerun this command after every PR update.`);
  }
}

function checkEvidenceMetadata(evidence) {
  const reviewedAt = String(evidence.reviewed_at || '').trim();
  const reviewer = String(evidence.reviewer || '').trim();
  if (!/^\d{4}-\d{2}-\d{2}/.test(reviewedAt) || /TODO|CHANGE_ME|placeholder/i.test(reviewedAt)) {
    addFailure('external state evidence reviewed_at must be a real review date.');
  } else {
    addPass('external state evidence has reviewed_at.');
  }

  if (reviewer === '' || /TODO|CHANGE_ME|placeholder/i.test(reviewer)) {
    addFailure('external state evidence reviewer is missing or placeholder.');
  } else {
    addPass('external state evidence has reviewer.');
  }
}

function checkEvidenceFile(evidencePath) {
  let evidence;
  try {
    const resolvedPath = path.resolve(evidencePath);
    evidence = JSON.parse(fs.readFileSync(resolvedPath, 'utf8'));
  } catch (error) {
    addFailure(`RELEASE_EXTERNAL_STATE_FILE is not readable JSON: ${error.message}`);
    return;
  }

  checkEvidenceMetadata(evidence);

  const commands = evidence.commands || {};
  const trackedBackups = commands.git_ls_files_database_backups || {};
  if (trackedBackups.exit_code !== 0) {
    addFailure('external evidence command git ls-files database/backups did not exit 0.');
  } else {
    checkTrackedBackupsOutput(trackedBackups.stdout);
  }

  const gitStatus = commands.git_status_short_branch || {};
  if (gitStatus.exit_code !== 0) {
    addFailure('external evidence command git status --short --branch did not exit 0.');
  } else {
    checkGitStatusOutput(gitStatus.stdout);
  }

  const prView = commands.gh_pr_view || {};
  if (prView.exit_code !== 0) {
    addFailure('external evidence command gh pr view did not exit 0.');
  } else {
    checkPrObject(prView.json);
  }
}

function checkTrackedBackups() {
  const result = run('git', ['ls-files', 'database/backups']);
  if (result.status !== 0) {
    addFailure(`git ls-files database/backups failed: ${commandFailureMessage('git', ['ls-files', 'database/backups'], result)}`);
    return;
  }

  checkTrackedBackupsOutput(result.stdout);
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

  checkGitStatusOutput(result.stdout);
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

  checkPrObject(pr);
}

if (process.env.RELEASE_EXTERNAL_STATE_FILE) {
  checkEvidenceFile(process.env.RELEASE_EXTERNAL_STATE_FILE);
} else {
  checkTrackedBackups();
  checkGitWorktree();
  checkGitHubPr();
}

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
  console.error('FAIL: This runtime blocked Node child_process access to external commands. Run `npm run collect:release-external-state`, then rerun with RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json.');
}

const failureCount = failures.length + (commandExecutionUnavailable ? 1 : 0);
const resultFailures = commandExecutionUnavailable
  ? [
      ...failures,
      'This runtime blocked Node child_process access to external commands. Run `npm run collect:release-external-state`, then rerun with RELEASE_EXTERNAL_STATE_FILE=docs/release_external_state_evidence.local.json.',
    ]
  : failures;
const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-external-state',
  status: failureCount > 0 ? 'failed' : 'passed',
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failureCount,
  },
  passes,
  warnings,
  failures: resultFailures,
};

if (process.env.RELEASE_EXTERNAL_STATE_RESULT_FILE) {
  const outputPath = resolveOutputPath(process.env.RELEASE_EXTERNAL_STATE_RESULT_FILE);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

console.log(`Release external-state summary: ${passes.length} passed, ${warnings.length} warnings, ${failureCount} failures.`);

if (failureCount > 0) {
  process.exit(1);
}
