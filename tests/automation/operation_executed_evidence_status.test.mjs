import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Canonical source by default; an explicit root only maps staged red/green source.
const sourceRoot = process.argv.find(a => a.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(a => a.startsWith('--evidence='))?.slice(11);
const reads = [], sections = [], templatePaths = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = path => {
  const mapped = sourceRoot && resolve(sourceRoot, path);
  const actual = mapped && existsSync(mapped) ? mapped : resolve(path), source = readFileSync(actual, 'utf8');
  reads.push({ path, resolved_path: actual, sha256: hash(source) }); return source;
};
const raw = { main: read('public/app-main.js'), operation: read('public/operation-static.js'), system: read('public/system-static.js'),
  components: read('public/components/system/app-main-components.js'),
  ops: read('resources/frontend/templates/fragments/17-page-ops-track.html'), online: read('resources/frontend/templates/fragments/35-page-online-data.html') };
const main = raw.main.replaceAll('\r\n', '\n');
function cut(source, start, end) {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start);
  const code = source.slice(a, b); sections.push({ start, end, sha256: hash(code) }); return code;
}
const section = (start, end) => cut(main, start, end);
const requestSource = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
].join('\n');
const methods = [
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters ='),
  section('            const operationEvidenceModalOpen =', '            const operationReviewModalOpen ='),
  cut(raw.components, '    const parseOperationEvidenceNumber =', '    const normalizeOperationReviewStatus ='),
  section('            const operationExecutionHotelId =', '            const collectPriceExecutionIntentFields ='),
  section('            const loadOperatingMemories =', '            const saveOperationExecutionMemory ='),
  section('            const loadOperationActions =', '            const parseOperationEvidenceNumber ='),
  section('            const recordOperationExecutionEvidence =', '            const recordOperationRoiEvidence ='),
  section('            const operationExecutionFilteredItems =', '            const operationExecutionStageFilterLabel ='),
  'const operationExecutionNodeRecordText = window.SUXI_OPERATION_STATIC.operationExecutionNodeRecordText;',
  section('            const nodeText = (item)', '            let operationExecutionRoiTextForRoi ='),
].join('\n');
const attr = (node, key) => node.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
// Retain the complete original ancestors of the 17 entry, row status and modal.
// Targets have independent v-if conditions, not a pruned sibling v-else chain.
function retainedRender(source, predicate, name, definitionOnly = false) {
  const ast = baseParse(source, parserOptions), retained = [];
  function visit(node, parents) {
    if (node.type !== 1) return null;
    if (predicate(node)) { retained.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
    const children = (node.children || []).map(n => visit(n, parents.concat(node))).filter(Boolean);
    return children.length ? { ...node, children } : null;
  }
  ast.children = ast.children.map(n => visit(n, [])).filter(Boolean); assert.ok(retained.length, name);
  templatePaths.push({ name, definition_only: definitionOnly, ancestors: retained });
  return new Function('Vue', compile(ast, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
}
// Full template compilation is a syntax check, not a claim of a full page mount.
for (const source of [raw.ops, raw.online]) compile(source, { mode: 'function', prefixIdentifiers: true });
const opsRender = retainedRender(raw.ops, node => attr(node, 'data-testid') === 'operation-evidence-modal'
  || (node.tag === 'button' && node.loc.source.includes('@click="recordOperationExecutionEvidence(item)"'))
  || (node.tag === 'td' && node.loc.source.includes('{{ operationExecutionStatusLabel(item.execution?.status) }}'))
  || (node.tag === 'td' && node.loc.source.includes('{{ nodeText(item) }}'))
  || (node.tag === 'div' && node.loc.source.startsWith('<div class="text-xs text-gray-400">行动 #'))
  || (node.tag === 'div' && node.loc.source.startsWith('<div v-if="operationError.actions"')),
  'Original 17 entry, identity, status, modal and adjunct error');
const onlineAst = baseParse(raw.online, parserOptions);
const find = (node, predicate) => predicate(node) ? node : (node.children || []).map(n => find(n, predicate)).find(Boolean);
const onlineModal = find(onlineAst, node => node.type === 1 && attr(node, 'data-testid') === 'core-loop-operation-evidence-modal');
assert.ok(onlineModal);
const onlineRender = retainedRender(onlineModal.loc.source, () => true, 'Original 35 modal definition compatibility', true);

// These literals are inherited from the existing pure-PHP normalization sample
// in operation_execution_evidence_form_lock.test.mjs, not freshly queried DB data.
const backendSamples = {
  "submitted_normalized_payload": {
    "task_id": 501,
    "evidence_type": "manual_operation_execution",
    "before": [],
    "after": [],
    "attachment_path": "",
    "platform_response": {
      "mode": "manual_operation_execution",
      "scope": "ota_channel_operation",
      "execution_status": "executed",
      "completed_action": "Synthetic action A",
      "expected_metric": "synthetic_metric",
      "executed_by": "Synthetic operator",
      "executed_at": "2026-09-15 09:00:00",
      "platform_receipt_id": "synthetic-receipt-A",
      "next_review_date": "2026-09-16",
      "effect_status": "pending_verification",
      "evidence_boundary": "local_manual_evidence_no_ota_write"
    },
    "remark": "Synthetic note A",
    "created_by": 9,
    "created_at": "2026-09-15 09:00:01"
  },
  "get_task_core": {
    "id": 501,
    "intent_id": 601,
    "hotel_id": 7,
    "tenant_id": "3",
    "operator_id": 9,
    "status": "executed",
    "action_track_id": 0,
    "current_value": [],
    "target_value": {
      "title": "Synthetic task"
    },
    "evidence": [
      {
        "id": 701,
        "task_id": 501,
        "tenant_id": "3",
        "evidence_type": "manual_operation_execution",
        "attachment_path": "",
        "remark": "Synthetic note A",
        "created_by": 9,
        "created_at": "2026-09-15 09:00:01",
        "updated_at": "2026-09-15 09:00:01",
        "deleted_at": null,
        "before": [],
        "after": [],
        "platform_response": {
          "mode": "manual_operation_execution",
          "scope": "ota_channel_operation",
          "execution_status": "executed",
          "completed_action": "Synthetic action A",
          "expected_metric": "synthetic_metric",
          "executed_by": "Synthetic operator",
          "executed_at": "2026-09-15 09:00:00",
          "platform_receipt_id": "synthetic-receipt-A",
          "next_review_date": "2026-09-16",
          "effect_status": "pending_verification",
          "evidence_boundary": "local_manual_evidence_no_ota_write"
        }
      }
    ],
    "execution_evidence": [
      {
        "id": 701,
        "task_id": 501,
        "tenant_id": "3",
        "evidence_type": "manual_operation_execution",
        "attachment_path": "",
        "remark": "Synthetic note A",
        "created_by": 9,
        "created_at": "2026-09-15 09:00:01",
        "updated_at": "2026-09-15 09:00:01",
        "deleted_at": null,
        "before": [],
        "after": [],
        "platform_response": {
          "mode": "manual_operation_execution",
          "scope": "ota_channel_operation",
          "execution_status": "executed",
          "completed_action": "Synthetic action A",
          "expected_metric": "synthetic_metric",
          "executed_by": "Synthetic operator",
          "executed_at": "2026-09-15 09:00:00",
          "platform_receipt_id": "synthetic-receipt-A",
          "next_review_date": "2026-09-16",
          "effect_status": "pending_verification",
          "evidence_boundary": "local_manual_evidence_no_ota_write"
        }
      }
    ]
  },
  "get_evidence_row": {
    "id": 701,
    "task_id": 501,
    "tenant_id": "3",
    "evidence_type": "manual_operation_execution",
    "attachment_path": "",
    "remark": "Synthetic note A",
    "created_by": 9,
    "created_at": "2026-09-15 09:00:01",
    "updated_at": "2026-09-15 09:00:01",
    "deleted_at": null,
    "before": [],
    "after": [],
    "platform_response": {
      "mode": "manual_operation_execution",
      "scope": "ota_channel_operation",
      "execution_status": "executed",
      "completed_action": "Synthetic action A",
      "expected_metric": "synthetic_metric",
      "executed_by": "Synthetic operator",
      "executed_at": "2026-09-15 09:00:00",
      "platform_receipt_id": "synthetic-receipt-A",
      "next_review_date": "2026-09-16",
      "effect_status": "pending_verification",
      "evidence_boundary": "local_manual_evidence_no_ota_write"
    }
  },
  "post_evidence_write": {
    "evidence_id": 701,
    "created": false,
    "replayed": true,
    "fingerprint": "22291bd01a0083bb521d463e133256037664ba9c6edeea02ca2fe20142c8abb0"
  }
};
const clone = value => JSON.parse(JSON.stringify(value));
const stable = value => Array.isArray(value) ? value.map(stable) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])])) : value;
const fingerprint = evidence => createHash('sha256').update(JSON.stringify(stable({ evidence_type: evidence.evidence_type,
  before: evidence.before, after: evidence.after, attachment_path: evidence.attachment_path.trim(),
  platform_response: evidence.platform_response, remark: evidence.remark.trim() }))).digest('hex');
assert.equal(fingerprint(backendSamples.submitted_normalized_payload), backendSamples.post_evidence_write.fingerprint);
function persistedTask(payload) {
  const current = { ...clone(backendSamples.get_evidence_row), ...clone(payload.evidence), id: 41, task_id: 11, tenant_id: '70', created_by: 901,
    created_at: '2026-09-15 10:31:00', updated_at: '2026-09-15 10:31:00', before: [], after: [], evidence_type: payload.evidence_type };
  const older = { ...clone(current), id: 40, remark: 'Synthetic older manual evidence',
    platform_response: { ...clone(current.platform_response), completed_action: 'Synthetic older manual evidence' } };
  const evidence = [current, older];
  // The active addExecutionEvidenceAuthorized appends evidence and never changes task status.
  return { ...clone(backendSamples.get_task_core), id: 11, hotel_id: 7, tenant_id: '70', intent_id: 111, operator_id: 901,
    status: 'executed', evidence, execution_evidence: clone(evidence) };
}
function row(count = 1) {
  // Exact consumer projection: an approved ordinary non-price manual task with
  // operator-attested evidence, without verified operating outcomes, stays evidence stage.
  return { id: 111, hotel_id: 7, stage: 'evidence', identity: { status: 'consistent', gap_count: 0 },
    recommendation: { source: 'manual#0', source_module: 'manual', source_record_id: 0, platform: 'ctrip', object_type: 'content',
      action_type: 'manual_operation', date_start: '2026-09-15', date_end: '2026-09-15', expected_metric: 'conversion_rate' },
    approval: { status: 'approved', approved_by: 901, approved_at: '2026-09-15 09:00:00', remark: '', blocked_reason: '' },
    execution: { task_id: 11, status: 'executed', mode: 'manual', operator_id: 901, executed_at: '2026-09-15 10:30:00', blocked_reason: '' },
    evidence_summary: { count, types: ['manual_operation_execution'], latest_type: 'manual_operation_execution',
      latest_at: '2026-09-15 10:31:00', node_record: { status: 'missing' } },
    evidence_truth: { source_verified: false }, next_action: { key: 'record_evidence', label: '补充执行证据' } };
}
const flowBody = count => ({ code: 200, message: 'success', data: { capabilities: { hotel_id: 7 }, list: [row(count)],
  matched_total: 1, returned_count: 1, truncated: false, data_status: 'ok', data_gaps: [], summary: {}, stages: [], statistics: { execution_total_loaded: true } } });
// Adjunct readers are actually executed. Their migration/transport failures are
// explicit: this test proves the task/evidence result, not a healthy whole operations page.
const missingMemories = { data_status: 'migration_required', list: [], count: 0, matched_total: 0, returned_count: 0, truncated: false,
  supported_layers: ['fact', 'analysis', 'judgement', 'decision', 'execution_review', 'milestone', 'sop'],
  supported_usage_levels: ['archive_only', 'reference', 'decision_support', 'sop_template'],
  data_gaps: [{ code: 'operating_memory_table_missing', message: '经营记忆表尚未创建，请先执行本地数据库迁移' }], source_policy: 'reference_existing_facts_without_ota_write' };
const missingGoals = { status: 'migration_required', migration_required: true,
  missing_tables: ['hotel_operating_goal_contracts', 'operation_intervention_contracts', 'operation_intervention_assessments'], tenant_id: 70, hotel_id: 7,
  current_goal_contract: null, history: [], interventions: [], summary: { supported: 0, contradicted: 0, indeterminate: 0, unassessed: 0 },
  monitor: { status: 'unavailable', monitor_state: 'inactive', reason_code: 'goal_learning_migration_required', last_observed_at: null } };
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 30 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const authMarker = 'synthetic-session-only-round130';
function harness(attempt) {
  const requests = [], notices = [], runtimeErrors = [], warnings = [], diagnostics = [], screens = [];
  attempt.observations = { requests, notices, runtimeErrors, warnings, diagnostics, screens };
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl,
    ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api',
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    authSessionEpoch: 1, pageRequestGeneration: 0, operationActionsRequestSeq: 0, operatingMemoryRequestSeq: 0, pageLoadRequests: new Map(),
    currentPage: Vue.ref('ops-track'), filterReportHotel: Vue.ref('7'), token: Vue.ref(authMarker),
    authContext: Vue.ref({ tenantId: 70, hotelId: 7, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 7, name: 'Synthetic hotel', tenant_id: 70 }]), user: Vue.ref({ id: 901, realname: 'Synthetic employee', is_super_admin: true }),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), operationYesterday: '2026-09-14', shanghaiBusinessYesterday: '2026-09-14',
    operationFilters: Vue.ref({ hotel_id: '7' }), operationExecutionStageFilter: Vue.ref(''), operationExecutionViewMode: Vue.ref('all'),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }), operatingGoalInterventionLoading: Vue.ref(false),
    operatingGoalInterventionError: Vue.ref(''), operationExecutionFlow: Vue.ref(flowBody(1).data), operationActions: Vue.ref([]), operationApprovalConfirmingIntentId: Vue.ref(0), operationActionTrackingRead: Vue.ref({}),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), operatingGoalInterventionOverview: Vue.ref({}),
    operatingMemoryLoading: Vue.ref(false), operatingMemoryError: Vue.ref(''), operatingMemories: Vue.ref({ list: [], data_status: 'not_loaded' }),
    homeOperatingScheduleError: Vue.ref(''), document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    readRequestCooldown: { check: () => null, record() {} }, normalizeCanonicalPage: value => value,
    operationErrorMessage: error => error?.message || '读取失败', applyHomeOperatingScheduleFlow() {},
    showToast: (message, type = 'success') => notices.push({ message, type }),
    openWorkflowFormDialog() { throw new Error('Unexpected price workflow'); },
    fetch: (url, options) => {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.equal(new Headers(options.headers).get('Authorization'), authMarker, 'only the in-memory marker, never recorded headers');
      const isPost = method === 'POST' && endpoint === '/operation/execution-tasks/11/evidence';
      const isGet = method === 'GET' && ['/operation/execution-tasks/11', '/operation/action-tracking', '/operation/execution-flow',
        '/operation/closure-overview', '/operation/operating-memories', '/operation/goal-intervention-overview'].includes(endpoint);
      assert.ok(isPost || isGet, `Unexpected synthetic ${method} ${endpoint}`);
      assert.equal(Boolean(options.signal), isGet, 'original GET signal and direct POST boundary');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), body: options.body ? JSON.parse(options.body) : null,
          signal: Boolean(options.signal), settled: false, aborted: false,
          resolve(body) { assert.equal(call.settled, false); call.settled = true; call.response = clone(body); resolveRequest(new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })); },
          reject(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown; call.failure = 'synthetic TypeError: Failed to fetch'; rejectRequest(new TypeError('Failed to fetch')); } };
        requests.push(call);
        options.signal?.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; rejectRequest(new DOMException('Aborted', 'AbortError')); } });
        if (endpoint === '/operation/operating-memories') call.resolve({ code: 200, message: 'success', data: missingMemories });
        else if (endpoint === '/operation/goal-intervention-overview') call.resolve({ code: 200, message: 'success', data: missingGoals });
        else if (['/operation/action-tracking', '/operation/closure-overview'].includes(endpoint)) call.reject();
      });
    } };
  const snapshot = () => ({ requests: requests.map(({ resolve, reject, ...c }) => c), notices, runtimeErrors, warnings, diagnostics, screens,
    form: sandbox.api ? clone(sandbox.api.operationEvidenceForm.value) : null,
    modal_open: sandbox.api?.operationEvidenceModalOpen.value, saving: sandbox.api?.operationEvidenceSaving.value,
    flow: clone(sandbox.operationExecutionFlow.value), error: clone(sandbox.operationError.value) });
  attempt.capture = snapshot;
  attempt.cleanup = async () => { for (const call of requests.filter(c => !c.settled)) call.reject(true); await tick(); };
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  sandbox.operationExecutionItems = Vue.computed(() => sandbox.operationExecutionFlow.value?.list || []);
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.ensureOperationStaticReady = sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  vm.runInContext(requestSource + '\n' + methods + '\nglobalThis.api={operationEvidenceModalOpen,operationEvidenceSaving,operationEvidenceModalItem,operationEvidenceForm,operationEvidenceBoundaryText,recordOperationExecutionEvidence,closeOperationEvidenceModal,submitOperationExecutionEvidence,loadOperationActions,operationExecutionFilteredItems,nodeText};', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.api,
    operationCanExecuteWithEvidence: sandbox.window.SUXI_OPERATION_STATIC.operationCanExecuteWithEvidence,
    operationExecutionStatusLabel: sandbox.appSystemStatic.operationExecutionStatusLabel, operationExecutionRowClass: () => '' });
  async function inspect(kind = 'ops') {
    let tree; const app = Vue.createSSRApp({ render() { tree = (kind === 'ops' ? opsRender : onlineRender)(state, []); return tree; } });
    app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => runtimeErrors.push(error.message);
    const html = await renderToString(app), nodes = [];
    const walk = (n, parents = []) => { if (Array.isArray(n)) return n.forEach(c => walk(c, parents)); if (!n || typeof n !== 'object') return;
      nodes.push({ node: n, parents }); walk(n.children, parents.concat(n)); }; walk(tree);
    return { html, nodes };
  }
  const usable = entry => { assert.ok(entry, 'original control exists'); for (const n of entry.parents.concat(entry.node)) {
    assert.ok(!n.props?.disabled && !n.props?.readonly && n.props?.style?.display !== 'none', 'original visible enabled ancestors');
    if (n.type === 'details') assert.ok(n.props?.open !== undefined && n.props.open !== false, 'details must be open');
  } };
  const button = async label => (await inspect()).nodes.find(({ node }) => node.type === 'button' && String(node.children).includes(label));
  async function click(label) { const entry = await button(label); usable(entry); return { run: entry.node.props.onClick() }; }
  async function edit(field, value) { const entry = (await inspect()).nodes.find(({ node }) => node.props?.['onUpdate:modelValue']?.toString().includes('operationEvidenceForm.' + field));
    usable(entry); entry.node.props['onUpdate:modelValue'](value); await tick(); }
  async function screen(label) { const view = await inspect(); screens.push({ label, html: view.html }); return view; }
  return { sandbox, state, requests, notices, inspect, click, edit, screen, snapshot };
}

if (!process.argv.includes('--prepare-only')) {
await test('executed task supplements evidence without offering or submitting a failed terminal status', async () => {
  const attempt = { name: 'original 17 supplement flow plus 35 definition compatibility' }; attempts.push(attempt); let p;
  try {
    p = harness(attempt); await p.screen('original executed task');
    await (await p.click('录证据')).run; await tick();
    for (const kind of ['ops', 'online']) {
      const view = await p.inspect(kind), status = view.nodes.find(({ node }) => node.type === 'select');
      assert.ok(status?.node.props.disabled, kind + ': original supplement status is read-only');
      assert.equal(view.nodes.some(({ node }) => node.type === 'option' && node.props?.value === 'failed'), false, kind + ': failed is absent');
      assert.match(view.html, /该任务已执行，本次仅补充证据，不修改执行结果。/);
    }
    await p.screen('supplement status is explicit');
    // Defensive stale-form input, not an event sent to the disabled status select.
    p.state.operationEvidenceForm.execution_status = 'failed'; p.state.operationEvidenceForm.failure_reason = 'Synthetic stale failure';
    await (await p.click('保存执行证据')).run;
    assert.equal(p.requests.length, 0); assert.equal(p.state.operationEvidenceForm.execution_status, 'failed');
    assert.equal(p.state.operationEvidenceModalOpen, true); assert.equal(p.state.operationEvidenceSaving, false);
    assert.deepEqual(p.notices.at(-1), { type: 'error', message: '该任务已执行，本次仅可补充证据，不能改为执行失败。' });
    // Original cancellation/reopening restores the valid initial form, no hidden input update.
    await (await p.click('取消')).run; await (await p.click('录证据')).run;
    assert.equal(p.state.operationEvidenceForm.execution_status, 'executed');
    await p.edit('completed_action', 'Synthetic additional manual evidence');
    await p.edit('executed_at', '2026-09-15T10:30'); await p.edit('next_review_date', '2026-09-16');
    const { run } = await p.click('保存执行证据');
    await until(() => p.requests.some(c => c.method === 'POST'), 'original supplement POST');
    const post = p.requests.find(c => c.method === 'POST');
    assert.deepEqual(post.body, { status: 'executed', evidence_type: 'manual_operation_execution', evidence: {
      before: {}, after: {}, attachment_path: '', platform_response: { mode: 'manual_operation_execution', scope: 'ota_channel_operation',
        execution_status: 'executed', completed_action: 'Synthetic additional manual evidence', expected_metric: 'conversion_rate',
        executed_by: 'Synthetic employee', executed_at: '2026-09-15 10:30:00', next_review_date: '2026-09-16',
        effect_status: 'pending_observation', evidence_boundary: 'local_manual_evidence_no_ota_write' }, remark: 'Synthetic additional manual evidence' },
      system_hotel_id: '7', tenant_id: '70', platform: 'all' });
    const task = persistedTask(post.body), responseTask = { ...clone(task), evidence_write: { evidence_id: 41, created: true, replayed: false, fingerprint: fingerprint(task.evidence[0]) } };
    post.resolve({ code: 200, message: 'success', data: responseTask });
    await until(() => p.requests.some(c => c.endpoint === '/operation/execution-tasks/11'), 'original exact task GET');
    const exact = p.requests.find(c => c.endpoint === '/operation/execution-tasks/11');
    assert.equal(exact.query.hotel_id, '7'); assert.equal(p.state.operationEvidenceModalOpen, true);
    assert.equal(p.state.operationEvidenceSaving, true); assert.equal(p.notices.filter(n => n.type === 'success').length, 0);
    exact.resolve({ code: 200, message: 'success', data: task });
    await until(() => p.requests.some(c => c.endpoint === '/operation/execution-flow'), 'original row refresh');
    const flow = p.requests.find(c => c.endpoint === '/operation/execution-flow'); assert.equal(flow.query.hotel_id, '7'); assert.equal(flow.query.system_hotel_id, '7');
    flow.resolve(flowBody(2)); await run; await tick();
    const view = await p.screen('exact executed task and appended evidence after refresh');
    assert.equal(p.state.operationEvidenceModalOpen, false); assert.equal(p.state.operationEvidenceSaving, false);
    assert.match(view.html, /行动 #111 · 任务 #11 · ctrip · 2026-09-15/); assert.match(view.html, /已执行/); assert.match(view.html, /执行证据 2 条/);
    assert.equal(p.sandbox.operationExecutionFlow.value.list[0].execution.status, 'executed');
    assert.equal(p.sandbox.operationExecutionFlow.value.list[0].evidence_summary.count, 2);
    assert.match(view.html, /策略追踪加载失败/);
    assert.equal(p.sandbox.operationError.value.actions, '策略追踪加载失败'); // Original advanced-tools details remains collapsed; markup is not visibility proof.
    assert.equal(p.sandbox.operatingMemories.value.data_status, 'migration_required');
    assert.equal(p.sandbox.operatingGoalInterventionOverview.value.status, 'migration_required');
    assert.deepEqual(p.notices.filter(n => n.type === 'success').map(n => n.message), ['已保存运营动作证据；效果保持待观察，不自动生成收入或ROI']);
    assert.equal(p.requests.filter(c => c.method === 'POST').length, 1); assert.equal(p.requests.filter(c => c.method === 'GET').length, 6);
    assert.ok(p.requests.every(c => c.settled && !c.aborted && !c.teardown_only));
    const final = p.snapshot(); assert.deepEqual(final.runtimeErrors, []); assert.deepEqual(final.warnings, []);
    assert.equal(final.diagnostics.length, 2);
    assert.ok(final.diagnostics.every(d => d[0] === 'API请求失败:' && d[2] === 'Failed to fetch'
      && ['/operation/action-tracking?', '/operation/closure-overview?'].some(prefix => d[1].startsWith(prefix))));
    attempt.pass = true;
  } catch (error) { attempt.error = error.stack; throw error; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.cleanup) await attempt.cleanup();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.cleanup; }
});
}
const evidence = { source_reads: reads, source_sections: sections, template_paths: templatePaths, attempts,
  boundary: 'One original 17 SSR/VNode control flow; 35 modal-definition compatibility only. Synthetic session and 1 evidence POST plus 6 original GETs; no native browser, real DB, OTA/PMS, or approval. Adjunct errors remain in original state/markup/toast; advanced-tools details is collapsed, not claimed visible.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
console.log(JSON.stringify({ source_reads: reads, attempts: attempts.map(a => ({ name: a.name, pass: !!a.pass, error: a.error, requests: a.before_teardown?.requests.length })) }));
