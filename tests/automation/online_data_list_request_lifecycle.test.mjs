import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const declaration = (name) => {
  const start = source.indexOf(`            const ${name} =`);
  const end = /\r?\n            };/.exec(source.slice(start));
  assert.ok(start >= 0 && end, `missing production declaration: ${name}`);
  return source.slice(start, start + end.index + end[0].length);
};
const abortError = () => Object.assign(new Error('Page request was cancelled'), { name: 'AbortError' });
const response = (id = 'synthetic-current') => ({ code: 200, data: {
  list: [{ id, system_hotel_id: 901 }],
  pagination: { total: 1, page: 1, page_size: 30 },
  data_quality_summary: { source: 'synthetic-fixture' },
} });

function harness() {
  const requests = [], errors = [];
  const effects = { pruned: 0, cacheCleared: 0 };
  const context = vm.createContext({
    URLSearchParams, console: { error: (...args) => errors.push(args) },
    authSessionEpoch: 1, pageRequestGeneration: 1,
    currentPage: { value: 'online-data' }, authContext: { value: { tenantId: 'synthetic-tenant' } },
    filterReportHotel: { value: '901' }, revenueAiBusinessDate: { value: '' }, coreOperationsTargetDate: { value: '' },
    currentBusinessRequestContext: () => ({ tenant_id: 'synthetic-tenant' }),
    onlineDataFilter: { value: { hotel_id: '901', source: 'ctrip', start_date: '2026-09-01', end_date: '2026-09-01' } },
    onlineDataPage: { value: 1 }, onlineDataPagination: { value: { total: 0, page: 1, page_size: 30 } },
    onlineDataList: { value: [] }, onlineDataQualitySummary: { value: null },
    onlineDataListError: { value: '' }, onlineDataListLoading: { value: false },
    downloadCenterTab: { value: 'all' }, onlineDataLoadedQuery: { value: null },
    onlineDataListRequestPromises: new Map(), onlineDataListResultCache: new Map(),
    onlineDataListActiveRequestKey: '', onlineDataListSnapshotKey: '', onlineDataListSnapshotSession: {},
    captureAuthSession: () => ({ epoch: context.authSessionEpoch }),
    isAuthSessionCurrent: session => session?.epoch === context.authSessionEpoch,
    clearCoordinatedGetSuccessCache: () => { effects.cacheCleared++; },
    pruneSelectedOnlineDataIds: () => { effects.pruned++; }, debugLog: () => {},
    request: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  });
  vm.runInContext([
    'normalizeRequestCacheOptions', 'readRequestCache', 'writeRequestCache',
    'currentPageReadPolicy', 'isPageLoadPolicyCurrent', 'loadOnlineDataList',
  ].map(declaration).join('\n') + '\nglobalThis.load = loadOnlineDataList;', context);
  const revisit = () => {
    context.currentPage.value = 'compass';
    context.pageRequestGeneration++;
    context.currentPage.value = 'online-data';
    context.pageRequestGeneration++;
  };
  return { context, requests, errors, effects, load: context.load, revisit };
}

test('online data list cancellation is silent and the next request can load current rows', async () => {
  const h = harness();
  const cancelled = h.load();
  h.requests[0].reject(abortError());
  assert.equal(await cancelled, null);
  assert.equal(h.errors.length, 0, 'normal cancellation must not become a product console error');
  assert.equal(h.context.onlineDataListError.value, '');
  assert.equal(h.context.onlineDataListLoading.value, false);
  assert.equal(h.context.onlineDataListRequestPromises.size, 0);
  const current = h.load();
  const saved = response();
  h.requests[1].resolve(saved);
  assert.equal(await current, saved.data.list);
  assert.equal(h.context.onlineDataList.value, saved.data.list);
});

for (const scopeChange of ['page revisit', 'session change']) {
  for (const outcome of ['cancelled', 'success', 'failure']) {
    test(`online data list ${outcome} from an old ${scopeChange} cannot take over the new same-query request`, async () => {
      const h = harness();
      const previous = h.load();
      if (scopeChange === 'page revisit') h.revisit();
      else h.context.authSessionEpoch++;
      const current = h.load();
      const requestCount = h.requests.length;
      if (outcome === 'cancelled') h.requests[0].reject(abortError());
      else if (outcome === 'success') h.requests[0].resolve(response('synthetic-old'));
      else h.requests[0].reject(new Error('Synthetic obsolete failure'));
      await previous;
      assert.equal(requestCount, 2, 'a new lifecycle must issue its own request, not reuse the old promise');
      assert.equal(h.context.onlineDataList.value.length, 0);
      assert.equal(h.context.onlineDataListError.value, '');
      assert.equal(h.context.onlineDataListLoading.value, true, 'the new request still owns loading');
      assert.equal(h.context.onlineDataListRequestPromises.size, 1, 'old cleanup cannot delete the new request');
      assert.equal(h.errors.length, 0);
      assert.equal(h.effects.pruned, 0);
      const saved = response();
      h.requests[1].resolve(saved);
      assert.equal(await current, saved.data.list);
      assert.equal(h.context.onlineDataList.value, saved.data.list);
      assert.equal(h.context.onlineDataListLoading.value, false);
      assert.equal(h.context.onlineDataListRequestPromises.size, 0);
      assert.equal(h.effects.pruned, 1);
    });
  }
}

test('a cancelled old hotel query cannot clear a newer query loading or display', async () => {
  const h = harness();
  const previous = h.load();
  h.context.onlineDataFilter.value.hotel_id = '902';
  const current = h.load();
  assert.equal(h.requests.length, 2);
  assert.match(h.requests[1].url, /system_hotel_id=902/);
  h.requests[0].reject(abortError());
  await previous;
  assert.equal(h.errors.length, 0);
  assert.equal(h.context.onlineDataListError.value, '');
  assert.equal(h.context.onlineDataListLoading.value, true);
  const saved = response();
  saved.data.list[0].system_hotel_id = 902;
  h.requests[1].resolve(saved);
  await current;
  assert.equal(h.context.onlineDataList.value, saved.data.list);
  assert.equal(h.context.onlineDataListLoading.value, false);
});

test('a filter edit without a replacement request still shows the existing scope-change message', async () => {
  const h = harness();
  const previous = h.load();
  h.context.onlineDataFilter.value.start_date = '2026-09-02';
  h.requests[0].resolve(response('synthetic-obsolete-date'));
  assert.equal(await previous, null);
  assert.match(h.context.onlineDataListError.value, /筛选条件已变化/);
  assert.equal(h.context.onlineDataList.value.length, 0);
  assert.equal(h.context.onlineDataListLoading.value, false);
  assert.equal(h.errors.length, 0);
});

for (const outcome of ['network failure', 'business failure', 'incomplete response']) {
  test(`online data list still reports a current ${outcome}`, async () => {
    const h = harness();
    const current = h.load({ cacheMs: 60000 });
    if (outcome === 'network failure') h.requests[0].reject(new Error('Synthetic network failure'));
    else if (outcome === 'business failure') h.requests[0].resolve({ code: 503, message: 'Synthetic service failure' });
    else h.requests[0].resolve({ code: 200, data: {} });
    assert.equal(await current, null);
    assert.equal(h.errors.length, 1);
    assert.equal(h.errors[0][0], '加载数据列表失败:');
    assert.ok(h.context.onlineDataListError.value);
    assert.equal(h.context.onlineDataList.value.length, 0);
    assert.equal(h.context.onlineDataQualitySummary.value, null);
    assert.equal(h.context.onlineDataPagination.value.total, null);
    assert.equal(h.context.onlineDataListLoading.value, false);
    assert.equal(h.context.onlineDataListResultCache.size, 0);
  });
}

test('same-lifecycle duplicate reads and successful cache reuse stay intact while force refresh reads again', async () => {
  const h = harness();
  const first = h.load({ cacheMs: 60000 });
  const duplicate = h.load({ cacheMs: 60000 });
  assert.equal(h.requests.length, 1);
  const saved = response();
  h.requests[0].resolve(saved);
  assert.equal(await first, saved.data.list);
  assert.equal(await duplicate, saved.data.list);
  assert.equal(h.effects.pruned, 1);
  assert.equal(await h.load({ cacheMs: 60000 }), saved.data.list);
  assert.equal(h.requests.length, 1);
  const refreshed = h.load({ cacheMs: 60000, force: true });
  assert.equal(h.requests.length, 2);
  assert.equal(h.effects.cacheCleared, 1);
  const newer = response('synthetic-refreshed');
  h.requests[1].resolve(newer);
  assert.equal(await refreshed, newer.data.list);
  assert.equal(h.context.onlineDataList.value, newer.data.list);
});
