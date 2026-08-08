import assert from 'node:assert/strict';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import test from 'node:test';
import { extractGithubActionsJob } from './helpers/github_actions_workflow.mjs';

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
    assert.deepEqual(
      runner.buildPhpBinaryCandidates({ PHP_BINARY: 'D:\\php\\php.exe' }, 'win32'),
      ['D:\\php\\php.exe'],
    );
    assert.deepEqual(
      runner.buildPhpBinaryCandidates({ SUXI_PHP: 'D:\\portable-php\\php.exe' }, 'win32'),
      ['D:\\portable-php\\php.exe'],
    );
    assert.deepEqual(
      runner.buildPhpBinaryCandidates({}, 'win32'),
      ['php', 'C:\\xampp\\php\\php.exe'],
    );
    assert.deepEqual(runner.buildPhpBinaryCandidates({}, 'linux'), ['php']);
    assert.equal(runner.isRuntimeSkipAllowed([]), false);
    assert.equal(runner.isRuntimeSkipAllowed(['--allow-runtime-skip']), true);
    assert.deepEqual(
      runner.buildNodeTestEnv({ EXISTING_VALUE: 'kept' }, 'C:\\xampp\\php\\php.exe'),
      {
        EXISTING_VALUE: 'kept',
        PHP_BINARY: 'C:\\xampp\\php\\php.exe',
        SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME: '1',
      },
    );
    assert.deepEqual(
      runner.buildNodeTestEnv(
        { EXISTING_VALUE: 'kept' },
        '',
        { allowRuntimeSkip: true },
      ),
      { EXISTING_VALUE: 'kept' },
    );
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('package and the isolated CI lane run the complete strict Node automation suite', () => {
  const packageJson = JSON.parse(readFileSync('package.json', 'utf8'));
  const workflow = readFileSync('.github/workflows/php.yml', 'utf8');
  const nodeJob = extractGithubActionsJob(workflow, 'node_business_chain');
  const contractsJob = extractGithubActionsJob(workflow, 'contracts');
  const aggregateJob = extractGithubActionsJob(workflow, 'verify');
  const dependencyStep = nodeJob.indexOf('- name: Install Node dependencies');
  const playwrightStep = nodeJob.indexOf('- name: Install Playwright Chromium');
  const databaseStep = nodeJob.indexOf('- name: Initialize Node test database');
  const nodeStep = nodeJob.indexOf('- name: Run Node automation tests');
  const transitionStep = nodeJob.indexOf('- name: Verify full-render page transitions');

  assert.equal(packageJson.scripts?.['test:node'], 'node scripts/run_node_automation_tests.mjs');
  assert.equal(
    packageJson.scripts?.['test:node:partial'],
    'node scripts/run_node_automation_tests.mjs --allow-runtime-skip',
  );
  assert.equal(
    packageJson.scripts?.['test:e2e:transition'],
    'node tests/automation/run-quick-e2e-isolated.mjs --transition-only',
  );
  assert.ok(
    dependencyStep >= 0
      && dependencyStep < playwrightStep
      && playwrightStep < databaseStep
      && databaseStep < nodeStep,
    'the Node lane must install dependencies and initialize its isolated database before testing',
  );
  assert.match(
    nodeJob.slice(playwrightStep, nodeStep),
    /run:\s+npx playwright install --with-deps chromium/,
  );
  const nodeStepSource = nodeJob.slice(nodeStep);
  assert.match(nodeStepSource, /timeout-minutes:\s+10/);
  assert.match(nodeStepSource, /PHP_BINARY:\s+php/);
  assert.match(nodeStepSource, /SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME:\s+'1'/);
  assert.match(nodeStepSource, /SUXI_E2E_DB_OVERRIDE:\s+'1'/);
  assert.match(nodeStepSource, /SUXI_E2E_DB_NAME:\s+hotelx_ci_test/);
  assert.match(nodeStepSource, /DB_NAME:\s+hotelx_ci_test/);
  assert.match(nodeStepSource, /run:\s+npm run test:node/);
  assert.ok(transitionStep > nodeStep, 'the real full-render transition check must run after Node contracts');
  const transitionStepSource = nodeJob.slice(transitionStep);
  assert.match(transitionStepSource, /timeout-minutes:\s+4/);
  assert.match(transitionStepSource, /SUXI_E2E_DB_NAME:\s+hotelx_ci_test/);
  assert.match(transitionStepSource, /run:\s+npm run test:e2e:transition/);
  assert.doesNotMatch(nodeJob, /test:node:partial|allow-runtime-skip/);
  assert.doesNotMatch(nodeJob, /Run project guards/);
  assert.match(contractsJob, /Run project guards[\s\S]*npm run verify:p0-guards/);
  assert.match(aggregateJob, /needs:[\s\S]*-\s+node_business_chain/);
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
