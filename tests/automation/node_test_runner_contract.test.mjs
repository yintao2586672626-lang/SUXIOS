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
  writeFileSync(path.join(root, 'temporal-axis.spec.js'), '', 'utf8');
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
  const dependencyStep = workflow.indexOf('- name: Install Node dependencies');
  const playwrightStep = workflow.indexOf('- name: Install Playwright Chromium');
  const backendStep = workflow.indexOf('- name: Run backend tests');
  const nodeStep = workflow.indexOf('- name: Run Node automation tests');
  const guardStep = workflow.indexOf('- name: Run project guards');

  assert.equal(packageJson.scripts?.['test:node'], 'node scripts/run_node_automation_tests.mjs');
  assert.ok(
    dependencyStep >= 0 && dependencyStep < playwrightStep && playwrightStep < nodeStep,
    'CI must install the pinned Playwright Chromium before the Node suite',
  );
  assert.match(
    workflow.slice(playwrightStep, nodeStep),
    /run:\s+npx playwright install --with-deps chromium/,
  );
  assert.ok(backendStep >= 0 && backendStep < nodeStep, 'Node tests must run after backend tests');
  assert.ok(nodeStep < guardStep, 'Node tests must run before project guards');
  assert.match(
    workflow.slice(nodeStep, guardStep),
    /timeout-minutes:\s+10\s*\n\s+env:\s*\n\s+PHP_BINARY: php\s*\n\s+run: npm run test:node/,
  );
});

test('slow login handoff always closes its HTTP server when Chromium launch fails', () => {
  const source = readFileSync('tests/automation/login_handoff_slow_network.test.mjs', 'utf8');
  const browserDeclaration = source.indexOf('let browser = null;');
  const lifecycleTry = source.indexOf('try {', browserDeclaration);
  const browserLaunch = source.indexOf('browser = await chromium.launch', lifecycleTry);
  const lifecycleFinally = source.indexOf('} finally {', browserLaunch);
  const serverClose = source.indexOf('await close(server);', lifecycleFinally);

  assert.ok(browserDeclaration >= 0, 'browser lifecycle must tolerate launch failure');
  assert.ok(lifecycleTry < browserLaunch, 'Chromium launch must be inside the cleanup boundary');
  assert.ok(browserLaunch < lifecycleFinally, 'cleanup must follow the Chromium launch');
  assert.ok(lifecycleFinally < serverClose, 'HTTP server must close in the cleanup boundary');
});
