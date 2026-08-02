import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const appMain = fs.readFileSync('public/app-main.js', 'utf8');

test('deferred render promotion waits for the authenticated startup read', () => {
  assert.match(appMain, /const suxiStartupRequestCache = new Map\(\)/);
  assert.match(appMain, /const runSuxiStartupRequestOnce = \(cacheKey, task, ttlMs = 5000\) =>/);
  assert.match(
    appMain,
    /runSuxiStartupRequestOnce\(\s*`auth-info:\$\{bootstrapSession\.token\}`,\s*\(\) => request\('\/auth\/info', \{\s*requestPolicy: \{ scope: 'session', priority: 'current' \},\s*\}\),\s*\)/,
    'auth bootstrap must keep startup dedupe while using the current-session request lane',
  );
  assert.match(
    appMain,
    /refreshGlobalNotifications\(\{ silent: true, backendOnly: true, startupDedupe: true \}\)/,
  );
  assert.match(
    appMain,
    /startupDedupe: options\.startupDedupe === true/,
  );
  assert.match(
    appMain,
    /onUnmounted\(\(\) => \{\s*authSessionEpoch \+= 1;/,
    'unmounting the startup render must invalidate callbacks from its auth session',
  );
  const promotion = appMain.slice(
    appMain.indexOf('const pendingAuthBootstrapRead = () => {'),
    appMain.indexOf('requestSuxiFullRenderForPage = (page) => {'),
  );
  assert.match(promotion, /String\(key\)\.startsWith\('auth-info:'\) && entry\?\.promise/);
  assert.match(promotion, /const authBootstrapRead = pendingAuthBootstrapRead\(\);/);
  assert.match(promotion, /Promise\.resolve\(authBootstrapRead\)[\s\S]*\.finally\(\(\) => \{[\s\S]*promoteSuxiFullRender\(\);/);
  assert.match(promotion, /suxiActiveRender\.value = fullRender;/);
  assert.match(promotion, /suxiApp\?\.unmount\(\);[\s\S]*mountSuxiApp\(\);/);
});

test('manual notification refresh and write readback stay uncached', () => {
  assert.match(appMain, /const loadBackendGlobalNotifications = async \(options = \{\}\) =>/);
  assert.match(appMain, /: await requestNotifications\(\);/);
  assert.match(appMain, /const refreshGlobalNotifications = async \(options = \{\}\) =>/);
  assert.match(appMain, /await loadBackendGlobalNotifications\(\);/);
});
