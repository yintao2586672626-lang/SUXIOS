import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const indexPath = path.join(repoRoot, 'public/index.html');
const publicRouterPath = path.join(repoRoot, 'public/router.php');
const systemStaticPath = path.join(repoRoot, 'public/system-static.js');
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
  const systemStaticContent = fs.existsSync(systemStaticPath) ? fs.readFileSync(systemStaticPath, 'utf8') : '';
  const ctripStaticPath = path.join(repoRoot, 'public/ctrip-static.js');
  const ctripStaticContent = fs.existsSync(ctripStaticPath) ? fs.readFileSync(ctripStaticPath, 'utf8') : '';
  const meituanStaticPath = path.join(repoRoot, 'public/meituan-static.js');
  const meituanStaticContent = fs.existsSync(meituanStaticPath) ? fs.readFileSync(meituanStaticPath, 'utf8') : '';

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

  if (!content.includes('const suxiApp = createApp({')
    || !content.includes('const renderSuxiStartupError = (error) => {')
    || !content.includes('suxiApp.config.errorHandler = (error) => {')
    || !content.includes("suxiApp.mount('#app');")) {
    failures.push('public/index.html must surface Vue startup/runtime initialization errors through the app root instead of failing silently.');
  }
  if (!content.includes(".replace(/[<>&\"']/g")) {
    failures.push('public/index.html startup error renderer must HTML-escape error messages before injecting them into #app.');
  }
  if (!content.includes("const stack = String(error?.stack || '').split('\\n').slice(0, 8).join('\\n');")
    || !content.includes("[String(error?.message || error || 'unknown startup error'), stack].filter(Boolean).join('\\n')")) {
    failures.push('public/index.html startup error renderer must include bounded stack evidence for debugging startup failures.');
  }
  if (!content.includes("if (appRoot.dataset.startupErrorRendered === '1') return;")
    || !content.includes("appRoot.dataset.startupErrorRendered = '1';")) {
    failures.push('public/index.html startup error renderer must be idempotent so repeated runtime errors do not keep replacing #app.');
  }
  if (!content.includes("if (!u || typeof u !== 'object') return false;")
    || !content.includes("const username = String(u.username || '');")
    || !content.includes("const realname = String(u.realname || '');")) {
    failures.push('public/index.html user filtering must skip invalid rows and normalize names before matching search input.');
  }
  if (!content.includes(':key="u?.id || index"')
    || !content.includes("{{ u?.username || '-' }}")
    || !content.includes("String(u?.status) === '1'")
    || !content.includes('v-if="u && (user?.is_super_admin')) {
    failures.push('public/index.html user table must render invalid or partial rows safely after user filtering.');
  }
  if (!content.includes('v-for="(u, index) in logUsers"')
    || !content.includes(':value="u?.id || \'\'"')
    || !content.includes("{{ u?.realname || u?.username || '-' }}")) {
    failures.push('public/index.html operation-log user filter must render invalid or partial user rows safely.');
  }
  if (!/<script\s+src=["']vue\.global\.prod\.js\?v=[^"']+["']><\/script>/.test(content)
    || !/<script\s+src=["']system-static\.js\?v=[^"']+["']><\/script>/.test(content)) {
    failures.push('public/index.html must version core Vue/system static scripts so P0 entry fixes are not hidden by stale browser cache.');
  }

  if (/\/assets\/index-[A-Za-z0-9_-]+\.(?:js|css)/.test(content)) {
    failures.push('public/index.html references Vite hashed assets; do not build Vite into HOTEL/public.');
  }

  const tailwindOffset = content.indexOf('href="tailwind.min.css"');
  const vueScriptMatch = content.match(/<script\s+src=["']vue\.global\.prod\.js(?:\?v=[^"']+)?["']/);
  const vueScriptOffset = vueScriptMatch ? vueScriptMatch.index : -1;
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
  const openDataConfigModalStart = content.indexOf('const openDataConfigModal =');
  const openDataConfigModalEnd = content.indexOf('const firstDataConfigValue =', openDataConfigModalStart);
  const openDataConfigModalSource = openDataConfigModalStart >= 0 && openDataConfigModalEnd > openDataConfigModalStart
    ? content.slice(openDataConfigModalStart, openDataConfigModalEnd)
    : '';
  if (!/let dataConfigModalLoadSeq = 0;[\s\S]*const openDataConfigModal = \(type\) => \{/.test(content)
    || !openDataConfigModalSource.includes('showDataConfigModal.value = true;')
    || !openDataConfigModalSource.includes('deferUiTask(async () => {')
    || !openDataConfigModalSource.includes('const isCurrentConfigModal = () =>')
    || openDataConfigModalSource.includes('const openDataConfigModal = async')
    || /await loadDataConfig\(type\);[\s\S]*showDataConfigModal\.value = true;/.test(openDataConfigModalSource)
    || /await ensureAutoFetchStaticReady\(\);[\s\S]*currentDataConfigType\.value = type;/.test(openDataConfigModalSource)) {
    failures.push('public/index.html data-source config modal must open before loading auto-fetch-static.js or saved system-config data.');
  }
  if (!/const loadDataConfig = async \(type, options = \{\}\) => \{[\s\S]*const shouldApply = typeof options\.shouldApply === 'function' \? options\.shouldApply : \(\) => true;[\s\S]*if \(!shouldApply\(\)\) return;/.test(content)
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
  if (!/let ctripConfigListLoadingPromise = null;[\s\S]*const loadCtripConfigList = async[\s\S]*if \(ctripConfigListLoadingPromise\) \{[\s\S]*return ctripConfigListLoadingPromise;[\s\S]*finally \{[\s\S]*ctripConfigListLoadingPromise = null;/.test(content)) {
    failures.push('public/index.html must deduplicate concurrent Ctrip config-list loads for manual-fetch prewarm and tab switching.');
  }
  if (!/const ctripConfigDetailCache = new Map\(\);[\s\S]*const ctripConfigDetailLoadingPromises = new Map\(\);[\s\S]*const loadCtripConfigDetail = async[\s\S]*if \(ctripConfigDetailLoadingPromises\.has\(cacheKey\)\)[\s\S]*return ctripConfigDetailLoadingPromises\.get\(cacheKey\);[\s\S]*const ensureCtripConfigSecret = async[\s\S]*const cached = cacheKey \? ctripConfigDetailCache\.get\(cacheKey\) : null;/.test(content)) {
    failures.push('public/index.html must cache and deduplicate full Ctrip config detail loads for manual-fetch hotel switching.');
  }
  if (!content.includes("clearCtripConfigDetailCache(body?.id || '');")
    || !content.includes("clearCtripConfigDetailCache(ctrip.id || existing?.id || '');")
    || !/deleteCtripConfig = async[\s\S]*clearCtripConfigDetailCache\(id\);/.test(content)
    || !/batchDeleteCtripConfigs = async[\s\S]*clearCtripConfigDetailCache\(id\);/.test(content)) {
    failures.push('public/index.html must invalidate cached full Ctrip config details after Ctrip config saves or deletes.');
  }
  if (!/newTab === ['"]data['"][\s\S]{0,240}ensureManualOnlineFetchConfigReady\(\)/.test(content)
    || !/item\.path === ['"]online-data['"] && item\.tab === ['"]data['"][\s\S]{0,180}ensureManualOnlineFetchConfigReady\(\)/.test(content)) {
    failures.push('public/index.html must prewarm saved platform configs when the online-data manual data tab is opened.');
  }
  const ctripEbookingDefaultLoader = content.slice(
    content.indexOf("if (newPage === 'ctrip-ebooking')"),
    content.indexOf("if (newPage === 'meituan-ebooking'")
  );
  if (!content.includes('const scheduleCtripEbookingDeferredStartupRefresh = () => {')
    || !ctripEbookingDefaultLoader.includes('scheduleCtripEbookingDeferredStartupRefresh();')) {
    failures.push('public/index.html must defer Ctrip eBooking config/latest/cookie/bookmarklet startup refreshes until after the first paint loader.');
  }
  if (!/await Promise\.allSettled\(\[\s*loadOnlineDataHotelList\(\),\s*loadDataHealthPanel\(['"]light['"]\),\s*\]\);/.test(ctripEbookingDefaultLoader)) {
    failures.push('public/index.html Ctrip eBooking first-paint loader must keep only hotel list and light data-health status.');
  }
  if (/runPageLoadOnce\(newPage,\s*['"]main['"][\s\S]*Promise\.allSettled\(\[[\s\S]*loadCtripConfigList\(\)[\s\S]*loadCookiesList\(\)[\s\S]*loadBookmarklet\(\)[\s\S]*\]\)/.test(ctripEbookingDefaultLoader)) {
    failures.push('public/index.html Ctrip eBooking default loader must not start config/latest/cookie/bookmarklet refreshes in the first-paint request group.');
  }
  const ctripManualTabTemplate = content.slice(
    content.indexOf("currentPage === 'ctrip-ebooking'"),
    content.indexOf("onlineDataTab !== 'data-health'")
  );
  if (!content.includes('const openCtripManualTab = (tab) => {')
    || !content.includes("const runCtripManualTabSwitch = requireCtripStatic('runCtripManualTabSwitch');")
    || !content.includes('deferUiTask(() => runCtripManualTabSwitch({')
    || !content.includes('getCurrentPage: () => currentPage.value')
    || !content.includes('getCurrentTab: () => onlineDataTab.value')
    || !ctripManualTabTemplate.includes("@click=\"openCtripManualTab('ctrip-flow-overview')\"")
    || ctripManualTabTemplate.includes("onlineDataTab = 'ctrip-flow-overview'; loadCtripConfigList()")
    || ctripManualTabTemplate.includes("onlineDataTab = 'ctrip-fetch-settings'; loadCtripConfigList()")
    || ctripManualTabTemplate.includes("onlineDataTab = 'ctrip-ads'; syncCtripAdsDirectConfig(false)")) {
    failures.push('public/index.html Ctrip manual tab buttons must use the non-blocking tab switch helper.');
  }
  const openCtripManualTabSource = content.slice(
    content.indexOf('const openCtripManualTab = (tab) => {'),
    content.indexOf('const openMeituanManualTab = (tab) => {')
  );
  if (openCtripManualTabSource.includes('await loadCtripConfigList();')
    || openCtripManualTabSource.includes("if (['ctrip-flow-overview', 'ctrip-fetch-settings', 'ctrip-ads'].includes(tab))")
    || !ctripStaticContent.includes('const runCtripManualTabSwitch = async')) {
    failures.push('public/index.html must keep Ctrip manual tab async branching in public/ctrip-static.js.');
  }
  const refreshCtripHotelConfigOptionsSource = content.slice(
    content.indexOf('const refreshCtripHotelConfigOptions ='),
    content.indexOf('const applyMeituanHotelConfig = async')
  );
  if (!content.includes('const refreshCtripHotelConfigOptions = () => {')
    || !/deferUiTask\(async \(\) =>[\s\S]*Promise\.allSettled\(\[loadHotels\(\), loadCtripConfigList\(\)\]\)[\s\S]*applyCtripHotelConfig\(false\)/.test(refreshCtripHotelConfigOptionsSource)
    || refreshCtripHotelConfigOptionsSource.includes('const refreshCtripHotelConfigOptions = async')
    || refreshCtripHotelConfigOptionsSource.includes('await Promise.all([loadHotels(), loadCtripConfigList()]);')) {
    failures.push('public/index.html Ctrip hotel config refresh must not block manual fetch controls on config-list loading.');
  }
  const openCtripOverviewFetchTabSource = content.slice(
    content.indexOf('const openCtripOverviewFetchTab = async'),
    content.indexOf('const ctripOverviewCookieApiSections')
  );
  if (!/onlineDataTab\.value = tabName;\s*deferUiTask\(async \(\) =>/.test(openCtripOverviewFetchTabSource)
    || /await loadCtripConfigList\(\);[\s\S]*onlineDataTab\.value = tabName/.test(openCtripOverviewFetchTabSource)) {
    failures.push('public/index.html Ctrip overview external tab entry must switch tabs before deferred config loading.');
  }
  const openCtripCookieCreateFromHealthSource = content.slice(
    content.indexOf('const openCtripCookieCreateFromHealth ='),
    content.indexOf('const closeCtripCookieEditor =')
  );
  if (!content.includes('const openCtripCookieCreateFromHealth = () => {')
    || !openCtripCookieCreateFromHealthSource.includes('deferUiTask(() => loadCtripConfigList(), 80);')
    || openCtripCookieCreateFromHealthSource.includes('const openCtripCookieCreateFromHealth = async')
    || openCtripCookieCreateFromHealthSource.includes('await loadCtripConfigList();')) {
    failures.push('public/index.html Ctrip health Cookie create action must open the config form before config-list loading.');
  }
  const meituanEbookingDefaultLoader = content.slice(
    content.indexOf("if (newPage === 'meituan-ebooking'"),
    content.indexOf("if (newPage === 'hotels'")
  );
  if (!content.includes('const scheduleMeituanEbookingDeferredStartupRefresh = () => {')
    || !meituanEbookingDefaultLoader.includes('scheduleMeituanEbookingDeferredStartupRefresh();')) {
    failures.push('public/index.html must defer Meituan eBooking config matching and secondary startup refreshes until after route entry.');
  }
  if (/runPageLoadOnce\(newPage,\s*['"]main['"][\s\S]*loadMeituanConfigList\(\)/.test(meituanEbookingDefaultLoader)) {
    failures.push('public/index.html Meituan eBooking default loader must not synchronously request saved configs in the first-paint group.');
  }
  const meituanManualTabTemplate = content.slice(
    content.indexOf("currentPage === 'meituan-ebooking'"),
    content.indexOf("onlineDataTab === 'meituan-traffic'")
  );
  if (!content.includes('const openMeituanManualTab = (tab) => {')
    || !content.includes("const runMeituanManualTabSwitch = requireMeituanStatic('runMeituanManualTabSwitch');")
    || !content.includes('deferUiTask(() => runMeituanManualTabSwitch({')
    || !content.includes('getCurrentPage: () => currentPage.value')
    || !content.includes('getCurrentTab: () => onlineDataTab.value')
    || !meituanManualTabTemplate.includes("@click=\"openMeituanManualTab('meituan-ranking')\"")
    || meituanManualTabTemplate.includes("onlineDataTab = 'meituan-ranking'; loadMeituanConfigList()")) {
    failures.push('public/index.html Meituan manual tab buttons must use the non-blocking tab switch helper.');
  }
  const meituanManualTabsFullTemplate = content.slice(
    content.indexOf('<!-- Tabs -->'),
    content.indexOf('<!-- 美团排名数据表格 -->')
  );
  if (meituanManualTabsFullTemplate.includes("loadMeituanConfigList(); syncMeituanTrafficConfigFromSelectedConfig()")
    || meituanManualTabsFullTemplate.includes("loadMeituanConfigList(); syncMeituanOrderConfigFromSelectedConfig()")
    || meituanManualTabsFullTemplate.includes("loadMeituanConfigList(); syncMeituanAdsConfigFromSelectedConfig()")) {
    failures.push('public/index.html Meituan manual tab switches must not sync forms before deferred config-list loading settles.');
  }
  const openMeituanManualTabSource = content.slice(
    content.indexOf('const openMeituanManualTab = (tab) => {'),
    content.indexOf('const openPlatformAutoTab = (options = {}) =>')
  );
  if (openMeituanManualTabSource.includes('await loadMeituanConfigList();')
    || openMeituanManualTabSource.includes("if (tab === 'meituan-traffic')")
    || !meituanStaticContent.includes('const runMeituanManualTabSwitch = async')) {
    failures.push('public/index.html must keep Meituan manual tab async branching in public/meituan-static.js.');
  }
  const platformProfileActionSource = content.slice(
    content.indexOf('const openPlatformProfileAction = async'),
    content.indexOf("if (target === 'analysis')")
  );
  if (!platformProfileActionSource.includes('scheduleMeituanEbookingDeferredStartupRefresh();')
    || platformProfileActionSource.includes('await loadMeituanConfigList();')) {
    failures.push('public/index.html platform profile Meituan ranking action must not await config-list loading before returning.');
  }
  if (!content.includes('配置待读取，正在准备美团数据源匹配...')
    || !content.includes('配置读取失败，请刷新后重试；未读取成功前不会判断为未配置。')
    || !/meituanConfigListLoaded && !selectedMeituanHotelConfig/.test(content)) {
    failures.push('public/index.html Meituan manual fetch must distinguish pending, failed, and confirmed-missing config states.');
  }
  const applyMeituanHotelConfigSource = content.slice(
    content.indexOf('const applyMeituanHotelConfig = async'),
    content.indexOf('const syncMeituanTrafficConfigFromSelectedConfig')
  );
  if (!/await loadMeituanConfigList\(\);\s*if \(requestedHotelId !== String\(meituanForm\.value\.hotelId \|\| ['"]['"]\)\) return;/.test(applyMeituanHotelConfigSource)
    || applyMeituanHotelConfigSource.includes("request('/online-data/get-meituan-config-list')")) {
    failures.push('public/index.html Meituan hotel selection must reuse the deduplicated config-list loader instead of issuing a direct list request.');
  }
  if (!/const ensureManualOnlineFetchConfigReady = async[\s\S]*!ctripConfigListLoaded\.value && !ctripConfigList\.value\.length[\s\S]*!meituanConfigListLoaded\.value && !meituanConfigList\.value\.length/.test(content)) {
    failures.push('public/index.html manual online-data config prewarm must not refetch known-empty Ctrip or Meituan config lists.');
  }
  if (!/const ensureHotelOtaConfigLists = async[\s\S]*!ctripConfigListLoaded\.value && ctripConfigList\.value\.length === 0[\s\S]*!meituanConfigListLoaded\.value && meituanConfigList\.value\.length === 0/.test(content)) {
    failures.push('public/index.html hotel OTA config prewarm must not refetch known-empty Ctrip or Meituan config lists.');
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
  const downloadCenterTabSource = content.slice(
    content.indexOf('const scheduleDownloadCenterTabLoad = (tab, context = {}) => {'),
    content.indexOf('const getOnlineDataMetricNumber')
  );
  if (!content.includes('const scheduleDownloadCenterTabLoad = (tab, context = {}) => {')
    || !content.includes('const switchDownloadTab = (tab) => {')
    || !content.includes('const switchToDownloadCenter = () => {')
    || !content.includes('const switchToMeituanDownloadCenter = () => {')
    || !/deferUiTask\(async \(\) =>[\s\S]*downloadCenterTab\.value === tab[\s\S]*Promise\.allSettled\(\[[\s\S]*loadOnlineDataList\(\),[\s\S]*loadOnlineDataHotelList\(\)/.test(downloadCenterTabSource)
    || downloadCenterTabSource.includes('const switchDownloadTab = async')
    || downloadCenterTabSource.includes('const switchToDownloadCenter = async')
    || downloadCenterTabSource.includes('const switchToMeituanDownloadCenter = async')
    || downloadCenterTabSource.includes("onlineDataTab.value = 'ctrip-fetch-settings';\n                    await loadCtripConfigList();")
    || downloadCenterTabSource.includes("downloadCenterTab.value = 'overview';\n                await refreshOnlineHistory();")
    || downloadCenterTabSource.includes('await loadOnlineDataList();\n                await loadOnlineDataHotelList();')
    || downloadCenterTabSource.includes('await loadOnlineDataList();\n                    await loadOnlineDataHotelList();')) {
    failures.push('public/index.html download center tab switches must schedule list/config/AI loads after the tab changes.');
  }
  if (!/newTab === ['"]platform-auto['"][\s\S]*schedulePlatformAutoFetchPanelLoad\(\)/.test(content)) {
    failures.push('public/index.html must lazy-load the platform-auto panel when the platform-auto tab is opened.');
  }
  if (!/const\s+schedulePlatformAutoFetchPanelLoad\s*=\s*\(options\s*=\s*\{\}\)\s*=>\s*runPageLoadOnce\(\s*currentPage\.value\s*\|\|\s*['"]online-data['"],\s*['"]platform-auto-panel['"],\s*\(\)\s*=>\s*loadAutoFetchPanel\(options\)/.test(content)
    || !/const\s+openPlatformAutoTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)
    || !/const\s+openOnlinePlatformAutoTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)) {
    failures.push('public/index.html must route platform-auto tab opens through one deduplicated page-load scheduler.');
  }
  if (content.includes("onlineDataTab = 'platform-auto'; loadAutoFetchPanel()")
    || content.includes('onlineDataTab = "platform-auto"; loadAutoFetchPanel()')
    || content.includes("if (row.tab === 'platform-auto') loadAutoFetchPanel();")
    || content.includes('@change="loadAutoFetchPanel"')
    || content.includes('@click="loadAutoFetchPanel"')
    || content.includes("if (item.path === 'online-data' && item.tab === 'platform-auto') {\n                            loadAutoFetchPanel();")) {
    failures.push('public/index.html must not bypass the platform-auto scheduler from buttons, drilldowns, or menu clicks.');
  }
  const autoFetchPanelCacheKeySource = content.slice(
    content.indexOf('const autoFetchPanelCacheKey = () => ['),
    content.indexOf('const resetAutoFetchPanelCache = () => {')
  );
  if (!autoFetchPanelCacheKeySource.includes("String(getAutoFetchHotelId() || '')")
    || !autoFetchPanelCacheKeySource.includes('String(hotels.value?.length || 0)')
    || autoFetchPanelCacheKeySource.includes('ctripConfigList')
    || autoFetchPanelCacheKeySource.includes('meituanConfigList')) {
    failures.push('public/index.html platform-auto panel cache key must not be invalidated by deferred config-list prewarm.');
  }
  if (content.includes('await loadAutoFetchPanel()')) {
    failures.push('public/index.html must not block platform-auto navigation or profile follow-up refreshes on the full auto-fetch panel reload.');
  }
  const platformAutoNavigationRefreshCount = (content.match(/runPageLoadOnce\(['"]online-data['"],\s*['"]platform-auto-panel['"],\s*\(\)\s*=>\s*loadAutoFetchPanel\(\{\s*force:\s*true\s*\}\),\s*\{\s*force:\s*true\s*\}\)/g) || []).length;
  if (platformAutoNavigationRefreshCount < 2) {
    failures.push('public/index.html must schedule platform-auto panel refreshes from notification and hotel console navigation without awaiting them.');
  }
  if (!/deferUiTask\(\(\)\s*=>\s*Promise\.allSettled\(\[\s*loadPlatformProfileStatus\(\{\s*silent:\s*true\s*\}\),\s*loadAutoFetchPanel\(\{\s*force:\s*true\s*\}\),\s*\]\)\)/.test(content)) {
    failures.push('public/index.html must defer profile unbind follow-up refreshes instead of serially awaiting platform-auto reload.');
  }
  if (!/const\s+schedulePlatformDataSourcePanelLoad\s*=\s*\(options\s*=\s*\{\}\)\s*=>\s*runPageLoadOnce\(\s*currentPage\.value\s*\|\|\s*['"]online-data['"],\s*['"]platform-source-panel['"],\s*\(\)\s*=>\s*loadPlatformDataSourcePanel\(options\)/.test(content)
    || !/const\s+openPlatformSourcesTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)) {
    failures.push('public/index.html must route platform source tab opens through one deduplicated page-load scheduler.');
  }
  if (!/const\s+schedulePlatformSyncLogPanelRefresh\s*=\s*\(options\s*=\s*\{\}\)\s*=>\s*runPageLoadOnce\(\s*currentPage\.value\s*\|\|\s*['"]online-data['"],\s*['"]platform-sync-log-panel['"][\s\S]*loadPlatformSyncTasks\(\)[\s\S]*loadPlatformSyncLogs\(\)[\s\S]*loadPlatformProfileStatus\(\{\s*silent:\s*true\s*\}\)/.test(content)
    || !content.includes('@click="schedulePlatformSyncLogPanelRefresh({ force: true })"')) {
    failures.push('public/index.html must route platform sync-log refreshes through the shared scheduler instead of inline requests.');
  }
  if (content.includes("onlineDataTab = 'platform-sources'; loadPlatformDataSourcePanel()")
    || content.includes('onlineDataTab = "platform-sources"; loadPlatformDataSourcePanel()')) {
    failures.push('public/index.html must not double-trigger the heavy platform source panel from inline tab switches.');
  }
  if (content.includes('await loadPlatformDataSourcePanel();')
    || content.includes('await Promise.all([loadPlatformDataSources(), loadPlatformSyncTasks(), loadPlatformSyncLogs(), loadPlatformCollectionResources(), loadOnlineDataList()]);')
    || content.includes('await Promise.all([loadPlatformSyncTasks(), loadPlatformSyncLogs(), loadPlatformProfileStatus({ silent: true })]);')
    || content.includes('await Promise.all([loadPlatformSyncTasks(), loadPlatformSyncLogs()]);')) {
    failures.push('public/index.html must not block platform source save/delete/sync/import flows on full follow-up panel refreshes.');
  }
  if (content.includes('@click="loadPlatformSyncTasks(); loadPlatformSyncLogs()"')) {
    failures.push('public/index.html platform source log button must not synchronously request sync logs inline.');
  }
  if (!content.includes('schedulePlatformDataSourcePanelLoad({ force: true });')) {
    failures.push('public/index.html must force-refresh the platform source panel through the page-load scheduler after source mutations.');
  }
  if (/onlineDataTab\s*=\s*['"]platform-sources['"][^@]*loadPlatformDataSourcePanel\(\);\s*loadPlatformProfileStatus/.test(content)) {
    failures.push('public/index.html must not duplicate platform profile status loading when opening platform-sources.');
  }
  if (!content.includes("requireDataHealthStatic('buildOnlineAnalysisChartConfig')")
    || !content.includes('new ChartLib(ctx, buildOnlineAnalysisChartConfig(analysisData.value.chart_data))')) {
    failures.push('public/index.html must keep online analysis chart options in data-health-static.js and only wire Chart.js lifecycle in the entry.');
  }
  if (content.includes("text: '销售额(¥)'") || content.includes("text: '房晚/订单'")) {
    failures.push('public/index.html must not re-inline online analysis chart axis labels; use buildOnlineAnalysisChartConfig.');
  }
  if (!content.includes("requireSystemStatic('buildKnowledgeImportRequestBody')")
    || !content.includes("requireSystemStatic('knowledgeImportSuccessMessage')")
    || !content.includes("requireSystemStatic('knowledgeImportErrorMessage')")) {
    failures.push('public/index.html must use system-static.js helpers for knowledge import request body and messages.');
  }
  if (!content.includes("requireSystemStatic('createHotelForm')")
    || !content.includes("requireSystemStatic('buildHotelSavePayload')")
    || !content.includes('hotelForm.value = createHotelForm({ hotel, operatorName, parsedDescription });')
    || !content.includes('const payload = buildHotelSavePayload({')) {
    failures.push('public/index.html must use system-static.js helpers for hotel admin forms and save payloads.');
  }
  if (!content.includes("requireAppSystemStatic('getRememberedLoginAccount')")
    || !content.includes("requireAppSystemStatic('buildLoginRequestPayload')")
    || !content.includes("requireAppSystemStatic('validateLoginRequestPayload')")
    || !content.includes("requireAppSystemStatic('applyRememberedLoginAccount')")
    || !content.includes('const rememberedLogin = getRememberedLoginAccount(localStorage);')
    || !content.includes('const loginForm = ref(rememberedLogin.form);')
    || !content.includes('const payload = buildLoginRequestPayload(loginForm.value);')
    || !content.includes('const validationError = validateLoginRequestPayload(payload);')
    || !content.includes('applyRememberedLoginAccount({')) {
    failures.push('public/index.html must use system-static.js helpers for login form defaults, payloads, validation, and remembered-account storage.');
  }
  if (!content.includes("requireAppSystemStatic('createRegisterForm')")
    || !content.includes("requireAppSystemStatic('buildRegisterRequestPayload')")
    || !content.includes("requireAppSystemStatic('validateRegisterRequestPayload')")
    || !content.includes('const registerForm = ref(createRegisterForm());')
    || !content.includes('const payload = buildRegisterRequestPayload(registerForm.value);')
    || !content.includes('const validationError = validateRegisterRequestPayload(payload);')) {
    failures.push('public/index.html must use system-static.js helpers for self-registration form defaults, payloads, and validation.');
  }
  if (!systemStaticContent.includes('const createHotelForm = ({ hotel = null, operatorName = \'\', code = \'\', parsedDescription = {} } = {}) =>')
    || !systemStaticContent.includes('const buildHotelSavePayload = ({ form = {}, normalizedCode = \'\', operatorName = \'\', description = \'\' } = {}) => ({')) {
    failures.push('public/system-static.js must own hotel admin form defaults and save payload normalization.');
  }
  if (!systemStaticContent.includes('const createLoginForm = ({ username = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const getRememberedLoginAccount = (storage) => {')
    || !systemStaticContent.includes('const buildLoginRequestPayload = (form = {}) => ({')
    || !systemStaticContent.includes('const validateLoginRequestPayload = (payload = {}) => (')
    || !systemStaticContent.includes('const applyRememberedLoginAccount = ({ storage, username = \'\', remember = false } = {}) => {')) {
    failures.push('public/system-static.js must own login form defaults, payload normalization, validation, and remembered-account storage policy.');
  }
  if (!systemStaticContent.includes('const createRegisterForm = () => ({')
    || !systemStaticContent.includes('const buildRegisterRequestPayload = (form = {}) => ({')
    || !systemStaticContent.includes('const validateRegisterRequestPayload = (payload = {}) => {')) {
    failures.push('public/system-static.js must own self-registration form defaults, payload normalization, and validation.');
  }
  if (content.includes("hotelForm.value = { id: null, name: '', code: getNextHotelCode()")
    || content.includes('name: hotelForm.value.name.trim(),\n                    code: normalizedCode,')) {
    failures.push('public/index.html must not re-inline hotel admin form defaults or save payload normalization.');
  }
  if (content.includes("localStorage.getItem('remembered_username')")
    || content.includes("localStorage.setItem('remembered_username'")
    || content.includes("body: JSON.stringify({\n                            username: loginForm.value.username")) {
    failures.push('public/index.html must not re-inline login remembered-account storage or login payload normalization.');
  }
  if (content.includes("const username = String(registerForm.value.username || '').trim();")
    || content.includes("body: JSON.stringify({\n                            username,")) {
    failures.push('public/index.html must not re-inline self-registration payload normalization.');
  }
  if (content.includes('successCount = Number(res.data?.success_count')
    || content.includes("error.name === 'AbortError'")
    || content.includes('body: JSON.stringify({\n                            mode,\n                            source: form.source || mode,')) {
    failures.push('public/index.html must not re-inline knowledge import payload or success/timeout message formatting.');
  }
  const autoFetchPanelLoader = content.slice(
    content.indexOf('const loadAutoFetchPanel = async'),
    content.indexOf('const loadAutoFetchStatus = async')
  );
  if (!/await loadAutoFetchStatus\(\{\s*detail:\s*false\s*\}\);[\s\S]*scheduleAutoFetchStatusDetailRefresh\(\);[\s\S]*schedulePlatformProfileStatusRefresh\(\{ silent: true \}\);/.test(autoFetchPanelLoader)
    || /await Promise\.all\(\[[\s\S]*loadAutoFetchStatus\(\)[\s\S]*loadPlatformProfileStatus/.test(autoFetchPanelLoader)) {
    failures.push('public/index.html must let platform-auto first paint wait only for light auto-fetch status and defer detail/profile refresh.');
  }
  if (!content.includes('const scheduleAutoFetchConfigListPrewarm = () => {')
    || !content.includes('!ctripConfigListLoaded.value && (!ctripConfigList.value || ctripConfigList.value.length === 0)')
    || !content.includes('!meituanConfigListLoaded.value && (!meituanConfigList.value || meituanConfigList.value.length === 0)')
    || !autoFetchPanelLoader.includes('scheduleAutoFetchConfigListPrewarm();')) {
    failures.push('public/index.html must prewarm saved Ctrip/Meituan config lists after platform-auto first paint instead of blocking it.');
  }
  if (/await Promise\.all\(\[[\s\S]*loadCtripConfigList\(\)[\s\S]*loadMeituanConfigList\(\)[\s\S]*\]\);[\s\S]*await loadAutoFetchStatus\(\{\s*detail:\s*false\s*\}\);/.test(autoFetchPanelLoader)) {
    failures.push('public/index.html platform-auto first paint must not synchronously wait for saved Ctrip/Meituan config-list loads before light status.');
  }
  if (!content.includes('const autoFetchPlatformConfigState = (configured, configName, loading, loaded, failed) => {')
    || !content.includes("configName: '配置待读取'")
    || !content.includes("configName: '配置读取失败'")
    || !content.includes('const buildCtripAutoFetchPlatformCard = (status, configured, configState) => ({')
    || !content.includes('const buildMeituanAutoFetchPlatformCard = (status, configured, configState) => ({')
    || !content.includes('const ctripConfigListLoaded = ref(false);')
    || !content.includes('const meituanConfigListLoaded = ref(false);')) {
    failures.push('public/index.html must keep unloaded/failed platform config-list states explicit after platform-auto prewarm is deferred.');
  }
  if (!content.includes('const autoFetchStatusRequestPromises = new Map();')
    || !content.includes("const requestKey = `${String(hotelId || '')}|${includeDetail ? 'full' : 'light'}`;")
    || !content.includes('if (autoFetchStatusRequestPromises.has(requestKey))')
    || !content.includes('autoFetchStatusRequestPromises.delete(requestKey);')) {
    failures.push('public/index.html must deduplicate concurrent auto-fetch status requests by hotel and detail level.');
  }
  if (!content.includes("const scheduleAutoFetchStatusRefresh = () => schedulePostFetchRefresh('auto-fetch-status', () => loadAutoFetchStatus({ detail: false }), 180);")) {
    failures.push('public/index.html must use light auto-fetch status for post-fetch status refreshes.');
  }
  const dataHealthPanelSource = content.slice(
    content.indexOf('const buildDataHealthPanelJobs = (normalizedMode) =>'),
    content.indexOf('const triggerAutoFetch = async')
  );
  if (!dataHealthPanelSource.includes('const buildDataHealthPanelJobs = (normalizedMode) => {')
    || !dataHealthPanelSource.includes("loadAutoFetchStatus({ detail: normalizedMode === 'full' })")
    || !dataHealthPanelSource.includes('loadCookieStatus()')
    || !dataHealthPanelSource.includes('loadCollectionReliability(normalizedMode)')
    || !dataHealthPanelSource.includes('loadDataHealthOperationLogs()')
    || !dataHealthPanelSource.includes('loadPublicEndpointSecurity()')
    || !dataHealthPanelSource.includes('loadHotelDataDashboard()')
    || !dataHealthPanelSource.includes('const scheduleDataHealthLightDiagnostics = () => {')
    || !dataHealthPanelSource.includes('const jobs = buildDataHealthPanelJobs(normalizedMode);')
    || !dataHealthPanelSource.includes('scheduleDataHealthLightDiagnostics();')) {
    failures.push('public/index.html must keep data-health panel job composition and deferred light diagnostics out of loadDataHealthPanel.');
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
  if (!/const\s+buildAutoFetchTriggerRequestBody[\s\S]*async:\s*true/.test(autoFetchStaticContent)) {
    failures.push('public/auto-fetch-static.js must submit platform auto-fetch triggers with async: true so the UI is not blocked by OTA collection.');
  }
  if (!/return\s+\{\s*status:\s*['"]accepted['"]/.test(autoFetchStaticContent)
    || !/runPostFetchRefresh\(loadAutoFetchStatus\)/.test(autoFetchStaticContent)) {
    failures.push('public/auto-fetch-static.js must treat backend running/queued auto-fetch responses as accepted and refresh status without blocking.');
  }
  const retryAutoFetchStart = content.indexOf('const retryAutoFetchDate = async');
  const retryAutoFetchEnd = content.indexOf('const loadBookmarklet = async', retryAutoFetchStart);
  const retryAutoFetchSource = retryAutoFetchStart >= 0 && retryAutoFetchEnd > retryAutoFetchStart
    ? content.slice(retryAutoFetchStart, retryAutoFetchEnd)
    : '';
  if (!/\/online-data\/retry-auto-fetch/.test(retryAutoFetchSource)
    || !/async:\s*true/.test(retryAutoFetchSource)
    || !/\['running', 'queued', 'accepted'\]\.includes\(retryStatus\)/.test(retryAutoFetchSource)) {
    failures.push('public/index.html must submit retry auto-fetch in background mode and treat running responses as accepted.');
  }
  if (!/\{\s*\.\.\.requestContext\.requestBody,\s*async:\s*true\s*\}/.test(ctripStaticContent)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody/.test(ctripStaticContent)) {
    failures.push('public/ctrip-static.js must submit Ctrip manual fetch in background mode and treat running responses as accepted.');
  }
  const ctripAcceptedHelperMatches = ctripStaticContent.match(/const\s+isCtripBackgroundAcceptedResponse\s*=/g) || [];
  if (ctripAcceptedHelperMatches.length !== 1) {
    failures.push('public/ctrip-static.js must define one shared Ctrip accepted/running/queued response helper.');
  }
  const trafficFlowStart = ctripStaticContent.indexOf('const runCtripTrafficFetchFlow = async');
  const adsFlowStart = ctripStaticContent.indexOf('const runCtripAdsFetchFlow = async');
  const ctripTrafficFlowSource = trafficFlowStart >= 0 && adsFlowStart > trafficFlowStart
    ? ctripStaticContent.slice(trafficFlowStart, adsFlowStart)
    : '';
  const ctripAdsFlowSource = adsFlowStart >= 0
    ? ctripStaticContent.slice(adsFlowStart, ctripStaticContent.indexOf('const createCtripAdsState', adsFlowStart) > adsFlowStart
      ? ctripStaticContent.indexOf('const createCtripAdsState', adsFlowStart)
      : ctripStaticContent.length)
    : '';
  if (!/const\s+queuedRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*true\s*\};/.test(ctripTrafficFlowSource)
    || !/isCtripBackgroundAcceptedResponse\(res\)/.test(ctripTrafficFlowSource)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*queuedRequestBody/.test(ctripTrafficFlowSource)) {
    failures.push('public/ctrip-static.js must submit Ctrip traffic manual fetch in background mode and keep running responses visible.');
  }
  if (!/const\s+queuedRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*true\s*\};/.test(ctripAdsFlowSource)
    || !/isCtripBackgroundAcceptedResponse\(res\)/.test(ctripAdsFlowSource)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*queuedRequestBody/.test(ctripAdsFlowSource)) {
    failures.push('public/ctrip-static.js must submit Ctrip ads manual fetch in background mode and keep running responses visible.');
  }
  if (!/\{\s*\.\.\.task\.body,\s*async:\s*true\s*\}/.test(meituanStaticContent)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*acceptedCount/.test(meituanStaticContent)) {
    failures.push('public/meituan-static.js must submit Meituan manual batch fetch in background mode and treat running responses as accepted.');
  }
  const meituanAcceptedHelperMatches = meituanStaticContent.match(/const\s+isMeituanBackgroundAcceptedResponse\s*=/g) || [];
  if (meituanAcceptedHelperMatches.length !== 1) {
    failures.push('public/meituan-static.js must define one shared Meituan accepted/running/queued response helper.');
  }
  const meituanTrafficFlowStart = meituanStaticContent.indexOf('const runMeituanTrafficFetchFlow = async');
  const meituanOrderFlowStart = meituanStaticContent.indexOf('const runMeituanOrderFetchFlow = async');
  const meituanAdsFlowStart = meituanStaticContent.indexOf('const runMeituanAdsFetchFlow = async');
  const meituanBatchFlowStart = meituanStaticContent.indexOf('const runMeituanBatchFetchFlow = async');
  const meituanTrafficFlowSource = meituanTrafficFlowStart >= 0 && meituanOrderFlowStart > meituanTrafficFlowStart
    ? meituanStaticContent.slice(meituanTrafficFlowStart, meituanOrderFlowStart)
    : '';
  const meituanOrderFlowSource = meituanOrderFlowStart >= 0 && meituanAdsFlowStart > meituanOrderFlowStart
    ? meituanStaticContent.slice(meituanOrderFlowStart, meituanAdsFlowStart)
    : '';
  const meituanAdsFlowSource = meituanAdsFlowStart >= 0 && meituanBatchFlowStart > meituanAdsFlowStart
    ? meituanStaticContent.slice(meituanAdsFlowStart, meituanBatchFlowStart)
    : '';
  for (const [source, label] of [
    [meituanTrafficFlowSource, 'traffic'],
    [meituanOrderFlowSource, 'order'],
    [meituanAdsFlowSource, 'ads'],
  ]) {
    if (!/const\s+queuedRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*true\s*\};/.test(source)
      || !/isMeituanBackgroundAcceptedResponse\(res\)/.test(source)
      || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*queuedRequestBody/.test(source)) {
      failures.push(`public/meituan-static.js must submit Meituan ${label} manual fetch in background mode and keep running responses visible.`);
    }
  }
  const controllerPath = path.join(repoRoot, 'app/controller/OnlineData.php');
  const controllerContent = fs.existsSync(controllerPath) ? fs.readFileSync(controllerPath, 'utf8') : '';
  const manualTaskServicePath = path.join(repoRoot, 'app/service/ManualOnlineFetchTaskService.php');
  const manualTaskServiceContent = fs.existsSync(manualTaskServicePath) ? fs.readFileSync(manualTaskServicePath, 'utf8') : '';
  if (!controllerContent.includes("get('include_detail'") || !controllerContent.includes("'detail_loaded' => false")) {
    failures.push('app/controller/OnlineData.php must support light auto-fetch status with explicit detail_loaded=false.');
  }
  if (!controllerContent.includes("'/api/online-data/retry-auto-fetch'")
    || !controllerContent.includes("'retry_auto_fetch_queued'")
    || !controllerContent.includes("'background_task' => true")) {
    failures.push('app/controller/OnlineData.php must submit retry auto-fetch through the one-shot background worker instead of blocking the request.');
  }
  if (!manualTaskServiceContent.includes('final class ManualOnlineFetchTaskService')
    || !manualTaskServiceContent.includes('online-data:manual-fetch-once')
    || !controllerContent.includes("createTask('ctrip'")
    || !controllerContent.includes("createTask(strtolower($platform) . '_traffic'")
    || !controllerContent.includes("createTask('ctrip_ads'")
    || !controllerContent.includes('launchTask($task)')
    || controllerContent.includes('private function createManualCtripFetchBackgroundTask')
    || controllerContent.includes('private function launchManualCtripFetchBackgroundTask')) {
    failures.push('app/controller/OnlineData.php must use ManualOnlineFetchTaskService for Ctrip manual fetch background task support.');
  }
  if (!controllerContent.includes("createTask('meituan'")
    || !controllerContent.includes("createTask('meituan_traffic'")
    || !controllerContent.includes("createTask('meituan_' . $section")
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
