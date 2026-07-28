import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const pmsPage = read('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html');
const notificationPage = read('resources/frontend/templates/fragments/15ab-page-manual-notifications.html');
const notificationSchedulePanel = read('public/wechat-notification-static.js');
const targetPage = read('resources/frontend/templates/fragments/15aa-page-operating-targets.html');
const monitorPage = read('resources/frontend/templates/fragments/15aac-page-automation-monitor.html');
const taskPage = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const appMain = read('public/app-main.js');
const monitorSummary = monitorPage.slice(
  monitorPage.indexOf('data-testid="automation-monitor-summary"'),
  monitorPage.indexOf('data-testid="automation-monitor-error"')
);
const automationMonitorLogic = appMain.slice(
  appMain.indexOf('const automationMonitorSummaryCards'),
  appMain.indexOf('const automationMonitorDataStatusClass')
);

test('automation center keeps configuration, monitoring and execution pages focused', () => {
  assert.match(pmsPage, /运营自动化中心 · 第 1 步/);
  assert.match(pmsPage, /PMS经营数据/);
  assert.match(pmsPage, /data-testid="pms-selected-source"/);
  assert.match(pmsPage, /当前门店唯一 PMS/);
  assert.match(notificationPage, /运营自动化中心/);
  assert.match(notificationPage, /企业微信推送/);
  assert.match(monitorPage, /运营自动化中心 · 第 3 步/);
  assert.match(monitorPage, /今日数据与推送监控/);
  assert.match(targetPage, /每天目标、事实与剩余经营压力/);
  assert.doesNotMatch(targetPage, /运营自动化中心 · 第 3 步/);
  assert.match(monitorPage, /每店只显示一个主 PMS/);
  assert.match(monitorPage, /:aria-pressed="automationMonitorStatusFilter === card\.filter/);
  assert.doesNotMatch(monitorPage, /data-testid="automation-monitor-filters"/);
  for (const label of [
    '门店',
    '携程',
    '美团',
    '主 PMS 来源',
    '数据日期',
    '数据状态',
    '预计下次推送',
    '推送结果',
    '阻断原因',
  ]) {
    assert.match(monitorPage, new RegExp(label));
  }
  assert.match(taskPage, /运营自动化中心 · 第 4 步/);
  assert.match(taskPage, /任务执行与复盘/);
  assert.doesNotMatch(pmsPage, /携程数据|美团数据与采集|自动采集任务/);
});

test('notification plan exposes the necessary schedule and runtime settings', () => {
  assert.match(notificationPage, /data-testid="manual-notification-plan-summary"/);
  assert.match(notificationPage, /携程、美团、订单来了分别配置.*内容分项、通知群与暂停开关/);
  assert.match(notificationPage, /id="manual-notification-plan-config"/);
  for (const label of [
    '数据范围',
    '定时数据日期',
    '发送频率',
    '每日发送时间',
    '生效星期',
    '生效日期',
    '小时播报开始',
    '小时播报结束',
    '目标通知群',
    '启用或暂停本计划',
    '上次运行',
    '上次回执',
    '当前阻断原因',
  ]) {
    assert.match(notificationSchedulePanel, new RegExp(label));
  }
});

test('task execution page is explicitly hotel-scoped and labels manual task fields', () => {
  assert.match(taskPage, /data-testid="operation-scope-hotel"/);
  assert.match(taskPage, /@change="loadOperationActions"/);
  assert.match(taskPage, /人工新建运营任务/);
  for (const label of ['任务名称', '任务类型', '执行门店', '开始日期', '结束日期', '目标指标', '目标变化', '执行说明']) {
    assert.match(taskPage, new RegExp(label));
  }
});

test('automation monitor limits scope to WeCom-bound hotels and summary cards filter rows', () => {
  assert.match(monitorPage, /仅监测当前账号有权限且已绑定启用企业微信机器人的门店/);
  assert.match(monitorPage, /未绑定机器人门店不进入监控名单/);
  assert.match(monitorPage, /<button[\s\S]*v-for="card in automationMonitorSummaryCards"/);
  assert.match(monitorPage, /@click="automationMonitorStatusFilter = card\.filter"/);
  assert.match(monitorPage, /:aria-pressed="automationMonitorStatusFilter === card\.filter/);
  assert.doesNotMatch(monitorSummary, /当前筛选|点击筛选/);

  for (const [cardKey, filterKey] of [
    ['hotel_count', 'all'],
    ['data_ready_count', 'ready'],
    ['collecting_count', 'collecting'],
    ['waiting_push_count', 'waiting'],
    ['push_succeeded_count', 'sent'],
    ['blocked_count', 'blocked'],
  ]) {
    assert.match(
      automationMonitorLogic,
      new RegExp(`key: '${cardKey}', filter: '${filterKey}'`)
    );
  }

  assert.match(automationMonitorLogic, /filter === 'collecting'[\s\S]*row\?\.data_status === 'collecting'/);
  assert.match(automationMonitorLogic, /filter === 'waiting'[\s\S]*row\?\.push_status === 'waiting'/);
  assert.match(automationMonitorLogic, /filter === 'blocked'[\s\S]*row\.blockers\.length > 0/);
  assert.doesNotMatch(automationMonitorLogic, /key: 'attention', label: '待处理'/);
});

test('automation monitor uses concise truthful source status labels', () => {
  assert.match(monitorPage, /automationMonitorSourceStatusText\(row\.ctrip\)/);
  assert.match(monitorPage, /automationMonitorSourceStatusText\(row\.meituan\)/);
  assert.match(monitorPage, /automationMonitorSourceStatusText\(row\.pms\)/);
  assert.match(monitorPage, /automationMonitorSourceStatusHint\(row\.ctrip\)/);
  assert.match(automationMonitorLogic, /pending_collection: '状态待回写'/);
  assert.match(automationMonitorLogic, /pending_readback: '待入库回读'/);
  assert.match(automationMonitorLogic, /readback_verified: '已回读'/);
  assert.match(automationMonitorLogic, /pending_collection: '尚未确认采集任务是否运行'/);
  assert.doesNotMatch(monitorPage, /\{\{ row\.(?:ctrip|meituan|pms)\?\.status_label/);
});

test('automation monitor links primary-row facts directly without a duplicate detail panel', () => {
  assert.match(monitorPage, /点击门店、来源或推送状态直接查看对应页面/);
  assert.doesNotMatch(monitorPage, /automation-monitor-drilldown|运行明细|toggleAutomationMonitorRow|点击门店行展开/);
  for (const target of ['hotel', 'ctrip', 'meituan', 'pms', 'wechat', 'tasks']) {
    assert.match(
      monitorPage,
      new RegExp(`openAutomationMonitorDrilldown\\(row, '${target}'\\)`)
    );
  }
  assert.equal((monitorPage.match(/openAutomationMonitorDrilldown\(row, 'wechat'\)/g) || []).length, 2);

  assert.doesNotMatch(appMain, /automationMonitorExpandedHotelId|automationMonitorStatusFilters|toggleAutomationMonitorRow/);
  assert.match(automationMonitorLogic, /ctripTargetHotelManuallySelected\.value = true/);
  assert.match(automationMonitorLogic, /selectedCtripHotelId\.value = hotelId/);
  assert.match(automationMonitorLogic, /autoFetchHotelId\.value = hotelId/);
  assert.match(automationMonitorLogic, /meituanForm\.value = \{[\s\S]*hotelId/);
  assert.match(automationMonitorLogic, /operatingTargetForm\.value = \{[\s\S]*hotel_id: hotelId,[\s\S]*target_date: businessDate/);
  assert.match(automationMonitorLogic, /manualNotificationForm\.value = \{[\s\S]*hotel_id: hotelId,[\s\S]*business_date: businessDate/);
  assert.match(automationMonitorLogic, /operationFilters\.value = \{[\s\S]*hotel_id: hotelId,[\s\S]*date: businessDate/);
  assert.match(automationMonitorLogic, /aiDailyReportForm\.value = \{[\s\S]*hotel_id: hotelId,[\s\S]*report_date: businessDate/);
});
