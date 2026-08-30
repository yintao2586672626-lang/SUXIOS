import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';

import {
  buildEvidenceStatusReport,
  collectCurrentEvidenceStatus,
  evidenceStatusVersion,
  parseEvidenceStatusArgs,
  repoRoot,
  validateEvidenceStatusReport,
} from '../../scripts/report_suxi_skill_evidence_status.mjs';

const currentExecutionHead = {
  attempts: 1,
  latest: 'ed058dbdc0f80a11c9e9131a4743991f4e488cb479e0b11a49d679e306df4900',
};

function behaviorFixture({ fail = 0, blocked = 0, status = 'PASS' } = {}) {
  return {
    status,
    ledger_sha256: '1'.repeat(64),
    verified_counts: status === 'PASS'
      ? {
        skills: 3,
        cases: 18,
        pass: 18 - fail - blocked,
        fail,
        blocked,
        assertions: 82,
        evidence_spans: 116,
      }
      : null,
    skill_results: [
      'suxi-product-decision',
      'suxi-test-guard',
      'suxi-user-research',
    ].map((skillName, index) => ({
      skill_name: skillName,
      status,
      grade_status: index === 0 && fail
        ? 'FAIL'
        : index === 0 && blocked ? 'BLOCKED' : 'PASS',
      failures: [],
    })),
  };
}

function archiveFixture({ content = 'PASS', identity = 'MATCH', reproducibility = 'PASS' } = {}) {
  return {
    status: content,
    content_status: content,
    archive_seal_status: content === 'PASS' ? 'SEALED' : 'FAIL',
    verifier_identity_status: identity,
    reproducibility_status: reproducibility,
    archive_manifest_sha256: '2'.repeat(64),
    source_ledger_sha256: '1'.repeat(64),
    archive_seal_sha256: '4'.repeat(64),
    verifier_receipt_sha256: '5'.repeat(64),
    current_verifier_profile_sha256: '6'.repeat(64),
    bound_verifier_profile_sha256: '6'.repeat(64),
    reproducibility_verified_counts: content === 'PASS' && identity === 'MATCH' && reproducibility === 'PASS'
      ? { runs: 3, files: 155, bytes: 503173, seals: 6 }
      : null,
    archive_failures: [],
    verifier_identity_failures: [],
  };
}

function executionFixture({
  receipt = 'PASS',
  head = 'MATCH',
  execution = 'PASS',
} = {}) {
  return {
    receipt_status: receipt,
    chain_status: receipt === 'FAIL' ? 'FAIL' : 'PASS',
    head_anchor_status: head,
    test_execution_status: execution,
    attempts: 1,
    latest_result_sha256: currentExecutionHead.latest,
    verified_test_count: receipt === 'PASS' && head === 'MATCH' && execution === 'PASS' ? 43 : null,
    execution_profile_sha256: '7'.repeat(64),
    archive_manifest_sha256: '2'.repeat(64),
    verifier_receipt_sha256: '5'.repeat(64),
    verifier_profile_sha256: '6'.repeat(64),
    incomplete_attempt: null,
    failures: [],
  };
}

function recoveryFixture({ attention = false } = {}) {
  return attention
    ? {
      status: 'ATTENTION',
      recoveries: [{ state: 'RECOVERABLE_INCOMPLETE_ATTEMPT' }],
    }
    : { status: 'NO_LOCKS', recoveries: [] };
}

function report(overrides = {}) {
  return buildEvidenceStatusReport({
    behaviorResult: overrides.behavior || behaviorFixture(),
    archiveResult: overrides.archive || archiveFixture(),
    executionResult: overrides.execution || executionFixture(),
    recoveryResult: overrides.recovery || recoveryFixture(),
  });
}

test('all four verified layers produce one PASS status and one stop action', () => {
  const result = report();
  assert.equal(result.schema_version, evidenceStatusVersion);
  assert.equal(result.status, 'PASS');
  assert.equal(result.next_action, 'stop');
  assert.deepEqual(result.verified_counts, {
    skills: 3,
    cases: 18,
    assertions: 82,
    evidence_spans: 116,
    archive_runs: 3,
    archive_files: 155,
    archive_bytes: 503173,
    archive_seals: 6,
    executed_tests: 43,
    execution_attempts: 1,
  });
  assert.equal(validateEvidenceStatusReport(result), result);
});

test('FAIL, BLOCKED, and NOT_RUN precedence prevents green propagation and verified counts', () => {
  const behaviorBlocked = report({ behavior: behaviorFixture({ blocked: 1 }) });
  assert.equal(behaviorBlocked.status, 'BLOCKED');
  assert.equal(behaviorBlocked.verified_counts, null);
  assert.equal(behaviorBlocked.next_action, 'repair_or_rerun_skill_behavior_evidence');

  const recoveryBlocked = report({ recovery: recoveryFixture({ attention: true }) });
  assert.equal(recoveryBlocked.status, 'BLOCKED');
  assert.equal(recoveryBlocked.next_action, 'inspect_or_recover_execution_lock_without_deleting_history');

  const executionNotRun = report({
    execution: executionFixture({ receipt: 'UNANCHORED', head: 'UNANCHORED', execution: 'PASS' }),
  });
  assert.equal(executionNotRun.status, 'NOT_RUN');
  assert.equal(executionNotRun.next_action, 'supply_and_verify_external_execution_head');

  const archiveFailed = report({
    behavior: behaviorFixture({ blocked: 1 }),
    archive: archiveFixture({ content: 'FAIL', identity: 'FAIL', reproducibility: 'FAIL' }),
    execution: executionFixture({ receipt: 'UNANCHORED', head: 'UNANCHORED' }),
    recovery: recoveryFixture({ attention: true }),
  });
  assert.equal(archiveFailed.status, 'FAIL');
  assert.equal(archiveFailed.verified_counts, null);
  assert.equal(archiveFailed.next_action, 'repair_archive_or_verifier_identity_chain');
});

test('flaky or interrupted fixed tests remain BLOCKED instead of PASS', () => {
  for (const status of ['FLAKY', 'TIMEOUT', 'OUTPUT_LIMIT', 'SIGNALLED']) {
    const result = report({ execution: executionFixture({ execution: status }) });
    assert.equal(result.status, 'BLOCKED', status);
    assert.equal(result.verified_counts, null);
    assert.equal(result.layers.test_execution.status, 'BLOCKED');
  }
});

test('contradictory PASS claims and cross-layer identity mismatches are recomputed as FAIL', () => {
  const gradeContradiction = behaviorFixture();
  gradeContradiction.skill_results[0].grade_status = 'FAIL';
  assert.equal(report({ behavior: gradeContradiction }).status, 'FAIL');

  const countContradiction = behaviorFixture();
  countContradiction.verified_counts.pass = 0;
  assert.equal(report({ behavior: countContradiction }).status, 'FAIL');

  const skillCountContradiction = behaviorFixture();
  skillCountContradiction.verified_counts.skills = 2;
  assert.equal(report({ behavior: skillCountContradiction }).status, 'FAIL');

  const sealContradiction = archiveFixture();
  sealContradiction.archive_seal_status = 'FAIL';
  assert.equal(report({ archive: sealContradiction }).status, 'FAIL');

  const emptyArchiveCounts = archiveFixture();
  emptyArchiveCounts.reproducibility_verified_counts = {
    runs: 0,
    files: 0,
    bytes: 0,
    seals: 0,
  };
  assert.equal(report({ archive: emptyArchiveCounts }).status, 'FAIL');

  const archivePriority = archiveFixture({ identity: 'UNBOUND', reproducibility: 'FAIL' });
  assert.equal(report({ archive: archivePriority }).status, 'FAIL');

  const brokenUnanchored = executionFixture({
    receipt: 'FAIL',
    head: 'UNANCHORED',
    execution: 'ERROR',
  });
  brokenUnanchored.chain_status = 'FAIL';
  brokenUnanchored.failures = ['corrupt_chain'];
  const brokenReport = report({ execution: brokenUnanchored });
  assert.equal(brokenReport.status, 'FAIL');
  assert.equal(brokenReport.next_action, 'inspect_fixed_test_execution_receipts');

  const missingCount = executionFixture();
  missingCount.verified_test_count = null;
  assert.equal(report({ execution: missingCount }).status, 'FAIL');

  const incomplete = executionFixture();
  incomplete.incomplete_attempt = { attempt_number: 2 };
  assert.equal(report({ execution: incomplete }).status, 'FAIL');

  const falseNoLocks = { status: 'NO_LOCKS', recoveries: [{ state: 'ACTIVE' }] };
  assert.equal(report({ recovery: falseNoLocks }).status, 'FAIL');

  const mismatchedExecution = executionFixture();
  mismatchedExecution.archive_manifest_sha256 = 'f'.repeat(64);
  const identityMismatch = report({ execution: mismatchedExecution });
  assert.equal(identityMismatch.status, 'FAIL');
  assert.equal(identityMismatch.identity_consistency.status, 'FAIL');
  assert.equal(identityMismatch.next_action, 'repair_cross_layer_evidence_identity');

  const mutatedReport = report();
  mutatedReport.layers.behavior.status = 'FAIL';
  assert.throws(
    () => validateEvidenceStatusReport(mutatedReport),
    /behavior layer status disagrees/u,
  );
});

test('execution head arguments are strict and optional omission stays unanchored', () => {
  assert.deepEqual(parseEvidenceStatusArgs([]), {
    help: false,
    expectedExecutionAttempts: null,
    expectedExecutionLatestResultSha256: null,
  });
  assert.deepEqual(parseEvidenceStatusArgs([
    '--expected-execution-attempts=1',
    `--expected-execution-latest-result-sha256=${currentExecutionHead.latest}`,
  ]), {
    help: false,
    expectedExecutionAttempts: 1,
    expectedExecutionLatestResultSha256: currentExecutionHead.latest,
  });
  assert.throws(
    () => parseEvidenceStatusArgs(['--expected-execution-attempts=1']),
    /supplied together/u,
  );
  assert.throws(
    () => parseEvidenceStatusArgs([
      '--expected-execution-attempts=0',
      `--expected-execution-latest-result-sha256=${currentExecutionHead.latest}`,
    ]),
    /zero attempts require latest=none/u,
  );
});

test('current authoritative state is PASS only with the external execution head', () => {
  const unanchored = collectCurrentEvidenceStatus();
  assert.equal(unanchored.status, 'NOT_RUN');
  assert.equal(unanchored.layers.test_execution.status, 'NOT_RUN');
  assert.equal(unanchored.verified_counts, null);

  const anchored = collectCurrentEvidenceStatus({
    expectedExecutionAttempts: currentExecutionHead.attempts,
    expectedExecutionLatestResultSha256: currentExecutionHead.latest,
  });
  assert.equal(anchored.status, 'PASS');
  assert.equal(anchored.layers.behavior.status, 'PASS');
  assert.equal(anchored.layers.archive_verifier.status, 'PASS');
  assert.equal(anchored.layers.test_execution.status, 'PASS');
  assert.equal(anchored.layers.recovery.status, 'PASS');
  assert.equal(anchored.verified_counts.executed_tests, 43);
});

test('one verifier exception becomes a visible FAIL layer without hiding the other layers', () => {
  const result = collectCurrentEvidenceStatus({
    expectedExecutionAttempts: currentExecutionHead.attempts,
    expectedExecutionLatestResultSha256: currentExecutionHead.latest,
    providers: {
      behavior: () => behaviorFixture(),
      archive: () => {
        throw new Error('synthetic path-bearing failure must not escape');
      },
      execution: () => executionFixture(),
      recovery: () => recoveryFixture(),
    },
  });
  assert.equal(result.status, 'FAIL');
  assert.equal(result.layers.behavior.status, 'PASS');
  assert.equal(result.layers.archive_verifier.status, 'FAIL');
  assert.equal(result.layers.test_execution.status, 'PASS');
  assert.equal(result.layers.recovery.status, 'PASS');
  assert.equal(result.layers.archive_verifier.failure_count, 1);
  assert.equal(JSON.stringify(result).includes('synthetic path-bearing failure'), false);
});

test('status reporter is read-only and contains no child-process or filesystem write capability', () => {
  const source = readFileSync(
    path.join(repoRoot, 'scripts', 'report_suxi_skill_evidence_status.mjs'),
    'utf8',
  );
  assert.doesNotMatch(source, /node:child_process|spawnSync|execFile|writeFile|rmSync|unlinkSync/u);
  assert.match(source, /verifyBehaviorSuite/u);
  assert.match(source, /verifyEvidenceArchive/u);
  assert.match(source, /verifyCurrentTestExecution/u);
  assert.match(source, /inspectCurrentExecutionRecoveries/u);
  const packageJson = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
  assert.equal(
    packageJson.scripts['report:skill-evidence-status'],
    'node scripts/report_suxi_skill_evidence_status.mjs',
  );
});

test('status reporter CLI exit codes distinguish PASS, FAIL, and missing external head', () => {
  const reporterPath = path.join(repoRoot, 'scripts', 'report_suxi_skill_evidence_status.mjs');
  const run = args => spawnSync(process.execPath, [reporterPath, ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  const noHead = run([]);
  assert.equal(noHead.status, 2, noHead.stderr);
  assert.equal(JSON.parse(noHead.stdout).status, 'NOT_RUN');

  const badHead = run([
    '--expected-execution-attempts=1',
    `--expected-execution-latest-result-sha256=${'0'.repeat(64)}`,
  ]);
  assert.equal(badHead.status, 1, badHead.stderr);
  assert.equal(JSON.parse(badHead.stdout).status, 'FAIL');

  const goodHead = run([
    '--expected-execution-attempts=1',
    `--expected-execution-latest-result-sha256=${currentExecutionHead.latest}`,
  ]);
  assert.equal(goodHead.status, 0, goodHead.stderr);
  assert.equal(JSON.parse(goodHead.stdout).status, 'PASS');
});
