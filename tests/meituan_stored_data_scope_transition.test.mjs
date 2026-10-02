import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const app = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const staticSource = readFileSync('public/meituan-static.js', 'utf8');

function between(source, startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `expected source section ${startMarker}`);
  return source.slice(start, end);
}

const tabSource = between(app, 'const meituanStoredDataTypesByTab =', 'const queryMeituanStoredData =');
const switchSource = between(app, 'const switchDownloadTab = (tab) => {', '// 切换到下载中心（自动加载数据）');
const meituanPageSwitchSource = between(app, 'const switchToMeituanDownloadCenter = () => {', 'const openMeituanStoredBusinessDate =');
const preparationStart = app.indexOf('const prepareMeituanStoredDataViewForScopeChange =');
const preparationSource = preparationStart < 0
  ? ''
  : between(app.slice(preparationStart), 'const prepareMeituanStoredDataViewForScopeChange =', 'const currentOnlineDataListScope =');
const staticSandbox = { window: {}, console };
vm.runInNewContext(staticSource, staticSandbox);
const meituan = staticSandbox.window.SUXI_MEITUAN_STATIC;

function harness() {
  const scheduled = [];
  const state = vm.createContext({
    formatDate: date => date.toISOString().slice(0, 10),
    thirtyDaysAgo: new Date('2026-08-29T00:00:00.000Z'),
    today: new Date('2026-09-28T00:00:00.000Z'),
    onlineDataTab: { value: 'meituan-download' },
    downloadCenterTab: { value: 'all' },
    onlineDataFilter: { value: {
      hotel_id: '80', source: 'meituan', data_type: '', data_types: '',
      start_date: '2026-08-29', end_date: '2026-09-28', create_start: '', create_end: '',
    } },
    onlineDataPage: { value: 3 },
    onlineDataPagination: { value: { page: 3, page_size: 30, total: 62 } },
    onlineDataList: { value: [
      { id: 301, source: 'meituan', system_hotel_id: 80, hotel_name: 'Old scope hotel', data_date: '2026-09-20', data_type: 'advertising', exposure_count: 20 },
      { id: 302, source: 'meituan', system_hotel_id: 80, hotel_name: 'Old scope hotel', data_date: '2026-09-20', data_type: 'order', quantity: 2, amount: 600 },
    ] },
    onlineDataQualitySummary: { value: { status: 'ok' } },
    onlineDataListError: { value: '' },
    onlineDataListLoading: { value: false },
    onlineDataListActiveRequestKey: 'old-request',
    onlineDataListSnapshotKey: 'old-snapshot',
    onlineDataListSnapshotSession: { epoch: 1 },
    onlineDataListSnapshotScope: { value: 'old-scope' },
    meituanForm: { value: { hotelId: '121' } },
    resolveMeituanTemporalHotelId: () => '121',
    scheduleDownloadCenterTabLoad: (...args) => scheduled.push(args),
  });
  vm.runInContext([
    tabSource,
    preparationSource,
    switchSource,
    meituanPageSwitchSource,
  ].join('\n'), state);
  return { state, scheduled };
}

test('switching stored-data type invalidates prior rows before the deferred query begins', () => {
  const h = harness();
  assert.equal(meituan.buildMeituanDownloadData(h.state.onlineDataList.value).orderRows.length, 1);

  vm.runInContext("switchDownloadTab('orders')", h.state);

  assert.equal(h.state.downloadCenterTab.value, 'orders');
  assert.equal(meituan.buildMeituanDownloadData(h.state.onlineDataList.value).orderRows.length, 0,
    'a detail from the prior all-types snapshot must not appear as the selected tab result');
  assert.equal(h.state.onlineDataListLoading.value, true, 'the empty view must remain explicitly pending until the query starts');
  assert.equal(h.state.onlineDataListError.value, '');
  assert.equal(h.state.onlineDataPagination.value.total, null);
  assert.equal(h.state.onlineDataListActiveRequestKey, '', 'a response from the prior scope must not clear the pending state');
  assert.equal(h.scheduled.length, 1);
});

test('reopening Meituan stored data for another hotel invalidates prior detail rows immediately', () => {
  const h = harness();

  vm.runInContext('switchToMeituanDownloadCenter()', h.state);

  assert.equal(h.state.onlineDataFilter.value.hotel_id, '121');
  assert.equal(h.state.onlineDataList.value.length, 0, 'hotel 80 rows must not remain under hotel 121 while the deferred query waits');
  assert.equal(h.state.onlineDataListLoading.value, true);
  assert.equal(h.state.onlineDataPagination.value.total, null);
});

test('Meituan data panels distinguish a pending or failed query from a confirmed empty result', () => {
  const pageStart = template.indexOf('<div v-if="onlineDataTab === \'meituan-download\'">');
  assert.ok(pageStart >= 0);
  const page = template.slice(pageStart);
  assert.ok(/v-if="onlineDataListSnapshotScopeNotice\(\)"[^>]*data-testid="meituan-stored-data-stale-scope"/.test(page), 'a prior query must be labeled stale before another read begins');
  assert.ok(/v-else-if="onlineDataListLoading"[^>]*data-testid="meituan-stored-data-loading"/.test(page), 'loading must not appear as a confirmed empty list');
  assert.ok(/v-else-if="onlineDataListError"[^>]*data-testid="meituan-stored-data-error"/.test(page), 'failed history reads need a visible failure state');
  assert.ok(/v-else data-testid="meituan-stored-data-query-results"/.test(page), 'empty states are only rendered for a completed query');
  assert.match(app, /onlineDataList, onlineDataListError, onlineDataListLoading, onlineDataListSnapshotScopeNotice, onlineDataPagination/,
    'the stale-scope function used by the template must be part of the component setup result');
});
