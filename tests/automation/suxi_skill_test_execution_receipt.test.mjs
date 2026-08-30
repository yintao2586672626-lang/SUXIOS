import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  aggregateExecutionStatuses,
  buildExecutionWorkspaceManifest,
  buildFullExecutionWorkspaceManifest,
  buildSanitizedEnvironment,
  deriveExecutionTerminalStatus,
  executionEnvironmentPolicyId,
  executionLockVersion,
  executionOutputLimitBytes,
  executionProfileVersion,
  executionResultVersion,
  executionStartedVersion,
  executionTimeoutMs,
  expectedTapSummary,
  fixedSnapshotItems,
  fixedTestArgs,
  parseExecutionArgs,
  parseFixedTapSummary,
  probeProcessLiveness,
  inspectExecutionRecoveryCatalog,
  inspectExecutionRecovery,
  recoverExecutionSeries,
  repoRoot,
  validateExecutionProfile,
  validateExecutionLock,
  validateExecutionResultReceipt,
  validateExecutionStartedReceipt,
  validateExecutionWorkspaceManifest,
  verifyExecutionReceiptDirectory,
} from '../../scripts/suxi_skill_test_execution.mjs';

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function jsonText(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function writeJson(filePath, document) {
  mkdirSync(path.dirname(filePath), { recursive: true });
  writeFileSync(filePath, jsonText(document), 'utf8');
}

function tapFixture({ notOk = false, duplicateSummary = false } = {}) {
  const lines = ['TAP version 13'];
  for (let index = 1; index <= expectedTapSummary.tests; index += 1) {
    lines.push(`${notOk && index === 1 ? 'not ok' : 'ok'} ${index} - synthetic case ${index}`);
  }
  lines.push(`1..${expectedTapSummary.tests}`);
  for (const [field, value] of Object.entries(expectedTapSummary)) {
    lines.push(`# ${field} ${value}`);
    if (duplicateSummary && field === 'tests') lines.push(`# ${field} ${value}`);
  }
  lines.push('# duration_ms 1.25', '');
  return Buffer.from(lines.join('\n'), 'utf8');
}

function targetFixture() {
  return {
    archive_path_sha256: '1'.repeat(64),
    archive_manifest_sha256: '2'.repeat(64),
    source_ledger_sha256: '3'.repeat(64),
    archive_seal_sha256: '4'.repeat(64),
    verifier_receipt_sha256: '5'.repeat(64),
    verifier_profile_sha256: '6'.repeat(64),
    verified_counts: { runs: 3, files: 155, bytes: 503173, seals: 6 },
  };
}

function profileFixture() {
  return validateExecutionProfile({
    schema_version: executionProfileVersion,
    wrapper: {
      path: 'scripts/suxi_skill_test_execution.mjs',
      sha256: '7'.repeat(64),
      bytes: 100,
    },
    wrapper_contract_test: {
      path: 'tests/automation/suxi_skill_test_execution_receipt.test.mjs',
      sha256: '8'.repeat(64),
      bytes: 100,
    },
    node_executable: {
      realpath_sha256: '9'.repeat(64),
      sha256: 'a'.repeat(64),
      bytes: 100,
    },
    runtime: {
      node_version: 'v24.18.1',
      v8_version: '13.6-test',
      platform: 'win32',
      arch: 'x64',
    },
    command_argv: [...fixedTestArgs],
    command_argv_sha256: sha256(jsonText(fixedTestArgs)),
    cwd_policy_id: 'suxi.private_frozen_workspace.v1',
    shell: false,
    environment_policy_id: executionEnvironmentPolicyId,
    environment_sha256: 'b'.repeat(64),
    timeout_ms: executionTimeoutMs,
    output_limit_bytes: executionOutputLimitBytes,
    workspace_manifest_sha256: 'c'.repeat(64),
  });
}

const firstExecutionId = '00000000-0000-4000-8000-000000000001';
const secondExecutionId = '00000000-0000-4000-8000-000000000002';

function startedFixture({
  attemptNumber = 1,
  executionId = firstExecutionId,
  previous = null,
  startedAt = '2026-08-30T00:00:00.000Z',
} = {}) {
  const profile = profileFixture();
  return validateExecutionStartedReceipt({
    schema_version: executionStartedVersion,
    execution_id: executionId,
    attempt_number: attemptNumber,
    previous_attempt_receipt_sha256: previous,
    started_at: startedAt,
    target: targetFixture(),
    execution_profile: profile,
    execution_profile_sha256: sha256(jsonText(profile)),
    workspace_manifest_sha256: profile.workspace_manifest_sha256,
    workspace_path_sha256: 'd'.repeat(64),
    preflight_snapshot_sha256: 'e'.repeat(64),
  });
}

function resultFixture(started, startedSha256, {
  status = 'PASS',
  previous = started.previous_attempt_receipt_sha256,
} = {}) {
  const pass = status === 'PASS';
  return validateExecutionResultReceipt({
    schema_version: executionResultVersion,
    execution_id: started.execution_id,
    attempt_number: started.attempt_number,
    started_receipt_sha256: startedSha256,
    previous_attempt_receipt_sha256: previous,
    started_at: started.started_at,
    ended_at: '2026-08-30T00:00:01.000Z',
    duration_ms: 1000,
    target: started.target,
    execution_profile_sha256: started.execution_profile_sha256,
    workspace_manifest_sha256: started.workspace_manifest_sha256,
    workspace_path_sha256: started.workspace_path_sha256,
    preflight_snapshot_sha256: started.preflight_snapshot_sha256,
    postflight_snapshot_sha256: started.preflight_snapshot_sha256,
    artifact_stability_status: 'PASS',
    result: {
      status,
      failure_code: pass ? 'none' : 'test_exit_nonzero',
      exit_code: pass ? 0 : 1,
      signal: null,
      timeout_triggered: false,
      output_limit_triggered: false,
      kill_method: 'none',
      process_tree_kill_confirmed: true,
      child_pid: 123,
      stdout: { sha256: 'f'.repeat(64), bytes: 100, complete: true },
      stderr: { sha256: sha256(Buffer.alloc(0)), bytes: 0, complete: true },
      tap_summary: pass ? { ...expectedTapSummary, duration_ms: 1.25 } : null,
    },
  });
}

function lockFixture({
  executionId,
  expectedAttempts,
  expectedLatestResultSha256,
  profile = profileFixture(),
  target = targetFixture(),
  ownerPid = 123,
  startedAt = '2026-08-30T00:00:02.000Z',
} = {}) {
  return validateExecutionLock({
    schema_version: executionLockVersion,
    execution_id: executionId,
    started_at: startedAt,
    owner_pid: ownerPid,
    wrapper_sha256: profile.wrapper.sha256,
    expected_previous_attempts: expectedAttempts,
    expected_previous_latest_result_sha256: expectedLatestResultSha256,
    target,
    execution_profile: profile,
    execution_profile_sha256: sha256(jsonText(profile)),
    workspace_manifest_sha256: profile.workspace_manifest_sha256,
    workspace_path_sha256: 'd'.repeat(64),
    preflight_snapshot_sha256: 'e'.repeat(64),
  });
}

test('fixed execution command and environment reject caller-controlled injection', () => {
  assert.deepEqual(fixedTestArgs, [
    '--test',
    '--test-reporter=tap',
    'tests/automation/suxi_skill_behavior_eval.test.mjs',
    'tests/automation/suxi_skill_contracts.test.mjs',
  ]);
  const parsed = parseExecutionArgs([
    'run',
    `--expected-wrapper-sha256=${'1'.repeat(64)}`,
    `--expected-wrapper-test-sha256=${'2'.repeat(64)}`,
    `--expected-archive-seal-sha256=${'3'.repeat(64)}`,
    `--expected-verifier-receipt-sha256=${'4'.repeat(64)}`,
    `--expected-verifier-profile-sha256=${'5'.repeat(64)}`,
    `--expected-node-executable-sha256=${'6'.repeat(64)}`,
    `--expected-node-executable-realpath-sha256=${'7'.repeat(64)}`,
    `--expected-input-manifest-sha256=${'8'.repeat(64)}`,
    `--expected-execution-profile-sha256=${'9'.repeat(64)}`,
    '--expected-previous-attempts=0',
    '--expected-previous-latest-result-sha256=none',
  ]);
  assert.equal(parsed.command, 'run');
  assert.equal(parsed.expectedPreviousAttempts, 0);
  assert.equal(parsed.expectedPreviousLatestResultSha256, null);
  const recovered = parseExecutionArgs([
    'recover',
    '--series-id=synthetic-series',
    `--expected-lock-sha256=${'a'.repeat(64)}`,
    `--expected-recovery-wrapper-sha256=${'b'.repeat(64)}`,
    '--expected-previous-attempts=0',
    '--expected-previous-latest-result-sha256=none',
  ]);
  assert.equal(recovered.command, 'recover');
  assert.equal(recovered.seriesId, 'synthetic-series');
  assert.throws(() => parseExecutionArgs(['run', '--test=other.test.mjs']), /Unknown argument/u);
  assert.throws(() => parseExecutionArgs(['verify', `--expected-wrapper-sha256=${'1'.repeat(64)}`]), /does not accept/u);

  const built = buildSanitizedEnvironment('C:\\private-runtime', {
    SystemRoot: 'C:\\Windows',
    NODE_OPTIONS: '--require malicious.js',
    NODE_PATH: 'malicious-modules',
    API_KEY: 'secret',
    PATH: 'sensitive-path',
  });
  assert.equal(built.environment.SystemRoot, 'C:\\Windows');
  for (const forbidden of ['NODE_OPTIONS', 'NODE_PATH', 'API_KEY', 'PATH']) {
    assert.equal(Object.hasOwn(built.environment, forbidden), false);
  }
  assert.equal(built.environment.TZ, 'UTC');
  assert.equal(built.environment.CI, '1');
});

test('strict TAP parser requires version, one plan, 43 ordered passes, and exact summary', () => {
  assert.deepEqual(parseFixedTapSummary(tapFixture()), {
    ...expectedTapSummary,
    duration_ms: 1.25,
  });
  assert.throws(() => parseFixedTapSummary(tapFixture({ notOk: true })), /top-level not ok/u);
  assert.throws(() => parseFixedTapSummary(tapFixture({ duplicateSummary: true })), /duplicates tests/u);
  assert.throws(
    () => parseFixedTapSummary(Buffer.from(tapFixture().toString('utf8').replace('1..43', '1..42'))),
    /plan count mismatch/u,
  );
});

test('terminal status classification never promotes timeout, truncation, signal, failure, or stderr to PASS', () => {
  assert.deepEqual(deriveExecutionTerminalStatus(), { status: 'PASS', failure_code: 'none' });
  assert.equal(deriveExecutionTerminalStatus({ errorCode: 'ETIMEDOUT' }).status, 'TIMEOUT');
  assert.equal(deriveExecutionTerminalStatus({ errorCode: 'ENOBUFS' }).status, 'OUTPUT_LIMIT');
  assert.equal(deriveExecutionTerminalStatus({ signal: 'SIGKILL' }).status, 'SIGNALLED');
  assert.equal(deriveExecutionTerminalStatus({ exitCode: 1 }).status, 'FAIL');
  assert.equal(deriveExecutionTerminalStatus({ stderrBytes: 1 }).status, 'ERROR');
  assert.equal(deriveExecutionTerminalStatus({ tapValid: false }).status, 'ERROR');
});

test('execution workspace manifest is a sorted, linked-file-free fixed allowlist', () => {
  const manifest = buildExecutionWorkspaceManifest({ root: repoRoot });
  assert.equal(validateExecutionWorkspaceManifest(manifest.document), manifest.document);
  assert.ok(manifest.document.files.length > fixedSnapshotItems.length);
  for (const required of [
    'scripts/suxi_skill_test_execution.mjs',
    'scripts/suxi_skill_behavior_eval.mjs',
    'plugins/suxi-os-toolkit/.codex-plugin/plugin.json',
  ]) {
    assert.ok(manifest.document.files.some(file => file.path === required));
  }
  assert.deepEqual(
    manifest.document.files.map(file => file.path),
    [...manifest.document.files.map(file => file.path)].sort(),
  );

  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-full-workspace-manifest-'));
  try {
    mkdirSync(path.join(tempRoot, 'empty-directory'), { recursive: true });
    writeFileSync(path.join(tempRoot, 'file.txt'), 'fixture\n', 'utf8');
    const full = buildFullExecutionWorkspaceManifest({ root: tempRoot });
    assert.ok(full.document.directories.includes('empty-directory'));
    const before = full.sha256;
    mkdirSync(path.join(tempRoot, 'new-empty-directory'), { recursive: true });
    assert.notEqual(buildFullExecutionWorkspaceManifest({ root: tempRoot }).sha256, before);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('receipt validators reject raw output fields and PASS without stable complete evidence', () => {
  const started = startedFixture();
  const startedSha256 = sha256(jsonText(started));
  const result = resultFixture(started, startedSha256);
  assert.equal(result.result.status, 'PASS');
  assert.throws(
    () => validateExecutionResultReceipt({ ...result, raw_stdout: 'forbidden' }),
    /keys mismatch/u,
  );
  const unstable = structuredClone(result);
  unstable.artifact_stability_status = 'FAIL';
  assert.throws(() => validateExecutionResultReceipt(unstable), /stable artifacts/u);
  const stderr = structuredClone(result);
  stderr.result.stderr.bytes = 1;
  stderr.result.stderr.sha256 = '1'.repeat(64);
  assert.throws(() => validateExecutionResultReceipt(stderr), /empty stderr/u);
  const partialTap = structuredClone(result);
  partialTap.result.tap_summary.tests = 1;
  assert.throws(() => validateExecutionResultReceipt(partialTap), /TAP tests=1 expected 43/u);
});

test('append-only chain verifies PASS, preserves failures as FLAKY, and rejects orphan attempts', () => {
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-execution-receipt-test-'));
  try {
    const profile = profileFixture();
    const target = targetFixture();
    const absentSeries = path.join(tempRoot, 'absent-series');
    const emptyAnchored = verifyExecutionReceiptDirectory({
      seriesDir: absentSeries,
      target,
      profile,
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
    });
    assert.equal(emptyAnchored.receipt_status, 'PASS');
    assert.equal(emptyAnchored.head_anchor_status, 'MATCH');
    assert.equal(emptyAnchored.test_execution_status, 'NOT_RUN');
    const firstStarted = startedFixture();
    const firstStartedText = jsonText(firstStarted);
    const firstResult = resultFixture(firstStarted, sha256(firstStartedText), { status: 'FAIL' });
    const firstPrefix = `0001-${firstExecutionId}`;
    writeJson(path.join(tempRoot, `${firstPrefix}.started.json`), firstStarted);
    writeJson(path.join(tempRoot, `${firstPrefix}.result.json`), firstResult);
    const firstResultSha256 = sha256(jsonText(firstResult));

    const secondStarted = startedFixture({
      attemptNumber: 2,
      executionId: secondExecutionId,
      previous: firstResultSha256,
    });
    const secondResult = resultFixture(secondStarted, sha256(jsonText(secondStarted)));
    const secondPrefix = `0002-${secondExecutionId}`;
    writeJson(path.join(tempRoot, `${secondPrefix}.started.json`), secondStarted);
    writeJson(path.join(tempRoot, `${secondPrefix}.result.json`), secondResult);

    const secondResultSha256 = sha256(jsonText(secondResult));
    const verified = verifyExecutionReceiptDirectory({
      seriesDir: tempRoot,
      target,
      profile,
      expectedAttempts: 2,
      expectedLatestResultSha256: secondResultSha256,
    });
    assert.equal(verified.receipt_status, 'PASS', verified.failures.join('\n'));
    assert.equal(verified.test_execution_status, 'FLAKY');
    assert.equal(verified.verified_test_count, null);
    assert.deepEqual(aggregateExecutionStatuses(['FAIL', 'PASS']), 'FLAKY');

    const ownedLockId = '00000000-0000-4000-8000-000000000099';
    writeJson(path.join(tempRoot, '.execution.lock'), lockFixture({
      executionId: ownedLockId,
      expectedAttempts: 2,
      expectedLatestResultSha256: secondResultSha256,
      profile,
      target,
    }));
    const foreignLock = verifyExecutionReceiptDirectory({
      seriesDir: tempRoot,
      target,
      profile,
      expectedAttempts: 2,
      expectedLatestResultSha256: secondResultSha256,
    });
    assert.equal(foreignLock.receipt_status, 'FAIL');
    const ownedLock = verifyExecutionReceiptDirectory({
      seriesDir: tempRoot,
      target,
      profile,
      expectedAttempts: 2,
      expectedLatestResultSha256: secondResultSha256,
      ownedLockExecutionId: ownedLockId,
    });
    assert.equal(ownedLock.receipt_status, 'PASS');
    rmSync(path.join(tempRoot, '.execution.lock'));

    rmSync(path.join(tempRoot, `${secondPrefix}.result.json`));
    const orphaned = verifyExecutionReceiptDirectory({ seriesDir: tempRoot, target, profile });
    assert.equal(orphaned.receipt_status, 'FAIL');
    assert.equal(orphaned.test_execution_status, 'ERROR');

    writeJson(path.join(tempRoot, `${secondPrefix}.result.json`), secondResult);
    rmSync(path.join(tempRoot, `${secondPrefix}.started.json`));
    rmSync(path.join(tempRoot, `${secondPrefix}.result.json`));
    const truncated = verifyExecutionReceiptDirectory({
      seriesDir: tempRoot,
      target,
      profile,
      expectedAttempts: 2,
      expectedLatestResultSha256: secondResultSha256,
    });
    assert.equal(truncated.receipt_status, 'FAIL');
    assert.equal(truncated.head_anchor_status, 'MISMATCH');

    const unanchored = verifyExecutionReceiptDirectory({ seriesDir: tempRoot, target, profile });
    assert.equal(unanchored.receipt_status, 'UNANCHORED');
    assert.equal(unanchored.verified_test_count, null);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('stale-lock recovery preserves prestart and incomplete crashes as append-only ERROR attempts', () => {
  const tempRoot = mkdtempSync(path.join(tmpdir(), 'suxi-execution-recovery-test-'));
  try {
    assert.equal(probeProcessLiveness(999999), 'ABSENT');
    const profile = profileFixture();
    const target = targetFixture();
    const recoveryWrapperSha256 = sha256(
      readFileSync(path.join(repoRoot, 'scripts', 'suxi_skill_test_execution.mjs')),
    );
    const prestartDir = path.join(tempRoot, 'prestart');
    mkdirSync(prestartDir, { recursive: true });
    const prestartLock = lockFixture({
      executionId: firstExecutionId,
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: 999999,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    const prestartLockPath = path.join(prestartDir, '.execution.lock');
    writeJson(prestartLockPath, prestartLock);
    const prestartInspection = inspectExecutionRecovery({ seriesDir: prestartDir });
    assert.equal(prestartInspection.state, 'RECOVERABLE_PRESTART');
    const prestartRecovery = recoverExecutionSeries({
      seriesDir: prestartDir,
      expectedLockSha256: sha256(jsonText(prestartLock)),
      expectedRecoveryWrapperSha256: recoveryWrapperSha256,
      expectedPreviousAttempts: 0,
      expectedPreviousLatestResultSha256: null,
      allowExternalSeriesPath: true,
    });
    assert.equal(prestartRecovery.status, 'RECOVERED');
    assert.equal(prestartRecovery.created_started_receipt, true);
    assert.equal(prestartRecovery.created_result_receipt, true);
    assert.equal(prestartRecovery.test_execution_status, 'ERROR');
    assert.equal(existsSync(prestartLockPath), false, 'recovery lock path must be removed after verified recovery');
    const prestartVerified = verifyExecutionReceiptDirectory({
      seriesDir: prestartDir,
      target,
      profile,
      expectedAttempts: 1,
      expectedLatestResultSha256: prestartRecovery.next_expected_latest_result_sha256,
    });
    assert.equal(prestartVerified.receipt_status, 'PASS');
    assert.equal(prestartVerified.test_execution_status, 'ERROR');
    assert.equal(prestartVerified.verified_test_count, null);

    const incompleteDir = path.join(tempRoot, 'incomplete');
    mkdirSync(incompleteDir, { recursive: true });
    const incompleteLock = lockFixture({
      executionId: secondExecutionId,
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: 999999,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    const incompleteLockPath = path.join(incompleteDir, '.execution.lock');
    writeJson(incompleteLockPath, incompleteLock);
    const incompleteStarted = startedFixture({
      executionId: secondExecutionId,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    writeJson(path.join(incompleteDir, `0001-${secondExecutionId}.started.json`), incompleteStarted);
    assert.equal(inspectExecutionRecovery({ seriesDir: incompleteDir }).state, 'RECOVERABLE_INCOMPLETE_ATTEMPT');
    const incompleteRecovery = recoverExecutionSeries({
      seriesDir: incompleteDir,
      expectedLockSha256: sha256(jsonText(incompleteLock)),
      expectedRecoveryWrapperSha256: recoveryWrapperSha256,
      expectedPreviousAttempts: 0,
      expectedPreviousLatestResultSha256: null,
      allowExternalSeriesPath: true,
    });
    assert.equal(incompleteRecovery.created_started_receipt, false);
    assert.equal(incompleteRecovery.created_result_receipt, true);
    assert.equal(incompleteRecovery.test_execution_status, 'ERROR');

    const terminalDir = path.join(tempRoot, 'terminal');
    mkdirSync(terminalDir, { recursive: true });
    const terminalExecutionId = '00000000-0000-4000-8000-000000000004';
    const terminalLock = lockFixture({
      executionId: terminalExecutionId,
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: 999999,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    const terminalLockPath = path.join(terminalDir, '.execution.lock');
    writeJson(terminalLockPath, terminalLock);
    const terminalStarted = startedFixture({
      executionId: terminalExecutionId,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    const terminalStartedPath = path.join(terminalDir, `0001-${terminalExecutionId}.started.json`);
    const terminalResultPath = path.join(terminalDir, `0001-${terminalExecutionId}.result.json`);
    writeJson(terminalStartedPath, terminalStarted);
    const terminalResult = resultFixture(
      terminalStarted,
      sha256(jsonText(terminalStarted)),
    );
    writeJson(terminalResultPath, terminalResult);
    const terminalResultSha256 = sha256(jsonText(terminalResult));
    assert.equal(inspectExecutionRecovery({ seriesDir: terminalDir }).state, 'RECOVERABLE_TERMINAL');
    const terminalRecovery = recoverExecutionSeries({
      seriesDir: terminalDir,
      expectedLockSha256: sha256(jsonText(terminalLock)),
      expectedRecoveryWrapperSha256: recoveryWrapperSha256,
      expectedPreviousAttempts: 0,
      expectedPreviousLatestResultSha256: null,
      allowExternalSeriesPath: true,
    });
    assert.equal(terminalRecovery.created_started_receipt, false);
    assert.equal(terminalRecovery.created_result_receipt, false);
    assert.equal(terminalRecovery.next_expected_attempts, 1);
    assert.equal(terminalRecovery.next_expected_latest_result_sha256, terminalResultSha256);
    assert.equal(existsSync(terminalLockPath), false);

    const wrongHashDir = path.join(tempRoot, 'wrong-hash');
    mkdirSync(wrongHashDir, { recursive: true });
    const wrongHashLock = lockFixture({
      executionId: '00000000-0000-4000-8000-000000000005',
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: 999999,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    const wrongHashLockPath = path.join(wrongHashDir, '.execution.lock');
    writeJson(wrongHashLockPath, wrongHashLock);
    assert.throws(
      () => recoverExecutionSeries({
        seriesDir: wrongHashDir,
        expectedLockSha256: '0'.repeat(64),
        expectedRecoveryWrapperSha256: recoveryWrapperSha256,
        expectedPreviousAttempts: 0,
        expectedPreviousLatestResultSha256: null,
        allowExternalSeriesPath: true,
      }),
      /lock hash does not match/u,
    );
    assert.equal(existsSync(wrongHashLockPath), true);

    const activeDir = path.join(tempRoot, 'active');
    mkdirSync(activeDir, { recursive: true });
    const activeLock = lockFixture({
      executionId: '00000000-0000-4000-8000-000000000003',
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: process.pid,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    writeJson(path.join(activeDir, '.execution.lock'), activeLock);
    assert.equal(inspectExecutionRecovery({ seriesDir: activeDir }).state, 'ACTIVE');
    assert.throws(
      () => recoverExecutionSeries({
        seriesDir: activeDir,
        expectedLockSha256: sha256(jsonText(activeLock)),
        expectedRecoveryWrapperSha256: recoveryWrapperSha256,
        expectedPreviousAttempts: 0,
        expectedPreviousLatestResultSha256: null,
        allowExternalSeriesPath: true,
      }),
      /not allowed in state ACTIVE/u,
    );

    const catalogRoot = path.join(tempRoot, 'catalog');
    const legacyDir = path.join(catalogRoot, 'legacy-series');
    const validDir = path.join(catalogRoot, 'valid-series');
    const invalidDir = path.join(catalogRoot, 'invalid-series');
    for (const directory of [legacyDir, validDir, invalidDir]) {
      mkdirSync(directory, { recursive: true });
    }
    const legacyLock = {
      execution_id: '00000000-0000-4000-8000-000000000006',
      started_at: '2026-08-29T00:00:00.000Z',
    };
    writeJson(path.join(legacyDir, '.execution.lock'), legacyLock);
    const catalogValidLock = lockFixture({
      executionId: '00000000-0000-4000-8000-000000000007',
      expectedAttempts: 0,
      expectedLatestResultSha256: null,
      profile,
      target,
      ownerPid: 999999,
      startedAt: '2026-08-29T00:00:00.000Z',
    });
    writeJson(path.join(validDir, '.execution.lock'), catalogValidLock);
    writeJson(path.join(invalidDir, '.execution.lock'), { invalid: true });
    const catalog = inspectExecutionRecoveryCatalog({
      root: catalogRoot,
      allowExternalRoot: true,
    });
    assert.equal(catalog.status, 'ATTENTION');
    assert.deepEqual(
      catalog.recoveries.map(row => row.state).sort(),
      ['BLOCKED_INVALID_LOCK', 'BLOCKED_LEGACY_LOCK', 'RECOVERABLE_PRESTART'].sort(),
    );
    assert.throws(
      () => recoverExecutionSeries({
        seriesDir: legacyDir,
        expectedLockSha256: sha256(jsonText(legacyLock)),
        expectedRecoveryWrapperSha256: recoveryWrapperSha256,
        expectedPreviousAttempts: 0,
        expectedPreviousLatestResultSha256: null,
        allowExternalSeriesPath: true,
      }),
      /not allowed in state BLOCKED_LEGACY_LOCK/u,
    );
    assert.equal(existsSync(path.join(legacyDir, '.execution.lock')), true);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('behavior verifier remains free of child-process execution capability', () => {
  const source = readFileSync(path.join(repoRoot, 'scripts', 'suxi_skill_behavior_eval.mjs'), 'utf8');
  assert.doesNotMatch(source, /node:child_process|spawnSync|execFileSync/u);
  const wrapper = readFileSync(path.join(repoRoot, 'scripts', 'suxi_skill_test_execution.mjs'), 'utf8');
  assert.match(wrapper, /from 'node:child_process'/u);
  assert.match(wrapper, /--test-reporter=tap/u);
  const runStart = wrapper.indexOf('export function runFixedTestExecution');
  const runEnd = wrapper.indexOf('export function verifyCurrentTestExecution');
  const runSource = wrapper.slice(runStart, runEnd);
  const lockIndex = runSource.indexOf('lockPath = acquireExecutionLock');
  const previousHeadIndex = runSource.indexOf('const existing = verifyExecutionReceiptDirectory');
  const newHeadIndex = runSource.lastIndexOf('const verified = verifyExecutionReceiptDirectory');
  const releaseIndex = runSource.lastIndexOf('releaseExecutionLock');
  assert.ok(lockIndex >= 0 && lockIndex < previousHeadIndex, 'lock must precede previous-head validation');
  assert.ok(newHeadIndex >= 0 && newHeadIndex < releaseIndex, 'new-head validation must precede lock release');
  assert.match(runSource, /let releaseLock = false/u);
  assert.match(runSource, /releaseLock = true;[\s\S]*if \(releaseLock\) releaseExecutionLock/u);
});
