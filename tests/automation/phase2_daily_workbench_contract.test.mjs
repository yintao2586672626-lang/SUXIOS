import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { parse, tokenizer } from 'acorn';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
// A local candidate can be verified before replacing the checked-in guard.
const guardSource = read(process.env.SUXI_PHASE2_CONTRACT_CANDIDATE || 'scripts/verify_phase2_daily_workbench_contract.mjs');
const controllerPath = 'app/controller/concern/OperationWorkbenchConcern.php';
const manualFetchPath = 'app/controller/concern/OnlineDataManualFetchConcern.php';
const ctripPath = 'public/ctrip-static.js';
const dataHealthPath = 'public/data-health-static.js';
const appPath = 'public/app-main.js';
const appSource = read(appPath);

function runGuard(overrides = {}) {
  const context = {
    path,
    vm,
    parse,
    tokenizer,
    fs: {
      existsSync: (file) => Object.hasOwn(overrides, path.relative(root, file).replaceAll('\\', '/')) || fs.existsSync(file),
      readFileSync: (file) => {
        const relative = path.relative(root, file).replaceAll('\\', '/');
        return Object.hasOwn(overrides, relative) ? overrides[relative] : read(relative);
      },
    },
    process: { cwd: () => root, exit: (code) => { throw { guardExit: code }; } },
    console: { log() {}, error() {} },
  };
  const source = guardSource.replace(/^import .+;\r?\n/gm, '')
    .replace('const failures = checks.filter', 'globalThis.contractChecks = checks;\nconst failures = checks.filter');
  try {
    vm.runInNewContext(source, context, { timeout: 2000, filename: 'phase2-contract-synthetic-source.mjs' });
  } catch (error) {
    if (error?.guardExit !== 1) throw error;
  }
  assert.ok(Array.isArray(context.contractChecks), 'guard must finish collecting contract checks');
  return context.contractChecks.filter((item) => !item.ok);
}

test('phase2 guard accepts the current source, trait, bundle and confirmation contracts', () => {
  assert.deepEqual(Array.from(runGuard()), []);
});

const negativeCases = [
  ['canonical workflow is lost', controllerPath, "'workflow_chain' => (array)($operatingLoop['stages'] ?? [])", "'removed_workflow_chain' => []", 'employee-ready statuses'],
  ['kernel hotel scope is replaced', controllerPath, '$tenantId,\n            $hotelId,\n            $targetDate', '$tenantId,\n            999,\n            $targetDate', 'canonical stages use the current tenant hotel and date'],
  ['diagnostic chain is lost', controllerPath, "'diagnostic_workflow_chain' => $workflowChain", "'removed_diagnostic_workflow_chain' => []", 'employee-ready statuses'],
  ['execution concern is detached', manualFetchPath, 'use CtripManualFetchExecutionConcern;', '', 'composes and invokes its execution concern'],
  ['saved Qunar gap status is removed from the actual execution concern', 'app/controller/concern/CtripManualFetchExecutionConcern.php', "'saved_with_qunar_visitor_gap'", "'lost_gap_status'", 'zero Qunar visitors as a non-blocking field gap'],
  ['snake-case Qunar total is no longer recognized', dataHealthPath, 'quality?.total ?? quality?.visitor_total ?? 0', 'quality?.total ?? 0', 'saved-count aggregation'],
  ['fetch success bypasses report-source verification', ctripPath, 'setFetchSuccess(allHotels.length > 0 && isCtripVerifiedReportSource(currentFetchMeta))', 'setFetchSuccess(allHotels.length > 0)', 'persisted success from bounded display-only results'],
  ['report source accepts display-only data', ctripPath, "return String(meta?.status || '') === 'success'", "return ['success', 'display_only'].includes(String(meta?.status || ''))", 'exclude display-only data'],
  ['compass is removed from the startup bundle sources', 'scripts/lib/frontend_startup_helpers_build.mjs', "  'compass-static.js',", '', 'home entry points'],
  ['startup bundle is deferred beyond the startup entry', 'public/index.html', /"app-startup-helpers\.min\.js\?v=[^"]+"/, '{"src":"app-startup-helpers.min.js?v=synthetic","phase":"after-first-paint"}', 'home entry points'],
  ['report API route is replaced', appPath, 'fetch(API_BASE + `/online-data/daily-workbench-patrols/report?', 'fetch(API_BASE + `/online-data/removed-report?', 'loader uses read-only and patrol APIs'],
  ['API base is not the mounted API route', appPath, "const API_BASE = '/api'", "const API_BASE = '/other-api'", 'report export uses the API base'],
  ['first run click proceeds without confirmation', appPath, 'openDailyWorkbenchPatrolConfirmation(confirmationScope);\n                    return;', 'openDailyWorkbenchPatrolConfirmation(confirmationScope);', 'writes retain explicit operator confirmation'],
  ['scope change bypasses renewed confirmation', appPath, 'if (dailyWorkbenchPatrolConfirmationScope !== confirmationScope)', 'if (false)', 'writes retain explicit operator confirmation'],
  ['export continues after the user cancels', appPath, 'if (confirmation === null) return;', '', 'report export requires explicit confirmation'],
  ['export drops its same-session response and download checks', appPath, /if \(!isAuthSessionCurrent\(requestSession\) \|\| !isCurrentExportScope\(\)\) return;/g, 'if (!isCurrentExportScope()) return;', 'same-session response and download guards'],
  ['export drops its current hotel and snapshot response guards', appPath, /if \(!isAuthSessionCurrent\(requestSession\) \|\| !isCurrentExportScope\(\)\) return;/g, 'if (!isAuthSessionCurrent(requestSession)) return;', 'same-session response and download guards'],
  ['core refresh enables recursive workbench loading', appPath, 'includeDailyWorkbench: false,', 'includeDailyWorkbench: true,', 'refresh hydrates the one-page operating loop'],
  ['an extra OR admits display-only report evidence', ctripPath, '&& (verifiedFreshReadback || verifiedStoredSnapshot);', "&& (verifiedFreshReadback || verifiedStoredSnapshot) || meta?.status === 'display_only';", 'report source behavior'],
  ['compass bundle literal survives only in a comment', 'scripts/lib/frontend_startup_helpers_build.mjs', "  'compass-static.js',", "  // 'compass-static.js',", 'home entry points'],
  ['both run confirmation gates survive only in a comment', appPath, /if \(!dailyWorkbenchPatrolConfirming\.value\) \{[\s\S]+?(?=                cancelDailyWorkbenchPatrolConfirmation\(\);)/, (match) => `/* ${match} */`, 'writes retain explicit operator confirmation'],
  ['export cancellation survives only in a comment', appPath, 'if (confirmation === null) return;', '/* if (confirmation === null) return; */', 'report export requires explicit confirmation'],
  ['core refresh job survives only in a comment', appPath, /jobs\.push\(refreshCoreOperationsLoop\(\{[\s\S]*?\}\)\);/, (match) => `/* ${match} */`, 'refresh hydrates the one-page operating loop'],
];

for (const [name, file, before, after, label] of negativeCases) {
  test(`phase2 guard rejects synthetic regression: ${name}`, () => {
    const source = read(file).replace(/\r\n/g, '\n');
    assert.ok(typeof before === 'string' ? source.includes(before) : before.test(source), 'mutation anchor exists');
    const failures = runGuard({ [file]: source.replace(before, after) });
    assert.ok(failures.some((item) => item.label.includes(label)), `${label}: ${JSON.stringify(failures)}`);
  });
}

const staticContext = { window: {} };
vm.runInNewContext(read(dataHealthPath), staticContext, { filename: dataHealthPath, timeout: 2000 });
vm.runInNewContext(read(ctripPath), staticContext, { filename: ctripPath, timeout: 2000 });

test('real Qunar helper preserves camel/snake aliases, genuine zero and non-retryable states', () => {
  const needsRetry = staticContext.window.SUXI_DATA_HEALTH_STATIC.manualOneClickFetchQunarVisitorNeedsRetry;
  assert.equal(needsRetry({ rowCount: 26, total: 0, ready: false }), true);
  assert.equal(needsRetry({ row_count: 26, visitor_total: 0, ready: false }), true);
  assert.equal(needsRetry({ rowCount: 0, total: 0 }), false);
  assert.equal(needsRetry({ row_count: 26, visitor_total: 57 }), false);
  assert.equal(needsRetry({ rowCount: 26, total: 0, ready: true }), false);
  assert.equal(needsRetry({ rowCount: 26, total: 0, visitor_total: 57 }), true);
  assert.equal(needsRetry({}), false);
});

test('real Ctrip report helper rejects display-only, missing readback and conflicting source dates', () => {
  const api = staticContext.window.SUXI_CTRIP_STATIC;
  const verified = api.buildCtripFetchMeta({
    startDate: '2026-09-14', endDate: '2026-09-14', sourceBusinessDate: '2026-09-14',
    responseDateStatus: 'verified', persisted: true, readbackVerified: true,
  });
  assert.equal(api.isCtripVerifiedReportSource(verified), true);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, status: 'display_only' }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, readback_verified: false }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, response_date_status: 'unverified' }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, source_business_date: '2026-09-13' }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, request_date: '2026-09-13' }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, data_date: '' }), false);
  assert.equal(api.isCtripVerifiedReportSource({ ...verified, readback_verified: false, ranking_cache_eligible: true, verification_status: 'source_verified' }), true);
});

function patrolHarness() {
  const start = appSource.indexOf('const openDailyWorkbenchPatrolConfirmation =');
  const end = appSource.indexOf('const dailyWorkbenchPatrolActionKey =', start);
  assert.ok(start >= 0 && end > start);
  const requests = [];
  const downloads = [];
  const context = {
    URLSearchParams,
    console: { error() {} },
    dailyWorkbenchPatrolConfirming: { value: false },
    dailyWorkbenchPatrolRunning: { value: false },
    dailyWorkbenchPatrolError: { value: '' },
    dailyWorkbench: { value: { scope: { hotel_id: 7, target_date: '2026-09-14' } } },
    coreOperationsTargetDate: { value: '2026-09-14' },
    coreOperationsMaxDate: '2026-09-14',
    coreOperationsHotelId: { value: 7 },
    dailyWorkbenchPatrol: { value: {} },
    dailyWorkbenchPatrolLatest: { value: { run_id: 'synthetic-patrol', scope: { hotel_id: 7 } } },
    dailyWorkbenchWriteBoundary: { value: { export: { confirmText: 'synthetic export confirmation' } } },
    request: async (url, options) => {
      requests.push({ url, method: options.method, body: JSON.parse(options.body) });
      return { code: 200, data: { snapshot: { run_id: 'synthetic-patrol' } } };
    },
    captureAuthSession: () => ({ token: 'synthetic-session-only' }),
    isAuthSessionCurrent: () => true,
    openWorkflowFormDialog: async () => null,
    fetch: async (url, options) => {
      requests.push({ url, method: options.method });
      return { ok: true, headers: { get: (key) => key === 'content-type' ? 'text/markdown' : '' }, blob: async () => 'synthetic report' };
    },
    downloadBlob: (blob, filename) => downloads.push({ blob, filename }),
    showToast() {},
    loadPhase3OperationEffectLoop() {},
    API_BASE: '/api',
  };
  vm.runInNewContext(`let dailyWorkbenchPatrolConfirmationScope = '';
    let dailyWorkbenchPatrolReportExporting = false;
    ${appSource.slice(start, end)}
    globalThis.actions = { runDailyWorkbenchPatrol, cancelDailyWorkbenchPatrolConfirmation, exportDailyWorkbenchPatrolReport };`, context, { timeout: 2000 });
  return { context, requests, downloads, actions: context.actions };
}

test('real patrol run first click only confirms, second click posts the confirmed hotel and date', async () => {
  const { context, requests, actions } = patrolHarness();
  await actions.runDailyWorkbenchPatrol();
  assert.equal(requests.length, 0);
  assert.equal(context.dailyWorkbenchPatrolConfirming.value, true);
  await actions.runDailyWorkbenchPatrol();
  assert.equal(requests.length, 1);
  assert.deepEqual(requests[0], {
    url: '/online-data/daily-workbench-patrols/run', method: 'POST',
    body: { target_date: '2026-09-14', limit: 10, hotel_id: 7 },
  });
});

test('real patrol run requires a new confirmation after cancel or a hotel/date change', async () => {
  const { context, requests, actions } = patrolHarness();
  await actions.runDailyWorkbenchPatrol();
  actions.cancelDailyWorkbenchPatrolConfirmation();
  await actions.runDailyWorkbenchPatrol();
  assert.equal(requests.length, 0);
  context.dailyWorkbench.value.scope = { hotel_id: 8, target_date: '2026-09-15' };
  await actions.runDailyWorkbenchPatrol();
  assert.equal(requests.length, 0);
  assert.match(context.dailyWorkbenchPatrolError.value, /门店或目标日已变化/);
  await actions.runDailyWorkbenchPatrol();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].body.hotel_id, 8);
  assert.equal(requests[0].body.target_date, '2026-09-15');
});

test('real report export cancellation makes no request; confirmation downloads the scoped markdown', async () => {
  const { context, requests, downloads, actions } = patrolHarness();
  await actions.exportDailyWorkbenchPatrolReport();
  assert.equal(requests.length, 0);
  assert.equal(downloads.length, 0);
  context.openWorkflowFormDialog = async (options) => {
    assert.equal(options.description, 'synthetic export confirmation');
    return {};
  };
  await actions.exportDailyWorkbenchPatrolReport();
  assert.deepEqual(requests, [{ url: '/api/online-data/daily-workbench-patrols/report?run_id=synthetic-patrol&hotel_id=7', method: 'GET' }]);
  assert.equal(downloads.length, 1);
  assert.equal(downloads[0].blob, 'synthetic report');
});
