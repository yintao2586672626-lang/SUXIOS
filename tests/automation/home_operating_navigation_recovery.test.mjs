import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';

const files = {
  main: 'public/app-main.js', lab: 'public/components/system/operating-opportunity-lab.js',
  home: 'public/home-static.js', system: 'public/system-static.js',
  labTemplate: 'resources/frontend/templates/fragments/19b-page-operating-opportunities.html',
  opsTemplate: 'resources/frontend/templates/fragments/17-page-ops-track.html',
  shell: 'resources/frontend/templates/fragments/00-app-shell.html',
};
const raw = Object.fromEntries(Object.entries(files).map(([key, path]) => [key, readFileSync(path, 'utf8')]));
const main = raw.main.replaceAll('\r\n', '\n');
const section = (start, end) => { const a = main.indexOf(start), b = main.indexOf(end, a + start.length); assert.ok(a >= 0 && b > a, start); return main.slice(a, b); };
const requestSource = [
  section('            const captureAuthSession =', '            const createDefaultAuthContext ='),
  section('            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  section('            const createRequestAbortError =', '            const clearPageLifecycleTimers ='),
  section('            const currentPageReadPolicy =', '            const cancelPageLoadRequests ='),
  section('            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  section('            const request = async (', '            const apiRequest = request;'),
  'const apiRequest = request;',
].join('\n');
const methods = [
  section('            const isOperationHotelPermitted =', '            const selectPmsHotel ='),
  section('            const normalizeOperationHotelSelection =', '            const operationDisplayFormatters ='),
  section('            const loadOperationActions =', '            const parseOperationEvidenceNumber ='),
  section('            const openHomeOperatingScheduleItem =', '            const homeDailyWorkflowError ='),
  section('            const handleMenuClick =', '            const isStillOnRequestPage ='),
].join('\n');
// Original page-change cancellation prefix and exact ops-track activation branch;
// unrelated page data/visual timers are not run in this isolated path fixture.
const pageChange = section('                const previousPage = previousPageLifecycleKey;', '                if (!isCompassDataPage(newPage))');
const opsActivation = section("                if (newPage === 'ops-track') {", "                if (newPage === 'operating-growth-archive') {");
const compileRender = template => new Function('Vue', compile(template, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const labRender = compileRender(raw.labTemplate);
const scopeSelect = raw.opsTemplate.match(/<select v-model="operationFilters.hotel_id"[\s\S]*?<\/select>/)?.[0];
const menuAnchor = raw.shell.match(/<a v-else="" @click="handleMenuClick\(item\)"[\s\S]*?<\/a>/)?.[0];
assert.ok(scopeSelect && menuAnchor);
const controlsRender = compileRender(`<div>${menuAnchor.replace(' v-else=""', '')}<div v-if="currentPage === 'ops-track'">${scopeSelect}</div></div>`);
// Synthetic projection copied from the reviewed pure producer DTO, no backend writes.
const dto = { overview_saved_current_shape_minimal: {"code":200,"data":{"contract_version":"operating_opportunity_lab.v2","tenant_id":7,"system_hotel_id":80,"business_date":"2026-09-15","today":{"contract_version":"daily_one_thing.v2","feature_key":"daily_one_thing","business_date":"2026-09-15","status":"pending_approval","selected":{"candidate_key":"round47_synthetic_fact","source_type":"strict_fact_signal","problem":"Synthetic same-scope operating check","approval_status":"pending_approval","scope":{"tenant_id":7,"hotel_id":80,"business_date":"2026-09-15","platform":"ctrip","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","title":"Synthetic read-only check","description":"Synthetic fixture only","object":"ctrip_fact_scope","steps":[]},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"responsibility":{"owner_id":11,"due_at":"2026-09-15 18:00:00","review_at":"2026-09-16 10:00:00"},"source":{"record_id":101,"record_ref":"online_daily_data#101","snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"content_digest":"5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317","execution_intent_id":301,"execution_task_id":0},"selection_policy":{"full_candidate_list_exposed":false},"external_write_allowed":false,"execution_intent_id":301,"execution_task_id":0,"lifecycle":{"status":"pending_approval"},"task_count":0,"external_write_performed_by_system":false},"today_preview":{"contract_version":"daily_one_thing.v2","feature_key":"daily_one_thing","business_date":"2026-09-15","status":"draft","selected":{"candidate_key":"round47_synthetic_fact","source_type":"strict_fact_signal","problem":"Synthetic same-scope operating check","approval_status":"draft","scope":{"tenant_id":7,"hotel_id":80,"business_date":"2026-09-15","platform":"ctrip","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","title":"Synthetic read-only check","description":"Synthetic fixture only","object":"ctrip_fact_scope","steps":[]},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"responsibility":{"owner_id":11,"due_at":"2026-09-15 18:00:00","review_at":"2026-09-16 10:00:00"},"source":{"record_id":101,"record_ref":"online_daily_data#101","snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"content_digest":"5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"},"selection_policy":{"full_candidate_list_exposed":false},"external_write_allowed":false},"today_saved_run":{"id":901,"tenant_id":7,"system_hotel_id":80,"feature_key":"daily_one_thing","feature_label":"今日一件事","business_date":"2026-09-15","source_quality_status":"readback_verified","source_reference":"online_daily_data#101","input":{"business_date":"2026-09-15","source_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","selected_candidate_digest":"5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"},"result":{"contract_version":"daily_one_thing.v2","feature_key":"daily_one_thing","business_date":"2026-09-15","status":"draft","selected":{"candidate_key":"round47_synthetic_fact","source_type":"strict_fact_signal","problem":"Synthetic same-scope operating check","approval_status":"draft","scope":{"tenant_id":7,"hotel_id":80,"business_date":"2026-09-15","platform":"ctrip","metric_scope":"ota_channel"},"recommended_action":{"type":"human_reviewed_operating_check","title":"Synthetic read-only check","description":"Synthetic fixture only","object":"ctrip_fact_scope","steps":[]},"expected_observation_metric":{"key":"detail_exposure","unit":"exposure_count","baseline_value":10},"responsibility":{"owner_id":11,"due_at":"2026-09-15 18:00:00","review_at":"2026-09-16 10:00:00"},"source":{"record_id":101,"record_ref":"online_daily_data#101","snapshot_digest":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","fact_refs":["online_daily_data#101"],"gap_codes":[]},"content_digest":"5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"},"selection_policy":{"full_candidate_list_exposed":false},"external_write_allowed":false},"input_digest":"06ad5e55b977a90f0cb74e8570c000eaa61480aed1ebcd8ac7df488df498f857","result_digest":"bc0ce1a7ebd1c95c40462fafb6878576ad7264af3456e972b5c49be99c29e049","created_by":11,"created_at":"2026-09-15 10:00:00","record_readback_status":"readback_verified"},"today_execution_intent":{"id":301,"tenant_id":7,"hotel_id":80,"source_module":"daily_one_thing","source_record_id":901,"status":"pending_approval","tasks":[],"action_management":{"contract_version":"operation_action_card.v2","action_card":{"contract_version":"operation_action_card.v2","trace":{"daily_selection_digest":"5024d3822fb83d52d09ceab9a2c3afdb5d2c1baabb6bd4ad12401d405deb7317"}},"lifecycle":{"status":"pending_approval"}}},"today_execution_intent_id":301,"today_execution_task_id":0,"today_lifecycle_status":"pending_approval","today_state":"saved_current","source_digest":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}} };
const clone = value => JSON.parse(JSON.stringify(value));
class FixtureDate extends Date {
  constructor(...args) { super(...(args.length ? args : ['2026-09-15T10:00:00+08:00'])); }
  static now() { return new FixtureDate().getTime(); }
}
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
let assertions = 0;
const eq = (a, b, note) => { assertions++; assert.deepEqual(a, b, note); };
const ok = (value, note) => { assertions++; assert.ok(value, note); };
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {},
    addEventListener() {}, removeEventListener() {}, get options() { return this.children; } });
  const remove = child => { if (child.parent) { const i = child.parent.children.indexOf(child); if (i >= 0) child.parent.children.splice(i, 1); } child.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(child, parent, anchor = null) { remove(child); child.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(child); else parent.children.splice(i, 0, child); },
    remove, patchProp: (n, key, old, value) => { n.props[key] = value; },
  } };
}
function harness(start = 'compass', overviewDate = '2026-09-15') {
  const requests = [], notices = [], navs = [], menus = [], warnings = [];
  const sandbox = { window: {}, URL, URLSearchParams, Headers, FormData, AbortController, DOMException, structuredClone, Date, Intl,
    setTimeout, clearTimeout, console: { error() {}, warn() {} }, API_BASE: 'https://synthetic.invalid/api',
    authSessionEpoch: 1, pageRequestGeneration: 0, operationActionsRequestSeq: 0, actionTrackingStatus: 200,
    currentPage: Vue.ref(start), filterReportHotel: Vue.ref('80'), token: Vue.ref('synthetic-only'),
    authContext: Vue.ref({ tenantId: 7, hotelId: 80, permissionStatus: 'allowed', platform: 'all' }),
    permittedHotels: Vue.ref([{ id: 80, name: 'Synthetic A', tenant_id: 7 }, { id: 81, name: 'Synthetic B', tenant_id: 7 }]),
    user: Vue.ref({ id: 11, is_super_admin: true }), revenueAiBusinessDate: Vue.ref('2026-09-15'), coreOperationsTargetDate: Vue.ref('2026-09-15'),
    operationFilters: Vue.ref({ hotel_id: '80' }), operationExecutionStageFilter: Vue.ref(''), operationExecutionViewMode: Vue.ref('all'),
    operationLoading: Vue.ref({ actions: false }), operationError: Vue.ref({ actions: '' }),
    operatingGoalInterventionLoading: Vue.ref(false), operatingGoalInterventionError: Vue.ref(''),
    operationActionTrackingRead: Vue.ref({}), operationExecutionFlow: Vue.ref({ list: [], data_status: 'ready' }), operationActions: Vue.ref([]), operationApprovalConfirmingIntentId: Vue.ref(0),
    operationEffectValidation: Vue.ref({}), operationClosureOverview: Vue.ref({}), operatingGoalInterventionOverview: Vue.ref({}),
    homeOperatingScheduleError: Vue.ref(''), operationYesterday: '2026-09-14', shanghaiBusinessYesterday: '2026-09-14', nextTick: Vue.nextTick,
    document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    isTerminalAuthFailureResponse: () => false, readRequestCooldown: { check: () => null, record() {} },
    normalizeCanonicalPage: value => value, guardSuperAdminPageAccess: () => true,
    ensureOperationStaticReady: async () => {}, loadOperatingMemories: async () => {}, applyHomeOperatingScheduleFlow() {},
    operationErrorMessage: error => error?.message || 'Synthetic read error',
    showToast: (message, type = 'success') => notices.push({ message, type, page: sandbox.currentPage.value, hotel: sandbox.operationFilters.value.hotel_id }),
    autoFetchRunState: Vue.ref({ active: false, type: '' }), stopAutoFetchProgressMonitor() {}, stopAutoFetchRunTimer() {},
    clearPageLifecycleTimers() {}, clearPostFetchRefreshTimers() {}, cancelPageLoadRequests() {},
    fetch: (url, options) => {
      assert.equal(String(options.method || 'GET'), 'GET', 'No mutation permitted');
      const parsed = new URL(url), endpoint = parsed.pathname.replace('/api', '');
      assert.ok(['/operating-opportunities/overview', '/operation/action-tracking', '/operation/execution-flow', '/operation/closure-overview', '/operation/goal-intervention-overview'].includes(endpoint), endpoint);
      return new Promise((resolve, reject) => {
        const row = { url, endpoint, resolve, reject, settled: false, aborted: false, hotel: Number(parsed.searchParams.get('hotel_id') || parsed.searchParams.get('system_hotel_id') || 0), intent: Number(parsed.searchParams.get('intent_id') || 0) };
        requests.push(row); options.signal?.addEventListener('abort', () => { row.aborted = true; });
        if (endpoint !== '/operation/execution-flow') {
          const status = endpoint === '/operation/action-tracking' ? sandbox.actionTrackingStatus : 200;
          const body = status !== 200 ? { code: status, message: 'Synthetic action-tracking unavailable', data: null }
            : endpoint === '/operating-opportunities/overview' ? clone(dto.overview_saved_current_shape_minimal)
              : endpoint === '/operation/closure-overview' ? { code: 200, data: { summary: {}, modules: [], data_gaps: [], data_status: 'data_gap' } }
              : { code: 200, data: { hotel_id: row.hotel, actions: [], returned_count: 0, matched_total: 0, truncated: false, data_gaps: [], effect_validation: { metrics: [], data_gaps: [] }, data_status: 'ok' } };
          if (endpoint === '/operating-opportunities/overview') body.data.business_date = overviewDate;
          row.settled = true; resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
        }
      });
    },
  };
  sandbox.operationHotelOptions = sandbox.permittedHotels;
  sandbox.operationExecutionItems = Vue.computed(() => sandbox.operationExecutionFlow.value?.list || []);
  vm.createContext(sandbox); vm.runInContext(raw.system, sandbox); sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.requireAppSystemStatic = key => sandbox.appSystemStatic[key];
  vm.runInContext(`${requestSource}\nlet pendingOperationNavigation=null; let suppressNextOpsTrackAutoLoad=false; let previousPageLifecycleKey=currentPage.value;\n${methods}\nglobalThis.pathMethods={openHomeOperatingScheduleItem,openHomeOperatingScheduleAll,handleMenuClick,loadOperationActions}; globalThis.scopeChange=(newPage)=>{${pageChange}\n${opsActivation}};globalThis.actualRequest=request;`, sandbox);
  sandbox.activateOpsTrackPage = () => sandbox.pathMethods.loadOperationActions();
  const stopWatcher = Vue.watch(sandbox.currentPage, sandbox.scopeChange);
  const trackOpen = item => { const promise = sandbox.pathMethods.openHomeOperatingScheduleItem(item); navs.push({ item: clone(item), promise }); return promise; };
  const componentWindow = {};
  class LabDate extends Date {
    constructor(...args) { super(...(args.length ? args : ['2026-09-15T04:00:00Z'])); }
    static now() { return Date.parse('2026-09-15T04:00:00Z'); }
  }
  vm.runInNewContext(raw.lab, { window: componentWindow, Vue, Date: LabDate, Intl, URLSearchParams });
  const homeWindow = { Vue };
  vm.runInNewContext(raw.home, { window: homeWindow, URLSearchParams, Date, Intl });
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const menuItem = Vue.ref({ path: 'compass', name: 'Synthetic home', icon: '' });
  const model = Vue.ref({ date: '2026-09-15', stateCode: 'ready', stateLabel: 'Ready', items: [], dailyFocus: { intentId: 301, hotelId: 80, hotelName: 'Synthetic A', businessDateText: '2026-09-15', title: 'Original A', sourceRef: 'operating_opportunity_runs#901' } });
  const app = renderer.createApp({
    components: { OperatingOpportunityLab: componentWindow.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody },
    setup: () => ({ currentPage: sandbox.currentPage, filterReportHotel: sandbox.filterReportHotel, hotels: sandbox.permittedHotels,
      managerCapabilityRequest: sandbox.actualRequest, openHomeOperatingScheduleItem: trackOpen, showToast: sandbox.showToast,
      operationFilters: sandbox.operationFilters, operationHotelOptions: sandbox.operationHotelOptions, loadOperationActions: sandbox.pathMethods.loadOperationActions,
      item: menuItem, sidebarCollapsed: false, handleMenuClick: sandbox.pathMethods.handleMenuClick,
      menuTestId: item => `menu-${item.path}`, getMenuItemName: item => item.name, isSidebarMenuItemActive: item => item.path === sandbox.currentPage.value,
    }),
    render() { return Vue.h(Vue.Fragment, [controlsRender.call(this, this), labRender.call(this, this),
      sandbox.currentPage.value === 'compass' ? Vue.h(homeWindow.SUXI_HOME_STATIC.HomeOperatingOrchestration, { model: model.value, compact: true, onOpen: trackOpen }) : null]); },
  });
  app.config.warnHandler = message => warnings.push(message); app.mount(host.root);
  const walk = (n, found = []) => { if (Array.isArray(n)) { n.forEach(v => walk(v, found)); return found; } if (!n || typeof n !== 'object') return found; found.push(n); if (n.component) walk(n.component.subTree, found); walk(n.children, found); return found; };
  const nodes = () => walk(app._instance.subTree);
  const click = id => { const n = nodes().find(n => n.type === 'button' && n.props?.['data-testid'] === id); ok(n && !n.props.disabled, `Real enabled control ${id}`); n.props.onClick(); return navs.at(-1); };
  const menu = async path => { menuItem.value = { path, name: path, icon: '' }; await tick(); const n = nodes().find(n => n.type === 'a' && n.props?.['data-testid'] === `menu-${path}`); ok(n && !n.props.disabled, 'Actual sidebar remains reachable while task read pending'); menus.push(path); n.props.onClick(); await tick(); };
  const hotel = async value => { const n = nodes().find(n => n.type === 'select' && n.props?.['data-testid'] === 'operation-scope-hotel'); ok(n && !n.props.disabled, 'Actual task hotel selector enabled during pending read'); n.props['onUpdate:modelValue'](String(value)); n.props.onChange({ target: { value: String(value) } }); await tick(); };
  const reply = (row, ids = [row.intent || 401], status = 200) => { row.settled = true; row.resolve(new Response(JSON.stringify(status === 200
    ? { code: 200, data: { capabilities: { hotel_id: row.hotel }, list: ids.map(id => ({ id, hotel_id: row.hotel })), summary: {}, stages: [], data_gaps: [], data_status: 'ok', returned_count: ids.length, matched_total: ids.length, truncated: false, statistics: { execution_total_loaded: true } } }
    : { code: status, message: 'Synthetic denied read', data: null }), { status, headers: { 'Content-Type': 'application/json' } })); };
  const flow = () => requests.filter(row => row.endpoint === '/operation/execution-flow');
  return { sandbox, requests, notices, navs, menus, warnings, nodes, click, menu, hotel, reply, flow, model,
    async stop() { for (const row of requests) if (!row.settled) { row.settled = true; row.reject(new Error('Synthetic cleanup')); } app.unmount(); stopWatcher(); await tick(); } };
}
async function scenario(name, start, run) {
  await test(name, async t => {
    const p = harness(start), before = assertions;
    try { await tick(); await run(p); eq(p.warnings, [], 'No Vue warnings'); }
    finally { await p.stop(); }
    t.diagnostic(String(assertions - before) + ' explicit assertions');
  });
}
for (const origin of ['home', 'lab']) await scenario(`normal ${origin} button opens exact same-hotel original intent`, origin === 'home' ? 'compass' : 'operating-opportunities', async p => {
  const a = p.click(origin === 'home' ? 'home-daily-one-thing-focus' : 'daily-one-thing-open-original'); await tick();
  eq(p.sandbox.currentPage.value, 'ops-track'); eq(p.flow().length, 1); eq(p.flow()[0].hotel, 80); eq(p.flow()[0].intent, 301);
  p.reply(p.flow()[0]); await a.promise; eq(Array.from(p.sandbox.operationExecutionItems.value, row => row.id), [301]);
  eq(p.sandbox.operationLoading.value.actions, false); ok(p.notices.at(-1).message.includes('对应任务')); eq(p.notices.at(-1).type, 'success');
  return { exactReadPassed: true };
});
await scenario('partial task read failure cannot claim the original action opened', 'compass', async p => {
  p.sandbox.actionTrackingStatus = 500;
  const a = p.click('home-daily-one-thing-focus'); await tick();
  p.reply(p.flow()[0]); await a.promise;
  ok(p.notices.some(row => row.type === 'error'), JSON.stringify(p.notices));
  ok(!p.notices.some(row => row.type === 'success' && row.message.includes('对应任务')));
  return;
});
await scenario('leaving task page cancels old read but old navigation stays silent', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); const old = p.flow()[0];
  await p.menu('operating-opportunities'); await a.promise;
  eq(p.sandbox.currentPage.value, 'operating-opportunities'); ok(old.aborted); eq(p.notices, [], 'Obsolete navigation must not report a failure on another page'); p.reply(old); await tick();
  eq(Array.from(p.sandbox.operationExecutionItems.value), []); return;
});
await scenario('switching task hotel preserves new rows but old navigation stays silent', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); const old = p.flow()[0];
  await p.hotel(81); eq(p.flow().length, 2); p.reply(p.flow()[1], [401]); await tick();
  eq(Array.from(p.sandbox.operationExecutionItems.value, row => row.id), [401]);
  p.reply(old); await a.promise; eq(p.notices, [], 'Hotel A navigation must not notify Hotel B');
  eq(Array.from(p.sandbox.operationExecutionItems.value, row => row.hotel_id), [81]);
  return;
});
await scenario('another original item opened through sidebar return stays focused despite first cancelled navigation', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); const old = p.flow()[0];
  await p.menu('compass'); await a.promise; p.model.value = { ...p.model.value, dailyFocus: { ...p.model.value.dailyFocus, intentId: 302, title: 'Original B' } }; await tick();
  const b = p.click('home-daily-one-thing-focus'); await tick(); const current = p.flow().at(-1); eq(current.intent, 302);
  p.reply(current); await b.promise; p.reply(old); await tick();
  eq(Array.from(p.sandbox.operationExecutionItems.value, row => row.id), [302]); eq(p.sandbox.currentPage.value, 'ops-track');
  eq(p.notices.filter(row => row.type === 'error'), [], 'No obsolete failure during a new selection'); eq(p.notices.at(-1).type, 'success');
  return;
});
await scenario('current missing-intent response remains a real read failure', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); p.reply(p.flow()[0], [999]); await a.promise;
  eq(Array.from(p.sandbox.operationExecutionItems.value), []); ok(p.notices.every(row => row.type === 'error')); ok(p.sandbox.operationError.value.actions.includes('对应任务'));
  return { genuineFailurePreserved: true };
});

await scenario('old auth session cannot notify after exact read resolves', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); const old = p.flow()[0];
  p.sandbox.authSessionEpoch += 1;
  p.reply(old); await a.promise;
  eq(p.notices, []); eq(Array.from(p.sandbox.operationExecutionItems.value), []);
});
await scenario('current HTTP failure stays visible and releases loading', 'compass', async p => {
  const a = p.click('home-daily-one-thing-focus'); await tick(); p.reply(p.flow()[0], [], 500); await a.promise;
  ok(p.notices.length > 0); ok(p.notices.every(row => row.type === 'error'));
  eq(p.sandbox.operationLoading.value.actions, false); eq(Array.from(p.sandbox.operationExecutionItems.value), []);
});
await scenario('non-full render preserves pending handoff without starting an extra GET', 'compass', async p => {
  p.sandbox.document.documentElement.dataset.suxiRenderPhase = 'initial';
  const a = p.click('home-daily-one-thing-focus'); await a.promise; await tick();
  eq(p.flow().length, 0); eq(p.notices, []);
  eq(vm.runInContext('pendingOperationNavigation.intentId', p.sandbox), 301);
  eq(p.sandbox.currentPage.value, 'ops-track');
});
// An injected nextTick gate is an internal boundary control, not a claim that
// two human clicks can precede the normal Vue microtask flush.
await scenario('navigation invalidated while waiting for nextTick does not start an old GET', 'compass', async p => {
  let release; p.sandbox.nextTick = () => new Promise(resolve => { release = resolve; });
  const a = p.click('home-daily-one-thing-focus'); await tick();
  await p.menu('operating-opportunities'); release(); await tick();
  eq(p.flow().length, 0, 'Invalid navigation must not start its read'); await a.promise;
  eq(p.notices, []); eq(p.sandbox.currentPage.value, 'operating-opportunities');
});

await test('lab refuses an overview for a different business date and exposes no original-task button', async () => {
  const p = harness('operating-opportunities', '2026-09-14');
  try {
    await tick();
    const overview = p.requests.find(row => row.endpoint === '/operating-opportunities/overview'); ok(overview);
    eq(new URL(overview.url).searchParams.get('business_date'), '2026-09-15');
    eq(p.nodes().filter(node => node.props?.['data-testid'] === 'daily-one-thing-open-original').length, 0);
    ok(p.nodes().some(node => typeof node.children === 'string' && node.children.includes('没有按当前酒店、日期和唯一选择合同精确回读')));
    eq(p.flow().length, 0); eq(p.warnings, []);
  } finally { await p.stop(); }
});
