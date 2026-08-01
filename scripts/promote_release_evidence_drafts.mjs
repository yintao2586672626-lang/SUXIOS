import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';
import { checkOtaCredentialRotationAttestation } from './lib/ota_credential_checks.mjs';
import { safeJsonParseErrorCode } from './lib/safe_json_parse_error.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const draftDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DRAFT_DIR || path.join(evidenceDir, 'drafts'));
const candidateDir = path.join(evidenceDir, '.promotion-candidates');
const promotionResultPath = path.resolve(
  repoRoot,
  process.env.RELEASE_EVIDENCE_PROMOTION_RESULT_FILE || path.join(evidenceDir, 'release-evidence-promotion-result.json'),
);
const designDraftPath = path.join(draftDir, 'design_handoff_manifest.draft.json');
const otaDraftPath = path.join(draftDir, 'ota_credential_rotation_attestation.draft.json');
const designFinalPath = path.resolve(
  repoRoot,
  process.env.DESIGN_HANDOFF_MANIFEST_FILE || path.join(evidenceDir, 'design_handoff_manifest.json'),
);
const otaFinalPath = path.resolve(
  repoRoot,
  process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE || path.join(evidenceDir, 'ota_credential_rotation_attestation.json'),
);
const allowOverwrite = /^(1|true|yes)$/i.test(String(process.env.RELEASE_EVIDENCE_PROMOTE_OVERWRITE || ''));

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function formatPath(filePath) {
  return path.resolve(filePath);
}

function addPathFailure(failures, label, filePath) {
  if (isPathInsideRepo(filePath)) {
    failures.push(`${label} must be outside the repository: ${formatPath(filePath)}`);
  }
}

function readJsonWithRaw(filePath, label, failures) {
  if (!fs.existsSync(filePath)) {
    failures.push(`${label} was not found: ${formatPath(filePath)}`);
    return null;
  }

  try {
    const raw = fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, '');
    return {
      raw,
      json: JSON.parse(raw),
    };
  } catch (error) {
    failures.push(`${label} is not valid JSON (${safeJsonParseErrorCode(error)}).`);
    return null;
  }
}

function stringifyAsciiJson(value) {
  return JSON.stringify(value, null, 2).replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function collectDraftResidue(value, label, findings, pathParts = []) {
  const pathLabel = pathParts.length > 0 ? pathParts.join('.') : '<root>';
  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      collectDraftResidue(item, label, findings, [...pathParts, String(index)]);
    }
    return;
  }
  if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      if (key === '_draft_notice') {
        findings.push(`${label}.${[...pathParts, key].join('.')} must be removed before promotion`);
      }
      collectDraftResidue(child, label, findings, [...pathParts, key]);
    }
    return;
  }
  if (typeof value !== 'string') {
    return;
  }
  if (/\bTODO\b|CHANGE_ME|placeholder|TODO-|Draft only|Do not rename or copy/i.test(value) || /example\.com/i.test(value)) {
    findings.push(`${label}.${pathLabel} still contains draft or placeholder text`);
  }
}

function isRedactedSensitiveValue(value) {
  const text = String(value ?? '').trim();
  return text === ''
    || /redacted|masked|not stored|not included|secure record|internal ticket|ticket|rotated|invalidated|sanitized|encrypted archive/i.test(text)
    || /^<[^>]*redact[^>]*>$/i.test(text)
    || /^\*+$/.test(text);
}

function collectSensitiveValues(value, label, findings, pathParts = []) {
  const sensitiveKeys = new Set([
    'apikey',
    'authorization',
    'cookie',
    'password',
    'secret',
    'signature',
    'token',
    'usertoken',
    'usersign',
  ]);
  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      collectSensitiveValues(item, label, findings, [...pathParts, String(index)]);
    }
    return;
  }
  if (!value || typeof value !== 'object') {
    return;
  }
  for (const [key, child] of Object.entries(value)) {
    const childPath = [...pathParts, key];
    const normalizedKey = key.toLowerCase().replace(/[^a-z0-9]/g, '');
    if (sensitiveKeys.has(normalizedKey) && typeof child === 'string' && !isRedactedSensitiveValue(child)) {
      findings.push(`${label}.${childPath.join('.')} appears to contain unredacted sensitive material`);
    }
    collectSensitiveValues(child, label, findings, childPath);
  }
}

function rawSecretFindings(raw, label) {
  const findings = [];
  const patterns = [
    /Bearer\s+(?!redacted|masked|<redacted>)\S{8,}/i,
    /(?:usertoken|usersign|cookie|authorization|password|api[_-]?key|access[_-]?token|refresh[_-]?token|session[_-]?token)\s*[:=]\s*["']?(?!redacted|masked|not included|not stored|<redacted>)[^"',\s]{8,}/i,
  ];
  for (const pattern of patterns) {
    if (pattern.test(raw)) {
      findings.push(`${label} appears to contain credential-shaped text`);
    }
  }
  return findings;
}

function validateDraftPayload({ label, raw, json }) {
  const failures = [];
  collectDraftResidue(json, label, failures);
  collectSensitiveValues(json, label, failures);
  failures.push(...rawSecretFindings(raw, label));
  return failures;
}

function writeCandidate(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

function collectCheckFailures(label, result) {
  return [
    ...result.failures.map((failure) => `${label}: ${failure}`),
    ...result.warnings
      .filter((warning) => /Skipped unreadable/i.test(warning))
      .map((warning) => `${label}: ${warning}`),
  ];
}

function buildPromotionResult({
  status,
  canPromote,
  failures,
  designCandidatePath,
  otaCandidatePath,
  copiedFinalFiles = [],
}) {
  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    command: 'npm run promote:release-evidence-drafts',
    release_ready: false,
    does_not_close_release_readiness: true,
    status,
    can_promote: canPromote === true,
    evidence_dir: evidenceDir,
    draft_dir: draftDir,
    candidate_dir: candidateDir,
    result_file: promotionResultPath,
    overwrite_allowed: allowOverwrite,
    paths: {
      design_draft: designDraftPath,
      ota_credential_draft: otaDraftPath,
      design_candidate: designCandidatePath || null,
      ota_credential_candidate: otaCandidatePath || null,
      design_final: designFinalPath,
      ota_credential_final: otaFinalPath,
    },
    summary: {
      failure_count: failures.length,
      copied_final_file_count: copiedFinalFiles.length,
      final_release_evidence_files_written: copiedFinalFiles.length === 2,
      no_final_release_evidence_files_written: copiedFinalFiles.length === 0,
    },
    failures,
    copied_final_files: copiedFinalFiles,
    next_commands: canPromote === true
      ? [
        'npm run review:release-design',
        'npm run review:release-ota-credentials',
        'npm run review:release-readiness',
      ]
      : [
        'Fix the listed draft promotion failures.',
        'npm run review:release-evidence-drafts',
        'npm run promote:release-evidence-drafts',
      ],
    forbidden_closure: [
      'Do not manually copy draft files to final release evidence paths.',
      'Do not treat this promotion result as release-ready evidence.',
      'Do not mark release readiness closed until npm run review:release-readiness passes.',
    ],
  };
}

function writePromotionResult(result) {
  if (isPathInsideRepo(promotionResultPath)) {
    return false;
  }
  fs.mkdirSync(path.dirname(promotionResultPath), { recursive: true });
  fs.writeFileSync(promotionResultPath, `${stringifyAsciiJson(result)}\n`, 'utf8');
  return true;
}

const failures = [];
addPathFailure(failures, 'Release evidence directory', evidenceDir);
addPathFailure(failures, 'Release evidence draft directory', draftDir);
addPathFailure(failures, 'Design handoff final manifest', designFinalPath);
addPathFailure(failures, 'OTA credential final attestation', otaFinalPath);
addPathFailure(failures, 'Release evidence promotion candidate directory', candidateDir);
addPathFailure(failures, 'Release evidence promotion result', promotionResultPath);

if (!allowOverwrite) {
  if (fs.existsSync(designFinalPath)) {
    failures.push(`Design handoff final manifest already exists; set RELEASE_EVIDENCE_PROMOTE_OVERWRITE=1 to replace it: ${formatPath(designFinalPath)}`);
  }
  if (fs.existsSync(otaFinalPath)) {
    failures.push(`OTA credential final attestation already exists; set RELEASE_EVIDENCE_PROMOTE_OVERWRITE=1 to replace it: ${formatPath(otaFinalPath)}`);
  }
}

const designDraft = readJsonWithRaw(designDraftPath, 'Design handoff draft', failures);
const otaDraft = readJsonWithRaw(otaDraftPath, 'OTA credential rotation draft', failures);

if (designDraft) {
  failures.push(...validateDraftPayload({
    label: 'design_handoff_manifest.draft.json',
    raw: designDraft.raw,
    json: designDraft.json,
  }));
}
if (otaDraft) {
  failures.push(...validateDraftPayload({
    label: 'ota_credential_rotation_attestation.draft.json',
    raw: otaDraft.raw,
    json: otaDraft.json,
  }));
}

let designCandidatePath = null;
let otaCandidatePath = null;
if (failures.length === 0) {
  designCandidatePath = path.join(candidateDir, 'design_handoff_manifest.candidate.json');
  otaCandidatePath = path.join(candidateDir, 'ota_credential_rotation_attestation.candidate.json');
  writeCandidate(designCandidatePath, designDraft.json);
  writeCandidate(otaCandidatePath, otaDraft.json);

  failures.push(...collectCheckFailures(
    'Design candidate',
    checkDesignHandoff({ repoRoot, manifestPath: designCandidatePath, requireOutsideRepo: true }),
  ));
  failures.push(...collectCheckFailures(
    'OTA credential candidate',
    checkOtaCredentialRotationAttestation({ repoRoot, attestationPath: otaCandidatePath, requireOutsideRepo: true }),
  ));
}

if (failures.length > 0) {
  const resultWritten = writePromotionResult(buildPromotionResult({
    status: 'failed',
    canPromote: false,
    failures,
    designCandidatePath,
    otaCandidatePath,
  }));
  console.error('Release evidence draft promotion failed. No final release evidence files were written.');
  if (resultWritten) {
    console.error(`Promotion result: ${promotionResultPath}`);
  } else {
    console.error('Promotion result was not written because its configured path is inside the repository.');
  }
  for (const failure of failures) {
    console.error(`- ${failure}`);
  }
  process.exit(1);
}

fs.mkdirSync(path.dirname(designFinalPath), { recursive: true });
fs.mkdirSync(path.dirname(otaFinalPath), { recursive: true });
fs.copyFileSync(designCandidatePath, designFinalPath);
fs.copyFileSync(otaCandidatePath, otaFinalPath);

writePromotionResult(buildPromotionResult({
  status: 'passed',
  canPromote: true,
  failures: [],
  designCandidatePath,
  otaCandidatePath,
  copiedFinalFiles: [
    designFinalPath,
    otaFinalPath,
  ],
}));

console.log('Release evidence drafts promoted to final evidence paths.');
console.log(`Promotion result: ${promotionResultPath}`);
console.log(`Design handoff manifest: ${designFinalPath}`);
console.log(`OTA credential rotation attestation: ${otaFinalPath}`);
console.log('Run npm run review:release-design, npm run review:release-ota-credentials, and npm run review:release-readiness next.');
