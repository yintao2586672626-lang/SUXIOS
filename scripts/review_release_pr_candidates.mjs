import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { safeJsonParseErrorCode } from './lib/safe_json_parse_error.mjs';

const repoRoot = path.resolve(process.cwd());
const releaseEvidenceDir = path.resolve(repoRoot, process.env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp');
const resultOutputPath = path.resolve(
  process.env.RELEASE_PR_CANDIDATES_RESULT_FILE || path.join(releaseEvidenceDir, 'release-pr-candidates-result.json'),
);
const githubHandoffEvidencePath = path.resolve(
  process.env.RELEASE_GITHUB_HANDOFF_EVIDENCE_FILE || path.join(repoRoot, 'docs/release_github_handoff_evidence.json'),
);
const baseRef = String(process.env.RELEASE_PR_BASE_REF || 'main').trim();
const headRef = String(process.env.RELEASE_PR_HEAD_REF || '').trim();
const configuredReleasePrNumber = String(process.env.RELEASE_PR_NUMBER || '').trim();
const allowDraftCandidate = process.env.RELEASE_PR_CANDIDATES_ALLOW_DRAFT === '1';
const ghListAttempts = Math.max(1, Math.min(5, Number.parseInt(process.env.RELEASE_PR_CANDIDATES_GH_ATTEMPTS || '2', 10) || 2));
const candidatePolicy = allowDraftCandidate
  ? 'allow_draft_for_ready_transition'
  : 'final_non_draft_release';

const passes = [];
const warnings = [];
const failures = [];

function timestampDate(value) {
  const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})/);
  return match ? match[1] : null;
}

function shanghaiTimestamp(date = new Date()) {
  const shanghaiOffsetMs = 8 * 60 * 60 * 1000;
  return new Date(date.getTime() + shanghaiOffsetMs).toISOString().replace(/\.\d{3}Z$/, '+08:00');
}

function isPathInsideRepo(filePath) {
  const resolvedPath = path.resolve(filePath);
  const relativePath = path.relative(repoRoot, resolvedPath);
  return relativePath === '' || (!relativePath.startsWith('..') && !path.isAbsolute(relativePath));
}

function readJsonIfExists(filePath) {
  if (!fs.existsSync(filePath)) {
    return { exists: false, path: filePath, data: null, error: null };
  }

  try {
    return {
      exists: true,
      path: filePath,
      data: JSON.parse(fs.readFileSync(filePath, 'utf8')),
      error: null,
    };
  } catch (error) {
    return { exists: true, path: filePath, data: null, error: safeJsonParseErrorCode(error) };
  }
}

function connectorEvidenceSummary(evidence) {
  const connector = evidence.data?.latest_connector_check || null;
  return {
    path: evidence.path,
    exists: evidence.exists,
    error: evidence.error,
    status: evidence.data?.status || 'unknown',
    checked_at: evidence.data?.checked_at || connector?.checked_at || null,
    repository_full_name: evidence.data?.repository_full_name || null,
    release_base_ref: evidence.data?.release_base_ref || null,
    latest_connector_check: connector ? {
      checked_at: connector.checked_at || null,
      tool: connector.tool || null,
      state: connector.state || null,
      result: connector.result || null,
      pull_requests_count: Number.isFinite(Number(connector.pull_requests_count))
        ? Number(connector.pull_requests_count)
        : null,
      reason: connector.reason || null,
    } : null,
    does_not_close_release_readiness: evidence.data?.does_not_close_release_readiness === true,
  };
}

function connectorOpenPrDetail(summary, currentGhPrList) {
  const connector = summary.latest_connector_check || null;
  if (!connector) {
    return null;
  }
  if (
    connector.tool !== 'mcp__codex_apps__github._get_users_recent_prs_in_repo'
    || connector.state !== 'open'
    || Number(connector.pull_requests_count) !== 0
  ) {
    return null;
  }
  const checkedAt = connector.checked_at || summary.checked_at || 'unknown';
  const currentCheckedAt = currentGhPrList?.checked_at || 'unknown';
  const connectorDate = timestampDate(checkedAt);
  const currentDate = timestampDate(currentCheckedAt);
  const isStaleForCurrentReview = Boolean(connectorDate && currentDate && connectorDate !== currentDate);
  const staleText = isStaleForCurrentReview
    ? ` This connector snapshot is stale for the current review; current_gh_pr_list_checked_at=${currentCheckedAt}.`
    : '';
  return {
    message: `GitHub connector evidence ${summary.path} is diagnostic-only and also reports open_pr_count=0 checked_at=${checkedAt}.${staleText}`,
    warning: isStaleForCurrentReview
      ? `GitHub connector evidence is stale for current gh pr list review: connector_checked_at=${checkedAt}; gh_pr_list_checked_at=${currentCheckedAt}.`
      : null,
    checked_at: checkedAt === 'unknown' ? null : checkedAt,
    current_gh_pr_list_checked_at: currentCheckedAt === 'unknown' ? null : currentCheckedAt,
    pull_requests_count: Number(connector.pull_requests_count),
    is_stale_for_current_review: isStaleForCurrentReview,
    does_not_close_release_readiness: true,
  };
}

function run(command, args) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    shell: false,
  });
  return {
    status: result.status,
    stdout: String(result.stdout || '').trim(),
    stderr: String(result.stderr || '').trim(),
    error: result.error,
  };
}

function commandFailureDetail(result) {
  return result.stderr || result.error?.message || result.stdout || 'unknown error';
}

function runWithAttempts(command, args, attempts) {
  const records = [];
  let lastResult = null;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const result = run(command, args);
    lastResult = result;
    records.push({
      attempt,
      exit_code: result.status ?? (result.error ? 1 : 0),
      stderr: result.stderr,
      stdout: result.stdout,
      error: result.error?.message || null,
    });
    if (result.status === 0) {
      break;
    }
  }

  return {
    result: lastResult,
    records,
    attempted: records.length,
  };
}

function checkStatusChecks(pr) {
  const checks = Array.isArray(pr.statusCheckRollup) ? pr.statusCheckRollup : [];
  if (checks.length === 0) {
    return {
      passed: false,
      reason: 'no status checks were returned',
    };
  }

  const incomplete = checks.filter((check) => check.status !== 'COMPLETED');
  const failed = checks.filter((check) => check.status === 'COMPLETED' && check.conclusion !== 'SUCCESS');
  if (incomplete.length > 0 || failed.length > 0) {
    return {
      passed: false,
      reason: `${incomplete.length} incomplete checks and ${failed.length} failed checks`,
    };
  }

  return {
    passed: true,
    reason: `${checks.length} checks are green`,
  };
}

function evaluateCandidate(pr) {
  const reasons = [];
  const checks = checkStatusChecks(pr);
  if (pr.state !== 'OPEN') {
    reasons.push(`state is ${pr.state || 'missing'}`);
  }
  if (pr.isDraft !== false) {
    if (allowDraftCandidate && pr.isDraft !== true) {
      reasons.push('PR draft state is missing');
    } else if (!allowDraftCandidate) {
      reasons.push('PR is draft');
    }
  }
  if (baseRef && pr.baseRefName !== baseRef) {
    reasons.push(`baseRefName is ${pr.baseRefName || 'missing'}, expected ${baseRef}`);
  }
  if (headRef && pr.headRefName !== headRef) {
    reasons.push(`headRefName is ${pr.headRefName || 'missing'}, expected ${headRef}`);
  }
  if (pr.mergeable !== 'MERGEABLE') {
    reasons.push(`mergeable is ${pr.mergeable || 'missing'}`);
  }
  if (!/^[a-f0-9]{40}$/i.test(String(pr.headRefOid || ''))) {
    reasons.push('headRefOid is missing or not a 40-character commit sha');
  }
  if (!checks.passed) {
    reasons.push(checks.reason);
  }

  return {
    number: pr.number,
    url: pr.url,
    title: pr.title,
    baseRefName: pr.baseRefName,
    headRefName: pr.headRefName,
    headRefOid: pr.headRefOid,
    isDraft: pr.isDraft,
    mergeable: pr.mergeable,
    checks: checks.reason,
    candidate_policy: candidatePolicy,
    viable: reasons.length === 0,
    reasons,
  };
}

if (isPathInsideRepo(resultOutputPath)) {
  failures.push(`Release PR candidate result output must be stored outside the repository in a controlled evidence directory: ${resultOutputPath}.`);
}
if (configuredReleasePrNumber && !/^\d+$/.test(configuredReleasePrNumber)) {
  failures.push(`RELEASE_PR_NUMBER must be a numeric PR number when set; got ${configuredReleasePrNumber}.`);
}

const githubConnectorEvidence = connectorEvidenceSummary(readJsonIfExists(githubHandoffEvidencePath));

const args = [
  'pr',
  'list',
  '--state',
  'open',
  '--base',
  baseRef,
  '--limit',
  '50',
  '--json',
  'number,url,title,state,isDraft,headRefName,headRefOid,baseRefName,mergeable,statusCheckRollup,updatedAt',
];
const ghPrListCheckedAt = shanghaiTimestamp();
const prListReview = runWithAttempts('gh', args, ghListAttempts);
const prList = prListReview.result;
let candidates = [];
if (prList.status !== 0) {
  failures.push(`gh pr list failed after ${prListReview.attempted} attempt(s): ${commandFailureDetail(prList)}`);
} else {
  if (prListReview.attempted > 1) {
    warnings.push(`gh pr list succeeded after ${prListReview.attempted} attempts; keep the generated diagnostics with the release evidence.`);
  }
  try {
    candidates = JSON.parse(prList.stdout);
  } catch (error) {
    failures.push(`gh pr list did not return valid JSON (${safeJsonParseErrorCode(error)}).`);
  }
}

const reviewed = candidates.map(evaluateCandidate);
const viable = reviewed.filter((candidate) => candidate.viable);
const currentGhPrListDiagnostic = {
  checked_at: ghPrListCheckedAt,
  command: `gh ${args.join(' ')}`,
  base_ref: baseRef,
  head_ref: headRef || null,
  status: prList.status === 0 ? 'completed' : 'failed',
  open_pr_count: prList.status === 0 ? reviewed.length : null,
  source_of_truth_for_current_pr_candidates: true,
};
const githubConnectorDiagnostic = connectorOpenPrDetail(githubConnectorEvidence, currentGhPrListDiagnostic);
if (githubConnectorDiagnostic?.warning) {
  warnings.push(githubConnectorDiagnostic.warning);
}
let selected = null;

if (failures.length === 0) {
  if (reviewed.length === 0) {
    failures.push([
      `No open release PR candidates found for base ${baseRef} from current gh pr list checked_at=${currentGhPrListDiagnostic.checked_at}.`,
      githubConnectorDiagnostic?.message || null,
      'Create or reopen a final release PR, then set RELEASE_PR_NUMBER to its number before release handoff.',
    ].filter(Boolean).join(' '));
  } else {
    passes.push(`Found ${reviewed.length} open PR candidate(s) for base ${baseRef}.`);
  }

  if (configuredReleasePrNumber && reviewed.length > 0) {
    const configuredCandidate = reviewed.find((candidate) => String(candidate.number) === configuredReleasePrNumber);
    if (!configuredCandidate) {
      failures.push(`RELEASE_PR_NUMBER=${configuredReleasePrNumber} is not in open PR candidates for base ${baseRef}. Rerun npm run review:release-pr-candidates with the intended release base/head filters.`);
    } else if (!configuredCandidate.viable) {
      failures.push(`Configured release PR #${configuredCandidate.number} is not viable. Candidate blockers: ${configuredCandidate.reasons.join('; ')}`);
    } else {
      selected = configuredCandidate;
      if (allowDraftCandidate && selected.isDraft === true) {
        passes.push(`Selected configured draft release PR ready target #${selected.number}.`);
        warnings.push(`PR #${selected.number} is still draft; mark it ready only through npm run release:mark-pr-ready after pre-ready gates pass.`);
      } else {
        passes.push(`Selected configured release PR candidate #${selected.number}.`);
        warnings.push(`Rerun npm run review:release-external-state after local Git is clean and final evidence exists.`);
      }
      if (viable.length > 1) {
        warnings.push(`RELEASE_PR_NUMBER=${selected.number} selected one of ${viable.length} viable release PR candidates.`);
      }
    }
  } else if (viable.length === 1) {
    selected = viable[0];
    if (allowDraftCandidate && selected.isDraft === true) {
      passes.push(`Selected draft release PR ready target #${selected.number}.`);
      warnings.push(`PR #${selected.number} is still draft; mark it ready only through npm run release:mark-pr-ready after pre-ready gates pass.`);
    } else {
      passes.push(`Selected release PR candidate #${selected.number}.`);
      warnings.push(`Set RELEASE_PR_NUMBER=${selected.number}, then rerun npm run review:release-external-state after local Git is clean and final evidence exists.`);
    }
  } else if (viable.length > 1) {
    failures.push(`Multiple viable release PR candidates found: ${viable.map((candidate) => `#${candidate.number}`).join(', ')}. Set RELEASE_PR_NUMBER explicitly.`);
  } else if (reviewed.length > 0) {
    failures.push(`No viable release PR candidate found. Candidate blockers: ${reviewed.map((candidate) => `#${candidate.number}: ${candidate.reasons.join('; ')}`).join(' | ')}`);
  }
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

const result = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  command: 'npm run review:release-pr-candidates',
  status: failures.length > 0 ? 'failed' : 'passed',
  gh_pr_list_checked_at: currentGhPrListDiagnostic.checked_at,
  gh_pr_list_open_pr_count: currentGhPrListDiagnostic.open_pr_count,
  base_ref: baseRef,
  head_ref: headRef || null,
  allow_draft_candidate: allowDraftCandidate,
  candidate_policy: candidatePolicy,
  configured_release_pr_number: configuredReleasePrNumber || null,
  selected_release_pr_number: selected?.number ?? null,
  summary: {
    passed: passes.length,
    warnings: warnings.length,
    failures: failures.length,
    candidates: reviewed.length,
    viable_candidates: viable.length,
  },
  diagnostics: {
    current_gh_pr_list: currentGhPrListDiagnostic,
    github_connector_evidence: githubConnectorEvidence,
    github_connector_diagnostic: githubConnectorDiagnostic,
    gh_pr_list_attempts_configured: ghListAttempts,
    gh_pr_list_attempts_used: prListReview.attempted,
    gh_pr_list_attempts: prListReview.records,
  },
  passes,
  warnings,
  failures,
  candidates: reviewed,
};

if (!isPathInsideRepo(resultOutputPath)) {
  fs.mkdirSync(path.dirname(resultOutputPath), { recursive: true });
  fs.writeFileSync(resultOutputPath, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
}

console.log(`Release PR candidate summary: ${passes.length} passed, ${warnings.length} warnings, ${failures.length} failures, ${viable.length} viable candidates.`);

if (failures.length > 0) {
  process.exit(1);
}
