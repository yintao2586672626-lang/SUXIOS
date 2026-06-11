import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function requireText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoText(file, needle, label) {
  const source = read(file);
  checks.push({
    file,
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

function requireTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: source.includes(needle),
    detail: needle,
  });
}

function requireNoTextInFiles(files, needle, label) {
  const source = files.map(read).join('\n');
  checks.push({
    file: files.join(' + '),
    label,
    ok: !source.includes(needle),
    detail: needle,
  });
}

requireText('public/index.html', 'data-testid="login-username"', 'login username has stable selector');
requireText('public/index.html', 'data-testid="login-password"', 'login password has stable selector');
requireText('public/index.html', 'data-testid="login-submit"', 'login submit has stable selector');
requireText('public/index.html', "requireAppSystemStatic('getRememberedLoginAccount')", 'entry uses extracted remembered login account reader');
requireText('public/index.html', "requireAppSystemStatic('buildLoginRequestPayload')", 'entry uses extracted login payload builder');
requireText('public/index.html', "requireAppSystemStatic('validateLoginRequestPayload')", 'entry uses extracted login validation');
requireText('public/index.html', "requireAppSystemStatic('applyRememberedLoginAccount')", 'entry uses extracted remembered login account writer');
requireText('public/index.html', 'data-testid="open-register"', 'login page exposes self-registration entry selector');
requireText('public/index.html', 'data-testid="register-submit"', 'login page exposes self-registration submit');
requireText('public/index.html', 'data-testid="register-username"', 'login page exposes self-registration fields');
requireText('public/index.html', "request('/auth/register'", 'frontend calls public self-registration API');
requireText('public/index.html', "requireAppSystemStatic('createRegisterForm')", 'entry uses extracted register form builder');
requireText('public/index.html', "requireAppSystemStatic('buildRegisterRequestPayload')", 'entry uses extracted register payload builder');
requireText('public/index.html', "requireAppSystemStatic('validateRegisterRequestPayload')", 'entry uses extracted register validation');
requireText('public/index.html', 'data-testid="app-nav"', 'sidebar nav has stable selector');
requireText('public/index.html', 'data-testid="app-main"', 'main app surface has stable selector');
requireText('public/index.html', ':data-current-page="currentPage"', 'main app surface exposes current page state');
requireText('public/index.html', 'const suxiApp = createApp({', 'entry keeps Vue app instance available before mount');
requireText('public/index.html', 'const renderSuxiStartupError = (error) => {', 'entry renders startup/runtime initialization failures explicitly');
requireText('public/index.html', 'suxiApp.config.errorHandler = (error) => {', 'entry wires Vue runtime errors to explicit startup error surface');
requireText('public/index.html', ".replace(/[<>&\"']/g", 'startup error surface escapes injected error text');
requireText('public/index.html', "const stack = String(error?.stack || '').split('\\n').slice(0, 8).join('\\n');", 'startup error surface keeps bounded stack evidence');
requireText('public/index.html', "[String(error?.message || error || 'unknown startup error'), stack].filter(Boolean).join('\\n')", 'startup error surface combines message and stack evidence');
requireText('public/index.html', "if (appRoot.dataset.startupErrorRendered === '1') return;", 'startup error surface is idempotent');
requireText('public/index.html', "appRoot.dataset.startupErrorRendered = '1';", 'startup error surface marks rendered state');
requireText('public/index.html', "if (!u || typeof u !== 'object') return false;", 'user search skips invalid user rows');
requireText('public/index.html', "const username = String(u.username || '');", 'user search normalizes username before matching');
requireText('public/index.html', "const realname = String(u.realname || '');", 'user search normalizes real name before matching');
requireText('public/index.html', ':key="u?.id || index"', 'user table keeps stable fallback key');
requireText('public/index.html', "{{ u?.username || '-' }}", 'user table renders missing username safely');
requireText('public/index.html', "String(u?.status) === '1'", 'user table renders missing status safely');
requireText('public/index.html', 'v-if="u && (user?.is_super_admin', 'user table actions require a valid row');
requireText('public/index.html', 'v-for="(u, index) in logUsers"', 'operation log user filter exposes row index fallback');
requireText('public/index.html', ':value="u?.id || \'\'"', 'operation log user filter handles missing ids');
requireText('public/index.html', "{{ u?.realname || u?.username || '-' }}", 'operation log user filter handles missing names');
requireText('public/index.html', 'vue.global.prod.js?v=', 'entry versions the local Vue runtime');
requireText('public/index.html', 'system-static.js?v=', 'entry versions the system static helper');
requireText('public/index.html', ':data-testid="menuTestId(item)"', 'top-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(child)"', 'second-level menu uses test id helper');
requireText('public/index.html', ':data-testid="menuTestId(grandChild)"', 'third-level menu uses test id helper');
requireText('public/index.html', 'filterVisibleMenuItems(menuItems.value, user.value)', 'entry uses extracted visible menu filter');
requireText('public/system-static.js', 'const resolveMenuItems', 'system static module resolves menu config keys');
requireText('public/system-static.js', 'const filterVisibleMenuItems', 'system static module filters visible menu items');
requireText('public/index.html', 'buildHotelPlatformAccountRowStatic', 'entry uses extracted hotel platform account row builder');
requireText('public/system-static.js', 'const buildHotelPlatformAccountRow', 'system static builds hotel platform account rows');
requireText('public/system-static.js', "target: 'profile-login'", 'system static keeps profile login direct target metadata');
requireText('public/system-static.js', "target: 'sync-logs'", 'system static keeps sync logs direct target metadata');
requireText('public/index.html', "requireCtripStatic('runCtripBrowserCaptureFlow')", 'entry uses extracted Ctrip browser capture flow runner');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureTargetContext', 'Ctrip static builds browser capture target context');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCapturePayload', 'Ctrip static builds browser capture payloads');
requireText('public/ctrip-static.js', 'const buildCtripBrowserCaptureRequestContext', 'Ctrip static builds browser capture request context');
requireText('public/ctrip-static.js', 'const normalizeCtripBrowserCaptureErrorResult', 'Ctrip static normalizes browser capture errors');
requireText('public/ctrip-static.js', 'const runCtripBrowserCaptureFlow', 'Ctrip static runs browser capture flow');
requireText('public/index.html', "requireCtripStatic('runCtripFetchDataFlow')", 'entry uses extracted Ctrip fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripFetchDateRange', 'Ctrip static builds fetch date ranges');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestBody', 'Ctrip static builds fetch request bodies');
requireText('public/ctrip-static.js', 'const buildCtripFetchRequestContext', 'Ctrip static builds fetch request context');
requireText('public/ctrip-static.js', 'const runCtripFetchDataFlow', 'Ctrip static runs fetch flow');
requireText('public/index.html', "requireCtripStatic('buildLatestCtripSnapshotModel')", 'entry uses extracted Ctrip latest snapshot model builder');
requireText('public/ctrip-static.js', 'const buildLatestCtripSnapshotModel', 'Ctrip static builds latest snapshot models');
requireText('public/index.html', "requireCtripStatic('runCtripTrafficFetchFlow')", 'entry uses extracted Ctrip traffic fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripTrafficFetchRequestBody', 'Ctrip static builds traffic fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripTrafficFetchFlow', 'Ctrip static runs traffic fetch flow');
requireText('public/index.html', "requireCtripStatic('runCtripOverviewFetchFlow')", 'entry uses extracted Ctrip overview fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripOverviewFetchRequestBody', 'Ctrip static builds overview fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripOverviewFetchFlow', 'Ctrip static runs overview fetch flow');
requireText('public/index.html', "requireCtripStatic('runCtripAdsFetchFlow')", 'entry uses extracted Ctrip ads fetch flow runner');
requireText('public/ctrip-static.js', 'const buildCtripAdsFetchRequestBody', 'Ctrip static builds ads fetch request bodies');
requireText('public/ctrip-static.js', 'const runCtripAdsFetchFlow', 'Ctrip static runs ads fetch flow');
requireText('public/ctrip-static.js', 'const buildCtripCookieApiFetchRequestBody', 'Ctrip static builds Cookie API fetch request bodies');
requireText('public/index.html', "requireCtripStatic('runCtripCookieApiCaptureFlow')", 'entry uses extracted Ctrip Cookie API capture flow runner');
requireText('public/ctrip-static.js', 'const runCtripCookieApiCaptureFlow', 'Ctrip static runs Cookie API capture flow');
requireText('public/index.html', "requireCtripStatic('isCtripAdsApiUrl')", 'entry uses extracted Ctrip ads URL guard');
requireText('public/index.html', "requireCtripStatic('createCtripConfigForm')", 'entry uses extracted Ctrip config default form builder');
requireText('public/index.html', "requireCtripStatic('runCtripConfigSaveFlow')", 'entry uses extracted Ctrip config save flow runner');
requireText('public/ctrip-static.js', 'const createCtripConfigForm', 'Ctrip static builds config default forms');
requireText('public/ctrip-static.js', 'const buildCtripConfigSavePayload', 'Ctrip static builds config save payloads');
requireText('public/ctrip-static.js', 'const validateCtripConfigSaveInput', 'Ctrip static validates config save inputs');
requireText('public/ctrip-static.js', 'const runCtripConfigSaveFlow', 'Ctrip static runs config save flow');
requireText('public/index.html', "requireCtripStatic('createCtripProfileFieldForm')", 'entry uses extracted Ctrip Profile field default form builder');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldSmartDefaults')", 'entry uses extracted Ctrip Profile field smart defaults builder');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileFieldSavePayload')", 'entry uses extracted Ctrip Profile field save payload builder');
requireText('public/ctrip-static.js', 'const createCtripProfileFieldForm', 'Ctrip static builds Profile field default forms');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldSmartDefaults', 'Ctrip static builds Profile field smart defaults');
requireText('public/ctrip-static.js', 'const buildCtripProfileFieldSavePayload', 'Ctrip static builds Profile field save payloads');
requireText('public/index.html', "requireCtripStatic('buildCtripProfileRecheckRunContext')", 'entry uses extracted Ctrip Profile recheck run context builder');
requireText('public/index.html', "requireCtripStatic('runCtripProfileRecheckFlow')", 'entry uses extracted Ctrip Profile recheck flow runner');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckInitialState', 'Ctrip static builds Profile recheck initial state');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckRunContext', 'Ctrip static builds Profile recheck run context');
requireText('public/ctrip-static.js', 'const buildCtripProfileRecheckSuccessResult', 'Ctrip static builds Profile recheck success result');
requireText('public/ctrip-static.js', 'const runCtripProfileRecheckFlow', 'Ctrip static runs Profile recheck flow');
requireText('public/index.html', "requireMeituanStatic('runMeituanBatchFetchFlow')", 'entry uses extracted Meituan batch fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanTrafficFetchFlow')", 'entry uses extracted Meituan traffic fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanOrderFetchFlow')", 'entry uses extracted Meituan order fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanAdsFetchFlow')", 'entry uses extracted Meituan ads fetch flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanBrowserCaptureFlow')", 'entry uses extracted Meituan browser capture flow runner');
requireText('public/index.html', "requireMeituanStatic('runMeituanCapturedPayloadSaveFlow')", 'entry uses extracted Meituan captured payload save flow runner');
requireText('public/meituan-static.js', 'const buildMeituanBatchFetchTasks', 'Meituan static builds batch fetch tasks');
requireText('public/meituan-static.js', 'const buildMeituanDisplayModelPayload', 'Meituan static builds display model payloads');
requireText('public/meituan-static.js', 'const validateMeituanBatchFetchInput', 'Meituan static validates batch fetch inputs');
requireText('public/meituan-static.js', 'const runMeituanBatchFetchFlow', 'Meituan static runs batch fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanBrowserCaptureRequestContext', 'Meituan static builds browser capture request context');
requireText('public/meituan-static.js', 'const runMeituanBrowserCaptureFlow', 'Meituan static runs browser capture flow');
requireText('public/meituan-static.js', 'const buildMeituanCapturedPayloadSaveContext', 'Meituan static builds captured payload save context');
requireText('public/meituan-static.js', 'const runMeituanCapturedPayloadSaveFlow', 'Meituan static runs captured payload save flow');
requireText('public/meituan-static.js', 'const buildMeituanTrafficFetchRequestBody', 'Meituan static builds traffic fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanTrafficFetchFlow', 'Meituan static runs traffic fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanOrderFetchRequestBody', 'Meituan static builds order fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanOrderFetchFlow', 'Meituan static runs order fetch flow');
requireText('public/meituan-static.js', 'const buildMeituanAdsFetchRequestBody', 'Meituan static builds ads fetch request bodies');
requireText('public/meituan-static.js', 'const runMeituanAdsFetchFlow', 'Meituan static runs ads fetch flow');
requireNoText('public/index.html', '<script src="auto-fetch-static.js"></script>', 'frontend lazy-loads extracted auto-fetch static helper');
requireText('public/index.html', "const autoFetchStaticScript = 'auto-fetch-static.js'", 'entry keeps auto-fetch static lazy script path');
requireText('public/index.html', 'const ensureAutoFetchStaticReady = async () =>', 'entry keeps auto-fetch static ready guard');
requireText('public/index.html', "requireAutoFetchStatic('runAutoFetchTriggerFlow')", 'entry uses extracted auto-fetch trigger flow runner');
requireText('public/index.html', 'const loadAutoFetchPanel = async', 'entry keeps platform auto-fetch panel loader');
requireText('public/index.html', 'await ensureAutoFetchStaticReady();', 'entry gates auto-fetch actions on static helper readiness');
requireText('public/index.html', 'const schedulePostFetchRefresh =', 'entry defers post-fetch refresh work');
requireText('public/index.html', 'const AUTO_FETCH_PANEL_CACHE_TTL_MS', 'entry deduplicates platform auto-fetch panel loading');
requireText('public/index.html', "newTab === 'platform-auto'", 'entry lazy-loads platform auto-fetch panel only on tab entry');
requireNoText('public/index.html', 'await loadAutoFetchPanel()', 'platform-auto navigation and profile follow-up refreshes do not block on the full panel reload');
requireText('public/index.html', "runPageLoadOnce('online-data', 'platform-auto-panel', () => loadAutoFetchPanel({ force: true }), { force: true })", 'platform-auto navigation schedules full panel refresh without blocking first interaction');
requireText('public/index.html', 'deferUiTask(() => Promise.allSettled([\n                            loadPlatformProfileStatus({ silent: true }),\n                            loadAutoFetchPanel({ force: true }),', 'profile unbind refreshes platform profile and auto-fetch state in deferred work');
requireText('public/index.html', "const schedulePlatformDataSourcePanelLoad = (options = {}) => runPageLoadOnce(", 'platform source panel loads through the shared page-load scheduler');
requireText('public/index.html', "const openPlatformSourcesTab = (options = {}) =>", 'platform source tab opens through a single deduplicated entrypoint');
requireNoText('public/index.html', "onlineDataTab = 'platform-sources'; loadPlatformDataSourcePanel()", 'platform source tab switches do not double-trigger the heavy data-source panel load');
requireNoText('public/index.html', 'await loadPlatformDataSourcePanel();', 'platform source mutations do not block on full panel reload');
requireNoText('public/index.html', 'await Promise.all([loadPlatformDataSources(), loadPlatformSyncTasks(), loadPlatformSyncLogs(), loadPlatformCollectionResources(), loadOnlineDataList()]);', 'platform import completion defers heavy follow-up panel and list refreshes');
requireText('public/index.html', 'schedulePlatformDataSourcePanelLoad({ force: true });', 'platform source mutations schedule forced panel refresh after server writes');
requireNoText('public/index.html', "onlineDataTab = 'ctrip-fetch-settings'; loadCtripConfigList(); loadAutoFetchPanel()", 'Ctrip fetch settings does not load full platform auto-fetch panel');
requireNoText('public/index.html', "onlineDataTab = 'platform-sources'; loadPlatformDataSourcePanel(); loadPlatformProfileStatus({ silent: true })", 'platform sources tab does not duplicate profile status loading');
requireNoText('public/index.html', 'await loadAutoFetchPanel();\n                    return;\n                }\n                downloadCenterTab.value = tab;', 'download tab switch does not load full platform auto-fetch panel for Ctrip settings');
requireText('public/index.html', 'await loadAutoFetchStatus({ detail: false });\n                    scheduleAutoFetchStatusDetailRefresh();\n                    schedulePlatformProfileStatusRefresh({ silent: true });', 'platform auto-fetch first paint uses light status and defers detail/profile refresh');
requireText('public/index.html', "params.append('include_detail', '0');", 'platform auto-fetch status can request light backend status');
requireText('public/index.html', "const scheduleAutoFetchStatusRefresh = () => schedulePostFetchRefresh('auto-fetch-status', () => loadAutoFetchStatus({ detail: false }), 180);", 'post-fetch status refresh uses light auto-fetch status');
requireText('public/index.html', 'const autoFetchStatusRequestPromises = new Map();', 'entry deduplicates concurrent auto-fetch status requests');
requireText('public/index.html', "const requestKey = `${String(hotelId || '')}|${includeDetail ? 'full' : 'light'}`;", 'auto-fetch status request dedupe is scoped by hotel and detail level');
requireText('public/index.html', "loadAutoFetchStatus({ detail: normalizedMode === 'full' })", 'data-health light refresh uses light auto-fetch status');
requireText('public/index.html', 'ctrip_auto_fetch_mode: autoFetchMode.value', 'platform auto-fetch keeps Ctrip on the selected fast mode by default');
requireText('app/controller/OnlineData.php', "?? $options['auto_fetch_mode'];", 'backend auto-fetch defaults Ctrip mode to the selected auto-fetch mode');
requireText('app/controller/OnlineData.php', "get('include_detail'", 'backend auto-fetch status supports light detail requests');
requireText('app/controller/OnlineData.php', "'detail_loaded' => false", 'backend auto-fetch status marks light responses explicitly');
requireText('public/index.html', 'const buildDataHealthPanelJobs = (normalizedMode) =>', 'entry builds data-health panel jobs outside the main loader');
requireText('public/index.html', 'const scheduleDataHealthLightDiagnostics = () =>', 'entry defers non-core light data-health diagnostics through a helper');
requireText('public/index.html', 'const jobs = buildDataHealthPanelJobs(normalizedMode);', 'data-health panel loader uses extracted job composition');
requireText('public/index.html', 'scheduleDataHealthLightDiagnostics();', 'light data-health refresh defers non-core diagnostics after OTA health returns');
requireText('public/index.html', 'const ensureManualOnlineFetchConfigReady = async', 'entry prewarms saved platform configs for manual online-data fetch');
requireText('public/index.html', 'let ctripConfigListLoadingPromise = null;', 'entry deduplicates concurrent Ctrip config-list loads');
requireText('public/index.html', 'if (ctripConfigListLoadingPromise) {\n                    return ctripConfigListLoadingPromise;', 'Ctrip config-list loader reuses in-flight requests');
requireText('public/index.html', 'const ctripConfigDetailCache = new Map();', 'entry caches full Ctrip config details for manual-fetch hotel switching');
requireText('public/index.html', 'const ctripConfigDetailLoadingPromises = new Map();', 'entry deduplicates concurrent full Ctrip config detail loads');
requireText('public/index.html', "clearCtripConfigDetailCache(body?.id || '');", 'entry invalidates Ctrip config detail cache after manual config saves');
requireText('public/index.html', "item.path === 'online-data' && item.tab === 'data'", 'manual online-data tab prewarms saved platform configs without loading platform-auto panel');
requireText('public/index.html', 'const scheduleLatestCtripRefresh', 'entry defers latest Ctrip snapshot refresh after manual collection');
requireText('public/index.html', 'const scheduleDataHealthPanelRefresh', 'entry defers data-health refresh after manual collection');
requireText('public/index.html', 'const schedulePlatformProfileStatusRefresh', 'entry defers platform profile refresh after manual collection');
requireText('public/index.html', 'const schedulePlatformDataSourcesRefresh', 'entry defers platform data-source refresh after manual collection');
requireText('app/service/ManualOnlineFetchTaskService.php', 'final class ManualOnlineFetchTaskService', 'manual OTA background task creation and launch lives in a focused service');
requireText('app/service/ManualOnlineFetchTaskService.php', 'online-data:manual-fetch-once', 'manual OTA background task service launches the shared one-shot worker');
requireText('app/controller/OnlineData.php', "createTask('ctrip'", 'backend can run Ctrip manual fetch as a background task through the manual OTA task service');
requireText('app/controller/OnlineData.php', "createTask(strtolower($platform) . '_traffic'", 'backend can run Ctrip traffic manual fetch as a background task through the manual OTA task service');
requireText('app/controller/OnlineData.php', "createTask('ctrip_ads'", 'backend can run Ctrip ads manual fetch as a background task through the manual OTA task service');
requireText('app/controller/OnlineData.php', 'launchTask($task)', 'backend launches manual OTA fetch tasks through the manual OTA task service');
requireNoText('app/controller/OnlineData.php', 'private function createManualCtripFetchBackgroundTask', 'OnlineData does not re-inline Ctrip manual background task creation');
requireNoText('app/controller/OnlineData.php', 'private function launchManualCtripFetchBackgroundTask', 'OnlineData does not re-inline manual background task launching');
requireText('app/command/ManualFetchOnlineDataOnce.php', 'online-data:manual-fetch-once', 'manual Ctrip fetch has a one-shot background worker command');
requireText('config/console.php', "'online-data:manual-fetch-once'", 'console exposes one-shot manual Ctrip fetch worker command');
requireText('app/service/OnlineTrafficDataExtractionService.php', 'extractCtripTrafficRows', 'traffic response extraction lives in a focused service');
requireText('app/controller/OnlineData.php', 'OnlineTrafficDataExtractionService::extractCtripTrafficRows', 'OnlineData keeps a thin Ctrip traffic extraction wrapper');
requireNoText('app/controller/OnlineData.php', 'private function extractCtripTrafficRowsRecursive', 'OnlineData does not re-inline recursive Ctrip traffic extraction');
for (const directRefreshBinding of [
  'refreshLatestCtripData: loadLatestCtripData',
  'refreshLatestCtripData: params => loadLatestCtripData(params)',
  'refreshDataHealthPanel: loadDataHealthPanel',
  'refreshDataHealthPanel: (mode, params) => loadDataHealthPanel(mode, params)',
  'refreshPlatformProfileStatus: loadPlatformProfileStatus',
  'refreshPlatformProfileStatus: params => loadPlatformProfileStatus(params)',
  'refreshPlatformDataSources: loadPlatformDataSources',
  'refreshPlatformDataSources: () => loadPlatformDataSources()',
]) {
  requireNoText('public/index.html', directRefreshBinding, `entry avoids direct post-fetch refresh binding: ${directRefreshBinding}`);
}
requireNoText('public/ctrip-static.js', 'await refreshOnlineHistory();\n                await refreshLatestCtripData({ silent: true });', 'Ctrip manual fetch success does not block on history/latest snapshot refresh');
for (const flowFile of ['public/auto-fetch-static.js', 'public/ctrip-static.js', 'public/meituan-static.js']) {
  requireText(flowFile, 'const runPostFetchRefresh', `${flowFile} uses non-blocking post-fetch refresh helper`);
  requireNoText(flowFile, 'await refreshOnlineHistory(', `${flowFile} does not block collection completion on history refresh`);
  requireNoText(flowFile, 'await refreshLatestCtripData(', `${flowFile} does not block collection completion on latest Ctrip refresh`);
  requireNoText(flowFile, 'await refreshOnlineData(', `${flowFile} does not block collection completion on online data refresh`);
  requireNoText(flowFile, 'await loadAutoFetchStatus(', `${flowFile} does not block auto-fetch completion on status refresh`);
}
requireText('public/auto-fetch-static.js', 'const buildAutoFetchTriggerRequestBody', 'auto-fetch static builds trigger request bodies');
requireText('public/auto-fetch-static.js', 'const buildAutoFetchRunStartState', 'auto-fetch static builds trigger run start state');
requireText('public/auto-fetch-static.js', 'const runAutoFetchTriggerFlow', 'auto-fetch static runs manual trigger flow');
requireText('public/auto-fetch-static.js', 'const resolveDataConfigTestEndpoint', 'auto-fetch static resolves data-source test endpoints');
requireText('public/auto-fetch-static.js', 'const runDataConfigTestFlow', 'auto-fetch static runs data-source config test flow');
requireText('public/index.html', "requireAutoFetchStatic('runDataConfigTestFlow')", 'entry uses extracted data-source config test flow runner');
requireText('public/auto-fetch-static.js', 'async: true', 'auto-fetch trigger submits quickly and lets backend continue collection');
requireText('public/auto-fetch-static.js', "return { status: 'accepted'", 'auto-fetch trigger keeps backend queued state non-blocking');
requireText('public/index.html', 'async: true, ...buildAutoFetchModePayload()', 'retry auto-fetch submits quickly and lets backend continue collection');
requireText('public/index.html', "['running', 'queued', 'accepted'].includes(retryStatus)", 'retry auto-fetch treats backend queued state as non-blocking');
requireText('public/ctrip-static.js', 'const isCtripBackgroundAcceptedResponse', 'Ctrip static shares accepted/running/queued background response detection');
requireText('public/ctrip-static.js', 'const queuedRequestBody = { ...requestBody, async: true };', 'Ctrip manual fetch flows submit quickly and let backend continue collection');
requireText('public/ctrip-static.js', "return { status: 'accepted'", 'Ctrip manual fetch flows keep backend queued state non-blocking');
requireText('public/meituan-static.js', 'const requestBody = { ...task.body, async: true }', 'Meituan manual batch fetch submits quickly and lets backend continue collection');
requireText('public/meituan-static.js', "return { status: 'accepted'", 'Meituan manual batch fetch keeps backend queued state non-blocking');
requireText('app/controller/OnlineData.php', 'markAutoFetchRunningStatus', 'backend records running auto-fetch task status');
requireText('app/controller/OnlineData.php', 'createAutoFetchBackgroundTask', 'backend creates one-shot auto-fetch background tasks');
requireText('app/controller/OnlineData.php', "'/api/online-data/retry-auto-fetch'", 'backend retry auto-fetch posts the one-shot worker back to retry endpoint');
requireText('app/controller/OnlineData.php', "'retry_auto_fetch_queued'", 'backend records queued retry auto-fetch tasks');
requireText('app/controller/OnlineData.php', "createTask('meituan'", 'backend creates one-shot Meituan manual fetch background tasks through the manual OTA task service');
requireText('app/controller/OnlineData.php', "createTask('meituan_traffic'", 'backend creates one-shot Meituan traffic manual fetch background tasks through the manual OTA task service');
requireText('app/controller/OnlineData.php', "createTask('meituan_' . $section", 'backend creates one-shot Meituan order/ads manual fetch background tasks through the manual OTA task service');
requireNoText('app/controller/OnlineData.php', 'private function createManualMeituanFetchBackgroundTask', 'OnlineData does not re-inline Meituan manual background task creation');
requireText('app/command/AutoFetchOnlineDataOnce.php', 'online-data:auto-fetch-once', 'backend registers a one-shot auto-fetch worker command');
requireText('config/console.php', "'online-data:auto-fetch-once'", 'console exposes one-shot auto-fetch worker command');
requireText('public/index.html', "requireSystemStatic('getDefaultDataConfigForm')", 'entry uses extracted data config default form');
requireText('public/index.html', "requireSystemStatic('getDataConfigTypeDefaults')", 'entry uses extracted data config type defaults');
requireText('public/index.html', "requireSystemStatic('getSystemConfigDefaults')", 'entry uses extracted system config defaults');
requireText('public/index.html', "requireSystemStatic('createHotelForm')", 'entry uses extracted hotel form builder');
requireText('public/index.html', "requireSystemStatic('buildHotelSavePayload')", 'entry uses extracted hotel save payload builder');
requireText('public/system-static.js', 'const createLoginForm', 'system static builds login default forms');
requireText('public/system-static.js', 'const getRememberedLoginAccount', 'system static reads remembered login account and clears legacy password');
requireText('public/system-static.js', 'const buildLoginRequestPayload', 'system static builds login request payloads');
requireText('public/system-static.js', 'const validateLoginRequestPayload', 'system static validates login request payloads');
requireText('public/system-static.js', 'const applyRememberedLoginAccount', 'system static writes remembered login account without persisting passwords');
requireText('public/system-static.js', 'const createRegisterForm', 'system static builds register default forms');
requireText('public/system-static.js', 'const buildRegisterRequestPayload', 'system static builds register request payloads');
requireText('public/system-static.js', 'const validateRegisterRequestPayload', 'system static validates register request payloads');
requireText('public/system-static.js', 'const getDefaultDataConfigForm', 'system static builds data config default form');
requireText('public/system-static.js', 'const getDataConfigTypeDefaults', 'system static owns data config type defaults');
requireText('public/system-static.js', 'const getSystemConfigDefaults', 'system static owns system config defaults');
requireText('public/system-static.js', 'const createHotelForm', 'system static builds hotel admin forms');
requireText('public/system-static.js', 'const buildHotelSavePayload', 'system static builds hotel save payloads');
requireText('public/index.html', "requireSystemStatic('buildKnowledgeImportRequestBody')", 'entry uses extracted knowledge import request body builder');
requireText('public/index.html', "requireSystemStatic('knowledgeImportSuccessMessage')", 'entry uses extracted knowledge import success message');
requireText('public/index.html', "requireSystemStatic('knowledgeImportErrorMessage')", 'entry uses extracted knowledge import error message');
requireText('public/system-static.js', 'const buildKnowledgeImportRequestBody', 'system static builds knowledge import request body');
requireText('public/system-static.js', 'const knowledgeImportSuccessMessage', 'system static formats knowledge import success message');
requireText('public/system-static.js', 'const knowledgeImportErrorMessage', 'system static formats knowledge import error message');
requireNoText('public/index.html', "hotelForm.value = { id: null, name: '', code: getNextHotelCode()", 'hotel create defaults are not re-inlined in the SPA entry');
requireNoText('public/index.html', 'name: hotelForm.value.name.trim(),\n                    code: normalizedCode,', 'hotel save payload is not re-inlined in the SPA entry');
requireNoText('public/index.html', "successCount = Number(res.data?.success_count", 'knowledge import success message is not re-inlined in the SPA entry');
requireNoText('public/index.html', "error.name === 'AbortError'", 'knowledge import abort message is not re-inlined in the SPA entry');
{
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const helpers = context.window.SUXI_SYSTEM_STATIC;
  const payload = helpers.buildKnowledgeImportRequestBody({
    form: { mode: 'document', source: '', hotel_id: '12', model_key: '' },
    tags: ['OTA', '复盘'],
    raw: '经营资料',
  });
  checks.push({
    file: 'public/system-static.js',
    label: 'knowledge import helper preserves request and message semantics',
    ok: payload.mode === 'document'
      && payload.source === 'document'
      && payload.hotel_id === 12
      && payload.model_key === 'deepseek_chat'
      && payload.raw === '经营资料'
      && payload.tags.length === 2
      && helpers.knowledgeImportSuccessMessage({ success_count: 3, error_count: 1 }).includes('失败 1 条')
      && helpers.knowledgeImportErrorMessage({ name: 'AbortError' }).includes('超过90秒'),
    detail: 'knowledge import helper must keep the original request defaults and explicit timeout message',
  });
}
requireText('public/index.html', "requireDataHealthStatic('buildOnlineAnalysisChartConfig')", 'entry uses extracted online analysis chart config');
requireText('public/data-health-static.js', 'const buildOnlineAnalysisChartConfig', 'data-health static builds online analysis chart config');
requireText('public/index.html', 'new ChartLib(ctx, buildOnlineAnalysisChartConfig(analysisData.value.chart_data))', 'analysis chart rendering keeps only lifecycle wiring in the SPA entry');
requireNoText('public/index.html', "text: '销售额(¥)'", 'analysis chart axis labels are not re-inlined in the SPA entry');
{
  const context = { window: {} };
  vm.runInNewContext(read('public/data-health-static.js'), context, {
    filename: 'public/data-health-static.js',
  });
  const chartData = { labels: ['2026-06-11'], datasets: [{ label: 'OTA销售额', data: [100] }] };
  const config = context.window.SUXI_DATA_HEALTH_STATIC.buildOnlineAnalysisChartConfig(chartData);
  checks.push({
    file: 'public/data-health-static.js',
    label: 'online analysis chart config preserves chart data and axis semantics',
    ok: config?.type === 'line'
      && config?.data === chartData
      && config?.options?.scales?.y?.title?.text === '销售额(¥)'
      && config?.options?.scales?.y1?.title?.text === '房晚/订单'
      && config?.options?.scales?.y1?.grid?.drawOnChartArea === false,
    detail: 'buildOnlineAnalysisChartConfig must keep original Chart.js line config semantics',
  });
}
requireText('public/index.html', ':data-testid="pageTestId(currentPage)"', 'active page container exposes current page test id');
requireText('public/index.html', "const testIdStaticScript = 'testid-static.js'", 'frontend lazy-loads extracted test id helper');
requireText('public/index.html', 'const loadTestIdStatic = () =>', 'entry keeps explicit test id helper lazy loader');
requireText('public/index.html', 'createPageTestIdController', 'entry wires extracted page test id controller after lazy load');
requireText('public/index.html', 'const pageTestId = (page) =>', 'entry keeps page test id available before helper loads');
requireText('public/testid-static.js', 'assignPageControlTestIds', 'page controls receive generated stable test ids');
requireText('public/testid-static.js', 'normalizeTestIdSegment', 'test id helper keeps stable segment normalization');
requireText('public/index.html', 'buildGlobalNotifications({', 'entry uses extracted global notification builder');
requireText('public/notification-static.js', 'const buildGlobalNotifications', 'notification static builds global notification rows');
requireText('app/controller/SystemNotificationController.php', 'visibleNotificationIdsForCurrentUser', 'system notification bulk actions use DB-scoped visible ID query');
requireText('app/controller/SystemNotificationController.php', 'notification_state.is_cleared IS NULL OR notification_state.is_cleared <> 1', 'system notification bulk actions filter per-user cleared state in SQL');
requireNoText('app/controller/SystemNotificationController.php', 'filterRowsByCurrentUserState', 'system notification bulk actions do not reintroduce full-list PHP state filtering');
{
  const source = read('app/controller/SystemConfigController.php');
  const requestedKeyOffset = source.indexOf("$requestedKey = trim((string)$this->request->get('key', ''))");
  const firstFullConfigOffset = source.indexOf('$configs = SystemConfig::getAllConfigs();');
  checks.push({
    file: 'app/controller/SystemConfigController.php',
    label: 'system config single-key reads avoid full config scan',
    ok: requestedKeyOffset >= 0
      && firstFullConfigOffset > requestedKeyOffset
      && source.slice(requestedKeyOffset, firstFullConfigOffset).includes('SystemConfig::getValue($requestedKey'),
    detail: 'requested key branch must return before getAllConfigs',
  });
  checks.push({
    file: 'app/controller/SystemConfigController.php',
    label: 'system config public scope reads only public keys',
    ok: source.includes("request->get('scope', '')")
      && source.includes("SystemConfig::getConfigsByKeys($publicKeys)")
      && source.indexOf("SystemConfig::getConfigsByKeys($publicKeys)") < firstFullConfigOffset,
    detail: 'public scope must return before getAllConfigs',
  });
}
requireText('app/model/SystemConfig.php', 'public static function getConfigsByKeys(array $keys): array', 'system config model supports bounded key reads');
requireNoText('public/index.html', 'const isItemVisible = (item) => {', 'visible menu permission filter is not re-inlined');
requireNoText('public/index.html', 'const platformNextActionMeta =', 'platform next action metadata is not re-inlined');
requireNoText('public/index.html', 'const platformAccountStoreText =', 'platform account store text is not re-inlined');
requireNoText('public/index.html', 'const hotelId = String(\n                    form.hotelId', 'Ctrip browser capture hotel id resolution is not re-inlined');
requireNoText('public/index.html', "cookies: activeConfig?.cookies || activeConfig?.cookie || '',", 'Ctrip browser capture cookie payload is not re-inlined');
requireNoText('public/index.html', 'const optionSections = options.sections || options.captureSections ||', 'Ctrip browser capture section normalization is not re-inlined');
requireNoText('public/index.html', 'const normalizeCtripBrowserCaptureErrorResult = (error) => {', 'Ctrip browser capture error normalization is not re-inlined');
requireNoText('public/index.html', 'const targetContext = buildCtripBrowserCaptureTargetContext({', 'Ctrip browser capture target context flow is not re-inlined');
requireNoText('public/index.html', 'const requestContext = buildCtripBrowserCaptureRequestContext({', 'Ctrip browser capture request context flow is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/capture-ctrip-browser', {", 'Ctrip browser capture request flow is not re-inlined');
requireNoText('public/index.html', 'ctripBrowserCaptureResult.value = normalizeCtripBrowserCaptureErrorResult(e);', 'Ctrip browser capture catch flow is not re-inlined');
requireNoText('public/index.html', 'const cookies = ctripForm.value.cookies.trim();', 'Ctrip fetch credential trim is not re-inlined');
requireNoText('public/index.html', 'const nodeId = String(ctripForm.value.nodeId || \'\').trim();', 'Ctrip fetch node id normalization is not re-inlined');
requireNoText('public/index.html', 'const { startDate, endDate } = buildCtripFetchDateRange(ctripForm.value);', 'Ctrip fetch date range construction is not re-inlined');
requireNoText('public/index.html', 'const yesterday = new Date();', 'Ctrip fetch default date calculation is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = {', 'Ctrip fetch request body is not re-inlined');
requireNoText('public/index.html', 'const ctripFetchBody = buildCtripFetchRequestBody({', 'Ctrip fetch request body helper call is not re-inlined');
requireNoText('public/index.html', 'raw: rawResponse.substring(0, 1000)', 'Ctrip fetch raw failure result is not re-inlined');
requireNoText('public/index.html', 'const requestContext = buildCtripFetchRequestContext({', 'Ctrip fetch request context flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = selectCtripFetchResponsePayload(res.data || {});', 'Ctrip fetch success result flow is not re-inlined');
requireNoText('public/index.html', 'const currentFetchMeta = buildCtripFetchMeta({', 'Ctrip fetch meta flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = buildCtripFetchRawFailureResult({', 'Ctrip fetch raw failure flow is not re-inlined');
requireNoText('public/index.html', 'const rankRows = payload?.rank?.rows || [];', 'Ctrip latest snapshot row slicing is not re-inlined');
requireNoText('public/index.html', 'const trafficUrl = String(form.url || \'\').trim();', 'Ctrip traffic request URL trimming is not re-inlined');
requireNoText('public/index.html', 'const ctripTrafficFetchBody = {', 'Ctrip traffic request body is not re-inlined');
requireNoText('public/index.html', 'const ctripTrafficFetchBody = buildCtripTrafficFetchRequestBody({', 'Ctrip traffic request flow is not re-inlined');
requireNoText('public/index.html', 'const trafficModel = buildCtripTrafficResponseModel(res.data || {});', 'Ctrip traffic response flow is not re-inlined');
requireNoText('public/index.html', 'onlineDataResult.value = trafficModel.onlineResult;', 'Ctrip traffic success result write is not re-inlined');
requireNoText('public/index.html', 'const createCtripProfileFieldForm = () => ({', 'Ctrip Profile field default form is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileSimpleHash = (value) => {', 'Ctrip Profile field key hashing is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileEndpointFromUrl = (url) => {', 'Ctrip Profile endpoint parsing is not re-inlined');
requireNoText('public/index.html', 'const buildCtripProfileFieldSmartDefaults = (source =', 'Ctrip Profile smart defaults are not re-inlined');
requireNoText('public/index.html', 'const buildCtripProfileFieldSavePayload = () => {', 'Ctrip Profile save payload builder is not re-inlined');
requireNoText('public/index.html', 'decoded_data: decoded,', 'Ctrip traffic response model is not re-inlined');
requireNoText('public/index.html', 'request_urls: form.requestUrls,', 'Ctrip overview request body is not re-inlined');
requireNoText('public/index.html', 'request_urls: requestUrls,', 'Ctrip flow overview request body is not re-inlined');
requireNoText('public/index.html', "const requestUrls = form.requestUrls || ctripFlowOverviewDefaultRequestUrls.join('\\n');", 'Ctrip flow overview default URL selection is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'POST',", 'Ctrip overview request method fallback is not re-inlined');
requireNoText('public/index.html', "method: form.method || 'GET',", 'Ctrip flow overview request method fallback is not re-inlined');
requireNoText('public/index.html', "const defaultCtripAdsEffectReportUrl = 'https://", 'Ctrip ads default URL is not re-inlined');
requireNoText('public/index.html', 'const isCtripAdsApiUrl = (url = \'\') => {', 'Ctrip ads URL guard is not re-inlined');
requireNoText('public/index.html', 'api_type: normalizeCtripAdsApiType(form.apiType),', 'Ctrip ads request body is not re-inlined');
requireNoText('public/index.html', 'const ctripAdsFetchBody = buildCtripAdsFetchRequestBody({', 'Ctrip ads request flow is not re-inlined');
requireNoText('public/index.html', 'const url = String(form.url || defaultCtripAdsEffectReportUrl).trim();', 'Ctrip ads URL selection is not re-inlined');
requireNoText('public/index.html', "const cookies = String(form.cookies || ctripForm.value.cookies || activeConfig.cookies || '').trim();", 'Ctrip ads cookie selection is not re-inlined');
requireNoText('public/index.html', 'profile_id: cookieApiProfileId,', 'Ctrip Cookie API request body is not re-inlined');
requireNoText('public/index.html', "method: String(ctripCookieApiForm.value.method || 'GET').toUpperCase(),", 'Ctrip Cookie API request method normalization is not re-inlined');
requireNoText('public/index.html', "payload_json: String(ctripCookieApiForm.value.payloadJson || '').trim(),", 'Ctrip Cookie API payload trimming is not re-inlined');
requireNoText('public/index.html', 'const requestUrl = String(ctripCookieApiForm.value.requestUrl || \'\').trim();', 'Ctrip Cookie API request source validation is not re-inlined');
requireNoText('public/index.html', 'const cookies = String(ctripCookieApiForm.value.cookies || activeConfig?.cookies || activeConfig?.cookie || \'\').trim();', 'Ctrip Cookie API cookie source selection is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-ctrip-cookie-api', {", 'Ctrip Cookie API capture request flow is not re-inlined');
requireNoText('public/index.html', 'const ctripProfileFieldRecheckSections = (fields = []) => {', 'Ctrip Profile recheck section builder is not re-inlined');
requireNoText('public/index.html', 'const canRecapture = Boolean(selectedCtripHotelId.value || autoFetchHotelId.value || user.value?.hotel_id);', 'Ctrip Profile recheck recapture guard is not re-inlined');
requireNoText('public/index.html', 'body: JSON.stringify({\n                            sections,', 'Ctrip Profile recheck request options are not re-inlined');
requireNoText('public/index.html', 'const captureRes = await runCtripBrowserCapture({', 'Ctrip Profile recheck browser capture flow is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/recheck-ctrip-profile-mismatched-fields', requestOptions);", 'Ctrip Profile recheck request flow is not re-inlined');
requireNoText('public/index.html', 'const recheckResult = buildCtripProfileRecheckSuccessResult({', 'Ctrip Profile recheck success handling is not re-inlined');
requireNoText('public/index.html', 'const recheckResult = buildCtripProfileRecheckErrorResult({', 'Ctrip Profile recheck error handling is not re-inlined');
requireNoText('public/index.html', 'buildCtripProfileRecheckInterruptedState({', 'Ctrip Profile recheck interrupted handling is not re-inlined');
requireNoText('public/index.html', 'if (!ctripConfigForm.value.name) {', 'Ctrip config save validation is not re-inlined');
requireNoText('public/index.html', 'id: ctripConfigForm.value.id,', 'Ctrip config save payload is not re-inlined');
requireNoText('public/index.html', "console.error('携程配置保存失败:', res?.message || res?.msg || '接口返回异常');", 'Ctrip config save failed response handling is not re-inlined');
requireNoText('public/index.html', "const errData = await e.response.json();", 'Ctrip config save response error parsing is not re-inlined');
requireNoText('public/index.html', 'const batchInput = validateMeituanBatchFetchInput({', 'Meituan batch fetch validation flow is not re-inlined');
requireNoText('public/index.html', 'const fetchTasks = buildMeituanBatchFetchTasks({', 'Meituan batch fetch task flow is not re-inlined');
requireNoText('public/index.html', 'results.push(buildMeituanBatchFetchResultEntry(task, res));', 'Meituan batch fetch result flow is not re-inlined');
requireNoText('public/index.html', 'body: JSON.stringify(buildMeituanDisplayModelPayload({ results, form: meituanForm.value }))', 'Meituan display model payload flow is not re-inlined');
requireNoText('public/index.html', "meituanTrafficForm.value.url = (meituanTrafficForm.value.url || '').trim();", 'Meituan traffic URL trim is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-traffic', {", 'Meituan traffic request flow is not re-inlined');
requireNoText('public/index.html', 'latestTrafficData.value = res.data.data;', 'Meituan traffic success result write is not re-inlined');
requireNoText('public/index.html', '获取成功！已保存 ${savedCount} 条流量数据', 'Meituan traffic success toast is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-orders', {", 'Meituan order request flow is not re-inlined');
requireNoText('public/index.html', "form.url.includes('/order-eb/index.html')", 'Meituan order page URL guard is not re-inlined');
requireNoText('public/index.html', 'meituanOrderResult.value = res.data || {};', 'Meituan order success result write is not re-inlined');
requireNoText('public/index.html', '订单数据获取成功，已入库 ${savedCount} 条', 'Meituan order success toast is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/fetch-meituan-ads', {", 'Meituan ads request flow is not re-inlined');
requireNoText('public/index.html', "form.url.includes('/shopdiy/account/pcCpcEntry')", 'Meituan ads page URL guard is not re-inlined');
requireNoText('public/index.html', 'meituanAdsResult.value = res.data || {};', 'Meituan ads success result write is not re-inlined');
requireNoText('public/index.html', '广告数据获取成功，已入库 ${savedCount} 条', 'Meituan ads success toast is not re-inlined');
requireNoText('public/index.html', 'const prefix = captureSucceeded', 'Ctrip Profile recheck result message is not re-inlined');
requireNoText('public/index.html', "message: '重抓流程已结束，但字段列表在执行中被刷新；请查看当前获取值状态或再次重抓。'", 'Ctrip Profile recheck interrupted state is not re-inlined');
requireNoText('public/index.html', 'const allRankTypes = [', 'Meituan batch rank type list is not re-inlined');
requireNoText('public/index.html', 'const rankTypeNames = {', 'Meituan batch rank labels are not re-inlined');
requireNoText('public/index.html', 'const missingResourceFields = [];', 'Meituan batch fetch input validation is not re-inlined');
requireNoText('public/index.html', "meituanForm.value.dateRanges.includes('custom')", 'Meituan batch custom-date validation is not re-inlined');
requireNoText('public/index.html', 'display_hotels: results.flatMap', 'Meituan display model payload is not re-inlined');
requireNoText('public/index.html', 'const systemHotelId = meituanForm.value.hotelId || autoFetchHotelId.value || user.value?.hotel_id || null;', 'Meituan browser capture target context is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/capture-meituan-browser', {", 'Meituan browser capture request flow is not re-inlined');
requireNoText('public/index.html', 'login_only: loginOnly,', 'Meituan browser capture login-only payload is not re-inlined');
requireNoText('public/index.html', 'meituanBrowserCaptureResult.value = e?.data?.data || { error: e.message };', 'Meituan browser capture exception result is not re-inlined');
requireNoText('public/index.html', 'const rawJson = String(meituanBrowserCaptureForm.value.payloadJson || \'\').trim();', 'Meituan captured payload JSON trimming is not re-inlined');
requireNoText('public/index.html', 'payload = JSON.parse(rawJson);', 'Meituan captured payload JSON parsing is not re-inlined');
requireNoText('public/index.html', 'payload.store_id = payload.store_id || storeId || poiId;', 'Meituan captured payload enrichment is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/save-meituan-captured-data', {", 'Meituan captured payload save request flow is not re-inlined');
requireNoText('public/index.html', "const body = { system_hotel_id: hotelId, data_period: 'realtime_snapshot'", 'auto-fetch trigger request body is not re-inlined');
requireNoText('public/index.html', '已提交后端执行。${autoFetchCtripExecutionText.value}', 'auto-fetch trigger start state is not re-inlined');
requireNoText('public/index.html', 'await openCtripProfileFieldsForReview();', 'auto-fetch trigger success refresh flow is not re-inlined');
requireNoText('public/index.html', 'const getDefaultDataConfigForm = () => ({', 'data config default form is not re-inlined');
requireNoText('public/index.html', 'const getDataConfigTypeDefaults = (type) => ({', 'data config type defaults are not re-inlined');
requireNoText('public/index.html', "system_description: '授权OTA数据驱动的经营诊断、AI建议与动作复盘系统'", 'system config defaults are not re-inlined');
requireNoText('public/index.html', 'const rows = [...globalNotificationBackendItems.value];', 'global notification row aggregation is not re-inlined');
requireNoText('public/index.html', 'autoFetchRecentRuns.value.slice(0, 3).forEach', 'global notification recent-run loop is not re-inlined');
requireNoText('public/index.html', 'const readSet = new Set(globalNotificationReadIds.value);', 'global notification read-set mapping is not re-inlined');
requireText('public/index.html', 'history-strategy-reuse', 'strategy history reuse button has stable selector');
requireText('public/index.html', 'history-simulation-reuse', 'simulation history reuse button has stable selector');
requireText('public/index.html', 'history-expansion-reuse', 'expansion history reuse button has stable selector');
requireText('public/index.html', 'history-transfer-reuse', 'transfer history reuse button has stable selector');
requireText('public/index.html', 'field-strategy-city', 'strategy city field has stable selector');
requireText('public/index.html', 'field-simulation-adr', 'simulation ADR field has stable selector');
requireText('public/index.html', 'field-market-business-area', 'market business area field has stable selector');
requireText('public/index.html', 'field-transfer-pricing-', 'transfer pricing fields have stable selectors');
requireText('public/index.html', "requireSimulationStatic('buildTransferDecisionLayerRows')", 'entry uses extracted transfer decision layer builder');
requireText('public/simulation-static.js', 'const buildTransferDecisionLayerRows', 'simulation static builds transfer decision layer rows');
requireNoText('public/index.html', 'const pricingReady = !!transferPricingResult.value;', 'transfer decision pricing ready state is not re-inlined');
requireNoText('public/index.html', "label: '事实数据',\n                        status: snapshot ? '有快照' : '待取数'", 'transfer decision fact row is not re-inlined');
requireNoText('public/index.html', "evidence: `定价 ${pricingReady ? '有' : '无'} / 时机 ${timingReady ? '有' : '无'}`", 'transfer decision calculation evidence is not re-inlined');
requireTextInFiles(['public/index.html', 'public/ota-diagnosis-static.js'], 'result.diagnosis_sections', 'OTA diagnosis UI renders backend-provided diagnosis sections');
requireText('public/index.html', "requireOtaDiagnosisStatic('runOtaDiagnosisHotelFetchFlow')", 'entry uses extracted OTA diagnosis fetch flow runner');
requireText('public/index.html', "requireOtaDiagnosisStatic('runOtaDiagnosisGenerateFlow')", 'entry uses extracted OTA diagnosis generate flow runner');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchContext', 'OTA diagnosis static builds fetch context');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisFetchTasks', 'OTA diagnosis static builds fetch tasks');
requireText('public/ota-diagnosis-static.js', 'const runOtaDiagnosisHotelFetchFlow', 'OTA diagnosis static runs fetch flow');
requireText('public/ota-diagnosis-static.js', 'const buildOtaDiagnosisGenerateRequestBody', 'OTA diagnosis static builds generate request bodies');
requireText('public/ota-diagnosis-static.js', 'const runOtaDiagnosisGenerateFlow', 'OTA diagnosis static runs generate flow');
requireNoText('public/index.html', '<script src="ai-analysis-static.js"></script>', 'frontend lazy-loads extracted AI analysis static helper');
requireText('public/index.html', "const aiAnalysisStaticScript = 'ai-analysis-static.js'", 'entry keeps AI analysis static lazy script path');
requireText('public/index.html', 'const ensureAiAnalysisStaticReady = async () =>', 'entry keeps AI analysis static ready guard');
requireText('public/index.html', "if (tab === 'ai')", 'download center AI tab is the OTA AI static loading boundary');
requireText('public/index.html', "runPageLoadOnce(currentPage.value || 'online-data', 'ai-analysis-static'", 'online analysis tab lazy-loads AI analysis static helper');
requireText('public/index.html', 'await ensureAiAnalysisStaticReady();', 'entry gates AI analysis actions on static helper readiness');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryRequestBody')", 'entry uses extracted AI analysis summary request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisStartContext')", 'entry uses extracted AI analysis start context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaAnalysisRunContext')", 'entry uses extracted AI analysis run context builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildCapturedOtaSummaryResponseResult')", 'entry uses extracted AI analysis summary response builder');
requireText('public/index.html', "requireAiAnalysisStatic('runCapturedOtaAnalysisExecution')", 'entry uses extracted captured OTA AI analysis execution runner');
requireText('public/index.html', "requireAiAnalysisStatic('runCapturedOtaAnalysisStartFlow')", 'entry uses extracted captured OTA AI analysis start flow runner');
requireText('public/index.html', "requireAiAnalysisStatic('buildCtripAiAnalysisHotelSelection')", 'entry uses extracted Ctrip AI analysis hotel selection builder');
requireText('public/index.html', "requireAiAnalysisStatic('sanitizeAiReportHtml')", 'entry uses extracted AI report sanitizer');
requireText('public/index.html', "requireAiAnalysisStatic('aiReportHtmlToText')", 'entry uses extracted AI report text converter');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHotelList')", 'entry uses extracted Meituan AI hotel list builder');
requireText('public/index.html', "requireAiAnalysisStatic('resolveMeituanAiSelectedData')", 'entry uses extracted Meituan AI selection resolver');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisRequestBody')", 'entry uses extracted Meituan AI request builder');
requireText('public/index.html', "requireAiAnalysisStatic('buildMeituanAiAnalysisHistoryRecord')", 'entry uses extracted Meituan AI history builder');
requireText('public/index.html', "requireAiAnalysisStatic('runMeituanAiAnalysisFlow')", 'entry uses extracted Meituan AI analysis flow runner');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaHotelPayload', 'AI analysis static builds captured OTA hotel payloads');
requireText('public/ai-analysis-static.js', 'const buildCtripAiAnalysisHotelSelection', 'AI analysis static builds Ctrip hotel selections');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunPlan', 'AI analysis static builds captured OTA run plans');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisStartContext', 'AI analysis static builds captured OTA start context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisRunContext', 'AI analysis static builds captured OTA run context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaGroupOutcome', 'AI analysis static builds captured OTA group outcomes');
requireText('public/ai-analysis-static.js', 'const applyCapturedOtaGroupRunState', 'AI analysis static applies captured OTA group state updates');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryRequestBody', 'AI analysis static builds captured OTA summary requests');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryContext', 'AI analysis static builds captured OTA summary context');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaSummaryResponseResult', 'AI analysis static builds captured OTA summary response results');
requireText('public/ai-analysis-static.js', 'const buildCapturedFallbackSummaryReport', 'AI analysis static builds fallback summary reports');
requireText('public/ai-analysis-static.js', 'const runCapturedOtaAnalysisExecution', 'AI analysis static runs captured OTA analysis execution');
requireText('public/ai-analysis-static.js', 'const runCapturedOtaAnalysisStartFlow', 'AI analysis static runs captured OTA analysis start flow');
requireText('public/ai-analysis-static.js', 'const resolveAiSelectedData', 'AI analysis static resolves selected hotel rows');
requireText('public/ai-analysis-static.js', 'const validateCapturedOtaAiAnalysisStart', 'AI analysis static validates analysis start inputs');
requireText('public/ai-analysis-static.js', 'const buildCapturedOtaAnalysisCompletion', 'AI analysis static builds captured OTA completion state');
requireText('public/ai-analysis-static.js', 'const sanitizeAiReportHtml', 'AI analysis static sanitizes report HTML');
requireText('public/ai-analysis-static.js', 'const aiReportHtmlToText', 'AI analysis static converts report HTML to text');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHotelList', 'AI analysis static builds Meituan hotel selections');
requireText('public/ai-analysis-static.js', 'const resolveMeituanAiSelectedData', 'AI analysis static resolves Meituan selected hotels');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisRequestBody', 'AI analysis static builds Meituan AI request bodies');
requireText('public/ai-analysis-static.js', 'const buildMeituanAiAnalysisHistoryRecord', 'AI analysis static builds Meituan AI history records');
requireText('public/ai-analysis-static.js', 'const validateMeituanAiAnalysisStart', 'AI analysis static validates Meituan AI analysis start');
requireText('public/ai-analysis-static.js', 'const runMeituanAiAnalysisFlow', 'AI analysis static runs Meituan AI analysis flow');
requireNoText('public/index.html', 'const pushOtaDiagnosisFetchTask = (tasks, task) => {', 'OTA diagnosis task push helper is not re-inlined');
requireNoText('public/index.html', 'const fetchContext = buildOtaDiagnosisFetchContext({', 'OTA diagnosis fetch context construction is not re-inlined');
requireNoText('public/index.html', 'tasks.push(...buildOtaDiagnosisFetchTasks({', 'OTA diagnosis fetch task construction is not re-inlined');
requireNoText('public/index.html', 'const genericCtripCookie = String(fetchContext.ctripCookieApiCookies || \'\').trim()', 'OTA diagnosis generic Ctrip Cookie selection is not re-inlined');
requireNoText('public/index.html', 'let useCtripCorePresetForDiagnosis = false;', 'OTA diagnosis core preset decision is not re-inlined');
requireNoText('public/index.html', 'const success = results.filter(item => item.success).length;', 'OTA diagnosis fetch result summary is not re-inlined');
requireNoText('public/index.html', "['P_RZ', 'P_XS', 'P_ZH', 'P_LL'].forEach(rankType => {", 'OTA diagnosis Meituan task list is not re-inlined');
requireNoText('public/index.html', "const res = await request('/agent/ota-diagnosis', {", 'OTA diagnosis generate request flow is not re-inlined');
requireNoText('public/index.html', "const conclusion = String(data.diagnosis?.summary || data.core_conclusion || '');", 'OTA diagnosis empty-result detection is not re-inlined');
requireNoText('public/index.html', 'OTA数据同步完成，${fetchSummary.failed} 项失败', 'OTA diagnosis fetch failure warning is not re-inlined');
requireNoText('public/index.html', 'hotel_id: diagnosisHotelId || 0,', 'OTA diagnosis generate request body is not re-inlined');
requireNoText('public/index.html', 'const aiAnalysisStatusText = (status) => {', 'AI analysis status text helper is not re-inlined');
requireNoText('public/index.html', 'const chunkArray = (items, size) => {', 'AI analysis chunk helper is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaHotelPayload = (hotel) => {', 'AI analysis captured payload builder is not re-inlined');
requireNoText('public/index.html', "const key = (h.hotelId || h.id) + '_' + (h.hotelName || h.name);", 'Ctrip AI analysis hotel selection is not re-inlined');
requireNoText('public/index.html', "const key = h.poiId + '_' + h.hotelName;", 'Meituan AI analysis hotel key building is not re-inlined');
requireNoText('public/index.html', 'existing.amountRank = existing.amountRank === 0 ?', 'Ctrip AI analysis rank merge is not re-inlined');
requireNoText('public/index.html', 'const hotelsPayload = selectedData.map(buildCapturedOtaHotelPayload)', 'AI analysis run plan is not re-inlined');
requireNoText('public/index.html', 'const groupSize = isDeepSeekProAnalysisModel() ? 3 : 5;', 'AI analysis group sizing is not re-inlined');
requireNoText('public/index.html', 'const selectedData = resolveAiSelectedData(aiSelectedHotels.value, aiAnalysisHotelList.value);', 'AI analysis selected data resolution is not re-inlined');
requireNoText('public/index.html', 'const startValidation = validateCapturedOtaAiAnalysisStart({', 'AI analysis start validation context is not re-inlined');
requireNoText('public/index.html', 'const runPlan = buildCapturedOtaAnalysisRunPlan({', 'AI analysis run plan context is not re-inlined');
requireNoText('public/index.html', 'aiSelectedHotels.value.map(key => {', 'AI selected hotel lookup is not re-inlined');
requireNoText('public/index.html', 'if (aiSelectedHotels.value.length === 0) {', 'AI selected hotel start validation is not re-inlined');
requireNoText('public/index.html', 'if (!onlineDataFilter.value.start_date || !onlineDataFilter.value.end_date) {', 'AI date range start validation is not re-inlined');
requireNoText('public/index.html', 'if (onlineDataFilter.value.start_date > onlineDataFilter.value.end_date) {', 'AI date order start validation is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisHistory.value.unshift(buildAiAnalysisHistoryRecord({', 'AI analysis completion history is not re-inlined');
requireNoText('public/index.html', 'if (aiAnalysisHistory.value.length > 10) {', 'AI analysis history trim is not re-inlined');
requireNoText('public/index.html', "item.status === 'success' && item.result", 'AI group success filtering is not re-inlined');
requireNoText('public/index.html', "item.status === 'failed' || item.error", 'AI group failure filtering is not re-inlined');
requireNoText('public/index.html', 'failedGroups.map(item => `第 ${item.group_index} 组：', 'AI group failure reason is not re-inlined');
requireNoText('public/index.html', 'groupState.result = result.result;', 'AI group success result update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += group.length;', 'AI group success count update is not re-inlined');
requireNoText('public/index.html', 'groupState.error = result.error;', 'AI group failure state update is not re-inlined');
requireNoText('public/index.html', 'aiAnalysisProgress.value.completedHotels += retryResult.successCount;', 'AI retry completed count update is not re-inlined');
requireNoText('public/index.html', 'for (let index = 0; index < groups.length; index++) {', 'AI captured OTA group execution loop is not re-inlined');
requireNoText('public/index.html', 'if (summaryRes.code === 200) {', 'AI summary success response handling is not re-inlined');
requireNoText('public/index.html', 'const summaryData = summaryRes.data || {};', 'AI summary data extraction is not re-inlined');
requireNoText('public/index.html', "reason: summaryRes.message || '汇总失败'", 'AI summary fallback response handling is not re-inlined');
requireNoText('public/index.html', 'selectedCount: hotelsPayload.length,', 'AI summary selected count context is not re-inlined');
requireNoText('public/index.html', 'groupCount: aiAnalysisBatchResults.value.length,', 'AI summary group count context is not re-inlined');
requireNoText('public/index.html', 'completedHotels: aiAnalysisProgress.value.completedHotels,', 'AI summary completed count context is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedOtaSummaryRequestBody = ({', 'AI analysis summary request builder is not re-inlined');
requireNoText('public/index.html', 'if (meituanAiSelectedHotels.value.length === 0) {', 'Meituan AI analysis selection guard is not re-inlined');
requireNoText('public/index.html', 'const selectedData = resolveMeituanAiSelectedData(meituanAiSelectedHotels.value, meituanAiAnalysisHotelList.value);', 'Meituan AI selected data resolution is not re-inlined');
requireNoText('public/index.html', 'const analysisData = buildMeituanAiAnalysisRequestBody(selectedData);', 'Meituan AI request body construction is not re-inlined');
requireNoText('public/index.html', "const res = await request('/online-data/ai-analysis', {", 'Meituan AI request flow is not re-inlined');
requireNoText('public/index.html', 'meituanAiAnalysisHistory.value.unshift(buildMeituanAiAnalysisHistoryRecord({', 'Meituan AI history construction is not re-inlined');
requireNoText('public/index.html', 'meituanAiAnalysisHistory.value = meituanAiAnalysisHistory.value.slice(0, 10);', 'Meituan AI history trimming is not re-inlined');
requireNoText('public/index.html', "console.error('美团AI分析请求失败:', e);", 'Meituan AI exception logging is not re-inlined');
requireNoText('public/index.html', 'total_hotels: selectedData.length,', 'Meituan AI analysis request body is not re-inlined');
requireNoText('public/index.html', 'selectedData.slice(0, 3).map(h => h.hotelName)', 'Meituan AI analysis history naming is not re-inlined');
requireNoText('public/index.html', 'const buildCapturedFallbackSummaryReport = ({', 'AI analysis fallback summary builder is not re-inlined');
requireNoText('public/index.html', 'const sanitizeAiReportHtml = (value) => {', 'AI report sanitizer is not re-inlined');
requireNoText('public/index.html', 'const aiReportHtmlToText = (value) => {', 'AI report text converter is not re-inlined');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireNoText('public/index.html', "openDataConfigModal('ctrip-comments')", 'Ctrip comment capture card is not exposed in UI');
requireNoText('public/index.html', "openDataConfigModal('meituan-comments')", 'Meituan comment capture card is not exposed in UI');
requireNoText('public/index.html', '<option value="comment">评价</option>', 'platform data source form does not offer comment data type');
requireNoText('public/index.html', '<option value="review">点评数据</option>', 'online data history filter does not offer review data type');
requireNoText('public/index.html', "title: '点评问题'", 'OTA diagnosis UI does not render the deprecated comment section');
requireTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'service-quality'", 'revenue research exposes service-quality product instead of review-topic');
requireNoTextInFiles(['public/index.html', 'public/revenue-research-static.js'], "key: 'review-topic'", 'revenue research does not expose review-topic product');
requireText('app/service/RevenueResearchService.php', "'service-quality' =>", 'revenue research backend supports service-quality product');
requireNoText('app/service/RevenueResearchService.php', "'review-topic' =>", 'revenue research backend does not support review-topic product');
requireTextInFiles(['public/index.html', 'public/operation-static.js'], 'service_quality', 'operation dashboard renders service quality data');
requireText('public/operation-static.js', 'buildOperationSourceBrief', 'operation source brief builder lives in operation static module');
requireText('public/operation-static.js', 'buildOperationDecisionCards', 'operation decision card builder lives in operation static module');
requireText('public/index.html', 'buildOperationDecisionCards(operationFullData.value || {}, operationDisplayFormatters)', 'operation dashboard uses extracted decision card builder');
requireNoText('public/index.html', 'operationFullData.reviews', 'operation dashboard does not render disabled review data');
requireText('app/service/OperationManagementService.php', "'service_quality' => $serviceQuality", 'operation full data returns service quality summary');
requireNoText('app/service/OperationManagementService.php', "'reviews' => $reviews", 'operation full data does not depend on review summary');
requireNoText('public/index.html', "onlineDataTab === 'ctrip-review'", 'Ctrip hidden review tab is removed from frontend');
requireNoText('public/index.html', "onlineDataTab === 'meituan-review'", 'Meituan hidden review tab is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'ctrip-comments'", 'Ctrip comment config modal is removed from frontend');
requireNoText('public/index.html', "currentDataConfigType === 'meituan-comments'", 'Meituan comment config modal is removed from frontend');
requireNoText('public/index.html', "/online-data/fetch-ctrip-comments", 'frontend does not call Ctrip comment fetch endpoint');
requireNoText('public/index.html', "/online-data/capture-ctrip-comments-browser", 'frontend does not call Ctrip browser comment capture endpoint');
requireNoText('public/index.html', "/online-data/fetch-meituan-comments", 'frontend does not call Meituan comment fetch endpoint');
requireText('public/index.html', 'online-data-ota-supplement', 'online data page renders daily OTA supplement summary panel');
requireText('public/index.html', 'ota_channel_supplement', 'frontend consumes OTA supplement summary from daily data summary');
requireText('app/controller/OnlineData.php', "'ota_channel_supplement' =>", 'daily data summary returns OTA supplement summary');
requireText('app/controller/OnlineData.php', "'scope' => 'ota_channel'", 'OTA supplement summary is explicitly scoped to OTA channel');

requireText('tests/automation/e2e-helpers.js', 'function modulePath', 'helpers expose module path mapping');
requireText('tests/automation/e2e-helpers.js', 'function testIdForModule', 'helpers expose module test id selector');
requireText('tests/automation/e2e-helpers.js', 'function semanticInputValue', 'helpers generate field-semantic input values');
requireText('tests/automation/e2e-helpers.js', 'async function waitForApiOrState', 'helpers wait by API response or state assertion');
requireText('tests/automation/e2e-helpers.js', 'function classifyApiStatus', 'helpers classify API status by failure type');
requireText('tests/automation/e2e-helpers.js', "status === 400 || status === 422", 'helpers classify validation failures as invalid test data');

requireText('tests/automation/full-click-coverage.spec.js', 'backupDatabase', 'full click test backs up database before mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'restoreDatabase', 'full click test can restore database after mutation');
requireText('tests/automation/full-click-coverage.spec.js', 'semanticInputValue', 'full click test uses semantic input generator');
requireText('tests/automation/full-click-coverage.spec.js', "category: 'safe-skip'", 'full click report classifies safe skips');
requireText('tests/automation/full-click-coverage.spec.js', "'test-data-invalid'", 'full click report classifies invalid test data');
requireText('tests/automation/full-click-coverage.spec.js', "'product-bug'", 'full click report classifies product bugs');
requireText('tests/automation/full-click-coverage.spec.js', 'summary.json', 'full click test writes classified summary');
requireText('tests/automation/full-click-coverage.spec.js', 'MIN_KEY_FUNCTION_LOOPS = 50', 'full click key-function validation starts at 50 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'MAX_KEY_FUNCTION_LOOPS = 100', 'full click key-function validation caps at 100 loops');
requireText('tests/automation/full-click-coverage.spec.js', 'parseKeyFunctionLoopCount', 'full click clamps key-function loop count');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MIN_LOOP', 'full click can lower loop floor for bounded runs');
requireText('tests/automation/full-click-coverage.spec.js', 'E2E_FULL_MAX_LOOP', 'full click can cap loop count for bounded runs');
requireNoText('tests/automation/full-click-coverage.spec.js', 'waitForTimeout', 'full click test avoids fixed sleeps');

requireText('tests/automation/edge-input-guard.spec.js', 'edgeCasesForField', 'edge input guard generates boundary input cases');
requireText('tests/automation/edge-input-guard.spec.js', 'installEdgeApiMocks', 'edge input guard mocks mutating APIs by default');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_EDGE_LIVE_API', 'edge input guard can opt into live API mode');
requireText('tests/automation/edge-input-guard.spec.js', 'E2E_USERNAME', 'edge input guard uses E2E username override');
requireText('tests/automation/edge-input-guard.spec.js', 'mocked-response', 'edge input guard records mocked validation responses');
requireText('tests/automation/edge-input-guard.spec.js', 'classifyConsoleEvent', 'edge input guard classifies expected console validation errors');
requireText('tests/automation/edge-input-guard.spec.js', "row.category === 'page-error'", 'edge input guard fails on real page-error diagnostics');
requireText('tests/automation/edge-input-guard.spec.js', 'script-like-text', 'edge input guard covers script-like text safely as field input');
requireText('tests/automation/edge-input-guard.spec.js', 'maxFieldsPerModule: clampInt(process.env.E2E_EDGE_MAX_FIELDS_PER_MODULE, 12', 'edge input guard has bounded default field scan');
requireText('tests/automation/edge-input-guard.spec.js', 'maxActionsPerModule: clampInt(process.env.E2E_EDGE_MAX_ACTIONS_PER_MODULE, 8', 'edge input guard has bounded default action scan');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.USERNAME', 'edge input guard avoids OS username environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'process.env.PASSWORD', 'edge input guard avoids generic password environment');
requireNoText('tests/automation/edge-input-guard.spec.js', 'waitForTimeout', 'edge input guard avoids fixed sleeps');

requireText('tests/automation/module-smoke.spec.js', 'goModule', 'module smoke reuses stable module navigation helper');
requireText('tests/automation/module-smoke.spec.js', 'semanticInputValue', 'module smoke uses semantic input generator');
requireText('tests/automation/module-smoke.spec.js', 'waitForApiOrState', 'module smoke waits by API response or state assertion');
requireText('tests/automation/module-smoke.spec.js', 'category: classifyError(error)', 'module smoke classifies failures in report');
requireNoText('tests/automation/module-smoke.spec.js', 'waitForTimeout', 'module smoke avoids fixed sleeps');
requireNoText('tests/automation/module-smoke.spec.js', 'getByText', 'module smoke avoids text-only navigation selectors');

requireText('tests/automation/async-page-guard.spec.js', 'installHistoryFixtures', 'async guard uses deterministic history fixtures');
requireText('tests/automation/async-page-guard.spec.js', 'waitForResponse', 'async guard waits for delayed detail response');
requireNoText('tests/automation/async-page-guard.spec.js', 'waitForTimeout', 'async guard avoids fixed sleeps');

requireText('tests/automation/business-chains.spec.js', 'business chain: OTA import to revenue', 'business chain covers OTA to operation');
requireText('tests/automation/business-chains.spec.js', 'business chain: market evaluation to transfer', 'business chain covers market to transfer');
requireText('tests/automation/business-chains.spec.js', 'business chain: strategy, quant simulation, feasibility', 'business chain covers investment decision');
requireText('tests/automation/business-chains.spec.js', '/api/online-data/save-daily-data', 'business chain imports OTA data through API');
requireText('tests/automation/business-chains.spec.js', '/api/operation/action-tracking', 'business chain asserts operation action tracking');
requireText('tests/automation/business-chains.spec.js', '/api/transfer/dashboard', 'business chain asserts transfer dashboard reads upstream results');
requireText('tests/automation/business-chains.spec.js', '/api/agent/feasibility-report/generate', 'business chain asserts feasibility report persistence');
requireText('tests/automation/business-chains.spec.js', 'E2E_API_REQUEST_TIMEOUT_MS', 'business chain API client has configurable timeout');
requireText('tests/automation/business-chains.spec.js', 'timeout: apiRequestTimeout', 'business chain applies API timeout to auth and business calls');
requireText('tests/automation/business-chains.spec.js', "'test-data-invalid'", 'business chain classifies invalid test data');
requireText('tests/automation/business-chains.spec.js', "'product-bug'", 'business chain classifies product bugs');
requireNoText('tests/automation/business-chains.spec.js', 'waitForTimeout', 'business chain avoids fixed sleeps');

requireText('tests/automation/README.md', 'test:e2e:business', 'README documents business-chain test command');
requireText('tests/automation/README.md', 'test:e2e:edge', 'README documents edge input guard command');
requireText('tests/automation/README.md', 'E2E_EDGE_LIVE_API=0', 'README documents edge test safe mocked API mode');
requireText('tests/automation/README.md', '`product-bug`', 'README documents product bug category');
requireText('tests/automation/README.md', '`test-data-invalid`', 'README documents invalid test data category');

requireText('package.json', 'test:e2e:quick', 'package exposes quick CI e2e command');
requireText('package.json', 'test:e2e:business', 'package exposes business chain e2e command');
requireText('package.json', 'test:e2e:edge', 'package exposes edge input e2e command');
requireText('package.json', 'test:e2e:ui', 'package exposes UI automation e2e command');
requireText('package.json', 'test:e2e:full:bounded', 'package exposes bounded full-click e2e command');

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/simulation-static.js'), context, {
    filename: 'public/simulation-static.js',
  });
  const simulationStatic = context.window.SUXI_SIMULATION_STATIC || {};
  const buildTransferDecisionLayerRows = simulationStatic.buildTransferDecisionLayerRows;
  if (typeof buildTransferDecisionLayerRows !== 'function') {
    checks.push({
      file: 'public/simulation-static.js',
      label: 'Simulation static exports transfer decision layer builder',
      ok: false,
      detail: 'buildTransferDecisionLayerRows',
    });
  } else {
    const readyRows = buildTransferDecisionLayerRows({
      snapshot: { data_status: 'verified' },
      sourceDate: '2026-06-10',
      pricingResult: { valuation: 280 },
      timingResult: { window: 'now' },
      dashboardResult: { final_judgement: '可进入谈判' },
      pricingForm: { hotel_name: '天成酒店' },
      timingForm: {},
    });
    const emptyRows = buildTransferDecisionLayerRows({});
    checks.push({
      file: 'public/simulation-static.js',
      label: 'Simulation static builds transfer decision rows with explicit fact, assumption, calculation and risk states',
      ok: readyRows.length === 4
        && readyRows[0].key === 'facts'
        && readyRows[0].status === '有快照'
        && readyRows[0].detail.includes('2026-06-10')
        && readyRows[0].evidence === 'data_status: verified'
        && readyRows[1].status === '已填写'
        && readyRows[1].evidence === '表单输入不自动等同于已验证事实。'
        && readyRows[2].status === '已生成'
        && readyRows[2].evidence === '定价 有 / 时机 有'
        && readyRows[3].status === '可汇总'
        && readyRows[3].evidence === '可进入谈判'
        && emptyRows[0].status === '待取数'
        && emptyRows[0].evidence === '暂无经营快照'
        && emptyRows[1].status === '待填写'
        && emptyRows[2].evidence === '定价 无 / 时机 无'
        && emptyRows[3].status === '待汇总'
        && emptyRows[3].evidence === '暂无最终判断',
      detail: 'buildTransferDecisionLayerRows samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/simulation-static.js',
    label: 'Simulation static VM smoke check',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/auto-fetch-static.js'), context, {
    filename: 'public/auto-fetch-static.js',
  });
  const autoFetchStatic = context.window.SUXI_AUTO_FETCH_STATIC || {};
  const buildAutoFetchTriggerRequestBody = autoFetchStatic.buildAutoFetchTriggerRequestBody;
  const buildAutoFetchRunStartState = autoFetchStatic.buildAutoFetchRunStartState;
  const runAutoFetchTriggerFlow = autoFetchStatic.runAutoFetchTriggerFlow;
  const resolveDataConfigTestEndpoint = autoFetchStatic.resolveDataConfigTestEndpoint;
  const buildDataConfigTestRequest = autoFetchStatic.buildDataConfigTestRequest;
  const runDataConfigTestFlow = autoFetchStatic.runDataConfigTestFlow;
  if (typeof buildAutoFetchTriggerRequestBody !== 'function'
    || typeof buildAutoFetchRunStartState !== 'function'
    || typeof runAutoFetchTriggerFlow !== 'function'
    || typeof resolveDataConfigTestEndpoint !== 'function'
    || typeof buildDataConfigTestRequest !== 'function'
    || typeof runDataConfigTestFlow !== 'function') {
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch static exports trigger flow helpers',
      ok: false,
      detail: 'trigger request, start state, flow runner, data-config test runner',
    });
  } else {
    const triggerBody = buildAutoFetchTriggerRequestBody({
      hotelId: 58,
      browserHeadless: true,
      modePayload: { meituan_auto_fetch_mode: 'hybrid_auto', ctrip_section_concurrency: 3 },
    });
    const startState = buildAutoFetchRunStartState({
      startedAt: '2026-06-11 10:00:00',
      ctripExecutionText: '携程 3 页并发',
      modePayload: { meituan_auto_fetch_mode: 'hybrid_auto' },
      modeLabel: () => '接口直连自动',
      browserHeadless: true,
    });
    const runTriggerSample = async (overrides = {}) => {
      const events = [];
      const timestamps = [...(overrides.timestamps || ['2026-06-11 10:00:00', '2026-06-11 10:00:09'])];
      let capturedRequestBody = null;
      let delayedRefreshSettled = false;
      const delayedRefresh = label => new Promise(resolve => {
        setTimeout(() => {
          delayedRefreshSettled = true;
          events.push(['refresh', label]);
          resolve();
        }, 25);
      });
      const result = await runAutoFetchTriggerFlow({
        getHotelId: () => (overrides.hotelId === undefined ? 58 : overrides.hotelId),
        hasPlatformFetchConfig: hotelId => (overrides.hasConfig === undefined ? Boolean(hotelId) : overrides.hasConfig),
        setFetching: value => events.push(['fetching', value]),
        startTimer: () => events.push(['timer', 'start']),
        stopTimer: () => events.push(['timer', 'stop']),
        getTimestamp: () => timestamps.shift() || '2026-06-11 10:00:09',
        getBrowserHeadless: () => (overrides.browserHeadless === undefined ? true : overrides.browserHeadless),
        getCtripExecutionText: () => '携程 3 页并发',
        buildModePayload: () => ({ meituan_auto_fetch_mode: 'hybrid_auto', ctrip_section_concurrency: 3 }),
        modeLabel: value => ({ hybrid_auto: '接口直连自动' }[value] || value),
        getCtripSectionConcurrency: () => 3,
        notify: (message, type = 'success') => events.push(['notify', type, message]),
        setRunState: value => events.push(['state', value.type, value]),
        requestAutoFetch: async (body) => {
          capturedRequestBody = body;
          events.push(['request', body]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || { code: 200, message: 'ok', data: { saved_count: 9 } };
        },
        getDurationText: () => '9秒',
        updateLastResult: (response, success, message) => events.push(['lastResult', success, message, response]),
        refreshOnlineData: overrides.delayedRefresh ? () => delayedRefresh('online') : async () => events.push(['refresh', 'online']),
        refreshOnlineHistory: overrides.delayedRefresh ? () => delayedRefresh('history') : async () => events.push(['refresh', 'history']),
        refreshLatestCtripData: overrides.delayedRefresh ? () => delayedRefresh('latest') : async options => events.push(['refresh', 'latest', options]),
        openCtripProfileFieldsForReview: overrides.delayedRefresh ? () => delayedRefresh('profile-review') : async () => events.push(['refresh', 'profile-review']),
        loadAutoFetchStatus: overrides.delayedRefresh ? () => delayedRefresh('status') : async () => events.push(['refresh', 'status']),
        loadBackendGlobalNotifications: overrides.delayedRefresh ? () => delayedRefresh('notifications') : async () => events.push(['refresh', 'notifications']),
      });
      return { result, events, capturedRequestBody, returnedBeforeDelayedRefresh: !delayedRefreshSettled };
    };

    const successRun = await runTriggerSample();
    const acceptedRun = await runTriggerSample({
      response: { code: 200, message: 'queued', data: { status: 'running', task_id: 'task-1', saved_count: 0 } },
    });
    const delayedRefreshRun = await runTriggerSample({ delayedRefresh: true });
    const errorRun = await runTriggerSample({
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const exceptionRun = await runTriggerSample({ throwRequest: true });
    const missingHotelRun = await runTriggerSample({ hotelId: '' });
    const missingConfigRun = await runTriggerSample({ hasConfig: false });
    const ctripAdsEndpoint = resolveDataConfigTestEndpoint('ctrip-ads');
    const unsupportedEndpoint = resolveDataConfigTestEndpoint('booking-ota');
    const invalidEndpoint = resolveDataConfigTestEndpoint('unknown-platform');
    const ctripAdsTestRequest = buildDataConfigTestRequest({
      type: 'ctrip-ads',
      form: {
        url: 'https://m.ctrip.com/restapi/soa2/18320/json/queryCampaignReportList',
        cookies: 'sid=secret',
        payload_json: '{"page":1}',
        start_date: '2026-06-10',
        end_date: '2026-06-11',
        system_hotel_id: 'hotel-58',
      },
      validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
      ctripAdsApiUrlHint: 'ads url hint',
    });
    const invalidCtripAdsTestRequest = buildDataConfigTestRequest({
      type: 'ctrip-ads',
      form: { url: 'https://ebooking.ctrip.com/page' },
      validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
      ctripAdsApiUrlHint: 'ads url hint',
    });
    const runDataConfigTestSample = async (overrides = {}) => {
      const events = [];
      let capturedApiUrl = '';
      let capturedBody = null;
      const result = await runDataConfigTestFlow({
        getType: () => (overrides.type === undefined ? 'ctrip-ads' : overrides.type),
        getForm: () => overrides.form || {
          url: 'https://m.ctrip.com/restapi/soa2/18320/json/queryCampaignReportList',
          cookies: 'sid=secret',
          payload_json: '{"page":1}',
          start_date: '2026-06-10',
          end_date: '2026-06-11',
          system_hotel_id: 'hotel-58',
        },
        setTesting: value => events.push(['testing', value]),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        validateCtripAdsApiUrl: url => url.includes('queryCampaignReportList'),
        ctripAdsApiUrlHint: 'ads url hint',
        requestTest: async (apiUrl, body) => {
          capturedApiUrl = apiUrl;
          capturedBody = body;
          events.push(['request', apiUrl, body]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || { code: 200, message: 'ok', data: { saved_count: 2 } };
        },
      });
      return { result, events, capturedApiUrl, capturedBody };
    };
    const configSuccessRun = await runDataConfigTestSample();
    const configFailedRun = await runDataConfigTestSample({ response: { code: 500, message: 'connection failed' } });
    const configUnsupportedRun = await runDataConfigTestSample({ type: 'booking-ota' });
    const configInvalidUrlRun = await runDataConfigTestSample({ form: { url: 'https://ebooking.ctrip.com/page' } });
    const configExceptionRun = await runDataConfigTestSample({ throwRequest: true });

    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger request body preserves OTA capture scope',
      ok: triggerBody.system_hotel_id === 58
        && triggerBody.data_period === 'realtime_snapshot'
        && triggerBody.interactive_browser === false
        && triggerBody.browser_headless === true
        && triggerBody.async === true
        && triggerBody.meituan_auto_fetch_mode === 'hybrid_auto'
        && triggerBody.ctrip_section_concurrency === 3,
      detail: 'buildAutoFetchTriggerRequestBody sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger running state keeps explicit platform mode text',
      ok: startState.active === true
        && startState.type === 'running'
        && startState.message.includes('携程 3 页并发')
        && startState.message.includes('美团使用接口直连自动')
        && startState.message.includes('浏览器无头运行'),
      detail: 'buildAutoFetchRunStartState sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger accepted path returns before OTA collection finishes',
      ok: acceptedRun.result.status === 'accepted'
        && acceptedRun.capturedRequestBody.async === true
        && acceptedRun.events.some(event => event[0] === 'state' && event[1] === 'running' && event[2].active === true)
        && acceptedRun.events.some(event => event[0] === 'lastResult' && event[1] === null && event[2] === 'queued')
        && acceptedRun.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2] === 'queued')
        && acceptedRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && acceptedRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && !acceptedRun.events.some(event => event[0] === 'refresh' && ['online', 'history', 'latest'].includes(event[1]))
        && acceptedRun.events.some(event => event[0] === 'timer' && event[1] === 'start')
        && acceptedRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && acceptedRun.events.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runAutoFetchTriggerFlow accepted sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger success path refreshes persisted and UI data',
      ok: successRun.result.status === 'success'
        && successRun.capturedRequestBody.system_hotel_id === 58
        && successRun.capturedRequestBody.async === true
        && successRun.events.some(event => event[0] === 'state' && event[1] === 'running')
        && successRun.events.some(event => event[0] === 'state' && event[1] === 'success')
        && successRun.events.some(event => event[0] === 'lastResult' && event[1] === true && event[2] === 'ok')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'online')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'history')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'latest' && event[2]?.silent === true)
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'profile-review')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && successRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && successRun.events.some(event => event[0] === 'timer' && event[1] === 'start')
        && successRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && successRun.events.some(event => event[0] === 'fetching' && event[1] === false)
        && delayedRefreshRun.result.status === 'success'
        && delayedRefreshRun.returnedBeforeDelayedRefresh === true,
      detail: 'runAutoFetchTriggerFlow success sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger error response keeps failed state explicit',
      ok: errorRun.result.status === 'error_response'
        && errorRun.events.some(event => event[0] === 'lastResult' && event[1] === false && String(event[2]).includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'state' && event[1] === 'error' && event[2].message.includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && !errorRun.events.some(event => event[0] === 'refresh' && event[1] === 'online'),
      detail: 'runAutoFetchTriggerFlow error response sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger exception path exposes failure and releases busy state',
      ok: exceptionRun.result.status === 'exception'
        && exceptionRun.events.some(event => event[0] === 'state' && event[1] === 'error' && event[2].message.includes('network failed'))
        && exceptionRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('获取失败: network failed'))
        && exceptionRun.events.some(event => event[0] === 'refresh' && event[1] === 'status')
        && exceptionRun.events.some(event => event[0] === 'refresh' && event[1] === 'notifications')
        && exceptionRun.events.some(event => event[0] === 'timer' && event[1] === 'stop')
        && exceptionRun.events.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runAutoFetchTriggerFlow exception sample',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger guards missing hotel before mutation',
      ok: missingHotelRun.result.status === 'missing_hotel'
        && missingHotelRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请先选择酒店'))
        && !missingHotelRun.events.some(event => event[0] === 'fetching')
        && !missingHotelRun.events.some(event => event[0] === 'timer'),
      detail: 'missing hotel guard',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'auto-fetch trigger guards missing platform config before mutation',
      ok: missingConfigRun.result.status === 'missing_config'
        && missingConfigRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('保存并关联携程或美团配置'))
        && !missingConfigRun.events.some(event => event[0] === 'fetching')
        && !missingConfigRun.events.some(event => event[0] === 'timer'),
      detail: 'missing config guard',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test endpoint mapping keeps explicit unsupported states',
      ok: ctripAdsEndpoint.status === 'ready'
        && ctripAdsEndpoint.apiUrl === '/online-data/fetch-ctrip-ads'
        && unsupportedEndpoint.status === 'unsupported'
        && unsupportedEndpoint.level === 'info'
        && invalidEndpoint.status === 'unknown_type'
        && invalidEndpoint.level === 'error',
      detail: 'resolveDataConfigTestEndpoint samples',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test request preserves OTA scope and URL validation',
      ok: ctripAdsTestRequest.status === 'ready'
        && ctripAdsTestRequest.apiUrl === '/online-data/fetch-ctrip-ads'
        && ctripAdsTestRequest.body?.api_type === 'effect_report'
        && ctripAdsTestRequest.body?.cookies === 'sid=secret'
        && ctripAdsTestRequest.body?.payload_json === '{"page":1}'
        && ctripAdsTestRequest.body?.system_hotel_id === 'hotel-58'
        && invalidCtripAdsTestRequest.status === 'invalid_url'
        && invalidCtripAdsTestRequest.message === 'ads url hint',
      detail: 'buildDataConfigTestRequest samples',
    });
    checks.push({
      file: 'public/auto-fetch-static.js',
      label: 'data-source config test flow keeps success, failed and skipped states visible',
      ok: configSuccessRun.result.status === 'success'
        && configSuccessRun.capturedApiUrl === '/online-data/fetch-ctrip-ads'
        && configSuccessRun.capturedBody?.cookies === 'sid=secret'
        && configSuccessRun.events.some(event => event[0] === 'notify' && event[2] === '连接测试成功！数据获取正常')
        && configFailedRun.result.status === 'failed'
        && configFailedRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2] === 'connection failed')
        && configUnsupportedRun.result.status === 'unsupported'
        && configUnsupportedRun.events.some(event => event[0] === 'notify' && event[1] === 'info')
        && !configUnsupportedRun.events.some(event => event[0] === 'request')
        && configInvalidUrlRun.result.status === 'invalid_url'
        && configInvalidUrlRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2] === 'ads url hint')
        && !configInvalidUrlRun.events.some(event => event[0] === 'request')
        && configExceptionRun.result.status === 'exception'
        && configExceptionRun.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('network failed'))
        && [configSuccessRun, configFailedRun, configUnsupportedRun, configInvalidUrlRun, configExceptionRun].every(run => run.events[0]?.[0] === 'testing' && run.events[0]?.[1] === true)
        && [configSuccessRun, configFailedRun, configUnsupportedRun, configInvalidUrlRun, configExceptionRun].every(run => run.events[run.events.length - 1]?.[0] === 'testing' && run.events[run.events.length - 1]?.[1] === false),
      detail: 'runDataConfigTestFlow samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/auto-fetch-static.js',
    label: 'auto-fetch static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/meituan-static.js'), context, {
    filename: 'public/meituan-static.js',
  });
  const meituanStatic = context.window.SUXI_MEITUAN_STATIC || {};
  const buildMeituanBatchFetchTasks = meituanStatic.buildMeituanBatchFetchTasks;
  const buildMeituanBatchFetchResultEntry = meituanStatic.buildMeituanBatchFetchResultEntry;
  const buildMeituanDisplayModelPayload = meituanStatic.buildMeituanDisplayModelPayload;
  const validateMeituanBatchFetchInput = meituanStatic.validateMeituanBatchFetchInput;
  const runMeituanBatchFetchFlow = meituanStatic.runMeituanBatchFetchFlow;
  const buildMeituanBrowserCaptureRequestContext = meituanStatic.buildMeituanBrowserCaptureRequestContext;
  const runMeituanBrowserCaptureFlow = meituanStatic.runMeituanBrowserCaptureFlow;
  const buildMeituanCapturedPayloadSaveContext = meituanStatic.buildMeituanCapturedPayloadSaveContext;
  const runMeituanCapturedPayloadSaveFlow = meituanStatic.runMeituanCapturedPayloadSaveFlow;
  const normalizeMeituanTrafficFetchForm = meituanStatic.normalizeMeituanTrafficFetchForm;
  const validateMeituanTrafficFetchInput = meituanStatic.validateMeituanTrafficFetchInput;
  const buildMeituanTrafficFetchRequestBody = meituanStatic.buildMeituanTrafficFetchRequestBody;
  const runMeituanTrafficFetchFlow = meituanStatic.runMeituanTrafficFetchFlow;
  const normalizeMeituanOrderFetchForm = meituanStatic.normalizeMeituanOrderFetchForm;
  const validateMeituanOrderFetchInput = meituanStatic.validateMeituanOrderFetchInput;
  const buildMeituanOrderFetchRequestBody = meituanStatic.buildMeituanOrderFetchRequestBody;
  const runMeituanOrderFetchFlow = meituanStatic.runMeituanOrderFetchFlow;
  const normalizeMeituanAdsFetchForm = meituanStatic.normalizeMeituanAdsFetchForm;
  const validateMeituanAdsFetchInput = meituanStatic.validateMeituanAdsFetchInput;
  const buildMeituanAdsFetchRequestBody = meituanStatic.buildMeituanAdsFetchRequestBody;
  const runMeituanAdsFetchFlow = meituanStatic.runMeituanAdsFetchFlow;
  if (typeof buildMeituanBatchFetchTasks !== 'function'
    || typeof buildMeituanBatchFetchResultEntry !== 'function'
    || typeof buildMeituanDisplayModelPayload !== 'function'
    || typeof validateMeituanBatchFetchInput !== 'function'
    || typeof runMeituanBatchFetchFlow !== 'function'
    || typeof buildMeituanBrowserCaptureRequestContext !== 'function'
    || typeof runMeituanBrowserCaptureFlow !== 'function'
    || typeof buildMeituanCapturedPayloadSaveContext !== 'function'
    || typeof runMeituanCapturedPayloadSaveFlow !== 'function'
    || typeof normalizeMeituanTrafficFetchForm !== 'function'
    || typeof validateMeituanTrafficFetchInput !== 'function'
    || typeof buildMeituanTrafficFetchRequestBody !== 'function'
    || typeof runMeituanTrafficFetchFlow !== 'function'
    || typeof normalizeMeituanOrderFetchForm !== 'function'
    || typeof validateMeituanOrderFetchInput !== 'function'
    || typeof buildMeituanOrderFetchRequestBody !== 'function'
    || typeof runMeituanOrderFetchFlow !== 'function'
    || typeof normalizeMeituanAdsFetchForm !== 'function'
    || typeof validateMeituanAdsFetchInput !== 'function'
    || typeof buildMeituanAdsFetchRequestBody !== 'function'
    || typeof runMeituanAdsFetchFlow !== 'function') {
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan static exports batch/browser/payload/traffic/order/ads fetch builders',
      ok: false,
      detail: 'batch/browser/payload/traffic/order/ads fetch builders and flow runners',
    });
  } else {
    const tasks = buildMeituanBatchFetchTasks({
      form: {
        url: 'https://example.test/rank',
        hotelId: '10',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'demo' },
      },
      partnerId: 'partner-1',
      poiId: 'poi-1',
      cookies: 'mt-cookie',
    });
    const missingCookieValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: '',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const missingResourceValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['1'] },
      cookies: 'mt-cookie',
      partnerId: '',
      poiId: '',
    });
    const missingCustomDateValidation = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '', endDate: '' },
      cookies: 'mt-cookie',
      partnerId: 'partner-1',
      poiId: 'poi-1',
    });
    const validBatchInput = validateMeituanBatchFetchInput({
      form: { hotelId: '10', dateRanges: ['custom'], startDate: '2026-06-01', endDate: '2026-06-10' },
      cookies: ' mt-cookie ',
      partnerId: ' partner-1 ',
      poiId: ' poi-1 ',
    });
    const customTask = tasks.find(task => task.rankType === 'P_LL' && task.dateRange === 'custom');
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch input validator keeps missing-state signals explicit',
      ok: missingCookieValidation.ok === false
        && missingCookieValidation.level === 'error'
        && missingCookieValidation.message.includes('平台授权缺失')
        && missingResourceValidation.ok === false
        && missingResourceValidation.level === 'warning'
        && missingResourceValidation.message.includes('平台接口标识 / 平台门店标识')
        && missingCustomDateValidation.ok === false
        && missingCustomDateValidation.message.includes('自定义时间')
        && validBatchInput.ok === true
        && validBatchInput.cookies === 'mt-cookie'
        && validBatchInput.partnerId === 'partner-1'
        && validBatchInput.poiId === 'poi-1',
      detail: 'validateMeituanBatchFetchInput sample',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch task builder covers four rank types and custom dates',
      ok: tasks.length === 8
        && tasks.some(task => task.rankType === 'P_RZ' && task.dateRange === '1')
        && tasks.some(task => task.rankType === 'P_ZH' && task.dateRange === 'custom')
        && customTask?.body?.start_date === '2026-06-01'
        && customTask?.body?.end_date === '2026-06-10'
        && customTask?.body?.partner_id === 'partner-1'
        && customTask?.body?.poi_id === 'poi-1'
        && customTask?.body?.cookies === 'mt-cookie'
        && customTask?.body?.system_hotel_id === '10',
      detail: 'buildMeituanBatchFetchTasks sample',
    });
    const successEntry = buildMeituanBatchFetchResultEntry(tasks[0], {
      code: 200,
      data: {
        data: [{ rank: 1 }],
        saved_count: 3,
        display_hotels: [{ poiId: 'poi-1', hotelName: 'Demo' }],
        display_summary: { total: 1 },
        display_hotel_count: 1,
      },
    });
    const failedEntry = buildMeituanBatchFetchResultEntry(tasks[1], { code: 500, message: 'upstream failed' });
    const modelPayload = buildMeituanDisplayModelPayload({
      results: [successEntry, failedEntry],
      form: {
        competitorRoomCount: '20',
        poiId: 'poi-1',
        dateRanges: ['1', 'custom'],
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    const flowEvents = [];
    const flowStates = [];
    const requestedBodies = [];
    let flowOnlineResult = null;
    let flowBusinessSummary = null;
    let flowDisplayPayload = null;
    let flowSavedCount = 0;
    let flowFetchTime = '';
    const flowResult = await runMeituanBatchFetchFlow({
      getForm: () => ({
        url: 'https://example.test/rank',
        hotelId: '10',
        partnerId: 'partner-1',
        poiId: 'poi-1',
        cookies: ' mt-cookie ',
        dateRanges: ['1'],
        auth_data: { token: 'demo' },
        competitorRoomCount: '20',
      }),
      getSelectedConfig: () => ({ hotel_id: '10', cookies: 'mt-cookie' }),
      ensureMeituanConfigSecret: async config => {
        flowEvents.push('ensure-config');
        return config;
      },
      applyMeituanHotelConfig: async showMessage => flowEvents.push(`apply:${showMessage}`),
      notify: (message, level) => flowEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => flowStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { flowOnlineResult = value; },
      setFetchSuccess: value => flowStates.push(`success:${value}`),
      setHotelsList: value => flowStates.push(`hotels:${value.length}`),
      getEmptyBusinessSummary: () => ({ status: 'empty', metrics: {}, cards: [] }),
      setBusinessSummary: value => { flowBusinessSummary = value; },
      requestFetch: async body => {
        requestedBodies.push(body);
        return {
          code: 200,
          data: {
            data: [{ rank: 1, rankType: body.rank_type }],
            saved_count: 2,
            display_hotels: [{ poiId: body.poi_id, rankType: body.rank_type }],
            display_summary: { rankType: body.rank_type },
            display_hotel_count: 1,
          },
        };
      },
      requestDisplayModel: async payload => {
        flowDisplayPayload = payload;
        return { code: 200, data: { rows: [{ poiId: 'poi-1' }, { poiId: 'poi-2' }] } };
      },
      useDisplayModel: data => data.rows,
      setSavedCount: value => { flowSavedCount = value; },
      setDataFetchTime: value => { flowFetchTime = value; },
      getFetchTime: () => '2026-06-11 12:00:00',
      updateAiAnalysisHotelList: () => flowEvents.push('update-ai-hotels'),
      refreshOnlineHistory: async () => flowEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => flowEvents.push('refresh-data'),
    });
    const acceptedMeituanEvents = [];
    const acceptedMeituanStates = [];
    const acceptedMeituanBodies = [];
    let acceptedMeituanOnlineResult = null;
    let acceptedMeituanSavedCount = -1;
    const acceptedMeituanResult = await runMeituanBatchFetchFlow({
      getForm: () => ({
        url: 'https://example.test/rank',
        hotelId: '10',
        partnerId: 'partner-1',
        poiId: 'poi-1',
        cookies: ' mt-cookie ',
        dateRanges: ['1'],
      }),
      getSelectedConfig: () => ({ hotel_id: '10', cookies: 'mt-cookie' }),
      ensureMeituanConfigSecret: async config => config,
      applyMeituanHotelConfig: async showMessage => acceptedMeituanEvents.push(`apply:${showMessage}`),
      notify: (message, level) => acceptedMeituanEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedMeituanStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { acceptedMeituanOnlineResult = value; },
      setFetchSuccess: value => acceptedMeituanStates.push(`success:${value}`),
      setHotelsList: value => acceptedMeituanStates.push(`hotels:${value.length}`),
      getEmptyBusinessSummary: () => ({ status: 'empty', metrics: {}, cards: [] }),
      setBusinessSummary: () => {},
      requestFetch: async body => {
        acceptedMeituanBodies.push(body);
        return {
          code: 200,
          message: 'queued',
          data: {
            status: 'running',
            task_id: 'mt-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
          },
        };
      },
      requestDisplayModel: async () => {
        throw new Error('display model should not run for accepted background fetch');
      },
      setSavedCount: value => { acceptedMeituanSavedCount = value; },
      refreshOnlineHistory: async () => acceptedMeituanEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedMeituanEvents.push('refresh-data'),
    });
    const guardEvents = [];
    const guardResult = await runMeituanBatchFetchFlow({
      getForm: () => ({ hotelId: '', dateRanges: ['1'] }),
      notify: (message, level) => guardEvents.push(`notify:${level}:${message}`),
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan batch fetch result and display payload builders preserve response evidence',
      ok: successEntry.savedCount === 3
        && successEntry.displayCount === 1
        && failedEntry.error === 'upstream failed'
        && Array.isArray(modelPayload.display_hotels)
        && modelPayload.display_hotels.length === 1
        && modelPayload.target_poi_id === 'poi-1'
        && modelPayload.competitor_room_count === '20'
        && flowResult.status === 'success'
        && requestedBodies.length === 4
        && requestedBodies.every(body => body.partner_id === 'partner-1' && body.poi_id === 'poi-1' && body.cookies === 'mt-cookie' && body.async === true)
        && flowOnlineResult.length === 4
        && flowDisplayPayload.display_hotels.length === 4
        && flowDisplayPayload.target_poi_id === 'poi-1'
        && flowSavedCount === 8
        && flowFetchTime === '2026-06-11 12:00:00'
        && flowBusinessSummary.status === 'empty'
        && flowStates.join('|') === 'fetching:true|success:false|hotels:0|fetching:false'
        && flowEvents.includes('ensure-config')
        && flowEvents.includes('apply:false')
        && flowEvents.includes('update-ai-hotels')
        && flowEvents.includes('history')
        && flowEvents.includes('refresh-data')
        && flowEvents.some(event => event.includes('批量获取完成！共保存 8 条数据'))
        && acceptedMeituanResult.status === 'accepted'
        && acceptedMeituanResult.acceptedCount === 4
        && acceptedMeituanBodies.length === 4
        && acceptedMeituanBodies.every(body => body.async === true)
        && Array.isArray(acceptedMeituanOnlineResult)
        && acceptedMeituanOnlineResult.length === 4
        && acceptedMeituanOnlineResult[0].status === 'running'
        && acceptedMeituanOnlineResult[0].taskId === 'mt-task-1'
        && acceptedMeituanSavedCount === 0
        && acceptedMeituanStates.join('|') === 'fetching:true|success:false|hotels:0|fetching:false'
        && acceptedMeituanEvents.includes('history')
        && acceptedMeituanEvents.includes('refresh-data')
        && acceptedMeituanEvents.some(event => event.startsWith('notify:info:'))
        && guardResult.status === 'missing_hotel'
        && guardEvents[0] === 'notify:error:请选择目标酒店',
      detail: 'Meituan batch result sample',
    });

    const browserMissingHotel = buildMeituanBrowserCaptureRequestContext({
      form: { storeId: 'store-1' },
      systemHotelId: null,
    });
    const browserMissingStore = buildMeituanBrowserCaptureRequestContext({
      form: {},
      systemHotelId: '10',
    });
    const browserMissingAdsUrl = buildMeituanBrowserCaptureRequestContext({
      form: { storeId: 'store-1', captureSections: ['ads'], adsUrl: '' },
      systemHotelId: '10',
    });
    const browserRequestContext = buildMeituanBrowserCaptureRequestContext({
      form: {
        storeId: ' store-10 ',
        poiId: 'poi-10',
        poiName: 'POI Demo',
        adsUrl: 'https://ads.example.test',
        captureSections: 'traffic ads',
      },
      systemHotelId: '10',
      fallbackPoiId: 'poi-fallback',
      partnerId: 'partner-10',
      hotelName: 'Hotel 10',
      options: { loginOnly: true, bindDataSource: false },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan browser capture request context keeps missing states explicit',
      ok: browserMissingHotel.status === 'missing_hotel'
        && browserMissingHotel.message === '请选择目标酒店'
        && browserMissingStore.status === 'missing_store_id'
        && browserMissingStore.message === '请填写美团门店标识'
        && browserMissingAdsUrl.status === 'missing_ads_url'
        && browserMissingAdsUrl.message === '请填写推广通广告入口 URL'
        && browserRequestContext.ok === true
        && browserRequestContext.requestBody.system_hotel_id === '10'
        && browserRequestContext.requestBody.store_id === 'store-10'
        && browserRequestContext.requestBody.poi_id === 'poi-10'
        && browserRequestContext.requestBody.poi_name === 'POI Demo'
        && browserRequestContext.requestBody.partner_id === 'partner-10'
        && browserRequestContext.requestBody.ads_url === 'https://ads.example.test'
        && browserRequestContext.requestBody.sections.join(',') === 'traffic,ads'
        && browserRequestContext.requestBody.login_only === true
        && browserRequestContext.requestBody.bind_data_source === false,
      detail: 'buildMeituanBrowserCaptureRequestContext sample',
    });

    const browserEvents = [];
    const browserStates = [];
    let browserCapturePayload = null;
    let browserOnlinePayload = null;
    let browserRequestedBody = null;
    const browserFlowResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({
        storeId: ' store-20 ',
        poiId: 'poi-20',
        poiName: 'POI 20',
        adsUrl: 'https://ads.example.test/20',
        captureSections: ['traffic', 'ads'],
      }),
      getSystemHotelId: () => '20',
      getFallbackPoiId: () => 'poi-fallback',
      getPartnerId: () => 'partner-20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => browserEvents.push(`notify:${level || 'info'}:${message}`),
      setRunning: value => browserStates.push(`running:${value}`),
      setFetching: value => browserStates.push(`fetching:${value}`),
      setCaptureResult: value => { browserCapturePayload = value; },
      setOnlineDataResult: value => { browserOnlinePayload = value; },
      requestCapture: async body => {
        browserRequestedBody = body;
        return { code: 200, message: 'capture ok', data: { saved_count: 9, rows: [{ id: 1 }] } };
      },
      refreshOnlineHistory: async () => browserEvents.push('history'),
      refreshPlatformProfileStatus: async params => browserEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => browserEvents.push('data-sources'),
    });
    const browserLoginEvents = [];
    const browserLoginResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-login', captureSections: ['traffic'] }),
      getSystemHotelId: () => '30',
      getFallbackPoiId: () => '',
      getPartnerId: () => '',
      getHotelNameById: id => `Hotel ${id}`,
      options: { loginOnly: true, bindDataSource: false },
      notify: (message, level) => browserLoginEvents.push(`notify:${level || 'info'}:${message}`),
      requestCapture: async () => ({ code: 200, data: { profile_saved: true } }),
      refreshOnlineHistory: async () => browserLoginEvents.push('history'),
      refreshPlatformProfileStatus: async params => browserLoginEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => browserLoginEvents.push('data-sources'),
    });
    const browserFailedEvents = [];
    const browserFailedStates = [];
    const browserFailedResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-failed', captureSections: ['traffic'] }),
      getSystemHotelId: () => '40',
      notify: (message, level) => browserFailedEvents.push(`notify:${level}:${message}`),
      setRunning: value => browserFailedStates.push(`running:${value}`),
      setFetching: value => browserFailedStates.push(`fetching:${value}`),
      requestCapture: async () => ({ code: 500, message: 'browser backend failed' }),
    });
    const browserExceptionEvents = [];
    const browserExceptionStates = [];
    let browserExceptionPayload = null;
    const browserExceptionResult = await runMeituanBrowserCaptureFlow({
      getForm: () => ({ storeId: 'store-exception', captureSections: ['traffic'] }),
      getSystemHotelId: () => '50',
      notify: (message, level) => browserExceptionEvents.push(`notify:${level}:${message}`),
      setRunning: value => browserExceptionStates.push(`running:${value}`),
      setFetching: value => browserExceptionStates.push(`fetching:${value}`),
      setCaptureResult: value => { browserExceptionPayload = value; },
      requestCapture: async () => {
        const error = new Error('network down');
        error.data = { data: { stderr: 'stderr details', partial_capture: { saved_count: 1 } } };
        throw error;
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan browser capture flow preserves success, login, failed and exception states',
      ok: browserFlowResult.status === 'success'
        && browserRequestedBody.system_hotel_id === '20'
        && browserRequestedBody.store_id === 'store-20'
        && browserRequestedBody.poi_id === 'poi-20'
        && browserRequestedBody.poi_name === 'POI 20'
        && browserRequestedBody.partner_id === 'partner-20'
        && browserRequestedBody.ads_url === 'https://ads.example.test/20'
        && browserRequestedBody.sections.join(',') === 'traffic,ads'
        && browserCapturePayload.saved_count === 9
        && browserOnlinePayload.saved_count === 9
        && browserStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && browserEvents.includes('history')
        && browserEvents.includes('profile-status:true')
        && browserEvents.includes('data-sources')
        && browserEvents.includes('notify:info:capture ok')
        && browserLoginResult.status === 'success'
        && browserLoginEvents.includes('profile-status:true')
        && !browserLoginEvents.includes('history')
        && !browserLoginEvents.includes('data-sources')
        && browserLoginEvents.some(event => event.includes('美团 Profile 登录状态已保存'))
        && browserFailedResult.status === 'failed'
        && browserFailedEvents[0] === 'notify:error:browser backend failed'
        && browserFailedStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && browserExceptionResult.status === 'exception'
        && browserExceptionEvents[0] === 'notify:error:抓取失败: network down，请查看结果详情'
        && browserExceptionPayload.stderr === 'stderr details'
        && browserExceptionStates.join('|') === 'running:true|fetching:true|running:false|fetching:false',
      detail: 'runMeituanBrowserCaptureFlow state samples',
    });

    const payloadMissingHotel = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '{}' },
      systemHotelId: null,
    });
    const payloadMissingJson = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '' },
      systemHotelId: '10',
    });
    const payloadInvalidJson = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '{bad json' },
      systemHotelId: '10',
    });
    const payloadInvalidObject = buildMeituanCapturedPayloadSaveContext({
      form: { payloadJson: '[]' },
      systemHotelId: '10',
    });
    const payloadSaveContext = buildMeituanCapturedPayloadSaveContext({
      form: {
        payloadJson: '{"source":"browser","saved_count":2}',
        storeId: ' store-60 ',
        poiId: 'poi-60',
        poiName: 'POI 60',
      },
      systemHotelId: '60',
      hotelName: 'Hotel 60',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan captured payload save context keeps JSON and target gaps explicit',
      ok: payloadMissingHotel.status === 'missing_hotel'
        && payloadMissingHotel.message === '请选择目标酒店'
        && payloadMissingJson.status === 'missing_payload_json'
        && payloadMissingJson.message === '请粘贴抓取结果 JSON'
        && payloadInvalidJson.status === 'invalid_json'
        && payloadInvalidJson.message.includes('抓取结果 JSON 格式不正确')
        && payloadInvalidObject.status === 'invalid_payload_object'
        && payloadInvalidObject.message === '抓取结果必须是 JSON 对象'
        && payloadSaveContext.ok === true
        && payloadSaveContext.requestBody.system_hotel_id === '60'
        && payloadSaveContext.requestBody.payload.store_id === 'store-60'
        && payloadSaveContext.requestBody.payload.poi_id === 'poi-60'
        && payloadSaveContext.requestBody.payload.poi_name === 'POI 60'
        && payloadSaveContext.requestBody.payload.system_hotel_id === 60
        && payloadSaveContext.requestBody.payload.source === 'browser',
      detail: 'buildMeituanCapturedPayloadSaveContext sample',
    });

    const payloadEvents = [];
    const payloadStates = [];
    let payloadRequestedBody = null;
    let payloadCaptureResult = null;
    let payloadOnlineResult = null;
    const payloadFlowResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({
        payloadJson: '{"rooms":[{"id":1}]}',
        storeId: ' store-70 ',
        poiId: 'poi-70',
        poiName: '',
      }),
      getSystemHotelId: () => '70',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => payloadEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => payloadStates.push(`fetching:${value}`),
      setCaptureResult: value => { payloadCaptureResult = value; },
      setOnlineDataResult: value => { payloadOnlineResult = value; },
      requestSave: async body => {
        payloadRequestedBody = body;
        return { code: 200, data: { saved_count: 4, rows: [{ id: 1 }] } };
      },
      refreshOnlineHistory: async () => payloadEvents.push('history'),
    });
    const payloadFailedEvents = [];
    const payloadFailedStates = [];
    const payloadFailedResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '{}', storeId: 'store-failed' }),
      getSystemHotelId: () => '80',
      notify: (message, level) => payloadFailedEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadFailedStates.push(`fetching:${value}`),
      requestSave: async () => ({ code: 500, message: 'save backend failed' }),
    });
    const payloadExceptionEvents = [];
    const payloadExceptionStates = [];
    const payloadExceptionResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '{}', storeId: 'store-exception' }),
      getSystemHotelId: () => '90',
      notify: (message, level) => payloadExceptionEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadExceptionStates.push(`fetching:${value}`),
      requestSave: async () => {
        throw new Error('save network down');
      },
    });
    const payloadGuardEvents = [];
    const payloadGuardResult = await runMeituanCapturedPayloadSaveFlow({
      getForm: () => ({ payloadJson: '' }),
      getSystemHotelId: () => '100',
      notify: (message, level) => payloadGuardEvents.push(`notify:${level}:${message}`),
      setFetching: value => payloadGuardEvents.push(`fetching:${value}`),
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan captured payload save flow preserves success, failed and exception states',
      ok: payloadFlowResult.status === 'success'
        && payloadRequestedBody.system_hotel_id === '70'
        && payloadRequestedBody.payload.store_id === 'store-70'
        && payloadRequestedBody.payload.poi_id === 'poi-70'
        && payloadRequestedBody.payload.poi_name === 'Hotel 70'
        && payloadRequestedBody.payload.system_hotel_id === 70
        && payloadCaptureResult.saved_count === 4
        && payloadOnlineResult.saved_count === 4
        && payloadEvents.includes('notify:info:保存成功，已入库 4 条')
        && payloadEvents.includes('history')
        && payloadStates.join('|') === 'fetching:true|fetching:false'
        && payloadFailedResult.status === 'failed'
        && payloadFailedEvents[0] === 'notify:error:save backend failed'
        && payloadFailedStates.join('|') === 'fetching:true|fetching:false'
        && payloadExceptionResult.status === 'exception'
        && payloadExceptionEvents[0] === 'notify:error:保存失败: save network down'
        && payloadExceptionStates.join('|') === 'fetching:true|fetching:false'
        && payloadGuardResult.status === 'missing_payload_json'
        && payloadGuardEvents.join('|') === 'notify:error:请粘贴抓取结果 JSON',
      detail: 'runMeituanCapturedPayloadSaveFlow state samples',
    });

    const trafficForm = {
      url: ' https://example.test/traffic ',
      partnerId: ' partner-traffic ',
      poiId: ' poi-traffic ',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      cookies: '\nmt-traffic-cookie\n',
      extraParams: 'scope=traffic',
    };
    const normalizedTrafficForm = normalizeMeituanTrafficFetchForm(trafficForm);
    const missingTrafficUrl = validateMeituanTrafficFetchInput({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const missingTrafficPartner = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: '', poiId: 'poi', cookies: 'cookie' });
    const missingTrafficPoi = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: 'p', poiId: '', cookies: 'cookie' });
    const missingTrafficCookie = validateMeituanTrafficFetchInput({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: '' });
    const trafficRequestBody = buildMeituanTrafficFetchRequestBody({
      form: normalizedTrafficForm,
      systemHotelId: '10',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan traffic fetch input and request builder keep missing states explicit',
      ok: normalizedTrafficForm.url === 'https://example.test/traffic'
        && normalizedTrafficForm.partnerId === 'partner-traffic'
        && normalizedTrafficForm.poiId === 'poi-traffic'
        && normalizedTrafficForm.cookies === 'mt-traffic-cookie'
        && normalizedTrafficForm.extraParams === 'scope=traffic'
        && missingTrafficUrl.status === 'missing_url'
        && missingTrafficPartner.status === 'missing_partner_id'
        && missingTrafficPoi.status === 'missing_poi_id'
        && missingTrafficCookie.status === 'missing_cookies'
        && trafficRequestBody.partner_id === 'partner-traffic'
        && trafficRequestBody.poi_id === 'poi-traffic'
        && trafficRequestBody.auto_save === true
        && trafficRequestBody.system_hotel_id === '10'
        && trafficRequestBody.extra_params === 'scope=traffic',
      detail: 'buildMeituanTrafficFetchRequestBody sample',
    });

    const trafficEvents = [];
    const trafficStates = [];
    let trafficOnlinePayload = null;
    let trafficLatestPayload = null;
    let trafficRequestedBody = null;
    let delayedTrafficHistorySettled = false;
    const trafficFlowResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: ' https://example.test/traffic ',
        partnerId: ' partner-flow ',
        poiId: ' poi-flow ',
        cookies: ' mt-traffic-flow-cookie ',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
        extraParams: '{"scope":"flow"}',
      }),
      getSystemHotelId: () => '20',
      notify: (message, level) => trafficEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => trafficStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { trafficOnlinePayload = value; },
      setLatestTrafficData: value => { trafficLatestPayload = value; },
      requestFetch: async body => {
        trafficRequestedBody = body;
        return { code: 200, data: { data: [{ exposure: 10 }], saved_count: 6 } };
      },
      refreshOnlineHistory: async () => trafficEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => trafficEvents.push('refresh-data'),
    });
    const acceptedTrafficEvents = [];
    const acceptedTrafficStates = [];
    let acceptedTrafficOnlinePayload = null;
    let acceptedTrafficLatestPayload = null;
    let acceptedTrafficRequestedBody = null;
    const acceptedTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: 'https://example.test/traffic',
        partnerId: 'partner-flow',
        poiId: 'poi-flow',
        cookies: 'mt-traffic-flow-cookie',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
      }),
      getSystemHotelId: () => '20',
      notify: (message, level) => acceptedTrafficEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedTrafficStates.push(`fetching:${value}`),
      setOnlineDataResult: value => { acceptedTrafficOnlinePayload = value; },
      setLatestTrafficData: value => { acceptedTrafficLatestPayload = value; },
      requestFetch: async body => {
        acceptedTrafficRequestedBody = body;
        return {
          code: 200,
          message: 'traffic queued',
          data: {
            status: 'running',
            task_id: 'mt-traffic-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-02',
            request_end_date: '2026-06-03',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedTrafficEvents.push('history'),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedTrafficEvents.push('refresh-data'),
    });
    const delayedTrafficFlowResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({
        url: 'https://example.test/traffic',
        partnerId: 'partner-flow',
        poiId: 'poi-flow',
        cookies: 'mt-traffic-flow-cookie',
      }),
      setFetching: value => trafficEvents.push(`delayed-fetching:${value}`),
      setOnlineDataResult: () => {},
      setLatestTrafficData: () => {},
      requestFetch: async () => ({ code: 200, data: { data: [{ exposure: 1 }], saved_count: 1 } }),
      refreshOnlineHistory: () => new Promise(resolve => {
        setTimeout(() => {
          delayedTrafficHistorySettled = true;
          trafficEvents.push('delayed-history');
          resolve();
        }, 25);
      }),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => trafficEvents.push('delayed-refresh-data'),
    });
    const delayedTrafficReturnedBeforeHistory = !delayedTrafficHistorySettled;
    const missingTrafficEvents = [];
    const missingTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => missingTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingTrafficEvents.push(`fetching:${value}`),
    });
    const failedTrafficEvents = [];
    const failedTrafficStates = [];
    const failedTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => failedTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedTrafficStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'traffic backend failed' }),
    });
    const exceptionTrafficEvents = [];
    const exceptionTrafficStates = [];
    const exceptionTrafficResult = await runMeituanTrafficFetchFlow({
      getForm: () => ({ url: 'https://example.test/traffic', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => exceptionTrafficEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionTrafficStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan traffic fetch flow preserves success, failed and exception states',
      ok: trafficFlowResult.status === 'success'
        && trafficRequestedBody.partner_id === 'partner-flow'
        && trafficRequestedBody.async === true
        && trafficRequestedBody.poi_id === 'poi-flow'
        && trafficRequestedBody.cookies === 'mt-traffic-flow-cookie'
        && trafficRequestedBody.system_hotel_id === '20'
        && trafficOnlinePayload[0].exposure === 10
        && trafficLatestPayload[0].exposure === 10
        && trafficStates.join('|') === 'fetching:true|fetching:false'
        && trafficEvents.includes('history')
        && trafficEvents.includes('refresh-data')
        && delayedTrafficFlowResult.status === 'success'
        && delayedTrafficReturnedBeforeHistory === true
        && trafficEvents.some(event => event === 'notify:info:获取成功！已保存 6 条流量数据')
        && acceptedTrafficResult.status === 'accepted'
        && acceptedTrafficRequestedBody.async === true
        && acceptedTrafficOnlinePayload.status === 'running'
        && acceptedTrafficOnlinePayload.task_id === 'mt-traffic-task-1'
        && acceptedTrafficLatestPayload.status === 'running'
        && acceptedTrafficEvents.includes('history')
        && acceptedTrafficEvents.includes('refresh-data')
        && acceptedTrafficEvents.includes('notify:info:traffic queued')
        && acceptedTrafficStates.join('|') === 'fetching:true|fetching:false'
        && missingTrafficResult.status === 'missing_url'
        && missingTrafficEvents[0] === 'notify:error:需 Network 请求信息：请输入接口地址'
        && !missingTrafficEvents.some(event => event.startsWith('fetching:'))
        && failedTrafficResult.status === 'failed'
        && failedTrafficEvents[0] === 'notify:error:traffic backend failed'
        && failedTrafficStates.join('|') === 'fetching:true|fetching:false'
        && exceptionTrafficResult.status === 'exception'
        && exceptionTrafficEvents[0] === 'notify:error:请求失败: network down'
        && exceptionTrafficStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanTrafficFetchFlow state samples',
    });

    const orderForm = {
      url: ' https://example.test/orders/list ',
      method: 'post',
      partnerId: ' partner-10 ',
      poiId: ' poi-10 ',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      cookies: '\nmt-cookie\n',
      payloadJson: ' {"pageNo":1} ',
      extraParams: ' {"pageSize":50} ',
    };
    const normalizedOrderForm = normalizeMeituanOrderFetchForm(orderForm);
    const missingOrderUrl = validateMeituanOrderFetchInput({ url: '', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const invalidOrderPageUrl = validateMeituanOrderFetchInput({ url: 'https://eb.meituan.com/order-eb/index.html', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: 'cookie' });
    const missingOrderCookie = validateMeituanOrderFetchInput({ url: 'https://example.test/orders/list', method: 'GET', partnerId: 'p', poiId: 'poi', cookies: '' });
    const orderRequestBody = buildMeituanOrderFetchRequestBody({
      form: normalizedOrderForm,
      systemHotelId: '10',
      hotelName: 'Meituan Demo',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order fetch input and request builder keep missing states explicit',
      ok: normalizedOrderForm.url === 'https://example.test/orders/list'
        && normalizedOrderForm.method === 'POST'
        && normalizedOrderForm.partnerId === 'partner-10'
        && normalizedOrderForm.poiId === 'poi-10'
        && normalizedOrderForm.cookies === 'mt-cookie'
        && normalizedOrderForm.payloadJson === '{"pageNo":1}'
        && normalizedOrderForm.extraParams === '{"pageSize":50}'
        && missingOrderUrl.status === 'missing_url'
        && invalidOrderPageUrl.status === 'invalid_page_url'
        && missingOrderCookie.status === 'missing_cookies'
        && orderRequestBody.partner_id === 'partner-10'
        && orderRequestBody.poi_id === 'poi-10'
        && orderRequestBody.auto_save === true
        && orderRequestBody.system_hotel_id === '10'
        && orderRequestBody.hotel_name === 'Meituan Demo',
      detail: 'buildMeituanOrderFetchRequestBody sample',
    });

    const orderEvents = [];
    const orderStates = [];
    let orderResultPayload = null;
    let orderOnlinePayload = null;
    let orderRequestedBody = null;
    const orderFlowResult = await runMeituanOrderFetchFlow({
      getForm: () => ({
        url: ' https://example.test/orders/list ',
        method: 'get',
        partnerId: ' partner-20 ',
        poiId: ' poi-20 ',
        cookies: ' mt-cookie-20 ',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
        payloadJson: ' {"pageNo":2} ',
        extraParams: '',
      }),
      getSystemHotelId: () => '20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => orderEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => orderStates.push(`fetching:${value}`),
      setOrderResult: value => { orderResultPayload = value; },
      setOnlineDataResult: value => { orderOnlinePayload = value; },
      requestFetch: async body => {
        orderRequestedBody = body;
        return { code: 200, data: { saved_count: 4, row_count: 6 } };
      },
      refreshOnlineHistory: async () => orderEvents.push('history'),
    });
    const acceptedOrderEvents = [];
    const acceptedOrderStates = [];
    let acceptedOrderResultPayload = null;
    let acceptedOrderOnlinePayload = null;
    let acceptedOrderRequestedBody = null;
    const acceptedOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({
        url: 'https://example.test/orders/list',
        method: 'get',
        partnerId: 'partner-20',
        poiId: 'poi-20',
        cookies: 'mt-cookie-20',
        startDate: '2026-06-02',
        endDate: '2026-06-03',
      }),
      getSystemHotelId: () => '20',
      getHotelNameById: id => `Hotel ${id}`,
      notify: (message, level) => acceptedOrderEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedOrderStates.push(`fetching:${value}`),
      setOrderResult: value => { acceptedOrderResultPayload = value; },
      setOnlineDataResult: value => { acceptedOrderOnlinePayload = value; },
      requestFetch: async body => {
        acceptedOrderRequestedBody = body;
        return {
          code: 200,
          message: 'order queued',
          data: {
            status: 'running',
            task_id: 'mt-order-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-02',
            request_end_date: '2026-06-03',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedOrderEvents.push('history'),
    });
    const missingOrderEvents = [];
    const missingOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: '', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => missingOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingOrderEvents.push(`fetching:${value}`),
    });
    const failedOrderStates = [];
    const failedOrderEvents = [];
    const failedOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: 'https://example.test/orders/list', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => failedOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedOrderStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'order backend failed' }),
    });
    const exceptionOrderStates = [];
    const exceptionOrderEvents = [];
    const exceptionOrderResult = await runMeituanOrderFetchFlow({
      getForm: () => ({ url: 'https://example.test/orders/list', partnerId: 'p', poiId: 'poi', cookies: 'cookie' }),
      notify: (message, level) => exceptionOrderEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionOrderStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan order fetch flow preserves success, failed and exception states',
      ok: orderFlowResult.status === 'success'
        && orderRequestedBody.partner_id === 'partner-20'
        && orderRequestedBody.async === true
        && orderRequestedBody.method === 'GET'
        && orderRequestedBody.hotel_name === 'Hotel 20'
        && orderResultPayload.saved_count === 4
        && orderOnlinePayload.row_count === 6
        && orderStates.join('|') === 'fetching:true|fetching:false'
        && orderEvents.includes('history')
        && orderEvents.some(event => event === 'notify:success:订单数据获取成功，已入库 4 条')
        && acceptedOrderResult.status === 'accepted'
        && acceptedOrderRequestedBody.async === true
        && acceptedOrderResultPayload.status === 'running'
        && acceptedOrderResultPayload.task_id === 'mt-order-task-1'
        && acceptedOrderOnlinePayload.status === 'running'
        && acceptedOrderEvents.includes('history')
        && acceptedOrderEvents.includes('notify:info:order queued')
        && acceptedOrderStates.join('|') === 'fetching:true|fetching:false'
        && missingOrderResult.status === 'missing_url'
        && missingOrderEvents[0] === 'notify:error:需 Network 请求信息：请填写订单接口 Request URL'
        && !missingOrderEvents.some(event => event.startsWith('fetching:'))
        && failedOrderResult.status === 'failed'
        && failedOrderEvents[0] === 'notify:error:order backend failed'
        && failedOrderStates.join('|') === 'fetching:true|fetching:false'
        && exceptionOrderResult.status === 'exception'
        && exceptionOrderEvents[0] === 'notify:error:订单数据获取失败: network down'
        && exceptionOrderStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanOrderFetchFlow state samples',
    });

    const adsForm = {
      url: ' https://example.test/cureShops ',
      method: 'post',
      partnerId: ' partner-30 ',
      poiId: ' poi-30 ',
      shopId: ' shop-30 ',
      startDate: '2026-06-04',
      endDate: '2026-06-05',
      cookies: '\nmt-ads-cookie\n',
      payloadJson: ' {"timeUnit":"day"} ',
      extraParams: ' {"scope":"campaign"} ',
    };
    const normalizedAdsForm = normalizeMeituanAdsFetchForm(adsForm);
    const missingAdsUrl = validateMeituanAdsFetchInput({ url: '', shopId: 'shop', cookies: 'cookie' });
    const invalidAdsPageUrl = validateMeituanAdsFetchInput({ url: 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry', shopId: 'shop', cookies: 'cookie' });
    const missingAdsTarget = validateMeituanAdsFetchInput({ url: 'https://example.test/cureShops', shopId: '', poiId: '', cookies: 'cookie' });
    const missingAdsCookie = validateMeituanAdsFetchInput({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: '' });
    const adsRequestBody = buildMeituanAdsFetchRequestBody({
      form: normalizedAdsForm,
      systemHotelId: '30',
      hotelName: 'Ads Hotel',
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan ads fetch input and request builder keep missing states explicit',
      ok: normalizedAdsForm.url === 'https://example.test/cureShops'
        && normalizedAdsForm.method === 'POST'
        && normalizedAdsForm.partnerId === 'partner-30'
        && normalizedAdsForm.poiId === 'poi-30'
        && normalizedAdsForm.shopId === 'shop-30'
        && normalizedAdsForm.cookies === 'mt-ads-cookie'
        && normalizedAdsForm.payloadJson === '{"timeUnit":"day"}'
        && normalizedAdsForm.extraParams === '{"scope":"campaign"}'
        && missingAdsUrl.status === 'missing_url'
        && invalidAdsPageUrl.status === 'invalid_page_url'
        && missingAdsTarget.status === 'missing_shop_or_poi_id'
        && missingAdsCookie.status === 'missing_cookies'
        && adsRequestBody.partner_id === 'partner-30'
        && adsRequestBody.poi_id === 'poi-30'
        && adsRequestBody.shop_id === 'shop-30'
        && adsRequestBody.auto_save === true
        && adsRequestBody.system_hotel_id === '30'
        && adsRequestBody.hotel_name === 'Ads Hotel',
      detail: 'buildMeituanAdsFetchRequestBody sample',
    });

    const adsEvents = [];
    const adsStates = [];
    let adsResultPayload = null;
    let adsOnlinePayload = null;
    let adsRequestedBody = null;
    const adsFlowResult = await runMeituanAdsFetchFlow({
      getForm: () => ({
        url: ' https://example.test/cureShops ',
        method: 'get',
        partnerId: ' partner-40 ',
        poiId: '',
        shopId: ' shop-40 ',
        cookies: ' mt-ads-cookie-40 ',
        startDate: '2026-06-06',
        endDate: '2026-06-07',
        payloadJson: ' {"pageNo":1} ',
        extraParams: '',
      }),
      getSystemHotelId: () => '40',
      getHotelNameById: id => `Ads Hotel ${id}`,
      notify: (message, level) => adsEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => adsStates.push(`fetching:${value}`),
      setAdsResult: value => { adsResultPayload = value; },
      setOnlineDataResult: value => { adsOnlinePayload = value; },
      requestFetch: async body => {
        adsRequestedBody = body;
        return { code: 200, data: { saved_count: 5, row_count: 7 } };
      },
      refreshOnlineHistory: async () => adsEvents.push('history'),
    });
    const acceptedAdsEvents = [];
    const acceptedAdsStates = [];
    let acceptedAdsResultPayload = null;
    let acceptedAdsOnlinePayload = null;
    let acceptedAdsRequestedBody = null;
    const acceptedAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({
        url: 'https://example.test/cureShops',
        method: 'get',
        partnerId: 'partner-40',
        shopId: 'shop-40',
        cookies: 'mt-ads-cookie-40',
        startDate: '2026-06-06',
        endDate: '2026-06-07',
      }),
      getSystemHotelId: () => '40',
      getHotelNameById: id => `Ads Hotel ${id}`,
      notify: (message, level) => acceptedAdsEvents.push(`notify:${level || 'info'}:${message}`),
      setFetching: value => acceptedAdsStates.push(`fetching:${value}`),
      setAdsResult: value => { acceptedAdsResultPayload = value; },
      setOnlineDataResult: value => { acceptedAdsOnlinePayload = value; },
      requestFetch: async body => {
        acceptedAdsRequestedBody = body;
        return {
          code: 200,
          message: 'ads queued',
          data: {
            status: 'running',
            task_id: 'mt-ads-task-1',
            platform: 'meituan',
            async: true,
            saved_count: 0,
            request_start_date: '2026-06-06',
            request_end_date: '2026-06-07',
          },
        };
      },
      refreshOnlineHistory: async () => acceptedAdsEvents.push('history'),
    });
    const missingAdsEvents = [];
    const missingAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: '', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => missingAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => missingAdsEvents.push(`fetching:${value}`),
    });
    const failedAdsStates = [];
    const failedAdsEvents = [];
    const failedAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => failedAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => failedAdsStates.push(`fetching:${value}`),
      requestFetch: async () => ({ code: 500, message: 'ads backend failed' }),
    });
    const exceptionAdsStates = [];
    const exceptionAdsEvents = [];
    const exceptionAdsResult = await runMeituanAdsFetchFlow({
      getForm: () => ({ url: 'https://example.test/cureShops', shopId: 'shop', cookies: 'cookie' }),
      notify: (message, level) => exceptionAdsEvents.push(`notify:${level}:${message}`),
      setFetching: value => exceptionAdsStates.push(`fetching:${value}`),
      requestFetch: async () => {
        throw new Error('network down');
      },
    });
    checks.push({
      file: 'public/meituan-static.js',
      label: 'Meituan ads fetch flow preserves success, failed and exception states',
      ok: adsFlowResult.status === 'success'
        && adsRequestedBody.partner_id === 'partner-40'
        && adsRequestedBody.async === true
        && adsRequestedBody.method === 'GET'
        && adsRequestedBody.poi_id === 'shop-40'
        && adsRequestedBody.shop_id === 'shop-40'
        && adsRequestedBody.hotel_name === 'Ads Hotel 40'
        && adsResultPayload.saved_count === 5
        && adsOnlinePayload.row_count === 7
        && adsStates.join('|') === 'fetching:true|fetching:false'
        && adsEvents.includes('history')
        && adsEvents.some(event => event === 'notify:success:广告数据获取成功，已入库 5 条')
        && acceptedAdsResult.status === 'accepted'
        && acceptedAdsRequestedBody.async === true
        && acceptedAdsResultPayload.status === 'running'
        && acceptedAdsResultPayload.task_id === 'mt-ads-task-1'
        && acceptedAdsOnlinePayload.status === 'running'
        && acceptedAdsEvents.includes('history')
        && acceptedAdsEvents.includes('notify:info:ads queued')
        && acceptedAdsStates.join('|') === 'fetching:true|fetching:false'
        && missingAdsResult.status === 'missing_url'
        && missingAdsEvents[0] === 'notify:error:需 Network 请求信息：请填写广告接口 Request URL'
        && !missingAdsEvents.some(event => event.startsWith('fetching:'))
        && failedAdsResult.status === 'failed'
        && failedAdsEvents[0] === 'notify:error:ads backend failed'
        && failedAdsStates.join('|') === 'fetching:true|fetching:false'
        && exceptionAdsResult.status === 'exception'
        && exceptionAdsEvents[0] === 'notify:error:广告数据获取失败: network down'
        && exceptionAdsStates.join('|') === 'fetching:true|fetching:false',
      detail: 'runMeituanAdsFetchFlow state samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/meituan-static.js',
    label: 'Meituan static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ota-diagnosis-static.js'), context, {
    filename: 'public/ota-diagnosis-static.js',
  });
  const otaDiagnosisStatic = context.window.SUXI_OTA_DIAGNOSIS_STATIC || {};
  const buildOtaDiagnosisFetchContext = otaDiagnosisStatic.buildOtaDiagnosisFetchContext;
  const buildOtaDiagnosisFetchTasks = otaDiagnosisStatic.buildOtaDiagnosisFetchTasks;
  const runOtaDiagnosisHotelFetchFlow = otaDiagnosisStatic.runOtaDiagnosisHotelFetchFlow;
  const runOtaDiagnosisGenerateFlow = otaDiagnosisStatic.runOtaDiagnosisGenerateFlow;
  if (typeof buildOtaDiagnosisFetchContext !== 'function'
    || typeof buildOtaDiagnosisFetchTasks !== 'function'
    || typeof runOtaDiagnosisHotelFetchFlow !== 'function'
    || typeof runOtaDiagnosisGenerateFlow !== 'function') {
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis static exports fetch/generate builders and flow runners',
      ok: false,
      detail: 'buildOtaDiagnosisFetchContext/buildOtaDiagnosisFetchTasks/runOtaDiagnosisHotelFetchFlow/runOtaDiagnosisGenerateFlow',
    });
  } else {
    const fetchContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '10', hotel_id: '10' },
      form: { hotel_id: '10', start_date: '2026-06-01', end_date: '2026-06-10' },
      ctripConfig: { url: 'ctrip-url', node_id: '24588', cookies: 'ctrip-cookie', auth_data: { ok: true }, ctrip_hotel_id: 'ctrip-10', name: 'Ctrip Demo' },
      ctripTrafficConfig: { url: 'traffic-url', cookies: 'traffic-cookie', platform: 'Ctrip', extra_params: 'foo=1' },
      ctripCookieApiConfig: { endpoints_json: '[{"request_url":"u"}]', headers_json: 'Cookie: header-cookie', profile_id: 'profile-10', method: 'POST', system_hotel_id: '10', ctrip_hotel_id: 'hotel-10' },
      meituanConfig: { url: 'meituan-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'meituan-cookie', data_scope: 'vpoi' },
      meituanTrafficConfig: { url: 'meituan-traffic-url', partner_id: 'partner-1', poi_id: 'poi-1', cookies: 'mt-cookie', system_hotel_id: '10' },
    });
    const tasks = buildOtaDiagnosisFetchTasks({ context: fetchContext });
    const taskLabels = tasks.map(task => task.label);
    const cookieApiTask = tasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps Ctrip and Meituan task coverage',
      ok: fetchContext.systemHotelId === '10'
        && fetchContext.ctripCookieApiCookies === 'header-cookie'
        && fetchContext.hasCtripCookieApiRequests === true
        && taskLabels.includes('ctrip-business')
        && taskLabels.includes('ctrip-traffic')
        && taskLabels.includes('ctrip-cookie-api')
        && taskLabels.includes('meituan-P_RZ')
        && taskLabels.includes('meituan-P_LL')
        && taskLabels.includes('meituan-traffic')
        && cookieApiTask?.body?.request_source === 'saved_config',
      detail: 'buildOtaDiagnosisFetchTasks saved config sample',
    });
    const coreContext = buildOtaDiagnosisFetchContext({
      selectedHotel: { system_hotel_id: '20' },
      form: { hotel_id: '20', start_date: '2026-06-02', end_date: '2026-06-02' },
      ctripCookieApiConfig: { profile_id: 'profile-20' },
    });
    const coreTasks = buildOtaDiagnosisFetchTasks({
      context: coreContext,
      genericCtripCookie: { cookies: 'generic-cookie' },
      useCtripCorePresetForDiagnosis: true,
      ctripCorePresetReason: 'generic_cookie',
      ctripCorePresetJson: '[{"request_url":"core"}]',
    });
    const coreTask = coreTasks.find(task => task.label === 'ctrip-cookie-api');
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch task builder keeps core preset source explicit',
      ok: coreTask?.body?.request_source === 'core_preset:generic_cookie'
        && coreTask?.body?.cookies === 'generic-cookie'
        && coreTask?.body?.endpoints_json === '[{"request_url":"core"}]',
      detail: 'core_preset',
    });
    const flowEvents = [];
    const flowStatuses = [];
    const flowResult = await runOtaDiagnosisHotelFetchFlow({
      selectedHotel: { system_hotel_id: '30' },
      form: { hotel_id: '30', start_date: '2026-06-03', end_date: '2026-06-03' },
      readSavedOtaDataConfig: async type => {
        flowEvents.push({ type: 'config', source: type });
        if (type === 'ctrip-cookie-api') return { profile_id: 'profile-30', system_hotel_id: '30' };
        return {};
      },
      readSavedGenericCookieForDiagnosis: async systemHotelId => {
        flowEvents.push({ type: 'generic_cookie', systemHotelId });
        return null;
      },
      checkCtripProfileStatus: async ({ systemHotelId, profileId }) => {
        flowEvents.push({ type: 'profile_status', systemHotelId, profileId });
        return { exists: true, profile_id: profileId };
      },
      applyCtripProfileStatus: status => flowStatuses.push(status),
      getCtripCookieApiCorePresetJson: () => '[{"request_url":"core-flow"}]',
      requestTask: async task => {
        flowEvents.push({ type: 'task', task });
        return { code: 200, message: 'ok', data: { saved_count: 3 } };
      },
      notify: message => flowEvents.push({ type: 'notify', message }),
    });
    const flowTaskEvent = flowEvents.find(event => event.type === 'task') || {};
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis fetch flow keeps profile core preset and task summary explicit',
      ok: flowResult.attempted === 1
        && flowResult.success === 1
        && flowResult.failed === 0
        && flowResult.results[0]?.label === 'ctrip-cookie-api'
        && flowResult.results[0]?.saved_count === 3
        && flowResult.results[0]?.request_source === 'core_preset:profile'
        && flowStatuses[0]?.profile_id === 'profile-30'
        && flowTaskEvent.task?.body?.endpoints_json === '[{"request_url":"core-flow"}]'
        && flowTaskEvent.task?.body?.request_source === 'core_preset:profile'
        && flowEvents.some(event => event.type === 'notify'),
      detail: 'runOtaDiagnosisHotelFetchFlow profile preset sample',
    });

    const generateEvents = [];
    const generateLoading = [];
    let generateError = 'seed';
    let generateResult = 'seed';
    let generateEmpty = true;
    let generateRequestBody = null;
    const generateResultStatus = await runOtaDiagnosisGenerateFlow({
      form: {
        hotel_id: 'hotel-key',
        platform: 'ctrip',
        start_date: '2026-06-04',
        end_date: '2026-06-05',
      },
      hotelOptions: [{
        value: 'hotel-key',
        hotel_id: '40',
        platform_hotel_id: 'platform-40',
        config_id: 'config-40',
        source: 'system',
        name: 'Hotel 40',
      }],
      getModelKey: () => 'deepseek-chat',
      runHotelFetch: async (selectedHotel, form) => {
        generateEvents.push({ type: 'fetch', selectedHotel, form });
        return {
          attempted: 2,
          success: 1,
          failed: 1,
          results: [
            { label: 'ctrip-cookie-api', success: true, message: 'ok' },
            { label: 'meituan-traffic', success: false, message: 'missing auth' },
          ],
        };
      },
      requestDiagnosis: async body => {
        generateRequestBody = body;
        return {
          code: 200,
          data: {
            diagnosis: { summary: '流量稳定' },
            metrics: { record_count: 12 },
          },
        };
      },
      setLoading: value => { generateLoading.push(value); },
      setError: value => { generateError = value; },
      setResult: value => { generateResult = value; },
      setEmpty: value => { generateEmpty = value; },
      notify: (message, level) => generateEvents.push({ type: 'notify', message, level: level || '' }),
    });
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis generate flow keeps fetch warning and success state explicit',
      ok: generateResultStatus.status === 'success'
        && generateLoading[0] === true
        && generateLoading[generateLoading.length - 1] === false
        && generateError === ''
        && generateEmpty === false
        && generateResult?.metrics?.record_count === 12
        && generateRequestBody?.hotel_id === '40'
        && generateRequestBody?.platform_hotel_id === 'platform-40'
        && generateRequestBody?.model_key === 'deepseek-chat'
        && generateEvents.some(event => event.type === 'fetch' && event.selectedHotel?.hotel_id === '40')
        && generateEvents.some(event => event.type === 'notify' && event.level === 'warning' && event.message.includes('继续使用已入库数据生成诊断'))
        && generateEvents.some(event => event.type === 'notify' && event.message === 'OTA诊断已生成'),
      detail: 'runOtaDiagnosisGenerateFlow success sample',
    });

    const missingLoading = [];
    let missingError = '';
    const missingHotelResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: '', platform: 'ctrip', start_date: '2026-06-04', end_date: '2026-06-05' },
      setLoading: value => { missingLoading.push(value); },
      setError: value => { missingError = value; },
    });
    const failedLoading = [];
    let failedError = '';
    let failedRequestBody = null;
    const failedResponseResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: 'fallback-hotel', platform: 'meituan', start_date: '2026-06-06', end_date: '2026-06-06' },
      hotelOptions: [],
      getModelKey: () => 'deepseek-reasoner',
      runHotelFetch: async () => ({ attempted: 0, success: 0, failed: 0, results: [] }),
      requestDiagnosis: async body => {
        failedRequestBody = body;
        return { code: 500, message: 'backend failed' };
      },
      setLoading: value => { failedLoading.push(value); },
      setError: value => { failedError = value; },
    });
    const exceptionLoading = [];
    let exceptionError = '';
    const exceptionResult = await runOtaDiagnosisGenerateFlow({
      form: { hotel_id: 'fallback-hotel', platform: 'ctrip', start_date: '2026-06-07', end_date: '2026-06-07' },
      runHotelFetch: async () => ({ attempted: 0, success: 0, failed: 0, results: [] }),
      requestDiagnosis: async () => {
        throw new Error('network down');
      },
      setLoading: value => { exceptionLoading.push(value); },
      setError: value => { exceptionError = value; },
    });
    checks.push({
      file: 'public/ota-diagnosis-static.js',
      label: 'OTA diagnosis generate flow keeps missing, failed and exception states visible',
      ok: missingHotelResult.status === 'missing_hotel'
        && missingError === '请选择酒店'
        && missingLoading.length === 0
        && failedResponseResult.status === 'failed'
        && failedError === 'backend failed'
        && failedRequestBody?.hotel_id === 'fallback-hotel'
        && failedRequestBody?.model_key === 'deepseek-reasoner'
        && failedLoading[0] === true
        && failedLoading[failedLoading.length - 1] === false
        && exceptionResult.status === 'exception'
        && exceptionResult.errorMessage === 'network down'
        && exceptionError === 'network down'
        && exceptionLoading[0] === true
        && exceptionLoading[exceptionLoading.length - 1] === false,
      detail: 'runOtaDiagnosisGenerateFlow error-state samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ota-diagnosis-static.js',
    label: 'OTA diagnosis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ai-analysis-static.js'), context, {
    filename: 'public/ai-analysis-static.js',
  });
  const aiAnalysisStatic = context.window.SUXI_AI_ANALYSIS_STATIC || {};
  const requiredKeys = [
    'getAiAnalysisHotelKey',
    'sanitizeAiReportHtml',
    'aiReportHtmlToText',
    'aiAnalysisStatusText',
    'aiAnalysisPriorityText',
    'normalizeAiAnalysisList',
    'normalizeAiProblemHotels',
    'maskAiAnalysisError',
    'chunkArray',
    'resolveAiSelectedData',
    'validateCapturedOtaAiAnalysisStart',
    'buildCapturedOtaHotelPayload',
    'buildCtripAiAnalysisHotelSelection',
    'buildAiAnalysisProgress',
    'buildAiAnalysisBatchResults',
    'buildCapturedOtaAnalysisRunPlan',
    'buildCapturedOtaAnalysisStartContext',
    'buildCapturedOtaAnalysisRunContext',
    'buildCapturedOtaGroupOutcome',
    'applyCapturedOtaGroupRunState',
    'buildCapturedOtaSummaryRequestBody',
    'buildCapturedOtaSummaryContext',
    'buildCapturedOtaSummaryResponseResult',
    'buildCapturedFallbackSummaryReport',
    'buildAiAnalysisHistoryRecord',
    'buildCapturedOtaAnalysisCompletion',
    'runCapturedOtaAnalysisExecution',
    'runCapturedOtaAnalysisStartFlow',
    'getMeituanAiAnalysisHotelKey',
    'buildMeituanAiAnalysisHotelList',
    'resolveMeituanAiSelectedData',
    'validateMeituanAiAnalysisStart',
    'buildMeituanAiAnalysisRequestBody',
    'buildMeituanAiAnalysisHistoryRecord',
    'runMeituanAiAnalysisFlow',
  ];
  const missingKeys = requiredKeys.filter(key => typeof aiAnalysisStatic[key] !== 'function');
  if (missingKeys.length > 0) {
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static exports required builders',
      ok: false,
      detail: missingKeys.join(', '),
    });
  } else {
    const hotelPayload = aiAnalysisStatic.buildCapturedOtaHotelPayload({
      poiId: 'ctrip-10',
      hotelName: '示例酒店',
      roomNights: '2',
      roomRevenue: '360',
      exposure: '1200',
      views: '88',
      totalOrderNum: '6',
      viewConversion: '7.5',
      payConversion: '3.2',
      amountRank: '5',
      quantityRank: '3',
      commentScore: '4.8',
    });
    const groups = aiAnalysisStatic.chunkArray([hotelPayload, { hotel_name: 'B' }, { hotel_name: 'C' }], 2);
    const hotelSelection = aiAnalysisStatic.buildCtripAiAnalysisHotelSelection({
      ctripHotels: [
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          quantity: 2,
          amount: 300,
          views: 10,
          exposure: 100,
          amountRank: 5,
        },
        {
          hotelId: 'h1',
          hotelName: 'Alpha',
          roomNights: 3,
          roomRevenue: 480,
          salesRoomNights: 4,
          sales: 620,
          totalDetailNum: 20,
          exposure: 200,
          amountRank: 2,
          quantityRank: 4,
        },
        {
          id: 'h2',
          name: 'Beta',
          convertionRate: '6.5',
          qunarDetailCRRank: 3,
        },
      ],
      selectedKeys: ['h1_Alpha', 'missing_Key'],
    });
    const progress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 3, groupCount: groups.length });
    const batchResults = aiAnalysisStatic.buildAiAnalysisBatchResults(groups, 12345);
    const runPlan = aiAnalysisStatic.buildCapturedOtaAnalysisRunPlan({
      selectedData: [
        {
          poiId: 'r1',
          hotelName: 'Run One',
          roomNights: 2,
          roomRevenue: 500,
        },
        {
          poiId: 'r2',
          hotelName: 'Run Two',
          roomNights: 1,
          sales: 260,
        },
        {
          poiId: 'r3',
          hotelName: 'Run Three',
          roomNights: 1,
          sales: 220,
        },
        {
          poiId: 'r4',
          hotelName: 'Run Four',
          roomNights: 1,
          sales: 180,
        },
      ],
      isDeepSeekPro: true,
      timestamp: 67890,
    });
    const startContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: ['r1_Run One'],
      hotels: [
        { poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 },
        { poiId: 'r2', hotelName: 'Run Two', roomNights: 1, sales: 260 },
      ],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingStartContext = aiAnalysisStatic.buildCapturedOtaAnalysisStartContext({
      selectedKeys: [],
      hotels: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const runContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: startContext.selectedData,
      isDeepSeekPro: false,
      timestamp: 24680,
    });
    const emptyRunContext = aiAnalysisStatic.buildCapturedOtaAnalysisRunContext({
      selectedData: [],
      isDeepSeekPro: false,
      timestamp: 13579,
    });
    const selectedRows = aiAnalysisStatic.resolveAiSelectedData(
      ['r1_Run One', 'missing_Key'],
      [
        { poiId: 'r1', hotelName: 'Run One' },
        { poiId: 'r2', hotelName: 'Run Two' },
      ],
    );
    const missingSelectedValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: [],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDataValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const missingDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '',
      endDate: '',
    });
    const invalidDateValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-10',
      endDate: '2026-06-01',
    });
    const validStartValidation = aiAnalysisStatic.validateCapturedOtaAiAnalysisStart({
      selectedKeys: ['r1_Run One'],
      selectedData: [{ poiId: 'r1', hotelName: 'Run One' }],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
    });
    const successGroup = {
      ...batchResults[0],
      status: 'success',
      result: {
        overall_conclusion: '订单转化偏弱',
        key_findings: ['曝光充足'],
        competitor_insights: ['竞对价格更稳'],
        problem_hotels: ['酒店：示例酒店；问题：转化偏低；关键指标：曝光、订单；建议：复核价格'],
        recommended_actions: ['调整促销'],
        priority: 'high',
        data_anomalies: [],
      },
    };
    const summaryBody = aiAnalysisStatic.buildCapturedOtaSummaryRequestBody({
      platform: 'ctrip',
      modelKey: 'deepseek_chat',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      selectedHotelCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const fallback = aiAnalysisStatic.buildCapturedFallbackSummaryReport({
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
      reason: 'Bearer token-secret',
    });
    const summaryContext = aiAnalysisStatic.buildCapturedOtaSummaryContext({
      hotelsPayload: runPlan.hotelsPayload,
      progress: { completedHotels: '3', failedHotels: '1' },
      batchResults: runPlan.batchResults,
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'failed' }],
    });
    const summarySuccessResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: {
        code: 200,
        data: {
          report: { overall_conclusion: '汇总成功' },
          process: { steps: ['汇总'] },
        },
      },
      successGroups: [successGroup],
      failedGroups: [],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const summaryFallbackResult = aiAnalysisStatic.buildCapturedOtaSummaryResponseResult({
      response: { code: 500, message: 'Bearer token-secret' },
      successGroups: [successGroup],
      failedGroups: [{ group_index: 2, error: 'sk-secret12345678' }],
      selectedCount: 3,
      completedHotels: 2,
      failedHotels: 1,
      groupCount: 2,
    });
    const history = aiAnalysisStatic.buildAiAnalysisHistoryRecord({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成' },
      completedHotels: 2,
      failedHotels: 1,
      reportHtml: '<section>ok</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const completion = aiAnalysisStatic.buildCapturedOtaAnalysisCompletion({
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }, { hotelName: 'C' }, { hotelName: 'D' }],
      capturedReport: { overall_conclusion: '已完成', key_findings: ['曝光充足'] },
      completedHotels: 2,
      failedHotels: 1,
      existingHistory: [{ id: 1 }, { id: 2 }, { id: 3 }],
      historyLimit: 3,
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const groupOutcome = aiAnalysisStatic.buildCapturedOtaGroupOutcome([
      { groupIndex: 1, hotelCount: 2, status: 'success', result: { priority: 'medium' } },
      { groupIndex: 2, hotelCount: 1, status: 'failed', error: 'model failed' },
      { groupIndex: 3, hotelCount: 1, status: 'pending', error: 'timeout' },
    ]);
    const groupStateSuccess = { status: 'running', result: null };
    const progressStateSuccess = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateSuccess,
      progressState: progressStateSuccess,
      group: [{ hotel_name: 'A' }, { hotel_name: 'B' }],
      result: { ok: true, result: { overall_conclusion: '成功' } },
    });
    const groupStateFailure = { status: 'running', error: '', errorDetails: null };
    const progressStateFailure = { completedHotels: 0, failedHotels: 0 };
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      groupState: groupStateFailure,
      result: { ok: false, error: 'failed', errorDetails: { error_type: 'model_error' } },
    });
    aiAnalysisStatic.applyCapturedOtaGroupRunState({
      progressState: progressStateFailure,
      result: { ok: false },
      retryResult: { successCount: '1', failedCount: '2' },
    });
    const runnerGroups = [[{ hotel_name: 'A' }], [{ hotel_name: 'B' }]];
    const runnerBatchResults = aiAnalysisStatic.buildAiAnalysisBatchResults(runnerGroups, 9988);
    const runnerProgress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 2, groupCount: 2 });
    const runnerRequests = [];
    const runnerSummaryContexts = [];
    const runnerResult = await aiAnalysisStatic.runCapturedOtaAnalysisExecution({
      groups: runnerGroups,
      batchResults: runnerBatchResults,
      progressState: runnerProgress,
      hotelsPayload: runnerGroups.flat(),
      selectedData: [{ hotelName: 'A' }, { hotelName: 'B' }],
      existingHistory: [{ id: 'old' }],
      requestGroup: async group => {
        runnerRequests.push(group[0]?.hotel_name || '');
        if (group[0]?.hotel_name === 'B') {
          return { ok: false, error: 'temporary failed', errorDetails: { error_type: 'model_error' } };
        }
        return {
          ok: true,
          result: {
            overall_conclusion: 'A成功',
            key_findings: ['A有效'],
            recommended_actions: ['继续观察'],
          },
        };
      },
      retryGroup: async (group, groupState) => {
        groupState.status = 'success';
        groupState.result = {
          overall_conclusion: `${group[0]?.hotel_name || ''}重试成功`,
          key_findings: ['重试有效'],
          recommended_actions: ['复核转化'],
        };
        groupState.retried = true;
        return { successCount: 1, failedCount: 0 };
      },
      requestSummary: async summaryContext => {
        runnerSummaryContexts.push(summaryContext);
        return {
          report: {
            overall_conclusion: '汇总完成',
            key_findings: ['样本有效'],
            recommended_actions: ['保留显式状态'],
          },
          process: { steps: ['summary'] },
        };
      },
    });
    const failedRunnerBatchResults = aiAnalysisStatic.buildAiAnalysisBatchResults([[{ hotel_name: 'Failed' }]], 9989);
    const failedRunnerProgress = aiAnalysisStatic.buildAiAnalysisProgress({ hotelCount: 1, groupCount: 1 });
    const failedRunnerResult = await aiAnalysisStatic.runCapturedOtaAnalysisExecution({
      groups: [[{ hotel_name: 'Failed' }]],
      batchResults: failedRunnerBatchResults,
      progressState: failedRunnerProgress,
      hotelsPayload: [{ hotel_name: 'Failed' }],
      selectedData: [{ hotelName: 'Failed' }],
      requestGroup: async () => ({ ok: false, error: 'sk-secret12345678' }),
      retryGroup: async (group, groupState) => {
        groupState.status = 'failed';
        groupState.error = 'sk-secret12345678';
        return { successCount: 0, failedCount: group.length };
      },
      requestSummary: async () => ({ report: {}, process: null }),
      maskError: value => `masked:${aiAnalysisStatic.maskAiAnalysisError(value)}`,
    });
    const startFlowEvents = [];
    const startFlowStates = [];
    let startFlowProgress = null;
    let startFlowBatchResults = null;
    let startFlowCompletion = null;
    let startFlowCapturedError = 'seed';
    const startFlowResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: ['r1_Run One'],
      hotels: [
        { poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 },
        { poiId: 'r2', hotelName: 'Run Two', roomNights: 1, sales: 260 },
      ],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      isDeepSeekPro: false,
      existingHistory: [{ id: 'old-start-flow' }],
      notify: (message, level = 'success') => startFlowEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowStates.push(['analyzing', value]),
      resetState: () => startFlowEvents.push(['reset']),
      setProgress: value => { startFlowProgress = value; },
      setBatchResults: value => { startFlowBatchResults = value; },
      setCompletion: value => { startFlowCompletion = value; },
      setCapturedError: value => { startFlowCapturedError = value; },
      requestGroup: async group => ({ ok: true, result: { overall_conclusion: `${group[0]?.hotel_name || ''} ok` } }),
      retryGroup: async () => ({ successCount: 0, failedCount: 0 }),
      requestSummary: async summaryContext => ({
        report: {
          overall_conclusion: `summary ${summaryContext.completedHotels}`,
          key_findings: ['start flow ok'],
          recommended_actions: ['continue'],
        },
        process: { steps: ['start-flow-summary'] },
      }),
    });
    const startFlowGuardEvents = [];
    const startFlowGuardStates = [];
    const startFlowGuardResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: [],
      hotels: [],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      notify: (message, level) => startFlowGuardEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowGuardStates.push(['analyzing', value]),
    });
    const startFlowExceptionEvents = [];
    const startFlowExceptionStates = [];
    let startFlowExceptionError = '';
    const startFlowExceptionResult = await aiAnalysisStatic.runCapturedOtaAnalysisStartFlow({
      selectedKeys: ['r1_Run One'],
      hotels: [{ poiId: 'r1', hotelName: 'Run One', roomNights: 2, roomRevenue: 500 }],
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      notify: (message, level = 'success') => startFlowExceptionEvents.push(['notify', level, message]),
      setAnalyzing: value => startFlowExceptionStates.push(['analyzing', value]),
      resetState: () => startFlowExceptionEvents.push(['reset']),
      setCapturedError: value => { startFlowExceptionError = value; },
      requestGroup: async () => { throw new Error('network down'); },
      retryGroup: async () => ({ successCount: 0, failedCount: 0 }),
      requestSummary: async () => ({ report: {}, process: null }),
    });
    const meituanHotels = aiAnalysisStatic.buildMeituanAiAnalysisHotelList([
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '2', roomRevenue: '300', views: '40' },
      { poiId: 'm1', hotelName: 'Meituan One', roomNights: '5', roomRevenue: '800', views: '80' },
      { poiId: 'm2', hotelName: 'Meituan Two', sales: '260', exposure: '900' },
    ]);
    const meituanSelectedData = aiAnalysisStatic.resolveMeituanAiSelectedData(['m1_Meituan One', 'missing_Key'], meituanHotels);
    const meituanRequestBody = aiAnalysisStatic.buildMeituanAiAnalysisRequestBody(meituanSelectedData);
    const meituanHistory = aiAnalysisStatic.buildMeituanAiAnalysisHistoryRecord({
      selectedData: [...meituanSelectedData, { hotelName: 'Meituan Extra A' }, { hotelName: 'Meituan Extra B' }],
      summary: 'Meituan summary',
      report: '<section>meituan</section>',
      now: new Date('2026-06-10T00:00:00+08:00'),
    });
    const meituanMissingSelection = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: [],
      hotels: meituanHotels,
    });
    const meituanMissingData = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: ['missing_Key'],
      hotels: meituanHotels,
    });
    const meituanValidStart = aiAnalysisStatic.validateMeituanAiAnalysisStart({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
    });
    const meituanFlowEvents = [];
    const meituanFlowStates = [];
    let meituanFlowRequestBody = null;
    let meituanFlowResultHtml = '';
    let meituanFlowHistory = [];
    const oldMeituanHistory = Array.from({ length: 10 }, (_, index) => ({ id: `old-${index}` }));
    const meituanFlowResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async body => {
        meituanFlowRequestBody = body;
        return { code: 200, data: { report: '<section>美团报告</section>', summary: '美团汇总' } };
      },
      notify: (message, level) => meituanFlowEvents.push(`notify:${level || 'info'}:${message}`),
      setAnalyzing: value => meituanFlowStates.push(`analyzing:${value}`),
      setResult: value => { meituanFlowResultHtml = value; },
      getHistory: () => oldMeituanHistory,
      setHistory: value => { meituanFlowHistory = value; },
      sanitizeReport: value => `safe:${value}`,
      now: () => new Date('2026-06-10T00:00:00+08:00'),
    });
    const meituanFailedEvents = [];
    const meituanFailedStates = [];
    let meituanFailedResultHtml = 'before';
    const meituanFailedResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async () => ({ code: 500, message: 'backend failed' }),
      notify: (message, level) => meituanFailedEvents.push(`notify:${level || 'info'}:${message}`),
      setAnalyzing: value => meituanFailedStates.push(`analyzing:${value}`),
      setResult: value => { meituanFailedResultHtml = value; },
    });
    const meituanExceptionEvents = [];
    const meituanExceptionLogs = [];
    const meituanExceptionStates = [];
    let meituanExceptionResultHtml = 'before';
    const meituanExceptionResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: ['m1_Meituan One'],
      hotels: meituanHotels,
      requestAnalysis: async () => { throw new Error('network down'); },
      notify: (message, level) => meituanExceptionEvents.push(`notify:${level}:${message}`),
      setAnalyzing: value => meituanExceptionStates.push(`analyzing:${value}`),
      setResult: value => { meituanExceptionResultHtml = value; },
      logError: (...args) => meituanExceptionLogs.push(args.map(item => item?.message || item).join('|')),
    });
    const meituanGuardEvents = [];
    const meituanGuardStates = [];
    const meituanGuardResult = await aiAnalysisStatic.runMeituanAiAnalysisFlow({
      selectedKeys: [],
      hotels: meituanHotels,
      notify: (message, level) => meituanGuardEvents.push(`notify:${level}:${message}`),
      setAnalyzing: value => meituanGuardStates.push(`analyzing:${value}`),
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA payload and batch state',
      ok: hotelPayload.hotel_id === 'ctrip-10'
        && hotelPayload.price === 180
        && hotelPayload.exposure === 1200
        && hotelPayload.tags.includes('最好排名3')
        && groups.length === 2
        && progress.totalHotels === 3
        && progress.totalGroups === 2
        && batchResults[0].key === 'group_12345_0'
        && batchResults[0].hotelNames.includes('示例酒店'),
      detail: 'captured payload batch sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA run plans with model-aware group sizing',
      ok: runPlan.hotelsPayload.length === 4
        && runPlan.groups.length === 2
        && runPlan.groups[0].length === 3
        && runPlan.groups[1].length === 1
        && runPlan.progress.totalHotels === 4
        && runPlan.progress.totalGroups === 2
        && runPlan.batchResults[0].key === 'group_67890_0'
        && runPlan.batchResults[0].hotelNames.includes('Run One')
        && runPlan.batchResults[1].hotelCount === 1
        && startContext.ok === true
        && startContext.selectedData.length === 1
        && startContext.selectedData[0].hotelName === 'Run One'
        && missingStartContext.ok === false
        && runContext.ok === true
        && runContext.message.includes('开始分析 1 家酒店')
        && runContext.batchResults[0].key === 'group_24680_0'
        && emptyRunContext.ok === false
        && emptyRunContext.message === '暂无抓取数据',
      detail: 'captured OTA run plan sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static resolves selections and group outcomes',
      ok: selectedRows.length === 1
        && selectedRows[0].hotelName === 'Run One'
        && groupOutcome.successGroups.length === 1
        && groupOutcome.failedGroups.length === 2
        && groupOutcome.failedGroups[0].group_index === 2
        && groupOutcome.failedGroups[1].hotel_count === 1
        && groupOutcome.failedReason.includes('第 2 组：model failed')
        && groupOutcome.failedReason.includes('第 3 组：timeout')
        && groupStateSuccess.status === 'success'
        && groupStateSuccess.result.overall_conclusion === '成功'
        && progressStateSuccess.completedHotels === 2
        && groupStateFailure.error === 'failed'
        && groupStateFailure.errorDetails.error_type === 'model_error'
        && progressStateFailure.completedHotels === 1
        && progressStateFailure.failedHotels === 2,
      detail: 'selected data and group outcome sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static validates captured OTA start inputs',
      ok: missingSelectedValidation.ok === false
        && missingSelectedValidation.message === '请先选择要分析的酒店'
        && missingDataValidation.ok === false
        && missingDataValidation.message === '未找到选中的酒店数据'
        && missingDateValidation.ok === false
        && missingDateValidation.message === '请选择分析日期范围'
        && invalidDateValidation.ok === false
        && invalidDateValidation.message === '开始日期不能晚于结束日期'
        && validStartValidation.ok === true
        && validStartValidation.level === 'success',
      detail: 'captured OTA start validation sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis start flow preserves captured OTA success and visible failure states',
      ok: startFlowResult.status === 'success'
        && startFlowProgress?.totalHotels === 1
        && Array.isArray(startFlowBatchResults)
        && startFlowBatchResults.length === 1
        && startFlowCompletion?.capturedReport?.overall_conclusion === 'summary 1'
        && startFlowCompletion?.history?.[0]?.summary === 'summary 1'
        && String(startFlowCompletion?.history?.[0]?.report || '').includes('summary 1')
        && startFlowEvents.some(event => event[0] === 'reset')
        && startFlowEvents.some(event => event[0] === 'notify' && event[2] === 'AI分析完成')
        && startFlowStates[0]?.[0] === 'analyzing'
        && startFlowStates[0]?.[1] === true
        && startFlowStates[startFlowStates.length - 1]?.[1] === false
        && startFlowCapturedError === 'seed'
        && startFlowGuardResult.status === 'invalid_start'
        && startFlowGuardEvents.some(event => event[0] === 'notify' && event[1] === 'error')
        && startFlowGuardStates.length === 0
        && startFlowExceptionResult.status === 'exception'
        && startFlowExceptionError === 'network down'
        && startFlowExceptionEvents.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('network down'))
        && startFlowExceptionStates[0]?.[1] === true
        && startFlowExceptionStates[startFlowExceptionStates.length - 1]?.[1] === false,
      detail: 'runCapturedOtaAnalysisStartFlow samples',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Ctrip hotel selections without losing merged metrics',
      ok: hotelSelection.hotels.length === 2
        && hotelSelection.selectedKeys.length === 1
        && hotelSelection.selectedKeys[0] === 'h1_Alpha'
        && hotelSelection.hotels[0].poiId === 'h1'
        && hotelSelection.hotels[0].hotelName === 'Alpha'
        && hotelSelection.hotels[0].roomNights === 3
        && hotelSelection.hotels[0].roomRevenue === 480
        && hotelSelection.hotels[0].salesRoomNights === 4
        && hotelSelection.hotels[0].sales === 620
        && hotelSelection.hotels[0].views === 20
        && hotelSelection.hotels[0].exposure === 200
        && hotelSelection.hotels[0].amountRank === 2
        && hotelSelection.hotels[0].quantityRank === 4
        && hotelSelection.hotels[1].poiId === 'h2'
        && hotelSelection.hotels[1].convertionRate === 6.5,
      detail: 'Ctrip AI hotel selection sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds Meituan hotel selections and request bodies',
      ok: meituanHotels.length === 2
        && meituanHotels[0].poiId === 'm1'
        && meituanHotels[0].roomNights === '2'
        && meituanSelectedData.length === 1
        && meituanMissingSelection.status === 'missing_selection'
        && meituanMissingSelection.message === '请先选择要分析的酒店'
        && meituanMissingData.status === 'missing_selected_data'
        && meituanMissingData.message === '未找到选中的酒店数据'
        && meituanValidStart.ok === true
        && meituanValidStart.selectedData.length === 1
        && meituanRequestBody.total_hotels === 1
        && meituanRequestBody.source === 'meituan'
        && meituanRequestBody.include_suggestions === true
        && meituanHistory.hotel_count === 3
        && meituanHistory.hotel_names === 'Meituan One、Meituan Extra A、Meituan Extra B'
        && meituanHistory.summary === 'Meituan summary',
      detail: 'Meituan AI selection request history sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static runs Meituan analysis flow with explicit success, failure and guard states',
      ok: meituanFlowResult.status === 'success'
        && meituanFlowRequestBody.source === 'meituan'
        && meituanFlowRequestBody.total_hotels === 1
        && meituanFlowResultHtml === 'safe:<section>美团报告</section>'
        && meituanFlowHistory.length === 10
        && meituanFlowHistory[0].summary === '美团汇总'
        && meituanFlowHistory[0].report === 'safe:<section>美团报告</section>'
        && meituanFlowHistory[9].id === 'old-8'
        && meituanFlowStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanFlowEvents.join('|') === 'notify:info:AI正在分析数据，请稍候...|notify:info:AI分析完成！'
        && meituanFailedResult.status === 'failed'
        && meituanFailedResultHtml === ''
        && meituanFailedEvents.includes('notify:error:backend failed')
        && meituanFailedStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanExceptionResult.status === 'exception'
        && meituanExceptionResultHtml === ''
        && meituanExceptionEvents.includes('notify:error:美团 AI 分析请求失败，请修复后端接口后重试')
        && meituanExceptionLogs[0].includes('美团AI分析请求失败:')
        && meituanExceptionLogs[0].includes('network down')
        && meituanExceptionStates.join('|') === 'analyzing:true|analyzing:false'
        && meituanGuardResult.status === 'missing_selection'
        && meituanGuardEvents[0] === 'notify:error:请先选择要分析的酒店'
        && meituanGuardStates.length === 0,
      detail: 'runMeituanAiAnalysisFlow state samples',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds summary and fallback payloads with explicit failures',
      ok: summaryBody.model_key === 'deepseek_chat'
        && summaryBody.group_summaries[0].report.priority === 'high'
        && summaryBody.group_summaries[0].report.problem_hotels[0].problem === '转化偏低'
        && summaryBody.failed_groups.length === 1
        && summaryContext.selectedHotelCount === 4
        && summaryContext.selectedCount === 4
        && summaryContext.completedHotels === 3
        && summaryContext.failedHotels === 1
        && summaryContext.groupCount === 2
        && summaryContext.successGroups.length === 1
        && fallback.fallback === true
        && fallback.summary.failed_hotel_count === 1
        && fallback.fallback_reason === 'Bearer ****'
        && summarySuccessResult.report.overall_conclusion === '汇总成功'
        && summarySuccessResult.process.steps[0] === '汇总'
        && summaryFallbackResult.report.fallback === true
        && summaryFallbackResult.report.fallback_reason === 'Bearer ****'
        && summaryFallbackResult.process === null,
      detail: 'summary fallback sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static builds captured OTA completion state',
      ok: completion.reportHtml.includes('已完成')
        && completion.reportHtml.includes('曝光充足')
        && completion.history.length === 3
        && completion.history[0].hotel_names === 'A、B、C等'
        && completion.history[0].summary === '已完成'
        && completion.history[1].id === 1,
      detail: 'captured OTA completion state sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis execution runner preserves grouped progress and summary context',
      ok: runnerRequests.join(',') === 'A,B'
        && runnerProgress.currentGroup === 2
        && runnerProgress.completedHotels === 2
        && runnerProgress.failedHotels === 0
        && runnerBatchResults[1].retried === true
        && runnerSummaryContexts[0]?.selectedCount === 2
        && runnerSummaryContexts[0]?.successGroups?.length === 2
        && runnerResult.capturedReport?.overall_conclusion === '汇总完成'
        && runnerResult.process?.steps?.[0] === 'summary'
        && runnerResult.history.length === 2,
      detail: 'captured OTA execution runner sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis execution runner keeps all-failed state explicit and masked',
      ok: failedRunnerProgress.failedHotels === 1
        && failedRunnerResult.capturedReport === null
        && failedRunnerResult.capturedError.includes('全部分析失败')
        && failedRunnerResult.capturedError.includes('masked:')
        && !failedRunnerResult.capturedError.includes('sk-secret12345678'),
      detail: 'captured OTA execution all-failed sample',
    });
    checks.push({
      file: 'public/ai-analysis-static.js',
      label: 'AI analysis static keeps display labels and sensitive error masking',
      ok: aiAnalysisStatic.aiAnalysisStatusText('running') === '分析中'
        && aiAnalysisStatic.aiAnalysisPriorityText('high') === '高优先级'
        && aiAnalysisStatic.normalizeAiAnalysisList([{ 指标: '曝光', 结论: '偏低' }])[0] === '指标: 曝光；结论: 偏低'
        && aiAnalysisStatic.maskAiAnalysisError('api_key=abc123 sk-abcdefghijk').includes('api_key=****')
        && history.hotel_names === 'A、B、C等'
        && history.summary === '已完成',
      detail: 'labels masks history sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ai-analysis-static.js',
    label: 'AI analysis static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/ctrip-static.js'), context, {
    filename: 'public/ctrip-static.js',
  });
  const ctripStatic = context.window.SUXI_CTRIP_STATIC || {};
  const buildCtripBrowserCaptureTargetContext = ctripStatic.buildCtripBrowserCaptureTargetContext;
  const buildCtripBrowserCapturePayload = ctripStatic.buildCtripBrowserCapturePayload;
  const buildCtripBrowserCaptureRequestContext = ctripStatic.buildCtripBrowserCaptureRequestContext;
  const normalizeCtripBrowserCaptureErrorResult = ctripStatic.normalizeCtripBrowserCaptureErrorResult;
  const runCtripBrowserCaptureFlow = ctripStatic.runCtripBrowserCaptureFlow;
  const buildCtripFetchDateRange = ctripStatic.buildCtripFetchDateRange;
  const buildCtripFetchRequestBody = ctripStatic.buildCtripFetchRequestBody;
  const buildCtripFetchRequestContext = ctripStatic.buildCtripFetchRequestContext;
  const selectCtripFetchResponsePayload = ctripStatic.selectCtripFetchResponsePayload;
  const buildCtripFetchMeta = ctripStatic.buildCtripFetchMeta;
  const buildCtripFetchRawFailureResult = ctripStatic.buildCtripFetchRawFailureResult;
  const runCtripFetchDataFlow = ctripStatic.runCtripFetchDataFlow;
  const buildLatestCtripSnapshotModel = ctripStatic.buildLatestCtripSnapshotModel;
  const buildCtripTrafficFetchRequestBody = ctripStatic.buildCtripTrafficFetchRequestBody;
  const buildCtripTrafficResponseModel = ctripStatic.buildCtripTrafficResponseModel;
  const runCtripTrafficFetchFlow = ctripStatic.runCtripTrafficFetchFlow;
  const buildCtripOverviewFetchRequestBody = ctripStatic.buildCtripOverviewFetchRequestBody;
  const runCtripOverviewFetchFlow = ctripStatic.runCtripOverviewFetchFlow;
  const buildCtripAdsFetchRequestBody = ctripStatic.buildCtripAdsFetchRequestBody;
  const runCtripAdsFetchFlow = ctripStatic.runCtripAdsFetchFlow;
  const buildCtripCookieApiFetchRequestBody = ctripStatic.buildCtripCookieApiFetchRequestBody;
  const runCtripCookieApiCaptureFlow = ctripStatic.runCtripCookieApiCaptureFlow;
  const defaultCtripAdsEffectReportUrl = ctripStatic.defaultCtripAdsEffectReportUrl;
  const isCtripAdsApiUrl = ctripStatic.isCtripAdsApiUrl;
  const normalizeCtripAdsApiType = ctripStatic.normalizeCtripAdsApiType;
  const createCtripConfigForm = ctripStatic.createCtripConfigForm;
  const buildCtripConfigSavePayload = ctripStatic.buildCtripConfigSavePayload;
  const validateCtripConfigSaveInput = ctripStatic.validateCtripConfigSaveInput;
  const runCtripConfigSaveFlow = ctripStatic.runCtripConfigSaveFlow;
  const createCtripProfileFieldForm = ctripStatic.createCtripProfileFieldForm;
  const buildCtripProfileFieldSmartDefaults = ctripStatic.buildCtripProfileFieldSmartDefaults;
  const buildCtripProfileFieldSavePayload = ctripStatic.buildCtripProfileFieldSavePayload;
  const buildCtripProfileRecheckInitialState = ctripStatic.buildCtripProfileRecheckInitialState;
  const buildCtripProfileRecheckRunContext = ctripStatic.buildCtripProfileRecheckRunContext;
  const buildCtripProfileRecheckCaptureRefreshState = ctripStatic.buildCtripProfileRecheckCaptureRefreshState;
  const buildCtripProfileRecheckSuccessResult = ctripStatic.buildCtripProfileRecheckSuccessResult;
  const buildCtripProfileRecheckErrorResult = ctripStatic.buildCtripProfileRecheckErrorResult;
  const buildCtripProfileRecheckInterruptedState = ctripStatic.buildCtripProfileRecheckInterruptedState;
  const runCtripProfileRecheckFlow = ctripStatic.runCtripProfileRecheckFlow;
  if (typeof createCtripConfigForm !== 'function'
    || typeof buildCtripConfigSavePayload !== 'function'
    || typeof validateCtripConfigSaveInput !== 'function'
    || typeof runCtripConfigSaveFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports config save builders',
      ok: false,
      detail: 'Ctrip config save builders',
    });
  } else {
    const defaultConfigForm = createCtripConfigForm();
    const overriddenConfigForm = createCtripConfigForm({ hotel_id: '10', name: '携程账号' });
    const missingName = validateCtripConfigSaveInput({ name: '', cookies: 'cookie' });
    const missingCookies = validateCtripConfigSaveInput({ name: '配置', cookies: '' });
    const validConfig = validateCtripConfigSaveInput({ name: '配置', cookies: 'cookie' });
    const savePayload = buildCtripConfigSavePayload({
      id: 9,
      name: '携程账号',
      hotel_id: '10',
      ctrip_hotel_id: 'ctrip-10',
      cookies: 'sid=secret',
      url: 'https://example.test/ctrip',
      node_id: '24588',
      capture_sections: 'default traffic',
      approved_mappings_path: 'approved.json',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip config builders preserve default form, payload and missing states',
      ok: defaultConfigForm.id === null
        && defaultConfigForm.url.includes('getDayReportCompeteHotelReport')
        && defaultConfigForm.node_id === '24588'
        && defaultConfigForm.capture_sections === 'default'
        && overriddenConfigForm.hotel_id === '10'
        && overriddenConfigForm.name === '携程账号'
        && missingName.status === 'missing_name'
        && missingName.message === '请输入配置名称'
        && missingCookies.status === 'missing_cookies'
        && missingCookies.message === '请输入平台授权内容'
        && validConfig.ok === true
        && savePayload.id === 9
        && savePayload.name === '携程账号'
        && savePayload.hotel_id === '10'
        && savePayload.ctrip_hotel_id === 'ctrip-10'
        && savePayload.cookies === 'sid=secret'
        && savePayload.url === 'https://example.test/ctrip'
        && savePayload.node_id === '24588'
        && savePayload.capture_sections === 'default traffic'
        && savePayload.approved_mappings_path === 'approved.json',
      detail: 'Ctrip config save builder sample',
    });
    const saveEvents = [];
    const saveLogs = [];
    let requestedConfigBody = null;
    let resetConfigForm = null;
    const saveResult = await runCtripConfigSaveFlow({
      getForm: () => ({
        id: 10,
        name: '携程保存',
        hotel_id: '20',
        ctrip_hotel_id: 'ctrip-20',
        cookies: 'sid=save',
        url: 'https://example.test/save',
        node_id: '24588',
        capture_sections: 'default',
        approved_mappings_path: '',
      }),
      requestSave: async body => {
        requestedConfigBody = body;
        return { code: 200, data: { id: 10 } };
      },
      notify: (message, level) => saveEvents.push(`notify:${level || 'info'}:${message}`),
      resetForm: form => { resetConfigForm = form; },
      reloadConfigs: () => saveEvents.push('reload'),
      logError: (...args) => saveLogs.push(args.join('|')),
    });
    const failedEvents = [];
    const failedLogs = [];
    const failedResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '携程失败', cookies: 'sid=failed' }),
      requestSave: async () => ({ code: 500, message: 'backend failed' }),
      notify: (message, level) => failedEvents.push(`notify:${level}:${message}`),
      logError: (...args) => failedLogs.push(args.join('|')),
    });
    const exceptionEvents = [];
    const exceptionLogs = [];
    const exceptionResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '携程异常', cookies: 'sid=exception' }),
      requestSave: async () => {
        throw {
          message: 'network down',
          response: {
            json: async () => ({ msg: 'response parsed failed' }),
          },
        };
      },
      notify: (message, level) => exceptionEvents.push(`notify:${level}:${message}`),
      logError: (...args) => exceptionLogs.push(args.join('|')),
    });
    const guardEvents = [];
    const guardResult = await runCtripConfigSaveFlow({
      getForm: () => ({ name: '', cookies: 'sid=guard' }),
      notify: (message, level) => guardEvents.push(`notify:${level}:${message}`),
      requestSave: async () => {
        throw new Error('should not request');
      },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip config save flow preserves success, failed, exception and guard states',
      ok: saveResult.status === 'success'
        && requestedConfigBody.id === 10
        && requestedConfigBody.hotel_id === '20'
        && requestedConfigBody.ctrip_hotel_id === 'ctrip-20'
        && requestedConfigBody.cookies === 'sid=save'
        && resetConfigForm.url.includes('getDayReportCompeteHotelReport')
        && resetConfigForm.cookies === ''
        && saveEvents.join('|') === 'notify:info:配置保存成功|reload'
        && saveLogs.length === 0
        && failedResult.status === 'failed'
        && failedEvents[0] === 'notify:error:backend failed'
        && failedLogs[0].includes('携程配置保存失败:')
        && exceptionResult.status === 'exception'
        && exceptionEvents[0] === 'notify:error:保存失败: response parsed failed'
        && exceptionLogs[0].includes('保存失败:')
        && guardResult.status === 'missing_name'
        && guardEvents[0] === 'notify:error:请输入配置名称',
      detail: 'runCtripConfigSaveFlow state samples',
    });
  }
  if (typeof createCtripProfileFieldForm !== 'function'
    || typeof buildCtripProfileFieldSmartDefaults !== 'function'
    || typeof buildCtripProfileFieldSavePayload !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports Profile field form builders',
      ok: false,
      detail: 'Profile field form builders',
    });
  } else {
    const profileFieldForm = createCtripProfileFieldForm();
    const smartDefaults = buildCtripProfileFieldSmartDefaults({
      page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
      request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowTransforNewV1?hostType=Ebooking',
      json_path: "$.data.metrics[0].visitor_count",
      value_meaning: '访客人数',
    });
    const savePayload = buildCtripProfileFieldSavePayload({
      page_url: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/beneficialdata?microJump=true',
      request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1?hostType=Ebooking',
      json_path: "$.data.rows[0].order_amount",
      value_meaning: '收入金额',
      status: 'pending',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile field builders infer defaults and save payloads',
      ok: profileFieldForm.section === 'business_overview'
        && profileFieldForm.notes === ''
        && profileFieldForm.sample_verification_status === 'unverified'
        && smartDefaults.section === 'traffic_report'
        && smartDefaults.sourceKey === 'visitor_count'
        && smartDefaults.endpoint === 'queryFlowTransforNewV1'
        && smartDefaults.valueType === 'integer'
        && smartDefaults.unit === '人'
        && smartDefaults.storageField === 'ota_ctrip_metric_facts.metric_key=visitor_count'
        && savePayload.section === 'sales_report'
        && savePayload.field_key === 'order_amount'
        && savePayload.field_name === '收入金额'
        && savePayload.source_interface === 'queryOrderTrendV1'
        && savePayload.value_type === 'amount'
        && savePayload.unit === '元'
        && savePayload.storage_field === 'online_daily_data.amount'
        && savePayload.status === 'needs_parser',
      detail: 'Profile field builder sample',
    });
  }
  if (typeof buildCtripBrowserCaptureTargetContext !== 'function'
    || typeof buildCtripBrowserCapturePayload !== 'function'
    || typeof buildCtripBrowserCaptureRequestContext !== 'function'
    || typeof runCtripBrowserCaptureFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture context builders',
      ok: false,
      detail: 'buildCtripBrowserCaptureTargetContext/buildCtripBrowserCapturePayload/buildCtripBrowserCaptureRequestContext/runCtripBrowserCaptureFlow',
    });
  } else {
    const missingTarget = buildCtripBrowserCaptureTargetContext({});
    const selectedTarget = buildCtripBrowserCaptureTargetContext({
      selectedCtripHotelId: '',
      autoFetchHotelId: '58',
      userHotelId: '99',
    });
    const payload = buildCtripBrowserCapturePayload({
      systemHotelId: '10',
      hotelId: '24588',
      hotelName: 'Demo Hotel',
      profileId: 'profile-1',
      cookies: 'sid=secret',
      dataDate: '2026-06-10',
      form: { sections: 'default traffic', approvedMappingsPath: '  approved.json  ' },
      options: { captureSections: 'ads reviews', loginOnly: true, bindDataSource: false },
    });
    const fallbackPayload = buildCtripBrowserCapturePayload({
      form: { sections: '' },
      options: {},
    });
    const requestContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: {
        ota_hotel_id: 'ota-58',
        ctrip_hotel_id: 'ctrip-ignored',
        cookies: 'sid=request-context',
      },
      form: { hotelId: '', sections: 'business_overview', approvedMappingsPath: ' approved.json ' },
      overviewForm: { hotelId: 'overview-58', dataDate: '2026-06-10' },
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-58',
      options: { loginOnly: false, bindDataSource: true },
    });
    const missingProfileContext = buildCtripBrowserCaptureRequestContext({
      systemHotelId: '58',
      activeConfig: { ota_hotel_id: 'ota-58' },
      form: {},
      profileId: '',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture context keeps target and request fields explicit',
      ok: missingTarget.ok === false
        && missingTarget.result.message === '请选择目标酒店'
        && selectedTarget.ok === true
        && selectedTarget.systemHotelId === '58'
        && requestContext.ok === true
        && requestContext.capturePayload.system_hotel_id === '58'
        && requestContext.capturePayload.hotel_id === 'ota-58'
        && requestContext.capturePayload.hotel_name === 'Tiancheng Hotel'
        && requestContext.capturePayload.profile_id === 'profile-58'
        && requestContext.capturePayload.cookies === 'sid=request-context'
        && requestContext.capturePayload.data_date === '2026-06-10'
        && requestContext.capturePayload.sections[0] === 'business_overview'
        && missingProfileContext.ok === false
        && missingProfileContext.result.message.includes('携程登录会话标识')
        && payload.system_hotel_id === '10'
        && payload.hotel_id === '24588'
        && payload.hotel_name === 'Demo Hotel'
        && payload.profile_id === 'profile-1'
        && payload.cookies === 'sid=secret'
        && payload.data_date === '2026-06-10'
        && payload.login_only === true
        && payload.bind_data_source === false
        && payload.approved_mappings_path === 'approved.json'
        && Array.isArray(payload.sections)
        && payload.sections.join(',') === 'ads,reviews',
      detail: 'buildCtripBrowserCapturePayload sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture payload defaults to default section',
      ok: Array.isArray(fallbackPayload.sections) && fallbackPayload.sections.length === 1 && fallbackPayload.sections[0] === 'default',
      detail: 'sections default',
    });
    const captureFlowEvents = [];
    const captureFlowStates = [];
    let flowSelectedHotelId = '';
    let flowCaptureResult = null;
    let flowOnlineResult = null;
    let flowShowRawData = true;
    let flowCookieProfileId = '';
    let flowProfileStatus = null;
    let flowRequestedPayload = null;
    const flowResult = await runCtripBrowserCaptureFlow({
      options: { captureSections: 'sales_report', bindDataSource: true },
      getSelectedCtripHotelId: () => flowSelectedHotelId,
      setSelectedCtripHotelId: value => {
        flowSelectedHotelId = value;
        captureFlowEvents.push(`selected:${value}`);
      },
      getAutoFetchHotelId: () => '58',
      getUserHotelId: () => '99',
      hasCtripConfigList: () => false,
      loadCtripConfigList: async () => {
        captureFlowEvents.push('load-configs');
      },
      getActiveCtripConfig: () => null,
      findCtripConfigByHotelId: systemHotelId => ({
        system_hotel_id: systemHotelId,
        ota_hotel_id: 'ota-58',
        cookies: 'sid=flow',
      }),
      ensureCtripConfigSecret: async config => {
        captureFlowEvents.push('ensure-secret');
        return config;
      },
      applyCtripConfigObject: config => {
        captureFlowEvents.push(`apply:${config.system_hotel_id}`);
      },
      getBrowserCaptureForm: () => ({ sections: 'default', approvedMappingsPath: ' approved.json ' }),
      getOverviewForm: () => ({ hotelId: 'overview-58', dataDate: '2026-06-10' }),
      getHotelNameById: systemHotelId => `Hotel ${systemHotelId}`,
      resolveProfileId: activeConfig => `profile-${activeConfig.system_hotel_id}`,
      requestCapture: async payload => {
        flowRequestedPayload = payload;
        captureFlowEvents.push('request-capture');
        return { code: 200, message: 'capture ok', data: { saved_count: 5, profile_id: 'profile-58' } };
      },
      setRunning: value => captureFlowStates.push(`running:${value}`),
      setFetching: value => captureFlowStates.push(`fetching:${value}`),
      setCaptureResult: value => { flowCaptureResult = value; },
      setOnlineDataResult: value => { flowOnlineResult = value; },
      setShowRawData: value => { flowShowRawData = value; },
      setCookieApiProfileId: value => { flowCookieProfileId = value; },
      setProfileStatus: value => { flowProfileStatus = value; },
      notify: message => captureFlowEvents.push(`notify:${message}`),
      refreshLatestCtripData: async params => captureFlowEvents.push(`latest:${params.silent}`),
      refreshOnlineHistory: async () => captureFlowEvents.push('history'),
      shouldRefreshDataHealthPanel: () => true,
      refreshDataHealthPanel: async (mode, params) => captureFlowEvents.push(`health:${mode}:${params.force}`),
      refreshPlatformProfileStatus: async params => captureFlowEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => captureFlowEvents.push('data-sources'),
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture flow orchestrates request and refresh callbacks',
      ok: flowResult.code === 200
        && flowSelectedHotelId === '58'
        && flowRequestedPayload.system_hotel_id === '58'
        && flowRequestedPayload.hotel_id === 'ota-58'
        && flowRequestedPayload.hotel_name === 'Hotel 58'
        && flowRequestedPayload.profile_id === 'profile-58'
        && flowRequestedPayload.cookies === 'sid=flow'
        && flowRequestedPayload.data_date === '2026-06-10'
        && flowRequestedPayload.sections.join(',') === 'sales_report'
        && flowRequestedPayload.bind_data_source === true
        && flowCaptureResult.saved_count === 5
        && flowOnlineResult.saved_count === 5
        && flowShowRawData === false
        && flowCookieProfileId === ''
        && flowProfileStatus === null
        && captureFlowStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && captureFlowEvents.includes('load-configs')
        && captureFlowEvents.includes('ensure-secret')
        && captureFlowEvents.includes('apply:58')
        && captureFlowEvents.includes('request-capture')
        && captureFlowEvents.includes('latest:true')
        && captureFlowEvents.includes('history')
        && captureFlowEvents.includes('health:light:true')
        && captureFlowEvents.includes('profile-status:true')
        && captureFlowEvents.includes('data-sources'),
      detail: 'runCtripBrowserCaptureFlow success sample',
    });
    const loginFlowEvents = [];
    let loginCookieProfileId = '';
    let loginProfileStatus = null;
    const loginFlowResult = await runCtripBrowserCaptureFlow({
      options: { loginOnly: true, bindDataSource: true, silent: true },
      getSelectedCtripHotelId: () => '58',
      getAutoFetchHotelId: () => '',
      getUserHotelId: () => '',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', ota_hotel_id: 'ota-58' }),
      ensureCtripConfigSecret: async config => config,
      getBrowserCaptureForm: () => ({}),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      getHotelNameById: () => 'Hotel 58',
      resolveProfileId: () => 'profile-local',
      requestCapture: async () => ({ code: 200, message: 'login ok', data: { profile_id: 'profile-api' } }),
      setCookieApiProfileId: value => { loginCookieProfileId = value; },
      setProfileStatus: value => { loginProfileStatus = value; },
      refreshPlatformProfileStatus: async params => loginFlowEvents.push(`profile-status:${params.silent}`),
      refreshPlatformDataSources: async () => loginFlowEvents.push('data-sources'),
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser login flow updates Profile status without data refresh',
      ok: loginFlowResult.code === 200
        && loginCookieProfileId === 'profile-api'
        && loginProfileStatus?.status === 'profile_found'
        && loginFlowEvents.join('|') === 'profile-status:true|data-sources',
      detail: 'runCtripBrowserCaptureFlow login-only sample',
    });
  }
  if (typeof normalizeCtripBrowserCaptureErrorResult !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports browser capture error normalizer',
      ok: false,
      detail: 'normalizeCtripBrowserCaptureErrorResult',
    });
  } else {
    const errorResult = normalizeCtripBrowserCaptureErrorResult({
      message: 'capture failed',
      data: {
        data: {
          stdout: 'out',
          stderr: 'err',
          partial_capture: { available: true, saved_count: 2 },
        },
      },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip browser capture error normalizer preserves partial capture evidence',
      ok: errorResult.available === true
        && errorResult.saved_count === 2
        && errorResult.error === 'capture failed'
        && errorResult.stdout === 'out'
        && errorResult.stderr === 'err'
        && errorResult.partial_capture?.available === true,
      detail: 'partial_capture',
    });
  }
  if (typeof buildCtripFetchDateRange !== 'function'
    || typeof buildCtripFetchRequestBody !== 'function'
    || typeof buildCtripFetchRequestContext !== 'function'
    || typeof selectCtripFetchResponsePayload !== 'function'
    || typeof buildCtripFetchMeta !== 'function'
    || typeof buildCtripFetchRawFailureResult !== 'function'
    || typeof runCtripFetchDataFlow !== 'function'
    || typeof buildLatestCtripSnapshotModel !== 'function'
    || typeof buildCtripTrafficFetchRequestBody !== 'function'
    || typeof runCtripTrafficFetchFlow !== 'function'
    || typeof buildCtripOverviewFetchRequestBody !== 'function'
    || typeof runCtripOverviewFetchFlow !== 'function'
    || typeof buildCtripAdsFetchRequestBody !== 'function'
    || typeof runCtripAdsFetchFlow !== 'function'
    || typeof buildCtripCookieApiFetchRequestBody !== 'function'
    || typeof runCtripCookieApiCaptureFlow !== 'function'
    || typeof isCtripAdsApiUrl !== 'function'
    || typeof normalizeCtripAdsApiType !== 'function'
    || typeof buildCtripTrafficResponseModel !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports fetch request builders',
      ok: false,
      detail: 'Ctrip fetch context, flow, latest snapshot, traffic runner, overview, ads, and Cookie API builders/flow',
    });
  } else {
    const defaultRange = buildCtripFetchDateRange({}, new Date('2026-06-10T12:00:00Z'));
    const explicitRange = buildCtripFetchDateRange({ startDate: '2026-06-01', endDate: '2026-06-10' });
    const fetchBody = buildCtripFetchRequestBody({
      form: { url: ' https://ebooking.ctrip.test/api ', auth_data: { token: 'demo' } },
      cookies: 'sid=abc',
      nodeId: '24588',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      systemHotelId: '58',
    });
    const fallbackBody = buildCtripFetchRequestBody({
      form: { url: '   ' },
      cookies: 'sid=abc',
      startDate: '2026-06-09',
      endDate: '2026-06-09',
    });
    const fetchContext = buildCtripFetchRequestContext({
      form: {
        url: ' https://ebooking.ctrip.test/api ',
        cookies: ' sid=context ',
        nodeId: '24588',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'ctx' },
      },
      selectedCtripHotelId: '58',
    });
    const missingCredentialContext = buildCtripFetchRequestContext({
      form: { cookies: '   ' },
      selectedCtripHotelId: '58',
    });
    const multiDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }, { date: '2026-06-10' }],
      data: [{ ignored: true }],
    });
    const singleDatePayload = selectCtripFetchResponsePayload({
      date_results: [{ date: '2026-06-09' }],
      data: [{ kept: true }],
    });
    const fetchMeta = buildCtripFetchMeta({
      hotelId: '58',
      startDate: '2026-06-01',
      endDate: '2026-06-10',
      fetchedAt: '2026-06-10 14:00:00',
      savedCount: 0,
      displayHotelCount: 7,
    });
    const rawFailure = buildCtripFetchRawFailureResult({
      errorMsg: '授权过期',
      rawResponse: 'x'.repeat(1200),
    });
    const fetchFlowEvents = [];
    const fetchFlowStates = [];
    let fetchFlowRequestedBody = null;
    let fetchFlowResultPayload = null;
    let fetchFlowFilterDates = null;
    let fetchFlowLatestMeta = null;
    let fetchFlowTableTab = '';
    let fetchFlowHistorySettled = false;
    let fetchFlowLatestSettled = false;
    const fetchFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => fetchFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ id: 1, hotel_id: '58', cookies: 'sid=config' }),
      ensureCtripConfigSecret: async config => {
        fetchFlowEvents.push('ensure-config');
        return config;
      },
      applyCtripConfigObject: config => fetchFlowEvents.push(`apply:${config.hotel_id}`),
      getForm: () => ({
        cookies: ' sid=fetch ',
        nodeId: '24588',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        auth_data: { token: 'ctx' },
      }),
      setFetching: value => fetchFlowStates.push(`fetching:${value}`),
      setShowRawData: value => fetchFlowStates.push(`raw:${value}`),
      setFetchSuccess: value => fetchFlowStates.push(`success:${value}`),
      setSavedCount: value => fetchFlowStates.push(`saved:${value}`),
      debugLog: (message) => fetchFlowEvents.push(`debug:${message}`),
      requestFetch: async requestBody => {
        fetchFlowRequestedBody = requestBody;
        fetchFlowEvents.push('request-fetch');
        return {
          code: 200,
          data: {
            data: [{ order_id: 'o1' }],
            display_hotels: [{ hotel_id: 'h1' }],
            display_summary: { status: 'ok' },
            saved_count: 4,
            fetched_at: '2026-06-10 14:00:00',
          },
        };
      },
      setOnlineDataResult: value => { fetchFlowResultPayload = value; },
      useDisplayHotels: rows => {
        fetchFlowEvents.push(`display-hotels:${rows.length}`);
        return rows;
      },
      setOnlineDataFilterDates: value => { fetchFlowFilterDates = value; },
      getLatestMeta: () => fetchFlowLatestMeta,
      setLatestMeta: value => { fetchFlowLatestMeta = value; },
      setTableTab: value => { fetchFlowTableTab = value; },
      updateAiAnalysisHotelList: () => fetchFlowEvents.push('update-ai-hotels'),
      refreshOnlineHistory: () => new Promise(resolve => {
        setTimeout(() => {
          fetchFlowHistorySettled = true;
          fetchFlowEvents.push('history');
          resolve();
        }, 25);
      }),
      refreshLatestCtripData: params => new Promise(resolve => {
        setTimeout(() => {
          fetchFlowLatestSettled = true;
          fetchFlowEvents.push(`latest:${params.silent}`);
          resolve();
        }, 25);
      }),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => fetchFlowEvents.push('refresh-data'),
      handleFetchFailure: async message => fetchFlowEvents.push(`failure:${message}`),
      hasVisibleSnapshot: () => false,
      logError: (message) => fetchFlowEvents.push(`log-error:${message}`),
    });
    const fetchFlowReturnedBeforePostRefresh = !fetchFlowHistorySettled && !fetchFlowLatestSettled;
    let acceptedFetchFlowRequestedBody = null;
    let acceptedFetchFlowResultPayload = null;
    const acceptedFetchFlowEvents = [];
    const acceptedFetchFlowStates = [];
    const acceptedFetchFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => acceptedFetchFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ id: 1, hotel_id: '58', cookies: 'sid=config' }),
      ensureCtripConfigSecret: async config => config,
      applyCtripConfigObject: config => acceptedFetchFlowEvents.push(`apply:${config.hotel_id}`),
      getForm: () => ({
        cookies: ' sid=fetch ',
        nodeId: '24588',
        startDate: '2026-06-10',
        endDate: '2026-06-10',
      }),
      setFetching: value => acceptedFetchFlowStates.push(`fetching:${value}`),
      setShowRawData: value => acceptedFetchFlowStates.push(`raw:${value}`),
      setFetchSuccess: value => acceptedFetchFlowStates.push(`success:${value}`),
      setSavedCount: value => acceptedFetchFlowStates.push(`saved:${value}`),
      requestFetch: async requestBody => {
        acceptedFetchFlowRequestedBody = requestBody;
        return {
          code: 200,
          message: 'queued',
          data: { status: 'running', task_id: 'manual-task-1', saved_count: 0 },
        };
      },
      setOnlineDataResult: value => { acceptedFetchFlowResultPayload = value; },
      refreshOnlineHistory: () => acceptedFetchFlowEvents.push('history'),
      refreshLatestCtripData: params => acceptedFetchFlowEvents.push(`latest:${params.silent}`),
      getOnlineDataTab: () => 'data',
      refreshOnlineData: () => acceptedFetchFlowEvents.push('refresh-data'),
    });
    let failedFlowResultPayload = null;
    let failedFlowShowRawData = false;
    const failedFlowEvents = [];
    const failedFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => true,
      getSelectedCtripHotelId: () => '58',
      notify: (message, level) => failedFlowEvents.push(`notify:${level || 'info'}:${message}`),
      getActiveCtripConfig: () => ({ hotel_id: '58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ cookies: 'sid=fetch', startDate: '2026-06-10', endDate: '2026-06-10' }),
      requestFetch: async () => ({
        code: 500,
        message: '授权过期',
        data: { raw_response: 'raw-body' },
      }),
      setOnlineDataResult: value => { failedFlowResultPayload = value; },
      setShowRawData: value => { failedFlowShowRawData = value; },
      handleFetchFailure: async message => failedFlowEvents.push(`failure:${message}`),
      hasVisibleSnapshot: () => false,
    });
    const guardFlowEvents = [];
    const guardFlowResult = await runCtripFetchDataFlow({
      isLoggedIn: () => false,
      notify: (message, level) => guardFlowEvents.push(`notify:${level}:${message}`),
    });
    const latestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'success', data_date: '2026-06-09' },
      rank: {
        rows: [{ row_id: 'rank-1' }],
        display_hotels: [{ hotelId: 'h1' }],
        display_summary: { cards: [{ key: 'amount' }] },
        total: 3,
        data_date: '2026-06-09',
      },
      traffic: {
        rows: [{ date: '2026-06-09' }],
        display_traffic_rows: [{ date: '2026-06-09', compareType: 'self' }],
        display_traffic_summary: { status: 'ok' },
      },
      review: {
        rows: [{ review_id: 'r1' }],
        total: 2,
      },
    });
    const emptyLatestModel = buildLatestCtripSnapshotModel({
      metadata: { status: 'missing', status_label: '暂无入库快照' },
      rank: { rows: [], display_hotels: [] },
      traffic: { rows: [], display_traffic_rows: [] },
      review: { rows: [] },
    });
    const trafficBody = buildCtripTrafficFetchRequestBody({
      form: {
        platform: 'ctrip',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        url: ' https://ebooking.ctrip.test/traffic ',
        extraParams: '{"scope":"self"}',
      },
      cookies: 'sid=traffic',
      systemHotelId: '58',
    });
    const trafficBodyWithoutUrl = buildCtripTrafficFetchRequestBody({
      form: { platform: 'qunar', dateRange: 'yesterday', url: '   ' },
      cookies: 'sid=traffic',
    });
    const overviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=overview',
      requestUrls: 'https://ebooking.ctrip.test/overview',
      form: {
        payloadJson: '{"page":1}',
        spidertoken: 'spider',
        method: 'POST',
        dataDate: '2026-06-09',
      },
      defaultMethod: 'GET',
    });
    const flowOverviewBody = buildCtripOverviewFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      cookies: 'sid=flow',
      requestUrls: 'https://ebooking.ctrip.test/flow',
      form: {
        payloadJson: '',
        spidertoken: '',
        dataDate: '2026-06-10',
      },
      defaultMethod: 'GET',
    });
    const runOverviewSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        requestUrls: '',
        cookies: '',
        payloadJson: '',
        spidertoken: '',
        hotelId: '',
        method: '',
        dataDate: '2026-06-10',
        ...(overrides.form || {}),
      };
      let overviewResultPayload = null;
      let overviewOnlinePayload = null;
      let overviewShowRawData = true;
      let overviewRequestBody = null;
      const result = await runCtripOverviewFetchFlow({
        getSystemHotelId: () => (overrides.systemHotelId === undefined ? '58' : overrides.systemHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { ota_hotel_id: 'ctrip-hotel-1', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.ota_hotel_id || '']),
        getForm: () => form,
        getCtripCookies: () => (overrides.ctripCookies === undefined ? 'sid=form' : overrides.ctripCookies),
        getFallbackRequestUrls: () => overrides.fallbackRequestUrls || '',
        getHotelNameById: hotelId => `hotel-${hotelId}`,
        setFetching: value => states.push(['fetching', value]),
        setGlobalFetching: value => states.push(['global-fetching', value]),
        setResult: value => { overviewResultPayload = value; },
        setOnlineDataResult: value => { overviewOnlinePayload = value; },
        setShowRawData: value => { overviewShowRawData = value; },
        requestFetch: async requestBody => {
          overviewRequestBody = requestBody;
          events.push(['request-fetch', requestBody]);
          if (overrides.throwRequest) {
            const error = new Error('network failed');
            error.data = { data: { stderr: 'stderr details', error: 'network failed' } };
            throw error;
          }
          return overrides.response || { code: 200, message: '', data: { saved_count: 6, rows: [{ id: 1 }] } };
        },
        refreshLatestCtripData: async options => events.push(['latest', options]),
        refreshOnlineHistory: async () => events.push(['history']),
        defaultMethod: overrides.defaultMethod || 'GET',
        messages: {
          missingRequestUrls: '未配置可用的流量概要直连接口',
          invalidPageUrl: '请填写 Network 中的 JSON 接口 URL，不是携程概况页面地址',
          missingCookies: '请提供携程 Cookie',
          successPrefix: '流量概要直连获取完成',
          failure: '流量概要抓取失败',
          exceptionPrefix: '流量概要获取失败',
        },
      });
      return { result, events, states, form, overviewResultPayload, overviewOnlinePayload, overviewShowRawData, overviewRequestBody };
    };
    const overviewFlowSuccess = await runOverviewSample({
      fallbackRequestUrls: 'https://ebooking.ctrip.test/flow',
    });
    const overviewFlowFailure = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: 'sid=flow' },
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const overviewFlowException = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: 'sid=flow' },
      throwRequest: true,
    });
    const overviewMissingHotel = await runOverviewSample({ systemHotelId: '' });
    const overviewMissingConfig = await runOverviewSample({ activeConfig: null });
    const overviewInvalidUrl = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true' },
    });
    const overviewMissingCookie = await runOverviewSample({
      form: { requestUrls: 'https://ebooking.ctrip.test/flow', cookies: '' },
      ctripCookies: '',
      activeConfig: { ota_hotel_id: 'ctrip-hotel-1', cookies: '' },
    });
    const adsBody = buildCtripAdsFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      url: 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
      cookies: 'sid=ads',
      form: {
        apiType: 'custom_ignored',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
      },
    });
    const runAdsSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        url: 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE',
        cookies: '',
        apiType: 'effect_report',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        ...(overrides.form || {}),
      };
      let adsResultPayload = null;
      let adsOnlinePayload = null;
      let adsShowRawData = true;
      let adsRequestBody = null;
      const result = await runCtripAdsFetchFlow({
        getSystemHotelId: () => (overrides.systemHotelId === undefined ? '58' : overrides.systemHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { ota_hotel_id: 'ctrip-hotel-1', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.ota_hotel_id || '']),
        syncAdsDirectConfig: async showMessage => events.push(['sync-ads', showMessage]),
        getForm: () => form,
        getCtripCookies: () => (overrides.ctripCookies === undefined ? 'sid=form' : overrides.ctripCookies),
        getHotelNameById: hotelId => `hotel-${hotelId}`,
        defaultAdsUrl: defaultCtripAdsEffectReportUrl,
        adsUrlHint: '广告接口 URL 提示',
        setRunning: value => states.push(['running', value]),
        setGlobalFetching: value => states.push(['global-fetching', value]),
        setResult: value => { adsResultPayload = value; },
        setOnlineDataResult: value => { adsOnlinePayload = value; },
        setShowRawData: value => { adsShowRawData = value; },
        requestFetch: async requestBody => {
          adsRequestBody = requestBody;
          events.push(['request-ads', requestBody]);
          if (overrides.throwRequest) {
            const error = new Error('network failed');
            error.data = { data: { error: 'network failed' } };
            throw error;
          }
          return overrides.response || { code: 200, message: '', data: { saved_count: 5, rows: [{ id: 1 }] } };
        },
        refreshLatestCtripData: async options => events.push(['latest', options]),
        refreshOnlineHistory: async () => events.push(['history']),
      });
      return { result, events, states, form, adsResultPayload, adsOnlinePayload, adsShowRawData, adsRequestBody };
    };
    const adsFlowSuccess = await runAdsSample();
    const adsFlowAccepted = await runAdsSample({
      response: {
        code: 200,
        message: 'ads queued',
        data: {
          status: 'running',
          task_id: 'ads-task-1',
          platform: 'ctrip',
          async: true,
          saved_count: 0,
          request_start_date: '2026-06-01',
          request_end_date: '2026-06-10',
        },
      },
    });
    const adsFlowFailure = await runAdsSample({
      response: { code: 500, message: 'upstream failed', data: { saved_count: 0 } },
    });
    const adsFlowException = await runAdsSample({ throwRequest: true });
    const adsMissingHotel = await runAdsSample({ systemHotelId: '' });
    const adsMissingConfig = await runAdsSample({ activeConfig: null });
    const adsPageUrl = await runAdsSample({
      form: { url: 'https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true' },
    });
    const adsInvalidUrl = await runAdsSample({
      form: { url: 'https://ebooking.ctrip.com/not/ads/api' },
    });
    const adsMissingCookie = await runAdsSample({
      form: { cookies: '' },
      ctripCookies: '',
      activeConfig: { ota_hotel_id: 'ctrip-hotel-1', cookies: '' },
    });
    const adsMissingCustomDates = await runAdsSample({
      form: { dateRange: 'custom', startDate: '', endDate: '' },
    });
    const cookieApiBody = buildCtripCookieApiFetchRequestBody({
      systemHotelId: '58',
      hotelId: 'ctrip-hotel-1',
      hotelName: 'Tiancheng Hotel',
      profileId: 'profile-1',
      dataDate: '2026-06-10',
      requestUrl: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHomePageRealTimeData',
      form: { method: 'post', payloadJson: ' {"scope":"core"} ' },
      endpointsJson: '[{"section":"homepage"}]',
      cookies: 'sid=cookie-api',
    });
    const cookieFlowEvents = [];
    const cookieFlowStates = [];
    let cookieSelectedHotelId = '';
    let cookieCaptureResult = null;
    let cookieOnlineResult = null;
    let cookieShowRawData = true;
    let cookieProfileId = '';
    let cookieRequestBody = null;
    const cookieFlowResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => cookieSelectedHotelId,
      setSelectedCtripHotelId: value => {
        cookieSelectedHotelId = value;
        cookieFlowEvents.push(`selected:${value}`);
      },
      getAutoFetchHotelId: () => '58',
      getUserHotelId: () => '99',
      hasCtripConfigList: () => false,
      loadCtripConfigList: async () => cookieFlowEvents.push('load-configs'),
      getActiveCtripConfig: () => null,
      findCtripConfigByHotelId: systemHotelId => ({
        system_hotel_id: systemHotelId,
        ota_hotel_id: `ota-${systemHotelId}`,
        cookies: 'sid=config',
        profile_id: `profile-${systemHotelId}`,
      }),
      ensureCtripConfigSecret: async config => {
        cookieFlowEvents.push('ensure-secret');
        return config;
      },
      applyCtripConfigObject: (config, showMessage) => cookieFlowEvents.push(`apply:${config.system_hotel_id}:${showMessage}`),
      getForm: () => ({
        requestUrl: '',
        endpointsJson: '[{"section":"homepage"}]',
        cookies: ' sid=form ',
        method: 'post',
        payloadJson: ' {"scope":"core"} ',
      }),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      getHotelNameById: systemHotelId => `Hotel ${systemHotelId}`,
      resolveProfileId: (systemHotelId, activeConfig) => activeConfig.profile_id || `profile-${systemHotelId}`,
      resolveRequestHotelId: systemHotelId => `request-${systemHotelId}`,
      requestCapture: async body => {
        cookieRequestBody = body;
        cookieFlowEvents.push('request-cookie-api');
        return { code: 200, message: 'cookie ok', data: { saved_count: 7, is_ready: true } };
      },
      setProfileId: value => { cookieProfileId = value; },
      setRunning: value => cookieFlowStates.push(`running:${value}`),
      setFetching: value => cookieFlowStates.push(`fetching:${value}`),
      setCaptureResult: value => { cookieCaptureResult = value; },
      setOnlineDataResult: value => { cookieOnlineResult = value; },
      setShowRawData: value => { cookieShowRawData = value; },
      notify: (message, level) => cookieFlowEvents.push(`notify:${level || 'info'}:${message}`),
      refreshLatestCtripData: async params => cookieFlowEvents.push(`latest:${params.silent}`),
      refreshOnlineHistory: async () => cookieFlowEvents.push('history'),
      shouldRefreshDataHealthPanel: () => true,
      refreshDataHealthPanel: async (mode, params) => cookieFlowEvents.push(`health:${mode}:${params.force}`),
    });
    const cookieNotReadyEvents = [];
    const cookieNotReadyResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      getOverviewForm: () => ({ dataDate: '2026-06-10' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => ({ code: 200, message: 'not ready', data: { is_ready: false, warning: 'cookie insufficient' } }),
      notify: (message, level) => cookieNotReadyEvents.push(`notify:${level || 'info'}:${message}`),
    });
    let cookieFailureResultPayload = null;
    const cookieFailureEvents = [];
    const cookieFailureResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => ({
        code: 422,
        message: 'identity failed',
        data: { identity_check: { message: 'hotel mismatch' } },
      }),
      setCaptureResult: value => { cookieFailureResultPayload = value; },
      notify: (message, level) => cookieFailureEvents.push(`notify:${level || 'info'}:${message}`),
    });
    let cookieExceptionResultPayload = null;
    const cookieExceptionEvents = [];
    const cookieExceptionResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58', profile_id: 'profile-58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => 'profile-58',
      resolveRequestHotelId: () => 'hotel-58',
      requestCapture: async () => {
        const error = new Error('network failed');
        error.data = { data: { message: 'request blocked' } };
        throw error;
      },
      setCaptureResult: value => { cookieExceptionResultPayload = value; },
      notify: (message, level) => cookieExceptionEvents.push(`notify:${level || 'info'}:${message}`),
    });
    const cookieMissingProfileEvents = [];
    const cookieMissingProfileResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      hasCtripConfigList: () => true,
      getActiveCtripConfig: () => ({ system_hotel_id: '58' }),
      ensureCtripConfigSecret: async config => config,
      getForm: () => ({ requestUrl: 'https://ebooking.ctrip.test/api', endpointsJson: '' }),
      resolveProfileId: () => '',
      notify: (message, level) => cookieMissingProfileEvents.push(`notify:${level}:${message}`),
    });
    const cookieMissingSourceEvents = [];
    const cookieMissingSourceResult = await runCtripCookieApiCaptureFlow({
      getSelectedCtripHotelId: () => '58',
      getForm: () => ({ requestUrl: '   ', endpointsJson: '   ' }),
      notify: (message, level) => cookieMissingSourceEvents.push(`notify:${level}:${message}`),
    });
    const trafficModel = buildCtripTrafficResponseModel({
      http_code: 200,
      saved_count: 4,
      platform: 'ctrip',
      request_start_date: '2026-06-01',
      request_end_date: '2026-06-10',
      decoded_data: [{ decoded: true }],
      traffic_rows: [{ row_id: 'traffic-1' }],
      display_traffic_rows: [{ date: '2026-06-01', compareType: 'self' }],
      display_traffic_summary: { status: 'ok' },
      raw_response: '{"ok":true}',
      derived_analysis: { conversion: 'stable' },
    });
    const trafficFallbackModel = buildCtripTrafficResponseModel({
      data: [{ decoded: 'fallback' }],
    });
    const runTrafficSample = async (overrides = {}) => {
      const events = [];
      const states = [];
      const form = {
        platform: 'ctrip',
        dateRange: 'custom',
        startDate: '2026-06-01',
        endDate: '2026-06-10',
        url: ' https://ebooking.ctrip.test/traffic ',
        cookies: ' sid=traffic-flow ',
        extraParams: '{"scope":"self"}',
        ...(overrides.form || {}),
      };
      let trafficOnlinePayload = null;
      let trafficRequestBody = null;
      let trafficDisplayArgs = null;
      const result = await runCtripTrafficFetchFlow({
        getSelectedCtripHotelId: () => (overrides.selectedHotelId === undefined ? '58' : overrides.selectedHotelId),
        notify: (message, level = 'success') => events.push(['notify', level, message]),
        getActiveCtripConfig: () => (overrides.activeConfig === undefined
          ? { hotel_id: '58', cookies: 'sid=config' }
          : overrides.activeConfig),
        ensureCtripConfigSecret: async config => {
          events.push(['ensure-config', Boolean(config)]);
          return config;
        },
        applyCtripConfigObject: config => events.push(['apply-config', config?.hotel_id || '']),
        getForm: () => form,
        setFetching: value => states.push(['fetching', value]),
        requestFetch: async requestBody => {
          trafficRequestBody = requestBody;
          events.push(['request-traffic', requestBody]);
          if (overrides.throwRequest) {
            throw new Error('network failed');
          }
          return overrides.response || {
            code: 200,
            data: {
              saved_count: 3,
              decoded_data: [{ decoded: true }],
              traffic_rows: [{ row_id: 'traffic-flow-1' }],
              display_traffic_rows: [{ date: '2026-06-01', compareType: 'self' }],
              display_traffic_summary: { status: 'ok' },
              derived_analysis: { conversion: 'stable' },
            },
          };
        },
        useCtripTrafficDisplayRows: (displayRows, displaySummary, trafficRows, derivedAnalysis) => {
          trafficDisplayArgs = { displayRows, displaySummary, trafficRows, derivedAnalysis };
          return overrides.displayRowsReturn === undefined ? displayRows : overrides.displayRowsReturn;
        },
        setOnlineDataResult: value => { trafficOnlinePayload = value; },
        refreshOnlineHistory: async () => events.push(['history']),
        getOnlineDataTab: () => (overrides.onlineDataTab === undefined ? 'data' : overrides.onlineDataTab),
        refreshOnlineData: () => events.push(['refresh-data']),
        handleFetchFailure: async message => events.push(['failure', message]),
      });
      return { result, events, states, form, trafficOnlinePayload, trafficRequestBody, trafficDisplayArgs };
    };
    const trafficFlowSuccess = await runTrafficSample();
    const trafficFlowAccepted = await runTrafficSample({
      response: {
        code: 200,
        message: 'traffic queued',
        data: {
          status: 'running',
          task_id: 'traffic-task-1',
          platform: 'ctrip',
          async: true,
          saved_count: 0,
          request_start_date: '2026-06-01',
          request_end_date: '2026-06-10',
        },
      },
    });
    const trafficFlowEmpty = await runTrafficSample({
      response: { code: 200, data: { saved_count: 0, data: [], display_traffic_rows: [] } },
      displayRowsReturn: [],
    });
    const trafficFlowFailure = await runTrafficSample({
      response: { code: 500, message: 'upstream traffic failed' },
    });
    const trafficFlowException = await runTrafficSample({ throwRequest: true });
    const trafficMissingHotel = await runTrafficSample({ selectedHotelId: '' });
    const trafficMissingConfig = await runTrafficSample({ activeConfig: null });
    const trafficMissingCookie = await runTrafficSample({
      form: { cookies: '   ' },
      activeConfig: { hotel_id: '58', cookies: '' },
    });
    const trafficMissingCustomDates = await runTrafficSample({
      form: { dateRange: 'custom', startDate: '', endDate: '' },
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep request fields and date defaults',
      ok: defaultRange.startDate === '2026-06-09'
        && defaultRange.endDate === '2026-06-09'
        && explicitRange.startDate === '2026-06-01'
        && explicitRange.endDate === '2026-06-10'
        && fetchContext.ok === true
        && fetchContext.requestBody.cookies === 'sid=context'
        && fetchContext.requestBody.node_id === '24588'
        && fetchContext.requestBody.system_hotel_id === '58'
        && fetchContext.requestBody.start_date === '2026-06-01'
        && fetchContext.requestBody.end_date === '2026-06-10'
        && fetchContext.debugMeta.node_id === '24588'
        && missingCredentialContext.ok === false
        && missingCredentialContext.message.includes('平台授权内容')
        && fetchBody.url === 'https://ebooking.ctrip.test/api'
        && fetchBody.node_id === '24588'
        && fetchBody.system_hotel_id === '58'
        && fetchBody.cookies === 'sid=abc'
        && fallbackBody.url === undefined
        && fallbackBody.node_id === undefined
        && fallbackBody.system_hotel_id === null,
      detail: 'Ctrip fetch request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip fetch builders keep response and failure evidence explicit',
      ok: Array.isArray(multiDatePayload.date_results)
        && multiDatePayload.date_results.length === 2
        && Array.isArray(singleDatePayload)
        && singleDatePayload[0].kept === true
        && fetchMeta.data_date === '2026-06-01 至 2026-06-10'
        && fetchMeta.total_records === 7
        && rawFailure.error === '授权过期'
        && rawFailure.raw.length === 1000
        && rawFailure.hint.includes('Cookie是否过期')
        && fetchFlowResult.status === 'success'
        && fetchFlowRequestedBody.async === true
        && fetchFlowRequestedBody.cookies === 'sid=fetch'
        && fetchFlowRequestedBody.node_id === '24588'
        && fetchFlowRequestedBody.system_hotel_id === '58'
        && fetchFlowResultPayload[0].order_id === 'o1'
        && fetchFlowFilterDates.startDate === '2026-06-01'
        && fetchFlowFilterDates.endDate === '2026-06-10'
        && fetchFlowLatestMeta.total_records === 4
        && fetchFlowLatestMeta.data_date === '2026-06-01 至 2026-06-10'
        && fetchFlowTableTab === 'sales'
        && fetchFlowStates.join('|') === 'fetching:true|raw:false|success:false|saved:0|saved:4|success:true|fetching:false'
        && fetchFlowEvents.includes('ensure-config')
        && fetchFlowEvents.includes('apply:58')
        && fetchFlowEvents.includes('request-fetch')
        && fetchFlowEvents.includes('display-hotels:1')
        && fetchFlowReturnedBeforePostRefresh
        && fetchFlowEvents.includes('refresh-data')
        && acceptedFetchFlowResult.status === 'accepted'
        && acceptedFetchFlowRequestedBody.async === true
        && acceptedFetchFlowResultPayload.status === 'running'
        && acceptedFetchFlowResultPayload.task_id === 'manual-task-1'
        && acceptedFetchFlowEvents.includes('notify:info:queued')
        && acceptedFetchFlowEvents.includes('history')
        && acceptedFetchFlowEvents.includes('latest:true')
        && acceptedFetchFlowEvents.includes('refresh-data')
        && acceptedFetchFlowStates.join('|') === 'fetching:true|raw:false|success:false|saved:0|saved:0|success:false|fetching:false'
        && failedFlowResult.status === 'failed'
        && failedFlowResultPayload.error === '授权过期'
        && failedFlowResultPayload.raw === 'raw-body'
        && failedFlowShowRawData === true
        && failedFlowEvents.includes('failure:授权过期')
        && guardFlowResult.status === 'not_logged_in'
        && guardFlowEvents[0] === 'notify:error:请先登录',
      detail: 'Ctrip fetch response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip latest snapshot model keeps payload slices explicit',
      ok: latestModel.metadata.status === 'success'
        && latestModel.hasRank === true
        && latestModel.rankRows.length === 1
        && latestModel.rankDisplayHotels.length === 1
        && latestModel.rankDisplaySummary.cards.length === 1
        && latestModel.rankTotal === 3
        && latestModel.rankDataDate === '2026-06-09'
        && latestModel.hasTraffic === true
        && latestModel.trafficRows.length === 1
        && latestModel.displayTrafficRows.length === 1
        && latestModel.trafficDisplaySummary.status === 'ok'
        && latestModel.hasReview === true
        && latestModel.reviewResult.saved_count === 2
        && latestModel.onlineResult.source === 'latest'
        && emptyLatestModel.metadata.status === 'missing'
        && emptyLatestModel.hasAnySnapshot === false
        && emptyLatestModel.onlineResult === null,
      detail: 'Ctrip latest snapshot sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic builders keep request and display model fields',
      ok: trafficBody.url === 'https://ebooking.ctrip.test/traffic'
        && trafficBody.platform === 'ctrip'
        && trafficBody.date_range === 'custom'
        && trafficBody.start_date === '2026-06-01'
        && trafficBody.end_date === '2026-06-10'
        && trafficBody.cookies === 'sid=traffic'
        && trafficBody.system_hotel_id === '58'
        && trafficBody.extra_params === '{"scope":"self"}'
        && trafficBodyWithoutUrl.url === undefined
        && trafficBodyWithoutUrl.system_hotel_id === null
        && trafficModel.savedCount === 4
        && trafficModel.trafficRows[0].row_id === 'traffic-1'
        && trafficModel.displayTrafficRows[0].compareType === 'self'
        && trafficModel.onlineResult.decoded_data[0].decoded === true
        && trafficModel.onlineResult.raw_response === '{"ok":true}'
        && trafficModel.onlineResult.derived_analysis.conversion === 'stable'
        && trafficFallbackModel.trafficRows[0].decoded === 'fallback'
        && trafficFallbackModel.onlineResult.display_traffic_rows.length === 0,
      detail: 'Ctrip traffic builder sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic fetch flow preserves success, empty, failed and exception states',
      ok: trafficFlowSuccess.result.status === 'success'
        && trafficFlowSuccess.trafficRequestBody.url === 'https://ebooking.ctrip.test/traffic'
        && trafficFlowSuccess.trafficRequestBody.async === true
        && trafficFlowSuccess.trafficRequestBody.cookies === 'sid=traffic-flow'
        && trafficFlowSuccess.trafficRequestBody.system_hotel_id === '58'
        && trafficFlowSuccess.trafficRequestBody.extra_params === '{"scope":"self"}'
        && trafficFlowSuccess.trafficOnlinePayload.saved_count === 3
        && trafficFlowSuccess.trafficOnlinePayload.traffic_rows[0].row_id === 'traffic-flow-1'
        && trafficFlowSuccess.trafficDisplayArgs.displayRows[0].compareType === 'self'
        && trafficFlowSuccess.trafficDisplayArgs.derivedAnalysis.conversion === 'stable'
        && trafficFlowSuccess.states.join('|') === 'fetching,true|fetching,false'
        && trafficFlowSuccess.events.some(event => event[0] === 'history')
        && trafficFlowSuccess.events.some(event => event[0] === 'refresh-data')
        && trafficFlowSuccess.events.some(event => event[0] === 'notify' && event[1] === 'success' && event[2].includes('获取成功，已保存 3 条流量数据'))
        && trafficFlowAccepted.result.status === 'accepted'
        && trafficFlowAccepted.trafficRequestBody.async === true
        && trafficFlowAccepted.trafficOnlinePayload.status === 'running'
        && trafficFlowAccepted.trafficOnlinePayload.task_id === 'traffic-task-1'
        && trafficFlowAccepted.trafficOnlinePayload.saved_count === 0
        && trafficFlowAccepted.trafficDisplayArgs === null
        && trafficFlowAccepted.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2].includes('traffic queued'))
        && trafficFlowAccepted.events.some(event => event[0] === 'history')
        && trafficFlowAccepted.events.some(event => event[0] === 'refresh-data')
        && trafficFlowAccepted.states.join('|') === 'fetching,true|fetching,false'
        && trafficFlowEmpty.result.status === 'empty'
        && trafficFlowEmpty.events.some(event => event[0] === 'notify' && event[1] === 'warning' && event[2].includes('当前日期范围暂无流量数据'))
        && !trafficFlowEmpty.events.some(event => event[0] === 'history')
        && trafficFlowFailure.result.status === 'failed'
        && trafficFlowFailure.events.some(event => event[0] === 'failure' && event[1] === 'upstream traffic failed')
        && trafficFlowFailure.states.some(event => event[0] === 'fetching' && event[1] === false)
        && trafficFlowException.result.status === 'exception'
        && trafficFlowException.events.some(event => event[0] === 'failure' && event[1] === '请求失败: network failed')
        && trafficFlowException.states.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runCtripTrafficFetchFlow state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip traffic fetch flow keeps missing states explicit',
      ok: trafficMissingHotel.result.status === 'missing_hotel'
        && trafficMissingHotel.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择目标酒店'))
        && trafficMissingConfig.result.status === 'missing_config'
        && trafficMissingConfig.events.some(event => event[0] === 'notify' && event[1] === 'warning' && event[2].includes('当前酒店未配置携程数据源'))
        && trafficMissingCookie.result.status === 'missing_cookies'
        && trafficMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie'))
        && trafficMissingCustomDates.result.status === 'missing_custom_dates'
        && trafficMissingCustomDates.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择自定义开始日期和结束日期')),
      detail: 'runCtripTrafficFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview builder keeps request fields and method defaults',
      ok: overviewBody.system_hotel_id === '58'
        && overviewBody.hotel_id === 'ctrip-hotel-1'
        && overviewBody.hotel_name === 'Tiancheng Hotel'
        && overviewBody.cookies === 'sid=overview'
        && overviewBody.request_urls === 'https://ebooking.ctrip.test/overview'
        && overviewBody.payload_json === '{"page":1}'
        && overviewBody.spidertoken === 'spider'
        && overviewBody.method === 'POST'
        && overviewBody.data_date === '2026-06-09'
        && flowOverviewBody.cookies === 'sid=flow'
        && flowOverviewBody.request_urls === 'https://ebooking.ctrip.test/flow'
        && flowOverviewBody.payload_json === ''
        && flowOverviewBody.spidertoken === ''
        && flowOverviewBody.method === 'GET'
        && flowOverviewBody.data_date === '2026-06-10',
      detail: 'Ctrip overview request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow refreshes persisted and UI data on success',
      ok: overviewFlowSuccess.result.status === 'success'
        && overviewFlowSuccess.overviewRequestBody.system_hotel_id === '58'
        && overviewFlowSuccess.overviewRequestBody.hotel_id === 'ctrip-hotel-1'
        && overviewFlowSuccess.overviewRequestBody.hotel_name === 'hotel-58'
        && overviewFlowSuccess.overviewRequestBody.cookies === 'sid=form'
        && overviewFlowSuccess.overviewRequestBody.request_urls === 'https://ebooking.ctrip.test/flow'
        && overviewFlowSuccess.overviewRequestBody.method === 'GET'
        && overviewFlowSuccess.overviewResultPayload?.saved_count === 6
        && overviewFlowSuccess.overviewOnlinePayload?.saved_count === 6
        && overviewFlowSuccess.overviewShowRawData === false
        && overviewFlowSuccess.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && overviewFlowSuccess.events.some(event => event[0] === 'history')
        && overviewFlowSuccess.states.some(event => event[0] === 'fetching' && event[1] === false)
        && overviewFlowSuccess.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow keeps failed response visible',
      ok: overviewFlowFailure.result.status === 'failed'
        && overviewFlowFailure.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && overviewFlowFailure.states.some(event => event[0] === 'fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow failed response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow preserves exception evidence',
      ok: overviewFlowException.result.status === 'exception'
        && overviewFlowException.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('流量概要获取失败: network failed'))
        && overviewFlowException.overviewResultPayload?.stderr === 'stderr details'
        && overviewFlowException.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripOverviewFetchFlow exception sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip overview fetch flow keeps missing states explicit',
      ok: overviewMissingHotel.result.status === 'missing_hotel'
        && overviewMissingConfig.result.status === 'missing_config'
        && overviewInvalidUrl.result.status === 'invalid_page_url'
        && overviewMissingCookie.result.status === 'missing_cookies'
        && overviewInvalidUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('不是携程概况页面地址'))
        && overviewMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie')),
      detail: 'runCtripOverviewFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads builders keep request fields and URL guard',
      ok: defaultCtripAdsEffectReportUrl.includes('queryCampaignReportList')
        && isCtripAdsApiUrl(defaultCtripAdsEffectReportUrl) === true
        && isCtripAdsApiUrl('https://ebooking.ctrip.com/toolcenter/cpc/pyramid?microJump=true') === false
        && normalizeCtripAdsApiType('anything') === 'effect_report'
        && adsBody.system_hotel_id === '58'
        && adsBody.hotel_id === 'ctrip-hotel-1'
        && adsBody.hotel_name === 'Tiancheng Hotel'
        && adsBody.url.includes('queryCampaignReportList')
        && adsBody.cookies === 'sid=ads'
        && adsBody.api_type === 'effect_report'
        && adsBody.date_range === 'custom'
        && adsBody.start_date === '2026-06-01'
        && adsBody.end_date === '2026-06-10'
        && adsBody.auto_save === true,
      detail: 'Ctrip ads request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow refreshes persisted and UI data on success',
      ok: adsFlowSuccess.result.status === 'success'
        && adsFlowSuccess.adsRequestBody.system_hotel_id === '58'
        && adsFlowSuccess.adsRequestBody.async === true
        && adsFlowSuccess.adsRequestBody.hotel_id === 'ctrip-hotel-1'
        && adsFlowSuccess.adsRequestBody.hotel_name === 'hotel-58'
        && adsFlowSuccess.adsRequestBody.url.includes('queryCampaignReportList')
        && adsFlowSuccess.adsRequestBody.cookies === 'sid=form'
        && adsFlowSuccess.adsRequestBody.api_type === 'effect_report'
        && adsFlowSuccess.adsRequestBody.date_range === 'custom'
        && adsFlowSuccess.adsRequestBody.start_date === '2026-06-01'
        && adsFlowSuccess.adsRequestBody.end_date === '2026-06-10'
        && adsFlowSuccess.adsResultPayload?.saved_count === 5
        && adsFlowSuccess.adsOnlinePayload?.saved_count === 5
        && adsFlowSuccess.adsShowRawData === false
        && adsFlowSuccess.events.some(event => event[0] === 'sync-ads' && event[1] === false)
        && adsFlowSuccess.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && adsFlowSuccess.events.some(event => event[0] === 'history')
        && adsFlowSuccess.states.some(event => event[0] === 'running' && event[1] === false)
        && adsFlowSuccess.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow treats background accepted state as explicit running task',
      ok: adsFlowAccepted.result.status === 'accepted'
        && adsFlowAccepted.adsRequestBody.async === true
        && adsFlowAccepted.adsResultPayload?.status === 'running'
        && adsFlowAccepted.adsResultPayload?.task_id === 'ads-task-1'
        && adsFlowAccepted.adsOnlinePayload?.status === 'running'
        && adsFlowAccepted.adsOnlinePayload?.saved_count === 0
        && adsFlowAccepted.adsShowRawData === false
        && adsFlowAccepted.events.some(event => event[0] === 'notify' && event[1] === 'info' && event[2].includes('ads queued'))
        && adsFlowAccepted.events.some(event => event[0] === 'latest' && event[1]?.silent === true)
        && adsFlowAccepted.events.some(event => event[0] === 'history')
        && adsFlowAccepted.states.some(event => event[0] === 'running' && event[1] === false)
        && adsFlowAccepted.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow accepted sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow keeps failed response visible',
      ok: adsFlowFailure.result.status === 'failed'
        && adsFlowFailure.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('upstream failed'))
        && adsFlowFailure.states.some(event => event[0] === 'running' && event[1] === false),
      detail: 'runCtripAdsFetchFlow failed response sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow preserves exception evidence',
      ok: adsFlowException.result.status === 'exception'
        && adsFlowException.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('广告数据获取失败: network failed'))
        && adsFlowException.adsResultPayload?.error === 'network failed'
        && adsFlowException.states.some(event => event[0] === 'global-fetching' && event[1] === false),
      detail: 'runCtripAdsFetchFlow exception sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip ads fetch flow keeps missing states explicit',
      ok: adsMissingHotel.result.status === 'missing_hotel'
        && adsMissingConfig.result.status === 'missing_config'
        && adsPageUrl.result.status === 'invalid_page_url'
        && adsInvalidUrl.result.status === 'invalid_api_url'
        && adsMissingCookie.result.status === 'missing_cookies'
        && adsMissingCustomDates.result.status === 'missing_custom_dates'
        && adsPageUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('不是广告页面地址'))
        && adsInvalidUrl.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('广告接口 URL 提示'))
        && adsMissingCookie.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请提供携程 Cookie'))
        && adsMissingCustomDates.events.some(event => event[0] === 'notify' && event[1] === 'error' && event[2].includes('请选择自定义开始日期和结束日期')),
      detail: 'runCtripAdsFetchFlow missing-state samples',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API builder keeps request fields and normalized payload',
      ok: cookieApiBody.system_hotel_id === '58'
        && cookieApiBody.hotel_id === 'ctrip-hotel-1'
        && cookieApiBody.hotel_name === 'Tiancheng Hotel'
        && cookieApiBody.profile_id === 'profile-1'
        && cookieApiBody.data_date === '2026-06-10'
        && cookieApiBody.request_url.includes('queryHomePageRealTimeData')
        && cookieApiBody.method === 'POST'
        && cookieApiBody.payload_json === '{"scope":"core"}'
        && cookieApiBody.endpoints_json === '[{"section":"homepage"}]'
        && cookieApiBody.cookies === 'sid=cookie-api'
        && cookieApiBody.auto_save === true,
      detail: 'Ctrip Cookie API request sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API flow preserves request, state, and refresh callbacks',
      ok: cookieFlowResult.status === 'success'
        && cookieSelectedHotelId === '58'
        && cookieProfileId === 'profile-58'
        && cookieRequestBody.system_hotel_id === '58'
        && cookieRequestBody.hotel_id === 'request-58'
        && cookieRequestBody.hotel_name === 'Hotel 58'
        && cookieRequestBody.profile_id === 'profile-58'
        && cookieRequestBody.data_date === '2026-06-10'
        && cookieRequestBody.request_url === ''
        && cookieRequestBody.method === 'POST'
        && cookieRequestBody.payload_json === '{"scope":"core"}'
        && cookieRequestBody.endpoints_json === '[{"section":"homepage"}]'
        && cookieRequestBody.cookies === 'sid=form'
        && cookieCaptureResult.saved_count === 7
        && cookieOnlineResult.saved_count === 7
        && cookieShowRawData === false
        && cookieFlowStates.join('|') === 'running:true|fetching:true|running:false|fetching:false'
        && cookieFlowEvents.includes('selected:58')
        && cookieFlowEvents.includes('load-configs')
        && cookieFlowEvents.includes('ensure-secret')
        && cookieFlowEvents.includes('apply:58:false')
        && cookieFlowEvents.includes('request-cookie-api')
        && cookieFlowEvents.includes('latest:true')
        && cookieFlowEvents.includes('history')
        && cookieFlowEvents.includes('health:light:true'),
      detail: 'Ctrip Cookie API flow success sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Cookie API flow keeps not-ready, failure, exception, and missing states explicit',
      ok: cookieNotReadyResult.status === 'success'
        && cookieNotReadyEvents[0] === 'notify:warning:cookie insufficient'
        && cookieFailureResult.status === 'error_response'
        && cookieFailureResultPayload.identity_check.message === 'hotel mismatch'
        && cookieFailureEvents[0] === 'notify:error:hotel mismatch'
        && cookieExceptionResult.status === 'exception'
        && cookieExceptionResultPayload.message === 'request blocked'
        && cookieExceptionEvents[0] === 'notify:error:request blocked'
        && cookieMissingProfileResult.status === 'missing_profile'
        && cookieMissingProfileEvents[0].includes('携程登录会话标识')
        && cookieMissingSourceResult.status === 'missing_request_source'
        && cookieMissingSourceEvents[0].includes('Request URL'),
      detail: 'Ctrip Cookie API flow failure samples',
    });
  }
  if (typeof buildCtripProfileRecheckInitialState !== 'function'
    || typeof buildCtripProfileRecheckRunContext !== 'function'
    || typeof buildCtripProfileRecheckCaptureRefreshState !== 'function'
    || typeof buildCtripProfileRecheckSuccessResult !== 'function'
    || typeof buildCtripProfileRecheckErrorResult !== 'function'
    || typeof buildCtripProfileRecheckInterruptedState !== 'function'
    || typeof runCtripProfileRecheckFlow !== 'function') {
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip static exports Profile recheck state builders',
      ok: false,
      detail: 'Profile recheck state builders',
    });
  } else {
    const initialState = buildCtripProfileRecheckInitialState({
      canRecapture: true,
      targetCount: 3,
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:00:00',
      sections: ['business_overview'],
    });
    const runContext = buildCtripProfileRecheckRunContext({
      targets: [
        { section: 'business_overview' },
        { section: 'business_overview' },
        { section: 'traffic_report' },
      ],
      estimatedText: '预计 2 分钟',
      startedAt: '2026-06-10 14:01:00',
      selectedCtripHotelId: 'hotel_001',
    });
    const defaultRunContext = buildCtripProfileRecheckRunContext({
      targets: [{ section: '' }],
      estimatedText: '预计 1 分钟',
      startedAt: '2026-06-10 14:02:00',
    });
    const refreshState = buildCtripProfileRecheckCaptureRefreshState({
      previousState: initialState,
      captureSucceeded: false,
      captureMessage: '',
    });
    const successResult = buildCtripProfileRecheckSuccessResult({
      previousState: refreshState,
      captureSucceeded: false,
      captureSkipped: true,
      result: { refreshed_count: 2, unresolved_count: 1 },
      durationText: '12秒',
      finishedAt: '2026-06-10 14:00:12',
    });
    const errorResult = buildCtripProfileRecheckErrorResult({
      previousState: initialState,
      message: '接口失败',
      durationText: '8秒',
      finishedAt: '2026-06-10 14:00:08',
      prefix: '不符字段重跑失败: ',
    });
    const interruptedState = buildCtripProfileRecheckInterruptedState({
      previousState: initialState,
      finishedAt: '2026-06-10 14:00:20',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep capture and refresh states explicit',
      ok: initialState.stage === 'capture'
        && initialState.target_count === 3
        && initialState.sections.includes('business_overview')
        && runContext.canRecapture === true
        && runContext.targetCount === 3
        && runContext.sections.length === 2
        && runContext.requestOptions.method === 'POST'
        && JSON.parse(runContext.requestOptions.body).sections.join(',') === 'business_overview,traffic_report'
        && runContext.initialState.stage === 'capture'
        && runContext.startMessage.includes('开始重抓 3 个')
        && defaultRunContext.canRecapture === false
        && defaultRunContext.sections[0] === 'default'
        && defaultRunContext.initialState.stage === 'refresh_samples'
        && refreshState.type === 'warning'
        && refreshState.stage === 'refresh_samples'
        && refreshState.message.includes('后端未返回成功状态')
        && successResult.state.stage === 'partial'
        && successResult.toastType === 'warning'
        && successResult.message.includes('仅刷新历史获取值')
        && successResult.message.includes('待补解析 1 个'),
      detail: 'Profile recheck state sample',
    });
    const flowStates = [];
    const flowToasts = [];
    const flowEvents = [];
    const flowResponses = [];
    const flowResult = await runCtripProfileRecheckFlow({
      recheckRun: runContext,
      requestSeq: 7,
      getCurrentRequestSeq: () => 7,
      getCurrentState: () => flowStates[flowStates.length - 1] || {},
      setState: state => flowStates.push(state),
      notify: (message, type) => flowToasts.push({ message, type }),
      runBrowserCapture: async options => {
        flowEvents.push({ type: 'capture', options });
        return { code: 200, message: 'capture ok' };
      },
      requestRecheck: async options => {
        flowEvents.push({ type: 'request', options });
        return {
          code: 200,
          data: {
            recheck_result: {
              second_confirmation_count: 4,
              unresolved_count: 1,
            },
            fields: [{ id: 1 }],
          },
        };
      },
      applyResponse: data => flowResponses.push(data),
      getDurationText: () => '5s',
      getFinishedAt: () => '2026-06-10 14:03:00',
      shouldFinalize: () => true,
      onStop: () => flowEvents.push({ type: 'stop' }),
    });
    const flowCaptureEvent = flowEvents.find(event => event.type === 'capture') || {};
    const flowRequestEvent = flowEvents.find(event => event.type === 'request') || {};
    const flowRequestBody = JSON.parse(flowRequestEvent.options?.body || '{}');
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck flow runs capture, request, response, and stop callbacks',
      ok: flowResult.status === 'success'
        && flowStates[0]?.stage === 'capture'
        && flowStates.some(state => state.stage === 'refresh_samples')
        && flowStates[flowStates.length - 1]?.stage === 'done'
        && flowStates[flowStates.length - 1]?.type === 'success'
        && flowToasts[0]?.type === 'info'
        && flowToasts[flowToasts.length - 1]?.type === 'success'
        && flowCaptureEvent.options?.bindDataSource === true
        && flowCaptureEvent.options?.silent === true
        && flowRequestBody.sections?.join(',') === 'business_overview,traffic_report'
        && flowEvents[flowEvents.length - 1]?.type === 'stop'
        && flowResponses[0]?.recheck_result?.second_confirmation_count === 4,
      detail: 'Profile recheck flow sample',
    });
    checks.push({
      file: 'public/ctrip-static.js',
      label: 'Ctrip Profile recheck builders keep error and interruption states visible',
      ok: errorResult.state.type === 'error'
        && errorResult.message === '不符字段重跑失败: 接口失败（耗时 8秒）'
        && interruptedState.type === 'warning'
        && interruptedState.stage === 'partial'
        && interruptedState.message.includes('字段列表在执行中被刷新'),
      detail: 'Profile recheck error sample',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/ctrip-static.js',
    label: 'Ctrip static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/system-static.js'), context, {
    filename: 'public/system-static.js',
  });
  const getDefaultDataConfigForm = context.window.SUXI_SYSTEM_STATIC?.getDefaultDataConfigForm;
  const getDataConfigTypeDefaults = context.window.SUXI_SYSTEM_STATIC?.getDataConfigTypeDefaults;
  const getSystemConfigDefaults = context.window.SUXI_SYSTEM_STATIC?.getSystemConfigDefaults;
  const createLoginForm = context.window.SUXI_SYSTEM_STATIC?.createLoginForm;
  const getRememberedLoginAccount = context.window.SUXI_SYSTEM_STATIC?.getRememberedLoginAccount;
  const buildLoginRequestPayload = context.window.SUXI_SYSTEM_STATIC?.buildLoginRequestPayload;
  const validateLoginRequestPayload = context.window.SUXI_SYSTEM_STATIC?.validateLoginRequestPayload;
  const applyRememberedLoginAccount = context.window.SUXI_SYSTEM_STATIC?.applyRememberedLoginAccount;
  const createRegisterForm = context.window.SUXI_SYSTEM_STATIC?.createRegisterForm;
  const buildRegisterRequestPayload = context.window.SUXI_SYSTEM_STATIC?.buildRegisterRequestPayload;
  const validateRegisterRequestPayload = context.window.SUXI_SYSTEM_STATIC?.validateRegisterRequestPayload;
  const createHotelForm = context.window.SUXI_SYSTEM_STATIC?.createHotelForm;
  const buildHotelSavePayload = context.window.SUXI_SYSTEM_STATIC?.buildHotelSavePayload;
  if (typeof getDefaultDataConfigForm !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports data config default form builder',
      ok: false,
      detail: 'getDefaultDataConfigForm',
    });
  } else {
    const first = getDefaultDataConfigForm();
    const second = getDefaultDataConfigForm();
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form keeps OTA config defaults',
      ok: first.platform === 'Ctrip'
        && first.rank_type === 'P_RZ'
        && Array.isArray(first.rank_types)
        && first.rank_types.includes('P_ZH')
        && first.api_type === 'effect_report'
        && first.reply_type === '2',
      detail: 'getDefaultDataConfigForm sample',
    });
    first.rank_types.push('mutated');
    checks.push({
      file: 'public/system-static.js',
      label: 'data config default form returns fresh mutable arrays',
      ok: Array.isArray(second.rank_types) && !second.rank_types.includes('mutated'),
      detail: 'rank_types',
    });
  }
  if (typeof getDataConfigTypeDefaults !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports data config type defaults',
      ok: false,
      detail: 'getDataConfigTypeDefaults',
    });
  } else {
    const ctripDefaults = getDataConfigTypeDefaults('ctrip-ebooking');
    const meituanDefaults = getDataConfigTypeDefaults('meituan-ebooking');
    const bookingDefaults = getDataConfigTypeDefaults('booking-ota');
    checks.push({
      file: 'public/system-static.js',
      label: 'data config type defaults keep OTA source presets',
      ok: ctripDefaults.node_id === '24588'
        && ctripDefaults.nodeId === '24588'
        && String(ctripDefaults.url || '').includes('ebooking.ctrip.com')
        && meituanDefaults.rank_type === 'P_RZ'
        && meituanDefaults.rankType === 'P_RZ'
        && meituanDefaults.data_scope === 'vpoi'
        && bookingDefaults.platform === 'booking'
        && String(bookingDefaults.extra_params || '').includes('Booking.com房费收入'),
      detail: 'getDataConfigTypeDefaults samples',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'unknown data config type returns empty defaults',
      ok: Object.keys(getDataConfigTypeDefaults('unknown-platform')).length === 0,
      detail: 'unknown-platform',
    });
  }
  if (typeof getSystemConfigDefaults !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports system config defaults',
      ok: false,
      detail: 'getSystemConfigDefaults',
    });
  } else {
    const first = getSystemConfigDefaults();
    const second = getSystemConfigDefaults();
    checks.push({
      file: 'public/system-static.js',
      label: 'system config defaults preserve product and security defaults',
      ok: first.system_name === '宿析OS'
        && first.system_description.includes('授权OTA数据')
        && first.menu_online_data_name === '竞对价格监控'
        && first.complaint_mini_page === 'pages/complaint/index'
        && first.complaint_mini_use_scene === '1'
        && first.login_max_attempts === '5'
        && first.notify_email_port === '587',
      detail: 'getSystemConfigDefaults sample',
    });
    first.system_name = 'mutated';
    checks.push({
      file: 'public/system-static.js',
      label: 'system config defaults return fresh objects',
      ok: second.system_name === '宿析OS',
      detail: 'system_name',
    });
  }
  if (typeof createLoginForm !== 'function'
    || typeof getRememberedLoginAccount !== 'function'
    || typeof buildLoginRequestPayload !== 'function'
    || typeof validateLoginRequestPayload !== 'function'
    || typeof applyRememberedLoginAccount !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports login form helpers',
      ok: false,
      detail: 'createLoginForm/getRememberedLoginAccount/buildLoginRequestPayload/validateLoginRequestPayload/applyRememberedLoginAccount',
    });
  } else {
    const storageMap = new Map([
      ['remembered_username', 'manager01'],
      ['remembered_password', 'legacy-secret'],
    ]);
    const storage = {
      getItem: key => storageMap.get(key) || '',
      setItem: (key, value) => storageMap.set(key, String(value)),
      removeItem: key => storageMap.delete(key),
    };
    const remembered = getRememberedLoginAccount(storage);
    const loginPayload = buildLoginRequestPayload({ username: ' manager01 ', password: 'secret123' });
    applyRememberedLoginAccount({ storage, username: loginPayload.username, remember: true });
    const rememberedPasswordAfterSave = storageMap.has('remembered_password');
    applyRememberedLoginAccount({ storage, username: loginPayload.username, remember: false });
    checks.push({
      file: 'public/system-static.js',
      label: 'system static login helpers preserve account-only storage and explicit validation',
      ok: remembered.username === 'manager01'
        && remembered.remember === true
        && remembered.form.username === 'manager01'
        && remembered.form.password === ''
        && !storageMap.has('remembered_password')
        && createLoginForm({ username: 'u1' }).password === ''
        && loginPayload.username === ' manager01 '
        && loginPayload.password === 'secret123'
        && validateLoginRequestPayload(loginPayload) === ''
        && validateLoginRequestPayload({ username: '', password: 'secret123' }).includes('用户名')
        && rememberedPasswordAfterSave === false
        && !storageMap.has('remembered_username'),
      detail: 'login helper samples and remembered_password cleanup',
    });
  }
  if (typeof createRegisterForm !== 'function' || typeof buildRegisterRequestPayload !== 'function' || typeof validateRegisterRequestPayload !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports register form helpers',
      ok: false,
      detail: 'createRegisterForm/buildRegisterRequestPayload/validateRegisterRequestPayload',
    });
  } else {
    const first = createRegisterForm();
    const second = createRegisterForm();
    first.username = 'mutated';
    const payload = buildRegisterRequestPayload({
      username: ' test_user ',
      realname: ' 店长 ',
      password: 'secret123',
      confirm_password: 'secret123',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'system static register helpers preserve defaults, normalization, and explicit validation',
      ok: first.username === 'mutated'
        && second.username === ''
        && payload.username === 'test_user'
        && payload.realname === '店长'
        && payload.password === 'secret123'
        && payload.confirm_password === 'secret123'
        && validateRegisterRequestPayload(payload) === ''
        && validateRegisterRequestPayload({ ...payload, confirm_password: 'other' }).includes('不一致')
        && validateRegisterRequestPayload({ ...payload, username: '' }).includes('用户名'),
      detail: 'createRegisterForm/buildRegisterRequestPayload/validateRegisterRequestPayload samples',
    });
  }
  if (typeof createHotelForm !== 'function' || typeof buildHotelSavePayload !== 'function') {
    checks.push({
      file: 'public/system-static.js',
      label: 'system static exports hotel admin form helpers',
      ok: false,
      detail: 'createHotelForm/buildHotelSavePayload',
    });
  } else {
    const created = createHotelForm({ operatorName: '店长A', code: 'H009' });
    const edited = createHotelForm({
      hotel: { id: 7, name: '门店七', code: 'H007', address: ' 西湖 ', contact_person: '', contact_phone: '138', status: 0 },
      operatorName: '管理员',
      parsedDescription: { description: '旧描述' },
    });
    const payload = buildHotelSavePayload({
      form: { name: ' 门店七 ', address: ' 地址 ', contact_person: '', contact_phone: ' 139 ', status: '1' },
      normalizedCode: 'H007',
      operatorName: '管理员',
      description: '经营画像',
    });
    checks.push({
      file: 'public/system-static.js',
      label: 'hotel admin form helpers preserve defaults and payload normalization',
      ok: created.id === null
        && created.code === 'H009'
        && created.contact_person === '店长A'
        && edited.id === 7
        && edited.name === '门店七'
        && edited.contact_person === '管理员'
        && edited.status === 0
        && edited.description === '旧描述'
        && payload.name === '门店七'
        && payload.address === '地址'
        && payload.contact_person === '管理员'
        && payload.contact_phone === '139'
        && payload.status === 1
        && payload.description === '经营画像',
      detail: 'createHotelForm/buildHotelSavePayload samples',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/system-static.js',
    label: 'system static runtime validation',
    ok: false,
    detail: error.message,
  });
}

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/notification-static.js'), context, {
    filename: 'public/notification-static.js',
  });
  const buildGlobalNotifications = context.window.SUXI_NOTIFICATION_STATIC?.buildGlobalNotifications;
  if (typeof buildGlobalNotifications !== 'function') {
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification static exports global notification builder',
      ok: false,
      detail: 'buildGlobalNotifications',
    });
  } else {
    const rows = buildGlobalNotifications({
      backendItems: [{ id: 'backend-1', backend_id: 1, source: 'backend', is_read: false }],
      autoFetchRunState: { active: true, message: 'token=abc123 13800138000' },
      autoFetchRunElapsedLabel: '10秒',
      autoFetchStatus: {
        last_run_time: '2026-06-10 10:00:00',
        last_result: { success: true, saved_count: 3 },
      },
      autoFetchRecentRuns: [
        { success: false, run_at: '2026-06-09 08:00:00', data_date: '2026-06-09', message: 'cookie=expired' },
      ],
      dataHealthTodayWorkOrders: [
        { priority: 'high', action_type: 'cookie', key: 'auth', title: '授权过期', detail: 'spidertoken=secret', source_label: '携程', platform_label: 'Ctrip' },
      ],
      readIds: ['auto-fetch-running'],
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps active auto-fetch notification readable',
      ok: rows.some(row => row.id === 'auto-fetch-running' && row.is_read === true && /token=\*\*\*\*/.test(row.detail) && row.detail.includes('138****8000')),
      detail: 'auto-fetch-running',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder keeps data-health action target',
      ok: rows.some(row => row.category === 'cookie_alert' && row.severity === 'error' && row.target_page === 'online-data' && row.target_tab === 'data-health'),
      detail: 'cookie_alert',
    });
    checks.push({
      file: 'public/notification-static.js',
      label: 'notification builder deduplicates rows',
      ok: rows.length === new Set(rows.map(row => row.id)).size,
      detail: 'unique ids',
    });
  }
} catch (error) {
  checks.push({
    file: 'public/notification-static.js',
    label: 'notification static runtime validation',
    ok: false,
    detail: error.message,
  });
}

const failures = checks.filter((check) => !check.ok);
if (failures.length) {
  console.error('E2E contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`E2E contract verification passed (${checks.length} checks).`);
