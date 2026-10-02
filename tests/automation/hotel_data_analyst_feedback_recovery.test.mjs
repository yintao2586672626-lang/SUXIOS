import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.env.FEEDBACK_COMPONENT_SOURCE || 'public/components/system/hotel-data-analyst-components.js', 'utf8');
const question = () => ({ id: 990, hotel_id: 7, platform: 'ctrip', date_start: '2026-08-12', date_end: '2026-08-12', content_digest: 'c'.repeat(64), analysis_quality_receipt: { receipt_digest: 'd'.repeat(64) } });
const row = () => ({ id: 91, question_id: 990, hotel_id: 7, source_content_digest: 'c'.repeat(64), quality_receipt_digest: 'd'.repeat(64), content_digest: 'e'.repeat(64), feedback_kind: 'useful', correction: {}, readback_verified: true, persistence_status: 'readback_verified', usage_policy: 'eval_candidate_only_no_training', formal_evaluation_case_created: false, model_training_triggered: false, external_action_authorized: false, boundaries: { original_analysis_mutated: false, external_action_authorized: false } });
const history = (list = [row()]) => ({ code: 200, data: { contract_version: 'hotel_data_analyst_feedback.v1', data_status: 'ready', question_id: 990, list, latest: list[0] || null, summary: { total: list.length, useful: list.length, needs_correction: 0 }, boundaries: { original_analysis_mutated: false, external_action_authorized: false } } });
const failure = { code: 503, message: '历史暂时不可用' };
const clone = value => JSON.parse(JSON.stringify(value));
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
function find(node, id) {
  if (!node || typeof node !== 'object') return null;
  if (node.props?.['data-testid'] === id) return node;
  return (Array.isArray(node.children) ? node.children : [node.children]).map(item => find(item, id)).find(Boolean) || null;
}
function harness(replies = [], prefix = 'operating-question-quality-feedback') {
  const sandbox = { window: {}, console };
  vm.runInNewContext(source, sandbox);
  const components = sandbox.window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS.create({ h: (type, props, children) => ({ type, props, children }), inject: () => null, nextTick: async () => {} });
  const state = {};
  const calls = [];
  const q = question();
  const ui = components.createFeedbackUi({ getState: () => state, request: async (url, options = {}) => {
    calls.push({ url, ...options });
    const reply = replies.shift();
    if (typeof reply === 'function') return reply();
    if (reply instanceof Error) throw reply;
    assert.ok(reply, `unexpected request ${url}`);
    return clone(reply);
  } });
  const render = (interactive = true) => components.renderQualityReceipt(q.analysis_quality_receipt, 'receipt', { question: q, feedbackUi: ui, feedbackTestId: prefix, interactive });
  return { q, ui, calls, replies, render, feedback: () => ui.forQuestion(q), node: suffix => find(render(), `${prefix}-${suffix}`), prefix };
}

for (const prefix of ['operating-question-quality-feedback', 'system-guide-analysis-quality-feedback']) {
  test(`${prefix}: visible history retry recovers exact existing feedback with GET only`, async () => {
    const h = harness([failure, history()], prefix);
    h.render(); await flush();
    assert.equal(h.feedback().phase, 'error');
    h.render(); await flush();
    assert.equal(h.calls.length, 1, 'no automatic retry loop');
    const retry = h.node('retry-history');
    assert.ok(retry, 'history failure must have a visible retry control');
    assert.equal(retry.props.disabled, false);
    await retry.props.onClick();
    assert.equal(h.feedback().latest.id, 91);
    assert.equal(h.feedback().saved_feedback_id, 91);
    assert.equal(h.feedback().phase, 'saved');
    assert.equal(h.node('retry-history'), null);
    assert.ok(h.node('latest'));
    assert.equal(h.calls.length, 2);
    assert.ok(h.calls.every(call => !call.method && call.url.endsWith('/990/feedbacks/mine?limit=20')));
    assert.deepEqual(h.q, question(), 'original analysis remains unchanged');
  });
}

test('retry is single flight and restores control after a second failure', async () => {
  const gate = deferred();
  const h = harness([failure, () => gate.promise, history()]);
  await h.ui.load(h.q);
  const retry = h.node('retry-history'); assert.ok(retry);
  const pending = retry.props.onClick();
  assert.equal(h.node('retry-history').props.disabled, true);
  assert.equal(h.node('save').props.disabled, true);
  assert.equal(h.node('needs-correction').props.disabled, true);
  await retry.props.onClick();
  assert.equal(h.calls.length, 2);
  gate.resolve(failure); await pending;
  assert.equal(h.feedback().loading, false);
  assert.equal(h.node('retry-history').props.disabled, false);
  await h.node('retry-history').props.onClick();
  assert.equal(h.feedback().latest.id, 91);
});

test('consecutive history failures preserve edited correction and failed-save idempotency key', async () => {
  const h = harness([failure, failure, { code: 500, message: '保存暂不可用' }, history()]);
  await h.ui.load(h.q);
  const draft = { feedback_kind: 'needs_correction', correction_text: '仅统计携程渠道，不能推断全酒店', issue_codes: ['scope_or_date'] };
  h.ui.updateDraft(h.q, draft);
  await h.ui.load(h.q, { force: true });
  await h.ui.save(h.q);
  const key = h.feedback().idempotency_key;
  assert.ok(key);
  await h.ui.load(h.q, { force: true });
  for (const [field, value] of Object.entries(draft)) assert.deepEqual(clone(h.feedback()[field]), value);
  assert.equal(h.feedback().idempotency_key, key);
  assert.equal(h.feedback().phase, 'editing');
  assert.equal(h.feedback().latest.id, 91);
  assert.equal(h.feedback().saved_feedback_id, 0, 'historical record does not confirm unsaved correction');
  assert.equal(h.feedback().saved_message, '');
  assert.equal(h.calls.filter(call => call.method === 'POST').length, 1);
});

test('empty recovered history is ready without inventing a zero-valued feedback', async () => {
  const h = harness([failure, history([])]);
  await h.ui.load(h.q);
  const retry = h.node('retry-history'); assert.ok(retry);
  await retry.props.onClick();
  assert.equal(h.feedback().phase, 'ready');
  assert.equal(h.feedback().latest, null);
  assert.deepEqual(clone(h.feedback().list), []);
  assert.equal(h.node('latest'), null);
});

test('transport exception recovers without losing an intentionally empty correction draft', async () => {
  const h = harness([new Error('连接暂时中断'), failure, history()]);
  await h.ui.load(h.q);
  assert.equal(h.feedback().error, '连接暂时中断');
  h.ui.updateDraft(h.q, { feedback_kind: 'needs_correction', correction_text: '', issue_codes: [] });
  const retry = h.node('retry-history'); assert.ok(retry);
  await retry.props.onClick();
  await h.node('retry-history').props.onClick();
  assert.equal(h.feedback().feedback_kind, 'needs_correction');
  assert.equal(h.feedback().correction_text, '');
  assert.deepEqual(clone(h.feedback().issue_codes), []);
  assert.equal(h.feedback().phase, 'editing');
  assert.equal(h.feedback().latest.id, 91);
});

for (const [name, mutate] of [
  ['question', data => { data.question_id = 991; }],
  ['hotel', data => { data.list[0].hotel_id = 8; }],
  ['source digest', data => { data.list[0].source_content_digest = 'a'.repeat(64); }],
  ['receipt digest', data => { data.list[0].quality_receipt_digest = 'b'.repeat(64); }],
  ['latest id', data => { data.latest = { ...data.latest, id: 92 }; }],
  ['mutation boundary', data => { data.boundaries.original_analysis_mutated = true; }],
  ['readback flag', data => { data.list[0].readback_verified = false; }],
  ['training boundary', data => { data.list[0].model_training_triggered = true; }],
]) {
  test(`retry rejects inconsistent ${name}, then recovers from current valid history`, async () => {
    const bad = history(); mutate(bad.data);
    const h = harness([failure, bad, history()]);
    await h.ui.load(h.q);
    const retry = h.node('retry-history'); assert.ok(retry);
    await retry.props.onClick();
    assert.equal(h.feedback().loaded, false);
    assert.equal(h.feedback().latest, null);
    assert.ok(h.feedback().error);
    await h.node('retry-history').props.onClick();
    assert.equal(h.feedback().latest.id, 91);
  });
}

test('migration and invalid snapshot are explicit unavailable states without a read retry', async () => {
  const payload = history([]); payload.data.data_status = 'migration_required';
  const h = harness([payload]);
  await h.ui.load(h.q);
  assert.equal(h.feedback().phase, 'migration_required');
  assert.equal(h.node('retry-history'), null);
  assert.equal(h.node('save').props.disabled, true);
  h.q.content_digest = '';
  assert.equal(h.feedback().phase, 'unavailable');
  assert.equal(h.node('retry-history'), null);
  assert.equal(h.calls.length, 1);
});

test('noninteractive global historical card cannot retry or auto-read', async () => {
  const h = harness([failure]);
  h.render(false); await flush();
  assert.equal(h.calls.length, 0);
  await h.ui.load(h.q);
  assert.equal(find(h.render(false), `${h.prefix}-retry-history`), null);
});

test('history load cannot overlap an in-flight save, and successful save clears draft preservation', async () => {
  const gate = deferred();
  const saved = row(); saved.feedback_kind = 'needs_correction'; saved.correction = { summary: '仅保留携程口径', issue_codes: ['scope_or_date'] };
  const h = harness([failure, () => gate.promise, { code: 200, data: saved }, { code: 200, data: question() }, history()]);
  await h.ui.load(h.q);
  h.ui.updateDraft(h.q, { feedback_kind: 'needs_correction', correction_text: saved.correction.summary, issue_codes: saved.correction.issue_codes });
  const saving = h.ui.save(h.q);
  await h.ui.load(h.q, { force: true });
  assert.equal(h.calls.length, 2, 'force history must not consume a response during POST');
  gate.resolve({ code: 200, data: saved }); await saving;
  assert.equal(h.feedback().phase, 'saved');
  assert.equal(h.feedback().idempotency_key, '');
  await h.ui.load(h.q, { force: true });
  assert.equal(h.feedback().feedback_kind, 'useful', 'clean saved draft can be restored from latest history');
  assert.equal(h.feedback().correction_text, '');
  assert.equal(h.calls.filter(call => call.method === 'POST').length, 1);
});
