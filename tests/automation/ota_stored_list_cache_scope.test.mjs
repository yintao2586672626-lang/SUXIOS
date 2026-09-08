import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const start = source.indexOf('const loadOnlineDataList = async (options = {}) =>');
const body = source.slice(start, source.indexOf('// 加载数据汇总', start));
const declaration = name => {
  const start = source.indexOf(`            const ${name} =`);
  const end = /\r?\n            };/.exec(source.slice(start));
  assert.ok(start >= 0 && end, `missing production declaration: ${name}`);
  return source.slice(start, start + end.index + end[0].length);
};
function harness(request) {
  const env = {
    URLSearchParams, Date, Map, console: { error() {} },
    authSessionEpoch: 1, pageRequestGeneration: 1,
    currentPage: { value: 'meituan-ebooking' }, authContext: { value: { tenantId: 'synthetic-tenant' } },
    filterReportHotel: { value: '80' }, revenueAiBusinessDate: { value: '' }, coreOperationsTargetDate: { value: '' },
    currentBusinessRequestContext: () => ({ tenant_id: 'synthetic-tenant' }),
    onlineDataPage: { value: 1 }, onlineDataPagination: { value: { page_size: 30 } },
    onlineDataFilter: { value: { hotel_id: '80', source: 'meituan', start_date: '2026-08-01', end_date: '2026-08-01' } },
    onlineDataList: { value: [] }, onlineDataQualitySummary: { value: null },
    onlineDataListError: { value: '' }, onlineDataListLoading: { value: false },
    downloadCenterTab: { value: 'ads' }, onlineDataLoadedQuery: { value: null },
    normalizeRequestCacheOptions: x => x,
    readRequestCache: (cache, key) => cache.has(key),
    writeRequestCache: (cache, key) => cache.set(key, { expiresAt: Date.now() + 100000 }),
    clearCoordinatedGetSuccessCache() {}, pruneSelectedOnlineDataIds() {}, debugLog() {},
    captureAuthSession: () => ({ epoch: 1 }), isAuthSessionCurrent: session => session.epoch === 1,
    request,
  };
  const run = vm.runInNewContext(`(() => {
    let onlineDataListSnapshotKey = '', onlineDataListActiveRequestKey = '', onlineDataListSnapshotSession = {};
    const onlineDataListResultCache = new Map(), onlineDataListRequestPromises = new Map();
    ${declaration('currentPageReadPolicy')}
    ${declaration('isPageLoadPolicyCurrent')}
    ${body}; return loadOnlineDataList;
  })()`, env);
  return { run, env };
}
const response = hotel => ({ code: 200, data: { list: [{ system_hotel_id: hotel }], pagination: { total: 1 } } });

test('returning to a cached hotel never returns the other hotel currently in memory', async () => {
  const h = harness(async url => response(new URL(url, 'http://test').searchParams.get('system_hotel_id')));
  await h.run({ cacheMs: 10000 });
  h.env.onlineDataFilter.value.hotel_id = '81';
  await h.run({ cacheMs: 10000 });
  h.env.onlineDataFilter.value.hotel_id = '80';
  const rows = await h.run({ cacheMs: 10000 });
  assert.equal(rows[0].system_hotel_id, '80');
});

test('rapid A-B-A navigation can reuse the in-flight A response without accepting B', async () => {
  const resolves = new Map();
  const h = harness(url => new Promise(resolve => resolves.set(new URL(url, 'http://test').searchParams.get('system_hotel_id'), resolve)));
  const firstA = h.run();
  h.env.onlineDataFilter.value.hotel_id = '81';
  const b = h.run();
  h.env.onlineDataFilter.value.hotel_id = '80';
  const secondA = h.run();
  resolves.get('80')(response('80'));
  const result = await secondA;
  assert.equal(result?.[0]?.system_hotel_id, '80');
  resolves.get('81')(response('81'));
  assert.equal(await b, null);
  await firstA;
  assert.equal(h.env.onlineDataList.value[0].system_hotel_id, '80');
});

test('returning to cached A while B is pending restores A instead of using its cleared snapshot', async () => {
  let finishB;
  const h = harness(url => {
    const hotel = new URL(url, 'http://test').searchParams.get('system_hotel_id');
    return hotel === '81'
      ? new Promise(resolve => { finishB = resolve; })
      : Promise.resolve(response(hotel));
  });
  await h.run({ cacheMs: 10000 });
  h.env.onlineDataFilter.value.hotel_id = '81';
  const pendingB = h.run({ cacheMs: 10000 });
  h.env.onlineDataFilter.value.hotel_id = '80';
  const rows = await h.run({ cacheMs: 10000 });
  finishB(response('81'));
  assert.equal(await pendingB, null);
  assert.equal(rows?.[0]?.system_hotel_id, '80');
  assert.equal(h.env.onlineDataList.value[0]?.system_hotel_id, '80');
  assert.equal(h.env.onlineDataPagination.value.total, 1);
  assert.equal(h.env.onlineDataListError.value, '');
  assert.equal(h.env.onlineDataListLoading.value, false);
});

test('malformed responses and transport errors return failure, not an empty successful result', async () => {
  for (const request of [async () => ({ code: 200, data: {} }), async () => { throw new Error('offline'); }]) {
    const h = harness(request);
    assert.equal(await h.run(), null);
  }
});

test('editing dates during a request and changing account invalidate the old result', async () => {
  let finish;
  const h = harness(() => new Promise(resolve => { finish = resolve; }));
  const pending = h.run();
  h.env.onlineDataFilter.value.end_date = '2026-08-02';
  finish(response('80'));
  assert.equal(await pending, null);
  assert.equal(h.env.onlineDataList.value.length, 0);

  let epoch = 1, requests = 0;
  const other = harness(async () => { requests += 1; return response('80'); });
  other.env.captureAuthSession = () => ({ epoch });
  other.env.isAuthSessionCurrent = session => session.epoch === epoch;
  await other.run({ cacheMs: 10000 });
  epoch = 2;
  await other.run({ cacheMs: 10000 });
  assert.equal(requests, 2);
});

test('a rejected query exposes the backend error and removes the previous total', async () => {
  let rejected = false;
  const h = harness(async () => rejected
    ? {code:422, message:'开始日期不能晚于结束日期'} : response('80'));
  await h.run();
  h.env.onlineDataPagination.value.total = 2048;
  rejected = true;
  assert.equal(await h.run({force:true}), null);
  assert.equal(h.env.onlineDataList.value.length, 0);
  assert.equal(h.env.onlineDataPagination.value.total, null);
  assert.match(h.env.onlineDataListError.value, /开始日期不能晚于结束日期/);
  assert.equal(h.env.onlineDataListLoading.value, false);
  rejected = false;
  await h.run({force:true});
  assert.equal(h.env.onlineDataListError.value, '');
  assert.equal(h.env.onlineDataPagination.value.total, 1);
});

test('a filter edit during refresh clears stale results and ends loading without claiming empty success', async () => {
  let finish;
  let refresh = false;
  const h = harness(() => refresh ? new Promise(resolve => {finish = resolve;}) : Promise.resolve(response('80')));
  await h.run();
  refresh = true;
  const pending = h.run({force:true});
  assert.equal(h.env.onlineDataListLoading.value, true);
  assert.equal(h.env.onlineDataList.value.length, 0);
  h.env.onlineDataFilter.value.end_date = '2026-08-02';
  finish(response('80'));
  assert.equal(await pending, null);
  assert.equal(h.env.onlineDataListLoading.value, false);
  assert.match(h.env.onlineDataListError.value, /筛选条件已变化/);
});

test('a transport failure after a filter edit requests a new query instead of showing empty success', async () => {
  let rejectRefresh;
  let refresh = false;
  const h = harness(() => refresh
    ? new Promise((resolve, reject) => { rejectRefresh = reject; })
    : Promise.resolve(response('80')));
  await h.run();
  refresh = true;
  const pending = h.run({ force: true });
  h.env.onlineDataFilter.value.end_date = '2026-08-02';
  rejectRefresh(new Error('offline'));
  assert.equal(await pending, null);
  assert.equal(h.env.onlineDataList.value.length, 0);
  assert.equal(h.env.onlineDataPagination.value.total, null);
  assert.equal(h.env.onlineDataListLoading.value, false);
  assert.match(h.env.onlineDataListError.value, /筛选条件已变化/);
});

test('Meituan renders loading and errors before empty/list content', () => {
  const template = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
  assert.ok(/v-if="onlineDataListError &amp;&amp; downloadCenterTab !== 'traffic'"[^>]*role="alert"/.test(template));
  assert.ok(/v-else-if="onlineDataListLoading &amp;&amp; downloadCenterTab !== 'traffic'"/.test(template));
  assert.ok(/onlineDataListLoading[\s\S]*?<template v-else>[\s\S]*?暂无美团已存数据/.test(template));
});
