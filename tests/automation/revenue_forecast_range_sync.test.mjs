import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_FORECAST_RANGE_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const take = name => {
  const start = main.indexOf(`            const ${name} =`);
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start >= 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const production = ['captureAuthSession', 'isAuthSessionCurrent', 'captureAgentRevenueRequestContext',
  ...(main.includes('            const applyRevenueAiOverviewReadback =') ? ['applyRevenueAiOverviewReadback'] : []),
  ...(main.includes('            const isRevenueForecastRangeCurrent =') ? ['isRevenueForecastRangeCurrent'] : []),
  ...(main.includes('            const captureRevenueForecastRange =') ? ['captureRevenueForecastRange'] : []),
  'isAgentRevenueRequestCurrent', 'createPriceSuggestionPagination', 'createRevenueLoadState',
  'createEmptyRevenueAnalysisData', 'createEmptyRevenueDashboard', 'firstEnabledRoomTypeId',
  'manualCtripPricingInputMeta', 'createCompetitorPriceForm', 'resetCompetitorPriceForm',
  'applyRoomTypeReadback', 'applyDemandForecastReadback', 'applyRevenueDashboardReadback', 'applyRevenueAnalysisReadback',
  'setRevenueLoadState', 'syncRevenuePricingInputDate', 'loadDemandForecasts',
  'loadRevenueAnalysisBundle', 'saveCompetitorPriceInput',
].map(take).join('\n');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const outcomes = ['success', 'failed', 'throw'];
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };

// The date-sync, forecast reader, bundle, competitor save and form-reset chain
// are production functions with real Vue refs/state tokens. Other save refreshes
// and overview/competitor display are closed boundaries; all HTTP is deferred.
function harness() {
  const refs = {}, requests = [], notices = [], boundaries = [], ensureGates = [];
  for (const match of production.matchAll(/\b(\w+)\.value/g)) refs[match[1]] ??= Vue.ref(null);
  const initial = {
    token: 'synthetic-session', filterReportHotel: '81', revenueAiBusinessDate: '2026-09-12',
    priceSuggestionFilter: { date: '2026-09-12', end_date: '2026-09-13', status: 0 },
    priceSuggestionPagination: { page: 1, page_size: 20 },
    forecastFilter: { start_date: '2026-09-12', end_date: '2026-09-13' },
    competitorFilter: { date: '2026-09-12' },
    demandForecastForm: { room_type_id: 501, forecast_date: '2026-10-01', predicted_demand: 0 },
    competitorPriceForm: { room_type_id: 501, analysis_date: '2026-09-15', competitor_hotel_id: 0,
      competitor_name: 'Synthetic competitor', our_price: 291, competitor_price: 287 },
    competitorPriceSaving: false, competitorAnalysisError: '',
    roomTypeConfigList: [{ id: 501, is_enabled: 1 }], roomTypeConfigMeta: {},
    demandForecasts: [], forecastAccuracy: {}, highDemandDates: [],
    priceSuggestions: [], revenueAiOverviewLoading: false, revenueAiOverviewError: '',
  };
  for (const [key, value] of Object.entries(initial)) refs[key] = Vue.ref(value);
  const boundary = name => async () => { boundaries.push(name); };
  const context = vm.createContext({ ...refs, URLSearchParams, console: { error() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1, revenueAnalysisBundleRequestSeq: 0, priceSuggestionRequestSeq: 0,
    revenueAiOverviewRequestSeq: 0, revenueAiOverviewRequestPromises: new Map(),
    ensureRevenueAiStaticReady: async () => { const gate = ensureGates.shift(); if (gate) await gate.promise; return true; },
    formatDate: () => '2026-09-12', resetCompetitorAnalysisView() {},
    loadRevenueAnalysis: boundary('analysis'), loadRevenueDashboard: boundary('dashboard'),
    loadRevenueAiOverview: boundary('overview'), loadPriceSuggestions: boundary('prices'),
    loadCompetitorAnalysis: async (options = {}) => { boundaries.push('competitor'); await options.priceResponsePromise?.catch(() => null); },
    revenueAiResolveOverviewResponse: ({ response }) => ({ overview: response.data, errorMessage: '' }),
    showToast: (...args) => notices.push(args),
    request: (url, options = {}) => {
      const parsed = new URL(url, 'https://synthetic.invalid');
      assert.ok(['/agent/revenue-bundle', '/agent/demand-forecasts', '/agent/competitor-analysis'].includes(parsed.pathname));
      assert.equal(options.method || 'GET', parsed.pathname === '/agent/competitor-analysis' ? 'POST' : 'GET');
      const transport = deferred();
      requests.push({ ...transport, path: parsed.pathname, params: parsed.searchParams, options, settled: false });
      return transport.promise;
    },
  });
  vm.runInContext(`${production}\nrevenueLoadState.value=createRevenueLoadState();
    globalThis.api={sync:syncRevenuePricingInputDate,forecasts:loadDemandForecasts,
      bundle:loadRevenueAnalysisBundle,save:saveCompetitorPriceInput,setState:setRevenueLoadState};`, context);
  const state = () => refs.revenueLoadState.value.forecasts;
  const snapshot = () => copy({ state: state(), forecasts: refs.demandForecasts.value,
    accuracy: refs.forecastAccuracy.value, highDemand: refs.highDemandDates.value });
  const reply = (row, outcome = 'success', marker = 'current') => {
    assert.ok(row && !row.settled, 'one pending synthetic transport'); row.settled = true;
    if (outcome === 'throw') return row.reject(new Error(`synthetic ${marker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${marker} failure` });
    if (row.path === '/agent/competitor-analysis') return row.resolve({ code: 200, data: { id: 9001 } });
    const empty = outcome === 'empty';
    const forecasts = { forecasts: empty ? [] : [{ id: 1001, marker, forecast_date: row.params.get('start_date'),
      predicted_demand: 0, predicted_occupancy: 0, historical_data: { input_type: 'manual_demand_forecast' } }],
      accuracy: empty ? {} : { marker, mae: 0 }, high_demand_dates: empty ? [] : [{ marker }] };
    row.resolve({ code: 200, data: row.path === '/agent/revenue-bundle' ? {
      forecasts, dashboard: {}, analysis: {}, room_types: { list: [] }, price_suggestions: { list: [], pagination: {} }, competitor: {}, overview: {},
    } : forecasts });
  };
  const changeScope = scope => {
    if (scope === 'hotel') refs.filterReportHotel.value = '82';
    else if (scope === 'session') context.authSessionEpoch++;
    else if (scope === 'epoch') context.agentRevenueStateEpoch++;
    else if (scope === 'start-date') refs.forecastFilter.value.start_date = '2026-09-11';
    else if (scope === 'end-date') refs.forecastFilter.value.end_date = '2026-09-14';
  };
  return { api: context.api, context, refs, requests, notices, boundaries, state, snapshot, reply, changeScope,
    gateEnsure: () => { const gate = deferred(); ensureGates.push(gate); return gate; } };
}

async function begin(h, kind) {
  const start = h.requests.length, operation = { pending: null, done: false };
  operation.pending = h.api[kind]().then(value => { operation.done = true; return value; });
  await flush(); operation.rows = h.requests.slice(start); operation.row = operation.rows[0];
  return operation;
}
async function ready(h, marker = 'old-ready') {
  const operation = await begin(h, 'forecasts'); h.reply(operation.row, 'success', marker); await operation.pending;
}
function assertIdle(h) {
  assert.equal(h.state().status, 'idle', 'range invalidation is unread, not an empty successful read');
  assert.deepEqual(copy(h.refs.demandForecasts.value), []);
  assert.deepEqual(copy(h.refs.forecastAccuracy.value), {});
  assert.deepEqual(copy(h.refs.highDemandDates.value), []);
}

for (const target of ['2026-09-11', '2026-09-15']) {
  test(`sync to ${target} clears old ready facts and returns a changed range`, async () => {
    const h = harness(); await ready(h); const owner = h.state();
    assert.equal(h.api.sync(target), true);
    assertIdle(h); assert.notEqual(h.state(), owner);
    assert.equal(h.refs.forecastFilter.value.start_date, target);
    assert.equal(h.refs.forecastFilter.value.end_date, target > '2026-09-13' ? target : '2026-09-13');
    assert.equal(h.refs.demandForecastForm.value.forecast_date, target);
    assert.equal(h.refs.competitorPriceForm.value.analysis_date, target);
  });
}

test('syncDraftDates false preserves both drafts while revoking old range facts', async () => {
  const h = harness(); await ready(h);
  const demandDraft = copy(h.refs.demandForecastForm.value), competitorDraft = copy(h.refs.competitorPriceForm.value);
  assert.equal(h.api.sync('2026-09-16', { syncDraftDates: false }), true);
  assertIdle(h);
  assert.deepEqual(copy(h.refs.demandForecastForm.value), demandDraft);
  assert.deepEqual(copy(h.refs.competitorPriceForm.value), competitorDraft);
  assert.equal(h.refs.competitorFilter.value.date, '2026-09-16');
});

for (const initial of ['ready', 'pending']) {
  test(`same-range synchronization retains ${initial} facts and owner identity`, async () => {
    const h = harness(); let current;
    if (initial === 'ready') await ready(h); else current = await begin(h, 'forecasts');
    const expected = h.snapshot(), owner = h.state();
    assert.equal(Boolean(h.api.sync('2026-09-12', { syncDraftDates: false })), false);
    assert.equal(h.state(), owner); assert.deepEqual(h.snapshot(), expected);
    if (current) { h.reply(current.row, 'success', 'same-range'); await current.pending; assert.equal(h.state().status, 'ready'); }
  });
}

test('missing synchronization date leaves the current range and evidence unchanged', async () => {
  const h = harness(); await ready(h); const expected = h.snapshot(), range = copy(h.refs.forecastFilter.value);
  h.api.sync(''); assert.deepEqual(h.snapshot(), expected); assert.deepEqual(copy(h.refs.forecastFilter.value), range);
});

for (const source of ['forecasts', 'bundle']) {
  for (const outcome of outcomes) {
    test(`${source} pending ${outcome} cannot restore or fail forecasts after range sync`, async () => {
      const h = harness(), old = await begin(h, source);
      h.api.sync('2026-09-15', { syncDraftDates: false });
      const owner = h.state(); h.reply(old.row, outcome, 'old-range'); await old.pending;
      assertIdle(h); assert.equal(h.state(), owner);
      if (source === 'forecasts') assert.equal(h.notices.length, 0, 'stale forecast errors are silent');
      else if (outcome !== 'success') assert.match(h.refs.revenueAiOverviewError.value, /old-range/, 'other bundle failures may remain visible');
    });
  }
}

for (const outcome of outcomes) {
  for (const completion of ['new-first', 'old-first']) {
    test(`new forecast re-read survives old bundle ${outcome}, ${completion}`, async () => {
      const h = harness(), old = await begin(h, 'bundle');
      h.api.sync('2026-09-15'); const current = await begin(h, 'forecasts');
      if (completion === 'new-first') { h.reply(current.row, 'success', 'new-range'); await current.pending; }
      const expected = h.snapshot(), owner = h.state();
      h.reply(old.row, outcome, 'old-range'); await old.pending;
      assert.equal(h.state(), owner); assert.deepEqual(h.snapshot(), expected);
      if (completion === 'old-first') { h.reply(current.row, 'success', 'new-range'); await current.pending; }
      assert.equal(h.refs.demandForecasts.value[0].marker, 'new-range');
      assert.equal(h.refs.demandForecasts.value[0].forecast_date, '2026-09-15');
    });
  }
}

for (const outcome of outcomes) {
  test(`sync during helper wait revokes old bundle forecasts even when ${outcome} uses the new dispatch range`, async () => {
    const h = harness(), gate = h.gateEnsure(), old = await begin(h, 'bundle');
    assert.equal(old.rows.length, 0); h.api.sync('2026-09-15'); const owner = h.state();
    gate.resolve(); await flush(); const row = h.requests[0];
    assert.equal(row.params.get('start_date'), '2026-09-15'); assert.equal(row.params.get('end_date'), '2026-09-15');
    h.reply(row, outcome, 'revoked-bundle'); await old.pending;
    assertIdle(h); assert.equal(h.state(), owner);
  });
}

for (const date of ['start-date', 'end-date']) {
  test(`bundle actual dispatch ${date} guards forecast writes even without a sync token replacement`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), old = await begin(h, 'bundle'); h.changeScope(date);
      const expected = h.snapshot(), owner = h.state(); h.reply(old.row, outcome, 'old-dispatch'); await old.pending;
      assert.equal(h.state(), owner); assert.deepEqual(h.snapshot(), expected);
    }
  });
}

for (const outcome of ['success', 'empty', 'failed', 'throw']) {
  test(`cross-date competitor save waits for its real forecast ${outcome} and preserves manual-source metadata`, async () => {
    const h = harness(); await ready(h); const demandDraft = copy(h.refs.demandForecastForm.value);
    const save = await begin(h, 'save'); const payload = JSON.parse(save.row.options.body);
    assert.equal(payload.hotel_id, 81); assert.equal(payload.analysis_date, '2026-09-15');
    assert.equal(payload.ota_platform, 1); assert.equal(payload.competitor_data.auto_write_ota, false);
    assert.equal(payload.competitor_data.source_scope, 'ctrip_ota_channel');
    assert.equal(payload.competitor_data.input_type, 'manual_ctrip_competitor_price_sample');
    h.reply(save.row); await flush();
    const reads = h.requests.filter(row => row.path === '/agent/demand-forecasts' && !row.settled);
    assert.equal(reads.length, 1, 'save-date change must perform one real forecast read');
    const forecast = reads[0];
    assert.equal(forecast.params.get('hotel_id'), '81');
    assert.equal(forecast.params.get('start_date'), '2026-09-15'); assert.equal(forecast.params.get('end_date'), '2026-09-15');
    assert.equal(h.state().status, 'loading'); assert.equal(save.done, false); assert.equal(h.refs.competitorPriceSaving.value, true);
    assert.deepEqual(copy(h.refs.demandForecastForm.value), demandDraft);
    h.reply(forecast, outcome, 'saved-date'); await save.pending;
    assert.equal(h.refs.competitorPriceSaving.value, false); assert.equal(save.done, true);
    assert.equal(h.state().status, outcome === 'success' ? 'ready' : outcome === 'empty' ? 'empty' : 'failed');
    if (outcome === 'success') {
      assert.equal(h.refs.demandForecasts.value[0].predicted_demand, 0);
      assert.equal(h.refs.demandForecasts.value[0].predicted_occupancy, 0);
      assert.equal(h.refs.forecastAccuracy.value.mae, 0);
    } else if (outcome === 'failed' || outcome === 'throw') {
      assert.match(h.state().error, /saved-date/);
      const retry = await begin(h, 'forecasts'); h.reply(retry.row, 'success', 'manual-retry'); await retry.pending;
      assert.equal(h.state().status, 'ready'); assert.equal(h.refs.demandForecasts.value[0].marker, 'manual-retry');
    }
  });
}

test('same-range competitor save preserves ready predictions and adds no forecast request', async () => {
  const h = harness(); await ready(h); h.refs.competitorPriceForm.value.analysis_date = '2026-09-12';
  const expected = h.snapshot(), owner = h.state(), count = h.requests.length;
  const save = await begin(h, 'save'); h.reply(save.row); await save.pending;
  assert.equal(h.requests.length, count + 1); assert.equal(h.state(), owner); assert.deepEqual(h.snapshot(), expected);
  assert.equal(h.refs.competitorPriceSaving.value, false);
  assert.deepEqual(h.boundaries.sort(), ['analysis', 'competitor', 'dashboard', 'overview', 'prices']);
});

for (const outcome of ['failed', 'throw']) {
  test(`competitor save ${outcome} does not change range or trigger forecast read`, async () => {
    const h = harness(); await ready(h); const expected = h.snapshot(), range = copy(h.refs.forecastFilter.value);
    const save = await begin(h, 'save'); h.reply(save.row, outcome, 'save-failure'); await save.pending;
    assert.deepEqual(h.snapshot(), expected); assert.deepEqual(copy(h.refs.forecastFilter.value), range);
    assert.equal(h.requests.filter(row => row.path === '/agent/demand-forecasts').length, 1);
    assert.equal(h.refs.competitorPriceSaving.value, false);
  });
}

for (const scope of ['hotel', 'session', 'epoch', 'start-date', 'end-date']) {
  test(`forecast ${scope} isolation survives the save-triggered read for every old outcome`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), save = await begin(h, 'save'); h.reply(save.row); await flush();
      const forecast = h.requests.find(row => row.path === '/agent/demand-forecasts');
      assert.ok(forecast, 'actual save-triggered forecast reader'); h.changeScope(scope);
      const expected = h.snapshot(), notices = h.notices.length;
      h.reply(forecast, outcome, 'old-scope'); await save.pending;
      assert.deepEqual(h.snapshot(), expected); assert.equal(h.notices.length, notices);
    }
  });
}

test('an old-session competitor POST cannot synchronize the new session or start any read', async () => {
  const h = harness(), save = await begin(h, 'save'); h.changeScope('session');
  const range = copy(h.refs.forecastFilter.value), expected = h.snapshot(); h.reply(save.row); await save.pending;
  assert.equal(h.requests.length, 1); assert.deepEqual(copy(h.refs.forecastFilter.value), range);
  assert.deepEqual(h.snapshot(), expected); assert.equal(h.notices.length, 0);
});
