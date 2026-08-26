import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import test from 'node:test';

const runnerPath = path.resolve('scripts/verify_integration.mjs');
const read = (relative) => readFileSync(relative, 'utf8');

test('one canonical integration gate is shared by package, project rules, staged hook and CI', async () => {
  const runner = await import(`${pathToFileURL(runnerPath).href}?contract=${Date.now()}`);
  const packageJson = JSON.parse(read('package.json'));
  const rules = read('AGENTS.md');
  const hook = read('hooks/verify-staged-frontend-build.mjs');
  const workflow = read('.github/workflows/php.yml');

  assert.equal(packageJson.scripts['verify:integration'], 'node scripts/verify_integration.mjs');
  assert.equal(packageJson.scripts['verify:integration:staged'], 'node scripts/verify_integration.mjs --staged');
  assert.match(rules, /npm\.cmd run verify:integration/);
  assert.match(hook, /runNpmVerifier\('verify:integration:staged'\)/);
  assert.match(hook, /const needsSnapshot = Boolean\(contextVerifier\) \|\| changed\.length > 0;/);
  assert.match(workflow, /Run canonical integration gate[\s\S]*npm run verify:integration/);
  assert.doesNotMatch(workflow, /run:\s+npm run verify:source-hotspot-budget/);
  assert.doesNotMatch(workflow, /run:\s+npm run verify:p0-guards/);
  assert.match(workflow, /windows_control_plane:[\s\S]*runs-on: windows-latest/);
  assert.match(workflow, /needs:[\s\S]*- windows_control_plane/);
  assert.deepEqual(
    runner.integrationChecks().map((check) => check.id),
    ['verifier_domain_registry', 'source_hotspot_budget', 'p0_guards', 'working_tree_diff_check'],
  );
  assert.deepEqual(
    runner.integrationChecks({ staged: true }).map((check) => check.id),
    ['verifier_domain_registry', 'source_hotspot_budget'],
  );
});

test('canonical integration gate stops at the first failed check with its stable id', async () => {
  const runner = await import(`${pathToFileURL(runnerPath).href}?failure=${Date.now()}`);
  const invoked = [];
  const result = runner.runIntegrationGate({
    spawn: (command, args) => {
      invoked.push([command, args]);
      return invoked.length < 3
        ? { status: 0, error: null }
        : { status: 7, error: null };
    },
    log: () => {},
    logError: () => {},
    platform: 'linux',
    env: {},
  });
  assert.equal(result.status, 7);
  assert.equal(result.failedCheck, 'p0_guards');
  assert.equal(invoked.length, 3);
});
