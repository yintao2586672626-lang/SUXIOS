import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildOtaPersistentContextOptions,
  resolveOtaBrowserBinaryPath,
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
