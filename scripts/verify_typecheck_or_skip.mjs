import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const args = process.argv.slice(2);
const buildMode = args.includes('--build');
const watchMode = args.includes('--watch');
const tsconfig = buildMode ? 'tsconfig.build.json' : 'tsconfig.json';
const tscBin = path.join(root, 'node_modules', 'typescript', 'bin', 'tsc');
const ignoredDirs = new Set(['node_modules', 'vendor', 'runtime', 'output', 'test-results']);

function walk(dir, files = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!ignoredDirs.has(entry.name)) {
        walk(path.join(dir, entry.name), files);
      }
      continue;
    }
    if (entry.isFile() && /\.d?ts$/.test(entry.name)) {
      files.push(path.join(dir, entry.name));
    }
  }
  return files;
}

const tsFiles = walk(root);
if (tsFiles.length === 0) {
  console.log(`No TypeScript inputs found; skipped ${tsconfig}.`);
  process.exit(0);
}

if (!fs.existsSync(tscBin)) {
  console.error(`TypeScript compiler not found: ${tscBin}`);
  process.exit(1);
}

const tscArgs = buildMode ? ['-p', tsconfig] : ['--noEmit', '-p', tsconfig];
if (watchMode && !buildMode) {
  tscArgs.push('--watch');
}
const result = spawnSync(process.execPath, [tscBin, ...tscArgs], {
  cwd: root,
  stdio: 'inherit',
  shell: false,
});

process.exit(result.status ?? 1);
