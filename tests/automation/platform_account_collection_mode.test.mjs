import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const systemStaticSource = readFileSync('public/system-static.js', 'utf8');
const html = readFileSync('public/index.html', 'utf8');
const sandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(systemStaticSource, sandbox, { filename: 'public/system-static.js' });
const classify = sandbox.window.SUXI_SYSTEM_STATIC.classifyPlatformCollectionReadiness;

test('platform collection readiness separates automatic, manual and unverified Profile states', () => {
  assert.equal(typeof classify, 'function');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, currentSessionVerified: true }), 'auto_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: false, hasManualAssist: true }), 'manual_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, currentSessionVerified: false, hasManualAssist: true }), 'waiting_login');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: false, hasManualAssist: false }), 'unbound');
  assert.equal(classify({ hotelActive: true, permissionDenied: true, accountLevel: 'ready', hasManualAssist: true }), 'permission_denied');
});

test('platform account UI names manual and automatic paths and routes them separately', () => {
  assert.match(html, /<option value="auto_ready">自动可采集<\/option>/);
  assert.match(html, /<option value="manual_ready">手动可采集<\/option>/);
  assert.match(html, /auto_ready:\s*'自动可采集'/);
  assert.match(html, /manual_ready:\s*'手动可采集'/);
  assert.match(html, /actionTarget === 'platform-manual'/);
  assert.match(html, /openHotelManualFetch/);
  assert.match(html, /readinessCode === 'waiting_login' \? '验证 Profile 登录'/);
  assert.match(html, /仅可手动补采；未验证 Profile 当前会话，不进入自动采集/);
  assert.doesNotMatch(html, /label: '携程可采门店'/);
  assert.doesNotMatch(html, /label: '美团可采门店'/);
});

test('multi-hotel account center loads all permitted data sources instead of inheriting the selected hotel', () => {
  assert.match(
    html,
    /request\('\/online-data\/data-sources', \{ withBusinessContext: false \}\)/,
  );
});

test('hotel card next actions route collection work separately from explicit authorization login', () => {
  assert.match(
    html,
    /const openHotelPlatformCardLogin = async \(hotel, account = \{\}\) => \{[\s\S]*openHotelPlatformAccountAction\(hotel, account, \{ forceLogin: true \}\)/,
    'the explicit authorization button must force a Profile login',
  );
  assert.match(
    html,
    /const openHotelPlatformAccountAction = async \(hotel, account, \{ forceLogin = false \} = \{\}\) =>/,
    'the generic next-action handler must distinguish navigation from login',
  );
  assert.match(
    html,
    /!forceLogin && \(target === 'sync-logs' \|\| actionKey === 'open_sync_logs' \|\| actionKey === 'review_partial_capture'\)[\s\S]*openHotelSyncLogs\(hotel, platform\)/,
    'a failed collection next action must open its sync logs instead of launching login',
  );
  assert.match(
    html,
    /!forceLogin && \(target === 'platform-auto' \|\| actionKey === 'run_trial_capture'\)[\s\S]*openHotelPlatformConsole\(hotel, platform\)/,
    'an automatic collection next action must open the collection console instead of launching login',
  );
  assert.match(
    html,
    /!forceLogin && target !== 'profile-login' && actionKey !== 'login_platform_profile'[\s\S]*openHotelModal\(hotel, \{ expandOta: true \}\)/,
    'a normal or configuration next action must open hotel details instead of launching login',
  );
  assert.match(
    html,
    /openHotelPlatformAccountAction\(hotelFormAccountHotel\(\), account, \{ forceLogin: true \}\)/,
    'the modal authorization button must remain an explicit Profile login action',
  );
});
