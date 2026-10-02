import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// Original navigation/watchers/readers/request/coordinator; only fetch is synthetic.
// Start from an already displayed public gap-card projection, not a backend attestation.
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourceRoot = path.resolve(process.argv.find(arg => arg.startsWith('--source-root='))?.slice(14) || repository);
const readers = [];
const read = relative => {
  const resolved = path.join(sourceRoot, relative), bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: createHash('sha256').update(bytes).digest('hex').toUpperCase() });
  return bytes.toString('utf8').replaceAll('\r\n', '\n');
};
const main = read('public/app-main.js');
const fromTemplate = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const toTemplate = read('resources/frontend/templates/fragments/35-page-online-data.html');
const componentSource = read('public/components/system/app-main-components.js');
const statics = ['public/system-static.js', 'public/revenue-overview-contract-static.js', 'public/revenue-cockpit-static.js', 'public/revenue-ai-static.js'].map(relative => [relative, read(relative)]);
const cut = (source, from, to) => {
  const a = source.indexOf(from), b = source.indexOf(to, a + from.length); assert.ok(a >= 0 && b > a, from); return source.slice(a, b);
};
const decl = name => {
  const a = main.indexOf(`            const ${name} =`); assert.ok(a >= 0, name);
  const next = /\n            (?:const|let) /.exec(main.slice(a + 1)); assert.ok(next, name); return main.slice(a, a + 1 + next.index);
};
const requestSource = [
  cut(main, '            const BUSINESS_CONTEXT_ENDPOINT_PREFIXES =', '            const userHasPermission ='),
  cut(main, '            const PAGE_LOAD_DEDUP_MS =', '            const activateCoreOperationsAfterLogin ='),
  cut(main, '            const COORDINATED_GET_MAX_CONCURRENCY =', '            // API 请求'),
  cut(main, '            const request = async (', '            const apiRequest = request;'),
].join('\n');
const targetNames = ['permittedHotels', 'captureAuthSession', 'isAuthSessionCurrent', 'readRequestCooldown', 'terminalAuthFailureReason',
  'isTerminalAuthFailureResponse', 'userHasPermission', 'normalizeCanonicalPage', 'SUPER_ADMIN_ONLY_PAGES', 'guardSuperAdminPageAccess',
  'isCompassDataPage', 'showToast', 'applyRevenueAiEvidenceScope', 'openRevenueAiGap', 'isOnlineDataTabVisible', 'isVisibleOnlineDataTab',
  'openOnlineDataEntryTab', 'openOnlineDataTab', 'setOnlineDataTabFromPage', 'scheduleOnlineDataTabLoad',
  'shouldPrewarmManualOnlineFetchConfig', 'loadOnlineDataList', 'loadOnlineDataSummary', 'refreshOnlineData',
  'isAutoFetchRecordDeletable', 'autoFetchRecordDeletableRows', 'pruneSelectedOnlineDataIds'];
if (main.includes('            const onlineDataSummaryRequestKey =')) targetNames.push('onlineDataSummaryState', 'onlineDataSummaryRequestKey', 'onlineDataSummaryViewState');
if (main.includes('            const revenueCockpitEvidenceTarget =')) targetNames.push('revenueCockpitEvidenceTarget', 'openRevenueCockpitEvidence');
const fullRenderStart = main.indexOf('    requestSuxiFullRenderForPage = (page) => {');
const fullRenderEnd = main.indexOf('\n    };', fullRenderStart);
assert.ok(fullRenderStart >= 0 && fullRenderEnd > fullRenderStart);
const fullRender = main.slice(fullRenderStart, fullRenderEnd + '\n    };'.length);
const watchers = cut(main, '            watch(currentPage, (newPage) => {', '            watch(isLoggedIn, (loggedIn) => {')
  + '\n' + cut(main, '            watch(onlineDataTab, (newTab) => {', '            let meituanHotelConfigApplyVersion =');

// Keep original conditions/loops/ancestors and v-if/else chains; omit only unrelated siblings.
const ast = parse(fromTemplate + '\n' + toTemplate), selected = new Set();
const scan = node => {
  if (node.type === 1) {
    const event = node.props.find(prop => prop.name === 'on' && prop.arg?.content === 'click')?.exp?.content;
    const model = node.props.find(prop => prop.name === 'model')?.exp?.content;
    const condition = node.props.find(prop => ['if', 'else-if'].includes(prop.name))?.exp?.content;
    const id = node.props.find(prop => prop.type === 6 && prop.name === 'data-testid')?.value?.content;
    if (event === 'openRevenueCockpitEvidence(card)' || event === 'refreshOnlineData({ force: true })'
      || (condition === 'card.reasonText') || condition === 'onlineDataSummary?.ota_channel_supplement'
      || ['online-data-records-loading', 'online-data-records-error'].includes(id)
      || (node.tag === 'tr' && condition === 'onlineDataList.length === 0')
      || ['onlineDataFilter.start_date', 'onlineDataFilter.end_date', 'onlineDataFilter.source', 'onlineDataFilter.hotel_id'].includes(model)
      || (node.tag === 'td' && (node.loc.source.includes('item.data_date') || node.loc.source.includes('item.hotel_name')))) selected.add(node);
  }
  for (const child of node.children || []) scan(child);
};
scan(ast);
const wrapper = (node, body = '') => node.type === 0 ? body : node.loc.source.slice(0, node.loc.source.indexOf('>') + 1) + body + node.loc.source.slice(node.loc.source.lastIndexOf('</'));
const directive = (node, names) => node.type === 1 && node.props.some(prop => prop.type === 7 && names.includes(prop.name));
const retain = node => {
  if (selected.has(node)) return node.loc.source;
  const children = node.children || [], outputs = children.map(retain);
  for (let i = 0; i < children.length; i++) if (outputs[i] && directive(children[i], ['else', 'else-if'])) {
    for (let j = i - 1; j >= 0; j--) {
      if (children[j].type !== 1) continue;
      if (!directive(children[j], ['if', 'else-if'])) break;
      if (!outputs[j]) outputs[j] = wrapper(children[j]);
      if (directive(children[j], ['if'])) break;
    }
  }
  const body = outputs.join(''); return body ? wrapper(node, body) : '';
};
const render = new Function('Vue', compile('<section>' + retain(ast) + '</section>', { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const textOf = node => typeof node === 'string' ? node : Array.isArray(node) ? node.map(textOf).join('') : textOf(node?.children || '');
function model(platform) {
  return { hotelId: 80, tenantId: 7, businessDate: '2026-09-10', asOfDate: '2026-09-19', selectedPlatform: 'all_ota', status: 'partial',
    visibleSections: [{ key: 'gaps', title: '缺口', cards: [{ key: `gap:${platform}_ota:exposure`, kind: 'gap', sourceKey: `${platform}_ota`,
      businessDate: '2026-09-10', status: 'missing', label: '曝光缺口', display: '—', reasonText: '缺少同店同日严格回读；不是0。' }] }] };
}
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setImmediate(resolve)); };
function harness(t, platform = 'ctrip') {
  const requests = [], errors = [], knownErrors = [], timers = [], stops = [], boundaries = [];
  const seed = { currentPage: 'agent-center', agentTab: 'revenue', revenueAgentTab: 'analysis', isLoggedIn: true,
    user: { id: 11, tenant_id: 7, is_super_admin: true, permissions: { can_view_online_data: true } },
    token: 'synthetic-round127-not-a-credential', authContext: { tenantId: 7, hotelId: 80, tokenStatus: 'valid', permissionStatus: 'allowed', platform: 'all' },
    filterReportHotel: '80', coreOperationsHotelId: '80', coreOperationsTargetDate: '2026-09-19', autoFetchHotelId: '80',
    revenueAiBusinessDate: '2026-09-19', revenueCockpitModel: model(platform), revenueCockpitBusinessDate: '2026-09-10', revenueCockpitPlatform: 'all_ota',
    revenueCockpitLoading: false, revenueAiStaticReady: true, revenueAiStaticLoading: false, revenueAiStaticError: '',
    onlineDataTab: 'data-health', onlineDataFilter: { hotel_id: '81', source: 'qunar', start_date: '2025-01-01', end_date: '2025-01-02',
      data_type: 'review', data_types: 'quality,review', create_start: '2025-02-01', create_end: '2025-02-02', status: 'old' },
    onlineDataPage: 9, onlineDataPagination: { page: 9, page_size: 30, total: 1 },
    onlineDataList: [{ id: 999, system_hotel_id: 81, hotel_name: 'OLD_WRONG_HOTEL', data_date: '2025-01-01', source: 'qunar' }],
    onlineDataListLoading: false, onlineDataListError: '', onlineDataLoadedQuery: null, downloadCenterTab: platform, onlineDataQualitySummary: { stale: true },
    onlineDataSummary: { ota_channel_supplement: { data_status: 'ok', advertising: { spend: 98765, order_amount: 100000, roas: 1.01, bookings: 50 }, service_quality: { avg_psi_score: 4.8, avg_service_score: 4.9 } } },
    selectedOnlineDataIds: [], analysisData: {}, hotels: [{ id: 80, name: '合成酒店 A' }, { id: 81, name: '旧酒店 B' }],
    toast: { show: false, message: '', type: 'info' }, autoFetchRunState: { active: false, type: '' }, fetchingData: false,
  };
  for (const name of ['homeSecondaryPanelsReady', 'dataHealthSecondaryPanelsReady', 'dataHealthDetailPanelsReady', 'dataHealthEmployeePanelsReady',
    'platformAutoSettingsPanelsReady', 'platformAutoSecondaryPanelsReady', 'platformSourceGuidePanelsReady', 'ctripEbookingModuleCardsReady',
    'ctripEbookingSecondaryPanelsReady', 'ctripEbookingDeepPanelsReady', 'ctripEbookingBusinessDetailsReady', 'ctripEbookingDiagnosticsPanelsReady']) seed[name] = false;
  const refs = Object.fromEntries(Object.entries(seed).map(([key, value]) => [key, Vue.ref(clone(value))]));
  const setTimer = (callback, delay = 0) => { const timer = { callback, delay: Number(delay), cleared: false, executed: false }; timers.push(timer); return timer; };
  const clearTimer = timer => { if (timer) timer.cleared = true; };
  const port = name => () => { boundaries.push(name); };
  const blocked = name => () => { throw new Error(`Forbidden adjacent action: ${name}`); };
  const sandbox = { ...refs, ref: Vue.ref, computed: Vue.computed, nextTick: Vue.nextTick, h: Vue.h, cachedPermittedHotels: clone(seed.hotels),
    watch: (...args) => { const stop = Vue.watch(...args); stops.push(stop); return stop; },
    window: {}, document: { documentElement: { dataset: { suxiRenderPhase: 'full' } } },
    URL, URLSearchParams, Headers, FormData, AbortController, DOMException, Date, TypeError,
    setTimeout: setTimer, clearTimeout: clearTimer, deferUiTask: setTimer, baseScheduleDelayedPageTask: setTimer,
    API_BASE: 'https://synthetic.invalid/api', authSessionEpoch: 1, previousPageLifecycleKey: 'agent-center', dataLoadTimer: null,
    pendingOnlineDataEntryTab: '', suppressNextOnlineDataTabWatcherLoad: false, suppressNextDataHealthTabLoad: false,
    MANUAL_ONLINE_FETCH_CONFIG_TABS: new Set(['ctrip', 'meituan']), revenueAiGapNavigationSeq: 0,
    requestSuxiFullRenderForPage: null, debugLog: () => {},
    resetCoreOperationsScopedState: blocked('same-hotel reset must not be needed'), invalidateCoreOperationsScopedState: port('invalidateCoreOperationsScopedState'), clearAuthSessionIfCurrent: blocked('auth mutation'),
    ensureRevenueAiStaticReady: blocked('already loaded Revenue AI'),
    console: { error: (...args) => {
      const failure = args.at(-1), call = requests.find(call => call.failure === failure);
      if (call && failure instanceof TypeError && failure.message === 'Failed to fetch'
        && ['API请求失败:', '加载数据列表失败:', '加载汇总失败:', '刷新数据失败:'].includes(args[0])) { knownErrors.push({ prefix: args[0], path: call.pathname }); return; }
      errors.push(args.map(String).join(' '));
    }, warn: (...args) => errors.push(args.map(String).join(' ')) },
    fetch: (url, options) => new Promise((resolve, reject) => {
      const parsed = new URL(url); assert.equal(parsed.origin, 'https://synthetic.invalid'); assert.equal(options.method || 'GET', 'GET');
      assert.ok(['/api/online-data/daily-data-list', '/api/online-data/daily-data-summary'].includes(parsed.pathname), parsed.pathname);
      assert.equal(new Headers(options.headers).get('Authorization'), refs.token.value); assert.ok(options.signal);
      for (const [key, expected] of Object.entries({ system_hotel_id: '80', source: platform, start_date: '2026-09-10', end_date: '2026-09-10' })) assert.equal(parsed.searchParams.get(key), expected, key);
      for (const key of ['data_type', 'data_types', 'create_start', 'create_end']) assert.equal(parsed.searchParams.has(key), false, key);
      if (parsed.pathname.endsWith('list')) assert.equal(parsed.searchParams.get('page'), '1');
      const call = { pathname: parsed.pathname, query: parsed.search, resolve, reject, settled: false, aborted: false };
      options.signal.addEventListener('abort', () => { call.aborted = true; if (!call.settled) { call.settled = true; reject(new DOMException('Original abort', 'AbortError')); } });
      requests.push(call);
    }),
  };
  // Chart/timer cleanup ports never replace navigation, scheduling, readers, or request.
  for (const name of ['stopAutoFetchProgressMonitor', 'stopAutoFetchRunTimer', 'clearPostFetchRefreshTimers',
    'clearHomeSecondaryPanelsReadyTimer', 'clearDualOtaSystemMetricDrilldownHydrationTimer', 'destroyHomeTrendChart',
    'clearManualOnlineFetchConfigPrewarmTimer', 'clearDataHealthSecondaryPanelsReadyTimer', 'clearDataHealthDetailPanelsReadyTimer',
    'clearDataHealthEmployeePanelsReadyTimer', 'clearPlatformAutoSettingsPanelsReadyTimer', 'clearPlatformAutoSecondaryPanelsReadyTimer',
    'clearPlatformSourceGuidePanelsReadyTimer', 'destroyAnalysisChart', 'clearCtripEbookingModuleCardsReadyTimer',
    'clearCtripEbookingSecondaryPanelsReadyTimer', 'clearCtripEbookingDeepPanelsReadyTimer', 'clearCtripEbookingBusinessDetailsReadyTimer',
    'resetHotelManagementRowsReady', 'stopAutomationMonitorPolling']) sandbox[name] = port(name);
  for (const name of ['schedulePageControlTestIdObserverStart', 'scheduleFormOperationSupportLoad']) sandbox[name] = delay => setTimer(blocked(name), delay || 5200);
  vm.createContext(sandbox);
  vm.runInContext(cut(componentSource, '    const OnlineTruthSummary = {', '    const dualOtaReceiptReasonLabels =') + '\nglobalThis.OriginalOnlineTruthSummary = OnlineTruthSummary;', sandbox);
  for (const [file, source] of statics) vm.runInContext(source, sandbox, { filename: file });
  sandbox.appSystemStatic = sandbox.window.SUXI_SYSTEM_STATIC;
  sandbox.formatNumber = sandbox.appSystemStatic.formatNumber;
  sandbox.revenueAiResolveGapTarget = sandbox.window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiGapTarget;
  const source = cut(main, '    const requireAppSystemStatic = (key) => {', '    const requireUserAdminStatic =') + '\n' + requestSource + '\n'
    + cut(main, '            const ONLINE_DATA_PANEL_CACHE_TTL_MS =', '            const onlineDataCorrectionLedgerFieldLabels =') + '\n'
    + targetNames.map(decl).join('\n') + '\n' + fullRender + '\n' + watchers;
  vm.runInContext(source + `\nglobalThis.original = { ${targetNames.join(',')}, clearPageLifecycleTimers,
    pendingReads: () => [...onlineDataListRequestPromises.values(), ...onlineDataSummaryRequestPromises.values()],
    coordinator: () => ({ active: coordinatedGetActiveCount, queued: coordinatedGetQueue.length, entries: coordinatedGetRequests.size }),
    generation: () => pageRequestGeneration };`, sandbox);
  let tree;
  const inspect = async () => {
    const app = Vue.createSSRApp({ components: { TermHelp: sandbox.appSystemStatic.createSuxiTermHelpComponent(Vue.h), OnlineTruthSummary: sandbox.OriginalOnlineTruthSummary },
      setup: () => ({ ...refs, ...sandbox.original, formatNumber: sandbox.formatNumber }), render() { tree = render(this, []); return tree; } });
    app.config.warnHandler = message => { errors.push(message); throw new Error(message); };
    app.config.errorHandler = error => { errors.push(error.message); throw error; };
    const html = await renderToString(app), entries = [];
    const walk = (node, parents = []) => {
      if (Array.isArray(node)) return node.forEach(child => walk(child, parents));
      if (!node || typeof node !== 'object') return;
      entries.push({ node, parents }); walk(node.children, [...parents, node]);
    };
    walk(tree); return { html, entries };
  };
  const click = async entry => {
    assert.ok(entry); assert.equal(entry.node.props?.disabled, undefined);
    assert.ok(!entry.parents.some(parent => parent.props?.disabled || parent.props?.style?.display === 'none'));
    const result = entry.node.props.onClick(); await tick(); return result;
  };
  const open = async () => {
    const page = await inspect(), entry = page.entries.find(({ node }) => node.props?.['data-testid'] === 'revenue-cockpit-open-evidence');
    assert.ok(entry, 'Current original gap card must offer scope-preserving records navigation (baseline lacks it)');
    const result = await click(entry); assert.equal(result, true); assert.equal(refs.currentPage.value, 'online-data'); assert.equal(refs.onlineDataTab.value, 'data');
    assert.equal(sandbox.original.generation(), 1, 'Original page watcher runs');
    assert.equal(requests.length, 0, 'Original data timer has not run yet');
    const loading = await inspect(); assert.ok(loading.html.includes('正在读取当前范围记录')); assert.equal(loading.html.includes('OLD_WRONG_HOTEL'), false);
    assert.equal(refs.onlineDataSummary.value, null); assert.equal(loading.html.includes('98,765'), false);
    const timer = timers.find(timer => !timer.cleared && !timer.executed && timer.delay === 100); assert.ok(timer);
    timer.executed = true; timer.callback(); await tick(); assert.equal(requests.length, 2);
  };
  const respond = (call, data) => { assert.ok(call && !call.settled); call.settled = true; call.resolve(new Response(JSON.stringify({ code: 200, message: '操作成功', time: 1790000000, data }), { status: 200 })); };
  const reply = (empty = false) => {
    for (const call of requests.filter(call => !call.settled)) respond(call, call.pathname.endsWith('list')
      ? { list: empty ? [] : [{ id: 701, tenant_id: 7, system_hotel_id: 80, hotel_name: '合成酒店 A', data_date: '2026-09-10', source: platform,
        data_type: 'traffic' }], pagination: { total: empty ? 0 : 1, page: 1, page_size: 30 } }
      // Consumed public empty/unverified-summary projection; amounts remain missing.
      : { daily: [], total: { total_amount: null, total_quantity: null, total_book_order_num: null, avg_comment_score: null, sample_count: 0, data_status: 'pending' } });
  };
  const finish = async () => { await Promise.allSettled(sandbox.original.pendingReads()); await tick(); assert.deepEqual(errors, []); assert.ok(requests.every(call => call.settled)); assert.deepEqual(clone(sandbox.original.coordinator()), { active: 0, queued: 0, entries: 0 }); };
  t.after(async () => {
    for (const call of requests.filter(call => !call.settled)) { call.teardown_only = true; call.settled = true; call.reject(new DOMException('Teardown only', 'AbortError')); }
    await Promise.allSettled(sandbox.original.pendingReads()); await tick();
    sandbox.original.clearPageLifecycleTimers(); stops.forEach(stop => stop()); timers.forEach(clearTimer);
    t.diagnostic(JSON.stringify({ readers, requests: requests.map(({ pathname, query, settled, aborted, teardown_only }) => ({ pathname, query, settled, aborted, teardown_only })), knownErrors, errors, boundaries,
      timers: timers.map(({ delay, cleared, executed }) => ({ delay, cleared, executed })), coordinator: sandbox.original.coordinator(), evidence: 'synthetic public projections; original nav/watch/read/request; no HTTP/DB/collection' }));
    await finish();
  });
  return { refs, requests, inspect, click, open, reply, finish, original: sandbox.original };
}

test('Ctrip gap -> original same-scope data page, real read chain and visible saved-record identity', async t => {
  const h = harness(t), before = clone(h.refs.revenueCockpitModel.value); await h.open(); h.reply(); await h.finish();
  const page = await h.inspect(); assert.ok(page.html.includes('合成酒店 A')); assert.ok(page.html.includes('2026-09-10')); assert.equal(page.html.includes('OLD_WRONG_HOTEL'), false);
  assert.equal(h.refs.onlineDataFilter.value.source, 'ctrip'); assert.equal(h.refs.onlineDataFilter.value.hotel_id, '80'); assert.equal(h.refs.onlineDataPage.value, 1);
  assert.deepEqual(clone(h.refs.revenueCockpitModel.value), before, 'Reading records never upgrades the missing card');
});
test('Meituan failed read is visible failure, then original explicit query retry may show true empty', async t => {
  const h = harness(t, 'meituan'); await h.open();
  for (const call of h.requests) { call.failure = new TypeError('Failed to fetch'); call.settled = true; call.reject(call.failure); }
  await h.finish(); const failed = await h.inspect(); assert.ok(failed.html.includes('Failed to fetch')); assert.ok(failed.html.includes('可点击查询重试')); assert.equal(failed.html.includes('暂无入库数据'), false);
  assert.equal(h.refs.onlineDataSummary.value, null); assert.equal(failed.html.includes('98,765'), false);
  const query = failed.entries.find(({ node }) => node.type === 'button' && textOf(node).trim() === '查询'); assert.ok(query);
  const pending = query.node.props.onClick(); await tick(); assert.equal(h.requests.length, 4); h.reply(true); await pending; await h.finish();
  const page = await h.inspect(); assert.ok(page.html.includes('暂无入库数据')); assert.equal(page.html.includes('可点击查询重试'), false);
  assert.equal(h.refs.onlineDataFilter.value.source, 'meituan'); assert.equal(h.refs.revenueCockpitModel.value.status, 'partial');
});
test('unknown source, missing date, stale card and blocked model do not create a navigation action', async t => {
  const h = harness(t); assert.equal(typeof h.original.revenueCockpitEvidenceTarget, 'function', 'New caller qualification exists');
  const card = h.refs.revenueCockpitModel.value.visibleSections[0].cards[0];
  assert.ok(h.original.revenueCockpitEvidenceTarget(card));
  assert.equal(h.original.revenueCockpitEvidenceTarget(clone(card)), null, 'Copied/stale object is not current model member');
  for (const change of [() => { card.sourceKey = 'dingdandao_pms'; }, () => { card.sourceKey = 'ctrip_ota'; card.businessDate = ''; },
    () => { card.businessDate = '2026-09-10'; h.refs.filterReportHotel.value = '81'; },
    () => { h.refs.filterReportHotel.value = '80'; h.refs.revenueCockpitModel.value.status = 'blocked'; }]) {
    change(); assert.equal(h.original.revenueCockpitEvidenceTarget(card), null);
    assert.equal((await h.inspect()).entries.some(({ node }) => node.props?.['data-testid'] === 'revenue-cockpit-open-evidence'), false);
  }
  assert.equal(h.requests.length, 0); assert.equal(h.refs.currentPage.value, 'agent-center');
});
