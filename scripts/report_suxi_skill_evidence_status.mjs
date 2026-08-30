import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';
import { fileURLToPath } from 'node:url';

import {
  repoRoot as behaviorRepoRoot,
  verifyBehaviorSuite,
  verifyEvidenceArchive,
} from './suxi_skill_behavior_eval.mjs';
import {
  inspectCurrentExecutionRecoveries,
  repoRoot as executionRepoRoot,
  verifyCurrentTestExecution,
} from './suxi_skill_test_execution.mjs';

const scriptPath = fileURLToPath(import.meta.url);
export const repoRoot = path.resolve(path.dirname(scriptPath), '..');
export const evidenceStatusVersion = 'suxi.skill.behavior_evidence_status.v1';

function requireCondition(condition, message) {
  if (!condition) throw new Error(message);
}

function requireExactKeys(value, keys, context) {
  requireCondition(value && typeof value === 'object' && !Array.isArray(value), `${context} must be an object`);
  requireCondition(
    JSON.stringify(Object.keys(value).sort()) === JSON.stringify([...keys].sort()),
    `${context} keys mismatch`,
  );
}

function requireStatus(value, context) {
  requireCondition(['PASS', 'FAIL', 'BLOCKED', 'NOT_RUN'].includes(value), `${context} is invalid`);
  return value;
}

function requireShaOrNull(value, context) {
  requireCondition(
    value === null || (typeof value === 'string' && /^[a-f0-9]{64}$/u.test(value)),
    `${context} must be null or lowercase SHA-256`,
  );
  return value;
}

function samePath(left, right) {
  const normalize = value => (
    process.platform === 'win32' ? path.resolve(value).toLowerCase() : path.resolve(value)
  );
  return normalize(left) === normalize(right);
}

requireCondition(samePath(repoRoot, behaviorRepoRoot), 'Status reporter and behavior verifier roots differ');
requireCondition(samePath(repoRoot, executionRepoRoot), 'Status reporter and execution verifier roots differ');

function isSha(value) {
  return typeof value === 'string' && /^[a-f0-9]{64}$/u.test(value);
}

function isNonNegativeInteger(value) {
  return Number.isInteger(value) && value >= 0;
}

function hasCountShape(value, keys) {
  return value
    && typeof value === 'object'
    && !Array.isArray(value)
    && JSON.stringify(Object.keys(value).sort()) === JSON.stringify([...keys].sort())
    && keys.every(key => isNonNegativeInteger(value[key]));
}

function behaviorLayerStatus(layer) {
  const skills = Array.isArray(layer.skills) ? layer.skills : [];
  if (layer.replay_status === 'FAIL'
    || layer.failure_count > 0
    || skills.some(row => row.replay_status === 'FAIL' || row.grade_status === 'FAIL')
    || layer.counts?.fail > 0) return 'FAIL';
  if (skills.some(row => row.grade_status === 'BLOCKED') || layer.counts?.blocked > 0) return 'BLOCKED';
  if (layer.replay_status === 'NOT_RUN'
    || skills.some(row => row.replay_status === 'NOT_RUN')
    || layer.counts === null) return 'NOT_RUN';
  if (layer.replay_status !== 'PASS'
    || skills.length === 0
    || !isSha(layer.ledger_sha256)
    || !hasCountShape(layer.counts, ['skills', 'cases', 'pass', 'fail', 'blocked', 'assertions', 'evidence_spans'])
    || new Set(skills.map(row => row.skill_name)).size !== skills.length
    || layer.counts.skills !== skills.length
    || layer.counts.cases !== layer.counts.pass + layer.counts.fail + layer.counts.blocked
    || layer.counts.cases <= 0
    || layer.counts.pass !== layer.counts.cases
    || layer.counts.assertions <= 0
    || layer.counts.evidence_spans <= 0
    || skills.some(row => row.replay_status !== 'PASS' || row.grade_status !== 'PASS')) {
    return 'FAIL';
  }
  return 'PASS';
}

function archiveLayerStatus(layer) {
  const structuralFail = layer.content_status === 'FAIL'
    || layer.archive_seal_status === 'FAIL'
    || ['FAIL', 'MISMATCH'].includes(layer.verifier_identity_status)
    || layer.reproducibility_status === 'FAIL'
    || layer.failure_count > 0;
  if (structuralFail) return 'FAIL';
  const notRun = layer.content_status === 'NOT_RUN'
    || layer.archive_seal_status === 'MISSING'
    || layer.verifier_identity_status === 'UNBOUND'
    || layer.reproducibility_status === 'NOT_RUN';
  if (notRun) return 'NOT_RUN';
  if (layer.content_status !== 'PASS'
    || layer.archive_seal_status !== 'SEALED'
    || layer.verifier_identity_status !== 'MATCH'
    || layer.reproducibility_status !== 'PASS'
    || !isSha(layer.archive_manifest_sha256)
    || !isSha(layer.source_ledger_sha256)
    || !isSha(layer.archive_seal_sha256)
    || !isSha(layer.verifier_receipt_sha256)
    || !isSha(layer.verifier_profile_sha256)
    || layer.verifier_profile_sha256 !== layer.bound_verifier_profile_sha256
    || !hasCountShape(layer.counts, ['runs', 'files', 'bytes', 'seals'])
    || layer.counts.runs <= 0
    || layer.counts.files <= 0
    || layer.counts.bytes <= 0
    || layer.counts.seals !== layer.counts.runs * 2) {
    return 'FAIL';
  }
  return 'PASS';
}

function executionLayerStatus(layer) {
  if (layer.receipt_status === 'FAIL'
    || layer.chain_status === 'FAIL'
    || layer.head_anchor_status === 'MISMATCH'
    || layer.failure_count > 0) return 'FAIL';
  if (layer.receipt_status === 'UNANCHORED'
    || layer.head_anchor_status === 'UNANCHORED') return 'NOT_RUN';
  if (['FLAKY', 'TIMEOUT', 'OUTPUT_LIMIT', 'SIGNALLED'].includes(layer.test_execution_status)) {
    return 'BLOCKED';
  }
  if (layer.test_execution_status === 'NOT_RUN') return 'NOT_RUN';
  if (layer.receipt_status !== 'PASS'
    || layer.chain_status !== 'PASS'
    || layer.head_anchor_status !== 'MATCH'
    || layer.test_execution_status !== 'PASS'
    || !Number.isInteger(layer.attempts)
    || layer.attempts <= 0
    || !isSha(layer.latest_result_sha256)
    || layer.verified_test_count !== 43
    || !isSha(layer.execution_profile_sha256)
    || !isSha(layer.archive_manifest_sha256)
    || !isSha(layer.verifier_receipt_sha256)
    || !isSha(layer.verifier_profile_sha256)
    || layer.incomplete_attempt !== null) {
    return 'FAIL';
  }
  return 'PASS';
}

function recoveryLayerStatus(layer) {
  if (layer.catalog_status === 'NO_LOCKS') {
    return layer.lock_count === 0 && Array.isArray(layer.states) && layer.states.length === 0
      ? 'PASS'
      : 'FAIL';
  }
  if (layer.catalog_status === 'ATTENTION') {
    return layer.lock_count > 0
      && Array.isArray(layer.states)
      && layer.states.length === layer.lock_count
      ? 'BLOCKED'
      : 'FAIL';
  }
  return 'FAIL';
}

function normalizeBehaviorLayer(result) {
  const counts = result.verified_counts || null;
  const layer = {
    status: 'FAIL',
    replay_status: result.status,
    ledger_sha256: result.ledger_sha256 || null,
    counts,
    skills: Array.isArray(result.skill_results)
      ? result.skill_results.map(row => ({
        skill_name: row.skill_name,
        replay_status: row.status,
        grade_status: row.grade_status || null,
      }))
      : [],
    failure_count: Array.isArray(result.skill_results)
      ? result.skill_results.reduce((total, row) => total + (row.failures?.length || 0), 0)
      : 1,
  };
  layer.status = behaviorLayerStatus(layer);
  return layer;
}

function normalizeArchiveLayer(result) {
  const layer = {
    status: 'FAIL',
    content_status: result.content_status || result.status || 'FAIL',
    archive_seal_status: result.archive_seal_status || 'FAIL',
    verifier_identity_status: result.verifier_identity_status || 'FAIL',
    reproducibility_status: result.reproducibility_status || 'FAIL',
    archive_manifest_sha256: result.archive_manifest_sha256 || null,
    source_ledger_sha256: result.source_ledger_sha256 || null,
    archive_seal_sha256: result.archive_seal_sha256 || null,
    verifier_receipt_sha256: result.verifier_receipt_sha256 || null,
    verifier_profile_sha256: result.current_verifier_profile_sha256 || null,
    bound_verifier_profile_sha256: result.bound_verifier_profile_sha256 || null,
    counts: result.reproducibility_verified_counts || null,
    failure_count: (result.archive_failures?.length || 0)
      + (result.verifier_identity_failures?.length || 0),
  };
  layer.status = archiveLayerStatus(layer);
  return layer;
}

function normalizeExecutionLayer(result) {
  const layer = {
    status: 'FAIL',
    receipt_status: result.receipt_status || null,
    chain_status: result.chain_status || null,
    head_anchor_status: result.head_anchor_status || null,
    test_execution_status: result.test_execution_status || null,
    attempts: Number.isInteger(result.attempts) ? result.attempts : 0,
    latest_result_sha256: result.latest_result_sha256 || null,
    verified_test_count: Number.isInteger(result.verified_test_count)
      ? result.verified_test_count
      : null,
    execution_profile_sha256: result.execution_profile_sha256 || null,
    archive_manifest_sha256: result.archive_manifest_sha256 || null,
    verifier_receipt_sha256: result.verifier_receipt_sha256 || null,
    verifier_profile_sha256: result.verifier_profile_sha256 || null,
    incomplete_attempt: result.incomplete_attempt || null,
    failure_count: result.failures?.length || 0,
  };
  layer.status = executionLayerStatus(layer);
  return layer;
}

function normalizeRecoveryLayer(result) {
  let layer;
  if (result.status === 'NO_LOCKS') {
    layer = {
      status: 'FAIL',
      catalog_status: result.status,
      lock_count: result.recoveries?.length || 0,
      states: (result.recoveries || []).map(row => row.state),
    };
  } else if (result.status === 'ATTENTION') {
    layer = {
      status: 'FAIL',
      catalog_status: result.status,
      lock_count: result.recoveries?.length || 0,
      states: (result.recoveries || []).map(row => row.state),
    };
  } else {
    layer = {
      status: 'FAIL',
      catalog_status: result.status || 'ERROR',
      lock_count: result.recoveries?.length || 0,
      states: (result.recoveries || []).map(row => row.state),
    };
  }
  layer.status = recoveryLayerStatus(layer);
  return layer;
}

function crossLayerIdentity(layers) {
  const comparisons = [
    [layers.behavior.ledger_sha256, layers.archive_verifier.source_ledger_sha256],
    [layers.test_execution.archive_manifest_sha256, layers.archive_verifier.archive_manifest_sha256],
    [layers.test_execution.verifier_receipt_sha256, layers.archive_verifier.verifier_receipt_sha256],
    [layers.test_execution.verifier_profile_sha256, layers.archive_verifier.verifier_profile_sha256],
  ];
  const mismatches = comparisons.filter(([left, right]) => (
    isSha(left) && isSha(right) && left !== right
  )).length;
  return {
    status: mismatches === 0 ? 'PASS' : 'FAIL',
    mismatch_count: mismatches,
  };
}

function overallStatus(layers, identityConsistency) {
  const statuses = [
    ...Object.values(layers).map(layer => layer.status),
    identityConsistency.status,
  ];
  if (statuses.includes('FAIL')) return 'FAIL';
  if (statuses.includes('BLOCKED')) return 'BLOCKED';
  if (statuses.includes('NOT_RUN')) return 'NOT_RUN';
  return 'PASS';
}

function nextAction(status, layers, identityConsistency) {
  if (status === 'PASS') return 'stop';
  if (identityConsistency.status === 'FAIL') return 'repair_cross_layer_evidence_identity';
  const priority = ['FAIL', 'BLOCKED', 'NOT_RUN'];
  const layerNames = ['behavior', 'archive_verifier', 'test_execution', 'recovery'];
  for (const targetStatus of priority) {
    const layer = layerNames.find(name => layers[name].status === targetStatus);
    if (!layer) continue;
    return {
      behavior: 'repair_or_rerun_skill_behavior_evidence',
      archive_verifier: 'repair_archive_or_verifier_identity_chain',
      test_execution: targetStatus === 'NOT_RUN'
        ? 'supply_and_verify_external_execution_head'
        : 'inspect_fixed_test_execution_receipts',
      recovery: 'inspect_or_recover_execution_lock_without_deleting_history',
    }[layer];
  }
  return 'inspect_evidence_status_contract';
}

function verifiedCountsFor(layers, status) {
  if (status !== 'PASS') return null;
  return {
    skills: layers.behavior.counts.skills,
    cases: layers.behavior.counts.cases,
    assertions: layers.behavior.counts.assertions,
    evidence_spans: layers.behavior.counts.evidence_spans,
    archive_runs: layers.archive_verifier.counts.runs,
    archive_files: layers.archive_verifier.counts.files,
    archive_bytes: layers.archive_verifier.counts.bytes,
    archive_seals: layers.archive_verifier.counts.seals,
    executed_tests: layers.test_execution.verified_test_count,
    execution_attempts: layers.test_execution.attempts,
  };
}

export function buildEvidenceStatusReport({
  behaviorResult,
  archiveResult,
  executionResult,
  recoveryResult,
} = {}) {
  const layers = {
    behavior: normalizeBehaviorLayer(behaviorResult),
    archive_verifier: normalizeArchiveLayer(archiveResult),
    test_execution: normalizeExecutionLayer(executionResult),
    recovery: normalizeRecoveryLayer(recoveryResult),
  };
  const identityConsistency = crossLayerIdentity(layers);
  const status = overallStatus(layers, identityConsistency);
  const verifiedCounts = verifiedCountsFor(layers, status);
  return validateEvidenceStatusReport({
    schema_version: evidenceStatusVersion,
    status,
    layers,
    identity_consistency: identityConsistency,
    verified_counts: verifiedCounts,
    next_action: nextAction(status, layers, identityConsistency),
    local_only: true,
    evidence_boundary: 'This report aggregates four local evidence layers without changing them. PASS requires behavior replay, archive/verifier identity, an externally anchored fixed-test execution head, and an empty recovery catalog. It does not prove judge identity, model execution, Git persistence, deployment, production data, field behavior, or an external signature.',
  });
}

export function validateEvidenceStatusReport(document) {
  requireExactKeys(
    document,
    [
      'schema_version',
      'status',
      'layers',
      'identity_consistency',
      'verified_counts',
      'next_action',
      'local_only',
      'evidence_boundary',
    ],
    'evidence status report',
  );
  requireCondition(document.schema_version === evidenceStatusVersion, 'evidence status report schema mismatch');
  requireStatus(document.status, 'evidence status report.status');
  requireExactKeys(
    document.layers,
    ['behavior', 'archive_verifier', 'test_execution', 'recovery'],
    'evidence status report.layers',
  );
  const behavior = document.layers.behavior;
  requireExactKeys(
    behavior,
    ['status', 'replay_status', 'ledger_sha256', 'counts', 'skills', 'failure_count'],
    'evidence status behavior layer',
  );
  requireStatus(behavior.status, 'evidence status behavior.status');
  requireCondition(['PASS', 'FAIL', 'NOT_RUN'].includes(behavior.replay_status), 'behavior replay_status is invalid');
  requireShaOrNull(behavior.ledger_sha256, 'behavior ledger_sha256');
  requireCondition(behavior.counts === null
    || hasCountShape(behavior.counts, ['skills', 'cases', 'pass', 'fail', 'blocked', 'assertions', 'evidence_spans']), 'behavior counts are invalid');
  requireCondition(Array.isArray(behavior.skills), 'behavior skills must be an array');
  for (const skill of behavior.skills) {
    requireExactKeys(skill, ['skill_name', 'replay_status', 'grade_status'], 'behavior skill');
    requireCondition(typeof skill.skill_name === 'string' && skill.skill_name !== '', 'behavior skill_name is invalid');
    requireCondition(['PASS', 'FAIL', 'NOT_RUN'].includes(skill.replay_status), 'behavior skill replay status is invalid');
    requireCondition(skill.grade_status === null
      || ['PASS', 'FAIL', 'BLOCKED'].includes(skill.grade_status), 'behavior skill grade status is invalid');
  }
  requireCondition(isNonNegativeInteger(behavior.failure_count), 'behavior failure_count is invalid');
  requireCondition(behavior.status === behaviorLayerStatus(behavior), 'behavior layer status disagrees with its evidence');

  const archive = document.layers.archive_verifier;
  requireExactKeys(
    archive,
    [
      'status',
      'content_status',
      'archive_seal_status',
      'verifier_identity_status',
      'reproducibility_status',
      'archive_manifest_sha256',
      'source_ledger_sha256',
      'archive_seal_sha256',
      'verifier_receipt_sha256',
      'verifier_profile_sha256',
      'bound_verifier_profile_sha256',
      'counts',
      'failure_count',
    ],
    'evidence status archive layer',
  );
  requireStatus(archive.status, 'evidence status archive.status');
  requireCondition(['PASS', 'FAIL', 'NOT_RUN'].includes(archive.content_status), 'archive content_status is invalid');
  requireCondition(['SEALED', 'MISSING', 'FAIL'].includes(archive.archive_seal_status), 'archive seal status is invalid');
  requireCondition(['MATCH', 'MISMATCH', 'UNBOUND', 'FAIL'].includes(archive.verifier_identity_status), 'archive identity status is invalid');
  requireCondition(['PASS', 'FAIL', 'NOT_RUN'].includes(archive.reproducibility_status), 'archive reproducibility status is invalid');
  for (const field of [
    'archive_manifest_sha256',
    'source_ledger_sha256',
    'archive_seal_sha256',
    'verifier_receipt_sha256',
    'verifier_profile_sha256',
    'bound_verifier_profile_sha256',
  ]) requireShaOrNull(archive[field], `archive ${field}`);
  requireCondition(archive.counts === null
    || hasCountShape(archive.counts, ['runs', 'files', 'bytes', 'seals']), 'archive counts are invalid');
  requireCondition(isNonNegativeInteger(archive.failure_count), 'archive failure_count is invalid');
  requireCondition(archive.status === archiveLayerStatus(archive), 'archive layer status disagrees with its evidence');

  const execution = document.layers.test_execution;
  requireExactKeys(
    execution,
    [
      'status',
      'receipt_status',
      'chain_status',
      'head_anchor_status',
      'test_execution_status',
      'attempts',
      'latest_result_sha256',
      'verified_test_count',
      'execution_profile_sha256',
      'archive_manifest_sha256',
      'verifier_receipt_sha256',
      'verifier_profile_sha256',
      'incomplete_attempt',
      'failure_count',
    ],
    'evidence status execution layer',
  );
  requireStatus(execution.status, 'evidence status execution.status');
  requireCondition(['PASS', 'FAIL', 'UNANCHORED'].includes(execution.receipt_status), 'execution receipt_status is invalid');
  requireCondition(['PASS', 'FAIL'].includes(execution.chain_status), 'execution chain_status is invalid');
  requireCondition(['MATCH', 'MISMATCH', 'UNANCHORED'].includes(execution.head_anchor_status), 'execution head status is invalid');
  requireCondition(
    ['PASS', 'FAIL', 'NOT_RUN', 'FLAKY', 'TIMEOUT', 'OUTPUT_LIMIT', 'SIGNALLED', 'ERROR'].includes(execution.test_execution_status),
    'execution test status is invalid',
  );
  requireCondition(isNonNegativeInteger(execution.attempts), 'execution attempts is invalid');
  for (const field of [
    'latest_result_sha256',
    'execution_profile_sha256',
    'archive_manifest_sha256',
    'verifier_receipt_sha256',
    'verifier_profile_sha256',
  ]) requireShaOrNull(execution[field], `execution ${field}`);
  requireCondition(execution.verified_test_count === null
    || isNonNegativeInteger(execution.verified_test_count), 'execution verified_test_count is invalid');
  requireCondition(execution.incomplete_attempt === null
    || (execution.incomplete_attempt && typeof execution.incomplete_attempt === 'object'), 'execution incomplete_attempt is invalid');
  requireCondition(isNonNegativeInteger(execution.failure_count), 'execution failure_count is invalid');
  requireCondition(execution.status === executionLayerStatus(execution), 'execution layer status disagrees with its evidence');

  const recovery = document.layers.recovery;
  requireExactKeys(recovery, ['status', 'catalog_status', 'lock_count', 'states'], 'evidence status recovery layer');
  requireStatus(recovery.status, 'evidence status recovery.status');
  requireCondition(['NO_LOCKS', 'ATTENTION', 'ERROR'].includes(recovery.catalog_status), 'recovery catalog status is invalid');
  requireCondition(isNonNegativeInteger(recovery.lock_count), 'recovery lock_count is invalid');
  requireCondition(Array.isArray(recovery.states)
    && recovery.states.every(value => typeof value === 'string'), 'recovery states are invalid');
  requireCondition(recovery.status === recoveryLayerStatus(recovery), 'recovery layer status disagrees with its evidence');

  requireExactKeys(document.identity_consistency, ['status', 'mismatch_count'], 'identity consistency');
  requireCondition(['PASS', 'FAIL'].includes(document.identity_consistency.status), 'identity consistency status is invalid');
  requireCondition(isNonNegativeInteger(document.identity_consistency.mismatch_count), 'identity mismatch_count is invalid');
  const expectedIdentity = crossLayerIdentity(document.layers);
  requireCondition(isDeepStrictEqual(document.identity_consistency, expectedIdentity), 'identity consistency disagrees with layer hashes');
  const expectedStatus = overallStatus(document.layers, expectedIdentity);
  requireCondition(document.status === expectedStatus, 'overall status disagrees with layer statuses');
  const expectedNextAction = nextAction(expectedStatus, document.layers, expectedIdentity);
  requireCondition(document.next_action === expectedNextAction, 'next_action disagrees with current evidence');
  const expectedCounts = verifiedCountsFor(document.layers, expectedStatus);
  requireCondition(isDeepStrictEqual(document.verified_counts, expectedCounts), 'verified_counts disagree with current evidence');
  requireCondition(typeof document.local_only === 'boolean' && document.local_only, 'evidence status must remain local_only');
  requireCondition(typeof document.evidence_boundary === 'string' && document.evidence_boundary.trim() !== '', 'evidence status boundary is missing');
  return document;
}

export function collectCurrentEvidenceStatus({
  expectedExecutionAttempts = null,
  expectedExecutionLatestResultSha256 = null,
  providers = {},
} = {}) {
  const capture = (operation, fallback) => {
    try {
      return operation();
    } catch {
      return fallback;
    }
  };
  const behaviorResult = capture(
    providers.behavior || (() => verifyBehaviorSuite()),
    {
      status: 'FAIL',
      ledger_sha256: null,
      verified_counts: null,
      skill_results: [{ skill_name: 'collection', status: 'FAIL', failures: ['collection_error'] }],
    },
  );
  const archiveResult = capture(
    providers.archive || (() => verifyEvidenceArchive()),
    {
      status: 'FAIL',
      content_status: 'FAIL',
      archive_failures: ['collection_error'],
      verifier_identity_failures: [],
    },
  );
  const executionResult = capture(
    providers.execution || (() => verifyCurrentTestExecution({
      expectedAttempts: expectedExecutionAttempts,
      expectedLatestResultSha256: expectedExecutionLatestResultSha256,
    })),
    {
      receipt_status: 'FAIL',
      chain_status: 'FAIL',
      head_anchor_status: 'MISMATCH',
      test_execution_status: 'ERROR',
      attempts: 0,
      failures: ['collection_error'],
    },
  );
  const recoveryResult = capture(
    providers.recovery || (() => inspectCurrentExecutionRecoveries()),
    { status: 'ERROR', recoveries: [] },
  );
  return buildEvidenceStatusReport({
    behaviorResult,
    archiveResult,
    executionResult,
    recoveryResult,
  });
}

export function parseEvidenceStatusArgs(argv) {
  const options = {
    expectedExecutionAttempts: null,
    expectedExecutionLatestResultSha256: null,
  };
  let attemptsSeen = false;
  let latestSeen = false;
  for (const token of argv) {
    if (token.startsWith('--expected-execution-attempts=')) {
      const value = token.slice('--expected-execution-attempts='.length);
      requireCondition(/^\d+$/u.test(value), 'expected execution attempts must be a non-negative integer');
      options.expectedExecutionAttempts = Number(value);
      attemptsSeen = true;
    } else if (token.startsWith('--expected-execution-latest-result-sha256=')) {
      const value = token.slice('--expected-execution-latest-result-sha256='.length).trim();
      options.expectedExecutionLatestResultSha256 = value === 'none' ? null : value;
      latestSeen = true;
    } else if (token === '--help' || token === '-h') {
      return { help: true, ...options };
    } else {
      throw new Error(`Unknown argument: ${token}`);
    }
  }
  requireCondition(attemptsSeen === latestSeen, 'execution head arguments must be supplied together');
  if (attemptsSeen) {
    if (options.expectedExecutionAttempts === 0) {
      requireCondition(options.expectedExecutionLatestResultSha256 === null, 'zero attempts require latest=none');
    } else {
      requireShaOrNull(options.expectedExecutionLatestResultSha256, 'expected execution latest result');
      requireCondition(options.expectedExecutionLatestResultSha256 !== null, 'positive attempts require a result SHA');
    }
  }
  return { help: false, ...options };
}

function printHelp() {
  process.stdout.write('SUXIOS skill evidence status (read-only)\n\n');
  process.stdout.write('Usage:\n');
  process.stdout.write('  node scripts/report_suxi_skill_evidence_status.mjs --expected-execution-attempts=N --expected-execution-latest-result-sha256=sha256|none\n');
  process.stdout.write('\nOmitting the external execution head keeps the execution layer and overall status at NOT_RUN.\n');
}

function main() {
  try {
    const options = parseEvidenceStatusArgs(process.argv.slice(2));
    if (options.help) {
      printHelp();
      return;
    }
    const result = collectCurrentEvidenceStatus(options);
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    if (result.status === 'FAIL') process.exitCode = 1;
    else if (['BLOCKED', 'NOT_RUN'].includes(result.status)) process.exitCode = 2;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({ status: 'ERROR', error: error.message })}\n`);
    process.exitCode = 1;
  }
}

if (process.argv[1] && samePath(process.argv[1], scriptPath)) {
  main();
}
