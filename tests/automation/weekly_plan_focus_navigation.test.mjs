import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash, webcrypto } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';
const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
const sourceRoot = resolve(option('source-root') || '.'), readers = [], sections = [], attempts = [];
const sha = value => createHash('sha256').update(value).digest('hex');
const read = path => { const actual = resolve(sourceRoot, path), text = readFileSync(actual, 'utf8');
  readers.push({ path, resolved_path: actual, sha256: sha(text).toUpperCase() }); return text; };
const raw = { main: read('public/app-main.js'), home: read('public/home-static.js'), system: read('public/system-static.js'),
  operation: read('public/operation-static.js'), template: read('resources/frontend/templates/fragments/23a-page-compass-summary.html'),
  ops: read('resources/frontend/templates/fragments/17-page-ops-track.html') };
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start);
  const text = main.slice(a, b); sections.push({ start, end, sha256: sha(text).toUpperCase() }); return text; };
const decl = name => { const marker = `            const ${name} =`, a = main.indexOf(marker); assert.ok(a >= 0, name);
  const end = /\n            (?:const|let) /.exec(main.slice(a + marker.length)); assert.ok(end, name);
  const text = main.slice(a, a + marker.length + end.index); sections.push({ declaration: name, sha256: sha(text).toUpperCase() }); return text; };
const methods = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
  decl('normalizeCanonicalPage'), decl('isCompassDataPage'),
  section('            const collectMenuPagePaths =', '            const resolveInitialPageOverride ='),
  decl('SUPER_ADMIN_ONLY_PAGES'), decl('guardSuperAdminPageAccess'), decl('handleMenuClick'),
  section('            const downloadBlob =', '            const buildCtripBusinessCanvas ='),
  // Original order matters: the factory is created before all late-bound navigation/read constants.
  section('            const homeWeeklyOperatingPlanController =', '            const operationExecutionStageFilter ='),
  decl('operationExecutionItems'), decl('operationExecutionFilteredItems'),
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  decl('readOperationExecutionTask'), decl('requireOperationStatic'),
  section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters ='),
  section('            const loadOperatingMemories =', '            const saveOperationExecutionMemory ='),
  section('            let homeOperatingScheduleRequestSeq =', '            const homeDailyWorkflowError ='),
  decl('operationApprovalConfirmingIntentId'),
  section('            let operationActionsRequestSeq =', '            const parseOperationEvidenceNumber ='),
].join('\n');
const pagePrefix = section('                const previousPage = previousPageLifecycleKey;', '                if (!isCompassDataPage(newPage))');
const opsActivation = section("                if (newPage === 'ops-track') {", "                if (newPage === 'operating-growth-archive') {");
const compassActivation = section('                if (isCompassDataPage(newPage)) {\n                    scheduleHomeSecondaryPanelsReady();', "                if (newPage === 'ctrip-ebooking') {");
const ancestors = [], ast = baseParse(raw.template + '\n' + raw.ops, parserOptions);
const attr = (node, key) => node.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
const target = node => node.tag === 'home-operating-orchestration'
  || attr(node, 'data-testid') === 'weekly-plan-return-origin'
  || (node.tag === 'div' && node.loc.source.startsWith('<div class="text-xs text-gray-400">行动 #{{ item.id }}'))
  || (node.tag === 'button' && node.loc.source.includes('@click="loadOperationActions"') && node.loc.source.includes('>刷新</button>'))
  || (node.tag === 'div' && node.loc.source.startsWith('<div v-if="operationError.actions"'));
const keep = (node, parents = []) => {
  if (node.type === 1 && target(node)) { ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
  const children = (node.children || []).map(n => keep(n, parents.concat(node.type === 1 ? [node] : []))).filter(Boolean);
  return children.length ? { ...node, children } : null;
};
compile(raw.template, { mode: 'function', prefixIdentifiers: true }); compile(raw.ops, { mode: 'function', prefixIdentifiers: true });
const render = new Function('Vue', compile(keep(ast), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));

const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])])) : value;
const period = weekEnd => { const date = new Date(`${weekEnd}T00:00:00Z`); date.setUTCDate(date.getUTCDate() - 6); return date.toISOString().slice(0, 10); };
// Same normalized v2 public consumer shape and canonical fingerprint keys as the
// accepted weekly_plan_download test. Source-backed synthetic snapshot, no PHP/DB.
function snapshot({ id = 301, hotelId = 80, weekEnd = '2026-09-06', version = 2, kind = 'intent', targetId = 301,
  focusType = '', taskStatus = 'executed' } = {}) {
  const weekStart = period(weekEnd), final_text = `宿析OS周度经营计划\n合成酒店 ${hotelId}\n周期：${weekStart} 至 ${weekEnd}\n原保存说明 ${id}\n缺失保持缺失，不自动审批、执行或写入OTA/PMS。`;
  const value = { contract_version: 'weekly_operating_plan.v2', snapshot_id: id, tenant_id: 9, hotel_id: hotelId,
    week_start: weekStart, week_end: weekEnd, version_no: version, status: 'partial',
    generated_at: `${weekEnd} 23:59:00`, generation_trigger: 'synthetic_readonly_fixture', source_digest: sha(`synthetic source ${hotelId}/${weekEnd}/${id}`),
    daily_run_refs: ['operating_opportunity_runs#701'], broadcast_snapshot_refs: [], repeated_gap_summary: [],
    lifecycle_summary: { pending_approval: 1, approved_or_executing: 0, blocked: 0, review_pending: 2, reviewed: 1,
      task_workflow: { status: 'ready', task_completed: 0, execution_verified: 1, reviewed: 1 } },
    selected_focus: { type: 'coverage_gap', key: 'weekly_operating_coverage_missing', title: `原保存重点 ${id}`, reason: '该周期仍有日期来源缺失。',
      evidence_refs: [`business_date#${weekEnd}`] },
    missing_days: { daily_priority: [weekEnd], trusted_broadcast: [weekEnd] }, final_text, final_text_sha256: sha(final_text),
    readback_verified: true, external_write_count: 0, external_message_count: 0 };
  if (kind !== 'gap') value.selected_focus = {
    type: focusType || (kind === 'task' ? 'review_pending' : 'oldest_pending_approval'),
    key: focusType || (kind === 'task' ? 'review_pending' : 'pending_approval'),
    title: '原保存重点：人工核对同酒店事项', reason: '保存时仍有事项待人工处理；不是实时结果。',
    evidence_refs: [kind === 'task' ? `operation_execution_tasks#${targetId}` : `operation_execution_intents#${targetId}`],
  };
  value.lifecycle_summary.pending_approval = kind === 'intent' ? 1 : 0;
  value.lifecycle_summary.review_pending = kind === 'task' ? 1 : 0;
  if (focusType === 'execution_pending') Object.assign(value.lifecycle_summary, {
    approved_or_executing: 1, pending_execute: Number(taskStatus === 'pending_execute'),
    executing: Number(taskStatus === 'executing'), review_pending: 0,
  });
  if (focusType === 'execution_pending') value.selected_focus.key = taskStatus;
  const keys = ['contract_version', 'tenant_id', 'hotel_id', 'week_start', 'week_end', 'version_no', 'status', 'source_digest',
    'daily_run_refs', 'broadcast_snapshot_refs', 'lifecycle_summary', 'repeated_gap_summary', 'selected_focus', 'missing_days', 'final_text_sha256', 'generation_trigger'];
  value.snapshot_fingerprint = sha(JSON.stringify(canonical(Object.fromEntries(keys.map(key => [key, value[key]])))));
  return value;
}

const missingMemories = { data_status: 'migration_required', list: [], count: 0, matched_total: 0, returned_count: 0, truncated: false,
  supported_layers: ['fact', 'analysis', 'judgement', 'decision', 'execution_review', 'milestone', 'sop'],
  supported_usage_levels: ['archive_only', 'reference', 'decision_support', 'sop_template'],
  data_gaps: [{ code: 'operating_memory_table_missing', message: '经营记忆表尚未创建，请先执行本地数据库迁移' }], source_policy: 'reference_existing_facts_without_ota_write' };
const missingGoals = { status: 'migration_required', migration_required: true,
  missing_tables: ['hotel_operating_goal_contracts', 'operation_intervention_contracts', 'operation_intervention_assessments'],
  tenant_id: 7, hotel_id: 80, current_goal_contract: null, history: [], interventions: [],
  summary: { supported: 0, contradicted: 0, indeterminate: 0, unassessed: 0 },
  monitor: { status: 'unavailable', monitor_state: 'inactive', reason_code: 'goal_learning_migration_required', last_observed_at: null } };

function host() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, open: false });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: { createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; if (at < 0) parent.children.push(n); else parent.children.splice(at, 0, n); },
    remove, patchProp: (n, key, old, next) => { n.props[key] = next; if (key === 'style') n.style = next || {}; } } };
}

const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 40 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const marker = 'synthetic-memory-session-round138';
// Public consumer projection of the original flow reader. T701 is a historical task;
// the same intent's current flow may truthfully project later task T702.
function flowBody({ id = 301, hotelId = 80, taskId = 0, taskStatus = 'executed' } = {}) {
  return { capabilities: { hotel_id: hotelId }, list: [{ id, hotel_id: hotelId,
    stage: taskId ? (taskStatus === 'executed' ? 'review' : 'execution') : 'approval', identity: { status: 'consistent', gap_count: 0 },
    recommendation: { source: 'daily_one_thing#701', source_module: 'daily_one_thing', source_record_id: 701,
      platform: 'ctrip', object_type: 'manual_operation', action_type: 'manual_review',
      date_start: '2026-09-02', date_end: '2026-09-02', target_value: {}, evidence: {} },
    approval: { status: taskId ? 'approved' : 'pending_approval', approved_by: taskId ? 11 : 0,
      approved_at: taskId ? '2026-09-02 10:00:00' : '', remark: '', blocked_reason: '' },
    execution: { task_id: taskId, mode: taskId ? 'manual' : '', status: taskId ? taskStatus : 'pending_create',
      operator_id: taskId && taskStatus !== 'pending_execute' ? 11 : 0,
      executed_at: taskId && taskStatus === 'executed' ? '2026-09-02 12:00:00' : '', blocked_reason: '', target_value: {}, current_value: {} },
  }], summary: {}, stages: [], statistics: { execution_total_loaded: true },
  matched_total: 1, returned_count: 1, truncated: false, data_status: 'ok', data_gaps: [] };
}
function harness(attempt) {
  const requests = [], errors = [], warnings = [], diagnostics = [], notices = [], screens = [], sidePorts = [];
  const scope = Vue.effectScope(), timers = [];
  const sandbox = { ...Vue, window: { Vue }, URL, URLSearchParams, Headers, FormData, Response, Blob, TextEncoder,
    crypto: webcrypto, AbortController, DOMException, Date, Intl, structuredClone, setTimeout, clearTimeout,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1,
    token: Vue.ref(marker), authContext: Vue.ref({ tenantId: 9, hotelId: 80, platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 138, tenant_id: 9, hotel_id: 80, is_super_admin: true }),
    permittedHotels: Vue.ref([{ id: 80, tenant_id: 9 }, { id: 81, tenant_id: 9 }]),
    currentPage: Vue.ref('compass'), filterReportHotel: Vue.ref('80'), operationHotelOptions: Vue.ref([{ id: 80 }, { id: 81 }]),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'), shanghaiToday: () => '2026-09-20',
    operationErrorMessage: (error, fallback) => error?.message || fallback,
    readRequestCooldown: { check: () => null, record() {} },
    operationFilters: Vue.ref({ hotel_id: '80' }), operationExecutionStageFilter: Vue.ref('review'), operationExecutionViewMode: Vue.ref('mine'),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }), operatingGoalInterventionLoading: Vue.ref(false),
    operatingGoalInterventionError: Vue.ref(''), operationExecutionFlow: Vue.ref({ list: [], data_status: 'not_loaded' }),
    operationActions: Vue.ref([]), operationActionTrackingRead: Vue.ref({ data_status: 'not_loaded' }), operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), operatingGoalInterventionOverview: Vue.ref({}),
    operatingMemoryLoading: Vue.ref(false), operatingMemoryError: Vue.ref(''), operatingMemories: Vue.ref({ list: [], data_status: 'not_loaded' }),
    operatingMemoryRequestSeq: 0, operationYesterday: '2026-09-19', shanghaiBusinessYesterday: '2026-09-19',
    homeOperatingScheduleLoading: Vue.ref(false), homeOperatingScheduleError: Vue.ref(''), homeOperatingScheduleFlow: Vue.ref(null),
    homeOperatingScheduleScopeHotelId: Vue.ref(''), homeOperatingScheduleLastReadAt: Vue.ref(''), homeSecondaryPanelsReady: Vue.ref(true),
    autoFetchRunState: Vue.ref({ active: false, type: '' }),
    console: { error: (...args) => diagnostics.push(args.map(value => value?.message || String(value))),
      warn: (...args) => warnings.push(args.map(value => value?.message || String(value))) },
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    showToast: (message, type = 'success') => notices.push({ message, type, page: sandbox.currentPage.value }),
    loadCompassData: () => { throw new Error('Cache-hit control must not claim a new full compass facts read'); },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, '');
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
      assert.ok(endpoint.startsWith('/operation/') || endpoint === '/operating-opportunities/weekly-plan/latest');
      assert.equal(new Headers(options.headers).get('Authorization'), marker); assert.ok(options.signal, 'original GET AbortSignal');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, query: Object.fromEntries(parsed.searchParams), method: 'GET', settled: false, aborted: false,
          resolve(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data);
            resolveRequest(new Response(JSON.stringify({ code: 200, message: 'success', data }), { status: 200 })); },
          reject(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown;
            call.failure = 'controlled transport TypeError'; rejectRequest(new TypeError('Failed to fetch')); } };
        requests.push(call); options.signal.addEventListener('abort', () => {
          call.aborted = true; if (!call.settled) { call.settled = true; rejectRequest(new DOMException('Aborted', 'AbortError')); }
        });
        queueMicrotask(() => {
          if (endpoint === '/operation/operating-memories') call.resolve({ ...clone(missingMemories) });
          if (endpoint === '/operation/goal-intervention-overview') call.resolve({ ...clone(missingGoals), tenant_id: 9 });
          if (['/operation/action-tracking', '/operation/closure-overview'].includes(endpoint)) call.reject();
        });
      });
    },
  };
  for (const name of ['stopAutoFetchProgressMonitor', 'stopAutoFetchRunTimer', 'clearPageLifecycleTimers', 'clearPostFetchRefreshTimers',
    'scheduleHomeSecondaryPanelsReady', 'scheduleDualOtaWorkbenchAutoFetch', 'scheduleDualOtaSystemMetricDrilldownHydration']) {
    sandbox[name] = (...args) => sidePorts.push({ name, args });
  }
  sandbox.window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  attempt.capture = () => ({ requests: requests.map(({ resolve, reject, ...row }) => row), errors, warnings, diagnostics, sidePorts });
  attempt.cleanup = async () => { scope.stop(); requests.filter(row => !row.settled).forEach(row => row.reject(true)); await tick(); };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.home, sandbox); vm.runInContext(raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key]; sandbox.homeStatic = sandbox.window.SUXI_HOME_STATIC;
  sandbox.menuItemDefinitions = sandbox.appSystemStatic.menuItemDefinitions;
  sandbox.filterVisibleMenuItemsForUser = sandbox.appSystemStatic.filterVisibleMenuItems;
  // Only the actual already-loaded module boundary is used, not a rewritten task reader.
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.ensureOperationStaticReady = sandbox.loadOperationStatic;
  scope.run(() => vm.runInContext(`let pendingOperationNavigation=null; let suppressNextOpsTrackAutoLoad=false; let previousPageLifecycleKey=currentPage.value;\n${methods}
    globalThis.ui=homeWeeklyOperatingPlanController;
    globalThis.methods={openHomeOperatingScheduleItem,openHomeOperatingScheduleAll,loadOperationActions,loadHomeOperatingSchedule,handleMenuClick};
    globalThis.items=operationExecutionFilteredItems;
    globalThis.scopeChange=newPage=>{${pagePrefix}\n${opsActivation}\n${compassActivation}};
    globalThis.primeHomeCache=()=>runPageLoadOnce('compass','main',()=>loadHomeOperatingSchedule({hotelId:'80'}),{ttlMs:DASHBOARD_PAGE_CACHE_TTL_MS,requestPolicy:currentCompassReadPolicy()});
    globalThis.cacheEvidence=()=>[...pageLoadRequests.entries()].map(([key,value])=>({key,loadedAt:value.loadedAt,pending:!!value.promise,page:value.pageKey}));
    globalThis.coordinator=()=>({active:coordinatedGetActiveCount,inflight:coordinatedGetRequests.size,queued:coordinatedGetQueue.length});`, sandbox));
  sandbox.activateOpsTrackPage = () => { throw new Error('Unexpected duplicate ops activation'); };
  const stopWatcher = Vue.watch(sandbox.currentPage, sandbox.scopeChange);
  const memory = host(), renderer = Vue.createRenderer(memory.options);
  const app = renderer.createApp({ components: { HomeOperatingOrchestration: sandbox.homeStatic.HomeOperatingOrchestration },
    setup: () => ({ ...sandbox.ui, homeWeeklyOperatingPlanController: sandbox.ui,
      currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel, currentClockText: '12:00',
      authContext: sandbox.authContext, operationLoading: sandbox.operationLoading, operationError: sandbox.operationError,
      operationExecutionFilteredItems: sandbox.items, operationExecutionRowClass: () => '',
      homeOperatingScheduleModel: { stateCode: 'ready', stateLabel: '独立任务列表', items: [] },
      homeOperatingScheduleLoading: sandbox.homeOperatingScheduleLoading, ...sandbox.methods }), render });
  app.config.errorHandler = error => errors.push(error.message); app.config.warnHandler = warning => warnings.push(warning);
  const walk = (node, output = []) => { output.push(node); node.children.forEach(child => walk(child, output)); return output; };
  const nodes = () => walk(memory.root), content = node => node.text + node.children.map(content).join('');
  const find = id => nodes().find(node => node.props['data-testid'] === id);
  const visible = node => { for (let n = node; n; n = n.parent) {
    if (n.props.disabled || n.style.display === 'none') return false;
    if (n !== node && n.type === 'details' && !n.open
      && !(node.type === 'summary' && node.parent === n
        && n.children.find(child => child.type === 'summary') === node)) return false;
  } return true; };
  const control = id => { const node = find(id); assert.ok(node, `original control ${id} exists`); assert.ok(visible(node), 'original visible enabled ancestors'); return node; };
  const openDetails = () => { const summary = nodes().find(node => node.type === 'summary' && node.parent?.type === 'details' && String(node.parent.props.class).includes('home-weekly-fold'));
    assert.ok(summary, 'original weekly summary'); assert.ok(visible(summary)); summary.parent.open = true; };
  const capture = () => ({ requests: requests.map(({ resolve, reject, ...row }) => row), diagnostics, errors, warnings, notices, screens, sidePorts,
    coordinator: clone(sandbox.coordinator()), cache: clone(sandbox.cacheEvidence()), text: content(memory.root), page: sandbox.currentPage.value,
    plan: clone(sandbox.ui.homeWeeklyOperatingPlan.value), selected_week: sandbox.ui.homeWeeklyOperatingPlanWeekEnd.value,
    loading: sandbox.ui.homeWeeklyOperatingPlanLoading.value, error: sandbox.ui.homeWeeklyOperatingPlanError.value });
  attempt.capture = capture; attempt.cleanup = async () => { app.unmount(); stopWatcher(); scope.stop();
    requests.filter(row => !row.settled).forEach(row => row.reject(true)); await tick(); };
  app.mount(memory.root);
  return { sandbox, requests, errors, warnings, diagnostics, nodes, find, control, openDetails,
    click: id => control(id).props.onClick({ type: 'click' }), text: () => content(memory.root),
    screen: label => screens.push({ label, text: content(memory.root), page: sandbox.currentPage.value }) };
}
const pending = (p, endpoint) => p.requests.find(row => !row.settled && row.endpoint === endpoint);
async function answer(p, endpoint, data) { await until(() => pending(p, endpoint), endpoint); const call = pending(p, endpoint); call.resolve(data); await tick(); return call; }
async function readPlan(p, config = {}) {
  const date = config.weekEnd || '2026-09-06'; p.control('home-weekly-plan-week-end').props.onChange({ target: { value: date } }); await tick();
  const run = p.click('home-weekly-plan-read'); const call = await answer(p, '/operating-opportunities/weekly-plan/latest', snapshot(config));
  assert.equal(call.query.week_end, date); assert.equal(call.query.hotel_id, '80'); await run; await tick(); return p.sandbox.ui.homeWeeklyOperatingPlan.value;
}
function assertSavedExecutionCounts(p, pendingExecute, executing) {
  const summary = p.nodes().find(node => node.type === 'p' && node.text.startsWith('保存时汇总：'));
  assert.ok(summary, 'the saved snapshot summary is rendered');
  assert.match(summary.text, new RegExp(`待执行 ${pendingExecute}(?: · |；)`));
  assert.match(summary.text, new RegExp(`执行中 ${executing}(?: · |；)`));
}
async function primeCache(p) {
  // Explicit cache-contract setup: the original runPageLoadOnce executes the original
  // home schedule reader and its real synthetic flow + weekly GETs. No fake future
  // cache timestamp, no invented successful full compass/dashboard payload.
  const run = p.sandbox.primeHomeCache(); await answer(p, '/operation/execution-flow', flowBody());
  await answer(p, '/operating-opportunities/weekly-plan/latest', snapshot({ weekEnd: '2026-09-13' })); await run;
  assert.ok(p.sandbox.cacheEvidence().some(row => row.page === 'compass' && row.loadedAt > 0 && !row.pending));
}
async function finishNavigation(p, run, taskId = 0, taskStatus = 'executed') {
  const call = await answer(p, '/operation/execution-flow', flowBody({ taskId, taskStatus }));
  assert.equal(call.query.hotel_id, '80'); assert.equal(call.query.system_hotel_id, '80'); assert.equal(call.query.intent_id, '301');
  assert.equal(call.query.business_date, undefined); await run; await tick();
  assert.equal(p.sandbox.currentPage.value, 'ops-track');
  assert.ok(p.nodes().some(node => Number(node.props['data-operation-execution-intent-id']) === 301));
  assert.match(p.text(), new RegExp(`行动 #301 · 任务 #${taskId || '尚未创建'}`));
  assert.match(p.text(), /策略追踪加载失败/); assert.equal(p.sandbox.operatingMemories.value.data_status, 'migration_required');
}
async function scenario(name, body) { await test(name, async () => {
  const attempt = { name }; attempts.push(attempt); let p;
  try { p = harness(attempt); await tick(); p.openDetails(); await body(p);
    assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []);
    assert.deepEqual(clone(p.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0 });
    assert.ok(p.requests.every(row => row.settled && !row.teardown_only));
    const failed = p.requests.filter(row => row.failure); assert.equal(p.diagnostics.length, failed.length);
    p.diagnostics.forEach(row => { assert.equal(row[0], 'API请求失败:'); assert.equal(row[2], 'Failed to fetch');
      assert.ok(failed.some(call => row[1].startsWith(call.endpoint))); }); attempt.pass = true;
  } catch (error) { attempt.error = error.stack; throw error; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.cleanup) await attempt.cleanup();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.cleanup; }
}); }
if (!process.argv.includes('--prepare-only')) {
for (const taskStatus of ['pending_execute', 'executing']) {
  await scenario(`execution_pending ${taskStatus} focus reads its exact original task and opens the same scoped action`, async p => {
    await primeCache(p);
    const saved = await readPlan(p, { kind: 'task', targetId: 701, focusType: 'execution_pending', taskStatus });
    assertSavedExecutionCounts(p, Number(taskStatus === 'pending_execute'), Number(taskStatus === 'executing'));
    assert.match(p.control('home-weekly-focus-open').text, /查看任务 #701 所属行动/);
    const run = p.click('home-weekly-focus-open');
    const taskRead = await answer(p, '/operation/execution-tasks/701', { id: 701, hotel_id: 80, tenant_id: 9, intent_id: 301, status: taskStatus });
    assert.equal(taskRead.query.hotel_id, '80'); assert.equal(taskRead.query.system_hotel_id, '80');
    await finishNavigation(p, run, 701, taskStatus);
    assert.deepEqual(clone(p.sandbox.ui.focusNavigation.returnOrigin), {
      hotelId: saved.hotel_id, weekEnd: saved.week_end, snapshotId: saved.snapshot_id,
      version: saved.version_no, taskId: 701, intentId: 301,
    });
    assert.equal(p.sandbox.items.value.find(row => Number(row.id) === 301)?.execution.status, taskStatus);
    assert.match(p.text(), /截止 2026-09-06，快照 #301 \/ 版本 2/);
    assert.match(p.text(), /原任务 #701 的所属行动 #301/);
    assert.equal(p.requests.filter(row => row.endpoint === '/operation/execution-tasks/701').length, 1);
    assert.ok(p.requests.every(row => row.method === 'GET'));
    p.screen(`execution_pending ${taskStatus} preserves the original task and saved snapshot identity`);
  });
}
await scenario('execution_pending rejects mismatched task identity and scope without losing the snapshot, then retries the exact task', async p => {
  await primeCache(p);
  const saved = await readPlan(p, { kind: 'task', targetId: 701, focusType: 'execution_pending', taskStatus: 'pending_execute' });
  const task = { id: 701, hotel_id: 80, tenant_id: 9, intent_id: 301, status: 'pending_execute' };
  const flowReads = () => p.requests.filter(row => row.endpoint === '/operation/execution-flow').length;
  const originalFlowReads = flowReads();
  for (const mismatch of [{ id: 702 }, { hotel_id: 81 }, { tenant_id: 10 }, { intent_id: 0 }]) {
    const run = p.click('home-weekly-focus-open');
    const call = await answer(p, '/operation/execution-tasks/701', { ...task, ...mismatch });
    assert.equal(call.query.hotel_id, '80'); assert.equal(call.query.system_hotel_id, '80');
    await run; await tick();
    assert.equal(p.sandbox.currentPage.value, 'compass');
    assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, saved);
    assert.equal(flowReads(), originalFlowReads, 'an invalid original task must not resolve a parent action');
    assert.equal(p.find('weekly-plan-return'), undefined);
    assert.match(p.text(), /原任务或所属行动读取失败/);
  }
  const run = p.click('home-weekly-focus-open'); await answer(p, '/operation/execution-tasks/701', task);
  await finishNavigation(p, run, 701, 'pending_execute');
  assert.match(p.text(), /截止 2026-09-06，快照 #301 \/ 版本 2/);
  assert.match(p.text(), /原任务 #701 的所属行动 #301/);
  assert.ok(p.requests.every(row => row.method === 'GET'));
  p.screen('execution_pending identity failures retain the saved plan and recover through the exact original task');
});
await scenario('saved intent focus opens the exact action; return rereads the selected historical week and reports its actual newer version', async p => {
  await primeCache(p); await readPlan(p);
  assertSavedExecutionCounts(p, '未返回', '未返回');
  const run = p.click('home-weekly-focus-open'); await finishNavigation(p, run);
  assert.match(p.text(), /截止 2026-09-06，快照 #301 \/ 版本 2/); assert.match(p.text(), /返回将重新读取原周范围，版本可能更新/);
  const returning = p.click('weekly-plan-return'); const back = await answer(p, '/operating-opportunities/weekly-plan/latest', snapshot({ id: 302, version: 3 }));
  assert.equal(back.query.week_end, '2026-09-06'); await returning; await tick(); p.openDetails();
  assert.match(p.text(), /原周期 2026-08-31 至 2026-09-06 · 快照 #302 · 版本 3/);
  assert.equal(p.sandbox.ui.homeWeeklyOperatingPlanWeekEnd.value, '2026-09-06');
  p.screen('returned historical range and truthful latest saved version');
});
await scenario('task focus failure and wrong-hotel response retain the saved plan; retry resolves the real parent and labels a different latest task honestly', async p => {
  await primeCache(p); const saved = await readPlan(p, { kind: 'task', targetId: 701 });
  assert.match(p.control('home-weekly-focus-open').text, /查看任务 #701 所属行动/);
  let run = p.click('home-weekly-focus-open'); await until(() => pending(p, '/operation/execution-tasks/701'), 'exact task GET');
  pending(p, '/operation/execution-tasks/701').reject(); await run; await tick();
  assert.equal(p.sandbox.currentPage.value, 'compass'); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, saved);
  assert.match(p.text(), /原任务或所属行动读取失败/);
  run = p.click('home-weekly-focus-open'); const bad = await answer(p, '/operation/execution-tasks/701', { id: 701, hotel_id: 81, tenant_id: 9, intent_id: 301 });
  assert.equal(bad.query.hotel_id, '80'); assert.equal(bad.query.system_hotel_id, '80'); await run; await tick();
  assert.equal(p.sandbox.currentPage.value, 'compass'); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, saved);
  run = p.click('home-weekly-focus-open'); await answer(p, '/operation/execution-tasks/701', { id: 701, hotel_id: 80, tenant_id: 9, intent_id: 301 });
  await finishNavigation(p, run, 702); assert.match(p.text(), /原任务 #701 的所属行动 #301/);
  assert.match(p.text(), /列表可能显示该行动的更新任务/); assert.doesNotMatch(p.text(), /已定位原任务 #701/);
  const back = p.click('weekly-plan-return'); const call = await answer(p, '/operating-opportunities/weekly-plan/latest', snapshot({ kind: 'task', targetId: 701 }));
  assert.equal(call.query.week_end, '2026-09-06'); await back; await tick(); p.openDetails(); p.screen('task parent navigation remains readonly and recoverable');
});
await scenario('gap and invalid references cannot navigate; full-readiness and stale plan/session controls do not publish another scope or leave a return ticket', async p => {
  await readPlan(p, { kind: 'gap' }); assert.ok(p.find('home-weekly-focus-unlinked')); assert.equal(p.find('home-weekly-focus-open'), undefined);
  await readPlan(p, { targetId: '9007199254740993' }); assert.equal(p.find('home-weekly-focus-open'), undefined);
  await readPlan(p, { kind: 'task', targetId: 701 }); const before = p.requests.length;
  p.sandbox.document.documentElement.dataset.suxiRenderPhase = 'shell'; await p.click('home-weekly-focus-open'); await tick();
  assert.equal(p.requests.length, before); assert.match(p.text(), /页面仍在准备/); p.sandbox.document.documentElement.dataset.suxiRenderPhase = 'full';
  let run = p.click('home-weekly-focus-open'); await until(() => pending(p, '/operation/execution-tasks/701'), 'pending task read');
  // Original date control remains editable; no hidden/disabled event is dispatched.
  p.control('home-weekly-plan-week-end').props.onChange({ target: { value: '2026-09-05' } }); await tick();
  await answer(p, '/operation/execution-tasks/701', { id: 701, hotel_id: 80, tenant_id: 9, intent_id: 301 }); await run;
  assert.equal(p.sandbox.currentPage.value, 'compass'); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, null);
  await readPlan(p, { kind: 'task', targetId: 701 }); run = p.click('home-weekly-focus-open');
  await until(() => pending(p, '/operation/execution-tasks/701'), 'second pending task read');
  // Direct auth-generation boundary only, not a real login.
  p.sandbox.authSessionEpoch += 1; await answer(p, '/operation/execution-tasks/701', { id: 701, hotel_id: 80, tenant_id: 9, intent_id: 301 }); await run; await tick();
  assert.equal(p.sandbox.currentPage.value, 'compass'); assert.equal(p.find('weekly-plan-return'), undefined);
  p.screen('stale task read never navigates or creates a return ticket');
});
}
const evidence = { source_readers: readers, original_sections: sections, template_ancestors: ancestors, attempts,
  boundary: 'Original loaded home/operation modules and main factory, original controls/ancestors, auth/request/GET coordinator and navigation in a Vue memory renderer. Original runPageLoadOnce is primed using genuine synthetic home-flow + weekly reads; the return is the cache-hit branch, not a full dashboard facts run. Native details expansion is modeled. Task and flow fixtures are source-backed public consumer projections. No HTTP/PHP/DB/account/writes or approvals.' };
if (option('evidence')) writeFileSync(option('evidence'), JSON.stringify(evidence, null, 2));
console.log(JSON.stringify({ source_readers: readers, attempts: attempts.map(row => ({ name: row.name, pass: !!row.pass, requests: row.before_teardown?.requests.length })) }));
