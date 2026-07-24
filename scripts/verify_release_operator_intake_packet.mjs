import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { safeJsonParseErrorCode } from './lib/safe_json_parse_error.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const packetPath = process.env.RELEASE_OPERATOR_INTAKE_PACKET_FILE
  ? resolveInputPath(process.env.RELEASE_OPERATOR_INTAKE_PACKET_FILE)
  : evidencePath('release-operator-intake-packet.json');
const markdownPath = process.env.RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE
  ? resolveInputPath(process.env.RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE)
  : evidencePath('release-operator-intake-packet.md');

const passes = [];
const failures = [];

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function evidencePath(fileName) {
  return path.join(evidenceDir, fileName);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(filePath);
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function addPass(message) {
  passes.push(message);
}

function addFailure(message) {
  failures.push(message);
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8').replace(/^\uFEFF/, ''));
}

function requireString(value, label) {
  if (!String(value || '').trim()) {
    addFailure(`${label} is required.`);
    return '';
  }
  return String(value).trim();
}

function requireOutsideRepoPath(filePath, label) {
  const value = requireString(filePath, label);
  if (!value) {
    return;
  }
  if (isPathInsideRepo(value)) {
    addFailure(`${label} must be outside the repository: ${value}`);
  }
}

function inputById(inputs, id) {
  return inputs.find((input) => input?.id === id) || null;
}

function assertMarkdownContains(markdown, phrase) {
  if (!markdown.includes(phrase)) {
    addFailure(`Operator intake Markdown is missing required phrase: ${phrase}`);
  }
}

function assertPrCandidateReview(review, label) {
  if (!review || typeof review !== 'object') {
    addFailure(`${label} is required.`);
    return;
  }
  const status = String(review.status || '');
  if (!['failed', 'passed'].includes(status)) {
    addFailure(`${label}.status must be failed or passed.`);
  }
  if (status === 'passed' && Array.isArray(review.failures) && review.failures.length > 0) {
    addFailure(`${label}.failures must be empty when status is passed.`);
  }
  if (!String(review.gh_pr_list_checked_at || '').trim()) {
    addFailure(`${label}.gh_pr_list_checked_at is required.`);
  }
  if (!Number.isFinite(Number(review.gh_pr_list_open_pr_count))) {
    addFailure(`${label}.gh_pr_list_open_pr_count must be numeric.`);
  }
  if (review.does_not_close_release_readiness !== true) {
    addFailure(`${label}.does_not_close_release_readiness must be true.`);
  }
  const diagnostic = review.github_connector_diagnostic || null;
  if (!diagnostic || typeof diagnostic !== 'object') {
    addFailure(`${label}.github_connector_diagnostic is required.`);
    return;
  }
  if (diagnostic.path !== 'docs/release_github_handoff_evidence.json') {
    addFailure(`${label}.github_connector_diagnostic.path must reference docs/release_github_handoff_evidence.json.`);
  }
  if (typeof diagnostic.is_stale_for_current_review !== 'boolean') {
    addFailure(`${label}.github_connector_diagnostic.is_stale_for_current_review must be boolean.`);
  }
  if (diagnostic.does_not_close_release_readiness !== true) {
    addFailure(`${label}.github_connector_diagnostic.does_not_close_release_readiness must be true.`);
  }
}

if (isPathInsideRepo(packetPath)) {
  addFailure(`Release operator intake packet must be outside the repository: ${packetPath}`);
}
if (isPathInsideRepo(markdownPath)) {
  addFailure(`Release operator intake Markdown must be outside the repository: ${markdownPath}`);
}
if (path.resolve(packetPath) === path.resolve(markdownPath)) {
  addFailure('Release operator intake packet JSON and Markdown paths must be different.');
}

let payload = null;
let markdown = '';
try {
  payload = readJson(packetPath);
  addPass(`Release operator intake packet is readable JSON: ${packetPath}`);
} catch (error) {
  addFailure(`Release operator intake packet is not readable JSON (${safeJsonParseErrorCode(error)}).`);
}

try {
  markdown = fs.readFileSync(markdownPath, 'utf8');
  addPass(`Release operator intake Markdown is readable: ${markdownPath}`);
} catch (error) {
  addFailure(`Release operator intake Markdown is not readable: ${error.message}`);
}

if (payload) {
  if (payload.command !== 'npm run export:release-operator-intake') {
    addFailure('Release operator intake packet command must be npm run export:release-operator-intake.');
  }
  if (payload.release_ready !== false || payload.does_not_close_release_readiness !== true) {
    addFailure('Release operator intake packet must record release_ready=false and does_not_close_release_readiness=true.');
  } else {
    addPass('Release operator intake packet explicitly does not close release readiness.');
  }
  requireOutsideRepoPath(payload.source_gap_pack, 'source_gap_pack');
  requireOutsideRepoPath(payload.evidence_dir, 'evidence_dir');

  const packet = payload.operator_intake_packet || null;
  if (!packet || typeof packet !== 'object') {
    addFailure('Release operator intake packet is missing operator_intake_packet.');
  } else {
    if (packet.does_not_close_release_readiness !== true) {
      addFailure('operator_intake_packet must not close release readiness.');
    }
    const requiredInputs = Array.isArray(packet.required_external_inputs)
      ? packet.required_external_inputs
      : [];
    if (requiredInputs.length === 0) {
      addFailure('operator_intake_packet.required_external_inputs is required.');
    }
    const designInput = inputById(requiredInputs, 'design_handoff_manifest');
    const otaInput = inputById(requiredInputs, 'ota_credential_rotation_attestation');
    const prInput = inputById(requiredInputs, 'final_release_pr_and_local_state');

    if (!designInput) {
      addFailure('operator_intake_packet is missing design_handoff_manifest input.');
    } else {
      requireOutsideRepoPath(designInput.required_file, 'design_handoff_manifest.required_file');
      if (designInput.creation_command !== 'npm run release:create-design-manifest') {
        addFailure('design_handoff_manifest creation_command must be npm run release:create-design-manifest.');
      }
      if (designInput.isolated_review_command !== 'npm run review:release-design') {
        addFailure('design_handoff_manifest isolated_review_command must be npm run review:release-design.');
      }
    }

    if (!otaInput) {
      addFailure('operator_intake_packet is missing ota_credential_rotation_attestation input.');
    } else {
      requireOutsideRepoPath(otaInput.required_file, 'ota_credential_rotation_attestation.required_file');
      if (otaInput.creation_command !== 'npm run release:create-ota-attestation') {
        addFailure('ota_credential_rotation_attestation creation_command must be npm run release:create-ota-attestation.');
      }
      if (otaInput.isolated_review_command !== 'npm run review:release-ota-credentials') {
        addFailure('ota_credential_rotation_attestation isolated_review_command must be npm run review:release-ota-credentials.');
      }
    }

    if (!prInput) {
      addFailure('operator_intake_packet is missing final_release_pr_and_local_state input.');
    } else {
      requireOutsideRepoPath(prInput.required_result_file, 'final_release_pr_and_local_state.required_result_file');
      if (prInput.selection_command !== 'npm run review:release-pr-candidates') {
        addFailure('final_release_pr_and_local_state selection_command must be npm run review:release-pr-candidates.');
      }
      if (prInput.isolated_review_command !== 'npm run review:release-external-state') {
        addFailure('final_release_pr_and_local_state isolated_review_command must be npm run review:release-external-state.');
      }
    }

    const finalPrHead = packet.final_pr_head_status || null;
    if (!finalPrHead || typeof finalPrHead !== 'object') {
      addFailure('operator_intake_packet.final_pr_head_status is required.');
    } else {
      for (const field of [
        'configured_release_pr_number',
        'selected_release_pr_number',
        'selected_release_pr_head_sha',
        'external_state_local_head_sha',
        'external_state_pr_head_sha',
        'close_condition',
      ]) {
        if (!(field in finalPrHead)) {
          addFailure(`final_pr_head_status is missing ${field}.`);
        }
      }
      if (finalPrHead.does_not_close_release_readiness !== true) {
        addFailure('final_pr_head_status must not close release readiness.');
      }
      const closeCondition = String(finalPrHead.close_condition || '');
      for (const phrase of [
        'review:release-pr-candidates',
        'review:release-external-state',
        'local HEAD',
      ]) {
        if (!closeCondition.includes(phrase)) {
          addFailure(`final_pr_head_status.close_condition must mention ${phrase}.`);
        }
      }
    }

    const worktreeSummary = packet.worktree_staging_summary || null;
    if (!worktreeSummary || typeof worktreeSummary !== 'object') {
      addFailure('operator_intake_packet.worktree_staging_summary is required.');
    } else {
      if (worktreeSummary.does_not_close_release_readiness !== true) {
        addFailure('worktree_staging_summary must not close release readiness.');
      }
      const bucketCounts = worktreeSummary.bucket_counts || {};
      for (const bucket of [
        'candidate_release_scope',
        'needs_explicit_operator_decision',
        'must_remain_local_by_default',
      ]) {
        if (!(bucket in bucketCounts)) {
          addFailure(`worktree_staging_summary.bucket_counts is missing ${bucket}.`);
        }
      }
    }

    const connector = packet.current_pr_status?.connector_evidence || payload.current_pr_status?.connector_evidence || null;
    if (!connector) {
      addFailure('current_pr_status.connector_evidence is required.');
    } else {
      if (connector.path !== 'docs/release_github_handoff_evidence.json') {
        addFailure('current_pr_status.connector_evidence.path must reference docs/release_github_handoff_evidence.json.');
      }
      if (connector.tool !== 'mcp__codex_apps__github._get_users_recent_prs_in_repo') {
        addFailure('current_pr_status.connector_evidence.tool must record the GitHub recent PR connector.');
      }
      if (connector.state !== 'open') {
        addFailure('current_pr_status.connector_evidence.state must be open.');
      }
    }
    assertPrCandidateReview(
      packet.current_pr_status?.pr_candidate_review || payload.current_pr_status?.pr_candidate_review,
      'current_pr_status.pr_candidate_review',
    );

    const forbiddenClosure = Array.isArray(packet.forbidden_closure) ? packet.forbidden_closure.join('\n') : '';
    for (const phrase of ['template', 'draft', 'gap pack', 'release-readiness']) {
      if (!forbiddenClosure.toLowerCase().includes(phrase)) {
        addFailure(`forbidden_closure must mention ${phrase}.`);
      }
    }
  }
}

if (markdown) {
  for (const phrase of [
    'Release Operator Intake Packet',
    'external-evidence intake checklist only',
    'Current PR Handoff Evidence',
    'Current gh PR checked at',
    'Current gh open PR count',
    'Connector stale for current review',
    'Configured release PR number',
    'Selected release PR number',
    'Worktree Staging/Isolation Summary',
    'Required operator-controlled inputs',
    'Forbidden inputs or closure shortcuts',
    'does not replace the final design manifest',
  ]) {
    assertMarkdownContains(markdown, phrase);
  }
}

for (const message of passes) {
  console.log(`PASS: ${message}`);
}
for (const message of failures) {
  console.error(`FAIL: ${message}`);
}

console.log(`Release operator intake packet verification summary: ${passes.length} passed, ${failures.length} failures.`);

if (failures.length > 0) {
  process.exit(1);
}
