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
    window: {}, console, URLSearchParams, pageRequestGeneration: 1,
    meituanManualFetchRequestSeq: 0, meituanManualFetchActive: false,
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
  const contextHelpers = ['invalidateMeituanManualFetch', 'captureMeituanManualRequestContext', 'isMeituanManualRequestContextCurrent']
    .filter(name => mainSource.includes(`const ${name} =`));
  vm.runInContext(contextHelpers.map(name => declaration(name)).join('\n'), context);
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

const formForFlow = (h, name) => name === 'fetchMeituanTrafficData' ? h.context.meituanTrafficForm
  : name === 'fetchMeituanAdsData' ? h.context.meituanAdsForm : h.context.meituanOrderForm;

for (const name of flowNames) {
  for (const field of ['startDate', 'endDate']) {
    test(`${name} rejects obsolete replies after editing ${field} and releases its own loading`, async () => {
      for (const outcome of ['success', 'failed', 'exception', 'accepted']) {
        if (outcome === 'accepted' && name === 'importMeituanOrderCsvData') continue;
        const h = createHarness();
        const pending = h.context.flows[name]();
        formForFlow(h, name).value[field] = '2026-09-02';
        if (outcome === 'exception') h.requests[0].reject(new Error('Synthetic obsolete date error'));
        else h.requests[0].resolve(outcome === 'success' ? response('SYNTHETIC-OLD-DATE')
          : outcome === 'accepted' ? { code: 200, data: { status: 'running', async: true, task_id: 'synthetic-old-date' } }
            : { code: 500, message: 'Synthetic obsolete date failure' });
        assert.equal((await pending).status, 'stale', outcome);
        assert.equal(h.context.onlineDataResult.value, null);
        assert.equal(h.context.meituanOrderResult.value, null);
        assert.equal(h.context.meituanAdsResult.value, null);
        assert.equal(h.context.latestTrafficData.value.length, 0);
        assert.equal(h.context.fetchingData.value, false, 'date edit does not leave the old spinner stuck');
        assert.equal(h.notifications.length, 0);
        assert.equal(h.state.refreshes, 0);
      }
    });
  }

  test(`${name} old date completion cannot overwrite a newer result`, async () => {
    const h = createHarness();
    const obsolete = h.context.flows[name]();
    formForFlow(h, name).value = { ...formForFlow(h, name).value,
      startDate: '2026-09-02', endDate: '2026-09-02' };
    const current = h.context.flows[name]();
    h.requests[1].resolve(response('SYNTHETIC-NEW-DATE'));
    assert.equal((await current).status, 'success');
    const before = JSON.stringify(h.context.onlineDataResult.value);
    const notifications = h.notifications.length;
    const refreshes = h.state.refreshes;
    h.requests[0].resolve(response('SYNTHETIC-OLD-DATE'));
    const oldResult = await obsolete;
    assert.equal(JSON.stringify(h.context.onlineDataResult.value), before, 'old date cannot replace the current date result');
    assert.equal(oldResult.status, 'stale');
    assert.equal(h.notifications.length, notifications);
    assert.equal(h.state.refreshes, refreshes);
  });

  test(`${name} previous completion cannot release the newer request loading even in the same date range`, async () => {
    const h = createHarness();
    const obsolete = h.context.flows[name]();
    const current = h.context.flows[name]();
    h.requests[0].resolve(response('SYNTHETIC-SUPERSEDED'));
    assert.equal((await obsolete).status, 'stale');
    assert.equal(h.context.fetchingData.value, true);
    assert.equal(h.context.onlineDataResult.value, null);
    h.requests[1].resolve(response('SYNTHETIC-CURRENT'));
    assert.equal((await current).status, 'success');
    assert.equal(h.context.fetchingData.value, false);
  });

  test(`${name} preserves current observed zero and unknown metrics`, async () => {
    const h = createHarness();
    const pending = h.context.flows[name]();
    const saved = response();
    saved.data.rows[0].amount = saved.data.data[0].amount = 0;
    saved.data.rows[0].quantity = saved.data.data[0].quantity = null;
    h.requests[0].resolve(saved);
    assert.equal((await pending).status, 'success');
    const result = h.context.onlineDataResult.value;
    const row = Array.isArray(result) ? result[0] : result.rows[0];
    assert.equal(row.amount, 0);
    assert.equal(row.quantity, null);
  });

  test(`${name} ignores completion after leaving and returning to the platform page`, async () => {
    const h = createHarness();
    const pending = h.context.flows[name]();
    if (mainSource.includes('const invalidateMeituanManualFetch =')) {
      vm.runInContext('invalidateMeituanManualFetch()', h.context);
      assert.equal(h.context.fetchingData.value, false, 'page leave releases the old manual owner immediately');
    }
    h.context.pageRequestGeneration += 2;
    h.context.fetchingData.value = true; // The new page lifecycle owns loading.
    h.requests[0].resolve(response('SYNTHETIC-OLD-PAGE'));
    assert.equal((await pending).status, 'stale');
    assert.equal(h.context.onlineDataResult.value, null);
    assert.equal(h.context.fetchingData.value, true);
    assert.equal(h.notifications.length, 0);
    assert.equal(h.state.refreshes, 0);
  });
}

test('manual page invalidation keeps unrelated loading and is wired to actual page/account/hotel resets', () => {
  assert.ok(mainSource.includes('const invalidateMeituanManualFetch ='), 'manual loading must be invalidated on page change');
  const h = createHarness();
  h.context.fetchingData.value = true;
  vm.runInContext('invalidateMeituanManualFetch()', h.context);
  assert.equal(h.context.fetchingData.value, true, 'no active manual fetch means nothing to release');
  const pageWatcher = mainSource.slice(mainSource.indexOf('watch(currentPage, (newPage) => {'),
    mainSource.indexOf('pageRequestGeneration += 1;'));
  assert.match(pageWatcher, /if \(previousPage !== newPage\) \{\s*invalidateMeituanManualFetch\(\);/);
  for (const name of ['resetHotelScopedClientState', 'clearMeituanPlatformHotelScopedState']) {
    assert.match(declaration(name), /invalidateMeituanManualFetch\(\);/);
  }
});
