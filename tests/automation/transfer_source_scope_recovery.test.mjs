import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { readFrontendTestSource, retiredFrontendManifest } from './helpers/retired_frontend_source.mjs';

const files = {
  main: process.env.TRANSFER_SOURCE_MAIN_PATH || 'public/app-main.js', system: 'public/system-static.js', simulation: 'public/simulation-static.js',
  context: 'resources/frontend/templates/fragments/08-shared-transfer-context.html',
  pricing: 'resources/frontend/templates/fragments/09-page-asset-pricing.html',
  timing: 'resources/frontend/templates/fragments/10-page-timing-strategy.html',
};
const raw = Object.fromEntries(Object.entries(files).map(([k, path]) => [k,
  ['context', 'pricing', 'timing'].includes(k) ? readFrontendTestSource(path) : readFileSync(path, 'utf8'),
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
  unified: withHistoricalTransferBinding(extract('            const unifiedHotelContextBindings =', '            return {')),
};
test('historical transfer source scope oracle stays archived and absent from live runtime', () => {
  assert.doesNotMatch(raw.main, /\b(?:loadTransferSource|loadTransferRecords|loadTransferDetail|reuseTransferRecord|transferPricingForm|transferSourceSnapshot)\b/);
  const live = vm.createContext({ window: {} });
  vm.runInContext(raw.simulation, live);
  for (const key of Object.keys(live.window.SUXI_SIMULATION_STATIC)) assert.doesNotMatch(key, /^(?:transfer|buildTransfer|createTransfer|resolveTransfer)/);
  for (const id of ['shared-transfer-context', 'page-asset-pricing', 'page-timing-strategy']) {
    assert.equal(retiredFrontendManifest.fragments.find(fragment => fragment.id === id)?.runtime, false);
  }
});
const render = new Function('Vue', compile(raw.context + raw.pricing + raw.timing, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
const check = (value, description) => assert.ok(value, description);
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
      check(url.startsWith('https://synthetic.invalid/api/transfer/source?'), 'only synthetic source GET is reachable');
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
  const effect = Vue.effectScope();
  effect.run(() => vm.runInContext(Object.values(parts).join('\n') + `
    globalThis.exposed = { transferPricingForm, transferPricingResult, transferPricingLoading,
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
    transferTimingDataCheck: {}, handleTransferTiming() { throw new Error('calculation outside probe'); },
    transferAiModelOptions: [], transferSourceMetricRows: [], transferDecisionLayerRows: [], transferCurrentReadiness: null,
    loadTransferRecords() { throw new Error('records outside probe'); },
    handleTransferPricing() { throw new Error('calculation outside probe'); },
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
function nodeFor(nodes, field) {
  if (field === 'hotel') return nodes.find(node => node.type === 'select');
  if (field === 'date') return nodes.find(node => node.type === 'input' && node.props?.type === 'date');
  if (field === 'timing_revenue') return nodes.find(node => node.type === 'input' && node.props?.type === 'number');
  if (field === 'licenses_complete') return nodes.find(node => node.type === 'input' && node.props?.type === 'checkbox');
  return nodes.find(node => node.type === 'input' && node.props?.['data-testid'] === `field-transfer-pricing-${field}`);
}
async function edit(p, field, value) {
  const node = nodeFor((await p.inspect()).nodes, field);
  check(!!node && !node.props?.disabled && !node.props?.readonly, `${field} is actually editable`);
  check(typeof node.props['onUpdate:modelValue'] === 'function', `${field} uses actual compiled v-model`);
  node.props['onUpdate:modelValue'](value);
  await tick();
}

async function clickSource(p) {
  const nodes = (await p.inspect()).nodes;
  const button = nodes.find(n => n.type === 'button' && n.props?.onClick === p.loadTransferSource);
  assert.ok(button && !button.props.disabled, 'click only the enabled actual source button');
  const result = button.props.onClick();
  await tick();
  const pending = await p.inspect();
  assert.equal(pending.nodes.find(n => n.type === 'button' && n.props?.onClick === p.loadTransferSource).props.disabled, true);
  return { result };
}
function payload(req) {
  const url = new URL(req.url), hotelId = Number(url.searchParams.get('hotel_id')), date = url.searchParams.get('date');
  return { hotel_id: hotelId, source_date: date,
    snapshot: { hotel_id: hotelId, source_date: date, source_verified: false, data_status: 'synthetic current source' },
    pricing_input: { hotel_id: hotelId, hotel_name: 'synthetic loaded', monthly_revenue: 88, room_count: 40 },
    timing_input: { hotel_id: hotelId, current_revenue: 88 }, data_notice: 'synthetic current source' };
}
function reply(req, status = 200) {
  req.resolve(new Response(JSON.stringify(status === 200 ? { code: 200, data: payload(req) } : { code: status, message: 'synthetic failed source' }), { status }));
}
function state(p) {
  return clone({ hotel: String(p.transferSelectedHotelId.value), globalHotel: p.sandbox.filterReportHotel.value,
    date: p.transferSourceDate.value, pricing: p.transferPricingForm.value, timing: p.transferTimingForm.value, snapshot: p.transferSourceSnapshot.value });
}
for (const page of ['asset-pricing', 'timing-strategy']) test('unchanged source applies both forms on ' + page, async t => {
  const p = harness({ page }); t.after(p.stop);
  const { result } = await clickSource(p);
  assert.equal(p.requests.length, 1);
  assert.equal(new URL(p.requests[0].url).searchParams.get('date'), '2026-09-15');
  reply(p.requests[0]); await result;
  assert.equal(p.transferPricingForm.value.monthly_revenue, 88);
  assert.equal(p.transferTimingForm.value.current_revenue, 88);
  assert.equal(p.transferSourceSnapshot.value.source_date, p.transferSourceDate.value);
  assert.equal(p.transferSourceLoading.value, false);
  assert.match((await p.inspect()).html, /value="88"/);
});
for (const field of ['hotel', 'date']) for (const status of [200, 500]) test(field + ' switch discards late HTTP ' + status, async t => {
  const p = harness(); t.after(p.stop);
  const { result } = await clickSource(p);
  await edit(p, field, field === 'hotel' ? '8' : '2026-09-14');
  const current = state(p);
  if (field === 'hotel') assert.equal(current.globalHotel, '8', 'actual unified watcher adopted hotel B');
  reply(p.requests[0], status); await result;
  assert.deepEqual(state(p), current, 'abandoned read cannot bind source or overwrite forms');
  assert.deepEqual(p.notices, [], 'old scope cannot show success or failure in current scope');
  assert.equal(p.transferSourceLoading.value, false);
});
for (const field of ['monthly_revenue', 'hotel_name', 'licenses_complete', 'timing_revenue']) test('preserve complete draft and source binding after editing ' + field, async t => {
  const p = harness({ page: field === 'timing_revenue' ? 'timing-strategy' : 'asset-pricing' }); t.after(p.stop);
  const { result } = await clickSource(p);
  await edit(p, field, field === 'hotel_name' ? 'synthetic edited draft' : field === 'licenses_complete' ? false : 99);
  const current = state(p);
  reply(p.requests[0]); await result;
  assert.deepEqual(state(p), current, 'neither snapshot nor either form may be rebound after draft edits');
  assert.ok(p.notices.some(n => n.type === 'warning' && n.message.includes('输入已修改') && n.message.includes('本次来源未带入')));
  assert.equal(p.transferSourceLoading.value, false);
});
test('current HTTP failure stays visible while preserving an edited draft', async t => {
  const p = harness(); t.after(p.stop);
  const { result } = await clickSource(p);
  await edit(p, 'monthly_revenue', 99); const current = state(p);
  reply(p.requests[0], 500); await result;
  assert.deepEqual(state(p), current);
  assert.ok(p.notices.some(n => n.type === 'error' && n.message.includes('synthetic failed source') && n.message.includes('已保留当前输入')));
});
for (const change of ['hotel', 'date', 'draft', 'page', 'auth']) test('capture ownership before deferred static module: ' + change, async t => {
  const p = harness({ delayStatic: true }); t.after(p.stop);
  const { result } = await clickSource(p);
  assert.equal(p.requests.length, 0);
  if (change === 'hotel') await edit(p, 'hotel', '8');
  else if (change === 'date') await edit(p, 'date', '2026-09-14');
  else if (change === 'draft') await edit(p, 'monthly_revenue', 99);
  else if (change === 'page') { p.sandbox.currentPage.value = 'timing-strategy'; p.sandbox.pageRequestGeneration++; }
  else p.sandbox.authSessionEpoch++;
  const current = state(p); p.resolveStatic(); await tick();
  // Settle a wrong baseline dispatch so failures remain bounded and diagnostic.
  for (const req of p.requests) reply(req);
  await result;
  assert.equal(p.requests.length, 0, 'abandoned preflight must not dispatch with old hotel/new policy');
  assert.deepEqual(state(p), current); assert.equal(p.transferSourceLoading.value, false);
  if (change !== 'draft') assert.deepEqual(p.notices, []);
});
for (const change of ['page', 'auth']) test('original coordinator plus owner ignores ' + change + ' change after dispatch', async t => {
  const p = harness(); t.after(p.stop);
  const { result } = await clickSource(p);
  if (change === 'page') { p.sandbox.currentPage.value = 'timing-strategy'; p.sandbox.pageRequestGeneration++; }
  else p.sandbox.authSessionEpoch++;
  const current = state(p); reply(p.requests[0]); await result;
  assert.deepEqual(state(p), current); assert.deepEqual(p.notices, []);
});
test('current HTTP failure retains draft and enabled button can read again', async t => {
  const p = harness(); t.after(p.stop); const current = state(p);
  const first = await clickSource(p); reply(p.requests[0], 500); await first.result;
  assert.deepEqual(state(p), current); assert.ok(p.notices.some(n => n.type === 'error'));
  const second = await clickSource(p); assert.equal(p.requests.length, 2); reply(p.requests[1]); await second.result;
  assert.equal(p.transferPricingForm.value.monthly_revenue, 88); assert.equal(p.transferSourceSnapshot.value.source_date, '2026-09-15');
});
test('changed date waits for original busy to end then retries the new captured date', async t => {
  const p = harness(); t.after(p.stop);
  const first = await clickSource(p); await edit(p, 'date', '2026-09-14');
  const pendingButton = (await p.inspect()).nodes.find(n => n.type === 'button' && n.props?.onClick === p.loadTransferSource);
  assert.equal(pendingButton.props.disabled, true, 'do not invent concurrent clicks behind disabled control');
  reply(p.requests[0]); await first.result; assert.equal(p.transferSourceSnapshot.value, null);
  const second = await clickSource(p); assert.equal(new URL(p.requests[1].url).searchParams.get('date'), '2026-09-14');
  reply(p.requests[1]); await second.result; assert.equal(p.transferSourceSnapshot.value.source_date, '2026-09-14');
});
test('late static failure is silent after date switch', async t => {
  const p = harness({ delayStatic: true }); t.after(p.stop);
  const first = await clickSource(p); await edit(p, 'date', '2026-09-14'); const current = state(p);
  p.rejectStatic(new Error('synthetic module failure')); await first.result;
  assert.equal(p.requests.length, 0); assert.deepEqual(state(p), current); assert.deepEqual(p.notices, []);
});
test('a draft edit during re-read preserves the previous source snapshot without rebinding it', async t => {
  const p = harness(); t.after(p.stop);
  const initial = await clickSource(p); reply(p.requests[0]); await initial.result;
  const second = await clickSource(p); await edit(p, 'monthly_revenue', 99); const current = state(p);
  const nextPayload = payload(p.requests[1]); nextPayload.snapshot.data_status = 'different newer source';
  nextPayload.pricing_input.monthly_revenue = 77;
  p.requests[1].resolve(new Response(JSON.stringify({ code: 200, data: nextPayload }), { status: 200 }));
  await second.result; assert.deepEqual(state(p), current);
  assert.equal(p.transferSourceSnapshot.value.data_status, 'synthetic current source');
  assert.equal(p.transferPricingForm.value.monthly_revenue, 99);
});
test('current static failure remains visible and the actual source button can retry', async t => {
  const p = harness({ delayStatic: true }); t.after(p.stop); const current = state(p);
  const first = await clickSource(p); p.rejectStatic(new Error('synthetic module failure')); await first.result;
  assert.deepEqual(state(p), current); assert.equal(p.requests.length, 0);
  assert.ok(p.notices.some(n => n.type === 'error' && n.message === 'synthetic module failure'));
  p.sandbox.ensureSimulationStaticReady = async () => {};
  const second = await clickSource(p); assert.equal(p.requests.length, 1); reply(p.requests[0]); await second.result;
  assert.equal(p.transferPricingForm.value.monthly_revenue, 88);
});
