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
  'syncRevenuePricingInputDate', 'resetCompetitorPriceForm', 'competitorPriceSampleMatches', 'verifyCompetitorPriceSaveReadback', 'saveCompetitorPriceInput', 'loadCompetitorAnalysis',
  ...(main.includes('const competitorManualSamples =') ? ['competitorManualSamples'] : []),
];
let card, tab, hiddenAncestors;
const walkAst = (node, ancestors = []) => {
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-suggestion-ctrip-competitor-price-manual-input')) card = node;
  if (node.type === 1 && node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) tab = node;
  if (node.type === 1 && node.props.some(p => p.name === 'data-testid' && p.value?.content === 'agent-ctrip-competitor-price-manual-input')) hiddenAncestors = ancestors.flatMap(a => (a.props || []).filter(p => p.type === 7 && p.name === 'if').map(p => p.exp?.content));
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
    competitorPriceForm: { analysis_date: '2026-09-15' }, competitorFilter: { date: '2026-09-15' }, competitorPriceSaving: false, competitorAnalysis: {}, competitorAnalysisLoading: false, competitorAnalysisError: '', competitorMicroscopeSelectedKey: '',
    roomTypeConfigList: [{ id: 501, hotel_id: 80, name: 'Current hotel room', is_enabled: 1 }],
    revenueLoadState: { competitor: { status: 'not_loaded', error: '' } },
    permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const requests = [], notices = [];
  state.competitorPriceSaveReadback = Vue.ref(null);
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
  return { state, requests, notices, html, nodes, visibleText, rows, fields, latest, reply, click, input, read, settleReads };
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

test('visible card distinguishes unread and pending reads without claiming no saved samples', async () => {
  const h = await harness();
  assert.equal(status(h), 'not_loaded'); assert.match(h.visibleText(), /尚未读取/); assert.equal(h.rows().length, 0);
  const { pending } = await h.click('刷新'); await h.html();
  assert.equal(status(h), 'loading'); assert.match(h.visibleText(), /正在读取/);
  h.settleReads(response()); await pending; await tick(); await h.html();
  assert.equal(status(h), 'empty'); assert.match(h.visibleText(), /暂无携程人工样本/);
});

test('real save and refresh show the separately read sample with exact fields and manual Ctrip source', async () => {
  const h = await harness();
  await h.input('本店价', 291); await h.input('竞品价', 287); await h.input('竞对名称（不知道ID时必填）', 'Synthetic competitor A');
  const { pending } = await h.click('保存样本'); const post = h.latest('POST'); assert.ok(post);
  const payload = JSON.parse(post.options.body);
  assert.equal(payload.hotel_id, 80); assert.equal(payload.room_type_id, 501); assert.equal(payload.analysis_date, '2026-09-15');
  assert.equal(payload.ota_platform, 1); assert.equal(payload.competitor_data.input_type, 'manual_ctrip_competitor_price_sample'); assert.equal(payload.competitor_data.auto_write_ota, false);
  h.reply(post, { code: 200, message: '记录成功', data: { id: 76001 } }); await tick();
  h.reply(h.latest('GET'), { code: 200, data: response([manual({ competitor_data: payload.competitor_data })]) }); await tick();
  h.settleReads(response([manual()]), { meituanStatus: 500 }); await pending; await tick(); await h.html();
  assert.equal(h.state.competitorPriceForm.value.our_price, null); assert.equal(h.state.competitorPriceForm.value.competitor_name, '');
  assert.equal(status(h), 'ready'); assert.equal(h.rows().length, 1);
  assert.deepEqual(h.fields(h.rows()[0]), { date: '2026-09-15', room: 'Synthetic room A', name: 'Synthetic competitor A', 'our-price': '¥291', 'competitor-price': '¥287', source: '携程 · 人工录入' });
  await h.read(response([manual()]), { meituanStatus: 500 });
  assert.equal(h.rows().length, 1); assert.equal(h.fields(h.rows()[0])['our-price'], '¥291');
  assert.equal(h.requests.filter(r => r.options.method === 'POST').length, 1);
});

test('same-name samples and separate room types retain each row rather than aggregate prices', async () => {
  const h = await harness();
  await h.read(response([manual(), manual({ id: 76002, our_price: 310, competitor_price: 309 }), manual({ id: 76003, room_type_id: 502, room_type_name: 'Synthetic room B', our_price: 430, competitor_price: 429 })]));
  assert.equal(h.rows().length, 3);
  assert.deepEqual(h.rows().map(r => h.fields(r)['our-price']), ['¥291', '¥310', '¥430']);
  assert.equal(h.fields(h.rows()[2]).room, 'Synthetic room B');
});

test('explicit manual type and Ctrip platform classify samples without treating readiness or other channels as manual', async () => {
  const h = await harness();
  await h.read(response([
    manual({ id: 1 }), manual({ id: 2, ota_platform: 2, ota_platform_name: '美团' }),
    manual({ id: 3, competitor_data: { evidence_status: 'operator_provided', source_scope: 'ctrip_ota_channel' } }),
    manual({ id: 4, competitor_data: { input_type: 'auto_competitor_price' } }),
    manual({ id: 5, ota_platform: 0 }), manual({ id: 6, ota_platform: true }),
    manual({ id: 7, ota_platform: '1' }),
  ]));
  assert.deepEqual(h.rows().map(r => Number(r.props['data-competitor-sample-id'])), [1, 7]);
  await h.read(response([manual({ ota_platform: 2 })])); assert.equal(status(h), 'empty');
});

test('legacy numeric zero remains visible while null, omitted and blank prices remain missing', async () => {
  const h = await harness();
  await h.read(response([manual({ id: 1, our_price: 0, competitor_price: '0' }), manual({ id: 2, our_price: null, competitor_price: undefined }), manual({ id: 3, our_price: '  ', competitor_price: false })]));
  assert.deepEqual(h.rows().map(r => [h.fields(r)['our-price'], h.fields(r)['competitor-price']]), [['¥0', '¥0'], ['未返回', '未返回'], ['未返回', '未返回']]);
});

test('Ctrip failure is visible despite accepted Meituan data and the same refresh button recovers', async () => {
  const h = await harness();
  await h.read(null, { ctripStatus: 500, meituanData: meituan });
  assert.equal(h.state.competitorAnalysis.value.meituan_competition_circle.data_status, 'success');
  assert.equal(status(h), 'failed'); assert.match(h.visibleText(), /Synthetic Ctrip failure/); assert.equal(h.rows().length, 0);
  await h.read(response([manual()]), { meituanData: meituan }); assert.equal(status(h), 'ready'); assert.equal(h.rows().length, 1);
});

test('both-source failure stays failed and does not show cached rows as current', async () => {
  const h = await harness(); await h.read(response([manual()])); assert.equal(h.rows().length, 1);
  await h.read(null, { ctripStatus: 500, meituanStatus: 500 });
  assert.equal(status(h), 'failed'); assert.equal(h.rows().length, 0); assert.match(h.visibleText(), /Synthetic Ctrip failure/);
});

for (const matrix of [{}, []]) test(`legal PHP empty ${Array.isArray(matrix) ? 'array' : 'object'} matrix is genuinely empty`, async () => {
  const h = await harness(); await h.read({ ...response(), price_matrix: matrix });
  assert.equal(status(h), 'empty'); assert.equal(h.state.competitorAnalysis.value.source_errors.ctrip, undefined);
  if (Array.isArray(matrix)) { await h.read({ ...response(), price_matrix: [{}] }); assert.equal(status(h), 'empty'); }
});

for (const [label, matrix] of [['missing', undefined], ['null', null], ['string', 'invalid'], ['numeric invalid group', [7]], ['invalid group', { room: 5 }], ['invalid row', { room: { competitor: null } }]]) {
  test(`malformed ${label} matrix is a Ctrip read failure without discarding valid Meituan`, async () => {
    const h = await harness(); await h.read({ ...response(), price_matrix: matrix }, { meituanData: meituan });
    assert.match(h.state.competitorAnalysis.value.source_errors.ctrip, /价格矩阵/);
    assert.equal(h.state.competitorAnalysis.value.meituan_competition_circle.data_status, 'success');
    assert.equal(status(h), 'failed'); assert.equal(h.rows().length, 0);
    if (label === 'invalid row') {
      await h.read({ ...response(), price_matrix: { room: { competitor: [] } } }, { meituanData: meituan });
      assert.match(h.state.competitorAnalysis.value.source_errors.ctrip, /价格矩阵/); assert.equal(status(h), 'failed');
    }
  });
}

// PHP converts sequential numeric room/competitor name keys to JSON arrays.
// These exact outer/inner shapes are independently checked with native PHP and
// the real pure enrichPriceMatrix; the formal test itself has no output dependency.
for (const [label, room, name] of [['room', '0', 'Synthetic competitor A'], ['competitor', 'Synthetic room A', '0'], ['both', '0', '0']]) {
  test(`PHP numeric ${label} name containers preserve the exact saved sample`, async () => {
    const h = await harness();
    const row = manual({ room_type_name: room, competitor_name: name, sample_key: name, competitor_data: { ...manual().competitor_data, competitor_name: name } });
    const group = name === '0' ? [row] : { [name]: row };
    const matrix = room === '0' ? [group] : { [room]: group };
    await h.read({ ...response(), price_matrix: matrix }, { meituanData: meituan });
    assert.equal(status(h), 'ready'); assert.equal(h.rows().length, 1);
    assert.equal(h.fields(h.rows()[0]).room, room); assert.equal(h.fields(h.rows()[0]).name, name);
    assert.equal(h.fields(h.rows()[0])['our-price'], '¥291'); assert.equal(h.fields(h.rows()[0]).source, '携程 · 人工录入');
  });
}

test('wrong or missing row hotel/date is an explicit failure rather than a valid empty classification', async () => {
  const h = await harness();
  for (const row of [manual({ hotel_id: 81 }), manual({ analysis_date: '2026-09-14' }), manual({ hotel_id: undefined }), manual({ analysis_date: undefined })]) {
    await h.read(response([row])); assert.equal(status(h), 'failed'); assert.match(h.visibleText(), /酒店或日期/); assert.equal(h.rows().length, 0);
  }
  await h.read(response([manual()])); assert.equal(status(h), 'ready');
});

test('wrong response query scope stays failed and a changed current hotel never displays old sample rows', async () => {
  const h = await harness(); await h.read({ ...response([manual()]), query_scope: { hotel_id: 81, date: '2026-09-15' } });
  assert.equal(status(h), 'failed'); assert.equal(h.rows().length, 0);
  await h.read(response([manual()])); assert.equal(h.rows().length, 1);
  // Read projection defense only; real hotel/page request watchers are not replaced here.
  h.state.filterReportHotel.value = '81'; await tick(); await h.html(); assert.equal(status(h), 'not_loaded'); assert.equal(h.rows().length, 0);
});
