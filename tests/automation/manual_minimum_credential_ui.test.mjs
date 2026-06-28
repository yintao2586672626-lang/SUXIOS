import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');
const ctripStatic = readFileSync('public/ctrip-static.js', 'utf8');
const meituanStatic = readFileSync('public/meituan-static.js', 'utf8');
const platformAutoSettingsPanels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');
const ctripProfileFieldConfigPanel = readFileSync('public/components/online-data/ctrip-profile-field-config-panel.js', 'utf8');

const sliceFrom = (needle, endNeedle) => {
  const start = html.indexOf(needle);
  assert.ok(start >= 0, `missing start marker: ${needle}`);
  const end = endNeedle ? html.indexOf(endNeedle, start) : -1;
  return end > start ? html.slice(start, end) : html.slice(start);
};

const functionSlice = (name) => sliceFrom(`const ${name} = async () => {`, `\n            const `);
const constSlice = (needle, endNeedle = '\n            const ') => sliceFrom(needle, endNeedle);

test('Ctrip manual ranking and traffic use platform authorization as the daily credential', () => {
  const fetchCtripData = sliceFrom('const fetchCtripData = async () => {', 'const fetchMeituanData = async () => {');
  const fetchCtripTrafficData = sliceFrom('const fetchCtripTrafficData = async () => {', 'const fetchCtripComments = async () => {');
  const ctripManualFetchConfigGuard = sliceFrom('const ctripManualFetchConfigProofPending = () => {', '\n\n            const saveCtripConfig');
  const ctripFetchFlow = ctripStatic.slice(
    ctripStatic.indexOf('const runCtripFetchDataFlow = async ({'),
    ctripStatic.indexOf('const buildLatestCtripSnapshotModel')
  );

  assert.doesNotMatch(fetchCtripData, /请输入节点ID/);
  assert.match(html, /requireCtripStatic\('runCtripFetchDataFlow'\)/);
  assert.match(html, /requireCtripStatic\('isCtripRankingFormAlignedWithConfig'\)/);
  assert.match(fetchCtripData, /runCtripFetchDataFlow\(\{/);
  assert.match(fetchCtripData, /const preparingConfig = ctripManualFetchConfigProofPending\(\);/);
  assert.match(fetchCtripData, /ensureCtripConfigSecret: async config => ensureCtripConfigSecret\(await resolveCtripManualFetchConfig\(config\)\)/);
  assert.match(fetchCtripData, /finally \{\s*if \(preparingConfig\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
  assert.match(fetchCtripData, /body: JSON\.stringify\(requestBody\)/);
  assert.match(ctripStatic, /const isCtripRankingFormAlignedWithConfig = \(form = \{\}, config = \{\}, options = \{\}\) =>/);
  assert.match(ctripStatic, /if \(selectedConfig && !isCtripRankingFormAlignedWithConfig\(form, selectedConfig, \{ selectedHotelId: selectedCtripHotelId \}\)\) \{/);
  assert.match(ctripStatic, /const requestBody = \{ \.\.\.requestContext\.requestBody, async: false, background: false \};/);
  assert.match(ctripStatic, /const requestContext = buildCtripFetchRequestContext\(\{/);
  assert.match(ctripStatic, /const nodeId = String\(form\.nodeId \|\| ''\)\.trim\(\)/);
  assert.match(html, /requireCtripStatic\('runCtripTrafficFetchFlow'\)/);
  assert.match(fetchCtripTrafficData, /runCtripTrafficFetchFlow\(\{/);
  assert.match(fetchCtripTrafficData, /const preparingConfig = ctripManualFetchConfigProofPending\(\);/);
  assert.match(fetchCtripTrafficData, /ensureCtripConfigSecret: async config => ensureCtripConfigSecret\(await resolveCtripManualFetchConfig\(config\)\)/);
  assert.match(ctripStatic, /const requestBody = buildCtripTrafficFetchRequestBody\(\{/);
  assert.match(html, /:disabled="fetchingData \|\| !canFetchCtripManualData\(\)"/);
  assert.match(ctripManualFetchConfigGuard, /return !!ctripConfigListLoadingPromise\s*\|\| \(!ctripConfigListLoaded\.value && !ctripConfigListLoadFailed\.value\);/);
  assert.match(ctripManualFetchConfigGuard, /const canFetchCtripManualData = \(\) => \{/);
  assert.match(ctripManualFetchConfigGuard, /if \(String\(activeCookies \|\| ''\)\.trim\(\)\) return true;/);
  assert.match(ctripManualFetchConfigGuard, /return !!selectedCtripHotelId\.value && \(selectedCtripHotelConfig\.value \|\| ctripManualFetchConfigProofPending\(\)\);/);
  assert.match(ctripManualFetchConfigGuard, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(ctripManualFetchConfigGuard, /return getActiveCtripConfig\(\);/);
  assert.doesNotMatch(ctripFetchFlow, /notify\('请选择目标酒店', 'error'\)/);
  assert.match(ctripFetchFlow, /const selectedConfig = selectedCtripHotelId\s*\?\s*await ensureCtripConfigSecret\(getActiveCtripConfig\(\)\)\s*:\s*null;/);
  assert.match(ctripFetchFlow, /if \(selectedConfig && !isCtripRankingFormAlignedWithConfig/);
  assert.match(html, /只需平台授权/);
});

test('Meituan ranking supports temporary resource ids on the daily fetch panel', () => {
  const rankingPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const meituanFetchFlow = meituanStatic.slice(
    meituanStatic.indexOf('const runMeituanBatchFetchFlow = async ({'),
    meituanStatic.indexOf('const useMeituanDisplayModel')
  );

  assert.match(rankingPanel, /v-model="meituanForm\.partnerId"/);
  assert.match(rankingPanel, /v-model="meituanForm\.poiId"/);
  assert.match(rankingPanel, /临时获取可不先保存配置/);
  assert.match(rankingPanel, /临时获取必填，可不保存/);
  assert.match(rankingPanel, /需一次性门店标识/);
  assert.match(meituanStatic, /需补充一次性门店标识/);
  assert.match(meituanStatic, /请在本页临时填写/);
  assert.doesNotMatch(meituanFetchFlow, /notify\('请选择目标酒店', 'error'\)/);
  assert.doesNotMatch(meituanFetchFlow, /return \{ status: 'missing_hotel' \}/);
  assert.doesNotMatch(meituanFetchFlow, /return \{ status: 'missing_config' \}/);
  assert.match(meituanFetchFlow, /const selectedMeituanConfig = form\.hotelId\s*\?\s*await ensureMeituanConfigSecret\(getSelectedConfig\(\)\)\s*:\s*null;/);
});

test('Meituan ranking keeps rank summary on the second screen like Ctrip', () => {
  const beforeMainTable = sliceFrom('<!-- backend display summary -->', '<!-- 竞对排名表格 -->');
  const firstTable = sliceFrom('<!-- 竞对排名表格 -->', 'data-testid="meituan-rank-summary-second-screen"');
  const secondScreen = sliceFrom('data-testid="meituan-rank-summary-second-screen"', '<!-- 流量数据获取 -->');

  assert.doesNotMatch(beforeMainTable, /meituanVisibleRankInsightCards/);
  assert.doesNotMatch(beforeMainTable, /榜单与来源状态/);
  assert.doesNotMatch(firstTable, /rowspan="2">排名摘要/);
  assert.match(secondScreen, /排名摘要/);
  assert.match(secondScreen, /data-testid="meituan-rank-source-second-screen"/);
  assert.match(secondScreen, /meituanVisibleRankInsightCards/);
  assert.match(secondScreen, /榜单与来源状态/);
  assert.match(secondScreen, /v-for="\(\s*hotel,\s*index\s*\) in pagedMeituanHotelsList"/);
  assert.match(secondScreen, /hotel\.circlePositionText/);
  assert.match(secondScreen, /hotel\.gapToLeaderText/);
  assert.match(secondScreen, /hotel\.rankSummaryText/);
});

test('Meituan ranking money cells use backend source prefixes', () => {
  const rankingTable = sliceFrom('<!-- 竞对排名表格 -->', 'data-testid="meituan-rank-summary-second-screen"');
  const displayPayload = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanDisplayModelPayload = ({'),
    meituanStatic.indexOf('const normalizeMeituanCookieText')
  );
  const fetchTasks = meituanStatic.slice(
    meituanStatic.indexOf('const buildMeituanBatchFetchTasks = ({'),
    meituanStatic.indexOf('const buildMeituanBatchFetchResultEntry')
  );

  assert.doesNotMatch(rankingTable, /'¥'\s*\+\s*hotel\.roomRevenueText/);
  assert.doesNotMatch(rankingTable, /'¥'\s*\+\s*hotel\.salesText/);
  assert.match(rankingTable, /\(hotel\.roomRevenuePrefix \|\| ''\) \+ hotel\.roomRevenueText/);
  assert.match(rankingTable, /\(hotel\.salesPrefix \|\| ''\) \+ hotel\.salesText/);
  assert.match(displayPayload, /self_metric_values:\s*mergeMeituanSelfMetricValues/);
  assert.match(displayPayload, /results\.map\(result => result\.selfMetricValues\)/);
  assert.match(fetchTasks, /include_self_trade_metrics:\s*true/);
});

test('Meituan config saves cookie-only and no longer treats room counts as credentials', () => {
  const saveMeituanConfigItem = functionSlice('saveMeituanConfigItem');

  assert.match(saveMeituanConfigItem, /请输入平台授权内容/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入Partner ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入POI ID/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入酒店房量/);
  assert.doesNotMatch(saveMeituanConfigItem, /请输入竞争圈总房量/);
  assert.match(html, /缺门店标识/);
  assert.match(html, /平台接口标识（Partner ID）/);
  assert.match(html, /平台门店标识（POI ID）/);
  assert.match(html, /detail\?partnerId=4927325 中的 4927325/);
  assert.match(html, /detail\?poiId=570866031 中的 570866031/);
});

test('FontAwesome stylesheet does not block the core shell first second', () => {
  const head = sliceFrom('<head>', '</head>');

  assert.doesNotMatch(head, /<link\s+href=["']font-awesome\.min\.css["']\s+rel=["']stylesheet["']/);
  assert.match(head, /const fontAwesomeStylesheet = 'font-awesome\.min\.css\?v=20260628-static-router-fix';/);
  assert.match(head, /link\.dataset\.suxiFontawesome = '1';/);
  assert.match(head, /window\.setTimeout\(loadFontAwesomeStylesheet, 1600\);/);
  assert.match(head, /document\.addEventListener\('DOMContentLoaded', run, \{ once: true \}\);/);
});

test('Login background preload does not compete with cached-auth shell', () => {
  const head = sliceFrom('<head>', '</head>');
  const preloadOffset = head.indexOf("const loginBackgroundPreload = 'images/login-hotel-lobby-bg.avif';");
  const tailwindOffset = head.indexOf('href="tailwind.min.css?v=20260628-static-router-fix"');

  assert.doesNotMatch(head, /<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']/);
  assert.ok(preloadOffset >= 0 && tailwindOffset >= 0 && preloadOffset < tailwindOffset);
  assert.match(head, /const shouldPreloadLoginBackground = \(\) => \{/);
  assert.match(head, /!localStorage\.getItem\('token'\) \|\| !localStorage\.getItem\('suxios_auth_user_cache_v1'\)/);
  assert.match(head, /link\.setAttribute\('fetchpriority', 'high'\);/);
  assert.match(head, /link\.dataset\.suxiLoginBgPreload = '1';/);
});

test('OTA diagnosis helper does not block the online data shell', () => {
  const head = sliceFrom('<head>', '</head>');
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const generateOtaDiagnosis = sliceFrom('const generateOtaDiagnosis = async () => {', '\n\n            // 加载Agent概览');

  assert.doesNotMatch(head, /<script src="ota-diagnosis-static\.js/);
  assert.match(html, /const otaDiagnosisStaticScript = 'ota-diagnosis-static\.js\?v=20260627-decision-closure-v2';/);
  assert.match(html, /const ensureOtaDiagnosisStaticReady = async \(\) => loadOtaDiagnosisStatic\(\);/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'ota-diagnosis-static', \(\) => new Promise\(resolve => setTimeout\(resolve, 420\)\)\s*\.then\(\(\) => currentPage\.value === 'agent-center' \? ensureOtaDiagnosisStaticReady\(\) : null\)\);/);
  assert.match(generateOtaDiagnosis, /const runOtaDiagnosisGenerateFlow = await getOtaDiagnosisGenerateFlow\(\);/);
  assert.match(html, /const runOtaDiagnosisHotelFetch = async \(selectedHotel, form\) => \{\s*const runOtaDiagnosisHotelFetchFlow = await getOtaDiagnosisHotelFetchFlow\(\);/);
});

test('Home lower dashboard panels mount after the first OTA navigation window', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');

  assert.match(html, /const HOME_SECONDARY_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const COMPASS_WEATHER_REFRESH_DELAY_MS = 3200;/);
  assert.match(html, /const homeSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const scheduleHomeSecondaryPanelsReady = \(delayMs = HOME_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(currentPageWatcher, /clearHomeSecondaryPanelsReadyTimer\(\);\s*homeSecondaryPanelsReady\.value = false;\s*destroyHomeTrendChart\(\);/);
  assert.match(currentPageWatcher, /homeSecondaryPanelsReady\.value = false;\s*scheduleHomeSecondaryPanelsReady\(\);\s*runPageLoadOnce\(newPage, 'main', \(\) => loadCompassData\(\)\);/);
  assert.doesNotMatch(currentPageWatcher, /runPageLoadOnce\(newPage, 'auto-fetch-static'/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="daily-ops-monitor-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="home-weather-demand-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady"[^>]+data-testid="home-market-signal-card"/);
  assert.match(html, /v-if="homeSecondaryPanelsReady && homeTrendCards\.length"/);
});

test('Page-control test ids do not block core page switching', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const isLoggedInWatcher = sliceFrom('watch(isLoggedIn, (loggedIn) => {', '\n\n            // 监听数据记录标签页切换');
  const onlineDataTabWatcher = sliceFrom('watch(onlineDataTab, (newTab) => {', '\n\n            let meituanHotelConfigApplyVersion');
  const scheduleObserverStart = sliceFrom('const schedulePageControlTestIdObserverStart = (delayMs = 520) => {', '\n\n            //');
  const pageControlGate = sliceFrom('const pageControlTestIdsEnabledForShell = () => {', '\n            const loadTestIdStatic');

  assert.match(html, /let pageControlTestIdObserverTimer = null;/);
  assert.match(pageControlGate, /params\.get\('testids'\) === '1'/);
  assert.match(pageControlGate, /params\.get\('e2e'\) === '1'/);
  assert.match(pageControlGate, /localStorage\.getItem\('enablePageTestIds'\) === '1'/);
  assert.doesNotMatch(pageControlGate, /host === 'localhost'/);
  assert.doesNotMatch(pageControlGate, /host === '127\.0\.0\.1'/);
  assert.doesNotMatch(pageControlGate, /host === '::1'/);
  assert.match(scheduleObserverStart, /clearPageControlTestIdObserverTimer\(\);/);
  assert.match(scheduleObserverStart, /const observerDelay = isCoreOtaPageVisible\(\) \? Math\.max\(normalizedDelay, 1800\) : normalizedDelay;/);
  assert.match(scheduleObserverStart, /deferUiTask\(\(\) => \{/);
  assert.match(scheduleObserverStart, /startPageControlTestIdObserver\(\);/);
  assert.match(scheduleObserverStart, /scheduleTestIdRefresh\(\);/);
  assert.match(currentPageWatcher, /schedulePageControlTestIdObserverStart\(520\);/);
  assert.doesNotMatch(currentPageWatcher, /scheduleTestIdRefresh\(\);\s*startPageControlTestIdObserver\(\);/);
  assert.doesNotMatch(currentPageWatcher, /startPageControlTestIdObserver\(\);/);
  assert.match(isLoggedInWatcher, /schedulePageControlTestIdObserverStart\(700\);/);
  assert.doesNotMatch(isLoggedInWatcher, /startPageControlTestIdObserver\(\);/);
  assert.match(onlineDataTabWatcher, /schedulePageControlTestIdObserverStart\(1800\);/);
  assert.match(html, /if \(isLoggedIn\.value\) \{\s*schedulePageControlTestIdObserverStart\(700\);/);
});

test('Public system config refresh does not compete with core OTA switching', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const systemConfigLoader = sliceFrom('const SYSTEM_CONFIG_PUBLIC_CACHE_TTL_MS = 60 * 1000;', '\n\n            //');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.match(systemConfigLoader, /let systemConfigPublicLoadPromise = null;/);
  assert.match(systemConfigLoader, /if \(publicOnly && systemConfigPublicLoadPromise\) \{/);
  assert.match(systemConfigLoader, /systemConfigPublicLoadedAt && Date\.now\(\) - systemConfigPublicLoadedAt < SYSTEM_CONFIG_PUBLIC_CACHE_TTL_MS/);
  assert.match(systemConfigLoader, /const schedulePublicSystemConfigRefresh = \(delayMs = 1800\) => \{/);
  assert.match(systemConfigLoader, /if \(isCoreOtaPageVisible\(\)\) return undefined;/);
  assert.match(currentPageWatcher, /deferUiTask\(\(\) => runPendingPublicSystemConfigRefresh\(\), 600\);/);
  assert.match(loadData, /schedulePublicSystemConfigRefresh\(1800\);/);
  assert.doesNotMatch(loadData, /deferUiTask\(\(\) => loadSystemConfig\(\{ publicOnly: true \}\), 120\)/);
});

test('eBooking startup refreshes are deduplicated during quick page returns', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');

  assert.match(html, /const EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS = 45000;/);
  assert.match(currentPageWatcher, /runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleDelayedPageTask\(\(\) => \{\s*if \(!isCtripEbookingDataHealthVisible\(\)\) return null;\s*scheduleDataHealthPanelRefresh\('light'\);\s*return null;\s*\}, CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS\);\s*scheduleCtripEbookingDeferredStartupRefresh\(\);\s*\}, \{ ttlMs: EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS \}\);/);
  assert.match(currentPageWatcher, /if \(newPage === 'meituan-ebooking'\) \{\s*onlineDataTab\.value = 'meituan-ranking';\s*ensureMeituanManualHotelSelected\(\);\s*runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleMeituanEbookingDeferredStartupRefresh\(\);\s*\}, \{ ttlMs: EBOOKING_STARTUP_REFRESH_CACHE_TTL_MS \}\);/);
});

test('Saved OTA data config reads are short-cached and deduplicated during manual tab switching', () => {
  const savedOtaConfigLoader = sliceFrom('const SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS = 30000;', '\n\n            const readSavedOtaDataConfig = async');
  const readSavedOtaDataConfig = sliceFrom('const readSavedOtaDataConfig = async (type) => {', '\n\n            const isSavedOtaDataConfigUsable');
  const loadSavedDataConfigByType = sliceFrom('const loadSavedDataConfigByType = async (type) => {', '\n\n            const applyCtripCommentManualConfig');
  const saveDataConfig = sliceFrom('const saveDataConfig = async () => {', '\n\n            const testDataConfig');

  assert.match(savedOtaConfigLoader, /const savedOtaDataConfigCache = new Map\(\);/);
  assert.match(savedOtaConfigLoader, /const savedOtaDataConfigLoadingPromises = new Map\(\);/);
  assert.match(savedOtaConfigLoader, /const getSavedOtaDataConfigKey = \(type\) => `data_config_\$\{String\(type \|\| ''\)\.replace\('-', '_'\)\}`;/);
  assert.match(savedOtaConfigLoader, /const readSavedOtaDataConfigFromSystem = async \(type\) => \{/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigCache\.get\(configKey\)/);
  assert.match(savedOtaConfigLoader, /cached && cached\.expiresAt > Date\.now\(\)/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigLoadingPromises\.has\(configKey\)/);
  assert.match(savedOtaConfigLoader, /request\(`\/system-config\?key=\$\{configKey\}`\)/);
  assert.match(savedOtaConfigLoader, /savedOtaDataConfigCache\.set\(configKey, \{/);
  assert.match(savedOtaConfigLoader, /expiresAt: Date\.now\(\) \+ SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS/);
  assert.match(readSavedOtaDataConfig, /return await readSavedOtaDataConfigFromSystem\(type\) \|\| \{\};/);
  assert.match(loadSavedDataConfigByType, /return await readSavedOtaDataConfigFromSystem\(type\);/);
  assert.match(saveDataConfig, /clearSavedOtaDataConfigCache\(currentDataConfigType\.value\);/);
});

test('Ctrip profile field config tab reuses recent list and sample reads', () => {
  const profileFieldCache = sliceFrom('const CTRIP_PROFILE_FIELDS_TAB_CACHE_TTL_MS = 30000;', '\n\n            const loadCtripProfileFieldSamples');
  const loadSamples = sliceFrom('const loadCtripProfileFieldSamples = async (requestSeq, options = {}) => {', '\n\n            const loadCtripProfileFields');
  const loadFields = sliceFrom('const loadCtripProfileFields = async (options = {}) => {', '\n\n            const openCtripProfileFieldsForReview');
  const onlineDataTabScheduler = sliceFrom('const scheduleOnlineDataTabLoad = (newTab, options = {}) => {', '\n            const openOnlineDataTab');
  const saveModule = sliceFrom('const saveCtripProfileModule = async () => {', '\n\n            const deleteCtripProfileModule');
  const saveField = sliceFrom('const saveCtripProfileField = async () => {', '\n\n            const toggleCtripProfileFieldEnabled');

  assert.match(profileFieldCache, /const ctripProfileFieldResultCache = new Map\(\);/);
  assert.match(profileFieldCache, /const ctripProfileFieldRequestPromises = new Map\(\);/);
  assert.match(profileFieldCache, /const ctripProfileFieldCacheKey = \(includeSamples\) => includeSamples \? 'include-samples' : 'list-only';/);
  assert.match(profileFieldCache, /const clearCtripProfileFieldCache = \(\) => \{/);
  assert.match(profileFieldCache, /ctripProfileFieldResultCache\.clear\(\);/);
  assert.match(profileFieldCache, /const requestCtripProfileFields = async \(includeSamples, options = \{\}\) => \{/);
  assert.match(profileFieldCache, /const cached = readCtripProfileFieldCache\(key\);/);
  assert.match(profileFieldCache, /return \{ code: 200, data: cached, from_cache: true \};/);
  assert.match(profileFieldCache, /if \(ctripProfileFieldRequestPromises\.has\(key\)\) \{/);
  assert.match(profileFieldCache, /request\(`\/online-data\/ctrip-profile-fields\?include_samples=\$\{includeSamples \? 1 : 0\}`\)/);
  assert.match(profileFieldCache, /writeCtripProfileFieldCache\(key, res\.data \|\| \{\}\);/);
  assert.match(loadSamples, /requestCtripProfileFields\(true, \{ force: options\.force === true \}\)/);
  assert.match(loadFields, /const force = options\.force === true;/);
  assert.match(loadFields, /requestCtripProfileFields\(false, \{ force \}\)/);
  assert.match(loadFields, /loadCtripProfileFieldSamples\(requestSeq, \{ force \}\)/);
  assert.match(onlineDataTabScheduler, /void ensureCtripProfileFieldConfigPanelReady\(\)\.catch/);
  assert.match(onlineDataTabScheduler, /return runIfCurrent\(\(\) => loadCtripProfileFields\(options\)\);/);
  assert.match(saveModule, /clearCtripProfileFieldCache\(\);\s*await loadCtripProfileFields\(\{ force: true \}\);/);
  assert.match(saveField, /clearCtripProfileFieldCache\(\);\s*await loadCtripProfileFields\(\{ force: true \}\);/);
  assert.match(html, /clearCtripProfileFieldCache\(\);\s*mergeCtripProfileFieldUpdate\(res\.data \|\| \{\}\);/);
  assert.match(html, /const CtripProfileFieldConfigPanel = \{\s*name: 'CtripProfileFieldConfigPanel'/);
  assert.match(html, /const ensureCtripProfileFieldConfigPanelReady = async \(\) => \{/);
  assert.match(html, /requireOnlineDataComponent\('CtripProfileFieldConfigPanelBody'\)/);
  assert.match(html, /void ensureCtripProfileFieldConfigPanelReady\(\)\.catch/);
  assert.match(html, /<ctrip-profile-field-config-panel\s+v-if="onlineDataTab === 'profile-fields' && user\?\.is_super_admin"\s+:ctx="\$root">/);
  assert.match(html, /data-testid="ctrip-profile-field-config-loading"/);
  assert.match(ctripProfileFieldConfigPanel, /components\.CtripProfileFieldConfigPanelBody/);
  assert.match(ctripProfileFieldConfigPanel, /data-testid=\\?"ctrip-profile-field-config-panel\\?"/);
  assert.match(ctripProfileFieldConfigPanel, /return new Proxy\(\{\}, \{/);
  assert.match(ctripProfileFieldConfigPanel, /return props\.ctx\?\.\[key\] \?\? target\[key\];/);
  assert.match(ctripProfileFieldConfigPanel, /props\.ctx\[key\] = value;/);
  assert.match(ctripProfileFieldConfigPanel, /getOwnPropertyDescriptor\(\) \{/);
  assert.doesNotMatch(html, /携程登录会话字段配置/);
});

test('Form operation support loads after login instead of blocking the login shell', () => {
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n\n            watch(isLoggedIn');
  const onlineDataTabWatcher = sliceFrom('watch(onlineDataTab, (newTab) => {', '\n\n            let meituanHotelConfigApplyVersion');
  const formOperationLoader = sliceFrom("const formOperationSupportScript = 'form-operation-support.js';", '\n            const clearAuthSession');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.doesNotMatch(html, /<script\s+src=["']form-operation-support\.js["']/);
  assert.match(formOperationLoader, /script\.src = formOperationSupportScript;/);
  assert.match(formOperationLoader, /window\.SuxiFormOperationSupport\.init\(window\);/);
  assert.match(formOperationLoader, /const shouldDeferFormOperationSupportLoad = \(\) => currentPage\.value === 'compass' \|\| isCoreOtaPageVisible\(\);/);
  assert.match(formOperationLoader, /const pageDelay = shouldDeferFormOperationSupportLoad\(\) \? 6400 : 5200;/);
  assert.match(formOperationLoader, /if \(shouldDeferFormOperationSupportLoad\(\)\) return;/);
  assert.match(currentPageWatcher, /scheduleFormOperationSupportLoad\(\);/);
  assert.match(onlineDataTabWatcher, /scheduleFormOperationSupportLoad\(\);/);
  assert.match(loadData, /scheduleFormOperationSupportLoad\(\);/);
});

test('Meituan hotel matching does not wait for all-store competitor summaries', () => {
  const loadCompetitorSummary = sliceFrom('const loadCompetitorSummary = async (options = {}) => {', '\n            const loadCompassData');
  const scheduleMeituanRankingSummaryRefresh = sliceFrom('const scheduleMeituanRankingSummaryRefresh = (options = {}) => {', '\n\n            // 线上数据获取相关方法');
  const applyMeituanHotelConfig = sliceFrom('const applyMeituanHotelConfig = async (showMessage = true, options = {}) => {', '\n            const syncMeituanTrafficConfigFromSelectedConfig');
  const loadMeituanConfigDetail = sliceFrom('const meituanConfigDetailCache = new Map();', '\n            const applyCtripConfigObject');
  const loadMeituanConfigList = sliceFrom('const loadMeituanConfigList = async (options = {}) => {', '\n            const saveMeituanConfigItem');
  const findMeituanConfigByHotelId = sliceFrom('const findMeituanConfigByHotelId = (hotelId) => {', '\n\n            const selectedCtripHotelConfig');
  const openHomeQuickEntry = sliceFrom('const openHomeQuickEntry = (entry) => {', '\n\n            // 竞对价格监控');
  const meituanHotelSelectPanel = sliceFrom('<div v-if="onlineDataTab === \'meituan-ranking\'">', '<!-- 获取结果显示 -->');
  const meituanHotelWatcher = sliceFrom('watch(() => meituanForm.value.hotelId, () => {', '\n\n            watch(competitorTab');
  const currentPageWatcher = sliceFrom('watch(currentPage, (newPage) => {', '\n            const handleMenuClick');
  const handleMenuClick = sliceFrom('const handleMenuClick = (item) => {', '\n\n            const isStillOnRequestPage');
  const scheduleMeituanEbookingDeferredStartupRefresh = sliceFrom('const scheduleMeituanEbookingDeferredStartupRefresh = () => {', '\n            const scheduleDefaultDashboardDeferredRefresh');
  const openMeituanManualTab = sliceFrom('const openMeituanManualTab = (tab) => {', '\n            let dataLoadTimer');
  const fetchMeituanData = sliceFrom('const fetchMeituanData = async () => {', '\n\n            const useCtripTrafficDisplayRows');
  const meituanManualFetchConfigGuard = sliceFrom('const meituanManualFetchConfigProofPending = () => {', '\n\n            let manualOnlineFetchConfigReadyPromise');
  const resolveMeituanManualDefaultHotelId = sliceFrom('const resolveMeituanManualDefaultHotelId = () => {', '\n            const ensureMeituanManualHotelSelected');
  const ensureMeituanManualHotelSelected = sliceFrom('const ensureMeituanManualHotelSelected = () => {', '\n            const scheduleMeituanEbookingDeferredStartupRefresh');

  assert.match(loadCompetitorSummary, /const isMeituanRankingPage = currentPage\.value === 'meituan-ebooking' && onlineDataTab\.value === 'meituan-ranking';/);
  assert.match(loadCompetitorSummary, /includeByHotel = options\.includeByHotel === true;/);
  assert.match(loadCompetitorSummary, /if \(includeByHotel\) params\.append\('include_by_hotel', '1'\);/);
  assert.match(loadCompetitorSummary, /const cacheMs = force \? 0 : Number\(options\.cacheMs \|\| 0\);/);
  assert.match(loadCompetitorSummary, /readRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\)/);
  assert.match(loadCompetitorSummary, /competitorSummaryRequestPromises\.has\(requestKey\)/);
  assert.match(loadCompetitorSummary, /writeRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\);/);
  assert.match(loadCompetitorSummary, /if \(requestSeq !== competitorSummaryRequestSeq\) return;/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /scheduleDelayedPageTask\(async \(\) => \{/);
  assert.match(scheduleMeituanRankingSummaryRefresh, /await loadCompetitorSummary\(\{ includeByHotel: false \}\);/);
  assert.doesNotMatch(openHomeQuickEntry, /await loadCompetitorSummary\(\)/);
  assert.match(openHomeQuickEntry, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.match(findMeituanConfigByHotelId, /const idMatched = meituanConfigList\.value\.find/);
  assert.match(findMeituanConfigByHotelId, /normalizeOtaConfigHotelName\(getHotelNameById\(hotelId\)\)/);
  assert.match(findMeituanConfigByHotelId, /configHotelName === hotelName \|\| configName === hotelName/);
  assert.doesNotMatch(meituanHotelSelectPanel, /meituanConfigListLoading && !selectedMeituanHotelConfig/);
  assert.doesNotMatch(meituanHotelSelectPanel, /正在匹配美团数据源/);
  assert.doesNotMatch(meituanHotelSelectPanel, /配置待读取，正在准备美团数据源匹配/);
  assert.doesNotMatch(meituanHotelSelectPanel, /!meituanConfigListLoading && !meituanConfigListLoaded && !meituanConfigListLoadFailed && !selectedMeituanHotelConfig/);
  assert.match(meituanHotelSelectPanel, /:disabled="fetchingData \|\| !canFetchMeituanRankingData\(\)"/);
  assert.match(fetchMeituanData, /const preparingConfig = meituanManualFetchConfigProofPending\(\);/);
  assert.match(fetchMeituanData, /ensureMeituanConfigSecret: async config => ensureMeituanConfigSecret\(await resolveMeituanManualFetchConfig\(config\)\)/);
  assert.match(fetchMeituanData, /finally \{\s*if \(preparingConfig\) \{\s*fetchingData\.value = false;\s*\}\s*\}/);
  assert.match(meituanManualFetchConfigGuard, /return !!meituanForm\.value\.hotelId && !!selectedMeituanHotelConfig\.value;/);
  assert.match(meituanManualFetchConfigGuard, /const canFetchMeituanRankingData = \(\) => \{/);
  assert.match(meituanManualFetchConfigGuard, /if \(String\(form\.cookies \|\| ''\)\.trim\(\)\) return true;/);
  assert.match(meituanManualFetchConfigGuard, /return !!form\.hotelId && !!selectedMeituanHotelConfig\.value;/);
  assert.doesNotMatch(meituanManualFetchConfigGuard, /await loadMeituanConfigList\(/);
  assert.match(meituanManualFetchConfigGuard, /if \(!meituanForm\.value\.hotelId\) return null;/);
  assert.match(meituanManualFetchConfigGuard, /return selectedMeituanHotelConfig\.value;/);
  assert.doesNotMatch(meituanHotelSelectPanel, /@change="applyMeituanHotelConfig/);
  assert.match(meituanHotelWatcher, /if \(onlineDataTab\.value === 'meituan-ranking'\) \{/);
  assert.match(meituanHotelWatcher, /scheduleMeituanHotelConfigApply\(\{ delayMs: 0 \}\);/);
  assert.match(html, /let meituanHotelConfigApplyVersion = 0;/);
  assert.match(html, /requestedHotelId !== String\(meituanForm\.value\.hotelId \|\| ''\)/);
  assert.doesNotMatch(handleMenuClick, /await loadCompetitorSummary\(\)/);
  assert.match(handleMenuClick, /scheduleMeituanRankingSummaryRefresh\(\)/);
  assert.match(currentPageWatcher, /scheduleMeituanEbookingDeferredStartupRefresh\(\);/);
  assert.match(currentPageWatcher, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(html, /const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS = 16;/);
  assert.match(html, /const MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS = 5200;/);
  assert.match(html, /const MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS = 6400;/);
  assert.match(html, /let suppressNextMeituanHotelConfigApply = false;/);
  assert.match(resolveMeituanManualDefaultHotelId, /autoFetchHotelId\.value/);
  assert.match(resolveMeituanManualDefaultHotelId, /selectedCtripHotelId\.value/);
  assert.match(resolveMeituanManualDefaultHotelId, /onlineDataFilter\.value\.hotel_id/);
  assert.match(resolveMeituanManualDefaultHotelId, /user\.value\?\.hotel_id/);
  assert.match(resolveMeituanManualDefaultHotelId, /hotelPool\?\.\[0\]\?\.id/);
  assert.match(ensureMeituanManualHotelSelected, /suppressNextMeituanHotelConfigApply = true;/);
  assert.match(ensureMeituanManualHotelSelected, /meituanForm\.value\.hotelId = hotelId;/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /applySelectedConfig: false/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /scheduleMeituanHotelConfigApply\(\{\s*delayMs: 0,\s*refreshList: false,\s*skipIfAligned: true,\s*\}\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /return null;\s*\}, 0\);\s*scheduleDelayedPageTask\(\(\) => \{/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /return loadMeituanConfig\(\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(scheduleMeituanEbookingDeferredStartupRefresh, /}, MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /}, 2400\);/);
  assert.doesNotMatch(scheduleMeituanEbookingDeferredStartupRefresh, /}, 3000\);/);
  assert.doesNotMatch(applyMeituanHotelConfig, /await loadCompetitorSummary\(\)/);
  assert.match(applyMeituanHotelConfig, /const requestedHotelId = String\(meituanForm\.value\.hotelId \|\| ''\);/);
  assert.match(applyMeituanHotelConfig, /if \(requestedHotelId !== String\(meituanForm\.value\.hotelId \|\| ''\)\) return;/);
  assert.doesNotMatch(applyMeituanHotelConfig, /options\.refreshList !== false/);
  assert.doesNotMatch(applyMeituanHotelConfig, /await loadMeituanConfigList\(/);
  assert.doesNotMatch(applyMeituanHotelConfig, /applySelectedConfig: false/);
  assert.match(applyMeituanHotelConfig, /options\.skipIfAligned === true && config && isMeituanRankingFormAlignedWithConfig\(meituanForm\.value, config\)/);
  assert.doesNotMatch(applyMeituanHotelConfig, /scheduleMeituanRankingSummaryRefresh/);
  assert.match(openMeituanManualTab, /applySelectedConfig: false/);
  assert.match(openMeituanManualTab, /skipIfAligned: true/);
  assert.match(openMeituanManualTab, /ensureMeituanManualHotelSelected\(\);/);
  assert.match(meituanHotelWatcher, /if \(suppressNextMeituanHotelConfigApply\) \{[\s\S]*suppressNextMeituanHotelConfigApply = false;[\s\S]*return;/);
  assert.match(loadMeituanConfigDetail, /const meituanConfigDetailCache = new Map\(\);/);
  assert.match(loadMeituanConfigDetail, /const clearMeituanConfigDetailCache = \(id = ''\) => \{/);
  assert.match(loadMeituanConfigDetail, /if \(meituanConfigDetailLoadingPromises\.has\(cacheKey\)\) \{/);
  assert.match(loadMeituanConfigDetail, /return meituanConfigDetailLoadingPromises\.get\(cacheKey\);/);
  assert.match(loadMeituanConfigDetail, /meituanConfigDetailCache\.set\(cacheKey, \{/);
  assert.match(loadMeituanConfigDetail, /if \(config\.cookies \|\| !config\.id \|\| config\.has_cookies === false\) return config;/);
  assert.match(html, /clearMeituanConfigDetailCache\(meituanConfigForm\.value\.id\);/);
  assert.match(html, /clearMeituanConfigDetailCache\(id\);/);
  assert.match(loadMeituanConfigList, /if \(meituanConfigListLoadingPromise\) \{/);
  assert.match(loadMeituanConfigList, /return meituanConfigListLoadingPromise;/);
  assert.match(html, /const meituanConfigListLoading = ref\(false\);/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoading\.value = true;/);
  assert.match(loadMeituanConfigList, /meituanConfigListLoading\.value = false;/);
  assert.match(loadMeituanConfigList, /const shouldApplySelectedConfig = options\.applySelectedConfig === true;/);
  assert.match(loadMeituanConfigList, /if \(meituanForm\.value\.hotelId && shouldApplySelectedConfig\) \{/);
  assert.match(loadMeituanConfigList, /deferUiTask\(\(\) => applyMeituanHotelConfig\(false, \{ refreshList: false \}\), 80\);/);
});

test('Ctrip manual startup keeps config list responsive without first-paint blocking', () => {
  const scheduleCtripEbookingDeferredStartupRefresh = sliceFrom(
    'const scheduleCtripEbookingDeferredStartupRefresh = () => {',
    '\n            const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS'
  );
  const ctripEbookingDefaultLoader = sliceFrom(
    "if (newPage === 'ctrip-ebooking') {",
    "\n                if (newPage === 'meituan-ebooking')"
  );
  const ctripSecondaryScheduler = sliceFrom(
    'const clearCtripEbookingSecondaryPanelsReadyTimer = () => {',
    '\n            const shouldRefreshAutoFetchStatusPanel'
  );

  assert.match(html, /const CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS = 1600;/);
  assert.match(html, /const CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS = 2600;/);
  assert.match(html, /const CTRIP_EBOOKING_LATEST_DATA_DELAY_MS = 5200;/);
  assert.match(html, /const CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS = 6400;/);
  assert.match(html, /const CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS = 7600;/);
  assert.match(html, /const CTRIP_EBOOKING_MODULE_CARD_DELAY_MS = 1000;/);
  assert.match(html, /const ctripEbookingModuleCardsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const ctripEbookingSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS = 6200;/);
  assert.match(html, /const ctripEbookingDeepPanelsReady = ref\(false\);/);
  assert.match(html, /const CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS = 8200;/);
  assert.match(html, /const ctripEbookingBusinessDetailsReady = ref\(false\);/);
  assert.match(html, /const ctripEbookingDiagnosticsPanelsReady = ref\(false\);/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingModuleCardsReady = \(delayMs = CTRIP_EBOOKING_MODULE_CARD_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingSecondaryPanelsReady = \(delayMs = CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingDeepPanelsReady = \(delayMs = CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const scheduleCtripEbookingBusinessDetailsReady = \(delayMs = CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS\) => \{/);
  assert.match(ctripSecondaryScheduler, /const handleCtripEbookingDiagnosticsToggle = \(event\) => \{\s*if \(event\?\.target\?\.open\) \{\s*ctripEbookingDiagnosticsPanelsReady\.value = true;/);
  assert.match(ctripSecondaryScheduler, /currentPage\.value === 'ctrip-ebooking' && onlineDataTab\.value === 'data-health'/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingModuleCardsReady\.value = false;\s*scheduleCtripEbookingModuleCardsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingSecondaryPanelsReady\.value = false;\s*scheduleCtripEbookingSecondaryPanelsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingDeepPanelsReady\.value = false;\s*scheduleCtripEbookingDeepPanelsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingBusinessDetailsReady\.value = false;\s*scheduleCtripEbookingBusinessDetailsReady\(\);/);
  assert.match(ctripEbookingDefaultLoader, /ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(html, /<div v-if="ctripEbookingModuleCardsReady" class="px-4 py-3 border-b bg-gray-50 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-2">/);
  assert.match(html, /v-if="ctripEbookingModuleCardsReady" data-testid="ctrip-overview-module-cards" class="p-4"/);
  assert.match(html, /v-if="ctripEbookingSecondaryPanelsReady" class="space-y-4"/);
  assert.match(html, /v-if="ctripEbookingDeepPanelsReady" class="space-y-4"/);
  assert.match(html, /v-if="ctripEbookingBusinessDetailsReady" data-testid="ctrip-store-overview-business-details" class="space-y-4"/);
  assert.match(html, /data-testid="ctrip-store-overview-diagnostics"[^>]+@toggle="handleCtripEbookingDiagnosticsToggle"/);
  assert.match(html, /v-if="ctripEbookingDiagnosticsPanelsReady" class="p-4 border-t space-y-4"/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /scheduleCtripHotelConfigApply\(null, \{\s*refreshList: false,\s*skipIfAligned: true,\s*\}\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /if \(currentPage\.value !== 'ctrip-ebooking'\) return null;/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_LATEST_DATA_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS\);/);
  assert.match(scheduleCtripEbookingDeferredStartupRefresh, /}, CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 1800\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 2400\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 3000\);/);
  assert.doesNotMatch(scheduleCtripEbookingDeferredStartupRefresh, /}, 3600\);/);
});

test('Meituan orders and ads remain network-required workflows', () => {
  const fetchMeituanOrdersData = constSlice('const fetchMeituanOrdersData = async () => runMeituanOrderFetchFlow({');
  const fetchMeituanAdsData = constSlice('const fetchMeituanAdsData = async () => runMeituanAdsFetchFlow({');
  assert.match(meituanStatic, /需 Network 请求信息/);
  assert.match(meituanStatic, /请填写订单接口 Request URL/);
  assert.match(meituanStatic, /请填写广告接口 Request URL/);
  assert.match(fetchMeituanOrdersData, /runMeituanOrderFetchFlow\(\{/);
  assert.match(fetchMeituanAdsData, /runMeituanAdsFetchFlow\(\{/);
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
  assert.match(html, /requireCtripStatic\('runCtripAdsFetchFlow'\)/);
  assert.match(ctripStatic, /const defaultCtripAdsEffectReportUrl =/);
  assert.match(ctripStatic, /const normalizeCtripAdsApiType = \(value = ''\) =>/);
  assert.match(ctripStatic, /const buildCtripAdsFetchRequestBody = \(\{/);
  assert.match(ctripStatic, /const url = String\(form\.url \|\| defaultAdsUrl\)\.trim\(\);/);
  assert.match(ctripStatic, /const requestBody = buildCtripAdsFetchRequestBody\(\{/);
  assert.doesNotMatch(html, /const ctripAdsFetchBody = buildCtripAdsFetchRequestBody\(\{/);
  assert.doesNotMatch(html, /api_type: normalizeCtripAdsApiType\(form\.apiType\)/);
});

test('Platform auto-fetch panel prewarms static helper without blocking first paint', () => {
  const loadAutoFetchPanel = sliceFrom(
    'const loadAutoFetchPanel = async (options = {}) => {',
    '\n            const autoFetchStatusRequestPromises'
  );
  const autoFetchPanelArea = sliceFrom(
    'const AUTO_FETCH_PANEL_CACHE_TTL_MS = 45000;',
    '\n            const autoFetchStatusRequestPromises'
  );
  const triggerAutoFetch = sliceFrom(
    'const triggerAutoFetch = async () => {',
    '\n\n            const retryAutoFetchDate'
  );
  const autoFetchTriggerGuard = sliceFrom(
    'const autoFetchConfigProofPendingForHotelId = (hotelId) => {',
    '\n\n            const ensureHotelOtaConfigLists'
  );
  const loadHotels = sliceFrom('const HOTEL_LIST_CACHE_TTL_MS = 30000;', '\n\n            const getHotelNameById');
  const loadData = sliceFrom('const loadData = async () => {', '\n\n            //');

  assert.doesNotMatch(loadAutoFetchPanel, /const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/);
  assert.match(html, /const prewarmAutoFetchStaticForPlatformAuto = \(\) => \{/);
  assert.match(html, /if \(!isVisibleOnlineDataTab\('platform-auto'\)\) return null;/);
  assert.match(html, /const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/);
  assert.match(html, /void staticReadyPromise;/);
  assert.match(autoFetchPanelArea, /const PLATFORM_AUTO_PANEL_START_DELAY_MS = 16;/);
  assert.match(html, /const AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS = AUTO_FETCH_PANEL_CACHE_TTL_MS;/);
  assert.match(html, /const PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS = 800;/);
  assert.match(html, /const platformAutoSettingsPanelsReady = ref\(false\);/);
  assert.match(html, /const platformAutoSettingsPanelsBody = shallowRef\(null\);/);
  assert.match(html, /const platformAutoPanelsScript = 'components\/online-data\/platform-auto-settings-panels\.js\?v=20260613-platform-auto-lazy';/);
  assert.match(html, /const ensurePlatformAutoPanelsReady = async \(\) => \{/);
  assert.match(html, /requireOnlineDataComponent\('PlatformAutoSettingsPanelsBody'\)/);
  assert.match(html, /requireOnlineDataComponent\('PlatformAutoSecondaryPanelsBody'\)/);
  assert.doesNotMatch(html, /<script src="components\/online-data\/platform-auto-settings-panels\.js/);
  assert.match(html, /<platform-auto-settings-panels\s+v-if="platformAutoSettingsPanelsReady"\s+:ctx="\$root">/);
  assert.ok(
    html.indexOf('@click="triggerAutoFetch"') < html.indexOf('<platform-auto-settings-panels'),
    'platform-auto immediate collect button must stay above delayed settings panels'
  );
  assert.match(html, /data-testid="platform-auto-settings-panels-loading"/);
  assert.match(platformAutoSettingsPanels, /components\.PlatformAutoSettingsPanelsBody/);
  assert.match(platformAutoSettingsPanels, /data-testid="platform-auto-settings-panels"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchRealtimeIntervalHours"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchScheduleMinute"/);
  assert.match(platformAutoSettingsPanels, /v-model="ctx\.autoFetchBrowserHeadless"/);
  assert.match(platformAutoSettingsPanels, /v-model\.number="ctx\.autoFetchCtripSectionConcurrency"/);
  assert.doesNotMatch(html, /实时采集间隔（小时）/);
  assert.match(html, /const PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS = 2600;/);
  assert.match(html, /const platformAutoSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const platformAutoSecondaryPanelsBody = shallowRef\(null\);/);
  assert.match(html, /void ensurePlatformAutoPanelsReady\(\)\.catch/);
  assert.match(html, /prewarmAutoFetchStaticForPlatformAuto\(\);/);
  assert.match(html, /platformAutoSettingsPanelsReady\.value = false;\s*schedulePlatformAutoSettingsPanelsReady\(\);/);
  assert.match(html, /<platform-auto-secondary-panels\s+v-if="platformAutoSecondaryPanelsReady"\s+:ctx="\$root">/);
  assert.match(html, /data-testid="platform-auto-secondary-panels-loading"/);
  assert.match(platformAutoSettingsPanels, /components\.PlatformAutoSecondaryPanelsBody/);
  assert.match(platformAutoSettingsPanels, /data-testid="platform-auto-secondary-panels"/);
  assert.match(platformAutoSettingsPanels, /ctx\.autoFetchCollectionBlueprintRows/);
  assert.match(platformAutoSettingsPanels, /ctx\.meituanPlatformProfileStatusRow/);
  assert.match(platformAutoSettingsPanels, /ctx\.autoFetchPlatformResultRows/);
  assert.doesNotMatch(html, /采集闭环/);
  assert.match(html, /platformAutoSecondaryPanelsReady\.value = false;\s*schedulePlatformAutoSecondaryPanelsReady\(\);\s*return runIfCurrent\(\(\) => schedulePlatformAutoFetchPanelLoad\(options\)\);/);
  assert.match(autoFetchPanelArea, /const waitForPlatformAutoPanelStart = async \(options = \{\}\) => \{/);
  assert.match(loadAutoFetchPanel, /if \(!await waitForPlatformAutoPanelStart\(options\)\) \{\s*return;\s*\}/);
  assert.match(loadAutoFetchPanel, /const defaultAutoFetchHotelId = getAutoFetchHotelId\(\);\s*if \(!autoFetchHotelId\.value && defaultAutoFetchHotelId\) \{\s*autoFetchHotelId\.value = defaultAutoFetchHotelId;\s*\}/);
  assert.match(loadAutoFetchPanel, /let panelLoaded = false;/);
  assert.match(loadAutoFetchPanel, /const hotelsPromise = shouldLoadHotels \? loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\) : Promise\.resolve\(\);/);
  assert.match(
    loadAutoFetchPanel,
    /await Promise\.all\(\[\s*loadAutoFetchStatus\(\{ detail: false \}\),\s*hotelsPromise,\s*\]\);/
  );
  assert.match(loadAutoFetchPanel, /await loadAutoFetchStatus\(\{ detail: false \}\);/);
  assert.match(loadAutoFetchPanel, /if \(panelLoaded\) \{\s*autoFetchPanelCache = \{/);
  assert.match(loadAutoFetchPanel, /else if \(autoFetchPanelCache\.promise === run\) \{\s*autoFetchPanelCache = \{ key: '', expiresAt: 0, promise: null \};\s*\}/);
  assert.doesNotMatch(
    loadAutoFetchPanel,
    /loadAutoFetchStatus\(\{ detail: false \}\),\s*staticReadyPromise/
  );
  assert.doesNotMatch(loadAutoFetchPanel, /staticReadyPromise,\s*hotelsPromise/);
  assert.doesNotMatch(html, /\b(?:fab|far)\s+fa-/);
  assert.match(loadHotels, /const hotelListResultCache = new Map\(\);/);
  assert.match(loadHotels, /const cacheMs = Number\(options\.cacheMs \|\| 0\);/);
  assert.match(loadHotels, /readRequestCache\(hotelListResultCache, requestKey, cacheMs\)/);
  assert.match(loadHotels, /writeRequestCache\(hotelListResultCache, requestKey, cacheMs\);/);
  assert.match(loadHotels, /const scheduleStartupHotelListLoad = \(delayMs = null\) => \{/);
  assert.match(loadHotels, /if \(!hasKnownHotelOptions\(\)\) \{/);
  assert.match(loadHotels, /return loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(loadHotels, /if \(!isLoggedIn\.value \|\| !token\.value \|\| isCoreOtaPageVisible\(\)\) return null;/);
  assert.match(loadData, /scheduleStartupHotelListLoad\(\);/);
  assert.doesNotMatch(loadData, /loadHotels\(\{ cacheMs: HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(triggerAutoFetch, /await ensureAutoFetchStaticReady\(\);/);
  assert.match(triggerAutoFetch, /requireAutoFetchStatic\('runAutoFetchTriggerFlow'\)/);
  assert.match(triggerAutoFetch, /hasPlatformFetchConfig: canTriggerAutoFetchByHotelId,/);
  assert.match(autoFetchTriggerGuard, /autoFetchStatusRequestPromises\.has\(`\$\{keyPrefix\}light`\)/);
  assert.match(autoFetchTriggerGuard, /autoFetchStatusRequestPromises\.has\(`\$\{keyPrefix\}full`\)/);
  assert.match(autoFetchTriggerGuard, /const canTriggerAutoFetchByHotelId = \(hotelId\) => \{/);
  assert.match(autoFetchTriggerGuard, /hasAnyPlatformFetchConfigByHotelId\(hotelId\) \|\| autoFetchConfigProofPendingForHotelId\(hotelId\)/);
  assert.equal((html.match(/:disabled="fetchingData \|\| !canTriggerAutoFetchByHotelId\(autoFetchHotelId\)"/g) || []).length, 2);
});

test('Platform source panel staggers secondary sync and log reads', () => {
  const loadPlatformDataSourcePanel = sliceFrom(
    'const loadPlatformDataSourcePanel = async (options = {}) => {',
    '\n            const savePlatformDataSource = async'
  );
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );

  assert.match(html, /const PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS = 3200;/);
  assert.match(html, /const PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS = 1200;/);
  assert.match(html, /const PLATFORM_SOURCE_PANEL_CACHE_TTL_MS = 30000;/);
  assert.match(html, /const platformSourceGuidePanelsReady = ref\(false\);/);
  assert.match(html, /const schedulePlatformSourceGuidePanelsReady = \(delayMs = PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS\) => \{/);
  assert.match(html, /v-if="platformSourceGuidePanelsReady" data-testid="platform-account-binding-guide"/);
  assert.match(html, /v-if="platformSourceGuidePanelsReady" data-testid="platform-batch-health-check"/);
  assert.match(html, /const competitorSummaryRequestPromises = new Map\(\);/);
  assert.match(html, /const competitorSummaryResultCache = new Map\(\);/);
  assert.match(scheduleOnlineDataTabLoad, /if \(newTab === 'platform-sources'\) \{\s*platformSourceGuidePanelsReady\.value = false;\s*schedulePlatformSourceGuidePanelsReady\(\);/);
  assert.match(loadPlatformDataSourcePanel, /await Promise\.allSettled\(\[\s*loadPlatformDataSources\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformProfileStatus\(\{\s*silent: true,\s*cacheMs: options\.force \? 0 : PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS,/);
  assert.match(loadPlatformDataSourcePanel, /scheduleDelayedPageTask\(\(\) => \{\s*if \(!shouldRefreshPlatformDataSourcesPanel\(\)\) return null;\s*return Promise\.allSettled\(\[/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformSyncTasks\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformSyncLogs\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadPlatformCollectionResources\(\{/);
  assert.match(loadPlatformDataSourcePanel, /loadCompetitorSummary\(\{\s*includeByHotel: true,\s*force: options\.force === true,\s*cacheMs: options\.force \? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,\s*\}\)/);
  assert.match(loadPlatformDataSourcePanel, /\}, PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS\);/);
  assert.match(html, /platformDataSourceHotelOptions, platformSourceGuidePanelsReady, loadPlatformDataSourcePanel/);
  assert.doesNotMatch(loadPlatformDataSourcePanel, /deferUiTask\(\(\) => \{\s*if \(!shouldRefreshPlatformDataSourcesPanel\(\)\) return null;\s*return Promise\.allSettled\(\[\s*loadPlatformSyncTasks\(\{/);
});

test('Online data health tab schedules light refresh outside the switch path', () => {
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );
  const openCtripManualTab = sliceFrom(
    'const openCtripManualTab = (tab) => {',
    '\n            const openMeituanManualTab'
  );
  const goAiDailyReportDataGap = sliceFrom(
    'const goAiDailyReportDataGap = async (gap) => {',
    '\n            const operationExecutionItems'
  );
  const onlineDataDefaultLoader = sliceFrom(
    "if (newPage === 'online-data' && token.value) {",
    "\n                if (newPage === 'operation-logs')"
  );
  const openOnlineDataEntryTab = sliceFrom(
    "const openOnlineDataEntryTab = (tab = 'data-health', options = {}) => {",
    '\n            const openOnlineDataManualEntry'
  );
  const dataHealthSecondaryScheduler = sliceFrom(
    'const clearDataHealthSecondaryPanelsReadyTimer = () => {',
    '\n            const shouldRefreshAutoFetchStatusPanel'
  );

  assert.match(
    scheduleOnlineDataTabLoad,
    /scheduleDataHealthPanelRefresh\('light', options\.force \? \{ force: true \} : \{\}\)/
  );
  assert.match(html, /const DATA_HEALTH_SECONDARY_PANEL_DELAY_MS = 900;/);
  assert.match(html, /const DATA_HEALTH_DETAIL_PANEL_DELAY_MS = 2600;/);
  assert.match(html, /const DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS = 4200;/);
  assert.match(html, /const dataHealthSecondaryPanelsReady = ref\(false\);/);
  assert.match(html, /const dataHealthDetailPanelsReady = ref\(false\);/);
  assert.match(html, /const dataHealthEmployeePanelsReady = ref\(false\);/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthSecondaryPanelsReady = \(delayMs = DATA_HEALTH_SECONDARY_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthDetailPanelsReady = \(delayMs = DATA_HEALTH_DETAIL_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /const scheduleDataHealthEmployeePanelsReady = \(delayMs = DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS\) => \{/);
  assert.match(dataHealthSecondaryScheduler, /currentPage\.value !== 'online-data' \|\| onlineDataTab\.value !== 'data-health'/);
  assert.match(scheduleOnlineDataTabLoad, /newTab === 'data-health'[\s\S]*dataHealthSecondaryPanelsReady\.value = false;[\s\S]*scheduleDataHealthSecondaryPanelsReady\(\);[\s\S]*dataHealthDetailPanelsReady\.value = false;[\s\S]*scheduleDataHealthDetailPanelsReady\(\);[\s\S]*dataHealthEmployeePanelsReady\.value = false;[\s\S]*scheduleDataHealthEmployeePanelsReady\(\);/);
  assert.match(onlineDataDefaultLoader, /dataHealthSecondaryPanelsReady\.value = false;\s*scheduleDataHealthSecondaryPanelsReady\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*scheduleDataHealthDetailPanelsReady\(\);\s*dataHealthEmployeePanelsReady\.value = false;\s*scheduleDataHealthEmployeePanelsReady\(\);/);
  assert.match(openOnlineDataEntryTab, /clearDataHealthSecondaryPanelsReadyTimer\(\);\s*dataHealthSecondaryPanelsReady\.value = false;\s*clearDataHealthDetailPanelsReadyTimer\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*clearDataHealthEmployeePanelsReadyTimer\(\);\s*dataHealthEmployeePanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /clearPlatformAutoSettingsPanelsReadyTimer\(\);\s*platformAutoSettingsPanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /clearPlatformAutoSecondaryPanelsReadyTimer\(\);\s*platformAutoSecondaryPanelsReady\.value = false;/);
  assert.match(openOnlineDataEntryTab, /onlineDataTab\.value = targetTab;\s*currentPage\.value = 'online-data';/);
  assert.match(html, /v-if="dataHealthEmployeePanelsReady" data-testid="phase1-employee-six-question-summary"/);
  assert.match(html, /v-if="dataHealthSecondaryPanelsReady" data-testid="data-health-command-center"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady && !dataHealthFullDiagnosticsLoaded" data-testid="hotel-data-cockpit-pending"/);
  assert.match(html, /v-else-if="dataHealthDetailPanelsReady" data-testid="hotel-data-cockpit"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady" data-testid="data-health-drilldown"/);
  assert.match(html, /v-if="dataHealthDetailPanelsReady" data-testid="mixed-collection-lifecycle-panel"/);
  assert.doesNotMatch(scheduleOnlineDataTabLoad, /return runIfCurrent\(\(\) => loadDataHealthPanel\('light'\)\);/);
  assert.match(
    onlineDataDefaultLoader,
    /runPageLoadOnce\(newPage, 'main', \(\) => \{\s*scheduleDataHealthPanelRefresh\('light'\);\s*return null;\s*\}\);/
  );
  assert.doesNotMatch(onlineDataDefaultLoader, /runPageLoadOnce\(newPage, 'main', \(\) => loadDataHealthPanel\('light'\)\);/);
  assert.match(openCtripManualTab, /loadDataHealthPanel:\s*scheduleDataHealthPanelRefresh/);
  assert.match(openCtripManualTab, /tab === 'data-health'[\s\S]*ctripEbookingSecondaryPanelsReady\.value = false;[\s\S]*scheduleCtripEbookingSecondaryPanelsReady\(\);[\s\S]*ctripEbookingDeepPanelsReady\.value = false;[\s\S]*scheduleCtripEbookingDeepPanelsReady\(\);[\s\S]*ctripEbookingBusinessDetailsReady\.value = false;[\s\S]*scheduleCtripEbookingBusinessDetailsReady\(\);[\s\S]*ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(openCtripManualTab, /clearCtripEbookingSecondaryPanelsReadyTimer\(\);[\s\S]*ctripEbookingSecondaryPanelsReady\.value = false;[\s\S]*clearCtripEbookingDeepPanelsReadyTimer\(\);[\s\S]*ctripEbookingDeepPanelsReady\.value = false;[\s\S]*clearCtripEbookingBusinessDetailsReadyTimer\(\);[\s\S]*ctripEbookingBusinessDetailsReady\.value = false;[\s\S]*ctripEbookingDiagnosticsPanelsReady\.value = false;/);
  assert.match(openCtripManualTab, /applySelectedConfig: false/);
  assert.match(openCtripManualTab, /refreshLatest: false/);
  assert.match(openCtripManualTab, /skipIfAligned: true/);
  assert.doesNotMatch(openCtripManualTab, /loadDataHealthPanel,\s*loadConfigList/);
  assert.match(goAiDailyReportDataGap, /currentPage\.value = 'online-data';\s*onlineDataTab\.value = 'data-health';\s*dataHealthSecondaryPanelsReady\.value = false;\s*scheduleDataHealthSecondaryPanelsReady\(\);\s*dataHealthDetailPanelsReady\.value = false;\s*scheduleDataHealthDetailPanelsReady\(\);\s*dataHealthEmployeePanelsReady\.value = false;\s*scheduleDataHealthEmployeePanelsReady\(\);\s*scheduleDataHealthPanelRefresh\('light'\);/);
  assert.doesNotMatch(goAiDailyReportDataGap, /await loadDataHealthPanel\('light'\);/);
  assert.match(html, /const MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS = 60;/);
  assert.match(html, /const MANUAL_ONLINE_FETCH_CONFIG_TABS = new Set\(\['ctrip', 'meituan', 'custom'\]\);/);
  assert.match(html, /const shouldPrewarmManualOnlineFetchConfig = \(newTab\) => MANUAL_ONLINE_FETCH_CONFIG_TABS\.has\(String\(newTab \|\| ''\)\);/);
  assert.match(html, /const scheduleManualOnlineFetchConfigPrewarm = \(newTab, delayMs = MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS\) => \{[\s\S]*if \(!isVisibleOnlineDataTab\(newTab\)\) return;[\s\S]*ensureManualOnlineFetchConfigReady\(\);/);
  assert.match(scheduleOnlineDataTabLoad, /const shouldPrewarmManualConfig = shouldPrewarmManualOnlineFetchConfig\(newTab\);/);
  assert.match(scheduleOnlineDataTabLoad, /if \(!shouldPrewarmManualConfig\) \{\s*clearManualOnlineFetchConfigPrewarmTimer\(\);\s*\}/);
  assert.match(scheduleOnlineDataTabLoad, /if \(newTab === 'data'\) \{[\s\S]*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);[\s\S]*return undefined;[\s\S]*\}/);
  assert.doesNotMatch(sliceFrom("if (newTab === 'data') {", "if (shouldPrewarmManualConfig) {"), /scheduleManualOnlineFetchConfigPrewarm/);
  assert.match(scheduleOnlineDataTabLoad, /if \(shouldPrewarmManualConfig\) \{\s*scheduleManualOnlineFetchConfigPrewarm\(newTab, options\.configPrewarmDelayMs\);\s*return undefined;\s*\}/);
  assert.doesNotMatch(scheduleOnlineDataTabLoad, /ensureManualOnlineFetchConfigReady\(\);\s*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);/);
});

test('Online analysis tab reuses recent analysis and detail reads during tab returns', () => {
  const scheduleOnlineDataTabLoad = sliceFrom(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    '\n            const openOnlineDataTab'
  );
  const analysisCache = sliceFrom(
    'const ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS = 8000;',
    '\n            const onlineAnalysisSourceText'
  );
  const loadAnalysisData = sliceFrom(
    'const loadAnalysisData = async (dimension = null, options = {}) => {',
    '\n\n            // 渲染分析图表'
  );
  const loadOnlineAnalysisRows = sliceFrom(
    'const loadOnlineAnalysisRows = async (options = {}) => {',
    '\n\n            const resolveDefaultOnlineAnalysisHotelId'
  );
  const refreshOnlineAnalysis = sliceFrom(
    'const refreshOnlineAnalysis = async (options = {}) => {',
    '\n\n            const openOnlineAnalysisTab'
  );
  const clearOnlineDataReadCaches = sliceFrom(
    'const clearOnlineDataReadCaches = () => {',
    '\n\n            const loadOnlineDataList'
  );

  assert.match(analysisCache, /const onlineAnalysisDataResultCache = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisRowsResultCache = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisDataRequestPromises = new Map\(\);/);
  assert.match(analysisCache, /const onlineAnalysisRowsRequestPromises = new Map\(\);/);
  assert.match(analysisCache, /const clearOnlineAnalysisReadCaches = \(\) => \{/);
  assert.match(analysisCache, /const readOnlineAnalysisResultCache = \(cache, key, cacheMs\) => \{/);
  assert.match(analysisCache, /const writeOnlineAnalysisResultCache = \(cache, key, data, cacheMs\) => \{/);
  assert.match(loadAnalysisData, /const cacheMs = Number\(options\?\.cacheMs \?\? ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS\);/);
  assert.match(loadAnalysisData, /const cached = readOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, cacheMs\);/);
  assert.match(loadAnalysisData, /if \(onlineAnalysisDataRequestPromises\.has\(requestKey\)\) \{/);
  assert.match(loadAnalysisData, /request\(`\/online-data\/data-analysis\?\$\{params\}`\)/);
  assert.match(loadAnalysisData, /writeOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, data, cacheMs\);/);
  assert.match(loadOnlineAnalysisRows, /const cached = readOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, cacheMs\);/);
  assert.match(loadOnlineAnalysisRows, /if \(onlineAnalysisRowsRequestPromises\.has\(requestKey\)\) \{/);
  assert.match(loadOnlineAnalysisRows, /request\(`\/online-data\/daily-data-list\?\$\{params\}`\)/);
  assert.match(loadOnlineAnalysisRows, /writeOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, data, cacheMs\);/);
  assert.match(refreshOnlineAnalysis, /cacheMs: ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS,/);
  assert.match(refreshOnlineAnalysis, /if \(loadOptions\.force === true\) \{\s*clearOnlineAnalysisReadCaches\(\);/);
  assert.match(refreshOnlineAnalysis, /loadAnalysisData\(null, loadOptions\)/);
  assert.match(refreshOnlineAnalysis, /loadOnlineDataSummary\(loadOptions\)/);
  assert.match(refreshOnlineAnalysis, /loadOnlineAnalysisRows\(loadOptions\)/);
  assert.match(scheduleOnlineDataTabLoad, /return refreshOnlineAnalysis\(options\);/);
  assert.match(clearOnlineDataReadCaches, /clearOnlineAnalysisReadCaches\(\);/);
  assert.match(html, /@click="loadOnlineAnalysisRows\(\{ force: true \}\)"/);
});

test('Download center defers hotel filter loading after primary data', () => {
  const downloadCenterScheduler = sliceFrom(
    'const scheduleDownloadCenterTabLoad = (tab, context = {}) => {',
    '\n            const applyOnlineHistoryDatePreset = () => {'
  );

  assert.match(downloadCenterScheduler, /await refreshOnlineHistory\(\{ refreshHotels: false \}\);/);
  assert.match(downloadCenterScheduler, /scheduleDelayedPageTask\(\(\) => \{\s*if \(!isCurrentTab\(\)\) return null;\s*return loadOnlineHistoryHotelList\(\);\s*\}, 720\);/);
  assert.match(downloadCenterScheduler, /return loadOnlineHistoryHotelList\(\);/);
  assert.match(downloadCenterScheduler, /await loadOnlineDataList\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);/);
  assert.match(downloadCenterScheduler, /scheduleDelayedPageTask\(\(\) => \{\s*if \(seq !== downloadCenterTabLoadSeq \|\| !isCurrentTab\(\)\) return null;\s*return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);\s*\}, 720\);/);
  assert.match(downloadCenterScheduler, /return loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\);/);
  assert.match(html, /const meituanDownloadData = computed\(\(\) => buildMeituanDownloadData\(onlineDataList\.value\)\);/);
  assert.match(html, /switchToMeituanDownloadCenter, meituanDownloadData,/);
  assert.doesNotMatch(downloadCenterScheduler, /await refreshOnlineHistory\(\);\s*return null;/);
  assert.doesNotMatch(
    downloadCenterScheduler,
    /Promise\.allSettled\(\[\s*loadOnlineDataList\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\),\s*loadOnlineDataHotelList\(\{ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS \}\),?\s*\]\)/
  );
});
