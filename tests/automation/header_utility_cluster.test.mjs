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
  assert.match(shell, /data-testid="header-time-panel"[\s\S]*\{\{ currentClockText \}\}[\s\S]*\{\{ currentDateText \}\}/);

  assert.match(appMain, /const appTimeZone = 'Asia\/Shanghai'/);
  assert.match(appMain, /globalNotificationBackendTotalCount\.value = Math\.max\(0, Number\(res\.data\?\.total \|\| 0\)\)/);
  assert.match(appMain, /globalNotificationBackendUnreadCount\.value = Math\.max\(0, Number\(res\.data\?\.unread_count \|\| 0\)\)/);
  assert.match(appMain, /const globalNotificationTotalCount = computed/);
  assert.match(appMain, /currentTimeTimer = setInterval\(updateCurrentTime, 1000\)/);
  assert.match(appMain, /clearInterval\(currentTimeTimer\)/);

  assert.match(css, /\.header-utility-cluster/);
  assert.match(css, /\.header-language-option\.is-active/);
  assert.match(css, /\.header-notification-button\.is-open/);
  assert.match(css, /@media \(max-width: 767px\)[\s\S]*\.header-notification-panel[\s\S]*position: fixed/);
});
