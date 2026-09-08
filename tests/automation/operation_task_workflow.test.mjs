import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const context = { window: {}, crypto: { randomUUID: () => 'synthetic' } };
vm.runInNewContext(fs.readFileSync(new URL('../../public/components/operations/task-workflow-panel.js', import.meta.url), 'utf8'), context);
const component = context.window.SUXI_TASK_WORKFLOW_PANEL.create({ h: () => null });
const state = () => {
  const state = { ...component.data(), hotelId: 7, canExecute: true };
  for (const [name, fn] of Object.entries(component.methods)) state[name] = fn.bind(state);
  return state;
};
const row = (hotel = 7, version = 1) => ({ task_id: 1, intent_id: 1, scope: { hotel_id: hotel, tenant_id: 42, platform: 'ctrip', date_start: '2026-09-08', date_end: '2026-09-08' }, readback_verified: true, version, history: [], dependencies: [] });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
test('late hotel response never restores stale tasks or clears new loading', async () => {
  const s = state(), a = deferred(), b = deferred();
  s.request = () => a.promise; const first = s.load();
  s.hotelId = 8; s.epoch++; s.request = () => b.promise; const second = s.load();
  a.resolve({ code: 200, data: { scope: { hotel_id: 7, tenant_id: 42 }, items: [row()] } }); await first;
  assert.equal(s.items.length, 0); assert.equal(s.loading, true);
  b.resolve({ code: 200, data: { scope: { hotel_id: 8, tenant_id: 42 }, items: [row(8)] } }); await second;
  assert.equal(s.items[0].scope.hotel_id, 8); assert.equal(s.loading, false);
});
test('late same-hotel refresh cannot overwrite newer response', async () => {
  const s = state(), a = deferred(), b = deferred(); let i = 0;
  s.request = () => ++i === 1 ? a.promise : b.promise;
  const first = s.load(), second = s.load();
  b.resolve({ code: 200, data: { scope: { hotel_id: 7, tenant_id: 42 }, items: [row(7, 2)] } }); await second;
  a.resolve({ code: 200, data: { scope: { hotel_id: 7, tenant_id: 42 }, items: [row(7, 1)] } }); await first;
  assert.equal(s.items[0].version, 2);
});
test('save timeout holds exact request for retry and prevents a new mutation', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1; const sent = [];
  s.request = async (_url, options) => { sent.push(options.body); throw new Error('timeout'); };
  await s.send('start'); const body = s.pending.body;
  await s.send('block', { reason: 'new' }); assert.equal(sent.length, 1);
  await s.send('', {}, true); assert.equal(sent.length, 2); assert.equal(sent[0], sent[1]); assert.equal(s.pending.body.request_id, body.request_id);
});
test('save reply from old hotel is ignored', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1; const d = deferred(); s.request = () => d.promise;
  const save = s.send('start'); s.epoch++; s.hotelId = 8; s.selected = row(8); s.saving = true;
  d.resolve({ code: 200, data: { workflow: row(), saved_version: 2, replayed: false } }); await save;
  assert.equal(s.selected.scope.hotel_id, 8); assert.equal(s.saving, true);
});
test('wrong scope response and wrong historical version are visible failures', async () => {
  const s = state(); s.request = async () => ({ code: 200, data: row(8) }); await s.select(1);
  assert.equal(s.selected, null); assert.match(s.error, /身份/);
  s.request = async () => ({ code: 200, data: row(7, 2) }); await s.select(1, 1);
  assert.equal(s.selected, null); assert.match(s.error, /历史版本/);
});
test('history confirms an ambiguous successful save without resubmitting', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1;
  s.pending = { taskId: 1, body: { request_id: 'original' } };
  s.request = async () => ({ code: 200, data: { ...row(7, 2), history: [{ request_id: 'original' }] } });
  await s.recover(); assert.equal(s.pending, null); assert.match(s.message, /保存成功/);
});

test('completion button submits checked criteria in configured order after reverse clicks', () => {
  const h = (type, props, children) => ({ type, props: typeof props === 'object' && !Array.isArray(props) ? props : {}, children: children ?? props });
  const rendered = context.window.SUXI_TASK_WORKFLOW_PANEL.create({ h });
  const s = state();
  s.selected = { ...row(7, 3), completion_criteria: ['check A', 'check B'], task_status: 'in_progress',
    source: { module: 'manual' }, verification: { status: 'pending' }, review: { status: 'pending' },
    next_step: { label: 'record' }, execution_records: [] };
  s.mode = 'complete'; s.form = { checks: [] };
  const nodes = [];
  const walk = node => {
    if (Array.isArray(node)) return node.forEach(walk);
    if (!node || typeof node !== 'object') return;
    nodes.push(node); walk(node.children);
  };
  walk(rendered.render.call(s));
  const boxes = nodes.filter(node => node.type === 'input' && node.props.type === 'checkbox');
  assert.equal(boxes.length, 2);
  boxes[1].props.onChange({ target: { checked: true } });
  boxes[0].props.onChange({ target: { checked: true } });
  assert.deepEqual(Array.from(s.form.checks), ['check B', 'check A']);
  const submit = nodes.find(node => node.type === 'button' && node.children === '确认完成填报');
  assert.ok(submit);
  let sent;
  s.send = (action, body) => { sent = { action, body }; };
  submit.props.onClick();
  assert.equal(sent.action, 'complete');
  assert.deepEqual(Array.from(sent.body.completed_criteria), ['check A', 'check B']);
  boxes[0].props.onChange({ target: { checked: false } });
  submit.props.onClick();
  assert.deepEqual(Array.from(sent.body.completed_criteria), ['check B']);
});
