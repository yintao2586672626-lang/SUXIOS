import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto, createHash } from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';

const code = readFileSync('public/components/system/ai-daily-report-delivery.js', 'utf8');
function fixture() {
  const text = 'synthetic OTA 证据诊断\nctrip 曝光：100 people [online_daily_data#90401]\n价格因素尚不能判断';
  const snapshot = { contract_version: 'ai_evidence_reasoning.v1', scope: { tenant_id: 9004, hotel_id: 904, business_date: '2026-09-08' },
    final_text: text, final_text_sha256: createHash('sha256').update(text).digest('hex'), snapshot_fingerprint: 'a'.repeat(64),
    fact_pack: { facts: [{ value: 100 }], gaps: [] }, diagnosis: { recommendations: [{ recommendation_id: 'synthetic-rec',
      scope: { tenant_id: 9004, hotel_id: 904, platform: 'ctrip', date_start: '2026-09-08', date_end: '2026-09-08', object_ref: 'ota_channel:ctrip' },
      source_digest: 'c'.repeat(64), evidence_snapshot: { fingerprint: 'c'.repeat(64), scope: { object_ref: 'ota_channel:ctrip' } },
      problem: 'synthetic evidence review', status: 'proposed', requires_human_confirmation: true, handoff_status: 'ready_for_task_proposal' }] } };
  return { id: 1, hotel_id: 904, tenant_id: 9004, report_date: '2026-09-08', final_text: text,
    evidence_snapshot: snapshot, evidence_readback_status: 'exact_readback_verified' };
}
function harness(report = fixture(), crypto = webcrypto) {
  const writes = [], downloads = [], messages = [];
  const ctx = { aiDailyReport: report, aiDailyReportForm: { hotel_id: 904, report_date: '2026-09-08' },
    showToast: (...message) => messages.push(message) };
  const sandbox = { window: {}, Vue: { ref: value => ({ __v_isRef: true, value }), watch() {}, onBeforeUnmount() {} },
    crypto, TextEncoder, Uint8Array, Blob, navigator: { clipboard: { writeText: async text => writes.push(text) } },
    URL: { createObjectURL(blob) { downloads.push(blob); return 'blob:synthetic'; }, revokeObjectURL() {} },
    document: { createElement() { return { click() {} }; }, body: { appendChild() {}, removeChild() {} } } };
  vm.runInNewContext(code, sandbox);
  return { ctx, writes, downloads, messages, api: sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY,
    setup: sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({ ctx }) };
}
const intentReceipt = suggestion => ({ id: 44, ...suggestion.scope, status: 'pending_approval', tasks: [],
  target_value: { object_ref: suggestion.scope.object_ref }, evidence: { workflow_proposal: suggestion,
    source_snapshot_digest: suggestion.evidence_snapshot.fingerprint } });

test('page viewmodel, clipboard, broadcast and JSON use the same persisted text', async () => {
  const h = harness();
  assert.equal(h.setup.aiDailyEvidenceDelivery().text, h.ctx.aiDailyReport.final_text);
  assert.equal(h.api.buildAiDailyOperationsBroadcast(h.ctx).text, h.ctx.aiDailyReport.final_text);
  assert.equal(await h.setup.copyAiDailyEvidenceSnapshot(), true);
  assert.equal(await h.setup.downloadAiDailyEvidenceSnapshot(), true);
  assert.equal(h.writes[0], h.ctx.aiDailyReport.final_text);
  const exported = JSON.parse(await h.downloads[0].text());
  assert.deepEqual(exported, h.ctx.aiDailyReport.evidence_snapshot);
});

test('hotel/date/tenant mismatch and legacy reports block copy and export', async () => {
  for (const mutate of [h => { h.ctx.aiDailyReportForm.hotel_id = 905; }, h => { h.ctx.aiDailyReportForm.report_date = '2026-09-09'; },
    h => { h.ctx.aiDailyReport.evidence_snapshot.scope.tenant_id = 99; }, h => { delete h.ctx.aiDailyReport.evidence_snapshot; }]) {
    const h = harness(); mutate(h);
    assert.equal(h.setup.aiDailyEvidenceDelivery().ready, false);
    assert.equal(await h.setup.copyAiDailyEvidenceSnapshot(), false);
    assert.equal(await h.setup.downloadAiDailyEvidenceSnapshot(), false);
    assert.equal(h.writes.length + h.downloads.length, 0);
  }
});

test('tampered text hash blocks output even when the readback flag says verified', async () => {
  const h = harness(); h.ctx.aiDailyReport.evidence_snapshot.final_text_sha256 = 'b'.repeat(64);
  assert.equal(await h.setup.copyAiDailyEvidenceSnapshot(), false);
  assert.equal(h.writes.length, 0);
});

test('late hash completion after a scope switch cannot copy stale content', async () => {
  let release;
  const h = harness(fixture(), { subtle: { digest: (...args) => new Promise(resolve => { release = async () => resolve(await webcrypto.subtle.digest(...args)); }) } });
  const pending = h.setup.copyAiDailyEvidenceSnapshot();
  h.ctx.aiDailyReportForm.hotel_id = 905;
  await release();
  assert.equal(await pending, false);
  assert.equal(h.writes.length, 0);
});

test('proposal is explicit, deduplicated, scoped and reads back the existing intent ID', async () => {
  const h = harness(); let calls = 0; let resolve;
  const suggestion = h.ctx.aiDailyReport.evidence_snapshot.diagnosis.recommendations[0];
  h.ctx.aiDailyReportDeliveryRequest = async (url, options) => {
    calls++; assert.equal(url, '/operation/task-workflow-proposals');
    assert.deepEqual(JSON.parse(options.body).recommendation, suggestion);
    return new Promise(done => { resolve = () => done({ code: 200, data: { readback_verified: true,
      intent: intentReceipt(suggestion) } }); });
  };
  assert.equal(calls, 0);
  const pending = h.setup.proposeAiEvidenceTask(suggestion);
  assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), false);
  while (!resolve) await new Promise(done => setImmediate(done));
  h.ctx.aiDailyReportForm.hotel_id = 905;
  resolve(); assert.equal(await pending, true);
  assert.equal(h.messages.length, 0, 'Late old-scope proposal receipt must not notify the new scope');
  assert.deepEqual(Object.keys(h.setup.aiEvidenceProposalState(suggestion)), []);
  h.ctx.aiDailyReportForm.hotel_id = 904;
  assert.equal(h.setup.aiEvidenceProposalState(suggestion).intent_id, 44);
  assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), false);
  assert.equal(calls, 1);
});

test('cross-hotel proposal receipt is rejected and network failure can be retried', async () => {
  const h = harness(); const suggestion = h.ctx.aiDailyReport.evidence_snapshot.diagnosis.recommendations[0];
  h.ctx.aiDailyReportDeliveryRequest = async () => ({ code: 200, message: 'ok', data: { readback_verified: true,
    intent: { ...intentReceipt(suggestion), hotel_id: 905 } } });
  assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), false);
  assert.match(h.setup.aiEvidenceProposalState(suggestion).message, /范围/);
  h.ctx.aiDailyReportDeliveryRequest = async () => { throw new Error('synthetic timeout'); };
  assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), false);
  h.ctx.aiDailyReportDeliveryRequest = async () => ({ code: 200, data: { readback_verified: true,
    intent: intentReceipt(suggestion) } });
  assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), true);
});

test('same hotel proposal for a different suggestion or object cannot be linked', async () => {
  for (const mutate of [intent => { intent.evidence.workflow_proposal.recommendation_id = 'different'; },
    intent => { intent.target_value.object_ref = 'different'; }, intent => { intent.evidence.source_snapshot_digest = 'f'.repeat(64); }]) {
    const h = harness(); const suggestion = h.ctx.aiDailyReport.evidence_snapshot.diagnosis.recommendations[0];
    const intent = JSON.parse(JSON.stringify(intentReceipt(suggestion))); mutate(intent);
    h.ctx.aiDailyReportDeliveryRequest = async () => ({ code: 200, data: { readback_verified: true, intent } });
    assert.equal(await h.setup.proposeAiEvidenceTask(suggestion), false);
    assert.equal(h.setup.aiEvidenceProposalState(suggestion).status, 'error');
  }
});
