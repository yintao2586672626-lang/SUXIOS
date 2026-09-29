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
const names = [...(main.includes('            const applyRoomTypeReadback =') ? ['applyRoomTypeReadback'] : []), 'createRoomTypeConfigForm', 'firstEnabledRoomTypeId', 'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState', 'loadRoomTypes'];
let card, tab;
const walkAst = (node, ancestors = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-room-type-pricing-guard')) card = node;
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  for (const child of node.children || []) walkAst(child, [...ancestors, node]);
};
walkAst(parse(template)); assert.ok(card && tab);
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
    demandForecastForm: {}, competitorPriceForm: {}, roomTypeConfigList: [],
    roomTypeConfigForm: {}, roomTypeConfigSaving: false, roomTypeConfigMeta: {},
    revenueLoadState: { roomTypes: { status: 'not_loaded', error: '' } },
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
      assert.equal(parsed.pathname, '/api/agent/room-types');
      assert.equal(options.method || 'GET', 'GET', 'Only a synthetic read is permitted');
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.createContext(sandbox); vm.runInContext(system, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  vm.runInContext(requestSource + '\n' + names.map(declaration).join('\n') + '\nglobalThis.methods={' + names.join(',') + '};', sandbox);
  state.roomTypeConfigForm.value = sandbox.methods.createRoomTypeConfigForm();
  const forbiddenWrite = () => { throw new Error('Room configuration writes are outside this read fixture'); };
  // The tab's unrelated bundle is closed; the card refresh uses the real loader/request.
  const context = { ...state, ...sandbox.methods, saveRoomTypeConfig: forbiddenWrite, editRoomTypeConfig: forbiddenWrite, resetRoomTypeConfigForm: forbiddenWrite, loadPriceSuggestionWorkbench: async () => {} };
  let tree;
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
  const nodes = () => flatten(tree);
  const visibleText = () => text(tree);
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
  const read = async data => {
    const { pending } = await click('刷新'); reply(latest('GET'), { code: 200, data });
    const result = await pending; await tick(); await html(); return result;
  };
  await html(); const originalTab = nodes().find(node => node.type === 'button');
  assert.ok(originalTab); originalTab.props.onClick(); await tick(); await html();
  assert.equal(state.revenueAgentTab.value, 'suggestions');
  return { state, requests, notices, html, nodes, visibleText, latest, reply, click, read };
}

const emptyText = '暂无房型配置，携程调价建议生成会保持受阻';
// GET /agent/room-types returns model rows plus these public input metadata.
// This closed DTO is not an execution of its controller, database or OTA service.
const payload = list => ({
  list, input_scope: 'manual_pricing_configuration', target_workflow: 'ctrip_revenue_ai_pricing_generation',
  evidence_status: 'operator_provided', auto_write_ota: false,
  next_action: list.length ? '继续补齐需求预测和竞对价格样本后，再生成待审调价建议。' : '先配置至少一个启用房型、基础价和最低保护价；未配置前不生成待审调价建议。',
});
const row = () => ({ id: 501, tenant_id: 7, hotel_id: 80, name: 'Synthetic recovered room', base_price: 280, min_price: 180, max_price: 480, room_count: 0, is_enabled: 1, sort_order: 0 });
const roleText = (h, role) => h.nodes().filter(node => node.props?.role === role).map(text).join('');
const assertRecovered = h => {
  assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'ready');
  assert.equal(h.state.roomTypeConfigList.value[0].id, 501);
  assert.equal(h.visibleText().includes(emptyText), false);
  assert.equal(roleText(h, 'alert'), '');
  const cells = h.nodes().find(node => node.type === 'tr' && text(node).includes('Synthetic recovered room'))?.children.filter(node => node.type === 'td');
  assert.ok(cells, 'Original table row is visible after explicit recovery');
  assert.deepEqual(cells.slice(0, 6).map(cell => text(cell).trim()), ['Synthetic recovered room', '¥280', '¥180', '¥480', '0', '启用']);
  assert.equal(h.state.roomTypeConfigMeta.value.auto_write_ota, false);
  assert.equal(h.state.demandForecastForm.value.room_type_id, 501, 'Original default room selection remains');
  assert.equal(h.state.competitorPriceForm.value.room_type_id, 501);
};

test('unread room configuration is distinct from a confirmed empty list on the original card', async () => {
  const h = await harness();
  assert.equal(h.state.revenueAgentTab.value, 'suggestions');
  assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'not_loaded');
  assert.ok(h.visibleText().includes('房型与最低保护价'));
  assert.ok(roleText(h, 'status').includes('尚未读取房型配置'));
  assert.equal(h.visibleText().includes(emptyText), false);
  assert.equal(h.requests.length, 0); assert.equal(h.notices.length, 0);
});

test('pending and failed original refresh stay distinct from empty and recover through the same button', async () => {
  const h = await harness();
  const { pending } = await h.click('刷新'); await h.html();
  const get = h.latest('GET'); assert.equal(new URL(get.url).searchParams.get('hotel_id'), '80');
  assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'loading');
  // Always settle the original request before evaluating the rendered snapshot.
  const loading = { text: h.visibleText(), status: roleText(h, 'status') };
  h.reply(get, { code: 500, message: 'Synthetic room read failure', data: null }, 500);
  assert.equal(await pending, null); await tick(); await h.html();
  assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'failed');
  assert.ok(loading.status.includes('正在读取房型配置'));
  assert.equal(loading.text.includes(emptyText), false);
  assert.ok(roleText(h, 'alert').includes('Synthetic room read failure'));
  assert.equal(h.visibleText().includes(emptyText), false);
  assert.equal(h.notices.length, 1); assert.equal(h.notices[0].type, 'error');
  assert.ok(h.notices[0].message.includes('Synthetic room read failure'));
  await tick(); await h.html();
  assert.ok(roleText(h, 'alert').includes('Synthetic room read failure'), 'Failure persists in the card beyond its global toast');
  assert.equal(h.requests.length, 1, 'No automatic retry or write');
  await h.read(payload([row()])); assertRecovered(h);
  assert.equal(h.requests.length, 2); assert.equal(h.notices.length, 1);
});

test('a successful empty GET retains the existing legitimate no-configuration message', async () => {
  const h = await harness(); const result = await h.read(payload([]));
  assert.equal(Array.isArray(result), true); assert.equal(result.length, 0);
  assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'empty');
  assert.ok(h.visibleText().includes(emptyText));
  assert.equal(roleText(h, 'alert'), ''); assert.equal(roleText(h, 'status'), '');
  assert.equal(h.notices.length, 0); assert.equal(h.requests.length, 1);
});

test('malformed room lists fail instead of empty success and each permits explicit refresh recovery', async () => {
  const h = await harness();
  for (const data of [{}, { list: null }, { list: {} }, { list: [null] }, { list: [[]] }, { list: [4] }, { list: ['bad'] }]) {
    const result = await h.read(data);
    // Check the original handler result first: this identifies the malformed DTO
    // false success independently of the card's separate visibility regression.
    assert.equal(result, null, 'Missing/malformed list cannot confirm an empty read');
    assert.equal(h.state.revenueLoadState.value.roomTypes.status, 'failed');
    assert.equal(h.state.roomTypeConfigList.value.length, 0);
    assert.ok(roleText(h, 'alert').includes('房型价保读取返回格式错误'));
    assert.equal(h.visibleText().includes(emptyText), false);
    assert.equal(h.notices.at(-1).type, 'error');
    await h.read(payload([row()])); assertRecovered(h);
  }
  assert.equal(h.requests.length, 14); assert.equal(h.notices.length, 7);
  assert.ok(h.requests.every(req => (req.options.method || 'GET') === 'GET'));
});
