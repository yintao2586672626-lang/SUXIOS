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
  assert.match(appMainSource, /manualNotificationMetadata\.value\?\.content_sections/);
  assert.match(appMainSource, /String\(section\)\.startsWith\('pms_'\)/);
  assert.match(appMainSource, /\/\^\(ctrip\|qunar\)_\//);
  assert.match(appMainSource, /data-manual-notification-edit-id/);
  assert.match(appMainSource, /handleManualNotificationAutomaticTaskClick/);
  assert.match(appMainSource, /三源推送回执/);
  assert.match(appMainSource, /推送计划/);
  for (const source of ['ctrip', 'meituan', 'dingdandao_pms']) {
    assert.match(appMainSource, new RegExp(`key: '${source}'`));
  }
  assert.match(
    appMainSource,
    /data-testid="manual-notification-source-\$\{source\.key\}"/,
  );
  assert.match(appMainSource, /系统保障/);
  assert.match(appMainSource, /manual-notification-system-safeguards/);
  assert.match(appMainSource, /task\?\.source_scope_label/);
  assert.match(taskUiSource, /固定发送/);
  assert.match(taskUiSource, /条件触发/);
  assert.match(taskUiSource, /历史核验/);
  assert.match(taskUiSource, /核验记录已过期/);
  assert.match(taskUiSource, /最近 \$\{escapeManualNotificationTaskText\(task\?\.last_result\)\}/);
  assert.match(appMainSource, /escapeManualNotificationTaskText/);
  assert.match(taskUiSource, /暂无计划，可在下方新建/);
  assert.doesNotMatch(taskUiSource, /目标机器人：/);
  assert.doesNotMatch(taskUiSource, /任务成功等于消息送达/);
});

test('three-source integration and automatic scheduling remain separate states', () => {
  assert.match(appMainSource, /三源已配置 · 未启用/);
  assert.match(appMainSource, /启用计划前不会自动发送/);
  assert.match(appMainSource, /item\?\.enabled === true/);
  assert.match(appMainSource, /schedule_status \|\| ''\) === 'schedule_enabled'/);
  assert.match(appMainSource, /const manualNotificationPlanUsesForbiddenLoop/);
  assert.match(appMainSource, /const manualNotificationPlanIsActive/);
  assert.match(appMainSource, /!manualNotificationPlanUsesForbiddenLoop\(item\)/);
  assert.match(appMainSource, /schedule_effective_enabled !== false/);
  assert.match(appMainSource, /旧循环计划已阻断/);
  assert.match(appMainSource, /String\(task\?\.trigger_type \|\| ''\) === 'manual_test'/);
  assert.match(appMainSource, /无定时运行/);
  assert.match(appMainSource, /计划已阻断/);
  assert.doesNotMatch(appMainSource, /按周期执行/);
  assert.match(notificationSource, /manualNotificationSchedulerDisplay\.label/);
  assert.match(notificationSource, /manualNotificationSchedulerDisplay\.note/);
  assert.match(notificationSource, /:title="manualNotificationSchedulerDisplay\.note"/);
  assert.doesNotMatch(notificationSource, /manual-notification-scheduler-note/);
  assert.doesNotMatch(appMainSource, /三源已接入 · 自动发送已启用/);
});

test('three-source delivery distinguishes the selected business date from historical receipts', () => {
  assert.match(appMainSource, /const selectedBusinessDate = String\(/);
  assert.match(
    appMainSource,
    /String\(item\?\.business_date \|\| ''\) === selectedBusinessDate/,
  );
  assert.match(appMainSource, /currentDelivery/);
  assert.match(appMainSource, /本业务日已送达/);
  assert.match(appMainSource, /历史送达/);
  assert.match(appMainSource, /最近业务日/);
  assert.match(appMainSource, /历史回执不计作本日送达/);
  assert.match(
    appMainSource,
    /deliveredCount: cards\.filter\(item => item\.currentDelivery !== null\)\.length/,
  );
  assert.doesNotMatch(
    appMainSource,
    /source\.latestDelivery\s*\?\s*'已送达'/,
  );
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
