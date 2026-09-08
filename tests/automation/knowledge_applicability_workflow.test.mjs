import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/components/system/knowledge-center-domain.js', 'utf8');
function fixture(request) {
  const sandbox = { window: {}, URLSearchParams, Date, Math };
  vm.runInNewContext(source, sandbox);
  const state = {};
  for (const [name, value] of Object.entries({ knowledgeCenterSelectedUnit: null, knowledgeCenterChunks: [], knowledgeCenterChunkForm: {}, knowledgeCenterFilter: {}, showKnowledgeCenterChunksModal: false, knowledgeCenterLoading: false, knowledgeCenterPagination: {}, knowledgeCenterUnits: [], selectedKnowledgeCenterUnitIds: [] })) state[name] = { value };
  const messages = [];
  let auth = 1;
  const methods = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...state, requireSystemStatic: () => ({}), computed: fn => ({ get value() { return fn(); } }), defaultKnowledgeCenterHotelId: () => 80, defaultKnowledgeExperienceChunk: () => '{}', formatKnowledgeJson: JSON.stringify, request, showToast: message => messages.push(message), captureAuthSession: () => auth, isAuthSessionCurrent: value => value === auth });
  return { state, methods, messages, logout: () => auth++ };
}
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const detail = id => ({ code: 0, data: { unit: { unit_id: id, hotel_id: 80 }, chunks: [{ chunk_id: id * 100 }] } });

test('late detail and its finally cannot replace a newer unit or clear its loading state', async () => {
  const first = deferred(), second = deferred(); let count = 0;
  const f = fixture(() => (++count === 1 ? first : second).promise);
  const a = f.methods.openKnowledgeChunks({ unit_id: 1 });
  const b = f.methods.openKnowledgeChunks({ unit_id: 2 });
  first.resolve(detail(1)); await a;
  assert.equal(f.state.knowledgeCenterSelectedUnit.value.unit_id, 2);
  assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_loading, true);
  second.resolve(detail(2)); await b;
  assert.equal(f.state.knowledgeCenterChunks.value[0].chunk_id, 200);
});

test('detail failure is visible and refresh recovers without losing the edited draft', async () => {
  let fail = true;
  const f = fixture(async () => { if (fail) throw new Error('isolated failure'); return detail(1); });
  await f.methods.openKnowledgeChunks({ unit_id: 1 });
  assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_error, 'isolated failure');
  f.state.knowledgeCenterChunkForm.value.content = 'retained draft'; fail = false;
  await f.methods.openKnowledgeChunks(f.state.knowledgeCenterSelectedUnit.value, { refresh: true });
  assert.equal(f.state.knowledgeCenterChunkForm.value.content, 'retained draft');
  assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_error, '');
});

test('duplicate save is suppressed; failed response retries the same idempotency key', async () => {
  const pending = deferred(); const bodies = []; let saving = 0;
  const f = fixture(async (url, options) => {
    if (!options) return detail(1);
    bodies.push(JSON.parse(options.body)); saving++; return saving === 1 ? pending.promise : { code: 500, msg: 'save error' };
  });
  await f.methods.openKnowledgeChunks({ unit_id: 1 });
  await f.methods.openKnowledgeChunks({ unit_id: 1 }, { editChunk: { chunk_id: 101, type: 'rule', content: { text: 'exposure' }, revision_digest: 'a'.repeat(64) } });
  const save = f.methods.saveKnowledgeChunk(); await f.methods.saveKnowledgeChunk();
  assert.equal(bodies.length, 1); pending.reject(new Error('connection lost')); await save;
  await f.methods.saveKnowledgeChunk();
  assert.equal(bodies[0].request_id, bodies[1].request_id);
  assert.equal(bodies[0].replaces_chunk_id, 101);
  assert.equal(f.state.knowledgeCenterChunkForm.value.saving, false);
});

test('saved data requires exact readback and late save cannot reopen the old scope', async () => {
  const pending = deferred();
  const f = fixture(async (url, options) => options ? pending.promise : detail(url.includes('/2?') ? 2 : 1));
  await f.methods.openKnowledgeChunks({ unit_id: 1 });
  const save = f.methods.saveKnowledgeChunk();
  await f.methods.openKnowledgeChunks({ unit_id: 2 });
  pending.resolve({ code: 0, data: { readback_verified: true } }); await save;
  assert.equal(f.state.knowledgeCenterSelectedUnit.value.unit_id, 2);
  assert.equal(f.messages.length, 0);
  const invalid = fixture(async (url, options) => options ? { code: 0, data: { readback_verified: false } } : detail(1));
  await invalid.methods.openKnowledgeChunks({ unit_id: 1 }); await invalid.methods.saveKnowledgeChunk();
  assert.ok(invalid.messages.some(message => message.includes('精确回读失败')));
});

test('authentication epoch change discards the response', async () => {
  const pending = deferred(); const f = fixture(() => pending.promise);
  const load = f.methods.openKnowledgeChunks({ unit_id: 1 }); f.logout(); pending.resolve(detail(1)); await load;
  assert.equal(f.state.knowledgeCenterChunks.value.length, 0);
});

test('edited applicability filters discard pending results and allow an exact refresh', async () => {
  for (const [field, value] of Object.entries({ platform: 'meituan', as_of: '2026-10-08', hotel_conditions: '{"store_stage":"mature"}', question: 'new question', hotel_id: 81 })) {
    const pending = deferred(); const requests = []; let first = true;
    const f = fixture(url => { requests.push(url); if (first) { first = false; return pending.promise; } return detail(1); });
    f.state.knowledgeCenterFilter.value.platform = 'ctrip';
    const load = f.methods.openKnowledgeChunks({ unit_id: 1, hotel_id: 80 });
    f.state.knowledgeCenterSelectedUnit.value.applicability_query[field] = value;
    f.state.knowledgeCenterChunkForm.value.content = 'retained draft';
    pending.resolve(detail(1)); await load;
    assert.equal(f.state.knowledgeCenterChunks.value.length, 0, field);
    assert.equal(f.state.knowledgeCenterSelectedUnit.value.applicability_query[field], value, field);
    assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_loading, false, field);
    assert.match(f.state.knowledgeCenterSelectedUnit.value.chunks_error, /条件.*变化|重新核验/, field);
    await f.methods.openKnowledgeChunks(f.state.knowledgeCenterSelectedUnit.value, { refresh: true });
    assert.equal(new URL(requests[1], 'http://fixture').searchParams.get(field), String(value), field);
    assert.equal(f.state.knowledgeCenterChunks.value[0].chunk_id, 100, field);
    assert.equal(f.state.knowledgeCenterChunkForm.value.content, 'retained draft', field);
    assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_error, '', field);
  }
});
