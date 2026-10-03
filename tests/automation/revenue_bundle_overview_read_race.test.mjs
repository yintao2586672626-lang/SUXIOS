import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_REVENUE_OVERVIEW_READ_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const take = name => {
  const start = main.indexOf(`\n            const ${name} =`) + 1;
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start > 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const production = ['resolveDemandForecastListPayload','resolvePriceSuggestionListPayload','captureAuthSession', 'isAuthSessionCurrent', 'captureAgentRevenueRequestContext',
  'isAgentRevenueRequestCurrent', 'captureRevenueForecastRange', 'isRevenueForecastRangeCurrent',
  'canUseRevenueAi', 'isCompassDataPage', 'revenueAiBusinessDate', 'currentPageReadPolicy', 'buildPageLoadScopeToken',
  'createPriceSuggestionPagination', 'createRevenueLoadState', 'createEmptyRevenueAnalysisData',
  'createEmptyRevenueDashboard', 'firstEnabledRoomTypeId', 'applyRoomTypeReadback', 'applyDemandForecastReadback',
  'applyRevenueDashboardReadback', 'applyRevenueAnalysisReadback', 'setRevenueLoadState',
  ...(main.includes('            const applyRevenueAiOverviewReadback =') ? ['applyRevenueAiOverviewReadback'] : []),
  'loadRevenueAnalysisBundle', 'loadRevenueAiOverview',
].map(take).join('\n');
const staticSources = ['revenue-overview-contract-static.js', 'revenue-ai-static.js']
  .map(file => readFileSync(new URL(`../../public/${file}`, import.meta.url), 'utf8'));
const copy = value => JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const overviewFixture = (marker, date = '2026-09-12', hotelId = '81') => ({
  as_of_date_contract_version: 'revenue_overview_as_of_date.v1', as_of_date: date,
  business_date: date, hotel_id: hotelId === '' ? null : Number(hotelId), marker, metrics: { revenue: 0, orders: 0 }, gaps: [],
});

// Actual two readers, Vue refs/computed, policy/scope key, authorization gate,
// date resolver and full response resolver. Transport is deferred; unrelated
// competitor work and permission lookup are closed synthetic boundaries.
function harness() {
  const refs = {}, requests = [], notices = [], ensureGates = [];
  for (const match of production.matchAll(/\b(\w+)\.value/g)) refs[match[1]] ??= Vue.ref(null);
  delete refs.revenueAiBusinessDate;
  const initial = {
    token: 'synthetic-session', user: { is_super_admin: true }, authContext: { tenantId: 7 },
    filterReportHotel: '81', currentPage: 'agent-center', coreOperationsTargetDate: '2026-09-12',
    priceSuggestionFilter: { date: '2026-09-12', end_date: '2026-09-13', status: 0 },
    priceSuggestionPagination: { page: 1, page_size: 20 },
    forecastFilter: { start_date: '2026-09-12', end_date: '2026-09-13' }, competitorFilter: { date: '2026-09-12' },
    demandForecastForm: { room_type_id: 0 }, competitorPriceForm: { room_type_id: 0 },
    roomTypeConfigList: [], roomTypeConfigMeta: {}, demandForecasts: [], forecastAccuracy: {}, highDemandDates: [],
    priceSuggestions: [], revenueAiOverview: null, revenueAiOverviewError: '', revenueAiOverviewLoading: false,
    competitorAnalysisError: '',
  };
  for (const [key, value] of Object.entries(initial)) refs[key] = Vue.ref(value);
  const context = vm.createContext({ ...refs, window: {}, computed: Vue.computed, URLSearchParams, console: { error() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1, pageRequestGeneration: 1,
    revenueAnalysisBundleRequestSeq: 0, priceSuggestionRequestSeq: 0, roomTypesRequestSequence:0,demandForecastsRequestSequence:0,revenueAiOverviewRequestSeq: 0,
    revenueAiOverviewRequestPromises: new Map(),
    currentBusinessRequestContext: () => ({ tenant_id: 7 }),
    userHasPermission: () => false, userHasCapability: () => false, normalizeCanonicalPage: value => String(value || ''),
    ensureRevenueAiStaticReady: async () => { const gate = ensureGates.shift(); if (gate) await gate.promise; return true; },
    formatDate: () => '2026-09-12', resetCompetitorAnalysisView() {},
    loadCompetitorAnalysis: async ({ priceResponsePromise }) => priceResponsePromise.catch(() => null),
    showToast: (...args) => notices.push(args),
    request: (url, options = {}) => {
      const parsed = new URL(url, 'https://synthetic.invalid');
      assert.ok(['/agent/revenue-bundle', '/revenue-ai/overview'].includes(parsed.pathname));
      const transport = deferred();
      requests.push({ ...transport, path: parsed.pathname, params: parsed.searchParams, options, settled: false });
      return transport.promise;
    },
  });
  for (const source of staticSources) vm.runInContext(source, context);
  const full = context.window.SUXI_REVENUE_AI_STATIC;
  context.revenueAiResolveBusinessDate = full.resolveRevenueAiBusinessDate;
  context.revenueAiResolveOverviewRequest = full.resolveRevenueAiOverviewRequest;
  context.revenueAiResolveOverviewResponse = full.resolveRevenueAiOverviewResponse;
  vm.runInContext(`${production}\nrevenueLoadState.value=createRevenueLoadState();
    globalThis.api={single:loadRevenueAiOverview,bundle:loadRevenueAnalysisBundle};
    globalThis.businessDateRef=revenueAiBusinessDate;`, context);
  refs.revenueAiBusinessDate = context.businessDateRef;
  const snapshot = () => copy({ overview: refs.revenueAiOverview.value, error: refs.revenueAiOverviewError.value, busy: refs.revenueAiOverviewLoading.value });
  const reply = (row, outcome = 'success', marker = 'current', scopeOverride = {}) => {
    assert.ok(row && !row.settled, 'one pending synthetic read'); row.settled = true;
    if (outcome === 'throw') return row.reject(new Error(`synthetic ${marker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${marker} failure` });
    const overview = outcome === 'empty' ? null : overviewFixture(
      marker, row.params.get('business_date') || '2026-09-12', row.params.get('hotel_id') || '');
    if (overview) Object.assign(overview, scopeOverride);
    row.resolve({ code: 200, data: row.path === '/revenue-ai/overview' ? overview : {
      overview, analysis: { statistics: { marker } }, dashboard: { today_suggestions: [{ marker }] },
      forecasts: { forecasts: [], accuracy: {}, high_demand_dates: [] }, room_types: { list: [] },
      price_suggestions: { list: [], pagination: {} }, competitor: {},
    } });
  };
  const changeScope = scope => {
    if (scope === 'hotel') refs.filterReportHotel.value = '82';
    else if (scope === 'page') refs.currentPage.value = 'compass';
    else if (scope === 'date') { refs.priceSuggestionFilter.value.date = '2026-09-11'; refs.coreOperationsTargetDate.value = '2026-09-11'; }
    else if (scope === 'session') context.authSessionEpoch++;
    else if (scope === 'token') refs.token.value = 'synthetic-new-session';
  };
  return { api: context.api, context, refs, requests, notices, snapshot, reply, changeScope,
    gateEnsure: () => { const gate = deferred(); ensureGates.push(gate); return gate; } };
}

async function begin(h, kind = 'single', options) {
  const start = h.requests.length, operation = { done: false, pending: null };
  operation.pending = h.api[kind](options).then(value => { operation.done = true; return value; });
  await flush(); operation.rows = h.requests.slice(start); operation.row = operation.rows[0];
  return operation;
}
async function prime(h) { const op = await begin(h); h.reply(op.row, 'success', 'previous'); await op.pending; }

for (const kind of ['single', 'bundle']) {
  for (const [scopeOverride, message] of [
    [{ business_date: '2026-09-11' }, /业务日期/],
    [{ hotel_id: 82 }, /酒店/],
  ]) {
    test(`${kind} refuses successful transport with wrong overview scope`, async () => {
      const h = harness();
      const op = await begin(h, kind);
      h.reply(op.row, 'success', 'wrong-scope', scopeOverride);
      await op.pending;
      assert.equal(h.refs.revenueAiOverview.value, null);
      assert.match(h.refs.revenueAiOverviewError.value, message);
      assert.equal(h.refs.revenueLoadState.value.overview.status, 'failed');
    });
  }
}

for (const direction of ['bundle-first', 'single-first']) {
  for (const outcome of ['success', 'failed', 'throw', 'empty']) {
    for (const order of ['new-first', 'old-first']) {
      test(`${direction}, old ${outcome}, ${order}: latest overview owns value/error/loading`, async () => {
        const h = harness(), old = await begin(h, direction === 'bundle-first' ? 'bundle' : 'single');
        const current = await begin(h, direction === 'bundle-first' ? 'single' : 'bundle');
        if (order === 'new-first') { h.reply(current.row, 'success', 'new-owner'); await current.pending; }
        const expected = h.snapshot();
        if (order === 'old-first') assert.equal(expected.busy, true);
        h.reply(old.row, outcome, 'old-owner'); await old.pending;
        assert.deepEqual(h.snapshot(), expected);
        if (direction === 'bundle-first' && outcome === 'success') {
          assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, 'old-owner');
          assert.equal(h.refs.revenueDashboard.value.today_suggestions[0].marker, 'old-owner');
        }
        if (order === 'old-first') { h.reply(current.row, 'success', 'new-owner'); await current.pending; }
        assert.equal(h.refs.revenueAiOverview.value.marker, 'new-owner');
        assert.equal(h.refs.revenueAiOverviewLoading.value, false);
        assert.equal(h.refs.revenueAiOverviewError.value, '');
      });
    }
  }
}

test('same-scope single calls deduplicate the real transport and do not revoke its owner', async () => {
  const h = harness(), first = await begin(h), seq = h.context.revenueAiOverviewRequestSeq, second = await begin(h);
  assert.equal(h.requests.length, 1); assert.equal(h.context.revenueAiOverviewRequestSeq, seq);
  h.reply(first.row, 'success', 'shared'); await Promise.all([first.pending, second.pending]);
  assert.equal(h.refs.revenueAiOverview.value.marker, 'shared');
  assert.equal(h.context.revenueAiOverviewRequestPromises.size, 0);
});

for (const outcome of ['success', 'failed', 'throw']) {
  test(`forced single starts a new request and suppresses older ${outcome}`, async () => {
    const h = harness(), old = await begin(h), current = await begin(h, 'single', { force: true });
    assert.equal(h.requests.length, 2); assert.equal(current.row.options.requestPolicy.force, true);
    h.reply(current.row, 'success', 'forced'); await current.pending; const expected = h.snapshot();
    h.reply(old.row, outcome, 'old'); await old.pending; assert.deepEqual(h.snapshot(), expected);
  });
}

test('single after bundle takeover cannot reuse the invalidated earlier single promise', async () => {
  const h = harness(), first = await begin(h), bundle = await begin(h, 'bundle'), latest = await begin(h);
  for (const [index, row] of h.requests.entries()) h.reply(row, 'success', `request-${index}`);
  await Promise.all([first.pending, bundle.pending, latest.pending]);
  assert.equal(h.requests.length, 3, 'the newest single requires its own transport');
  assert.equal(h.refs.revenueAiOverview.value.marker, 'request-2');
  assert.equal(h.context.revenueAiOverviewRequestPromises.size, 0);
});

test('hotel A to B to A starts a fresh A read instead of reusing its revoked pending promise', async () => {
  const h = harness(), oldA = await begin(h);
  h.refs.filterReportHotel.value = '82'; const hotelB = await begin(h);
  h.refs.filterReportHotel.value = '81'; const newA = await begin(h);
  const count = h.requests.length;
  if (newA.row) { h.reply(newA.row, 'success', 'new-A'); await newA.pending; }
  h.reply(hotelB.row, 'success', 'old-B'); h.reply(oldA.row, 'success', 'old-A');
  await Promise.all([oldA.pending, hotelB.pending, newA.pending]);
  assert.equal(count, 3, 'the original A promise lost ownership when B started');
  assert.equal(h.refs.revenueAiOverview.value.marker, 'new-A');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
  assert.equal(h.context.revenueAiOverviewRequestPromises.size, 0);
});

for (const outcome of ['failed', 'throw', 'empty']) {
  test(`current single ${outcome} preserves previous overview with explicit error and supports retry`, async () => {
    const h = harness(); await prime(h); const previous = copy(h.refs.revenueAiOverview.value);
    const current = await begin(h); h.reply(current.row, outcome, 'current-failure'); await current.pending;
    assert.deepEqual(copy(h.refs.revenueAiOverview.value), previous);
    assert.ok(h.refs.revenueAiOverviewError.value); assert.equal(h.refs.revenueAiOverviewLoading.value, false);
    const retry = await begin(h); h.reply(retry.row, 'success', 'retry'); await retry.pending;
    assert.equal(h.refs.revenueAiOverview.value.marker, 'retry'); assert.equal(h.refs.revenueAiOverviewError.value, '');
  });
}

test('current helper failure retains previous overview with error, releases busy and permits retry', async () => {
  const h = harness(); await prime(h); const previous = copy(h.refs.revenueAiOverview.value);
  const gate = h.gateEnsure(), current = await begin(h); gate.reject(new Error('synthetic helper failure')); await current.pending;
  assert.deepEqual(copy(h.refs.revenueAiOverview.value), previous); assert.match(h.refs.revenueAiOverviewError.value, /helper failure/);
  assert.equal(h.refs.revenueAiOverviewLoading.value, false); assert.equal(h.context.revenueAiOverviewRequestPromises.size, 0);
  const retry = await begin(h); h.reply(retry.row, 'success', 'retry'); await retry.pending;
  assert.equal(h.refs.revenueAiOverview.value.marker, 'retry');
});

for (const finish of ['resolve', 'reject']) {
  for (const currentState of ['pending', 'ready']) {
    test(`old single helper ${finish} preserves a forced newer ${currentState} owner without dispatch`, async () => {
      const h = harness(); await prime(h); const gate = h.gateEnsure(), old = await begin(h);
      const current = await begin(h, 'single', { force: true });
      if (currentState === 'ready') { h.reply(current.row, 'success', 'newer'); await current.pending; }
      const expected = h.snapshot(), count = h.requests.length;
      finish === 'resolve' ? gate.resolve() : gate.reject(new Error('obsolete helper failure')); await flush();
      for (const row of h.requests.slice(count)) h.reply(row, 'success', 'unexpected-stale-dispatch');
      await old.pending;
      assert.equal(h.requests.length, count); assert.deepEqual(h.snapshot(), expected);
      if (currentState === 'pending') { h.reply(current.row, 'success', 'newer'); await current.pending; }
    });
  }
}

for (const scope of ['hotel', 'page', 'date', 'session', 'token']) {
  for (const finish of ['resolve', 'reject']) {
    test(`single ensure ${finish} after ${scope} change neither dispatches nor changes overview`, async () => {
      const h = harness(), gate = h.gateEnsure(), old = await begin(h);
      h.changeScope(scope); const expected = h.snapshot();
      finish === 'resolve' ? gate.resolve() : gate.reject(new Error('obsolete scope helper failure')); await flush();
      for (const row of h.requests) h.reply(row, 'success', 'wrong-scope');
      await old.pending;
      if (['hotel', 'page', 'date'].includes(scope)) expected.busy = false;
      assert.equal(h.requests.length, 0); assert.deepEqual(h.snapshot(), expected);
    });
  }

  test(`single transport success/error/throw stay isolated after ${scope} change`, async () => {
    for (const outcome of ['success', 'failed', 'throw']) {
      const h = harness(), old = await begin(h); h.changeScope(scope); const expected = h.snapshot();
      h.reply(old.row, outcome, 'old-scope'); await old.pending;
      if (['hotel', 'page', 'date'].includes(scope)) expected.busy = false;
      assert.deepEqual(h.snapshot(), expected);
    }
  });
}

for (const finish of ['resolve', 'reject']) {
  test(`bundle helper ${finish} cannot replace an overview accepted from a newer single`, async () => {
    const h = harness(), gate = h.gateEnsure(), old = await begin(h, 'bundle'), current = await begin(h);
    h.reply(current.row, 'success', 'new-single'); await current.pending; const expected = h.snapshot(), count = h.requests.length;
    finish === 'resolve' ? gate.resolve() : gate.reject(new Error('old bundle helper failure')); await flush();
    for (const row of h.requests.slice(count)) h.reply(row, 'success', 'old-bundle');
    await old.pending; assert.deepEqual(h.snapshot(), expected);
    if (finish === 'resolve') assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, 'old-bundle');
  });
}

test('no-token single is a no-op and makes no helper or transport work', async () => {
  const h = harness(); h.refs.token.value = ''; h.refs.revenueAiOverview.value = overviewFixture('kept');
  const expected = h.snapshot(), current = await begin(h); await current.pending;
  assert.equal(h.requests.length, 0); assert.deepEqual(h.snapshot(), expected);
});

test('unavailable Revenue AI clears the visible overview and invalidates pending work', async () => {
  const h = harness(), old = await begin(h); h.refs.user.value.is_super_admin = false;
  const denied = await begin(h); await denied.pending;
  assert.deepEqual(h.snapshot(), { overview: null, error: '', busy: false });
  h.reply(old.row, 'success', 'denied-old'); await old.pending;
  assert.deepEqual(h.snapshot(), { overview: null, error: '', busy: false });
});

test('unsupported page single issues no transport and preserves the existing overview', async () => {
  const h = harness(); h.refs.currentPage.value = 'settings'; h.refs.revenueAiOverview.value = overviewFixture('kept');
  const expected = h.snapshot(), current = await begin(h); await current.pending;
  assert.equal(h.requests.length, 0); assert.deepEqual(h.snapshot(), expected);
});

for (const outcome of ['success', 'failed', 'throw', 'empty']) {
  test(`no-hotel bundle delegates to the real single overview and settles ${outcome}`, async () => {
    const h = harness(); h.refs.filterReportHotel.value = '';
    const current = await begin(h, 'bundle');
    assert.equal(current.rows.length, 1); assert.equal(current.row.path, '/revenue-ai/overview');
    assert.equal(current.row.params.has('hotel_id'), false);
    h.reply(current.row, outcome, 'delegated'); await current.pending;
    assert.equal(h.refs.revenueAiOverviewLoading.value, false);
    if (outcome === 'success') { assert.equal(h.refs.revenueAiOverview.value.marker, 'delegated'); assert.equal(h.refs.revenueAiOverviewError.value, ''); }
    else { assert.equal(h.refs.revenueAiOverview.value, null); assert.ok(h.refs.revenueAiOverviewError.value); }
  });
}

for (const reason of ['permission-refused', 'unsupported-page']) {
  test(`no-hotel bundle early exit ${reason} settles overview state instead of leaving loading`, async () => {
    const h = harness(); h.refs.filterReportHotel.value = '';
    if (reason === 'permission-refused') h.refs.user.value.is_super_admin = false;
    else h.refs.currentPage.value = 'settings';
    const current = await begin(h, 'bundle'); await current.pending;
    assert.equal(h.requests.length, 0);
    assert.deepEqual(h.snapshot(), { overview: null, error: '', busy: false });
    assert.deepEqual(copy(h.refs.revenueLoadState.value.overview), { status: 'empty', error: '' });
  });
}

for (const kind of ['single', 'bundle']) {
  test(`${kind} full resolver preserves real zero metrics and rejects missing overview as an explicit error`, async () => {
    const h = harness(), current = await begin(h, kind); h.reply(current.row, 'success', 'zero'); await current.pending;
    assert.equal(h.refs.revenueAiOverview.value.metrics.revenue, 0); assert.equal(h.refs.revenueAiOverview.value.metrics.orders, 0);
    assert.deepEqual(copy(h.refs.revenueAiOverview.value.gaps), []); assert.equal(h.refs.revenueAiOverviewError.value, '');
    const empty = await begin(h, kind); h.reply(empty.row, 'empty'); await empty.pending;
    assert.ok(h.refs.revenueAiOverviewError.value, 'missing response is not zero-valued success');
    assert.equal(h.refs.revenueAiOverviewLoading.value, false);
  });
}
