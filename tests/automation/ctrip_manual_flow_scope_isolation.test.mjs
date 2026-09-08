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
  const form = () => ({ nodeId: '24588', platform: 'Ctrip', dateRange: 'custom', startDate: '2026-09-01', endDate: '2026-09-01' });
  const context = {
    window: { setTimeout }, console, URLSearchParams,
    fetchingData: { value: false }, ctripRankingHistoryLoading: { value: false },
    ctripManualFetchRequestSeq: 0, ctripManualFetchActive: false,
    ctripRankingHistoryRequestSeq: 0, ctripRankingHistoryRange: { value: '' }, ctripRankingHistoryMessage: { value: '' },
    ctripRankingStoredDate: { value: '' },
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
    invalidatePlatformHotelRequestContext: () => { state.hotelEpoch++; },
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

function installRealDisplayClear(h) {
  for (const name of ['ctripHotelsList', 'topTenHotels', 'ctripTrafficRows', 'ctripTrafficSummary',
    'ctripTrafficAnalysis', 'ctripRealtimeTrafficRecord', 'latestTrafficData', 'ctripCommentResult',
    'ctripCommentBrowserCaptureResult', 'ctripBrowserCaptureResult', 'ctripOverviewResult',
    'ctripFlowOverviewResult', 'ctripAdsBrowserCaptureResult', 'ctripSearchOpportunityPayload',
    'ctripSearchOpportunityError', 'ctripSearchOpportunityLoading', 'ctripSearchOpportunitySaving',
    'ctripCommentBrowserCaptureRunning', 'ctripDiagnosisSnapshotLoading', 'ctripRankingDisplayActivated',
    'ctripLatestComparison']) h.context[name] ||= { value: null };
  Object.assign(h.context, {
    ctripCommentBrowserCaptureRequestSeq: 0, ctripDiagnosisSnapshotRequestSeq: 0,
    ctripSearchOpportunityRequestSeq: 0,
    ctripReviewMatchControllerBindings: { invalidateCtripReviewMatch() {},
      ctripReviewMatchResult: { value: null }, ctripReviewMatchLoading: { value: '' },
      ctripReviewMatchLookupLoadingCommentId: { value: '' } },
  });
  return vm.runInContext(declaration('clearCtripOverviewDisplayState') + '\nclearCtripOverviewDisplayState', h.context);
}

function installRealRecovery(h) {
  const actualHelper = h.context.window.SUXI_CTRIP_STATIC;
  Object.assign(h.context, {
    token: { value: '' }, currentPage: { value: 'ctrip-ebooking' },
    ctripLatestRequestSeq: 0, ctripLatestRequestPromises: new Map(), ctripLatestLoading: { value: false },
    ctripLatestComparison: { value: null }, dualOtaSelectedRange: { value: '' }, filterReportHotel: { value: '' },
    isCompassDataPage: () => false,
    getSelectedCtripHotelId: () => String(h.context.selectedCtripHotelId.value),
    buildCtripFetchDateRange: actualHelper.buildCtripFetchDateRange,
    buildLatestCtripSnapshotModel: actualHelper.buildLatestCtripSnapshotModel,
    isCtripLatestRequestCurrent: actualHelper.isCtripLatestRequestCurrent,
  });
  vm.runInContext(['applyLatestCtripSnapshot', 'resolveCtripLatestRequestRange',
    'shouldHydrateLatestCtripDisplay', 'loadLatestCtripData', 'handleCtripFetchFailure'].map(declaration).join('\n'), h.context);
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

test('date changes during asynchronous failure recovery suppress the old query error', async () => {
  for (const name of flows) {
    for (const outcome of ['failure', 'exception']) {
      const h = harness();
      let finishRecovery;
      h.context.loadLatestCtripData = () => new Promise(resolve => { finishRecovery = resolve; });
      vm.runInContext(declaration('handleCtripFetchFailure'), h.context);
      const pending = h.context.flows[name]();
      if (outcome === 'exception') h.requests[0].reject(new Error('Synthetic query error'));
      else h.requests[0].resolve({ code: 500, message: 'Synthetic query error' });
      for (let step = 0; step < 5 && !finishRecovery; step++) await Promise.resolve();
      assert.equal(typeof finishRecovery, 'function', 'production failure recovery is waiting');
      h.switchScope('date');
      finishRecovery();
      assert.equal((await pending).status, 'stale');
      assert.equal(h.notifications.length, 0, 'no obsolete error toast after the query changed');
      assert.equal(h.context.onlineDataResult.value, null);
      assert.equal(h.context.fetchingData.value, false);
    }
  }
});

test('the real hotel display clear releases a pending manual request so the new hotel can fetch', async () => {
  for (const name of flows) {
    for (const preparing of [false, true]) {
      const h = harness({ preparing });
      const clear = installRealDisplayClear(h);
      const previous = h.context.flows[name]();
      assert.equal(h.context.fetchingData.value, true);
      const newConfig = { ...h.context.getActiveCtripConfig(), hotel_id: 902, system_hotel_id: 902 };
      h.context.selectedCtripHotelId.value = 902;
      h.context.getActiveCtripConfig = h.context.ctripManualFetchConfigCandidate = () => newConfig;
      clear();
      assert.equal(h.context.fetchingData.value, false, 'the real invalidation releases the old loading slot');
      const current = h.context.flows[name]();
      assert.equal(h.requests.length, 2, 'the newly selected hotel really starts an HTTP request');
      assert.equal(String(JSON.parse(h.requests[1].options.body).system_hotel_id), '902');
      h.requests[0].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-old' } });
      assert.equal((await previous).status, 'stale');
      assert.equal(h.context.fetchingData.value, true, 'old completion cannot clear the genuinely newer request');
      assert.equal(h.context.onlineDataResult.value, null);
      h.requests[1].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-current' } });
      assert.equal((await current).status, 'accepted');
      assert.equal(h.context.fetchingData.value, false);
      assert.equal(h.context.ctripManualFetchActive, false);
    }
  }
});

test('display invalidation does not release loading when no Ctrip manual request owns it', () => {
  const h = harness();
  const clear = installRealDisplayClear(h);
  h.context.fetchingData.value = true;
  clear();
  assert.equal(h.context.fetchingData.value, true);
});

test('traffic platform changes discard every old response and allow a subsequent request', async () => {
  for (const outcome of ['success', 'failure', 'exception']) {
    const h = harness();
    const previous = h.context.flows.fetchCtripTrafficData();
    assert.equal(JSON.parse(h.requests[0].options.body).platform, 'Ctrip');
    h.context.ctripTrafficForm.value.platform = 'Qunar';
    if (outcome === 'exception') h.requests[0].reject(new Error('Synthetic old platform failure'));
    else h.requests[0].resolve(outcome === 'success'
      ? { code: 200, data: { status: 'accepted', task_id: 'synthetic-old' } }
      : { code: 500, message: 'Synthetic old platform failure' });
    assert.equal((await previous).status, 'stale');
    assert.equal(h.context.onlineDataResult.value, null);
    assert.equal(h.notifications.length, 0);
    assert.equal(h.effects.length, 0);
    assert.equal(h.context.fetchingData.value, false);
    const current = h.context.flows.fetchCtripTrafficData();
    assert.equal(JSON.parse(h.requests[1].options.body).platform, 'Qunar');
    h.requests[1].resolve({ code: 200, data: { status: 'accepted', task_id: 'synthetic-current' } });
    assert.equal((await current).status, 'accepted');
  }
});

test('real latest-snapshot recovery writes only while the complete query is still current', async () => {
  for (const name of flows) {
    for (const kind of ['unchanged', 'date', ...(name === 'fetchCtripTrafficData' ? ['platform'] : [])]) {
      const h = harness();
      installRealRecovery(h);
      const pending = h.context.flows[name]();
      h.requests[0].resolve({ code: 500, message: 'Synthetic query error' });
      for (let step = 0; step < 5 && h.requests.length < 2; step++) await Promise.resolve();
      assert.equal(h.requests.length, 2, 'production recovery issues its real latest read');
      assert.match(h.requests[1].url, /^\/online-data\/ctrip\/latest\?/);
      if (kind === 'date') h.context.ctripForm.value.startDate = h.context.ctripTrafficForm.value.startDate = '2026-09-02';
      if (kind === 'platform') h.context.ctripTrafficForm.value.platform = 'Qunar';
      const selectedResult = { source: 'synthetic-selected-query' };
      const selectedMeta = { status: 'synthetic-selected-query' };
      h.context.onlineDataResult.value = selectedResult;
      h.context.ctripLatestMeta.value = selectedMeta;
      h.requests[1].resolve({ code: 200, data: { metadata: { hotel_id: '901', status: 'success' },
        traffic: { rows: [{ date: '2026-09-01', visitor_count: 12 }] } } });
      const result = await pending;
      if (kind === 'unchanged') {
        assert.equal(result.status, 'failed');
        assert.equal(h.context.onlineDataResult.value.source, 'latest', 'positive control exercises the real write');
        assert.equal(h.context.ctripLatestMeta.value.status, 'success');
        assert.equal(h.notifications.length, 1);
      } else {
        assert.equal(result.status, 'stale');
        assert.equal(h.context.onlineDataResult.value, selectedResult, 'stale recovery cannot replace the selected result');
        assert.equal(h.context.ctripLatestMeta.value, selectedMeta, 'stale recovery cannot replace selected metadata');
        assert.equal(h.notifications.length, 0);
      }
      assert.equal(h.context.fetchingData.value, false);
      assert.equal(h.context.ctripLatestLoading.value, false);
    }
  }
});
