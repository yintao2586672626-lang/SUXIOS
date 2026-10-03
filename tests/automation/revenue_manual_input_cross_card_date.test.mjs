import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Both original visible cards, global date selector, native Vue input listeners,
// original save/read/request handlers and full ancestor tree. HTTP responses are
// closed synthetic Agent DTOs. No PHP, database, browser, OTA or pricing executes.
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
  'resolveDemandForecastListPayload', 'demandForecastReadState', 'competitorPriceSampleMatches', 'verifyCompetitorPriceSaveReadback',
  ...['captureRevenueForecastRange', 'isRevenueForecastRangeCurrent'].filter(name => main.includes('            const ' + name + ' =')),
  ...(main.includes('            const applyDemandForecastReadback =') ? ['applyDemandForecastReadback'] : []),
  'manualCtripPricingInputMeta', 'firstEnabledRoomTypeId', 'emptyCompetitorAnalysis', 'createCompetitorPriceForm',
  'captureAgentRevenueRequestContext', 'isAgentRevenueRequestCurrent', 'setRevenueLoadState',
  'syncRevenuePricingInputDate', 'resetCompetitorPriceForm', 'saveCompetitorPriceInput', 'loadCompetitorAnalysis',
  'createDemandForecastForm', 'resetDemandForecastForm', 'demandForecastInputNumber', 'demandForecastSavedReceiptMatches', 'saveDemandForecastInput', 'loadDemandForecasts',
  'priceSuggestionRangeError', 'handlePriceSuggestionDateChange',
  ...(main.includes('const competitorManualSamples =') ? ['competitorManualSamples'] : []),
];
const forecastId = 'agent-suggestion-demand-forecast-manual-input';
const competitorId = 'agent-suggestion-ctrip-competitor-price-manual-input';
const ast = parse(template), selected = new Set();
const walkAst = (node, parents = []) => {
  if (node.type === 1) {
    const id = node.props.find(p => p.name === 'data-testid')?.value?.content;
    if ([forecastId, competitorId].includes(id)) {
      selected.add(node);
      assert.deepEqual(parents.flatMap(n => n.props.filter(p => p.type === 7 && ['if','show','else-if'].includes(p.name)).map(p => p.exp.content)), ["currentPage === 'agent-center'", "agentTab === 'revenue'", "revenueAgentTab === 'suggestions'"]);
    }
    if (node.tag === 'button' && node.props.some(p => p.name === 'on' && p.exp?.content === "revenueAgentTab = 'suggestions'; loadPriceSuggestionWorkbench()")) selected.add(node);
    if (node.tag === 'input' && node.props.some(p => p.name === 'model' && p.exp?.content === 'priceSuggestionFilter.date')) selected.add(node);
  }
  for (const child of node.children || []) walkAst(child, node.type === 1 ? [...parents, node] : parents);
};
walkAst(ast); assert.equal(selected.size, 4);
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = (node.children || []).map(retain).filter(Boolean).join('');
  if (!children || node.type === 0) return children;
  assert.equal(node.type, 1);
  return node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + children + '</' + node.tag + '>';
};
const markup = retain(ast);
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
    forecastFilter: { start_date: '2026-09-15', end_date: '2026-09-16' }, priceSuggestionFilter: { date: '2026-09-15', end_date: '2026-09-16' }, priceSuggestionPagination: { page: 4 },
    competitorPriceForm: { analysis_date: '2026-09-15' }, competitorFilter: { date: '2026-09-15' }, competitorPriceSaving: false, competitorAnalysis: {}, competitorAnalysisLoading: false, competitorAnalysisError: '', competitorMicroscopeSelectedKey: '',
    roomTypeConfigList: [{ id: 501, hotel_id: 80, name: 'Current hotel room', is_enabled: 1 }, { id: 502, hotel_id: 80, name: 'Second current hotel room', is_enabled: 1 }],
    revenueLoadState: { competitor: { status: 'not_loaded', error: '' }, forecasts: { status: 'not_loaded', error: '' } },
    permittedHotels: [{ id: 80, tenant_id: 7, name: 'Synthetic hotel' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const requests = [], notices = [];
  let workbenchReads = 0;
  state.competitorPriceSaveReadback = Vue.ref(null);
  const sandbox = {
    ...state, computed: Vue.computed, window: {}, URL, URLSearchParams, Headers, AbortController, DOMException, Date, Intl,
    setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    demandForecastsRequestSequence: 0,
    authSessionEpoch: 1, pageRequestGeneration: 0, agentRevenueStateEpoch: 1, competitorAnalysisRequestSeq: 0,
    user: Vue.ref({ id: 11, tenant_id: 7 }), token: Vue.ref(''),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    captureAuthSession: () => ({ epoch: 1, token: '' }), isAuthSessionCurrent: value => value.epoch === 1,
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    readRequestCache: () => null, writeRequestCache() {},
    formatDate: () => { throw new Error('Explicit date required in this fixture'); },
    showToast: (message, type = 'success') => notices.push({ message, type }),
    loadPriceSuggestionWorkbench: async () => { workbenchReads++; },
    loadPriceSuggestions: async () => {}, loadRevenueAnalysis: async () => {}, loadRevenueDashboard: async () => {}, loadRevenueAiOverview: async () => {},
    fetch: (url, options = {}) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid');
      assert.ok(['/api/agent/competitor-analysis', '/api/online-data/competitor-summary', '/api/agent/demand-forecasts'].includes(parsed.pathname)); if (parsed.pathname === '/api/online-data/competitor-summary') assert.equal(options.method || 'GET', 'GET');
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
  state.demandForecastForm.value = sandbox.methods.createDemandForecastForm();
  const context = { ...state, ...sandbox.methods, loadPriceSuggestionWorkbench: sandbox.loadPriceSuggestionWorkbench };
  let tree;
  const html = async () => renderToString(Vue.createSSRApp({ setup: () => context, render() { tree = render.call(this, this, []); return tree; } }));
  const entries = (node = tree, result = [], parents = []) => {
    if (Array.isArray(node)) node.forEach(child => entries(child, result, parents));
    else if (node && typeof node === 'object') { result.push({ node, parents }); entries(node.children, result, [...parents,node]); }
    return result;
  };
  const nodes = () => entries().map(e => e.node);
  const disabled = node => !!node.props?.disabled || entries().find(e => e.node === node).parents.some(p => p.props?.disabled || p.props?.inert);
  const cardNodes = id => flatten(nodes().find(n => n.props?.['data-testid'] === id));
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
    assert.ok(node && !disabled(node), 'Real enabled button through all original ancestors: ' + label);
    const pending = node.props.onClick(); await tick(); return { pending };
  };
  const change = async (node, value) => {
    assert.ok(node && !disabled(node), 'Only effectively enabled original control');
    const listeners = {}, element = { type: node.props.type || 'text', value: String(value), composing: false, addEventListener: (event, handler) => { listeners[event] = handler; } };
    if (node.type === 'select') {
      const options = flatten(node).filter(n => n.type === 'option');
      assert.ok(options.some(n => Number(n.props.value) === value && !n.props.disabled));
      element.multiple = false;
      element.options = options.map(n => ({ selected: Number(n.props.value) === value, value: String(n.props.value), _value: n.props.value }));
      Vue.vModelSelect.created(element, { modifiers: { number: true } }, node);
      listeners.change({target:element});
    } else {
      Vue.vModelText.created(element, { modifiers: node.props.type === 'number' ? {number:true} : node.props.type === 'date' ? {} : {trim:true} }, node);
      listeners.input({target:element});
    }
    await tick();
  };
  const input = async (id, name, value) => {
    await html();
    const node = cardNodes(id).find(n => name === 'ROOM' ? n.type === 'select' : n.type === 'input' && (name === 'DATE' ? n.props.type === 'date' : n.props.placeholder === name));
    await change(node,value);
  };
  const globalDate = async value => {
    await html();
    const node = nodes().find(n => n.type === 'input' && n.props.type === 'date' && n.props.onChange);
    assert.ok(node); await change(node,value); await node.props.onChange(); await tick(); await html();
  };
  const settleReads = (data, options = {}) => {
    const gets = requests.filter(req => !req.settled && (req.options.method || 'GET') === 'GET');
    const forecasts = gets.filter(req => new URL(req.url).pathname === '/api/agent/demand-forecasts');
    assert.ok(forecasts.length <= 1, 'cross-range save adds at most one forecast refresh');
    assert.equal(gets.length, 2 + forecasts.length);
    for (const req of gets) {
      const url = new URL(req.url); assert.equal(url.searchParams.get('hotel_id'), '80');
      if (url.pathname === '/api/agent/competitor-analysis') {
        assert.equal(url.searchParams.get('date'), state.competitorFilter.value.date);
        const status = options.ctripStatus || 200;
        reply(req, { code: status, message: status === 200 ? '' : 'Synthetic Ctrip failure', data }, status);
      } else if (url.pathname === '/api/agent/demand-forecasts') {
        assert.equal(url.searchParams.get('start_date'), state.forecastFilter.value.start_date);
        assert.equal(url.searchParams.get('end_date'), state.forecastFilter.value.end_date);
        reply(req, { code: 200, data: { forecasts: [], accuracy: {}, high_demand_dates: [] } });
      } else {
        assert.equal(url.searchParams.get('target_date'), state.competitorFilter.value.date);
        const status = options.meituanStatus || 200;
        reply(req, { code: status, message: status === 200 ? '' : 'Synthetic Meituan failure', data: options.meituanData || { data_status: 'missing', system_hotel_id: 80, target_date: state.competitorFilter.value.date, display_hotels: [] } }, status);
      }
    }
  };
  await html(); const originalTab = nodes().find(node => node.type === 'button');
  assert.ok(originalTab); originalTab.props.onClick(); await tick(); await html();
  assert.equal(state.revenueAgentTab.value, 'suggestions');
  return { state, requests, notices, html, nodes, visibleText, rows, fields, latest, reply, click, input, settleReads, globalDate, workbenchReads: () => workbenchReads, cardNodes, disabled };
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
const clone = value => JSON.parse(JSON.stringify(value));
const forecastDraft = { forecast_date: '2026-09-21', room_type_id: 502, predicted_occupancy: 68, predicted_demand: 11, confidence_percent: 90, remark: 'Synthetic forecast September 21' };
const sampleDraft = { analysis_date: '2026-09-20', room_type_id: 501, competitor_hotel_id: 8202, competitor_name: 'Synthetic sample September 20', our_price: 351, competitor_price: 347 };
async function fillBoth(h) {
  for (const [key,name] of Object.entries({forecast_date:'DATE',room_type_id:'ROOM',predicted_occupancy:'预测入住率%',predicted_demand:'需求间夜',confidence_percent:'人工置信度%（必填）',remark:'预测口径备注（可选）'})) await h.input(forecastId,name,forecastDraft[key]);
  for (const [key,name] of Object.entries({analysis_date:'DATE',room_type_id:'ROOM',competitor_hotel_id:'竞对ID（未知请留空）',competitor_name:'竞对名称（不知道ID时必填）',our_price:'本店价',competitor_price:'竞品价'})) await h.input(competitorId,name,sampleDraft[key]);
  assert.deepEqual(clone(h.state.demandForecastForm.value),forecastDraft);
  assert.deepEqual(clone(h.state.competitorPriceForm.value),sampleDraft);
  assert.equal(h.nodes().some(n => n.type === 'form'),false);
}
function assertPayload(payload, kind) {
  assert.equal(payload.hotel_id,80);
  if (kind === 'sample') {
    for (const key of ['analysis_date','room_type_id','competitor_hotel_id','our_price','competitor_price']) assert.equal(payload[key],sampleDraft[key],key);
    assert.equal(payload.competitor_data.competitor_name,sampleDraft.competitor_name);
    assert.equal(payload.competitor_data.input_type,'manual_ctrip_competitor_price_sample');
    assert.equal(payload.competitor_data.auto_write_ota,false);
    assert.equal(payload.ota_platform,1);
  } else {
    for (const key of ['forecast_date','room_type_id','predicted_occupancy','predicted_demand','remark']) assert.equal(payload[key],forecastDraft[key],key);
    assert.equal(payload.confidence_score,0.9);
    assert.equal(payload.historical_data.input_type,'manual_demand_forecast');
    assert.equal(payload.historical_data.auto_write_ota,false);
  }
}
async function save(h,kind,expectedOther) {
  const other = () => clone(kind === 'sample' ? h.state.demandForecastForm.value : h.state.competitorPriceForm.value);
  const {pending} = await h.click(kind === 'sample' ? '保存样本' : '保存预测');
  const post=h.latest('POST'), payload=JSON.parse(post.options.body); assertPayload(payload,kind);
  assert.deepEqual(other(),expectedOther,'Other draft survives POST start');
  assert.equal(new URL(post.url).pathname,kind==='sample'?'/api/agent/competitor-analysis':'/api/agent/demand-forecasts');
  const ownBusy=kind==='sample'?h.state.competitorPriceSaving:h.state.demandForecastSaving;
  await h.html(); assert.equal(ownBusy.value,true);
  const ownId=kind==='sample'?competitorId:forecastId;
  assert.ok(h.cardNodes(ownId).filter(n=>['input','select','button'].includes(n.type)).every(h.disabled),'Current candidate card is effectively locked');
  const saved=kind==='sample'?manual({id:81001,...payload,competitor_name:payload.competitor_data.competitor_name}):{id:81002,...payload,predicted_revpar:null};
  h.reply(post,{code:200,message:'Synthetic successful write',data:kind==='sample'?{id:81001}:{id:81002,write_action:'created',readback_verified:true,forecast:saved}});
  await tick(); await h.html();
  assert.deepEqual(other(),expectedOther,'Other entire unsaved draft must survive successful save of this card');
  assert.equal(ownBusy.value,true,'Lock stays active while success GET is pending');
  if(kind==='sample') {
    const read=response([saved]);read.date=sampleDraft.analysis_date;read.query_scope.date=sampleDraft.analysis_date;
    assert.equal(h.state.competitorFilter.value.date,sampleDraft.analysis_date);
    h.reply(h.latest('GET'), {code:200,data:read}); await tick();
    h.settleReads(read);
  } else {
    const get=h.latest('GET'),url=new URL(get.url);
    assert.equal(url.pathname,'/api/agent/demand-forecasts');
    assert.equal(url.searchParams.get('hotel_id'),'80');
    assert.equal(url.searchParams.get('start_date'),forecastDraft.forecast_date);
    assert.ok(url.searchParams.get('end_date')>=forecastDraft.forecast_date);
    h.reply(get,{code:200,data:{forecasts:[saved],accuracy:{},high_demand_dates:[]}});
  }
  await pending; await tick(); await h.html();
  assert.deepEqual(other(),expectedOther,'Other entire draft must also survive GET completion');
  assert.equal(ownBusy.value,false);
  if(kind==='sample') {
    assert.equal(h.rows().length,1);assert.equal(h.fields(h.rows()[0]).date,sampleDraft.analysis_date);
    assert.equal(h.fields(h.rows()[0]).name,sampleDraft.competitor_name);
    assert.equal(h.state.competitorPriceForm.value.our_price,null,'Own success reset is retained');
  } else {
    const rows=h.nodes().filter(n=>n.type==='tr'&&n.props?.['data-forecast-id']!==undefined);
    assert.equal(rows.length,1);assert.equal(h.fields(rows[0]).date,forecastDraft.forecast_date);
    assert.equal(h.fields(rows[0]).remark,forecastDraft.remark);
    assert.equal(h.state.demandForecastForm.value.predicted_demand,null,'Own success reset is retained');
  }
}
for(const first of ['sample','forecast']) test(`${first} save preserves the other entire draft and its subsequent original-date POST`,async()=>{
  const h=await harness();await fillBoth(h);
  await save(h,first,first==='sample'?forecastDraft:sampleDraft);
  const firstReset=clone(first==='sample'?h.state.competitorPriceForm.value:h.state.demandForecastForm.value);
  await save(h,first==='sample'?'forecast':'sample',firstReset);
  assert.equal(h.state.priceSuggestionFilter.value.date,'2026-09-15','A save never implies an explicit global date change');
  assert.equal(h.requests.filter(r=>r.options.method==='POST').length,2,'Only the two native save clicks write');
  assert.equal(h.requests.filter(r=>(r.options.method||'GET')==='GET').length,5);
  assert.equal(h.requests.filter(r=>(r.options.method||'GET')==='GET'
    && new URL(r.url).pathname==='/api/agent/demand-forecasts').length,2,
    'Each cross-range save refreshes the forecast for its own resulting range');
  assert.ok(h.requests.every(r=>r.settled));
});

test('explicit global date input retains both draft-date and read-filter synchronization',async()=>{
  const h=await harness();await fillBoth(h);const reads=h.workbenchReads();
  await h.globalDate('2026-09-22');
  assert.deepEqual(clone(h.state.demandForecastForm.value),{...forecastDraft,forecast_date:'2026-09-22'});
  assert.deepEqual(clone(h.state.competitorPriceForm.value),{...sampleDraft,analysis_date:'2026-09-22'});
  assert.equal(h.state.competitorFilter.value.date,'2026-09-22');
  assert.deepEqual(clone(h.state.forecastFilter.value),{start_date:'2026-09-22',end_date:'2026-09-22'});
  assert.equal(h.state.priceSuggestionFilter.value.end_date,'2026-09-22');
  assert.equal(h.state.priceSuggestionPagination.value.page,1);
  assert.equal(h.workbenchReads(),reads+1,'Original global handler invokes its workbench loader');
  assert.equal(h.requests.length,0,'Global compatibility check writes no business data');
});
