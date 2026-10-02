import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, webcrypto } from 'node:crypto';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/components/system/ai-daily-report-delivery.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/16-page-ai-daily-report.html', 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const spec = () => ({ record_id: 11, report_id: 88, hotel_id: 7, audience: 'owner', readback_verified: true, spec_fingerprint: 'b'.repeat(64) });
const pending = () => ({ report_id: 88, hotel_id: 7, audience: 'owner', presentation_spec_id: 11, spec_fingerprint: 'b'.repeat(64),
  review_fingerprint: 'c'.repeat(64), status: 'pending', pending_item_count: 2, revision_item_count: 0, readback_verified: false,
  items: [{ id: 'check:html', is_evidence_gap: false, decision: 'pending', note: '' },
    { id: 'evidence:missing-cost', is_evidence_gap: true, decision: 'pending', note: '' }] });
const approved = () => { const row = pending(); row.status = 'reviewed'; row.pending_item_count = 0; row.readback_verified = true;
  row.review_id = 22; row.review_fingerprint = 'd'.repeat(64); row.items[0].decision = 'confirmed'; row.items[1].decision = 'gap_acknowledged'; return row; };

function setup(handler) {
  const downloads = [];
  let watch;
  const sandbox = { window: { atob: value => Buffer.from(value, 'base64').toString('binary') },
    Vue: { ref: value => ({ __v_isRef: true, value }), watch: (_sources, callback) => { watch = callback; }, onBeforeUnmount() {} },
    document: { createElement: () => ({ click() { downloads.push(this.download); } }), body: { appendChild() {}, removeChild() {} } },
    URL: { createObjectURL: () => 'blob:test-only', revokeObjectURL() {} }, Blob, crypto: webcrypto, console };
  vm.runInNewContext(source, sandbox);
  const ctx = { aiDailyReport: { id: 88, hotel_id: 7 }, aiDailyReportDeliveryRequest: handler, showToast() {} };
  return { state: sandbox.window.SUXI_AI_DAILY_REPORT_DELIVERY.setup({ ctx }), ctx, switchScope: () => watch(), downloads };
}

test('review UI exposes individual decisions, source hashes, exact readback and distinct formal export', () => {
  for (const marker of ['data-testid="ai-daily-presentation-review"', 'v-model="item.decision"', 'v-model="item.note"',
    'source_evidence_fingerprint', 'review_fingerprint', 'gap_acknowledged', 'needs_revision',
    'saveAiDailyReportPresentationReview', 'aiDailyReportFormalExportReady()', "downloadAiDailyReportPackage('formal')"]) assert.ok(template.includes(marker), marker);
});

test('current spec review saves individual decisions and rereads the exact new review fingerprint', async () => {
  let stored = pending();
  let body;
  const { state } = setup(async (url, options) => {
    if (url.endsWith('/presentation-spec')) return { code: 200, data: spec() };
    if (options?.method === 'POST') { body = JSON.parse(options.body); stored = approved(); return { code: 200, data: clone(stored) }; }
    return { code: 200, data: clone(stored) };
  });
  await state.prepareAiDailyReportPresentationReview();
  assert.equal(state.aiDailyReportPresentationReview.readback_verified, false);
  assert.equal(state.aiDailyReportFormalExportReady(), false);
  state.aiDailyReportPresentationReview.items[0].decision = 'confirmed';
  state.aiDailyReportPresentationReview.items[1].decision = 'gap_acknowledged';
  await state.saveAiDailyReportPresentationReview();
  assert.equal(body.expected_review_fingerprint, 'c'.repeat(64));
  assert.equal(body.expected_spec_fingerprint, 'b'.repeat(64));
  assert.deepEqual(body.decisions.map(item => item.decision), ['confirmed', 'gap_acknowledged']);
  assert.equal(state.aiDailyReportPresentationReview.review_id, 22);
  assert.equal(state.aiDailyReportFormalExportReady(), true);
  state.aiDailyReportPresentationReview.items[0].note = 'unsaved edit';
  assert.equal(state.aiDailyReportFormalExportReady(), false, 'unsaved review edits must not be exported as saved review');
});

test('a save/readback mismatch clears review and prevents formal export', async () => {
  let reads = 0;
  const { state } = setup(async (url, options) => {
    if (url.endsWith('/presentation-spec')) return { code: 200, data: spec() };
    if (options?.method === 'POST') return { code: 200, data: approved() };
    return { code: 200, data: ++reads === 1 ? pending() : { ...approved(), review_fingerprint: 'e'.repeat(64) } };
  });
  await state.prepareAiDailyReportPresentationReview();
  await state.saveAiDailyReportPresentationReview();
  assert.equal(state.aiDailyReportPresentationReview, null);
  assert.match(state.aiDailyReportPresentationReviewError, /复核已变化/);
  assert.equal(state.aiDailyReportFormalExportReady(), false);
});

test('changed report or hotel discards a delayed review and never saves it into the new scope', async () => {
  let resolve;
  let writes = 0;
  const { state, ctx, switchScope } = setup(async (url, options) => {
    if (url.endsWith('/presentation-artifacts?audience=owner')) return { code: 200, data: { status: 'not_generated' } };
    if (url.endsWith('/presentation-spec')) { writes++; return { code: 200, data: spec() }; }
    return new Promise(done => { resolve = done; });
  });
  const loading = state.prepareAiDailyReportPresentationReview();
  await new Promise(setImmediate);
  ctx.aiDailyReport = { id: 99, hotel_id: 8 };
  switchScope();
  resolve({ code: 200, data: approved() });
  await loading;
  assert.equal(state.aiDailyReportPresentationReview, null);
  await state.saveAiDailyReportPresentationReview();
  assert.equal(writes, 1);
});

test('formal export rechecks current review and stops before artifact generation when pending', async () => {
  let generated = 0;
  const { state, downloads } = setup(async url => {
    if (url.endsWith('/presentation-spec')) return { code: 200, data: spec() };
    if (url.includes('/presentation-review?')) return { code: 200, data: pending() };
    generated++; return { code: 200 };
  });
  await state.downloadAiDailyReportPackage('formal');
  assert.equal(generated, 0);
  assert.equal(downloads.length, 0);
  assert.match(state.aiDailyReportPresentationResult.message, /正式导出需要当前版本逐项复核完成/);
});

test('draft and formal downloads carry exact saved review status and verify bytes before downloading', async () => {
  for (const mode of ['draft', 'formal']) {
    let sent;
    const review = mode === 'formal' ? approved() : pending();
    const bytes = Buffer.from('TEST-ONLY-artifact-bytes');
    const sha = createHash('sha256').update(bytes).digest('hex');
    const { state, downloads } = setup(async (url, options) => {
      if (url.endsWith('/presentation-spec')) return { code: 200, data: spec() };
      if (url.includes('/presentation-review?')) return { code: 200, data: clone(review) };
      sent = JSON.parse(options.body);
      return { code: 200, data: { artifact_id: 33, presentation_spec_id: 11, report_id: 88, hotel_id: 7, audience: 'owner',
        spec_fingerprint: 'b'.repeat(64), review_fingerprint: review.review_fingerprint, human_review_status: review.status,
        export_mode: mode, artifact_readback_verified: true, render_status: 'rendered_and_readback_verified',
        content_sha256: sha, content_bytes: bytes.length, bundle_base64: bytes.toString('base64'), filename: `test-only-${mode}.zip` } };
    });
    await state.downloadAiDailyReportPackage(mode);
    assert.equal(sent.export_mode, mode);
    assert.equal(sent.expected_review_fingerprint, review.review_fingerprint);
    assert.equal(downloads.length, 1);
    assert.equal(state.aiDailyReportPresentationResult.humanReviewStatus, review.status);
    assert.equal(state.aiDailyReportPresentationResult.exportMode, mode);
  }
});

test('review from another hotel is rejected and cannot enable formal export', async () => {
  const { state } = setup(async url => ({ code: 200, data: url.endsWith('/presentation-spec') ? spec() : { ...approved(), hotel_id: 8 } }));
  await state.prepareAiDailyReportPresentationReview();
  assert.equal(state.aiDailyReportPresentationReview, null);
  assert.match(state.aiDailyReportPresentationReviewError, /身份、版本或回读验证失败/);
  assert.equal(state.aiDailyReportFormalExportReady(), false);
});
