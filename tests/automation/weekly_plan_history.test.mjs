import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash, webcrypto } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { baseParse, compile, parserOptions } from '@vue/compiler-dom';

const sourceRoot = process.argv.find(arg => arg.startsWith('--source-root='))?.slice(14);
const evidencePath = process.argv.find(arg => arg.startsWith('--evidence='))?.slice(11);
const readers = [], sections = [], attempts = [];
const sha = value => createHash('sha256').update(value).digest('hex');
const read = path => { const actual = resolve(sourceRoot || '.', path);
  const text = readFileSync(actual, 'utf8'); readers.push({ path, resolved_path: actual, sha256: sha(text).toUpperCase() }); return text; };
const raw = { main: read('public/app-main.js'), home: read('public/home-static.js'), system: read('public/system-static.js'),
  template: read('resources/frontend/templates/fragments/23a-page-compass-summary.html') };
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start);
  const text = main.slice(a, b); sections.push({ start, end, sha256: sha(text).toUpperCase() }); return text; };
const methods = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const terminalAuthFailureReason =', '            const applyAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const runPageLoadOnce ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'), 'const apiRequest = request;',
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  section('            const downloadBlob =', '            const buildCtripBusinessCanvas ='),
  section('            const homeWeeklyOperatingPlanController =', '            const operationExecutionStageFilter ='),
].join('\n');
const ast = baseParse(raw.template, parserOptions), ancestors = [];
const keep = (node, parents = []) => {
  if (node.type === 1 && node.tag === 'home-operating-orchestration') {
    ancestors.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node;
  }
  const children = (node.children || []).map(child => keep(child, parents.concat(node.type === 1 ? [node] : []))).filter(Boolean);
  return children.length ? { ...node, children } : null;
};
compile(raw.template, { mode: 'function', prefixIdentifiers: true });
const render = new Function('Vue', compile(keep(ast), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const canonical = value => Array.isArray(value) ? value.map(canonical) : value && typeof value === 'object'
  ? Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])])) : value;
const period = weekEnd => { const date = new Date(`${weekEnd}T00:00:00Z`); date.setUTCDate(date.getUTCDate() - 6); return date.toISOString().slice(0, 10); };
// Same normalized v2 public consumer shape and canonical fingerprint keys as the
// accepted weekly_plan_download test. Source-backed synthetic snapshot, no PHP/DB.
function snapshot({ id = 301, hotelId = 80, weekEnd = '2026-09-06', version = 2 } = {}) {
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
  const keys = ['contract_version', 'tenant_id', 'hotel_id', 'week_start', 'week_end', 'version_no', 'status', 'source_digest',
    'daily_run_refs', 'broadcast_snapshot_refs', 'lifecycle_summary', 'repeated_gap_summary', 'selected_focus', 'missing_days', 'final_text_sha256', 'generation_trigger'];
  value.snapshot_fingerprint = sha(JSON.stringify(canonical(Object.fromEntries(keys.map(key => [key, value[key]])))));
  return value;
}
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
async function until(predicate, label) { for (let i = 0; i < 30 && !predicate(); i++) await tick(); assert.ok(predicate(), label); }
const marker = 'synthetic-session-only-round136';
function host() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, open: false });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: { createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.text = text; n.children = []; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const at = anchor ? parent.children.indexOf(anchor) : -1; if (at < 0) parent.children.push(n); else parent.children.splice(at, 0, n); },
    remove, patchProp: (n, key, old, next) => { n.props[key] = next; if (key === 'style') n.style = next || {}; } } };
}
function harness(attempt) {
  const requests = [], diagnostics = [], errors = [], warnings = [], screens = [], downloads = [], timers = [], urls = new Map();
  attempt.observations = { requests, diagnostics, errors, warnings, screens, downloads };
  const sandbox = { ...Vue, window: { Vue }, URL: class extends URL {}, URLSearchParams, Headers, FormData, Response, Blob,
    TextEncoder, crypto: webcrypto, AbortController, DOMException, Date, Intl, structuredClone, setTimeout, clearTimeout,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 1, pageLoadRequests: new Map(),
    token: Vue.ref(marker), authContext: Vue.ref({ tenantId: 9, hotelId: 80, platform: 'all', permissionStatus: 'allowed' }),
    // Public consumer projection of operation_decision's allowed GET rule;
    // no login/credential fixture and no replacement of protectedRequestDenial.
    user: Vue.ref({ id: 136, tenant_id: 9, hotel_id: 80, is_super_admin: false, protected_access: [
      { key: 'operation_decision', module: 'operation_decision', allowed: true, reason: 'available',
        paths: [{ path: 'api/operating-opportunities', methods: ['GET'] }] },
    ] }),
    permittedHotels: Vue.ref([{ id: 80, tenant_id: 9 }, { id: 81, tenant_id: 9 }]),
    currentPage: Vue.ref('compass'), filterReportHotel: Vue.ref('80'), operationHotelOptions: Vue.ref([{ id: 80 }, { id: 81 }]),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'), shanghaiToday: () => '2026-09-20',
    operationErrorMessage: (_error, fallback) => fallback, normalizeCanonicalPage: value => value,
    readRequestCooldown: { check: () => null, record() {} },
    console: { error: (...args) => diagnostics.push(args.map(value => value?.message || String(value))), warn: (...args) => warnings.push(args.map(String)) },
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } },
      createElement(tag) { assert.equal(tag, 'a'); return { style: {}, isConnected: false,
        click() { assert.ok(urls.has(this.href)); downloads.push({ filename: this.download, blob: urls.get(this.href) }); }, remove() { this.isConnected = false; } }; },
      body: { appendChild(link) { link.isConnected = true; } } },
    fetch(url, options) {
      const parsed = new URL(url), endpoint = parsed.pathname.replace(/^\/api/, '');
      assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
      assert.match(endpoint, /^\/operating-opportunities\/weekly-plan\/(latest|snapshots\/\d+)$/);
      assert.equal(new Headers(options.headers).get('Authorization'), marker); assert.ok(options.signal, 'original GET AbortSignal');
      return new Promise((resolveRequest, rejectRequest) => {
        const call = { endpoint, query: Object.fromEntries(parsed.searchParams), method: 'GET', settled: false, aborted: false,
          resolve(data) { assert.equal(call.settled, false); call.settled = true; call.response = clone(data); resolveRequest(new Response(JSON.stringify({ code: 200, message: 'success', data }), { status: 200 })); },
          reject(teardown = false) { if (call.settled) return; call.settled = true; call.teardown_only = teardown; call.failure = 'controlled transport TypeError'; rejectRequest(new TypeError('Failed to fetch')); } };
        requests.push(call); options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; rejectRequest(new DOMException('Aborted', 'AbortError')); } });
      });
    } };
  sandbox.URL.createObjectURL = blob => { const value = `blob:synthetic/${urls.size}`; urls.set(value, blob); return value; };
  sandbox.URL.revokeObjectURL = value => urls.delete(value);
  sandbox.window.setTimeout = (fn, ms) => { assert.equal(ms, 60000); timers.push(fn); return timers.length; };
  const scope = Vue.effectScope();
  attempt.capture = () => ({ requests: requests.map(({ resolve, reject, ...row }) => row), diagnostics, errors, warnings, screens });
  attempt.cleanup = async () => { scope.stop(); requests.filter(row => !row.settled).forEach(row => row.reject(true)); timers.forEach(fn => fn()); await tick(); };
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); vm.runInContext(raw.home, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  sandbox.homeStatic = sandbox.window.SUXI_HOME_STATIC;
  scope.run(() => vm.runInContext(methods + '\nglobalThis.ui=homeWeeklyOperatingPlanController;globalThis.coordinator=()=>({active:coordinatedGetActiveCount,inflight:coordinatedGetRequests.size,queued:coordinatedGetQueue.length,page_reads:pageLoadRequests.size});', sandbox));
  const memory = host(), renderer = Vue.createRenderer(memory.options);
  const app = renderer.createApp({ components: { HomeOperatingOrchestration: sandbox.homeStatic.HomeOperatingOrchestration },
    setup: () => ({ ...sandbox.ui, homeWeeklyOperatingPlanController: sandbox.ui, currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel, currentClockText: '12:00',
      homeOperatingScheduleModel: { stateCode: 'ready', stateLabel: '独立任务列表', items: [] }, homeOperatingScheduleLoading: false,
      loadHomeOperatingSchedule: () => { throw new Error('Unrelated overall refresh'); }, openHomeOperatingScheduleItem: () => { throw new Error('Task navigation forbidden'); },
      openHomeOperatingScheduleAll: () => { throw new Error('Task navigation forbidden'); } }), render });
  app.config.errorHandler = error => errors.push(error.message); app.config.warnHandler = warning => warnings.push(warning);
  const walk = (node, output = []) => { output.push(node); node.children.forEach(child => walk(child, output)); return output; };
  const nodes = () => walk(memory.root), content = node => node.text + node.children.map(content).join('');
  const find = id => nodes().find(node => node.props['data-testid'] === id);
  const isVisible = node => { for (let p = node.parent; p; p = p.parent) if (p.type === 'details' && !p.open) return false; return true; };
  const control = id => { const node = find(id); assert.ok(node, 'original weekly history control exists');
    assert.equal(isVisible(node), true, 'original details must be opened first'); assert.ok(!node.props.disabled, 'disabled original control cannot receive event'); return node; };
  const openDetails = () => { const summary = nodes().find(node => node.type === 'summary' && node.parent?.type === 'details' && String(node.parent.props.class).includes('home-weekly-fold'));
    assert.ok(summary, 'original weekly summary'); summary.parent.open = true; };
  const readSnapshot = () => ({ requests: requests.map(({ resolve, reject, ...row }) => row), diagnostics, errors, warnings, screens,
    coordinator: clone(sandbox.coordinator()), object_urls: urls.size, downloads: downloads.map(row => ({ filename: row.filename, size: row.blob.size })),
    text: content(memory.root), plan: clone(sandbox.ui.homeWeeklyOperatingPlan.value), selected_week: sandbox.ui.homeWeeklyOperatingPlanWeekEnd?.value,
    loading: sandbox.ui.homeWeeklyOperatingPlanLoading.value, error: sandbox.ui.homeWeeklyOperatingPlanError.value });
  attempt.capture = readSnapshot; attempt.cleanup = async () => { app.unmount(); scope.stop(); requests.filter(row => !row.settled).forEach(row => row.reject(true)); timers.forEach(fn => fn()); await tick(); };
  app.mount(memory.root);
  return { sandbox, requests, diagnostics, errors, warnings, downloads, nodes, find, control, isVisible, openDetails,
    text: () => content(memory.root), screen: label => screens.push({ label, text: content(memory.root) }) };
}
async function choose(p, date) { p.control('home-weekly-plan-week-end').props.onChange({ target: { value: date } }); await tick();
  assert.equal(p.sandbox.ui.homeWeeklyOperatingPlanWeekEnd.value, date); assert.doesNotMatch(p.text(), /原保存重点 \d/); }
async function start(p, id = 'home-weekly-plan-read') { const before = p.requests.length; p.control(id).props.onClick({ type: 'click' });
  await until(() => p.requests.length > before, 'original read handler starts GET'); return p.requests.at(-1); }
async function finish(p, call, data) { call.resolve(data); await until(() => !p.sandbox.ui.homeWeeklyOperatingPlanLoading.value && p.sandbox.coordinator().active === 0, 'original read settled'); await tick(); }
async function scenario(name, body) { await test(name, async () => { const attempt = { name }; attempts.push(attempt); let p;
  try { p = harness(attempt); await tick(); p.openDetails(); await body(p);
    assert.deepEqual(p.errors, []); assert.deepEqual(p.warnings, []);
    assert.deepEqual(clone(p.sandbox.coordinator()), { active: 0, inflight: 0, queued: 0, page_reads: 0 });
    assert.ok(p.requests.every(row => row.settled && !row.teardown_only));
    assert.equal(p.diagnostics.length, p.requests.filter(row => row.failure).length);
    p.diagnostics.forEach(row => { assert.equal(row[0], 'API请求失败:'); assert.match(row[1], /^\/operating-opportunities\/weekly-plan\/latest\?/); assert.equal(row[2], 'Failed to fetch'); });
    attempt.pass = true;
  } catch (error) { attempt.error = error.stack; throw error; }
  finally { if (attempt.capture) attempt.before_teardown = attempt.capture(); if (attempt.cleanup) await attempt.cleanup();
    if (attempt.capture) attempt.after_teardown = attempt.capture(); delete attempt.capture; delete attempt.cleanup; } }); }

if (!process.argv.includes('--prepare-only')) {
await scenario('original historical week controls read and download the same saved version then return to latest complete week', async p => {
  assert.equal(p.control('home-weekly-plan-week-end').props.max, '2026-09-19');
  assert.match(p.text(), /周度计划 · 尚未读取/); assert.doesNotMatch(p.text(), /尚未生成/);
  await choose(p, ''); assert.equal(p.find('home-weekly-plan-read').props.disabled, true);
  // Direct caller validation only: never click the disabled empty-date button or
  // claim the native date picker accepted an invalid calendar value.
  assert.equal(await p.sandbox.ui.loadHomeWeeklyOperatingPlan({ weekEnd: '2026-02-30' }), false); assert.equal(p.requests.length, 0);
  for (const weekEnd of ['2026-09-20', '2026-09-21']) {
    await choose(p, weekEnd); p.control('home-weekly-plan-read').props.onClick({ type: 'click' }); await tick();
    assert.equal(p.requests.length, 0); assert.match(p.text(), /请选择有效且不晚于昨天的截止日/);
  }
  await choose(p, '2026-09-19'); assert.match(p.text(), /所选周尚未读取/); const historical = await start(p);
  assert.equal(historical.query.hotel_id, '80'); assert.equal(historical.query.week_end, '2026-09-19');
  const saved = snapshot({ weekEnd: '2026-09-19' }); await finish(p, historical, saved);
  for (const value of ['原周期 2026-09-13 至 2026-09-19', '快照 #301', '版本 2', '保存时汇总：待审批 1', '不代表当前实时任务进度']) assert.ok(p.text().includes(value), value);
  const exact = await start(p, 'home-weekly-plan-download'); assert.equal(exact.endpoint, '/operating-opportunities/weekly-plan/snapshots/301'); assert.equal(exact.query.hotel_id, '80');
  assert.equal(p.find('home-weekly-plan-week-end').props.disabled, true); exact.resolve(clone(saved));
  await until(() => !p.sandbox.ui.homeWeeklyOperatingPlanDownloading.value && p.downloads.length === 1, 'original fixed version download settled');
  assert.equal(p.downloads[0].filename, '周计划-酒店80-2026-09-13_2026-09-19-快照301-v2.txt');
  const body = await p.downloads[0].blob.text(); assert.equal(body.split('----- 以下为已保存原正文 -----\n\n')[1], saved.final_text);
  const latest = await start(p, 'home-weekly-plan-latest'); assert.equal(latest.query.week_end, '2026-09-13'); assert.doesNotMatch(p.text(), /原保存重点 301/);
  await finish(p, latest, snapshot({ id: 302, weekEnd: '2026-09-13', version: 3 })); assert.match(p.text(), /快照 #302 · 版本 3/);
  assert.equal(p.requests.length, 3); p.screen('recent week restored');
});
await scenario('selected-week failure missing snapshot and wrong identity remain distinct and recover through original read button', async p => {
  await choose(p, '2026-09-06'); await finish(p, await start(p), snapshot());
  await choose(p, '2026-08-30'); const failed = await start(p); failed.reject();
  await until(() => !p.sandbox.ui.homeWeeklyOperatingPlanLoading.value && p.sandbox.coordinator().active === 0, 'controlled failure closed');
  assert.match(p.text(), /所选周计划读取失败/); assert.doesNotMatch(p.text(), /原保存重点|暂无已保存周计划|Failed to fetch/);
  await finish(p, await start(p), { contract_version: 'weekly_operating_plan.v2', tenant_id: 9, hotel_id: 80,
    week_start: '2026-08-24', week_end: '2026-08-30', status: 'not_generated', readback_verified: false });
  assert.match(p.text(), /所选周期暂无已保存周计划/); assert.equal(p.find('home-weekly-plan-download'), undefined);
  assert.match(p.text(), /周度计划 · 所选周期暂无保存/);
  await finish(p, await start(p), snapshot({ id: 303, hotelId: 81, weekEnd: '2026-08-30' }));
  assert.match(p.text(), /所选周计划读取失败/); assert.doesNotMatch(p.text(), /原保存重点|暂无已保存周计划/);
  await finish(p, await start(p), snapshot({ id: 304, weekEnd: '2026-08-30' })); assert.match(p.text(), /原保存重点 304/);
  const retained = p.sandbox.ui.homeWeeklyOperatingPlan.value;
  const refresh = await start(p); assert.match(p.text(), /仍为上次已保存快照/); refresh.reject();
  await until(() => !p.sandbox.ui.homeWeeklyOperatingPlanLoading.value && p.sandbox.coordinator().active === 0, 'same-period refresh failure closed');
  assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, retained);
  assert.match(p.text(), /刷新失败，保留同周期上次已保存快照/); assert.match(p.text(), /原保存重点 304/);
  assert.match(p.text(), /周度计划 · 读取失败/); assert.doesNotMatch(p.text(), /Failed to fetch|暂无已保存周计划/);
  assert.equal(p.requests.length, 6); p.screen('same-period refresh failure preserves the exact prior snapshot');
});
await scenario('late week reads cannot replace newer scope and original context guards clear hotel permission or page-invalid results', async p => {
  await choose(p, '2026-09-06'); const a = await start(p); await choose(p, '2026-08-30'); const b = await start(p);
  b.resolve(snapshot({ id: 304, weekEnd: '2026-08-30' })); await until(() => !p.sandbox.ui.homeWeeklyOperatingPlanLoading.value, 'B committed');
  a.resolve(snapshot()); await until(() => p.sandbox.coordinator().active === 0, 'late A settled'); assert.match(p.text(), /原保存重点 304/); assert.doesNotMatch(p.text(), /原保存重点 301/);
  await choose(p, '2026-09-06'); const oldHotel = await start(p);
  // Context controls, not a claim of clicking the unrelated toolbar or login UI.
  p.sandbox.filterReportHotel.value = '81'; await tick(); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, null);
  const newHotel = await start(p); assert.equal(newHotel.query.hotel_id, '81'); assert.equal(newHotel.query.week_end, '2026-09-13');
  oldHotel.resolve(snapshot()); await tick(); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlanLoading.value, true); assert.doesNotMatch(p.text(), /原保存重点 301/);
  await finish(p, newHotel, snapshot({ id: 401, hotelId: 81, weekEnd: '2026-09-13' }));
  p.sandbox.authContext.value.permissionStatus = 'denied'; await tick(); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, null); assert.doesNotMatch(p.text(), /原保存重点 401/);
  p.sandbox.authContext.value.permissionStatus = 'allowed'; const leaving = await start(p);
  p.sandbox.currentPage.value = 'ops-track'; await tick(); assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, null);
  leaving.resolve(snapshot({ id: 402, hotelId: 81, weekEnd: '2026-09-13' })); await until(() => p.sandbox.coordinator().active === 0, 'departed page read settled');
  assert.equal(p.sandbox.ui.homeWeeklyOperatingPlan.value, null); assert.equal(p.find('home-weekly-plan-read'), undefined);
  assert.equal(p.requests.length, 5); p.screen('departed page keeps no historical result');
});
}
const result = { source_readers: readers, sections, template_ancestors: ancestors, attempts,
  boundary: 'Original home component/controller/main context/request/auth/coordinator and Blob download, Vue memory renderer with original details summary opening model. No native browser, account, real HTTP/PHP/DB or writes. Hotel/permission/page mutations are direct reactive context controls. No task navigation.' };
if (evidencePath) writeFileSync(evidencePath, JSON.stringify(result, null, 2));
console.log(JSON.stringify({ source_readers: readers, attempts: attempts.map(row => ({ name: row.name, pass: !!row.pass, error: row.error, requests: row.before_teardown?.requests.length })) }));
