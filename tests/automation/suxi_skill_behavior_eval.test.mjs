import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import {
  cpSync,
  existsSync,
  lstatSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  realpathSync,
  readdirSync,
  renameSync,
  rmSync,
  symlinkSync,
  unlinkSync,
  writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import test from 'node:test';

import {
  behaviorContractVersion,
  behaviorDimensions,
  behaviorEvalTempRoot,
  archiveEvidenceSuite,
  buildArchiveVerifierProfile,
  buildJudgePacket,
  defaultEvidenceLedgerPath,
  evidenceArchiveVersion,
  evidenceArchiveSealVersion,
  evidenceLedgerVersion,
  finalizeArchiveVerifierReceipt,
  finalizeEvidenceArchive,
  finalizeGradeRun,
  gradeBehaviorRun,
  judgmentVersion,
  judgmentOutputSchema,
  legacyJudgmentVersion,
  legacyRunManifestVersion,
  loadBehaviorContract,
  parseArgs,
  prepareBehaviorRun,
  repoRoot,
  respondentVersion,
  runManifestVersion,
  validateBehaviorContract,
  validateEvidenceArchiveManifest,
  validateEvidenceArchiveSeal,
  validateArchiveVerifierReceipt,
  validateEvidenceLedger,
  validateJudgments,
  verifyBehaviorRun,
  verifyEvidenceArchive,
  verifyBehaviorSuite,
  verifyBehaviorSuiteSnapshot,
  verifierReceiptVersion,
} from '../../scripts/suxi_skill_behavior_eval.mjs';

const skillName = 'suxi-product-decision';
const testGuardSkillName = 'suxi-test-guard';
const userResearchSkillName = 'suxi-user-research';
const runnerPath = path.join(repoRoot, 'scripts', 'suxi_skill_behavior_eval.mjs');
const selectedCaseIds = ['PD-BEH-001', 'PD-BEH-002'];
const projectContractPath = path.join(
  repoRoot,
  '.agents',
  'skills',
  skillName,
  'evals',
  'behavior-evals.json',
);
const pluginContractPath = path.join(
  repoRoot,
  'plugins',
  'suxi-os-toolkit',
  'skills',
  skillName,
  'evals',
  'behavior-evals.json',
);

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function writeJson(filePath, value) {
  writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function fileSha256(filePath) {
  return createHash('sha256').update(readFileSync(filePath)).digest('hex');
}

function selectedCases() {
  const contract = loadBehaviorContract(repoRoot, skillName).document;
  const byId = new Map(contract.cases.map(row => [row.id, row]));
  return selectedCaseIds.map(id => byId.get(id));
}

function validResponse(row) {
  const responseText = row.assertions.map((assertion, index) => (
    `Evidence ${index + 1}: ${assertion}`
  )).join('\n');
  return {
    schema_version: respondentVersion,
    skill_name: skillName,
    case_id: row.id,
    response_text: responseText,
    self_report: {
      decision_status: row.expected.decision_status[0],
      execution_mode: row.expected.execution_mode[0],
      selected_direction: row.expected.selected_direction[0],
      evidence_scope: row.expected.evidence_scope[0],
      invented_numeric_thresholds: row.expected.invented_numeric_thresholds,
      product_or_external_mutation: row.expected.product_or_external_mutation,
      single_next_action: row.expected.single_next_action,
      notes: 'Synthetic test fixture only.',
    },
  };
}

function evidenceSpan(responseText, quote) {
  const start = responseText.indexOf(quote);
  assert.ok(start >= 0, `fixture response is missing quote: ${quote}`);
  return { start, end: start + quote.length, quote };
}

function validJudgments(cases, responses, judgePacketSha256) {
  const responseById = new Map(responses.map(row => [row.case_id, row]));
  return {
    schema_version: judgmentVersion,
    skill_name: skillName,
    judge: 'synthetic-test-judge',
    judge_packet_sha256: judgePacketSha256,
    case_results: cases.map(row => ({
      case_id: row.id,
      verdict: 'PASS',
      normalized: {
        decision_status: row.expected.decision_status[0],
        execution_mode: row.expected.execution_mode[0],
        selected_direction: row.expected.selected_direction[0],
        evidence_scope: row.expected.evidence_scope[0],
        invented_numeric_thresholds: row.expected.invented_numeric_thresholds,
        product_or_external_mutation: row.expected.product_or_external_mutation,
        single_next_action: row.expected.single_next_action,
      },
      assertion_results: row.assertions.map(assertion => ({
        assertion,
        passed: true,
        evidence_spans: [evidenceSpan(responseById.get(row.id).response_text, assertion)],
        explanation: 'The exact assertion text is present in this synthetic fixture.',
      })),
      blocked_reason: '',
      notes: '',
    })),
  };
}

function makeTempRoot() {
  mkdirSync(behaviorEvalTempRoot, { recursive: true });
  return mkdtempSync(path.join(behaviorEvalTempRoot, 'test-'));
}

function prepareTempRun(tempRoot, name) {
  return prepareBehaviorRun({
    root: repoRoot,
    skillName,
    caseIds: selectedCaseIds,
    outputDir: path.join(tempRoot, name),
    now: new Date('2026-08-29T12:00:00.000Z'),
  });
}

function writeResponses(runDir, cases) {
  const responses = [];
  for (const row of cases) {
    const response = validResponse(row);
    writeJson(path.join(runDir, 'cases', row.id, 'response.json'), response);
    responses.push(response);
  }
  return responses;
}

function treeSnapshot(root) {
  const entries = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      const relativePath = path.relative(root, entryPath).replaceAll('\\', '/');
      if (entry.isDirectory()) {
        entries.push({ path: `${relativePath}/`, type: 'directory' });
        visit(entryPath);
      } else {
        entries.push({
          path: relativePath,
          type: 'file',
          bytes_base64: readFileSync(entryPath).toString('base64'),
        });
      }
    }
  };
  visit(root);
  return entries.sort((left, right) => left.path.localeCompare(right.path));
}

function fileTreeStatsForTest(root) {
  const records = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        visit(entryPath);
      } else {
        assert.ok(entry.isFile());
        const bytes = readFileSync(entryPath);
        records.push({
          path: path.relative(root, entryPath).replaceAll('\\', '/'),
          sha256: createHash('sha256').update(bytes).digest('hex'),
          bytes: bytes.length,
        });
      }
    }
  };
  visit(root);
  records.sort((left, right) => left.path.localeCompare(right.path));
  return {
    files: records.length,
    bytes: records.reduce((total, entry) => total + entry.bytes, 0),
    sha256: createHash('sha256')
      .update(records.map(entry => `${entry.path}:${entry.sha256}:${entry.bytes}`).join('\n'))
      .digest('hex'),
  };
}

function runBehaviorCli(args, env = process.env) {
  return spawnSync(process.execPath, [runnerPath, ...args], {
    cwd: repoRoot,
    encoding: 'utf8',
    env,
  });
}

function runBehaviorCliAsync(args, env = process.env) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [runnerPath, ...args], {
      cwd: repoRoot,
      env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    let stdout = '';
    let stderr = '';
    child.stdout.setEncoding('utf8');
    child.stderr.setEncoding('utf8');
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.on('error', reject);
    child.on('close', status => resolve({ status, stdout, stderr }));
  });
}

function ledgerEntryFromVerification(verified) {
  return {
    skill_name: verified.skill_name,
    run_id: verified.run_id,
    run_path: path.relative(
      realpathSync.native(behaviorEvalTempRoot),
      verified.runDir,
    ).replaceAll('\\', '/'),
    run_manifest_schema_version: runManifestVersion,
    judgment_schema_version: verified.judgment_schema_version,
    grade_status: verified.grade_status,
    counts: verified.counts,
    verified: {
      cases: verified.verified.case_workspaces,
      assertions: verified.verified.assertions,
      evidence_spans: verified.verified.evidence_spans,
    },
    hashes: {
      behavior_contract_sha256: verified.hashes.behavior_contract_sha256,
      source_snapshot_sha256: verified.hashes.source_snapshot_sha256,
      prepare_seal_sha256: verified.hashes.prepare_seal_sha256,
      prepare_manifest_sha256: verified.hashes.prepare_manifest_sha256,
      judge_packet_sha256: verified.hashes.judge_packet_sha256,
      judgments_sha256: verified.hashes.judgments_sha256,
      grade_summary_sha256: verified.hashes.grade_summary_sha256,
      grade_seal_sha256: verified.hashes.grade_seal_sha256,
      responses: verified.hashes.responses,
    },
  };
}

function genericResponse(row, targetSkillName) {
  const dimensions = behaviorDimensions(targetSkillName);
  const selfReport = Object.fromEntries(Object.keys(dimensions).map((field) => [
    field,
    Array.isArray(row.expected[field]) ? row.expected[field][0] : row.expected[field],
  ]));
  return {
    schema_version: respondentVersion,
    skill_name: targetSkillName,
    case_id: row.id,
    response_text: row.assertions.map((assertion, index) => `Evidence ${index + 1}: ${assertion}`).join('\n'),
    self_report: {
      ...selfReport,
      notes: 'Synthetic generic fixture.',
    },
  };
}

function genericJudgments(row, response, targetSkillName, judgePacketSha256) {
  const dimensions = behaviorDimensions(targetSkillName);
  const normalized = Object.fromEntries(Object.keys(dimensions).map((field) => [
    field,
    Array.isArray(row.expected[field]) ? row.expected[field][0] : row.expected[field],
  ]));
  return {
    schema_version: judgmentVersion,
    skill_name: targetSkillName,
    judge: 'synthetic-generic-judge',
    judge_packet_sha256: judgePacketSha256,
    case_results: [{
      case_id: row.id,
      verdict: 'PASS',
      normalized,
      assertion_results: row.assertions.map(assertion => ({
        assertion,
        passed: true,
        evidence_spans: [evidenceSpan(response.response_text, assertion)],
        explanation: 'Exact assertion is present in fixture response.',
      })),
      blocked_reason: '',
      notes: '',
    }],
  };
}

test('CLI help flags resolve to the help command', () => {
  assert.equal(parseArgs([]).command, 'help');
  assert.equal(parseArgs(['--help']).command, 'help');
  assert.equal(parseArgs(['-h']).command, 'help');
  assert.equal(parseArgs(['verify', '--run-dir=example']).command, 'verify');
  assert.equal(parseArgs(['finalize-grade', '--run-dir=example']).command, 'finalize-grade');
  assert.equal(parseArgs(['verify-suite', '--ledger=evals/example.json']).command, 'verify-suite');
  assert.equal(parseArgs(['archive-suite', '--archive-dir=evals/archive']).command, 'archive-suite');
  const finalizeArchiveArgs = parseArgs([
    'finalize-archive',
    '--archive-dir=evals/archive',
    `--expected-manifest-sha256=${'a'.repeat(64)}`,
    `--expected-source-ledger-sha256=${'b'.repeat(64)}`,
  ]);
  assert.equal(finalizeArchiveArgs.command, 'finalize-archive');
  assert.equal(finalizeArchiveArgs.expectedManifestSha256, 'a'.repeat(64));
  assert.equal(finalizeArchiveArgs.expectedSourceLedgerSha256, 'b'.repeat(64));
  const verifierReceiptArgs = parseArgs([
    'finalize-verifier-receipt',
    '--archive-dir=evals/archive',
    `--expected-archive-seal-sha256=${'a'.repeat(64)}`,
    `--expected-runner-sha256=${'b'.repeat(64)}`,
    `--expected-behavior-test-sha256=${'c'.repeat(64)}`,
    `--expected-contract-test-sha256=${'d'.repeat(64)}`,
    `--expected-runtime-sha256=${'e'.repeat(64)}`,
  ]);
  assert.equal(verifierReceiptArgs.command, 'finalize-verifier-receipt');
  assert.equal(verifierReceiptArgs.expectedArchiveSealSha256, 'a'.repeat(64));
  assert.equal(verifierReceiptArgs.expectedRunnerSha256, 'b'.repeat(64));
  assert.equal(verifierReceiptArgs.expectedBehaviorTestSha256, 'c'.repeat(64));
  assert.equal(verifierReceiptArgs.expectedContractTestSha256, 'd'.repeat(64));
  assert.equal(verifierReceiptArgs.expectedRuntimeSha256, 'e'.repeat(64));
  assert.equal(parseArgs(['verify-archive', '--archive-dir=evals/archive']).command, 'verify-archive');
});

test('product-decision behavior contract is strict and distribution-identical', () => {
  const projectBytes = readFileSync(projectContractPath);
  const pluginBytes = readFileSync(pluginContractPath);
  assert.ok(projectBytes.equals(pluginBytes));

  const document = validateBehaviorContract(JSON.parse(projectBytes.toString('utf8')), skillName);
  assert.equal(document.schema_version, behaviorContractVersion);
  assert.equal(document.cases.length, 5);
  assert.deepEqual(document.cases.map(row => row.id), [
    'PD-BEH-001',
    'PD-BEH-002',
    'PD-BEH-003',
    'PD-BEH-004',
    'PD-BEH-005',
  ]);
  const blockedDelivery = document.cases.find(row => row.id === 'PD-BEH-004');
  assert.deepEqual(blockedDelivery.expected.execution_mode, ['delivery']);
  assert.ok(blockedDelivery.assertions.includes('承认用户已经请求delivery'));
  assert.ok(blockedDelivery.assertions.includes('阻塞理由是需求与复现证据不足而不是没有实现授权'));
});

test('default governed-skill evidence ledger is strict and complete', () => {
  const ledger = validateEvidenceLedger(readJson(defaultEvidenceLedgerPath));
  assert.equal(ledger.scope, 'current_local_governed_skills');
  assert.deepEqual(
    ledger.entries.map(row => row.skill_name).sort(),
    [skillName, testGuardSkillName, userResearchSkillName].sort(),
  );
  assert.ok(ledger.entries.every(row => !path.isAbsolute(row.run_path) && !row.run_path.includes('..')));
  assert.ok(ledger.entries.every(row => row.run_manifest_schema_version === runManifestVersion));
  assert.ok(ledger.entries.every(row => row.judgment_schema_version === judgmentVersion));
});

test('test-guard behavior contract drives its own dimensions through prepare and grade', () => {
  const projectPath = path.join(
    repoRoot,
    '.agents',
    'skills',
    testGuardSkillName,
    'evals',
    'behavior-evals.json',
  );
  const pluginPath = path.join(
    repoRoot,
    'plugins',
    'suxi-os-toolkit',
    'skills',
    testGuardSkillName,
    'evals',
    'behavior-evals.json',
  );
  assert.ok(readFileSync(projectPath).equals(readFileSync(pluginPath)));
  const contract = loadBehaviorContract(repoRoot, testGuardSkillName).document;
  assert.equal(contract.cases.length, 6);
  assert.deepEqual(contract.cases.map(row => row.id), [
    'TG-BEH-001',
    'TG-BEH-002',
    'TG-BEH-003',
    'TG-BEH-004',
    'TG-BEH-005',
    'TG-BEH-006',
  ]);

  const tempRoot = makeTempRoot();
  try {
    const row = contract.cases.find(item => item.id === 'TG-BEH-002');
    const prepared = prepareBehaviorRun({
      root: repoRoot,
      skillName: testGuardSkillName,
      caseIds: [row.id],
      outputDir: path.join(tempRoot, 'test-guard-generic-run'),
      now: new Date('2026-08-29T13:00:00.000Z'),
    });
    const schema = readJson(path.join(
      prepared.runDir,
      'cases',
      row.id,
      'workspace',
      'respondent-output.schema.json',
    ));
    assert.deepEqual(
      Object.keys(schema.properties.self_report.properties).sort(),
      [...Object.keys(behaviorDimensions(testGuardSkillName)), 'notes'].sort(),
    );
    assert.equal('decision_status' in schema.properties.self_report.properties, false);

    const response = genericResponse(row, testGuardSkillName);
    writeJson(path.join(prepared.runDir, 'cases', row.id, 'response.json'), response);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      genericJudgments(row, response, testGuardSkillName, judgeReady.judge_packet_sha256),
    );
    const summary = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(summary.status, 'PASS');
    assert.deepEqual(summary.counts, { pass: 1, fail: 0, blocked: 0 });
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('user-research behavior contract drives consent and outreach dimensions', () => {
  const projectPath = path.join(repoRoot, '.agents', 'skills', userResearchSkillName, 'evals', 'behavior-evals.json');
  const pluginPath = path.join(repoRoot, 'plugins', 'suxi-os-toolkit', 'skills', userResearchSkillName, 'evals', 'behavior-evals.json');
  assert.ok(readFileSync(projectPath).equals(readFileSync(pluginPath)));
  const contract = loadBehaviorContract(repoRoot, userResearchSkillName).document;
  assert.equal(contract.cases.length, 7);
  for (const caseId of ['UR-BEH-002', 'UR-BEH-003']) {
    assert.deepEqual(
      contract.cases.find(item => item.id === caseId).expected.research_status,
      ['observed_partial'],
    );
  }
  const row = contract.cases.find(item => item.id === 'UR-BEH-004');
  const tempRoot = makeTempRoot();
  try {
    const prepared = prepareBehaviorRun({
      root: repoRoot,
      skillName: userResearchSkillName,
      caseIds: [row.id],
      outputDir: path.join(tempRoot, 'user-research-generic-run'),
      now: new Date('2026-08-29T14:00:00.000Z'),
    });
    const schema = readJson(path.join(prepared.runDir, 'cases', row.id, 'workspace', 'respondent-output.schema.json'));
    assert.deepEqual(
      Object.keys(schema.properties.self_report.properties).sort(),
      [...Object.keys(behaviorDimensions(userResearchSkillName)), 'notes'].sort(),
    );
    assert.equal('overall_status' in schema.properties.self_report.properties, false);
    assert.match(
      schema.properties.self_report.properties.outreach_status.description,
      /not_applicable when the current request only synthesizes supplied records or compares existing rounds/,
    );
    assert.match(
      schema.properties.self_report.properties.consent_status.description,
      /not_required when the current request performs no outreach or recording/,
    );
    const response = genericResponse(row, userResearchSkillName);
    writeJson(path.join(prepared.runDir, 'cases', row.id, 'response.json'), response);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      genericJudgments(row, response, userResearchSkillName, judgeReady.judge_packet_sha256),
    );
    const summary = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(summary.status, 'PASS');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('judgment schema and runtime validator agree on judgeable and BLOCKED shapes', () => {
  const cases = selectedCases();
  const responses = cases.map(validResponse);
  const packetHash = 'a'.repeat(64);
  const schema = judgmentOutputSchema(skillName, selectedCaseIds, packetHash);
  const branches = schema.properties.case_results.items.oneOf;
  const evidenceSchema = schema.properties.case_results.items.properties
    .assertion_results.items.properties.evidence_spans;
  assert.equal(branches[0].properties.verdict.const, 'BLOCKED');
  assert.deepEqual(branches[1].properties.verdict.enum, ['PASS', 'FAIL']);
  assert.equal(branches[0].properties.normalized.type, 'null');
  assert.equal(branches[0].properties.assertion_results.maxItems, 0);
  assert.equal(branches[1].properties.assertion_results.minItems, 1);
  assert.equal(evidenceSchema.minItems, 1);

  const passDocument = validJudgments(cases, responses, packetHash);
  assert.equal(
    validateJudgments(passDocument, skillName, selectedCaseIds, packetHash),
    passDocument,
  );

  const emptyEvidenceDocument = structuredClone(passDocument);
  emptyEvidenceDocument.case_results[0].assertion_results[0].evidence_spans = [];
  assert.throws(
    () => validateJudgments(emptyEvidenceDocument, skillName, selectedCaseIds, packetHash),
    /evidence_spans must not be empty/u,
  );

  const legacySchema = judgmentOutputSchema(
    skillName,
    selectedCaseIds,
    packetHash,
    null,
    legacyJudgmentVersion,
  );
  assert.equal(
    legacySchema.properties.case_results.items.properties
      .assertion_results.items.properties.evidence_spans.minItems,
    0,
  );
  const legacyDocument = structuredClone(passDocument);
  legacyDocument.schema_version = legacyJudgmentVersion;
  legacyDocument.case_results[0].verdict = 'FAIL';
  legacyDocument.case_results[0].assertion_results[0].passed = false;
  legacyDocument.case_results[0].assertion_results[0].evidence_spans = [];
  assert.equal(
    validateJudgments(
      legacyDocument,
      skillName,
      selectedCaseIds,
      packetHash,
      null,
      legacyJudgmentVersion,
    ),
    legacyDocument,
  );

  const blockedDocument = structuredClone(passDocument);
  blockedDocument.case_results[0] = {
    case_id: selectedCaseIds[0],
    verdict: 'BLOCKED',
    normalized: null,
    assertion_results: [],
    blocked_reason: 'The response text is unavailable.',
    notes: '',
  };
  assert.equal(
    validateJudgments(blockedDocument, skillName, selectedCaseIds, packetHash),
    blockedDocument,
  );

  const invalidBlocked = structuredClone(passDocument);
  invalidBlocked.case_results[0].verdict = 'BLOCKED';
  invalidBlocked.case_results[0].blocked_reason = 'Cannot judge.';
  assert.throws(
    () => validateJudgments(invalidBlocked, skillName, selectedCaseIds, packetHash),
    /normalized must be null when BLOCKED/u,
  );

  const invalidPass = structuredClone(passDocument);
  invalidPass.case_results[0].normalized = null;
  invalidPass.case_results[0].assertion_results = [];
  assert.throws(
    () => validateJudgments(invalidPass, skillName, selectedCaseIds, packetHash),
    /normalized must be an object/u,
  );
});

test('prepare creates expectation-blind instruction-separated workspaces and performs no model execution', () => {
  const tempRoot = makeTempRoot();
  try {
    const prepared = prepareTempRun(tempRoot, 'blind-run');
    assert.equal(prepared.status, 'PREPARED');
    assert.equal(prepared.manifest.model_execution, 'NOT_RUN');
    assert.equal(prepared.manifest.schema_version, runManifestVersion);
    assert.equal(prepared.manifest.judgment_schema_version, judgmentVersion);
    assert.equal(prepared.manifest.external_or_product_mutation, 'N/A');
    assert.equal(
      path.relative(realpathSync.native(behaviorEvalTempRoot), prepared.runDir).startsWith('..'),
      false,
    );
    assert.ok(prepared.manifest.cases.every(row => row.filesystem_isolation === 'instruction_only'));
    assert.ok(prepared.manifest.cases.every(row => row.expectations_in_workspace === false));
    assert.equal(existsSync(prepared.sealPath), true);

    for (const caseId of selectedCaseIds) {
      const workspace = path.join(prepared.runDir, 'cases', caseId, 'workspace');
      const snapshot = path.join(workspace, '.agents', 'skills', skillName);
      assert.equal(existsSync(path.join(snapshot, 'SKILL.md')), true);
      assert.equal(existsSync(path.join(snapshot, 'references', 'decision-evidence-contract.md')), true);
      assert.equal(existsSync(path.join(snapshot, 'references', 'source-provenance.md')), false);
      assert.equal(existsSync(path.join(snapshot, 'evals')), false);

      const request = readFileSync(path.join(workspace, 'respondent-request.json'), 'utf8');
      const prompt = readFileSync(path.join(workspace, 'respondent-prompt.md'), 'utf8');
      assert.doesNotMatch(request, /"expected"|"assertions"/u);
      assert.doesNotMatch(prompt, /expected answer|assertions/u);
      assert.match(prompt, new RegExp(`Use \\$${skillName}`, 'u'));
    }

    assert.deepEqual(
      buildJudgePacket({ root: repoRoot, runDir: prepared.runDir }),
      { status: 'NOT_RUN', runDir: prepared.runDir, missing_case_ids: selectedCaseIds },
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('judge packet reveals expectations only after responses exist and a valid judgment passes', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'passing-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(judgeReady.status, 'READY_FOR_JUDGMENT');

    const judgePacket = readJson(path.join(prepared.runDir, 'judge-packet.json'));
    assert.equal(judgePacket.cases.length, selectedCaseIds.length);
    assert.ok(judgePacket.cases.every(row => row.expected && row.assertions));

    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    const summary = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(summary.status, 'PASS');
    assert.deepEqual(summary.counts, { pass: 2, fail: 0, blocked: 0 });
    assert.equal(existsSync(path.join(prepared.runDir, 'grade-summary.json')), true);
    assert.equal(typeof summary.hashes.prepare_seal_sha256, 'string');
    assert.equal(typeof summary.hashes.prepare_manifest_sha256, 'string');
    assert.equal(typeof summary.hashes.source_snapshot_sha256, 'string');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier replays a completed PASS run without changing artifacts', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'verified-pass-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const finalized = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(finalized.status, 'SEALED');
    assert.equal(finalized.created, false);
    const before = treeSnapshot(prepared.runDir);
    const sealBefore = readFileSync(prepared.sealPath);
    const gradeSealBefore = readFileSync(finalized.sealPath);

    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });

    assert.equal(verified.status, 'PASS');
    assert.equal(verified.grade_status, 'PASS');
    assert.equal(verified.post_grade_seal_status, 'SEALED');
    assert.equal(verified.read_only, true);
    assert.deepEqual(verified.counts, { pass: 2, fail: 0, blocked: 0 });
    assert.deepEqual(verified.verified, {
      source_snapshot_files: 3,
      case_workspaces: 2,
      responses: 2,
      judgment_results: 2,
      assertions: 10,
      evidence_spans: 10,
    });
    assert.equal(typeof verified.hashes.grade_summary_sha256, 'string');
    assert.equal(typeof verified.hashes.grade_seal_sha256, 'string');
    assert.deepEqual(treeSnapshot(prepared.runDir), before);
    assert.ok(readFileSync(prepared.sealPath).equals(sealBefore));
    assert.ok(readFileSync(finalized.sealPath).equals(gradeSealBefore));
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('evidence ledger is strict and verify-suite preserves per-skill truth', () => {
  const tempRoot = makeTempRoot();
  const repoLedgerRoot = mkdtempSync(path.join(repoRoot, 'tests', 'suxi-ledger-'));
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'evidence-ledger-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const ledger = {
      schema_version: evidenceLedgerVersion,
      scope: 'test_subset',
      entries: [ledgerEntryFromVerification(verified)],
    };
    assert.equal(validateEvidenceLedger(ledger), ledger);

    const externalLedgerPath = path.join(tempRoot, 'evidence-ledger.json');
    writeJson(externalLedgerPath, ledger);
    const runBefore = treeSnapshot(prepared.runDir);
    const ledgerBefore = readFileSync(externalLedgerPath);
    const suite = verifyBehaviorSuite({
      root: repoRoot,
      ledgerPath: externalLedgerPath,
      allowExternalLedgerPath: true,
    });
    assert.equal(suite.status, 'PASS');
    const expectedCounts = {
      skills: 1,
      cases: 2,
      pass: 2,
      fail: 0,
      blocked: 0,
      assertions: 10,
      evidence_spans: 10,
    };
    assert.deepEqual(suite.claimed_counts, expectedCounts);
    assert.deepEqual(suite.verified_counts, expectedCounts);
    assert.equal(suite.skill_results[0].status, 'PASS');
    assert.equal(suite.read_only, true);
    assert.deepEqual(treeSnapshot(prepared.runDir), runBefore);
    assert.ok(readFileSync(externalLedgerPath).equals(ledgerBefore));

    const invalidFrozenLedger = structuredClone(ledger);
    invalidFrozenLedger.entries[0].verified.assertions += 1;
    const invalidFrozenBytes = Buffer.from(`${JSON.stringify(invalidFrozenLedger, null, 2)}\n`, 'utf8');
    const frozenReplay = verifyBehaviorSuiteSnapshot({
      root: repoRoot,
      resolvedLedgerPath: externalLedgerPath,
      ledgerBytes: invalidFrozenBytes,
    });
    assert.equal(frozenReplay.status, 'FAIL');
    assert.equal(frozenReplay.verified_counts, null);
    assert.equal(
      frozenReplay.ledger_sha256,
      createHash('sha256').update(invalidFrozenBytes).digest('hex'),
    );
    assert.ok(readFileSync(externalLedgerPath).equals(ledgerBefore));

    const tampered = structuredClone(ledger);
    tampered.entries[0].hashes.grade_seal_sha256 = '0'.repeat(64);
    writeJson(externalLedgerPath, tampered);
    const failed = verifyBehaviorSuite({
      root: repoRoot,
      ledgerPath: externalLedgerPath,
      allowExternalLedgerPath: true,
    });
    assert.equal(failed.status, 'FAIL');
    assert.equal(failed.verified_counts, null);
    assert.deepEqual(failed.claimed_counts, expectedCounts);
    assert.match(failed.skill_results[0].failures.join('\n'), /grade_seal_sha256/u);

    const missing = structuredClone(ledger);
    missing.entries[0].run_path = 'missing-governed-skill-run';
    writeJson(externalLedgerPath, missing);
    const notRun = verifyBehaviorSuite({
      root: repoRoot,
      ledgerPath: externalLedgerPath,
      allowExternalLedgerPath: true,
    });
    assert.equal(notRun.status, 'NOT_RUN');
    assert.equal(notRun.verified_counts, null);
    assert.deepEqual(notRun.claimed_counts, expectedCounts);
    assert.equal(notRun.skill_results[0].status, 'NOT_RUN');

    const duplicate = structuredClone(ledger);
    duplicate.entries.push(structuredClone(duplicate.entries[0]));
    assert.throws(
      () => validateEvidenceLedger(duplicate),
      /entries must have unique skill_name, run_id, and run_path/u,
    );
    assert.throws(
      () => validateEvidenceLedger({ ...ledger, unexpected: true }),
      /evidence ledger keys mismatch/u,
    );
    const backslashPath = structuredClone(ledger);
    backslashPath.entries[0].run_path = 'nested\\run';
    assert.throws(
      () => validateEvidenceLedger(backslashPath),
      /run_path must be a normalized relative path/u,
    );
    const windowsAbsolutePath = structuredClone(ledger);
    windowsAbsolutePath.entries[0].run_path = 'C:/outside-run';
    assert.throws(
      () => validateEvidenceLedger(windowsAbsolutePath),
      /run_path must be a normalized relative path/u,
    );

    const repoLedgerPath = path.join(repoLedgerRoot, 'ledger.json');
    writeJson(repoLedgerPath, ledger);
    const cli = runBehaviorCli(['verify-suite', `--ledger=${repoLedgerPath}`]);
    assert.equal(cli.status, 0, cli.stderr);
    assert.equal(JSON.parse(cli.stdout).status, 'PASS');
  } finally {
    rmSync(repoLedgerRoot, { recursive: true, force: true });
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('archive-suite durably copies evidence and verify-archive detects drift', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'archive-source-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const ledger = {
      schema_version: evidenceLedgerVersion,
      scope: 'test_subset',
      entries: [ledgerEntryFromVerification(verified)],
    };
    const ledgerPath = path.join(tempRoot, 'archive-source-ledger.json');
    writeJson(ledgerPath, ledger);
    const archiveDir = path.join(tempRoot, 'portable-archive');
    const sourceBefore = treeSnapshot(prepared.runDir);
    const ledgerBefore = readFileSync(ledgerPath);

    const archived = archiveEvidenceSuite({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(archived.status, 'ARCHIVED');
    assert.equal(archived.created, true);
    assert.equal(archived.counts.runs, 1);
    assert.equal(archived.archive_seal_status, 'SEALED');
    assert.ok(existsSync(archived.archive_seal_path));
    assert.equal(
      validateEvidenceArchiveSeal(readJson(archived.archive_seal_path)).schema_version,
      evidenceArchiveSealVersion,
    );
    const repeated = archiveEvidenceSuite({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(repeated.status, 'ARCHIVED');
    assert.equal(repeated.created, false);
    const existingArchiveBefore = treeSnapshot(archiveDir);
    const existingSealBefore = readFileSync(archived.archive_seal_path);
    writeFileSync(ledgerPath, JSON.stringify(ledger), 'utf8');
    assert.notEqual(fileSha256(ledgerPath), archived.source_ledger_sha256);
    assert.throws(
      () => archiveEvidenceSuite({
        root: repoRoot,
        ledgerPath,
        archiveDir,
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      }),
      /Existing evidence archive does not match current ledger/u,
    );
    writeFileSync(ledgerPath, ledgerBefore);
    assert.deepEqual(treeSnapshot(archiveDir), existingArchiveBefore);
    assert.ok(readFileSync(archived.archive_seal_path).equals(existingSealBefore));
    assert.deepEqual(treeSnapshot(prepared.runDir), sourceBefore);
    assert.ok(readFileSync(ledgerPath).equals(ledgerBefore));

    const archiveVerified = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(archiveVerified.status, 'PASS');
    assert.equal(archiveVerified.read_only, true);
    assert.equal(archiveVerified.verified_counts.runs, 1);
    assert.equal(archiveVerified.archive_seal_status, 'SEALED');
    assert.equal(archiveVerified.content_status, 'PASS');
    assert.equal(archiveVerified.verifier_identity_status, 'UNBOUND');
    assert.equal(archiveVerified.reproducibility_status, 'NOT_RUN');
    assert.equal(archiveVerified.reproducibility_verified_counts, null);

    const verifierProfile = buildArchiveVerifierProfile({ root: repoRoot });
    const profileFile = role => verifierProfile.document.files.find(file => file.role === role);
    const archivedTreeBeforeReceipt = treeSnapshot(archiveDir);
    const archiveSealBeforeReceipt = readFileSync(archived.archive_seal_path);
    assert.throws(
      () => finalizeArchiveVerifierReceipt({
        root: repoRoot,
        ledgerPath,
        archiveDir,
        expectedArchiveSealSha256: archived.archive_seal_sha256,
        expectedRunnerSha256: '0'.repeat(64),
        expectedBehaviorTestSha256: profileFile('behavior_test').sha256,
        expectedContractTestSha256: profileFile('contract_test').sha256,
        expectedRuntimeSha256: verifierProfile.runtime_sha256,
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      }),
      /runner hash .* does not match the expected hash/u,
    );
    const publicApiFakeRoot = path.join(tempRoot, 'public-api-fake-root');
    for (const file of verifierProfile.document.files) {
      const target = path.join(publicApiFakeRoot, ...file.path.split('/'));
      mkdirSync(path.dirname(target), { recursive: true });
      cpSync(path.join(repoRoot, ...file.path.split('/')), target, {
        force: false,
        errorOnExist: true,
      });
    }
    const fakeRunnerPath = path.join(publicApiFakeRoot, profileFile('runner').path);
    writeFileSync(fakeRunnerPath, `${readFileSync(fakeRunnerPath, 'utf8')}fake root drift\n`, 'utf8');
    const fakeProfile = buildArchiveVerifierProfile({
      root: repoRoot,
      verifierRoot: publicApiFakeRoot,
    });
    const fakeFile = role => fakeProfile.document.files.find(file => file.role === role);
    assert.throws(
      () => finalizeArchiveVerifierReceipt({
        root: publicApiFakeRoot,
        ledgerPath,
        archiveDir,
        expectedArchiveSealSha256: archived.archive_seal_sha256,
        expectedRunnerSha256: fakeFile('runner').sha256,
        expectedBehaviorTestSha256: fakeFile('behavior_test').sha256,
        expectedContractTestSha256: fakeFile('contract_test').sha256,
        expectedRuntimeSha256: fakeProfile.runtime_sha256,
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      }),
      /runner hash .* does not match the expected hash/u,
    );
    const boundReceipt = finalizeArchiveVerifierReceipt({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      expectedArchiveSealSha256: archived.archive_seal_sha256,
      expectedRunnerSha256: profileFile('runner').sha256,
      expectedBehaviorTestSha256: profileFile('behavior_test').sha256,
      expectedContractTestSha256: profileFile('contract_test').sha256,
      expectedRuntimeSha256: verifierProfile.runtime_sha256,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(boundReceipt.status, 'BOUND');
    assert.equal(boundReceipt.created, true);
    assert.equal(
      validateArchiveVerifierReceipt(readJson(boundReceipt.verifier_receipt_path)).schema_version,
      verifierReceiptVersion,
    );
    const boundAgain = finalizeArchiveVerifierReceipt({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      expectedArchiveSealSha256: archived.archive_seal_sha256,
      expectedRunnerSha256: profileFile('runner').sha256,
      expectedBehaviorTestSha256: profileFile('behavior_test').sha256,
      expectedContractTestSha256: profileFile('contract_test').sha256,
      expectedRuntimeSha256: verifierProfile.runtime_sha256,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(boundAgain.created, false);
    assert.deepEqual(treeSnapshot(archiveDir), archivedTreeBeforeReceipt);
    assert.ok(readFileSync(archived.archive_seal_path).equals(archiveSealBeforeReceipt));

    const reproducible = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(reproducible.content_status, 'PASS');
    assert.equal(reproducible.verifier_identity_status, 'MATCH');
    assert.equal(reproducible.reproducibility_status, 'PASS');
    assert.deepEqual(reproducible.reproducibility_verified_counts, reproducible.verified_counts);
    const receiptBytes = readFileSync(boundReceipt.verifier_receipt_path);

    const writeAlternateReceipt = (alternateProfile) => {
      const receipt = validateArchiveVerifierReceipt(JSON.parse(receiptBytes.toString('utf8')));
      receipt.verifier_profile = alternateProfile.document;
      receipt.verifier_profile_sha256 = alternateProfile.sha256;
      writeJson(boundReceipt.verifier_receipt_path, receipt);
    };

    const verifierCopyRoot = path.join(tempRoot, 'verifier-copy');
    for (const file of verifierProfile.document.files) {
      const target = path.join(verifierCopyRoot, ...file.path.split('/'));
      mkdirSync(path.dirname(target), { recursive: true });
      cpSync(path.join(repoRoot, ...file.path.split('/')), target, {
        force: false,
        errorOnExist: true,
      });
    }
    const copiedRunner = path.join(verifierCopyRoot, profileFile('runner').path);
    writeFileSync(copiedRunner, `${readFileSync(copiedRunner, 'utf8')}\n`, 'utf8');
    writeAlternateReceipt(buildArchiveVerifierProfile({
      root: repoRoot,
      verifierRoot: verifierCopyRoot,
    }));
    const runnerDrift = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(runnerDrift.content_status, 'PASS');
    assert.equal(runnerDrift.verifier_identity_status, 'MISMATCH');
    assert.equal(runnerDrift.reproducibility_status, 'FAIL');
    assert.equal(runnerDrift.reproducibility_verified_counts, null);
    writeFileSync(boundReceipt.verifier_receipt_path, receiptBytes);

    writeFileSync(
      copiedRunner,
      readFileSync(path.join(repoRoot, profileFile('runner').path)),
    );
    const copiedBehaviorTest = path.join(verifierCopyRoot, profileFile('behavior_test').path);
    writeFileSync(copiedBehaviorTest, `${readFileSync(copiedBehaviorTest, 'utf8')}\n`, 'utf8');
    writeAlternateReceipt(buildArchiveVerifierProfile({
      root: repoRoot,
      verifierRoot: verifierCopyRoot,
    }));
    const testDrift = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(testDrift.verifier_identity_status, 'MISMATCH');
    assert.equal(testDrift.reproducibility_status, 'FAIL');
    writeFileSync(boundReceipt.verifier_receipt_path, receiptBytes);

    writeAlternateReceipt(buildArchiveVerifierProfile({
      root: repoRoot,
      runtimeProfile: {
        ...verifierProfile.document.runtime,
        node_version: 'v0.0.0-test-drift',
      },
    }));
    const runtimeDrift = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(runtimeDrift.verifier_identity_status, 'MISMATCH');
    assert.equal(runtimeDrift.reproducibility_status, 'FAIL');
    writeFileSync(boundReceipt.verifier_receipt_path, receiptBytes);

    const tamperedReceipt = validateArchiveVerifierReceipt(readJson(boundReceipt.verifier_receipt_path));
    tamperedReceipt.verifier_profile_sha256 = '0'.repeat(64);
    writeJson(boundReceipt.verifier_receipt_path, tamperedReceipt);
    const receiptDrift = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(receiptDrift.content_status, 'PASS');
    assert.equal(receiptDrift.verifier_identity_status, 'FAIL');
    assert.equal(receiptDrift.reproducibility_status, 'FAIL');
    writeFileSync(boundReceipt.verifier_receipt_path, receiptBytes);

    const originalSealBytes = readFileSync(archived.archive_seal_path);
    const tamperedSeal = validateEvidenceArchiveSeal(readJson(archived.archive_seal_path));
    tamperedSeal.archive_manifest_sha256 = '0'.repeat(64);
    writeJson(archived.archive_seal_path, tamperedSeal);
    const sealDrifted = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(sealDrifted.status, 'FAIL');
    assert.equal(sealDrifted.archive_seal_status, 'FAIL');
    assert.equal(sealDrifted.verified_counts, null);
    writeFileSync(archived.archive_seal_path, originalSealBytes);

    unlinkSync(archived.archive_seal_path);
    const unsealed = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(unsealed.status, 'NOT_RUN');
    assert.equal(unsealed.archive_seal_status, 'MISSING');
    assert.equal(unsealed.verified_counts, null);
    assert.throws(
      () => finalizeEvidenceArchive({
        root: repoRoot,
        ledgerPath,
        archiveDir,
        expectedManifestSha256: '0'.repeat(64),
        expectedSourceLedgerSha256: archived.source_ledger_sha256,
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      }),
      /does not match the expected hash/u,
    );
    assert.throws(
      () => finalizeEvidenceArchive({
        root: repoRoot,
        ledgerPath,
        archiveDir,
        expectedManifestSha256: archived.archive_manifest_sha256,
        expectedSourceLedgerSha256: '0'.repeat(64),
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      }),
      /source ledger hash .* does not match the expected hash/u,
    );
    const resealed = finalizeEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      expectedManifestSha256: archived.archive_manifest_sha256,
      expectedSourceLedgerSha256: archived.source_ledger_sha256,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(resealed.status, 'SEALED');
    assert.equal(resealed.created, true);
    const resealedAgain = finalizeEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      expectedManifestSha256: archived.archive_manifest_sha256,
      expectedSourceLedgerSha256: archived.source_ledger_sha256,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(resealedAgain.created, false);

    const unexpectedArchiveFile = path.join(archiveDir, 'unexpected.txt');
    writeFileSync(unexpectedArchiveFile, 'unexpected archive drift\n', 'utf8');
    const unexpected = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(unexpected.status, 'FAIL');
    assert.match(unexpected.archive_failures.join('\n'), /unexpected files/u);
    unlinkSync(unexpectedArchiveFile);

    const manifestPath = path.join(archiveDir, 'archive-manifest.json');
    const manifest = validateEvidenceArchiveManifest(readJson(manifestPath));
    assert.equal(manifest.schema_version, evidenceArchiveVersion);
    const pathEscape = structuredClone(manifest);
    pathEscape.entries[0].run_path = '../outside';
    assert.throws(
      () => validateEvidenceArchiveManifest(pathEscape),
      /run_path must be a normalized relative path/u,
    );
    const nestedRunPath = structuredClone(manifest);
    nestedRunPath.entries[0].run_path = `${manifest.entries[0].run_path}/nested`;
    assert.throws(
      () => validateEvidenceArchiveManifest(nestedRunPath),
      /run_path must equal runs\/<run_id>/u,
    );
    const wrongArchiveRunId = structuredClone(manifest);
    wrongArchiveRunId.entries[0].run_id = 'synthetic-wrong-run-id';
    assert.throws(
      () => validateEvidenceArchiveManifest(wrongArchiveRunId),
      /run_path must equal runs\/<run_id>/u,
    );

    const archivedRunDir = path.join(archiveDir, manifest.entries[0].run_path);
    const archivedRunBackup = path.join(tempRoot, 'archived-run-backup');
    const originalManifestBytes = readFileSync(manifestPath);
    cpSync(archivedRunDir, archivedRunBackup, {
      recursive: true,
      force: false,
      errorOnExist: true,
    });
    const restoreArchivedRun = () => {
      rmSync(archivedRunDir, { recursive: true, force: true });
      cpSync(archivedRunBackup, archivedRunDir, {
        recursive: true,
        force: false,
        errorOnExist: true,
      });
      writeFileSync(manifestPath, originalManifestBytes);
    };
    const verifyCoordinatedDrift = () => {
      const changedManifest = validateEvidenceArchiveManifest(readJson(manifestPath));
      const stats = fileTreeStatsForTest(archivedRunDir);
      changedManifest.entries[0].run_file_count = stats.files;
      changedManifest.entries[0].run_bytes = stats.bytes;
      changedManifest.entries[0].run_tree_sha256 = stats.sha256;
      writeJson(manifestPath, changedManifest);
      const coordinated = verifyEvidenceArchive({
        root: repoRoot,
        ledgerPath,
        archiveDir,
        allowExternalLedgerPath: true,
        allowExternalArchivePath: true,
      });
      assert.equal(coordinated.status, 'FAIL');
      assert.equal(coordinated.verified_counts, null);
      assert.match(coordinated.archive_failures.join('\n'), /external archive seal/u);
      assert.ok(
        coordinated.run_results[0].failures.length > 0,
        'full archived prepare/post-grade replay must independently reject coordinated drift',
      );
      const sealBytes = readFileSync(archived.archive_seal_path);
      unlinkSync(archived.archive_seal_path);
      assert.throws(
        () => finalizeEvidenceArchive({
          root: repoRoot,
          ledgerPath,
          archiveDir,
          expectedManifestSha256: fileSha256(manifestPath),
          expectedSourceLedgerSha256: archived.source_ledger_sha256,
          allowExternalLedgerPath: true,
          allowExternalArchivePath: true,
        }),
        /Cannot seal evidence archive with content status FAIL/u,
      );
      assert.equal(existsSync(archived.archive_seal_path), false);
      writeFileSync(archived.archive_seal_path, sealBytes);
    };

    writeFileSync(path.join(archivedRunDir, 'unlisted-extra.bin'), 'add\n', 'utf8');
    verifyCoordinatedDrift();
    restoreArchivedRun();

    unlinkSync(path.join(archivedRunDir, 'judge-prompt.md'));
    verifyCoordinatedDrift();
    restoreArchivedRun();

    const archivedJudgePrompt = path.join(archivedRunDir, 'judge-prompt.md');
    writeFileSync(archivedJudgePrompt, `${readFileSync(archivedJudgePrompt, 'utf8')}tamper\n`, 'utf8');
    verifyCoordinatedDrift();
    restoreArchivedRun();
    assert.equal(verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    }).status, 'PASS');

    const archivedSourceLedgerPath = path.join(archiveDir, 'source-ledger.json');
    const verifyLedgerReplayCountDrift = (mutateLedger) => {
      const changedLedger = JSON.parse(ledgerBefore.toString('utf8'));
      mutateLedger(changedLedger.entries[0]);
      validateEvidenceLedger(changedLedger);
      writeJson(ledgerPath, changedLedger);
      writeJson(archivedSourceLedgerPath, changedLedger);
      const changedSourceLedgerSha256 = fileSha256(ledgerPath);
      const changedManifest = JSON.parse(originalManifestBytes.toString('utf8'));
      changedManifest.source_ledger_sha256 = changedSourceLedgerSha256;
      writeJson(manifestPath, changedManifest);
      const sealBytes = readFileSync(archived.archive_seal_path);
      unlinkSync(archived.archive_seal_path);
      try {
        assert.throws(
          () => finalizeEvidenceArchive({
            root: repoRoot,
            ledgerPath,
            archiveDir,
            expectedManifestSha256: fileSha256(manifestPath),
            expectedSourceLedgerSha256: changedSourceLedgerSha256,
            allowExternalLedgerPath: true,
            allowExternalArchivePath: true,
          }),
          /Cannot seal evidence archive with content status FAIL/u,
        );
        const rejected = verifyEvidenceArchive({
          root: repoRoot,
          ledgerPath,
          archiveDir,
          allowExternalLedgerPath: true,
          allowExternalArchivePath: true,
        });
        assert.equal(rejected.status, 'FAIL');
        assert.equal(rejected.verified_counts, null);
        assert.ok(rejected.run_results[0].failures.length > 0);
        assert.equal(existsSync(archived.archive_seal_path), false);
      } finally {
        writeFileSync(ledgerPath, ledgerBefore);
        writeFileSync(archivedSourceLedgerPath, ledgerBefore);
        writeFileSync(manifestPath, originalManifestBytes);
        writeFileSync(archived.archive_seal_path, sealBytes);
      }
    };

    verifyLedgerReplayCountDrift((entry) => {
      entry.verified.assertions += 1000;
    });
    verifyLedgerReplayCountDrift((entry) => {
      entry.verified.evidence_spans += 1000;
    });
    verifyLedgerReplayCountDrift((entry) => {
      entry.verified.cases += 1;
      entry.counts.pass += 1;
      entry.hashes.responses.push({
        case_id: 'synthetic-extra-response',
        sha256: 'f'.repeat(64),
      });
    });
    assert.equal(verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    }).status, 'PASS');

    const archivedResponse = path.join(
      archiveDir,
      manifest.entries[0].run_path,
      'cases',
      selectedCaseIds[0],
      'response.json',
    );
    writeFileSync(archivedResponse, `${readFileSync(archivedResponse, 'utf8')}drift\n`, 'utf8');
    const drifted = verifyEvidenceArchive({
      root: repoRoot,
      ledgerPath,
      archiveDir,
      allowExternalLedgerPath: true,
      allowExternalArchivePath: true,
    });
    assert.equal(drifted.status, 'FAIL');
    assert.match(drifted.run_results[0].failures.join('\n'), /run tree|response/u);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('post-grade seal finalization is idempotent, recoverable, and tamper-evident', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  let gradeSealPath = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'post-grade-seal-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const existing = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    gradeSealPath = existing.sealPath;
    assert.equal(existing.created, false);

    unlinkSync(gradeSealPath);
    assert.deepEqual(
      verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      { status: 'NOT_RUN', runDir: prepared.runDir, missing_grade_seal: true },
    );
    const recoveredCli = runBehaviorCli(['finalize-grade', `--run-dir=${prepared.runDir}`]);
    assert.equal(recoveredCli.status, 0, recoveredCli.stderr);
    const recovered = JSON.parse(recoveredCli.stdout);
    assert.equal(recovered.status, 'SEALED');
    assert.equal(recovered.created, true);
    const repeated = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(repeated.created, false);
    assert.equal(repeated.grade_seal_sha256, recovered.grade_seal_sha256);
    assert.equal(verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }).status, 'PASS');

    const changedSeal = readJson(gradeSealPath);
    changedSeal.grade_status = 'FAIL';
    writeJson(gradeSealPath, changedSeal);
    assert.throws(
      () => verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Post-grade seal differs from recomputed result/u,
    );
  } finally {
    if (gradeSealPath && existsSync(gradeSealPath)) unlinkSync(gradeSealPath);
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('legacy manifest v1 remains verifiable when its post-grade seal is absent', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  let gradeSealPath = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'legacy-missing-post-grade-seal-run');
    const manifestPath = path.join(prepared.runDir, 'manifest.json');
    const manifest = readJson(manifestPath);
    manifest.schema_version = legacyRunManifestVersion;
    delete manifest.judgment_schema_version;
    writeJson(manifestPath, manifest);
    const prepareSeal = readJson(prepared.sealPath);
    prepareSeal.manifest_sha256 = fileSha256(manifestPath);
    writeJson(prepared.sealPath, prepareSeal);

    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    judgments.schema_version = legacyJudgmentVersion;
    writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const finalized = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    gradeSealPath = finalized.sealPath;
    unlinkSync(gradeSealPath);

    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(verified.status, 'PASS');
    assert.equal(verified.grade_status, 'PASS');
    assert.equal(verified.judgment_schema_version, legacyJudgmentVersion);
    assert.equal(verified.post_grade_seal_status, 'LEGACY_MISSING');
    assert.equal('grade_seal_sha256' in verified.hashes, false);
  } finally {
    if (gradeSealPath && existsSync(gradeSealPath)) unlinkSync(gradeSealPath);
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('external post-grade seal rejects a coordinated in-run artifact rewrite', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  let gradeSealPath = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'coordinated-post-grade-tamper-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const finalized = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    gradeSealPath = finalized.sealPath;
    const originalGradeSeal = readFileSync(gradeSealPath);

    const responsePath = path.join(prepared.runDir, 'cases', selectedCaseIds[0], 'response.json');
    const changedResponse = readJson(responsePath);
    changedResponse.response_text += '\nCoordinated rewrite with the original evidence preserved.';
    writeJson(responsePath, changedResponse);

    const packetPath = path.join(prepared.runDir, 'judge-packet.json');
    const packet = readJson(packetPath);
    const packetCase = packet.cases.find(row => row.case_id === selectedCaseIds[0]);
    packetCase.response = changedResponse;
    packetCase.response_sha256 = fileSha256(responsePath);
    writeJson(packetPath, packet);
    const packetHash = fileSha256(packetPath);
    writeJson(
      path.join(prepared.runDir, 'judgment-output.schema.json'),
      judgmentOutputSchema(skillName, selectedCaseIds, packetHash),
    );
    const judgmentsPath = path.join(prepared.runDir, 'judgments.json');
    const judgments = readJson(judgmentsPath);
    judgments.judge_packet_sha256 = packetHash;
    writeJson(judgmentsPath, judgments);

    unlinkSync(path.join(prepared.runDir, 'grade-summary.json'));
    unlinkSync(gradeSealPath);
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    writeFileSync(gradeSealPath, originalGradeSeal);
    assert.throws(
      () => verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Post-grade seal differs from recomputed result/u,
    );
  } finally {
    if (gradeSealPath && existsSync(gradeSealPath)) unlinkSync(gradeSealPath);
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('concurrent finalizers use exclusive creation and agree on one post-grade seal', async () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  let gradeSealPath = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'concurrent-finalize-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    const existing = finalizeGradeRun({ root: repoRoot, runDir: prepared.runDir });
    gradeSealPath = existing.sealPath;
    unlinkSync(gradeSealPath);

    const args = ['finalize-grade', `--run-dir=${prepared.runDir}`];
    const [left, right] = await Promise.all([
      runBehaviorCliAsync(args),
      runBehaviorCliAsync(args),
    ]);
    assert.equal(left.status, 0, left.stderr);
    assert.equal(right.status, 0, right.stderr);
    const outputs = [JSON.parse(left.stdout), JSON.parse(right.stdout)];
    assert.deepEqual(outputs.map(row => row.created).sort(), [false, true]);
    assert.equal(outputs[0].grade_seal_sha256, outputs[1].grade_seal_sha256);
    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(verified.status, 'PASS');
    assert.equal(verified.post_grade_seal_status, 'SEALED');
  } finally {
    if (gradeSealPath && existsSync(gradeSealPath)) unlinkSync(gradeSealPath);
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('concurrent graders atomically converge on one summary and post-grade seal', async () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'concurrent-grade-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    const args = ['grade', `--run-dir=${prepared.runDir}`];
    const [left, right] = await Promise.all([
      runBehaviorCliAsync(args),
      runBehaviorCliAsync(args),
    ]);
    assert.equal(left.status, 0, left.stderr);
    assert.equal(right.status, 0, right.stderr);
    const outputs = [JSON.parse(left.stdout), JSON.parse(right.stdout)];
    assert.deepEqual(outputs.map(row => row.status), ['PASS', 'PASS']);
    assert.deepEqual(outputs[0], outputs[1]);
    const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(verified.status, 'PASS');
    assert.equal(verified.post_grade_seal_status, 'SEALED');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier supports the same custom judgments path used for grading', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'verify-custom-judgments-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgmentsName = 'independent-judgments.json';
    writeJson(
      path.join(prepared.runDir, judgmentsName),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({
      root: repoRoot,
      runDir: prepared.runDir,
      judgmentsPath: judgmentsName,
    });

    assert.deepEqual(
      verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      { status: 'NOT_RUN', runDir: prepared.runDir, missing_judgments: true },
    );
    const verified = verifyBehaviorRun({
      root: repoRoot,
      runDir: prepared.runDir,
      judgmentsPath: judgmentsName,
    });
    assert.equal(verified.status, 'PASS');
    assert.equal(verified.grade_status, 'PASS');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('Windows absolute judgments alias resolves to the same physical run file', {
  skip: process.platform !== 'win32',
}, (t) => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'verify-judgments-alias-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgmentsPath = path.join(prepared.runDir, 'alias-judgments.json');
    const lexicalAlias = path.join(
      tempRoot,
      'verify-judgments-alias-run',
      'alias-judgments.json',
    );
    writeJson(
      judgmentsPath,
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    if (lexicalAlias.toLowerCase() === judgmentsPath.toLowerCase()) {
      t.skip('Windows long-path and 8.3 aliases are identical on this host.');
      return;
    }
    gradeBehaviorRun({
      root: repoRoot,
      runDir: prepared.runDir,
      judgmentsPath: lexicalAlias,
    });
    const verified = verifyBehaviorRun({
      root: repoRoot,
      runDir: prepared.runDir,
      judgmentsPath: lexicalAlias,
    });
    assert.equal(verified.status, 'PASS');
    assert.equal(verified.grade_status, 'PASS');

    const cli = runBehaviorCli([
      'verify',
      `--run-dir=${prepared.runDir}`,
      `--judgments=${lexicalAlias}`,
    ]);
    assert.equal(cli.status, 0, cli.stderr);
    assert.equal(JSON.parse(cli.stdout).grade_status, 'PASS');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('Windows run-directory alias resolves the same prepare and post-grade seals', {
  skip: process.platform !== 'win32',
}, (t) => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const runName = 'verify-run-directory-alias';
    const lexicalRunAlias = path.join(tempRoot, runName);
    const prepared = prepareTempRun(tempRoot, runName);
    if (lexicalRunAlias.toLowerCase() === prepared.runDir.toLowerCase()) {
      t.skip('Windows long-path and 8.3 run aliases are identical on this host.');
      return;
    }
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: lexicalRunAlias });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: lexicalRunAlias });

    const direct = verifyBehaviorRun({ root: repoRoot, runDir: lexicalRunAlias });
    assert.equal(direct.status, 'PASS');
    assert.equal(direct.post_grade_seal_status, 'SEALED');
    const cli = runBehaviorCli(['verify', `--run-dir=${lexicalRunAlias}`]);
    assert.equal(cli.status, 0, cli.stderr);
    assert.equal(JSON.parse(cli.stdout).post_grade_seal_status, 'SEALED');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier preserves valid FAIL and BLOCKED grade outcomes', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    for (const expectedStatus of ['FAIL', 'BLOCKED']) {
      const prepared = prepareTempRun(tempRoot, `verified-${expectedStatus.toLowerCase()}-run`);
      const responses = writeResponses(prepared.runDir, cases);
      const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
      const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
      if (expectedStatus === 'FAIL') {
        judgments.case_results[0].normalized.decision_status = 'decision_ready';
      } else {
        judgments.case_results[0].verdict = 'BLOCKED';
        judgments.case_results[0].normalized = null;
        judgments.case_results[0].assertion_results = [];
        judgments.case_results[0].blocked_reason = 'The response cannot be judged.';
      }
      writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);
      const graded = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
      assert.equal(graded.status, expectedStatus);

      const verified = verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
      assert.equal(verified.status, 'PASS');
      assert.equal(verified.grade_status, expectedStatus);
      assert.equal(verified.post_grade_seal_status, 'SEALED');
      assert.deepEqual(verified.counts, graded.counts);
    }
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier reports a missing grade summary without creating it', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'verify-not-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    const before = treeSnapshot(prepared.runDir);

    assert.deepEqual(
      verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      { status: 'NOT_RUN', runDir: prepared.runDir, missing_grade_summary: true },
    );
    assert.deepEqual(treeSnapshot(prepared.runDir), before);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier rejects grade-summary and judge-scaffolding drift', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'verify-drift-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });

    const summaryPath = path.join(prepared.runDir, 'grade-summary.json');
    const originalSummary = readFileSync(summaryPath);
    const changedSummary = readJson(summaryPath);
    changedSummary.counts.pass = 0;
    writeJson(summaryPath, changedSummary);
    assert.throws(
      () => verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Grade summary differs from recomputed result/u,
    );

    writeFileSync(summaryPath, originalSummary);
    const judgePromptPath = path.join(prepared.runDir, 'judge-prompt.md');
    writeFileSync(judgePromptPath, `${readFileSync(judgePromptPath, 'utf8')}drift\n`, 'utf8');
    assert.throws(
      () => verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Judge prompt drifted after judge packet creation/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('sealed run manifest prevents judgment schema downgrade', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'judgment-schema-downgrade-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const legacyJudgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    legacyJudgments.schema_version = legacyJudgmentVersion;
    writeJson(path.join(prepared.runDir, 'judgments.json'), legacyJudgments);
    writeJson(
      path.join(prepared.runDir, 'judgment-output.schema.json'),
      judgmentOutputSchema(
        skillName,
        selectedCaseIds,
        judgeReady.judge_packet_sha256,
        null,
        legacyJudgmentVersion,
      ),
    );
    assert.throws(
      () => gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Judgment output schema drifted after judge packet creation/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('CLI verify exit codes separate integrity status from saved grade outcome', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  const createCompletedRun = (name, mutateJudgments = () => {}) => {
    const prepared = prepareTempRun(tempRoot, name);
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    mutateJudgments(judgments);
    writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);
    gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    return prepared;
  };
  try {
    const passRun = createCompletedRun('cli-verify-pass');
    const failRun = createCompletedRun('cli-verify-fail', (judgments) => {
      judgments.case_results[0].normalized.decision_status = 'decision_ready';
    });
    const blockedRun = createCompletedRun('cli-verify-blocked', (judgments) => {
      judgments.case_results[0].verdict = 'BLOCKED';
      judgments.case_results[0].normalized = null;
      judgments.case_results[0].assertion_results = [];
      judgments.case_results[0].blocked_reason = 'The response cannot be judged.';
    });

    for (const [prepared, gradeStatus] of [
      [passRun, 'PASS'],
      [failRun, 'FAIL'],
      [blockedRun, 'BLOCKED'],
    ]) {
      const result = runBehaviorCli(['verify', `--run-dir=${prepared.runDir}`]);
      assert.equal(result.status, 0, result.stderr);
      const output = JSON.parse(result.stdout);
      assert.equal(output.status, 'PASS');
      assert.equal(output.grade_status, gradeStatus);
    }

    const notRun = prepareTempRun(tempRoot, 'cli-verify-not-run');
    const notRunResult = runBehaviorCli(['verify', `--run-dir=${notRun.runDir}`]);
    assert.equal(notRunResult.status, 2, notRunResult.stderr);
    assert.equal(JSON.parse(notRunResult.stdout).status, 'NOT_RUN');

    const promptPath = path.join(passRun.runDir, 'judge-prompt.md');
    writeFileSync(promptPath, `${readFileSync(promptPath, 'utf8')}drift\n`, 'utf8');
    const errorResult = runBehaviorCli(['verify', `--run-dir=${passRun.runDir}`]);
    assert.equal(errorResult.status, 1, errorResult.stdout);
    assert.equal(JSON.parse(errorResult.stderr).status, 'ERROR');
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('CLI verify does not create a missing evaluation root', () => {
  const isolatedTemp = mkdtempSync(path.join(path.dirname(behaviorEvalTempRoot), 'suxi-verify-read-only-'));
  const isolatedEvalRoot = path.join(isolatedTemp, 'suxi-skill-behavior-evals');
  try {
    const result = runBehaviorCli(
      ['verify', '--run-dir=missing-run'],
      {
        ...process.env,
        TEMP: isolatedTemp,
        TMP: isolatedTemp,
        TMPDIR: isolatedTemp,
      },
    );
    assert.equal(result.status, 1, result.stdout);
    const error = JSON.parse(result.stderr);
    assert.equal(error.status, 'ERROR');
    assert.match(error.error, /Evaluation root is missing/u);
    assert.equal(existsSync(isolatedEvalRoot), false);
  } finally {
    rmSync(isolatedTemp, { recursive: true, force: true });
  }
});

test('deterministic grader fails a normalized behavior mismatch', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'failing-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    judgments.case_results[0].normalized.decision_status = 'decision_ready';
    writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);

    const summary = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(summary.status, 'FAIL');
    assert.equal(summary.counts.fail, 1);
    assert.match(summary.case_results[0].failures.join('\n'), /decision_status/u);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('deterministic grader refuses to seal a fabricated quote span', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'fabricated-span-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    judgments.case_results[0].assertion_results[0].evidence_spans[0] = {
      start: 0,
      end: 10,
      quote: 'fabricated',
    };
    writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);

    assert.throws(
      () => gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /Saved judgment has invalid evidence span/u,
    );
    assert.equal(existsSync(path.join(prepared.runDir, 'grade-summary.json')), false);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('read-only verifier rejects fabricated spans on failed and unexpected assertions', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    for (const variant of ['failed-assertion', 'unexpected-assertion']) {
      const prepared = prepareTempRun(tempRoot, `fabricated-${variant}-verify-run`);
      const responses = writeResponses(prepared.runDir, cases);
      const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
      const valid = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
      writeJson(path.join(prepared.runDir, 'judgments.json'), valid);
      gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
      const judgments = structuredClone(valid);
      judgments.case_results[0].verdict = 'FAIL';
      if (variant === 'failed-assertion') {
        judgments.case_results[0].assertion_results[0].passed = false;
        judgments.case_results[0].assertion_results[0].evidence_spans[0] = {
          start: 0,
          end: 10,
          quote: 'fabricated',
        };
      } else {
        judgments.case_results[0].assertion_results.push({
          assertion: 'Unexpected synthetic assertion.',
          passed: false,
          evidence_spans: [{ start: 0, end: 10, quote: 'fabricated' }],
          explanation: 'Synthetic unexpected assertion.',
        });
      }
      writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);
      assert.throws(
        () => verifyBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
        /Saved judgment has invalid evidence span/u,
      );
    }
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('deterministic grader preserves an unjudgeable case as BLOCKED', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'blocked-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const judgments = validJudgments(cases, responses, judgeReady.judge_packet_sha256);
    judgments.case_results[0].verdict = 'BLOCKED';
    judgments.case_results[0].normalized = null;
    judgments.case_results[0].assertion_results = [];
    judgments.case_results[0].blocked_reason = 'The synthetic response is unavailable to the judge.';
    judgments.case_results[0].notes = 'Synthetic unjudgeable result.';
    writeJson(path.join(prepared.runDir, 'judgments.json'), judgments);

    const summary = gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir });
    assert.equal(summary.status, 'BLOCKED');
    assert.equal(summary.counts.blocked, 1);
    assert.equal(summary.counts.fail, 0);
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('contract drift after prepare blocks judge packet construction', () => {
  const tempRoot = makeTempRoot();
  try {
    const prepared = prepareTempRun(tempRoot, 'drift-run');
    const manifestPath = path.join(prepared.runDir, 'manifest.json');
    const manifest = readJson(manifestPath);
    manifest.behavior_contract_sha256 = '0'.repeat(64);
    writeJson(manifestPath, manifest);
    assert.throws(
      () => buildJudgePacket({ root: repoRoot, runDir: prepared.runDir }),
      /Run manifest changed after prepare|Behavior contract drifted after prepare/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('skill snapshot drift after prepare blocks response collection', () => {
  const tempRoot = makeTempRoot();
  try {
    const prepared = prepareTempRun(tempRoot, 'snapshot-drift-run');
    const snapshotPath = path.join(
      prepared.runDir,
      'cases',
      selectedCaseIds[0],
      'workspace',
      '.agents',
      'skills',
      skillName,
      'SKILL.md',
    );
    writeFileSync(snapshotPath, `${readFileSync(snapshotPath, 'utf8')}\nInjected drift\n`, 'utf8');
    assert.throws(
      () => buildJudgePacket({ root: repoRoot, runDir: prepared.runDir }),
      /workspace drifted after prepare|snapshot file drift/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('response drift after judge packet creation blocks grading', () => {
  const tempRoot = makeTempRoot();
  const cases = selectedCases();
  try {
    const prepared = prepareTempRun(tempRoot, 'response-drift-run');
    const responses = writeResponses(prepared.runDir, cases);
    const judgeReady = buildJudgePacket({ root: repoRoot, runDir: prepared.runDir });
    const responsePath = path.join(prepared.runDir, 'cases', selectedCaseIds[0], 'response.json');
    const changedResponse = readJson(responsePath);
    changedResponse.response_text += '\nChanged after judgment packet.';
    writeJson(responsePath, changedResponse);
    writeJson(
      path.join(prepared.runDir, 'judgments.json'),
      validJudgments(cases, responses, judgeReady.judge_packet_sha256),
    );
    assert.throws(
      () => gradeBehaviorRun({ root: repoRoot, runDir: prepared.runDir }),
      /response changed after judge packet creation|judge packet response drift/u,
    );
  } finally {
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('CLI prepare rejects a run directory outside the system eval root', () => {
  const unsafeOutput = path.join(repoRoot, 'output', 'skill-behavior-evals', 'unsafe-test-run');
  assert.throws(
    () => prepareBehaviorRun({
      root: repoRoot,
      skillName,
      caseIds: [selectedCaseIds[0]],
      outputDir: unsafeOutput,
    }),
    /Run directory must stay under|Reparse-point ancestor escapes/u,
  );
  assert.equal(existsSync(unsafeOutput), false);
});

test('Windows junction cannot redirect a lexically valid run path outside the eval root', {
  skip: process.platform !== 'win32',
}, (t) => {
  mkdirSync(behaviorEvalTempRoot, { recursive: true });
  const outsideRoot = mkdtempSync(path.join(path.dirname(behaviorEvalTempRoot), 'suxi-eval-outside-'));
  const junctionPath = path.join(
    behaviorEvalTempRoot,
    `junction-${process.pid}-${Date.now()}`,
  );
  try {
    try {
      symlinkSync(outsideRoot, junctionPath, 'junction');
    } catch (error) {
      if (['EPERM', 'EACCES'].includes(error.code)) {
        t.skip(`junction creation unavailable: ${error.code}`);
        return;
      }
      throw error;
    }
    assert.throws(
      () => prepareBehaviorRun({
        root: repoRoot,
        skillName,
        caseIds: [selectedCaseIds[0]],
        outputDir: path.join(junctionPath, 'escaped-run'),
      }),
      /Reparse-point ancestor escapes|Symlink or junction ancestor is forbidden/u,
    );
    assert.equal(existsSync(path.join(outsideRoot, 'escaped-run')), false);
  } finally {
    if (existsSync(junctionPath)) unlinkSync(junctionPath);
    rmSync(outsideRoot, { recursive: true, force: true });
  }
});

test('Windows junction cannot replace a prepared case workspace root', {
  skip: process.platform !== 'win32',
}, (t) => {
  const tempRoot = makeTempRoot();
  const outsideRoot = mkdtempSync(path.join(path.dirname(behaviorEvalTempRoot), 'suxi-workspace-outside-'));
  let workspace = '';
  let backup = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'workspace-junction-run');
    workspace = path.join(prepared.runDir, 'cases', selectedCaseIds[0], 'workspace');
    backup = `${workspace}-original`;
    renameSync(workspace, backup);
    try {
      symlinkSync(outsideRoot, workspace, 'junction');
    } catch (error) {
      if (['EPERM', 'EACCES'].includes(error.code)) {
        t.skip(`junction creation unavailable: ${error.code}`);
        return;
      }
      throw error;
    }
    assert.throws(
      () => buildJudgePacket({ root: repoRoot, runDir: prepared.runDir }),
      /workspace must be a regular non-linked directory|workspace escapes|symlink or junction component/u,
    );
  } finally {
    if (workspace && existsSync(workspace) && lstatSync(workspace).isSymbolicLink()) unlinkSync(workspace);
    rmSync(tempRoot, { recursive: true, force: true });
    rmSync(outsideRoot, { recursive: true, force: true });
  }
});

test('Windows junction cannot replace an in-run case ancestor', {
  skip: process.platform !== 'win32',
}, (t) => {
  const tempRoot = makeTempRoot();
  let caseRoot = '';
  let relocatedCaseRoot = '';
  try {
    const prepared = prepareTempRun(tempRoot, 'case-ancestor-junction-run');
    caseRoot = path.join(prepared.runDir, 'cases', selectedCaseIds[0]);
    relocatedCaseRoot = path.join(prepared.runDir, `${selectedCaseIds[0]}-relocated`);
    renameSync(caseRoot, relocatedCaseRoot);
    try {
      symlinkSync(relocatedCaseRoot, caseRoot, 'junction');
    } catch (error) {
      if (['EPERM', 'EACCES'].includes(error.code)) {
        t.skip(`junction creation unavailable: ${error.code}`);
        return;
      }
      throw error;
    }
    assert.throws(
      () => buildJudgePacket({ root: repoRoot, runDir: prepared.runDir }),
      /symlink or junction component/u,
    );
  } finally {
    if (caseRoot && existsSync(caseRoot) && lstatSync(caseRoot).isSymbolicLink()) unlinkSync(caseRoot);
    rmSync(tempRoot, { recursive: true, force: true });
  }
});

test('behavior runner contains no model, API, network, or child-process executor', () => {
  const source = readFileSync(runnerPath, 'utf8');
  const imports = [...source.matchAll(/\bfrom\s+['"]([^'"]+)['"]/gu)]
    .map(match => match[1])
    .sort();
  assert.deepEqual(imports, [
    'node:crypto',
    'node:fs',
    'node:os',
    'node:path',
    'node:url',
    'node:util',
  ]);
  assert.doesNotMatch(
    source,
    /\bprocess\.env\b|\bfetch\s*\(|\bWebSocket\b|\bXMLHttpRequest\b|\bimport\s*\(|\beval\s*\(|\bnew\s+Function\b|\bDeno\.|\bBun\./u,
  );
  assert.doesNotMatch(source, /node:child_process|\bspawn(?:Sync)?\b|\bexecFile(?:Sync)?\b/u);
  assert.doesNotMatch(source, /OPENAI_API_KEY|CODEX_API_KEY|Authorization\s*:/u);
  assert.doesNotMatch(source, /https?:\/\//u);
  assert.doesNotMatch(source, /codex\s+exec/u);
});
