import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const operationStatic = fs.readFileSync('public/operation-static.js', 'utf8');

const loadOperationStaticApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(operationStatic, context, { filename: 'public/operation-static.js' });
  return context.window.SUXI_OPERATION_STATIC;
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
