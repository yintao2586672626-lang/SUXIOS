import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const domain = fs.readFileSync('public/components/system/knowledge-center-domain.js', 'utf8');
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const sourceDto = { code: 200, data: { chunk_id: 20, title: 'Synthetic source', digest: 'a'.repeat(64),
  source_segments: [{ id: 'segment-1', locator: '原文第1段', quote: '合成原文' }] } };
const chunk = { chunk_id: 20, content: {} };
const fields = ['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'];
const values = title => ({ title, ...Object.fromEntries(fields.flatMap(key => [[key, '合成 ' + key], [key + '_source', '20:segment-1']])) });
function harness({ request, dialog } = {}) {
  const requests = [], notices = [], dialogs = [], confirmations = [];
  let defaultHotel = 80, uuid = 0;
  const ref = Vue.ref;
  const state = { currentPage: ref('knowledge-center'), knowledgeCenterSelectedUnit: ref({ unit_id: 10, hotel_id: 80, name: 'Synthetic' }),
    knowledgeCenterFilter: ref({ hotel_id: 80 }), selectedKnowledgeCenterUnitIds: ref([10]), knowledgeCenterUnits: ref([]), knowledgeCenterBatchDeleting: ref(false) };
  const window = {};
  vm.runInNewContext(domain, { window, crypto: { randomUUID: () => 'synthetic-key-' + (++uuid) },
    confirm: message => { confirmations.push(message); return false; } });
  const methods = window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...state, computed: Vue.computed,
    captureAuthSession: () => 1, isAuthSessionCurrent: epoch => epoch === 1, defaultKnowledgeCenterHotelId: () => defaultHotel,
    requireSystemStatic: () => [], sameAiGovernanceJson: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    showToast: (message, type) => notices.push({ message, type }),
    request: async (url, options = {}) => { requests.push({ url, options }); return request ? request(url, options) : sourceDto; },
    openWorkflowFormDialog: async input => { dialogs.push(input); return dialog ? dialog(input) : null; },
  });
  return { state, methods, requests, notices, dialogs, confirmations, setDefaultHotel: id => { defaultHotel = id; } };
}

test('more than eight selected references is explicitly rejected before any partial source fetch', async () => {
  const h = harness(); h.state.selectedKnowledgeCenterUnitIds.value = Array.from({ length: 9 }, (_, i) => i + 10);
  await h.methods.editKnowledgeReference();
  assert.equal(h.requests.length, 0); assert.equal(h.dialogs.length, 0);
  assert.match(h.notices[0].message, /每次最多合并 8 项资料，请减少勾选/);
  assert.deepEqual(h.state.selectedKnowledgeCenterUnitIds.value, Array.from({ length: 9 }, (_, i) => i + 10));
});

test('failed reference retry reuses the key only for identical values and keeps changed draft values', async () => {
  const entered = [values('稿A'), values('稿A'), values('稿B')];
  const h = harness({ dialog: () => entered.shift(), request: async (url, options) => options.method === 'POST'
    ? { code: 409, message: 'Synthetic save conflict' } : sourceDto });
  for (let i = 0; i < 3; i++) await h.methods.editKnowledgeReference(chunk);
  const writes = h.requests.filter(row => row.options.method === 'POST').map(row => JSON.parse(row.options.body));
  assert.equal(writes.length, 3); assert.equal(writes[0].idempotency_key, writes[1].idempotency_key);
  assert.notEqual(writes[2].idempotency_key, writes[1].idempotency_key);
  assert.deepEqual(writes.map(row => row.title), ['稿A', '稿A', '稿B']);
  assert.ok(writes.every(row => row.hotel_id === 80 && row.citations.length === 5));
  assert.equal(h.dialogs[1].fields.find(field => field.name === 'title').value, '稿A');
  assert.equal(h.notices.length, 3); assert.ok(h.notices.every(row => row.type === 'error' && row.message.includes('草稿已保留')));
});

test('a later reference open owns the dialog when an older same-scope source read returns last', async () => {
  const first = deferred(), second = deferred(); let n = 0;
  const h = harness({ request: () => (++n === 1 ? first : second).promise });
  const a = h.methods.editKnowledgeReference(chunk), b = h.methods.editKnowledgeReference(chunk);
  second.resolve(sourceDto); await b; assert.equal(h.dialogs.length, 1);
  first.resolve(sourceDto); await a; assert.equal(h.dialogs.length, 1); assert.equal(h.notices.length, 0);
});

test('changing the default hotel invalidates an outstanding reference read even when its filter is unchanged', async () => {
  const read = deferred(), h = harness({ request: () => read.promise });
  const action = h.methods.editKnowledgeReference(chunk); h.setDefaultHotel(81);
  read.resolve(sourceDto); await action;
  assert.equal(h.dialogs.length, 0); assert.equal(h.notices.length, 0); assert.equal(h.requests.length, 1);
});

test('protected selected knowledge is refused before confirmation or delete; editable selection still offers confirmation', async () => {
  const h = harness(); h.state.knowledgeCenterUnits.value = [{ unit_id: 10, can_edit: false }, { unit_id: 11, can_edit: true }];
  h.state.selectedKnowledgeCenterUnitIds.value = [10, 11]; await h.methods.batchDeleteKnowledgeUnits();
  assert.equal(h.confirmations.length, 0); assert.equal(h.requests.length, 0); assert.equal(h.state.knowledgeCenterBatchDeleting.value, false);
  assert.match(h.notices[0].message, /所选资料含保护版本/); assert.deepEqual(h.state.selectedKnowledgeCenterUnitIds.value, [10, 11]);
  h.state.selectedKnowledgeCenterUnitIds.value = [11]; await h.methods.batchDeleteKnowledgeUnits();
  assert.equal(h.confirmations.length, 1); assert.match(h.confirmations[0], /1 条知识/); assert.equal(h.requests.length, 0);
});

test('the actual workflow dialog renders its higher layer and backdrop while retaining native form submit', async () => {
  const main = fs.readFileSync('public/app-main.js', 'utf8');
  const start = main.indexOf('            const createWorkflowFormDialogState =');
  const end = main.indexOf('            let runtimeErrorRecoveryQueued =', start);
  assert.ok(start >= 0 && end > start);
  const sandbox = { ...Vue };
  vm.runInNewContext(main.slice(start, end) + '\nglobalThis.ui={workflowFormDialog,openWorkflowFormDialog,closeWorkflowFormDialog,submitWorkflowFormDialog};', sandbox);
  const template = parse(fs.readFileSync('resources/frontend/templates/fragments/46-global-toast.html', 'utf8')).children
    .find(node => node.type === 1 && node.loc.source.includes('data-testid="workflow-form-dialog"')).loc.source;
  const render = new Function('Vue', compile(template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  let vnode;
  const result = sandbox.ui.openWorkflowFormDialog({ title: '参考稿', fields: [{ name: 'title', label: '标题', required: true, value: '原题' }] });
  const app = Vue.createSSRApp({ setup: () => sandbox.ui, render() { vnode = render.call(this, this, []); return vnode; } });
  const html = await renderToString(app);
  assert.equal(vnode.props.style['z-index'], '70'); assert.equal(vnode.props.style.background, 'rgba(2,6,23,.6)');
  assert.match(html, /role="dialog"/); assert.match(html, /aria-modal="true"/);
  const walk = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(walk) : [])];
  const input = walk(vnode).find(node => node.type === 'input'); input.props['onUpdate:modelValue']('新参考稿');
  walk(vnode).find(node => node.type === 'form').props.onSubmit({ preventDefault() {} });
  assert.equal((await result).title, '新参考稿'); assert.equal(sandbox.ui.workflowFormDialog.value.visible, false);
});
