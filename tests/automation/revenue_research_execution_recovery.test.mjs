import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Synthetic research inputs issued by the isolated real ArtifactService probe.
// Runtime dependencies are only current source/template files; ignored evidence is not required.
const artifactEvidence = {research:{"A":{"status":"done","product_key":"demand-forecast","model_key":"deepseek_chat","generation_mode":"synthetic_fixture","hotel_scope":{"mode":"single_hotel","hotel_id":7,"hotel_ids":[7]},"readiness":{"stage":"research_ready_for_execution","execution_ready":true,"score":80,"status_label":"合成研究","next_action":"仅人工核查","missing_evidence":[]},"gaps":[],"local_sources":[],"web_sources":[],"result":{"summary":"合成研究 A 酒店 7","data_gaps":[],"risk_signals":[],"recommended_actions":["仅人工核查合成情景"],"decision_recommendations":[{"title":"合成研究人工核查 A","action":"仅验证本地界面恢复，不执行真实业务","can_create_execution_intent":true,"decision_quality":{"contract_version":"ai_recommendation_quality.v2","execution_ready":true}}]},"business_forecast":{"available":true,"decision_ready":true,"forecast_7d":{"revenue":700,"room_nights":7,"adr":100},"forecast_30d":{"revenue":3000,"room_nights":30,"adr":100},"sample_days":30,"date_range":{"start":"2026-08-16","end":"2026-09-14"},"method":"synthetic_only","confidence":"uncalibrated","truth_context":[]},"execution_artifact":{"id":"00000000000000000000000000000001","status":"available","expires_at":"2026-09-15T12:30:00+08:00","expires_in":1800}},"B":{"status":"done","product_key":"demand-forecast","model_key":"deepseek_chat","generation_mode":"synthetic_fixture","hotel_scope":{"mode":"single_hotel","hotel_id":7,"hotel_ids":[7]},"readiness":{"stage":"research_ready_for_execution","execution_ready":true,"score":80,"status_label":"合成研究","next_action":"仅人工核查","missing_evidence":[]},"gaps":[],"local_sources":[],"web_sources":[],"result":{"summary":"合成研究 B 酒店 7","data_gaps":[],"risk_signals":[],"recommended_actions":["仅人工核查合成情景"],"decision_recommendations":[{"title":"合成研究人工核查 B","action":"仅验证本地界面恢复，不执行真实业务","can_create_execution_intent":true,"decision_quality":{"contract_version":"ai_recommendation_quality.v2","execution_ready":true}}]},"business_forecast":{"available":true,"decision_ready":true,"forecast_7d":{"revenue":700,"room_nights":7,"adr":100},"forecast_30d":{"revenue":3000,"room_nights":30,"adr":100},"sample_days":30,"date_range":{"start":"2026-08-16","end":"2026-09-14"},"method":"synthetic_only","confidence":"uncalibrated","truth_context":[]},"execution_artifact":{"id":"00000000000000000000000000000002","status":"available","expires_at":"2026-09-15T12:30:00+08:00","expires_in":1800}},"C":{"status":"done","product_key":"demand-forecast","model_key":"deepseek_chat","generation_mode":"synthetic_fixture","hotel_scope":{"mode":"single_hotel","hotel_id":8,"hotel_ids":[8]},"readiness":{"stage":"research_ready_for_execution","execution_ready":true,"score":80,"status_label":"合成研究","next_action":"仅人工核查","missing_evidence":[]},"gaps":[],"local_sources":[],"web_sources":[],"result":{"summary":"合成研究 C 酒店 8","data_gaps":[],"risk_signals":[],"recommended_actions":["仅人工核查合成情景"],"decision_recommendations":[{"title":"合成研究人工核查 C","action":"仅验证本地界面恢复，不执行真实业务","can_create_execution_intent":true,"decision_quality":{"contract_version":"ai_recommendation_quality.v2","execution_ready":true}}]},"business_forecast":{"available":true,"decision_ready":true,"forecast_7d":{"revenue":700,"room_nights":7,"adr":100},"forecast_30d":{"revenue":3000,"room_nights":30,"adr":100},"sample_days":30,"date_range":{"start":"2026-08-16","end":"2026-09-14"},"method":"synthetic_only","confidence":"uncalibrated","truth_context":[]},"execution_artifact":{"id":"00000000000000000000000000000003","status":"available","expires_at":"2026-09-15T12:30:00+08:00","expires_in":1800}},"D":{"status":"done","product_key":"demand-forecast","model_key":"deepseek_chat","generation_mode":"synthetic_fixture","hotel_scope":{"mode":"single_hotel","hotel_id":8,"hotel_ids":[8]},"readiness":{"stage":"research_ready_for_execution","execution_ready":true,"score":80,"status_label":"合成研究","next_action":"仅人工核查","missing_evidence":[]},"gaps":[],"local_sources":[],"web_sources":[],"result":{"summary":"合成研究 D 酒店 8","data_gaps":[],"risk_signals":[],"recommended_actions":["仅人工核查合成情景"],"decision_recommendations":[{"title":"合成研究人工核查 D","action":"仅验证本地界面恢复，不执行真实业务","can_create_execution_intent":true,"decision_quality":{"contract_version":"ai_recommendation_quality.v2","execution_ready":true}}]},"business_forecast":{"available":true,"decision_ready":true,"forecast_7d":{"revenue":700,"room_nights":7,"adr":100},"forecast_30d":{"revenue":3000,"room_nights":30,"adr":100},"sample_days":30,"date_range":{"start":"2026-08-16","end":"2026-09-14"},"method":"synthetic_only","confidence":"uncalibrated","truth_context":[]},"execution_artifact":{"id":"00000000000000000000000000000004","status":"available","expires_at":"2026-09-15T12:30:00+08:00","expires_in":1800}}}};
let source = fs.readFileSync('public/app-main.js', 'utf8');
if (process.env.SUXI_RESEARCH_EXECUTION_SOURCE) {
  const start=source.indexOf('            const openRevenueResearchExecutionIntent =');
  const end=source.indexOf('            const openRevenueResearchModule =',start);
  assert.ok(start>0&&end>start);
  source=source.slice(0,start)+fs.readFileSync(process.env.SUXI_RESEARCH_EXECUTION_SOURCE,'utf8')+source.slice(end);
}
const staticSource = fs.readFileSync('public/revenue-research-static.js', 'utf8');
const systemSource = fs.readFileSync('public/system-static.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/19-page-revenue-research-center.html', 'utf8');
const slice = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert.ok(a >= 0 && b > a, start); return source.slice(a, b);
};
const domain = slice('const revenueResearchStaticScript =', '// Agent配置');
const actionsLoader = slice('let operationActionsRequestSeq =', 'const parseOperationEvidenceNumber =');
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
  const calls = [], gets = [], notices = [], timers = new Map(), intents = new Map(), artifacts = new Map(); let timerId = 0, tree, flowFailure = false;
  const permittedHotels = Vue.ref([{ id: 7, name: '合成门店七', tenant_id: 5 }, { id: 8, name: '合成门店八', tenant_id: 5 }]);
  const currentPage = Vue.ref('revenue-research-center'), filterReportHotel = Vue.ref('7');
  const operationFilters = Vue.ref({ hotel_id: '7' }), revenueAiExecutionFocus = Vue.ref(null), operationExecutionFlow = Vue.ref({}), operationError = Vue.ref({actions:''});
  const sandbox = {
    ...Vue, window: { Vue }, permittedHotels, hotels: permittedHotels, currentPage, filterReportHotel,
    operationFilters, revenueAiExecutionFocus, operationExecutionFlow, operationError,
    operationExecutionViewMode: Vue.ref('all'), operationLoading: Vue.ref({actions:false}), operationActions: Vue.ref([]), operationApprovalConfirmingIntentId: Vue.ref(0), operationActionTrackingRead: Vue.ref({}),
    operatingGoalInterventionLoading: Vue.ref(false), operatingGoalInterventionError: Vue.ref(''), operatingGoalInterventionOverview: Vue.ref({}),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), homeOperatingScheduleError: Vue.ref(''),
    operationYesterday: '2026-09-14', shanghaiBusinessYesterday: '2026-09-14', operationErrorMessage: (error, fallback) => error?.message || fallback,
    ensureOperationStaticReady: async () => true,
    normalizeOperationHotelSelection: filters => ['7','8'].includes(String(filters.value.hotel_id)) ? String(filters.value.hotel_id) : null,
    loadOperatingMemories: async () => true, applyHomeOperatingScheduleFlow() {},
    URL, URLSearchParams, Headers, AbortController, structuredClone,
    token: Vue.ref('synthetic-session'), isLoggedIn: Vue.ref(true), operationExecutionStageFilter: Vue.ref(''), suppressNextOpsTrackAutoLoad: false, authSessionEpoch: 0, pageRequestGeneration: 1,
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
      assert.ok(url.startsWith(sandbox.API_BASE)); const route = url.slice(sandbox.API_BASE.length);
      if (options.method === 'POST') {
        assert.ok(['/revenue-research/run','/revenue-research/execution-intent'].includes(route), 'only synthetic selected endpoints');
        const pending = deferred(); calls.push({ url, route, options, body: JSON.parse(options.body), ...pending }); return pending.promise;
      }
      gets.push({ url, options }); const parsed = new URL(url), hotelId = Number(parsed.searchParams.get('hotel_id'));
      assert.ok([7,8].includes(hotelId));
      if (parsed.pathname.endsWith('/operation/execution-flow')) {
        if (flowFailure) return Promise.resolve(response({code:500,message:'合成执行列表读取失败'},500));
        const list = [...intents.values()].filter(row => row.hotel_id === hotelId).map(row => ({
          id: row.id, hotel_id: row.hotel_id, recommendation: { source_module: row.source_module, source_record_id: row.source_record_id, target_value: row.target_value },
          approval: {status:row.status}, execution:{task_id:0,status:'pending_create'},
        }));
        return Promise.resolve(response({code:200,data:{list,capabilities:{hotel_id:hotelId},summary:{},stages:[],data_gaps:[],data_status:'ok',returned_count:list.length,matched_total:list.length,truncated:false,statistics:{execution_total_loaded:true}}}));
      }
      if (parsed.pathname.endsWith('/operation/action-tracking')) return Promise.resolve(response({code:200,data:{actions:[],returned_count:0,matched_total:0,truncated:false,data_gaps:[],data_status:'ok',effect_validation:{status:'data_gap',metrics:[],data_gaps:[]}}}));
      if (parsed.pathname.endsWith('/operation/closure-overview')) return Promise.resolve(response({code:200,data:{summary:{},modules:[],data_gaps:[],data_status:'ok'}}));
      if (parsed.pathname.endsWith('/operation/goal-intervention-overview')) return Promise.resolve(response({code:200,data:{hotel_id:hotelId,data_status:'ok'}}));
      throw new Error('Unexpected synthetic GET: ' + route);

    },
  };
  vm.runInNewContext(systemSource + '\n' + staticSource, sandbox);
  vm.runInNewContext(`const appSystemStatic = window.SUXI_SYSTEM_STATIC;
    const requireAppSystemStatic = key => appSystemStatic[key];
    const readRequestCooldown = appSystemStatic.createReadRequestCooldown();
    ${auth}\n${business}\n${policy}\n${requests}\n${actionsLoader}\n${domain}
    const unifiedHotelContextBindings = [{ key: 'operations', pages: ['ops-track'], read: () => operationFilters.value.hotel_id, write: hotelId => { operationFilters.value.hotel_id = hotelId; } }, { key: 'revenue-research', pages: ['revenue-research-center'], read: () => revenueResearchHotelId.value, write: hotelId => { revenueResearchHotelId.value = hotelId; } }];
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

  async function bridge(kind = 'create', key = 'demand-forecast') {
    await render(); const id = (kind === 'open' ? 'button-revenue-research-open-execution-' : 'button-revenue-research-execution-') + key;
    const node = flatten(tree).find(item => item.props?.['data-testid'] === id); assert.ok(node, 'actual execution bridge button');
    if (node.props.disabled) return { disabled:true, done:Promise.resolve() };
    return { disabled:false, done:node.props.onClick() };
  }
  const finishResearch = (index, label) => {
    const research = clone(artifactEvidence.research[label]); const call = calls[index];
    assert.equal(call.route, '/revenue-research/run'); assert.equal(Number(call.body.hotel_id), research.hotel_scope.hotel_id);
    assert.equal(call.body.product_key, research.product_key);
    artifacts.set(research.execution_artifact.id, research);
    call.resolve(response({code:200,data:research})); return research;
  };
  const commitIntent = (index, respond = true) => {
    const call = calls[index]; assert.equal(call.route, '/revenue-research/execution-intent');
    const saved = [...intents.values()].find(row => row.evidence.revenue_research_artifact.artifact_id === call.body.research_artifact_id);
    if (saved) { call.resolve(response({code:200,data:{execution_intent:{...saved,idempotent_replay:true}}})); return saved; }
    const research = artifacts.get(call.body.research_artifact_id);
    if (!research) { if (respond) call.resolve(response({code:410,message:'合成凭证已消费，请重新研究'},410)); return null; }
    assert.equal(Number(call.body.hotel_id), research.hotel_scope.hotel_id);
    artifacts.delete(call.body.research_artifact_id);
    const intent = { id:701+intents.size, hotel_id:research.hotel_scope.hotel_id, source_module:'revenue_research', source_record_id:9000+index,
      status:'pending_approval', action_type:research.product_key, object_type:'revenue_research', platform:'ctrip',
      date_start:'2026-09-15', date_end:'2026-09-15', current_value:{research_status:'done'},
      target_value:{research_product:research.product_key,action_text:research.result.decision_recommendations[0].action},
      created_by:42,evidence:{summary:research.result.summary,metric_scope:'ota_channel',revenue_research_artifact:{artifact_id:call.body.research_artifact_id,actor_id:42,hotel_id:research.hotel_scope.hotel_id,research_digest:'a'.repeat(64)}},tasks:[] };
    intents.set(intent.id,intent);
    if (respond) call.resolve(response({code:200,data:{execution_intent:intent,source_module:'revenue_research',metric_scope:'ota_channel',source_policy:'revenue_research_output_to_operation_execution_intent',next_action:'review_and_approve_execution_intent'}}));
    return intent;
  };
  return { bridge, finishResearch, commitIntent, intents, gets, operationFilters, revenueAiExecutionFocus, operationExecutionFlow, operationError, setFlowFailure: value => { flowFailure = value; }, ui, calls, notices, timers, render, button, click, selectHotel, receipt, finish, currentPage, filterReportHotel, token: sandbox.token, tree: () => tree };
}


const api = { fixture, flush, response };
async function ready() { const f = api.fixture(); const run = await f.click(); await api.flush(); f.finishResearch(0, 'A'); await run.done; return f; }
const current = f => f.ui.revenueResearchRunFor('demand-forecast');

test('real create saves one pending receipt and reads its exact execution ID', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush();
  assert.equal((await f.bridge()).disabled, true);
  const intent = f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent.id, intent.id);
  assert.equal(f.currentPage.value, 'ops-track');
  assert.equal(f.operationFilters.value.hotel_id, '7');
  assert.equal(f.operationExecutionFlow.value.list[0].id, intent.id);
  assert.equal(f.calls.length, 2);
  const get = f.gets.find(item => item.url.includes('/operation/execution-flow?'));
  assert.equal(new URL(get.url).searchParams.get('intent_id'), String(intent.id));
});

for (const hotelChange of [false, true]) test('old success stays out of a new ' + (hotelChange ? 'hotel' : 'same-product') + ' research card', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush();
  if (hotelChange) await f.selectHotel(8);
  const b = await f.click(); assert.equal(b.disabled, false); await api.flush(); f.finishResearch(2, hotelChange ? 'C' : 'B'); await b.done;
  const notices = f.notices.length; f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent, null);
  assert.equal(f.currentPage.value, 'revenue-research-center');
  assert.equal(f.notices.length, notices); assert.equal(f.gets.length, 0);
  assert.equal(current(f).result.result.summary, hotelChange ? '合成研究 C 酒店 8' : '合成研究 B 酒店 7');
  assert.equal((await f.render()).includes('执行意图 #701'), false);
});

test('old failure cannot clear the new request lock or permit a second current create', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); await f.selectHotel(8);
  const b = await f.click(); await api.flush(); f.finishResearch(2, 'C'); await b.done;
  const c = await f.bridge(); await api.flush(); const notices = f.notices.length;
  f.calls[1].reject(new Error('旧 A 合成失败')); await a.done;
  assert.equal(current(f).executionLoading, true); assert.equal(current(f).executionError, '');
  assert.equal((await f.bridge()).disabled, true); assert.equal(f.calls.length, 4); assert.equal(f.notices.length, notices);
  f.commitIntent(3); await c.done; assert.equal(current(f).executionLoading, false);
});

for (const returnToPage of [false, true]) test('confirmed same artifact survives page departure ' + (returnToPage ? 'and return' : 'without navigation'), async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); f.currentPage.value = 'compass'; await Vue.nextTick();
  if (returnToPage) { f.currentPage.value = 'revenue-research-center'; await Vue.nextTick(); }
  const notices = f.notices.length; const intent = f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent.id, intent.id); assert.equal(current(f).executionLoading, false);
  assert.equal(f.currentPage.value, returnToPage ? 'revenue-research-center' : 'compass');
  assert.equal(f.notices.length, notices); assert.equal(f.gets.length, 0);
  f.currentPage.value = 'revenue-research-center'; await Vue.nextTick(); const reopen = await f.bridge('open'); await reopen.done;
  assert.equal(f.operationExecutionFlow.value.list[0].id, intent.id); assert.equal(f.calls.length, 2);
});

test('auth replacement discards the old receipt and only finishes its own lock', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); f.token.value = 'synthetic-next-session';
  const notices = f.notices.length; f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent, null); assert.equal(current(f).executionLoading, false);
  assert.equal(f.notices.length, notices); assert.equal(f.currentPage.value, 'revenue-research-center');
});

test('A-B-A hotel selection does not restore ownership to the old request', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); await f.selectHotel(8); await f.selectHotel(7);
  const b = await f.click(); await api.flush(); f.finishResearch(2, 'B'); await b.done;
  f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent, null); assert.equal(f.currentPage.value, 'revenue-research-center');
});

test('a saved receipt remains available after exact-list read failure and retries GET only', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); f.setFlowFailure(true);
  const intent = f.commitIntent(1); await a.done;
  assert.equal(current(f).executionIntent.id, intent.id); assert.equal(f.operationExecutionFlow.value.data_status, 'load_failed');
  f.currentPage.value = 'revenue-research-center'; await Vue.nextTick(); f.setFlowFailure(false);
  const reopen = await f.bridge('open'); await reopen.done;
  assert.equal(f.operationExecutionFlow.value.list[0].id, intent.id); assert.equal(f.calls.length, 2);
});

test('a current ordinary create rejection retains research and allows only an explicit retry', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); const research = current(f).result;
  f.calls[1].resolve(api.response({ code: 422, message: '合成当前拒绝' }, 422)); await a.done;
  assert.equal(current(f).result, research); assert.equal(current(f).executionLoading, false);
  assert.match(current(f).executionError, /合成当前拒绝/); assert.equal(f.calls.length, 2);
  const retry = await f.bridge(); await api.flush(); assert.equal(f.calls.length, 3);
  f.commitIntent(2); await retry.done; assert.ok(current(f).executionIntent.id);
});

for (const field of ['hotel', 'source', 'product', 'boolean-id']) test('POST detail rejects mismatched ' + field + ' without inventing a tracked receipt', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); const intent = f.commitIntent(1, false);
  if (field === 'hotel') intent.hotel_id = 8;
  if (field === 'source') intent.source_module = 'manual';
  if (field === 'product') intent.action_type = 'price-elasticity';
  if (field === 'boolean-id') intent.id = true;
  f.calls[1].resolve(api.response({ code: 200, data: { execution_intent: intent } })); await a.done;
  assert.equal(current(f).executionIntent, null); assert.equal(current(f).executionLoading, false);
  assert.match(current(f).executionError, /同一研究范围/); assert.equal(f.currentPage.value, 'revenue-research-center');
});

test('lost creation response confirms the original artifact and never inserts a second modeled intent', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); const saved = f.commitIntent(1, false);
  f.calls[1].reject(new Error('合成回执丢失')); await a.done;
  assert.equal(current(f).executionIntent, null); assert.equal(f.intents.size, 1);
  const retry = await f.bridge(); await api.flush(); assert.equal(f.calls[2].options.body, f.calls[1].options.body);
  f.commitIntent(2); await retry.done; assert.equal(current(f).executionIntent.id, saved.id);
  assert.equal(f.intents.size, 1); assert.equal(f.operationExecutionFlow.value.list[0].id, saved.id);
});

test('a different artifact in an otherwise matching POST receipt cannot attach or navigate', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); const saved = f.commitIntent(1, false);
  saved.evidence.revenue_research_artifact.artifact_id = 'f'.repeat(32);
  f.calls[1].resolve(api.response({code:200,data:{execution_intent:saved}})); await a.done;
  assert.equal(current(f).executionIntent, null); assert.match(current(f).executionError, /同一研究范围/);
  assert.equal(f.currentPage.value, 'revenue-research-center');
});

for (const field of ['actor', 'digest']) test('POST provenance rejects mismatched ' + field + ' while keeping research available', async () => {
  const f = await ready(), a = await f.bridge(); await api.flush(); const saved = f.commitIntent(1, false);
  if (field === 'actor') saved.evidence.revenue_research_artifact.actor_id = 43;
  else saved.evidence.revenue_research_artifact.research_digest = '';
  f.calls[1].resolve(api.response({code:200,data:{execution_intent:saved}})); await a.done;
  assert.equal(current(f).executionIntent, null); assert.match(current(f).executionError, /同一研究范围/);
  assert.equal(f.currentPage.value, 'revenue-research-center');
});
