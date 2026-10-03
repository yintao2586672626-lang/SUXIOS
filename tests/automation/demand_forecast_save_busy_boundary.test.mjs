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
  'resolveDemandForecastListPayload', 'manualCtripPricingInputMeta', 'firstEnabledRoomTypeId', 'createDemandForecastForm',
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState',
  'syncRevenuePricingInputDate', 'resetDemandForecastForm', 'demandForecastInputNumber', 'demandForecastSavedReceiptMatches', 'saveDemandForecastInput', 'loadDemandForecasts',
];
let card, tab, cardAncestors, hiddenAncestors;
const walkAst = (node, ancestors = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-suggestion-demand-forecast-manual-input')) { card = node; cardAncestors = ancestors; }
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-demand-forecast-manual-input')) hiddenAncestors = ancestors.flatMap(a => (a.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp?.content));
  for (const child of node.children || []) walkAst(child, [...ancestors, node]);
};
const ast = parse(template); walkAst(ast); assert.ok(card && tab);
assert.ok(hiddenAncestors.includes("false && revenueAgentTab === 'analysis'"));
// Keep every original ancestor and condition of the tab and visible card.
const conditions = cardAncestors.flatMap(n => (n.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp.content));
assert.deepEqual(conditions, ["currentPage === 'agent-center'", "agentTab === 'revenue'", "revenueAgentTab === 'suggestions'"]);
assert.ok(!cardAncestors.some(n => ['form', 'fieldset', 'legend'].includes(n.tag)));
const retain = node => {
  if (node === card || node === tab) return node.loc.source;
  const children = (node.children || []).map(retain).join('');
  if (!children || node.type === 0) return children;
  assert.equal(node.type, 1);
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
};
const markup = retain(ast);
assert.equal(card.loc.source.includes('<legend'), false, 'No first-legend exception to native fieldset disabling');
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
    demandForecastSaving: false, demandForecastSaveReadback: null, demandForecastForm: {}, demandForecasts: [], forecastAccuracy: {}, highDemandDates: [],
    forecastFilter: { start_date: '2026-09-15', end_date: '2026-09-16' }, priceSuggestionFilter: { date: '2026-09-15' },
    competitorPriceForm: { analysis_date: '2026-09-15' }, competitorFilter: { date: '2026-09-15' },
    roomTypeConfigList: [{ id: 501, hotel_id: 80, name: 'Current hotel room', is_enabled: 1 }, { id: 502, hotel_id: 80, name: 'Next synthetic room', is_enabled: 1 }],
    revenueLoadState: { forecasts: { status: 'not_loaded', error: '' } },
    permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const requests = [], notices = [];
  const sandbox = {
    ...state, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl,
    setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 0, agentRevenueStateEpoch: 1,
    demandForecastsRequestSequence: 0,
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
  const context = { ...state, ...sandbox.methods, demandForecastReadState: Vue.computed(() => state.revenueLoadState.value.forecasts), loadPriceSuggestionWorkbench: async () => {} };
  let tree;
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
  const entries = (node = tree, result = [], parents = []) => {
    if (Array.isArray(node)) node.forEach(child => entries(child, result, parents));
    else if (node && typeof node === 'object') { result.push({ node, parents }); entries(node.children, result, [...parents, node]); }
    return result;
  };
  const nodes = () => entries().map(entry => entry.node);
  const disabled = node => !!node.props?.disabled || entries().find(entry => entry.node === node).parents.some(parent => parent.type === 'fieldset' && parent.props?.disabled);
  const controls = () => {
    const root = nodes().find(node => node.props?.['data-testid'] === 'agent-suggestion-demand-forecast-manual-input');
    assert.ok(root);
    return entries().filter(entry => entry.parents.includes(root) && ['input', 'select', 'button'].includes(entry.node.type));
  };
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
    assert.ok(node && !disabled(node), 'Real effectively enabled button: ' + label);
    const pending = node.props.onClick(); await tick(); return { pending };
  };
  const input = async (placeholder, value) => {
    await html(); const node = nodes().find(item => item.type === 'input' && (placeholder === 'DATE' ? item.props?.type === 'date' : item.props?.placeholder === placeholder));
    assert.ok(node && !disabled(node), 'Only effectively enabled original input');
    const listeners = {}, element = { type: node.props.type || 'text', value: String(value), composing: false, addEventListener: (event, handler) => { listeners[event] = handler; } };
    Vue.vModelText.created(element, { modifiers: node.props.type === 'number' ? { number: true } : { trim: true } }, node);
    listeners.input({ target: element }); await tick();
  };
  const selectRoom = async value => {
    await html(); const node = controls().find(entry => entry.node.type === 'select')?.node;
    assert.ok(node && !disabled(node), 'Only effectively enabled original select');
    const options = flatten(node).filter(item => item.type === 'option');
    assert.ok(options.some(option => Number(option.props.value) === value && !option.props.disabled));
    const listeners = {}, element = { multiple: false, options: options.map(option => ({ selected: Number(option.props.value) === value, value: String(option.props.value), _value: option.props.value })), addEventListener: (event, handler) => { listeners[event] = handler; } };
    Vue.vModelSelect.created(element, { value: state.demandForecastForm.value.room_type_id, modifiers: { number: true } }, node);
    listeners.change({ target: element }); await tick();
  };
  const read = async data => {
    const { pending } = await click('刷新'); reply(latest('GET'), { code: 200, data });
    const result = await pending; await tick(); await html(); return result;
  };
  await html(); const originalTab = nodes().find(node => node.type === 'button');
  assert.ok(originalTab); originalTab.props.onClick(); await tick(); await html();
  assert.equal(state.revenueAgentTab.value, 'suggestions');
  return { state, requests, notices, html, nodes, entries, disabled, controls, selectRoom, visibleText, rows, fields, latest, reply, click, input, read };
}

const clone = value => JSON.parse(JSON.stringify(value));
async function fillA(h) {
  await h.input('DATE', '2026-09-15'); await h.selectRoom(501);
  await h.input('预测入住率%', '65'); await h.input('需求间夜', '7');
  await h.input('人工置信度%（必填）', '80'); await h.input('预测口径备注（可选）', 'Synthetic submitted A');
  const draft = clone(h.state.demandForecastForm.value);
  assert.equal(h.nodes().some(node => node.type === 'form'), false, 'The actual card saves by a button, not a form submit');
  const { pending } = await h.click('保存预测'); const post = h.latest('POST'); assert.ok(post);
  const payload = JSON.parse(post.options.body);
  assert.equal(payload.hotel_id, 80); assert.equal(payload.room_type_id, 501); assert.equal(payload.forecast_date, '2026-09-15');
  assert.equal(payload.predicted_occupancy, 65); assert.equal(payload.predicted_demand, 7); assert.equal(payload.confidence_score, 0.8);
  assert.equal(payload.historical_data.input_type, 'manual_demand_forecast'); assert.equal(payload.historical_data.auto_write_ota, false);
  assert.equal(payload.historical_data.evidence_status, 'operator_provided'); assert.equal(payload.historical_data.source_scope, 'ctrip_ota_channel');
  assert.equal(payload.remark, 'Synthetic submitted A');
  return { draft, pending, post, payload };
}
async function assertBusy(h, expected) {
  await h.html(); assert.equal(h.state.demandForecastSaving.value, expected);
  const controls = h.controls();
  assert.equal(controls.filter(entry => entry.node.type === 'input').length, 5);
  assert.equal(controls.filter(entry => entry.node.type === 'select').length, 1);
  assert.deepEqual(controls.filter(entry => entry.node.type === 'button').map(entry => text(entry.node).trim()), ['刷新', '清空', '保存预测']);
  for (const { node, parents } of controls) {
    assert.equal(h.disabled(node), expected, 'Every original control follows save busy: ' + (node.props.placeholder || node.props.type || text(node).trim()));
    if (expected) assert.ok(parents.some(parent => parent.type === 'fieldset' && parent.props.disabled && parent.props['data-testid'] === 'agent-suggestion-demand-forecast-manual-input'));
  }
  if (expected) assert.match(await h.html(), /<fieldset[^>]*disabled[^>]*>/);
  // Native disabled inheritance is modeled through the complete original VNode
  // parent chain. Never dispatch input/select/click to a disabled control.
}
async function newDraftB(h) {
  await h.input('DATE', '2026-09-16'); await h.selectRoom(502);
  await h.input('预测入住率%', '69'); await h.input('需求间夜', '9');
  await h.input('人工置信度%（必填）', '85'); await h.input('预测口径备注（可选）', 'Explicit next B');
  const draft = clone(h.state.demandForecastForm.value);
  assert.equal(draft.forecast_date, '2026-09-16'); assert.equal(draft.room_type_id, 502);
  assert.equal(draft.predicted_occupancy, 69); assert.equal(draft.predicted_demand, 9);
  assert.equal(draft.confidence_percent, 85); assert.equal(draft.remark, 'Explicit next B');
  await assertBusy(h, false);
}
async function settlePending(h, pending) {
  // Also close synthetic requests on a baseline assertion failure, without retry.
  for (const request of h.requests.filter(request => !request.settled)) h.reply(request, { code: 500, message: 'Fixture cleanup', data: null }, 500);
  await pending;
  assert.ok(h.requests.every(request => request.settled), 'Every synthetic request is closed');
}

test('manual forecast busy card locks the original controls during POST; HTTP500 preserves A and permits explicit B', async () => {
  const h = await harness(); const a = await fillA(h);
  try {
    await assertBusy(h, true); assert.deepEqual(clone(h.state.demandForecastForm.value), a.draft);
    assert.equal(h.requests.length, 1);
    h.reply(a.post, { code: 500, message: 'Synthetic forecast save unavailable', data: null }, 500);
    await a.pending; await tick(); await assertBusy(h, false);
    assert.deepEqual(clone(h.state.demandForecastForm.value), a.draft);
    assert.equal(h.notices.at(-1).type, 'error'); assert.ok(h.notices.at(-1).message.includes('Synthetic forecast save unavailable'));
    assert.equal(h.requests.length, 1, 'No automatic refresh or POST replay after failure');
    await newDraftB(h); assert.equal(h.requests.length, 1, 'B remains a new unsaved draft');
  } finally { await settlePending(h, a.pending); }
});

test('manual forecast busy card remains locked through successful POST and pending scoped GET, then shows A and permits B', async () => {
  const h = await harness(); const a = await fillA(h);
  try {
    await assertBusy(h, true);
    const row = { id: 80001, ...a.payload };
    // Producer-consumed DTO subset; PHP diagnostics not consumed by this path
    // are omitted only in the fixture. No database/persistence is executed.
    h.reply(a.post, { code: 200, data: { id: 80001, write_action: 'created', readback_verified: true, forecast: row } });
    await tick(); const get = h.latest('GET'); assert.ok(get);
    const query = new URL(get.url).searchParams;
    assert.equal(query.get('hotel_id'), '80'); assert.equal(query.get('start_date'), '2026-09-15'); assert.equal(query.get('end_date'), '2026-09-16');
    await assertBusy(h, true); assert.equal(h.state.revenueLoadState.value.forecasts.status, 'loading');
    assert.equal(h.state.demandForecastForm.value.predicted_demand, null);
    assert.ok(h.visibleText().includes('正在读取人工需求预测'));
    assert.equal(h.requests.length, 2, 'One original POST and one original GET');
    h.reply(get, { code: 200, data: { forecasts: [row], accuracy: {}, high_demand_dates: [] } });
    await a.pending; await tick(); await assertBusy(h, false);
    assert.equal(h.state.revenueLoadState.value.forecasts.status, 'ready'); assert.equal(h.rows().length, 1);
    assert.equal(h.state.demandForecasts.value[0].hotel_id, 80); assert.equal(h.state.demandForecasts.value[0].forecast_date, '2026-09-15');
    assert.equal(h.rows()[0].props['data-forecast-id'], 80001);
    assert.deepEqual(h.fields(h.rows()[0]), { date: '2026-09-15', 'room-type': 'Current hotel room', demand: '7', occupancy: '65%', confidence: '80%', remark: 'Synthetic submitted A', source: '人工提供 · 未校准（非 OTA 采集事实）' });
    assert.equal(h.notices.at(-1).type, 'success');
    const savedDisplay = h.fields(h.rows()[0]);
    await newDraftB(h); assert.deepEqual(h.fields(h.rows()[0]), savedDisplay);
    assert.equal(h.requests.length, 2, 'B is explicit and not automatically submitted');
  } finally { await settlePending(h, a.pending); }
});
