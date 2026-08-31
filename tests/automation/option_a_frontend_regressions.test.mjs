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

test('Ctrip display removes unsupported estimates and refuses to invent full-channel room nights', () => {
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
  const scenario = api.buildCtripFullChannelRoomNightScenario({
    hotelId: 'A',
    hotelName: 'Alpha Hotel',
    quantity: 54,
  });
  assert.equal(scenario.status, 'full_channel_source_missing');
  assert.equal(scenario.value, null);
  assert.equal(scenario.ctripRoomNights, 54);
  assert.equal(Object.hasOwn(scenario, 'multiplier'), false);
  assert.match(scenario.sourceLabel, /全渠道间夜来源未接入/);
  assert.equal(api.buildCtripFullChannelRoomNightScenario({ hotelId: 'B', quantity: 0 }).value, null);
  const missingScenario = api.buildCtripFullChannelRoomNightScenario({
    quantity: 0,
    metricSourceStatus: { quantity: '系统未返回' },
  });
  assert.equal(missingScenario.value, null);
  assert.equal(missingScenario.status, 'ctrip_room_nights_missing');
  const scenarioRow = api.attachCtripFullChannelRoomNightScenario({
    hotelId: 'A',
    hotelName: 'Alpha Hotel',
    quantity: 54,
  });
  assert.equal(scenarioRow.fullChannelRoomNightsEstimate, null);
  assert.equal(scenarioRow.fullChannelRoomNightsEstimateMeta.status, 'full_channel_source_missing');
  assert.doesNotMatch(appMain, /ctripStableEstimateRatio|ctripAiEstimatedRoomNights|全渠道AI预计总间夜数/);
  assert.doesNotMatch(appMain, /ctripFullChannelRoomNightSharePercent|normalizeCtripRoomNightSharePercent/);
  assert.doesNotMatch(ctripStaticSource, /field === 'aiEstimatedTotalRoomNights'/);
  assert.doesNotMatch(appMain, /field: 'fullChannelRoomNightsEstimate'|label: '全渠道间夜'|fullChannelRoomNightText/);
  assert.doesNotMatch(ctripStaticSource, /deriveCtripFullChannelRoomNightMultiplier|1\.15\s*\+|scenario_estimate/);

  const downloadAdapter = sliceBetween(appMain, 'const buildCtripBusinessCanvas', 'const canvasToPngBlob');
  assert.match(downloadAdapter, /captureCtripBusinessDownloadSnapshot/);
  assert.match(downloadAdapter, /cards: visibleSnapshot\.cards/);
  assert.match(downloadAdapter, /table: visibleSnapshot\.table/);
  assert.doesNotMatch(appMain, /const ctripDownloadRows/);
  assert.match(appTemplate, /hasDisplayValue\(hotel\.amount\)[\s\S]*Number\(hotel\.amount\)\.toLocaleString\('zh-CN', \{ minimumFractionDigits: 1, maximumFractionDigits: 1 \}\)/);
  assert.match(appTemplate, /hasDisplayValue\(hotel\.adr\)[\s\S]*Number\(hotel\.adr\)\.toLocaleString\('zh-CN', \{ minimumFractionDigits: 1, maximumFractionDigits: 1 \}\)/);
  for (const field of ['quantity', 'bookOrderNum', 'commentScore', 'qunarCommentScore']) {
    assert.match(appTemplate, new RegExp(`formatOptionalNumber\\(hotel\\.${field}\\)`), `${field} must preserve the visible zero/missing state`);
  }
  assert.match(appMain, /const formatOptionalPercent = \(value, missingText = '未返回'\) => \{[\s\S]*Number\.isFinite\(numeric\) \? `\$\{toFixedSafe\(numeric, 2, '0\.00'\)\}%` : missingText/);
  assert.match(ctripStaticSource, /normalizeCtripVisibleDownloadText\(node\?\.innerText \?\? node\?\.textContent \?\? '', missingText\)/);
  assert.match(ctripStaticSource, /Array\.from\(row\.querySelectorAll\?\.\('td'\) \|\| \[\]\)\.map\(cell => ctripVisibleDownloadNodeText\(cell\)\)/);
  assert.match(ctripStaticSource, /ctripVisibleDownloadNodeText/);
  assert.match(ctripStaticSource, /row => row\?\.\[columnIndex\] \?\? '-'/);
});

test('Ctrip templates expose source boundaries and no unsupported full-channel room-night formula', () => {
  assert.doesNotMatch(appTemplate, /携程间夜占全渠道比例（%）|v-model="ctripFullChannelRoomNightSharePercent"/);
  assert.match(appMain, /field: 'quantity', label: '离店间夜', title: '携程离店间夜，仅代表携程渠道'/);
  assert.doesNotMatch(appMain, /field: 'fullChannelRoomNightsEstimate'|label: '全渠道间夜'/);
  assert.doesNotMatch(appTemplate, /hotel\.fullChannelRoomNightsEstimate|全渠道间夜来源/);
  assert.doesNotMatch(appTemplate, /1\.15–1\.30|情景推算/);
  assert.doesNotMatch(appTemplate, /<span class="ml-1 rounded bg-amber-100 px-1 py-0\.5 text-\[10px\] text-amber-700">情景<\/span>/);
  assert.doesNotMatch(appTemplate, /≈\s*\{\{\s*formatOptionalNumber\(hotel\.fullChannelRoomNightsEstimate\)\s*\}\}/);
  assert.doesNotMatch(appTemplate, /<div class="mt-0\.5 text-\[10px\] text-gray-400">非平台返回<\/div>/);
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
  assert.match(latestApply, /return currentPage\.value === 'ctrip-ebooking'[\s\S]{0,120}buildCtripFetchDateRange\(\{\}, new Date\(\)\)\.endDate/);

  const latestStatus = sliceBetween(appMain, 'const ctripLatestSnapshotText', '// 美团配置管理');
  assert.match(latestStatus, /目标日期 \$\{targetDate\} 未采集；当前页不回填历史数据。历史记录请到“入库记录”查询。/);
  assert.doesNotMatch(latestStatus, /已展示最近已抓取快照/);
  assert.doesNotMatch(latestStatus, /当前展示：\$\{dataDate\} · 采集时间 \$\{fetchedAt\}/);
});

test('dual-OTA current hotel requires both the system binding and an explicit self identity', () => {
  const api = loadWindowApi(dualOtaStaticSource, 'SUXI_DUAL_OTA_HOME', 'public/dual-ota-home-static.js');
  const rows = [
    { systemHotelId: 'B', hotelId: 'COMPETITOR-B', hotelName: '竞圈第一', compareType: 'competitor', isSelf: false, amount: 999 },
    { systemHotelId: 'A', hotelId: 'SELF-A', hotelName: 'A酒店', compareType: 'self', isSelf: true, amount: 100 },
    { system_hotel_id: 'B', hotelId: 'SELF-B', hotelName: 'B酒店', compare_type: 'self', isSelf: true, amount: 200 },
  ];

  assert.equal(api.resolveDualOtaBoundHotelRow(rows, 'B').amount, 200);
  assert.equal(api.resolveDualOtaBoundHotelRow([rows[0]], 'B'), null);
  assert.equal(api.resolveDualOtaBoundHotelRow([{ systemHotelId: 'B', hotelName: '身份未标记' }], 'B'), null);
  assert.equal(api.resolveDualOtaBoundHotelRow([{ hotelId: 'B', hotelName: '本店', compareType: 'self', isSelf: true }], 'B'), null);
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
  assert.match(mountedBootstrap, /const primaryPageLoad = isCompassDataPage\(\)\s*\?\s*activateCoreOperationsAfterLogin\(\)\s*:\s*nextTick\(\);/);
  assert.match(mountedBootstrap, /scheduleHotelManagementPrewarmAfter\(primaryPageLoad\);/);
  assert.match(appMain, /const scheduleHotelManagementPrewarmAfter = \(primaryPageLoad\) => \{\s*Promise\.resolve\(primaryPageLoad\)\.then/);
  assert.match(appTemplate, /<option value="">全部门店<\/option>[\s\S]*v-for="hotel in dualOtaCurrentHotelOptions"/);

  const shortcuts = sliceBetween(appMain, 'const dualOtaModuleNavigationTarget', 'const dualOtaSystemMetricPlatform');
  assert.match(shortcuts, /'携程竞争圈数据': 'ctrip-ranking'/);
  assert.match(shortcuts, /await applyGeneralHotelToPlatformContext\('ctrip', hotelId\)[\s\S]*currentPage\.value = 'ctrip-ebooking'[\s\S]*openCtripManualTab\('ctrip-ranking'\)/);
  assert.match(shortcuts, /await applyGeneralHotelToPlatformContext\('meituan', hotelId\)[\s\S]*currentPage\.value = 'meituan-ebooking'[\s\S]*onlineDataTab\.value = 'meituan-ranking'/);
  assert.match(shortcuts, /selectedCtripHotelId\.value = normalizedHotelId/);
  assert.match(shortcuts, /meituanForm\.value\.hotelId = normalizedHotelId/);
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
    'ensureHotelOtaConfigLists',
    'ctripTargetHotelOptions',
    'meituanTargetHotelOptions',
    'permittedHotels',
    'hotels',
    'showToast',
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
    async () => {},
    { value: [{ id: '42' }] },
    { value: [{ id: '42' }] },
    { value: [{ id: '42', name: '测试门店' }] },
    { value: [] },
    () => {},
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
