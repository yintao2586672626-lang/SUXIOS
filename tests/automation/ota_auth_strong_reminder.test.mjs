import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relativePath => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('direct OTA submitter auth failure becomes a strong reminder', () => {
  const context = { window: {} };
  vm.runInNewContext(read('public/notification-static.js'), context, {
    filename: 'public/notification-static.js',
  });
  const helpers = context.window.SUXI_NOTIFICATION_STATIC;
  const normalized = helpers.normalizeBackendGlobalNotification({
    id: 91,
    hotel_id: 7,
    hotel_name: '测试门店',
    platform: 'ctrip',
    category: 'ota_auth_required',
    requires_resolution: true,
    reminder_level: 'strong',
    is_direct_recipient: true,
    reason_code: 'login_expired',
    authorization_source_label: '携程前台 Profile',
    authorization_source_type: 'profile',
    authorization_source_state: 'exact',
    authorization_source_note: '本次失败已记录到该授权来源。',
    data_source_id: 29,
    updated_at: '2026-07-14 12:00:00',
  });

  assert.equal(helpers.isStrongOtaReminder(normalized), true);
  assert.equal(normalized.hotel_id, 7);
  assert.equal(normalized.platform, 'ctrip');
  assert.equal(normalized.reason_code, 'login_expired');
  assert.equal(normalized.authorization_source_label, '携程前台 Profile');
  assert.equal(normalized.authorization_source_type, 'profile');
  assert.equal(normalized.authorization_source_state, 'exact');
  assert.equal(normalized.authorization_source_note, '本次失败已记录到该授权来源。');
  assert.equal(normalized.data_source_id, 29);
  assert.match(normalized.reminder_key, /^ota-auth-reminder-91:/);

  assert.equal(helpers.isStrongOtaReminder({ ...normalized, is_direct_recipient: false }), false);
  assert.equal(helpers.isStrongOtaReminder({ ...normalized, requires_resolution: false }), false);
});

test('all direct strong reminders survive the paged notification merge', () => {
  const context = { window: {} };
  vm.runInNewContext(read('public/notification-static.js'), context, {
    filename: 'public/notification-static.js',
  });
  const helpers = context.window.SUXI_NOTIFICATION_STATIC;
  const strongRows = Array.from({ length: 25 }, (_, index) => ({ id: index + 1 }));
  const firstPageRows = Array.from({ length: 20 }, (_, index) => ({ id: index + 1 }));
  const merged = helpers.mergeBackendNotificationRows(strongRows, firstPageRows);

  assert.equal(merged.length, 25);
  assert.deepEqual(Array.from(merged, row => row.id), Array.from({ length: 25 }, (_, index) => index + 1));
});

test('strong reminder is persistent, opens after login, and cannot be bulk-cleared', () => {
  const appMain = read('public/app-main.js');
  const shell = read('resources/frontend/templates/fragments/00-app-shell.html');
  const style = read('public/style.css');
  const controller = read('app/controller/SystemNotificationController.php');
  const service = read('app/service/OtaFailureNotificationService.php');
  const profileLogin = read('app/command/PlatformProfileLogin.php');

  assert.match(appMain, /const strongOtaReminderItems = computed/);
  assert.match(appMain, /const strongOtaReminderAutoDismissSeconds = 5/);
  assert.match(appMain, /const strongOtaReminderAutoDismissSecondsRemaining = ref\(0\)/);
  assert.match(appMain, /const strongOtaReminderSnoozeHours = 24/);
  assert.match(appMain, /const startStrongOtaReminderAutoDismissCountdown = \(\) =>/);
  assert.match(appMain, /strongOtaReminderAutoDismissSecondsRemaining\.value <= 0[\s\S]*?deferStrongOtaReminder\(\)/);
  assert.match(appMain, /const restartStrongOtaReminderAutoDismissCountdown = \(\) =>/);
  assert.match(appMain, /watch\(isLoggedIn,[\s\S]*?resetStrongOtaReminderSessionState\(\)/);
  assert.doesNotMatch(appMain, /strongOtaReminderMinimumVisibleSeconds/);
  assert.doesNotMatch(appMain, /strongOtaReminderCanDefer/);
  assert.match(appMain, /suxios_ota_auth_reminder_session_deferred_v1/);
  assert.match(appMain, /suxios_ota_auth_reminder_snoozed_until_v1/);
  assert.match(appMain, /const strongOtaReminderGlobalSnoozeKey = '__all__'/);
  assert.match(appMain, /normalized\[strongOtaReminderGlobalSnoozeKey\] = Math\.max/);
  assert.match(appMain, /const snoozeStrongOtaReminder24Hours = \(\) =>/);
  assert.match(appMain, /\[strongOtaReminderGlobalSnoozeKey\]: expiresAt/);
  assert.match(appMain, /Number\(strongOtaReminderSnoozedUntil\.value\[strongOtaReminderGlobalSnoozeKey\] \|\| 0\) > now/);
  assert.match(appMain, /saveStrongOtaReminderSessionKeys\(strongOtaReminderDeferredKeys\.value\)/);
  assert.match(appMain, /saveStrongOtaReminderSnoozedUntil\(strongOtaReminderSnoozedUntil\.value\)/);
  assert.match(appMain, /filter\(item => item\.backend_id && !item\.requires_resolution\)/);
  assert.match(appMain, /scheduleInitialBackendNotificationRefresh = \(delayMs = 800\)/);
  assert.match(appMain, /strongOtaReminderOpen\.value = true/);
  assert.match(appMain, /await openHotelPlatformCardLogin\(hotel/);
  assert.match(appMain, /mergeBackendNotificationRows\(\s*res\.data\?\.strong_reminders,\s*res\.data\?\.list\s*\)/);
  assert.match(appMain, /taskForView\.status === 'logged_in'[\s\S]*?await refreshGlobalNotifications\(\{ silent: true, backendOnly: true \}\)/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-banner"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-modal"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-close"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-list"/);
  assert.match(shell, /@click\.self="deferStrongOtaReminder"/);
  assert.match(shell, /@pointerdown="restartStrongOtaReminderAutoDismissCountdown"/);
  assert.match(shell, /@keydown="restartStrongOtaReminderAutoDismissCountdown"/);
  assert.match(shell, /@wheel\.passive="restartStrongOtaReminderAutoDismissCountdown"/);
  assert.match(shell, /style="max-height: calc\(100vh - 2rem\);"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-footer"/);
  assert.match(shell, /class="ota-auth-reminder-panel/);
  assert.match(shell, /class="ota-auth-reminder-grid/);
  assert.match(shell, /class="ota-auth-reminder-card/);
  assert.match(shell, /data-testid="ota-auth-auto-dismiss-countdown"/);
  assert.match(shell, /未操作将在 \{\{ strongOtaReminderAutoDismissSecondsRemaining \}\} 秒后自动关闭，顶部提醒仍会保留。/);
  assert.doesNotMatch(shell, /:disabled="!strongOtaReminderCanDefer"/);
  assert.doesNotMatch(shell, /data-testid="ota-auth-authorization-source"/);
  assert.doesNotMatch(shell, /候选授权来源/);
  assert.doesNotMatch(shell, /查看详情/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-snooze-24h"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-defer"/);
  assert.match(shell, /OTA Cookie 状态提醒/);
  assert.match(shell, /部分账号 Cookie 可能已失效/);
  assert.match(shell, /可能暂时无法获取 OTA 数据/);
  assert.match(shell, /Cookie 可能已失效/);
  assert.match(shell, /更新 Cookie/);
  assert.doesNotMatch(shell, /需要补全浏览器 Profile 或 Cookie\/API 授权/);
  assert.match(shell, /小时内不再弹窗/);
  assert.match(shell, /下次登录再提醒/);
  assert.doesNotMatch(style, /\.ota-auth-reminder-source/);
  assert.match(style, /@media \(max-width: 767px\)[\s\S]*?\.ota-auth-reminder-grid/);
  assert.match(controller, /登录失效强提醒将在重新登录验证成功后自动解除/);
  assert.match(controller, /'category'\] \?\? ''\) === 'ota_auth_required'/);
  assert.match(controller, /'strong_reminders' => array_map/);
  assert.match(service, /verified_same_platform_session_or_capture/);
  assert.match(service, /where\('category', 'ota_auth_required'\)/);
  assert.match(profileLogin, /new OtaFailureNotificationService\(\)/);
  assert.match(profileLogin, /'auth_verified' => true/);
  const resolutionIndex = profileLogin.indexOf('(new OtaFailureNotificationService())->recordCollectionOutcome');
  const publishedSuccess = profileLogin.match(/\$this->writeTask\(\$taskId,\s*\[\s*'status' => 'logged_in'/);
  assert.ok(resolutionIndex >= 0, 'verified login must resolve the strong reminder');
  assert.ok(publishedSuccess?.index > resolutionIndex, 'strong reminder must resolve before logged_in is published');
});
