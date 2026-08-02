import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const homeStaticSource = readFileSync('public/home-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const operationStatic = readFileSync('public/operation-static.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const context = { window: {} };
vm.runInNewContext(homeStaticSource, context, { filename: 'public/home-static.js' });

const { buildHomeOperatingScheduleModel } = context.window.SUXI_HOME_STATIC;

const yesterday = {
  date: '2026-08-01',
  status: '已取得',
  statusClass: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  sourceText: '携程、美团 OTA · 2026-08-01定稿事实 · 入库与回读证据见数据健康',
};

const baseItem = {
  hotel_id: 5,
  recommendation: {
    source: 'ota_diagnosis_saved#99',
    source_module: 'ota_diagnosis_saved',
    platform: 'ctrip',
    object_type: 'data_collection',
    action_type: 'complete_public_page_evidence',
    date_start: '2026-08-02',
    date_end: '2026-08-02',
    created_at: '2026-08-02 08:30:00',
  },
  approval: { status: 'pending', approved_at: '', blocked_reason: '' },
  execution: { status: 'pending_create', executed_at: '', blocked_reason: '' },
  assignment: { status: 'scheduled', due_at: '2026-08-02 10:00:00', review_at: '2026-08-03 10:00:00' },
  review: { status: 'observing', available_at: '2026-08-03 10:00:00', is_available: false },
  next_action: { key: 'approve_intent', label: '审批执行意图' },
};

const build = (flow, extra = {}) => buildHomeOperatingScheduleModel({
  flow,
  today: '2026-08-02',
  selectedHotelId: '5',
  scopeHotelName: '漠蓝酒店',
  yesterday,
  helpers: {
    hotelNameForId: (id) => (Number(id) === 5 ? '漠蓝酒店' : ''),
    sourceText: () => '携程 · OTA诊断行动',
    actionText: () => '证据采集 · 补齐公开页证据',
  },
  ...extra,
});

test('today orchestration keeps exact hotel date source and non-success task states', () => {
  const flow = {
    data_status: 'ok',
    data_gaps: [],
    list: [
      { ...baseItem, id: 101, stage: 'approval' },
      {
        ...baseItem,
        id: 102,
        stage: 'execution',
        approval: { status: 'approved', approved_at: '2026-08-02 09:00:00' },
        next_action: { key: 'execute_task', label: '执行并留证' },
      },
      {
        ...baseItem,
        id: 103,
        stage: 'review',
        approval: { status: 'approved', approved_at: '2026-08-01 09:00:00' },
        execution: { status: 'executed', executed_at: '2026-08-02 11:00:00' },
        review: { status: 'observing', available_at: '2026-08-03 10:00:00', is_available: false },
      },
      {
        ...baseItem,
        id: 104,
        stage: 'blocked',
        approval: { status: 'blocked', blocked_reason: '来源事实缺失' },
      },
      {
        ...baseItem,
        id: 105,
        stage: 'reviewed',
        execution: { status: 'executed', executed_at: '2026-08-02 15:00:00' },
        review: { status: 'success', available_at: '2026-08-02 14:00:00', is_available: true },
      },
      {
        ...baseItem,
        id: 106,
        stage: 'reviewed',
        recommendation: { ...baseItem.recommendation, date_start: '2026-07-20', date_end: '2026-07-20', created_at: '2026-07-20 08:00:00' },
        execution: { status: 'executed', executed_at: '2026-07-20 15:00:00' },
        assignment: { due_at: '2026-07-20 10:00:00', review_at: '2026-07-21 10:00:00' },
        review: { status: 'success', available_at: '2026-07-21 10:00:00', is_available: true },
      },
    ],
  };

  const model = build(flow);
  assert.equal(model.date, '2026-08-02');
  assert.equal(model.scopeHotelName, '漠蓝酒店');
  assert.equal(model.total, 5, 'historical completed tasks must not leak into today');
  assert.equal(model.items[0].hotelName, '漠蓝酒店');
  assert.equal(model.items[0].businessDateText, '2026-08-02');
  assert.equal(model.items[0].sourceRef, 'ota_diagnosis_saved#99');
  assert.equal(model.items[0].sourceLabel, '携程 · OTA诊断行动');
  assert.deepEqual(
    Array.from(model.items, (item) => item.statusLabel),
    ['已阻塞', '待审批', '待执行', '等待复盘', '已复盘'],
  );
  assert.equal(model.items.find((item) => item.intentId === 104)?.blockedReason, '来源事实缺失');
  assert.equal(model.fact.statusLabel, '已取得');
  assert.match(model.fact.sourceLabel, /2026-08-01定稿事实/);
});

test('loading failure waiting and empty are separate truthful states', () => {
  const loading = build(null, { loading: true });
  assert.equal(loading.stateCode, 'loading');
  assert.equal(loading.isInitialLoading, true);

  const failed = build(null, { error: '接口超时' });
  assert.equal(failed.stateCode, 'failed');
  assert.match(failed.notice, /接口超时/);
  assert.equal(failed.isEmpty, false);

  const waiting = build({ data_status: '待接入真实数据', data_gaps: [], list: [] });
  assert.equal(waiting.stateCode, 'waiting');
  assert.match(waiting.notice, /不显示为无任务或已完成/);
  assert.equal(waiting.isEmpty, false);

  const empty = build({ data_status: 'ok', data_gaps: [], list: [] });
  assert.equal(empty.stateCode, 'ready');
  assert.equal(empty.stateLabel, '今日暂无任务');
  assert.equal(empty.isEmpty, true);
});

test('home entry opens exact fact or intent and refreshes from execution readback', () => {
  assert.match(template, /<home-operating-orchestration/);
  assert.match(template, /@open="openHomeOperatingScheduleItem"/);
  assert.match(homeStaticSource, /'data-intent-id': fact \? undefined : item\.intentId/);
  assert.match(homeStaticSource, /今天没有匹配的运营任务/);
  assert.match(homeStaticSource, /这不等于经营已完成/);
  assert.match(appMain, /HomeOperatingOrchestration = window\.SUXI_HOME_STATIC\?\.HomeOperatingOrchestration/);
  assert.match(operationStatic, /apiRequest\(`\/operation\/execution-flow\?\$\{params\.toString\(\)\}`\)/);
  assert.match(operationStatic, /params\.set\('system_hotel_id', hotelId\)/);
  assert.match(operationStatic, /params\.append\('system_hotel_id', requestHotelId\)/);
  assert.match(operationStatic, /flow\.list\.find\(item => Number\(item\?\.hotel_id \|\| 0\) !== Number\(scopedHotelId\)\)/);
  assert.match(operationStatic, /loadOperationActions\(\{ focusIntentId: intentId \}\)/);
  assert.match(operationStatic, /applyHomeOperatingScheduleFlow\(operationExecutionFlow\.value, requestHotelId\)/);
  assert.match(appMain, /const homeOperatingSchedulePromise = force\s*\?\s*loadHomeOperatingSchedule\(\{ hotelId: compassHotelId \}\)/);
  assert.match(appMain, /scheduleDelayedPageTask\([\s\S]*loadHomeOperatingSchedule\(\{ hotelId: compassHotelId \}\)[\s\S]*HOME_SECONDARY_PANEL_DELAY_MS/);
});
