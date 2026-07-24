import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import {
  extractAuthenticatedAssetEntries,
  extractAuthenticatedAssetReferences,
  stripFrontendAssetQuery,
} from '../../scripts/lib/frontend_authenticated_assets.mjs';

const index = fs.readFileSync('public/index.html', 'utf8');
const bootstrap = fs.readFileSync('public/app-bootstrap.js', 'utf8');
const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const style = fs.readFileSync('public/style.css', 'utf8');

test('public login shell defers the authenticated application asset chain', () => {
  const references = extractAuthenticatedAssetReferences(index);
  const entries = extractAuthenticatedAssetEntries(index);
  const assets = references.map(stripFrontendAssetQuery);
  const scriptAssets = entries
    .filter((entry) => entry.type === 'script')
    .map((entry) => stripFrontendAssetQuery(entry.src));
  const styleAssets = entries
    .filter((entry) => entry.type === 'style')
    .map((entry) => stripFrontendAssetQuery(entry.src));
  assert.deepEqual(styleAssets, ['tailwind.min.css', 'style.css', 'ai-custom.css']);
  assert.match(index, /<link rel="stylesheet" href="login-critical\.css\?v=[^"]+"/);
  assert.doesNotMatch(index, /<link[^>]+href="(?:tailwind\.min|style|ai-custom)\.css/);
  assert.equal(scriptAssets[0], 'vue.runtime.global.prod.js');
  assert.equal(scriptAssets.at(-3), 'app-startup-render.min.js');
  assert.equal(scriptAssets.at(-2), 'app-render.min.js');
  assert.equal(scriptAssets.at(-1), 'app-main.min.js');
  assert.equal(entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'app-render.min.js')?.phase, 'after-first-paint');
  for (const deferredAsset of [
    'ctrip-search-opportunity-static.js',
    'user-admin-static.js',
  ]) {
    assert.equal(
      entries.find((entry) => stripFrontendAssetQuery(entry.src) === deferredAsset)?.phase,
      'after-first-paint',
      `${deferredAsset} must stay off the authenticated first paint`,
    );
  }
  assert(!assets.includes('ota-browser-assist-static.js'), 'OTA browser assist must load only after its copy action');
  assert(assets.includes('ctrip-static.js'));
  assert(assets.includes('meituan-static.js'));
  assert(assets.includes('data-health-static.js'));
  assert.match(index, /<script defer src="app-bootstrap\.js\?v=[^"]+"[^>]*><\/script>/);
  const versionHash = index.match(/app-bootstrap\.js\?v=[^"']*-h([a-f0-9]{10})/)?.[1];
  const contentHash = crypto.createHash('sha256').update(bootstrap).digest('hex').slice(0, 10);
  assert.equal(versionHash, contentHash, 'public login bootstrap URL must follow its current content hash');
  assert.doesNotMatch(index, /<script defer src="(?:vue\.runtime|ctrip-static|meituan-static|data-health-static|app-render|min\.js|app-main)/);
});

test('public login locale switch persists the selected locale before authenticated assets load', () => {
  assert.match(bootstrap, /data-testid="public-login-locale-select"/);
  assert.match(bootstrap, /const PUBLIC_LOCALES = Object\.freeze\(\['zh-CN', 'en-US'\]\)/);
  const initialLocaleStart = bootstrap.indexOf('const getInitialPublicLocale = () => {');
  const initialLocaleEnd = bootstrap.indexOf('\n    const applyPublicLocale', initialLocaleStart);
  const initialLocaleBlock = bootstrap.slice(initialLocaleStart, initialLocaleEnd);
  const langParam = initialLocaleBlock.indexOf("params.get('lang')");
  const cachedLocale = initialLocaleBlock.indexOf('localStorage.getItem(PUBLIC_LOCALE_KEY)');
  assert(initialLocaleStart >= 0 && initialLocaleEnd > initialLocaleStart);
  assert(langParam >= 0 && cachedLocale > langParam, 'URL locale must take priority over cached locale');
  assert.match(bootstrap, /localeSelect\.addEventListener\('change',[\s\S]*?applyPublicLocale\(event\.target\.value\)/);
  assert.match(bootstrap, /syncPublicLocaleUrl\(normalized\)/);
  assert.match(bootstrap, /url\.searchParams\.set\('lang', normalizePublicLocale\(value\)\)/);
  assert.match(bootstrap, /document\.documentElement\.lang = normalized/);
  assert.match(bootstrap, /localStorage\.setItem\(PUBLIC_LOCALE_KEY, normalized\)/);
});

test('login bootstrap delegates remembered passwords to the browser credential store', () => {
  assert.match(bootstrap, /fetchJson\('\/api\/auth\/login'/);
  assert.match(bootstrap, /sessionStorage\.setItem\(AUTH_TOKEN_KEY/);
  assert.match(bootstrap, /localStorage\.removeItem\(LEGACY_PASSWORD_KEY\)/);
  assert.match(bootstrap, /remembered_username/);
  assert.match(bootstrap, /suxios_browser_password_save_v1/);
  assert.match(bootstrap, /new PasswordCredentialCtor\(\{/);
  assert.match(bootstrap, /LOGIN_PASSWORD_SAVE_TIMEOUT_MS = 1500/);
  assert.match(bootstrap, /credentialStore\.store\(credential\)/);
  assert.match(bootstrap, /status: 'timeout'/);
  assert.match(bootstrap, /<span>记住密码<\/span>/);
  assert.doesNotMatch(bootstrap, /localStorage\.setItem\([^,\n]+,\s*(?:payload\.)?password/i);
  assert.doesNotMatch(bootstrap, /sessionStorage\.setItem\([^,\n]+,\s*(?:payload\.)?password/i);
  assert.match(bootstrap, /await loadAuthenticatedApp\(\)/);
  const submitBindingOffset = bootstrap.indexOf("form.addEventListener('submit'");
  const readyOffset = bootstrap.indexOf("form.dataset.suxiLoginReady = '1'");
  assert(submitBindingOffset >= 0 && readyOffset > submitBindingOffset, 'login-ready marker must follow submit binding');
  const loadingGuardOffset = bootstrap.indexOf('if (submit.dataset.suxiLoading !== loadingState) {');
  const loadingMarkupOffset = bootstrap.indexOf('submit.innerHTML = loading', loadingGuardOffset);
  const loadingGuardEnd = bootstrap.indexOf('\n            }', loadingMarkupOffset);
  assert(loadingGuardOffset >= 0 && loadingMarkupOffset > loadingGuardOffset && loadingMarkupOffset < loadingGuardEnd);

  const authSuccessOffset = bootstrap.indexOf("markLoginAuthSuccess({ source: 'public-login' })");
  const passwordSaveOffset = bootstrap.indexOf('const passwordSavePromise = saveLoginPasswordWithBrowser', authSuccessOffset);
  const appLoadOffset = bootstrap.indexOf('await loadAuthenticatedApp()', passwordSaveOffset);
  assert(authSuccessOffset >= 0 && passwordSaveOffset > authSuccessOffset && appLoadOffset > passwordSaveOffset);
  assert.doesNotMatch(bootstrap.slice(passwordSaveOffset, appLoadOffset), /await passwordSavePromise/);
});

test('login handoff exposes auth-success to interactive timing after a usable app surface and paint', () => {
  assert.match(bootstrap, /window\.SUXI_LOGIN_HANDOFF_METRICS = snapshot/);
  assert.match(bootstrap, /auth_to_interactive_ms:/);
  assert.match(bootstrap, /suxi-login-auth-to-interactive/);
  assert.match(bootstrap, /suxi:login-handoff-metric/);
  assert.match(bootstrap, /const waitForAuthenticatedInteractiveReady = \(\) => new Promise/);
  assert.match(
    bootstrap,
    /const markLoginInteractiveAfterPaint = async \(metadata = \{\}\) => \{\s*await waitForAuthenticatedInteractiveReady\(\);\s*await waitForFirstAuthenticatedPaint\(\);/,
  );
  assert.match(bootstrap, /await markLoginInteractiveAfterPaint\(\{ source: 'public-login' \}\)/);
  assert.match(bootstrap, /window\.SUXI_MARK_LOGIN_AUTH_SUCCESS = markLoginAuthSuccess/);
  assert.match(bootstrap, /window\.SUXI_MARK_LOGIN_INTERACTIVE_AFTER_PAINT = markLoginInteractiveAfterPaint/);
  assert.match(appMain, /document\.querySelector\('\[data-testid="deferred-page-loading"\]'\)/);
  assert.match(appMain, /dataset\.suxiAuthenticatedInteractiveReady = '1'/);
  assert.match(appMain, /suxi:authenticated-interactive-ready/);
});

test('authenticated startup paints the home render before loading the full render', () => {
  assert.match(bootstrap, /assetBaseName\(asset\.src\) === 'vue\.runtime\.global\.prod\.js'/);
  assert.match(bootstrap, /assetBaseName\(asset\.src\) === 'app-main\.min\.js'/);
  assert.match(bootstrap, /asset\.phase === ASSET_PHASE_STARTUP/);
  assert.match(bootstrap, /asset\.phase === ASSET_PHASE_AFTER_FIRST_PAINT/);
  assert.match(bootstrap, /await Promise\.all\(\[\s*loadScript\(runtime\),/);
  assert.match(bootstrap, /await Promise\.all\(prerequisites\.map\(\(src\) => loadScript\(src\)\)\);/);
  assert.match(bootstrap, /await loadScript\(entry\);/);
  assert.match(bootstrap, /await waitForFirstAuthenticatedPaint\(\);/);
  assert.match(bootstrap, /suxi:full-render-ready/);
  assert.match(bootstrap, /const loadDeferredAuthenticatedAssetManifest = \(\) => \{/);
  assert.match(bootstrap, /window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS = loadDeferredAuthenticatedAssetManifest;/);
  assert.doesNotMatch(bootstrap, /void loadDeferredAuthenticatedAssets\(deferredAssets\);/);
  assert.match(appMain, /requestSuxiFullRenderForPage = \(page\) => \{[\s\S]*window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS\(\)/);
  assert.doesNotMatch(bootstrap, /for \(const src of assets\)/);
});

test('login intent preloads only the authenticated entry before the sequential startup barrier', () => {
  assert.match(bootstrap, /const authenticatedStartupAssets = \(\) => \([\s\S]*asset\.phase === ASSET_PHASE_STARTUP/);
  assert.match(bootstrap, /const preloadAuthenticatedEntry = \(\) => \{/);
  assert.doesNotMatch(bootstrap, /preloadAuthenticatedStartupDependencies/);
  assert.match(bootstrap, /link\.rel = 'preload'/);
  assert.match(bootstrap, /link\.as = asset\.type === ASSET_TYPE_STYLE \? 'style' : 'script'/);
  assert.match(bootstrap, /link\.dataset\.suxiAuthenticatedStartupPreload = assetName/);
  assert.match(bootstrap, /preloadAuthenticatedAsset\(entry, 'high'\)/);
  assert.match(bootstrap, /authenticatedStartupPreloadLinks\.delete\(assetName\)/);
  assert.match(bootstrap, /form\.addEventListener\('focusin', preloadAuthenticatedEntry\)/);
  assert.match(bootstrap, /const handleInput = \(\) => \{[\s\S]*?preloadAuthenticatedEntry\(\)/);

  const submitStart = bootstrap.indexOf("form.addEventListener('submit'");
  const entryPreloadOffset = bootstrap.indexOf('preloadAuthenticatedEntry();', submitStart);
  const loginRequestOffset = bootstrap.indexOf("fetchJson('/api/auth/login'", submitStart);
  assert(submitStart >= 0 && entryPreloadOffset > submitStart && loginRequestOffset > entryPreloadOffset);
});

test('authenticated login lands on the one-page operating loop through one entry helper', () => {
  const helperStart = appMain.indexOf('const activateCoreOperationsAfterLogin = () => {');
  const helperEnd = appMain.indexOf('\n            const isVisibleOnlineDataTab', helperStart);
  const helper = appMain.slice(helperStart, helperEnd);
  assert(helperStart >= 0 && helperEnd > helperStart, 'core-operations activation helper must exist');
  assert.match(helper, /return openOnlineDataEntryTab\('data-health'\);/);

  const loginStart = appMain.indexOf('const handleLogin = async () => {');
  const loginEnd = appMain.indexOf('\n            const loadLoginSupportContact', loginStart);
  const loginFlow = appMain.slice(loginStart, loginEnd);
  assert.match(loginFlow, /activateCoreOperationsAfterLogin\(\)/);
  assert.match(loginFlow, /applyDefaultReportHotel\(\{ suppressDashboardRefresh: true \}\)/);
  assert.doesNotMatch(loginFlow, /scheduleInitialCompassLoad|scheduleDualOtaWorkbenchAutoFetch/);

  const mountedStart = appMain.indexOf('onMounted(() => {');
  const mountedEnd = appMain.indexOf('\n            onUnmounted', mountedStart);
  const mountedFlow = appMain.slice(mountedStart, mountedEnd);
  assert.match(mountedFlow, /if \(token\.value\) \{\s*requestSuxiFullRenderForPage\(currentPage\.value\);/, 'remembered sessions must promote a deferred default page even when currentPage does not change');
  assert.match(mountedFlow, /if \(isCompassDataPage\(\)\) \{\s*activateCoreOperationsAfterLogin\(\);\s*\}/);
  assert.match(mountedFlow, /applyDefaultReportHotel\(\{ suppressDashboardRefresh: true \}\)/);
  assert.match(mountedFlow, /request\('\/auth\/info'\)[\s\S]*handleAuthInfoBootstrapUnavailable\(bootstrapSession\)/, 'transient auth-info failures must retain the current session for retry');
  assert.doesNotMatch(mountedFlow, /request\('\/auth\/info'\)[\s\S]*clearAuthSession\(\)/, 'auth-info bootstrap must not clear a session after network, 5xx, or malformed-response failures');
  assert.match(appMain, /if \(response\.status === 401 \|\| data\.code === 401\)[\s\S]*clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/, 'explicit 401 responses must still clear the matching invalid session');
  assert.match(appMain, /isTerminalAuthFailureResponse\(response, data\)[\s\S]*clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/, 'explicit terminal auth responses, including disabled users, must clear the matching cached session');
  assert.match(appMain, /authFailureReason === 'user_disabled'/, 'disabled-user responses must be distinguished from ordinary permission denials');
  assert.doesNotMatch(mountedFlow, /scheduleInitialCompassLoad|scheduleDualOtaWorkbenchAutoFetch/);
  assert.doesNotMatch(appMain, /const scheduleInitialCompassLoad =/);

  const pageWatcherStart = appMain.indexOf('watch(currentPage, (newPage) => {');
  const pageWatcherEnd = appMain.indexOf('\n            watch(onlineDataTab', pageWatcherStart);
  const pageWatcher = appMain.slice(pageWatcherStart, pageWatcherEnd);
  assert.match(pageWatcher, /loadCompassData\(\{ skipOtaBackground: true \}\)/);
  assert.match(appMain, /if \(options\.skipOtaBackground !== true\) \{[\s\S]*?loadLatestCtripData[\s\S]*?loadCompetitorSummary/);
});

test('deferred and action-gated helpers resolve from their bundles only when invoked', () => {
  assert.match(appMain, /const requireUserAdminStatic = \(key\) => \{/);
  assert.match(appMain, /const requireCtripSearchOpportunityStatic = \(key\) => \(\.\.\.args\) => \{/);
  assert.match(appMain, /const loadOtaBrowserAssistStatic = \(\) => \{/);
  assert.match(appMain, /script\.dataset\.suxiActionAsset = assetName/);
  assert.match(appMain, /otaBrowserAssistStaticLoadPromise = null/);
  assert.match(appMain, /const otaBrowserAssistStatic = await loadOtaBrowserAssistStatic\(\)/);
  assert.doesNotMatch(appMain, /const userAdminStatic = window\.SUXI_USER_ADMIN_STATIC;\s+if \(/);
  assert.doesNotMatch(appMain, /const ctripSearchOpportunityStatic = window\.SUXI_CTRIP_SEARCH_OPPORTUNITY_STATIC;\s+if \(/);
});

test('public login feedback, support dialog, and hidden states remain accessible', () => {
  assert.match(bootstrap, /role="alert" aria-live="assertive" aria-atomic="true" hidden/);
  assert.match(bootstrap, /aria-describedby="public-login-error public-login-caps-lock"/);
  assert.match(bootstrap, /aria-labelledby="public-login-support-title" aria-describedby="public-login-support-description"/);
  assert.match(bootstrap, /登录请求超时，请检查网络后重试/);
  assert.match(bootstrap, /开通账号或处理登录问题/);
  assert.doesNotMatch(bootstrap, /申请账号或处理登录问题/);
  assert.match(
    style,
    /\.login-caps-lock\[hidden\],[\s\S]*\.login-error\[hidden\],[\s\S]*\.login-support-backdrop\[hidden\][\s\S]*display:\s*none\s*!important/,
  );
});

test('public login reconciles browser autofill before deciding the submit state', () => {
  assert.match(bootstrap, /LOGIN_AUTOFILL_SYNC_DELAYS = Object\.freeze\(\[0, 100, 300, 800, 1600, 3000, 5000, 8000, 12000\]\)/);
  assert.match(bootstrap, /const scheduleLoginAutofillSync = \(\) =>/);
  assert.match(bootstrap, /input\?\.matches\?\.\(':-webkit-autofill'\)/);
  assert.match(bootstrap, /!password\.value && !hasBrowserAutofill\(password\)/);
  assert.match(bootstrap, /请先点击密码框确认浏览器保存的密码，再登录/);
  assert.match(bootstrap, /window\.addEventListener\('pageshow', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /window\.addEventListener\('focus', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /form\.addEventListener\('focusin', scheduleLoginAutofillSync\)/);
  assert.match(bootstrap, /password\.addEventListener\('change', handleInput\)/);
});

test('public login keeps the same-origin transport warm without delaying submit', () => {
  assert.match(bootstrap, /LOGIN_CONNECTION_WARMUP_TIMEOUT_MS = 12000/);
  assert.match(bootstrap, /LOGIN_CONNECTION_WARMUP_MIN_GAP_MS = 15000/);
  assert.match(bootstrap, /fetchImpl\('\/api\/health', \{/);
  assert.match(bootstrap, /credentials: 'omit'/);
  assert.match(bootstrap, /cache: 'no-store'/);
  assert.match(bootstrap, /priority: 'low'/);
  assert.match(bootstrap, /\.catch\(\(\) => false\)/);
  assert.match(bootstrap, /form\.addEventListener\('focusin', warmLoginConnection\)/);
  assert.match(bootstrap, /window\.addEventListener\('focus', warmLoginConnection\)/);
  assert.match(bootstrap, /loginConnectionWarmup\.stop\(\);[\s\S]*?await loadAuthenticatedApp\(\)/);
  assert.doesNotMatch(bootstrap, /LOGIN_CONNECTION_WARMUP_INTERVAL_MS|setIntervalImpl|clearIntervalImpl/);

  const submitStart = bootstrap.indexOf("form.addEventListener('submit'");
  const loginRequest = bootstrap.indexOf("fetchJson('/api/auth/login'", submitStart);
  const warmupAwait = bootstrap.indexOf('await warmLoginConnection', submitStart);
  assert(submitStart >= 0 && loginRequest > submitStart, 'login request must stay inside the submit handler');
  assert(warmupAwait < 0 || warmupAwait > loginRequest, 'connection warmup must never delay the login request');
});

test('dual OTA loss-chain grid follows the actual node count', () => {
  assert.match(
    style,
    /grid-template-columns:\s*repeat\(var\(--dual-ota-loss-columns,\s*5\),\s*minmax\(0,\s*1fr\)\)/,
  );
});
