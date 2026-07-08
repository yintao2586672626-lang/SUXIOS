import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checkedAtPattern = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/;
const datePattern = /^\d{4}-\d{2}-\d{2}$/;

function checkedAtDate(value) {
  const text = String(value || '');
  return checkedAtPattern.test(text) ? text.slice(0, 10) : '';
}

const requiredScope = [
  '@github',
  '@openai-developers',
  '@codex-security',
  '@figma',
  '@canva',
];

const requiredDocs = [
  'docs/release_readiness_remaining_issues.md',
  'docs/release_blocker_close_plan.md',
  'docs/release_verification_command_matrix.md',
  'docs/release_functional_acceptance_matrix.md',
  'docs/release_issue_register.md',
  'docs/release_problem_report.zh-CN.md',
  'docs/release_evidence_bundle_intake.md',
  'docs/release_evidence_collection.zh-CN.md',
  'docs/formal_release_final_handoff.md',
  'docs/release_readiness_status.json',
  'docs/release_readiness_status.schema.json',
  'docs/deployment_env_checklist.md',
  'docs/design-tokens.release.json',
  'docs/design_handoff_manifest.example.json',
  'docs/release_figma_handoff_evidence.json',
  'docs/release_canva_handoff_evidence.json',
  'docs/release_github_handoff_evidence.json',
  'docs/ota_credential_rotation_checklist.md',
  'docs/ota_credential_rotation_attestation.example.json',
  'docs/codex_security_scan_authorization.md',
  'docs/codex_security_scan_manifest.example.json',
  'docs/ui-handoff/README.md',
  'docs/release_external_state_evidence.example.json',
  'docs/release_external_state_result.example.json',
  'docs/llm_connectivity_attestation.example.json',
  'docs/release_readiness_result.example.json',
  'scripts/prepare_release_evidence_drafts.mjs',
  'scripts/review_release_evidence_drafts.mjs',
  'scripts/promote_release_evidence_drafts.mjs',
  'scripts/create_release_ota_attestation.mjs',
  'scripts/verify_release_evidence_intake_contract.mjs',
  'scripts/verify_release_readiness_contract.mjs',
  'scripts/review_release_staged_scope.mjs',
  'scripts/collect_release_external_state.ps1',
  'scripts/report_release_evidence_gap_pack.mjs',
  'scripts/verify_release_evidence_gap_pack.mjs',
  'scripts/export_release_operator_intake_packet.mjs',
  'scripts/verify_release_operator_intake_packet.mjs',
  'scripts/review_release_pr_candidates.mjs',
  'scripts/create_worktree_quarantine_bundle.mjs',
  'scripts/refresh_release_current_evidence.ps1',
  'scripts/review_release_final_handoff.ps1',
  'scripts/continue_release_handoff.ps1',
  'scripts/mark_release_pr_ready.ps1',
  'scripts/verify_release_functional_readiness.mjs',
  'scripts/verify_release_issue_register.mjs',
  'scripts/verify_release_env.mjs',
  'scripts/lib/release_env_checks.mjs',
  'scripts/lib/release_worktree_scope.mjs',
  'scripts/verify_release_llm.mjs',
  'scripts/lib/llm_attestation_checks.mjs',
  'scripts/verify_release_design.mjs',
  'scripts/lib/design_handoff_checks.mjs',
  'scripts/verify_release_ota_credentials.mjs',
  'scripts/lib/ota_credential_checks.mjs',
  'scripts/verify_release_security_scan.mjs',
  'scripts/lib/security_scan_checks.mjs',
];

const requiredPackageScripts = [
  'review:release-readiness',
  'review:functional-readiness',
  'review:release-issues',
  'review:release-env',
  'review:release-llm',
  'review:release-design',
  'release:create-design-manifest',
  'release:create-ota-attestation',
  'verify:release-evidence-intake',
  'verify:release-readiness-contract',
  'review:release-ota-credentials',
  'review:release-security-scan',
  'review:release-external-state',
  'review:release-pr-candidates',
  'review:release-staged-scope',
  'prepare:release-evidence-drafts',
  'review:release-evidence-drafts',
  'promote:release-evidence-drafts',
  'report:release-evidence-gap-pack',
  'verify:release-evidence-gap-pack',
  'export:release-operator-intake',
  'verify:release-operator-intake',
  'export:worktree-quarantine',
  'review:release-final-handoff',
  'refresh:release-current-evidence',
  'release:continue-handoff',
  'release:mark-pr-ready',
  'collect:release-external-state',
  'review:release-external-state:local',
  'verify:release-status',
  'review:report-security-finance',
];

const requiredVerificationMatrixCommands = [
  'npm run review:release-env',
  'npm run review:release-llm',
  'npm run review:release-design',
  'npm run release:create-design-manifest',
  'npm run review:release-ota-credentials',
  'npm run release:create-ota-attestation',
  'npm run verify:release-evidence-intake',
  'npm run verify:release-readiness-contract',
  'npm run review:release-security-scan',
  'npm run review:release-pr-candidates',
  'npm run review:release-staged-scope',
  'npm run review:release-external-state',
  'npm run review:release-external-state:local',
  'npm run prepare:release-evidence-drafts',
  'npm run review:release-evidence-drafts',
  'npm run promote:release-evidence-drafts',
  'npm run report:release-evidence-gap-pack',
  'npm run verify:release-evidence-gap-pack',
  'npm run export:release-operator-intake',
  'npm run verify:release-operator-intake',
  'npm run review:release-final-handoff',
  'npm run release:continue-handoff',
  'npm run refresh:release-current-evidence',
  'npm run release:mark-pr-ready',
  'npm run review:release-readiness',
  'npm run review:functional-readiness',
  'npm run review:release-issues',
  'npm run verify:release-status',
  'npm run review:non-security',
  'npm run review:report-security-finance',
];

const requiredWorkflowCommands = [
  'composer audit --no-interaction',
  'npm audit --audit-level=moderate',
  'composer test',
  'npm run verify:p0-guards',
  'npm run review:functional-readiness',
  'npm run verify:release-evidence-intake',
  'npm run verify:release-readiness-contract',
  'npm run review:release-issues',
  'npm run review:non-security',
  'npm run review:report-security-finance',
  'npm run verify:release-status',
];

const requiredOpenFailurePatterns = [
  /figma|canva|design-token|DESIGN_HANDOFF_MANIFEST_FILE|design_handoff_manifest|design_handoff_manifest\.json/i,
  /OTA credential rotation|OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE/i,
  /external-state|review:release-external-state|local worktree|RELEASE_PR_NUMBER|final PR/i,
];

const requiredExternalStateFailurePatterns = [
  /worktree/i,
  /RELEASE_PR_NUMBER|final release PR/i,
];

const requiredLocalExternalStateScriptFragments = [
  'review_release_external_state_local.ps1',
];

const requiredDoNotClaimReadyPatterns = [
  /production env/i,
  /LLM|connectivity/i,
  /figma|canva|design-token/i,
  /OTA credential rotation/i,
  /Codex Security/i,
  /git state/i,
];

const requiredReportBlockerPatterns = [
  /Production env/i,
  /LLM_CONNECTIVITY_ATTESTATION_FILE|LLM connectivity/i,
  /Figma|Canva|DESIGN_HANDOFF_MANIFEST_FILE|design token/i,
  /standalone design-token files or screenshots are not sufficient/i,
  /OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE|OTA credential rotation/i,
  /CODEX_SECURITY_SCAN_DIR|Codex Security/i,
  /\.git\/index\.lock|Local Git state/i,
];

const requiredBlockerIds = [
  'production-env-missing',
  'llm-connectivity-attestation-missing',
  'design-handoff-missing',
  'ota-credential-rotation-attestation-missing',
  'codex-security-scan-missing',
  'local-git-state-open',
];

const closedBlockerIds = [
  'production-env-missing',
  'llm-connectivity-attestation-missing',
  'codex-security-scan-missing',
];

const requiredBlockerScopes = {
  'production-env-missing': ['@openai-developers'],
  'llm-connectivity-attestation-missing': ['@openai-developers'],
  'design-handoff-missing': ['@figma', '@canva'],
  'ota-credential-rotation-attestation-missing': ['@codex-security'],
  'codex-security-scan-missing': ['@codex-security'],
  'local-git-state-open': ['@github'],
};

const requiredSecurityScanPatterns = [
  /subagents/i,
  /Threat model/i,
  /Finding discovery/i,
  /Validation/i,
  /Attack-path analysis/i,
  /Markdown\s*\/\s*HTML final report/i,
  /scan_manifest\.json/i,
  /validation summary/i,
  /attack-path analysis report/i,
  /reviewed_surfaces\.md/i,
  /production configuration/i,
  /OTA credentials/i,
  /tenant isolation/i,
  /file import/i,
  /external HTTP/i,
];

const requiredDesignManifestKeys = [
  'owner',
  'last_reviewed_at',
  'figma_url',
  'canva_url',
  'brand_kit_url',
  'design_tokens_path',
  'covered_flows',
  'source_review',
  'open_issues',
];

const requiredExternalEvidenceKeys = [
  'reviewed_at',
  'reviewer',
  'target_release_pr_number',
  'commands',
];

const requiredSecurityScanManifestKeys = [
  'schema_version',
  'scan_mode',
  'target',
  'reviewed_at',
  'reviewer',
  'subagents_authorized',
  'phases',
  'artifacts',
  'final_report_validated',
  'report_html_rendered',
];

const requiredLlmAttestationKeys = [
  'reviewed_at',
  'reviewer',
  'environment',
  'provider',
  'model_key',
  'model_name',
  'base_url',
  'ai_model_config_enabled',
  'ai_config_secret_checked',
  'redaction_checked',
  'request',
  'result',
  'evidence_ref',
];

const requiredOtaAttestationKeys = [
  'reviewed_at',
  'reviewer',
  'redaction_checked',
  'platforms',
  'backup_cleanup',
];

const requiredReadinessResultKeys = [
  'schema_version',
  'generated_at',
  'command',
  'mode',
  'final_release_ready',
  'status',
  'summary',
  'passes',
  'warnings',
  'failures',
];

const requiredExternalStateResultKeys = [
  'schema_version',
  'generated_at',
  'command',
  'expected_release_pr_number',
  'expected_local_head_sha',
  'expected_release_pr_head_sha',
  'status',
  'summary',
  'passes',
  'warnings',
  'failures',
  'diagnostics',
];

const asciiReleaseDocs = [
  'docs/release_readiness_remaining_issues.md',
  'docs/release_blocker_close_plan.md',
  'docs/release_verification_command_matrix.md',
  'docs/release_functional_acceptance_matrix.md',
  'docs/release_issue_register.md',
  'docs/release_readiness_status.json',
  'docs/codex_security_scan_authorization.md',
  'docs/release_readiness_result.example.json',
  'docs/release_external_state_result.example.json',
];

const issues = [];
const passes = [];

function fail(message) {
  issues.push(message);
}

function pass(message) {
  passes.push(message);
}

function readText(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function readJson(relativePath) {
  try {
    return JSON.parse(readText(relativePath));
  } catch (error) {
    fail(`${relativePath} is not valid JSON: ${error.message}`);
    return null;
  }
}

function assertFileExists(relativePath) {
  const absolutePath = path.join(root, relativePath);
  if (!fs.existsSync(absolutePath)) {
    fail(`${relativePath} is missing`);
    return false;
  }
  pass(`${relativePath} exists`);
  return true;
}

function assertArrayContainsPatterns(values, patterns, label) {
  const joined = Array.isArray(values) ? values.join('\n') : '';
  let missing = false;
  for (const pattern of patterns) {
    if (!pattern.test(joined)) {
      fail(`${label} does not mention required blocker pattern ${pattern}`);
      missing = true;
    }
  }
  if (!missing) {
    pass(`${label} covers required blocker patterns`);
  }
}

function assertTextContainsPatterns(text, patterns, label) {
  let missing = false;
  for (const pattern of patterns) {
    if (!pattern.test(text)) {
      fail(`${label} does not mention required pattern ${pattern}`);
      missing = true;
    }
  }
  if (!missing) {
    pass(`${label} covers required patterns`);
  }
}

function assertExactStringArray(actual, expected, label) {
  if (!Array.isArray(actual)) {
    fail(`${label} must be an array`);
    return;
  }
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    fail(`${label} must be exactly ${JSON.stringify(expected)}; got ${JSON.stringify(actual)}`);
    return;
  }
  pass(`${label} matches contract`);
}

function assertAsciiText(relativePath) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    fail(`${relativePath} is missing`);
    return;
  }
  const text = readText(relativePath);
  const invalid = [...text].find((char) => char.charCodeAt(0) > 0x7f);
  if (invalid) {
    fail(`${relativePath} contains non-ASCII text; keep release status docs encoding-stable`);
    return;
  }
  pass(`${relativePath} is ASCII-only`);
}

for (const doc of requiredDocs) {
  assertFileExists(doc);
}

for (const doc of asciiReleaseDocs) {
  assertAsciiText(doc);
}

try {
  const gitignore = readText('.gitignore');
  if (!gitignore.includes('docs/release_external_state_evidence.local.json')) {
    fail('.gitignore must exclude local release external-state evidence output');
  } else {
    pass('.gitignore excludes local release external-state evidence output');
  }
} catch (error) {
  fail(`could not read .gitignore: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/collect_release_external_state.ps1'),
    [
      /target_release_pr_number/,
      /missing-release-pr-number/,
      /RELEASE_PR_NUMBER is required/,
      /function Invoke-NativeCommand/,
      /git.*ls-files.*database\/backups|database\/backups.*ls-files/s,
      /git.*status.*--short.*--branch|--short.*--branch/s,
      /git.*rev-parse.*HEAD|rev-parse.*HEAD/s,
      /gh.*pr.*view|pr.*view/s,
      /stderr\s*=\s*\$prView\.stderr/,
      /ConvertTo-Json/i,
    ],
    'scripts/collect_release_external_state.ps1',
  );
  assertTextContainsPatterns(
    readText('scripts/lib/release_worktree_scope.mjs'),
    [
      /gitStatusCategoryOrder/,
      /function normalizeReleasePath/,
      /function isReleaseLocalArtifactPath/,
      /function categorizeReleasePath/,
      /function releaseStagingBucket/,
      /function classifyReleaseWorktreeEntry/,
      /docs\/functional_acceptance_report\.zh-CN\.md/,
      /scripts\/create_worktree_quarantine_bundle\.mjs/,
      /release_env_checks\|llm_attestation_checks\|design_handoff_checks\|ota_credential_checks\|security_scan_checks\|release_worktree_scope/,
      /reports\//,
      /storage\//,
      /runtime\//,
      /output\//,
      /test-results\//,
      /database\/backups/,
      /docs\/release_external_state_evidence\.local\.json/,
      /release-staging-candidate-scope\.tsv/,
      /release-staging-needs-operator-decision\.tsv/,
      /release-staging-must-remain-local\.tsv/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
    ],
    'scripts/lib/release_worktree_scope.mjs canonical worktree scope classifier',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_external_state.mjs'),
    [
      /expected_release_pr_number/,
      /expected_local_head_sha/,
      /expected_release_pr_head_sha/,
      /resolvedReleasePrHeadSha/,
      /resolvedLocalHeadSha/,
      /function checkExpectedPrNumberSource/,
      /function checkGitHeadOutput/,
      /function checkLocalHeadMatchesPrHead/,
      /RELEASE_PR_NUMBER is required/,
      /expected release PR/,
      /configured release PR/,
      /function isWeakReviewer/,
      /function parseReviewTimestamp/,
      /function isPathInsideRepo/,
      /const diagnostics/,
      /git_status_changed_entries/,
      /git_status_changed_summary/,
      /release_worktree_scope\.mjs/,
      /categorizeReleasePath/,
      /gitStatusCategoryOrder/,
      /function summarizeGitStatusEntries/,
      /const releaseEvidenceDir/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function evidencePath/,
      /RELEASE_EXTERNAL_STATE_RESULT_FILE/,
      /release-external-state-result\.json/,
      /Release external-state result output must be stored outside the repository in a controlled evidence directory/,
      /reviewed_at must be a real ISO timestamp/,
      /accepted 24-hour final handoff window/,
      /reviewer must be a real accountable reviewer/,
      /commandEvidenceFailureMessage/,
      /stderr:/,
      /stdout:/,
      /categories:/,
    ],
    'scripts/verify_release_external_state.mjs release PR guard',
  );
  assertTextContainsPatterns(
    readText('scripts/review_release_pr_candidates.mjs'),
    [
      /RELEASE_PR_CANDIDATES_RESULT_FILE/,
      /release-pr-candidates-result\.json/,
      /RELEASE_PR_BASE_REF/,
      /RELEASE_PR_HEAD_REF/,
      /configuredReleasePrNumber/,
      /RELEASE_PR_NUMBER must be a numeric PR number/,
      /configured_release_pr_number/,
      /RELEASE_PR_CANDIDATES_GH_ATTEMPTS/,
      /RELEASE_GITHUB_HANDOFF_EVIDENCE_FILE/,
      /docs\/release_github_handoff_evidence\.json/,
      /function isPathInsideRepo/,
      /function readJsonIfExists/,
      /function timestampDate/,
      /function shanghaiTimestamp/,
      /function connectorEvidenceSummary/,
      /function connectorOpenPrDetail/,
      /function runWithAttempts/,
      /current_gh_pr_list/,
      /gh_pr_list_checked_at/,
      /gh_pr_list_open_pr_count/,
      /github_connector_evidence/,
      /github_connector_diagnostic/,
      /diagnostic-only/,
      /is_stale_for_current_review/,
      /mcp__codex_apps__github\._get_users_recent_prs_in_repo/,
      /open_pr_count=0/,
      /checked_at/,
      /gh_pr_list_attempts/,
      /gh pr list failed after/,
      /must be stored outside the repository/,
      /'pr',\s*'list'/,
      /'--state',\s*'open'/,
      /selected_release_pr_number/,
      /RELEASE_PR_CANDIDATES_ALLOW_DRAFT/,
      /allow_draft_candidate/,
      /candidate_policy/,
      /allow_draft_for_ready_transition/,
      /final_non_draft_release/,
      /Set RELEASE_PR_NUMBER/,
      /Selected configured release PR candidate/,
      /Configured release PR #/,
      /RELEASE_PR_NUMBER=.*is not in open PR candidates/,
      /No open release PR candidates found/,
      /current gh pr list checked_at/,
      /GitHub connector evidence/,
      /Multiple viable release PR candidates found/,
      /npm run review:release-external-state/,
    ],
    'scripts/review_release_pr_candidates.mjs guarded open PR candidate review',
  );
  assertTextContainsPatterns(
    readText('scripts/mark_release_pr_ready.ps1'),
    [
      /gh\s+@viewArgs/s,
      /state.*OPEN/s,
      /release-pr-ready-candidates-result\.json/,
      /RELEASE_PR_CANDIDATES_ALLOW_DRAFT/,
      /allow_draft_for_ready_transition/,
      /selected_release_pr_number/,
      /headRefOid/,
      /Assert-LocalHeadMatchesReleasePrReadyTarget/,
      /git rev-parse HEAD/,
      /Local HEAD .* does not match selected release PR/,
      /Configured release PR #\$ConfiguredPrNumber does not match selected release PR ready target/,
      /Set RELEASE_PR_NUMBER to the actual open final release PR/,
      /is already not draft/,
    ],
    'scripts/mark_release_pr_ready.ps1 guarded PR ready transition',
  );
  assertTextContainsPatterns(
    readText('scripts/review_release_final_handoff.ps1'),
    [
      /RELEASE_EXTERNAL_STATE_RESULT_FILE/,
      /RELEASE_PR_CANDIDATES_RESULT_FILE/,
      /release-pr-candidates-result\.json/,
      /RELEASE_STAGED_SCOPE_RESULT_FILE/,
      /release-staged-scope-result\.json/,
      /RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE/,
      /release PR candidates after PR ready/,
      /release staged scope after PR ready/,
      /release staged scope pre-ready/,
      /review:release-pr-candidates/,
      /review:release-staged-scope/,
      /collect external state after PR ready/,
      /release readiness pre-ready/,
      /release readiness/,
    ],
    'scripts/review_release_final_handoff.ps1 external-state readiness sequencing',
  );
  assertTextContainsPatterns(
    readText('scripts/continue_release_handoff.ps1'),
    [
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-result\.json/,
      /RELEASE_EVIDENCE_RESULT_FILE/,
      /release-pr-candidates-result\.json/,
      /RELEASE_PR_CANDIDATES_RESULT_FILE/,
      /release-staged-scope-result\.json/,
      /RELEASE_STAGED_SCOPE_RESULT_FILE/,
      /\$MarkPrReady -and \$AfterPrReady/,
      /Do not combine -MarkPrReady and -AfterPrReady/,
      /release handoff PR-ready continuation/,
      /function Resolve-SelectedReleasePrNumber/,
      /selected_release_pr_number/,
      /Selected RELEASE_PR_NUMBER from release-pr-candidates result/,
      /function Require-ReleasePrNumber/,
      /release external-state collection/,
      /Remove-Item Env:\\RELEASE_PR_NUMBER/,
      /if \(\$MarkPrReady\)[\s\S]*guarded PR ready transition[\s\S]*npm\.cmd run release:mark-pr-ready[\s\S]*else[\s\S]*collect PR #\$PrNumber external state/,
      /release-external-state-evidence\.json/,
      /RELEASE_EXTERNAL_STATE_FILE/,
      /release-external-state-result\.json/,
      /RELEASE_EXTERNAL_STATE_RESULT_FILE/,
      /npm\.cmd run review:release-evidence/,
      /npm\.cmd run review:release-pr-candidates/,
      /npm\.cmd run review:release-staged-scope/,
      /npm\.cmd run review:release-external-state/,
      /npm\.cmd run review:release-readiness/,
    ],
    'scripts/continue_release_handoff.ps1 controlled handoff evidence outputs',
  );
  assertTextContainsPatterns(
    readText('scripts/refresh_release_current_evidence.ps1'),
    [
      /release-evidence-current-result\.json/,
      /release-evidence-draft-review-current-result\.json/,
      /release-evidence-promotion-current-result\.json/,
      /release-readiness-current-result\.json/,
      /release-pr-candidates-current-result\.json/,
      /release-staged-scope-current-result\.json/,
      /worktree-quarantine-current/,
      /release-external-state-current-evidence\.json/,
      /release-external-state-current-result\.json/,
      /release-evidence-gap-pack-current\.json/,
      /release-evidence-gap-pack-current\.md/,
      /release-operator-intake-packet-current\.json/,
      /release-operator-intake-packet-current\.md/,
      /verify:release-evidence-intake/,
      /release evidence intake behavior contract/,
      /verify:release-readiness-contract/,
      /release readiness behavior contract/,
      /RELEASE_PR_CANDIDATES_RESULT_FILE/,
      /RELEASE_STAGED_SCOPE_RESULT_FILE/,
      /RELEASE_EVIDENCE_GAP_PACK_FILE/,
      /RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE/,
      /RELEASE_OPERATOR_INTAKE_SOURCE_FILE/,
      /RELEASE_OPERATOR_INTAKE_PACKET_FILE/,
      /RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE/,
      /RELEASE_EVIDENCE_DRAFT_REVIEW_RESULT_FILE/,
      /RELEASE_EVIDENCE_PROMOTION_RESULT_FILE/,
      /WORKTREE_QUARANTINE_DIR/,
      /WORKTREE_QUARANTINE_MANIFEST_FILE/,
      /review:release-evidence-drafts/,
      /promote:release-evidence-drafts/,
      /review:release-pr-candidates/,
      /review:release-staged-scope/,
      /export:worktree-quarantine/,
      /report:release-evidence-gap-pack/,
      /verify:release-evidence-gap-pack/,
      /export:release-operator-intake/,
      /verify:release-operator-intake/,
      /\$PrNumber\s*=\s*\$env:RELEASE_PR_NUMBER/,
      /Remove-Item Env:\\RELEASE_PR_NUMBER/,
      /RELEASE_PR_NUMBER: <not set>/,
      /release evidence intake behavior contract[\s\S]*release readiness behavior contract/,
      /release readiness behavior contract[\s\S]*release evidence current result/,
      /release external state current result[\s\S]*release readiness current result/,
      /release evidence draft review current result[\s\S]*release evidence draft promotion current result/,
      /release evidence draft promotion current result[\s\S]*release PR candidates current result/,
      /release PR candidates current result[\s\S]*release staged scope current result/,
      /release staged scope current result[\s\S]*worktree quarantine current result/,
      /worktree quarantine current result[\s\S]*collect release external state current evidence/,
      /release readiness current result[\s\S]*release evidence gap pack current result/,
      /release evidence gap pack current result[\s\S]*release evidence gap pack verification current result[\s\S]*release operator intake packet current result[\s\S]*release operator intake packet verification current result[\s\S]*release evidence gap pack parse check/,
      /release evidence gap pack parse check/,
      /operator_intake_packet/,
      /required_external_inputs/,
      /Release Operator Intake Packet/,
      /Current PR Handoff Evidence/,
      /Current PR connector open PR count/,
      /Current PR connector checked at/,
      /Connector open PR count/,
      /Connector checked at/,
      /\| Connector open PR count \|/,
      /Configured release PR number/,
      /Selected release PR head/,
      /External-state expected PR head/,
      /Final PR head match status/,
      /source_status\.release_readiness_status\.current_pr/,
      /current_pr_status\.connector_evidence/,
      /final_pr_head_status/,
      /configured_release_pr_number/,
      /docs\/release_github_handoff_evidence\.json/,
      /mcp__codex_apps__github\._get_users_recent_prs_in_repo/,
      /pull_requests_count/,
      /latest_release_draft_review_result/,
      /latest_release_promotion_result/,
      /does_not_close_release_readiness/,
      /ConvertFrom-Json/,
      /readiness_close_sequence/,
      /source_status\.local_worktree_close_plan/,
      /quarantine manifest path is not the current manifest file/,
      /quarantine changed_paths must match current changed_entries/,
      /changed_entries/,
      /categories/,
      /acceptance_commands/,
      /isolation_evidence/,
      /staging_plan/,
      /still_blocks_release/,
      /current_dirty_state_preserved_not_release_closure/,
      /blocked_until_clean_or_isolated/,
      /quarantine_bundle/,
      /stale_changed_path_mismatch/,
      /Remove-Item[\s\S]*-Recurse[\s\S]*-Force/,
      /Refusing to reset worktree quarantine outside evidence dir/,
      /git status --short --branch/,
      /local-git-state-open/,
      /worktree_close_plan/,
      /npm run review:release-staged-scope/,
      /Status: not release-ready/,
      /Do not treat this gap pack as release-ready evidence/,
      /staging\/isolation plan/,
      /Worktree Staging\/Isolation Summary/,
      /worktree_staging_summary/,
      /bucket_counts/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
    ],
    'scripts/refresh_release_current_evidence.ps1',
  );
  assertTextContainsPatterns(
    readText('scripts/report_release_evidence_gap_pack.mjs'),
    [
      /RELEASE_EVIDENCE_GAP_PACK_FILE/,
      /RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE/,
      /release-evidence-gap-pack\.json/,
      /release-evidence-gap-pack\.md/,
      /function latestExistingEvidencePath/,
      /function stringifyAsciiJson/,
      /charCodeAt\(0\)\.toString\(16\)/,
      /function renderGapPackMarkdown/,
      /function operatorIntakePacket/,
      /function renderOperatorInputMarkdown/,
      /function renderConnectorStatusMarkdown/,
      /Connector Status/,
      /Checked at/,
      /blocker evidence only/,
      /mcp__codex_apps__figma\._get_libraries/,
      /docs\/release_github_handoff_evidence\.json/,
      /mcp__codex_apps__github\._get_users_recent_prs_in_repo/,
      /pull_requests_count/,
      /Status: not release-ready/,
      /Do not treat this gap pack as release-ready evidence/,
      /operator_intake_packet/,
      /operatorWorktreeStagingSummary/,
      /function finalPrHeadStatus/,
      /worktree_staging_summary/,
      /final_pr_head_status/,
      /configured_release_pr_number/,
      /Configured release PR number/,
      /selected_release_pr_head_sha/,
      /expected_release_pr_head_sha/,
      /External-state expected PR head/,
      /Final PR head match status/,
      /bucket_counts/,
      /waiting_for_external_operator_inputs/,
      /Operator Intake Packet/,
      /Required operator-controlled inputs/,
      /forbidden inputs or closure shortcuts/i,
      /staging\/isolation plan/,
      /function renderStagingBucketMarkdown/,
      /Staging\/isolation bucket entries/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /generated_at/,
      /release-pr-candidates-current-result\.json/,
      /release-staged-scope-current-result\.json/,
      /release-evidence-draft-review-current-result\.json/,
      /release-evidence-promotion-result\.json/,
      /release-design-manifest-create-result\.json/,
      /release-ota-attestation-create-result\.json/,
      /latest_release_promotion_result/,
      /latest_design_manifest_create_result/,
      /latest_ota_attestation_create_result/,
      /Promotion result/,
      /Promotion failures/,
      /Evidence Creation Attempts/,
      /Design creation failures/,
      /OTA creation failures/,
      /latest_release_pr_candidates_result/,
      /gh_pr_list_checked_at/,
      /gh_pr_list_open_pr_count/,
      /current_gh_pr_list/,
      /github_connector_diagnostic/,
      /connector_diagnostic_stale_for_current_review/,
      /latest_release_staged_scope_result/,
      /latest_release_draft_review_result/,
      /current_pr_status/,
      /connector_evidence/,
      /source_of_truth/,
      /Staged-scope status/,
      /Staged-scope closes release/,
      /can_promote_all/,
      /can_promote/,
      /does_not_close_release_readiness/,
      /blocking_fields/,
      /required_operator_inputs/,
      /externalStateDiagnostics/,
      /git_status_changed_summary/,
      /worktree_close_hint/,
      /WORKTREE_QUARANTINE_MANIFEST_FILE/,
      /worktree-quarantine-current/,
      /function summarizeWorktreeQuarantine/,
      /function worktreeClosePlan/,
      /local_worktree_close_plan/,
      /quarantine_bundle/,
      /available_as_preservation_evidence_not_release_closure/,
      /stale_changed_path_mismatch/,
      /current_changed_entries/,
      /stale_reason/,
      /changed_paths/,
      /include_local_artifacts/,
      /copied_untracked/,
      /skipped_local_artifacts/,
      /isolation_evidence/,
      /quarantine_matches_current/,
      /still_blocks_release/,
      /current_dirty_state_preserved_not_release_closure/,
      /quarantine evidence alone is not release closure/,
      /function worktreeStagingPlan/,
      /release_worktree_scope\.mjs/,
      /normalizeReleasePath/,
      /releaseStagingBucket/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /Do not stage this plan automatically/,
      /blocked_until_clean_or_isolated/,
      /worktree_close_plan/,
      /acceptance_commands/,
      /export:worktree-quarantine/,
      /Do not commit runtime, storage, reports, or local evidence by default/,
      /function isPathInsideRepo/,
      /Release evidence gap pack output must be outside the repository/,
      /release_ready:\s*false/,
      /readiness_close_sequence/,
      /function readinessCloseSequence/,
      /blocking_requirements/,
      /command_skeleton/,
      /Command skeleton \(PowerShell\)/,
      /design-handoff-missing/,
      /ota-credential-rotation-attestation-missing/,
      /local-git-state-open/,
      /final-release-readiness/,
      /npm run review:release-pr-candidates/,
      /npm run review:release-staged-scope/,
      /npm run review:release-readiness/,
      /DESIGN_HANDOFF_OWNER/,
      /DESIGN_HANDOFF_MANIFEST_INPUT_FILE/,
      /CANVA_BRAND_KIT_URL/,
      /OTA_CREDENTIAL_ROTATION_INPUT_FILE/,
      /RELEASE_PR_NUMBER/,
      /forbidden_closure/,
      /Do not use screenshots/,
      /Do not include Cookie/,
      /Do not reuse merged PR #2/,
      /Current PR status source/,
      /Current PR connector open PR count/,
      /Current PR connector checked at/,
      /PR candidate gh checked at/,
      /PR candidate gh open PR count/,
      /PR connector stale for current review/,
      /Selected release PR head/,
      /External-state expected PR head/,
    ],
    'scripts/report_release_evidence_gap_pack.mjs truthful gap-pack report',
  );
  assertTextContainsPatterns(
    readText('scripts/export_release_operator_intake_packet.mjs'),
    [
      /RELEASE_OPERATOR_INTAKE_SOURCE_FILE/,
      /RELEASE_OPERATOR_INTAKE_PACKET_FILE/,
      /RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE/,
      /release-operator-intake-packet\.json/,
      /release-operator-intake-packet\.md/,
      /fallbackValue/,
      /source_gap_pack/,
      /operator_intake_packet/,
      /current_pr_status/,
      /final_pr_head_status/,
      /configured_release_pr_number/,
      /selected_release_pr_head_sha/,
      /expected_release_pr_head_sha/,
      /source_status/,
      /release_github_handoff_evidence\.json/,
      /mcp__codex_apps__github\._get_users_recent_prs_in_repo/,
      /Current PR Handoff Evidence/,
      /Connector open PR count/,
      /Connector checked at/,
      /pr_candidate_review/,
      /Current gh PR checked at/,
      /Current gh open PR count/,
      /Connector stale for current review/,
      /Configured release PR number/,
      /Selected release PR head/,
      /External-state expected PR head/,
      /Final PR head match status/,
      /Worktree Staging\/Isolation Summary/,
      /worktree_staging_summary/,
      /bucket_counts/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /required_external_inputs/,
      /command_skeleton/,
      /DESIGN_HANDOFF_MANIFEST_INPUT_FILE/,
      /OTA_CREDENTIAL_ROTATION_INPUT_FILE/,
      /RELEASE_PR_NUMBER/,
      /release_ready:\s*false/,
      /does_not_close_release_readiness:\s*true/,
      /Release Operator Intake Packet/,
      /Command skeleton \(PowerShell\)/,
      /external-evidence intake checklist only/,
      /does not replace the final design manifest/,
      /This packet does not close release readiness/,
      /function isPathInsideRepo/,
      /Release operator intake packet output must be outside the repository/,
      /Run npm run report:release-evidence-gap-pack first/,
    ],
    'scripts/export_release_operator_intake_packet.mjs operator intake export guard',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_evidence_gap_pack.mjs'),
    [
      /RELEASE_EVIDENCE_GAP_PACK_FILE/,
      /RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE/,
      /release-evidence-gap-pack\.json/,
      /release-evidence-gap-pack\.md/,
      /Release evidence gap pack must be outside the repository/,
      /release_ready=false/,
      /source_status\.release_readiness_status\.current_pr/,
      /latest_release_readiness_result/,
      /Design handoff manifest was not found/,
      /OTA credential rotation attestation was not found/,
      /Release PR candidate gate has not passed/,
      /Release external-state gate has not passed/,
      /design-handoff-missing/,
      /ota-credential-rotation-attestation-missing/,
      /local-git-state-open/,
      /operator_intake_packet/,
      /required_external_inputs/,
      /design_handoff_manifest/,
      /ota_credential_rotation_attestation/,
      /final_release_pr_and_local_state/,
      /worktree_staging_summary/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /final_gate/,
      /npm run review:release-readiness/,
      /Release evidence gap pack verification summary/,
    ],
    'scripts/verify_release_evidence_gap_pack.mjs release gap pack contract',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_operator_intake_packet.mjs'),
    [
      /RELEASE_OPERATOR_INTAKE_PACKET_FILE/,
      /RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE/,
      /release-operator-intake-packet\.json/,
      /release-operator-intake-packet\.md/,
      /Release operator intake packet must be outside the repository/,
      /Release operator intake Markdown must be outside the repository/,
      /release_ready=false/,
      /does_not_close_release_readiness=true/,
      /design_handoff_manifest/,
      /ota_credential_rotation_attestation/,
      /final_release_pr_and_local_state/,
      /configured_release_pr_number/,
      /selected_release_pr_number/,
      /selected_release_pr_head_sha/,
      /external_state_local_head_sha/,
      /external_state_pr_head_sha/,
      /worktree_staging_summary/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /mcp__codex_apps__github\._get_users_recent_prs_in_repo/,
      /Release Operator Intake Packet/,
      /external-evidence intake checklist only/,
      /Release operator intake packet verification summary/,
    ],
    'scripts/verify_release_operator_intake_packet.mjs operator intake packet contract',
  );
  assertTextContainsPatterns(
    readText('scripts/review_release_staged_scope.mjs'),
    [
      /RELEASE_STAGED_SCOPE_RESULT_FILE/,
      /release-staged-scope-result\.json/,
      /Release staged-scope result output must be outside the repository/,
      /git.*diff.*--cached.*--name-status|--cached[\s\S]*--name-status/s,
      /RELEASE_STAGED_SCOPE_ALLOW_OPERATOR_DECISION/,
      /does_not_close_release_readiness:\s*true/,
      /candidate_release_scope/,
      /release_worktree_scope\.mjs/,
      /categorizeReleasePath/,
      /releaseStagingBucket/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /runtime\/local entries that must remain local by default/,
      /requiring explicit operator decision/,
      /No staged files are present; staged-scope review has nothing to reject/,
    ],
    'scripts/review_release_staged_scope.mjs staged release scope guard',
  );
  assertTextContainsPatterns(
    readText('scripts/create_worktree_quarantine_bundle.mjs'),
    [
      /includeLocalArtifacts/,
      /release_worktree_scope\.mjs/,
      /isReleaseLocalArtifactPath/,
      /include_local_artifacts/,
      /release_staging_plan/,
      /function renderReleaseStagingTsv/,
      /candidate_release_scope/,
      /needs_explicit_operator_decision/,
      /must_remain_local_by_default/,
      /does_not_close_release_readiness/,
      /skipped_reason/,
      /local\/runtime artifact excluded by default/,
      /Do not stage this plan automatically/,
      /release-staging-\*\.tsv/,
      /Do not copy reports, storage, runtime, output, test-results, or database\/backups/,
      /Skipped local artifacts/,
    ],
    'scripts/create_worktree_quarantine_bundle.mjs local-artifact quarantine guard',
  );
  assertTextContainsPatterns(
    readText('scripts/prepare_release_evidence_drafts.mjs'),
    [
      /drafts/,
      /design_handoff_manifest\.draft\.json/,
      /ota_credential_rotation_attestation\.draft\.json/,
      /RELEASE_EVIDENCE_DRAFT_OVERWRITE/,
      /already exists/,
      /overwrite_allowed/,
      /function isPathInsideRepo/,
      /Release evidence draft output must be outside the repository/,
      /release_ready:\s*false/,
      /Draft only/,
      /Drafts are not release-ready evidence/,
      /Do not manually copy a draft to its final path/,
      /review:release-evidence-drafts/,
      /promote:release-evidence-drafts/,
      /review:release-design/,
      /review:release-ota-credentials/,
      /review:release-readiness/,
      /30-day release evidence window/,
      /Do not include Cookie/,
    ],
    'scripts/prepare_release_evidence_drafts.mjs draft-only external evidence preparation',
  );
  assertTextContainsPatterns(
    readText('scripts/review_release_evidence_drafts.mjs'),
    [
      /checkDesignHandoff/,
      /checkOtaCredentialRotationAttestation/,
      /RELEASE_EVIDENCE_DRAFT_REVIEW_RESULT_FILE/,
      /release-evidence-draft-review-result\.json/,
      /RELEASE_EVIDENCE_DRAFT_DIR/,
      /design_handoff_manifest\.draft\.json/,
      /ota_credential_rotation_attestation\.draft\.json/,
      /function isPathInsideRepo/,
      /draft review result must be outside the repository/,
      /release_ready:\s*false/,
      /can_promote_all/,
      /blocking_fields/,
      /required_operator_inputs/,
      /summarizeBlockingFields/,
      /sourceReviewPattern/,
      /platformCredentialTypesPattern/,
      /redaction_checked/,
      /backup_cleanup\.paths_reviewed/,
      /source_failure/,
      /Accessible Figma source URL/,
      /database\/backups cleanup action/,
      /30-day release evidence window/,
      /review:release-evidence-drafts/,
      /promote:release-evidence-drafts/,
      /Do not treat this draft review result as final release evidence/,
      /process\.exit\(1\)/,
    ],
    'scripts/review_release_evidence_drafts.mjs draft pre-review guard',
  );
  assertTextContainsPatterns(
    readText('scripts/promote_release_evidence_drafts.mjs'),
    [
      /checkDesignHandoff/,
      /checkOtaCredentialRotationAttestation/,
      /RELEASE_EVIDENCE_DRAFT_DIR/,
      /RELEASE_EVIDENCE_PROMOTION_RESULT_FILE/,
      /release-evidence-promotion-result\.json/,
      /RELEASE_EVIDENCE_PROMOTE_OVERWRITE/,
      /design_handoff_manifest\.draft\.json/,
      /ota_credential_rotation_attestation\.draft\.json/,
      /design_handoff_manifest\.json/,
      /ota_credential_rotation_attestation\.json/,
      /function isPathInsideRepo/,
      /must be outside the repository/,
      /_draft_notice/,
      /credential-shaped text/,
      /No final release evidence files were written/,
      /Promotion result/,
      /does_not_close_release_readiness/,
      /final_release_evidence_files_written/,
      /requireOutsideRepo:\s*true/,
      /review:release-design/,
      /review:release-ota-credentials/,
      /review:release-readiness/,
    ],
    'scripts/promote_release_evidence_drafts.mjs guarded external evidence promotion',
  );
} catch (error) {
  fail(`could not read external-state collector script: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/verify_release_env.mjs'),
    [
      /checkProductionEnvFile/,
      /RELEASE_ENV_FILE/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function existingEvidenceOrRepo/,
      /production\.env/,
      /Release env summary/,
    ],
    'scripts/verify_release_env.mjs',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness.mjs'),
    [
      /checkProductionEnvFile/,
      /const releaseEvidenceDir/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function evidencePath/,
      /function existingEvidenceOrRepo/,
      /function latestExistingEvidencePath/,
      /generated_at/,
      /mtimeMs/,
      /production\.env/,
      /llm-attestation\.json/,
      /function checkExternalStateResult/,
      /expected_local_head_sha/,
      /RELEASE_EXTERNAL_STATE_RESULT_FILE/,
      /release-external-state-result\.json/,
      /release-external-state-current-result\.json/,
      /RELEASE_PR_CANDIDATES_RESULT_FILE/,
      /release-pr-candidates-result\.json/,
      /release-pr-candidates-current-result\.json/,
      /function checkReleasePrCandidateResult\(/,
      /checkReleasePrCandidateResultForFailedExternalState/,
      /Release PR candidate gate has not passed/,
      /function checkReleaseStagedScopeResult\(/,
      /RELEASE_STAGED_SCOPE_RESULT_FILE/,
      /release-staged-scope-result\.json/,
      /release-staged-scope-current-result\.json/,
      /npm run review:release-staged-scope/,
      /does_not_close_release_readiness/,
      /Release staged-scope result has no forbidden staged files/,
      /Release staged-scope warning/,
      /summarizeStagedScopeFailures/,
      /checkReleaseStagedScopeResult\(\);[\s\S]*checkExternalStateResult\(\);/s,
      /Release PR candidate result matches external-state final PR/,
      /Release external-state PR #\$\{expectedPrNumber\} does not match release PR candidate result/,
      /selectedHeadRefOid/,
      /expected_release_pr_head_sha/,
      /Release external-state local HEAD/,
      /Release external-state result did not record expected_local_head_sha/,
      /Release PR candidate head matches external-state head/,
      /Release external-state PR head \$\{externalStateHeadSha\} does not match release PR candidate head/,
      /draft-ready transition mode/,
      /RELEASE_READINESS_ALLOW_PENDING_EXTERNAL_STATE/,
      /const pendingExternalStateAllowed/,
      /pre_ready_pending_external_state/,
      /final_release_ready/,
      /mode: pendingExternalStateAllowed \? 'pre_ready' : 'final'/,
      /function isPathInsideRepo/,
      /stored outside the repository in a controlled evidence directory/,
      /Release external-state gate has not passed/,
      /function summarizeExternalStateFailures/,
      /normalizedFailures\.join/,
      /replace\(\s*\/\\\.\+\$\/,\s*''\s*\)/,
      /RELEASE_READINESS_RESULT_FILE/,
      /release-readiness-result\.json/,
      /Release readiness result output must be stored outside the repository in a controlled evidence directory/,
      /generated_at must be a real ISO timestamp/,
      /function escapeRegExp/,
      /expectedPrNumber/,
      /requiredPassedEvidence/,
      /is the configured release PR/,
      /PR #\$\{expectedPrPattern\} is not draft/,
      /PR #\$\{expectedPrPattern\} status checks are all green/,
      /24 \* 60 \* 60 \* 1000/,
    ],
    'scripts/verify_release_readiness.mjs release env and external-state integration',
  );
} catch (error) {
  fail(`could not read release env verifier scripts: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/verify_release_llm.mjs'),
    [
      /checkLlmConnectivityAttestation/,
      /LLM_CONNECTIVITY_ATTESTATION_FILE/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function existingEvidenceOrRepo/,
      /llm-attestation\.json/,
      /Release LLM connectivity summary/,
    ],
    'scripts/verify_release_llm.mjs',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness.mjs'),
    [
      /checkLlmAttestationFile/,
      /LLM_CONNECTIVITY_ATTESTATION_FILE/,
    ],
    'scripts/verify_release_readiness.mjs release LLM integration',
  );
} catch (error) {
  fail(`could not read release LLM verifier scripts: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/verify_release_design.mjs'),
    [
      /checkDesignHandoff/,
      /DESIGN_HANDOFF_MANIFEST_FILE/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function existingEvidenceOrRepo/,
      /requireOutsideRepo/,
      /Release design handoff summary/,
    ],
    'scripts/verify_release_design.mjs',
  );
  const designHandoffChecks = readText('scripts/lib/design_handoff_checks.mjs');
  assertTextContainsPatterns(
    designHandoffChecks,
    [
      /function isDateOnly/,
      /last_reviewed_at must be a real YYYY-MM-DD date/,
      /function isFutureDateOnly/,
      /last_reviewed_at must not be in the future/,
      /RELEASE_EVIDENCE_MAX_AGE_DAYS/,
      /function isOlderThanReleaseEvidenceWindow/,
      /last_reviewed_at must be within the \$\{RELEASE_EVIDENCE_MAX_AGE_DAYS\}-day release evidence window/,
      /function isWeakDesignOwner/,
      /function collectNonClosingSourceEvidenceFailures/,
      /function collectSourceReviewFailures/,
      /sourceReviewRequiredTrueFields/,
      /source_review must record how Figma, Canva, Brand Kit, design tokens, and required flows were verified/,
      /figma_source_verified/,
      /brand_kit_source_verified/,
      /does_not_close_release_design_gate/,
      /source_review\.evidence_ref must not reference non-closing connector blocker evidence/,
      /docs\/release_figma_handoff_evidence\.json/,
      /docs\/release_canva_handoff_evidence\.json/,
      /failures\.push\(\.\.\.sourceReviewFailures\)/,
      /owner must be a real accountable design owner/,
      /function collectDraftResidue/,
      /_draft_notice/,
      /continue;/,
      /draft or placeholder text/,
      /failures\.length === 0/,
      /replace\(\s*\/\^\\uFEFF\/,\s*''\)/,
    ],
    'scripts/lib/design_handoff_checks.mjs design date and owner guards',
  );
  assertTextContainsPatterns(
    readText('scripts/create_release_design_manifest.mjs'),
    [
      /DESIGN_HANDOFF_OWNER/,
      /DESIGN_HANDOFF_MANIFEST_INPUT_FILE/,
      /DESIGN_HANDOFF_MANIFEST_OUTPUT/,
      /DESIGN_HANDOFF_MANIFEST_FILE/,
      /DESIGN_HANDOFF_SOURCE_REVIEW_METHOD/,
      /DESIGN_HANDOFF_SOURCE_REVIEW_REF/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE/,
      /release-design-manifest-create-result\.json/,
      /import fs from 'node:fs'/,
      /createHash/,
      /function defaultOutputPath/,
      /function defaultResultPath/,
      /function readJsonPath/,
      /function writeResult/,
      /function fileSha256/,
      /input_file_sha256/,
      /candidate_file_sha256/,
      /output_file_sha256/,
      /checkDesignHandoff/,
      /function isPathInsideRepo/,
      /requireOutsideRepo:\s*true/,
      /release_ready:\s*false/,
      /does_not_close_release_readiness/,
      /\.design-manifest-candidates/,
      /Design handoff manifest output must be outside the repository/,
      /Design manifest creation result must be outside the repository/,
      /DESIGN_HANDOFF_MANIFEST_INPUT_FILE must be outside the repository/,
      /Design handoff manifest input and output paths must be different/,
      /External design handoff manifest input passed release design verifier/,
      /did not pass the release design verifier/,
      /assertDesignManifestPasses\(inputPath,\s*'input'\)/,
      /assertDesignManifestPasses\(candidatePath,\s*'candidate'\)/,
      /assertDesignManifestPasses\(outputPath,\s*'output'\)/,
      /Generated design handoff manifest passed release design verifier/,
      /Creation result/,
      /No final release design handoff manifest should be used unless this command passes/,
      /design_handoff_manifest\.json/,
      /latestReviewedAt/,
      /does_not_close_release_design_gate/,
      /remaining_design_gate_inputs/,
      /brand_kits_available/,
      /source_review/,
      /not release-closing/,
    ],
    'scripts/create_release_design_manifest.mjs release-closing evidence guard',
  );
  const createDesignManifest = readText('scripts/create_release_design_manifest.mjs');
  if (/owner:\s*['"]Codex release handoff['"]/.test(createDesignManifest)) {
    fail('scripts/create_release_design_manifest.mjs must not hard-code the design handoff owner');
  }
  if (/new Date\(\)\.toISOString\(\)\.slice\(0,\s*10\)/.test(createDesignManifest)) {
    fail('scripts/create_release_design_manifest.mjs must not use the script runtime date as last_reviewed_at');
  }
  if (/\|\|\s*['"]docs\/design_handoff_manifest\.json['"]/.test(createDesignManifest)) {
    fail('scripts/create_release_design_manifest.mjs must not default release manifest output into docs/');
  }
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness.mjs'),
    [
      /checkDesignHandoff/,
      /DESIGN_HANDOFF_MANIFEST_FILE/,
      /existingEvidenceOrRepo/,
      /requireOutsideRepo/,
      /manifestPath !== 'docs\/design_handoff_manifest\.json'/,
      /result\.warnings\.forEach\(addWarning\)/,
    ],
    'scripts/verify_release_readiness.mjs release design integration',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_evidence_bundle.mjs'),
    [
      /checkDesignHandoff/,
      /DESIGN_HANDOFF_MANIFEST_FILE/,
      /RELEASE_EVIDENCE_RESULT_FILE/,
      /release-evidence-result\.json/,
      /function isPathInsideRepo/,
      /existingEvidenceOrRepo/,
      /requireOutsideRepo/,
      /designManifestFile !== 'docs\/design_handoff_manifest\.json'/,
      /Release evidence result output must be stored outside the repository in a controlled evidence directory/,
    ],
    'scripts/verify_release_evidence_bundle.mjs release design integration',
  );
} catch (error) {
  fail(`could not read release design verifier scripts: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/verify_release_ota_credentials.mjs'),
    [
      /checkOtaCredentialRelease/,
      /OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE/,
      /RELEASE_EVIDENCE_DIR/,
      /release-evidence-temp/,
      /function existingEvidenceOrRepo/,
      /requireOutsideRepo/,
      /Release OTA credential summary/,
    ],
    'scripts/verify_release_ota_credentials.mjs',
  );
  assertTextContainsPatterns(
    readText('scripts/create_release_ota_attestation.mjs'),
    [
      /import fs from 'node:fs'/,
      /createHash/,
      /OTA_CREDENTIAL_ROTATION_INPUT_FILE/,
      /OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT/,
      /OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE/,
      /OTA_CREDENTIAL_ROTATION_ATTESTATION_OVERWRITE/,
      /OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE/,
      /source_mode:\s*inputPath\s*\?\s*'external_attestation_input'/,
      /release-ota-attestation-create-result\.json/,
      /function writeCreateResult/,
      /function fileSha256/,
      /input_file_sha256/,
      /output_file_sha256/,
      /checkOtaCredentialRelease/,
      /requireOutsideRepo:\s*true/,
      /release_ready:\s*false/,
      /does_not_close_release_readiness/,
      /Do not point OTA_CREDENTIAL_ROTATION_INPUT_FILE at a repository file, draft file, or the same path as the final output/,
      /must be outside the repository/,
      /input and output paths must be different/,
      /Release OTA credential attestation input passed verifier/,
      /Release OTA credential attestation output passed verifier/,
      /no final evidence file was written/i,
      /Creation result/,
      /review:release-ota-credentials/,
      /review:release-readiness/,
    ],
    'scripts/create_release_ota_attestation.mjs controlled OTA attestation generation',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_evidence_intake_contract.mjs'),
    [
      /mkdtempSync/,
      /release-evidence-intake-/,
      /spawnSync/,
      /create_release_design_manifest\.mjs/,
      /create_release_ota_attestation\.mjs/,
      /DESIGN_HANDOFF_MANIFEST_INPUT_FILE/,
      /OTA_CREDENTIAL_ROTATION_INPUT_FILE/,
      /external_manifest_input/,
      /external_attestation_input/,
      /design create-result records input file sha256/,
      /design create-result records candidate file sha256/,
      /design create-result records output file sha256/,
      /OTA create-result records input file sha256/,
      /OTA create-result records output file sha256/,
      /release_ready === false/,
      /does_not_close_release_readiness === true/,
      /rejects repository input path/,
      /rejects same input and output path/,
      /function designManifestWithEvidenceRef/,
      /design intake rejects connector blocker evidence as source_review evidence_ref/,
      /source_review\\\.evidence_ref must not reference non-closing connector blocker evidence/,
      /function otaAttestationWithPlatformAction/,
      /\['sanitized', 'encrypted_archive'\]/,
      /OTA intake rejects \$\{action\} as a platform credential action/,
      /backup_cleanup\\\.database_backups_action/,
      /Temporary verifier directory retained for inspection/,
    ],
    'scripts/verify_release_evidence_intake_contract.mjs behavioral evidence intake guard',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness_contract.mjs'),
    [
      /mkdtempSync/,
      /release-readiness-contract-/,
      /verify_release_readiness\.mjs/,
      /release-pr-candidates-result\.json/,
      /release-external-state-result\.json/,
      /candidateHead\s*=\s*'a{40}'/,
      /externalStateHead\s*=\s*'b{40}'/,
      /localHeadMismatch\s*=\s*'c{40}'/,
      /expected_local_head_sha:\s*localHeadValue/,
      /expected_release_pr_head_sha:\s*externalStateHeadValue/,
      /headRefOid:\s*candidateHeadValue/,
      /Release external-state PR head b\{40\} does not match release PR candidate head a\{40\}/,
      /Release external-state local HEAD c\{40\} does not match release PR head a\{40\}/,
      /Design handoff manifest last_reviewed_at must be within the 30-day release evidence window/,
      /OTA credential rotation attestation reviewed_at must be within the 30-day release evidence window/,
      /release-readiness rejects matching PR numbers when candidate and external-state heads differ/,
      /release-readiness rejects matching PR heads when local HEAD differs/,
      /release-readiness rejects stale design and OTA final evidence dates/,
      /PR number match is distinguished from PR head mismatch/,
      /PR head match is distinguished from local HEAD mismatch/,
      /stale evidence fixture closes PR head consistency before testing evidence freshness/,
      /PR head mismatch fixture closes unrelated evidence inputs before testing head consistency/,
      /Temporary verifier directory retained for inspection/,
    ],
    'scripts/verify_release_readiness_contract.mjs behavioral release-readiness guard',
  );
  const otaCredentialChecks = readText('scripts/lib/ota_credential_checks.mjs');
  assertTextContainsPatterns(
    otaCredentialChecks,
    [
      /import fs from 'node:fs'/,
      /function isDateOnly/,
      /function isFutureDateOnly/,
      /function isPathInsideRepo/,
      /function isRedactedSecretValue/,
      /function isCredentialTypeList/,
      /function missingRequiredCredentialCoverage/,
      /function isWeakAttestationReviewer/,
      /function normalizeOtaPlatform/,
      /function collectDraftResidue/,
      /_draft_notice/,
      /continue;/,
      /replace\(\s*\/\^\\uFEFF\/,\s*''\)/,
      /draft or placeholder text/,
      /unredacted sensitive fields/,
      /failures\.length === 0/,
      /requireOutsideRepo/,
      /controlled location outside the repository/,
      /reviewed_at must use YYYY-MM-DD/,
      /reviewed_at must not be in the future/,
      /RELEASE_EVIDENCE_MAX_AGE_DAYS/,
      /function isOlderThanReleaseEvidenceWindow/,
      /reviewed_at must be within the \$\{RELEASE_EVIDENCE_MAX_AGE_DAYS\}-day release evidence window/,
      /reviewer must be a real accountable reviewer/,
      /must include Ctrip and Meituan platform entries/,
      /credential_types must be a non-empty list of known OTA credential types/,
      /credential_types must cover cookie, token\/usertoken, signature\/usersign, and authorization material/,
      /action must be rotated or invalidated/,
      /backup cleanup actions belong in backup_cleanup\.database_backups_action/,
      /git ls-files database\/backups/,
      /review:release-readiness\|review:release-ota-credentials/,
    ],
    'scripts/lib/ota_credential_checks.mjs attestation evidence guards',
  );
  const platformActionGuard = otaCredentialChecks.match(/const action = String\(platform\?\.action[\s\S]*?backup_cleanup\.database_backups_action\.`\);[\s\S]*?\n    }\n  }/)?.[0] || '';
  if (!/\['rotated', 'invalidated'\]\.includes\(action\)/.test(platformActionGuard)) {
    fail('scripts/lib/ota_credential_checks.mjs must require platform credential actions to be rotated or invalidated');
  } else if (/encrypted_archive|sanitized/.test(platformActionGuard)) {
    fail('scripts/lib/ota_credential_checks.mjs must not allow backup cleanup actions as platform credential actions');
  } else {
    pass('scripts/lib/ota_credential_checks.mjs keeps platform credential actions separate from backup cleanup actions');
  }
  const redactedSecretFunction = otaCredentialChecks.match(/function isRedactedSecretValue[\s\S]*?\n}/)?.[0] || '';
  if (/TODO|CHANGE_ME|placeholder/i.test(redactedSecretFunction)) {
    fail('scripts/lib/ota_credential_checks.mjs must not treat TODO, CHANGE_ME, or placeholder sensitive fields as redacted release evidence');
  } else {
    pass('scripts/lib/ota_credential_checks.mjs rejects placeholder sensitive fields as release evidence');
  }

  const prepareEvidenceDrafts = readText('scripts/prepare_release_evidence_drafts.mjs');
  if (!/platform credential rotation or invalidation action/.test(prepareEvidenceDrafts)) {
    fail('scripts/prepare_release_evidence_drafts.mjs must tell operators that platform credentials require rotation or invalidation');
  }
  if (!/backup_cleanup\.database_backups_action as deleted, encrypted_archive, or sanitized/.test(prepareEvidenceDrafts)) {
    fail('scripts/prepare_release_evidence_drafts.mjs must keep backup cleanup actions under backup_cleanup.database_backups_action');
  }
  const prepareOtaDraftSection = prepareEvidenceDrafts.match(/id: 'ota-credential-rotation-attestation-missing'[\s\S]*?next_gate_order/)?.[0] || prepareEvidenceDrafts;
  if (/credential rotation, invalidation, encrypted archive, or sanitization action/i.test(prepareOtaDraftSection)) {
    fail('scripts/prepare_release_evidence_drafts.mjs must not present encrypted_archive or sanitized as platform credential actions');
  }

  const reviewEvidenceDrafts = readText('scripts/review_release_evidence_drafts.mjs');
  if (!/Use rotated or invalidated only for platform credentials/.test(reviewEvidenceDrafts)) {
    fail('scripts/review_release_evidence_drafts.mjs action hint must restrict platform credentials to rotated or invalidated');
  }
  if (!/encrypted_archive and sanitized are backup cleanup actions only/.test(reviewEvidenceDrafts)) {
    fail('scripts/review_release_evidence_drafts.mjs required OTA inputs must label encrypted_archive and sanitized as backup cleanup actions only');
  }
  const draftActionHint = reviewEvidenceDrafts.match(/action:\s*'[^']+'/)?.[0] || '';
  if (/encrypted_archive|sanitized/.test(draftActionHint)) {
    fail('scripts/review_release_evidence_drafts.mjs platform action hint must not list backup cleanup actions as supported platform actions');
  }
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness.mjs'),
    [
      /checkBackupCredentialFields/,
      /checkOtaAttestationFile/,
      /requireOutsideRepo/,
    ],
    'scripts/verify_release_readiness.mjs release OTA credential integration',
  );
} catch (error) {
  fail(`could not read release OTA credential verifier scripts: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('scripts/verify_release_security_scan.mjs'),
    [
      /checkSecurityScanReports/,
      /CODEX_SECURITY_SCAN_DIR/,
      /Release security scan summary/,
    ],
    'scripts/verify_release_security_scan.mjs',
  );
  assertTextContainsPatterns(
    readText('scripts/verify_release_readiness.mjs'),
    [
      /checkSecurityScanReports/,
      /CODEX_SECURITY_SCAN_DIR/,
    ],
    'scripts/verify_release_readiness.mjs release security scan integration',
  );
} catch (error) {
  fail(`could not read release security scan verifier scripts: ${error.message}`);
}

try {
  const workflow = readText('.github/workflows/php.yml');
  let missingWorkflowCommand = false;
  for (const command of requiredWorkflowCommands) {
    if (!workflow.includes(command)) {
      fail(`.github/workflows/php.yml must run ${command}`);
      missingWorkflowCommand = true;
    }
  }
  if (!missingWorkflowCommand) {
    pass('.github/workflows/php.yml covers required CI commands');
  }
} catch (error) {
  fail(`could not read .github/workflows/php.yml: ${error.message}`);
}

const status = readJson('docs/release_readiness_status.json');
const releaseStatusDate = status?.updated_at || '';
if (status) {
  if (status.schema_version !== 1) {
    fail('schema_version must be 1');
  } else {
    pass('schema_version is 1');
  }

  if (!datePattern.test(String(status.updated_at || ''))) {
    fail('updated_at must be a YYYY-MM-DD date');
  } else {
    pass('updated_at is a YYYY-MM-DD date');
  }

  if (status.overall_status !== 'not_release_ready') {
    fail(`overall_status must remain not_release_ready until blockers close; got ${status.overall_status}`);
  } else {
    pass('overall_status is not_release_ready');
  }

  assertExactStringArray(status.scope, requiredScope, 'scope');

  const pluginStatus = Array.isArray(status.plugin_status) ? status.plugin_status : [];
  assertExactStringArray(
    pluginStatus.map((entry) => entry?.plugin),
    requiredScope,
    'plugin_status plugin list',
  );
  for (const plugin of requiredScope) {
    const entry = pluginStatus.find((candidate) => candidate?.plugin === plugin);
    if (!entry) {
      fail(`plugin_status is missing ${plugin}`);
      continue;
    }
    for (const field of ['status', 'resolved', 'open']) {
      if (!(field in entry)) {
        fail(`plugin_status entry ${plugin} is missing ${field}`);
      }
    }
    if (!Array.isArray(entry.resolved)) {
      fail(`plugin_status entry ${plugin}.resolved must be an array`);
    }
    if (!Array.isArray(entry.open)) {
      fail(`plugin_status entry ${plugin}.open must be an array`);
    }
  }
  const figmaStatus = pluginStatus.find((candidate) => candidate?.plugin === '@figma');
  const figmaOpenText = Array.isArray(figmaStatus?.open) ? figmaStatus.open.join('\n') : '';
  if (!/UNAUTHORIZED|reauthentication/i.test(figmaOpenText)) {
    fail('plugin_status @figma.open must record the current connector reauthentication blocker');
  }
  const canvaStatus = pluginStatus.find((candidate) => candidate?.plugin === '@canva');
  const canvaOpenText = Array.isArray(canvaStatus?.open) ? canvaStatus.open.join('\n') : '';
  if (!/UNAUTHORIZED|oauth_token_invalid_grant|reauthentication/i.test(canvaOpenText)) {
    fail('plugin_status @canva.open must record the current connector reauthentication blocker');
  }
  const githubStatus = pluginStatus.find((candidate) => candidate?.plugin === '@github');
  const githubStatusText = [
    ...(Array.isArray(githubStatus?.resolved) ? githubStatus.resolved : []),
    ...(Array.isArray(githubStatus?.open) ? githubStatus.open : []),
  ].join('\n');
  if (!/gh pr list/i.test(githubStatusText) || !/gh_pr_list_checked_at|checked_at=2026/i.test(githubStatusText)) {
    fail('plugin_status @github must record current gh pr list as the PR candidate source of truth');
  }
  if (!/release_github_handoff_evidence\.json/.test(githubStatusText) || !/diagnostic|does not close PR state|does not close release readiness/i.test(githubStatusText)) {
    fail('plugin_status @github must label GitHub connector evidence as non-closing diagnostic evidence');
  }

  const currentPrCandidateReview = status.current_pr?.pr_candidate_review || {};
  if (
    currentPrCandidateReview.command !== 'npm run review:release-pr-candidates'
    || currentPrCandidateReview.result_file !== '../release-evidence-temp/release-pr-candidates-current-result.json'
    || currentPrCandidateReview.status !== 'failed'
    || Number(currentPrCandidateReview.gh_pr_list_open_pr_count) !== 0
    || currentPrCandidateReview.source_of_truth_for_current_pr_candidates !== true
    || !checkedAtPattern.test(String(currentPrCandidateReview.gh_pr_list_checked_at || ''))
    || checkedAtDate(currentPrCandidateReview.gh_pr_list_checked_at) !== releaseStatusDate
  ) {
    fail('current_pr.pr_candidate_review must record the current failing gh pr list result as the PR candidate source of truth');
  } else {
    pass('current_pr.pr_candidate_review records the current failing gh pr list result');
  }
  const currentPrConnectorDiagnostic = currentPrCandidateReview.connector_diagnostic || {};
  if (
    currentPrConnectorDiagnostic.path !== 'docs/release_github_handoff_evidence.json'
    || !checkedAtPattern.test(String(currentPrConnectorDiagnostic.checked_at || ''))
    || typeof currentPrConnectorDiagnostic.is_stale_for_current_review !== 'boolean'
    || currentPrConnectorDiagnostic.does_not_close_release_readiness !== true
  ) {
    fail('current_pr.pr_candidate_review.connector_diagnostic must record non-closing GitHub connector evidence');
  } else {
    pass('current_pr.pr_candidate_review.connector_diagnostic records non-closing GitHub connector evidence');
  }

  const currentPrConnectorEvidence = status.current_pr?.connector_evidence || {};
  if (
    currentPrConnectorEvidence.path !== 'docs/release_github_handoff_evidence.json'
    || currentPrConnectorEvidence.tool !== 'mcp__codex_apps__github._get_users_recent_prs_in_repo'
    || currentPrConnectorEvidence.state !== 'open'
    || currentPrConnectorEvidence.result !== 'blocked'
    || Number(currentPrConnectorEvidence.pull_requests_count) !== 0
    || !checkedAtPattern.test(String(currentPrConnectorEvidence.checked_at || ''))
    || currentPrConnectorEvidence.does_not_close_release_readiness !== true
  ) {
    fail('current_pr.connector_evidence must point to the non-closing GitHub open-PR connector diagnostic');
  } else {
    pass('current_pr.connector_evidence records the non-closing GitHub open-PR connector diagnostic');
  }

  const releaseCheck = status.release_readiness_check ?? {};
  if (releaseCheck.command !== 'npm run review:release-readiness') {
    fail('release_readiness_check.command must be npm run review:release-readiness');
  }
  if (releaseCheck.status !== 'failing_as_expected') {
    fail('release_readiness_check.status must be failing_as_expected while release blockers remain');
  }
  if (releaseCheck.result_mode !== 'final') {
    fail('release_readiness_check.result_mode must be final for the formal release-readiness gate');
  }
  if (releaseCheck.final_release_ready !== false) {
    fail('release_readiness_check.final_release_ready must remain false while blockers are open');
  }
  if (releaseCheck.result_file_template !== 'docs/release_readiness_result.example.json') {
    fail('release_readiness_check.result_file_template must reference docs/release_readiness_result.example.json');
  }
  assertArrayContainsPatterns(
    releaseCheck.open_failures,
    requiredOpenFailurePatterns,
    'release_readiness_check.open_failures',
  );

  const externalStateCheck = status.external_state_check ?? {};
  if (externalStateCheck.command !== 'npm run review:release-external-state') {
    fail('external_state_check.command must be npm run review:release-external-state');
  }
  if (externalStateCheck.evidence_file_template !== 'docs/release_external_state_evidence.example.json') {
    fail('external_state_check.evidence_file_template must reference docs/release_external_state_evidence.example.json');
  }
  if (externalStateCheck.result_file_template !== 'docs/release_external_state_result.example.json') {
    fail('external_state_check.result_file_template must reference docs/release_external_state_result.example.json');
  }
  if (externalStateCheck.status !== 'failing_as_expected') {
    fail('external_state_check.status must be failing_as_expected while local git blockers remain');
  }
  assertArrayContainsPatterns(
    externalStateCheck.open_failures,
    requiredExternalStateFailurePatterns,
    'external_state_check.open_failures',
  );
  const externalStateWarnings = Array.isArray(externalStateCheck.warnings) ? externalStateCheck.warnings.join('\n') : '';
  if (!externalStateWarnings.includes('npm run collect:release-external-state')) {
    fail('external_state_check.warnings must mention npm run collect:release-external-state');
  }

  const prCandidateCheck = status.pr_candidate_check ?? {};
  if (prCandidateCheck.command !== 'npm run review:release-pr-candidates') {
    fail('pr_candidate_check.command must be npm run review:release-pr-candidates');
  }
  if (prCandidateCheck.status !== 'failing_as_expected') {
    fail('pr_candidate_check.status must be failing_as_expected while no final PR candidate is available');
  }
  if (prCandidateCheck.result_file !== '../release-evidence-temp/release-pr-candidates-current-result.json') {
    fail('pr_candidate_check.result_file must reference ../release-evidence-temp/release-pr-candidates-current-result.json');
  }
  const prCandidateOpenFailures = Array.isArray(prCandidateCheck.open_failures) ? prCandidateCheck.open_failures.join('\n') : '';
  if (!/no open release PR candidates/i.test(prCandidateOpenFailures) || !/current gh pr list checked_at/i.test(prCandidateOpenFailures)) {
    fail('pr_candidate_check.open_failures must mention no open release PR candidates from current gh pr list');
  }
  if (!/diagnostic connector evidence|diagnostic-only connector evidence|does not close PR state/i.test(prCandidateOpenFailures)) {
    fail('pr_candidate_check.open_failures must keep connector evidence diagnostic-only');
  }
  if (!/RELEASE_PR_NUMBER/.test(String(prCandidateCheck.close_condition || ''))) {
    fail('pr_candidate_check.close_condition must mention RELEASE_PR_NUMBER');
  }
  if (!/review:release-staged-scope/.test(String(prCandidateCheck.close_condition || ''))) {
    fail('pr_candidate_check.close_condition must mention review:release-staged-scope before external-state');
  }

  const stagedScopeCheck = status.staged_scope_check ?? {};
  if (stagedScopeCheck.command !== 'npm run review:release-staged-scope') {
    fail('staged_scope_check.command must be npm run review:release-staged-scope');
  }
  if (stagedScopeCheck.status !== 'passing_not_release_closure') {
    fail('staged_scope_check.status must be passing_not_release_closure while it is only a staging guard');
  }
  if (stagedScopeCheck.result_file !== '../release-evidence-temp/release-staged-scope-current-result.json') {
    fail('staged_scope_check.result_file must reference ../release-evidence-temp/release-staged-scope-current-result.json');
  }
  const stagedScopeText = [
    ...(Array.isArray(stagedScopeCheck.resolved) ? stagedScopeCheck.resolved : []),
    ...(Array.isArray(stagedScopeCheck.warnings) ? stagedScopeCheck.warnings : []),
    String(stagedScopeCheck.close_condition || ''),
  ].join('\n');
  if (!/runtime\/local/i.test(stagedScopeText)) {
    fail('staged_scope_check must mention runtime/local staged file protection');
  }
  if (!/does not close|not prove|not close/i.test(stagedScopeText)) {
    fail('staged_scope_check must state that staged-scope does not close release readiness or external-state');
  }
  if (!/review:release-readiness/.test(stagedScopeText)) {
    fail('staged_scope_check.close_condition must mention review:release-readiness consumption');
  }

  assertArrayContainsPatterns(
    status.do_not_claim_ready_until,
    requiredDoNotClaimReadyPatterns,
    'do_not_claim_ready_until',
  );
  const doNotClaimReadyText = Array.isArray(status.do_not_claim_ready_until) ? status.do_not_claim_ready_until.join('\n') : '';
  if (!doNotClaimReadyText.includes('review:release-env')) {
    fail('do_not_claim_ready_until must mention review:release-env for production env closure');
  }
  if (!doNotClaimReadyText.includes('review:release-llm')) {
    fail('do_not_claim_ready_until must mention review:release-llm for production LLM closure');
  }
  if (!doNotClaimReadyText.includes('review:release-design')) {
    fail('do_not_claim_ready_until must mention review:release-design for Figma/Canva closure');
  }
  if (!doNotClaimReadyText.includes('review:release-ota-credentials')) {
    fail('do_not_claim_ready_until must mention review:release-ota-credentials for OTA credential closure');
  }
  if (!doNotClaimReadyText.includes('review:release-security-scan')) {
    fail('do_not_claim_ready_until must mention review:release-security-scan for Codex Security scan closure');
  }
  if (!doNotClaimReadyText.includes('review:release-pr-candidates')) {
    fail('do_not_claim_ready_until must mention review:release-pr-candidates for final PR selection');
  }

  const blockers = Array.isArray(status.blockers) ? status.blockers : [];
  if (blockers.length !== requiredBlockerIds.length) {
    fail(`blockers must contain exactly ${requiredBlockerIds.length} entries`);
  }
  for (const id of requiredBlockerIds) {
    const blocker = blockers.find((candidate) => candidate?.id === id);
    if (!blocker) {
      fail(`blockers is missing ${id}`);
      continue;
    }
    const expectedStatus = closedBlockerIds.includes(id) ? 'closed' : 'open';
    if (blocker.status !== expectedStatus) {
      fail(`blocker ${id} must be ${expectedStatus}`);
    }
    for (const field of ['title', 'evidence', 'close_condition']) {
      if (typeof blocker[field] !== 'string' || blocker[field].trim() === '') {
        fail(`blocker ${id} is missing ${field}`);
      }
    }
    if (!Array.isArray(blocker.scope) || blocker.scope.length === 0) {
      fail(`blocker ${id} must declare at least one scope`);
    } else {
      const expectedScope = requiredBlockerScopes[id].slice().sort();
      const actualScope = blocker.scope.slice().sort();
      if (JSON.stringify(actualScope) !== JSON.stringify(expectedScope)) {
        fail(`blocker ${id} scope must be ${expectedScope.join(', ')}`);
      }
    }
  }
}

const packageJson = readJson('package.json');
if (packageJson) {
  for (const scriptName of requiredPackageScripts) {
    if (typeof packageJson.scripts?.[scriptName] !== 'string') {
      fail(`package.json scripts is missing ${scriptName}`);
    }
  }
  const localExternalStateScript = String(packageJson.scripts?.['review:release-external-state:local'] || '');
  for (const fragment of requiredLocalExternalStateScriptFragments) {
    if (!localExternalStateScript.includes(fragment)) {
      fail(`package.json review:release-external-state:local must include ${fragment}`);
    }
  }
  assertTextContainsPatterns(
    readText('scripts/review_release_external_state_local.ps1'),
    [
      /collect_release_external_state\.ps1/,
      /RELEASE_EXTERNAL_STATE_FILE/,
      /RELEASE_EXTERNAL_STATE_RESULT_FILE/,
      /release-external-state-local-evidence\.json/,
      /release-external-state-result\.json/,
      /npm\.cmd run review:release-external-state/,
      /exit \$exitCode/,
    ],
    'scripts/review_release_external_state_local.ps1 controlled external-state local wrapper',
  );
}

for (const jsonDoc of [
  'docs/release_readiness_status.schema.json',
  'docs/design_handoff_manifest.example.json',
  'docs/ota_credential_rotation_attestation.example.json',
  'docs/release_external_state_evidence.example.json',
  'docs/release_external_state_result.example.json',
  'docs/llm_connectivity_attestation.example.json',
  'docs/release_readiness_result.example.json',
  'docs/codex_security_scan_manifest.example.json',
]) {
  readJson(jsonDoc);
}

const releaseStatusSchema = readJson('docs/release_readiness_status.schema.json');
if (releaseStatusSchema) {
  const schemaProperties = releaseStatusSchema.properties || {};
  const schemaScopeEnum = schemaProperties.scope?.items?.enum;
  const schemaBlockerIdEnum = schemaProperties.blockers?.items?.properties?.id?.enum;
  const schemaBlockerScopeEnum = schemaProperties.blockers?.items?.properties?.scope?.items?.enum;

  assertExactStringArray(schemaScopeEnum, requiredScope, 'release readiness schema scope enum');
  assertExactStringArray(schemaBlockerIdEnum, requiredBlockerIds, 'release readiness schema blocker id enum');
  assertExactStringArray(schemaBlockerScopeEnum, requiredScope, 'release readiness schema blocker scope enum');

  if (schemaProperties.overall_status?.const !== 'not_release_ready') {
    fail('release readiness schema overall_status.const must be not_release_ready');
  } else {
    pass('release readiness schema overall_status.const is not_release_ready');
  }

  if (schemaProperties.blockers?.minItems !== requiredBlockerIds.length) {
    fail(`release readiness schema blockers.minItems must be ${requiredBlockerIds.length}`);
  } else {
    pass('release readiness schema blockers.minItems matches blocker count');
  }

  if (schemaProperties.blockers?.maxItems !== requiredBlockerIds.length) {
    fail(`release readiness schema blockers.maxItems must be ${requiredBlockerIds.length}`);
  } else {
    pass('release readiness schema blockers.maxItems matches blocker count');
  }

  if (schemaProperties.scope?.maxItems !== requiredScope.length) {
    fail(`release readiness schema scope.maxItems must be ${requiredScope.length}`);
  } else {
    pass('release readiness schema scope.maxItems matches plugin scope count');
  }

  if (schemaProperties.plugin_status?.maxItems !== requiredScope.length) {
    fail(`release readiness schema plugin_status.maxItems must be ${requiredScope.length}`);
  } else {
    pass('release readiness schema plugin_status.maxItems matches plugin scope count');
  }

  if (!schemaProperties.release_readiness_check?.required?.includes('result_file_template')) {
    fail('release readiness schema release_readiness_check.required must include result_file_template');
  } else {
    pass('release readiness schema requires release readiness result file template');
  }
  if (!schemaProperties.release_readiness_check?.required?.includes('default_evidence_dir')) {
    fail('release readiness schema release_readiness_check.required must include default_evidence_dir');
  }
  if (schemaProperties.release_readiness_check?.properties?.command?.const !== 'npm run review:release-readiness') {
    fail('release readiness schema release_readiness_check.command must be const npm run review:release-readiness');
  }
  const currentPrRequired = Array.isArray(schemaProperties.current_pr?.required) ? schemaProperties.current_pr.required : [];
  if (!currentPrRequired.includes('pr_candidate_review')) {
    fail('release readiness schema current_pr.required must include pr_candidate_review');
  }
  const currentPrCandidateReviewSchema = schemaProperties.current_pr?.properties?.pr_candidate_review || {};
  const prCandidateReviewRequired = Array.isArray(currentPrCandidateReviewSchema.required) ? currentPrCandidateReviewSchema.required : [];
  for (const field of ['command', 'result_file', 'status', 'gh_pr_list_checked_at', 'gh_pr_list_open_pr_count', 'source_of_truth_for_current_pr_candidates', 'connector_diagnostic']) {
    if (!prCandidateReviewRequired.includes(field)) {
      fail(`release readiness schema current_pr.pr_candidate_review must require ${field}`);
    }
  }
  if (currentPrCandidateReviewSchema.properties?.command?.const !== 'npm run review:release-pr-candidates') {
    fail('release readiness schema current_pr.pr_candidate_review.command must be const npm run review:release-pr-candidates');
  }
  if (!currentPrCandidateReviewSchema.properties?.gh_pr_list_checked_at?.pattern) {
    fail('release readiness schema current_pr.pr_candidate_review.gh_pr_list_checked_at must have a timestamp pattern');
  }
  if (currentPrCandidateReviewSchema.properties?.source_of_truth_for_current_pr_candidates?.const !== true) {
    fail('release readiness schema current_pr.pr_candidate_review.source_of_truth_for_current_pr_candidates must be const true');
  }
  const prCandidateConnectorDiagnosticSchema = currentPrCandidateReviewSchema.properties?.connector_diagnostic || {};
  if (prCandidateConnectorDiagnosticSchema.properties?.is_stale_for_current_review?.const !== true) {
    fail('release readiness schema current_pr.pr_candidate_review.connector_diagnostic.is_stale_for_current_review must be const true');
  }
  if (prCandidateConnectorDiagnosticSchema.properties?.does_not_close_release_readiness?.const !== true) {
    fail('release readiness schema current_pr.pr_candidate_review.connector_diagnostic.does_not_close_release_readiness must be const true');
  }

  const currentPrConnectorSchema = schemaProperties.current_pr?.properties?.connector_evidence || {};
  const connectorRequired = Array.isArray(currentPrConnectorSchema.required) ? currentPrConnectorSchema.required : [];
  if (!connectorRequired.includes('checked_at')) {
    fail('release readiness schema current_pr.connector_evidence must require checked_at');
  }
  if (!currentPrConnectorSchema.properties?.checked_at?.pattern) {
    fail('release readiness schema current_pr.connector_evidence.checked_at must have a timestamp pattern');
  }
  if (schemaProperties.release_readiness_check?.properties?.status?.const !== 'failing_as_expected') {
    fail('release readiness schema release_readiness_check.status must be const failing_as_expected while blockers are open');
  }
  if (!schemaProperties.release_readiness_check?.required?.includes('result_mode')) {
    fail('release readiness schema release_readiness_check.required must include result_mode');
  }
  if (!schemaProperties.release_readiness_check?.required?.includes('final_release_ready')) {
    fail('release readiness schema release_readiness_check.required must include final_release_ready');
  }
  if (schemaProperties.release_readiness_check?.properties?.result_mode?.const !== 'final') {
    fail('release readiness schema release_readiness_check.result_mode must be const final');
  }
  if (schemaProperties.release_readiness_check?.properties?.final_release_ready?.const !== false) {
    fail('release readiness schema release_readiness_check.final_release_ready must be const false');
  }
  if (schemaProperties.release_readiness_check?.properties?.default_evidence_dir?.const !== '../release-evidence-temp') {
    fail('release readiness schema release_readiness_check.default_evidence_dir must be const ../release-evidence-temp');
  }
  if (schemaProperties.release_readiness_check?.properties?.result_file_template?.const !== 'docs/release_readiness_result.example.json') {
    fail('release readiness schema release_readiness_check.result_file_template must be const docs/release_readiness_result.example.json');
  }

  if (!schemaProperties.external_state_check?.required?.includes('result_file_template')) {
    fail('release readiness schema external_state_check.required must include result_file_template');
  } else {
    pass('release readiness schema requires external-state result file template');
  }
  if (schemaProperties.external_state_check?.properties?.command?.const !== 'npm run review:release-external-state') {
    fail('release readiness schema external_state_check.command must be const npm run review:release-external-state');
  }
  if (schemaProperties.external_state_check?.properties?.status?.const !== 'failing_as_expected') {
    fail('release readiness schema external_state_check.status must be const failing_as_expected while blockers are open');
  }
  if (schemaProperties.external_state_check?.properties?.evidence_file_template?.const !== 'docs/release_external_state_evidence.example.json') {
    fail('release readiness schema external_state_check.evidence_file_template must be const docs/release_external_state_evidence.example.json');
  }
  if (schemaProperties.external_state_check?.properties?.result_file_template?.const !== 'docs/release_external_state_result.example.json') {
    fail('release readiness schema external_state_check.result_file_template must be const docs/release_external_state_result.example.json');
  }

  if (!releaseStatusSchema.required?.includes('pr_candidate_check')) {
    fail('release readiness schema required must include pr_candidate_check');
  }
  if (schemaProperties.pr_candidate_check?.properties?.command?.const !== 'npm run review:release-pr-candidates') {
    fail('release readiness schema pr_candidate_check.command must be const npm run review:release-pr-candidates');
  }
  if (schemaProperties.pr_candidate_check?.properties?.status?.const !== 'failing_as_expected') {
    fail('release readiness schema pr_candidate_check.status must be const failing_as_expected while blockers are open');
  }
  if (schemaProperties.pr_candidate_check?.properties?.result_file?.const !== '../release-evidence-temp/release-pr-candidates-current-result.json') {
    fail('release readiness schema pr_candidate_check.result_file must be const ../release-evidence-temp/release-pr-candidates-current-result.json');
  }
  if (!releaseStatusSchema.required?.includes('staged_scope_check')) {
    fail('release readiness schema required must include staged_scope_check');
  }
  if (schemaProperties.staged_scope_check?.properties?.command?.const !== 'npm run review:release-staged-scope') {
    fail('release readiness schema staged_scope_check.command must be const npm run review:release-staged-scope');
  }
  if (schemaProperties.staged_scope_check?.properties?.status?.const !== 'passing_not_release_closure') {
    fail('release readiness schema staged_scope_check.status must be const passing_not_release_closure');
  }
  if (schemaProperties.staged_scope_check?.properties?.result_file?.const !== '../release-evidence-temp/release-staged-scope-current-result.json') {
    fail('release readiness schema staged_scope_check.result_file must be const ../release-evidence-temp/release-staged-scope-current-result.json');
  }
}

const llmAttestationExample = readJson('docs/llm_connectivity_attestation.example.json');
if (llmAttestationExample) {
  let attestationComplete = true;
  for (const key of requiredLlmAttestationKeys) {
    if (!(key in llmAttestationExample)) {
      fail(`docs/llm_connectivity_attestation.example.json is missing ${key}`);
      attestationComplete = false;
    }
  }
  if (llmAttestationExample.result?.status === 'passed') {
    fail('docs/llm_connectivity_attestation.example.json must remain a placeholder template, not a passing attestation');
    attestationComplete = false;
  }
  if (llmAttestationExample.redaction_checked !== true) {
    fail('docs/llm_connectivity_attestation.example.json redaction_checked must be true');
    attestationComplete = false;
  }
  if (attestationComplete) {
    pass('docs/llm_connectivity_attestation.example.json covers required fields');
  }
}

const otaAttestationExample = readJson('docs/ota_credential_rotation_attestation.example.json');
if (otaAttestationExample) {
  let otaAttestationComplete = true;
  for (const key of requiredOtaAttestationKeys) {
    if (!(key in otaAttestationExample)) {
      fail(`docs/ota_credential_rotation_attestation.example.json is missing ${key}`);
      otaAttestationComplete = false;
    }
  }
  if (otaAttestationExample.redaction_checked !== true) {
    fail('docs/ota_credential_rotation_attestation.example.json redaction_checked must be true');
    otaAttestationComplete = false;
  }
  if (!String(otaAttestationExample.reviewed_at || '').includes('30-day release evidence window')) {
    fail('docs/ota_credential_rotation_attestation.example.json reviewed_at must mention the 30-day release evidence window');
    otaAttestationComplete = false;
  }
  const platformActionTemplate = Array.isArray(otaAttestationExample.platforms)
    ? otaAttestationExample.platforms.map((platform) => String(platform?.action || '')).join('\n')
    : '';
  if (!/rotated\s*\|\s*invalidated/.test(platformActionTemplate)) {
    fail('docs/ota_credential_rotation_attestation.example.json platform action template must offer rotated | invalidated');
    otaAttestationComplete = false;
  }
  if (/encrypted_archive|sanitized/.test(platformActionTemplate)) {
    fail('docs/ota_credential_rotation_attestation.example.json platform action template must not offer backup cleanup actions');
    otaAttestationComplete = false;
  }
  const cleanup = otaAttestationExample.backup_cleanup || {};
  for (const key of ['database_backups_action', 'paths_reviewed', 'git_tracking_check', 'release_readiness_check']) {
    if (!(key in cleanup)) {
      fail(`docs/ota_credential_rotation_attestation.example.json backup_cleanup is missing ${key}`);
      otaAttestationComplete = false;
    }
  }
  if (otaAttestationComplete) {
    pass('docs/ota_credential_rotation_attestation.example.json covers required fields');
  }
}

const externalEvidenceExample = readJson('docs/release_external_state_evidence.example.json');
if (externalEvidenceExample) {
  let evidenceComplete = true;
  for (const key of requiredExternalEvidenceKeys) {
    if (!(key in externalEvidenceExample)) {
      fail(`docs/release_external_state_evidence.example.json is missing ${key}`);
      evidenceComplete = false;
    }
  }
  for (const commandKey of [
    'git_ls_files_database_backups',
    'git_index_lock',
    'git_status_short_branch',
    'git_rev_parse_head',
    'gh_pr_view',
  ]) {
    if (!(commandKey in (externalEvidenceExample.commands || {}))) {
      fail(`docs/release_external_state_evidence.example.json commands is missing ${commandKey}`);
      evidenceComplete = false;
    }
  }
  const prJson = externalEvidenceExample.commands?.gh_pr_view?.json || {};
  if (String(externalEvidenceExample.target_release_pr_number || '') !== String(prJson.number || '')) {
    fail('docs/release_external_state_evidence.example.json target_release_pr_number must match gh_pr_view.json.number');
    evidenceComplete = false;
  }
  if (prJson.state !== 'OPEN') {
    fail('docs/release_external_state_evidence.example.json gh_pr_view.json.state must be OPEN');
    evidenceComplete = false;
  }
  if (prJson.isDraft !== false) {
    fail('docs/release_external_state_evidence.example.json gh_pr_view.json.isDraft must be false');
    evidenceComplete = false;
  }
  const localHead = String(externalEvidenceExample.commands?.git_rev_parse_head?.stdout || '').trim();
  if (!/^[a-f0-9]{40}$/i.test(localHead)) {
    fail('docs/release_external_state_evidence.example.json git_rev_parse_head.stdout must be a 40-character commit sha');
    evidenceComplete = false;
  }
  if (localHead.toLowerCase() !== String(prJson.headRefOid || '').trim().toLowerCase()) {
    fail('docs/release_external_state_evidence.example.json local HEAD must match gh_pr_view.json.headRefOid');
    evidenceComplete = false;
  }
  if (evidenceComplete) {
    pass('docs/release_external_state_evidence.example.json covers required commands');
  }
}

const securityScanManifestExample = readJson('docs/codex_security_scan_manifest.example.json');
if (securityScanManifestExample) {
  let manifestComplete = true;
  for (const key of requiredSecurityScanManifestKeys) {
    if (!(key in securityScanManifestExample)) {
      fail(`docs/codex_security_scan_manifest.example.json is missing ${key}`);
      manifestComplete = false;
    }
  }
  const phases = securityScanManifestExample.phases || {};
  for (const phase of ['threat_model', 'finding_discovery', 'validation', 'attack_path_analysis', 'final_report']) {
    if (phases[phase] !== 'completed') {
      fail(`docs/codex_security_scan_manifest.example.json phases.${phase} must be completed`);
      manifestComplete = false;
    }
  }
  if (securityScanManifestExample.scan_mode !== 'repository-wide') {
    fail('docs/codex_security_scan_manifest.example.json scan_mode must be repository-wide');
    manifestComplete = false;
  }
  if (securityScanManifestExample.subagents_authorized !== true) {
    fail('docs/codex_security_scan_manifest.example.json subagents_authorized must be true');
    manifestComplete = false;
  }
  if (securityScanManifestExample.final_report_validated !== true || securityScanManifestExample.report_html_rendered !== true) {
    fail('docs/codex_security_scan_manifest.example.json must confirm final report validation and HTML rendering');
    manifestComplete = false;
  }
  if (manifestComplete) {
    pass('docs/codex_security_scan_manifest.example.json covers required fields');
  }
}

const readinessResultExample = readJson('docs/release_readiness_result.example.json');
if (readinessResultExample) {
  let resultComplete = true;
  for (const key of requiredReadinessResultKeys) {
    if (!(key in readinessResultExample)) {
      fail(`docs/release_readiness_result.example.json is missing ${key}`);
      resultComplete = false;
    }
  }
  if (readinessResultExample.command !== 'npm run review:release-readiness') {
    fail('docs/release_readiness_result.example.json command must be npm run review:release-readiness');
    resultComplete = false;
  }
  if (readinessResultExample.mode !== 'final') {
    fail('docs/release_readiness_result.example.json mode must be final');
    resultComplete = false;
  }
  if (readinessResultExample.final_release_ready !== false) {
    fail('docs/release_readiness_result.example.json final_release_ready must remain false while blockers are open');
    resultComplete = false;
  }
  if (!Array.isArray(readinessResultExample.failures) || readinessResultExample.failures.length < requiredOpenFailurePatterns.length) {
    fail(`docs/release_readiness_result.example.json failures must include at least ${requiredOpenFailurePatterns.length} entries`);
    resultComplete = false;
  }
  if (readinessResultExample.summary?.passed !== 14) {
    fail('docs/release_readiness_result.example.json summary.passed must match the current 14 release-readiness passes');
    resultComplete = false;
  }
  if (readinessResultExample.summary?.warnings !== 4) {
    fail('docs/release_readiness_result.example.json summary.warnings must match the current 4 release-readiness warnings');
    resultComplete = false;
  }
  if (readinessResultExample.summary?.failures !== 4) {
    fail('docs/release_readiness_result.example.json summary.failures must match the current 4 release-readiness failures');
    resultComplete = false;
  }
  const readinessPasses = Array.isArray(readinessResultExample.passes) ? readinessResultExample.passes.join('\n') : '';
  if (!/GitHub Actions workflow includes dependency audits, PHPUnit, P0 guards, functional readiness, release issue register, release evidence intake behavior guard, release readiness behavior guard, non-security review, report\/security\/finance regression review, and release-status contracts\./.test(readinessPasses)) {
    fail('docs/release_readiness_result.example.json passes must include the current GitHub Actions workflow coverage pass');
    resultComplete = false;
  }
  if (!/Release staged-scope result has no forbidden staged files/.test(readinessPasses)) {
    fail('docs/release_readiness_result.example.json passes must include the staged-scope gate pass');
    resultComplete = false;
  }
  const readinessWarnings = Array.isArray(readinessResultExample.warnings) ? readinessResultExample.warnings.join('\n') : '';
  if (!/Release staged-scope warning: This does not prove the local worktree is clean and does not close the release external-state gate\./.test(readinessWarnings)) {
    fail('docs/release_readiness_result.example.json warnings must include the staged-scope non-closure warning');
    resultComplete = false;
  }
  const readinessFailures = Array.isArray(readinessResultExample.failures) ? readinessResultExample.failures.join('\n') : '';
  if (!/Release PR candidate gate has not passed/i.test(readinessFailures)) {
    fail('docs/release_readiness_result.example.json failures must include the PR candidate gate failure');
    resultComplete = false;
  }
  assertArrayContainsPatterns(
    readinessResultExample.failures,
    requiredOpenFailurePatterns,
    'docs/release_readiness_result.example.json failures',
  );
  if (resultComplete) {
    pass('docs/release_readiness_result.example.json covers required fields');
  }
}

const externalStateResultExample = readJson('docs/release_external_state_result.example.json');
if (externalStateResultExample) {
  let resultComplete = true;
  for (const key of requiredExternalStateResultKeys) {
    if (!(key in externalStateResultExample)) {
      fail(`docs/release_external_state_result.example.json is missing ${key}`);
      resultComplete = false;
    }
  }
  if (externalStateResultExample.command !== 'npm run review:release-external-state') {
    fail('docs/release_external_state_result.example.json command must be npm run review:release-external-state');
    resultComplete = false;
  }
  if (!Array.isArray(externalStateResultExample.failures) || externalStateResultExample.failures.length < requiredExternalStateFailurePatterns.length) {
    fail(`docs/release_external_state_result.example.json failures must include at least ${requiredExternalStateFailurePatterns.length} entries`);
    resultComplete = false;
  }
  assertArrayContainsPatterns(
    externalStateResultExample.failures,
    requiredExternalStateFailurePatterns,
    'docs/release_external_state_result.example.json failures',
  );
  if (typeof externalStateResultExample.expected_release_pr_number === 'undefined') {
    fail('docs/release_external_state_result.example.json must include expected_release_pr_number');
    resultComplete = false;
  }
  if (typeof externalStateResultExample.expected_local_head_sha === 'undefined') {
    fail('docs/release_external_state_result.example.json must include expected_local_head_sha');
    resultComplete = false;
  }
  if (typeof externalStateResultExample.expected_release_pr_head_sha === 'undefined') {
    fail('docs/release_external_state_result.example.json must include expected_release_pr_head_sha');
    resultComplete = false;
  }
  const externalStateFailuresText = Array.isArray(externalStateResultExample.failures) ? externalStateResultExample.failures.join('\n') : '';
  if (!externalStateFailuresText.includes('RELEASE_PR_NUMBER is required')) {
    fail('docs/release_external_state_result.example.json failures must include the missing RELEASE_PR_NUMBER failure');
    resultComplete = false;
  }
  if (Number(externalStateResultExample.summary?.passed || 0) < 5) {
    fail('docs/release_external_state_result.example.json summary.passed must include external-state metadata, local backup/index checks, and local HEAD capture');
    resultComplete = false;
  }
  const statusDiagnostics = externalStateResultExample.diagnostics || {};
  if (!Array.isArray(statusDiagnostics.git_status_changed_entries)) {
    fail('docs/release_external_state_result.example.json diagnostics.git_status_changed_entries must be an array');
    resultComplete = false;
  }
  if (typeof statusDiagnostics.git_status_changed_summary?.total !== 'number') {
    fail('docs/release_external_state_result.example.json diagnostics.git_status_changed_summary.total must be numeric');
    resultComplete = false;
  }
  if (!statusDiagnostics.git_status_changed_summary?.by_category?.['release-docs']) {
    fail('docs/release_external_state_result.example.json diagnostics.git_status_changed_summary.by_category must include release-docs');
    resultComplete = false;
  }
  if (resultComplete) {
    pass('docs/release_external_state_result.example.json covers required fields');
  }
}

const designManifestExample = readJson('docs/design_handoff_manifest.example.json');
  if (designManifestExample) {
  let manifestComplete = true;
  for (const key of requiredDesignManifestKeys) {
    if (!(key in designManifestExample)) {
      fail(`docs/design_handoff_manifest.example.json is missing ${key}`);
      manifestComplete = false;
    }
  }
  for (const flow of [
    'login',
    'ota-data',
    'revenue-analysis',
    'ai-decision',
    'operations-management',
    'investment-decision',
  ]) {
    if (!designManifestExample.covered_flows?.includes(flow)) {
      fail(`docs/design_handoff_manifest.example.json covered_flows is missing ${flow}`);
      manifestComplete = false;
    }
  }
  if (!Array.isArray(designManifestExample.open_issues) || designManifestExample.open_issues.length !== 0) {
    fail('docs/design_handoff_manifest.example.json open_issues must be an empty array');
    manifestComplete = false;
  }
  if (!String(designManifestExample.last_reviewed_at || '').includes('30-day release evidence window')) {
    fail('docs/design_handoff_manifest.example.json last_reviewed_at must mention the 30-day release evidence window');
    manifestComplete = false;
  }
  const sourceReview = designManifestExample.source_review || {};
  for (const key of [
    'review_method',
    'evidence_ref',
    'figma_source_verified',
    'canva_source_verified',
    'brand_kit_source_verified',
    'design_tokens_reviewed',
    'required_flows_reviewed',
  ]) {
    if (!(key in sourceReview)) {
      fail(`docs/design_handoff_manifest.example.json source_review is missing ${key}`);
      manifestComplete = false;
    }
  }
  const designTokensPath = String(designManifestExample.design_tokens_path || '').trim();
  if (!designTokensPath || path.isAbsolute(designTokensPath) || !fs.existsSync(path.join(root, designTokensPath))) {
    fail('docs/design_handoff_manifest.example.json design_tokens_path must point to an existing repo-relative file');
    manifestComplete = false;
  }
  if (manifestComplete) {
    pass('docs/design_handoff_manifest.example.json covers required fields and flows');
  }
}

const figmaHandoffEvidence = readJson('docs/release_figma_handoff_evidence.json');
if (figmaHandoffEvidence) {
  const connector = figmaHandoffEvidence.latest_connector_check || {};
  if (!checkedAtPattern.test(String(connector.checked_at || ''))) {
    fail('docs/release_figma_handoff_evidence.json must record latest_connector_check.checked_at as a timestamp');
  }
  if (checkedAtDate(connector.checked_at) > releaseStatusDate) {
    fail('docs/release_figma_handoff_evidence.json latest connector check date must not be later than docs/release_readiness_status.json updated_at');
  }
  if (connector.result !== 'blocked' || connector.error_code !== 'UNAUTHORIZED' || !/reauthentication/i.test(String(connector.reason || ''))) {
    fail('docs/release_figma_handoff_evidence.json must record the current Figma connector reauthentication blocker');
  } else {
    pass('docs/release_figma_handoff_evidence.json records the Figma connector reauthentication blocker');
  }
  if (
    connector.error_data?.action !== 'TRIGGER_REAUTHENTICATION'
    || !/www_authenticate_reauth|reauthentication/i.test(String(connector.error_data?.reason || connector.error_data?.detail || ''))
  ) {
    fail('docs/release_figma_handoff_evidence.json must preserve Figma connector reauthentication error_data');
  }
  if (figmaHandoffEvidence.does_not_close_release_design_gate !== true) {
    fail('docs/release_figma_handoff_evidence.json must not claim to close the release design gate');
  }
}

const canvaHandoffEvidence = readJson('docs/release_canva_handoff_evidence.json');
if (canvaHandoffEvidence) {
  const connectorChecks = Array.isArray(canvaHandoffEvidence.latest_connector_checks)
    ? canvaHandoffEvidence.latest_connector_checks
    : [];
  if (connectorChecks.length === 0 || connectorChecks.some((entry) => !checkedAtPattern.test(String(entry?.checked_at || '')))) {
    fail('docs/release_canva_handoff_evidence.json must record checked_at for every latest_connector_checks entry');
  }
  if (connectorChecks.some((entry) => checkedAtDate(entry?.checked_at) > releaseStatusDate)) {
    fail('docs/release_canva_handoff_evidence.json latest connector check dates must not be later than docs/release_readiness_status.json updated_at');
  }
  const connectorText = connectorChecks.map((entry) => [
    entry?.tool,
    entry?.result,
    entry?.error_code,
    entry?.reason,
  ].join(' ')).join('\n');
  if (!/blocked/i.test(connectorText) || !/UNAUTHORIZED/i.test(connectorText) || !/oauth_token_invalid_grant|reauthentication/i.test(connectorText)) {
    fail('docs/release_canva_handoff_evidence.json must record the current Canva connector reauthentication blocker');
  } else {
    pass('docs/release_canva_handoff_evidence.json records the Canva connector reauthentication blocker');
  }
  if (!connectorChecks.some((entry) => entry?.tool === 'mcp__codex_apps__canva._list_comments')) {
    fail('docs/release_canva_handoff_evidence.json must include the current Canva comments recheck blocker');
  }
  if (canvaHandoffEvidence.comments_reviewed !== false || !/cannot currently revalidate comments/i.test(String(canvaHandoffEvidence.comments_source || ''))) {
    fail('docs/release_canva_handoff_evidence.json must not present historical Canva comments review as current connector evidence');
  }
  const canvaMissingErrorData = connectorChecks.some((entry) => (
    entry?.error_data?.action !== 'TRIGGER_REAUTHENTICATION'
    || !/oauth_token_invalid_grant|reauthentication/i.test(String(entry?.error_data?.reason || entry?.error_data?.detail || ''))
  ));
  if (canvaMissingErrorData) {
    fail('docs/release_canva_handoff_evidence.json must preserve Canva connector reauthentication error_data');
  }
  if (canvaHandoffEvidence.does_not_close_release_design_gate !== true) {
    fail('docs/release_canva_handoff_evidence.json must not claim to close the release design gate');
  }
}

const githubHandoffEvidence = readJson('docs/release_github_handoff_evidence.json');
if (githubHandoffEvidence) {
  const connector = githubHandoffEvidence.latest_connector_check || {};
  if (!checkedAtPattern.test(String(githubHandoffEvidence.checked_at || '')) || !checkedAtPattern.test(String(connector.checked_at || ''))) {
    fail('docs/release_github_handoff_evidence.json must record checked_at at the document and latest_connector_check levels');
  }
  if (checkedAtDate(githubHandoffEvidence.checked_at) > releaseStatusDate || checkedAtDate(connector.checked_at) > releaseStatusDate) {
    fail('docs/release_github_handoff_evidence.json checked_at dates must not be later than docs/release_readiness_status.json updated_at');
  }
  const statusPrConnectorDiagnostic = status?.current_pr?.pr_candidate_review?.connector_diagnostic || {};
  if (
    statusPrConnectorDiagnostic.path !== 'docs/release_github_handoff_evidence.json'
    || statusPrConnectorDiagnostic.checked_at !== connector.checked_at
    || typeof statusPrConnectorDiagnostic.is_stale_for_current_review !== 'boolean'
    || statusPrConnectorDiagnostic.does_not_close_release_readiness !== true
  ) {
    fail('docs/release_github_handoff_evidence.json must be represented as non-closing connector_diagnostic in docs/release_readiness_status.json');
  }
  if (
    connector.result !== 'blocked'
    || connector.tool !== 'mcp__codex_apps__github._get_users_recent_prs_in_repo'
    || connector.state !== 'open'
    || Number(connector.pull_requests_count) !== 0
    || !/no open final release PR|empty pull_requests/i.test(String(connector.reason || ''))
  ) {
    fail('docs/release_github_handoff_evidence.json must record the current GitHub open-PR connector blocker');
  } else {
    pass('docs/release_github_handoff_evidence.json records the GitHub open-PR connector blocker');
  }
  if (githubHandoffEvidence.does_not_close_release_external_state_gate !== true || githubHandoffEvidence.does_not_close_release_readiness !== true) {
    fail('docs/release_github_handoff_evidence.json must not claim to close external-state or release readiness');
  }
}

try {
  assertTextContainsPatterns(
    readText('docs/codex_security_scan_authorization.md'),
    requiredSecurityScanPatterns,
    'docs/codex_security_scan_authorization.md',
  );
} catch (error) {
  fail(`could not read Codex Security authorization doc: ${error.message}`);
}

try {
  assertTextContainsPatterns(
    readText('docs/release_evidence_bundle_intake.md'),
    [
      /review:release-evidence/,
      /DESIGN_HANDOFF_MANIFEST_FILE/,
      /OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE/,
      /30-day release evidence window/,
      /review:release-readiness/,
    ],
    'docs/release_evidence_bundle_intake.md freshness and command guidance',
  );

  const evidenceCollection = readText('docs/release_evidence_collection.zh-CN.md');
  for (const phrase of [
    'npm run refresh:release-current-evidence',
    'npm run verify:release-evidence-gap-pack',
    'npm run verify:release-operator-intake',
    '../release-evidence-temp/release-operator-intake-packet-current.md',
    'operator_intake_packet.required_external_inputs',
    'design_handoff_manifest',
    'ota_credential_rotation_attestation',
    'final_release_pr_and_local_state',
    'worktree_staging_summary.bucket_counts',
    'candidate_release_scope',
    'needs_explicit_operator_decision',
    'must_remain_local_by_default',
    '30-day release evidence window',
    '不关闭 `npm run review:release-readiness`',
  ]) {
    if (!evidenceCollection.includes(phrase)) {
      fail(`release_evidence_collection.zh-CN.md must mention current operator intake boundary: ${phrase}`);
    }
  }
} catch (error) {
  fail(`could not read release evidence collection checklist: ${error.message}`);
}

try {
  const report = readText('docs/release_readiness_remaining_issues.md');
  if (!report.includes('docs/release_readiness_status.json')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_readiness_status.json');
  }
  if (!report.includes('docs/release_functional_acceptance_matrix.md')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_functional_acceptance_matrix.md');
  }
  if (!report.includes('docs/release_issue_register.md')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_issue_register.md');
  }
  if (!report.includes('docs/release_problem_report.zh-CN.md')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_problem_report.zh-CN.md');
  }
  if (!report.includes('docs/release_evidence_collection.zh-CN.md')) {
    fail('release_readiness_remaining_issues.md must reference docs/release_evidence_collection.zh-CN.md');
  }
  if (!report.includes('mode=final') || !report.includes('final_release_ready=true')) {
    fail('release_readiness_remaining_issues.md must distinguish final readiness results from pre-ready results');
  }
  if (!report.includes('npm run review:functional-readiness')) {
    fail('release_readiness_remaining_issues.md must mention npm run review:functional-readiness');
  }
  if (!report.includes('4 release-readiness failures')) {
    fail('release_readiness_remaining_issues.md must state the current 4 release-readiness failures');
  }
  if (report.includes('3 release-evidence failures')) {
    fail('release_readiness_remaining_issues.md must not use the stale 3 release-evidence failure count');
  }
  if (report.includes('2 release-evidence failures')) {
    fail('release_readiness_remaining_issues.md must not use the stale 2 release-evidence failure count');
  }
  if (report.includes('4 release-evidence failures')) {
    fail('release_readiness_remaining_issues.md must not use the stale 4 release-evidence failure count');
  }
  if (report.includes('5 release-evidence failures')) {
    fail('release_readiness_remaining_issues.md must not use the stale 5 release-evidence failure count');
  }
  if (report.includes('6 direct release-evidence failures')) {
    fail('release_readiness_remaining_issues.md must not use the stale 6 direct release-evidence failure count');
  }
  if (!report.includes('Supported platform credential actions are only `rotated` or `invalidated`')) {
    fail('release_readiness_remaining_issues.md must restrict platform credential actions to rotated or invalidated');
  }
  if (!report.includes('`encrypted_archive` and `sanitized` are backup cleanup actions only')) {
    fail('release_readiness_remaining_issues.md must keep encrypted_archive and sanitized under backup cleanup only');
  }
  if (report.includes('Supported platform actions are `rotated`, `invalidated`, `encrypted_archive`, or `sanitized`')) {
    fail('release_readiness_remaining_issues.md must not list backup cleanup actions as supported platform actions');
  }
  if (report.includes('`platforms[].action`: one of `rotated`, `invalidated`, `encrypted_archive`, or `sanitized`')) {
    fail('release_readiness_remaining_issues.md must not list encrypted_archive or sanitized in platforms[].action');
  }
  assertTextContainsPatterns(
    report,
    requiredReportBlockerPatterns,
    'docs/release_readiness_remaining_issues.md',
  );
  for (const plugin of requiredScope) {
    if (!report.includes(plugin)) {
      fail(`release_readiness_remaining_issues.md must mention ${plugin}`);
    }
  }
} catch (error) {
  fail(`could not read release readiness report: ${error.message}`);
}

try {
  const closePlan = readText('docs/release_blocker_close_plan.md');
  if (!closePlan.includes('docs/release_verification_command_matrix.md')) {
    fail('release_blocker_close_plan.md must reference docs/release_verification_command_matrix.md');
  }
  for (const id of requiredBlockerIds) {
    if (!closePlan.includes(id)) {
      fail(`release_blocker_close_plan.md must mention ${id}`);
    }
  }
  for (const command of [
    'npm run review:release-env',
    'npm run review:release-llm',
    'npm run review:release-design',
    'npm run review:release-ota-credentials',
    'npm run review:release-security-scan',
    'npm run review:release-evidence',
    'npm run review:release-evidence-drafts',
    'npm run review:release-readiness',
    'npm run review:release-pr-candidates',
    'npm run review:release-staged-scope',
    'npm run review:release-external-state',
    'LLM_CONNECTIVITY_ATTESTATION_FILE',
    'DESIGN_HANDOFF_MANIFEST_FILE',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'RELEASE_EVIDENCE_RESULT_FILE',
    'CODEX_SECURITY_SCAN_DIR',
    'mode=pre_ready',
    'docs/design_handoff_manifest.json',
    'scripts/collect_release_external_state.ps1',
  ]) {
    if (!closePlan.includes(command)) {
      fail(`release_blocker_close_plan.md must mention ${command}`);
    }
  }
} catch (error) {
  fail(`could not read release blocker close plan: ${error.message}`);
}

try {
  const matrix = readText('docs/release_verification_command_matrix.md');
  for (const id of requiredBlockerIds) {
    if (!matrix.includes(id)) {
      fail(`release_verification_command_matrix.md must mention ${id}`);
    }
  }
  for (const command of requiredVerificationMatrixCommands) {
    if (!matrix.includes(command)) {
      fail(`release_verification_command_matrix.md must mention ${command}`);
    }
  }
  for (const evidenceRef of [
    'RELEASE_ENV_FILE',
    'LLM_CONNECTIVITY_ATTESTATION_FILE',
    'DESIGN_HANDOFF_MANIFEST_FILE',
    'docs/design_handoff_manifest.json',
    'OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE',
    'CODEX_SECURITY_SCAN_DIR',
    'docs/release_external_state_evidence.local.json',
  ]) {
    if (!matrix.includes(evidenceRef)) {
      fail(`release_verification_command_matrix.md must mention ${evidenceRef}`);
    }
  }
  if (!matrix.includes('Do not mark a blocker closed from narrative evidence alone')) {
    fail('release_verification_command_matrix.md must require command-based closure');
  }
  if (!matrix.includes('Do not store real keys')) {
    fail('release_verification_command_matrix.md must prohibit secret-bearing evidence');
  }
} catch (error) {
  fail(`could not read release verification command matrix: ${error.message}`);
}

try {
  const finalHandoff = readText('docs/formal_release_final_handoff.md');
  for (const phrase of [
    'Do not reuse merged PR #2',
    'review:release-pr-candidates',
    'release-pr-ready-candidates-result.json',
    'RELEASE_PR_CANDIDATES_ALLOW_DRAFT=1',
    'release-pr-candidates-result.json',
    'RELEASE_PR_CANDIDATES_RESULT_FILE',
    'release-staged-scope-result.json',
    'RELEASE_STAGED_SCOPE_RESULT_FILE',
    'npm.cmd run release:continue-handoff',
    'selected_release_pr_number',
    '-MarkPrReady',
    '-AfterPrReady',
    'npm.cmd run review:release-pr-candidates',
    'npm.cmd run review:release-staged-scope',
    'external-state explicitly pending',
    'This transition does not close release readiness',
    'review:release-pr-candidates` passes and selects the same final release PR',
    'review:release-staged-scope` passes and rejects runtime/local staged files',
  ]) {
    if (!finalHandoff.includes(phrase)) {
      fail(`formal_release_final_handoff.md must mention ${phrase}`);
    }
  }
} catch (error) {
  fail(`could not read formal release final handoff doc: ${error.message}`);
}

try {
  const functionalMatrix = readText('docs/release_functional_acceptance_matrix.md');
  for (const phrase of [
    'OTA channel data',
    'Revenue analysis',
    'AI decision',
    'Operations management',
    'Investment decision',
    '@github',
    '@openai-developers',
    '@codex-security',
    '@figma',
    '@canva',
    'does not close the external release blockers',
    'npm run review:functional-readiness',
    'npm run test:e2e:business',
  ]) {
    if (!functionalMatrix.includes(phrase)) {
      fail(`release_functional_acceptance_matrix.md must mention ${phrase}`);
    }
  }
} catch (error) {
  fail(`could not read release functional acceptance matrix: ${error.message}`);
}

try {
  const issueRegister = readText('docs/release_issue_register.md');
  for (const id of requiredBlockerIds) {
    if (!issueRegister.includes(id)) {
      fail(`release_issue_register.md must mention ${id}`);
    }
  }
  for (const scope of requiredScope) {
    if (!issueRegister.includes(scope)) {
      fail(`release_issue_register.md must mention ${scope}`);
    }
  }
  for (const command of [
    'npm run review:release-env',
    'npm run review:release-llm',
    'npm run review:release-design',
    'npm run review:release-ota-credentials',
    'npm run review:release-security-scan',
    'npm run review:release-pr-candidates',
    'npm run review:release-staged-scope',
    'npm run review:release-external-state',
    'npm run review:release-readiness',
    'npm run review:functional-readiness',
    'npm run verify:release-status',
  ]) {
    if (!issueRegister.includes(command)) {
      fail(`release_issue_register.md must mention ${command}`);
    }
  }
  for (const phrase of [
    'Status: not release-ready',
    '.git/index.lock',
    'Do not mark any issue closed from narrative evidence alone',
    'Do not delete or sanitize local backup files without explicit operator approval',
  ]) {
    if (!issueRegister.includes(phrase)) {
      fail(`release_issue_register.md must include ${phrase}`);
    }
  }
} catch (error) {
  fail(`could not read release issue register: ${error.message}`);
}

if (issues.length > 0) {
  console.error('Release status contract failed:');
  for (const issue of issues) {
    console.error(`- ${issue}`);
  }
  process.exit(1);
}

console.log(`Release status contract passed (${passes.length} structural checks).`);
