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
  list: [{ id, system_hotel_id: 901, source: 'ctrip', data_date: '2026-09-01', data_type: 'business' }],
  pagination: { total: 1, page: 1, page_size: 30 },
  data_quality_summary: { status: 'warning', calculation_scope: 'current_page', checked_records: 1,
    sample_size: 1, total_records: 1, page: 1, page_size: 30, issue_records: 1, ok_records: 0 },
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
    downloadCenterTab: { value: 'list' }, onlineDataLoadedQuery: { value: null },
    onlineDataListError: { value: '' }, onlineDataListLoading: { value: false },
    downloadCenterTab: { value: 'all' }, onlineDataLoadedQuery: { value: null },
    onlineDataListRequestPromises: new Map(), onlineDataListResultCache: new Map(),
    onlineDataListActiveRequestKey: '', onlineDataListSnapshotKey: '', onlineDataListSnapshotSession: {},
    onlineDataListSnapshotScope: { value: '' },
    onlineAnalysisSourceText: source => ({ ctrip: '携程', meituan: '美团' })[source] || source,
    onlineAnalysisDataTypeText: type => ({ business: '经营' })[type] || type,
    captureAuthSession: () => ({ epoch: context.authSessionEpoch }),
    isAuthSessionCurrent: session => session?.epoch === context.authSessionEpoch,
    clearCoordinatedGetSuccessCache: () => { effects.cacheCleared++; },
    pruneSelectedOnlineDataIds: () => { effects.pruned++; }, debugLog: () => {},
    request: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
  });
  vm.runInContext([
    'normalizeRequestCacheOptions', 'readRequestCache', 'writeRequestCache',
    'currentPageReadPolicy', 'isPageLoadPolicyCurrent', 'currentOnlineDataListScope',
    'onlineDataListSnapshotScopeNotice', 'loadOnlineDataList',
  ].map(declaration).join('\n') + '\nglobalThis.load = loadOnlineDataList; globalThis.scopeNotice = onlineDataListSnapshotScopeNotice;', context);
  const revisit = () => {
    context.currentPage.value = 'compass';
    context.pageRequestGeneration++;
    context.currentPage.value = 'online-data';
    context.pageRequestGeneration++;
  };
  return { context, requests, errors, effects, load: context.load, scopeNotice: context.scopeNotice, revisit };
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

test('a settled list labels its previous query scope until the edited filters are queried', async () => {
  const h = harness();
  const first = h.load();
  h.requests[0].resolve(response('synthetic-ctrip'));
  await first;
  assert.equal(h.scopeNotice(), '');

  h.context.onlineDataFilter.value.source = 'meituan';
  h.context.onlineDataFilter.value.start_date = '2026-09-02';
  h.context.onlineDataFilter.value.end_date = '2026-09-02';
  const staleNotice = h.scopeNotice();
  assert.match(staleNotice, /筛选条件已修改/);
  assert.match(staleNotice, /上次成功查询/);
  assert.match(staleNotice, /携程/);
  assert.match(staleNotice, /2026-09-01 至 2026-09-01/);
  assert.match(staleNotice, /第 1 页/);

  const current = h.load();
  assert.equal(h.scopeNotice(), '', 'the previous scope notice clears while current data is being fetched');
  assert.match(h.requests[1].url, /source=meituan/);
  assert.match(h.requests[1].url, /start_date=2026-09-02/);
  const refreshed = response('synthetic-meituan');
  refreshed.data.list[0].source = 'meituan';
  refreshed.data.list[0].data_date = '2026-09-02';
  h.requests[1].resolve(refreshed);
  await current;
  assert.equal(h.scopeNotice(), '', 'the notice stays clear after the edited scope is successfully read');
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

test('a 200 list without pagination cannot be mistaken for a verified empty history', async () => {
  const h = harness();
  const current = h.load();
  h.requests[0].resolve({ code: 200, data: { list: [{ id: 'synthetic-saved', system_hotel_id: 901 }] } });
  assert.equal(await current, null);
  assert.equal(h.context.onlineDataList.value.length, 0);
  assert.equal(h.context.onlineDataPagination.value.total, null);
  assert.match(h.context.onlineDataListError.value, /分页|不完整/);
});

test('a returned row outside the selected hotel, platform or business date is rejected', async () => {
  for (const altered of [
    { system_hotel_id: 902 },
    { source: 'meituan' },
    { data_date: '2026-09-02' },
  ]) {
    const h = harness();
    const current = h.load();
    const saved = response();
    saved.data.list[0] = { ...saved.data.list[0], ...altered };
    h.requests[0].resolve(saved);
    assert.equal(await current, null);
    assert.equal(h.context.onlineDataList.value.length, 0);
    assert.match(h.context.onlineDataListError.value, /范围|酒店|来源|日期/);
  }
});

test('selected data type is checked while a legacy OTA hotel id remains readable', async () => {
  const wrong = harness();
  wrong.context.onlineDataFilter.value.data_type = 'traffic';
  const wrongRead = wrong.load();
  wrong.requests[0].resolve(response());
  assert.equal(await wrongRead, null);
  assert.match(wrong.context.onlineDataListError.value, /数据类型/);

  const legacy = harness();
  const legacyRead = legacy.load();
  const saved = response();
  delete saved.data.list[0].system_hotel_id;
  saved.data.list[0].hotel_id = 901;
  legacy.requests[0].resolve(saved);
  assert.equal(await legacyRead, saved.data.list);
});

test('only quality evidence matching the loaded page can receive a complete status', async () => {
  const matched = harness();
  const matchedRead = matched.load();
  matched.requests[0].resolve(response());
  await matchedRead;
  assert.equal(matched.context.onlineDataQualitySummary.value.status, 'warning');

  const inconsistent = harness();
  const inconsistentRead = inconsistent.load();
  const saved = response();
  saved.data.data_quality_summary.checked_records = 0;
  inconsistent.requests[0].resolve(saved);
  assert.equal(await inconsistentRead, saved.data.list);
  assert.equal(inconsistent.context.onlineDataQualitySummary.value, null);
});

test('saved data table exposes current-page quality and an explicit unknown state', () => {
  const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
  assert.match(template, /data-testid="online-data-list-stale-scope"/);
  assert.match(template, /onlineDataListSnapshotScopeNotice\(\)/);
  assert.match(template, /role="status"/);
  assert.match(template, /data-testid="online-data-list-quality"/);
  assert.match(template, /v-if="onlineDataQualitySummary"/);
  assert.match(template, /当前页质量摘要未取得或与列表不一致/);
  assert.match(template, /onlineDataQualityScopeText\(onlineDataQualitySummary\)/);
});

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

test('deletion shrinking history recovers an out-of-range page instead of showing empty history', async () => {
  const h = harness();
  h.context.onlineDataPage.value = 3;
  const current = h.load();
  h.requests[0].resolve({ code: 200, data: { list: [], pagination: { total: 40, page: 3, page_size: 30 },
    data_quality_summary: { status: 'ok', calculation_scope: 'current_page', checked_records: 0,
      sample_size: 0, total_records: 40, page: 3, page_size: 30, issue_records: 0, ok_records: 0 } } });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(h.context.onlineDataPage.value, 2);
  assert.equal(h.requests.length, 2);
  const lastPage = response('synthetic-last-page');
  lastPage.data.pagination.page = 2;
  lastPage.data.data_quality_summary.page = 2;
  h.requests[1].resolve(lastPage);
  assert.equal(await current, h.context.onlineDataList.value);
  assert.equal(h.context.onlineDataList.value[0].id, 'synthetic-last-page');
  assert.equal(h.context.onlineDataListError.value, '');
});

test('an empty page inside the reported total stays a read error instead of claiming no records', async () => {
  const h = harness();
  const current = h.load();
  h.requests[0].resolve({ code: 200, data: { list: [], pagination: { total: 40, page: 1, page_size: 30 },
    data_quality_summary: { status: 'ok', calculation_scope: 'current_page', checked_records: 0,
      sample_size: 0, total_records: 40, page: 1, page_size: 30, issue_records: 0, ok_records: 0 } } });
  assert.equal(await current, null);
  assert.equal(h.context.onlineDataListError.value.includes('总数不为零'), true);
  assert.equal(h.context.onlineDataPagination.value.total, null);
});
