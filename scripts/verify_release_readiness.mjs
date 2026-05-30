import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';
import { checkLlmConnectivityAttestation as checkLlmAttestationFile } from './lib/llm_attestation_checks.mjs';
import { checkBackupCredentialFields, checkOtaCredentialRotationAttestation as checkOtaAttestationFile } from './lib/ota_credential_checks.mjs';
import { checkProductionEnvFile } from './lib/release_env_checks.mjs';
import { checkSecurityScanReports } from './lib/security_scan_checks.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const failures = [];
const warnings = [];
const passes = [];

function addPass(message) {
  passes.push(message);
}

function addFailure(message) {
  failures.push(message);
}

function addWarning(message) {
  warnings.push(message);
}

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function readText(relativePath) {
  return fs.readFileSync(resolveInputPath(relativePath), 'utf8');
}

function resolveOutputPath(outputPath) {
  return path.isAbsolute(outputPath) ? outputPath : path.join(repoRoot, outputPath);
}

function exists(relativePath) {
  return fs.existsSync(resolveInputPath(relativePath));
}

function sleepMs(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

function checkEnvReadiness() {
  const envFile = process.env.RELEASE_ENV_FILE || '.env.production';
  const result = checkProductionEnvFile({
    repoRoot,
    envFile,
    requireOutsideRepo: Boolean(process.env.RELEASE_ENV_FILE),
  });
  result.passes.forEach(addPass);
  result.failures.forEach(addFailure);
}

function checkOpenAiEntrypoints() {
  const openAiClient = exists('app/service/OpenAIClient.php') ? readText('app/service/OpenAIClient.php') : '';
  const llmClient = exists('app/service/LlmClient.php') ? readText('app/service/LlmClient.php') : '';

  if (openAiClient.includes('OPENAI_API_KEY') && llmClient.includes('AI_CONFIG_SECRET')) {
    addFailure('Two AI configuration paths exist: OpenAIClient reads .env keys, while LlmClient reads encrypted DB model configs. Production entrypoint decision is required.');
  } else if (llmClient.includes('AI_CONFIG_SECRET') && llmClient.includes('AiModelConfig::where')) {
    addPass('Production AI client path is LlmClient with encrypted database model configs.');
  } else {
    addFailure('LlmClient database model configuration path was not detected.');
  }
}

function checkLlmConnectivityAttestation() {
  const attestationPath = process.env.LLM_CONNECTIVITY_ATTESTATION_FILE || 'docs/llm_connectivity_attestation.json';
  const result = checkLlmAttestationFile({ repoRoot, attestationPath });
  result.passes.forEach(addPass);
  result.failures.forEach(addFailure);
}

function checkDesignArtifacts() {
  const result = checkDesignHandoff({ repoRoot });
  result.passes.forEach(addPass);
  result.warnings.forEach(addWarning);
  result.failures.forEach(addFailure);
}

function checkBackups() {
  const result = checkBackupCredentialFields({ repoRoot });
  result.passes.forEach(addPass);
  result.warnings.forEach(addWarning);
  result.failures.forEach(addFailure);
}

function checkOtaCredentialRotationAttestation() {
  const attestationPath = process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE || 'docs/ota_credential_rotation_attestation.json';
  const result = checkOtaAttestationFile({ repoRoot, attestationPath });
  result.passes.forEach(addPass);
  result.warnings.forEach(addWarning);
  result.failures.forEach(addFailure);
}

function checkReleasePackageScope() {
  const gitignore = exists('.gitignore') ? readText('.gitignore') : '';
  const requiredIgnores = [
    '.env',
    '.env.*',
    'database/backups/',
    'hotelx_dump.sql',
    '*_dump.sql',
    '*_backup*.sql',
    '/storage/meituan_profile_*/',
    '/storage/ctrip_profile_*/',
    'reports/ctrip_capture_assets/',
    'reports/meituan_capture_assets/',
    'reports/ctrip_browser_capture_*.json',
    'reports/meituan_browser_capture_*.json',
  ];

  const missing = requiredIgnores.filter((entry) => {
    const escaped = entry.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return !new RegExp(`(^|\\n)${escaped}(\\r?\\n|$)`).test(gitignore);
  });

  if (missing.length > 0) {
    addFailure(`Release package sensitive-path ignore rules are missing: ${missing.join(', ')}`);
  } else {
    addPass('Release package sensitive-path ignore rules are present.');
  }
}

function checkCodexSecurityScan() {
  const result = checkSecurityScanReports({
    repoRoot,
    configuredScanDir: process.env.CODEX_SECURITY_SCAN_DIR,
  });
  result.passes.forEach(addPass);
  result.failures.forEach(addFailure);
}

function checkTooling() {
  const workflowPath = '.github/workflows/php.yml';
  if (!exists(workflowPath)) {
    addFailure('GitHub Actions workflow is missing: .github/workflows/php.yml.');
  } else {
    const workflow = readText(workflowPath);
    const requiredWorkflowCommands = [
      'composer audit --no-interaction',
      'npm audit --audit-level=moderate',
      'composer test',
      'npm run verify:p0-guards',
      'npm run review:non-security',
      'npm run verify:release-status',
    ];
    const missingCommands = requiredWorkflowCommands.filter((command) => !workflow.includes(command));
    if (missingCommands.length > 0) {
      addFailure(`GitHub Actions workflow is missing release verification commands: ${missingCommands.join(', ')}.`);
    } else {
      addPass('GitHub Actions workflow includes dependency audits, PHPUnit, P0 guards, non-security review, and release-status contracts.');
    }
  }

  addWarning('Confirm GitHub Actions ran `composer audit --no-interaction` and `npm audit --audit-level=moderate` on the current PR head.');
}

function checkGitEnvironment() {
  if (exists('.git/index.lock')) {
    sleepMs(1500);
  }

  if (exists('.git/index.lock')) {
    addFailure('.git/index.lock exists after retry; local git index is not ready for normal commit/pull flows.');
  } else {
    addPass('.git/index.lock is absent.');
  }

  addWarning('Run `git status --short --branch` before release; local cleanliness is intentionally verified outside this script.');
}

checkEnvReadiness();
checkOpenAiEntrypoints();
checkLlmConnectivityAttestation();
checkDesignArtifacts();
checkBackups();
checkOtaCredentialRotationAttestation();
checkReleasePackageScope();
checkCodexSecurityScan();
checkTooling();
checkGitEnvironment();

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-readiness',
  status: failures.length > 0 ? 'failed' : 'passed',
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failures.length,
  },
  passes,
  warnings,
  failures,
};

if (process.env.RELEASE_READINESS_RESULT_FILE) {
  const outputPath = resolveOutputPath(process.env.RELEASE_READINESS_RESULT_FILE);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of warnings) {
  console.warn(`WARN: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release readiness summary: ${passes.length} passed, ${warnings.length} warnings, ${failures.length} failures.`);

if (failures.length > 0) {
  process.exit(1);
}
