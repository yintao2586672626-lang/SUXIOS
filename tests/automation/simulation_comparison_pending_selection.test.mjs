import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

const option = key => process.argv.find(arg => arg.startsWith(`--${key}=`))?.slice(key.length + 3);
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const root = path.resolve(option('source-root') || repository);
const readers = [], sections = [], ancestors = [], attempts = [];
const sha = text => createHash('sha256').update(text).digest('hex').toUpperCase();
const read = file => { const resolved = path.join(root, file), bytes = fs.readFileSync(resolved);
  readers.push({ path: file, resolved_path: resolved, sha256: sha(bytes) }); return bytes.toString('utf8'); };
const raw = { main: read('public/app-main.js'), simulation: read('public/simulation-static.js'),
  system: read('public/system-static.js'), template: read('resources/frontend/templates/fragments/02-page-ai-simulation.html') };
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start); const code = main.slice(a, b);
  sections.push({ start, end, sha256: sha(code) }); return code; };
const decl = name => { const start = `            const ${name} =`, a = main.indexOf(start); assert.ok(a >= 0, name);
  const match = /\n            (?:const|let) /.exec(main.slice(a + start.length)); assert.ok(match, name);
  const code = main.slice(a, a + start.length + match.index); sections.push({ declaration: name, sha256: sha(code) }); return code; };
// The frozen pre-pagination main has no history state. In that baseline only,
// supply the same loaded-list consumer premise; never simulate a history fetch.
const historyStateSource = main.includes('            const simulationHistoryState =')
  ? decl('simulationHistoryState') : 'const simulationHistoryState = ref({ loaded: false });';
const source = [
  section('    const requireAppSystemStatic =', '    const requireUserAdminStatic ='), decl('permittedHotels'),
  historyStateSource,
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'),
  decl('normalizeCanonicalPage'), decl('isCompassDataPage'),
  section('            const simulationStaticScript =', '            const defaultSimulationInput ='),
  section('            const simulationComparisonRecords =', '            const hydrateSimulationStateFromStorage ='),
].join('\n');
const attr = (node, name) => node.props?.find(prop => prop.type === 6 && prop.name === name)?.value?.content;
const ast = baseParse(raw.template, parserOptions);
const retain = (node, parents = []) => {
  const target = node.type === 1 && (attr(node, 'data-testid') === 'scenario-comparison'
    || node.loc.source.startsWith('<button v-if="record.summary?.operatingScenario"')
    || node.loc.source.startsWith('<div class="font-medium text-gray-900">{{ record.project_name'));
  if (target) { ancestors.push(parents.concat(node).map(item => item.loc.source.slice(0, item.loc.source.indexOf('>') + 1))); return node; }
  const children = (node.children || []).map(child => retain(child, node.type === 1 ? parents.concat(node) : parents)).filter(Boolean);
  return children.length ? { ...node, children } : null;
};
compile(raw.template, { mode: 'function', prefixIdentifiers: true });
const render = new Function('Vue', compile(retain(ast), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
if (option('prepare-only') === 'true') {
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestors, compile: 'PASS', behavior_runs: 0 }, null, 2) + '\n');
  console.log('prepare-only: full and retained original 02 templates compiled; no application execution');
} else {
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 40 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const marker = 'synthetic-memory-session-round140';

// Public saved-record consumer projection from QuantSimulationService::formatRecord.
// Values are already-saved hypothetical metrics, not freshly calculated or verified facts.
function record(id) {
  const name = id === 101 ? '已存方案 A' : '已存方案 B';
  const scenario = { case_name: name, case_type: 'existing_hotel', start_month: '2026-08', horizon_months: 12,
    currency: 'CNY', monetary_unit: 'yuan', loan_amount: 0, ramp_months: 0,
    evidence_basis: 'assumptions', source_note: 'synthetic saved manual assumptions; no operating fact verification' };
  return { id, project_name: name, created_at: id === 101 ? '2026-09-01 10:00:00' : '2026-09-02 11:00:00',
    input: { hotel_id: 80, monthlyRent: id === 101 ? 10000 : 12000, laborCost: 0, utilityCost: 3000,
      decorationInvestment: 100000, operatingScenario: scenario },
    result: { operatingScenario: { case_name: name, equity_payback: { status: 'not_recovered_within_horizon', months: null },
      additional_cash_gap: 0, ending_cash_balance: id === 101 ? 18000 : 12000 } },
    truth_context: { status: 'unverified', metric_scope: 'investment_scenario', tenant_id: 9, hotel_id: 80,
      scenario_period: { start_month: '2026-08', horizon_months: 12 }, platforms: ['not_applicable'],
      source_methods: ['user_input', 'deterministic_formula'], persistence: { stored: true, readback_verified: true } } };
}
const listRow = id => ({ id, project_name: record(id).project_name, summary: { operatingScenario: {
  case_name: record(id).project_name, case_type: 'existing_hotel', start_month: '2026-08' } } });

function host() {
  const node = (type, text = '') => ({ type, text, props: {}, style: {}, children: [], parent: null });
  const root = node('root');
  const remove = n => { if (n.parent) { n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; } };
  return { root, options: { createElement: type => node(type), createText: text => node('#text', text), createComment: text => node('#comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; if (at < 0) parent.children.push(n); else parent.children.splice(at, 0, n); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; if (key === 'style') n.style = value || {}; } } };
}

function harness(attempt, { cold = false } = {}) {
  const calls = [], scripts = [], errors = [], warnings = [], diagnostics = [], screens = [];
  const scope = Vue.effectScope();
  const sandbox = { ...Vue, window: { Vue }, URL, URLSearchParams, Headers, FormData, Response, Date, Intl, structuredClone,
    AbortController, DOMException, setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api',
    token: Vue.ref(marker), authSessionEpoch: 1, pageRequestGeneration: 1, currentPage: Vue.ref('ai-simulation'),
    authContext: Vue.ref({ tenantId: 9, hotelId: 80, platform: 'all', permissionStatus: 'allowed' }),
    user: Vue.ref({ id: 140, tenant_id: 9, hotel_id: 80, is_super_admin: true }),
    cachedPermittedHotels: [{ id: 80, tenant_id: 9, name: 'synthetic authorized hotel' }],
    filterReportHotel: Vue.ref('80'), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    shanghaiToday: () => '2026-09-20',
    aiSimulationParams: Vue.ref({ hotel_id: 80, roomCount: 97, monthlyRent: 22222, operatingScenario: null }),
    aiSimulationResult: Vue.ref({ monthlyNetCashflow: 9876, retained_current_result: true }),
    simulationDraft: Vue.ref({ record: { id: 909, name: '当前独立已读记录', hotelId: 80, savedAt: '2026-09-03 12:00:00' }, name: '当前未保存名称', dirty: true }),
    // Legitimate already-loaded visible history is the initial consumer precondition.
    // History pagination/list fetching is not simulated or replaced by this test.
    aiSimulationRecords: Vue.ref([listRow(102), listRow(101)]),
    console: { error: (...args) => diagnostics.push(args.map(value => value?.message || String(value))), warn: (...args) => warnings.push(args.map(String)) },
    showToast: () => assert.fail('comparison uses its original local error surface, not a fabricated toast'),
    hydrateSimulationStaticDefaults: () => assert.fail('read-only comparison must not hydrate/edit the current simulation'),
    document: { createElement(tag) { assert.equal(tag, 'script'); return { tag }; }, head: { appendChild(script) { scripts.push(script); } } },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, '');
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.match(endpoint, /^\/simulation\/records\/(101|102)$/);
      assert.equal(options.method || 'GET', 'GET'); assert.equal(new Headers(options.headers).get('Authorization'), marker);
      assert.ok(options.signal, 'original coordinator GET AbortSignal');
      return new Promise((resolve, reject) => {
        const call = { endpoint, method: 'GET', settled: false, aborted: false,
          resolve(data) { assert.equal(call.settled, false); call.settled = true; call.data = clone(data);
            resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', data, time: 1789876800 }), { status: 200 })); },
          reject(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown;
            call.failure = 'controlled TypeError'; reject(new TypeError('Failed to fetch')); } };
        calls.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Aborted', 'AbortError')); } });
      });
    },
  };
  sandbox.window.setTimeout = setTimeout;
  attempt.capture = () => ({ requests: calls.map(({ resolve, reject, ...call }) => call), errors, warnings, diagnostics });
  attempt.cleanup = async () => { scope.stop(); calls.filter(call => !call.settled).forEach(call => call.reject(true)); await tick(); };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.readRequestCooldown = sandbox.appSystemStatic.createReadRequestCooldown();
  if (!cold) vm.runInContext(raw.simulation, sandbox);
  scope.run(() => vm.runInContext(`${source}
    simulationHistoryState.value = { loaded: true, loading: false, error: '', hasMore: false, nextBeforeId: null };
    globalThis.ui = { currentPage, aiSimulationRecords, simulationHistoryState, simulationComparisonRecords, simulationComparisonRows, simulationComparisonError,
      simulationComparisonLoading: typeof simulationComparisonLoading === 'undefined' ? ref(false) : simulationComparisonLoading,
      toggleSimulationComparison, clearSimulationComparison,
      formatCurrency: appSystemStatic.formatCurrency, operatingPaybackText: value => requireSimulationStatic('operatingPaybackText')(value) };
    globalThis.coordinator = () => ({ active: coordinatedGetActiveCount, inflight: coordinatedGetRequests.size, queued: coordinatedGetQueue.length });`, sandbox));
  const memory = host(), renderer = Vue.createRenderer(memory.options);
  // Whitelist UI bindings only; never expose Vue's __esModule via setup spreading.
  const app = renderer.createApp({ setup: () => sandbox.ui, render });
  app.config.errorHandler = error => errors.push(error.message); app.config.warnHandler = warning => warnings.push(warning);
  const walk = (node, output = []) => { output.push(node); node.children.forEach(child => walk(child, output)); return output; };
  const nodes = () => walk(memory.root), text = node => node.text + node.children.map(text).join('');
  const find = id => nodes().find(node => node.props['data-testid'] === id);
  const visible = node => { for (let n = node; n; n = n.parent) {
    if (n.style.display === 'none') return false;
    if (n !== node && n.type === 'details' && !n.open && !(node.type === 'summary' && node.parent === n && n.children.find(child => child.type === 'summary') === node)) return false;
  } return true; };
  const enabled = node => { for (let n = node; n; n = n.parent) if (n.props.disabled) return false; return true; };
  const control = id => { const node = find(id); assert.ok(node, id); assert.ok(visible(node), 'original visible ancestors'); return node; };
  const click = id => { const node = control(id); if (!enabled(node)) return { dispatched: false };
    return { dispatched: true, result: node.props.onClick({ type: 'click' }) }; };
  const protectedState = () => clone({ input: sandbox.aiSimulationParams.value, result: sandbox.aiSimulationResult.value, draft: sandbox.simulationDraft.value });
  const capture = () => ({ requests: calls.map(({ resolve, reject, ...call }) => call), errors, warnings, diagnostics, screens,
    scripts: scripts.map(script => ({ src: script.src, async: script.async })), selected: clone(sandbox.ui.simulationComparisonRecords.value),
    rows: clone(sandbox.ui.simulationComparisonRows.value), loading: sandbox.ui.simulationComparisonLoading.value,
    error: sandbox.ui.simulationComparisonError.value, text: text(memory.root), protected_state: protectedState(), coordinator: clone(sandbox.coordinator()) });
  attempt.capture = capture;
  attempt.cleanup = async () => { app.unmount(); scope.stop(); calls.filter(call => !call.settled).forEach(call => call.reject(true)); await tick(); };
  app.mount(memory.root);
  return { sandbox, calls, scripts, errors, warnings, diagnostics, nodes, find, control, enabled, click, capture, protectedState,
    selected: () => clone(sandbox.ui.simulationComparisonRecords.value).map(value => value.id),
    text: () => text(memory.root), screen: label => screens.push({ label, text: text(memory.root) }),
    completeModule() { assert.equal(scripts.length, 1); assert.match(scripts[0].src, /^simulation-static\.js\?v=/);
      vm.runInContext(raw.simulation, sandbox); scripts[0].onload(); },
    expireSession() { sandbox.authSessionEpoch += 1; sandbox.token.value = 'synthetic-replacement-session-round140'; },
  };
}
const pending = (p, id) => p.calls.find(call => !call.settled && call.endpoint === `/simulation/records/${id}`);
async function answer(p, id, value = record(id)) { await until(() => pending(p, id), 'original exact GET pending'); pending(p, id).resolve(value); await tick(); }
function closed(p, count, diagnostics) {
  assert.equal(p.calls.length, count); assert.ok(p.calls.every(call => call.settled && !call.teardown_only && !call.aborted));
  assert.equal(p.diagnostics.length, diagnostics); assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []);
  assert.deepEqual(clone(p.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0 });
}
async function scenario(name, body, options) {
  await test(name, async () => {
    const attempt = { name }; attempts.push(attempt); let p;
    try { p = harness(attempt, options); await body(p); attempt.pass = true; }
    catch (error) { attempt.error = error.stack; throw error; }
    finally { attempt.before_teardown = attempt.capture?.(); await attempt.cleanup?.(); attempt.after_teardown = attempt.capture?.(); delete attempt.capture; delete attempt.cleanup; }
  });
}
try {
await scenario('original A pending visibly blocks B; two exact saved records compare and clear without editing the current simulation', async p => {
  const before = p.protectedState(); assert.match(p.text(), /已保存方案比较/);
  const a = p.click('history-simulation-compare-101'); assert.ok(a.dispatched);
  await until(() => pending(p, 101), 'A requested');
  assert.equal(p.enabled(p.control('history-simulation-compare-102')), false, 'B is disabled while A is pending');
  assert.ok(p.find('simulation-comparison-loading')); assert.ok(p.find('simulation-comparison-clear'));
  assert.equal(p.click('history-simulation-compare-102').dispatched, false); assert.equal(p.calls.length, 1);
  await answer(p, 101); await a.result; assert.deepEqual(p.selected(), [101]);
  const b = p.click('history-simulation-compare-102'); await answer(p, 102); await b.result;
  assert.deepEqual(p.selected(), [101, 102]);
  const rows = p.nodes().filter(node => node.type === 'tr' && node.parent?.type === 'tbody'); assert.equal(rows.length, 2);
  assert.match(p.text(), /已存方案 A #101/); assert.match(p.text(), /已存方案 B #102/); assert.match(p.text(), /¥0/);
  p.screen('two exact saved record rows');
  p.click('simulation-comparison-clear'); await tick(); assert.deepEqual(p.selected(), []); assert.equal(p.sandbox.ui.simulationComparisonRows.value.length, 0);
  assert.equal(p.find('simulation-comparison-loading'), undefined); assert.deepEqual(p.protectedState(), before); closed(p, 2, 0);
});

await scenario('a current detail failure preserves A, releases B for the original retry, and leaves current draft/result unchanged', async p => {
  const before = p.protectedState(); const a = p.click('history-simulation-compare-101'); await answer(p, 101); await a.result;
  const b = p.click('history-simulation-compare-102'); await until(() => pending(p, 102), 'B pending');
  assert.ok(p.find('simulation-comparison-loading'), 'visible pending state');
  pending(p, 102).reject(); await b.result; await tick();
  assert.deepEqual(p.selected(), [101]); assert.match(p.text(), /Failed to fetch/); assert.ok(p.enabled(p.control('history-simulation-compare-102')));
  const retry = p.click('history-simulation-compare-102'); await answer(p, 102); await retry.result;
  assert.deepEqual(p.selected(), [101, 102]); assert.equal(p.sandbox.ui.simulationComparisonError.value, '');
  assert.deepEqual(p.protectedState(), before); closed(p, 3, 1);
});

await scenario('cold original loader, cancellation and a newer different-ID owner reject late work without releasing new busy state', async p => {
  const before = p.protectedState();
  const first = p.click('history-simulation-compare-101'); await tick();
  assert.equal(p.scripts.length, 1, 'original cold loader appends one script'); assert.equal(p.calls.length, 0);
  assert.ok(p.find('simulation-comparison-loading')); p.click('simulation-comparison-clear'); await tick();
  assert.equal(p.sandbox.ui.simulationComparisonLoading.value, false);
  const b = p.click('history-simulation-compare-102'); await tick(); assert.equal(p.scripts.length, 1, 'shared original module promise');
  p.completeModule(); await until(() => pending(p, 102), 'new owner B requests after module readiness'); await first.result;
  assert.equal(p.calls.length, 1, 'cancelled A sends no detail GET');
  p.click('simulation-comparison-clear'); await tick(); const a = p.click('history-simulation-compare-101');
  await until(() => pending(p, 101), 'new different-ID A GET');
  await answer(p, 102); await b.result;
  assert.equal(p.sandbox.ui.simulationComparisonLoading.value, true, 'old finally cannot release newer A busy');
  assert.deepEqual(p.selected(), []); assert.equal(p.click('history-simulation-compare-102').dispatched, false);
  await answer(p, 101); await a.result; assert.deepEqual(p.selected(), [101]);
  // Session invalidation is an explicit in-memory auth-generation boundary, not a real login.
  const stale = p.click('history-simulation-compare-102'); await until(() => pending(p, 102), 'session-bound read');
  p.expireSession(); await tick(); assert.deepEqual(p.selected(), []); assert.equal(p.sandbox.ui.simulationComparisonLoading.value, false);
  await answer(p, 102); await stale.result; assert.deepEqual(p.selected(), []); assert.equal(p.sandbox.ui.simulationComparisonError.value, '');
  assert.deepEqual(p.protectedState(), before); closed(p, 3, 0);
}, { cold: true });
} finally {
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestors, attempts,
    boundary: 'Original comparison controls, loader, auth/request/GET coordinator; preloaded history and public saved-record consumer projection; synthetic fetch and memory renderer only. No history pagination, PHP, DB, browser or credentials.' }, null, 2) + '\n');
}
}
