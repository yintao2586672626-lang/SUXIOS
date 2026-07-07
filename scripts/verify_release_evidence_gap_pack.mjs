import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const gapPackPath = process.env.RELEASE_EVIDENCE_GAP_PACK_FILE
  ? resolveInputPath(process.env.RELEASE_EVIDENCE_GAP_PACK_FILE)
  : evidencePath('release-evidence-gap-pack.json');
const markdownPath = process.env.RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE
  ? resolveInputPath(process.env.RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE)
  : evidencePath('release-evidence-gap-pack.md');

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

function requireObject(value, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    addFailure(`${label} is required.`);
    return null;
  }
  return value;
}

function requireArray(value, label) {
  if (!Array.isArray(value) || value.length === 0) {
    addFailure(`${label} is required.`);
    return [];
  }
  return value;
}

function requireOutsideRepoPath(filePath, label) {
  const value = String(filePath || '').trim();
  if (!value) {
    addFailure(`${label} is required.`);
    return;
  }
  if (isPathInsideRepo(value)) {
    addFailure(`${label} must be outside the repository: ${value}`);
  }
}

function assertMarkdownContains(markdown, phrase) {
  if (!markdown.includes(phrase)) {
    addFailure(`Release evidence gap pack Markdown is missing required phrase: ${phrase}`);
  }
}

function inputById(inputs, id) {
  return inputs.find((input) => input?.id === id) || null;
}

function requirementById(requirements, id) {
  return requirements.find((requirement) => requirement?.id === id) || null;
}

function assertCurrentPrCandidateReview(review, label, connectorDiagnosticKey = 'github_connector_diagnostic') {
  if (!review || typeof review !== 'object') {
    addFailure(`${label} is required.`);
    return;
  }
  if (review.command !== 'npm run review:release-pr-candidates') {
    addFailure(`${label}.command must be npm run review:release-pr-candidates.`);
  }
  if (review.status !== 'failed') {
    addFailure(`${label}.status must remain failed until an open final release PR is selected.`);
  }
  if (!String(review.gh_pr_list_checked_at || '').trim()) {
    addFailure(`${label}.gh_pr_list_checked_at is required.`);
  }
  if (!Number.isFinite(Number(review.gh_pr_list_open_pr_count))) {
    addFailure(`${label}.gh_pr_list_open_pr_count must be numeric.`);
  }
  const diagnostic = review[connectorDiagnosticKey] || null;
  if (!diagnostic || typeof diagnostic !== 'object') {
    addFailure(`${label}.${connectorDiagnosticKey} is required.`);
    return;
  }
  if (diagnostic.path !== 'docs/release_github_handoff_evidence.json') {
    addFailure(`${label}.${connectorDiagnosticKey}.path must reference docs/release_github_handoff_evidence.json.`);
  }
  if (typeof diagnostic.is_stale_for_current_review !== 'boolean') {
    addFailure(`${label}.${connectorDiagnosticKey}.is_stale_for_current_review must be boolean.`);
  }
  if (diagnostic.does_not_close_release_readiness !== true) {
    addFailure(`${label}.${connectorDiagnosticKey}.does_not_close_release_readiness must be true.`);
  }
}

if (isPathInsideRepo(gapPackPath)) {
  addFailure(`Release evidence gap pack must be outside the repository: ${gapPackPath}`);
}
if (isPathInsideRepo(markdownPath)) {
  addFailure(`Release evidence gap pack Markdown must be outside the repository: ${markdownPath}`);
}
if (path.resolve(gapPackPath) === path.resolve(markdownPath)) {
  addFailure('Release evidence gap pack JSON and Markdown paths must be different.');
}

let gapPack = null;
let markdown = '';
try {
  gapPack = readJson(gapPackPath);
  addPass(`Release evidence gap pack is readable JSON: ${gapPackPath}`);
} catch (error) {
  addFailure(`Release evidence gap pack is not readable JSON: ${error.message}`);
}

try {
  markdown = fs.readFileSync(markdownPath, 'utf8');
  addPass(`Release evidence gap pack Markdown is readable: ${markdownPath}`);
} catch (error) {
  addFailure(`Release evidence gap pack Markdown is not readable: ${error.message}`);
}

if (gapPack) {
  if (gapPack.command !== 'npm run report:release-evidence-gap-pack') {
    addFailure('Release evidence gap pack command must be npm run report:release-evidence-gap-pack.');
  }
  if (gapPack.release_ready !== false) {
    addFailure('Release evidence gap pack must record release_ready=false.');
  } else {
    addPass('Release evidence gap pack explicitly does not close release readiness.');
  }
  requireOutsideRepoPath(gapPack.evidence_dir, 'evidence_dir');

  const sourceStatus = requireObject(gapPack.source_status, 'source_status');
  if (sourceStatus) {
    const status = sourceStatus.release_readiness_status || {};
    if (status.overall_status !== 'not_release_ready') {
      addFailure('source_status.release_readiness_status.overall_status must remain not_release_ready while blockers are open.');
    }
    const currentPr = status.current_pr || null;
    if (!currentPr || typeof currentPr !== 'object') {
      addFailure('source_status.release_readiness_status.current_pr is required.');
    } else {
      const connector = currentPr.connector_evidence || null;
      if (!connector || typeof connector !== 'object') {
        addFailure('source_status.release_readiness_status.current_pr.connector_evidence is required.');
      } else {
        if (connector.path !== 'docs/release_github_handoff_evidence.json') {
          addFailure('current PR connector evidence must reference docs/release_github_handoff_evidence.json.');
        }
        if (connector.tool !== 'mcp__codex_apps__github._get_users_recent_prs_in_repo') {
          addFailure('current PR connector evidence must record the GitHub recent PR connector.');
        }
        if (connector.state !== 'open') {
          addFailure('current PR connector evidence state must be open.');
        }
        if (!Number.isFinite(Number(connector.pull_requests_count))) {
          addFailure('current PR connector evidence must preserve numeric pull_requests_count.');
        }
      }
      assertCurrentPrCandidateReview(currentPr.pr_candidate_review, 'source_status.release_readiness_status.current_pr.pr_candidate_review', 'connector_diagnostic');
    }

    assertCurrentPrCandidateReview(
      sourceStatus.latest_release_pr_candidates_result,
      'source_status.latest_release_pr_candidates_result',
      'github_connector_diagnostic',
    );

    const readinessResult = sourceStatus.latest_release_readiness_result || {};
    if (readinessResult.final_release_ready !== false || readinessResult.status !== 'failed') {
      addFailure('latest_release_readiness_result must remain failed with final_release_ready=false while blockers are open.');
    }
    const readinessFailures = Array.isArray(readinessResult.failures) ? readinessResult.failures.join('\n') : '';
    for (const phrase of [
      'Design handoff manifest was not found',
      'OTA credential rotation attestation was not found',
      'Release PR candidate gate has not passed',
      'Release external-state gate has not passed',
    ]) {
      if (!readinessFailures.includes(phrase)) {
        addFailure(`latest_release_readiness_result.failures must mention ${phrase}.`);
      }
    }

    const worktreePlan = sourceStatus.local_worktree_close_plan || null;
    if (!worktreePlan || typeof worktreePlan !== 'object') {
      addFailure('source_status.local_worktree_close_plan is required.');
    } else {
      const changedEntries = Number(worktreePlan.changed_entries || 0);
      if (!['clean', 'blocked_until_clean_or_isolated'].includes(String(worktreePlan.status || ''))) {
        addFailure(`local_worktree_close_plan has unexpected status: ${worktreePlan.status}`);
      }
      if (changedEntries > 0 && worktreePlan.status !== 'blocked_until_clean_or_isolated') {
        addFailure('local_worktree_close_plan must be blocked_until_clean_or_isolated when changed entries exist.');
      }
      if (changedEntries === 0 && worktreePlan.status !== 'clean') {
        addFailure('local_worktree_close_plan must be clean when there are no changed entries.');
      }
      const isolationEvidence = requireObject(worktreePlan.isolation_evidence, 'local_worktree_close_plan.isolation_evidence');
      if (isolationEvidence) {
        if (changedEntries > 0 && isolationEvidence.still_blocks_release !== true) {
          addFailure('local_worktree_close_plan.isolation_evidence must keep dirty worktree as release-blocking.');
        }
        if (changedEntries === 0 && isolationEvidence.still_blocks_release !== false) {
          addFailure('local_worktree_close_plan.isolation_evidence must not block release when the worktree is clean.');
        }
      }
      const stagingPlan = requireObject(worktreePlan.staging_plan, 'local_worktree_close_plan.staging_plan');
      if (stagingPlan) {
        const counts = stagingPlan.counts || {};
        for (const bucket of [
          'candidate_release_scope',
          'needs_explicit_operator_decision',
          'must_remain_local_by_default',
        ]) {
          if (!Number.isFinite(Number(counts[bucket]))) {
            addFailure(`local_worktree_close_plan.staging_plan.counts must include numeric ${bucket}.`);
          }
        }
        const forbiddenActions = Array.isArray(stagingPlan.forbidden_actions) ? stagingPlan.forbidden_actions.join('\n') : '';
        if (!forbiddenActions.includes('Do not stage this plan automatically.')) {
          addFailure('local_worktree_close_plan.staging_plan must forbid automatic staging.');
        }
      }
    }
  }

  const closeSequence = requireArray(gapPack.readiness_close_sequence, 'readiness_close_sequence');
  const closeCommands = closeSequence.map((entry) => entry?.command);
  for (const command of [
    'npm run review:release-design',
    'npm run review:release-ota-credentials',
    'npm run review:release-pr-candidates',
    'npm run review:release-staged-scope',
    'npm run review:release-external-state',
    'npm run review:release-readiness',
  ]) {
    if (!closeCommands.includes(command)) {
      addFailure(`readiness_close_sequence is missing ${command}.`);
    }
  }

  const requirements = requireArray(gapPack.blocking_requirements, 'blocking_requirements');
  for (const [id, status, command] of [
    ['design-handoff-missing', 'missing', 'npm run review:release-design'],
    ['ota-credential-rotation-attestation-missing', 'missing', 'npm run review:release-ota-credentials'],
    ['local-git-state-open', 'open', 'npm run review:release-external-state'],
  ]) {
    const requirement = requirementById(requirements, id);
    if (!requirement) {
      addFailure(`blocking_requirements is missing ${id}.`);
      continue;
    }
    if (requirement.status !== status) {
      addFailure(`blocking_requirements.${id}.status must be ${status}.`);
    }
    if (requirement.acceptance_command !== command) {
      addFailure(`blocking_requirements.${id}.acceptance_command must be ${command}.`);
    }
    const forbiddenClosure = Array.isArray(requirement.forbidden_closure) ? requirement.forbidden_closure.join('\n') : '';
    if (!/Do not/i.test(forbiddenClosure)) {
      addFailure(`blocking_requirements.${id}.forbidden_closure must prevent false closure.`);
    }
  }

  const packet = requireObject(gapPack.operator_intake_packet, 'operator_intake_packet');
  if (packet) {
    if (packet.release_ready !== false || packet.does_not_close_release_readiness !== true) {
      addFailure('operator_intake_packet must record release_ready=false and does_not_close_release_readiness=true.');
    }
    const inputs = requireArray(packet.required_external_inputs, 'operator_intake_packet.required_external_inputs');
    for (const id of [
      'design_handoff_manifest',
      'ota_credential_rotation_attestation',
      'final_release_pr_and_local_state',
    ]) {
      if (!inputById(inputs, id)) {
        addFailure(`operator_intake_packet.required_external_inputs is missing ${id}.`);
      }
    }
    const worktreeSummary = requireObject(packet.worktree_staging_summary, 'operator_intake_packet.worktree_staging_summary');
    if (worktreeSummary?.does_not_close_release_readiness !== true) {
      addFailure('operator_intake_packet.worktree_staging_summary must not close release readiness.');
    }
    const finalPrHead = requireObject(packet.final_pr_head_status, 'operator_intake_packet.final_pr_head_status');
    if (finalPrHead?.does_not_close_release_readiness !== true) {
      addFailure('operator_intake_packet.final_pr_head_status must not close release readiness.');
    }
    assertCurrentPrCandidateReview(
      packet.current_pr_status?.pr_candidate_review,
      'operator_intake_packet.current_pr_status.pr_candidate_review',
      'github_connector_diagnostic',
    );
  }

  const finalGate = gapPack.final_gate || {};
  if (finalGate.command !== 'npm run review:release-readiness' || finalGate.required_to_pass !== true) {
    addFailure('final_gate must require npm run review:release-readiness.');
  }
  if (finalGate.current_expected_status !== 'failed_until_blocking_requirements_close') {
    addFailure('final_gate.current_expected_status must remain failed_until_blocking_requirements_close.');
  }
}

if (markdown) {
  for (const phrase of [
    'Status: not release-ready',
    'Do not treat this gap pack as release-ready evidence',
    'Operator Intake Packet',
    'Required operator-controlled inputs',
    'staging/isolation plan',
    'candidate_release_scope',
    'needs_explicit_operator_decision',
    'must_remain_local_by_default',
    'Current PR connector open PR count',
    'Current PR connector checked at',
    'PR candidate gh checked at',
    'PR candidate gh open PR count',
    'PR connector stale for current review',
    'Configured release PR number',
    'Selected release PR head',
    'External-state local HEAD',
    'External-state expected PR head',
    'Final PR head match status',
    'mcp__codex_apps__github._get_users_recent_prs_in_repo',
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

console.log(`Release evidence gap pack verification summary: ${passes.length} passed, ${failures.length} failures.`);

if (failures.length > 0) {
  process.exit(1);
}
