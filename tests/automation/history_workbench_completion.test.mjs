import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { compile } from '@vue/compiler-dom';
import * as Vue from 'vue';

const main = readFileSync('public/app-main.js', 'utf8');
const plain = value => JSON.parse(JSON.stringify(value));
function staticApi(file, name) {
  const env = { window: {}, console, URLSearchParams };
  vm.runInNewContext(readFileSync(file, 'utf8'), env, { filename: file });
  return env.window[name];
}
const system = staticApi('public/system-static.js', 'SUXI_SYSTEM_STATIC');
const meituan = staticApi('public/meituan-static.js', 'SUXI_MEITUAN_STATIC');
function segment(from, to) {
  const start = main.indexOf(from);
  const end = main.indexOf(to, start + from.length);
  assert.ok(start >= 0 && end > start, `source boundaries exist: ${from}`);
  return main.slice(start, end);
}
const row = (id, extra = {}) => ({ id, system_hotel_id: 80, source: 'meituan', data_type: 'advertising',
  data_date: '2026-08-01', create_time: '2026-08-02 12:00:00', hotel_name: '合成门店',
  dimension: `campaign-${id}`, list_exposure: id, detail_exposure: 0, amount: null, ...extra });
const query = { source: 'meituan', system_hotel_id: '80', start_date: '2026-08-01', end_date: '2026-08-03', data_type: 'advertising' };
const response = (list, total, page) => ({ code: 200, data: { list, pagination: { total, page, page_size: 100 } } });
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };

test('history detail allowlist keeps true zero, missing values and verification distinct without exposing raw payloads', () => {
  const fields = system.buildOnlineHistoryRecordDetail({ id: 12, data_value: 0, amount: null, quantity: '',
    readback_verified: false, data_quality_status: 'missing', raw_data: 'synthetic-private-field',
    arbitrary_property: 'not displayed' });
  const values = Object.fromEntries(Array.from(fields, field => [field.key, field.value]));
  assert.equal(values.data_value, '0');
  assert.equal(values.amount, '未提供');
  assert.equal(values.quantity, '未提供');
  assert.equal(values.readback_verified, '尚未核验');
  assert.ok(!Object.hasOwn(values, 'raw_data'));
  assert.ok(!Object.hasOwn(values, 'arbitrary_property'));
  for (const verified of [true, 1, '1']) assert.equal(system.buildOnlineHistoryRecordDetail({ readback_verified: verified })[0].value, '已核验');
  assert.equal(system.buildOnlineHistoryRecordDetail({ readback_verified: null })[0].value, '未提供');
});

test('filtered export reads every page with the captured query and returns only after exact total completion', async () => {
  const requests = [];
  const firstPage = Array.from({ length: 100 }, (_, i) => row(i + 1));
  const rows = await system.readOnlineHistoryExportRows({ query: { ...query, page: 8, page_size: 30 }, isCurrent: () => true,
    requestPage: async params => { requests.push(plain(params)); return response(params.page === 1 ? firstPage : [row(101)], 101, params.page); } });
  assert.equal(rows.length, 101);
  assert.equal(rows.at(-1).id, 101);
  assert.deepEqual(requests.map(item => item.page), [1, 2]);
  for (const params of requests) assert.deepEqual(params, { ...query, page: params.page, page_size: 100 });
});

test('filtered export rejects repeated identities, changing totals, incomplete pages and invalid record scopes', async () => {
  const scenarios = [
    ['repeated identity', response([row(1)], 2, 2)],
    ['changed total', response([row(2)], 3, 2)],
    ['empty partial page', response([], 2, 2)],
    ['page identity drift', response([row(2)], 2, 1)],
    ['wrong hotel', response([row(2, { system_hotel_id: 81 })], 2, 2)],
    ['wrong platform', response([row(2, { source: 'ctrip' })], 2, 2)],
    ['wrong business date', response([row(2, { data_date: '2026-08-04' })], 2, 2)],
    ['missing record id', response([row(null)], 2, 2)],
  ];
  for (const [label, secondPage] of scenarios) {
    await assert.rejects(system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
      requestPage: async params => params.page === 1 ? response([row(1)], 2, 1) : secondPage }), undefined, label);
  }
  await assert.rejects(system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
    requestPage: async () => response([], 2001, 1) }), /2000/);
});

test('filtered export rejects missing pagination totals instead of turning null into an empty success', async () => {
  for (const total of [null, undefined, '', false]) {
    await assert.rejects(system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
      requestPage: async () => response([], total, 1) }));
  }
  const empty = await system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
    requestPage: async () => response([], 0, 1) });
  assert.equal(empty.length, 0, 'an explicit zero total is a valid empty result');
});

test('filtered export enforces data type and acquired-time scope as well as hotel and business date', async () => {
  for (const [scope, invalid] of [
    [query, row(1, { data_type: 'search_keyword' })],
    [{ ...query, data_type: '', data_types: 'advertising,search_keyword' }, row(1, { data_type: 'order_info' })],
    [{ ...query, start_date: '' }, row(1, { data_date: null })],
    [{ ...query, start_date: '' }, row(1, { data_date: '' })],
    [{ ...query, create_start: '2026-08-02', create_end: '2026-08-03' }, row(1, { create_time: '2026-08-01 12:00:00' })],
    [{ ...query, create_start: '2026-08-02', create_end: '2026-08-03' }, row(1, { create_time: '2026-08-04 12:00:00' })],
  ]) {
    await assert.rejects(system.readOnlineHistoryExportRows({ query: scope, isCurrent: () => true,
      requestPage: async () => response([invalid], 1, 1) }));
  }
  for (const scope of [
    { ...query, create_start: '2026-08-02', create_end: '2026-08-03' },
    { ...query, create_start: '2026-08-02' },
    { ...query, create_end: '2026-08-02' },
  ]) {
    const result = await system.readOnlineHistoryExportRows({ query: scope, isCurrent: () => true,
      requestPage: async () => response([row(1)], 1, 1) });
    assert.equal(result.length, 1, 'valid acquired-time rows remain exportable');
  }
});

test('history export never substitutes an external OTA hotel identifier for the internal hotel scope', async () => {
  for (const systemHotelId of [undefined, null]) {
    const externalOnly = row(1, { system_hotel_id: systemHotelId, hotel_id: '80' });
    assert.throws(() => system.assertOnlineHistoryRowScope(externalOnly, query), /查询范围/);
    await assert.rejects(system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
      requestPage: async () => response([externalOnly], 1, 1) }), /查询范围/);
  }
  const scoped = row(1, { system_hotel_id: 80, hotel_id: 'external-ota-9001' });
  assert.doesNotThrow(() => system.assertOnlineHistoryRowScope(scoped, query));
  const rows = await system.readOnlineHistoryExportRows({ query, isCurrent: () => true,
    requestPage: async () => response([scoped], 1, 1) });
  assert.equal(rows.length, 1);
});

test('filtered export cancels between pages when account or loaded result identity changes', async () => {
  let current = true, calls = 0;
  await assert.rejects(system.readOnlineHistoryExportRows({ query, isCurrent: () => current,
    requestPage: async () => { calls += 1; current = false; return response([row(1)], 2, 1); } }), /范围已变化/);
  assert.equal(calls, 1);
});

function workbenchHarness(request = async () => response([row(1)], 1, 1)) {
  const scope = Vue.effectScope();
  const downloads = [], toasts = [], requests = [], scrolls = [];
  const dialog = { open: false, showModal() { this.open = true; }, close() { this.open = false; } };
  const ref = Vue.ref;
  const env = {
    ...Vue, URLSearchParams, Date, Map, Blob, console: { error() {} },
    window: { scrollY: 240, scrollTo: options => scrolls.push(plain(options)) },
    document: { getElementById: id => id === 'online-history-record-dialog' ? dialog : null },
    currentPage: ref('meituan-ebooking'), isLoggedIn: ref(true), user: ref({ id: 7 }),
    authSessionEpoch: 1, pageRequestGeneration: 1,
    authContext: ref({ tenantId: 'synthetic-tenant' }), filterReportHotel: ref('80'),
    revenueAiBusinessDate: ref(''), coreOperationsTargetDate: ref(''),
    currentBusinessRequestContext: () => ({ tenant_id: 'synthetic-tenant' }),
    onlineDataTab: ref('download'), downloadCenterTab: ref('ads'), ctripTableTab: ref('traffic'),
    ctripSortField: ref('amount'), ctripSortOrder: ref('asc'),
    onlineDataFilter: ref({ hotel_id: '80', source: 'meituan', data_type: 'advertising', start_date: '2026-08-01', end_date: '2026-08-03' }),
    onlineDataPage: ref(3), onlineDataPagination: ref({ page: 3, total: 61, page_size: 30 }),
    onlineDataList: ref([row(1)]), onlineDataLoadedQuery: ref(null), onlineDataQualitySummary: ref(null),
    onlineDataListLoading: ref(false), onlineDataListError: ref(''), onlineHistoryExporting: ref(false),
    onlineHistoryRecordDetail: ref(null), onlineHistoryListReturn: ref(null),
    normalizeRequestCacheOptions: value => value,
    readRequestCache: (cache, key) => cache.has(key), writeRequestCache: (cache, key) => cache.set(key, true),
    clearCoordinatedGetSuccessCache() {}, pruneSelectedOnlineDataIds() {}, debugLog() {},
    requireAppSystemStatic: name => system[name], requireMeituanStatic: name => meituan[name],
    buildMeituanDownloadData: meituan.buildMeituanDownloadData,
    runMeituanStoredPageCsvDownload: meituan.runMeituanStoredPageCsvDownload,
    captureAuthSession: () => ({ userId: env.user.value.id }),
    isAuthSessionCurrent: session => env.isLoggedIn.value && session?.userId === env.user.value.id,
    request: async (url, options) => { requests.push({ url, options }); return request(url, options); },
    showToast: (...values) => toasts.push(values), downloadBlob: (blob, fileName) => downloads.push({ blob, fileName }),
    useCtripDisplayHotels() {},
  };
  env.meituanDownloadData = Vue.computed(() => meituan.buildMeituanDownloadData(env.onlineDataList.value));
  const source = `(() => {
    let onlineDataListSnapshotKey = '', onlineDataListActiveRequestKey = '', onlineDataListSnapshotSession = {};
    const onlineDataListResultCache = new Map(), onlineDataListRequestPromises = new Map();
    ${segment('const currentPageReadPolicy =', '// /compass is scoped')}
    ${segment('const isPageLoadPolicyCurrent =', 'const cancelPageLoadRequests =')}
    ${segment('const onlineHistoryResultScopeText = computed', 'const onlineDataListError = ref')}
    ${segment('const closeOnlineHistoryDetail = () =>', '// 编辑线上数据')}
    ${segment("const onlineHistoryCsvContext = (loaded, scope = 'current_page')", 'const applyOnlineHistoryDatePreset = () =>')}
    ${segment('const loadOnlineDataList = async (options = {}) =>', '// 加载数据汇总')}
    return { onlineHistoryResultScopeText, onlineHistoryQueryChanged, closeOnlineHistoryDetail,
      viewOnlineDataDetail, openOnlineHistoryCompetitionTable, returnToOnlineHistoryList,
      downloadMeituanCurrentPageCsv, downloadMeituanFilteredCsv, loadOnlineDataList };
  })()`;
  const api = scope.run(() => vm.runInNewContext(source, env));
  const setLoaded = () => {
    env.onlineDataLoadedQuery.value = { params: { ...query, page: '3', page_size: '30' },
      filterKey: JSON.stringify(env.onlineDataFilter.value), tab: 'ads' };
  };
  setLoaded();
  return { env, api, downloads, toasts, requests, dialog, scrolls, setLoaded, stop: () => scope.stop() };
}

test('query drafts do not relabel or change current-page and filtered CSV snapshot identities', async () => {
  const h = workbenchHarness(async url => response([row(1)], 1, Number(new URL(url, 'http://test').searchParams.get('page'))));
  try {
    const before = h.api.onlineHistoryResultScopeText.value;
    h.env.onlineDataFilter.value.hotel_id = '81';
    h.env.onlineDataFilter.value.start_date = '2026-09-01';
    await Vue.nextTick();
    assert.equal(h.api.onlineHistoryResultScopeText.value, before);
    assert.equal(h.api.onlineHistoryQueryChanged.value, true);
    assert.equal(h.api.downloadMeituanCurrentPageCsv(), true);
    await h.api.downloadMeituanFilteredCsv();
    assert.equal(h.downloads.length, 2);
    assert.match(h.downloads[0].fileName, /-80-2026-08-01-2026-08-03-page-3\.csv$/);
    assert.match(h.downloads[1].fileName, /-80-2026-08-01-2026-08-03-filtered\.csv$/);
    for (const download of h.downloads) assert.match(await download.blob.text(), /meituan,80,2026-08-01,2026-08-03,/);
    assert.ok(h.requests.every(entry => new URL(entry.url, 'http://test').searchParams.get('system_hotel_id') === '80'));
    assert.ok(h.requests.every(entry => entry.options.withBusinessContext === false));
  } finally { h.stop(); }
});

test('failed or stale filtered export never downloads its already read partial rows', async () => {
  for (const cause of ['transport', 'changed_total', 'scope']) {
    let calls = 0;
    const h = workbenchHarness(async () => {
      calls += 1;
      if (calls === 1) return response([row(1)], 2, 1);
      if (cause === 'transport') throw new Error('page two offline');
      if (cause === 'scope') return response([row(2, { system_hotel_id: 81 })], 2, 2);
      return response([row(2)], 3, 2);
    });
    try {
      await h.api.downloadMeituanFilteredCsv();
      assert.equal(calls, 2);
      assert.equal(h.downloads.length, 0);
      assert.equal(h.env.onlineHistoryExporting.value, false);
      assert.equal(h.toasts.at(-1)?.[1], 'error');
    } finally { h.stop(); }
  }
});

test('a new loaded query or logout during filtered export prevents an obsolete download', async () => {
  for (const change of ['query', 'logout']) {
    const pending = deferred();
    const h = workbenchHarness(() => pending.promise);
    try {
      const run = h.api.downloadMeituanFilteredCsv();
      if (change === 'query') h.setLoaded(); else h.env.isLoggedIn.value = false;
      await Vue.nextTick();
      pending.resolve(response([row(1)], 1, 1));
      await run;
      assert.equal(h.downloads.length, 0);
      assert.equal(h.env.onlineHistoryExporting.value, false);
    } finally { h.stop(); }
  }
});

test('current-page export refuses loading, failure, a different tab and absent snapshots', () => {
  for (const change of [
    env => { env.onlineDataListLoading.value = true; },
    env => { env.onlineDataListError.value = 'offline'; },
    env => { env.downloadCenterTab.value = 'keywords'; },
    env => { env.onlineDataLoadedQuery.value = null; },
  ]) {
    const h = workbenchHarness();
    try { change(h.env); assert.equal(h.api.downloadMeituanCurrentPageCsv(), false); assert.equal(h.downloads.length, 0); }
    finally { h.stop(); }
  }
});

test('current-page CSV never labels rows from another hotel or date with the loaded query identity', () => {
  for (const invalid of [row(1, { system_hotel_id: 81 }), row(1, { data_date: '2026-08-04' })]) {
    const h = workbenchHarness();
    try {
      h.env.onlineDataList.value = [invalid];
      assert.equal(h.api.downloadMeituanCurrentPageCsv(), false);
      assert.equal(h.downloads.length, 0);
    } finally { h.stop(); }
  }
});

test('loader records accepted params and exposes changed draft state instead of publishing a stale response', async () => {
  const pending = deferred();
  let refresh = false;
  const h = workbenchHarness(async () => refresh ? pending.promise : response([row(1)], 1, 3));
  try {
    await h.api.loadOnlineDataList({ force: true });
    assert.equal(h.env.onlineDataLoadedQuery.value.params.system_hotel_id, '80');
    assert.equal(h.env.onlineDataLoadedQuery.value.params.page, '3');
    assert.equal(h.env.onlineDataLoadedQuery.value.tab, 'ads');
    const before = h.api.onlineHistoryResultScopeText.value;
    h.env.onlineDataFilter.value.end_date = '2026-08-04';
    assert.equal(h.api.onlineHistoryResultScopeText.value, before);
    assert.equal(h.api.onlineHistoryQueryChanged.value, true);
    refresh = true;
    const load = h.api.loadOnlineDataList({ force: true });
    assert.equal(h.env.onlineDataLoadedQuery.value, null);
    h.env.onlineDataFilter.value.end_date = '2026-08-05';
    pending.resolve(response([row(2)], 1, 3));
    assert.equal(await load, null);
    assert.equal(h.env.onlineDataList.value.length, 0);
    assert.equal(h.env.onlineDataLoadedQuery.value, null);
    assert.match(h.env.onlineDataListError.value, /筛选条件已变化/);
  } finally { h.stop(); }
});

test('native record dialog closes without changing filters, page, sorting, rows or loaded identity', async () => {
  const h = workbenchHarness();
  try {
    const before = { filter: plain(h.env.onlineDataFilter.value), page: h.env.onlineDataPage.value,
      sorting: [h.env.ctripSortField.value, h.env.ctripSortOrder.value], rows: h.env.onlineDataList.value,
      loaded: h.env.onlineDataLoadedQuery.value };
    await h.api.viewOnlineDataDetail(h.env.onlineDataList.value[0]);
    assert.equal(h.dialog.open, true);
    assert.equal(h.env.onlineHistoryRecordDetail.value.scope, h.api.onlineHistoryResultScopeText.value);
    h.api.closeOnlineHistoryDetail();
    assert.equal(h.dialog.open, false);
    assert.equal(h.env.onlineHistoryRecordDetail.value, null);
    assert.deepEqual(plain(h.env.onlineDataFilter.value), before.filter);
    assert.equal(h.env.onlineDataPage.value, before.page);
    assert.deepEqual([h.env.ctripSortField.value, h.env.ctripSortOrder.value], before.sorting);
    assert.equal(h.env.onlineDataList.value, before.rows);
    assert.equal(h.env.onlineDataLoadedQuery.value, before.loaded);
    const source = readFileSync('resources/frontend/templates/fragments/46-global-toast.html', 'utf8');
    const template = source.slice(0, source.indexOf('</dialog>') + '</dialog>'.length);
    const { code } = compile(template, { prefixIdentifiers: true });
    const render = new Function('Vue', code)(Vue);
    const tree = render({ onlineHistoryRecordDetail: { scope: '原结果范围', record: {} }, onlineHistoryDetailRows: [],
      closeOnlineHistoryDetail: h.api.closeOnlineHistoryDetail, openOnlineHistoryCompetitionTable: h.api.openOnlineHistoryCompetitionTable }, []);
    assert.equal(tree.type, 'dialog');
    assert.equal(tree.props['aria-labelledby'], 'online-history-record-title');
    assert.equal(tree.props.onClose, h.api.closeOnlineHistoryDetail);
  } finally { h.stop(); }
});

test('competition-table return restores the original history snapshot and logout prevents restoring it', async () => {
  for (const logout of [false, true]) {
    const h = workbenchHarness();
    try {
      const initial = { page: h.env.onlineDataPage.value, rows: h.env.onlineDataList.value,
        loaded: h.env.onlineDataLoadedQuery.value, filter: plain(h.env.onlineDataFilter.value), pagination: plain(h.env.onlineDataPagination.value) };
      await h.api.viewOnlineDataDetail({ ...row(1), display_hotels: [{ hotel_name: '合成竞品' }] });
      h.api.openOnlineHistoryCompetitionTable();
      await Vue.nextTick();
      assert.equal(h.env.currentPage.value, 'ctrip-ebooking');
      assert.ok(h.env.onlineHistoryListReturn.value);
      if (logout) {
        h.env.isLoggedIn.value = false;
        await Vue.nextTick();
        assert.equal(h.env.onlineHistoryListReturn.value, null);
        assert.equal(h.env.onlineDataLoadedQuery.value, null);
        await h.api.returnToOnlineHistoryList();
        assert.equal(h.env.currentPage.value, 'ctrip-ebooking');
        assert.equal(h.scrolls.length, 0);
      } else {
        h.env.onlineDataPage.value = 9;
        h.env.onlineDataList.value = [];
        await h.api.returnToOnlineHistoryList();
        assert.equal(h.env.currentPage.value, 'meituan-ebooking');
        assert.deepEqual(plain(h.env.onlineDataFilter.value), initial.filter);
        assert.deepEqual(plain(h.env.onlineDataPagination.value), initial.pagination);
        assert.equal(h.env.onlineDataPage.value, initial.page);
        assert.equal(h.env.onlineDataList.value, initial.rows);
        assert.equal(h.env.onlineDataLoadedQuery.value, initial.loaded);
        assert.deepEqual([h.env.ctripSortField.value, h.env.ctripSortOrder.value], ['amount', 'asc']);
        assert.equal(h.env.onlineHistoryListReturn.value, null);
        assert.deepEqual(h.scrolls, [{ top: 240, behavior: 'instant' }]);
      }
    } finally { h.stop(); }
  }
});

test('advertising availability belongs to the loaded result hotel, including all-hotel queries', () => {
  const source = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
  const start = source.indexOf('<tr v-if="meituanDownloadData.adsRowsCount === 0">');
  assert.ok(start >= 0);
  const template = source.slice(start, source.indexOf('</tr>', start) + 5);
  const render = new Function('Vue', compile(template, { prefixIdentifiers: true }).code)(Vue);
  for (const [loadedHotel, draftHotel] of [['80', '81'], ['81', '80'], ['', '81']]) {
    let checkedHotel;
    const tree = render({
      meituanDownloadData: { adsRowsCount: 0 }, onlineDataLoadedQuery: { params: { system_hotel_id: loadedHotel } },
      onlineDataFilter: { hotel_id: draftHotel }, meituanForm: { hotelId: draftHotel },
      isMeituanAdsNotApplicableForHotel: id => { checkedHotel = id; return id === '81'; },
    }, []);
    assert.equal(checkedHotel, loadedHotel);
    assert.equal(tree.children[0].children.includes('不适用'), loadedHotel === '81');
  }
});

test('CSV metadata identifies exported scope while formula prefixes and null metrics remain safe', () => {
  const data = meituan.buildMeituanDownloadData([row(1, { hotel_name: '\t=HYPERLINK("x")', dimension: '@SUM(1)', list_exposure: 0, detail_exposure: null, amount: null })]);
  const csv = meituan.buildMeituanStoredPageCsvPayload('ads', data, { hotelId: 80, startDate: '2026-08-01', endDate: '2026-08-03', scope: 'filtered', page: 9, createStart: '2026-08-02', createEnd: '2026-08-03', dataTypes: 'advertising' });
  assert.ok(csv.ok);
  assert.match(csv.fileName, /-80-2026-08-01-2026-08-03-filtered\.csv$/);
  assert.match(csv.csv, /'\t=HYPERLINK/);
  assert.match(csv.csv, /'@SUM/);
  assert.match(csv.csv, /@SUM\(1\),0,,,,/);
  assert.match(csv.csv, /采集开始日期,采集结束日期,查询记录类型/);
  assert.match(csv.csv, /meituan,80,2026-08-01,2026-08-03,全部筛选结果,,2026-08-02,2026-08-03,advertising\s*$/);
});
