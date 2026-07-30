import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const systemStaticSource = fs.readFileSync('public/system-static.js', 'utf8');
const appMainSource = fs.readFileSync('public/app-main.js', 'utf8');
const serviceSource = fs.readFileSync('app/service/ManualNotificationService.php', 'utf8');
const fragmentSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15ab-page-manual-notifications.html',
  'utf8',
);
const schedulePanelSource = fs.readFileSync('public/wechat-notification-static.js', 'utf8');
const notificationUiSource = `${fragmentSource}\n${schedulePanelSource}`;
const operatingTargetFragmentSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15aa-page-operating-targets.html',
  'utf8',
);
const pmsOperatingDataFragmentSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15aab-page-pms-operating-data.html',
  'utf8',
);

const browserContext = { window: {} };
vm.createContext(browserContext);
vm.runInContext(systemStaticSource, browserContext);
const systemStatic = browserContext.window.SUXI_SYSTEM_STATIC;

test('operating target missing-record reset preserves new context and clears stale facts', () => {
  const reset = systemStatic.resetOperatingTargetFormForContext;
  const result = reset({
    hotel_id: '9',
    target_date: '2026-07-25',
    target_revenue: '8888',
    target_occupancy_rate_percent: '82',
    target_revpar: '320',
    actual_revenue: '7777',
    sold_room_nights: '22',
    sellable_room_nights: '30',
    source_type: 'whole_hotel_daily_report',
    source_reference: 'old-date-source',
    quality_status: 'verified',
    quality_reason: 'old-date-proof',
    change_reason: 'old-date-change',
  }, '80', '2026-07-26');

  assert.equal(result.hotel_id, '80');
  assert.equal(result.target_date, '2026-07-26');
  assert.equal(result.target_revenue, '');
  assert.equal(result.target_occupancy_rate_percent, '');
  assert.equal(result.target_revpar, '');
  assert.equal(result.actual_revenue, '');
  assert.equal(result.sold_room_nights, '');
  assert.equal(result.sellable_room_nights, '');
  assert.equal(result.fact_scope, 'accommodation_room_fee');
  assert.equal(result.source_reference, '');
  assert.equal(result.quality_reason, '');
  assert.equal(result.change_reason, '');
  assert.equal(result.source_type, 'manual');
  assert.equal(result.quality_status, 'unverified');
  assert.match(
    appMainSource,
    /applyOperatingTargetRecord\(operatingTargetResult\.value,\s*context\)/,
  );
});

test('notification variables replace only the four SUXIOS placeholders', () => {
  const replace = systemStatic.replaceManualNotificationVariables;
  const rendered = replace(
    '{酒店名称}|{经营日期}|{统计时间}|{数据状态}|{未知变量}',
    {
      hotelName: '漠蓝酒店',
      businessDate: '2026-07-26',
      statisticsTime: '2026-07-26 09:00',
      dataStatus: '仅保存/仅测试',
    },
  );
  assert.equal(
    rendered,
    '漠蓝酒店|2026-07-26|2026-07-26 09:00|仅保存/仅测试|{未知变量}',
  );
});

test('notification center keeps one plan list with a compact editor and preview', () => {
  for (const label of ['今日收益管理', '远期房态', '今日复盘', '空白自定义']) {
    assert.match(serviceSource, new RegExp(label));
  }
  assert.match(fragmentSource, /新建计划/);
  assert.match(fragmentSource, /data-testid="manual-notification-automatic-tasks"/);
  assert.match(appMainSource, /const hotelPlans = tasks\.filter/);
  assert.match(appMainSource, /openManualNotificationPlanById/);
  assert.doesNotMatch(fragmentSource, /data-testid="manual-notification-history"|已保存计划/);
  assert.doesNotMatch(fragmentSource, /我的模板消息/);
  assert.match(fragmentSource, /消息预览/);
  assert.doesNotMatch(fragmentSource, /预览在企业微信里的展示格式，根据上方选择的计划生成/);
  assert.doesNotMatch(fragmentSource, /实时渲染/);
  assert.match(schedulePanelSource, /安全规则/);
  assert.match(schedulePanelSource, /运行详情/);
  assert.match(fragmentSource, /manualNotificationBodyCount/);
  assert.match(fragmentSource, /manualNotificationTestAllowed\(manualNotificationForm\)/);
  assert.doesNotMatch(fragmentSource, /手机号|客户短信/);
});

test('message preview reacts to all selections but displays only source and date metadata', () => {
  assert.match(appMainSource, /const manualNotificationPreviewContext = computed/);
  for (const field of ['source', 'content', 'businessDate', 'schedule']) {
    assert.match(appMainSource, new RegExp(`${field}:`));
  }
  assert.match(fragmentSource, /manualNotificationPreviewContext\.source/);
  assert.match(fragmentSource, /manualNotificationPreviewContext\.businessDate/);
  assert.doesNotMatch(fragmentSource, /manualNotificationPreviewContext\.(?:content|schedule)/);
  assert.match(
    appMainSource,
    /manualNotificationForm\.value\[field\] = value;\s*manualNotificationPreview\.value = null;/,
  );
  assert.match(
    appMainSource,
    /const selection = manualNotificationPreviewContext\.value;[\s\S]*来源：\$\{selection\.source\}[\s\S]*内容：\$\{selection\.content\}/,
  );
  assert.match(fragmentSource, /生成消息预览/);
});

test('notification plans expose persisted schedule rules and truthful runtime state', () => {
  for (const marker of [
    'manual-notification-business-date-rule',
    'manual-notification-weekdays',
    'manual-notification-hourly-start',
    'manual-notification-hourly-end',
    'manual-notification-interval-minutes',
    'manual-notification-interval-start',
    'manual-notification-enabled',
    'manual-notification-runtime-status',
    'manual-notification-next-run',
    'manual-notification-current-blocker',
  ]) {
    assert.match(notificationUiSource, new RegExp(marker));
  }
  assert.doesNotMatch(notificationUiSource, /manual-notification-effective-from|manual-notification-effective-to/);
  for (const policy of ['缺数据', '漏跑', '结果不明', '明确失败可人工重试', '确认可能重复送达']) {
    assert.match(schedulePanelSource, new RegExp(policy));
  }
  for (const field of [
    'business_date_rule',
    'active_weekdays',
    'effective_from',
    'effective_to',
    'hourly_start_time',
    'hourly_end_time',
    'interval_minutes',
    'next_run_at',
  ]) {
    assert.match(appMainSource, new RegExp(field));
  }
  assert.match(appMainSource, /applyManualNotificationRecord\(record\)/);
  assert.match(notificationUiSource, /manual-notification-source-scope/);
  assert.match(notificationUiSource, /manual-notification-content-sections/);
  assert.match(notificationUiSource, /发送来源/);
  assert.match(notificationUiSource, /发送什么/);
  assert.match(notificationUiSource, /首次发送时间/);
  assert.doesNotMatch(notificationUiSource, /manual-notification-interval-end|循环开始|循环结束/);
});

test('notification schedule panel cache key matches its exact source bytes', () => {
  const versionMatch = appMainSource.match(
    /wechat-notification-static\.js\?v=[^'"]+-h([0-9a-f]{10})/,
  );
  assert.ok(versionMatch, 'notification schedule panel must have a content hash cache key');
  assert.equal(
    versionMatch[1],
    createHash('sha256').update(schedulePanelSource).digest('hex').slice(0, 10),
  );
});

test('operating daily keeps custom compatibility while common templates choose a source', () => {
  assert.match(fragmentSource, /manual-notification-content-template-mode/);
  assert.match(fragmentSource, /manual-notification-content-mode-\$\{mode\.key\}/);
  assert.match(serviceSource, /'label' => '通用模板'/);
  assert.match(serviceSource, /'label' => '自定义模板'/);
  assert.match(appMainSource, /const selectManualNotificationContentMode = \(mode\) =>/);
  assert.match(appMainSource, /operating_daily_custom_report/);
  assert.match(appMainSource, /template\.key === item\?\.notification_type/);
  assert.match(appMainSource, /source_scope: 'combined'/);
  assert.match(notificationUiSource, /manual-notification-source-scope/);
  assert.match(
    appMainSource,
    /updateManualNotificationScheduleField[\s\S]{0,500}'source_scope'/,
  );
  assert.match(serviceSource, /\$input\['content_sections'\] \?\? null/);
  assert.match(
    serviceSource,
    /\$type === self::OPERATING_DAILY_CUSTOM_REPORT_TYPE[\s\S]{0,500}\$sourceScope = 'combined'/,
  );
});

test('PMS operating data page owns unified PMS deltas without duplicating source configuration', () => {
  assert.match(pmsOperatingDataFragmentSource, /data-testid="pms-operating-data-page"/);
  assert.match(pmsOperatingDataFragmentSource, /data-testid="pms-selected-source"/);
  assert.match(pmsOperatingDataFragmentSource, /data-testid="pms-selected-source-deltas"/);
  assert.match(pmsOperatingDataFragmentSource, /同一 PMS 的相邻快照差值/);
  assert.match(pmsOperatingDataFragmentSource, /不会自动选择其中一套作为真值/);
  assert.doesNotMatch(pmsOperatingDataFragmentSource, /data-testid="operating-target-pms-status"/);
  assert.doesNotMatch(pmsOperatingDataFragmentSource, /data-testid="dingdandao-pms-integration"/);
  assert.doesNotMatch(pmsOperatingDataFragmentSource, /data-testid="meituan-cloud-pms-integration"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /data-testid="operating-target-pms-status"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /data-testid="dingdandao-pms-integration"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /data-testid="meituan-cloud-pms-integration"/);
});

test('operating target page keeps PMS prefill and truthful report blockers', () => {
  assert.match(operatingTargetFragmentSource, /operating-target-prefill-dingdandao/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-pms-facts"/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-report-preview"/);
  assert.match(operatingTargetFragmentSource, /formal_send_gate\?\.allowed === true/);
  assert.match(operatingTargetFragmentSource, /formal_send_gate\.blockers/);
  assert.match(operatingTargetFragmentSource, /数据门禁通过，尚未发送/);
  assert.match(operatingTargetFragmentSource, /发送已阻断/);
  assert.doesNotMatch(operatingTargetFragmentSource, /PMS[^<\n]{0,20}(?:发送成功|采集成功)/);
});

test('operating target desktop layout prioritizes entry with a compact evidence rail', () => {
  assert.match(
    operatingTargetFragmentSource,
    /xl:grid-cols-\[minmax\(0,1\.55fr\)_minmax\(320px,0\.75fr\)\]/,
  );
  assert.match(
    operatingTargetFragmentSource,
    /设置当日住宿房费总目标[\s\S]*data-testid="operating-target-pms-facts"[\s\S]*<aside class="space-y-4">/,
  );
  assert.match(
    operatingTargetFragmentSource,
    /数据缺口与一致性检查[\s\S]*border-t border-gray-100 pt-4[\s\S]*经营提醒/,
  );
  assert.match(
    operatingTargetFragmentSource,
    /class="grid grid-cols-2 gap-3 md:grid-cols-4" data-testid="operating-target-metrics"/,
  );
});

test('operating targets keep one revenue goal and show PMS facts as read-only evidence', () => {
  assert.match(operatingTargetFragmentSource, /v-model="operatingTargetForm\.target_revenue"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /v-model="operatingTargetForm\.target_occupancy_rate_percent"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /v-model="operatingTargetForm\.target_revpar"/);
  assert.doesNotMatch(operatingTargetFragmentSource, /data-testid="operating-target-pms-comparison"/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-pms-facts"/);
  assert.match(operatingTargetFragmentSource, /PMS 经营事实（只读）/);
  assert.match(operatingTargetFragmentSource, /operatingTargetPmsFactRows/);
  assert.match(operatingTargetFragmentSource, /只设置一个总目标/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-create-task-draft"/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-task-draft-error"/);
  assert.match(operatingTargetFragmentSource, /进入任务执行与复盘/);

  assert.match(appMainSource, /target_occupancy_rate_percent: null/);
  assert.match(appMainSource, /target_revpar: null/);
  assert.match(appMainSource, /fact_scope: 'accommodation_room_fee'/);
  for (const key of [
    'target_revenue',
    'actual_revenue',
    'completion_rate_percent',
    'remaining_revenue',
  ]) {
    assert.match(appMainSource, new RegExp(`key: '${key}'`));
  }
  for (const label of ['实际住宿房费', '已售间夜', '可售房夜', '入住率', 'ADR', 'RevPAR']) {
    assert.match(appMainSource, new RegExp(`label: '${label}'`));
  }
  assert.match(appMainSource, /apiRequest\('\/operating-targets\/task-draft'/);
  assert.match(appMainSource, /operatingTargetTaskDraftError\.value = operationErrorMessage/);
  assert.match(appMainSource, /currentPage\.value = 'ops-track'/);
  assert.doesNotMatch(appMainSource, /Number\([^)]*actual_revenue[^)]*\)\s*\|\|\s*0/);
});

test('dynamic operating-target template supports save then immediate test without preview-as-success', () => {
  assert.match(fragmentSource, /manual-notification-template-\$\{template\.key\}/);
  assert.match(fragmentSource, /xl:grid-cols-5/);
  assert.match(fragmentSource, /data-testid="manual-notification-test-now"/);
  assert.match(fragmentSource, /Number\(manualNotificationForm\.id \|\| 0\) > 0/);
  assert.match(fragmentSource, /testManualNotification\(manualNotificationForm\)/);
  assert.match(fragmentSource, /manualNotificationHasUnsavedChanges/);
  assert.match(fragmentSource, /请先保存更改/);
  assert.match(appMainSource, /计划有未保存更改，请先保存再测试/);
  assert.match(appMainSource, /manualNotificationComparablePlan/);
  assert.match(fragmentSource, /预览 · 未发送/);
  assert.doesNotMatch(fragmentSource, /后端预览已验证/);
});

test('dispatch history is independent and never treats missing receipts as delivery success', () => {
  assert.match(fragmentSource, /manualNotificationDispatchPanelBody/);
  assert.match(notificationUiSource, /data-testid': 'manual-notification-dispatch-history'/);
  assert.match(notificationUiSource, /发送记录/);
  assert.match(notificationUiSource, /props\.history\?\.list/);
  assert.match(notificationUiSource, /status === 'sent'/);
  assert.match(notificationUiSource, /item\?\.dispatched_at \|\| item\?\.last_attempt_at \|\| item\?\.claimed_at/);
  assert.match(appMainSource, /retryManualNotificationDispatch\(item\)/);
  assert.match(appMainSource, /openManualNotificationDispatchPlan\(item\)/);
  assert.match(notificationUiSource, /无需操作/);
  assert.match(notificationUiSource, /补齐数据/);
  assert.match(notificationUiSource, /确认后重试/);
  assert.match(notificationUiSource, /item\?\.plan_title/);
  assert.match(notificationUiSource, /item\?\.source_scope_label/);
  assert.match(appMainSource, /执行记录未取得/);
  assert.match(notificationUiSource, /暂无发送记录/);
  assert.doesNotMatch(notificationUiSource, />不可重试</);
  assert.match(appMainSource, /\/manual-notifications\/dispatch-history/);
  assert.match(appMainSource, /manualNotificationDispatchCanRetry/);
  assert.match(appMainSource, /\['failed', 'outcome_unknown'\]\.includes\(status\)/);
  assert.match(appMainSource, /再次发送可能产生重复消息/);
  assert.match(appMainSource, /manualNotificationWorkspaceTab\.value = 'plans'/);
  assert.match(appMainSource, /status === 'sent'[\s\S]*manualNotificationWorkspaceTab\.value = 'records'/);
});

test('test push remains explicit and uses the persisted authorized plan robot', () => {
  assert.match(appMainSource, /将向“\$\{targetRobotName\}”发送一次真实测试消息/);
  assert.match(appMainSource, /confirmed:\s*true/);
  assert.match(appMainSource, /target_robot_id:\s*targetRobotId/);
  assert.match(appMainSource, /target_robot_name:\s*targetRobotName/);
  assert.match(appMainSource, /\['wecom_test', 'wecom_formal'\]\.includes/);
  assert.match(appMainSource, /scope_label: '当前酒店通道'/);
  assert.match(appMainSource, /applyCurrentHotelNotificationChannel/);
  assert.match(notificationUiSource, /当前酒店企业微信群机器人 Webhook 已绑定/);
  assert.match(appMainSource, /formal_scope_ready/);
  assert.match(fragmentSource, /manualNotificationSchedulerDisplay\.label/);
  assert.match(appMainSource, /manualNotificationTestAllowed/);
  assert.doesNotMatch(appMainSource, /测试推送仅允许酒店80绑定的1号漠蓝测试机器人/);
});

test('notification workspace removes repeated explanations while keeping the three-step flow', () => {
  for (const step of ['1　推送通道', '2　自动推送', '3　发送记录']) {
    assert.match(fragmentSource, new RegExp(step));
  }
  assert.match(fragmentSource, /:title="manualNotificationSchedulerDisplay\.note"/);
  assert.doesNotMatch(fragmentSource, /manual-notification-scheduler-note/);
  assert.match(schedulePanelSource, /h\('details'/);
  assert.doesNotMatch(fragmentSource, /manual-notification-history/);
  for (const removedCopy of [
    '每家酒店仅绑定一个群机器人',
    '选择“经营日报”后',
    '成功消息不会重复发送',
    '保存后可回读、编辑和预览',
  ]) {
    assert.doesNotMatch(fragmentSource, new RegExp(removedCopy));
  }
  assert.doesNotMatch(schedulePanelSource, /酒店由页面顶部统一选择，不会绑定到其他酒店/);
  assert.match(appMainSource, /showToast\(response\.message \|\| '测试消息已发送'\);[\s\S]*manualNotificationWorkspaceTab\.value = 'plans'/);
});
