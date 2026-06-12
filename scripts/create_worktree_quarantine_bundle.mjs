#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs, timestamp } from './lib/shared_helpers.mjs';

const scriptRepoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = parseArgs(process.argv.slice(2));
const repoRoot = path.resolve(args.repo || scriptRepoRoot);
const dryRun = args.dryRun === 'true';
const includeAgents = args.includeAgents !== 'false';
const outputRoot = path.resolve(args.output || path.join(repoRoot, 'reports', 'worktree-quarantine', timestamp()));

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

function runNodeScript(script, scriptArgs, options = {}) {
  const result = spawnSync(process.execPath, [path.join(scriptRepoRoot, 'scripts', script), ...scriptArgs], {
    cwd: scriptRepoRoot,
    encoding: options.encoding ?? 'utf8',
    shell: false,
  });
  if (result.status !== 0 && !options.allowFailure) {
    const detail = result.error ? String(result.error.message || result.error) : String(result.stderr || 'script failed');
    throw new Error(`${script} failed: ${detail}`);
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

function ensureInside(parent, target) {
  const relative = path.relative(parent, target);
  if (relative.startsWith('..') || path.isAbsolute(relative)) {
    throw new Error(`Refusing to write outside output root: ${target}`);
  }
}

function statusEntries() {
  const result = runGit(['status', '--porcelain=v1', '-z'], { encoding: 'buffer' });
  return result.stdout.toString('utf8').split('\0').filter(Boolean).map((entry) => ({
    status: entry.slice(0, 2),
    path: normalizePath(entry.slice(3)),
  }));
}

function trackedChanged(entries) {
  return entries
    .filter((entry) => entry.status !== '??')
    .filter((entry) => includeAgents || !isAgentPath(entry.path));
}

function untracked(entries) {
  return entries
    .filter((entry) => entry.status === '??')
    .filter((entry) => includeAgents || !isAgentPath(entry.path));
}

function changedPathArgs(entries) {
  return entries.map((entry) => entry.path);
}

function readDivergence() {
  const scriptArgs = [`--repo=${repoRoot}`, '--json'];
  if (args.upstream) {
    scriptArgs.push(`--upstream=${String(args.upstream)}`);
  }
  const result = runNodeScript('inspect_worktree_divergence.mjs', scriptArgs, { allowFailure: true });
  const start = result.stdout.indexOf('{');
  if (start < 0) {
    return {
      error: result.stderr || result.stdout || 'inspect_worktree_divergence did not return JSON',
    };
  }
  return JSON.parse(result.stdout.slice(start));
}

function writeFileSafe(relativePath, content, encoding = 'utf8') {
  const target = path.join(outputRoot, relativePath);
  ensureInside(outputRoot, target);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content, encoding);
}

function copyPathSafe(sourceRelativePath, targetRoot) {
  const source = path.join(repoRoot, sourceRelativePath);
  const target = path.join(outputRoot, targetRoot, sourceRelativePath);
  ensureInside(outputRoot, target);
  if (!fs.existsSync(source)) {
    return false;
  }
  const stat = fs.statSync(source);
  if (stat.isDirectory()) {
    fs.cpSync(source, target, { recursive: true, force: true });
  } else if (stat.isFile()) {
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
  } else {
    return false;
  }
  return true;
}

function gitDiffFor(paths, binary = true) {
  if (paths.length === 0) {
    return '';
  }
  const gitArgs = ['diff'];
  if (binary) {
    gitArgs.push('--binary');
  }
  gitArgs.push('HEAD', '--', ...paths);
  return runGit(gitArgs).stdout;
}

function buildManifest(entries, divergence, copiedUntracked) {
  const tracked = trackedChanged(entries);
  const loose = untracked(entries);
  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    repo_root: repoRoot,
    output_root: outputRoot,
    dry_run: dryRun,
    include_agents: includeAgents,
    base: {
      head: runGit(['rev-parse', 'HEAD']).stdout.trim(),
      branch: runGit(['status', '--short', '--branch']).stdout.split(/\r?\n/).find((line) => line.startsWith('##'))?.replace(/^##\s*/, '') || '',
    },
    divergence,
    changed_paths: entries,
    tracked_patch: tracked.length > 0 ? 'tracked.patch' : null,
    agent_patch: tracked.some((entry) => isAgentPath(entry.path)) ? 'agent.patch' : null,
    untracked_files: loose.map((entry) => ({
      path: entry.path,
      copied: copiedUntracked.includes(entry.path),
      target: copiedUntracked.includes(entry.path) ? normalizePath(path.join('untracked', entry.path)) : null,
    })),
    safe_next_steps: [
      'Review manifest.json and tracked.patch before changing the source worktree.',
      'Do not run git reset, clean, pull, or merge until the risky local changes are either preserved or explicitly discarded.',
      'After manual approval, sync the worktree to origin/codex/save-project-20260531 and rerun inspect:worktree-divergence.',
    ],
  };
}

function renderPlan(manifest) {
  console.log('Worktree quarantine bundle plan');
  console.log(`Repo: ${repoRoot}`);
  console.log(`Output: ${outputRoot}`);
  console.log(`Dry run: ${dryRun ? 'yes' : 'no'}`);
  console.log(`Changed paths: ${manifest.changed_paths.length}`);
  console.log(`Tracked patch: ${manifest.tracked_patch ? 'yes' : 'no'}`);
  console.log(`Untracked files: ${manifest.untracked_files.length}`);
  console.log(`Risk count: ${manifest.divergence?.risks?.count ?? 'unknown'}`);
  if (dryRun) {
    console.log('No files written.');
  }
}

try {
  if (!fs.existsSync(repoRoot)) {
    throw new Error(`Repo path does not exist: ${repoRoot}`);
  }

  const entries = statusEntries();
  const tracked = trackedChanged(entries);
  const loose = untracked(entries);
  const divergence = readDivergence();
  const trackedPaths = changedPathArgs(tracked.filter((entry) => !isAgentPath(entry.path)));
  const agentPaths = changedPathArgs(tracked.filter((entry) => isAgentPath(entry.path)));
  const copiedUntracked = [];

  const manifest = buildManifest(entries, divergence, copiedUntracked);

  if (!dryRun) {
    fs.mkdirSync(outputRoot, { recursive: true });
    if (trackedPaths.length > 0) {
      writeFileSafe('tracked.patch', gitDiffFor(trackedPaths));
    }
    if (agentPaths.length > 0) {
      writeFileSafe('agent.patch', gitDiffFor(agentPaths));
    }
    for (const entry of loose) {
      if (copyPathSafe(entry.path, 'untracked')) {
        copiedUntracked.push(entry.path);
      }
    }
    const finalManifest = buildManifest(entries, divergence, copiedUntracked);
    writeFileSafe('manifest.json', JSON.stringify(finalManifest, null, 2));
    writeFileSafe('README.md', [
      '# Worktree Quarantine Bundle',
      '',
      'This bundle preserves local dirty-worktree evidence without modifying source files.',
      '',
      '- `manifest.json`: structured status and risk inventory.',
      '- `tracked.patch`: non-agent tracked changes.',
      '- `agent.patch`: `.agents` tracked changes, when present.',
      '- `untracked/`: copied untracked files, when present.',
      '',
      'Do not treat this bundle as approval to reset or delete source changes.',
      '',
    ].join('\n'));
    renderPlan(finalManifest);
  } else {
    renderPlan(manifest);
  }
} catch (error) {
  console.error(String(error?.message || error));
  process.exit(1);
}
