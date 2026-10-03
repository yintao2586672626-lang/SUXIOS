import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

// Default readers are canonical; explicit roots only map a frozen red/green candidate.
const sourceRoot = process.argv.find(a => a.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(a => a.startsWith('--evidence='))?.slice(11);
const reads = [], sections = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = path => { const mapped = sourceRoot && resolve(sourceRoot, path), actual = mapped && existsSync(mapped) ? mapped : resolve(path);
  const code = readFileSync(actual, 'utf8'); reads.push({ path, resolved_path: actual, sha256: hash(code) }); return code; };
const raw = { main: read('public/app-main.js'), operation: read('public/operation-static.js'), system: read('public/system-static.js'),
  full: read('public/components/system/app-main-components.js'), loader: read('public/components/system/app-main-components-loader.js'),
  template: read('resources/frontend/templates/fragments/17-page-ops-track.html') };
const main = raw.main.replaceAll('\r\n', '\n');
const cut = (source, start, end, optional = false) => { const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  if (optional && a < 0) return ''; assert.ok(a >= 0 && b > a, start); const code = source.slice(a, b); sections.push({ start, end, sha256: hash(code) }); return code; };
const section = (start, end, optional = false) => cut(main, start, end, optional);
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
  section('            const captureOperationEvidenceViewContext =', '            const readOperationExecutionTask =', true),
  section('            const readOperationExecutionTask =', '            const operationExecutionEvidenceCount ='),
].join('\n');
const componentSource = cut(raw.full, '    const OperationExecutionEvidenceViewer =', '    const AiDailyReportHistoryPanel =', true);
const factorySetup = main.match(/^    const appMainComponents = window\.SUXI_APP_MAIN_COMPONENTS\?\.create\?\.\(\{ Vue, h \}\);$/m)?.[0];
const registration = main.match(/^            OperationExecutionEvidenceViewer: appMainComponents\.OperationExecutionEvidenceViewer,$/m)?.[0];
assert.ok(factorySetup, 'original main component factory setup');
const ast = baseParse(raw.template, parserOptions), ancestors = [];
function retain(node, parents = []) {
  if (node.type !== 1) return null;
  if (node.tag === 'operation-execution-evidence-viewer'
      || (node.tag === 'div' && node.loc.source.startsWith('<div class="text-xs text-gray-400">行动 #'))) {
    ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node;
  }
  const children = (node.children || []).map(child => retain(child, parents.concat(node))).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
ast.children = ast.children.map(node => retain(node)).filter(Boolean);
compile(raw.template, { mode: 'function', prefixIdentifiers: true }); // Whole original template syntax, not full mount.
const render = new Function('Vue', compile(ast, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 30 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const clone = value => JSON.parse(JSON.stringify(value));
const marker = 'synthetic-session-only-round133';
function row(id, hotelId) { return { id: id + 100, hotel_id: hotelId, execution: { task_id: id, status: 'executed' },
  recommendation: { source_module: 'manual', platform: 'ctrip', date_start: '2026-09-15', object_type: 'content' },
  evidence_summary: { count: 1, types: ['manual_operation_execution'] } }; }
// Public consumer projection from executionTaskDetail/normalizeExecutionEvidenceRow.
// No DB call, raw response, financial fact, source-verification or approval is invented.
function evidenceRow(taskId, id = 41, change = {}) { return { id, task_id: taskId, tenant_id: '70', evidence_type: 'manual_operation_execution',
  created_by: 901, created_at: '2026-09-15 10:31:00', attachment_path: 'synthetic-image-reference',
  platform_response: { execution_status: 'executed', completed_action: `Synthetic saved action ${taskId}`,
    executed_by: 'Synthetic operator', executed_at: '2026-09-15 10:30:00', platform_receipt_id: 'SYNTHETIC-RECEIPT',
    formal_record_ref: 'SYNTHETIC-RECORD', next_review_date: '2026-09-16', effect_status: 'pending_observation',
    debug_payload: 'DO_NOT_RENDER_PRIVATE_STRUCTURE' }, ...change }; }
function task(id = 11, hotelId = 7, rows = [evidenceRow(id)]) { return { id, intent_id: id + 100, hotel_id: hotelId, tenant_id: '70',
  status: 'executed', operator_id: 901, evidence: clone(rows), execution_evidence: clone(rows) }; }
function host() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, addEventListener() {}, removeEventListener() {} });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: { createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(n); else parent.children.splice(index, 0, n); },
    remove, patchProp: (n, key, previous, next) => { n.props[key] = next; if (key === 'style') n.style = next || {}; },
  } };
}
function harness(attempt) {
  const requests = [], diagnostics = [], runtimeErrors = [], warnings = [], screens = [], bindings = [];
  attempt.observations = { requests, diagnostics, runtimeErrors, warnings, screens, bindings };
  const sandbox = { window: {}, Vue, h: Vue.h, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl, setTimeout, clearTimeout,
    ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, API_BASE: 'https://synthetic.invalid/api',
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    authSessionEpoch: 1, pageRequestGeneration: 0, pageLoadRequests: new Map(), currentPage: Vue.ref('ops-track'), filterReportHotel: Vue.ref('7'),
    token: Vue.ref(marker), authContext: Vue.ref({ tenantId: 70, hotelId: 7, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 70 }]), user: Vue.ref({ id: 901, is_super_admin: true }),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    operationFilters: Vue.ref({ hotel_id: '' }), operationExecutionFilteredItems: Vue.ref([row(11, 7), row(12, 8)]),
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } }, readRequestCooldown: { check: () => null, record() {} }, normalizeCanonicalPage: value => value,
    fetch: (url, options) => {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(method, 'GET'); assert.match(endpoint, /^\/operation\/execution-tasks\/(11|12)$/);
      assert.equal(new Headers(options.headers).get('Authorization'), marker); assert.ok(options.signal, 'original GET AbortSignal');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), signal: true, settled: false, aborted: false,
          resolve(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data);
            resolveRequest(new Response(JSON.stringify({ code: 200, message: 'success', data }), { status: 200, headers: { 'Content-Type': 'application/json' } })); },
          reject(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown; call.failure = 'synthetic transport TypeError'; rejectRequest(new TypeError('Failed to fetch')); } };
        requests.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; rejectRequest(new DOMException('Aborted', 'AbortError')); } });
      });
    } };
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  attempt.capture = () => ({ requests: requests.map(({ resolve, reject, ...r }) => r), diagnostics, runtimeErrors, warnings, screens, bindings });
  attempt.cleanup = async () => { for (const r of requests.filter(r => !r.settled)) r.reject(true); await tick(); };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.loadOperationStatic = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.requireOperationStatic = (api, key) => { assert.equal(typeof api[key], 'function'); return api[key]; };
  vm.runInContext(requestSource + '\n' + methods + '\nglobalThis.api={readOperationExecutionTask,captureOperationEvidenceViewContext:typeof captureOperationEvidenceViewContext===\'function\'?captureOperationEvidenceViewContext:undefined,coordinatorSnapshot:()=>({active:coordinatedGetActiveCount,inflight:coordinatedGetRequests.size,queued:coordinatedGetQueue.length,page_reads:pageLoadRequests.size})};', sandbox);
  // Original full factory is preloaded; the real loader still resolves its lazy key
  // through FULL.create. No script-network/full application mount is claimed.
  vm.runInContext(raw.full, sandbox); vm.runInContext(raw.loader, sandbox);
  vm.runInContext(factorySetup + '\nglobalThis.registeredComponents={' + (registration || '') + '};', sandbox);
  const Viewer = sandbox.registeredComponents.OperationExecutionEvidenceViewer;
  bindings.push({ full_factory: typeof sandbox.window.SUXI_APP_MAIN_COMPONENTS_FULL?.create,
    loader_factory: typeof sandbox.window.SUXI_APP_MAIN_COMPONENTS?.create, main_registration: !!registration,
    registered_lazy_loader: typeof Viewer?.__asyncLoader });
  const memory = host(), renderer = Vue.createRenderer(memory.options);
  const app = renderer.createApp({ components: sandbox.registeredComponents,
    setup: () => ({ currentPage: sandbox.currentPage, authContext: sandbox.authContext, operationFilters: sandbox.operationFilters,
      operationExecutionFilteredItems: sandbox.operationExecutionFilteredItems, operationExecutionRowClass: () => '', ...sandbox.api }), render });
  app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => runtimeErrors.push(error.message);
  const walk = (n, all = []) => { all.push(n); n.children.forEach(c => walk(c, all)); return all; };
  const nodes = () => walk(memory.root), content = n => n.text + n.children.map(content).join('');
  const ancestor = (node, predicate) => { for (let n = node; n; n = n.parent) if (predicate(n)) return n; return null; };
  const control = (id, intentId) => nodes().find(n => n.type === 'button' && n.props['data-testid'] === id
    && (!intentId || Number(ancestor(n, a => a.props['data-operation-execution-intent-id'])?.props['data-operation-execution-intent-id']) === intentId));
  const dialog = () => nodes().find(n => n.props['data-testid'] === 'operation-evidence-view-dialog');
  const click = node => { assert.ok(node, 'original visible control'); for (let a = node; a; a = a.parent) assert.ok(!a.props.disabled && a.style.display !== 'none');
    if (dialog()) assert.ok(ancestor(node, n => n === dialog()), 'cannot click behind the original modal overlay'); return node.props.onClick({ type: 'click' }); };
  const snapshot = () => ({ requests: requests.map(({ resolve, reject, ...r }) => r), diagnostics, runtimeErrors, warnings, screens, bindings,
    coordinator: clone(sandbox.api.coordinatorSnapshot()),
    page: sandbox.currentPage.value, visible_dialogs: nodes().filter(n => n.props['data-testid'] === 'operation-evidence-view-dialog').length, text: content(memory.root) });
  attempt.capture = snapshot; attempt.cleanup = async () => { app.unmount(); for (const r of requests.filter(r => !r.settled)) r.reject(true); await tick(); };
  app.mount(memory.root);
  return { sandbox, requests, diagnostics, warnings, runtimeErrors, nodes, control, dialog, click, snapshot, Viewer,
    text: () => content(memory.root), screen: label => screens.push({ label, text: content(memory.root) }) };
}
async function open(p, intentId = 111) {
  await tick(); const before = p.requests.length;
  if (componentSource) await until(() => p.control('operation-evidence-view', intentId), 'original lazy loader resolves the registered viewer');
  const button = p.control('operation-evidence-view', intentId); assert.ok(button, 'original17 now exposes the evidence view entry');
  assert.match(raw.main, /OperationExecutionEvidenceViewer: appMainComponents\.OperationExecutionEvidenceViewer/);
  assert.match(raw.main, /readOperationExecutionTask, captureOperationEvidenceViewContext,/);
  assert.match(raw.loader, /'OperationExecutionEvidenceViewer'/);
  assert.match(raw.full, /return Object\.freeze\(\{(?:[^}]*,\s*)?\s*OperationExecutionEvidenceViewer\s*(?:,|\})/,
    'the frozen component registry exports the viewer regardless of sibling ordering');
  assert.equal(p.Viewer?.__asyncResolved?.name, 'OperationExecutionEvidenceViewer', 'original FULL.create export resolved through loader');
  const run = p.click(button); await until(() => p.requests.length > before, 'original exact task GET');
  assert.match(p.text(), /正在读取本任务执行证据/); return { run, call: p.requests.at(-1) };
}
async function retry(p) { const before = p.requests.length, run = p.click(p.control('operation-evidence-view-retry'));
  await until(() => p.requests.length > before, 'original retry GET'); assert.doesNotMatch(p.text(), /Synthetic saved action/); return { run, call: p.requests.at(-1) }; }
async function close(p) { p.click(p.control('operation-evidence-view-close')); await tick(); assert.equal(p.dialog(), undefined); }
async function scenario(name, body) { await test(name, async () => { const attempt = { name }; attempts.push(attempt); let p;
  try { p = harness(attempt); await body(p); assert.deepEqual(p.runtimeErrors, []); assert.deepEqual(p.warnings, []);
    const failures = p.requests.filter(r => r.failure === 'synthetic transport TypeError' && !r.teardown_only);
    assert.equal(p.diagnostics.length, failures.length, 'only explicitly controlled transport failures log diagnostics');
    for (const log of p.diagnostics) { assert.equal(log[0], 'API请求失败:'); assert.match(log[1], /^\/operation\/execution-tasks\/11\?/); assert.equal(log[2], 'Failed to fetch'); }
    assert.deepEqual(clone(p.sandbox.api.coordinatorSnapshot()), { active: 0, inflight: 0, queued: 0, page_reads: 0 }, 'original coordinator closed before teardown');
    assert.ok(p.requests.every(r => r.settled && !r.teardown_only)); assert.ok(p.requests.every(r => r.method === 'GET')); attempt.pass = true; }
  catch (error) { attempt.error = error.stack; throw error; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.cleanup) await attempt.cleanup();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.cleanup; } }); }

if (!process.argv.includes('--prepare-only')) {
await scenario('original task row reads its saved manual evidence with explicit source boundary', async p => {
  const { run, call } = await open(p); assert.equal(call.endpoint, '/operation/execution-tasks/11');
  assert.equal(call.query.hotel_id, '7'); assert.equal(call.query.system_hotel_id, '7');
  call.resolve(task(11, 7, [evidenceRow(11), evidenceRow(11, 42, { evidence_type: 'unknown_archived_evidence', platform_response: { completed_action: 'UNKNOWN_TYPE_PRIVATE_BODY' } })]));
  await run; await tick(); p.screen('same task saved evidence');
  for (const value of ['酒店 #7 · 任务 #11 · 行动 #111', '任务口径：ctrip · 2026-09-15', 'Synthetic saved action 11', 'Synthetic operator',
    '2026-09-15 10:30:00', 'SYNTHETIC-RECEIPT', 'SYNTHETIC-RECORD', 'synthetic-image-reference', '2026-09-16', 'unknown_archived_evidence', '此类证据详情暂不支持']) assert.ok(p.text().includes(value), value);
  assert.match(p.text(), /人工记录不等同 OTA 来源事实或经营效果核验/);
  assert.doesNotMatch(p.text(), /DO_NOT_RENDER_PRIVATE_STRUCTURE|UNKNOWN_TYPE_PRIVATE_BODY|pending_observation/);
  assert.equal(p.nodes().some(n => n.type === 'a' || n.type === 'img'), false); assert.equal(p.requests.length, 1);
});
await scenario('legacy aliases and abnormal manual fields remain explicit without rendering objects or inherited labels', async p => {
  const { run, call } = await open(p);
  const rows = ['constructor', '__proto__', { nested: 'UNSUPPORTED_STATUS_BODY' }].map((value, index) => evidenceRow(11, 51 + index,
    { platform_response: { execution_status: value, completed_action: 'Synthetic legacy action' } }));
  rows.push(evidenceRow(11, 54, { platform_response: ['UNSUPPORTED_ARRAY_BODY'] }), evidenceRow(11, 55, { platform_response: 'UNSUPPORTED_SCALAR_BODY' }));
  const old = evidenceRow(11, 56); delete old.platform_response; delete old.created_by; rows.push(old);
  const data = task(11, 7, rows); delete data.execution_evidence; call.resolve(data); await run; await tick(); p.screen('legacy and unsupported formats');
  assert.equal((p.text().match(/原执行结果格式暂不支持/g) || []).length, 3);
  assert.equal((p.text().match(/人工证据详情格式暂不支持/g) || []).length, 2);
  assert.match(p.text(), /未记录/); assert.doesNotMatch(p.text(), /UNSUPPORTED_|function Object|constructor|__proto__/);
  assert.equal(p.requests.length, 1);
});
await scenario('read failure and malformed identities show no old detail; original retry distinguishes valid empty records', async p => {
  let action = await open(p); action.call.reject(); await action.run; await tick();
  assert.match(p.text(), /证据读取失败，未展示旧详情/); assert.doesNotMatch(p.text(), /Failed to fetch|Synthetic saved action/);
  const wrongIntent = task(); wrongIntent.intent_id = 999;
  const wrongRecord = task(); wrongRecord.execution_evidence[0].task_id = 12;
  const absent = task(); delete absent.evidence; delete absent.execution_evidence;
  for (const data of [wrongIntent, wrongRecord, absent]) { action = await retry(p); action.call.resolve(data); await action.run; await tick();
    assert.match(p.text(), /未能确认本任务的执行证据，请重试/); assert.doesNotMatch(p.text(), /Synthetic saved action|暂无可展示/); }
  action = await retry(p); action.call.resolve(task(11, 7, [])); await action.run; await tick();
  assert.match(p.text(), /本任务暂无可展示的执行证据记录/); assert.equal(p.control('operation-evidence-view-retry'), undefined);
  await close(p); action = await open(p); action.call.resolve(task()); await action.run; await tick(); assert.match(p.text(), /Synthetic saved action 11/);
  assert.equal(p.requests.length, 6); p.screen('retry then reopened saved task');
});
await scenario('closing A and opening another permitted hotel preserves B; scope page permission and session changes clear details', async p => {
  const a = await open(p, 111); await close(p); const b = await open(p, 112);
  assert.equal(b.call.query.hotel_id, '8'); b.call.resolve(task(12, 8)); await b.run; await tick();
  a.call.resolve(task(11, 7)); await a.run; await tick(); assert.match(p.text(), /酒店 #8 · 任务 #12 · 行动 #112/);
  assert.match(p.text(), /Synthetic saved action 12/); assert.doesNotMatch(p.text(), /Synthetic saved action 11/); p.screen('B survives late A');
  await close(p); const reopened = await open(p, 112); assert.doesNotMatch(p.text(), /Synthetic saved action 12/);
  reopened.call.resolve(task(12, 8)); await reopened.run; await tick();
  // Direct reactive scope/permission boundary, not a click through the modal overlay.
  p.sandbox.operationFilters.value.hotel_id = '7'; await tick(); assert.equal(p.dialog(), undefined);
  p.sandbox.operationFilters.value.hotel_id = ''; const next = await open(p); next.call.resolve(task()); await next.run; await tick();
  p.sandbox.currentPage.value = 'compass'; await tick(); assert.equal(p.dialog(), undefined); assert.equal(p.control('operation-evidence-view'), undefined);
  p.sandbox.currentPage.value = 'ops-track'; const afterPage = await open(p); afterPage.call.resolve(task()); await afterPage.run; await tick();
  p.sandbox.permittedHotels.value = [{ id: 8, tenant_id: 70 }]; await tick(); assert.equal(p.dialog(), undefined);
  p.sandbox.permittedHotels.value = [{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 70 }]; const final = await open(p);
  // Original capture/current functions observe a synthetic session invalidation;
  // this is a boundary control, not a claim of exercising a login/logout UI.
  p.sandbox.authSessionEpoch++; p.sandbox.token.value = ''; await tick(); assert.equal(p.dialog(), undefined);
  final.call.resolve(task()); await final.run; await tick(); assert.equal(p.dialog(), undefined);
  assert.equal(p.requests.length, 6);
});
}
const result = { source_reads: reads, source_sections: sections, template_ancestors: ancestors, attempts,
  boundary: 'Synthetic original17 controls in a Vue memory renderer, original auth/request/coordinator/GET signals, preloaded full factory then real lazy loader and main registration. No network script loading/full app mount/native browser/real account/DB. Scope/page/permission/session negatives are direct reactive context controls, not the full application navigation/login path. No hidden UI events or writes.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(result, null, 2));
console.log(JSON.stringify({ source_reads: reads, attempts: attempts.map(a => ({ name: a.name, pass: !!a.pass, error: a.error, requests: a.before_teardown?.requests.length })) }));
