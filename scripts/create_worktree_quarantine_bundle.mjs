#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs, timestamp } from './lib/shared_helpers.mjs';
import {
  categorizeReleasePath,
  classifyReleaseWorktreeEntry,
  gitStatusCategoryOrder,
  isReleaseLocalArtifactPath,
  releaseStagingReviewFiles,
} from './lib/release_worktree_scope.mjs';

const scriptRepoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = parseArgs(process.argv.slice(2));
const repoRoot = path.resolve(args.repo || scriptRepoRoot);
const dryRun = args.dryRun === 'true';
const includeAgents = args.includeAgents !== 'false';
const includeLocalArtifacts = args.includeLocalArtifacts === 'true';
const outputRoot = path.resolve(args.output || path.join(repoRoot, 'reports', 'worktree-quarantine', timestamp()));

function runGit(gitArgs, options = {}) {
  const result = spawnSync('git', ['-c', 'core.quotePath=false', ...gitArgs], {
    cwd: repoRoot,
    encoding: options.encoding ?? 'utf8',
    maxBuffer: 64 * 1024 * 1024,
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
    maxBuffer: 64 * 1024 * 1024,
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

function buildReleaseStagingPlan(entries) {
  const buckets = {
    candidate_release_scope: [],
    needs_explicit_operator_decision: [],
    must_remain_local_by_default: [],
  };
  for (const entry of entries.map(classifyReleaseWorktreeEntry)) {
    buckets[entry.bucket].push(entry);
  }

  const byCategory = {};
  for (const category of gitStatusCategoryOrder) {
    const count = entries.filter((entry) => categorizeReleasePath(entry.path) === category).length;
    if (count > 0) {
      byCategory[category] = count;
    }
  }

  return {
    status: entries.length === 0 ? 'clean_no_release_staging_needed' : 'requires_review_before_release_pr',
    counts: Object.fromEntries(Object.entries(buckets).map(([bucket, bucketEntries]) => [bucket, bucketEntries.length])),
    by_category: byCategory,
    buckets,
    review_files: releaseStagingReviewFiles,
    close_condition: 'Use this plan as review input only; final release still requires a clean worktree, explicit PR selection, and passing release-readiness.',
    forbidden_actions: [
      'Do not stage this plan automatically.',
      'Do not include runtime, storage, reports, or local evidence files in the final release PR by default.',
      'Do not include frontend, revenue-AI, or other non-release-evidence changes without an explicit operator decision.',
    ],
    does_not_close_release_readiness: true,
  };
}

function ensureInside(parent, target) {
  const relative = path.relative(parent, target);
  if (relative.startsWith('..') || path.isAbsolute(relative)) {
    throw new Error(`Refusing to write outside output root: ${target}`);
  }
}

function statusEntries() {
  const result = runGit(['status', '--porcelain=v1', '-z'], { encoding: 'buffer' });
  return result.stdout.toString('utf8').split('\0').filter(Boolean).map((entry) => classifyReleaseWorktreeEntry({
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

function writeGitDiffSafe(relativePath, paths) {
  if (paths.length === 0) {
    return;
  }

  const target = path.join(outputRoot, relativePath);
  const partialTarget = `${target}.partial`;
  ensureInside(outputRoot, target);
  ensureInside(outputRoot, partialTarget);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.rmSync(partialTarget, { force: true });
  const result = runGit(['diff', '--binary', `--output=${partialTarget}`, 'HEAD', '--', ...paths], {
    allowFailure: true,
  });
  if (result.status !== 0) {
    fs.rmSync(partialTarget, { force: true });
    const detail = result.error ? String(result.error.message || result.error) : String(result.stderr || 'git diff failed');
    throw new Error(`Unable to create ${relativePath}: ${detail}`);
  }
  fs.renameSync(partialTarget, target);
}

function patchIntegrity(relativePath) {
  const target = path.join(outputRoot, relativePath);
  ensureInside(outputRoot, target);
  if (!fs.existsSync(target)) {
    return null;
  }
  const content = fs.readFileSync(target);
  return {
    path: relativePath,
    bytes: content.byteLength,
    sha256: createHash('sha256').update(content).digest('hex'),
  };
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

function renderReleaseStagingTsv(entries) {
  const rows = ['status\tpath\tcategory\tbucket'];
  for (const entry of entries) {
    rows.push([
      String(entry.status || '').trim(),
      entry.path,
      entry.category,
      entry.bucket,
    ].join('\t'));
  }
  rows.push('');
  return rows.join('\n');
}

function buildManifest(entries, divergence, copiedUntracked, skippedUntracked = []) {
  const tracked = trackedChanged(entries);
  const loose = untracked(entries);
  const releaseStagingPlan = buildReleaseStagingPlan(entries);
  return {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    repo_root: repoRoot,
    output_root: outputRoot,
    dry_run: dryRun,
    include_agents: includeAgents,
    include_local_artifacts: includeLocalArtifacts,
    base: {
      head: runGit(['rev-parse', 'HEAD']).stdout.trim(),
      branch: runGit(['status', '--short', '--branch']).stdout.split(/\r?\n/).find((line) => line.startsWith('##'))?.replace(/^##\s*/, '') || '',
    },
    divergence,
    changed_paths: entries,
    release_staging_plan: releaseStagingPlan,
    tracked_patch: tracked.length > 0 ? 'tracked.patch' : null,
    agent_patch: tracked.some((entry) => isAgentPath(entry.path)) ? 'agent.patch' : null,
    patch_integrity: {
      tracked: tracked.length > 0 ? patchIntegrity('tracked.patch') : null,
      agent: tracked.some((entry) => isAgentPath(entry.path)) ? patchIntegrity('agent.patch') : null,
    },
    untracked_files: loose.map((entry) => ({
      path: entry.path,
      copied: copiedUntracked.includes(entry.path),
      target: copiedUntracked.includes(entry.path) ? normalizePath(path.join('untracked', entry.path)) : null,
      skipped_reason: skippedUntracked.includes(entry.path)
        ? 'local/runtime artifact excluded by default; rerun with --includeLocalArtifacts=true only after explicit review'
        : null,
    })),
    safe_next_steps: [
      'Review manifest.json and tracked.patch before changing the source worktree.',
      'Use release_staging_plan and release-staging-*.tsv as review checklists only; do not stage candidate paths automatically.',
      'Do not run git reset, clean, pull, or merge until the risky local changes are either preserved or explicitly discarded.',
      'Do not copy reports, storage, runtime, output, test-results, or database/backups into a release bundle unless explicitly reviewed.',
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
  console.log(`Skipped local artifacts: ${manifest.untracked_files.filter((entry) => entry.skipped_reason).length}`);
  console.log(`Candidate release scope: ${manifest.release_staging_plan?.counts?.candidate_release_scope ?? 0}`);
  console.log(`Needs operator decision: ${manifest.release_staging_plan?.counts?.needs_explicit_operator_decision ?? 0}`);
  console.log(`Must remain local: ${manifest.release_staging_plan?.counts?.must_remain_local_by_default ?? 0}`);
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
  const skippedUntracked = includeLocalArtifacts
    ? []
    : loose.filter((entry) => isReleaseLocalArtifactPath(entry.path)).map((entry) => entry.path);

  const manifest = buildManifest(entries, divergence, copiedUntracked, skippedUntracked);

  if (!dryRun) {
    fs.mkdirSync(outputRoot, { recursive: true });
    if (trackedPaths.length > 0) {
      writeGitDiffSafe('tracked.patch', trackedPaths);
    }
    if (agentPaths.length > 0) {
      writeGitDiffSafe('agent.patch', agentPaths);
    }
    for (const entry of loose) {
      if (!includeLocalArtifacts && isReleaseLocalArtifactPath(entry.path)) {
        continue;
      }
      if (copyPathSafe(entry.path, 'untracked')) {
        copiedUntracked.push(entry.path);
      }
    }
    const finalManifest = buildManifest(entries, divergence, copiedUntracked, skippedUntracked);
    writeFileSafe('manifest.json', JSON.stringify(finalManifest, null, 2));
    for (const [bucket, fileName] of Object.entries(releaseStagingReviewFiles)) {
      writeFileSafe(fileName, renderReleaseStagingTsv(finalManifest.release_staging_plan.buckets[bucket] || []));
    }
    writeFileSafe('README.md', [
      '# Worktree Quarantine Bundle',
      '',
      'This bundle preserves local dirty-worktree evidence without modifying source files.',
      '',
      '- `manifest.json`: structured status and risk inventory.',
      '- `manifest.json.release_staging_plan`: release candidate, operator-decision, and local-only buckets for review only.',
      '- `release-staging-*.tsv`: review-only path lists generated from `manifest.json.release_staging_plan`.',
      '- `tracked.patch`: non-agent tracked changes.',
      '- `agent.patch`: `.agents` tracked changes, when present.',
      '- `untracked/`: copied untracked files, when present.',
      '',
      'Do not stage `release_staging_plan` or `release-staging-*.tsv` automatically.',
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
