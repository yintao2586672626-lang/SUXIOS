import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import test from 'node:test';

const runnerPath = path.resolve('scripts/run_node_automation_tests.mjs');

test('Node automation runner discovers nested test files and forces serial execution', async () => {
  assert.equal(existsSync(runnerPath), true, 'Node automation runner must exist');
  const runner = await import(`${pathToFileURL(runnerPath).href}?test=${Date.now()}`);
  const root = mkdtempSync(path.join(tmpdir(), 'suxi-node-tests-'));
  const nested = path.join(root, 'nested');
  mkdirSync(nested);
  writeFileSync(path.join(root, 'z.test.mjs'), '', 'utf8');
  writeFileSync(path.join(root, 'ignore.mjs'), '', 'utf8');
  writeFileSync(path.join(nested, 'a.test.mjs'), '', 'utf8');

  try {
    const discovered = runner.discoverNodeTests(root)
      .map(file => path.relative(root, file).replaceAll('\\', '/'));
    assert.deepEqual(discovered, ['nested/a.test.mjs', 'z.test.mjs']);
    assert.deepEqual(
      runner.buildNodeTestArgs(['tests/automation/a.test.mjs']),
      ['--test', '--test-concurrency=1', 'tests/automation/a.test.mjs'],
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('package and CI run the complete Node automation suite between backend tests and project guards', () => {
  const packageJson = JSON.parse(readFileSync('package.json', 'utf8'));
  const workflow = readFileSync('.github/workflows/php.yml', 'utf8');
  const backendStep = workflow.indexOf('- name: Run backend tests');
  const nodeStep = workflow.indexOf('- name: Run Node automation tests');
  const guardStep = workflow.indexOf('- name: Run project guards');

  assert.equal(packageJson.scripts?.['test:node'], 'node scripts/run_node_automation_tests.mjs');
  assert.ok(backendStep >= 0 && backendStep < nodeStep, 'Node tests must run after backend tests');
  assert.ok(nodeStep < guardStep, 'Node tests must run before project guards');
  assert.match(
    workflow.slice(nodeStep, guardStep),
    /env:\s*\n\s+PHP_BINARY: php\s*\n\s+run: npm run test:node/,
  );
});
