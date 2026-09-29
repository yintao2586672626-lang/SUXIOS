import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';
import { createSSRApp, h, nextTick, reactive } from 'vue';
import { renderToString } from '@vue/server-renderer';

const panelSource = readFileSync(new URL('../../public/components/operations/task-workflow-panel.js', import.meta.url), 'utf8');
const mainSource = readFileSync('public/app-main.js', 'utf8');
const start = mainSource.indexOf('            const executeApiRequest = async (');
const end = mainSource.indexOf('            // API 请求', start);
assert.ok(start >= 0 && end > start, 'load the actual HTTP adapter');
const executeSource = mainSource.slice(start, end);
const tick = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const copy = value => JSON.parse(JSON.stringify(value));
const row = () => ({
  task_id: 1, intent_id: 1, version: 1, readback_verified: true, historical: false, history: [],
  scope: { hotel_id: 7, tenant_id: 42, platform: 'ctrip', date_start: '2026-09-15', date_end: '2026-09-15', object_ref: 'synthetic-object' },
  source: { module: 'manual' }, task_status: 'in_progress', approval_status: 'approved',
  verification: { status: 'pending' }, review: { status: 'pending' }, next_step: { key: 'record', label: '登记材料' },
  completion_criteria: ['synthetic check'], dependencies: [], execution_records: [],
});
function harness() {
  const pendingFetch = [];
  const sandbox = {
    window: {}, crypto: webcrypto, Intl, Date, console: { error() {} }, API_BASE: 'https://synthetic.invalid/api',
    readRequestCooldown: { check: () => null, record() {} }, isAuthSessionCurrent: () => true,
    isTerminalAuthFailureResponse: () => false,
    fetch: (url, options) => new Promise((resolve, reject) => pendingFetch.push({ url, options, resolve, reject })),
  };
  vm.runInNewContext(panelSource + '\n' + executeSource + '\nglobalThis.syntheticExecuteApiRequest = executeApiRequest;', sandbox);
  const component = sandbox.window.SUXI_TASK_WORKFLOW_PANEL.create({ h });
  const state = reactive({ ...component.data(), hotelId: 7, canExecute: true });
  state.request = (url, options = {}) => sandbox.syntheticExecuteApiRequest({ requestSession: { epoch: 1 }, requestUrl: url, requestOptions: options, headers: {} });
  for (const [name, method] of Object.entries(component.methods)) state[name] = method.bind(state);
  state.selected = row(); state.selectedId = 1; state.items = [state.selected]; state.fillForm();
  const nodes = () => {
    const found = [];
    const walk = node => {
      if (Array.isArray(node)) return node.forEach(walk);
      if (!node || typeof node !== 'object') return;
      found.push(node); walk(node.children);
    };
    walk(component.render.call(state));
    return found;
  };
  const button = label => nodes().find(node => node.type === 'button' && node.children === label);
  const click = label => { const node = button(label); assert.ok(node && !node.props.disabled, label + ' is enabled'); return node.props.onClick(); };
  const html = () => renderToString(createSSRApp({ render: () => component.render.call(state) }));
  const reply = (index, status, data) => pendingFetch[index].resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
  return { component, state, nodes, button, click, html, reply, pendingFetch };
}
async function enterAndSubmit(p) {
  p.click('登记执行材料');
  for (const [label, value] of [['材料引用', 'synthetic://material/one'], ['执行记录', 'synthetic preserved material note']]) {
    const input = p.nodes().find(node => node.props?.['aria-label'] === label);
    assert.ok(input, label + ' is visible');
    input.props.onInput({ target: { value } });
  }
  p.nodes().find(node => node.type === 'input' && node.props.type === 'checkbox').props.onChange({ target: { checked: true } });
  const form = copy(p.state.form);
  p.click('保存执行材料');
  assert.equal(p.pendingFetch.length, 1);
  assert.equal(p.pendingFetch[0].options.method, 'POST');
  const body = p.pendingFetch[0].options.body;
  const input = JSON.parse(body);
  assert.equal(input.action, 'record');
  assert.equal(input.record.note, form.note);
  assert.equal(input.record.reference, form.reference);
  assert.doesNotMatch(await p.html(), /data-testid="workflow-form"/);
  assert.ok(p.nodes().filter(node => node.type === 'button').every(node => node.props.disabled));
  assert.deepEqual(copy(p.state.form), form);
  return { form, body, input };
}

for (const [name, status, code] of [
  ['HTTP 400', 400, 400], ['HTTP 422', 422, 422], ['HTTP 409', 409, 409],
  ['HTTP 200 business 400', 200, 400], ['HTTP 200 business 422', 200, 422], ['HTTP 200 business 409', 200, 409],
  ['HTTP 422 with inconsistent body 500', 422, 500],
]) {
  test(`${name} restores the original record editor after definite rejection`, async () => {
    const p = harness(), original = await enterAndSubmit(p);
    p.reply(0, status, { code, message: 'synthetic definite rejection' });
    await tick();
    assert.equal(p.state.saving, false);
    assert.equal(p.state.pending, null);
    assert.equal(p.state.mode, 'record');
    assert.deepEqual(copy(p.state.form), original.form);
    const html = await p.html();
    assert.match(html, /data-testid="workflow-form"/);
    assert.ok(html.includes(original.form.note));
    assert.ok(html.includes(original.form.reference));
    assert.equal(p.button('保存执行材料').props.disabled, false);
    assert.equal(p.button('重试原提交'), undefined);
    assert.equal(p.state.message, '');
    assert.match(p.state.error, /synthetic definite rejection/);
    if (status === 409 || (status === 200 && code === 409)) assert.match(p.state.error, /请读取最新版本/);
  });
}

async function recoverAndRetry(p, original) {
  assert.equal(p.state.saving, false);
  assert.ok(p.state.pending);
  assert.equal(JSON.stringify(p.state.pending.body), original.body);
  assert.deepEqual(copy(p.state.form), original.form);
  assert.doesNotMatch(await p.html(), /data-testid="workflow-form"/);
  assert.equal(p.button('回读确认保存').props.disabled, false);
  assert.equal(p.button('重试原提交').props.disabled, false);
  const recovery = p.click('回读确认保存');
  p.reply(1, 200, { code: 200, data: row() });
  await recovery;
  assert.ok(p.state.pending);
  assert.match(p.state.message, /未找到原提交/);
  assert.equal(JSON.stringify(p.state.pending.body), original.body);
  const retry = p.click('重试原提交');
  assert.equal(p.pendingFetch[2].options.body, original.body);
  const saved = { ...row(), version: 2, history: [{ request_id: original.input.request_id, action: 'record', version: 2 }], execution_records: [original.input.record] };
  p.reply(2, 200, { code: 200, data: { workflow: saved, saved_version: 2, replayed: false } });
  await tick();
  p.reply(3, 200, { code: 200, data: { scope: saved.scope, items: [saved] } });
  await retry;
  assert.equal(p.state.pending, null);
  assert.equal(p.state.selected.version, 2);
  assert.deepEqual(copy(p.state.selected.execution_records[0]), original.input.record);
  assert.match(p.state.message, /已保存并回读版本 2/);
  assert.ok((await p.html()).includes(original.input.record.note));
}
for (const failure of ['HTTP 500', 'HTTP 500 with body 422', 'transport', 'unverified success']) {
  test(`${failure} retains the exact request through missing-event recovery and retry`, async () => {
    const p = harness(), original = await enterAndSubmit(p);
    if (failure === 'transport') p.pendingFetch[0].reject(new Error('synthetic transport loss'));
    else if (failure === 'unverified success') p.reply(0, 200, { code: 200, data: { workflow: row(), saved_version: 2 } });
    else p.reply(0, 500, { code: failure.includes('422') ? 422 : 500, message: 'synthetic uncertain save' });
    await tick();
    await recoverAndRetry(p, original);
  });
}

test('a thrown business rejection without an HTTP status restores the original editor', async () => {
  const p = harness(), original = await enterAndSubmit(p);
  p.pendingFetch[0].reject(Object.assign(new Error('synthetic legacy business rejection'), { data: { code: 422 } }));
  await tick();
  assert.equal(p.state.pending, null);
  assert.deepEqual(copy(p.state.form), original.form);
  assert.match(await p.html(), /data-testid="workflow-form"/);
});

for (const recoveryFailure of [false, true]) test(`a definite rejection after ${recoveryFailure ? 'failed' : 'missing-event'} recovery restores the editable draft across later reads`, async () => {
  const p = harness(), original = await enterAndSubmit(p);
  p.pendingFetch[0].reject(new Error('synthetic transport loss'));
  await tick();
  const recovery = p.click('回读确认保存');
  if (recoveryFailure) p.pendingFetch[1].reject(new Error('synthetic recovery read failure'));
  else p.reply(1, 200, { code: 200, data: row() });
  await recovery;
  const retry = p.click('重试原提交');
  assert.equal(p.pendingFetch[2].options.body, original.body);
  p.reply(2, 422, { code: 422, message: 'synthetic definitive retry rejection' });
  await retry;
  assert.equal(p.state.pending, null);
  assert.equal(p.state.selected.task_id, 1);
  assert.equal(p.state.selected.version, 1, 'restore only the original verified version');
  assert.equal(p.state.mode, 'record');
  assert.deepEqual(copy(p.state.form), original.form);
  assert.match(await p.html(), /data-testid="workflow-form"/);
  const correctedNote = 'synthetic corrected material note';
  p.nodes().find(node => node.props?.['aria-label'] === '执行记录').props.onInput({ target: { value: correctedNote } });
  const current = { ...row(), version: 2, history: [{ version: 1, action: 'configure', request_id: 'synthetic-earlier' }] };
  const latest = p.click('读取最新版本');
  p.reply(3, 200, { code: 200, data: current });
  await latest;
  assert.equal(p.state.selected.version, 2, 'a newer version must come from the actual readback');
  assert.equal(p.state.form.note, correctedNote);
  assert.equal(p.state.form.reference, original.form.reference);
  assert.equal(p.state.mode, 'record');
  const history = p.click('历史 v1');
  p.reply(4, 200, { code: 200, data: { ...row(), historical: true } });
  await history;
  assert.doesNotMatch(await p.html(), /data-testid="workflow-form"/);
  const returnCurrent = p.click('读取最新版本');
  p.reply(5, 200, { code: 200, data: current });
  await returnCurrent;
  assert.equal(p.state.form.note, correctedNote);
  assert.equal(p.state.form.reference, original.form.reference);
  assert.match(await p.html(), /data-testid="workflow-form"/);
});

async function rejectedDraft() {
  const p = harness();
  await enterAndSubmit(p);
  p.reply(0, 422, { code: 422, message: 'synthetic definite rejection' });
  await tick();
  assert.ok(p.state.retainedDraft);
  return p;
}

for (const watchKey of ['hotelId', 'context']) test(`${watchKey} scope reset discards the rejected draft`, async () => {
  const p = await rejectedDraft();
  if (watchKey === 'hotelId') p.state.hotelId = 8;
  else p.state.context = { session: 'synthetic-next-session' };
  p.component.watch[watchKey].handler.call(p.state);
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.pending, null);
  assert.equal(p.state.selected, null);
  assert.equal(p.state.mode, '');
  p.reply(1, 200, { code: 200, data: { scope: { hotel_id: Number(p.state.hotelId), tenant_id: 42 }, items: [] } });
  await tick();
  assert.doesNotMatch(await p.html(), /data-testid="workflow-form"/);
});

test('switching tasks discards the rejected draft and cannot restore it on return', async () => {
  const p = await rejectedDraft();
  const other = { ...row(), task_id: 2, intent_id: 2 };
  p.state.items.push(other);
  const otherButton = p.nodes().find(node => node.type === 'button' && String(node.children).startsWith('#2 '));
  assert.ok(otherButton && !otherButton.props.disabled);
  const selected = otherButton.props.onClick();
  p.reply(1, 200, { code: 200, data: other });
  await selected;
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.selected.task_id, 2);
  assert.equal(p.state.mode, '');
  assert.equal(p.state.form.note, '');
  const firstButton = p.nodes().find(node => node.type === 'button' && String(node.children).startsWith('#1 '));
  const back = firstButton.props.onClick();
  p.reply(2, 200, { code: 200, data: row() });
  await back;
  p.click('登记执行材料');
  assert.equal(p.state.form.note, '');
  assert.equal(p.state.form.reference, '');
});

test('starting another edit mode explicitly discards the rejected material draft', async () => {
  const p = await rejectedDraft();
  p.click('登记阻塞');
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.mode, 'block');
  assert.equal(p.state.form.note, '');
});

test('the configure button clears a rejected postpone draft before a later current read', async () => {
  const p = harness();
  const current = { ...row(), task_status: 'pending', next_step: { key: 'start', label: '开始任务' } };
  const read = p.click('读取最新版本');
  p.reply(0, 200, { code: 200, data: current });
  await read;
  p.click('延期');
  for (const [label, value] of [['操作原因', 'synthetic postponed draft'], ['新截止日期', '2026-09-18']]) {
    p.nodes().find(node => node.props?.['aria-label'] === label).props.onInput({ target: { value } });
  }
  p.click('保存状态变更');
  assert.equal(JSON.parse(p.pendingFetch[1].options.body).action, 'postpone');
  p.reply(1, 422, { code: 422, message: 'synthetic definite rejection' });
  await tick();
  assert.equal(p.state.mode, 'postpone');
  assert.equal(p.state.retainedDraft.mode, 'postpone');
  p.click('设置任务条件');
  assert.equal(p.state.mode, 'configure');
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.form.reason, '');
  p.nodes().find(node => node.props?.['aria-label'] === '执行对象（房型、页面或服务问题编号）').props.onInput({ target: { value: 'synthetic new configuration' } });
  assert.match(await p.html(), /保存任务条件/);
  const refresh = p.click('读取最新版本');
  p.reply(2, 200, { code: 200, data: current });
  await refresh;
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.mode, '');
  assert.equal(p.state.form.reason, '');
  assert.doesNotMatch(await p.html(), /synthetic postponed draft/);
  assert.equal(p.pendingFetch.filter(item => item.options.method === 'POST').length, 1);
});

test('a corrected fresh submission uses a new request and clears retained draft only after exact success', async () => {
  const p = await rejectedDraft();
  const firstBody = JSON.parse(p.pendingFetch[0].options.body);
  const note = 'synthetic corrected record';
  p.nodes().find(node => node.props?.['aria-label'] === '执行记录').props.onInput({ target: { value: note } });
  p.click('保存执行材料');
  const body = JSON.parse(p.pendingFetch[1].options.body);
  assert.notEqual(body.request_id, firstBody.request_id);
  assert.equal(body.expected_version, 1);
  assert.equal(body.record.note, note);
  const saved = { ...row(), version: 2, history: [{ request_id: body.request_id, action: 'record', version: 2 }], execution_records: [body.record] };
  p.reply(1, 200, { code: 200, data: { workflow: saved, saved_version: 2, replayed: false } });
  await tick();
  p.reply(2, 200, { code: 200, data: { scope: saved.scope, items: [saved] } });
  await tick();
  assert.equal(p.state.retainedDraft, null);
  assert.equal(p.state.pending, null);
  assert.equal(p.state.mode, '');
  assert.equal(p.state.selected.version, 2);
  assert.equal(p.state.selected.execution_records[0].note, note);
});
