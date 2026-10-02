import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

const root = 'output/autonomous-verification/20260915-round37/';
let source = fs.readFileSync('public/app-main.js', 'utf8');
if (process.env.SUXI_RESEARCH_RUN_SOURCE) {
  const start = source.indexOf('            const runRevenueResearchProduct =');
  const end = source.indexOf('            const openRevenueResearchExecutionIntent =', start);
  assert.ok(start >= 0 && end > start);
  source = source.slice(0, start) + fs.readFileSync(process.env.SUXI_RESEARCH_RUN_SOURCE, 'utf8') + source.slice(end);
}
const staticSource = fs.readFileSync('public/revenue-research-static.js', 'utf8');
const systemSource = fs.readFileSync('public/system-static.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/19-page-revenue-research-center.html', 'utf8');
const slice = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert.ok(a >= 0 && b > a, start); return source.slice(a, b);
};
const domain = slice('const revenueResearchStaticScript =', '// Agent配置');
const auth = slice('const captureAuthSession =', 'const createDefaultAuthContext =');
const business = slice('const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', 'const userHasPermission =');
const policy = slice('const currentPageReadPolicy =', 'const cancelPageLoadRequests =');
const requests = slice('const COORDINATED_GET_MAX_CONCURRENCY = 3;', 'const askSystemUsageGuide =');
const syncHotel = slice('const syncUnifiedHotelContexts =', '            return {');
const pageLifecycle = slice('watch(currentPage, (newPage) => {', '                clearPageLifecycleTimers();');
const pageGenerationTransition = pageLifecycle.match(/^\s*pageRequestGeneration \+= 1;$/m)?.[0];
assert.ok(pageGenerationTransition, 'retain the real page generation transition with the original default watch flush');
const pageRender = new Function('Vue', compile(template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const uiNames = [...new Set(template.match(/(?:run|open|create)RevenueResearch[A-Za-z]+|revenueResearch[A-Za-z]+|revenueForecastReadinessClass/g))];
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const response = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const sha = value => createHash('sha256').update(value).digest('hex');
const clone = value => JSON.parse(JSON.stringify(value));
const flush = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
const flatten = node => !node || typeof node !== 'object' ? [] : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])];
let assertions = 0;
const equal = (actual, expected, message) => { assert.equal(actual, expected, message); assertions++; };
const match = (actual, expected) => { assert.match(actual, expected); assertions++; };

function fixture() {
  const calls = [], notices = [], timers = new Map(); let timerId = 0, tree;
  const permittedHotels = Vue.ref([{ id: 7, name: '合成门店七', tenant_id: 5 }, { id: 8, name: '合成门店八', tenant_id: 5 }]);
  const currentPage = Vue.ref('revenue-research-center'), filterReportHotel = Vue.ref('7');
  const sandbox = {
    ...Vue, window: { Vue }, permittedHotels, hotels: permittedHotels, currentPage, filterReportHotel,
    URL, URLSearchParams, Headers, AbortController, structuredClone,
    token: Vue.ref('synthetic-session'), isLoggedIn: Vue.ref(true), authSessionEpoch: 0, pageRequestGeneration: 1,
    authContext: Vue.ref({ permissionStatus: 'allowed', tenantId: '5', hotelId: '7' }),
    user: Vue.ref({ id: 42, hotel_id: 7, tenant_id: 5, is_super_admin: true }),
    revenueAiBusinessDate: Vue.ref(''), coreOperationsTargetDate: Vue.ref(''), API_BASE: 'https://synthetic.invalid/api',
    reportHotelOptionExists: id => permittedHotels.value.some(item => String(item.id) === String(id)),
    isTerminalAuthFailureResponse: () => false,
    createRequestAbortError: (message = 'Request aborted') => Object.assign(new Error(message), { name: 'AbortError' }),
    showToast: (message, type = 'success') => notices.push({ message, type }), console: { error() {} },
    onlineTruthStatusText: () => '本地合成', onlineTruthStatusClass: () => '', onlineTruthDetailText: () => '仅合成验证',
    formatCurrency: value => String(value), toFixedSafe: (value, digits) => Number(value).toFixed(digits),
    operationExecutionStatusLabel: value => String(value),
    setInterval: callback => { const id = ++timerId; timers.set(id, callback); return id; },
    clearInterval: id => timers.delete(id),
    fetch: (url, options) => {
      assert.ok(url.startsWith(sandbox.API_BASE));
      equal(url.slice(sandbox.API_BASE.length), '/revenue-research/run', 'Only the selected synthetic generation endpoint may be called');
      equal(options.method, 'POST');
      const pending = deferred(); calls.push({ url, options, body: JSON.parse(options.body), ...pending }); return pending.promise;
    },
  };
  vm.runInNewContext(systemSource + '\n' + staticSource, sandbox);
  vm.runInNewContext(`const appSystemStatic = window.SUXI_SYSTEM_STATIC;
    const requireAppSystemStatic = key => appSystemStatic[key];
    const readRequestCooldown = appSystemStatic.createReadRequestCooldown();
    ${auth}\n${business}\n${policy}\n${requests}\n${domain}
    const unifiedHotelContextBindings = [{ key: 'revenue-research', pages: ['revenue-research-center'], read: () => revenueResearchHotelId.value, write: hotelId => { revenueResearchHotelId.value = hotelId; } }];
    let unifiedHotelContextSyncing = false;
    const resetUnifiedHotelScopedResults = () => { revenueResearchRuns.value = {}; };
    ${syncHotel}
    watch(currentPage, () => { ${pageGenerationTransition} });
    globalThis.ui = { ${uiNames.join(', ')}, revenueResearchRuns, ensureRevenueResearchReady };
  `, sandbox);
  const ui = sandbox.ui;
  async function render() {
    await ui.ensureRevenueResearchReady();
    const app = Vue.createSSRApp({ setup: () => ({ currentPage, ...ui, operationExecutionStatusLabel: sandbox.operationExecutionStatusLabel }),
      render(...args) { tree = pageRender.apply(this, args); return tree; } });
    app.component('ai-decision-quality-details', { render: () => null });
    return renderToString(app);
  }
  async function button(key) {
    await render(); const node = flatten(tree).find(item => item.props?.['data-testid'] === 'button-revenue-research-run-' + key);
    assert.ok(node, 'real product button'); return node;
  }
  async function click(key = 'demand-forecast') {
    const node = await button(key);
    if (node.props.disabled) return { disabled: true, done: Promise.resolve() };
    return { disabled: false, done: node.props.onClick() };
  }
  async function selectHotel(hotelId) {
    await render(); const node = flatten(tree).find(item => item.type === 'select');
    assert.ok(node && !node.props.disabled && typeof node.props['onUpdate:modelValue'] === 'function');
    node.props['onUpdate:modelValue'](String(hotelId)); await Vue.nextTick();
    if (String(hotelId)) equal(filterReportHotel.value, String(hotelId), 'actual synchronous unified hotel watcher adopts the selected hotel');
    else equal(ui.revenueResearchHotelId.value, '', 'all-visible selection does not invent a current hotel');
  }
  function receipt(index, status = 'done') {
    const body = calls[index].body, hotelId = Number(body.hotel_id);
    return { code: 200, data: {
      status, product_key: body.product_key, model_key: body.model_key,
      hotel_scope: { mode: hotelId ? 'single_hotel' : 'all_permitted_hotels', hotel_id: hotelId || null, hotel_ids: hotelId ? [hotelId] : [7, 8] },
      result: { summary: `合成研究：酒店 ${hotelId} / ${body.product_key} / 请求 ${index + 1}`, risk_signals: [], decision_recommendations: [], recommended_actions: ['人工复核合成情景'] },
      readiness: { stage: status === 'done' ? 'research_ready_for_execution' : 'research_data_gaps_pending', execution_ready: status === 'done', status_label: '合成研究状态', score: 80, notice: '仅合成验证', missing_evidence: [], next_action: '人工复核' },
      execution_artifact: status === 'done' ? { status: 'available', id: sha(JSON.stringify(body)).slice(0, 32) } : { status: 'not_issued', reason: 'research_not_execution_ready' },
      business_forecast: { available: true, decision_ready: status === 'done', forecast_7d: { revenue: 700, room_nights: 7, adr: 100 }, forecast_30d: { revenue: 3000, room_nights: 30, adr: 100 }, sample_days: 30, date_range: { start: '2026-08-16', end: '2026-09-14' }, method: 'synthetic_only', confidence: 'uncalibrated', truth_context: {} },
      local_sources: [], web_sources: [], gaps: status === 'done' ? [] : [{ table: 'synthetic', label: '合成缺口', reason: '未取得' }],
    } };
  }
  const finish = (index, status = 'done') => { const value = receipt(index, status); calls[index].resolve(response(value)); return value.data; };
  return { ui, calls, notices, timers, render, button, click, selectHotel, receipt, finish, currentPage, filterReportHotel, token: sandbox.token, tree: () => tree };
}


test('real normal generation returns the selected product and hotel and blocks a repeated click', async () => {
  const f = fixture(); const a = await f.click(); await flush(); equal((await f.click()).disabled, true); equal(f.calls.length, 1);
  f.finish(0); await a.done; equal(f.ui.revenueResearchRunFor('demand-forecast').result.hotel_scope.hotel_id, 7);
  match(await f.render(), /合成研究：酒店 7/); equal(f.timers.size, 0); equal(f.notices.at(-1).type, 'success');
});

test('all visible hotels remains a legal generation scope with a null returned hotel ID', async () => {
  const f = fixture(); await f.selectHotel(''); const a = await f.click(); await flush(); equal(f.calls[0].body.hotel_id, '');
  f.finish(0, 'pending_data'); await a.done; const result = f.ui.revenueResearchRunFor('demand-forecast').result;
  equal(result.hotel_scope.mode, 'all_permitted_hotels'); equal(result.hotel_scope.hotel_id, null); equal(f.notices.at(-1).type, 'warning');
});

test('all permitted scope with no hotels remains pending data rather than a rejected receipt', async () => {
  const f = fixture(); await f.selectHotel(''); const a = await f.click(); await flush();
  const receipt = f.receipt(0, 'pending_data'); receipt.data.hotel_scope.hotel_ids = [];
  f.calls[0].resolve(response(receipt)); await a.done;
  const run = f.ui.revenueResearchRunFor('demand-forecast');
  equal(run.result.hotel_scope.hotel_ids.length, 0); equal(run.result.status, 'pending_data');
  equal(run.error, ''); equal(f.notices.at(-1).type, 'warning');
});

test('different products keep their legitimate parallel runs', async () => {
  const f = fixture(); const a = await f.click(); await flush(); const b = await f.click('price-elasticity'); await flush();
  equal(f.calls.length, 2); f.finish(1); await b.done; f.finish(0); await a.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result.product_key, 'demand-forecast');
  equal(f.ui.revenueResearchRunFor('price-elasticity').result.product_key, 'price-elasticity'); equal(f.timers.size, 0);
});

for (const failure of ['transport', 'http-422']) test('current ' + failure + ' stays failed and only an explicit click retries', async () => {
  const f = fixture(); const a = await f.click(); await flush();
  if (failure === 'transport') f.calls[0].reject(new Error('当前合成失败')); else f.calls[0].resolve(response({ code: 422, message: '当前合成拒绝' }, 422));
  await a.done; equal(f.ui.revenueResearchRunFor('demand-forecast').loading, false); equal(f.ui.revenueResearchRunFor('demand-forecast').result, null);
  equal(f.calls.length, 1); equal(f.notices.at(-1).type, 'error'); const b = await f.click(); await flush(); equal(f.calls.length, 2);
  f.finish(1, 'pending_data'); await b.done; equal(f.notices.at(-1).type, 'warning'); equal(f.timers.size, 0);
});

test('old A7 response cannot overwrite completed B8 or leave an old-scope execution button', async () => {
  const f = fixture(); const a = await f.click(); await flush(); await f.selectHotel(8); const b = await f.click(); await flush();
  f.finish(1); await b.done; const count = f.notices.length; f.finish(0); await a.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result.hotel_scope.hotel_id, 8); equal(f.notices.length, count);
  const html = await f.render(); match(html, /合成研究：酒店 8/); equal(html.includes('合成研究：酒店 7'), false);
  const execution = flatten(f.tree()).find(node => node.props?.['data-testid'] === 'button-revenue-research-execution-demand-forecast');
  equal(Boolean(execution), true); equal(f.calls.every(call => call.url.endsWith('/revenue-research/run')), true);
});

test('old A7 completion cannot unlock B8 or permit a third generation while B8 waits', async () => {
  const f = fixture(); const a = await f.click(); await flush(); await f.selectHotel(8); const b = await f.click(); await flush();
  f.finish(0); await a.done; equal(f.ui.revenueResearchRunFor('demand-forecast').loading, true);
  equal((await f.click()).disabled, true); equal(f.calls.length, 2); f.finish(1); await b.done; equal(f.timers.size, 0);
});

test('old A7 failure cannot label completed B8 as failed', async () => {
  const f = fixture(); const a = await f.click(); await flush(); await f.selectHotel(8); const b = await f.click(); await flush();
  f.finish(1); await b.done; const count = f.notices.length; f.calls[0].reject(new Error('旧门店合成失败')); await a.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result.hotel_scope.hotel_id, 8);
  equal(f.ui.revenueResearchRunFor('demand-forecast').error, ''); equal(f.notices.length, count);
});

test('an old progress callback cannot advance the new hotel run', async () => {
  const f = fixture(); const a = await f.click(); await flush(); const oldTick = [...f.timers.values()][0];
  await f.selectHotel(8); const b = await f.click(); await flush(); equal(f.ui.revenueResearchRunFor('demand-forecast').stepIndex, 0);
  oldTick(); equal(f.ui.revenueResearchRunFor('demand-forecast').stepIndex, 0);
  f.finish(0); await a.done; f.finish(1); await b.done;
});

for (const context of ['page', 'auth']) test('an old ' + context + ' result stays out of the current view and its own lock can finish', async () => {
  const f = fixture(); const a = await f.click(); await flush();
  if (context === 'page') f.currentPage.value = 'compass'; else f.token.value = 'synthetic-next-session';
  await Vue.nextTick(); f.finish(0); await a.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result, null); equal(f.ui.revenueResearchRunFor('demand-forecast').loading, false);
  equal(f.notices.length, 0); f.currentPage.value = 'revenue-research-center';
  const b = await f.click(); await flush(); equal(b.disabled, false); f.finish(1); await b.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result.hotel_scope.hotel_id, 7);
});

test('same-hotel page leave and return rejects the previous generation and releases only its own lock', async () => {
  const f = fixture(); const a = await f.click(); await flush();
  f.currentPage.value = 'compass'; await Vue.nextTick();
  f.currentPage.value = 'revenue-research-center'; await Vue.nextTick();
  equal((await f.button('demand-forecast')).props.disabled, true);
  f.finish(0); await a.done;
  const run = f.ui.revenueResearchRunFor('demand-forecast');
  equal(run.result, null); equal(run.loading, false); equal(f.notices.length, 0); equal(f.timers.size, 0);
  const b = await f.click(); await flush(); equal(b.disabled, false); equal(f.calls.length, 2);
  f.finish(1); await b.done; equal(f.ui.revenueResearchRunFor('demand-forecast').result.hotel_scope.hotel_id, 7);
  match(await f.render(), /请求 2/);
});

for (const invalid of ['product', 'hotel', 'mode', 'hotel-list', 'boolean-hotel', 'missing']) test('a current response with mismatched contract stays an explicit failure: ' + invalid, async () => {
  const f = fixture(); const a = await f.click(); await flush(); const receipt = f.receipt(0);
  if (invalid === 'product') receipt.data.product_key = 'price-elasticity';
  if (invalid === 'hotel') receipt.data.hotel_scope.hotel_id = 8;
  if (invalid === 'mode') receipt.data.hotel_scope.mode = 'all_permitted_hotels';
  if (invalid === 'hotel-list') receipt.data.hotel_scope.hotel_ids = [7, 8];
  if (invalid === 'boolean-hotel') receipt.data.hotel_scope.hotel_id = true;
  if (invalid === 'missing') delete receipt.data.hotel_scope;
  f.calls[0].resolve(response(receipt)); await a.done;
  equal(f.ui.revenueResearchRunFor('demand-forecast').result, null); equal(f.ui.revenueResearchRunFor('demand-forecast').loading, false);
  equal(f.notices.at(-1).type, 'error');
  const html = await f.render(); equal(html.includes('data-testid="button-revenue-research-execution-demand-forecast"'), false);
});
