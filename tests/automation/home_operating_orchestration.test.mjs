import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const homeStaticSource = readFileSync('public/home-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/23a-page-compass-summary.html', 'utf8');
const context = { window: {}, URLSearchParams };
vm.runInNewContext(homeStaticSource, context, { filename: 'public/home-static.js' });

const { buildHomeOperatingScheduleModel, createHomeWeeklyOperatingPlanController } = context.window.SUXI_HOME_STATIC;

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

test('daily one thing is promoted to one focus card and removed from the generic list', () => {
  const daily = {
    ...baseItem,
    id: 201,
    stage: 'approval',
    recommendation: {
      ...baseItem.recommendation,
      source: 'operating_opportunity_runs#901',
      source_module: 'daily_one_thing',
      action_type: 'collect_trusted_ota_facts',
    },
    action_management: {
      action_card: {
        contract_version: 'operation_action_card.v2',
        problem: '携程目标日期可信事实尚未回读',
        fact_refs: ['operating_opportunity_runs#901', 'dual_ota_field_closure#abc'],
        recommendation_explanation: {
          contract_version: 'daily_one_thing_explanation.v1',
          why_now: {
            summary: '当前营业日仍缺携程核心事实。',
            source_refs: ['dual_ota_field_closure#abc'],
          },
          why_recommended: {
            summary: '该事项在公共四维基础排序中最高。',
            source_refs: ['operating_opportunity_runs#901'],
          },
          personalization: {
            status: 'not_applied',
            reason_code: 'hotel_shared_base_selection',
            summary: '酒店共享正式事项未使用个人偏好改写。',
          },
        },
      },
    },
  };
  const ordinary = { ...baseItem, id: 202, stage: 'approval' };
  const model = build({ data_status: 'ok', data_gaps: [], list: [ordinary, daily] });

  assert.equal(model.dailyFocus.intentId, 201);
  assert.equal(model.dailyFocus.sourceModule, 'daily_one_thing');
  assert.equal(model.dailyFocus.explanation.whyNow, '当前营业日仍缺携程核心事实。');
  assert.equal(model.dailyFocus.explanation.whyRecommended, '该事项在公共四维基础排序中最高。');
  assert.equal(model.dailyFocus.explanation.personalizationStatus, 'not_applied');
  assert.deepEqual(
    Array.from(model.dailyFocus.explanation.sourceRefs),
    ['dual_ota_field_closure#abc', 'operating_opportunity_runs#901'],
  );
  assert.equal(model.items.length, 1);
  assert.equal(model.items[0].intentId, 202);
  assert.equal(model.total, 2);
  assert.match(homeStaticSource, /daily_one_thing: '每日一件事'/);
  assert.match(homeStaticSource, /data-testid': 'home-daily-one-thing-panel'/);
  assert.match(homeStaticSource, /home-daily-one-thing-focus/);
  assert.match(homeStaticSource, /home-daily-one-thing-explanation/);
  assert.match(homeStaticSource, /home-daily-one-thing-why-now/);
  assert.match(homeStaticSource, /home-daily-one-thing-why-recommended/);
  assert.match(homeStaticSource, /home-daily-one-thing-personalization/);
  assert.match(homeStaticSource, /自动计划尚未生成或严格事实来源不可用/);
  assert.match(homeStaticSource, /除今日唯一重点外，暂无其他匹配任务/);
});

test('daily focus duplication is scoped to one business date', () => {
  const latest = {
    ...baseItem,
    id: 211,
    recommendation: { ...baseItem.recommendation, source: 'daily_one_thing#911', source_module: 'daily_one_thing' },
  };
  const older = {
    ...baseItem,
    id: 210,
    recommendation: {
      ...baseItem.recommendation,
      source: 'daily_one_thing#910',
      source_module: 'daily_one_thing',
      date_start: '2026-08-01',
      date_end: '2026-08-01',
    },
    assignment: { ...baseItem.assignment, due_at: '2026-08-01 10:00:00' },
  };
  const model = build({ data_status: 'ok', data_gaps: [], list: [older, latest] });
  assert.equal(model.dailyFocus.intentId, 211);
  assert.equal(model.duplicateDailyFocusCount, 0);
  assert.equal(model.items.some(item => item.intentId === 210), true);
  assert.doesNotMatch(model.notice, /duplicate_daily_focus/);
});

test('home entry opens exact fact or intent and refreshes from execution readback', () => {
  assert.match(template, /<home-operating-orchestration/);
  assert.match(template, /@open="openHomeOperatingScheduleItem"/);
  assert.match(template, /:weekly-plan="homeWeeklyOperatingPlan"/);
  assert.match(homeStaticSource, /data-testid': 'home-weekly-operating-plan'/);
  assert.match(homeStaticSource, /\/operating-opportunities\/weekly-plan\/latest\?/);
  assert.match(homeStaticSource, /res\.data\?\.readback_verified !== true/);
  assert.match(homeStaticSource, /周度经营计划返回的酒店或周期身份不一致/);
  assert.match(homeStaticSource, /'data-intent-id': fact \? undefined : item\.intentId/);
  assert.match(homeStaticSource, /今天没有匹配的运营任务/);
  assert.match(homeStaticSource, /这不等于经营已完成/);
  assert.match(appMain, /HomeOperatingOrchestration = window\.SUXI_HOME_STATIC\?\.HomeOperatingOrchestration/);
  assert.match(appMain, /apiRequest\(`\/operation\/execution-flow\?\$\{params\.toString\(\)\}`\)/);
  assert.match(appMain, /params\.set\('system_hotel_id', hotelId\)/);
  assert.match(appMain, /params\.append\('system_hotel_id', requestHotelId\)/);
  assert.match(appMain, /flow\.list\.find\(item => Number\(item\?\.hotel_id \|\| 0\) !== Number\(scopedHotelId\)\)/);
  assert.match(appMain, /loadOperationActions\(\{ focusIntentId: intentId \}\)/);
  assert.match(appMain, /applyHomeOperatingScheduleFlow\(operationExecutionFlow\.value, requestHotelId\)/);
  assert.match(appMain, /homeOperatingScheduleLoading\.value = true;[\s\S]*scheduleDelayedPageTask\(\(\) => \{[\s\S]*return loadHomeOperatingSchedule\(\{ hotelId: compassHotelId \}\);[\s\S]*\}, HOME_SECONDARY_PANEL_DELAY_MS\);/);
  assert.doesNotMatch(appMain, /const homeOperatingSchedulePromise = requestPage === 'compass'/);
});

test('weekly plan refresh failure preserves the last verified same-scope snapshot', async () => {
  const ref = value => ({ value });
  let fail = false;
  const controller = createHomeWeeklyOperatingPlanController({
    ref,
    apiRequest: async () => fail
      ? { code: 503, message: '临时超时' }
      : { code: 200, data: {
          readback_verified: true,
          hotel_id: 5,
          week_start: '2026-08-17',
          week_end: '2026-08-23',
          snapshot_id: 9,
          selected_focus: { title: '补齐事实', reason: '缺口重复出现' },
          lifecycle_summary: { pending_approval: 1, review_pending: 2, reviewed: 3 },
        } },
    getHotelId: () => '5',
    getToday: () => '2026-08-29',
    errorMessage: error => error?.message || '读取失败',
  });
  assert.equal(await controller.loadHomeWeeklyOperatingPlan({ hotelId: 5, weekEnd: '2026-08-23' }), true);
  const verified = controller.homeWeeklyOperatingPlan.value;
  fail = true;
  assert.equal(await controller.loadHomeWeeklyOperatingPlan({ hotelId: 5, weekEnd: '2026-08-23' }), false);
  assert.equal(controller.homeWeeklyOperatingPlan.value, verified);
  assert.match(controller.homeWeeklyOperatingPlanError.value, /刷新失败，保留上次已验证周计划/);
});
