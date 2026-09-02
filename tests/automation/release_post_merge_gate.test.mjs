import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import { verifyReleasePostMerge } from '../../scripts/verify_release_post_merge.mjs';

const MAIN_SHA = 'a'.repeat(40);
const PR_HEAD_SHA = 'b'.repeat(40);
const OTHER_SHA = 'c'.repeat(40);
const TREE_ONE = 'd'.repeat(40);
const TREE_TWO = 'e'.repeat(40);
const NOW = new Date('2026-08-30T12:00:00.000Z');
const REPOSITORY = 'suxios/example';
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

function commandKey(command, args) {
  return JSON.stringify([command, ...args]);
}

function ok(stdout = '') {
  return { status: 0, stdout, stderr: '' };
}

function result(status, stdout = '', stderr = '') {
  return { status, stdout, stderr };
}

function json(value) {
  return JSON.stringify(value);
}

function baseCommands() {
  return new Map([
    [commandKey('git', ['status', '--porcelain=v1', '--untracked-files=all']), ok('')],
    [commandKey('git', ['rev-parse', '--verify', 'HEAD^{commit}']), ok(MAIN_SHA)],
    [commandKey('git', ['remote', 'get-url', 'origin']), ok(`https://github.com/${REPOSITORY}.git`)],
    [commandKey('gh', ['repo', 'view', '--json', 'nameWithOwner']), ok(json({ nameWithOwner: REPOSITORY }))],
    [commandKey('git', ['ls-remote', '--heads', 'origin', 'refs/heads/main']), ok(`${MAIN_SHA}\trefs/heads/main\n`)],
    [commandKey('gh', [
      'pr', 'view', '41',
      '--json', 'number,state,baseRefName,headRefOid,mergeCommit,url',
    ]), ok(json({
      number: 41,
      state: 'MERGED',
      baseRefName: 'main',
      headRefOid: PR_HEAD_SHA,
      mergeCommit: { oid: MAIN_SHA },
      url: 'https://github.com/suxios/example/pull/41',
    }))],
    [commandKey('git', ['merge-base', '--is-ancestor', PR_HEAD_SHA, MAIN_SHA]), ok('')],
    [commandKey('gh', [
      'api',
      '-H', 'Accept: application/vnd.github+json',
      `repos/${REPOSITORY}/commits/${MAIN_SHA}/check-runs?per_page=100`,
    ]), ok(json({
      total_count: 2,
      check_runs: [
        { name: 'verify', status: 'completed', conclusion: 'success' },
        { name: 'Branch freshness', status: 'completed', conclusion: 'success' },
      ],
    }))],
    [commandKey('gh', [
      'api',
      '-H', 'Accept: application/vnd.github+json',
      `repos/${REPOSITORY}/branches/main/protection/required_status_checks`,
    ]), ok(json({
      contexts: ['verify', 'Branch freshness'],
      checks: [
        { context: 'verify', app_id: 1 },
        { context: 'Branch freshness', app_id: 1 },
      ],
    }))],
  ]);
}

function buildExecutor(overrides = []) {
  const commands = baseCommands();
  for (const [command, args, executionResult] of overrides) {
    commands.set(commandKey(command, args), executionResult);
  }
  return (command, args) => {
    const executionResult = commands.get(commandKey(command, args));
    assert.ok(executionResult, `unexpected command: ${command} ${args.join(' ')}`);
    return executionResult;
  };
}

function validDeploymentEvidence() {
  return {
    status: 'deployed',
    deployed_source_sha: MAIN_SHA,
    deployment_sha: MAIN_SHA,
    health: {
      status: 'ok',
      production_runtime_ready: true,
    },
    runtime_asset_identity: {
      match: true,
    },
    checked_at: '2026-08-30T11:30:00.000Z',
  };
}

function createFixture(t, evidence = validDeploymentEvidence(), { evidenceInsideRepo = false } = {}) {
  const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-post-merge-gate-'));
  const repoRoot = path.join(fixtureRoot, 'repo');
  fs.mkdirSync(repoRoot, { recursive: true });
  const evidenceFile = evidenceInsideRepo
    ? path.join(repoRoot, 'deployment-evidence.json')
    : path.join(fixtureRoot, 'deployment-evidence.json');
  fs.writeFileSync(evidenceFile, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
  t.after(() => fs.rmSync(fixtureRoot, { recursive: true, force: true }));
  return { repoRoot, evidenceFile };
}

function runGate(t, {
  evidence = validDeploymentEvidence(),
  evidenceInsideRepo = false,
  overrides = [],
} = {}) {
  const fixture = createFixture(t, evidence, { evidenceInsideRepo });
  return verifyReleasePostMerge({
    repoRoot: fixture.repoRoot,
    env: {
      RELEASE_PR_NUMBER: '41',
      RELEASE_POST_MERGE_DEPLOYMENT_EVIDENCE_FILE: fixture.evidenceFile,
    },
    now: NOW,
    executor: buildExecutor(overrides),
    writeResult: false,
  });
}

function assertBlocked(gateResult, reason) {
  assert.equal(gateResult.ready, false);
  assert.equal(gateResult.status, 'blocked');
  assert.equal(gateResult.reason, reason);
}

test('post-merge release gate passes with exact main, merged PR, green required CI, and fresh deployment evidence', (t) => {
  const gateResult = runGate(t);

  assert.equal(gateResult.ready, true);
  assert.equal(gateResult.status, 'ready');
  assert.equal(gateResult.data.main_sha, MAIN_SHA);
  assert.equal(gateResult.data.release_pr.head_sha, PR_HEAD_SHA);
  assert.equal(gateResult.data.release_pr.relationship_to_main, 'ancestor');
  assert.deepEqual(gateResult.data.github_checks.required_contexts, ['verify', 'Branch freshness']);
});

test('post-merge release gate rejects a dirty local checkout', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'git',
      ['status', '--porcelain=v1', '--untracked-files=all'],
      ok(' M app/example.php'),
    ]],
  });

  assertBlocked(gateResult, 'worktree_not_clean');
});

test('post-merge release gate rejects a remote main SHA mismatch', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'git',
      ['ls-remote', '--heads', 'origin', 'refs/heads/main'],
      ok(`${OTHER_SHA}\trefs/heads/main\n`),
    ]],
  });

  assertBlocked(gateResult, 'remote_main_mismatch');
});

test('post-merge release gate rejects a PR that is not merged', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'gh',
      ['pr', 'view', '41', '--json', 'number,state,baseRefName,headRefOid,mergeCommit,url'],
      ok(json({
        number: 41,
        state: 'OPEN',
        baseRefName: 'main',
        headRefOid: PR_HEAD_SHA,
        mergeCommit: null,
        url: 'https://github.com/suxios/example/pull/41',
      })),
    ]],
  });

  assertBlocked(gateResult, 'release_pr_not_merged');
});

test('post-merge release gate rejects main after it advanced beyond the reviewed merge commit', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'gh',
      ['pr', 'view', '41', '--json', 'number,state,baseRefName,headRefOid,mergeCommit,url'],
      ok(json({
        number: 41,
        state: 'MERGED',
        baseRefName: 'main',
        headRefOid: PR_HEAD_SHA,
        mergeCommit: { oid: OTHER_SHA },
        url: 'https://github.com/suxios/example/pull/41',
      })),
    ]],
  });

  assertBlocked(gateResult, 'merged_pr_not_main_tip');
});

test('post-merge release gate rejects a PR head with no ancestor or equal-tree relationship', (t) => {
  const gateResult = runGate(t, {
    overrides: [
      ['git', ['merge-base', '--is-ancestor', PR_HEAD_SHA, MAIN_SHA], result(1)],
      ['git', ['rev-parse', '--verify', `${PR_HEAD_SHA}^{tree}`], ok(TREE_ONE)],
      ['git', ['rev-parse', '--verify', `${MAIN_SHA}^{tree}`], ok(TREE_TWO)],
    ],
  });

  assertBlocked(gateResult, 'pr_head_not_in_main');
});

test('post-merge release gate accepts a squash merge with a tree equal to the PR head', (t) => {
  const gateResult = runGate(t, {
    overrides: [
      ['git', ['merge-base', '--is-ancestor', PR_HEAD_SHA, MAIN_SHA], result(1)],
      ['git', ['rev-parse', '--verify', `${PR_HEAD_SHA}^{tree}`], ok(TREE_ONE)],
      ['git', ['rev-parse', '--verify', `${MAIN_SHA}^{tree}`], ok(TREE_ONE)],
    ],
  });

  assert.equal(gateResult.ready, true);
  assert.equal(gateResult.data.release_pr.relationship_to_main, 'tree_equal');
});

test('post-merge release gate rejects a missing required CI context', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'gh',
      [
        'api',
        '-H', 'Accept: application/vnd.github+json',
        `repos/${REPOSITORY}/commits/${MAIN_SHA}/check-runs?per_page=100`,
      ],
      ok(json({
        total_count: 1,
        check_runs: [{ name: 'verify', status: 'completed', conclusion: 'success' }],
      })),
    ]],
  });

  assertBlocked(gateResult, 'required_contexts_not_successful');
});

test('post-merge release gate rejects an unsuccessful required CI run', (t) => {
  const gateResult = runGate(t, {
    overrides: [[
      'gh',
      [
        'api',
        '-H', 'Accept: application/vnd.github+json',
        `repos/${REPOSITORY}/commits/${MAIN_SHA}/check-runs?per_page=100`,
      ],
      ok(json({
        total_count: 2,
        check_runs: [
          { name: 'verify', status: 'completed', conclusion: 'success' },
          { name: 'Branch freshness', status: 'completed', conclusion: 'failure' },
        ],
      })),
    ]],
  });

  assertBlocked(gateResult, 'check_runs_not_successful');
});

test('post-merge release gate rejects a deployment SHA mismatch', (t) => {
  const evidence = validDeploymentEvidence();
  evidence.deployment_sha = OTHER_SHA;
  const gateResult = runGate(t, { evidence });

  assertBlocked(gateResult, 'deployment_sha_mismatch');
});

test('post-merge release gate rejects staged evidence that was not deployed', (t) => {
  const evidence = validDeploymentEvidence();
  evidence.status = 'staged';
  const gateResult = runGate(t, { evidence });

  assertBlocked(gateResult, 'deployment_status_not_deployed');
});

test('post-merge release gate rejects deployment evidence older than 24 hours', (t) => {
  const evidence = validDeploymentEvidence();
  evidence.checked_at = '2026-08-29T11:59:59.000Z';
  const gateResult = runGate(t, { evidence });

  assertBlocked(gateResult, 'deployment_evidence_stale');
});

test('post-merge release gate rejects deployment evidence stored inside the repository', (t) => {
  const gateResult = runGate(t, { evidenceInsideRepo: true });

  assertBlocked(gateResult, 'deployment_evidence_inside_repo');
});

test('post-merge result path inside the repository is rejected before directories are created', (t) => {
  const fixture = createFixture(t);
  const resultDirectory = path.join(fixture.repoRoot, 'generated-release-result');
  const gateResult = verifyReleasePostMerge({
    repoRoot: fixture.repoRoot,
    env: {
      RELEASE_PR_NUMBER: '41',
      RELEASE_POST_MERGE_DEPLOYMENT_EVIDENCE_FILE: fixture.evidenceFile,
      RELEASE_POST_MERGE_RESULT_FILE: path.join(resultDirectory, 'result.json'),
    },
    now: NOW,
    executor: buildExecutor(),
  });

  assertBlocked(gateResult, 'result_file_inside_repo');
  assert.equal(fs.existsSync(resultDirectory), false);
});

test('package.json exposes the post-merge release review command', () => {
  const packageJson = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
  assert.equal(
    packageJson.scripts['review:release-post-merge'],
    'node scripts/verify_release_post_merge.mjs'
  );
});
