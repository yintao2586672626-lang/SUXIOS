#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs } from './lib/shared_helpers.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = parseArgs(process.argv.slice(2));

const outputJson = args.json === 'true';
const includeDependencies = args.includeDependencies === 'true';
const includeSensitiveBackups = args.includeSensitiveBackups === 'true';
const failOnArtifacts = args.failOnArtifacts === 'true';
const requireCleanGit = args.requireCleanGit === 'true';
const maxReclaimMb = numberArg(args.maxReclaimMb, 0);
const topLimit = Math.max(1, numberArg(args.top, 12));

const codeExtensions = new Set(['.php', '.html', '.js', '.mjs', '.ts', '.tsx', '.vue', '.css', '.scss', '.py', '.ps1', '.sh']);
const textExtensions = new Set([...codeExtensions, '.md', '.json', '.xml', '.yml', '.yaml', '.sql', '.txt', '.env', '.example']);

const trackedFiles = listTrackedFiles();
const trackedStats = measureTrackedFiles(trackedFiles);
const lineStats = measureLineStats(trackedFiles);
const topLevelSizes = measureTopLevel();
const cleanup = measureCleanupTargets();
const git = gitState();
const status = resolveStatus({ cleanup, git });

const audit = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  repo_root: repoRoot,
  status,
  git,
  size: {
    full_mb: roundMb(topLevelSizes.totalBytes),
    without_git_mb: roundMb(topLevelSizes.totalBytes - (topLevelSizes.byName.get('.git')?.bytes ?? 0)),
    without_git_and_dependencies_mb: roundMb(
      topLevelSizes.totalBytes
        - (topLevelSizes.byName.get('.git')?.bytes ?? 0)
        - (topLevelSizes.byName.get('node_modules')?.bytes ?? 0)
        - (topLevelSizes.byName.get('vendor')?.bytes ?? 0),
    ),
    tracked_files: trackedStats.files,
    tracked_mb: roundMb(trackedStats.bytes),
    file_count: topLevelSizes.fileCount,
  },
  cleanup,
  code_lines: lineStats.code,
  text_lines: lineStats.text,
  top_level: topLevelSizes.rows.slice(0, topLimit),
  top_tracked_files: trackedStats.topFiles.slice(0, topLimit),
};

if (outputJson) {
  console.log(JSON.stringify(audit, null, 2));
} else {
  renderText(audit);
}

if (status.failures.length > 0) {
  process.exit(1);
}

function numberArg(value, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function runGit(gitArgs, options = {}) {
  const result = spawnSync('git', gitArgs, {
    cwd: repoRoot,
    encoding: options.encoding ?? 'utf8',
    shell: false,
  });
  if (result.status !== 0) {
    const detail = result.error ? String(result.error.message || result.error) : String(result.stderr || 'git failed');
    throw new Error(`git ${gitArgs.join(' ')} failed: ${detail}`);
  }
  return result.stdout;
}

function listTrackedFiles() {
  const stdout = runGit(['-c', 'core.quotePath=false', 'ls-files', '-z'], { encoding: 'buffer' });
  return stdout.toString('utf8').split('\0').filter(Boolean);
}

function gitState() {
  const statusShort = runGit(['-c', 'core.quotePath=false', 'status', '--short', '--branch']).trimEnd();
  const changed = statusShort.split(/\r?\n/).filter((line) => line && !line.startsWith('##'));
  const branchLine = statusShort.split(/\r?\n/).find((line) => line.startsWith('##')) || '';
  const indexLockPath = path.join(repoRoot, '.git', 'index.lock');
  return {
    branch: branchLine.replace(/^##\s*/, ''),
    clean: changed.length === 0,
    changed_paths: changed.length,
    index_lock: fs.existsSync(indexLockPath),
    status_short: statusShort,
  };
}

function measureTrackedFiles(files) {
  let bytes = 0;
  let count = 0;
  const rows = [];
  for (const relativePath of files) {
    const absolutePath = path.join(repoRoot, relativePath);
    const stat = safeStat(absolutePath);
    if (!stat?.isFile()) {
      continue;
    }
    bytes += stat.size;
    count += 1;
    rows.push({
      path: normalizePath(relativePath),
      mb: roundMb(stat.size),
      bytes: stat.size,
    });
  }
  rows.sort((a, b) => b.bytes - a.bytes);
  return { files: count, bytes, topFiles: rows.map(({ bytes: _bytes, ...row }) => row) };
}

function measureLineStats(files) {
  const code = emptyLineSummary();
  const text = emptyLineSummary();
  for (const relativePath of files) {
    const extension = path.extname(relativePath).toLowerCase();
    const absolutePath = path.join(repoRoot, relativePath);
    if (!safeStat(absolutePath)?.isFile()) {
      continue;
    }
    if (!codeExtensions.has(extension) && !textExtensions.has(extension)) {
      continue;
    }
    const content = safeReadText(absolutePath);
    if (content === null) {
      continue;
    }
    const lines = countLines(content);
    const nonblank = countNonblankLines(content);
    if (textExtensions.has(extension)) {
      addLineSummary(text, extension, lines, nonblank);
    }
    if (codeExtensions.has(extension)) {
      addLineSummary(code, extension, lines, nonblank);
    }
  }
  finalizeLineSummary(code);
  finalizeLineSummary(text);
  return { code, text };
}

function emptyLineSummary() {
  return {
    files: 0,
    lines: 0,
    nonblank: 0,
    by_extension: {},
  };
}

function addLineSummary(summary, extension, lines, nonblank) {
  const key = extension || '[no_ext]';
  if (!summary.by_extension[key]) {
    summary.by_extension[key] = { files: 0, lines: 0, nonblank: 0 };
  }
  summary.by_extension[key].files += 1;
  summary.by_extension[key].lines += lines;
  summary.by_extension[key].nonblank += nonblank;
  summary.files += 1;
  summary.lines += lines;
  summary.nonblank += nonblank;
}

function finalizeLineSummary(summary) {
  summary.by_extension = Object.fromEntries(
    Object.entries(summary.by_extension).sort(([, a], [, b]) => b.lines - a.lines),
  );
}

function countLines(content) {
  if (!content.length) {
    return 0;
  }
  const normalized = content.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  return normalized.endsWith('\n') ? normalized.slice(0, -1).split('\n').length : normalized.split('\n').length;
}

function countNonblankLines(content) {
  if (!content.length) {
    return 0;
  }
  return content.split(/\r\n|\n|\r/).filter((line) => line.trim().length > 0).length;
}

function safeReadText(absolutePath) {
  try {
    const buffer = fs.readFileSync(absolutePath);
    if (buffer.includes(0)) {
      return null;
    }
    return buffer.toString('utf8');
  } catch {
    return null;
  }
}

function measureTopLevel() {
  const rows = [];
  const byName = new Map();
  let totalBytes = 0;
  let fileCount = 0;
  for (const entry of fs.readdirSync(repoRoot, { withFileTypes: true })) {
    const absolutePath = path.join(repoRoot, entry.name);
    const measured = measurePath(absolutePath);
    const row = {
      name: entry.name,
      type: entry.isDirectory() ? 'dir' : 'file',
      files: measured.files,
      mb: roundMb(measured.bytes),
      bytes: measured.bytes,
    };
    rows.push(row);
    byName.set(entry.name, row);
    totalBytes += measured.bytes;
    fileCount += measured.files;
  }
  rows.sort((a, b) => b.bytes - a.bytes);
  return {
    totalBytes,
    fileCount,
    byName,
    rows: rows.map(({ bytes: _bytes, ...row }) => row),
  };
}

function measureCleanupTargets() {
  const candidates = [
    'output',
    'runtime',
    'test-results',
    '.pytest_cache',
    '.gstack',
  ];
  const storagePath = path.join(repoRoot, 'storage');
  if (safeStat(storagePath)?.isDirectory()) {
    for (const entry of fs.readdirSync(storagePath, { withFileTypes: true })) {
      if (entry.isDirectory() && /^(ctrip_profile_|meituan_profile_)/.test(entry.name)) {
        candidates.push(path.join('storage', entry.name));
      }
      if (entry.isFile() && entry.name.endsWith('.log')) {
        candidates.push(path.join('storage', entry.name));
      }
    }
  }
  if (includeDependencies) {
    candidates.push('node_modules', 'vendor');
  }
  if (includeSensitiveBackups) {
    candidates.push(path.join('database', 'backups'));
  }

  const rows = [];
  let bytes = 0;
  for (const candidate of candidates) {
    const absolutePath = path.resolve(repoRoot, candidate);
    if (!isInsideRepo(absolutePath) || !fs.existsSync(absolutePath)) {
      continue;
    }
    const measured = measurePath(absolutePath);
    bytes += measured.bytes;
    rows.push({
      path: normalizePath(path.relative(repoRoot, absolutePath)),
      files: measured.files,
      mb: roundMb(measured.bytes),
      bytes: measured.bytes,
    });
  }
  rows.sort((a, b) => b.bytes - a.bytes);
  return {
    target_count: rows.length,
    estimated_reclaim_mb: roundMb(bytes),
    includes_dependencies: includeDependencies,
    includes_sensitive_backups: includeSensitiveBackups,
    targets: rows.map(({ bytes: _bytes, ...row }) => row),
  };
}

function measurePath(absolutePath) {
  let bytes = 0;
  let files = 0;
  const stack = [absolutePath];
  while (stack.length > 0) {
    const current = stack.pop();
    const stat = safeLstat(current);
    if (!stat) {
      continue;
    }
    if (stat.isSymbolicLink()) {
      bytes += stat.size;
      files += 1;
      continue;
    }
    if (stat.isDirectory()) {
      let entries = [];
      try {
        entries = fs.readdirSync(current, { withFileTypes: true });
      } catch {
        continue;
      }
      for (const entry of entries) {
        stack.push(path.join(current, entry.name));
      }
      continue;
    }
    if (stat.isFile()) {
      bytes += stat.size;
      files += 1;
    }
  }
  return { bytes, files };
}

function resolveStatus({ cleanup, git }) {
  const failures = [];
  const warnings = [];
  if (git.index_lock) {
    failures.push('.git/index.lock is present.');
  }
  if (requireCleanGit && !git.clean) {
    failures.push(`Git worktree is not clean: ${git.changed_paths} changed path(s).`);
  } else if (!git.clean) {
    warnings.push(`Git worktree has ${git.changed_paths} changed path(s).`);
  }
  if (failOnArtifacts && cleanup.estimated_reclaim_mb > maxReclaimMb) {
    failures.push(`Cleanup reclaim ${cleanup.estimated_reclaim_mb} MB exceeds allowed ${maxReclaimMb} MB.`);
  } else if (cleanup.estimated_reclaim_mb > 0) {
    warnings.push(`Default cleanup can reclaim ${cleanup.estimated_reclaim_mb} MB.`);
  }
  return {
    ok: failures.length === 0,
    warnings,
    failures,
  };
}

function safeStat(absolutePath) {
  try {
    return fs.statSync(absolutePath);
  } catch {
    return null;
  }
}

function safeLstat(absolutePath) {
  try {
    return fs.lstatSync(absolutePath);
  } catch {
    return null;
  }
}

function isInsideRepo(absolutePath) {
  const relative = path.relative(repoRoot, absolutePath);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

function normalizePath(value) {
  return String(value).replace(/\\/g, '/');
}

function roundMb(bytes) {
  return Math.round((bytes / 1024 / 1024) * 100) / 100;
}

function renderText(audit) {
  console.log('Project self-audit');
  console.log(`Repo: ${audit.repo_root}`);
  console.log(`Generated: ${audit.generated_at}`);
  console.log(`Status: ${audit.status.ok ? 'ok' : 'failed'}`);
  console.log('');

  console.log('Git');
  console.log(`- branch: ${audit.git.branch || '(unknown)'}`);
  console.log(`- clean: ${audit.git.clean ? 'yes' : 'no'}`);
  console.log(`- changed paths: ${audit.git.changed_paths}`);
  console.log(`- index lock: ${audit.git.index_lock ? 'present' : 'absent'}`);
  console.log('');

  console.log('Size');
  console.log(`- full: ${audit.size.full_mb} MB`);
  console.log(`- without .git: ${audit.size.without_git_mb} MB`);
  console.log(`- without .git and dependencies: ${audit.size.without_git_and_dependencies_mb} MB`);
  console.log(`- tracked: ${audit.size.tracked_mb} MB / ${audit.size.tracked_files} files`);
  console.log('');

  console.log('Cleanup candidates');
  console.log(`- targets: ${audit.cleanup.target_count}`);
  console.log(`- estimated reclaim: ${audit.cleanup.estimated_reclaim_mb} MB`);
  renderRows(audit.cleanup.targets, ['path', 'files', 'mb']);
  console.log('');

  console.log('Code lines');
  console.log(`- files: ${audit.code_lines.files}`);
  console.log(`- lines: ${audit.code_lines.lines}`);
  console.log(`- nonblank: ${audit.code_lines.nonblank}`);
  renderExtensionRows(audit.code_lines.by_extension);
  console.log('');

  console.log('Top-level size');
  renderRows(audit.top_level, ['name', 'type', 'files', 'mb']);
  console.log('');

  console.log('Top tracked files');
  renderRows(audit.top_tracked_files, ['path', 'mb']);
  if (audit.status.warnings.length > 0) {
    console.log('');
    console.log('Warnings');
    for (const warning of audit.status.warnings) {
      console.log(`- ${warning}`);
    }
  }
  if (audit.status.failures.length > 0) {
    console.log('');
    console.log('Failures');
    for (const failure of audit.status.failures) {
      console.log(`- ${failure}`);
    }
  }
}

function renderExtensionRows(byExtension) {
  const rows = Object.entries(byExtension).map(([ext, value]) => ({
    ext,
    files: value.files,
    lines: value.lines,
    nonblank: value.nonblank,
  }));
  renderRows(rows, ['ext', 'files', 'lines', 'nonblank']);
}

function renderRows(rows, columns) {
  if (!rows.length) {
    console.log('(none)');
    return;
  }
  const widths = {};
  for (const column of columns) {
    widths[column] = Math.max(
      column.length,
      ...rows.map((row) => String(row[column] ?? '').length),
    );
  }
  console.log(columns.map((column) => column.padEnd(widths[column])).join('  '));
  console.log(columns.map((column) => '-'.repeat(widths[column])).join('  '));
  for (const row of rows) {
    console.log(columns.map((column) => String(row[column] ?? '').padEnd(widths[column])).join('  '));
  }
}
