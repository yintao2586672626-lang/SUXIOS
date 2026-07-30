import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildOtaPersistentContextOptions,
  createConnectedContextFacade,
  launchOtaPersistentContext,
  resolveOtaBrowserBinaryPath,
  resolveOtaCdpUrl,
} from '../../scripts/lib/cloakbrowser_launcher.mjs';

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

test('connected context reuses the guarded page and closes only pages it creates', async () => {
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
  assert.equal(await facade.newPage(), createdPage);
  assert.equal(await facade.grantPermissions(), 'ok');
  await facade.close();
  await facade.close();

  assert.deepEqual(calls, ['created_close', 'disconnect']);
});

test('launcher connects to the gateway CDP instead of launching a persistent profile', async () => {
  const restoredPage = {
    isClosed: () => false,
    close: async () => undefined,
    evaluate: async () => '',
  };
  const guardedPage = {
    isClosed: () => false,
    close: async () => undefined,
    evaluate: async () => 'suxios_profile_lease_guarded',
  };
  const context = {
    pages: () => [guardedPage, restoredPage],
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
