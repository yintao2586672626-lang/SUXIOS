import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('            const loadHomeTrends = async () => {');
const end = source.indexOf('            const selectHomeTrendRange =', start);
assert.ok(start >= 0 && end > start, 'home trend loader is present');
const loader = source.slice(start, end);
const emptyStart = source.indexOf('            const emptyHomeTrendData =');
const emptyEnd = source.indexOf('            const homeTrendData =', emptyStart);
assert.ok(emptyStart >= 0 && emptyEnd > emptyStart, 'home trend empty state is present');
const emptyState = source.slice(emptyStart, emptyEnd);

function makeHarness({ range = 'custom', dates = {}, response = { code: 500 }, rejects = false, loading = false } = {}) {
  const oldCard = { key: 'revenue', value: 9000 };
  const state = {
    homeTrendData: { value: {
      data_status: 'ok', sample_days: 4, updated_at: '2026-05-05 10:00:00',
      cards: [oldCard], chart: { labels: ['05-05'], metrics: {} },
      interpretation: { judgement: '旧判断' },
    } },
    homeTrendLoading: { value: loading },
    homeTrendRange: { value: range },
    homeTrendCustomRange: { value: { start_date: '', end_date: '', ...dates } },
    requestCount: 0,
  };
  const context = {
    ...state,
    token: { value: 'test-present' }, currentPage: { value: 'compass' }, filterReportHotel: { value: '121' },
    isCompassDataPage: () => true, captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    currentPageReadPolicy: () => 'current', showToast: () => {}, nextTick: async () => {},
    scheduleHomeTrendChartRender: () => {}, URLSearchParams,
    request: async () => { state.requestCount++; if (rejects) throw Error('read failed'); return response; },
    console: { error: () => {} },
  };
  vm.runInNewContext(`let homeTrendRequestId = 0; ${emptyState}; ${loader}; globalThis.runHomeTrends = loadHomeTrends;`, context);
  return { state, run: context.runHomeTrends };
}

for (const scenario of [
  { name: 'incomplete custom dates', options: { loading: true }, expectedRequests: 0 },
  { name: 'HTTP failure', options: { dates: { start_date: '2026-05-01', end_date: '2026-05-05' } }, expectedRequests: 1 },
  { name: 'network failure', options: { dates: { start_date: '2026-05-01', end_date: '2026-05-05' }, rejects: true }, expectedRequests: 1 },
]) {
  test(`home trend ${scenario.name} does not show stale facts`, async () => {
    const { state, run } = makeHarness(scenario.options);
    await run();
    assert.equal(state.requestCount, scenario.expectedRequests);
    assert.equal(state.homeTrendData.value.sample_days, 0);
    assert.equal(state.homeTrendLoading.value, false);
    assert.equal(state.homeTrendData.value.cards.length, 0);
    assert.equal(state.homeTrendData.value.chart?.labels?.length || 0, 0);
    assert.notEqual(state.homeTrendData.value.interpretation?.judgement, '旧判断');
  });
}

test('home data source calls a failed trend read a failure', () => {
  const context = { window: {} };
  vm.runInNewContext(readFileSync('public/home-static.js', 'utf8'), context);
  const sources = context.window.SUXI_HOME_STATIC.buildHomeDataSources({
    trendReady: null, sampleDays: 0,
  });
  assert.equal(sources[0].status, '读取失败');
  assert.equal(sources[0].ready, false);
});

test('successful trend read replaces the previous error and restores samples', async () => {
  const response = { code: 200, data: {
    data_status: 'ok', sample_days: 2, cards: [{ key: 'revenue', value: 500 }],
    chart: { labels: ['05-01', '05-02'], metrics: {} }, interpretation: { judgement: '新判断' },
  } };
  const { state, run } = makeHarness({ range: '30', response });
  await run();
  assert.equal(state.homeTrendData.value.sample_days, 2);
  assert.equal(state.homeTrendData.value.cards[0].value, 500);
  assert.equal(state.homeTrendData.value.interpretation.judgement, '新判断');
});
