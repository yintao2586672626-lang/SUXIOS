import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile, parse } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const folder = 'output/autonomous-verification/20260915-round36/';
const helperPath = 'tests/automation/operating_growth_event_save_recovery.test.mjs';
const definition = fs.readFileSync(process.env.SUXI_ANNOTATION_FIXTURE_SOURCE || helperPath, 'utf8');
const start = definition.indexOf('const folder =');
const end = definition.indexOf("\ntest('normal real submit");
assert.ok(start > 0 && end > start);
let helper = definition.slice(start, end);
const replace = (before, after) => {
  assert.equal(helper.split(before).length, 2, 'one exact fixture-only substitution: ' + before.slice(0, 60));
  helper = helper.replace(before, after);
};
// Reuse fixture definitions, not round34 tests. Product is evaluated verbatim from actual paths.
replace("const domain = slice('const operatingGrowthStaticScript =', 'const addOperatingGrowthAnnotation =');",
  "const domain = slice('const operatingGrowthStaticScript =', 'const openOperatingGrowthSource =');\n");
replace("const template = fs.readFileSync('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html', 'utf8');",
  "const template = fs.readFileSync('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html', 'utf8') + parse(fs.readFileSync('resources/frontend/templates/fragments/46-global-toast.html', 'utf8')).children.find(node => node.type === 1 && node.props.some(prop => prop.name === 'data-testid' && prop.value?.content === 'workflow-form-dialog')).loc.source;");
replace('let wrongDigest = false, tree,', 'let wrongDigest = false, tree, pageTree,');
replace("assert.equal(url, '/operation/growth-archive/events');", "assert.match(url, /^\\/operation\\/growth-archive\\/\\d+\\/annotations$/);");
if (!helper.includes("const dialog = slice(")) helper += "\nconst dialog = slice('const createWorkflowFormDialogState =', 'let runtimeErrorRecoveryQueued =');";
if (!helper.includes('${dialog}')) replace('${requests}\\n${domain}', '${requests}\\n${dialog}\\n${domain}');
replace('globalThis.ui = { operatingGrowthArchiveBody,', 'globalThis.ui = { workflowFormDialog, submitWorkflowFormDialog, closeWorkflowFormDialog, operatingGrowthArchiveBody,');
replace('render: renderPage }', 'render(...args) { pageTree = renderPage.apply(this, args); return pageTree; } }');
replace("const button = kind === 'open' ?", "const button = kind === 'note' ? find(node => node.type === 'button' && /^(补充批注|继续编辑批注|确认上次批注)$/.test(content(node))) : kind === 'open' ?");
// This in-memory persistence adapter mirrors addOwnerAnnotation's documented key/digest contract.
// It is not a PHP service execution or evidence of real database persistence.
const oldCommitStart = helper.indexOf('  const commit = (index, respond = true) => {');
const oldCommitEnd = helper.indexOf('  return { ui, operationFilters,', oldCommitStart);
assert.ok(oldCommitStart > 0 && oldCommitEnd > oldCommitStart);
helper = helper.slice(0, oldCommitStart) + `
  const commit = (index, respond = true) => {
    const post = posts[index], memoryId = Number(post.url.match(/growth-archive\\/(\\d+)/)[1]), parent = persisted.get(memoryId);
    const payload = { tenant_id: 5, hotel_id: parent.hotel_id, parent_memory_id: memoryId, annotation: post.body.annotation, recorded_by: 42 };
    const digest = sha(JSON.stringify(payload));
    const key = 'owner-annotation:' + memoryId + ':' + sha(post.body.client_request_id);
    const old = keys.get(key);
    assert.ok(!old || old.content_digest === digest, 'synthetic adapter requires same content for same request key');
    const memory = old || { ...parent, id: Math.max(...persisted.keys()) + 1, memory_key: key, content_digest: digest,
      memory_layer: 'judgement', event_kind: 'judgement', title: '老板批注 · ' + parent.title,
      summary: post.body.annotation, source_record_type: 'hotel_operating_memory', source_record_id: memoryId,
      previous_memory_id: memoryId, is_owner_annotation: true,
      evidence_refs: [{ type: 'hotel_operating_memory', id: memoryId }],
      context: { event_kind: 'judgement', relation_type: 'owner_annotation', parent_memory_id: memoryId,
        parent_quality_status: parent.quality_status, annotated_by: 42, annotated_at: '2026-09-15 10:00:00' } };
    keys.set(key, memory); persisted.set(memory.id, memory);
    if (respond) post.resolve(bodyResponse({ code: 200, data: { memory, created: !old,
      persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }));
    return memory;
  };
  const findDialog = predicate => { const found = flatten(pageTree).find(predicate); assert.ok(found, 'actual compiled dialog control'); return found; };
  const typeNote = async value => {
    await render(); const textarea = findDialog(node => node.type === 'textarea');
    assert.ok(!textarea.props.disabled && !textarea.props.readonly);
    textarea.props['onUpdate:modelValue'](value); await Vue.nextTick();
  };
  const submitNote = async () => {
    await render(); const form = findDialog(node => node.type === 'form');
    const submit = flatten(form).find(node => node.type === 'button' && node.props.type === 'submit');
    assert.match(content(submit), /保存批注|确认上次批注/); assert.ok(!submit.props.disabled);
    form.props.onSubmit({ preventDefault() {} }); await flush();
  };
  const cancelNote = async () => {
    await render(); findDialog(node => node.type === 'button' && content(node) === '取消').props.onClick(); await flush();
  };
` + helper.slice(oldCommitEnd);

replace('return { ui, operationFilters, posts, gets,', 'return { typeNote, submitNote, cancelNote, clickNote, clickMilestone, ui, operationFilters, posts, gets,');
helper = helper.replace('  const findDialog = predicate =>', `  const clickNote = async id => {
    await render(); const card = find(node => String(node.props?.['data-event-id'] || '') === String(id));
    const button = flatten(card).find(node => node.type === 'button' && /^(补充批注|继续编辑批注|确认上次批注)$/.test(content(node)));
    assert.ok(button); if (button.props.disabled) return { disabled: true, done: Promise.resolve() };
    const before = tasks.length; button.props.onClick(); return { disabled: false, done: tasks.length > before ? tasks.at(-1).promise : Promise.resolve() };
  };
  const clickMilestone = async () => {
    await render(); const button = find(node => node.type === 'button' && content(node) === '设为里程碑');
    assert.ok(!button.props.disabled); const before = tasks.length; button.props.onClick();
    return { done: tasks.length > before ? tasks.at(-1).promise : Promise.resolve() };
  };
  const findDialog = predicate =>`);

const fixtureProcess = { env: { SUXI_GROWTH_MAIN_SOURCE: process.env.SUXI_ANNOTATION_MAIN_SOURCE || 'public/app-main.js', SUXI_GROWTH_STATIC_SOURCE: process.env.SUXI_ANNOTATION_STATIC_SOURCE || 'public/operating-growth-static.js' } };
const { fixture, flush, bodyResponse } = new Function('assert', 'fs', 'vm', 'createHash', 'webcrypto', 'Vue', 'compile', 'parse', 'renderToString', 'process', helper + '\nreturn { fixture, flush, bodyResponse };')(assert, fs, vm, createHash, webcrypto, Vue, compile, parse, renderToString, fixtureProcess);
const frozenClone = value => JSON.parse(JSON.stringify(value));
const notes = f => [...f.persisted.values()].filter(row => row.is_owner_annotation);
async function ready() {
  const f = fixture();
  f.persisted.set(101, { id: 101, tenant_id: 5, hotel_id: 7, memory_layer: 'fact', event_kind: 'fact',
    business_date: '2026-09-15', occurred_at: '2026-09-15 09:00:00', title: '合成原始事件', summary: '仅合成事实',
    platform: 'manual', source_scope: 'manual_background', source_module: 'operating_growth_archive',
    source_record_type: 'manual_operating_event', content_digest: 'a'.repeat(64), quality_status: 'unverified',
    usage_level: 'archive_only', lifecycle_status: 'active', evidence_refs: [], context: { manual_record: true } });
  const read = await f.click('refresh'); await read.done; return f;
}
async function submit(f, text) {
  const task = await f.click('note'); assert.equal(task.disabled, false);
  await f.typeNote(text); await f.submitNote(); return task;
}
async function confirm(f) {
  assert.match(await f.render(), /确认上次批注/);
  const task = await f.click('note');
  assert.equal(f.ui.workflowFormDialog.value.fields.length, 0, 'confirmation cannot silently accept new text');
  assert.equal(f.ui.workflowFormDialog.value.submitText, '确认上次批注');
  await f.submitNote(); return task;
}

test('normal real dialog save and cancel retain manual source boundary', async () => {
  const f = await ready(); const cancel = await f.click('note'); await f.typeNote('合成取消'); await f.cancelNote(); await cancel.done;
  assert.equal(f.posts.length, 0);
  const a = await submit(f, '合成新批注'); f.commit(0); await a.done;
  assert.equal(notes(f).length, 1); assert.match(await f.render(), /合成新批注/);
  assert.equal(notes(f)[0].quality_status, 'unverified'); assert.equal(notes(f)[0].usage_level, 'archive_only');
  assert.equal(f.notices.at(-1).type, 'success'); assert.doesNotMatch(await f.render(), /确认上次批注|继续编辑批注/);
});

test('explicit HTTP422 retains text for a corrected next submission', async () => {
  const f = await ready(); const a = await submit(f, '合成原稿');
  f.posts[0].resolve(bodyResponse({ code: 422, message: '合成明确拒绝' }, 422)); await a.done;
  assert.match(await f.render(), /继续编辑批注/);
  const b = await f.click('note'); assert.equal(f.ui.workflowFormDialog.value.values.annotation, '合成原稿');
  await f.typeNote('合成修订稿'); await f.submitNote();
  assert.notEqual(f.posts[1].body.client_request_id, f.posts[0].body.client_request_id);
  f.commit(1); await b.done; assert.equal(notes(f).length, 1); assert.equal(notes(f)[0].summary, '合成修订稿');
});

for (const failure of ['before-write-transport', 'committed-lost', 'http500-body422', 'strict-digest', 'strict-http422']) {
  test('unknown original annotation uses original body/ID only: ' + failure, async () => {
    const f = await ready(); const a = await submit(f, '合成原批注 A');
    if (failure === 'before-write-transport') f.posts[0].reject(new Error('synthetic no commit'));
    else if (failure === 'committed-lost') { f.commit(0, false); f.posts[0].reject(new Error('synthetic committed response lost')); }
    else if (failure === 'http500-body422') { f.commit(0, false); f.posts[0].resolve(bodyResponse({ code: 422 }, 500)); }
    else { if (failure === 'strict-digest') f.setWrongDigest(true); else f.setReadFailure({ body: { code: 422 }, status: 422 }); f.commit(0); }
    await a.done; assert.equal(f.posts.length, 1);
    const reopened = await f.click('note');
    assert.equal(f.ui.workflowFormDialog.value.fields.length, 0);
    assert.match(f.ui.workflowFormDialog.value.description, /合成原批注 A/);
    await f.cancelNote(); await reopened.done;
    assert.equal(f.posts.length, 1, 'cancel confirmation does not undo or repeat a write');
    const retry = await confirm(f);
    assert.equal(f.posts[1].options.body, f.posts[0].options.body); assert.equal(f.posts[1].url, f.posts[0].url);
    f.setWrongDigest(false); f.setReadFailure(null); f.commit(1); await retry.done;
    assert.equal(notes(f).length, 1); assert.equal(f.posts.length, 2);
    assert.doesNotMatch(await f.render(), /确认上次批注|继续编辑批注/);
    const b = await submit(f, '合成独立新批注 B');
    assert.notEqual(f.posts[2].body.client_request_id, f.posts[0].body.client_request_id);
    f.commit(2); await b.done; assert.equal(notes(f).length, 2);
  });
}

test('later 422 confirmation cannot disprove an earlier unknown write', async () => {
  const f = await ready(); const a = await submit(f, '合成原稿'); f.commit(0, false); f.posts[0].reject(new Error('synthetic lost')); await a.done;
  const denied = await confirm(f); f.posts[1].resolve(bodyResponse({ code: 422 }, 422)); await denied.done;
  const retry = await confirm(f); assert.equal(f.posts[2].options.body, f.posts[0].options.body);
  f.commit(2); await retry.done; assert.equal(notes(f).length, 1);
});

test('strict receipt of another parent cannot confirm the submitted annotation', async () => {
  const f = await ready(); const a = await submit(f, '合成精确父记录');
  const saved = f.commit(0, false); saved.source_record_id = 999; saved.previous_memory_id = 999; saved.context.parent_memory_id = 999;
  f.posts[0].resolve(bodyResponse({ code: 200, data: { memory: saved, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } })); await a.done;
  assert.match(await f.render(), /确认上次批注/); assert.equal(f.notices.at(-1).type, 'error');
  const retry = await confirm(f); assert.equal(f.posts[1].options.body, f.posts[0].options.body);
  saved.source_record_id = 101; saved.previous_memory_id = 101; saved.context.parent_memory_id = 101;
  f.commit(1); await retry.done; assert.equal(notes(f).length, 1);
});

test('post-save force sends a distinct timeline HTTP; late old response cannot hide annotation', async () => {
  const f = await ready(); const a = await submit(f, '合成新批注');
  f.pauseTimeline(true); f.ignoreTimelineAbort(true);
  const old = await f.click('refresh'); await flush();
  f.commit(0); await flush(); await flush();
  assert.equal(f.timeline.length, 2);
  assert.equal((await f.timeline[1].response.clone().json()).data.list.length, 2);
  f.timeline[1].resolve(f.timeline[1].response); await a.done; await old.done;
  f.timeline[0].resolve(f.timeline[0].response); await flush();
  assert.match(await f.render(), /合成新批注/); assert.equal(f.posts.length, 1);
});

test('page departure during POST preserves original unknown request but old response cannot clear current confirmation', async () => {
  const f = await ready(); const a = await submit(f, '合成跨页原稿');
  f.currentPage.value = 'compass'; await Vue.nextTick(); f.currentPage.value = 'operating-growth-archive'; await Vue.nextTick();
  const retry = await confirm(f); assert.equal(f.posts[1].options.body, f.posts[0].options.body);
  f.commit(0); await a.done; assert.equal((await f.click('note')).disabled, true);
  f.commit(1); await retry.done; assert.equal(notes(f).length, 1);
});

for (const scope of ['hotel', 'auth']) test('old ' + scope + ' response cannot clear a new scope annotation', async () => {
  const f = await ready(); const a = await submit(f, '合成旧范围');
  if (scope === 'hotel') {
    f.persisted.set(201, { ...f.persisted.get(101), id: 201, hotel_id: 8, title: '合成酒店八记录' }); await f.changeHotel(8);
  } else { f.token.value = 'synthetic-auth-next'; await Vue.nextTick(); }
  const b = await submit(f, '合成当前范围');
  f.posts[0].reject(new Error('synthetic old scope error')); await a.done;
  assert.equal((await f.click('note')).disabled, true);
  f.commit(1); await b.done; assert.match(await f.render(), /合成当前范围/);
  assert.doesNotMatch(await f.render(), /确认上次批注/);
});

test('different memory has its own draft and does not inherit a pending annotation', async () => {
  const f = await ready(); const a = await submit(f, '合成目标101'); f.posts[0].reject(new Error('synthetic unknown')); await a.done;
  f.persisted.set(201, { ...f.persisted.get(101), id: 201, title: '合成第二目标' });
  const read = await f.click('refresh'); await read.done;
  const other = await f.clickNote(201); assert.equal(f.ui.workflowFormDialog.value.values.annotation, '');
  await f.typeNote('合成目标201'); await f.submitNote(); assert.match(f.posts[1].url, /\/201\/annotations$/);
  f.commit(1); await other.done;
  const original = await f.clickNote(101); assert.equal(f.ui.workflowFormDialog.value.submitText, '确认上次批注');
  await f.submitNote(); assert.equal(f.posts[2].options.body, f.posts[0].options.body); f.commit(2); await original.done;
  assert.equal(notes(f).length, 2);
});

test('hotel change while real dialog is open does not submit stale target under the new context', async () => {
  const f = await ready(); const open = await f.click('note'); await f.typeNote('合成不应发送');
  f.operationFilters.value.hotel_id = '8'; await Vue.nextTick();
  assert.equal(f.ui.workflowFormDialog.value.visible, false); assert.equal(f.posts.length, 0); await open.done;
});

test('cancel retains same-memory draft across another real shared-dialog entry', async () => {
  const f = await ready(); const open = await f.click('note'); await f.typeNote('合成取消后保留'); await f.cancelNote(); await open.done;
  assert.match(await f.render(), /继续编辑批注/);
  const milestone = await f.clickMilestone(); assert.equal(f.ui.workflowFormDialog.value.title, '设置经营里程碑');
  await f.cancelNote(); await milestone.done;
  const reopened = await f.click('note'); assert.equal(f.ui.workflowFormDialog.value.values.annotation, '合成取消后保留');
  await f.cancelNote(); await reopened.done; assert.equal(f.posts.length, 0);
});

test('confirmed write with failed timeline reports saved and only asks to reread', async () => {
  const f = await ready(); const a = await submit(f, '合成已保存'); f.pauseTimeline(true);
  f.commit(0); await flush(); await flush(); assert.equal(f.timeline.length, 1);
  f.timeline[0].resolve(bodyResponse({ code: 500, message: '合成列表失败' }, 500)); await a.done;
  assert.match(f.notices.at(-1).message, /已保存.*列表刷新失败/); assert.equal(f.notices.at(-1).type, 'warning');
  assert.doesNotMatch(await f.render(), /确认上次批注|继续编辑批注/);
  f.pauseTimeline(false); const read = await f.click('refresh'); await read.done;
  assert.match(await f.render(), /合成已保存/); assert.equal(f.posts.length, 1);
});

for (const malformed of ['empty-digest', 'wrong-summary', 'wrong-relation', 'verified-quality', 'decision-usage']) {
  test('strict annotation receipt rejects ' + malformed, async () => {
    const f = await ready(); const a = await submit(f, '合成精确文本'); const saved = f.commit(0, false);
    if (malformed === 'empty-digest') saved.content_digest = '';
    if (malformed === 'wrong-summary') saved.summary = '合成别人的文本';
    if (malformed === 'wrong-relation') saved.context.relation_type = 'unrelated';
    if (malformed === 'verified-quality') saved.quality_status = 'verified';
    if (malformed === 'decision-usage') saved.usage_level = 'decision_support';
    f.posts[0].resolve(bodyResponse({ code: 200, data: { memory: saved, persistence_status: 'readback_verified', write_boundaries: { ota_write: false, external_message: false } } }));
    await a.done; assert.equal(f.notices.at(-1).type, 'error'); assert.match(await f.render(), /确认上次批注/); assert.equal(f.posts.length, 1);
  });
}
