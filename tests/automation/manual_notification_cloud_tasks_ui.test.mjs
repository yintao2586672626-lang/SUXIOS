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
  assert.match(notificationSource, /@click="handleManualNotificationAutomaticTaskClick"/);
  assert.match(appMainSource, /manualNotificationMetadata\.value\?\.automatic_tasks/);
  assert.match(appMainSource, /manualNotificationThreeSourceSummary/);
  assert.match(appMainSource, /manualNotificationOperatingDailyPlans/);
  assert.match(appMainSource, /data-manual-notification-edit-id/);
  assert.match(appMainSource, /handleManualNotificationAutomaticTaskClick/);
  assert.match(appMainSource, /三源推送接入/);
  assert.match(appMainSource, /统一推送配置/);
  assert.match(appMainSource, /已接入统一推送/);
  for (const source of ['ctrip', 'meituan', 'dingdandao_pms']) {
    assert.match(appMainSource, new RegExp(`key: '${source}'`));
  }
  assert.match(
    appMainSource,
    /data-testid="manual-notification-source-\$\{source\.key\}"/,
  );
  assert.match(appMainSource, /系统运行保障/);
  assert.match(appMainSource, /manual-notification-system-safeguards/);
  assert.match(taskUiSource, /固定发送/);
  assert.match(taskUiSource, /条件触发/);
  assert.match(taskUiSource, /历史核验/);
  assert.match(taskUiSource, /核验记录已过期/);
  assert.match(taskUiSource, /最近结果/);
  assert.match(taskUiSource, /目标机器人/);
  assert.match(appMainSource, /escapeManualNotificationTaskText/);
  assert.match(taskUiSource, /尚未保存统一推送配置/);
  assert.doesNotMatch(taskUiSource, /任务成功等于消息送达/);
});

test('three-source integration and automatic scheduling remain separate states', () => {
  const taskUiSource = `${notificationSource}\n${appMainSource}`;
  assert.match(appMainSource, /三源已接入 · 自动发送未启用/);
  assert.match(appMainSource, /启用计划前不会自动发送/);
  assert.match(appMainSource, /item\?\.enabled === true/);
  assert.match(appMainSource, /schedule_status \|\| ''\) === 'schedule_enabled'/);
  assert.match(notificationSource, /manualNotificationSchedulerDisplay\.label/);
  assert.match(notificationSource, /manualNotificationSchedulerDisplay\.note/);
  assert.match(taskUiSource, /不代表渠道页面当前实时/);
  assert.doesNotMatch(taskUiSource, /三源已接入 · 自动发送已启用/);
});

test('the unified current-hotel channel precedes automatic task evidence', () => {
  const channelIndex = notificationSource.indexOf(
    'data-testid="wechat-notification-page"',
  );
  const automaticTaskIndex = notificationSource.indexOf(
    'data-testid="manual-notification-automatic-tasks"',
  );

  assert.ok(channelIndex >= 0);
  assert.ok(automaticTaskIndex >= 0);
  assert.ok(automaticTaskIndex > channelIndex);
  assert.doesNotMatch(notificationSource, /data-testid="wecom-robot-management"/);
});

test('notification center remembers the selection and otherwise defaults to 敦煌漠蓝新', () => {
  assert.match(appMainSource, /const DEFAULT_WECHAT_NOTIFICATION_HOTEL_NAME = '敦煌漠蓝新'/);
  assert.match(appMainSource, /readWechatNotificationHotelPreference/);
  assert.match(
    appMainSource,
    /String\(item\?\.id \|\| ''\) === String\(user\.value\?\.hotel_id \|\| ''\)/,
  );
  assert.match(appMainSource, /\(storedHotel \|\| defaultHotel \|\| preferredHotel \|\| options\[0\]\)\.id/);
  assert.match(appMainSource, /persistWechatNotificationHotelPreference\(context\.hotelId\)/);
});
