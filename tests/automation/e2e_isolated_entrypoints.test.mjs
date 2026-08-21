import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const require = createRequire(import.meta.url);
const {
  MODULE,
  classifyRequestFailureText,
  moduleNavLabel,
  modulePath,
} = require('./e2e-helpers.js');

const packageJson = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'));
const isolatedRunner = readFileSync(new URL('./run-quick-e2e-isolated.mjs', import.meta.url), 'utf8');
const helpers = readFileSync(new URL('./e2e-helpers.js', import.meta.url), 'utf8');
const businessChains = readFileSync(new URL('./business-chains.spec.js', import.meta.url), 'utf8');
const fullClick = readFileSync(new URL('./full-click-coverage.spec.js', import.meta.url), 'utf8');
const publicPageTaskBridge = readFileSync(new URL('./public-page-task-bridge.spec.js', import.meta.url), 'utf8');
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
    'test:e2e:public-page',
    'test:e2e:transition',
    'test:e2e:stability',
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

test('every Playwright spec is classified by the isolated runner', () => {
  for (const spec of [
    'async-page-guard.spec.js',
    'business-chains.spec.js',
    'daily-regression.spec.js',
    'edge-input-guard.spec.js',
    'frontend_full_render_transition.spec.js',
    'full-click-coverage.spec.js',
    'module-smoke.spec.js',
    'ota-auth-strong-reminder.spec.js',
    'public-page-task-bridge.spec.js',
    'security_monitoring_page.spec.js',
    'temporal-axis.spec.js',
  ]) {
    assert.match(isolatedRunner, new RegExp(spec.replaceAll('.', '\\.')));
  }
});

test('E2E navigation follows the current visible boss menu labels', () => {
  assert.equal(modulePath(MODULE.AI_WORKBENCH), 'compass');
  assert.equal(moduleNavLabel(MODULE.DATA_TRUST), '昨日经营闭环');
  assert.equal(moduleNavLabel(MODULE.AI_DAILY_REPORT), 'AI经营日报');
  assert.equal(moduleNavLabel(MODULE.EXECUTION_TRACKING), '任务执行与复盘');
  assert.match(helpers, /for \(let attempt = 0; attempt < 3 && !clicked; attempt \+= 1\)/);
  assert.doesNotMatch(helpers, /if \(await firstVisibleLocator\(targetLocators\)\) return nav;/);
  assert.match(helpers, /while \(Date\.now\(\) < deadline\)/);
  assert.match(helpers, /nav = await navRoot\(page\);/);
  assert.match(helpers, /A deferred full-render can replace the entire Vue tree between modules/);
});

test('diagnostics separate intentional browser cancellation from API failure', () => {
  assert.equal(classifyRequestFailureText('net::ERR_ABORTED'), 'api-error');
  assert.equal(classifyRequestFailureText('net::ERR_ABORTED', true), 'api-cancelled');
  assert.equal(classifyRequestFailureText('net::ERR_CONNECTION_RESET'), 'api-error');
  assert.equal(classifyRequestFailureText(null), 'api-error');
  assert.match(helpers, /activeReads: new Set\(\), expectedNavigationCancellations: new WeakSet\(\)/);
  assert.match(helpers, /navigationCancellationActive = true/);
  assert.match(helpers, /navigationCancellationExpiresAt = Date\.now\(\) \+ 1000/);
  assert.match(helpers, /if \(lifecycle\.navigationCancellationActive \|\| Date\.now\(\) <= lifecycle\.navigationCancellationExpiresAt\) \{\s*lifecycle\.expectedNavigationCancellations\.add\(request\)/);
  assert.match(helpers, /finishExpectedApiReadCancellationsForNavigation\(page\)/);
  assert.match(helpers, /expectedNavigationCancellations\.add\(request\)/);
  assert.match(helpers, /expectedNavigationCancellations\.has\(request\)/);
  assert.match(helpers, /expectActiveApiReadCancellationsForNavigation\(page\);\s*await navItem\.click/);
});

test('OTA browser readback keeps system-hotel authorization separate from platform identity', () => {
  assert.doesNotMatch(businessChains, /params:\s*\{\s*hotel_id:\s*otaHotelId/);
  assert.match(
    businessChains,
    /system_hotel_id:\s*hotelContext\.hotelId,\s*ota_hotel_id:\s*otaHotelId/,
  );
});

test('business E2E preserves the formal P0 gate and labels its fallback report as non-formal', () => {
  assert.match(businessChains, /expectedStatus:\s*409/);
  assert.match(businessChains, /formal_report_generated\)\.toBe\(false\)/);
  assert.match(businessChains, /seedSyntheticAiReportFixture/);
  assert.match(businessChains, /generation_mode\)\.toBe\('synthetic_e2e'\)/);
  assert.match(businessChains, /input_trust\?\.readback_verified\)\.toBe\(false\)/);
  assert.match(businessChains, /expectedStatus:\s*422/);
  assert.match(businessChains, /Trusted OTA readback verification is required/);
  assert.match(businessChains, /source-verified business metric readback is required/);
  assert.match(businessChains, /result_status:\s*'observing'/);
  assert.match(businessChains, /roi\.incremental_revenue\)\.toBeNull\(\)/);
  assert.doesNotMatch(businessChains, /authority_verifier|external_p0_verifier/);
});

test('public-page task bridge has a dedicated authenticated browser entrypoint', () => {
  assert.match(isolatedRunner, /--public-page-only/);
  assert.match(isolatedRunner, /public-page-task-bridge\.spec\.js/);
  assert.match(publicPageTaskBridge, /saved_readback_verified/);
  assert.match(publicPageTaskBridge, /调整排期并打开/);
  assert.match(publicPageTaskBridge, /重新创建待审批任务/);
  assert.match(publicPageTaskBridge, /intent_id/);
});

test('isolated runner always selects a dedicated database and self-hosted loopback server', () => {
  assert.match(isolatedRunner, /const dedicatedDatabaseName = configuredDedicatedDatabase !== ''/);
  assert.match(isolatedRunner, /performanceOnly \? 'hotelx_performance_e2e' : 'hotelx_e2e';/);
  assert.match(isolatedRunner, /requires a dedicated \*_test\/\*_testing\/\*_e2e database name/);
  assert.match(isolatedRunner, /const selfHosted = true;/);
  assert.match(isolatedRunner, /SUXI_E2E_DB_OVERRIDE: '1'/);
  assert.match(isolatedRunner, /SUXI_E2E_ISOLATED_RUNNER: '1'/);
  assert.doesNotMatch(isolatedRunner, /SUXI_E2E_ALLOW_SHARED_DB/);
});

test('authenticated performance measurement uses the isolated runner and fails closed', () => {
  assert.match(
    String(packageJson.scripts?.['measure:performance:isolated'] || ''),
    /run-quick-e2e-isolated\.mjs --performance-only/,
  );
  assert.match(isolatedRunner, /scripts\/measure_frontend_performance\.mjs/);
  assert.match(isolatedRunner, /--authenticated=1/);
  assert.match(isolatedRunner, /--require-verified=1/);
  assert.match(isolatedRunner, /const performanceEnforceBudget[\s\S]*:\s*'1';/);
  assert.match(isolatedRunner, /`--enforce-budget=\$\{performanceEnforceBudget\}`/);
  assert.match(isolatedRunner, /--performance-iterations must be an integer between 1 and 30/);
  assert.match(isolatedRunner, /--performance-enforce-budget must be 0 or 1/);
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
