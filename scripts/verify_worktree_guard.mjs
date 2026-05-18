import { spawnSync } from 'node:child_process';
import fs from 'node:fs';

const git = spawnSync('git', ['status', '--porcelain=v1'], {
  encoding: 'utf8',
  shell: false,
});

if (git.status !== 0) {
  console.error(git.stderr || 'git status failed.');
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

if (fs.existsSync('public/index.html')) {
  const size = fs.statSync('public/index.html').size;
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
