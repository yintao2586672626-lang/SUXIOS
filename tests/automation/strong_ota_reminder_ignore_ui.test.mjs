import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const shell = readFileSync('resources/frontend/templates/fragments/00-app-shell.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');

test('strong OTA reminder can be ignored for the current login without marking it resolved', () => {
  assert.match(shell, /data-testid="ota-auth-strong-reminder-ignore"[\s\S]*忽略/);
  assert.match(shell, /仅在本次登录中隐藏顶部提示，不改变 Cookie 状态/);
  assert.match(shell, /v-if="strongOtaReminderBannerCount"/);
  assert.match(appMain, /suxios_ota_auth_reminder_banner_ignored_v1/);
  assert.match(appMain, /sessionStorage\.setItem\(strongOtaReminderBannerIgnoredStorageKey/);
  assert.match(appMain, /const ignoreStrongOtaReminderBanner = \(\) => \{/);
  assert.match(appMain, /const strongOtaReminderBannerIdentity = \(item = \{\}\) => \{/);
  assert.match(appMain, /return `backend:\$\{backendId\}`/);
  assert.match(appMain, /strongOtaReminderBannerItems[\s\S]*strongOtaReminderItems/);
  assert.match(appMain, /resetHotelScopedClientState\(\{ preserveStrongOtaReminderSession \}\)/);
  assert.match(appMain, /normalizedNextToken === String\(token\.value \|\| ''\)/);
  assert.match(appMain, /watch\(isLoggedIn, \(loggedIn, wasLoggedIn\) => \{\s*if \(wasLoggedIn && !loggedIn\)/);
  assert.doesNotMatch(appMain, /watch\(isLoggedIn,[\s\S]{0,180}\{ immediate: true \}/);
  assert.doesNotMatch(
    appMain.slice(
      appMain.indexOf('const ignoreStrongOtaReminderBanner'),
      appMain.indexOf('const deferStrongOtaReminder', appMain.indexOf('const ignoreStrongOtaReminderBanner'))
    ),
    /mark.*resolved|delete|clearGlobalNotifications/i
  );
});
