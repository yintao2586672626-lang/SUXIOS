import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash, webcrypto } from 'node:crypto';
import test, { after } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

// Original 17 controls, handlers, auth and request coordinator in a memory renderer.
// The response projection models an eligible legacy AI-report task, not verified
// OTA facts or a real database. No output-directory or PHP runtime dependency.
const sourceRoot = process.argv.find(a => a.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(a => a.startsWith('--evidence='))?.slice(11);
const readers = [], sections = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = path => {
  const mapped = sourceRoot && resolve(sourceRoot, path);
  const actual = mapped && existsSync(mapped) ? mapped : resolve(path);
  const text = readFileSync(actual, 'utf8');
  readers.push({ path, resolved_path: actual, sha256: hash(text) });
  return text.replaceAll('\r\n', '\n');
};
const raw = {
  main: read('public/app-main.js'), operation: read('public/operation-static.js'),
  system: read('public/system-static.js'), components: read('public/components/system/app-main-components.js'),
  ops: read('resources/frontend/templates/fragments/17-page-ops-track.html'),
};
function cut(source, start, end) {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  const value = source.slice(a, b); sections.push({ start, end, sha256: hash(value) }); return value;
}
const section = (start, end) => cut(raw.main, start, end);
const statement = text => {
  const matches = raw.main.split('\n').filter(line => line.trim().startsWith(text));
  assert.equal(matches.length, 1, text); sections.push({ start: text, sha256: hash(matches[0]) }); return matches[0];
};
const requestSource = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const askSystemUsageGuide ='),
].join('\n');
const methods = [
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters ='),
  section('            const operationExecutionHotelId =', '            const collectPriceExecutionIntentFields ='),
  section('            const loadOperatingMemories =', '            const saveOperationExecutionMemory ='),
  section('            const loadOperationActions =', '            const parseOperationEvidenceNumber ='),
  section('            const reviewOperationExecutionTask =', '            const finishOperationAction ='),
  section('            const operationExecutionFilteredItems =', '            const operationExecutionStageFilterLabel ='),
  section('            const operationExecutionRowClass =', '            let buildOperationExecutionTraceRows ='),
  statement('const operationExecutionItems ='), statement('const operationIsManagedAction ='),
  statement('const operationExecutionReviewText ='), statement('let operationReviewRequestSeq ='),
  cut(raw.components, '    const normalizeOperationReviewStatus =', '    const RevenueCockpitOpportunityDetails ='),
].join('\n');
// These assignments are the original main loader's relevant static bindings.
const bindings = ['operationCanReviewExecution', 'operationCanReconcileExecution', 'operationExecutionReviewTextForItem', 'operationExecutionSourceText']
  .map(name => statement(`${name} = requireOperationStatic(`)).join('\n');

const attr = (node, key) => node.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
const ancestorPaths = [];
const ast = baseParse(raw.ops, parserOptions);
function retain(node, parents = []) {
  if (node.type !== 1) return null;
  const source = node.loc.source;
  const target = ['operation-review-action', 'operation-review-modal', 'operation-access-pending'].includes(attr(node, 'data-testid'))
    || (node.tag === 'button' && source.includes('@click="loadOperationActions"') && source.endsWith('>刷新</button>'))
    || (node.tag === 'div' && source.startsWith('<div class="whitespace-pre-line">{{ operationExecutionReviewText(item) }}'))
    || (node.tag === 'div' && source.startsWith('<div class="font-medium text-gray-900">{{ operationExecutionSourceText(item) }}'))
    || (node.tag === 'div' && source.startsWith('<div class="text-xs text-gray-400">行动 #'))
    || (node.tag === 'span' && source.startsWith('<span v-else-if="item.execution?.status'));
  if (target) {
    ancestorPaths.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1)));
    return node;
  }
  const children = (node.children || []).map(n => retain(n, parents.concat(node))).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
ast.children = ast.children.map(n => retain(n)).filter(Boolean);
assert.ok(ancestorPaths.some(path => path.some(tag => tag.includes('authContext.permissionStatus'))));
assert.ok(ancestorPaths.some(path => path.some(tag => tag.includes('operationExecutionFilteredItems'))));
const render = new Function('Vue', compile(ast, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const tick = async () => { await Vue.nextTick(); await new Promise(resolveTick => setImmediate(resolveTick)); };
const clone = value => JSON.parse(JSON.stringify(value));
const authMarker = 'synthetic-session-only-round131';
const A = '人工检查已完成；继续等待该酒店携程原日期订单回执，不判断收入效果。';
const B = '继续观察：已核对检查范围，等待同口径来源事实，尚不能归因。';
const C = '另一位复核者刚保存的不同说明。';
const date = '2026-09-14';
const envelope = data => ({ code: 200, message: 'success', data, time: 1789862400 });
const missingMemories = { data_status: 'migration_required', list: [], count: 0, matched_total: 0, returned_count: 0, truncated: false,
  supported_layers: ['fact', 'analysis', 'judgement', 'decision', 'execution_review', 'milestone', 'sop'],
  supported_usage_levels: ['archive_only', 'reference', 'decision_support', 'sop_template'],
  data_gaps: [{ code: 'operating_memory_table_missing', message: '经营记忆表尚未创建，请先执行本地数据库迁移' }], source_policy: 'reference_existing_facts_without_ota_write' };
const missingGoals = { status: 'migration_required', migration_required: true,
  missing_tables: ['hotel_operating_goal_contracts', 'operation_intervention_contracts', 'operation_intervention_assessments'],
  tenant_id: 70, hotel_id: 7, current_goal_contract: null, history: [], interventions: [],
  summary: { supported: 0, contradicted: 0, indeterminate: 0, unassessed: 0 },
  monitor: { status: 'unavailable', monitor_state: 'inactive', reason_code: 'goal_learning_migration_required', last_observed_at: null } };
// Public consumer projection of ExecutionFlowReadService::buildItem. The assumed
// stored task is executed, has a meaningful operator receipt, and its review time
// has arrived. It is a legacy non-managed ai_daily_report intent with no frozen
// effect contract; observing is not a claim of source verification or ROI.
function flow(task) {
  return { capabilities: { hotel_id: 7 }, list: [{ id: 111, hotel_id: 7,
    recommendation: { source: 'ai_daily_report#901', source_module: 'ai_daily_report', source_record_id: 901,
      platform: 'ctrip', object_type: 'operation_checklist', action_type: 'operation_check', date_start: date, date_end: date,
      current_value: [], target_value: [], evidence: [] },
    approval: { status: 'approved', approved_by: 901, approved_at: '2026-09-14 09:00:00', remark: '', blocked_reason: '' },
    execution: { task_id: 11, mode: 'manual', status: 'executed', operator_id: 901, executed_at: '2026-09-14 10:00:00', blocked_reason: '', target_value: [], current_value: [] },
    review: { status: task.result_status, reported_status: task.result_status, summary: task.result_summary,
      truth_status: 'unverified', failure_reason: null, action_track_id: 0, available_at: '2026-09-15 00:00:00', available_on: '2026-09-15', is_available: true },
  }], summary: {}, stages: [], statistics: { execution_total_loaded: true },
  data_status: 'ok', matched_total: 1, returned_count: 1, truncated: false, data_gaps: [] };
}
const originalDocument = globalThis.Document, originalShadowRoot = globalThis.ShadowRoot;
globalThis.Document ??= class MemoryDocument {};
globalThis.ShadowRoot ??= class MemoryShadowRoot {};
after(() => {
  if (originalDocument === undefined) delete globalThis.Document; else globalThis.Document = originalDocument;
  if (originalShadowRoot === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = originalShadowRoot;
  if (evidencePath) writeFileSync(evidencePath, JSON.stringify({ readers, sections, ancestorPaths, attempts,
    boundary: 'Memory renderer and synthetic transport only; no browser, PHP, DB, real credential, OTA write, approval or operating effect.' }, null, 2) + '\n');
});
function memoryHost() {
  const node = (type, text = '') => {
    const n = { type, text, props: {}, children: [], parent: null, style: {}, value: '', selected: false, listeners: {},
      addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); },
      removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] || []).filter(fn => fn !== listener); },
      dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) listener({ type: event.type, target: this }); },
      getRootNode() { let root = this; while (root.parent) root = root.parent; return root; },
    };
    Object.defineProperty(n, 'options', { get: () => n.children.filter(child => child.type === 'option') });
    Object.defineProperty(n, 'selectedIndex', { get: () => n.options.findIndex(option => option.selected), set: value => n.options.forEach((option, index) => { option.selected = index === value; }) });
    return n;
  };
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(n); else parent.children.splice(i, 0, n); },
    remove, patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } if (key === 'multiple') n.multiple = value; },
  } };
}
function harness({ mismatch = false } = {}) {
  const requests = [], notices = [], errors = [], warnings = [], diagnostics = [], checkpoints = [];
  let task = { id: 11, hotel_id: 7, tenant_id: 70, intent_id: 111, status: 'executed', result_status: 'observing', result_summary: '' };
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date,
    TextEncoder, crypto: webcrypto, setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api',
    ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, authSessionEpoch: 1, pageRequestGeneration: 1,
    pageLoadRequests: new Map(), currentPage: Vue.ref('ops-track'), token: Vue.ref(authMarker),
    authContext: Vue.ref({ tenantId: 70, hotelId: 7, platform: 'ctrip', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 901, is_super_admin: true }), filterReportHotel: Vue.ref('7'),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70, name: 'Synthetic hotel 7' }]),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'), operationYesterday: '2026-09-19', shanghaiBusinessYesterday: '2026-09-19',
    operationActionsRequestSeq: 0, operatingMemoryRequestSeq: 0,
    operationFilters: Vue.ref({ hotel_id: '7' }), operationExecutionViewMode: Vue.ref('all'), operationExecutionStageFilter: Vue.ref(''),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }),
    operatingGoalInterventionLoading: Vue.ref(false), operatingGoalInterventionError: Vue.ref(''),
    operationExecutionFlow: Vue.ref({ list: [], data_status: 'not_loaded' }), operationActions: Vue.ref([]),
    operationActionTrackingRead: Vue.ref({ data_status: 'not_loaded' }), operationApprovalConfirmingIntentId: Vue.ref(0),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), operatingGoalInterventionOverview: Vue.ref({}),
    operatingMemoryLoading: Vue.ref(false), operatingMemoryError: Vue.ref(''), operatingMemories: Vue.ref({ list: [], data_status: 'not_loaded' }),
    homeOperatingScheduleError: Vue.ref(''), revenueAiExecutionFocus: Vue.ref({}),
    operationReviewModalOpen: Vue.ref(false), operationReviewModalItem: Vue.ref(null), operationReviewMutationContext: Vue.ref(null), operationReviewForm: Vue.ref({}),
    readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    operationErrorMessage: (error, fallback) => error?.message || fallback,
    // Adjacent home projection is outside this path; the original operation list
    // and its five reads execute, including memory/goal explicit unavailable DTOs.
    applyHomeOperatingScheduleFlow() {},
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    fetch: (url, options) => {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(new Headers(options.headers).get('Authorization'), authMarker);
      assert.equal(Boolean(options.signal), method === 'GET');
      const allowed = method === 'POST' ? endpoint === '/operation/execution-tasks/11/review'
        : method === 'GET' && ['/operation/execution-tasks/11', '/operation/execution-flow', '/operation/action-tracking', '/operation/closure-overview', '/operation/operating-memories', '/operation/goal-intervention-overview'].includes(endpoint);
      assert.ok(allowed, `${method} ${endpoint}`);
      const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), body: options.body ? JSON.parse(options.body) : null, settled: false, aborted: false };
      requests.push(call);
      return new Promise((resolveRequest, rejectRequest) => {
        options.signal?.addEventListener('abort', () => {
          if (call.settled) return;
          call.aborted = true; call.settled = true;
          rejectRequest(new DOMException('Synthetic request aborted by the original signal', 'AbortError'));
        });
        call.reject = () => { call.settled = true; call.failure = 'TypeError: Failed to fetch'; rejectRequest(new TypeError('Failed to fetch')); };
        let data;
        if (method === 'POST') {
          assert.equal(call.body.hotel_id, 7); assert.equal(call.body.system_hotel_id, 7);
          assert.equal(call.body.result_status, 'observing');
          assert.equal('initialReviewStatus' in call.body, false); assert.equal('initialReviewSummary' in call.body, false);
          assert.equal('authSession' in call.body, false);
          task = { ...task, result_status: call.body.result_status, result_summary: call.body.result_summary };
          data = clone(task);
        } else {
          assert.equal(call.query.hotel_id, '7'); assert.equal(call.query.system_hotel_id, '7');
          if (endpoint === '/operation/execution-tasks/11') {
            const summary = mismatch ? C : task.result_summary;
            data = { ...task, result_summary: summary, result_summary_sha256: hash(summary).toLowerCase() };
          } else if (endpoint === '/operation/execution-flow') data = flow(task);
          else if (endpoint === '/operation/operating-memories') data = clone(missingMemories);
          else if (endpoint === '/operation/goal-intervention-overview') data = clone(missingGoals);
          else { call.reject(); return; } // Original catches keep ancillary failures explicit.
        }
        call.response = envelope(data); call.settled = true;
        resolveRequest(new Response(JSON.stringify(call.response), { status: 200, headers: { 'Content-Type': 'application/json' } }));
      });
    },
  };
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  vm.createContext(sandbox); vm.runInContext(raw.system + '\n' + raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  sandbox.operationExecutionStatusLabel = sandbox.appSystemStatic.operationExecutionStatusLabel;
  // Preloaded real static exports; the loader's original four assignments run.
  vm.runInContext(`let operationCanReviewExecution, operationCanReconcileExecution, operationExecutionReviewTextForItem, operationExecutionSourceText;
    const ensureOperationStaticReady = async () => { const staticConfig = await loadOperationStatic(); ${bindings} };
    ${requestSource}\n${methods}
    globalThis.api={loadOperationActions,reviewOperationExecutionTask,submitOperationExecutionReview,closeOperationReviewModal,
      operationExecutionItems,operationExecutionFilteredItems,operationExecutionRowClass,operationExecutionReviewText,
      operationCanReviewExecution:(...args)=>operationCanReviewExecution(...args),
      operationCanReconcileExecution:(...args)=>operationCanReconcileExecution(...args),
      operationExecutionSourceText:(...args)=>operationExecutionSourceText(...args)};
    globalThis.requestState=()=>({ active:coordinatedGetActiveCount,inflight:coordinatedGetRequests.size,queued:coordinatedGetQueue.length });`, sandbox);
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({ setup: () => ({ ...sandbox, ...sandbox.api }), render });
  app.config.warnHandler = message => warnings.push(message);
  app.config.errorHandler = error => errors.push(error?.stack || String(error));
  app.mount(host.root);
  const walk = (n, all = []) => { all.push(n); n.children.forEach(child => walk(child, all)); return all; };
  const text = n => n.text + n.children.map(text).join('');
  const nodes = () => walk(host.root);
  const modal = () => nodes().find(n => n.props['data-testid'] === 'operation-review-modal');
  const available = node => {
    assert.ok(node, 'original control exists');
    let insideModal = false;
    for (let p = node; p; p = p.parent) { assert.ok(!p.props.disabled && p.style.display !== 'none'); if (p === modal()) insideModal = true; }
    assert.ok(!modal() || insideModal, 'never dispatch to controls behind the original modal');
    return node;
  };
  const button = value => nodes().find(n => n.type === 'button' && (n.props['data-testid'] === value || text(n) === value));
  const click = async value => { const node = available(button(value)); const promise = node.props.onClick({ type: 'click', target: node }); await promise; await tick(); };
  const edit = async value => { const textarea = available(nodes().find(n => n.type === 'textarea')); textarea.value = value; textarea.dispatchEvent({ type: 'input' }); await tick(); };
  const snapshot = () => ({ requests: requests.map(({ reject, ...entry }) => entry), notices, errors, warnings, diagnostics,
    text: text(host.root), form: clone(sandbox.operationReviewForm.value), modal_open: sandbox.operationReviewModalOpen.value,
    flow: clone(sandbox.operationExecutionFlow.value), task: clone(task), request_state: sandbox.requestState(), checkpoints });
  return { sandbox, requests, notices, errors, warnings, diagnostics, checkpoints, click, edit, snapshot, text: () => text(host.root),
    async stop() { app.unmount(); for (const call of requests.filter(item => !item.settled)) { call.teardown_only = true; call.reject(); } await tick(); } };
}
function scenario(name, body) {
  test(name, async () => {
    let h; const attempt = { name }; attempts.push(attempt);
    try {
      h = harness({ mismatch: name.includes('different exact') }); await body(h);
      assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
      assert.ok(h.requests.every(call => call.settled && !call.aborted && !call.teardown_only));
      assert.deepEqual(clone(h.snapshot().request_state), { active: 0, inflight: 0, queued: 0 });
      const failed = h.requests.filter(call => call.failure);
      assert.equal(h.diagnostics.length, failed.length);
      assert.ok(h.diagnostics.every(d => d[0] === 'API请求失败:' && d[2] === 'Failed to fetch'
        && failed.some(call => d[1].startsWith(call.endpoint + '?'))));
      attempt.pass = true;
    } catch (error) { attempt.error = error?.stack || String(error); throw error; }
    finally { if (h) { attempt.before_teardown = h.snapshot(); await h.stop(); attempt.after_teardown = h.snapshot(); } }
  });
}
scenario('AI-report saved observing explanation survives original reopen, and explicit B still saves and reads exactly', async h => {
  await h.click('刷新');
  assert.equal(h.requests.length, 5); assert.match(h.text(), /行动 #111 · 任务 #11 · ctrip · 2026-09-14/);
  await h.click('operation-review-action'); await h.edit(A); await h.click('保存复盘');
  assert.equal(h.requests.filter(call => call.method === 'POST').length, 1);
  assert.equal(h.snapshot().task.result_summary, A);
  assert.equal(h.sandbox.operationExecutionFlow.value.list[0].review.summary, A);
  assert.match(h.text(), new RegExp(A));
  assert.ok(h.notices.some(notice => notice.type === 'success'));
  await h.click('operation-review-action');
  const formAtReopen = clone(h.sandbox.operationReviewForm.value);
  const before = h.requests.length;
  h.checkpoints.push({ name: 'original_reopen_after_A_saved', form: formAtReopen, requests: before });
  await h.click('保存复盘');
  h.checkpoints.push({ name: 'unchanged_save', task: h.snapshot().task, requests: h.requests.length });
  assert.equal(h.requests.length, before, 'unchanged reopen must not POST a default or claim a fresh GET');
  assert.equal(formAtReopen.summary, A); assert.equal(formAtReopen.status, 'observing');
  assert.equal(h.snapshot().task.result_summary, A); assert.equal(h.sandbox.operationReviewModalOpen.value, false);
  assert.deepEqual(h.notices.at(-1), { message: '复盘内容未修改', type: 'info' });
  await h.click('operation-review-action'); await h.edit(B); await h.click('保存复盘');
  const posts = h.requests.filter(call => call.method === 'POST'), exact = h.requests.filter(call => call.endpoint === '/operation/execution-tasks/11');
  assert.deepEqual(posts.map(call => call.body.result_summary), [A, B]);
  assert.deepEqual(exact.map(call => call.response.data.result_summary), [A, B]);
  assert.equal(h.snapshot().task.result_summary, B); assert.equal(h.sandbox.operationExecutionFlow.value.list[0].review.summary, B);
  assert.equal(h.sandbox.operationReviewModalOpen.value, false); assert.match(h.text(), new RegExp(B));
  assert.equal(h.requests.filter(call => call.method === 'GET').length, 17);
  assert.equal(h.sandbox.operationFilters.value.hotel_id, '7');
  assert.equal(h.sandbox.revenueAiBusinessDate.value, '2026-09-20');
  assert.equal(h.sandbox.operationExecutionFlow.value.list[0].recommendation.date_start, date);
});
scenario('AI-report explicit edit with a different exact explanation keeps the visible draft and refuses success', async h => {
  await h.click('刷新'); await h.click('operation-review-action'); await h.edit(B); await h.click('保存复盘');
  assert.equal(h.requests.filter(call => call.method === 'POST').length, 1);
  assert.equal(h.requests.filter(call => call.method === 'GET').length, 6);
  assert.equal(h.sandbox.operationReviewModalOpen.value, true); assert.equal(h.sandbox.operationReviewForm.value.summary, B);
  assert.equal(h.sandbox.operationLoading.value.actions, false);
  assert.equal(h.notices.some(notice => notice.type === 'success'), false);
  assert.ok(h.notices.some(notice => notice.type === 'error' && /复盘说明回读不一致/.test(notice.message)));
  assert.equal(h.sandbox.operationExecutionFlow.value.list[0].review.summary, '', 'failed exact read does not present the draft as saved list data');
});
