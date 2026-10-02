import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';

const source = fs.readFileSync(process.env.SUXI_QUESTION_GENERATION_SOURCE || path.resolve('public/app-main.js'), 'utf8');
const section = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
const handler = section('const askOperatingQuestion =', 'const createOperatingQuestionActionIntent =');
const intents = section('const applyOperatingQuestionIntentReadback =', 'const operatingQuestionCouncilReadbackMatches =');
const council = section('const loadLatestOperatingQuestionCouncil =', 'const runOperatingQuestionCouncil =');
assert.ok(handler.includes('readback_verified') && intents.includes('action_intents'));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const freshState = () => ({ question: '合成经营问题', loading: false, council_generation: 0, history: [], media_selected_ids: [], action_intents: {}, error: '', result: null });
const row = (id = 501) => ({ id, tenant_id: 70, hotel_id: 7, platform: 'ctrip', date_start: '2026-09-01', date_end: '2026-09-02', question_text: '合成经营问题', content_digest: 'a'.repeat(64), readback_verified: true, answer_status: 'blocked_by_missing_facts', answer: { decision_frame: { requested_object: '' }, media_evidence_refs: [] } });
const saved = (value = row()) => ({ code: 200, data: { question: value, persistence_status: 'readback_verified' } });
function harness(responder = () => null) {
  let epoch = 0;
  const calls = [], toasts = [];
  const context = { operatingQuestionState: { value: freshState() }, operatingQuestionForm: { value: { hotel_id: '7', platform: 'ctrip', date_start: '2026-09-01', date_end: '2026-09-02', model_key: 'local_second_brain', decision_object: '' } },
    ensureOperatingQuestionScope: () => 7, captureAuthSession: () => ({ epoch }), isAuthSessionCurrent: session => session.epoch === epoch,
    showToast: message => toasts.push(message),
    request: async (url, options) => { calls.push({ url, options }); return await responder(url, options) || (options?.method === 'POST' ? saved() : { code: 200, data: url.includes('council') ? null : row() }); },
    operatingQuestionCouncilReadbackMatches: () => false,
  };
  // The actual intent readback writes through the current ref, which makes an
  // obsolete successful generation observable after auth replaces that ref.
  const ask = vm.runInNewContext(`(() => { let operatingQuestionHistoryOpenRequestId = 0; ${intents} ${council} ${handler} return askOperatingQuestion; })()`, context);
  return { context, ask, calls, toasts, get state() { return context.operatingQuestionState.value; }, get form() { return context.operatingQuestionForm.value; },
    reset(mode = 'both') { if (mode !== 'state') epoch++; if (mode !== 'epoch') context.operatingQuestionState.value = freshState(); },
  };
}
const until = async predicate => { for (let i = 0; i < 30 && !predicate(); i++) await Promise.resolve(); assert.ok(predicate(), 'request reached expected wait point'); };
for (const stage of ['save', 'readback', 'council']) {
  for (const mode of ['both', 'epoch', 'state']) {
    test(`obsolete ${stage} success cannot continue or publish after ${mode} replacement`, async () => {
      const gate = deferred();
      const h = harness((url, options) => (stage === 'save' ? options?.method === 'POST' : stage === 'readback' ? !options && !url.includes('council') : url.includes('council')) ? gate.promise : null);
      const pending = h.ask(); const count = { save: 1, readback: 2, council: 3 }[stage];
      await until(() => h.calls.length === count);
      h.reset(mode); const current = h.state;
      current.action_intents = { retained: true }; current.result = row(900); current.error = '当前操作状态';
      const generation = current.council_generation;
      gate.resolve(stage === 'save' ? saved() : { code: 200, data: row() });
      assert.equal(await pending, null);
      assert.equal(h.calls.length, count, 'no request starts under the next account');
      assert.equal(h.toasts.length, 0, 'no old success toast');
      assert.equal(current.result.id, 900);
      assert.equal(current.action_intents.retained, true);
      assert.equal(current.council_generation, generation);
      assert.equal(current.error, '当前操作状态');
      assert.equal(current.history.length, 0);
    });
  }
  test(`obsolete ${stage} failure cannot replace current error`, async () => {
    const gate = deferred();
    const h = harness((url, options) => (stage === 'save' ? options?.method === 'POST' : stage === 'readback' ? !options && !url.includes('council') : url.includes('council')) ? gate.promise : null);
    const pending = h.ask(); await until(() => h.calls.length === { save: 1, readback: 2, council: 3 }[stage]);
    h.reset('epoch'); h.state.error = '新会话状态';
    gate.reject(new Error('旧会话失败')); assert.equal(await pending, null);
    assert.equal(h.state.error, '新会话状态'); assert.equal(h.toasts.length, 0);
  });
}
test('current strict readback preserves missing-fact status and adds exact history once', async () => {
  const h = harness(); h.state.history = [row()];
  const result = await h.ask();
  assert.equal(result.id, 501); assert.equal(h.state.result.answer_status, 'blocked_by_missing_facts');
  assert.equal(h.state.history.length, 1); assert.equal(h.state.history_loaded_hotel_id, '7');
  assert.equal(h.state.loading, false); assert.match(h.toasts[0], /缺少同范围/);
  const body = JSON.parse(h.calls[0].options.body);
  assert.equal(body.hotel_id, 7); assert.equal(body.platform, 'ctrip'); assert.equal(body.date_end, '2026-09-02');
});
for (const [name, mutate, expected] of [
  ['save rejected', () => ({ code: 500, message: '保存失败' }), /保存失败/],
  ['save receipt missing', () => ({ code: 200, data: { question: row() } }), /严格回读/],
  ['readback failed', null, /回读失败/],
  ['wrong identity', null, /身份不一致/],
]) test(`current ${name} clears busy and permits retry`, async () => {
  let fail = true;
  const h = harness((url, options) => {
    if (!fail) return null;
    if (mutate && options?.method === 'POST') return mutate();
    if (!options && !url.includes('council')) return name === 'wrong identity' ? { code: 200, data: { ...row(), hotel_id: 8 } } : { code: 500, message: '回读失败' };
  });
  assert.equal(await h.ask(), null); assert.equal(h.state.loading, false); assert.equal(h.state.result, null);
  assert.match(h.state.error, expected); assert.equal(h.toasts.length, 0);
  fail = false; assert.equal((await h.ask()).id, 501); assert.equal(h.state.error, '');
});
test('an obsolete save cannot clear a new submission busy state or erase its intents', async () => {
  const old = deferred(), fresh = deferred(); let submissions = 0;
  const h = harness((url, options) => options?.method === 'POST' ? (++submissions === 1 ? old.promise : fresh.promise) : null);
  const first = h.ask(); h.reset(); const second = h.ask(); h.state.action_intents = { retained: true };
  old.resolve(saved(row(500))); assert.equal(await first, null);
  assert.equal(h.state.loading, true); assert.equal(h.state.action_intents.retained, true);
  assert.equal(h.calls.length, 2); assert.equal(h.toasts.length, 0);
  fresh.resolve(saved()); assert.equal((await second).id, 501); assert.equal(h.state.loading, false);
});
test('current missing input does not submit and duplicate clicks do not double-submit', async () => {
  const gate = deferred(); const h = harness(() => gate.promise);
  h.state.question = ''; assert.equal(await h.ask(), null); assert.equal(h.calls.length, 0);
  h.state.question = '合成经营问题'; const first = h.ask();
  assert.equal(await h.ask(), null); assert.equal(h.calls.length, 1);
  gate.resolve({ code: 500, message: '合成失败' }); await first; assert.equal(h.state.loading, false);
});
test('current optional council failure keeps the strictly saved primary answer and an explicit council error', async () => {
  const h = harness(url => url.includes('council') ? { code: 500, message: '会诊回读失败' } : null);
  assert.equal((await h.ask()).id, 501);
  assert.equal(h.state.result.id, 501); assert.equal(h.state.error, '');
  assert.equal(h.state.council_error, '会诊回读失败'); assert.equal(h.state.loading, false);
  assert.equal(h.toasts.length, 1); assert.equal(h.state.history.length, 1);
});
test('auth cancellation swallowed by the real optional council loader cannot publish an old success', async () => {
  const gate = deferred(); const h = harness(url => url.includes('council') ? gate.promise : null);
  const pending = h.ask(); await until(() => h.calls.length === 3);
  h.reset(); h.state.council_error = '新会话会诊状态';
  gate.reject(Object.assign(new Error('synthetic auth cancellation'), { name: 'AbortError' }));
  assert.equal(await pending, null); assert.equal(h.toasts.length, 0);
  assert.equal(h.state.council_error, '新会话会诊状态'); assert.equal(h.state.history.length, 0);
});
