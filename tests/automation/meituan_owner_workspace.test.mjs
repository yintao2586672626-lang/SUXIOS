import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const template = fs.readFileSync(new URL('../../resources/frontend/app-template.html', import.meta.url), 'utf8');
const appMain = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');

function sliceBetween(source, start, end) {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
}

test('Meituan owner navigation exposes only capturable data categories as primary filters', () => {
  const navigation = sliceBetween(
    template,
    'data-testid="meituan-owner-navigation"',
    '<!-- 美团老板工作台主内容 -->',
  );

  assert.match(navigation, /同行榜单/);
  assert.match(navigation, /流量分析/);
  assert.match(navigation, /订单流向/);
  assert.match(navigation, /订单线索/);
  assert.match(navigation, /广告数据/);
  assert.doesNotMatch(navigation, /经营数据/);
  assert.match(navigation, /openHotelManagementForOta[^>]*>[\s\S]*?设置/);
  assert.match(navigation, /openMeituanStoredDataTab\('traffic'\)/);
  assert.match(navigation, /openMeituanStoredDataTab\('orders'\)/);
  assert.match(navigation, /openMeituanStoredDataTab\('ads'\)/);
});

test('technical Meituan collection tools remain collapsed and super-admin only', () => {
  const advanced = sliceBetween(
    template,
    '<details v-if="user?.is_super_admin" data-testid="meituan-advanced-collection"',
    '<!-- 美团老板工作台主内容 -->',
  );

  assert.match(advanced, /<details[^>]*v-if="user\?\.is_super_admin"/);
  assert.match(advanced, /openMeituanManualTab\('meituan-traffic'\)/);
  assert.match(advanced, /openMeituanManualTab\('meituan-orders'\)/);
  assert.match(advanced, /openMeituanManualTab\('meituan-ads'\)/);
});

test('Meituan ranking keeps the official freshness notice without extra realtime copy', () => {
  assert.match(template, />今日实时<\/button>/);
  assert.doesNotMatch(template, /今日实时\*/);
  assert.match(template, /每日9点更新前日数据。数据仅作经营参考，不作结算依据。/);
  assert.doesNotMatch(template, /“今日实时”是美团页面筛选名称，不代表秒级实时/);
});

test('competition-circle and stored-data actions remain truthful without exposing raw debug tools', () => {
  const ranking = sliceBetween(
    template,
    "<div v-if=\"onlineDataTab === 'meituan-ranking'\">",
    "<div v-if=\"onlineDataTab === 'meituan-traffic'\">",
  );
  const storedData = sliceBetween(
    template,
    "<div v-if=\"onlineDataTab === 'meituan-download'\">",
    '<!-- 未验证竞对数据预览 - 弹窗 -->',
  );

  assert.match(ranking, /aria-label="更新竞争圈"/);
  assert.doesNotMatch(ranking, /data-testid="meituan-ranking-advanced-tools"/);
  assert.doesNotMatch(ranking, /查看原始结果|复制排障数据|JSON\.stringify\(onlineDataResult/);
  assert.match(storedData, /同行榜单明细/);
  assert.match(storedData, /卡片仅统计本页数据/);
  assert.match(storedData, /@click="queryMeituanStoredData"/);
  assert.match(storedData, /当前第 \{\{ onlineDataPage \}\} 页/);
  assert.match(storedData, /当前筛选暂无美团流量数据/);
  assert.doesNotMatch(storedData, /总订单量|总营收|平均房价/);
  assert.match(storedData, /v-if="user\?\.is_super_admin"[^>]*@click="deleteOnlineDataItem\(item\.id\)"/);
  assert.match(template, /更新美团竞争圈/);
  assert.doesNotMatch(template, />一键获取美团</);
});

test('Meituan stored data entry resets cross-platform dates and keeps manual queries editable', () => {
  const applyStoredFilter = sliceBetween(
    appMain,
    'const applyMeituanStoredDataFilter = (tab, options = {}) => {',
    'const queryMeituanStoredData = async () => {',
  );
  const switchToStoredData = sliceBetween(
    appMain,
    'const switchToMeituanDownloadCenter = () => {',
    'const openMeituanStoredDataTab = (tab) => {',
  );
  const queryStoredData = sliceBetween(
    appMain,
    'const queryMeituanStoredData = async () => {',
    'let downloadCenterTabLoadSeq = 0;',
  );

  assert.match(applyStoredFilter, /if \(options\.resetDates === true\) \{/);
  assert.match(applyStoredFilter, /onlineDataFilter\.value\.start_date = formatDate\(thirtyDaysAgo\);/);
  assert.match(applyStoredFilter, /onlineDataFilter\.value\.end_date = formatDate\(today\);/);
  assert.match(switchToStoredData, /resetPage: true, resetDates: true/);
  assert.doesNotMatch(queryStoredData, /resetDates: true/);
});
