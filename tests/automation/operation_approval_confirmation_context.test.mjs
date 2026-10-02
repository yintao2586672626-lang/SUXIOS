import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { sourceDeclaration } from './helpers/source_declaration.mjs';

const file = 'public/app-main.js';
const source = readFileSync(file, 'utf8').replaceAll('\r\n', '\n');
const start = '            const operationApprovalConfirmingIntentId = ref(0);';
const end = '            const startOperationExecutionTask = async (item) => {';
const startAt = source.indexOf(start);
const endAt = source.indexOf(end, startAt + start.length);
assert.ok(startAt >= 0 && endAt > startAt, 'extracts the original approval confirmation and mutation handlers');
const approvalSource = source.slice(startAt, endAt);
const sourceDigest = createHash('sha256').update(approvalSource).digest('hex').toUpperCase();
const loaderStart = '            const loadOperationActions = async (options = {}) => {\n';
const loaderEnd = '                let requestHotelId = String(operationFilters.value.hotel_id || \'\').trim();';
const loaderStartAt = source.indexOf(loaderStart);
const loaderEndAt = source.indexOf(loaderEnd, loaderStartAt + loaderStart.length);
assert.ok(loaderStartAt >= 0 && loaderEndAt > loaderStartAt, 'extracts the original refresh invalidation boundary');
const loaderPrelude = source.slice(loaderStartAt + loaderStart.length, loaderEndAt);
const refreshReset = loaderPrelude.match(/operationApprovalConfirmingIntentId\.value = 0;/)?.[0];
const refreshResetAt = source.indexOf(refreshReset, loaderStartAt);
assert.ok(refreshReset && refreshResetAt < loaderEndAt, 'refresh clears confirmation before issuing the request');
assert.match(loaderPrelude, /operationActions\.value = \[\];/);
const operationStaticSource = readFileSync('public/operation-static.js', 'utf8');
const runtime = { window: {} };
vm.runInNewContext(operationStaticSource, runtime);
const approvalBody = sourceDeclaration(operationStaticSource, 'runApproveOperationExecutionIntent');

function harness() {
  const toasts = [];
  let mutationContextCalls = 0;
  const sandbox = {
    ref: Vue.ref,
    operationFilters: Vue.ref({ hotel_id: '7' }),
    currentPage: Vue.ref('ops-track'),
    operationLoading: Vue.ref({ actions: false }),
    operationActions: Vue.ref([]),
    operationActionsRequestSeq: 4,
    showToast: (...args) => toasts.push(args),
    captureOperationExecutionMutationContext: () => { mutationContextCalls++; throw new Error('unexpected approval mutation'); },
    operationIsManagedAction: () => false,
    operationAiDailyApprovalDateWarning: () => '',
    operationErrorMessage: error => error.message,
    formatDate: undefined, openWorkflowFormDialog: undefined, user: undefined,
    normalizeOperationEvidenceDateTime: undefined, assertOperationExecutionMutationContextCurrent: undefined,
    apiRequest: undefined, readOperationExecutionIntent: undefined,
    assertOperationExecutionMutationDigestReadback: undefined, loadOperationActions: undefined,
    window: runtime.window,
    requireOperationStatic: (api, name) => { assert.equal(typeof api[name], 'function'); return api[name]; },
  };
  const requestSequenceStatement = loaderPrelude.match(/const requestSeq = \+\+operationActionsRequestSeq;/)[0];
  const actionReset = loaderPrelude.match(/operationActions\.value = \[\];/)[0];
  const actions = vm.runInNewContext(`${approvalSource}\nconst resetFromOriginalLoad = () => {\n${requestSequenceStatement}\n${refreshReset}\n${actionReset}\n};\n({ operationApprovalConfirmingIntentId, operationApprovalConfirming, operationApprovalText, operationRejectText, approveOperationExecutionIntent, rejectOrCancelOperationApproval, resetFromOriginalLoad })`, sandbox);
  return { actions, sandbox, toasts, mutationCalls: () => mutationContextCalls };
}

const item = change => ({
  id: 91,
  hotel_id: 7,
  status: 'pending',
  recommendation: { source_module: 'manual', summary: 'Synthetic approval intent', ...change },
});

test('same intent confirmation is invalidated when the pending intent content changes in place', async () => {
  const h = harness();
  const current = item({ expected_metric: 'occupancy' });
  await h.actions.approveOperationExecutionIntent(current, true);
  assert.equal(h.actions.operationApprovalConfirming(current), true, 'first click arms the unchanged row');
  assert.equal(h.actions.operationApprovalText(current), '确认审批');

  current.recommendation.expected_metric = 'room_revenue';
  h.actions.resetFromOriginalLoad(); // the original list loader invalidates before replacing pending rows
  assert.equal(h.actions.operationApprovalConfirming(current), false, 'a refreshed row with changed approval content requires a fresh confirmation');
  assert.equal(h.actions.operationApprovalText(current), '审批');
  assert.equal(h.actions.operationRejectText(current), '驳回');
  await h.actions.approveOperationExecutionIntent(current, true);
  assert.equal(h.mutationCalls(), 0, 'the next click only re-arms confirmation and never reaches the approval mutation');
  assert.deepEqual(h.toasts.map(row => row[0]), ['请再次点击“确认审批”', '请再次点击“确认审批”']);
});

test('refresh generation, selected hotel, and page are all part of the pending confirmation context', async () => {
  for (const change of [
    h => { h.sandbox.operationActionsRequestSeq++; },
    h => { h.sandbox.operationFilters.value.hotel_id = '8'; },
    h => { h.sandbox.currentPage.value = 'home'; },
  ]) {
    const h = harness();
    const current = item({ expected_metric: 'occupancy' });
    await h.actions.approveOperationExecutionIntent(current, true);
    change(h);
    h.actions.resetFromOriginalLoad();
    assert.equal(h.actions.operationApprovalConfirming(current), false, 'changed scope cannot reuse the first click');
    await h.actions.approveOperationExecutionIntent(current, true);
    assert.equal(h.mutationCalls(), 0, 'scope-change retry only arms a new confirmation');
  }
});

test('explicit cancel clears the current confirmation without attempting approval', async () => {
  const h = harness();
  const current = item({ expected_metric: 'occupancy' });
  await h.actions.approveOperationExecutionIntent(current, true);
  assert.equal(h.actions.operationApprovalConfirming(current), true);
  await h.actions.rejectOrCancelOperationApproval(current);
  assert.equal(h.actions.operationApprovalConfirming(current), false);
  assert.equal(h.mutationCalls(), 0);
  assert.equal(h.toasts.at(-1)[0], '已取消审批确认');
});

test('test oracle is tied to the original approval-handler source section', () => {
  assert.match(sourceDigest, /^[A-F0-9]{64}$/);
  assert.match(approvalBody, /captureOperationExecutionMutationContext\(item/);
  assert.match(approvalSource, /operationApprovalConfirming\(item\)/);
  assert.match(loaderPrelude, /const requestSeq = \+\+operationActionsRequestSeq;/);
  assert.equal(refreshResetAt > loaderStartAt, true);
  assert.match(refreshReset, /operationApprovalConfirmingIntentId\.value = 0;/);
  assert.match(loaderPrelude, /operationActions\.value = \[\];/);
});

function dateGuardHarness({ confirming = true, afterCapture = () => {} } = {}) {
  const day = { value: '2026-09-30' }, calls = [], toasts = [];
  const warning = vm.runInNewContext(`${sourceDeclaration(source, 'operationAiDailyApprovalDateWarning')}\noperationAiDailyApprovalDateWarning`, {
    shanghaiToday: () => day.value,
  });
  const context = {
    operationLoading: { value: { actions: false } }, operationApprovalConfirmingIntentId: { value: 91 },
    operationApprovalConfirming: () => confirming, operationAiDailyApprovalDateWarning: warning,
    showToast: (...args) => toasts.push(args), operationIsManagedAction: () => false,
    operationErrorMessage: error => error.message,
    captureOperationExecutionMutationContext: () => { calls.push('capture'); afterCapture(day); return { intentId: 91, hotelId: 7 }; },
    assertOperationExecutionMutationContextCurrent: () => {},
    openWorkflowFormDialog: async () => ({ remark: 'Synthetic rejection reason' }),
    apiRequest: async (url, options) => { calls.push({ url, body: JSON.parse(options.body) }); return { code: 200, data: { id: 91 } }; },
    readOperationExecutionIntent: async (id, hotelId) => { assert.equal(id, 91); assert.equal(hotelId, 7); calls.push('read'); return { id, status: 'rejected' }; },
    assertOperationExecutionMutationDigestReadback: () => {}, loadOperationActions: async () => {},
  };
  const run = approved => runtime.window.SUXI_OPERATION_STATIC.runApproveOperationExecutionIntent(context, {
    ...item(), approval: { status: 'pending_approval' },
    recommendation: { source_module: 'ai_daily_report', date_start: '2026-09-30', date_end: '2026-09-30' },
  }, approved);
  return { day, calls, toasts, context, run };
}

test('expired AI daily intent cannot arm or reuse approval confirmation or make a POST', async () => {
  for (const confirming of [false, true]) {
    const h = dateGuardHarness({ confirming }); h.day.value = '2026-10-01';
    await h.run(true);
    assert.deepEqual(h.calls, []);
    assert.equal(h.context.operationApprovalConfirmingIntentId.value, 0);
    assert.match(h.toasts.at(-1)[0], /执行日期已过期/);
  }
  assert.match(sourceDeclaration(source, 'approveOperationExecutionIntent'), /operationAiDailyApprovalDateWarning/);
});

test('approval rechecks the real date warning after freezing mutation identity and before POST', async () => {
  const h = dateGuardHarness({ afterCapture: day => { day.value = '2026-10-01'; } });
  await h.run(true);
  assert.deepEqual(h.calls, ['capture']);
  assert.match(h.toasts.at(-1)[0], /执行日期已过期/);
  assert.equal(h.context.operationLoading.value.actions, false);
});

test('expired AI daily intent can still be rejected and independently read back in its hotel', async () => {
  const h = dateGuardHarness(); h.day.value = '2026-10-01';
  await h.run(false);
  assert.equal(h.calls[1].url, '/operation/execution-intents/91/approve');
  assert.equal(h.calls[1].body.approved, false);
  assert.equal(h.calls[1].body.hotel_id, 7);
  assert.equal(h.calls.at(-1), 'read');
  assert.equal(h.context.operationLoading.value.actions, false);
});
