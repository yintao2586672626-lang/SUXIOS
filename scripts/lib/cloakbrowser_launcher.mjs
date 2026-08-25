import { launchPersistentContext } from 'cloakbrowser';
import { chromium } from 'playwright-core';

const freshOtaNetworkSessions = new WeakMap();

export async function launchOtaPersistentContext(userDataDir, parsedArgs, defaults = {}) {
  const cdpUrl = resolveOtaCdpUrl(parsedArgs);
  if (cdpUrl) {
    return connectOtaCdpContext(cdpUrl);
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

  const browser = await chromiumClient.connectOverCDP(normalizedCdpUrl);
  const contexts = browser.contexts();
  if (contexts.length !== 1) {
    await browser.close().catch(() => null);
    throw new Error('ota_browser_cdp_context_count_invalid');
  }

  const context = contexts[0];
  let closed = false;
  Object.defineProperty(context, 'close', {
    configurable: true,
    value: async () => {
      if (closed) {
        return;
      }
      closed = true;
      await browser.close();
    },
  });

  return context;
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

export function resolveOtaBrowserBinaryPath(parsedArgs = {}) {
  return stringValue(process.env.CLOAKBROWSER_BINARY_PATH) || stringValue(parsedArgs.chromePath);
}

export function resolveOtaCdpUrl(parsedArgs = {}) {
  const value = stringValue(parsedArgs.cdpUrl || parsedArgs.cdp_url);
  if (!value) {
    return '';
  }
  const normalized = normalizeOtaCdpUrl(value);
  if (!normalized) {
    throw new Error('ota_browser_cdp_url_invalid');
  }
  return normalized;
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
