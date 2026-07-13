import { readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const scriptPath = fileURLToPath(import.meta.url);
const projectRoot = path.resolve(path.dirname(scriptPath), '..');

export function discoverNodeTests(root) {
  const tests = [];
  const visit = (directory) => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const entryPath = path.join(directory, entry.name);
      if (entry.isDirectory()) {
        visit(entryPath);
      } else if (entry.isFile() && entry.name.endsWith('.test.mjs')) {
        tests.push(entryPath);
      }
    }
  };

  visit(root);
  return tests.sort();
}

export function buildNodeTestArgs(testFiles) {
  return ['--test', '--test-concurrency=1', ...testFiles];
}

function run() {
  const automationRoot = path.join(projectRoot, 'tests', 'automation');
  const testFiles = discoverNodeTests(automationRoot)
    .map(file => path.relative(projectRoot, file).split(path.sep).join('/'));
  if (testFiles.length === 0) {
    console.error('No tests/automation/*.test.mjs files found.');
    process.exit(2);
  }

  console.log(`Running ${testFiles.length} Node automation test files serially.`);
  const result = spawnSync(process.execPath, buildNodeTestArgs(testFiles), {
    cwd: projectRoot,
    stdio: 'inherit',
    env: process.env,
  });
  if (result.error) {
    console.error(result.error.message);
    process.exit(1);
  }
  process.exit(result.status ?? 1);
}

if (process.argv[1] && path.resolve(process.argv[1]) === scriptPath) {
  run();
}
