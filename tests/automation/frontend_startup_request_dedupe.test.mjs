import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const appMain = fs.readFileSync('public/app-main.js', 'utf8');

test('deferred render remount shares only authenticated startup reads', () => {
  assert.match(appMain, /const suxiStartupRequestCache = new Map\(\)/);
  assert.match(appMain, /const runSuxiStartupRequestOnce = \(cacheKey, task, ttlMs = 5000\) =>/);
  assert.match(
    appMain,
    /runSuxiStartupRequestOnce\(\s*`auth-info:\$\{bootstrapSession\.token\}`,\s*\(\) => request\('\/auth\/info'\)/,
  );
  assert.match(
    appMain,
    /refreshGlobalNotifications\(\{ silent: true, backendOnly: true, startupDedupe: true \}\)/,
  );
  assert.match(
    appMain,
    /startupDedupe: options\.startupDedupe === true/,
  );
});

test('manual notification refresh and write readback stay uncached', () => {
  assert.match(appMain, /const loadBackendGlobalNotifications = async \(options = \{\}\) =>/);
  assert.match(appMain, /: await requestNotifications\(\);/);
  assert.match(appMain, /const refreshGlobalNotifications = async \(options = \{\}\) =>/);
  assert.match(appMain, /await loadBackendGlobalNotifications\(\);/);
});
