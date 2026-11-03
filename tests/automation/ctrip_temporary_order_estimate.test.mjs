import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const staticSource = readFileSync('public/ctrip-static.js', 'utf8');
const appSource = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const FixtureDate = class extends Date {
  constructor(...args) { super(...(args.length ? args : ['2026-10-01T09:30:00+08:00'])); }
};
const context = { window: {}, console, Date: FixtureDate };
vm.runInNewContext(staticSource, context);
const api = context.window.SUXI_CTRIP_STATIC;
const row = { hotelId: 'synthetic-peer', bookOrderNum: 9, totalDetailNum: 20,
  convertionRate: 10, qunarDetailVisitors: 20, qunarDetailCR: 10 };
const displayDefinition = appSource.slice(appSource.indexOf('const useCtripDisplayHotels ='),
  appSource.indexOf('const clearCtripRankingDisplayState ='));

const createState = () => {
  const state = {
    buildTruthfulCtripDisplayModel: api.buildTruthfulCtripDisplayModel,
    emptyCtripBusinessSummary: () => ({ cards: [] }),
    ctripHotelsList: { value: [] }, ctripBusinessSummary: { value: null },
    topTenHotels: { value: [] }, ctripRankingDisplayActivated: { value: false },
    ctripFetchSuccess: { value: false }, ctripLatestMeta: { value: {} },
    selectedHotelId: '121', form: { startDate: '2026-09-30', endDate: '2026-09-30' },
    failures: [], refreshes: [], savedCounts: [],
    updateAiAnalysisHotelList: () => {},
  };
  vm.runInNewContext(`${displayDefinition}; useDisplay = useCtripDisplayHotels;`, state);
  return state;
};

const fetchPreview = async (state, changes = {}, requestOverride = null) => api.runCtripFetchDataFlow({
  isLoggedIn: () => true,
  getSelectedCtripHotelId: () => state.selectedHotelId,
  getActiveCtripConfig: () => ({ id: 'synthetic-config', config_id: 'synthetic-config',
    credential_status: 'ready', has_cookies: true, system_hotel_id: 121 }),
  getForm: () => state.form,
  requestFetch: requestOverride || (async () => ({ code: 422, data: {
    saved_count: 0, save_status: 'target_date_unverified', persisted: false,
    readback_verified: false, response_date_status: 'target_date_unverified',
    fetched_at: '2026-10-01T09:00:00+08:00', display_hotels: [{ ...row }],
    ...changes,
  } })),
  useDisplayHotels: state.useDisplay,
  setFetchSuccess: value => { state.ctripFetchSuccess.value = value; },
  setSavedCount: value => state.savedCounts.push(value),
  getLatestMeta: () => state.ctripLatestMeta.value,
  setLatestMeta: value => { state.ctripLatestMeta.value = value; },
  handleFetchFailure: message => state.failures.push(message),
  refreshOnlineHistory: async () => state.refreshes.push('history'),
  refreshLatestCtripData: async () => state.refreshes.push('latest'),
  refreshOnlineData: () => state.refreshes.push('online'),
});

const estimate = (state, extraOptions = {}, displayRow = state.ctripHotelsList.value[0]) => (
  api.buildCtripChannelOrderBreakdown(displayRow, {
    sourceReady: false, enforceUpdateWindow: true, now: '2026-10-01T09:30:00+08:00',
    dataDate: displayRow?._channelOrderDataDate,
    targetDataDate: displayRow?._channelOrderTargetDataDate,
    fetchedAt: displayRow?._channelOrderFetchedAt,
    previewContext: { meta: state.ctripLatestMeta.value, selectedHotelId: state.selectedHotelId, form: state.form },
    ...extraOptions,
  })
);

test('only the current unsaved batch receives explicitly unverified temporary order estimates', async () => {
  const state = createState();
  const result = await fetchPreview(state, { display_hotels: [{ ...row, data_date: '2026-09-30' }] });
  const displayRow = state.ctripHotelsList.value[0];
  const breakdown = estimate(state);
  assert.equal(result.status, 'source_unverified');
  assert.deepEqual([breakdown.totalOrdersIncludingCancelled, breakdown.ctripOrders,
    breakdown.qunarOrders, breakdown.ctripUndistributedOrders], [12, 2, 2, 8]);
  assert.equal(breakdown.referenceOnly, true);
  assert.equal(breakdown.validationStatus, 'unverified');
  assert.equal(breakdown.identity.businessDate, null);
  assert.equal(breakdown.estimateAvailability.dataDate, '');
  assert.match(breakdown.sourceLabel, /临时估算.*未入库.*仅供参考/);
  assert.equal(displayRow._channelOrderDataDate, '');
  assert.equal(displayRow._channelOrderTargetDataDate, '2026-09-30');
  assert.equal(state.ctripFetchSuccess.value, false);
  assert.equal(api.isCtripVerifiedReportSource(state.ctripLatestMeta.value), false);
  assert.equal(state.savedCounts.at(-1), 0);
  assert.deepEqual(state.refreshes, []);
});

test('the real empty default date form can estimate the current unsaved return without inventing a source date', async () => {
  const state = createState();
  state.form = api.createCtripFetchForm();
  assert.equal(state.form.startDate, '');
  assert.equal(state.form.endDate, '');
  await fetchPreview(state);
  const breakdown = estimate(state);
  assert.equal(breakdown.ctripOrders, 2);
  assert.equal(breakdown.referenceOnly, true);
  assert.equal(breakdown.identity.businessDate, null);
  assert.equal(state.ctripHotelsList.value[0]._channelOrderDataDate, '');
  assert.equal(state.ctripHotelsList.value[0]._channelOrderPreviewScope.formStartDate, '');
  assert.equal(state.ctripHotelsList.value[0]._channelOrderPreviewScope.formEndDate, '');
  assert.equal(estimate(state, { now: '2026-10-02T09:30:00+08:00' }).status, 'source_unverified');
  // Filling in the same effective date still changes the original query identity.
  state.form.startDate = state.ctripHotelsList.value[0]._channelOrderPreviewScope.startDate;
  state.form.endDate = state.form.startDate;
  assert.equal(estimate(state).status, 'source_unverified');
});

test('temporary estimates retain missing inputs and channel conflicts instead of zero filling', async () => {
  const missing = createState();
  await fetchPreview(missing, { display_hotels: [{ ...row, qunarDetailVisitors: null }] });
  const missingBreakdown = estimate(missing);
  assert.equal(missingBreakdown.referenceOnly, true);
  assert.equal(missingBreakdown.status, 'input_missing');
  assert.equal(missingBreakdown.ctripOrders, 2);
  assert.equal(missingBreakdown.qunarOrders, null);
  assert.equal(missingBreakdown.ctripUndistributedOrders, null);
  const conflicting = createState();
  await fetchPreview(conflicting, { display_hotels: [{ ...row,
    totalDetailNum: 100, convertionRate: 100, qunarDetailVisitors: 100, qunarDetailCR: 100 }] });
  const conflict = estimate(conflicting);
  assert.equal(conflict.status, 'ctrip_ecosystem_total_conflict');
  assert.equal(conflict.ctripUndistributedOrders, null);
  assert.ok(conflict.ctripEstimateExcessOrders > 0);
});

test('an unknown source date uses the actual capture window instead of the requested historical date', async () => {
  const state = createState();
  await fetchPreview(state, { fetched_at: '2026-10-01T03:00:00+08:00' });
  const breakdown = estimate(state);
  assert.equal(breakdown.referenceOnly, true);
  assert.equal(breakdown.status, 'traffic_pending_window');
  assert.equal(breakdown.totalOrdersIncludingCancelled, 12);
  assert.equal(breakdown.ctripOrders, null);
  assert.equal(breakdown.qunarOrders, null);
  assert.equal(breakdown.ctripUndistributedOrders, null);
});

test('same-batch source date stays separate from the request and a range stays unknown', async () => {
  const state = createState();
  await fetchPreview(state, { source_business_date: '2026-09-29', response_date_status: 'target_date_mismatch' });
  assert.equal(estimate(state).identity.businessDate, '2026-09-29');
  assert.equal(state.ctripHotelsList.value[0]._channelOrderTargetDataDate, '2026-09-30');
  await fetchPreview(state, { source_business_date: '2026-09-28 至 2026-09-29' });
  assert.equal(estimate(state).identity.businessDate, null);
  assert.equal(state.ctripHotelsList.value[0]._channelOrderDataDate, '');
});

test('preview calculation never mixes historical traffic and keeps returned real zero', async () => {
  const historical = createState();
  await fetchPreview(historical, { display_hotels: [{ ...row, earlyMorningFallback: {
    applied_fields: ['totalDetailNum'], source_data_date: '2026-09-29',
  } }] });
  const historicResult = estimate(historical);
  assert.equal(historicResult.status, 'historical_traffic_reference_only');
  assert.equal(historicResult.ctripOrders, null);
  assert.equal(historicResult.qunarOrders, null);
  assert.equal(historicResult.ctripUndistributedOrders, null);
  const zero = createState();
  await fetchPreview(zero, { display_hotels: [{ ...row, bookOrderNum: 0,
    totalDetailNum: 0, qunarDetailVisitors: 0 }] });
  const zeroResult = estimate(zero);
  assert.deepEqual([zeroResult.totalOrdersIncludingCancelled, zeroResult.ctripOrders,
    zeroResult.qunarOrders, zeroResult.ctripUndistributedOrders], [0, 0, 0, 0]);
});

test('production scenario rows receive preview context while the trusted gate remains false', async () => {
  const state = createState();
  await fetchPreview(state);
  const scenarioDefinition = appSource.slice(appSource.indexOf('const ctripScenarioHotelsList ='),
    appSource.indexOf('const ctripSortedHotelsList ='));
  const scope = {
    computed: fn => ({ get value() { return fn(); } }), currentTime: { value: '' },
    ctripHotelsList: state.ctripHotelsList, ctripLatestMeta: state.ctripLatestMeta,
    ctripCompetitionReportSourceReady: { value: false },
    selectedCtripHotelId: { value: state.selectedHotelId }, ctripForm: { value: state.form },
    attachCtripChannelOrderBreakdown: api.attachCtripChannelOrderBreakdown,
    attachCtripFullChannelRoomNightScenario: api.attachCtripFullChannelRoomNightScenario,
    appTimeZone: 'Asia/Shanghai',
    Date: class extends Date { constructor(value = '2026-10-01T09:30:00+08:00') { super(value); } },
  };
  vm.runInNewContext(`${scenarioDefinition}; scenario = ctripScenarioHotelsList;`, scope);
  assert.equal(scope.scenario.value[0].ctripOrderEstimate, 2);
  assert.equal(scope.scenario.value[0].channelOrderBreakdownMeta.referenceOnly, true);
  assert.equal(scope.ctripCompetitionReportSourceReady.value, false);
  scope.selectedCtripHotelId.value = '122';
  assert.equal(scope.scenario.value[0].ctripOrderEstimate, null);
});

test('Vue ref proxies keep the same-batch identity shared by rows and metadata', async () => {
  const vueContext = { console };
  vm.runInNewContext(readFileSync('public/vue.runtime.global.prod.js', 'utf8'), vueContext);
  const state = createState();
  state.ctripHotelsList = vueContext.Vue.ref([]);
  state.ctripLatestMeta = vueContext.Vue.ref({});
  await fetchPreview(state);
  assert.equal(vueContext.Vue.isReactive(state.ctripHotelsList.value[0]), true);
  assert.equal(state.ctripHotelsList.value[0]._channelOrderPreviewScope,
    state.ctripLatestMeta.value.order_estimate_preview_scope);
  assert.equal(estimate(state).ctripOrders, 2);
  assert.equal(estimate(state).referenceOnly, true);
});

test('changing hotel or request dates and replacing a batch invalidates temporary estimates', async () => {
  const state = createState();
  await fetchPreview(state);
  const originalRow = state.ctripHotelsList.value[0];
  state.selectedHotelId = '122';
  assert.equal(estimate(state).status, 'source_unverified');
  state.selectedHotelId = '121';
  state.form = { startDate: '2026-09-29', endDate: '2026-09-29' };
  assert.equal(estimate(state).status, 'source_unverified');
  state.form = { startDate: '2026-09-30', endDate: '2026-09-30' };
  await fetchPreview(state);
  assert.equal(estimate(state, {}, originalRow).status, 'source_unverified');
  assert.equal(estimate(state).referenceOnly, true);
});

test('saved-history hydration strips preview identity even if a row contains preview-shaped fields', async () => {
  const state = createState();
  await fetchPreview(state);
  const oldRow = state.ctripHotelsList.value[0];
  state.useDisplay([oldRow], null, { sourceReady: false, orderEstimateDataDate: '' });
  assert.equal(state.ctripHotelsList.value[0]._channelOrderPreviewScope, null);
  const breakdown = estimate(state);
  assert.equal(breakdown.status, 'source_unverified');
  assert.equal(breakdown.ctripOrders, null);
  assert.equal(breakdown.referenceOnly, undefined);
});

test('permission failures, identity rejection and persisted responses cannot issue temporary permission', async () => {
  const forbidden = createState();
  const error = Object.assign(new Error('synthetic permission denied'), { httpStatus: 403,
    data: { code: 403, data: { save_status: 'target_date_unverified', display_hotels: [row] } } });
  await fetchPreview(forbidden, {}, async () => { throw error; });
  assert.equal(forbidden.ctripHotelsList.value.length, 0);
  assert.equal(forbidden.failures.length, 1);
  for (const change of [{ identity_check: { ok: false } }, { persisted: true }, { saved_count: 1 }, { readback_verified: true }]) {
    const state = createState();
    await fetchPreview(state, change);
    assert.equal(estimate(state).status, 'source_unverified');
    assert.equal(state.ctripHotelsList.value[0]._channelOrderPreviewScope, null);
  }
  const identityBlocked = createState();
  await fetchPreview(identityBlocked, { save_status: 'blocked', identity_check: { ok: false } });
  assert.equal(identityBlocked.ctripHotelsList.value.length, 0);
});

test('derived columns show numbers with one source notice and preserve conflict text', async () => {
  const state = createState();
  await fetchPreview(state);
  const secondaryDefinition = appSource.slice(appSource.indexOf('const ctripTrafficChannelSecondaryText ='),
    appSource.indexOf('const ctripTrafficChannelCellTitle ='));
  const formatter = {};
  vm.runInNewContext(`${secondaryDefinition}; format = ctripTrafficChannelSecondaryText;`, formatter);
  for (const field of ['ctripOrderEstimate', 'qunarOrderEstimate', 'ctripUndistributedOrderEstimate']) {
    assert.equal(formatter.format({ channelOrderBreakdownMeta: estimate(state) }, { field }), '');
  }
  assert.equal(formatter.format({ channelOrderBreakdownMeta: {
    referenceOnly: true, status: 'ctrip_ecosystem_total_conflict',
  } }, { field: 'ctripUndistributedOrderEstimate' }), '口径冲突');
  assert.doesNotMatch(template, /临时估算 · 未核验/);
  assert.equal((template.match(/\{\{ ctripUnverifiedDerivationNotice \}\}/g) || []).length, 2);
  assert.match(staticSource, /sourceNotice: \[unverifiedNoticeElement, sourceNoticeElement\]/);
});
