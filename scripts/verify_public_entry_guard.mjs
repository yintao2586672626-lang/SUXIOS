import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const indexPath = path.join(repoRoot, 'public/index.html');
const publicRouterPath = path.join(repoRoot, 'public/router.php');
const stylePath = path.join(repoRoot, 'public/style.css');
const loginBgPngPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.png');
const loginBgWebpPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.webp');
const loginBgAvifPath = path.join(repoRoot, 'public/images/login-hotel-lobby-bg.avif');
const failures = [];

const lineNumberForOffset = (content, offset) => content.slice(0, offset).split(/\r?\n/).length;

const openTagStackBefore = (content, endOffset) => {
  const voidTags = new Set([
    'area',
    'base',
    'br',
    'col',
    'embed',
    'hr',
    'img',
    'input',
    'link',
    'meta',
    'param',
    'source',
    'track',
    'wbr',
  ]);
  const stack = [];
  const tagPattern = /<!--[\s\S]*?-->|<![^>]*>|<\/?([a-zA-Z][\w:-]*)([^>]*)>/g;
  let match;

  while ((match = tagPattern.exec(content)) && match.index < endOffset) {
    const raw = match[0];
    if (raw.startsWith('<!--') || raw.startsWith('<!')) continue;

    const tag = match[1].toLowerCase();
    if (raw.startsWith('</')) {
      let matchingIndex = -1;
      for (let i = stack.length - 1; i >= 0; i -= 1) {
        if (stack[i].tag === tag) {
          matchingIndex = i;
          break;
        }
      }
      if (matchingIndex >= 0) stack.splice(matchingIndex);
      continue;
    }

    if (!voidTags.has(tag) && !/\/\s*>$/.test(raw)) {
      stack.push({ tag, raw });
    }
  }

  return stack;
};

const hasOpenVueRoot = (stack) => stack.some((entry) => entry.tag === 'div' && /\bid\s*=\s*["']app["']/.test(entry.raw));

const coreFetchFlowFiles = [
  'public/auto-fetch-static.js',
  'public/ctrip-static.js',
  'public/meituan-static.js',
];

if (!fs.existsSync(indexPath)) {
  failures.push('public/index.html is missing.');
} else {
  const stat = fs.statSync(indexPath);
  const content = fs.readFileSync(indexPath, 'utf8');

  if (stat.size < 500_000) {
    failures.push(`public/index.html is too small (${stat.size} bytes). It may have been overwritten by a frontend build.`);
  }

  const requiredMarkers = [
    { name: 'Vue mount root', pattern: /id=["']app["']/ },
    { name: 'local Vue runtime', pattern: /vue\.global\.prod\.js/ },
    { name: 'local Tailwind stylesheet', pattern: /tailwind\.min\.css/ },
    { name: 'application stylesheet', pattern: /style\.css/ },
    { name: 'Vue app bootstrap', pattern: /createApp|Vue\.createApp/ },
  ];

  for (const marker of requiredMarkers) {
    if (!marker.pattern.test(content)) {
      failures.push(`public/index.html missing marker: ${marker.name}`);
    }
  }

  if (/\/assets\/index-[A-Za-z0-9_-]+\.(?:js|css)/.test(content)) {
    failures.push('public/index.html references Vite hashed assets; do not build Vite into HOTEL/public.');
  }

  const tailwindOffset = content.indexOf('href="tailwind.min.css"');
  const vueScriptOffset = content.indexOf('src="vue.global.prod.js"');
  if (tailwindOffset < 0 || vueScriptOffset < 0 || tailwindOffset > vueScriptOffset) {
    failures.push('public/index.html must discover core stylesheets before synchronous Vue/static scripts.');
  }
  const loginBgPreloadOffset = content.indexOf('href="images/login-hotel-lobby-bg.avif"');
  if (!/<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']\s+as=["']image["']\s+type=["']image\/avif["']\s+fetchpriority=["']high["']/.test(content)) {
    failures.push('public/index.html must preload the optimized AVIF login background with high fetch priority.');
  }
  if (loginBgPreloadOffset < 0 || tailwindOffset < 0 || loginBgPreloadOffset > tailwindOffset) {
    failures.push('public/index.html must discover the AVIF login background preload before core stylesheets.');
  }

  if (/vue-router\.global\.prod\.js/.test(content)) {
    failures.push('public/index.html must not eagerly load vue-router.global.prod.js; the current shell uses currentPage state navigation.');
  }
  if (fs.existsSync(path.join(repoRoot, 'public/vue-router.global.prod.js'))) {
    failures.push('public/vue-router.global.prod.js is unused by the current shell and must not remain as a dead public asset.');
  }

  if (/<script\s+src=["']hotel-image-optimizer-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load hotel-image-optimizer-static.js; it is not required for the initial shell.');
  }
  if (!/const\s+hotelImageOptimizerStaticScript\s*=\s*["']hotel-image-optimizer-static\.js["']/.test(content) || !/const\s+loadHotelImageOptimizerStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for hotel-image-optimizer-static.js.');
  }
  if (!/newPage === ['"]agent-center['"] \|\| newPage === ['"]hotel-image-optimizer['"]/.test(content) || !/ensureHotelImageOptimizerReady\(\)/.test(content)) {
    failures.push('public/index.html must load hotel image optimizer static data only when agent-center or hotel-image-optimizer is opened.');
  }
  if (/<script\s+src=["']revenue-research-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load revenue-research-static.js; it is only required by revenue-research-center.');
  }
  if (!/const\s+revenueResearchStaticScript\s*=\s*["']revenue-research-static\.js["']/.test(content) || !/const\s+loadRevenueResearchStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for revenue-research-static.js.');
  }
  if (!/newPage === ['"]revenue-research-center['"]/.test(content) || !/ensureRevenueResearchReady\(\)/.test(content)) {
    failures.push('public/index.html must load revenue research static data only when revenue-research-center is opened.');
  }
  if (/<script\s+src=["']expansion-static-options\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load expansion-static-options.js; the login and compass shell do not need investment expansion options.');
  }
  if (!/const\s+expansionStaticOptionsScript\s*=\s*["']expansion-static-options\.js["']/.test(content) || !/const\s+loadExpansionStaticOptions\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for expansion-static-options.js.');
  }
  if (!/ensureExpansionStaticReady\(\)/.test(content) || !/isExpansionStaticPage\(newPage\)/.test(content)) {
    failures.push('public/index.html must load expansion static data only when investment expansion pages are opened.');
  }
  if (/<script\s+src=["']simulation-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load simulation-static.js; the login and compass shell do not need simulation and transfer calculators.');
  }
  if (!/const\s+simulationStaticScript\s*=\s*["']simulation-static\.js["']/.test(content) || !/const\s+loadSimulationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for simulation-static.js.');
  }
  if (!/ensureSimulationStaticReady\(\)/.test(content) || !/isSimulationStaticPage\(newPage\)/.test(content)) {
    failures.push('public/index.html must load simulation static data only when simulation, feasibility, benchmark, collaboration, or transfer pages are opened.');
  }
  const simulationDetailLoader = content.slice(
    content.indexOf('const loadSimulationDetail = async'),
    content.indexOf('const reuseSimulationRecord = async')
  );
  if (!simulationDetailLoader.includes('await ensureSimulationStaticReady();')) {
    failures.push('public/index.html must load simulation static data before reusing simulation history input.');
  }
  const transferDetailLoader = content.slice(
    content.indexOf('const loadTransferDetail = async'),
    content.indexOf('const reuseTransferRecord = async')
  );
  if (!transferDetailLoader.includes('await ensureSimulationStaticReady();')) {
    failures.push('public/index.html must load simulation static data before reusing transfer history input.');
  }
  if (/<script\s+src=["']ai-analysis-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load ai-analysis-static.js; the login and compass shell do not need OTA AI analysis helpers.');
  }
  if (!/const\s+aiAnalysisStaticScript\s*=\s*["']ai-analysis-static\.js["']/.test(content) || !/const\s+loadAiAnalysisStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for ai-analysis-static.js.');
  }
  if (!/if \(tab === ['"]ai['"]\)[\s\S]*await ensureAiAnalysisStaticReady\(\);/.test(content)
    || !/runPageLoadOnce\(currentPage\.value \|\| ['"]online-data['"], ['"]ai-analysis-static['"][\s\S]*await ensureAiAnalysisStaticReady\(\);/.test(content)) {
    failures.push('public/index.html must load AI analysis static data only from the OTA AI tab or online analysis tab.');
  }
  const startAiAnalysisSource = content.slice(
    content.indexOf('const startAiAnalysis = async'),
    content.indexOf('const generateLocalAnalysis =', content.indexOf('const startAiAnalysis = async'))
  );
  if (!/runCapturedOtaAnalysisStartFlow\(\{/.test(startAiAnalysisSource)
    || /buildCapturedOtaAnalysisRunContext\(\{/.test(startAiAnalysisSource)
    || /runCapturedOtaAnalysisExecution\(\{/.test(startAiAnalysisSource)) {
    failures.push('public/index.html startAiAnalysis must use ai-analysis-static.js runCapturedOtaAnalysisStartFlow instead of inlining captured OTA AI orchestration.');
  }
  if (/<script\s+src=["']auto-fetch-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load auto-fetch-static.js; the login shell and default online-data page do not need platform auto-fetch helpers.');
  }
  if (!/const\s+autoFetchStaticScript\s*=\s*["']auto-fetch-static\.js["']/.test(content)
    || !/const\s+loadAutoFetchStatic\s*=\s*\(\)\s*=>/.test(content)
    || !/const\s+ensureAutoFetchStaticReady\s*=\s*async\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader and ready guard for auto-fetch-static.js.');
  }
  if (!/const loadAutoFetchPanel = async[\s\S]*await ensureAutoFetchStaticReady\(\);/.test(content)
    || !/const triggerAutoFetch = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*requireAutoFetchStatic\(['"]runAutoFetchTriggerFlow['"]\)/.test(content)) {
    failures.push('public/index.html must load auto-fetch-static.js before opening the platform-auto panel or triggering manual auto-fetch.');
  }
  if (!/const openDataConfigModal = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*const loadDataConfig = async/.test(content)
    || !/const saveDataConfig = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*const testDataConfig = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*requireAutoFetchStatic\(['"]runDataConfigTestFlow['"]\)/.test(content)) {
    failures.push('public/index.html must load auto-fetch-static.js before data-source config form parsing, saving, or testing.');
  }
  const testDataConfigStart = content.indexOf('const testDataConfig = async');
  const testDataConfigEnd = content.indexOf('const loadHolidayRevenueCountdown = async', testDataConfigStart);
  const testDataConfigSource = testDataConfigStart >= 0 && testDataConfigEnd > testDataConfigStart
    ? content.slice(testDataConfigStart, testDataConfigEnd)
    : '';
  if (/switch\s*\(\s*type\s*\)/.test(testDataConfigSource)
    || /\/online-data\/fetch-ctrip-ads/.test(testDataConfigSource)) {
    failures.push('public/index.html must not re-inline data-source config test endpoint selection; use auto-fetch-static.js runDataConfigTestFlow.');
  }
  if (!/const ensureManualOnlineFetchConfigReady = async[\s\S]*loadCtripConfigList\(\)[\s\S]*loadMeituanConfigList\(\)/.test(content)) {
    failures.push('public/index.html must keep a lightweight manual-fetch config prewarm that loads saved Ctrip/Meituan config lists without opening the full platform-auto panel.');
  }
  if (!/newTab === ['"]data['"][\s\S]{0,240}ensureManualOnlineFetchConfigReady\(\)/.test(content)
    || !/item\.path === ['"]online-data['"] && item\.tab === ['"]data['"][\s\S]{0,180}ensureManualOnlineFetchConfigReady\(\)/.test(content)) {
    failures.push('public/index.html must prewarm saved platform configs when the online-data manual data tab is opened.');
  }
  const onlineDataDefaultLoader = content.slice(
    content.indexOf("if (newPage === 'online-data' && token.value)"),
    content.indexOf("if (newPage === 'operation-logs'")
  );
  if (onlineDataDefaultLoader.includes('loadAutoFetchPanel()')) {
    failures.push('public/index.html must not preload the full platform-auto panel from the default online-data page load.');
  }
  if (/onlineDataTab\s*=\s*['"]ctrip-fetch-settings['"][^@]*loadAutoFetchPanel\(\)/.test(content)
    || /tab\s*===\s*['"]traffic['"][\s\S]{0,220}loadAutoFetchPanel\(\)/.test(content)) {
    failures.push('public/index.html must not load the full platform-auto panel from Ctrip fetch settings or download tab switches.');
  }
  if (!/newTab === ['"]platform-auto['"][\s\S]*loadAutoFetchPanel\(\)/.test(content)) {
    failures.push('public/index.html must lazy-load the platform-auto panel when the platform-auto tab is opened.');
  }
  if (/onlineDataTab\s*=\s*['"]platform-sources['"][^@]*loadPlatformDataSourcePanel\(\);\s*loadPlatformProfileStatus/.test(content)) {
    failures.push('public/index.html must not duplicate platform profile status loading when opening platform-sources.');
  }
  const autoFetchPanelLoader = content.slice(
    content.indexOf('const loadAutoFetchPanel = async'),
    content.indexOf('const loadAutoFetchStatus = async')
  );
  if (!/await loadAutoFetchStatus\(\{\s*detail:\s*false\s*\}\);[\s\S]*scheduleAutoFetchStatusDetailRefresh\(\);[\s\S]*schedulePlatformProfileStatusRefresh\(\{ silent: true \}\);/.test(autoFetchPanelLoader)
    || /await Promise\.all\(\[[\s\S]*loadAutoFetchStatus\(\)[\s\S]*loadPlatformProfileStatus/.test(autoFetchPanelLoader)) {
    failures.push('public/index.html must let platform-auto first paint wait only for light auto-fetch status and defer detail/profile refresh.');
  }
  const autoFetchModePayloadSource = content.slice(
    content.indexOf('const buildAutoFetchModePayload = () => ({'),
    content.indexOf('const buildAutoFetchSchedulePayload = () => ({')
  );
  if (!/ctrip_auto_fetch_mode:\s*autoFetchMode\.value/.test(autoFetchModePayloadSource)
    || !/meituan_auto_fetch_mode:\s*autoFetchMode\.value/.test(autoFetchModePayloadSource)) {
    failures.push('public/index.html must keep platform auto-fetch Ctrip and Meituan modes on the selected fast mode by default.');
  }
  if (/ctrip_auto_fetch_mode:\s*['"]profile_browser['"]/.test(autoFetchModePayloadSource)) {
    failures.push('public/index.html must not force platform auto-fetch Ctrip runs through browser Profile by default.');
  }
  if (!content.includes('schedulePostFetchRefresh')
    || !content.includes('scheduleOnlineDataRefresh')
    || !content.includes('scheduleOnlineHistoryRefresh')) {
    failures.push('public/index.html must keep post-fetch refreshes deferred so manual and auto collection do not block the UI.');
  }
  for (const requiredScheduler of [
    'scheduleLatestCtripRefresh',
    'scheduleAutoFetchStatusDetailRefresh',
    'scheduleDataHealthPanelRefresh',
    'schedulePlatformProfileStatusRefresh',
    'schedulePlatformDataSourcesRefresh',
  ]) {
    if (!content.includes(`const ${requiredScheduler}`)) {
      failures.push(`public/index.html must keep ${requiredScheduler} for deferred post-fetch refresh work.`);
    }
  }
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
    if (content.includes(directRefreshBinding)) {
      failures.push(`public/index.html must pass scheduled post-fetch refresh callbacks instead of direct binding: ${directRefreshBinding.trim()}`);
    }
  }
  const autoFetchStaticPath = path.join(repoRoot, 'public/auto-fetch-static.js');
  const autoFetchStaticContent = fs.existsSync(autoFetchStaticPath) ? fs.readFileSync(autoFetchStaticPath, 'utf8') : '';
  const ctripStaticPath = path.join(repoRoot, 'public/ctrip-static.js');
  const ctripStaticContent = fs.existsSync(ctripStaticPath) ? fs.readFileSync(ctripStaticPath, 'utf8') : '';
  const meituanStaticPath = path.join(repoRoot, 'public/meituan-static.js');
  const meituanStaticContent = fs.existsSync(meituanStaticPath) ? fs.readFileSync(meituanStaticPath, 'utf8') : '';
  if (!/const\s+buildAutoFetchTriggerRequestBody[\s\S]*async:\s*true/.test(autoFetchStaticContent)) {
    failures.push('public/auto-fetch-static.js must submit platform auto-fetch triggers with async: true so the UI is not blocked by OTA collection.');
  }
  if (!/return\s+\{\s*status:\s*['"]accepted['"]/.test(autoFetchStaticContent)
    || !/runPostFetchRefresh\(loadAutoFetchStatus\)/.test(autoFetchStaticContent)) {
    failures.push('public/auto-fetch-static.js must treat backend running/queued auto-fetch responses as accepted and refresh status without blocking.');
  }
  if (!/\{\s*\.\.\.requestContext\.requestBody,\s*async:\s*true\s*\}/.test(ctripStaticContent)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody/.test(ctripStaticContent)) {
    failures.push('public/ctrip-static.js must submit Ctrip manual fetch in background mode and treat running responses as accepted.');
  }
  if (!/\{\s*\.\.\.task\.body,\s*async:\s*true\s*\}/.test(meituanStaticContent)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*acceptedCount/.test(meituanStaticContent)) {
    failures.push('public/meituan-static.js must submit Meituan manual batch fetch in background mode and treat running responses as accepted.');
  }
  const controllerPath = path.join(repoRoot, 'app/controller/OnlineData.php');
  const controllerContent = fs.existsSync(controllerPath) ? fs.readFileSync(controllerPath, 'utf8') : '';
  const manualTaskServicePath = path.join(repoRoot, 'app/service/ManualOnlineFetchTaskService.php');
  const manualTaskServiceContent = fs.existsSync(manualTaskServicePath) ? fs.readFileSync(manualTaskServicePath, 'utf8') : '';
  if (!controllerContent.includes("get('include_detail'") || !controllerContent.includes("'detail_loaded' => false")) {
    failures.push('app/controller/OnlineData.php must support light auto-fetch status with explicit detail_loaded=false.');
  }
  if (!manualTaskServiceContent.includes('final class ManualOnlineFetchTaskService')
    || !manualTaskServiceContent.includes('online-data:manual-fetch-once')
    || !controllerContent.includes("createTask('ctrip'")
    || !controllerContent.includes('launchTask($task)')
    || controllerContent.includes('private function createManualCtripFetchBackgroundTask')
    || controllerContent.includes('private function launchManualCtripFetchBackgroundTask')) {
    failures.push('app/controller/OnlineData.php must use ManualOnlineFetchTaskService for Ctrip manual fetch background task support.');
  }
  if (!controllerContent.includes("createTask('meituan'")
    || controllerContent.includes('private function createManualMeituanFetchBackgroundTask')) {
    failures.push('app/controller/OnlineData.php must use ManualOnlineFetchTaskService for Meituan manual fetch background task support.');
  }
  const strategyDetailLoader = content.slice(
    content.indexOf('const loadStrategyDetail = async'),
    content.indexOf('const reuseStrategyRecord = async')
  );
  if (!strategyDetailLoader.includes('await ensureExpansionStaticReady();')) {
    failures.push('public/index.html must load expansion static data before reusing strategy history input.');
  }
  if (/<script\s+src=["']operation-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load operation-static.js; it is only required by operation/opening/lifecycle pages.');
  }
  if (!/const\s+operationStaticScript\s*=\s*["']operation-static\.js["']/.test(content) || !/const\s+loadOperationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for operation-static.js.');
  }
  if (!content.includes('await ensureOperationStaticReady();')
    || !/newPage === ['"]lifecycle['"]/.test(content)
    || !/newPage === ['"]opening-overview['"] \|\| newPage === ['"]opening-checklist['"]/.test(content)
    || !/newPage === ['"]ops-source['"]/.test(content)
    || !/newPage === ['"]ops-analysis['"] \|\| newPage === ['"]ops-plan['"]/.test(content)
    || !/newPage === ['"]ops-insight['"]/.test(content)
    || !/newPage === ['"]ops-track['"]/.test(content)) {
    failures.push('public/index.html must load operation static data before operation, opening, and lifecycle page work.');
  }
  if (/<script\s+src=["']notification-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load notification-static.js; the login shell does not need notification rendering helpers.');
  }
  if (!/const\s+notificationStaticScript\s*=\s*["']notification-static\.js["']/.test(content) || !/const\s+loadNotificationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for notification-static.js.');
  }
  if (!/await ensureNotificationStaticReady\(\);/.test(content) || !/const\s+globalNotifications\s*=\s*computed\(\(\)\s*=>\s*\{[\s\S]*notificationStaticReady\.value/.test(content)) {
    failures.push('public/index.html must load notification static data before notification refresh and avoid building notifications before the helper is ready.');
  }
  if (/<script\s+src=["']testid-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load testid-static.js; the login shell only needs inline page/menu test id helpers.');
  }
  if (!/const\s+testIdStaticScript\s*=\s*["']testid-static\.js["']/.test(content) || !/const\s+loadTestIdStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for testid-static.js.');
  }
  if (!/const\s+pageTestId\s*=\s*\(page\)\s*=>/.test(content)
    || !/const\s+menuTestId\s*=\s*\(item\)\s*=>/.test(content)
    || !/createPageTestIdController/.test(content)) {
    failures.push('public/index.html must keep page/menu test ids available before lazy-loading the page-control test id controller.');
  }

  if (!/<script\s+src=["']form-operation-support\.js["']\s+defer\s*><\/script>/.test(content)) {
    failures.push('public/index.html must defer form-operation-support.js because it self-initializes and is not a Vue setup dependency.');
  }

  const vueBoundaryMarkers = [
    { name: 'Ctrip Profile field modal', marker: 'data-testid="ctrip-profile-field-modal"' },
    { name: 'Ctrip Cookie editor modal', marker: 'v-if="showCtripCookieEditorModal"' },
    { name: 'Online data edit modal', marker: 'v-if="showOnlineDataEditModal"' },
    { name: 'Data config modal', marker: 'v-if="showDataConfigModal"' },
    { name: 'Toast container', marker: 'v-if="toast.show"' },
  ];

  for (const marker of vueBoundaryMarkers) {
    const offset = content.indexOf(marker.marker);
    if (offset < 0) {
      failures.push(`public/index.html missing Vue boundary marker: ${marker.name}.`);
      continue;
    }
    if (!hasOpenVueRoot(openTagStackBefore(content, offset))) {
      failures.push(
        `public/index.html Vue boundary broken before ${marker.name} at line ${lineNumberForOffset(content, offset)}. ` +
        'Global modals and toast must stay inside #app; check malformed <div>, <details>, <template>, or <teleport> closures.'
      );
    }
  }
}

for (const relativePath of coreFetchFlowFiles) {
  const flowPath = path.join(repoRoot, relativePath);
  if (!fs.existsSync(flowPath)) {
    failures.push(`${relativePath} is missing.`);
    continue;
  }

  const flowSource = fs.readFileSync(flowPath, 'utf8');
  if (!flowSource.includes('const runPostFetchRefresh')) {
    failures.push(`${relativePath} must keep a non-blocking post-fetch refresh helper.`);
  }

  for (const blockedCall of [
    'await refreshOnlineHistory(',
    'await refreshLatestCtripData(',
    'await refreshOnlineData(',
    'await loadAutoFetchStatus(',
  ]) {
    if (flowSource.includes(blockedCall)) {
      failures.push(`${relativePath} must not block collection completion with ${blockedCall}.`);
    }
  }
}

if (!fs.existsSync(stylePath)) {
  failures.push('public/style.css is missing.');
} else {
  const styleSource = fs.readFileSync(stylePath, 'utf8');
  if (!fs.existsSync(loginBgPngPath)) {
    failures.push('public/images/login-hotel-lobby-bg.png fallback is missing.');
  }
  if (!fs.existsSync(loginBgWebpPath)) {
    failures.push('public/images/login-hotel-lobby-bg.webp optimized login background is missing.');
  }
  if (!fs.existsSync(loginBgAvifPath)) {
    failures.push('public/images/login-hotel-lobby-bg.avif optimized login background is missing.');
  }
  if (fs.existsSync(loginBgPngPath) && fs.existsSync(loginBgWebpPath) && fs.existsSync(loginBgAvifPath)) {
    const pngSize = fs.statSync(loginBgPngPath).size;
    const webpSize = fs.statSync(loginBgWebpPath).size;
    const avifSize = fs.statSync(loginBgAvifPath).size;
    if (webpSize >= pngSize * 0.25) {
      failures.push('public/images/login-hotel-lobby-bg.webp must remain a substantially smaller first-choice login background.');
    }
    if (avifSize >= webpSize * 0.75) {
      failures.push('public/images/login-hotel-lobby-bg.avif must remain smaller than the WebP login background.');
    }
  }
  const loginPngOffset = styleSource.indexOf('images/login-hotel-lobby-bg.png');
  const loginWebpOffset = styleSource.indexOf('images/login-hotel-lobby-bg.webp');
  const loginAvifOffset = styleSource.indexOf('images/login-hotel-lobby-bg.avif');
  if (loginPngOffset === -1 || loginWebpOffset === -1 || loginAvifOffset === -1) {
    failures.push('public/style.css must keep the original PNG declaration plus optimized AVIF and WebP login background declarations.');
  }
  if (loginPngOffset !== -1 && loginWebpOffset !== -1 && loginAvifOffset !== -1 && (loginAvifOffset < loginPngOffset || loginWebpOffset < loginAvifOffset)) {
    failures.push('public/style.css must declare login backgrounds in PNG legacy, AVIF first-choice, then WebP fallback order.');
  }
  if (!styleSource.includes('-webkit-image-set(') || !styleSource.includes('image-set(') || !styleSource.includes('type("image/avif")') || !styleSource.includes('type("image/webp")')) {
    failures.push('public/style.css must use image-set declarations for the optimized AVIF/WebP login background with PNG fallback.');
  }
}

if (!fs.existsSync(publicRouterPath)) {
  failures.push('public/router.php is missing.');
} else {
  const routerSource = fs.readFileSync(publicRouterPath, 'utf8');
  if (!routerSource.includes("'runtime' . DIRECTORY_SEPARATOR . 'static-gzip'")) {
    failures.push('public/router.php must cache gzip output under runtime/static-gzip to avoid repeated CPU compression on large local assets.');
  }
  if (!routerSource.includes("file_put_contents($gzipCacheFile, $encoded, LOCK_EX)")) {
    failures.push('public/router.php must persist gzip cache files atomically enough for local dev reloads.');
  }
  if (!routerSource.includes("header('Content-Length: ' . (int)filesize($gzipCacheFile))")) {
    failures.push('public/router.php must send Content-Length for cached gzip assets.');
  }
  if (!routerSource.includes("header('Content-Length: ' . strlen($encoded))")) {
    failures.push('public/router.php must send Content-Length for refreshed gzip assets.');
  }
  if (!/gzencode\(\(string\)file_get_contents\(\$staticFile\),\s*1\)/.test(routerSource)) {
    failures.push('public/router.php must use gzip level 1 when refreshing the static gzip cache.');
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Public entry guard passed.');
