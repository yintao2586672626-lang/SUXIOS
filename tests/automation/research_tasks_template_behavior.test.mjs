import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import * as Vue from 'vue';

// Mount the actual fragments in Vue's renderer. The host is an in-memory tree;
// responsive geometry and native details keyboard behavior belong to browser QA.
const templates = Object.fromEntries([
  ['research', '19-page-revenue-research-center.html'],
  ['tasks', '17-page-ops-track.html'],
].map(([key, file]) => [key, readFileSync(`resources/frontend/templates/fragments/${file}`, 'utf8')]));

function node(tag, value = '') {
  return {
    tag, value, props: {}, children: [], parent: null, listeners: {},
    addEventListener(name, listener) { this.listeners[name] = listener; },
    removeEventListener(name) { delete this.listeners[name]; },
    get options() { return this.children.filter(child => child.tag === 'option'); },
  };
}

const renderer = Vue.createRenderer({
  createElement: tag => node(tag),
  createText: value => node('#text', value),
  createComment: value => node('#comment', value),
  setText: (target, value) => { target.value = value; },
  setElementText: (target, value) => { target.value = value; target.children = []; },
  patchProp(target, key, _old, value) { target.props[key] = value; if (key === 'value') target.value = value; },
  insert(target, parent, anchor = null) {
    if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1);
    const index = anchor ? parent.children.indexOf(anchor) : -1;
    if (index < 0) parent.children.push(target); else parent.children.splice(index, 0, target);
    target.parent = parent;
  },
  remove(target) { if (target.parent) target.parent.children.splice(target.parent.children.indexOf(target), 1); },
  parentNode: target => target.parent,
  nextSibling: target => target.parent?.children[target.parent.children.indexOf(target) + 1] || null,
});

const descendants = root => [root, ...root.children.flatMap(descendants)];
const textOf = root => root.tag === '#comment' ? '' : [root.value, ...root.children.map(textOf)].join(' ');
const byId = (root, id) => descendants(root).find(entry => entry.props['data-testid'] === id);
const buttons = root => descendants(root).filter(entry => entry.tag === 'button');
const buttonText = root => buttons(root).map(entry => textOf(entry).trim());
const button = (root, text) => buttons(root).find(entry => textOf(entry).trim() === text);

function mount(key, state) {
  const root = node('root');
  const { code } = compile(templates[key], { mode: 'function', prefixIdentifiers: true });
  const render = new Function('Vue', code)(Vue);
  const app = renderer.createApp({ setup: () => state, render });
  app.config.warnHandler = warning => { throw new Error(warning); };
  app.component('manager-capability-panel', { props: ['hotelId', 'request'], render: () => Vue.h('aside') });
  app.component('operation-task-workflow-panel', { props: ['hotelId', 'request', 'context', 'canExecute'], render: () => Vue.h('aside') });
  app.component('term-help', { props: ['term'], render: () => Vue.h('span', 'ROI') });
  app.component('ai-decision-quality-details', {
    props: ['item'],
    render() { return Vue.h('div', { 'data-quality-title': this.item.title }, this.item.quality_note || ''); },
  });
  app.mount(root);
  return { root, state, unmount: () => app.unmount() };
}

function researchState(result) {
  const calls = [];
  const state = Vue.reactive({
    currentPage: 'revenue-research-center', revenueResearchHotelId: '80',
    revenueResearchCatalogLoading: false, revenueResearchCatalogError: '',
    retryRevenueResearchCatalog: () => calls.push(['retry-catalog']),
    revenueResearchHotelOptions: [{ id: 80, name: '合成测试门店' }],
    revenueResearchProducts: [{ key: 'sample', name: '合成研究', status: 'ready', statusText: '可研究', module: 'sample-module' }],
    revenueResearchSteps: ['读取', '研究'],
    run: { loading: false, error: '', result, executionError: '', executionIntent: null },
    revenueResearchRunFor: () => state.run,
    revenueResearchStatusClass: () => '', revenueResearchStepClass: () => '', revenueResearchStepIcon: () => '',
    revenueResearchResultStatusClass: () => '', revenueResearchResultStatusLabel: () => '待核验',
    revenueResearchReadinessClass: () => '', revenueResearchMissingText: () => '概要缺口',
    revenueResearchForecastCards: () => [], revenueResearchCanCreateExecutionIntent: () => false,
    revenueResearchExecutionBlockedText: () => '测试研究不可转执行',
    runRevenueResearchProduct: item => calls.push(['generate', item.key]),
    openRevenueResearchModule: item => calls.push(['module', item.key]),
  });
  return { state, calls };
}

test('complete research disclosure renders every returned entry and preserves both recommendation formats', async () => {
  const list = label => Array.from({ length: 7 }, (_, i) => `${label}${i + 1}`);
  const summary = `完整首段\n${'本次已返回的完整摘要。'.repeat(80)}\n完整末段`;
  const result = {
    status: 'pending_data', model_key: 'synthetic',
    local_sources: list('来源').map(label => ({ label, count: 2, summary: `${label}的完整说明` })),
    gaps: list('信息缺口').map(label => ({ label, reason: `${label}需要补齐` })),
    readiness: { stage: 'research_data_gaps_pending', missing_evidence: list('就绪缺口').map(label => ({ label, next_action: `补齐${label}` })) },
    web_sources: [{ title: '引用末项', url: 'https://example.test/research' }],
    result: {
      summary, risk_signals: list('风险'),
      decision_recommendations: list('建议').map(title => ({ title, priority: 'P1', action: `执行${title}`, quality_note: `${title}的质量详情` })),
      recommended_actions: list('原始建议'), forecast_assumptions: list('情景假设'), key_metrics: list('关键指标'), data_gaps: list('研究缺口'),
      confidence_note: '合成样本仅验证展示', next_review_date: '2026-09-12',
    },
  };
  const { state, calls } = researchState(result);
  const mounted = mount('research', state);
  try {
    const detail = byId(mounted.root, 'revenue-research-full-result-sample');
    assert.equal(detail.tag, 'details');
    assert.equal(detail.props.open, undefined, 'full result starts collapsed');
    assert.equal(detail.props.onToggle, undefined, 'opening does not issue a request');
    assert.equal(detail.children.find(child => child.tag === 'summary').props.onClick, undefined);
    assert.ok(textOf(byId(detail, 'revenue-research-full-summary-sample')).includes(summary));
    assert.ok(!String(byId(detail, 'revenue-research-full-summary-sample').props.class).includes('line-clamp'));
    for (const expected of ['来源7的完整说明', '信息缺口7需要补齐', '补齐就绪缺口7', '风险7', '执行建议7', '建议7的质量详情', '原始建议7', '情景假设7', '关键指标7', '研究缺口7', '引用末项', '合成样本仅验证展示', '2026-09-12']) {
      assert.ok(textOf(detail).includes(expected), `full result must contain ${expected}`);
    }
    assert.match(textOf(byId(mounted.root, 'revenue-research-result-counts-sample')), /信息缺口 7 项.*就绪条件缺口 7 项.*研究缺口 7 项/s);
    assert.equal(descendants(detail).filter(entry => entry.props['data-quality-title']).length, 7);
    assert.deepEqual(calls, []);
    state.run.result = { status: 'pending_data', result: { summary: '第二次研究摘要' } };
    await Vue.nextTick();
    assert.ok(!textOf(byId(mounted.root, 'revenue-research-full-result-sample')).includes('来源7'));
    assert.ok(textOf(byId(mounted.root, 'revenue-research-full-result-sample')).includes('第二次研究摘要'));
    assert.deepEqual(calls, []);
  } finally { mounted.unmount(); }
});

test('research result scope remains tied to returned identity when the current selector changes', async () => {
  for (const [scope, expected] of [
    [{ hotel_id: 80, hotel_name: '结果原门店' }, ['结果原门店', '酒店 #80']],
    [{ hotel_id: 80 }, ['酒店 #80']],
    [{ hotel_name: '仅返回名称的门店' }, ['仅返回名称的门店']],
    [{ mode: 'all_permitted_hotels', hotel_ids: [80, 82] }, ['本次返回的可见门店', '酒店 #80、#82']],
    [undefined, ['结果范围未返回，请核对后使用']],
  ]) {
    const { state, calls } = researchState({ hotel_scope: scope, result: { summary: '已有研究结果' } });
    const mounted = mount('research', state);
    try {
      const before = textOf(byId(mounted.root, 'revenue-research-result-scope-sample'));
      for (const value of expected) assert.ok(before.includes(value), `caption must include ${value}`);
      state.revenueResearchHotelOptions.push({ id: 81, name: '当前新选门店' });
      state.revenueResearchHotelId = '81';
      await Vue.nextTick();
      assert.equal(textOf(byId(mounted.root, 'revenue-research-result-scope-sample')), before);
      state.revenueResearchHotelId = '';
      await Vue.nextTick();
      assert.equal(textOf(byId(mounted.root, 'revenue-research-result-scope-sample')), before);
      assert.deepEqual(calls, []);
    } finally { mounted.unmount(); }
  }
});

test('legacy and missing research results stay readable without invented successful content', () => {
  for (const result of [
    { result: { recommended_actions: ['历史一', '历史二', '历史三', '历史四', '历史第五项'] } },
    { result: {} },
  ]) {
    const { state } = researchState(result);
    const mounted = mount('research', state);
    try {
      const detail = byId(mounted.root, 'revenue-research-full-result-sample');
      assert.ok(textOf(detail).includes('本次未返回可展示的研究摘要'));
      assert.ok(textOf(detail).includes('本次未返回来源记录'));
      if (result.result.recommended_actions) {
        assert.ok(textOf(detail).includes('历史建议结构不完整'));
        assert.ok(textOf(detail).includes('历史第五项'));
      } else {
        assert.ok(textOf(detail).includes('本次未返回建议动作'));
        assert.ok(!textOf(detail).includes('等待模型生成'));
      }
    } finally { mounted.unmount(); }
  }
});

function taskState(items = []) {
  const calls = [];
  const invoke = name => (...args) => calls.push([name, ...args.map(value => value?.id ?? value)]);
  const state = Vue.reactive({
    currentPage: 'ops-track', authContext: { permissionStatus: 'allowed' }, operationFinanceCanExecute: false,
    operationFilters: { hotel_id: '80' }, operationHotelOptions: [{ id: 80, name: '合成测试门店' }],
    operationLoading: { actions: false }, operationError: { actions: '' }, operationExecutionViewMode: 'mine',
    operationExecutionStages: [{ key: 'review', label: '效果复盘', count: 1 }], operationExecutionStageFilter: '', operationExecutionStageFilterLabel: '全部阶段',
    operationExecutionSummaryCards: [], operationExecutionTraceRows: [], operationExecutionBottleneckText: '尚未形成结论',
    operationExecutionMoneyStatusClass: '', operationExecutionMoneyStatusText: '证据不足',
    operationExecutionItems: items, operationExecutionFilteredItems: items, operationActions: [],
    aiDailyReportTaskReturn: null, currentOperatingGoalContract: null, currentOperatingGoalContractText: '未建立',
    operatingGoalMonitorModel: { label: '未建立', detail: '无目标' }, operatingGoalInterventionLoading: false,
    operatingGoalInterventionOverview: {}, operatingGoalInterventionDataGapText: '', operatingGoalInterventionSummary: {},
    managerCapabilityRequest: invoke('managerRequest'),
    memoBody: '', operationEffectValidation: { status: 'missing' }, operationEffectMetricCards: [], operationEffectDataGapText: '缺证',
    operationEvidenceModalOpen: false, operationReviewModalOpen: false,
    operationExecutionRowClass: () => '', operationExecutionStatusClass: () => '', operationExecutionNextActionClass: () => '',
    operationExecutionStatusLabel: status => status || '状态未返回',
    operationExecutionActionText: item => item.action || '动作未返回', operationExecutionSourceText: () => '本地研究',
    operationInterventionLearningModelForItem: () => ({ label: '无干预', summary: '证据不足' }),
    nodeText: () => '节点证据未返回', operationExecutionReviewText: () => '复盘未取得同口径证据', operationExecutionRoiText: () => 'ROI缺证',
    operationEffectStatusClass: () => '', operationEffectStatusLabel: () => '缺证',
    operationApprovalConfirming: () => false, operationApprovalText: () => '审批', operationRejectText: () => '拒绝',
    operationExecutionActionAvailable: item => Boolean(item.allowed?.length),
  });
  for (const method of [
    'loadOperationActions', 'setOperationExecutionViewMode', 'setOperationExecutionStageFilter', 'openOperationPendingReviews',
    'returnToAiDailyReport', 'approveOperationExecutionIntent', 'rejectOrCancelOperationApproval', 'startOperationExecutionTask',
    'recordOperationRevenueNodeCheck', 'recordOperationExecutionEvidence', 'cancelOperationExecution', 'reconcileOperationExecutionReview',
    'reviewOperationExecutionTask', 'openOperatingInterventionForm', 'saveMemo', 'openOperatingGoalContractForm',
  ]) state[method] = invoke(method);
  for (const name of ['ApproveExecution', 'StartExecution', 'RecordNodeCheck', 'ExecuteWithEvidence', 'CancelExecution', 'ReconcileExecution', 'ReviewExecution', 'DefineIntervention', 'AssessIntervention']) {
    state[`operationCan${name}`] = item => Boolean(item.allowed?.includes(name));
  }
  state.canSaveMemo = item => Boolean(item.allowed?.includes('SaveMemo'));
  return { state, calls };
}

const taskItem = allowed => ({
  id: 41, action: '复核本渠道可售房型与价格', allowed,
  execution: { task_id: 91, status: 'approved' }, approval: { status: 'approved' },
  recommendation: { platform: 'ctrip', date_start: '2026-09-08' },
  assignment: { status: 'scheduled', assignee_id: 7, due_at: '2026-09-09 18:00', review_at: '2026-09-10 10:00' },
  next_action: { label: '核对后记录执行证据' },
});

test('mobile task card surfaces action, assignee, deadline, status and next step before secondary evidence', () => {
  const { state, calls } = taskState([taskItem(['StartExecution'])]);
  state.aiDailyReportTaskReturn = { reportId: 15, reportDate: '2026-09-08', intentId: 41 };
  const mounted = mount('tasks', state);
  try {
    const mobile = byId(mounted.root, 'operation-mobile-task-list');
    for (const expected of ['复核本渠道可售房型与价格', '用户 #7', '2026-09-09 18:00', 'approved', '核对后记录执行证据']) assert.ok(textOf(mobile).includes(expected));
    assert.equal(byId(mobile, 'operation-mobile-task-evidence').props.open, undefined);
    assert.ok(String(mobile.props.class).includes('lg:hidden'));
    assert.ok(String(byId(mounted.root, 'operation-desktop-task-table').props.class).includes('lg:block'));
    for (const id of ['operation-advanced-tools', 'operation-execution-overview']) assert.equal(byId(mounted.root, id).props.open, undefined);
    const allNodes = descendants(mounted.root);
    assert.ok(allNodes.indexOf(mobile) < allNodes.indexOf(byId(mounted.root, 'operation-advanced-tools')));
    byId(mounted.root, 'operation-my-tasks-tab').props.onClick();
    byId(mounted.root, 'operation-pending-reviews-tab').props.onClick();
    button(byId(mounted.root, 'operation-daily-report-return'), '返回日报并刷新进展').props.onClick();
    button(mobile, '开始任务').props.onClick();
    assert.deepEqual(calls, [['setOperationExecutionStageFilter', ''], ['setOperationExecutionViewMode', 'mine'], ['openOperationPendingReviews'], ['returnToAiDailyReport'], ['startOperationExecutionTask', 41]]);
    assert.equal(state.operationFilters.hotel_id, '80');
  } finally { mounted.unmount(); }
});

test('mobile and desktop preserve the same gated actions and dispatch original handlers', () => {
  const scenarios = [
    { allowed: ['ApproveExecution', 'CancelExecution'], labels: ['审批', '拒绝'], handlers: ['approveOperationExecutionIntent', 'rejectOrCancelOperationApproval'] },
    { allowed: ['StartExecution', 'RecordNodeCheck', 'ExecuteWithEvidence', 'CancelExecution', 'DefineIntervention', 'SaveMemo'], labels: ['开始任务', '节点检查', '录证据', '取消', '定义干预', '沉淀记忆'], handlers: ['startOperationExecutionTask', 'recordOperationRevenueNodeCheck', 'recordOperationExecutionEvidence', 'cancelOperationExecution', 'openOperatingInterventionForm', 'saveMemo'] },
    { allowed: ['ReconcileExecution', 'ReviewExecution'], labels: ['读取事实'], handlers: ['reconcileOperationExecutionReview'] },
    { allowed: ['ReviewExecution'], labels: ['复盘'], handlers: ['reviewOperationExecutionTask'] },
    { allowed: [], labels: [], handlers: [] },
  ];
  for (const scenario of scenarios) {
    const { state, calls } = taskState([taskItem(scenario.allowed)]);
    const mounted = mount('tasks', state);
    try {
      const mobile = byId(mounted.root, 'operation-mobile-actions');
      const desktop = byId(mounted.root, 'operation-desktop-task-table');
      assert.deepEqual(buttonText(mobile), scenario.labels);
      assert.deepEqual(buttonText(desktop), scenario.labels);
      for (const entry of buttons(mobile)) entry.props.onClick();
      assert.deepEqual(calls.map(call => call[0]), scenario.handlers);
      assert.ok(calls.every(call => call[1] === 41));
      if (scenario.allowed.includes('ApproveExecution')) assert.equal(calls[0][2], true);
    } finally { mounted.unmount(); }
  }
});

test('permission pending, failures and missing assignments retain honest UI states', async () => {
  const { state } = taskState([{ ...taskItem([]), assignment: null }]);
  const mounted = mount('tasks', state);
  try {
    const mobile = byId(mounted.root, 'operation-mobile-task-list');
    assert.ok(textOf(mobile).includes('尚未指派'));
    assert.ok(textOf(mobile).includes('尚未设置'));
    state.operationLoading.actions = true;
    await Vue.nextTick();
    assert.equal(byId(mounted.root, 'operation-my-tasks-tab').props.disabled, true);
    assert.equal(byId(mounted.root, 'operation-pending-reviews-tab').props.disabled, true);
    state.authContext.permissionStatus = 'pending';
    await Vue.nextTick();
    assert.ok(byId(mounted.root, 'operation-access-pending'));
    assert.equal(byId(mounted.root, 'operation-mobile-task-list'), undefined);
    assert.equal(byId(mounted.root, 'operation-desktop-task-table'), undefined);
    state.authContext.permissionStatus = 'allowed';
    state.operationLoading.actions = false;
    state.operationExecutionItems = [];
    state.operationExecutionFilteredItems = [];
    state.operationError.actions = '当前门店读取失败';
    await Vue.nextTick();
    assert.ok(textOf(mounted.root).includes('当前门店读取失败'));
    assert.ok(!textOf(mounted.root).includes('暂无执行闭环记录'));
    assert.ok(!textOf(mounted.root).includes('暂无策略动作'));
  } finally { mounted.unmount(); }
});
