import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const start = source.indexOf('            const openMeituanStoredBusinessDate = async () => {');
const end = /\r?\n            };/.exec(source.slice(start));
assert.ok(start >= 0 && end, 'production stored-date handler exists');
const handler = source.slice(start, start + end.index + end[0].length);

test('mismatched saved records leave no verified page or export snapshot behind', async () => {
  const notices = [];
  const context = vm.createContext({
    meituanForm: { value: { hotelId: '80', startDate: '2026-09-20', endDate: '2026-09-20' } },
    onlineDataTab: { value: 'meituan-manual' }, downloadCenterTab: { value: 'traffic' },
    onlineDataFilter: { value: { hotel_id: '', source: '', start_date: '', end_date: '' } },
    onlineDataList: { value: [{ id: 'old' }] },
    onlineDataQualitySummary: { value: { source: 'old' } },
    onlineDataPagination: { value: { total: 4, page: 1, page_size: 30 } },
    onlineDataListError: { value: '' },
    onlineDataListSnapshotKey: '', onlineDataListSnapshotSession: {},
    onlineDataListSnapshotScope: { value: '' },
    onlineDataListResultCache: new Map(),
    captureAuthSession: () => ({ epoch: 1 }), isAuthSessionCurrent: () => true,
    showToast: (...args) => notices.push(args),
    applyMeituanStoredDataFilter: (_tab, { hotelId }) => {
      context.onlineDataFilter.value.hotel_id = hotelId;
      context.onlineDataFilter.value.source = 'meituan';
    },
    loadOnlineDataList: async () => {
      const rows = [{ id: 'wrong-date', system_hotel_id: 80, source: 'meituan', data_date: '2026-09-19' }];
      context.onlineDataList.value = rows;
      context.onlineDataQualitySummary.value = { source: 'wrong-date' };
      context.onlineDataPagination.value = { total: 1, page: 1, page_size: 30 };
      context.onlineDataListSnapshotKey = 'wrong-date-snapshot';
      context.onlineDataListResultCache.set('wrong-date-snapshot', rows);
      return rows;
    },
  });
  vm.runInContext(`${handler}\nglobalThis.runStoredDate = openMeituanStoredBusinessDate;`, context);
  assert.equal(await context.runStoredDate(), false);
  assert.equal(context.onlineDataList.value.length, 0);
  assert.equal(context.onlineDataQualitySummary.value, null);
  assert.equal(context.onlineDataPagination.value.total, null);
  assert.equal(context.onlineDataListSnapshotKey, '');
  assert.equal(context.onlineDataListResultCache.size, 0);
  assert.match(context.onlineDataListError.value, /日期范围不一致/);
  assert.equal(notices.at(-1)?.[1], 'error');

  context.loadOnlineDataList = async () => {
    const rows = [{ id: 'current', system_hotel_id: 80, source: 'meituan', data_date: '2026-09-20' }];
    context.onlineDataList.value = rows;
    context.onlineDataPagination.value = { total: 1, page: 1, page_size: 30 };
    context.onlineDataListError.value = '';
    context.onlineDataListSnapshotKey = 'current-date-snapshot';
    return rows;
  };
  assert.equal(await context.runStoredDate(), true);
  assert.equal(context.onlineDataList.value[0].id, 'current');
  assert.equal(context.onlineDataPagination.value.total, 1);
  assert.equal(context.onlineDataListSnapshotKey, 'current-date-snapshot');
  assert.equal(notices.at(-1)?.[1], 'success');
});
