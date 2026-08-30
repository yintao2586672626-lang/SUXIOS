import { createHash } from 'node:crypto';
import {
  cpSync,
  existsSync,
  lstatSync,
  mkdtempSync,
  mkdirSync,
  readFileSync,
  realpathSync,
  readdirSync,
  renameSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';
import { fileURLToPath } from 'node:url';

const scriptPath = fileURLToPath(import.meta.url);
export const repoRoot = path.resolve(path.dirname(scriptPath), '..');
const loadedRunnerBytes = readFileSync(scriptPath);
const loadedRunnerIdentity = {
  sha256: sha256(loadedRunnerBytes),
  bytes: loadedRunnerBytes.length,
};

export const behaviorContractVersion = 'suxi.skill.behavior_evals.v1';
export const respondentVersion = 'suxi.skill.behavior_response.v1';
export const legacyJudgmentVersion = 'suxi.skill.behavior_judgments.v1';
export const judgmentVersion = 'suxi.skill.behavior_judgments.v2';
export const legacyRunManifestVersion = 'suxi.skill.behavior_run.v1';
export const runManifestVersion = 'suxi.skill.behavior_run.v2';
export const gradeSealVersion = 'suxi.skill.behavior_grade_seal.v1';
export const evidenceLedgerVersion = 'suxi.skill.behavior_evidence_ledger.v1';
export const defaultEvidenceLedgerPath = path.join(repoRoot, 'evals', 'suxi-skill-behavior-evidence.json');
export const evidenceArchiveVersion = 'suxi.skill.behavior_evidence_archive.v1';
export const evidenceArchiveSealVersion = 'suxi.skill.behavior_evidence_archive_seal.v1';
export const verifierProfileVersion = 'suxi.skill.behavior_archive_verifier_profile.v1';
export const verifierReceiptVersion = 'suxi.skill.behavior_archive_verifier_receipt.v1';
export const defaultEvidenceArchiveRoot = path.join(repoRoot, 'evals', 'suxi-skill-behavior-archives');

const verifierProfileFileSpecs = [
  { role: 'runner', path: 'scripts/suxi_skill_behavior_eval.mjs' },
  { role: 'behavior_test', path: 'tests/automation/suxi_skill_behavior_eval.test.mjs' },
  { role: 'contract_test', path: 'tests/automation/suxi_skill_contracts.test.mjs' },
];

const governedSkills = new Map([
  ['suxi-product-decision', {
    snapshotFiles: [
      'SKILL.md',
      'agents/openai.yaml',
      'references/decision-evidence-contract.md',
    ],
    behaviorContract: 'evals/behavior-evals.json',
    dimensions: {
      decision_status: { type: 'enum', values: ['decision_ready', 'provisional', 'blocked'] },
      execution_mode: { type: 'enum', values: ['decision_only', 'spec', 'delivery'] },
      selected_direction: { type: 'enum', values: ['A', 'B', 'C', 'none', 'approved_direction', 'other'] },
      evidence_scope: {
        type: 'enum',
        values: ['synthetic', 'missing', 'provided_decision', 'external_reference', 'provided_requirement', 'unknown'],
      },
      invented_numeric_thresholds: { type: 'boolean' },
      product_or_external_mutation: { type: 'boolean' },
      single_next_action: { type: 'boolean' },
    },
  }],
  ['suxi-test-guard', {
    snapshotFiles: [
      'SKILL.md',
      'agents/openai.yaml',
      'references/acceptance-evidence.md',
    ],
    behaviorContract: 'evals/behavior-evals.json',
    dimensions: {
      overall_status: {
        type: 'enum',
        values: ['PASS', 'FAIL', 'BLOCKED', 'NOT_RUN', 'N/A'],
        description: 'One allowed aggregate status for the named user claim; never invent MIXED or unverified.',
      },
      request_mode: {
        type: 'enum',
        values: ['read_only', 'fix_authorized', 'oracle_change_requested'],
        description: 'read_only covers diagnosis and verification without edits; use the other values only for explicit fix or oracle-change requests.',
      },
      highest_evidence_level: {
        type: 'enum',
        values: ['none', 'syntax', 'unit', 'integration', 'page_api', 'deployment_field'],
        description: 'Highest evidence actually supplied or executed for the named claim, not a planned check.',
      },
      page_claim_status: {
        type: 'enum',
        values: ['PASS', 'FAIL', 'BLOCKED', 'NOT_RUN', 'N/A'],
        description: 'Use N/A when no page/API claim is in scope; NOT_RUN only for an in-scope but unattempted page/API check.',
      },
      first_failure_handling: {
        type: 'enum',
        values: ['preserved', 'overwritten', 'not_applicable'],
        description: 'Use preserved only when a FAIL observation exists and remains visible; use not_applicable when no FAIL was observed.',
      },
      product_or_external_mutation: {
        type: 'boolean',
        description: 'True only when product files/data or an external system were actually mutated.',
      },
      single_next_action: {
        type: 'boolean',
        description: 'True only when the answer gives one next action or explicitly stops.',
      },
    },
  }],
  ['suxi-user-research', {
    snapshotFiles: [
      'SKILL.md',
      'agents/openai.yaml',
    ],
    behaviorContract: 'evals/behavior-evals.json',
    dimensions: {
      research_status: {
        type: 'enum',
        values: ['planned', 'observed_partial', 'synthesized', 'retested', 'not_comparable'],
        description: 'Use only the evidence maturity supported by real supplied records: planned when no participant evidence exists; observed_partial for summaries or incomplete/untraceable records; synthesized only when every key finding can trace to supplied records; retested only for a comparable completed retest; not_comparable when material comparison conditions changed.',
      },
      research_mode: { type: 'enum', values: ['plan', 'synthesize', 'retest'] },
      evidence_scope: {
        type: 'enum',
        values: ['none', 'provided_records', 'screenshot_only', 'not_comparable'],
      },
      outreach_status: {
        type: 'enum',
        values: ['not_requested', 'requested_blocked', 'ready_for_user_execution', 'not_applicable'],
        description: 'External contact is never executed by this evaluation. Use not_requested for a plan where outreach could be a later step but the user did not request execution; requested_blocked when execution was requested but recipients, tenant scope, contact details, or consent prerequisites are missing; ready_for_user_execution only when an explicitly requested execution package is complete but remains for the user to run; not_applicable when the current request only synthesizes supplied records or compares existing rounds and needs no outreach decision.',
      },
      consent_status: {
        type: 'enum',
        values: ['not_required', 'missing', 'confirmed', 'unknown'],
        description: 'Use not_required when the current request performs no outreach or recording, including plan-only work and analysis of already supplied deidentified records; missing when a requested outreach or recording action lacks consent; confirmed only when supplied evidence explicitly confirms consent for that action; unknown only when outreach or recording is in current scope but consent cannot be determined.',
      },
      invented_research_findings: { type: 'boolean' },
      invented_numeric_thresholds: { type: 'boolean' },
      external_or_recording_action: { type: 'boolean' },
      single_next_action: { type: 'boolean' },
    },
  }],
]);
export const behaviorEvalTempRoot = path.join(tmpdir(), 'suxi-skill-behavior-evals');

function snapshotFingerprint(records) {
  return sha256(records.map(item => `${item.path}:${item.sha256}`).join('\n'));
}

function sealPathFor(runDir) {
  const identity = sha256(
    process.platform === 'win32' ? path.resolve(runDir).toLowerCase() : path.resolve(runDir),
  ).slice(0, 24);
  return path.join(path.dirname(path.resolve(runDir)), '.seals', `${path.basename(runDir)}-${identity}.json`);
}

function gradeSealPathForPrepareSeal(prepareSealPath) {
  return prepareSealPath.replace(/\.json$/u, '.grade.json');
}

function dimensionEntries(dimensions) {
  return Object.entries(dimensions);
}

function enumDimensionEntries(dimensions) {
  return dimensionEntries(dimensions).filter(([, definition]) => definition.type === 'enum');
}

function booleanDimensionEntries(dimensions) {
  return dimensionEntries(dimensions).filter(([, definition]) => definition.type === 'boolean');
}

function resolveDimensions(skillName, provided = null) {
  const dimensions = provided || governedSkills.get(skillName)?.dimensions;
  requireCondition(dimensions && typeof dimensions === 'object', `Missing behavior dimensions for ${skillName}`);
  return dimensions;
}

export function behaviorDimensions(skillName) {
  return structuredClone(resolveDimensions(skillName));
}

function requireCondition(condition, message) {
  if (!condition) throw new Error(message);
}

function nonEmptyString(value, context) {
  requireCondition(typeof value === 'string' && value.trim() !== '', `${context} must be a non-empty string`);
  return value.trim();
}

function readJson(filePath) {
  try {
    return JSON.parse(readFileSync(filePath, 'utf8'));
  } catch (error) {
    throw new Error(`Cannot read JSON ${filePath}: ${error.message}`);
  }
}

function tryWriteNewText(filePath, content) {
  mkdirSync(path.dirname(filePath), { recursive: true });
  try {
    writeFileSync(filePath, content, { encoding: 'utf8', flag: 'wx' });
    return true;
  } catch (error) {
    if (error.code === 'EEXIST') return false;
    throw error;
  }
}

function writeNewText(filePath, content) {
  requireCondition(tryWriteNewText(filePath, content), `Refusing to overwrite ${filePath}`);
}

function jsonText(value) {
  return `${JSON.stringify(value, null, 2)}\n`;
}

function writeNewJson(filePath, value) {
  writeNewText(filePath, jsonText(value));
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

function fileSha256(filePath) {
  return sha256(readFileSync(filePath));
}

function safeSegment(value, context) {
  const segment = nonEmptyString(value, context);
  requireCondition(/^[A-Za-z0-9._-]+$/u.test(segment), `${context} contains unsupported characters`);
  return segment;
}

function requireExactKeys(value, expectedKeys, context) {
  const actual = Object.keys(value).sort();
  const expected = [...expectedKeys].sort();
  requireCondition(isDeepStrictEqual(actual, expected), `${context} keys mismatch: ${JSON.stringify(actual)}`);
}

function isPathInside(candidate, parent) {
  const relative = path.relative(path.resolve(parent), path.resolve(candidate));
  return relative !== '' && relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative);
}

function isPathInsideOrSame(candidate, parent) {
  const relative = path.relative(path.resolve(parent), path.resolve(candidate));
  return relative === '' || (relative !== '..' && !relative.startsWith(`..${path.sep}`) && !path.isAbsolute(relative));
}

function samePath(candidate, expected) {
  const normalize = value => (
    process.platform === 'win32' ? path.resolve(value).toLowerCase() : path.resolve(value)
  );
  return normalize(candidate) === normalize(expected);
}

function nearestExistingAncestor(candidate) {
  let current = path.resolve(candidate);
  while (!existsSync(current)) {
    const parent = path.dirname(current);
    requireCondition(parent !== current, `No existing ancestor for ${candidate}`);
    current = parent;
  }
  return current;
}

function requireNoReparseEscape(candidate, allowedRoot, { createAllowedRoot = false } = {}) {
  if (createAllowedRoot) {
    mkdirSync(allowedRoot, { recursive: true });
  } else {
    requireCondition(existsSync(allowedRoot), `Evaluation root is missing: ${allowedRoot}`);
  }
  const resolvedRoot = path.resolve(allowedRoot);
  const resolvedCandidate = path.resolve(candidate);

  const existingAncestor = nearestExistingAncestor(resolvedCandidate);
  const canonicalRoot = realpathSync.native(resolvedRoot);
  const canonicalAncestor = realpathSync.native(existingAncestor);
  requireCondition(
    isPathInsideOrSame(canonicalAncestor, canonicalRoot),
    `Reparse-point ancestor escapes ${resolvedRoot}: ${existingAncestor}`,
  );

  let current = existingAncestor;
  while (true) {
    const info = lstatSync(current);
    requireCondition(!info.isSymbolicLink(), `Symlink or junction ancestor is forbidden: ${current}`);
    const canonicalCurrent = realpathSync.native(current);
    requireCondition(
      isPathInsideOrSame(canonicalCurrent, canonicalRoot),
      `Reparse-point ancestor escapes ${resolvedRoot}: ${current}`,
    );
    if (samePath(canonicalCurrent, canonicalRoot)) break;
    const parent = path.dirname(current);
    requireCondition(parent !== current, `Could not reach eval root from ${existingAncestor}`);
    current = parent;
  }
}

function requireRunPath(candidate, { allowExternalRunDir = false, createAllowedRoot = false } = {}) {
  const resolved = path.resolve(candidate);
  if (!allowExternalRunDir) {
    requireNoReparseEscape(resolved, behaviorEvalTempRoot, { createAllowedRoot });
  }
  return resolved;
}

function requireNoLinkedPathComponents(candidate, allowedRoot, context) {
  const resolvedRoot = path.resolve(allowedRoot);
  const resolvedCandidate = path.resolve(candidate);
  requireCondition(
    isPathInsideOrSame(resolvedCandidate, resolvedRoot),
    `${context} is outside its allowed root: ${candidate}`,
  );
  requireCondition(existsSync(resolvedRoot), `${context} root is missing: ${resolvedRoot}`);
  const rootInfo = lstatSync(resolvedRoot);
  requireCondition(
    !rootInfo.isSymbolicLink(),
    `${context} contains a symlink or junction component: ${resolvedRoot}`,
  );
  const canonicalRoot = realpathSync.native(resolvedRoot);
  let current = resolvedRoot;
  const relative = path.relative(resolvedRoot, resolvedCandidate);
  for (const component of relative.split(path.sep).filter(Boolean)) {
    current = path.join(current, component);
    requireCondition(existsSync(current), `${context} path component is missing: ${current}`);
    const info = lstatSync(current);
    requireCondition(
      !info.isSymbolicLink(),
      `${context} contains a symlink or junction component: ${current}`,
    );
    const canonicalCurrent = realpathSync.native(current);
    requireCondition(
      isPathInsideOrSame(canonicalCurrent, canonicalRoot),
      `${context} path component escapes its allowed root: ${current}`,
    );
  }
}

function requireNoLinkedAbsolutePathComponents(candidate, context) {
  const resolved = path.resolve(candidate);
  const parsed = path.parse(resolved);
  let current = parsed.root;
  for (const component of resolved.slice(parsed.root.length).split(path.sep).filter(Boolean)) {
    current = path.join(current, component);
    requireCondition(existsSync(current), `${context} path component is missing: ${current}`);
    requireCondition(
      !lstatSync(current).isSymbolicLink(),
      `${context} contains a symlink or junction component: ${current}`,
    );
  }
}

function requireSafeRegularFile(filePath, allowedRoot, context) {
  requireCondition(existsSync(filePath), `${context} is missing: ${filePath}`);
  requireNoLinkedPathComponents(filePath, allowedRoot, context);
  const info = lstatSync(filePath);
  requireCondition(info.isFile() && !info.isSymbolicLink(), `${context} must be a regular non-linked file: ${filePath}`);
  const canonicalRoot = realpathSync.native(allowedRoot);
  const canonicalFile = realpathSync.native(filePath);
  requireCondition(isPathInside(canonicalFile, canonicalRoot), `${context} escapes its run directory: ${filePath}`);
  return filePath;
}

function requireSafeDirectory(directoryPath, allowedRoot, context) {
  requireCondition(existsSync(directoryPath), `${context} is missing: ${directoryPath}`);
  requireNoLinkedPathComponents(directoryPath, allowedRoot, context);
  const info = lstatSync(directoryPath);
  requireCondition(info.isDirectory() && !info.isSymbolicLink(), `${context} must be a regular non-linked directory: ${directoryPath}`);
  const canonicalRoot = realpathSync.native(allowedRoot);
  const canonicalDirectory = realpathSync.native(directoryPath);
  requireCondition(
    isPathInside(canonicalDirectory, canonicalRoot),
    `${context} escapes its run directory: ${directoryPath}`,
  );
  return directoryPath;
}

function listRegularFiles(root) {
  const files = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      const relativePath = path.relative(root, entryPath).replaceAll('\\', '/');
      requireCondition(!entry.isSymbolicLink(), `Symlink or junction is forbidden in eval workspace: ${relativePath}`);
      if (entry.isDirectory()) {
        visit(entryPath);
      } else {
        requireCondition(entry.isFile(), `Unsupported eval workspace entry: ${relativePath}`);
        files.push(relativePath);
      }
    }
  };
  visit(root);
  return files.sort();
}

function fileRecords(root) {
  return listRegularFiles(root).map(relativeFile => ({
    path: relativeFile,
    sha256: fileSha256(path.join(root, relativeFile)),
    bytes: readFileSync(path.join(root, relativeFile)).length,
  }));
}

function fileTreeFingerprint(records) {
  return sha256(records.map(item => `${item.path}:${item.sha256}:${item.bytes}`).join('\n'));
}

function fileTreeStats(root) {
  const records = fileRecords(root);
  return {
    files: records.length,
    bytes: records.reduce((total, item) => total + item.bytes, 0),
    sha256: fileTreeFingerprint(records),
  };
}

function defaultRunId(skillName, now = new Date()) {
  return `${skillName}-${now.toISOString().replace(/[:.]/gu, '-')}`;
}

export function parseArgs(argv) {
  const [firstToken = 'help', ...tokens] = argv;
  const command = ['--help', '-h'].includes(firstToken) ? 'help' : firstToken;
  const options = {
    command,
    skillName: 'suxi-product-decision',
    caseIds: null,
    outputDir: '',
    runDir: '',
    judgmentsPath: '',
    ledgerPath: '',
    archiveDir: '',
    expectedManifestSha256: '',
    expectedSourceLedgerSha256: '',
    expectedArchiveSealSha256: '',
    expectedRunnerSha256: '',
    expectedBehaviorTestSha256: '',
    expectedContractTestSha256: '',
    expectedRuntimeSha256: '',
  };

  for (const token of tokens) {
    if (token.startsWith('--skill=')) {
      options.skillName = token.slice('--skill='.length).trim();
    } else if (token.startsWith('--cases=')) {
      options.caseIds = token.slice('--cases='.length).split(',').map(item => item.trim()).filter(Boolean);
    } else if (token.startsWith('--output-dir=')) {
      options.outputDir = token.slice('--output-dir='.length).trim();
    } else if (token.startsWith('--run-dir=')) {
      options.runDir = token.slice('--run-dir='.length).trim();
    } else if (token.startsWith('--judgments=')) {
      options.judgmentsPath = token.slice('--judgments='.length).trim();
    } else if (token.startsWith('--ledger=')) {
      options.ledgerPath = token.slice('--ledger='.length).trim();
    } else if (token.startsWith('--archive-dir=')) {
      options.archiveDir = token.slice('--archive-dir='.length).trim();
    } else if (token.startsWith('--expected-manifest-sha256=')) {
      options.expectedManifestSha256 = token.slice('--expected-manifest-sha256='.length).trim();
    } else if (token.startsWith('--expected-source-ledger-sha256=')) {
      options.expectedSourceLedgerSha256 = token.slice('--expected-source-ledger-sha256='.length).trim();
    } else if (token.startsWith('--expected-archive-seal-sha256=')) {
      options.expectedArchiveSealSha256 = token.slice('--expected-archive-seal-sha256='.length).trim();
    } else if (token.startsWith('--expected-runner-sha256=')) {
      options.expectedRunnerSha256 = token.slice('--expected-runner-sha256='.length).trim();
    } else if (token.startsWith('--expected-behavior-test-sha256=')) {
      options.expectedBehaviorTestSha256 = token.slice('--expected-behavior-test-sha256='.length).trim();
    } else if (token.startsWith('--expected-contract-test-sha256=')) {
      options.expectedContractTestSha256 = token.slice('--expected-contract-test-sha256='.length).trim();
    } else if (token.startsWith('--expected-runtime-sha256=')) {
      options.expectedRuntimeSha256 = token.slice('--expected-runtime-sha256='.length).trim();
    } else if (token === '--help' || token === '-h') {
      options.command = 'help';
    } else {
      throw new Error(`Unknown argument: ${token}`);
    }
  }

  requireCondition(
    [
      'help',
      'prepare',
      'build-judge',
      'grade',
      'finalize-grade',
      'verify',
      'verify-suite',
      'archive-suite',
      'finalize-archive',
      'finalize-verifier-receipt',
      'verify-archive',
    ].includes(options.command),
    `Unknown command: ${options.command}`,
  );
  requireCondition(governedSkills.has(options.skillName), `Unsupported skill: ${options.skillName}`);
  return options;
}

export function validateBehaviorContract(document, skillName) {
  const dimensions = resolveDimensions(skillName);
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'behavior contract must be an object');
  requireCondition(document.schema_version === behaviorContractVersion, `behavior contract must use ${behaviorContractVersion}`);
  requireCondition(document.skill_name === skillName, `behavior contract skill_name must be ${skillName}`);
  requireCondition(Array.isArray(document.cases) && document.cases.length > 0, 'behavior contract needs cases');

  const ids = new Set();
  for (const [index, row] of document.cases.entries()) {
    requireCondition(row && typeof row === 'object' && !Array.isArray(row), `behavior case ${index} must be an object`);
    const id = safeSegment(row.id, `behavior case ${index}.id`);
    requireCondition(!ids.has(id), `duplicate behavior case id ${id}`);
    ids.add(id);
    nonEmptyString(row.source_eval_id, `${id}.source_eval_id`);
    nonEmptyString(row.prompt, `${id}.prompt`);
    requireCondition(row.expected && typeof row.expected === 'object' && !Array.isArray(row.expected), `${id}.expected must be an object`);
    requireExactKeys(row.expected, Object.keys(dimensions), `${id}.expected`);
    for (const [field, definition] of enumDimensionEntries(dimensions)) {
      requireCondition(
        Array.isArray(row.expected[field])
          && row.expected[field].length > 0
          && row.expected[field].every(item => typeof item === 'string' && item.trim() !== ''),
        `${id}.expected.${field} must be a non-empty string array`,
      );
      requireCondition(
        row.expected[field].every(item => definition.values.includes(item)),
        `${id}.expected.${field} contains unsupported values`,
      );
    }
    for (const [field] of booleanDimensionEntries(dimensions)) {
      requireCondition(typeof row.expected[field] === 'boolean', `${id}.expected.${field} must be boolean`);
    }
    requireCondition(
      Array.isArray(row.assertions)
        && row.assertions.length > 0
        && row.assertions.every(item => typeof item === 'string' && item.trim() !== ''),
      `${id}.assertions must be a non-empty string array`,
    );
    requireCondition(new Set(row.assertions).size === row.assertions.length, `${id}.assertions must be unique`);
  }
  return document;
}

export function loadBehaviorContract(root, skillName) {
  const config = governedSkills.get(skillName);
  requireCondition(config, `Unsupported skill: ${skillName}`);
  const skillRoot = path.join(root, '.agents', 'skills', skillName);
  const contractPath = path.join(skillRoot, config.behaviorContract);
  return {
    config,
    skillRoot,
    contractPath,
    document: validateBehaviorContract(readJson(contractPath), skillName),
  };
}

function requireNonNegativeInteger(value, context) {
  requireCondition(Number.isInteger(value) && value >= 0, `${context} must be a non-negative integer`);
}

function requireSha256(value, context) {
  requireCondition(typeof value === 'string' && /^[a-f0-9]{64}$/u.test(value), `${context} must be SHA-256`);
}

export function validateEvidenceLedger(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'evidence ledger must be an object');
  requireExactKeys(document, ['schema_version', 'scope', 'entries'], 'evidence ledger');
  requireCondition(document.schema_version === evidenceLedgerVersion, `evidence ledger must use ${evidenceLedgerVersion}`);
  requireCondition(
    ['current_local_governed_skills', 'test_subset'].includes(document.scope),
    'evidence ledger scope is invalid',
  );
  requireCondition(Array.isArray(document.entries) && document.entries.length > 0, 'evidence ledger needs entries');
  const skillNames = new Set();
  const runIds = new Set();
  const runPaths = new Set();
  for (const [index, entry] of document.entries.entries()) {
    const context = `evidence ledger entry ${index}`;
    requireCondition(entry && typeof entry === 'object' && !Array.isArray(entry), `${context} must be an object`);
    requireExactKeys(
      entry,
      [
        'skill_name',
        'run_id',
        'run_path',
        'run_manifest_schema_version',
        'judgment_schema_version',
        'grade_status',
        'counts',
        'verified',
        'hashes',
      ],
      context,
    );
    requireCondition(governedSkills.has(entry.skill_name), `${context}.skill_name is unsupported`);
    safeSegment(entry.run_id, `${context}.run_id`);
    const storedRunPath = nonEmptyString(entry.run_path, `${context}.run_path`);
    const runPath = storedRunPath.replaceAll('\\', '/');
    requireCondition(
      storedRunPath === runPath
        && !path.posix.isAbsolute(runPath)
        && !path.win32.isAbsolute(runPath)
        && path.posix.normalize(runPath) === runPath
        && runPath !== '..'
        && !runPath.startsWith('../'),
      `${context}.run_path must be a normalized relative path`,
    );
    requireCondition(
      [legacyRunManifestVersion, runManifestVersion].includes(entry.run_manifest_schema_version),
      `${context}.run_manifest_schema_version is invalid`,
    );
    requireCondition(
      [legacyJudgmentVersion, judgmentVersion].includes(entry.judgment_schema_version),
      `${context}.judgment_schema_version is invalid`,
    );
    requireCondition(['PASS', 'FAIL', 'BLOCKED'].includes(entry.grade_status), `${context}.grade_status is invalid`);
    requireExactKeys(entry.counts, ['pass', 'fail', 'blocked'], `${context}.counts`);
    for (const field of ['pass', 'fail', 'blocked']) {
      requireNonNegativeInteger(entry.counts[field], `${context}.counts.${field}`);
    }
    requireExactKeys(entry.verified, ['cases', 'assertions', 'evidence_spans'], `${context}.verified`);
    for (const field of ['cases', 'assertions', 'evidence_spans']) {
      requireNonNegativeInteger(entry.verified[field], `${context}.verified.${field}`);
    }
    requireCondition(
      entry.verified.cases === entry.counts.pass + entry.counts.fail + entry.counts.blocked,
      `${context}.verified.cases must equal grade counts`,
    );
    requireCondition(
      (entry.grade_status === 'PASS' && entry.counts.fail === 0 && entry.counts.blocked === 0)
        || (entry.grade_status === 'FAIL' && entry.counts.fail > 0)
        || (entry.grade_status === 'BLOCKED' && entry.counts.fail === 0 && entry.counts.blocked > 0),
      `${context}.grade_status disagrees with counts`,
    );
    requireExactKeys(
      entry.hashes,
      [
        'behavior_contract_sha256',
        'source_snapshot_sha256',
        'prepare_seal_sha256',
        'prepare_manifest_sha256',
        'judge_packet_sha256',
        'judgments_sha256',
        'grade_summary_sha256',
        'grade_seal_sha256',
        'responses',
      ],
      `${context}.hashes`,
    );
    for (const field of [
      'behavior_contract_sha256',
      'source_snapshot_sha256',
      'prepare_seal_sha256',
      'prepare_manifest_sha256',
      'judge_packet_sha256',
      'judgments_sha256',
      'grade_summary_sha256',
      'grade_seal_sha256',
    ]) {
      requireSha256(entry.hashes[field], `${context}.hashes.${field}`);
    }
    requireCondition(
      Array.isArray(entry.hashes.responses) && entry.hashes.responses.length === entry.verified.cases,
      `${context}.hashes.responses must match case count`,
    );
    const caseIds = new Set();
    for (const [responseIndex, response] of entry.hashes.responses.entries()) {
      const responseContext = `${context}.hashes.responses[${responseIndex}]`;
      requireExactKeys(response, ['case_id', 'sha256'], responseContext);
      const caseId = safeSegment(response.case_id, `${responseContext}.case_id`);
      requireCondition(!caseIds.has(caseId), `${context}.response case ids must be unique`);
      caseIds.add(caseId);
      requireSha256(response.sha256, `${responseContext}.sha256`);
    }
    requireCondition(
      !skillNames.has(entry.skill_name) && !runIds.has(entry.run_id) && !runPaths.has(runPath),
      'evidence ledger entries must have unique skill_name, run_id, and run_path',
    );
    skillNames.add(entry.skill_name);
    runIds.add(entry.run_id);
    runPaths.add(runPath);
  }
  if (document.scope === 'current_local_governed_skills') {
    requireCondition(
      isDeepStrictEqual([...skillNames].sort(), [...governedSkills.keys()].sort()),
      'current evidence ledger must cover every governed skill exactly once',
    );
  }
  return document;
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

export function validateEvidenceArchiveManifest(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'evidence archive manifest must be an object');
  requireExactKeys(
    document,
    ['schema_version', 'source_ledger_path', 'source_ledger_sha256', 'entries'],
    'evidence archive manifest',
  );
  requireCondition(
    document.schema_version === evidenceArchiveVersion,
    `evidence archive manifest must use ${evidenceArchiveVersion}`,
  );
  const sourceLedgerPath = requireNormalizedRelativePath(
    document.source_ledger_path,
    'evidence archive manifest.source_ledger_path',
  );
  requireCondition(sourceLedgerPath === 'source-ledger.json', 'evidence archive source ledger path is invalid');
  requireSha256(document.source_ledger_sha256, 'evidence archive manifest.source_ledger_sha256');
  requireCondition(Array.isArray(document.entries) && document.entries.length > 0, 'evidence archive manifest needs entries');
  const skillNames = new Set();
  const runIds = new Set();
  const paths = new Set();
  for (const [index, entry] of document.entries.entries()) {
    const context = `evidence archive entry ${index}`;
    requireCondition(entry && typeof entry === 'object' && !Array.isArray(entry), `${context} must be an object`);
    requireExactKeys(
      entry,
      [
        'skill_name',
        'run_id',
        'run_path',
        'prepare_seal_path',
        'post_grade_seal_path',
        'run_file_count',
        'run_bytes',
        'run_tree_sha256',
        'prepare_seal_sha256',
        'post_grade_seal_sha256',
      ],
      context,
    );
    requireCondition(governedSkills.has(entry.skill_name), `${context}.skill_name is unsupported`);
    safeSegment(entry.run_id, `${context}.run_id`);
    const runPath = requireNormalizedRelativePath(entry.run_path, `${context}.run_path`);
    const prepareSealPath = requireNormalizedRelativePath(
      entry.prepare_seal_path,
      `${context}.prepare_seal_path`,
    );
    const postGradeSealPath = requireNormalizedRelativePath(
      entry.post_grade_seal_path,
      `${context}.post_grade_seal_path`,
    );
    requireCondition(
      runPath === `runs/${entry.run_id}`,
      `${context}.run_path must equal runs/<run_id>`,
    );
    requireCondition(
      prepareSealPath.startsWith('seals/') && postGradeSealPath.startsWith('seals/'),
      `${context} seal paths must stay under seals/`,
    );
    requireCondition(
      prepareSealPath.startsWith(`seals/${entry.run_id}-`)
        && prepareSealPath.endsWith('.json')
        && !prepareSealPath.endsWith('.grade.json'),
      `${context}.prepare_seal_path must use the canonical run-id prefix`,
    );
    requireCondition(
      postGradeSealPath === prepareSealPath.replace(/\.json$/u, '.grade.json'),
      `${context}.post_grade_seal_path must pair with prepare_seal_path`,
    );
    requireNonNegativeInteger(entry.run_file_count, `${context}.run_file_count`);
    requireNonNegativeInteger(entry.run_bytes, `${context}.run_bytes`);
    requireCondition(entry.run_file_count > 0 && entry.run_bytes > 0, `${context} run stats must be positive`);
    requireSha256(entry.run_tree_sha256, `${context}.run_tree_sha256`);
    requireSha256(entry.prepare_seal_sha256, `${context}.prepare_seal_sha256`);
    requireSha256(entry.post_grade_seal_sha256, `${context}.post_grade_seal_sha256`);
    for (const relativePath of [runPath, prepareSealPath, postGradeSealPath]) {
      requireCondition(!paths.has(relativePath), 'evidence archive paths must be unique');
      paths.add(relativePath);
    }
    requireCondition(
      !skillNames.has(entry.skill_name) && !runIds.has(entry.run_id),
      'evidence archive entries must have unique skill_name and run_id',
    );
    skillNames.add(entry.skill_name);
    runIds.add(entry.run_id);
  }
  return document;
}

export function validateEvidenceArchiveSeal(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'evidence archive seal must be an object');
  requireExactKeys(
    document,
    [
      'schema_version',
      'archive_path_sha256',
      'archive_manifest_sha256',
      'source_ledger_sha256',
      'counts',
    ],
    'evidence archive seal',
  );
  requireCondition(
    document.schema_version === evidenceArchiveSealVersion,
    `evidence archive seal must use ${evidenceArchiveSealVersion}`,
  );
  requireSha256(document.archive_path_sha256, 'evidence archive seal.archive_path_sha256');
  requireSha256(document.archive_manifest_sha256, 'evidence archive seal.archive_manifest_sha256');
  requireSha256(document.source_ledger_sha256, 'evidence archive seal.source_ledger_sha256');
  requireCondition(
    document.counts && typeof document.counts === 'object' && !Array.isArray(document.counts),
    'evidence archive seal.counts must be an object',
  );
  requireExactKeys(document.counts, ['runs', 'files', 'bytes', 'seals'], 'evidence archive seal.counts');
  for (const field of ['runs', 'files', 'bytes', 'seals']) {
    requireNonNegativeInteger(document.counts[field], `evidence archive seal.counts.${field}`);
  }
  requireCondition(
    document.counts.runs > 0
      && document.counts.files > 0
      && document.counts.bytes > 0
      && document.counts.seals > 0,
    'evidence archive seal counts must be positive',
  );
  return document;
}

function validateArchiveVerifierRuntime(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'archive verifier runtime must be an object');
  requireExactKeys(
    document,
    ['node_version', 'v8_version', 'platform', 'arch'],
    'archive verifier runtime',
  );
  for (const field of ['node_version', 'v8_version', 'platform', 'arch']) {
    nonEmptyString(document[field], `archive verifier runtime.${field}`);
  }
  return document;
}

function validateArchiveVerifierProfile(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'archive verifier profile must be an object');
  requireExactKeys(document, ['schema_version', 'files', 'runtime'], 'archive verifier profile');
  requireCondition(
    document.schema_version === verifierProfileVersion,
    `archive verifier profile must use ${verifierProfileVersion}`,
  );
  requireCondition(
    Array.isArray(document.files) && document.files.length === verifierProfileFileSpecs.length,
    'archive verifier profile files must exactly match the fixed verifier contract',
  );
  for (const [index, expected] of verifierProfileFileSpecs.entries()) {
    const record = document.files[index];
    const context = `archive verifier profile file ${index}`;
    requireCondition(record && typeof record === 'object' && !Array.isArray(record), `${context} must be an object`);
    requireExactKeys(record, ['role', 'path', 'sha256', 'bytes'], context);
    requireCondition(record.role === expected.role, `${context}.role must be ${expected.role}`);
    requireCondition(record.path === expected.path, `${context}.path must be ${expected.path}`);
    requireSha256(record.sha256, `${context}.sha256`);
    requireNonNegativeInteger(record.bytes, `${context}.bytes`);
    requireCondition(record.bytes > 0, `${context}.bytes must be positive`);
  }
  validateArchiveVerifierRuntime(document.runtime);
  return document;
}

export function validateArchiveVerifierReceipt(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'archive verifier receipt must be an object');
  requireExactKeys(
    document,
    [
      'schema_version',
      'archive_path_sha256',
      'archive_manifest_sha256',
      'source_ledger_sha256',
      'archive_seal_sha256',
      'verifier_profile',
      'verifier_profile_sha256',
    ],
    'archive verifier receipt',
  );
  requireCondition(
    document.schema_version === verifierReceiptVersion,
    `archive verifier receipt must use ${verifierReceiptVersion}`,
  );
  for (const field of [
    'archive_path_sha256',
    'archive_manifest_sha256',
    'source_ledger_sha256',
    'archive_seal_sha256',
    'verifier_profile_sha256',
  ]) {
    requireSha256(document[field], `archive verifier receipt.${field}`);
  }
  validateArchiveVerifierProfile(document.verifier_profile);
  requireCondition(
    sha256(jsonText(document.verifier_profile)) === document.verifier_profile_sha256,
    'archive verifier receipt profile hash mismatch',
  );
  return document;
}

function selectCases(document, caseIds) {
  if (!caseIds || caseIds.length === 0) return document.cases;
  requireCondition(new Set(caseIds).size === caseIds.length, '--cases must not contain duplicates');
  const byId = new Map(document.cases.map(row => [row.id, row]));
  return caseIds.map((id) => {
    requireCondition(byId.has(id), `Unknown behavior case: ${id}`);
    return byId.get(id);
  });
}

function dimensionJsonSchema(definition) {
  const schema = definition.type === 'enum'
    ? { enum: definition.values }
    : { type: 'boolean' };
  if (definition.description) schema.description = definition.description;
  return schema;
}

function respondentOutputSchema(skillName, caseId, providedDimensions = null) {
  const dimensions = resolveDimensions(skillName, providedDimensions);
  return {
    type: 'object',
    properties: {
      schema_version: { const: respondentVersion },
      skill_name: { const: skillName },
      case_id: { const: caseId },
      response_text: { type: 'string', minLength: 1 },
      self_report: {
        type: 'object',
        properties: {
          ...Object.fromEntries(
            dimensionEntries(dimensions).map(([field, definition]) => [field, dimensionJsonSchema(definition)]),
          ),
          notes: { type: 'string' },
        },
        required: [...Object.keys(dimensions), 'notes'],
        additionalProperties: false,
      },
    },
    required: ['schema_version', 'skill_name', 'case_id', 'response_text', 'self_report'],
    additionalProperties: false,
  };
}

export function judgmentOutputSchema(
  skillName,
  caseIds,
  judgePacketSha256,
  providedDimensions = null,
  schemaVersion = judgmentVersion,
) {
  const dimensions = resolveDimensions(skillName, providedDimensions);
  requireCondition(
    [legacyJudgmentVersion, judgmentVersion].includes(schemaVersion),
    `Unsupported judgment schema version: ${schemaVersion}`,
  );
  return {
    type: 'object',
    properties: {
      schema_version: { const: schemaVersion },
      skill_name: { const: skillName },
      judge: { type: 'string', minLength: 1 },
      judge_packet_sha256: { const: judgePacketSha256 },
      case_results: {
        type: 'array',
        minItems: caseIds.length,
        maxItems: caseIds.length,
        items: {
          type: 'object',
          properties: {
            case_id: { enum: caseIds },
            verdict: { enum: ['PASS', 'FAIL', 'BLOCKED'] },
            normalized: {
              oneOf: [
                {
                  type: 'object',
                  properties: {
                    ...Object.fromEntries(
                      dimensionEntries(dimensions).map(([field, definition]) => [field, dimensionJsonSchema(definition)]),
                    ),
                  },
                  required: Object.keys(dimensions),
                  additionalProperties: false,
                },
                { type: 'null' },
              ],
            },
            assertion_results: {
              type: 'array',
              minItems: 0,
              items: {
                type: 'object',
                properties: {
                  assertion: { type: 'string', minLength: 1 },
                  passed: { type: 'boolean' },
                  evidence_spans: {
                    type: 'array',
                    minItems: schemaVersion === legacyJudgmentVersion ? 0 : 1,
                    items: {
                      type: 'object',
                      properties: {
                        start: { type: 'integer', minimum: 0 },
                        end: { type: 'integer', minimum: 1 },
                        quote: { type: 'string', minLength: 1 },
                      },
                      required: ['start', 'end', 'quote'],
                      additionalProperties: false,
                    },
                  },
                  explanation: { type: 'string', minLength: 1 },
                },
                required: ['assertion', 'passed', 'evidence_spans', 'explanation'],
                additionalProperties: false,
              },
            },
            blocked_reason: { type: 'string' },
            notes: { type: 'string' },
          },
          oneOf: [
            {
              properties: {
                verdict: { const: 'BLOCKED' },
                normalized: { type: 'null' },
                assertion_results: { type: 'array', maxItems: 0 },
                blocked_reason: { type: 'string', minLength: 1 },
              },
            },
            {
              properties: {
                verdict: { enum: ['PASS', 'FAIL'] },
                normalized: { type: 'object' },
                assertion_results: { type: 'array', minItems: 1 },
                blocked_reason: { const: '' },
              },
            },
          ],
          required: ['case_id', 'verdict', 'normalized', 'assertion_results', 'blocked_reason', 'notes'],
          additionalProperties: false,
        },
      },
    },
    required: ['schema_version', 'skill_name', 'judge', 'judge_packet_sha256', 'case_results'],
    additionalProperties: false,
  };
}

function isolatedAgentsText(skillName) {
  return `# Context-minimized SUXIOS skill behavior evaluation

- Treat this packet workspace as read-only except for the requested response JSON outside this workspace.
- Use only the copied $${skillName} Skill and its copied references.
- Do not inspect the original repository, user profile, credentials, network, eval expectations, or other skills.
- Do not run commands, modify product files, contact external services, or perform OTA/PMS actions.
- Answer only the supplied synthetic or bounded request and preserve its evidence scope.
`;
}

function respondentPrompt(skillName, caseId) {
  return `Use $${skillName} at .agents/skills/${skillName} to answer the user request in respondent-request.json.

This is an expectation-blind forward evaluation packet. Do not search for evaluation artifacts, prior runs, or the original repository. Do not call tools or change product/external state. Put the complete user-facing answer in response_text, then self-report the normalized enum fields exactly as allowed by respondent-output.schema.json. Return only schema-conforming JSON. The case id is ${caseId}.
`;
}

function judgePrompt() {
  return `Judge the saved responses in judge-packet.json against the included expectations and assertions.

Use only the packet. Do not inspect the skill, original repository, prior runs, or external sources. For every judged assertion, provide one or more exact non-empty quote spans from response_text using zero-based JavaScript string offsets; each quote must equal response_text.slice(start, end). Normalize the requested fields from response_text rather than trusting self_report. PASS requires every assertion and normalized expectation to pass. Use FAIL for a judgeable behavior mismatch. Use BLOCKED only when the response cannot be judged: set normalized to null, assertion_results to an empty array, and provide a concrete blocked_reason. Return only JSON matching judgment-output.schema.json and copy judge_packet_sha256 exactly.
`;
}

function prepareOutputDirectory(skillName, outputDir, now, { allowExternalRunDir = false } = {}) {
  const resolved = outputDir
    ? path.isAbsolute(outputDir) ? path.resolve(outputDir) : path.join(behaviorEvalTempRoot, outputDir)
    : path.join(behaviorEvalTempRoot, defaultRunId(skillName, now));
  requireRunPath(resolved, { allowExternalRunDir, createAllowedRoot: true });
  requireCondition(!existsSync(resolved), `Output directory already exists: ${resolved}`);
  mkdirSync(path.dirname(resolved), { recursive: true });
  mkdirSync(resolved);
  return resolved;
}

function copySnapshotFiles(skillRoot, targetRoot, snapshotFiles) {
  const records = [];
  for (const relativeFile of snapshotFiles) {
    const source = path.join(skillRoot, relativeFile);
    requireCondition(existsSync(source), `Missing snapshot file ${source}`);
    const sourceInfo = lstatSync(source);
    requireCondition(sourceInfo.isFile() && !sourceInfo.isSymbolicLink(), `Snapshot source must be a regular file: ${source}`);
    const bytes = readFileSync(source);
    const target = path.join(targetRoot, relativeFile);
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, bytes);
    records.push({ path: relativeFile.replaceAll('\\', '/'), sha256: sha256(bytes), bytes: bytes.length });
  }
  return records;
}

export function prepareBehaviorRun({
  root = repoRoot,
  skillName = 'suxi-product-decision',
  caseIds = null,
  outputDir = '',
  now = new Date(),
  allowExternalRunDir = false,
} = {}) {
  const loaded = loadBehaviorContract(root, skillName);
  const selectedCases = selectCases(loaded.document, caseIds);
  const preparedRunDir = prepareOutputDirectory(skillName, outputDir, now, { allowExternalRunDir });
  const runDir = realpathSync.native(preparedRunDir);
  const sourceSnapshotFiles = loaded.config.snapshotFiles.map(relativeFile => ({
    path: relativeFile.replaceAll('\\', '/'),
    sha256: fileSha256(path.join(loaded.skillRoot, relativeFile)),
    bytes: readFileSync(path.join(loaded.skillRoot, relativeFile)).length,
  }));
  const sourceSnapshotSha256 = snapshotFingerprint(sourceSnapshotFiles);
  const caseManifests = [];

  for (const row of selectedCases) {
    const caseRoot = path.join(runDir, 'cases', row.id);
    const workspace = path.join(caseRoot, 'workspace');
    const snapshotRoot = path.join(workspace, '.agents', 'skills', skillName);
    const snapshotFiles = copySnapshotFiles(loaded.skillRoot, snapshotRoot, loaded.config.snapshotFiles);
    writeNewText(path.join(workspace, 'AGENTS.md'), isolatedAgentsText(skillName));
    writeNewJson(path.join(workspace, 'respondent-request.json'), {
      schema_version: 'suxi.skill.behavior_request.v1',
      skill_name: skillName,
      case_id: row.id,
      user_request: row.prompt,
    });
    writeNewJson(path.join(workspace, 'respondent-output.schema.json'), respondentOutputSchema(skillName, row.id));
    writeNewText(path.join(workspace, 'respondent-prompt.md'), respondentPrompt(skillName, row.id));
    const caseSnapshotFingerprint = snapshotFingerprint(snapshotFiles);
    const workspaceFiles = fileRecords(workspace);
    caseManifests.push({
      case_id: row.id,
      workspace: path.relative(runDir, workspace).replaceAll('\\', '/'),
      response_path: `cases/${row.id}/response.json`,
      skill_snapshot_sha256: caseSnapshotFingerprint,
      snapshot_files: snapshotFiles,
      workspace_files: workspaceFiles,
      expectations_in_workspace: false,
      filesystem_isolation: 'instruction_only',
    });
  }

  const manifest = {
    schema_version: runManifestVersion,
    run_id: path.basename(runDir),
    skill_name: skillName,
    created_at: now.toISOString(),
    behavior_contract_path: path.relative(root, loaded.contractPath).replaceAll('\\', '/'),
    behavior_contract_sha256: fileSha256(loaded.contractPath),
    source_snapshot_sha256: sourceSnapshotSha256,
    source_snapshot_files: sourceSnapshotFiles,
    judgment_schema_version: judgmentVersion,
    case_ids: selectedCases.map(row => row.id),
    cases: caseManifests,
    model_execution: 'NOT_RUN',
    external_or_product_mutation: 'N/A',
    execution_boundary: 'No model or API is called by this script. Packet contents are minimized, but respondent and judge filesystem separation is instruction-only unless an external sandbox is supplied.',
  };
  const manifestText = `${JSON.stringify(manifest, null, 2)}\n`;
  const manifestSha256 = sha256(manifestText);
  writeNewText(path.join(runDir, 'manifest.json'), manifestText);
  const sealPath = sealPathFor(runDir);
  requireNoReparseEscape(sealPath, behaviorEvalTempRoot);
  const seal = {
    schema_version: 'suxi.skill.behavior_prepare_seal.v1',
    run_id: manifest.run_id,
    run_path_sha256: sha256(
      process.platform === 'win32' ? path.resolve(runDir).toLowerCase() : path.resolve(runDir),
    ),
    created_at: manifest.created_at,
    behavior_contract_sha256: manifest.behavior_contract_sha256,
    source_snapshot_sha256: sourceSnapshotSha256,
    manifest_sha256: manifestSha256,
  };
  writeNewJson(sealPath, seal);
  return { status: 'PREPARED', runDir, manifest, sealPath, seal };
}

function validateDimensionValue(value, definition, context) {
  if (definition.type === 'enum') {
    requireCondition(definition.values.includes(value), `${context} is invalid`);
  } else {
    requireCondition(typeof value === 'boolean', `${context} must be boolean`);
  }
}

function validateSelfReport(value, context, dimensions) {
  requireCondition(value && typeof value === 'object' && !Array.isArray(value), `${context} must be an object`);
  requireExactKeys(value, [...Object.keys(dimensions), 'notes'], context);
  for (const [field, definition] of dimensionEntries(dimensions)) {
    validateDimensionValue(value[field], definition, `${context}.${field}`);
  }
  requireCondition(typeof value.notes === 'string', `${context}.notes must be a string`);
}

export function validateResponse(document, skillName, caseId, providedDimensions = null) {
  const dimensions = resolveDimensions(skillName, providedDimensions);
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), `${caseId} response must be an object`);
  requireExactKeys(document, ['schema_version', 'skill_name', 'case_id', 'response_text', 'self_report'], `${caseId} response`);
  requireCondition(document.schema_version === respondentVersion, `${caseId} response schema_version mismatch`);
  requireCondition(document.skill_name === skillName, `${caseId} response skill_name mismatch`);
  requireCondition(document.case_id === caseId, `${caseId} response case_id mismatch`);
  nonEmptyString(document.response_text, `${caseId}.response_text`);
  validateSelfReport(document.self_report, `${caseId}.self_report`, dimensions);
  return document;
}

function loadRunContext(root, runDir, { allowExternalRunDir = false } = {}) {
  const requestedRunDir = requireRunPath(
    path.isAbsolute(runDir) ? runDir : path.join(behaviorEvalTempRoot, runDir),
    { allowExternalRunDir },
  );
  requireCondition(existsSync(requestedRunDir), `Run directory is missing: ${requestedRunDir}`);
  requireNoLinkedAbsolutePathComponents(requestedRunDir, 'Run directory');
  const requestedInfo = lstatSync(requestedRunDir);
  requireCondition(
    requestedInfo.isDirectory() && !requestedInfo.isSymbolicLink(),
    `Run directory must be a regular non-linked directory: ${requestedRunDir}`,
  );
  const resolvedRunDir = realpathSync.native(requestedRunDir);
  const runAliases = [requestedRunDir, resolvedRunDir];
  if (!allowExternalRunDir) {
    const canonicalEvalRoot = realpathSync.native(behaviorEvalTempRoot);
    if (isPathInsideOrSame(resolvedRunDir, canonicalEvalRoot)) {
      runAliases.push(path.join(
        behaviorEvalTempRoot,
        path.relative(canonicalEvalRoot, resolvedRunDir),
      ));
    }
  }
  const uniqueRunAliases = [...new Map(runAliases.map(alias => [
    process.platform === 'win32' ? path.resolve(alias).toLowerCase() : path.resolve(alias),
    path.resolve(alias),
  ])).values()];
  const sealCandidates = [...new Set(uniqueRunAliases.map(alias => sealPathFor(alias)))];
  const existingSeals = sealCandidates.filter(candidate => existsSync(candidate));
  requireCondition(existingSeals.length > 0, `Prepare seal is missing for run: ${resolvedRunDir}`);
  requireCondition(existingSeals.length === 1, `Multiple prepare seals match run: ${resolvedRunDir}`);
  const sealPath = realpathSync.native(existingSeals[0]);
  requireSafeRegularFile(sealPath, path.dirname(resolvedRunDir), 'Prepare seal');
  const seal = readJson(sealPath);
  requireCondition(seal.schema_version === 'suxi.skill.behavior_prepare_seal.v1', 'Prepare seal schema_version mismatch');
  const runPathHashes = new Set(uniqueRunAliases.map(alias => sha256(
    process.platform === 'win32' ? path.resolve(alias).toLowerCase() : path.resolve(alias),
  )));
  requireCondition(runPathHashes.has(seal.run_path_sha256), 'Prepare seal run path mismatch');
  const manifestPath = path.join(resolvedRunDir, 'manifest.json');
  requireSafeRegularFile(manifestPath, resolvedRunDir, 'Run manifest');
  const manifestSha256 = fileSha256(manifestPath);
  requireCondition(manifestSha256 === seal.manifest_sha256, 'Run manifest changed after prepare');
  const manifest = readJson(manifestPath);
  requireCondition(
    [legacyRunManifestVersion, runManifestVersion].includes(manifest.schema_version),
    `Run manifest must use ${legacyRunManifestVersion} or ${runManifestVersion}`,
  );
  const judgmentSchemaVersion = manifest.schema_version === legacyRunManifestVersion
    ? legacyJudgmentVersion
    : manifest.judgment_schema_version;
  requireCondition(
    [legacyJudgmentVersion, judgmentVersion].includes(judgmentSchemaVersion),
    'Run manifest judgment schema version is invalid',
  );
  if (manifest.schema_version === runManifestVersion) {
    requireCondition(
      manifest.judgment_schema_version === judgmentVersion,
      `Run manifest ${runManifestVersion} must use ${judgmentVersion}`,
    );
  }
  requireCondition(manifest.run_id === seal.run_id, 'Run manifest id does not match prepare seal');
  requireCondition(governedSkills.has(manifest.skill_name), `Unsupported run skill ${manifest.skill_name}`);
  const loaded = loadBehaviorContract(root, manifest.skill_name);
  const currentContractHash = fileSha256(loaded.contractPath);
  requireCondition(
    currentContractHash === manifest.behavior_contract_sha256,
    'Behavior contract drifted after prepare; create a fresh run',
  );
  requireCondition(currentContractHash === seal.behavior_contract_sha256, 'Behavior contract does not match prepare seal');
  const currentSourceSnapshotFiles = loaded.config.snapshotFiles.map(relativeFile => ({
    path: relativeFile.replaceAll('\\', '/'),
    sha256: fileSha256(path.join(loaded.skillRoot, relativeFile)),
    bytes: readFileSync(path.join(loaded.skillRoot, relativeFile)).length,
  }));
  const currentSourceSnapshotSha256 = snapshotFingerprint(currentSourceSnapshotFiles);
  requireCondition(
    isDeepStrictEqual(currentSourceSnapshotFiles, manifest.source_snapshot_files),
    'Configured source snapshot files changed after prepare',
  );
  requireCondition(
    currentSourceSnapshotSha256 === manifest.source_snapshot_sha256
      && currentSourceSnapshotSha256 === seal.source_snapshot_sha256,
    'Configured source snapshot hash changed after prepare',
  );
  const selectedCases = selectCases(loaded.document, manifest.case_ids);
  const context = {
    resolvedRunDir,
    manifest,
    manifestSha256,
    seal,
    sealPath,
    sealSha256: fileSha256(sealPath),
    judgmentSchemaVersion,
    loaded,
    selectedCases,
  };
  verifyPreparedWorkspaces(context);
  return context;
}

function verifyPreparedWorkspaces(context) {
  requireCondition(Array.isArray(context.manifest.cases), 'Run manifest cases must be an array');
  const ids = context.manifest.cases.map(row => row?.case_id);
  requireCondition(new Set(ids).size === ids.length, 'Run manifest case ids must be unique');
  requireCondition(
    isDeepStrictEqual([...ids].sort(), [...context.manifest.case_ids].sort()),
    'Run manifest case records must exactly match case_ids',
  );

  for (const record of context.manifest.cases) {
    const expectedWorkspace = path.join(context.resolvedRunDir, 'cases', record.case_id, 'workspace');
    const workspace = path.resolve(context.resolvedRunDir, record.workspace);
    requireCondition(workspace === expectedWorkspace, `${record.case_id} workspace path mismatch`);
    requireSafeDirectory(workspace, context.resolvedRunDir, `${record.case_id} workspace`);
    requireCondition(record.expectations_in_workspace === false, `${record.case_id} expectation boundary is invalid`);
    requireCondition(record.filesystem_isolation === 'instruction_only', `${record.case_id} isolation status is invalid`);
    const currentWorkspaceFiles = fileRecords(workspace);
    requireCondition(
      isDeepStrictEqual(currentWorkspaceFiles, record.workspace_files),
      `${record.case_id} workspace drifted after prepare`,
    );
    requireCondition(
      !currentWorkspaceFiles.some(item => item.path.includes('/evals/') || item.path.endsWith('/source-provenance.md')),
      `${record.case_id} workspace exposes evaluation expectations or provenance`,
    );
    requireCondition(
      isDeepStrictEqual(record.snapshot_files, context.manifest.source_snapshot_files),
      `${record.case_id} snapshot file set does not match configured source snapshot`,
    );
    const caseSnapshotFingerprint = snapshotFingerprint(record.snapshot_files);
    requireCondition(
      caseSnapshotFingerprint === record.skill_snapshot_sha256
        && caseSnapshotFingerprint === context.manifest.source_snapshot_sha256,
      `${record.case_id} snapshot manifest fingerprint mismatch`,
    );
    for (const item of context.manifest.source_snapshot_files) {
      const snapshotFile = path.join(workspace, '.agents', 'skills', context.manifest.skill_name, item.path);
      requireCondition(fileSha256(snapshotFile) === item.sha256, `${record.case_id} snapshot file drift: ${item.path}`);
    }
  }
}

function collectResponses(context) {
  const responses = [];
  const responseFiles = [];
  const missing = [];
  for (const row of context.selectedCases) {
    const responsePath = path.join(context.resolvedRunDir, 'cases', row.id, 'response.json');
    if (!existsSync(responsePath)) {
      missing.push(row.id);
      continue;
    }
    requireSafeRegularFile(responsePath, context.resolvedRunDir, `${row.id} response`);
    responses.push(validateResponse(
      readJson(responsePath),
      context.manifest.skill_name,
      row.id,
      context.loaded.config.dimensions,
    ));
    responseFiles.push({
      case_id: row.id,
      path: path.relative(context.resolvedRunDir, responsePath).replaceAll('\\', '/'),
      sha256: fileSha256(responsePath),
    });
  }
  return { responses, responseFiles, missing };
}

export function buildJudgePacket({ root = repoRoot, runDir, allowExternalRunDir = false } = {}) {
  nonEmptyString(runDir, 'runDir');
  const context = loadRunContext(root, runDir, { allowExternalRunDir });
  const collected = collectResponses(context);
  if (collected.missing.length > 0) {
    return { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_case_ids: collected.missing };
  }
  const responseById = new Map(collected.responses.map(row => [row.case_id, row]));
  const responseFileById = new Map(collected.responseFiles.map(row => [row.case_id, row]));
  const packet = {
    schema_version: 'suxi.skill.behavior_judge_packet.v1',
    skill_name: context.manifest.skill_name,
    prepare_seal_sha256: context.sealSha256,
    prepare_manifest_sha256: context.manifestSha256,
    source_snapshot_sha256: context.manifest.source_snapshot_sha256,
    cases: context.selectedCases.map(row => ({
      case_id: row.id,
      user_request: row.prompt,
      response: responseById.get(row.id),
      response_sha256: responseFileById.get(row.id).sha256,
      expected: row.expected,
      assertions: row.assertions,
    })),
  };
  const packetText = `${JSON.stringify(packet, null, 2)}\n`;
  const judgePacketSha256 = sha256(packetText);
  writeNewText(path.join(context.resolvedRunDir, 'judge-packet.json'), packetText);
  writeNewJson(
    path.join(context.resolvedRunDir, 'judgment-output.schema.json'),
    judgmentOutputSchema(
      context.manifest.skill_name,
      context.manifest.case_ids,
      judgePacketSha256,
      context.loaded.config.dimensions,
      context.judgmentSchemaVersion,
    ),
  );
  writeNewText(path.join(context.resolvedRunDir, 'judge-prompt.md'), judgePrompt());
  return {
    status: 'READY_FOR_JUDGMENT',
    runDir: context.resolvedRunDir,
    case_ids: context.manifest.case_ids,
    judge_packet_sha256: judgePacketSha256,
  };
}

function loadAndValidateJudgePacket(context, collected) {
  const packetPath = path.join(context.resolvedRunDir, 'judge-packet.json');
  requireSafeRegularFile(packetPath, context.resolvedRunDir, 'Judge packet');
  const packetBytes = readFileSync(packetPath);
  const packetSha256 = sha256(packetBytes);
  const packet = readJson(packetPath);
  requireCondition(packet && typeof packet === 'object' && !Array.isArray(packet), 'Judge packet must be an object');
  requireExactKeys(
    packet,
    [
      'schema_version',
      'skill_name',
      'prepare_seal_sha256',
      'prepare_manifest_sha256',
      'source_snapshot_sha256',
      'cases',
    ],
    'judge packet',
  );
  requireCondition(packet.schema_version === 'suxi.skill.behavior_judge_packet.v1', 'Judge packet schema_version mismatch');
  requireCondition(packet.skill_name === context.manifest.skill_name, 'Judge packet skill_name mismatch');
  requireCondition(packet.prepare_seal_sha256 === context.sealSha256, 'Judge packet prepare seal mismatch');
  requireCondition(packet.prepare_manifest_sha256 === context.manifestSha256, 'Judge packet prepare manifest mismatch');
  requireCondition(
    packet.source_snapshot_sha256 === context.manifest.source_snapshot_sha256,
    'Judge packet source snapshot mismatch',
  );
  requireCondition(Array.isArray(packet.cases), 'Judge packet cases must be an array');
  requireCondition(packet.cases.length === context.selectedCases.length, 'Judge packet case count mismatch');

  const contractById = new Map(context.selectedCases.map(row => [row.id, row]));
  const responseById = new Map(collected.responses.map(row => [row.case_id, row]));
  const responseFileById = new Map(collected.responseFiles.map(row => [row.case_id, row]));
  const packetIds = packet.cases.map(row => row?.case_id);
  requireCondition(new Set(packetIds).size === packetIds.length, 'Judge packet case ids must be unique');
  requireCondition(
    isDeepStrictEqual([...packetIds].sort(), [...context.manifest.case_ids].sort()),
    'Judge packet case ids must exactly match the run',
  );

  for (const row of packet.cases) {
    requireCondition(row && typeof row === 'object' && !Array.isArray(row), `${row?.case_id} judge packet row must be an object`);
    requireExactKeys(
      row,
      ['case_id', 'user_request', 'response', 'response_sha256', 'expected', 'assertions'],
      `${row.case_id} judge packet row`,
    );
    const contract = contractById.get(row.case_id);
    requireCondition(contract, `Unknown judge packet case ${row.case_id}`);
    requireCondition(row.user_request === contract.prompt, `${row.case_id} judge packet request drift`);
    requireCondition(isDeepStrictEqual(row.expected, contract.expected), `${row.case_id} judge packet expectation drift`);
    requireCondition(isDeepStrictEqual(row.assertions, contract.assertions), `${row.case_id} judge packet assertion drift`);
    requireCondition(isDeepStrictEqual(row.response, responseById.get(row.case_id)), `${row.case_id} judge packet response drift`);
    requireCondition(
      row.response_sha256 === responseFileById.get(row.case_id).sha256,
      `${row.case_id} response changed after judge packet creation`,
    );
  }
  return { packet, packetPath, packetSha256 };
}

function verifyJudgeScaffolding(context, judgePacketSha256) {
  const schemaPath = path.join(context.resolvedRunDir, 'judgment-output.schema.json');
  requireSafeRegularFile(schemaPath, context.resolvedRunDir, 'Judgment output schema');
  const expectedSchema = jsonText(judgmentOutputSchema(
    context.manifest.skill_name,
    context.manifest.case_ids,
    judgePacketSha256,
    context.loaded.config.dimensions,
    context.judgmentSchemaVersion,
  ));
  requireCondition(
    readFileSync(schemaPath, 'utf8') === expectedSchema,
    'Judgment output schema drifted after judge packet creation',
  );

  const promptPath = path.join(context.resolvedRunDir, 'judge-prompt.md');
  requireSafeRegularFile(promptPath, context.resolvedRunDir, 'Judge prompt');
  requireCondition(
    readFileSync(promptPath, 'utf8') === judgePrompt(),
    'Judge prompt drifted after judge packet creation',
  );
  return context.judgmentSchemaVersion;
}

function validateNormalized(value, context, dimensions) {
  requireCondition(value && typeof value === 'object' && !Array.isArray(value), `${context} must be an object`);
  requireExactKeys(value, Object.keys(dimensions), context);
  for (const [field, definition] of dimensionEntries(dimensions)) {
    validateDimensionValue(value[field], definition, `${context}.${field}`);
  }
}

export function validateJudgments(
  document,
  skillName,
  caseIds,
  judgePacketSha256,
  providedDimensions = null,
  expectedVersion = judgmentVersion,
) {
  const dimensions = resolveDimensions(skillName, providedDimensions);
  requireCondition(
    [legacyJudgmentVersion, judgmentVersion].includes(expectedVersion),
    `Unsupported judgment schema version: ${expectedVersion}`,
  );
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'judgments must be an object');
  requireExactKeys(document, ['schema_version', 'skill_name', 'judge', 'judge_packet_sha256', 'case_results'], 'judgments');
  requireCondition(document.schema_version === expectedVersion, `judgments must use ${expectedVersion}`);
  requireCondition(document.skill_name === skillName, `judgment skill_name must be ${skillName}`);
  nonEmptyString(document.judge, 'judgment judge');
  requireCondition(document.judge_packet_sha256 === judgePacketSha256, 'judgment judge_packet_sha256 mismatch');
  requireCondition(Array.isArray(document.case_results), 'judgment case_results must be an array');
  requireCondition(document.case_results.length === caseIds.length, 'judgment case_results count mismatch');
  const ids = document.case_results.map(row => row?.case_id);
  requireCondition(new Set(ids).size === ids.length, 'judgment case ids must be unique');
  requireCondition(caseIds.every(id => ids.includes(id)), 'judgment case ids must exactly match the run');
  for (const result of document.case_results) {
    const context = `${result.case_id} judgment`;
    requireCondition(result && typeof result === 'object' && !Array.isArray(result), `${context} must be an object`);
    requireExactKeys(
      result,
      ['case_id', 'verdict', 'normalized', 'assertion_results', 'blocked_reason', 'notes'],
      context,
    );
    requireCondition(['PASS', 'FAIL', 'BLOCKED'].includes(result.verdict), `${context}.verdict is invalid`);
    requireCondition(typeof result.notes === 'string', `${context}.notes must be a string`);
    requireCondition(typeof result.blocked_reason === 'string', `${context}.blocked_reason must be a string`);
    requireCondition(Array.isArray(result.assertion_results), `${context}.assertion_results must be an array`);
    if (result.verdict === 'BLOCKED') {
      requireCondition(result.normalized === null, `${context}.normalized must be null when BLOCKED`);
      requireCondition(result.assertion_results.length === 0, `${context}.assertion_results must be empty when BLOCKED`);
      nonEmptyString(result.blocked_reason, `${context}.blocked_reason`);
      continue;
    }
    validateNormalized(result.normalized, `${context}.normalized`, dimensions);
    requireCondition(result.blocked_reason === '', `${context}.blocked_reason must be empty when judgeable`);
    requireCondition(result.assertion_results.length > 0, `${context}.assertion_results must not be empty`);
    for (const [index, item] of result.assertion_results.entries()) {
      const itemContext = `${context}.assertion_results[${index}]`;
      requireCondition(item && typeof item === 'object' && !Array.isArray(item), `${itemContext} must be an object`);
      requireExactKeys(item, ['assertion', 'passed', 'evidence_spans', 'explanation'], itemContext);
      nonEmptyString(item.assertion, `${itemContext}.assertion`);
      requireCondition(typeof item.passed === 'boolean', `${itemContext}.passed must be boolean`);
      requireCondition(Array.isArray(item.evidence_spans), `${itemContext}.evidence_spans must be an array`);
      if (expectedVersion !== legacyJudgmentVersion) {
        requireCondition(item.evidence_spans.length > 0, `${itemContext}.evidence_spans must not be empty`);
      }
      nonEmptyString(item.explanation, `${itemContext}.explanation`);
      for (const [spanIndex, span] of item.evidence_spans.entries()) {
        const spanContext = `${itemContext}.evidence_spans[${spanIndex}]`;
        requireCondition(span && typeof span === 'object' && !Array.isArray(span), `${spanContext} must be an object`);
        requireExactKeys(span, ['start', 'end', 'quote'], spanContext);
        requireCondition(Number.isInteger(span.start) && span.start >= 0, `${spanContext}.start is invalid`);
        requireCondition(Number.isInteger(span.end) && span.end > span.start, `${spanContext}.end is invalid`);
        nonEmptyString(span.quote, `${spanContext}.quote`);
      }
    }
  }
  return document;
}

function compareJudgment(caseContract, result, response, dimensions) {
  const failures = [];
  if (result.verdict === 'BLOCKED') {
    return {
      case_id: caseContract.id,
      status: 'BLOCKED',
      failures: [],
      blocked_reason: result.blocked_reason,
    };
  }

  for (const item of result.assertion_results) {
    for (const span of item.evidence_spans) {
      if (
        span.end > response.response_text.length
        || response.response_text.slice(span.start, span.end) !== span.quote
        || span.quote.trim() === ''
      ) {
        failures.push(`invalid evidence span: ${item.assertion}`);
        break;
      }
    }
  }

  for (const [field, definition] of dimensionEntries(dimensions)) {
    if (definition.type === 'enum') {
      if (!caseContract.expected[field].includes(result.normalized[field])) {
        failures.push(`${field}=${JSON.stringify(result.normalized[field])} expected one of ${JSON.stringify(caseContract.expected[field])}`);
      }
    } else if (result.normalized[field] !== caseContract.expected[field]) {
      failures.push(`${field}=${JSON.stringify(result.normalized[field])} expected ${JSON.stringify(caseContract.expected[field])}`);
    }
  }
  for (const field of Object.keys(dimensions)) {
    if (response.self_report[field] !== result.normalized[field]) {
      failures.push(`self_report.${field} disagrees with judge normalization`);
    }
  }

  const assertionMap = new Map(result.assertion_results.map(item => [item?.assertion, item]));
  if (assertionMap.size !== result.assertion_results.length) failures.push('assertion_results contain duplicates');
  for (const assertion of caseContract.assertions) {
    const observed = assertionMap.get(assertion);
    if (!observed) {
      failures.push(`missing assertion result: ${assertion}`);
    } else if (observed.passed !== true) {
      failures.push(`failed assertion: ${assertion}`);
    } else if (observed.evidence_spans.length === 0) {
      failures.push(`missing evidence span: ${assertion}`);
    }
  }
  if (result.assertion_results.some(item => !caseContract.assertions.includes(item.assertion))) {
    failures.push('assertion_results contain unexpected assertions');
  }
  if (result.verdict === 'FAIL') failures.push('judge verdict=FAIL');
  const status = failures.length === 0 ? 'PASS' : 'FAIL';
  return { case_id: caseContract.id, status, failures };
}

function resolveJudgmentsPath(context, judgmentsPath) {
  const requestedJudgments = judgmentsPath
    ? path.isAbsolute(judgmentsPath) ? path.resolve(judgmentsPath) : path.resolve(context.resolvedRunDir, judgmentsPath)
    : path.join(context.resolvedRunDir, 'judgments.json');
  const requestedParent = path.dirname(requestedJudgments);
  requireCondition(existsSync(requestedParent), 'Judgments parent directory is missing');
  requireNoLinkedAbsolutePathComponents(requestedParent, 'Judgments parent');
  const canonicalParent = realpathSync.native(requestedParent);
  const canonicalRunDir = realpathSync.native(context.resolvedRunDir);
  requireCondition(
    samePath(canonicalParent, canonicalRunDir),
    'Judgments file must be directly inside the run directory',
  );
  const resolvedJudgments = path.join(context.resolvedRunDir, path.basename(requestedJudgments));
  if (existsSync(requestedJudgments)) {
    requireNoLinkedAbsolutePathComponents(requestedJudgments, 'Judgments');
    requireCondition(
      existsSync(resolvedJudgments)
        && samePath(realpathSync.native(requestedJudgments), realpathSync.native(resolvedJudgments)),
      'Judgments path alias does not resolve to the selected run file',
    );
  }
  return resolvedJudgments;
}

function computeGradeSummary(context, collected, resolvedJudgments) {
  requireSafeRegularFile(resolvedJudgments, context.resolvedRunDir, 'Judgments');
  const judgePacket = loadAndValidateJudgePacket(context, collected);
  const judgmentSchemaVersion = verifyJudgeScaffolding(context, judgePacket.packetSha256);
  const judgments = validateJudgments(
    readJson(resolvedJudgments),
    context.manifest.skill_name,
    context.manifest.case_ids,
    judgePacket.packetSha256,
    context.loaded.config.dimensions,
    judgmentSchemaVersion,
  );
  const resultById = new Map(judgments.case_results.map(row => [row.case_id, row]));
  const responseById = new Map(collected.responses.map(row => [row.case_id, row]));
  const caseResults = context.selectedCases.map(row => compareJudgment(
    row,
    resultById.get(row.id),
    responseById.get(row.id),
    context.loaded.config.dimensions,
  ));
  const status = caseResults.some(row => row.status === 'FAIL')
    ? 'FAIL'
    : caseResults.some(row => row.status === 'BLOCKED') ? 'BLOCKED' : 'PASS';
  const summary = {
    schema_version: 'suxi.skill.behavior_grade.v1',
    skill_name: context.manifest.skill_name,
    run_id: context.manifest.run_id,
    status,
    case_results: caseResults,
    counts: {
      pass: caseResults.filter(row => row.status === 'PASS').length,
      fail: caseResults.filter(row => row.status === 'FAIL').length,
      blocked: caseResults.filter(row => row.status === 'BLOCKED').length,
    },
    hashes: {
      behavior_contract_sha256: context.manifest.behavior_contract_sha256,
      prepare_seal_sha256: context.sealSha256,
      prepare_manifest_sha256: context.manifestSha256,
      source_snapshot_sha256: context.manifest.source_snapshot_sha256,
      judge_packet_sha256: judgePacket.packetSha256,
      judgments_sha256: fileSha256(resolvedJudgments),
      responses: collected.responseFiles.map(row => ({ case_id: row.case_id, sha256: row.sha256 })),
    },
    separation_status: 'instruction_only',
    judge_identity_verified: false,
    evidence_boundary: 'Responses, exact quote spans, and judgment fields are internally consistent for the selected packet. Filesystem isolation and evaluator identity are not independently verified.',
  };
  return { summary, judgments, judgmentSchemaVersion };
}

function requireExactJudgmentEvidence(judgments, collected) {
  const responseById = new Map(collected.responses.map(row => [row.case_id, row]));
  for (const result of judgments.case_results) {
    if (result.verdict === 'BLOCKED') continue;
    const response = responseById.get(result.case_id);
    requireCondition(response, `Saved judgment response is missing: ${result.case_id}`);
    for (const assertionResult of result.assertion_results) {
      for (const span of assertionResult.evidence_spans) {
        requireCondition(
          span.end <= response.response_text.length
            && response.response_text.slice(span.start, span.end) === span.quote
            && span.quote.trim() !== '',
          `Saved judgment has invalid evidence span: ${result.case_id}/${assertionResult.assertion}`,
        );
      }
    }
  }
}

function postGradeSealDocument(context, summary, judgmentSchemaVersion, summaryPath) {
  return {
    schema_version: gradeSealVersion,
    run_id: context.manifest.run_id,
    run_path_sha256: context.seal.run_path_sha256,
    run_manifest_schema_version: context.manifest.schema_version,
    judgment_schema_version: judgmentSchemaVersion,
    grade_schema_version: summary.schema_version,
    grade_status: summary.status,
    counts: summary.counts,
    prepare_seal_sha256: context.sealSha256,
    prepare_manifest_sha256: context.manifestSha256,
    behavior_contract_sha256: summary.hashes.behavior_contract_sha256,
    source_snapshot_sha256: summary.hashes.source_snapshot_sha256,
    judge_packet_sha256: summary.hashes.judge_packet_sha256,
    judgment_output_schema_sha256: fileSha256(path.join(
      context.resolvedRunDir,
      'judgment-output.schema.json',
    )),
    judge_prompt_sha256: fileSha256(path.join(context.resolvedRunDir, 'judge-prompt.md')),
    judgments_sha256: summary.hashes.judgments_sha256,
    responses: summary.hashes.responses,
    grade_summary_sha256: fileSha256(summaryPath),
    separation_status: summary.separation_status,
    judge_identity_verified: summary.judge_identity_verified,
    seal_scope: 'Binds the complete post-grade artifact chain outside the run directory; it is not an external signature.',
  };
}

function requireCanonicalFileText(
  filePath,
  allowedRoot,
  expectedText,
  context,
  { retryConcurrentCreate = false } = {},
) {
  const waiter = retryConcurrentCreate ? new Int32Array(new SharedArrayBuffer(4)) : null;
  const attempts = retryConcurrentCreate ? 20 : 1;
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    if (existsSync(filePath)) {
      requireSafeRegularFile(filePath, allowedRoot, context);
      if (readFileSync(filePath, 'utf8') === expectedText) return;
    }
    if (attempt + 1 < attempts) Atomics.wait(waiter, 0, 0, 10);
  }
  throw new Error(`${context} differs from recomputed result`);
}

function ensurePostGradeSeal(context, expectedSeal, { create = false } = {}) {
  const sealPath = gradeSealPathForPrepareSeal(context.sealPath);
  if (!existsSync(sealPath) && !create) return { status: 'MISSING', sealPath };
  let created = false;
  if (create) {
    requireNoReparseEscape(sealPath, path.dirname(context.resolvedRunDir));
    created = tryWriteNewText(sealPath, jsonText(expectedSeal));
  }
  requireCanonicalFileText(
    sealPath,
    path.dirname(context.resolvedRunDir),
    jsonText(expectedSeal),
    'Post-grade seal',
    { retryConcurrentCreate: create && !created },
  );
  return {
    status: 'SEALED',
    sealPath,
    grade_seal_sha256: fileSha256(sealPath),
    created,
  };
}

function loadCompletedGrade({
  root,
  runDir,
  judgmentsPath,
  allowExternalRunDir,
}) {
  nonEmptyString(runDir, 'runDir');
  const context = loadRunContext(root, runDir, { allowExternalRunDir });
  const collected = collectResponses(context);
  if (collected.missing.length > 0) {
    return { notRun: { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_case_ids: collected.missing } };
  }
  const resolvedJudgments = resolveJudgmentsPath(context, judgmentsPath);
  if (!existsSync(resolvedJudgments)) {
    return { notRun: { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_judgments: true } };
  }
  const summaryPath = path.join(context.resolvedRunDir, 'grade-summary.json');
  if (!existsSync(summaryPath)) {
    return { notRun: { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_grade_summary: true } };
  }
  requireSafeRegularFile(summaryPath, context.resolvedRunDir, 'Grade summary');
  const computed = computeGradeSummary(context, collected, resolvedJudgments);
  requireExactJudgmentEvidence(computed.judgments, collected);
  requireCondition(
    readFileSync(summaryPath, 'utf8') === jsonText(computed.summary),
    'Grade summary differs from recomputed result',
  );
  return {
    context,
    collected,
    resolvedJudgments,
    summaryPath,
    ...computed,
  };
}

function confirmPostGradeStability(options, expectedSeal) {
  const confirmed = loadCompletedGrade(options);
  requireCondition(!confirmed.notRun, 'Completed grade became incomplete during finalization');
  const recomputedSeal = postGradeSealDocument(
    confirmed.context,
    confirmed.summary,
    confirmed.judgmentSchemaVersion,
    confirmed.summaryPath,
  );
  requireCondition(
    jsonText(recomputedSeal) === jsonText(expectedSeal),
    'Graded artifacts changed during post-grade finalization',
  );
  const sealed = ensurePostGradeSeal(confirmed.context, recomputedSeal);
  requireCondition(sealed.status === 'SEALED', 'Post-grade seal disappeared during finalization');
  return { confirmed, sealed };
}

export function gradeBehaviorRun({
  root = repoRoot,
  runDir,
  judgmentsPath = '',
  allowExternalRunDir = false,
} = {}) {
  nonEmptyString(runDir, 'runDir');
  const context = loadRunContext(root, runDir, { allowExternalRunDir });
  const collected = collectResponses(context);
  if (collected.missing.length > 0) {
    return { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_case_ids: collected.missing };
  }
  const resolvedJudgments = resolveJudgmentsPath(context, judgmentsPath);
  if (!existsSync(resolvedJudgments)) {
    return { status: 'NOT_RUN', runDir: context.resolvedRunDir, missing_judgments: true };
  }
  const { summary, judgments, judgmentSchemaVersion } = computeGradeSummary(
    context,
    collected,
    resolvedJudgments,
  );
  requireExactJudgmentEvidence(judgments, collected);
  const summaryPath = path.join(context.resolvedRunDir, 'grade-summary.json');
  const summaryCreated = tryWriteNewText(summaryPath, jsonText(summary));
  requireCanonicalFileText(
    summaryPath,
    context.resolvedRunDir,
    jsonText(summary),
    'Grade summary',
    { retryConcurrentCreate: !summaryCreated },
  );
  const expectedSeal = postGradeSealDocument(
    context,
    summary,
    judgmentSchemaVersion,
    summaryPath,
  );
  ensurePostGradeSeal(
    context,
    expectedSeal,
    { create: true },
  );
  confirmPostGradeStability(
    { root, runDir, judgmentsPath, allowExternalRunDir },
    expectedSeal,
  );
  return summary;
}

export function finalizeGradeRun({
  root = repoRoot,
  runDir,
  judgmentsPath = '',
  allowExternalRunDir = false,
} = {}) {
  const completed = loadCompletedGrade({
    root,
    runDir,
    judgmentsPath,
    allowExternalRunDir,
  });
  if (completed.notRun) return completed.notRun;
  const expectedSeal = postGradeSealDocument(
    completed.context,
    completed.summary,
    completed.judgmentSchemaVersion,
    completed.summaryPath,
  );
  const sealed = ensurePostGradeSeal(
    completed.context,
    expectedSeal,
    { create: true },
  );
  confirmPostGradeStability(
    { root, runDir, judgmentsPath, allowExternalRunDir },
    expectedSeal,
  );
  return {
    schema_version: 'suxi.skill.behavior_grade_finalization.v1',
    status: 'SEALED',
    skill_name: completed.context.manifest.skill_name,
    run_id: completed.context.manifest.run_id,
    runDir: completed.context.resolvedRunDir,
    grade_status: completed.summary.status,
    counts: completed.summary.counts,
    judgment_schema_version: completed.judgmentSchemaVersion,
    sealPath: sealed.sealPath,
    grade_seal_sha256: sealed.grade_seal_sha256,
    created: sealed.created,
    evidence_boundary: 'The post-grade seal is outside the run directory and binds the complete graded artifact chain. It is not an external signature and does not verify evaluator identity or filesystem isolation.',
  };
}

export function verifyBehaviorRun({
  root = repoRoot,
  runDir,
  judgmentsPath = '',
  allowExternalRunDir = false,
} = {}) {
  const completed = loadCompletedGrade({
    root,
    runDir,
    judgmentsPath,
    allowExternalRunDir,
  });
  if (completed.notRun) return completed.notRun;
  const expectedSeal = postGradeSealDocument(
    completed.context,
    completed.summary,
    completed.judgmentSchemaVersion,
    completed.summaryPath,
  );
  const sealed = ensurePostGradeSeal(completed.context, expectedSeal);
  if (
    sealed.status === 'MISSING'
    && completed.context.manifest.schema_version !== legacyRunManifestVersion
  ) {
    return {
      status: 'NOT_RUN',
      runDir: completed.context.resolvedRunDir,
      missing_grade_seal: true,
    };
  }
  const assertionResults = completed.judgments.case_results.flatMap(row => row.assertion_results);
  const postGradeSealStatus = sealed.status === 'SEALED' ? 'SEALED' : 'LEGACY_MISSING';
  return {
    schema_version: 'suxi.skill.behavior_verification.v1',
    status: 'PASS',
    skill_name: completed.context.manifest.skill_name,
    run_id: completed.context.manifest.run_id,
    runDir: completed.context.resolvedRunDir,
    grade_status: completed.summary.status,
    run_manifest_schema_version: completed.context.manifest.schema_version,
    judgment_schema_version: completed.judgmentSchemaVersion,
    post_grade_seal_status: postGradeSealStatus,
    counts: completed.summary.counts,
    verified: {
      source_snapshot_files: completed.context.manifest.source_snapshot_files.length,
      case_workspaces: completed.context.manifest.cases.length,
      responses: completed.collected.responses.length,
      judgment_results: completed.judgments.case_results.length,
      assertions: assertionResults.length,
      evidence_spans: assertionResults.reduce((total, row) => total + row.evidence_spans.length, 0),
    },
    hashes: {
      ...completed.summary.hashes,
      grade_summary_sha256: fileSha256(completed.summaryPath),
      ...(sealed.status === 'SEALED' ? { grade_seal_sha256: sealed.grade_seal_sha256 } : {}),
    },
    read_only: true,
    separation_status: completed.summary.separation_status,
    judge_identity_verified: completed.summary.judge_identity_verified,
    evidence_boundary: postGradeSealStatus === 'SEALED'
      ? 'The saved grade and external post-grade seal were reproduced from the complete artifact chain without rewriting evidence; every saved evidence span matched exactly. The seal is not an external signature, and filesystem isolation and evaluator identity remain unverified.'
      : 'The legacy saved grade was reproduced without rewriting evidence, and every saved evidence span matched exactly, but no external post-grade seal exists. Legacy judgment v1 artifacts may omit spans on non-passing assertions. Filesystem isolation and evaluator identity remain unverified.',
  };
}

function resolveEvidenceLedgerPath(root, ledgerPath, { allowExternalLedgerPath = false } = {}) {
  const resolved = ledgerPath
    ? path.isAbsolute(ledgerPath) ? path.resolve(ledgerPath) : path.resolve(root, ledgerPath)
    : path.resolve(defaultEvidenceLedgerPath);
  if (allowExternalLedgerPath) {
    requireNoLinkedAbsolutePathComponents(resolved, 'Evidence ledger');
    requireSafeRegularFile(resolved, path.dirname(resolved), 'Evidence ledger');
  } else {
    requireCondition(isPathInside(resolved, root), 'Evidence ledger must stay inside the repository');
    requireSafeRegularFile(resolved, root, 'Evidence ledger');
  }
  return resolved;
}

function evidenceLedgerSnapshot(resolvedLedgerPath, ledgerBytes) {
  nonEmptyString(resolvedLedgerPath, 'resolvedLedgerPath');
  requireCondition(Buffer.isBuffer(ledgerBytes), 'ledgerBytes must be one frozen Buffer');
  let ledgerDocument;
  try {
    ledgerDocument = JSON.parse(ledgerBytes.toString('utf8'));
  } catch (error) {
    throw new Error(`Cannot parse evidence ledger snapshot ${resolvedLedgerPath}: ${error.message}`);
  }
  return {
    resolvedLedgerPath,
    ledgerBytes,
    ledger: validateEvidenceLedger(ledgerDocument),
    ledgerSha256: sha256(ledgerBytes),
  };
}

function readEvidenceLedgerSnapshot(root, ledgerPath, { allowExternalLedgerPath = false } = {}) {
  const resolvedLedgerPath = resolveEvidenceLedgerPath(root, ledgerPath, { allowExternalLedgerPath });
  return evidenceLedgerSnapshot(resolvedLedgerPath, readFileSync(resolvedLedgerPath));
}

function compareLedgerEntry(entry, verified) {
  const failures = [];
  const compare = (field, actual, expected) => {
    if (!isDeepStrictEqual(actual, expected)) {
      failures.push(`${field}=${JSON.stringify(actual)} expected ${JSON.stringify(expected)}`);
    }
  };
  compare('skill_name', verified.skill_name, entry.skill_name);
  compare('run_id', verified.run_id, entry.run_id);
  compare('run_manifest_schema_version', verified.run_manifest_schema_version, entry.run_manifest_schema_version);
  compare('judgment_schema_version', verified.judgment_schema_version, entry.judgment_schema_version);
  compare('grade_status', verified.grade_status, entry.grade_status);
  compare('post_grade_seal_status', verified.post_grade_seal_status, 'SEALED');
  compare('counts', verified.counts, entry.counts);
  compare('verified.cases', verified.verified.case_workspaces, entry.verified.cases);
  compare('verified.assertions', verified.verified.assertions, entry.verified.assertions);
  compare('verified.evidence_spans', verified.verified.evidence_spans, entry.verified.evidence_spans);
  for (const field of [
    'behavior_contract_sha256',
    'source_snapshot_sha256',
    'prepare_seal_sha256',
    'prepare_manifest_sha256',
    'judge_packet_sha256',
    'judgments_sha256',
    'grade_summary_sha256',
    'grade_seal_sha256',
    'responses',
  ]) {
    compare(`hashes.${field}`, verified.hashes[field], entry.hashes[field]);
  }
  return failures;
}

export function verifyBehaviorSuiteSnapshot({ root = repoRoot, resolvedLedgerPath, ledgerBytes }) {
  const snapshot = evidenceLedgerSnapshot(resolvedLedgerPath, ledgerBytes);
  const { ledger, ledgerSha256 } = snapshot;
  const skillResults = ledger.entries.map((entry) => {
    try {
      const verified = verifyBehaviorRun({ root, runDir: entry.run_path });
      if (verified.status !== 'PASS') {
        return {
          skill_name: entry.skill_name,
          run_id: entry.run_id,
          run_path: entry.run_path,
          status: verified.status,
          failures: [`run verification status=${verified.status}`],
        };
      }
      const failures = compareLedgerEntry(entry, verified);
      return {
        skill_name: entry.skill_name,
        run_id: entry.run_id,
        run_path: entry.run_path,
        status: failures.length === 0 ? 'PASS' : 'FAIL',
        grade_status: verified.grade_status,
        counts: verified.counts,
        verified: {
          cases: verified.verified.case_workspaces,
          assertions: verified.verified.assertions,
          evidence_spans: verified.verified.evidence_spans,
        },
        failures,
      };
    } catch (error) {
      const missing = /Run directory is missing|Prepare seal is missing|Evaluation root is missing/u.test(error.message);
      return {
        skill_name: entry.skill_name,
        run_id: entry.run_id,
        run_path: entry.run_path,
        status: missing ? 'NOT_RUN' : 'FAIL',
        failures: [error.message],
      };
    }
  });
  const status = skillResults.some(row => row.status === 'FAIL')
    ? 'FAIL'
    : skillResults.some(row => row.status === 'NOT_RUN') ? 'NOT_RUN' : 'PASS';
  const claimedCounts = ledger.entries.reduce((aggregate, entry) => ({
    skills: aggregate.skills + 1,
    cases: aggregate.cases + entry.verified.cases,
    pass: aggregate.pass + entry.counts.pass,
    fail: aggregate.fail + entry.counts.fail,
    blocked: aggregate.blocked + entry.counts.blocked,
    assertions: aggregate.assertions + entry.verified.assertions,
    evidence_spans: aggregate.evidence_spans + entry.verified.evidence_spans,
  }), {
    skills: 0,
    cases: 0,
    pass: 0,
    fail: 0,
    blocked: 0,
    assertions: 0,
    evidence_spans: 0,
  });
  const verifiedCounts = status === 'PASS'
    ? skillResults.reduce((aggregate, row) => ({
      skills: aggregate.skills + 1,
      cases: aggregate.cases + row.verified.cases,
      pass: aggregate.pass + row.counts.pass,
      fail: aggregate.fail + row.counts.fail,
      blocked: aggregate.blocked + row.counts.blocked,
      assertions: aggregate.assertions + row.verified.assertions,
      evidence_spans: aggregate.evidence_spans + row.verified.evidence_spans,
    }), {
      skills: 0,
      cases: 0,
      pass: 0,
      fail: 0,
      blocked: 0,
      assertions: 0,
      evidence_spans: 0,
    })
    : null;
  return {
    schema_version: 'suxi.skill.behavior_suite_verification.v1',
    status,
    scope: ledger.scope,
    ledgerPath: resolvedLedgerPath,
    ledger_sha256: ledgerSha256,
    claimed_counts: claimedCounts,
    verified_counts: verifiedCounts,
    skill_results: skillResults,
    read_only: true,
    evidence_boundary: 'Each ledger entry is independently replayed and compared with its recorded hashes and counts. claimed_counts are ledger declarations; verified_counts are emitted only when every entry passes replay. The ledger is a local index, not an external signature, and missing or mismatched runs are never filled from other entries.',
  };
}

export function verifyBehaviorSuite({
  root = repoRoot,
  ledgerPath = '',
  allowExternalLedgerPath = false,
} = {}) {
  const snapshot = readEvidenceLedgerSnapshot(root, ledgerPath, { allowExternalLedgerPath });
  return verifyBehaviorSuiteSnapshot({
    root,
    resolvedLedgerPath: snapshot.resolvedLedgerPath,
    ledgerBytes: snapshot.ledgerBytes,
  });
}

function resolveEvidenceArchiveDirectory(
  root,
  archiveDir,
  ledgerSha256,
  { allowExternalArchivePath = false, mustExist = true } = {},
) {
  const resolved = archiveDir
    ? path.isAbsolute(archiveDir) ? path.resolve(archiveDir) : path.resolve(root, archiveDir)
    : path.join(defaultEvidenceArchiveRoot, ledgerSha256.slice(0, 16));
  const allowedRoot = allowExternalArchivePath ? path.dirname(resolved) : root;
  if (!allowExternalArchivePath) {
    requireCondition(isPathInside(resolved, root), 'Evidence archive must stay inside the repository');
  }
  if (mustExist) {
    requireSafeDirectory(resolved, allowedRoot, 'Evidence archive');
    return resolved;
  }
  const parent = path.dirname(resolved);
  if (!existsSync(parent)) {
    requireNoReparseEscape(parent, allowedRoot);
    mkdirSync(parent, { recursive: true });
  }
  requireNoLinkedAbsolutePathComponents(parent, 'Evidence archive parent');
  if (existsSync(resolved)) requireSafeDirectory(resolved, allowedRoot, 'Evidence archive');
  return resolved;
}

function archiveClaimedCounts(manifest) {
  return {
    runs: manifest.entries.length,
    files: manifest.entries.reduce((total, entry) => total + entry.run_file_count, 0),
    bytes: manifest.entries.reduce((total, entry) => total + entry.run_bytes, 0),
    seals: manifest.entries.length * 2,
  };
}

function archivePhysicalPathIdentity(archiveDir) {
  const physicalPath = realpathSync.native(path.resolve(archiveDir));
  const normalizedPath = process.platform === 'win32' ? physicalPath.toLowerCase() : physicalPath;
  return {
    physicalPath,
    sha256: sha256(normalizedPath),
  };
}

function archiveSealPathFor(archiveDir) {
  const identity = archivePhysicalPathIdentity(archiveDir);
  return path.join(
    path.dirname(identity.physicalPath),
    '.seals',
    `${path.basename(identity.physicalPath)}-${identity.sha256.slice(0, 24)}.archive.json`,
  );
}

function archiveSealDocument(archiveDir, manifestSha256, sourceLedgerSha256, counts) {
  return validateEvidenceArchiveSeal({
    schema_version: evidenceArchiveSealVersion,
    archive_path_sha256: archivePhysicalPathIdentity(archiveDir).sha256,
    archive_manifest_sha256: manifestSha256,
    source_ledger_sha256: sourceLedgerSha256,
    counts,
  });
}

function ensureArchiveSealDirectory(sealPath) {
  const sealDirectory = path.dirname(sealPath);
  mkdirSync(sealDirectory, { recursive: true });
  requireNoLinkedAbsolutePathComponents(sealDirectory, 'Evidence archive seal directory');
  return sealDirectory;
}

function defaultArchiveVerifierRuntime() {
  return {
    node_version: process.version,
    v8_version: process.versions.v8,
    platform: process.platform,
    arch: process.arch,
  };
}

export function buildArchiveVerifierProfile({
  root = repoRoot,
  verifierRoot = root,
  runtimeProfile = null,
} = {}) {
  const resolvedVerifierRoot = path.resolve(verifierRoot);
  requireNoLinkedAbsolutePathComponents(resolvedVerifierRoot, 'Archive verifier root');
  const files = verifierProfileFileSpecs.map((spec) => {
    const filePath = path.join(resolvedVerifierRoot, ...spec.path.split('/'));
    requireSafeRegularFile(filePath, resolvedVerifierRoot, `Archive verifier ${spec.role}`);
    if (spec.role === 'runner' && samePath(resolvedVerifierRoot, repoRoot)) {
      requireCondition(
        samePath(filePath, scriptPath),
        'Archive verifier runner path does not match the loaded module',
      );
      return {
        role: spec.role,
        path: spec.path,
        sha256: loadedRunnerIdentity.sha256,
        bytes: loadedRunnerIdentity.bytes,
      };
    }
    const bytes = readFileSync(filePath);
    return {
      role: spec.role,
      path: spec.path,
      sha256: sha256(bytes),
      bytes: bytes.length,
    };
  });
  const runtime = validateArchiveVerifierRuntime(
    structuredClone(runtimeProfile || defaultArchiveVerifierRuntime()),
  );
  const document = validateArchiveVerifierProfile({
    schema_version: verifierProfileVersion,
    files,
    runtime,
  });
  return {
    document,
    sha256: sha256(jsonText(document)),
    runtime_sha256: sha256(jsonText(runtime)),
  };
}

function archiveVerifierReceiptPathFor(archiveDir) {
  const identity = archivePhysicalPathIdentity(archiveDir);
  return path.join(
    path.dirname(identity.physicalPath),
    '.verifier-receipts',
    `${path.basename(identity.physicalPath)}-${identity.sha256.slice(0, 24)}.verifier.json`,
  );
}

function ensureArchiveVerifierReceiptDirectory(receiptPath) {
  const receiptDirectory = path.dirname(receiptPath);
  mkdirSync(receiptDirectory, { recursive: true });
  requireNoLinkedAbsolutePathComponents(receiptDirectory, 'Archive verifier receipt directory');
  return receiptDirectory;
}

function archiveVerifierReceiptDocument(archiveDir, archiveResult, verifierProfile) {
  requireSha256(archiveResult.archive_seal_sha256, 'archive verifier receipt source archive seal');
  return validateArchiveVerifierReceipt({
    schema_version: verifierReceiptVersion,
    archive_path_sha256: archivePhysicalPathIdentity(archiveDir).sha256,
    archive_manifest_sha256: archiveResult.archive_manifest_sha256,
    source_ledger_sha256: archiveResult.source_ledger_sha256,
    archive_seal_sha256: archiveResult.archive_seal_sha256,
    verifier_profile: verifierProfile.document,
    verifier_profile_sha256: verifierProfile.sha256,
  });
}

function archiveEntryFromLiveRun(root, ledgerEntry, stagingRoot) {
  const context = loadRunContext(root, ledgerEntry.run_path);
  const postGradeSealPath = gradeSealPathForPrepareSeal(context.sealPath);
  requireSafeRegularFile(
    postGradeSealPath,
    path.dirname(context.resolvedRunDir),
    'Post-grade seal',
  );
  const runRelative = `runs/${ledgerEntry.run_id}`;
  const prepareSealRelative = `seals/${path.basename(context.sealPath)}`;
  const postGradeSealRelative = `seals/${path.basename(postGradeSealPath)}`;
  const archivedRun = path.join(stagingRoot, ...runRelative.split('/'));
  cpSync(context.resolvedRunDir, archivedRun, {
    recursive: true,
    force: false,
    errorOnExist: true,
    dereference: false,
  });
  cpSync(context.sealPath, path.join(stagingRoot, ...prepareSealRelative.split('/')), {
    force: false,
    errorOnExist: true,
  });
  cpSync(postGradeSealPath, path.join(stagingRoot, ...postGradeSealRelative.split('/')), {
    force: false,
    errorOnExist: true,
  });
  const stats = fileTreeStats(archivedRun);
  return {
    skill_name: ledgerEntry.skill_name,
    run_id: ledgerEntry.run_id,
    run_path: runRelative,
    prepare_seal_path: prepareSealRelative,
    post_grade_seal_path: postGradeSealRelative,
    run_file_count: stats.files,
    run_bytes: stats.bytes,
    run_tree_sha256: stats.sha256,
    prepare_seal_sha256: fileSha256(context.sealPath),
    post_grade_seal_sha256: fileSha256(postGradeSealPath),
  };
}

function validateArchivedFileRecord(record, context) {
  requireCondition(record && typeof record === 'object' && !Array.isArray(record), `${context} must be an object`);
  requireExactKeys(record, ['path', 'sha256', 'bytes'], context);
  requireNormalizedRelativePath(record.path, `${context}.path`);
  requireSha256(record.sha256, `${context}.sha256`);
  requireNonNegativeInteger(record.bytes, `${context}.bytes`);
  return record;
}

function validateArchivedPrepareSeal(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'archived prepare seal must be an object');
  requireExactKeys(
    document,
    [
      'schema_version',
      'run_id',
      'run_path_sha256',
      'created_at',
      'behavior_contract_sha256',
      'source_snapshot_sha256',
      'manifest_sha256',
    ],
    'archived prepare seal',
  );
  requireCondition(document.schema_version === 'suxi.skill.behavior_prepare_seal.v1', 'archived prepare seal schema_version mismatch');
  safeSegment(document.run_id, 'archived prepare seal.run_id');
  requireSha256(document.run_path_sha256, 'archived prepare seal.run_path_sha256');
  nonEmptyString(document.created_at, 'archived prepare seal.created_at');
  requireSha256(document.behavior_contract_sha256, 'archived prepare seal.behavior_contract_sha256');
  requireSha256(document.source_snapshot_sha256, 'archived prepare seal.source_snapshot_sha256');
  requireSha256(document.manifest_sha256, 'archived prepare seal.manifest_sha256');
  return document;
}

function validateArchivedRunManifest(document) {
  requireCondition(document && typeof document === 'object' && !Array.isArray(document), 'archived run manifest must be an object');
  requireExactKeys(
    document,
    [
      'schema_version',
      'run_id',
      'skill_name',
      'created_at',
      'behavior_contract_path',
      'behavior_contract_sha256',
      'source_snapshot_sha256',
      'source_snapshot_files',
      'judgment_schema_version',
      'case_ids',
      'cases',
      'model_execution',
      'external_or_product_mutation',
      'execution_boundary',
    ],
    'archived run manifest',
  );
  requireCondition(document.schema_version === runManifestVersion, `archived run manifest must use ${runManifestVersion}`);
  safeSegment(document.run_id, 'archived run manifest.run_id');
  requireCondition(governedSkills.has(document.skill_name), 'archived run manifest skill_name is unsupported');
  nonEmptyString(document.created_at, 'archived run manifest.created_at');
  requireNormalizedRelativePath(document.behavior_contract_path, 'archived run manifest.behavior_contract_path');
  requireSha256(document.behavior_contract_sha256, 'archived run manifest.behavior_contract_sha256');
  requireSha256(document.source_snapshot_sha256, 'archived run manifest.source_snapshot_sha256');
  requireCondition(document.judgment_schema_version === judgmentVersion, `archived run manifest must use ${judgmentVersion}`);
  requireCondition(document.model_execution === 'NOT_RUN', 'archived run manifest model_execution mismatch');
  requireCondition(document.external_or_product_mutation === 'N/A', 'archived run manifest mutation boundary mismatch');
  nonEmptyString(document.execution_boundary, 'archived run manifest.execution_boundary');
  requireCondition(Array.isArray(document.source_snapshot_files) && document.source_snapshot_files.length > 0, 'archived run manifest needs source_snapshot_files');
  const sourcePaths = new Set();
  for (const [index, record] of document.source_snapshot_files.entries()) {
    validateArchivedFileRecord(record, `archived source snapshot file ${index}`);
    requireCondition(!sourcePaths.has(record.path), 'archived source snapshot paths must be unique');
    sourcePaths.add(record.path);
  }
  requireCondition(
    snapshotFingerprint(document.source_snapshot_files) === document.source_snapshot_sha256,
    'archived run manifest source snapshot fingerprint mismatch',
  );
  requireCondition(
    Array.isArray(document.case_ids) && document.case_ids.length > 0,
    'archived run manifest needs case_ids',
  );
  const caseIds = document.case_ids.map((caseId, index) => safeSegment(caseId, `archived run manifest.case_ids[${index}]`));
  requireCondition(new Set(caseIds).size === caseIds.length, 'archived run manifest case_ids must be unique');
  requireCondition(Array.isArray(document.cases) && document.cases.length === caseIds.length, 'archived run manifest cases mismatch');
  for (const [index, record] of document.cases.entries()) {
    const context = `archived run case ${index}`;
    requireCondition(record && typeof record === 'object' && !Array.isArray(record), `${context} must be an object`);
    requireExactKeys(
      record,
      [
        'case_id',
        'workspace',
        'response_path',
        'skill_snapshot_sha256',
        'snapshot_files',
        'workspace_files',
        'expectations_in_workspace',
        'filesystem_isolation',
      ],
      context,
    );
    safeSegment(record.case_id, `${context}.case_id`);
    requireCondition(caseIds.includes(record.case_id), `${context}.case_id is not declared`);
    requireCondition(record.workspace === `cases/${record.case_id}/workspace`, `${context}.workspace is not canonical`);
    requireCondition(record.response_path === `cases/${record.case_id}/response.json`, `${context}.response_path is not canonical`);
    requireSha256(record.skill_snapshot_sha256, `${context}.skill_snapshot_sha256`);
    requireCondition(Array.isArray(record.snapshot_files), `${context}.snapshot_files must be an array`);
    requireCondition(Array.isArray(record.workspace_files) && record.workspace_files.length > 0, `${context}.workspace_files must be a non-empty array`);
    const workspacePaths = new Set();
    for (const [fileIndex, fileRecord] of record.workspace_files.entries()) {
      validateArchivedFileRecord(fileRecord, `${context}.workspace_files[${fileIndex}]`);
      requireCondition(!workspacePaths.has(fileRecord.path), `${context}.workspace file paths must be unique`);
      workspacePaths.add(fileRecord.path);
    }
  }
  return document;
}

function verifyArchivedRunInventory(runDir, manifest) {
  const expectedFiles = new Set([
    'manifest.json',
    'judge-packet.json',
    'judge-prompt.md',
    'judgment-output.schema.json',
    'judgments.json',
    'grade-summary.json',
  ]);
  for (const record of manifest.cases) {
    expectedFiles.add(record.response_path);
    for (const workspaceFile of record.workspace_files) {
      expectedFiles.add(`${record.workspace}/${workspaceFile.path}`);
    }
  }
  const actualFiles = listRegularFiles(runDir);
  requireCondition(
    isDeepStrictEqual(actualFiles, [...expectedFiles].sort()),
    'archived run file inventory differs from the sealed manifest and graded artifact set',
  );
}

function replayArchivedRunChain(archiveRoot, archiveEntry, ledgerEntry) {
  const runDir = path.join(archiveRoot, ...archiveEntry.run_path.split('/'));
  const prepareSealPath = path.join(archiveRoot, ...archiveEntry.prepare_seal_path.split('/'));
  const postGradeSealPath = path.join(archiveRoot, ...archiveEntry.post_grade_seal_path.split('/'));
  const manifestPath = path.join(runDir, 'manifest.json');
  requireSafeRegularFile(manifestPath, runDir, 'Archived run manifest');
  const manifestSha256 = fileSha256(manifestPath);
  const manifest = validateArchivedRunManifest(readJson(manifestPath));
  const prepareSeal = validateArchivedPrepareSeal(readJson(prepareSealPath));
  requireCondition(archiveEntry.skill_name === ledgerEntry.skill_name, 'archive entry skill_name does not match ledger');
  requireCondition(archiveEntry.run_id === ledgerEntry.run_id, 'archive entry run_id does not match ledger');
  requireCondition(archiveEntry.run_path === `runs/${ledgerEntry.run_id}`, 'archive entry run_path does not match ledger run_id');
  requireCondition(manifest.run_id === archiveEntry.run_id, 'archived run manifest id does not match archive entry');
  requireCondition(manifest.skill_name === archiveEntry.skill_name, 'archived run manifest skill does not match archive entry');
  requireCondition(manifest.schema_version === ledgerEntry.run_manifest_schema_version, 'archived run manifest schema does not match ledger');
  requireCondition(manifest.judgment_schema_version === ledgerEntry.judgment_schema_version, 'archived judgment schema does not match ledger');
  requireCondition(prepareSeal.run_id === manifest.run_id, 'archived prepare seal run_id mismatch');
  requireCondition(prepareSeal.created_at === manifest.created_at, 'archived prepare seal created_at mismatch');
  requireCondition(prepareSeal.manifest_sha256 === manifestSha256, 'archived prepare seal manifest hash mismatch');
  requireCondition(prepareSeal.behavior_contract_sha256 === manifest.behavior_contract_sha256, 'archived prepare seal behavior contract mismatch');
  requireCondition(prepareSeal.source_snapshot_sha256 === manifest.source_snapshot_sha256, 'archived prepare seal source snapshot mismatch');
  requireCondition(manifestSha256 === ledgerEntry.hashes.prepare_manifest_sha256, 'archived run manifest does not match ledger');
  requireCondition(manifest.behavior_contract_sha256 === ledgerEntry.hashes.behavior_contract_sha256, 'archived behavior contract hash does not match ledger');
  requireCondition(manifest.source_snapshot_sha256 === ledgerEntry.hashes.source_snapshot_sha256, 'archived source snapshot hash does not match ledger');
  requireCondition(fileSha256(prepareSealPath) === ledgerEntry.hashes.prepare_seal_sha256, 'archived prepare seal does not match ledger');

  const packetPath = path.join(runDir, 'judge-packet.json');
  requireSafeRegularFile(packetPath, runDir, 'Archived judge packet');
  const packet = readJson(packetPath);
  requireCondition(packet && typeof packet === 'object' && !Array.isArray(packet) && Array.isArray(packet.cases), 'Archived judge packet cases are missing');
  const archivedContract = validateBehaviorContract({
    schema_version: behaviorContractVersion,
    skill_name: manifest.skill_name,
    cases: packet.cases.map(row => ({
      id: row?.case_id,
      source_eval_id: `archived:${row?.case_id || 'missing'}`,
      prompt: row?.user_request,
      expected: row?.expected,
      assertions: row?.assertions,
    })),
  }, manifest.skill_name);
  const config = governedSkills.get(manifest.skill_name);
  const context = {
    resolvedRunDir: runDir,
    manifest,
    manifestSha256,
    seal: prepareSeal,
    sealPath: prepareSealPath,
    sealSha256: fileSha256(prepareSealPath),
    judgmentSchemaVersion: manifest.judgment_schema_version,
    loaded: { config },
    selectedCases: archivedContract.cases,
  };
  verifyPreparedWorkspaces(context);
  verifyArchivedRunInventory(runDir, manifest);
  const collected = collectResponses(context);
  requireCondition(collected.missing.length === 0, `Archived responses are missing: ${collected.missing.join(', ')}`);
  const judgmentsPath = path.join(runDir, 'judgments.json');
  const summaryPath = path.join(runDir, 'grade-summary.json');
  requireSafeRegularFile(summaryPath, runDir, 'Archived grade summary');
  const computed = computeGradeSummary(context, collected, judgmentsPath);
  requireExactJudgmentEvidence(computed.judgments, collected);
  requireCondition(
    readFileSync(summaryPath, 'utf8') === jsonText(computed.summary),
    'Archived grade summary differs from full replay',
  );
  const expectedPostGradeSeal = postGradeSealDocument(
    context,
    computed.summary,
    computed.judgmentSchemaVersion,
    summaryPath,
  );
  const actualPostGradeSeal = readJson(postGradeSealPath);
  requireCondition(
    jsonText(actualPostGradeSeal) === jsonText(expectedPostGradeSeal),
    'Archived post-grade seal differs from full replay',
  );
  requireCondition(fileSha256(postGradeSealPath) === ledgerEntry.hashes.grade_seal_sha256, 'archived post-grade seal does not match ledger');
  const assertionResults = computed.judgments.case_results.flatMap(row => row.assertion_results);
  return {
    grade_status: computed.summary.status,
    counts: computed.summary.counts,
    cases: manifest.cases.length,
    responses: collected.responses.length,
    assertions: assertionResults.length,
    evidence_spans: assertionResults.reduce((total, row) => total + row.evidence_spans.length, 0),
  };
}

function compareArchiveEntry(archiveRoot, archiveEntry, ledgerEntry) {
  const failures = [];
  const compare = (field, actual, expected) => {
    if (!isDeepStrictEqual(actual, expected)) {
      failures.push(`${field}=${JSON.stringify(actual)} expected ${JSON.stringify(expected)}`);
    }
  };
  const runDir = path.join(archiveRoot, ...archiveEntry.run_path.split('/'));
  const prepareSealPath = path.join(archiveRoot, ...archiveEntry.prepare_seal_path.split('/'));
  const postGradeSealPath = path.join(archiveRoot, ...archiveEntry.post_grade_seal_path.split('/'));
  requireSafeDirectory(runDir, archiveRoot, `${archiveEntry.run_id} archived run`);
  requireSafeRegularFile(prepareSealPath, archiveRoot, `${archiveEntry.run_id} archived prepare seal`);
  requireSafeRegularFile(postGradeSealPath, archiveRoot, `${archiveEntry.run_id} archived post-grade seal`);
  const stats = fileTreeStats(runDir);
  compare('run_file_count', stats.files, archiveEntry.run_file_count);
  compare('run_bytes', stats.bytes, archiveEntry.run_bytes);
  compare('run tree sha256', stats.sha256, archiveEntry.run_tree_sha256);
  compare('archive entry run_id', archiveEntry.run_id, ledgerEntry.run_id);
  compare('archive entry run_path', archiveEntry.run_path, `runs/${ledgerEntry.run_id}`);
  compare('prepare seal archive hash', fileSha256(prepareSealPath), archiveEntry.prepare_seal_sha256);
  compare('post-grade seal archive hash', fileSha256(postGradeSealPath), archiveEntry.post_grade_seal_sha256);
  compare('prepare_seal_sha256', archiveEntry.prepare_seal_sha256, ledgerEntry.hashes.prepare_seal_sha256);
  compare('post_grade_seal_sha256', archiveEntry.post_grade_seal_sha256, ledgerEntry.hashes.grade_seal_sha256);
  compare(
    'prepare_manifest_sha256',
    fileSha256(path.join(runDir, 'manifest.json')),
    ledgerEntry.hashes.prepare_manifest_sha256,
  );
  compare(
    'judge_packet_sha256',
    fileSha256(path.join(runDir, 'judge-packet.json')),
    ledgerEntry.hashes.judge_packet_sha256,
  );
  compare(
    'judgments_sha256',
    fileSha256(path.join(runDir, 'judgments.json')),
    ledgerEntry.hashes.judgments_sha256,
  );
  compare(
    'grade_summary_sha256',
    fileSha256(path.join(runDir, 'grade-summary.json')),
    ledgerEntry.hashes.grade_summary_sha256,
  );
  const manifest = readJson(path.join(runDir, 'manifest.json'));
  compare('manifest.skill_name', manifest.skill_name, ledgerEntry.skill_name);
  compare('manifest.run_id', manifest.run_id, ledgerEntry.run_id);
  compare('manifest.source_snapshot_sha256', manifest.source_snapshot_sha256, ledgerEntry.hashes.source_snapshot_sha256);
  compare('manifest.behavior_contract_sha256', manifest.behavior_contract_sha256, ledgerEntry.hashes.behavior_contract_sha256);
  const gradeSummary = readJson(path.join(runDir, 'grade-summary.json'));
  compare('grade_summary.skill_name', gradeSummary.skill_name, ledgerEntry.skill_name);
  compare('grade_summary.run_id', gradeSummary.run_id, ledgerEntry.run_id);
  compare('grade_summary.status', gradeSummary.status, ledgerEntry.grade_status);
  compare('grade_summary.counts', gradeSummary.counts, ledgerEntry.counts);
  for (const response of ledgerEntry.hashes.responses) {
    compare(
      `response.${response.case_id}`,
      fileSha256(path.join(runDir, 'cases', response.case_id, 'response.json')),
      response.sha256,
    );
  }
  const replayed = replayArchivedRunChain(archiveRoot, archiveEntry, ledgerEntry);
  compare('full replay grade_status', replayed.grade_status, ledgerEntry.grade_status);
  compare('full replay counts', replayed.counts, ledgerEntry.counts);
  compare('full replay verified.cases', replayed.cases, ledgerEntry.verified.cases);
  compare('full replay response count', replayed.responses, ledgerEntry.hashes.responses.length);
  compare('full replay verified.assertions', replayed.assertions, ledgerEntry.verified.assertions);
  compare('full replay verified.evidence_spans', replayed.evidence_spans, ledgerEntry.verified.evidence_spans);
  return failures;
}

function inspectEvidenceArchiveContents({
  root = repoRoot,
  ledgerPath = '',
  archiveDir = '',
  allowExternalLedgerPath = false,
  allowExternalArchivePath = false,
  providedLedgerSnapshot = null,
} = {}) {
  const snapshot = providedLedgerSnapshot
    ? evidenceLedgerSnapshot(
      providedLedgerSnapshot.resolvedLedgerPath,
      providedLedgerSnapshot.ledgerBytes,
    )
    : readEvidenceLedgerSnapshot(root, ledgerPath, { allowExternalLedgerPath });
  const {
    resolvedLedgerPath,
    ledger,
    ledgerSha256,
  } = snapshot;
  const resolvedArchiveDir = resolveEvidenceArchiveDirectory(
    root,
    archiveDir,
    ledgerSha256,
    { allowExternalArchivePath, mustExist: true },
  );
  const manifestPath = path.join(resolvedArchiveDir, 'archive-manifest.json');
  requireSafeRegularFile(manifestPath, resolvedArchiveDir, 'Evidence archive manifest');
  const manifest = validateEvidenceArchiveManifest(readJson(manifestPath));
  const sourceLedgerPath = path.join(
    resolvedArchiveDir,
    ...manifest.source_ledger_path.split('/'),
  );
  requireSafeRegularFile(sourceLedgerPath, resolvedArchiveDir, 'Archived source ledger');
  const archiveFailures = [];
  if (fileSha256(sourceLedgerPath) !== manifest.source_ledger_sha256) {
    archiveFailures.push('archived source ledger hash mismatch');
  }
  if (manifest.source_ledger_sha256 !== ledgerSha256) {
    archiveFailures.push('archive source ledger does not match selected ledger');
  }
  const archiveFiles = listRegularFiles(resolvedArchiveDir);
  const expectedStandaloneFiles = new Set([
    'archive-manifest.json',
    manifest.source_ledger_path,
    ...manifest.entries.flatMap(entry => [
      entry.prepare_seal_path,
      entry.post_grade_seal_path,
    ]),
  ]);
  const unexpectedFiles = archiveFiles.filter(relativePath => (
    !expectedStandaloneFiles.has(relativePath)
      && !manifest.entries.some(entry => relativePath.startsWith(`${entry.run_path}/`))
  ));
  if (unexpectedFiles.length > 0) {
    archiveFailures.push(`archive contains unexpected files: ${unexpectedFiles.join(', ')}`);
  }
  const ledgerBySkill = new Map(ledger.entries.map(entry => [entry.skill_name, entry]));
  if (!isDeepStrictEqual(
    manifest.entries.map(entry => entry.skill_name).sort(),
    ledger.entries.map(entry => entry.skill_name).sort(),
  )) {
    archiveFailures.push('archive entries do not match ledger skill set');
  }
  const runResults = manifest.entries.map((entry) => {
    const ledgerEntry = ledgerBySkill.get(entry.skill_name);
    if (!ledgerEntry) {
      return {
        skill_name: entry.skill_name,
        run_id: entry.run_id,
        status: 'FAIL',
        failures: ['archive skill is missing from ledger'],
      };
    }
    try {
      const failures = compareArchiveEntry(resolvedArchiveDir, entry, ledgerEntry);
      return {
        skill_name: entry.skill_name,
        run_id: entry.run_id,
        status: failures.length === 0 ? 'PASS' : 'FAIL',
        failures,
      };
    } catch (error) {
      return {
        skill_name: entry.skill_name,
        run_id: entry.run_id,
        status: 'FAIL',
        failures: [error.message],
      };
    }
  });
  const runsRoot = path.join(resolvedArchiveDir, 'runs');
  const sealsRoot = path.join(resolvedArchiveDir, 'seals');
  requireSafeDirectory(runsRoot, resolvedArchiveDir, 'Evidence archive runs root');
  requireSafeDirectory(sealsRoot, resolvedArchiveDir, 'Evidence archive seals root');
  const runRootEntries = readdirSync(runsRoot, { withFileTypes: true });
  const actualRunDirectories = runRootEntries.filter(entry => entry.isDirectory()).map(entry => entry.name).sort();
  const expectedRunDirectories = manifest.entries.map(entry => entry.run_id).sort();
  if (
    runRootEntries.some(entry => entry.isSymbolicLink())
    || !isDeepStrictEqual(actualRunDirectories, expectedRunDirectories)
  ) {
    archiveFailures.push('archive runs root does not contain exactly one canonical directory per manifest entry');
  }
  const sealRootEntries = readdirSync(sealsRoot, { withFileTypes: true });
  if (sealRootEntries.some(entry => !entry.isFile() || entry.isSymbolicLink())) {
    archiveFailures.push('archive seals root must contain regular files only');
  }
  const physicalRunStats = fileTreeStats(runsRoot);
  const physicalCounts = {
    runs: actualRunDirectories.length,
    files: physicalRunStats.files,
    bytes: physicalRunStats.bytes,
    seals: listRegularFiles(sealsRoot).length,
  };
  const claimedCounts = archiveClaimedCounts(manifest);
  if (!isDeepStrictEqual(physicalCounts, claimedCounts)) {
    archiveFailures.push(`archive claimed counts ${JSON.stringify(claimedCounts)} do not match unique physical counts ${JSON.stringify(physicalCounts)}`);
  }
  const status = archiveFailures.length > 0 || runResults.some(row => row.status === 'FAIL')
    ? 'FAIL'
    : 'PASS';
  return {
    manifest,
    manifestPath,
    resolvedArchiveDir,
    result: {
      schema_version: 'suxi.skill.behavior_archive_verification.v1',
      status,
      archiveDir: resolvedArchiveDir,
      archive_manifest_sha256: fileSha256(manifestPath),
      source_ledger_sha256: ledgerSha256,
      claimed_counts: claimedCounts,
      verified_counts: status === 'PASS' ? physicalCounts : null,
      archive_failures: archiveFailures,
      run_results: runResults,
      read_only: true,
      evidence_boundary: 'This verifies durable byte preservation, canonical relative-path structure, unique physical counts, source-ledger binding, prepare/workspace replay, judgment and exact-span replay, post-grade seal replay, run-tree fingerprints, and selected artifact hashes. It does not re-execute a respondent or model, independently verify judge identity, or convert local hashes into an external signature.',
    },
  };
}

function inspectArchiveVerifierIdentity({
  archiveDir,
  archiveResult,
}) {
  const receiptPath = archiveVerifierReceiptPathFor(archiveDir);
  let currentProfile = null;
  try {
    currentProfile = buildArchiveVerifierProfile({ root: repoRoot });
  } catch (error) {
    return {
      status: 'FAIL',
      receiptPath,
      receiptSha256: null,
      currentProfileSha256: null,
      boundProfileSha256: null,
      failures: [error.message],
    };
  }
  if (!existsSync(receiptPath)) {
    return {
      status: 'UNBOUND',
      receiptPath,
      receiptSha256: null,
      currentProfileSha256: currentProfile.sha256,
      boundProfileSha256: null,
      failures: [`archive verifier receipt is missing: ${receiptPath}`],
    };
  }
  let observedReceiptSha256 = null;
  try {
    requireSafeRegularFile(
      receiptPath,
      path.dirname(path.dirname(receiptPath)),
      'Archive verifier receipt',
    );
    const receiptBytes = readFileSync(receiptPath);
    observedReceiptSha256 = sha256(receiptBytes);
    const actualReceipt = validateArchiveVerifierReceipt(
      JSON.parse(receiptBytes.toString('utf8')),
    );
    const expectedReceipt = archiveVerifierReceiptDocument(
      archiveDir,
      archiveResult,
      currentProfile,
    );
    const matches = isDeepStrictEqual(actualReceipt, expectedReceipt);
    return {
      status: matches ? 'MATCH' : 'MISMATCH',
      receiptPath,
      receiptSha256: observedReceiptSha256,
      currentProfileSha256: currentProfile.sha256,
      boundProfileSha256: actualReceipt.verifier_profile_sha256,
      failures: matches
        ? []
        : ['archive verifier receipt does not match the archive identity, external seal, verifier files, or runtime profile'],
    };
  } catch (error) {
    return {
      status: 'FAIL',
      receiptPath,
      receiptSha256: observedReceiptSha256,
      currentProfileSha256: currentProfile.sha256,
      boundProfileSha256: null,
      failures: [error.message],
    };
  }
}

export function verifyEvidenceArchive(options = {}) {
  const inspected = inspectEvidenceArchiveContents(options);
  const base = inspected.result;
  const sealPath = archiveSealPathFor(inspected.resolvedArchiveDir);
  const sealFailures = [];
  let sealStatus = 'MISSING';
  let sealSha256 = null;
  if (existsSync(sealPath)) {
    try {
      requireSafeRegularFile(
        sealPath,
        path.dirname(path.dirname(sealPath)),
        'Evidence archive external seal',
      );
      sealSha256 = fileSha256(sealPath);
      const actualSeal = validateEvidenceArchiveSeal(readJson(sealPath));
      const expectedSeal = archiveSealDocument(
        inspected.resolvedArchiveDir,
        base.archive_manifest_sha256,
        base.source_ledger_sha256,
        base.claimed_counts,
      );
      if (!isDeepStrictEqual(actualSeal, expectedSeal)) {
        sealFailures.push('external archive seal does not match the archive manifest, selected ledger, counts, or physical archive path');
        sealStatus = 'FAIL';
      } else {
        sealStatus = 'SEALED';
      }
    } catch (error) {
      sealFailures.push(error.message);
      sealStatus = 'FAIL';
    }
  } else {
    sealFailures.push(`external archive seal is missing: ${sealPath}`);
  }
  const archiveFailures = [...base.archive_failures, ...sealFailures];
  const status = base.status === 'FAIL' || sealStatus === 'FAIL'
    ? 'FAIL'
    : sealStatus === 'MISSING' ? 'NOT_RUN' : 'PASS';
  const contentResult = {
    ...base,
    status,
    content_status: status,
    verified_counts: status === 'PASS' ? base.verified_counts : null,
    archive_failures: archiveFailures,
    archive_seal_status: sealStatus,
    archive_seal_path: sealPath,
    archive_seal_sha256: sealSha256,
  };
  const verifierIdentity = inspectArchiveVerifierIdentity({
    archiveDir: inspected.resolvedArchiveDir,
    archiveResult: contentResult,
  });
  const reproducibilityStatus = status !== 'PASS'
    ? status === 'NOT_RUN' ? 'NOT_RUN' : 'FAIL'
    : verifierIdentity.status === 'MATCH'
      ? 'PASS'
      : verifierIdentity.status === 'UNBOUND' ? 'NOT_RUN' : 'FAIL';
  return {
    ...contentResult,
    verifier_identity_status: verifierIdentity.status,
    reproducibility_status: reproducibilityStatus,
    reproducibility_verified_counts: reproducibilityStatus === 'PASS'
      ? contentResult.verified_counts
      : null,
    verifier_receipt_path: verifierIdentity.receiptPath,
    verifier_receipt_sha256: verifierIdentity.receiptSha256,
    current_verifier_profile_sha256: verifierIdentity.currentProfileSha256,
    bound_verifier_profile_sha256: verifierIdentity.boundProfileSha256,
    verifier_identity_failures: verifierIdentity.failures,
    evidence_boundary: 'content_status performs deterministic replay of the archived prepare/workspace, response, judgment, exact-span, grade-summary, post-grade, canonical-path, unique-count, source-ledger, run-tree, artifact-hash, and external archive-seal chain. reproducibility_status separately requires a local append-only receipt matching the current runner, fixed test contracts, and Node/V8/platform/arch profile. The receipt records local provenance only: it does not prove the tests executed, re-execute a respondent or model, independently verify judge identity, or create an external signature. A same-account actor able to rewrite the complete code, ledger, archive, and sidecars remains outside this boundary.',
  };
}

export function finalizeEvidenceArchive({
  root = repoRoot,
  ledgerPath = '',
  archiveDir = '',
  expectedManifestSha256 = '',
  expectedSourceLedgerSha256 = '',
  allowExternalLedgerPath = false,
  allowExternalArchivePath = false,
} = {}) {
  requireSha256(expectedManifestSha256, '--expected-manifest-sha256');
  requireSha256(expectedSourceLedgerSha256, '--expected-source-ledger-sha256');
  const inspected = inspectEvidenceArchiveContents({
    root,
    ledgerPath,
    archiveDir,
    allowExternalLedgerPath,
    allowExternalArchivePath,
  });
  requireCondition(
    inspected.result.status === 'PASS',
    `Cannot seal evidence archive with content status ${inspected.result.status}`,
  );
  requireCondition(
    inspected.result.archive_manifest_sha256 === expectedManifestSha256,
    `Evidence archive manifest hash ${inspected.result.archive_manifest_sha256} does not match the expected hash ${expectedManifestSha256}`,
  );
  requireCondition(
    inspected.result.source_ledger_sha256 === expectedSourceLedgerSha256,
    `Evidence archive source ledger hash ${inspected.result.source_ledger_sha256} does not match the expected hash ${expectedSourceLedgerSha256}`,
  );
  const sealPath = archiveSealPathFor(inspected.resolvedArchiveDir);
  ensureArchiveSealDirectory(sealPath);
  const expectedSeal = archiveSealDocument(
    inspected.resolvedArchiveDir,
    inspected.result.archive_manifest_sha256,
    inspected.result.source_ledger_sha256,
    inspected.result.claimed_counts,
  );
  const created = tryWriteNewText(sealPath, jsonText(expectedSeal));
  if (!created) {
    requireSafeRegularFile(
      sealPath,
      path.dirname(path.dirname(sealPath)),
      'Evidence archive external seal',
    );
    const actualSeal = validateEvidenceArchiveSeal(readJson(sealPath));
    requireCondition(
      isDeepStrictEqual(actualSeal, expectedSeal),
      `Refusing to replace mismatched evidence archive seal ${sealPath}`,
    );
  }
  const verified = verifyEvidenceArchive({
    root,
    ledgerPath,
    archiveDir: inspected.resolvedArchiveDir,
    allowExternalLedgerPath,
    allowExternalArchivePath: true,
  });
  requireCondition(
    verified.status === 'PASS',
    `Finalized evidence archive failed sealed verification: ${verified.archive_failures.join('; ')}`,
  );
  return {
    schema_version: 'suxi.skill.behavior_archive_finalization.v1',
    status: 'SEALED',
    archiveDir: inspected.resolvedArchiveDir,
    archive_manifest_sha256: inspected.result.archive_manifest_sha256,
    source_ledger_sha256: inspected.result.source_ledger_sha256,
    archive_seal_path: sealPath,
    archive_seal_sha256: verified.archive_seal_sha256,
    counts: verified.verified_counts,
    created,
  };
}

export function finalizeArchiveVerifierReceipt({
  root = repoRoot,
  ledgerPath = '',
  archiveDir = '',
  expectedArchiveSealSha256 = '',
  expectedRunnerSha256 = '',
  expectedBehaviorTestSha256 = '',
  expectedContractTestSha256 = '',
  expectedRuntimeSha256 = '',
  allowExternalLedgerPath = false,
  allowExternalArchivePath = false,
} = {}) {
  for (const [value, context] of [
    [expectedArchiveSealSha256, '--expected-archive-seal-sha256'],
    [expectedRunnerSha256, '--expected-runner-sha256'],
    [expectedBehaviorTestSha256, '--expected-behavior-test-sha256'],
    [expectedContractTestSha256, '--expected-contract-test-sha256'],
    [expectedRuntimeSha256, '--expected-runtime-sha256'],
  ]) {
    requireSha256(value, context);
  }
  const archiveResult = verifyEvidenceArchive({
    root,
    ledgerPath,
    archiveDir,
    allowExternalLedgerPath,
    allowExternalArchivePath,
  });
  requireCondition(
    archiveResult.content_status === 'PASS' && archiveResult.archive_seal_status === 'SEALED',
    `Cannot bind verifier receipt to archive content status ${archiveResult.content_status}`,
  );
  requireCondition(
    archiveResult.archive_seal_sha256 === expectedArchiveSealSha256,
    `Archive seal hash ${archiveResult.archive_seal_sha256} does not match the expected hash ${expectedArchiveSealSha256}`,
  );
  const profile = buildArchiveVerifierProfile({ root: repoRoot });
  const byRole = new Map(profile.document.files.map(file => [file.role, file]));
  for (const [role, expected, label] of [
    ['runner', expectedRunnerSha256, 'runner'],
    ['behavior_test', expectedBehaviorTestSha256, 'behavior test'],
    ['contract_test', expectedContractTestSha256, 'contract test'],
  ]) {
    const actual = byRole.get(role).sha256;
    requireCondition(
      actual === expected,
      `Archive verifier ${label} hash ${actual} does not match the expected hash ${expected}`,
    );
  }
  requireCondition(
    profile.runtime_sha256 === expectedRuntimeSha256,
    `Archive verifier runtime hash ${profile.runtime_sha256} does not match the expected hash ${expectedRuntimeSha256}`,
  );
  const receiptPath = archiveVerifierReceiptPathFor(archiveResult.archiveDir);
  ensureArchiveVerifierReceiptDirectory(receiptPath);
  const expectedReceipt = archiveVerifierReceiptDocument(
    archiveResult.archiveDir,
    archiveResult,
    profile,
  );
  const created = tryWriteNewText(receiptPath, jsonText(expectedReceipt));
  if (!created) {
    requireSafeRegularFile(
      receiptPath,
      path.dirname(path.dirname(receiptPath)),
      'Archive verifier receipt',
    );
    const actualReceipt = validateArchiveVerifierReceipt(readJson(receiptPath));
    requireCondition(
      isDeepStrictEqual(actualReceipt, expectedReceipt),
      `Refusing to replace mismatched archive verifier receipt ${receiptPath}`,
    );
  }
  const verified = verifyEvidenceArchive({
    root,
    ledgerPath,
    archiveDir: archiveResult.archiveDir,
    allowExternalLedgerPath,
    allowExternalArchivePath: true,
  });
  requireCondition(
    verified.content_status === 'PASS'
      && verified.verifier_identity_status === 'MATCH'
      && verified.reproducibility_status === 'PASS',
    `Finalized archive verifier receipt failed verification: ${verified.verifier_identity_failures.join('; ')}`,
  );
  requireCondition(
    verified.archive_manifest_sha256 === archiveResult.archive_manifest_sha256
      && verified.source_ledger_sha256 === archiveResult.source_ledger_sha256
      && verified.archive_seal_sha256 === archiveResult.archive_seal_sha256,
    'Archive identity changed while the verifier receipt was finalized',
  );
  return {
    schema_version: 'suxi.skill.behavior_archive_verifier_finalization.v1',
    status: 'BOUND',
    archiveDir: archiveResult.archiveDir,
    archive_manifest_sha256: archiveResult.archive_manifest_sha256,
    source_ledger_sha256: archiveResult.source_ledger_sha256,
    archive_seal_sha256: archiveResult.archive_seal_sha256,
    verifier_profile_sha256: profile.sha256,
    verifier_receipt_path: receiptPath,
    verifier_receipt_sha256: verified.verifier_receipt_sha256,
    reproducibility_status: verified.reproducibility_status,
    created,
    evidence_boundary: 'This local receipt binds archive identity to the current runner, fixed test-contract files, and Node/V8/platform/arch profile. It does not prove that the tests executed, independently verify judge identity, or create an external signature.',
  };
}

export function archiveEvidenceSuite({
  root = repoRoot,
  ledgerPath = '',
  archiveDir = '',
  allowExternalLedgerPath = false,
  allowExternalArchivePath = false,
} = {}) {
  const resolvedLedgerPath = resolveEvidenceLedgerPath(root, ledgerPath, { allowExternalLedgerPath });
  const frozenLedgerBytes = readFileSync(resolvedLedgerPath);
  const ledgerSha256 = sha256(frozenLedgerBytes);
  let frozenLedgerDocument;
  try {
    frozenLedgerDocument = JSON.parse(frozenLedgerBytes.toString('utf8'));
  } catch (error) {
    throw new Error(`Cannot parse frozen evidence ledger ${resolvedLedgerPath}: ${error.message}`);
  }
  const ledger = validateEvidenceLedger(frozenLedgerDocument);
  const suite = verifyBehaviorSuiteSnapshot({
    root,
    resolvedLedgerPath,
    ledgerBytes: frozenLedgerBytes,
  });
  requireCondition(suite.status === 'PASS', `Cannot archive evidence suite with status ${suite.status}`);
  requireCondition(
    suite.ledger_sha256 === ledgerSha256,
    'Evidence ledger changed between snapshot and suite replay',
  );
  const resolvedArchiveDir = resolveEvidenceArchiveDirectory(
    root,
    archiveDir,
    ledgerSha256,
    { allowExternalArchivePath, mustExist: false },
  );
  if (existsSync(resolvedArchiveDir)) {
    const verified = verifyEvidenceArchive({
      root,
      archiveDir: resolvedArchiveDir,
      allowExternalArchivePath: true,
      providedLedgerSnapshot: {
        resolvedLedgerPath,
        ledgerBytes: frozenLedgerBytes,
      },
    });
    requireCondition(verified.status === 'PASS', 'Existing evidence archive does not match current ledger');
    requireCondition(
      verified.source_ledger_sha256 === ledgerSha256,
      'Existing evidence archive does not match the frozen ledger snapshot',
    );
    return {
      schema_version: 'suxi.skill.behavior_archive_result.v1',
      status: 'ARCHIVED',
      archiveDir: resolvedArchiveDir,
      archive_manifest_sha256: verified.archive_manifest_sha256,
      source_ledger_sha256: verified.source_ledger_sha256,
      archive_seal_path: verified.archive_seal_path,
      archive_seal_sha256: verified.archive_seal_sha256,
      archive_seal_status: verified.archive_seal_status,
      verifier_identity_status: verified.verifier_identity_status,
      reproducibility_status: verified.reproducibility_status,
      counts: verified.verified_counts,
      created: false,
    };
  }
  const parent = path.dirname(resolvedArchiveDir);
  const staging = mkdtempSync(path.join(parent, `.${path.basename(resolvedArchiveDir)}.staging-`));
  try {
    mkdirSync(path.join(staging, 'runs'), { recursive: true });
    mkdirSync(path.join(staging, 'seals'), { recursive: true });
    writeNewText(path.join(staging, 'source-ledger.json'), frozenLedgerBytes.toString('utf8'));
    const entries = ledger.entries.map(entry => archiveEntryFromLiveRun(root, entry, staging));
    const manifest = validateEvidenceArchiveManifest({
      schema_version: evidenceArchiveVersion,
      source_ledger_path: 'source-ledger.json',
      source_ledger_sha256: ledgerSha256,
      entries,
    });
    writeNewJson(path.join(staging, 'archive-manifest.json'), manifest);
    const staged = inspectEvidenceArchiveContents({
      root,
      ledgerPath: path.join(staging, 'source-ledger.json'),
      archiveDir: staging,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    requireCondition(
      staged.result.status === 'PASS',
      `Staged evidence archive failed full replay: ${[
        ...staged.result.archive_failures,
        ...staged.result.run_results.flatMap(row => row.failures),
      ].join('; ')}`,
    );
    requireCondition(
      fileSha256(resolvedLedgerPath) === ledgerSha256,
      'Evidence ledger changed while the archive was staged',
    );
    renameSync(staging, resolvedArchiveDir);
  } catch (error) {
    if (
      existsSync(staging)
      && isPathInside(staging, parent)
      && path.basename(staging).startsWith(`.${path.basename(resolvedArchiveDir)}.staging-`)
      && !lstatSync(staging).isSymbolicLink()
    ) {
      rmSync(staging, { recursive: true, force: true });
    }
    throw error;
  }
  requireCondition(
    fileSha256(resolvedLedgerPath) === ledgerSha256,
    'Evidence ledger changed before archive sealing',
  );
  finalizeEvidenceArchive({
    root,
    ledgerPath: resolvedLedgerPath,
    archiveDir: resolvedArchiveDir,
    expectedManifestSha256: fileSha256(path.join(resolvedArchiveDir, 'archive-manifest.json')),
    expectedSourceLedgerSha256: ledgerSha256,
    allowExternalLedgerPath: true,
    allowExternalArchivePath: true,
  });
  const verified = verifyEvidenceArchive({
    root,
    ledgerPath: resolvedLedgerPath,
    archiveDir: resolvedArchiveDir,
    allowExternalLedgerPath: true,
    allowExternalArchivePath: true,
  });
  requireCondition(verified.status === 'PASS', 'Created evidence archive failed verification');
  return {
    schema_version: 'suxi.skill.behavior_archive_result.v1',
    status: 'ARCHIVED',
    archiveDir: resolvedArchiveDir,
    archive_manifest_sha256: verified.archive_manifest_sha256,
    source_ledger_sha256: verified.source_ledger_sha256,
    archive_seal_path: verified.archive_seal_path,
    archive_seal_sha256: verified.archive_seal_sha256,
    archive_seal_status: verified.archive_seal_status,
    verifier_identity_status: verified.verifier_identity_status,
    reproducibility_status: verified.reproducibility_status,
    counts: verified.verified_counts,
    created: true,
  };
}

function printHelp() {
  process.stdout.write(`SUXIOS Skill behavior evaluation packet\n\n`);
  process.stdout.write(`This tool never calls a model or API. It prepares blind respondent workspaces, builds a judge packet after responses exist, and grades structured judgments.\n\n`);
  process.stdout.write(`Run directories stay under the system temporary root ${behaviorEvalTempRoot}; --output-dir accepts a child name there, not an arbitrary filesystem path.\n\n`);
  process.stdout.write(`Commands:\n`);
  process.stdout.write(`  prepare --skill=suxi-product-decision [--cases=PD-BEH-001,PD-BEH-002] [--output-dir=path]\n`);
  process.stdout.write(`  build-judge --run-dir=path\n`);
  process.stdout.write(`  grade --run-dir=path [--judgments=path]  # writes grade summary and external post-grade seal\n`);
  process.stdout.write(`  finalize-grade --run-dir=path [--judgments=path]  # idempotent recovery/sealing\n`);
  process.stdout.write(`  verify --run-dir=path [--judgments=path]  # read-only replay; preserves grade_status\n`);
  process.stdout.write(`  verify-suite [--ledger=evals/suxi-skill-behavior-evidence.json]  # read-only governed-skill aggregate\n`);
  process.stdout.write(`  archive-suite [--ledger=path] [--archive-dir=path]  # durable byte archive plus external local seal; no overwrite\n`);
  process.stdout.write(`  finalize-archive --archive-dir=path --expected-manifest-sha256=sha256 --expected-source-ledger-sha256=sha256 [--ledger=path]  # explicitly seal a fully replayed pre-existing archive from a prior receipt\n`);
  process.stdout.write(`  finalize-verifier-receipt --archive-dir=path --expected-archive-seal-sha256=sha256 --expected-runner-sha256=sha256 --expected-behavior-test-sha256=sha256 --expected-contract-test-sha256=sha256 --expected-runtime-sha256=sha256 [--ledger=path]  # bind local verifier and runtime identity without claiming test execution\n`);
  process.stdout.write(`  verify-archive [--ledger=path] [--archive-dir=path]  # read-only archive and external-seal integrity\n`);
}

function main() {
  try {
    const options = parseArgs(process.argv.slice(2));
    if (options.command === 'help') {
      printHelp();
      return;
    }
    let result;
    if (options.command === 'prepare') {
      result = prepareBehaviorRun({
        skillName: options.skillName,
        caseIds: options.caseIds,
        outputDir: options.outputDir,
      });
    } else if (options.command === 'build-judge') {
      result = buildJudgePacket({ runDir: options.runDir });
    } else if (options.command === 'grade') {
      result = gradeBehaviorRun({ runDir: options.runDir, judgmentsPath: options.judgmentsPath });
    } else if (options.command === 'finalize-grade') {
      result = finalizeGradeRun({ runDir: options.runDir, judgmentsPath: options.judgmentsPath });
    } else if (options.command === 'verify-suite') {
      result = verifyBehaviorSuite({ ledgerPath: options.ledgerPath });
    } else if (options.command === 'archive-suite') {
      result = archiveEvidenceSuite({
        ledgerPath: options.ledgerPath,
        archiveDir: options.archiveDir,
      });
    } else if (options.command === 'finalize-archive') {
      result = finalizeEvidenceArchive({
        ledgerPath: options.ledgerPath,
        archiveDir: options.archiveDir,
        expectedManifestSha256: options.expectedManifestSha256,
        expectedSourceLedgerSha256: options.expectedSourceLedgerSha256,
      });
    } else if (options.command === 'finalize-verifier-receipt') {
      result = finalizeArchiveVerifierReceipt({
        ledgerPath: options.ledgerPath,
        archiveDir: options.archiveDir,
        expectedArchiveSealSha256: options.expectedArchiveSealSha256,
        expectedRunnerSha256: options.expectedRunnerSha256,
        expectedBehaviorTestSha256: options.expectedBehaviorTestSha256,
        expectedContractTestSha256: options.expectedContractTestSha256,
        expectedRuntimeSha256: options.expectedRuntimeSha256,
      });
    } else if (options.command === 'verify-archive') {
      result = verifyEvidenceArchive({
        ledgerPath: options.ledgerPath,
        archiveDir: options.archiveDir,
      });
    } else {
      result = verifyBehaviorRun({ runDir: options.runDir, judgmentsPath: options.judgmentsPath });
    }
    process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
    if (['FAIL'].includes(result.status)) process.exitCode = 1;
    if (['BLOCKED', 'NOT_RUN'].includes(result.status)) process.exitCode = 2;
  } catch (error) {
    process.stderr.write(`${JSON.stringify({ status: 'ERROR', error: error.message })}\n`);
    process.exitCode = 1;
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  main();
}
