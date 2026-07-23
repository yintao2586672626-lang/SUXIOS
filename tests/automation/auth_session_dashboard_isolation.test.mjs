import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');
const systemSource = readFileSync('public/system-static.js', 'utf8');
const onlineDataTemplate = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const hotelManagementTemplate = readFileSync('resources/frontend/templates/fragments/18-page-hotels.html', 'utf8');
const usersTemplate = readFileSync('resources/frontend/templates/fragments/21-page-users.html', 'utf8');
const authMiddleware = readFileSync('app/middleware/Auth.php', 'utf8');

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

const compileScopedFunction = (functionSource, functionName, context) => {
  const names = Object.keys(context);
  return Function(...names, `${functionSource}\nreturn ${functionName};`)(...names.map(name => context[name]));
};

const sliceBetween = (start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const sliceSystemBetween = (start, end) => {
  const startIndex = systemSource.indexOf(start);
  const endIndex = systemSource.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing system start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing system end marker: ${end}`);
  return systemSource.slice(startIndex, endIndex);
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
    'hotelListLoading.value = false;',
    'hotelListLoadFailed.value = false;',
    'hotelListSnapshotReady.value = false;',
    "hotelListSnapshotScope = '';",
    'hotelManagementRequestSeq += 1;',
    'hotelManagementLoading.value = false;',
    'hotelManagementSnapshotReady.value = false;',
    "hotelManagementLoadError.value = '';",
    "hotelManagementLastRefreshedAt.value = '';",
    "filterReportHotel.value = '';",
    "onlineDataFilter.value.hotel_id = '';",
    "operationFilters.value.hotel_id = '';",
    "coreOperationsHotelId.value = '';",
    'resetCoreOperationsScopedState();',
    'globalNotificationBackendItems.value = [];',
    'globalNotificationBackendTotalCount.value = 0;',
    'globalNotificationBackendUnreadCount.value = 0;',
    'ctripConfigList.value = [];',
    'meituanConfigList.value = [];',
    'platformDataSourceLoading.value = false;',
    'platformDataSourceLoadFailed.value = false;',
    'platformDataSourceSnapshotReady.value = false;',
    "platformDataSourceLoadError.value = '';",
    'platformDataSourceSaving.value = false;',
    'platformDataImporting.value = false;',
    'browserAssistImporting.value = false;',
    'browserAssistImportResult.value = null;',
    "browserAssistImportFileName.value = '';",
    "platformDataSourceError.value = '';",
    'platformDataSourceForm.value = defaultPlatformDataSourceForm();',
    "platformImportForm.value = { data_source_id: '', rows_json: '' };",
    "browserAssistImportForm.value = { system_hotel_id: '', capture_json: '' };",
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

test('notification refreshes and no-hotel OTA entry cannot reuse another authentication session', () => {
  const notificationLoad = sliceBetween(
    'const loadBackendGlobalNotifications = async () => {',
    'const globalNotifications = computed',
  );
  const dataHealthLoad = sliceBetween(
    "const loadDataHealthPanel = async (mode = 'light', options = {}) => {",
    '// 手动触发自动获取',
  );
  const coreLoopRefresh = sliceBetween(
    'const refreshCoreOperationsLoop = async (options = {}) => {',
    'const loadPhase3OperationEffectLoop = async',
  );
  const tabLoader = sliceBetween(
    'const scheduleOnlineDataTabLoad = (newTab, options = {}) => {',
    'const openOnlineDataTab = (tab, options = {}) => {',
  );
  const hotelManagementLoader = sliceBetween(
    'const loadHotelManagementSnapshot = async (options = {}) => {',
    'const refreshHotelBindingPanelLight = async () => {',
  );

  assert.match(notificationLoad, /const notificationSession = captureAuthSession\(\);/);
  assert.match(notificationLoad, /if \(!isAuthSessionCurrent\(notificationSession\)\) return;/);
  assert.match(dataHealthLoad, /if \(!operationHotelOptions\.value\.length \|\| !currentHotelId\) \{[\s\S]*resetCoreOperationsScopedState\(\);[\s\S]*return;/);
  assert.match(dataHealthLoad, /const hotelScopeLoadFailed = hotelListLoadFailed\.value;/);
  assert.match(dataHealthLoad, /coreOperationsError\.value = hotelScopeLoadFailed[\s\S]*门店列表加载失败，请重试后再读取昨日经营闭环/);
  assert.ok(
    dataHealthLoad.indexOf('if (!operationHotelOptions.value.length || !currentHotelId)') < dataHealthLoad.indexOf('const jobs = buildDataHealthPanelRefreshJobs'),
    'no-hotel accounts must stop before protected data-health requests are scheduled',
  );
  assert.match(coreLoopRefresh, /const hotelIsAccessible = operationHotelOptions\.value\.some/);
  assert.match(coreLoopRefresh, /if \(!hotelId \|\| !hotelIsAccessible\)/);
  assert.match(tabLoader, /if \(!coreOperationsHasAccessibleHotel\.value\) return null;[\s\S]*ensureHotelOtaConfigLists/);
  assert.ok(
    hotelManagementLoader.indexOf("loadHotels({ force, includeInactive: true })")
      < hotelManagementLoader.indexOf('if (coreOperationsHasAccessibleHotel.value)'),
    'hotel management must resolve the hotel scope before loading protected OTA configuration',
  );
  assert.match(hotelManagementLoader, /const requestSession = captureAuthSession\(\);/);
  assert.match(hotelManagementLoader, /const requestSeq = \+\+hotelManagementRequestSeq;/);
  assert.match(hotelManagementLoader, /requestSeq === hotelManagementRequestSeq[\s\S]*isAuthSessionCurrent\(requestSession\)/);
  assert.match(hotelManagementLoader, /await Promise\.allSettled\([\s\S]*if \(!isCurrentRequest\(\)\) return false;/);
  assert.match(hotelManagementLoader, /finally \{\s*if \(isCurrentRequest\(\)\) \{\s*hotelManagementLoading\.value = false;/);
  assert.match(hotelManagementLoader, /if \(coreOperationsHasAccessibleHotel\.value\) \{[\s\S]*ensureHotelOtaConfigLists/);
  assert.match(hotelManagementLoader, /hotelManagementFailureLabels\(deep, coreOperationsHasAccessibleHotel\.value\)/);
});

test('no-hotel accounts see a concise Chinese Ctrip and Meituan preview with a hotel CTA', () => {
  const filterSource = sliceSystemBetween(
    'const filterVisibleMenuItems = (items = [], currentUser = null) => {',
    'const firstNonEmptyText = (...values) => {',
  );
  const filterVisibleMenuItems = Function(`${filterSource}; return filterVisibleMenuItems;`)();
  const menuItems = [
    {
      name: '线上数据',
      children: [
        { name: '概览', path: 'online-data', tab: 'data-health', permissions: ['can_view_online_data'] },
        { name: '携程采集', path: 'ctrip-ebooking', permissions: ['can_view_online_data'] },
      ],
    },
  ];
  const visible = filterVisibleMenuItems(menuItems, {
    permissions: { can_view_online_data: false },
    modules: { online_data: true },
    permitted_hotels: [],
  });
  const unverifiedScope = filterVisibleMenuItems(menuItems, {
    permissions: { can_view_online_data: false },
    modules: { online_data: true },
  });
  const nonEmptyScope = filterVisibleMenuItems(menuItems, {
    permissions: { can_view_online_data: false },
    modules: { online_data: true },
    permitted_hotels: [{ id: 7, name: '有权限门店' }],
  });
  const visibleWithArrayPermissions = filterVisibleMenuItems(menuItems, {
    permissions: ['can_view_online_data'],
    modules: {},
  });

  assert.deepEqual(visible[0].children.map(item => item.name), ['概览']);
  assert.deepEqual(unverifiedScope, []);
  assert.deepEqual(nonEmptyScope, []);
  assert.deepEqual(visibleWithArrayPermissions[0].children.map(item => item.name), ['概览', '携程采集']);
  assert.match(source, /sourcePath: 'online-data',[\s\S]*sourceTab: 'data-health',[\s\S]*overrides: \{ name: '携程 \/ 美团数据概览' \}/);
  assert.match(onlineDataTemplate, /v-else-if="coreOperationsHasVerifiedNoHotel" data-testid="core-operations-no-hotel-onboarding"/);
  assert.match(onlineDataTemplate, /携程、美团数据已开放/);
  assert.match(onlineDataTemplate, /<b>携程：<\/b>/);
  assert.match(onlineDataTemplate, /<b>美团：<\/b>/);
  assert.match(onlineDataTemplate, /不使用模拟值，不读取其他门店数据/);
  assert.match(onlineDataTemplate, /01 · 昨日数据/);
  assert.match(onlineDataTemplate, /06 · 执行复盘/);
  assert.doesNotMatch(onlineDataTemplate, /Yesterday evidence|Comparable set|Explainable signals|AI assistance|Action handoff|Execute and review/);
  assert.doesNotMatch(hotelManagementTemplate, /OTA ACCOUNT/);
  assert.match(hotelManagementTemplate, /维护携程、美团账号与采集配置，判断 OTA 采集入口是否可用；不展示账号密码或客人隐私。/);
  assert.match(onlineDataTemplate, /@click="openCoreOperationsHotelOnboarding"/);
  assert.match(onlineDataTemplate, /<template v-if="coreOperationsHasAccessibleHotel">[\s\S]*data-testid="ota-direct-view-overview"[\s\S]*data-testid="manual-one-click-fetch"[\s\S]*<\/template>/);
  assert.match(source, /const openCoreOperationsHotelOnboarding = async \(\) => \{[\s\S]*currentPage\.value = 'hotels';/);
});

test('OTA hotel scope distinguishes loading, failed, and verified-empty snapshots', () => {
  const loadHotelsFlow = sliceBetween(
    'const loadHotels = async (options = {}) => {',
    'let startupHotelListLoadTimer = null;',
  );
  const scopeState = sliceBetween(
    'const coreOperationsHasAccessibleHotel = computed',
    'const firstOperationHotelId = () => {',
  );
  const resourceLoad = sliceBetween(
    'const loadPlatformCollectionResources = async (options = {}) => {',
    'const loadPlatformCollectionStatus = async',
  );

  assert.match(source, /const hotelListLoading = ref\(false\);/);
  assert.match(source, /const hotelListSnapshotReady = ref\(false\);/);
  assert.match(loadHotelsFlow, /hotelListLoading\.value = true;/);
  assert.match(loadHotelsFlow, /hotelListLoadFailed\.value = false;/);
  assert.match(loadHotelsFlow, /if \(Array\.isArray\(hotelData\)\) \{[\s\S]*hotelListSnapshotReady\.value = true;/);
  assert.match(loadHotelsFlow, /hotelListSnapshotScope = listScope;/);
  assert.match(loadHotelsFlow, /hotelListRequestIntentSeqByKey\.get\(requestKey\) === hotelListRequestSeq/);
  assert.match(loadHotelsFlow, /listScope === currentHotelListScope\(\)/);
  assert.match(loadHotelsFlow, /hotelListLoadFailed\.value = true;/);
  assert.match(loadHotelsFlow, /if \(isLatestRequestIntent\(\)\) \{\s*hotelListLoading\.value = false;/);
  assert.doesNotMatch(resourceLoad, /hotelListLoadFailed\.value = false;/);

  assert.match(scopeState, /const coreOperationsHotelScopeLoading = computed/);
  assert.match(scopeState, /const coreOperationsHotelScopeLoadFailed = computed/);
  assert.match(scopeState, /const coreOperationsHasVerifiedNoHotel = computed/);
  assert.match(scopeState, /hotelListSnapshotReady\.value/);
  assert.match(onlineDataTemplate, /data-testid="core-operations-hotel-scope-loading"/);
  assert.match(onlineDataTemplate, /data-testid="core-operations-hotel-scope-error"/);
  assert.match(onlineDataTemplate, /data-testid="core-operations-hotel-scope-retry"/);
  assert.match(onlineDataTemplate, /无法确认门店范围，暂不读取平台数据/);
  assert.ok(
    onlineDataTemplate.indexOf('data-testid="core-operations-hotel-scope-error"')
      < onlineDataTemplate.indexOf('data-testid="core-operations-no-hotel-onboarding"'),
    'load failure must be handled before the verified-empty onboarding branch',
  );
  assert.match(source, /const retryCoreOperationsHotelScope = async \(\) => \{[\s\S]*loadHotels\(\{ force: true, cacheMs: 0 \}\)/);
});

test('a delayed active-only hotel response cannot overwrite the newer management scope', async () => {
  const loadHotelsFlow = sliceBetween(
    'const loadHotels = async (options = {}) => {',
    'let startupHotelListLoadTimer = null;',
  );
  const activeOnly = deferred();
  const management = deferred();
  const sessionState = { epoch: 1, token: 'token-a' };
  const user = { value: { is_super_admin: true } };
  const currentPage = { value: 'compass' };
  const hotels = { value: [] };
  const hotelListLoading = { value: false };
  const hotelListLoadFailed = { value: false };
  const hotelListSnapshotReady = { value: false };
  const request = (url) => {
    if (url === '/hotels/all') return activeOnly.promise;
    if (url.startsWith('/hotels?page=')) return management.promise;
    throw new Error(`unexpected URL: ${url}`);
  };
  const loadHotels = compileScopedFunction(loadHotelsFlow, 'loadHotels', {
    captureAuthSession: () => ({ ...sessionState }),
    isAuthSessionCurrent: session => session.epoch === sessionState.epoch && session.token === sessionState.token,
    user,
    currentPage,
    hotels,
    hotelListLoading,
    hotelListLoadFailed,
    hotelListSnapshotReady,
    hotelListPendingCount: 0,
    hotelListRequestSeq: 0,
    hotelListSnapshotScope: '',
    loadHotelsRequestPromises: new Map(),
    hotelListResultCache: new Map(),
    hotelListRequestIntentSeqByKey: new Map(),
    currentHotelListScope: () => (
      user.value?.is_super_admin && currentPage.value === 'hotels'
        ? 'paged-with-inactive'
        : 'all-active'
    ),
    readRequestCache: () => false,
    writeRequestCache: (cache, key) => cache.set(key, true),
    request,
    dedupeHotels: items => items,
    showToast: () => {},
  });

  const activeRun = loadHotels({ force: true });
  currentPage.value = 'hotels';
  const managementRun = loadHotels({ force: true, includeInactive: true });
  management.resolve({ code: 200, data: { list: [{ id: 22, name: '停用门店也可见' }], total_page: 1 } });
  await managementRun;
  assert.deepEqual(hotels.value, [{ id: 22, name: '停用门店也可见' }]);

  activeOnly.resolve({ code: 200, data: [{ id: 11, name: '仅营业门店' }] });
  await activeRun;
  assert.deepEqual(
    hotels.value,
    [{ id: 22, name: '停用门店也可见' }],
    'the obsolete active-only response must not replace the management snapshot',
  );
});

test('hotel management ignores a delayed response after its auth session and request sequence change', async () => {
  const loaderSource = sliceBetween(
    'const loadHotelManagementSnapshot = async (options = {}) => {',
    'const refreshHotelBindingPanelLight = async () => {',
  );
  const firstHotelLoad = deferred();
  const authState = { epoch: 1, token: 'token-a' };
  const hotelManagementLoading = { value: false };
  const hotelManagementSnapshotReady = { value: false };
  const hotelManagementLoadError = { value: '' };
  const hotelManagementLastRefreshedAt = { value: '' };
  let hotelLoadCalls = 0;
  let failureMode = false;
  const context = {
    authState,
    hotelManagementLoading,
    hotelManagementSnapshotReady,
    hotelManagementLoadError,
    hotelManagementLastRefreshedAt,
    hotelManagementRequestSeq: 0,
    captureAuthSession: () => ({ ...authState }),
    isAuthSessionCurrent: session => session.epoch === authState.epoch && session.token === authState.token,
    clearStartupHotelListLoadTimer: () => {},
    loadHotels: () => {
      hotelLoadCalls += 1;
      return hotelLoadCalls === 1 ? firstHotelLoad.promise : Promise.resolve([]);
    },
    coreOperationsHasAccessibleHotel: { value: false },
    ensureHotelOtaConfigLists: () => Promise.resolve(),
    loadPlatformSyncTasks: () => Promise.resolve(),
    loadPlatformSyncLogs: () => Promise.resolve(),
    loadCompetitorSummary: () => Promise.resolve(),
    hotelManagementFailureLabels: () => (failureMode ? ['旧请求失败'] : []),
    showToast: () => {},
  };
  const names = Object.keys(context);
  const harness = Function(
    ...names,
    `${loaderSource}
    return {
      loadHotelManagementSnapshot,
      invalidate() {
        authState.epoch += 1;
        authState.token = 'token-b';
        hotelManagementRequestSeq += 1;
        hotelManagementLoading.value = false;
      },
    };`,
  )(...names.map(name => context[name]));

  const obsoleteRun = harness.loadHotelManagementSnapshot();
  harness.invalidate();
  const currentResult = await harness.loadHotelManagementSnapshot();
  assert.equal(currentResult, true);
  const currentRefreshTime = hotelManagementLastRefreshedAt.value;
  failureMode = true;
  firstHotelLoad.resolve([]);
  assert.equal(await obsoleteRun, false);
  assert.equal(hotelManagementLoadError.value, '');
  assert.equal(hotelManagementSnapshotReady.value, true);
  assert.equal(hotelManagementLastRefreshedAt.value, currentRefreshTime);
  assert.equal(hotelManagementLoading.value, false);
});

test('platform data sources preserve only a verified snapshot when refresh fails', async () => {
  const loaderSource = sliceBetween(
    'const loadPlatformDataSources = async (options = {}) => {',
    'const loadPlatformSyncTasks = async (options = {}) => {',
  );
  const createHarness = (request) => {
    const sessionState = { epoch: 1, token: 'token-a' };
    const state = {
      platformDataSources: { value: [] },
      platformDataSourceLoading: { value: false },
      platformDataSourceLoadFailed: { value: false },
      platformDataSourceSnapshotReady: { value: false },
      platformDataSourceLoadError: { value: '' },
    };
    const loadPlatformDataSources = compileScopedFunction(loaderSource, 'loadPlatformDataSources', {
      ...state,
      captureAuthSession: () => ({ ...sessionState }),
      isAuthSessionCurrent: session => session.epoch === sessionState.epoch && session.token === sessionState.token,
      normalizeRequestCacheOptions: options => options,
      platformDataSourcesRequestPromises: new Map(),
      platformDataSourcesResultCache: new Map(),
      readRequestCache: () => false,
      writeRequestCache: (cache, key) => cache.set(key, true),
      request,
      showToast: () => {},
    });
    return { state, loadPlatformDataSources };
  };

  const firstResponse = deferred();
  const failedRefresh = deferred();
  const responseQueue = [firstResponse.promise, failedRefresh.promise];
  const harness = createHarness(() => responseQueue.shift());
  const firstRun = harness.loadPlatformDataSources({ force: true });
  firstResponse.resolve({ code: 200, data: [{ id: 3, name: '携程 Profile' }] });
  await firstRun;
  assert.equal(harness.state.platformDataSourceSnapshotReady.value, true);
  assert.deepEqual(harness.state.platformDataSources.value, [{ id: 3, name: '携程 Profile' }]);

  const refreshRun = harness.loadPlatformDataSources({ force: true });
  failedRefresh.resolve({ code: 503, message: '暂时不可用' });
  await refreshRun;
  assert.deepEqual(harness.state.platformDataSources.value, [{ id: 3, name: '携程 Profile' }]);
  assert.equal(harness.state.platformDataSourceSnapshotReady.value, true);
  assert.equal(harness.state.platformDataSourceLoadFailed.value, true);
  assert.equal(harness.state.platformDataSourceLoadError.value, '暂时不可用');

  const malformed = createHarness(async () => ({ code: 200, data: { list: [] } }));
  await malformed.loadPlatformDataSources({ force: true });
  assert.deepEqual(malformed.state.platformDataSources.value, []);
  assert.equal(malformed.state.platformDataSourceSnapshotReady.value, false);
  assert.equal(malformed.state.platformDataSourceLoadFailed.value, true);
  assert.equal(malformed.state.platformDataSourceLoadError.value, '平台数据源返回格式异常');
  assert.match(onlineDataTemplate, /data-testid="platform-data-source-load-error"/);
  assert.match(onlineDataTemplate, /加载失败，不代表未配置数据源/);
  assert.match(onlineDataTemplate, /刷新失败，当前显示上次成功结果；不代表当前真实状态/);
  assert.match(onlineDataTemplate, /platformDataSourceSnapshotReady[\s\S]*platformDataSources\.length/);
});

test('employee refresh failure keeps the prior snapshot explicitly stale instead of empty', async () => {
  const loaderSource = sliceBetween(
    'const loadUsers = async (options = {}) => {',
    'const loadRoles = async (options = {}) => {',
  );
  const firstResponse = deferred();
  const failedRefresh = deferred();
  const responseQueue = [firstResponse.promise, failedRefresh.promise];
  const sessionState = { epoch: 1, token: 'token-a' };
  const users = { value: [] };
  const usersLoading = { value: false };
  const usersLoadError = { value: '' };
  const usersSnapshotReady = { value: false };
  const loadUsers = compileScopedFunction(loaderSource, 'loadUsers', {
    captureAuthSession: () => ({ ...sessionState }),
    isAuthSessionCurrent: session => session.epoch === sessionState.epoch && session.token === sessionState.token,
    usersRequestSeq: 0,
    users,
    usersLoading,
    usersLoadError,
    usersSnapshotReady,
    request: () => responseQueue.shift(),
    showToast: () => {},
  });

  const firstRun = loadUsers();
  firstResponse.resolve({
    code: 200,
    data: { list: [], total_page: 1 },
  });
  await firstRun;
  assert.deepEqual(users.value, []);
  assert.equal(usersSnapshotReady.value, true);

  const refreshRun = loadUsers();
  failedRefresh.reject(new Error('员工服务暂时不可用'));
  await refreshRun;
  assert.deepEqual(users.value, []);
  assert.equal(usersSnapshotReady.value, true);
  assert.equal(usersLoadError.value, '员工服务暂时不可用');
  assert.equal(usersLoading.value, false);
  assert.match(usersTemplate, /data-testid="employee-list-stale-loading"/);
  assert.match(usersTemplate, /data-testid="employee-list-stale-error"/);
  assert.match(usersTemplate, /加载失败，当前显示上次成功结果；不代表当前真实状态/);
  assert.match(usersTemplate, /v-else-if="usersSnapshotReady &amp;&amp; !usersLoading &amp;&amp; !usersLoadError"[\s\S]*暂无员工数据/);
});

test('expected permission denials stay in audit logs while actionable security notices are Chinese and targeted', () => {
  const deniedStart = authMiddleware.indexOf('private function recordProtectedAccessDenied');
  const deniedEnd = authMiddleware.indexOf('private function recordSecurityNotification', deniedStart);
  assert.ok(deniedStart >= 0 && deniedEnd > deniedStart);
  const deniedSource = authMiddleware.slice(deniedStart, deniedEnd);

  assert.match(deniedSource, /OperationLog::record/);
  assert.doesNotMatch(deniedSource, /recordSecurityNotification/);
  assert.match(authMiddleware, /'recipient_user_id' => \(int\)\$user->id/);
  assert.match(authMiddleware, /'title' => '请求过于频繁'/);
  assert.match(authMiddleware, /'message' => '系统已暂时限制高频访问，请稍后重试。'/);
  assert.doesNotMatch(authMiddleware, /'title' => 'SUXIOS security guard'/);
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
