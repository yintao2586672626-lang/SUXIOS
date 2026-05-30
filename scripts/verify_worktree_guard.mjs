import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const git = spawnSync('git', ['status', '--porcelain=v1'], {
  encoding: 'utf8',
  cwd: repoRoot,
  shell: false,
});

if (git.status !== 0) {
  const detail = git.error ? String(git.error.message || git.error) : (git.stderr || 'unknown error');
  console.error(`git status failed: ${detail}`);
  process.exit(git.status ?? 1);
}

const lines = git.stdout.split(/\r?\n/).filter(Boolean);
const failures = [];
const summary = new Map();

function addSummary(bucket) {
  summary.set(bucket, (summary.get(bucket) ?? 0) + 1);
}

for (const line of lines) {
  const status = line.slice(0, 2);
  const rawPath = line.slice(3).replace(/^"|"$/g, '');
  const path = rawPath.replace(/\\/g, '/');

  if (status.includes('U') || ['AA', 'DD'].includes(status)) {
    failures.push(`Unmerged path must be resolved before continuing: ${path}`);
  }

  if (path === '.env' || path.endsWith('/.env')) {
    failures.push(`Environment file must not be staged or committed: ${path}`);
  }

  if (path === 'public/index.html' && status.includes('D')) {
    failures.push('public/index.html is deleted.');
  }

  if (path.startsWith('HOTEL/')) {
    addSummary('nested HOTEL cleanup');
  } else if (path.startsWith('public/assets/') || /^public\/app(?:-main|-styles)?\./.test(path)) {
    addSummary('old frontend build artifact cleanup');
  } else if (path.startsWith('tests/') || path.startsWith('scripts/')) {
    addSummary('test or verification change');
  } else if (path.startsWith('docs/') || path.endsWith('.md')) {
    addSummary('documentation change');
  } else {
    addSummary('application source change');
  }
}

const publicIndexPath = path.join(repoRoot, 'public/index.html');
if (fs.existsSync(publicIndexPath)) {
  const size = fs.statSync(publicIndexPath).size;
  if (size < 500_000) {
    failures.push(`public/index.html is only ${size} bytes; expected the single-file SPA, not a generated stub.`);
  }
} else {
  failures.push('public/index.html is missing.');
}

for (const [bucket, count] of summary.entries()) {
  console.log(`${bucket}: ${count}`);
}
console.log(`worktree paths changed: ${lines.length}`);

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Worktree guard passed.');
