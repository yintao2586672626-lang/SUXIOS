import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const root = path.resolve(option('source-root') || repository);
const readers = [], sections = [], ancestors = [], attempts = [];
const sha = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = file => { const resolved = path.join(root, file), bytes = fs.readFileSync(resolved);
  readers.push({ path: file, resolved_path: resolved, sha256: sha(bytes) }); return bytes.toString('utf8'); };
const raw = { main: read('public/app-main.js'), simulation: read('public/simulation-static.js'), system: read('public/system-static.js'),
  components: read('public/components/system/app-main-components.js'), template: read('resources/frontend/templates/fragments/02-page-ai-simulation.html') };
const main = raw.main.replaceAll('\r\n', '\n');
function cut(text, start, end) {
  const a = text.indexOf(start), b = text.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start);
  const code = text.slice(a, b); sections.push({ start, end, sha256: sha(code) }); return code;
}
const part = (start, end) => cut(main, start, end);
const decl = name => { const start = `            const ${name} =`, a = main.indexOf(start); assert.ok(a >= 0, name);
  const next = /\n            (?:const|let) /.exec(main.slice(a + start.length)); assert.ok(next, name);
  const code = main.slice(a, a + start.length + next.index); sections.push({ declaration: name, sha256: sha(code) }); return code; };
const archiveStart = main.includes('            const simulationArchivePending =')
  ? '            const simulationArchivePending =' : '            const archiveSimulationRecord =';
const source = [
  part('    const requireAppSystemStatic =', '    const requireUserAdminStatic ='), decl('permittedHotels'),
  part('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  part('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  part('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  part('            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  part('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  part('            const request = async (', '            const apiRequest = request;'),
  decl('normalizeCanonicalPage'), decl('isCompassDataPage'),
  part('            const toast =', '            const createWorkflowFormDialogState ='),
  part('            const simulationStaticScript =', '            const defaultSimulationInput ='),
  decl('aiSimulationRecords'), decl('simulationHistoryState'), decl('aiSimulationRecordId'), decl('simulationDraft'),
  part('            function saveSimulationInputOnly(', '            function loadSimulationState('),
  part('            function refreshSimulationState(', '            const clearSimulationRecordContext ='),
  decl('clearSimulationRecordContext'),
  part("            watch([currentPage, token], () => { simulationHistoryState.value", '            let simulationDetailRequestId'),
  part(archiveStart, '            const simulationRecordSummary ='),
  decl('simulationRecordSummary'), decl('invalidateSimulationCalculation'),
  part('            watch([aiSimulationParams, () => simulationDraft.value.name]', '            const baseSimulation = computed'),
].join('\n');
const heroSource = cut(raw.components, '    const SimulationHeroActions = {', '        return Object.freeze({');
const attr = (node, name) => node.props?.find(p => p.type === 6 && p.name === name)?.value?.content;
const ast = baseParse(raw.template, parserOptions);
function retain(node, parents = []) {
  const target = node.type === 1 && (node.tag === 'simulation-hero-actions'
    || ['simulation-record-context', 'simulation-history-pagination'].includes(attr(node, 'data-testid'))
    || node.loc.source.startsWith('<button v-if="canArchiveSim(record)"')
    || node.loc.source.startsWith('<div class="font-medium text-gray-900">{{ record.project_name')
    || node.loc.source.startsWith('<div class="text-xs text-gray-500 mt-1">{{ simulationRecordSummary(record) }}'));
  if (target) { ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
  const children = (node.children || []).map(child => retain(child, node.type === 1 ? parents.concat(node) : parents)).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
compile(raw.template, { mode: 'function', prefixIdentifiers: true });
const render = new Function('Vue', compile(retain(ast), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
if (option('prepare-only') === 'true') {
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestors, compile: 'PASS', behavior_runs: 0 }, null, 2) + '\n');
  console.log('prepare-only: full and retained original templates compiled; no application execution');
} else {
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 40 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const marker = 'synthetic-memory-session-round142';
const rows = () => [103, 102, 101].map(id => ({ id, project_name: `已存方案 ${id}`, created_at: '2026-09-19 10:00:00', risk_level: '',
  summary: { monthlyRevenue: null, monthlyNetCashflow: null, paybackMonths: null, operatingScenario: null },
  truth_context: { hotel_id: id === 101 ? 80 : 81, tenant_id: 9, status: 'unverified', metric_scope: 'investment_scenario',
    platforms: ['not_applicable'], source_methods: ['user_input', 'deterministic_formula'], persistence: { stored: true, readback_verified: true } },
  access_policy: { mode: 'hotel_scoped', mutation_allowed: null, reason_code: 'hotel_capability_required', hotel_binding_required: false } }));
const page = list => ({ list, pagination: { page_size: 30, returned_count: list.length, has_more: false, next_before_id: null } });

function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, style: {}, children: [], parent: null });
  const root = node('root');
  const remove = n => { if (n.parent) { n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; } };
  return { root, options: { createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1;
      if (at < 0) parent.children.push(n); else parent.children.splice(at, 0, n); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; if (key === 'style') n.style = value || {}; } } };
}

function harness(attempt) {
  const calls = [], confirmations = [], errors = [], warnings = [], diagnostics = [], screens = [], timers = [], memory = new Map();
  const scope = Vue.effectScope(); let confirmAnswer = true;
  const sandbox = { ...Vue, window: { Vue }, URL, URLSearchParams, Headers, FormData, Response, Date, Intl, structuredClone,
    AbortController, DOMException, clearTimeout, API_BASE: 'https://synthetic.invalid/api',
    setTimeout(fn, delay) { const timer = setTimeout(fn, delay); timers.push(timer); return timer; },
    token: Vue.ref(marker), authSessionEpoch: 1, pageRequestGeneration: 1, currentPage: Vue.ref('ai-simulation'),
    authContext: Vue.ref({ tenantId: 9, hotelId: 80, platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 142, tenant_id: 9, hotel_id: 80, is_super_admin: true }),
    cachedPermittedHotels: [{ id: 80, tenant_id: 9, name: '合成门店 A' }, { id: 81, tenant_id: 9, name: '合成门店 B' }],
    operationHotelOptions: Vue.ref([{ id: 80, name: '合成门店 A' }, { id: 81, name: '合成门店 B' }]),
    filterReportHotel: Vue.ref('80'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    shanghaiToday: () => '2026-09-20',
    aiSimulationParams: Vue.ref({ hotel_id: 80, roomCount: 97, monthlyRent: 22222, operatingScenario: null }),
    aiSimulationResult: Vue.ref({ monthlyNetCashflow: 9876 }), aiSimulationScenarios: Vue.ref([]), aiSimulationLoading: Vue.ref(false),
    simulationRiskHints: Vue.ref([]), simulationModelAnalysis: Vue.ref(null),
    suppressSimulationAutoRefresh: true, simulationDetailRequestId: 0, simulationCalculationRequestId: 0,
    simulationHotelSelectionValid: Vue.computed(() => true),
    handleSimulation: () => assert.fail('calculation is outside this archive test'),
    managerCapabilityRequest: () => assert.fail('commission panel is outside this archive test'),
    getHotelNameById: id => ({ 80: '合成门店 A', 81: '合成门店 B' }[id] || ''),
    formatCurrency: value => value == null ? '--' : String(value),
    console: { error: (...args) => diagnostics.push(args.map(value => value?.message || String(value))), warn: (...args) => warnings.push(args.map(String)) },
    confirm(message) { confirmations.push({ message, answer: confirmAnswer }); return confirmAnswer; },
    hydrateSimulationStaticDefaults: () => assert.fail('archiving must not hydrate or edit the current simulation'),
    localStorage: { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, String(value)), removeItem: key => memory.delete(key) },
    document: { createElement() { assert.fail('visible history already loaded the module'); } },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok(method === 'GET' && endpoint === '/simulation/records' || method === 'DELETE' && /^\/simulation\/records\/(101|102|103)$/.test(endpoint));
      assert.equal(new Headers(options.headers).get('Authorization'), sandbox.token.value);
      if (method === 'GET') assert.ok(options.signal, 'original coordinator GET signal'); else assert.equal(options.signal, undefined, 'original DELETE has no coordinator signal');
      return new Promise((resolve, reject) => {
        const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), settled: false, aborted: false,
          reply(data) { assert.equal(call.settled, false); call.settled = true; call.data = clone(data);
            resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', data, time: 1790000000 }), { status: 200 })); },
          fail(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown;
            call.failure = 'controlled TypeError'; reject(new TypeError('Failed to fetch')); } };
        calls.push(call); options.signal?.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Aborted', 'AbortError')); } });
      });
    },
  };
  sandbox.window.setTimeout = sandbox.setTimeout;
  attempt.capture = () => clone({ calls: calls.map(({ reply, fail, ...call }) => call), errors, warnings, diagnostics, confirmations });
  attempt.cleanup = async () => { scope.stop(); calls.filter(call => !call.settled).forEach(call => call.fail(true)); await tick(); timers.forEach(clearTimeout); };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.simulation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.readRequestCooldown = sandbox.appSystemStatic.createReadRequestCooldown();
  scope.run(() => vm.runInContext(`${source}
    aiSimulationRecordId.value = 101;
    simulationDraft.value = { record: { id: 101, name: '已存方案 101', hotelId: 80, savedAt: '2026-09-19 10:00:00' }, name: '当前保留名称', dirty: true };
    suppressSimulationAutoRefresh = false;
    globalThis.ui = { currentPage, aiSimulationRecords, simulationHistoryState, aiSimulationRecordId, simulationDraft,
      aiSimulationParams, aiSimulationLoading, operationHotelOptions, simulationHotelSelectionValid, handleSimulation, managerCapabilityRequest,
      getHotelNameById, simulationRecordSummary, canArchiveSim, archiveSim, loadSimulationRecords, toast,
      simulationArchivePending: typeof simulationArchivePending === 'undefined' ? ref({}) : simulationArchivePending };
    globalThis.coordinator = () => ({ active: coordinatedGetActiveCount, inflight: coordinatedGetRequests.size, queued: coordinatedGetQueue.length });`, sandbox));
  vm.runInContext(heroSource + '\nglobalThis.hero = SimulationHeroActions;', sandbox);
  const memoryHostValue = memoryHost(), renderer = Vue.createRenderer(memoryHostValue.options);
  const app = renderer.createApp({ setup: () => sandbox.ui, render }); app.component('SimulationHeroActions', sandbox.hero);
  app.config.errorHandler = error => errors.push(error.stack || error.message); app.config.warnHandler = warning => warnings.push(warning);
  const walk = node => [node, ...node.children.flatMap(walk)], text = node => node.type === '#comment' ? '' : node.text + node.children.map(text).join('');
  const nodes = () => walk(memoryHostValue.root), find = id => nodes().find(node => node.props['data-testid'] === id);
  const visible = node => { for (let n = node; n; n = n.parent) {
    if (n.style.display === 'none') return false;
    if (n !== node && n.type === 'details' && !n.open && !(node.type === 'summary' && node.parent === n && n.children.find(child => child.type === 'summary') === node)) return false;
  } return true; };
  const enabled = node => { for (let n = node; n; n = n.parent) if (n.props.disabled) return false; return true; };
  const control = id => { const node = find(id) || nodes().find(n => n.type === 'button' && text(n) === id); assert.ok(node, id); assert.ok(visible(node)); return node; };
  const click = id => { const node = control(id); if (!enabled(node)) return { dispatched: false };
    return { dispatched: true, result: node.props.onClick({ type: 'click', target: node }) }; };
  const protectedState = () => clone({ input: sandbox.aiSimulationParams.value, result: sandbox.aiSimulationResult.value, draft: sandbox.ui.simulationDraft.value, recordId: sandbox.ui.aiSimulationRecordId.value });
  const capture = () => clone({ calls: calls.map(({ reply, fail, ...call }) => call), errors, warnings, diagnostics, confirmations, screens,
    toast: sandbox.ui.toast.value, records: sandbox.ui.aiSimulationRecords.value.map(row => row.id), history: sandbox.ui.simulationHistoryState.value,
    pending_ids: Object.keys(sandbox.ui.simulationArchivePending.value), protected_state: protectedState(), text: text(memoryHostValue.root), coordinator: sandbox.coordinator() });
  attempt.capture = capture;
  attempt.cleanup = async () => { app.unmount(); scope.stop(); calls.filter(call => !call.settled).forEach(call => call.fail(true)); await tick(); timers.forEach(clearTimeout); };
  app.mount(memoryHostValue.root);
  return { sandbox, ui: sandbox.ui, calls, confirmations, errors, warnings, diagnostics, control, enabled, click, find, protectedState, capture,
    text: () => text(memoryHostValue.root), confirm: value => { confirmAnswer = value; }, screen: label => screens.push({ label, text: text(memoryHostValue.root) }),
    async changeHotel(id) { const node = control('simulation-hotel-selector'); assert.ok(enabled(node)); node.props.onChange({ target: { value: String(id) } }); await tick(); },
    expireSession() { sandbox.authSessionEpoch += 1; sandbox.token.value = 'synthetic-replacement-session-round142'; },
  };
}
const pending = (h, method, id = '') => h.calls.find(call => !call.settled && call.method === method && call.endpoint === '/simulation/records' + (id ? '/' + id : ''));
async function reply(h, method, id, data) { await until(() => pending(h, method, id), `${method} ${id || 'history'} pending`); pending(h, method, id).reply(data); await tick(); }
async function loadInitial(h) { h.click('刷新历史'); await reply(h, 'GET', '', page(rows())); await tick(); assert.equal(h.ui.simulationHistoryState.value.loaded, true); }
function closed(h, requests, diagnostics) {
  assert.equal(h.calls.length, requests); assert.ok(h.calls.every(call => call.settled && !call.teardown_only && !call.aborted));
  assert.equal(h.diagnostics.length, diagnostics); assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
  assert.deepEqual(clone(h.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0 });
  assert.deepEqual(Object.keys(h.ui.simulationArchivePending.value), []);
}
async function scenario(name, body) { await test(name, async () => { const attempt = { name }; attempts.push(attempt); let h;
  try { h = harness(attempt); await body(h); attempt.pass = true; }
  catch (error) { attempt.error = error.stack; throw error; }
  finally { attempt.before_teardown = attempt.capture?.(); await attempt.cleanup?.(); attempt.after_teardown = attempt.capture?.(); delete attempt.capture; delete attempt.cleanup; }
}); }
try {
await scenario('original confirmation cancels cleanly; one archive lease covers DELETE and refresh while preserving the current name', async h => {
  await loadInitial(h); const before = h.protectedState(); h.confirm(false);
  await h.click('history-simulation-archive-101').result; assert.equal(h.calls.length, 1); h.confirm(true);
  const a = h.click('history-simulation-archive-101'); await until(() => pending(h, 'DELETE', 101), 'archive submitted');
  assert.equal(h.enabled(h.control('history-simulation-archive-101')), false); assert.match(h.text(), /归档中/);
  assert.equal(h.click('history-simulation-archive-101').dispatched, false); assert.equal(h.confirmations.length, 2); assert.equal(h.calls.length, 2);
  await reply(h, 'DELETE', 101, { id: 101 }); await until(() => pending(h, 'GET'), 'original refresh after confirmed archive');
  assert.equal(h.enabled(h.control('history-simulation-archive-101')), false, 'lease lasts through refresh');
  assert.equal(h.ui.aiSimulationRecordId.value, null); assert.equal(h.ui.simulationDraft.value.record, null); assert.equal(h.ui.simulationDraft.value.name, before.draft.name);
  await reply(h, 'GET', '', page(rows().filter(row => row.id !== 101))); await a.result; await tick();
  assert.equal(h.find('history-simulation-archive-101'), undefined); assert.deepEqual(h.ui.aiSimulationRecords.value.map(row => row.id), [103, 102]);
  assert.deepEqual(clone(h.sandbox.aiSimulationParams.value), before.input); assert.deepEqual(clone(h.sandbox.aiSimulationResult.value), before.result);
  assert.equal(h.ui.toast.value.message, '量化模拟记录已归档'); h.screen('confirmed archive removed its row'); closed(h, 3, 0);
});
await scenario('current transport failure permits explicit retry; confirmed archive plus failed refresh keeps an honest warning and read-only refresh recovery', async h => {
  await loadInitial(h); const before = h.protectedState(); const first = h.click('history-simulation-archive-101');
  await until(() => pending(h, 'DELETE', 101), 'first DELETE'); pending(h, 'DELETE', 101).fail(); await first.result; await tick();
  assert.deepEqual(h.protectedState(), before); assert.ok(h.enabled(h.control('history-simulation-archive-101'))); assert.equal(h.ui.toast.value.type, 'error');
  const retry = h.click('history-simulation-archive-101'); await reply(h, 'DELETE', 101, { id: 101 });
  await until(() => pending(h, 'GET'), 'refresh pending'); pending(h, 'GET').fail(); await retry.result; await tick();
  assert.match(h.ui.toast.value.message, /已归档.*刷新未确认/); assert.equal(h.ui.toast.value.type, 'warning'); assert.match(h.text(), /历史记录读取失败/);
  assert.deepEqual(h.ui.aiSimulationRecords.value.map(row => row.id), [103, 102, 101]); assert.equal(h.ui.aiSimulationRecordId.value, null);
  h.screen('archive confirmed but history refresh failed');
  h.click('刷新历史'); await reply(h, 'GET', '', page(rows().filter(row => row.id !== 101))); await tick();
  assert.deepEqual(h.ui.aiSimulationRecords.value.map(row => row.id), [103, 102]); assert.equal(h.calls.filter(call => call.method === 'DELETE').length, 2);
  closed(h, 5, 2);
});
await scenario('a mismatched 200 receipt is a protocol boundary, never permission to clear context or claim an updated list', async h => {
  await loadInitial(h); const before = h.protectedState(); const action = h.click('history-simulation-archive-101');
  // Deliberately invalid receipt boundary, not a claim that the service emits this body.
  await reply(h, 'DELETE', 101, { id: 102 }); await tick();
  assert.deepEqual(h.protectedState(), before); assert.equal(h.calls.length, 2); assert.match(h.ui.toast.value.message, /回执与记录不一致/);
  await action.result;
  assert.ok(h.enabled(h.control('history-simulation-archive-101'))); closed(h, 2, 0);
});
await scenario('original hotel selection invalidates feedback but keeps each ID locked; old completion cannot release another current archive', async h => {
  await loadInitial(h); const old = h.click('history-simulation-archive-101'); await until(() => pending(h, 'DELETE', 101), 'old archive');
  await h.changeHotel(81); const afterEdit = h.protectedState(); assert.equal(h.enabled(h.control('history-simulation-archive-101')), false);
  const current = h.click('history-simulation-archive-102'); await until(() => pending(h, 'DELETE', 102), 'current other ID archive');
  await reply(h, 'DELETE', 101, { id: 101 }); await old.result; await tick();
  assert.equal(h.calls.length, 3, 'stale archive starts no history GET'); assert.equal(h.ui.toast.value.show, false);
  assert.equal(h.enabled(h.control('history-simulation-archive-102')), false); assert.deepEqual(h.protectedState(), afterEdit);
  await reply(h, 'DELETE', 102, { id: 102 }); await reply(h, 'GET', '', page(rows().filter(row => row.id === 103))); await current.result; await tick();
  assert.deepEqual(h.protectedState(), afterEdit, 'another record archive does not clear the current draft');
  const last = h.click('history-simulation-archive-103'); await until(() => pending(h, 'DELETE', 103), 'session-bound archive');
  const previousToast = clone(h.ui.toast.value); h.expireSession(); await tick();
  // Explicit in-memory auth-generation boundary, not an actual logout/login flow.
  await reply(h, 'DELETE', 103, { id: 103 }); await last.result; await tick();
  assert.deepEqual(clone(h.ui.toast.value), previousToast); assert.equal(h.ui.simulationHistoryState.value.loaded, false);
  closed(h, 5, 0);
});
} finally {
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestors, attempts,
    boundary: 'Original history/confirmation/archive/refresh/hotel controls plus original auth/request/GET coordinator in a memory renderer. Synthetic fetch only; public list projection and matching archive receipt. Initial current draft is an explicit already-read premise. No PHP, DB, browser, host storage or real deletion. Current transport error does not establish whether a remote write occurred.' }, null, 2) + '\n');
}
}
