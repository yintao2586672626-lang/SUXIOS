import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_REVENUE_DASHBOARD_ANALYSIS_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const declaration = name => {
  const start = main.indexOf(`            const ${name} =`);
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start >= 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const production = ['resolveDemandForecastListPayload','resolvePriceSuggestionListPayload','captureAuthSession', 'isAuthSessionCurrent',
  ...['applyRevenueAiOverviewReadback', 'captureRevenueForecastRange', 'isRevenueForecastRangeCurrent'].filter(name => main.includes('            const ' + name + ' =')),
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent',
  'createPriceSuggestionPagination', 'createRevenueLoadState', 'createEmptyRevenueAnalysisData',
  'createEmptyRevenueDashboard', 'firstEnabledRoomTypeId', 'applyRoomTypeReadback', 'applyDemandForecastReadback',
  ...['applyRevenueDashboardReadback', 'applyRevenueAnalysisReadback']
    .filter(name => main.includes(`            const ${name} =`)),
  'setRevenueLoadState', 'syncRevenuePricingInputDate', 'loadRevenueDashboard', 'loadRevenueAnalysis', 'loadRevenueAnalysisBundle',
].map(declaration).join('\n');
const copy = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const keys = ['dashboard', 'analysis'];
const outcomes = ['success', 'failed', 'throw'];

// Real Vue refs, factories, scope predicates, state setter and all three readers.
// Overview/competitor are explicitly unrelated boundaries. The competitor stub
// consumes the shared price promise so transport failures cannot escape the VM.
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
    competitorAnalysisError: '',
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = Vue.ref(value);
  const context = vm.createContext({ ...refs, URLSearchParams, console: { error() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1,
    priceSuggestionRequestSeq: 0, revenueAnalysisBundleRequestSeq: 0,
    roomTypesRequestSequence:0,demandForecastsRequestSequence:0,revenueAiOverviewRequestSeq: 0, revenueAiOverviewRequestPromises: new Map(),
    ensureRevenueAiStaticReady: async () => { const gate = ensureGates.shift(); if (gate) await gate; return true; },
    loadRevenueAiOverview: async () => null, resetCompetitorAnalysisView() {}, formatDate: () => '2026-09-12',
    revenueAiResolveOverviewResponse: ({ response }) => ({ overview: response.data, errorMessage: '' }),
    loadCompetitorAnalysis: async ({ priceResponsePromise }) => priceResponsePromise.catch(() => null),
    showToast: (...args) => notices.push(args),
    request: url => {
      const parsed = new URL(url, 'https://synthetic.invalid');
      assert.ok(['/agent/revenue-bundle', '/agent/revenue-dashboard', '/agent/revenue-analysis'].includes(parsed.pathname));
      return new Promise((resolve, reject) => requests.push({ url, path: parsed.pathname,
        params: parsed.searchParams, resolve, reject, settled: false }));
    },
  });
  vm.runInContext(`${production}
    revenueLoadState.value = createRevenueLoadState();
    revenueDashboard.value = createEmptyRevenueDashboard();
    revenueAnalysisData.value = createEmptyRevenueAnalysisData();
    globalThis.api = { dashboard: loadRevenueDashboard, analysis: loadRevenueAnalysis,
      bundle: loadRevenueAnalysisBundle, setState: setRevenueLoadState, syncDate: syncRevenuePricingInputDate };`, context);
  const state = key => refs.revenueLoadState.value[key];
  const data = key => key === 'dashboard' ? refs.revenueDashboard.value : refs.revenueAnalysisData.value;
  const snapshot = key => copy({ data: data(key), state: state(key) });
  const marker = key => key === 'dashboard' ? data(key).today_suggestions?.[0]?.marker : data(key).statistics?.marker;
  const settle = (index, outcome = 'success', responseMarker = `response-${index}`, payload) => {
    const row = requests[index];
    assert.ok(row && !row.settled, 'one pending synthetic read');
    row.settled = true;
    if (outcome === 'throw') return row.reject(new Error(`synthetic ${responseMarker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${responseMarker} failure` });
    const dashboard = outcome === 'empty' ? {} : { today_suggestions: [{ marker: responseMarker }], pending_count: 0 };
    const analysis = outcome === 'empty' ? {} : { statistics: { marker: responseMarker, revenue: 0 },
      date_range: { start_date: row.params.get('start_date'), end_date: row.params.get('end_date') } };
    const response = row.path === '/agent/revenue-bundle' ? {
      overview: { marker: responseMarker }, dashboard, analysis,
      room_types: { list: [] }, forecasts: { forecasts: [], accuracy: {}, high_demand_dates: [] },
      price_suggestions: { list: [], pagination: {} }, competitor: {},
    } : row.path === '/agent/revenue-dashboard' ? dashboard : analysis;
    row.resolve({ code: 200, data: payload === undefined ? response : payload });
  };
  const changeScope = scope => {
    if (scope === 'hotel') refs.filterReportHotel.value = '82';
    else if (scope === 'session') context.authSessionEpoch++;
    else if (scope === 'epoch') context.agentRevenueStateEpoch++;
    else if (scope === 'start-date') refs.forecastFilter.value.start_date = '2026-09-11';
    else if (scope === 'end-date') refs.forecastFilter.value.end_date = '2026-09-14';
  };
  const gateEnsure = () => { let release; ensureGates.push(new Promise(resolve => { release = resolve; })); return release; };
  return { refs, requests, notices, context, api: context.api, state, data, snapshot, marker, settle, changeScope, gateEnsure };
}

async function begin(h, kind) {
  const previous = h.requests.length;
  const pending = h.api[kind]();
  await flush();
  return { pending, index: h.requests.length > previous ? previous : -1 };
}

for (const key of keys) {
  for (const source of [key, 'bundle']) {
    for (const outcome of ['failed', 'throw']) {
      test(`${key}: current ${source} ${outcome} becomes failed and an independent retry recovers`, async () => {
        const h = harness(), bad = await begin(h, source);
        h.settle(bad.index, outcome, 'current-failure'); await bad.pending;
        assert.equal(h.state(key).status, 'failed');
        assert.match(h.state(key).error, /current-failure/);
        if (source === 'bundle') assert.equal(h.refs.revenueAiOverviewLoading.value, false);
        const retry = await begin(h, key);
        h.settle(retry.index, 'success', 'retry'); await retry.pending;
        assert.equal(h.state(key).status, 'ready');
        assert.equal(h.marker(key), 'retry');
        assert.equal(h.state(key).error, '');
      });
    }
  }

  for (const direction of ['bundle-first', 'independent-first', 'independent-repeat']) {
    for (const outcome of outcomes) {
      for (const completion of ['new-first', 'old-first']) {
        test(`${key}: ${direction}, old ${outcome}, ${completion} preserves the newest owner`, async () => {
          const h = harness();
          const old = await begin(h, direction === 'bundle-first' ? 'bundle' : key);
          const current = await begin(h, direction === 'independent-first' ? 'bundle' : key);
          if (completion === 'new-first') { h.settle(current.index, 'success', 'new-owner'); await current.pending; }
          const expected = h.snapshot(key), owner = h.state(key), noticeCount = h.notices.length;
          if (completion === 'old-first') assert.equal(owner.status, 'loading');
          h.settle(old.index, outcome, 'old-owner'); await old.pending;
          assert.equal(h.state(key), owner, 'obsolete completion preserves the actual Vue module object');
          assert.deepEqual(h.snapshot(key), expected);
          if (direction !== 'bundle-first') assert.equal(h.notices.length, noticeCount, 'stale independent errors are silent');
          else {
            assert.equal(h.refs.revenueAiOverviewLoading.value, false);
            const other = key === 'dashboard' ? 'analysis' : 'dashboard';
            if (outcome === 'success') assert.equal(h.marker(other), 'old-owner', 'other still-owned bundle data completes');
            else assert.match(h.refs.revenueAiOverviewError.value, /old-owner/, 'current bundle may report its other failures');
          }
          if (completion === 'old-first') {
            if (direction === 'independent-first') assert.equal(h.refs.revenueAiOverviewLoading.value, true);
            h.settle(current.index, 'success', 'new-owner'); await current.pending;
          }
          assert.equal(h.marker(key), 'new-owner');
          assert.equal(h.state(key).status, 'ready');
        });
      }
    }
  }

  for (const outcome of ['empty', 'failed']) {
    test(`${key}: old successful bundle cannot replace a newer independent ${outcome} state`, async () => {
      const h = harness(), old = await begin(h, 'bundle'), current = await begin(h, key);
      h.settle(current.index, outcome, 'new-owner'); await current.pending;
      const expected = h.snapshot(key), owner = h.state(key);
      h.settle(old.index, 'success', 'old-bundle'); await old.pending;
      assert.equal(h.state(key), owner);
      assert.deepEqual(h.snapshot(key), expected);
      assert.equal(h.state(key).status, outcome === 'empty' ? 'empty' : 'failed');
    });
  }

  test(`${key}: another module state update retains the actual Vue owner`, async () => {
    const h = harness(), current = await begin(h, key), owner = h.state(key), top = h.refs.revenueLoadState.value;
    assert.equal(Vue.isReactive(owner), true);
    h.api.setState('logs', 'ready');
    h.api.setState(key === 'analysis' ? 'dashboard' : 'analysis', 'failed', 'unrelated failure');
    assert.notEqual(h.refs.revenueLoadState.value, top);
    assert.equal(h.state(key), owner);
    h.settle(current.index, 'success', 'owned'); await current.pending;
    assert.equal(h.marker(key), 'owned');
    assert.equal(h.state(key).status, 'ready');
  });

  for (const scope of ['hotel', 'session', 'epoch']) {
    test(`${key}: ${scope} still isolates all independent outcomes`, async () => {
      for (const outcome of outcomes) {
        const h = harness(), old = await begin(h, key);
        h.changeScope(scope);
        const expected = h.snapshot(key);
        h.settle(old.index, outcome, 'old-scope'); await old.pending;
        assert.deepEqual(h.snapshot(key), expected);
        assert.equal(h.notices.length, 0);
      }
    });
  }

  test(`${key}: no hotel returns empty and cannot resurrect a pending old hotel read`, async () => {
    const h = harness(), old = await begin(h, key);
    h.refs.filterReportHotel.value = '';
    const empty = await begin(h, key); await empty.pending;
    assert.equal(empty.index, -1);
    assert.equal(h.state(key).status, 'empty');
    const expected = h.snapshot(key);
    h.settle(old.index, 'success', 'old-hotel'); await old.pending;
    assert.deepEqual(h.snapshot(key), expected);
  });
}

const readyFields = {
  dashboard: { today_suggestions: [{}], competitor_alerts: [{}], pending_count: 1, forecast_accuracy: { mae: 0 } },
  analysis: { revpar_trend: [{}], pricing_strategies: [{}], room_types: [{}], statistics: { revenue: 0 } },
};
for (const key of keys) {
  for (const source of [key, 'bundle']) {
    test(`${source} ${key} readback retains every original ready/empty condition and real zeros`, async () => {
      for (const [field, value] of Object.entries(readyFields[key])) {
        const h = harness(), current = await begin(h, source), payload = { [field]: value };
        h.settle(current.index, 'success', '', source === 'bundle' ? { [key]: payload } : payload);
        await current.pending;
        assert.equal(h.state(key).status, 'ready', field);
        assert.deepEqual(copy(h.data(key)[field]), value);
      }
      const h = harness(), empty = await begin(h, source);
      const payload = key === 'dashboard' ? { pending_count: 0, high_demand_count: 0, week_revpar_forecast: 0 } : { high_demand_dates: [], date_range: {} };
      h.settle(empty.index, 'success', '', source === 'bundle' ? { [key]: payload } : payload);
      await empty.pending;
      assert.equal(h.state(key).status, 'empty', 'unrelated/default fields are not fabricated evidence');
    });
  }
}

test('dashboard standalone preserves raw payload shape while bundle and analysis retain their existing defaults', async () => {
  const h = harness(), dashboard = await begin(h, 'dashboard');
  h.settle(dashboard.index, 'success', '', { pending_count: 2 }); await dashboard.pending;
  assert.deepEqual(copy(h.data('dashboard')), { pending_count: 2 });
  const analysis = await begin(h, 'analysis');
  h.settle(analysis.index, 'success', '', { statistics: { revenue: 0 } }); await analysis.pending;
  assert.deepEqual(copy(h.data('analysis').revpar_trend), []);
  assert.equal(h.data('analysis').statistics.revenue, 0);
  const bundle = await begin(h, 'bundle');
  h.settle(bundle.index, 'success', '', { dashboard: { pending_count: 2 }, analysis: { statistics: { revenue: 0 } } });
  await bundle.pending;
  assert.deepEqual(copy(h.data('dashboard').today_suggestions), []);
  assert.deepEqual(copy(h.data('analysis').room_types), []);
});

for (const date of ['start-date', 'end-date']) {
  test(`independent analysis ${date} change rejects all old response outcomes`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), old = await begin(h, 'analysis');
      h.changeScope(date);
      const expected = h.snapshot('analysis');
      h.settle(old.index, outcome, 'old-range'); await old.pending;
      assert.deepEqual(h.snapshot('analysis'), expected);
      assert.equal(h.notices.length, 0);
    }
  });

  for (const outcome of outcomes) {
    test(`bundle analysis ${date} change blocks old ${outcome} writes but preserves hotel-level dashboard completion`, async () => {
      const h = harness(), old = await begin(h, 'bundle');
      h.changeScope(date);
      const expected = h.snapshot('analysis'), owner = h.state('analysis');
      h.settle(old.index, outcome, 'old-range'); await old.pending;
      assert.equal(h.state('analysis'), owner);
      assert.deepEqual(h.snapshot('analysis'), expected);
      assert.equal(h.state('dashboard').status, outcome === 'success' ? 'ready' : 'failed');
      if (outcome === 'success') assert.equal(h.marker('dashboard'), 'old-range');
      assert.equal(h.refs.revenueAiOverviewLoading.value, false);
    });
  }

  test(`bundle captures the actual dispatch ${date} after helper readiness and accepts only that same range`, async () => {
    const h = harness(), release = h.gateEnsure(), old = await begin(h, 'bundle');
    assert.equal(old.index, -1);
    h.changeScope(date);
    const expectedRange = copy(h.refs.forecastFilter.value);
    release(); await flush();
    const row = h.requests[0];
    assert.ok(row);
    h.settle(0, 'success', 'captured-range'); await old.pending;
    assert.equal(row.params.get('start_date'), expectedRange.start_date);
    assert.equal(row.params.get('end_date'), expectedRange.end_date);
    assert.deepEqual(copy(h.data('analysis').date_range), expectedRange);
    assert.equal(h.marker('analysis'), 'captured-range');
    assert.equal(h.marker('dashboard'), 'captured-range');
  });
}

test('save-date sync during bundle helper wait preserves newer independent owners and dispatches forecasts for the new visible range', async () => {
  const h = harness(), release = h.gateEnsure(), old = await begin(h, 'bundle');
  assert.equal(old.index, -1);
  h.api.syncDate('2026-09-15', { syncDraftDates: false });
  const analysis = await begin(h, 'analysis'), dashboard = await begin(h, 'dashboard');
  h.settle(analysis.index, 'success', 'new-analysis');
  h.settle(dashboard.index, 'success', 'new-dashboard');
  await Promise.all([analysis.pending, dashboard.pending]);
  const expectedAnalysis = h.snapshot('analysis'), expectedDashboard = h.snapshot('dashboard');
  release(); await flush();
  const index = h.requests.findIndex(row => row.path === '/agent/revenue-bundle');
  assert.ok(index >= 0);
  const row = h.requests[index], forecastDate = row.params.get('start_date');
  h.settle(index, 'success', '', {
    analysis: { statistics: { marker: 'older-bundle' } }, dashboard: { pending_count: 99 },
    forecasts: { forecasts: [{ id: 1, forecast_date: forecastDate, predicted_demand: 7,
      historical_data: { input_type: 'manual_demand_forecast' } }], accuracy: {}, high_demand_dates: [] },
  });
  await old.pending;
  assert.equal(row.params.get('start_date'), '2026-09-15');
  assert.equal(row.params.get('end_date'), '2026-09-15');
  assert.deepEqual(h.snapshot('analysis'), expectedAnalysis);
  assert.deepEqual(h.snapshot('dashboard'), expectedDashboard);
  assert.equal(h.refs.revenueLoadState.value.forecasts.status, 'idle');
  assert.deepEqual(copy(h.refs.demandForecasts.value), []);
});

test('hotel-scoped standalone dashboard remains current after an analysis-only date change', async () => {
  const h = harness(), current = await begin(h, 'dashboard');
  h.changeScope('start-date'); h.changeScope('end-date');
  h.settle(current.index, 'success', 'hotel-scoped'); await current.pending;
  assert.equal(h.marker('dashboard'), 'hotel-scoped');
  assert.equal(h.state('dashboard').status, 'ready');
});

for (const scope of ['hotel', 'session', 'epoch']) {
  test(`bundle ${scope} guard still isolates both modules for success/failure/throw`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), old = await begin(h, 'bundle');
      h.changeScope(scope);
      const expected = keys.map(key => h.snapshot(key));
      h.settle(old.index, outcome, 'old-scope'); await old.pending;
      assert.deepEqual(keys.map(key => h.snapshot(key)), expected);
      assert.equal(h.notices.length, 0);
    }
  });
}

test('other module state replacements do not revoke either owned bundle result', async () => {
  const h = harness(), current = await begin(h, 'bundle'), owners = keys.map(key => h.state(key));
  h.api.setState('logs', 'ready');
  h.api.setState('roomTypes', 'empty');
  for (const [index, key] of keys.entries()) assert.equal(h.state(key), owners[index]);
  h.settle(current.index, 'success', 'owned-bundle'); await current.pending;
  for (const key of keys) { assert.equal(h.state(key).status, 'ready'); assert.equal(h.marker(key), 'owned-bundle'); }
});

test('no-hotel bundle settles both modules as empty without issuing a transport', async () => {
  const h = harness(); h.refs.filterReportHotel.value = '';
  const current = await begin(h, 'bundle'); await current.pending;
  assert.equal(current.index, -1);
  assert.equal(h.requests.length, 0);
  for (const key of keys) assert.equal(h.state(key).status, 'empty');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});
