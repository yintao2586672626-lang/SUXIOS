#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import path from 'node:path';

function parseArgs(argv) {
  const options = {
    baseRef: process.env.SUXI_PR_BASE_REF || 'origin/main',
    headRef: process.env.SUXI_PR_HEAD_REF || 'HEAD',
    repoRoot: process.cwd(),
  };
  for (const argument of argv) {
    if (argument.startsWith('--base-ref=')) {
      options.baseRef = argument.slice('--base-ref='.length);
    } else if (argument.startsWith('--head-ref=')) {
      options.headRef = argument.slice('--head-ref='.length);
    } else if (argument.startsWith('--repo-root=')) {
      options.repoRoot = argument.slice('--repo-root='.length);
    } else {
      throw new Error(`unsupported_argument:${argument}`);
    }
  }
  options.repoRoot = path.resolve(options.repoRoot);
  return options;
}

function validatedRef(value, label) {
  const ref = String(value || '').trim();
  if (
    ref === ''
    || ref.length > 255
    || ref.startsWith('-')
    || /[\u0000-\u0020\u007f~^:?*[\\]/.test(ref)
    || ref.includes('..')
    || ref.includes('@{')
  ) {
    throw new Error(`${label}_invalid`);
  }
  return ref;
}

function runGit(repoRoot, args, { allowExitOne = false } = {}) {
  const result = spawnSync('git', ['-C', repoRoot, ...args], {
    encoding: 'utf8',
    timeout: 30_000,
  });
  if (result.error) {
    throw new Error(`git_unavailable:${result.error.message}`);
  }
  if (result.status !== 0 && !(allowExitOne && result.status === 1)) {
    const detail = String(result.stderr || result.stdout || 'git_failed').trim();
    throw new Error(detail);
  }
  return result;
}

function verify(options) {
  const baseRef = validatedRef(options.baseRef, 'base_ref');
  const headRef = validatedRef(options.headRef, 'head_ref');
  runGit(options.repoRoot, ['rev-parse', '--is-inside-work-tree']);
  const baseSha = runGit(options.repoRoot, ['rev-parse', '--verify', `${baseRef}^{commit}`]).stdout.trim();
  const headSha = runGit(options.repoRoot, ['rev-parse', '--verify', `${headRef}^{commit}`]).stdout.trim();
  const counts = runGit(
    options.repoRoot,
    ['rev-list', '--left-right', '--count', `${baseRef}...${headRef}`]
  ).stdout.trim().split(/\s+/).map(Number);
  const [baseOnlyCount, headOnlyCount] = counts;
  if (!Number.isInteger(baseOnlyCount) || !Number.isInteger(headOnlyCount)) {
    throw new Error('git_divergence_unreadable');
  }
  const ancestor = runGit(
    options.repoRoot,
    ['merge-base', '--is-ancestor', baseRef, headRef],
    { allowExitOne: true }
  ).status === 0;
  const ready = ancestor && baseOnlyCount === 0;
  return {
    schema_version: 1,
    status: ready ? 'ready' : 'blocked',
    reason: ready ? 'head_contains_latest_base' : 'head_missing_base_commits',
    repo_root: options.repoRoot,
    base_ref: baseRef,
    base_sha: baseSha,
    head_ref: headRef,
    head_sha: headSha,
    base_only_count: baseOnlyCount,
    head_only_count: headOnlyCount,
  };
}

try {
  const result = verify(parseArgs(process.argv.slice(2)));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (result.status !== 'ready') {
    process.stderr.write(
      `PR head is behind ${result.base_ref} by ${result.base_only_count} commit(s). Sync the same project branch before merging.\n`
    );
    process.exitCode = 1;
  }
} catch (error) {
  const result = {
    schema_version: 1,
    status: 'error',
    reason: 'freshness_verification_failed',
    message: error instanceof Error ? error.message : String(error),
  };
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  process.stderr.write('Unable to verify PR base freshness.\n');
  process.exitCode = 1;
}
