import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const operationStatic = fs.readFileSync('public/operation-static.js', 'utf8');
const systemStatic = fs.readFileSync('public/system-static.js', 'utf8');

const loadOperationStaticApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(operationStatic, context, { filename: 'public/operation-static.js' });
  return context.window.SUXI_OPERATION_STATIC;
};

const loadSystemStaticApi = () => {
  const context = { window: {}, console, setTimeout, clearTimeout };
  vm.runInNewContext(systemStatic, context, { filename: 'public/system-static.js' });
  return context.window.SUXI_SYSTEM_STATIC;
};

const openingStaticKeys = [
  'buildOpeningProjectFormDefaults',
  'normalizeOpeningProjectFormForSubmit',
  'buildOpeningProjectFormFromProject',
  'buildOpeningOverviewCards',
  'buildOpeningCategoryProgressCards',
  'buildOpeningPositioningImpact',
  'buildOpeningTaskStats',
  'buildOpeningTaskProgressCards',
  'buildOpeningTaskProgressStages',
  'buildOpeningStatusFilterChips',
  'buildOpeningAttentionFilterChips',
  'filterOpeningTasks',
  'selectOpeningTasks',
  'areAllFilteredOpeningTasksSelected',
  'pruneOpeningTaskIds',
  'mergeOpeningTaskSelection',
  'buildOpeningAiOutputResult',
  'openingTaskIsOverdue',
  'openingTaskIsDueSoon',
  'openingTaskHasOwner',
  'openingTaskProgressPercent',
  'syncOpeningTaskStatusByProgress',
  'syncOpeningTaskProgressByStatus',
  'buildOpeningTaskUpdatePayload',
  'snapshotOpeningTaskForRollback',
  'openingTaskPatchHasChanges',
  'applyOpeningTaskPatch',
  'normalizeOpeningTaskId',
  'openingTaskDueLabel',
  'openingTaskDueClass',
  'openingTaskProgressStage',
  'openingTaskProgressTextClass',
  'openingRiskText',
  'openingRiskTextClass',
  'openingRiskClass',
  'openingCategories',
  'openingStatusOptions',
  'openingProgressQuickValues',
];

test('opening project helpers have safe setup defaults and are replaced after operation static loads', () => {
  assert.match(html, /let buildOpeningProjectFormDefaults = \(\) => \(\{/);
  assert.match(html, /let buildOpeningProjectFormFromProject = \(\) => buildOpeningProjectFormDefaults\(\);/);
  openingStaticKeys.forEach((key) => {
    assert.match(operationStatic, new RegExp(`\\b${key}\\b`), `${key} must stay exported by operation-static.js`);
    assert.match(html, new RegExp(`requireOperationStatic\\(staticConfig, '${key}'\\)`), `${key} must be bound after operation-static.js loads`);
  });
});

test('opening AI coverage counts every suggestion even when the visible list is capped', () => {
  const tasks = Array.from({ length: 10 }, (_, index) => ({
    id: index + 1,
    task_name: `检查项 ${index + 1}`,
    ai_suggestion: index < 8 ? `建议 ${index + 1}` : '',
    progress: index * 5,
  }));
  const result = loadOperationStaticApi().buildOpeningAiOutputResult({
    tasks,
    stats: { total: 10, highRisk: 0, overdue: 0, blocked: 0 },
  });
  const coverage = result.cards.find(card => card.label === '检查项输出');
  const missing = result.cards.find(card => card.label === '待补齐输出');

  assert.equal(result.taskOutputs.length, 6, 'the visible suggestion list remains capped');
  assert.equal(coverage.value, '80%');
  assert.equal(coverage.hint, '8/10 项带AI建议');
  assert.equal(missing.value, 2);
});

test('opening overview keeps missing checklist ratios distinct from real zero', () => {
  const api = loadOperationStaticApi();
  const truth = {
    status: 'unverified',
    status_label: '未验证',
    metric_scope: 'opening_project',
    failure_reason: '检查清单尚未生成',
  };
  const data = {
    project: { overall_score: null, risk_level: 'medium', opening_date: '2026-08-01' },
    truth_context: truth,
    metrics: {
      days_left: 13,
      overall_score: null,
      total_tasks: 0,
      completed_tasks: 0,
      completion_rate: null,
      core_tasks: 0,
      core_completed_tasks: 0,
      core_completion_rate: null,
      high_risk_count: 0,
      overdue_count: 0,
      ai_penetration_rate: null,
      ai_covered_tasks: 0,
      metric_truth: {
        completion_rate: { ...truth, calculation_status: 'missing' },
      },
    },
  };

  const cards = api.buildOpeningOverviewCards(data);
  const score = cards.find(card => card.metricKey === 'overall_score');
  const completion = cards.find(card => card.metricKey === 'completion_rate');
  const riskCount = cards.find(card => card.metricKey === 'high_risk_count');
  const category = api.buildOpeningCategoryProgressCards([{
    category: 'OTA上线配置',
    total: 0,
    done: 0,
    completion_rate: null,
    truth: { ...truth, calculation_status: 'missing' },
  }])[0];

  assert.equal(score.value, '—');
  assert.equal(completion.value, '—');
  assert.equal(completion.progress, null);
  assert.equal(completion.truth.calculation_status, 'missing');
  assert.equal(riskCount.value, 0, 'a stored zero count remains a real zero');
  assert.equal(category.progress, null);
  assert.equal(category.truth.status, 'unverified');
});

test('execution review action stays unavailable until the recorded review date', () => {
  const api = loadOperationStaticApi();
  const item = {
    execution: { status: 'executed', task_id: 35 },
    review: { status: 'observing', is_available: false },
  };

  assert.equal(api.operationCanReviewExecution(item), false);
  item.review.is_available = true;
  assert.equal(api.operationCanReviewExecution(item), true);
});

test('saved OTA task exposes source readback only after the exact review window', () => {
  const api = loadOperationStaticApi();
  const item = {
    recommendation: { source_module: 'ota_diagnosis_saved' },
    execution: { status: 'executed', task_id: 50 },
    evidence_truth: { source_verified: false },
    review: { status: 'observing', is_available: false },
  };

  assert.equal(api.operationCanReconcileExecution(item), false);
  item.review.is_available = true;
  assert.equal(api.operationCanReconcileExecution(item), true);
  assert.equal(api.operationExecutionActionAvailable(item), true);
  item.evidence_truth.source_verified = true;
  assert.equal(api.operationCanReconcileExecution(item), false);
  assert.match(html, /reconcileOperationExecutionReview/);
  assert.match(html, /execution-tasks\/\$\{taskId\}\/reconcile-review/);
});

test('an executed non-price task can add more manual evidence', () => {
  const api = loadOperationStaticApi();
  const item = {
    recommendation: { object_type: 'campaign' },
    execution: { status: 'executed', task_id: 50 },
    next_action: { key: 'record_evidence' },
  };

  assert.equal(api.operationCanExecuteWithEvidence(item), true);
  item.recommendation.object_type = 'price';
  assert.equal(api.operationCanExecuteWithEvidence(item), false);
  item.recommendation.object_type = 'campaign';
  item.next_action.key = 'none';
  assert.equal(api.operationCanExecuteWithEvidence(item), false);
  assert.match(html, /supplementingExecutedTask/);
  assert.match(html, /`\/operation\/execution-tasks\/\$\{taskId\}\/evidence`/);
  assert.match(html, /operationExecutionEvidenceCount\(persistedTask\) <= previousEvidenceCount/);
  assert.match(html, /&& item\?\.next_action\?\.key === 'record_evidence';/);
  assert.match(html, /item\?\.execution\?\.status === 'executed' \|\| operationExecutionAssignedToCurrentUser\(item\)/);
});

test('legacy OTA conversion intent is shown as a readable operation check', () => {
  const api = loadOperationStaticApi();
  const item = {
    recommendation: {
      object_type: 'campaign',
      action_type: 'booking_conversion_optimization',
    },
  };

  assert.equal(api.operationExecutionActionText(item), '运营核查 · 下单转化核查');
});

test('canonical OTA investigation uses analysis-only source action and status labels', () => {
  const operationApi = loadOperationStaticApi();
  const systemApi = loadSystemStaticApi();
  const actionLabels = {
    list_detail_math_check: '列表到详情转化数学核查',
    detail_fill_breakpoint_check: '详情到填单断点核查',
    fill_submit_chain_check: '填单到提交链路核查',
    same_scope_recollection_eligibility_check: '同范围重采与准入核查',
  };

  Object.entries(actionLabels).forEach(([actionType, expectedLabel]) => {
    const item = {
      recommendation: {
        source_module: 'canonical_ota_investigation',
        object_type: 'operation_checklist',
        action_type: actionType,
      },
    };
    assert.equal(operationApi.operationExecutionSourceText(item), '携程权威数据核查');
    assert.equal(operationApi.operationExecutionActionText(item), `运营核查 · ${expectedLabel}`);
  });

  assert.equal(
    operationApi.operationExecutionSourceText({
      recommendation: {
        source_module: 'canonical_ota_investigation',
        source_record_id: 81818,
      },
    }),
    '携程权威数据核查 · 源行 #81818',
  );

  assert.equal(
    operationApi.operationExecutionSourceText({
      recommendation: {
        source_module: 'canonical_ota_investigation',
        source_record_id: 81866,
        platform: 'meituan',
      },
    }),
    '美团权威数据核查 · 源行 #81866',
  );
  for (const actionType of [
    'meituan_list_detail_count_order_check',
    'meituan_list_detail_rate_check',
    'meituan_observed_flow_rate_alignment_check',
  ]) {
    const actionText = operationApi.operationExecutionActionText({
      recommendation: {
        source_module: 'canonical_ota_investigation',
        object_type: 'operation_checklist',
        action_type: actionType,
        platform: 'meituan',
      },
    });
    assert.match(actionText, /^运营核查 · /);
    assert.doesNotMatch(actionText, /ctrip|填单|提交/i);
  }

  assert.equal(systemApi.operationExecutionStatusLabel('system_authorized_analysis'), '系统授权核查');
  assert.equal(systemApi.operationExecutionStatusClass('system_authorized_analysis'), 'bg-violet-50 text-violet-700');
  assert.notEqual(
    systemApi.operationExecutionStatusClass('system_authorized_analysis'),
    systemApi.operationExecutionStatusClass('approved'),
    '系统授权核查必须与人工审批使用不同视觉语义',
  );
});

test('canonical analysis-only records expose no operator mutation actions', () => {
  const api = loadOperationStaticApi();
  const item = {
    recommendation: { source_module: 'canonical_ota_investigation', object_type: 'operation_checklist' },
    approval: { status: 'system_authorized_analysis' },
    execution: { mode: 'analysis_only', status: 'executed', task_id: 81 },
    evidence_truth: { source_verified: true },
    review: { status: 'observing', is_available: true },
    next_action: { key: 'record_evidence' },
  };

  assert.equal(api.operationCanApproveExecution(item), false);
  assert.equal(api.operationCanExecuteWithEvidence(item), false);
  assert.equal(api.operationCanRecordNodeCheck(item), false);
  assert.equal(api.operationCanReconcileExecution(item), false);
  assert.equal(api.operationCanReviewExecution(item), false);
  assert.equal(api.operationExecutionActionAvailable(item), false);

  const memoryGateStart = html.indexOf('const operationCanSaveOperatingMemory');
  const memoryGateEnd = html.indexOf('let operationExecutionActionAvailable', memoryGateStart);
  assert.ok(memoryGateStart > 0 && memoryGateEnd > memoryGateStart);
  assert.match(
    html.slice(memoryGateStart, memoryGateEnd),
    /!operationIsProtectedSystemAnalysis\(item\)/,
  );
});

test('managed operating questions expose only the explicit human approval action', () => {
  const api = loadOperationStaticApi();
  const item = {
    recommendation: { source_module: 'operating_question', object_type: 'operation_checklist' },
    action_management: { contract_version: 'operation_action_card.v1' },
    approval: { status: 'pending_approval' },
    execution: { mode: '', status: 'pending_create', task_id: 0 },
  };

  assert.equal(api.operationCanApproveExecution(item), true);
  assert.equal(api.operationExecutionActionAvailable(item), true);
});

test('revenue node record keeps one fixed scope and explicit missing-state validation', () => {
  const api = loadOperationStaticApi();
  const sourceScope = api.operationRevenueNodeDialogFields.find(field => field.name === 'source_scope');
  assert.ok(sourceScope.options.some(option => option.value === 'pms_ota_cross_check'));
  assert.equal(api.operationCanRecordNodeCheck({ execution: { status: 'pending_execute', task_id: 31 } }), true);
  assert.equal(api.operationCanRecordNodeCheck({ execution: { status: 'executing', task_id: 31 } }), true);
  assert.equal(api.operationCanRecordNodeCheck({ execution: { status: 'executed', task_id: 31 } }), true);
  assert.equal(api.operationCanRecordNodeCheck({ execution: { status: 'pending_create', task_id: 31 } }), false);

  const form = {
    operating_period: 'weekend',
    source_scope: 'pms_ota_cross_check',
    room_status_alignment: 'operator_confirmed',
    data_quality_status: 'manual_confirmed',
    metric_definition: 'fixed 16:00 denominator',
    comparison_basis: 'same weekend node',
    progress_status: 'normal',
    judgment_basis: 'same-scope pace comparison',
    success_criteria: 'review again at 20:00',
    stop_condition: 'stop on PMS/OTA mismatch',
  };
  const identity = { system_hotel_id: 7, business_date: '2026-08-03' };
  const record = api.buildOperationRevenueNodeRecord(form, '2026-08-03 16:00:00', identity);
  assert.equal(record.contract_version, 'operation_revenue_node.v2');
  assert.equal(record.system_hotel_id, '7');
  assert.equal(record.business_date, '2026-08-03');
  assert.equal(record.comparison_basis, 'same weekend node');
  assert.equal(record.metric_snapshot, '');

  assert.throws(
    () => api.buildOperationRevenueNodeRecord({ ...form, comparison_basis: '' }, '2026-08-03 16:00:00', identity),
    /同节点比较基准/
  );
  assert.throws(() => api.buildOperationRevenueNodeRecord(form, '2026-08-03 16:00:00', {}), /酒店身份/);
  assert.equal(api.operationExecutionNodeRecordText({ evidence_summary: { node_record: { status: 'missing' } } }), '节点检查未记录');
  assert.match(api.operationExecutionNodeRecordText({ evidence_summary: { node_record: { status: 'available', operating_period: 'weekend', room_status_alignment: 'operator_confirmed', progress_status: 'normal' } } }), /周末.*房态人工确认一致.*进度正常/);

  const fields = api.operationRevenueNodeFieldsForItem({ evidence_summary: { node_record: { status: 'available', comparison_basis: 'saved basis' } } });
  assert.equal(fields.find(field => field.name === 'comparison_basis').value, 'saved basis');
});

test('operating goal contract payload keeps explicit thresholds, scopes, dates, and rollback facts', () => {
  const api = loadOperationStaticApi();
  const payload = JSON.parse(JSON.stringify(api.buildOperatingGoalContractPayload({
    primary_objective: 'revenue',
    primary_metric_key: '',
    objective_direction: 'increase',
    adr_min: '268.5',
    occupancy_rate_min: '72',
    rating_min: '4.6',
    cancellation_rate_max: '12',
    minimum_room_rate: '199',
    available_room_count: '3',
    room_types_csv: ' 大床房, 双床房，大床房 ',
    channels_csv: '携程,美团',
    effective_from: '2026-08-12',
    effective_to: '2026-09-12',
    risk_preference: 'balanced',
    operating_phase: '暑期收益爬坡',
    phase_note: ' 先保住评分 ',
    stop_conditions_text: '评分低于4.6\n取消率高于12%\n评分低于4.6',
    rollback_plan: ' 恢复上一版价格与库存 ',
    version_note: ' 暑期换版 ',
  })));

  assert.deepEqual(payload, {
    primary_objective: 'revenue',
    primary_metric_key: 'revenue',
    objective_direction: 'increase',
    guard_metrics: [
      { metric_key: 'adr', operator: '>=', threshold: 268.5 },
      { metric_key: 'occupancy_rate', operator: '>=', threshold: 72 },
      { metric_key: 'rating', operator: '>=', threshold: 4.6 },
      { metric_key: 'cancellation_rate', operator: '<=', threshold: 12 },
    ],
    operating_constraints: [
      { constraint_key: 'minimum_room_rate', operator: '>=', value: 199 },
      { constraint_key: 'available_room_count', operator: '>=', value: 3 },
      { constraint_key: 'room_types', operator: 'in', value: ['大床房', '双床房'] },
      { constraint_key: 'channels', operator: 'in', value: ['携程', '美团'] },
    ],
    risk_preference: 'balanced',
    operating_phase: '暑期收益爬坡',
    phase_note: '先保住评分',
    stop_conditions: ['评分低于4.6', '取消率高于12%'],
    rollback_plan: '恢复上一版价格与库存',
    effective_from: '2026-08-12',
    effective_to: '2026-09-12',
    version_note: '暑期换版',
  });
  assert.equal(
    api.operatingGoalContractText({ version_no: 3, primary_objective: 'revenue', operating_phase: '暑期收益爬坡', effective_from: '2026-08-12', effective_to: '2026-09-12' }),
    'v3 · 收入 · 暑期收益爬坡 · 2026-08-12~2026-09-12',
  );
});

test('operating goal monitor status requires an exact persisted heartbeat', () => {
  const api = loadOperationStaticApi();
  const notRun = api.operatingGoalMonitorStatusModel({ hotel_id: 80, monitor: { status: 'not_run' } }, 80);
  assert.equal(notRun.state, 'inactive');
  assert.equal(notRun.label, '智能监控未运行');

  const monitoring = api.operatingGoalMonitorStatusModel({
    hotel_id: 80,
    monitor: {
      status: 'ready',
      monitor_state: 'monitoring',
      business_date: '2026-08-12',
      last_observed_at: '2026-08-12 09:00:00',
      data_gaps: [],
    },
  }, 80);
  assert.equal(monitoring.state, 'monitoring');
  assert.equal(monitoring.label, '智能监控运行中');
  assert.match(monitoring.detail, /经营日 2026-08-12.*最近核验/);

  const attention = api.operatingGoalMonitorStatusModel({
    hotel_id: 80,
    monitor: {
      status: 'ready',
      monitor_state: 'attention',
      data_gaps: ['guard_metric_unavailable'],
    },
  }, 80);
  assert.equal(attention.state, 'attention');
  assert.match(attention.detail, /1 个数据缺口/);

  const mismatch = api.operatingGoalMonitorStatusModel({
    hotel_id: 81,
    monitor: { status: 'ready', monitor_state: 'monitoring' },
  }, 80);
  assert.equal(mismatch.state, 'unknown');
  assert.equal(mismatch.label, '监控身份不一致');
});

test('operating goal contract rejects missing or invalid protection thresholds', () => {
  const api = loadOperationStaticApi();
  const form = {
    primary_objective: 'profit',
    objective_direction: 'preserve',
    adr_min: 260,
    occupancy_rate_min: 70,
    rating_min: 4.5,
    cancellation_rate_max: 10,
    minimum_room_rate: 188,
    available_room_count: 0,
    room_types_csv: '大床房',
    channels_csv: '携程',
    effective_from: '2026-08-12',
    effective_to: '2026-08-31',
    risk_preference: 'conservative',
    operating_phase: '保守观察',
    stop_conditions_text: '评分越界立即停止',
    rollback_plan: '恢复上一版合同',
  };

  assert.throws(() => api.buildOperatingGoalContractPayload({ ...form, adr_min: '' }), /ADR保护阈值/);
  assert.throws(() => api.buildOperatingGoalContractPayload({ ...form, rating_min: 5.1 }), /评分保护阈值范围无效/);
  assert.throws(() => api.buildOperatingGoalContractPayload({ ...form, effective_to: '2026-08-01' }), /截止日期不能早于生效日期/);
});

test('intervention learning exposes latest intent assessment with three-state and unassessed mappings', () => {
  const api = loadOperationStaticApi();
  const expected = {
    supported: ['证据支持', 'bg-green-50 text-green-700'],
    contradicted: ['证据反驳', 'bg-red-50 text-red-700'],
    indeterminate: ['证据不足', 'bg-amber-50 text-amber-700'],
    '': ['未判定', 'bg-gray-50 text-gray-600'],
  };
  Object.entries(expected).forEach(([verdict, [label, className]]) => {
    assert.equal(api.operationLearningVerdictLabel(verdict), label);
    assert.equal(api.operationLearningVerdictClass(verdict), className);
  });

  const model = JSON.parse(JSON.stringify(api.operationInterventionLearningModel(
    { id: 41 },
    {
      interventions: [
        { id: 7, intent_id: 41, version_no: 1, contract_status: 'retrospective', latest_assessment: { verdict: 'contradicted', result_summary: '旧判定' } },
        { id: 9, intent_id: 41, version_no: 2, contract_status: 'prospective', latest_assessment: { verdict: 'supported', result_summary: '收入达标且保护指标未越界' } },
        { id: 10, intent_id: 42, version_no: 3, contract_status: 'prospective', latest_assessment: { verdict: 'indeterminate', result_summary: '他的判定' } },
      ],
    },
  )));
  assert.deepEqual(model, {
    contract_status: 'prospective',
    verdict: 'supported',
    label: '证据支持',
    className: 'bg-green-50 text-green-700',
    summary: '收入达标且保护指标未越界',
  });

  assert.deepEqual(
    JSON.parse(JSON.stringify(api.operationInterventionLearningModel({ id: 99 }, { interventions: [] }))),
    {
      contract_status: '',
      verdict: '',
      label: '未判定',
      className: 'bg-gray-50 text-gray-600',
      summary: '尚未登记经营干预',
    },
  );
});
