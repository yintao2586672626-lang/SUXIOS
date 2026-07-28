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
  assert.match(appMain, /request\('\/wechat-notification\/bind'[\s\S]+?hotel_id: Number\(hotelId\)/);
  assert.match(appMain, /request\('\/wechat-notification\/test'[\s\S]+?hotel_id: Number\(hotelId\)/);
  assert.match(appMain, /const changeWechatNotificationHotel = \(\) => \{\s*wechatNotificationForm\.value\.name = DEFAULT_WECHAT_NOTIFICATION_NAME;\s*wechatNotificationForm\.value\.webhook = ''/);
  assert.match(controller, /hasHotelPermission\(\$hotelId, 'can_fill_daily_report'\)/);
  assert.match(service, /->where\('store_id', \$hotelId\)\s*->where\('owner_user_id', \$userId\)\s*->where\('notification_scope', self::SCOPE\)/);
  assert.match(routes, /Route::group\('api\/wechat-notification'[\s\S]+?->middleware\(\\app\\middleware\\Auth::class\)/);
});

test('Webhook is password-only input, is cleared after save, and only backend mask is rendered', () => {
  assert.match(panel, /type: 'password'[\s\S]+?'data-testid': 'wechat-notification-webhook'/);
  assert.match(panel, /binding\?\.webhook_masked \|\| '未保存'/);
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
  assert.match(appMain, /last_test_status === 'sent'/);
  assert.match(appMain, /last_test_status === 'failed'/);
  assert.match(appMain, /response\.data\?\.binding/);
});

test('enterprise WeChat uses one page with separate account and admin shared scopes', () => {
  assert.match(panel, /账号级配置 · 当前账户与当前门店/);
  assert.match(panel, /我的通知群/);
  assert.match(notificationPage, /data-testid="wecom-robot-management"/);
  assert.match(notificationPage, /管理员配置/);
  assert.match(notificationPage, /门店共享机器人/);
  assert.match(notificationPage, /\['manual-notifications', 'wechat-notification'\]\.includes\(currentPage\)/);
  assert.match(notificationUi, /manual-notification-formal-robot/);
  assert.match(notificationPage, /个人通知群与管理员共享机器人保持分开记录/);
  assert.match(panel, /自动发送设置/);
  assert.match(notificationPage, /data-testid="manual-notification-automatic-tasks"/);
  assert.match(notificationPage, /data-testid="manual-notification-history"/);
  assert.match(notificationPage, /data-testid="manual-notification-dispatch-history"/);
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
});
