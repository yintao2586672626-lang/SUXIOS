#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs } from './lib/shared_helpers.mjs';

const scriptRepoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = parseArgs(process.argv.slice(2));
const repoRoot = path.resolve(args.repo || scriptRepoRoot);
const outputJson = args.json === 'true';
const failOnRisk = args.failOnRisk === 'true';

function runGit(gitArgs, options = {}) {
  const result = spawnSync('git', ['-c', 'core.quotePath=false', ...gitArgs], {
    cwd: repoRoot,
    encoding: options.encoding ?? 'utf8',
    shell: false,
  });
  if (result.status !== 0 && !options.allowFailure) {
    const detail = result.error ? String(result.error.message || result.error) : String(result.stderr || 'git failed');
    throw new Error(`git ${gitArgs.join(' ')} failed: ${detail}`);
  }
  return result;
}

function normalizePath(value) {
  return String(value || '').replace(/\\/g, '/').replace(/^"|"$/g, '');
}

function isAgentPath(relativePath) {
  const normalized = normalizePath(relativePath);
  return normalized === '.agents' || normalized.startsWith('.agents/');
}

function statusEntries() {
  const status = runGit(['status', '--porcelain=v1', '-z'], { encoding: 'buffer' });
  return status.stdout.toString('utf8').split('\0').filter(Boolean).map((entry) => ({
    status: entry.slice(0, 2),
    path: normalizePath(entry.slice(3)),
  }));
}

function branchStatus() {
  return runGit(['status', '--short', '--branch']).stdout.trimEnd();
}

function resolveUpstream(branchLine) {
  if (args.upstream) {
    return String(args.upstream);
  }

  const explicit = runGit(['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}'], { allowFailure: true });
  if (explicit.status === 0 && explicit.stdout.trim()) {
    return explicit.stdout.trim();
  }

  const branch = branchLine.replace(/^##\s*/, '').split(/[ .[]/)[0];
  if (branch && branch !== 'HEAD') {
    return `origin/${branch}`;
  }

  return 'origin/codex/save-project-20260531';
}

function verifyCommit(ref) {
  return runGit(['rev-parse', '--verify', '--quiet', `${ref}^{commit}`], { allowFailure: true }).status === 0;
}

function nulPathSet(gitArgs) {
  const result = runGit(gitArgs, { encoding: 'buffer' });
  return new Set(result.stdout.toString('utf8').split('\0').filter(Boolean).map(normalizePath));
}

function aheadBehind(upstream) {
  const result = runGit(['rev-list', '--left-right', '--count', `HEAD...${upstream}`], { allowFailure: true });
  if (result.status !== 0) {
    return { ahead: null, behind: null, error: String(result.stderr || 'rev-list failed').trim() };
  }
  const [ahead, behind] = result.stdout.trim().split(/\s+/).map((value) => Number(value));
  return {
    ahead: Number.isFinite(ahead) ? ahead : null,
    behind: Number.isFinite(behind) ? behind : null,
    error: '',
  };
}

function fileText(relativePath) {
  const target = path.join(repoRoot, relativePath);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function regressionSignals() {
  const meituanStatic = fileText('public/meituan-static.js');
  const signals = [];
  if (!meituanStatic.includes('const requestBody = { ...task.body, async: false, background: false }')) {
    signals.push({
      code: 'meituan_manual_batch_not_direct',
      path: 'public/meituan-static.js',
      message: 'Meituan manual ranking fetch is not requesting direct results.',
    });
  }
  if (meituanStatic.includes('const requestBody = { ...task.body, async: true, background: true }')) {
    signals.push({
      code: 'meituan_manual_batch_background_default',
      path: 'public/meituan-static.js',
      message: 'Meituan manual ranking fetch is still submitting background work by default.',
    });
  }
  if (!meituanStatic.includes('await Promise.all(fetchTasks.map(async (task, index) => {')) {
    signals.push({
      code: 'meituan_manual_batch_not_concurrent',
      path: 'public/meituan-static.js',
      message: 'Meituan manual ranking fetch is not requesting independent rank results concurrently.',
    });
  }
  if (!meituanStatic.includes('const modelRes = await requestDisplayModel')) {
    signals.push({
      code: 'meituan_manual_batch_display_compat_missing',
      path: 'public/meituan-static.js',
      message: 'Meituan manual ranking fetch lost direct-response display compatibility.',
    });
  }
  return signals;
}

function uniqueRows(rows) {
  const seen = new Set();
  const result = [];
  for (const row of rows) {
    const key = `${row.status}:${row.path}:${row.reason || ''}`;
    if (!seen.has(key)) {
      seen.add(key);
      result.push(row);
    }
  }
  return result;
}

function inspect() {
  const branch = branchStatus();
  const branchLine = branch.split(/\r?\n/).find((line) => line.startsWith('##')) || '';
  const upstream = resolveUpstream(branchLine);
  const upstreamAvailable = verifyCommit(upstream);
  const entries = statusEntries();
  const nonAgent = entries.filter((entry) => !isAgentPath(entry.path));
  const agent = entries.filter((entry) => isAgentPath(entry.path));

  let upstreamChanged = new Set();
  let upstreamTracked = new Set();
  let divergence = { ahead: null, behind: null, error: upstreamAvailable ? '' : `Upstream ref is not available: ${upstream}` };
  if (upstreamAvailable) {
    divergence = aheadBehind(upstream);
    upstreamChanged = nulPathSet(['diff', '--name-only', '-z', `HEAD...${upstream}`]);
    upstreamTracked = nulPathSet(['ls-tree', '-r', '--name-only', '-z', upstream]);
  }

  const colliding = [];
  const untrackedWouldBeOverwritten = [];
  const localOnly = [];
  for (const entry of nonAgent) {
    if (entry.status === '??' && upstreamTracked.has(entry.path)) {
      untrackedWouldBeOverwritten.push({ ...entry, reason: 'untracked path exists upstream' });
      continue;
    }
    if (upstreamChanged.has(entry.path)) {
      colliding.push({ ...entry, reason: 'path changed upstream and locally' });
      continue;
    }
    localOnly.push({ ...entry, reason: 'local-only non-agent change' });
  }

  const regressions = regressionSignals();
  const risks = [
    ...colliding.map((entry) => ({ code: 'sync_conflict_risk', ...entry })),
    ...untrackedWouldBeOverwritten.map((entry) => ({ code: 'untracked_overwrite_risk', ...entry })),
    ...regressions,
  ];

  return {
    schema_version: 1,
    repo_root: repoRoot,
    branch_line: branchLine.replace(/^##\s*/, ''),
    upstream,
    upstream_available: upstreamAvailable,
    ahead: divergence.ahead,
    behind: divergence.behind,
    divergence_error: divergence.error,
    dirty_paths: entries.length,
    non_agent_dirty_paths: nonAgent.length,
    agent_dirty_paths: agent.length,
    upstream_changed_paths: upstreamChanged.size,
    risks: {
      count: risks.length,
      sync_conflict_paths: uniqueRows(colliding),
      untracked_would_be_overwritten: uniqueRows(untrackedWouldBeOverwritten),
      regression_signals: regressions,
    },
    local_only_non_agent_changes: uniqueRows(localOnly),
    agent_changes: uniqueRows(agent),
    recommendation: risks.length > 0 || ((divergence.behind ?? 0) > 0 && nonAgent.length > 0)
      ? 'Do not pull, merge, or commit from this worktree until risky local changes are quarantined or reviewed.'
      : 'No high-risk divergence detected by this inspector.',
  };
}

function renderText(result) {
  console.log('Worktree divergence inspection');
  console.log(`Repo: ${result.repo_root}`);
  console.log(`Branch: ${result.branch_line || '(unknown)'}`);
  console.log(`Upstream: ${result.upstream}${result.upstream_available ? '' : ' (missing)'}`);
  console.log(`Ahead/behind: ${result.ahead ?? '?'} ahead / ${result.behind ?? '?'} behind`);
  console.log(`Dirty paths: ${result.dirty_paths} (${result.non_agent_dirty_paths} non-agent, ${result.agent_dirty_paths} .agents)`);
  console.log(`Upstream changed paths: ${result.upstream_changed_paths}`);
  console.log(`Risk count: ${result.risks.count}`);

  for (const [title, rows] of [
    ['Sync conflict risk', result.risks.sync_conflict_paths],
    ['Untracked overwrite risk', result.risks.untracked_would_be_overwritten],
    ['Regression signals', result.risks.regression_signals],
  ]) {
    if (rows.length === 0) {
      continue;
    }
    console.log(`\n${title}:`);
    for (const row of rows.slice(0, 30)) {
      console.log(`- ${row.path}${row.code ? ` [${row.code}]` : ''}${row.reason ? `: ${row.reason}` : ''}${row.message ? `: ${row.message}` : ''}`);
    }
    if (rows.length > 30) {
      console.log(`- ... ${rows.length - 30} more`);
    }
  }

  if (result.local_only_non_agent_changes.length > 0) {
    console.log(`\nLocal-only non-agent changes: ${result.local_only_non_agent_changes.length}`);
    for (const row of result.local_only_non_agent_changes.slice(0, 20)) {
      console.log(`- ${row.path} (${row.status.trim() || 'changed'})`);
    }
  }

  if (result.agent_changes.length > 0) {
    console.log(`\nAgent-local changes: ${result.agent_changes.length}`);
  }

  console.log(`\nRecommendation: ${result.recommendation}`);
}

try {
  const result = inspect();
  if (outputJson) {
    console.log(JSON.stringify(result, null, 2));
  } else {
    renderText(result);
  }
  if (failOnRisk && result.risks.count > 0) {
    process.exit(1);
  }
} catch (error) {
  console.error(String(error?.message || error));
  process.exit(1);
}
