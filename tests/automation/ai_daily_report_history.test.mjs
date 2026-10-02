import assert from 'node:assert/strict';
import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import test from 'node:test';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

// Canonical source is the default. Explicit mapping is only for baseline/candidate verification.
const sourceRoot = process.argv.find(v => v.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(v => v.startsWith('--evidence='))?.slice(11);
const reads = [], sections = [], attempts = [];
const read = path => {
  const mapped = sourceRoot && resolve(sourceRoot, path), actual = mapped && existsSync(mapped) ? mapped : resolve(path);
  const code = readFileSync(actual, 'utf8');
  reads.push({ path, resolved_path: actual, sha256: createHash('sha256').update(code).digest('hex').toUpperCase() });
  return code;
};
const raw = { main: read('public/app-main.js'), full: read('public/components/system/app-main-components.js'),
  loader: read('public/components/system/app-main-components-loader.js'), system: read('public/system-static.js'),
  revenueContract: read('public/revenue-overview-contract-static.js'), revenue: read('public/revenue-ai-static.js'), template: read('resources/frontend/templates/fragments/16-page-ai-daily-report.html') };
const main = raw.main.replaceAll('\r\n', '\n');
function section(start, end) {
  const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start);
  const code = main.slice(a, b); sections.push({ start, end, sha256: createHash('sha256').update(code).digest('hex') }); return code;
}
const requestSource = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
].join('\n');
const scopeSource = section('            const isOperationHotelPermitted =', '            const selectPmsHotel =')
  + section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters =');
const factSource = section('            const loadAiDailyFactGate =', '            let aiDailyReportRequestSeq = 0;');
const detailSource = ['validateAiDailyReportReadback', 'readAiDailyReportById', 'captureAiDailyReportHistoryContext', 'openAiDailyReportHistory']
  .map(name => section(`            const ${name} =`, '\n            };') + '\n            };').join('\n');
const attr = (node, name) => node.props?.find(p => p.type === 6 && p.name === name)?.value?.content;
const ancestors = [];
// Keep the original history insertion, original hotel selector and current report
// date/summary with all ancestors. These targets have no v-else sibling requirement.
const parsed = baseParse(raw.template, parserOptions);
function retain(node, parents = []) {
  if (node.type !== 1) return null;
  const target = node.tag === 'ai-daily-report-history-panel'
    || (node.tag === 'select' && node.loc.source.includes('v-model="aiDailyReportForm.hotel_id"'))
    || (node.tag === 'div' && (node.loc.source.startsWith('<div class="text-xs text-blue-600 font-semibold">日报日期：')
      || node.loc.source.startsWith('<div class="mt-1 text-lg font-bold text-gray-900">{{ aiDailyReport.summary')));
  if (target) { ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
  const children = node.children.map(n => retain(n, parents.concat(node))).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
parsed.children = parsed.children.map(n => retain(n)).filter(Boolean);
const render = new Function('Vue', compile(parsed, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
// Compile the complete template separately; this does not claim a full browser mount.
compile(raw.template, { mode: 'function', prefixIdentifiers: true });
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, message) { for (let n = 0; n < 40 && !predicate(); n++) await tick(); assert.ok(predicate(), message); }
const authMarker = 'synthetic-session-only-round125';
// Persisted legacy report consumer projection from normalizeReportRow. No modern
// snapshot is invented; refs describe saved citations, not verified live facts.
const report = (id, date, overrides = {}) => ({ id, hotel_id: 80, tenant_id: 7, report_date: date, status: 'generated',
  summary: `合成历史日报 ${id}：渠道情况仍需人工核对。`, model_status: 'not_requested', evidence_readback_status: 'legacy_unverified',
  source_refs: [{ table: 'online_daily_data', platform: 'meituan', record_id: 500 + id }], data_gaps: [], ...overrides });
const latest = () => report(920, '2026-09-20', { summary: '合成最近报告正文，应保留至精确历史读取成功。' });
const pageOne = () => Array.from({ length: 10 }, (_, i) => report(919 - i, `2026-09-${19 - i}`));
const listBody = (list, total = list.length, page = 1, overrides = {}) => ({ code: 200, message: 'success', data: {
  list, pagination: { total, page, page_size: 10, total_page: Math.ceil(total / 10) }, data_status: 'ok', ...overrides } });
const failedRead = stage => ({ code: 503, message: '日报读取或证据校验未通过，请恢复数据读取后重试。', data: {
  ...(stage === 'list' ? { list: [], pagination: { total: null, page: null, page_size: null, total_page: null } } : {}),
  status: 'blocked', data_status: 'read_failed', reason_code: 'ai_daily_reports_read_failed', stage,
  data_gaps: [{ code: 'ai_daily_reports_read_failed', data_status: 'read_failed', stage,
    message: 'AI daily report storage could not be read; the result was not treated as missing or empty.' }],
} });
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, listeners: {},
    get options() { return this.children.filter(child => child.type === 'option'); },
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }, removeEventListener() {} });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(n); else parent.children.splice(i, 0, n); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } },
  } };
}
function harness(attempt) {
  const calls = [], errors = [], warnings = [], diagnostics = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl,
    setTimeout, clearTimeout, API_BASE: 'https://synthetic.invalid/api', computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick,
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    authSessionEpoch: 1, pageRequestGeneration: 0, pageLoadRequests: new Map(), aiDailyFactGateRequestSeq: 0, aiDailyReportRequestSeq: 0, aiDailyReportReadOwner: 0,
    currentPage: Vue.ref('ai-daily-report'), filterReportHotel: Vue.ref('80'), token: Vue.ref(authMarker),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]),
    user: Vue.ref({ id: 11, is_super_admin: true }), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    operationFilters: Vue.ref({ hotel_id: '81', date: '2026-08-01' }), operationLoading: Vue.ref({ aiDailyReport: false }), operationError: Vue.ref({ aiDailyReport: '' }),
    aiDailyReport: Vue.ref(latest()), aiDailyReportForm: Vue.ref({ hotel_id: '80', report_date: '2026-09-20', use_llm: false }),
    aiDailyReportGenerationTaskPolling: Vue.ref(false), aiDailyReportGenerationTask: Vue.ref(null), aiDailyReportTaskReturn: Vue.ref(null), operationYesterday: '2026-09-19',
    aiDailyFactGateState: Vue.ref({}), aiDailyFactGateLoading: Vue.ref(false),
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    readRequestCooldown: { check: () => null, record() {} }, normalizeCanonicalPage: value => value,
    operationErrorMessage: error => error?.message || '读取失败', showToast() {},
    autoFetchRunState: Vue.ref({ active: false, type: '' }), stopAutoFetchProgressMonitor() {}, stopAutoFetchRunTimer() {},
    // Other page timers have not been registered in this scoped component renderer.
    clearPageLifecycleTimers() {}, clearPostFetchRefreshTimers() {},
    fetch: (url, options) => {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, ''), method = options.method || 'GET';
      assert.equal(method, 'GET', 'history is read-only');
      assert.equal(new Headers(options.headers).get('Authorization'), authMarker, 'original auth header uses only the in-memory marker');
      const fact = ['/online-data/collection-status', '/online-data/platform-profile-status'].includes(endpoint);
      assert.ok(fact || /^\/ai-daily-reports(?:\/\d+)?$/.test(endpoint), 'unexpected path: ' + endpoint);
      assert.ok(options.signal, 'original coordinator signal retained');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, method, query: Object.fromEntries(parsed.searchParams), signal: true, settled: false, aborted: false,
          resolve(body, status = 200) { call.settled = true; call.response = clone(body); resolveRequest(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); },
          reject() { call.settled = true; call.failure = 'synthetic TypeError: Failed to fetch'; rejectRequest(new TypeError('Failed to fetch')); } };
        calls.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; });
        // Date/hotel sync invokes the original fact-gate watcher and its two GETs.
        // These auxiliary reads fail explicitly; no source-health success is invented.
        if (fact) call.reject();
      });
    },
  };
  const snapshot = () => ({ calls: calls.map(({ resolve, reject, ...c }) => c), errors, warnings, diagnostics,
    report: clone(sandbox.aiDailyReport.value), form: clone(sandbox.aiDailyReportForm.value), operationFilters: clone(sandbox.operationFilters.value),
    busy: sandbox.operationLoading.value.aiDailyReport, factGate: clone(sandbox.aiDailyFactGateState.value) });
  attempt.observe = snapshot;
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.revenueContract, sandbox); vm.runInContext(raw.revenue, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.ensureRevenueAiStaticReady = async () => sandbox.window.SUXI_REVENUE_AI_STATIC;
  const helperStart = raw.full.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_START'), helperEnd = raw.full.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_END', helperStart);
  const effectScope = Vue.effectScope();
  effectScope.run(() => vm.runInContext(`${raw.full.slice(helperStart, helperEnd)}\n${requestSource}\n${scopeSource}\n${factSource}\n${detailSource}\nglobalThis.api={request,loadAiDailyFactGate,captureAiDailyReportHistoryContext:typeof captureAiDailyReportHistoryContext==='function'?captureAiDailyReportHistoryContext:null,openAiDailyReportHistory:typeof openAiDailyReportHistory==='function'?openAiDailyReportHistory:null};`, sandbox));
  const window = {}; vm.runInNewContext(raw.full, { window, URL, URLSearchParams, Date }); vm.runInNewContext(raw.loader, { window, URL });
  const components = window.SUXI_APP_MAIN_COMPONENTS.create({ Vue, h: Vue.h });
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({ components: { AiDailyReportHistoryPanel: components.AiDailyReportHistoryPanel },
    setup: () => ({ currentPage: sandbox.currentPage, aiDailyReport: sandbox.aiDailyReport, aiDailyReportForm: sandbox.aiDailyReportForm,
      operationHotelOptions: sandbox.permittedHotels, operationLoading: sandbox.operationLoading, aiDailyReportGenerationTaskPolling: sandbox.aiDailyReportGenerationTaskPolling,
      aiDailyReportDeliveryRequest: sandbox.api.request, captureAiDailyReportHistoryContext: sandbox.api.captureAiDailyReportHistoryContext,
      openAiDailyReportHistory: sandbox.api.openAiDailyReportHistory, loadAiDailyFactGate: sandbox.api.loadAiDailyFactGate }), render });
  app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => errors.push(error.message); app.mount(host.root);
  const walk = (n, all = []) => { all.push(n); n.children.forEach(c => walk(c, all)); return all; };
  const nodes = () => walk(host.root), text = n => n.text + n.children.map(text).join('');
  const button = id => nodes().find(n => n.type === 'button' && n.props['data-testid'] === id);
  const enabled = n => { assert.ok(n, 'original visible control exists'); for (let a = n; a; a = a.parent) assert.ok(!a.props.disabled && a.style.display !== 'none'); };
  return { sandbox, calls, errors, warnings, diagnostics, nodes, button,
    click(n) { enabled(n); return n.props.onClick({ type: 'click' }); }, text: () => text(host.root),
    async date(value) { const n = nodes().find(n => n.props['data-testid'] === 'ai-history-date'); enabled(n); n.props.onInput({ target: { value } }); await tick(); },
    async hotel(value) { const n = nodes().find(n => n.type === 'select'); enabled(n);
      n.options.forEach(option => { option.selected = String(option._value) === String(value); });
      for (const listener of n.listeners.change || []) listener({ target: n });
      await n.props.onChange({ target: n }); await tick(); },
    lists: () => calls.filter(c => c.endpoint === '/ai-daily-reports'), details: () => calls.filter(c => /^\/ai-daily-reports\/\d+$/.test(c.endpoint)),
    snapshot: () => ({ ...snapshot(), text: text(host.root) }),
    async stop() { app.unmount(); effectScope.stop(); for (const c of calls.filter(c => !c.settled)) { c.teardown_only = true; c.reject(); } await tick(); },
  };
}
async function openPanel(p) { await tick(); const before = p.lists().length; p.click(p.button('ai-history-toggle')); await until(() => p.lists().length > before, 'history request'); }
async function ready(p, rows, total = rows.length) { await openPanel(p); p.lists().at(-1).resolve(listBody(rows, total)); await tick(); }
async function scenario(name, body) {
  await test(name, async () => {
    let p; const attempt = { name }; attempts.push(attempt);
    try { p = harness(attempt); await body(p); assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []); assert.ok(p.calls.every(c => c.settled));
      assert.ok(p.diagnostics.every(d => d[0] === 'API请求失败:' && p.calls.some(c => (c.failure || c.response?.code === 503) && d[1].startsWith(c.endpoint))));
      assert.deepEqual(clone(p.sandbox.operationFilters.value), { hotel_id: '81', date: '2026-08-01' }); attempt.pass = true; }
    catch (error) { attempt.error = error.stack; throw error; }
    finally { if (p) { attempt.before_teardown = p.snapshot(); await p.stop(); attempt.after_teardown = p.snapshot(); } else if (attempt.observe) attempt.before_teardown = attempt.observe(); delete attempt.observe; }
  });
}
if (!process.argv.includes('--prepare-only')) {
await scenario('original daily page opens all historical dates, pages and reads the exact saved legacy report', async p => {
  await ready(p, pageOne(), 11); assert.equal(p.lists()[0].query.hotel_id, '80'); assert.equal(p.lists()[0].query.report_date, undefined);
  assert.match(p.text(), /共 11 份/); assert.match(p.text(), /美团/); assert.match(p.text(), /旧版报告·保存证据未核验/);
  p.click(p.button('ai-history-next')); await until(() => p.lists().length === 2, 'page two'); assert.equal(p.lists()[1].query.page, '2');
  const old = report(901, '2026-09-01', { summary: '较早保存的日报正文：美团渠道说明。', source_refs: [] });
  p.lists()[1].resolve(listBody([old], 11, 2)); await tick(); assert.match(p.text(), /平台未记录 · 来源未记录/);
  const run = p.click(p.button('ai-history-open-901')); await until(() => p.details().length === 1, 'strict detail request');
  assert.equal(p.details()[0].endpoint, '/ai-daily-reports/901'); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
  p.details()[0].resolve({ code: 200, message: 'success', data: old }); await run; await tick();
  assert.deepEqual(clone(p.sandbox.aiDailyReport.value), old); assert.equal(p.sandbox.aiDailyReportForm.value.report_date, '2026-09-01');
  assert.match(p.text(), /日报日期：2026-09-01/); assert.match(p.text(), /较早保存的日报正文/); assert.match(p.text(), /正在查看 · 重新读取/);
  assert.equal(p.sandbox.aiDailyReport.value.evidence_snapshot, undefined); assert.equal(p.sandbox.aiDailyReport.value.evidence_readback_status, 'legacy_unverified');
  assert.equal(p.sandbox.aiDailyFactGateState.value.targetDate, '2026-09-01'); assert.equal(p.sandbox.aiDailyFactGateState.value.errors.length, 2);
});
await scenario('date filtering distinguishes empty success, malformed pagination and 503, then retries while keeping current report', async p => {
  await ready(p, []); assert.match(p.text(), /该范围内没有已保存的日报/);
  await p.date('2026-09-02'); p.click(p.button('ai-history-search')); await until(() => p.lists().length === 2, 'filtered read');
  assert.equal(p.lists()[1].query.report_date, '2026-09-02'); p.lists()[1].resolve(listBody([], 0, 1, { pagination: { total: null, page: 1, page_size: 10, total_page: 0 } })); await tick();
  assert.match(p.text(), /列表响应不完整/); assert.ok(!p.text().includes('该范围内没有已保存'));
  p.click(p.button('ai-history-search')); await until(() => p.lists().length === 3, 'failed read'); p.lists()[2].resolve(failedRead('list'), 503); await tick();
  assert.match(p.text(), /日报读取或证据校验未通过/); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
  p.click(p.button('ai-history-search')); await until(() => p.lists().length === 4, 'retry');
  p.lists()[3].resolve(listBody([report(902, '2026-09-02')], 1, 1, { pagination: { total: '1', page: '1', page_size: '10', total_page: '1' } })); await tick();
  assert.ok(p.button('ai-history-open-902')); assert.ok(!p.text().includes('列表响应不完整')); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
});
await scenario('detail mismatch or failed read preserves current report; original retry can read the selected report', async p => {
  const row = report(903, '2026-09-03'); await ready(p, [row]);
  for (const wrong of [{ id: 904 }, { hotel_id: 81 }, { report_date: '2026-09-04' }]) {
    const before = p.details().length, run = p.click(p.button('ai-history-open-903')); await until(() => p.details().length > before, 'strict mismatch read');
    p.details().at(-1).resolve({ code: 200, data: { ...row, ...wrong } }); await run; await tick(); assert.equal(p.sandbox.aiDailyReport.value.id, 920); assert.match(p.text(), /不一致/);
  }
  let run = p.click(p.button('ai-history-open-903')); await until(() => p.details().length === 4, 'read failure'); p.details().at(-1).resolve(failedRead('read'), 503); await run; await tick();
  assert.match(p.text(), /日报读取或证据校验未通过/); assert.equal(p.sandbox.aiDailyReportForm.value.report_date, '2026-09-20');
  run = p.click(p.button('ai-history-open-903')); await until(() => p.details().length === 5, 'detail retry'); p.details().at(-1).resolve({ code: 200, data: row }); await run; await tick();
  assert.equal(p.sandbox.aiDailyReport.value.id, 903); assert.equal(p.sandbox.operationLoading.value.aiDailyReport, false);
});
await scenario('new hotel and newer query own the list; late detail after a page or session boundary cannot replace the report', async p => {
  await openPanel(p); const first = p.lists()[0]; await p.hotel('81');
  assert.ok(!p.text().includes('日报 #919')); p.click(p.button('ai-history-search')); await until(() => p.lists().length === 2, 'hotel B read');
  p.lists()[1].resolve(listBody([report(811, '2026-09-11', { hotel_id: 81 })])); await tick(); first.resolve(listBody([report(919, '2026-09-19')])); await tick();
  assert.ok(p.button('ai-history-open-811')); assert.equal(p.button('ai-history-open-919'), undefined);
  await p.hotel('80'); p.click(p.button('ai-history-search')); await until(() => p.lists().length === 3, 'old date query');
  await p.date('2026-09-05'); p.click(p.button('ai-history-search')); await until(() => p.lists().length === 4, 'new date query');
  p.lists()[3].resolve(listBody([report(905, '2026-09-05')])); await tick(); p.lists()[2].resolve(listBody([report(906, '2026-09-06')])); await tick();
  assert.ok(p.button('ai-history-open-905')); assert.equal(p.button('ai-history-open-906'), undefined);
  let run = p.click(p.button('ai-history-open-905')); await until(() => p.details().length === 1, 'old page detail');
  // Direct page/context boundary of the component, not an event dispatched to the disabled hotel selector.
  p.sandbox.currentPage.value = 'compass'; await tick(); p.details()[0].resolve({ code: 200, data: report(905, '2026-09-05') }); await run; await tick();
  assert.equal(p.sandbox.aiDailyReport.value.id, 920); assert.equal(p.sandbox.operationLoading.value.aiDailyReport, false);
  p.sandbox.currentPage.value = 'ai-daily-report'; await tick(); await ready(p, [report(905, '2026-09-05')]);
  run = p.click(p.button('ai-history-open-905')); await until(() => p.details().length === 2, 'old session detail'); p.sandbox.authSessionEpoch++;
  p.details()[1].resolve({ code: 200, data: report(905, '2026-09-05') }); await run; await tick();
  assert.equal(p.sandbox.aiDailyReport.value.id, 920); assert.equal(p.sandbox.operationLoading.value.aiDailyReport, false);
});
}
const evidence = { source_reads: reads, original_sections: sections, original_ancestors: ancestors, attempts,
  boundary: 'Original lazy factory, retained original template plus full template compile, original request/auth/coordinator and strict detail reader. Original date/hotel fact-gate watcher retained; its two reads are explicit synthetic transport failures. Page/session controls are direct context boundaries, not a login/navigation browser test. No real HTTP, DB, writes, generation or approval.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
console.log(JSON.stringify({ source_reads: reads, cases: attempts.map(a => ({ name: a.name, pass: !!a.pass, requests: a.before_teardown?.calls.length })) }));
