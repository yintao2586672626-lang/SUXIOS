import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(path, 'utf8');

test('header utility cluster exposes one-click language, truthful notifications, and Beijing time', () => {
  const shell = read('resources/frontend/templates/fragments/00-app-shell.html');
  const appMain = read('public/app-main.js');
  const css = read('public/style.css');

  assert.match(shell, /data-testid="header-locale-switch"[\s\S]*@click="switchLocale\(option\.value\)"/);
  assert.match(shell, /data-testid="header-notification-trigger"[\s\S]*:aria-expanded="globalNotificationOpen \? 'true' : 'false'"/);
  assert.match(shell, /id="global-notification-panel"[\s\S]*aria-label="关闭通知中心"/);
  assert.match(shell, /显示最近 \{\{ globalNotificationVisibleItems\.length \}\} 条 · 共 \{\{ globalNotificationTotalCount \}\} 条/);
  assert.match(shell, /@click="markAllGlobalNotificationsRead"[^>]*:disabled="globalNotificationLoading"[^>]*>全部已读<\/button>/);
  assert.doesNotMatch(shell, />当前列表已读<\/button>/);
  assert.match(shell, /@click="clearGlobalNotifications"[\s\S]*清空全部普通提醒/);
  assert.match(shell, /data-testid="header-time-panel"[\s\S]*\{\{ currentClockText \}\}[\s\S]*\{\{ currentDateText \}\}/);

  assert.match(appMain, /const appTimeZone = 'Asia\/Shanghai'/);
  assert.match(appMain, /globalNotificationBackendTotalCount\.value = Math\.max\(0, Number\(res\.data\?\.total \|\| 0\)\)/);
  assert.match(appMain, /globalNotificationBackendUnreadCount\.value = Math\.max\(0, Number\(res\.data\?\.unread_count \|\| 0\)\)/);
  const readAllStart = appMain.indexOf('const markAllGlobalNotificationsRead = async () => {');
  const readAllEnd = appMain.indexOf('\n            const clearGlobalNotifications', readAllStart);
  assert.notEqual(readAllStart, -1);
  assert.notEqual(readAllEnd, -1);
  const readAllSource = appMain.slice(readAllStart, readAllEnd);
  assert.match(readAllSource, /if \(globalNotificationLoading\.value\) return/);
  assert.match(readAllSource, /request\('\/notifications\/read-all', \{[\s\S]*method: 'POST'/);
  assert.match(readAllSource, /globalNotificationLocalItems\.value\.map\(item => item\.id\)/);
  assert.match(readAllSource, /saveGlobalNotificationIds\(globalNotificationReadStorageKey, globalNotificationReadIds\.value\)/);
  assert.match(readAllSource, /await loadBackendGlobalNotifications\(\)/);
  assert.match(readAllSource, /showToast\(`全部已读失败：\$\{message\}`, 'error'\)/);
  assert.match(readAllSource, /finally \{[\s\S]*globalNotificationLoading\.value = false/);
  assert.doesNotMatch(readAllSource, /globalNotificationVisibleItems\.value/);
  assert.doesNotMatch(readAllSource, /request\('\/notifications\/read'/);
  const clearStart = appMain.indexOf('const clearGlobalNotifications = async () => {');
  const clearEnd = appMain.indexOf('\n            const refreshGlobalNotifications', clearStart);
  assert.notEqual(clearStart, -1);
  assert.notEqual(clearEnd, -1);
  const clearSource = appMain.slice(clearStart, clearEnd);
  assert.match(clearSource, /window\.confirm\('确认清空当前账号的全部普通提醒/);
  assert.match(clearSource, /globalNotificationBackendTotalCount\.value > protectedBackendItems\.length/);
  assert.match(clearSource, /JSON\.stringify\(\{ ids: \[\] \}\)/);
  assert.match(clearSource, /await loadBackendGlobalNotifications\(\)/);
  assert.doesNotMatch(clearSource, /globalNotificationVisibleItems\.value/);
  assert.match(appMain, /const globalNotificationTotalCount = computed/);
  assert.match(appMain, /currentTimeTimer = setInterval\(updateCurrentTime, 1000\)/);
  assert.match(appMain, /clearInterval\(currentTimeTimer\)/);

  assert.match(css, /\.header-utility-cluster/);
  assert.match(css, /\.header-language-option\.is-active/);
  assert.match(css, /\.header-notification-button\.is-open/);
  assert.match(css, /@media \(max-width: 767px\)[\s\S]*\.header-notification-panel[\s\S]*position: fixed/);
  assert.match(css, /Header utility visual repair/);
  assert.match(css, /grid-template-columns: 28px minmax\(58px, 1fr\) auto/);
  assert.match(css, /\.header-notification-count[\s\S]*min-width: 30px/);
});
