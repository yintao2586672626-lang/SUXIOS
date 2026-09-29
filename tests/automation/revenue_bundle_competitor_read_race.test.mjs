import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_REVENUE_COMPETITOR_READ_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const declaration = name => {
  const start = main.indexOf(`            const ${name} =`);
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start >= 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const production = ['captureAuthSession', 'isAuthSessionCurrent',
  ...['applyRevenueAiOverviewReadback', 'captureRevenueForecastRange', 'isRevenueForecastRangeCurrent'].filter(name => main.includes('            const ' + name + ' =')),
  ...['applyRevenueDashboardReadback', 'applyRevenueAnalysisReadback'].filter(name => main.includes('            const ' + name + ' =')),
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent',
  'createPriceSuggestionPagination', 'createRevenueLoadState', 'createEmptyRevenueAnalysisData',
  'createEmptyRevenueDashboard', 'firstEnabledRoomTypeId', 'applyRoomTypeReadback',
  'applyDemandForecastReadback', 'emptyCompetitorAnalysis', 'resetCompetitorAnalysisView',
  'setRevenueLoadState', 'loadCompetitorAnalysis', 'loadRevenueAnalysisBundle',
].map(declaration).join('\n');
const staticSources = ['revenue-overview-contract-static.js', 'revenue-ai-static.js']
  .map(file => readFileSync(new URL(`../../public/${file}`, import.meta.url), 'utf8'));
const copy = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};

// Real readers, resets, guards, Vue refs, state replacement and full static
// normalizer/microscope. Only transport and unrelated overview resolution close
// over synthetic fixtures; no competitor reader or normalizer is stubbed.
function harness() {
  const refs = {}, requests = [], notices = [], ensureGates = [];
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) refs[match[1]] ??= Vue.ref(null);
  const initial = {
    token: 'synthetic-session', filterReportHotel: '81', revenueAiBusinessDate: '2026-09-12',
    priceSuggestionFilter: { date: '2026-09-12', end_date: '2026-09-13', status: 0 },
    priceSuggestionPagination: { total: 0, page: 1, page_size: 20, total_page: 1 },
    forecastFilter: { start_date: '2026-09-12', end_date: '2026-09-13' },
    competitorFilter: { date: '2026-09-12' }, demandForecastForm: { room_type_id: 0 },
    competitorPriceForm: { room_type_id: 0 }, priceSuggestions: [], priceSuggestionReview: null,
    roomTypeConfigList: [], roomTypeConfigMeta: {}, demandForecasts: [], forecastAccuracy: {},
    highDemandDates: [], revenueAiOverviewLoading: false, revenueAiOverviewError: '',
    competitorAnalysisLoading: false, competitorAnalysisError: '', competitorMicroscopeSelectedKey: '',
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = Vue.ref(value);
  const context = vm.createContext({ ...refs, window: {}, URLSearchParams, console: { error() {}, warn() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1, competitorAnalysisRequestSeq: 0,
    priceSuggestionRequestSeq: 0, revenueAnalysisBundleRequestSeq: 0,
    revenueAiOverviewRequestSeq: 0, revenueAiOverviewRequestPromises: new Map(),
    loadRevenueAiOverview: async () => null, formatDate: () => '2026-09-12',
    revenueAiResolveOverviewResponse: ({ response }) => ({ overview: response.data, errorMessage: '' }),
    showToast: (...args) => notices.push(args),
    request: url => {
      const parsed = new URL(url, 'https://synthetic.invalid');
      assert.ok(['/agent/revenue-bundle', '/agent/competitor-analysis', '/online-data/competitor-summary'].includes(parsed.pathname));
      const transport = deferred();
      requests.push({ ...transport, url, path: parsed.pathname, params: parsed.searchParams, settled: false });
      return transport.promise;
    },
  });
  for (const source of staticSources) vm.runInContext(source, context);
  const helpers = context.window.SUXI_REVENUE_AI_STATIC;
  context.revenueAiNormalizeMeituanCompetitionCircle = helpers.normalizeMeituanCompetitionCircle;
  context.revenueAiBuildCompetitorMicroscope = helpers.buildCompetitorMicroscope;
  context.ensureRevenueAiStaticReady = async () => {
    const gate = ensureGates.shift();
    if (gate) await gate.promise;
    return helpers;
  };
  vm.runInContext(`${production}
    revenueLoadState.value = createRevenueLoadState();
    competitorAnalysis.value = emptyCompetitorAnalysis('81', '2026-09-12');
    globalThis.api = { competitor: loadCompetitorAnalysis, bundle: loadRevenueAnalysisBundle };`, context);
  const state = () => refs.revenueLoadState.value.competitor;
  const snapshot = () => copy({ analysis: refs.competitorAnalysis.value, state: state(),
    error: refs.competitorAnalysisError.value, busy: refs.competitorAnalysisLoading.value,
    selection: refs.competitorMicroscopeSelectedKey.value });
  const microscope = () => helpers.buildCompetitorMicroscope(refs.competitorAnalysis.value, refs.competitorMicroscopeSelectedKey.value);
  const settle = (row, outcome = 'success', marker = 'current', overrides = {}) => {
    assert.ok(row && !row.settled, 'one pending synthetic transport');
    row.settled = true;
    if (outcome === 'throw') return row.reject(new Error(`synthetic ${marker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${marker} failure` });
    const hotel = row.params.get('hotel_id');
    const date = row.params.get('date') || row.params.get('competitor_date') || row.params.get('target_date');
    const competitor = { date, query_scope: { hotel_id: Number(hotel), date, metric_scope: 'ota_channel' },
      price_matrix: { 'Synthetic room': [{ id: 1, hotel_id: Number(hotel), analysis_date: date,
        competitor_hotel_id: 901, competitor_name: `${marker} Ctrip competitor`, ota_platform: 1,
        ota_platform_name: '携程', our_price: 291, competitor_price: 287, difference: 4, diff_percent: 1.39,
        competitor_data: { input_type: 'manual_ctrip_competitor_price_sample', evidence_status: 'operator_provided', source_scope: 'ctrip_ota_channel' } }] },
      alerts: [], trends: {}, marker };
    let data;
    if (row.path === '/online-data/competitor-summary') {
      data = outcome === 'empty'
        ? { data_status: 'missing', system_hotel_id: Number(hotel), target_date: date, display_hotels: [] }
        : { data_status: 'success', system_hotel_id: Number(hotel), latest_data_date: date,
          display_hotels: [{ poiId: `synthetic-${marker}`, hotelName: `${marker} Meituan competitor`, isSelf: false }] };
    } else if (row.path === '/agent/revenue-bundle') {
      data = { overview: { marker }, analysis: { statistics: { marker } }, dashboard: {},
        room_types: { list: [] }, forecasts: { forecasts: [], accuracy: {}, high_demand_dates: [] },
        price_suggestions: { list: [], pagination: {} }, competitor };
    } else data = competitor;
    row.resolve({ code: 200, data: { ...data, ...overrides } });
  };
  const changeScope = scope => {
    if (scope === 'hotel') refs.filterReportHotel.value = '82';
    else if (scope === 'date') refs.competitorFilter.value.date = '2026-09-11';
    else if (scope === 'session') context.authSessionEpoch++;
    else if (scope === 'epoch') context.agentRevenueStateEpoch++;
  };
  return { refs, requests, notices, context, api: context.api, state, snapshot, microscope, settle, changeScope,
    gateEnsure: () => { const gate = deferred(); ensureGates.push(gate); return gate; } };
}

async function begin(h, kind) {
  const start = h.requests.length;
  const operation = { done: false, pending: null, rows: null };
  operation.pending = h.api[kind]().then(value => { operation.done = true; return value; });
  await flush();
  operation.rows = h.requests.slice(start);
  operation.bundle = operation.rows.find(row => row.path === '/agent/revenue-bundle');
  operation.price = operation.rows.find(row => row.path === '/agent/competitor-analysis');
  operation.meituan = operation.rows.find(row => row.path === '/online-data/competitor-summary');
  return operation;
}

async function completeIndependent(h, operation, marker = 'current') {
  h.settle(operation.price, 'success', marker);
  h.settle(operation.meituan, 'success', marker);
  await operation.pending;
}

function assertMeituanPartial(h, marker) {
  assert.equal(h.state().status, 'ready');
  assert.equal(h.refs.competitorAnalysisLoading.value, false);
  assert.equal(h.refs.competitorAnalysisError.value, '');
  assert.deepEqual(copy(h.refs.competitorAnalysis.value.price_matrix), {});
  assert.match(h.refs.competitorAnalysis.value.source_errors.ctrip, /synthetic/);
  assert.equal(h.refs.competitorAnalysis.value.meituan_competition_circle.display_hotels[0].hotelName, `${marker} Meituan competitor`);
  const view = h.microscope();
  assert.equal(view.platformStatuses.find(row => row.platformKey === 'ctrip').status, 'error');
  assert.equal(view.platformStatuses.find(row => row.platformKey === 'meituan').status, 'partial', 'fixture does not claim verified readiness');
  assert.equal(view.status, 'partial');
  assert.ok(view.options.some(row => row.platformKey === 'meituan'));
  assert.match(h.refs.competitorMicroscopeSelectedKey.value, /platform:meituan/);
}

for (const outcome of ['failed', 'throw']) {
  for (const order of ['meituan-first', 'bundle-first']) {
    test(`bundle ${outcome}, ${order}: accepted Meituan evidence survives with Ctrip error`, async () => {
      const h = harness(), operation = await begin(h, 'bundle');
      assert.equal(operation.rows.length, 2, 'bundle shares its own price response with the real competitor reader');
      if (order === 'meituan-first') h.settle(operation.meituan, 'success', 'partial');
      h.settle(operation.bundle, outcome, 'bundle');
      await flush();
      const beforeMeituan = { done: operation.done, busy: h.refs.competitorAnalysisLoading.value, status: h.state().status };
      if (order === 'bundle-first') {
        h.settle(operation.meituan, 'success', 'partial');
      }
      await operation.pending; await flush();
      if (order === 'bundle-first') {
        assert.equal(beforeMeituan.done, outcome === 'throw', 'only transport rejection may end the bundle before its child');
        assert.equal(beforeMeituan.busy, true, 'a legal source remains pending');
        assert.equal(beforeMeituan.status, 'loading');
      }
      assertMeituanPartial(h, 'partial');
      assert.equal(h.refs.revenueAiOverviewLoading.value, false);
      assert.equal(h.refs.revenueLoadState.value.bundle.status, 'failed');
    });
  }

  test(`both sources fail after bundle ${outcome}: persistent error survives and independent retry recovers`, async () => {
    const h = harness(), operation = await begin(h, 'bundle');
    h.settle(operation.meituan, 'failed', 'meituan');
    h.settle(operation.bundle, outcome, 'bundle');
    await operation.pending; await flush();
    assert.equal(h.state().status, 'failed');
    assert.match(h.refs.competitorAnalysisError.value, /bundle.*meituan/);
    assert.equal(h.refs.competitorAnalysisLoading.value, false);
    const retry = await begin(h, 'competitor');
    await completeIndependent(h, retry, 'retry');
    assert.equal(h.state().status, 'ready');
    assert.equal(h.refs.competitorAnalysisError.value, '');
    assert.deepEqual(copy(h.refs.competitorAnalysis.value.source_errors), {});
    assert.equal(h.refs.competitorAnalysis.value.marker, 'retry');
  });

  for (const newer of ['pending', 'ready']) {
    test(`old bundle ${outcome} preserves newer independent ${newer} reader and its seq`, async () => {
      const h = harness(), old = await begin(h, 'bundle'), current = await begin(h, 'competitor');
      if (newer === 'ready') await completeIndependent(h, current, 'new-owner');
      const expected = h.snapshot(), seq = h.context.competitorAnalysisRequestSeq, owner = h.state();
      h.settle(old.meituan, 'success', 'old-meituan');
      h.settle(old.bundle, outcome, 'old-bundle');
      await old.pending; await flush();
      assert.equal(h.context.competitorAnalysisRequestSeq, seq, 'old bundle must not cancel the new dual-source reader');
      assert.equal(h.state(), owner);
      assert.deepEqual(h.snapshot(), expected);
      if (newer === 'pending') await completeIndependent(h, current, 'new-owner');
      assert.equal(h.refs.competitorAnalysis.value.marker, 'new-owner');
      assert.equal(h.state().status, 'ready');
    });
  }
}

for (const scope of ['hotel', 'date', 'session', 'epoch']) {
  for (const outcome of ['failed', 'throw']) {
    test(`${scope}: old bundle ${outcome} cannot erase new-scope competitor data`, async () => {
      const h = harness(), old = await begin(h, 'bundle');
      h.changeScope(scope);
      const current = await begin(h, 'competitor');
      await completeIndependent(h, current, 'new-scope');
      const expected = h.snapshot(), owner = h.state();
      h.settle(old.meituan, 'success', 'old-scope');
      h.settle(old.bundle, outcome, 'old-scope');
      await old.pending; await flush();
      assert.equal(h.state(), owner);
      assert.deepEqual(h.snapshot(), expected);
    });
  }
}

for (const newer of ['pending', 'ready']) {
  for (const outcome of ['success', 'failed', 'throw']) {
    test(`ensure-await takeover: ${newer} independent owner prevents bundle ${outcome} from launching another competitor read`, async () => {
      const h = harness(), gate = h.gateEnsure(), old = await begin(h, 'bundle');
      assert.equal(old.rows.length, 0);
      const current = await begin(h, 'competitor');
      if (newer === 'ready') await completeIndependent(h, current, 'independent-owner');
      const expected = h.snapshot(), owner = h.state(), seq = h.context.competitorAnalysisRequestSeq;
      gate.resolve(); await flush();
      const bundle = h.requests.find(row => row.path === '/agent/revenue-bundle');
      assert.ok(bundle, 'unrelated bundle modules continue reading');
      h.settle(bundle, outcome, 'resumed-bundle');
      // Settle any unexpectedly issued old request before asserting, so negative
      // controls fail by behavior and leave no fixture promise outstanding.
      for (const row of h.requests) {
        if (!row.settled && row !== current.price && row !== current.meituan) h.settle(row, 'success', 'unexpected-old-reader');
      }
      await old.pending; await flush();
      assert.equal(h.requests.filter(row => row.path === '/online-data/competitor-summary').length, 1);
      assert.equal(h.requests.filter(row => row.path === '/agent/competitor-analysis').length, 1);
      assert.equal(h.context.competitorAnalysisRequestSeq, seq);
      assert.equal(h.state(), owner);
      assert.deepEqual(h.snapshot(), expected);
      if (newer === 'pending') await completeIndependent(h, current, 'independent-owner');
      assert.equal(h.refs.competitorAnalysis.value.marker, 'independent-owner');
    });
  }
}

test('static-helper failure before child launch marks the still-owned competitor placeholder failed', async () => {
  const h = harness(), gate = h.gateEnsure(), operation = await begin(h, 'bundle');
  gate.reject(new Error('synthetic helper unavailable'));
  await operation.pending;
  assert.equal(h.requests.length, 0);
  assert.equal(h.state().status, 'failed');
  assert.match(h.refs.competitorAnalysisError.value, /helper unavailable/);
  assert.equal(h.refs.competitorAnalysisLoading.value, false);
});

for (const mismatch of ['hotel', 'date']) {
  test(`real Meituan normalizer rejects ${mismatch} mismatch after bundle price failure`, async () => {
    const h = harness(), operation = await begin(h, 'bundle');
    h.settle(operation.meituan, 'success', 'wrong-scope', mismatch === 'hotel'
      ? { system_hotel_id: 82 } : { latest_data_date: '2026-09-11' });
    h.settle(operation.bundle, 'failed', 'bundle');
    await operation.pending; await flush();
    assert.equal(h.state().status, 'failed');
    assert.match(h.refs.competitorAnalysisError.value, /酒店或日期不一致/);
    assert.deepEqual(copy(h.refs.competitorAnalysis.value.meituan_competition_circle.display_hotels), []);
    assert.equal(h.microscope().options.length, 0);
  });
}

test('a matching empty Meituan receipt remains empty with the Ctrip source error, without fabricated samples', async () => {
  const h = harness(), operation = await begin(h, 'bundle');
  h.settle(operation.meituan, 'empty');
  h.settle(operation.bundle, 'failed', 'bundle');
  await operation.pending; await flush();
  assert.equal(h.state().status, 'empty');
  assert.match(h.refs.competitorAnalysis.value.source_errors.ctrip, /bundle/);
  assert.equal(h.refs.competitorAnalysis.value.meituan_competition_circle.data_status, 'missing');
  assert.equal(h.microscope().options.length, 0);
});
