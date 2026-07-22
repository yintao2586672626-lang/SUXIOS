import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');

const sliceBetween = (start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

test('stale authentication responses cannot clear or replace a newer session', () => {
  const sessionHelpers = sliceBetween('let authSessionEpoch = 0;', 'const createDefaultAuthContext');
  const apiRequest = sliceBetween('const request = async (url, options = {}) => {', 'const apiRequest = request;');
  const mountedBootstrap = sliceBetween('const bootstrapSession = captureAuthSession();', '\n                }\n            });');

  assert.match(sessionHelpers, /epoch:\s*authSessionEpoch/);
  assert.match(sessionHelpers, /Number\(session\.epoch\) === authSessionEpoch/);
  assert.match(sessionHelpers, /String\(session\.token \|\| ''\) === String\(token\.value \|\| ''\)/);
  assert.match(apiRequest, /const requestSession = captureAuthSession\(\);/);
  assert.match(apiRequest, /clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/);
  assert.match(apiRequest, /isTerminalAuthFailureResponse\(response, data\)/);
  assert.match(apiRequest, /authFailureReason === 'user_disabled'/);
  assert.match(apiRequest, /data\.code === 403\) && isAuthSessionCurrent\(requestSession\)/);
  assert.match(mountedBootstrap, /if \(!isAuthSessionCurrent\(bootstrapSession\)\) return;/);
  assert.match(mountedBootstrap, /beginAuthSession\(bootstrapSession\.token\);/);
  assert.match(source, /const handleAuthInfoBootstrapUnavailable = \(session\) => \{\s*if \(!isAuthSessionCurrent\(session\)\) return;/);
  assert.match(mountedBootstrap, /handleAuthInfoBootstrapUnavailable\(bootstrapSession\)/);
  assert.doesNotMatch(mountedBootstrap, /clearAuthSession\(\)/);
});

test('only terminal authentication responses clear a cached session', () => {
  const helperSource = sliceBetween(
    'const terminalAuthFailureReason = (data = {}) =>',
    'const applyAuthContext = (context = {}) =>',
  );
  const helpers = Function(`${helperSource}; return { terminalAuthFailureReason, isTerminalAuthFailureResponse };`)();

  assert.equal(helpers.isTerminalAuthFailureResponse({ status: 401 }, { code: 401, data: { reason: 'token_revoked' } }), true);
  assert.equal(helpers.isTerminalAuthFailureResponse({ status: 403 }, { code: 403, data: { reason: 'user_disabled' } }), true);
  assert.equal(helpers.isTerminalAuthFailureResponse({ status: 403 }, { code: 403, data: { redacted_reason: 'permission_denied' } }), false);
  assert.equal(helpers.isTerminalAuthFailureResponse({ status: 503 }, { code: 503, message: 'temporary outage' }), false);
});

test('login, logout, and account switches reset hotel-scoped browser state', () => {
  const resetState = sliceBetween('const resetHotelScopedClientState = () => {', 'const clearActiveHotelDashboardSnapshots = () => {');
  const loginFlow = sliceBetween('const handleLogin = async () => {', 'const loadLoginSupportContact = async () => {');
  const logoutFlow = sliceBetween('const handleLogout = async () => {', 'const dedupeHotels = (items = []) => {');

  for (const expected of [
    'pageLoadRequests.clear();',
    'loadHotelsRequestPromises.clear();',
    'platformDataSourcesRequestPromises.clear();',
    'competitorSummaryRequestPromises.clear();',
    'ctripLatestRequestPromises.clear();',
    'hotels.value = [];',
    'permittedHotels.value = [];',
    "filterReportHotel.value = '';",
    'ctripConfigList.value = [];',
    'meituanConfigList.value = [];',
    "resetAgentCenterClientState({ reason: 'auth-session' });",
    'clearSessionScopedFrontendTimers();',
  ]) {
    assert.ok(resetState.includes(expected), `account reset must include: ${expected}`);
  }
  assert.match(loginFlow, /beginAuthSession\(res\.data\.token\);[\s\S]*permittedHotels\.value = dedupeHotels[\s\S]*hotels\.value = \[\.\.\.permittedHotels\.value\]/);
  assert.ok(logoutFlow.indexOf('clearAuthSession();') < logoutFlow.indexOf('await logoutRequest;'));
  assert.match(logoutFlow, /const logoutRequest = request\('\/auth\/logout', \{ method: 'POST' \}\);/);
  assert.match(logoutFlow, /catch \(error\)/);
});

test('account and hotel changes clear Agent, OTA diagnosis, revenue, and polling state', () => {
  const agentReset = sliceBetween(
    'const resetAgentCenterClientState = (options = {}) => {',
    'const competitorMicroscope = computed',
  );
  const profileTimerReset = sliceBetween(
    'const clearPlatformProfileLoginTimer = (platform) => {',
    'const firstNonEmptyText = (...values) => {',
  );
  const timerReset = sliceBetween(
    'const clearSessionScopedFrontendTimers = () => {',
    'let suppressNextOnlineDataTabWatcherLoad = false;',
  );
  const hotelSwitch = sliceBetween(
    'watch(filterReportHotel, (newHotelId, previousHotelId) => {',
    'watch(weatherLocationName, () => {',
  );
  const userSwitch = sliceBetween(
    'watch(() => user.value?.id, (newUserId, previousUserId) => {',
    'watch(filterReportHotel, (newHotelId, previousHotelId) => {',
  );

  for (const expected of [
    'agentRevenueStateEpoch += 1;',
    "agentTab.value = 'overview';",
    'agentOverview.value = createEmptyAgentOverview();',
    'otaDiagnosisForm.value = createOtaDiagnosisForm();',
    'otaDiagnosisResult.value = null;',
    'otaDiagnosisLoading.value = false;',
    'otaDiagnosisExecutionLoading.value = \"\";',
    'revenueAnalysisData.value = createEmptyRevenueAnalysisData();',
    'revenueDashboard.value = createEmptyRevenueDashboard();',
    'priceSuggestions.value = [];',
    'demandForecasts.value = [];',
    'agentLogs.value = [];',
    'revenueLoadState.value = createRevenueLoadState();',
    'resetCompetitorAnalysisView();',
  ]) {
    assert.ok(agentReset.includes(expected), `Agent reset must include: ${expected}`);
  }

  assert.match(profileTimerReset, /const clearPlatformProfileLoginTimers = \(\) => \{[\s\S]*clearPlatformProfileLoginTimer\('ctrip'\);[\s\S]*clearPlatformProfileLoginTimer\('meituan'\);[\s\S]*platformProfileLoginTasks\.value = \{\};/);
  assert.match(timerReset, /clearPostFetchRefreshTimers\(\);/);
  assert.match(timerReset, /clearHomeQuickLayoutAutoSaveTimer\(\);/);
  assert.match(timerReset, /clearPlatformProfileLoginTimers\(\);/);
  assert.match(timerReset, /clearManualOnlineFetchConfigPrewarmTimer\(\);/);
  assert.match(hotelSwitch, /String\(newHotelId \|\| ''\) !== String\(previousHotelId \|\| ''\)/);
  assert.match(hotelSwitch, /resetAgentCenterClientState\(\{ reason: 'hotel-switch' \}\);/);
  assert.match(hotelSwitch, /clearPostFetchRefreshTimers\(\);/);
  assert.match(hotelSwitch, /clearPlatformProfileLoginTimers\(\);/);
  assert.match(userSwitch, /resetAgentCenterClientState\(\{ reason: 'user-switch' \}\);/);
});

test('Agent and OTA diagnosis async results reject stale sessions and hotels', () => {
  const contextHelpers = sliceBetween(
    'let agentRevenueStateEpoch = 0;',
    'const resetAgentCenterClientState = (options = {}) => {',
  );
  const diagnosis = sliceBetween(
    'const generateOtaDiagnosis = async () => {',
    'const createOtaDiagnosisExecutionIntent = async (row) => {',
  );
  const overview = sliceBetween(
    'const loadAgentOverview = async (options = {}) => {',
    '// 保存Agent配置',
  );
  const profilePoll = sliceBetween(
    'const pollPlatformProfileLoginStatus = async (platform, taskId) => {',
    'const resumePlatformProfileLoginTasks = (status) => {',
  );
  const revenueActions = sliceBetween(
    'const saveRoomTypeConfig = async () => {',
    'const loadAgentLogs = async (options = {}) => {',
  );

  assert.match(contextHelpers, /session: captureAuthSession\(\)/);
  assert.match(contextHelpers, /isAuthSessionCurrent\(context\.session\)/);
  assert.match(contextHelpers, /String\(filterReportHotel\.value \|\| ''\) === context\.hotelId/);
  assert.match(diagnosis, /const requestContext = captureAgentRevenueRequestContext\(\);/);
  assert.match(diagnosis, /if \(!isAgentRevenueRequestCurrent\(requestContext\)\) return;/);
  assert.match(overview, /const requestContext = captureAgentRevenueRequestContext\(\);/);
  assert.match(overview, /if \(!isAgentRevenueRequestCurrent\(requestContext\)\) return/);
  assert.match(profilePoll, /const requestSession = captureAuthSession\(\);/);
  assert.match(profilePoll, /const requestHotelId = String\(getAutoFetchHotelId\(\) \|\| ''\)\.trim\(\);/);
  assert.match(profilePoll, /if \(!isPlatformProfilePollCurrent\(requestSession, requestHotelId\)\) return;/);
  assert.match(revenueActions, /const generatePriceSuggestions = async \(\) => \{[\s\S]*const requestContext = captureAgentRevenueRequestContext/);
  assert.match(revenueActions, /const createPriceSuggestionExecutionIntent = async \(id\) => \{[\s\S]*if \(!isAgentRevenueRequestCurrent\(requestContext\)\) return;/);
  assert.match(revenueActions, /const reviewPriceSuggestion = async \(id\) => \{[\s\S]*if \(!isAgentRevenueRequestCurrent\(requestContext\)\) return;/);
});

test('revenue loader failures clear old values and expose a persistent failure or empty state', () => {
  const revenueState = sliceBetween(
    'const createRevenueLoadState = () => ({',
    'const competitorMicroscope = computed',
  );
  const roomTypes = sliceBetween(
    'const loadRoomTypes = async (options = {}) => {',
    'const saveRoomTypeConfig = async () => {',
  );
  const loaders = sliceBetween(
    'const loadAgentLogs = async (options = {}) => {',
    'const switchAgentTab = async (tabKey) => {',
  );
  const notice = sliceBetween(
    'const revenueAnalysisDataNotice = computed(() => {',
    'const SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS',
  );

  assert.match(revenueState, /analysis: \{ status: 'not_loaded', error: '' \}/);
  assert.match(roomTypes, /roomTypeConfigList\.value = \[\];/);
  assert.match(roomTypes, /setRevenueLoadState\('roomTypes', 'failed'/);
  assert.match(loaders, /revenueAnalysisData\.value = createEmptyRevenueAnalysisData\(\);/);
  assert.match(loaders, /revenueDashboard\.value = createEmptyRevenueDashboard\(\);/);
  assert.match(loaders, /demandForecasts\.value = \[\];/);
  assert.match(loaders, /priceSuggestions\.value = \[\];/);
  assert.match(loaders, /setRevenueLoadState\('analysis', 'failed'/);
  assert.match(loaders, /if \(!isAgentRevenueRequestCurrent\(requestContext\)\) return/);
  assert.match(notice, /收益数据读取失败/);
  assert.match(notice, /已清除上一会话或上一酒店数据/);
  assert.match(notice, /收益数据读取中/);
  assert.match(notice, /收益数据为空/);
});

test('hotel and dashboard loaders reject stale responses and reuse in-flight requests', () => {
  const pageLoadGuard = sliceBetween('const runPageLoadOnce = (page, loadingKey, task, options = {}) => {', 'const activateCoreOperationsAfterLogin = () => {');
  const workbench = sliceBetween('let dualOtaWorkbenchRequestSeq = 0;', 'const setDualOtaPlatform = (platform, options = {}) => {');
  const competitor = sliceBetween('const loadCompetitorSummary = async (options = {}) => {', 'const loadRevenueAiOverview = async () => {');
  const latest = sliceBetween('const loadLatestCtripData = async (', 'const hasVisibleCtripSnapshot = () => {');
  const hotelSwitch = sliceBetween('watch(filterReportHotel, (newHotelId, previousHotelId) => {', 'watch(weatherLocationName, () => {');
  const clearHotel = sliceBetween('const clearActiveHotelDashboardSnapshots = () => {', 'const beginAuthSession = (nextToken) => {');

  assert.match(pageLoadGuard, /const key = `\$\{sessionEpoch\}:\$\{page\}:\$\{loadingKey\}`/);
  assert.match(pageLoadGuard, /if \(sessionEpoch !== authSessionEpoch\)/);
  assert.match(workbench, /if \(!isLoggedIn\.value \|\| !token\.value \|\| !isCompassDataPage\(\)\) return;/);
  assert.match(workbench, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(workbench, /if \(!isCurrentRequest\(\)\) return null;/);
  assert.match(competitor, /competitorSummaryRequestPromises\.has\(requestKey\)/);
  assert.match(competitor, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(competitor, /currentPage\.value === requestPage/);
  assert.match(latest, /ctripLatestRequestPromises\.get\(requestKey\)/);
  assert.match(latest, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(hotelSwitch, /clearActiveHotelDashboardSnapshots\(\);[\s\S]*refreshDualOtaWorkbenchData/);
  assert.match(hotelSwitch, /if \(suppressNextReportHotelDashboardRefresh\) \{\s*suppressNextReportHotelDashboardRefresh = false;\s*return;/);
  assert.match(clearHotel, /homeTrendData\.value = \{/);
  assert.match(clearHotel, /competitorSummary\.value = null;/);
  assert.match(clearHotel, /clearCtripOverviewDisplayState\(\);/);
});

test('hotel data dashboard rejects stale hotel and session responses before mutating diagnostics state', () => {
  const dashboard = sliceBetween('const loadHotelDataDashboard = async () => {', '\n\n            const dataHealthLightCacheKey');
  const responseIndex = dashboard.indexOf('await Promise.all([');
  const responseGuardIndex = dashboard.indexOf('if (!isCurrentRequest()) return null;', responseIndex);
  const firstResponseWriteIndex = dashboard.indexOf('dashboardAccountOverview.value =', responseIndex);

  assert.match(source, /let hotelDashboardRequestSeq = 0;/);
  assert.match(dashboard, /const requestSession = captureAuthSession\(\);/);
  assert.match(dashboard, /const requestSeq = \+\+hotelDashboardRequestSeq;/);
  assert.match(dashboard, /requestSeq === hotelDashboardRequestSeq/);
  assert.match(dashboard, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(dashboard, /String\(dashboardHotelId\.value \|\| getAutoFetchHotelId\(\) \|\| ''\)\.trim\(\) === selectedHotelId/);
  assert.match(dashboard, /dataHealthFullDiagnosticsLoaded\.value = false;/);
  assert.ok(responseIndex >= 0 && responseGuardIndex > responseIndex, 'dashboard response must be checked after the requests settle');
  assert.ok(firstResponseWriteIndex > responseGuardIndex, 'stale dashboard responses must be rejected before the first state write');
  assert.match(dashboard, /catch \(error\) \{\s*if \(!isCurrentRequest\(\)\) return null;/);
  assert.match(dashboard, /finally \{\s*if \(isCurrentRequest\(\)\) \{[\s\S]*dataHealthFullDiagnosticsLoaded\.value = diagnosticsLoaded;[\s\S]*hotelDashboardLoading\.value = false;/);
});
