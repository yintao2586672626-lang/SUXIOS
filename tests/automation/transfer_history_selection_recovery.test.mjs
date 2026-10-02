import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { readFrontendTestSource, retiredFrontendManifest } from './helpers/retired_frontend_source.mjs';

const candidateRoot = process.env.TRANSFER_HISTORY_CANDIDATE_DIR || '';
const sourcePath = path => candidateRoot ? candidateRoot.replace(/\/$/, '') + '/' + path : path;
const files = {
  main: sourcePath('public/app-main.js'), system: 'public/system-static.js', simulation: sourcePath('public/simulation-static.js'),
  context: 'resources/frontend/templates/fragments/08-shared-transfer-context.html',
  pricing: 'resources/frontend/templates/fragments/09-page-asset-pricing.html',
  timing: 'resources/frontend/templates/fragments/10-page-timing-strategy.html',
  history: 'resources/frontend/templates/fragments/12-shared-transfer-history.html',
};
const raw = Object.fromEntries(Object.entries(files).map(([k, path]) => [k,
  ['context', 'pricing', 'timing', 'history'].includes(k) ? readFrontendTestSource(path) : readFileSync(path, 'utf8'),
]));
const retired = JSON.parse(readFileSync(new URL('../fixtures/retired-transfer-recovery-20261002.json', import.meta.url), 'utf8'));
const base = JSON.parse(readFileSync(new URL('../fixtures/retired-transfer-source-20261002.json', import.meta.url), 'utf8'));
const sha256 = text => createHash('sha256').update(text).digest('hex');
for (const fixture of [retired, base]) {
  assert.equal(fixture.runtime, false, 'historical transfer oracle cannot enter runtime');
  assert.equal(fixture.source_commit, 'd3e53e176d86d74f7868297125772116f666b3e4');
}
assert.equal(sha256(base.static_source), base.static_source_sha256);
assert.equal(base.static_source_sha256, retired.base_static_source_sha256);
for (const [key, text] of Object.entries(retired.segments)) {
  assert.equal(sha256(text), retired.segment_sha256[key], `pinned historical segment: ${key}`);
  assert.equal(Buffer.byteLength(text), retired.segment_bytes[key]);
}
const archivedSimulation = base.static_source + '\n' + retired.segments.staticSource;
const source = raw.main.replaceAll('\r\n', '\n');
const extract = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `source boundaries: ${start}`);
  return source.slice(a, b);
};
const withHistoricalTransferBinding = live => {
  const bindingEnd = '            ];';
  const resetEnd = '            };\n            const syncUnifiedHotelContexts =';
  assert.ok(live.includes(bindingEnd) && live.includes(resetEnd), 'active unified context boundaries');
  return live.replace(bindingEnd, '                ' + retired.segments.unifiedBinding + ',\n' + bindingEnd)
    .replace(resetEnd, '                ' + retired.segments.unifiedReset.replaceAll('\n', '\n                ') + '\n' + resetEnd);
};
const parts = {
  businessContext: extract('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  abortError: extract('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  pagePolicy: extract('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  coordinatorAndAdapter: extract('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  request: extract('            const request = async (', '            const apiRequest = request;'),
  hotelOptions: extract('            const reportHotelOptionExists =', '            const resolveDefaultReportHotelId ='),
  transferRefs: retired.segments.transferRefs,
  loadSource: retired.segments.loadSource,
  decisionLayers: retired.segments.decisionLayers,
  records: retired.segments.records,
  pageGuard: source.match(/const isStillOnRequestPage = \(requestPage\) => currentPage.value === requestPage;/)[0],
  unified: withHistoricalTransferBinding(extract('            const unifiedHotelContextBindings =', '            return {')),
};
test('historical transfer selection oracle stays archived and absent from live runtime', () => {
  assert.doesNotMatch(raw.main, /\b(?:loadTransferSource|loadTransferRecords|loadTransferDetail|reuseTransferRecord|transferPricingForm|transferSourceSnapshot)\b/);
  const live = vm.createContext({ window: {} });
  vm.runInContext(raw.simulation, live);
  for (const key of Object.keys(live.window.SUXI_SIMULATION_STATIC)) assert.doesNotMatch(key, /^(?:transfer|buildTransfer|createTransfer|resolveTransfer)/);
  for (const id of ['shared-transfer-context', 'page-asset-pricing', 'page-timing-strategy', 'shared-transfer-history']) {
    assert.equal(retiredFrontendManifest.fragments.find(fragment => fragment.id === id)?.runtime, false);
  }
});
const render = new Function('Vue', compile(raw.context + raw.pricing + raw.timing + raw.history, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const check = (value, description) => { assertions++; assert.ok(value, description); };
const clone = value => JSON.parse(JSON.stringify(value));

function harness({ delayStatic = false, page = 'asset-pricing' } = {}) {
  let resolveStatic, rejectStatic;
  const staticReady = delayStatic ? new Promise((resolve, reject) => { resolveStatic = resolve; rejectStatic = reject; }) : Promise.resolve();
  const requests = [], notices = [], otherResets = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException,
    structuredClone, ref: Vue.ref, computed: Vue.computed, watch: Vue.watch,
    console: { error() {}, warn() {} }, Date, setTimeout, clearTimeout,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, pageRequestGeneration: 0,
    currentPage: Vue.ref(page), filterReportHotel: Vue.ref('7'), operationAiDailySourceNavigation: { clear() {} },
    authContext: Vue.ref({ tenantId: 3, hotelId: 7, permissionStatus: 'allowed', platform: 'all' }),
    user: Vue.ref({ id: 901, is_super_admin: true }), token: Vue.ref(''),
    permittedHotels: Vue.ref([{ id: '7', name: 'Synthetic A', tenant_id: 3 }, { id: '8', name: 'Synthetic B', tenant_id: 3 }]),
    hotels: Vue.ref([]), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    operationYesterday: '2026-09-14',
    captureAuthSession: () => ({ epoch: sandbox.authSessionEpoch, token: '' }),
    isAuthSessionCurrent: session => session.epoch === sandbox.authSessionEpoch,
    isTerminalAuthFailureResponse: () => false,
    readRequestCooldown: { check: () => null, record() {} },
    showToast: (message, type) => notices.push({ message, type }),
    ensureSimulationStaticReady: () => staticReady,
    isCompassDataPage: () => false,
    clearActiveHotelDashboardSnapshots: () => otherResets.push('dashboard'),
    resetCoreOperationsScopedState: () => otherResets.push('operations'),
    resetHomeWeeklyOperatingPlan: () => otherResets.push('weekly'),
    fetch: (url, options) => new Promise((resolve, reject) => {
      check(url.startsWith('https://synthetic.invalid/api/transfer/'), 'only synthetic transfer requests are reachable');
      requests.push({ url, options, resolve, reject });
    }),
  };
  // Execute the active unified watcher/reset block with the pinned historical
  // transfer binding and reset statements. Unrelated modules use empty refs.
  const transferDeclared = new Set([...parts.transferRefs.matchAll(/const (\w+) =/g)].map(m => m[1]));
  for (const [, name] of parts.unified.matchAll(/\b(\w+)\.value/g)) {
    if (!(name in sandbox) && !transferDeclared.has(name)) sandbox[name] = Vue.ref({ hotel_id: '7' });
  }
  for (const [, name] of parts.unified.matchAll(/\b(\w+)\s*\+=\s*1/g)) sandbox[name] = 0;
  vm.createContext(sandbox);
  vm.runInContext(raw.system + '\n' + archivedSimulation, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = name => sandbox.appSystemStatic[name];
  sandbox.requireSimulationStatic = name => sandbox.window.SUXI_SIMULATION_STATIC[name];
  sandbox.formatDate = sandbox.appSystemStatic.formatDate;
  sandbox.transferTimingDataCheck = Vue.ref({});
  sandbox.hasSimulationStatic = Vue.ref(true);
  const effect = Vue.effectScope();
  effect.run(() => vm.runInContext(Object.values(parts).join('\n') + `
    globalThis.exposed = { transferDecisionLayerRows, loadTransferRecords, loadTransferDetail, reuseTransferRecord, transferPricingForm, transferPricingResult, transferPricingLoading,
      transferTimingForm, transferTimingResult, transferTimingLoading, transferDashboardResult, transferSelectedHotelId,
      transferSourceDate, transferSourceSnapshot, transferSourceLoading, transferRecords,
      transferRecordsLoading, transferHotelOptions, loadTransferSource, currentPageReadPolicy,
      unifiedHotelContextBindings };
  `, sandbox));
  const r = sandbox.exposed;
  r.transferSourceDate.value = '2026-09-15';
  r.transferPricingForm.value = { hotel_name: 'Synthetic initial draft', monthly_revenue: 12, room_count: 40, model_key: '', licenses_complete: true };
  r.transferTimingForm.value = { current_revenue: 12 };
  const state = Vue.proxyRefs({ ...r, currentPage: sandbox.currentPage,
    transferPricingFields: sandbox.window.SUXI_SIMULATION_STATIC.transferPricingFields,
    transferTimingCompareFields: sandbox.window.SUXI_SIMULATION_STATIC.transferTimingCompareFields,
    transferTimingNumberFields: sandbox.window.SUXI_SIMULATION_STATIC.transferTimingNumberFields,
    transferTimingDataFields: sandbox.window.SUXI_SIMULATION_STATIC.transferTimingDataFields,
    transferTimingDataCheck: {},
    transferAiModelOptions: [], transferSourceMetricRows: [], transferCurrentReadiness: null,
    handleTransferPricing() { throw new Error('Calculation outside archived read-only probe'); },
    handleTransferTiming() { throw new Error('Calculation outside archived read-only probe'); },
    handleTransferDashboard() { throw new Error('Calculation outside archived read-only probe'); },


    transferRecordTypeLabel: sandbox.requireSimulationStatic('transferRecordTypeLabel'),
    transferReadinessBadgeClass: sandbox.requireSimulationStatic('transferReadinessBadgeClass'),
    transferReadinessMissingText: () => '',
    transferExecutionIntentId: sandbox.requireSimulationStatic('executionIntentIdFromRecord'),
    riskBadgeClass: () => '', transferDecisionClass: () => '',
    createTransferExecutionIntent() { throw new Error('Execution intent outside read-only probe'); },
    archiveTransferRecord() { throw new Error('Archive outside read-only probe'); },
  });
  const inspect = async () => {
    let tree;
    const app = Vue.createSSRApp({ render() { tree = render(state, []); return tree; } });
    app.config.warnHandler = () => {};
    const html = await renderToString(app);
    const nodes = [];
    const walk = node => {
      if (Array.isArray(node)) return node.forEach(walk);
      if (!node || typeof node !== 'object') return;
      nodes.push(node); walk(node.children);
    };
    walk(tree);
    return { html, nodes };
  };
  return { ...r, sandbox, requests, notices, otherResets, inspect, resolveStatic, rejectStatic, stop: () => effect.stop() };
}

let assertions = 0;
const eq = (actual, expected, note) => { assertions++; assert.deepEqual(actual, expected, note); };
const ok = (actual, note) => { assertions++; assert.ok(actual, note); };
const backendSamples = {"detail_body":{"code":200,"data":{"id":701,"record_type":"pricing","hotel_id":7,"hotel_name":"Synthetic hotel","source_date":"2026-09-13","decision":"","risk_level":"","execution_intent_id":0,"created_by":9,"created_at":"2026-09-14 09:00:00","summary":{"monthly_net_profit":0,"reasonable_valuation":0,"timing_score":null,"suggested_action":null},"decision_readiness":{"stage":"manual_input_only","status_label":"仅手工测算","score":30,"ready_for_review":false,"decision_ready":false,"source_scope":"manual_input_only","record_type":"pricing","actual_days":0,"checks":[{"key":"hotel_bound","label":"系统酒店绑定","passed":true,"status":"ok","evidence":"已绑定到可访问酒店","next_action":"先选择系统酒店，避免脱离门店权限的孤立测算。","weight":10},{"key":"source_snapshot","label":"来源记录快照","passed":false,"status":"missing","evidence":"暂无可追溯的来源记录快照","next_action":"先带入可追溯的来源记录，并保留取数日期、来源计数和核验状态。","weight":15},{"key":"operating_window","label":"经营样本窗口","passed":false,"status":"missing","evidence":"当前窗口无样本","next_action":"至少补齐 7 天以上经营样本；30 天窗口更适合投决复核。","weight":10},{"key":"cost_assumptions","label":"成本与报价输入","passed":false,"status":"missing","evidence":"租金、人力、转让价等关键输入已填写","next_action":"补齐租金、人力、预期转让价、房量和收入等关键假设。","weight":10},{"key":"pricing_result","label":"资产定价结果","passed":true,"status":"ok","evidence":"已形成估值、利润和风险结果","next_action":"先生成资产定价，不能只保留原始表单。","weight":15},{"key":"timing_result","label":"转让时机结果","passed":false,"status":"missing","evidence":"已形成时机评分和动作建议","next_action":"先生成时机推演，补齐趋势与数据质量判断。","weight":15},{"key":"dashboard_summary","label":"决策看板汇总","passed":false,"status":"missing","evidence":"已汇总定价、时机、风险和下一步动作","next_action":"在决策看板汇总，不用单一测算替代投决结论。","weight":10},{"key":"data_quality_clear","label":"数据质量复核","passed":true,"status":"ok","evidence":"未发现显式异常标记","next_action":"先复核数据异常、断档或 OTA 采集口径冲突。","weight":5},{"key":"lease_license_inputs","label":"租约与证照输入","passed":false,"status":"missing","evidence":"租期和证照输入已填写","next_action":"补齐剩余租期和证照状态；表单勾选不等同于原件证据。","weight":5},{"key":"manual_review","label":"人工复核审批","passed":false,"status":"missing","evidence":"已记录人工复核/审批状态","next_action":"补一条人工复核结论，明确通过、暂缓或重谈。","weight":3},{"key":"post_decision_tracking","label":"投后跟踪","passed":false,"status":"missing","evidence":"已关联执行/跟踪记录","next_action":"关联运营执行、成交跟踪或复盘记录，避免投决后失联。","weight":2}],"missing_evidence":[{"code":"source_snapshot","label":"来源记录快照","next_action":"先带入可追溯的来源记录，并保留取数日期、来源计数和核验状态。"},{"code":"operating_window","label":"经营样本窗口","next_action":"至少补齐 7 天以上经营样本；30 天窗口更适合投决复核。"},{"code":"cost_assumptions","label":"成本与报价输入","next_action":"补齐租金、人力、预期转让价、房量和收入等关键假设。"},{"code":"timing_result","label":"转让时机结果","next_action":"先生成时机推演，补齐趋势与数据质量判断。"},{"code":"dashboard_summary","label":"决策看板汇总","next_action":"在决策看板汇总，不用单一测算替代投决结论。"},{"code":"lease_license_inputs","label":"租约与证照输入","next_action":"补齐剩余租期和证照状态；表单勾选不等同于原件证据。"},{"code":"manual_review","label":"人工复核审批","next_action":"补一条人工复核结论，明确通过、暂缓或重谈。"},{"code":"post_decision_tracking","label":"投后跟踪","next_action":"关联运营执行、成交跟踪或复盘记录，避免投决后失联。"},{"code":"diligence_document_evidence","label":"尽调原件证据","next_action":"补充租约、证照、流水、平台截图或附件证据；当前仅能视为测算记录。"}],"next_action":"先带入可追溯的来源记录，并保留取数日期、来源计数和核验状态。","notice":"当前只有手工输入或测算结果，不能作为来源背书的投决依据。"},"input":{"hotel_id":7,"monthly_room_revenue":0,"monthly_rent":null,"legacy_zero_field":0},"result":{"profit":{"monthly_net_profit":0},"valuation":{"reasonable_valuation":0},"timing_score":null,"legacy_extra":{"zero":0,"nullable":null}},"snapshot":{"hotel_id":7,"hotel_name":"Synthetic hotel","source_date":"2026-09-13"}}}};
const detail = (id, label, amount, sourceDate = '2026-09-14') => ({
  ...clone(backendSamples.detail_body.data),
  id, record_type: 'pricing', hotel_id: 7, hotel_name: 'Synthetic A', source_date: sourceDate,
  summary: { ...clone(backendSamples.detail_body.data.summary), reasonable_valuation: amount },
  input: { ...clone(backendSamples.detail_body.data.input), hotel_id: 7, hotel_name: 'Synthetic A', monthly_revenue: amount, occupancy_rate: 0, adr: null },
  result: { ...clone(backendSamples.detail_body.data.result), status: 'insufficient_data', data_notice: label, main_reasons: [label], risk_points: [], valuation: { reasonable_valuation: amount } },
  snapshot: { ...clone(backendSamples.detail_body.data.snapshot), hotel_id: 7, source_date: sourceDate },
});
const records = [detail(101, 'Synthetic first history A', 12), detail(202, 'Synthetic selected history B', 0, '2026-09-13')];
const summary = row => Object.fromEntries(Object.entries(row).filter(([key]) => !['input', 'result', 'snapshot'].includes(key)));
const reply = (request, body, status = 200) => { request.response = clone(body); request.settled = true; request.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })); };
async function button(p, testid) {
  const node = (await p.inspect()).nodes.find(n => n.type === 'button' && n.props?.['data-testid'] === testid);
  ok(node && !node.props?.disabled, 'Actual historical button is available: ' + testid);
  return node;
}
async function click(p, testid) { const node = await button(p, testid); const pending = node.props.onClick(); await tick(); return { pending }; }
async function loadList(p) {
  const node = (await p.inspect()).nodes.find(n => n.type === 'button' && n.props?.onClick === p.loadTransferRecords);
  ok(node && !node.props?.disabled, 'Original refresh button is enabled');
  const pending = node.props.onClick(); await tick();
  const request = p.requests.find(r => new URL(r.url).pathname === '/api/transfer/records' && !r.settled);
  ok(request); reply(request, { code: 200, data: { list: records.map(summary) } }); await pending; await tick();
  eq(p.transferRecords.value.length, 2); eq(String(p.transferSelectedHotelId.value), '7');
}
async function historyRequest(p, id) { await tick(); const request = p.requests.find(r => new URL(r.url).pathname === '/api/transfer/records/' + id && !r.settled); ok(request); return request; }


// Original Vue controls and adapter use only in-memory synthetic Response objects.
async function changeHotel(p, value) {
  const node = (await p.inspect()).nodes.find(node => node.type === 'select');
  ok(node && !node.props?.disabled && typeof node.props['onUpdate:modelValue'] === 'function');
  node.props['onUpdate:modelValue'](value); await tick();
  eq(p.sandbox.filterReportHotel.value, value, 'Full original unified watcher updates global hotel');
}
const visibleState = p => clone({ result: p.transferPricingResult.value, draft: p.transferPricingForm.value,
  snapshot: p.transferSourceSnapshot.value, hotel: String(p.transferSelectedHotelId.value) });
for (const action of ['view', 'reuse']) test('normal historical ' + action + ' preserves exact result zero/null and selected input semantics', async t => {
  const p = harness(); t.after(p.stop); await loadList(p);
  const oldInput = clone(p.transferPricingForm.value), clicked = await click(p, 'history-transfer-' + action + '-202');
  reply(await historyRequest(p, 202), { code: 200, data: records[1] }); await clicked.pending;
  eq(p.transferPricingResult.value.valuation.reasonable_valuation, 0);
  eq(p.transferPricingResult.value.legacy_extra.nullable, null);
  if (action === 'reuse') eq(p.transferPricingForm.value.monthly_revenue, 0);
  else eq(clone(p.transferPricingForm.value), oldInput, 'View does not reuse inputs');
  ok((await p.inspect()).html.includes('Synthetic selected history B'));
  eq(p.requests.filter(row => row.options.method === 'POST').length, 0);
});
for (const action of ['view', 'reuse']) for (const status of [200, 400, 500]) test(action + ' A then B keeps B after late A HTTP ' + status, async t => {
  const p = harness(); t.after(p.stop); await loadList(p);
  const a = await click(p, 'history-transfer-' + action + '-101'), reqA = await historyRequest(p, 101);
  const b = await click(p, 'history-transfer-' + action + '-202'), reqB = await historyRequest(p, 202);
  reply(reqB, { code: 200, data: records[1] }); await b.pending; await tick();
  const expected = visibleState(p), notices = clone(p.notices);
  reply(reqA, status === 200 ? { code: 200, data: records[0] } : { code: status, message: 'Obsolete history A failed' }, status); await a.pending;
  eq(visibleState(p), expected, 'Earlier detail may not replace latest result, snapshot, zero or draft');
  eq(clone(p.notices), notices, 'Earlier failure may not overwrite latest success feedback');
  ok((await p.inspect()).html.includes('Synthetic selected history B'));
});
test('earlier success while B is pending cannot replace prior view, and failed B remains retryable', async t => {
  const p = harness(); t.after(p.stop); await loadList(p); const expected = visibleState(p);
  const a = await click(p, 'history-transfer-reuse-101'), reqA = await historyRequest(p, 101);
  const b = await click(p, 'history-transfer-reuse-202'), reqB = await historyRequest(p, 202);
  reply(reqA, { code: 200, data: records[0] }); await a.pending;
  eq(visibleState(p), expected); eq(p.notices.length, 0);
  reply(reqB, { code: 400, message: 'Current history B failed' }, 400); await b.pending;
  eq(visibleState(p), expected); ok(p.notices.some(row => row.type === 'error' && row.message.includes('Current history B failed')));
  const retry = await click(p, 'history-transfer-reuse-202'); reply(await historyRequest(p, 202), { code: 200, data: records[1] }); await retry.pending;
  eq(p.transferPricingForm.value.monthly_revenue, 0); eq(p.transferPricingResult.value.data_notice, records[1].result.data_notice);
});
test('last selection also owns deferred static preflight', async t => {
  const p = harness({ delayStatic: true }); t.after(p.stop); p.transferRecords.value = records.map(summary);
  const a = await click(p, 'history-transfer-view-101'), b = await click(p, 'history-transfer-view-202');
  eq(p.requests.length, 0); p.resolveStatic(); await tick();
  const requests = [...p.requests]; for (const req of requests) reply(req, { code: 200, data: req.url.endsWith('/101') ? records[0] : records[1] });
  await Promise.all([a.pending, b.pending]);
  eq(requests.length, 1, 'Only last selection is dispatched after static module becomes ready');
  ok(requests[0].url.endsWith('/202')); eq(p.transferPricingResult.value.data_notice, records[1].result.data_notice);
});
test('late A success cannot replace the last selected B failure', async t => {
  const p = harness(); t.after(p.stop); p.transferRecords.value = records.map(summary); const expected = visibleState(p);
  const a = await click(p, 'history-transfer-view-101'), reqA = await historyRequest(p, 101);
  const b = await click(p, 'history-transfer-view-202'), reqB = await historyRequest(p, 202);
  reply(reqB, { code: 500, message: 'Latest history B unavailable' }, 500); await b.pending; const notices = clone(p.notices);
  reply(reqA, { code: 200, data: records[0] }); await a.pending;
  eq(visibleState(p), expected); eq(clone(p.notices), notices); ok(notices.some(row => row.type === 'error'));
});
for (const phase of ['preflight', 'dispatched']) for (const status of [200, 500]) test('actual hotel control isolates historical ' + phase + ' HTTP ' + status, async t => {
  const p = harness({ delayStatic: phase === 'preflight' }); t.after(p.stop); p.transferRecords.value = records.map(summary);
  const a = await click(p, 'history-transfer-reuse-101'); await changeHotel(p, '8'); const expected = visibleState(p);
  if (phase === 'preflight') { p.resolveStatic(); await tick(); }
  for (const req of p.requests) reply(req, status === 200 ? { code: 200, data: records[0] } : { code: 500, message: 'Old hotel request failed' }, status);
  await a.pending; eq(visibleState(p), expected); eq(p.notices, []);
  if (phase === 'preflight') eq(p.requests.length, 0, 'Old hotel request is not dispatched under new coordinator context');
});
// These are direct lifecycle boundaries, not claimed as additional UI defects.
for (const boundary of ['page', 'auth']) for (const phase of ['preflight', 'dispatched']) test(boundary + ' policy remains isolated across ' + phase, async t => {
  const p = harness({ delayStatic: phase === 'preflight' }); t.after(p.stop); p.transferRecords.value = records.map(summary);
  const a = await click(p, 'history-transfer-view-101');
  if (boundary === 'page') { p.sandbox.currentPage.value = 'timing-strategy'; p.sandbox.pageRequestGeneration++; } else p.sandbox.authSessionEpoch++;
  const expected = visibleState(p); if (phase === 'preflight') { p.resolveStatic(); await tick(); }
  for (const req of p.requests) reply(req, { code: 200, data: records[0] }); await a.pending;
  eq(visibleState(p), expected); eq(p.notices, []); if (phase === 'preflight') eq(p.requests.length, 0);
});
test('current static failure keeps draft and enabled history action can retry', async t => {
  const p = harness({ delayStatic: true }); t.after(p.stop); p.transferRecords.value = records.map(summary); const expected = visibleState(p);
  const a = await click(p, 'history-transfer-view-101'); p.rejectStatic(new Error('Synthetic history static failure')); await a.pending;
  eq(visibleState(p), expected); ok(p.notices.some(row => row.type === 'error'));
  p.sandbox.ensureSimulationStaticReady = async () => {};
  const b = await click(p, 'history-transfer-view-202'); reply(await historyRequest(p, 202), { code: 200, data: records[1] }); await b.pending;
  eq(p.transferPricingResult.value.data_notice, records[1].result.data_notice);
});
for (const date of ['2026-09-13', '2026-09-15', '', null, '   ']) test('verified history fact date uses its displayed snapshot: ' + JSON.stringify(date), async t => {
  const p = harness(); t.after(p.stop); const record = clone(records[1]);
  record.snapshot.source_verified = true; record.source_date = date || ''; if (date === null) delete record.snapshot.source_date; else record.snapshot.source_date = date;
  p.transferRecords.value = [summary(record)]; const a = await click(p, 'history-transfer-view-202');
  reply(await historyRequest(p, 202), { code: 200, data: record }); await a.pending;
  eq(p.transferSourceDate.value, '2026-09-15', 'History read does not rewrite source selector');
  const facts = p.transferDecisionLayerRows.value.find(row => row.key === 'facts'), html = (await p.inspect()).html;
  eq(facts.status, '已验证快照', 'Existing verification flag is not redefined'); ok(html.includes(facts.detail));
  if (String(date || '').trim()) ok(facts.detail.includes(date)); else { ok(facts.detail.includes('日期未返回')); ok(!facts.detail.includes('2026-09-15')); }
});
test('unverified history keeps simulation status and no selector date becomes a verified fact', async t => {
  const p = harness(); t.after(p.stop); p.transferRecords.value = records.map(summary);
  const record = clone(records[1]); record.snapshot.source_verified = false;
  const a = await click(p, 'history-transfer-view-202'); reply(await historyRequest(p, 202), { code: 200, data: record }); await a.pending;
  const facts = p.transferDecisionLayerRows.value.find(row => row.key === 'facts'); eq(facts.status, '快照待核验'); ok(!facts.detail.includes('2026-09-15')); ok((await p.inspect()).html.includes(facts.detail));
});
test('editing the source selector date does not change the historical date or discard an allowed history GET', async t => {
  const p = harness(); t.after(p.stop); const record = clone(records[1]); record.snapshot.source_verified = true;
  p.transferRecords.value = [summary(record)]; const a = await click(p, 'history-transfer-view-202'); const req = await historyRequest(p, 202);
  const date = (await p.inspect()).nodes.find(node => node.type === 'input' && node.props?.type === 'date');
  ok(date && !date.props?.disabled && typeof date.props['onUpdate:modelValue'] === 'function');
  date.props['onUpdate:modelValue']('2026-09-16'); await tick(); reply(req, { code: 200, data: record }); await a.pending;
  eq(p.transferSourceDate.value, '2026-09-16'); eq(p.transferSourceSnapshot.value.source_date, '2026-09-13');
  const facts = p.transferDecisionLayerRows.value.find(row => row.key === 'facts'); ok(facts.detail.includes('2026-09-13')); ok(!facts.detail.includes('2026-09-16')); ok((await p.inspect()).html.includes(facts.detail));
});
after(() => console.log('Historical selection/date assertions: ' + assertions));
