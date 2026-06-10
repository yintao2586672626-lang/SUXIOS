import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');

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
  assert.match(fetchCtripData, /const ctripFetchBody = buildCtripFetchRequestBody\(\{/);
  assert.match(fetchCtripData, /nodeId,/);
  assert.match(fetchCtripTrafficData, /const ctripTrafficFetchBody = buildCtripTrafficFetchRequestBody\(\{/);
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

test('Meituan hotel matching does not wait for all-store competitor summaries', () => {
  const loadCompetitorSummary = sliceFrom('const loadCompetitorSummary = async (options = {}) => {', '\n            const loadCompassData');
  const scheduleMeituanRankingSummaryRefresh = sliceFrom('const scheduleMeituanRankingSummaryRefresh = (options = {}) => {', '\n\n            // 线上数据获取相关方法');
  const applyMeituanHotelConfig = sliceFrom('const applyMeituanHotelConfig = async (showMessage = true, options = {}) => {', '\n            const syncMeituanTrafficConfigFromSelectedConfig');
  const loadMeituanConfigDetail = sliceFrom('const meituanConfigDetailCache = new Map();', '\n            const applyCtripConfigObject');
  const loadMeituanConfigList = sliceFrom('const loadMeituanConfigList = async () => {', '\n            const saveMeituanConfigItem');
  const openHomeQuickEntry = sliceFrom('const openHomeQuickEntry = (entry) => {', '\n\n            // 竞对价格监控');
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n            const handleMenuClick');
  const handleMenuClick = sliceFrom('const handleMenuClick = (item) => {', '\n\n            const isStillOnRequestPage');

  assert.match(loadCompetitorSummary, /const isMeituanRankingPage = currentPage\.value === 'meituan-ebooking' && onlineDataTab\.value === 'meituan-ranking';/);
  assert.match(loadCompetitorSummary, /includeByHotel = options\.includeByHotel === true \|\| \(options\.includeByHotel !== false && !isMeituanRankingPage\);/);
  assert.match(loadCompetitorSummary, /if \(includeByHotel\) params\.append\('include_by_hotel', '1'\);/);
  assert.match(loadCompetitorSummary, /if \(requestSeq !== competitorSummaryRequestSeq\) return;/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /deferUiTask\(async \(\) => \{/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /await loadCompetitorSummary\(\{ includeByHotel: false \}\);/);
  assert.doesNotMatch(openHomeQuickEntry, /await loadCompetitorSummary\(\)/);
  assert.match(openHomeQuickEntry, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.doesNotMatch(handleMenuClick, /await loadCompetitorSummary\(\)/);
  assert.match(handleMenuClick, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'main', \(\) => loadMeituanConfigList\(\)\);/);
  assert.match(currentPageWatcher, /deferUiTask\(\(\) => \{/);
  assert.match(currentPageWatcher, /loadMeituanConfig\(\),/);
  assert.match(currentPageWatcher, /loadOnlineDataHotelList\(\),/);
  assert.doesNotMatch(applyMeituanHotelConfig, /await loadCompetitorSummary\(\)/);
  assert.match(applyMeituanHotelConfig, /options\.refreshList !== false/);
  assert.match(applyMeituanHotelConfig, /await meituanConfigListLoadingPromise;/);
  assert.match(applyMeituanHotelConfig, /scheduleMeituanRankingSummaryRefresh\(\{ force: true \}\);/);
  assert.match(loadMeituanConfigDetail, /const meituanConfigDetailCache = new Map\(\);/);
  assert.match(loadMeituanConfigDetail, /const clearMeituanConfigDetailCache = \(id = ''\) => \{/);
  assert.match(loadMeituanConfigDetail, /if \(meituanConfigDetailLoadingPromises\.has\(cacheKey\)\) \{/);
  assert.match(loadMeituanConfigDetail, /return meituanConfigDetailLoadingPromises\.get\(cacheKey\);/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailCache\.set\(cacheKey, \{/);
  assert.match(html, /clearMeituanConfigDetailCache\(meituanConfigForm\.value\.id\);/);
  assert.match(html, /clearMeituanConfigDetailCache\(id\);/);
  assert.match(loadMeituanConfigList, /if \(meituanConfigListLoadingPromise\) \{/);
  assert.match(loadMeituanConfigList, /return meituanConfigListLoadingPromise;/);
  assert.match(loadMeituanConfigList, /applyMeituanHotelConfig\(false, \{ refreshList: false \}\);/);
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
  assert.match(html, /requireCtripStatic\('defaultCtripAdsEffectReportUrl'\)/);
  assert.match(html, /requireCtripStatic\('normalizeCtripAdsApiType'\)/);
  assert.match(html, /requireCtripStatic\('buildCtripAdsFetchRequestBody'\)/);
  assert.match(ctripStatic, /const defaultCtripAdsEffectReportUrl =/);
  assert.match(ctripStatic, /const normalizeCtripAdsApiType = \(value = ''\) =>/);
  assert.match(ctripStatic, /const buildCtripAdsFetchRequestBody = \(\{/);
  assert.match(functionSlice('fetchCtripAdsData'), /const url = String\(form\.url \|\| defaultCtripAdsEffectReportUrl\)\.trim\(\);/);
  assert.match(functionSlice('fetchCtripAdsData'), /const ctripAdsFetchBody = buildCtripAdsFetchRequestBody\(\{/);
  assert.doesNotMatch(functionSlice('fetchCtripAdsData'), /api_type: normalizeCtripAdsApiType\(form\.apiType\)/);
});
