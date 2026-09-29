import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_HOME_SECONDARY_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const take = name => {
  const start = main.indexOf(`\n            const ${name} =`) + 1;
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start > 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const declarationsStart = main.indexOf('            const HOME_SECONDARY_PANEL_DELAY_MS =');
const declarationsEnd = main.indexOf('            const selectedWeatherCity =', declarationsStart);
assert.ok(declarationsStart >= 0 && declarationsEnd > declarationsStart);
const homeDeclarations = main.slice(declarationsStart, declarationsEnd);
const flush = () => new Promise(resolve => setImmediate(resolve));
const copy = value => JSON.parse(JSON.stringify(value));

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

// Production scheduling/clearing, both runtime readiness functions, the actual
// shared full-helper Promise implementation and page/auth policy are extracted.
// Only script transport and timers are synthetic: each in-flight asset has ONE
// shared outcome. The overview boundary records its live invocation scope; it
// does not reproduce the overview reader or claim network/browser validation.
function harness({ runtimeReady = false, fullReady = false, loaderAvailable = true,
  page = 'compass', hotel = '81', tenant = 'tenant-a', date = '2026-09-12' } = {}) {
  const refs = {
    token: Vue.ref('synthetic-session-a'), currentPage: Vue.ref(page),
    filterReportHotel: Vue.ref(hotel), authContext: Vue.ref({ tenant_id: tenant }),
    revenueAiBusinessDate: Vue.ref(date), coreOperationsTargetDate: Vue.ref(date),
    revenueAiOverviewError: Vue.ref(''), revenueAiStaticReady: Vue.ref(fullReady),
    revenueAiStaticLoading: Vue.ref(false), revenueAiStaticError: Vue.ref(''),
    revenueAiStaticRevision: Vue.ref(0),
  };
  const business = {};
  const timers = new Map();
  const scripts = [];
  const runtimeLoads = [];
  const overviewCalls = [];
  let nextTimer = 0;
  let runtimePending = null;
  const fullObject = { testInterface() {} };
  const window = {};
  const registerRuntime = () => {
    window.SUXI_DATA_HEALTH_STATIC = {};
    window.SUXI_REVENUE_OVERVIEW_CONTRACT_STATIC = {};
  };
  if (runtimeReady) registerRuntime();
  if (fullReady) window.SUXI_REVENUE_AI_STATIC = fullObject;
  if (loaderAvailable) window.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = name => {
    assert.equal(name, 'app-deferred-helpers.min.js');
    if (!runtimePending) {
      runtimePending = { ...deferred(), settled: false };
      runtimeLoads.push(runtimePending);
    }
    return runtimePending.promise;
  };
  const document = {
    createElement: tag => {
      assert.equal(tag, 'script');
      return { dataset: {}, removed: false, remove() { this.removed = true; } };
    },
    head: { appendChild: script => scripts.push(script) },
    querySelector: () => scripts.find(script => !script.removed && !script.dataset.loaded) || null,
  };
  let context;
  const liveScope = () => ({
    page: refs.currentPage.value, generation: context.pageRequestGeneration,
    hotel: String(refs.filterReportHotel.value || business.system_hotel_id || ''),
    tenant: String(business.tenant_id || refs.authContext.value.tenant_id || ''),
    date: String(business.business_date || refs.revenueAiBusinessDate.value || ''),
    epoch: context.authSessionEpoch, token: refs.token.value,
  });
  const loadRevenueAiOverview = () => {
    // The separately covered real overview reader claims this shared sequence
    // before its ensure/request boundary. Keep that boundary effect observable.
    context.revenueAiOverviewRequestSeq += 1;
    overviewCalls.push(liveScope());
    return Promise.resolve(null);
  };
  context = vm.createContext({
    window, document, ref: Vue.ref, ...refs, authSessionEpoch: 1, pageRequestGeneration: 3,
    currentBusinessRequestContext: () => business,
    normalizeCanonicalPage: value => String(value || ''),
    setTimeout: (callback, delay) => { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    revenueAiStaticScript: 'revenue-ai-static.js', revenueAiStaticVersion: 'fixture',
    revenueAiStaticLoadPromise: null, revenueAiStaticFallbacks: { testInterface() {} },
    revenueAiOverviewRequestSeq: 0,
    loadRevenueAiOverview,
  });
  const names = ['captureAuthSession', 'isAuthSessionCurrent', 'isCompassDataPage',
    'currentPageReadPolicy', 'currentCompassReadPolicy', 'buildPageLoadScopeToken',
    'isPageLoadPolicyCurrent', 'clearHomeSecondaryPanelsReadyTimer',
    'ensureHomeSecondaryStaticRuntimeReady', 'scheduleHomeSecondaryPanelsReady',
    'loadRevenueAiStatic', 'ensureRevenueAiStaticReady'];
  vm.runInContext(`${homeDeclarations}\n${names.map(take).join('\n')}\nObject.assign(globalThis, {
    schedule: scheduleHomeSecondaryPanelsReady, clear: clearHomeSecondaryPanelsReadyTimer,
    readyRef: homeSecondaryPanelsReady, compassPolicy: currentCompassReadPolicy,
    isPolicyCurrent: isPageLoadPolicyCurrent, runtime: ensureHomeSecondaryStaticRuntimeReady,
    ensureFull: ensureRevenueAiStaticReady, loadFull: loadRevenueAiStatic,
  });`, context);
  return {
    refs, context, business, timers, scripts, runtimeLoads, overviewCalls,
    ready: () => context.readyRef.value, error: () => refs.revenueAiOverviewError.value,
    schedule: (...args) => context.schedule(...args), clear: () => context.clear(),
    fire() {
      const entry = timers.entries().next().value;
      assert.ok(entry, 'a deterministic timer is pending');
      const [id, { callback }] = entry;
      timers.delete(id);
      callback();
    },
    resolveRuntime({ register = true } = {}) {
      assert.ok(runtimePending && !runtimePending.settled, 'one runtime transport is pending');
      const pending = runtimePending;
      pending.settled = true;
      runtimePending = null;
      if (register) registerRuntime();
      pending.resolve();
    },
    rejectRuntime(message = 'synthetic deferred asset failure') {
      assert.ok(runtimePending && !runtimePending.settled);
      const pending = runtimePending;
      pending.settled = true;
      runtimePending = null;
      pending.reject(new Error(message));
    },
    resolveFull({ register = true } = {}) {
      const script = scripts.find(item => !item.settled && !item.removed);
      assert.ok(script, 'one full-helper transport is pending');
      script.settled = true;
      if (register) window.SUXI_REVENUE_AI_STATIC = fullObject;
      script.onload();
    },
    rejectFull() {
      const script = scripts.find(item => !item.settled && !item.removed);
      assert.ok(script);
      script.settled = true;
      script.onerror();
    },
    async reach(stage) {
      context.schedule();
      this.fire();
      await flush();
      if (stage === 'full') { this.resolveRuntime(); await flush(); }
    },
    async complete(stage) {
      if (stage === 'runtime') { this.resolveRuntime(); await flush(); }
      if (scripts.some(item => !item.settled && !item.removed)) this.resolveFull();
      await flush();
    },
    leaveAndReturn() {
      // These are the actual currentPage watcher lifecycle effects relevant to
      // hydration: increment generation, clear on departure, schedule on entry.
      refs.currentPage.value = 'agent-center';
      context.pageRequestGeneration += 1;
      context.clear();
      context.readyRef.value = false;
      refs.currentPage.value = 'compass';
      context.pageRequestGeneration += 1;
      context.schedule();
    },
    independentOverviewRead: loadRevenueAiOverview,
  };
}

test('current hydration waits for both actual readiness stages and reads overview once', async () => {
  const h = harness();
  h.schedule();
  assert.equal([...h.timers.values()][0].delay, 4200);
  h.fire();
  await flush();
  assert.equal(h.ready(), false);
  assert.equal(h.runtimeLoads.length, 1);
  assert.equal(h.scripts.length, 0);
  h.resolveRuntime();
  await flush();
  assert.equal(h.ready(), false);
  assert.equal(h.scripts.length, 1);
  h.resolveFull();
  await flush();
  assert.equal(h.ready(), true);
  assert.equal(h.overviewCalls.length, 1);
});

test('rescheduling hides previously rendered panels before the new delay', () => {
  const h = harness();
  h.context.readyRef.value = true;
  h.schedule();
  assert.equal(h.ready(), false);
  assert.equal([...h.timers.values()][0].delay, 4200);
  assert.equal(h.runtimeLoads.length + h.scripts.length, 0);
});

test('already registered tools activate current panels without additional transport', async () => {
  const h = harness({ runtimeReady: true, fullReady: true });
  h.schedule(19);
  assert.equal([...h.timers.values()][0].delay, 19);
  h.fire();
  await flush();
  assert.equal(h.ready(), true);
  assert.equal(h.overviewCalls.length, 1);
  assert.equal(h.runtimeLoads.length + h.scripts.length, 0);
});

test('clear before timer fires cancels all work', async () => {
  const h = harness();
  h.schedule();
  h.clear();
  await flush();
  assert.equal(h.timers.size, 0);
  assert.equal(h.runtimeLoads.length + h.scripts.length + h.overviewCalls.length, 0);
  assert.equal(h.ready(), false);
});

test('non-compass scheduling keeps secondary panels closed and creates no timer', () => {
  const h = harness({ page: 'agent-center' });
  h.context.readyRef.value = true;
  h.schedule();
  assert.equal(h.ready(), false);
  assert.equal(h.timers.size, 0);
});

test('missing token when timer fires starts no loaders', async () => {
  const h = harness();
  h.schedule();
  h.refs.token.value = '';
  h.fire();
  await flush();
  assert.equal(h.runtimeLoads.length + h.scripts.length + h.overviewCalls.length, 0);
  assert.equal(h.ready(), false);
});

test('an account replaced before the timer fires cannot launch the previous schedule', async () => {
  const h = harness();
  h.schedule();
  h.refs.token.value = 'synthetic-session-b';
  h.context.authSessionEpoch += 1;
  h.fire();
  await flush();
  assert.equal(h.runtimeLoads.length + h.scripts.length + h.overviewCalls.length, 0);
  assert.equal(h.ready(), false);
});

for (const stage of ['runtime', 'full']) {
  for (const invalidate of ['clear', 'leave-return', 'reschedule', 'session', 'token', 'generation']) {
    test(`${stage}: ${invalidate} makes a pending old success inert`, async () => {
      const h = harness();
      await h.reach(stage);
      if (invalidate === 'clear') h.clear();
      if (invalidate === 'leave-return') h.leaveAndReturn();
      if (invalidate === 'reschedule') h.schedule();
      if (invalidate === 'session') h.context.authSessionEpoch += 1;
      if (invalidate === 'token') h.refs.token.value = 'synthetic-session-b';
      if (invalidate === 'generation') h.context.pageRequestGeneration += 1;
      await h.complete(stage);
      assert.equal(h.ready(), false, 'stale callback cannot activate panels');
      assert.equal(h.overviewCalls.length, 0, 'stale callback cannot initiate an overview read');
      if (stage === 'runtime') assert.equal(h.scripts.length, 0, 'stale first stage cannot start the next loader');
      assert.equal(h.error(), '');
      if (invalidate === 'reschedule' || invalidate === 'leave-return') {
        h.fire();
        await flush();
        if (h.scripts.some(item => !item.settled && !item.removed)) h.resolveFull();
        await flush();
        assert.equal(h.ready(), true, 'the replacement timer can recover');
        assert.equal(h.overviewCalls.length, 1);
      }
    });
  }

  test(`${stage}: two owners share one transport but only the latest callback reads overview`, async () => {
    const h = harness();
    await h.reach(stage);
    h.schedule();
    h.fire();
    await flush();
    assert.equal(h.runtimeLoads.length, 1, 'shared runtime transport is not independently resolved per consumer');
    if (stage === 'full') assert.equal(h.scripts.length, 1, 'actual full helper loader deduplicates its Promise');
    await h.complete(stage);
    assert.equal(h.ready(), true);
    assert.equal(h.overviewCalls.length, 1);
    assert.equal(h.scripts.length, 1);
  });

  test(`${stage}: cleared callback failure cannot replace a current overview error`, async () => {
    const h = harness();
    await h.reach(stage);
    h.clear();
    h.schedule();
    // A direct overview consumer can start independently of the home runtime
    // asset. It supplies current read state, not a contradictory second outcome
    // for the shared in-flight helper transport.
    await h.independentOverviewRead();
    h.refs.revenueAiOverviewError.value = 'current overview business failure';
    if (stage === 'runtime') h.rejectRuntime('old runtime failure');
    else h.rejectFull();
    await flush();
    assert.equal(h.error(), 'current overview business failure');
    assert.equal(h.ready(), false);
    assert.equal(h.overviewCalls.length, 1);
  });

  test(`${stage}: old session failure cannot publish its error into the new account`, async () => {
    const h = harness();
    await h.reach(stage);
    h.context.authSessionEpoch += 1;
    h.refs.token.value = 'synthetic-session-b';
    h.refs.revenueAiOverviewError.value = 'new session state';
    if (stage === 'runtime') h.rejectRuntime();
    else h.rejectFull();
    await flush();
    assert.equal(h.error(), 'new session state');
    assert.equal(h.overviewCalls.length, 0);
  });

  for (const change of ['hotel', 'tenant', 'date', 'empty-to-concrete']) {
    test(`${stage}: same-page ${change} hydration reads the latest live scope`, async () => {
      const h = harness(change === 'empty-to-concrete' ? { hotel: '', tenant: '', date: '' } : {});
      await h.reach(stage);
      if (change === 'hotel' || change === 'empty-to-concrete') h.refs.filterReportHotel.value = '82';
      if (change === 'tenant' || change === 'empty-to-concrete') h.refs.authContext.value = { tenant_id: 'tenant-b' };
      if (change === 'date' || change === 'empty-to-concrete') h.refs.revenueAiBusinessDate.value = '2026-09-26';
      await h.complete(stage);
      assert.equal(h.ready(), true, 'pure static hydration must not be stranded by data-scope changes');
      assert.equal(h.overviewCalls.length, 1);
      assert.equal(h.overviewCalls[0].hotel, change === 'hotel' || change === 'empty-to-concrete' ? '82' : '81');
      assert.equal(h.overviewCalls[0].tenant, change === 'tenant' || change === 'empty-to-concrete' ? 'tenant-b' : 'tenant-a');
      assert.equal(h.overviewCalls[0].date, change === 'date' || change === 'empty-to-concrete' ? '2026-09-26' : '2026-09-12');
    });
  }

  test(`${stage}: current failure is explicit and a later scheduled retry succeeds`, async () => {
    const h = harness();
    await h.reach(stage);
    if (stage === 'runtime') h.rejectRuntime('current runtime failure');
    else h.rejectFull();
    await flush();
    assert.match(h.error(), stage === 'runtime' ? /current runtime failure/ : /Revenue AI 展示工具加载失败/);
    assert.equal(h.ready(), false);
    assert.equal(h.overviewCalls.length, 0);
    h.schedule();
    h.fire();
    await flush();
    if (stage === 'runtime') { h.resolveRuntime(); await flush(); }
    h.resolveFull();
    await flush();
    assert.equal(h.ready(), true);
    assert.equal(h.overviewCalls.length, 1);
  });
}

test('missing runtime loader reports an explicit current error', async () => {
  const h = harness({ loaderAvailable: false });
  h.schedule();
  h.fire();
  await flush();
  assert.match(h.error(), /首页.*加载器不可用/);
  assert.equal(h.ready(), false);
  assert.equal(h.overviewCalls.length, 0);
});

test('a still-current runtime callback cannot replace a newer independent overview error', async () => {
  const h = harness();
  await h.reach('runtime');
  // Revenue AI full helpers and the authenticated deferred helper asset are
  // independent transports. A direct overview consumer can finish its full
  // prerequisite while this home runtime asset remains pending.
  const fullReady = h.context.loadFull();
  await flush();
  h.resolveFull();
  await fullReady;
  await h.independentOverviewRead();
  h.refs.revenueAiOverviewError.value = 'current overview business failure';
  h.rejectRuntime('old runtime asset failure');
  await flush();
  assert.equal(h.error(), 'current overview business failure');
  assert.equal(h.ready(), false);
  assert.equal(h.overviewCalls.length, 1);
  assert.equal(h.runtimeLoads.length, 1);
  assert.equal(h.scripts.length, 1);
});

test('runtime transport success without registration remains a failure', async () => {
  const h = harness();
  await h.reach('runtime');
  h.resolveRuntime({ register: false });
  await flush();
  assert.match(h.error(), /首页.*工具.*未注册/);
  assert.equal(h.scripts.length + h.overviewCalls.length, 0);
  assert.equal(h.ready(), false);
});

test('full helper transport success without registration remains a failure', async () => {
  const h = harness();
  await h.reach('full');
  h.resolveFull({ register: false });
  await flush();
  assert.match(h.error(), /Revenue AI 展示工具加载完成但未注册/);
  assert.equal(h.overviewCalls.length, 0);
  assert.equal(h.ready(), false);
});

test('actual compass policy excludes the hydrated report date while retaining page and auth identity', () => {
  const h = harness();
  const policy = h.context.compassPolicy();
  assert.equal(policy.businessDate, '');
  h.refs.revenueAiBusinessDate.value = '2026-09-26';
  assert.equal(h.context.isPolicyCurrent(policy), true);
  assert.deepEqual(copy(policy), {
    scope: 'page', pageKey: 'compass', pageGeneration: 3, tenantId: 'tenant-a',
    systemHotelId: '81', businessDate: '', priority: 'current', sessionEpoch: 1,
  });
  h.context.pageRequestGeneration += 1;
  assert.equal(h.context.isPolicyCurrent(policy), false);
});
