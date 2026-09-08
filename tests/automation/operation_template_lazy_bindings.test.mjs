import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const operationSource = fs.readFileSync('public/operation-static.js', 'utf8');
const exportsStart = source.lastIndexOf('operationExecutionBottleneckText, operationExecutionMoneyStatusText, operationExecutionMoneyStatusClass, operationExecutionNextActionClass,');
assert.ok(exportsStart > 0, 'actual setup export anchor must exist');
const helperStart = source.indexOf('\n', exportsStart) + 1;
const helperEnd = source.indexOf('operationExecutionTraceRows,', helperStart) + 'operationExecutionTraceRows,'.length;
const exportExpression = source.slice(helperStart, helperEnd);
const names = [
  'operationCanApproveExecution', 'operationCanStartExecution', 'operationCanCancelExecution',
  'operationCanExecuteWithEvidence', 'operationCanRecordNodeCheck', 'operationCanReconcileExecution',
  'operationCanReviewExecution', 'operationCanSaveOperatingMemory', 'operationExecutionActionAvailable',
  'operationExecutionRowClass', 'operationExecutionTraceRows',
];

function mountedTemplate() {
  const context = vm.createContext(Object.fromEntries(names.map(name => [name, () => false])));
  const loaded = { window: {} };
  vm.runInNewContext(operationSource, loaded);
  vm.runInContext(`globalThis.template = { ${exportExpression} };`, context);
  // Vue keeps the setup return object. Lazy reassignment must reach these same references.
  Object.assign(context, loaded.window.SUXI_OPERATION_STATIC);
  return context.template;
}

test('already mounted template uses the loaded review helper for an eligible manual task', () => {
  const template = mountedTemplate();
  assert.equal(template.operationCanReviewExecution({
    recommendation: { source_module: 'operating_question' },
    execution: { status: 'executed', task_id: 7 }, review: { status: 'observing', is_available: true },
  }), true);
});

test('already mounted template keeps analysis-only records free of operator actions', () => {
  const template = mountedTemplate();
  const item = { recommendation: { source_module: 'canonical_ota_investigation' },
    approval: { status: 'system_authorized_analysis' }, execution: { status: 'executed', task_id: 7, mode: 'analysis_only' },
    review: { status: 'observing', is_available: true } };
  for (const name of ['operationCanApproveExecution', 'operationCanExecuteWithEvidence', 'operationCanRecordNodeCheck', 'operationCanReviewExecution', 'operationCanReconcileExecution']) {
    assert.equal(template[name](item), false, name);
  }
});

test('already mounted template honors a historical terminal review after truth downgrade', () => {
  const template = mountedTemplate();
  const item = { recommendation: { source_module: 'operating_question' },
    execution: { status: 'executed', task_id: 7 }, review: { status: 'unverified', reported_status: 'success', is_available: true } };
  assert.equal(template.operationCanReviewExecution(item), false);
  assert.equal(template.operationCanReconcileExecution(item), false);
});

test('source label keeps the recorded module instead of treating every stored row as manual', () => {
  const loaded = { window: {} };
  vm.runInNewContext(operationSource, loaded);
  const operationExecutionSourceText = loaded.window.SUXI_OPERATION_STATIC.operationExecutionSourceText;
  assert.match(operationExecutionSourceText({ recommendation: { source: 'manual', source_module: 'canonical_ota_investigation', source_record_id: 22, platform: 'ctrip' } }), /携程权威数据核查/);
  assert.equal(operationExecutionSourceText({ recommendation: { source: 'manual', source_module: 'ota_diagnosis_saved' } }), 'OTA诊断行动');
  assert.equal(operationExecutionSourceText({ recommendation: { source: 'manual' } }), '人工创建');
});
