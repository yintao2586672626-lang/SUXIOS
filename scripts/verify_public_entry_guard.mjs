import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { inspectFrontendEntryBuild } from './lib/frontend_entry_build.mjs';
import { inspectTailwindRuntimeBuild } from './lib/frontend_tailwind_build.mjs';
import { inspectFrontendTemplateBuild } from './lib/frontend_template_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const indexPath = path.join(repoRoot, 'public/index.html');
const appMainPath = path.join(repoRoot, 'public/app-main.js');
const appMainRuntimePath = path.join(repoRoot, 'public/app-main.min.js');
const appTemplatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const appRenderRuntimePath = path.join(repoRoot, 'public/app-render.min.js');
const publicRouterPath = path.join(repoRoot, 'public/router.php');
const systemStaticPath = path.join(repoRoot, 'public/system-static.js');
const revenueAiStaticPath = path.join(repoRoot, 'public/revenue-ai-static.js');
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
  const htmlContent = fs.readFileSync(indexPath, 'utf8');
  const appMainContent = fs.existsSync(appMainPath) ? fs.readFileSync(appMainPath, 'utf8') : '';
  const appMainRuntimeContent = fs.existsSync(appMainRuntimePath) ? fs.readFileSync(appMainRuntimePath, 'utf8') : '';
  const appTemplateContent = fs.existsSync(appTemplatePath) ? fs.readFileSync(appTemplatePath, 'utf8') : '';
  const appRenderRuntimeContent = fs.existsSync(appRenderRuntimePath) ? fs.readFileSync(appRenderRuntimePath, 'utf8') : '';
  const appTemplateSemanticContent = appTemplateContent
    .replaceAll('&amp;', '&')
    .replaceAll('&gt;', '>')
    .replaceAll('&lt;', '<')
    .replaceAll('&quot;', '"');
  let content = `${htmlContent}\n${appTemplateSemanticContent}\n${appMainContent}`;
  const systemStaticContent = fs.existsSync(systemStaticPath) ? fs.readFileSync(systemStaticPath, 'utf8') : '';
  const revenueAiStaticContent = fs.existsSync(revenueAiStaticPath) ? fs.readFileSync(revenueAiStaticPath, 'utf8') : '';
  const revenueAiServicePath = path.join(repoRoot, 'app/service/RevenueAiOverviewService.php');
  const revenueAiServiceContent = fs.existsSync(revenueAiServicePath) ? fs.readFileSync(revenueAiServicePath, 'utf8') : '';
  const operationStaticContent = fs.existsSync(operationStaticPath) ? fs.readFileSync(operationStaticPath, 'utf8') : '';
  const ctripStaticPath = path.join(repoRoot, 'public/ctrip-static.js');
  const ctripStaticContent = fs.existsSync(ctripStaticPath) ? fs.readFileSync(ctripStaticPath, 'utf8') : '';
  if (ctripStaticContent.includes('const buildCtripBookmarkletSuccessState = (response = {}) => ({')
    && ctripStaticContent.includes("toastMessage: response?.data?.message || '旧版携程 Cookie 书签已禁用'")
    && content.includes('const successState = buildCtripBookmarkletSuccessState(res);')) {
    content += "\nshowToast(res.data?.message || '旧版携程 Cookie 书签已禁用', 'warning')";
  }
  const meituanStaticPath = path.join(repoRoot, 'public/meituan-static.js');
  const meituanStaticContent = fs.existsSync(meituanStaticPath) ? fs.readFileSync(meituanStaticPath, 'utf8') : '';
  if (meituanStaticContent.includes('const buildMeituanBookmarkletSuccessState = (response = {}) => ({')
    && meituanStaticContent.includes("toastMessage: response?.data?.message || '旧版美团 Cookie 书签已禁用'")
    && content.includes('const successState = buildMeituanBookmarkletSuccessState(res);')) {
    content += "\nshowToast(res.data?.message || '旧版美团 Cookie 书签已禁用', 'warning')";
  }
  const dataHealthStaticPath = path.join(repoRoot, 'public/data-health-static.js');
  const dataHealthStaticContent = fs.existsSync(dataHealthStaticPath) ? fs.readFileSync(dataHealthStaticPath, 'utf8') : '';
  const homeStaticPath = path.join(repoRoot, 'public/home-static.js');
  const homeStaticContent = fs.existsSync(homeStaticPath) ? fs.readFileSync(homeStaticPath, 'utf8') : '';
  const autoFetchStaticPath = path.join(repoRoot, 'public/auto-fetch-static.js');
  const autoFetchStaticContent = fs.existsSync(autoFetchStaticPath) ? fs.readFileSync(autoFetchStaticPath, 'utf8') : '';
  const platformAutoSettingsPanelsPath = path.join(repoRoot, 'public/components/online-data/platform-auto-settings-panels.js');
  const platformAutoSettingsPanelsContent = fs.existsSync(platformAutoSettingsPanelsPath)
    ? fs.readFileSync(platformAutoSettingsPanelsPath, 'utf8')
    : '';
  const ctripProfileFieldConfigPanelPath = path.join(repoRoot, 'public/components/online-data/ctrip-profile-field-config-panel.js');
  const ctripProfileFieldConfigPanelContent = fs.existsSync(ctripProfileFieldConfigPanelPath)
    ? fs.readFileSync(ctripProfileFieldConfigPanelPath, 'utf8')
    : '';

  if (stat.size < 5_000) {
    failures.push(`public/index.html is too small (${stat.size} bytes). It may have been overwritten by a frontend build.`);
  }
  if (Buffer.byteLength(appTemplateContent) < 1_000_000) {
    failures.push('resources/frontend/app-template.html is missing or unexpectedly small.');
  }
  if (!appMainContent) {
    failures.push('public/app-main.js is missing or empty.');
  }
  if (!appMainRuntimeContent) {
    failures.push('public/app-main.min.js is missing or empty.');
  }
  if (!appRenderRuntimeContent) {
    failures.push('public/app-render.min.js is missing or empty.');
  }
  if (/const\s+suxiApp\s*=\s*createApp\(/.test(htmlContent)) {
    failures.push('public/index.html must not inline the main Vue bootstrap after entry externalization.');
  }
  if (!/const\s+suxiApp\s*=\s*createApp\(/.test(appMainContent)) {
    failures.push('public/app-main.js must contain the main Vue bootstrap.');
  }
  if (appMainContent && appMainRuntimeContent) {
    const buildInspection = await inspectFrontendEntryBuild({
      source: appMainContent,
      artifact: appMainRuntimeContent,
      html: htmlContent,
    });
    failures.push(...buildInspection.failures);
  }
  const tailwindBuildInspection = await inspectTailwindRuntimeBuild(repoRoot);
  failures.push(...tailwindBuildInspection.failures);
  const templateBuildInspection = await inspectFrontendTemplateBuild(repoRoot);
  failures.push(...templateBuildInspection.failures);
  const appMainReference = htmlContent.match(/<script\s+defer\s+src="app-main\.min\.js\?v=[^"]*-h([a-f0-9]{10})"[^>]*><\/script>/);
  const appMainHash = appMainRuntimeContent
    ? crypto.createHash('sha256').update(appMainRuntimeContent).digest('hex').slice(0, 10)
    : '';
  if (!appMainReference || appMainReference[1] !== appMainHash) {
    failures.push('public/index.html must use the current public/app-main.min.js content hash in its immutable cache version.');
  }
  const deferredScripts = [...htmlContent.matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1].split('?')[0]);
  if (deferredScripts[0] !== 'vue.runtime.global.prod.js'
    || deferredScripts.at(-2) !== 'app-render.min.js'
    || deferredScripts.at(-1) !== 'app-main.min.js') {
    failures.push('public/index.html must keep runtime Vue first, the precompiled render before app-main, and app-main last.');
  }

if (!/<script\s+(?:defer\s+)?src=["']system-static\.js\?v=[^"']+["']><\/script>/.test(content)
    || !systemStaticContent.includes('const getHotelCodeNumber = (code) => {')
    || !systemStaticContent.includes('const formatHotelCode = (num) =>')
    || !systemStaticContent.includes('const normalizeOtaConfigHotelName = (value = \'\') =>')
    || !systemStaticContent.includes('const formatHotelBindingDate = (value) => {')
    || !content.includes("const getHotelCodeNumber = requireAppSystemStatic('getHotelCodeNumber');")
    || !content.includes("const formatHotelCode = requireAppSystemStatic('formatHotelCode');")
    || !content.includes("const normalizeOtaConfigHotelName = requireAppSystemStatic('normalizeOtaConfigHotelName');")
    || !content.includes("const formatHotelBindingDate = requireAppSystemStatic('formatHotelBindingDate');")
    || content.includes('const getHotelCodeNumber = (code) => {')
    || content.includes('const formatHotelCode = (num) => {')
    || content.includes('const normalizeOtaConfigHotelName = (value = \'\') => String(value || \'\')')
    || content.includes('const formatHotelBindingDate = (value) => {')) {
    failures.push('public/index.html must delegate hotel code, OTA config hotel-name normalization, and binding-date formatting to public/system-static.js.');
  }

  const requiredMarkers = [
    { name: 'Vue mount root', pattern: /id=["']app["']/ },
    { name: 'local Vue runtime', pattern: /vue\.runtime\.global\.prod\.js/ },
    { name: 'local Tailwind stylesheet', pattern: /tailwind\.min\.css/ },
    { name: 'application stylesheet', pattern: /style\.css/ },
    { name: 'Vue app bootstrap', pattern: /createApp|Vue\.createApp/ },
  ];

  for (const marker of requiredMarkers) {
    if (!marker.pattern.test(content)) {
      failures.push(`public/index.html missing marker: ${marker.name}`);
    }
  }

  const ctripStaticVersionMatch = htmlContent.match(/<script\s+(?:defer\s+)?src="ctrip-static\.js\?v=([^"]+)"/);
  const ctripStaticHash = ctripStaticContent
    ? crypto.createHash('sha256').update(ctripStaticContent).digest('hex').slice(0, 10)
    : '';
  const meituanStaticVersionMatch = htmlContent.match(/<script\s+(?:defer\s+)?src="meituan-static\.js\?v=([^"]+)"/);
  const meituanStaticHash = meituanStaticContent
    ? crypto.createHash('sha256').update(meituanStaticContent).digest('hex').slice(0, 10)
    : '';
  const dataHealthStaticVersionMatch = htmlContent.match(/<script\s+(?:defer\s+)?src="data-health-static\.js\?v=([^"]+)"/);
  const dataHealthStaticHash = dataHealthStaticContent
    ? crypto.createHash('sha256').update(dataHealthStaticContent).digest('hex').slice(0, 10)
    : '';
  if (!ctripStaticVersionMatch
    || !ctripStaticVersionMatch[1].includes(`h${ctripStaticHash}`)
    || !meituanStaticVersionMatch
    || !meituanStaticVersionMatch[1].includes(`h${meituanStaticHash}`)
    || !dataHealthStaticVersionMatch
    || !dataHealthStaticVersionMatch[1].includes(`h${dataHealthStaticHash}`)) {
    failures.push('public/index.html must keep static helper cache versions aligned with changed helper files.');
  }
  try {
    const requiredMeituanStaticKeys = [...new Set(
      [...content.matchAll(/requireMeituanStatic\('([^']+)'\)/g)].map(match => match[1])
    )].sort();
    const meituanStaticSandbox = { window: {}, console, URLSearchParams };
    vm.runInNewContext(meituanStaticContent, meituanStaticSandbox, { filename: 'public/meituan-static.js' });
    const meituanStaticApi = meituanStaticSandbox.window.SUXI_MEITUAN_STATIC || {};
    const missingMeituanStaticKeys = requiredMeituanStaticKeys
      .filter(key => typeof meituanStaticApi[key] !== 'function');
    if (missingMeituanStaticKeys.length) {
      failures.push(`public/index.html requires Meituan static helpers that are not exported by public/meituan-static.js: ${missingMeituanStaticKeys.join(', ')}.`);
    }
  } catch (error) {
    failures.push(`public/meituan-static.js export contract could not be evaluated: ${error.message}`);
  }
  if (!content.includes('window.SUXI_MISSING_MEITUAN_STATIC_HELPERS = missingMeituanStaticHelpers')
    || !content.includes('return meituanStaticFallbackFor(key)')
    || content.includes('throw new Error(`缺少美团静态展示工具项：${key}`)')) {
    failures.push('public/index.html must not block whole-app startup when a Meituan static helper is missing; it must record the missing helper and degrade the related Meituan feature only.');
  }
  const forbiddenServerLoginCopy = [
    ['public/index.html', content, '上一次登录任务未继续执行，可重新触发登录'],
    ['public/index.html', content, '平台登录失败，请重新登录平台账号'],
    ['public/index.html', content, '请填写携程登录会话标识，或先绑定携程登录会话数据源'],
    ['public/index.html', content, '请填写美团门店标识，或先绑定美团登录会话数据源'],
    ['public/index.html', content, '未配置，请触发携程登录'],
    ['public/index.html', content, '未配置，请触发美团登录'],
    ['public/index.html', content, '平台登录已失效，请重新登录后再采集。'],
    ['public/index.html', content, '重新登录平台并更新 Cookie。'],
    ['public/index.html', content, '触发登录始终打开可见浏览器'],
    ['public/auto-fetch-static.js', autoFetchStaticContent, '检查服务器/定时任务运行账号是否允许启动浏览器'],
    ['public/auto-fetch-static.js', autoFetchStaticContent, '登录任务异常，请重新触发登录并保留失败原因'],
    ['public/components/online-data/platform-auto-settings-panels.js', platformAutoSettingsPanelsContent, '触发登录始终打开可见浏览器'],
  ];
  for (const [file, source, text] of forbiddenServerLoginCopy) {
    if (source.includes(text)) {
      failures.push(`${file} must keep OTA authorization copy on account-owner local-computer authorization and must not contain legacy server/login-task wording: ${text}`);
    }
  }
  if (!content.includes("const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260712-meituan-ads-not-applicable';")
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
    || !content.includes('let recoverSuxiRuntimeError = null;')
    || !content.includes('recoverSuxiRuntimeError = ({ error, info }) => {')
    || !content.includes('const isFatalStartupError = /setup function|app errorHandler|app warnHandler|app unmount cleanup function/i.test')
    || !content.includes("currentPage.value = 'compass';")
    || !content.includes('当前功能发生异常，已返回今日经营看板')
    || !content.includes('suxiApp.config.errorHandler = (error, _instance, info) => {')
    || !content.includes("recovered = typeof recoverSuxiRuntimeError === 'function'")
    || !content.includes('if (recovered) return;')
    || !content.includes("suxiApp.mount('#app');")) {
    failures.push('public/index.html must isolate recoverable Vue runtime errors while preserving an explicit fatal startup surface.');
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
  const ctripFuturePanelStart = content.indexOf('data-testid="ctrip-search-opportunity-panel"');
  const ctripFuturePanelEnd = content.indexOf("onlineDataTab === 'ctrip-ads'", ctripFuturePanelStart);
  if (ctripFuturePanelStart >= 0 && ctripFuturePanelEnd > ctripFuturePanelStart) {
    const ctripFuturePanel = content.slice(ctripFuturePanelStart, ctripFuturePanelEnd);
    const startupNullableBindings = [
      'ctripSearchOpportunityView',
      'ctripSearchOpportunityActiveRange',
      'ctripSearchOpportunityHorizonSummary',
    ];
    for (const binding of startupNullableBindings) {
      if (new RegExp(`\\b${binding}\\.`).test(ctripFuturePanel)) {
        failures.push(`public/index.html future-search panel must optional-chain startup-nullable binding ${binding} before reading its fields.`);
      }
    }
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
  if (!/<script\s+(?:defer\s+)?src=["']vue\.runtime\.global\.prod\.js\?v=[^"']+["'][^>]*><\/script>/.test(htmlContent)
    || !/<script\s+(?:defer\s+)?src=["']system-static\.js\?v=[^"']+["']><\/script>/.test(htmlContent)) {
    failures.push('public/index.html must version core Vue/system static scripts so P0 entry fixes are not hidden by stale browser cache.');
  }

  if (/\/assets\/index-[A-Za-z0-9_-]+\.(?:js|css)/.test(content)) {
    failures.push('public/index.html references Vite hashed assets; do not build Vite into HOTEL/public.');
  }

  const tailwindMatch = htmlContent.match(/<link\s+href=["']tailwind\.min\.css\?v=[^"']+["']\s+rel=["']stylesheet["']>/);
  const tailwindOffset = tailwindMatch ? tailwindMatch.index : -1;
  const vueScriptMatch = htmlContent.match(/<script\s+(?:defer\s+)?src=["']vue\.runtime\.global\.prod\.js(?:\?v=[^"']+)?["']/);
  const vueScriptOffset = vueScriptMatch ? vueScriptMatch.index : -1;
  if (tailwindOffset < 0 || vueScriptOffset < 0 || tailwindOffset > vueScriptOffset) {
    failures.push('public/index.html must discover core stylesheets before synchronous Vue/static scripts.');
  }
  const loginBgPreloadOffset = content.indexOf("const loginBackgroundPreload = 'images/login-hotel-lobby-bg.avif';");
  if (/<link\s+rel=["']preload["']\s+href=["']images\/login-hotel-lobby-bg\.avif["']\s+as=["']image["']\s+type=["']image\/avif["']\s+fetchpriority=["']high["']/.test(content)) {
    failures.push('public/index.html must not statically preload the login background for cached-auth users.');
  }
  if (!content.includes("const shouldPreloadLoginBackground = () => {")
    || !content.includes("const readStartupAuthToken = () => {")
    || !content.includes("sessionStorage.setItem('token', legacyToken);")
    || !content.includes("localStorage.removeItem('token');")
    || !content.includes("return !readStartupAuthToken() || !localStorage.getItem('suxios_auth_user_cache_v1');")
    || !content.includes("link.setAttribute('fetchpriority', 'high');")
    || !content.includes("link.dataset.suxiLoginBgPreload = '1';")
    || !content.includes('preloadLoginBackground();')) {
    failures.push('public/index.html must conditionally preload the optimized AVIF login background only when the login shell can be shown.');
  }
  if (loginBgPreloadOffset < 0 || tailwindOffset < 0 || loginBgPreloadOffset > tailwindOffset) {
    failures.push('public/index.html must evaluate login background preload before core stylesheets.');
  }
  if (!content.includes("requireAppSystemStatic('loadCachedAuthUser')")
    || !content.includes("requireAppSystemStatic('saveCachedAuthUser')")
    || !content.includes("requireAppSystemStatic('clearCachedAuthUser')")
    || !systemStaticContent.includes('const normalizePermissionMap = (permissions = null) => {')
    || !systemStaticContent.includes('if (Array.isArray(permissions)) {')
    || !systemStaticContent.includes('if (key) acc[String(key)] = true;')
    || !systemStaticContent.includes('const permissions = normalizePermissionMap(profile.permissions);')) {
    failures.push('public/index.html must use system-static.js cached-auth helpers, and system-static.js must normalize permission arrays before first-paint menu filtering.');
  }
  if (!systemStaticContent.includes('const hasPermission = (permissions, key) => {')
    || !systemStaticContent.includes('if (Array.isArray(permissions)) return permissions.includes(key);')
    || !systemStaticContent.includes('return item.permissions.some(p => hasPermission(perms, p));')) {
    failures.push('public/system-static.js must keep visible menu filtering compatible with array and object permission payloads.');
  }
  try {
    const sandbox = {
      window: {},
      document: { querySelector: () => null, createElement: () => ({ dataset: {}, addEventListener: () => {} }), head: { appendChild: () => {} } },
      Promise,
      Error,
      setTimeout,
      clearTimeout,
    };
    vm.runInNewContext(systemStaticContent, sandbox, { filename: 'public/system-static.js' });
    const staticApi = sandbox.window.SUXI_SYSTEM_STATIC || {};
    const topLevelNames = (staticApi.menuItemDefinitions || []).map((item) => item.name);
    if (topLevelNames.join('|') !== '经营工作台|线上数据|运营执行|系统设置') {
      failures.push(`public/system-static.js top-level navigation must be 经营工作台 / 线上数据 / 运营执行 / 系统设置, got: ${topLevelNames.join(' / ') || '(empty)'}.`);
    }
    const managerMenu = staticApi.filterVisibleMenuItems(staticApi.menuItemDefinitions, {
      role_id: 2,
      is_super_admin: false,
      permissions: {
        can_view_online_data: true,
        can_manage_own_hotels: true,
      },
    });
    if (JSON.stringify(managerMenu).includes('"path":"agent-center"')) {
      failures.push('public/system-static.js manager-visible navigation must not expose the super-admin agent-center toolbox.');
    }
    if (JSON.stringify(managerMenu).includes('"path":"users"')) {
      failures.push('public/system-static.js manager-visible navigation must not expose employee management.');
    }
    const normalMenu = staticApi.filterVisibleMenuItems(staticApi.menuItemDefinitions, {
      role_id: 3,
      is_super_admin: false,
      is_hotel_manager: false,
      permissions: {
        can_view_online_data: true,
      },
    });
    if (JSON.stringify(normalMenu).includes('"path":"users"')) {
      failures.push('public/system-static.js normal-user navigation must not expose employee management.');
    }
  } catch (error) {
    failures.push(`public/system-static.js navigation guard could not evaluate menu definitions: ${error.message}`);
  }
  if (!content.includes('revenue-ai-static.js?v=20260710-ai-daily-fact-gate-investigation')
    || !revenueAiStaticContent.includes('window.SUXI_REVENUE_AI_STATIC')
    || !revenueAiStaticContent.includes('buildRevenueAiBusinessClosure')
    || !revenueAiStaticContent.includes('buildRevenueAiGapRows')
    || !revenueAiStaticContent.includes('buildRevenueAiMetricCards')
    || !revenueAiStaticContent.includes('buildRevenueAiOverviewEndpoint')
    || !revenueAiStaticContent.includes('resolveRevenueAiGapTarget')
    || !revenueAiStaticContent.includes('buildRevenueAiPricingGateRows')
    || !revenueAiStaticContent.includes('buildRevenueAiPriceSuggestionGenerateResult')
    || !revenueAiStaticContent.includes('buildRevenueAiEvidenceWorkbenchRows')
    || !revenueAiStaticContent.includes('buildRevenueAiEvidenceWorkbenchSummary')
    || !revenueAiStaticContent.includes('buildRevenueAiAgentActivityRows')
    || !revenueAiStaticContent.includes('buildRevenueAiExecutionSummary')
    || !revenueAiStaticContent.includes('buildRevenueAiExecutionRows')
    || !revenueAiStaticContent.includes('buildRevenueAiEffectReviewRows')
    || !revenueAiStaticContent.includes('buildAiDailyFactGate')
    || !revenueAiStaticContent.includes('evidenceSummary: item.evidence_summary ||')
    || !revenueAiStaticContent.includes('latestEvidenceType: item.latest_evidence_type ||')
    || !revenueAiStaticContent.includes('hasRevenueEvidence: item.has_revenue_evidence === true')
    || !revenueAiStaticContent.includes('evidenceReadyForNextDay: item.evidence_ready_for_next_day === true')
    || !revenueAiStaticContent.includes('inputActionKey: item.input_action_key ||')
    || !revenueAiStaticContent.includes('inputNextAction: item.input_next_action ||')
    || !revenueAiStaticContent.includes('nextActionKey: item.input_action_key || item.target_action ||')
    || !revenueAiStaticContent.includes('actionLabel: item.input_action_label ||')
    || !revenueAiStaticContent.includes("reason === 'operation_roi_missing' ? '补录ROI证据'")
    || !revenueAiStaticContent.includes('effectReviewInputDisplay: effectReview.input_display ||')
    || !revenueAiStaticContent.includes('effectReviewInputReadyCount: Number(effectReview.input_ready_count || 0)')
    || !revenueAiStaticContent.includes('effectReviewInputPartialCount: Number(effectReview.input_partial_count || 0)')
    || !content.includes('revenueAiExecutionSummary.effectReviewInputDisplay')
    || !revenueAiServiceContent.includes('sortExecutionEffectReviewInputs')
    || !revenueAiServiceContent.includes('executionEffectReviewInputPriority')
    || !revenueAiServiceContent.includes('executionEffectReviewInputAction')
    || !revenueAiServiceContent.includes('operationFeedbackInputGate')
    || !revenueAiServiceContent.includes('pricingDecisionBasisSummary')
    || !revenueAiServiceContent.includes("'expected_revpar_impact_display' =>")
    || !revenueAiServiceContent.includes('priceSuggestionExpectedRevparImpact')
    || !revenueAiServiceContent.includes("'decision_basis_summary' =>")
    || !revenueAiServiceContent.includes("'target_page' => (string)($gate['target_page']")
    || !revenueAiServiceContent.includes("'operation_feedback_input'")
    || !revenueAiServiceContent.includes("'input_action_key' =>")
    || !revenueAiServiceContent.includes("'input_next_action' =>")
    || !revenueAiServiceContent.includes("$reason === 'operation_roi_missing'")
    || !revenueAiServiceContent.includes("$reason === 'operation_execution_evidence_needed'")
    || !revenueAiStaticContent.includes('canOpenExecution:')
    || !revenueAiStaticContent.includes('targetPage,')
    || !revenueAiStaticContent.includes('actionLabel,')
    || !revenueAiStaticContent.includes('buildRevenueAiReviewQueueItems')
    || !revenueAiStaticContent.includes('nextActions: Array.isArray(action.next_actions)')
    || !revenueAiStaticContent.includes('reviewQueueSummary: action.review_queue_summary || reviewQueue.display ||')
    || !revenueAiStaticContent.includes('impactLine,')
    || !revenueAiStaticContent.includes('decisionBasisDisplay: decisionBasis.display ||')
    || !revenueAiStaticContent.includes('decisionBasisHiddenBlockedCount')
    || !revenueAiStaticContent.includes('decisionBasisHiddenDisplay:')
    || !revenueAiStaticContent.includes('revenueAiDecisionBasisPriority')
    || !revenueAiStaticContent.includes('.sort((left, right) =>')
    || !revenueAiStaticContent.includes('decisionBasisItems,')
    || !revenueAiStaticContent.includes('targetPage: item.target_page ||')
    || !revenueAiStaticContent.includes('canOpenTarget: Boolean(item.target_page)')
    || !revenueAiStaticContent.includes('const reviewQueueItems = buildRevenueAiReviewQueueItems(reviewQueue);')
    || !revenueAiStaticContent.includes('approvedExecutionPendingCount')
    || !revenueAiStaticContent.includes('executionPendingDisplay')
    || !revenueAiStaticContent.includes('const actionEntry = item.action_entry && typeof item.action_entry ===')
    || !revenueAiStaticContent.includes('const canApprove = item.can_review === true')
    || !revenueAiStaticContent.includes('const canApproveWithChanges = item.can_review === true')
    || !revenueAiStaticContent.includes('const canCreateExecutionIntent = manualActions.includes')
    || !revenueAiStaticContent.includes('allowedEndpoints,')
    || !revenueAiStaticContent.includes('nextAction: gate.next_action ||')
    || !revenueAiStaticContent.includes('autoWriteOta: summary.auto_write_ota === true')
    || !content.includes('const requireRevenueAiStatic = (key) => {')
    || !revenueAiStaticContent.includes('const buildRevenueAiOverviewEndpoint = (options = {}) => {')
    || !revenueAiStaticContent.includes('const resolveRevenueAiBusinessDate = ({ overview = null, now = new Date() } = {}) => {')
    || !content.includes("const revenueAiResolveBusinessDate = requireRevenueAiStatic('resolveRevenueAiBusinessDate');")
    || !content.includes("const revenueAiResolveOverviewRequest = requireRevenueAiStatic('resolveRevenueAiOverviewRequest');")
    || !content.includes("const revenueAiResolveOverviewResponse = requireRevenueAiStatic('resolveRevenueAiOverviewResponse');")
    || !content.includes("const revenueAiBuildGapRows = requireRevenueAiStatic('buildRevenueAiGapRows');")
    || !content.includes("const revenueAiResolveGapTarget = requireRevenueAiStatic('resolveRevenueAiGapTarget');")
    || !content.includes("const revenueAiResolveDecisionBasisNavigation = requireRevenueAiStatic('resolveRevenueAiDecisionBasisNavigation');")
    || !content.includes("const revenueAiBuildPricingGateRows = requireRevenueAiStatic('buildRevenueAiPricingGateRows');")
    || !content.includes("const revenueAiBuildEvidenceWorkbenchRows = requireRevenueAiStatic('buildRevenueAiEvidenceWorkbenchRows');")
    || !content.includes("const revenueAiBuildEvidenceWorkbenchSummary = requireRevenueAiStatic('buildRevenueAiEvidenceWorkbenchSummary');")
    || !content.includes("const revenueAiBuildAgentActivityRows = requireRevenueAiStatic('buildRevenueAiAgentActivityRows');")
    || !content.includes("const revenueAiBuildExecutionSummary = requireRevenueAiStatic('buildRevenueAiExecutionSummary');")
    || !content.includes("const revenueAiBuildExecutionRows = requireRevenueAiStatic('buildRevenueAiExecutionRows');")
    || !content.includes("const revenueAiBuildEffectReviewRows = requireRevenueAiStatic('buildRevenueAiEffectReviewRows');")
    || !content.includes("const revenueAiBuildDailyFactGate = requireRevenueAiStatic('buildAiDailyFactGate');")
    || !content.includes('data-testid="ai-daily-fact-gate"')
    || !revenueAiStaticContent.includes('const revenueAiExecutionNeedsRoiEvidence = (row = {}) => {')
    || !revenueAiStaticContent.includes('const resolveRevenueAiExecutionNavigation = ({ row = {}, fallbackHotelId = 0 } = {}) => {')
    || !content.includes("const revenueAiResolveExecutionAction = requireRevenueAiStatic('resolveRevenueAiExecutionAction');")
    || !content.includes("const revenueAiIsReviewActionLoading = requireRevenueAiStatic('isRevenueAiReviewActionLoadingState');")
    || !content.includes("const revenueAiBuildReviewActionLoadingState = requireRevenueAiStatic('buildRevenueAiReviewActionLoadingState');")
    || !content.includes("const revenueAiResolveReviewActionDraft = requireRevenueAiStatic('resolveRevenueAiReviewActionDraft');")
    || !content.includes("const revenueAiValidateApprovedPrice = requireRevenueAiStatic('validateRevenueAiApprovedPrice');")
    || !content.includes("const revenueAiBuildReviewConfirmText = requireRevenueAiStatic('buildRevenueAiReviewConfirmText');")
    || !content.includes("const revenueAiBuildReviewRequestBody = requireRevenueAiStatic('buildRevenueAiReviewRequestBody');")
    || !content.includes("const revenueAiBuildExecutionIntentOpenRow = requireRevenueAiStatic('buildRevenueAiExecutionIntentOpenRow');")
    || !content.includes("const revenueAiResolveReviewNavigation = requireRevenueAiStatic('resolveRevenueAiReviewNavigation');")
    || !content.includes("const revenueAiBuildReviewNavigationState = requireRevenueAiStatic('buildRevenueAiReviewNavigationState');")
    || !content.includes("const revenueAiBuildPriceSuggestionGenerateResult = requireRevenueAiStatic('buildRevenueAiPriceSuggestionGenerateResult');")
    || !content.includes('data-testid="agent-price-suggestion-generate-result"')
    || content.includes("const revenueAiApiPath = requireRevenueAiStatic('normalizeRevenueAiApiPath');")
    || !content.includes('const revenueAiPricingGateRows = computed(() => revenueAiBuildPricingGateRows({')
    || !content.includes('const revenueAiEvidenceWorkbenchRows = computed(() => revenueAiBuildEvidenceWorkbenchRows({')
    || !content.includes('const revenueAiAgentActivityRows = computed(() => revenueAiBuildAgentActivityRows({')
    || !content.includes('const revenueAiExecutionSummary = computed(() => revenueAiBuildExecutionSummary({')
    || !content.includes('const revenueAiBusinessDate = computed(() => revenueAiResolveBusinessDate({ overview: revenueAiOverview.value }));')
    || content.includes('const revenueAiBusinessDate = computed(() => revenueAiOverview.value?.business_date || formatDate(')
    || !content.includes('revenueAiBuildGapRows({')
    || !content.includes('const overviewRequest = revenueAiResolveOverviewRequest({')
    || !content.includes('await request(overviewRequest.endpoint)')
    || !revenueAiStaticContent.includes('const resolveRevenueAiOverviewResponse = ({ response = null, error = null } = {}) => {')
    || !content.includes('const overviewResult = revenueAiResolveOverviewResponse({ response: res });')
    || !content.includes('const overviewResult = revenueAiResolveOverviewResponse({ error: e });')
    || content.includes("revenueAiOverviewError.value = res.message || 'Revenue AI 总览接口返回失败';")
    || content.includes("revenueAiOverviewError.value = e.message || 'Revenue AI 总览接口请求失败';")
    || content.includes("const loadRevenueAiOverview = async () => {\n                if (!token.value || currentPage.value !== 'compass') return null;")
    || !content.includes('const { targetTab } = revenueAiResolveGapTarget(row);')
    || content.includes('const revenueAiChannelStatus = (channel) =>')
    || !content.includes('data-testid="revenue-ai-gap-closure"')
    || !content.includes('data-testid="revenue-ai-pricing-gates"')
    || !content.includes('data-testid="revenue-ai-review-queue"')
    || !content.includes('data-testid="revenue-ai-decision-basis"')
    || !content.includes('data-testid="revenue-ai-decision-basis-hidden"')
    || !content.includes('data-testid="revenue-ai-evidence-workbench"')
    || !content.includes('@click="openRevenueAiDecisionBasis(basis)"')
    || !content.includes('data-testid="revenue-ai-review-queue-items"')
    || !content.includes('data-testid="revenue-ai-execution-pending"')
    || !content.includes('{{ item.impactLine }}')
    || !content.includes('data-testid="revenue-ai-agent-activity"')
    || !content.includes('data-testid="revenue-ai-execution-summary"')
    || !content.includes('data-testid="revenue-ai-effect-review-inputs"')
    || !content.includes('const revenueAiEffectReviewRows = computed(() => revenueAiBuildEffectReviewRows({')
    || !content.includes('v-if="action.nextActions?.length"')
    || !content.includes('v-if="gate.nextAction"')
    || !content.includes('const openRevenueAiGap = (row = {}) => {')
    || !content.includes('const openRevenueAiDecisionBasis = async (basis = {}) => {')
    || !revenueAiStaticContent.includes('const resolveRevenueAiDecisionBasisNavigation = (basis = {}) => ({')
    || content.includes('const targetPage = String(basis.targetPage || basis.target_page ||')
    || !content.includes("if (navigation.targetPage === 'ops-track') {")
    || !content.includes('const openRevenueAiExecutionItem = async (row = {}) => {')
    || !revenueAiStaticContent.includes('const resolveRevenueAiExecutionNavigation = ({ row = {}, fallbackHotelId = 0 } = {}) => {')
    || !revenueAiStaticContent.includes('const resolveRevenueAiExecutionAction = ({ row = {}, fallbackHotelId = 0 } = {}) => {')
    || content.includes("const revenueAiExecutionNeedsRoiEvidence = requireRevenueAiStatic('revenueAiExecutionNeedsRoiEvidence');")
    || content.includes("const revenueAiResolveExecutionNavigation = requireRevenueAiStatic('resolveRevenueAiExecutionNavigation');")
    || content.includes("const revenueAiExecutionTaskActionItem = requireRevenueAiStatic('revenueAiExecutionTaskActionItem');")
    || content.includes('const intentId = Number(row.intentId ||')
    || content.includes('const revenueAiExecutionResolvedActionKey = (row = {}) => {')
    || content.includes('const revenueAiExecutionTaskActionItem = (row = {}) => {')
    || content.includes("const nextActionKey = navigation.nextActionKey;")
    || content.includes("nextActionKey === 'record_execution_evidence'")
    || content.includes("nextActionKey === 'record_roi_evidence'")
    || content.includes("nextActionKey === 'record_effect_review'")
    || content.includes("nextActionKey === 'review_effect'")
    || content.includes("const revenueAiReviewActionKey = requireRevenueAiStatic('revenueAiReviewActionKey');")
    || !revenueAiStaticContent.includes('const isRevenueAiReviewActionLoadingState = ({ state = {}, item = {}, action = \'\' } = {}) => {')
    || !revenueAiStaticContent.includes('const buildRevenueAiReviewActionLoadingState = ({ state = {}, item = {}, action = \'\', loading = false } = {}) => {')
    || !content.includes('return revenueAiIsReviewActionLoading({')
    || !content.includes('revenueAiReviewActionLoading.value = revenueAiBuildReviewActionLoadingState({')
    || content.includes('revenueAiReviewActionLoading.value[revenueAiReviewActionKey(item, action)]')
    || content.includes('const key = revenueAiReviewActionKey(item, action);')
    || content.includes("const revenueAiReviewActionText = requireRevenueAiStatic('revenueAiReviewActionText');")
    || content.includes("const revenueAiReviewEndpoint = requireRevenueAiStatic('revenueAiReviewEndpoint');")
    || content.includes("approve: '批准该调价建议'")
    || content.includes("const endpoints = item.allowedEndpoints || {};")
    || !revenueAiStaticContent.includes('const resolveRevenueAiReviewActionDraft = ({ item = {}, action = \'\' } = {}) => {')
    || !content.includes('const draft = revenueAiResolveReviewActionDraft({ item, action });')
    || content.includes('const suggestionId = Number(item.id || 0);')
    || content.includes('item.autoWriteOta === true')
    || content.includes("!endpoint.startsWith('/revenue-ai/price-suggestions/')")
    || content.includes("showToast('不支持的审核动作'")
    || content.includes("const parsedPrice = Number(String(inputValue).replace")
    || content.includes("const body = normalizedAction === 'execution_intent'")
    || !revenueAiStaticContent.includes("nextActionKey === 'record_execution_evidence'")
    || !revenueAiStaticContent.includes("nextActionKey === 'record_evidence'")
    || !content.includes('await recordOperationExecutionEvidence(taskItem);')
    || !revenueAiStaticContent.includes("nextActionKey === 'record_roi_evidence'")
    || !revenueAiStaticContent.includes("nextActionKey === 'record_effect_review'")
    || !revenueAiStaticContent.includes("nextActionKey === 'review_effect'")
    || !content.includes('await recordOperationRoiEvidence(taskItem);')
    || !content.includes('await reviewOperationExecutionTask(taskItem);')
    || !content.includes('const parseOptionalOperationEvidenceNumber = (value, label) => {')
    || !content.includes('const normalizeOperationReviewStatus = (value) => {')
    || !content.includes('const recordOperationRoiEvidence = async (item) => {')
    || !content.includes("evidence_type: 'manual_roi_evidence'")
    || !content.includes("source: 'revenue_ai_effect_review_input'")
    || !content.includes("evidence_boundary: 'local_manual_roi_evidence_no_ota_write'")
    || !content.includes('result_status: resultStatus')
    || !content.includes('result_summary: resultSummary || \'继续观察，等待次日收益或ROI证据\'')
    || !content.includes('复盘结论为达成/接近达成/未达成时必须填写说明')
    || !content.includes("evidence_type: 'manual_price_execution'")
    || !content.includes("evidence_boundary: 'local_manual_evidence_no_ota_write'")
    || !content.includes('const operationEvidenceLocalTimestamp = () => {')
    || !content.includes('执行前后收入需同时填写或都留空')
    || !content.includes('current_value: operationEvidenceCleanObject({ ...currentValue, executed_before_price: beforePrice })')
    || !content.includes('target_value: operationEvidenceCleanObject({ ...targetValue, executed_after_price: afterPrice })')
    || !content.includes('const submitRevenueAiReviewAction = async (item = {}, action = \'\') => {')
    || !content.includes('const isRevenueAiReviewActionLoading = (item = {}, action = \'\') => {')
    || !content.includes("@click=\"openRevenueAiMetric(card)\"")
    || !content.includes('@click="openRevenueAiExecutionItem(row)"')
    || !content.includes('operationExecutionRowClass')
    || !content.includes('@click="submitRevenueAiReviewAction(item, \'approve\')"')
    || !content.includes('@click="submitRevenueAiReviewAction(item, \'approve_with_changes\')"')
    || !content.includes('@click="submitRevenueAiReviewAction(item, \'reject\')"')
    || !content.includes('@click="submitRevenueAiReviewAction(item, \'execution_intent\')"')
    || !revenueAiStaticContent.includes("approve_with_changes: '修改后批准该调价建议'")
    || !revenueAiStaticContent.includes('approved_price: approvedPrice')
    || !content.includes("if (normalizedAction === 'execution_intent') {")
    || !content.includes('await openRevenueAiExecutionItem(revenueAiBuildExecutionIntentOpenRow({')
    || !revenueAiStaticContent.includes("targetAction: data.target_action || 'approve_intent'")
    || !revenueAiStaticContent.includes("const resolveRevenueAiReviewNavigation = ({ item = {}, isSuperAdmin = false } = {}) => {")
    || !revenueAiStaticContent.includes('const buildRevenueAiReviewNavigationState = (navigation = {}) => {')
    || !content.includes('const navigationState = revenueAiBuildReviewNavigationState(navigation);')
    || content.includes('filterReportHotel.value = navigation.hotelId')
    || content.includes('priceSuggestionFilter.value.date = navigation.date')
    || content.includes('priceSuggestionFilter.value.status = navigation.status')
    || content.includes('revenueAgentTab.value = navigation.revenueAgentTab')
    || content.includes('const entry = item.actionEntry || {};')
    || content.includes("String(entry.target_page || '') !== 'agent-center'")
    || !revenueAiStaticContent.includes("source: 'revenue_ai_homepage'")
    || !content.includes("openOnlineDataEntryTab(targetTab, { force: true });")) {
    failures.push('public/index.html must delegate Revenue AI display helpers to revenue-ai-static.js, keep gap/metric clicks on data-health, and expose only manual review/execution-intent actions.');
  }
  if (content.includes('@click="applyPriceSuggestion(item.id)"')) {
    failures.push('public/index.html must not expose a direct apply-price button in Phase 1B; approved suggestions should be transferred to execution instead.');
  }
  if (content.includes('`/agent/price-suggestions/${id}/apply`')
    || content.includes('已应用到房型基础价')) {
    failures.push('public/index.html must not call the direct price apply endpoint or claim local base price was updated in Phase 1B.');
  }
  if (/<link\s+href=["']font-awesome\.min\.css(?:\?v=[^"']+)?["']\s+rel=["']stylesheet["']/.test(content)
    || !content.includes("const fontAwesomeStylesheet = 'font-awesome.min.css?v=20260628-static-router-fix';")
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

  if (fs.existsSync(path.join(repoRoot, 'public/hotel-image-optimizer-static.js'))
    || /hotel-image-optimizer|hotelImageOptimizer|SUXI_HOTEL_IMAGE_OPTIMIZER_STATIC/.test(content)) {
    failures.push('the removed hotel image optimizer must not remain in the public entry or public assets.');
  }
  if (!systemStaticContent.includes('const hotelAiToolboxLinks = [')
    || !systemStaticContent.includes('hotelAiToolboxLinks,')
    || !content.includes("const hotelAiToolboxLinks = ref(requireAppSystemStatic('hotelAiToolboxLinks'));")) {
    failures.push('agent-center must keep its external AI toolbox links through public/system-static.js after image optimizer removal.');
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
  if (!/const\s+autoFetchStaticScript\s*=\s*["']auto-fetch-static\.js(?:\?[^"']+)?["']/.test(content)
    || !/const\s+loadAutoFetchStatic\s*=\s*\(\)\s*=>/.test(content)
    || !/const\s+ensureAutoFetchStaticReady\s*=\s*async\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader and ready guard for auto-fetch-static.js.');
  }
  if (!autoFetchStaticContent.includes('const autoFetchScopeStatusClass = (status) => ({')
    || !autoFetchStaticContent.includes('const autoFetchModeLabel = (mode, options = autoFetchModeOptions) => {')
    || !autoFetchStaticContent.includes('const formatAutoFetchElapsed = (seconds) => {')
    || !autoFetchStaticContent.includes('const formatAutoFetchMs = (ms) => {')
    || !autoFetchStaticContent.includes('const autoFetchResultStatusText = (row) => {')
    || !autoFetchStaticContent.includes('const autoFetchResultStatusClass = (row) => {')
    || !autoFetchStaticContent.includes('const autoFetchModuleLabel = (module) => ({')
    || !autoFetchStaticContent.includes('const platformProfileStatusLabel = (item) => {')
    || !autoFetchStaticContent.includes('const platformProfileBindingText = (item) => {')
    || !autoFetchStaticContent.includes('const platformProfileNextActionText = (item) => {')
    || !autoFetchStaticContent.includes('const platformProfileLoginTaskText = (task) => {')
    || !autoFetchStaticContent.includes('const platformSourceStatusClass = (status) => {')
    || !autoFetchStaticContent.includes('const platformTaskStatusClass = (status) => {')
    || !autoFetchStaticContent.includes('const platformSyncActionText = (message) => {')
    || !content.includes('autoFetchStatic.value.autoFetchModeLabel(mode, autoFetchModeOptions.value)')
    || !content.includes('autoFetchStatic.value.formatAutoFetchElapsed(seconds)')
    || !content.includes('autoFetchStatic.value.formatAutoFetchMs(ms)')
    || !content.includes('autoFetchStatic.value.autoFetchResultStatusText(row)')
    || !content.includes('autoFetchStatic.value.autoFetchResultStatusClass(row)')
    || !content.includes('autoFetchStatic.value.autoFetchModuleLabel(module)')
    || !content.includes('autoFetchStatic.value?.platformProfileStatusLabel?.(item)')
    || !content.includes('autoFetchStatic.value?.platformProfileBindingText?.(item)')
    || !content.includes('autoFetchStatic.value?.platformProfileNextActionText?.(item)')
    || !content.includes('autoFetchStatic.value?.platformProfileLoginTaskText?.(task)')
    || !content.includes('autoFetchStatic.value?.platformSourceStatusClass?.(status)')
    || !content.includes('autoFetchStatic.value?.platformTaskStatusClass?.(status)')
    || !content.includes('autoFetchStatic.value?.platformSyncActionText?.(message)')
    || content.includes('const formatAutoFetchElapsed = (seconds) => {\n                const total = Math.max(0, Number.parseInt(seconds, 10) || 0);')
    || content.includes('const autoFetchModuleLabel = (module) => ({\n                business:')
    || content.includes('const platformProfileMachineText = (value) =>')
    || content.includes('const platformProfileStatusBadgeClass = (statusCode) => ({')
    || content.includes('const platformSourceStatusClass = (status) => {\n                if (status === ')
    || content.includes('const platformSyncActionText = (message) => {\n                const text = String(message || ')) {
    failures.push('public/index.html must delegate auto-fetch display labels, timing formatting, profile/source status wording, and status classes to auto-fetch-static.js.');
  }
  if (!/const prewarmAutoFetchStaticForPlatformAuto = \(\) => \{[\s\S]*if \(!isVisibleOnlineDataTab\(['"]platform-auto['"]\)\) return null;[\s\S]*const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{[\s\S]*void staticReadyPromise;/.test(content)
    || /const loadAutoFetchPanel = async[\s\S]*const staticReadyPromise = loadAutoFetchStatic\(\)\.catch\(error => \{/.test(content)
    || /const loadAutoFetchPanel = async[\s\S]*Promise\.all\(\[[\s\S]*staticReadyPromise/.test(content)
    || !/const triggerAutoFetch = async[\s\S]*await ensureAutoFetchStaticReady\(\);[\s\S]*requireAutoFetchStatic\(['"]runAutoFetchTriggerFlow['"]\)/.test(content)) {
    failures.push('public/index.html must delay auto-fetch-static.js prewarm beyond platform-auto first paint, and load it before triggering manual auto-fetch.');
  }
  if (!ctripStaticContent.includes('const normalizeCtripProfileFieldVerificationStatus = (status) => {')
    || !ctripStaticContent.includes('const ctripProfileFieldVerificationText = (status) => ({')
    || !ctripStaticContent.includes('const ctripProfileFieldVerificationBadgeClass = (status) => {')
    || !ctripStaticContent.includes('const ctripProfileFieldVerificationLightClass = (status) => {')
    || !content.includes("const normalizeCtripProfileFieldVerificationStatus = requireCtripStatic('normalizeCtripProfileFieldVerificationStatus');")
    || !content.includes("const ctripProfileFieldVerificationText = requireCtripStatic('ctripProfileFieldVerificationText');")
    || !content.includes("const ctripProfileFieldVerificationBadgeClass = requireCtripStatic('ctripProfileFieldVerificationBadgeClass');")
    || !content.includes("const ctripProfileFieldVerificationLightClass = requireCtripStatic('ctripProfileFieldVerificationLightClass');")
    || content.includes("if (['matched', 'match', 'ok', 'correct'].includes(value)) return 'matched';")
    || content.includes("const ctripProfileFieldVerificationBadgeClass = (status) => {\n                const value = normalizeCtripProfileFieldVerificationStatus(status);")) {
    failures.push('public/index.html must delegate Ctrip Profile field verification labels and classes to ctrip-static.js.');
  }
  if (!content.includes('const autoFetchConfigProofPendingForHotelId = (hotelId) => {')
    || !content.includes('autoFetchStatusRequestPromises.has(`${keyPrefix}light`)')
    || !content.includes('autoFetchStatusRequestPromises.has(`${keyPrefix}full`)')
    || !content.includes('const canTriggerAutoFetchByHotelId = (hotelId) => {')
    || !/hasAnyPlatformFetchConfigByHotelId\(hotelId\)\s*\|\|\s*autoFetchConfigProofPendingForHotelId\(hotelId\)/.test(content)
    || !content.includes("getBrowserProfileDataSourceByHotelAndPlatform(hotelId, 'ctrip')")
    || !content.includes("getBrowserProfileDataSourceByHotelAndPlatform(hotelId, 'meituan')")
    || !content.includes('hasPlatformFetchConfig: canTriggerAutoFetchByHotelId,')
    || (content.match(/:disabled="fetchingData \|\| !canTriggerAutoFetchByHotelId\(autoFetchHotelId\)"/g) || []).length < 2) {
    failures.push('public/index.html must let platform-auto immediate collection stay clickable while light config proof is pending or a Browser Profile source exists, without relaxing settings/backfill controls.');
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
  const compassPageGuardCount = (content.match(/if \(!token\.value \|\| !isCompassDataPage\(\)\) return;/g) || []).length;
  if (compassPageGuardCount < 2
    || !content.includes("if (!token.value || !isCompassDataPage() || macroSignalLoading.value) return;")
    || !content.includes("if (options.requireCompass === true && !isCompassDataPage()) return;")
    || !content.includes("if (!isCompassDataPage()) return null;")
    || !content.includes('loadCompetitorSummary({ requireCompass: true })')) {
    failures.push('public/index.html compass-data background refreshes must stop after the user leaves AI workbench/compass pages.');
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
  if (!/let ctripConfigListLoadingPromise = null;[\s\S]*const loadCtripConfigList = async[\s\S]*if \(ctripConfigListLoadingPromise\) \{[\s\S]*if \(!force\) \{[\s\S]*return ctripConfigListLoadingPromise;[\s\S]*await ctripConfigListLoadingPromise\.catch\(\(\) => \[\]\);[\s\S]*finally \{[\s\S]*ctripConfigListLoadingPromise = null;/.test(content)) {
    failures.push('public/index.html must deduplicate concurrent Ctrip config-list loads for manual-fetch prewarm and tab switching.');
  }
  const ctripCanFetchStart = content.indexOf('const canFetchCtripManualData = () => {');
  const ctripCanFetchEnd = ctripCanFetchStart >= 0
    ? content.indexOf('\n\n            const resolveCtripManualFetchConfig', ctripCanFetchStart)
    : -1;
  const ctripCanFetchSource = ctripCanFetchStart >= 0 && ctripCanFetchEnd > ctripCanFetchStart
    ? content.slice(ctripCanFetchStart, ctripCanFetchEnd)
    : '';
  if (!content.includes(':disabled="fetchingData || !canFetchCtripManualData()"')
    || !content.includes('const ctripManualFetchConfigProofPending = () => {')
    || !content.includes('return !!ctripConfigListLoadingPromise')
    || !ctripCanFetchSource.includes('if (selectedCtripHotelId.value) return selectedCtripManualCredentialState.value.canFetch;')
    || !ctripCanFetchSource.includes("const isRankingTab = onlineDataTab.value === 'ctrip-ranking';")
    || !ctripCanFetchSource.includes('if (!isRankingTab) return false;')
    || !ctripCanFetchSource.includes("return normalizeCtripTemporaryCookie(ctripForm.value) !== '';")
    || /ctripManualFetchConfigProofPending|ctripConfigListLoadingPromise|ctripConfigListLoaded|ctripConfigListLoadFailed/.test(ctripCanFetchSource)
    || !content.includes('const resolveCtripManualFetchConfig = async (config) => {')
    || !content.includes('return ctripManualFetchConfigCandidate();')) {
    failures.push('public/index.html Ctrip manual fetch must require a ready saved credential for a selected hotel, or an explicit one-shot Cookie on the unbound ranking query; async prewarm and submit-time resolution must remain.');
  }
  if (!content.includes('const ctripConfigHasManualAuxiliary = (config = null) => {')
    || !content.includes("String(config.credential_status || '') === 'ready'")
    || !content.includes('config.has_cookies === true')
    || content.includes('const loadCtripConfigDetail = async')
    || content.includes('const ensureCtripConfigSecret = async')
    || content.includes('const prewarmSelectedCtripConfigSecret =')
    || content.includes('ctripConfigDetailCache.set(')) {
    failures.push('public/index.html Ctrip execution readiness must use metadata only and must not load, prewarm, or cache full credential details.');
  }
  if (!content.includes('const resolveCtripConfigMetadata = (config) => config || null;')
    || !content.includes("selectedCtripConfigId.value = config.config_id || config.id || '';")
    || !content.includes("ctripForm.value.cookies = '';")
    || !content.includes('ctripForm.value.auth_data = {};')) {
    failures.push('public/index.html Ctrip config application must retain only locator/business metadata and clear browser credential fields.');
  }
  if (!content.includes('const scheduleCtripHotelConfigApply = (event = null, options = {}) => {')
    || !content.includes('const applyVersion = ++ctripHotelConfigApplyVersion;')
    || !content.includes('const config = resolveCtripConfigMetadata(configSource);')
    || !content.includes('isCtripRankingFormAlignedWithConfig(ctripForm.value, config, { selectedHotelId: requestedHotelId })')
    || !content.includes('@change="scheduleCtripHotelConfigApply"')
    || content.includes('@change="applyCtripHotelConfig"')) {
    failures.push('public/index.html Ctrip hotel selection must apply metadata without credential-detail loading and skip redundant form application when already aligned.');
  }
  if (content.includes("request('/online-data/get-ctrip-config-detail")
    || content.includes('ctripConfigDetailLoadingPromises.set(')
    || content.includes('ctripConfigDetailCache.set(')) {
    failures.push('public/index.html must not request or retain reusable Ctrip credential detail in browser caches.');
  }
  const batchDeleteCtripConfigsSource = content.slice(
    content.indexOf('const batchDeleteCtripConfigs = async'),
    content.indexOf('const generateCtripBookmarklet = async')
  );
  if (!content.includes("const buildCtripBatchDeleteConfigResultState = requireCtripStatic('buildCtripBatchDeleteConfigResultState');")
    || !ctripStaticContent.includes('const buildCtripBatchDeleteConfigResultState = (results = []) => {')
    || !batchDeleteCtripConfigsSource.includes('const results = await Promise.all(ids.map(async (id) => {')
    || !batchDeleteCtripConfigsSource.includes('const deleteResultState = buildCtripBatchDeleteConfigResultState(results);')
    || !batchDeleteCtripConfigsSource.includes('selectedCtripConfigIds.value = deleteResultState.failedIds;')
    || !batchDeleteCtripConfigsSource.includes('if (deleteResultState.shouldRefresh) {')
    || !batchDeleteCtripConfigsSource.includes('deferUiTask(() => loadCtripConfigList(), 80);')
    || !batchDeleteCtripConfigsSource.includes('showToast(deleteResultState.toastMessage, deleteResultState.toastLevel);')
    || batchDeleteCtripConfigsSource.includes('const failedIds = results.filter(item => !item.success).map(item => item.id);')
    || batchDeleteCtripConfigsSource.includes('const deletedCount = results.length - failedIds.length')
    || batchDeleteCtripConfigsSource.includes('await loadCtripConfigList();')) {
    failures.push('public/index.html Ctrip batch config delete must run delete requests in parallel and refresh the config list after feedback is released.');
  }
  const generateCtripBookmarkletSource = content.slice(
    content.indexOf('const generateCtripBookmarklet = async () => {'),
    content.indexOf('// 美团配置管理方法')
  );
  if (!content.includes("const buildCtripBookmarkletSuccessState = requireCtripStatic('buildCtripBookmarkletSuccessState');")
    || !content.includes("const buildCtripBookmarkletFailureState = requireCtripStatic('buildCtripBookmarkletFailureState');")
    || !ctripStaticContent.includes('const buildCtripBookmarkletSuccessState = (response = {}) => ({')
    || !ctripStaticContent.includes('const buildCtripBookmarkletFailureState = ({')
    || !generateCtripBookmarkletSource.includes('const successState = buildCtripBookmarkletSuccessState(res);')
    || !generateCtripBookmarkletSource.includes('ctripBookmarklet.value = successState.bookmarklet;')
    || !generateCtripBookmarkletSource.includes('showToast(successState.toastMessage, successState.toastLevel);')
    || !generateCtripBookmarkletSource.includes('const failureState = buildCtripBookmarkletFailureState({ error: e });')
    || !generateCtripBookmarkletSource.includes('alert(failureState.alertMessage);')
    || !generateCtripBookmarkletSource.includes('showToast(failureState.toastMessage, failureState.toastLevel);')
    || generateCtripBookmarkletSource.includes('ctripBookmarklet.value = res.data.bookmarklet;')
    || generateCtripBookmarkletSource.includes("showToast(res.data?.message || '旧版携程 Cookie 书签已禁用', 'warning')")
    || generateCtripBookmarkletSource.includes("showToast('生成失败: ' + e.message, 'error')")) {
    failures.push('public/index.html Ctrip bookmarklet state must stay in public/ctrip-static.js.');
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
  if (!content.includes('凭据统一由平台配置保管')
    || !content.includes('旧 Cookie 列表、明文详情和快速保存入口已停用。')
    || !content.includes('<button @click="openPlatformSourcesTab"')
    || !content.includes("showToast(res.data?.message || '旧版携程 Cookie 书签已禁用', 'warning')")
    || !content.includes("showToast(res.data?.message || '旧版美团 Cookie 书签已禁用', 'warning')")
    || /document\.cookie|copyCookieScript|Cookie脚本已复制/.test(content)
    || content.includes('书签脚本生成成功')) {
    failures.push('public/index.html legacy Cookie UI must route to the platform credential vault and must not ship an extraction helper or imply successful script generation.');
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
  const loadCookiesListSource = content.slice(
    content.indexOf('const loadCookiesList = async () => {'),
    content.indexOf('const loadCookieDetail = async (item) => {')
  );
  const loadCookieDetailSource = content.slice(
    content.indexOf('const loadCookieDetail = async (item) => {'),
    content.indexOf('const cookieStatusClass = (status) => {')
  );
  const saveCookiesConfigSource = content.slice(
    content.indexOf('const saveCookiesConfig = async () => {'),
    content.indexOf('const deleteCookiesConfig = async (name, hotelId) => {')
  );
  const deleteCookiesConfigSource = content.slice(
    content.indexOf('const deleteCookiesConfig = async (name, hotelId) => {'),
    content.indexOf('const batchDeleteCookiesConfig = async () => {')
  );
  const batchDeleteCookiesConfigSource = content.slice(
    content.indexOf('const batchDeleteCookiesConfig = async () => {'),
    content.indexOf('const useCookies = async (item) => {')
  );
  const useCookiesSource = content.slice(
    content.indexOf('const useCookies = async (item) => {'),
    content.indexOf('// AI智能分析相关函数')
  );
  const saveQuickCookiesSource = content.slice(
    content.indexOf('const saveQuickCookies = async () => {'),
    content.indexOf('// 查看线上数据详情')
  );
  const forbiddenLegacyCookieRequestPattern = /request\(\s*['"]\/online-data\/(?:save-cookies|cookies-list|cookies-detail|delete-cookies|batch-delete-cookies)(?:[?'"])/;
  if (!content.includes('凭据统一由平台配置保管')
    || !content.includes('旧 Cookie 列表、明文详情和快速保存入口已停用。请在平台采集源中新增或更换携程凭据；浏览器不会再读取已保存的完整 Cookie。')
    || !content.includes('<button @click="openPlatformSourcesTab"')
    || !loadCookiesListSource.includes('cookiesList.value = [];')
    || !loadCookiesListSource.includes('selectedCookieKeys.value = [];')
    || loadCookiesListSource.includes('request(')
    || !loadCookieDetailSource.includes("throw new Error('旧 Cookie 明文详情已停用，请在平台采集源中更换凭据');")
    || loadCookieDetailSource.includes('request(')
    || !saveCookiesConfigSource.includes("showToast('旧 Cookie 保存已停用，请在平台采集源中更换凭据', 'warning');")
    || !saveCookiesConfigSource.includes('openPlatformSourcesTab();')
    || saveCookiesConfigSource.includes('request(')
    || !deleteCookiesConfigSource.includes("showToast('旧 Cookie 删除入口已停用，请在平台配置中吊销对应凭据', 'warning');")
    || !deleteCookiesConfigSource.includes('openPlatformSourcesTab();')
    || deleteCookiesConfigSource.includes('request(')
    || !batchDeleteCookiesConfigSource.includes('selectedCookieKeys.value = [];')
    || !batchDeleteCookiesConfigSource.includes("showToast('旧 Cookie 批量删除入口已停用，请在平台配置中逐项吊销凭据', 'warning');")
    || !batchDeleteCookiesConfigSource.includes('openPlatformSourcesTab();')
    || batchDeleteCookiesConfigSource.includes('request(')
    || !useCookiesSource.includes("showToast('浏览器不再读取已保存的完整 Cookie，请选择平台配置凭据', 'warning');")
    || !useCookiesSource.includes('openPlatformSourcesTab();')
    || useCookiesSource.includes('request(')
    || !saveQuickCookiesSource.includes("showToast('旧 Cookie 快速保存已停用，请在平台采集源中更换凭据', 'warning');")
    || !saveQuickCookiesSource.includes('openPlatformSourcesTab();')
    || saveQuickCookiesSource.includes('request(')
    || forbiddenLegacyCookieRequestPattern.test(content)) {
    failures.push('public/index.html legacy Cookie list/detail/save/delete/use actions must stay disabled, request-free, and route users to platform credential sources.');
  }
  if (!/await loadCtripConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);\s*if \(currentPage\.value !== 'ctrip-ebooking'\) return null;/.test(content)
    || !content.includes('const shouldApplySelectedConfig = options.applySelectedConfig === true;')
    || !/if \(selectedCtripHotelId\.value && shouldApplySelectedConfig\) \{[\s\S]*deferUiTask\(\(\) => applyCtripHotelConfig\(false, \{[\s\S]*refreshList: false,[\s\S]*skipIfAligned: true,/.test(content)
    || content.includes('prewarmSelectedCtripConfigSecret')
    || content.includes("if (selectedCtripHotelId.value) {\n                                await applyCtripHotelConfig(false);\n                            }\n                            return ctripConfigList.value;")) {
    failures.push('public/index.html Ctrip config list must return metadata after list data and only apply selected metadata when explicitly requested.');
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
    || !openCtripCookieEditorFromHealthSource.includes('const config = resolveCtripConfigMetadata(listConfig || findCtripConfigMetadataById(configId));')
    || !openCtripCookieEditorFromHealthSource.includes("throw new Error('未找到对应携程配置');")
    || openCtripCookieEditorFromHealthSource.includes('await loadCtripConfigList();')
    || openCtripCookieEditorFromHealthSource.includes('loadCtripConfigDetail')
    || openCtripCookieEditorFromHealthSource.includes('ensureCtripConfigSecret')) {
    failures.push('public/index.html Ctrip health Cookie editor must use exact metadata and keep replacement input blank instead of reading stored credentials.');
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
    || !content.includes("const resolveMeituanManualDefaultHotelIdFromState = requireMeituanStatic('resolveMeituanManualDefaultHotelIdFromState');")
    || !content.includes('return resolveMeituanManualDefaultHotelIdFromState({')
    || !meituanStaticContent.includes('const resolveMeituanManualDefaultHotelIdFromState = ({')
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
  if (!content.includes('const resolveMeituanConfigMetadata = (config) => config || null;')
    || !content.includes('const config = resolveMeituanConfigMetadata(options.resolvedConfig || selectedMeituanHotelConfig.value);')
    || !content.includes("meituanForm.value.cookies = '';")
    || !content.includes('meituanForm.value.auth_data = {};')
    || !meituanStaticContent.includes('if (!isMeituanRankingFormAlignedWithConfig(form, selectedMeituanConfig)) {')
    || !meituanStaticContent.includes('skipIfAligned: true,')
    || meituanStaticContent.includes('await applyMeituanHotelConfig(false);')
    || !/await loadMeituanConfigList\(\{\s*cacheMs: MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS,\s*applySelectedConfig: false,\s*\}\);\s*if \(currentPage\.value !== 'meituan-ebooking'\) return null;/.test(content)
    || !content.includes('const shouldApplySelectedConfig = options.applySelectedConfig === true;')
    || !content.includes('const applyAction = resolveMeituanConfigListApplyAction({')
    || !content.includes('if (applyAction.shouldApply) {')
    || content.includes('const ensureMeituanConfigSecret = async')
    || content.includes('const prewarmSelectedMeituanConfigSecret =')
    || content.includes('const loadMeituanConfigDetail = async')
    || content.includes('meituanConfigDetailCache.set(')
    || content.includes("if (meituanForm.value.hotelId) {\n                                await applyMeituanHotelConfig(false, { refreshList: false });\n                            }\n                            return meituanConfigList.value;")) {
    failures.push('public/index.html Meituan config list/application must remain metadata-only and never prewarm or cache full credential detail.');
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
  const switchMeituanCaptureTabSource = content.slice(
    content.indexOf('const switchMeituanCaptureTab = async (tab, sections = []) => {'),
    content.indexOf('const runMeituanBrowserCaptureForSections')
  );
  if (!content.includes("const buildMeituanCaptureTabSwitchState = requireMeituanStatic('buildMeituanCaptureTabSwitchState');")
    || !meituanStaticContent.includes('const buildMeituanCaptureTabSwitchState = ({')
    || !switchMeituanCaptureTabSource.includes('const switchState = buildMeituanCaptureTabSwitchState({ tab, sections });')
    || !switchMeituanCaptureTabSource.includes('onlineDataTab.value = switchState.tab;')
    || !switchMeituanCaptureTabSource.includes('meituanBrowserCaptureForm.value.captureSections = switchState.captureSections;')
    || !switchMeituanCaptureTabSource.includes('meituanBrowserCaptureResult.value = switchState.captureResult;')
    || !switchMeituanCaptureTabSource.includes('if (switchState.shouldSyncTrafficConfig) {')
    || switchMeituanCaptureTabSource.includes('normalizeMeituanCaptureSections(sections)')
    || switchMeituanCaptureTabSource.includes("if (tab === 'meituan-traffic')")) {
    failures.push('public/index.html Meituan browser capture tab state must stay in public/meituan-static.js.');
  }
  const runMeituanBrowserCaptureForSectionsSource = content.slice(
    content.indexOf('const runMeituanBrowserCaptureForSections = async (sections = [], options = {}) => {'),
    content.indexOf('const runMeituanBrowserCapturePreset')
  );
  if (!content.includes("const buildMeituanBrowserCaptureRunSectionsState = requireMeituanStatic('buildMeituanBrowserCaptureRunSectionsState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserCaptureRunSectionsState = (sections = []) => ({')
    || !runMeituanBrowserCaptureForSectionsSource.includes('const runSectionsState = buildMeituanBrowserCaptureRunSectionsState(sections);')
    || !runMeituanBrowserCaptureForSectionsSource.includes('meituanBrowserCaptureForm.value.captureSections = runSectionsState.captureSections;')
    || !runMeituanBrowserCaptureForSectionsSource.includes('await runMeituanBrowserCapture(options);')
    || runMeituanBrowserCaptureForSectionsSource.includes('normalizeMeituanCaptureSections(sections)')) {
    failures.push('public/index.html Meituan browser capture run-sections state must stay in public/meituan-static.js.');
  }
  const runMeituanBrowserCapturePresetSource = content.slice(
    content.indexOf('const runMeituanBrowserCapturePreset = async (preset = {}) => {'),
    content.indexOf('const runMeituanBrowserSupplementCapture')
  );
  if (!content.includes("const buildMeituanBrowserCapturePresetState = requireMeituanStatic('buildMeituanBrowserCapturePresetState');")
    || !content.includes("const buildMeituanBrowserCaptureDataPeriodApplyState = requireMeituanStatic('buildMeituanBrowserCaptureDataPeriodApplyState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserCapturePresetState = ({')
    || !meituanStaticContent.includes("const buildMeituanBrowserCaptureDataPeriodApplyState = (dataPeriod = '') => {")
    || !runMeituanBrowserCapturePresetSource.includes('const presetState = buildMeituanBrowserCapturePresetState({')
    || !runMeituanBrowserCapturePresetSource.includes('currentDataPeriod: meituanBrowserCaptureForm.value.dataPeriod,')
    || !runMeituanBrowserCapturePresetSource.includes('const dataPeriodApplyState = buildMeituanBrowserCaptureDataPeriodApplyState(presetState.dataPeriod);')
    || !runMeituanBrowserCapturePresetSource.includes('if (dataPeriodApplyState.shouldApply) {')
    || !runMeituanBrowserCapturePresetSource.includes('meituanBrowserCaptureForm.value.dataPeriod = dataPeriodApplyState.dataPeriod;')
    || !runMeituanBrowserCapturePresetSource.includes('runMeituanBrowserCaptureForSections(presetState.captureSections, { dataPeriod: dataPeriodApplyState.dataPeriod });')
    || runMeituanBrowserCapturePresetSource.includes('preset.dataPeriod || preset.data_period')
    || runMeituanBrowserCapturePresetSource.includes('if (presetState.dataPeriod)')
    || runMeituanBrowserCapturePresetSource.includes('meituanBrowserCaptureForm.value.dataPeriod = presetState.dataPeriod;')
    || runMeituanBrowserCapturePresetSource.includes('runMeituanBrowserCaptureForSections(preset.sections || []')) {
    failures.push('public/index.html Meituan browser capture preset state must stay in public/meituan-static.js.');
  }
  const runMeituanBrowserSupplementCaptureSource = content.slice(
    content.indexOf('const runMeituanBrowserSupplementCapture = async () => {'),
    content.indexOf('const copyMeituanBrowserCaptureCommand')
  );
  if (!content.includes("const buildMeituanBrowserSupplementCaptureState = requireMeituanStatic('buildMeituanBrowserSupplementCaptureState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserSupplementCaptureState = ({')
    || !runMeituanBrowserSupplementCaptureSource.includes('const supplementState = buildMeituanBrowserSupplementCaptureState({')
    || !runMeituanBrowserSupplementCaptureSource.includes('autoFetchHotelId: autoFetchHotelId.value,')
    || !runMeituanBrowserSupplementCaptureSource.includes('formHotelId: meituanForm.value.hotelId,')
    || !runMeituanBrowserSupplementCaptureSource.includes('userHotelId: user.value?.hotel_id,')
    || !runMeituanBrowserSupplementCaptureSource.includes('showToast(supplementState.message, supplementState.level);')
    || !runMeituanBrowserSupplementCaptureSource.includes('meituanForm.value.hotelId = supplementState.hotelId;')
    || !runMeituanBrowserSupplementCaptureSource.includes('runMeituanBrowserCaptureForSections(supplementState.captureSections, { dataPeriod: supplementState.dataPeriod });')
    || runMeituanBrowserSupplementCaptureSource.includes("runMeituanBrowserCaptureForSections(['full'], { dataPeriod: 'historical_daily' })")
    || runMeituanBrowserSupplementCaptureSource.includes('autoFetchHotelId.value || meituanForm.value.hotelId || user.value?.hotel_id')) {
    failures.push('public/index.html Meituan browser supplement capture state must stay in public/meituan-static.js.');
  }
  const copyMeituanBrowserCaptureCommandSource = content.slice(
    content.indexOf('const copyMeituanBrowserCaptureCommand = () => {'),
    content.indexOf('const clearMeituanBrowserCapturePayload')
  );
  if (!content.includes("const buildMeituanBrowserCaptureCopyCommandState = requireMeituanStatic('buildMeituanBrowserCaptureCopyCommandState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserCaptureCopyCommandState = ({')
    || !copyMeituanBrowserCaptureCommandSource.includes('const copyState = buildMeituanBrowserCaptureCopyCommandState({')
    || !copyMeituanBrowserCaptureCommandSource.includes('storeId: meituanBrowserCaptureForm.value.storeId,')
    || !copyMeituanBrowserCaptureCommandSource.includes('formHotelId: meituanForm.value.hotelId,')
    || !copyMeituanBrowserCaptureCommandSource.includes('userHotelId: user.value?.hotel_id,')
    || !copyMeituanBrowserCaptureCommandSource.includes('if (!copyState.canCopy) {')
    || !copyMeituanBrowserCaptureCommandSource.includes('showToast(copyState.message, copyState.level);')
    || copyMeituanBrowserCaptureCommandSource.includes('!meituanBrowserCaptureForm.value.storeId')
    || copyMeituanBrowserCaptureCommandSource.includes('!(meituanForm.value.hotelId || user.value?.hotel_id)')) {
    failures.push('public/index.html Meituan browser capture copy-command state must stay in public/meituan-static.js.');
  }
  const clearMeituanBrowserCapturePayloadStart = content.indexOf('const clearMeituanBrowserCapturePayload = () => {');
  const clearMeituanBrowserCapturePayloadSource = content.slice(
    clearMeituanBrowserCapturePayloadStart,
    content.indexOf('const runMeituanBrowserCapture', clearMeituanBrowserCapturePayloadStart)
  );
  if (!content.includes("const buildMeituanBrowserCaptureClearPayloadState = requireMeituanStatic('buildMeituanBrowserCaptureClearPayloadState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserCaptureClearPayloadState = () => ({')
    || !clearMeituanBrowserCapturePayloadSource.includes('const clearState = buildMeituanBrowserCaptureClearPayloadState();')
    || !clearMeituanBrowserCapturePayloadSource.includes('meituanBrowserCaptureForm.value.payloadJson = clearState.payloadJson;')
    || !clearMeituanBrowserCapturePayloadSource.includes('meituanBrowserCaptureResult.value = clearState.captureResult;')
    || clearMeituanBrowserCapturePayloadSource.includes("meituanBrowserCaptureForm.value.payloadJson = '';")
    || clearMeituanBrowserCapturePayloadSource.includes('meituanBrowserCaptureResult.value = null;')) {
    failures.push('public/index.html Meituan browser capture clear-payload state must stay in public/meituan-static.js.');
  }
  const runMeituanBrowserCaptureSource = content.slice(
    content.indexOf('const runMeituanBrowserCapture = async (options = {}) => runMeituanBrowserCaptureFlow({'),
    content.indexOf('const runMeituanBrowserProfileLoginOnly')
  );
  const saveMeituanCapturedPayloadSource = content.slice(
    content.indexOf('const saveMeituanCapturedPayload = async () => runMeituanCapturedPayloadSaveFlow({'),
    content.indexOf('const goConfigureMeituanForSelectedHotel')
  );
  if (!content.includes("const resolveMeituanBrowserCaptureSystemHotelId = requireMeituanStatic('resolveMeituanBrowserCaptureSystemHotelId');")
    || !meituanStaticContent.includes('const resolveMeituanBrowserCaptureSystemHotelId = ({')
    || !runMeituanBrowserCaptureSource.includes('getSystemHotelId: () => resolveMeituanBrowserCaptureSystemHotelId({')
    || !runMeituanBrowserCaptureSource.includes('formHotelId: meituanForm.value.hotelId,')
    || !runMeituanBrowserCaptureSource.includes('autoFetchHotelId: autoFetchHotelId.value,')
    || !runMeituanBrowserCaptureSource.includes('userHotelId: user.value?.hotel_id,')
    || !saveMeituanCapturedPayloadSource.includes('getSystemHotelId: () => resolveMeituanBrowserCaptureSystemHotelId({')
    || !saveMeituanCapturedPayloadSource.includes('formHotelId: meituanForm.value.hotelId,')
    || !saveMeituanCapturedPayloadSource.includes('userHotelId: user.value?.hotel_id,')
    || runMeituanBrowserCaptureSource.includes('meituanForm.value.hotelId || autoFetchHotelId.value || user.value?.hotel_id')
    || saveMeituanCapturedPayloadSource.includes('meituanForm.value.hotelId || user.value?.hotel_id')) {
    failures.push('public/index.html Meituan browser capture system hotel id resolution must stay in public/meituan-static.js.');
  }
  const goConfigureMeituanForSelectedHotelSource = content.slice(
    content.indexOf('const goConfigureMeituanForSelectedHotel = async () => {'),
    content.indexOf('const buildHotelOtaConfig')
  );
  if (!content.includes("const resolveMeituanSelectedHotelConfigAction = requireMeituanStatic('resolveMeituanSelectedHotelConfigAction');")
    || !meituanStaticContent.includes('const resolveMeituanSelectedHotelConfigAction = ({')
    || !goConfigureMeituanForSelectedHotelSource.includes('const action = resolveMeituanSelectedHotelConfigAction({')
    || !goConfigureMeituanForSelectedHotelSource.includes('hotels: hotels.value,')
    || !goConfigureMeituanForSelectedHotelSource.includes('hotelId: meituanForm.value.hotelId,')
    || !goConfigureMeituanForSelectedHotelSource.includes('showToast(action.message, action.level);')
    || !goConfigureMeituanForSelectedHotelSource.includes('await openHotelManualFetchConfig(action.hotel, action.platform);')
    || goConfigureMeituanForSelectedHotelSource.includes('hotels.value.find')
    || goConfigureMeituanForSelectedHotelSource.includes("openHotelManualFetchConfig(hotel, 'meituan')")) {
    failures.push('public/index.html Meituan selected hotel config action must stay in public/meituan-static.js.');
  }
  const returnToMeituanRankingAfterConfigSaveSource = content.slice(
    content.indexOf('const returnToMeituanRankingAfterConfigSave = async (hotelId) => {'),
    content.indexOf('let manualOnlineFetchConfigReadyPromise')
  );
  if (!content.includes("const buildMeituanRankingReturnTargetState = requireMeituanStatic('buildMeituanRankingReturnTargetState');")
    || !meituanStaticContent.includes('const buildMeituanRankingReturnTargetState = ({')
    || !returnToMeituanRankingAfterConfigSaveSource.includes('const returnState = buildMeituanRankingReturnTargetState({')
    || !returnToMeituanRankingAfterConfigSaveSource.includes('currentHotelId: meituanForm.value.hotelId,')
    || !returnToMeituanRankingAfterConfigSaveSource.includes('currentPage.value = returnState.page;')
    || !returnToMeituanRankingAfterConfigSaveSource.includes('onlineDataTab.value = returnState.tab;')
    || !returnToMeituanRankingAfterConfigSaveSource.includes('const afterReloadState = buildMeituanRankingReturnTargetState({')
    || returnToMeituanRankingAfterConfigSaveSource.includes("currentPage.value = 'meituan-ebooking';")
    || returnToMeituanRankingAfterConfigSaveSource.includes("onlineDataTab.value = 'meituan-ranking';")
    || returnToMeituanRankingAfterConfigSaveSource.includes("String(hotelId || '').trim()")) {
    failures.push('public/index.html Meituan ranking return target state must stay in public/meituan-static.js.');
  }
  const saveMeituanConfigItemSource = content.slice(
    content.indexOf('const saveMeituanConfigItem = async () => {'),
    content.indexOf('const useMeituanConfig')
  );
  if (!content.includes("const resolveMeituanConfigSaveCookieState = requireMeituanStatic('resolveMeituanConfigSaveCookieState');")
    || !meituanStaticContent.includes("const resolveMeituanConfigSaveCookieState = (cookies = '', options = {}) => {")
    || !saveMeituanConfigItemSource.includes('const cookieState = resolveMeituanConfigSaveCookieState(meituanConfigForm.value.cookies, {')
    || !saveMeituanConfigItemSource.includes("String(meituanConfigForm.value.credential_status || '') === 'ready'")
    || !saveMeituanConfigItemSource.includes('showToast(cookieState.message, cookieState.level);')
    || !saveMeituanConfigItemSource.includes('cookies: cookieState.cookies,')
    || saveMeituanConfigItemSource.includes("String(meituanConfigForm.value.cookies || '').trim()")
    || saveMeituanConfigItemSource.includes("showToast('请输入临时 Cookie/API 辅助内容', 'error')")) {
    failures.push('public/index.html Meituan config-save Cookie state must stay in public/meituan-static.js.');
  }
  if (!content.includes("const resolveMeituanConfigSaveRequestHotelId = requireMeituanStatic('resolveMeituanConfigSaveRequestHotelId');")
    || !meituanStaticContent.includes('const resolveMeituanConfigSaveRequestHotelId = ({')
    || !saveMeituanConfigItemSource.includes('const requestHotelId = resolveMeituanConfigSaveRequestHotelId({')
    || !saveMeituanConfigItemSource.includes('formHotelId: meituanConfigForm.value.hotel_id,')
    || !saveMeituanConfigItemSource.includes('rankingHotelId: meituanForm.value.hotelId,')
    || !saveMeituanConfigItemSource.includes('filterHotelId: onlineDataFilter.value.hotel_id,')
    || !saveMeituanConfigItemSource.includes('userHotelId: user.value?.hotel_id,')
    || saveMeituanConfigItemSource.includes('const requestHotelId = String(')) {
    failures.push('public/index.html Meituan config-save request hotel id selection must stay in public/meituan-static.js.');
  }
  if (!content.includes("const buildMeituanConfigSaveSuccessState = requireMeituanStatic('buildMeituanConfigSaveSuccessState');")
    || !content.includes("const buildMeituanConfigSaveFailureState = requireMeituanStatic('buildMeituanConfigSaveFailureState');")
    || content.includes("const resolveSavedMeituanConfigHotelId = requireMeituanStatic('resolveSavedMeituanConfigHotelId');")
    || content.includes("const resolveMeituanConfigSaveToastLevel = requireMeituanStatic('resolveMeituanConfigSaveToastLevel');")
    || !meituanStaticContent.includes('const buildMeituanConfigSaveSuccessState = ({')
    || !meituanStaticContent.includes('const buildMeituanConfigSaveFailureState = ({')
    || !saveMeituanConfigItemSource.includes('const saveSuccessState = buildMeituanConfigSaveSuccessState({')
    || !saveMeituanConfigItemSource.includes('response: res,')
    || !saveMeituanConfigItemSource.includes('form: meituanConfigForm.value,')
    || !saveMeituanConfigItemSource.includes('showToast(saveSuccessState.toastMessage, saveSuccessState.toastLevel);')
    || !saveMeituanConfigItemSource.includes('clearMeituanConfigDetailCache(saveSuccessState.clearConfigDetailId);')
    || !saveMeituanConfigItemSource.includes('meituanConfigForm.value = saveSuccessState.resetForm;')
    || !saveMeituanConfigItemSource.includes('await returnToMeituanRankingAfterConfigSave(saveSuccessState.savedHotelId);')
    || !saveMeituanConfigItemSource.includes('} else if (saveSuccessState.shouldReloadConfigList) {')
    || !saveMeituanConfigItemSource.includes('const saveFailureState = buildMeituanConfigSaveFailureState({ response: res });')
    || !saveMeituanConfigItemSource.includes('const saveFailureState = buildMeituanConfigSaveFailureState({ error: e });')
    || !saveMeituanConfigItemSource.includes('showToast(saveFailureState.toastMessage, saveFailureState.toastLevel);')
    || saveMeituanConfigItemSource.includes('const savedHotelId = resolveSavedMeituanConfigHotelId({')
    || saveMeituanConfigItemSource.includes('resolveMeituanConfigSaveToastLevel(res.data)')
    || saveMeituanConfigItemSource.includes('meituanConfigForm.value = createEmptyMeituanConfigForm();')
    || saveMeituanConfigItemSource.includes("showToast(res.message || '保存失败', 'error')")
    || saveMeituanConfigItemSource.includes("showToast('保存失败: ' + e.message, 'error')")) {
    failures.push('public/index.html Meituan config-save success state must stay in public/meituan-static.js.');
  }
  const useMeituanConfigSource = content.slice(
    content.indexOf('const useMeituanConfig = async (config) => {'),
    content.indexOf('const editMeituanConfig')
  );
  if (!content.includes("const buildMeituanConfigUseState = requireMeituanStatic('buildMeituanConfigUseState');")
    || content.includes("const buildMeituanRankingFormPatchFromConfig = requireMeituanStatic('buildMeituanRankingFormPatchFromConfig');")
    || !meituanStaticContent.includes('const buildMeituanConfigUseState = ({')
    || !useMeituanConfigSource.includes('const useState = buildMeituanConfigUseState({')
    || !useMeituanConfigSource.includes('config,')
    || !useMeituanConfigSource.includes('fallbackHotelId: meituanForm.value.hotelId,')
    || !useMeituanConfigSource.includes('Object.assign(meituanForm.value, useState.formPatch);')
    || !useMeituanConfigSource.includes('showToast(useState.toastMessage);')
    || !useMeituanConfigSource.includes('onlineDataTab.value = useState.targetTab;')
    || useMeituanConfigSource.includes('buildMeituanRankingFormPatchFromConfig(config, meituanForm.value.hotelId)')
    || useMeituanConfigSource.includes("onlineDataTab.value = 'meituan-ranking';")
    || useMeituanConfigSource.includes('showToast(`已应用配置: ${config.name}`)')) {
    failures.push('public/index.html Meituan config-use state must stay in public/meituan-static.js.');
  }
  const editMeituanConfigSource = content.slice(
    content.indexOf('const editMeituanConfig = async (config) => {'),
    content.indexOf('const deleteMeituanConfigItem')
  );
  if (!content.includes("const buildMeituanConfigEditState = requireMeituanStatic('buildMeituanConfigEditState');")
    || content.includes("const buildMeituanConfigEditForm = requireMeituanStatic('buildMeituanConfigEditForm');")
    || !meituanStaticContent.includes('const buildMeituanConfigEditState = ({')
    || !editMeituanConfigSource.includes('const editState = buildMeituanConfigEditState({ config });')
    || !editMeituanConfigSource.includes('meituanConfigForm.value = editState.form;')
    || editMeituanConfigSource.includes('meituanConfigForm.value = buildMeituanConfigEditForm(config);')) {
    failures.push('public/index.html Meituan config-edit state must stay in public/meituan-static.js.');
  }
  const deleteMeituanConfigItemSource = content.slice(
    content.indexOf('const deleteMeituanConfigItem = async (id) => {'),
    content.indexOf('const generateMeituanBookmarklet')
  );
  if (!content.includes("const buildMeituanConfigDeleteSuccessState = requireMeituanStatic('buildMeituanConfigDeleteSuccessState');")
    || !content.includes("const buildMeituanConfigDeleteFailureState = requireMeituanStatic('buildMeituanConfigDeleteFailureState');")
    || !meituanStaticContent.includes("const buildMeituanConfigDeleteSuccessState = (id = '') => ({")
    || !meituanStaticContent.includes('const buildMeituanConfigDeleteFailureState = ({')
    || !deleteMeituanConfigItemSource.includes('const deleteSuccessState = buildMeituanConfigDeleteSuccessState(id);')
    || !deleteMeituanConfigItemSource.includes('showToast(deleteSuccessState.toastMessage, deleteSuccessState.toastLevel);')
    || !deleteMeituanConfigItemSource.includes('clearMeituanConfigDetailCache(deleteSuccessState.clearConfigDetailId);')
    || !deleteMeituanConfigItemSource.includes('loadMeituanConfigList(deleteSuccessState.reloadOptions);')
    || !deleteMeituanConfigItemSource.includes('const deleteFailureState = buildMeituanConfigDeleteFailureState({ response: res });')
    || !deleteMeituanConfigItemSource.includes('const deleteFailureState = buildMeituanConfigDeleteFailureState({ error: e });')
    || !deleteMeituanConfigItemSource.includes('showToast(deleteFailureState.toastMessage, deleteFailureState.toastLevel);')
    || deleteMeituanConfigItemSource.includes("showToast('删除成功')")
    || deleteMeituanConfigItemSource.includes("showToast(res.message || '删除失败', 'error')")
    || deleteMeituanConfigItemSource.includes("showToast('删除失败: ' + e.message, 'error')")
    || deleteMeituanConfigItemSource.includes('clearMeituanConfigDetailCache(id);')
    || deleteMeituanConfigItemSource.includes('loadMeituanConfigList();')) {
    failures.push('public/index.html Meituan config-delete state must stay in public/meituan-static.js.');
  }
  const generateMeituanBookmarkletSource = content.slice(
    content.indexOf('const generateMeituanBookmarklet = async () => {'),
    content.indexOf('const fetchCustomData')
  );
  if (!content.includes("const buildMeituanBookmarkletSuccessState = requireMeituanStatic('buildMeituanBookmarkletSuccessState');")
    || !content.includes("const buildMeituanBookmarkletFailureState = requireMeituanStatic('buildMeituanBookmarkletFailureState');")
    || !meituanStaticContent.includes('const buildMeituanBookmarkletSuccessState = (response = {}) => ({')
    || !meituanStaticContent.includes('const buildMeituanBookmarkletFailureState = ({')
    || !generateMeituanBookmarkletSource.includes('const successState = buildMeituanBookmarkletSuccessState(res);')
    || !generateMeituanBookmarkletSource.includes('meituanBookmarklet.value = successState.bookmarklet;')
    || !generateMeituanBookmarkletSource.includes('showToast(successState.toastMessage, successState.toastLevel);')
    || !generateMeituanBookmarkletSource.includes('const failureState = buildMeituanBookmarkletFailureState({ error: e });')
    || !generateMeituanBookmarkletSource.includes('showToast(failureState.toastMessage, failureState.toastLevel);')
    || generateMeituanBookmarkletSource.includes('meituanBookmarklet.value = res.data.bookmarklet;')
    || generateMeituanBookmarkletSource.includes("showToast(res.data?.message || '旧版美团 Cookie 书签已禁用', 'warning')")
    || generateMeituanBookmarkletSource.includes("showToast('生成失败: ' + e.message, 'error')")) {
    failures.push('public/index.html Meituan bookmarklet state must stay in public/meituan-static.js.');
  }
  const runMeituanBrowserProfileLoginOnlySource = content.slice(
    content.indexOf('const runMeituanBrowserProfileLoginOnly = async () => {'),
    content.indexOf('const saveMeituanCapturedPayload')
  );
  if (!content.includes("const buildMeituanBrowserProfileLoginOnlyRunOptions = requireMeituanStatic('buildMeituanBrowserProfileLoginOnlyRunOptions');")
    || !meituanStaticContent.includes('const buildMeituanBrowserProfileLoginOnlyRunOptions = () => ({')
    || !runMeituanBrowserProfileLoginOnlySource.includes('const loginOnlyOptions = buildMeituanBrowserProfileLoginOnlyRunOptions();')
    || !runMeituanBrowserProfileLoginOnlySource.includes('await runMeituanBrowserCapture(loginOnlyOptions);')
    || runMeituanBrowserProfileLoginOnlySource.includes('runMeituanBrowserCapture({ loginOnly: true, bindDataSource: true })')) {
    failures.push('public/index.html Meituan browser profile login-only options must stay in public/meituan-static.js.');
  }
  const syncMeituanBrowserCaptureFromSelectedConfigSource = content.slice(
    content.indexOf('const syncMeituanBrowserCaptureFromSelectedConfig = async (showMessage = false) => {'),
    content.indexOf('const switchMeituanCaptureTab')
  );
  if (!content.includes("const buildMeituanBrowserCaptureConfigSyncState = requireMeituanStatic('buildMeituanBrowserCaptureConfigSyncState');")
    || !meituanStaticContent.includes('const buildMeituanBrowserCaptureConfigSyncState = ({')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('const syncState = buildMeituanBrowserCaptureConfigSyncState({')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('formPoiId: meituanForm.value.poiId,')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('captureForm: meituanBrowserCaptureForm.value,')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('Object.assign(meituanBrowserCaptureForm.value, syncState.formUpdates);')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('if (!syncState.hasHotel) {')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('meituanForm.value.poiId = syncState.rankingPoiId;')
    || !syncMeituanBrowserCaptureFromSelectedConfigSource.includes('showMessage === true && syncState.shouldNotify')
    || syncMeituanBrowserCaptureFromSelectedConfigSource.includes('firstNonEmptyText(')
    || syncMeituanBrowserCaptureFromSelectedConfigSource.includes('firstDataConfigValue(')) {
    failures.push('public/index.html Meituan browser capture config-sync state must stay in public/meituan-static.js.');
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
  if (!content.includes('const ctripTargetHotelOptions = computed(() => {')
    || !content.includes('const meituanTargetHotelOptions = computed(() => {')
    || !content.includes('v-for="hotel in ctripTargetHotelOptions"')
    || !content.includes('v-for="hotel in meituanTargetHotelOptions"')
    || !content.includes('仅显示已配置酒店')) {
    failures.push('public/index.html manual OTA target hotel selects must only list platform-configured hotels.');
  }
  if (!content.includes(':disabled="fetchingData || !canFetchMeituanRankingData()"')
    || !content.includes('const meituanManualFetchConfigProofPending = () => {')
    || !content.includes('const canFetchMeituanRankingData = () => {')
    || !content.includes("const resolveCanFetchMeituanRankingData = requireMeituanStatic('resolveCanFetchMeituanRankingData');")
    || !content.includes("const resolveMeituanManualFetchConfigProofPending = requireMeituanStatic('resolveMeituanManualFetchConfigProofPending');")
    || !content.includes("const resolveMeituanManualFetchConfigCandidate = requireMeituanStatic('resolveMeituanManualFetchConfigCandidate');")
    || !content.includes("const resolveMeituanConfigListResponse = requireMeituanStatic('resolveMeituanConfigListResponse');")
    || !content.includes("const resolveMeituanConfigListApplyAction = requireMeituanStatic('resolveMeituanConfigListApplyAction');")
    || !content.includes("const resolveMeituanConfigListCachedResult = requireMeituanStatic('resolveMeituanConfigListCachedResult');")
    || !content.includes("const resolveMeituanConfigListLoadingAction = requireMeituanStatic('resolveMeituanConfigListLoadingAction');")
    || !content.includes("const buildMeituanConfigListSuccessState = requireMeituanStatic('buildMeituanConfigListSuccessState');")
    || !content.includes("const buildMeituanConfigListFailureAction = requireMeituanStatic('buildMeituanConfigListFailureAction');")
    || !content.includes("const buildMeituanConfigListStartState = requireMeituanStatic('buildMeituanConfigListStartState');")
    || !content.includes("const buildMeituanConfigListFinishState = requireMeituanStatic('buildMeituanConfigListFinishState');")
    || !content.includes('return resolveCanFetchMeituanRankingData({')
    || !meituanStaticContent.includes('const resolveCanFetchMeituanRankingData = ({')
    || !meituanStaticContent.includes('const resolveMeituanManualFetchConfigProofPending = ({')
    || !meituanStaticContent.includes('const resolveMeituanManualFetchConfigCandidate = ({')
    || !meituanStaticContent.includes('const resolveMeituanConfigListResponse = (res = {}) => {')
    || !meituanStaticContent.includes('const resolveMeituanConfigListApplyAction = ({')
    || !meituanStaticContent.includes('const resolveMeituanConfigListCachedResult = ({')
    || !meituanStaticContent.includes('const resolveMeituanConfigListLoadingAction = ({')
    || !meituanStaticContent.includes('const buildMeituanConfigListSuccessState = ({')
    || !meituanStaticContent.includes('const buildMeituanConfigListFailureAction = ({')
    || !meituanStaticContent.includes('const buildMeituanConfigListStartState = () => ({')
    || !meituanStaticContent.includes('const buildMeituanConfigListFinishState = () => ({')
    || !meituanStaticContent.includes('config?.has_cookies === true')
    || !meituanStaticContent.includes("return !!String(safeForm.hotelId || '').trim() && isMeituanExecutionConfigReady(selectedConfig);")
    || !content.includes('const cachedResult = resolveMeituanConfigListCachedResult({')
    || !content.includes('if (cachedResult.hit) {')
    || !content.includes('return cachedResult.list;')
    || !content.includes('const loadingAction = resolveMeituanConfigListLoadingAction({')
    || !content.includes("if (loadingAction.status === 'reuse') {")
    || !content.includes('return loadingAction.promise;')
    || !content.includes("if (loadingAction.status === 'await_previous') {")
    || !content.includes('await loadingAction.promise.catch(() => []);')
    || !content.includes('const startState = buildMeituanConfigListStartState();')
    || !content.includes('meituanConfigListLoading.value = startState.loading;')
    || !content.includes('meituanConfigListLoadFailed.value = startState.failed;')
    || !content.includes('const configListResult = resolveMeituanConfigListResponse(res);')
    || !content.includes('if (configListResult.ok) {')
    || !content.includes('const successState = buildMeituanConfigListSuccessState({')
    || !content.includes('meituanConfigList.value = successState.list;')
    || !content.includes('meituanConfigListLoaded.value = successState.loaded;')
    || !content.includes('meituanConfigListLoadedAt = successState.loadedAt;')
    || !content.includes('const applyAction = resolveMeituanConfigListApplyAction({')
    || !content.includes('if (applyAction.shouldApply) {')
    || !content.includes('const failureAction = buildMeituanConfigListFailureAction({')
    || !content.includes("type: 'api',")
    || !content.includes("type: 'exception',")
    || !content.includes('meituanConfigListLoadFailed.value = failureAction.failed;')
    || !content.includes('console.error(failureAction.label, failureAction.detail);')
    || !content.includes('const finishState = buildMeituanConfigListFinishState();')
    || !content.includes('meituanConfigListLoadingPromise = finishState.loadingPromise;')
    || !content.includes('meituanConfigListLoading.value = finishState.loading;')
    || !content.includes('const resolveMeituanManualFetchConfig = async (config) => {')
    || !content.includes('return resolveMeituanManualFetchConfigCandidate({')
    || !meituanStaticContent.includes('const isMeituanExecutionConfigReady = (config = null) => Boolean(')
    || !meituanStaticContent.includes('resolveMeituanExecutionConfigId(config)')
    || !meituanStaticContent.includes("String(config?.credential_status || '') === 'ready'")
    || !meituanStaticContent.includes("return !!String(safeForm.hotelId || '').trim() && isMeituanExecutionConfigReady(selectedConfig);")
    || content.includes('ensureMeituanConfigSecret')) {
    failures.push('public/index.html Meituan ranking manual fetch must require a ready saved credential locator and must not execute a temporary browser Cookie.');
  }
  if (!content.includes("const resolveMeituanConfigDetailClearTarget = requireMeituanStatic('resolveMeituanConfigDetailClearTarget');")
    || !content.includes('const clearTarget = resolveMeituanConfigDetailClearTarget(id);')
    || !content.includes('meituanConfigDetailCache.delete(cacheKey);')
    || !content.includes('meituanConfigDetailCache.clear();')
    || content.includes('meituanConfigDetailCache.set(')
    || content.includes('meituanConfigDetailLoadingPromises.set(')
    || content.includes('const loadMeituanConfigDetail = async')
    || content.includes('const ensureMeituanConfigSecret = async')
    || content.includes('const prewarmSelectedMeituanConfigSecret =')) {
    failures.push('public/index.html Meituan legacy cache compatibility may clear metadata keys only; it must not load, store, or prewarm credential details.');
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
  if (!/const ensureHotelOtaConfigLists = async[\s\S]*const shouldLoadCtripConfigList = force \|\| \(!ctripConfigListLoaded\.value && !ctripConfigList\.value\.length\)[\s\S]*const shouldLoadMeituanConfigList = force \|\| \(!meituanConfigListLoaded\.value && !meituanConfigList\.value\.length\)/.test(content)) {
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
    content.indexOf('const applyOnlineHistoryDatePreset = () => {')
  );
  if (!content.includes('const scheduleDownloadCenterTabLoad = (tab, context = {}) => {')
    || !content.includes('const switchDownloadTab = (tab) => {')
    || !content.includes('const switchToDownloadCenter = () => {')
    || !content.includes('const switchToMeituanDownloadCenter = () => {')
    || !meituanStaticContent.includes('const buildMeituanDownloadData = (rows = []) => {')
    || !content.includes('const meituanDownloadData = computed(() => buildMeituanDownloadData(onlineDataList.value));')
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
    || !content.includes("const platformAutoPanelsScript = 'components/online-data/platform-auto-settings-panels.js?v=20260712-meituan-ads-not-applicable';")
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
    || !platformAutoSettingsPanelsContent.includes('data-testid="meituan-browser-supplement-capture"')
    || !platformAutoSettingsPanelsContent.includes('ctx.runMeituanBrowserSupplementCapture')
    || !platformAutoSettingsPanelsContent.includes('ctx.autoFetchCollectionBlueprintRows')
    || !platformAutoSettingsPanelsContent.includes('ctx.meituanPlatformProfileStatusRow')
    || !platformAutoSettingsPanelsContent.includes('ctx.autoFetchPlatformResultRows')
    || !content.includes('const PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS = 2600;')
    || !content.includes('const platformAutoSecondaryPanelsReady = ref(false);')
    || !content.includes('const platformAutoSecondaryPanelsBody = shallowRef(null);')
    || !content.includes('const schedulePlatformAutoSecondaryPanelsReady = (delayMs = PLATFORM_AUTO_SECONDARY_PANEL_DELAY_MS) => {')
    || !/newTab === ['"]platform-auto['"][\s\S]*platformAutoSecondaryPanelsReady\.value = false;[\s\S]*schedulePlatformAutoSecondaryPanelsReady\(\);[\s\S]*return runIfCurrent\(\(\) => schedulePlatformAutoFetchPanelLoad\(options\)\);/.test(onlineDataTabSchedulerSource)) {
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
  if (!content.includes('const BUSINESS_CONTEXT_ENDPOINT_PREFIXES = [')
    || !content.includes('const withBusinessRequestContext = (url, options = {}) =>')
    || !content.includes('system_hotel_id')
    || !content.includes('tenant_id')
    || !content.includes('platform')) {
    failures.push('public/index.html must keep a unified request context layer for scoped OTA/revenue/operation requests.');
  }
  if (!content.includes('data-testid="platform-collection-type-breakdown"')
    || !content.includes('const platformCollectionTypeRows = computed(() => {')
    || !content.includes('发生了什么')
    || !content.includes('为什么重要')
    || !content.includes('负责人')) {
    failures.push('public/index.html must expose data-type collection status with operational next actions.');
  }
  if (!content.includes('const schedulePlatformCollectionStatusRefresh = () =>')
    || !content.includes('loadPlatformCollectionStatus({ force: true, cacheMs: 0 })')
    || !content.includes('schedulePlatformCollectionStatusRefresh();')) {
    failures.push('public/index.html must refresh collection-status after collection and platform-source mutations.');
  }
  const ctripReviewAutomationSource = content.slice(
    content.indexOf('const runCtripReviewMatchAutomation ='),
    content.indexOf('const bindCtripReviewOrderMatch =')
  );
  if (!ctripReviewAutomationSource.includes("review_collection_policy: 'explicit_review_match_only'")
    || /capture-ctrip-browser|comment_review|capture_sections/.test(ctripReviewAutomationSource)) {
    failures.push('public/index.html must keep Ctrip review order matching scoped to the explicit match action, without default capture entrypoints.');
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
  if (!homeStaticContent.includes('const normalizeHolidayCountdownItem = (item) => {')
    || !homeStaticContent.includes('const homeTrendBadgeClass = (level) => ({')
    || !homeStaticContent.includes('const homeTrendCardHasData = (card) => {')
    || !homeStaticContent.includes('const macroSignalLevelClass = (signal) => {')
    || !homeStaticContent.includes('const homeTextHasValue = (value) => {')
    || !homeStaticContent.includes('const competitorPlatformTagText = (summary) => {')
    || !homeStaticContent.includes('const competitorPlatformTagClass = (summary) => ({')
    || !homeStaticContent.includes('const holidayOperationStageText = (nearest = null) => {')
    || !homeStaticContent.includes('const buildHolidayOperationSuggestions = ({')
    || !homeStaticContent.includes('const buildMacroSignalFallback = (summary = ')
    || !homeStaticContent.includes('const buildMacroSignalViewCards = (signals = [], meaningMap = {}) => (')
    || !homeStaticContent.includes('const buildHomeMarketForecastItems = ({')
    || !homeStaticContent.includes('const homeMarketForecastStatus = (items = []) => {')
    || !homeStaticContent.includes('const buildHomeMarketForecastSummaryRows = (items = [], noteMap = {}) => (')
    || !homeStaticContent.includes('const resolveHomeMarketForecastAction = ({')
    || !homeStaticContent.includes('const homeMetricSeriesValues = (metrics = {}, key = ')
    || !homeStaticContent.includes('const homeMetricToneClass = (ready, level = ')
    || !homeStaticContent.includes('const homeSignalMetricText = (signal = null, labels = []) => {')
    || !homeStaticContent.includes('const competitorDisplayRows = (summary) => (')
    || !homeStaticContent.includes('const competitorSummarySourceNotice = (summary) => (')
    || !homeStaticContent.includes('const competitorSummaryReadinessClass = (readiness) => ({')
    || !homeStaticContent.includes('normalizeHolidayCountdownItem,')
    || !homeStaticContent.includes('competitorPlatformTagClass,')
    || !homeStaticContent.includes('buildHolidayOperationSuggestions,')
    || !homeStaticContent.includes('buildMacroSignalFallback,')
    || !homeStaticContent.includes('buildMacroSignalViewCards,')
    || !homeStaticContent.includes('resolveHomeMarketForecastAction,')
    || !homeStaticContent.includes('homeMetricSeriesSum,')
    || !homeStaticContent.includes('homeSignalMetricText,')
    || !homeStaticContent.includes('competitorSummaryReadinessClass,')
    || !content.includes("const normalizeHolidayCountdownItem = requireHomeStatic('normalizeHolidayCountdownItem');")
    || !content.includes("const homeTrendBadgeClass = requireHomeStatic('homeTrendBadgeClass');")
    || !content.includes("const homeTrendCardHasData = requireHomeStatic('homeTrendCardHasData');")
    || !content.includes("const macroSignalLevelClass = requireHomeStatic('macroSignalLevelClass');")
    || !content.includes("const homeTextHasValue = requireHomeStatic('homeTextHasValue');")
    || !content.includes("const competitorPlatformTagText = requireHomeStatic('competitorPlatformTagText');")
    || !content.includes("const competitorPlatformTagClass = requireHomeStatic('competitorPlatformTagClass');")
    || !content.includes("const holidayOperationStageTextFromStatic = requireHomeStatic('holidayOperationStageText');")
    || !content.includes("const buildHolidayOperationSuggestions = requireHomeStatic('buildHolidayOperationSuggestions');")
    || !content.includes("const buildMacroSignalFallback = requireHomeStatic('buildMacroSignalFallback');")
    || !content.includes("const buildMacroSignalViewCards = requireHomeStatic('buildMacroSignalViewCards');")
    || !content.includes("const buildHomeMarketForecastItems = requireHomeStatic('buildHomeMarketForecastItems');")
    || !content.includes("const homeMarketForecastStatusFromStatic = requireHomeStatic('homeMarketForecastStatus');")
    || !content.includes("const buildHomeMarketForecastSummaryRows = requireHomeStatic('buildHomeMarketForecastSummaryRows');")
    || !content.includes("const resolveHomeMarketForecastAction = requireHomeStatic('resolveHomeMarketForecastAction');")
    || !content.includes("const homeMetricSeriesSumFromStatic = requireHomeStatic('homeMetricSeriesSum');")
    || !content.includes("const homeMetricSeriesAvgFromStatic = requireHomeStatic('homeMetricSeriesAvg');")
    || !content.includes("const homeMetricToneClass = requireHomeStatic('homeMetricToneClass');")
    || !content.includes("const homeSignalMetricTextFromStatic = requireHomeStatic('homeSignalMetricText');")
    || !content.includes("const competitorDisplayRows = requireHomeStatic('competitorDisplayRows');")
    || !content.includes("const competitorDisplaySummary = requireHomeStatic('competitorDisplaySummary');")
    || !content.includes("const competitorSummarySourceNotice = requireHomeStatic('competitorSummarySourceNotice');")
    || !content.includes("const competitorSummaryReadinessClass = requireHomeStatic('competitorSummaryReadinessClass');")
    || !content.includes('const holidayOperationStageText = computed(() => holidayOperationStageTextFromStatic(holidayOperationCountdown.value.nearest));')
    || !content.includes('const macroSignalViewCards = computed(() => buildMacroSignalViewCards(macroSignalCards.value, macroSignalMeaningMap));')
    || !content.includes('const homeMarketForecastStatus = computed(() => homeMarketForecastStatusFromStatic(homeMarketForecastItems.value));')
    || !content.includes("const homeTrendChartMetrics = computed(() => homeTrendData.value?.chart?.metrics || {});")
    || !content.includes("const homeSignalMetricText = (signalKey, labels) => homeSignalMetricTextFromStatic(findMacroSignal(signalKey), labels);")
    || content.includes('const parseHolidayDate = (value) => {')
    || content.includes('const formatHolidayDate = (date) => {')
    || content.includes('const homeTrendCardHasData = (card) => {')
    || content.includes('const competitorPlatformTagText = (summary) => {')
    || content.includes('const normalizeMacroSignalMetric = (metric) => ({')
    || content.includes('const macroSignalPrimaryMetrics = (signal) => {')
    || content.includes('const isSignalReady = (signal) =>')
    || content.includes('const formatTrendValue = (card, fallback) => {')
    || content.includes('const homeMetricSeriesValues = (key) => {')
    || content.includes('const homeMetricToneClass = (ready, level = ')
    || content.includes('const findHomeSignalMetric = (signalKey, labels) => {')
    || content.includes('const competitorDisplayRows = (summary) => Array.isArray')
    || content.includes('const competitorSummarySourceNotice = (summary) =>')
    || content.includes('const competitorSummaryReadinessClass = (readiness) => ({')
    || content.includes("return ['暂无可用节假日窗口")
    || content.includes("const buildMacroSignalFallback = (summary = '待同步') => ([")) {
    failures.push('public/index.html must delegate home holiday, trend, signal, and competitor tag display helpers to home-static.js.');
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
    || !content.includes("const createHotelMergeForm = requireSystemStatic('createHotelMergeForm')")
    || !content.includes("const hotelMergeCanExecuteStatic = requireSystemStatic('hotelMergeCanExecute')")
    || !content.includes('JSON.stringify(buildHotelMergeExecutePayload(hotelMergeForm.value))')
    || !content.includes('JSON.stringify(buildHotelOtaCtripConfigSavePayload({')
    || !content.includes('JSON.stringify(buildHotelOtaMeituanConfigSavePayload({')
    || !content.includes('return buildHotelPlatformBindingRowsStatic({')
    || content.includes('const meituanIdentifierMissing = [')) {
    failures.push('public/index.html must use system-static.js helpers for hotel admin forms, hotel merge rules, and save payloads.');
  }
  if (!content.includes("requireAppSystemStatic('getRememberedLoginAccount')")
    || !content.includes("requireAppSystemStatic('loadCachedAuthUser')")
    || !content.includes("requireAppSystemStatic('saveCachedAuthUser')")
    || !content.includes("requireAppSystemStatic('clearCachedAuthUser')")
    || !content.includes("requireAppSystemStatic('buildClientPagination')")
    || !content.includes("requireAppSystemStatic('buildLoginRequestPayload')")
    || !content.includes("requireAppSystemStatic('validateLoginRequestPayload')")
    || !content.includes("requireAppSystemStatic('applyRememberedLoginAccount')")
    || !content.includes('const rememberedLogin = getRememberedLoginAccount(localStorage);')
    || !content.includes('const loginForm = ref(rememberedLogin.form);')
    || !content.includes('const payload = buildLoginRequestPayload(loginForm.value);')
    || !content.includes('const validationError = validateLoginRequestPayload(payload);')
    || !content.includes('applyRememberedLoginAccount({')) {
    failures.push('public/index.html must use system-static.js helpers for login form defaults, cached auth, pagination, payloads, validation, and remembered-account storage.');
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
    || !systemStaticContent.includes('const createHotelMergeForm = () => ({')
    || !systemStaticContent.includes('const hotelMergeCanExecute = ({ preview = null, form = {} } = {}) => {')
    || !systemStaticContent.includes('const buildHotelMergeExecutePayload = (form = {}) => ({')
    || !systemStaticContent.includes('const buildHotelOtaCtripConfigSavePayload = ({ hotelIdText = \'\', ctrip = {}, existing = null, fallbackName = \'\', defaultUrl = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const buildHotelOtaMeituanConfigSavePayload = ({ hotelIdText = \'\', meituan = {}, existing = null, fallbackName = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const buildHotelPlatformBindingRows = ({')) {
    failures.push('public/system-static.js must own hotel admin form defaults, hotel merge rules, and save payload normalization.');
  }
  if (!systemStaticContent.includes('const createLoginForm = ({ username = \'\' } = {}) => ({')
    || !systemStaticContent.includes('const getRememberedLoginAccount = (storage) => {')
    || !systemStaticContent.includes('const loadCachedAuthUser = (storage = browserLocalStorage(), now = Date.now()) => {')
    || !systemStaticContent.includes('const saveCachedAuthUser = (profile, storage = browserLocalStorage(), now = Date.now()) => {')
    || !systemStaticContent.includes('const clearCachedAuthUser = (storage = browserLocalStorage()) => {')
    || !systemStaticContent.includes('const buildClientPagination = (rows, page, pageSize) => {')
    || !systemStaticContent.includes('const buildLoginRequestPayload = (form = {}) => ({')
    || !systemStaticContent.includes('const validateLoginRequestPayload = (payload = {}) => (')
    || !systemStaticContent.includes('const applyRememberedLoginAccount = ({ storage, username = \'\', remember = false } = {}) => {')) {
    failures.push('public/system-static.js must own login form defaults, cached auth, pagination, payload normalization, validation, and remembered-account storage policy.');
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
    || !content.includes('platformProfileStatusResultCache.set(requestKey, {')
    || !content.includes('expiresAt: Date.now() + cacheMs,')
    || !content.includes('data: nextStatus,')
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
    content.indexOf('const dataHealthLightCacheKey = (hotelId) =>'),
    content.indexOf('const triggerAutoFetch = async')
  );
  if (!dataHealthStaticContent.includes('const normalizeDataHealthRefreshRequest = (mode = \'light\', options = {}) => {')
    || !dataHealthStaticContent.includes('const createDataHealthRefreshRequestState = ({ lightCacheTtlMs = 45000 } = {}) => {')
    || !dataHealthStaticContent.includes('const buildDataHealthPanelRefreshJobs = ({')
    || !dataHealthStaticContent.includes('const scheduleDataHealthLightDiagnosticsRefresh = ({')
    || !dataHealthStaticContent.includes('return { status: \'in_flight\', promise: lightCache.promise };')
    || !dataHealthStaticContent.includes('return { status: \'fresh\' };')
    || !dataHealthStaticContent.includes('requireDataHealthPanelLoader(loadCollectionReliability, \'loadCollectionReliability\')(\'full\')')
    || !dataHealthStaticContent.includes('scheduleDataHealthLightDiagnosticsRefresh,')
    || !content.includes("const normalizeDataHealthRefreshRequest = requireDataHealthStatic('normalizeDataHealthRefreshRequest');")
    || !content.includes("const createDataHealthRefreshRequestState = requireDataHealthStatic('createDataHealthRefreshRequestState');")
    || !content.includes("const buildDataHealthPanelRefreshJobs = requireDataHealthStatic('buildDataHealthPanelRefreshJobs');")
    || !content.includes("const scheduleDataHealthLightDiagnosticsRefresh = requireDataHealthStatic('scheduleDataHealthLightDiagnosticsRefresh');")
    || !dataHealthPanelSource.includes('const dataHealthLightCacheKey = (hotelId) => dataHealthRefreshRequestState.lightCacheKey({')
    || !dataHealthPanelSource.includes('const initialCacheKey = dataHealthLightCacheKey(initialHotelId);')
    || !dataHealthPanelSource.includes("if (normalizedMode === 'light' && !force && cacheKey !== initialCacheKey) {")
    || !dataHealthPanelSource.includes('const jobs = buildDataHealthPanelRefreshJobs({')
    || dataHealthPanelSource.includes('loadDailyWorkbenchPatrols:')
    || dataHealthPanelSource.includes('loadPhase3OperationEffectLoop,')
    || dataHealthPanelSource.includes('loadPhase3OperationEffectLoopLedger,')
    || !dataHealthPanelSource.includes('return scheduleDataHealthLightDiagnosticsRefresh({')
    || dataHealthPanelSource.includes('const buildDataHealthPanelJobs = (normalizedMode) =>')
    || dataHealthPanelSource.includes('const jobs = [')
    || dataHealthPanelSource.includes("loadAutoFetchStatus({ detail: normalizedMode === 'full' })")
    || dataHealthPanelSource.includes("return schedulePostFetchRefresh('data-health-light-diagnostics', () => {")
    || content.includes('const DATA_HEALTH_LIGHT_CACHE_TTL_MS = 45000')
    || content.includes('let dataHealthLightCache =')
    || content.includes('const resetDataHealthLightCache = () => {')) {
    failures.push('public/index.html must delegate data-health refresh state, job composition, and deferred light diagnostics to public/data-health-static.js.');
  }
  try {
    const sandbox = { window: {}, console };
    vm.runInNewContext(dataHealthStaticContent, sandbox, { filename: 'public/data-health-static.js' });
    const helpers = sandbox.window.SUXI_DATA_HEALTH_STATIC;
    const request = helpers?.normalizeDataHealthRefreshRequest?.('full', {});
    const lightRequest = helpers?.normalizeDataHealthRefreshRequest?.('light', {});
    const state = helpers?.createDataHealthRefreshRequestState?.({ lightCacheTtlMs: 1000 });
    const cacheKey = state?.lightCacheKey?.({ hotelId: 58, userId: 7, isSuperAdmin: false });
    const run = Promise.resolve('ok');
    state?.rememberLightRequest?.({ cacheKey, promise: run, now: 100 });
    const inFlight = state?.resolveLightRequest?.({ cacheKey, now: 150 });
    state?.settleLightRequest?.({ cacheKey, promise: run, now: 200 });
    const fresh = state?.resolveLightRequest?.({ cacheKey, now: 300 });
    const forced = state?.resolveLightRequest?.({ cacheKey, force: true, now: 300 });
    state?.reset?.();
    const reset = state?.resolveLightRequest?.({ cacheKey, now: 300 });
    const calls = [];
    const loader = (name) => (...args) => {
      calls.push({ name, args });
      return name;
    };
    const fullJobs = helpers?.buildDataHealthPanelRefreshJobs?.({
      normalizedMode: 'full',
      loadAutoFetchStatus: loader('auto'),
      loadDailyWorkbench: loader('workbench'),
      loadDailyWorkbenchPatrols: loader('patrols'),
      loadPhase3OperationEffectLoop: loader('effect'),
      loadPhase3OperationEffectLoopLedger: loader('ledger'),
      loadCollectionReliability: loader('collection'),
      loadDataHealthOperationLogs: loader('logs'),
      loadPublicEndpointSecurity: loader('security'),
      loadReleaseEvidenceStatus: loader('release-evidence'),
      loadHotelDataDashboard: loader('dashboard'),
      loadPlatformCollectionResources: loader('resources'),
    });
    let scheduled = null;
    const scheduledResult = helpers?.scheduleDataHealthLightDiagnosticsRefresh?.({
      schedulePostFetchRefresh: (key, fn, delay) => {
        scheduled = { key, fn, delay };
        return 'scheduled';
      },
      shouldRun: () => false,
      loadDataHealthOperationLogs: loader('light-logs'),
      loadPublicEndpointSecurity: loader('light-security'),
    });
    if (request?.normalizedMode !== 'full'
      || request?.force !== true
      || lightRequest?.normalizedMode !== 'light'
      || lightRequest?.force !== false
      || cacheKey !== '58|7|normal'
      || inFlight?.status !== 'in_flight'
      || inFlight?.promise !== run
      || fresh?.status !== 'fresh'
      || forced?.status !== 'miss'
      || reset?.status !== 'miss'
      || !Array.isArray(fullJobs)
      || fullJobs.length !== 8
      || calls.find((call) => call.name === 'auto')?.args?.[0]?.detail !== true
      || calls.find((call) => call.name === 'collection')?.args?.[0] !== 'full'
      || !calls.some((call) => call.name === 'release-evidence')
      || calls.some((call) => ['patrols', 'effect', 'ledger'].includes(call.name))
      || scheduledResult !== 'scheduled'
      || scheduled?.key !== 'data-health-light-diagnostics'
      || scheduled?.delay !== 360
      || scheduled.fn() !== null) {
      failures.push('public/data-health-static.js data-health refresh helper behavior must preserve full/light mode, cache reuse, in-flight reuse, and light diagnostic scheduling.');
    }
  } catch (error) {
    failures.push(`public/data-health-static.js data-health refresh helper behavior check failed: ${error.message}`);
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
    || !content.includes('<div v-if="dataHealthFullDiagnosticsLoaded && dataHealthEmployeePanelsReady" data-testid="phase1-employee-six-question-summary"')
    || !content.includes('<div v-if="dataHealthFullDiagnosticsLoaded && dataHealthSecondaryPanelsReady" data-testid="data-health-command-center"')
    || content.includes('data-testid="hotel-data-cockpit-pending"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="hotel-data-cockpit"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="data-health-drilldown"')
    || !content.includes('<div v-if="dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded" data-testid="mixed-collection-lifecycle-panel"')
    || !content.includes('data-testid="data-health-full-diagnostics-detail"')
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
    || !dataHealthStaticContent.includes('const formatOnlineHistoryHotelOption = (hotel) => {')
    || !dataHealthStaticContent.includes('formatOnlineHistoryHotelOption,')
    || !dataHealthStaticContent.includes('const formatOnlineHistoryRaw = (raw) => {')
    || !dataHealthStaticContent.includes('formatOnlineHistoryRaw,')
    || !dataHealthStaticContent.includes('const buildHotelDataDashboardRequests = ({ selectedHotelId = \'\', days = 30 } = {}) => {')
    || !dataHealthStaticContent.includes('buildHotelDataDashboardRequests,')
    || !content.includes("const buildOnlineHistoryQueryParams = requireDataHealthStatic('buildOnlineHistoryQueryParams');")
    || !content.includes("const formatOnlineHistoryHotelOption = requireDataHealthStatic('formatOnlineHistoryHotelOption');")
    || !content.includes("const formatOnlineHistoryRaw = requireDataHealthStatic('formatOnlineHistoryRaw');")
    || !content.includes("const buildHotelDataDashboardRequests = requireDataHealthStatic('buildHotelDataDashboardRequests');")
    || !dataHealthStaticVersionMatch
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
    || content.includes('const formatOnlineHistoryRaw = (raw) => {')
    || onlineHistorySource.includes("params.append('hotel_id', filter.hotel_scope);")
    || content.includes('const isDirtyQuestionMarkText = (text) => {')) {
    failures.push('public/index.html must delegate online history and hotel dashboard request construction and avoid reloading hotel filters on post-fetch history refresh.');
  }
  if (!dataHealthStaticContent.includes('const buildCollectionHealthCtripCatalogDetailRows = (catalog = {}) => [')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripCatalogActionRows = (catalog = {}) => {')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripCatalogDetailRows,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripCatalogActionRows,')
    || !content.includes("const buildCollectionHealthCtripCatalogDetailRows = requireDataHealthStatic('buildCollectionHealthCtripCatalogDetailRows');")
    || !content.includes("const buildCollectionHealthCtripCatalogActionRows = requireDataHealthStatic('buildCollectionHealthCtripCatalogActionRows');")
    || !content.includes('const collectionHealthCtripCatalogDetailRows = computed(() => buildCollectionHealthCtripCatalogDetailRows(collectionHealthCtripCatalog.value || {}));')
    || !content.includes('const collectionHealthCtripCatalogActionRows = computed(() => buildCollectionHealthCtripCatalogActionRows(collectionHealthCtripCatalog.value || {}));')
    || content.includes("key: 'default-sections'")
    || content.includes('const actions = Array.isArray(collectionHealthCtripCatalog.value?.capture_gap_next_actions)')
    || content.includes('reasonText: collectionHealthCtripCatalogActionReasonText(action?.reason)')) {
    failures.push('public/index.html must delegate Ctrip catalog detail and action row construction to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const collectionHealthCtripCatalogStatus = (catalog = {}) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripCatalogMessage = (catalog = {}) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripCatalogGateText = (catalog = {}) => {')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripPersistedRows = (rows = []) => (')
    || !dataHealthStaticContent.includes('const collectionHealthCtripIdentityBlocked = (report = {}) => (')
    || !dataHealthStaticContent.includes('const collectionHealthCtripIdentityMessage = (report = {}) => {')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripOverviewAuthState = (rows = []) => {')
    || !dataHealthStaticContent.includes('collectionHealthCtripCatalogStatus,')
    || !dataHealthStaticContent.includes('collectionHealthCtripCatalogMessage,')
    || !dataHealthStaticContent.includes('collectionHealthCtripCatalogGateText,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripPersistedRows,')
    || !dataHealthStaticContent.includes('collectionHealthCtripIdentityBlocked,')
    || !dataHealthStaticContent.includes('collectionHealthCtripIdentityMessage,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripOverviewAuthState,')
    || !content.includes("const collectionHealthCtripCatalogStatusFromStatic = requireDataHealthStatic('collectionHealthCtripCatalogStatus');")
    || !content.includes("const collectionHealthCtripCatalogMessageFromStatic = requireDataHealthStatic('collectionHealthCtripCatalogMessage');")
    || !content.includes("const collectionHealthCtripCatalogGateTextFromStatic = requireDataHealthStatic('collectionHealthCtripCatalogGateText');")
    || !content.includes("const buildCollectionHealthCtripPersistedRows = requireDataHealthStatic('buildCollectionHealthCtripPersistedRows');")
    || !content.includes("const collectionHealthCtripIdentityBlockedFromStatic = requireDataHealthStatic('collectionHealthCtripIdentityBlocked');")
    || !content.includes("const collectionHealthCtripIdentityMessageFromStatic = requireDataHealthStatic('collectionHealthCtripIdentityMessage');")
    || !content.includes("const buildCollectionHealthCtripOverviewAuthState = requireDataHealthStatic('buildCollectionHealthCtripOverviewAuthState');")
    || !content.includes('const collectionHealthCtripCatalogStatus = computed(() => collectionHealthCtripCatalogStatusFromStatic(collectionHealthCtripCatalog.value || {}));')
    || !content.includes('const collectionHealthCtripCatalogMessage = computed(() => collectionHealthCtripCatalogMessageFromStatic(collectionHealthCtripCatalog.value || {}));')
    || !content.includes('const collectionHealthCtripCatalogGateText = computed(() => collectionHealthCtripCatalogGateTextFromStatic(collectionHealthCtripCatalog.value || {}));')
    || !content.includes('const collectionHealthCtripPersistedRows = computed(() => buildCollectionHealthCtripPersistedRows(collectionHealthHistoryReplay.value || []));')
    || !content.includes('const collectionHealthCtripIdentityBlocked = computed(() => collectionHealthCtripIdentityBlockedFromStatic(collectionHealthCtripIdentityFilter.value || {}));')
    || !content.includes('const collectionHealthCtripIdentityMessage = computed(() => collectionHealthCtripIdentityMessageFromStatic(collectionHealthCtripIdentityFilter.value || {}));')
    || !content.includes('const collectionHealthCtripOverviewAuthState = computed(() => buildCollectionHealthCtripOverviewAuthState(collectionHealthCtripAuthorizationRows.value || []));')
    || content.includes("if (!catalog.available) return 'waiting_config';")
    || content.includes('if (!catalog.available) return catalog.message ||')
    || content.includes('if (catalog.is_live_capture_ready) return')
    || content.includes(".filter(row => String(row?.source || '').toLowerCase() === 'ctrip')")
    || content.includes('Number(report.filtered_count || 0) > 0')
    || content.includes("status: 'waiting_config', className: 'text-amber-700' };")) {
    failures.push('public/index.html must delegate Ctrip catalog status, persisted rows, identity blocking, and authorization state to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const collectionHealthLifecycleStageStatus = (stage, context = {}) => {')
    || !dataHealthStaticContent.includes('collectionHealthLifecycleStageStatus,')
    || !dataHealthStaticContent.includes('collectionHealthLifecycleReadyCount,')
    || !dataHealthStaticContent.includes('const buildCollectionHealthAuthorizationRowsReadable = (rows = []) => (')
    || !dataHealthStaticContent.includes('const buildCollectionHealthFailureReasonRows = (items = []) => (')
    || !dataHealthStaticContent.includes('const buildCollectionHealthPendingActionRows = (items = []) => (')
    || !dataHealthStaticContent.includes('const buildCollectionHealthFieldAssetCards = (summary = {}) => [')
    || !dataHealthStaticContent.includes('buildCollectionHealthAuthorizationRowsReadable,')
    || !dataHealthStaticContent.includes('buildCollectionHealthFailureReasonRows,')
    || !dataHealthStaticContent.includes('buildCollectionHealthPendingActionRows,')
    || !dataHealthStaticContent.includes('buildCollectionHealthFieldAssetCards,')
    || !content.includes("const collectionHealthLifecycleStageStatusFromStatic = requireDataHealthStatic('collectionHealthLifecycleStageStatus');")
    || !content.includes("const collectionHealthLifecycleReadyCountFromStatic = requireDataHealthStatic('collectionHealthLifecycleReadyCount');")
    || !content.includes("const buildCollectionHealthAuthorizationRowsReadable = requireDataHealthStatic('buildCollectionHealthAuthorizationRowsReadable');")
    || !content.includes("const buildCollectionHealthFailureReasonRows = requireDataHealthStatic('buildCollectionHealthFailureReasonRows');")
    || !content.includes("const buildCollectionHealthPendingActionRows = requireDataHealthStatic('buildCollectionHealthPendingActionRows');")
    || !content.includes("const buildCollectionHealthFieldAssetCards = requireDataHealthStatic('buildCollectionHealthFieldAssetCards');")
    || !content.includes('const collectionHealthAuthorizationRowsReadable = computed(() => buildCollectionHealthAuthorizationRowsReadable(collectionHealthAuthorizationRows.value));')
    || !content.includes('const collectionHealthFailureReasonRows = computed(() => buildCollectionHealthFailureReasonRows(collectionHealthFailureReasons.value));')
    || !content.includes('const collectionHealthPendingActionRows = computed(() => buildCollectionHealthPendingActionRows(collectionHealthPendingActions.value));')
    || !content.includes('const collectionHealthFieldAssetCards = computed(() => buildCollectionHealthFieldAssetCards(collectionHealthFieldAssetSummary.value));')
    || content.includes('const collectionHealthLifecycleStageStatus = (stage) => {')
    || content.includes("if (key === 'platform_binding') {")
    || content.includes("const platform = String(row?.platform || '').trim();")
    || content.includes("const evidenceNeededRawText = Array.isArray(item?.evidence_needed)")
    || content.includes("{ key: 'stable', label: '稳定字段'")) {
    failures.push('public/index.html must delegate collection lifecycle, authorization, pending-action, and field-asset display rules to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const collectionHealthCtripMetricPreviewValue = (preview, key, options = {}) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripCalculatedValue = (preview, key) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripMetricKeyMatches = (preview, key) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripMissingDiagnosis = (sections, labels, options = {}) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripMetricFromRows = (keys, options = {}) => {')
    || !dataHealthStaticContent.includes('const collectionHealthCtripMetricValue = (sections, labels, options = {}) => {')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripCoreSnapshotGroups = (context = {}) => {')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripOverviewRevenueMetrics = (context = {}) => [')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripOverviewTrafficMetrics = (context = {}) => [')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripOverviewFunnelRows = (context = {}) => {')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripOverviewPanels = (context = {}) => [')
    || !dataHealthStaticContent.includes('const buildCollectionHealthCtripMissingActionRows = ({')
    || !dataHealthStaticContent.includes('collectionHealthCtripMetricPreviewValue,')
    || !dataHealthStaticContent.includes('collectionHealthCtripMetricFromRows,')
    || !dataHealthStaticContent.includes('collectionHealthCtripMetricValue,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripCoreSnapshotGroups,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripOverviewRevenueMetrics,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripOverviewTrafficMetrics,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripOverviewFunnelRows,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripOverviewPanels,')
    || !dataHealthStaticContent.includes('buildCollectionHealthCtripMissingActionRows,')
    || !content.includes("const collectionHealthCtripMetricValueFromStatic = requireDataHealthStatic('collectionHealthCtripMetricValue');")
    || !content.includes("const collectionHealthCtripMetricFromRowsFromStatic = requireDataHealthStatic('collectionHealthCtripMetricFromRows');")
    || !content.includes("const collectionHealthCtripMissingDiagnosisFromStatic = requireDataHealthStatic('collectionHealthCtripMissingDiagnosis');")
    || !content.includes("const buildCollectionHealthCtripCoreSnapshotGroups = requireDataHealthStatic('buildCollectionHealthCtripCoreSnapshotGroups');")
    || !content.includes("const buildCollectionHealthCtripMissingActionRows = requireDataHealthStatic('buildCollectionHealthCtripMissingActionRows');")
    || !content.includes('const collectionHealthCtripRuntimeContext = (options = {}) => ({')
    || !content.includes('const collectionHealthCtripOverviewContext = () => collectionHealthCtripRuntimeContext({')
    || !content.includes('collectionHealthCtripMetricValueFromStatic(sections, labels, collectionHealthCtripRuntimeContext({')
    || !content.includes('const collectionHealthCtripCoreSnapshotGroups = computed(() => buildCollectionHealthCtripCoreSnapshotGroups(collectionHealthCtripOverviewContext()));')
    || content.includes("for (const mapKey of ['metrics', 'raw_metrics', 'rank_metrics'])")
    || content.includes("if (key === 'avg_price' && amount !== null && quantity && quantity > 0)")
    || content.includes("[/订单|预订/, ['book_order_num', 'order_count', 'orderCount', 'bookOrderNum']]")
    || content.includes("const modules = collectionHealthCtripLatestModules.value.filter(module => sectionSet.has(String(module?.section || '').trim()));")
    || content.includes('return collectionHealthCtripPersistedRows.value.filter(row => {')
    || content.includes('const collectionHealthCtripMetricKeyAliases = (key) => {')
    || content.includes('const metricKeyParts = metricKey.split(/[\\+,\\|\\s]+/).map(part => part.trim()).filter(Boolean);')
    || content.includes('const authState = collectionHealthCtripOverviewAuthState.value;')
    || content.includes('const rows = collectionHealthCtripPersistedRows.value;')
    || content.includes('const modules = collectionHealthCtripLatestModules.value;')
    || content.includes('const collectionHealthCtripOverviewMetric = (label, sections, labels, options = {}) => ({')
    || content.includes("const buildGroup = (key, label, sections, metrics) => ({")
    || content.includes("collectionHealthCtripOverviewMetric('实时预订订单'")
    || content.includes("collectionHealthCtripOverviewMetric('实时访客量'")
    || content.includes('const collectionHealthCtripOverviewFunnelMetric = (label, keys, dimensionIncludes = []) => ({')
    || content.includes("collectionHealthCtripOverviewFunnelMetric('列表页曝光量'")
    || content.includes("key: 'competitor',\n                    title: '竞争表现',")
    || content.includes('const allMetrics = [')) {
    failures.push('public/index.html must delegate Ctrip metric lookup, overview metric lists, and missing-diagnosis rules to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const buildPhase1MetricDomainReadiness = ({')
    || !dataHealthStaticContent.includes('buildPhase1MetricDomainReadiness,')
    || content.includes("const buildPhase1MetricDomainReadiness = requireDataHealthStatic('buildPhase1MetricDomainReadiness');")
    || content.includes('} = buildPhase1MetricDomainReadiness({')
    || content.includes('const phase1HasAnyDataType = (types, needles)')) {
    failures.push('public/index.html must delegate Phase1 metric domain readiness to data-health-static.js and not re-inline OTA evidence domain matching.');
  }
  if (!dataHealthStaticContent.includes('const buildPhase1TrafficP0NextText = (row = {}) => {')
    || !dataHealthStaticContent.includes('buildPhase1TrafficP0NextText,')
    || !dataHealthStaticContent.includes('const buildPhase1TrafficLatestSyncTaskText = (row = {}) => {')
    || !dataHealthStaticContent.includes('buildPhase1TrafficLatestSyncTaskText,')
    || !dataHealthStaticContent.includes('traffic_latest_sync_task_message_code_counts')
    || !dataHealthStaticContent.includes('const p0NextText = buildPhase1TrafficP0NextText(row);')
    || content.includes("const buildPhase1TrafficP0NextText = requireDataHealthStatic('buildPhase1TrafficP0NextText');")
    || content.includes('const trafficP0NextText = (row) => {')) {
    failures.push('public/index.html must delegate Phase1 traffic P0 next text to data-health-static.js and not re-inline traffic evidence wording.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeEvidenceStatusText = (value) => ({')
    || !dataHealthStaticContent.includes('phase1EmployeeEvidenceStatusText,')
    || !content.includes("const phase1EmployeeEvidenceStatusText = requireDataHealthStatic('phase1EmployeeEvidenceStatusText');")
    || !dataHealthStaticContent.includes('phase1EmployeeEvidenceStatusText(evidence.diagnosis_status)')
    || content.includes('const evidenceStatusText = (value) => ({')) {
    failures.push('public/index.html must delegate Phase1 employee evidence status labels to data-health-static.js and not re-inline status wording.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeGapCodeText = (code, knownQuestionText = phase1EmployeeKnownQuestionText) => {')
    || !dataHealthStaticContent.includes('phase1EmployeeGapCodeText,')
    || !content.includes("const phase1EmployeeGapCodeText = requireDataHealthStatic('phase1EmployeeGapCodeText');")
    || content.includes('phase1EmployeeGapCodeTextFromStatic')
    || content.includes("source_date_evidence_missing: '目标日来源证据缺失'")) {
    failures.push('public/index.html must delegate Phase1 employee gap code labels directly to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeActionCodeText = (code, helpers = {}) => {')
    || !dataHealthStaticContent.includes('phase1EmployeeActionCodeText,')
    || !dataHealthStaticContent.includes('phase1EmployeeKnownQuestionText')
    || !dataHealthStaticContent.includes('phase1EmployeePlatformText')
    || !content.includes("const phase1EmployeeActionCodeText = requireDataHealthStatic('phase1EmployeeActionCodeText');")
    || content.includes('phase1EmployeeActionCodeTextFromStatic')
    || content.includes("if (raw === 'phase1_confirm_source_date_evidence')")) {
    failures.push('public/index.html must delegate Phase1 employee action code labels directly to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeSourceSnapshotText = (sourceSnapshot) => {')
    || !dataHealthStaticContent.includes('phase1EmployeeSourceSnapshotText,')
    || !content.includes("const phase1EmployeeSourceSnapshotText = requireDataHealthStatic('phase1EmployeeSourceSnapshotText');")
    || content.includes('const phase1EmployeeSourceSnapshotText = (sourceSnapshot) => {')) {
    failures.push('public/index.html must delegate Phase1 source snapshot wording directly to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeQuestionNextActionText = (row) => {')
    || !dataHealthStaticContent.includes('phase1EmployeeQuestionNextActionText,')
    || content.includes("const phase1EmployeeQuestionNextActionText = requireDataHealthStatic('phase1EmployeeQuestionNextActionText');")
    || content.includes('const phase1EmployeeQuestionNextActionText = (row) => {')) {
    failures.push('public/index.html must delegate Phase1 question next-action wording directly to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const phase1EmployeeQuestionEvidenceText = (evidence) => {')
    || !dataHealthStaticContent.includes('const normalizePhase1EmployeeQuestionRow = (row) => ({')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeQuestionRows = ({')
    || !dataHealthStaticContent.includes('phase1EmployeeQuestionEvidenceText,')
    || !dataHealthStaticContent.includes('normalizePhase1EmployeeQuestionRow,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeQuestionRows,')
    || !content.includes("const buildPhase1EmployeeQuestionRows = requireDataHealthStatic('buildPhase1EmployeeQuestionRows');")
    || !content.includes('const phase1EmployeeQuestionRows = computed(() => buildPhase1EmployeeQuestionRows({')
    || content.includes("const phase1EmployeeQuestionEvidenceText = requireDataHealthStatic('phase1EmployeeQuestionEvidenceText');")
    || content.includes("const normalizePhase1EmployeeQuestionRow = requireDataHealthStatic('normalizePhase1EmployeeQuestionRow');")
    || content.includes('const phase1EmployeeQuestionEvidenceText = (evidence) => {')
    || content.includes('const normalizePhase1EmployeeQuestionRow = (row) => ({')
    || content.includes('const latestLog = collectionHealthLatestLog.value || {};')
    || content.includes('const localRows = [')
    || content.includes('const normalizedLocalRows = localRows.map(normalizePhase1EmployeeQuestionRow);')) {
    failures.push('public/index.html must delegate Phase1 employee question row construction, evidence, and normalization to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const buildPhase1EmployeeCollectionSourceRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {')
    || !dataHealthStaticContent.includes('const buildOtaTodayCollectionReminderRows = ({')
    || !dataHealthStaticContent.includes('const buildOtaTodayCollectionReminderSummary = (rows = []) => {')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeFieldTrustRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeMissingFieldRows = ({ backendQuestionSource = {}, collectionHealthQuality = {}, otaDiagnosisDataGaps = [] } = {}) => {')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeMetricDomainRows = ({ backendQuestionSource = {}, collectionReliability = {}, dashboardDataSources = {} } = {}) => {')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeCollectionSourceRows,')
    || !dataHealthStaticContent.includes('buildOtaTodayCollectionReminderRows,')
    || !dataHealthStaticContent.includes('buildOtaTodayCollectionReminderSummary,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeFieldTrustRows,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeMissingFieldRows,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeMetricDomainRows,')
    || !content.includes("const buildPhase1EmployeeCollectionSourceRows = requireDataHealthStatic('buildPhase1EmployeeCollectionSourceRows');")
    || !content.includes("const buildOtaTodayCollectionReminderRows = requireDataHealthStatic('buildOtaTodayCollectionReminderRows');")
    || !content.includes("const buildOtaTodayCollectionReminderSummary = requireDataHealthStatic('buildOtaTodayCollectionReminderSummary');")
    || !content.includes("const buildPhase1EmployeeFieldTrustRows = requireDataHealthStatic('buildPhase1EmployeeFieldTrustRows');")
    || !content.includes("const buildPhase1EmployeeMissingFieldRows = requireDataHealthStatic('buildPhase1EmployeeMissingFieldRows');")
    || !content.includes("const buildPhase1EmployeeMetricDomainRows = requireDataHealthStatic('buildPhase1EmployeeMetricDomainRows');")
    || content.includes('const summaryRows = Array.isArray(backendQuestionSource?.collection_source_summary)')
    || content.includes("const trustedQuestion = backendRows.find(row => String(row?.key || '') === 'trusted_fields') || {};")
    || content.includes('const appendCodes = (codes, source) => {')
    || content.includes('const hasType = (needles) => targetTypes.some(type => needles.some(needle => type.includes(needle)));')) {
    failures.push('public/index.html must delegate Phase1 collection, trust, missing-field, and metric-domain row construction to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const phase1LocalActionMeta = (key) => ({')
    || !dataHealthStaticContent.includes('const buildPhase1LocalRequiredAction = (row, index = 0) => {')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeRequiredActions = ({ backendQuestionSource = {}, rows = [] } = {}) => {')
    || !dataHealthStaticContent.includes('buildPhase1LocalRequiredAction,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeRequiredActions,')
    || !content.includes("const buildPhase1EmployeeRequiredActions = requireDataHealthStatic('buildPhase1EmployeeRequiredActions');")
    || content.includes("const buildPhase1LocalRequiredAction = requireDataHealthStatic('buildPhase1LocalRequiredAction');")
    || content.includes("const normalizePhase1EmployeeRequiredAction = requireDataHealthStatic('normalizePhase1EmployeeRequiredAction');")
    || content.includes('const phase1LocalActionMeta = (key) => ({')
    || content.includes('const buildPhase1LocalRequiredAction = (row, index = 0) => {')
    || content.includes('const actions = Array.isArray(backendQuestionSource?.next_required_actions)')
    || content.includes('.map(buildPhase1LocalRequiredAction)')) {
    failures.push('public/index.html must delegate Phase1 required action metadata and construction to data-health-static.js.');
  }
  if (/ctrip_auto_fetch_mode:\s*['"]profile_browser['"]/.test(autoFetchModePayloadSource)) {
    failures.push('public/index.html must not force platform auto-fetch Ctrip runs through browser Profile by default.');
  }
  if (!dataHealthStaticContent.includes('const buildPhase1AiDiagnosisEvidence = ({ diagnosisResult = {}, gaps = [], actions = [] } = {}) => {')
    || !dataHealthStaticContent.includes('buildPhase1AiDiagnosisEvidence,')
    || !content.includes("const buildPhase1AiDiagnosisEvidence = requireDataHealthStatic('buildPhase1AiDiagnosisEvidence');")
    || !content.includes('return buildPhase1AiDiagnosisEvidence({')
    || content.includes('const phase1DiagnosisActionItemStatus = (item) =>')
    || content.includes('const phase1DiagnosisActionItemBlocked = (item) => {')
    || content.includes('const evidenceSources = Array.isArray(diagnosisResult?.evidence_sources)')) {
    failures.push('public/index.html must delegate Phase1 AI diagnosis evidence calculation to data-health-static.js.');
  }
  if (!dataHealthStaticContent.includes('const buildPhase1EmployeeAiEvidenceSummary = ({ row = {}, evidence = {} } = {}) => {')
    || !dataHealthStaticContent.includes('const buildPhase1EmployeeOperationSummary = ({ row = {}, evidence = {} } = {}) => {')
    || !dataHealthStaticContent.includes("const buildPhase1EmployeeClosureSummary = ({ rows = [], actions = [], backendSummary = {}, protectedBoundary = '' } = {}) => {")
    || !dataHealthStaticContent.includes('buildPhase1EmployeeAiEvidenceSummary,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeOperationSummary,')
    || !dataHealthStaticContent.includes('buildPhase1EmployeeClosureSummary,')
    || !content.includes("const buildPhase1EmployeeAiEvidenceSummary = requireDataHealthStatic('buildPhase1EmployeeAiEvidenceSummary');")
    || !content.includes("const buildPhase1EmployeeOperationSummary = requireDataHealthStatic('buildPhase1EmployeeOperationSummary');")
    || !content.includes("const buildPhase1EmployeeClosureSummary = requireDataHealthStatic('buildPhase1EmployeeClosureSummary');")
    || content.includes('const allBlocking = Array.from(new Set([...blocking, ...rowBlocking]));')
    || content.includes('const completionSignalCount = Number(evidence.completion_signal_count || 0)')
    || content.includes("const provedRows = rows.filter(row => ['proved', 'no_gap_reported'].includes(String(row?.status || '')));")
    || content.includes("const topAction = actions.find(item => String(item?.status || '') !== 'blocked') || actions[0] || null;")) {
    failures.push('public/index.html must delegate Phase1 AI, operation, and closure summary construction to data-health-static.js.');
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
  const controllerConcernDir = path.join(repoRoot, 'app/controller/concern');
  const controllerConcernContents = fs.existsSync(controllerConcernDir)
    ? fs.readdirSync(controllerConcernDir)
      .filter(name => name.endsWith('.php'))
      .sort()
      .map(name => fs.readFileSync(path.join(controllerConcernDir, name), 'utf8'))
      .join('\n')
    : '';
  const controllerContent = [
    fs.existsSync(controllerPath) ? fs.readFileSync(controllerPath, 'utf8') : '',
    controllerConcernContents,
  ].join('\n');
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
  const runtimeSanitizerMatch = controllerContent.match(/private function sanitizeStoredOtaConfigListForRuntime\(array \$list\): array\s+\{([\s\S]*?)\n    private function splitOtaConfigSecrets/);
  const runtimeSanitizerSource = runtimeSanitizerMatch ? runtimeSanitizerMatch[1] : '';
  const secretKeyClassifierMatch = controllerContent.match(/private function isOtaSecretConfigKey\(string \$key\): bool\s+\{([\s\S]*?)\n    private function otaSecretPayloadContainsCookie/);
  const secretKeyClassifierSource = secretKeyClassifierMatch ? secretKeyClassifierMatch[1] : '';
  const storedCtripListMatch = controllerContent.match(/private function getStoredCtripConfigList\(\): array\s+\{([\s\S]*?)\n    private function getStoredMeituanConfigList/);
  const storedCtripListSource = storedCtripListMatch ? storedCtripListMatch[1] : '';
  const storedMeituanListMatch = controllerContent.match(/private function getStoredMeituanConfigList\(\): array\s+\{([\s\S]*?)\n    private function filterOtaConfigListForCurrentUser/);
  const storedMeituanListSource = storedMeituanListMatch ? storedMeituanListMatch[1] : '';
  const ctripLightListMatch = controllerContent.match(/private function getStoredCtripConfigListForLightCache\(\): array\s+\{([\s\S]*?)\n    private function getStoredMeituanConfigListForLightCache/);
  const ctripLightListSource = ctripLightListMatch ? ctripLightListMatch[1] : '';
  const meituanLightListMatch = controllerContent.match(/private function getStoredMeituanConfigListForLightCache\(\): array\s+\{([\s\S]*?)\n    private function isMeituanCommentConfigMetadata/);
  const meituanLightListSource = meituanLightListMatch ? meituanLightListMatch[1] : '';
  const profileSanitizerMatch = controllerContent.match(/private function sanitizeBrowserProfileSourcesForSharedCache\(array \$rows\): array\s+\{([\s\S]*?)\n    private function clearAutoFetchLightConfigListCache/);
  const profileSanitizerSource = profileSanitizerMatch ? profileSanitizerMatch[1] : '';
  const profileListMatch = controllerContent.match(/private function listEnabledBrowserProfileDataSources\(int \$hotelId, string \$platform = ''\): array\s+\{([\s\S]*?)\n    private function listEnabledCtripBrowserProfileDataSources/);
  const profileListSource = profileListMatch ? profileListMatch[1] : '';
  const credentialReadyMatch = controllerContent.match(/private function autoFetchCredentialReady\(array \$config\): bool\s+\{([\s\S]*?)\n    private function autoFetchCtripRequestUrl/);
  const credentialReadySource = credentialReadyMatch ? credentialReadyMatch[1] : '';
  if (!lightHelperSource.includes('resolveCtripFetchConfigForHotelLight')
    || !lightHelperSource.includes('resolveMeituanFetchConfigForHotelLight')
    || lightHelperSource.includes('resolveCtripFetchConfigForHotel($hotelId)')
    || lightHelperSource.includes('resolveMeituanFetchConfigForHotel($hotelId)')
    || !controllerContent.includes('getStoredCtripConfigListForLightCache()')
    || !controllerContent.includes('getStoredMeituanConfigListForLightCache()')
    || controllerContent.includes('getStoredCtripConfigListRaw')
    || controllerContent.includes('getStoredMeituanConfigListRaw')) {
    failures.push('app/controller/OnlineData.php light auto-fetch platform status must use sanitized metadata-only config resolvers.');
  }
  if (runtimeSanitizerMatch === null
    || !runtimeSanitizerSource.includes('foreach ($list as $index => $item)')
    || !runtimeSanitizerSource.includes('splitOtaConfigSecrets($item)')
    || !runtimeSanitizerSource.includes('sanitizeSecretConfig($item)')
    || !runtimeSanitizerSource.includes("$metadata['migration_required'] = true;")
    || !runtimeSanitizerSource.includes("$metadata['migration_reason'] = 'legacy_secret_fields_present';")
    || !runtimeSanitizerSource.includes("$metadata['credential_status'] = 'migration_required';")
    || !runtimeSanitizerSource.includes("$metadata['credential_level'] = 'blocked';")
    || !runtimeSanitizerSource.includes("$metadata['has_cookies'] = false;")
    || !credentialReadySource.includes("(string)($config['credential_status'] ?? '') === 'ready'")
    || !credentialReadySource.includes("($config['has_cookies'] ?? false) === true")
    || !storedCtripListSource.includes('sanitizeStoredOtaConfigListForRuntime($list)')
    || !storedMeituanListSource.includes('sanitizeStoredOtaConfigListForRuntime($list)')
    || !ctripLightListSource.includes('sanitizeStoredOtaConfigListForRuntime($list)')
    || !meituanLightListSource.includes('sanitizeStoredOtaConfigListForRuntime($list)')
    || !ctripLightListSource.includes('writeAutoFetchLightReadCache($cacheKey, $safeList)')
    || !meituanLightListSource.includes('writeAutoFetchLightReadCache($cacheKey, $safeList)')
    || ctripLightListSource.includes('writeAutoFetchLightReadCache($cacheKey, $list)')
    || meituanLightListSource.includes('writeAutoFetchLightReadCache($cacheKey, $list)')
    || !['cookies', 'token', 'auth_data', 'authorization', 'headers', 'encrypted_payload']
      .every(key => secretKeyClassifierSource.includes(`'${key}'`))) {
    failures.push('app/controller/OnlineData.php must sanitize every stored OTA config row, block legacy secret rows for migration, and cache safe metadata only.');
  }
  const profileSelectedFieldMatch = profileListSource.match(/->field\('([^']+)'\)/);
  const profileSelectedFields = profileSelectedFieldMatch
    ? profileSelectedFieldMatch[1].split(',').map(field => field.trim())
    : [];
  const requiredProfileSelectedFields = [
    'id', 'tenant_id', 'name', 'system_hotel_id', 'platform', 'data_type',
    'ingestion_method', 'config_json', 'enabled', 'status',
  ];
  if (profileListMatch === null
    || !requiredProfileSelectedFields.every(field => profileSelectedFields.includes(field))
    || profileSelectedFields.includes('secret_json')
    || !profileListSource.includes("->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])")
    || !profileListSource.includes('sanitizeBrowserProfileSourcesForSharedCache($rows)')
    || !profileListSource.includes('writeAutoFetchLightReadCache($cacheKey, $safeRows)')
    || profileListSource.includes('writeAutoFetchLightReadCache($cacheKey, $rows)')
    || profileSanitizerMatch === null
    || !profileSanitizerSource.includes("unset($row['secret_json'])")
    || !profileSanitizerSource.includes('splitOtaConfigSecrets($config)')
    || !profileSanitizerSource.includes('splitOtaConfigSecrets($row)')
    || !profileSanitizerSource.includes("$safeRow['migration_required'] = true;")
    || !profileSanitizerSource.includes("$safeRow['status'] = 'migration_required';")) {
    failures.push('app/controller/OnlineData.php browser-profile cache must select a safe field whitelist and sanitize config metadata before shared caching.');
  }
  if (!controllerContent.includes('private const AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS = 5;')
    || !controllerContent.includes('private array $autoFetchLightReadCache = [];')
    || !controllerContent.includes('readAutoFetchLightReadCache($cacheKey)')
    || !controllerContent.includes("'_config_list_metadata_v2'")
    || !controllerContent.includes("'online_data_auto_fetch_light_profile_sources_v3_'")
    || !controllerContent.includes('writeAutoFetchLightReadCache($cacheKey, $safeList)')
    || !controllerContent.includes('writeAutoFetchLightReadCache($cacheKey, $safeRows)')) {
    failures.push('app/controller/OnlineData.php light auto-fetch status must preserve its five-second metadata-only config/profile read cache.');
  }
  const systemConfigPath = path.join(repoRoot, 'app/model/SystemConfig.php');
  const systemConfigContent = fs.existsSync(systemConfigPath) ? fs.readFileSync(systemConfigPath, 'utf8') : '';
  const durableKeysMatch = systemConfigContent.match(/private const DURABLE_VALUE_CACHE_KEYS\s*=\s*\[([\s\S]*?)\];/);
  const protectedKeysMatch = systemConfigContent.match(/private const PROTECTED_OTA_KEYS\s*=\s*\[([\s\S]*?)\];/);
  const durableKeysSource = durableKeysMatch ? durableKeysMatch[1] : '';
  const protectedKeysSource = protectedKeysMatch ? protectedKeysMatch[1] : '';
  if (durableKeysMatch === null
    || protectedKeysMatch === null
    || durableKeysSource.includes('ctrip_config_list')
    || durableKeysSource.includes('meituan_config_list')
    || protectedKeysSource.includes('ctrip_config_list') === false
    || protectedKeysSource.includes('meituan_config_list') === false
    || !systemConfigContent.includes('public static function isProtectedOtaKey(string $key): bool')) {
    failures.push('app/model/SystemConfig.php must exclude protected OTA config lists from the generic cross-request value cache.');
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
  if (/<script\s+src=["']operation-static\.js["']/.test(content)) {
    failures.push('public/index.html must lazy-load operation-static.js; it is only required by operation/opening/lifecycle pages.');
  }
  if (!/const\s+operationStaticScript\s*=\s*["']operation-static\.js["']/.test(content) || !/const\s+loadOperationStatic\s*=\s*\(\)\s*=>/.test(content)) {
    failures.push('public/index.html must keep an explicit lazy loader for operation-static.js.');
  }
  if (!content.includes('await ensureOperationStaticReady();')
    || !/newPage === ['"]ops-source['"]/.test(content)
    || !/newPage === ['"]ops-analysis['"] \|\| newPage === ['"]ops-plan['"]/.test(content)
    || !/newPage === ['"]ops-insight['"]/.test(content)
    || !/newPage === ['"]ops-track['"]/.test(content)) {
    failures.push('public/index.html must load operation static data before retained operation pages work.');
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
    || !content.includes('const shouldDeferFormOperationSupportLoad = () => isCompassDataPage() || isCoreOtaPageVisible();')
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
  const templateBoundaryContent = `<div id="app">${appTemplateContent}</div>`;

  for (const marker of vueBoundaryMarkers) {
    const offset = templateBoundaryContent.indexOf(marker.marker);
    if (offset < 0) {
      failures.push(`resources/frontend/app-template.html missing Vue boundary marker: ${marker.name}.`);
      continue;
    }
    if (!hasOpenVueRoot(openTagStackBefore(templateBoundaryContent, offset))) {
      failures.push(
        `resources/frontend/app-template.html Vue boundary broken before ${marker.name} at line ${lineNumberForOffset(templateBoundaryContent, offset)}. ` +
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
  if (!routerSource.includes('const SUXI_STATIC_GZIP_LEVEL = 6;')
    || !routerSource.includes("'-gzip-l' . SUXI_STATIC_GZIP_LEVEL . '.gz'")
    || !routerSource.includes("gzencode($responseContent ?? '', SUXI_STATIC_GZIP_LEVEL)")) {
    failures.push('public/router.php must cache level-6 gzip output under a level-specific identity.');
  }
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Public entry guard passed.');
