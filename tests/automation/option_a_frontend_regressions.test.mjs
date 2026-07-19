import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const appMain = readFileSync('public/app-main.js', 'utf8');
const appTemplate = readFileSync('resources/frontend/app-template.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const dualOtaStaticSource = readFileSync('public/dual-ota-home-static.js', 'utf8');
const meituanStaticSource = readFileSync('public/meituan-static.js', 'utf8');

const loadWindowApi = (source, key, filename) => {
  const context = { window: {}, console };
  vm.runInNewContext(source, context, { filename });
  return context.window[key] || {};
};

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  if (start < 0) return '';
  const end = source.indexOf(endText, start + startText.length);
  return end > start ? source.slice(start, end) : source.slice(start);
};

test('Ctrip display and export omit the unsupported estimate and preserve real zeroes', () => {
  const api = loadWindowApi(ctripStaticSource, 'SUXI_CTRIP_STATIC', 'public/ctrip-static.js');
  const display = api.buildTruthfulCtripDisplayModel([
    { hotelId: 'A', quantity: 0, bookOrderNum: 0, aiEstimatedTotalRoomNights: 12 },
  ], {
    metrics: { totalAmount: 0, aiEstimatedTotalRoomNights: 12 },
    cards: [
      { key: 'totalAmount', value: 0 },
      { key: 'aiEstimatedTotalRoomNights', value: 12 },
    ],
  });

  assert.equal(display.rows[0].quantity, 0);
  assert.equal(Object.hasOwn(display.rows[0], 'aiEstimatedTotalRoomNights'), false);
  assert.equal(Object.hasOwn(display.summary.metrics, 'aiEstimatedTotalRoomNights'), false);
  assert.deepEqual(Array.from(display.summary.cards, card => card.key), ['totalAmount']);
  assert.doesNotMatch(appMain, /ctripStableEstimateRatio|ctripAiEstimatedRoomNights|全渠道AI预计总间夜数/);
  assert.doesNotMatch(ctripStaticSource, /field === 'aiEstimatedTotalRoomNights'/);

  const downloadTable = sliceBetween(appMain, 'const ctripDownloadRows', 'const buildCtripBusinessCanvas');
  for (const field of ['quantity', 'bookOrderNum', 'commentScore', 'qunarCommentScore']) {
    assert.match(downloadTable, new RegExp(`formatNumber\\(row\\.${field}\\)`), `${field} must preserve zero through formatNumber`);
  }
  assert.doesNotMatch(downloadTable, /row\.(?:quantity|bookOrderNum|commentScore|qunarCommentScore)\s*\|\|\s*'-'/);
  assert.match(downloadTable, /value === null \|\| value === undefined \|\| value === '' \? '-' : `\$\{value\}%`/);
});

test('Ctrip templates omit the unsupported estimate column', () => {
  assert.doesNotMatch(appTemplate, /全渠道AI预计总间夜数|aiEstimatedTotalRoomNights|ai_estimated_total_room_nights/);
});

test('Ctrip latest-snapshot and dual-OTA refresh guards reject stale A-to-B responses', () => {
  const ctripApi = loadWindowApi(ctripStaticSource, 'SUXI_CTRIP_STATIC', 'public/ctrip-static.js');
  assert.equal(ctripApi.isCtripLatestRequestCurrent(
    { seq: 2, hotelId: 'B', range: 'yesterday' },
    { activeSeq: 2, hotelId: 'B', range: 'yesterday' },
  ), true);
  assert.equal(ctripApi.isCtripLatestRequestCurrent(
    { seq: 1, hotelId: 'A', range: 'yesterday' },
    { activeSeq: 2, hotelId: 'B', range: 'yesterday' },
  ), false);

  const dualApi = loadWindowApi(dualOtaStaticSource, 'SUXI_DUAL_OTA_HOME', 'public/dual-ota-home-static.js');
  assert.equal(dualApi.isDualOtaWorkbenchRequestCurrent(
    { seq: 3, hotelId: 'B', range: '7d' },
    { activeSeq: 3, hotelId: 'B', range: '7d' },
  ), true);
  assert.equal(dualApi.isDualOtaWorkbenchRequestCurrent(
    { seq: 2, hotelId: 'A', range: '7d' },
    { activeSeq: 3, hotelId: 'B', range: '7d' },
  ), false);

  const latestLoader = sliceBetween(appMain, 'let ctripLatestRequestSeq', 'const hasVisibleCtripSnapshot');
  assert.match(latestLoader, /isCtripLatestRequestCurrent/);
  assert.match(latestLoader, /if \(!isCurrentRequest\(\)\) return null;[\s\S]*applyLatestCtripSnapshot/);
  assert.match(latestLoader, /finally\s*\{[\s\S]*if \(requestSeq === ctripLatestRequestSeq\)[\s\S]*ctripLatestLoading\.value = false/);

  const workbenchRefresh = sliceBetween(appMain, 'let dualOtaWorkbenchRequestSeq', 'const setDualOtaPlatform');
  assert.match(workbenchRefresh, /isDualOtaWorkbenchRequestCurrent/);
  assert.match(workbenchRefresh, /await loadLatestCtripData[\s\S]*if \(!isCurrentRequest\(\)\) return null;[\s\S]*shouldAutoFetch = true/);
  assert.match(workbenchRefresh, /await loadCompetitorSummary[\s\S]*if \(!isCurrentRequest\(\)\) return null;[\s\S]*dualOtaEnsureWorkbenchAutoFetch/);
});

test('Ctrip current data page keeps target-date truth separate from stored history', () => {
  const latestApply = sliceBetween(appMain, 'const applyLatestCtripSnapshot', 'const shouldHydrateLatestCtripDisplay');
  assert.match(latestApply, /if \(hydrateDisplay && !snapshotModel\.hasRank\) \{\s*clearCtripRankingDisplayState\(\);/);
  assert.match(latestApply, /else if \(hydrateDisplay\) \{\s*useCtripTrafficDisplayRows\(\[\], null, \[\], null\);/);
  assert.match(latestApply, /else if \(hydrateDisplay\) \{\s*ctripCommentResult\.value = null;/);
  assert.match(latestApply, /return currentPage\.value === 'ctrip-ebooking' \? 'yesterday' : '';/);

  const latestStatus = sliceBetween(appMain, 'const ctripLatestSnapshotText', '// 美团配置管理');
  assert.match(latestStatus, /目标日期 \$\{targetDate\} 未采集；当前页不回填历史数据。历史记录请到“入库记录”查询。/);
  assert.doesNotMatch(latestStatus, /已展示最近已抓取快照/);
});

test('dual-OTA current hotel and connection state use verified system bindings only', () => {
  const api = loadWindowApi(dualOtaStaticSource, 'SUXI_DUAL_OTA_HOME', 'public/dual-ota-home-static.js');
  const rows = [
    { hotelId: 'B', hotelName: '本店', amount: 999 },
    { systemHotelId: 'A', hotelName: 'A酒店', amount: 100 },
    { system_hotel_id: 'B', hotelName: 'B酒店', amount: 200 },
  ];

  assert.equal(api.resolveDualOtaBoundHotelRow(rows, 'B').amount, 200);
  assert.equal(api.resolveDualOtaBoundHotelRow([{ hotelId: 'B', hotelName: '本店' }], 'B'), null);
  assert.equal(api.resolveDualOtaBoundHotelRow(rows, ''), null);

  const disconnected = api.buildDualOtaConnectionRows([]);
  const ctripOnly = api.buildDualOtaConnectionRows(['ctrip']);
  const both = api.buildDualOtaConnectionRows(['ctrip', 'meituan']);
  assert.equal(api.hasAllDualOtaConnections(disconnected), false);
  assert.equal(api.hasAllDualOtaConnections(ctripOnly), false);
  assert.equal(api.hasAllDualOtaConnections(both), true);
  assert.equal(ctripOnly.find(row => row.platform === 'ctrip').status, 'connected');
  assert.equal(ctripOnly.find(row => row.platform === 'meituan').status, 'disconnected');
  assert.doesNotMatch(appMain, /dualOtaRowIsPlatformSelf|rows\.find\(dualOtaRowIsPlatformSelf\)/);
  assert.match(appMain, /const dualOtaNormalizeMatchText =/);
  assert.match(appMain, /const dualOtaRowMatchesSelectedHotel =/);
  assert.match(appMain, /resolveDualOtaBoundHotelRow/);
  assert.match(appMain, /buildDualOtaConnectionRows/);
});

test('AI workbench personalizes hotel order per account and opens the matching manual capture page', async () => {
  const hotelOrder = sliceBetween(appMain, 'const dualOtaHotelSearchCountStorageKey', 'const knowledgeCenterHotelOptions');
  const mountedBootstrap = sliceBetween(appMain, 'onMounted(() => {', 'onUnmounted(() => {');
  assert.match(hotelOrder, /suxios_dual_ota_hotel_search_counts_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  assert.match(hotelOrder, /\.sort\(\(a, b\) => \(b\.count - a\.count\) \|\| \(a\.index - b\.index\)\)/);
  assert.match(mountedBootstrap, /if \(isCompassDataPage\(\)\) \{\s*activateAiWorkbenchAfterLogin\(\);/);
  assert.match(appTemplate, /<option value="">全部门店<\/option>[\s\S]*v-for="hotel in dualOtaCurrentHotelOptions"/);

  const shortcuts = sliceBetween(appMain, 'const dualOtaModuleNavigationTarget', 'const dualOtaSystemMetricPlatform');
  assert.match(shortcuts, /'携程竞争圈数据': 'ctrip-ranking'/);
  assert.match(shortcuts, /selectedCtripHotelId\.value = hotelId[\s\S]*currentPage\.value = 'ctrip-ebooking'[\s\S]*openCtripManualTab\('ctrip-ranking'\)/);
  assert.match(shortcuts, /meituanForm\.value\.hotelId = hotelId[\s\S]*currentPage\.value = 'meituan-ebooking'[\s\S]*onlineDataTab\.value = 'meituan-ranking'/);
  assert.match(appTemplate, /data-testid="`dual-ota-\$\{dualOtaModuleNavigationTarget\(item\)\}-shortcut`"[\s\S]*@click="openDualOtaModule\(item\)"/);
  assert.ok(
    appMain.indexOf('const hotelPlatformBindingRows =') < appMain.indexOf('watch([dualOtaReadyStoreScopes, dualOtaSelectedHotel]'),
    'the immediate connection watcher must run only after its hotel binding dependency is initialized',
  );

  const createShortcutRuntime = new Function(
    'filterReportHotel',
    'ctripTargetHotelManuallySelected',
    'selectedCtripHotelId',
    'currentPage',
    'nextTick',
    'openCtripManualTab',
    'meituanForm',
    'suppressNextMeituanHotelConfigApply',
    'onlineDataTab',
    'scheduleMeituanHotelConfigApply',
    `${shortcuts}; return { dualOtaModuleNavigationTarget, openDualOtaModule };`,
  );
  const filterReportHotel = { value: '42' };
  const ctripTargetHotelManuallySelected = { value: false };
  const selectedCtripHotelId = { value: '' };
  const currentPage = { value: 'ai-workbench' };
  const onlineDataTab = { value: '' };
  const meituanForm = { value: { hotelId: '' } };
  const ctripTabs = [];
  const meituanApplies = [];
  const runtime = createShortcutRuntime(
    filterReportHotel,
    ctripTargetHotelManuallySelected,
    selectedCtripHotelId,
    currentPage,
    async () => {},
    tab => ctripTabs.push(tab),
    meituanForm,
    false,
    onlineDataTab,
    options => meituanApplies.push(options),
  );

  await runtime.openDualOtaModule({ name: '携程竞争圈数据' });
  assert.equal(currentPage.value, 'ctrip-ebooking');
  assert.equal(selectedCtripHotelId.value, '42');
  assert.equal(ctripTargetHotelManuallySelected.value, true);
  assert.deepEqual(ctripTabs, ['ctrip-ranking']);

  currentPage.value = 'ai-workbench';
  await runtime.openDualOtaModule({ name: '美团竞争圈数据' });
  assert.equal(currentPage.value, 'meituan-ebooking');
  assert.equal(onlineDataTab.value, 'meituan-ranking');
  assert.equal(meituanForm.value.hotelId, '42');
  assert.deepEqual(meituanApplies, [{ delayMs: 0, refreshList: false, skipIfAligned: true }]);
});

test('business date defaults and filenames use local calendar dates', () => {
  const appBusinessDateSections = [
    sliceBetween(appMain, 'const operationToday', 'const lifecycleMetricLabels'),
    sliceBetween(appMain, 'const priceSuggestionFilter', 'const manualCtripPricingInputMeta'),
    sliceBetween(appMain, 'const forecastFilter', 'const createDemandForecastForm'),
    sliceBetween(appMain, 'const competitorFilter', 'const createCompetitorPriceForm'),
    sliceBetween(appMain, 'const downloadCtripSearchOpportunityImage', 'const loadDownloadCenterData'),
    sliceBetween(appMain, 'const exportSystemConfig', 'const loadUserManagementData'),
  ].join('\n');
  assert.doesNotMatch(appBusinessDateSections, /toISOString\(\)\.(?:split\('T'\)|slice\(0, 10\))/);
  assert.match(appBusinessDateSections, /formatDate\(new Date/);

  const meituanConfigName = sliceBetween(meituanStaticSource, 'const buildMeituanConfigAutoName', 'const buildMeituanConfigSaveRequestBody');
  const meituanCollector = sliceBetween(meituanStaticSource, 'const buildMeituanOrderDomCollectorScript', 'const parseMeituanOrderCsvText');
  const meituanImport = sliceBetween(meituanStaticSource, 'const buildMeituanOrderCsvImportRequestBody', 'const runMeituanOrderCsvImportFlow');
  assert.match(meituanConfigName, /todayDateText\(\)/);
  assert.match(meituanCollector, /function localDateText\(\)/);
  assert.match(meituanCollector, /localDateText\(\)/);
  assert.doesNotMatch(meituanCollector, /new Date\(\)\.toISOString\(\)\.slice\(0, 10\)/);
  assert.match(meituanImport, /todayDateText\(\)/);
});

test('draft checkbox restoration can change a checked field back to false', () => {
  const modulePath = require.resolve('../../public/form-operation-support.js');
  delete require.cache[modulePath];
  const api = require(modulePath);
  const events = [];
  const field = {
    type: 'checkbox',
    checked: true,
    dispatchEvent: event => events.push(event.type),
  };

  api.writeField(field, false);

  assert.equal(field.checked, false);
  assert.deepEqual(events, ['input', 'change']);
});

test('frontend fallbacks keep missing risk and forecast metrics unknown instead of zero or low-risk', () => {
  const openingFallbacks = sliceBetween(appMain, 'let buildOpeningOverviewCards', 'const ensureOperationStaticReady');
  assert.match(openingFallbacks, /let openingRiskText = \(\) => '待评估'/);
  assert.doesNotMatch(openingFallbacks, /let openingRiskText = \(\) => '低风险'/);

  const researchForecastCards = sliceBetween(appMain, 'const revenueResearchForecastCards', 'const runRevenueResearchProduct');
  assert.match(researchForecastCards, /const hasMetric =/);
  assert.match(researchForecastCards, /: '—'/);
  assert.match(researchForecastCards, /forecast\.truth_context \|\| \{\}/);
  assert.match(researchForecastCards, /onlineTruthStatusText\(truth\)/);
  assert.match(researchForecastCards, /onlineTruthDetailText\(truth\)/);
  assert.doesNotMatch(researchForecastCards, /forecast(?:7|30)\.(?:revenue|adr) \|\| 0/);
  assert.doesNotMatch(researchForecastCards, /forecast\.trend_percent \|\| 0/);
  assert.match(appTemplate, /data-testid="revenue-research-forecast-truth"/);
  assert.match(appTemplate, /\{\{ card\.truthDetail \}\}/);
});

test('feasibility generation keeps the target hotel as an explicit truth boundary', () => {
  const feasibilityFlow = sliceBetween(appMain, 'const buildFeasibilityPayload', 'const createFeasibilityExecutionIntent');
  assert.match(feasibilityFlow, /hotel_id:\s*Number\(aiFeasibilityHotelId\.value \|\| 0\) \|\| null/);
  assert.match(feasibilityFlow, /record\.input\.hotel_id \|\| record\.input\.system_hotel_id/);
  assert.match(appTemplate, /data-testid="feasibility-target-hotel"/);
  assert.match(appTemplate, /未绑定门店（仅未验证情景）/);
  assert.match(appTemplate, /未绑定不会跨门店汇总/);
});
