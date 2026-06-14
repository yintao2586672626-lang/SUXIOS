import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const indexPath = path.join(repoRoot, 'public/index.html');
const publicRouterPath = path.join(repoRoot, 'public/router.php');
const systemStaticPath = path.join(repoRoot, 'public/system-static.js');
const operationStaticPath = path.join(repoRoot, 'public/operation-static.js');
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
  const operationStaticContent = fs.existsSync(operationStaticPath) ? fs.readFileSync(operationStaticPath, 'utf8') : '';
  const ctripStaticPath = path.join(repoRoot, 'public/ctrip-static.js');
  const ctripStaticContent = fs.existsSync(ctripStaticPath) ? fs.readFileSync(ctripStaticPath, 'utf8') : '';
  const meituanStaticPath = path.join(repoRoot, 'public/meituan-static.js');
  const meituanStaticContent = fs.existsSync(meituanStaticPath) ? fs.readFileSync(meituanStaticPath, 'utf8') : '';
  const dataHealthStaticPath = path.join(repoRoot, 'public/data-health-static.js');
  const dataHealthStaticContent = fs.existsSync(dataHealthStaticPath) ? fs.readFileSync(dataHealthStaticPath, 'utf8') : '';
  const platformAutoSettingsPanelsPath = path.join(repoRoot, 'public/components/online-data/platform-auto-settings-panels.js');
  const platformAutoSettingsPanelsContent = fs.existsSync(platformAutoSettingsPanelsPath)
    ? fs.readFileSync(platformAutoSettingsPanelsPath, 'utf8')
    : '';
  const ctripProfileFieldConfigPanelPath = path.join(repoRoot, 'public/components/online-data/ctrip-profile-field-config-panel.js');
  const ctripProfileFieldConfigPanelContent = fs.existsSync(ctripProfileFieldConfigPanelPath)
    ? fs.readFileSync(ctripProfileFieldConfigPanelPath, 'utf8')
    : '';

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

  if (!content.includes('ctrip-static.js?v=20260613-manual-direct-fetch')
    || !content.includes('meituan-static.js?v=20260613-manual-direct-fetch')) {
    failures.push('public/index.html must bump Ctrip/Meituan static helper versions when manual tab/performance exports change.');
  }
  if (!content.includes("const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260613-platform-auto-lazy';")
    || !content.includes("const PlatformAutoSettingsPanels = {")
    || !content.includes("const PlatformAutoSecondaryPanels = {")
    || !content.includes('const ensurePlatformAutoPanelsReady = async () => {')
    || !content.includes("requireOnlineDataComponent('PlatformAutoSettingsPanelsBody')")
    || !content.includes("requireOnlineDataComponent('PlatformAutoSecondaryPanelsBody')")
    || !content.includes('platformAutoSettingsPanelsBody')
    || !content.includes('platformAutoSecondaryPanelsBody')
    || !platformAutoSettingsPanelsContent.includes('components.PlatformAutoSettingsPanelsBody')
    || !platformAutoSettingsPanelsContent.includes('components.PlatformAutoSecondaryPanelsBody')
    || content.includes('<script src="components/online-data/platform-auto-settings-panels.js')) {
    failures.push('public/index.html must lazy-load the platform-auto extension panels instead of loading them before Vue mount.');
  }
  if (!content.includes('components/online-data/ctrip-profile-field-config-panel.js?v=20260613-profile-template-split')
    || !content.includes("const CtripProfileFieldConfigPanel = {")
    || !content.includes('const ensureCtripProfileFieldConfigPanelReady = async () => {')
    || !content.includes("requireOnlineDataComponent('CtripProfileFieldConfigPanelBody')")
    || !content.includes('void ensureCtripProfileFieldConfigPanelReady().catch')
    || !content.includes('<ctrip-profile-field-config-panel')
    || !content.includes('data-testid="ctrip-profile-field-config-loading"')
    || !ctripProfileFieldConfigPanelContent.includes('components.CtripProfileFieldConfigPanelBody')
    || !/data-testid=\\?"ctrip-profile-field-config-panel\\?"/.test(ctripProfileFieldConfigPanelContent)
    || !ctripProfileFieldConfigPanelContent.includes('return new Proxy({}, {')
    || !ctripProfileFieldConfigPanelContent.includes('return props.ctx?.[key] ?? target[key];')
    || !ctripProfileFieldConfigPanelContent.includes('props.ctx[key] = value;')
    || !ctripProfileFieldConfigPanelContent.includes('getOwnPropertyDescriptor() {')
    || content.includes('携程登录会话字段配置')) {
    failures.push('public/index.html must lazy-load the admin-only Ctrip profile-field config panel from public/components/online-data/ctrip-profile-field-config-panel.js.');
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
  const loginBgPreloadOffset = content.indexOf("const loginBackgroundPreload = 'images/login-hotel-lobby-bg.avif';");
  if (/<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']\s+as=["']image["']\s+type=["']image\/avif["']\s+fetchpriority=["']high["']/.test(content)) {
    failures.push('public/index.html must not statically preload the login background for cached-auth users.');
  }
  if (!content.includes("const shouldPreloadLoginBackground = () => {")
    || !content.includes("return !localStorage.getItem('token') || !localStorage.getItem('suxios_auth_user_cache_v1');")
    || !content.includes("link.setAttribute('fetchpriority', 'high');")
    || !content.includes("link.dataset.suxiLoginBgPreload = '1';")
    || !content.includes('preloadLoginBackground();')) {
    failures.push('public/index.html must conditionally preload the optimized AVIF login background only when the login shell can be shown.');
  }
  if (loginBgPreloadOffset < 0 || tailwindOffset < 0 || loginBgPreloadOffset > tailwindOffset) {
    failures.push('public/index.html must evaluate login background preload before core stylesheets.');
  }
  if (!content.includes('const normalizePermissionMap = (permissions = null) => {')
    || !content.includes('if (Array.isArray(permissions)) {')
    || !content.includes('if (key) acc[String(key)] = true;')
    || !content.includes('const permissions = normalizePermissionMap(profile.permissions);')) {
    failures.push('public/index.html must normalize cached permission arrays before first-paint menu filtering.');
  }
  if (!systemStaticContent.includes('const hasPermission = (permissions, key) => {')
    || !systemStaticContent.includes('if (Array.isArray(permissions)) return permissions.includes(key);')
    || !systemStaticContent.includes('return item.permissions.some(p => hasPermission(perms, p));')) {
    failures.push('public/system-static.js must keep visible menu filtering compatible with array and object permission payloads.');
  }
  if (/<link\s+href=["']font-awesome\.min\.css["']\s+rel=["']stylesheet["']/.test(content)
    || !content.includes("const fontAwesomeStylesheet = 'font-awesome.min.css';")
    || !content.includes("link.dataset.suxiFontawesome = '1';")
    || !content.includes('window.setTimeout(loadFontAwesomeStylesheet, 1600);')) {
    failures.push('public/index.html must idle-load FontAwesome so icon fonts do not compete with core OTA first-second rendering.');
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
  if (/<script\s+src=["']ota-diagnosis-static\.js/.test(content)) {
    failures.push('public/index.html must lazy-load ota-diagnosis-static.js; the login, home, and online-data shell do not need OTA diagnosis helpers.');
  }
  if (!/const\s+otaDiagnosisStaticScript\s*=\s*["']ota-diagnosis-static\.js/.test(content)
    || !/const\s+loadOtaDiagnosisStatic\s*=\s*\(\)\s*=>/.test(content)
    || !/const\s+ensureOtaDiagnosisStaticReady\s*=\s*async\s*\(\)\s*=>/.test(content)
    || !/runPageLoadOnce\(newPage,\s*['"]ota-diagnosis-static['"][\s\S]*ensureOtaDiagnosisStaticReady\(\)/.test(content)
    || !/const generateOtaDiagnosis = async \(\) => \{[\s\S]*await getOtaDiagnosisGenerateFlow\(\);/.test(content)) {
    failures.push('public/index.html must keep OTA diagnosis helpers off the initial shell and load them before diagnosis generation.');
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
  if (!/const prewarmAutoFetchStaticForPlatformAuto = \(\) => \{[\s\S]*if \(!isVisibleOnlineDataTab\(['"]platform-auto['"]\)\) return null;[\s\S]*const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{[\s\S]*void staticReadyPromise;/.test(content)
    || /const loadAutoFetchPanel = async[\s\S]*const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/.test(content)
    || /const loadAutoFetchPanel = async[\s\S]*Promise\.all\(\[[\s\S]*staticReadyPromise/.test(content)
    || !/const triggerAutoFetch = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*requireAutoFetchStatic\(['"]runAutoFetchTriggerFlow['"]\)/.test(content)) {
    failures.push('public/index.html must delay auto-fetch-static.js prewarm beyond platform-auto first paint, and load it before triggering manual auto-fetch.');
  }
  if (!content.includes('const autoFetchConfigProofPendingForHotelId = (hotelId) => {')
    || !content.includes('autoFetchStatusRequestPromises.has(`${keyPrefix}light`)')
    || !content.includes('autoFetchStatusRequestPromises.has(`${keyPrefix}full`)')
    || !content.includes('const canTriggerAutoFetchByHotelId = (hotelId) => {')
    || !content.includes('hasAnyPlatformFetchConfigByHotelId(hotelId) || autoFetchConfigProofPendingForHotelId(hotelId)')
    || !content.includes('hasPlatformFetchConfig: canTriggerAutoFetchByHotelId,')
    || (content.match(/:disabled="fetchingData \|\| !canTriggerAutoFetchByHotelId\(autoFetchHotelId\)"/g) || []).length < 2) {
    failures.push('public/index.html must let platform-auto immediate collection stay clickable while light config proof is pending, without relaxing settings/backfill controls.');
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
  if (!content.includes('const SAVED_OTA_DATA_CONFIG_CACHE_TTL_MS = 30000;')
    || !content.includes('const savedOtaDataConfigCache = new Map();')
    || !content.includes('const savedOtaDataConfigLoadingPromises = new Map();')
    || !/const readSavedOtaDataConfigFromSystem = async \(type\) => \{[\s\S]*savedOtaDataConfigCache\.get\(configKey\)[\s\S]*savedOtaDataConfigLoadingPromises\.has\(configKey\)[\s\S]*request\(`\/system-config\?key=\$\{configKey\}`\)[\s\S]*savedOtaDataConfigCache\.set\(configKey/.test(content)
    || !content.includes('const loadSavedDataConfigByType = async (type) => {\n                return await readSavedOtaDataConfigFromSystem(type);\n            };')
    || !content.includes('clearSavedOtaDataConfigCache(currentDataConfigType.value);')) {
    failures.push('public/index.html saved OTA data-source config reads must be short-cached, deduplicated, and invalidated after saves so manual tab switching does not repeat system-config reads.');
  }
  if (!content.includes('const CTRIP_PROFILE_FIELDS_TAB_CACHE_TTL_MS = 30000;')
    || !content.includes('const ctripProfileFieldResultCache = new Map();')
    || !content.includes('const ctripProfileFieldRequestPromises = new Map();')
    || !/const requestCtripProfileFields = async \(includeSamples, options = \{\}\) => \{[\s\S]*const cached = readCtripProfileFieldCache\(key\)[\s\S]*ctripProfileFieldRequestPromises\.has\(key\)[\s\S]*request\(`\/online-data\/ctrip-profile-fields\?include_samples=\$\{includeSamples \? 1 : 0\}`\)[\s\S]*writeCtripProfileFieldCache\(key, res\.data \|\| \{\}\)/.test(content)
    || !content.includes('void ensureCtripProfileFieldConfigPanelReady().catch')
    || !content.includes('return runIfCurrent(() => loadCtripProfileFields(options));')
    || !content.includes('const loadCtripProfileFields = async (options = {}) => {')
    || !content.includes('const res = await requestCtripProfileFields(false, { force });')
    || !content.includes('const res = await requestCtripProfileFields(true, { force: options.force === true });')
    || !content.includes('clearCtripProfileFieldCache();\n                        await loadCtripProfileFields({ force: true });')
    || !content.includes('clearCtripProfileFieldCache();\n                        mergeCtripProfileFieldUpdate(res.data || {});')) {
    failures.push('public/index.html Ctrip profile-field config reads must be short-cached, deduplicated, force-refreshable, and invalidated after field mutations.');
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
  const compassPageGuardCount = (content.match(/if \(!token\.value \|\| currentPage\.value !== ['"]compass['"]\) return;/g) || []).length;
  if (compassPageGuardCount < 2
    || !content.includes("if (!token.value || currentPage.value !== 'compass' || macroSignalLoading.value) return;")
    || !content.includes("if (options.requireCompass === true && currentPage.value !== 'compass') return;")
    || !content.includes("if (currentPage.value !== 'compass') return null;")
    || !content.includes('loadCompetitorSummary({ requireCompass: true })')) {
    failures.push('public/index.html compass background refreshes must stop after the user leaves the compass page.');
  }
  if (!/const ensureManualOnlineFetchConfigReady = async[\s\S]*loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\)[\s\S]*loadMeituanConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\)/.test(content)) {
    failures.push('public/index.html must keep a lightweight cached manual-fetch config prewarm that loads saved Ctrip/Meituan config lists without opening the full platform-auto panel.');
  }
  if (!content.includes('const MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS = 15000;')
    || !/loadConfigList: \(\) => loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\)/.test(content)
    || !/loadConfigList: \(\) => loadMeituanConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\)/.test(content)
    || !content.includes('let ctripConfigListLoadedAt = 0;')
    || !content.includes('let meituanConfigListLoadedAt = 0;')) {
    failures.push('public/index.html manual fetch tab switches must reuse recently loaded Ctrip/Meituan config lists without changing default full refresh behavior.');
  }
  if (!/let ctripConfigListLoadingPromise = null;[\s\S]*const loadCtripConfigList = async[\s\S]*if \(ctripConfigListLoadingPromise\) \{[\s\S]*return ctripConfigListLoadingPromise;[\s\S]*finally \{[\s\S]*ctripConfigListLoadingPromise = null;/.test(content)) {
    failures.push('public/index.html must deduplicate concurrent Ctrip config-list loads for manual-fetch prewarm and tab switching.');
  }
  if (!content.includes(':disabled="fetchingData || !canFetchCtripManualData()"')
    || !content.includes('const ctripManualFetchConfigProofPending = () => {')
    || !content.includes('return !!ctripConfigListLoadingPromise')
    || !content.includes('const canFetchCtripManualData = () => {')
    || !content.includes('const resolveCtripManualFetchConfig = async (config) => {')
    || !content.includes('ensureCtripConfigSecret: async config => ensureCtripConfigSecret(await resolveCtripManualFetchConfig(config))')) {
    failures.push('public/index.html Ctrip ranking/traffic manual fetch must stay clickable while pending config proof is loading and reuse the same config-list request before backend submission.');
  }
  if (!/const ctripConfigDetailCache = new Map\(\);[\s\S]*const ctripConfigDetailLoadingPromises = new Map\(\);[\s\S]*const loadCtripConfigDetail = async[\s\S]*if \(ctripConfigDetailLoadingPromises\.has\(cacheKey\)\)[\s\S]*return ctripConfigDetailLoadingPromises\.get\(cacheKey\);[\s\S]*const ensureCtripConfigSecret = async[\s\S]*const cached = cacheKey \? ctripConfigDetailCache\.get\(cacheKey\) : null;/.test(content)) {
    failures.push('public/index.html must cache and deduplicate full Ctrip config detail loads for manual-fetch hotel switching.');
  }
  if (!content.includes('const ensureCtripConfigSecret = async (config, options = {}) => {')
    || !content.includes("console.error('[CTrip] 预热完整配置失败:', e);")
    || !content.includes('const prewarmSelectedCtripConfigSecret = (config = findCtripConfigByHotelId(selectedCtripHotelId.value)) => {')
    || !content.includes('deferUiTask(() => ensureCtripConfigSecret(config, { silent: true }), 80);')) {
    failures.push('public/index.html must support silent, deferred Ctrip full-config prewarm for manual-fetch responsiveness.');
  }
  if (!content.includes('const scheduleCtripHotelConfigApply = (event = null, options = {}) => {')
    || !content.includes('const applyVersion = ++ctripHotelConfigApplyVersion;')
    || !content.includes('const config = await ensureCtripConfigSecret(configSource, { silent: true });')
    || !content.includes('isCtripRankingFormAlignedWithConfig(ctripForm.value, config, { selectedHotelId: requestedHotelId })')
    || !content.includes('@change="scheduleCtripHotelConfigApply"')
    || content.includes('@change="applyCtripHotelConfig"')) {
    failures.push('public/index.html Ctrip hotel selection must defer full config detail loading and skip redundant form application when already aligned.');
  }
  if (!content.includes("clearCtripConfigDetailCache(body?.id || '');")
    || !content.includes("clearCtripConfigDetailCache(ctrip.id || existing?.id || '');")
    || !/deleteCtripConfig = async[\s\S]*clearCtripConfigDetailCache\(id\);/.test(content)
    || !/batchDeleteCtripConfigs = async[\s\S]*clearCtripConfigDetailCache\(id\);/.test(content)) {
    failures.push('public/index.html must invalidate cached full Ctrip config details after Ctrip config saves or deletes.');
  }
  const batchDeleteCtripConfigsSource = content.slice(
    content.indexOf('const batchDeleteCtripConfigs = async'),
    content.indexOf('const generateCtripBookmarklet = async')
  );
  if (!batchDeleteCtripConfigsSource.includes('const results = await Promise.all(ids.map(async (id) => {')
    || !batchDeleteCtripConfigsSource.includes('const failedIds = results.filter(item => !item.success).map(item => item.id);')
    || !batchDeleteCtripConfigsSource.includes('deferUiTask(() => loadCtripConfigList(), 80);')
    || batchDeleteCtripConfigsSource.includes('await loadCtripConfigList();')) {
    failures.push('public/index.html Ctrip batch config delete must run delete requests in parallel and refresh the config list after feedback is released.');
  }
  const onlineDataTabSchedulerStart = content.indexOf('const scheduleOnlineDataTabLoad = (newTab, options = {}) => {');
  const onlineDataTabSchedulerEnd = content.indexOf('const openOnlineDataTab =', onlineDataTabSchedulerStart);
  const onlineDataTabSchedulerSource = onlineDataTabSchedulerStart >= 0 && onlineDataTabSchedulerEnd > onlineDataTabSchedulerStart
    ? content.slice(onlineDataTabSchedulerStart, onlineDataTabSchedulerEnd)
    : '';
  const manualOnlineDataConfigPrewarmStart = content.indexOf('const MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS = 60;');
  const manualOnlineDataConfigPrewarmEnd = content.indexOf('let suppressNextOnlineDataTabWatcherLoad = false;', manualOnlineDataConfigPrewarmStart);
  const manualOnlineDataConfigPrewarmSource = manualOnlineDataConfigPrewarmStart >= 0 && manualOnlineDataConfigPrewarmEnd > manualOnlineDataConfigPrewarmStart
    ? content.slice(manualOnlineDataConfigPrewarmStart, manualOnlineDataConfigPrewarmEnd)
    : '';
  const onlineDataTabWatchSource = content.slice(
    content.indexOf('watch(onlineDataTab'),
    content.indexOf('watch(() => meituanForm.value.hotelId')
  );
  const onlineDataDataTabStart = onlineDataTabSchedulerSource.indexOf("if (newTab === 'data') {");
  const onlineDataManualPrewarmStart = onlineDataTabSchedulerSource.indexOf('if (shouldPrewarmManualConfig) {', onlineDataDataTabStart);
  const onlineDataDataTabSource = onlineDataDataTabStart >= 0 && onlineDataManualPrewarmStart > onlineDataDataTabStart
    ? onlineDataTabSchedulerSource.slice(onlineDataDataTabStart, onlineDataManualPrewarmStart)
    : '';
  if (!manualOnlineDataConfigPrewarmSource.includes('const MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS = 60;')
    || !manualOnlineDataConfigPrewarmSource.includes("const MANUAL_ONLINE_FETCH_CONFIG_TABS = new Set(['ctrip', 'meituan', 'custom']);")
    || !manualOnlineDataConfigPrewarmSource.includes("const shouldPrewarmManualOnlineFetchConfig = (newTab) => MANUAL_ONLINE_FETCH_CONFIG_TABS.has(String(newTab || ''));")
    || !manualOnlineDataConfigPrewarmSource.includes('const clearManualOnlineFetchConfigPrewarmTimer = () => {')
    || !manualOnlineDataConfigPrewarmSource.includes('const scheduleManualOnlineFetchConfigPrewarm = (newTab, delayMs = MANUAL_ONLINE_DATA_CONFIG_PREWARM_DELAY_MS) => {')
    || !manualOnlineDataConfigPrewarmSource.includes('if (!isVisibleOnlineDataTab(newTab)) return;')
    || !manualOnlineDataConfigPrewarmSource.includes('ensureManualOnlineFetchConfigReady();')
    || !onlineDataTabSchedulerSource.includes('const shouldPrewarmManualConfig = shouldPrewarmManualOnlineFetchConfig(newTab);')
    || !/if \(!shouldPrewarmManualConfig\) \{\s*clearManualOnlineFetchConfigPrewarmTimer\(\);\s*\}/.test(onlineDataTabSchedulerSource)
    || !/newTab === ['"]data['"][\s\S]*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);[\s\S]*return undefined;/.test(onlineDataTabSchedulerSource)
    || onlineDataDataTabSource.includes('scheduleManualOnlineFetchConfigPrewarm')
    || !/if \(shouldPrewarmManualConfig\) \{\s*scheduleManualOnlineFetchConfigPrewarm\(newTab, options\.configPrewarmDelayMs\);\s*return undefined;\s*\}/.test(onlineDataTabSchedulerSource)
    || /ensureManualOnlineFetchConfigReady\(\);\s*refreshOnlineData\(\{ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS \}\);/.test(onlineDataTabSchedulerSource)
    || !/item\.path === ['"]online-data['"][\s\S]*openOnlineDataTab\(targetTab\)/.test(content)) {
    failures.push('public/index.html must keep saved platform config prewarm off the online-data data-records first paint and reserve it for manual fetch tabs.');
  }
  if (!content.includes("let pendingOnlineDataEntryTab = '';")
    || !content.includes("pendingOnlineDataEntryTab = String(item.tab || '');")
    || !content.includes("if (requestedOnlineDataTab && requestedOnlineDataTab !== 'data-health') {\n                        return;\n                    }")) {
    failures.push('public/index.html must skip default data-health first-paint loading when menu navigation targets another online-data tab.');
  }
  if (!content.includes("const openOnlineDataEntryTab = (tab = 'data-health', options = {}) => {\n                const targetTab = String(tab || 'data-health');")
    || !content.includes("clearDataHealthSecondaryPanelsReadyTimer();\n                dataHealthSecondaryPanelsReady.value = false;\n                clearDataHealthDetailPanelsReadyTimer();\n                dataHealthDetailPanelsReady.value = false;\n                clearDataHealthEmployeePanelsReadyTimer();\n                dataHealthEmployeePanelsReady.value = false;\n                clearPlatformAutoSettingsPanelsReadyTimer();\n                platformAutoSettingsPanelsReady.value = false;\n                clearPlatformAutoSecondaryPanelsReadyTimer();\n                platformAutoSecondaryPanelsReady.value = false;")
    || !content.includes("if (targetTab !== 'data-health') {\n                    pendingOnlineDataEntryTab = targetTab;\n                }")
    || !content.includes("onlineDataTab.value = targetTab;\n                currentPage.value = 'online-data';")
    || !content.includes("const openOnlinePlatformAutoTab = (options = {}) => {\n                return openOnlineDataEntryTab('platform-auto', options);\n            };")
    || !content.includes("const openOnlineDataManualEntry = () => {\n                return openOnlineDataEntryTab('data-health');\n            };")
    || !content.includes("if (item.path === 'online-data' && !item.tab) {\n                    openOnlineDataManualEntry();\n                    return;\n                }")) {
    failures.push('public/index.html online-data menu clicks without an explicit tab must return to the default data-health tab.');
  }
  if (!content.includes('@click="handleParentMenuClick(item)"')
    || !content.includes("const handleParentMenuClick = (item) => {\n                const menuName = item?.name || getMenuItemName(item);\n                toggleSubmenu(menuName);\n            };")
    || !content.includes('expandedMenus, toggleSubmenu, handleParentMenuClick,')) {
    failures.push('public/index.html parent online-data menu clicks must only toggle the submenu and must not load the data-health panel.');
  }
  if (content.includes("if (menuName === '线上数据手动获取') {\n                    openOnlineDataManualEntry();\n                }")) {
    failures.push('public/index.html parent online-data menu clicks must not trigger the default data-health load before the user chooses a manual platform.');
  }
  if (!content.includes("if (targetPage === 'online-data') {\n                    openOnlineDataEntryTab(targetTab || 'data-health');")
    || !content.includes("} else if (targetPage === 'ctrip-ebooking') {\n                        scheduleDataHealthPanelRefresh('light');")
    || content.includes("item.target_page === 'online-data' || item.target_page === 'ctrip-ebooking'")
    || content.includes("if (item.target_tab) {\n                    onlineDataTab.value = item.target_tab;\n                }")) {
    failures.push('public/index.html global notifications targeting online-data must use openOnlineDataEntryTab and avoid loading data-health for other target tabs.');
  }
  if (!content.includes("if (entry.page === 'online-data') {\n                    openOnlineDataEntryTab(entry.tab || 'data-health');\n                    return;\n                }")) {
    failures.push('public/index.html home quick entries targeting online-data must use openOnlineDataEntryTab.');
  }
  if (!content.includes('const HOME_SECONDARY_PANEL_DELAY_MS = 4200;')
    || !content.includes('const homeSecondaryPanelsReady = ref(false);')
    || !content.includes('const scheduleHomeSecondaryPanelsReady = (delayMs = HOME_SECONDARY_PANEL_DELAY_MS) => {')
    || !content.includes('clearHomeSecondaryPanelsReadyTimer();\n                    homeSecondaryPanelsReady.value = false;\n                    destroyHomeTrendChart();')
    || !content.includes("homeSecondaryPanelsReady.value = false;\n                    scheduleHomeSecondaryPanelsReady();\n                    runPageLoadOnce(newPage, 'main', () => loadCompassData());")
    || !/<div v-if="homeSecondaryPanelsReady"[^>]*data-testid="daily-ops-monitor-card"/.test(content)
    || !/<div v-if="homeSecondaryPanelsReady"[^>]*data-testid="home-weather-demand-card"/.test(content)
    || !/<div v-if="homeSecondaryPanelsReady"[^>]*data-testid="home-market-signal-card"/.test(content)
    || !content.includes('<div v-if="homeSecondaryPanelsReady && homeTrendCards.length"')
    || !content.includes('homeSecondaryPanelsReady, homeClosedLoopStages')) {
    failures.push('public/index.html must delay mounting lower home dashboard panels so core OTA navigation stays responsive after login.');
  }
  if (content.includes("runPageLoadOnce(newPage, 'auto-fetch-static', () => ensureAutoFetchStaticReady())")
    || content.includes("runPageLoadOnce('compass', 'auto-fetch-static', () => ensureAutoFetchStaticReady(), runOptions)")) {
    failures.push('public/index.html must not prewarm auto-fetch-static.js from the home/compass first paint path.');
  }
  if (!content.includes("@click=\"openOnlineDataTab('data-health')\"")
    || !content.includes("@click=\"openOnlineDataTab('data')\"")
    || content.includes("@click=\"onlineDataTab = 'data-health'; loadDataHealthPanel('light')\"")
    || content.includes("@click=\"onlineDataTab = 'data'; refreshOnlineData()\"")) {
    failures.push('public/index.html online-data tab buttons must switch immediately through openOnlineDataTab instead of loading data inline.');
  }
  if (!content.includes('const openDataHealthDrilldown = (row) => {\n                if (!row?.tab) return;\n                openOnlineDataTab(row.tab);\n            };')
    || content.includes('onlineDataTab.value = row.tab;')
    || content.includes("if (row.tab === 'platform-auto') schedulePlatformAutoFetchPanelLoad();")
    || content.includes("if (row.tab === 'profile-fields') loadCtripProfileFields();")
    || content.includes("if (row.tab === 'data') refreshOnlineData();")) {
    failures.push('public/index.html data-health drilldown must use openOnlineDataTab so tab switches do not double-trigger heavy loaders.');
  }
  if (!onlineDataTabWatchSource.includes('scheduleOnlineDataTabLoad(newTab)')
    || !content.includes("const isVisibleOnlineDataTab = isOnlineDataTabVisible;")
    || !onlineDataTabSchedulerSource.includes('if (!isVisibleOnlineDataTab(newTab)) return null;')
    || !onlineDataTabSchedulerSource.includes('if (!isVisibleOnlineDataTab(newTab)) return;')
    || !onlineDataTabSchedulerSource.includes("scheduleDataHealthPanelRefresh('light', options.force ? { force: true } : {})")
    || onlineDataTabSchedulerSource.includes("return runIfCurrent(() => loadDataHealthPanel('light'));")
    || !onlineDataTabWatchSource.includes("if (currentPage.value !== 'online-data') {")
    || onlineDataTabWatchSource.includes("loadDataHealthPanel('light')")
    || onlineDataTabWatchSource.includes('refreshOnlineAnalysis()')
    || onlineDataTabWatchSource.includes('schedulePlatformAutoFetchPanelLoad()')
    || onlineDataTabWatchSource.includes('loadCtripProfileFields()')) {
    failures.push('public/index.html online-data tab watcher must only delegate visible online-data tab work to the deferred scheduler instead of running panel loads inline.');
  }
  const ctripEbookingDefaultLoader = content.slice(
    content.indexOf("if (newPage === 'ctrip-ebooking')"),
    content.indexOf("if (newPage === 'meituan-ebooking'")
  );
  if (!content.includes('const scheduleCtripEbookingDeferredStartupRefresh = () => {')
    || !ctripEbookingDefaultLoader.includes('scheduleCtripEbookingDeferredStartupRefresh();')) {
    failures.push('public/index.html must defer Ctrip eBooking config/latest/cookie/bookmarklet startup refreshes until after the first paint loader.');
  }
  if (!content.includes('const CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS = 2600;')
    || !content.includes('const CTRIP_EBOOKING_LATEST_DATA_DELAY_MS = 5200;')
    || !content.includes('const CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS = 6400;')
    || !content.includes('const CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS = 7600;')
    || !content.includes('}, CTRIP_EBOOKING_STARTUP_CONFIG_DELAY_MS);\n                scheduleDelayedPageTask(() => {')
    || !content.includes('}, CTRIP_EBOOKING_LATEST_DATA_DELAY_MS);\n                scheduleDelayedPageTask(() => {')
    || !content.includes('}, CTRIP_EBOOKING_COOKIE_STATUS_DELAY_MS);\n                scheduleDelayedPageTask(() => {')
    || !content.includes('}, CTRIP_EBOOKING_BOOKMARKLET_DELAY_MS);')
    || content.includes("prewarmSelectedCtripConfigSecret();\n                    return null;\n                }, 1800);")
    || content.includes("return loadLatestCtripData({ silent: true });\n                }, 2400);")
    || content.includes("return loadCookiesList();\n                }, 3000);")
    || content.includes("return loadBookmarklet();\n                }, 3600);")) {
    failures.push('public/index.html Ctrip eBooking config-list startup refresh must stay responsive and use the explicit short delay constant.');
  }
  if (!/await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);\s*if \(currentPage\.value !== 'ctrip-ebooking'\) return null;\s*prewarmSelectedCtripConfigSecret\(\);/.test(content)
    || !content.includes('const shouldApplySelectedConfig = options.applySelectedConfig === true;')
    || !/if \(selectedCtripHotelId\.value && shouldApplySelectedConfig\) \{[\s\S]*deferUiTask\(\(\) => applyCtripHotelConfig\(false, \{[\s\S]*refreshList: false,[\s\S]*skipIfAligned: true,/.test(content)
    || /if \(selectedCtripHotelId\.value\) \{\s*prewarmSelectedCtripConfigSecret\(\);\s*deferUiTask\(\(\) => applyCtripHotelConfig\(false\), 80\);/.test(content)
    || content.includes("if (selectedCtripHotelId.value) {\n                                await applyCtripHotelConfig(false);\n                            }\n                            return ctripConfigList.value;")) {
    failures.push('public/index.html Ctrip config list must return after list data and only apply selected config when explicitly requested.');
  }
  if (!content.includes('const CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS = 1600;')
    || !ctripEbookingDefaultLoader.includes("scheduleDelayedPageTask(() => {\n                            if (!isCtripEbookingDataHealthVisible()) return null;\n                            scheduleDataHealthPanelRefresh('light');\n                            return null;\n                        }, CTRIP_EBOOKING_DATA_HEALTH_REFRESH_DELAY_MS);")
    || /await loadDataHealthPanel\(['"]light['"]\);/.test(ctripEbookingDefaultLoader)
    || /await Promise\.allSettled\(\[\s*loadOnlineDataHotelList\(\),\s*loadDataHealthPanel\(['"]light['"]\),\s*\]\);/.test(ctripEbookingDefaultLoader)) {
    failures.push('public/index.html Ctrip eBooking first-paint loader must delay light data-health status outside the immediate interaction window and defer hotel-list loading.');
  }
  if (/runPageLoadOnce\(newPage,\s*['"]main['"][\s\S]*Promise\.allSettled\(\[[\s\S]*loadCtripConfigList\(\)[\s\S]*loadCookiesList\(\)[\s\S]*loadBookmarklet\(\)[\s\S]*\]\)/.test(ctripEbookingDefaultLoader)) {
    failures.push('public/index.html Ctrip eBooking default loader must not start config/latest/cookie/bookmarklet refreshes in the first-paint request group.');
  }
  if (!content.includes('const CTRIP_EBOOKING_MODULE_CARD_DELAY_MS = 1000;')
    || !content.includes('const ctripEbookingModuleCardsReady = ref(false);')
    || !content.includes('const scheduleCtripEbookingModuleCardsReady = (delayMs = CTRIP_EBOOKING_MODULE_CARD_DELAY_MS) => {')
    || !content.includes('<div v-if="ctripEbookingModuleCardsReady" class="px-4 py-3 border-b bg-gray-50 grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-2">')
    || !content.includes('<div v-if="ctripEbookingModuleCardsReady" data-testid="ctrip-overview-module-cards" class="p-4">')
    || !ctripEbookingDefaultLoader.includes('ctripEbookingModuleCardsReady.value = false;\n                    scheduleCtripEbookingModuleCardsReady();')
    || !content.includes('const CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS = 4200;')
    || !content.includes('const ctripEbookingSecondaryPanelsReady = ref(false);')
    || !content.includes('const scheduleCtripEbookingSecondaryPanelsReady = (delayMs = CTRIP_EBOOKING_SECONDARY_PANEL_DELAY_MS) => {')
    || !content.includes('<div v-if="ctripEbookingSecondaryPanelsReady" class="space-y-4">')
    || !ctripEbookingDefaultLoader.includes('ctripEbookingSecondaryPanelsReady.value = false;\n                    scheduleCtripEbookingSecondaryPanelsReady();')
    || !content.includes('const CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS = 6200;')
    || !content.includes('const ctripEbookingDeepPanelsReady = ref(false);')
    || !content.includes('const scheduleCtripEbookingDeepPanelsReady = (delayMs = CTRIP_EBOOKING_DEEP_PANEL_DELAY_MS) => {')
    || !content.includes('<div v-if="ctripEbookingDeepPanelsReady" class="space-y-4">')
    || !ctripEbookingDefaultLoader.includes('ctripEbookingDeepPanelsReady.value = false;\n                    scheduleCtripEbookingDeepPanelsReady();')
    || !content.includes('const CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS = 8200;')
    || !content.includes('const ctripEbookingBusinessDetailsReady = ref(false);')
    || !content.includes('const scheduleCtripEbookingBusinessDetailsReady = (delayMs = CTRIP_EBOOKING_BUSINESS_DETAIL_DELAY_MS) => {')
    || !content.includes('<div v-if="ctripEbookingBusinessDetailsReady" data-testid="ctrip-store-overview-business-details" class="space-y-4">')
    || !ctripEbookingDefaultLoader.includes('ctripEbookingBusinessDetailsReady.value = false;\n                    scheduleCtripEbookingBusinessDetailsReady();')
    || !content.includes('const ctripEbookingDiagnosticsPanelsReady = ref(false);')
    || !content.includes('@toggle="handleCtripEbookingDiagnosticsToggle"')
    || !content.includes('<div v-if="ctripEbookingDiagnosticsPanelsReady" class="p-4 border-t space-y-4">')
    || !ctripEbookingDefaultLoader.includes('ctripEbookingDiagnosticsPanelsReady.value = false;')
    || !content.includes("if (newPage !== 'ctrip-ebooking') {\n                    clearCtripEbookingModuleCardsReadyTimer();\n                    ctripEbookingModuleCardsReady.value = false;\n                    clearCtripEbookingSecondaryPanelsReadyTimer();\n                    ctripEbookingSecondaryPanelsReady.value = false;\n                    clearCtripEbookingDeepPanelsReadyTimer();\n                    ctripEbookingDeepPanelsReady.value = false;\n                    clearCtripEbookingBusinessDetailsReadyTimer();\n                    ctripEbookingBusinessDetailsReady.value = false;\n                    ctripEbookingDiagnosticsPanelsReady.value = false;\n                }")
    || !content.includes('clearCtripEbookingModuleCardsReadyTimer();\n                clearCtripEbookingSecondaryPanelsReadyTimer();\n                clearCtripEbookingDeepPanelsReadyTimer();\n                clearCtripEbookingBusinessDetailsReadyTimer();')
    || !content.includes('ctripEbookingModuleCardsReady, ctripEbookingSecondaryPanelsReady, ctripEbookingDeepPanelsReady, ctripEbookingBusinessDetailsReady, ctripEbookingDiagnosticsPanelsReady, handleCtripEbookingDiagnosticsToggle, dashboardHotelId')) {
    failures.push('public/index.html Ctrip eBooking secondary data-health panels must be delayed behind the first manual-fetch interaction window.');
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
  if (!openCtripManualTabSource.includes('loadDataHealthPanel: scheduleDataHealthPanelRefresh')
    || openCtripManualTabSource.includes('loadDataHealthPanel,')) {
    failures.push('public/index.html Ctrip manual data-health tab must schedule the light health refresh instead of passing the blocking loader.');
  }
  if (!/if \(tab === 'data-health'\) \{\s*ctripEbookingModuleCardsReady\.value = false;\s*scheduleCtripEbookingModuleCardsReady\(\);\s*ctripEbookingSecondaryPanelsReady\.value = false;\s*scheduleCtripEbookingSecondaryPanelsReady\(\);\s*ctripEbookingDeepPanelsReady\.value = false;\s*scheduleCtripEbookingDeepPanelsReady\(\);\s*ctripEbookingBusinessDetailsReady\.value = false;\s*scheduleCtripEbookingBusinessDetailsReady\(\);\s*ctripEbookingDiagnosticsPanelsReady\.value = false;\s*\} else \{\s*clearCtripEbookingModuleCardsReadyTimer\(\);\s*ctripEbookingModuleCardsReady\.value = false;\s*clearCtripEbookingSecondaryPanelsReadyTimer\(\);\s*ctripEbookingSecondaryPanelsReady\.value = false;\s*clearCtripEbookingDeepPanelsReadyTimer\(\);\s*ctripEbookingDeepPanelsReady\.value = false;\s*clearCtripEbookingBusinessDetailsReadyTimer\(\);\s*ctripEbookingBusinessDetailsReady\.value = false;\s*ctripEbookingDiagnosticsPanelsReady\.value = false;\s*\}/.test(openCtripManualTabSource)) {
    failures.push('public/index.html Ctrip manual tab switch must only mount secondary overview diagnostics after the data-health tab is visibly selected.');
  }
  const refreshCtripHotelConfigOptionsSource = content.slice(
    content.indexOf('const refreshCtripHotelConfigOptions ='),
    content.indexOf('const applyMeituanHotelConfig = async')
  );
  if (!content.includes('const refreshCtripHotelConfigOptions = () => {')
    || !/deferUiTask\(async \(\) =>[\s\S]*Promise\.allSettled\(\[loadHotels\(\), loadCtripConfigList\(\{[\s\S]*applySelectedConfig: false,[\s\S]*\}\)\]\)[\s\S]*applyCtripHotelConfig\(false, \{[\s\S]*refreshList: false,[\s\S]*refreshLatest: false,[\s\S]*skipIfAligned: true,/.test(refreshCtripHotelConfigOptionsSource)
    || refreshCtripHotelConfigOptionsSource.includes('const refreshCtripHotelConfigOptions = async')
    || refreshCtripHotelConfigOptionsSource.includes('await Promise.all([loadHotels(), loadCtripConfigList()]);')) {
    failures.push('public/index.html Ctrip hotel config refresh must not block manual fetch controls on config-list loading.');
  }
  const openCtripOverviewFetchTabSource = content.slice(
    content.indexOf('const openCtripOverviewFetchTab = async'),
    content.indexOf('const ctripOverviewCookieApiSections')
  );
  if (!/onlineDataTab\.value = tabName;\s*deferUiTask\(async \(\) =>/.test(openCtripOverviewFetchTabSource)
    || !/await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/.test(openCtripOverviewFetchTabSource)
    || !/await applyCtripHotelConfig\(false, \{\s*refreshList: false,\s*refreshLatest: false,\s*skipIfAligned: true,\s*\}\);/.test(openCtripOverviewFetchTabSource)
    || /await loadCtripConfigList\(\);[\s\S]*onlineDataTab\.value = tabName/.test(openCtripOverviewFetchTabSource)) {
    failures.push('public/index.html Ctrip overview external tab entry must switch tabs before deferred short-cache config loading.');
  }
  const ctripOverviewFetchRunnerSource = content.slice(
    content.indexOf('const runCtripOverviewFetchActionInternal = async'),
    content.indexOf('const refreshCtripHotelConfigOptions =')
  );
  if (!ctripOverviewFetchRunnerSource.includes("scheduleDataHealthPanelRefresh('light', { force: true });")
    || ctripOverviewFetchRunnerSource.includes("await loadDataHealthPanel('light', { force: true });")) {
    failures.push('public/index.html Ctrip overview fetch completion must schedule data-health refresh instead of waiting before releasing loading state.');
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
  const openCtripCookieEditorFromHealthSource = content.slice(
    content.indexOf('const openCtripCookieEditorFromHealth = async'),
    content.indexOf('const editCtripCookieFromHealth = async')
  );
  if (!openCtripCookieEditorFromHealthSource.includes("const listConfig = ctripConfigList.value.find(item => String(item.id || '') === configId);")
    || !openCtripCookieEditorFromHealthSource.includes('listConfig\n                        ? await ensureCtripConfigSecret(listConfig)\n                        : await loadCtripConfigDetail(configId);')
    || openCtripCookieEditorFromHealthSource.includes('await loadCtripConfigList();')) {
    failures.push('public/index.html Ctrip health Cookie editor must read the exact config detail without waiting for the full config list first.');
  }
  const ctripCookieHealthMutationSource = content.slice(
    content.indexOf('const saveCtripCookieFromHealth = async'),
    content.indexOf('const batchDeleteCtripConfigs = async')
  );
  if (!ctripCookieHealthMutationSource.includes('loadCtripConfigList();')
    || !ctripCookieHealthMutationSource.includes("scheduleDataHealthPanelRefresh('light', { force: true });")
    || ctripCookieHealthMutationSource.includes("await loadDataHealthPanel('light', { force: true });")) {
    failures.push('public/index.html Ctrip health Cookie save/delete actions must refresh lists and data-health status without waiting on the data-health panel.');
  }
  const meituanEbookingDefaultLoader = content.slice(
    content.indexOf("if (newPage === 'meituan-ebooking'"),
    content.indexOf("if (newPage === 'hotels'")
  );
  const meituanStartupRefreshStart = content.indexOf('const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS = 16;');
  const meituanStartupRefreshMarker = content.indexOf('const scheduleMeituanEbookingDeferredStartupRefresh = () => {');
  const meituanStartupRefreshDefaultEnd = content.indexOf('const scheduleDefaultDashboardDeferredRefresh', meituanStartupRefreshMarker);
  const meituanStartupRefreshFallbackEnd = content.indexOf('const openCtripManualTab', meituanStartupRefreshMarker);
  const meituanStartupRefreshEnd = meituanStartupRefreshDefaultEnd >= 0
    ? meituanStartupRefreshDefaultEnd
    : meituanStartupRefreshFallbackEnd;
  const meituanStartupRefreshSource = content.slice(
    meituanStartupRefreshStart,
    meituanStartupRefreshEnd
  );
  if (!content.includes('const MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS = 16;')
    || !content.includes('const MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS = 5200;')
    || !content.includes('const MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS = 6400;')
    || !content.includes('const scheduleMeituanEbookingDeferredStartupRefresh = () => {')
    || !content.includes('const resolveMeituanManualDefaultHotelId = () => {')
    || !content.includes('const ensureMeituanManualHotelSelected = () => {')
    || !content.includes('suppressNextMeituanHotelConfigApply = true;')
    || !meituanStartupRefreshSource.includes('ensureMeituanManualHotelSelected();')
    || !meituanStartupRefreshSource.includes('}, MEITUAN_EBOOKING_STARTUP_CONFIG_DELAY_MS);')
    || !meituanStartupRefreshSource.includes('}, MEITUAN_EBOOKING_SECONDARY_CONFIG_DELAY_MS);')
    || !meituanStartupRefreshSource.includes('}, MEITUAN_EBOOKING_HOTEL_LIST_DELAY_MS);')
    || meituanStartupRefreshSource.includes('}, 0);')
    || meituanStartupRefreshSource.includes('}, 2400);')
    || meituanStartupRefreshSource.includes('}, 3000);')
    || !meituanEbookingDefaultLoader.includes('scheduleMeituanEbookingDeferredStartupRefresh();')) {
    failures.push('public/index.html must start Meituan eBooking config matching near immediately while keeping secondary startup refreshes deferred.');
  }
  if (!content.includes('const ensureMeituanConfigSecret = async (config, options = {}) => {')
    || !content.includes("console.error('[Meituan]")
    || !content.includes('const prewarmSelectedMeituanConfigSecret = (config = selectedMeituanHotelConfig.value) => {')
    || !content.includes('deferUiTask(() => ensureMeituanConfigSecret(config, { silent: true }), 80);')
    || !content.includes('let configSource = options.resolvedConfig || selectedMeituanHotelConfig.value;')
    || !content.includes('const config = options.resolvedConfig || await ensureMeituanConfigSecret(configSource);')
    || !meituanStaticContent.includes('if (!isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {')
    || !meituanStaticContent.includes('skipIfAligned: true,')
    || meituanStaticContent.includes('await applyMeituanHotelConfig(false);')
    || !/await loadMeituanConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);\s*if \(currentPage\.value !== 'meituan-ebooking'\) return null;\s*prewarmSelectedMeituanConfigSecret\(\);/.test(content)
    || !content.includes('const shouldApplySelectedConfig = options.applySelectedConfig === true;')
    || !content.includes('if (meituanForm.value.hotelId && shouldApplySelectedConfig) {')
    || content.includes("if (meituanForm.value.hotelId) {\n                                await applyMeituanHotelConfig(false, { refreshList: false });\n                            }\n                            return meituanConfigList.value;")) {
    failures.push('public/index.html Meituan config list must return after list data and prewarm full config detail in deferred work.');
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
    || !openMeituanManualTabSource.includes('ensureMeituanManualHotelSelected();')
    || !meituanStaticContent.includes('const runMeituanManualTabSwitch = async')) {
    failures.push('public/index.html must keep Meituan manual tab async branching in public/meituan-static.js.');
  }
  const platformProfileActionSource = content.slice(
    content.indexOf('const openPlatformProfileAction = async'),
    content.indexOf("if (target === 'analysis')")
  );
  if (!platformProfileActionSource.includes('scheduleMeituanEbookingDeferredStartupRefresh();')
    || !platformProfileActionSource.includes('ensureMeituanManualHotelSelected();')
    || platformProfileActionSource.includes('await loadMeituanConfigList();')) {
    failures.push('public/index.html platform profile Meituan ranking action must not await config-list loading before returning.');
  }
  if (content.includes('配置待读取，正在准备美团数据源匹配...')
    || !content.includes('配置读取失败，请刷新后重试；未读取成功前不会判断为未配置。')
    || !/meituanConfigListLoaded && !selectedMeituanHotelConfig/.test(content)) {
    failures.push('public/index.html Meituan manual fetch must not show a slow pending match state and must keep failed/confirmed-missing states explicit.');
  }
  if (!content.includes(':disabled="fetchingData || !canFetchMeituanRankingData()"')
    || !content.includes('const meituanManualFetchConfigProofPending = () => {')
    || !content.includes('const canFetchMeituanRankingData = () => {')
    || !content.includes("if (String(form.cookies || '').trim()) return true;")
    || !content.includes('return !!form.hotelId && !!selectedMeituanHotelConfig.value;')
    || !content.includes('const resolveMeituanManualFetchConfig = async (config) => {')
    || !content.includes('if (!meituanForm.value.hotelId) return null;')
    || !content.includes('ensureMeituanConfigSecret: async config => ensureMeituanConfigSecret(await resolveMeituanManualFetchConfig(config))')
    || !meituanStaticContent.includes('const selectedMeituanConfig = form.hotelId')
    || meituanStaticContent.includes("return { status: 'missing_hotel' };")
    || meituanStaticContent.includes("return { status: 'missing_config' };")
    || !content.includes('if (preparingConfig) {\n                        fetchingData.value = false;')) {
    failures.push('public/index.html Meituan ranking manual fetch must allow temporary Cookie validation without a saved config, while keeping saved config application optional.');
  }
  const applyMeituanHotelConfigSource = content.slice(
    content.indexOf('const applyMeituanHotelConfig = async'),
    content.indexOf('const syncMeituanTrafficConfigFromSelectedConfig')
  );
  if (/await loadMeituanConfigList\(/.test(applyMeituanHotelConfigSource)
    || applyMeituanHotelConfigSource.includes("request('/online-data/get-meituan-config-list')")) {
    failures.push('public/index.html Meituan hotel selection must apply only already loaded configs and must not wait on the config-list loader.');
  }
  const meituanHotelWatcherSource = content.slice(
    content.indexOf('watch(() => meituanForm.value.hotelId'),
    content.indexOf('watch(competitorTab')
  );
  if (!content.includes('let meituanHotelConfigApplyVersion = 0;')
    || !content.includes('let suppressNextMeituanHotelConfigApply = false;')
    || !content.includes('const scheduleMeituanHotelConfigApply = (options = {}) => {')
    || !meituanHotelWatcherSource.includes('if (suppressNextMeituanHotelConfigApply) {')
    || !meituanHotelWatcherSource.includes('suppressNextMeituanHotelConfigApply = false;')
    || !meituanHotelWatcherSource.includes('scheduleMeituanHotelConfigApply({ delayMs: 0 });')
    || meituanHotelWatcherSource.includes('applyMeituanHotelConfig(false);')) {
    failures.push('public/index.html Meituan hotel switching must defer config matching through a stale-guarded scheduler.');
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
  if (!onlineDataDefaultLoader.includes("runPageLoadOnce(newPage, 'main', () => {\n                            scheduleDataHealthPanelRefresh('light');\n                            return null;\n                        });")
    || onlineDataDefaultLoader.includes("runPageLoadOnce(newPage, 'main', () => loadDataHealthPanel('light'));")
    || /runPageLoadOnce\(newPage,\s*['"]main['"],\s*\(\)\s*=>\s*Promise\.allSettled\(\[\s*loadOnlineDataHotelList\(\),\s*loadDataHealthPanel\(['"]light['"]\),\s*\]\)\)/.test(onlineDataDefaultLoader)) {
    failures.push('public/index.html default online-data first paint must schedule only light data-health status and defer hotel-list loading.');
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
    || !content.includes('const meituanDownloadData = computed(() => {')
    || !content.includes('switchToMeituanDownloadCenter, meituanDownloadData,')
    || !downloadCenterTabSource.includes("await refreshOnlineHistory({ refreshHotels: false });")
    || !downloadCenterTabSource.includes('scheduleDelayedPageTask(() => {')
    || !downloadCenterTabSource.includes('return loadOnlineHistoryHotelList();')
    || !downloadCenterTabSource.includes('await loadOnlineDataList({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });')
    || !downloadCenterTabSource.includes('return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });')
    || downloadCenterTabSource.includes('deferUiTask(() => {\n                            if (!isCurrentTab()) return null;\n                            return loadOnlineHistoryHotelList();\n                        }, 720);')
    || downloadCenterTabSource.includes('deferUiTask(() => {\n                        if (seq !== downloadCenterTabLoadSeq || !isCurrentTab()) return null;\n                        return loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS });\n                    }, 720);')
    || !/await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/.test(downloadCenterTabSource)
    || /Promise\.allSettled\(\[\s*loadOnlineDataList\(\{\s*cacheMs:\s*ONLINE_DATA_PANEL_CACHE_TTL_MS\s*\}\),\s*loadOnlineDataHotelList\(\{\s*cacheMs:\s*ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS\s*\}\),?\s*\]\)/.test(downloadCenterTabSource)
    || downloadCenterTabSource.includes('await refreshOnlineHistory();')
    || downloadCenterTabSource.includes('const switchDownloadTab = async')
    || downloadCenterTabSource.includes('const switchToDownloadCenter = async')
    || downloadCenterTabSource.includes('const switchToMeituanDownloadCenter = async')
    || downloadCenterTabSource.includes("onlineDataTab.value = 'ctrip-fetch-settings';\n                    await loadCtripConfigList();")
    || downloadCenterTabSource.includes("downloadCenterTab.value = 'overview';\n                await refreshOnlineHistory();")
    || downloadCenterTabSource.includes('await loadOnlineDataList();\n                await loadOnlineDataHotelList();')
    || downloadCenterTabSource.includes('await loadOnlineDataList();\n                    await loadOnlineDataHotelList();')) {
    failures.push('public/index.html download center tab switches must schedule list/config/AI loads after the tab changes.');
  }
  const ctripOverviewTargetHotelSource = content.slice(
    content.indexOf('const syncCtripOverviewTargetHotel = async'),
    content.indexOf('const handleCtripOverviewHotelChange = async')
  );
  if (!/await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);/.test(ctripOverviewTargetHotelSource)
    || !/await applyCtripHotelConfig\(false, \{\s*refreshList: false,\s*refreshLatest: false,\s*skipIfAligned: true,\s*\}\);/.test(ctripOverviewTargetHotelSource)) {
    failures.push('public/index.html Ctrip overview hotel switching must reuse the short config-list cache before applying manual fetch config.');
  }
  const ctripOverviewHotelChangeSource = content.slice(
    content.indexOf('const handleCtripOverviewHotelChange = async'),
    content.indexOf('const applyCtripHotelConfig = async')
  );
  if (!ctripOverviewHotelChangeSource.includes('await syncCtripOverviewTargetHotel({ clearDisplay: true, loadConfig: true });')
    || !ctripOverviewHotelChangeSource.includes("scheduleDataHealthPanelRefresh('light', { force: true });")
    || ctripOverviewHotelChangeSource.includes("await loadDataHealthPanel('light');")) {
    failures.push('public/index.html Ctrip overview hotel switching must schedule data-health refresh after config sync instead of waiting on it.');
  }
  if (!/newTab === ['"]platform-auto['"][\s\S]*schedulePlatformAutoFetchPanelLoad\((?:options)?\)/.test(onlineDataTabSchedulerSource)) {
    failures.push('public/index.html must lazy-load the platform-auto panel when the platform-auto tab is opened.');
  }
  if (!/const\s+schedulePlatformAutoFetchPanelLoad\s*=\s*\(options\s*=\s*\{\}\)\s*=>\s*\{[\s\S]*const\s+run\s*=\s*\(\)\s*=>\s*runPageLoadOnce\(\s*currentPage\.value\s*\|\|\s*['"]online-data['"],\s*['"]platform-auto-panel['"],\s*\(\)\s*=>\s*\{[\s\S]*if\s*\(!isVisibleOnlineDataTab\(['"]platform-auto['"]\)\)\s*return\s+null;[\s\S]*return\s+loadAutoFetchPanel\(options\);[\s\S]*scheduleDelayedPageTask\(run,\s*delayMs\);[\s\S]*return\s+run\(\);[\s\S]*\}/.test(content)
    || !/const\s+openPlatformAutoTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)
    || !/const\s+openOnlinePlatformAutoTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)) {
    failures.push('public/index.html must route platform-auto tab opens through one deduplicated visible-tab page-load scheduler.');
  }
  if (content.includes("onlineDataTab = 'platform-auto'; loadAutoFetchPanel()")
    || content.includes('onlineDataTab = "platform-auto"; loadAutoFetchPanel()')
    || content.includes("if (row.tab === 'platform-auto') loadAutoFetchPanel();")
    || content.includes('@change="loadAutoFetchPanel"')
    || content.includes('@click="loadAutoFetchPanel"')
    || content.includes("if (item.path === 'online-data' && item.tab === 'platform-auto') {\n                            loadAutoFetchPanel();")) {
    failures.push('public/index.html must not bypass the platform-auto scheduler from buttons, drilldowns, or menu clicks.');
  }
  const platformAutoTemplateStart = content.indexOf('<div v-if="onlineDataTab === \'platform-auto\'">');
  const platformAutoTemplateEnd = content.indexOf('<div v-if="onlineDataTab === \'data\'">', platformAutoTemplateStart);
  const platformAutoTemplateSource = platformAutoTemplateStart >= 0 && platformAutoTemplateEnd > platformAutoTemplateStart
    ? content.slice(platformAutoTemplateStart, platformAutoTemplateEnd)
    : '';
  if (!platformAutoTemplateSource
    || platformAutoTemplateSource.includes('v-if="false"')
    || platformAutoTemplateSource.includes('v-if="false &&')
    || platformAutoTemplateSource.includes('<details v-if="false"')) {
    failures.push('public/index.html platform-auto template must not keep disabled legacy blocks that still inflate Vue parsing work.');
  }
  if (!platformAutoTemplateSource.includes('<platform-auto-settings-panels')
    || !platformAutoTemplateSource.includes(':ctx="$root"')
    || !content.includes("const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260613-platform-auto-lazy';")
    || !content.includes('const ensurePlatformAutoPanelsReady = async () => {')
    || !content.includes("requireOnlineDataComponent('PlatformAutoSettingsPanelsBody')")
    || !content.includes("requireOnlineDataComponent('PlatformAutoSecondaryPanelsBody')")
    || !content.includes('data-testid="platform-auto-settings-panels-loading"')
    || !platformAutoSettingsPanelsContent.includes('data-testid="platform-auto-settings-panels"')
    || !platformAutoSettingsPanelsContent.includes('v-model.number="ctx.autoFetchRealtimeIntervalHours"')
    || !platformAutoSettingsPanelsContent.includes('v-model.number="ctx.autoFetchScheduleMinute"')
    || !platformAutoSettingsPanelsContent.includes('v-model="ctx.autoFetchBrowserHeadless"')
    || !platformAutoSettingsPanelsContent.includes('v-model.number="ctx.autoFetchCtripSectionConcurrency"')
    || !content.includes('const PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS = 800;')
    || !content.includes('const platformAutoSettingsPanelsReady = ref(false);')
    || !content.includes('const platformAutoSettingsPanelsBody = shallowRef(null);')
    || !content.includes('const schedulePlatformAutoSettingsPanelsReady = (delayMs = PLATFORM_AUTO_SETTINGS_PANEL_DELAY_MS) => {')
    || !content.includes("console.error('[platform-auto-settings-panels] load failed:', error);")
    || !content.includes('platformAutoSettingsPanelsReady.value = false;\n                    schedulePlatformAutoSettingsPanelsReady();')
    || !platformAutoTemplateSource.includes('<platform-auto-secondary-panels')
    || !content.includes('data-testid="platform-auto-secondary-panels-loading"')
    || !platformAutoSettingsPanelsContent.includes('data-testid="platform-auto-secondary-panels"')
    || !platformAutoSettingsPanelsContent.includes('ctx.autoFetchCollectionBlueprintRows')
    || !platformAutoSettingsPanelsContent.includes('ctx.meituanPlatformProfileStatusRow')
    || !platformAutoSettingsPanelsContent.includes('ctx.autoFetchPlatformResultRows')
    || !content.includes('const PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS = 2600;')
    || !content.includes('const platformAutoSecondaryPanelsReady = ref(false);')
    || !content.includes('const platformAutoSecondaryPanelsBody = shallowRef(null);')
    || !content.includes('const schedulePlatformAutoSecondaryPanelsReady = (delayMs = PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS) => {')
    || !content.includes('platformAutoSecondaryPanelsReady.value = false;\n                    schedulePlatformAutoSecondaryPanelsReady();\n                    return runIfCurrent(() => schedulePlatformAutoFetchPanelLoad(options));')) {
    failures.push('public/index.html platform-auto must keep secondary result/status panels delayed so core login and collect controls paint first.');
  }
  if (platformAutoTemplateSource.includes('实时采集间隔（小时）')
    || platformAutoTemplateSource.includes('无头模式（后台运行，不显示浏览器窗口）')
    || platformAutoTemplateSource.includes('采集闭环')
    || platformAutoTemplateSource.includes('最近结果：')) {
    failures.push('public/index.html platform-auto template must keep schedule/browser and secondary status panels inside the split component, not the root template.');
  }
  if (content.includes('v-if="false"') || content.includes("v-if='false'")) {
    failures.push('public/index.html must not keep disabled v-if=false template blocks that still inflate Vue parsing work.');
  }
  if (content.includes('v-if="false && onlineDataQualitySummary"')
    || content.includes('<div v-if="false" class="mt-6 border-t pt-4">')) {
    failures.push('public/index.html online-data template must not keep disabled legacy data-quality or inline analysis blocks.');
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
  const openOnlinePlatformAutoTabSource = content.slice(
    content.indexOf('const openOnlinePlatformAutoTab = (options = {}) => {'),
    content.indexOf('const openPlatformSourcesTab =', content.indexOf('const openOnlinePlatformAutoTab = (options = {}) => {'))
  );
  const openHotelPlatformConsoleSource = content.slice(
    content.indexOf('const openHotelPlatformConsole = async'),
    content.indexOf('const openHotelPlatformAccountAction = async', content.indexOf('const openHotelPlatformConsole = async'))
  );
  if (!openOnlinePlatformAutoTabSource.includes("openOnlineDataEntryTab('platform-auto', options)")
    || !openHotelPlatformConsoleSource.includes('openPlatformAutoTab({ force: true')
    || openHotelPlatformConsoleSource.includes("runPageLoadOnce('online-data', 'platform-auto-panel'")) {
    failures.push('public/index.html must schedule platform-auto panel refreshes from notification and hotel console navigation without awaiting them.');
  }
  if (!content.includes('deferUiTask(() => {\n                            schedulePlatformProfileStatusRefresh({ silent: true, force: true });\n                            schedulePlatformAutoFetchPanelLoad({ force: true });\n                        });')) {
    failures.push('public/index.html must defer profile unbind follow-up refreshes through forced visible-tab schedulers instead of serially awaiting platform-auto reload.');
  }
  if (!/const\s+schedulePlatformDataSourcePanelLoad\s*=\s*\(options\s*=\s*\{\}\)\s*=>\s*runPageLoadOnce\(\s*currentPage\.value\s*\|\|\s*['"]online-data['"],\s*['"]platform-source-panel['"],\s*\(\)\s*=>\s*\{[\s\S]*if\s*\(!isVisibleOnlineDataTab\(['"]platform-sources['"]\)\)\s*return\s+null;[\s\S]*return\s+loadPlatformDataSourcePanel\(options\);[\s\S]*\}/.test(content)
    || !/const\s+openPlatformSourcesTab\s*=\s*\(options\s*=\s*\{\}\)\s*=>/.test(content)) {
    failures.push('public/index.html must route platform source tab opens through one deduplicated visible-tab page-load scheduler.');
  }
  const platformDataSourcePanelSource = content.slice(
    content.indexOf('const loadPlatformDataSourcePanel = async (options = {}) => {'),
    content.indexOf('const savePlatformDataSource = async', content.indexOf('const loadPlatformDataSourcePanel = async (options = {}) => {'))
  );
  if (!content.includes('const PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS = 3200;')
    || !content.includes('const PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS = 1200;')
    || !content.includes('const PLATFORM_SOURCE_PANEL_CACHE_TTL_MS = 30000;')
    || !content.includes('const platformSourceGuidePanelsReady = ref(false);')
    || !content.includes('const schedulePlatformSourceGuidePanelsReady = (delayMs = PLATFORM_SOURCE_GUIDE_PANEL_DELAY_MS) => {')
    || !content.includes('<div v-if="platformSourceGuidePanelsReady" data-testid="platform-account-binding-guide"')
    || !content.includes('<div v-if="platformSourceGuidePanelsReady" data-testid="platform-batch-health-check"')
    || !content.includes("if (newTab === 'platform-sources') {\n                    platformSourceGuidePanelsReady.value = false;\n                    schedulePlatformSourceGuidePanelsReady();")
    || !content.includes('platformDataSourceHotelOptions, platformSourceGuidePanelsReady, loadPlatformDataSourcePanel')
    || !platformDataSourcePanelSource.includes('await Promise.allSettled([\n                    loadPlatformDataSources({')
    || !platformDataSourcePanelSource.includes('loadPlatformProfileStatus({\n                        silent: true,')
    || !platformDataSourcePanelSource.includes('scheduleDelayedPageTask(() => {')
    || !platformDataSourcePanelSource.includes('if (!shouldRefreshPlatformDataSourcesPanel()) return null;')
    || !platformDataSourcePanelSource.includes('loadPlatformSyncTasks({')
    || !platformDataSourcePanelSource.includes('loadPlatformSyncLogs({')
    || !platformDataSourcePanelSource.includes('loadPlatformCollectionResources({')
    || !platformDataSourcePanelSource.includes('loadCompetitorSummary({\n                            includeByHotel: true,\n                            force: options.force === true,\n                            cacheMs: options.force ? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,')
    || !platformDataSourcePanelSource.includes('}, PLATFORM_SOURCE_SECONDARY_REFRESH_DELAY_MS);')
    || platformDataSourcePanelSource.includes('deferUiTask(() => {')) {
    failures.push('public/index.html platform source panel must keep core data-source/profile state first, then delay guide cards and secondary sync/log/resource refreshes.');
  }
  if (!content.includes('const platformCollectionResourcesRequestPromises = new Map();')
    || !content.includes('const platformCollectionResourcesResultCache = new Map();')
    || !/const loadPlatformCollectionResources = async \(options = \{\}\) =>[\s\S]*readRequestCache\(platformCollectionResourcesResultCache, requestKey, cacheMs\)[\s\S]*platformCollectionResourcesRequestPromises\.has\(requestKey\)[\s\S]*writeRequestCache\(platformCollectionResourcesResultCache, requestKey, cacheMs\)/.test(content)
    || !content.includes('loadPlatformCollectionResources({\n                            force: options.force === true,\n                            cacheMs: options.force ? 0 : PLATFORM_SOURCE_PANEL_CACHE_TTL_MS,')) {
    failures.push('public/index.html must deduplicate and short-cache platform collection-resource reads from the platform source panel.');
  }
  if (!content.includes('const competitorSummaryRequestPromises = new Map();')
    || !content.includes('const competitorSummaryResultCache = new Map();')
    || !/const loadCompetitorSummary = async \(options = \{\}\) =>[\s\S]*readRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\)[\s\S]*competitorSummaryRequestPromises\.has\(requestKey\)[\s\S]*writeRequestCache\(competitorSummaryResultCache, requestKey, cacheMs\)[\s\S]*competitorSummaryRequestPromises\.delete\(requestKey\)/.test(content)) {
    failures.push('public/index.html must deduplicate and short-cache competitor summary reads during platform source panel tab switching.');
  }
  const platformSyncLogPanelSource = content.slice(
    content.indexOf('const schedulePlatformSyncLogPanelRefresh ='),
    content.indexOf('const schedulePlatformAutoFetchPanelLoad =')
  );
  if (!platformSyncLogPanelSource.includes("const schedulePlatformSyncLogPanelRefresh = (options = {}) => runPageLoadOnce(")
    || !platformSyncLogPanelSource.includes("if (!isVisibleOnlineDataTab('platform-sources')) return null;")
    || !/loadPlatformSyncTasks\s*\(\s*\{?/.test(platformSyncLogPanelSource)
    || !/loadPlatformSyncLogs\s*\(\s*\{?/.test(platformSyncLogPanelSource)
    || !platformSyncLogPanelSource.includes('cacheMs: options.force ? 0 : PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS,')
    || !content.includes('@click="schedulePlatformSyncLogPanelRefresh({ force: true })"')) {
    failures.push('public/index.html must route platform sync-log refreshes through the shared visible-tab scheduler instead of inline requests.');
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
  if (content.includes('@click="loadPlatformDataSourcePanel"')) {
    failures.push('public/index.html platform source refresh buttons must use schedulePlatformDataSourcePanelLoad({ force: true }) instead of directly loading the full panel.');
  }
  if (!content.includes('schedulePlatformDataSourcePanelLoad({ force: true });')) {
    failures.push('public/index.html must force-refresh the platform source panel through the page-load scheduler after source mutations.');
  }
  if (!content.includes('@click="schedulePlatformDataSourcePanelLoad({ force: true })"')
    || !content.includes('schedulePlatformDataSourcePanelLoad, schedulePlatformSyncLogPanelRefresh')) {
    failures.push('public/index.html must expose and use the platform source refresh scheduler from template buttons.');
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
    || !content.includes("requireSystemStatic('buildHotelOtaCtripConfigSavePayload')")
    || !content.includes("requireSystemStatic('buildHotelOtaMeituanConfigSavePayload')")
    || !content.includes("requireSystemStatic('buildHotelPlatformBindingRows')")
    || !content.includes('hotelForm.value = createHotelForm({ hotel, operatorName, parsedDescription });')
    || !content.includes('const payload = buildHotelSavePayload({')
    || !content.includes('JSON.stringify(buildHotelOtaCtripConfigSavePayload({')
    || !content.includes('JSON.stringify(buildHotelOtaMeituanConfigSavePayload({')
    || !content.includes('return buildHotelPlatformBindingRowsStatic({')
    || content.includes('const meituanIdentifierMissing = [')) {
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
    || !systemStaticContent.includes('const buildHotelSavePayload = ({ form = {}, normalizedCode = \'\', operatorName = \'\', description = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const buildHotelOtaCtripConfigSavePayload = ({ hotelIdText = \'\', ctrip = {}, existing = null, fallbackName = \'\', defaultUrl = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const buildHotelOtaMeituanConfigSavePayload = ({ hotelIdText = \'\', meituan = {}, existing = null, fallbackName = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const buildHotelPlatformBindingRows = ({')) {
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
    || content.includes('name: hotelForm.value.name.trim(),\n                    code: normalizedCode,')
    || content.includes('ctrip_hotel_id: ctrip.ctrip_hotel_id || existing?.ctrip_hotel_id')
    || content.includes('hotel_room_count: meituan.hotel_room_count || existing?.hotel_room_count')) {
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
  if (!content.includes('const prewarmAutoFetchStaticForPlatformAuto = () => {')
    || !content.includes("if (!isVisibleOnlineDataTab('platform-auto')) return null;")
    || !content.includes('const staticReadyPromise = loadAutoFetchStatic().catch(error => {')
    || !content.includes('void staticReadyPromise;')
    || autoFetchPanelLoader.includes('const staticReadyPromise = loadAutoFetchStatic().catch(error => {')
    || !content.includes('const PLATFORM_AUTO_PANEL_START_DELAY_MS = 16;')
    || !content.includes('const waitForPlatformAutoPanelStart = async (options = {}) => {')
    || !autoFetchPanelLoader.includes('if (!await waitForPlatformAutoPanelStart(options)) {')
    || !autoFetchPanelLoader.includes('let panelLoaded = false;')
    || !autoFetchPanelLoader.includes('const canLoadStatusBeforeHotels = !!autoFetchHotelId.value;')
    || !autoFetchPanelLoader.includes('const hotelsPromise = shouldLoadHotels ? loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS }) : Promise.resolve();')
    || !autoFetchPanelLoader.includes('if (canLoadStatusBeforeHotels) {\n                        await Promise.all([\n                            loadAutoFetchStatus({ detail: false }),\n                            hotelsPromise,\n                        ]);')
    || !autoFetchPanelLoader.includes("await hotelsPromise;\n                    if (!isVisibleOnlineDataTab('platform-auto')) {\n                        return;\n                    }\n                    if (!autoFetchHotelId.value && hotels.value && hotels.value.length > 0) {")
    || !autoFetchPanelLoader.includes('await loadAutoFetchStatus({ detail: false });')
    || !autoFetchPanelLoader.includes('if (panelLoaded) {')
    || !autoFetchPanelLoader.includes('else if (autoFetchPanelCache.promise === run) {')
    || !content.includes('prewarmAutoFetchStaticForPlatformAuto();')
    || autoFetchPanelLoader.includes('staticReadyPromise,\n                            hotelsPromise')
    || autoFetchPanelLoader.includes('staticReadyPromise,\n                    ]);')
    || /scheduleAutoFetchStatusDetailRefresh\(\);/.test(autoFetchPanelLoader)
    || /schedulePlatformProfileStatusRefresh\(\{ silent: true \}\);/.test(autoFetchPanelLoader)
    || /await Promise\.all\(\[[\s\S]*loadAutoFetchStatus\(\)[\s\S]*loadPlatformProfileStatus/.test(autoFetchPanelLoader)) {
    failures.push('public/index.html must let platform-auto first paint wait only for light auto-fetch status, and load hotels/status/static helper in parallel when the selected hotel is already known.');
  }
  if (!content.includes('const scheduleAutoFetchConfigListPrewarm = () => {')
    || !content.includes('!ctripConfigListLoaded.value && (!ctripConfigList.value || ctripConfigList.value.length === 0)')
    || !content.includes('!meituanConfigListLoaded.value && (!meituanConfigList.value || meituanConfigList.value.length === 0)')) {
    failures.push('public/index.html must keep the saved Ctrip/Meituan config-list prewarm helper available without blocking platform-auto first paint.');
  }
  if (autoFetchPanelLoader.includes('scheduleAutoFetchConfigListPrewarm();')) {
    failures.push('public/index.html must not auto-start saved Ctrip/Meituan config-list prewarm when entering platform-auto.');
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
    || !content.includes('const AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS = AUTO_FETCH_PANEL_CACHE_TTL_MS;')
    || !content.includes('const autoFetchStatusResultCache = new Map();')
    || !content.includes('const resetAutoFetchStatusResultCache = () => {')
    || !content.includes("const requestKey = `${String(hotelId || '')}|${includeDetail ? 'full' : 'light'}`;")
    || !content.includes("if (!force && !includeDetail) {")
    || !content.includes('return autoFetchStatus.value;')
    || !content.includes('if (autoFetchStatusRequestPromises.has(requestKey))')
    || !content.includes('autoFetchStatusRequestPromises.delete(requestKey);')
    || !content.includes('autoFetchStatusResultCache.set(requestKey, { expiresAt: Date.now() + AUTO_FETCH_STATUS_RESULT_CACHE_TTL_MS });')) {
    failures.push('public/index.html must deduplicate concurrent and recent light auto-fetch status requests by hotel and detail level across core OTA page switches.');
  }
  if (!content.includes('const PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS = 20000;')
    || !content.includes('const platformProfileStatusPanelRefreshOptions = (params = {}) => (')
    || !content.includes('const platformProfileStatusResultCache = new Map();')
    || !content.includes('return loadPlatformProfileStatus(platformProfileStatusPanelRefreshOptions(params));')
    || !content.includes('return platformProfileStatus.value;')
    || !content.includes('platformProfileStatusResultCache.set(requestKey, { expiresAt: Date.now() + cacheMs });')
    || !content.includes('@click="loadPlatformProfileStatus({ silent: true, force: true })"')
    || !content.includes('schedulePlatformProfileStatusRefresh({ silent: true, force: true });')
    || !content.includes('cacheMs: options.force ? 0 : PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS,')) {
    failures.push('public/index.html must cache platform profile status for panel/tab switching while keeping manual and mutation refreshes forced.');
  }
  if (!content.includes("const shouldRefreshAutoFetchStatusPanel = () => isOnlineDataTabVisible('platform-auto') || isDataHealthPanelVisible();")
    || !content.includes("const scheduleAutoFetchStatusRefresh = () => schedulePostFetchRefresh('auto-fetch-status', () => {")
    || !content.includes('if (!shouldRefreshAutoFetchStatusPanel()) return null;')
    || !content.includes('resetAutoFetchStatusResultCache();\n                return loadAutoFetchStatus({ detail: false });')
    || !content.includes('return loadAutoFetchStatus({ detail: false });')
    || !content.includes("if (!isOnlineDataTabVisible('platform-auto')) return null;")) {
    failures.push('public/index.html must use guarded light auto-fetch status for post-fetch status refreshes.');
  }
  if (!content.includes('const scheduleAutoFetchStatusPanelRefresh = () => {')
    || !content.includes('scheduleAutoFetchStatusRefresh();\n                scheduleAutoFetchStatusDetailRefresh();')
    || !content.includes('scheduleAutoFetchStatusPanelRefresh();')
    || content.includes('loadAutoFetchStatus();')
    || content.includes('await loadAutoFetchStatus();')) {
    failures.push('public/index.html platform-auto settings/history actions must schedule light status plus deferred detail refresh instead of loading full status inline.');
  }
  if (content.includes('@change="loadAutoFetchStatus"')
    || !content.includes('@change="schedulePlatformAutoFetchPanelLoad({ force: true, delayMs: 80 })"')
    || !content.includes('scheduleDelayedPageTask(run, delayMs);')) {
    failures.push('public/index.html platform-auto hotel switching must defer the shared scheduler instead of directly loading full auto-fetch status.');
  }
  const dataHealthPanelSource = content.slice(
    content.indexOf('const buildDataHealthPanelJobs = (normalizedMode) =>'),
    content.indexOf('const triggerAutoFetch = async')
  );
  if (!dataHealthPanelSource.includes('const buildDataHealthPanelJobs = (normalizedMode) => {')
    || !dataHealthPanelSource.includes("loadAutoFetchStatus({ detail: normalizedMode === 'full' })")
    || !dataHealthPanelSource.includes("if (normalizedMode === 'full') {")
    || !dataHealthPanelSource.includes("loadCollectionReliability('full')")
    || !dataHealthPanelSource.includes('loadDataHealthOperationLogs()')
    || !dataHealthPanelSource.includes('loadPublicEndpointSecurity()')
    || !dataHealthPanelSource.includes('loadHotelDataDashboard()')
    || !dataHealthPanelSource.includes('const scheduleDataHealthLightDiagnostics = () => {')
    || !dataHealthPanelSource.includes("return schedulePostFetchRefresh('data-health-light-diagnostics', () => {")
    || !dataHealthPanelSource.includes("if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') return null;")
    || !dataHealthPanelSource.includes("const initialHotelId = String(getAutoFetchHotelId() || '');")
    || !dataHealthPanelSource.includes('const initialCacheKey = dataHealthLightCacheKey(initialHotelId);')
    || !dataHealthPanelSource.includes("if (normalizedMode === 'light' && !force && cacheKey !== initialCacheKey) {")
    || !dataHealthPanelSource.includes('const jobs = buildDataHealthPanelJobs(normalizedMode);')) {
    failures.push('public/index.html must keep data-health panel job composition and deferred light diagnostics out of loadDataHealthPanel.');
  }
  if (dataHealthPanelSource.includes('scheduleDataHealthLightDiagnostics();')) {
    failures.push('public/index.html data-health light first paint must not auto-run non-core light diagnostics.');
  }
  if (dataHealthPanelSource.indexOf('const initialCacheKey = dataHealthLightCacheKey(initialHotelId);') > dataHealthPanelSource.indexOf('await syncCtripOverviewTargetHotel({ loadConfig: false });')) {
    failures.push('public/index.html data-health light-cache hit checks must run before target-hotel sync.');
  }
  if (dataHealthPanelSource.includes('loadCookieStatus()')) {
    failures.push('public/index.html data-health panel must not duplicate collection-reliability authorization work by also calling cookie-status.');
  }

  if (!content.includes("if (!options.backendOnly) {\n                        scheduleDataHealthPanelRefresh('light');\n                    }\n                    await loadBackendGlobalNotifications();")
    || content.includes("const jobs = [loadBackendGlobalNotifications()];\n                    if (!options.backendOnly) {\n                        jobs.push(loadDataHealthPanel('light'));\n                    }")) {
    failures.push('public/index.html global notification refresh must not block on data-health light status; it should schedule the visible-tab refresh instead.');
  }
  if (!content.includes("currentPage.value = 'online-data';\n                onlineDataTab.value = 'data-health';\n                dataHealthSecondaryPanelsReady.value = false;\n                scheduleDataHealthSecondaryPanelsReady();\n                dataHealthDetailPanelsReady.value = false;\n                scheduleDataHealthDetailPanelsReady();\n                dataHealthEmployeePanelsReady.value = false;\n                scheduleDataHealthEmployeePanelsReady();\n                scheduleDataHealthPanelRefresh('light');")
    || content.includes("currentPage.value = 'online-data';\n                onlineDataTab.value = 'data-health';\n                await loadDataHealthPanel('light');")) {
    failures.push('public/index.html AI daily report data-gap navigation must switch immediately and schedule data-health light refresh/readiness.');
  }
  if (dataHealthPanelSource.includes('loadCollectionReliability(normalizedMode)')) {
    failures.push('public/index.html data-health light first paint must not run collection-reliability; keep reliability diagnostics in full mode.');
  }
  if (!content.includes('data-testid="data-health-loading-banner"')
    || content.includes('<template v-else>\n                                        <div data-testid="data-health-command-center"')
    || content.includes('<div v-if="hotelDashboardLoading || collectionReliabilityLoading" class="rounded-xl border border-gray-200 bg-white p-5">')) {
    failures.push('public/index.html data-health loading must be a non-blocking banner so drilldowns remain clickable while light diagnostics refresh.');
  }
  if (!content.includes('const DATA_HEALTH_SECONDARY_PANEL_DELAY_MS = 900;')
    || !content.includes('const DATA_HEALTH_DETAIL_PANEL_DELAY_MS = 2600;')
    || !content.includes('const DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS = 4200;')
    || !content.includes('const dataHealthSecondaryPanelsReady = ref(false);')
    || !content.includes('const dataHealthDetailPanelsReady = ref(false);')
    || !content.includes('const dataHealthEmployeePanelsReady = ref(false);')
    || !content.includes('const scheduleDataHealthSecondaryPanelsReady = (delayMs = DATA_HEALTH_SECONDARY_PANEL_DELAY_MS) => {')
    || !content.includes('const scheduleDataHealthDetailPanelsReady = (delayMs = DATA_HEALTH_DETAIL_PANEL_DELAY_MS) => {')
    || !content.includes('const scheduleDataHealthEmployeePanelsReady = (delayMs = DATA_HEALTH_EMPLOYEE_PANEL_DELAY_MS) => {')
    || !content.includes("if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthSecondaryPanelsReady.value = false;\n                    return;\n                }")
    || !content.includes("if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthDetailPanelsReady.value = false;\n                    return;\n                }")
    || !content.includes("if (currentPage.value !== 'online-data' || onlineDataTab.value !== 'data-health') {\n                    dataHealthEmployeePanelsReady.value = false;\n                    return;\n                }")
    || !content.includes("if (newTab === 'data-health') {\n                    dataHealthSecondaryPanelsReady.value = false;\n                    scheduleDataHealthSecondaryPanelsReady();")
    || !content.includes("dataHealthDetailPanelsReady.value = false;\n                    scheduleDataHealthDetailPanelsReady();")
    || !content.includes("dataHealthEmployeePanelsReady.value = false;\n                    scheduleDataHealthEmployeePanelsReady();")
    || !content.includes("if (newPage !== 'online-data') {\n                    clearDataHealthSecondaryPanelsReadyTimer();\n                    dataHealthSecondaryPanelsReady.value = false;")
    || !content.includes("clearDataHealthDetailPanelsReadyTimer();\n                    dataHealthDetailPanelsReady.value = false;")
    || !content.includes("clearDataHealthEmployeePanelsReadyTimer();\n                    dataHealthEmployeePanelsReady.value = false;")
    || !content.includes('<div v-if="dataHealthEmployeePanelsReady" data-testid="phase1-employee-six-question-summary"')
    || !content.includes('<div v-if="dataHealthSecondaryPanelsReady" data-testid="data-health-command-center"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady && !dataHealthFullDiagnosticsLoaded" data-testid="hotel-data-cockpit-pending"')
    || !content.includes('<div v-else-if="dataHealthDetailPanelsReady" data-testid="hotel-data-cockpit"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady" data-testid="data-health-drilldown"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady" data-testid="mixed-collection-lifecycle-panel"')
    || !content.includes('dataHealthSecondaryPanelsReady, dataHealthDetailPanelsReady, dataHealthEmployeePanelsReady, ctripEbookingModuleCardsReady, ctripEbookingSecondaryPanelsReady, ctripEbookingDeepPanelsReady, ctripEbookingBusinessDetailsReady, ctripEbookingDiagnosticsPanelsReady, handleCtripEbookingDiagnosticsToggle, dashboardHotelId')) {
    failures.push('public/index.html must split data-health secondary, detail, and employee diagnostic panels so manual online-data entry stays responsive.');
  }
  const autoFetchModePayloadSource = content.slice(
    content.indexOf('const buildAutoFetchModePayload = () => ({'),
    content.indexOf('const buildAutoFetchSchedulePayload = () => ({')
  );
  if (!/ctrip_auto_fetch_mode:\s*autoFetchMode\.value/.test(autoFetchModePayloadSource)
    || !/meituan_auto_fetch_mode:\s*autoFetchMode\.value/.test(autoFetchModePayloadSource)) {
    failures.push('public/index.html must keep platform auto-fetch Ctrip and Meituan modes on the selected fast mode by default.');
  }
  const onlineHistorySource = content.slice(
    content.indexOf('const loadOnlineHistory = async'),
    content.indexOf('const refreshOnlineHistory = async')
  );
  const hotelDashboardSource = content.slice(
    content.indexOf('const loadHotelDataDashboard = async'),
    content.indexOf('const DATA_HEALTH_LIGHT_CACHE_TTL_MS')
  );
  if (!dataHealthStaticContent.includes('const buildOnlineHistoryQueryParams = ({ page = 1, pageSize = 20, filter = {} } = {}) => {')
    || !dataHealthStaticContent.includes('buildOnlineHistoryQueryParams,')
    || !dataHealthStaticContent.includes('const buildHotelDataDashboardRequests = ({ selectedHotelId = \'\', days = 30 } = {}) => {')
    || !dataHealthStaticContent.includes('buildHotelDataDashboardRequests,')
    || !content.includes("const buildOnlineHistoryQueryParams = requireDataHealthStatic('buildOnlineHistoryQueryParams');")
    || !content.includes("const buildHotelDataDashboardRequests = requireDataHealthStatic('buildHotelDataDashboardRequests');")
    || !content.includes('data-health-static.js?v=20260612-dashboard-requests')
    || !onlineHistorySource.includes('const params = buildOnlineHistoryQueryParams({')
    || !hotelDashboardSource.includes('const requests = buildHotelDataDashboardRequests({ selectedHotelId });')
    || hotelDashboardSource.includes('const accountParams = new URLSearchParams();')
    || !content.includes('let onlineHistoryHotelListLoadingPromise = null;')
    || !content.includes('const onlineHistoryHotelListLoaded = ref(false);')
    || !content.includes('const refreshOnlineHistory = async (options = {}) => {')
    || !content.includes("const scheduleOnlineHistoryRefresh = () => schedulePostFetchRefresh('online-history', () => refreshOnlineHistory({ refreshHotels: false }), 340);")
    || content.includes('await Promise.all([loadOnlineHistory(), loadOnlineHistoryHotelList()]);')
    || content.includes("schedulePostFetchRefresh('online-history', () => refreshOnlineHistory(), 340)")
    || onlineHistorySource.includes('const params = new URLSearchParams({')
    || onlineHistorySource.includes("params.append('hotel_id', filter.hotel_scope);")) {
    failures.push('public/index.html must delegate online history and hotel dashboard request construction and avoid reloading hotel filters on post-fetch history refresh.');
  }
  if (/ctrip_auto_fetch_mode:\s*['"]profile_browser['"]/.test(autoFetchModePayloadSource)) {
    failures.push('public/index.html must not force platform auto-fetch Ctrip runs through browser Profile by default.');
  }
  if (!content.includes('schedulePostFetchRefresh')
    || !content.includes('scheduleOnlineDataRefresh')
    || !content.includes('scheduleOnlineHistoryRefresh')) {
    failures.push('public/index.html must keep post-fetch refreshes deferred so manual and auto collection do not block the UI.');
  }
  if (!content.includes('const ONLINE_DATA_PANEL_CACHE_TTL_MS = 8000;')
    || !content.includes('const ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS = 30000;')
    || !content.includes('const HOTEL_LIST_CACHE_TTL_MS = 30000;')
    || !content.includes('const hotelListResultCache = new Map();')
    || !content.includes('readRequestCache(hotelListResultCache, requestKey, cacheMs)')
    || !content.includes('const scheduleStartupHotelListLoad = (delayMs = null) => {')
    || !content.includes('if (!hasKnownHotelOptions()) {')
    || !content.includes('return loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS });')
    || !content.includes('if (!isLoggedIn.value || !token.value || isCoreOtaPageVisible()) return null;')
    || !content.includes('scheduleStartupHotelListLoad();')
    || !content.includes('const onlineDataListRequestPromises = new Map();')
    || !content.includes('const onlineDataSummaryRequestPromises = new Map();')
    || !content.includes('const onlineDataHotelListRequestPromises = new Map();')
    || !content.includes('const onlineDataListResultCache = new Map();')
    || !content.includes('const onlineDataSummaryResultCache = new Map();')
    || !content.includes('const onlineDataHotelListResultCache = new Map();')
    || !content.includes('refreshOnlineData({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS });')
    || !content.includes('loadOnlineDataList({ cacheMs: ONLINE_DATA_PANEL_CACHE_TTL_MS })')
    || !content.includes('loadOnlineDataHotelList({ cacheMs: ONLINE_DATA_HOTEL_LIST_CACHE_TTL_MS })')
    || !content.includes('const scheduleOnlineDataRefresh = () => schedulePostFetchRefresh(\'online-data-list\', () => refreshOnlineData({ force: true }), 260);')
    || !content.includes('@click="refreshOnlineData({ force: true })"')
    || !content.includes('@click="loadOnlineDataList({ force: true })"')) {
    failures.push('public/index.html must deduplicate online-data list/summary/hotel reads for tab switching while keeping manual query and post-fetch refreshes forced.');
  }
  if (!content.includes('const ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS = 8000;')
    || !content.includes('const onlineAnalysisDataResultCache = new Map();')
    || !content.includes('const onlineAnalysisRowsResultCache = new Map();')
    || !content.includes('const onlineAnalysisDataRequestPromises = new Map();')
    || !content.includes('const onlineAnalysisRowsRequestPromises = new Map();')
    || !content.includes('const clearOnlineAnalysisReadCaches = () => {')
    || !content.includes('const loadAnalysisData = async (dimension = null, options = {}) => {')
    || !content.includes('const loadOnlineAnalysisRows = async (options = {}) => {')
    || !/const loadAnalysisData = async \(dimension = null, options = \{\}\) => \{[\s\S]*readOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, cacheMs\)[\s\S]*onlineAnalysisDataRequestPromises\.has\(requestKey\)[\s\S]*request\(`\/online-data\/data-analysis\?\$\{params\}`\)[\s\S]*writeOnlineAnalysisResultCache\(onlineAnalysisDataResultCache, requestKey, data, cacheMs\)/.test(content)
    || !/const loadOnlineAnalysisRows = async \(options = \{\}\) => \{[\s\S]*readOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, cacheMs\)[\s\S]*onlineAnalysisRowsRequestPromises\.has\(requestKey\)[\s\S]*request\(`\/online-data\/daily-data-list\?\$\{params\}`\)[\s\S]*writeOnlineAnalysisResultCache\(onlineAnalysisRowsResultCache, requestKey, data, cacheMs\)/.test(content)
    || !content.includes('const refreshOnlineAnalysis = async (options = {}) => {')
    || !content.includes('cacheMs: ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS,')
    || !content.includes('loadAnalysisData(null, loadOptions),')
    || !content.includes('loadOnlineDataSummary(loadOptions),')
    || !content.includes('loadOnlineAnalysisRows(loadOptions),')
    || !content.includes('return refreshOnlineAnalysis(options);')
    || !content.includes('@click="loadOnlineAnalysisRows({ force: true })"')
    || !content.includes('clearOnlineAnalysisReadCaches();')) {
    failures.push('public/index.html online-data analysis tab must short-cache and deduplicate analysis summary/detail reads while preserving forced manual refresh.');
  }
  const startupLoadDataStart = content.indexOf('const loadData = async () => {');
  const startupLoadDataEnd = content.indexOf('\n\n            //', startupLoadDataStart);
  const startupLoadDataSource = startupLoadDataStart >= 0 && startupLoadDataEnd > startupLoadDataStart
    ? content.slice(startupLoadDataStart, startupLoadDataEnd)
    : '';
  if (!startupLoadDataSource.includes('scheduleStartupHotelListLoad();')
    || startupLoadDataSource.includes('loadHotels({ cacheMs: HOTEL_LIST_CACHE_TTL_MS });')) {
    failures.push('public/index.html login startup must schedule the full hotel list instead of requesting /hotels/all on first paint.');
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
  if (!content.includes("const isDataHealthPanelVisible = () => ['online-data', 'ctrip-ebooking'].includes(currentPage.value) && onlineDataTab.value === 'data-health';")
    || !content.includes("const scheduleDataHealthPanelRefresh = (mode = 'light', params = {}) => schedulePostFetchRefresh('data-health-panel', () => {")
    || !content.includes('if (!isDataHealthPanelVisible()) return null;')
    || content.includes("const scheduleDataHealthPanelRefresh = (mode = 'light', params = {}) => schedulePostFetchRefresh('data-health-panel', () => loadDataHealthPanel(mode, params), 560);")) {
    failures.push('public/index.html post-fetch data-health refreshes must not run after the user leaves the visible data-health tab.');
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
  const ctripAcceptedHelperMatches = ctripStaticContent.match(/const\s+isCtripBackgroundAcceptedResponse\s*=/g) || [];
  if (ctripAcceptedHelperMatches.length !== 1) {
    failures.push('public/ctrip-static.js must define one shared Ctrip accepted/running/queued response helper.');
  }
  const rankingFlowStart = ctripStaticContent.indexOf('const runCtripFetchDataFlow = async');
  const trafficFlowStart = ctripStaticContent.indexOf('const runCtripTrafficFetchFlow = async');
  const ctripRankingFlowSource = rankingFlowStart >= 0 && trafficFlowStart > rankingFlowStart
    ? ctripStaticContent.slice(rankingFlowStart, trafficFlowStart)
    : '';
  if (!/\{\s*\.\.\.requestContext\.requestBody,\s*async:\s*false,\s*background:\s*false\s*\}/.test(ctripRankingFlowSource)
    || /\{\s*\.\.\.requestContext\.requestBody,\s*async:\s*true\s*\}/.test(ctripRankingFlowSource)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody/.test(ctripRankingFlowSource)) {
    failures.push('public/ctrip-static.js Ctrip ranking manual fetch must request direct results while keeping defensive queued-state handling.');
  }
  const adsFlowStart = ctripStaticContent.indexOf('const runCtripAdsFetchFlow = async');
  const ctripTrafficFlowSource = trafficFlowStart >= 0 && adsFlowStart > trafficFlowStart
    ? ctripStaticContent.slice(trafficFlowStart, adsFlowStart)
    : '';
  const ctripAdsFlowSource = adsFlowStart >= 0
    ? ctripStaticContent.slice(adsFlowStart, ctripStaticContent.indexOf('const createCtripAdsState', adsFlowStart) > adsFlowStart
      ? ctripStaticContent.indexOf('const createCtripAdsState', adsFlowStart)
      : ctripStaticContent.length)
    : '';
  if (!/const\s+directRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*false,\s*background:\s*false\s*\};/.test(ctripTrafficFlowSource)
    || /const\s+queuedRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*true\s*\};/.test(ctripTrafficFlowSource)
    || !/isCtripBackgroundAcceptedResponse\(res\)/.test(ctripTrafficFlowSource)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*directRequestBody/.test(ctripTrafficFlowSource)) {
    failures.push('public/ctrip-static.js must request direct Ctrip traffic manual results and keep running responses explicit if returned.');
  }
  if (!/const\s+directRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*false,\s*background:\s*false\s*\};/.test(ctripAdsFlowSource)
    || /const\s+queuedRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*true\s*\};/.test(ctripAdsFlowSource)
    || !/isCtripBackgroundAcceptedResponse\(res\)/.test(ctripAdsFlowSource)
    || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*directRequestBody/.test(ctripAdsFlowSource)) {
    failures.push('public/ctrip-static.js must request direct Ctrip ads manual results and keep running responses explicit if returned.');
  }
  if (!/\{\s*\.\.\.task\.body,\s*async:\s*false,\s*background:\s*false\s*\}/.test(meituanStaticContent)
    || !/await\s+Promise\.all\(fetchTasks\.map\(async\s+\(task,\s*index\)\s*=>\s*\{/.test(meituanStaticContent)
    || /\{\s*\.\.\.task\.body,\s*async:\s*true,\s*background:\s*true\s*\}/.test(meituanStaticContent)
    || !/const\s+modelRes\s*=\s*await\s+requestDisplayModel/.test(meituanStaticContent)) {
    failures.push('public/meituan-static.js must request Meituan ranking direct results concurrently and build the display model from returned data.');
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
    if (!/const\s+directRequestBody\s*=\s*\{\s*\.\.\.requestBody,\s*async:\s*false,\s*background:\s*false\s*\};/.test(source)
      || !/isMeituanBackgroundAcceptedResponse\(res\)/.test(source)
      || !/return\s+\{\s*status:\s*['"]accepted['"][\s\S]*requestBody:\s*directRequestBody/.test(source)) {
      failures.push(`public/meituan-static.js must request Meituan ${label} manual results directly and keep running responses explicit if returned.`);
    }
  }
  const controllerPath = path.join(repoRoot, 'app/controller/OnlineData.php');
  const controllerContent = fs.existsSync(controllerPath) ? fs.readFileSync(controllerPath, 'utf8') : '';
  const manualTaskServicePath = path.join(repoRoot, 'app/service/ManualOnlineFetchTaskService.php');
  const manualTaskServiceContent = fs.existsSync(manualTaskServicePath) ? fs.readFileSync(manualTaskServicePath, 'utf8') : '';
  if (!controllerContent.includes("get('include_detail'") || !controllerContent.includes("'detail_loaded' => false")) {
    failures.push('app/controller/OnlineData.php must support light auto-fetch status with explicit detail_loaded=false.');
  }
  const lightStatusMatch = controllerContent.match(/\} else \{\s+\$status\['missed_dates'\] = \[\];\s+\$status\['missed_count'\] = null;([\s\S]*?)\$status\['detail_loaded'\] = false;/);
  const lightStatusBranch = lightStatusMatch ? lightStatusMatch[1] : '';
  if (!controllerContent.includes('private function buildAutoFetchPlatformLightStatus')
    || !lightStatusBranch.includes('buildAutoFetchPlatformLightStatus')
    || lightStatusBranch.includes('hasAnyPlatformFetchConfigForHotel')
    || lightStatusBranch.includes('buildAutoFetchPlatformStatus')) {
    failures.push('app/controller/OnlineData.php light auto-fetch status must not run full config/profile diagnostics.');
  }
  const lightHelperMatch = controllerContent.match(/private function buildAutoFetchPlatformLightStatus\(int \$hotelId, array \$status\): array\s+\{([\s\S]*?)\n    private function autoFetchPlatformsHaveConfig/);
  const lightHelperSource = lightHelperMatch ? lightHelperMatch[1] : '';
  if (!lightHelperSource.includes('resolveCtripFetchConfigForHotelLight')
    || !lightHelperSource.includes('resolveMeituanFetchConfigForHotelLight')
    || lightHelperSource.includes('resolveCtripFetchConfigForHotel($hotelId)')
    || lightHelperSource.includes('resolveMeituanFetchConfigForHotel($hotelId)')
    || !controllerContent.includes('private function getStoredCtripConfigListRaw')
    || !controllerContent.includes('private function getStoredMeituanConfigListRaw')) {
    failures.push('app/controller/OnlineData.php light auto-fetch platform status must use read-only raw config resolvers.');
  }
  if (!controllerContent.includes('private const AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS = 5;')
    || !controllerContent.includes('private array $autoFetchLightReadCache = [];')
    || !controllerContent.includes('readAutoFetchLightReadCache($cacheKey)')
    || !controllerContent.includes('writeAutoFetchLightReadCache($cacheKey, $list)')
    || !controllerContent.includes('writeAutoFetchLightReadCache($cacheKey, array_values(array_filter($rows, \'is_array\')))')) {
    failures.push('app/controller/OnlineData.php light auto-fetch status must short-cache config-list and browser-profile source reads.');
  }
  if (!controllerContent.includes("clearAutoFetchLightConfigListCache('ctrip')")
    || !controllerContent.includes("clearAutoFetchLightConfigListCache('meituan')")
    || !controllerContent.includes('clearAutoFetchLightProfileSourcesCache((int)($data[\'system_hotel_id\'] ?? 0)')
    || !controllerContent.includes('clearAutoFetchLightProfileSourcesCache($hotelId, $platform)')) {
    failures.push('app/controller/OnlineData.php must clear light auto-fetch read caches after config/source mutations.');
  }
  if (!controllerContent.includes("'/api/online-data/retry-auto-fetch'")
    || !controllerContent.includes("'retry_auto_fetch_queued'")
    || !controllerContent.includes("'background_task' => true")) {
    failures.push('app/controller/OnlineData.php must submit retry auto-fetch through the one-shot background worker instead of blocking the request.');
  }
  if (!manualTaskServiceContent.includes('final class ManualOnlineFetchTaskService')
    || !manualTaskServiceContent.includes('online-data:manual-fetch-once')
    || !manualTaskServiceContent.includes('launchWindowsBatchFile($batPath)')
    || !manualTaskServiceContent.includes('launchWindowsScriptHost($launcherPath)')
    || !manualTaskServiceContent.includes('launchWindowsBatchFileWithStart($batPath)')
    || !manualTaskServiceContent.includes('appendWindowsLauncherDiagnostic($batPath')
    || manualTaskServiceContent.includes('powershell.exe -NoProfile -ExecutionPolicy Bypass -EncodedCommand')
    || !controllerContent.includes("createTask('ctrip'")
    || !controllerContent.includes("createTask(strtolower($platform) . '_traffic'")
    || !controllerContent.includes("createTask('ctrip_ads'")
    || !controllerContent.includes('launchTask($task)')
    || !controllerContent.includes('launchWindowsBatchFile($batPath)')
    || !controllerContent.includes('launchWindowsScriptHost($launcherPath)')
    || !controllerContent.includes('launchWindowsBatchFileWithStart($batPath)')
    || !controllerContent.includes('appendWindowsLauncherDiagnostic($batPath')
    || controllerContent.includes('private function createManualCtripFetchBackgroundTask')
    || controllerContent.includes('private function launchManualCtripFetchBackgroundTask')) {
    failures.push('app/controller/OnlineData.php must use ManualOnlineFetchTaskService for Ctrip manual fetch background task support and keep Windows launch on the confirmed VBS path with cmd-start fallback diagnostics.');
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
  if (!operationStaticContent.includes('buildOpeningTaskProgressCards')
    || !operationStaticContent.includes('buildOpeningTaskProgressStages')
    || !operationStaticContent.includes('buildOpeningCategoryProgressCards')
    || !operationStaticContent.includes('buildOpeningPositioningImpact')
    || !operationStaticContent.includes('buildOpeningStatusFilterChips')
    || !operationStaticContent.includes('buildOpeningAttentionFilterChips')
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningCategoryProgressCards')")
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningPositioningImpact')")
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningTaskProgressCards')")
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningTaskProgressStages')")
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningStatusFilterChips')")
    || !content.includes("requireOperationStatic(staticConfig, 'buildOpeningAttentionFilterChips')")
    || !content.includes('buildOpeningCategoryProgressCards(openingOverview.value?.category_progress || [])')
    || !content.includes('buildOpeningPositioningImpact(openingProjectForm.value.positioning)')
    || !content.includes('buildOpeningTaskProgressCards(openingTaskStats.value)')
    || !content.includes('buildOpeningTaskProgressStages(openingTaskStats.value)')
    || !content.includes('buildOpeningStatusFilterChips(openingTaskStats.value)')
    || !content.includes('buildOpeningAttentionFilterChips(openingTaskStats.value)')
    || content.includes("status: '待生成'")
    || content.includes("items: ['房价体系', 'OTA卖点', '物资标准', '培训话术']")
    || content.includes("label: '任务进度均值'")
    || content.includes("label: '1%-49%'")
    || content.includes("activeClass: 'bg-gray-900 text-white border-gray-900'")
    || content.includes("value: 'dueSoon', label: '7天内到期'")) {
    failures.push('public/index.html must delegate opening display models to public/operation-static.js.');
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
  if (!/const\s+pageControlTestIdsEnabledForShell\s*=\s*\(\)\s*=>\s*\{/.test(content)
    || !content.includes("if (params.get('testids') === '1' || params.get('e2e') === '1') return true;")
    || !content.includes("return localStorage.getItem('enablePageTestIds') === '1';")
    || content.includes("host === 'localhost' || host === '127.0.0.1' || host === '::1'")) {
    failures.push('public/index.html must only load page-control test ids by explicit opt-in, not ordinary localhost startup.');
  }
  if (/\b(?:fab|far)\s+fa-/.test(content)) {
    failures.push('public/index.html must avoid FontAwesome brands/regular icon classes in the SPA entry because they trigger extra webfont downloads on core OTA pages.');
  }
  if (!content.includes('const SYSTEM_CONFIG_PUBLIC_CACHE_TTL_MS = 60 * 1000;')
    || !content.includes('let systemConfigPublicLoadPromise = null;')
    || !content.includes('const schedulePublicSystemConfigRefresh = (delayMs = 1800) => {')
    || !content.includes('if (isCoreOtaPageVisible()) return undefined;')
    || !content.includes('schedulePublicSystemConfigRefresh(1800);')
    || content.includes('deferUiTask(() => loadSystemConfig({ publicOnly: true }), 120)')) {
    failures.push('public/index.html must defer, deduplicate, and short-cache public system-config refreshes away from core OTA page switching.');
  }
  if (!/let\s+pageControlTestIdObserverTimer\s*=\s*null;/.test(content)
    || !/const\s+schedulePageControlTestIdObserverStart\s*=\s*\(delayMs\s*=\s*520\)\s*=>\s*\{[\s\S]*deferUiTask\(\(\)\s*=>\s*\{[\s\S]*startPageControlTestIdObserver\(\);[\s\S]*scheduleTestIdRefresh\(\);/.test(content)
    || !content.includes('const observerDelay = isCoreOtaPageVisible() ? Math.max(normalizedDelay, 1800) : normalizedDelay;')
    || !/watch\(currentPage,\s*\(newPage\)\s*=>\s*\{[\s\S]*schedulePageControlTestIdObserverStart\(520\);/.test(content)
    || !/watch\(isLoggedIn,\s*\(loggedIn\)\s*=>\s*\{[\s\S]*schedulePageControlTestIdObserverStart\(700\);/.test(content)) {
    failures.push('public/index.html must defer page-control test id observer startup so page switching and login remain responsive.');
  }
  if (!/watch\(onlineDataTab,\s*\(newTab\)\s*=>\s*\{[\s\S]*schedulePageControlTestIdObserverStart\(1800\);/.test(content)) {
    failures.push('public/index.html must reset page-control test id observer delay when switching online-data tabs.');
  }
  if (!/const\s+pageTestId\s*=\s*\(page\)\s*=>/.test(content)
    || !/const\s+menuTestId\s*=\s*\(item\)\s*=>/.test(content)
    || !/createPageTestIdController/.test(content)) {
    failures.push('public/index.html must keep page/menu test ids available before lazy-loading the page-control test id controller.');
  }

  if (/<script\s+src=["']form-operation-support\.js["']/.test(content)
    || !content.includes("const formOperationSupportScript = 'form-operation-support.js';")
    || !content.includes('const scheduleFormOperationSupportLoad = (delayMs = null) => {')
    || !content.includes("const shouldDeferFormOperationSupportLoad = () => currentPage.value === 'compass' || isCoreOtaPageVisible();")
    || !content.includes('const pageDelay = shouldDeferFormOperationSupportLoad() ? 6400 : 5200;')
    || !content.includes('if (shouldDeferFormOperationSupportLoad()) return;')
    || !content.includes('scheduleFormOperationSupportLoad();')) {
    failures.push('public/index.html must lazy-load form-operation-support.js after the first core OTA interaction window.');
  }
  if (!/const renderHomeTrendChart = \(retryCount = 0\) => \{\n\s+if \(!homeTrendHasSamples\.value\) \{\n\s+destroyHomeTrendChart\(\);\n\s+return;\n\s+\}\n\s+const ChartLib = window\.Chart;/.test(content)) {
    failures.push('public/index.html must not load Chart.js for the home trend chart before confirming there are usable trend samples.');
  }
  if (!/data-testid=\\?"ctrip-profile-field-modal\\?"/.test(ctripProfileFieldConfigPanelContent)) {
    failures.push('public/components/online-data/ctrip-profile-field-config-panel.js must keep the Ctrip profile-field modal marker in the lazy component.');
  }

  const vueBoundaryMarkers = [
    { name: 'Ctrip Profile field component', marker: '<ctrip-profile-field-config-panel' },
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
  if (!routerSource.includes("'runtime' . DIRECTORY_SEPARATOR . 'static-html'")) {
    failures.push('public/router.php must cache the trimmed index.html response under runtime/static-html.');
  }
  if (!routerSource.includes('index-indent-trim-v3') || !routerSource.includes('suxi_trim_index_html_indent')) {
    failures.push('public/router.php must keep the index.html indentation-trim response variant explicit.');
  }
  if (!routerSource.includes("preg_split('/(\\r\\n|\\n|\\r)/'") || !routerSource.includes('$rawTag = null')) {
    failures.push('public/router.php index.html trimming must use a line scanner instead of a whole-file regex.');
  }
  if (!routerSource.includes('/<(script|style|textarea|pre)\\b/i') || !routerSource.includes("'</' . $rawTag . '>'")) {
    failures.push('public/router.php index.html trimming must preserve script/style/textarea/pre regions.');
  }
  if (!routerSource.includes("preg_replace('/^[ \\t]+(?=<)/'")) {
    failures.push('public/router.php index.html trimming must only remove line indentation before tags.');
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
  if (!routerSource.includes("gzencode($responseContent ?? '', 1)")) {
    failures.push('public/router.php must use gzip level 1 on the prepared static response payload when refreshing the static gzip cache.');
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Public entry guard passed.');
