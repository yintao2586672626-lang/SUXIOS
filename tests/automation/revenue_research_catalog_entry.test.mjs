import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/19-page-revenue-research-center.html', 'utf8');
const slice = (start, end) => {
  const first = main.indexOf(start);
  const last = main.indexOf(end, first + start.length);
  assert.ok(first >= 0 && last > first, `missing source boundary: ${start}`);
  return main.slice(first, last);
};
const catalogSource = slice('const requireRevenueResearchStatic =', 'const revenueResearchRunFor =');
const ref = (value) => ({ value });
const tick = async () => { for (let index = 0; index < 8; index += 1) await Promise.resolve(); };
const catalog = () => ({
  revenueResearchProducts: [{ key: 'forecast', name: '经营预测' }, { key: 'pricing', name: '调价情景' }],
  revenueResearchSteps: ['读取信息', '形成研究'],
});

function mount({ page = 'revenue-research-center', loggedIn = true, load = async () => catalog() } = {}) {
  const watchers = [];
  const calls = [];
  const observed = (source) => Array.isArray(source)
    ? source.map((item) => typeof item === 'function' ? item() : item.value)
    : (typeof source === 'function' ? source() : source.value);
  const s = {
    ref, computed: (read) => ({ get value() { return read(); } }),
    currentPage: ref(page), isLoggedIn: ref(loggedIn),
    permittedHotels: ref([{ id: 80, name: '测试门店' }]), hotels: ref([]), filterReportHotel: ref('80'),
    console: { error() {} }, load,
    loadRevenueResearchStatic: async () => { calls.push('load-catalog'); return s.load(); },
    watch(source, callback, options = {}) {
      const value = observed(source);
      watchers.push({ source, callback, previous: JSON.stringify(value) });
      if (options.immediate) callback(value);
    },
  };
  vm.createContext(s);
  vm.runInContext(`${catalogSource}\nthis.catalog = {
    products: revenueResearchProducts, steps: revenueResearchSteps,
    loading: revenueResearchCatalogLoading, error: revenueResearchCatalogError,
    retry: retryRevenueResearchCatalog, ensure: ensureRevenueResearchReady,
  };`, s);
  const flush = () => watchers.forEach((watcher) => {
    const current = observed(watcher.source);
    const serialized = JSON.stringify(current);
    if (serialized !== watcher.previous) {
      watcher.previous = serialized;
      watcher.callback(current);
    }
  });
  return { s, calls, flush };
}

test('an authenticated direct URL entry immediately loads and displays the catalog without a page change', async () => {
  let finish;
  const h = mount({ load: () => new Promise((resolve) => { finish = resolve; }) });
  assert.equal(h.s.currentPage.value, 'revenue-research-center');
  assert.equal(h.calls.length, 1);
  assert.equal(h.s.catalog.loading.value, true);
  assert.equal(h.s.catalog.products.value.length, 0);
  finish(catalog());
  await tick();
  assert.equal(h.s.catalog.products.value.length, 2);
  assert.equal(h.s.catalog.steps.value.length, 2);
  assert.equal(h.s.catalog.loading.value, false);
  assert.equal(h.s.catalog.error.value, '');
});

test('a direct URL before login waits until login completes and needs no navigation workaround', async () => {
  const h = mount({ loggedIn: false });
  await tick();
  assert.equal(h.calls.length, 0);
  assert.equal(h.s.catalog.loading.value, false);
  h.s.isLoggedIn.value = true;
  h.flush();
  await tick();
  assert.equal(h.calls.length, 1);
  assert.equal(h.s.catalog.products.value.length, 2);
  assert.equal(h.s.currentPage.value, 'revenue-research-center');
});

test('other pages do not load the catalog and returning to a populated catalog reuses it', async () => {
  const h = mount({ page: 'compass' });
  await tick();
  assert.equal(h.calls.length, 0);
  h.s.currentPage.value = 'revenue-research-center';
  h.flush();
  await tick();
  assert.equal(h.calls.length, 1);
  h.s.currentPage.value = 'compass';
  h.flush();
  h.s.currentPage.value = 'revenue-research-center';
  h.flush();
  await tick();
  assert.equal(h.calls.length, 1);
});

test('an automatic load failure is visible, stops loading and recovers through the actual retry action', async () => {
  const h = mount({ load: async () => { throw new Error('fixture catalog error'); } });
  await tick();
  assert.equal(h.s.catalog.products.value.length, 0);
  assert.equal(h.s.catalog.loading.value, false);
  assert.match(h.s.catalog.error.value, /研究入口加载失败/);
  h.s.load = async () => catalog();
  await h.s.catalog.retry();
  assert.equal(h.calls.length, 2);
  assert.equal(h.s.catalog.products.value.length, 2);
  assert.equal(h.s.catalog.error.value, '');
  assert.equal(h.s.catalog.loading.value, false);
});

test('empty or malformed catalog arrays fail visibly without committing a partial catalog', async () => {
  for (const broken of [
    { revenueResearchProducts: [], revenueResearchSteps: ['ready'] },
    { revenueResearchProducts: [{ key: 'forecast' }], revenueResearchSteps: [] },
    { revenueResearchProducts: {}, revenueResearchSteps: ['ready'] },
    { revenueResearchProducts: [{ key: 'forecast' }] },
  ]) {
    const h = mount({ load: async () => broken });
    await tick();
    assert.equal(h.s.catalog.products.value.length, 0);
    assert.equal(h.s.catalog.steps.value.length, 0);
    assert.match(h.s.catalog.error.value, /研究入口加载失败/);
    assert.equal(h.s.catalog.loading.value, false);
  }
});

test('the page renders loading and failure states and exposes the same retry action from the root', () => {
  assert.match(template, /v-if="revenueResearchCatalogLoading && !revenueResearchProducts\.length"[^>]*role="status"/);
  assert.match(template, /v-if="revenueResearchCatalogError"[^>]*role="alert"/);
  assert.match(template, /@click="retryRevenueResearchCatalog" :disabled="revenueResearchCatalogLoading"/);
  assert.match(main, /revenueResearchProducts, revenueResearchCatalogLoading, revenueResearchCatalogError, retryRevenueResearchCatalog,/);
  assert.doesNotMatch(main, /runPageLoadOnce\(newPage, 'revenue-research-static', \(\) => ensureRevenueResearchReady\(\)\)/);
});
