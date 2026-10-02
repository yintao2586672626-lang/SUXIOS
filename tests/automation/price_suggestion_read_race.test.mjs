import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_PRICE_READ_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const template = readFileSync(new URL('../../resources/frontend/templates/fragments/27-page-agent-center.html', import.meta.url), 'utf8');
const declaration = name => {
  const start = main.indexOf(`            const ${name} =`);
  const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(start >= 0 && end, `actual declaration: ${name}`);
  return main.slice(start, start + 1 + end.index);
};
const production = ['captureAuthSession', 'isAuthSessionCurrent', 'createPriceSuggestionPagination',
  ...['applyRevenueAiOverviewReadback', 'captureRevenueForecastRange', 'isRevenueForecastRangeCurrent'].filter(name => main.includes('            const ' + name + ' =')),
  ...['applyRevenueDashboardReadback', 'applyRevenueAnalysisReadback'].filter(name => main.includes('            const ' + name + ' =')),
  ...(main.includes('            const applyRoomTypeReadback =') ? ['applyRoomTypeReadback'] : []),
  ...(main.includes('            const applyDemandForecastReadback =') ? ['applyDemandForecastReadback'] : []),
  'createRevenueLoadState', 'createEmptyRevenueAnalysisData', 'createEmptyRevenueDashboard',
  'setRevenueLoadState', 'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent',
  'priceSuggestionRangeError', 'loadPriceSuggestions', 'changePriceSuggestionPage', 'loadRevenueAnalysisBundle',
].map(declaration).join('\n');
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));

// Original list/page/bundle handlers and ownership predicates. Pure projections
// and unrelated competitor rendering are boundaries; HTTP is always deferred.
function harness() {
  const requests = [], effects = [], notices = [];
  const ref = (name, initial = null) => {
    let value = initial;
    return { get value() { return value; }, set value(next) {
      value = next; effects.push([name, copy(next)]);
    } };
  };
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) refs[match[1]] ??= ref(match[1]);
  const initial = {
    token: 'synthetic-session', filterReportHotel: '81', revenueAiBusinessDate: '2026-09-12',
    priceSuggestionFilter: { date: '2026-09-12', end_date: '2026-09-13', status: 0 },
    priceSuggestionPagination: { total: 60, page: 1, page_size: 20, total_page: 3 },
    forecastFilter: { start_date: '2026-09-12', end_date: '2026-09-13' },
    competitorFilter: { date: '2026-09-12' }, demandForecastForm: { room_type_id: 0 },
    competitorPriceForm: { room_type_id: 0 }, priceSuggestions: [], priceSuggestionReview: null,
    revenueAiOverviewLoading: false, revenueAiOverviewError: '',
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = ref(name, value);
  const context = vm.createContext({ ...refs, URLSearchParams, console: { error() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1, priceSuggestionRequestSeq: 0, revenueAnalysisBundleRequestSeq: 0,
    revenueAiOverviewRequestSeq: 0, revenueAiOverviewRequestPromises: new Map(),
    ensureRevenueAiStaticReady: async () => true, loadRevenueAiOverview: async () => null,
    resetCompetitorAnalysisView() {}, firstEnabledRoomTypeId: () => 0,
    formatDate: () => '2026-09-12',
    revenueAiResolveOverviewResponse: ({ response }) => ({ overview: response.data, errorMessage: '' }),
    loadCompetitorAnalysis: async ({ priceResponsePromise }) => priceResponsePromise.catch(() => null),
    showToast: (...args) => notices.push(args),
    request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject, settled: false })),
  });
  vm.runInContext(`${production}\nrevenueLoadState.value = createRevenueLoadState();
    globalThis.api = { list: loadPriceSuggestions, page: changePriceSuggestionPage, bundle: loadRevenueAnalysisBundle };`, context);
  effects.length = 0;
  const query = () => {
    // This is the original query-button action before invoking its actual reader.
    refs.priceSuggestionPagination.value.page = 1;
    return context.api.list();
  };
  const price = () => copy({ list: refs.priceSuggestions.value, pagination: refs.priceSuggestionPagination.value,
    review: refs.priceSuggestionReview.value, state: refs.revenueLoadState.value.priceSuggestions });
  const snapshot = () => copy({ price: price(), states: refs.revenueLoadState.value,
    analysis: refs.revenueAnalysisData.value, rooms: refs.roomTypeConfigList.value,
    overview: refs.revenueAiOverview.value, busy: refs.revenueAiOverviewLoading.value, notices });
  const settle = (index, outcome = 'success', marker = `response-${index}`) => {
    const row = requests[index];
    assert.ok(row, `request ${index} exists`);
    assert.equal(row.settled, false, `request ${index} settles only once`);
    row.settled = true;
    if (outcome === 'exception') return row.reject(new Error(`synthetic ${marker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${marker} failure` });
    const params = new URL(row.url, 'https://synthetic.invalid').searchParams;
    const pricing = { list: [{ id: marker, hotel_id: params.get('hotel_id'), price: 0 }],
      pagination: { total: 60, page: Number(params.get('page')), page_size: 20, total_page: 3 } };
    row.resolve({ code: 200, data: row.url.startsWith('/agent/revenue-bundle?') ? {
      overview: { marker }, analysis: { statistics: { marker } }, dashboard: { today_suggestions: [{ marker }] },
      forecasts: { forecasts: [{ marker }], accuracy: {}, high_demand_dates: [] }, competitor: {},
      room_types: { list: [{ id: marker, name: marker }] }, price_suggestions: pricing,
    } : pricing });
  };
  const changeScope = kind => {
    if (kind === 'hotel') refs.filterReportHotel.value = '82';
    else if (kind === 'session') context.authSessionEpoch++;
    else if (kind === 'epoch') context.agentRevenueStateEpoch++;
    else if (kind === 'date') refs.priceSuggestionFilter.value.date = '2026-09-11';
    else if (kind === 'end-date') refs.priceSuggestionFilter.value.end_date = '2026-09-14';
    else if (kind === 'status') refs.priceSuggestionFilter.value.status = 2;
  };
  return { api: context.api, context, refs, effects, notices, requests, query, price, snapshot, settle, changeScope };
}

async function begin(h, kind = 'list', options) {
  const previous = h.requests.length;
  const pending = kind === 'query' ? h.query() : h.api[kind](options);
  await flush();
  return { pending, index: h.requests.length > previous ? previous : -1 };
}

test('original query and pagination buttons allow a new read while another read is loading', () => {
  const tags = template.match(/<button\b[^>]*>/g) || [];
  const query = tags.find(tag => tag.includes('priceSuggestionPagination.page = 1; loadPriceSuggestions()'));
  const pagination = tags.filter(tag => tag.includes('changePriceSuggestionPage('));
  assert.ok(query);
  assert.doesNotMatch(query, /disabled/);
  assert.equal(pagination.length, 2);
  for (const tag of pagination) assert.doesNotMatch(tag, /loading|revenueLoadState|priceSuggestionRequestSeq/);
});

for (const outcome of ['success', 'failed', 'exception']) {
  test(`page 2 ${outcome} cannot overwrite the newer query-button page 1 result`, async () => {
    const h = harness(), old = await begin(h, 'page', 2), current = await begin(h, 'query');
    assert.match(h.requests[old.index].url, /page=2(?:&|$)/);
    assert.match(h.requests[current.index].url, /page=1(?:&|$)/);
    h.settle(current.index, 'success', 'query-page-1'); await current.pending;
    const expected = h.snapshot();
    h.settle(old.index, outcome, 'old-page-2'); await old.pending;
    assert.deepEqual(h.snapshot(), expected);
    assert.equal(h.refs.priceSuggestions.value[0].id, 'query-page-1');
  });

  test(`old list ${outcome} leaves a newer pending list loading`, async () => {
    const h = harness(), old = await begin(h, 'list'), current = await begin(h, 'query');
    const expected = h.price();
    assert.equal(expected.state.status, 'loading');
    h.settle(old.index, outcome, 'obsolete'); await old.pending;
    assert.deepEqual(h.price(), expected);
    h.settle(current.index, 'success', 'current'); await current.pending;
    assert.equal(h.refs.priceSuggestions.value[0].id, 'current');
  });
}

for (const outcome of ['failed', 'exception']) {
  test(`a current list ${outcome} remains visible and the next query recovers`, async () => {
    const h = harness(), bad = await begin(h);
    h.settle(bad.index, outcome, 'current'); await bad.pending;
    assert.equal(h.refs.revenueLoadState.value.priceSuggestions.status, 'failed');
    assert.deepEqual(copy(h.refs.priceSuggestions.value), []);
    const retry = await begin(h, 'query');
    h.settle(retry.index, 'success', 'retry'); await retry.pending;
    assert.equal(h.refs.revenueLoadState.value.priceSuggestions.status, 'ready');
    assert.equal(h.refs.priceSuggestions.value[0].id, 'retry');
  });
}

for (const scope of ['hotel', 'session', 'epoch', 'date', 'end-date', 'status']) {
  test(`list responses stay isolated after a ${scope} change`, async () => {
    const h = harness(), old = await begin(h);
    h.changeScope(scope);
    const before = h.snapshot();
    h.settle(old.index, 'success', 'old-scope'); await old.pending;
    assert.deepEqual(h.snapshot(), before);
  });
}

for (const missing of ['hotel', 'range']) {
  test(`a new request with no valid ${missing} cannot restore the previous list`, async () => {
    const h = harness(), old = await begin(h);
    if (missing === 'hotel') h.refs.filterReportHotel.value = '';
    else h.refs.priceSuggestionFilter.value.end_date = '';
    const invalid = await begin(h, 'query'); await invalid.pending;
    assert.equal(invalid.index, -1);
    const expected = h.price();
    assert.equal(expected.state.status, missing === 'hotel' ? 'empty' : 'failed');
    h.settle(old.index, 'success', 'old-valid'); await old.pending;
    assert.deepEqual(h.price(), expected);
  });
}

for (const outcome of ['success', 'failed', 'exception']) {
  test(`an earlier bundle ${outcome} cannot replace or clear a later list result`, async () => {
    const h = harness(), bundle = await begin(h, 'bundle'), list = await begin(h, 'query');
    h.settle(list.index, 'success', 'new-list'); await list.pending;
    const expected = h.price();
    h.settle(bundle.index, outcome, 'earlier-bundle'); await bundle.pending;
    assert.deepEqual(h.price(), expected);
    assert.equal(h.refs.revenueAiOverviewLoading.value, false);
    if (outcome === 'success') {
      assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, 'earlier-bundle');
      assert.equal(h.refs.roomTypeConfigList.value[0].id, 'earlier-bundle');
    }
  });

  test(`an earlier list ${outcome} cannot replace the later bundle price result`, async () => {
    const h = harness(), list = await begin(h), bundle = await begin(h, 'bundle');
    h.settle(bundle.index, 'success', 'new-bundle'); await bundle.pending;
    const expected = h.snapshot();
    h.settle(list.index, outcome, 'earlier-list'); await list.pending;
    assert.deepEqual(h.snapshot(), expected);
  });
}

for (const filter of ['page', 'status']) {
  test(`bundle retains same-date analysis and rooms after a new price ${filter} while that list is pending`, async () => {
    const h = harness(), bundle = await begin(h, 'bundle');
    if (filter === 'page') h.refs.priceSuggestionPagination.value.page = 2;
    else h.refs.priceSuggestionFilter.value.status = 2;
    const list = await begin(h);
    const expected = h.price();
    h.settle(bundle.index, 'success', `bundle-after-${filter}`); await bundle.pending;
    assert.deepEqual(h.price(), expected);
    assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, `bundle-after-${filter}`);
    assert.equal(h.refs.roomTypeConfigList.value[0].id, `bundle-after-${filter}`);
    assert.equal(h.refs.revenueAiOverviewLoading.value, false);
    h.settle(list.index, 'success', 'latest-price'); await list.pending;
    assert.equal(h.refs.priceSuggestions.value[0].id, 'latest-price');
  });
}

test('same-page bundle completion leaves the newer list pending while completing its own other sections', async () => {
  const h = harness(), bundle = await begin(h, 'bundle'), list = await begin(h, 'query');
  const expected = h.price();
  assert.equal(expected.state.status, 'loading');
  h.settle(bundle.index, 'success', 'bundle-other-sections'); await bundle.pending;
  assert.deepEqual(h.price(), expected);
  assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, 'bundle-other-sections');
  assert.equal(h.refs.roomTypeConfigList.value[0].id, 'bundle-other-sections');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
  h.settle(list.index, 'success', 'current-price'); await list.pending;
  assert.equal(h.refs.priceSuggestions.value[0].id, 'current-price');
});

test('same-page old list completion leaves the newer bundle price loading and bundle busy', async () => {
  const h = harness(), list = await begin(h), bundle = await begin(h, 'bundle');
  const expected = h.snapshot();
  assert.equal(expected.price.state.status, 'loading');
  assert.equal(expected.busy, true);
  h.settle(list.index, 'success', 'old-list'); await list.pending;
  assert.deepEqual(h.snapshot(), expected);
  h.settle(bundle.index, 'success', 'current-bundle'); await bundle.pending;
  assert.equal(h.refs.priceSuggestions.value[0].id, 'current-bundle');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});

for (const outcome of ['success', 'failed', 'exception']) {
  test(`earlier bundle ${outcome} cannot overwrite a later bundle or release its ownership`, async () => {
    const h = harness(), old = await begin(h, 'bundle'), current = await begin(h, 'bundle');
    h.settle(current.index, 'success', 'current-bundle'); await current.pending;
    const expected = h.snapshot();
    h.settle(old.index, outcome, 'old-bundle'); await old.pending;
    assert.deepEqual(h.snapshot(), expected);
  });
}

test('an old bundle completion leaves a newer bundle busy until its own response', async () => {
  const h = harness(), old = await begin(h, 'bundle'), current = await begin(h, 'bundle');
  const expected = h.snapshot();
  assert.equal(expected.busy, true);
  h.settle(old.index, 'success', 'old-bundle'); await old.pending;
  assert.deepEqual(h.snapshot(), expected);
  h.settle(current.index, 'success', 'current-bundle'); await current.pending;
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});

for (const scope of ['hotel', 'session', 'epoch', 'date']) {
  test(`bundle results stay isolated after a ${scope} change`, async () => {
    const h = harness(), bundle = await begin(h, 'bundle');
    h.changeScope(scope);
    const expected = h.price(), analysis = copy(h.refs.revenueAnalysisData.value);
    h.settle(bundle.index, 'success', 'obsolete-scope'); await bundle.pending;
    assert.deepEqual(h.price(), expected);
    assert.deepEqual(copy(h.refs.revenueAnalysisData.value), analysis);
    if (scope === 'date') assert.equal(h.refs.revenueAiOverviewLoading.value, false,
      'the still-owned bundle must release its busy flag even when its dates changed');
  });
}

test('a current bundle failure remains failed and a later bundle recovers', async () => {
  const h = harness(), bad = await begin(h, 'bundle');
  h.settle(bad.index, 'failed', 'current-bundle'); await bad.pending;
  assert.equal(h.refs.revenueLoadState.value.bundle.status, 'failed');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
  const retry = await begin(h, 'bundle');
  h.settle(retry.index, 'success', 'recovered-bundle'); await retry.pending;
  assert.equal(h.refs.revenueLoadState.value.bundle.status, 'ready');
  assert.equal(h.refs.priceSuggestions.value[0].id, 'recovered-bundle');
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});
