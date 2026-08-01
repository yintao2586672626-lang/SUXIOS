import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';

const appMain = fs.readFileSync('public/app-main.js', 'utf8');
const systemStatic = fs.readFileSync('public/system-static.js', 'utf8');
const notificationPage = fs.readFileSync('resources/frontend/templates/fragments/15ab-page-manual-notifications.html', 'utf8');
const hotelPage = fs.readFileSync('resources/frontend/templates/fragments/18-page-hotels.html', 'utf8');
const dataConfigPage = fs.readFileSync('resources/frontend/templates/fragments/34-page-data-config.html', 'utf8');
const panel = fs.readFileSync('public/wechat-notification-static.js', 'utf8');
const notificationUi = `${notificationPage}\n${panel}`;
const controller = fs.readFileSync('app/controller/WechatNotificationOnboarding.php', 'utf8');
const adminController = fs.readFileSync('app/controller/admin/CompetitorWechatRobotController.php', 'utf8');
const deliveryService = fs.readFileSync('app/service/WechatRobotDeliveryService.php', 'utf8');
const service = fs.readFileSync('app/service/WechatNotificationBindingService.php', 'utf8');
const routes = fs.readFileSync('route/app.php', 'utf8');

test('ordinary accounts receive a permission-gated enterprise WeChat entry', () => {
  assert.match(
    systemStatic,
    /\{ name: '企业微信推送', path: 'wechat-notification'[^}]+requireSuper: false[^}]+permissions: \['can_fill_daily_report'\]/,
  );
  assert.match(appMain, /name: '运营自动化中心'[\s\S]+?sourcePath: 'wechat-notification'[\s\S]+?name: '企业微信推送'/);
  const superAdminGate = appMain.slice(
    appMain.indexOf('const SUPER_ADMIN_ONLY_PAGES'),
    appMain.indexOf('const guardSuperAdminPageAccess'),
  );
  assert.doesNotMatch(superAdminGate, /wechat-notification/);
});

test('self-service UI scopes every status, save and test request to the selected permitted hotel', () => {
  assert.match(appMain, /const wechatNotificationHotelOptions = computed\(\(\) => \{[\s\S]+?permittedHotels\.value/);
  assert.match(appMain, /\/wechat-notification\/status\?hotel_id=\$\{encodeURIComponent\(hotelId\)\}/);
  assert.match(appMain, /: '\/wechat-notification\/bind';[\s\S]+?hotel_id: Number\(hotelId\)/);
  assert.match(appMain, /: '\/wechat-notification\/test'[\s\S]+?hotel_id: Number\(hotelId\)/);
  assert.match(appMain, /const changeWechatNotificationHotel = \(\) => \{[\s\S]+?persistWechatNotificationHotelPreference\(wechatNotificationHotelId\.value\)/);
  assert.match(controller, /hasHotelPermission\(\$hotelId, 'can_fill_daily_report'\)/);
  assert.match(service, /->where\('store_id', \$hotelId\)\s*->where\('owner_user_id', \$userId\)\s*->where\('notification_scope', self::SCOPE\)/);
  assert.match(routes, /Route::group\('api\/wechat-notification'[\s\S]+?->middleware\(\\app\\middleware\\Auth::class\)/);
});

test('enterprise WeChat persists the selected hotel and otherwise defaults to 敦煌漠蓝新', () => {
  assert.match(appMain, /const DEFAULT_WECHAT_NOTIFICATION_HOTEL_NAME = '敦煌漠蓝新';/);
  assert.match(appMain, /suxios_wechat_notification_hotel_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  const ensureHotel = appMain.slice(
    appMain.indexOf('const ensureWechatNotificationHotel = () => {'),
    appMain.indexOf('const loadWechatNotificationStatus = async () => {'),
  );
  assert.match(
    ensureHotel,
    /const defaultHotel = options\.find\([\s\S]+?hotel\?\.name[\s\S]+?DEFAULT_WECHAT_NOTIFICATION_HOTEL_NAME/,
  );
  assert.ok(
    ensureHotel.indexOf('if (currentExists) {') < ensureHotel.indexOf('const defaultHotel ='),
    'an explicit current selection must remain stable',
  );
  assert.ok(
    ensureHotel.indexOf('storedHotel?.id') < ensureHotel.indexOf('defaultHotel?.id')
      && ensureHotel.indexOf('defaultHotel?.id') < ensureHotel.indexOf('preferred?.id'),
    'a saved selection should win; 敦煌漠蓝新 should remain the first-use fallback',
  );
  assert.match(appMain, /\(storedHotel \|\| defaultHotel \|\| preferredHotel \|\| options\[0\]\)\.id/);
});

test('Webhook is password-only input, is cleared after save, and only backend mask is rendered', () => {
  assert.match(panel, /type: 'password'[\s\S]+?'data-testid': 'wechat-notification-webhook'/);
  assert.match(panel, /binding\?\.webhook_masked \|\| '未绑定'/);
  assert.doesNotMatch(panel, /localStorage|sessionStorage/);
  assert.match(appMain, /finally \{\s*wechatNotificationForm\.value\.webhook = '';/);
  assert.doesNotMatch(appMain, /(?:localStorage|sessionStorage)\.setItem\([^)]*wechatNotificationForm/);
});

test('page exposes save, test-delivery and readback status evidence', () => {
  for (const marker of [
    'data-testid="wechat-notification-page"',
  ]) {
    assert.ok(notificationPage.includes(marker), `missing ${marker}`);
  }
  for (const marker of [
    "'data-testid': 'wechat-notification-status'",
    "'data-testid': 'wechat-notification-save'",
    "'data-testid': 'wechat-notification-test'",
    "'wechat-notification-mask'",
    "'wechat-notification-last-test'",
  ]) {
    assert.ok(panel.includes(marker), `missing ${marker}`);
  }
  assert.match(appMain, /last_test_status === 'failed'/);
  assert.match(appMain, /item => String\(item\?\.status \|\| ''\) === 'sent'/);
  assert.match(appMain, /response\.data\?\.binding/);
});

test('enterprise WeChat exposes one compact current-hotel channel without duplicate binding forms', () => {
  assert.match(panel, /企业微信群机器人/);
  assert.match(panel, /加密保存，不回显完整地址/);
  assert.doesNotMatch(panel, /我的通知群|通知群名称|wechat-notification-hotel|wechat-notification-name/);
  assert.doesNotMatch(panel, /当前酒店只绑定一个企业微信群机器人 Webhook|酒店由页面顶部统一选择/);
  assert.doesNotMatch(notificationPage, /data-testid="wecom-robot-management"|管理员配置|绑定共享机器人/);
  assert.match(notificationPage, /\['manual-notifications', 'wechat-notification'\]\.includes\(currentPage\)/);
  assert.match(notificationUi, /manual-notification-formal-robot/);
  assert.match(notificationPage, /1　推送通道/);
  assert.match(notificationPage, /2　自动推送/);
  assert.match(panel, /自动发送设置/);
  assert.match(panel, /当前酒店企业微信群机器人 Webhook 已绑定/);
  assert.match(notificationPage, /data-testid="manual-notification-automatic-tasks"/);
  assert.doesNotMatch(notificationPage, /data-testid="manual-notification-history"|已保存计划/);
  assert.match(notificationUi, /manual-notification-dispatch-history/);
  assert.doesNotMatch(hotelPage, /wechat-notification-page|wecom-robot-management|绑定共享机器人/);
  assert.doesNotMatch(dataConfigPage, /wecom-robot-management|绑定共享机器人/);
  assert.match(
    appMain,
    /\['wechat-notification', 'manual-notifications'\]\.includes\(newPage\)[\s\S]+?loadManualNotificationCenter\(\)[\s\S]+?loadCompetitorStores\(\)/,
  );
  assert.match(
    appMain,
    /const loadManualNotificationCenter = async \(\) => \{[\s\S]+?loadWechatNotificationStatus\(\)[\s\S]+?loadCompetitorRobots\(\)/,
  );
  const mountedStart = appMain.indexOf('onMounted(() => {');
  const mountedEnd = appMain.indexOf('\n            onUnmounted', mountedStart);
  const mountedFlow = appMain.slice(mountedStart, mountedEnd);
  assert.match(
    mountedFlow,
    /\['wechat-notification', 'manual-notifications'\]\.includes\(currentPage\.value\)[\s\S]+?nextTick\(\(\) => loadManualNotificationCenter\(\)\)/,
    'a deferred full-render remount must initialize the selected hotel and channel without another click',
  );
  assert.match(appMain, /const wechatNotificationSharedBinding = computed/);
  assert.match(appMain, /wechatNotificationSharedBinding\.value\s*\|\|\s*wechatNotificationPersonalBinding\.value/);
  assert.match(appMain, /\/admin\/competitor-wechat-robot\/update\/\$\{sharedRobotId\}/);
  assert.match(appMain, /\/admin\/competitor-wechat-robot\/test-store\/\$\{hotelId\}/);
  assert.match(appMain, /showToast\(response\.message \|\| '测试消息已发送'\);[\s\S]*manualNotificationWorkspaceTab\.value = 'plans'/);
});

test('admin shared management and default hotel delivery exclude account bindings', () => {
  assert.match(adminController, /private const ADMIN_NOTIFICATION_SCOPE = 'admin_shared'/);
  assert.match(adminController, /'owner_user_id' => null[\s\S]+?'notification_scope' => self::ADMIN_NOTIFICATION_SCOPE/);
  assert.match(adminController, /adminManagedRobotQuery\(\)[\s\S]+?whereNull\('owner_user_id'\)[\s\S]+?whereOr\('notification_scope', self::ADMIN_NOTIFICATION_SCOPE\)/);
  assert.match(deliveryService, /if \(\$onlyRobotIds !== \[\]\)[\s\S]+?whereIn\('id', \$onlyRobotIds\)[\s\S]+?whereNull\('owner_user_id'\)[\s\S]+?whereOr\('notification_scope', self::ADMIN_NOTIFICATION_SCOPE\)/);
});

test('lazy panel URL is pinned to the current component content', () => {
  const hash = crypto.createHash('sha256').update(panel).digest('hex').slice(0, 10);
  assert.match(appMain, new RegExp(`wechat-notification-static\\.js\\?v=[^'"]*-h${hash}`));
  assert.match(appMain, /__SUXI_WECHAT_NOTIFICATION_PANEL_LOAD_PROMISE__/);
  assert.match(appMain, /if \(applyWechatNotificationPanel\(\)\) \{/);
});
