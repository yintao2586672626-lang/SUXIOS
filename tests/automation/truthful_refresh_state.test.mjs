import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const ctripPage = readFileSync(
  'resources/frontend/templates/fragments/24-page-ctrip-ebooking.html',
  'utf8'
);

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.ok(startIndex >= 0, `missing start marker: ${start}`);
  assert.ok(endIndex > startIndex, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

test('Ctrip health refresh keeps only the same hotel and date snapshot visible', () => {
  const snapshotScope = sliceBetween(
    appMain,
    'const collectionReliabilityHasCurrentSnapshot = computed(() => {',
    'const AUTO_FETCH_PANEL_CACHE_TTL_MS'
  );

  assert.match(snapshotScope, /snapshot\.hotel_id/);
  assert.match(snapshotScope, /getAutoFetchHotelId\(\)/);
  assert.match(snapshotScope, /snapshot\.period\?\.end_date/);
  assert.match(snapshotScope, /coreOperationsTargetDate\.value/);
  assert.match(appMain, /collectionReliability\.value = null;/);
  assert.match(appMain, /const collectionReliabilityRefreshNotice = computed/);
  assert.match(appMain, /刷新失败，当前显示上次成功结果；不代表当前最新状态/);
  assert.match(ctripPage, /data-testid="ctrip-health-refresh-state"/);
  assert.match(ctripPage, /collectionReliabilityRefreshNotice\.text/);
  assert.match(ctripPage, /<template v-if="collectionReliabilityHasCurrentSnapshot">/);
  assert.doesNotMatch(ctripPage, /v-if="collectionReliabilityLoading"[^>]*>数据健康加载中/);
});

test('stored-data history preserves a successful snapshot and scopes it to the active query', () => {
  const loader = sliceBetween(
    appMain,
    'const loadOnlineHistory = async () => {',
    'const refreshOnlineHistory = async'
  );

  assert.match(appMain, /const onlineHistoryLoading = ref\(false\);/);
  assert.match(appMain, /const onlineHistoryErrorQueryKey = ref\(''\);/);
  assert.match(appMain, /const onlineHistorySnapshotKey = ref\(''\);/);
  assert.match(appMain, /let onlineHistoryRequestSeq = 0;/);
  assert.match(appMain, /const onlineHistoryCurrentQueryKey = computed\(/);
  assert.match(appMain, /onlineHistorySnapshotKey\.value === onlineHistoryCurrentQueryKey\.value/);
  assert.match(appMain, /onlineHistoryErrorQueryKey\.value === onlineHistoryCurrentQueryKey\.value/);
  assert.match(appMain, /const onlineHistoryRefreshNotice = computed/);
  assert.match(appMain, /读取失败，不代表没有历史记录/);

  assert.match(loader, /const requestSeq = \+\+onlineHistoryRequestSeq;/);
  assert.match(loader, /const params = buildOnlineHistoryQueryParams\(\{/);
  assert.match(loader, /const queryKey = params\.toString\(\);/);
  assert.match(loader, /queryKey !== onlineHistoryCurrentQueryKey\.value/);
  assert.match(loader, /res\.code !== 200 \|\| !Array\.isArray\(res\.data\?\.list\)/);
  assert.match(loader, /onlineHistorySnapshotKey\.value = onlineHistoryCurrentQueryKey\.value;/);
  assert.match(loader, /onlineHistoryErrorQueryKey\.value = queryKey;/);
  assert.doesNotMatch(loader, /onlineHistoryList\.value = \[\]/);
  assert.doesNotMatch(loader, /onlineHistorySummary\.value = createEmptyOnlineHistorySummary\(\)/);

  assert.match(ctripPage, /data-testid="online-history-refresh-state"/);
  assert.match(ctripPage, /onlineHistoryRefreshNotice\.text/);
  assert.match(ctripPage, /<template v-if="onlineHistoryHasCurrentSnapshot">[\s\S]*已存记录总数/);
  assert.match(ctripPage, /v-if="onlineHistoryHasCurrentSnapshot" class="overflow-x-auto table-container"/);
  assert.match(ctripPage, /:disabled="onlineHistoryLoading"[\s\S]*查询中/);
});

test('authentication reset clears every retained Ctrip snapshot', () => {
  const resetFlow = sliceBetween(
    appMain,
    'const resetHotelScopedClientState =',
    'const clearAuthSession'
  );

  for (const expected of [
    'collectionReliability.value = null;',
    'onlineHistoryRequestSeq += 1;',
    "onlineHistoryError.value = '';",
    "onlineHistoryErrorQueryKey.value = '';",
    "onlineHistorySnapshotKey.value = '';",
    'onlineHistoryList.value = [];',
    'onlineHistorySummary.value = createEmptyOnlineHistorySummary();',
  ]) {
    assert.match(resetFlow, new RegExp(expected.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
});
