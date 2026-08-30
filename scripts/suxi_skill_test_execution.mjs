import { createHash, randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import {
  cpSync,
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  realpathSync,
  rmSync,
  statSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';
import { fileURLToPath } from 'node:url';

import {
  repoRoot as behaviorRepoRoot,
  verifyEvidenceArchive,
} from './suxi_skill_behavior_eval.mjs';

const scriptPath = fileURLToPath(import.meta.url);
export const repoRoot = path.resolve(path.dirname(scriptPath), '..');

export const executionWorkspaceVersion = 'suxi.skill.behavior_test_execution_workspace.v1';
export const executionProfileVersion = 'suxi.skill.behavior_test_execution_profile.v1';
export const executionStartedVersion = 'suxi.skill.behavior_test_execution_started.v1';
export const executionResultVersion = 'suxi.skill.behavior_test_execution_result.v1';
export const executionVerificationVersion = 'suxi.skill.behavior_test_execution_verification.v1';
export const executionLockVersion = 'suxi.skill.behavior_test_execution_lock.v1';

export const fixedTestArgs = Object.freeze([
  '--test',
  '--test-reporter=tap',
  'tests/automation/suxi_skill_behavior_eval.test.mjs',
  'tests/automation/suxi_skill_contracts.test.mjs',
]);

export const fixedSnapshotItems = Object.freeze([
  'scripts/suxi_skill_test_execution.mjs',
  'scripts/suxi_skill_behavior_eval.mjs',
  'tests/automation/suxi_skill_test_execution_receipt.test.mjs',
  'tests/automation/suxi_skill_behavior_eval.test.mjs',
  'tests/automation/suxi_skill_contracts.test.mjs',
  'evals/suxi-skill-behavior-evidence.json',
  'plugins/suxi-os-toolkit/.codex-plugin/plugin.json',
  '.agents/skills/suxi-product-decision',
  '.agents/skills/suxi-test-guard',
  '.agents/skills/suxi-user-research',
  'plugins/suxi-os-toolkit/skills/suxi-product-decision',
  'plugins/suxi-os-toolkit/skills/suxi-test-guard',
  'plugins/suxi-os-toolkit/skills/suxi-user-research',
]);

export const executionEnvironmentPolicyId = 'suxi.synthetic_fixed_node_test_env.v1';
export const executionTimeoutMs = 60_000;
export const executionOutputLimitBytes = 8 * 1024 * 1024;
export const expectedTapSummary = Object.freeze({
  tests: 43,
  suites: 0,
  pass: 43,
  fail: 0,
  cancelled: 0,
  skipped: 0,
  todo: 0,
});

const executionReceiptsRoot = path.join(
  repoRoot,
  'evals',
  'suxi-skill-behavior-test-execution-receipts',
);
const snapshotPrefix = 'suxi-skill-test-execution-';

function requireCondition(condition, message) {
  if (!condition) throw new Error(message);
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function jsonText(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function fileSha256(filePath) {
  return sha256(readFileSync(filePath));
}

function nonEmptyString(value, context) {
  requireCondition(typeof value === 'string' && value.trim() !== '', `${context} must be a non-empty string`);
  return value.trim();
}

function requireSha256(value, context) {
  requireCondition(typeof value === 'string' && /^[a-f0-9]{64}$/u.test(value), `${context} must be a lowercase SHA-256`);
  return value;
}

function requireNonNegativeInteger(value, context) {
  requireCondition(Number.isInteger(value) && value >= 0, `${context} must be a non-negative integer`);
  return value;
}

function requireExactKeys(value, keys, context) {
  requireCondition(value && typeof value === 'object' && !Array.isArray(value), `${context} must be an object`);
  requireCondition(
    isDeepStrictEqual(Object.keys(value).sort(), [...keys].sort()),
    `${context} keys mismatch`,
  );
}

function requireNormalizedRelativePath(value, context) {
  const stored = nonEmptyString(value, context);
  const normalized = stored.replaceAll('\\', '/');
  requireCondition(
    stored === normalized
      && !path.posix.isAbsolute(normalized)
      && !path.win32.isAbsolute(normalized)
      && path.posix.normalize(normalized) === normalized
      && normalized !== '..'
      && !normalized.startsWith('../'),
    `${context} must be a normalized relative path`,
  );
  return normalized;
}

function samePath(left, right) {
  const normalize = value => (
    process.platform === 'win32' ? path.resolve(value).toLowerCase() : path.resolve(value)
  );
  return normalize(left) === normalize(right);
}

function isInside(candidate, parent) {
  const relative = path.relative(path.resolve(parent), path.resolve(candidate));
  return relative !== ''
    && relative !== '..'
    && !relative.startsWith(`..${path.sep}`)
    && !path.isAbsolute(relative);
}

function isInsideOrSame(candidate, parent) {
  return samePath(candidate, parent) || isInside(candidate, parent);
}

function requireNoLinkedPathComponents(candidate, allowedRoot, context) {
  const resolvedRoot = path.resolve(allowedRoot);
  const resolvedCandidate = path.resolve(candidate);
  requireCondition(existsSync(resolvedRoot), `${context} root is missing`);
  requireCondition(existsSync(resolvedCandidate), `${context} is missing`);
  requireCondition(!lstatSync(resolvedRoot).isSymbolicLink(), `${context} root must not be linked`);
  requireCondition(!lstatSync(resolvedCandidate).isSymbolicLink(), `${context} must not be linked`);
  const canonicalRoot = realpathSync.native(resolvedRoot);
  const canonicalCandidate = realpathSync.native(resolvedCandidate);
  requireCondition(isInsideOrSame(canonicalCandidate, canonicalRoot), `${context} is outside its allowed root`);
  const lexicalRelative = path.relative(resolvedRoot, resolvedCandidate);
  const lexicalInside = lexicalRelative === ''
    || (lexicalRelative !== '..' && !lexicalRelative.startsWith(`..${path.sep}`) && !path.isAbsolute(lexicalRelative));
  let current = lexicalInside ? resolvedRoot : canonicalRoot;
  const relative = lexicalInside ? lexicalRelative : path.relative(canonicalRoot, canonicalCandidate);
  for (const component of relative.split(path.sep).filter(Boolean)) {
    current = path.join(current, component);
    requireCondition(existsSync(current), `${context} component is missing: ${current}`);
    requireCondition(!lstatSync(current).isSymbolicLink(), `${context} contains a symlink or junction`);
    requireCondition(
      isInsideOrSame(realpathSync.native(current), canonicalRoot),
      `${context} escapes its allowed root`,
    );
  }
}

function requireSafeRegularFile(filePath, allowedRoot, context) {
  requireNoLinkedPathComponents(filePath, allowedRoot, context);
  const info = lstatSync(filePath);
  requireCondition(info.isFile() && !info.isSymbolicLink(), `${context} must be a regular file`);
  return filePath;
}

function requireSafeDirectory(directoryPath, allowedRoot, context) {
  requireNoLinkedPathComponents(directoryPath, allowedRoot, context);
  const info = lstatSync(directoryPath);
  requireCondition(info.isDirectory() && !info.isSymbolicLink(), `${context} must be a regular directory`);
  return directoryPath;
}

function normalizedPhysicalPathHash(value) {
  const physical = realpathSync.native(path.resolve(value));
  return sha256(process.platform === 'win32' ? physical.toLowerCase() : physical);
}

function fileIdentity(filePath, allowedRoot, context) {
  requireSafeRegularFile(filePath, allowedRoot, context);
  const bytes = readFileSync(filePath);
  return {
    sha256: sha256(bytes),
    bytes: bytes.length,
  };
}

requireCondition(samePath(repoRoot, behaviorRepoRoot), 'Execution wrapper and behavior verifier roots differ');
const loadedWrapperBytes = readFileSync(scriptPath);
const loadedWrapperIdentity = {
  sha256: sha256(loadedWrapperBytes),
  bytes: loadedWrapperBytes.length,
};
const loadedNodePath = realpathSync.native(process.execPath);
const loadedNodeIdentity = {
  realpath_sha256: normalizedPhysicalPathHash(loadedNodePath),
  ...fileIdentity(loadedNodePath, path.dirname(loadedNodePath), 'Loaded Node executable'),
};

function addDirectoryAncestors(relativePath, directories) {
  let current = path.posix.dirname(relativePath.replaceAll('\\', '/'));
  while (current !== '.' && current !== '') {
    directories.add(current);
    current = path.posix.dirname(current);
  }
}

function visitFiles(root, current, records, directories) {
  const info = lstatSync(current);
  const relativePath = path.relative(root, current).replaceAll('\\', '/');
  requireCondition(!info.isSymbolicLink(), `Snapshot input contains a symlink or junction: ${relativePath}`);
  if (info.isFile()) {
    const bytes = readFileSync(current);
    records.push({ path: relativePath, sha256: sha256(bytes), bytes: bytes.length });
    return;
  }
  requireCondition(info.isDirectory(), `Snapshot input has an unsupported entry: ${relativePath}`);
  if (relativePath) {
    directories.add(relativePath);
    addDirectoryAncestors(relativePath, directories);
  }
  for (const entry of readdirSync(current, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
    visitFiles(root, path.join(current, entry.name), records, directories);
  }
}

export function validateExecutionWorkspaceManifest(document) {
  requireExactKeys(document, ['schema_version', 'directories', 'files'], 'execution workspace manifest');
  requireCondition(document.schema_version === executionWorkspaceVersion, 'execution workspace manifest schema mismatch');
  requireCondition(Array.isArray(document.directories), 'execution workspace manifest directories must be an array');
  const directories = new Set();
  for (const [index, directory] of document.directories.entries()) {
    const relativePath = requireNormalizedRelativePath(directory, `execution workspace directory ${index}`);
    requireCondition(!directories.has(relativePath), 'execution workspace directories must be unique');
    directories.add(relativePath);
  }
  requireCondition(
    isDeepStrictEqual(document.directories, [...directories].sort()),
    'execution workspace directories must be sorted',
  );
  requireCondition(Array.isArray(document.files) && document.files.length > 0, 'execution workspace manifest needs files');
  const paths = new Set();
  for (const [index, file] of document.files.entries()) {
    const context = `execution workspace file ${index}`;
    requireExactKeys(file, ['path', 'sha256', 'bytes'], context);
    const relativePath = requireNormalizedRelativePath(file.path, `${context}.path`);
    requireCondition(!paths.has(relativePath), 'execution workspace file paths must be unique');
    paths.add(relativePath);
    requireSha256(file.sha256, `${context}.sha256`);
    requireNonNegativeInteger(file.bytes, `${context}.bytes`);
  }
  requireCondition(
    isDeepStrictEqual(document.files.map(file => file.path), [...paths].sort()),
    'execution workspace files must be sorted',
  );
  return document;
}

export function buildExecutionWorkspaceManifest({ root = repoRoot } = {}) {
  const resolvedRoot = path.resolve(root);
  requireSafeDirectory(resolvedRoot, path.dirname(resolvedRoot), 'Execution workspace root');
  const records = [];
  const directories = new Set();
  for (const relativeItem of fixedSnapshotItems) {
    requireNormalizedRelativePath(relativeItem, 'fixed snapshot item');
    const itemPath = path.join(resolvedRoot, ...relativeItem.split('/'));
    requireCondition(existsSync(itemPath), `Execution snapshot input is missing: ${relativeItem}`);
    requireNoLinkedPathComponents(itemPath, resolvedRoot, `Execution snapshot input ${relativeItem}`);
    visitFiles(resolvedRoot, itemPath, records, directories);
  }
  for (const record of records) addDirectoryAncestors(record.path, directories);
  records.sort((left, right) => (left.path < right.path ? -1 : left.path > right.path ? 1 : 0));
  return executionWorkspaceManifestFromRecords(records, [...directories].sort());
}

function executionWorkspaceManifestFromRecords(records, directories) {
  const document = validateExecutionWorkspaceManifest({
    schema_version: executionWorkspaceVersion,
    directories,
    files: records,
  });
  return { document, sha256: sha256(jsonText(document)) };
}

export function buildFullExecutionWorkspaceManifest({ root } = {}) {
  const resolvedRoot = path.resolve(root);
  requireSafeDirectory(resolvedRoot, resolvedRoot, 'Frozen execution workspace root');
  const records = [];
  const directories = new Set();
  visitFiles(resolvedRoot, resolvedRoot, records, directories);
  records.sort((left, right) => (left.path < right.path ? -1 : left.path > right.path ? 1 : 0));
  return executionWorkspaceManifestFromRecords(records, [...directories].sort());
}

function createExecutionSandbox(sourceManifest) {
  const container = mkdtempSync(path.join(tmpdir(), snapshotPrefix));
  const workspace = path.join(container, 'workspace');
  const runtimeTemp = path.join(container, 'runtime-temp');
  mkdirSync(workspace, { recursive: true });
  mkdirSync(runtimeTemp, { recursive: true });
  try {
    for (const relativeItem of fixedSnapshotItems) {
      const source = path.join(repoRoot, ...relativeItem.split('/'));
      const destination = path.join(workspace, ...relativeItem.split('/'));
      mkdirSync(path.dirname(destination), { recursive: true });
      cpSync(source, destination, {
        recursive: true,
        dereference: false,
        force: false,
        errorOnExist: true,
      });
    }
    const copied = buildFullExecutionWorkspaceManifest({ root: workspace });
    requireCondition(
      isDeepStrictEqual(copied.document, sourceManifest.document),
      'Frozen execution workspace does not match source allowlist',
    );
    return { container, workspace, runtimeTemp, manifest: copied };
  } catch (error) {
    cleanupExecutionSandbox(container);
    throw error;
  }
}

function cleanupExecutionSandbox(container) {
  if (!container || !existsSync(container)) return;
  const relative = path.relative(tmpdir(), container);
  requireCondition(
    relative !== ''
      && !relative.startsWith(`..${path.sep}`)
      && !path.isAbsolute(relative)
      && path.basename(container).startsWith(snapshotPrefix)
      && !lstatSync(container).isSymbolicLink(),
    'Refusing to remove an unsafe execution sandbox',
  );
  rmSync(container, { recursive: true, force: true });
}

function buildArchiveTarget(verification) {
  requireCondition(
    verification.content_status === 'PASS'
      && verification.archive_seal_status === 'SEALED'
      && verification.verifier_identity_status === 'MATCH'
      && verification.reproducibility_status === 'PASS',
    'Archive/verifier preflight is not fully reproducible',
  );
  return {
    archive_path_sha256: normalizedPhysicalPathHash(verification.archiveDir),
    archive_manifest_sha256: verification.archive_manifest_sha256,
    source_ledger_sha256: verification.source_ledger_sha256,
    archive_seal_sha256: verification.archive_seal_sha256,
    verifier_receipt_sha256: verification.verifier_receipt_sha256,
    verifier_profile_sha256: verification.current_verifier_profile_sha256,
    verified_counts: verification.verified_counts,
  };
}

function validateCounts(document, context) {
  requireExactKeys(document, ['runs', 'files', 'bytes', 'seals'], context);
  for (const field of ['runs', 'files', 'bytes', 'seals']) {
    requireNonNegativeInteger(document[field], `${context}.${field}`);
  }
  return document;
}

function validateArchiveTarget(document) {
  requireExactKeys(
    document,
    [
      'archive_path_sha256',
      'archive_manifest_sha256',
      'source_ledger_sha256',
      'archive_seal_sha256',
      'verifier_receipt_sha256',
      'verifier_profile_sha256',
      'verified_counts',
    ],
    'execution archive target',
  );
  for (const field of [
    'archive_path_sha256',
    'archive_manifest_sha256',
    'source_ledger_sha256',
    'archive_seal_sha256',
    'verifier_receipt_sha256',
    'verifier_profile_sha256',
  ]) {
    requireSha256(document[field], `execution archive target.${field}`);
  }
  validateCounts(document.verified_counts, 'execution archive target.verified_counts');
  return document;
}

export function buildSanitizedEnvironment(runtimeTemp, baseEnvironment = process.env) {
  const environment = {};
  for (const key of ['SystemRoot', 'WINDIR', 'ComSpec', 'PATHEXT']) {
    if (typeof baseEnvironment[key] === 'string' && baseEnvironment[key] !== '') {
      environment[key] = baseEnvironment[key];
    }
  }
  Object.assign(environment, {
    TEMP: runtimeTemp,
    TMP: runtimeTemp,
    TMPDIR: runtimeTemp,
    NO_COLOR: '1',
    FORCE_COLOR: '0',
    NODE_DISABLE_COLORS: '1',
    TZ: 'UTC',
    CI: '1',
    SUXI_TEST_EXECUTION_RECEIPT: '1',
  });
  const descriptor = {
    policy_id: executionEnvironmentPolicyId,
    inherited_system_value_hashes: Object.fromEntries(
      ['SystemRoot', 'WINDIR', 'ComSpec', 'PATHEXT']
        .filter(key => environment[key])
        .map(key => [key, sha256(environment[key])]),
    ),
    private_runtime_temp: true,
    fixed_values: {
      NO_COLOR: '1',
      FORCE_COLOR: '0',
      NODE_DISABLE_COLORS: '1',
      TZ: 'UTC',
      CI: '1',
      SUXI_TEST_EXECUTION_RECEIPT: '1',
    },
  };
  return { environment, sha256: sha256(jsonText(descriptor)) };
}

function currentWrapperContractIdentity() {
  const relativePath = 'tests/automation/suxi_skill_test_execution_receipt.test.mjs';
  const filePath = path.join(repoRoot, ...relativePath.split('/'));
  return { path: relativePath, ...fileIdentity(filePath, repoRoot, 'Execution wrapper contract test') };
}

function currentNodeDiskIdentity() {
  return {
    realpath_sha256: normalizedPhysicalPathHash(loadedNodePath),
    ...fileIdentity(loadedNodePath, path.dirname(loadedNodePath), 'Current Node executable'),
  };
}

function currentWrapperDiskIdentity() {
  return fileIdentity(scriptPath, repoRoot, 'Current execution wrapper');
}

function validateRuntime(document) {
  requireExactKeys(document, ['node_version', 'v8_version', 'platform', 'arch'], 'execution runtime');
  for (const field of ['node_version', 'v8_version', 'platform', 'arch']) {
    nonEmptyString(document[field], `execution runtime.${field}`);
  }
  return document;
}

function validateIdentityFile(document, context, expectedPath = '') {
  requireExactKeys(document, ['path', 'sha256', 'bytes'], context);
  const relativePath = requireNormalizedRelativePath(document.path, `${context}.path`);
  if (expectedPath) requireCondition(relativePath === expectedPath, `${context}.path mismatch`);
  requireSha256(document.sha256, `${context}.sha256`);
  requireNonNegativeInteger(document.bytes, `${context}.bytes`);
  requireCondition(document.bytes > 0, `${context}.bytes must be positive`);
  return document;
}

function validateNodeIdentity(document) {
  requireExactKeys(document, ['realpath_sha256', 'sha256', 'bytes'], 'execution Node identity');
  requireSha256(document.realpath_sha256, 'execution Node identity.realpath_sha256');
  requireSha256(document.sha256, 'execution Node identity.sha256');
  requireNonNegativeInteger(document.bytes, 'execution Node identity.bytes');
  requireCondition(document.bytes > 0, 'execution Node identity.bytes must be positive');
  return document;
}

export function validateExecutionProfile(document) {
  requireExactKeys(
    document,
    [
      'schema_version',
      'wrapper',
      'wrapper_contract_test',
      'node_executable',
      'runtime',
      'command_argv',
      'command_argv_sha256',
      'cwd_policy_id',
      'shell',
      'environment_policy_id',
      'environment_sha256',
      'timeout_ms',
      'output_limit_bytes',
      'workspace_manifest_sha256',
    ],
    'execution profile',
  );
  requireCondition(document.schema_version === executionProfileVersion, 'execution profile schema mismatch');
  validateIdentityFile(document.wrapper, 'execution profile.wrapper', 'scripts/suxi_skill_test_execution.mjs');
  validateIdentityFile(
    document.wrapper_contract_test,
    'execution profile.wrapper_contract_test',
    'tests/automation/suxi_skill_test_execution_receipt.test.mjs',
  );
  validateNodeIdentity(document.node_executable);
  validateRuntime(document.runtime);
  requireCondition(isDeepStrictEqual(document.command_argv, fixedTestArgs), 'execution profile argv mismatch');
  requireCondition(
    document.command_argv_sha256 === sha256(jsonText(fixedTestArgs)),
    'execution profile argv hash mismatch',
  );
  requireCondition(
    document.cwd_policy_id === 'suxi.private_frozen_workspace.v1',
    'execution profile cwd policy mismatch',
  );
  requireCondition(document.shell === false, 'execution profile must use shell=false');
  requireCondition(
    document.environment_policy_id === executionEnvironmentPolicyId,
    'execution profile environment policy mismatch',
  );
  requireSha256(document.environment_sha256, 'execution profile.environment_sha256');
  requireCondition(document.timeout_ms === executionTimeoutMs, 'execution profile timeout mismatch');
  requireCondition(document.output_limit_bytes === executionOutputLimitBytes, 'execution profile output limit mismatch');
  requireSha256(document.workspace_manifest_sha256, 'execution profile.workspace_manifest_sha256');
  return document;
}

function buildExecutionProfile(workspaceManifestSha256, environmentSha256) {
  const document = validateExecutionProfile({
    schema_version: executionProfileVersion,
    wrapper: {
      path: 'scripts/suxi_skill_test_execution.mjs',
      ...loadedWrapperIdentity,
    },
    wrapper_contract_test: currentWrapperContractIdentity(),
    node_executable: loadedNodeIdentity,
    runtime: {
      node_version: process.version,
      v8_version: process.versions.v8,
      platform: process.platform,
      arch: process.arch,
    },
    command_argv: [...fixedTestArgs],
    command_argv_sha256: sha256(jsonText(fixedTestArgs)),
    cwd_policy_id: 'suxi.private_frozen_workspace.v1',
    shell: false,
    environment_policy_id: executionEnvironmentPolicyId,
    environment_sha256: environmentSha256,
    timeout_ms: executionTimeoutMs,
    output_limit_bytes: executionOutputLimitBytes,
    workspace_manifest_sha256: workspaceManifestSha256,
  });
  return { document, sha256: sha256(jsonText(document)) };
}

function requireIsoTimestamp(value, context) {
  nonEmptyString(value, context);
  requireCondition(new Date(value).toISOString() === value, `${context} must be canonical UTC ISO-8601`);
  return value;
}

export function parseFixedTapSummary(stdoutBuffer) {
  requireCondition(Buffer.isBuffer(stdoutBuffer), 'TAP stdout must be a Buffer');
  const text = stdoutBuffer.toString('utf8').replaceAll('\r\n', '\n');
  requireCondition(!text.includes('\u001b'), 'TAP output must not contain ANSI escape sequences');
  const lines = text.split('\n');
  requireCondition(lines[0] === 'TAP version 13', 'TAP output must start with TAP version 13');

  const resultRows = [];
  const plans = [];
  const summary = {};
  const summaryLines = {};
  let durationMs = null;
  let durationLine = -1;
  for (const [index, line] of lines.entries()) {
    const resultMatch = line.match(/^(ok|not ok) (\d+) - /u);
    if (resultMatch) {
      resultRows.push({
        status: resultMatch[1],
        number: Number(resultMatch[2]),
        line: index,
      });
    }
    const planMatch = line.match(/^1\.\.(\d+)$/u);
    if (planMatch) plans.push({ count: Number(planMatch[1]), line: index });
    const summaryMatch = line.match(/^# (tests|suites|pass|fail|cancelled|skipped|todo) (\d+)$/u);
    if (summaryMatch) {
      requireCondition(summary[summaryMatch[1]] === undefined, `TAP summary duplicates ${summaryMatch[1]}`);
      summary[summaryMatch[1]] = Number(summaryMatch[2]);
      summaryLines[summaryMatch[1]] = index;
    }
    const durationMatch = line.match(/^# duration_ms ([0-9]+(?:\.[0-9]+)?)$/u);
    if (durationMatch) {
      requireCondition(durationMs === null, 'TAP summary duplicates duration_ms');
      durationMs = Number(durationMatch[1]);
      durationLine = index;
    }
  }
  requireCondition(plans.length === 1, 'TAP output must contain exactly one top-level plan');
  requireCondition(resultRows.length === expectedTapSummary.tests, 'TAP top-level result count mismatch');
  requireCondition(
    resultRows.every((row, index) => row.number === index + 1),
    'TAP top-level result numbers must be sequential',
  );
  requireCondition(resultRows.every(row => row.status === 'ok'), 'TAP output contains a top-level not ok');
  requireCondition(plans[0].count === expectedTapSummary.tests, 'TAP plan count mismatch');
  requireCondition(
    plans[0].line > resultRows[resultRows.length - 1].line,
    'TAP plan must follow every top-level result',
  );
  for (const [field, expected] of Object.entries(expectedTapSummary)) {
    requireCondition(summary[field] === expected, `TAP summary ${field}=${summary[field]} expected ${expected}`);
    requireCondition(summaryLines[field] > plans[0].line, `TAP summary ${field} must follow the final plan`);
  }
  requireCondition(Number.isFinite(durationMs) && durationMs >= 0, 'TAP summary duration_ms is missing');
  requireCondition(durationLine > plans[0].line, 'TAP duration must follow the final plan');
  requireCondition(
    lines.slice(durationLine + 1).every(line => line === ''),
    'TAP output contains non-empty data after the final summary',
  );
  return { ...summary, duration_ms: durationMs };
}

function validateTapSummary(document) {
  requireExactKeys(
    document,
    ['tests', 'suites', 'pass', 'fail', 'cancelled', 'skipped', 'todo', 'duration_ms'],
    'execution TAP summary',
  );
  for (const field of ['tests', 'suites', 'pass', 'fail', 'cancelled', 'skipped', 'todo']) {
    requireNonNegativeInteger(document[field], `execution TAP summary.${field}`);
  }
  requireCondition(Number.isFinite(document.duration_ms) && document.duration_ms >= 0, 'execution TAP summary.duration_ms is invalid');
  return document;
}

function validateOutputObservation(document, context) {
  requireExactKeys(document, ['sha256', 'bytes', 'complete'], context);
  requireSha256(document.sha256, `${context}.sha256`);
  requireNonNegativeInteger(document.bytes, `${context}.bytes`);
  requireCondition(typeof document.complete === 'boolean', `${context}.complete must be boolean`);
  return document;
}

export function validateExecutionStartedReceipt(document) {
  requireExactKeys(
    document,
    [
      'schema_version',
      'execution_id',
      'attempt_number',
      'previous_attempt_receipt_sha256',
      'started_at',
      'target',
      'execution_profile',
      'execution_profile_sha256',
      'workspace_manifest_sha256',
      'workspace_path_sha256',
      'preflight_snapshot_sha256',
    ],
    'execution started receipt',
  );
  requireCondition(document.schema_version === executionStartedVersion, 'execution started receipt schema mismatch');
  requireCondition(/^[a-f0-9-]{36}$/u.test(document.execution_id), 'execution started receipt.execution_id is invalid');
  requireCondition(Number.isInteger(document.attempt_number) && document.attempt_number > 0, 'execution started receipt.attempt_number is invalid');
  if (document.previous_attempt_receipt_sha256 !== null) {
    requireSha256(document.previous_attempt_receipt_sha256, 'execution started receipt.previous_attempt_receipt_sha256');
  }
  requireIsoTimestamp(document.started_at, 'execution started receipt.started_at');
  validateArchiveTarget(document.target);
  validateExecutionProfile(document.execution_profile);
  requireSha256(document.execution_profile_sha256, 'execution started receipt.execution_profile_sha256');
  requireCondition(
    document.execution_profile_sha256 === sha256(jsonText(document.execution_profile)),
    'execution started receipt profile hash mismatch',
  );
  requireSha256(document.workspace_manifest_sha256, 'execution started receipt.workspace_manifest_sha256');
  requireCondition(
    document.workspace_manifest_sha256 === document.execution_profile.workspace_manifest_sha256,
    'execution started receipt workspace hash mismatch',
  );
  requireSha256(document.workspace_path_sha256, 'execution started receipt.workspace_path_sha256');
  requireSha256(document.preflight_snapshot_sha256, 'execution started receipt.preflight_snapshot_sha256');
  return document;
}

export function validateExecutionLock(document) {
  requireExactKeys(
    document,
    [
      'schema_version',
      'execution_id',
      'started_at',
      'owner_pid',
      'wrapper_sha256',
      'expected_previous_attempts',
      'expected_previous_latest_result_sha256',
      'target',
      'execution_profile',
      'execution_profile_sha256',
      'workspace_manifest_sha256',
      'workspace_path_sha256',
      'preflight_snapshot_sha256',
    ],
    'execution lock',
  );
  requireCondition(document.schema_version === executionLockVersion, 'execution lock schema mismatch');
  requireCondition(/^[a-f0-9-]{36}$/u.test(document.execution_id), 'execution lock.execution_id is invalid');
  requireIsoTimestamp(document.started_at, 'execution lock.started_at');
  requireCondition(Number.isInteger(document.owner_pid) && document.owner_pid > 0, 'execution lock.owner_pid is invalid');
  requireSha256(document.wrapper_sha256, 'execution lock.wrapper_sha256');
  validateExpectedHead(
    document.expected_previous_attempts,
    document.expected_previous_latest_result_sha256,
    { allowUnanchored: false },
  );
  validateArchiveTarget(document.target);
  validateExecutionProfile(document.execution_profile);
  requireCondition(
    document.wrapper_sha256 === document.execution_profile.wrapper.sha256,
    'execution lock wrapper hash differs from execution profile',
  );
  requireSha256(document.execution_profile_sha256, 'execution lock.execution_profile_sha256');
  requireCondition(
    document.execution_profile_sha256 === sha256(jsonText(document.execution_profile)),
    'execution lock profile hash mismatch',
  );
  requireSha256(document.workspace_manifest_sha256, 'execution lock.workspace_manifest_sha256');
  requireCondition(
    document.workspace_manifest_sha256 === document.execution_profile.workspace_manifest_sha256,
    'execution lock workspace hash mismatch',
  );
  requireSha256(document.workspace_path_sha256, 'execution lock.workspace_path_sha256');
  requireSha256(document.preflight_snapshot_sha256, 'execution lock.preflight_snapshot_sha256');
  return document;
}

const terminalStatuses = new Set([
  'PASS',
  'FAIL',
  'TIMEOUT',
  'OUTPUT_LIMIT',
  'SIGNALLED',
  'ERROR',
]);

export function validateExecutionResultReceipt(document) {
  requireExactKeys(
    document,
    [
      'schema_version',
      'execution_id',
      'attempt_number',
      'started_receipt_sha256',
      'previous_attempt_receipt_sha256',
      'started_at',
      'ended_at',
      'duration_ms',
      'target',
      'execution_profile_sha256',
      'workspace_manifest_sha256',
      'workspace_path_sha256',
      'preflight_snapshot_sha256',
      'postflight_snapshot_sha256',
      'artifact_stability_status',
      'result',
    ],
    'execution result receipt',
  );
  requireCondition(document.schema_version === executionResultVersion, 'execution result receipt schema mismatch');
  requireCondition(/^[a-f0-9-]{36}$/u.test(document.execution_id), 'execution result receipt.execution_id is invalid');
  requireCondition(Number.isInteger(document.attempt_number) && document.attempt_number > 0, 'execution result receipt.attempt_number is invalid');
  requireSha256(document.started_receipt_sha256, 'execution result receipt.started_receipt_sha256');
  if (document.previous_attempt_receipt_sha256 !== null) {
    requireSha256(document.previous_attempt_receipt_sha256, 'execution result receipt.previous_attempt_receipt_sha256');
  }
  requireIsoTimestamp(document.started_at, 'execution result receipt.started_at');
  requireIsoTimestamp(document.ended_at, 'execution result receipt.ended_at');
  requireCondition(
    Date.parse(document.ended_at) >= Date.parse(document.started_at),
    'execution result receipt ended_at precedes started_at',
  );
  requireCondition(Number.isFinite(document.duration_ms) && document.duration_ms >= 0, 'execution result receipt.duration_ms is invalid');
  validateArchiveTarget(document.target);
  requireSha256(document.execution_profile_sha256, 'execution result receipt.execution_profile_sha256');
  requireSha256(document.workspace_manifest_sha256, 'execution result receipt.workspace_manifest_sha256');
  requireSha256(document.workspace_path_sha256, 'execution result receipt.workspace_path_sha256');
  requireSha256(document.preflight_snapshot_sha256, 'execution result receipt.preflight_snapshot_sha256');
  requireSha256(document.postflight_snapshot_sha256, 'execution result receipt.postflight_snapshot_sha256');
  requireCondition(['PASS', 'FAIL'].includes(document.artifact_stability_status), 'execution result receipt artifact stability is invalid');

  const result = document.result;
  requireExactKeys(
    result,
    [
      'status',
      'failure_code',
      'exit_code',
      'signal',
      'timeout_triggered',
      'output_limit_triggered',
      'kill_method',
      'process_tree_kill_confirmed',
      'child_pid',
      'stdout',
      'stderr',
      'tap_summary',
    ],
    'execution result',
  );
  requireCondition(terminalStatuses.has(result.status), 'execution result.status is invalid');
  nonEmptyString(result.failure_code, 'execution result.failure_code');
  requireCondition(result.exit_code === null || Number.isInteger(result.exit_code), 'execution result.exit_code is invalid');
  requireCondition(result.signal === null || typeof result.signal === 'string', 'execution result.signal is invalid');
  requireCondition(typeof result.timeout_triggered === 'boolean', 'execution result.timeout_triggered must be boolean');
  requireCondition(typeof result.output_limit_triggered === 'boolean', 'execution result.output_limit_triggered must be boolean');
  requireCondition(['none', 'spawn_sync_kill', 'taskkill_tree', 'posix_process_group'].includes(result.kill_method), 'execution result.kill_method is invalid');
  requireCondition(typeof result.process_tree_kill_confirmed === 'boolean', 'execution result.process_tree_kill_confirmed must be boolean');
  requireCondition(result.child_pid === null || (Number.isInteger(result.child_pid) && result.child_pid > 0), 'execution result.child_pid is invalid');
  validateOutputObservation(result.stdout, 'execution result.stdout');
  validateOutputObservation(result.stderr, 'execution result.stderr');
  if (result.tap_summary !== null) validateTapSummary(result.tap_summary);

  if (result.status === 'PASS') {
    requireCondition(result.failure_code === 'none', 'PASS execution must have failure_code=none');
    requireCondition(result.exit_code === 0 && result.signal === null, 'PASS execution needs exit code 0 and no signal');
    requireCondition(!result.timeout_triggered && !result.output_limit_triggered, 'PASS execution cannot be truncated or timed out');
    requireCondition(result.stdout.complete && result.stderr.complete, 'PASS execution needs complete output');
    requireCondition(result.stderr.bytes === 0, 'PASS execution requires empty stderr');
    requireCondition(result.stdout.bytes > 0, 'PASS execution requires non-empty stdout');
    requireCondition(result.child_pid !== null, 'PASS execution requires an observed child pid');
    requireCondition(result.kill_method === 'none', 'PASS execution must not require a kill method');
    requireCondition(result.process_tree_kill_confirmed, 'PASS execution process-tree state must be complete');
    requireCondition(result.tap_summary !== null, 'PASS execution needs a validated TAP summary');
    for (const [field, expected] of Object.entries(expectedTapSummary)) {
      requireCondition(
        result.tap_summary[field] === expected,
        `PASS execution TAP ${field}=${result.tap_summary[field]} expected ${expected}`,
      );
    }
    requireCondition(document.artifact_stability_status === 'PASS', 'PASS execution needs stable artifacts');
    requireCondition(
      document.preflight_snapshot_sha256 === document.postflight_snapshot_sha256,
      'PASS execution needs matching pre/post snapshots',
    );
  }
  return document;
}

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function writeNewJson(filePath, document) {
  mkdirSync(path.dirname(filePath), { recursive: true });
  writeFileSync(filePath, jsonText(document), { encoding: 'utf8', flag: 'wx' });
}

function executionSeriesId(target, profile) {
  return sha256(jsonText({
    archive_path_sha256: target.archive_path_sha256,
    archive_manifest_sha256: target.archive_manifest_sha256,
    archive_seal_sha256: target.archive_seal_sha256,
    verifier_receipt_sha256: target.verifier_receipt_sha256,
    verifier_profile_sha256: target.verifier_profile_sha256,
    execution_profile_sha256: sha256(jsonText(profile)),
  }));
}

function executionSeriesDirectory(target, profile) {
  const seriesId = executionSeriesId(target, profile);
  return path.join(
    executionReceiptsRoot,
    `${target.archive_manifest_sha256.slice(0, 16)}-${target.verifier_profile_sha256.slice(0, 16)}-${seriesId.slice(0, 24)}`,
  );
}

function attemptPrefix(attemptNumber, executionId) {
  return `${String(attemptNumber).padStart(4, '0')}-${executionId}`;
}

function listAttemptFiles(seriesDir) {
  if (!existsSync(seriesDir)) return [];
  requireSafeDirectory(seriesDir, seriesDir, 'Execution receipt series');
  return readdirSync(seriesDir, { withFileTypes: true })
    .filter(entry => entry.isFile() && !entry.isSymbolicLink() && entry.name.endsWith('.json'))
    .map(entry => entry.name)
    .sort();
}

function receiptFileSha256(filePath, seriesDir, context) {
  requireSafeRegularFile(filePath, seriesDir, context);
  return fileSha256(filePath);
}

function resultStatusPriority(status) {
  return {
    ERROR: 5,
    TIMEOUT: 4,
    OUTPUT_LIMIT: 3,
    SIGNALLED: 2,
    FAIL: 1,
    PASS: 0,
  }[status] ?? 5;
}

export function aggregateExecutionStatuses(statuses) {
  requireCondition(Array.isArray(statuses), 'execution statuses must be an array');
  if (statuses.length === 0) return 'NOT_RUN';
  requireCondition(statuses.every(status => terminalStatuses.has(status)), 'execution statuses contain an invalid value');
  const hasPass = statuses.includes('PASS');
  const hasNonPass = statuses.some(status => status !== 'PASS');
  if (hasPass && hasNonPass) return 'FLAKY';
  if (hasPass) return 'PASS';
  return [...statuses].sort((left, right) => resultStatusPriority(right) - resultStatusPriority(left))[0];
}

function validateExpectedHead(expectedAttempts, expectedLatestResultSha256, { allowUnanchored = true } = {}) {
  const unanchored = expectedAttempts === null && expectedLatestResultSha256 === null;
  if (unanchored) {
    requireCondition(allowUnanchored, 'An external execution head is required');
    return { status: 'UNANCHORED', attempts: null, latest: null };
  }
  requireCondition(Number.isInteger(expectedAttempts) && expectedAttempts >= 0, 'Expected execution attempts must be a non-negative integer');
  if (expectedAttempts === 0) {
    requireCondition(expectedLatestResultSha256 === null, 'A zero-attempt head must use latest=none');
  } else {
    requireSha256(expectedLatestResultSha256, 'Expected latest execution result');
  }
  return {
    status: 'EXPECTED',
    attempts: expectedAttempts,
    latest: expectedLatestResultSha256,
  };
}

function buildExecutionSnapshotDocument({
  target,
  profile,
  liveWorkspaceManifest,
  frozenWorkspaceManifest,
  workspacePathSha256,
}) {
  return {
    target,
    expected_execution_profile_sha256: profile.sha256,
    live_workspace_manifest_sha256: liveWorkspaceManifest.sha256,
    frozen_workspace_manifest_sha256: frozenWorkspaceManifest.sha256,
    workspace_path_sha256: workspacePathSha256,
    wrapper_disk_sha256: currentWrapperDiskIdentity().sha256,
    wrapper_contract_test_sha256: currentWrapperContractIdentity().sha256,
    node_executable_disk_sha256: currentNodeDiskIdentity().sha256,
  };
}

function buildExecutionSnapshotHash(options) {
  return sha256(jsonText(buildExecutionSnapshotDocument(options)));
}

function emptyOutputObservation() {
  return { sha256: sha256(Buffer.alloc(0)), bytes: 0, complete: false };
}

function confirmTimedOutProcessTree(pid, sanitizedEnvironment) {
  if (!Number.isInteger(pid) || pid <= 0) {
    return { method: 'spawn_sync_kill', confirmed: false };
  }
  if (process.platform === 'win32') {
    const systemRoot = process.env.SystemRoot || process.env.WINDIR || '';
    if (!systemRoot) return { method: 'taskkill_tree', confirmed: false };
    const taskkillPath = path.join(systemRoot, 'System32', 'taskkill.exe');
    if (!existsSync(taskkillPath)) return { method: 'taskkill_tree', confirmed: false };
    const killed = spawnSync(taskkillPath, ['/PID', String(pid), '/T', '/F'], {
      env: sanitizedEnvironment,
      windowsHide: true,
      stdio: 'ignore',
      timeout: 5_000,
    });
    return { method: 'taskkill_tree', confirmed: !killed.error && killed.status === 0 };
  }
  try {
    process.kill(-pid, 'SIGKILL');
    return { method: 'posix_process_group', confirmed: true };
  } catch {
    return { method: 'posix_process_group', confirmed: false };
  }
}

export function deriveExecutionTerminalStatus({
  errorCode = '',
  exitCode = 0,
  signal = null,
  stderrBytes = 0,
  tapValid = true,
} = {}) {
  if (errorCode === 'ETIMEDOUT') return { status: 'TIMEOUT', failure_code: 'timeout' };
  if (errorCode === 'ENOBUFS') return { status: 'OUTPUT_LIMIT', failure_code: 'output_limit' };
  if (errorCode) return { status: 'ERROR', failure_code: `spawn_${String(errorCode).toLowerCase()}` };
  if (signal !== null) return { status: 'SIGNALLED', failure_code: 'terminated_by_signal' };
  if (exitCode !== 0) return { status: 'FAIL', failure_code: 'test_exit_nonzero' };
  if (stderrBytes > 0) return { status: 'ERROR', failure_code: 'unexpected_stderr' };
  if (!tapValid) return { status: 'ERROR', failure_code: 'invalid_tap' };
  return { status: 'PASS', failure_code: 'none' };
}

function observeFixedChild(workspace, sanitizedEnvironment) {
  const started = Date.now();
  const child = spawnSync(process.execPath, [...fixedTestArgs], {
    cwd: workspace,
    env: sanitizedEnvironment,
    shell: false,
    encoding: 'buffer',
    windowsHide: true,
    detached: process.platform !== 'win32',
    timeout: executionTimeoutMs,
    killSignal: 'SIGKILL',
    maxBuffer: executionOutputLimitBytes,
  });
  const durationMs = Date.now() - started;
  const stdout = Buffer.isBuffer(child.stdout) ? child.stdout : Buffer.alloc(0);
  const stderr = Buffer.isBuffer(child.stderr) ? child.stderr : Buffer.alloc(0);
  const errorCode = child.error?.code || '';
  const timeoutTriggered = errorCode === 'ETIMEDOUT';
  const outputLimitTriggered = errorCode === 'ENOBUFS';
  const outputComplete = !timeoutTriggered && !outputLimitTriggered && !child.error;
  const termination = timeoutTriggered || outputLimitTriggered
    ? confirmTimedOutProcessTree(child.pid, sanitizedEnvironment)
    : { method: 'none', confirmed: true };
  let tapSummary = null;
  let tapFailure = '';
  if (outputComplete) {
    try {
      tapSummary = parseFixedTapSummary(stdout);
    } catch (error) {
      tapFailure = error.message;
    }
  }

  const terminal = deriveExecutionTerminalStatus({
    errorCode,
    exitCode: Number.isInteger(child.status) ? child.status : null,
    signal: child.signal || null,
    stderrBytes: stderr.length,
    tapValid: Boolean(tapSummary),
  });
  if (!tapSummary && !tapFailure && terminal.status === 'ERROR') {
    terminal.failure_code = 'missing_tap';
  }

  return {
    durationMs,
    result: {
      status: terminal.status,
      failure_code: terminal.failure_code,
      exit_code: Number.isInteger(child.status) ? child.status : null,
      signal: child.signal || null,
      timeout_triggered: timeoutTriggered,
      output_limit_triggered: outputLimitTriggered,
      kill_method: termination.method,
      process_tree_kill_confirmed: termination.confirmed,
      child_pid: Number.isInteger(child.pid) && child.pid > 0 ? child.pid : null,
      stdout: {
        sha256: sha256(stdout),
        bytes: stdout.length,
        complete: outputComplete,
      },
      stderr: {
        sha256: sha256(stderr),
        bytes: stderr.length,
        complete: outputComplete,
      },
      tap_summary: tapSummary,
    },
  };
}

export function verifyExecutionReceiptDirectory({
  seriesDir,
  target,
  profile,
  expectedAttempts = null,
  expectedLatestResultSha256 = null,
  ownedLockExecutionId = '',
  allowedIncompleteExecutionId = '',
} = {}) {
  validateArchiveTarget(target);
  validateExecutionProfile(profile);
  const expectedProfileSha256 = sha256(jsonText(profile));
  const expectedHead = validateExpectedHead(
    expectedAttempts,
    expectedLatestResultSha256,
    { allowUnanchored: true },
  );
  if (!existsSync(seriesDir)) {
    const headAnchorStatus = expectedHead.status === 'UNANCHORED'
      ? 'UNANCHORED'
      : expectedHead.attempts === 0 && expectedHead.latest === null ? 'MATCH' : 'MISMATCH';
    return {
      schema_version: executionVerificationVersion,
      receipt_status: headAnchorStatus === 'MATCH'
        ? 'PASS'
        : headAnchorStatus === 'MISMATCH' ? 'FAIL' : 'UNANCHORED',
      chain_status: 'PASS',
      head_anchor_status: headAnchorStatus,
      test_execution_status: headAnchorStatus === 'MISMATCH' ? 'ERROR' : 'NOT_RUN',
      attempts: 0,
      latest_result_sha256: null,
      verified_test_count: null,
      incomplete_attempt: null,
      failures: [],
    };
  }
  try {
    requireSafeDirectory(seriesDir, seriesDir, 'Execution receipt series');
    const directoryEntries = readdirSync(seriesDir, { withFileTypes: true });
    const lockPresent = directoryEntries.some(entry => entry.name === '.execution.lock');
    const unsupported = directoryEntries.filter(entry => (
      entry.name !== '.execution.lock'
        && (!entry.isFile() || entry.isSymbolicLink() || !/^[0-9]{4}-[a-f0-9-]{36}\.(started|result)\.json$/u.test(entry.name))
    ));
    requireCondition(unsupported.length === 0, 'Execution receipt series contains unsupported entries');
    if (lockPresent) {
      requireCondition(ownedLockExecutionId !== '', 'Execution receipt series has an active or stale lock');
      const lockPath = path.join(seriesDir, '.execution.lock');
      requireSafeRegularFile(lockPath, seriesDir, 'Execution lock');
      const lock = validateExecutionLock(readJson(lockPath));
      requireCondition(lock.execution_id === ownedLockExecutionId, 'Execution lock belongs to another execution');
      requireCondition(isDeepStrictEqual(lock.target, target), 'Execution lock target differs from current series');
      requireCondition(isDeepStrictEqual(lock.execution_profile, profile), 'Execution lock profile differs from current series');
    } else {
      requireCondition(ownedLockExecutionId === '', 'Owned execution lock disappeared before head verification');
    }
    const names = listAttemptFiles(seriesDir);
    const prefixes = [...new Set(names.map(name => name.replace(/\.(started|result)\.json$/u, '')))].sort();
    const statuses = [];
    const attempts = [];
    let incompleteAttempt = null;
    let expectedPreviousResultSha256 = null;
    for (const [index, prefix] of prefixes.entries()) {
      const attemptNumber = index + 1;
      const startedPath = path.join(seriesDir, `${prefix}.started.json`);
      const resultPath = path.join(seriesDir, `${prefix}.result.json`);
      requireCondition(existsSync(startedPath), `Execution attempt ${attemptNumber} is missing STARTED receipt`);
      const started = validateExecutionStartedReceipt(readJson(startedPath));
      const startedSha256 = receiptFileSha256(startedPath, seriesDir, 'Execution STARTED receipt');
      requireCondition(started.attempt_number === attemptNumber, 'Execution STARTED attempt sequence mismatch');
      requireCondition(prefix === attemptPrefix(attemptNumber, started.execution_id), 'Execution receipt filename mismatch');
      requireCondition(
        started.previous_attempt_receipt_sha256 === expectedPreviousResultSha256,
        'Execution STARTED hash chain mismatch',
      );
      requireCondition(isDeepStrictEqual(started.target, target), 'Execution receipt target does not match current archive');
      requireCondition(isDeepStrictEqual(started.execution_profile, profile), 'Execution receipt profile does not match current executor');
      requireCondition(started.execution_profile_sha256 === expectedProfileSha256, 'Execution receipt profile hash mismatch');
      if (!existsSync(resultPath)) {
        requireCondition(
          allowedIncompleteExecutionId !== ''
            && started.execution_id === allowedIncompleteExecutionId
            && index === prefixes.length - 1,
          `Execution attempt ${attemptNumber} is incomplete`,
        );
        incompleteAttempt = {
          attempt_number: attemptNumber,
          execution_id: started.execution_id,
          started_receipt_sha256: startedSha256,
        };
        continue;
      }
      const result = validateExecutionResultReceipt(readJson(resultPath));
      const resultSha256 = receiptFileSha256(resultPath, seriesDir, 'Execution result receipt');
      requireCondition(result.attempt_number === attemptNumber, 'Execution result attempt sequence mismatch');
      requireCondition(started.execution_id === result.execution_id, 'Execution receipt ids do not match');
      requireCondition(result.started_receipt_sha256 === startedSha256, 'Execution result does not bind STARTED receipt');
      requireCondition(
        result.previous_attempt_receipt_sha256 === expectedPreviousResultSha256,
        'Execution result hash chain mismatch',
      );
      requireCondition(isDeepStrictEqual(result.target, started.target), 'Execution result target differs from STARTED receipt');
      requireCondition(result.execution_profile_sha256 === started.execution_profile_sha256, 'Execution result profile differs from STARTED receipt');
      requireCondition(result.workspace_manifest_sha256 === started.workspace_manifest_sha256, 'Execution result workspace differs from STARTED receipt');
      requireCondition(result.workspace_path_sha256 === started.workspace_path_sha256, 'Execution result workspace path differs from STARTED receipt');
      requireCondition(result.preflight_snapshot_sha256 === started.preflight_snapshot_sha256, 'Execution result preflight differs from STARTED receipt');
      statuses.push(result.result.status);
      attempts.push({
        attempt_number: attemptNumber,
        execution_id: result.execution_id,
        status: result.result.status,
        artifact_stability_status: result.artifact_stability_status,
        result_receipt_sha256: resultSha256,
      });
      expectedPreviousResultSha256 = resultSha256;
    }
    const aggregateStatus = aggregateExecutionStatuses(statuses);
    const headAnchorStatus = expectedHead.status === 'UNANCHORED'
      ? 'UNANCHORED'
      : expectedHead.attempts === attempts.length
          && expectedHead.latest === expectedPreviousResultSha256
        ? 'MATCH'
        : 'MISMATCH';
    return {
      schema_version: executionVerificationVersion,
      receipt_status: headAnchorStatus === 'MATCH' ? 'PASS' : headAnchorStatus === 'UNANCHORED' ? 'UNANCHORED' : 'FAIL',
      chain_status: 'PASS',
      head_anchor_status: headAnchorStatus,
      test_execution_status: headAnchorStatus === 'MISMATCH' ? 'ERROR' : aggregateStatus,
      attempts: attempts.length,
      latest_result_sha256: expectedPreviousResultSha256,
      verified_test_count: aggregateStatus === 'PASS' && headAnchorStatus === 'MATCH'
        ? expectedTapSummary.tests
        : null,
      attempt_results: attempts,
      incomplete_attempt: incompleteAttempt,
      failures: [],
    };
  } catch (error) {
    return {
      schema_version: executionVerificationVersion,
      receipt_status: 'FAIL',
      chain_status: 'FAIL',
      head_anchor_status: expectedHead.status === 'UNANCHORED' ? 'UNANCHORED' : 'MISMATCH',
      test_execution_status: 'ERROR',
      attempts: 0,
      latest_result_sha256: null,
      verified_test_count: null,
      incomplete_attempt: null,
      failures: [error.message],
    };
  }
}

function currentExecutionContext() {
  const archiveVerification = verifyEvidenceArchive();
  const target = buildArchiveTarget(archiveVerification);
  const sourceManifest = buildExecutionWorkspaceManifest({ root: repoRoot });
  return { archiveVerification, target, sourceManifest };
}

function validateRunExpectations(options, context, profile) {
  const wrapperContract = currentWrapperContractIdentity();
  const checks = [
    ['expectedWrapperSha256', loadedWrapperIdentity.sha256, 'wrapper'],
    ['expectedWrapperTestSha256', wrapperContract.sha256, 'wrapper contract test'],
    ['expectedArchiveSealSha256', context.target.archive_seal_sha256, 'archive seal'],
    ['expectedVerifierReceiptSha256', context.target.verifier_receipt_sha256, 'verifier receipt'],
    ['expectedVerifierProfileSha256', context.target.verifier_profile_sha256, 'verifier profile'],
    ['expectedNodeExecutableSha256', loadedNodeIdentity.sha256, 'Node executable'],
    ['expectedNodeExecutableRealpathSha256', loadedNodeIdentity.realpath_sha256, 'Node executable realpath'],
    ['expectedInputManifestSha256', context.sourceManifest.sha256, 'input manifest'],
    ['expectedExecutionProfileSha256', profile.sha256, 'execution profile'],
  ];
  for (const [field, actual, label] of checks) {
    requireSha256(options[field], `--${field.replace(/[A-Z]/gu, match => `-${match.toLowerCase()}`)}`);
    requireCondition(
      actual === options[field],
      `Execution ${label} hash ${actual} does not match expected ${options[field]}`,
    );
  }
  requireCondition(profile.document.wrapper.sha256 === options.expectedWrapperSha256, 'Execution profile wrapper mismatch');
}

function acquireExecutionLock(seriesDir, lockDocument) {
  validateExecutionLock(lockDocument);
  mkdirSync(executionReceiptsRoot, { recursive: true });
  requireNoLinkedPathComponents(executionReceiptsRoot, repoRoot, 'Execution receipts root');
  mkdirSync(seriesDir, { recursive: true });
  requireSafeDirectory(seriesDir, executionReceiptsRoot, 'Execution receipt series');
  const lockPath = path.join(seriesDir, '.execution.lock');
  try {
    writeFileSync(lockPath, jsonText(lockDocument), {
      encoding: 'utf8',
      flag: 'wx',
    });
  } catch (error) {
    if (error.code === 'EEXIST') throw new Error('Another execution attempt is active or left a stale lock');
    throw error;
  }
  requireSafeRegularFile(lockPath, seriesDir, 'Execution lock');
  return lockPath;
}

function releaseExecutionLock(lockPath, seriesDir) {
  if (!lockPath || !existsSync(lockPath)) return;
  requireSafeRegularFile(lockPath, seriesDir, 'Execution lock');
  unlinkSync(lockPath);
}

export function probeProcessLiveness(pid) {
  requireCondition(Number.isInteger(pid) && pid > 0, 'Process liveness pid is invalid');
  try {
    process.kill(pid, 0);
    return 'ALIVE';
  } catch (error) {
    if (error.code === 'ESRCH') return 'ABSENT';
    return 'UNKNOWN';
  }
}

function loadExecutionRecoveryState(seriesDir) {
  requireSafeDirectory(seriesDir, seriesDir, 'Recovery receipt series');
  const lockPath = path.join(seriesDir, '.execution.lock');
  if (!existsSync(lockPath)) {
    return {
      state: 'NO_LOCK',
      seriesDir,
      lockPath,
      lockSha256: null,
      lock: null,
      startedPath: '',
      resultPath: '',
      owner_liveness: 'N/A',
    };
  }
  requireSafeRegularFile(lockPath, seriesDir, 'Recovery execution lock');
  const lockSha256 = fileSha256(lockPath);
  const lockDocument = readJson(lockPath);
  if (isDeepStrictEqual(Object.keys(lockDocument).sort(), ['execution_id', 'started_at'])) {
    requireCondition(/^[a-f0-9-]{36}$/u.test(lockDocument.execution_id), 'legacy execution lock id is invalid');
    requireIsoTimestamp(lockDocument.started_at, 'legacy execution lock.started_at');
    return {
      state: 'BLOCKED_LEGACY_LOCK',
      phase: 'LEGACY',
      reason: 'legacy_lock_missing_owner_and_head_binding',
      seriesDir,
      lockPath,
      lockSha256,
      lock: lockDocument,
      startedPath: '',
      resultPath: '',
      owner_liveness: 'UNKNOWN',
    };
  }
  const lock = validateExecutionLock(lockDocument);
  const names = listAttemptFiles(seriesDir);
  const startedName = names.find(name => name.endsWith(`-${lock.execution_id}.started.json`)) || '';
  const resultName = names.find(name => name.endsWith(`-${lock.execution_id}.result.json`)) || '';
  requireCondition(!(resultName && !startedName), 'Recovery series has result without STARTED receipt');
  const startedPath = startedName ? path.join(seriesDir, startedName) : '';
  const resultPath = resultName
    ? path.join(seriesDir, resultName)
    : startedName
      ? path.join(seriesDir, startedName.replace(/\.started\.json$/u, '.result.json'))
      : '';
  if (startedPath) {
    const started = validateExecutionStartedReceipt(readJson(startedPath));
    requireCondition(started.execution_id === lock.execution_id, 'Recovery STARTED execution id differs from lock');
    requireCondition(started.started_at === lock.started_at, 'Recovery STARTED timestamp differs from lock');
    requireCondition(started.attempt_number === lock.expected_previous_attempts + 1, 'Recovery STARTED attempt differs from lock seed');
    requireCondition(
      started.previous_attempt_receipt_sha256 === lock.expected_previous_latest_result_sha256,
      'Recovery STARTED previous head differs from lock seed',
    );
    requireCondition(isDeepStrictEqual(started.target, lock.target), 'Recovery STARTED target differs from lock');
    requireCondition(isDeepStrictEqual(started.execution_profile, lock.execution_profile), 'Recovery STARTED profile differs from lock');
    requireCondition(started.workspace_path_sha256 === lock.workspace_path_sha256, 'Recovery STARTED workspace path differs from lock');
    requireCondition(started.preflight_snapshot_sha256 === lock.preflight_snapshot_sha256, 'Recovery STARTED preflight differs from lock');
  }
  const phase = resultName ? 'TERMINAL' : startedPath ? 'INCOMPLETE_ATTEMPT' : 'PRESTART';
  const ownerLiveness = probeProcessLiveness(lock.owner_pid);
  const state = ownerLiveness === 'ALIVE'
    ? 'ACTIVE'
    : ownerLiveness === 'UNKNOWN'
      ? 'BLOCKED_UNKNOWN_OWNER'
      : `RECOVERABLE_${phase}`;
  return {
    state,
    phase,
    seriesDir,
    lockPath,
    lockSha256,
    lock,
    startedPath,
    resultPath,
    owner_liveness: ownerLiveness,
    reason: '',
  };
}

export function inspectExecutionRecovery({ seriesDir } = {}) {
  const observed = loadExecutionRecoveryState(seriesDir);
  return {
    schema_version: 'suxi.skill.behavior_test_execution_recovery_inspection.v1',
    state: observed.state,
    phase: observed.phase || 'N/A',
    series_id: path.basename(observed.seriesDir),
    lock_sha256: observed.lockSha256,
    execution_id: observed.lock?.execution_id || null,
    owner_pid: observed.lock?.owner_pid || null,
    owner_liveness: observed.owner_liveness,
    has_started_receipt: Boolean(observed.startedPath),
    has_result_receipt: Boolean(observed.resultPath),
    expected_previous_attempts: observed.lock?.expected_previous_attempts ?? null,
    expected_previous_latest_result_sha256: observed.lock?.expected_previous_latest_result_sha256 ?? null,
    reason: observed.reason || '',
    evidence_boundary: 'This is a read-only local liveness and receipt-shape observation. ALIVE or UNKNOWN owners are never recoverable; PID liveness is not cryptographic process identity.',
  };
}

export function inspectExecutionRecoveryCatalog({
  root = executionReceiptsRoot,
  allowExternalRoot = false,
} = {}) {
  const resolvedRoot = path.resolve(root);
  if (!existsSync(resolvedRoot)) {
    return {
      schema_version: 'suxi.skill.behavior_test_execution_recovery_catalog.v1',
      status: 'NO_LOCKS',
      recoveries: [],
    };
  }
  if (!allowExternalRoot) {
    requireCondition(samePath(resolvedRoot, executionReceiptsRoot), 'Recovery catalog root must be fixed');
  }
  requireSafeDirectory(resolvedRoot, resolvedRoot, 'Execution receipts root');
  const recoveries = [];
  for (const entry of readdirSync(resolvedRoot, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) {
      recoveries.push({
        schema_version: 'suxi.skill.behavior_test_execution_recovery_inspection.v1',
        state: 'BLOCKED_INVALID_LOCK',
        phase: 'INVALID',
        series_id: entry.name,
        lock_sha256: null,
        execution_id: null,
        owner_pid: null,
        owner_liveness: 'UNKNOWN',
        has_started_receipt: false,
        has_result_receipt: false,
        expected_previous_attempts: null,
        expected_previous_latest_result_sha256: null,
        reason: 'linked_series_is_forbidden',
        evidence_boundary: 'Linked series are reported and never traversed, recovered, or deleted.',
      });
      continue;
    }
    if (!entry.isDirectory()) continue;
    const seriesDir = path.join(resolvedRoot, entry.name);
    if (existsSync(path.join(seriesDir, '.execution.lock'))) {
      try {
        recoveries.push(inspectExecutionRecovery({ seriesDir }));
      } catch {
        const lockPath = path.join(seriesDir, '.execution.lock');
        let lockSha256 = null;
        try {
          requireSafeRegularFile(lockPath, seriesDir, 'Invalid recovery lock');
          lockSha256 = fileSha256(lockPath);
        } catch {
          lockSha256 = null;
        }
        recoveries.push({
          schema_version: 'suxi.skill.behavior_test_execution_recovery_inspection.v1',
          state: 'BLOCKED_INVALID_LOCK',
          phase: 'INVALID',
          series_id: entry.name,
          lock_sha256: lockSha256,
          execution_id: null,
          owner_pid: null,
          owner_liveness: 'UNKNOWN',
          has_started_receipt: false,
          has_result_receipt: false,
          expected_previous_attempts: null,
          expected_previous_latest_result_sha256: null,
          reason: 'invalid_lock_schema_or_path',
          evidence_boundary: 'Invalid or unsafe locks are reported per series and are never auto-recovered or deleted.',
        });
      }
    }
  }
  return {
    schema_version: 'suxi.skill.behavior_test_execution_recovery_catalog.v1',
    status: recoveries.length === 0 ? 'NO_LOCKS' : 'ATTENTION',
    recoveries,
  };
}

export function inspectCurrentExecutionRecoveries() {
  return inspectExecutionRecoveryCatalog({ root: executionReceiptsRoot });
}

function recoveredIncompleteObservation() {
  return {
    status: 'ERROR',
    failure_code: 'recovered_incomplete_attempt',
    exit_code: null,
    signal: null,
    timeout_triggered: false,
    output_limit_triggered: false,
    kill_method: 'none',
    process_tree_kill_confirmed: false,
    child_pid: null,
    stdout: emptyOutputObservation(),
    stderr: emptyOutputObservation(),
    tap_summary: null,
  };
}

export function recoverExecutionSeries({
  seriesDir,
  expectedLockSha256,
  expectedPreviousAttempts,
  expectedPreviousLatestResultSha256,
  expectedRecoveryWrapperSha256,
  allowExternalSeriesPath = false,
} = {}) {
  requireSha256(expectedLockSha256, '--expected-lock-sha256');
  requireSha256(expectedRecoveryWrapperSha256, '--expected-recovery-wrapper-sha256');
  requireCondition(
    expectedRecoveryWrapperSha256 === loadedWrapperIdentity.sha256,
    `Recovery wrapper hash ${loadedWrapperIdentity.sha256} does not match expected ${expectedRecoveryWrapperSha256}`,
  );
  validateExpectedHead(
    expectedPreviousAttempts,
    expectedPreviousLatestResultSha256,
    { allowUnanchored: false },
  );
  const resolvedSeriesDir = path.resolve(seriesDir);
  if (!allowExternalSeriesPath) {
    requireCondition(isInside(resolvedSeriesDir, executionReceiptsRoot), 'Recovery series must stay under the fixed receipts root');
  }
  const observed = loadExecutionRecoveryState(resolvedSeriesDir);
  requireCondition(observed.state.startsWith('RECOVERABLE_'), `Execution recovery is not allowed in state ${observed.state}`);
  requireCondition(observed.lockSha256 === expectedLockSha256, 'Execution recovery lock hash does not match expected');
  const lock = observed.lock;
  requireCondition(lock.expected_previous_attempts === expectedPreviousAttempts, 'Execution recovery attempt head differs from lock');
  requireCondition(
    lock.expected_previous_latest_result_sha256 === expectedPreviousLatestResultSha256,
    'Execution recovery result head differs from lock',
  );
  const prefixVerification = verifyExecutionReceiptDirectory({
    seriesDir: resolvedSeriesDir,
    target: lock.target,
    profile: lock.execution_profile,
    expectedAttempts: observed.phase === 'TERMINAL' ? null : expectedPreviousAttempts,
    expectedLatestResultSha256: observed.phase === 'TERMINAL'
      ? null
      : expectedPreviousLatestResultSha256,
    ownedLockExecutionId: lock.execution_id,
    allowedIncompleteExecutionId: observed.phase === 'INCOMPLETE_ATTEMPT'
      ? lock.execution_id
      : '',
  });
  if (observed.phase === 'TERMINAL') {
    requireCondition(
      prefixVerification.chain_status === 'PASS'
        && prefixVerification.attempts === expectedPreviousAttempts + 1,
      `Recovery terminal chain is invalid: ${prefixVerification.failures.join('; ')}`,
    );
  } else {
    requireCondition(prefixVerification.receipt_status === 'PASS', `Recovery previous head is invalid: ${prefixVerification.failures.join('; ')}`);
  }

  let startedPath = observed.startedPath;
  let resultPath = observed.resultPath;
  let createdStarted = false;
  let createdResult = false;
  let resultReceiptSha256 = resultPath && existsSync(resultPath)
    ? fileSha256(resultPath)
    : null;
  let releaseLock = false;
  try {
    if (observed.phase === 'PRESTART') {
      const started = buildStartedReceipt({
        executionId: lock.execution_id,
        attemptNumber: expectedPreviousAttempts + 1,
        previousResultSha256: expectedPreviousLatestResultSha256,
        startedAt: lock.started_at,
        target: lock.target,
        profile: {
          document: lock.execution_profile,
          sha256: lock.execution_profile_sha256,
        },
        workspacePathSha256: lock.workspace_path_sha256,
        preflightSnapshotSha256: lock.preflight_snapshot_sha256,
      });
      const prefix = attemptPrefix(started.attempt_number, started.execution_id);
      startedPath = path.join(resolvedSeriesDir, `${prefix}.started.json`);
      resultPath = path.join(resolvedSeriesDir, `${prefix}.result.json`);
      writeNewJson(startedPath, started);
      createdStarted = true;
    }
    if (!resultPath || !existsSync(resultPath)) {
      const started = validateExecutionStartedReceipt(readJson(startedPath));
      const startedSha256 = fileSha256(startedPath);
      const terminal = buildTerminalReceipt({
        started,
        startedSha256,
        endedAt: new Date().toISOString(),
        durationMs: Math.max(0, Date.now() - Date.parse(started.started_at)),
        postflightSnapshotSha256: sha256(jsonText({
          status: 'RECOVERED_INCOMPLETE_ATTEMPT',
          lock_sha256: observed.lockSha256,
          recovery_wrapper_sha256: loadedWrapperIdentity.sha256,
        })),
        artifactStable: false,
        observedResult: recoveredIncompleteObservation(),
      });
      writeNewJson(resultPath, terminal);
      createdResult = true;
      resultReceiptSha256 = fileSha256(resultPath);
    }
    const newAttempts = observed.phase === 'TERMINAL'
      ? prefixVerification.attempts
      : expectedPreviousAttempts + 1;
    const newHeadSha256 = observed.phase === 'TERMINAL'
      ? prefixVerification.latest_result_sha256
      : resultReceiptSha256;
    const completed = verifyExecutionReceiptDirectory({
      seriesDir: resolvedSeriesDir,
      target: lock.target,
      profile: lock.execution_profile,
      expectedAttempts: newAttempts,
      expectedLatestResultSha256: newHeadSha256,
      ownedLockExecutionId: lock.execution_id,
    });
    requireCondition(completed.receipt_status === 'PASS', `Recovered execution head is invalid: ${completed.failures.join('; ')}`);
    releaseLock = true;
    return {
      schema_version: 'suxi.skill.behavior_test_execution_recovery.v1',
      status: 'RECOVERED',
      recovered_phase: observed.phase,
      execution_id: lock.execution_id,
      created_started_receipt: createdStarted,
      created_result_receipt: createdResult,
      result_receipt_path: resultPath || null,
      result_receipt_sha256: newHeadSha256,
      next_expected_attempts: newAttempts,
      next_expected_latest_result_sha256: newHeadSha256,
      test_execution_status: completed.test_execution_status,
      verified_test_count: null,
      evidence_boundary: 'Recovery is allowed only after the lock owner PID is confirmed absent and the external previous head and lock hash match. Incomplete attempts become append-only ERROR results; recovery never deletes STARTED or result receipts and does not convert later success into a pure PASS.',
    };
  } finally {
    if (releaseLock) releaseExecutionLock(observed.lockPath, resolvedSeriesDir);
  }
}

function buildCurrentProfile(sourceManifest) {
  const environment = buildSanitizedEnvironment('<private-runtime-temp>');
  return buildExecutionProfile(sourceManifest.sha256, environment.sha256);
}

function requireLoadedIdentitiesStable() {
  requireCondition(
    isDeepStrictEqual(currentWrapperDiskIdentity(), loadedWrapperIdentity),
    'Execution wrapper disk bytes differ from the loaded module',
  );
  requireCondition(
    isDeepStrictEqual(currentNodeDiskIdentity(), loadedNodeIdentity),
    'Node executable disk bytes differ from the loaded process identity',
  );
}

export function inspectFixedExecutionInputs() {
  requireLoadedIdentitiesStable();
  const context = currentExecutionContext();
  const profile = buildCurrentProfile(context.sourceManifest);
  return {
    schema_version: 'suxi.skill.behavior_test_execution_inputs.v1',
    status: 'READY',
    expected_archive_seal_sha256: context.target.archive_seal_sha256,
    expected_verifier_receipt_sha256: context.target.verifier_receipt_sha256,
    expected_verifier_profile_sha256: context.target.verifier_profile_sha256,
    expected_wrapper_sha256: loadedWrapperIdentity.sha256,
    expected_wrapper_test_sha256: profile.document.wrapper_contract_test.sha256,
    expected_node_executable_sha256: loadedNodeIdentity.sha256,
    expected_node_executable_realpath_sha256: loadedNodeIdentity.realpath_sha256,
    expected_input_manifest_sha256: context.sourceManifest.sha256,
    execution_profile_sha256: profile.sha256,
    command_argv: [...fixedTestArgs],
    environment_policy_id: executionEnvironmentPolicyId,
    evidence_boundary: 'This read-only inspection reports current local identities. It does not execute tests and must not be used as proof that tests ran.',
  };
}

function buildStartedReceipt({
  executionId,
  attemptNumber,
  previousResultSha256,
  startedAt,
  target,
  profile,
  workspacePathSha256,
  preflightSnapshotSha256,
}) {
  return validateExecutionStartedReceipt({
    schema_version: executionStartedVersion,
    execution_id: executionId,
    attempt_number: attemptNumber,
    previous_attempt_receipt_sha256: previousResultSha256,
    started_at: startedAt,
    target,
    execution_profile: profile.document,
    execution_profile_sha256: profile.sha256,
    workspace_manifest_sha256: profile.document.workspace_manifest_sha256,
    workspace_path_sha256: workspacePathSha256,
    preflight_snapshot_sha256: preflightSnapshotSha256,
  });
}

function buildTerminalReceipt({
  started,
  startedSha256,
  endedAt,
  durationMs,
  postflightSnapshotSha256,
  artifactStable,
  observedResult,
}) {
  const result = structuredClone(observedResult);
  if (!artifactStable) {
    if (result.status === 'PASS') result.status = 'FAIL';
    result.failure_code = result.failure_code === 'none'
      ? 'artifact_drift'
      : `${result.failure_code}_and_artifact_drift`;
  }
  return validateExecutionResultReceipt({
    schema_version: executionResultVersion,
    execution_id: started.execution_id,
    attempt_number: started.attempt_number,
    started_receipt_sha256: startedSha256,
    previous_attempt_receipt_sha256: started.previous_attempt_receipt_sha256,
    started_at: started.started_at,
    ended_at: endedAt,
    duration_ms: durationMs,
    target: started.target,
    execution_profile_sha256: started.execution_profile_sha256,
    workspace_manifest_sha256: started.workspace_manifest_sha256,
    workspace_path_sha256: started.workspace_path_sha256,
    preflight_snapshot_sha256: started.preflight_snapshot_sha256,
    postflight_snapshot_sha256: postflightSnapshotSha256,
    artifact_stability_status: artifactStable ? 'PASS' : 'FAIL',
    result,
  });
}

function buildWrapperErrorObservation() {
  return {
    status: 'ERROR',
    failure_code: 'wrapper_exception',
    exit_code: null,
    signal: null,
    timeout_triggered: false,
    output_limit_triggered: false,
    kill_method: 'none',
    process_tree_kill_confirmed: false,
    child_pid: null,
    stdout: emptyOutputObservation(),
    stderr: emptyOutputObservation(),
    tap_summary: null,
  };
}

export function runFixedTestExecution(options = {}) {
  const overallStarted = Date.now();
  requireLoadedIdentitiesStable();
  const context = currentExecutionContext();
  const sandbox = createExecutionSandbox(context.sourceManifest);
  try {
    const sanitized = buildSanitizedEnvironment(sandbox.runtimeTemp);
    const profile = buildExecutionProfile(sandbox.manifest.sha256, sanitized.sha256);
    validateRunExpectations(options, context, profile);
    const seriesDir = executionSeriesDirectory(context.target, profile.document);
    const executionId = randomUUID().toLowerCase();
    const startedAt = new Date().toISOString();
    const workspacePathSha256 = normalizedPhysicalPathHash(sandbox.workspace);
    const preflightSnapshotSha256 = buildExecutionSnapshotHash({
      target: context.target,
      profile,
      liveWorkspaceManifest: context.sourceManifest,
      frozenWorkspaceManifest: sandbox.manifest,
      workspacePathSha256,
    });
    const lockDocument = validateExecutionLock({
      schema_version: executionLockVersion,
      execution_id: executionId,
      started_at: startedAt,
      owner_pid: process.pid,
      wrapper_sha256: loadedWrapperIdentity.sha256,
      expected_previous_attempts: options.expectedPreviousAttempts,
      expected_previous_latest_result_sha256: options.expectedPreviousLatestResultSha256,
      target: context.target,
      execution_profile: profile.document,
      execution_profile_sha256: profile.sha256,
      workspace_manifest_sha256: profile.document.workspace_manifest_sha256,
      workspace_path_sha256: workspacePathSha256,
      preflight_snapshot_sha256: preflightSnapshotSha256,
    });
    let lockPath = '';
    let attemptNumber = 0;
    let started = null;
    let startedPath = '';
    let resultPath = '';
    let startedSha256 = '';
    let terminal = null;
    let executionError = null;
    let releaseLock = false;
    try {
      lockPath = acquireExecutionLock(seriesDir, lockDocument);
      try {
      const existing = verifyExecutionReceiptDirectory({
        seriesDir,
        target: context.target,
        profile: profile.document,
        expectedAttempts: options.expectedPreviousAttempts,
        expectedLatestResultSha256: options.expectedPreviousLatestResultSha256,
        ownedLockExecutionId: executionId,
      });
      requireCondition(existing.receipt_status === 'PASS', `Existing execution receipt chain is invalid: ${existing.failures.join('; ')}`);
      attemptNumber = existing.attempts + 1;
      started = buildStartedReceipt({
        executionId,
        attemptNumber,
        previousResultSha256: existing.latest_result_sha256,
        startedAt,
        target: context.target,
        profile,
        workspacePathSha256,
        preflightSnapshotSha256,
      });
      const prefix = attemptPrefix(attemptNumber, executionId);
      startedPath = path.join(seriesDir, `${prefix}.started.json`);
      resultPath = path.join(seriesDir, `${prefix}.result.json`);
      writeNewJson(startedPath, started);
      startedSha256 = receiptFileSha256(startedPath, seriesDir, 'Execution STARTED receipt');
      const observed = observeFixedChild(sandbox.workspace, sanitized.environment);
      let artifactStable = false;
      let postflightSnapshotSha256 = sha256(jsonText({ status: 'POSTFLIGHT_NOT_RUN' }));
      try {
        const postContext = currentExecutionContext();
        const postLiveManifest = postContext.sourceManifest;
        const postFrozenManifest = buildFullExecutionWorkspaceManifest({ root: sandbox.workspace });
        postflightSnapshotSha256 = buildExecutionSnapshotHash({
          target: postContext.target,
          profile,
          liveWorkspaceManifest: postLiveManifest,
          frozenWorkspaceManifest: postFrozenManifest,
          workspacePathSha256,
        });
        artifactStable = postflightSnapshotSha256 === preflightSnapshotSha256
          && isDeepStrictEqual(postContext.target, context.target)
          && isDeepStrictEqual(postLiveManifest.document, context.sourceManifest.document)
          && isDeepStrictEqual(postFrozenManifest.document, sandbox.manifest.document)
          && isDeepStrictEqual(currentWrapperDiskIdentity(), loadedWrapperIdentity)
          && isDeepStrictEqual(currentNodeDiskIdentity(), loadedNodeIdentity);
      } catch {
        postflightSnapshotSha256 = sha256(jsonText({ status: 'POSTFLIGHT_ERROR' }));
        artifactStable = false;
      }
      terminal = buildTerminalReceipt({
        started,
        startedSha256,
        endedAt: new Date().toISOString(),
        durationMs: Date.now() - overallStarted,
        postflightSnapshotSha256,
        artifactStable,
        observedResult: observed.result,
      });
      writeNewJson(resultPath, terminal);
      validateExecutionResultReceipt(readJson(resultPath));
    } catch (error) {
      executionError = error;
      if (started && startedSha256 && resultPath && !existsSync(resultPath)) {
        try {
          terminal = buildTerminalReceipt({
            started,
            startedSha256,
            endedAt: new Date().toISOString(),
            durationMs: Date.now() - overallStarted,
            postflightSnapshotSha256: sha256(jsonText({ status: 'WRAPPER_EXCEPTION' })),
            artifactStable: false,
            observedResult: buildWrapperErrorObservation(),
          });
          writeNewJson(resultPath, terminal);
        } catch {
          terminal = null;
        }
      }
      }
      if (executionError && !terminal) {
        releaseLock = !(startedPath && existsSync(startedPath));
        throw executionError;
      }
      const resultReceiptSha256 = resultPath && existsSync(resultPath)
        ? receiptFileSha256(resultPath, seriesDir, 'Execution result receipt')
        : null;
      const verified = verifyExecutionReceiptDirectory({
        seriesDir,
        target: context.target,
        profile: profile.document,
        expectedAttempts: attemptNumber,
        expectedLatestResultSha256: resultReceiptSha256,
        ownedLockExecutionId: executionId,
      });
      requireCondition(verified.receipt_status === 'PASS', `New execution head failed verification: ${verified.failures.join('; ')}`);
      releaseLock = true;
      return {
        schema_version: 'suxi.skill.behavior_test_execution_run.v1',
        status: verified.test_execution_status,
        attempt_status: terminal?.result.status || 'ERROR',
        receipt_status: 'LOCAL_HEAD_WRITTEN',
        head_anchor_status: 'REQUIRES_EXTERNAL_CONFIRMATION',
        test_execution_status: verified.test_execution_status,
        artifact_stability_status: terminal?.artifact_stability_status || 'FAIL',
        execution_id: executionId,
        attempt_number: attemptNumber,
        result_receipt_path: resultPath,
        result_receipt_sha256: resultReceiptSha256,
        next_expected_attempts: attemptNumber,
        next_expected_latest_result_sha256: resultReceiptSha256,
        tap_summary: terminal?.result.tap_summary || null,
        stdout_sha256: terminal?.result.stdout.sha256 || null,
        stderr_sha256: terminal?.result.stderr.sha256 || null,
        verified_test_count: null,
        locally_observed_test_count: terminal?.result.tap_summary?.tests || null,
        evidence_boundary: 'This append-only local receipt records one fixed Node test child observation from a frozen allowlist workspace. It preserves failures and binds exit/signal, strict TAP summary, output digests, archive/verifier identity, wrapper, Node binary, environment policy, and pre/post artifact stability. The newly written head must be confirmed by a later verifier call using next_expected_attempts and next_expected_latest_result_sha256 before verified_test_count can be non-null. It is not a signature or remote execution proof and does not verify judge identity, deployment, field behavior, or resistance to a same-account actor who can rewrite every local artifact.',
      };
    } finally {
      if (releaseLock) releaseExecutionLock(lockPath, seriesDir);
    }
  } finally {
    cleanupExecutionSandbox(sandbox.container);
  }
}

export function verifyCurrentTestExecution({
  expectedAttempts = null,
  expectedLatestResultSha256 = null,
} = {}) {
  requireLoadedIdentitiesStable();
  const context = currentExecutionContext();
  const profile = buildCurrentProfile(context.sourceManifest);
  const seriesDir = executionSeriesDirectory(context.target, profile.document);
  return {
    ...verifyExecutionReceiptDirectory({
      seriesDir,
      target: context.target,
      profile: profile.document,
      expectedAttempts,
      expectedLatestResultSha256,
    }),
    series_path: seriesDir,
    archive_manifest_sha256: context.target.archive_manifest_sha256,
    verifier_receipt_sha256: context.target.verifier_receipt_sha256,
    verifier_profile_sha256: context.target.verifier_profile_sha256,
    execution_profile_sha256: profile.sha256,
    evidence_boundary: 'This verifies the append-only local attempt chain for the current archive, verifier, wrapper, Node runtime, fixed tests, and input manifest. PASS is local execution evidence only; it is not a signature, remote attestation, judge-identity proof, deployment proof, or field validation.',
  };
}

export function parseExecutionArgs(argv) {
  const [command = 'help', ...tokens] = argv;
  const options = {
    command: ['--help', '-h'].includes(command) ? 'help' : command,
    expectedWrapperSha256: '',
    expectedWrapperTestSha256: '',
    expectedArchiveSealSha256: '',
    expectedVerifierReceiptSha256: '',
    expectedVerifierProfileSha256: '',
    expectedNodeExecutableSha256: '',
    expectedNodeExecutableRealpathSha256: '',
    expectedInputManifestSha256: '',
    expectedExecutionProfileSha256: '',
    expectedPreviousAttempts: undefined,
    expectedPreviousLatestResultSha256: undefined,
    expectedAttempts: undefined,
    expectedLatestResultSha256: undefined,
    seriesId: '',
    expectedLockSha256: '',
    expectedRecoveryWrapperSha256: '',
  };
  const mappings = [
    ['--expected-wrapper-sha256=', 'expectedWrapperSha256'],
    ['--expected-wrapper-test-sha256=', 'expectedWrapperTestSha256'],
    ['--expected-archive-seal-sha256=', 'expectedArchiveSealSha256'],
    ['--expected-verifier-receipt-sha256=', 'expectedVerifierReceiptSha256'],
    ['--expected-verifier-profile-sha256=', 'expectedVerifierProfileSha256'],
    ['--expected-node-executable-sha256=', 'expectedNodeExecutableSha256'],
    ['--expected-node-executable-realpath-sha256=', 'expectedNodeExecutableRealpathSha256'],
    ['--expected-input-manifest-sha256=', 'expectedInputManifestSha256'],
    ['--expected-execution-profile-sha256=', 'expectedExecutionProfileSha256'],
  ];
  for (const token of tokens) {
    if (token.startsWith('--series-id=')) {
      options.seriesId = token.slice('--series-id='.length).trim();
    } else if (token.startsWith('--expected-lock-sha256=')) {
      options.expectedLockSha256 = token.slice('--expected-lock-sha256='.length).trim();
    } else if (token.startsWith('--expected-recovery-wrapper-sha256=')) {
      options.expectedRecoveryWrapperSha256 = token.slice('--expected-recovery-wrapper-sha256='.length).trim();
    } else if (token.startsWith('--expected-previous-attempts=')) {
      const value = token.slice('--expected-previous-attempts='.length);
      requireCondition(/^\d+$/u.test(value), '--expected-previous-attempts must be a non-negative integer');
      options.expectedPreviousAttempts = Number(value);
    } else if (token.startsWith('--expected-previous-latest-result-sha256=')) {
      const value = token.slice('--expected-previous-latest-result-sha256='.length).trim();
      options.expectedPreviousLatestResultSha256 = value === 'none' ? null : value;
    } else if (token.startsWith('--expected-attempts=')) {
      const value = token.slice('--expected-attempts='.length);
      requireCondition(/^\d+$/u.test(value), '--expected-attempts must be a non-negative integer');
      options.expectedAttempts = Number(value);
    } else if (token.startsWith('--expected-latest-result-sha256=')) {
      const value = token.slice('--expected-latest-result-sha256='.length).trim();
      options.expectedLatestResultSha256 = value === 'none' ? null : value;
    } else {
      const mapping = mappings.find(([prefix]) => token.startsWith(prefix));
      if (mapping) {
      options[mapping[1]] = token.slice(mapping[0].length).trim();
      } else if (token === '--help' || token === '-h') {
        options.command = 'help';
      } else {
        throw new Error(`Unknown argument: ${token}`);
      }
    }
  }
  requireCondition(
    ['help', 'inspect', 'verify', 'inspect-recovery', 'recover', 'run'].includes(options.command),
    `Unknown command: ${options.command}`,
  );
  const hashFields = [
    'expectedWrapperSha256',
    'expectedWrapperTestSha256',
    'expectedArchiveSealSha256',
    'expectedVerifierReceiptSha256',
    'expectedVerifierProfileSha256',
    'expectedNodeExecutableSha256',
    'expectedNodeExecutableRealpathSha256',
    'expectedInputManifestSha256',
    'expectedExecutionProfileSha256',
  ];
  if (options.command === 'run') {
    requireCondition(
      options.expectedPreviousAttempts !== undefined
        && options.expectedPreviousLatestResultSha256 !== undefined,
      'run requires an external previous execution head',
    );
    validateExpectedHead(
      options.expectedPreviousAttempts,
      options.expectedPreviousLatestResultSha256,
      { allowUnanchored: false },
    );
    requireCondition(
      options.seriesId === ''
        && options.expectedLockSha256 === ''
        && options.expectedRecoveryWrapperSha256 === '',
      'run does not accept recovery arguments',
    );
  } else if (options.command === 'recover') {
    requireCondition(/^[A-Za-z0-9._-]+$/u.test(options.seriesId), 'recover requires a safe --series-id');
    requireSha256(options.expectedLockSha256, '--expected-lock-sha256');
    requireSha256(options.expectedRecoveryWrapperSha256, '--expected-recovery-wrapper-sha256');
    requireCondition(
      options.expectedPreviousAttempts !== undefined
        && options.expectedPreviousLatestResultSha256 !== undefined,
      'recover requires an external previous execution head',
    );
    validateExpectedHead(
      options.expectedPreviousAttempts,
      options.expectedPreviousLatestResultSha256,
      { allowUnanchored: false },
    );
    requireCondition(hashFields.every(field => options[field] === ''), 'recover does not accept execution input hashes');
    requireCondition(
      options.expectedAttempts === undefined && options.expectedLatestResultSha256 === undefined,
      'recover does not accept verification-head arguments',
    );
  } else if (options.command === 'verify') {
    const bothMissing = options.expectedAttempts === undefined
      && options.expectedLatestResultSha256 === undefined;
    const bothPresent = options.expectedAttempts !== undefined
      && options.expectedLatestResultSha256 !== undefined;
    requireCondition(bothMissing || bothPresent, 'verify expected-head arguments must be supplied together');
    if (bothMissing) {
      options.expectedAttempts = null;
      options.expectedLatestResultSha256 = null;
    } else {
      validateExpectedHead(options.expectedAttempts, options.expectedLatestResultSha256);
    }
    requireCondition(hashFields.every(field => options[field] === ''), 'verify does not accept execution input hashes');
    requireCondition(
      options.expectedPreviousAttempts === undefined
        && options.expectedPreviousLatestResultSha256 === undefined,
      'verify does not accept previous-head arguments',
    );
    requireCondition(
      options.seriesId === ''
        && options.expectedLockSha256 === ''
        && options.expectedRecoveryWrapperSha256 === '',
      'verify does not accept recovery arguments',
    );
  } else {
    requireCondition(hashFields.every(field => options[field] === ''), `${options.command} does not accept execution input hashes`);
    requireCondition(
      options.expectedPreviousAttempts === undefined
        && options.expectedPreviousLatestResultSha256 === undefined
        && options.expectedAttempts === undefined
        && options.expectedLatestResultSha256 === undefined,
      `${options.command} does not accept execution-head arguments`,
    );
    requireCondition(
      options.seriesId === ''
        && options.expectedLockSha256 === ''
        && options.expectedRecoveryWrapperSha256 === '',
      `${options.command} does not accept recovery arguments`,
    );
  }
  return options;
}

function printHelp() {
  process.stdout.write('SUXIOS fixed skill-verifier test execution\n\n');
  process.stdout.write('Commands:\n');
  process.stdout.write('  inspect  # read-only current identities; does not execute tests\n');
  process.stdout.write('  inspect-recovery  # read-only catalog of active, blocked, or recoverable locks\n');
  process.stdout.write('  verify [--expected-attempts=N --expected-latest-result-sha256=sha256|none]  # without an external head the chain is UNANCHORED\n');
  process.stdout.write('  recover --series-id=name --expected-lock-sha256=sha256 --expected-recovery-wrapper-sha256=sha256 --expected-previous-attempts=N --expected-previous-latest-result-sha256=sha256|none  # only when owner PID is confirmed absent\n');
  process.stdout.write('  run --expected-previous-attempts=N --expected-previous-latest-result-sha256=sha256|none --expected-wrapper-sha256=... --expected-wrapper-test-sha256=... --expected-archive-seal-sha256=... --expected-verifier-receipt-sha256=... --expected-verifier-profile-sha256=... --expected-node-executable-sha256=... --expected-node-executable-realpath-sha256=... --expected-input-manifest-sha256=... --expected-execution-profile-sha256=...\n');
  process.stdout.write('\nThe run command accepts no custom executable, tests, cwd, reporter, environment, timeout, output limit, or retry policy.\n');
}

function main() {
  try {
    const options = parseExecutionArgs(process.argv.slice(2));
    if (options.command === 'help') {
      printHelp();
      return;
    }
    const result = options.command === 'inspect'
      ? inspectFixedExecutionInputs()
      : options.command === 'inspect-recovery'
        ? inspectCurrentExecutionRecoveries()
      : options.command === 'verify'
        ? verifyCurrentTestExecution({
          expectedAttempts: options.expectedAttempts,
          expectedLatestResultSha256: options.expectedLatestResultSha256,
        })
        : options.command === 'recover'
          ? recoverExecutionSeries({
            seriesDir: path.join(executionReceiptsRoot, options.seriesId),
            expectedLockSha256: options.expectedLockSha256,
            expectedRecoveryWrapperSha256: options.expectedRecoveryWrapperSha256,
            expectedPreviousAttempts: options.expectedPreviousAttempts,
            expectedPreviousLatestResultSha256: options.expectedPreviousLatestResultSha256,
          })
          : runFixedTestExecution(options);
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    const status = result.test_execution_status || result.status;
    if (options.command === 'inspect-recovery' && status === 'NO_LOCKS') process.exitCode = 0;
    else if (options.command === 'inspect-recovery' && status === 'ATTENTION') process.exitCode = 2;
    else if (options.command === 'verify' && result.receipt_status === 'UNANCHORED') process.exitCode = 2;
    else if (options.command === 'verify' && result.receipt_status !== 'PASS') process.exitCode = 1;
    else if (status === 'NOT_RUN') process.exitCode = 2;
    else if (!['PASS', 'READY'].includes(status)) process.exitCode = 1;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({ status: 'ERROR', error: error.message })}\n`);
    process.exitCode = 1;
  }
}

if (process.argv[1] && samePath(process.argv[1], scriptPath)) {
  main();
}
