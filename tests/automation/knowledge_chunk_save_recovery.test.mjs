import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { createHash } from 'node:crypto';
import { compile, computed, createSSRApp, ref } from 'vue';
import { renderToString } from '@vue/server-renderer';

const sourceArgument = process.argv.find(argument => argument.startsWith('--source='));
const sourcePath = sourceArgument ? sourceArgument.slice('--source='.length) : 'public/components/system/knowledge-center-domain.js';
const source = fs.readFileSync(sourcePath, 'utf8');
const clone = value => JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const unit = id => ({ unit_id: id, hotel_id: 7, can_edit: true, name: `Synthetic unit ${id}` });
const original = (id = 1) => ({ unit_id: id, chunk_id: id * 100 + 1, type: 'rule', content: { text: 'Saved parent' }, revision_digest: 'a'.repeat(64) });
const dialog = fs.readFileSync('resources/frontend/templates/fragments/38-dialogs-knowledge-center.html', 'utf8');
const formStart = dialog.indexOf('<form v-if="knowledgeCenterSelectedUnit?.can_edit !== false" @submit.prevent="saveKnowledgeChunk"');
const formEnd = dialog.indexOf('</form>', formStart);
assert.ok(formStart >= 0 && formEnd > formStart);
const renderEditor = compile(dialog.slice(formStart, formEnd + '</form>'.length));

function fixture() {
  let serial = 0, session = 1, chunkSerial = 1001;
  let nextDetail = null;
  const sandbox = { window: {}, URLSearchParams, Date, Math, crypto: { randomUUID: () => `synthetic-${String(++serial).padStart(12, '0')}` } };
  vm.runInNewContext(source, sandbox);
  const state = Object.fromEntries(Object.entries({
    knowledgeCenterSelectedUnit: null, knowledgeCenterChunks: [], knowledgeCenterChunkForm: {},
    knowledgeCenterFilter: {}, showKnowledgeCenterChunksModal: false, knowledgeCenterLoading: false, knowledgeCenterListError: '',
    knowledgeCenterPagination: {}, knowledgeCenterUnits: [], selectedKnowledgeCenterUnitIds: [],
  }).map(([name, value]) => [name, ref(value)]));
  const posts = [], gets = [], notices = [], saved = new Map(), committed = new Map();
  const methods = sandbox.window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({
    ...state, computed, requireSystemStatic: () => ({}), defaultKnowledgeCenterHotelId: () => 7,
    defaultKnowledgeExperienceChunk: () => '{}', formatKnowledgeJson: JSON.stringify,
    captureAuthSession: () => session, isAuthSessionCurrent: captured => captured === session,
    showToast: (message, type) => notices.push({ message, type: type || 'success' }),
    request: async (url, options) => {
      if (options?.method === 'POST') {
        const wait = deferred(); posts.push({ url, body: JSON.parse(options.body), ...wait }); return wait.promise;
      }
      gets.push(url);
      if (url.startsWith('/knowledge/list?')) return { code: 0, data: { list: [unit(1), unit(2)], pagination: { total: 2, page: 1, page_size: 10, total_page: 1 } } };
      const id = Number(url.match(/^\/knowledge\/(\d+)\?/)?.[1]);
      assert.ok(id, url);
      if (nextDetail) {
        const pending = nextDetail; nextDetail = null; return pending.promise;
      }
      return { code: 0, data: { unit: unit(id), chunks: saved.get(id) || [original(id)] } };
    },
  });
  const edit = async (id = 1, text = 'Submitted A', creating = false) => {
    await methods.openKnowledgeChunks(unit(id));
    if (!creating) await methods.openKnowledgeChunks(state.knowledgeCenterSelectedUnit.value, { editChunk: original(id) });
    state.knowledgeCenterChunkForm.value.type = 'rule';
    state.knowledgeCenterChunkForm.value.content = JSON.stringify({ text });
  };
  const receipt = (index, mutate = value => value) => {
    const post = posts[index];
    const id = Number(post.url.match(/^\/knowledge\/(\d+)\//)?.[1]);
    const key = `${id}:${post.body.request_id}`;
    if (committed.has(key)) return mutate({ ...clone(committed.get(key)), data: { ...clone(committed.get(key).data), replayed: true } });
    const content = { ...clone(post.body.content), knowledge_revision: {
      request_id: post.body.request_id, parent_chunk_id: post.body.replaces_chunk_id || null,
      parent_digest: post.body.replaces_chunk_id ? post.body.expected_digest : null,
    } };
    const chunk = { unit_id: id, chunk_id: ++chunkSerial, type: post.body.type, content,
      revision_digest: createHash('sha256').update(JSON.stringify(content)).digest('hex') };
    saved.set(id, [chunk]);
    const response = { code: 0, data: { readback_verified: true, chunk, reevaluation: { marker: `saved-${id}` } } };
    committed.set(key, clone(response));
    return mutate(response);
  };
  const resolve = (index, mutate) => { const response = receipt(index, mutate); posts[index].resolve(response); return response; };
  const deferNextDetail = () => { nextDetail = deferred(); return nextDetail; };
  const render = () => renderToString(createSSRApp({ setup: () => ({ ...state, saveKnowledgeChunk: methods.saveKnowledgeChunk }), render: renderEditor }));
  return { state, methods, posts, gets, notices, edit, resolve, receipt, deferNextDetail, render, committedCount: () => committed.size, logout: () => session++ };
}

test('current unchanged save resets the editor and refreshes exact saved chunks and unit list', async () => {
  const f = fixture(); await f.edit(); const pending = f.methods.saveKnowledgeChunk();
  const response = f.resolve(0); await pending;
  assert.equal(f.state.knowledgeCenterChunkForm.value.content, '{}');
  assert.equal(f.state.knowledgeCenterChunks.value[0].chunk_id, response.data.chunk.chunk_id);
  assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'Submitted A');
  assert.equal(f.gets.filter(url => url.startsWith('/knowledge/list?')).length, 1);
  assert.equal(f.notices.filter(item => item.type === 'success').length, 1);
  assert.equal(f.notices.filter(item => item.type === 'error').length, 0);
  assert.deepEqual(clone(f.state.knowledgeCenterUnits.value.map(item => item.unit_id)), [1, 2]);
});

for (const creating of [false, true]) {
  test(`${creating ? 'new chunk' : 'revision'}: A receipt retains B and the next save uses A's returned parent, digest and a new request ID`, async () => {
    const f = fixture(); await f.edit(1, 'Submitted A', creating);
    const form = f.state.knowledgeCenterChunkForm.value;
    const first = f.methods.saveKnowledgeChunk();
    form.content = JSON.stringify({ text: 'New unsaved B' }); form.type = 'note';
    const firstResponse = f.resolve(0); await first;
    assert.equal(f.state.knowledgeCenterChunkForm.value, form, 'Retain the actual edited Vue form.');
    assert.equal(JSON.parse(form.content).text, 'New unsaved B');
    assert.equal(form.type, 'note');
    assert.equal(form.replaces_chunk_id, firstResponse.data.chunk.chunk_id);
    assert.equal(form.expected_digest, firstResponse.data.chunk.revision_digest);
    assert.equal(form.saving, false);
    assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'Submitted A');
    assert.equal(f.gets.filter(url => url.startsWith('/knowledge/list?')).length, 1);
    assert.match(f.notices[0].message, /新修改.*保留.*尚未保存/);
    const second = f.methods.saveKnowledgeChunk();
    assert.equal(f.posts[1].body.content.text, 'New unsaved B');
    assert.equal(f.posts[1].body.replaces_chunk_id, firstResponse.data.chunk.chunk_id);
    assert.equal(f.posts[1].body.expected_digest, firstResponse.data.chunk.revision_digest);
    assert.notEqual(f.posts[1].body.request_id, f.posts[0].body.request_id);
    f.resolve(1); await second;
    assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'New unsaved B');
    assert.equal(f.state.knowledgeCenterChunkForm.value.content, '{}');
  });
}

test('unchanged network retry keeps its original idempotency key and repeated click stays locked', async () => {
  const f = fixture(); await f.edit();
  const form = f.state.knowledgeCenterChunkForm.value;
  const first = f.methods.saveKnowledgeChunk(); await f.methods.saveKnowledgeChunk();
  assert.equal(f.posts.length, 1);
  f.posts[0].reject(new Error('Synthetic connection interrupted')); await first;
  assert.equal(form.saving, false); assert.equal(JSON.parse(form.content).text, 'Submitted A');
  const second = f.methods.saveKnowledgeChunk();
  assert.equal(f.posts[1].body.request_id, f.posts[0].body.request_id);
  f.resolve(1); await second;
  assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'Submitted A');
});

for (const getFirst of [true, false]) {
  test(`same-unit preview refresh: ${getFirst ? 'GET before POST' : 'POST before GET'} preserves the save receipt and newer revision draft`, async () => {
    const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
    const first = f.methods.saveKnowledgeChunk();
    form.content = JSON.stringify({ text: 'New unsaved B' });
    const olderRead = f.deferNextDetail();
    const preview = f.methods.openKnowledgeChunks(f.state.knowledgeCenterSelectedUnit.value, { refresh: true });
    const releasePreview = () => olderRead.resolve({ code: 0, data: { unit: unit(1), chunks: [original(1)] } });
    assert.equal(f.state.knowledgeCenterChunkForm.value, form);
    assert.equal(form.saving, true);
    await f.methods.saveKnowledgeChunk(); assert.equal(f.posts.length, 1);
    if (getFirst) { releasePreview(); await preview; }
    const response = f.resolve(0); await first;
    assert.equal(f.state.knowledgeCenterChunkForm.value, form);
    assert.equal(JSON.parse(form.content).text, 'New unsaved B');
    assert.equal(form.replaces_chunk_id, response.data.chunk.chunk_id);
    assert.equal(form.expected_digest, response.data.chunk.revision_digest);
    assert.equal(f.state.knowledgeCenterChunks.value[0].chunk_id, response.data.chunk.chunk_id);
    assert.match(f.notices.at(-1).message, /新修改.*保留.*尚未保存/);
    if (!getFirst) { releasePreview(); await preview; }
    assert.equal(f.state.knowledgeCenterChunks.value[0].chunk_id, response.data.chunk.chunk_id, 'The old preview cannot overwrite saved A.');
    assert.equal(f.state.knowledgeCenterSelectedUnit.value.chunks_loading, false);
    const second = f.methods.saveKnowledgeChunk();
    assert.equal(f.posts[1].body.content.text, 'New unsaved B');
    assert.equal(f.posts[1].body.replaces_chunk_id, response.data.chunk.chunk_id);
    assert.equal(f.posts[1].body.expected_digest, response.data.chunk.revision_digest);
    assert.notEqual(f.posts[1].body.request_id, f.posts[0].body.request_id);
    f.resolve(1); await second;
    assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'New unsaved B');
  });
}

test('same-unit refresh followed by transport failure retains input, reports failure and retries the unchanged request ID', async () => {
  const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
  const first = f.methods.saveKnowledgeChunk();
  await f.methods.openKnowledgeChunks(f.state.knowledgeCenterSelectedUnit.value, { refresh: true, evaluate: true });
  f.posts[0].reject(new Error('Synthetic connection interrupted after preview')); await first;
  assert.equal(f.state.knowledgeCenterChunkForm.value, form);
  assert.equal(JSON.parse(form.content).text, 'Submitted A');
  assert.equal(form.replaces_chunk_id, 101); assert.equal(form.expected_digest, 'a'.repeat(64));
  assert.equal(form.saving, false); assert.equal(f.notices.at(-1)?.type, 'error');
  const second = f.methods.saveKnowledgeChunk();
  assert.equal(f.posts[1].body.request_id, f.posts[0].body.request_id);
  f.resolve(1); await second;
  assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'Submitted A');
});

test('network failure preserves a newer draft without retargeting it or inventing success', async () => {
  const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
  const pending = f.methods.saveKnowledgeChunk(); form.content = JSON.stringify({ text: 'New unsaved B' });
  f.posts[0].reject(new Error('Synthetic connection interrupted')); await pending;
  assert.equal(JSON.parse(form.content).text, 'New unsaved B');
  assert.equal(form.replaces_chunk_id, 101); assert.equal(form.expected_digest, 'a'.repeat(64));
  assert.equal(form.saving, false); assert.equal(f.notices.filter(item => item.type === 'success').length, 0);
});

test('committed A with a lost response confirms only A, retains B and then saves B on the confirmed parent', async () => {
  const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
  const first = f.methods.saveKnowledgeChunk(); const submitted = clone(f.posts[0]);
  form.content = JSON.stringify({ text: 'New unsaved B' });
  const committedA = f.receipt(0);
  f.posts[0].reject(new Error('Synthetic response lost after commit')); await first;
  assert.ok(form.pending_save); assert.equal(f.committedCount(), 1);
  const html = await f.render();
  assert.match(html, />确认上次保存<\/button>/);
  assert.match(html, /新修改.*保留.*确认.*继续保存/);
  assert.doesNotMatch(html, /<textarea\b[^>]*(?:disabled|readonly)/);
  f.state.knowledgeCenterSelectedUnit.value.applicability_query.question = 'New preview scope';
  await f.methods.openKnowledgeChunks(f.state.knowledgeCenterSelectedUnit.value, { refresh: true });
  assert.ok(form.pending_save, 'GET readback must not consume pending confirmation.');
  const confirmation = f.methods.saveKnowledgeChunk();
  await f.methods.saveKnowledgeChunk(); assert.equal(f.posts.length, 2);
  assert.equal(f.posts[1].url, submitted.url); assert.deepEqual(f.posts[1].body, submitted.body);
  f.resolve(1); await confirmation;
  assert.equal(f.committedCount(), 1, 'Idempotent confirmation adds no synthetic revision.');
  assert.equal(f.posts.length, 2, 'Confirmation must not implicitly submit B.');
  assert.equal(JSON.parse(form.content).text, 'New unsaved B');
  assert.equal(form.replaces_chunk_id, committedA.data.chunk.chunk_id);
  assert.equal(form.expected_digest, committedA.data.chunk.revision_digest);
  assert.equal(form.pending_save, undefined);
  assert.match(f.notices.at(-1).message, /尚未保存.*再次保存/);
  const second = f.methods.saveKnowledgeChunk();
  assert.equal(f.posts[2].body.content.text, 'New unsaved B');
  assert.equal(f.posts[2].body.replaces_chunk_id, committedA.data.chunk.chunk_id);
  assert.equal(f.posts[2].body.expected_digest, committedA.data.chunk.revision_digest);
  assert.notEqual(f.posts[2].body.request_id, submitted.body.request_id);
  f.resolve(2); await second;
  assert.equal(f.committedCount(), 2); assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'New unsaved B');
});

test('repeated confirmation failures keep original A while newer B and then C remain editable', async () => {
  const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
  const first = f.methods.saveKnowledgeChunk(); f.receipt(0);
  form.content = JSON.stringify({ text: 'New unsaved B' });
  f.posts[0].reject(new Error('Synthetic lost receipt')); await first;
  for (let attempt = 1; attempt <= 2; attempt++) {
    const confirmation = f.methods.saveKnowledgeChunk();
    assert.deepEqual(f.posts[attempt].body, f.posts[0].body); assert.equal(f.posts[attempt].url, f.posts[0].url);
    form.content = JSON.stringify({ text: 'Newest unsaved C' });
    f.posts[attempt].reject(new Error('Synthetic confirmation interruption')); await confirmation;
    assert.ok(form.pending_save); assert.equal(JSON.parse(form.content).text, 'Newest unsaved C');
    assert.equal(form.replaces_chunk_id, 101); assert.equal(form.saving, false);
  }
  const last = f.methods.saveKnowledgeChunk(); const response = f.resolve(3); await last;
  assert.equal(form.replaces_chunk_id, response.data.chunk.chunk_id);
  assert.equal(JSON.parse(form.content).text, 'Newest unsaved C'); assert.equal(f.committedCount(), 1);
});

for (const status of [400, 401, 403, 404, 409, 422]) {
  for (const transport of [false, true]) {
    test(`${transport ? 'HTTP' : 'business'} ${status} rejection releases confirmation and permits a changed draft`, async () => {
      const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
      const first = f.methods.saveKnowledgeChunk();
      if (transport) f.posts[0].reject(Object.assign(new Error('Synthetic explicit rejection'), { status, data: { code: 500 } }));
      else f.posts[0].resolve({ code: status, msg: 'Synthetic explicit rejection' });
      await first; assert.equal(form.pending_save, undefined);
      form.content = JSON.stringify({ text: 'Corrected B' });
      const second = f.methods.saveKnowledgeChunk();
      assert.equal(f.posts[1].body.content.text, 'Corrected B');
      assert.notEqual(f.posts[1].body.request_id, f.posts[0].body.request_id);
      f.resolve(1); await second;
    });
  }
}

for (const [name, reject] of [
  ['HTTP 500 with business 422', post => post.reject(Object.assign(new Error('Synthetic ambiguous failure'), { status: 500, data: { code: 422 } }))],
  ['business 500', post => post.resolve({ code: 500, msg: 'Synthetic ambiguous failure' })],
  ['unverified success', post => post.resolve({ code: 0, data: { readback_verified: false } })],
]) {
  test(`${name} keeps pending A rather than sending edited B`, async () => {
    const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
    const first = f.methods.saveKnowledgeChunk(); form.content = JSON.stringify({ text: 'New unsaved B' });
    reject(f.posts[0]); await first; assert.ok(form.pending_save);
    const confirmation = f.methods.saveKnowledgeChunk();
    assert.deepEqual(f.posts[1].body, f.posts[0].body);
    f.resolve(1); await confirmation;
    assert.equal(JSON.parse(form.content).text, 'New unsaved B'); assert.equal(form.pending_save, undefined);
  });
}

test('an incomplete confirmation receipt cannot clear pending A even when A remains unchanged', async () => {
  const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
  const first = f.methods.saveKnowledgeChunk(); f.posts[0].reject(new Error('Synthetic lost receipt')); await first;
  const confirmation = f.methods.saveKnowledgeChunk();
  f.posts[1].resolve({ code: 0, data: { readback_verified: true } }); await confirmation;
  assert.equal(f.state.knowledgeCenterChunkForm.value, form); assert.ok(form.pending_save);
  assert.equal(JSON.parse(form.content).text, 'Submitted A'); assert.equal(form.saving, false);
  const retry = f.methods.saveKnowledgeChunk(); assert.deepEqual(f.posts[2].body, f.posts[0].body);
  f.resolve(2); await retry; assert.equal(f.state.knowledgeCenterChunkForm.value.content, '{}');
});

for (const change of ['unit', 'auth', 'form']) {
  test(`${change} change prevents an old receipt from clearing or retargeting the newer editor`, async () => {
    const f = fixture(); await f.edit(); const pending = f.methods.saveKnowledgeChunk();
    if (change === 'unit') await f.edit(2, 'New editor B');
    if (change === 'auth') { f.logout(); f.state.knowledgeCenterChunkForm.value.content = JSON.stringify({ text: 'New editor B' }); }
    if (change === 'form') f.state.knowledgeCenterChunkForm.value = { type: 'note', content: JSON.stringify({ text: 'New editor B' }), replaces_chunk_id: 301, expected_digest: 'c'.repeat(64) };
    const form = f.state.knowledgeCenterChunkForm.value, before = clone(form), reads = f.gets.length;
    f.resolve(0); await pending;
    assert.equal(f.state.knowledgeCenterChunkForm.value, form);
    assert.equal(form.content, before.content); assert.equal(form.replaces_chunk_id, before.replaces_chunk_id);
    assert.equal(form.expected_digest, before.expected_digest);
    assert.equal(f.gets.length, reads); assert.equal(f.notices.length, 0);
  });
}

test('old finally cannot unlock a replacement editor submission or invalidate its successful readback', async () => {
  const f = fixture(); await f.edit(); const first = f.methods.saveKnowledgeChunk();
  f.state.knowledgeCenterChunkForm.value = { type: 'note', content: JSON.stringify({ text: 'New editor B' }), replaces_chunk_id: 301, expected_digest: 'c'.repeat(64) };
  const replacement = f.state.knowledgeCenterChunkForm.value;
  const second = f.methods.saveKnowledgeChunk();
  f.resolve(0); await first;
  assert.equal(f.state.knowledgeCenterChunkForm.value, replacement); assert.equal(replacement.saving, true);
  f.resolve(1); await second;
  assert.equal(f.state.knowledgeCenterChunks.value[0].content.text, 'New editor B');
  assert.equal(f.state.knowledgeCenterChunkForm.value.content, '{}');
});

for (const [name, mutate] of [
  ['missing chunk', response => { delete response.data.chunk; return response; }],
  ['wrong unit', response => { response.data.chunk.unit_id = 2; return response; }],
  ['boolean chunk ID', response => { response.data.chunk.chunk_id = true; return response; }],
  ['array chunk ID', response => { response.data.chunk.chunk_id = [1002]; return response; }],
  ['exponent chunk ID', response => { response.data.chunk.chunk_id = '1e3'; return response; }],
  ['invalid digest', response => { response.data.chunk.revision_digest = 'bad'; return response; }],
  ['wrong saved request', response => { response.data.chunk.content.knowledge_revision.request_id = 'different-request'; return response; }],
  ['wrong parent', response => { response.data.chunk.content.knowledge_revision.parent_chunk_id = 202; return response; }],
  ['wrong parent digest', response => { response.data.chunk.content.knowledge_revision.parent_digest = 'b'.repeat(64); return response; }],
  ['unchanged parent ID', response => { response.data.chunk.chunk_id = 101; return response; }],
]) {
  test(`${name} cannot become the parent of a retained new draft`, async () => {
    const f = fixture(); await f.edit(); const form = f.state.knowledgeCenterChunkForm.value;
    const pending = f.methods.saveKnowledgeChunk(); form.content = JSON.stringify({ text: 'New unsaved B' });
    f.resolve(0, mutate); await pending;
    assert.equal(f.state.knowledgeCenterChunkForm.value, form);
    assert.equal(JSON.parse(form.content).text, 'New unsaved B');
    assert.equal(form.replaces_chunk_id, 101); assert.equal(form.expected_digest, 'a'.repeat(64));
    assert.equal(form.saving, false); assert.equal(f.notices.filter(item => item.type === 'success').length, 0);
    assert.equal(f.notices.at(-1).type, 'error');
  });
}
