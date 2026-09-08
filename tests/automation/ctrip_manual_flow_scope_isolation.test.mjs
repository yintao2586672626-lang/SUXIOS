import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const helper = fs.readFileSync(new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const flows = ['fetchCtripData', 'fetchCtripTrafficData'];
function declaration(name) {
  const start = main.indexOf(`            const ${name} =`);
  const end = /\r?\n            };/.exec(main.slice(start));
  assert.ok(start >= 0 && end, `production binding ${name}`);
  return main.slice(start, start + end.index + end[0].length);
}

function harness({ preparing = false } = {}) {
  const requests = [], effects = [], notifications = [];
  const state = { epoch: 1, hotelEpoch: 1 };
  const config = { id: 'ctrip-901', config_id: 'ctrip-901', hotel_id: 901, system_hotel_id: 901,
    node_id: '24588', credential_status: 'ready', has_cookies: true, configuration_verified: true };
  const form = () => ({ nodeId: '24588', dateRange: 'custom', startDate: '2026-09-01', endDate: '2026-09-01' });
  const context = {
    window: { setTimeout }, console, URLSearchParams,
    fetchingData: { value: false }, ctripRankingHistoryLoading: { value: false },
    ctripManualFetchRequestSeq: 0,
    isLoggedIn: { value: true }, selectedCtripHotelId: { value: 901 },
    ctripForm: { value: form() }, ctripTrafficForm: { value: form() },
    ctripFetchSuccess: { value: false }, ctripSavedCount: { value: 0 }, showRawData: { value: false },
    onlineDataResult: { value: null }, ctripTrafficHistoryResult: { value: null },
    ctripLatestMeta: { value: null }, ctripTableTab: { value: '' },
    onlineDataFilter: { value: {} }, onlineDataTab: { value: 'data' },
    ctripManualFetchConfigProofPending: () => preparing,
    ctripManualFetchConfigCandidate: () => config, getActiveCtripConfig: () => config,
    applyCtripConfigObject: () => {}, clearCtripRankingDisplayState: () => {},
    captureAuthSession: () => ({ epoch: state.epoch }),
    isAuthSessionCurrent: session => session.epoch === state.epoch,
    capturePlatformHotelRequestContext: () => ({ hotelId: context.selectedCtripHotelId.value, epoch: state.hotelEpoch }),
    isPlatformHotelRequestContextCurrent: captured => captured.hotelId === context.selectedCtripHotelId.value && captured.epoch === state.hotelEpoch,
    debugLog: () => {}, showToast: (...args) => notifications.push(args),
    useCtripDisplayHotels: rows => { effects.push('display'); return rows; },
    useCtripTrafficDisplayRows: rows => { effects.push('traffic'); return rows; },
    updateAiAnalysisHotelList: () => effects.push('analysis'),
    scheduleOnlineHistoryRefresh: () => effects.push('history'),
    scheduleLatestCtripRefresh: () => effects.push('latest'),
    scheduleOnlineDataRefresh: () => effects.push('data'),
    handleCtripFetchFailure: async () => effects.push('failure'), hasVisibleCtripSnapshot: () => false,
    manualOneClickFetchQunarAutoRetryAllowedAt: () => false,
    manualOneClickFetchQunarVisitorNeedsRetry: () => false, CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT: 2,
    request: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  };
  vm.createContext(context);
  vm.runInContext(helper, context);
  context.runCtripFetchDataFlow = context.window.SUXI_CTRIP_STATIC.runCtripFetchDataFlow;
  context.runCtripTrafficFetchFlow = context.window.SUXI_CTRIP_STATIC.runCtripTrafficFetchFlow;
  vm.runInContext(flows.map(declaration).join('\n') + `\nglobalThis.flows = {${flows.join(',')}};`, context);
  function switchScope(kind) {
    if (kind === 'account') state.epoch++;
    else if (kind === 'date') {
      context.ctripForm.value.startDate = context.ctripTrafficForm.value.startDate = '2026-09-02';
    } else {
      context.selectedCtripHotelId.value = kind === 'hotel-roundtrip' ? 901 : 902;
      state.hotelEpoch++;
    }
    context.onlineDataResult.value = null;
    context.fetchingData.value = true; // A new scope owns this loading state.
  }
  return { context, requests, effects, notifications, switchScope };
}

for (const name of flows) {
  for (const kind of ['hotel', 'hotel-roundtrip', 'account', 'date']) {
    test(`${name} discards old completion and preserves new loading after ${kind} change`, async () => {
      for (const preparing of [false, true]) {
        for (const outcome of ['success', 'failure', 'exception']) {
          const h = harness({ preparing });
          const pending = h.context.flows[name]();
          assert.equal(h.requests.length, 1, 'fixture request reached');
          h.switchScope(kind);
          if (outcome === 'exception') h.requests[0].reject(new Error('Synthetic old failure'));
          else h.requests[0].resolve(outcome === 'success'
            ? { code: 200, data: { status: 'accepted', task_id: 'synthetic-old' } }
            : { code: 500, message: 'Synthetic old failure' });
          assert.equal((await pending).status, 'stale');
          assert.equal(h.context.onlineDataResult.value, null);
          assert.equal(h.context.fetchingData.value, kind !== 'date', 'date edits release the same owner; changed hotel/account keeps the new owner');
          assert.equal(h.effects.length, 0);
          assert.equal(h.notifications.length, 0);
        }
      }
    });
  }
  test(`${name} accepts current numeric hotel identity and releases its loading`, async () => {
    const h = harness();
    const pending = h.context.flows[name]();
    h.requests[0].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-current' } });
    assert.equal((await pending).status, 'accepted');
    assert.equal(h.context.onlineDataResult.value.task_id, 'synthetic-current');
    assert.equal(h.context.fetchingData.value, false);
  });
}

test('ranking automatic retry cannot start a request for a changed hotel or date', async () => {
  for (const scope of ['hotel', 'date', 'account']) {
    const h = harness();
    const timers = [];
    h.context.window.setTimeout = callback => timers.push(callback);
    h.context.manualOneClickFetchQunarAutoRetryAllowedAt = () => true;
    h.context.manualOneClickFetchQunarVisitorNeedsRetry = quality => quality?.status === 'partial_qunar_visitor_gap';
    const pending = h.context.flows.fetchCtripData();
    h.requests[0].resolve({ code: 200, data: {
      display_hotels: [{ hotelId: 'synthetic' }], saved_count: 0, persisted: false,
      qunar_visitor_quality: { status: 'partial_qunar_visitor_gap' },
    } });
    for (let step = 0; step < 5 && timers.length === 0; step++) await Promise.resolve();
    assert.equal(timers.length, 1, 'the real retry loop reaches its delay');
    h.switchScope(scope);
    timers[0]();
    assert.equal((await pending).status, 'stale');
    assert.equal(h.requests.length, 1, 'no follow-up platform request after scope changed');
    assert.equal(h.context.fetchingData.value, scope !== 'date');
  }
});

test('a replaced same-hotel request cannot clear loading or deliver effects owned by a newer flow', async () => {
  for (const oldFlow of flows) {
    const h = harness({ preparing: true });
    const previous = h.context.flows[oldFlow]();
    h.context.fetchingData.value = false; // UI cancellation releases the slot before the old HTTP call settles.
    const nextFlow = flows.find(name => name !== oldFlow);
    const current = h.context.flows[nextFlow]();
    assert.equal(h.requests.length, 2);
    h.requests[0].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-old' } });
    assert.equal((await previous).status, 'stale');
    assert.equal(h.context.fetchingData.value, true);
    assert.equal(h.context.onlineDataResult.value, null);
    assert.equal(h.notifications.length, 0);
    assert.equal(h.effects.length, 0);
    h.requests[1].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-current' } });
    assert.equal((await current).status, 'accepted');
    assert.equal(h.context.onlineDataResult.value.task_id, 'synthetic-current');
    assert.equal(h.context.fetchingData.value, false);
  }
});

test('failure recovery cannot display an old error after a session change', async () => {
  const h = harness();
  let finishRecovery;
  h.context.loadLatestCtripData = () => new Promise(resolve => { finishRecovery = resolve; });
  const handleFailure = vm.runInContext(declaration('handleCtripFetchFailure') + '\nhandleCtripFetchFailure', h.context);
  let active = true;
  const pending = handleFailure('Synthetic stale error', () => active);
  active = false;
  finishRecovery();
  await pending;
  assert.equal(h.notifications.length, 0);
});
