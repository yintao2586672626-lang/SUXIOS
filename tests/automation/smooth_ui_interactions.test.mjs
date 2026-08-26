import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const appMain = read('public/app-main.js');
const schedulePanel = read('public/wechat-notification-static.js');
const notificationOrchestration = read('public/manual-notification-orchestration-static.js');
const notificationPage = read(
  'resources/frontend/templates/fragments/15ab-page-manual-notifications.html'
);
const pmsPage = read(
  'resources/frontend/templates/fragments/15aab-page-pms-operating-data.html'
);
const automationPage = read(
  'resources/frontend/templates/fragments/15aac-page-automation-monitor.html'
);
const operationsPage = read(
  'resources/frontend/templates/fragments/17-page-ops-track.html'
);

test('notification and PMS hotel selectors support bounded name, code and id search', () => {
  assert.match(notificationPage, /data-testid="manual-notification-hotel-search"/);
  assert.match(notificationPage, /v-for="hotel in manualNotificationFilteredHotelOptions"/);
  assert.match(pmsPage, /data-testid="pms-operating-data-hotel-search"/);
  assert.match(pmsPage, /v-for="hotel in pmsFilteredHotelOptions"/);

  assert.match(appMain, /const filterHotelOptions = \(options, keyword\) => \{/);
  assert.match(appMain, /hotel\?\.name[\s\S]*hotel\?\.code[\s\S]*hotel\?\.id/);
  assert.match(appMain, /const pmsFilteredHotelOptions = computed/);
  assert.match(appMain, /const manualNotificationFilteredHotelOptions = computed/);
  assert.match(appMain, /if \(!hotelId \|\| !isOperationHotelPermitted\(hotelId\)\) return/);
});

test('first-load placeholders preserve layout without inventing business values', () => {
  assert.match(pmsPage, /data-testid="pms-selected-source-loading"[\s\S]*animate-pulse/);
  assert.match(automationPage, /data-testid="automation-monitor-skeleton"[\s\S]*animate-pulse/);
  assert.match(notificationPage, /manualNotificationLoading\.metadata[\s\S]*读取模板/);
  assert.doesNotMatch(notificationPage, /manual-notification-template-skeleton/);
  assert.doesNotMatch(
    pmsPage,
    /data-testid="pms-selected-source-loading"[\s\S]{0,1200}(?:\b0%\b|¥0|0 间夜)/
  );
});

test('manual notification validation is attached to the actual fields before API calls', () => {
  assert.match(appMain, /const manualNotificationFieldErrors = computed\(\(\) => \{/);
  assert.match(appMain, /const validateManualNotificationForm = \(\) => \{/);
  assert.match(appMain, /const previewManualNotification = async \(\) => \{\s*if \(!validateManualNotificationForm\(\)\) return/);
  assert.match(appMain, /const saveManualNotification = async \(\) => \{\s*if \(!validateManualNotificationForm\(\)\) return/);
  assert.match(notificationPage, /data-testid="manual-notification-hotel-error"/);
  assert.match(notificationPage, /data-testid="manual-notification-title-error"/);
  assert.match(notificationPage, /data-testid="manual-notification-body-error"/);
  assert.match(schedulePanel, /validationErrors:\s*\{\s*type:\s*Object/);
  assert.match(schedulePanel, /'aria-invalid':\s*fieldError\(fieldName\) \? 'true' : 'false'/);
});

test('dispatch history expands a factual attempt timeline without replacing the compact record list', () => {
  const notificationUi = `${notificationPage}\n${schedulePanel}`;
  assert.match(notificationUi, /manual-notification-dispatch-history/);
  assert.match(notificationUi, /manual-notification-dispatch-timeline/);
  assert.match(appMain, /const manualNotificationDispatchTimeline = \(\.\.\.args\) => requireManualNotificationOrchestrationController\(\)\.manualNotificationDispatchTimeline\(\.\.\.args\)/);
  assert.match(notificationOrchestration, /const manualNotificationDispatchTimeline = \(item = \{\}\) => \{/);
  assert.match(notificationOrchestration, /Array\.isArray\(item\?\.attempts\) \? item\.attempts : \[\]/);
  assert.match(notificationOrchestration, /attempted_at/);
  assert.match(notificationOrchestration, /claimed_at/);
  assert.match(notificationOrchestration, /dispatched_at/);
  assert.doesNotMatch(notificationOrchestration, /manualNotificationDispatchTimeline[\s\S]{0,1800}http.*成功/i);
});

test('execution stage cards filter the existing task pool without changing backend counts', () => {
  assert.match(operationsPage, /@click="setOperationExecutionStageFilter\(stage\.key\)"/);
  assert.match(operationsPage, /:aria-pressed="operationExecutionStageFilter === String\(stage\.key\)"/);
  assert.match(operationsPage, /v-for="item in operationExecutionFilteredItems"/);
  assert.match(operationsPage, /查看全部阶段/);
  assert.match(appMain, /const operationExecutionStageFilter = ref\(''\)/);
  assert.match(appMain, /const operationExecutionFilteredItems = computed\(\(\) => \{/);
  assert.match(appMain, /item => String\(item\?\.stage \|\| ''\) === stage/);
  assert.match(appMain, /operationExecutionStageFilter\.value === key \? '' : key/);
});
