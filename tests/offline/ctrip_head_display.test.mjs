import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

// Source-only VM harness: no application, browser, login, HTTP, database,
// environment, credential reads or server-side persistence. All payloads synthetic.
// Targets main 703dd68 and extracts actual flow/display/clear/failure bindings,
// existing manual request/active and history counter declarations. Auth cleanup
// executes only the actual epoch
// increment/reset-call/overview-clear statements; the rest of login/watch and
// unrelated reset wiring is outside this harness. No session token is created
// or copied to metadata/logs. Synthetic history refs correspond to declarations
// already present in this main baseline; the old HEAD-only shape oracle is adapted.
const projectRoot = process.argv[2]
  || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const helperSource = readFileSync(path.join(projectRoot, 'public/ctrip-static.js'), 'utf8');
const mainSource = readFileSync(path.join(projectRoot, 'public/app-main.js'), 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));

function declaration(name) {
  const start = mainSource.indexOf(`            const ${name} =`);
  const end = /\r?\n            };/.exec(mainSource.slice(start));
  assert.ok(start >= 0 && end, `actual production declaration: ${name}`);
  return mainSource.slice(start, start + end.index + end[0].length);
}

function successResponse() {
  return { code: 200, data: {
    status: 'success', persistence_status: 'readback_verified', persisted: true,
    readback_verified: true, saved_count: 1, fetched_at: '2026-10-01 10:00:00',
    source_business_date: '2026-09-30', response_date_status: 'verified',
    data: { synthetic_valid_result: 'LAST_VALID' },
    display_hotels: [{ hotelId: 'SYNTHETIC_PLATFORM', name: 'Synthetic Last Valid', bookOrderNum: 7 }],
    display_summary: { cards: [{ label: 'synthetic', value: 7 }] },
  } };
}

function harness() {
  let nextResponse = successResponse();
  const scopeState = { hotelEpoch: 1 };
  const effects = [], notices = [], recovery = [];
  const config = { id: 'synthetic-ctrip', config_id: 'synthetic-ctrip', hotel_id: 901,
    system_hotel_id: 901, node_id: '24588', has_cookies: true, credential_status: 'ready' };
  const context = {
    window: { setTimeout: () => { throw new Error('Unexpected offline timer.'); } },
    console: { error: () => {} }, URLSearchParams,
    authSessionEpoch: 1,
    fetchingData: { value: false },
    ctripRankingHistoryLoading: { value: false }, ctripRankingHistoryRange: { value: '' },
    ctripRankingHistoryMessage: { value: '' }, ctripRankingStoredDate: { value: '' },
    isLoggedIn: { value: true }, selectedCtripHotelId: { value: 901 },
    ctripForm: { value: { nodeId: '24588', dateRange: 'custom', startDate: '2026-09-30', endDate: '2026-09-30' } },
    ctripFetchSuccess: { value: false }, ctripSavedCount: { value: 0 }, showRawData: { value: false },
    onlineDataResult: { value: null }, ctripLatestMeta: { value: null }, ctripTableTab: { value: '' },
    ctripHotelsList: { value: [] }, ctripBusinessSummary: { value: { cards: [] } },
    topTenHotels: { value: [] }, ctripTablePage: { value: 1 }, ctripRankingDisplayActivated: { value: false },
    ctripTrafficRows: { value: [] }, ctripCommentResult: { value: null },
    onlineDataFilter: { value: {} }, onlineDataTab: { value: 'data' },
    emptyCtripBusinessSummary: () => ({ cards: [] }),
    ctripManualFetchConfigProofPending: () => false,
    ctripManualFetchConfigCandidate: () => config, getActiveCtripConfig: () => config,
    applyCtripConfigObject: () => {},
    captureAuthSession: () => ({ epoch: context.authSessionEpoch }),
    isAuthSessionCurrent: captured => captured.epoch === context.authSessionEpoch,
    capturePlatformHotelRequestContext: () => ({ hotelId: context.selectedCtripHotelId.value, epoch: scopeState.hotelEpoch }),
    isPlatformHotelRequestContextCurrent: captured => captured.hotelId === context.selectedCtripHotelId.value
      && captured.epoch === scopeState.hotelEpoch,
    invalidatePlatformHotelRequestContext: () => { scopeState.hotelEpoch++; },
    ctripCommentBrowserCaptureRequestSeq: 0, ctripDiagnosisSnapshotRequestSeq: 0, ctripSearchOpportunityRequestSeq: 0,
    ctripReviewMatchControllerBindings: {
      invalidateCtripReviewMatch: () => {}, ctripReviewMatchResult: { value: null },
      ctripReviewMatchLoading: { value: '' }, ctripReviewMatchLookupLoadingCommentId: { value: '' },
    },
    debugLog: () => {}, showToast: (...args) => notices.push(args),
    useCtripDisplayHotels: (rows, summary) => {
      effects.push('display'); context.ctripHotelsList.value = clone(rows);
      context.ctripBusinessSummary.value = clone(summary || { cards: [] });
      return context.ctripHotelsList.value;
    },
    updateAiAnalysisHotelList: () => effects.push('analysis'),
    scheduleOnlineHistoryRefresh: () => effects.push('history'),
    scheduleLatestCtripRefresh: () => effects.push('latest'),
    scheduleOnlineDataRefresh: () => effects.push('data'),
    loadLatestCtripData: async options => { recovery.push(options); return context.ctripLatestMeta.value; },
    manualOneClickFetchQunarAutoRetryAllowedAt: () => false,
    manualOneClickFetchQunarVisitorNeedsRetry: () => false, CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT: 2,
    request: async () => {
      effects.push('synthetic_request');
      const response = await nextResponse;
      if (response instanceof Error) throw response;
      return clone(response);
    },
  };
  for (const name of ['ctripTrafficSummary','ctripTrafficAnalysis','ctripTrafficHistoryResult','ctripRealtimeTrafficRecord',
    'latestTrafficData','ctripCommentBrowserCaptureResult','ctripBrowserCaptureResult','ctripOverviewResult',
    'ctripFlowOverviewResult','ctripAdsBrowserCaptureResult','ctripSearchOpportunityPayload','ctripSearchOpportunityError',
    'ctripSearchOpportunityLoading','ctripSearchOpportunitySaving','ctripCommentBrowserCaptureRunning',
    'ctripDiagnosisSnapshotLoading','ctripLatestComparison']) context[name] = { value: null };
  vm.createContext(context);
  vm.runInContext(helperSource, context, { filename: 'actual-ctrip-static.js', timeout: 1000 });
  context.runCtripFetchDataFlow = context.window.SUXI_CTRIP_STATIC.runCtripFetchDataFlow;
  context.canPreserveCtripRankingSnapshot = context.window.SUXI_CTRIP_STATIC.canPreserveCtripRankingSnapshot;
  context.buildTruthfulCtripDisplayModel = context.window.SUXI_CTRIP_STATIC.buildTruthfulCtripDisplayModel;
  context.buildLatestCtripSnapshotModel = context.window.SUXI_CTRIP_STATIC.buildLatestCtripSnapshotModel;
  const names = ['useCtripDisplayHotels', 'clearCtripRankingDisplayState', 'clearCtripOverviewDisplayState',
    'hasVisibleCtripSnapshot', 'handleCtripFetchFailure', 'fetchCtripData', 'applyLatestCtripSnapshot'];
  const scopeDeclarations = ['ctripManualFetchRequestSeq', 'ctripManualFetchActive', 'ctripRankingHistoryRequestSeq']
    .map(name => {
      const statement = mainSource.match(new RegExp('let ' + name + '\\s*=\\s*[^;\\r\\n]+;'))?.[0];
      assert.ok(statement, 'actual main scope declaration is required: ' + name);
      return statement;
    }).join('\n');
  const resetClear = declaration('resetHotelScopedClientState').match(/clearCtripOverviewDisplayState\(\);/)?.[0];
  const authClear = declaration('clearAuthSessionWithStatus');
  const epochIncrement = authClear.match(/authSessionEpoch\s*\+=\s*1\s*;/)?.[0];
  const authReset = authClear.match(/resetHotelScopedClientState\(\);/)?.[0];
  assert.ok(resetClear && epochIncrement && authReset, 'actual HEAD auth/reset/display clear chain is required');
  vm.runInContext(scopeDeclarations + '\n' + names.map(declaration).join('\n')
    + `\nconst resetHotelScopedClientState = () => {${resetClear}};`
    + `\nconst resetAuthDisplayFragment = () => {${epochIncrement}${authReset}};`
    + `\nglobalThis.bound = {${names.join(',')}, resetAuthDisplayFragment};`, context,
    { filename: 'actual-manual-binding-extract.js', timeout: 1000 });
  context.useCtripDisplayHotels = context.bound.useCtripDisplayHotels;
  const runHelper = () => context.runCtripFetchDataFlow({
    isLoggedIn: () => true, getSelectedCtripHotelId: () => context.selectedCtripHotelId.value,
    getActiveCtripConfig: () => config, getForm: () => context.ctripForm.value,
    setFetching: value => { context.fetchingData.value = value; },
    setShowRawData: value => { context.showRawData.value = value; },
    setFetchSuccess: value => { context.ctripFetchSuccess.value = value; },
    setSavedCount: value => { context.ctripSavedCount.value = value; },
    setOnlineDataResult: value => { context.onlineDataResult.value = value; },
    useDisplayHotels: context.useCtripDisplayHotels,
    setOnlineDataFilterDates: ({ startDate, endDate }) => {
      context.onlineDataFilter.value = { start_date: startDate, end_date: endDate };
    },
    getLatestMeta: () => context.ctripLatestMeta.value,
    setLatestMeta: value => { context.ctripLatestMeta.value = value; },
    setTableTab: value => { context.ctripTableTab.value = value; },
    notify: context.showToast, requestFetch: context.request,
    handleFetchFailure: context.bound.handleCtripFetchFailure,
    hasVisibleSnapshot: context.bound.hasVisibleCtripSnapshot,
    suppressPostFetchRefresh: true,
  });
  const visibleSnapshot = () => clone({ rows: context.ctripHotelsList.value,
    summary: context.ctripBusinessSummary.value, result: context.onlineDataResult.value,
    latestMeta: context.ctripLatestMeta.value });
  const switchScope = kind => {
    if (kind === 'session') context.bound.resetAuthDisplayFragment();
    else if (kind === 'date') context.ctripForm.value.startDate = context.ctripForm.value.endDate = '2026-10-01';
    else {
      context.selectedCtripHotelId.value = 902;
      scopeState.hotelEpoch++;
      if (kind === 'hotel-roundtrip') {
        context.selectedCtripHotelId.value = 901;
        scopeState.hotelEpoch++;
      }
    }
    // For auth this is the actual selected HEAD auth/reset/overview clear chain.
    // Other external owners invoke the actual ranking clear binding here.
    if (kind !== 'session') context.bound.clearCtripRankingDisplayState();
    context.onlineDataResult.value = context.ctripLatestMeta.value = null;
    context.fetchingData.value = false;
  };
  return { context, effects, notices, recovery, runHelper, visibleSnapshot, switchScope,
    respond: response => { nextResponse = response; },
    defer: () => {
      let resolve, reject;
      nextResponse = new Promise((ok, fail) => { resolve = ok; reject = fail; });
      return { resolve, reject };
    } };
}

// Calls the actual exported pure production guard. This intentionally fails
// clearly until the reviewed source patch supplies that export.
function proposedSameVerifiedScope(context) {
  const api = context.window.SUXI_CTRIP_STATIC;
  assert.equal(typeof api.canPreserveCtripRankingSnapshot, 'function', 'actual exported preserve helper is required');
  return api.canPreserveCtripRankingSnapshot({
    selectedHotelId: context.selectedCtripHotelId.value,
    form: context.ctripForm.value, meta: context.ctripLatestMeta.value,
    rows: context.ctripHotelsList.value, displayActivated: context.ctripRankingDisplayActivated.value,
    filterStartDate: context.onlineDataFilter.value.start_date,
    filterEndDate: context.onlineDataFilter.value.end_date,
  });
}

async function seed(h) {
  assert.equal((await h.runHelper()).status, 'success');
  assert.equal(h.context.ctripHotelsList.value[0].bookOrderNum, 7);
  assert.equal(h.context.ctripLatestMeta.value.readback_verified, true);
  assert.equal(h.context.ctripFetchSuccess.value, true);
  return h.visibleSnapshot();
}

test('verified synthetic save/readback metadata produces successful visible result', async () => {
  const h = harness(); await seed(h);
  assert.equal(h.context.ctripLatestMeta.value.saved_count, 1);
  assert.equal(h.context.fetchingData.value, false);
});

for (const status of [409, 403]) {
  test(`helper HTTP${status} preserves last verified result and table`, async () => {
    const h = harness(); const previous = await seed(h);
    h.respond({ code: status, message: 'Synthetic safe execution failure', data: {
      reason: 'ota_manual_execution_failed', stage: status === 403 ? 'authorization' : 'credential',
      ...(status === 409 ? { failure_code: 'credential_configuration_mismatch' } : {}),
      raw_response: 'SYNTHETIC_FAILED_RAW',
    } });
    assert.equal((await h.runHelper()).status, 'failed');
    assert.deepEqual(h.visibleSnapshot(), previous);
    assert.equal(h.context.showRawData.value, false);
    assert.equal(h.recovery.at(-1).hydrateDisplay, false);
    assert.equal(h.notices.at(-1)[1], 'error');
  });
}

test('helper rejected synthetic request preserves last verified result and table', async () => {
  const h = harness(); const previous = await seed(h);
  h.respond(new Error('Synthetic rejected request'));
  assert.equal((await h.runHelper()).status, 'error');
  assert.deepEqual(h.visibleSnapshot(), previous);
});

for (const persistenceStatus of ['not_persisted', 'readback_failed']) {
  test(`helper HTTP200 ${persistenceStatus} must preserve last verified result and table`, async () => {
    const h = harness(); const previous = await seed(h);
    h.respond({ code: 200, message: 'Synthetic failed persistence', data: {
      status: persistenceStatus === 'not_persisted' ? 'not_persisted' : 'failed',
      persistence_status: persistenceStatus, saved_count: 0, persisted: false, readback_verified: false,
      data: { synthetic_rejected_result: 'FAILED_REPLACEMENT' }, display_hotels: [],
      source_business_date: '2026-09-30', response_date_status: 'verified',
    } });
    await h.runHelper();
    assert.deepEqual(h.visibleSnapshot(), previous, 'failed save/readback must not replace last valid display');
  });
}

test('actual manual binding HTTP409 must preserve same-hotel same-date last verified table', async () => {
  const h = harness(); const previous = await seed(h);
  h.respond({ code: 409, message: 'Synthetic configuration mismatch', data: {
    failure_code: 'credential_configuration_mismatch', reason: 'ota_manual_execution_failed', stage: 'credential',
  } });
  assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'failed');
  assert.deepEqual(h.visibleSnapshot(), previous, 'same-scope failed attempt must preserve last valid table');
  assert.equal(h.context.ctripFetchSuccess.value, false, 'current attempt remains failed');
  assertRetainedSnapshotFailureNotice(h);
});

for (const scope of ['hotel', 'date']) {
  test(`actual manual binding clears old snapshot when requested ${scope} changes`, async () => {
    const h = harness(); await seed(h);
    if (scope === 'hotel') h.context.selectedCtripHotelId.value = 902;
    else h.context.ctripForm.value.startDate = h.context.ctripForm.value.endDate = '2026-10-01';
    h.respond({ code: 409, message: 'Synthetic failure in new scope' });
    await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
    assert.equal(h.context.ctripHotelsList.value.length, 0, 'old scope must not be displayed as current');
    assert.equal(h.context.ctripFetchSuccess.value, false);
  });
}

test('production pure guard recognizes a verified single-day same-hotel displayed snapshot', async () => {
  const h = harness(); await seed(h);
  assert.equal(proposedSameVerifiedScope(h.context), true);
});

test('production pure guard rejects changed hotel/date/platform and unverified or drifted display', async () => {
  const mutations = {
    hotel: c => { c.selectedCtripHotelId.value = 902; },
    date: c => { c.ctripForm.value.startDate = c.ctripForm.value.endDate = '2026-10-01'; },
    platform: c => { c.ctripLatestMeta.value.platform = 'meituan'; },
    missing_metadata: c => { c.ctripLatestMeta.value = null; },
    unverified: c => { c.ctripLatestMeta.value.readback_verified = false; },
    display_date: c => { c.ctripHotelsList.value[0]._channelOrderDataDate = '2026-09-29'; },
    missing_row_provenance: c => { delete c.ctripHotelsList.value[0]._channelOrderFetchedAt; },
    null_row: c => { c.ctripHotelsList.value = [null]; },
    metadata_only_refresh: c => { c.ctripLatestMeta.value.fetched_at = '2026-10-01 11:00:00'; },
    multi_day: c => { c.ctripForm.value.startDate = '2026-09-29'; },
  };
  for (const [name, mutate] of Object.entries(mutations)) {
    const h = harness(); await seed(h); mutate(h.context);
    assert.equal(proposedSameVerifiedScope(h.context), false, name);
  }
});

test('actual manual binding safely clears when an older static helper has no preserve export', async () => {
  const h = harness(); await seed(h);
  delete h.context.window.SUXI_CTRIP_STATIC.canPreserveCtripRankingSnapshot;
  h.respond({ code: 409, message: 'Synthetic failure with old helper cache' });
  assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'failed');
  assert.equal(h.context.ctripHotelsList.value.length, 0);
});

test('production pure guard rejects source-unverified metadata despite matching row provenance', async () => {
  const h = harness(); await seed(h);
  h.context.ctripLatestMeta.value.status = 'source_unverified';
  h.context.ctripLatestMeta.value.response_date_status = 'target_date_unverified';
  assert.equal(proposedSameVerifiedScope(h.context), false);
});

test('production pure guard retains eligible old snapshot after consecutive same-scope HTTP409 failures', async () => {
  const h = harness(); const previous = await seed(h);
  h.respond({ code: 409, message: 'Synthetic same-scope configuration failure' });
  for (let attempt = 0; attempt < 2; attempt++) {
    assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'failed');
    assert.deepEqual(h.visibleSnapshot(), previous);
    assert.equal(h.context.ctripFetchSuccess.value, false, 'current failed attempt stays failed');
    assert.equal(proposedSameVerifiedScope(h.context), true,
      'old verified snapshot provenance remains eligible without claiming a new successful attempt');
    assertRetainedSnapshotFailureNotice(h);
  }
});

function assertRetainedSnapshotFailureNotice(h) {
  const [text, level] = h.notices.at(-1) || [];
  assert.equal(level, 'error');
  assert.ok(text.includes('本次获取失败'), 'notice identifies the failed current attempt');
  assert.ok(text.includes('2026-09-30'), 'notice identifies the retained data date');
  assert.ok(text.includes('2026-10-01 10:00:00'), 'notice identifies the last successful capture time');
}

test('main manual/history fixture dependencies use declarations already present in the target source', () => {
  for (const name of ['ctripManualFetchRequestSeq', 'ctripManualFetchActive', 'ctripRankingHistoryRequestSeq']) {
    assert.match(mainSource, new RegExp('let ' + name + '\\s*='));
  }
  for (const name of ['ctripRankingHistoryLoading', 'ctripRankingHistoryRange', 'ctripRankingHistoryMessage', 'ctripRankingStoredDate']) {
    assert.match(mainSource, new RegExp('const ' + name + '\\s*=\\s*ref\\('));
  }
  assert.doesNotMatch(declaration('fetchCtripData'), /ctripManualFetchRunToken/,
    'port must reuse main request ownership instead of adding the old HEAD-only counter');
});

test('failure notice keeps retained row capture time after an actual metadata-only refresh', async () => {
  const h = harness(); await seed(h);
  const rowsBefore = clone(h.context.ctripHotelsList.value);
  const deferred = h.defer();
  const pending = h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
  const refreshedMeta = { ...clone(h.context.ctripLatestMeta.value), fetched_at: '2026-10-01 11:00:00' };
  h.context.bound.applyLatestCtripSnapshot({ metadata: refreshedMeta }, { hydrateDisplay: false });
  assert.deepEqual(clone(h.context.ctripHotelsList.value), rowsBefore, 'metadata-only refresh does not replace displayed rows');
  deferred.resolve({ code: 409, message: 'Synthetic safe same-scope failure after metadata refresh' });
  assert.equal((await pending).status, 'failed');
  assert.deepEqual(clone(h.context.ctripHotelsList.value), rowsBefore);
  assertRetainedSnapshotFailureNotice(h);
});

test('date changes during actual failure-recovery await cannot show the old failure notice', async () => {
  const h = harness(); await seed(h);
  let releaseRecovery;
  h.context.loadLatestCtripData = () => new Promise(resolve => { releaseRecovery = resolve; });
  h.respond({ code: 409, message: 'Synthetic old-date failure awaiting recovery' });
  const pending = h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
  for (let step = 0; step < 10 && !releaseRecovery; step++) await Promise.resolve();
  assert.equal(typeof releaseRecovery, 'function', 'actual failure handler reached recovery await');
  h.switchScope('date');
  const noticeCount = h.notices.length;
  const newScopeSnapshot = h.visibleSnapshot();
  releaseRecovery();
  assert.equal((await pending).status, 'stale');
  assert.deepEqual(h.visibleSnapshot(), newScopeSnapshot);
  assert.equal(h.notices.length, noticeCount, 'date change must suppress the old error toast after recovery');
});

test('verified synthetic zero stays zero while a missing metric stays missing', async () => {
  for (const missing of [false, true]) {
    const h = harness(); const response = successResponse();
    if (missing) delete response.data.display_hotels[0].bookOrderNum;
    else response.data.display_hotels[0].bookOrderNum = 0;
    h.respond(response);
    assert.equal((await h.runHelper()).status, 'success');
    const value = h.context.ctripHotelsList.value[0].bookOrderNum;
    if (missing) assert.ok(value == null, 'unavailable metrics cannot be fabricated as zero');
    else assert.equal(value, 0);
  }
});

test('zero or absent synthetic display rows never activate a successful table', async () => {
  for (const missing of [false, true]) {
    const h = harness(); const data = { saved_count: 0, persisted: false, data: {} };
    if (!missing) data.display_hotels = [];
    h.respond({ code: 200, data });
    const outcome = await h.runHelper();
    assert.notEqual(outcome.status, 'success');
    assert.equal(h.context.ctripHotelsList.value.length, 0);
    assert.equal(h.context.ctripRankingDisplayActivated.value, false);
    assert.equal(h.context.ctripFetchSuccess.value, false);
  }
});

test('explicit synthetic save failure cannot activate rejected rows', async () => {
  const h = harness();
  h.respond({ code: 200, message: 'Synthetic save failed', data: {
    status: 'failed', persistence_status: 'readback_failed', saved_count: 0,
    display_hotels: [{ hotelId: 'SYNTHETIC_PLATFORM', name: 'Synthetic Rejected', bookOrderNum: 999 }],
    data: { synthetic_rejected_result: true },
  } });
  assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'business_failed');
  assert.equal(h.context.ctripHotelsList.value.length, 0);
  assert.equal(h.context.ctripRankingDisplayActivated.value, false);
  assert.equal(h.context.ctripFetchSuccess.value, false);
  assert.equal(h.context.onlineDataResult.value, null);
});

test('actual selected HEAD auth/reset/overview-clear chain removes the old session display', async () => {
  const h = harness(); await seed(h);
  assert.match(declaration('beginAuthSession'), /authSessionEpoch\s*\+=\s*1[\s\S]*resetHotelScopedClientState\(/);
  h.switchScope('session');
  assert.equal(h.context.ctripHotelsList.value.length, 0);
  assert.equal(h.context.ctripRankingDisplayActivated.value, false);
  assert.equal(h.context.ctripFetchSuccess.value, false);
  assert.equal(h.context.ctripLatestMeta.value, null);
  assert.equal(h.context.onlineDataResult.value, null);
});

for (const scope of ['hotel', 'hotel-roundtrip', 'date', 'session']) {
  test(`actual binding discards old success/failure/exception after ${scope} scope changes`, async () => {
    for (const outcome of ['success', 'failure', 'exception']) {
      const h = harness(); await seed(h);
      const deferred = h.defer();
      const pending = h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
      h.switchScope(scope);
      const date = h.context.ctripForm.value.endDate;
      h.context.ctripLatestMeta.value = h.context.window.SUXI_CTRIP_STATIC.buildCtripFetchMeta({
        hotelId: String(h.context.selectedCtripHotelId.value), startDate: date, endDate: date,
        fetchedAt: '2026-10-01 12:00:00', savedCount: 1, displayHotelCount: 1,
        persisted: true, readbackVerified: true, sourceBusinessDate: date, responseDateStatus: 'verified',
      });
      h.context.bound.useCtripDisplayHotels([{ hotelId: 'NEW_SCOPE_SYNTHETIC', name: 'New Owner', bookOrderNum: 42 }],
        { cards: [] }, { sourceReady: true, orderEstimateDataDate: date,
          orderEstimateTargetDataDate: date, orderEstimateFetchedAt: '2026-10-01 12:00:00' });
      h.context.onlineDataResult.value = { synthetic_scope_result: 'NEW_OWNER' };
      h.context.onlineDataFilter.value = { start_date: date, end_date: date };
      h.context.ctripSavedCount.value = 1;
      const currentDeferred = h.defer();
      const currentPending = h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
      const previous = h.visibleSnapshot();
      const noticeCount = h.notices.length, recoveryCount = h.recovery.length;
      const savedCount = h.context.ctripSavedCount.value;
      assert.equal(h.context.fetchingData.value, true, 'an actual newer request owns loading');
      if (outcome === 'exception') deferred.reject(new Error('Synthetic old-scope exception'));
      else deferred.resolve(outcome === 'success' ? successResponse()
        : { code: 409, message: 'Synthetic old-scope failure', data: { raw: 'SYNTHETIC_OLD_RAW' } });
      assert.equal((await pending).status, 'stale', outcome);
      assert.deepEqual(h.visibleSnapshot(), previous, 'old completion cannot replace current owner data');
      assert.equal(h.context.ctripFetchSuccess.value, false, 'new request has not completed');
      assert.equal(h.context.ctripSavedCount.value, savedCount);
      assert.equal(h.context.fetchingData.value, true, 'old request cannot release the newer owner');
      assert.equal(h.notices.length, noticeCount);
      assert.equal(h.recovery.length, recoveryCount);
      const currentResponse = successResponse();
      currentResponse.data.source_business_date = date;
      currentResponse.data.fetched_at = '2026-10-01 12:00:00';
      currentDeferred.resolve(currentResponse);
      assert.equal((await currentPending).status, 'success', 'new request still completes normally');
    }
  });
}
