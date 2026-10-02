import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original visible competitor card/tab and original handlers/coordinator. All fetches are
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
  'manualCtripPricingInputMeta', 'firstEnabledRoomTypeId', 'emptyCompetitorAnalysis', 'createCompetitorPriceForm',
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState',
  'syncRevenuePricingInputDate', 'resetCompetitorPriceForm', 'saveCompetitorPriceInput', 'loadCompetitorAnalysis',
  ...(main.includes('const competitorManualSamples =') ? ['competitorManualSamples'] : []),
];
let card, tab, hiddenAncestors, cardAncestors;
const walkAst = (node, ancestors = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-suggestion-ctrip-competitor-price-manual-input')) { card = node; cardAncestors = ancestors.filter(n => n.type === 1); }
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-ctrip-competitor-price-manual-input')) hiddenAncestors = ancestors.flatMap(a => (a.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp?.content));
  for (const child of node.children || []) walkAst(child, [...ancestors, node]);
};
const templateAst = parse(template); walkAst(templateAst); assert.ok(card && tab);
assert.ok(hiddenAncestors.includes("false && revenueAgentTab === 'analysis'"));
// Keep all original ancestors and both target subtrees; remove only unrelated siblings.
const retain = node => {
  if (node === card || node === tab) return node.loc.source;
  const children = (node.children || []).map(retain).filter(Boolean).join('');
  if (!children) return '';
  if (node.type === 0) return children;
  assert.equal(node.type, 1);
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + '</' + node.tag + '>';
};
const markup = retain(templateAst);
assert.equal(card.loc.source.includes('<legend'), false, 'Current fieldset has no legend exception');
const render = new Function('Vue', compile(markup, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const text = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(text).join('') : node?.children ? text(node.children) : '';
const nodeAncestors = new WeakMap();
const flatten = (node, result = [], parents = []) => {
  if (Array.isArray(node)) node.forEach(child => flatten(child, result, parents));
  else if (node && typeof node === 'object') { nodeAncestors.set(node, parents); result.push(node); flatten(node.children, result, [...parents, node]); }
  return result;
};
const disabled = node => [node, ...(nodeAncestors.get(node) || [])].some(n => Boolean(n.props?.disabled || n.props?.inert));
const inCard = node => [node, ...(nodeAncestors.get(node) || [])].some(n => n.props?.['data-testid'] === 'agent-suggestion-ctrip-competitor-price-manual-input');
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };

async function harness() {
  const state = Object.fromEntries(Object.entries({
    currentPage: 'agent-center', agentTab: 'revenue', revenueAgentTab: 'settings', filterReportHotel: '80',
    demandForecastSaving: false, demandForecastForm: {}, demandForecasts: [], forecastAccuracy: {}, highDemandDates: [],
    forecastFilter: { start_date: '2026-09-15', end_date: '2026-09-16' }, priceSuggestionFilter: { date: '2026-09-15' },
    competitorPriceForm: { analysis_date: '2026-09-15' }, competitorFilter: { date: '2026-09-15' }, competitorPriceSaving: false, competitorAnalysis: {}, competitorAnalysisLoading: false, competitorAnalysisError: '', competitorMicroscopeSelectedKey: '',
    roomTypeConfigList: [{ id: 501, hotel_id: 80, name: 'Current hotel room', is_enabled: 1 }, { id: 502, hotel_id: 80, name: 'Second current hotel room', is_enabled: 1 }],
    revenueLoadState: { competitor: { status: 'not_loaded', error: '' } },
    permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const requests = [], notices = [];
  const sandbox = {
    ...state, computed: Vue.computed, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl,
    setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 0, agentRevenueStateEpoch: 1, competitorAnalysisRequestSeq: 0,
    user: Vue.ref({ id: 11, tenant_id: 7 }), token: Vue.ref(''),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: 1, token: '' }), isAuthSessionCurrent: value => value.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    readRequestCache: () => null, writeRequestCache() {},
    formatDate: () => { throw new Error('Explicit date required in this fixture'); },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    loadPriceSuggestions: async () => {}, loadRevenueAnalysis: async () => {}, loadRevenueDashboard: async () => {}, loadRevenueAiOverview: async () => {},
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok(['/api/agent/competitor-analysis', '/api/online-data/competitor-summary'].includes(parsed.pathname)); if (parsed.pathname === '/api/online-data/competitor-summary') assert.equal(options.method || 'GET', 'GET');
      assert.ok(['GET', 'POST'].includes(options.method || 'GET'));
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.createContext(sandbox); vm.runInContext(system, sandbox);
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  vm.runInContext(readFileSync('public/revenue-overview-contract-static.js', 'utf8'), sandbox);
  vm.runInContext(readFileSync('public/revenue-ai-static.js', 'utf8'), sandbox);
  sandbox.ensureRevenueAiStaticReady = async () => sandbox.window.SUXI_REVENUE_AI_STATIC;
  sandbox.revenueAiBuildCompetitorMicroscope = sandbox.window.SUXI_REVENUE_AI_STATIC.buildCompetitorMicroscope;
  sandbox.revenueAiNormalizeMeituanCompetitionCircle = sandbox.window.SUXI_REVENUE_AI_STATIC.normalizeMeituanCompetitionCircle;
  vm.runInContext(requestSource + '\n' + names.map(declaration).join('\n') + '\nglobalThis.methods={' + names.join(',') + '};', sandbox);
  state.competitorPriceForm.value = sandbox.methods.createCompetitorPriceForm();
  const context = { ...state, ...sandbox.methods, loadPriceSuggestionWorkbench: async () => {} };
  let tree;
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
  const nodes = () => flatten(tree);
  const visibleText = () => text(tree);
  const rows = () => nodes().filter(node => node.type === 'tr' && node.props?.['data-competitor-sample-id'] !== undefined);
  const fields = row => Object.fromEntries(flatten(row).filter(node => node.props?.['data-field']).map(node => [node.props['data-field'], text(node).trim()]));
  const latest = method => requests.findLast(req => !req.settled && (req.options.method || 'GET') === method);
  const reply = (req, data, status = 200) => {
    assert.ok(req && !req.settled); req.settled = true;
    req.resolve(new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } }));
  };
  const click = async label => {
    await html(); const node = nodes().find(item => item.type === 'button' && text(item).trim() === label);
    assert.ok(node && !disabled(node), 'Original button and all ancestors must be enabled: ' + label);
    const pending = node.props.onClick(); await tick(); return { pending };
  };
  const controlInput = async (predicate, value) => {
    await html(); const node = nodes().find(predicate);
    assert.ok(node && inCard(node) && !disabled(node), 'Never dispatch input/change to a disabled control or ancestor');
    const listeners = {}, element = { type: node.props.type || 'text', value: String(value), composing: false, addEventListener: (event, handler) => { listeners[event] = handler; } };
    if (node.type === 'select') {
      element.multiple = false;
      element.options = nodes().filter(n => n.type === 'option').map(n => ({ _value: n.props.value, value: String(n.props.value), selected: Number(n.props.value) === Number(value) }));
      Vue.vModelSelect.created(element, { value: state.competitorPriceForm.value.room_type_id, modifiers: { number: true } }, node);
      listeners.change({ target: element });
    } else {
      Vue.vModelText.created(element, { modifiers: node.props.type === 'number' ? { number: true } : node.props.type === 'date' ? {} : { trim: true } }, node);
      listeners.input({ target: element });
    }
    await tick();
  };
  const controls = () => nodes().filter(n => inCard(n) && ['input', 'select', 'button'].includes(n.type)).map(n => ({ tag: n.type, placeholder: n.props?.placeholder || '', label: n.type === 'button' ? text(n).trim() : '', disabled: disabled(n) }));
  const settleReads = (data, options = {}) => {
    const gets = requests.filter(req => !req.settled && (req.options.method || 'GET') === 'GET');
    assert.equal(gets.length, 2);
    for (const req of gets) {
      const url = new URL(req.url); assert.equal(url.searchParams.get('hotel_id'), '80');
      if (url.pathname === '/api/agent/competitor-analysis') {
        assert.equal(url.searchParams.get('date'), state.competitorFilter.value.date);
        const status = options.ctripStatus || 200;
        reply(req, { code: status, message: status === 200 ? '' : 'Synthetic Ctrip failure', data }, status);
      } else {
        assert.equal(url.searchParams.get('target_date'), state.competitorFilter.value.date);
        const status = options.meituanStatus || 200;
        reply(req, { code: status, message: status === 200 ? '' : 'Synthetic Meituan failure', data: options.meituanData || { data_status: 'missing', system_hotel_id: 80, target_date: state.competitorFilter.value.date, display_hotels: [] } }, status);
      }
    }
  };
  const read = async (data, options = {}) => {
    const { pending } = await click('刷新'); settleReads(data, options);
    await pending; await tick(); await html();
  };
  await html(); const originalTab = nodes().find(node => node.type === 'button');
  assert.ok(originalTab); originalTab.props.onClick(); await tick(); await html();
  assert.equal(state.revenueAgentTab.value, 'suggestions');
  return { state, requests, notices, html, nodes, visibleText, rows, fields, latest, reply, click, controlInput, controls, read, settleReads };
}

// Synthetic read rows preserve the getPriceMatrix fields used by this card.
// Unused readiness/statistical details are a fixture subset, not a claim that
// the producer omits them. Source is never inferred from that projection.
// No producer/controller/database method runs.
const manual = (overrides = {}) => ({
  id: 76001, analysis_date: '2026-09-15', hotel_id: 80, room_type_id: 501,
  room_type_name: 'Synthetic room A', competitor_hotel_id: 0, competitor_room_type_id: 0,
  competitor_name: 'Synthetic competitor A', ota_platform: 1, ota_platform_name: '携程',
  our_price: 291, competitor_price: 287, difference: 4, diff_percent: 1.39, status: '我方高',
  sample_key: 'Synthetic competitor A',
  competitor_data: { input_type: 'manual_ctrip_competitor_price_sample', evidence_status: 'operator_provided', source_scope: 'ctrip_ota_channel', auto_write_ota: false, competitor_name: 'Synthetic competitor A' },
  price_signal_readiness: { stage: 'competitor_signal_observed', status_label: '已采样待判断', authority_status: 'diagnostic_only', closed_loop: false, execution_ready: false },
  ...overrides,
});
const response = (rows = []) => {
  const matrix = {};
  for (const row of rows) {
    const group = matrix[row.room_type_name || '未返回房型'] ||= {};
    let key = row.competitor_name || '未返回名称';
    if (Object.hasOwn(group, key)) key += `|platform:${row.ota_platform}|sample:${row.id}`;
    group[key] = { ...row, sample_key: key };
  }
  return { price_matrix: matrix, alerts: [], trends: [], date: '2026-09-15', query_scope: { hotel_id: 80, date: '2026-09-15', metric_scope: 'ota_channel' } };
};
const panel = h => h.nodes().find(n => n.props?.['data-testid'] === 'competitor-manual-samples');
const status = h => panel(h)?.props?.['data-status'];
const meituan = { data_status: 'success', system_hotel_id: 80, latest_data_date: '2026-09-15', display_hotels: [{ poiId: 'synthetic-mt-9', hotelName: 'Synthetic Meituan competitor', isSelf: false }] };

const clone = value => JSON.parse(JSON.stringify(value));
const A = { analysis_date: '2026-09-15', room_type_id: 501, competitor_hotel_id: 0, competitor_name: 'Synthetic A', our_price: 291, competitor_price: 287 };
const B = { analysis_date: '2026-09-16', room_type_id: 502, competitor_hotel_id: 8202, competitor_name: 'Synthetic B', our_price: 321, competitor_price: 317 };
const fill = async (h, draft) => {
  await h.controlInput(n => n.type === 'input' && n.props?.type === 'date', draft.analysis_date);
  await h.controlInput(n => n.type === 'select', draft.room_type_id);
  for (const [key, placeholder] of [['our_price', '本店价'], ['competitor_price', '竞品价'], ['competitor_hotel_id', '竞对ID（未知请留空）'], ['competitor_name', '竞对名称（不知道ID时必填）']]) await h.controlInput(n => n.type === 'input' && n.props?.placeholder === placeholder, draft[key]);
  assert.deepEqual(clone(h.state.competitorPriceForm.value), draft);
};
const assertControls = (snapshot, expectedDisabled) => {
  assert.equal(snapshot.length, 8, 'Six original editable fields plus refresh/save, no user clear control');
  assert.equal(snapshot.filter(n => ['input', 'select'].includes(n.tag)).length, 6);
  assert.deepEqual(snapshot.filter(n => n.tag === 'button').map(n => n.label), ['刷新', '保存样本']);
  assert.ok(snapshot.every(n => n.disabled === expectedDisabled), 'All original controls inherit the correct busy state');
};
const assertPayload = (payload, draft) => {
  assert.equal(payload.hotel_id, 80); assert.equal(payload.ota_platform, 1);
  for (const key of ['analysis_date', 'room_type_id', 'our_price', 'competitor_price', 'competitor_hotel_id']) assert.equal(payload[key], draft[key]);
  assert.equal(payload.competitor_data.competitor_name, draft.competitor_name);
  assert.equal(payload.competitor_data.input_type, 'manual_ctrip_competitor_price_sample');
  assert.equal(payload.competitor_data.auto_write_ota, false);
};

test('HTTP500 retains submitted A and unlocks every original control for explicit later B edits', async () => {
  const h = await harness();
  assert.equal(h.state.revenueAgentTab.value, 'suggestions');
  assert.deepEqual(cardAncestors.map(n => n.tag), ['div', 'div', 'div', 'div', 'div', 'div']);
  assert.equal(h.nodes().some(n => n.type === 'form'), false, 'Actual save entry is an enabled native button, not form submit');
  assertControls(h.controls(), false);
  await fill(h, A); const { pending } = await h.click('保存样本');
  const post = h.latest('POST'); assertPayload(JSON.parse(post.options.body), A);
  await h.html(); const duringPost = h.controls();
  assert.equal(h.state.competitorPriceSaving.value, true);
  // Observe disabled descendants without ever dispatching a forbidden edit,
  // refresh or repeat-save event. Settle before checking the old-source oracle.
  h.reply(post, { code: 500, message: 'Synthetic write response failure', data: null }, 500);
  await pending; await tick(); await h.html();
  assertControls(duringPost, true);
  assert.deepEqual(clone(h.state.competitorPriceForm.value), A);
  assert.equal(h.state.competitorPriceSaving.value, false); assertControls(h.controls(), false);
  assert.equal(h.notices.length, 1); assert.equal(h.notices[0].type, 'error');
  assert.match(h.notices[0].message, /Synthetic write response failure/);
  assert.equal(h.requests.length, 1, 'Failure does not issue an automatic POST or GET');
  await fill(h, B); assertControls(h.controls(), false);
  assert.equal(h.requests.length, 1, 'Editing after failure does not save implicitly');
});

test('success keeps the card locked through original confirmation GETs and permits B only after completion', async () => {
  const h = await harness(); await fill(h, A);
  const { pending } = await h.click('保存样本'); const post = h.latest('POST');
  const submitted = JSON.parse(post.options.body); assertPayload(submitted, A);
  await h.html(); const duringPost = h.controls();
  // Real controller POST contract is only {id}; do not invent strict flags.
  h.reply(post, { code: 200, message: '记录成功', data: { id: 79002 } });
  await tick(); await h.html(); const duringRead = h.controls();
  assert.equal(h.state.competitorPriceSaving.value, true, 'Original save awaits both read requests');
  const gets = h.requests.filter(r => !r.settled && (r.options.method || 'GET') === 'GET');
  assert.equal(gets.length, 2);
  for (const get of gets) {
    const url = new URL(get.url); assert.equal(url.searchParams.get('hotel_id'), '80');
    assert.equal(url.searchParams.get(url.pathname === '/api/agent/competitor-analysis' ? 'date' : 'target_date'), A.analysis_date);
  }
  const saved = manual({ id: 79002, competitor_name: A.competitor_name, competitor_data: submitted.competitor_data });
  const ctripGet = gets.find(r => new URL(r.url).pathname === '/api/agent/competitor-analysis');
  const meituanGet = gets.find(r => new URL(r.url).pathname === '/api/online-data/competitor-summary');
  h.reply(ctripGet, { code: 200, data: response([saved]) }); await tick(); await h.html();
  const whileSecondReadPending = h.controls();
  assert.equal(h.state.competitorPriceSaving.value, true, 'One completed GET cannot release the other pending GET lock');
  h.reply(meituanGet, { code: 200, data: { data_status: 'missing', system_hotel_id: 80, target_date: A.analysis_date, display_hotels: [] } });
  await pending; await tick(); await h.html();
  assertControls(duringPost, true); assertControls(duringRead, true); assertControls(whileSecondReadPending, true);
  assert.equal(h.state.competitorPriceSaving.value, false); assertControls(h.controls(), false);
  assert.deepEqual(clone(h.state.competitorPriceForm.value), { analysis_date: A.analysis_date, room_type_id: 501, competitor_hotel_id: 0, competitor_name: '', our_price: null, competitor_price: null }, 'Original success reset still runs');
  assert.equal(h.rows().length, 1);
  assert.deepEqual(h.fields(h.rows()[0]), { date: A.analysis_date, room: 'Synthetic room A', name: A.competitor_name, 'our-price': '¥291', 'competitor-price': '¥287', source: '携程 · 人工录入' });
  assert.equal(h.notices.filter(n => n.type === 'error').length, 0);
  assert.equal(h.requests.length, 3, 'One explicit synthetic POST and its two original GETs only');
  await fill(h, B); assertControls(h.controls(), false);
  assert.equal(h.fields(h.rows()[0]).name, A.competitor_name, 'Later unsaved B never replaces the read A record');
  assert.equal(h.requests.length, 3, 'Later explicit edits do not auto-save');
});
