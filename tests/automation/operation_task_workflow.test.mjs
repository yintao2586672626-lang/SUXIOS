import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

let syntheticRequest = 0;
const context = { window: {}, crypto: { randomUUID: () => `synthetic-${++syntheticRequest}` } };
vm.runInNewContext(fs.readFileSync(new URL('../../public/components/operations/task-workflow-panel.js', import.meta.url), 'utf8'), context);
const component = context.window.SUXI_TASK_WORKFLOW_PANEL.create({ h: () => null });
const state = () => {
  const state = { ...component.data(), hotelId: 7, canExecute: true };
  for (const [name, fn] of Object.entries(component.methods)) state[name] = fn.bind(state);
  return state;
};
const row = (hotel = 7, version = 1) => ({ task_id: 1, intent_id: 1, scope: { hotel_id: hotel, tenant_id: 42, platform: 'ctrip', date_start: '2026-09-08', date_end: '2026-09-08' }, readback_verified: true, version, history: [], dependencies: [] });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const reviewState = review => {
  const s = state(); s.selected = { ...row(), source: { module: 'manual' }, approval_status: 'approved',
    task_status: 'completed', verification: { status: 'manual_verified' }, review,
    execution_records: [], completion_criteria: [], next_step: { label: '复核观察' } };
  return s;
};
const observation = (value, end = '2026-09-08') => ({
  scope: { ...row().scope, date_start: end, date_end: end, object_ref: 'room:101' },
  metric: '浏览转化率', unit: 'percent', value, reference: 'synthetic:' + end, source: 'manual_input'
});
const renderedText = s => renderNodes(s).map(n => typeof n.children === 'string' || typeof n.children === 'number' ? n.children : '').join('\n');
test('read-only completed task retains conditions, registered checks and human verification basis', () => {
  const s = reviewState({ status: 'pending' }); s.canExecute = false;
  s.selected.completion_criteria = ['核查甲条件', '核查乙条件'];
  s.selected.execution_records = [{ performed_on: '2026-09-08', kind: 'manual_check', note: '原登记', reference: 'synthetic:record', checks: ['核查甲条件'], recorded_by: 5, recorded_at: '2026-09-09 10:00:00' }];
  s.selected.verification = { status: 'manual_verified', reason: '对照两项条件人工确认', verified_by: 3, verified_at: '2026-09-10 12:00:00', source: 'human_attestation' };
  const text = renderedText(s);
  for (const expected of ['完成条件', '核查甲条件', '核查乙条件', '材料内登记的核查项', '登记人：5', '2026-09-09 10:00:00', '对照两项条件人工确认', '核实人：3', '2026-09-10 12:00:00', '人工确认']) assert.ok(text.includes(expected), expected);
  assert.equal(renderNodes(s).filter(n => n.type === 'button' && n.children === '人工核实').length, 0);
});

test('weak screenshot explains pending verification without inventing an attestation', () => {
  const s = reviewState({ status: 'pending' }); s.canExecute = false;
  s.selected.completion_criteria = ['核查条件'];
  s.selected.execution_records = [{ performed_on: '2026-09-08', kind: 'screenshot', note: '截图', reference: 'synthetic:image', checks: [] }];
  s.selected.verification = { status: 'pending', reason: '截图或回执为弱证据，需补充同对象逐项人工核查' };
  const text = renderedText(s);
  assert.match(text, /执行核实：待核实；复盘：待复盘/);
  assert.match(text, /截图或回执为弱证据/); assert.match(text, /未登记逐项核查/);
  assert.doesNotMatch(text, /核实人：|核实时间：|来源：人工确认/);
});

test('historic conditions and attestation are visible while writes remain unavailable', () => {
  const s = reviewState({ status: 'pending' }); s.selected.historical = true;
  s.selected.completion_criteria = ['旧版本原条件']; s.form.criteria = '当前新草稿';
  s.selected.verification = { status: 'manual_verified', reason: '旧版本原核实', verified_by: 3, verified_at: '2026-09-08 12:00:00', source: 'human_attestation' };
  const text = renderedText(s); assert.match(text, /旧版本原条件/); assert.match(text, /旧版本原核实/); assert.doesNotMatch(text, /当前新草稿/);
  assert.equal(renderNodes(s).filter(n => n.type === 'button' && n.children === '人工核实').length, 0);
});

test('legacy task and new-cycle task explicitly show their unverified condition', () => {
  const s = reviewState({ status: 'pending' }); s.selected.task_status = 'legacy_unconfigured';
  s.selected.verification = { status: 'pending', reason: '旧记录尚未通过新合同核实' };
  assert.match(renderedText(s), /尚未保存完成条件/); assert.match(renderedText(s), /旧记录尚未通过新合同核实/);
  s.selected.task_status = 'reopened'; s.selected.verification.reason = '新轮次需要重新核实';
  assert.match(renderedText(s), /新轮次需要重新核实/);
});

test('stored condition and verification text is passed as text children without HTML parsing', () => {
  const s = reviewState({ status: 'pending' }), marker = '<img src=x onerror=alert(1)>';
  s.selected.completion_criteria = [marker]; s.selected.verification = { status: 'manual_verified', reason: marker };
  const nodes = renderNodes(s); assert.match(renderedText(s), /<img src=x onerror=alert\(1\)>/);
  assert.equal(nodes.some(n => n.type === 'img' || n.props?.innerHTML), false);
});

test('saved review shows exact observations, true zero, units, sources and each window', () => {
  const s = reviewState({ status: 'reviewed', note: '人工观察', reason: '变化不证明因果', delta: 3.5, delta_unit: 'percentage_point',
    before: observation(0), after: observation(3.5, '2026-09-09') });
  const text = renderedText(s);
  for (const expected of ['前窗观察', '后窗观察', '浏览转化率：0 %', '浏览转化率：3.5 %', 'synthetic:2026-09-08', 'synthetic:2026-09-09', '酒店 7', '租户 42', 'ctrip', 'room:101', '人工录入', '3.5 个百分点', '变化不证明因果']) assert.ok(text.includes(expected), expected);
});

test('missing review observations remain explicit and no zero is invented', () => {
  const text = renderedText(reviewState({ status: 'reviewed', note: '尚无数据', reason: '前后证据缺失，不以零补齐', delta: null }));
  assert.match(text, /未保存前窗观察/); assert.match(text, /未保存后窗观察/); assert.match(text, /变化：未计算/);
  assert.doesNotMatch(text, /：0 %|人工录入/);
});

test('incomparable saved windows retain both numeric observations without a delta', () => {
  const before = { ...observation(2), metric: '订单数', unit: 'count' };
  const after = { ...observation(14, '2026-09-09'), metric: '订单数', unit: 'count' }; after.scope.date_end = '2026-09-15';
  const text = renderedText(reviewState({ status: 'reviewed', note: '仅观察', reason: '前后窗口天数不同', delta: null, before, after }));
  for (const expected of ['订单数：2 次', '订单数：14 次', '2026-09-09—2026-09-15', '变化：未计算']) assert.ok(text.includes(expected), expected);
});

test('history uses saved observation scope rather than the current task scope', () => {
  const s = reviewState({ status: 'reviewed', note: '历史', delta: 0, delta_unit: 'percent', before: observation(0), after: observation(0, '2026-09-09') });
  s.selected.historical = true; s.selected.scope.date_start = '2026-09-20'; s.selected.scope.date_end = '2026-09-20';
  const nodes = renderNodes(s);
  const dates = nodes.filter(n => typeof n.children === 'string' && n.children.includes('2026-09-09—2026-09-09'));
  assert.ok(dates.length > 0); assert.match(renderedText(s), /变化：0 %/);
  assert.equal(nodes.filter(n => n.type === 'button' && n.children === '复盘观察').length, 0);
});

test('unconfigured legacy workflow has no invented review and sparse observations stay unknown', () => {
  const pending = renderedText(reviewState({ status: 'pending' })); assert.doesNotMatch(pending, /前窗观察|后窗观察/);
  const text = renderedText(reviewState({ status: 'reviewed', note: '旧快照', before: {}, after: { reference: '<b>原文</b>' } }));
  assert.match(text, /指标未记录：未记录/); assert.match(text, /来源未记录/); assert.match(text, /<b>原文<\/b>/); assert.match(text, /变化：未计算/);
});
const receipt = (body, overrides = {}) => ({ ...row(7, Number(body.expected_version) + 1),
  history: [{ request_id: body.request_id, action: body.action, version: Number(body.expected_version) + 1 }], ...overrides });
const listReply = items => ({ code: 200, data: { scope: { hotel_id: 7, tenant_id: 42 }, items } });
const renderNodes = s => {
  const h = (type, props, children) => ({ type, props: typeof props === 'object' && !Array.isArray(props) ? props : {}, children: children ?? props });
  const nodes = [];
  const walk = node => {
    if (Array.isArray(node)) return node.forEach(walk);
    if (!node || typeof node !== 'object') return;
    nodes.push(node); walk(node.children);
  };
  walk(context.window.SUXI_TASK_WORKFLOW_PANEL.create({ h }).render.call(s));
  return nodes;
};
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
  s.pending = { taskId: 1, body: { hotel_id: 7, request_id: 'original', expected_version: 1, action: 'start' } };
  s.request = async () => ({ code: 200, data: receipt(s.pending.body) });
  await s.recover(); assert.equal(s.pending, null); assert.match(s.message, /保存成功/);
});

test('normal save confirms its event and clears success when another task is selected', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1; let saved;
  s.request = async (url, options) => {
    if (options.method === 'POST') {
      saved = receipt(JSON.parse(options.body));
      return { code: 200, data: { workflow: saved, saved_version: 2, replayed: false } };
    }
    if (url.includes('task-workflows?')) return listReply([saved]);
    return { code: 200, data: { ...row(), task_id: 2 } };
  };
  await s.send('start');
  assert.equal(s.selected.version, 2); assert.equal(s.pending, null); assert.match(s.message, /已保存并回读版本 2/);
  await s.select(2);
  assert.equal(s.selected.task_id, 2); assert.equal(s.message, '');
});

test('idempotent replay accepts its original saved event in a newer current workflow', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1;
  s.request = async () => { throw new Error('synthetic lost response'); }; await s.send('start');
  const original = s.pending;
  const current = receipt(original.body, { version: 3 });
  current.history.push({ request_id: 'synthetic-other-user', action: 'record', version: 3 });
  s.request = async (_url, options) => options.method === 'POST'
    ? { code: 200, data: { workflow: current, saved_version: 2, replayed: true } } : listReply([current]);
  await s.send('', {}, true);
  assert.equal(s.selected.version, 3); assert.equal(s.pending, null); assert.match(s.message, /未重复写入/);
});

test('save response without the exact submitted event cannot claim success', async t => {
  for (const mismatch of ['request', 'action', 'version', 'saved_version', 'historical', 'scope']) {
    await t.test(mismatch, async () => {
      const s = state(); s.selected = row(); s.selectedId = 1;
      s.request = async (_url, options) => {
        if (options.method !== 'POST') return listReply([]);
        const workflow = receipt(JSON.parse(options.body));
        if (mismatch === 'request') workflow.history[0].request_id = 'another-request';
        if (mismatch === 'action') workflow.history[0].action = 'block';
        if (mismatch === 'version') workflow.history[0].version = 1;
        if (mismatch === 'historical') workflow.historical = true;
        if (mismatch === 'scope') workflow.scope = { ...workflow.scope, platform: 'meituan' };
        return { code: 200, data: { workflow, saved_version: mismatch === 'saved_version' ? 3 : 2, replayed: false } };
      };
      await s.send('start');
      assert.ok(s.pending); assert.equal(s.message, ''); assert.notEqual(s.error, ''); assert.equal(s.selected.version, 1);
    });
  }
});

test('failed recovery retains visible controls and retries the exact request without a selected detail', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1; const sent = []; let saved;
  s.request = async (url, options) => {
    if (options.method === 'POST') {
      sent.push(options.body);
      if (sent.length === 1) throw new Error('synthetic lost response');
      saved = receipt(JSON.parse(options.body));
      return { code: 200, data: { workflow: saved, saved_version: 2, replayed: true } };
    }
    if (url.includes('task-workflows?')) return listReply([saved]);
    throw new Error('synthetic read timeout');
  };
  await s.send('start'); await s.recover();
  assert.equal(s.selected, null); assert.ok(s.pending); assert.match(s.error, /read timeout/);
  const nodes = renderNodes(s);
  const retry = nodes.find(node => node.type === 'button' && node.children === '重试原提交');
  assert.ok(retry); assert.equal(retry.props.disabled, false);
  await retry.props.onClick();
  assert.equal(sent.length, 2); assert.equal(sent[0], sent[1]); assert.equal(s.pending, null);
  assert.equal(s.selected.task_id, 1); assert.equal(s.selectedId, 1); assert.match(s.message, /未重复写入/);
});

test('superseded recovery cannot clear a newer ambiguous submission', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1;
  await (async () => { s.request = async () => { throw new Error('synthetic save timeout'); }; await s.send('start'); })();
  const original = s.pending, slowRead = deferred();
  s.request = () => slowRead.promise; const slowRecovery = s.recover();
  s.request = async () => ({ code: 200, data: receipt(original.body) });
  await s.recover(); assert.equal(s.pending, null);
  s.request = async () => { throw new Error('synthetic second timeout'); };
  await s.send('block', { reason: 'synthetic blocked' });
  const newerPending = s.pending;
  slowRead.resolve({ code: 200, data: receipt(original.body) }); await slowRecovery;
  assert.equal(s.pending, newerPending); assert.equal(s.message, ''); assert.match(s.error, /second timeout/);
});

test('recovery requires the original event and scope while keeping unresolved requests', async t => {
  for (const mismatch of ['request', 'action', 'version', 'historical', 'scope', 'missing_history']) {
    await t.test(mismatch, async () => {
      const s = state(); s.selected = row(); s.selectedId = 1;
      s.request = async () => { throw new Error('synthetic timeout'); }; await s.send('start');
      const original = s.pending, data = receipt(original.body);
      if (mismatch === 'request') data.history[0].request_id = 'another-request';
      if (mismatch === 'action') data.history[0].action = 'block';
      if (mismatch === 'version') data.history[0].version = 1;
      if (mismatch === 'historical') data.historical = true;
      if (mismatch === 'scope') data.scope = { ...data.scope, date_start: '2026-09-09', date_end: '2026-09-09' };
      if (mismatch === 'missing_history') delete data.history;
      s.request = async () => ({ code: 200, data }); await s.recover();
      assert.equal(s.pending, original); assert.doesNotMatch(s.message, /保存成功/);
    });
  }
});

test('a delayed recovery cannot restore the prior task after newer recovery permits switching', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1;
  s.request = async () => { throw new Error('synthetic timeout'); }; await s.send('start');
  const original = s.pending, slowRead = deferred();
  s.request = () => slowRead.promise; const lateRecovery = s.recover();
  s.request = async () => ({ code: 200, data: receipt(original.body) }); await s.recover();
  s.request = async () => ({ code: 200, data: { ...row(), task_id: 2 } }); await s.select(2);
  slowRead.resolve({ code: 200, data: receipt(original.body) }); await lateRecovery;
  assert.equal(s.selected.task_id, 2); assert.equal(s.selectedId, 2); assert.equal(s.message, '');
});

test('recovery remains read-only when execution permission is absent', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1;
  s.request = async () => { throw new Error('synthetic timeout'); }; await s.send('start');
  const original = s.pending; s.canExecute = false; s.selected = null;
  let writes = 0;
  s.request = async (_url, options) => { writes += options.method === 'POST' ? 1 : 0; return { code: 200, data: receipt(original.body) }; };
  const retry = renderNodes(s).find(node => node.type === 'button' && node.children === '重试原提交');
  assert.equal(retry.props.disabled, true);
  await s.send('', {}, true); assert.equal(writes, 0); assert.equal(s.pending, original);
  await s.recover(); assert.equal(s.pending, null); assert.match(s.message, /保存成功/);
});

test('an ambiguous submission blocks task switching and a rejected save keeps failure visible', async () => {
  const s = state(); s.selected = row(); s.selectedId = 1; let requests = 0;
  s.request = async () => { requests++; throw new Error('synthetic timeout'); }; await s.send('start');
  await s.select(2);
  assert.equal(requests, 1); assert.equal(s.selected.task_id, 1); assert.match(s.error, /原任务/);
  s.request = async () => ({ code: 409, message: 'synthetic version conflict' });
  await s.send('', {}, true);
  assert.equal(s.pending, null); assert.equal(s.message, ''); assert.match(s.error, /最新版本/);
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
