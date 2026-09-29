import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const flushPromises = () => new Promise(resolve => setImmediate(resolve));

// Synthetic callbacks execute the production Flow; no network, storage, or credentials are used.
const sourcePath = process.env.SUXIOS_CTRIP_COOKIE_SOURCE
  || new URL('../../public/ctrip-static.js', import.meta.url);
const productionSource = fs.readFileSync(sourcePath, 'utf8');

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

function harness({ configPending = false, selected = '901', queueRefresh = false, configMissing = false } = {}) {
  const configWait = deferred();
  const requests = [], effects = [], refreshQueue = [];
  const state = { selected, session: 1, hotelEpoch: 1, seq: 0, running: false, fetching: false,
    capture: null, online: null, raw: true };
  const config = { config_id: 'synthetic-901', system_hotel_id: '901', has_cookies: true,
    credential_status: 'ready' };
  let source = productionSource;
  if (queueRefresh) {
    const start = source.indexOf('    const runPostFetchRefresh =');
    const end = source.indexOf('    const isCtripBackgroundAcceptedResponse =', start);
    assert.ok(start >= 0 && end > start, 'production refresh dependency must be available');
    // Substitute only the scheduler dependency to exercise delayed callback execution.
    source = source.slice(0, start)
      + '    const runPostFetchRefresh = globalThis.testOnlyScheduleRefresh;\n' + source.slice(end);
  }
  const sandbox = { window: {}, console, URL, setTimeout, clearTimeout,
    testOnlyScheduleRefresh: (callback, ...args) => refreshQueue.push(() => callback(...args)) };
  vm.runInNewContext(source, sandbox, { filename: String(sourcePath) });
  const options = {
    getSelectedCtripHotelId: () => state.selected,
    setSelectedCtripHotelId: value => { effects.push(['selected', value]); state.selected = value; state.hotelEpoch++; },
    getAutoFetchHotelId: () => '901',
    getUserHotelId: () => '901',
    hasCtripConfigList: () => !configPending,
    loadCtripConfigList: () => { effects.push(['load-config']); return configWait.promise; },
    getActiveCtripConfig: () => configMissing ? null : config,
    findCtripConfigByHotelId: () => configMissing ? null : config,
    applyCtripConfigObject: value => effects.push(['apply-config', value.config_id]),
    getForm: () => ({ requestSource: 'traffic_report' }),
    getOverviewForm: () => ({ dataDate: '2026-09-27' }),
    getHotelNameById: () => 'Synthetic hotel',
    resolveProfileId: () => 'synthetic-profile',
    resolveRequestHotelId: () => 'synthetic-platform-901',
    requestCapture: body => {
      const request = deferred();
      requests.push({ body, ...request });
      return request.promise;
    },
    setProfileId: value => effects.push(['profile', value]),
    isRunning: () => state.running,
    captureRequestContext: () => {
      const context = { seq: ++state.seq, session: state.session, hotel: state.selected, hotelEpoch: state.hotelEpoch };
      effects.push(['capture-context', context]);
      return context;
    },
    isRequestContextCurrent: context => context.seq === state.seq && context.session === state.session
      && context.hotel === state.selected && context.hotelEpoch === state.hotelEpoch,
    setRunning: value => { effects.push(['running', value]); state.running = value; },
    setFetching: value => { effects.push(['fetching', value]); state.fetching = value; },
    setCaptureResult: value => { effects.push(['capture', value]); state.capture = value; },
    setOnlineDataResult: value => { effects.push(['online', value]); state.online = value; },
    setShowRawData: value => { effects.push(['raw', value]); state.raw = value; },
    notify: (...args) => effects.push(['notice', ...args]),
    refreshLatestCtripData: (...args) => effects.push(['latest', ...args]),
    refreshOnlineHistory: (...args) => effects.push(['history', ...args]),
    shouldRefreshDataHealthPanel: () => true,
    refreshDataHealthPanel: (...args) => effects.push(['health', ...args]),
  };
  const run = overrides => sandbox.window.SUXI_CTRIP_STATIC.runCtripCookieApiCaptureFlow({ ...options, ...overrides });
  function switchScope(kind) {
    if (kind === 'session') state.session++;
    else {
      state.selected = kind === 'hotel-roundtrip' ? '901' : '902';
      state.hotelEpoch++;
    }
    state.seq++;
    state.running = state.fetching = true; // The new scope owns loading and display state.
    state.capture = { scope: 'new' };
    state.online = { scope: 'new' };
    state.raw = true;
    effects.length = 0;
  }
  return { state, effects, requests, configWait, refreshQueue, run, switchScope };
}

const zeroResponse = () => ({ code: 200, data: { is_ready: true, saved_count: 1,
  rows: [{ system_hotel_id: '901', platform: 'ctrip', data_date: '2026-09-27', pv: 0, uv: 0 }] } });

function assertNewScopeUntouched(h) {
  assert.deepEqual(h.effects, []);
  assert.deepEqual(h.state.capture, { scope: 'new' });
  assert.deepEqual(h.state.online, { scope: 'new' });
  assert.equal(h.state.running, true);
  assert.equal(h.state.fetching, true);
  assert.equal(h.state.raw, true);
}

for (const scope of ['session', 'hotel', 'hotel-roundtrip']) {
  for (const outcome of ['resolve', 'reject']) {
    test(`Cookie Flow ignores ${scope} stale config ${outcome} without starting POST`, async () => {
      const h = harness({ configPending: true });
      const pending = h.run();
      h.switchScope(scope);
      if (outcome === 'resolve') h.configWait.resolve();
      else h.configWait.reject(new Error('synthetic config failure'));
      // Let an unguarded baseline POST finish, so the regression reports a failure instead of hanging.
      await flushPromises();
      if (h.requests[0]) h.requests[0].resolve(zeroResponse());
      const result = await pending;
      assert.equal(result.status, 'stale');
      assert.equal(h.requests.length, 0);
      assertNewScopeUntouched(h);
    });
  }
  for (const outcome of ['success', 'error-response', 'exception']) {
    test(`Cookie Flow ignores ${scope} stale POST ${outcome} and retains the new owner's loading`, async () => {
      const h = harness();
      const pending = h.run();
      assert.equal(h.requests.length, 1);
      h.switchScope(scope);
      if (outcome === 'success') h.requests[0].resolve(zeroResponse());
      else if (outcome === 'error-response') h.requests[0].resolve({ code: 403, message: 'synthetic forbidden' });
      else h.requests[0].reject(new Error('synthetic capture failure'));
      const result = await pending;
      assert.equal(result.status, 'stale');
      assertNewScopeUntouched(h);
    });
  }
}

test('Cookie Flow marks config preparation busy before its first await and rejects a duplicate', async () => {
  const h = harness({ configPending: true });
  const first = h.run();
  assert.equal(h.state.running, true);
  assert.equal(h.state.fetching, true);
  const before = h.effects.slice();
  const second = await h.run();
  assert.equal(second.status, 'busy');
  assert.deepEqual(h.effects, before);
  h.configWait.resolve();
  await flushPromises();
  assert.equal(h.requests.length, 1);
  h.requests[0].resolve(zeroResponse());
  assert.equal((await first).status, 'success');
  assert.equal(h.state.running, false);
  assert.equal(h.state.fetching, false);
});

test('Cookie Flow rejects duplicate POST without invalidating the existing owner', async () => {
  const h = harness();
  const first = h.run();
  const before = h.effects.slice();
  const duplicate = h.run();
  for (const request of h.requests.slice(1)) request.resolve(zeroResponse());
  const second = await duplicate;
  assert.equal(second.status, 'busy');
  assert.deepEqual(h.effects, before);
  assert.equal(h.requests.length, 1);
  h.requests[0].resolve(zeroResponse());
  assert.equal((await first).status, 'success');
});

test('Cookie Flow chooses the fallback hotel synchronously before capturing the owner', async () => {
  const h = harness({ selected: '' });
  const pending = h.run();
  assert.equal(h.state.selected, '901');
  assert.ok(h.effects.findIndex(([name]) => name === 'selected')
    < h.effects.findIndex(([name]) => name === 'capture-context'));
  assert.equal(h.requests.length, 1);
  h.requests[0].resolve(zeroResponse());
  assert.equal((await pending).status, 'success');
});

test('Cookie Flow preserves current zero metrics, source identity, and successful refresh arguments', async () => {
  const h = harness();
  const pending = h.run();
  const response = zeroResponse();
  h.requests[0].resolve(response);
  const result = await pending;
  assert.equal(result.status, 'success');
  assert.equal(h.state.capture, response.data);
  assert.equal(h.state.online, response.data);
  assert.equal(h.state.online.rows[0].pv, 0);
  assert.deepEqual(JSON.parse(JSON.stringify(h.requests[0].body)), {
    config_id: 'synthetic-901', system_hotel_id: '901', ctrip_hotel_id: 'synthetic-platform-901',
    data_date: '2026-09-27', request_source: 'traffic_report', auto_save: true,
  });
  const refreshes = h.effects.filter(([name]) => ['latest', 'history', 'health'].includes(name));
  for (const refresh of refreshes) {
    assert.equal(typeof refresh.at(-1), 'function', 'queued refresh receives its original ownership predicate');
    assert.equal(refresh.at(-1)(), true);
  }
  assert.deepEqual(JSON.parse(JSON.stringify(refreshes.map(row => row.slice(0, -1)))),
    [['latest', { silent: true }], ['history'], ['health', 'light', { force: true }]]);
  assert.equal(h.state.running, false);
  assert.equal(h.state.fetching, false);
});

for (const outcome of ['error-response', 'exception', 'not-ready', 'incomplete']) {
  test(`Cookie Flow preserves current ${outcome} without reporting success`, async () => {
    const h = harness();
    const pending = h.run();
    if (outcome === 'exception') h.requests[0].reject(new Error('synthetic current failure'));
    else h.requests[0].resolve(outcome === 'error-response' ? { code: 403, message: 'synthetic forbidden' }
      : { code: 200, data: outcome === 'not-ready' ? { is_ready: false, warning: 'synthetic not ready' } : {} });
    const result = await pending;
    assert.equal(result.status, outcome.replace('-', '_'));
    assert.ok(h.effects.some(([name]) => name === 'notice'));
    assert.equal(h.state.running, false);
    assert.equal(h.state.fetching, false);
  });
}

test('Cookie Flow reports current config-load exceptions and releases busy state', async () => {
  const h = harness({ configPending: true });
  const pending = h.run();
  h.configWait.reject(new Error('synthetic current config failure'));
  assert.equal((await pending).status, 'exception');
  assert.equal(h.requests.length, 0);
  assert.ok(h.effects.some(([name, message]) => name === 'notice' && /current config failure/.test(message)));
  assert.equal(h.state.running, false);
  assert.equal(h.state.fetching, false);
});

test('Cookie Flow preserves missing-config state and releases busy state', async () => {
  const h = harness({ configMissing: true });
  assert.equal((await h.run()).status, 'missing_config');
  assert.equal(h.requests.length, 0);
  assert.ok(h.effects.some(([name, , level]) => name === 'notice' && level === 'warning'));
  assert.equal(h.state.running, false);
  assert.equal(h.state.fetching, false);
});

test('Cookie Flow remains compatible with callers omitting ownership and busy hooks', async () => {
  const h = harness();
  const pending = h.run({ captureRequestContext: undefined, isRequestContextCurrent: undefined, isRunning: undefined });
  h.requests[0].resolve(zeroResponse());
  assert.equal((await pending).status, 'success');
  assert.equal(h.state.running, false);
  assert.equal(h.state.fetching, false);
});

test('Cookie Flow checks ownership when queued refresh callbacks execute', async () => {
  const h = harness({ queueRefresh: true });
  const pending = h.run();
  h.requests[0].resolve(zeroResponse());
  assert.equal((await pending).status, 'success');
  assert.equal(h.refreshQueue.length, 3);
  h.switchScope('session');
  for (const callback of h.refreshQueue) await callback();
  assertNewScopeUntouched(h);
});

test('Cookie Flow runs queued refresh callbacks for the current owner', async () => {
  const h = harness({ queueRefresh: true });
  const pending = h.run();
  h.requests[0].resolve(zeroResponse());
  assert.equal((await pending).status, 'success');
  h.effects.length = 0;
  for (const callback of h.refreshQueue) await callback();
  assert.deepEqual(h.effects.map(([name]) => name), ['latest', 'history', 'health']);
});
