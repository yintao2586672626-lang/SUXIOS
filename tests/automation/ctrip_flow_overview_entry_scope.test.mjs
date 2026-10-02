import assert from 'node:assert/strict';
import fs from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const flush = () => new Promise(resolve => setImmediate(resolve));
const main = fs.readFileSync(process.env.SUXIOS_CTRIP_FLOW_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const helper = fs.readFileSync(process.env.SUXIOS_CTRIP_FLOW_SOURCE
  || new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
function section(start, end) {
  const a = main.indexOf(start), b = main.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `production section: ${start}`);
  return main.slice(a, b);
}
const production = [
  section('const postFetchRefreshTimers =', 'const normalizeCanonicalPage ='),
  section('const otaResultFiniteNumber =', 'const ctripFlowOverviewMetricCards ='),
  section('const currentPlatformHotelId =', 'const ctripSearchOpportunityPayload ='),
  section('const scheduleOnlineHistoryRefresh =', 'const scheduleAutoFetchStatusRefresh ='),
  section('const fetchCtripOverviewData =', 'const requestBrowserCaptureTask ='),
  section('const clearCtripOverviewDisplayState =', 'const getCtripOverviewTargetHotelId ='),
].join('\n');
const explicitUrl = 'https://ebooking.ctrip.com/datacenter/api/synthetic/overview';
const plain = value => JSON.parse(JSON.stringify(value));
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

// These are actual entry/clear/Flow/scheduler functions with test-only identity,
// configuration metadata, timers, and final endpoints. No real request is issued.
function harness({ form = {}, explicit = false, syncFormHotel = true, missingSelectedConfig = false, fallbackConfig = null } = {}) {
  const effects = [], notices = [], requests = [], refreshes = [], uiTasks = [], timers = new Map();
  const identity = { session: 1 };
  let timerId = 0;
  const ref = (value, name = '') => ({
    get value() { return value; },
    set value(next) { value = next; if (name) effects.push([name, next]); },
  });
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) refs[match[1]] ??= ref(null);
  Object.assign(refs, {
    selectedCtripHotelId: ref('701'), meituanForm: ref({ hotelId: '' }),
    ctripFlowOverviewFetching: ref(false, 'flow-busy'), fetchingData: ref(false, 'global-busy'),
    ctripFlowOverviewResult: ref(null, 'flow-result'), onlineDataResult: ref(null, 'online'),
    showRawData: ref(true, 'raw'), ctripOverviewFetching: ref(false), ctripOverviewResult: ref(null),
    ctripAdsBrowserCaptureRunning: ref(false), ctripCookieApiRunning: ref(false),
  });
  const review = { invalidateCtripReviewMatch() {} };
  for (const match of production.matchAll(/ctripReviewMatchControllerBindings\.([\w$]+)\.value/g)) {
    review[match[1]] = ref(null);
  }
  const config = () => missingSelectedConfig ? null : ({ config_id: `synthetic-${refs.selectedCtripHotelId.value}`,
    system_hotel_id: refs.selectedCtripHotelId.value, ota_hotel_id: `ota-${refs.selectedCtripHotelId.value}`,
    credential_status: 'ready', has_cookies: true });
  const record = (name, args) => {
    refreshes.push({ name, hotel: refs.selectedCtripHotelId.value, session: identity.session, args: plain(args) });
    return Promise.resolve();
  };
  const context = vm.createContext({
    ...refs, window: { SUXI_DATA_HEALTH_STATIC: {} }, console,
    ctripPlatformHotelContextEpoch: 0, meituanPlatformHotelContextEpoch: 0,
    ctripFlowOverviewCaptureRequestSeq: 0, ctripManualFetchActive: false,
    ctripReviewMatchControllerBindings: review,
    captureAuthSession: () => ({ epoch: identity.session }),
    isAuthSessionCurrent: captured => captured?.epoch === identity.session,
    selectedCtripHotelConfig: { get value() { return config(); } },
    getActiveCtripConfig: () => config() || fallbackConfig,
    applyCtripConfigObject: value => {
      effects.push(['apply-config', value.config_id]);
      refs.selectedCtripHotelId.value = String(value.hotel_id || value.system_hotel_id || refs.selectedCtripHotelId.value);
      if (syncFormHotel) refs.ctripFlowOverviewForm.value.hotelId = value.ota_hotel_id;
      refs.ctripOverviewForm.value.hotelId = value.ota_hotel_id;
    },
    getHotelNameById: hotel => `Synthetic hotel ${hotel}`,
    showToast: (...args) => notices.push(args),
    request: (url, options) => {
      const row = { ...deferred(), url, method: options.method, body: JSON.parse(options.body) };
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
  const full = context.window.SUXI_CTRIP_STATIC;
  refs.ctripFlowOverviewForm = ref({ ...full.createCtripFlowOverviewForm(), dataDate: '2026-09-27',
    ...(explicit ? { requestUrls: explicitUrl } : {}), ...form });
  refs.ctripOverviewForm = ref({ ...full.createCtripOverviewForm(), dataDate: '2026-09-26',
    requestUrls: explicitUrl, method: 'GET' });
  Object.assign(context, {
    ctripFlowOverviewForm: refs.ctripFlowOverviewForm, ctripOverviewForm: refs.ctripOverviewForm,
    ctripFlowOverviewDefaultRequestUrls: full.ctripFlowOverviewDefaultRequestUrls,
    runCtripOverviewFetchFlow: full.runCtripOverviewFetchFlow,
  });
  vm.runInContext(`${production}\nglobalThis.api = {
    run: fetchCtripFlowOverviewData, legacy: fetchCtripOverviewData,
    clear: clearCtripOverviewDisplayState, clearTimers: clearPostFetchRefreshTimers,
    resultView: otaFetchResultView
  };`, context, { filename: 'production-flow-overview-entry.js' });
  function switchScope(kind) {
    if (kind === 'auth') identity.session++;
    else refs.selectedCtripHotelId.value = '702';
    context.api.clear();
    if (kind === 'hotel-A-B-A') {
      refs.selectedCtripHotelId.value = '701';
      context.api.clear();
    }
    if (kind === 'auth') context.api.clearTimers();
  }
  const snapshot = () => plain({ busy: refs.ctripFlowOverviewFetching.value, global: refs.fetchingData.value,
    result: refs.ctripFlowOverviewResult.value, online: refs.onlineDataResult.value, raw: refs.showRawData.value });
  function fireTimers() {
    for (const [id, row] of [...timers.entries()].sort((a, b) => a[1].delay - b[1].delay)) {
      if (!timers.has(id)) continue;
      timers.delete(id);
      row.callback();
    }
  }
  const runUiTasks = async () => { for (const row of uiTasks.splice(0)) await row.callback(); };
  return { api: context.api, refs, effects, notices, requests, refreshes, timers, uiTasks,
    full, snapshot, switchScope, fireTimers, runUiTasks };
}

const success = () => ({ code: 200, data: { saved_count: 1, persisted: true, readback_verified: true,
  traffic_rows: [{ platform: 'ctrip', data_date: '2026-09-27', exposure: 0, visitors: 0 }] } });
async function begin(h, run = h.api.run) {
  const pending = run();
  await flush();
  return { pending };
}
async function finish(h, pending, outcome = 'success', index = h.requests.length - 1) {
  const request = h.requests[index];
  if (request) {
    if (outcome === 'exception') request.reject(new Error('synthetic overview error'));
    else request.resolve(outcome === 'failed' ? { code: 403, message: 'synthetic denied' } : success());
  }
  return pending;
}
const expectedRefreshes = (hotel = '701', session = 1) => [
  { name: 'history', hotel, session, args: [{ refreshHotels: false }] },
  { name: 'latest', hotel, session, args: [{ silent: true }] },
];
function assertTaskBody(request, hotel = '701', dataDate = '2026-09-27') {
  assert.equal(request.url, '/online-data/fetch-ctrip-overview');
  assert.equal(request.method, 'POST');
  assert.deepEqual(request.body, { config_id: `synthetic-${hotel}`, system_hotel_id: hotel,
    hotel_id: `ota-${hotel}`, data_date: dataDate,
    request_source: 'flow_overview' });
}

test('actual default flow-overview form dispatches its fixed task and selected business date without URLs', async () => {
  const h = harness();
  assert.equal(h.refs.ctripFlowOverviewForm.value.requestUrls, '');
  assert.deepEqual(Array.from(h.full.ctripFlowOverviewDefaultRequestUrls), []);
  const { pending } = await begin(h);
  const result = await finish(h, pending);
  assert.equal(result.status, 'success', 'configured default form must not stop at missing_request_urls');
  assert.equal(h.requests.length, 1);
  assertTaskBody(h.requests[0]);
  assert.equal(h.refs.ctripFlowOverviewResult.value.traffic_rows[0].exposure, 0);
  assert.equal(h.refs.onlineDataResult.value.traffic_rows[0].visitors, 0);
  assert.equal(result.readback_verified, true);
});

test('fixed flow-overview task ignores legacy URL/method and form task overrides', async () => {
  const h = harness({ explicit: true, form: { method: 'DELETE', requestSource: 'other-task', dataDate: '2026-09-25' } });
  const { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  assertTaskBody(h.requests[0], '701', '2026-09-25');
});

test('fixed flow-overview task emits only the strict backend task keys', async () => {
  const h = harness({ explicit: true, form: { method: 'GET' } });
  const { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  assert.deepEqual(Object.keys(h.requests[0].body).sort(), [
    'config_id', 'system_hotel_id', 'hotel_id', 'data_date', 'request_source',
  ].sort(), 'legacy hotel_name, URL and method fields are not accepted by the fixed task contract');
});

test('flow-overview cards display all fourteen available strict funnel metrics', () => {
  const h = harness();
  const metrics = {
    self_list_exposure: 100, self_detail_exposure: 50,
    self_order_filling_num: 10, self_order_submit_num: 5,
    self_flow_rate: 50, self_order_fill_rate: 20, self_deal_rate: 50,
    competitor_list_exposure: 200, competitor_detail_exposure: 100,
    competitor_order_filling_num: 20, competitor_order_submit_num: 0,
    competitor_flow_rate: 50, competitor_order_fill_rate: 20, competitor_deal_rate: 0,
  };
  const cards = plain(h.full.buildCtripFlowOverviewMetricCards({ status: 'ready', gaps: [], metrics }));
  assert.equal(cards.length, 14, 'the strict producer has seven self and seven competitor metrics');
  assert.deepEqual(cards.map(card => card.key).sort(), Object.keys(metrics).sort());
  for (const card of cards) {
    assert.equal(card.value, metrics[card.key]);
    if (card.key.endsWith('_rate')) assert.equal(card.type, 'percent');
  }
  assert.equal(cards.find(card => card.key === 'competitor_flow_rate').label, '竞圈曝光转化率');
});

test('flow-overview nullable metrics stay absent while real zero counts and rates remain visible', () => {
  const h = harness();
  const cards = plain(h.full.buildCtripFlowOverviewMetricCards({ status: 'partial',
    gaps: ['self_flow_rate_not_calculable'], metrics: {
      self_list_exposure: 0, self_detail_exposure: null, self_order_filling_num: undefined,
      self_order_submit_num: '', self_flow_rate: null, competitor_flow_rate: 0,
    } }));
  assert.deepEqual(cards.map(card => [card.key, card.value]), [
    ['self_list_exposure', 0], ['competitor_flow_rate', 0],
  ]);
});

function taskResponse({ status = 'partial', gaps = ['competitor_row_missing'], requestSource = 'flow_overview' } = {}) {
  return { code: 200, message: '携程流量概览已确认入库，部分指标未返回或不可计算', data: {
    ...(requestSource ? { request_source: requestSource } : {}), status, gaps,
    system_hotel_id: '701', hotel_id: 'ota-701', platform: 'ctrip', data_date: '2026-09-27',
    metric_scope: 'ctrip_channel_funnel', source_method: 'cookie_api',
    data: [{ hotelId: 'ota-701', date: '2026-09-27', platform: 'ctrip', compareType: 'self',
      listExposure: 0, detailExposure: null, orderFillingNum: null, orderSubmitNum: null }],
    total: 1, row_count: 1, saved_count: 1, readback_verified: true,
    persistence_status: 'readback_verified', counts: { overview: 1 },
    metrics: { self_list_exposure: 0, self_detail_exposure: null, competitor_list_exposure: null },
  } };
}

for (const partial of [
  { name: 'partial status', status: 'partial', gaps: [] },
  { name: 'explicit gaps', status: 'ready', gaps: ['competitor_row_missing'] },
]) {
  test(`fixed task ${partial.name} keeps verified counts and warns in the actual Flow and result panel`, async () => {
    const h = harness(), response = taskResponse(partial);
    const { pending } = await begin(h);
    h.requests[0].resolve(response);
    const outcome = await pending;
    const result = h.refs.ctripFlowOverviewResult.value;
    assert.equal(outcome.status, 'partial');
    assert.equal(result.ui_flow_status, 'partial');
    assert.equal(result.status, partial.status);
    assert.deepEqual(plain(result.gaps), partial.gaps);
    assert.equal(result.saved_count, 1);
    assert.equal(result.readback_verified, true);
    assert.equal(result.persistence_status, 'readback_verified');
    assert.equal(h.notices.at(-1)[1], 'warning');
    assert.match(h.notices.at(-1)[0], /部分|缺失|未返回|不可计算/);
    assert.match(h.notices.at(-1)[0], /不计.*零/);
    const view = h.api.resultView(result);
    assert.equal(view.tone, 'warning');
    assert.equal(view.title, '部分指标已入库并回读验证');
    assert.match(view.detail, /1/);
    assert.match(view.detail, /不计.*零/);
    assert.equal(view.showMetrics, true);
    assert.deepEqual(plain(h.full.buildCtripFlowOverviewMetricCards(result)).map(card => [card.key, card.value]), [
      ['self_list_exposure', 0],
    ]);
  });
}

test('complete fixed task retains its successful persistence result without a partial warning', async () => {
  const h = harness(), response = taskResponse({ status: 'ready', gaps: [] });
  const { pending } = await begin(h);
  h.requests[0].resolve(response);
  assert.equal((await pending).status, 'success');
  assert.equal(h.notices.at(-1)[1], 'success');
  assert.equal(h.api.resultView(h.refs.ctripFlowOverviewResult.value).tone, 'success');
});

test('legacy overview partial metadata keeps its existing explicit URL and result behavior', async () => {
  const h = harness(), response = taskResponse({ requestSource: '' });
  const { pending } = await begin(h, h.api.legacy);
  h.requests[0].resolve(response);
  assert.equal((await pending).status, 'success');
  assert.equal(h.notices.at(-1)[1], 'success');
  assert.equal(h.api.resultView(h.refs.ctripOverviewResult.value).tone, 'success');
  assert.deepEqual(h.requests[0].body.request_urls, [explicitUrl]);
  assert.equal(h.requests[0].body.hotel_name, 'Synthetic hotel 701');
});

test('a fixed task save failure from the request error envelope stays failed and hides metrics', async () => {
  const h = harness(), response = taskResponse();
  Object.assign(response, { code: 500, message: '携程流量概览已返回，但数据未全部通过保存回读，请重试' });
  Object.assign(response.data, { status: 'error', saved_count: 0, readback_verified: false,
    persistence_status: 'readback_not_verified' });
  const { pending } = await begin(h);
  h.requests[0].reject(Object.assign(new Error(response.message), { data: response }));
  assert.equal((await pending).status, 'exception');
  const result = h.refs.ctripFlowOverviewResult.value;
  assert.equal(result.readback_verified, false);
  assert.equal(result.saved_count, 0);
  assert.equal(h.notices.at(-1)[1], 'error');
  const view = h.api.resultView(result);
  assert.equal(view.tone, 'error');
  assert.equal(view.showMetrics, false);
  assert.match(view.detail, /未全部通过保存回读/);
});

test('fixed flow-overview task takes platform hotel identity from active config despite a stale form value', async () => {
  const h = harness({ explicit: true, syncFormHotel: false, form: { hotelId: 'ota-stale-other-hotel' } });
  const { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  assertTaskBody(h.requests[0]);
});

test('flow-overview rejects a stale configuration fallback when the selected hotel has no config', async () => {
  const h = harness({ missingSelectedConfig: true, fallbackConfig: {
    config_id: 'synthetic-701', system_hotel_id: '701', ota_hotel_id: 'ota-701',
    credential_status: 'ready', has_cookies: true,
  } });
  h.refs.selectedCtripHotelId.value = '702';
  const { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'missing_config');
  assert.equal(h.requests.length, 0, 'the old config must not be combined with the new system hotel');
  assert.equal(h.refs.selectedCtripHotelId.value, '702', 'old config application must not switch the hotel back');
  assert.deepEqual(h.effects, [], 'reject before applying config or mutating loading/results');
  assert.equal(h.refs.ctripFlowOverviewFetching.value, false);
  assert.equal(h.refs.fetchingData.value, false);
  assert.ok(h.notices.some(([, level]) => level === 'warning'));
});

test('legacy overview caller keeps explicit request URLs and method without new hooks or task mode', async () => {
  const h = harness(), { pending } = await begin(h, h.api.legacy);
  assert.equal((await finish(h, pending)).status, 'success');
  assert.deepEqual(h.requests[0].body, {
    config_id: 'synthetic-701', system_hotel_id: '701', hotel_id: 'ota-701', hotel_name: 'Synthetic hotel 701',
    request_urls: [explicitUrl], method: 'GET', data_date: '2026-09-26',
  });
});

test('flow-overview requires its business date even for a fixed task', async () => {
  const h = harness({ form: { dataDate: '' } });
  assert.equal((await h.api.run()).status, 'missing_data_date');
  assert.equal(h.requests.length, 0);
  assert.equal(h.refs.ctripFlowOverviewFetching.value, false);
  assert.equal(h.refs.fetchingData.value, false);
});

for (const [scope, outcome] of [['auth', 'exception'], ['hotel', 'success'], ['hotel-A-B-A', 'failed']]) {
  test(`actual flow-overview ignores ${scope} stale ${outcome} without results, error feedback, or refresh`, async () => {
    const h = harness({ explicit: true }), { pending } = await begin(h);
    assert.equal(h.requests.length, 1);
    h.switchScope(scope);
    const before = h.snapshot();
    h.effects.length = h.notices.length = 0;
    assert.equal((await finish(h, pending, outcome)).status, 'stale');
    assert.deepEqual(h.snapshot(), before);
    assert.deepEqual(h.effects, []);
    assert.deepEqual(h.notices, []);
    assert.equal(h.timers.size, 0);
  });
}

test('real flow clear releases its lock so a new hotel owner can start before the old response', async () => {
  const h = harness({ explicit: true }), first = await begin(h);
  h.switchScope('hotel');
  assert.equal(h.refs.ctripFlowOverviewFetching.value, false);
  assert.equal(h.refs.fetchingData.value, false);
  const second = await begin(h);
  assert.equal(h.requests.length, 2);
  const before = h.snapshot();
  h.effects.length = h.notices.length = 0;
  assert.equal((await finish(h, first.pending, 'exception', 0)).status, 'stale');
  assert.deepEqual(h.snapshot(), before);
  assert.deepEqual(h.effects, []);
  assert.deepEqual(h.notices, []);
  assert.equal((await finish(h, second.pending, 'success', 1)).status, 'success');
  assertTaskBody(h.requests[1], '702');
});

for (const [name, form] of [['valid form', {}], ['missing date', { dataDate: '' }]]) {
  test(`flow-overview leaves another capture's global busy untouched for ${name}`, async () => {
    const h = harness({ explicit: true, form });
    h.refs.fetchingData.value = true;
    const before = h.snapshot();
    h.effects.length = 0;
    const { pending } = await begin(h);
    assert.equal((await finish(h, pending)).status, 'busy');
    assert.deepEqual(h.snapshot(), before);
    assert.equal(h.requests.length, 0);
    assert.deepEqual(h.effects, []);
    assert.deepEqual(h.notices, []);
  });
}

test('duplicate flow-overview click retains the original owner and only one POST', async () => {
  const h = harness({ explicit: true }), first = await begin(h);
  const second = await begin(h);
  assert.equal((await finish(h, second.pending, 'success', 1)).status, 'busy');
  assert.equal(h.requests.length, 1);
  assert.equal((await finish(h, first.pending, 'success', 0)).status, 'success');
});

for (const outcome of ['failed', 'exception']) {
  test(`current flow-overview ${outcome} stays visible and permits a successful retry`, async () => {
    const h = harness({ explicit: true }), first = await begin(h);
    assert.equal((await finish(h, first.pending, outcome)).status, outcome);
    assert.equal(h.refs.ctripFlowOverviewResult.value.ui_flow_status, outcome);
    assert.equal(h.refs.ctripFlowOverviewFetching.value, false);
    assert.equal(h.refs.fetchingData.value, false);
    assert.equal(h.timers.size, 0);
    assert.ok(h.notices.length > 0);
    const second = await begin(h);
    assert.equal((await finish(h, second.pending)).status, 'success');
  });
}

test('current flow-overview preserves actual history/latest scheduler delays and arguments', async () => {
  const h = harness({ explicit: true }), { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  assert.deepEqual([...h.timers.values()].map(row => row.delay).sort((a, b) => a - b), [340, 420]);
  h.fireTimers();
  assert.equal(h.uiTasks.length, 2);
  assert.deepEqual(h.refreshes, []);
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, expectedRefreshes());
});

for (const scope of ['auth', 'hotel', 'hotel-A-B-A']) {
  for (const stage of ['timer', 'defer']) {
    test(`completed flow-overview does not refresh after ${scope} changes during ${stage}`, async () => {
      const h = harness({ explicit: true }), { pending } = await begin(h);
      assert.equal((await finish(h, pending)).status, 'success');
      if (stage === 'defer') h.fireTimers();
      h.switchScope(scope);
      h.fireTimers();
      await h.runUiTasks();
      assert.deepEqual(h.refreshes, []);
    });
  }
}

test('new flow-overview request invalidates already deferred reads from the prior request', async () => {
  const h = harness({ explicit: true }), first = await begin(h);
  await finish(h, first.pending);
  h.fireTimers();
  const second = await begin(h), before = h.snapshot();
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, []);
  assert.deepEqual(h.snapshot(), before);
  assert.equal((await finish(h, second.pending)).status, 'success');
});

test('changing the business date after completion cancels already deferred refreshes', async () => {
  const h = harness({ explicit: true }), { pending } = await begin(h);
  assert.equal((await finish(h, pending)).status, 'success');
  assert.equal(h.refs.ctripFlowOverviewFetching.value, false);
  h.fireTimers();
  assert.equal(h.uiTasks.length, 2);
  h.refs.ctripFlowOverviewForm.value.dataDate = '2026-09-28';
  await h.runUiTasks();
  assert.deepEqual(h.refreshes, []);
  assert.equal(h.refs.fetchingData.value, false);
});

test('clearing inactive flow-overview preserves unrelated global busy', () => {
  const h = harness();
  h.refs.fetchingData.value = true;
  h.api.clear();
  assert.equal(h.refs.fetchingData.value, true);
});
