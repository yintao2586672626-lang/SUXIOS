import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
  buildOtaPersistentContextOptions,
  connectOtaCdpContext,
  requireFreshOtaPageNetwork,
  createConnectedContextFacade,
  launchOtaPersistentContext,
  resolveOtaBrowserBinaryPath,
  resolveOtaCdpUrl,
} from '../../scripts/lib/cloakbrowser_launcher.mjs';

test('OTA CDP URL accepts only an explicit IPv4 loopback endpoint', () => {
  assert.equal(resolveOtaCdpUrl({ cdpUrl: 'http://127.0.0.1:9223/' }), 'http://127.0.0.1:9223');
  assert.equal(resolveOtaCdpUrl({}), '');
  assert.throws(() => resolveOtaCdpUrl({ cdpUrl: 'http://localhost:9223' }), /CDP URL/);
  assert.throws(() => resolveOtaCdpUrl({ cdpUrl: 'http://127.0.0.1:65536' }), /CDP URL/);
  assert.throws(() => resolveOtaCdpUrl({ cdpUrl: 'http://user:secret@127.0.0.1:9223' }), /CDP URL/);
});

test('CDP attach rejects every non-loopback or malformed endpoint with one stable error', async () => {
  for (const cdpUrl of [
    'http://localhost:9223',
    'http://127.0.0.1:65536',
    'http://user:secret@127.0.0.1:9223',
  ]) {
    await assert.rejects(
      () => connectOtaCdpContext(cdpUrl, async () => {
        throw new Error('connector must not be called');
      }),
      /ota_browser_cdp_url_invalid/,
    );
  }
});

test('CDP attach returns a guarded facade and its close shuts down the owning browser once', async () => {
  const guardedPage = {
    isClosed: () => false,
    evaluate: async () => 'suxios_profile_lease_guarded',
  };
  const context = {
    pages: () => [guardedPage],
    newPage: async () => {
      throw new Error('guarded page must be reused');
    },
  };
  let closeCount = 0;
  const browser = {
    contexts: () => [context],
    async close() {
      closeCount += 1;
    },
  };
  const chromiumClient = {
    async connectOverCDP(url) {
      assert.equal(url, 'http://127.0.0.1:9223');
      return browser;
    },
  };

  const attached = await connectOtaCdpContext('http://127.0.0.1:9223', chromiumClient);
  assert.equal(await attached.newPage(), guardedPage);
  await assert.rejects(() => attached.newPage(), /ota_browser_cdp_additional_page_blocked/);
  assert.notEqual(attached, context);
  await attached.close();
  await attached.close();
  assert.equal(closeCount, 1);
});

test('CDP attach fails closed and closes the browser unless exactly one context exists', async () => {
  let closed = false;
  const chromiumClient = {
    async connectOverCDP() {
      return {
        contexts: () => [{}, {}],
        async close() {
          closed = true;
        },
      };
    },
  };

  await assert.rejects(
    () => connectOtaCdpContext('http://127.0.0.1:9223', chromiumClient),
    /ota_browser_cdp_context_count_invalid/,
  );
  assert.equal(closed, true);
});

test('CDP attach fails closed when the context contains more than the guarded page', async () => {
  const guardedPage = { evaluate: async () => 'suxios_profile_lease_guarded' };
  const extraPage = { evaluate: async () => '' };
  let closed = false;
  await assert.rejects(
    () => connectOtaCdpContext('http://127.0.0.1:9223', {
      connectOverCDP: async () => ({
        contexts: () => [{ pages: () => [guardedPage, extraPage] }],
        close: async () => { closed = true; },
      }),
    }),
    /ota_browser_cdp_guarded_page_missing/,
  );
  assert.equal(closed, true);
});

test('configured browser binary takes precedence over a request path', () => {
  const previous = process.env.CLOAKBROWSER_BINARY_PATH;
  process.env.CLOAKBROWSER_BINARY_PATH = '/opt/suxios/chrome';
  try {
    assert.equal(resolveOtaBrowserBinaryPath({ chromePath: '/requested/chrome' }), '/opt/suxios/chrome');
    assert.equal(
      buildOtaPersistentContextOptions('/tmp/suxios-profile', { chromePath: '/requested/chrome' }).launchOptions.executablePath,
      '/opt/suxios/chrome',
    );
  } finally {
    if (previous === undefined) delete process.env.CLOAKBROWSER_BINARY_PATH;
    else process.env.CLOAKBROWSER_BINARY_PATH = previous;
  }
});

test('request chrome path is used when no process-wide binary is configured', () => {
  const previous = process.env.CLOAKBROWSER_BINARY_PATH;
  delete process.env.CLOAKBROWSER_BINARY_PATH;
  try {
    assert.equal(resolveOtaBrowserBinaryPath({ chromePath: '/requested/chrome' }), '/requested/chrome');
  } finally {
    if (previous === undefined) delete process.env.CLOAKBROWSER_BINARY_PATH;
    else process.env.CLOAKBROWSER_BINARY_PATH = previous;
  }
});

test('fresh OTA page network disables cache and bypasses service workers', async () => {
  const calls = [];
  const page = {};
  const session = {
    async send(method, params) {
      calls.push([method, params]);
    },
  };
  const context = {
    async newCDPSession(receivedPage) {
      assert.equal(receivedPage, page);
      return session;
    },
  };

  assert.deepEqual(await requireFreshOtaPageNetwork(context, page), {
    status: 'ready',
    http_cache_disabled: true,
    service_worker_bypassed: true,
    sensitive_values_exposed: false,
  });
  assert.deepEqual(calls, [
    ['Network.enable', undefined],
    ['Network.setCacheDisabled', { cacheDisabled: true }],
    ['Network.setBypassServiceWorker', { bypass: true }],
  ]);
});

test('fresh OTA page network fails closed without leaking CDP errors', async () => {
  const context = {
    async newCDPSession() {
      return {
        async send(method) {
          if (method === 'Network.setCacheDisabled') {
            throw new Error('secret browser diagnostic');
          }
        },
      };
    },
  };

  await assert.rejects(
    () => requireFreshOtaPageNetwork(context, {}),
    error => error?.message === 'ota_browser_fresh_network_control_unavailable'
      && !error.message.includes('secret'),
  );
});

test('every Ctrip and Meituan capture page installs the fresh network gate', async () => {
  const ctrip = await readFile(new URL('../../scripts/ctrip_browser_capture.mjs', import.meta.url), 'utf8');
  const meituan = await readFile(new URL('../../scripts/meituan_browser_capture.mjs', import.meta.url), 'utf8');

  assert.match(
    ctrip,
    /payload\.network_freshness = authOnly\s*\?\s*await prepareCtripAuthPage\(page\)\s*:\s*await requireFreshOtaPageNetwork\(browser, page\)/,
  );
  assert.match(ctrip, /networkFreshness = await requireFreshOtaPageNetwork\(context, sectionPage\)/);
  assert.match(ctrip, /retry_network_freshness = await requireFreshOtaPageNetwork\(context, retryPage\)/);
  assert.match(meituan, /payload\.network_freshness = await requireFreshOtaPageNetwork\(browser, page\)/);
});

test('cloud Profile CDP accepts only the protected loopback endpoint', () => {
  assert.equal(resolveOtaCdpUrl({ cdpUrl: 'http://127.0.0.1:9223' }), 'http://127.0.0.1:9223');
  assert.equal(resolveOtaCdpUrl({}), '');
  for (const value of [
    'http://127.0.0.1:9222',
    'http://localhost:9223',
    'https://127.0.0.1:9223',
    'http://127.0.0.1:9223/json',
    'http://user@127.0.0.1:9223',
  ]) {
    assert.throws(() => resolveOtaCdpUrl({ cdpUrl: value }), /CDP URL/);
  }
});

test('connected context reuses only the guarded page and rejects additional pages', async () => {
  const calls = [];
  const guardedPage = {
    isClosed: () => false,
    close: async () => calls.push('guarded_close'),
  };
  const createdPage = {
    close: async () => calls.push('created_close'),
  };
  const pages = [guardedPage];
  const context = {
    pages: () => pages,
    newPage: async () => {
      pages.push(createdPage);
      return createdPage;
    },
    grantPermissions: async () => 'ok',
  };
  const browser = {
    close: async () => calls.push('disconnect'),
  };
  const facade = createConnectedContextFacade(context, browser);

  assert.equal(await facade.newPage(), guardedPage);
  await assert.rejects(() => facade.newPage(), /ota_browser_cdp_additional_page_blocked/);
  assert.equal(await facade.grantPermissions(), 'ok');
  await facade.close();
  await facade.close();

  assert.deepEqual(calls, ['disconnect']);
});

test('launcher connects to the gateway CDP instead of launching a persistent profile', async () => {
  const guardedPage = {
    isClosed: () => false,
    close: async () => undefined,
    evaluate: async () => 'suxios_profile_lease_guarded',
  };
  const context = {
    pages: () => [guardedPage],
    newPage: async () => {
      throw new Error('must reuse guarded page');
    },
  };
  let connectedUrl = '';
  const facade = await launchOtaPersistentContext('/unused/profile', {
    cdpUrl: 'http://127.0.0.1:9223',
  }, {
    connectOverCDP: async (url) => {
      connectedUrl = url;
      return {
        contexts: () => [context],
        close: async () => undefined,
      };
    },
  });

  assert.equal(connectedUrl, 'http://127.0.0.1:9223');
  assert.equal(await facade.newPage(), guardedPage);
  await facade.close();
});
