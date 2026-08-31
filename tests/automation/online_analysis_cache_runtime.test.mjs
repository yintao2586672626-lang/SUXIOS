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
    onlineAnalysisPageSize: 100,
    pageRequestGeneration,
    scheduleAnalysisChartRender() {},
    showToast() {},
    structuredClone,
    terminalAuthFailureReason: () => '',
    token,
    withBusinessRequestContext: (url, options) => ({ url, options }),
  });
  vm.runInContext(
    `${ownerSource}\n${analysisSource}\n${rowsSource}\n${coordinatorSource}\n`
    + `globalThis.__onlineAnalysis = {
      loadAnalysisData,
      loadOnlineAnalysisRows,
      resetOnlineAnalysisSessionState,
      resetGetRequestCoordinator,
      coordinatedGetScopeKey,
      coordinatedGetSuccessCache,
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
