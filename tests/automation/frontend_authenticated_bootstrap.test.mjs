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
const bootstrapRuntime = fs.readFileSync('public/app-bootstrap.min.js', 'utf8');
const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const appMainComponents = fs.readFileSync('public/components/system/app-main-components.js', 'utf8');
const appMainComponentsLoader = fs.readFileSync('public/components/system/app-main-components-loader.js', 'utf8');
const operatingIntelligenceComponents = fs.readFileSync('public/components/system/operating-intelligence-components.js', 'utf8');
const operatingIntelligenceLoader = fs.readFileSync('public/components/system/operating-intelligence-loader.js', 'utf8');
const systemStatic = fs.readFileSync('public/system-static.js', 'utf8');
const style = fs.readFileSync('public/style.css', 'utf8');

function createAuthenticatedAssetLoaderHarness(timeoutMs = 20, manifestTimeoutMs = 80) {
  const loaderStart = bootstrap.indexOf('const resolveAssetUrl = (src) => {');
  const loaderEnd = bootstrap.indexOf('\n\n    const waitForFirstAuthenticatedPaint', loaderStart);
  const manifestStart = bootstrap.indexOf('const loadDeferredAuthenticatedAssets = (assets = []) => {');
  const manifestEnd = bootstrap.indexOf('\n\n    const loadDeferredAuthenticatedAssetManifest', manifestStart);
  assert(loaderStart >= 0 && loaderEnd > loaderStart, 'authenticated asset loader source must be extractable');
  assert(manifestStart >= 0 && manifestEnd > manifestStart, 'deferred manifest source must be extractable');

  const scripts = [];
  const styles = [];
  const events = [];
  const removeNode = (collection, node) => {
    const index = collection.indexOf(node);
    if (index >= 0) collection.splice(index, 1);
  };
  const createNode = (tagName) => {
    const listeners = new Map();
    const collection = tagName === 'script' ? scripts : styles;
    return {
      tagName,
      dataset: {},
      sheet: null,
      src: '',
      href: '',
      rel: '',
      async: true,
      addEventListener(type, listener) {
        const bucket = listeners.get(type) || new Set();
        bucket.add(listener);
        listeners.set(type, bucket);
      },
      removeEventListener(type, listener) {
        listeners.get(type)?.delete(listener);
      },
      getAttribute(name) {
        return Object.hasOwn(this, name) ? this[name] : null;
      },
      emit(type) {
        for (const listener of [...(listeners.get(type) || [])]) listener({ type, target: this });
      },
      remove() {
        removeNode(collection, this);
      },
    };
  };
  const appendNode = (collection, node) => {
    if (!collection.includes(node)) collection.push(node);
    return node;
  };
  const documentMock = {
    baseURI: 'http://127.0.0.1:8080/',
    documentElement: { dataset: {} },
    scripts,
    body: { appendChild: node => appendNode(scripts, node) },
    head: { appendChild: node => appendNode(styles, node) },
    createElement: createNode,
    querySelectorAll: selector => (selector === 'link[rel="stylesheet"]' ? styles : []),
  };
  const windowMock = {
    setTimeout: (callback, delay) => setTimeout(callback, delay),
    clearTimeout: timerId => clearTimeout(timerId),
    dispatchEvent: event => {
      events.push(event);
      return true;
    },
  };
  class CustomEventMock {
    constructor(type, options = {}) {
      this.type = type;
      this.detail = options.detail;
    }
  }
  const loaderFactory = Function(
    'document',
    'window',
    'CustomEvent',
    'assetBaseName',
    'ASSET_TYPE_STYLE',
    'ASSET_TYPE_SCRIPT',
    'AUTHENTICATED_ASSET_LOAD_TIMEOUT_MS',
    'DEFERRED_AUTHENTICATED_ASSET_LOAD_TIMEOUT_MS',
    'DEFERRED_AUTHENTICATED_ASSET_RETRY_LIMIT',
    'DEFERRED_AUTHENTICATED_MANIFEST_TIMEOUT_MS',
    `"use strict";
      const authenticatedAssetLoadPromises = new Map();
      let deferredAuthenticatedAssetsPromise = null;
      const waitForFirstAuthenticatedPaint = () => Promise.resolve();
      const preloadAuthenticatedAsset = () => false;
      ${bootstrap.slice(loaderStart, loaderEnd)}
      ${bootstrap.slice(manifestStart, manifestEnd)}
      return {
        loadScript,
        loadStylesheet,
        loadDeferredAuthenticatedAssetWithRetry,
        loadDeferredAuthenticatedAssets,
      };`,
  );
  return {
    ...loaderFactory(
      documentMock,
      windowMock,
      CustomEventMock,
      src => String(src || '').split(/[?#]/, 1)[0],
      'style',
      'script',
      timeoutMs,
      timeoutMs,
      1,
      manifestTimeoutMs,
    ),
    scripts,
    styles,
    events,
    document: documentMock,
  };
}

test('Meituan helper bindings resolve the deferred bundle at call time', () => {
  const bindingStart = appMain.indexOf('const currentMeituanStatic =');
  const bindingEnd = appMain.indexOf('const OTA_BROWSER_ASSIST_STATIC_ASSET', bindingStart);
  const bindingSource = appMain.slice(bindingStart, bindingEnd);
  assert.match(bindingSource, /const currentMeituanStatic = \(\) => \(\s*window\.SUXI_MEITUAN_STATIC/);
  assert.match(
    bindingSource,
    /const requireMeituanStatic = \(key\) => \{\s*const fallback = resolveMeituanStaticFallback\(key\);\s*return \(\.\.\.args\) => \{\s*const owner = currentMeituanStatic\(\);/,
  );
  assert.match(bindingSource, /if \(meituanDeferredRuntimePending\(\)\) return fallback\(\.\.\.args\);/);
  assert.doesNotMatch(bindingSource, /const meituanStatic = window\.SUXI_MEITUAN_STATIC/);
});

test('authenticated asset loads share in-flight work and recover after error or timeout', async () => {
  const loader = createAuthenticatedAssetLoaderHarness(10);

  const firstStyleLoad = loader.loadStylesheet('style.min.css?v=retry');
  const failedStyle = loader.styles[0];
  failedStyle.emit('error');
  await assert.rejects(firstStyleLoad, /style\.min\.css 加载失败/);
  assert.equal(failedStyle.dataset.suxiAssetFailed, '1');
  assert.equal(loader.styles.length, 0, 'a failed stylesheet must leave no terminal DOM node');

  const retryStyleLoad = loader.loadStylesheet('style.min.css?v=retry');
  const retriedStyle = loader.styles[0];
  assert.notEqual(retriedStyle, failedStyle, 'a retry must create a fresh stylesheet node');
  retriedStyle.emit('load');
  await retryStyleLoad;
  assert.equal(retriedStyle.dataset.suxiAssetLoaded, '1');

  const firstSharedLoad = loader.loadScript('shared.js?v=1');
  const secondSharedLoad = loader.loadScript('shared.js?v=1');
  assert.equal(firstSharedLoad, secondSharedLoad, 'concurrent callers must share one in-flight asset promise');
  assert.equal(loader.scripts.length, 1);
  loader.scripts[0].emit('load');
  await Promise.all([firstSharedLoad, secondSharedLoad]);

  const deferredRetry = loader.loadDeferredAuthenticatedAssetWithRetry({
    type: 'script',
    src: 'deferred-retry.js?v=1',
  });
  const firstDeferredScript = loader.scripts.find(node => node.src.includes('deferred-retry.js'));
  assert.equal(firstDeferredScript.async, true, 'JS-sequential deferred scripts must not join the native ordered queue');
  firstDeferredScript.emit('error');
  await new Promise(resolve => setImmediate(resolve));
  const retriedDeferredScript = loader.scripts.find(node => node.src.includes('deferred-retry.js'));
  assert.notEqual(retriedDeferredScript, firstDeferredScript, 'a deferred asset gets one fresh bounded retry');
  assert.equal(retriedDeferredScript.async, true);
  assert.equal(new URL(retriedDeferredScript.src).searchParams.get('suxi_retry'), '1');
  assert.equal(
    retriedDeferredScript.dataset.suxiCanonicalSrc,
    'http://127.0.0.1:8080/deferred-retry.js?v=1',
    'the retry transport must retain the original versioned resource identity',
  );
  retriedDeferredScript.emit('load');
  await deferredRetry;

  const stalledLoad = loader.loadScript('stalled.js?v=1');
  const stalledScript = loader.scripts.find(node => node.src.startsWith('stalled.js'));
  await assert.rejects(stalledLoad, /stalled\.js 加载超时/);
  assert.equal(stalledScript.dataset.suxiAssetFailed, undefined);
  assert(loader.scripts.includes(stalledScript), 'a timed-out script must remain canonical while its transport is pending');

  const retryStalledLoad = loader.loadScript('stalled.js?v=1');
  const retriedScript = loader.scripts.find(node => node.src.startsWith('stalled.js'));
  assert.equal(retriedScript, stalledScript, 'a retry after timeout must observe the original transport');
  assert.equal(loader.scripts.filter(node => node.src.includes('stalled.js')).length, 1);
  stalledScript.emit('load');
  await retryStalledLoad;
  assert.equal(stalledScript.dataset.suxiAssetLoaded, '1');
});

test('a deferred timeout never creates a second script transport or duplicate execution path', async () => {
  const loader = createAuthenticatedAssetLoaderHarness(10, 80);
  const asset = { type: 'script', src: 'slow-canonical.js?v=1' };

  await assert.rejects(
    loader.loadDeferredAuthenticatedAssetWithRetry(asset),
    /slow-canonical\.js 加载超时/,
  );
  assert.equal(loader.scripts.length, 1, 'timeout must not consume the network-error retry allowance');
  assert.equal(new URL(loader.scripts[0].src, loader.document.baseURI).searchParams.has('suxi_retry'), false);

  const recovery = loader.loadDeferredAuthenticatedAssetWithRetry(asset);
  assert.equal(loader.scripts.length, 1, 'recovery must attach to the same canonical script node');
  loader.scripts[0].emit('load');
  await recovery;
  assert.equal(loader.scripts[0].dataset.suxiAssetLoaded, '1');
});

test('deferred manifest resets a rejected attempt and enforces one shared terminal deadline', async () => {
  const retryLoader = createAuthenticatedAssetLoaderHarness(20, 100);
  const assets = [{ type: 'script', src: 'manifest-retry.js?v=1' }];

  const firstManifestLoad = retryLoader.loadDeferredAuthenticatedAssets(assets);
  await new Promise(resolve => setImmediate(resolve));
  const firstAttempt = retryLoader.scripts.find(node => node.src.includes('manifest-retry.js'));
  firstAttempt.emit('error');
  await new Promise(resolve => setImmediate(resolve));
  const automaticRetry = retryLoader.scripts.find(node => node.src.includes('manifest-retry.js'));
  assert.notEqual(automaticRetry, firstAttempt);
  assert.equal(new URL(automaticRetry.src).searchParams.get('suxi_retry'), '1');
  automaticRetry.emit('error');
  await assert.rejects(firstManifestLoad, /manifest-retry\.js 加载失败/);
  assert.equal(
    retryLoader.events.filter(event => event.type === 'suxi:full-render-error').length,
    1,
    'one exhausted manifest attempt must publish one explicit terminal error',
  );

  const secondManifestLoad = retryLoader.loadDeferredAuthenticatedAssets(assets);
  assert.notEqual(secondManifestLoad, firstManifestLoad, 'a rejected manifest must create a fresh top-level Promise');
  await new Promise(resolve => setImmediate(resolve));
  const explicitRetry = retryLoader.scripts.find(node => node.src.includes('manifest-retry.js'));
  assert.notEqual(explicitRetry, automaticRetry, 'an explicit manifest retry must create a fresh failed asset node');
  explicitRetry.emit('load');
  await secondManifestLoad;
  assert.equal(retryLoader.document.documentElement.dataset.suxiFullRenderReady, '1');
  assert.equal(
    retryLoader.events.filter(event => event.type === 'suxi:full-render-ready').length,
    1,
    'only the fully recovered manifest may publish ready',
  );

  const deadlineLoader = createAuthenticatedAssetLoaderHarness(50, 35);
  const deadlineStartedAt = Date.now();
  const deadlineLoad = deadlineLoader.loadDeferredAuthenticatedAssets([
    { type: 'script', src: 'slow-before-deadline.js?v=1' },
    { type: 'script', src: 'late-stall.js?v=1' },
  ]);
  await new Promise(resolve => setTimeout(resolve, 12));
  deadlineLoader.scripts.find(node => node.src.startsWith('slow-before-deadline.js'))?.emit('load');
  await assert.rejects(deadlineLoad, /完整页面资源清单加载超时/);
  const elapsedMs = Date.now() - deadlineStartedAt;
  assert(
    elapsedMs < 80,
    `a late manifest stall must share the ${35}ms test deadline instead of receiving another full retry window (actual ${elapsedMs}ms)`,
  );
  assert.equal(
    deadlineLoader.events.filter(event => event.type === 'suxi:full-render-error').length,
    1,
  );
});

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
  assert.deepEqual(styleAssets, ['tailwind.min.css', 'style-startup.min.css', 'style.min.css', 'ai-custom.css']);
  assert.match(index, /<link rel="stylesheet" href="login-critical\.css\?v=[^"]+"/);
  assert.doesNotMatch(index, /<link[^>]+href="(?:tailwind\.min|style|ai-custom)\.css/);
  assert.equal(scriptAssets[0], 'vue.runtime.global.prod.js');
  assert.equal(scriptAssets.at(-3), 'app-startup-render.min.js');
  assert.equal(scriptAssets.at(-2), 'app-render.min.js');
  assert.equal(scriptAssets.at(-1), 'app-main.min.js');
  assert.equal(entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'app-render.min.js')?.phase, 'after-first-paint');
  assert.equal(entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'style-startup.min.css')?.phase, 'startup');
  assert.equal(entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'style.min.css')?.phase, 'after-first-paint');
  assert.equal(entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'ai-custom.css')?.phase, 'after-first-paint');
  assert.equal(
    entries.find((entry) => stripFrontendAssetQuery(entry.src) === 'app-deferred-helpers.min.js')?.phase,
    'after-first-paint',
    'full-page domain helpers must stay off the authenticated first paint',
  );
  assert(
    scriptAssets.indexOf('app-deferred-helpers.min.js') < scriptAssets.indexOf('app-render.min.js'),
    'deferred domain helpers must precede the full render',
  );
  const deferredScriptOrder = [
    'components/online-data/ctrip-order-analysis-loader.js',
    'ctrip-search-opportunity-static.js',
    'user-admin-static.js',
    'app-deferred-helpers.min.js',
    'components/system/app-main-components.js',
    'app-render.min.js',
  ];
  const deferredScriptIndexes = deferredScriptOrder.map((asset) => scriptAssets.indexOf(asset));
  assert(
    deferredScriptIndexes.every((index) => index >= 0),
    `deferred script chain is incomplete: ${JSON.stringify(deferredScriptIndexes)}`,
  );
  assert.deepEqual(
    deferredScriptIndexes,
    [...deferredScriptIndexes].sort((left, right) => left - right),
    'deferred helper, component factory, and full-render scripts must preserve manifest dependency order',
  );
  for (const deferredAsset of [
    'components/system/app-main-components.js',
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
  assert(
    !assets.includes('components/system/operating-intelligence-components.js'),
    'the optional operating-intelligence full component must load only after explicit user demand',
  );
  assert(assets.includes('app-startup-helpers.min.js'));
  assert(assets.includes('app-deferred-helpers.min.js'));
  for (const sourceAsset of [
    'shared-components.js',
    'ctrip-static-loader.js',
    'ctrip-static.js',
    'meituan-static.js',
    'data-health-static.js',
    'platform-profile-login-static.js',
    'competition-download-static.js',
    'system-static.js',
    'compass-static.js',
    'home-static.js',
    'dual-ota-home-static.js',
    'components/system/app-main-components-loader.js',
    'components/system/operating-intelligence-loader.js',
  ]) {
    assert(!assets.includes(sourceAsset), `${sourceAsset} must stay out of the runtime manifest`);
  }
  assert.match(index, /<script defer src="app-bootstrap\.min\.js\?v=[^"]+"[^>]*><\/script>/);
  const versionHash = index.match(/app-bootstrap\.min\.js\?v=[^"']*-h([a-f0-9]{10})/)?.[1];
  const contentHash = crypto.createHash('sha256').update(bootstrapRuntime).digest('hex').slice(0, 10);
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
  assert.match(bootstrap, /const AUTHENTICATED_FIRST_PAINT_FALLBACK_MS = 240;/);
  assert.match(
    bootstrap,
    /const waitForFirstAuthenticatedPaint = \(\) => new Promise\(\(resolve\) => \{[\s\S]*timeoutId = window\.setTimeout\(finish, AUTHENTICATED_FIRST_PAINT_FALLBACK_MS\);[\s\S]*window\.requestAnimationFrame\(\(\) => window\.requestAnimationFrame\(finish\)\)/,
    'background tabs must have a bounded fallback instead of waiting indefinitely for animation frames',
  );
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

test('authenticated interactive readiness is reset and republished for every login session', () => {
  assert.match(
    bootstrap,
    /const markLoginAuthSuccess = \(\{ source = 'public-login' \} = \{\}\) => \{\s*resetAuthenticatedInteractiveState\(\);/,
  );
  assert.match(
    bootstrap,
    /window\.SUXI_RESET_AUTHENTICATED_INTERACTIVE_STATE = resetAuthenticatedInteractiveState/,
  );
  assert.match(
    appMain,
    /const clearAuthSessionWithStatus = \(tokenStatus = 'missing'\) => \{\s*authSessionEpoch \+= 1;\s*window\.SUXI_RESET_AUTHENTICATED_INTERACTIVE_STATE\?\.\(\);/,
  );
  assert.match(
    appMain,
    /scheduleInitialBackendNotificationRefresh\(\);\s*await nextTick\(\);\s*publishSuxiAuthenticatedInteractiveReady\(\);/,
  );
});

test('authenticated startup paints the compact page before progressively hydrating the current full page', () => {
  assert.match(bootstrap, /assetBaseName\(asset\.src\) === 'vue\.runtime\.global\.prod\.js'/);
  assert.match(bootstrap, /assetBaseName\(asset\.src\) === 'app-main\.min\.js'/);
  assert.match(bootstrap, /asset\.phase === ASSET_PHASE_STARTUP/);
  assert.match(bootstrap, /asset\.phase === ASSET_PHASE_AFTER_FIRST_PAINT/);
  assert.match(bootstrap, /await Promise\.all\(\[\s*loadScript\(runtime\),/);
  assert.match(bootstrap, /await Promise\.all\(prerequisites\.map\(\(src\) => loadScript\(src\)\)\);/);
  assert.match(bootstrap, /await loadScript\(entry\);/);
  assert.match(bootstrap, /await waitForFirstAuthenticatedPaint\(\);/);
  assert.match(bootstrap, /suxi:full-render-ready/);
  assert.match(bootstrap, /const resolvedSrc = resolveAssetUrl\(src\);/);
  assert.match(
    bootstrap,
    /resolveAssetUrl\(script\.getAttribute\('src'\)\) === resolvedSrc/,
    'script reuse must compare the full versioned URL instead of only the basename',
  );
  assert.doesNotMatch(
    bootstrap,
    /find\(\(script\) => assetBaseName\(script\.getAttribute\('src'\)\) === assetName\)/,
  );
  assert.match(bootstrap, /url\.searchParams\.set\('suxi_retry', attempt\)/);
  assert.match(bootstrap, /canonicalSrc: asset\.src/);
  assert.match(bootstrap, /suxiCanonicalSrc = canonicalResolvedSrc/);
  assert.match(bootstrap, /error\?\.cause === 2/);
  assert.match(bootstrap, /error\?\.cause !== 1/);
  const deferredReadyMarker = bootstrap.indexOf("document.documentElement.dataset.suxiFullRenderReady = '1';");
  const deferredReadyEvent = bootstrap.indexOf("window.dispatchEvent(new CustomEvent('suxi:full-render-ready'", deferredReadyMarker);
  assert(deferredReadyMarker >= 0 && deferredReadyEvent > deferredReadyMarker, 'ready marker must follow all loads and precede the ready event');
  assert.match(bootstrap, /const loadDeferredAuthenticatedAssetManifest = \(\) => \{/);
  assert.match(bootstrap, /window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS = loadDeferredAuthenticatedAssetManifest;/);
  assert.match(bootstrap, /const loadDeferredAuthenticatedManifestAsset = \(assetName\) => \{/);
  assert.match(bootstrap, /waitForFirstAuthenticatedPaint\(\)\.then\(\(\) => loadDeferredAuthenticatedAssetWithRetry\(asset\)\)/);
  assert.match(bootstrap, /window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET = loadDeferredAuthenticatedManifestAsset;/);
  assert.match(
    bootstrap,
    /const fullRenderAsset = assets\.find\([\s\S]*?assetBaseName\(asset\.src\) === 'app-render\.min\.js'[\s\S]*?preloadAuthenticatedAsset\(fullRenderAsset, 'low'\)/,
  );
  const deferredLoadStart = bootstrap.indexOf('const loadDeferredAuthenticatedAssets = (assets = []) => {');
  const deferredLoadEnd = bootstrap.indexOf('\n\n    const loadDeferredAuthenticatedAssetManifest', deferredLoadStart);
  const deferredLoad = bootstrap.slice(deferredLoadStart, deferredLoadEnd);
  assert.match(deferredLoad, /const styles = assets\.filter\(\(asset\) => asset\.type === ASSET_TYPE_STYLE\);/);
  assert.match(deferredLoad, /const scripts = assets\.filter\(\(asset\) => asset\.type === ASSET_TYPE_SCRIPT\);/);
  assert.match(
    deferredLoad,
    /Promise\.all\(styles\.map\(\(asset\) => \(\s*loadDeferredAuthenticatedAssetWithRetry\(asset, manifestDeadlineEpochMs\)\s*\)\)\)/,
  );
  assert.match(
    deferredLoad,
    /for \(const asset of scripts\) \{\s*await loadDeferredAuthenticatedAssetWithRetry\(asset, manifestDeadlineEpochMs\);\s*\}/,
    'dependent deferred scripts must settle in manifest order while styles may download in parallel',
  );
  assert.match(deferredLoad, /Date\.now\(\) \+ DEFERRED_AUTHENTICATED_MANIFEST_TIMEOUT_MS/);
  assert.match(deferredLoad, /Promise\.race\(\[manifestDeadline, manifestLoad\]\)/);
  assert.doesNotMatch(
    deferredLoad,
    /Promise\.all\(assets\.map\(/,
    'the deferred manifest must not saturate the origin with every dependent script at once',
  );
  assert.match(deferredLoad, /deferredAuthenticatedAssetsPromise = currentLoad/);
  assert.match(
    deferredLoad,
    /if \(deferredAuthenticatedAssetsPromise === currentLoad\) \{\s*deferredAuthenticatedAssetsPromise = null;/,
    'a failed manifest must not permanently cache its rejected Promise',
  );
  const authenticatedLoadStart = bootstrap.indexOf('const loadAuthenticatedApp = () => {');
  const authenticatedLoadEnd = bootstrap.indexOf('\n\n    const loginMarkup', authenticatedLoadStart);
  const authenticatedLoad = bootstrap.slice(authenticatedLoadStart, authenticatedLoadEnd);
  assert.doesNotMatch(authenticatedLoad, /void loadDeferredAuthenticatedAssets\(/);
  assert.match(authenticatedLoad, /await loadScript\(entry\);/);
  assert.match(appMain, /requestSuxiFullRenderForPage = \(page\) => \{[\s\S]*window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS\(\)/);
  assert.doesNotMatch(appMain, /startupRenderPages\.has\(normalizedPage\)/);
  assert.match(
    appMain,
    /if \(!normalizedPage\s*\|\| normalizedPage === 'compass'\s*\|\| document\.documentElement\.dataset\.suxiRenderPhase === 'full'\)/,
    'the startup compass must not pull the full-page asset manifest before a real page transition',
  );
  assert.match(
    appMain,
    /const promoteSuxiFullRender = \(\) => \{[\s\S]*!fullRenderRuntimeReady\(\)\) return false;/,
    'full render must not mount while deferred helper namespaces are still loading',
  );
  assert.match(
    appMain,
    /const handleSuxiFullRenderReady = \(\) => \{\s*clearSuxiFullRenderAttempt\(\);\s*if \(!fullRenderRuntimeReady\(\)\)[\s\S]*requestSuxiFullRenderForPage\(pendingFullRenderPage\)/,
    'the completed deferred-asset event must release the full-render barrier',
  );
  assert.match(
    appMain,
    /const render = fullRenderRuntimeReady\(\)\s*\? window\.SUXI_APP_RENDER\s*:\s*window\.SUXI_APP_STARTUP_RENDER/,
    'a stale full-render global must not replace the safe startup render',
  );
  assert.match(
    appMain,
    /if \(!fullRenderRuntimeReady\(\)[\s\S]*window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS/,
    'a stale full-render global must not suppress the deferred helper loader',
  );
  assert.doesNotMatch(appMain, /if \(window\.SUXI_APP_RENDER\) handleSuxiFullRenderReady\(\)/);
  assert.match(appMain, />重试完整资源<\/button>/);
  assert.match(appMain, /const retrySuxiFullRender = async \(\) => \{/);
  assert.match(appMain, /bindSuxiFullRenderAttempt\(\);\s*try \{\s*await window\.SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSETS/);
  assert.doesNotMatch(appMain, /suxi:full-render-(?:ready|error)', handleSuxiFullRender(?:Ready|Error), \{ once: true \}/);
  assert.doesNotMatch(bootstrap, /for \(const src of assets\)/);
});

test('deferred component bridges keep startup components small and preserve full factories', () => {
  assert.match(appMainComponentsLoader, /window\.SUXI_APP_MAIN_COMPONENTS = Object\.freeze\(\{ create \}\)/);
  assert.match(appMainComponentsLoader, /window\.SUXI_APP_MAIN_COMPONENTS_FULL/);
  assert.match(operatingIntelligenceLoader, /window\.SUXI_OPERATING_INTELLIGENCE_COMPONENTS = Object\.freeze\(\{ create \}\)/);
  assert.match(operatingIntelligenceLoader, /window\.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL/);
  assert.match(operatingIntelligenceLoader, /SUXI_LOAD_DEFERRED_AUTHENTICATED_ASSET/);
  assert.match(operatingIntelligenceLoader, /style\.min\.css/);
  assert.match(appMainComponents, /window\.SUXI_APP_MAIN_COMPONENTS_FULL = exportedFactory/);
  assert.match(operatingIntelligenceComponents, /window\.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = exportedFactory/);
  assert.match(operatingIntelligenceComponents, /const create = \(\{ ref, computed, inject, h, nextTick, onMounted, onUnmounted \}\) => \{/);
  assert.match(appMain, /\{\s*Vue, ref, computed, inject, h, nextTick, onMounted, onUnmounted,\s*\}/);
});

test('data-health helper calls stay lazy until the progressive full-page bundle is ready', () => {
  assert.match(systemStatic, /const requireDeferredStaticFunction = \(namespace, key, missingMessage, onAccess = null\)/);
  assert.match(systemStatic, /const createLazyFactoryMethods = \(factory, methods = \[\]\)/);
  assert.match(
    appMain,
    /const requirePlatformBatchHealthStatic = key => requireDeferredStaticFunction\('SUXI_DATA_HEALTH_STATIC'/,
  );
  assert.match(
    appMain,
    /const requireDataHealthStatic = key => requireDeferredStaticFunction\('SUXI_DATA_HEALTH_STATIC'[\s\S]*\(\) => dataHealthStaticVersion\.value\)/,
  );
  assert.match(
    appMain,
    /const dataHealthRefreshRequestState = createLazyFactoryMethods\(\s*createDataHealthRefreshRequestState,/,
  );
  assert.doesNotMatch(appMain, /const dataHealthRefreshRequestState = createDataHealthRefreshRequestState\(\);/);
  assert.match(
    appMain,
    /window\.SUXI_DATA_HEALTH_STATIC\s*\?\s*otaConfigOverviewAllRows\.value[\s\S]*:\s*null/,
  );
  assert.match(
    appMain,
    /dataHealthTodayWorkOrders:\s*\(dataHealthStaticVersion\.value,\s*window\.SUXI_DATA_HEALTH_STATIC\s*\?\s*dataHealthTodayWorkOrders\.value\s*:\s*\[\]\)/,
    'startup notifications must defer data-health work orders until the helper namespace is ready',
  );
  assert.match(
    appMain,
    /const scheduleOnlineHistoryRefresh = \(\) => schedulePostFetchRefresh\('online-history',[\s\S]*window\.SUXI_DATA_HEALTH_STATIC[\s\S]*refreshOnlineHistory\(\{ refreshHotels: false \}\)[\s\S]*:\s*null/,
    'post-fetch history refresh must skip the startup shell until deferred data-health helpers are ready',
  );
  assert.match(
    appMain,
    /publishDataHealthStaticReady = \(\) => !!window\.SUXI_DATA_HEALTH_STATIC[\s\S]*dataHealthStaticVersion\.value \+= 1/,
  );
  assert.match(appMain, /DATA_HEALTH_STATIC_CONTRACT_VERSION = '20260811-full-render-v1'/);
  assert.match(appMain, /dataHealthStatic\?\.contractVersion === DATA_HEALTH_STATIC_CONTRACT_VERSION/);
  assert.match(appMain, /const handleSuxiFullRenderReady = \(\) => \{[\s\S]*publishDataHealthStaticReady\(\);/);
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
  assert.doesNotMatch(bootstrap, /scheduleAuthenticatedEntryPreload/);
  assert.match(bootstrap, /form\.addEventListener\('focusin', preloadAuthenticatedEntry\)/);
  assert.match(bootstrap, /const handleInput = \(\) => \{[\s\S]*?preloadAuthenticatedEntry\(\)/);

  const submitStart = bootstrap.indexOf("form.addEventListener('submit'");
  const entryPreloadOffset = bootstrap.indexOf('preloadAuthenticatedEntry();', submitStart);
  const loginRequestOffset = bootstrap.indexOf("fetchJson('/api/auth/login'", submitStart);
  assert(submitStart >= 0 && entryPreloadOffset > submitStart && loginRequestOffset > entryPreloadOffset);
});

test('authenticated login lands on the today operating dashboard through one entry helper', () => {
  const helperStart = appMain.indexOf('const activateCoreOperationsAfterLogin = () => {');
  const helperEnd = appMain.indexOf('\n            const isVisibleOnlineDataTab', helperStart);
  const helper = appMain.slice(helperStart, helperEnd);
  assert(helperStart >= 0 && helperEnd > helperStart, 'core-operations activation helper must exist');
  assert.match(helper, /const landingPage = initialPageOverride \|\| 'compass';/);
  assert.match(helper, /currentPage\.value = landingPage;/);
  assert.match(helper, /const requestPolicy = currentCompassReadPolicy\(landingPage, 'current'\);/);
  assert.match(helper, /runPageLoadOnce\([\s\S]*landingPage,[\s\S]*'main',[\s\S]*loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)[\s\S]*\{ ttlMs: DASHBOARD_PAGE_CACHE_TTL_MS, requestPolicy \}/);
  assert.doesNotMatch(helper, /openOnlineDataEntryTab\('data-health'\)/);

  const loginStart = appMain.indexOf('const handleLogin = async () => {');
  const loginEnd = appMain.indexOf('\n            const loadLoginSupportContact', loginStart);
  const loginFlow = appMain.slice(loginStart, loginEnd);
  assert.match(
    loginFlow,
    /const primaryPageLoad = activateCoreOperationsAfterLogin\(\);\s*loadData\(\);\s*scheduleHotelManagementPrewarmAfter\(primaryPageLoad\);/,
    'hotel-management prewarm must wait for the primary dashboard load to settle',
  );
  assert.match(loginFlow, /applyDefaultReportHotel\(\{ suppressDashboardRefresh: true \}\)/);
  assert.doesNotMatch(loginFlow, /scheduleInitialCompassLoad|scheduleDualOtaWorkbenchAutoFetch/);

  const mountedStart = appMain.indexOf('onMounted(() => {');
  const mountedEnd = appMain.indexOf('\n            onUnmounted', mountedStart);
  const mountedFlow = appMain.slice(mountedStart, mountedEnd);
  assert.match(mountedFlow, /if \(token\.value\) \{\s*requestSuxiFullRenderForPage\(currentPage\.value\);/, 'remembered sessions must route the initial page through the startup/full-render gate');
  assert.match(
    mountedFlow,
    /const primaryPageLoad = isCompassDataPage\(\)\s*\? activateCoreOperationsAfterLogin\(\)\s*: nextTick\(\);\s*scheduleHotelManagementPrewarmAfter\(primaryPageLoad\);/,
    'remembered sessions must also defer hotel-management prewarm until the primary page is ready',
  );
  assert.match(mountedFlow, /applyDefaultReportHotel\(\{ suppressDashboardRefresh: true \}\)/);
  assert.match(
    mountedFlow,
    /request\('\/auth\/info', \{\s*requestPolicy: \{ scope: 'session', priority: 'current' \},\s*\}\)[\s\S]*handleAuthInfoBootstrapUnavailable\(bootstrapSession\)/,
    'transient auth-info failures must retain the current session for retry',
  );
  assert.doesNotMatch(
    mountedFlow,
    /request\('\/auth\/info', \{[\s\S]*?\}\)[\s\S]*clearAuthSession\(\)/,
    'auth-info bootstrap must not clear a session after network, 5xx, or malformed-response failures',
  );
  assert.match(appMain, /if \(response\.status === 401 \|\| data\.code === 401\)[\s\S]*clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/, 'explicit 401 responses must still clear the matching invalid session');
  assert.match(appMain, /isTerminalAuthFailureResponse\(response, data\)[\s\S]*clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/, 'explicit terminal auth responses, including disabled users, must clear the matching cached session');
  assert.match(appMain, /authFailureReason === 'user_disabled'/, 'disabled-user responses must be distinguished from ordinary permission denials');
  assert.doesNotMatch(mountedFlow, /scheduleInitialCompassLoad|scheduleDualOtaWorkbenchAutoFetch/);
  assert.doesNotMatch(appMain, /const scheduleInitialCompassLoad =/);

  const pageWatcherStart = appMain.indexOf('watch(currentPage, (newPage) => {');
  const pageWatcherEnd = appMain.indexOf('\n            watch(onlineDataTab', pageWatcherStart);
  const pageWatcher = appMain.slice(pageWatcherStart, pageWatcherEnd);
  assert.match(pageWatcher, /const requestPolicy = currentCompassReadPolicy\(newPage, 'current'\);[\s\S]*loadCompassData\(\{ skipOtaBackground: true, requestPolicy \}\)/);
  assert.match(appMain, /if \(options\.skipOtaBackground !== true\) \{[\s\S]*?loadLatestCtripData[\s\S]*?loadCompetitorSummary/);
});

test('authenticated dashboard defers read-only secondary APIs and delays OTA collection', () => {
  const compassLoaderStart = appMain.indexOf('const loadCompassData = async (options = {}) => {');
  const compassLoaderEnd = appMain.indexOf('\n\n            const refreshCompassDashboard', compassLoaderStart);
  const compassLoader = appMain.slice(compassLoaderStart, compassLoaderEnd);
  assert.match(appMain, /const AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS = 4600;/);
  assert.match(appMain, /const scheduleDualOtaWorkbenchAutoFetch = \(delayMs = 9000\) => \{/);
  assert.match(
    appMain,
    /const scheduleInitialBackendNotificationRefresh = \(delayMs = AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS\) => \{/,
  );
  assert.match(
    appMain,
    /const schedulePublicSystemConfigRefresh = \(delayMs = AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS\) => \{/,
  );
  assert.match(
    compassLoader,
    /scheduleDelayedPageTask\(async \(\) => \{[\s\S]*?const compassBackgroundJobs = \[[\s\S]*?\}, 6200\);/,
  );
  assert.doesNotMatch(
    compassLoader,
    /deferUiTask\(async \(\) => \{[\s\S]*?const compassBackgroundJobs = \[/,
  );
  assert.match(appMain, /scheduleStartupHotelListLoad\(\);\s*schedulePublicSystemConfigRefresh\(\);/);
  assert.match(appMain, /\/\/ 手动触发自动获取\s+const triggerAutoFetch = async/);
  assert.doesNotMatch(appMain, /scheduleInitialBackendNotificationRefresh = \(delayMs = 800\)/);
  assert.doesNotMatch(appMain, /schedulePublicSystemConfigRefresh = \(delayMs = 1800\)/);
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

test('public login shell releases global listeners, timers, and warmup after authenticated handoff', () => {
  assert.match(bootstrap, /let disposeLoginShell = null/);
  assert.match(bootstrap, /const disposeCurrentLoginShell = \(\) =>/);
  assert.match(bootstrap, /autofillSyncTimers\.forEach\(\(timer\) => window\.clearTimeout\(timer\)\)/);
  assert.match(bootstrap, /loginConnectionWarmup\.stop\(\)/);
  for (const expectedCleanup of [
    /window\.removeEventListener\('pageshow', scheduleLoginAutofillSync\)/,
    /window\.removeEventListener\('pageshow', warmLoginConnection\)/,
    /window\.removeEventListener\('focus', scheduleLoginAutofillSync\)/,
    /window\.removeEventListener\('focus', warmLoginConnection\)/,
    /document\.removeEventListener\('visibilitychange', handleLoginVisibilityChange\)/,
    /window\.removeEventListener\('pagehide', loginConnectionWarmup\.stop\)/,
  ]) {
    assert.match(bootstrap, expectedCleanup);
  }
  assert.match(bootstrap, /await loadAuthenticatedApp\(\);\s*disposeLoginShell\?\.\(\);/);
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

test('authenticated dashboard defers secondary API requests beyond the first measurement window', () => {
  const compassLoaderStart = appMain.indexOf('const loadCompassData = async (options = {}) => {');
  const compassLoaderEnd = appMain.indexOf('\n\n            const refreshCompassDashboard', compassLoaderStart);
  const compassLoader = appMain.slice(compassLoaderStart, compassLoaderEnd);
  assert.match(appMain, /const AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS = 4600;/);
  assert.match(
    appMain,
    /const scheduleDualOtaWorkbenchAutoFetch = \(delayMs = 9000\) => \{/,
  );
  assert.match(
    appMain,
    /const scheduleInitialBackendNotificationRefresh = \(delayMs = AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS\) => \{/,
  );
  assert.match(
    appMain,
    /const schedulePublicSystemConfigRefresh = \(delayMs = AUTHENTICATED_SECONDARY_REQUEST_DELAY_MS\) => \{/,
  );
  assert.match(
    compassLoader,
    /scheduleDelayedPageTask\(async \(\) => \{[\s\S]*?const compassBackgroundJobs = \[[\s\S]*?\}, 6200\);/,
  );
  assert.doesNotMatch(
    compassLoader,
    /deferUiTask\(async \(\) => \{[\s\S]*?const compassBackgroundJobs = \[/,
  );
  assert.match(appMain, /scheduleStartupHotelListLoad\(\);\s*schedulePublicSystemConfigRefresh\(\);/);
  assert.doesNotMatch(appMain, /scheduleDualOtaWorkbenchAutoFetch = \(delayMs = 900\)/);
  assert.doesNotMatch(appMain, /scheduleInitialBackendNotificationRefresh = \(delayMs = 800\)/);
  assert.doesNotMatch(appMain, /schedulePublicSystemConfigRefresh = \(delayMs = 1800\)/);
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
