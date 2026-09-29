import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original full modal ancestors, native controls, review handlers and request
// coordinator. Closed synthetic DTO transport only: no DB, HTTP or OTA action.
// The original open handler is invoked directly; list-entry clicks, browser
// layout/focus and authenticated-account persistence are outside this test.

// Closed synthetic transport only. No DB, browser, real fetch, credentials, or source writes.
const files = {
  main: 'public/app-main.js', operation: 'public/operation-static.js', system: 'public/system-static.js',
  components: 'public/components/system/app-main-components.js',
  ops: 'resources/frontend/templates/fragments/17-page-ops-track.html',
  online: 'resources/frontend/templates/fragments/35-page-online-data.html',
};
// The optional root redirects only templates for pre-integration red/green runs.
const templateRoot = process.env.SUXI_REVIEW_TEMPLATE_ROOT || '.';
const raw = Object.fromEntries(Object.entries(files).map(([key, file]) => [key, fs.readFileSync(file.startsWith('resources/') ? path.resolve(templateRoot, file) : file, 'utf8')]));
const cut = (source, start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  return source.slice(a, b);
};
const main = raw.main.replaceAll('\r\n', '\n');
const parts = {
  reviewSequence: main.match(/^            let operationReviewRequestSeq = 0;$/m)[0],
  context: cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  abort: cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  policy: cut(main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  coordinator: cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  request: cut(main, '            const request = async (', '            const askSystemUsageGuide ='),
  identity: cut(main, '            const operationExecutionHotelId =', '            const collectPriceExecutionIntentFields ='),
  normalize: cut(raw.components, '    const normalizeOperationReviewStatus =', '    const RevenueCockpitOpportunityDetails ='),
  list: cut(main, '            const loadOperationActions = async', '            const parseOperationEvidenceNumber ='),
  review: cut(main, '            const reviewOperationExecutionTask = async', '            const finishOperationAction ='),
  managed: main.match(/const operationIsManagedAction = [^\n]+/)[0],
};
const templates = {};
for (const [key, id] of [['ops', 'operation-review-modal'], ['online', 'core-loop-operation-review-modal']]) {
  const ast = parse(raw[key]); let modal, ancestors;
  const walk = (node, parents = []) => {
    if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === id)) { modal = node; ancestors = parents; }
    for (const child of node.children || []) walk(child, [...parents, node]);
  };
  walk(ast); assert.ok(modal);
  const retain = node => {
    if (node === modal) return node.loc.source;
    const children = (node.children || []).map(retain).join('');
    if (!children || node.type === 0) return children;
    assert.equal(node.type, 1);
    return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
  };
  const markup = retain(ast);
  templates[key] = { text: markup, modalText: modal.loc.source, line: modal.loc.start.line,
    ancestorConditions: ancestors.flatMap(node => (node.props || []).filter(p => p.type === 7 && ['if','show'].includes(p.name)).map(p => p.exp?.content)),
    ancestorTags: ancestors.map(node => node.tag).filter(Boolean),
    render: new Function('Vue', compile(markup, { mode: 'function', prefixIdentifiers: true }).code)(Vue) };
}
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const A = 'Synthetic review A: wait for the matching follow-up facts.';
const B = 'Synthetic review B: another reviewer is waiting for a different receipt.';
const row = summary => ({ id: 111, hotel_id: 7,
  recommendation: { source_module: 'manual', object_type: 'content', expected_metric: 'conversion_rate' },
  execution: { task_id: 11, hotel_id: 7, status: 'executed' },
  review: { status: 'observing', summary }, next_action: { key: 'review', label: '复盘' } });

function harness(template) {
  const requests = [], notices = [];
  let persisted = null;
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 1, currentPage: Vue.ref(template === 'ops' ? 'ops-track' : 'online-data'),
    filterReportHotel: Vue.ref('7'), authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 901, realname: 'Synthetic reviewer', is_super_admin: true }), token: Vue.ref(''),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 80 }]),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), operationYesterday: '2026-09-14', shanghaiBusinessYesterday: '2026-09-14',
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }),
    isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    operationActionsRequestSeq: 0, operationFilters: Vue.ref({ hotel_id: '7' }),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }),
    operatingGoalInterventionLoading: Vue.ref(false), operatingGoalInterventionError: Vue.ref(''),
    operationExecutionViewMode: Vue.ref('all'), operationExecutionFlow: Vue.ref({ capabilities: { hotel_id: 7, can_execute: true }, list: [row('')], data_status: 'ok' }),
    operationActions: Vue.ref([]), operationActionTrackingRead: Vue.ref({}), operationApprovalConfirmingIntentId: Vue.ref(0),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}),
    operatingGoalInterventionOverview: Vue.ref({}), homeOperatingScheduleError: Vue.ref(''),
    operationReviewModalOpen: Vue.ref(false), operationReviewModalItem: Vue.ref(null),
    operationReviewMutationContext: Vue.ref(null), operationReviewForm: Vue.ref({}),
    showToast: (message, type = 'success') => notices.push({ message, type }),
    operationErrorMessage: (error, fallback) => error.message || fallback,
    ensureOperationStaticReady: async () => {}, normalizeOperationHotelSelection: form => form.value.hotel_id,
    loadOperatingMemories: async () => {}, applyHomeOperatingScheduleFlow() {},
    fetch: (url, options) => new Promise((resolve, reject) => {
      assert.equal(new URL(url).origin, 'https://synthetic.invalid');
      const method = options.method || 'GET'; assert.ok(['GET','POST'].includes(method));
      const call = { url, method, body: options.body ? JSON.parse(options.body) : null, resolve, reject, settled: false, aborted: false, response: null };
      requests.push(call);
      const abort = () => { if (!call.settled) { call.settled = true; call.aborted = true; reject(new DOMException('Original signal aborted', 'AbortError')); } };
      call.removeAbort = () => options.signal?.removeEventListener('abort', abort);
      if (options.signal?.aborted) { abort(); return; }
      options.signal?.addEventListener('abort', abort, { once: true });
      const pathname = new URL(url).pathname;
      if (method === 'POST') {
        assert.equal(pathname, '/api/operation/execution-tasks/11/review'); return;
      }
      if (pathname === '/api/operation/execution-tasks/11') {
        assert.equal(new URL(url).searchParams.get('hotel_id'), '7');
        assert.equal(new URL(url).searchParams.get('system_hotel_id'), '7'); assert.ok(persisted); return;
      }
      let data;
      if (pathname === '/api/operation/execution-flow') {
        assert.ok(persisted); data = { capabilities: { hotel_id: 7, can_execute: true }, summary: {}, stages: [], list: [row(persisted.result_summary)], data_status: 'ok', data_gaps: [], matched_total: 1, returned_count: 1, truncated: false, statistics: { execution_total_loaded: true } };
      } else if (pathname === '/api/operation/action-tracking') data = { actions: [], effect_validation: { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} }, data_status: 'ok', data_gaps: [], matched_total: 0, returned_count: 0, truncated: false };
      else if (pathname === '/api/operation/goal-intervention-overview') data = { hotel_id: 7, data_status: 'no_data' };
      else if (pathname === '/api/operation/closure-overview') data = { summary: {}, modules: [], data_gaps: [], data_status: 'ok' };
      else throw new Error('Unsupported synthetic path: ' + pathname);
      call.response = { code: 200, data: clone(data) }; call.settled = true; call.removeAbort();
      resolve(new Response(JSON.stringify(call.response), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    }),
  };
  vm.createContext(sandbox);
  vm.runInContext(raw.system + '\n' + raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  vm.runInContext(Object.values(parts).join('\n') + '\nglobalThis.exposed = { reviewOperationExecutionTask, closeOperationReviewModal, submitOperationExecutionReview };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.exposed });
  const inspect = async () => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = templates[template].render(state, []); return tree; } });
    app.config.warnHandler = () => {};
    const html = await renderToString(app), nodes = [], entries = [];
    const walk = (n, parents = []) => { if (Array.isArray(n)) return n.forEach(child => walk(child, parents)); if (!n || typeof n !== 'object') return;
      nodes.push(n); entries.push({ node: n, parents }); walk(n.children, [...parents,n]); };
    walk(tree); return { html, nodes, entries };
  };
  const reply = (call, data, status = 200) => {
    assert.ok(call && !call.settled); call.settled = true; call.removeAbort(); call.response = clone(data);
    if (call.method === 'POST' && data.code === 200) persisted = clone(data.data);
    call.resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
  };
  return { state, requests, notices, inspect, reply, inFlight: [], operationApi: sandbox.window.SUXI_OPERATION_STATIC };
}

const nodeText = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(nodeText).join('') : node?.children ? nodeText(node.children) : '';
const effectiveDisabled = (view, node) => !!node.props?.disabled || view.entries.find(entry => entry.node === node).parents.some(parent => parent.props?.inert || parent.type === 'fieldset' && parent.props?.disabled);

async function editDraft(h, status, summary, allowBlocked = false) {
  let view = await h.inspect();
  const select = view.nodes.find(node => node.type === 'select');
  const textarea = view.nodes.find(node => node.type === 'textarea');
  assert.ok(select && textarea);
  if (effectiveDisabled(view, select) || effectiveDisabled(view, textarea)) {
    assert.equal(allowBlocked, true, 'Expected editable original form');
    assert.equal(effectiveDisabled(view, select), true);
    assert.equal(effectiveDisabled(view, textarea), true);
    // A real user cannot edit disabled native controls. Do not dispatch their
    // events or call model updaters; the busy oracle separately requires the lock.
    return { blocked: 2, dispatched: 0 };
  }
  const options = [];
  const walk = node => {
    if (Array.isArray(node)) node.forEach(walk);
    else if (node && typeof node === 'object') {
      if (node.type === 'option') options.push(node);
      walk(node.children);
    }
  };
  walk(select);
  assert.ok(options.some(option => option.props.value === status && !option.props.disabled));
  const listeners = {};
  const element = {
    multiple: false,
    options: options.map(option => ({ selected: option.props.value === status, value: option.props.value, _value: option.props.value })),
    addEventListener: (event, listener) => { listeners[event] = listener; },
  };
  Vue.vModelSelect.created(element, { modifiers: {} }, select);
  listeners.change({ target: element });
  await tick();
  view = await h.inspect();
  const currentTextarea = view.nodes.find(node => node.type === 'textarea');
  assert.ok(currentTextarea && !effectiveDisabled(view, currentTextarea));
  const events = {};
  const input = { type: 'textarea', value: summary, composing: false, addEventListener: (event, listener) => { events[event] = listener; } };
  Vue.vModelText.created(input, { modifiers: { trim: true } }, currentTextarea);
  events.input({ target: input });
  await tick();
  assert.deepEqual(clone(h.state.operationReviewForm), { status, summary });
  return { blocked: 0, dispatched: 2 };
}

async function assertBusy(h, phase) {
  const view = await h.inspect();
  assert.equal(h.state.operationLoading.actions, true, phase);
  const select = view.nodes.find(node => node.type === 'select');
  const textarea = view.nodes.find(node => node.type === 'textarea');
  assert.ok(select && textarea, phase);
  assert.equal(effectiveDisabled(view, select), true, phase + ': original select must be disabled');
  assert.equal(effectiveDisabled(view, textarea), true, phase + ': original textarea must be disabled');
  const body = view.nodes.filter(node => node.type === 'fieldset');
  assert.equal(body.length, 1, phase + ': only the modal body is locked');
  assert.equal(body[0].props.disabled, true);
  assert.equal(view.nodes.some(node => node.type === 'legend'), false, 'No first-legend disabling exception');
  const buttons = view.nodes.filter(node => node.type === 'button');
  assert.equal(buttons.length, 2);
  assert.ok(buttons.every(node => effectiveDisabled(view, node)));
  assert.deepEqual(await editDraft(h, 'failed', B, true), { blocked: 2, dispatched: 0 });
  assert.deepEqual(clone(h.state.operationReviewForm), { status: 'observing', summary: A }, phase + ': pending A is retained');
}

async function assertEditable(h) {
  const view = await h.inspect();
  assert.equal(h.state.operationLoading.actions, false);
  const controls = view.nodes.filter(node => ['select', 'textarea', 'button'].includes(node.type));
  assert.equal(controls.length, 4);
  assert.ok(controls.every(node => !effectiveDisabled(view, node)));
}

async function submitA(h) {
  const view = await h.inspect();
  const save = view.nodes.find(node => node.type === 'button' && nodeText(node).trim() === '保存复盘');
  assert.ok(save && !effectiveDisabled(view, save));
  const pending = save.props.onClick();
  h.inFlight.push(pending);
  await tick();
  const post = h.requests.findLast(call => call.method === 'POST' && !call.settled);
  assert.ok(post);
  assert.deepEqual(post.body, { hotel_id: 7, system_hotel_id: 7, result_status: 'observing', result_summary: A, tenant_id: '70', platform: 'all' });
  return { pending, post };
}

// Legal legacy plaintext task DTO. The exact final82 method checks same hotel,
// task, status and summary; this fixture does not manufacture a summary digest.
const savedTask = () => ({
  id: 11, hotel_id: 7, tenant_id: 70, intent_id: 111, status: 'executed', operator_id: 901,
  result_status: 'observing', result_summary: A, target_value: {},
  evidence: [{ id: 41, task_id: 11, tenant_id: 70, evidence_type: 'manual_operation_execution', created_by: 901,
    platform_response: { completed_action: 'Synthetic already completed content adjustment', executed_at: '2026-09-14 09:00:00', next_review_date: '2026-09-15', effect_status: 'pending_verification', evidence_boundary: 'local_manual_evidence_no_ota_write' } }],
});

for (const template of ['ops', 'online']) {
  test(template + ' review locks the draft through POST and exact GET, then restores editing', async t => {
    const h = harness(template);
    try {
      assert.ok(h.operationApi.operationCanReviewExecution(row('')));
      assert.equal(h.operationApi.operationCanReconcileExecution(row('')), false);
      assert.ok(templates[template].ancestorConditions.some(condition => condition.includes(template === 'ops' ? "currentPage === 'ops-track'" : "currentPage === 'online-data'")));
      await h.state.reviewOperationExecutionTask(row(''));
      await tick();
      assert.equal(h.state.operationReviewModalOpen, true);
      await assertEditable(h);
      await editDraft(h, 'observing', A);

      const failed = await submitA(h);
      await assertBusy(h, 'POST pending before failure');
      h.reply(failed.post, { code: 500, message: 'Synthetic review POST unavailable', data: null }, 500);
      await failed.pending;
      await tick();
      assert.equal(h.state.operationReviewModalOpen, true);
      assert.deepEqual(clone(h.state.operationReviewForm), { status: 'observing', summary: A });
      assert.ok(h.notices.some(notice => notice.type === 'error' && notice.message.includes('Synthetic review POST unavailable')));
      assert.equal(h.requests.length, 1, 'Failure does not retry or read a task');
      await assertEditable(h);
      await editDraft(h, 'failed', B);

      // Explicitly restore A and click save again. No automatic retry or B POST.
      await editDraft(h, 'observing', A);
      const success = await submitA(h);
      await assertBusy(h, 'POST pending before success');
      h.reply(success.post, { code: 200, data: savedTask() });
      await tick();
      const get = h.requests.findLast(call => call.method === 'GET' && /\/execution-tasks\/11\?/.test(call.url) && !call.settled);
      assert.ok(get, 'Original strict task GET is held pending');
      assert.equal(h.state.operationReviewModalOpen, true);
      await assertBusy(h, 'Exact task GET pending');
      h.reply(get, { code: 200, data: savedTask() });
      await success.pending;
      await tick();
      assert.equal(h.state.operationReviewModalOpen, false, JSON.stringify(h.notices));
      assert.equal(h.state.operationLoading.actions, false);
      assert.equal(h.state.operationReviewModalItem, null);
      assert.equal(h.state.operationReviewMutationContext, null);
      assert.deepEqual(clone(h.state.operationReviewForm), { status: 'observing', summary: A });
      assert.equal((await h.inspect()).nodes.some(node => node.type === 'textarea'), false);
      assert.ok(h.notices.some(notice => notice.type === 'success' && notice.message.includes('继续观察')));
      assert.ok(h.state.operationExecutionFlow.list[0], JSON.stringify({ notices: h.notices, error: h.state.operationError.actions }));
      assert.equal(h.state.operationExecutionFlow.list[0].review.summary, A);

      await h.state.reviewOperationExecutionTask(h.state.operationExecutionFlow.list[0]);
      await tick();
      assert.equal(h.state.operationReviewModalOpen, true);
      await assertEditable(h);
      await editDraft(h, 'failed', B);
      assert.equal(h.requests.filter(call => call.method === 'POST').length, 2);
      assert.equal(h.requests.filter(call => call.method === 'GET').length, 5);
      assert.ok(h.requests.every(call => call.settled && !call.aborted));
      t.diagnostic('Closed synthetic requests: 2 POST + 5 GET; no events sent to disabled controls.');
    } finally {
      for (const call of h.requests.filter(call => !call.settled)) h.reply(call, { code: 500, message: 'Synthetic probe cleanup', data: null }, 500);
      await Promise.allSettled(h.inFlight);
    }
  });
}
