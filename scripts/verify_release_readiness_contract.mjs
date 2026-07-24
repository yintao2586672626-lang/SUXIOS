import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'release-readiness-contract-'));
const readinessScript = path.join(repoRoot, 'scripts', 'verify_release_readiness.mjs');

const passes = [];
const failures = [];

const candidateHead = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const externalStateHead = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const localHeadMismatch = 'cccccccccccccccccccccccccccccccccccccccc';
const releasePrNumber = '123';

function writeJson(filePath, payload) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

function writeText(filePath, content) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, content, 'utf8');
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, ''));
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

function nowIso() {
  return new Date().toISOString();
}

function validDesignManifest() {
  const reviewDate = todayDateOnly();
  return {
    owner: 'Release Design Lead',
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
    notes: 'Controlled temporary verifier manifest with no release-ready claim.',
  };
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
      database_backups_action: 'deleted',
      paths_reviewed: ['database/backups'],
      git_tracking_check: `git ls-files database/backups returned no tracked files on ${reviewDate}`,
      release_readiness_check: `review:release-ota-credentials and review:release-readiness rerun recorded on ${reviewDate}`,
    },
    notes: 'Controlled temporary verifier attestation with redacted references only.',
  };
}

function validLlmAttestation() {
  return {
    reviewed_at: '2024-01-01',
    reviewer: 'Release AI Reviewer',
    environment: 'production',
    provider: 'openai',
    model_key: 'production-default',
    model_name: 'gpt-release-verifier',
    base_url: 'https://api.openai.com/v1',
    ai_model_config_enabled: true,
    ai_config_secret_checked: true,
    redaction_checked: true,
    request: {
      entrypoint: 'LlmClient',
      purpose: 'Production connectivity smoke test',
      prompt_summary: 'Short non-sensitive connectivity prompt',
    },
    result: {
      status: 'passed',
      response_status: 200,
      completed_at: '2024-01-01T00:00:00Z',
      latency_ms: 100,
    },
    evidence_ref: 'release-llm-connectivity-record-2024-01-01',
    notes: 'Controlled temporary verifier attestation without provider secrets.',
  };
}

function writeReadinessFixture(evidenceDir, options = {}) {
  const candidateHeadValue = options.candidateHead || candidateHead;
  const externalStateHeadValue = options.externalStateHead || externalStateHead;
  const localHeadValue = options.localHead || externalStateHeadValue;
  const localHeadMatchesPr = localHeadValue.toLowerCase() === externalStateHeadValue.toLowerCase();
  writeText(path.join(evidenceDir, 'production.env'), [
    'APP_DEBUG=false',
    'APP_TRACE=false',
    'AI_CONFIG_SECRET=12345678901234567890123456789012',
    'DB_HOST=prod-db.internal',
    'DB_NAME=hotelx_prod',
    'DB_USER=hotel_app',
    'DB_PASS=nonempty-production-password',
    '',
  ].join('\n'));
  writeJson(path.join(evidenceDir, 'llm-attestation.json'), validLlmAttestation());
  writeJson(path.join(evidenceDir, 'design_handoff_manifest.json'), validDesignManifest());
  writeJson(path.join(evidenceDir, 'ota_credential_rotation_attestation.json'), validOtaAttestation());
  writeJson(path.join(evidenceDir, 'release-staged-scope-result.json'), {
    schema_version: 1,
    generated_at: nowIso(),
    command: 'npm run review:release-staged-scope',
    status: 'passed',
    does_not_close_release_readiness: true,
    allow_operator_decision: false,
    summary: {
      passed: 3,
      warnings: 0,
      failures: 0,
      staged_entries: 0,
    },
    warnings: [],
    failures: [],
    staged_entries: [],
    buckets: {
      candidate_release_scope: [],
      needs_explicit_operator_decision: [],
      must_remain_local_by_default: [],
    },
  });
  writeJson(path.join(evidenceDir, 'release-pr-candidates-result.json'), {
    schema_version: 1,
    generated_at: nowIso(),
    command: 'npm run review:release-pr-candidates',
    status: 'passed',
    base_ref: 'main',
    head_ref: null,
    allow_draft_candidate: false,
    candidate_policy: 'final_non_draft_release',
    selected_release_pr_number: Number(releasePrNumber),
    summary: {
      passed: 1,
      warnings: 0,
      failures: 0,
      candidates: 1,
      viable_candidates: 1,
    },
    passes: [`Selected release PR candidate #${releasePrNumber}.`],
    warnings: [],
    failures: [],
    candidates: [
      {
        number: Number(releasePrNumber),
        state: 'OPEN',
        isDraft: false,
        mergeStateStatus: 'CLEAN',
        headRefOid: candidateHeadValue,
      },
    ],
  });
  writeJson(path.join(evidenceDir, 'release-external-state-result.json'), {
    schema_version: 1,
    generated_at: nowIso(),
    command: 'npm run review:release-external-state',
    expected_release_pr_number: Number(releasePrNumber),
    expected_local_head_sha: localHeadValue,
    expected_release_pr_head_sha: externalStateHeadValue,
    status: 'passed',
    summary: {
      passed: localHeadMatchesPr ? 11 : 10,
      warnings: 0,
      failures: 0,
    },
    passes: [
      'database/backups has no git-tracked files.',
      '.git/index.lock is absent in external evidence.',
      'local worktree is clean.',
      `PR #${releasePrNumber} is the configured release PR.`,
      `PR #${releasePrNumber} is open.`,
      `PR #${releasePrNumber} is not draft.`,
      `PR #${releasePrNumber} is mergeable (CLEAN).`,
      `PR #${releasePrNumber} status checks are all green.`,
      'PR head sha is recorded.',
      `local HEAD sha is recorded: ${localHeadValue}.`,
      ...(localHeadMatchesPr ? [`local HEAD matches release PR head ${externalStateHeadValue}.`] : []),
    ],
    warnings: [],
    failures: [],
  });
}

function runReadiness(evidenceDir) {
  const resultPath = path.join(evidenceDir, 'release-readiness-result.json');
  const env = { ...process.env };
  for (const key of [
    'RELEASE_ENV_FILE',
    'LLM_CONNECTIVITY_ATTESTATION_FILE',
    'DESIGN_HANDOFF_MANIFEST_FILE',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'RELEASE_STAGED_SCOPE_RESULT_FILE',
    'RELEASE_PR_CANDIDATES_RESULT_FILE',
    'RELEASE_EXTERNAL_STATE_RESULT_FILE',
    'RELEASE_READINESS_RESULT_FILE',
    'RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE',
  ]) {
    delete env[key];
  }

  return spawnSync(process.execPath, [readinessScript], {
    cwd: repoRoot,
    env: {
      ...env,
      RELEASE_EVIDENCE_DIR: evidenceDir,
      RELEASE_PR_NUMBER: releasePrNumber,
      RELEASE_READINESS_RESULT_FILE: resultPath,
    },
    encoding: 'utf8',
  });
}

function verifyPrHeadMismatchGuard() {
  const evidenceDir = path.join(tempRoot, 'head-mismatch');
  writeReadinessFixture(evidenceDir);
  const result = runReadiness(evidenceDir);
  const resultPath = path.join(evidenceDir, 'release-readiness-result.json');
  const combined = `${result.stdout || ''}\n${result.stderr || ''}`;

  expect(result.status !== 0, 'release-readiness rejects matching PR numbers when candidate and external-state heads differ');
  expect(fs.existsSync(resultPath), 'release-readiness writes a controlled result for PR head mismatch');
  expect(/Release external-state PR head b{40} does not match release PR candidate head a{40}/.test(combined), 'release-readiness explains final PR head mismatch');
  if (fs.existsSync(resultPath)) {
    const parsed = readJson(resultPath);
    const failureText = Array.isArray(parsed.failures) ? parsed.failures.join('\n') : '';
    const passText = Array.isArray(parsed.passes) ? parsed.passes.join('\n') : '';
    expect(parsed.final_release_ready === false, 'PR head mismatch result does not claim final_release_ready');
    expect(/Release external-state PR head b{40} does not match release PR candidate head a{40}/.test(failureText), 'PR head mismatch is recorded in the readiness result JSON');
    expect(/Release PR candidate result matches external-state final PR #123/.test(passText), 'PR number match is distinguished from PR head mismatch');
    expect(!/Design handoff manifest was not found|OTA credential rotation attestation was not found|Production env file was not found|Production LLM connectivity attestation was not found/.test(failureText), 'PR head mismatch fixture closes unrelated evidence inputs before testing head consistency');
  }
}

function verifyLocalHeadMismatchGuard() {
  const evidenceDir = path.join(tempRoot, 'local-head-mismatch');
  writeReadinessFixture(evidenceDir, {
    candidateHead,
    externalStateHead: candidateHead,
    localHead: localHeadMismatch,
  });
  const result = runReadiness(evidenceDir);
  const resultPath = path.join(evidenceDir, 'release-readiness-result.json');
  const combined = `${result.stdout || ''}\n${result.stderr || ''}`;

  expect(result.status !== 0, 'release-readiness rejects matching PR heads when local HEAD differs');
  expect(fs.existsSync(resultPath), 'release-readiness writes a controlled result for local HEAD mismatch');
  expect(/Release external-state local HEAD c{40} does not match release PR head a{40}/.test(combined), 'release-readiness explains local HEAD mismatch');
  if (fs.existsSync(resultPath)) {
    const parsed = readJson(resultPath);
    const failureText = Array.isArray(parsed.failures) ? parsed.failures.join('\n') : '';
    const passText = Array.isArray(parsed.passes) ? parsed.passes.join('\n') : '';
    expect(parsed.final_release_ready === false, 'local HEAD mismatch result does not claim final_release_ready');
    expect(/Release external-state local HEAD c{40} does not match release PR head a{40}/.test(failureText), 'local HEAD mismatch is recorded in the readiness result JSON');
    expect(/Release PR candidate head matches external-state head a{40}/.test(passText), 'PR head match is distinguished from local HEAD mismatch');
  }
}

function verifyStaleDesignAndOtaEvidenceGuard() {
  const evidenceDir = path.join(tempRoot, 'stale-design-ota-evidence');
  writeReadinessFixture(evidenceDir, {
    candidateHead,
    externalStateHead: candidateHead,
    localHead: candidateHead,
  });
  const staleDesign = validDesignManifest();
  staleDesign.last_reviewed_at = '2024-01-01';
  staleDesign.source_review.evidence_ref = 'release-design-audit-record-2024-01-01';
  writeJson(path.join(evidenceDir, 'design_handoff_manifest.json'), staleDesign);
  const staleOta = validOtaAttestation();
  staleOta.reviewed_at = '2024-01-01';
  staleOta.platforms = staleOta.platforms.map((platform) => ({
    ...platform,
    evidence_ref: `secure-rotation-record-${platform.platform}-2024-01-01`,
  }));
  staleOta.backup_cleanup.git_tracking_check = 'git ls-files database/backups returned no tracked files on 2024-01-01';
  staleOta.backup_cleanup.release_readiness_check = 'review:release-ota-credentials and review:release-readiness rerun recorded on 2024-01-01';
  writeJson(path.join(evidenceDir, 'ota_credential_rotation_attestation.json'), staleOta);

  const result = runReadiness(evidenceDir);
  const resultPath = path.join(evidenceDir, 'release-readiness-result.json');
  const combined = `${result.stdout || ''}\n${result.stderr || ''}`;

  expect(result.status !== 0, 'release-readiness rejects stale design and OTA final evidence dates');
  expect(fs.existsSync(resultPath), 'release-readiness writes a controlled result for stale design and OTA evidence');
  expect(/Design handoff manifest last_reviewed_at must be within the 30-day release evidence window/.test(combined), 'release-readiness explains stale design handoff evidence');
  expect(/OTA credential rotation attestation reviewed_at must be within the 30-day release evidence window/.test(combined), 'release-readiness explains stale OTA credential rotation evidence');
  if (fs.existsSync(resultPath)) {
    const parsed = readJson(resultPath);
    const failureText = Array.isArray(parsed.failures) ? parsed.failures.join('\n') : '';
    const passText = Array.isArray(parsed.passes) ? parsed.passes.join('\n') : '';
    expect(parsed.final_release_ready === false, 'stale design and OTA evidence result does not claim final_release_ready');
    expect(/Design handoff manifest last_reviewed_at must be within the 30-day release evidence window/.test(failureText), 'stale design handoff evidence is recorded in the readiness result JSON');
    expect(/OTA credential rotation attestation reviewed_at must be within the 30-day release evidence window/.test(failureText), 'stale OTA credential rotation evidence is recorded in the readiness result JSON');
    expect(/Release external-state local HEAD matches PR head a{40}/.test(passText), 'stale evidence fixture closes PR head consistency before testing evidence freshness');
  }
}

try {
  verifyPrHeadMismatchGuard();
  verifyLocalHeadMismatchGuard();
  verifyStaleDesignAndOtaEvidenceGuard();
} catch (error) {
  failures.push(`release readiness contract crashed: ${error.message}`);
}

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release readiness contract summary: ${passes.length} passed, ${failures.length} failures.`);

if (failures.length > 0) {
  console.error(`Temporary verifier directory retained for inspection: ${tempRoot}`);
  process.exit(1);
}

const tempParent = path.resolve(os.tmpdir());
const resolvedTempRoot = path.resolve(tempRoot);
if (resolvedTempRoot.startsWith(tempParent + path.sep) && path.basename(resolvedTempRoot).startsWith('release-readiness-contract-')) {
  fs.rmSync(resolvedTempRoot, { recursive: true, force: true });
}
