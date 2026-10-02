import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Closed synthetic transport only. No DB, browser, real fetch, credentials, or source writes.
const files = {
  main: 'public/app-main.js', operation: 'public/operation-static.js', system: 'public/system-static.js',
  components: 'public/components/system/app-main-components.js',
  ops: 'resources/frontend/templates/fragments/17-page-ops-track.html',
  online: 'resources/frontend/templates/fragments/35-page-online-data.html',
};
const raw = Object.fromEntries(Object.entries(files).map(([k, p]) => [k, fs.readFileSync(p, 'utf8')]));
const cut = (source, start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  return source.slice(a, b);
};
const main = raw.main.replaceAll('\r\n', '\n');
const parts = {
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
  reviewSequence: main.match(/let operationReviewRequestSeq = 0;/)?.[0] || '',
};
const templates = {};
for (const [key, id] of [['ops', 'operation-review-modal'], ['online', 'core-loop-operation-review-modal']]) {
  const nodes = [], walk = n => { nodes.push(n); (n.children || []).forEach(walk); };
  walk(parse(raw[key]));
  const node = nodes.find(n => n.type === 1 && n.props.some(p => p.name === 'data-testid' && p.value?.content === id));
  assert.ok(node, id);
  templates[key] = { text: node.loc.source,
    render: new Function('Vue', compile(node.loc.source, { mode: 'function', prefixIdentifiers: true }).code)(Vue) };
}
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const A = 'Synthetic review A: wait for the matching follow-up facts.';
const B = 'Synthetic review B: another reviewer is waiting for a different receipt.';
const DEFAULT_SUMMARY = '继续观察，等待次日收益或ROI证据';
const JSON_SUMMARY = '{ "note": "继续观察" }';
const LARGE_SUMMARY = '{ "receipt": 9007199254740993 }';
const OTHER_LARGE_SUMMARY = '{ "receipt": 9007199254740992 }';
const COLLISION_DISPLAY = '{"receipt":1.0e+20}';
const COLLISION_RAW = '{ "receipt": 100000000000000000001 }';
// These displays match the actual PHP normalizer's pure fixtures; never parse the human's numeric text in JS.
const summaryDisplay = raw => ({
  [JSON_SUMMARY]: '{"note":"继续观察"}',
  [LARGE_SUMMARY]: '{"receipt":9007199254740993}',
  [OTHER_LARGE_SUMMARY]: '{"receipt":9007199254740992}',
})[raw] ?? raw;
const summaryDigest = raw => createHash('sha256').update(raw, 'utf8').digest('hex');
const row = (summary, taskId = 11, hotelId = 7) => ({ id: taskId + 100, hotel_id: hotelId,
  recommendation: { source_module: 'manual', object_type: 'content', expected_metric: 'conversion_rate' },
  execution: { task_id: taskId, hotel_id: hotelId, status: 'executed' },
  review: { status: 'observing', summary }, next_action: { key: 'review', label: '复盘' } });

function harness(template, mode, inputSummary) {
  const requests = [], notices = [];
  const digests = [], taskRecords = new Map();
  let persisted = null;
  const pausedCrypto = { subtle: { digest: (...args) => new Promise((resolve, reject) => {
    const gate = { released: false, release: async () => {
      if (gate.released) return;
      gate.released = true;
      try { resolve(await webcrypto.subtle.digest(...args)); } catch (error) { reject(error); }
    } };
    digests.push(gate);
  }) } };
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout,
    TextEncoder,
    crypto: mode === 'no-crypto' ? undefined : mode === 'crypto-error'
      ? { subtle: { digest: async () => { throw new Error('Synthetic digest unavailable'); } } }
      : mode === 'paused-digest' ? pausedCrypto : webcrypto,
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
    operationExecutionViewMode: Vue.ref('all'), operationExecutionFlow: Vue.ref({ list: [row('')], data_status: 'ok' }),
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
      requests.push(call);
      const pathname = new URL(url).pathname;
      let data;
      if (call.method === 'POST') {
        const match = pathname.match(/^\/api\/operation\/execution-tasks\/(11|12)\/review$/);
        assert.ok(match, pathname);
        const taskId = Number(match[1]);
        assert.equal(call.body.result_status, 'observing');
        assert.equal(call.body.result_summary, inputSummary || DEFAULT_SUMMARY);
        assert.ok([7, 8].includes(call.body.hotel_id)); assert.equal(call.body.system_hotel_id, call.body.hotel_id);
        persisted = { id: taskId, hotel_id: call.body.hotel_id, tenant_id: call.body.hotel_id * 10,
          intent_id: taskId + 100, status: 'executed', operator_id: 901,
          result_status: 'observing', result_summary: summaryDisplay(call.body.result_summary), target_value: {}, evidence: [{
            id: taskId + 30, task_id: taskId, tenant_id: call.body.hotel_id * 10, evidence_type: 'manual_operation_execution', created_by: 901,
            platform_response: { completed_action: 'Synthetic already completed content adjustment',
              executed_at: '2026-09-14 09:00:00', next_review_date: '2026-09-15',
              effect_status: 'pending_verification', evidence_boundary: 'local_manual_evidence_no_ota_write' },
          }] };
        if (!['matching-summary', 'default-summary'].includes(mode)) {
          persisted.result_summary_sha256 = summaryDigest(call.body.result_summary);
        }
        taskRecords.set(taskId, persisted);
        data = clone(persisted); // POST read back this text before a later writer can save another summary.
      } else if (/^\/api\/operation\/execution-tasks\/(11|12)$/.test(pathname)) {
        persisted = taskRecords.get(Number(pathname.split('/').at(-1)));
        assert.ok(persisted);
        assert.equal(new URL(url).searchParams.get('hotel_id'), String(persisted.hotel_id));
        assert.equal(new URL(url).searchParams.get('system_hotel_id'), String(persisted.hotel_id));
        if (mode === 'different-summary') {
          persisted.result_summary = B;
          persisted.result_summary_sha256 = summaryDigest(B);
        }
        if (mode === 'changed-large-integer') {
          persisted.result_summary = summaryDisplay(OTHER_LARGE_SUMMARY);
          persisted.result_summary_sha256 = summaryDigest(OTHER_LARGE_SUMMARY);
        }
        if (mode === 'missing-summary') delete persisted.result_summary;
        if (mode === 'missing-digest') delete persisted.result_summary_sha256;
        if (['invalid-digest', 'invalid-digest-equal-display'].includes(mode)) persisted.result_summary_sha256 = 'g'.repeat(64);
        if (mode === 'same-display-different-raw') {
          persisted.result_summary = COLLISION_DISPLAY;
          persisted.result_summary_sha256 = summaryDigest(COLLISION_RAW);
        }
        data = clone(persisted);
      } else if (pathname === '/api/operation/execution-flow') {
        const hotelId = Number(new URL(url).searchParams.get('hotel_id'));
        const list = [...taskRecords.values()].filter(t => t.hotel_id === hotelId)
          .map(t => row(t.result_summary, t.id, t.hotel_id));
        data = { capabilities: { hotel_id: hotelId }, summary: {}, stages: [], list,
          data_status: 'ok', data_gaps: [], matched_total: list.length, returned_count: list.length,
          truncated: false, statistics: { execution_total_loaded: true } };
      } else if (pathname === '/api/operation/action-tracking') data = { actions: [], effect_validation: { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} }, data_status: 'ok', data_gaps: [], matched_total: 0, returned_count: 0, truncated: false };
      else if (pathname === '/api/operation/goal-intervention-overview') data = { hotel_id: Number(new URL(url).searchParams.get('hotel_id')), data_status: 'no_data' };
      else if (pathname === '/api/operation/closure-overview') data = { summary: {}, modules: [], data_gaps: [], data_status: 'ok' };
      else throw new Error('Unsupported synthetic path: ' + pathname);
      call.response = { code: 200, data: clone(data) };
      return new Response(JSON.stringify(call.response), { status: 200, headers: { 'Content-Type': 'application/json' } });
    },
  };
  vm.createContext(sandbox);
  vm.runInContext(raw.system + '\n' + raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  vm.runInContext(Object.values(parts).join('\n') + '\nglobalThis.exposed = { reviewOperationExecutionTask, closeOperationReviewModal, submitOperationExecutionReview, loadOperationActions };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.exposed });
  const inspect = async () => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = templates[template].render(state, []); return tree; } });
    app.config.warnHandler = () => {};
    const html = await renderToString(app), nodes = [];
    const walk = n => { if (Array.isArray(n)) return n.forEach(walk); if (!n || typeof n !== 'object') return;
      nodes.push(n); walk(n.children); };
    walk(tree); return { html, nodes };
  };
  return { state, requests, notices, inspect, digests };
}

// These are public-handler/state boundaries, not claims that a browser can click through a modal overlay.
for (const change of ['hotel-and-task', 'same-hotel-new-task', 'new-review-in-flight']) {
  test(`ops: delayed summary digest cannot finish another context (${change})`, async () => {
    const h = harness('ops', 'paused-digest', JSON_SUMMARY), pending = [];
    const beginReview = async item => {
      await h.state.reviewOperationExecutionTask(item); await tick();
      let view = await h.inspect();
      view.nodes.find(n => n.type === 'textarea').props['onUpdate:modelValue'](JSON_SUMMARY);
      await tick(); view = await h.inspect();
      const button = view.nodes.find(n => n.type === 'button' && String(n.children).includes('保存复盘'));
      assert.ok(button && !button.props.disabled, 'The original compiled save control is enabled');
      const result = button.props.onClick(); pending.push(result); await tick();
      return { result };
    };
    try {
      const first = await beginReview(row(''));
      assert.equal(h.digests.length, 1);
      const hotelId = change === 'same-hotel-new-task' ? 7 : 8;
      h.state.operationFilters.hotel_id = String(hotelId);
      if (change !== 'same-hotel-new-task') await h.state.loadOperationActions();
      const nextItem = row('', 12, hotelId);
      let second;
      if (change === 'new-review-in-flight') second = await beginReview(nextItem);
      else { await h.state.reviewOperationExecutionTask(nextItem); await tick(); }
      const nextContext = h.state.operationReviewMutationContext;
      await h.digests[0].release(); await first.result; await tick();
      assert.equal(h.state.operationReviewModalOpen, true, 'The stale digest cannot close the replacement dialog');
      assert.equal(h.state.operationReviewModalItem.execution.task_id, 12);
      assert.equal(h.state.operationReviewMutationContext, nextContext);
      assert.equal(h.notices.some(n => n.type === 'success'), false);
      assert.ok(h.notices.some(n => n.type === 'error' && /复盘回读范围.*变化/.test(n.message)));
      if (second) {
        assert.equal(h.state.operationLoading.actions, true, 'The old callback cannot clear the new review request busy state');
        assert.equal(h.digests.length, 2);
        await h.digests[1].release(); await second.result; await tick();
        assert.equal(h.state.operationReviewModalOpen, false);
        assert.equal(h.state.operationLoading.actions, false);
        assert.equal(h.notices.filter(n => n.type === 'success').length, 1);
      } else {
        assert.equal(h.state.operationLoading.actions, false, 'The old request releases only its own busy state');
      }
    } finally {
      for (const gate of h.digests) await gate.release();
      await Promise.allSettled(pending);
    }
  });
}

for (const template of ['ops', 'online']) {
  const modes = ['matching-summary', 'default-summary', 'different-summary', 'missing-summary',
    'json-spacing', 'json-large-integer', 'changed-large-integer'];
  if (template === 'ops') modes.push('missing-digest', 'invalid-digest', 'no-crypto', 'crypto-error',
    'same-display-different-raw', 'invalid-digest-equal-display', 'valid-digest-equal-display');
  for (const mode of modes) {
    test(`${template}: review ${mode} requires exact submitted summary readback`, async () => {
    const inputSummary = mode === 'default-summary' ? ''
      : mode === 'same-display-different-raw' ? COLLISION_DISPLAY
      : ['json-large-integer', 'changed-large-integer'].includes(mode) ? LARGE_SUMMARY
      : ['json-spacing', 'missing-digest', 'invalid-digest', 'no-crypto', 'crypto-error'].includes(mode) ? JSON_SUMMARY : A;
    const h = harness(template, mode, inputSummary);
    await h.state.reviewOperationExecutionTask(row('')); await tick();
    let view = await h.inspect();
    const input = view.nodes.find(n => n.type === 'textarea');
    assert.ok(input?.props['onUpdate:modelValue']);
    input.props['onUpdate:modelValue'](inputSummary); await tick();
    view = await h.inspect();
    const save = view.nodes.find(n => n.type === 'button' && String(n.children).includes('保存复盘'));
    assert.ok(save && !save.props.disabled);
    await save.props.onClick(); await tick();
    const post = h.requests.find(r => r.method === 'POST');
    const get = h.requests.find(r => r.method === 'GET' && /\/execution-tasks\/11\?/.test(r.url));
    assert.ok(post && get, JSON.stringify(h.notices));
    const accepted = !h.state.operationReviewModalOpen && h.notices.some(n => n.type === 'success');
    const finalView = await h.inspect();
    if (!['matching-summary', 'default-summary', 'json-spacing', 'json-large-integer', 'valid-digest-equal-display'].includes(mode)) {
      assert.equal(accepted, false, 'Another or missing summary must not acknowledge this submitted review');
      assert.equal(h.state.operationReviewModalOpen, true, 'A content mismatch preserves the review dialog');
      assert.equal(h.state.operationReviewForm.summary, inputSummary, 'A content mismatch preserves the submitted draft');
      assert.equal(h.state.operationReviewForm.status, 'observing');
      assert.equal(h.state.operationReviewModalItem.execution.task_id, 11);
      assert.equal(h.state.operationReviewMutationContext.taskId, 11);
      assert.match(finalView.html, /role="dialog"/, 'The original template still renders the review dialog');
      assert.ok(h.notices.some(n => n.type === 'error' && /复盘说明.*回读.*不一致/.test(n.message)));
      assert.equal(h.notices.some(n => n.type === 'success'), false);
      assert.equal(h.requests.some(r => /\/execution-flow(?:\?|$)/.test(r.url)), false,
        'A rejected content receipt must not enter the successful list-refresh branch');
    } else {
      assert.equal(accepted, true, JSON.stringify(h.notices));
      assert.equal(get.response.data.result_status, 'observing');
      assert.equal(get.response.data.result_summary, summaryDisplay(inputSummary || DEFAULT_SUMMARY));
      assert.equal(h.state.operationExecutionFlow.list[0].review.summary, get.response.data.result_summary);
      assert.equal(h.state.operationReviewModalItem, null);
      assert.equal(h.state.operationReviewMutationContext, null);
      assert.doesNotMatch(finalView.html, /role="dialog"/);
      assert.equal(h.notices.some(n => n.type === 'error'), false);
    }
    assert.equal(h.state.operationLoading.actions, false);
    assert.equal(h.requests.filter(r => r.method === 'POST').length, 1);
    assert.equal(h.requests.filter(r => /\/execution-tasks\/11\?/.test(r.url)).length, 1);
    });
  }
}
