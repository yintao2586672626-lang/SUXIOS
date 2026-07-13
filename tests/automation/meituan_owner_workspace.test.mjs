import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const template = fs.readFileSync(new URL('../../resources/frontend/app-template.html', import.meta.url), 'utf8');

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
  assert.match(navigation, /订单数据/);
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

test('competition-circle action and stored-data actions are truthful for hotel owners', () => {
  const ranking = sliceBetween(
    template,
    "<div v-if=\"onlineDataTab === 'meituan-ranking'\">",
    "<div v-if=\"onlineDataTab === 'meituan-traffic'\">",
  );
  const storedData = sliceBetween(
    template,
    "<div v-if=\"onlineDataTab === 'meituan-download'\">",
    '<!-- AI智能分析模块 - 弹窗 -->',
  );

  assert.match(ranking, /aria-label="更新竞争圈"/);
  assert.match(ranking, /data-testid="meituan-ranking-advanced-tools"/);
  assert.match(ranking, /v-if="user\?\.is_super_admin"/);
  assert.match(storedData, /竞争圈明细/);
  assert.match(storedData, /v-if="user\?\.is_super_admin"[^>]*@click="deleteOnlineDataItem\(item\.id\)"/);
  assert.match(template, /更新美团竞争圈/);
  assert.doesNotMatch(template, />一键获取美团</);
});
