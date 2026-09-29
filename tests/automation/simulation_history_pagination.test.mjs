import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { baseParse } from '@vue/compiler-core';
import { compile, parserOptions } from '@vue/compiler-dom';

// Original simulation controls plus request/auth/GET coordinator in a memory renderer.
// Synthetic transport only; no HTTP, login, DB, PHP calculation or host storage.
const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const root = path.resolve(option('source-root') || repository);
const readers = [], sections = [], ancestorPaths = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = relative => {
  const resolved = path.join(root, relative), bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: hash(bytes) });
  return bytes.toString('utf8');
};
const main = read('public/app-main.js'), staticSource = read('public/simulation-static.js');
const template = read('resources/frontend/templates/fragments/02-page-ai-simulation.html');
const components = read('public/components/system/app-main-components.js');
const systemSource = read('public/system-static.js');
function cut(source, start, end) {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  const value = source.slice(a, b); sections.push({ start, end, sha256: hash(value) }); return value;
}
function statement(start) {
  const lines = main.split('\n').filter(line => line.trim().startsWith(start));
  assert.equal(lines.length, 1, start); sections.push({ start, sha256: hash(lines[0]) }); return lines[0];
}
const inputWatchStart = main.includes('watch([aiSimulationParams, () => simulationDraft.value.name]')
  ? '            watch([aiSimulationParams, () => simulationDraft.value.name]' : '            watch(aiSimulationParams, () => {';
const methods = [
  cut(main, '            const captureAuthSession =', '            const createDefaultAuthContext ='),
  cut(main, '            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(main, '            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  cut(main, '            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
  cut(main, '            const simulationStaticScript =', '            const ensureSimulationStaticReady ='),
  cut(main, '            const simulationStaticOption =', '            const requireSimulationStaticFunction ='),
  cut(main, '            const defaultSimulationInput =', '                const aiFeasibilityResult ='),
  cut(main, '            function saveSimulationState(', '            const simulationExportLoadingId ='),
  cut(main, '            const reuseSimulationRecord =', '            const operatingScenarioFields ='),
  statement('const hydrateSimulationStateFromStorage ='),
  cut(main, inputWatchStart, '            const baseSimulation = computed'),
].join('\n');
const heroSource = cut(components, '    const SimulationHeroActions = {', '        return Object.freeze({');
const attr = (node, key) => node.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
const ast = baseParse(template, parserOptions);
function retain(node, parents = []) {
  if (node.type !== 1) return null;
  const src = node.loc.source;
  const target = node.tag === 'simulation-hero-actions'
    || attr(node, 'data-testid') === 'simulation-history-pagination'
    || ['simulation-record-context', 'simulation-legacy-name', 'scenario-name', 'field-simulation-room-count'].includes(attr(node, 'data-testid'))
    || (src.startsWith('<div v-else') || src.startsWith('<p v-else')) && src.includes('当前为旧版单月测算')
    || node.tag === 'button' && /@click="(?:loadSimulationDetail\(record.id\)|reuseSimulationRecord\(record\)|archiveSim\(record\))"/.test(src)
    || src.startsWith('<div class="font-medium text-gray-900">{{ record.project_name')
    || src.startsWith('<div class="text-xs text-gray-500 mt-1">{{ simulationRecordSummary(record) }}')
    || src.startsWith('<div v-else="" class="py-8 text-center text-gray-400">暂无历史记录');
  if (target) { ancestorPaths.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
  const children = (node.children || []).map(n => retain(n, parents.concat(node))).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
ast.children = ast.children.map(node => retain(node)).filter(Boolean);
assert.ok(ancestorPaths.some(p => p.some(s => s.includes("currentPage === 'ai-simulation'"))));
assert.ok(ancestorPaths.some(p => p.some(s => s.includes('v-for="record in aiSimulationRecords"'))));
const renderCode = compile(ast, { mode: 'function', prefixIdentifiers: true }).code;
if (option('prepare-only') === 'true') {
  compile(template, { mode: 'function', prefixIdentifiers: true });
  console.log(JSON.stringify({ prepare_only: true, readers, sections: sections.length, retained_ancestors: ancestorPaths.length, behavior: 0 }));
  process.exit(0);
}
const render = new Function('Vue', renderCode)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
const documentBefore = globalThis.Document, shadowBefore = globalThis.ShadowRoot;
globalThis.Document ??= class MemoryDocument {};
globalThis.ShadowRoot ??= class MemoryShadowRoot {};
function finishEvidence() {
  if (documentBefore === undefined) delete globalThis.Document; else globalThis.Document = documentBefore;
  if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestorPaths, attempts,
    boundary: 'Original handlers/native v-model in a memory renderer; Original auth/request/GET queue with synthetic fetch and memory renderer/storage; no HTTP/PHP/DB/browser or host storage.' }, null, 2) + '\n');
}
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, value: '', listeners: {},
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); },
    removeEventListener(name, fn) { this.listeners[name] = (this.listeners[name] || []).filter(value => value !== fn); },
    dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) listener({ ...event, target: this }); },
    getRootNode() { let root = this; while (root.parent) root = root.parent; return root; },
  });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(n); else parent.children.splice(i, 0, n); },
    remove, patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } },
  } };
}
const marker = 'synthetic-session-only-round139';
async function until(condition, label) { for (let i = 0; i < 30 && !condition(); i++) await tick(); assert.ok(condition(), label); }
function harness(attempt) {
  const requests = [], notices = [], errors = [], warnings = [], diagnostics = [], actions = [], memory = new Map(), screens = [];
  const sandbox = { ...Vue, window: {}, JSON, setTimeout, clearTimeout, crypto: webcrypto, Headers, Response, URL, URLSearchParams, FormData,
    AbortController, DOMException, Date, Intl, structuredClone, TextEncoder,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1, pageLoadRequests: new Map(),
    token: Vue.ref(marker), user: Vue.ref({ id: 77, tenant_id: 70, hotel_id: 7, is_super_admin: true }),
    authContext: Vue.ref({ tenantId: 70, hotelId: 7, permissionStatus: 'allowed' }),
    permittedHotels: Vue.ref([{ id: 7, tenant_id: 70 }, { id: 8, tenant_id: 70 }]),
    currentPage: Vue.ref('ai-simulation'), operationHotelOptions: Vue.ref([{ id: 7, name: '合成门店七' }, { id: 8, name: '合成门店八' }]),
    filterReportHotel: Vue.ref('7'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    normalizeCanonicalPage: value => value, readRequestCooldown: { check: () => null, record() {} },
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    aiProject: Vue.ref({ project_name: '独立当前项目' }),
    localStorage: { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, String(value)), removeItem: key => memory.delete(key) },
    confirm: () => { throw new Error('No writes permitted'); }, showToast: (message, type = 'success') => notices.push({ message, type }),
    getHotelNameById: id => ({ 7: '合成门店七', 8: '合成门店八' }[id] || ''), formatCurrency: value => value == null ? '--' : String(value),
    isStillOnRequestPage: page => page === sandbox.currentPage.value,
    console: { error: (...args) => diagnostics.push(args.map(value => value?.message || String(value))), warn: (...args) => warnings.push(args.map(String).join(' ')) },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, '');
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
      assert.match(endpoint, /^\/simulation\/records(?:\/\d+)?$/);
      assert.equal(new Headers(options.headers).get('Authorization'), marker); assert.ok(options.signal, 'original GET AbortSignal');
      return new Promise((resolve, reject) => {
        const call = { endpoint, query: Object.fromEntries(parsed.searchParams), method: 'GET', settled: false, aborted: false,
          reply(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data); resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', data, time: 1790000000 }), { status: 200 })); },
          fail(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown; call.failure = 'controlled TypeError'; reject(new TypeError('Failed to fetch')); } };
        requests.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Aborted', 'AbortError')); } });
      });
    },
  };
  const scope = Vue.effectScope();
  attempt.capture = () => ({ requests: requests.map(({ reply, fail, ...row }) => row), notices, errors, warnings, diagnostics, screens });
  attempt.stop = async () => { scope.stop(); requests.filter(row => !row.settled).forEach(row => row.fail(true)); await tick(); };
  vm.createContext(sandbox); vm.runInContext(systemSource, sandbox); vm.runInContext(staticSource, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  const api = sandbox.window.SUXI_SIMULATION_STATIC;
  sandbox.hasSimulationStatic = Vue.computed(() => true);
  sandbox.ensureSimulationStaticReady = async () => api;
  // Original history loadSimulationStatic takes its real preloaded branch. The
  // untouched detail method retains its existing ensureReady projection; this
  // test does not claim cold script loading, hydration or browser startup.
  sandbox.normalizeSimulationInput = api.normalizeSimulationInput;
  sandbox.normalizeSimulationModelAnalysis = api.normalizeSimulationModelAnalysis;
  sandbox.generateRiskHints = api.generateRiskHints;
  function record(id, hotelId = 7) {
    const input = { ...clone(api.defaultSimulationInput), hotel_id: hotelId, input_source_status: 'manual_unverified', operatingScenario: null };
    // A consumer projection of formatRecord: missing result facts remain null;
    // no PHP calculation/persisted account data is claimed by these fixture rows.
    return { id, project_name: `合成已存方案 ${id}`, created_at: '2026-09-19 09:00:00', created_by: 77,
      monthly_net_cashflow: null, payback_months: null, risk_level: '', summary: { monthlyRevenue: null, monthlyNetCashflow: null, paybackMonths: null, riskLevel: '', operatingScenario: null },
      truth_context: { hotel_id: hotelId, tenant_id: 70, status: 'unverified', metric_scope: 'investment_scenario',
        platforms: ['not_applicable'], date_range: { start: '2026-09-19', end: '2026-09-19' }, source_methods: ['user_input', 'deterministic_formula'],
        persistence: { stored: true, readback_verified: true, stored_count: 1, readback_verified_count: 1 } },
      access_policy: { mode: 'hotel_scoped', hotel_binding_required: false, mutation_allowed: null, reason_code: 'hotel_capability_required' },
      input, result: {}, scenarios: [], risk_hints: [], model_analysis: null };
  }
  sandbox.managerCapabilityRequest = () => { throw new Error('Unrelated commission panel request forbidden'); };
  scope.run(() => vm.runInContext(methods + `\nglobalThis.ui = {
    aiSimulationParams, aiSimulationResult, aiSimulationScenarios, aiSimulationRecords, aiSimulationRecordId, aiSimulationLoading,
    simulationHotelSelectionValid, handleSimulation, loadSimulationDetail, reuseSimulationRecord, loadSimulationRecords,
    archiveSim, canArchiveSim, simulationArchivePending, simulationRecordSummary, hydrateSimulationStateFromStorage, simulationDraft,
    ...(typeof simulationHistoryState === 'undefined' ? {} : { simulationHistoryState }) };
    globalThis.coordinator = () => ({ active: coordinatedGetActiveCount, inflight: coordinatedGetRequests.size, queued: coordinatedGetQueue.length, page_reads: pageLoadRequests.size });`, sandbox));
  vm.runInContext(heroSource + '\nglobalThis.hero = SimulationHeroActions;', sandbox);
  const ui = sandbox.ui, exposed = { ...sandbox, ...ui };
  delete exposed.__esModule; // Vue namespace interop metadata is not a page binding.
  for (const name of ['handleSimulation', 'loadSimulationDetail', 'reuseSimulationRecord', 'loadSimulationRecords', 'archiveSim']) {
    exposed[name] = (...args) => { const promise = ui[name](...args); actions.push({ name, promise }); return promise; };
  }
  const memoryHostValue = memoryHost(), renderer = Vue.createRenderer(memoryHostValue.options);
  const app = renderer.createApp({ setup: () => exposed, render }); app.component('SimulationHeroActions', sandbox.hero);
  app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => errors.push(error.stack || String(error));
  const walk = n => [n, ...n.children.flatMap(walk)], text = n => n.type === 'comment' ? '' : n.text + n.children.map(text).join('');
  const nodes = () => walk(memoryHostValue.root), find = id => nodes().find(n => n.props['data-testid'] === id);
  const available = node => { assert.ok(node, 'original visible control exists'); for (let p = node; p; p = p.parent) {
    assert.ok(!p.props.disabled && p.style.display !== 'none'); if (p.type === 'details') assert.ok(p.open);
  } return node; };
  const button = name => nodes().find(n => n.type === 'button' && (n.props['data-testid'] === name || text(n) === name));
  attempt.capture = () => ({ requests: requests.map(({ reply, fail, ...row }) => row), notices, errors, warnings, diagnostics, screens,
    coordinator: clone(sandbox.coordinator()), records: ui.aiSimulationRecords.value.map(row => row.id),
    state: ui.simulationHistoryState ? clone(ui.simulationHistoryState.value) : null,
    current_record: ui.aiSimulationRecordId.value, draft: clone(ui.simulationDraft.value), input: clone(ui.aiSimulationParams.value), text: text(memoryHostValue.root) });
  attempt.stop = async () => { app.unmount(); scope.stop(); requests.filter(row => !row.settled).forEach(row => row.fail(true)); await tick(); };
  app.mount(memoryHostValue.root);
  return { ui, sandbox, record, requests, notices, errors, warnings, diagnostics, find, button, text: () => text(memoryHostValue.root),
    screen: label => screens.push({ label, text: text(memoryHostValue.root) }),
    async click(name) { const node = available(button(name)), before = actions.length; node.props.onClick({ target: node }); await tick();
      assert.equal(actions.length, before + 1); return { completion: actions.at(-1).promise }; },
    async editHotel(id) { const node = available(nodes().find(n => n.type === 'select' && n.props['data-testid'] === 'simulation-hotel-selector'));
      node.props.onChange({ target: { value: String(id) } }); await tick(); },
  };
}
function page(h, first, count, hasMore) {
  const list = Array.from({ length: count }, (_, i) => h.record(first - i, (first - i) % 2 ? 7 : 8));
  return { list, pagination: { page_size: 30, returned_count: count, has_more: hasMore, next_before_id: hasMore ? list.at(-1).id : null } };
}
async function begin(h, button = '刷新历史') { const before = h.requests.length, action = await h.click(button);
  await until(() => h.requests.length > before, 'original loader starts its GET'); return { action, call: h.requests.at(-1) }; }
async function finish(h, pending, data) { pending.call.reply(data); await pending.action.completion; await tick(); }
async function scenario(name, body) { await test(name, async () => { const attempt = { name }; attempts.push(attempt);
  try { const h = harness(attempt); await tick(); assert.ok(h.find('simulation-history-pagination'), 'history continuation control exists');
    await body(h); await tick(); assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
    assert.deepEqual(clone(h.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0, page_reads: 0 });
    assert.ok(h.requests.every(row => row.settled && !row.teardown_only));
    assert.equal(h.diagnostics.length, h.requests.filter(row => row.failure).length);
    h.diagnostics.forEach(row => { assert.equal(row[0], 'API请求失败:'); assert.match(row[1], /^\/simulation\/records/); assert.equal(row[2], 'Failed to fetch'); });
    attempt.passed = true;
  } catch (error) { attempt.failure = error.stack; throw error; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.stop) await attempt.stop();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.stop; } }); }

try {
await scenario('earlier authorized records survive a retry and the31st original view fills the saved identity', async h => {
  await h.editHotel(7);
  const first = await begin(h); assert.deepEqual(first.call.query, {}); await finish(h, first, page(h, 200, 30, true));
  const existing = await begin(h, 'history-simulation-view-199'); await finish(h, existing, h.record(199, 7));
  const draft = clone(h.ui.aiSimulationParams.value), identity = clone(h.ui.simulationDraft.value);
  assert.equal(identity.dirty, false); assert.equal(h.ui.aiSimulationRecords.value.length, 30);
  const failed = await begin(h, 'simulation-history-more'); assert.deepEqual(failed.call.query, { before_id: '171', page_size: '30' });
  assert.equal(h.button('simulation-history-more').props.disabled, true); failed.call.fail(); await failed.action.completion; await tick();
  assert.equal(h.ui.aiSimulationRecords.value.length, 30); assert.equal(h.ui.simulationHistoryState.value.nextBeforeId, 171);
  assert.deepEqual(clone(h.ui.aiSimulationParams.value), draft); assert.match(h.text(), /历史记录读取失败/);
  const retry = await begin(h, 'simulation-history-more'); assert.deepEqual(retry.call.query, failed.call.query); await finish(h, retry, page(h, 170, 5, false));
  assert.equal(h.ui.aiSimulationRecords.value.length, 35); assert.match(h.text(), /全部记录/); assert.equal(h.button('simulation-history-more'), undefined);
  assert.deepEqual(clone(h.ui.aiSimulationParams.value), draft, 'append cannot change input hotel or draft');
  assert.deepEqual(clone(h.ui.simulationDraft.value), identity, 'history reading cannot mark the current saved draft dirty');
  const detail = await begin(h, 'history-simulation-view-170'); assert.equal(detail.call.endpoint, '/simulation/records/170');
  await finish(h, detail, h.record(170, 8)); assert.equal(h.ui.aiSimulationRecordId.value, 170);
  assert.equal(Number(h.ui.aiSimulationParams.value.hotel_id), 8); assert.equal(h.ui.simulationDraft.value.name, '合成已存方案 170');
  assert.match(h.text(), /记录 #170/); assert.match(h.text(), /合成门店八/); assert.match(h.text(), /2026-09-19 09:00:00/);
  h.screen('31st visible record and exact saved context');
});
await scenario('legacy list-only is explicit and malformed continuation preserves the original page and cursor', async h => {
  const legacy = await begin(h); await finish(h, legacy, { list: [h.record(9)] });
  assert.match(h.text(), /分页信息未返回/); assert.doesNotMatch(h.text(), /全部记录/); assert.equal(h.button('simulation-history-more'), undefined);
  const current = await begin(h); await finish(h, current, page(h, 100, 30, true));
  for (const malformed of [data => { delete data.pagination; }, data => { data.pagination.next_before_id = 999; }, data => { data.list.reverse(); }]) {
    const next = await begin(h, 'simulation-history-more'), data = page(h, 70, 30, true); malformed(data); await finish(h, next, data);
    assert.equal(h.ui.aiSimulationRecords.value.length, 30); assert.equal(h.ui.simulationHistoryState.value.nextBeforeId, 71);
    assert.match(h.text(), /历史记录读取失败/);
  }
  const end = await begin(h, 'simulation-history-more'); await finish(h, end, page(h, 70, 0, false));
  assert.equal(h.ui.aiSimulationRecords.value.length, 30); assert.match(h.text(), /全部记录/);
  const fresh = await begin(h); assert.deepEqual(fresh.call.query, {}); await finish(h, fresh, page(h, 300, 1, false));
  assert.deepEqual(h.ui.aiSimulationRecords.value.map(row => row.id), [300]); h.screen('refresh replaces accumulated pages');
});
await scenario('refresh owns its read and late continuation cannot restore the old page or busy state', async h => {
  const first = await begin(h); await finish(h, first, page(h, 100, 30, true));
  const oldMore = await begin(h, 'simulation-history-more'), failedRefresh = await begin(h);
  failedRefresh.call.fail(); await failedRefresh.action.completion; await tick();
  assert.equal(h.ui.simulationHistoryState.value.loading, false); assert.match(h.text(), /历史记录读取失败/);
  oldMore.call.reply(page(h, 70, 2, false)); await oldMore.action.completion; await tick();
  assert.equal(h.ui.aiSimulationRecords.value.length, 30); assert.equal(h.ui.simulationHistoryState.value.nextBeforeId, 71);
  const lateMore = await begin(h, 'simulation-history-more'), refresh = await begin(h);
  lateMore.call.reply(page(h, 70, 2, false)); await lateMore.action.completion; await tick();
  assert.equal(h.ui.simulationHistoryState.value.loading, true, 'old completion cannot clear newer read busy');
  await finish(h, refresh, page(h, 500, 1, false)); assert.deepEqual(h.ui.aiSimulationRecords.value.map(row => row.id), [500]);
  h.sandbox.currentPage.value = 'compass'; await tick(); assert.equal(h.ui.simulationHistoryState.value.loaded, false);
  h.sandbox.currentPage.value = 'ai-simulation'; await tick(); assert.equal(h.find('history-simulation-view-500'), undefined);
  h.screen('return awaits fresh history rather than exposing the old rows');
});

} finally {
  finishEvidence();
}
