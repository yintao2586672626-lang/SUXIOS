import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const { ref } = createRequire(new URL('../../package.json', import.meta.url))('vue');
const mainSource = fs.readFileSync(process.env.SUXIOS_CTRIP_BUNDLE_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const cookieSource = fs.readFileSync(process.env.SUXIOS_CTRIP_COOKIE_SOURCE
  || new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const section = (start, end) => {
  const a = mainSource.indexOf(start), b = mainSource.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `Production section must exist: ${start}`);
  return mainSource.slice(a, b);
};
const production = [
  section('const postFetchRefreshTimers =', 'const normalizeCanonicalPage ='),
  section('const currentPlatformHotelId =', 'const ctripSearchOpportunityPayload ='),
  section('const scheduleOnlineHistoryRefresh =', 'const scheduleAutoFetchStatusRefresh ='),
  section('const scheduleDataHealthPanelRefresh =', 'const PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS ='),
  section('const runCtripCookieApiCapture =', 'const validateCtripEndpointEvidence ='),
  section('const prepareCtripOverviewFetchAction =', 'const runCtripTrafficBundleStep ='),
].join('\n');
const plain = value => JSON.parse(JSON.stringify(value));
const deferred = () => {
  let resolve;
  const promise = new Promise(yes => { resolve = yes; });
  return { promise, resolve };
};

// The production Flow, main wrapper, timer scheduler and refresh adapters are executed
// together. Only time, authentication identity and the final I/O endpoints are synthetic.
// In particular runPostFetchRefresh is not substituted with a different scheduler.
function harness() {
  const timers = new Map(), uiTasks = [], refreshes = [], requests = [], notices = [];
  let timerId = 0;
  const identity = { session: 1 };
  const selectedCtripHotelId = ref('901');
  const ctripCookieApiRunning = ref(false), fetchingData = ref(false);
  const ctripBrowserCaptureResult = ref(null), onlineDataResult = ref(null), showRawData = ref(true);
  const record = (name, args) => {
    refreshes.push({ name, hotel: selectedCtripHotelId.value, session: identity.session, args: plain(args) });
    return Promise.resolve(true);
  };
  const config = () => ({ config_id: `synthetic-${selectedCtripHotelId.value}`,
    system_hotel_id: selectedCtripHotelId.value, has_cookies: true, credential_status: 'ready' });
  const sandbox = {
    window: { SUXI_DATA_HEALTH_STATIC: {} }, console, URL,
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    deferUiTask: (callback, delay) => uiTasks.push({ callback, delay }),
    selectedCtripHotelId, meituanForm: ref({ hotelId: '' }),
    ctripCookieApiRunning, fetchingData, ctripBrowserCaptureResult, onlineDataResult, showRawData,
    ctripConfigList: ref([config()]), ctripCookieApiForm: ref({ requestSource: 'traffic_report' }),
    ctripOverviewForm: ref({ dataDate: '2026-09-27' }),
    ctripOverviewFetchActionLoading: ref(''), ctripOverviewCoreFetchRunning: ref(false),
    currentPage: ref('ctrip-ebooking'),
    syncCtripOverviewTargetHotel: async () => selectedCtripHotelId.value,
    ctripOverviewFetchActionMap: () => ({ 'ctrip-quality': async () => ({ status: 'success' }) }),
    captureAuthSession: () => ({ epoch: identity.session }),
    isAuthSessionCurrent: session => session.epoch === identity.session,
    loadCtripConfigList: async () => {}, getActiveCtripConfig: config,
    findCtripConfigByHotelId: config, applyCtripConfigObject: () => {},
    getHotelNameById: hotelId => `Synthetic hotel ${hotelId}`,
    resolveCtripCookieApiProfileId: hotelId => `synthetic-profile-${hotelId}`,
    resolveCtripCookieApiRequestHotelId: hotelId => `synthetic-platform-${hotelId}`,
    request: (url, options) => {
      const row = Object.assign(deferred(), { url, body: JSON.parse(options.body) });
      requests.push(row);
      return row.promise;
    },
    showToast: (...args) => notices.push(args),
    loadLatestCtripData: (...args) => record('latest', args),
    refreshOnlineHistory: (...args) => record('history', args),
    isDataHealthPanelVisible: () => true,
    loadDataHealthPanel: (...args) => record('health', args),
  };
  vm.runInNewContext(cookieSource, sandbox, { filename: 'production-ctrip-static.js' });
  sandbox.runCtripCookieApiCaptureFlow = sandbox.window.SUXI_CTRIP_STATIC.runCtripCookieApiCaptureFlow;
  vm.runInNewContext(`
    let ctripPlatformHotelContextEpoch = 0, meituanPlatformHotelContextEpoch = 0;
    let ctripCookieApiCaptureRequestSeq = 0, ctripOverviewFetchActionRequestSeq = 0;
    ${production}
    globalThis.api = { runCtripCookieApiCapture, runCtripOverviewFetchActionInternal, clearPostFetchRefreshTimers,
      schedulePostFetchRefresh, scheduleLatestCtripRefresh,
      scheduleOnlineHistoryRefresh, scheduleDataHealthPanelRefresh,
      invalidate: () => { invalidatePlatformHotelRequestContext('ctrip'); ctripCookieApiCaptureRequestSeq += 1; ctripOverviewFetchActionRequestSeq += 1; }
    };
  `, sandbox, { filename: 'production-main-cookie-refresh.js' });
  const fireTimers = () => {
    const scheduled = [...timers.entries()].sort((a, b) => a[1].delay - b[1].delay);
    for (const [id, timer] of scheduled) {
      if (!timers.has(id)) continue;
      timers.delete(id);
      timer.callback();
    }
  };
  const runUiTasks = async () => {
    const pending = uiTasks.splice(0);
    for (const task of pending) await task.callback();
  };
  const switchScope = kind => {
    sandbox.api.invalidate();
    if (kind === 'auth') {
      identity.session += 1;
      // Real resetHotelScopedClientState clears pending post-fetch timers, but an
      // already deferred UI callback is no longer in that timer map.
      sandbox.api.clearPostFetchRefreshTimers();
    } else {
      selectedCtripHotelId.value = '902';
      if (kind === 'hotel-A-B-A') {
        sandbox.api.invalidate();
        selectedCtripHotelId.value = '901';
      }
    }
    ctripCookieApiRunning.value = fetchingData.value = false;
  };
  const finish = async (pending, requestIndex = requests.length - 1) => {
    const request = requests[requestIndex];
    request.resolve({ code: 200, data: { is_ready: true, saved_count: 1,
      rows: [{ system_hotel_id: request.body.system_hotel_id, pv: 0, uv: 0 }] } });
    assert.equal((await pending).status, 'success');
  };
  return { api: sandbox.api, timers, uiTasks, refreshes, requests, notices, identity,
    ctripCookieApiRunning, fetchingData, ctripBrowserCaptureResult, onlineDataResult,
    fireTimers, runUiTasks, switchScope, finish };
}

const expectedRefreshes = (hotel = '901', session = 1) => [
  { name: 'history', hotel, session, args: [{ refreshHotels: false }] },
  { name: 'latest', hotel, session, args: [{ silent: true }] },
  { name: 'health', hotel, session, args: ['light', { force: true }] },
];

test('actual Cookie Flow and main timer adapters preserve all three current refreshes and parameters', async () => {
  const h = harness();
  await h.finish(h.api.runCtripCookieApiCapture());
  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].url, '/online-data/fetch-ctrip-cookie-api');
  assert.equal(String(h.requests[0].body.system_hotel_id), '901');
  assert.deepEqual([...h.timers.values()].map(row => row.delay).sort((a, b) => a - b), [340, 420, 560]);
  assert.deepEqual(h.refreshes, []);
  h.fireTimers();
  assert.equal(h.uiTasks.length, 3);
  assert.deepEqual(h.refreshes, []);
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
  assert.equal(h.ctripCookieApiRunning.value, false);
  assert.equal(h.fetchingData.value, false);
});

for (const scope of ['hotel', 'auth', 'hotel-A-B-A']) {
  for (const stage of ['timer', 'defer']) {
    test(`completed Cookie capture does not refresh after ${scope} changes during ${stage} wait`, async () => {
      const h = harness();
      await h.finish(h.api.runCtripCookieApiCapture());
      if (stage === 'defer') {
        h.fireTimers();
        assert.equal(h.uiTasks.length, 3);
      }
      h.switchScope(scope);
      h.fireTimers();
      await h.runUiTasks();
      assert.deepEqual(h.refreshes, [], 'old capture must not start new-scope reads');
    });
  }
}

test('same-key refresh replacement retains only the latest current capture callbacks', async () => {
  const h = harness();
  await h.finish(h.api.runCtripCookieApiCapture());
  const oldTimers = [...h.timers.keys()];
  await h.finish(h.api.runCtripCookieApiCapture());
  assert.equal(h.timers.size, 3);
  assert.ok(oldTimers.every(id => !h.timers.has(id)), 'same-key timers must be replaced');
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});

test('deferred callbacks of an older capture cannot read or alter a newer capture still pending', async () => {
  const h = harness();
  await h.finish(h.api.runCtripCookieApiCapture());
  h.fireTimers();
  const newer = h.api.runCtripCookieApiCapture();
  assert.equal(h.requests.length, 2);
  assert.equal(h.ctripCookieApiRunning.value, true);
  assert.equal(h.ctripBrowserCaptureResult.value, null);
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, [], 'old deferred reads must stop when capture ownership changes');
  assert.equal(h.ctripCookieApiRunning.value, true);
  assert.equal(h.fetchingData.value, true);
  assert.equal(h.ctripBrowserCaptureResult.value, null);
  await h.finish(newer);
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});

test('unowned scheduler calls retain their existing default behavior and clear semantics', async () => {
  const h = harness(), calls = [];
  h.api.schedulePostFetchRefresh('ordinary', () => calls.push('ordinary'), 10);
  h.api.schedulePostFetchRefresh('replace', () => calls.push('old'), 10);
  h.api.schedulePostFetchRefresh('replace', () => calls.push('new'), 10);
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(calls, ['ordinary', 'new']);
  h.api.schedulePostFetchRefresh('clear', () => calls.push('cleared'), 10);
  h.api.clearPostFetchRefreshTimers();
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(calls, ['ordinary', 'new']);
});

for (const scope of ['hotel', 'auth']) {
  for (const stage of ['timer', 'defer']) {
    test(`actual overview action final health refresh respects ${scope} change during ${stage} wait`, async () => {
      const h = harness();
      assert.equal((await h.api.runCtripOverviewFetchActionInternal('ctrip-quality')).status, 'success');
      assert.deepEqual([...h.timers.values()].map(row => row.delay), [560]);
      if (stage === 'defer') {
        h.fireTimers();
        assert.equal(h.uiTasks.length, 1);
      }
      h.switchScope(scope);
      h.fireTimers();
      await h.runUiTasks();
      assert.deepEqual(h.refreshes, [], 'overview finally must retain ownership through actual health refresh');
    });
  }
}

test('actual overview action retains its current final health refresh and refreshAfter opt-out', async () => {
  const h = harness();
  assert.equal((await h.api.runCtripOverviewFetchActionInternal('ctrip-quality')).status, 'success');
  h.fireTimers();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, [expectedRefreshes()[2]]);
  assert.equal((await h.api.runCtripOverviewFetchActionInternal('ctrip-quality', { refreshAfter: false })).status, 'success');
  assert.equal(h.timers.size, 0);
});
