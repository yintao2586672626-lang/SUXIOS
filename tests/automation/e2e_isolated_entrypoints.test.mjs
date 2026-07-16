import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const packageJson = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
const isolatedRunner = readFileSync(new URL('./run-quick-e2e-isolated.mjs', import.meta.url), 'utf8');
const helpers = readFileSync(new URL('./e2e-helpers.js', import.meta.url), 'utf8');
const fullClick = readFileSync(new URL('./full-click-coverage.spec.js', import.meta.url), 'utf8');
const boundedRunner = readFileSync(new URL('../../scripts/run_full_click_bounded.mjs', import.meta.url), 'utf8');
const codexRunner = readFileSync(new URL('../../scripts/codex_automation_runner.mjs', import.meta.url), 'utf8');

test('all package E2E write-capable entrypoints route through the dedicated isolated runner', () => {
  for (const name of [
    'test:e2e:daily',
    'test:e2e:async',
    'test:e2e:edge',
    'test:e2e:ui',
    'test:e2e:module',
    'test:e2e:full',
    'test:e2e:business',
    'test:e2e:temporal',
    'test:e2e:quick',
    'codex:runner',
    'codex:runner:quick',
  ]) {
    assert.match(
      String(packageJson.scripts?.[name] || ''),
      /run-quick-e2e-isolated\.mjs/,
      `${name} must use the isolated E2E runner`,
    );
  }
  assert.match(String(packageJson.scripts?.['test:e2e:full:bounded'] || ''), /run_full_click_bounded\.mjs/);
  assert.match(boundedRunner, /run-quick-e2e-isolated\.mjs/);
  assert.match(boundedRunner, /--full-click-bounded/);
});

test('isolated runner always selects a dedicated database and self-hosted loopback server', () => {
  assert.match(isolatedRunner, /const dedicatedDatabaseName = configuredDedicatedDatabase !== ''/);
  assert.match(isolatedRunner, /: 'hotelx_e2e';/);
  assert.match(isolatedRunner, /requires a dedicated \*_test\/\*_testing\/\*_e2e database name/);
  assert.match(isolatedRunner, /const selfHosted = true;/);
  assert.match(isolatedRunner, /SUXI_E2E_DB_OVERRIDE: '1'/);
  assert.match(isolatedRunner, /SUXI_E2E_ISOLATED_RUNNER: '1'/);
  assert.doesNotMatch(isolatedRunner, /SUXI_E2E_ALLOW_SHARED_DB/);
});

test('direct Playwright and Codex runners fail closed without isolation proof', () => {
  assert.match(helpers, /Playwright E2E requires the isolated runner/);
  assert.match(helpers, /effectivePort === 8080/);
  assert.match(fullClick, /const mutateForms = process\.env\.E2E_MUTATE === '1';/);
  assert.match(codexRunner, /assertIsolatedAutomationEnvironment\(options\)/);
  assert.doesNotMatch(codexRunner, /admin123/);

  const env = { ...process.env };
  for (const key of [
    'SUXI_E2E_ISOLATED_RUNNER',
    'SUXI_E2E_DB_OVERRIDE',
    'SUXI_E2E_DB_NAME',
    'E2E_BASE_URL',
    'E2E_USERNAME',
    'E2E_PASSWORD',
    'E2E_OBJECT_PREFIX',
  ]) {
    delete env[key];
  }
  const result = spawnSync(process.execPath, [
    'scripts/codex_automation_runner.mjs',
    '--profile=quick',
    '--iterations=1',
  ], {
    cwd: new URL('../..', import.meta.url),
    env,
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.notEqual(result.status, 0);
  assert.match(`${result.stdout}\n${result.stderr}`, /requires the isolated runner/i);
});
