#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const SHA_PATTERN = /^[a-f0-9]{40}$/i;
const RESULT_SCHEMA_VERSION = 'suxios.release-post-merge-result.v1';
const COMMAND_NAME = 'npm run review:release-post-merge';
const MAX_EVIDENCE_AGE_MS = 24 * 60 * 60 * 1000;
const FUTURE_CLOCK_TOLERANCE_MS = 5 * 60 * 1000;
const defaultRepoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export class ReleasePostMergeGateError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'ReleasePostMergeGateError';
    this.code = code;
  }
}

function fail(code, message) {
  throw new ReleasePostMergeGateError(code, message);
}

function normalizedExecution(result) {
  return {
    status: Number.isInteger(result?.status)
      ? result.status
      : (Number.isInteger(result?.code) ? result.code : null),
    stdout: String(result?.stdout || '').trim(),
    stderr: String(result?.stderr || '').trim(),
    error: result?.error || null,
  };
}

function defaultExecutor(command, args, { cwd }) {
  return spawnSync(command, args, {
    cwd,
    encoding: 'utf8',
    shell: false,
    timeout: 60_000,
    windowsHide: true,
  });
}

function compactCommandFailure(result) {
  const detail = result.stderr || result.stdout || result.error?.message || 'no diagnostic output';
  return detail.replace(/\s+/g, ' ').slice(0, 500);
}

function executeRaw(executor, command, args, cwd) {
  let result;
  try {
    result = normalizedExecution(executor(command, args, { cwd }));
  } catch (error) {
    fail('command_execution_failed', `${command} could not be executed: ${error instanceof Error ? error.message : String(error)}`);
  }
  return result;
}

function execute(executor, command, args, cwd) {
  const result = executeRaw(executor, command, args, cwd);
  if (result.error || result.status !== 0) {
    fail('command_failed', `${command} ${args.join(' ')} failed: ${compactCommandFailure(result)}`);
  }
  return result.stdout;
}

function parseJson(text, code, label) {
  try {
    const value = JSON.parse(String(text || ''));
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      fail(code, `${label} must be a JSON object.`);
    }
    return value;
  } catch (error) {
    if (error instanceof ReleasePostMergeGateError) {
      throw error;
    }
    fail(code, `${label} is not valid JSON.`);
  }
}

function requireSha(value, code, label) {
  const sha = String(value || '').trim();
  if (!SHA_PATTERN.test(sha)) {
    fail(code, `${label} must be a 40-character Git commit SHA.`);
  }
  return sha.toLowerCase();
}

function isInsidePath(parentPath, candidatePath) {
  const relative = path.relative(parentPath, candidatePath);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function resolveExternalEvidenceFile(repoRoot, configuredPath) {
  const rawPath = String(configuredPath || '').trim();
  if (!rawPath) {
    fail(
      'deployment_evidence_file_missing',
      'RELEASE_POST_MERGE_DEPLOYMENT_EVIDENCE_FILE must point to fresh production evidence outside the repository.'
    );
  }

  const resolvedPath = path.isAbsolute(rawPath) ? path.resolve(rawPath) : path.resolve(repoRoot, rawPath);
  let realRepoRoot;
  let realEvidencePath;
  try {
    realRepoRoot = fs.realpathSync(repoRoot);
    realEvidencePath = fs.realpathSync(resolvedPath);
  } catch {
    fail('deployment_evidence_file_unreadable', 'Deployment evidence file or repository path could not be resolved.');
  }

  if (isInsidePath(realRepoRoot, realEvidencePath)) {
    fail('deployment_evidence_inside_repo', 'Deployment evidence must be stored outside the repository.');
  }
  if (!fs.statSync(realEvidencePath).isFile()) {
    fail('deployment_evidence_not_file', 'Deployment evidence path must be a regular file.');
  }
  return realEvidencePath;
}

function readDeploymentEvidence(repoRoot, configuredPath) {
  const evidencePath = resolveExternalEvidenceFile(repoRoot, configuredPath);
  let evidence;
  try {
    evidence = parseJson(fs.readFileSync(evidencePath, 'utf8'), 'deployment_evidence_invalid_json', 'Deployment evidence');
  } catch (error) {
    if (error instanceof ReleasePostMergeGateError) {
      throw error;
    }
    fail('deployment_evidence_unreadable', 'Deployment evidence could not be read.');
  }
  return { evidencePath, evidence };
}

function verifyDeploymentEvidence(evidence, mainSha, now) {
  if (String(evidence.status || '').trim().toLowerCase() !== 'deployed') {
    fail('deployment_status_not_deployed', 'Deployment evidence status must be deployed; staged evidence cannot close the post-merge gate.');
  }
  const deployedSourceSha = requireSha(
    evidence.deployed_source_sha,
    'deployed_source_sha_invalid',
    'deployment evidence deployed_source_sha'
  );
  const deploymentSha = requireSha(
    evidence.deployment_sha,
    'deployment_sha_invalid',
    'deployment evidence deployment_sha'
  );
  if (deployedSourceSha !== mainSha) {
    fail('deployed_source_sha_mismatch', `Deployed source SHA ${deployedSourceSha} does not match main SHA ${mainSha}.`);
  }
  if (deploymentSha !== mainSha) {
    fail('deployment_sha_mismatch', `Deployment SHA ${deploymentSha} does not match main SHA ${mainSha}.`);
  }

  if (String(evidence.health?.status || '').toLowerCase() !== 'ok') {
    fail('production_health_not_ok', 'Deployment evidence health.status must be ok.');
  }
  if (evidence.health?.production_runtime_ready !== true) {
    fail('production_runtime_not_ready', 'Deployment evidence health.production_runtime_ready must be true.');
  }
  if (evidence.runtime_asset_identity?.match !== true) {
    fail('runtime_asset_identity_mismatch', 'Deployment evidence runtime_asset_identity.match must be true.');
  }

  const checkedAt = String(evidence.checked_at || '').trim();
  const checkedAtMs = Date.parse(checkedAt);
  if (!Number.isFinite(checkedAtMs)) {
    fail('deployment_evidence_checked_at_invalid', 'Deployment evidence checked_at must be a valid timestamp.');
  }
  const nowMs = now.getTime();
  if (checkedAtMs > nowMs + FUTURE_CLOCK_TOLERANCE_MS || nowMs - checkedAtMs > MAX_EVIDENCE_AGE_MS) {
    fail('deployment_evidence_stale', 'Deployment evidence checked_at must be within the last 24 hours.');
  }

  return {
    status: 'deployed',
    deployed_source_sha: deployedSourceSha,
    deployment_sha: deploymentSha,
    health_status: 'ok',
    production_runtime_ready: true,
    runtime_asset_identity_match: true,
    checked_at: checkedAt,
  };
}

function parseRemoteMain(stdout) {
  const lines = String(stdout || '').split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  if (lines.length !== 1) {
    fail('remote_main_unreadable', 'git ls-remote must return exactly one refs/heads/main row.');
  }
  const [shaValue, ref] = lines[0].split(/\s+/);
  const sha = requireSha(shaValue, 'remote_main_sha_invalid', 'remote main SHA');
  if (ref !== 'refs/heads/main') {
    fail('remote_main_ref_invalid', 'git ls-remote did not return refs/heads/main.');
  }
  return sha;
}

function githubRepositoryFromOrigin(originUrl) {
  const normalized = String(originUrl || '').trim().replace(/\/$/, '').replace(/\.git$/i, '');
  const match = normalized.match(/^https?:\/\/github\.com\/([^/]+)\/([^/]+)$/i)
    || normalized.match(/^git@github\.com:([^/]+)\/([^/]+)$/i)
    || normalized.match(/^ssh:\/\/git@github\.com\/([^/]+)\/([^/]+)$/i);
  return match ? `${match[1]}/${match[2]}` : null;
}

function verifyPrRelationship(executor, repoRoot, prHeadSha, mainSha) {
  const ancestor = executeRaw(executor, 'git', ['merge-base', '--is-ancestor', prHeadSha, mainSha], repoRoot);
  if (!ancestor.error && ancestor.status === 0) {
    return 'ancestor';
  }

  const prTree = executeRaw(executor, 'git', ['rev-parse', '--verify', `${prHeadSha}^{tree}`], repoRoot);
  const mainTree = executeRaw(executor, 'git', ['rev-parse', '--verify', `${mainSha}^{tree}`], repoRoot);
  if (!prTree.error && prTree.status === 0 && !mainTree.error && mainTree.status === 0) {
    const prTreeSha = String(prTree.stdout || '').trim().toLowerCase();
    const mainTreeSha = String(mainTree.stdout || '').trim().toLowerCase();
    if (SHA_PATTERN.test(prTreeSha) && prTreeSha === mainTreeSha) {
      return 'tree_equal';
    }
  }

  fail(
    'pr_head_not_in_main',
    `Merged PR head ${prHeadSha} is neither a verified ancestor of main ${mainSha} nor tree-equal to it.`
  );
}

function verifyCheckRuns(payload) {
  const runs = Array.isArray(payload.check_runs) ? payload.check_runs : null;
  if (!runs || !Number.isInteger(payload.total_count) || payload.total_count !== runs.length) {
    fail('check_runs_incomplete', 'GitHub check-runs response must include the complete, untruncated run set.');
  }
  if (runs.length === 0) {
    fail('check_runs_missing', 'GitHub main SHA has no check runs.');
  }

  const invalid = runs.filter((run) => (
    String(run?.name || '').trim() === ''
    || String(run?.status || '').toLowerCase() !== 'completed'
    || String(run?.conclusion || '').toLowerCase() !== 'success'
  ));
  if (invalid.length > 0) {
    fail('check_runs_not_successful', `GitHub main SHA has ${invalid.length} incomplete or unsuccessful check run(s).`);
  }
  return runs;
}

function verifyRequiredContexts(payload, checkRuns) {
  const contexts = [
    ...(Array.isArray(payload.contexts) ? payload.contexts : []),
    ...(Array.isArray(payload.checks) ? payload.checks.map((entry) => entry?.context) : []),
  ].map((value) => String(value || '').trim()).filter(Boolean);
  const requiredContexts = [...new Set(contexts)];
  if (requiredContexts.length === 0) {
    fail('required_contexts_missing', 'main branch protection has no required status-check contexts.');
  }

  const successfulNames = new Set(checkRuns
    .filter((run) => (
      String(run?.status || '').toLowerCase() === 'completed'
      && String(run?.conclusion || '').toLowerCase() === 'success'
    ))
    .map((run) => String(run.name || '').trim()));
  const missing = requiredContexts.filter((context) => !successfulNames.has(context));
  if (missing.length > 0) {
    fail('required_contexts_not_successful', `Required main checks are missing or unsuccessful: ${missing.join(', ')}.`);
  }
  return requiredContexts;
}

function evaluateGate({ repoRoot, env, now, executor }) {
  const status = execute(executor, 'git', ['status', '--porcelain=v1', '--untracked-files=all'], repoRoot);
  if (status !== '') {
    fail('worktree_not_clean', 'Local release checkout must be clean before post-merge verification.');
  }

  const localHeadSha = requireSha(
    execute(executor, 'git', ['rev-parse', '--verify', 'HEAD^{commit}'], repoRoot),
    'local_head_invalid',
    'local HEAD'
  );

  const originRepository = githubRepositoryFromOrigin(
    execute(executor, 'git', ['remote', 'get-url', 'origin'], repoRoot)
  );
  if (!originRepository) {
    fail('origin_not_github', 'origin must be a supported github.com repository URL.');
  }

  const repository = parseJson(
    execute(executor, 'gh', ['repo', 'view', '--json', 'nameWithOwner'], repoRoot),
    'github_repository_invalid',
    'gh repo view response'
  );
  const nameWithOwner = String(repository.nameWithOwner || '').trim();
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(nameWithOwner)) {
    fail('github_repository_invalid', 'gh repo view must return a valid GitHub nameWithOwner.');
  }
  if (originRepository.toLowerCase() !== nameWithOwner.toLowerCase()) {
    fail('github_repository_mismatch', `origin repository ${originRepository} does not match gh repository ${nameWithOwner}.`);
  }

  const remoteMainSha = parseRemoteMain(
    execute(executor, 'git', ['ls-remote', '--heads', 'origin', 'refs/heads/main'], repoRoot)
  );
  if (remoteMainSha !== localHeadSha) {
    fail('remote_main_mismatch', `GitHub main SHA ${remoteMainSha} does not match local HEAD ${localHeadSha}.`);
  }

  const prNumber = String(env.RELEASE_PR_NUMBER || '').trim();
  if (!/^[1-9]\d*$/.test(prNumber)) {
    fail('release_pr_number_missing', 'RELEASE_PR_NUMBER must identify the merged release PR.');
  }
  const pr = parseJson(
    execute(executor, 'gh', [
      'pr', 'view', prNumber,
      '--json', 'number,state,baseRefName,headRefOid,mergeCommit,url',
    ], repoRoot),
    'release_pr_invalid',
    'gh pr view response'
  );
  if (String(pr.number ?? '') !== prNumber) {
    fail('release_pr_number_mismatch', `gh pr view returned PR #${pr.number ?? 'unknown'}, expected #${prNumber}.`);
  }
  if (String(pr.state || '').toUpperCase() !== 'MERGED') {
    fail('release_pr_not_merged', `Release PR #${prNumber} must be MERGED.`);
  }
  if (String(pr.baseRefName || '') !== 'main') {
    fail('release_pr_base_mismatch', `Release PR #${prNumber} must target main.`);
  }
  const prHeadSha = requireSha(pr.headRefOid, 'release_pr_head_invalid', 'release PR head SHA');
  const mergeCommitSha = requireSha(pr.mergeCommit?.oid, 'release_pr_merge_commit_invalid', 'release PR merge commit SHA');
  if (mergeCommitSha !== localHeadSha) {
    fail('merged_pr_not_main_tip', `Release PR merge commit ${mergeCommitSha} does not match current main ${localHeadSha}.`);
  }
  const prRelationship = verifyPrRelationship(executor, repoRoot, prHeadSha, localHeadSha);

  const checkRunsPayload = parseJson(
    execute(executor, 'gh', [
      'api',
      '-H', 'Accept: application/vnd.github+json',
      `repos/${nameWithOwner}/commits/${localHeadSha}/check-runs?per_page=100`,
    ], repoRoot),
    'check_runs_invalid',
    'GitHub check-runs response'
  );
  const checkRuns = verifyCheckRuns(checkRunsPayload);

  const protectionPayload = parseJson(
    execute(executor, 'gh', [
      'api',
      '-H', 'Accept: application/vnd.github+json',
      `repos/${nameWithOwner}/branches/main/protection/required_status_checks`,
    ], repoRoot),
    'branch_protection_invalid',
    'GitHub branch-protection response'
  );
  const requiredContexts = verifyRequiredContexts(protectionPayload, checkRuns);

  const { evidencePath, evidence } = readDeploymentEvidence(
    repoRoot,
    env.RELEASE_POST_MERGE_DEPLOYMENT_EVIDENCE_FILE
  );
  const deployment = verifyDeploymentEvidence(evidence, localHeadSha, now);

  return {
    main_sha: localHeadSha,
    github_repository: nameWithOwner,
    release_pr: {
      number: Number(prNumber),
      url: String(pr.url || ''),
      state: 'MERGED',
      base: 'main',
      head_sha: prHeadSha,
      merge_commit_sha: mergeCommitSha,
      relationship_to_main: prRelationship,
    },
    github_checks: {
      total: checkRuns.length,
      required_contexts: requiredContexts,
    },
    deployment_evidence: {
      file: evidencePath,
      ...deployment,
    },
  };
}

function resolveResultFile(repoRoot, env) {
  const evidenceDir = path.resolve(repoRoot, String(env.RELEASE_EVIDENCE_DIR || '../release-evidence-temp'));
  const configured = String(env.RELEASE_POST_MERGE_RESULT_FILE || '').trim();
  return configured
    ? (path.isAbsolute(configured) ? path.resolve(configured) : path.resolve(repoRoot, configured))
    : path.join(evidenceDir, 'release-post-merge-result.json');
}

function resolveProspectiveRealPath(targetPath) {
  let ancestor = path.resolve(targetPath);
  const missingSegments = [];
  while (!fs.existsSync(ancestor)) {
    const parent = path.dirname(ancestor);
    if (parent === ancestor) {
      fail('result_path_unresolvable', 'RELEASE_POST_MERGE_RESULT_FILE has no resolvable parent path.');
    }
    missingSegments.unshift(path.basename(ancestor));
    ancestor = parent;
  }
  return path.resolve(fs.realpathSync(ancestor), ...missingSegments);
}

function writeResultOutsideRepo(repoRoot, resultFile, result) {
  const realRepoRoot = fs.realpathSync(repoRoot);
  const prospectiveResultFile = resolveProspectiveRealPath(resultFile);
  if (isInsidePath(realRepoRoot, prospectiveResultFile)) {
    fail('result_file_inside_repo', 'RELEASE_POST_MERGE_RESULT_FILE must be outside the repository.');
  }
  const directory = path.dirname(resultFile);
  fs.mkdirSync(directory, { recursive: true });
  const realDirectory = fs.realpathSync(directory);
  const realResultFile = path.join(realDirectory, path.basename(resultFile));
  if (isInsidePath(realRepoRoot, realResultFile)) {
    fail('result_file_inside_repo', 'RELEASE_POST_MERGE_RESULT_FILE must be outside the repository.');
  }
  fs.writeFileSync(realResultFile, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  return realResultFile;
}

export function verifyReleasePostMerge(options = {}) {
  const repoRoot = path.resolve(options.repoRoot || defaultRepoRoot);
  const env = options.env || process.env;
  const now = options.now instanceof Date ? options.now : new Date(options.now || Date.now());
  const executor = options.executor || defaultExecutor;
  const resultFile = resolveResultFile(repoRoot, env);
  let result;

  if (!Number.isFinite(now.getTime())) {
    result = {
      schema_version: RESULT_SCHEMA_VERSION,
      command: COMMAND_NAME,
      status: 'blocked',
      ready: false,
      checked_at: new Date().toISOString(),
      reason: 'verification_time_invalid',
      failures: [{ code: 'verification_time_invalid', message: 'Verification time is invalid.' }],
    };
  } else {
    try {
      const data = evaluateGate({ repoRoot, env, now, executor });
      result = {
        schema_version: RESULT_SCHEMA_VERSION,
        command: COMMAND_NAME,
        status: 'ready',
        ready: true,
        checked_at: now.toISOString(),
        reason: 'post_merge_release_verified',
        failures: [],
        data,
      };
    } catch (error) {
      const code = error instanceof ReleasePostMergeGateError ? error.code : 'post_merge_verification_failed';
      const message = error instanceof Error ? error.message : String(error);
      result = {
        schema_version: RESULT_SCHEMA_VERSION,
        command: COMMAND_NAME,
        status: 'blocked',
        ready: false,
        checked_at: now.toISOString(),
        reason: code,
        failures: [{ code, message }],
      };
    }
  }

  if (options.writeResult !== false) {
    try {
      result.result_file = writeResultOutsideRepo(repoRoot, resultFile, result);
    } catch (error) {
      const code = error instanceof ReleasePostMergeGateError ? error.code : 'result_write_failed';
      const message = error instanceof Error ? error.message : String(error);
      result = {
        ...result,
        status: 'blocked',
        ready: false,
        reason: code,
        failures: [...(result.failures || []), { code, message }],
      };
    }
  }
  return result;
}

const directInvocation = process.argv[1]
  && pathToFileURL(path.resolve(process.argv[1])).href === import.meta.url;
if (directInvocation) {
  const result = verifyReleasePostMerge();
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (!result.ready) {
    process.exitCode = 1;
  }
}
