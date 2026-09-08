import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const app = await readFile(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const template = await readFile(new URL('../../resources/frontend/templates/fragments/35-page-online-data.html', import.meta.url), 'utf8');
const start = app.indexOf('const localCollectorReceiptObject =');
const end = app.indexOf('// End pure local collection receipt projections.', start);
assert.ok(start > 0 && end > start);
const { project, evidenceView } = Function(`${app.slice(start, end)}
return { project: buildLocalCollectorCollectionReceipt, evidenceView: buildLocalCollectorEvidenceView };`)();

const scope = {
  tenant_id: 7, system_hotel_id: 80, platform: 'ctrip', business_date: '2026-09-04',
};
const delivery = {
  ...scope, task_id: 42, status: 'accepted', result_id: 'result-fixture-42',
  result_hash: 'a'.repeat(64), attempt: 2, accepted_at: '2026-09-05 09:00:00',
};
const task = () => ({
  id: 42, tenant_id: 7, account_id: 2, system_hotel_id: 80, platform: 'ctrip',
  task_type: 'collect', data_date: '2026-09-04', status: 'success',
  request_summary: { result_delivery: { status: 'accepted', result_id: delivery.result_id, result_hash: delivery.result_hash, attempt: 2 } },
  result_summary: {
    scope_identity: { ...scope, account_id: 2, capture_task_id: 42 },
    result_delivery: { ...delivery }, saved_count: 1, readback_count: 1, readback_verified: true,
    data_source_id: 18, sync_task_id: 24, run_readback_scope_verified: true,
    run_readback: { ...scope, readback_verified: true, p0_status: 'ready', row_ids: [99] },
    dual_ota_authority: { ready: false, status: 'awaiting_other_platform', missing_platforms: ['meituan'] },
    canonical_history: {
      tenant_id: 7, hotel_id: 80, target_date: '2026-09-04', status: 'partial',
      platform_results: { ctrip: { status: 'verified', promotion: { ...scope, readback_verified: true } } },
    },
  },
});
const payload = () => ({
  receipt: { ...delivery }, scope: { ...scope },
  evidence: { status: 'retained', bytes: 128, result_hash: 'b'.repeat(64), replayable: true },
  data_quality: 'reference_only',
  business_result: {
    capture_summary: { capture_id: 'capture-fixture', fetched_at: '2026-09-05 08:59:00' },
    rows: [{ ...scope, data_date: scope.business_date, data_type: 'daily', source: 'ctrip', revenue: 125,
      room_nights: 2, raw_data: { password: 'must-not-render' }, cookie: 'must-not-render' }],
  },
});

test('pending delivery stays pending without claiming server save', () => {
  const value = task();
  value.status = 'running';
  value.result_summary = {};
  value.request_summary.result_delivery.status = 'upload_pending';
  const row = project(value);
  assert.equal(row.state, 'upload_pending');
  assert.equal(row.stateText, '待回传');
  assert.equal(row.savedText, '未确认正式保存');
  assert.equal(row.canQueryEvidence, false);
  assert.equal(row.pollable, true);
});

test('running without delivery evidence means waiting for a result, not completed capture', () => {
  const value = task();
  value.status = 'running';
  delete value.request_summary;
  delete value.result_summary;
  assert.equal(project(value).state, 'waiting');
  assert.equal(project(value).deliveryText, '尚无回传确认');
});

test('saved receipt remains pending verification when canonical evidence is absent', () => {
  const value = task();
  delete value.result_summary.canonical_history;
  const row = project(value);
  assert.equal(row.state, 'saved_pending');
  assert.match(row.savedText, /已保存 1 条/);
  assert.equal(row.historyText, '未返回历史事实归档回执');
  assert.equal(row.canQueryEvidence, true);
});

test('one platform can verify while the other platform remains pending', () => {
  const row = project(task());
  assert.equal(row.state, 'verified');
  assert.match(row.authorityText, /等待另一平台.*meituan/);
  assert.equal(row.historyText, '本平台历史事实已核验');
});

test('status labels alone never establish verified data', () => {
  const value = task();
  value.result_summary.run_readback.readback_verified = false;
  value.result_summary.dual_ota_authority = { ready: true, status: 'ready' };
  value.result_summary.canonical_history.platform_results.ctrip.promotion.readback_verified = false;
  assert.equal(project(value).state, 'saved_pending');
});

test('failed task keeps an actionable reason without becoming successful through old evidence', () => {
  const value = task();
  value.status = 'failed';
  value.error_summary = '目标日期不匹配，未使用旧数据';
  value.error_code = 'date_mismatch';
  value.recovery = { next_action: '按原任务业务日期重新采集' };
  const row = project(value);
  assert.equal(row.state, 'failed');
  assert.match(row.failureText, /目标日期不匹配/);
  assert.equal(row.recoveryText, '按原任务业务日期重新采集');
});

test('legacy success without evidence stays explicitly unknown', () => {
  const value = task();
  delete value.request_summary;
  delete value.result_summary;
  const row = project(value);
  assert.equal(row.state, 'legacy');
  assert.equal(row.canQueryEvidence, false);
  assert.match(row.evidenceNote, /旧任务.*未保存/);
  assert.equal(row.savedText, '未确认正式保存');
});

test('hotel, tenant, platform, date and attempt mismatches never reuse another receipt', () => {
  const mutations = [
    value => { value.result_summary.scope_identity.system_hotel_id = 81; },
    value => { value.result_summary.scope_identity.tenant_id = 8; },
    value => { value.result_summary.run_readback.platform = 'meituan'; },
    value => { value.result_summary.run_readback.business_date = '2026-09-03'; },
    value => { value.result_summary.result_delivery.attempt = 1; },
    value => { value.result_summary.canonical_history.platform_results.ctrip.promotion.business_date = '2026-09-03'; },
  ];
  for (const mutate of mutations) {
    const value = task();
    mutate(value);
    const row = project(value);
    assert.equal(row.state, 'scope_mismatch');
    assert.equal(row.canQueryEvidence, false);
    assert.deepEqual(row.rowIds, []);
  }
});

test('safe evidence renders selected business fields and keeps transport and sanitized hashes distinct', () => {
  const view = evidenceView(payload(), project(task()));
  assert.equal(view.quality, 'reference_only');
  assert.equal(view.businessRows[0].source, 'ctrip');
  assert.equal(view.businessRows[0].date, '2026-09-04');
  assert.match(view.businessRows[0].metricText, /收入：125.*间夜：2/);
  assert.doesNotMatch(JSON.stringify(view), /must-not-render|password|cookie|raw_data/);
  assert.equal(view.bytes, '128 字节');
});

test('safe evidence identity mismatch refuses all business rows', () => {
  const row = project(task());
  for (const mutate of [
    value => { value.scope.system_hotel_id = 81; },
    value => { value.scope.business_date = '2026-09-03'; },
    value => { value.receipt.result_hash = 'c'.repeat(64); },
    value => { value.receipt.result_id = 'other-result'; },
    value => { value.receipt.attempt = 1; },
    value => { value.business_result.rows[0].data_date = '2026-09-03'; },
  ]) {
    const value = payload();
    mutate(value);
    assert.throws(() => evidenceView(value, row), /不一致/);
  }
});

test('receipt UI stays in the existing local collector block and queries a scoped safe endpoint', () => {
  assert.match(template, /<local-collector-login-handoff\s*\/>[\s\S]*data-testid="local-collector-collection-receipts"/);
  assert.match(template, /data-testid="local-collector-evidence-query"/);
  assert.match(template, /不是正式经营事实/);
  assert.match(template, /row\.evidenceView\.data\.businessRows/);
  assert.doesNotMatch(template.slice(template.indexOf('data-testid="local-collector-collection-receipts"'), template.indexOf('第 1 步：连接账户使用者电脑')), /JSON\.stringify|v-html/);
  assert.match(app, /local-collector\/tasks\/\$\{row\.taskId\}\/evidence/);
  assert.match(app, /result_hash: row\.resultHash, result_id: row\.resultId, attempt: String\(row\.attempt\)/);
  assert.match(app, /onUnmounted\(stopLocalCollectorCollectionPolling\)/);
});
