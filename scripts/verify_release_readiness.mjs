import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDesignHandoff } from './lib/design_handoff_checks.mjs';
import { checkLlmConnectivityAttestation as checkLlmAttestationFile } from './lib/llm_attestation_checks.mjs';
import { checkOtaCredentialRelease } from './lib/ota_credential_checks.mjs';
import { checkProductionEnvFile } from './lib/release_env_checks.mjs';
import { checkSecurityScanReports } from './lib/security_scan_checks.mjs';
import { safeJsonParseErrorCode } from './lib/safe_json_parse_error.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const pendingExternalStateAllowed = process.env.RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE === '1';

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

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function evidencePath(fileName) {
  return path.join(releaseEvidenceDir, fileName);
}

function existingEvidenceOrRepo(evidenceFileName, repoRelativeFallback) {
  const candidate = evidencePath(evidenceFileName);
  if (fs.existsSync(candidate)) {
    return candidate;
  }
  if (fs.existsSync(resolveInputPath(repoRelativeFallback))) {
    return repoRelativeFallback;
  }
  return candidate;
}

function latestExistingEvidencePath(fileNames) {
  const candidates = [];
  for (const fileName of fileNames) {
    const candidate = evidencePath(fileName);
    if (!fs.existsSync(candidate)) {
      continue;
    }

    let generatedAtTime = 0;
    const stat = fs.statSync(candidate);
    try {
      const parsed = JSON.parse(fs.readFileSync(candidate, 'utf8'));
      generatedAtTime = Date.parse(String(parsed.generated_at || ''));
    } catch {
      generatedAtTime = 0;
    }
    candidates.push({
      path: candidate,
      timestamp: Number.isFinite(generatedAtTime) ? generatedAtTime : stat.mtimeMs,
      mtimeMs: stat.mtimeMs,
    });
  }

  candidates.sort((left, right) => {
    if (right.timestamp !== left.timestamp) {
      return right.timestamp - left.timestamp;
    }
    return right.mtimeMs - left.mtimeMs;
  });
  return candidates[0]?.path || '';
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

function readJsonFile(filePath) {
  return JSON.parse(fs.readFileSync(resolveInputPath(filePath), 'utf8'));
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function summarizeExternalStateFailures(result) {
  if (!Array.isArray(result?.failures) || result.failures.length === 0) {
    return '';
  }
  const normalizedFailures = result.failures
    .map((failure) => String(failure).trim().replace(/\.+$/, ''))
    .filter(Boolean);
  return normalizedFailures.length > 0 ? `: ${normalizedFailures.join(' | ')}` : '';
}

function summarizeReleasePrCandidateFailures(result) {
  if (!Array.isArray(result?.failures) || result.failures.length === 0) {
    return '';
  }
  const normalizedFailures = result.failures
    .map((failure) => String(failure).trim().replace(/\.+$/, ''))
    .filter(Boolean);
  return normalizedFailures.length > 0 ? `: ${normalizedFailures.join(' | ')}` : '';
}

function summarizeStagedScopeFailures(result) {
  if (!Array.isArray(result?.failures) || result.failures.length === 0) {
    return '';
  }
  const normalizedFailures = result.failures
    .map((failure) => String(failure).trim().replace(/\.+$/, ''))
    .filter(Boolean);
  return normalizedFailures.length > 0 ? `: ${normalizedFailures.join(' | ')}` : '';
}

function sleepMs(milliseconds) {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds);
}

function checkEnvReadiness() {
  const envFile = process.env.RELEASE_ENV_FILE || evidencePath('production.env');
  const result = checkProductionEnvFile({
    repoRoot,
    envFile,
    requireOutsideRepo: true,
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
  const attestationPath = process.env.LLM_CONNECTIVITY_ATTESTATION_FILE
    || existingEvidenceOrRepo('llm-attestation.json', 'docs/llm_connectivity_attestation.json');
  const result = checkLlmAttestationFile({ repoRoot, attestationPath });
  result.passes.forEach(addPass);
  result.failures.forEach(addFailure);
}

function checkDesignArtifacts() {
  const manifestPath = process.env.DESIGN_HANDOFF_MANIFEST_FILE
    || existingEvidenceOrRepo('design_handoff_manifest.json', 'docs/design_handoff_manifest.json');
  const result = checkDesignHandoff({
    repoRoot,
    manifestPath,
    requireOutsideRepo: Boolean(process.env.DESIGN_HANDOFF_MANIFEST_FILE) || manifestPath !== 'docs/design_handoff_manifest.json',
  });
  result.passes.forEach(addPass);
  result.warnings.forEach(addWarning);
  result.failures.forEach(addFailure);
}

function checkOtaCredentialReadiness() {
  const attestationPath = process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE
    || existingEvidenceOrRepo('ota_credential_rotation_attestation.json', 'docs/ota_credential_rotation_attestation.json');
  const result = checkOtaCredentialRelease({
    repoRoot,
    attestationPath,
    requireOutsideRepo: Boolean(process.env.OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE) || attestationPath !== 'docs/ota_credential_rotation_attestation.json',
  });
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
    '/storage/db_backups/',
    'hotelx_dump.sql',
    '*_dump.sql',
    '*_backup*.sql',
    '/storage/meituan_profile_*/',
    '/storage/ctrip_profile_*/',
    'reports/ctrip_capture_assets/',
    'reports/meituan_capture_assets/',
    'reports/ctrip_browser_capture_*.json',
    'reports/meituan_browser_capture_*.json',
    'reports/ctrip_capture_summary.json',
    'reports/p0_traffic_*.json',
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
      'npm run review:functional-readiness',
      'npm run review:release-issues',
      'npm run verify:release-evidence-intake',
      'npm run verify:release-readiness-contract',
      'npm run review:non-security',
      'npm run review:report-security-finance',
      'npm run verify:release-status',
    ];
    const missingCommands = requiredWorkflowCommands.filter((command) => !workflow.includes(command));
    if (missingCommands.length > 0) {
      addFailure(`GitHub Actions workflow is missing release verification commands: ${missingCommands.join(', ')}.`);
    } else {
      addPass('GitHub Actions workflow includes dependency audits, PHPUnit, P0 guards, functional readiness, release issue register, release evidence intake behavior guard, release readiness behavior guard, non-security review, report/security/finance regression review, and release-status contracts.');
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

function checkReleasePrCandidateResult() {
  const resultPath = process.env.RELEASE_PR_CANDIDATES_RESULT_FILE
    || latestExistingEvidencePath([
      'release-pr-candidates-result.json',
      'release-pr-candidates-current-result.json',
    ]);
  if (!resultPath.trim()) {
    addWarning('Release PR candidate result was not provided. Run `npm run review:release-pr-candidates` before final handoff; if multiple viable PRs exist, set RELEASE_PR_NUMBER and rerun the candidate review.');
    return;
  }
  if (isPathInsideRepo(resultPath)) {
    addFailure(`Release PR candidate result must be stored outside the repository in a controlled evidence directory: ${resultPath}.`);
    return;
  }

  let result = null;
  let stat = null;
  try {
    const resolvedPath = resolveInputPath(resultPath);
    stat = fs.statSync(resolvedPath);
    result = readJsonFile(resultPath);
  } catch (error) {
    addFailure(`Release PR candidate result is not readable JSON (${safeJsonParseErrorCode(error)}).`);
    return;
  }

  if (Date.now() - stat.mtimeMs > 24 * 60 * 60 * 1000) {
    addFailure(`Release PR candidate result is stale: ${resultPath}. Rerun npm run review:release-pr-candidates before release-readiness.`);
    return;
  }
  if (result.command !== 'npm run review:release-pr-candidates') {
    addFailure('Release PR candidate result command must be npm run review:release-pr-candidates.');
    return;
  }
  if (result.allow_draft_candidate === true || result.candidate_policy === 'allow_draft_for_ready_transition') {
    addFailure('Release PR candidate result was generated in draft-ready transition mode. Rerun npm run review:release-pr-candidates without RELEASE_PR_CANDIDATES_ALLOW_DRAFT after the final PR is ready.');
    return;
  }

  const generatedAtTime = Date.parse(String(result.generated_at || ''));
  if (!Number.isFinite(generatedAtTime)) {
    addFailure('Release PR candidate result generated_at must be a real ISO timestamp.');
    return;
  }
  if (Date.now() - generatedAtTime > 24 * 60 * 60 * 1000 || generatedAtTime > Date.now() + 5 * 60 * 1000) {
    addFailure(`Release PR candidate result generated_at is outside the accepted 24-hour final handoff window: ${result.generated_at}.`);
    return;
  }

  const failureCount = Number(result.summary?.failures ?? 0);
  if (result.status !== 'passed' || failureCount !== 0) {
    addFailure(`Release PR candidate gate has not passed: ${result.status || 'unknown'} (${failureCount} failures)${summarizeReleasePrCandidateFailures(result)}.`);
    return;
  }

  const selectedPr = String(result.selected_release_pr_number || '').trim();
  if (!/^\d+$/.test(selectedPr)) {
    addFailure('Release PR candidate result passed but did not record selected_release_pr_number.');
    return;
  }
  const selectedCandidate = Array.isArray(result.candidates)
    ? result.candidates.find((candidate) => String(candidate?.number ?? '') === selectedPr)
    : null;
  const selectedHeadRefOid = String(selectedCandidate?.headRefOid || '').trim();
  if (!/^[a-f0-9]{40}$/i.test(selectedHeadRefOid)) {
    addFailure('Release PR candidate result passed but did not record the selected PR headRefOid.');
    return;
  }
  addPass(`Release PR candidate result selected final PR #${selectedPr}.`);
  return { selectedPr, selectedHeadRefOid };
}

function checkReleasePrCandidateResultForFailedExternalState() {
  checkReleasePrCandidateResult();
}

function checkReleaseStagedScopeResult() {
  const resultPath = process.env.RELEASE_STAGED_SCOPE_RESULT_FILE
    || latestExistingEvidencePath([
      'release-staged-scope-result.json',
      'release-staged-scope-current-result.json',
    ]);
  if (!resultPath.trim()) {
    addFailure('Release staged-scope result was not provided. Run `npm run review:release-staged-scope` before final release-readiness.');
    return;
  }
  if (isPathInsideRepo(resultPath)) {
    addFailure(`Release staged-scope result must be stored outside the repository in a controlled evidence directory: ${resultPath}.`);
    return;
  }

  let result = null;
  let stat = null;
  try {
    const resolvedPath = resolveInputPath(resultPath);
    stat = fs.statSync(resolvedPath);
    result = readJsonFile(resultPath);
  } catch (error) {
    addFailure(`Release staged-scope result is not readable JSON (${safeJsonParseErrorCode(error)}).`);
    return;
  }

  if (Date.now() - stat.mtimeMs > 24 * 60 * 60 * 1000) {
    addFailure(`Release staged-scope result is stale: ${resultPath}. Rerun npm run review:release-staged-scope before release-readiness.`);
    return;
  }
  if (result.command !== 'npm run review:release-staged-scope') {
    addFailure('Release staged-scope result command must be npm run review:release-staged-scope.');
    return;
  }

  const generatedAtTime = Date.parse(String(result.generated_at || ''));
  if (!Number.isFinite(generatedAtTime)) {
    addFailure('Release staged-scope result generated_at must be a real ISO timestamp.');
    return;
  }
  if (Date.now() - generatedAtTime > 24 * 60 * 60 * 1000 || generatedAtTime > Date.now() + 5 * 60 * 1000) {
    addFailure(`Release staged-scope result generated_at is outside the accepted 24-hour final handoff window: ${result.generated_at}.`);
    return;
  }

  const failureCount = Number(result.summary?.failures ?? 0);
  if (result.status !== 'passed' || failureCount !== 0) {
    addFailure(`Release staged-scope gate has not passed: ${result.status || 'unknown'} (${failureCount} failures)${summarizeStagedScopeFailures(result)}.`);
    return;
  }
  if (result.does_not_close_release_readiness !== true) {
    addFailure('Release staged-scope result must explicitly record does_not_close_release_readiness=true.');
    return;
  }

  const buckets = result.buckets;
  const requiredBuckets = [
    'candidate_release_scope',
    'needs_explicit_operator_decision',
    'must_remain_local_by_default',
  ];
  if (!buckets || typeof buckets !== 'object') {
    addFailure('Release staged-scope result must include bucketed staged entries.');
    return;
  }
  for (const bucketName of requiredBuckets) {
    if (!Array.isArray(buckets[bucketName])) {
      addFailure(`Release staged-scope result bucket ${bucketName} must be an array.`);
      return;
    }
  }
  if (buckets.must_remain_local_by_default.length > 0) {
    addFailure('Release staged-scope result contains runtime/local staged files that must remain local by default.');
    return;
  }

  const stagedEntryCount = Number(result.summary?.staged_entries ?? result.staged_entries?.length ?? 0);
  addPass(`Release staged-scope result has no forbidden staged files (${Number.isFinite(stagedEntryCount) ? stagedEntryCount : 0} staged entries reviewed).`);
  if (result.allow_operator_decision === true && buckets.needs_explicit_operator_decision.length > 0) {
    addWarning('Release staged-scope passed with explicit operator-decision entries; preserve that decision in the final PR handoff.');
  }
  if (Array.isArray(result.warnings)) {
    result.warnings.forEach((warning) => addWarning(`Release staged-scope warning: ${warning}`));
  }
}

function checkExternalStateResult() {
  if (pendingExternalStateAllowed) {
    addWarning('Release external-state gate is intentionally pending for pre-ready handoff; rerun review:release-readiness after PR ready with RELEASE_EXTERNAL_STATE_RESULT_FILE.');
    return;
  }

  const resultPath = process.env.RELEASE_EXTERNAL_STATE_RESULT_FILE
    || latestExistingEvidencePath([
      'release-external-state-result.json',
      'release-external-state-current-result.json',
    ]);
  if (!resultPath.trim()) {
    addFailure('Release external-state result was not provided. Run `npm run review:release-external-state` with RELEASE_EXTERNAL_STATE_RESULT_FILE before final release-readiness.');
    return;
  }
  if (isPathInsideRepo(resultPath)) {
    addFailure(`Release external-state result must be stored outside the repository in a controlled evidence directory: ${resultPath}.`);
    return;
  }

  let result = null;
  let stat = null;
  try {
    const resolvedPath = resolveInputPath(resultPath);
    stat = fs.statSync(resolvedPath);
    result = readJsonFile(resultPath);
  } catch (error) {
    addFailure(`Release external-state result is not readable JSON (${safeJsonParseErrorCode(error)}).`);
    return;
  }

  if (Date.now() - stat.mtimeMs > 24 * 60 * 60 * 1000) {
    addFailure(`Release external-state result is stale: ${resultPath}. Rerun npm run review:release-external-state before release-readiness.`);
    return;
  }

  if (result.command !== 'npm run review:release-external-state') {
    addFailure('Release external-state result command must be npm run review:release-external-state.');
    return;
  }

  const generatedAtTime = Date.parse(String(result.generated_at || ''));
  if (!Number.isFinite(generatedAtTime)) {
    addFailure('Release external-state result generated_at must be a real ISO timestamp.');
    return;
  }
  if (Date.now() - generatedAtTime > 24 * 60 * 60 * 1000 || generatedAtTime > Date.now() + 5 * 60 * 1000) {
    addFailure(`Release external-state result generated_at is outside the accepted 24-hour final handoff window: ${result.generated_at}.`);
    return;
  }

  const failureCount = Number(result.summary?.failures ?? 0);
  if (result.status !== 'passed' || failureCount !== 0) {
    checkReleasePrCandidateResultForFailedExternalState();
    addFailure(`Release external-state gate has not passed: ${result.status || 'unknown'} (${failureCount} failures)${summarizeExternalStateFailures(result)}.`);
    return;
  }

  const passText = Array.isArray(result.passes) ? result.passes.join('\n') : '';
  const expectedPrNumber = String(process.env.RELEASE_PR_NUMBER || result.expected_release_pr_number || '').trim();
  if (!/^\d+$/.test(expectedPrNumber)) {
    addFailure('Release external-state result did not record a configured final PR number. Set RELEASE_PR_NUMBER and rerun npm run review:release-external-state before final release-readiness.');
    return;
  }
  if (process.env.RELEASE_PR_NUMBER && result.expected_release_pr_number && String(process.env.RELEASE_PR_NUMBER).trim() !== String(result.expected_release_pr_number).trim()) {
    addFailure(`Release external-state result is for PR #${result.expected_release_pr_number}, but RELEASE_PR_NUMBER is ${process.env.RELEASE_PR_NUMBER}.`);
    return;
  }
  const failureCountBeforePrCandidateCheck = failures.length;
  const prCandidateResult = checkReleasePrCandidateResult();
  if (!prCandidateResult) {
    if (failures.length === failureCountBeforePrCandidateCheck) {
      addFailure('Release PR candidate result must pass before a passed external-state result can close final release-readiness.');
    }
    return;
  }
  if (prCandidateResult.selectedPr !== expectedPrNumber) {
    addFailure(`Release external-state PR #${expectedPrNumber} does not match release PR candidate result #${prCandidateResult.selectedPr}. Rerun npm run review:release-pr-candidates and npm run review:release-external-state against the same final PR.`);
    return;
  }
  addPass(`Release PR candidate result matches external-state final PR #${expectedPrNumber}.`);

  const externalStateHeadSha = String(result.expected_release_pr_head_sha || '').trim();
  if (!/^[a-f0-9]{40}$/i.test(externalStateHeadSha)) {
    addFailure('Release external-state result did not record expected_release_pr_head_sha. Rerun npm run review:release-external-state on the selected final PR.');
    return;
  }
  if (externalStateHeadSha.toLowerCase() !== prCandidateResult.selectedHeadRefOid.toLowerCase()) {
    addFailure(`Release external-state PR head ${externalStateHeadSha} does not match release PR candidate head ${prCandidateResult.selectedHeadRefOid}. Rerun npm run review:release-pr-candidates and npm run review:release-external-state on the same PR head.`);
    return;
  }
  addPass(`Release PR candidate head matches external-state head ${externalStateHeadSha}.`);

  const externalStateLocalHeadSha = String(result.expected_local_head_sha || '').trim();
  if (!/^[a-f0-9]{40}$/i.test(externalStateLocalHeadSha)) {
    addFailure('Release external-state result did not record expected_local_head_sha. Rerun npm run review:release-external-state from the final release worktree.');
    return;
  }
  if (externalStateLocalHeadSha.toLowerCase() !== externalStateHeadSha.toLowerCase()) {
    addFailure(`Release external-state local HEAD ${externalStateLocalHeadSha} does not match release PR head ${externalStateHeadSha}. Check out the final release PR head or rerun external-state evidence from the matching release worktree before release-readiness.`);
    return;
  }
  addPass(`Release external-state local HEAD matches PR head ${externalStateHeadSha}.`);

  const expectedPrPattern = escapeRegExp(expectedPrNumber);
  const requiredPassedEvidence = [
    ['database/backups has no git-tracked files', /database\/backups has no git-tracked files/i],
    ['.git/index.lock is absent', /\.git\/index\.lock is absent/i],
    ['local worktree is clean', /local worktree is clean/i],
    ['local HEAD matches release PR head', /local HEAD matches release PR head/i],
    [`release PR #${expectedPrNumber} is configured`, new RegExp(`PR #${expectedPrPattern} is the configured release PR\\.`, 'i')],
    [`release PR #${expectedPrNumber} is open`, new RegExp(`PR #${expectedPrPattern} is open\\.`, 'i')],
    [`release PR #${expectedPrNumber} is not draft`, new RegExp(`PR #${expectedPrPattern} is not draft\\.`, 'i')],
    [`release PR #${expectedPrNumber} is mergeable`, new RegExp(`PR #${expectedPrPattern} is mergeable`, 'i')],
    [`release PR #${expectedPrNumber} checks are green`, new RegExp(`PR #${expectedPrPattern} status checks are all green`, 'i')],
    ['release PR head sha is recorded', /PR head sha is recorded/i],
  ];
  const missingEvidence = requiredPassedEvidence
    .filter(([, pattern]) => !pattern.test(passText))
    .map(([label]) => label);
  if (missingEvidence.length > 0) {
    addFailure(`Release external-state result is missing required passed evidence: ${missingEvidence.join(', ')}.`);
    return;
  }

  addPass(`Release external-state result is current and proves clean local state plus open, non-draft, mergeable, green final PR #${expectedPrNumber}.`);
}

checkEnvReadiness();
checkOpenAiEntrypoints();
checkLlmConnectivityAttestation();
checkDesignArtifacts();
checkOtaCredentialReadiness();
checkReleasePackageScope();
checkCodexSecurityScan();
checkTooling();
checkGitEnvironment();
checkReleaseStagedScopeResult();
checkExternalStateResult();

const resultOutputPath = process.env.RELEASE_READINESS_RESULT_FILE
  ? resolveOutputPath(process.env.RELEASE_READINESS_RESULT_FILE)
  : evidencePath('release-readiness-result.json');
if (isPathInsideRepo(resultOutputPath)) {
  addFailure(`Release readiness result output must be stored outside the repository in a controlled evidence directory: ${resultOutputPath}.`);
}

const resultStatus = failures.length > 0
  ? 'failed'
  : (pendingExternalStateAllowed ? 'pre_ready_pending_external_state' : 'passed');

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-readiness',
  mode: pendingExternalStateAllowed ? 'pre_ready' : 'final',
  final_release_ready: resultStatus === 'passed',
  status: resultStatus,
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failures.length,
  },
  passes,
  warnings,
  failures,
};

if (!isPathInsideRepo(resultOutputPath)) {
  fs.mkdirSync(path.dirname(resultOutputPath), { recursive: true });
  fs.writeFileSync(resultOutputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
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
