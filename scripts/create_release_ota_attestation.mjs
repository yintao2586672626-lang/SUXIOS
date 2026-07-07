import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { checkOtaCredentialRelease } from './lib/ota_credential_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function formatPath(filePath) {
  return path.resolve(resolveInputPath(filePath));
}

function readJsonFile(filePath) {
  const raw = fs.readFileSync(resolveInputPath(filePath), 'utf8').replace(/^\uFEFF/, '');
  return JSON.parse(raw);
}

function outputPath() {
  const configuredPath = process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT
    || process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE;
  return configuredPath
    ? resolveInputPath(configuredPath)
    : path.join(releaseEvidenceDir, 'ota_credential_rotation_attestation.json');
}

function resultPath() {
  return process.env.OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE
    ? resolveInputPath(process.env.OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE)
    : path.join(releaseEvidenceDir, 'release-ota-attestation-create-result.json');
}

function stringifyAsciiJson(value) {
  return JSON.stringify(value, null, 2).replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function printResult(prefix, result) {
  for (const message of result.passes) {
    console.log(`PASS: ${prefix}: ${message}`);
  }
  for (const message of result.warnings) {
    console.warn(`WARN: ${prefix}: ${message}`);
  }
  for (const message of result.failures) {
    console.error(`FAIL: ${prefix}: ${message}`);
  }
}

function fileSha256(filePath) {
  if (!filePath) {
    return null;
  }
  const resolvedPath = resolveInputPath(filePath);
  if (!fs.existsSync(resolvedPath)) {
    return null;
  }
  return createHash('sha256').update(fs.readFileSync(resolvedPath)).digest('hex');
}

const createResultPath = resultPath();
let finalPath = null;
let inputResult = null;
let outputResult = null;

function buildResult(status, failures = []) {
  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    command: 'npm run release:create-ota-attestation',
    release_ready: false,
    does_not_close_release_readiness: true,
    status,
    can_create_attestation: status === 'passed',
    result_file: createResultPath,
    evidence_dir: releaseEvidenceDir,
    input_file: inputPath ? formatPath(inputPath) : null,
    input_file_sha256: fileSha256(inputPath),
    output_file: finalPath ? formatPath(finalPath) : null,
    output_file_sha256: fileSha256(finalPath),
    source_mode: inputPath ? 'external_attestation_input' : null,
    summary: {
      failure_count: failures.length,
      final_attestation_written: status === 'passed',
      input_failure_count: inputResult?.failures?.length ?? null,
      output_failure_count: outputResult?.failures?.length ?? null,
    },
    failures,
    next_commands: status === 'passed'
      ? [
        'npm run review:release-ota-credentials',
        'npm run review:release-readiness',
      ]
      : [
        'Set OTA_CREDENTIAL_ROTATION_INPUT_FILE to a real external reviewed OTA credential rotation attestation JSON.',
        'Do not point OTA_CREDENTIAL_ROTATION_INPUT_FILE at a repository file, draft file, or the same path as the final output.',
        'npm run release:create-ota-attestation',
      ],
    forbidden_closure: [
      'Do not include Cookie, token, usertoken, usersign, signature, Authorization, password, or reusable login state values.',
      'Do not treat this creation result as release-ready evidence.',
      'Do not close release readiness until npm run review:release-readiness passes.',
    ],
  };
}

function writeCreateResult(payload) {
  if (isPathInsideRepo(createResultPath)) {
    console.error(`WARN: OTA credential attestation creation result was not written because the result path is inside the repository: ${formatPath(createResultPath)}.`);
    return false;
  }
  fs.mkdirSync(path.dirname(createResultPath), { recursive: true });
  fs.writeFileSync(createResultPath, `${stringifyAsciiJson(payload)}\n`, 'utf8');
  return true;
}

function fail(message) {
  writeCreateResult(buildResult('failed', [message]));
  console.error(`FAIL: ${message}`);
  console.error(`Creation result: ${formatPath(createResultPath)}`);
  process.exit(1);
}

const inputPath = process.env.OTA_CREDENTIAL_ROTATION_INPUT_FILE;
if (!inputPath || !inputPath.trim()) {
  fail('OTA_CREDENTIAL_ROTATION_INPUT_FILE is required and must point to an external reviewed attestation JSON.');
}
if (isPathInsideRepo(inputPath)) {
  fail(`OTA_CREDENTIAL_ROTATION_INPUT_FILE must be outside the repository: ${formatPath(inputPath)}.`);
}
if (!fs.existsSync(resolveInputPath(inputPath))) {
  fail(`OTA credential rotation input file was not found: ${formatPath(inputPath)}.`);
}

finalPath = outputPath();
if (isPathInsideRepo(finalPath)) {
  fail(`OTA credential rotation attestation output must be outside the repository: ${formatPath(finalPath)}.`);
}
if (isPathInsideRepo(createResultPath)) {
  fail(`OTA credential rotation attestation creation result must be outside the repository: ${formatPath(createResultPath)}.`);
}
if (path.resolve(resolveInputPath(inputPath)) === path.resolve(finalPath)) {
  fail('OTA credential rotation attestation input and output paths must be different.');
}

const allowOverwrite = /^(1|true|yes)$/i.test(String(process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_OVERWRITE || ''));
if (fs.existsSync(finalPath) && !allowOverwrite) {
  fail(`OTA credential rotation attestation output already exists; set OTA_CREDENTIAL_ROTATION_ATTESTATION_OVERWRITE=1 to replace it: ${formatPath(finalPath)}.`);
}

let attestation = null;
try {
  attestation = readJsonFile(inputPath);
} catch (error) {
  fail(`OTA credential rotation input file is not valid JSON: ${error.message}`);
}

inputResult = checkOtaCredentialRelease({
  repoRoot,
  attestationPath: inputPath,
  requireOutsideRepo: true,
});
printResult('input', inputResult);
if (inputResult.failures.length > 0) {
  fail('Release OTA credential attestation input did not pass verifier; no final evidence file was written.');
}
console.log('PASS: Release OTA credential attestation input passed verifier.');

fs.mkdirSync(path.dirname(finalPath), { recursive: true });
fs.writeFileSync(finalPath, `${JSON.stringify(attestation, null, 2)}\n`, 'utf8');

outputResult = checkOtaCredentialRelease({
  repoRoot,
  attestationPath: finalPath,
  requireOutsideRepo: true,
});
printResult('output', outputResult);
if (outputResult.failures.length > 0) {
  fail('Release OTA credential attestation output did not pass verifier; review the output before using it for release-readiness.');
}

writeCreateResult(buildResult('passed'));
console.log('PASS: Release OTA credential attestation output passed verifier.');
console.log(`Creation result: ${formatPath(createResultPath)}`);
console.log(`Wrote OTA credential rotation attestation: ${formatPath(finalPath)}`);
console.log('Run npm run review:release-ota-credentials and npm run review:release-readiness next.');
