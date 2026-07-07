import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  categorizeReleasePath,
  gitStatusCategoryOrder,
  normalizeReleasePath,
  releaseStagingBucket,
} from './lib/release_worktree_scope.mjs';

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

function worktreeQuarantineManifestPath() {
  return process.env.WORKTREE_QUARANTINE_MANIFEST_FILE
    ? resolveInputPath(process.env.WORKTREE_QUARANTINE_MANIFEST_FILE)
    : path.join(evidenceDir, 'worktree-quarantine-current', 'manifest.json');
}

function readJsonIfExists(filePath) {
  const resolvedPath = resolveInputPath(filePath);
  if (!fs.existsSync(resolvedPath)) {
    return { exists: false, path: resolvedPath, data: null, error: null };
  }
  try {
    return {
      exists: true,
      path: resolvedPath,
      data: JSON.parse(fs.readFileSync(resolvedPath, 'utf8')),
      error: null,
    };
  } catch (error) {
    return { exists: true, path: resolvedPath, data: null, error: error.message };
  }
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

function compactMarkdownValue(value) {
  if (value === null || typeof value === 'undefined' || value === '') {
    return 'n/a';
  }
  if (typeof value === 'object') {
    return stringifyAsciiJson(value).replace(/\s+/g, ' ').trim();
  }
  return stringifyAsciiText(value);
}

function markdownCell(value) {
  return compactMarkdownValue(value)
    .replace(/\r?\n/g, '<br>')
    .replace(/\|/g, '\\|');
}

function markdownTable(headers, rows) {
  return [
    `| ${headers.map(markdownCell).join(' | ')} |`,
    `| ${headers.map(() => '---').join(' | ')} |`,
    ...rows.map((row) => `| ${row.map(markdownCell).join(' | ')} |`),
  ].join('\n');
}

function markdownList(items, fallback = 'None.') {
  const normalizedItems = (Array.isArray(items) ? items : [])
    .map((item) => compactMarkdownValue(item))
    .map((item) => item.replace(/\r?\n/g, ' ').trim())
    .filter(Boolean);
  if (normalizedItems.length === 0) {
    return stringifyAsciiText(fallback);
  }
  return normalizedItems.map((item) => `- ${item}`).join('\n');
}

function evidenceTimestamp(filePath) {
  try {
    const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
    const generatedAt = Date.parse(String(data.generated_at || ''));
    if (Number.isFinite(generatedAt)) {
      return generatedAt;
    }
  } catch {
    // Fall back to mtime when a result exists but is not readable JSON.
  }

  try {
    return fs.statSync(filePath).mtimeMs;
  } catch {
    return 0;
  }
}

function latestExistingEvidencePath(fileNames) {
  const candidates = fileNames
    .map((fileName) => evidencePath(fileName))
    .filter((candidate) => fs.existsSync(candidate));
  if (candidates.length === 0) {
    return evidencePath(fileNames[0]);
  }

  candidates.sort((left, right) => evidenceTimestamp(right) - evidenceTimestamp(left));
  return candidates[0];
}

function latestPromotionResultPath() {
  return process.env.RELEASE_EVIDENCE_PROMOTION_RESULT_FILE
    ? resolveInputPath(process.env.RELEASE_EVIDENCE_PROMOTION_RESULT_FILE)
    : latestExistingEvidencePath([
      'release-evidence-promotion-result.json',
      'release-evidence-promotion-current-result.json',
    ]);
}

function latestDesignManifestCreateResultPath() {
  return process.env.DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE
    ? resolveInputPath(process.env.DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE)
    : latestExistingEvidencePath([
      'release-design-manifest-create-result.json',
      'release-design-manifest-create-current-result.json',
    ]);
}

function latestOtaAttestationCreateResultPath() {
  return process.env.OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE
    ? resolveInputPath(process.env.OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE)
    : latestExistingEvidencePath([
      'release-ota-attestation-create-result.json',
      'release-ota-attestation-create-current-result.json',
    ]);
}

function parseGitStatusEntry(line) {
  const status = line.slice(0, 2).trim() || line.slice(0, 2);
  const rawPath = line.length > 3 ? line.slice(3).trim() : line.slice(2).trim();
  return {
    status,
    path: rawPath,
    category: categorizeReleasePath(rawPath),
  };
}

function summarizeGitStatusEntries(entries) {
  const byCategory = {};
  for (const category of gitStatusCategoryOrder) {
    const count = entries.filter((entry) => entry.category === category).length;
    if (count > 0) {
      byCategory[category] = count;
    }
  }

  const statusCounts = new Map();
  for (const entry of entries) {
    statusCounts.set(entry.status, (statusCounts.get(entry.status) || 0) + 1);
  }

  return {
    total: entries.length,
    by_category: byCategory,
    by_status: Object.fromEntries([...statusCounts.entries()].sort(([left], [right]) => left.localeCompare(right))),
  };
}

function gitStatusDiagnosticsFromText(stdout) {
  const lines = String(stdout || '').split(/\r?\n/).filter(Boolean);
  const changedEntries = lines.filter((line) => !line.startsWith('## ')).map(parseGitStatusEntry);
  return {
    git_status_changed_entries: changedEntries,
    git_status_changed_summary: summarizeGitStatusEntries(changedEntries),
  };
}

function externalStateDiagnostics(externalStateResult, gitStatus) {
  const resultDiagnostics = externalStateResult.data?.diagnostics || null;
  if (resultDiagnostics?.git_status_changed_summary) {
    return {
      source: externalStateResult.path,
      ...resultDiagnostics,
    };
  }

  return {
    source: 'git status --short --branch',
    git_status_changed_entries: gitStatus.changed_details,
    git_status_changed_summary: gitStatus.changed_summary,
  };
}

function prCandidateSummary(prCandidateResult) {
  const diagnostics = prCandidateResult.data?.diagnostics || {};
  const currentGhPrList = diagnostics.current_gh_pr_list || null;
  const githubConnectorDiagnostic = diagnostics.github_connector_diagnostic || null;
  const normalizedGithubConnectorDiagnostic = githubConnectorDiagnostic ? {
    ...githubConnectorDiagnostic,
    path: githubConnectorDiagnostic.path || 'docs/release_github_handoff_evidence.json',
    does_not_close_release_readiness: true,
  } : null;
  const configuredPrNumber = prCandidateResult.data?.configured_release_pr_number ?? null;
  const selectedPrNumber = prCandidateResult.data?.selected_release_pr_number ?? null;
  const candidates = Array.isArray(prCandidateResult.data?.candidates)
    ? prCandidateResult.data.candidates
    : [];
  const selectedCandidate = candidates.find((candidate) => (
    String(candidate?.number ?? '') === String(selectedPrNumber ?? '')
  ));
  const selectedHeadRefOid = String(selectedCandidate?.headRefOid || '').trim();
  return {
    path: prCandidateResult.path,
    command: prCandidateResult.data?.command || 'npm run review:release-pr-candidates',
    exists: prCandidateResult.exists,
    status: prCandidateResult.data?.status || 'unknown',
    generated_at: prCandidateResult.data?.generated_at || null,
    gh_pr_list_checked_at: prCandidateResult.data?.gh_pr_list_checked_at || currentGhPrList?.checked_at || null,
    gh_pr_list_open_pr_count: prCandidateResult.data?.gh_pr_list_open_pr_count ?? currentGhPrList?.open_pr_count ?? null,
    current_gh_pr_list: currentGhPrList,
    github_connector_diagnostic: normalizedGithubConnectorDiagnostic,
    base_ref: prCandidateResult.data?.base_ref || 'unknown',
    configured_release_pr_number: configuredPrNumber,
    selected_release_pr_number: selectedPrNumber,
    selected_release_pr_head_sha: /^[a-f0-9]{40}$/i.test(selectedHeadRefOid) ? selectedHeadRefOid : null,
    viable_candidates: Number(prCandidateResult.data?.summary?.viable_candidates || 0),
    failures: compactFailures(prCandidateResult),
  };
}

function finalPrHeadStatus(prCandidates, externalState) {
  const configuredPrNumber = prCandidates?.configured_release_pr_number ?? null;
  const selectedPrNumber = prCandidates?.selected_release_pr_number ?? null;
  const selectedHeadSha = prCandidates?.selected_release_pr_head_sha || null;
  const externalPrNumber = externalState?.expected_release_pr_number ?? null;
  const externalLocalHeadSha = externalState?.expected_local_head_sha || null;
  const externalHeadSha = externalState?.expected_release_pr_head_sha || null;
  let status = 'blocked_until_pr_candidate_external_state_and_local_head_match';
  if (selectedPrNumber !== null && externalPrNumber !== null && selectedHeadSha && externalHeadSha && externalLocalHeadSha) {
    const candidateMatchesExternal = String(selectedPrNumber) === String(externalPrNumber)
      && String(selectedHeadSha).toLowerCase() === String(externalHeadSha).toLowerCase();
    const localMatchesExternal = String(externalLocalHeadSha).toLowerCase() === String(externalHeadSha).toLowerCase();
    status = candidateMatchesExternal && localMatchesExternal
      ? 'candidate_external_state_and_local_head_match'
      : 'candidate_external_state_or_local_head_mismatch';
  }

  return {
    status,
    configured_release_pr_number: configuredPrNumber,
    selected_release_pr_number: selectedPrNumber,
    selected_release_pr_head_sha: selectedHeadSha,
    pr_candidate_review_checked_at: prCandidates?.gh_pr_list_checked_at || null,
    pr_candidate_review_open_pr_count: prCandidates?.gh_pr_list_open_pr_count ?? null,
    connector_diagnostic_stale_for_current_review: prCandidates?.github_connector_diagnostic?.is_stale_for_current_review ?? null,
    external_state_pr_number: externalPrNumber,
    external_state_local_head_sha: externalLocalHeadSha,
    external_state_pr_head_sha: externalHeadSha,
    close_condition: 'review:release-pr-candidates and review:release-external-state must pass on the same final PR number and head SHA, and the local HEAD captured by external-state must match that PR head before release-readiness can close.',
    does_not_close_release_readiness: true,
  };
}

function worktreeCloseHint(gitDiagnostics) {
  const summary = gitDiagnostics?.git_status_changed_summary || {};
  const categories = summary.by_category || {};
  const changed = Number(summary.total || 0);
  if (changed === 0) {
    return 'local worktree is clean; keep it clean until final external-state review';
  }

  const parts = gitStatusCategoryOrder
    .filter((category) => Number(categories[category] || 0) > 0)
    .map((category) => `${category}=${categories[category]}`);
  return `review or isolate ${changed} changed entries before final handoff (${parts.join(', ')})`;
}

function worktreeCategoryAction(category) {
  const actions = {
    'release-docs': 'review as release-readiness evidence and stage only if the corresponding verifier still passes',
    'release-scripts': 'review as release-readiness tooling and stage only with matching syntax and release-status verification',
    frontend: 'decide whether this belongs in the release PR; otherwise move it to a separate branch or worktree before final external-state review',
    'revenue-ai': 'decide whether this belongs in the release PR; otherwise isolate it from the release handoff because it is outside the release-evidence blockers',
    'runtime-or-local': 'keep untracked or local-only unless the operator explicitly approves cleanup; do not commit runtime, storage, reports, or local evidence by default',
    other: 'inspect manually and either include with evidence or isolate before final external-state review',
  };
  return actions[category] || actions.other;
}

function worktreeStagingPlan(entries) {
  const buckets = {
    candidate_release_scope: [],
    needs_explicit_operator_decision: [],
    must_remain_local_by_default: [],
  };
  for (const entry of entries) {
    const bucket = releaseStagingBucket(entry.category);
    buckets[bucket].push({
      status: entry.status,
      path: normalizeReleasePath(entry.path),
      category: entry.category,
    });
  }

  const counts = Object.fromEntries(Object.entries(buckets).map(([bucket, bucketEntries]) => [bucket, bucketEntries.length]));
  const total = entries.length;
  return {
    status: total === 0 ? 'clean_no_staging_needed' : 'requires_review_before_release_pr',
    counts,
    buckets,
    close_condition: 'Final release PR may include only reviewed release-scope changes; non-release and local/runtime changes must be clean or intentionally isolated before external-state review.',
    review_commands: [
      'git diff --check',
      'npm run review:release-staged-scope',
      'npm run verify:release-status',
      'npm run refresh:release-current-evidence',
      'npm run review:release-readiness',
    ],
    forbidden_actions: [
      'Do not stage this plan automatically.',
      'Do not include runtime, storage, reports, or local evidence files in the final release PR by default.',
      'Do not include frontend, revenue-AI, or other non-release-evidence changes without an explicit operator decision.',
    ],
  };
}

function summarizeWorktreeQuarantine(manifestPath) {
  const manifest = readJsonIfExists(manifestPath);
  const data = manifest.data || {};
  const untrackedFiles = Array.isArray(data.untracked_files) ? data.untracked_files : [];
  const copiedUntracked = untrackedFiles
    .filter((entry) => entry?.copied === true)
    .map((entry) => ({
      path: normalizeReleasePath(entry.path),
      target: normalizeReleasePath(entry.target),
    }));
  const skippedLocalArtifacts = untrackedFiles
    .filter((entry) => entry?.skipped_reason)
    .map((entry) => ({
      path: normalizeReleasePath(entry.path),
      skipped_reason: String(entry.skipped_reason),
    }));
  const insideRepo = manifest.exists ? isPathInsideRepo(manifest.path) : false;
  let status = 'missing';
  if (manifest.exists && manifest.error) {
    status = 'invalid_json';
  } else if (insideRepo) {
    status = 'invalid_inside_repo';
  } else if (data.dry_run === true) {
    status = 'dry_run_only';
  } else if (manifest.exists && data.tracked_patch) {
    status = 'available_as_preservation_evidence_not_release_closure';
  } else if (manifest.exists) {
    status = 'present_but_incomplete';
  }

  return {
    status,
    path: manifest.path,
    exists: manifest.exists,
    error: manifest.error,
    generated_at: data.generated_at || null,
    include_local_artifacts: data.include_local_artifacts === true,
    changed_paths: Array.isArray(data.changed_paths) ? data.changed_paths.length : 0,
    tracked_patch: data.tracked_patch || null,
    copied_untracked: copiedUntracked,
    skipped_local_artifacts: skippedLocalArtifacts,
    release_staging_plan: data.release_staging_plan ? {
      status: data.release_staging_plan.status || 'unknown',
      counts: data.release_staging_plan.counts || {},
      review_files: data.release_staging_plan.review_files || {},
      does_not_close_release_readiness: data.release_staging_plan.does_not_close_release_readiness === true,
      close_condition: data.release_staging_plan.close_condition || null,
    } : null,
    close_condition: 'Final release still requires a clean worktree or an explicitly reviewed release PR; this bundle only preserves current local evidence.',
  };
}

function worktreeClosePlan(gitDiagnostics, quarantineBundle) {
  const entries = Array.isArray(gitDiagnostics?.git_status_changed_entries)
    ? gitDiagnostics.git_status_changed_entries
    : [];
  const summary = gitDiagnostics?.git_status_changed_summary || summarizeGitStatusEntries(entries);
  const currentChangedEntries = Number(summary.total || 0);
  const effectiveQuarantineBundle = {
    ...quarantineBundle,
    current_changed_entries: currentChangedEntries,
  };
  if (
    quarantineBundle?.status === 'available_as_preservation_evidence_not_release_closure'
    && Number(quarantineBundle.changed_paths || 0) !== currentChangedEntries
  ) {
    effectiveQuarantineBundle.status = 'stale_changed_path_mismatch';
    effectiveQuarantineBundle.stale_reason = `quarantine manifest changed_paths=${Number(quarantineBundle.changed_paths || 0)} does not match current changed_entries=${currentChangedEntries}`;
  }
  const quarantineMatchesCurrent = effectiveQuarantineBundle.status === 'available_as_preservation_evidence_not_release_closure'
    && Number(effectiveQuarantineBundle.changed_paths || 0) === currentChangedEntries;
  const isolationEvidence = {
    status: currentChangedEntries === 0
      ? 'not_needed_clean_worktree'
      : (quarantineMatchesCurrent ? 'current_dirty_state_preserved_not_release_closure' : 'missing_or_stale'),
    quarantine_matches_current: quarantineMatchesCurrent,
    still_blocks_release: currentChangedEntries > 0,
    required_next_step: currentChangedEntries === 0
      ? 'Keep git status clean until final external-state review.'
      : 'Review, commit, or isolate these changes from the final release PR; quarantine evidence alone is not release closure.',
  };
  const categories = gitStatusCategoryOrder.map((category) => {
    const categoryEntries = entries.filter((entry) => entry.category === category);
    return {
      category,
      count: categoryEntries.length,
      action: worktreeCategoryAction(category),
      entries: categoryEntries.map((entry) => ({
        status: entry.status,
        path: normalizeReleasePath(entry.path),
      })),
    };
  }).filter((category) => category.count > 0);

  return {
    status: Number(summary.total || 0) === 0 ? 'clean' : 'blocked_until_clean_or_isolated',
    changed_entries: Number(summary.total || 0),
    changed_summary: summary,
    quarantine_bundle: effectiveQuarantineBundle,
    isolation_evidence: isolationEvidence,
    staging_plan: worktreeStagingPlan(entries),
    categories,
    acceptance_commands: [
      'git status --short --branch',
      'npm run export:worktree-quarantine -- --output=..\\release-evidence-temp\\worktree-quarantine-current',
      'npm run review:release-pr-candidates',
      'npm run review:release-staged-scope',
      'npm run review:release-external-state',
      'npm run review:release-readiness',
    ],
    forbidden_actions: [
      'Do not commit runtime, storage, reports, or local evidence by default.',
      'Do not reuse merged PR #2 as the final release target.',
      'Do not mark the worktree closed until git status is clean or every remaining change is intentionally isolated from the final release handoff.',
    ],
  };
}

function runGitStatus() {
  const result = spawnSync('git', ['status', '--short', '--branch'], {
    cwd: repoRoot,
    encoding: 'utf8',
    shell: false,
  });
  const stdout = String(result.stdout || '').trim();
  const stderr = String(result.stderr || '').trim();
  const diagnostics = gitStatusDiagnosticsFromText(stdout);
  return {
    command: 'git status --short --branch',
    exit_code: result.status ?? (result.error ? 1 : 0),
    changed_entries: diagnostics.git_status_changed_summary.total,
    changed_summary: diagnostics.git_status_changed_summary,
    changed_details: diagnostics.git_status_changed_entries,
    stdout,
    stderr,
    error: result.error?.message || null,
  };
}

function compactFailures(result) {
  const failures = [];
  if (Array.isArray(result?.data?.failures)) {
    failures.push(...result.data.failures);
  }
  if (Array.isArray(result?.data?.sections)) {
    for (const section of result.data.sections) {
      if (!Array.isArray(section?.failures)) {
        continue;
      }
      for (const failure of section.failures) {
        const prefix = section.name ? `[${section.name}] ` : '';
        failures.push(`${prefix}${failure}`);
      }
    }
  }
  return failures.map((failure) => String(failure)).filter(Boolean);
}

function connectorSummary(figmaEvidence, canvaEvidence, githubEvidence) {
  const figmaConnector = figmaEvidence.data?.latest_connector_check || null;
  const canvaConnectorChecks = Array.isArray(canvaEvidence.data?.latest_connector_checks)
    ? canvaEvidence.data.latest_connector_checks
    : [];
  const githubConnector = githubEvidence.data?.latest_connector_check || null;
  return {
    figma: figmaConnector ? {
      checked_at: figmaConnector.checked_at || '',
      result: figmaConnector.result || 'unknown',
      error_code: figmaConnector.error_code || '',
      reason: figmaConnector.reason || '',
    } : null,
    canva: canvaConnectorChecks.map((check) => ({
      checked_at: check.checked_at || '',
      tool: check.tool || '',
      result: check.result || 'unknown',
      error_code: check.error_code || '',
      reason: check.reason || '',
    })),
    github: githubConnector ? {
      checked_at: githubConnector.checked_at || '',
      tool: githubConnector.tool || '',
      result: githubConnector.result || 'unknown',
      state: githubConnector.state || '',
      pull_requests_count: Number.isFinite(Number(githubConnector.pull_requests_count))
        ? Number(githubConnector.pull_requests_count)
        : null,
      reason: githubConnector.reason || '',
    } : null,
  };
}

function readinessCloseSequence(designManifestPath, otaAttestationPath) {
  return [
    {
      order: 1,
      id: 'design-handoff-missing',
      required_input: designManifestPath,
      command: 'npm run review:release-design',
      success_condition: 'controlled design manifest proves accessible Figma, Canva, Brand Kit, design token path, required flow coverage, accountable owner, review date inside the 30-day release evidence window, and empty open_issues',
    },
    {
      order: 2,
      id: 'ota-credential-rotation-attestation-missing',
      required_input: otaAttestationPath,
      command: 'npm run review:release-ota-credentials',
      success_condition: 'credential-free attestation proves Ctrip and Meituan OTA credential rotation or invalidation, backup cleanup, git tracking check, redaction_checked=true, and accountable non-future review',
    },
    {
      order: 3,
      id: 'local-git-state-open',
      required_input: 'actual open final release PR selected by review:release-pr-candidates',
      command: 'npm run review:release-pr-candidates',
      success_condition: 'an open, non-draft, mergeable final release PR with green checks is selected; set RELEASE_PR_NUMBER and rerun candidate review when selection is not unique',
    },
    {
      order: 4,
      id: 'local-git-state-open',
      required_input: 'reviewed final release staging plan',
      command: 'npm run review:release-staged-scope',
      success_condition: 'staged entries contain no runtime/local artifacts and no non-release files without explicit operator approval; this does not replace clean worktree or PR verification',
    },
    {
      order: 5,
      id: 'local-git-state-open',
      required_input: 'RELEASE_PR_NUMBER plus clean or intentionally isolated local worktree',
      command: 'npm run review:release-external-state',
      success_condition: 'external-state result outside the repository proves clean local state and the configured final PR is open, non-draft, mergeable, and green',
    },
    {
      order: 6,
      id: 'final-release-readiness',
      required_input: 'passing isolated evidence results plus passing staged-scope and external-state results',
      command: 'npm run review:release-readiness',
      success_condition: 'final mode result has final_release_ready=true and zero failures',
    },
  ];
}

function operatorWorktreeStagingSummary(localWorktreeClosePlan) {
  const stagingPlan = localWorktreeClosePlan?.staging_plan || {};
  const counts = stagingPlan.counts || {};
  return {
    status: localWorktreeClosePlan?.status || 'unknown',
    changed_entries: localWorktreeClosePlan?.changed_entries ?? null,
    isolation_evidence_status: localWorktreeClosePlan?.isolation_evidence?.status || 'unknown',
    quarantine_bundle_status: localWorktreeClosePlan?.quarantine_bundle?.status || 'unknown',
    staging_plan_status: stagingPlan.status || 'unknown',
    bucket_counts: {
      candidate_release_scope: Number(counts.candidate_release_scope || 0),
      needs_explicit_operator_decision: Number(counts.needs_explicit_operator_decision || 0),
      must_remain_local_by_default: Number(counts.must_remain_local_by_default || 0),
    },
    close_condition: stagingPlan.close_condition || localWorktreeClosePlan?.isolation_evidence?.required_next_step || null,
    forbidden_actions: stagingPlan.forbidden_actions || [],
    does_not_close_release_readiness: true,
  };
}

function operatorIntakePacket(
  designManifestPath,
  otaAttestationPath,
  localWorktreeClosePlan,
  currentPrStatus,
  prCandidates,
  externalState,
) {
  const worktreeStagingSummary = operatorWorktreeStagingSummary(localWorktreeClosePlan);
  const prHeadStatus = finalPrHeadStatus(prCandidates, externalState);
  return {
    status: 'waiting_for_external_operator_inputs',
    release_ready: false,
    does_not_close_release_readiness: true,
    purpose: 'Machine-readable intake checklist for the external evidence that must exist before release-readiness can pass.',
    current_pr_status: currentPrStatus ? {
      number: currentPrStatus.number ?? null,
      status: currentPrStatus.status || 'unknown',
      source_of_truth: currentPrStatus.source_of_truth || 'unknown',
      connector_evidence: currentPrStatus.connector_evidence || null,
      pr_candidate_review: {
        path: prCandidates.path,
        command: prCandidates.command || 'npm run review:release-pr-candidates',
        status: prCandidates.status,
        gh_pr_list_checked_at: prCandidates.gh_pr_list_checked_at || null,
        gh_pr_list_open_pr_count: prCandidates.gh_pr_list_open_pr_count ?? null,
        github_connector_diagnostic: prCandidates.github_connector_diagnostic || null,
        does_not_close_release_readiness: true,
      },
      does_not_close_release_readiness: true,
    } : null,
    final_pr_head_status: prHeadStatus,
    worktree_staging_summary: worktreeStagingSummary,
    required_external_inputs: [
      {
        id: 'design_handoff_manifest',
        blocker_id: 'design-handoff-missing',
        required_file: designManifestPath,
        creation_command: 'npm run release:create-design-manifest',
        isolated_review_command: 'npm run review:release-design',
        final_gate_command: 'npm run review:release-readiness',
        command_skeleton: [
          '$env:RELEASE_EVIDENCE_DIR = (Resolve-Path ..\\release-evidence-temp).Path',
          '$env:DESIGN_HANDOFF_MANIFEST_INPUT_FILE = \'<absolute-controlled-external-design-manifest-json>\'',
          '$env:DESIGN_HANDOFF_MANIFEST_OUTPUT = Join-Path $env:RELEASE_EVIDENCE_DIR \'design_handoff_manifest.json\'',
          '$env:DESIGN_HANDOFF_MANIFEST_CREATE_RESULT_FILE = Join-Path $env:RELEASE_EVIDENCE_DIR \'release-design-manifest-create-result.json\'',
          'npm.cmd run release:create-design-manifest',
          '$env:DESIGN_HANDOFF_MANIFEST_FILE = $env:DESIGN_HANDOFF_MANIFEST_OUTPUT',
          'npm.cmd run review:release-design',
        ],
        required_operator_inputs: [
          'Preferred: DESIGN_HANDOFF_MANIFEST_INPUT_FILE points to a complete controlled external design_handoff_manifest.json that already passes npm run review:release-design.',
          'Connector compilation mode only: DESIGN_HANDOFF_OWNER names a real accountable design owner.',
          'Connector compilation mode only: CANVA_BRAND_KIT_URL points to an accessible Canva Brand Kit URL.',
          'Figma source is accessible and covers login, home-dashboard, ota-data, revenue-analysis, ai-decision, operations-management, and investment-decision flows.',
          'Canva source is accessible and connected to the Brand Kit evidence.',
          'DESIGN_HANDOFF_SOURCE_REVIEW_METHOD is connector_verified, manual_access_review, or independent_design_audit.',
          'DESIGN_HANDOFF_SOURCE_REVIEW_REF references a controlled connector result, ticket, or audit record.',
        ],
        forbidden_inputs: [
          'Do not use screenshots, exported images, standalone tokens, connector errors, placeholders, or draft text as source handoff evidence.',
          'Do not point DESIGN_HANDOFF_MANIFEST_INPUT_FILE at a repository file, draft file, or the same path as the final output.',
          'Do not put the final manifest inside the repository unless intentionally using the documented local default review path.',
        ],
        success_evidence: 'A controlled design_handoff_manifest.json exists outside the repository and npm run review:release-design passes.',
      },
      {
        id: 'ota_credential_rotation_attestation',
        blocker_id: 'ota-credential-rotation-attestation-missing',
        required_file: otaAttestationPath,
        creation_command: 'npm run release:create-ota-attestation',
        isolated_review_command: 'npm run review:release-ota-credentials',
        final_gate_command: 'npm run review:release-readiness',
        command_skeleton: [
          '$env:RELEASE_EVIDENCE_DIR = (Resolve-Path ..\\release-evidence-temp).Path',
          '$env:OTA_CREDENTIAL_ROTATION_INPUT_FILE = \'<absolute-controlled-external-ota-attestation-json>\'',
          '$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT = Join-Path $env:RELEASE_EVIDENCE_DIR \'ota_credential_rotation_attestation.json\'',
          '$env:OTA_CREDENTIAL_ROTATION_CREATE_RESULT_FILE = Join-Path $env:RELEASE_EVIDENCE_DIR \'release-ota-attestation-create-result.json\'',
          'npm.cmd run release:create-ota-attestation',
          '$env:OTA_CREDENTIAL_ROTATION_ATTESTATION_FILE = $env:OTA_CREDENTIAL_ROTATION_ATTESTATION_OUTPUT',
          'npm.cmd run review:release-ota-credentials',
        ],
        required_operator_inputs: [
          'OTA_CREDENTIAL_ROTATION_INPUT_FILE points to a complete controlled external reviewed attestation JSON that already passes npm run review:release-ota-credentials.',
          'Ctrip platform entry covers Cookie, Token/usertoken, signature/usersign, and Authorization rotation or invalidation.',
          'Meituan platform entry covers Cookie, Token/usertoken, signature/usersign, and Authorization rotation or invalidation.',
          'redaction_checked is true and backup cleanup fields reference real audit checks.',
          'reviewer is a real accountable operator or security reviewer.',
        ],
        forbidden_inputs: [
          'Do not include Cookie, token, usertoken, usersign, signature, Authorization, password, or reusable login state values.',
          'Do not treat database/backups cleanup as proof that platform credentials were rotated.',
          'Do not use draft notices, placeholder values, or credential-shaped masked strings as final evidence.',
          'Do not point OTA_CREDENTIAL_ROTATION_INPUT_FILE at a repository file, draft file, or the same path as the final output.',
        ],
        success_evidence: 'A credential-free ota_credential_rotation_attestation.json exists outside the repository and npm run review:release-ota-credentials passes.',
      },
      {
        id: 'final_release_pr_and_local_state',
        blocker_id: 'local-git-state-open',
        required_result_file: evidencePath('release-external-state-result.json'),
        selection_command: 'npm run review:release-pr-candidates',
        isolated_review_command: 'npm run review:release-external-state',
        final_gate_command: 'npm run review:release-readiness',
        current_worktree_changed_entries: localWorktreeClosePlan.changed_entries ?? null,
        worktree_staging_summary: worktreeStagingSummary,
        command_skeleton: [
          '$env:RELEASE_EVIDENCE_DIR = (Resolve-Path ..\\release-evidence-temp).Path',
          'npm.cmd run review:release-pr-candidates',
          '$env:RELEASE_PR_NUMBER = \'<actual-open-final-release-pr-number>\'',
          'npm.cmd run review:release-staged-scope',
          'npm.cmd run review:release-external-state',
          'npm.cmd run review:release-readiness',
        ],
        required_operator_inputs: [
          'Create or select the actual open final release PR only after design and OTA isolated gates pass.',
          'Set RELEASE_PR_NUMBER to that selected open final release PR.',
          'Rerun review:release-pr-candidates and review:release-external-state on the same PR head so selected_release_pr_head_sha, expected_release_pr_head_sha, and expected_local_head_sha all match.',
          'Keep the local worktree clean or explicitly isolate reviewed release-scope changes before final external-state review.',
          'Run npm run review:release-staged-scope before final staging or PR handoff.',
        ],
        forbidden_inputs: [
          'Do not reuse merged PR #2 or any stale/draft PR as the final release target.',
          'Do not treat worktree quarantine output, structural status checks, or historical CI as local/PR closure.',
          'Do not commit reports, storage, runtime, output, test-results, or local evidence by default.',
        ],
        success_evidence: 'review:release-pr-candidates selects the intended viable open PR, with RELEASE_PR_NUMBER recorded when selection is not unique; review:release-staged-scope passes for the final index, review:release-external-state passes, and review:release-readiness consumes those passing results.',
      },
    ],
    acceptance_order: [
      'npm run review:release-design',
      'npm run review:release-ota-credentials',
      'npm run review:release-pr-candidates',
      'npm run review:release-staged-scope',
      'npm run review:release-external-state',
      'npm run review:release-readiness',
    ],
    forbidden_closure: [
      'Do not close release readiness from this intake packet.',
      'Do not close release readiness from templates, drafts, failed creation results, or gap packs.',
      'Only npm run review:release-readiness passing on the final evidence set closes this release gate.',
    ],
  };
}

function buildGapPack() {
  const status = readJsonIfExists('docs/release_readiness_status.json');
  const figmaEvidence = readJsonIfExists('docs/release_figma_handoff_evidence.json');
  const canvaEvidence = readJsonIfExists('docs/release_canva_handoff_evidence.json');
  const githubEvidence = readJsonIfExists('docs/release_github_handoff_evidence.json');
  const readinessResult = readJsonIfExists(latestExistingEvidencePath([
    'release-readiness-result.json',
    'release-readiness-current-result.json',
  ]));
  const releaseEvidenceResult = readJsonIfExists(latestExistingEvidencePath([
    'release-evidence-result.json',
    'release-evidence-current-result.json',
  ]));
  const draftReviewResult = readJsonIfExists(latestExistingEvidencePath([
    'release-evidence-draft-review-result.json',
    'release-evidence-draft-review-current-result.json',
  ]));
  const designCreateResult = readJsonIfExists(latestDesignManifestCreateResultPath());
  const otaCreateResult = readJsonIfExists(latestOtaAttestationCreateResultPath());
  const promotionResult = readJsonIfExists(latestPromotionResultPath());
  const prCandidateResult = readJsonIfExists(latestExistingEvidencePath([
    'release-pr-candidates-result.json',
    'release-pr-candidates-current-result.json',
  ]));
  const stagedScopeResult = readJsonIfExists(latestExistingEvidencePath([
    'release-staged-scope-result.json',
    'release-staged-scope-current-result.json',
  ]));
  const externalStateResult = readJsonIfExists(latestExistingEvidencePath([
    'release-external-state-result.json',
    'release-external-state-current-result.json',
  ]));
  const designManifestPath = evidencePath('design_handoff_manifest.json');
  const otaAttestationPath = evidencePath('ota_credential_rotation_attestation.json');
  const gitStatus = runGitStatus();
  const gitDiagnostics = externalStateDiagnostics(externalStateResult, gitStatus);
  const quarantineBundle = summarizeWorktreeQuarantine(worktreeQuarantineManifestPath());
  const localWorktreeClosePlan = worktreeClosePlan(gitDiagnostics, quarantineBundle);
  const latestPrCandidates = prCandidateSummary(prCandidateResult);
  const githubConnector = githubEvidence.data?.latest_connector_check || null;
  const currentPrConnector = status.data?.current_pr?.connector_evidence || null;
  const currentPrConnectorEvidence = currentPrConnector ? {
    ...currentPrConnector,
    checked_at: githubConnector?.checked_at || currentPrConnector.checked_at || null,
  } : null;
  const currentPrStatusWithConnectorEvidence = status.data?.current_pr ? {
    number: status.data.current_pr.number ?? null,
    status: status.data.current_pr.status || 'unknown',
    checks: status.data.current_pr.checks || 'unknown',
    source_of_truth: status.data.current_pr.source_of_truth || 'unknown',
    connector_evidence: currentPrConnectorEvidence,
    pr_candidate_review: status.data.current_pr.pr_candidate_review || null,
  } : null;
  const latestExternalState = {
    path: externalStateResult.path,
    exists: externalStateResult.exists,
    status: externalStateResult.data?.status || 'unknown',
    expected_release_pr_number: externalStateResult.data?.expected_release_pr_number ?? null,
    expected_local_head_sha: externalStateResult.data?.expected_local_head_sha ?? null,
    expected_release_pr_head_sha: externalStateResult.data?.expected_release_pr_head_sha ?? null,
    failures: compactFailures(externalStateResult),
    diagnostics: gitDiagnostics,
  };

  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    command: 'npm run report:release-evidence-gap-pack',
    release_ready: false,
    evidence_dir: evidenceDir,
    source_status: {
      release_readiness_status: {
        path: status.path,
        exists: status.exists,
        overall_status: status.data?.overall_status || 'unknown',
        current_pr: currentPrStatusWithConnectorEvidence,
      },
      latest_release_readiness_result: {
        path: readinessResult.path,
        exists: readinessResult.exists,
        mode: readinessResult.data?.mode || 'unknown',
        final_release_ready: readinessResult.data?.final_release_ready === true,
        status: readinessResult.data?.status || 'unknown',
        failures: compactFailures(readinessResult),
      },
      latest_release_evidence_result: {
        path: releaseEvidenceResult.path,
        exists: releaseEvidenceResult.exists,
        status: releaseEvidenceResult.data?.status || 'unknown',
        failures: compactFailures(releaseEvidenceResult),
      },
      latest_release_draft_review_result: {
        path: draftReviewResult.path,
        exists: draftReviewResult.exists,
        can_promote_all: draftReviewResult.data?.can_promote_all === true,
        summary: draftReviewResult.data?.summary || null,
        sections: Array.isArray(draftReviewResult.data?.sections)
          ? draftReviewResult.data.sections.map((section) => ({
            id: section.id,
            draft_file: section.draft_file || null,
            final_file: section.final_file || null,
            acceptance_command: section.acceptance_command || null,
            can_promote: section.can_promote === true,
            blocking_fields: Array.isArray(section.blocking_fields) ? section.blocking_fields : [],
            required_operator_inputs: Array.isArray(section.required_operator_inputs) ? section.required_operator_inputs : [],
            warnings: Array.isArray(section.warnings) ? section.warnings : [],
            failures: Array.isArray(section.failures) ? section.failures : [],
          }))
          : [],
      },
      latest_release_promotion_result: {
        path: promotionResult.path,
        exists: promotionResult.exists,
        status: promotionResult.data?.status || 'unknown',
        can_promote: promotionResult.data?.can_promote === true,
        does_not_close_release_readiness: promotionResult.data?.does_not_close_release_readiness === true,
        summary: promotionResult.data?.summary || null,
        copied_final_files: Array.isArray(promotionResult.data?.copied_final_files)
          ? promotionResult.data.copied_final_files
          : [],
        failures: compactFailures(promotionResult),
      },
      latest_design_manifest_create_result: {
        path: designCreateResult.path,
        exists: designCreateResult.exists,
        status: designCreateResult.data?.status || 'unknown',
        can_create_manifest: designCreateResult.data?.can_create_manifest === true,
        does_not_close_release_readiness: designCreateResult.data?.does_not_close_release_readiness === true,
        summary: designCreateResult.data?.summary || null,
        failures: compactFailures(designCreateResult),
      },
      latest_ota_attestation_create_result: {
        path: otaCreateResult.path,
        exists: otaCreateResult.exists,
        status: otaCreateResult.data?.status || 'unknown',
        can_create_attestation: otaCreateResult.data?.can_create_attestation === true,
        does_not_close_release_readiness: otaCreateResult.data?.does_not_close_release_readiness === true,
        summary: otaCreateResult.data?.summary || null,
        failures: compactFailures(otaCreateResult),
      },
      latest_release_pr_candidates_result: latestPrCandidates,
      latest_release_staged_scope_result: {
        path: stagedScopeResult.path,
        exists: stagedScopeResult.exists,
        status: stagedScopeResult.data?.status || 'unknown',
        does_not_close_release_readiness: stagedScopeResult.data?.does_not_close_release_readiness === true,
        failures: compactFailures(stagedScopeResult),
      },
      latest_external_state_result: latestExternalState,
      local_git_status: gitStatus,
      local_worktree_close_plan: localWorktreeClosePlan,
    },
    connector_status: connectorSummary(figmaEvidence, canvaEvidence, githubEvidence),
    readiness_close_sequence: readinessCloseSequence(designManifestPath, otaAttestationPath),
    operator_intake_packet: operatorIntakePacket(
      designManifestPath,
      otaAttestationPath,
      localWorktreeClosePlan,
      currentPrStatusWithConnectorEvidence,
      latestPrCandidates,
      latestExternalState,
    ),
    blocking_requirements: [
      {
        id: 'design-handoff-missing',
        status: fs.existsSync(designManifestPath) ? 'candidate_present_requires_review' : 'missing',
        required_evidence_file: designManifestPath,
        acceptance_command: 'npm run review:release-design',
        current_evidence: [
          'docs/release_figma_handoff_evidence.json',
          'docs/release_canva_handoff_evidence.json',
          draftReviewResult.path,
          designCreateResult.path,
          promotionResult.path,
        ],
        next_actions: [
          'Reauthenticate Figma and Canva connectors or provide independently controlled accessible Figma, Canva, and Brand Kit source links.',
          'Create a controlled design_handoff_manifest.json outside the repository with owner, review date inside the 30-day release evidence window, design token path, required covered flows, and empty open_issues.',
          'Run npm run review:release-design, then npm run review:release-readiness.',
        ],
        forbidden_closure: [
          'Do not use screenshots, exported images, standalone token files, placeholders, or connector errors as release design handoff evidence.',
        ],
      },
      {
        id: 'ota-credential-rotation-attestation-missing',
        status: fs.existsSync(otaAttestationPath) ? 'candidate_present_requires_review' : 'missing',
        required_evidence_file: otaAttestationPath,
        acceptance_command: 'npm run review:release-ota-credentials',
        current_evidence: [
          'docs/ota_credential_rotation_attestation.example.json',
          releaseEvidenceResult.path,
          draftReviewResult.path,
          otaCreateResult.path,
          promotionResult.path,
        ],
        next_actions: [
          'Have the accountable operator rotate, invalidate, encrypt-archive, or sanitize the actual Ctrip and Meituan OTA credential material.',
          'Create a credential-free ota_credential_rotation_attestation.json outside the repository with redaction_checked=true and real audit references.',
          'Run git ls-files database/backups, npm run review:release-ota-credentials, then npm run review:release-readiness.',
        ],
        forbidden_closure: [
          'Do not include Cookie, token, usertoken, usersign, signature, Authorization, password, or reusable login state values.',
          'Do not treat a clean backup scan as proof that platform credentials were rotated.',
        ],
      },
      {
        id: 'local-git-state-open',
        status: externalStateResult.data?.status === 'passed' ? 'candidate_passed' : 'open',
        required_result_file: evidencePath('release-external-state-result.json'),
        acceptance_command: 'npm run review:release-external-state',
        current_evidence: [
          'docs/release_github_handoff_evidence.json',
          prCandidateResult.path,
          stagedScopeResult.path,
          externalStateResult.path,
          localWorktreeClosePlan.quarantine_bundle.path,
          'git status --short --branch',
        ],
        worktree_close_hint: worktreeCloseHint(gitDiagnostics),
        worktree_close_plan: localWorktreeClosePlan,
        next_actions: [
          'Select the actual open final release PR with RELEASE_PR_NUMBER after design and OTA isolated gates pass.',
          'Keep the local worktree clean or intentionally isolate reviewed release evidence changes.',
          'Run npm run review:release-staged-scope and npm run review:release-external-state so review:release-readiness consumes passing staged-scope and external-state results outside the repository.',
        ],
        forbidden_closure: [
          'Do not reuse merged PR #2 as the final handoff target.',
          'Do not claim final readiness from historical green checks or verify:release-status alone.',
        ],
      },
    ],
    final_gate: {
      command: 'npm run review:release-readiness',
      required_to_pass: true,
      current_expected_status: 'failed_until_blocking_requirements_close',
    },
  };
}

function renderDraftSectionMarkdown(section) {
  const lines = [];
  lines.push(`### ${stringifyAsciiText(section.id || 'unknown-section')}`);
  lines.push('');
  lines.push(markdownTable(
    ['Can promote', 'Acceptance command', 'Draft file', 'Final file', 'Failure count'],
    [[
      section.can_promote === true ? 'yes' : 'no',
      section.acceptance_command || 'n/a',
      section.draft_file || 'n/a',
      section.final_file || 'n/a',
      Array.isArray(section.failures) ? section.failures.length : 0,
    ]],
  ));
  lines.push('');

  const blockingFields = Array.isArray(section.blocking_fields) ? section.blocking_fields : [];
  if (blockingFields.length > 0) {
    lines.push('Blocking fields:');
    lines.push('');
    lines.push(markdownTable(
      ['Path', 'Action', 'Hint', 'Source failure'],
      blockingFields.map((field) => [
        field.path || 'n/a',
        field.action || 'n/a',
        field.hint || 'n/a',
        field.source_failure || field.failure || field.reason || 'n/a',
      ]),
    ));
  } else {
    lines.push('Blocking fields: None loaded.');
  }
  lines.push('');
  lines.push('Required operator inputs:');
  lines.push(markdownList(section.required_operator_inputs, 'None loaded.'));
  lines.push('');
  return lines.join('\n');
}

function renderStagingBucketMarkdown(bucketName, entries) {
  const normalizedEntries = Array.isArray(entries) ? entries : [];
  const lines = [];
  lines.push(`### ${stringifyAsciiText(bucketName)}`);
  lines.push('');
  if (normalizedEntries.length === 0) {
    lines.push('No entries.');
    lines.push('');
    return lines.join('\n');
  }

  lines.push(markdownTable(
    ['Status', 'Path', 'Category'],
    normalizedEntries.map((entry) => [
      entry.status || 'n/a',
      entry.path || 'n/a',
      entry.category || 'n/a',
    ]),
  ));
  lines.push('');
  return lines.join('\n');
}

function renderOperatorInputMarkdown(input) {
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
  lines.push(markdownList(input.required_operator_inputs, 'None loaded.'));
  lines.push('');
  lines.push('Forbidden inputs or closure shortcuts:');
  lines.push('');
  lines.push(markdownList(input.forbidden_inputs, 'None loaded.'));
  lines.push('');
  return lines.join('\n');
}

function renderConnectorStatusMarkdown(connectorStatus) {
  const figma = connectorStatus?.figma || null;
  const canva = Array.isArray(connectorStatus?.canva) ? connectorStatus.canva : [];
  const github = connectorStatus?.github || null;
  const rows = [];
  if (figma) {
    rows.push([
      'Figma',
      'mcp__codex_apps__figma._get_libraries',
      figma.checked_at || 'n/a',
      figma.result || 'unknown',
      figma.error_code || 'n/a',
      figma.reason || 'n/a',
    ]);
  }
  for (const check of canva) {
    rows.push([
      'Canva',
      check.tool || 'n/a',
      check.checked_at || 'n/a',
      check.result || 'unknown',
      check.error_code || 'n/a',
      check.reason || 'n/a',
    ]);
  }
  if (github) {
    rows.push([
      'GitHub',
      github.tool || 'mcp__codex_apps__github._get_users_recent_prs_in_repo',
      github.checked_at || 'n/a',
      github.result || 'unknown',
      github.state ? `open_pr_count=${github.pull_requests_count ?? 'unknown'}; state=${github.state}` : 'n/a',
      github.reason || 'n/a',
    ]);
  }
  if (rows.length === 0) {
    rows.push(['n/a', 'n/a', 'n/a', 'not_loaded', 'n/a', 'Connector status evidence was not loaded.']);
  }
  return [
    '## Connector Status',
    '',
    'These connector checks are blocker evidence only. They do not close design handoff or release readiness.',
    '',
    markdownTable(['Source', 'Tool', 'Checked at', 'Result', 'Error code', 'Reason'], rows),
    '',
  ].join('\n');
}

function renderGapPackMarkdown(gapPack) {
  const sourceStatus = gapPack.source_status || {};
  const draftReview = sourceStatus.latest_release_draft_review_result || {};
  const promotion = sourceStatus.latest_release_promotion_result || {};
  const designCreate = sourceStatus.latest_design_manifest_create_result || {};
  const otaCreate = sourceStatus.latest_ota_attestation_create_result || {};
  const prCandidates = sourceStatus.latest_release_pr_candidates_result || {};
  const stagedScope = sourceStatus.latest_release_staged_scope_result || {};
  const externalState = sourceStatus.latest_external_state_result || {};
  const readinessStatus = sourceStatus.release_readiness_status || {};
  const currentPr = readinessStatus.current_pr || {};
  const currentPrConnector = currentPr.connector_evidence || {};
  const worktreePlan = sourceStatus.local_worktree_close_plan || {};
  const stagingPlan = worktreePlan.staging_plan || {};
  const quarantineBundle = worktreePlan.quarantine_bundle || {};
  const isolationEvidence = worktreePlan.isolation_evidence || {};
  const operatorPacket = gapPack.operator_intake_packet || {};
  const finalPrHead = operatorPacket.final_pr_head_status || {};

  const lines = [];
  lines.push('# Release Evidence Gap Pack');
  lines.push('');
  lines.push('Status: not release-ready');
  lines.push('');
  lines.push('Do not treat this gap pack as release-ready evidence. It summarizes blockers only and does not replace design manifest, OTA credential rotation attestation, final PR selection, or clean local-state evidence.');
  lines.push('');
  lines.push(markdownTable(
    ['Field', 'Value'],
    [
      ['Generated at', gapPack.generated_at],
      ['Command', gapPack.command],
      ['Evidence dir', gapPack.evidence_dir],
      ['release_ready', gapPack.release_ready === true ? 'true' : 'false'],
      ['Final gate', gapPack.final_gate?.command || 'n/a'],
      ['Final gate expected status', gapPack.final_gate?.current_expected_status || 'n/a'],
      ['Machine status', readinessStatus.overall_status || 'unknown'],
      ['Current PR status', currentPr.status || 'unknown'],
      ['Current PR source', currentPr.source_of_truth || 'n/a'],
      ['Current PR connector evidence', currentPrConnector.path || 'n/a'],
      ['Current PR connector checked at', currentPrConnector.checked_at || 'n/a'],
      ['PR candidate gh checked at', prCandidates.gh_pr_list_checked_at || 'n/a'],
      ['PR candidate gh open PR count', prCandidates.gh_pr_list_open_pr_count ?? 'n/a'],
      ['PR connector stale for current review', prCandidates.github_connector_diagnostic?.is_stale_for_current_review === true ? 'yes' : 'no/not recorded'],
    ],
  ));
  lines.push('');

  lines.push('## Blocking Requirements');
  lines.push('');
  lines.push(markdownTable(
    ['ID', 'Status', 'Acceptance command', 'Required evidence or result'],
    (gapPack.blocking_requirements || []).map((requirement) => [
      requirement.id,
      requirement.status,
      requirement.acceptance_command,
      requirement.required_evidence_file || requirement.required_result_file || 'n/a',
    ]),
  ));
  lines.push('');

  lines.push('## Operator Intake Packet');
  lines.push('');
  lines.push('This section lists the external operator-controlled inputs still required before the final gate can pass. It is not release-ready evidence.');
  lines.push('');
  lines.push(markdownTable(
    ['Status', 'Closes readiness', 'Acceptance order'],
    [[
      operatorPacket.status || 'unknown',
      operatorPacket.does_not_close_release_readiness === true ? 'no' : 'unknown',
      operatorPacket.acceptance_order || 'n/a',
    ]],
  ));
  lines.push('');
  const operatorInputs = Array.isArray(operatorPacket.required_external_inputs)
    ? operatorPacket.required_external_inputs
    : [];
  for (const input of operatorInputs) {
    lines.push(renderOperatorInputMarkdown(input));
  }

  lines.push(renderConnectorStatusMarkdown(gapPack.connector_status || {}));

  lines.push('## Draft Evidence Blockers');
  lines.push('');
  lines.push(markdownTable(
    ['Path', 'Can promote all', 'Summary'],
    [[
      draftReview.path || 'n/a',
      draftReview.can_promote_all === true ? 'yes' : 'no',
      draftReview.summary || 'n/a',
    ]],
  ));
  lines.push('');
  lines.push(markdownTable(
    ['Promotion result', 'Status', 'Can promote', 'Summary', 'Failure count'],
    [[
      promotion.path || 'n/a',
      promotion.status || 'unknown',
      promotion.can_promote === true ? 'yes' : 'no',
      promotion.summary || 'n/a',
      Array.isArray(promotion.failures) ? promotion.failures.length : 0,
    ]],
  ));
  lines.push('');
  if (Array.isArray(promotion.failures) && promotion.failures.length > 0) {
    lines.push('Promotion failures:');
    lines.push('');
    lines.push(markdownList(promotion.failures));
    lines.push('');
  }
  lines.push('## Evidence Creation Attempts');
  lines.push('');
  lines.push(markdownTable(
    ['Type', 'Result path', 'Status', 'Can create', 'Summary', 'Failure count'],
    [
      [
        'Design manifest',
        designCreate.path || 'n/a',
        designCreate.status || 'unknown',
        designCreate.can_create_manifest === true ? 'yes' : 'no',
        designCreate.summary || 'n/a',
        Array.isArray(designCreate.failures) ? designCreate.failures.length : 0,
      ],
      [
        'OTA attestation',
        otaCreate.path || 'n/a',
        otaCreate.status || 'unknown',
        otaCreate.can_create_attestation === true ? 'yes' : 'no',
        otaCreate.summary || 'n/a',
        Array.isArray(otaCreate.failures) ? otaCreate.failures.length : 0,
      ],
    ],
  ));
  lines.push('');
  if (Array.isArray(designCreate.failures) && designCreate.failures.length > 0) {
    lines.push('Design creation failures:');
    lines.push('');
    lines.push(markdownList(designCreate.failures));
    lines.push('');
  }
  if (Array.isArray(otaCreate.failures) && otaCreate.failures.length > 0) {
    lines.push('OTA creation failures:');
    lines.push('');
    lines.push(markdownList(otaCreate.failures));
    lines.push('');
  }
  const draftSections = Array.isArray(draftReview.sections) ? draftReview.sections : [];
  if (draftSections.length > 0) {
    for (const section of draftSections) {
      lines.push(renderDraftSectionMarkdown(section));
    }
  } else {
    lines.push('No draft review sections were loaded.');
    lines.push('');
  }

  lines.push('## PR And Local State');
  lines.push('');
  lines.push(markdownTable(
    ['Item', 'Current state'],
    [
      ['PR candidate status', prCandidates.status || 'unknown'],
      ['Configured release PR number', prCandidates.configured_release_pr_number ?? 'not set'],
      ['Selected release PR number', prCandidates.selected_release_pr_number ?? 'not set'],
      ['Selected release PR head', prCandidates.selected_release_pr_head_sha || 'not recorded'],
      ['Viable PR candidates', prCandidates.viable_candidates ?? 0],
      ['PR candidate failures', Array.isArray(prCandidates.failures) ? prCandidates.failures.length : 0],
      ['Current PR status source', currentPr.source_of_truth || 'n/a'],
      ['Current PR connector tool', currentPrConnector.tool || 'n/a'],
      ['Current PR connector open PR count', currentPrConnector.pull_requests_count ?? 'n/a'],
      ['Current PR connector closes release', currentPrConnector.does_not_close_release_readiness === true ? 'no' : 'unknown'],
      ['Staged-scope status', stagedScope.status || 'unknown'],
      ['Staged-scope closes release', stagedScope.does_not_close_release_readiness === true ? 'no' : 'unknown'],
      ['Staged-scope failures', Array.isArray(stagedScope.failures) ? stagedScope.failures.length : 0],
      ['External-state status', externalState.status || 'unknown'],
      ['External-state expected PR number', externalState.expected_release_pr_number ?? 'not recorded'],
      ['External-state local HEAD', externalState.expected_local_head_sha || 'not recorded'],
      ['External-state expected PR head', externalState.expected_release_pr_head_sha || 'not recorded'],
      ['External-state failures', Array.isArray(externalState.failures) ? externalState.failures.length : 0],
      ['Final PR head match status', finalPrHead.status || 'unknown'],
      ['Final PR head close condition', finalPrHead.close_condition || 'n/a'],
      ['Worktree close status', worktreePlan.status || 'unknown'],
      ['Changed entries', worktreePlan.changed_entries ?? 'unknown'],
      ['Quarantine status', quarantineBundle.status || 'unknown'],
      ['Quarantine release staging counts', quarantineBundle.release_staging_plan?.counts || 'n/a'],
      ['Quarantine release staging review files', quarantineBundle.release_staging_plan?.review_files || 'n/a'],
      ['Isolation evidence status', isolationEvidence.status || 'unknown'],
      ['staging/isolation plan status', stagingPlan.status || 'unknown'],
      ['staging/isolation plan counts', stagingPlan.counts || 'n/a'],
    ],
  ));
  lines.push('');
  lines.push('Staging/isolation bucket entries:');
  lines.push('');
  for (const bucketName of [
    'candidate_release_scope',
    'needs_explicit_operator_decision',
    'must_remain_local_by_default',
  ]) {
    lines.push(renderStagingBucketMarkdown(bucketName, stagingPlan.buckets?.[bucketName]));
  }
  lines.push('Staging/isolation forbidden actions:');
  lines.push(markdownList(stagingPlan.forbidden_actions, 'None loaded.'));
  lines.push('');

  lines.push('## Close Sequence');
  lines.push('');
  lines.push(markdownTable(
    ['Order', 'ID', 'Command', 'Required input', 'Success condition'],
    (gapPack.readiness_close_sequence || []).map((step) => [
      step.order,
      step.id,
      step.command,
      step.required_input,
      step.success_condition,
    ]),
  ));
  lines.push('');

  lines.push('## Forbidden Closure');
  lines.push('');
  lines.push('Do not mark release readiness closed from templates, connector errors, draft files, quarantine bundles, structural status checks, or this gap pack.');
  lines.push('');
  return lines.join('\n');
}

const outputPath = process.env.RELEASE_EVIDENCE_GAP_PACK_FILE
  ? resolveInputPath(process.env.RELEASE_EVIDENCE_GAP_PACK_FILE)
  : evidencePath('release-evidence-gap-pack.json');
const markdownOutputPath = process.env.RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE
  ? resolveInputPath(process.env.RELEASE_EVIDENCE_GAP_PACK_MARKDOWN_FILE)
  : evidencePath('release-evidence-gap-pack.md');

if (isPathInsideRepo(outputPath)) {
  console.error(`Release evidence gap pack output must be outside the repository: ${outputPath}`);
  process.exit(1);
}
if (isPathInsideRepo(markdownOutputPath)) {
  console.error(`Release evidence gap pack Markdown output must be outside the repository: ${markdownOutputPath}`);
  process.exit(1);
}
if (path.resolve(outputPath) === path.resolve(markdownOutputPath)) {
  console.error('Release evidence gap pack JSON and Markdown outputs must use different paths.');
  process.exit(1);
}

const gapPack = buildGapPack();
fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${stringifyAsciiJson(gapPack)}\n`, 'utf8');
fs.mkdirSync(path.dirname(markdownOutputPath), { recursive: true });
fs.writeFileSync(markdownOutputPath, `${renderGapPackMarkdown(gapPack)}\n`, 'utf8');

console.log(`Wrote release evidence gap pack to ${outputPath}`);
console.log(`Wrote release evidence gap pack Markdown companion to ${markdownOutputPath}`);
console.log(`Blocking requirements: ${gapPack.blocking_requirements.map((item) => `${item.id}=${item.status}`).join(', ')}`);
console.log(`Worktree close plan: ${gapPack.source_status.local_worktree_close_plan.status} (${gapPack.source_status.local_worktree_close_plan.changed_entries} changed entries)`);
if (gapPack.source_status.latest_release_readiness_result.final_release_ready === true) {
  console.warn('WARN: Latest readiness result claims final release-ready; rerun npm run review:release-readiness before handoff.');
}
