import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataTemplate = readFileSync(
  'resources/frontend/templates/fragments/35-page-online-data.html',
  'utf8',
);
const authenticatedStyle = readFileSync('public/style.css', 'utf8');

const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.notEqual(start, -1, `missing start marker: ${startMarker}`);
  assert.notEqual(end, -1, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};

const cacheSource = sliceBetween(
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
  'const applyOnlineAnalysisRowsResponse = (data = {}) => {',
  'const resolveDefaultOnlineAnalysisHotelId = async () => {',
);

const createHarness = () => {
  const requests = [];
  const refs = {
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
  };
  const sandbox = {
    ...refs,
    URLSearchParams,
    structuredClone,
    Promise,
    Date,
    onlineAnalysisPageSize: 100,
    debugLog: () => {},
    nextTick: () => Promise.resolve(),
    scheduleAnalysisChartRender: () => {},
    console: { error: () => {} },
    request: (url, options = {}) => new Promise((resolve, reject) => {
      requests.push({ url, options, resolve, reject, settled: false });
    }),
  };
  vm.runInNewContext(
    `${cacheSource}\n${analysisSource}\n${rowsSource}\nthis.__analysisCache = { loadAnalysisData, loadOnlineAnalysisRows, onlineAnalysisDataRequestPromises, onlineAnalysisRowsRequestPromises };`,
    sandbox,
    { filename: 'public/app-main.js#online-analysis-cache' },
  );

  const resolveRequest = (path, response) => {
    const pending = requests.find((entry) => !entry.settled && entry.url.startsWith(path));
    assert.ok(pending, `missing pending request for ${path}`);
    pending.settled = true;
    pending.resolve(response);
    return pending;
  };

  return {
    ...sandbox.__analysisCache,
    refs,
    requests,
    resolveRequest,
  };
};

test('online analysis summary and rows reuse in-flight and recent reads while force bypasses both', async () => {
  const harness = createHarness();

  const firstSummary = harness.loadAnalysisData(null, { cacheMs: 8000 });
  const duplicateSummary = harness.loadAnalysisData(null, { cacheMs: 8000 });
  assert.equal(harness.requests.length, 1, 'matching summary reads must share one transport');
  const summaryTransport = harness.resolveRequest('/online-data/data-analysis?', {
    code: 200,
    data: { summary: { marker: 'summary-a' }, chart_data: null, hotel_ranking: [] },
  });
  const [firstSummaryResult, duplicateSummaryResult] = await Promise.all([firstSummary, duplicateSummary]);
  assert.equal(firstSummaryResult.summary.marker, 'summary-a');
  assert.equal(duplicateSummaryResult.summary.marker, 'summary-a');
  assert.equal(summaryTransport.options.businessContext.hotelId, '80');
  assert.equal(summaryTransport.options.businessContext.tenantId, '');
  assert.equal(harness.onlineAnalysisDataRequestPromises.size, 0);

  harness.refs.analysisData.value.summary.marker = 'mutated-ui-copy';
  const cachedSummary = await harness.loadAnalysisData(null, { cacheMs: 8000 });
  assert.equal(harness.requests.length, 1, 'recent summary must come from the short cache');
  assert.equal(cachedSummary.summary.marker, 'summary-a', 'cache payload must be isolated from UI mutation');

  const forcedSummary = harness.loadAnalysisData(null, { force: true, cacheMs: 8000 });
  assert.equal(harness.requests.length, 2, 'force must create a fresh summary transport');
  harness.resolveRequest('/online-data/data-analysis?', {
    code: 200,
    data: { summary: { marker: 'summary-b' }, chart_data: null, hotel_ranking: [] },
  });
  assert.equal((await forcedSummary).summary.marker, 'summary-b');

  const firstRows = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  const duplicateRows = harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  assert.equal(harness.requests.length, 3, 'matching detail reads must share one transport');
  harness.resolveRequest('/online-data/daily-data-list?', {
    code: 200,
    data: {
      list: [{ id: 1, marker: 'row-a' }],
      pagination: { total: 1, page: 1, page_size: 100 },
      data_quality_summary: { status: 'verified' },
    },
  });
  const [firstRowsResult, duplicateRowsResult] = await Promise.all([firstRows, duplicateRows]);
  assert.equal(firstRowsResult[0].marker, 'row-a');
  assert.equal(duplicateRowsResult[0].marker, 'row-a');
  assert.equal(harness.onlineAnalysisRowsRequestPromises.size, 0);

  harness.refs.onlineAnalysisRows.value[0].marker = 'mutated-ui-row';
  const cachedRows = await harness.loadOnlineAnalysisRows({ cacheMs: 8000 });
  assert.equal(harness.requests.length, 3, 'recent detail rows must come from the short cache');
  assert.equal(cachedRows[0].marker, 'row-a', 'detail cache must be isolated from UI mutation');

  const forcedRows = harness.loadOnlineAnalysisRows({ force: true, cacheMs: 8000 });
  assert.equal(harness.requests.length, 4, 'force must create a fresh detail transport');
  harness.resolveRequest('/online-data/daily-data-list?', {
    code: 200,
    data: {
      list: [{ id: 2, marker: 'row-b' }],
      pagination: { total: 1, page: 1, page_size: 100 },
    },
  });
  assert.equal((await forcedRows)[0].marker, 'row-b');
});

test('manual analysis query and row refresh explicitly bypass the short cache', () => {
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
