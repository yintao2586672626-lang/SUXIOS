import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const mainSource = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const staticSource = fs.readFileSync(new URL('../../public/meituan-static.js', import.meta.url), 'utf8');
const flowNames = ['fetchMeituanTrafficData', 'fetchMeituanOrdersData', 'importMeituanOrderCsvData', 'fetchMeituanAdsData'];

function declaration(name, terminator = '};') {
  const start = mainSource.indexOf(`            const ${name} =`);
  assert.ok(start >= 0, `${name} must be a production binding`);
  const endPattern = new RegExp(`\\r?\\n            ${terminator.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`);
  const match = endPattern.exec(mainSource.slice(start));
  assert.ok(match, `${name} must have an end marker`);
  return mainSource.slice(start, start + match.index + match[0].length);
}

function createHarness() {
  const requests = [];
  const notifications = [];
  const state = { epoch: 1, hotelEpoch: 1, refreshes: 0 };
  const form = () => ({
    url: 'https://fixture.invalid/metrics', method: 'GET', partnerId: 'fixture-partner', poiId: 'fixture-poi', shopId: 'fixture-shop',
    startDate: '2026-09-01', endDate: '2026-09-01',
    csvText: 'orderNo,roomType,checkIn,checkOut,buyTime,bottomPrice\nSYNTHETIC-ORDER,Fixture room,2026-09-01,2026-09-02,2026-09-01,100',
  });
  const context = {
    window: {}, console, URLSearchParams,
    meituanForm: { value: { hotelId: 901 } },
    meituanTrafficForm: { value: form() }, meituanOrderForm: { value: form() }, meituanAdsForm: { value: form() },
    selectedMeituanHotelConfig: { value: { id: 'fixture-config' } },
    onlineDataTab: { value: 'data' }, fetchingData: { value: false },
    meituanOrderResult: { value: null }, meituanAdsResult: { value: null }, latestTrafficData: { value: [] }, onlineDataResult: { value: null },
    isMeituanExecutionConfigReady: () => true,
    resolveMeituanExecutionConfigId: () => 'fixture-config',
    getHotelNameById: id => `Fixture hotel ${id}`,
    showToast: (...args) => notifications.push(args),
    scheduleOnlineHistoryRefresh: () => { state.refreshes += 1; },
    scheduleOnlineDataRefresh: () => { state.refreshes += 1; },
    captureAuthSession: () => ({ epoch: state.epoch }),
    isAuthSessionCurrent: session => session.epoch === state.epoch,
    capturePlatformHotelRequestContext: () => ({ hotelId: context.meituanForm.value.hotelId, epoch: state.hotelEpoch }),
    isPlatformHotelRequestContextCurrent: captured => captured.hotelId === context.meituanForm.value.hotelId && captured.epoch === state.hotelEpoch,
    request: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  };
  vm.createContext(context);
  vm.runInContext(staticSource, context, { filename: 'meituan-static.js' });
  for (const name of ['runMeituanTrafficFetchFlow', 'runMeituanOrderFetchFlow', 'runMeituanOrderCsvImportFlow', 'runMeituanAdsFetchFlow']) {
    context[name] = context.window.SUXI_MEITUAN_STATIC[name];
  }
  vm.runInContext(flowNames.map(name => declaration(name, '});')).join('\n')
    + `\nglobalThis.flows = {${flowNames.join(',')}};`, context, { filename: 'app-main.js:meituan-manual-bindings' });
  const switchScope = (kind) => {
    if (kind === 'account') state.epoch += 1;
    else {
      context.meituanForm.value.hotelId = kind === 'hotel-roundtrip' ? 901 : 902;
      state.hotelEpoch += 1;
    }
    // The app's auth/hotel reset owns releasing old loading state. Old completions must not touch it.
    context.fetchingData.value = false;
    context.meituanOrderResult.value = null;
    context.meituanAdsResult.value = null;
    context.latestTrafficData.value = [];
    context.onlineDataResult.value = null;
  };
  return { context, requests, notifications, state, switchScope };
}

const response = (marker = 'SYNTHETIC-CURRENT') => ({ code: 200, data: {
  system_hotel_id: 901, rows: [{ marker }], data: [{ marker }], saved_count: 1, persisted: true, readback_verified: true,
} });

for (const name of flowNames) {
  test(`${name} discards stale success, failure, and exceptions after hotel/account changes`, async () => {
    for (const scope of ['hotel', 'hotel-roundtrip', 'account']) {
      for (const outcome of ['success', 'failed', 'exception']) {
        const h = createHarness();
        const pending = h.context.flows[name]();
        assert.equal(h.requests.length, 1, `${name} reaches the fixture request`);
        h.switchScope(scope);
        // A newer scope may already have a pending action. Old finally must not unlock it.
        h.context.fetchingData.value = true;
        if (outcome === 'exception') h.requests[0].reject(new Error('Synthetic obsolete request error'));
        else h.requests[0].resolve(outcome === 'success' ? response('SYNTHETIC-OBSOLETE') : { code: 500, message: 'Synthetic obsolete failure' });
        const result = await pending;
        assert.equal(result.status, 'stale', `${scope}/${outcome}`);
        assert.equal(h.context.onlineDataResult.value, null);
        assert.equal(h.context.meituanOrderResult.value, null);
        assert.equal(h.context.meituanAdsResult.value, null);
        assert.equal(h.context.latestTrafficData.value.length, 0);
        assert.equal(h.context.fetchingData.value, true);
        assert.equal(h.notifications.length, 0);
        assert.equal(h.state.refreshes, 0);
      }
    }
  });

  test(`${name} retains current-scope success and releases loading on current failure`, async () => {
    const h = createHarness();
    const pending = h.context.flows[name]();
    assert.equal(h.context.fetchingData.value, true);
    h.requests[0].resolve(response());
    assert.equal((await pending).status, 'success');
    assert.ok(h.context.onlineDataResult.value);
    assert.equal(h.context.fetchingData.value, false);
    assert.equal(h.notifications.length, 1);
    assert.ok(h.state.refreshes > 0);

    const retry = h.context.flows[name]();
    h.requests[1].reject(new Error('Synthetic current request failure'));
    assert.equal((await retry).status, 'exception');
    assert.equal(h.context.fetchingData.value, false);
    assert.equal(h.notifications.length, 2);
  });
}

test('background accepted replies cannot repopulate a changed scope', async () => {
  for (const name of flowNames.filter(name => name !== 'importMeituanOrderCsvData')) {
    const h = createHarness();
    const pending = h.context.flows[name]();
    h.switchScope('hotel');
    h.requests[0].resolve({ code: 200, data: { status: 'running', task_id: 'synthetic-task', async: true } });
    assert.equal((await pending).status, 'stale', name);
    assert.equal(h.context.onlineDataResult.value, null);
    assert.equal(h.context.fetchingData.value, false);
    assert.equal(h.notifications.length, 0);
  }
});

test('the new hotel request keeps loading until its own response completes', async () => {
  for (const name of flowNames) {
    const h = createHarness();
    const obsolete = h.context.flows[name]();
    h.switchScope('hotel');
    const current = h.context.flows[name]();
    h.requests[0].resolve(response('SYNTHETIC-OBSOLETE'));
    assert.equal((await obsolete).status, 'stale', name);
    assert.equal(h.context.fetchingData.value, true);
    assert.equal(h.context.onlineDataResult.value, null);
    h.requests[1].resolve(response('SYNTHETIC-CURRENT'));
    assert.equal((await current).status, 'success', name);
    assert.equal(h.context.fetchingData.value, false);
    assert.doesNotMatch(JSON.stringify(h.context.onlineDataResult.value), /SYNTHETIC-OBSOLETE/);
  }
});

test('auth and hotel resets release old manual-loading ownership', () => {
  for (const name of ['resetHotelScopedClientState', 'clearMeituanPlatformHotelScopedState']) {
    assert.equal(/fetchingData\.value = false;/.test(declaration(name)), true, `${name} must release loading`);
  }
});
