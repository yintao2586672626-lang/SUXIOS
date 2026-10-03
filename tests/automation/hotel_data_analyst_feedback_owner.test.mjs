import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(process.env.FEEDBACK_COMPONENT_SOURCE || 'public/components/system/hotel-data-analyst-components.js', 'utf8');
const q = () => ({ id: 990, hotel_id: 7, content_digest: 'c'.repeat(64), analysis_quality_receipt: { receipt_digest: 'd'.repeat(64) } });
const row = () => ({ id: 91, question_id: 990, hotel_id: 7, source_content_digest: 'c'.repeat(64), quality_receipt_digest: 'd'.repeat(64), content_digest: 'e'.repeat(64), feedback_kind: 'useful', correction: {}, readback_verified: true, persistence_status: 'readback_verified', usage_policy: 'eval_candidate_only_no_training', formal_evaluation_case_created: false, model_training_triggered: false, external_action_authorized: false, boundaries: { original_analysis_mutated: false, external_action_authorized: false } });
const mine = () => ({ contract_version: 'hotel_data_analyst_feedback.v1', data_status: 'ready', question_id: 990, list: [row()], latest: row(), boundaries: { original_analysis_mutated: false, external_action_authorized: false } });
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const flush = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
const plain = v => JSON.parse(JSON.stringify(v));

function harness(stage = 'post') {
  const sandbox = { window: {}, console }; vm.runInNewContext(source, sandbox);
  const components = sandbox.window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS.create({ h: () => null, inject: () => null, nextTick: async () => {} });
  let state = {};
  let epoch = 1;
  const question = q(), calls = [], gate = deferred();
  let held = false;
  const ui = components.createFeedbackUi({ getState: () => state, getSessionEpoch: () => epoch, request: async (url, options = {}) => {
    const kind = options.method === 'POST' ? 'post' : url.includes('/mine?') ? 'history' : url.includes('/feedbacks/') ? 'exact' : 'question';
    calls.push({ kind, state });
    if (!held && kind === stage) { held = true; return gate.promise; }
    return { code: 200, data: kind === 'history' ? mine() : kind === 'question' ? q() : row() };
  } });
  ui.updateDraft(question, { feedback_kind: 'useful' });
  return { ui, question, calls, gate, state: () => state,
    render: () => components.renderQualityReceipt(question.analysis_quality_receipt, 'receipt', { question, feedbackUi: ui, interactive: true }),
    reset() { state = {}; return ui.forQuestion(question); },
    expire() { epoch++; return {}; },
    replaceCache() { const replacement = { ...question, content_digest: 'b'.repeat(64) }; return ui.forQuestion(replacement); },
  };
}

for (const stage of ['post', 'exact', 'question']) {
  for (const replacement of ['reset', 'replaceCache', 'expire']) {
    for (const outcome of ['success', 'failure']) {
      test(`${stage} ${outcome} after ${replacement}: old save is discarded without later requests or new-state mutation`, async () => {
        const h = harness(stage), old = h.ui.forQuestion(h.question);
        const pending = h.ui.save(h.question); await flush();
        const requestCount = h.calls.length;
        const next = h[replacement]();
        next.saving = true; next.error = '当前请求仍在处理';
        const before = plain(next);
        if (outcome === 'success') h.gate.resolve({ code: 200, data: stage === 'question' ? q() : row() });
        else h.gate.reject(new Error('旧账号读取失败'));
        const result = await pending;
        assert.equal(result, null);
        assert.equal(h.calls.length, requestCount, 'old lifecycle must not request with the replacement session');
        assert.deepEqual(plain(next), before, 'new request busy/error/draft remain untouched');
        assert.equal(old.saved_feedback_id, 0);
        assert.equal(old.saved_message, '');
        assert.equal(old.error, '', 'stale transport errors must not become feedback errors');
      });
    }
  }
}

for (const replacement of ['reset', 'replaceCache', 'expire']) {
  for (const outcome of ['success', 'failure']) {
    test(`history ${outcome} after ${replacement}: discarded receipt cannot claim current history`, async () => {
      const h = harness('history'), old = h.ui.forQuestion(h.question);
      const pending = h.ui.load(h.question, { force: true });
      const next = h[replacement](); next.loading = true;
      const before = plain(next);
      if (outcome === 'success') h.gate.resolve({ code: 200, data: mine() });
      else h.gate.reject(new Error('旧账号历史失败'));
      assert.equal(await pending, null);
      assert.deepEqual(plain(next), before);
      assert.equal(old.loaded, false);
      assert.equal(old.latest, null);
      assert.equal(old.error, '');
    });
  }
}

test('ordinary result navigation preserves a live request in its question-specific cache', async () => {
  const h = harness('post');
  const pending = h.ui.save(h.question);
  h.state().result = { ...q(), id: 991 };
  h.ui.forQuestion(h.state().result);
  h.gate.resolve({ code: 200, data: row() });
  assert.equal((await pending).id, 91);
  assert.deepEqual(h.calls.map(call => call.kind), ['post', 'exact', 'question']);
  assert.equal(h.ui.forQuestion(h.question).saved_feedback_id, 91);
  assert.equal(h.ui.forQuestion(h.state().result).saved_feedback_id, 0);
});

test('current transport failure remains visible and explicit retry retains the submission key', async () => {
  const h = harness('post');
  const pending = h.ui.save(h.question);
  const key = h.ui.forQuestion(h.question).idempotency_key;
  h.gate.reject(new Error('当前连接中断'));
  assert.equal(await pending, null);
  assert.equal(h.ui.forQuestion(h.question).error, '当前连接中断');
  assert.equal(h.ui.forQuestion(h.question).idempotency_key, key);
  assert.equal(h.ui.forQuestion(h.question).saving, false);
  assert.equal((await h.ui.save(h.question)).id, 91);
});

test('two overlapping accounts: abandoned save cannot clear new busy state and new save completes', async () => {
  const h = harness('post');
  const oldSave = h.ui.save(h.question);
  h.reset(); h.ui.updateDraft(h.question, { feedback_kind: 'useful' });
  const newSave = h.ui.save(h.question);
  h.gate.resolve({ code: 200, data: row() });
  assert.equal(await oldSave, null);
  assert.equal((await newSave).id, 91);
  assert.equal(h.ui.forQuestion(h.question).phase, 'saved');
  assert.equal(h.calls.length, 4, 'one abandoned POST plus current three-step save');
});

for (const replacement of ['reset', 'replaceCache', 'expire']) {
  test(`queued render load after ${replacement} does not start a request under a new owner`, async () => {
    const h = harness('unused');
    h.ui.forQuestion(h.question).phase = 'idle';
    h.render();
    h[replacement]();
    const before = plain(h.state());
    await flush();
    assert.equal(h.calls.length, 0);
    assert.deepEqual(plain(h.state()), before);
  });
}

test('current queued render still loads once despite duplicate renders', async () => {
  const h = harness('unused');
  h.ui.forQuestion(h.question).phase = 'idle';
  h.render(); h.render();
  await flush();
  assert.equal(h.calls.length, 1);
  assert.equal(h.ui.forQuestion(h.question).latest.id, 91);
});

test('both original consumers pass a live session getter and current state to feedback UI', () => {
  const captured = [], live = { epoch: 4, value: {} };
  const runtime = { ref: value => ({ value }), computed: read => ({ get value() { return read(); } }), inject: () => ({ state: live, sessionEpoch: () => live.epoch }), h: () => null, nextTick: async () => {}, onMounted: () => {}, onUnmounted: () => {} };
  const sandbox = { window: { Vue: { watch: () => {} }, SUXI_HOTEL_DATA_ANALYST_COMPONENTS: { create: () => ({ suggestions: [], createFeedbackUi: options => { captured.push(options); return {}; }, renderQualityReceipt: () => null, hotelDataAnalystProfile: {} }) } }, console };
  // Use the actual analyst renderer helpers while spying only on feedback ownership.
  const actual = { window: {}, console };
  vm.runInNewContext(source, actual);
  const analyst = actual.window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS.create(runtime);
  sandbox.window.SUXI_HOTEL_DATA_ANALYST_COMPONENTS.create = () => ({ ...analyst, createFeedbackUi: options => { captured.push(options); return {}; } });
  vm.runInNewContext(fs.readFileSync('public/components/system/operating-evidence-navigation.js', 'utf8'), sandbox);
  vm.runInNewContext(fs.readFileSync('public/components/system/operating-intelligence-loader.js', 'utf8'), sandbox);
  vm.runInNewContext(fs.readFileSync('public/components/system/operating-intelligence-components.js', 'utf8'), sandbox);
  const components = sandbox.window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL.create(runtime);
  components.operatingQuestionPanel.setup();
  components.operatingQuestionConsultant.setup({ ctx: { assistantSessionEpoch: () => live.epoch, operatingQuestionState: live } });
  assert.equal(captured.length, 2);
  for (const options of captured) { assert.equal(options.getSessionEpoch(), 4); assert.equal(options.getState(), live.value); }
  live.epoch = 5; live.value = { fresh: true };
  for (const options of captured) { assert.equal(options.getSessionEpoch(), 5); assert.equal(options.getState(), live.value); }
});

test('question UI injection exposes the current auth epoch without session material', () => {
  const main = fs.readFileSync('public/app-main.js', 'utf8');
  const start = main.indexOf("provide('operatingQuestionUi', {");
  const provision = main.slice(start, main.indexOf('\n            });', start) + '\n            });'.length);
  const sandbox = { authSessionEpoch: 10, request: () => {}, provide: (_, value) => { sandbox.ui = value; } };
  for (const match of provision.matchAll(/:\s*([A-Za-z_$][\w$]*)\s*,/g)) sandbox[match[1]] = {};
  for (const match of provision.matchAll(/^\s*([A-Za-z_$][\w$]*),\s*$/gm)) if (!(match[1] in sandbox)) sandbox[match[1]] = {};
  vm.runInNewContext(provision, sandbox);
  assert.equal(sandbox.ui.sessionEpoch(), 10);
  sandbox.authSessionEpoch = 11;
  assert.equal(sandbox.ui.sessionEpoch(), 11);
});
