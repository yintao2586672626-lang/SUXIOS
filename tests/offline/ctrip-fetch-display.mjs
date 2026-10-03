import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import vm from 'node:vm';

// Source-only VM harness: no application, browser, login, HTTP, database,
// environment, credential reads or server-side persistence. All payloads synthetic.
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

function harness({ throughTransport = false } = {}) {
  let nextResponse = successResponse();
  const effects = [], notices = [], recovery = [];
  const config = { id: 'synthetic-ctrip', config_id: 'synthetic-ctrip', hotel_id: 901,
    system_hotel_id: 901, node_id: '24588', has_cookies: true, credential_status: 'ready' };
  const context = {
    window: { setTimeout: () => { throw new Error('Unexpected offline timer.'); } },
    console: { error: () => {} }, URLSearchParams,
    fetchingData: { value: false }, ctripRankingHistoryLoading: { value: false },
    ctripRankingHistoryRequestSeq: 0, ctripRankingHistoryRange: { value: '' }, ctripRankingHistoryMessage: { value: '' },
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
    captureAuthSession: () => ({ epoch: 1 }), isAuthSessionCurrent: () => true,
    capturePlatformHotelRequestContext: () => ({ hotelId: context.selectedCtripHotelId.value }),
    isPlatformHotelRequestContextCurrent: captured => captured.hotelId === context.selectedCtripHotelId.value,
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
      if (nextResponse instanceof Error) throw nextResponse;
      return clone(nextResponse);
    },
    API_BASE: '/api', currentPage: { value: 'ctrip-ebooking' },
    currentPageReadPolicy: () => ({}),
    withBusinessRequestContext: (url, options) => ({ url, options }),
    isTerminalAuthFailureResponse: () => false,
    token: { value: '' }, applyAuthContext: () => effects.push('permission_denied'),
    fetch: async () => {
      effects.push('synthetic_request');
      if (nextResponse instanceof Error) throw nextResponse;
      const httpStatus = nextResponse.httpStatus || nextResponse.code;
      return { status: httpStatus, ok: httpStatus < 400,
        json: async () => clone(nextResponse) };
    },
  };
  vm.createContext(context);
  vm.runInContext(helperSource, context, { filename: 'actual-ctrip-static.js', timeout: 1000 });
  context.runCtripFetchDataFlow = context.window.SUXI_CTRIP_STATIC.runCtripFetchDataFlow;
  context.canPreserveCtripRankingSnapshot = context.window.SUXI_CTRIP_STATIC.canPreserveCtripRankingSnapshot;
  context.buildTruthfulCtripDisplayModel = context.window.SUXI_CTRIP_STATIC.buildTruthfulCtripDisplayModel;
  if (throughTransport) {
    vm.runInContext(declaration('executeApiRequest') + '\n' + declaration('request')
      + '\nglobalThis.transportRequest = request;', context);
    context.request = context.transportRequest;
  }
  const names = ['useCtripDisplayHotels', 'clearCtripRankingDisplayState', 'hasVisibleCtripSnapshot', 'handleCtripFetchFailure', 'fetchCtripData'];
  vm.runInContext(names.map(declaration).join('\n') + `\nglobalThis.bound = {${names.join(',')}};`, context,
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
  return { context, effects, notices, recovery, runHelper, visibleSnapshot,
    respond: response => { nextResponse = response; } };
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

test('actual HTTP422 transport displays date-unverified competitors without treating them as saved facts', async () => {
  const h = harness({ throughTransport: true });
  h.respond({ code: 422, message: 'Synthetic date evidence unavailable; not saved.', data: {
    save_status: 'target_date_unverified', persistence_status: 'blocked',
    saved_count: 0, persisted: false, readback_verified: false,
    fetched_at: '2026-10-01 10:00:00', source_business_date: null,
    response_date_status: 'target_date_unverified',
    display_hotels: [{ hotelId: 'SYNTHETIC_COMPETITOR', name: 'Synthetic Competitor', bookOrderNum: 7 }],
    display_summary: null,
  } });
  const result = await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true });
  assert.equal(result.status, 'source_unverified');
  assert.equal(h.context.ctripHotelsList.value[0].name, 'Synthetic Competitor');
  assert.equal(h.context.ctripRankingDisplayActivated.value, true);
  assert.equal(h.context.ctripFetchSuccess.value, false);
  assert.equal(h.context.ctripSavedCount.value, 0);
  assert.equal(h.context.ctripLatestMeta.value.data_date, '');
  assert.equal(h.context.ctripLatestMeta.value.request_date, '2026-09-30');
  assert.equal(h.context.ctripLatestMeta.value.readback_verified, false);
  assert.equal(h.context.window.SUXI_CTRIP_STATIC.isCtripVerifiedReportSource(h.context.ctripLatestMeta.value), false);
  assert.equal(h.notices.at(-1)[1], 'warning');
  assert.equal(h.effects.filter(effect => effect === 'synthetic_request').length, 1);
  assert.equal(h.effects.some(effect => ['history', 'latest', 'data'].includes(effect)), false);
  assert.equal(h.recovery.length, 0);
});

test('actual HTTP422 validation failure cannot enter the date-unverified preview branch', async () => {
  const h = harness({ throughTransport: true });
  h.respond({ code: 422, message: 'Synthetic validation failure', data: {
    save_status: 'blocked', display_hotels: [{ name: 'Rejected synthetic row' }],
  } });
  assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'error');
  assert.equal(h.context.ctripHotelsList.value.length, 0);
  assert.equal(h.context.ctripRankingDisplayActivated.value, false);
  assert.equal(h.notices.at(-1)[1], 'error');
});

test('HTTP403 remains denied even when its body resembles a date-unverified preview', async () => {
  const h = harness({ throughTransport: true });
  h.respond({ httpStatus: 403, code: 422, message: 'Synthetic denied response', data: {
    save_status: 'target_date_unverified', display_hotels: [{ name: 'Denied synthetic row' }],
  } });
  assert.equal((await h.context.bound.fetchCtripData({ suppressPostFetchRefresh: true })).status, 'error');
  assert.equal(h.effects.includes('permission_denied'), true);
  assert.equal(h.context.ctripHotelsList.value.length, 0);
  assert.equal(h.context.ctripRankingDisplayActivated.value, false);
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
