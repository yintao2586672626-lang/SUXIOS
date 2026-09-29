import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Native details opening is modelled through its actual summary; no browser/HTTP/DB.
// The existing apiRequest boundary is injected. Main factory/auth/page-policy,
// original weekly controller/component and Blob resource helper execute unchanged.
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourceRoot = path.resolve(process.argv.find(value => value.startsWith('--source-root='))?.slice(14) || repository);
const readers = [];
const sha = value => createHash('sha256').update(value).digest('hex');
const read = relative => {
  const resolved = path.join(sourceRoot, relative), data = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: sha(data).toUpperCase() }); return data.toString('utf8');
};
const main = read('public/app-main.js'), home = read('public/home-static.js');
const template = read('resources/frontend/templates/fragments/23a-page-compass-summary.html');
const cut = (source, start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a); assert.ok(a >= 0 && b > a, start); return source.slice(a, b);
};
const clone = value => JSON.parse(JSON.stringify(value));
const canonical = value => Array.isArray(value) ? value.map(canonical)
  : value && typeof value === 'object' ? Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])])) : value;
function snapshot() {
  // Fixed normalized v2 consumer fixture with the service's canonical fingerprint
  // field set. It does not claim this turn executed buildDraft/save/readExact.
  const final_text = [
    '宿析OS周度经营计划', '门店：合成酒店 A', '周期：2026-09-07 至 2026-09-13',
    '自动事项覆盖：3/7；可信播报覆盖：4/7。', '待审批：0；待复盘：2；已复盘：1。',
    '任务治理：完成填报 0；人工核实 1；已复盘观察 1。前后变化不证明因果效果。',
    '下周唯一重点：补齐缺失日期来源', '选择依据：原周快照仍有日期缺失。',
    '效果学习：证据不足；选择关系：insufficient_independent_reviewed_observations。',
    '缺失日期数：4。缺失保持缺失，不以0或旧报告补齐。',
    '边界：本计划只整理已保存事实和流程状态，不自动审批、执行、发送消息或写入OTA/PMS。',
  ].join('\n');
  const value = { contract_version: 'weekly_operating_plan.v2', snapshot_id: 301, tenant_id: 9, hotel_id: 80,
    week_start: '2026-09-07', week_end: '2026-09-13', version_no: 2, status: 'partial',
    generated_at: '2026-09-14 03:30:00', generation_trigger: 'synthetic_readonly_fixture',
    source_digest: sha('synthetic source identity; no business writes'),
    daily_run_refs: [701, 702, 703].map(id => `operating_opportunity_runs#${id}`),
    broadcast_snapshot_refs: [801, 802, 803, 804].map(id => `ai_daily_report_broadcast_snapshots#${id}`), repeated_gap_summary: [],
    lifecycle_summary: { pending_approval: 0, approved_or_executing: 0, blocked: 0, review_pending: 2, reviewed: 1,
      task_workflow: { status: 'ready', task_completed: 0, execution_verified: 1, reviewed: 1 } },
    selected_focus: { type: 'coverage_gap', title: '补齐缺失日期来源', reason: '原周快照仍有日期缺失。' },
    missing_days: { daily_priority: ['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'], trusted_broadcast: ['2026-09-11', '2026-09-12', '2026-09-13'] },
    final_text, final_text_sha256: sha(final_text), readback_verified: true, external_write_count: 0, external_message_count: 0 };
  const fingerprintFields = ['contract_version', 'tenant_id', 'hotel_id', 'week_start', 'week_end', 'version_no', 'status', 'source_digest',
    'daily_run_refs', 'broadcast_snapshot_refs', 'lifecycle_summary', 'repeated_gap_summary', 'selected_focus', 'missing_days', 'final_text_sha256', 'generation_trigger'];
  value.snapshot_fingerprint = sha(JSON.stringify(canonical(Object.fromEntries(fingerprintFields.map(key => [key, value[key]])))));
  return value;
}
const walk = (node, parents = []) => Array.isArray(node) ? node.flatMap(child => walk(child, parents)) : !node || typeof node !== 'object' ? []
  : [{ node, parents }, ...(Array.isArray(node.children) ? node.children.flatMap(child => walk(child, [...parents, node])) : [])];
const className = node => Array.isArray(node.props?.class) ? node.props.class.join(' ') : String(node.props?.class || '');
const tick = async () => { await Promise.resolve(); await Vue.nextTick(); };
function harness(t) {
  let tree, expanded = false, pending = 0, downloadPromise;
  const downloads = [], requests = [], errors = [], timers = [], urls = new Map();
  const controllerScope = Vue.effectScope();
  t.after(() => {
    controllerScope.stop();
    timers.forEach(timer => { assert.equal(timer.ms, 60000); timer.fn(); });
    assert.equal(pending, 0); assert.equal(urls.size, 0); assert.deepEqual(errors, []);
    t.diagnostic(JSON.stringify({ sourceReaders: readers, requests: requests.map(({ url, settled }) => ({ url, settled })),
      blobs: downloads.length, pending, objectUrls: urls.size, vueErrors: errors.length, evidence: 'actual controller/component and main wiring; native summary opening modeled; injected apiRequest; no HTTP/PHP/DB/browser' }));
  });
  const sandbox = { ...Vue, window: { Vue }, Blob, URL: class extends URL {}, URLSearchParams, TextEncoder, crypto: webcrypto,
    authSessionEpoch: 1, pageRequestGeneration: 1, token: Vue.ref('synthetic-session-not-a-credential'),
    currentPage: Vue.ref('compass'), filterReportHotel: Vue.ref('80'), authContext: Vue.ref({ tenant_id: 9, permissionStatus: 'allowed' }),
    operationHotelOptions: Vue.ref([{ id: 80 }]),
    revenueAiBusinessDate: Vue.ref('2026-09-20'), coreOperationsTargetDate: Vue.ref('2026-09-20'),
    currentBusinessRequestContext: () => ({ tenant_id: 9, system_hotel_id: 80, business_date: '2026-09-20' }),
    shanghaiToday: () => '2026-09-20', operationErrorMessage: (_error, fallback) => fallback,
    apiRequest(url, options) {
      assert.match(url, /^\/operating-opportunities\/weekly-plan\/(?:latest|snapshots\/\d+)\?/);
      assert.equal(options.requestPolicy.pageKey, 'compass'); assert.equal(options.method, undefined); assert.equal(options.body, undefined);
      pending++; return new Promise((resolve, reject) => requests.push({ url, settled: false,
        resolve(value) { this.settled = true; pending--; resolve(value); }, reject(error) { this.settled = true; pending--; reject(error); } }));
    },
    fetch: () => { throw new Error('Real HTTP forbidden'); },
    document: { createElement(tag) { assert.equal(tag, 'a'); return { style: {}, isConnected: false,
      click() { assert.ok(urls.has(this.href)); downloads.push({ filename: this.download, blob: urls.get(this.href) }); }, remove() { this.isConnected = false; } }; },
      body: { appendChild(link) { link.isConnected = true; } } },
  };
  sandbox.URL.createObjectURL = blob => { const url = `blob:synthetic/${urls.size}`; urls.set(url, blob); return url; };
  sandbox.URL.revokeObjectURL = url => urls.delete(url);
  sandbox.window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  vm.runInNewContext(home, sandbox); sandbox.homeStatic = sandbox.window.SUXI_HOME_STATIC;
  const methods = [cut(main, '            const captureAuthSession =', '            const createDefaultAuthContext ='),
    cut(main, '            const currentPageReadPolicy =', '            const buildPageLoadScopeToken ='),
    cut(main, '            const isPageLoadPolicyCurrent =', '            const cancelPageLoadRequests ='),
    cut(main, '            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
    cut(main, '            const downloadBlob =', '            const buildCtripBusinessCanvas ='),
    cut(main, '            const homeWeeklyOperatingPlanController =', '            const operationExecutionStageFilter =')];
  controllerScope.run(() => vm.runInNewContext(methods.join('\n') + '\nglobalThis.ui = homeWeeklyOperatingPlanController;', sandbox));
  // Keep the original component and its entire original template ancestry.
  const keep = node => {
    if (node.type === 1 && node.tag === 'home-operating-orchestration') return node;
    const children = (node.children || []).map(keep).filter(Boolean); return children.length ? { ...node, children } : null;
  };
  const render = new Function('Vue', compile(keep(parse(template)), { mode: 'function', prefixIdentifiers: true }).code)(Vue);
  const original = sandbox.homeStatic.HomeOperatingOrchestration;
  const observed = { ...original, render() { tree = original.render.call(this); return tree; } };
  const liveModel = Vue.ref({ stateCode: 'ready', stateLabel: '当前实时任务', date: '2026-09-20', scopeHotelName: '当前合成酒店',
    items: [{ title: 'LIVE_ONLY_TASK_999', statusLabel: '实时已完成999', hotelName: '当前合成酒店', businessDateText: '2026-09-20' }] });
  async function renderPage() {
    const app = Vue.createSSRApp({ components: { HomeOperatingOrchestration: observed },
      setup: () => ({ ...sandbox.ui, homeWeeklyOperatingPlanController: sandbox.ui, currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel,
        homeOperatingScheduleModel: liveModel, homeOperatingScheduleLoading: false, currentClockText: '12:00',
        loadHomeOperatingSchedule: () => { throw new Error('Unrelated daily refresh is not part of export'); },
        openHomeOperatingScheduleItem: () => { throw new Error('Task open not requested'); }, openHomeOperatingScheduleAll: () => { throw new Error('Task list not requested'); },
        downloadHomeWeeklyOperatingPlan: () => { downloadPromise = sandbox.ui.downloadHomeWeeklyOperatingPlan(); return downloadPromise; } }), render });
    app.config.warnHandler = message => errors.push(message); app.config.errorHandler = error => errors.push(error.message);
    const html = await renderToString(app); assert.deepEqual(errors, []); return { html, nodes: walk(tree) };
  }
  async function openDetails() {
    const { nodes } = await renderPage();
    const summary = nodes.find(({ node, parents }) => node.type === 'summary' && parents.some(parent => parent.type === 'details' && className(parent).includes('home-weekly-fold')));
    assert.ok(summary, 'Actual compact weekly details summary exists');
    expanded = true; // Native summary toggle semantics; no synthetic business handler.
  }
  async function button() {
    const { nodes } = await renderPage();
    const match = nodes.find(({ node }) => node.props?.['data-testid'] === 'home-weekly-plan-download');
    if (!match) return null;
    assert.ok(match.parents.some(parent => parent.type === 'details' && className(parent).includes('home-weekly-fold')));
    return { control: match.node, visible: expanded, disabled: !!match.node.props.disabled || match.parents.some(parent => parent.props?.disabled) };
  }
  async function load(data) {
    const action = sandbox.ui.loadHomeWeeklyOperatingPlan(); await tick();
    const req = requests.at(-1); assert.equal(req.url, '/operating-opportunities/weekly-plan/latest?hotel_id=80&week_end=2026-09-13');
    req.resolve({ code: 200, data }); assert.equal(await action, true);
  }
  async function start() {
    const buttonState = await button(); assert.ok(buttonState, 'Saved weekly plan offers a local download (baseline has none)');
    assert.equal(buttonState.visible, true); assert.equal(buttonState.disabled, false);
    buttonState.control.props.onClick(); await tick(); assert.ok(downloadPromise); return { promise: downloadPromise };
  }
  return { sandbox, liveModel, requests, downloads, load, openDetails, button, start, renderPage };
}

test('original weekly details -> fixed saved version read -> original body and metadata TXT', async t => {
  const h = harness(t), saved = snapshot(); await h.load(saved);
  const initially = await h.button(); assert.ok(initially, 'Saved weekly plan has an export button'); assert.equal(initially.visible, false);
  await h.openDetails(); const before = clone(h.sandbox.ui.homeWeeklyOperatingPlan.value), action = await h.start();
  assert.equal((await h.button()).disabled, true); assert.equal(h.requests.length, 2);
  assert.equal(h.requests[1].url, '/operating-opportunities/weekly-plan/snapshots/301?hotel_id=80');
  h.requests[1].resolve({ code: 200, data: clone(saved) }); assert.equal(await action.promise, true);
  assert.equal(h.downloads.length, 1); const file = h.downloads[0], content = await file.blob.text();
  assert.equal(file.blob.type, 'text/plain;charset=utf-8'); assert.equal(file.filename, '周计划-酒店80-2026-09-07_2026-09-13-快照301-v2.txt');
  for (const text of ['酒店 ID：80；快照 ID：301；版本：2', '覆盖周：2026-09-07 至 2026-09-13', '生成时间：2026-09-14 03:30:00',
    '保存时状态：来源覆盖不完整', '不代表下载时的实时任务或员工排班', '待审批：0；待复盘：2；已复盘：1。', '不自动审批、执行、发送消息或写入OTA/PMS']) assert.ok(content.includes(text), text);
  assert.equal(content.split('----- 以下为已保存原正文 -----\n\n')[1], saved.final_text);
  assert.equal(sha(content.split('----- 以下为已保存原正文 -----\n\n')[1]), saved.final_text_sha256);
  assert.equal(content.includes('LIVE_ONLY_TASK_999'), false); assert.equal(content.includes('实时已完成999'), false);
  assert.deepEqual(clone(h.sandbox.ui.homeWeeklyOperatingPlan.value), before); assert.equal((await h.button()).disabled, false);
});

test('failed or mismatched exact reads never download; original snapshot survives explicit retries', async t => {
  const h = harness(t), saved = snapshot(); await h.load(saved); await h.openDetails();
  const changes = [null, value => { value.final_text += '\nTAMPERED'; }, ...['snapshot_id', 'tenant_id', 'hotel_id', 'version_no'].map(key => value => { value[key]++; }),
    value => { value.week_start = '2026-09-08'; }, value => { value.week_end = '2026-09-14'; }];
  for (const change of changes) {
    const action = await h.start(), request = h.requests.at(-1), wrong = clone(saved);
    if (change) { change(wrong); request.resolve({ code: 200, data: wrong }); } else request.reject(new Error('Synthetic private failure MUST_NOT_LEAK'));
    assert.equal(await action.promise, false); assert.equal(h.downloads.length, 0);
    assert.deepEqual(clone(h.sandbox.ui.homeWeeklyOperatingPlan.value), saved);
    assert.equal(h.sandbox.ui.homeWeeklyOperatingPlanError.value, '');
    assert.equal(h.sandbox.ui.homeWeeklyOperatingPlanDownloadError.value, '该版本周计划下载失败，请重试；原快照仍保留。');
    const page = await h.renderPage(); assert.ok(page.html.includes('原快照仍保留')); assert.equal(page.html.includes('MUST_NOT_LEAK'), false);
    assert.equal((await h.button()).disabled, false);
  }
  const retry = await h.start(); h.requests.at(-1).resolve({ code: 200, data: clone(saved) }); assert.equal(await retry.promise, true);
  assert.equal(h.downloads.length, 1); assert.equal(h.sandbox.ui.homeWeeklyOperatingPlanDownloadError.value, '');
  assert.equal(h.requests.length, 10);
});

test('not-generated scoped state has no download action', async t => {
  const h = harness(t); await h.load({ hotel_id: 80, week_start: '2026-09-07', week_end: '2026-09-13', status: 'not_generated', readback_verified: false });
  await h.openDetails(); assert.equal(await h.button(), null); assert.equal(h.requests.length, 1); assert.equal(h.downloads.length, 0);
  assert.equal(h.sandbox.ui.homeWeeklyOperatingPlan.value, null);
});
