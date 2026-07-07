import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'release-evidence-intake-'));
const scriptsDir = path.join(repoRoot, 'scripts');
const designScript = path.join(scriptsDir, 'create_release_design_manifest.mjs');
const otaScript = path.join(scriptsDir, 'create_release_ota_attestation.mjs');

const passes = [];
const failures = [];

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, ''));
}

function fileSha256(filePath) {
  return createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
}

function cleanReleaseEnv(overrides) {
  const env = { ...process.env };
  for (const key of [
    'RELEASE_EVIDENCE_DIR',
    'DESIGN_HANDOFF_MANIFEST_INPUT_FILE',
    'DESIGN_HANDOFF_MANIFEST_OUTPUT',
    'DESIGN_HANDOFF_MANIFEST_FILE',
    'DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE',
    'DESIGN_HANDOFF_OWNER',
    'CANVA_BRAND_KIT_URL',
    'DESIGN_HANDOFF_SOURCE_REVIEW_METHOD',
    'DESIGN_HANDOFF_SOURCE_REVIEW_REF',
    'OTA_CREDENTIAL_ROTATION_INPUT_FILE',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_OVERWRITE',
    'OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE',
  ]) {
    delete env[key];
  }
  return { ...env, ...overrides };
}

function runNodeScript(scriptPath, envOverrides) {
  const result = spawnSync(process.execPath, [scriptPath], {
    cwd: repoRoot,
    env: cleanReleaseEnv(envOverrides),
    encoding: 'utf8',
  });
  return {
    status: result.status ?? 1,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    combined: `${result.stdout || ''}\n${result.stderr || ''}`,
  };
}

function expect(condition, message) {
  if (condition) {
    passes.push(message);
  } else {
    failures.push(message);
  }
}

function todayDateOnly() {
  return new Date().toISOString().slice(0, 10);
}

function validDesignManifest() {
  const reviewDate = todayDateOnly();
  return {
    owner: 'Release Design Owner',
    last_reviewed_at: reviewDate,
    figma_url: 'https://www.figma.com/file/release-design-source',
    canva_url: 'https://www.canva.com/design/release-editable-design',
    brand_kit_url: 'https://www.canva.com/brand/release-brand-kit',
    design_tokens_path: 'docs/design-tokens.release.json',
    covered_flows: [
      'login',
      'home-dashboard',
      'ota-data',
      'revenue-analysis',
      'ai-decision',
      'operations-management',
      'investment-decision',
    ],
    source_review: {
      review_method: 'manual_access_review',
      evidence_ref: `release-design-audit-record-${reviewDate}`,
      figma_source_verified: true,
      canva_source_verified: true,
      brand_kit_source_verified: true,
      design_tokens_reviewed: true,
      required_flows_reviewed: true,
    },
    open_issues: [],
    notes: 'Temporary verifier fixture stored outside release evidence.',
  };
}

function designManifestWithEvidenceRef(evidenceRef) {
  const manifest = validDesignManifest();
  manifest.source_review = {
    ...manifest.source_review,
    evidence_ref: evidenceRef,
  };
  return manifest;
}

function validOtaAttestation() {
  const reviewDate = todayDateOnly();
  return {
    reviewed_at: reviewDate,
    reviewer: 'Release Security Reviewer',
    redaction_checked: true,
    platforms: [
      {
        platform: 'ctrip',
        scope: 'hotel-merchant-scope-ctrip',
        credential_types: ['cookie', 'token', 'usertoken', 'usersign', 'signature', 'authorization'],
        action: 'rotated',
        evidence_ref: `secure-rotation-record-ctrip-${reviewDate}`,
      },
      {
        platform: 'meituan',
        scope: 'hotel-merchant-scope-meituan',
        credential_types: ['cookie', 'token', 'usertoken', 'usersign', 'signature', 'authorization'],
        action: 'rotated',
        evidence_ref: `secure-rotation-record-meituan-${reviewDate}`,
      },
    ],
    backup_cleanup: {
      database_backups_action: 'sanitized',
      paths_reviewed: ['database/backups'],
      git_tracking_check: `git ls-files database/backups returned no tracked files on ${reviewDate}`,
      release_readiness_check: `review:release-ota-credentials and review:release-readiness rerun recorded on ${reviewDate}`,
    },
    notes: 'Temporary verifier fixture with redacted evidence references only.',
  };
}

function otaAttestationWithPlatformAction(action) {
  const attestation = validOtaAttestation();
  attestation.platforms = attestation.platforms.map((platform) => ({
    ...platform,
    action,
  }));
  return attestation;
}

function verifyDesignIntake() {
  const designDir = path.join(tempRoot, 'design');
  const inputPath = path.join(designDir, 'input', 'design_handoff_manifest.json');
  const outputPath = path.join(designDir, 'output', 'design_handoff_manifest.json');
  const resultPath = path.join(designDir, 'result', 'release-design-manifest-create-result.json');
  writeJson(inputPath, validDesignManifest());

  const success = runNodeScript(designScript, {
    RELEASE_EVIDENCE_DIR: path.join(designDir, 'evidence'),
    DESIGN_HANDOFF_MANIFEST_INPUT_FILE: inputPath,
    DESIGN_HANDOFF_MANIFEST_OUTPUT: outputPath,
    DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE: resultPath,
  });
  expect(success.status === 0, 'design intake accepts complete external manifest fixture');
  expect(fs.existsSync(outputPath), 'design intake writes final manifest only after verifier pass');
  expect(fs.existsSync(resultPath), 'design intake writes create-result outside repository');
  if (fs.existsSync(resultPath)) {
    const result = readJson(resultPath);
    expect(result.source_mode === 'external_manifest_input', 'design create-result records external_manifest_input source_mode');
    expect(result.input_file_sha256 === fileSha256(inputPath), 'design create-result records input file sha256');
    expect(result.candidate_file_sha256 === fileSha256(result.candidate_file), 'design create-result records candidate file sha256');
    expect(result.output_file_sha256 === fileSha256(outputPath), 'design create-result records output file sha256');
    expect(result.release_ready === false && result.does_not_close_release_readiness === true, 'design create-result cannot close release readiness');
  }

  const repoOutputPath = path.join(designDir, 'repo-input-output.json');
  const repoInput = runNodeScript(designScript, {
    RELEASE_EVIDENCE_DIR: path.join(designDir, 'repo-input-evidence'),
    DESIGN_HANDOFF_MANIFEST_INPUT_FILE: 'docs/design_handoff_manifest.example.json',
    DESIGN_HANDOFF_MANIFEST_OUTPUT: repoOutputPath,
    DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE: path.join(designDir, 'repo-input-result.json'),
  });
  expect(repoInput.status !== 0, 'design intake rejects repository input path');
  expect(/DESIGN_HANDOFF_MANIFEST_INPUT_FILE must be outside the repository/.test(repoInput.combined), 'design intake explains repository input rejection');
  expect(!fs.existsSync(repoOutputPath), 'design intake does not write final output for repository input');

  const samePath = runNodeScript(designScript, {
    RELEASE_EVIDENCE_DIR: path.join(designDir, 'same-path-evidence'),
    DESIGN_HANDOFF_MANIFEST_INPUT_FILE: inputPath,
    DESIGN_HANDOFF_MANIFEST_OUTPUT: inputPath,
    DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE: path.join(designDir, 'same-path-result.json'),
  });
  expect(samePath.status !== 0, 'design intake rejects same input and output path');
  expect(/input and output paths must be different/.test(samePath.combined), 'design intake explains same-path rejection');

  const blockerRefInputPath = path.join(designDir, 'blocked-source-evidence', 'input', 'design_handoff_manifest.json');
  const blockerRefOutputPath = path.join(designDir, 'blocked-source-evidence', 'output', 'design_handoff_manifest.json');
  writeJson(
    blockerRefInputPath,
    designManifestWithEvidenceRef('docs/release_figma_handoff_evidence.json; docs/release_canva_handoff_evidence.json'),
  );
  const blockerRef = runNodeScript(designScript, {
    RELEASE_EVIDENCE_DIR: path.join(designDir, 'blocked-source-evidence', 'evidence'),
    DESIGN_HANDOFF_MANIFEST_INPUT_FILE: blockerRefInputPath,
    DESIGN_HANDOFF_MANIFEST_OUTPUT: blockerRefOutputPath,
    DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE: path.join(designDir, 'blocked-source-evidence', 'result', 'release-design-manifest-create-result.json'),
  });
  expect(blockerRef.status !== 0, 'design intake rejects connector blocker evidence as source_review evidence_ref');
  expect(/source_review\.evidence_ref must not reference non-closing connector blocker evidence/.test(blockerRef.combined), 'design intake explains connector blocker evidence cannot close source_review');
  expect(!fs.existsSync(blockerRefOutputPath), 'design intake does not write final output for connector blocker evidence_ref');
}

function verifyOtaIntake() {
  const otaDir = path.join(tempRoot, 'ota');
  const inputPath = path.join(otaDir, 'input', 'ota_credential_rotation_attestation.json');
  const outputPath = path.join(otaDir, 'output', 'ota_credential_rotation_attestation.json');
  const resultPath = path.join(otaDir, 'result', 'release-ota-attestation-create-result.json');
  writeJson(inputPath, validOtaAttestation());

  const success = runNodeScript(otaScript, {
    RELEASE_EVIDENCE_DIR: path.join(otaDir, 'evidence'),
    OTA_CREDENTIAL_ROTATION_INPUT_FILE: inputPath,
    OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT: outputPath,
    OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE: resultPath,
  });
  expect(success.status === 0, 'OTA intake accepts complete external attestation fixture');
  expect(fs.existsSync(outputPath), 'OTA intake writes final attestation only after verifier pass');
  expect(fs.existsSync(resultPath), 'OTA intake writes create-result outside repository');
  if (fs.existsSync(resultPath)) {
    const result = readJson(resultPath);
    expect(result.source_mode === 'external_attestation_input', 'OTA create-result records external_attestation_input source_mode');
    expect(result.input_file_sha256 === fileSha256(inputPath), 'OTA create-result records input file sha256');
    expect(result.output_file_sha256 === fileSha256(outputPath), 'OTA create-result records output file sha256');
    expect(result.release_ready === false && result.does_not_close_release_readiness === true, 'OTA create-result cannot close release readiness');
  }

  const repoOutputPath = path.join(otaDir, 'repo-input-output.json');
  const repoInput = runNodeScript(otaScript, {
    RELEASE_EVIDENCE_DIR: path.join(otaDir, 'repo-input-evidence'),
    OTA_CREDENTIAL_ROTATION_INPUT_FILE: 'docs/ota_credential_rotation_attestation.example.json',
    OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT: repoOutputPath,
    OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE: path.join(otaDir, 'repo-input-result.json'),
  });
  expect(repoInput.status !== 0, 'OTA intake rejects repository input path');
  expect(/OTA_CREDENTIAL_ROTATION_INPUT_FILE must be outside the repository/.test(repoInput.combined), 'OTA intake explains repository input rejection');
  expect(!fs.existsSync(repoOutputPath), 'OTA intake does not write final output for repository input');

  const samePath = runNodeScript(otaScript, {
    RELEASE_EVIDENCE_DIR: path.join(otaDir, 'same-path-evidence'),
    OTA_CREDENTIAL_ROTATION_INPUT_FILE: inputPath,
    OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT: inputPath,
    OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE: path.join(otaDir, 'same-path-result.json'),
  });
  expect(samePath.status !== 0, 'OTA intake rejects same input and output path');
  expect(/input and output paths must be different/.test(samePath.combined), 'OTA intake explains same-path rejection');

  for (const action of ['sanitized', 'encrypted_archive']) {
    const actionDir = path.join(otaDir, `platform-action-${action}`);
    const actionInputPath = path.join(actionDir, 'input', 'ota_credential_rotation_attestation.json');
    const actionOutputPath = path.join(actionDir, 'output', 'ota_credential_rotation_attestation.json');
    const actionResultPath = path.join(actionDir, 'result', 'release-ota-attestation-create-result.json');
    writeJson(actionInputPath, otaAttestationWithPlatformAction(action));
    const platformAction = runNodeScript(otaScript, {
      RELEASE_EVIDENCE_DIR: path.join(actionDir, 'evidence'),
      OTA_CREDENTIAL_ROTATION_INPUT_FILE: actionInputPath,
      OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT: actionOutputPath,
      OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE: actionResultPath,
    });
    expect(platformAction.status !== 0, `OTA intake rejects ${action} as a platform credential action`);
    expect(/action must be rotated or invalidated/.test(platformAction.combined), `OTA intake explains ${action} is not a platform rotation action`);
    expect(/backup cleanup actions belong in backup_cleanup\.database_backups_action/.test(platformAction.combined), `OTA intake points ${action} to backup_cleanup instead of platform action`);
    expect(!fs.existsSync(actionOutputPath), `OTA intake does not write final output for ${action} platform action`);
  }
}

try {
  verifyDesignIntake();
  verifyOtaIntake();
} catch (error) {
  failures.push(`release evidence intake contract crashed: ${error.message}`);
}

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release evidence intake contract summary: ${passes.length} passed, ${failures.length} failures.`);

if (failures.length > 0) {
  console.error(`Temporary verifier directory retained for inspection: ${tempRoot}`);
  process.exit(1);
}

const tempParent = path.resolve(os.tmpdir());
const resolvedTempRoot = path.resolve(tempRoot);
if (resolvedTempRoot.startsWith(tempParent + path.sep) && path.basename(resolvedTempRoot).startsWith('release-evidence-intake-')) {
  fs.rmSync(resolvedTempRoot, { recursive: true, force: true });
}
