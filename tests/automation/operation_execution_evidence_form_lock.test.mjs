import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Contract-shaped synthetic fixture derived from actual pure PHP normalize output; no App/Env/DB.
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
  "minimal_meaningful_evidence": {
    "evidence_type": "manual_operation_execution",
    "created_by": 9,
    "platform_response": {
      "completed_action": "Synthetic completed action only"
    }
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
  },
  "default_redacted_post": {
    "code": 200,
    "data": {
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
      ],
      "evidence_write": {
        "evidence_id": 701,
        "created": false,
        "replayed": true,
        "fingerprint": "22291bd01a0083bb521d463e133256037664ba9c6edeea02ca2fe20142c8abb0"
      }
    },
    "redacted": true,
    "redacted_reason": "protected_capability_summary_only",
    "reference_id": "synthetic-request",
    "protected_capability": "operation_execution",
    "redacted_key_count": 1
  }
};
const paths = {
  main: process.env.OPERATION_EVIDENCE_MAIN_PATH || 'public/app-main.js', operation: 'public/operation-static.js', system: 'public/system-static.js',
  components: 'public/components/system/app-main-components.js',
  ops: (process.env.OPERATION_EVIDENCE_TEMPLATE_ROOT || 'resources/frontend/templates/fragments') + '/17-page-ops-track.html',
  online: (process.env.OPERATION_EVIDENCE_TEMPLATE_ROOT || 'resources/frontend/templates/fragments') + '/35-page-online-data.html',
};
const raw = Object.fromEntries(Object.entries(paths).map(([key, path]) => [key, readFileSync(path, 'utf8')]));
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
  helpers: cut(raw.components, '    const parseOperationEvidenceNumber =', '    const normalizeOperationReviewStatus ='),
  identity: cut(main, '            const operationExecutionHotelId =', '            const collectPriceExecutionIntentFields ='),
  list: cut(main, '            const loadOperationActions = async', '            const parseOperationEvidenceNumber ='),
  modal: cut(main, '            const recordOperationExecutionEvidence = async', '            const recordOperationRoiEvidence ='),
};

parts.runPage = cut(main, '            const runPageLoadOnce =', '            const activateCoreOperationsAfterLogin =');
parts.daily = cut(main, '            let aiDailyReportRequestSeq =', '            const validateAiDailyReportReadback =');
parts.activate = cut(main, '            let suppressNextOpsTrackAutoLoad =', '            watch(currentPage, (newPage) => {');
const actualSavingDeclaration = main.match(/const operationEvidenceSaving = ref\(false\);/);
if (actualSavingDeclaration) parts.saving = actualSavingDeclaration[0];
function templatePieces(template, modalId) {
  const all = [];
  const walk = node => { all.push(node); for (const child of node.children || []) walk(child); };
  walk(parse(template));
  const modal = all.find(node => node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === modalId));
  const entry = all.find(node => node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === 'recordOperationExecutionEvidence(item)'));
  assert.ok(modal && entry);
  const compileRender = text => new Function('Vue', compile(text, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  return { entry: compileRender(entry.loc.source), modal: compileRender(modal.loc.source), entryText: entry.loc.source, modalText: modal.loc.source };
}
const templates = { ops: templatePieces(raw.ops, 'operation-evidence-modal'), online: templatePieces(raw.online, 'core-loop-operation-evidence-modal') };
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const clone = value => JSON.parse(JSON.stringify(value));
let assertions = 0;
const eq = (actual, expected, note) => { assertions++; assert.deepEqual(actual, expected, note); };
const ok = (actual, note) => { assertions++; assert.ok(actual, note); };
const row = (supplement = false, taskId = 11, evidenceCount = supplement ? 1 : 0) => ({
  id: taskId + 100, hotel_id: 7,
  recommendation: { source_module: 'manual', object_type: 'content', expected_metric: 'conversion_rate' },
  execution: { task_id: taskId, hotel_id: 7, status: supplement ? 'executed' : 'pending_execute' },
  next_action: { key: supplement ? 'record_evidence' : 'execute', label: 'synthetic next action' },
  evidence_summary: { count: evidenceCount, types: evidenceCount ? ['manual_operation_execution'] : [] },
});
const stable = value => Array.isArray(value) ? value.map(stable) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(key => [key, stable(value[key])])) : value;
const fingerprint = evidence => createHash('sha256').update(JSON.stringify(stable({
  evidence_type: evidence.evidence_type, before: evidence.before, after: evidence.after,
  attachment_path: evidence.attachment_path.trim(), platform_response: evidence.platform_response, remark: evidence.remark.trim(),
}))).digest('hex');
eq(fingerprint(backendSamples.submitted_normalized_payload), backendSamples.post_evidence_write.fingerprint, 'String/empty-array fixture hash agrees with actual pure PHP canonicalization sample');
function task(payload, supplement = false) {
  const current = { ...clone(backendSamples.get_evidence_row), id: 41, task_id: 11, tenant_id: '70', created_by: 901,
    created_at: '2026-09-15 10:31:00', updated_at: '2026-09-15 10:31:00', ...clone(payload.evidence),
    before: [], after: [], evidence_type: payload.evidence_type };
  const older = { ...clone(current), id: 40, remark: 'older synthetic material',
    platform_response: { ...clone(current.platform_response), completed_action: 'older synthetic material' } };
  const evidence = [ ...(supplement ? [older] : []), current ];
  return { ...clone(backendSamples.get_task_core), id: 11, hotel_id: 7, tenant_id: '70', intent_id: 111,
    operator_id: 901, status: payload.status, evidence, execution_evidence: clone(evidence) };
}

function harness({ supplement = false, page = 'ops-track', taskRead = 'ok', listRead = 'ok', existingEvidenceCount = supplement ? 1 : 0 } = {}) {
  const requests = [], notices = [];
  let persisted, newRows = [row(supplement, 11, existingEvidenceCount), row(false, 12)];
  const sandbox = {
    window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone,
    ref: Vue.ref, nextTick: Vue.nextTick, Date, setTimeout, clearTimeout,
    console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 1, currentPage: Vue.ref(page), filterReportHotel: Vue.ref('7'), aiDailyReportTaskReturn: Vue.ref(null),
    authContext: Vue.ref({ hotelId: '7', tenantId: '70', platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 901, realname: 'Synthetic employee', is_super_admin: true }), token: Vue.ref(''),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 80 }]),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), operationYesterday: '2026-09-14', shanghaiBusinessYesterday: '2026-09-14',
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }),
    isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    operationActionsRequestSeq: 0, operationApprovalConfirmingIntentId: Vue.ref(0), operationFilters: Vue.ref({ hotel_id: '7' }),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }),
    operatingGoalInterventionLoading: Vue.ref(false), operatingGoalInterventionError: Vue.ref(''),
    operationExecutionViewMode: Vue.ref('all'), operationExecutionFlow: Vue.ref({ list: newRows, data_status: 'ok' }),
    operationActions: Vue.ref([]), operationActionTrackingRead: Vue.ref({}), operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}),
    operatingGoalInterventionOverview: Vue.ref({}), homeOperatingScheduleError: Vue.ref(''),
    operationEvidenceModalOpen: Vue.ref(false), operationEvidenceModalItem: Vue.ref(null), operationEvidenceForm: Vue.ref({}),
    operationEvidenceBoundaryText: '执行证据与效果证据分开保存：本窗口不会接收执行前后收入、成本或 ROI。',
    showToast: (message, type = 'success') => notices.push({ message, type }),
    operationErrorMessage: (error, fallback) => error.message || fallback,
    ensureOperationStaticReady: async () => {}, normalizeOperationHotelSelection: form => form.value.hotel_id,
    loadOperatingMemories: async () => {}, applyHomeOperatingScheduleFlow() {},
    openWorkflowFormDialog() { throw new Error('Price dialog is outside this non-price probe'); },
    fetch: (url, options) => {
      assert.ok(url.startsWith('https://synthetic.invalid/api/operation/'), 'synthetic operation paths only');
      const request = { url, options };
      requests.push(request);
      if (options.method === 'POST') return new Promise((resolve, reject) => Object.assign(request, { resolve, reject }));
      const pathname = new URL(url).pathname;
      let status = 200, data = { code: 200, data: {} };
      if (/\/execution-tasks\/11$/.test(pathname)) {
        if (taskRead === '500') { status = 500; data = { code: 500, message: 'Synthetic exact read failed' }; }
        else data.data = { ...clone(persisted), ...(taskRead === 'wrong-hotel' ? { hotel_id: 8 } : {}), ...(taskRead === 'wrong-id' ? { id: 12 } : {}) };
      } else if (listRead === '500' && persisted) { status = 500; data = { code: 500, message: 'Synthetic list read failed' }; }
      else if (/\/execution-flow$/.test(pathname)) data.data = { capabilities: { hotel_id: 7 }, summary: {}, stages: [], list: newRows, data_gaps: [], data_status: 'ok', returned_count: newRows.length, matched_total: newRows.length, truncated: false, statistics: { execution_total_loaded: true } };
      else if (/\/action-tracking$/.test(pathname)) data.data = { actions: [], returned_count: 0, matched_total: 0, truncated: false, data_gaps: [], data_status: 'ok', effect_validation: { status: 'data_gap', metrics: [], data_gaps: [], action_counts: {} } };
      else if (/\/goal-intervention-overview$/.test(pathname)) data.data = { hotel_id: 7, data_status: 'no_data' };
      else if (/\/closure-overview$/.test(pathname)) data.data = { summary: {}, modules: [], data_gaps: [], data_status: 'data_gap' };
      else throw new Error('Unexpected synthetic read: ' + pathname);
      request.response = clone(data);
      return Promise.resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
    },
  };

  Object.assign(sandbox, {
    operationEvidenceSaving: Vue.ref(false), watch: Vue.watch, pendingOperationNavigation: null, pageLoadRequests: new Map(),
    PAGE_LOAD_DEDUP_MS: 500, lastLoadedPage: '', lastLoadedPageAt: 0,
    aiDailyReportGenerationTaskPolling: Vue.ref(false), aiDailyReportForm: Vue.ref({ hotel_id: '7', report_date: '2026-09-15' }),
    aiDailyReport: Vue.ref(null), ensureRevenueAiStaticReady: async () => {}, loadAiDailyFactGate: async () => {},
  });
  vm.createContext(sandbox);
  vm.runInContext(raw.system + '\n' + raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function', key); return api[key]; };
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  vm.runInContext(Object.values(parts).join('\n') + '\nglobalThis.exposed = { operationEvidenceSaving, recordOperationExecutionEvidence, closeOperationEvidenceModal, submitOperationExecutionEvidence, loadOperationActions };', sandbox);
  const state = Vue.proxyRefs({ ...sandbox, ...sandbox.exposed, item: newRows[0], coreOperationsCanExecute: true,
    operationCanExecuteWithEvidence: sandbox.window.SUXI_OPERATION_STATIC.operationCanExecuteWithEvidence });
  const inspect = async (kind = 'modal', template = 'ops') => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = templates[template][kind](state, []); return tree; } });
    app.config.warnHandler = () => {};
    const html = await renderToString(app), nodes = [];
    const walk = node => { if (Array.isArray(node)) return node.forEach(walk); if (!node || typeof node !== 'object') return; nodes.push(node); walk(node.children); };
    walk(tree);
    return { html, nodes };
  };
  const clickEntry = async (template = 'ops') => {
    const node = (await inspect('entry', template)).nodes.find(n => n.type === 'button');
    ok(node && !node.props?.disabled, 'Actual original entry is enabled');
    await node.props.onClick(); await tick();
  };
  const edit = async (field, value) => {
    const node = (await inspect()).nodes.find(n => n.props?.['onUpdate:modelValue']?.toString().includes('operationEvidenceForm.' + field));
    ok(node && !node.props?.disabled && !node.props?.readonly, field + ' has an editable original compiled model binding');
    node.props['onUpdate:modelValue'](value); await tick();
  };
  const clickSave = async () => {
    const node = (await inspect()).nodes.find(n => n.type === 'button' && String(n.children).includes('保存执行证据'));
    ok(node && !node.props?.disabled, 'Actual save button is enabled');
    const pending = node.props.onClick(); await tick(); return { pending };
  };
  const reply = (status = 200, { replayed = false, evidenceId = 41 } = {}) => {
    const request = requests.find(r => r.resolve && !r.settled), payload = JSON.parse(request.options.body);
    request.settled = true;
    if (status === 200) {
      if (!replayed || !persisted) persisted = task(payload, supplement);
      if (!replayed) {
        newRows = [ { ...row(supplement), execution: { task_id: 11, hotel_id: 7, status: payload.status }, next_action: { key: 'wait_for_review' } }, row(false, 12) ];
      }
    }
    const receipt = status === 200 ? { ...clone(persisted), execution_evidence: clone(persisted.evidence), ...(supplement ? { evidence_write: { evidence_id: evidenceId, created: !replayed, replayed, fingerprint: fingerprint(persisted.evidence.at(-1)) } } : {}) } : null;
    const data = status === 200 ? { code: 200, data: receipt } : { code: status, message: 'Synthetic save rejected or failed' };
    request.response = clone(data);
    request.resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
  };
  return { state, sandbox, requests, notices, inspect, clickEntry, edit, clickSave, reply, setTaskRead: value => { taskRead = value; } };
}

async function openAndFill(p) {
  await p.clickEntry();
  await p.edit('completed_action', 'Synthetic action A');
  await p.edit('executed_at', '2026-09-15T10:30');
  await p.edit('next_review_date', '2026-09-16');
}
async function checkControls(p, disabled, count = 8, template = 'ops') {
  const view = await p.inspect('modal', template);
  const inputs = view.nodes.filter(n => ['select', 'input', 'textarea'].includes(n.type));
  eq(inputs.length, count);
  for (const input of inputs) eq(Boolean(input.props?.disabled), disabled, 'original visible input lock');
  for (const button of view.nodes.filter(n => n.type === 'button')) eq(Boolean(button.props?.disabled), disabled, 'footer lock');
  if (disabled) ok(view.html.includes('保存中...'));
  return view;
}
test('actual main setup exports the saving ref consumed by both templates', () => {
  const start = main.lastIndexOf('\n            return {');
  ok(start > 0, 'actual setup return boundary');
  ok(/operationEvidenceModalOpen,\s*operationEvidenceSaving,\s*operationEvidenceForm,/.test(main.slice(start)), 'actual setup export, not only the fixture expose');
});
for (const [kind, status] of [['execute', 'executed'], ['execute', 'failed'], ['supplement', 'executed']]) {
  test(kind + ' ' + status + ': all visible fields lock through real POST and exact GET', async () => {
    const p = harness({ supplement: kind === 'supplement' }); await openAndFill(p);
    if (status === 'failed') { await p.edit('execution_status', 'failed'); await p.edit('failure_reason', 'Synthetic failure A'); }
    const { pending } = await p.clickSave();
    try {
      eq(p.state.operationEvidenceSaving, true);
      await checkControls(p, true, status === 'failed' ? 9 : 8);
      const before = clone(p.state.operationEvidenceForm);
      await p.state.submitOperationExecutionEvidence(); // defensive duplicate guard, not a simulated enabled UI click
      eq(p.requests.filter(r => r.options.method === 'POST').length, 1);
      p.state.closeOperationEvidenceModal();
      eq(p.state.operationEvidenceModalOpen, true);
      await p.state.recordOperationExecutionEvidence(row(false, 12)); // defensive target guard
      eq(p.state.operationEvidenceModalItem.execution.task_id, 11);
      eq(clone(p.state.operationEvidenceForm), before);
    } finally { p.reply(); await pending; }
    eq(p.state.operationEvidenceSaving, false); eq(p.state.operationEvidenceModalOpen, false);
    ok(p.notices.some(n => n.type === 'success'));
    const post = p.requests.find(r => r.options.method === 'POST');
    ok(post.url.includes(kind === 'supplement' ? '/evidence' : '/execute'));
    eq(post.response.data.status, status);
    const exact = p.requests.find(r => /\/execution-tasks\/11\?/.test(r.url));
    eq(exact.response.data.evidence.at(-1).platform_response.completed_action, 'Synthetic action A');
  });
}
for (const failure of ['422', '500', 'transport', 'exact-500', 'wrong-hotel']) {
  test(failure + ': original draft and editor become available after failure', async () => {
    const p = harness({ taskRead: failure === 'exact-500' ? '500' : failure }); await openAndFill(p);
    const original = clone(p.state.operationEvidenceForm), { pending } = await p.clickSave();
    try { await checkControls(p, true); }
    finally {
      if (failure === 'transport') p.requests.find(r => r.reject).reject(new TypeError('Synthetic transport failure'));
      else p.reply(failure === '422' ? 422 : failure === '500' ? 500 : 200);
      await pending;
    }
    eq(p.state.operationEvidenceSaving, false); eq(p.state.operationEvidenceModalOpen, true);
    eq(clone(p.state.operationEvidenceForm), original);
    await checkControls(p, false);
    await p.edit('completed_action', 'Synthetic editable after failure');
    eq(p.state.operationEvidenceForm.completed_action, 'Synthetic editable after failure');
    ok(p.notices.some(n => n.type === 'error'));
    eq(p.notices.filter(n => n.type === 'success').length, 0);
  });
}
test('idempotent supplemental evidence replay remains successful when exact readback already contains it', async () => {
  const p = harness({ supplement: true, existingEvidenceCount: 2 }); await openAndFill(p);
  const { pending } = await p.clickSave(); p.reply(200, { replayed: true }); await pending;
  eq(p.state.operationEvidenceModalOpen, false);
  eq(p.state.operationEvidenceModalItem, null);
  ok(p.notices.some(n => n.type === 'success'));
  eq(p.notices.some(n => n.type === 'error'), false);
});
test('supplemental evidence replay without the exact evidence ID in readback remains unresolved', async () => {
  const p = harness({ supplement: true, existingEvidenceCount: 2 }); await openAndFill(p);
  const { pending } = await p.clickSave(); p.reply(200, { replayed: true, evidenceId: 42 }); await pending;
  eq(p.state.operationEvidenceModalOpen, true);
  eq(p.state.operationEvidenceModalItem.execution.task_id, 11);
  eq(p.state.operationEvidenceSaving, false);
  ok(p.notices.some(n => n.type === 'error'));
  eq(p.notices.some(n => n.type === 'success'), false);
});
test('both original modal definitions lock all nine fields using the save owner', async () => {
  const p = harness(); await openAndFill(p);
  await p.edit('execution_status', 'failed'); await p.edit('failure_reason', 'Synthetic failure A');
  const { pending } = await p.clickSave();
  try {
    await checkControls(p, true, 9, 'ops');
    // Only template-definition compatibility: the actual online entry redirects to ops-track.
    await checkControls(p, true, 9, 'online');
  } finally { p.reply(422); await pending; }
  await checkControls(p, false, 9, 'ops'); await checkControls(p, false, 9, 'online');
});
test('initial parallel daily GET cannot release a later visible modal save', async () => {
  const p = harness({ page: 'online-data' });
  let resolveDaily;
  const originalFetch = p.sandbox.fetch;
  p.sandbox.fetch = (url, options) => {
    if (url.startsWith('https://synthetic.invalid/api/ai-daily-reports/latest?')) {
      p.requests.push({ url, options });
      return new Promise(resolve => { resolveDaily = () => resolve(new Response(JSON.stringify({ code: 200, data: { report: null } }), { status: 200, headers: { 'Content-Type': 'application/json' } })); });
    }
    return originalFetch(url, options);
  };
  const actualOpsWatchBranch = cut(main, "                if (newPage === 'ops-track') {", "                if (newPage === 'operating-growth-archive') {");
  const effects = Vue.effectScope();
  effects.run(() => vm.runInContext('watch(currentPage, (newPage) => {\n' + actualOpsWatchBranch + '\n});', p.sandbox));
  let pending;
  try {
    await p.clickEntry('online');
    eq(p.state.currentPage, 'ops-track'); eq(p.state.operationEvidenceModalOpen, false);
    vm.runInContext('void loadAiDailyReport();', p.sandbox); await tick();
    ok(resolveDaily); eq(p.state.operationLoading.aiDailyReport, true);
    eq(p.state.operationLoading.actions, false); eq(p.state.operationExecutionFlow.list.length, 2);
    await openAndFill(p);
    ({ pending } = await p.clickSave());
    eq(p.state.operationEvidenceSaving, true);
    resolveDaily(); await tick(); await tick();
    eq(p.requests.find(r => r.options.method === 'POST').settled, undefined, 'POST remains pending');
    eq(p.state.operationLoading.actions, false, 'Original activation released only its shared flag');
    eq(p.state.operationEvidenceSaving, true, 'Independent save owner stays busy');
    await checkControls(p, true);
    await p.state.submitOperationExecutionEvidence();
    p.state.closeOperationEvidenceModal();
    eq(p.requests.filter(r => r.options.method === 'POST').length, 1); eq(p.state.operationEvidenceModalOpen, true);
    eq(p.state.operationEvidenceForm.completed_action, 'Synthetic action A');
  } finally {
    resolveDaily?.();
    if (pending) { p.reply(); await pending; }
    effects.stop();
  }
  eq(p.state.operationEvidenceSaving, false); eq(p.state.operationEvidenceModalOpen, false);
  ok(p.notices.some(n => n.type === 'success'));
});
after(() => console.log('Synthetic scoped assertions:', assertions));
