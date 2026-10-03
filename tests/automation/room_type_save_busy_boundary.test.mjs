import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Closed UI harness: original card/handlers/request; synthetic responses only.
// Invoke from the repository root, like the adjacent automation tests.
const files = ['public/app-main.js', 'resources/frontend/templates/fragments/27-page-agent-center.html', 'public/system-static.js'];
const bytes = Object.fromEntries(files.map(path => [path, readFileSync(path)]));
const main = bytes[files[0]].toString();
const part = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return main.slice(a, b); };
const declaration = name => { const a = main.indexOf('            const ' + name + ' ='); assert.ok(a >= 0, name); const tail = main.slice(a + 1), match = /\n            (?:const|let) /.exec(tail); assert.ok(match, name); return main.slice(a, a + 1 + match.index); };
const requestSource = [part('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='), part('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='), part('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='), part('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'), part('            const request = async (', '            const apiRequest = request;')].join('\n');
const names = ['roomTypeConfigReadState','roomTypeConfigSavedRowMatches','verifyRoomTypeConfigSaveReadback',...(main.includes('            const applyRoomTypeReadback =') ? ['applyRoomTypeReadback'] : []), 'createRoomTypeConfigForm', 'firstEnabledRoomTypeId', 'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState', 'resetRoomTypeConfigForm', 'editRoomTypeConfig', 'loadRoomTypes', 'saveRoomTypeConfig'];

const manualMeta = { input_scope: 'manual_pricing_configuration', target_workflow: 'ctrip_revenue_ai_pricing_generation', evidence_status: 'operator_provided', auto_write_ota: false, next_action: '继续补齐需求预测和竞对价格样本后，再生成待审调价建议。' };
const rows = [ { id: 501, hotel_id: 80, name: 'Synthetic room A', base_price: 300, min_price: 250, max_price: 500, room_count: 5, sort_order: 0, is_enabled: 1, facilities: [] }, { id: 502, hotel_id: 80, name: 'Synthetic room B', base_price: 400, min_price: 350, max_price: 600, room_count: 6, sort_order: 1, is_enabled: 1, facilities: [] } ];

async function harness() {
const requests = [], notices = [], boundaries = [];
const state = Object.fromEntries(Object.entries({ currentPage: 'agent-center', agentTab: 'revenue', revenueAgentTab: 'settings', filterReportHotel: '80', roomTypeConfigSaving: false, roomTypeConfigForm: {}, roomTypeConfigList: [], roomTypeConfigMeta: {}, revenueLoadState: {}, demandForecastForm: { room_type_id: null }, competitorPriceForm: { room_type_id: null }, permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }] }).map(([key, value]) => [key, Vue.ref(value)]));
const sandbox = { ...state, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl, setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api', roomTypesRequestSequence:0, authSessionEpoch: 1, pageRequestGeneration: 0, agentRevenueStateEpoch: 1, user: Vue.ref({ id: 11, tenant_id: 7 }), token: Vue.ref(''), authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'), captureAuthSession: () => ({ epoch: 1, token: '' }), isAuthSessionCurrent: value => value.epoch === 1, isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} }, readRequestCache: () => null, writeRequestCache() {}, showToast: (message, type = 'success') => notices.push({ message, type }),
  loadRevenueDashboard: async () => { boundaries.push('dashboard refresh excluded'); }, loadRevenueAiOverview: async () => { boundaries.push('AI overview refresh excluded'); },
  fetch: (url, options = {}) => new Promise((resolve, reject) => { const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(parsed.pathname, '/api/agent/room-types'); assert.ok(['GET', 'POST'].includes(options.method || 'GET')); requests.push({ url, options, resolve, reject, settled: false }); }),
};
state.roomTypeConfigSaveReadback=Vue.ref(null); sandbox.roomTypeConfigSaveReadback=state.roomTypeConfigSaveReadback; sandbox.computed=Vue.computed; vm.createContext(sandbox); vm.runInContext(bytes[files[2]].toString(), sandbox); sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC; sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
vm.runInContext(requestSource + '\n' + names.map(declaration).join('\n') + '\nglobalThis.methods={' + names.join(',') + '};', sandbox);
state.roomTypeConfigForm.value = sandbox.methods.createRoomTypeConfigForm();
let card, tab, ancestors;
const walkAst = (node, parents = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-room-type-pricing-guard')) { card = node; ancestors = parents; }
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  for (const child of node.children || []) walkAst(child, [...parents, node]);
};
walkAst(parse(bytes[files[1]].toString())); assert.ok(card && tab);
const conditions = ancestors.flatMap(n => (n.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp.content));
assert.ok(conditions.includes("currentPage === 'agent-center'")); assert.ok(conditions.includes("agentTab === 'revenue'")); assert.ok(conditions.includes("revenueAgentTab === 'suggestions'")); assert.ok(!conditions.some(value => /\bfalse\b/.test(value)));
assert.ok(!ancestors.some(node => node.tag === 'fieldset' || (node.props || []).some(p => p.name === 'disabled' || p.arg?.content === 'disabled')));
const markup = '<div v-if="currentPage === \'agent-center\'"><div v-if="agentTab === \'revenue\'">' + tab.loc.source + '<div v-if="revenueAgentTab === \'suggestions\'">' + card.loc.source + '</div></div></div>';
const render = new Function('Vue', compile(markup, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const context = { ...state, ...sandbox.methods, loadPriceSuggestionWorkbench: async () => { boundaries.push('tab workbench read excluded; explicit card refresh exercised'); } };
let tree;
const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
const nodes = (node = tree, rows = [], parents = []) => { if (Array.isArray(node)) node.forEach(n => nodes(n, rows, parents)); else if (node && typeof node === 'object') { rows.push({ node, parents }); nodes(node.children, rows, [...parents, node]); } return rows; };
const text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
const disabled = entry => !!entry.node.props?.disabled || entry.parents.some(p => p.type === 'fieldset' && p.props?.disabled);
const find = fn => nodes().find(entry => fn(entry.node));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
let checks = 0;
const eq = (a, b, label) => { checks++; assert.deepEqual(a, b, label); };
const ok = (value, label) => { checks++; assert.ok(value, label); };
const clone = value => JSON.parse(JSON.stringify(value));
const input = async (placeholder, value) => {
  await html(); const entry = find(n => n.type === 'input' && n.props?.placeholder === placeholder); ok(entry && !disabled(entry), 'Only enabled original input');
  const listeners = {}, number = entry.node.props.type === 'number', element = { type: number ? 'number' : 'text', value: String(value), composing: false, addEventListener: (event, handler) => { listeners[event] = handler; } };
  Vue.vModelText.created(element, { modifiers: number ? { number: true } : { trim: true } }, entry.node); listeners.input({ target: element }); await tick();
};
const status = async value => {
  await html(); const entry = find(n => n.type === 'select' && n.props?.['aria-label'] === '房型状态'); ok(entry && !disabled(entry));
  const listeners = {}, element = { multiple: false, options: [1, 0].map(item => ({ selected: item === value, value: String(item), _value: item })), addEventListener: (event, handler) => { listeners[event] = handler; } };
  Vue.vModelSelect.created(element, { value: state.roomTypeConfigForm.value.is_enabled, modifiers: { number: true } }, entry.node); listeners.change({ target: element }); await tick();
};
const click = async predicate => { await html(); const entry = find(n => n.type === 'button' && predicate(n)); ok(entry && !disabled(entry), 'Only enabled original button'); const pending = entry.node.props.onClick(); await tick(); return { pending }; };
const edit = async id => { await html(); const row = find(n => n.type === 'tr' && n.key === id); ok(row); const entry = nodes().find(e => e.parents.includes(row.node) && e.node.type === 'button' && text(e.node).trim() === '编辑'); ok(entry && !disabled(entry)); entry.node.props.onClick(); await tick(); };
const reply = (req, body, code = 200) => { ok(req && !req.settled); req.settled = true; req.resolve(new Response(JSON.stringify(body), { status: code, headers: { 'Content-Type': 'application/json' } })); };
const nextRequest = method => requests.findLast(req => !req.settled && (req.options.method || 'GET') === method);

await html(); await click(n => text(n).includes('远期定价台账'));
ok((await html()).includes('不是 OTA 自动采集事实，不写 OTA。'));
const initialRead = await click(n => text(n).trim() === '刷新');
const initialGet = nextRequest('GET'); eq(new URL(initialGet.url).searchParams.get('hotel_id'), '80');
reply(initialGet, { code: 200, data: { list: rows, ...manualMeta } }); await initialRead.pending;
const beginA = async () => {
  await edit(501); await input('房型名称', 'Submitted A');
  await input('基础价', '310'); await input('最低保护价', '260');
  await input('最高限制价', '510'); await input('房量', '7'); await status(1);
  await html(); const form = find(n => n.type === 'form'); const submit = find(n => n.type === 'button' && n.props?.type === 'submit');
  ok(form && submit && !disabled(submit));
  const inputs = nodes().filter(e => e.parents.includes(form.node) && e.node.type === 'input');
  eq(inputs.length, 5); ok(inputs.every(e => e.node.props.required !== undefined && !disabled(e)));
  const draft = clone(state.roomTypeConfigForm.value);
  ok(draft.name.length > 0 && draft.name.length <= 80);
  for (const field of ['base_price', 'min_price', 'max_price']) ok(typeof draft[field] === 'number' && draft[field] >= 0.01);
  ok(draft.min_price <= draft.base_price && draft.max_price >= draft.base_price);
  ok(Number.isInteger(draft.room_count) && draft.room_count >= 0);
  let prevented = false; const pending = form.node.props.onSubmit({ preventDefault() { prevented = true; } });
  await tick(); ok(prevented); const post = nextRequest('POST'); ok(post);
  const payload = JSON.parse(post.options.body); eq(payload.id, 501); eq(Number(payload.hotel_id), 80); eq(payload.base_price, 310); eq(payload.name, 'Submitted A');
  return { pending, post, payload, draft };
};
const controls = async () => {
  await html(); const cardEntry = find(n => n.props?.['data-testid'] === 'agent-room-type-pricing-guard'); ok(cardEntry);
  return nodes().filter(e => e.parents.includes(cardEntry.node) && ['input', 'select', 'button'].includes(e.node.type));
};
const assertLocked = async (expectRows) => {
  eq(state.roomTypeConfigSaving.value, true);
  const entries = await controls();
  eq(entries.filter(e => e.node.type === 'input').length, 5); eq(entries.filter(e => e.node.type === 'select').length, 1);
  ok(entries.some(e => e.node.props?.title === '清空')); ok(entries.some(e => text(e.node).trim() === '刷新'));
  eq(entries.filter(e => e.node.type === 'button' && text(e.node).trim() === '编辑').length, expectRows ? 2 : 0);
  for (const e of entries) ok(disabled(e), 'Every visible room-card control inherits the save lock: ' + (e.node.props?.placeholder || e.node.props?.title || text(e.node)));
  const rendered = await html(); ok(/<fieldset[^>]*disabled[^>]*data-testid="agent-room-type-pricing-guard"/.test(rendered));
  // Do not dispatch any input/click on these effectively disabled native controls.
};
const assertUnlocked = async () => { eq(state.roomTypeConfigSaving.value, false); for (const e of await controls()) ok(!disabled(e)); };
const beginBThenClear = async () => {
  await edit(502); await input('房型名称', 'Explicit next B'); await input('基础价', '420'); await status(0);
  eq(state.roomTypeConfigForm.value.id, 502); eq(state.roomTypeConfigForm.value.name, 'Explicit next B'); eq(state.roomTypeConfigForm.value.base_price, 420); eq(state.roomTypeConfigForm.value.is_enabled, 0);
  await click(n => n.props?.title === '清空'); eq(state.roomTypeConfigForm.value.id, null); eq(state.roomTypeConfigForm.value.name, '');
};
return { state, requests, notices, beginA, assertLocked, assertUnlocked, beginBThenClear, reply, nextRequest, tick, eq, ok, clone, html, checks: () => checks };
}

test('room configuration locks the entire card during POST; HTTP500 preserves A and restores explicit edit/clear', async t => {
  const h = await harness(); const a = await h.beginA();
  await h.assertLocked(true); h.eq(h.clone(h.state.roomTypeConfigForm.value), a.draft);
  h.eq(h.requests.filter(r => r.options.method === 'POST').length, 1);
  h.reply(a.post, { code: 500, message: 'Synthetic room configuration save failed', data: null }, 500);
  await a.pending; await h.tick(); await h.assertUnlocked();
  h.eq(h.clone(h.state.roomTypeConfigForm.value), a.draft);
  h.ok(h.notices.at(-1).type === 'warning' && /保存响应中断，结果尚未确认/.test(h.notices.at(-1).message));
  h.eq(h.state.roomTypeConfigSaveReadback.value.status, 'unconfirmed');
  h.eq(h.requests.length, 2, 'Failure does not trigger refresh or replay');
  await h.beginBThenClear(); h.eq(h.requests.filter(r => r.options.method === 'POST').length, 1);
  t.diagnostic(h.checks() + ' behavioral assertions');
});

test('room configuration lock persists through successful POST and pending exact-scope GET; completion permits next draft', async t => {
  const h = await harness(); const a = await h.beginA(); await h.assertLocked(true);
  const saved = { ...rows[0], ...a.payload, hotel_id: 80, facilities: [] };
  h.reply(a.post, { code: 200, message: 'room type saved', data: { room_type: saved, ...manualMeta } }); await h.tick();
  const get = h.nextRequest('GET'); h.ok(get); h.eq(new URL(get.url).searchParams.get('hotel_id'), '80');
  await h.assertLocked(false); h.eq(h.state.revenueLoadState.value.roomTypes.status, 'loading');
  h.ok((await h.html()).includes('正在读取房型配置'));
  h.reply(get, { code: 200, data: { list: [saved, rows[1]], ...manualMeta } });
  await a.pending; await h.tick(); await h.assertUnlocked();
  h.eq(h.state.roomTypeConfigForm.value.id, null); h.eq(h.state.roomTypeConfigForm.value.name, '');
  h.eq(h.clone(h.state.roomTypeConfigList.value), [saved, rows[1]]); h.eq(h.state.revenueLoadState.value.roomTypes.status, 'ready');
  h.ok((await h.html()).includes('Submitted A')); h.eq(h.notices.at(-1).type, 'success'); h.eq(h.requests.length, 3);
  await h.beginBThenClear(); h.eq(h.requests.filter(r => r.options.method === 'POST').length, 1);
  t.diagnostic(h.checks() + ' behavioral assertions');
});
