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
const raw = { operation: read('public/operation-static.js'), opsTemplate: read('resources/frontend/templates/fragments/17-page-ops-track.html'), main: read('public/app-main.js'), full: read('public/components/system/app-main-components.js'),
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
  section('            const currentPageReadPolicy =', '            const activateCoreOperationsAfterLogin ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
].join('\n');
const scopeSource = section('            const isOperationHotelPermitted =', '            const selectPmsHotel =')
  + section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters =');
const factSource = section('            const loadAiDailyFactGate =', '            let aiDailyReportRequestSeq = 0;');
const detailSource = section('            const loadAiDailyReport =', '            const generateAiDailyReport =');
const pageChange = section('                const previousPage = previousPageLifecycleKey;', '                if (!isCompassDataPage(newPage))');
const aiActivation = section("                if (newPage === 'ai-daily-report') {", "                if (newPage === 'ops-track') {");
const attr = (node, name) => node.props?.find(p => p.type === 6 && p.name === name)?.value?.content;
const ancestors = [];
// Keep the original history insertion, original hotel selector and current report
// date/summary with all ancestors. These targets have no v-else sibling requirement.
function renderFor(source, target) {
  const parsed = baseParse(source, parserOptions);
  function retain(node, parents = []) {
    if (node.type !== 1) return null;
    if (target(node)) { ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
    const children = node.children.map(n => retain(n, parents.concat(node))).filter(Boolean);
    return children.length ? { ...node, children } : null;
  }
  parsed.children = parsed.children.map(n => retain(n)).filter(Boolean);
  compile(source, { mode: 'function', prefixIdentifiers: true });
  return new Function('Vue', compile(parsed, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
}
const render = renderFor(raw.opsTemplate + '\n' + raw.template, node => attr(node, 'data-testid') === 'ai-daily-source-location'
  || (node.tag === 'button' && node.loc.source.includes('@click="loadAiDailyReport"'))
  || (node.tag === 'div' && (node.loc.source.startsWith('<div class="text-xs text-blue-600 font-semibold">日报日期：')
    || node.loc.source.startsWith('<div class="mt-1 text-lg font-bold text-gray-900">{{ aiDailyReport.summary')))
  || attr(node, 'data-testid') === 'operation-ai-daily-source'
  || (node.tag === 'div' && node.loc.source.startsWith('<div class="font-medium text-gray-900">{{ operationExecutionSourceText(item) }}')));
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, message) { for (let n = 0; n < 40 && !predicate(); n++) await tick(); assert.ok(predicate(), message); }
const authMarker = 'synthetic-session-only-round128';
// Persisted legacy report consumer projection from normalizeReportRow. No modern
// snapshot is invented; refs describe saved citations, not verified live facts.
const report = (id, date, overrides = {}) => ({ id, hotel_id: 80, tenant_id: 7, report_date: date, status: 'generated',
  summary: `合成历史日报 ${id}：渠道情况仍需人工核对。`, model_status: 'not_requested', evidence_readback_status: 'legacy_unverified',
  source_refs: [{ table: 'online_daily_data', platform: 'meituan', record_id: 500 + id }], data_gaps: [], ...overrides });
const latest = () => report(920, '2026-09-20', { hotel_id: 81, summary: '另一酒店先前显示的报告正文。' });
const action = () => ({ id: 301, hotel_id: 80, identity: { status: 'consistent', gap_count: 0 }, recommendation: { source: 'ai_daily_report#901', source_module: 'ai_daily_report', source_record_id: 901, platform: 'ctrip', date_start: '2026-09-19', date_end: '2026-09-19', evidence: { ai_daily_report_id: 901, action_index: 0 } }, execution: { task_id: 0 } });
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
  let prepareGate = null; const prepCalls = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl,
    setTimeout, clearTimeout, ref: Vue.ref, PAGE_LOAD_DEDUP_MS: Number(main.match(/const PAGE_LOAD_DEDUP_MS = (\d+);/)[1]), lastLoadedPage: '', lastLoadedPageAt: 0, API_BASE: 'https://synthetic.invalid/api', computed: Vue.computed, watch: Vue.watch, nextTick: Vue.nextTick,
    console: { error: (...args) => diagnostics.push(args.map(a => a?.message || String(a))), warn: (...args) => warnings.push(args.map(String)) },
    authSessionEpoch: 1, pageRequestGeneration: 0, pageLoadRequests: new Map(), aiDailyFactGateRequestSeq: 0, aiDailyReportRequestSeq: 0, aiDailyReportReadOwner: 0,
    currentPage: Vue.ref('ops-track'), filterReportHotel: Vue.ref('81'), token: Vue.ref(authMarker),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]),
    user: Vue.ref({ id: 11, is_super_admin: true }), revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    operationFilters: Vue.ref({ hotel_id: '80', date: '2026-08-01' }), operationLoading: Vue.ref({ actions: false, aiDailyReport: false }), operationError: Vue.ref({ aiDailyReport: '' }),
    aiDailyReport: Vue.ref(latest()), aiDailyReportForm: Vue.ref({ hotel_id: '81', report_date: '2026-09-20', use_llm: false }),
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
      assert.ok(fact || /^\/ai-daily-reports\/(?:\d+|latest)$/.test(endpoint), 'unexpected path: ' + endpoint);
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
    notice: clone(sandbox.api?.notice?.value ?? null), page: sandbox.currentPage.value, report: clone(sandbox.aiDailyReport.value), form: clone(sandbox.aiDailyReportForm.value), operationFilters: clone(sandbox.operationFilters.value),
    busy: sandbox.operationLoading.value.aiDailyReport, factGate: clone(sandbox.aiDailyFactGateState.value) });
  attempt.observe = snapshot;
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  sandbox.operationExecutionFlow = Vue.ref({ list: [action()] });
  sandbox.operationExecutionItems = Vue.computed(() => sandbox.operationExecutionFlow.value.list);
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.revenueContract, sandbox); vm.runInContext(raw.revenue, sandbox); vm.runInContext(raw.operation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.ensureOperationStaticReady = async () => sandbox.window.SUXI_OPERATION_STATIC;
  sandbox.ensureRevenueAiStaticReady = async () => { prepCalls.push({ page: sandbox.currentPage.value }); if (prepareGate) await prepareGate.promise; return sandbox.window.SUXI_REVENUE_AI_STATIC; };
  const helperStart = raw.full.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_START'), helperEnd = raw.full.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_END', helperStart);
  const effectScope = Vue.effectScope();
  effectScope.run(() => vm.runInContext(`${raw.full.slice(helperStart, helperEnd)}\n${requestSource}\n${scopeSource}\n${factSource}\n${detailSource}\nglobalThis.api={request,loadAiDailyFactGate,loadAiDailyReport,openSource:typeof openOperationAiDailySource==='function'?openOperationAiDailySource:null,target:typeof operationAiDailySourceTarget==='function'?operationAiDailySourceTarget:()=>null,notice:typeof operationAiDailySourceNotice!=='undefined'?operationAiDailySourceNotice:null};let previousPageLifecycleKey=currentPage.value;watch(currentPage,newPage=>{${pageChange}\n${aiActivation}});`, sandbox));
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({
    setup: () => ({ currentPage: sandbox.currentPage, authContext: sandbox.authContext, aiDailyReport: sandbox.aiDailyReport,
      aiDailyReportForm: sandbox.aiDailyReportForm, operationLoading: sandbox.operationLoading, aiDailyReportGenerationTaskPolling: sandbox.aiDailyReportGenerationTaskPolling,
      operationAiDailySourceNotice: sandbox.api.notice, operationAiDailySourceTarget: sandbox.api.target,
      openOperationAiDailySource: sandbox.api.openSource, loadAiDailyReport: sandbox.api.loadAiDailyReport,
      operationExecutionFilteredItems: sandbox.operationExecutionItems, operationExecutionRowClass: () => '',
      operationExecutionSourceText: sandbox.window.SUXI_OPERATION_STATIC.operationExecutionSourceText }),
    render,
  });
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
    prepCalls, holdPrepare() { let release; const promise = new Promise(r => { release = r; }); prepareGate = { promise, release }; return () => { const gate = prepareGate; prepareGate = null; gate.release(); }; },
    latest: () => calls.filter(c => c.endpoint === '/ai-daily-reports/latest'), details: () => calls.filter(c => /^\/ai-daily-reports\/\d+$/.test(c.endpoint)),
    snapshot: () => ({ ...snapshot(), text: text(host.root) }),
    async stop() { app.unmount(); effectScope.stop(); for (const c of calls.filter(c => !c.settled)) { c.teardown_only = true; c.reject(); } await tick(); },
  };
}
async function openSource(p) {
  await tick(); const before = p.details().length; const result = await p.click(p.button('operation-ai-daily-source'));
  assert.equal(result, true); await until(() => p.details().length > before, 'exact source GET'); return p.details().at(-1);
}
async function finishSource(p, call, body = report(901, '2026-09-01'), status = 200) {
  call.resolve({ code: status, message: status === 200 ? 'success' : '合成错误', data: body }, status);
  await until(() => !p.sandbox.operationLoading.value.aiDailyReport, 'source settled'); await tick();
}
async function scenario(name, body) {
  await test(name, async () => {
    let p; const attempt = { name }; attempts.push(attempt);
    try { p = harness(attempt); await body(p); assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []);
      assert.ok(p.calls.every(c => c.settled)); assert.ok(p.calls.every(c => c.method === 'GET'));
      assert.ok(p.diagnostics.every(d => d[0] === 'API请求失败:' && p.calls.some(c => (c.failure || c.response?.code === 503) && d[1].startsWith(c.endpoint))));
      attempt.pass = true; }
    catch (error) { attempt.error = error.stack; throw error; }
    finally { if (p) { attempt.before_teardown = p.snapshot(); await p.stop(); attempt.after_teardown = p.snapshot(); } else if (attempt.observe) attempt.before_teardown = attempt.observe(); delete attempt.observe; }
  });
}
if (!process.argv.includes('--prepare-only')) {
await scenario('original source action reads its exact hotel/report and own date instead of the action execution date', async p => {
  const call = await openSource(p); assert.equal(call.endpoint, '/ai-daily-reports/901'); assert.equal(call.query.hotel_id, '80');
  assert.equal(p.sandbox.currentPage.value, 'ai-daily-report'); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
  assert.match(p.text(), /正在读取来源日报 #901（酒店 #80），当前显示尚未替换/); assert.match(p.text(), /另一酒店先前显示/);
  assert.equal(p.sandbox.operationExecutionItems.value[0].recommendation.date_start, '2026-09-19');
  await finishSource(p, call);
  assert.equal(p.sandbox.aiDailyReport.value.id, 901); assert.equal(p.sandbox.aiDailyReport.value.hotel_id, 80);
  assert.equal(p.sandbox.aiDailyReportForm.value.report_date, '2026-09-01'); assert.match(p.text(), /日报日期：2026-09-01/);
  assert.match(p.text(), /已读取来源日报 #901 · 酒店 #80 · 日报日期 2026-09-01/); assert.equal(p.latest().length, 0);
  assert.equal(p.sandbox.aiDailyReport.value.evidence_snapshot, undefined); assert.equal(p.sandbox.aiDailyReport.value.evidence_readback_status, 'legacy_unverified');
  assert.deepEqual(clone(p.sandbox.operationFilters.value), { hotel_id: '80', date: '2026-08-01' });
  // Another report selected through the existing page owns its own display; the old source notice disappears.
  p.sandbox.aiDailyReport.value = report(902, '2026-09-02'); await tick(); assert.ok(!p.text().includes('已读取来源日报 #901'));
});
await scenario('missing/conflicting/manual identities do not become source links; strict wrong report/hotel/date keeps the old display', async p => {
  await tick(); assert.ok(p.button('operation-ai-daily-source'));
  for (const patch of [{ source_module: 'manual', source_record_id: 0 }, { source_record_id: 0 }, { evidence: { ai_daily_report_id: 999 } }]) {
    p.sandbox.operationExecutionFlow.value = { list: [{ ...action(), recommendation: { ...action().recommendation, ...patch } }] }; await tick();
    assert.equal(p.button('operation-ai-daily-source'), undefined); assert.equal(p.calls.length, 0);
  }
  for (const wrong of [{ id: 902 }, { hotel_id: 81 }, { report_date: '2026-02-30' }]) {
    p.sandbox.operationExecutionFlow.value = { list: [action()] }; p.sandbox.currentPage.value = 'ops-track'; await tick();
    const call = await openSource(p); await finishSource(p, call, report(901, '2026-09-01', wrong));
    assert.equal(p.sandbox.aiDailyReport.value.id, 920); assert.match(p.text(), /来源日报 #901 未能读取，当前显示未替换/);
    assert.equal(p.latest().length, 0);
  }
});
await scenario('read failure leaves the previous report explicit, original action retries, and ordinary AI entry still reads latest', async p => {
  let call = await openSource(p); call.resolve(failedRead('read'), 503);
  await until(() => !p.sandbox.operationLoading.value.aiDailyReport, 'failure settled'); await tick();
  assert.match(p.text(), /未能读取，当前显示未替换/); assert.match(p.text(), /日报读取或证据校验未通过/); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
  p.sandbox.currentPage.value = 'ops-track'; await tick(); call = await openSource(p); await finishSource(p, call); assert.equal(p.sandbox.aiDailyReport.value.id, 901);
  // Respect the original successful-page deduplication window before checking ordinary entry.
  await new Promise(resolve => setTimeout(resolve, p.sandbox.PAGE_LOAD_DEDUP_MS + 1));
  p.sandbox.currentPage.value = 'ops-track'; await tick(); p.sandbox.aiDailyReportForm.value.report_date = '2026-09-19'; p.sandbox.currentPage.value = 'ai-daily-report';
  await until(() => p.latest().length === 1, 'ordinary original AI activation latest');
  p.latest()[0].resolve({ code: 200, data: { report: report(919, '2026-09-19'), data_status: 'ok', data_gaps: [] } });
  await until(() => !p.sandbox.operationLoading.value.aiDailyReport, 'latest settled'); await tick(); assert.equal(p.sandbox.aiDailyReport.value.id, 919);
  assert.ok(!p.text().includes('已读取来源日报 #901'));
});
await scenario('leaving during source preparation clears its target and busy without a fallback latest; row change prevents late commit', async p => {
  const release = p.holdPrepare(); await tick(); assert.equal(await p.click(p.button('operation-ai-daily-source')), true); await tick();
  assert.equal(p.sandbox.operationLoading.value.aiDailyReport, true); assert.match(p.text(), /当前显示尚未替换/);
  p.sandbox.currentPage.value = 'ops-track'; await tick(); assert.equal(p.sandbox.operationLoading.value.aiDailyReport, false);
  release(); await tick(); assert.equal(p.details().length, 0); assert.equal(p.latest().length, 0); assert.equal(p.sandbox.aiDailyReport.value.id, 920);
  const call = await openSource(p);
  // Direct current-row boundary; no forged click on a missing/disabled source control.
  p.sandbox.operationExecutionFlow.value = { list: [{ ...action(), recommendation: { ...action().recommendation, source_record_id: 999, evidence: { ai_daily_report_id: 999 } } }] };
  await finishSource(p, call); assert.equal(p.sandbox.aiDailyReport.value.id, 920); assert.equal(p.latest().length, 0);
  assert.match(p.text(), /来源范围已变化，当前显示未替换/);
});
await scenario('older AI activation and refresh waiting for static readiness cannot take ownership from a new source request', async p => {
  const release = p.holdPrepare(); p.sandbox.currentPage.value = 'ai-daily-report'; await tick();
  const refresh = p.nodes().find(n => n.type === 'button' && n.props.onClick === p.sandbox.api.loadAiDailyReport);
  assert.ok(refresh); const oldRead = p.click(refresh); await tick(); assert.equal(refresh.props.disabled, true);
  p.sandbox.currentPage.value = 'ops-track'; await tick(); assert.equal(await p.click(p.button('operation-ai-daily-source')), true); await tick();
  release(); await oldRead; await until(() => p.details().length === 1, 'only source detail'); await finishSource(p, p.details()[0]);
  assert.equal(p.latest().length, 0); assert.equal(p.sandbox.aiDailyReport.value.id, 901); assert.equal(p.sandbox.operationLoading.value.aiDailyReport, false);
});
}
const evidence = { source_reads: reads, original_sections: sections, original_ancestors: ancestors, attempts,
  boundary: 'Starts with a legal already-read execution-flow row projection. Original 17 source control, main source handler, original page lifecycle prefix/AI activation/runPageLoadOnce, original request/auth/coordinator and 16 source/date/summary controls. Both whole templates compile. Original fact-gate watcher remains, with explicit synthetic transport failures. Returning to ops and row negatives are direct context boundaries; permission guards are source-reviewed, not a dynamic permission-denial case. Full helpers are initialized for the old latest path; new source validator independence is source-reviewed. No full app navigation/account/cold-full validation or real HTTP/DB/write/generation/approval.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(evidence, null, 2));
console.log(JSON.stringify({ source_reads: reads, cases: attempts.map(a => ({ name: a.name, pass: !!a.pass, requests: a.before_teardown?.calls.length })) }));
