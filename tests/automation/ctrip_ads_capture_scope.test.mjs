import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const flush = () => new Promise(resolve => setImmediate(resolve));
const main = fs.readFileSync(process.env.SUXIOS_CTRIP_ADS_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const helper = fs.readFileSync(process.env.SUXIOS_CTRIP_ADS_SOURCE
  || new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const section = (start, end) => {
  const a = main.indexOf(start), b = main.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `production section: ${start}`);
  return main.slice(a, b);
};
const production = [
  section('const postFetchRefreshTimers =', 'const normalizeCanonicalPage ='),
  section('const currentPlatformHotelId =', 'const ctripSearchOpportunityPayload ='),
  section('const scheduleOnlineHistoryRefresh =', 'const scheduleAutoFetchStatusRefresh ='),
  section('const fetchCtripAdsData =', '// 美团流量数据获取'),
  section('const clearCtripOverviewDisplayState =', 'const getCtripOverviewTargetHotelId ='),
].join('\n');
const plain = value => JSON.parse(JSON.stringify(value));
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

// Execute the actual Flow, main entry, hotel clear, and both scheduler layers.
// Time, identity, configuration metadata, and final I/O dependencies are test-only.
function harness({ configPending = false, missingConfig = false, legacy = false, form = {} } = {}) {
  const effects = [], requests = [], notices = [], refreshes = [], uiTasks = [], syncCalls = [];
  const timers = new Map(), syncWait = deferred();
  const identity = { session: 1 };
  const controls = { configPending, missingConfig };
  let timerId = 0;
  const ref = (value, name = '') => ({
    get value() { return value; },
    set value(next) { value = next; if (name) effects.push([name, next]); },
  });
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) refs[match[1]] ??= ref(null);
  Object.assign(refs, {
    selectedCtripHotelId: ref('901'), meituanForm: ref({ hotelId: '' }),
    ctripAdsBrowserCaptureRunning: ref(false, 'running'), fetchingData: ref(false, 'fetching'),
    ctripAdsBrowserCaptureResult: ref(null, 'result'), onlineDataResult: ref(null, 'online'),
    showRawData: ref(true, 'raw'), ctripCookieApiRunning: ref(false),
    ctripAdsBrowserCaptureForm: ref({ url: 'https://ebooking.ctrip.com/datacenter/api/ads/queryCampaignReportList',
      apiType: 'effect_report', dateRange: 'custom', startDate: '2026-09-26', endDate: '2026-09-27', ...form }),
  });
  const config = () => controls.missingConfig ? null : ({
    config_id: `synthetic-${refs.selectedCtripHotelId.value}`,
    system_hotel_id: refs.selectedCtripHotelId.value,
    ctrip_hotel_id: `ota-${refs.selectedCtripHotelId.value}`,
    credential_status: 'ready', has_cookies: true,
  });
  const record = (name, args) => {
    refreshes.push({ name, hotel: refs.selectedCtripHotelId.value, session: identity.session, args: plain(args) });
    return Promise.resolve();
  };
  const reviewBindings = { invalidateCtripReviewMatch() {} };
  for (const match of production.matchAll(/ctripReviewMatchControllerBindings\.([\w$]+)\.value/g)) {
    reviewBindings[match[1]] = ref(null);
  }
  const context = vm.createContext({
    ...refs, window: { SUXI_DATA_HEALTH_STATIC: {} }, console, URL,
    ctripPlatformHotelContextEpoch: 0, meituanPlatformHotelContextEpoch: 0,
    ctripAdsCaptureRequestSeq: 0, ctripManualFetchActive: false,
    ctripReviewMatchControllerBindings: reviewBindings,
    captureAuthSession: () => ({ epoch: identity.session }),
    isAuthSessionCurrent: captured => captured?.epoch === identity.session,
    getActiveCtripConfig: config,
    applyCtripConfigObject: value => effects.push(['apply-config', value.config_id]),
    syncCtripAdsDirectConfig: showMessage => {
      syncCalls.push(showMessage);
      return controls.configPending ? syncWait.promise : Promise.resolve(true);
    },
    getHotelNameById: hotel => `Synthetic hotel ${hotel}`,
    defaultCtripAdsEffectReportUrl: '', ctripAdsApiUrlHint: 'synthetic JSON API URL required',
    showToast: (...args) => notices.push(args),
    request: (url, options) => {
      const row = { ...deferred(), url, body: JSON.parse(options.body) };
      requests.push(row);
      return row.promise;
    },
    loadLatestCtripData: (...args) => record('latest', args),
    refreshOnlineHistory: (...args) => record('history', args),
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    deferUiTask: (callback, delay) => uiTasks.push({ callback, delay }),
  });
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\s*\+=\s*1/g)) {
    if (!(match[1] in context)) context[match[1]] = 0;
  }
  vm.runInContext(helper, context, { filename: 'production-ctrip-static.js' });
  const flow = context.window.SUXI_CTRIP_STATIC.runCtripAdsFetchFlow;
  context.runCtripAdsFetchFlow = legacy ? options => flow({ ...options,
    captureRequestContext: undefined, isRequestContextCurrent: undefined, isRunning: undefined,
  }) : flow;
  vm.runInContext(`${production}\nglobalThis.api = {
    run: fetchCtripAdsData, clear: clearCtripOverviewDisplayState, clearTimers: clearPostFetchRefreshTimers
  };`, context, { filename: 'production-main-ads-scope.js' });
  const switchScope = kind => {
    if (kind === 'auth') identity.session++;
    else refs.selectedCtripHotelId.value = '902';
    context.api.clear();
    if (kind === 'hotel-A-B-A') {
      refs.selectedCtripHotelId.value = '901';
      context.api.clear();
    }
    if (kind === 'auth') context.api.clearTimers();
  };
  const snapshot = () => plain({ running: refs.ctripAdsBrowserCaptureRunning.value,
    fetching: refs.fetchingData.value, result: refs.ctripAdsBrowserCaptureResult.value,
    online: refs.onlineDataResult.value, raw: refs.showRawData.value });
  const fireTimers = () => {
    for (const [id, timer] of [...timers.entries()].sort((a, b) => a[1].delay - b[1].delay)) {
      if (!timers.has(id)) continue;
      timers.delete(id);
      timer.callback();
    }
  };
  const runUiTasks = async () => { for (const task of uiTasks.splice(0)) await task.callback(); };
  return { api: context.api, refs, controls, effects, requests, notices, refreshes, timers, uiTasks,
    syncCalls, syncWait, switchScope, snapshot, fireTimers, runUiTasks };
}

const responses = {
  success: () => ({ code: 200, data: { saved_count: 1, persisted: true, readback_verified: true,
    rows: [{ platform: 'ctrip', data_date: '2026-09-27', exposure: 0, spend: 0 }] } }),
  accepted: () => ({ code: 200, data: { status: 'queued', task_id: 'synthetic-task', platform: 'ctrip' } }),
  failed: () => ({ code: 403, message: 'synthetic denied', data: { identity_check: { status: 'blocked' } } }),
  business_failed: () => ({ code: 200, data: { status: 'failed', saved_count: 0 } }),
  incomplete: () => ({ code: 200, data: {} }),
};
async function begin(h) {
  const pending = h.api.run();
  await flush();
  return { pending };
}
async function finish(h, pending, kind = 'success', index = h.requests.length - 1) {
  if (kind === 'exception') h.requests[index].reject(new Error('synthetic capture error'));
  else h.requests[index].resolve(responses[kind]());
  return pending;
}
const expectedRefreshes = (hotel = '901', session = 1) => [
  { name: 'history', hotel, session, args: [{ refreshHotels: false }] },
  { name: 'latest', hotel, session, args: [{ silent: true }] },
];

for (const scope of ['auth', 'hotel', 'hotel-A-B-A']) {
  for (const outcome of ['success', 'accepted', 'failed', 'exception']) {
    test(`actual Ads entry ignores ${scope} stale POST ${outcome}`, async () => {
      const h = harness(), { pending } = await begin(h);
      assert.equal(h.requests.length, 1);
      h.switchScope(scope);
      const cleared = h.snapshot();
      h.effects.length = h.notices.length = 0;
      const result = await finish(h, pending, outcome);
      assert.equal(result.status, 'stale');
      assert.deepEqual(h.snapshot(), cleared);
      assert.deepEqual(h.effects, []);
      assert.deepEqual(h.notices, []);
      assert.equal(h.timers.size, 0);
    });
  }
  for (const outcome of ['resolve', 'reject']) {
    test(`actual Ads entry ignores ${scope} stale config ${outcome} before POST`, async () => {
      const h = harness({ configPending: true });
      const pending = h.api.run().catch(error => ({ status: 'uncaught', error }));
      h.switchScope(scope);
      const cleared = h.snapshot();
      h.effects.length = h.notices.length = 0;
      if (outcome === 'reject') h.syncWait.reject(new Error('synthetic config failure'));
      else h.syncWait.resolve(true);
      await flush();
      if (h.requests[0]) h.requests[0].resolve(responses.success()); // Let the broken baseline terminate.
      assert.equal((await pending).status, 'stale');
      assert.equal(h.requests.length, 0);
      assert.deepEqual(h.snapshot(), cleared);
      assert.deepEqual(h.effects, []);
      assert.deepEqual(h.notices, []);
    });
  }
}

test('actual Ads entry owns busy state during config preparation and rejects duplicate work', async () => {
  const h = harness({ configPending: true });
  const pending = h.api.run();
  assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, true);
  assert.equal(h.refs.fetchingData.value, true);
  const before = h.effects.slice();
  assert.equal((await h.api.run()).status, 'busy');
  assert.equal(h.syncCalls.length, 1);
  assert.deepEqual(h.effects, before);
  h.syncWait.resolve(true);
  await flush();
  assert.equal((await finish(h, pending)).status, 'success');
});

test('actual Ads entry rejects duplicate POST without invalidating the current owner', async () => {
  const h = harness(), { pending } = await begin(h);
  const duplicate = h.api.run();
  await flush();
  for (const row of h.requests.slice(1)) row.resolve(responses.success());
  assert.equal((await duplicate).status, 'busy');
  assert.equal(h.requests.length, 1);
  assert.equal((await finish(h, pending)).status, 'success');
});

for (const [name, form] of [
  ['valid request', {}],
  ['invalid API URL', { url: 'https://synthetic.invalid/api/ads' }],
  ['missing custom dates', { startDate: '' }],
]) {
  test(`actual Ads entry preserves another capture's global busy state for ${name}`, async () => {
    const h = harness({ form });
    h.refs.fetchingData.value = true;
    assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
    const before = h.snapshot();
    h.effects.length = 0;
    const pending = h.api.run();
    await flush();
    // Resolve any erroneous baseline POST so failure remains deterministic and does not hang.
    for (const request of h.requests) request.resolve(responses.success());
    assert.equal((await pending).status, 'busy');
    assert.deepEqual(h.snapshot(), before);
    assert.equal(h.refs.fetchingData.value, true);
    assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
    assert.equal(h.requests.length, 0);
    assert.equal(h.syncCalls.length, 0);
    assert.deepEqual(h.effects, []);
    assert.deepEqual(h.notices, []);
    assert.equal(h.timers.size, 0);
  });
}

for (const outcome of ['success', 'accepted', 'failed', 'business_failed', 'incomplete', 'exception']) {
  test(`current Ads ${outcome} preserves status, zero values, and relevant refresh behavior`, async () => {
    const h = harness(), { pending } = await begin(h);
    const result = await finish(h, pending, outcome);
    assert.equal(result.status, outcome);
    assert.equal(h.refs.ctripAdsBrowserCaptureResult.value.ui_flow_status, outcome);
    assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
    assert.equal(h.refs.fetchingData.value, false);
    assert.equal(h.requests[0].url, '/online-data/fetch-ctrip-ads');
    assert.equal(h.requests[0].body.system_hotel_id, '901');
    assert.equal(h.requests[0].body.hotel_id, 'ota-901');
    assert.equal(h.requests[0].body.start_date, '2026-09-26');
    assert.equal(h.requests[0].body.end_date, '2026-09-27');
    assert.equal(h.requests[0].body.async, false);
    assert.equal(h.requests[0].body.background, false);
    if (outcome === 'success') {
      assert.equal(h.refs.onlineDataResult.value.rows[0].exposure, 0);
      assert.equal(h.refs.onlineDataResult.value.rows[0].spend, 0);
      assert.equal(result.readback_verified, true);
    }
    const shouldRefresh = !['failed', 'exception'].includes(outcome);
    assert.deepEqual([...h.timers.values()].map(row => row.delay).sort((a, b) => a - b), shouldRefresh ? [340, 420] : []);
    h.fireTimers();
    assert.deepEqual(h.refreshes, []);
    await h.runUiTasks();
    assert.deepEqual(h.refreshes, shouldRefresh ? expectedRefreshes() : []);
    assert.ok(h.notices.length > 0);
  });
}

test('Ads readback failure replaces prior shared success for returned and thrown server errors', async () => {
  for (const throws of [false, true]) {
    const h = harness();
    h.refs.onlineDataResult.value = { ui_flow_status: 'success', saved_count: 5, readback_verified: true };
    const { pending } = await begin(h);
    const response = { code: 500, message: '数据库回读不完整；请核对历史记录',
      data: { saved_count: 1, row_count: 2, readback_verified: false, persistence_status: 'readback_not_verified' } };
    if (throws) h.requests[0].reject(Object.assign(new Error(response.message), { data: response }));
    else h.requests[0].resolve(response);
    assert.equal((await pending).status, throws ? 'exception' : 'failed');
    const shared = h.refs.onlineDataResult.value;
    assert.equal(shared.ui_flow_status, throws ? 'exception' : 'failed');
    assert.equal(shared.saved_count, 1);
    assert.equal(shared.readback_verified, false);
    assert.match(shared.error, /回读不完整/);
    assert.equal(h.refs.ctripAdsBrowserCaptureResult.value.ui_flow_status, shared.ui_flow_status);
    assert.equal(h.refs.showRawData.value, false);
  }
});

test('current config exception is visible, releases loading, and allows a real retry', async () => {
  const h = harness({ configPending: true });
  const pending = h.api.run().catch(error => ({ status: 'uncaught', error }));
  h.syncWait.reject(new Error('synthetic config failure'));
  assert.equal((await pending).status, 'exception');
  assert.match(h.refs.ctripAdsBrowserCaptureResult.value.error, /config failure/);
  assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
  assert.equal(h.refs.fetchingData.value, false);
  assert.equal(h.requests.length, 0);
  h.controls.configPending = false;
  const retry = await begin(h);
  assert.equal((await finish(h, retry.pending)).status, 'success');
});

for (const [name, options, expected] of [
  ['missing config', { missingConfig: true }, 'missing_config'],
  ['advertising page URL', { form: { url: 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid' } }, 'invalid_page_url'],
  ['invalid API URL', { form: { url: 'https://synthetic.invalid/api/ads' } }, 'invalid_api_url'],
  ['missing custom dates', { form: { startDate: '' } }, 'missing_custom_dates'],
]) {
  test(`current Ads ${name} retains failure status and no stuck loading`, async () => {
    const h = harness(options);
    assert.equal((await h.api.run()).status, expected);
    assert.equal(h.requests.length, 0);
    assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
    assert.equal(h.refs.fetchingData.value, false);
    assert.ok(h.notices.length > 0);
  });
}

test('clear releases only active Ads loading and the new real owner survives an old POST', async () => {
  const h = harness(), old = await begin(h);
  h.switchScope('hotel');
  assert.equal(h.refs.ctripAdsBrowserCaptureRunning.value, false);
  assert.equal(h.refs.fetchingData.value, false);
  const newer = await begin(h);
  assert.equal(h.requests.length, 2);
  const current = h.snapshot();
  h.effects.length = h.notices.length = 0;
  assert.equal((await finish(h, old.pending, 'success', 0)).status, 'stale');
  assert.deepEqual(h.snapshot(), current);
  assert.deepEqual(h.effects, []);
  assert.deepEqual(h.notices, []);
  assert.equal((await finish(h, newer.pending, 'success', 1)).status, 'success');
  assert.equal(h.requests[1].body.system_hotel_id, '902');
});

test('an inactive Ads clear does not release unrelated global loading', () => {
  const h = harness();
  h.refs.fetchingData.value = true;
  h.api.clear();
  assert.equal(h.refs.fetchingData.value, true);
});

for (const scope of ['auth', 'hotel', 'hotel-A-B-A']) {
  for (const stage of ['timer', 'defer']) {
    for (const outcome of ['success', 'accepted']) {
      test(`completed Ads ${outcome} does not refresh after ${scope} changes during ${stage}`, async () => {
        const h = harness(), { pending } = await begin(h);
        assert.equal((await finish(h, pending, outcome)).status, outcome);
        if (stage === 'defer') {
          h.fireTimers();
          assert.equal(h.uiTasks.length, 2);
        }
        h.switchScope(scope);
        h.fireTimers();
        await h.runUiTasks();
        assert.deepEqual(h.refreshes, []);
      });
    }
  }
}

test('a newer Ads request invalidates old deferred reads while retaining its loading', async () => {
  const h = harness(), first = await begin(h);
  assert.equal((await finish(h, first.pending)).status, 'success');
  h.fireTimers();
  const second = await begin(h);
  const before = h.snapshot();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, []);
  assert.deepEqual(h.snapshot(), before);
  assert.equal((await finish(h, second.pending)).status, 'success');
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});

test('same-key refresh replacement keeps only the latest completed Ads callbacks', async () => {
  const h = harness(), first = await begin(h);
  await finish(h, first.pending);
  const oldTimers = [...h.timers.keys()];
  const second = await begin(h);
  await finish(h, second.pending);
  assert.equal(h.timers.size, 2);
  assert.ok(oldTimers.every(id => !h.timers.has(id)));
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});

test('Ads Flow keeps compatibility when callers omit all ownership hooks', async () => {
  const h = harness({ legacy: true }), { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});
