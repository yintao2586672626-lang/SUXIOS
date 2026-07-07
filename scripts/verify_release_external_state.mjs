import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {
  categorizeReleasePath,
  gitStatusCategoryOrder,
} from './lib/release_worktree_scope.mjs';

const failures = [];
const warnings = [];
const passes = [];
let commandExecutionUnavailable = false;
let resolvedReleasePrNumber = String(process.env.RELEASE_PR_NUMBER || '').trim() || null;
let resolvedReleasePrHeadSha = null;
let resolvedLocalHeadSha = null;
const repoRoot = path.resolve(process.cwd());
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const diagnostics = {
  git_status_changed_entries: [],
  git_status_changed_summary: {
    total: 0,
    by_category: {},
    by_status: {},
  },
};

function resolveOutputPath(outputPath) {
  return path.isAbsolute(outputPath) ? outputPath : path.resolve(outputPath);
}

function evidencePath(fileName) {
  return path.join(releaseEvidenceDir, fileName);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveOutputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
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

function parseGitStatusEntry(line) {
  const status = line.slice(0, 2).trim() || line.slice(0, 2);
  const rawPath = line.length > 3 ? line.slice(3).trim() : line.slice(2).trim();
  return {
    status,
    path: rawPath,
    category: categorizeReleasePath(rawPath),
  };
}

function summarizeGitStatusEntries(entries) {
  const byCategory = {};
  for (const category of gitStatusCategoryOrder) {
    const count = entries.filter((entry) => entry.category === category).length;
    if (count > 0) {
      byCategory[category] = count;
    }
  }

  const statusCounts = new Map();
  for (const entry of entries) {
    statusCounts.set(entry.status, (statusCounts.get(entry.status) || 0) + 1);
  }
  const byStatus = Object.fromEntries([...statusCounts.entries()].sort(([left], [right]) => left.localeCompare(right)));

  return {
    total: entries.length,
    by_category: byCategory,
    by_status: byStatus,
  };
}

function formatGitStatusCategorySummary(summary) {
  const parts = gitStatusCategoryOrder
    .filter((category) => Number(summary.by_category?.[category] || 0) > 0)
    .map((category) => `${category}=${summary.by_category[category]}`);

  return parts.length > 0 ? `; categories: ${parts.join(', ')}` : '';
}

function isWeakReviewer(value) {
  const text = String(value || '').trim();
  return text === ''
    || /TODO|CHANGE_ME|placeholder/i.test(text)
    || /^release-check$/i.test(text)
    || /^Codex release handoff$/i.test(text)
    || /\b(test|fixture|dummy|script|bot)\b/i.test(text);
}

function parseReviewTimestamp(value) {
  const text = String(value || '').trim();
  const timestamp = Date.parse(text);
  if (!Number.isFinite(timestamp)) {
    return null;
  }
  return timestamp;
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
  diagnostics.git_status_changed_entries = changedLines.map(parseGitStatusEntry);
  diagnostics.git_status_changed_summary = summarizeGitStatusEntries(diagnostics.git_status_changed_entries);
  if (changedLines.length > 0) {
    const categorySummary = formatGitStatusCategorySummary(diagnostics.git_status_changed_summary);
    addFailure(`local worktree is not clean (${changedLines.length} changed entries${categorySummary}). Review, commit, or intentionally isolate them before release.`);
  } else {
    addPass('local worktree is clean.');
  }
}

function checkGitHeadOutput(stdout) {
  const sha = String(stdout || '').trim();
  if (/^[a-f0-9]{40}$/i.test(sha)) {
    resolvedLocalHeadSha = sha;
    addPass(`local HEAD sha is recorded: ${sha}.`);
  } else {
    addFailure('local HEAD sha is missing or not a 40-character commit sha.');
  }
}

function checkLocalHeadMatchesPrHead() {
  if (!resolvedLocalHeadSha || !resolvedReleasePrHeadSha) {
    return;
  }

  if (resolvedLocalHeadSha.toLowerCase() !== resolvedReleasePrHeadSha.toLowerCase()) {
    addFailure(`local HEAD ${resolvedLocalHeadSha} does not match release PR head ${resolvedReleasePrHeadSha}. Check out the final release PR head or rerun external-state evidence from the matching release worktree before handoff.`);
    return;
  }

  addPass(`local HEAD matches release PR head ${resolvedReleasePrHeadSha}.`);
}

function compactEvidenceOutput(text) {
  const normalized = String(text || '').replace(/\s+/g, ' ').trim();
  if (normalized.length <= 300) {
    return normalized;
  }
  return `${normalized.slice(0, 300)}...`;
}

function commandEvidenceFailureMessage(commandName, evidence) {
  const parts = [];
  const stderr = compactEvidenceOutput(evidence?.stderr);
  const stdout = compactEvidenceOutput(evidence?.stdout);
  if (stderr) {
    parts.push(`stderr: ${stderr}`);
  }
  if (stdout) {
    parts.push(`stdout: ${stdout}`);
  }

  const detail = parts.length > 0 ? ` ${parts.join(' ')}` : '';
  return `external evidence command ${commandName} did not exit 0.${detail}`;
}

function checkGitIndexLockObject(gitIndexLock) {
  if (!gitIndexLock || typeof gitIndexLock !== 'object') {
    addFailure('external evidence is missing .git/index.lock state.');
    return;
  }

  if (gitIndexLock.exists === true) {
    const lastWriteTime = gitIndexLock.last_write_time ? ` last_write_time=${gitIndexLock.last_write_time}` : '';
    addFailure(`.git/index.lock exists in external evidence; local git index is not ready for release operations.${lastWriteTime}`);
    return;
  }

  if (gitIndexLock.exists === false) {
    addPass('.git/index.lock is absent in external evidence.');
    return;
  }

  addFailure('external evidence .git/index.lock state must be a boolean.');
}

function evidenceTargetPrNumber(evidence) {
  return String(evidence?.target_release_pr_number || '').trim();
}

function expectedPrNumber(evidence = null) {
  return String(process.env.RELEASE_PR_NUMBER || '').trim() || evidenceTargetPrNumber(evidence);
}

function checkExpectedPrNumberSource(evidence = null) {
  const envPrNumber = String(process.env.RELEASE_PR_NUMBER || '').trim();
  const targetPrNumber = evidenceTargetPrNumber(evidence);
  const candidatePrNumber = envPrNumber || targetPrNumber;

  if (!/^\d+$/.test(candidatePrNumber)) {
    addFailure('RELEASE_PR_NUMBER is required. Run npm run review:release-pr-candidates and set RELEASE_PR_NUMBER to the selected open final release PR before release external-state review.');
    return null;
  }

  if (envPrNumber && targetPrNumber && envPrNumber !== targetPrNumber) {
    addFailure(`RELEASE_PR_NUMBER=${envPrNumber} does not match external evidence target_release_pr_number=${targetPrNumber}.`);
    return null;
  }

  resolvedReleasePrNumber = candidatePrNumber;
  if (targetPrNumber) {
    addPass(`external state evidence targets release PR #${targetPrNumber}.`);
  }

  return candidatePrNumber;
}

function checkPrObject(pr, expectedNumber = expectedPrNumber()) {
  if (!/^\d+$/.test(String(expectedNumber || ''))) {
    addFailure('Release PR number is missing; external-state evidence cannot prove the configured final release PR.');
    return;
  }

  if (!pr || typeof pr !== 'object') {
    addFailure('PR evidence is missing or invalid.');
    return;
  }

  if (String(pr.number ?? '') === expectedNumber) {
    addPass(`PR #${expectedNumber} is the configured release PR.`);
  } else {
    addFailure(`PR evidence is for #${pr.number ?? 'unknown'}, expected release PR #${expectedNumber}.`);
  }

  if (/^[a-f0-9]{40}$/i.test(String(pr.headRefOid || ''))) {
    resolvedReleasePrHeadSha = String(pr.headRefOid);
    addPass(`PR head sha is recorded: ${pr.headRefOid}.`);
  } else {
    addFailure('PR headRefOid is missing or not a 40-character commit sha.');
  }

  if (pr.state === 'OPEN') {
    addPass(`PR #${pr.number} is open.`);
  } else {
    addFailure(`PR #${pr.number ?? 'unknown'} is not open; current state is ${pr.state ?? 'missing'}.`);
  }

  if (pr.isDraft === false) {
    addPass(`PR #${pr.number} is not draft.`);
  } else if (pr.isDraft === true) {
    if (pr.state && pr.state !== 'OPEN') {
      addFailure(`PR #${pr.number ?? 'unknown'} is still draft but cannot be marked ready because current state is ${pr.state}; select an open final release PR before release handoff.`);
    } else {
      addFailure(`PR #${pr.number ?? 'unknown'} is still draft; mark it ready for review before release handoff.`);
    }
  } else {
    addFailure(`PR #${pr.number ?? 'unknown'} draft state is missing from release evidence.`);
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
  const reviewedAtTime = parseReviewTimestamp(reviewedAt);
  if (reviewedAtTime === null || /TODO|CHANGE_ME|placeholder/i.test(reviewedAt)) {
    addFailure('external state evidence reviewed_at must be a real ISO timestamp.');
  } else if (Date.now() - reviewedAtTime > 24 * 60 * 60 * 1000 || reviewedAtTime > Date.now() + 5 * 60 * 1000) {
    addFailure(`external state evidence reviewed_at is outside the accepted 24-hour final handoff window: ${reviewedAt}.`);
  } else {
    addPass('external state evidence has reviewed_at.');
  }

  if (isWeakReviewer(reviewer)) {
    addFailure('external state evidence reviewer must be a real accountable reviewer, not a placeholder, test owner, or script identity.');
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
  const expectedNumber = checkExpectedPrNumberSource(evidence);

  const commands = evidence.commands || {};
  const trackedBackups = commands.git_ls_files_database_backups || {};
  if (trackedBackups.exit_code !== 0) {
    addFailure(commandEvidenceFailureMessage('git ls-files database/backups', trackedBackups));
  } else {
    checkTrackedBackupsOutput(trackedBackups.stdout);
  }

  checkGitIndexLockObject(commands.git_index_lock);

  const gitHead = commands.git_rev_parse_head || {};
  if (gitHead.exit_code !== 0) {
    addFailure(commandEvidenceFailureMessage('git rev-parse HEAD', gitHead));
  } else {
    checkGitHeadOutput(gitHead.stdout);
  }

  const gitStatus = commands.git_status_short_branch || {};
  if (gitStatus.exit_code !== 0) {
    addFailure(commandEvidenceFailureMessage('git status --short --branch', gitStatus));
  } else {
    checkGitStatusOutput(gitStatus.stdout);
  }

  const prView = commands.gh_pr_view || {};
  if (prView.exit_code !== 0) {
    addFailure(commandEvidenceFailureMessage('gh pr view', prView));
  } else {
    checkPrObject(prView.json, expectedNumber);
    checkLocalHeadMatchesPrHead();
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

function checkGitHead() {
  const result = run('git', ['rev-parse', 'HEAD']);
  if (result.status !== 0) {
    addFailure(`git rev-parse HEAD failed: ${commandFailureMessage('git', ['rev-parse', 'HEAD'], result)}`);
    return;
  }

  checkGitHeadOutput(result.stdout);
}

function checkGitHubPr() {
  const prNumber = checkExpectedPrNumberSource();
  if (!prNumber) {
    return;
  }
  const result = run('gh', [
    'pr',
    'view',
    prNumber,
    '--json',
    'number,url,state,isDraft,headRefOid,mergeable,statusCheckRollup',
  ]);

  if (result.status !== 0) {
    addFailure(`gh pr view ${prNumber} failed: ${commandFailureMessage('gh', [
      'pr',
      'view',
      prNumber,
      '--json',
      'number,url,state,isDraft,headRefOid,mergeable,statusCheckRollup',
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

  checkPrObject(pr, prNumber);
  checkLocalHeadMatchesPrHead();
}

if (process.env.RELEASE_EXTERNAL_STATE_FILE) {
  checkEvidenceFile(process.env.RELEASE_EXTERNAL_STATE_FILE);
} else {
  checkTrackedBackups();
  checkGitWorktree();
  checkGitHead();
  checkGitHubPr();
}

const resultOutputPath = process.env.RELEASE_EXTERNAL_STATE_RESULT_FILE
  ? resolveOutputPath(process.env.RELEASE_EXTERNAL_STATE_RESULT_FILE)
  : evidencePath('release-external-state-result.json');
if (resultOutputPath && isPathInsideRepo(resultOutputPath)) {
  addFailure(`Release external-state result output must be stored outside the repository in a controlled evidence directory: ${resultOutputPath}.`);
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
  expected_release_pr_number: resolvedReleasePrNumber,
  expected_local_head_sha: resolvedLocalHeadSha,
  expected_release_pr_head_sha: resolvedReleasePrHeadSha,
  status: failureCount > 0 ? 'failed' : 'passed',
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failureCount,
  },
  passes,
  warnings,
  failures: resultFailures,
  diagnostics,
};

if (resultOutputPath && !isPathInsideRepo(resultOutputPath)) {
  fs.mkdirSync(path.dirname(resultOutputPath), { recursive: true });
  fs.writeFileSync(resultOutputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

console.log(`Release external-state summary: ${passes.length} passed, ${warnings.length} warnings, ${failureCount} failures.`);

if (failureCount > 0) {
  process.exit(1);
}
