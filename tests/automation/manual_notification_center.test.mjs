import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const systemStaticSource = fs.readFileSync('public/system-static.js', 'utf8');
const appMainSource = fs.readFileSync('public/app-main.js', 'utf8');
const serviceSource = fs.readFileSync('app/service/ManualNotificationService.php', 'utf8');
const testTargetResolverSource = fs.readFileSync(
  'app/service/ManualNotificationTestTargetService.php',
  'utf8',
);
const fragmentSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15ab-page-manual-notifications.html',
  'utf8',
);
const operatingTargetFragmentSource = fs.readFileSync(
  'resources/frontend/templates/fragments/15aa-page-operating-targets.html',
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
  assert.equal(result.actual_revenue, '');
  assert.equal(result.sold_room_nights, '');
  assert.equal(result.sellable_room_nights, '');
  assert.equal(result.fact_scope, 'whole_hotel');
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

test('notification center follows the required cards, editor, preview and history hierarchy', () => {
  for (const label of ['今日收益管理', '远期房态', '今日复盘', '空白自定义']) {
    assert.match(serviceSource, new RegExp(label));
  }
  for (const column of ['名称', '消息内容摘要', '发送时间', '状态', '操作']) {
    assert.match(fragmentSource, new RegExp(column));
  }
  assert.match(fragmentSource, /新建消息/);
  assert.match(fragmentSource, /我的通知消息/);
  assert.doesNotMatch(fragmentSource, /我的模板消息/);
  assert.match(fragmentSource, /实时渲染/);
  assert.match(fragmentSource, /经营数据与测试群门禁/);
  assert.match(fragmentSource, /manualNotificationBodyCount/);
  assert.match(fragmentSource, /manualNotificationTestAllowed\(item\)/);
  assert.doesNotMatch(fragmentSource, /手机号|客户短信/);
});

test('operating target page exposes truthful PMS evidence and report blockers', () => {
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-pms-status"/);
  assert.match(operatingTargetFragmentSource, /订单来了 PMS 事实状态/);
  assert.match(operatingTargetFragmentSource, /operatingTargetPmsStatus\?\.quality_status === 'verified'/);
  assert.match(operatingTargetFragmentSource, /captured_at/);
  assert.match(operatingTargetFragmentSource, /detail_room_fee_total/);
  assert.match(operatingTargetFragmentSource, /operating-target-prefill-dingdandao/);
  assert.match(operatingTargetFragmentSource, /accommodation_room_fee/);
  assert.match(operatingTargetFragmentSource, /data-testid="operating-target-report-preview"/);
  assert.match(operatingTargetFragmentSource, /formal_send_gate\?\.allowed === true/);
  assert.match(operatingTargetFragmentSource, /formal_send_gate\.blockers/);
  assert.match(operatingTargetFragmentSource, /数据门禁通过，尚未发送/);
  assert.match(operatingTargetFragmentSource, /发送已阻断/);
  assert.doesNotMatch(operatingTargetFragmentSource, /PMS[^<\n]{0,20}(?:发送成功|采集成功)/);
});

test('dynamic operating-target template supports save then immediate test without preview-as-success', () => {
  assert.match(fragmentSource, /template\.key === 'single_hotel_operating_brief'/);
  assert.match(fragmentSource, /基础经营事实简报不依赖经营目标/);
  assert.match(fragmentSource, /只要求当天已核验的订单来了住宿事实；目标和渠道块均为可选/);
  assert.match(fragmentSource, /template\.key === 'operating_target_report'/);
  assert.match(fragmentSource, /主动启用目标模块后动态生成；目标事实不齐时不发送/);
  assert.match(fragmentSource, /xl:grid-cols-3/);
  assert.match(fragmentSource, /data-testid="manual-notification-test-now"/);
  assert.match(fragmentSource, /Number\(manualNotificationForm\.id \|\| 0\) > 0/);
  assert.match(fragmentSource, /testManualNotification\(manualNotificationForm\)/);
  assert.match(fragmentSource, /后端预览已生成但未发送/);
  assert.doesNotMatch(fragmentSource, /后端预览已验证/);
});

test('dispatch history is independent and never treats missing receipts as delivery success', () => {
  assert.match(fragmentSource, /data-testid="manual-notification-dispatch-history"/);
  assert.match(fragmentSource, /调度与发送历史/);
  assert.match(fragmentSource, /manualNotificationDispatchHistory\.list/);
  assert.match(fragmentSource, /item\.status === 'sent'/);
  assert.match(fragmentSource, /item\.dispatched_at \|\| item\.last_attempt_at \|\| item\.claimed_at/);
  assert.match(fragmentSource, /retryManualNotificationDispatch\(item\)/);
  assert.match(fragmentSource, /执行记录未取得/);
  assert.match(fragmentSource, /未执行不显示成功/);
  assert.match(appMainSource, /\/manual-notifications\/dispatch-history/);
});

test('test push remains explicit and scoped to the verified metadata binding', () => {
  assert.match(appMainSource, /binding_status \|\| ''\)\s*\.startsWith\('verified_'\)/);
  assert.match(appMainSource, /formal_group_delivery_allowed === false/);
  assert.match(appMainSource, /const targetName = manualNotificationMetadata/);
  assert.match(appMainSource, /confirmed:\s*true/);
  assert.match(appMainSource, /target_robot_id:\s*Number/);
  assert.match(serviceSource, /ManualNotificationTestTargetService/);
  assert.match(testTargetResolverSource, /TEST_SCOPE\s*=\s*'operating_target_test'/);
  assert.doesNotMatch(testTargetResolverSource, /field\('webhook/);
  assert.match(appMainSource, /manualNotificationTestAllowed/);
});
