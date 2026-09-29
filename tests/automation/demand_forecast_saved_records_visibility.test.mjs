import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original visible card/tab and original handlers/coordinator. All fetches are
// closed synthetic Agent DTOs; this does not execute PHP, a database or pricing.
const main = readFileSync('public/app-main.js', 'utf8');
const system = readFileSync('public/system-static.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
const part = (start, end) => {
  const a = main.indexOf(start), b = main.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start); return main.slice(a, b);
};
const declaration = name => {
  const a = main.indexOf('            const ' + name + ' ='); assert.ok(a >= 0, name);
  const match = /\n            (?:const|let) /.exec(main.slice(a + 1)); assert.ok(match, name);
  return main.slice(a, a + 1 + match.index);
};
const requestSource = [
  part('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  part('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  part('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  part('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  part('            const request = async (', '            const apiRequest = request;'),
].join('\n');
const names = [
  ...['captureRevenueForecastRange', 'isRevenueForecastRangeCurrent'].filter(name => main.includes('            const ' + name + ' =')),
  ...(main.includes('            const applyDemandForecastReadback =') ? ['applyDemandForecastReadback'] : []),
  'manualCtripPricingInputMeta', 'firstEnabledRoomTypeId', 'createDemandForecastForm',
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState',
  'syncRevenuePricingInputDate', 'resetDemandForecastForm', 'saveDemandForecastInput', 'loadDemandForecasts',
];
let card, tab, hiddenAncestors;
const walkAst = (node, ancestors = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-suggestion-demand-forecast-manual-input')) card = node;
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-demand-forecast-manual-input')) hiddenAncestors = ancestors.flatMap(a => (a.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp?.content));
  for (const child of node.children || []) walkAst(child, [...ancestors, node]);
};
walkAst(parse(template)); assert.ok(card && tab);
assert.ok(hiddenAncestors.includes("false && revenueAgentTab === 'analysis'"));
const markup = '<div v-if="currentPage === \'agent-center\'"><div v-if="agentTab === \'revenue\'">' + tab.loc.source + '<div v-if="revenueAgentTab === \'suggestions\'">' + card.loc.source + '</div></div></div>';
const render = new Function('Vue', compile(markup, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
const flatten = (node, result = []) => {
  if (Array.isArray(node)) node.forEach(child => flatten(child, result));
  else if (node && typeof node === 'object') { result.push(node); flatten(node.children, result); }
  return result;
};
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };

async function harness() {
  const state = Object.fromEntries(Object.entries({
    currentPage: 'agent-center', agentTab: 'revenue', revenueAgentTab: 'settings', filterReportHotel: '80',
    demandForecastSaving: false, demandForecastForm: {}, demandForecasts: [], forecastAccuracy: {}, highDemandDates: [],
    forecastFilter: { start_date: '2026-09-15', end_date: '2026-09-16' }, priceSuggestionFilter: { date: '2026-09-15' },
    competitorPriceForm: { analysis_date: '2026-09-15' }, competitorFilter: { date: '2026-09-15' },
    roomTypeConfigList: [{ id: 501, hotel_id: 80, name: 'Current hotel room', is_enabled: 1 }],
    revenueLoadState: { forecasts: { status: 'not_loaded', error: '' } },
    permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const requests = [], notices = [];
  const sandbox = {
    ...state, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl,
    setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 0, agentRevenueStateEpoch: 1,
    user: Vue.ref({ id: 11, tenant_id: 7 }), token: Vue.ref(''),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: 1, token: '' }), isAuthSessionCurrent: value => value.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    readRequestCache: () => null, writeRequestCache() {},
    formatDate: () => { throw new Error('Explicit date required in this fixture'); },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    loadRevenueAnalysis: async () => {}, loadRevenueDashboard: async () => {}, loadRevenueAiOverview: async () => {},
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.equal(parsed.pathname, '/api/agent/demand-forecasts');
      assert.ok(['GET', 'POST'].includes(options.method || 'GET'));
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.createContext(sandbox); vm.runInContext(system, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  vm.runInContext(requestSource + '\n' + names.map(declaration).join('\n') + '\nglobalThis.methods={' + names.join(',') + '};', sandbox);
  state.demandForecastForm.value = sandbox.methods.createDemandForecastForm();
  const context = { ...state, ...sandbox.methods, loadPriceSuggestionWorkbench: async () => {} };
  let tree;
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
  const nodes = () => flatten(tree);
  const visibleText = () => text(tree);
  const rows = () => nodes().filter(node => node.type === 'tr' && node.props?.['data-forecast-id'] !== undefined);
  const fields = row => Object.fromEntries(flatten(row).filter(node => node.props?.['data-field']).map(node => [node.props['data-field'], text(node).trim()]));
  const latest = method => requests.findLast(req => !req.settled && (req.options.method || 'GET') === method);
  const reply = (req, data, status = 200) => {
    assert.ok(req && !req.settled); req.settled = true;
    req.resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
  };
  const click = async label => {
    await html(); const node = nodes().find(item => item.type === 'button' && text(item).trim() === label);
    assert.ok(node && !node.props.disabled, 'Real enabled button: ' + label);
    const pending = node.props.onClick(); await tick(); return { pending };
  };
  const input = async (placeholder, value) => {
    await html(); const node = nodes().find(item => item.type === 'input' && item.props?.placeholder === placeholder);
    assert.ok(node && !node.props.disabled);
    const listeners = {}, element = { type: node.props.type || 'text', value: String(value), composing: false, addEventListener: (event, handler) => { listeners[event] = handler; } };
    Vue.vModelText.created(element, { modifiers: node.props.type === 'number' ? { number: true } : { trim: true } }, node);
    listeners.input({ target: element }); await tick();
  };
  const read = async data => {
    const { pending } = await click('刷新'); reply(latest('GET'), { code: 200, data });
    const result = await pending; await tick(); await html(); return result;
  };
  await html(); const originalTab = nodes().find(node => node.type === 'button');
  assert.ok(originalTab); originalTab.props.onClick(); await tick(); await html();
  assert.equal(state.revenueAgentTab.value, 'suggestions');
  return { state, requests, notices, html, nodes, visibleText, rows, fields, latest, reply, click, input, read };
}

const manual = (overrides = {}) => ({
  id: 75001, hotel_id: 80, room_type_id: 501, forecast_date: '2026-09-15',
  predicted_occupancy: 67, predicted_demand: 137, confidence_score: 0.83, forecast_method: 3,
  historical_data: { input_type: 'manual_demand_forecast', evidence_status: 'operator_provided', source_scope: 'ctrip_ota_channel', auto_write_ota: false },
  remark: 'Synthetic operator note', ...overrides,
});
const data = (forecasts, extra = {}) => ({ forecasts, accuracy: {}, high_demand_dates: [], ...extra });

test('save and refresh show the actual saved manual row through the original visible card', async () => {
  const h = await harness();
  await h.input('预测入住率%', '67'); await h.input('需求间夜', '137'); await h.input('人工置信度%（必填）', '83');
  await h.input('预测口径备注（可选）', 'Synthetic operator note');
  const { pending } = await h.click('保存预测'); const post = h.latest('POST');
  const payload = JSON.parse(post.options.body);
  assert.equal(payload.hotel_id, 80); assert.equal(payload.room_type_id, 501);
  assert.equal(payload.forecast_date, '2026-09-15'); assert.equal(payload.confidence_score, 0.83);
  assert.equal(payload.historical_data.input_type, 'manual_demand_forecast');
  assert.equal(payload.historical_data.auto_write_ota, false);
  const row = { id: 75001, ...payload };
  h.reply(post, { code: 200, data: { id: 75001, write_action: 'created', readback_verified: true, forecast: row } });
  await tick(); const get = h.latest('GET'); assert.ok(get);
  const query = new URL(get.url).searchParams;
  assert.equal(query.get('hotel_id'), '80'); assert.equal(query.get('start_date'), '2026-09-15'); assert.equal(query.get('end_date'), '2026-09-16');
  h.reply(get, { code: 200, data: data([row]) }); await pending; await tick(); await h.html();
  assert.equal(h.state.demandForecastForm.value.predicted_demand, null);
  assert.equal(h.rows().length, 1, 'Successful range read must have a visible record');
  assert.deepEqual(h.fields(h.rows()[0]), { date: '2026-09-15', 'room-type': 'Current hotel room', demand: '137', occupancy: '67%', confidence: '83%', remark: 'Synthetic operator note', source: '人工提供 · 未校准（非 OTA 采集事实）' });
  assert.ok(h.visibleText().includes('2026-09-15 至 2026-09-16'));
  await h.read(data([row])); assert.equal(h.rows().length, 1); assert.equal(h.fields(h.rows()[0]).demand, '137');
  assert.equal(h.requests.filter(req => req.options.method === 'POST').length, 1);
  assert.equal(h.requests.filter(req => (req.options.method || 'GET') === 'GET').length, 2);
});

test('two dates and room types preserve zero and missing fields without treating method 3 as manual provenance', async () => {
  const h = await harness();
  h.state.roomTypeConfigList.value.push({ id: 502, hotel_id: 80, name: 'Retired local room', is_enabled: 0 });
  const zero = manual({ predicted_demand: 0, predicted_occupancy: 0, confidence_score: 0, roomType: { id: 501, hotel_id: 80, name: 'Readback relation room' } });
  const missing = manual({ id: 75002, forecast_date: '2026-09-16', room_type_id: 502, predicted_demand: null, predicted_occupancy: undefined, confidence_score: undefined, remark: null, forecast_readiness: { confidence_percent: 0 } });
  const other = manual({ id: 75003, historical_data: {}, remark: 'NOT_MANUAL_METHOD_3' });
  await h.read(data([zero, missing, other]));
  assert.deepEqual(h.rows().map(row => row.props['data-forecast-id']), [75001, 75002]);
  const [a, b] = h.rows().map(h.fields);
  assert.equal(a.date, '2026-09-15'); assert.equal(a['room-type'], 'Readback relation room');
  assert.equal(a.demand, '0'); assert.equal(a.occupancy, '0%'); assert.equal(a.confidence, '0%');
  assert.equal(b.date, '2026-09-16'); assert.equal(b['room-type'], 'Retired local room');
  assert.equal(b.demand, '未返回'); assert.equal(b.occupancy, '未返回'); assert.equal(b.confidence, '未返回'); assert.equal(b.remark, '未返回');
  assert.equal(h.visibleText().includes('NOT_MANUAL_METHOD_3'), false);
});

test('missing room relation cannot borrow another hotel room name and legacy percent confidence stays compatible', async () => {
  const h = await harness();
  h.state.roomTypeConfigList.value.push({ id: 503, hotel_id: 81, name: 'FOREIGN_ROOM', is_enabled: 1 });
  await h.read(data([manual({ room_type_id: 503, confidence_score: 75 })]));
  assert.equal(h.rows().length, 1);
  assert.equal(h.fields(h.rows()[0])['room-type'], '房型 503 · 名称未返回');
  assert.equal(h.fields(h.rows()[0]).confidence, '75%');
  assert.equal(text(h.rows()[0]).includes('FOREIGN_ROOM'), false);
});

test('not loaded then loading and HTTP failure stay explicit; the same refresh button recovers', async () => {
  const h = await harness();
  assert.ok(h.visibleText().includes('尚未读取人工需求预测'));
  const { pending } = await h.click('刷新'); await h.html();
  assert.ok(h.visibleText().includes('正在读取人工需求预测'));
  assert.equal(h.visibleText().includes('暂无已保存'), false);
  h.reply(h.latest('GET'), { code: 500, message: 'Synthetic forecast read unavailable', data: null }, 500);
  await pending; await tick(); await h.html();
  assert.equal(h.state.revenueLoadState.value.forecasts.status, 'failed');
  assert.ok(h.visibleText().includes('人工需求预测读取失败'));
  assert.ok(h.visibleText().includes('Synthetic forecast read unavailable'));
  assert.equal(h.visibleText().includes('暂无已保存'), false);
  assert.equal(h.rows().length, 0); assert.equal(h.notices.at(-1).type, 'error');
  await h.read(data([manual()])); assert.equal(h.rows().length, 1);
  assert.equal(h.visibleText().includes('Synthetic forecast read unavailable'), false);
});

test('successful empty or nonmanual lists remain empty even when zero accuracy makes loader ready', async () => {
  const h = await harness();
  const accuracy = { avg_error: 0, accuracy_rate: 0, total_forecasts: 0 };
  await h.read(data([], { accuracy }));
  assert.equal(h.state.revenueLoadState.value.forecasts.status, 'ready');
  assert.ok(h.visibleText().includes('当前读取范围暂无已保存的人工需求预测'));
  assert.equal(h.rows().length, 0);
  await h.read(data([manual({ historical_data: { input_type: 'model_forecast' } })], { accuracy }));
  assert.ok(h.visibleText().includes('当前读取范围暂无已保存的人工需求预测'));
  assert.equal(h.rows().length, 0);
});

test('malformed GET lists fail in the original loader instead of becoming a valid empty display', async () => {
  const h = await harness();
  const malformed = [{}, { forecasts: null }, { forecasts: {} }, { forecasts: [null] }, { forecasts: [5] }, { forecasts: ['bad'] }, { forecasts: [[]] }];
  for (const response of malformed) {
    const result = await h.read(response);
    assert.equal(h.state.revenueLoadState.value.forecasts.status, 'failed', 'Malformed reply must fail before any empty UI assertion');
    assert.equal(result, null); assert.equal(h.state.demandForecasts.value.length, 0);
    assert.ok(h.visibleText().includes('列表回执格式无效'));
    assert.equal(h.visibleText().includes('暂无已保存'), false);
    assert.equal(h.notices.at(-1).type, 'error');
  }
  await h.read(data([manual()])); assert.equal(h.rows().length, 1);
  assert.equal(h.state.revenueLoadState.value.forecasts.status, 'ready');
});
