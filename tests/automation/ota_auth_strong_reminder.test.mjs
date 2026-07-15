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
    updated_at: '2026-07-14 12:00:00',
  });

  assert.equal(helpers.isStrongOtaReminder(normalized), true);
  assert.equal(normalized.hotel_id, 7);
  assert.equal(normalized.platform, 'ctrip');
  assert.equal(normalized.reason_code, 'login_expired');
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
  const controller = read('app/controller/SystemNotificationController.php');
  const service = read('app/service/OtaFailureNotificationService.php');
  const profileLogin = read('app/command/PlatformProfileLogin.php');

  assert.match(appMain, /const strongOtaReminderItems = computed/);
  assert.match(appMain, /const strongOtaReminderMinimumVisibleSeconds = 3/);
  assert.match(appMain, /const strongOtaReminderCanDefer = computed/);
  assert.match(appMain, /if \(!strongOtaReminderCanDefer\.value\) return/);
  assert.match(appMain, /filter\(item => item\.backend_id && !item\.requires_resolution\)/);
  assert.match(appMain, /scheduleInitialBackendNotificationRefresh = \(delayMs = 800\)/);
  assert.match(appMain, /strongOtaReminderOpen\.value = true/);
  assert.match(appMain, /await openHotelPlatformCardLogin\(hotel/);
  assert.match(appMain, /mergeBackendNotificationRows\(\s*res\.data\?\.strong_reminders,\s*res\.data\?\.list\s*\)/);
  assert.match(appMain, /taskForView\.status === 'logged_in'[\s\S]*?await refreshGlobalNotifications\(\{ silent: true, backendOnly: true \}\)/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-banner"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-modal"/);
  assert.match(shell, /data-testid="ota-auth-strong-reminder-defer"/);
  assert.match(shell, /还需 \{\{ strongOtaReminderDeferSecondsRemaining \}\} 秒/);
  assert.match(shell, /重新登录并验证.*可立即操作/);
  assert.match(shell, /下次登录仍会弹出/);
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
