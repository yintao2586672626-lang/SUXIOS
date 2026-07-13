import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const systemStaticSource = readFileSync('public/system-static.js', 'utf8');
const html = readFrontendContractSource();
const sandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(systemStaticSource, sandbox, { filename: 'public/system-static.js' });
const classify = sandbox.window.SUXI_SYSTEM_STATIC.classifyPlatformCollectionReadiness;
const buildHotelPlatformBindingRows = sandbox.window.SUXI_SYSTEM_STATIC.buildHotelPlatformBindingRows;

const accountRowHelpers = {
  hasPlatformHotelMismatch: () => false,
  isPlatformSourceLoginExpired: () => false,
  platformCaptureStatusCode: () => 'success',
  platformAccountReason: () => ({ text: '', className: '' }),
  formatHotelBindingDate: value => String(value || '-'),
  platformLastSuccessText: () => '-',
  platformAccountStatusText: () => '待验证',
  platformAccountStatusClass: () => '',
  platformCaptureStatusText: () => '最近采集成功',
  platformCaptureStatusClass: () => '',
};

test('platform collection readiness separates automatic, manual and unverified Profile states', () => {
  assert.equal(typeof classify, 'function');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, currentSessionVerified: true }), 'auto_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, profileReusable: true, currentSessionVerified: false }), 'auto_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, profileReusable: true, renewalWarning: true }), 'auto_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: false, hasManualAssist: true }), 'manual_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: true, currentSessionVerified: false, hasManualAssist: true }), 'auto_ready');
  assert.equal(classify({ hotelActive: true, accountLevel: 'ready', hasProfile: false, hasManualAssist: false }), 'unbound');
  assert.equal(classify({ hotelActive: true, permissionDenied: true, accountLevel: 'ready', hasManualAssist: true }), 'permission_denied');
});

test('Profile login never submits non-Profile source ids for either OTA platform', () => {
  const rows = buildHotelPlatformBindingRows({
    hotel: { id: 121, name: '西安天诚', status: 1 },
    ctripConfig: { hotel_id: 121, has_cookies: true },
    meituanConfig: { hotel_id: 121, store_id: 'mt-121', poi_id: 'mt-121', partner_id: 'partner-121' },
    ctripProfile: null,
    meituanProfile: null,
    ctripSource: {
      id: 63,
      system_hotel_id: 121,
      platform: 'ctrip',
      ingestion_method: 'historical_backfill',
      config: {},
    },
    meituanSource: {
      id: 64,
      system_hotel_id: 121,
      platform: 'meituan',
      ingestion_method: 'manual_cookie_api',
      config: {},
    },
    helpers: accountRowHelpers,
  });
  const ctrip = rows.find(row => row.platform === 'ctrip');
  const meituan = rows.find(row => row.platform === 'meituan');

  assert.equal(ctrip.profileSource, null);
  assert.equal(ctrip.loginItem.data_source_id, undefined);
  assert.equal(ctrip.loginItem.profile_key, 'system_121');
  assert.equal(meituan.profileSource, null);
  assert.equal(meituan.loginItem.data_source_id, undefined);
  assert.equal(meituan.loginItem.profile_key, 'mt-121');
});

test('Profile login preserves real Profile source ids for both OTA platforms', () => {
  const ctripProfile = {
    id: 163,
    system_hotel_id: 121,
    platform: 'ctrip',
    ingestion_method: 'browser_profile',
    config: { profile_id: 'ctrip-121' },
  };
  const meituanProfile = {
    id: 164,
    system_hotel_id: 121,
    platform: 'meituan',
    ingestion_method: 'browser_profile',
    config: { store_id: 'mt-121', poi_id: 'mt-121' },
  };
  const rows = buildHotelPlatformBindingRows({
    hotel: { id: 121, name: '西安天诚', status: 1 },
    ctripProfile,
    meituanProfile,
    helpers: accountRowHelpers,
  });
  const ctrip = rows.find(row => row.platform === 'ctrip');
  const meituan = rows.find(row => row.platform === 'meituan');

  assert.equal(ctrip.loginItem.data_source_id, 163);
  assert.equal(ctrip.loginItem.profile_key, 'ctrip-121');
  assert.equal(meituan.loginItem.data_source_id, 164);
  assert.equal(meituan.loginItem.profile_key, 'mt-121');
});

test('blocking capture reason overrides non-blocking Profile guidance', () => {
  const failedHelpers = {
    ...accountRowHelpers,
    platformCaptureStatusCode: () => 'failed',
    platformAccountReason: () => ({ text: '最近采集失败：平台返回接口错误', className: 'text-red-700' }),
    platformCaptureStatusText: () => '最近采集失败',
  };
  const [ctrip] = buildHotelPlatformBindingRows({
    hotel: { id: 121, name: '西安天诚', status: 1 },
    ctripProfile: {
      id: 163,
      system_hotel_id: 121,
      platform: 'ctrip',
      ingestion_method: 'browser_profile',
      config: { profile_id: 'ctrip-121', ctrip_hotel_id: 'ctrip-hotel-121' },
    },
    helpers: failedHelpers,
  });

  assert.equal(ctrip.captureStatusCode, 'failed');
  assert.equal(ctrip.verificationReasonText, '未检测当天登录态，但不阻塞采集；以平台实际采集结果为准。');
  assert.equal(ctrip.blockingReasonText, '最近采集失败：平台返回接口错误');
  assert.equal(ctrip.reasonText, '最近采集失败：平台返回接口错误');
});

test('platform account UI names manual and automatic paths and routes them separately', () => {
  assert.match(html, /<option value="auto_ready">自动可采集<\/option>/);
  assert.match(html, /<option value="manual_ready">手动可采集<\/option>/);
  assert.match(html, /auto_ready:\s*'自动可采集'/);
  assert.match(html, /renewal_warning:\s*'自动可采集·建议续登'/);
  assert.match(html, /manual_ready:\s*'手动可采集'/);
  assert.match(html, /channelCount\('auto_ready', 'renewal_warning'\)/);
  assert.match(html, /不要求当天登录证明/);
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
  const actionStart = html.indexOf('const openHotelPlatformAccountAction = async');
  const actionEnd = html.indexOf('const unbindHotelPlatformAccount = async', actionStart);
  const actionSource = html.slice(actionStart, actionEnd);
  assert.match(
    actionSource,
    /openHotelModal\(hotel, \{ expandOta: true \}\);\s*showToast\('请先在已展开的美团配置/,
    'an unbound Meituan authorization entry must open the OTA configuration instead of ending at a warning',
  );
});
