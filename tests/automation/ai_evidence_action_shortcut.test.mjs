import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash, webcrypto } from 'node:crypto';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

// Canonical source by default; an explicit source root is only a red/green mapper.
const sourceRoot = process.argv.find(a => a.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(a => a.startsWith('--evidence='))?.slice(11);
const reads = [];
const read = path => {
  const mapped = sourceRoot && resolve(sourceRoot, path);
  const actual = mapped && existsSync(mapped) ? mapped : resolve(path);
  const code = readFileSync(actual, 'utf8');
  reads.push({ path, resolved_path: actual, sha256: createHash('sha256').update(code).digest('hex').toUpperCase() });
  return code;
};
const raw = {
  main: read('public/app-main.js'), system: read('public/system-static.js'), operation: read('public/operation-static.js'),
  delivery: read('public/components/system/ai-daily-report-delivery.js'),
  aiTemplate: read('resources/frontend/templates/fragments/16-page-ai-daily-report.html'),
  opsTemplate: read('resources/frontend/templates/fragments/17-page-ops-track.html'),
};
const main = raw.main.replaceAll('\r\n', '\n');
const sections = [];
function section(start, end) {
  const a = main.indexOf(start), b = main.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  const code = main.slice(a, b);
  sections.push({ start, end, sha256: createHash('sha256').update(code).digest('hex') });
  return code;
}
const requestSource = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'),
  'const apiRequest = request;',
].join('\n');
const methods = [
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters ='),
  section('            const loadOperatingMemories =', '            const saveOperationExecutionMemory ='),
  section('            const loadOperationActions =', '            const parseOperationEvidenceNumber ='),
  section('            const openHomeOperatingScheduleItem =', '            const homeDailyWorkflowError ='),
  section('            const operationExecutionFilteredItems =', '            const operationExecutionStageFilterLabel ='),
].join('\n');
const pageChange = section('                const previousPage = previousPageLifecycleKey;', '                if (!isCompassDataPage(newPage))');
const opsActivation = section("                if (newPage === 'ops-track') {", "                if (newPage === 'operating-growth-archive') {");

// Preserve every target ancestor and its original directives. The AI evidence
// subtree is whole, including the !ready / template-v-else pair. In the task page
// only identity, approval, refresh and error nodes are retained; other panels are
// outside this focused renderer, not asserted to have mounted or loaded facts.
const templatePaths = [];
function retainedRender(source, target, name) {
  const ast = baseParse(source, parserOptions), retained = [];
  const visit = (node, parents) => {
    if (node.type !== 1) return null;
    if (target(node)) { retained.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
    const children = (node.children || []).map(n => visit(n, parents.concat(node))).filter(Boolean);
    if (!children.length) return null;
    return { ...node, children };
  };
  ast.children = ast.children.map(n => visit(n, [])).filter(Boolean);
  assert.ok(retained.length, name);
  templatePaths.push({ name, ancestors: retained });
  return new Function('Vue', compile(ast, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
}
const attr = (n, key) => n.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
const aiRender = retainedRender(raw.aiTemplate, n => attr(n, 'data-testid') === 'ai-evidence-diagnosis', 'AI original evidence section');
const opsRender = retainedRender(raw.opsTemplate, n =>
  (n.tag === 'div' && n.loc.source.startsWith('<div class="text-xs text-gray-400">行动 #'))
  || (n.tag === 'span' && n.loc.source.includes('operationExecutionStatusLabel(item.approval?.status)'))
  || (n.tag === 'button' && n.loc.source.includes('@click="loadOperationActions"') && n.loc.source.includes('>刷新</button>'))
  || (n.tag === 'div' && n.loc.source.startsWith('<div v-if="operationError.actions"')),
  'Original task identity, approval, refresh and error');

const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 30 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const authMarker = 'synthetic-session-only-round121';
const missingMemories = { data_status: 'migration_required', list: [], count: 0, matched_total: 0, returned_count: 0, truncated: false,
  supported_layers: ['fact', 'analysis', 'judgement', 'decision', 'execution_review', 'milestone', 'sop'],
  supported_usage_levels: ['archive_only', 'reference', 'decision_support', 'sop_template'],
  data_gaps: [{ code: 'operating_memory_table_missing', message: '经营记忆表尚未创建，请先执行本地数据库迁移' }], source_policy: 'reference_existing_facts_without_ota_write' };
const missingGoals = { status: 'migration_required', migration_required: true,
  missing_tables: ['hotel_operating_goal_contracts', 'operation_intervention_contracts', 'operation_intervention_assessments'],
  tenant_id: 7, hotel_id: 80, current_goal_contract: null, history: [], interventions: [],
  summary: { supported: 0, contradicted: 0, indeterminate: 0, unassessed: 0 },
  monitor: { status: 'unavailable', monitor_state: 'inactive', reason_code: 'goal_learning_migration_required', last_observed_at: null } };
function reportFixture() {
  const text = '合成渠道证据诊断：请人工核对携程转化问题；尚不支持经营效果结论。';
  const scope = { tenant_id: 7, hotel_id: 80, platform: 'ctrip', date_start: '2026-09-15', date_end: '2026-09-15', object_ref: 'ota_channel:ctrip' };
  const suggestion = { recommendation_id: 'synthetic-round121-recommendation', scope, platform: 'ctrip',
    source_digest: 'c'.repeat(64), evidence_snapshot: { fingerprint: 'c'.repeat(64), scope: clone(scope), source_refs: ['online_daily_data#8001'] },
    problem: '合成建议：人工核对携程转化', status: 'proposed', requires_human_confirmation: true,
    handoff_status: 'ready_for_task_proposal', workflow_type: 'conversion_optimization', completion_criteria: ['人工核对原始页面并记录证据'],
    review_window: { baseline_start: '2026-09-15', baseline_end: '2026-09-15', followup_start: '2026-09-16', followup_end: '2026-09-16' } };
  return { id: 901, hotel_id: 80, tenant_id: 7, report_date: '2026-09-15', final_text: text,
    evidence_readback_status: 'exact_readback_verified', evidence_snapshot: { contract_version: 'ai_evidence_reasoning.v1',
      scope: { tenant_id: 7, hotel_id: 80, business_date: '2026-09-15' }, final_text: text,
      final_text_sha256: createHash('sha256').update(text).digest('hex'), snapshot_fingerprint: 'a'.repeat(64),
      diagnosis: { recommendations: [suggestion] } } };
}
// Public consumer projection of OperationTaskWorkflowService::propose/readExecutionIntent.
// Assumes a permitted synthetic hotel and saved pending-approval intent, never a real DB write.
function intentReceipt(suggestion) {
  return { id: 301, ...clone(suggestion.scope), source_module: 'manual', source_record_id: 0,
    object_type: 'operation_checklist', action_type: suggestion.workflow_type, status: 'pending_approval', tasks: [],
    target_value: { title: '转化优化', action_text: suggestion.problem, steps: suggestion.completion_criteria,
      acceptance_criteria: suggestion.completion_criteria, object_ref: suggestion.scope.object_ref },
    evidence: { evidence_refs: suggestion.evidence_snapshot.source_refs, workflow_proposal: clone(suggestion),
      link_status: 'unverified_link', source_snapshot_digest: suggestion.evidence_snapshot.fingerprint } };
}
function flowBody(suggestion) {
  const intent = intentReceipt(suggestion);
  // Only fields consumed by this original identity/status subtree and loader are
  // projected; no task, ROI, effect or permission success beyond the declared scope.
  return { code: 200, message: 'success', data: { capabilities: { hotel_id: 80 }, list: [{ id: intent.id, hotel_id: intent.hotel_id,
    stage: 'approval', identity: { status: 'consistent', gap_count: 0 },
    recommendation: { source: 'manual#0', source_module: intent.source_module, source_record_id: intent.source_record_id,
      platform: intent.platform, object_type: intent.object_type, action_type: intent.action_type,
      date_start: intent.date_start, date_end: intent.date_end, target_value: intent.target_value, evidence: intent.evidence },
    approval: { status: 'pending_approval', approved_by: 0, approved_at: '', remark: '', blocked_reason: '' },
    execution: { task_id: 0, mode: '', status: 'pending_create', operator_id: 0, executed_at: '', blocked_reason: '', target_value: [], current_value: [] } }],
    summary: {}, stages: [], statistics: { execution_total_loaded: true },
    matched_total: 1, returned_count: 1, truncated: false, data_status: 'ok', data_gaps: [] } };
}
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, addEventListener() {}, removeEventListener() {} });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(n); else parent.children.splice(i, 0, n); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; },
  } };
}
const attempts = [];
function harness() {
  const requests = [], notices = [], errors = [], warnings = [], diagnostics = [], navigations = [];
  const report = Vue.ref(reportFixture()), suggestion = report.value.evidence_snapshot.diagnosis.recommendations[0];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl,
    setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api', computed: Vue.computed,
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    authSessionEpoch: 1, pageRequestGeneration: 0, operationActionsRequestSeq: 0, operatingMemoryRequestSeq: 0, pageLoadRequests: new Map(),
    currentPage: Vue.ref('ai-daily-report'), filterReportHotel: Vue.ref('81'), token: Vue.ref(authMarker),
    authContext: Vue.ref({ tenantId: 7, hotelId: 81, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]),
    user: Vue.ref({ id: 11, is_super_admin: true }), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    operationFilters: Vue.ref({ hotel_id: '81' }), operationExecutionStageFilter: Vue.ref('review'), operationExecutionViewMode: Vue.ref('mine'),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }), operatingGoalInterventionLoading: Vue.ref(false),
    operatingGoalInterventionError: Vue.ref(''), operationExecutionFlow: Vue.ref({ list: [], data_status: 'not_loaded' }),
    operationActions: Vue.ref([]), operationActionTrackingRead: Vue.ref({ data_status: 'not_loaded' }), operationApprovalConfirmingIntentId: Vue.ref(0), operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), operatingGoalInterventionOverview: Vue.ref({}),
    operatingMemoryLoading: Vue.ref(false), operatingMemoryError: Vue.ref(''), operatingMemories: Vue.ref({ list: [], data_status: 'not_loaded' }),
    homeOperatingScheduleError: Vue.ref(''), operationYesterday: '2026-09-19', shanghaiBusinessYesterday: '2026-09-19', nextTick: Vue.nextTick,
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    readRequestCooldown: { check: () => null, record() {} }, normalizeCanonicalPage: value => value,
    operationErrorMessage: error => error?.message || '读取失败', applyHomeOperatingScheduleFlow() {},
    autoFetchRunState: Vue.ref({ active: false, type: '' }), stopAutoFetchProgressMonitor() {}, stopAutoFetchRunTimer() {},
    // No timers of unrelated pages are scheduled in this extracted renderer.
    clearPageLifecycleTimers() {}, clearPostFetchRefreshTimers() {},
    showToast: (message, type = 'success') => notices.push({ message, type, page: sandbox.currentPage.value }),
    fetch: (url, options) => {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(new Headers(options.headers).get('Authorization'), authMarker, 'original auth header uses only the in-memory marker');
      const isProposal = method === 'POST' && endpoint === '/operation/task-workflow-proposals';
      const isArtifact = method === 'GET' && /^\/ai-daily-reports\/90[12]\/presentation-artifacts$/.test(endpoint);
      const isOperation = method === 'GET' && ['/operation/action-tracking', '/operation/execution-flow', '/operation/closure-overview', '/operation/operating-memories', '/operation/goal-intervention-overview'].includes(endpoint);
      assert.ok(isProposal || isArtifact || isOperation, `unexpected method/path: ${method} ${endpoint}`);
      assert.equal(Boolean(options.signal), method === 'GET', 'original safe-read signal / direct POST boundary');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), body: options.body ? JSON.parse(options.body) : null, signal: Boolean(options.signal), settled: false, aborted: false,
          resolve(body) { call.settled = true; call.response = clone(body); resolveRequest(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })); },
          reject() { call.settled = true; call.failure = 'synthetic transport TypeError: Failed to fetch'; rejectRequest(new TypeError('Failed to fetch')); } };
        requests.push(call); options.signal?.addEventListener('abort', () => { call.aborted = true; });
        if (isArtifact) call.resolve({ code: 200, message: 'success', data: { status: 'not_generated', render_status: 'not_generated', failure_reason: 'presentation_spec_not_generated',
          report_id: Number(endpoint.split('/')[2]), hotel_id: 80, audience: 'owner', presentation_spec_id: null, spec_fingerprint: null, artifact_id: null, artifact_readback_verified: false, bundle_base64: null } });
        else if (endpoint === '/operation/operating-memories') call.resolve({ code: 200, message: 'success', data: missingMemories });
        else if (endpoint === '/operation/goal-intervention-overview') call.resolve({ code: 200, message: 'success', data: missingGoals });
        else if (isOperation && endpoint !== '/operation/execution-flow') call.reject();
      });
    },
  };
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  sandbox.operationExecutionItems = Vue.computed(() => sandbox.operationExecutionFlow.value?.list || []);
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  // Preloaded actual static module; no loader-network facade or rewritten source.
  sandbox.ensureOperationStaticReady = async () => sandbox.window.SUXI_OPERATION_STATIC;
  vm.runInContext(`${requestSource}\nlet pendingOperationNavigation=null; let suppressNextOpsTrackAutoLoad=false; let previousPageLifecycleKey=currentPage.value;\n${methods}\nglobalThis.methods={openHomeOperatingScheduleItem,loadOperationActions};globalThis.scopeChange=newPage=>{${pageChange}\n${opsActivation}};globalThis.actualRequest=request;globalThis.filtered=operationExecutionFilteredItems;`, sandbox);
  sandbox.activateOpsTrackPage = () => { throw new Error('Unexpected unsuppressed ops activation'); };
  const stopWatcher = Vue.watch(sandbox.currentPage, sandbox.scopeChange);
  const deliveryWindow = {}; vm.runInNewContext(raw.delivery, { window: deliveryWindow, Vue, crypto: webcrypto, TextEncoder, URLSearchParams, Date, Intl });
  const trackOpen = item => { const promise = sandbox.methods.openHomeOperatingScheduleItem(item); navigations.push({ item: clone(item), promise }); return promise; };
  const ctx = Vue.reactive({ aiDailyReport: report, aiDailyReportForm: { hotel_id: '80', report_date: '2026-09-15' }, currentPage: sandbox.currentPage,
    aiDailyReportDeliveryRequest: sandbox.actualRequest, openHomeOperatingScheduleItem: trackOpen, showToast: sandbox.showToast });
  let setup;
  const Delivery = { props: ['ctx'], setup(props) { setup = deliveryWindow.SUXI_AI_DAILY_REPORT_DELIVERY.setup(props); return setup; }, render: aiRender };
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({ setup: () => ({ currentPage: sandbox.currentPage, authContext: sandbox.authContext,
    operationLoading: sandbox.operationLoading, operationError: sandbox.operationError, operationExecutionFilteredItems: sandbox.filtered,
    operationExecutionRowClass: () => '', operationExecutionStatusClass: sandbox.appSystemStatic.operationExecutionStatusClass,
    operationExecutionStatusLabel: sandbox.appSystemStatic.operationExecutionStatusLabel, loadOperationActions: sandbox.methods.loadOperationActions }),
    render() { return Vue.h(Vue.Fragment, [sandbox.currentPage.value === 'ai-daily-report' ? Vue.h(Delivery, { ctx }) : null, opsRender.call(this, this)]); } });
  app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => errors.push(error.message); app.mount(host.root);
  const walk = (n, all = []) => { all.push(n); n.children.forEach(c => walk(c, all)); return all; };
  const nodes = () => walk(host.root), text = n => n.text + n.children.map(text).join('');
  const button = id => nodes().find(n => n.type === 'button' && n.props['data-testid'] === id);
  const click = n => { assert.ok(n, 'original control exists'); for (let a = n; a; a = a.parent) assert.ok(!a.props.disabled && a.style.display !== 'none', 'original visible enabled ancestor'); return n.props.onClick({ type: 'click' }); };
  return { sandbox, ctx, report, suggestion, requests, notices, errors, warnings, diagnostics, navigations, nodes, button, click, text: () => text(host.root), setup: () => setup,
    flow: () => requests.filter(r => r.endpoint === '/operation/execution-flow'),
    snapshot: () => ({ requests: requests.map(({ resolve, reject, ...c }) => c), notices, errors, warnings, diagnostics, page: sandbox.currentPage.value, text: text(host.root), saved: clone(setup.aiEvidenceProposalState(suggestion)) }),
    async stop() { app.unmount(); stopWatcher(); for (const call of requests.filter(r => !r.settled)) { call.teardown_only = true; call.reject(); } await tick(); } };
}
async function saved(p) {
  await tick(); assert.equal(p.button('ai-evidence-open-intent'), undefined);
  const run = p.click(p.button('ai-evidence-propose'));
  await until(() => p.requests.some(r => r.method === 'POST'), 'original proposal POST');
  const post = p.requests.find(r => r.method === 'POST');
  assert.deepEqual(post.body, { hotel_id: 80, recommendation: clone(p.suggestion), tenant_id: '7', platform: 'all' });
  post.resolve({ code: 200, message: 'success', data: { readback_verified: true, created: true, replayed: false, intent: intentReceipt(p.suggestion) } });
  await run; await tick(); assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).status, 'saved');
  assert.match(p.text(), /已关联意图 #301/); assert.match(p.text(), /尚未证明执行或效果/);
}
async function open(p) { const run = p.click(p.button('ai-evidence-open-intent')); await until(() => p.flow().length > 0, 'original exact flow GET'); return { run }; }
async function scenario(name, body) {
  await test(name, async () => {
    let p; const attempt = { name }; attempts.push(attempt);
    try { p = harness(); await body(p); assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []); assert.ok(p.requests.every(c => c.settled));
      const failed = p.requests.filter(c => c.failure);
      assert.equal(p.diagnostics.length, failed.length);
      assert.ok(p.diagnostics.every(d => d[0] === 'API请求失败:' && d[2] === 'Failed to fetch' && failed.some(c => d[1].startsWith(c.endpoint + '?'))));
      attempt.pass = true; }
    catch (error) { attempt.error = error.stack; throw error; }
    finally { if (p) { attempt.before_teardown = p.snapshot(); await p.stop(); attempt.after_teardown = p.snapshot(); } }
  });
}
if (!process.argv.includes('--prepare-only')) {
await scenario('saved evidence shortcut locates the exact task but reports incomplete task reads', async p => {
  await saved(p); const receipt = clone(p.setup().aiEvidenceProposalState(p.suggestion));
  const { run } = await open(p); assert.deepEqual(p.navigations[0].item, { hotelId: 80, intentId: 301 });
  const read = p.flow()[0]; assert.equal(read.query.hotel_id, '80'); assert.equal(read.query.system_hotel_id, '80'); assert.equal(read.query.intent_id, '301');
  assert.equal(read.query.business_date, undefined); assert.equal(p.sandbox.operationExecutionViewMode.value, 'all'); assert.equal(p.sandbox.operationExecutionStageFilter.value, '');
  read.resolve(flowBody(p.suggestion)); await run; await tick();
  assert.equal(p.sandbox.currentPage.value, 'ops-track'); assert.ok(p.nodes().some(n => Number(n.props['data-operation-execution-intent-id']) === 301));
  assert.match(p.text(), /行动 #301 · 任务 #尚未创建 · ctrip · 2026-09-15/); assert.match(p.text(), /待审批/);
  assert.equal(p.requests.filter(r => r.method === 'POST').length, 1); assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).status, receipt.status);
  assert.equal(p.notices.at(-1).message, '策略追踪加载失败');
  assert.ok(!p.notices.some(row => row.message === '已打开 酒店 #80 的对应任务'));
  assert.match(p.text(), /策略追踪加载失败/); assert.equal(p.sandbox.operatingMemories.value.data_status, 'migration_required');
  assert.equal(p.sandbox.operatingGoalInterventionOverview.value.status, 'migration_required');
});
await scenario('unverified and changed report identities never expose an old saved shortcut', async p => {
  await tick(); assert.equal(p.button('ai-evidence-open-intent'), undefined); await saved(p);
  assert.ok(p.button('ai-evidence-open-intent')); const count = p.requests.length;
  // Direct component-input boundary, not a click on a hidden old control.
  p.report.value = { ...clone(p.report.value), id: 902 }; await tick();
  assert.equal(p.button('ai-evidence-open-intent'), undefined); assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).status, undefined);
  assert.equal(p.requests.filter(r => r.method === 'POST').length, 1); assert.equal(p.flow().length, 0);
  assert.ok(p.requests.slice(count).every(r => r.endpoint.endsWith('/presentation-artifacts')));
});
await scenario('opening failure preserves the saved receipt; original task refresh recovers without another proposal', async p => {
  await saved(p); const receipt = clone(p.setup().aiEvidenceProposalState(p.suggestion)), normalOpen = p.ctx.openHomeOperatingScheduleItem;
  p.ctx.openHomeOperatingScheduleItem = undefined;
  await p.click(p.button('ai-evidence-open-intent')); await tick();
  assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).status, 'saved'); assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).opening, false);
  assert.match(p.text(), /已关联意图 #301/); assert.equal(p.notices.at(-1).message, '行动已保存，但打开失败，请重试');
  p.ctx.openHomeOperatingScheduleItem = normalOpen; const { run } = await open(p); p.flow()[0].reject(); await run; await tick();
  assert.match(p.text(), /执行闭环加载失败/); assert.equal(p.sandbox.operationLoading.value.actions, false); assert.equal(p.setup().aiEvidenceProposalState(p.suggestion).intent_id, receipt.intent_id);
  const refresh = p.nodes().find(n => n.type === 'button' && n.text === '刷新'); const retry = p.click(refresh);
  await until(() => p.flow().length === 2, 'original refresh GET'); assert.equal(p.flow()[1].query.hotel_id, '80');
  p.flow()[1].resolve(flowBody(p.suggestion)); await retry; await tick();
  assert.match(p.text(), /行动 #301 · 任务 #尚未创建/); assert.equal(p.requests.filter(r => r.method === 'POST').length, 1);
});
}
const evidence = { source_reads: reads, original_sections: sections, template_paths: templatePaths, attempts,
  boundary: 'Original component factory and retained original template controls in a Vue memory renderer; original request/auth/GET coordinator and navigation. No browser, database, real account or execution. Unrelated overview reads are controlled transport failures and stay explicit failures, not empty success.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
console.log(JSON.stringify({ source_reads: reads, cases: attempts.map(a => ({ name: a.name, pass: !!a.pass, requests: a.before_teardown?.requests.length })) }));
