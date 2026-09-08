import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const slice = (start, end) => {
  const first = source.indexOf(start);
  const last = source.indexOf(end, first + start.length);
  assert.ok(first >= 0 && last > first, `missing source boundary: ${start}`);
  return source.slice(first, last);
};
const readySource = slice('const ensureRevenueResearchReady = async', 'const revenueResearchStepClass =');
const runnerSource = slice('let revenueResearchRequestEpoch = 0;', 'const openRevenueResearchExecutionIntent =');
const requireSource = slice('const requireRevenueResearchStatic =', 'const revenueResearchProducts =');
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const ref = (value) => ({ value });
const product = { key: 'revenue_forecast', name: '经营预测' };
const completeResult = (hotel = '80') => ({
  id: 81, product_key: product.key, status: 'done',
  hotel_scope: hotel ? { mode: 'single_hotel', hotel_id: Number(hotel) } : { mode: 'all_permitted_hotels', hotel_id: null },
  result: {
    summary: '包含完整来源与限制的研究摘要。'.repeat(30),
    risk_signals: Array.from({ length: 6 }, (_, index) => `风险 ${index}`),
    decision_recommendations: Array.from({ length: 6 }, (_, index) => ({ title: `建议 ${index}`, action: `动作 ${index}` })),
  },
  local_sources: Array.from({ length: 7 }, (_, index) => ({ source: `source-${index}`, count: index === 6 ? null : index })),
  gaps: Array.from({ length: 5 }, (_, index) => ({ label: `字段 ${index}`, reason: `缺少来源 ${index}` })),
});

function harness() {
  const requests = [];
  const toasts = [];
  const intervals = new Map();
  const watchers = [];
  let timerId = 0;
  const s = {
    revenueResearchRuns: ref({}), revenueResearchHotelId: ref('80'), revenueResearchModelKey: ref('deepseek_chat'),
    revenueResearchProducts: ref([]), revenueResearchSteps: ref([]), isLoggedIn: ref(true),
    revenueResearchCatalogLoading: ref(false), revenueResearchCatalogError: ref(''), currentPage: ref('compass'),
    pageRequestGeneration: 1, authEpoch: 1,
    console: { error() {} },
    watch(getters, callback) {
      watchers.push({ getters, callback, previous: JSON.stringify(getters.map((getter) => typeof getter === 'function' ? getter() : getter.value)) });
    },
    setInterval: (callback) => { const id = ++timerId; intervals.set(id, callback); return id; },
    clearInterval: (id) => intervals.delete(id),
    loadRevenueResearchStatic: async () => ({ revenueResearchProducts: [product], revenueResearchSteps: ['读数据', '研究', '回读'] }),
    apiRequest: async (url, options) => { requests.push({ url, options }); return s.transport(url, options); },
    transport: async () => ({ code: 200, data: completeResult() }),
    showToast: (message, type) => toasts.push({ message, type }),
  };
  s.captureAuthSession = () => s.authEpoch;
  s.isAuthSessionCurrent = (epoch) => epoch === s.authEpoch;
  vm.createContext(s);
  vm.runInContext(`${requireSource}\n${readySource}\n${runnerSource}\nthis.run = runRevenueResearchProduct;`, s);
  const flush = () => watchers.forEach((watcher) => {
    const values = watcher.getters.map((getter) => typeof getter === 'function' ? getter() : getter.value);
    const next = JSON.stringify(values);
    if (next !== watcher.previous) { watcher.previous = next; watcher.callback(values); }
  });
  return { s, requests, toasts, intervals, flush, tick: () => intervals.forEach((callback) => callback()) };
}

test('successful research preserves the complete result, missing values, sources, risks and recommendations', async () => {
  const { s, requests, intervals } = harness();
  const exact = completeResult();
  s.transport = async () => ({ code: 200, data: exact });
  await s.run(product);
  const saved = s.revenueResearchRuns.value[product.key];
  assert.equal(saved.result, exact);
  assert.equal(saved.result.local_sources.length, 7);
  assert.equal(saved.result.local_sources[6].count, null);
  assert.equal(saved.result.gaps.length, 5);
  assert.equal(saved.result.result.risk_signals.length, 6);
  assert.equal(saved.result.result.decision_recommendations.length, 6);
  assert.equal(saved.loading, false);
  assert.equal(saved.error, '');
  assert.equal(intervals.size, 0);
  assert.equal(requests[0].url, '/revenue-research/run');
  assert.deepEqual(JSON.parse(requests[0].options.body), { product_key: product.key, model_key: 'deepseek_chat', hotel_id: '80' });
});

test('switching between hotels or all permitted hotels clears previous results and rejects the old response', async () => {
  for (const [before, after] of [['80', '81'], ['80', ''], ['', '80']]) {
    const { s, flush, intervals, toasts } = harness();
    s.revenueResearchHotelId.value = before;
    flush();
    const pending = deferred();
    const started = deferred();
    s.transport = () => { started.resolve(); return pending.promise; };
    const work = s.run(product);
    await started.promise;
    s.revenueResearchHotelId.value = after;
    flush();
    assert.equal(Object.keys(s.revenueResearchRuns.value).length, 0);
    pending.resolve({ code: 200, data: completeResult(before) });
    await work;
    assert.equal(Object.keys(s.revenueResearchRuns.value).length, 0);
    assert.equal(intervals.size, 0);
    assert.equal(toasts.length, 0);
    s.transport = async () => ({ code: 200, data: completeResult(after) });
    await s.run(product);
    assert.equal(s.revenueResearchRuns.value[product.key].result.hotel_scope.hotel_id, after ? Number(after) : null);
  }
});

test('an old scope cannot clear a newer request loading state or overwrite its result', async () => {
  const { s, flush } = harness();
  const first = deferred();
  const firstStarted = deferred();
  s.transport = () => { firstStarted.resolve(); return first.promise; };
  const firstWork = s.run(product);
  await firstStarted.promise;
  s.revenueResearchHotelId.value = '81';
  flush();
  const second = deferred();
  const secondStarted = deferred();
  s.transport = () => { secondStarted.resolve(); return second.promise; };
  const secondWork = s.run(product);
  await secondStarted.promise;
  first.resolve({ code: 200, data: completeResult('80') });
  await firstWork;
  assert.equal(s.revenueResearchRuns.value[product.key].loading, true);
  assert.equal(s.revenueResearchRuns.value[product.key].result, null);
  second.resolve({ code: 200, data: completeResult('81') });
  await secondWork;
  assert.equal(s.revenueResearchRuns.value[product.key].loading, false);
  assert.equal(s.revenueResearchRuns.value[product.key].result.hotel_scope.hotel_id, 81);
});

test('leaving and returning to the page or changing the model prevents old results and permits retry', async () => {
  for (const change of [
    (s) => { s.pageRequestGeneration += 2; },
    (s) => { s.revenueResearchModelKey.value = 'alternate_model_fixture'; },
  ]) {
    const { s, intervals, toasts } = harness();
    const pending = deferred();
    const started = deferred();
    s.transport = () => { started.resolve(); return pending.promise; };
    const work = s.run(product);
    await started.promise;
    change(s);
    pending.resolve({ code: 200, data: completeResult() });
    await work;
    assert.equal(s.revenueResearchRuns.value[product.key].result, null);
    assert.equal(s.revenueResearchRuns.value[product.key].loading, false);
    assert.equal(intervals.size, 0);
    assert.equal(toasts.length, 0);
    s.transport = async () => ({ code: 200, data: completeResult() });
    await s.run(product);
    assert.equal(s.revenueResearchRuns.value[product.key].result.id, 81);
  }
});

test('a helper load failure is visible and retryable without making a research request', async () => {
  const { s, requests, intervals } = harness();
  const load = s.loadRevenueResearchStatic;
  s.loadRevenueResearchStatic = async () => { throw new Error('fixture helper unavailable'); };
  await s.run(product);
  assert.equal(requests.length, 0);
  assert.match(s.revenueResearchRuns.value[product.key].error, /helper unavailable/);
  assert.equal(s.revenueResearchRuns.value[product.key].loading, false);
  assert.equal(intervals.size, 0);
  s.loadRevenueResearchStatic = load;
  await s.run(product);
  assert.equal(requests.length, 1);
  assert.equal(s.revenueResearchRuns.value[product.key].error, '');
  assert.equal(s.revenueResearchRuns.value[product.key].result.id, 81);
});

test('a scope change while the helper is loading prevents even the research request', async () => {
  const { s, flush, requests, intervals } = harness();
  const helper = deferred();
  s.loadRevenueResearchStatic = () => helper.promise;
  const work = s.run(product);
  s.revenueResearchHotelId.value = '';
  flush();
  helper.resolve({ revenueResearchProducts: [product], revenueResearchSteps: ['读数据'] });
  await work;
  assert.equal(requests.length, 0);
  assert.equal(Object.keys(s.revenueResearchRuns.value).length, 0);
  assert.equal(intervals.size, 0);
});

test('a missing or wrong hotel response is rejected and does not retain a result', async () => {
  for (const [hotelId, scope] of [
    ['80', null], ['80', { mode: 'single_hotel', hotel_id: 81 }],
    ['80', { mode: 'all_permitted_hotels', hotel_id: 80 }],
    ['', { mode: 'single_hotel', hotel_id: 80 }], ['', { mode: 'single_hotel', hotel_id: null }],
    ['', { mode: 'all_permitted_hotels', hotel_id: 80 }],
  ]) {
    const { s, flush } = harness();
    s.revenueResearchHotelId.value = hotelId;
    flush();
    s.transport = async () => ({ code: 200, data: { ...completeResult(), hotel_scope: scope } });
    await s.run(product);
    assert.equal(s.revenueResearchRuns.value[product.key].result === null, true, `wrong scope must stay blocked: ${JSON.stringify(scope)}`);
    assert.match(s.revenueResearchRuns.value[product.key].error, /酒店范围与本次请求不一致/);
    assert.equal(s.revenueResearchRuns.value[product.key].loading, false);
  }
});

test('signing out clears the research state and a response from the former session cannot restore it', async () => {
  const { s, flush, intervals } = harness();
  const pending = deferred();
  const started = deferred();
  s.transport = () => { started.resolve(); return pending.promise; };
  const work = s.run(product);
  await started.promise;
  s.authEpoch += 1;
  s.isLoggedIn.value = false;
  flush();
  pending.resolve({ code: 200, data: completeResult() });
  await work;
  assert.equal(Object.keys(s.revenueResearchRuns.value).length, 0);
  assert.equal(intervals.size, 0);
});

test('independent products finish independently and duplicate clicks do not send duplicate requests', async () => {
  const { s, requests } = harness();
  const first = deferred();
  const started = deferred();
  s.transport = () => { started.resolve(); return first.promise; };
  const firstWork = s.run(product);
  await started.promise;
  await s.run(product);
  assert.equal(requests.length, 1);
  const secondProduct = { key: 'pricing_scenario' };
  s.transport = async () => ({ code: 200, data: { ...completeResult(), product_key: secondProduct.key } });
  await s.run(secondProduct);
  assert.equal(s.revenueResearchRuns.value[product.key].loading, true);
  assert.equal(s.revenueResearchRuns.value[secondProduct.key].loading, false);
  first.resolve({ code: 200, data: completeResult() });
  await firstWork;
  assert.equal(s.revenueResearchRuns.value[product.key].result.product_key, product.key);
  assert.equal(s.revenueResearchRuns.value[secondProduct.key].result.product_key, secondProduct.key);
  assert.equal(requests.length, 2);
});
