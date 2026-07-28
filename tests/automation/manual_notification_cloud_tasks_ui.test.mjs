import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const shellSource = fs.readFileSync(
  'resources/frontend/templates/fragments/00-app-shell.html',
  'utf8',
);
const notificationSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15ab-page-manual-notifications.html',
  'utf8',
);
const appMainSource = fs.readFileSync('public/app-main.js', 'utf8');

test('authenticated header omits the unused language switch', () => {
  assert.doesNotMatch(shellSource, /data-testid="header-locale-switch"/);
  assert.doesNotMatch(shellSource, /header-language-options/);
  assert.match(shellSource, /data-testid="header-notification-trigger"/);
});

test('manual notification page exposes truthful cloud automation task evidence', () => {
  const taskUiSource = `${notificationSource}\n${appMainSource}`;
  assert.match(notificationSource, /data-testid="manual-notification-automatic-tasks"/);
  assert.match(appMainSource, /manualNotificationMetadata\.value\?\.automatic_tasks/);
  assert.match(taskUiSource, /固定发送/);
  assert.match(taskUiSource, /条件触发/);
  assert.match(taskUiSource, /最近核验/);
  assert.match(taskUiSource, /最近结果/);
  assert.match(taskUiSource, /目标机器人/);
  assert.match(appMainSource, /escapeManualNotificationTaskText/);
  assert.match(taskUiSource, /当前酒店没有取得已启用的自动发送任务证据/);
  assert.doesNotMatch(taskUiSource, /任务成功等于消息送达/);
});

test('notification center prefers the signed-in user hotel without a hard-coded store', () => {
  assert.match(
    appMainSource,
    /String\(item\?\.id \|\| ''\) === String\(user\.value\?\.hotel_id \|\| ''\)/,
  );
  assert.match(appMainSource, /\(preferredHotel \|\| operationHotelOptions\.value\[0\]\)\.id/);
  assert.doesNotMatch(appMainSource, /manualNotification[\s\S]{0,200}敦煌漠蓝新/);
});
