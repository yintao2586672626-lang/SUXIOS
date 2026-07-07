import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');

function resolveInputPath(filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(resolveInputPath(filePath));
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function evidencePath(fileName) {
  return path.join(evidenceDir, fileName);
}

function stringifyAsciiJson(value) {
  return JSON.stringify(value, null, 2).replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function stringifyAsciiText(value) {
  return String(value ?? '').replace(/[^\x00-\x7F]/g, (char) => {
    const hex = char.charCodeAt(0).toString(16).padStart(4, '0');
    return `\\u${hex}`;
  });
}

function markdownCell(value) {
  const fallbackValue = value === null || typeof value === 'undefined' || value === '' ? 'n/a' : value;
  const normalized = typeof value === 'object' && value !== null
    ? stringifyAsciiJson(value).replace(/\s+/g, ' ').trim()
    : stringifyAsciiText(fallbackValue);
  return normalized.replace(/\r?\n/g, '<br>').replace(/\|/g, '\\|');
}

function markdownTable(headers, rows) {
  return [
    `| ${headers.map(markdownCell).join(' | ')} |`,
    `| ${headers.map(() => '---').join(' | ')} |`,
    ...rows.map((row) => `| ${row.map(markdownCell).join(' | ')} |`),
  ].join('\n');
}

function markdownList(items, fallback = 'None loaded.') {
  const normalized = (Array.isArray(items) ? items : [])
    .map((item) => stringifyAsciiText(item).replace(/\r?\n/g, ' ').trim())
    .filter(Boolean);
  if (normalized.length === 0) {
    return fallback;
  }
  return normalized.map((item) => `- ${item}`).join('\n');
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(resolveInputPath(filePath), 'utf8').replace(/^\uFEFF/, ''));
}

function renderInput(input) {
  const lines = [];
  lines.push(`### ${stringifyAsciiText(input.id || 'unknown-input')}`);
  lines.push('');
  lines.push(markdownTable(
    ['Blocker', 'Required file/result', 'Create/select command', 'Review command', 'Success evidence'],
    [[
      input.blocker_id || 'n/a',
      input.required_file || input.required_result_file || 'n/a',
      input.creation_command || input.selection_command || 'n/a',
      input.isolated_review_command || 'n/a',
      input.success_evidence || 'n/a',
    ]],
  ));
  lines.push('');
  if (Array.isArray(input.command_skeleton) && input.command_skeleton.length > 0) {
    lines.push('Command skeleton (PowerShell):');
    lines.push('');
    lines.push('```powershell');
    lines.push(...input.command_skeleton.map((line) => stringifyAsciiText(line)));
    lines.push('```');
    lines.push('');
  }
  lines.push('Required operator-controlled inputs:');
  lines.push('');
  lines.push(markdownList(input.required_operator_inputs));
  lines.push('');
  lines.push('Forbidden inputs or closure shortcuts:');
  lines.push('');
  lines.push(markdownList(input.forbidden_inputs));
  lines.push('');
  return lines.join('\n');
}

function renderWorktreeStagingSummary(summary) {
  const bucketCounts = summary?.bucket_counts || {};
  const lines = [];
  lines.push('## Worktree Staging/Isolation Summary');
  lines.push('');
  lines.push('This is local-state blocker evidence only. It does not stage, commit, isolate, or close release readiness.');
  lines.push('');
  lines.push(markdownTable(
    ['Field', 'Value'],
    [
      ['Worktree close status', summary?.status || 'unknown'],
      ['Changed entries', summary?.changed_entries ?? 'unknown'],
      ['Isolation evidence status', summary?.isolation_evidence_status || 'unknown'],
      ['Quarantine bundle status', summary?.quarantine_bundle_status || 'unknown'],
      ['staging/isolation plan status', summary?.staging_plan_status || 'unknown'],
      ['candidate_release_scope', bucketCounts.candidate_release_scope ?? 0],
      ['needs_explicit_operator_decision', bucketCounts.needs_explicit_operator_decision ?? 0],
      ['must_remain_local_by_default', bucketCounts.must_remain_local_by_default ?? 0],
      ['Closes release readiness', summary?.does_not_close_release_readiness === true ? 'no' : 'unknown'],
      ['Close condition', summary?.close_condition || 'n/a'],
    ],
  ));
  lines.push('');
  lines.push('Forbidden local-state shortcuts:');
  lines.push('');
  lines.push(markdownList(summary?.forbidden_actions));
  lines.push('');
  return lines.join('\n');
}

function renderPacket(payload) {
  const packet = payload.operator_intake_packet;
  const currentPr = packet.current_pr_status || payload.current_pr_status || null;
  const currentPrConnector = currentPr?.connector_evidence || null;
  const prCandidateReview = currentPr?.pr_candidate_review || null;
  const finalPrHead = packet.final_pr_head_status || null;
  const worktreeStagingSummary = packet.worktree_staging_summary || null;
  const lines = [];
  lines.push('# Release Operator Intake Packet');
  lines.push('');
  lines.push('Status: not release-ready');
  lines.push('');
  lines.push('This packet is an external-evidence intake checklist only. It does not replace the final design manifest, OTA credential rotation attestation, final PR selection, clean local-state evidence, or npm run review:release-readiness.');
  lines.push('');
  lines.push(markdownTable(
    ['Field', 'Value'],
    [
      ['Generated at', payload.generated_at],
      ['Source gap pack', payload.source_gap_pack],
      ['Evidence dir', payload.evidence_dir],
      ['release_ready', payload.release_ready === true ? 'true' : 'false'],
      ['does_not_close_release_readiness', packet.does_not_close_release_readiness === true ? 'true' : 'false'],
      ['Status', packet.status || 'unknown'],
      ['Acceptance order', packet.acceptance_order || 'n/a'],
    ],
  ));
  lines.push('');
  lines.push('## Current PR Handoff Evidence');
  lines.push('');
  lines.push('This is current blocker evidence only. It does not replace final PR selection or review:release-external-state.');
  lines.push('');
  lines.push(markdownTable(
    ['Field', 'Value'],
    [
      ['PR number', currentPr?.number ?? 'not selected'],
      ['PR status', currentPr?.status || 'unknown'],
      ['Source of truth', currentPr?.source_of_truth || 'n/a'],
      ['Connector evidence path', currentPrConnector?.path || 'n/a'],
      ['Connector tool', currentPrConnector?.tool || 'n/a'],
      ['Connector checked at', currentPrConnector?.checked_at || 'n/a'],
      ['Connector result', currentPrConnector?.result || 'n/a'],
      ['Connector open PR count', currentPrConnector?.pull_requests_count ?? 'n/a'],
      ['Current gh PR checked at', prCandidateReview?.gh_pr_list_checked_at || 'n/a'],
      ['Current gh open PR count', prCandidateReview?.gh_pr_list_open_pr_count ?? 'n/a'],
      ['Connector stale for current review', prCandidateReview?.github_connector_diagnostic?.is_stale_for_current_review === true ? 'yes' : 'no/not recorded'],
      ['Configured release PR number', finalPrHead?.configured_release_pr_number || 'not set'],
      ['Selected release PR number', finalPrHead?.selected_release_pr_number || 'not set'],
      ['Selected release PR head', finalPrHead?.selected_release_pr_head_sha || 'not recorded'],
      ['External-state local HEAD', finalPrHead?.external_state_local_head_sha || 'not recorded'],
      ['External-state expected PR head', finalPrHead?.external_state_pr_head_sha || 'not recorded'],
      ['Final PR head match status', finalPrHead?.status || 'unknown'],
      ['Final PR head close condition', finalPrHead?.close_condition || 'n/a'],
      ['Closes release readiness', currentPr?.does_not_close_release_readiness === true ? 'no' : 'unknown'],
    ],
  ));
  lines.push('');
  lines.push(renderWorktreeStagingSummary(worktreeStagingSummary));
  for (const input of packet.required_external_inputs || []) {
    lines.push(renderInput(input));
  }
  lines.push('## Forbidden Closure');
  lines.push('');
  lines.push(markdownList(packet.forbidden_closure));
  lines.push('');
  return lines.join('\n');
}

const sourceGapPackPath = process.env.RELEASE_OPERATOR_INTAKE_SOURCE_FILE
  ? resolveInputPath(process.env.RELEASE_OPERATOR_INTAKE_SOURCE_FILE)
  : evidencePath('release-evidence-gap-pack.json');
const outputPath = process.env.RELEASE_OPERATOR_INTAKE_PACKET_FILE
  ? resolveInputPath(process.env.RELEASE_OPERATOR_INTAKE_PACKET_FILE)
  : evidencePath('release-operator-intake-packet.json');
const markdownOutputPath = process.env.RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE
  ? resolveInputPath(process.env.RELEASE_OPERATOR_INTAKE_PACKET_MARKDOWN_FILE)
  : evidencePath('release-operator-intake-packet.md');

if (isPathInsideRepo(outputPath)) {
  console.error(`Release operator intake packet output must be outside the repository: ${outputPath}`);
  process.exit(1);
}
if (isPathInsideRepo(markdownOutputPath)) {
  console.error(`Release operator intake packet Markdown output must be outside the repository: ${markdownOutputPath}`);
  process.exit(1);
}
if (path.resolve(outputPath) === path.resolve(markdownOutputPath)) {
  console.error('Release operator intake packet JSON and Markdown outputs must use different paths.');
  process.exit(1);
}
if (!fs.existsSync(sourceGapPackPath)) {
  console.error(`Release evidence gap pack was not found: ${sourceGapPackPath}. Run npm run report:release-evidence-gap-pack first.`);
  process.exit(1);
}

const gapPack = readJson(sourceGapPackPath);
const operatorIntakePacket = gapPack.operator_intake_packet;
if (!operatorIntakePacket || !Array.isArray(operatorIntakePacket.required_external_inputs)) {
  console.error(`Release evidence gap pack is missing operator_intake_packet.required_external_inputs: ${sourceGapPackPath}`);
  process.exit(1);
}
const operatorIntakePacketText = JSON.stringify(operatorIntakePacket);
for (const fragment of [
  'DESIGN_HANDOFF_MANIFEST_INPUT_FILE',
  'OTA_CREDENTIAL_ROTATION_INPUT_FILE',
  'RELEASE_PR_NUMBER',
  'release_github_handoff_evidence.json',
  'mcp__codex_apps__github._get_users_recent_prs_in_repo',
  'worktree_staging_summary',
  'final_pr_head_status',
  'selected_release_pr_head_sha',
  'expected_local_head_sha',
  'expected_release_pr_head_sha',
  'candidate_release_scope',
  'needs_explicit_operator_decision',
  'must_remain_local_by_default',
]) {
  if (!operatorIntakePacketText.includes(fragment)) {
    console.error(`Release operator intake packet is missing command skeleton fragment: ${fragment}`);
    process.exit(1);
  }
}

const payload = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run export:release-operator-intake',
  release_ready: false,
  does_not_close_release_readiness: true,
  source_gap_pack: sourceGapPackPath,
  evidence_dir: evidenceDir,
  source_status: gapPack.source_status || null,
  current_pr_status: operatorIntakePacket.current_pr_status || null,
  operator_intake_packet: operatorIntakePacket,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${stringifyAsciiJson(payload)}\n`, 'utf8');
fs.mkdirSync(path.dirname(markdownOutputPath), { recursive: true });
fs.writeFileSync(markdownOutputPath, `${renderPacket(payload)}\n`, 'utf8');

console.log(`Wrote release operator intake packet to ${outputPath}`);
console.log(`Wrote release operator intake packet Markdown companion to ${markdownOutputPath}`);
console.log(`Required external inputs: ${operatorIntakePacket.required_external_inputs.map((item) => item.id).join(', ')}`);
console.log('This packet does not close release readiness.');
