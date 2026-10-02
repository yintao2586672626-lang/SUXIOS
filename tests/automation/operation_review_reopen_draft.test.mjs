import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Canonical source only. Closed synthetic transport; no DB, browser or real fetch.
const paths = {
  main: 'public/app-main.js', operation: 'public/operation-static.js', system: 'public/system-static.js',
  components: 'public/components/system/app-main-components.js',
  ops: 'resources/frontend/templates/fragments/17-page-ops-track.html',
  online: 'resources/frontend/templates/fragments/35-page-online-data.html',
};
const raw = Object.fromEntries(Object.entries(paths).map(([key, path]) => [key, fs.readFileSync(path, 'utf8').replaceAll('\r\n', '\n')]));
const cut = (source, start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  return source.slice(a, b);
};
const parts = [
  cut(raw.main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(raw.main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  cut(raw.main, '            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  cut(raw.main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(raw.main, '            const request = async (', '            const askSystemUsageGuide ='),
  cut(raw.main, '            const operationExecutionHotelId =', '            const collectPriceExecutionIntentFields ='),
  cut(raw.components, '    const normalizeOperationReviewStatus =', '    const RevenueCockpitOpportunityDetails ='),
  cut(raw.main, '            const loadOperationActions = async', '            const parseOperationEvidenceNumber ='),
  cut(raw.main, '            const reviewOperationExecutionTask = async', '            const finishOperationAction ='),
  raw.main.match(/const operationIsManagedAction = [^\n]+/)[0],
  raw.main.match(/let operationReviewRequestSeq = 0;/)[0],
];
const collect = root => {
  const nodes = [];
  const walk = node => { if (Array.isArray(node)) return node.forEach(walk);
    if (!node || typeof node !== 'object') return; nodes.push(node); walk(node.children); };
  walk(root); return nodes;
};
const renders = Object.fromEntries(['ops', 'online'].map(key => {
  const id = key === 'ops' ? 'operation-review-modal' : 'core-loop-operation-review-modal';
  const node = collect(parse(raw[key])).find(n => n.type === 1 && n.props.some(p => p.name === 'data-testid' && p.value?.content === id));
  assert.ok(node, id);
  return [key, new Function('Vue', compile(node.loc.source, { mode: 'function', prefixIdentifiers: true }).code)(Vue)];
}));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const clone = value => JSON.parse(JSON.stringify(value));
const A = '等待同酒店同口径的次日订单回执，人工执行已完成。';
const B = '已收到次日回执，等待同口径转化指标核对。';
const DEFAULT_SUMMARY = '继续观察，等待次日收益或ROI证据';
const row = (summary = A, taskId = 11) => ({ id: taskId + 100, hotel_id: 7,
  recommendation: { source_module: 'manual', platform: 'ctrip', date_start: '2026-09-14', date_end: '2026-09-14' },
  execution: { task_id: taskId, hotel_id: 7, status: 'executed' },
  review: { status: 'observing', reported_status: 'observing', summary, is_available: true } });

function harness(template = 'ops', differentReadback = false) {
  const requests = [], notices = []; let saved;
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout, TextEncoder, crypto: webcrypto,
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
    operationExecutionViewMode: Vue.ref('all'), operationExecutionFlow: Vue.ref({ list: [], data_status: 'ok' }),
    operationActions: Vue.ref([]), operationActionTrackingRead: Vue.ref({}), operationApprovalConfirmingIntentId: Vue.ref(0),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}),
    operatingGoalInterventionOverview: Vue.ref({}), homeOperatingScheduleError: Vue.ref(''),
    operationReviewModalOpen: Vue.ref(false), operationReviewModalItem: Vue.ref(null),
    operationReviewMutationContext: Vue.ref(null), operationReviewForm: Vue.ref({}),
    showToast: (message, type = 'success') => notices.push({ message, type }),
    operationErrorMessage: (error, fallback) => error.message || fallback,
    ensureOperationStaticReady: async () => {}, normalizeOperationHotelSelection: form => form.value.hotel_id,
    loadOperatingMemories: async () => {}, applyHomeOperatingScheduleFlow() {},
    fetch: async (url, options) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/operation/'));
      const call = { url, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null };
      requests.push(call); const parsed = new URL(url); let data;
      if (call.method === 'POST') {
        assert.equal(parsed.pathname, '/api/operation/execution-tasks/11/review');
        assert.equal(call.body.hotel_id, 7); assert.equal(call.body.system_hotel_id, 7);
        assert.equal('initialReviewStatus' in call.body, false);
        assert.equal('initialReviewSummary' in call.body, false);
        saved = { id: 11, hotel_id: 7, tenant_id: 70, status: 'executed',
          result_status: call.body.result_status, result_summary: call.body.result_summary };
        data = clone(saved);
      } else if (parsed.pathname === '/api/operation/execution-tasks/11') {
        assert.equal(parsed.searchParams.get('hotel_id'), '7');
        assert.equal(parsed.searchParams.get('system_hotel_id'), '7');
        data = { ...saved, result_summary: differentReadback ? '另一份不同复盘' : saved.result_summary };
        data.result_summary_sha256 = createHash('sha256').update(data.result_summary, 'utf8').digest('hex');
      } else if (parsed.pathname === '/api/operation/execution-flow') {
        assert.equal(parsed.searchParams.get('hotel_id'), '7');
        const item = row(saved.result_summary); item.review.status = saved.result_status;
        data = { capabilities: { hotel_id: 7 }, summary: {}, stages: [], list: [item], data_status: 'ok', data_gaps: [], matched_total: 1, returned_count: 1, truncated: false, statistics: { execution_total_loaded: true } };
      } else if (parsed.pathname === '/api/operation/action-tracking') data = { actions: [], effect_validation: { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} }, data_status: 'ok', data_gaps: [], matched_total: 0, returned_count: 0, truncated: false };
      else if (parsed.pathname === '/api/operation/goal-intervention-overview') data = { hotel_id: 7, data_status: 'no_data' };
      else if (parsed.pathname === '/api/operation/closure-overview') data = { summary: {}, modules: [], data_gaps: [], data_status: 'ok' };
      else throw new Error('Unsupported synthetic endpoint: ' + parsed.pathname);
      call.response = clone(data);
      return new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    },
  };
  vm.createContext(sandbox); vm.runInContext(raw.system + '\n' + raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  vm.runInContext(parts.join('\n') + '\nglobalThis.exposed = { reviewOperationExecutionTask, closeOperationReviewModal, submitOperationExecutionReview };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.exposed });
  const inspect = async () => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = renders[template](state, []); return tree; } });
    app.config.warnHandler = () => {};
    const html = await renderToString(app); return { html, nodes: collect(tree) };
  };
  const open = async item => { await state.reviewOperationExecutionTask(item); await tick(); return inspect(); };
  const save = async () => {
    const view = await inspect();
    const button = view.nodes.find(n => n.type === 'button' && String(n.children).includes('保存复盘'));
    assert.ok(button && !button.props.disabled);
    assert.equal(view.nodes.some(n => n.type === 'fieldset' && n.props?.disabled), false);
    await button.props.onClick(); await tick();
  };
  const edit = async (summary, status) => {
    const view = await inspect();
    if (summary !== undefined) view.nodes.find(n => n.type === 'textarea').props['onUpdate:modelValue'](summary);
    if (status !== undefined) view.nodes.find(n => n.type === 'select').props['onUpdate:modelValue'](status);
    await tick();
  };
  return { state, requests, notices, open, edit, save, inspect };
}

const assertUnchanged = h => {
  assert.equal(h.requests.length, 0, 'Unedited existing text must cause neither POST nor a claimed fresh GET');
  assert.equal(h.state.operationReviewModalOpen, false);
  assert.equal(h.state.operationReviewModalItem, null);
  assert.equal(h.state.operationReviewMutationContext, null);
  assert.equal(h.state.operationLoading.actions, false);
  assert.deepEqual(h.notices, [{ message: '复盘内容未修改', type: 'info' }]);
};
for (const template of ['ops', 'online']) {
  test(`${template}: existing observing text reopens and unchanged save only closes`, async () => {
    const h = harness(template), item = row(); const view = await h.open(item);
    assert.equal(h.state.operationReviewForm.summary, A);
    assert.ok(view.html.includes(A));
    assert.equal(h.state.operationReviewForm.status, 'observing');
    assert.ok(Object.isFrozen(h.state.operationReviewMutationContext));
    // A later in-place list refresh must not replace the immutable editing baseline.
    item.review.summary = B;
    await h.save(); assertUnchanged(h);
  });
}
test('normalized JSON and large-number displays are never written back when unedited', async () => {
  for (const display of ['{"note":"继续观察"}', '{"receipt":1.0e+20}']) {
    const h = harness(); await h.open(row(display));
    assert.equal(h.state.operationReviewForm.summary, display);
    await h.save(); assertUnchanged(h);
  }
});
test('explicit summary change still POSTs and verifies exact hotel task status and digest', async () => {
  const h = harness(); await h.open(row()); await h.edit(B); await h.save();
  assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
  assert.equal(h.requests.find(r => r.method === 'POST').body.result_summary, B);
  assert.equal(h.requests.find(r => new URL(r.url).pathname === '/api/operation/execution-tasks/11').response.result_summary, B);
  assert.equal(h.state.operationExecutionFlow.list[0].review.summary, B);
  assert.equal(h.state.operationReviewModalOpen, false);
  assert.ok(h.notices.some(n => n.type === 'success'));
});
test('explicit status-only change still uses the original save path', async () => {
  const h = harness(); await h.open(row()); await h.edit(undefined, 'failed'); await h.save();
  const post = h.requests.find(r => r.method === 'POST');
  assert.equal(post.body.result_status, 'failed'); assert.equal(post.body.result_summary, A);
  assert.equal(h.state.operationReviewModalOpen, false);
  assert.ok(h.notices.some(n => n.type === 'success'));
});
test('first empty summary keeps the existing default save behavior', async () => {
  const h = harness(); await h.open(row('')); await h.save();
  assert.equal(h.requests.find(r => r.method === 'POST').body.result_summary, DEFAULT_SUMMARY);
  assert.equal(h.state.operationExecutionFlow.list[0].review.summary, DEFAULT_SUMMARY);
  assert.ok(h.notices.some(n => n.type === 'success'));
});
test('edited summary with different exact GET content retains the draft and does not acknowledge success', async () => {
  const h = harness('ops', true); await h.open(row()); await h.edit(B); await h.save();
  assert.equal(h.state.operationReviewModalOpen, true);
  assert.equal(h.state.operationReviewForm.summary, B);
  assert.equal(h.notices.some(n => n.type === 'success'), false);
  assert.ok(h.notices.some(n => n.type === 'error' && /复盘说明回读不一致/.test(n.message)));
});
for (const change of ['hotel', 'task']) {
  test(`current ${change} identity is checked before unchanged close`, async () => {
    const h = harness(); await h.open(row());
    if (change === 'hotel') h.state.operationFilters.hotel_id = '8';
    else h.state.operationReviewModalItem = row(A, 12); // Public state guard, not a browser concurrency claim.
    const currentItem = h.state.operationReviewModalItem;
    await h.save();
    assert.equal(h.requests.length, 0);
    assert.equal(h.state.operationReviewModalOpen, true);
    assert.equal(h.state.operationReviewModalItem, currentItem);
    assert.equal(h.notices.some(n => n.type === 'info' || n.type === 'success'), false);
    assert.ok(h.notices.some(n => n.type === 'error' && /身份|酒店/.test(n.message)));
  });
}
test('managed, excluded-source and terminal contexts keep their original opening behavior', async () => {
  const managed = row(); managed.action_management = { contract_version: 'operation_action_card.v2', action_card: { content_digest: 'a'.repeat(64) } };
  const sourceBacked = row(); sourceBacked.recommendation.source_module = 'revenue_cockpit_action';
  const terminal = row(); terminal.review.status = 'failed'; terminal.review.reported_status = 'failed';
  for (const item of [managed, sourceBacked, terminal]) {
    const h = harness(); await h.open(item);
    assert.equal(h.state.operationReviewForm.summary, '');
    assert.equal(h.state.operationReviewMutationContext.initialReviewSummary, undefined);
    assert.equal(h.requests.length, 0);
  }
});
