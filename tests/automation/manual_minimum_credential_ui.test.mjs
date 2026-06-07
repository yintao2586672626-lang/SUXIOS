import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');

const sliceFrom = (needle, endNeedle) => {
  const start = html.indexOf(needle);
  assert.ok(start >= 0, `missing start marker: ${needle}`);
  const end = endNeedle ? html.indexOf(endNeedle, start) : -1;
  return end > start ? html.slice(start, end) : html.slice(start);
};

const functionSlice = (name) => sliceFrom(`const ${name} = async () => {`, `\n            const `);

test('Ctrip manual ranking and traffic use platform authorization as the daily credential', () => {
  const fetchCtripData = functionSlice('fetchCtripData');
  const fetchCtripTrafficData = functionSlice('fetchCtripTrafficData');

  assert.doesNotMatch(fetchCtripData, /请输入节点ID/);
  assert.match(fetchCtripData, /const ctripFetchBody = \{/);
  assert.match(fetchCtripData, /if \(nodeId\) \{\s*ctripFetchBody\.node_id = nodeId;/);
  assert.match(fetchCtripTrafficData, /const ctripTrafficFetchBody = \{/);
  assert.match(html, /只需平台授权/);
});

test('Meituan ranking does not expose resource id inputs on the daily fetch panel', () => {
  const rankingPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const fetchMeituanData = functionSlice('fetchMeituanData');

  assert.doesNotMatch(rankingPanel, /v-model="meituanForm\.partnerId"/);
  assert.doesNotMatch(rankingPanel, /v-model="meituanForm\.poiId"/);
  assert.match(rankingPanel, /需一次性门店标识/);
  assert.match(fetchMeituanData, /需补充一次性门店标识/);
});

test('Meituan config saves cookie-only and no longer treats room counts as credentials', () => {
  const saveMeituanConfigItem = functionSlice('saveMeituanConfigItem');

  assert.match(saveMeituanConfigItem, /请输入平台授权内容/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入Partner ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入POI ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入酒店房量/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入竞争圈总房量/);
  assert.match(html, /缺门店标识/);
});

test('Meituan orders and ads remain network-required workflows', () => {
  assert.match(html, /需 Network 请求信息/);
  assert.match(functionSlice('fetchMeituanOrdersData'), /请填写订单接口 Request URL/);
  assert.match(functionSlice('fetchMeituanAdsData'), /请填写广告接口 Request URL/);
});

test('Ctrip ads only exposes the effect report workflow', () => {
  const adsPanel = sliceFrom('<div v-if="onlineDataTab === \'ctrip-ads\'">', '<div v-if="onlineDataTab === \'ctrip-overview\'">');
  const adsConfigPanel = sliceFrom('<!-- 携程广告配置 -->', '<!-- 美团广告配置 -->');

  assert.match(adsPanel, /效果报表/);
  assert.match(adsPanel, /高级排障接口地址（可选）/);
  assert.doesNotMatch(adsPanel, /推广活动列表/);
  assert.doesNotMatch(adsPanel, /推广活动报表/);
  assert.doesNotMatch(adsPanel, /广告接口 URL <span class="text-red-500">\*<\/span>/);
  assert.doesNotMatch(adsPanel, /v-model="ctripAdsBrowserCaptureForm\.apiType"/);
  const todayOptionIndex = adsPanel.indexOf('<option value="today">');
  const yesterdayOptionIndex = adsPanel.indexOf('<option value="yesterday">');
  assert.ok(todayOptionIndex >= 0, 'missing today option');
  assert.ok(yesterdayOptionIndex >= 0, 'missing yesterday option');
  assert.ok(todayOptionIndex < yesterdayOptionIndex, 'today option should appear before yesterday');
  assert.match(adsConfigPanel, /效果报表/);
  assert.match(adsConfigPanel, /效果报表接口URL（可选）/);
  assert.doesNotMatch(adsConfigPanel, /推广活动列表/);
  assert.doesNotMatch(adsConfigPanel, /推广活动报表/);
  assert.doesNotMatch(adsConfigPanel, /v-if="dataConfigForm\.api_type === 'campaign_report'"/);
  assert.match(html, /const defaultCtripAdsEffectReportUrl =/);
  assert.match(html, /const normalizeCtripAdsApiType = \(value = ''\) =>/);
  assert.match(functionSlice('fetchCtripAdsData'), /const url = String\(form\.url \|\| defaultCtripAdsEffectReportUrl\)\.trim\(\);/);
  assert.match(functionSlice('fetchCtripAdsData'), /api_type: normalizeCtripAdsApiType\(form\.apiType\)/);
});
