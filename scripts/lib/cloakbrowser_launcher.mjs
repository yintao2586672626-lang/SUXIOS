import { launchPersistentContext } from 'cloakbrowser';
import { chromium } from 'playwright-core';

const freshOtaNetworkSessions = new WeakMap();
const GUARDED_PAGE_MARKER = 'suxios_profile_lease_guarded';

export async function launchOtaPersistentContext(userDataDir, parsedArgs, defaults = {}) {
  const cdpUrl = resolveOtaCdpUrl(parsedArgs);
  if (cdpUrl) {
    return connectOtaCdpContext(cdpUrl, defaults.connectOverCDP || chromium);
  }

  const configuredBinary = stringValue(process.env.CLOAKBROWSER_BINARY_PATH);
  const requestedBinary = resolveOtaBrowserBinaryPath(parsedArgs);
  const applyRequestedBinary = configuredBinary === '' && requestedBinary !== '';

  // cloakbrowser resolves its executable from this environment variable before
  // looking at Playwright launch options. Without this bridge, --chrome-path
  // still causes a hidden first-run Chromium download.
  if (applyRequestedBinary) {
    process.env.CLOAKBROWSER_BINARY_PATH = requestedBinary;
  }

  try {
    return await launchPersistentContext(buildOtaPersistentContextOptions(userDataDir, parsedArgs, defaults));
  } finally {
    if (applyRequestedBinary) {
      delete process.env.CLOAKBROWSER_BINARY_PATH;
    }
  }
}

export async function connectOtaCdpContext(cdpUrl, chromiumClient = chromium) {
  const normalizedCdpUrl = normalizeOtaCdpUrl(cdpUrl);
  if (!normalizedCdpUrl) {
    throw new Error('ota_browser_cdp_url_invalid');
  }

  const connectOverCDP = typeof chromiumClient === 'function'
    ? chromiumClient
    : chromiumClient?.connectOverCDP?.bind(chromiumClient);
  if (typeof connectOverCDP !== 'function') {
    throw new Error('ota_browser_cdp_connector_invalid');
  }
  const browser = await connectOverCDP(normalizedCdpUrl);
  const contexts = typeof browser?.contexts === 'function' ? browser.contexts() : [];
  if (!Array.isArray(contexts) || contexts.length !== 1) {
    await browser?.close?.().catch(() => undefined);
    throw new Error('ota_browser_cdp_context_count_invalid');
  }
  const guardedPage = await findGuardedConnectedPage(contexts[0]);
  if (!guardedPage) {
    await browser.close().catch(() => undefined);
    throw new Error('ota_browser_cdp_guarded_page_missing');
  }
  return createConnectedContextFacade(contexts[0], browser, guardedPage);
}

/**
 * Persistent OTA Profiles intentionally keep authenticated browser state, but
 * business responses must never be reused from the browser HTTP cache or a
 * Service Worker. Keep the CDP session alive for the lifetime of the page and
 * fail closed when Chromium cannot establish this current-page network gate.
 */
export async function requireFreshOtaPageNetwork(context, page) {
  if (!context || typeof context.newCDPSession !== 'function' || !page) {
    throw new Error('ota_browser_fresh_network_control_unavailable');
  }

  try {
    const session = await context.newCDPSession(page);
    if (!session || typeof session.send !== 'function') {
      throw new Error('cdp_session_unavailable');
    }
    await session.send('Network.enable');
    await session.send('Network.setCacheDisabled', { cacheDisabled: true });
    await session.send('Network.setBypassServiceWorker', { bypass: true });
    freshOtaNetworkSessions.set(page, session);
  } catch {
    throw new Error('ota_browser_fresh_network_control_unavailable');
  }

  return {
    status: 'ready',
    http_cache_disabled: true,
    service_worker_bypassed: true,
    sensitive_values_exposed: false,
  };
}

export function resolveOtaCdpUrl(parsedArgs = {}) {
  const value = stringValue(parsedArgs.cdpUrl || parsedArgs.cdp_url);
  if (value === '') {
    return '';
  }
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error('Invalid cloud browser CDP URL.');
  }
  if (url.protocol !== 'http:'
    || url.hostname !== '127.0.0.1'
    || url.port !== '9223'
    || (url.pathname !== '' && url.pathname !== '/')
    || url.search !== ''
    || url.hash !== ''
    || url.username !== ''
    || url.password !== ''
  ) {
    throw new Error('Cloud browser CDP URL must be http://127.0.0.1:9223.');
  }
  return 'http://127.0.0.1:9223';
}

export function createConnectedContextFacade(context, connectedBrowser, guardedPage = null) {
  if (!context
    || typeof context.pages !== 'function'
    || typeof context.newPage !== 'function'
    || !connectedBrowser
    || typeof connectedBrowser.close !== 'function'
  ) {
    throw new TypeError('Invalid connected browser context.');
  }

  const existingPages = new Set(context.pages());
  const reservedPage = guardedPage || [...existingPages].at(-1) || null;
  if (existingPages.size !== 1 || !reservedPage || !existingPages.has(reservedPage)) {
    throw new TypeError('Connected browser context must contain exactly one guarded page.');
  }
  let reservedPageClaimed = false;
  let closed = false;

  return new Proxy(context, {
    get(target, property, receiver) {
      if (property === 'newPage') {
        return async (...args) => {
          if (!reservedPageClaimed
            && reservedPage
            && (typeof reservedPage.isClosed !== 'function' || !reservedPage.isClosed())
          ) {
            reservedPageClaimed = true;
            return reservedPage;
          }
          throw new Error('ota_browser_cdp_additional_page_blocked');
        };
      }
      if (property === 'close') {
        return async () => {
          if (closed) return;
          closed = true;
          const createdPages = target.pages().filter(page => !existingPages.has(page));
          await Promise.all(createdPages.map(page => page.close().catch(() => undefined)));
          await connectedBrowser.close();
        };
      }
      const value = Reflect.get(target, property, receiver);
      return typeof value === 'function' ? value.bind(target) : value;
    },
  });
}

async function findGuardedConnectedPage(context) {
  const pages = context.pages();
  if (pages.length !== 1 || typeof pages[0]?.evaluate !== 'function') return null;
  const marker = await pages[0].evaluate(() => window.name).catch(() => '');
  return marker === GUARDED_PAGE_MARKER ? pages[0] : null;
}

export function resolveOtaBrowserBinaryPath(parsedArgs = {}) {
  return stringValue(process.env.CLOAKBROWSER_BINARY_PATH) || stringValue(parsedArgs.chromePath);
}

export function buildOtaPersistentContextOptions(userDataDir, parsedArgs, defaults = {}) {
  const args = buildChromiumArgs(parsedArgs);
  const launchOptions = {};
  const chromePath = resolveOtaBrowserBinaryPath(parsedArgs);
  if (chromePath) {
    launchOptions.executablePath = chromePath;
  }

  return {
    userDataDir,
    headless: parsedArgs.headless === 'true',
    viewport: defaults.viewport || { width: 1440, height: 960 },
    locale: stringValue(parsedArgs.locale) || defaults.locale || 'zh-CN',
    ...(stringValue(parsedArgs.timezone) ? { timezone: stringValue(parsedArgs.timezone) } : {}),
    ...(stringValue(parsedArgs.proxy) ? { proxy: stringValue(parsedArgs.proxy) } : {}),
    ...(parsedArgs.geoip === 'true' ? { geoip: true } : {}),
    ...(parsedArgs.humanize === 'true' ? { humanize: true } : {}),
    ...(args.length ? { args } : {}),
    ...(Object.keys(launchOptions).length ? { launchOptions } : {}),
  };
}

function buildChromiumArgs(parsedArgs) {
  const args = [];
  const fingerprint = stringValue(parsedArgs.fingerprint || parsedArgs.fingerprintSeed);
  if (fingerprint) {
    args.push(`--fingerprint=${fingerprint}`);
  }

  const remoteDebuggingPort = stringValue(parsedArgs.remoteDebuggingPort || parsedArgs.cdpPort);
  if (remoteDebuggingPort) {
    const port = Number(remoteDebuggingPort);
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error(`Invalid remote debugging port: ${remoteDebuggingPort}`);
    }
    args.push(`--remote-debugging-port=${port}`);
    args.push('--remote-debugging-address=127.0.0.1');
  }

  return args;
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  return String(value).trim();
}

function normalizeOtaCdpUrl(value) {
  const match = /^http:\/\/127\.0\.0\.1:([1-9]\d{0,4})\/?$/u.exec(stringValue(value));
  if (!match) {
    return '';
  }
  const port = Number(match[1]);
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    return '';
  }
  return `http://127.0.0.1:${port}`;
}
