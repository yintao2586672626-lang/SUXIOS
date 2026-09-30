import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataTemplate = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const authenticatedStyle = readFileSync('public/style.css', 'utf8');

const sliceBetween = (source, startMarker, endMarker, { includeEnd = false } = {}) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.notEqual(start, -1, `missing start marker: ${startMarker}`);
  assert.notEqual(end, -1, `missing end marker: ${endMarker}`);
  return source.slice(start, end + (includeEnd ? endMarker.length : 0));
};
const ownerSource = sliceBetween(
  appMain,
  'const ONLINE_ANALYSIS_PANEL_CACHE_TTL_MS = 8000;',
  'const onlineAnalysisSummaryCards = computed',
);
const analysisSource = sliceBetween(
  appMain,
  'const loadAnalysisData = async (dimension = null, options = {}) => {',
  '// 渲染分析图表',
);
const rowsSource = sliceBetween(
  appMain,
  'const applyOnlineAnalysisRowsResponse = (data = {}, requestOwner = null) => {',
  'const resolveDefaultOnlineAnalysisHotelId = async () => {',
);
const feedSource = sliceBetween(appMain, 'const loadCompetitorEventFeed =', 'const competitorObservationOffsetDate =');
const refreshSource = sliceBetween(appMain, 'const refreshOnlineAnalysis =', 'const openOnlineAnalysisTab =');
const futureWindowSource = appMain.match(/const \{[^\n]*\} = ctripStatic\.createCompetitorFutureWindowController\([^\n]+/)[0];
const coordinatorSource = sliceBetween(
  appMain,
  'const COORDINATED_GET_MAX_CONCURRENCY = 3;',
  'const apiRequest = request;',
  { includeEnd: true },
);

const abortError = (message = 'Authentication session changed') => {
  const error = new Error(message);
  error.name = 'AbortError';
  return error;
};
const flushCoordinator = async () => {
  for (let index = 0; index < 10; index += 1) await Promise.resolve();
};

const createHarness = () => {
  const requests = [];
  const sessionState = { epoch: 1, token: 'token-a' };
  const refs = {
    authContext: { value: { tenantId: 'tenant-a', hotelId: '80' } },
    user: { value: { id: 'user-a', tenant_id: 'tenant-a', hotel_id: 80 } },
    analysisDimension: { value: 'day' },
    onlineDataFilter: {
      value: {
        start_date: '2026-08-24',
        end_date: '2026-08-24',
        hotel_id: '80',
        source: 'ctrip',
        data_type: 'orders',
      },
    },
    analysisData: { value: { summary: null, chart_data: null, hotel_ranking: [] } },
    onlineAnalysisError: { value: '' },
    onlineAnalysisRows: { value: [] },
    onlineAnalysisRowsLoading: { value: false },
    onlineAnalysisPagination: { value: { total: 0, page: 1, page_size: 100 } },
    onlineAnalysisQualitySummary: { value: null },
    onlineAnalysisSourceRecord: { value: null },
    competitorEventFeed: { value: null },
    competitorEventFeedLoading: { value: false },
    competitorEventFeedError: { value: '' },
    competitorEventFeedStayDate: { value: '2026-08-24' },
    selectedCtripHotelId: { value: '80' },
  };
  const currentPage = { value: 'online-data' };
  const filterReportHotel = { value: '80' };
  const pageRequestGeneration = 1;
  let resetAnalysisState = () => {};
  let resetCoordinator = () => {};

  const captureAuthSession = () => ({ ...sessionState });
  const isAuthSessionCurrent = (session = {}) => (
    Number(session.epoch) === sessionState.epoch
    && String(session.token || '') === sessionState.token
  );
  const currentPageReadPolicy = (pageKey = currentPage.value, priority = 'current') => ({
    scope: 'page',
    pageKey: String(pageKey || ''),
    pageGeneration: pageRequestGeneration,
    sessionEpoch: sessionState.epoch,
    tenantId: String(refs.authContext.value?.tenantId || ''),
    userId: String(refs.user.value?.id || ''),
    systemHotelId: String(refs.onlineDataFilter.value.hotel_id || ''),
    businessDate: '',
    priority,
  });
  const isPageLoadPolicyCurrent = (policy = {}) => (
    Number(policy.sessionEpoch ?? sessionState.epoch) === sessionState.epoch
    && (policy.scope !== 'page'
      || (Number(policy.pageGeneration) === pageRequestGeneration
        && String(policy.pageKey || '') === currentPage.value))
    && (!policy.tenantId || String(policy.tenantId) === String(refs.authContext.value?.tenantId || ''))
    && (!policy.userId || String(policy.userId) === String(refs.user.value?.id || ''))
    && (!policy.systemHotelId || String(policy.systemHotelId) === String(refs.onlineDataFilter.value.hotel_id || ''))
  );
  const clearAuthSessionIfCurrent = (session) => {
    if (!session?.token || !isAuthSessionCurrent(session)) return false;
    sessionState.epoch += 1;
    sessionState.token = '';
    refs.authContext.value = {};
    refs.user.value = null;
    resetCoordinator();
    resetAnalysisState();
    return true;
  };
  const fetch = (url, options = {}) => new Promise((resolve, reject) => {
    const transport = { url, options, resolve, reject, settled: false, aborted: false };
    options.signal?.addEventListener('abort', () => { transport.aborted = true; }, { once: true });
    requests.push(transport);
  });
  const token = {};
  Object.defineProperty(token, 'value', {
    get: () => sessionState.token,
    set: value => { sessionState.token = String(value || ''); },
  });
  const context = vm.createContext({
    ...refs,
    AbortController,
    API_BASE: '/api',
    Date,
    Headers,
    JSON,
    Map,
    Math,
    Object,
    Promise,
    String,
    URL,
    URLSearchParams,
    applyAuthContext() {},
    captureAuthSession,
    clearAuthSessionIfCurrent,
    console: { error() {}, warn() {} },
    createRequestAbortError: abortError,
    currentPage,
    currentPageReadPolicy,
    debugLog() {},
    fetch,
    filterReportHotel,
    isAuthSessionCurrent,
    isPageLoadPolicyCurrent,
    isTerminalAuthFailureResponse: (response = {}, data = {}) => response.status === 401 || data.code === 401,
    nextTick: () => Promise.resolve(),
    normalizeTokenStatusFromReason: () => 'expired',
    normalizeRequestCacheOptions: options => options,
    loadOnlineDataSummary: async () => null,
    resolveDefaultOnlineAnalysisHotelId: async () => String(filterReportHotel.value || ''),
    onlineAnalysisPageSize: 100,
    competitorEventFeedRequestSeq: 0,
    pageRequestGeneration,
    scheduleAnalysisChartRender() {},
    showToast() {},
    structuredClone,
    terminalAuthFailureReason: () => '',
    token,
    window: {},
    ref: value => ({ value }),
    computed: getter => ({ get value() { return getter(); } }),
    shanghaiToday: () => '2026-08-24',
    withBusinessRequestContext: (url, options) => ({ url, options }),
  });
  vm.runInContext(`${readFileSync('public/system-static.js', 'utf8')}\nconst appSystemStatic = window.SUXI_SYSTEM_STATIC;\nconst readRequestCooldown = appSystemStatic.createReadRequestCooldown();`, context);
  vm.runInContext(`${readFileSync('public/ctrip-static-loader.js', 'utf8')}\nconst ctripStatic = window.SUXI_CTRIP_STATIC;\n${futureWindowSource}`, context);
  vm.runInContext(
    `${ownerSource}\n${analysisSource}\n${rowsSource}\n${coordinatorSource}\n${feedSource}\n${refreshSource}\n`
    + `globalThis.__onlineAnalysis = {
      loadAnalysisData,
      loadCompetitorEventFeed,
      loadOnlineAnalysisRows,
      refreshOnlineAnalysis,
      resetOnlineAnalysisSessionState,
      resetGetRequestCoordinator,
      coordinatedGetScopeKey,
      coordinatedGetSuccessCache,
      loadCompetitorFutureWindow,
      competitorFutureWindowPanelModel,
      onlineAnalysisMetricDimension, onlineAnalysisLoadedFilterKey, onlineAnalysisQueryChanged,
      onlineAnalysisMetricOptions, onlineAnalysisRowsLoadedScope,
    };`,
    context,
    { filename: 'public/app-main.js#online-analysis-auth-cache' },
  );
  resetAnalysisState = context.__onlineAnalysis.resetOnlineAnalysisSessionState;
  resetCoordinator = context.__onlineAnalysis.resetGetRequestCoordinator;

  const resolveTransport = (transport, body, status = 200) => {
    assert.ok(transport && !transport.settled, 'transport must still be pending');
    transport.settled = true;
    transport.resolve({
      ok: status >= 200 && status < 300,
      status,
      json: async () => body,
    });
    return transport;
  };
  const pendingTransport = path => requests.find(
    entry => !entry.settled && entry.url.includes(path),
  );
  const loginAccountB = () => {
    sessionState.epoch += 1;
    resetCoordinator();
    resetAnalysisState();
    sessionState.token = 'token-b';
    refs.authContext.value = { tenantId: 'tenant-b', hotelId: '80' };
    refs.user.value = { id: 'user-b', tenant_id: 'tenant-b', hotel_id: 80 };
  };

  return {
    ...context.__onlineAnalysis,
    refs,
    requests,
    sessionState,
    resolveTransport,
    pendingTransport,
    loginAccountB,
  };
};

test('online analysis delegates in-flight, TTL, and force behavior to the shared GET coordinator', async () => {
  const harness = createHarness();
  const firstSummary = harness.loadAnalysisData(null, { cacheMs: 8000 });
  const duplicateSummary = harness.loadAnalysisData(null, { cacheMs: 8000 });
  await flushCoordinator();
  assert.equal(harness.requests.length, 1);
  harness.resolveTransport(harness.requests[0], {
    code: 200,
    data: { summary: { marker: 'summary-a' }, chart_data: null, hotel_ranking: [] },
  });
  const [first, duplicate] = await Promise.all([firstSummary, duplicateSummary]);
  assert.equal(first.summary.marker, 'summary-a');
  assert.equal(duplicate.summary.marker, 'summary-a');
  assert.equal((await harness.loadAnalysisData(null, { cacheMs: 8000 })).summary.marker, 'summary-a');
  assert.equal(harness.requests.length, 1, 'TTL reuse must not start another fetch');

  const forcedSummary = harness.loadAnalysisData(null, { force: true, cacheMs: 8000 });
  await flushCoordinator();
  assert.equal(harness.requests.length, 2);
  harness.resolveTransport(harness.requests[1], {
    code: 200,
    data: { summary: { marker: 'summary-b' }, chart_data: null, hotel_ranking: [] },
  });
  assert.equal((await forcedSummary).summary.marker, 'summary-b');

  const firstRows = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  const duplicateRows = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  await flushCoordinator();
  assert.equal(harness.requests.length, 3);
  harness.resolveTransport(harness.requests[2], {
    code: 200,
    data: {
      list: [{ id: 1, marker: 'row-a' }],
      pagination: { total: 1, page: 1, page_size: 100 },
      data_quality_summary: { status: 'verified' },
    },
  });
  const [rows, duplicateRowResult] = await Promise.all([firstRows, duplicateRows]);
  assert.equal(rows[0].marker, 'row-a');
  assert.equal(duplicateRowResult[0].marker, 'row-a');
  assert.equal((await harness.loadOnlineAnalysisRows({ cacheMs: 8000 }))[0].marker, 'row-a');
  assert.equal(harness.requests.length, 3);
});

const futureWindowReply = (name = 'synthetic account A') => ({ code: 200, data: {
  system_hotel_id: 80, platform: 'ctrip', start_date: '2026-08-24', end_date: '2026-09-13', days: 21,
  matrix: [{ stay_date: '2026-08-24', cells: [{ competitor_hotel_name: name, price: 318 }] }],
} });

test('future competitor window clears already loaded account A facts on account B login', async () => {
  const h = createHarness();
  const loaded = h.loadCompetitorFutureWindow(); await flushCoordinator();
  h.resolveTransport(h.pendingTransport('/competitor/future-window?'), futureWindowReply()); await loaded;
  assert.match(h.competitorFutureWindowPanelModel.value.dayText, /synthetic account A/);
  h.loginAccountB();
  assert.equal(h.competitorFutureWindowPanelModel.value.empty, true);
  assert.doesNotMatch(h.competitorFutureWindowPanelModel.value.dayText, /synthetic account A/);
});

test('future competitor window ignores prior-session cancellation and recovers for account B', async () => {
  const h = createHarness();
  const old = h.loadCompetitorFutureWindow(); await flushCoordinator();
  const oldTransport = h.pendingTransport('/competitor/future-window?');
  h.loginAccountB();
  const current = h.loadCompetitorFutureWindow(); await old; await flushCoordinator();
  assert.equal(h.competitorFutureWindowPanelModel.value.error, '');
  assert.equal(h.competitorFutureWindowPanelModel.value.loading, true, 'old catch/finally cannot finish the new request');
  h.resolveTransport(oldTransport, futureWindowReply()); await flushCoordinator();
  assert.equal(h.competitorFutureWindowPanelModel.value.empty, true);
  h.resolveTransport(h.pendingTransport('/competitor/future-window?'), futureWindowReply('synthetic account B')); await current;
  assert.match(h.competitorFutureWindowPanelModel.value.dayText, /synthetic account B/);
  assert.doesNotMatch(h.competitorFutureWindowPanelModel.value.dayText, /synthetic account A/);
});

test('future competitor window terminal 401 clears the loaded snapshot and stale error', async () => {
  const h = createHarness();
  const loaded = h.loadCompetitorFutureWindow(); await flushCoordinator();
  h.resolveTransport(h.pendingTransport('/competitor/future-window?'), futureWindowReply()); await loaded;
  const failure = h.loadOnlineAnalysisRows(); await flushCoordinator();
  h.resolveTransport(h.requests.find(item => !item.settled), { code: 401, message: 'expired' }, 401); await failure;
  assert.equal(h.competitorFutureWindowPanelModel.value.empty, true);
  assert.equal(h.competitorFutureWindowPanelModel.value.error, '');
});

test('future competitor window with no Ctrip hotel does not borrow the online-analysis hotel', async () => {
  const h = createHarness();
  h.refs.selectedCtripHotelId.value = '';
  assert.equal(h.refs.onlineDataFilter.value.hotel_id, '80');
  const empty = h.loadCompetitorFutureWindow({ systemHotelId: '', platform: 'ctrip' }); await flushCoordinator();
  assert.equal(h.requests.length, 0);
  assert.equal(await empty, null);
  assert.equal(h.competitorFutureWindowPanelModel.value.empty, true);
  assert.equal(h.competitorFutureWindowPanelModel.value.loading, false);
  h.refs.selectedCtripHotelId.value = '80';
  const recovered = h.loadCompetitorFutureWindow(); await flushCoordinator();
  h.resolveTransport(h.pendingTransport('/competitor/future-window?'), futureWindowReply('restored Ctrip hotel')); await recovered;
  assert.match(h.competitorFutureWindowPanelModel.value.dayText, /restored Ctrip hotel/);
});

test('automatic 401 followed by same-page account B login rejects account A cache and late analysis responses', async () => {
  const harness = createHarness();
  const keyA = harness.coordinatedGetScopeKey(
    { epoch: 1, token: 'token-a' }, 'GET', '/online-data/data-analysis?hotel_id=80', {},
    { tenantId: 'tenant-a', userId: 'user-a', systemHotelId: '80', businessDate: '' },
  );
  assert.match(keyA, /^1::tenant-a::user-a::80::/);

  const cachedSummaryA = harness.loadAnalysisData(null, { cacheMs: 8000 });
  const cachedRowsA = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  await flushCoordinator();
  harness.resolveTransport(harness.pendingTransport('/data-analysis?'), {
    code: 200,
    data: { summary: { marker: 'account-a-cache' }, chart_data: null, hotel_ranking: [] },
  });
  harness.resolveTransport(harness.pendingTransport('/daily-data-list?'), {
    code: 200,
    data: {
      list: [{ id: 1, marker: 'account-a-cache' }],
      pagination: { total: 9, page: 1, page_size: 100 },
      data_quality_summary: { status: 'account-a-quality' },
    },
  });
  await Promise.all([cachedSummaryA, cachedRowsA]);
  harness.refs.onlineAnalysisSourceRecord.value = { marker: 'account-a-source' };

  const staleSummaryA = harness.loadAnalysisData(null, { force: true, cacheMs: 8000 });
  const staleRowsA = harness.loadOnlineAnalysisRows({ force: true, cacheMs: 8000 });
  await flushCoordinator();
  const staleSummaryTransport = harness.pendingTransport('/data-analysis?');
  const staleRowsTransport = harness.pendingTransport('/daily-data-list?');
  harness.resolveTransport(staleSummaryTransport, {
    code: 401,
    data: { reason: 'token_expired' },
  }, 401);
  await flushCoordinator();
  assert.equal(await staleSummaryA, null);
  assert.equal((await staleRowsA).length, 0);
  assert.equal(staleRowsTransport.aborted, true);
  assert.equal(harness.coordinatedGetSuccessCache.size, 0);
  assert.equal(harness.refs.analysisData.value.summary, null);
  assert.equal(harness.refs.onlineAnalysisRows.value.length, 0);
  assert.equal(harness.refs.onlineAnalysisPagination.value.total, 0);
  assert.equal(harness.refs.onlineAnalysisQualitySummary.value, null);
  assert.equal(harness.refs.onlineAnalysisSourceRecord.value, null);
  assert.equal(harness.refs.onlineAnalysisRowsLoading.value, false);

  harness.loginAccountB();
  const keyB = harness.coordinatedGetScopeKey(
    { epoch: 3, token: 'token-b' }, 'GET', '/online-data/data-analysis?hotel_id=80', {},
    { tenantId: 'tenant-b', userId: 'user-b', systemHotelId: '80', businessDate: '' },
  );
  assert.match(keyB, /^3::tenant-b::user-b::80::/);
  assert.notEqual(keyA, keyB);

  const summaryB = harness.loadAnalysisData(null, { cacheMs: 8000 });
  const rowsB = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  await flushCoordinator();
  const summaryTransportB = harness.requests.at(-2);
  const rowsTransportB = harness.requests.at(-1);
  harness.resolveTransport(summaryTransportB, {
    code: 200,
    data: { summary: { marker: 'account-b' }, chart_data: null, hotel_ranking: [] },
  });
  harness.resolveTransport(rowsTransportB, {
    code: 200,
    data: {
      list: [{ id: 2, marker: 'account-b' }],
      pagination: { total: 1, page: 1, page_size: 100 },
      data_quality_summary: { status: 'account-b-quality' },
    },
  });
  await Promise.all([summaryB, rowsB]);

  harness.resolveTransport(staleRowsTransport, {
    code: 200,
    data: {
      list: [{ id: 3, marker: 'account-a-late' }],
      pagination: { total: 77, page: 1, page_size: 100 },
      data_quality_summary: { status: 'account-a-late-quality' },
    },
  });
  await flushCoordinator();
  assert.equal(harness.refs.analysisData.value.summary.marker, 'account-b');
  assert.equal(harness.refs.onlineAnalysisRows.value[0].marker, 'account-b');
  assert.equal(harness.refs.onlineAnalysisPagination.value.total, 1);
  assert.equal(harness.refs.onlineAnalysisQualitySummary.value.status, 'account-b-quality');
  assert.equal(harness.refs.onlineAnalysisSourceRecord.value, null);
  assert.equal(harness.refs.onlineAnalysisError.value, '');
  assert.ok([...harness.coordinatedGetSuccessCache.keys()].every(
    key => key.startsWith('3::tenant-b::user-b::80::'),
  ));
});

test('account changes clear an already loaded competition feed and its hotel permission', async () => {
  const h = createHarness();
  const pending = h.loadCompetitorEventFeed(); await flushCoordinator();
  h.resolveTransport(h.pendingTransport('/competitor/events?'), {code:200,data:{system_hotel_id:80,stay_date:'2026-08-24',platforms:['ctrip'],events:[{id:901}],can_collect_manual_observation:true}});
  await pending;
  assert.equal(h.refs.competitorEventFeed.value.events[0].id,901);
  h.refs.competitorEventFeedError.value = 'Previous synthetic account message';
  h.loginAccountB();
  assert.equal(h.refs.competitorEventFeed.value,null);
  assert.equal(h.refs.competitorEventFeedError.value,'');
  assert.equal(h.refs.competitorEventFeedLoading.value,false);
});

test('cancelled old-session competition reads cannot restore an error or clear the new read; current account can recover', async () => {
  const h = createHarness(), old = h.loadCompetitorEventFeed(); await flushCoordinator();
  const oldTransport = h.pendingTransport('/competitor/events?');
  h.loginAccountB(); await old;
  assert.equal(h.refs.competitorEventFeedError.value,'');
  const current = h.loadCompetitorEventFeed(); await flushCoordinator();
  const newTransport = h.requests.find(row=>row!==oldTransport && !row.settled && row.url.includes('/competitor/events?'));
  h.resolveTransport(oldTransport, {code:200,data:{events:[{id:901}],can_collect_manual_observation:true}}); await flushCoordinator();
  assert.equal(h.refs.competitorEventFeedLoading.value,true);
  assert.equal(h.refs.competitorEventFeed.value,null);
  h.resolveTransport(newTransport, {code:200,data:{system_hotel_id:80,stay_date:'2026-08-24',platforms:['ctrip'],events:[{id:902}],can_collect_manual_observation:false}});
  await current;
  assert.equal(h.refs.competitorEventFeed.value.events[0].id,902);
  assert.equal(h.refs.competitorEventFeed.value.can_collect_manual_observation,false);
  assert.equal(h.refs.competitorEventFeedError.value,'');
  assert.equal(h.refs.competitorEventFeedLoading.value,false);
});

test('competition feed failures preserve service messages and the missing-message fallback, then recover', async () => {
  for (const message of ['合成服务暂不可用', '', undefined]) {
    const h = createHarness();
    const pending = h.loadCompetitorEventFeed(); await flushCoordinator();
    h.resolveTransport(h.pendingTransport('/competitor/events?'), { code: 422, message });
    assert.equal(await pending, null);
    assert.equal(h.refs.competitorEventFeedError.value, message || '统一竞争事件读取失败');
    assert.equal(h.refs.competitorEventFeed.value, null);
    assert.equal(h.refs.competitorEventFeedLoading.value, false);
    const recovery = h.loadCompetitorEventFeed(); await flushCoordinator();
    h.resolveTransport(h.pendingTransport('/competitor/events?'), { code: 200, data: { events: [] } });
    await recovery;
    assert.equal(h.refs.competitorEventFeedError.value, '');
    assert.equal(h.refs.competitorEventFeedLoading.value, false);
  }
});

test('clearing the feed hotel or date clears loading without a request and invalidates the earlier read', async () => {
  for (const key of ['hotel', 'date']) {
    const h = createHarness(), old = h.loadCompetitorEventFeed(); await flushCoordinator();
    const oldTransport = h.pendingTransport('/competitor/events?');
    h.refs.competitorEventFeed.value = { events: [{ id: 901 }] };
    if (key === 'hotel') h.refs.onlineDataFilter.value.hotel_id = '';
    else h.refs.competitorEventFeedStayDate.value = '';
    const requestCount = h.requests.length;
    assert.equal(await h.loadCompetitorEventFeed(), null);
    assert.equal(h.requests.length, requestCount);
    assert.equal(h.refs.competitorEventFeed.value, null);
    assert.equal(h.refs.competitorEventFeedLoading.value, false);
    h.resolveTransport(oldTransport, { code: 200, data: { events: [{ id: 901 }] } }); await old;
    assert.equal(h.refs.competitorEventFeed.value, null);
    assert.equal(h.refs.competitorEventFeedError.value, '');
    assert.equal(h.refs.competitorEventFeedLoading.value, false);
  }
});

test('metric dimension selection is visible and distinguishes loaded summary and detail scopes', () => {
  assert.match(onlineDataTemplate, /label for="online-analysis-metric-dimension">指标口径/);
  assert.match(onlineDataTemplate, /data-testid="online-analysis-metric-dimension" v-model="onlineAnalysisMetricDimension"/);
  assert.match(onlineDataTemplate, /onlineAnalysisMetricOptions/);
  assert.match(onlineDataTemplate, /data-testid="online-analysis-loaded-scope"/);
  assert.match(onlineDataTemplate, /analysisData.query_scope.metric_dimension/);
  assert.match(onlineDataTemplate, /onlineAnalysisQueryChanged/);
  assert.match(onlineDataTemplate, /data-testid="online-analysis-detail-scope"/);
  assert.match(onlineDataTemplate, /onlineAnalysisRowsLoadedScope.metric_dimension/);
  assert.match(onlineDataTemplate, /没有可选的可信指标口径/);
});

test('metric selection scopes requests and cache, preserves old snapshot on failure, and can return to all dimensions', async () => {
  const h = createHarness();
  const first = h.loadAnalysisData();
  await flushCoordinator();
  h.resolveTransport(h.requests[0], { code: 200, data: {
    summary: { marker: 'all-blocked' }, query_scope: { metric_dimension: '' },
    metric_dimension_options: [{ value: 'revenue', label: 'revenue' }, { value: 'nights', label: 'nights' }],
  } });
  await first;
  assert.equal(h.onlineAnalysisQueryChanged.value, false);
  assert.equal(h.onlineAnalysisMetricDimension.value, '', 'catalog does not auto-select a dimension');
  assert.equal(h.onlineAnalysisMetricOptions.value.length, 2);
  h.onlineAnalysisMetricDimension.value = 'revenue';
  assert.equal(h.onlineAnalysisQueryChanged.value, true);
  const selected = h.loadAnalysisData();
  await flushCoordinator();
  assert.match(h.requests[1].url, /metric_dimension=revenue/);
  h.resolveTransport(h.requests[1], { code: 200, data: { summary: { marker: 'revenue' }, query_scope: { metric_dimension: 'revenue' } } });
  assert.equal((await selected).summary.marker, 'revenue');
  assert.equal(h.onlineAnalysisQueryChanged.value, false);
  h.onlineAnalysisMetricDimension.value = 'nights';
  const failed = h.loadAnalysisData();
  await flushCoordinator();
  assert.equal(h.requests.length, 3, 'another dimension cannot reuse revenue cache');
  h.resolveTransport(h.requests[2], { code: 422, message: 'Synthetic unavailable dimension' });
  assert.equal(await failed, null);
  assert.equal(h.refs.analysisData.value.summary.marker, 'revenue');
  assert.equal(h.onlineAnalysisQueryChanged.value, true);
  assert.equal(h.refs.onlineAnalysisError.value, 'Synthetic unavailable dimension');
  h.onlineAnalysisMetricDimension.value = '';
  assert.equal((await h.loadAnalysisData()).summary.marker, 'all-blocked', 'all-dimension snapshot has its own scope cache');
  assert.equal(h.onlineAnalysisQueryChanged.value, false);
});

for (const failedPanel of ['summary', 'detail']) {
  test(`concurrent metric refresh retains ${failedPanel} failure after the other panel succeeds in either completion order`, async () => {
    for (const failureFirst of [true, false]) {
      const h = createHarness();
      const summaryReply = (metric, marker) => ({ code: 200, data: {
        summary: { marker }, query_scope: { metric_dimension: metric, dimension: 'day' },
      } });
      const detailReply = (metric, marker) => ({ code: 200, data: {
        list: [{ id: 1, marker }], pagination: { total: 1, page: 1, page_size: 100 },
        query_scope: { metric_dimension: metric }, data_quality_summary: { status: 'verified' },
      } });
      h.onlineAnalysisMetricDimension.value = 'revenue';
      const baseline = h.refreshOnlineAnalysis({ force: true });
      await flushCoordinator();
      h.resolveTransport(h.pendingTransport('/data-analysis?'), summaryReply('revenue', 'known revenue summary'));
      h.resolveTransport(h.pendingTransport('/daily-data-list?'), detailReply('revenue', 'known revenue detail'));
      h.resolveTransport(h.pendingTransport('/competitor/events?'), { code: 200, data: { events: [] } });
      await baseline;
      const baselineKey = h.onlineAnalysisLoadedFilterKey.value;
      h.onlineAnalysisMetricDimension.value = 'nights';
      const pending = h.refreshOnlineAnalysis({ force: true });
      await flushCoordinator();
      const summaryTransport = h.pendingTransport('/data-analysis?');
      const detailTransport = h.pendingTransport('/daily-data-list?');
      assert.ok(summaryTransport && detailTransport, 'both panels must request concurrently');
      assert.match(summaryTransport.url, /metric_dimension=nights/);
      assert.match(detailTransport.url, /metric_dimension=nights/);
      h.resolveTransport(h.pendingTransport('/competitor/events?'), { code: 200, data: { events: [] } });
      const failureMessage = `Synthetic ${failedPanel} unavailable`;
      const fail = () => h.resolveTransport(failedPanel === 'summary' ? summaryTransport : detailTransport,
        { code: 503, message: failureMessage }, 503);
      const succeed = () => h.resolveTransport(failedPanel === 'summary' ? detailTransport : summaryTransport,
        failedPanel === 'summary' ? detailReply('nights', 'current nights detail') : summaryReply('nights', 'current nights summary'));
      (failureFirst ? fail : succeed)();
      await flushCoordinator();
      if (failureFirst) assert.equal(h.refs.onlineAnalysisError.value, failureMessage);
      (failureFirst ? succeed : fail)();
      await pending;
      assert.equal(h.refs.onlineAnalysisError.value, failureMessage,
        `${failedPanel} failure remains visible when failureFirst=${failureFirst}`);
      assert.equal(h.refs.onlineAnalysisRowsLoading.value, false);
      if (failedPanel === 'summary') {
        assert.equal(h.refs.analysisData.value.summary.marker, 'known revenue summary');
        assert.equal(h.refs.analysisData.value.query_scope.metric_dimension, 'revenue');
        assert.equal(h.onlineAnalysisLoadedFilterKey.value, baselineKey);
        assert.equal(h.onlineAnalysisQueryChanged.value, true);
        assert.equal(h.refs.onlineAnalysisRows.value[0].marker, 'current nights detail');
        assert.equal(h.onlineAnalysisRowsLoadedScope.value.metric_dimension, 'nights');
      } else {
        assert.equal(h.refs.analysisData.value.summary.marker, 'current nights summary');
        assert.equal(h.refs.analysisData.value.query_scope.metric_dimension, 'nights');
        assert.equal(h.onlineAnalysisQueryChanged.value, false);
        assert.equal(h.refs.onlineAnalysisRows.value.length, 0);
        assert.equal(h.onlineAnalysisRowsLoadedScope.value, null);
      }
      const retry = failedPanel === 'summary'
        ? h.loadAnalysisData(null, { force: true }) : h.loadOnlineAnalysisRows({ force: true });
      assert.equal(h.refs.onlineAnalysisError.value, '', 'an explicit retry clears the previous attempt error');
      await flushCoordinator();
      h.resolveTransport(h.pendingTransport(failedPanel === 'summary' ? '/data-analysis?' : '/daily-data-list?'),
        failedPanel === 'summary' ? summaryReply('nights', 'recovered nights summary') : detailReply('nights', 'recovered nights detail'));
      await retry;
      assert.equal(h.refs.onlineAnalysisError.value, '');
      assert.equal(h.refs.analysisData.value.query_scope.metric_dimension, 'nights');
      assert.equal(h.onlineAnalysisRowsLoadedScope.value.metric_dimension, 'nights');
    }
  });
}

test('selected metric rejects missing or mismatched response identity rather than showing all-metric totals', async () => {
  for (const scope of [undefined, { metric_dimension: 'nights' }]) {
    const h = createHarness();
    h.onlineAnalysisMetricDimension.value = 'revenue';
    const pending = h.loadAnalysisData();
    await flushCoordinator();
    h.resolveTransport(h.requests[0], { code: 200, data: { summary: { marker: 'wrong' }, query_scope: scope } });
    assert.equal(await pending, null);
    assert.equal(h.refs.analysisData.value.summary, null);
    assert.match(h.refs.onlineAnalysisError.value, /口径与查询不一致/);
  }
});

test('selected metric detail response requires independent scope identity', async () => {
  const h = createHarness();
  h.onlineAnalysisMetricDimension.value = 'revenue';
  const pending = h.loadOnlineAnalysisRows();
  await flushCoordinator();
  h.resolveTransport(h.requests[0], { code: 200, data: { list: [{ id: 3, dimension: 'nights' }], query_scope: { metric_dimension: 'nights' } } });
  assert.equal((await pending).length, 0);
  assert.equal(h.refs.onlineAnalysisRows.value.length, 0);
  assert.equal(h.onlineAnalysisRowsLoadedScope.value, null);
  assert.match(h.refs.onlineAnalysisError.value, /明细指标口径与查询不一致/);
  assert.equal(h.refs.onlineAnalysisRowsLoading.value, false);
});

test('metric options from an already loaded hotel or date are unavailable after draft scope changes', async () => {
  const h = createHarness();
  const pending = h.loadAnalysisData();
  await flushCoordinator();
  h.resolveTransport(h.requests[0], { code: 200, data: { summary: {}, query_scope: { metric_dimension: '' }, metric_dimension_options: [{ value: 'hotel-80-revenue', label: 'hotel-80-revenue' }] } });
  await pending;
  assert.equal(h.onlineAnalysisMetricOptions.value.length, 1);
  h.refs.onlineDataFilter.value.hotel_id = '81';
  assert.equal(h.onlineAnalysisMetricOptions.value.length, 0);
  assert.equal(h.onlineAnalysisQueryChanged.value, true);
  h.refs.onlineDataFilter.value.hotel_id = '80';
  h.refs.onlineDataFilter.value.end_date = '2026-08-25';
  assert.equal(h.onlineAnalysisMetricOptions.value.length, 0);
});

test('late old-dimension summary and row responses cannot overwrite selection or block the next query', async () => {
  const h = createHarness();
  h.onlineAnalysisMetricDimension.value = 'revenue';
  const oldSummary = h.loadAnalysisData();
  const oldRows = h.loadOnlineAnalysisRows();
  await flushCoordinator();
  const summaryTransport = h.pendingTransport('/data-analysis?');
  const rowsTransport = h.pendingTransport('/daily-data-list?');
  assert.match(rowsTransport.url, /metric_dimension=revenue/);
  h.onlineAnalysisMetricDimension.value = 'nights';
  h.resolveTransport(summaryTransport, { code: 200, data: { summary: { marker: 'old' }, query_scope: { metric_dimension: 'revenue' } } });
  h.resolveTransport(rowsTransport, { code: 200, data: { list: [{ id: 1, dimension: 'revenue' }] } });
  assert.equal(await oldSummary, null);
  assert.equal((await oldRows).length, 0);
  assert.equal(h.refs.analysisData.value.summary, null);
  assert.equal(h.refs.onlineAnalysisRows.value.length, 0);
  assert.equal(h.refs.onlineAnalysisRowsLoading.value, false, 'discarded response cannot strand the query button in loading');
  const newRows = h.loadOnlineAnalysisRows();
  await flushCoordinator();
  h.resolveTransport(h.pendingTransport('/daily-data-list?'), { code: 200, data: { list: [{ id: 2, dimension: 'nights' }], pagination: { total: 1 }, query_scope: { metric_dimension: 'nights' } } });
  assert.equal((await newRows)[0].dimension, 'nights');
  assert.equal(h.onlineAnalysisRowsLoadedScope.value.metric_dimension, 'nights');
  h.resetOnlineAnalysisSessionState();
  assert.equal(h.onlineAnalysisMetricDimension.value, '');
  assert.equal(h.onlineAnalysisRowsLoadedScope.value, null);
  assert.equal(h.onlineAnalysisLoadedFilterKey.value, '');
});

test('late prior-date response is discarded even when the hotel and metric are unchanged', async () => {
  const h = createHarness();
  const pending = h.loadAnalysisData();
  await flushCoordinator();
  h.refs.onlineDataFilter.value.end_date = '2026-08-25';
  h.resolveTransport(h.requests[0], { code: 200, data: { summary: { marker: 'old date' } } });
  assert.equal(await pending, null);
  assert.equal(h.refs.analysisData.value.summary, null);
});

test('manual analysis query and row refresh explicitly force the shared coordinator', () => {
  assert.match(onlineDataTemplate, /@click="refreshOnlineAnalysis\(\{ force: true \}\)"/);
  assert.match(onlineDataTemplate, /@click="loadOnlineAnalysisRows\(\{ force: true \}\)"/);
});

test('online-data empty states share one style-equivalent class instead of repeated render strings', () => {
  const aliases = onlineDataTemplate.match(/class="suxi-empty-state"/g) || [];
  assert.equal(aliases.length, 12);
  assert.doesNotMatch(
    onlineDataTemplate,
    /class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-sm text-gray-500"/,
  );
  assert.match(
    authenticatedStyle,
    /main\[data-current-page="online-data"\] \.suxi-empty-state \{[\s\S]*border: 1px dashed var\(--color-border\) !important;[\s\S]*border-radius: \.5rem;[\s\S]*background: var\(--color-surface-warm\) !important;[\s\S]*padding: 1rem;[\s\S]*color: var\(--color-text-muted\) !important;[\s\S]*font-size: 13px !important;[\s\S]*line-height: 1\.25rem;/,
  );
});
