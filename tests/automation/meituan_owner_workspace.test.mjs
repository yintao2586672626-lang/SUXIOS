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

test('Meituan owner navigation keeps competition circle and merges secondary data views', () => {
  const navigation = sliceBetween(
    template,
    'data-testid="meituan-owner-navigation"',
    '<!-- 美团老板工作台主内容 -->',
  );

  assert.match(navigation, /竞争圈/);
  assert.match(navigation, /经营数据/);
  assert.match(navigation, /账号设置/);
  assert.doesNotMatch(navigation, />流量数据</);
  assert.doesNotMatch(navigation, />订单数据</);
  assert.doesNotMatch(navigation, />广告数据</);
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
