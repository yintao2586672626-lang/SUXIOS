import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_REVENUE_INPUT_READ_SOURCE
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
  ...(main.includes('            const applyRoomTypeReadback =') ? ['applyRoomTypeReadback'] : []),
  ...(main.includes('            const applyDemandForecastReadback =') ? ['applyDemandForecastReadback'] : []),
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent',
  'createRoomTypeConfigForm', 'firstEnabledRoomTypeId', 'createPriceSuggestionPagination',
  'createRevenueLoadState', 'createEmptyRevenueAnalysisData', 'createEmptyRevenueDashboard',
  'setRevenueLoadState', 'loadRoomTypes', 'loadDemandForecasts', 'loadRevenueAnalysisBundle',
].map(declaration).join('\n');
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const flush = () => new Promise(resolve => setImmediate(resolve));
const inputs = [{ kind: 'rooms', key: 'roomTypes' }, { kind: 'forecasts', key: 'forecasts' }];
const outcomes = ['success', 'failed', 'exception'];

// Real Vue refs, state setter, readers, factories and scope predicates are used.
// Only transport and unrelated competitor/overview boundaries are synthetic.
function harness() {
  const requests = [], notices = [], refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) {
    refs[match[1]] ??= Vue.ref(null);
  }
  const initial = {
    token: 'synthetic-session', filterReportHotel: '81', revenueAiBusinessDate: '2026-09-12',
    priceSuggestionFilter: { date: '2026-09-12', end_date: '2026-09-13', status: 0 },
    priceSuggestionPagination: { total: 0, page: 1, page_size: 20, total_page: 1 },
    forecastFilter: { start_date: '2026-09-12', end_date: '2026-09-13' },
    competitorFilter: { date: '2026-09-12' }, demandForecastForm: { room_type_id: 0 },
    competitorPriceForm: { room_type_id: 0 }, priceSuggestions: [], priceSuggestionReview: null,
    roomTypeConfigList: [], roomTypeConfigMeta: {}, demandForecasts: [], forecastAccuracy: {},
    highDemandDates: [], revenueAiOverviewLoading: false, revenueAiOverviewError: '',
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = Vue.ref(value);
  const context = vm.createContext({ ...refs, URLSearchParams, console: { error() {} },
    authSessionEpoch: 1, agentRevenueStateEpoch: 1,
    priceSuggestionRequestSeq: 0, revenueAnalysisBundleRequestSeq: 0,
    revenueAiOverviewRequestSeq: 0, revenueAiOverviewRequestPromises: new Map(),
    ensureRevenueAiStaticReady: async () => true, loadRevenueAiOverview: async () => null,
    resetCompetitorAnalysisView() {}, formatDate: () => '2026-09-12',
    revenueAiResolveOverviewResponse: ({ response }) => ({ overview: response.data, errorMessage: '' }),
    loadCompetitorAnalysis: async ({ priceResponsePromise }) => priceResponsePromise.catch(() => null),
    showToast: (...args) => notices.push(args),
    request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject, settled: false })),
  });
  vm.runInContext(`${production}
    revenueLoadState.value = createRevenueLoadState();
    roomTypeConfigForm.value = createRoomTypeConfigForm();
    globalThis.api = { rooms: loadRoomTypes, forecasts: loadDemandForecasts,
      bundle: loadRevenueAnalysisBundle, setState: setRevenueLoadState };`, context);
  const state = key => refs.revenueLoadState.value[key];
  const snapshot = key => copy(key === 'roomTypes' ? {
    state: state(key), list: refs.roomTypeConfigList.value, meta: refs.roomTypeConfigMeta.value,
    form: refs.roomTypeConfigForm.value, forecastRoom: refs.demandForecastForm.value.room_type_id,
    competitorRoom: refs.competitorPriceForm.value.room_type_id,
  } : { state: state(key), list: refs.demandForecasts.value,
    accuracy: refs.forecastAccuracy.value, highDemand: refs.highDemandDates.value });
  const settle = (index, outcome = 'success', marker = `response-${index}`) => {
    const row = requests[index];
    assert.ok(row, `request ${index} exists`);
    assert.equal(row.settled, false, `request ${index} settles once`);
    row.settled = true;
    if (outcome === 'exception') return row.reject(new Error(`synthetic ${marker} exception`));
    if (outcome === 'failed') return row.resolve({ code: 503, message: `synthetic ${marker} failure` });
    const empty = outcome === 'empty';
    const rooms = { list: empty ? [] : [{ id: 100 + index, marker, name: marker, is_enabled: 1, min_price: 0 }],
      input_scope: 'hotel_room_type', evidence_status: 'synthetic', next_action: marker };
    const forecasts = { forecasts: empty ? [] : [{ marker, predicted_demand: 0 }],
      accuracy: empty ? {} : { marker, mae: 0 }, high_demand_dates: empty ? [] : [{ marker }] };
    const data = row.url.startsWith('/agent/revenue-bundle?') ? {
      overview: { marker }, analysis: { statistics: { marker } }, dashboard: { today_suggestions: [{ marker }] },
      forecasts, competitor: {}, room_types: rooms,
      price_suggestions: { list: [], pagination: { total: 0, page: 1, page_size: 20, total_page: 1 } },
    } : row.url.startsWith('/agent/room-types?') ? rooms : forecasts;
    row.resolve({ code: 200, data });
  };
  const changeScope = scope => {
    if (scope === 'hotel') refs.filterReportHotel.value = '82';
    else if (scope === 'session') context.authSessionEpoch++;
    else if (scope === 'token') refs.token.value = 'synthetic-new-session';
    else if (scope === 'epoch') context.agentRevenueStateEpoch++;
    else if (scope === 'date') refs.forecastFilter.value.start_date = '2026-09-11';
    else if (scope === 'end-date') refs.forecastFilter.value.end_date = '2026-09-14';
    else if (scope === 'bundle-date') refs.priceSuggestionFilter.value.date = '2026-09-11';
    else if (scope === 'bundle-end-date') refs.priceSuggestionFilter.value.end_date = '2026-09-14';
  };
  return { api: context.api, context, refs, requests, notices, state, snapshot, settle, changeScope };
}

async function begin(h, kind) {
  const previous = h.requests.length;
  const pending = h.api[kind]();
  await flush();
  return { pending, index: h.requests.length > previous ? previous : -1 };
}

for (const kind of ['rooms', 'bundle']) {
  for (const enabled of [true, false]) {
    test(`${kind}: room readback preserves an explicit choice and only defaults an unselected form to an enabled room`, async () => {
      const h = harness();
      h.refs.demandForecastForm.value.room_type_id = '91';
      const current = await begin(h, kind);
      const rooms = { list: [{ id: 101, is_enabled: 0 }, { id: 102, is_enabled: enabled ? 1 : 0 }] };
      h.requests[current.index].resolve({ code: 200, data: kind === 'rooms' ? rooms : { room_types: rooms } });
      await current.pending;
      assert.equal(h.refs.demandForecastForm.value.room_type_id, '91');
      assert.equal(h.refs.competitorPriceForm.value.room_type_id, enabled ? 102 : 0);
      assert.equal(h.state('roomTypes').status, 'ready');
    });
  }
}

for (const { kind, key } of inputs) {
  for (const outcome of ['failed', 'exception']) {
    test(`${key}: bundle ${outcome} exits loading and an independent retry recovers`, async () => {
      const h = harness(), bundle = await begin(h, 'bundle');
      h.settle(bundle.index, outcome, 'bundle-failure'); await bundle.pending;
      assert.equal(h.state(key).status, 'failed');
      assert.match(h.state(key).error, /bundle-failure/);
      assert.equal(h.refs.revenueAiOverviewLoading.value, false);
      const retry = await begin(h, kind);
      assert.equal(h.state(key).status, 'loading');
      h.settle(retry.index, 'success', 'independent-retry'); await retry.pending;
      assert.equal(h.state(key).status, 'ready');
      assert.equal(h.snapshot(key).list[0].marker, 'independent-retry');
    });
  }

  for (const direction of ['bundle-first', 'independent-first', 'independent-repeat']) {
    for (const outcome of outcomes) {
      for (const completion of ['new-first', 'old-first']) {
        test(`${key}: ${direction} / old ${outcome} / ${completion} retains the newest reader`, async () => {
          const h = harness();
          const old = await begin(h, direction === 'bundle-first' ? 'bundle' : kind);
          const current = await begin(h, direction === 'independent-first' ? 'bundle' : kind);
          if (completion === 'new-first') {
            h.settle(current.index, 'success', 'new-owner'); await current.pending;
          }
          const owner = h.state(key), expected = h.snapshot(key), noticeCount = h.notices.length;
          if (completion === 'old-first') assert.equal(owner.status, 'loading');
          h.settle(old.index, outcome, 'old-owner'); await old.pending;
          assert.equal(h.state(key), owner, 'obsolete completion must preserve the actual owner object');
          assert.deepEqual(h.snapshot(key), expected);
          if (direction !== 'bundle-first') assert.equal(h.notices.length, noticeCount, 'stale standalone failures are silent');
          if (direction === 'bundle-first') {
            assert.equal(h.refs.revenueAiOverviewLoading.value, false);
            if (outcome === 'success') assert.equal(h.refs.revenueAnalysisData.value.statistics.marker, 'old-owner');
            else assert.match(h.refs.revenueAiOverviewError.value, /old-owner/, 'the current bundle still reports its other failure');
          }
          if (completion === 'old-first') {
            if (direction === 'independent-first') assert.equal(h.refs.revenueAiOverviewLoading.value, true);
            h.settle(current.index, 'success', 'new-owner'); await current.pending;
          }
          assert.equal(h.state(key).status, 'ready');
          assert.equal(h.snapshot(key).list[0].marker, 'new-owner');
          if (direction === 'independent-first') assert.equal(h.refs.revenueAiOverviewLoading.value, false);
        });
      }
    }
  }

  test(`${key}: actual state replacement in another module preserves Vue owner identity`, async () => {
    const h = harness(), current = await begin(h, kind), owner = h.state(key);
    assert.equal(Vue.isReactive(owner), true);
    const previousTop = h.refs.revenueLoadState.value;
    h.api.setState('analysis', 'ready');
    h.api.setState(key === 'roomTypes' ? 'forecasts' : 'roomTypes', 'failed', 'other input unavailable');
    assert.notEqual(h.refs.revenueLoadState.value, previousTop);
    assert.equal(h.state(key), owner, 'the module token must not be cloned or invalidated');
    h.settle(current.index, 'success', 'owned-response'); await current.pending;
    assert.equal(h.state(key).status, 'ready');
    assert.equal(h.snapshot(key).list[0].marker, 'owned-response');
  });

  test(`${key}: concurrent other-input success does not cancel the owned request`, async () => {
    const h = harness(), current = await begin(h, kind), owner = h.state(key);
    const other = await begin(h, kind === 'rooms' ? 'forecasts' : 'rooms');
    h.settle(other.index, 'success', 'other-input'); await other.pending;
    assert.equal(h.state(key), owner);
    h.settle(current.index, 'success', 'owned-input'); await current.pending;
    assert.equal(h.snapshot(key).list[0].marker, 'owned-input');
    assert.equal(h.state(key).status, 'ready');
  });

  for (const scope of ['hotel', 'session', 'token', 'epoch']) {
    test(`${key}: ${scope} changes isolate success, business failure and thrown errors`, async () => {
      for (const outcome of outcomes) {
        const h = harness(), old = await begin(h, kind);
        h.changeScope(scope);
        const expected = h.snapshot(key);
        h.settle(old.index, outcome, 'obsolete-scope'); await old.pending;
        assert.deepEqual(h.snapshot(key), expected);
        assert.equal(h.notices.length, 0);
      }
    });
  }

  test(`${key}: clearing the hotel creates an empty owner and prevents old data restoration`, async () => {
    const h = harness(), old = await begin(h, kind);
    h.refs.filterReportHotel.value = '';
    const noHotel = await begin(h, kind); await noHotel.pending;
    assert.equal(noHotel.index, -1);
    assert.equal(h.state(key).status, 'empty');
    const expected = h.snapshot(key);
    h.settle(old.index, 'success', 'old-hotel'); await old.pending;
    assert.deepEqual(h.snapshot(key), expected);
    assert.equal(h.notices.length, 0);
  });

  test(`${key}: a successful empty independent response is empty, not a failed or fabricated sample`, async () => {
    const h = harness(), current = await begin(h, kind);
    h.settle(current.index, 'empty'); await current.pending;
    assert.equal(h.state(key).status, 'empty');
    assert.deepEqual(h.snapshot(key).list, []);
    assert.equal(h.notices.length, 0);
  });
}

for (const scope of ['date', 'end-date']) {
  test(`forecast ${scope} isolates every obsolete independent outcome`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), old = await begin(h, 'forecasts');
      h.changeScope(scope);
      const expected = h.snapshot('forecasts');
      h.settle(old.index, outcome, 'obsolete-date'); await old.pending;
      assert.deepEqual(h.snapshot('forecasts'), expected);
      assert.equal(h.notices.length, 0);
    }
  });
}

test('hotel-scoped room configuration remains readable after a forecast-only date change', async () => {
  const h = harness(), current = await begin(h, 'rooms');
  h.changeScope('date');
  h.settle(current.index, 'success', 'hotel-scoped'); await current.pending;
  assert.equal(h.state('roomTypes').status, 'ready');
  assert.equal(h.snapshot('roomTypes').list[0].marker, 'hotel-scoped');
});

for (const scope of ['hotel', 'session', 'epoch', 'bundle-date', 'bundle-end-date']) {
  test(`bundle ${scope} guard still isolates both input sections`, async () => {
    for (const outcome of outcomes) {
      const h = harness(), old = await begin(h, 'bundle');
      h.changeScope(scope);
      const expected = inputs.map(({ key }) => h.snapshot(key));
      h.settle(old.index, outcome, 'obsolete-bundle'); await old.pending;
      assert.deepEqual(inputs.map(({ key }) => h.snapshot(key)), expected);
      assert.equal(h.notices.length, 0);
    }
  });
}

test('bundle input owners survive unrelated module-state replacements with real Vue refs', async () => {
  const h = harness(), current = await begin(h, 'bundle');
  const owners = inputs.map(({ key }) => h.state(key));
  h.api.setState('logs', 'ready');
  h.api.setState('dashboard', 'ready');
  for (const [index, { key }] of inputs.entries()) assert.equal(h.state(key), owners[index]);
  h.settle(current.index, 'success', 'owned-bundle'); await current.pending;
  for (const { key } of inputs) {
    assert.equal(h.state(key).status, 'ready');
    assert.equal(h.snapshot(key).list[0].marker, 'owned-bundle');
  }
});

test('a no-hotel bundle makes both inputs empty without issuing transport requests', async () => {
  const h = harness();
  h.refs.filterReportHotel.value = '';
  const current = await begin(h, 'bundle'); await current.pending;
  assert.equal(current.index, -1);
  assert.equal(h.requests.length, 0);
  for (const { key } of inputs) {
    assert.equal(h.state(key).status, 'empty');
    assert.deepEqual(h.snapshot(key).list, []);
  }
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});

test('a successful bundle with no input samples preserves both empty states', async () => {
  const h = harness(), current = await begin(h, 'bundle');
  h.settle(current.index, 'empty'); await current.pending;
  for (const { key } of inputs) {
    assert.equal(h.state(key).status, 'empty');
    assert.deepEqual(h.snapshot(key).list, []);
  }
  assert.deepEqual(copy(h.refs.forecastAccuracy.value), {});
  assert.deepEqual(copy(h.refs.highDemandDates.value), []);
  assert.equal(h.refs.revenueAiOverviewLoading.value, false);
});
