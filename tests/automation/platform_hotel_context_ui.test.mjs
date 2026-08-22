import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const appShell = readFileSync('resources/frontend/templates/fragments/00-app-shell.html', 'utf8');
const ctripTemplate = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const meituanTemplate = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const meituanStaticSource = readFileSync('public/meituan-static.js', 'utf8');

const sliceFrom = (source, start, end) => {
  const startIndex = source.indexOf(start);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const platformRequestContextSource = () => sliceFrom(
  appMain,
  'let ctripPlatformHotelContextEpoch = 0;',
  '\n            const ctripSearchOpportunityPayload = ref(null);',
);

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

test('platform pages expose one sticky header hotel context switcher', () => {
  assert.match(appShell, /data-testid="platform-hotel-context"/);
  assert.match(appShell, /data-testid="platform-hotel-context-search"/);
  assert.match(appShell, /role="combobox"/);
  assert.match(appShell, /id="platform-hotel-context-options"/);
  assert.match(appShell, /v-for="hotel in filteredPlatformHotelOptions"/);
  assert.match(appShell, /@mousedown\.prevent="selectPlatformHotelOption\(hotel\)"/);
  assert.match(appShell, /placeholder="搜索名称、城市或ID"/);
  assert.match(appShell, /· ID \{\{ hotel\.id \}\}/);
  assert.match(appShell, /data-testid="platform-hotel-context-config"/);
  assert.match(appShell, /class="platform-hotel-context-config"/);
  assert.match(appShell, /v-if="platformHotelContext"/);
  assert.match(appShell, /platformHotelOptions/);
  assert.match(appShell, /@click="openPlatformHotelContextConfig"/);
  assert.match(appShell, /fetchingData \|\| ctripTrafficBundleLoading/);
  assert.match(appShell, /ctripCommentBrowserCaptureRunning/);
  assert.match(appShell, /ctripDiagnosisSnapshotLoading/);
  assert.match(appShell, /ctripReviewMatchLoading/);
  assert.match(appShell, /fetchingCommentData/);
});

test('platform hotel picker searches by hotel id and keeps the current hotel first', () => {
  const pickerSource = sliceFrom(
    appMain,
    "const platformHotelSearchKeyword = ref('');",
    '\n            const clearPlatformHotelSearch',
  );
  const computed = getter => ({ get value() { return getter(); } });
  const sandbox = {
    ref: value => ({ value }),
    computed,
    platformHotelContext: { value: 'ctrip' },
    onlineDataTab: { value: 'ctrip-traffic' },
    ctripTargetHotelOptions: {
      value: [
        { id: '64', name: '北京示例酒店', city: '北京' },
        { id: '80', name: '敦煌漠蓝新', city: '敦煌' },
      ],
    },
    ctripPublicProfileHotelOptions: {
      value: [
        { id: '64', name: '北京示例酒店', city: '北京' },
        { id: '80', name: '敦煌漠蓝新', city: '敦煌' },
        { id: '91', name: '公开资料待配置门店', city: '西安' },
      ],
    },
    meituanTargetHotelOptions: { value: [] },
    meituanForm: { value: { hotelId: '' } },
    selectedCtripHotelId: { value: '80' },
  };
  const picker = vm.runInNewContext(`(() => {
    ${pickerSource}
    return { platformHotelSearchKeyword, platformHotelSelectedName, filteredPlatformHotelOptions };
  })()`, sandbox, { filename: 'platform-hotel-picker-search-slice.js' });

  assert.equal(picker.platformHotelSelectedName.value, '敦煌漠蓝新');
  assert.deepEqual(
    Array.from(picker.filteredPlatformHotelOptions.value, hotel => hotel.id),
    ['80', '64'],
  );
  picker.platformHotelSearchKeyword.value = '64';
  assert.deepEqual(
    Array.from(picker.filteredPlatformHotelOptions.value, hotel => hotel.name),
    ['北京示例酒店'],
  );
  sandbox.onlineDataTab.value = 'ctrip-public-profiles';
  picker.platformHotelSearchKeyword.value = '91';
  assert.deepEqual(
    Array.from(picker.filteredPlatformHotelOptions.value, hotel => hotel.name),
    ['公开资料待配置门店'],
  );
});

test('daily Ctrip and Meituan modules no longer repeat hotel selectors', () => {
  assert.doesNotMatch(ctripTemplate, /v-model="selectedCtripHotelId"/);
  assert.doesNotMatch(ctripTemplate, /v-model="autoFetchHotelId"/);
  assert.match(ctripTemplate, /v-model="ctripConfigForm\.hotel_id"/);
  assert.doesNotMatch(ctripTemplate, /ctripReviewMatchForm\.systemHotelId/);
  assert.match(ctripTemplate, /data-testid="ctrip-review-current-hotel"/);
  assert.match(ctripTemplate, /订单证据只写入该酒店/);

  assert.doesNotMatch(meituanTemplate, /v-model="meituanForm\.hotelId"/);
  assert.match(meituanTemplate, /v-model="meituanConfigForm\.hotel_id"/);
  assert.equal((meituanTemplate.match(/v-model="onlineDataFilter\.hotel_id"/g) || []).length, 1);
  assert.match(meituanTemplate, /data-testid="meituan-stored-hotel-query-filter"/);
  assert.match(meituanTemplate, /历史查询酒店（不改变顶部当前酒店）/);
  assert.match(meituanTemplate, /data-testid="meituan-traffic-current-hotel"/);
  assert.doesNotMatch(meituanTemplate, /@change="loadMtSummary\(\{ force: true \}\)"/);
  assert.match(meituanTemplate, /:disabled="mtRefreshing \|\| !meituanForm\.hotelId"/);
  assert.match(meituanTemplate, /:disabled="!meituanForm\.hotelId"/);
});

test('Ctrip review evidence always resolves the shared Ctrip hotel and never an old form or account fallback', () => {
  const resolverSource = sliceFrom(
    appMain,
    'const resolveCtripReviewMatchSystemHotelId = () =>',
    '\n            const parseCtripReviewMatchJsonValue',
  );
  const basePayloadSource = sliceFrom(
    appMain,
    'const buildCtripReviewMatchBasePayload = () => {',
    '\n            const buildCtripReviewMatchReviewPayload',
  );
  const sandbox = {
    selectedCtripHotelId: { value: '80' },
    ctripReviewMatchForm: { value: { systemHotelId: '64' } },
    user: { value: { hotel_id: '42' } },
  };
  const api = vm.runInNewContext(`(() => {
    ${resolverSource}
    ${basePayloadSource}
    return { resolveCtripReviewMatchSystemHotelId, buildCtripReviewMatchBasePayload };
  })()`, sandbox, { filename: 'ctrip-review-shared-hotel-context.js' });

  assert.equal(api.resolveCtripReviewMatchSystemHotelId(), '80');
  assert.deepEqual(
    JSON.parse(JSON.stringify(api.buildCtripReviewMatchBasePayload())),
    { system_hotel_id: '80' },
  );
  sandbox.selectedCtripHotelId.value = '';
  assert.equal(api.resolveCtripReviewMatchSystemHotelId(), '');
  assert.throws(
    () => api.buildCtripReviewMatchBasePayload(),
    /请先在顶部选择携程当前酒店/,
  );

  const actionSource = sliceFrom(
    appMain,
    'const runCtripReviewMatchAction = async',
    '\n            const mergeCtripReviewMatchLookupResult',
  );
  const lookupSource = sliceFrom(
    appMain,
    'const lookupCtripReviewOrderMatch = async',
    '\n            const runCtripReviewMatchPreflight',
  );
  assert.match(actionSource, /capturePlatformHotelRequestContext\('ctrip'\)/);
  assert.match(actionSource, /isPlatformHotelRequestContextCurrent\(requestContext\)/);
  assert.match(lookupSource, /capturePlatformHotelRequestContext\('ctrip'\)/);
  assert.match(lookupSource, /isPlatformHotelRequestContextCurrent\(requestContext\)/);
});

test('global report context excludes platform workbench selections', () => {
  const bindings = sliceFrom(
    appMain,
    'const unifiedHotelContextBindings = [',
    '\n            let unifiedHotelContextSyncing = false;',
  );
  assert.doesNotMatch(bindings, /key: 'auto-fetch'/);
  assert.doesNotMatch(bindings, /key: 'ctrip-workbench'/);
  assert.doesNotMatch(bindings, /key: 'meituan-workbench'/);
  assert.doesNotMatch(bindings, /selectedCtripHotelId/);
  assert.doesNotMatch(bindings, /meituanForm\.value\.hotelId/);
});

test('platform context persistence is account-scoped and validated by platform options', () => {
  assert.match(appMain, /const platformHotelContextStorageKey = \(platform\) =>/);
  assert.match(appMain, /user\.value\?\.id/);
  assert.match(appMain, /const persistPlatformHotelContext = \(platform, hotelId\) =>/);
  assert.match(appMain, /const alignCtripTargetHotelToConfiguredContext = \(\) =>/);
  assert.match(appMain, /ctripTargetHotelOptions\.value/);
  assert.match(appMain, /meituanTargetHotelOptions\.value/);
  assert.match(appMain, /watch\(selectedCtripHotelId/);
  assert.match(appMain, /watch\(\(\) => meituanForm\.value\.hotelId/);
});

test('platform context reconciliation invalidates removed configs without retaining stale hotel ids', () => {
  const contextSource = sliceFrom(
    appMain,
    'const platformHotelContextStorageKey = (platform) =>',
    '\n            const platformHotelContext = computed',
  );
  const storage = new Map([
    ['phc_42_ctrip', '64'],
    ['phc_42_meituan', '7'],
  ]);
  const persistenceSandbox = {
    user: { value: { id: 42, hotel_id: '64' } },
    onlineDataTab: { value: 'ctrip-traffic' },
    ctripTargetHotelOptions: { value: [{ id: '64', name: 'Ctrip configured' }] },
    ctripPublicProfileHotelOptions: {
      value: [
        { id: '64', name: 'Ctrip configured' },
        { id: '91', name: 'Ctrip public profile only' },
      ],
    },
    meituanTargetHotelOptions: { value: [{ id: '7', name: 'Meituan configured' }] },
    localStorage: {
      getItem: key => storage.get(key) || null,
      setItem: (key, value) => storage.set(key, String(value)),
      removeItem: key => storage.delete(key),
    },
  };
  const persistenceApi = vm.runInNewContext(`(() => {
    ${contextSource}
    return { persistPlatformHotelContext };
  })()`, persistenceSandbox, { filename: 'platform-hotel-context-persistence-slice.js' });
  persistenceApi.persistPlatformHotelContext('meituan', 'not-configured');
  assert.equal(storage.has('phc_42_meituan'), false);
  persistenceApi.persistPlatformHotelContext('ctrip', '91');
  assert.equal(storage.has('phc_42_ctrip'), false);
  persistenceSandbox.onlineDataTab.value = 'ctrip-public-profiles';
  persistenceApi.persistPlatformHotelContext('ctrip', '91');
  assert.equal(storage.get('phc_42_ctrip'), '91');
  persistenceSandbox.onlineDataTab.value = 'ctrip-traffic';

  const alignSource = sliceFrom(
    appMain,
    'const alignCtripTargetHotelToConfiguredContext = () => {',
    '\n\n            const shouldShowCtripRankingManualAuxiliary',
  );
  const ctripSandbox = {
    selectedCtripHotelId: { value: 'removed-ctrip' },
    ctripTargetHotelManuallySelected: { value: true },
    nextHotelId: '64',
    clearCount: 0,
    getCtripOverviewTargetHotelId: () => ctripSandbox.nextHotelId,
    clearCtripOverviewDisplayState: () => { ctripSandbox.clearCount += 1; },
    persistPlatformHotelContext: (platform, hotelId) => persistenceApi.persistPlatformHotelContext(platform, hotelId),
  };
  const alignCtrip = vm.runInNewContext(`(() => {
    ${alignSource}
    return alignCtripTargetHotelToConfiguredContext;
  })()`, ctripSandbox, { filename: 'ctrip-platform-context-align-slice.js' });
  alignCtrip();
  assert.equal(ctripSandbox.selectedCtripHotelId.value, '64');
  assert.equal(ctripSandbox.clearCount, 1);
  assert.equal(storage.get('phc_42_ctrip'), '64');

  persistenceSandbox.ctripTargetHotelOptions.value = [];
  ctripSandbox.nextHotelId = '';
  alignCtrip();
  assert.equal(ctripSandbox.selectedCtripHotelId.value, '');
  assert.equal(storage.has('phc_42_ctrip'), false);

  const ensureMeituanSource = sliceFrom(
    appMain,
    'const ensureMeituanManualHotelSelected = () => {',
    '\n            const scheduleMeituanEbookingDeferredStartupRefresh',
  );
  const meituanSandbox = {
    meituanConfigListLoaded: { value: true },
    meituanConfigListLoadFailed: { value: false },
    meituanForm: { value: { hotelId: 'removed-meituan' } },
    resolveMeituanManualDefaultHotelId: () => '',
    suppressNextMeituanHotelConfigApply: false,
  };
  const ensureMeituan = vm.runInNewContext(`(() => {
    ${ensureMeituanSource}
    return ensureMeituanManualHotelSelected;
  })()`, meituanSandbox, { filename: 'meituan-platform-context-align-slice.js' });
  ensureMeituan();
  assert.equal(meituanSandbox.meituanForm.value.hotelId, '');

  const meituanWatcherSource = sliceFrom(
    appMain,
    'watch(() => meituanForm.value.hotelId, () => {',
    '\n\n            watch(competitorTab',
  );
  assert.ok(
    meituanWatcherSource.indexOf('clearMeituanPlatformHotelScopedState();')
      < meituanWatcherSource.indexOf('if (suppressNextMeituanHotelConfigApply)'),
    'hotel-scoped results must be cleared before the config-apply suppression return',
  );

  const clearMeituanSource = sliceFrom(
    appMain,
    'const clearMeituanPlatformHotelScopedState = () => {',
    '\n\n            const selectMeituanRankingDateRange',
  );
  assert.match(clearMeituanSource, /invalidatePlatformHotelRequestContext\('meituan'\)/);
  assert.match(clearMeituanSource, /meituanCommentResult\.value = null/);
  assert.match(clearMeituanSource, /meituanOrderResult\.value = null/);
  assert.match(clearMeituanSource, /meituanAdsResult\.value = null/);
  assert.match(clearMeituanSource, /meituanBrowserCaptureResult\.value = null/);
  assert.match(clearMeituanSource, /meituanTemporalSummary\.value = null/);
  assert.match(clearMeituanSource, /meituanOrderFlowRows\.value = \[\]/);

  const clearCtripSource = sliceFrom(
    appMain,
    'const clearCtripOverviewDisplayState = () => {',
    '\n\n            const getCtripOverviewTargetHotelId',
  );
  assert.match(clearCtripSource, /invalidatePlatformHotelRequestContext\('ctrip'\)/);
  assert.match(clearCtripSource, /ctripCommentBrowserCaptureResult\.value = null/);
  assert.match(clearCtripSource, /ctripBrowserCaptureResult\.value = null/);
  assert.match(clearCtripSource, /ctripReviewMatchResult\.value = null/);
  assert.match(clearCtripSource, /ctripSearchOpportunityPayload\.value = null/);
  assert.match(clearCtripSource, /ctripRealtimeTrafficRecord\.value = null/);
});

test('Ctrip header traffic switch dispatches through scoped reset path', () => {
  const handlerSource = sliceFrom(
    appMain,
    'const handlePlatformHotelContextChange = (event) => {',
    '\n            const openPlatformHotelContextConfig',
  );
  const scopedResetSource = sliceFrom(
    appMain,
    'const handleCtripTrafficHotelChange = (event) => {',
    '\n\n            const runCtripOverviewCoreFetchAction',
  );
  const sandbox = {
    platformHotelContext: { value: 'ctrip' },
    onlineDataTab: { value: 'ctrip-traffic' },
    selectedCtripHotelId: { value: '64' },
    ctripTargetHotelManuallySelected: { value: false },
    meituanForm: { value: { hotelId: '' } },
    suppressNextMeituanHotelConfigApply: false,
    trafficDispatches: 0,
    openDispatches: 0,
    clearPlatformHotelSearch: () => {},
    handleCtripTrafficHotelChange: () => { sandbox.trafficDispatches += 1; },
    clearCtripOverviewDisplayState: () => {},
    switchToMeituanDownloadCenter: () => {},
    openMeituanManualTab: () => {},
    switchToDownloadCenter: () => {},
    openCtripManualTab: () => { sandbox.openDispatches += 1; },
  };
  const handle = vm.runInNewContext(`(() => {
    ${handlerSource}
    return handlePlatformHotelContextChange;
  })()`, sandbox, { filename: 'platform-hotel-header-handler.js' });
  handle({ target: { value: '80' } });

  assert.equal(sandbox.selectedCtripHotelId.value, '80');
  assert.equal(sandbox.trafficDispatches, 1);
  assert.equal(sandbox.openDispatches, 0);

  const resetSandbox = {
    selectedCtripHotelId: { value: '80' },
    ctripForm: { value: { cookies: 'old-cookie' } },
    ctripSearchOpportunityRequestSeq: 4,
    ctripSearchOpportunityPayload: { value: { hotelId: '64' } },
    ctripSearchOpportunityError: { value: 'old-error' },
    ctripSearchOpportunityLoading: { value: true },
    ctripTrafficRows: { value: [{ hotelId: '64' }] },
    ctripTrafficSummary: { value: { hotelId: '64' } },
    ctripTrafficAnalysis: { value: { hotelId: '64' } },
    ctripTrafficHistoryResult: { value: { hotelId: '64' } },
    ctripRealtimeTrafficRecord: { value: { hotelId: '64' } },
    latestTrafficData: { value: [{ hotelId: '64' }] },
    onlineDataResult: { value: { hotelId: '64' } },
    onlineDataTab: { value: 'ctrip-traffic' },
    scheduleCtripHotelConfigApply: () => {},
    deferUiTask: () => {},
    loadCtripSearchOpportunity: () => {},
  };
  const resetTraffic = vm.runInNewContext(`(() => {
    ${scopedResetSource}
    return handleCtripTrafficHotelChange;
  })()`, resetSandbox, { filename: 'ctrip-traffic-context-reset.js' });
  resetTraffic({ target: { value: '80' } });
  assert.equal(resetSandbox.ctripSearchOpportunityRequestSeq, 5);
  assert.equal(resetSandbox.ctripSearchOpportunityPayload.value, null);
  assert.equal(Array.isArray(resetSandbox.ctripTrafficRows.value), true);
  assert.equal(resetSandbox.ctripTrafficRows.value.length, 0);
  assert.equal(resetSandbox.ctripTrafficSummary.value, null);
  assert.equal(resetSandbox.ctripTrafficAnalysis.value, null);
  assert.equal(resetSandbox.ctripTrafficHistoryResult.value, null);
  assert.equal(resetSandbox.ctripRealtimeTrafficRecord.value, null);
  assert.equal(Array.isArray(resetSandbox.latestTrafficData.value), true);
  assert.equal(resetSandbox.latestTrafficData.value.length, 0);
  assert.equal(resetSandbox.onlineDataResult.value, null);
});

test('Ctrip traffic ignores an old hotel response after the shared context switches', async () => {
  const sandbox = { window: {} };
  vm.runInNewContext(ctripStaticSource, sandbox, { filename: 'public/ctrip-static.js' });
  const flow = sandbox.window.SUXI_CTRIP_STATIC.runCtripTrafficFetchFlow;
  let currentHotelId = '64';
  let resolveRequest;
  let requestStarted;
  const requestStartedPromise = new Promise(resolve => { requestStarted = resolve; });
  const writes = [];
  const refreshes = [];
  const fetchingStates = [];
  const pending = flow({
    getSelectedCtripHotelId: () => currentHotelId,
    notify: (...args) => writes.push(['notify', ...args]),
    getActiveCtripConfig: () => ({ id: 'config-64', has_cookies: true, credential_status: 'ready' }),
    getForm: () => ({ dateRange: 'last_30_days' }),
    setFetching: value => fetchingStates.push(value),
    requestFetch: () => {
      requestStarted();
      return new Promise(resolve => { resolveRequest = resolve; });
    },
    useCtripTrafficDisplayRows: (...args) => {
      writes.push(['rows', ...args]);
      return args[0];
    },
    setOnlineDataResult: value => writes.push(['result', value]),
    refreshOnlineHistory: () => refreshes.push('history'),
    getOnlineDataTab: () => 'data',
    refreshOnlineData: () => refreshes.push('data'),
    handleFetchFailure: message => writes.push(['failure', message]),
  });

  await requestStartedPromise;
  currentHotelId = '80';
  resolveRequest({
    code: 200,
    data: {
      saved_count: 1,
      display_traffic_rows: [{ hotel_id: '64' }],
      traffic_rows: [{ hotel_id: '64' }],
    },
  });
  const result = await pending;

  assert.equal(result.status, 'stale');
  assert.deepEqual(writes, []);
  assert.deepEqual(refreshes, []);
  assert.deepEqual(fetchingStates, [true, false]);

  const bundleSource = sliceFrom(
    appMain,
    'const fetchCtripTrafficAndSearchData = async () => {',
    '\n\n            const handleCtripTrafficHotelChange',
  );
  assert.match(bundleSource, /if \(historyResult\?\.status === 'stale'\) return historyResult;/);
});

test('Ctrip comment browser and diagnosis responses cannot overwrite a newly selected hotel', async () => {
  const contextSource = platformRequestContextSource();
  const browserSource = sliceFrom(
    appMain,
    'const runCtripCommentBrowserCapture = async () => {',
    '\n\n            const fetchCtripOverviewData',
  );
  const browserRequest = deferred();
  const browserWrites = [];
  const browserSandbox = {
    selectedCtripHotelId: { value: '64' },
    meituanForm: { value: { hotelId: '7' } },
    ctripCommentBrowserCaptureRunning: { value: false },
    ctripCommentBrowserCaptureForm: { value: { profileId: 'profile-64', pageUrl: '', apiKeyword: '' } },
    ctripCommentForm: { value: { profileId: '', hotelId: 'ctrip-64' } },
    ctripCommentBrowserCaptureResult: { value: null },
    ctripCommentResult: { value: null },
    onlineDataResult: { value: null },
    showRawData: { value: false },
    firstDataConfigValue: (...values) => values.find(value => String(value || '').trim()) || '',
    request: () => browserRequest.promise,
    scheduleOnlineDataRefresh: () => browserWrites.push('data'),
    scheduleOnlineHistoryRefresh: () => browserWrites.push('history'),
    scheduleLatestCtripRefresh: () => browserWrites.push('latest'),
    showToast: (...args) => browserWrites.push(['toast', ...args]),
  };
  const browserApi = vm.runInNewContext(`(() => {
    ${contextSource}
    ${browserSource}
    return { runCtripCommentBrowserCapture, invalidatePlatformHotelRequestContext };
  })()`, browserSandbox, { filename: 'ctrip-comment-browser-context.js' });
  const browserPending = browserApi.runCtripCommentBrowserCapture();
  browserSandbox.selectedCtripHotelId.value = '80';
  browserApi.invalidatePlatformHotelRequestContext('ctrip');
  browserRequest.resolve({ code: 200, data: { system_hotel_id: 64 }, message: 'old hotel response' });
  const browserResult = await browserPending;
  assert.equal(browserResult.status, 'stale');
  assert.equal(browserSandbox.ctripCommentBrowserCaptureResult.value, null);
  assert.equal(browserSandbox.ctripCommentResult.value, null);
  assert.equal(browserSandbox.onlineDataResult.value, null);
  assert.deepEqual(browserWrites, []);

  const diagnosisSource = sliceFrom(
    appMain,
    'const loadCtripDiagnosisSnapshot = async () => {',
    '\n\n            const getCtripCookieApiCorePresetEndpoints',
  );
  const diagnosisRequest = deferred();
  const diagnosisWrites = [];
  const diagnosisSandbox = {
    URLSearchParams,
    selectedCtripHotelId: { value: '64' },
    meituanForm: { value: { hotelId: '7' } },
    ctripDiagnosisSnapshotLoading: { value: false },
    ctripBrowserCaptureForm: { value: { profileId: 'profile-64' } },
    ctripBrowserCaptureResult: { value: null },
    onlineDataResult: { value: null },
    showRawData: { value: false },
    request: () => diagnosisRequest.promise,
    showToast: (...args) => diagnosisWrites.push(args),
  };
  const diagnosisApi = vm.runInNewContext(`(() => {
    ${contextSource}
    ${diagnosisSource}
    return { loadCtripDiagnosisSnapshot, invalidatePlatformHotelRequestContext };
  })()`, diagnosisSandbox, { filename: 'ctrip-diagnosis-context.js' });
  const diagnosisPending = diagnosisApi.loadCtripDiagnosisSnapshot();
  diagnosisSandbox.selectedCtripHotelId.value = '80';
  diagnosisApi.invalidatePlatformHotelRequestContext('ctrip');
  diagnosisRequest.resolve({ code: 200, data: { counts: { standard_rows: 3 } }, message: 'old diagnosis' });
  const diagnosisResult = await diagnosisPending;
  assert.equal(diagnosisResult.status, 'stale');
  assert.equal(diagnosisSandbox.ctripBrowserCaptureResult.value, null);
  assert.equal(diagnosisSandbox.onlineDataResult.value, null);
  assert.deepEqual(diagnosisWrites, []);
});

test('Meituan comment response cannot overwrite a newly selected Meituan hotel', async () => {
  const contextSource = platformRequestContextSource();
  const commentSource = sliceFrom(
    appMain,
    'const fetchMeituanComments = async () => {',
    '\n\n            // 获取评分样式类',
  );
  const requestState = deferred();
  const writes = [];
  const sandbox = {
    selectedCtripHotelId: { value: '64' },
    meituanForm: { value: { hotelId: '7' } },
    meituanCommentForm: { value: { partnerId: 'p7', poiId: 'poi7', requestUrl: '/reviews', replyType: '2', tag: '', limit: 50 } },
    meituanBrowserCaptureForm: { value: { storeId: 'poi7' } },
    fetchingCommentData: { value: false },
    meituanCommentSuccess: { value: false },
    meituanCommentResult: { value: null },
    onlineDataResult: { value: null },
    showRawData: { value: false },
    firstDataConfigValue: (...values) => values.find(value => String(value || '').trim()) || '',
    request: () => requestState.promise,
    scheduleOnlineDataRefresh: () => writes.push('data'),
    scheduleOnlineHistoryRefresh: () => writes.push('history'),
    showToast: (...args) => writes.push(['toast', ...args]),
  };
  const api = vm.runInNewContext(`(() => {
    ${contextSource}
    ${commentSource}
    return { fetchMeituanComments, invalidatePlatformHotelRequestContext };
  })()`, sandbox, { filename: 'meituan-comments-context.js' });
  const pending = api.fetchMeituanComments();
  sandbox.meituanForm.value.hotelId = '9';
  api.invalidatePlatformHotelRequestContext('meituan');
  requestState.resolve({ code: 200, data: { system_hotel_id: 7 }, message: 'old hotel response' });
  const result = await pending;
  assert.equal(result.status, 'stale');
  assert.equal(sandbox.meituanCommentSuccess.value, false);
  assert.equal(sandbox.meituanCommentResult.value, null);
  assert.equal(sandbox.onlineDataResult.value, null);
  assert.deepEqual(writes, []);
});

test('delayed Meituan saved-config reads cannot apply an old hotel config to a new hotel form', async () => {
  const specs = [
    {
      name: 'syncMeituanCommentConfigFromSelectedConfig',
      start: 'const syncMeituanCommentConfigFromSelectedConfig = async',
      end: '\n\n            // 保存美团配置',
      formRef: 'meituanCommentForm',
      form: { partnerId: 'new-partner', poiId: 'new-poi', requestUrl: '/new', replyType: '2', tag: '', limit: 50 },
    },
    {
      name: 'syncMeituanTrafficConfigFromSelectedConfig',
      start: 'const syncMeituanTrafficConfigFromSelectedConfig = async',
      end: '\n\n            const syncMeituanOrderConfigFromSelectedConfig',
      formRef: 'meituanTrafficForm',
      form: { url: '/new-traffic', partnerId: 'new-partner', poiId: 'new-poi', cookies: '' },
    },
    {
      name: 'syncMeituanOrderConfigFromSelectedConfig',
      start: 'const syncMeituanOrderConfigFromSelectedConfig = async',
      end: '\n\n            const syncMeituanAdsConfigFromSelectedConfig',
      formRef: 'meituanOrderForm',
      form: { url: '/new-orders', method: 'GET', partnerId: 'new-partner', poiId: 'new-poi', cookies: '' },
    },
    {
      name: 'syncMeituanAdsConfigFromSelectedConfig',
      start: 'const syncMeituanAdsConfigFromSelectedConfig = async',
      end: '\n\n            const syncMeituanBrowserCaptureFromSelectedConfig',
      formRef: 'meituanAdsForm',
      form: { url: '/new-ads', method: 'GET', partnerId: 'new-partner', poiId: 'new-poi', shopId: 'new-shop', cookies: '' },
    },
  ];

  for (const spec of specs) {
    const configRead = deferred();
    const initialForm = JSON.parse(JSON.stringify(spec.form));
    const sandbox = {
      selectedCtripHotelId: { value: '64' },
      meituanForm: { value: { hotelId: '7' } },
      [spec.formRef]: { value: JSON.parse(JSON.stringify(initialForm)) },
      readSavedOtaDataConfig: () => configRead.promise,
    };
    const api = vm.runInNewContext(`(() => {
      ${platformRequestContextSource()}
      ${sliceFrom(appMain, spec.start, spec.end)}
      return { fn: ${spec.name}, invalidatePlatformHotelRequestContext };
    })()`, sandbox, { filename: `${spec.name}-stale-config.js` });
    const pending = api.fn();
    sandbox.meituanForm.value.hotelId = '9';
    api.invalidatePlatformHotelRequestContext('meituan');
    configRead.resolve({
      system_hotel_id: '7',
      partner_id: 'old-partner',
      poi_id: 'old-poi',
      shop_id: 'old-shop',
      url: '/old-hotel',
    });
    const result = await pending;
    assert.equal(result.status, 'stale', spec.name);
    assert.deepEqual(sandbox[spec.formRef].value, initialForm, spec.name);
  }
});

test('Meituan tab setup stops when the shared Meituan hotel changes during async sync', async () => {
  const sandbox = { window: {} };
  vm.runInNewContext(meituanStaticSource, sandbox, { filename: 'public/meituan-static.js' });
  const flow = sandbox.window.SUXI_MEITUAN_STATIC.runMeituanManualTabSwitch;
  const syncState = deferred();
  const syncStarted = deferred();
  let currentHotelId = '7';
  const pending = flow({
    tab: 'meituan-traffic',
    getCurrentPage: () => 'meituan-ebooking',
    getCurrentTab: () => 'meituan-traffic',
    getCurrentHotelId: () => currentHotelId,
    loadConfigList: async () => {},
    syncTrafficConfig: () => {
      syncStarted.resolve();
      return syncState.promise;
    },
  });
  await syncStarted.promise;
  currentHotelId = '9';
  syncState.resolve({ status: 'synced' });
  const result = await pending;
  assert.equal(result.status, 'stale_after_sync');
  assert.equal(result.hotelId, '7');
});

test('Meituan default hotel cannot fall back to Ctrip, auto-fetch, or a global report hotel', () => {
  const sandbox = { window: {} };
  vm.runInNewContext(meituanStaticSource, sandbox, { filename: 'public/meituan-static.js' });
  const resolver = sandbox.window.SUXI_MEITUAN_STATIC.resolveMeituanManualDefaultHotelIdFromState;
  assert.equal(typeof resolver, 'function');

  assert.equal(resolver({
    currentHotelId: 'ctrip-only',
    storedHotelId: 'mt-7',
    userHotelId: 'user-hotel',
    autoFetchHotelId: 'auto-hotel',
    selectedCtripHotelId: 'ctrip-hotel',
    onlineDataHotelId: 'report-hotel',
    hotelPool: [{ id: 'mt-7' }],
  }), 'mt-7');
  assert.equal(resolver({
    currentHotelId: '',
    storedHotelId: '',
    userHotelId: 'user-hotel',
    autoFetchHotelId: 'auto-hotel',
    selectedCtripHotelId: 'ctrip-hotel',
    hotelPool: [],
  }), '');

  const captureResolver = sandbox.window.SUXI_MEITUAN_STATIC.resolveMeituanBrowserCaptureSystemHotelId;
  assert.equal(captureResolver({
    formHotelId: '',
    autoFetchHotelId: 'auto-hotel',
    userHotelId: 'user-hotel',
  }), null);
  assert.equal(captureResolver({
    formHotelId: 'mt-7',
    autoFetchHotelId: 'auto-hotel',
    userHotelId: 'user-hotel',
  }), 'mt-7');
  const supplementState = sandbox.window.SUXI_MEITUAN_STATIC.buildMeituanBrowserSupplementCaptureState({
    formHotelId: '',
    autoFetchHotelId: 'auto-hotel',
    userHotelId: 'user-hotel',
  });
  assert.equal(supplementState.ok, false);
  assert.equal(supplementState.hotelId, '');

  const appResolver = sliceFrom(
    appMain,
    'const resolveMeituanManualDefaultHotelId = () => {',
    '\n            const ensureMeituanManualHotelSelected',
  );
  assert.match(appResolver, /storedHotelId:/);
  assert.match(appResolver, /meituanTargetHotelOptions\.value/);
  assert.doesNotMatch(appResolver, /autoFetchHotelId/);
  assert.doesNotMatch(appResolver, /selectedCtripHotelId/);
  assert.doesNotMatch(appResolver, /onlineDataFilter/);
  assert.doesNotMatch(appResolver, /filterReportHotel/);
});

test('Ctrip overview and home dual-OTA fetch no longer overwrite platform contexts', () => {
  const overviewResolver = sliceFrom(
    appMain,
    'const getCtripOverviewTargetHotelId = () => {',
    '\n\n            const syncCtripOverviewTargetHotel',
  );
  assert.match(overviewResolver, /selectedCtripHotelId\.value/);
  assert.doesNotMatch(overviewResolver, /autoFetchHotelId\.value/);

  const dualOtaSync = sliceFrom(
    appMain,
    'const dualOtaSyncFetchContext = (hotelId = \'\') => {',
    '\n            const dualOtaStoreScopeIncludesPlatform',
  );
  assert.doesNotMatch(dualOtaSync, /selectedCtripHotelId/);
  assert.doesNotMatch(dualOtaSync, /meituanForm\.value\.hotelId/);
});

test('stored-data navigation explicitly keeps the selected platform hotel', () => {
  const ctripDownload = sliceFrom(
    appMain,
    'const switchToDownloadCenter = () => {',
    '\n\n            // 切换到美团下载中心',
  );
  assert.match(ctripDownload, /onlineDataFilter\.value\.hotel_id = String\(selectedCtripHotelId\.value/);

  const meituanTemporal = sliceFrom(
    appMain,
    'const resolveMeituanTemporalHotelId = () => String(',
    '\n            const ensureMeituanTemporalHotelId',
  );
  assert.match(meituanTemporal, /meituanForm\.value\.hotelId/);
  assert.doesNotMatch(meituanTemporal, /selectedCtripHotelId/);
  assert.doesNotMatch(meituanTemporal, /autoFetchHotelId/);
});

test('platform actions do not repopulate a project hotel from the global context', () => {
  const meituanProfileFill = sliceFrom(
    appMain,
    'const fillPlatformProfileForms = (item) => {',
    '\n\n            const loginPlatformProfile',
  );
  assert.doesNotMatch(meituanProfileFill, /getAutoFetchHotelId/);
  assert.match(meituanProfileFill, /getHotelNameById\(meituanForm\.value\.hotelId\)/);

  const ctripProfileCheck = sliceFrom(
    appMain,
    'const checkCtripProfileStatus = async () => {',
    '\n\n            const runCtripCookieApiCapture',
  );
  assert.doesNotMatch(ctripProfileCheck, /autoFetchHotelId/);
  assert.doesNotMatch(ctripProfileCheck, /user\.value\?\.hotel_id/);

  const ctripBrowserCapture = sliceFrom(
    appMain,
    'const runCtripBrowserCapture = async (options = {}) =>',
    '\n\n            const loadCtripDiagnosisSnapshot',
  );
  assert.doesNotMatch(ctripBrowserCapture, /getAutoFetchHotelId:/);
  assert.doesNotMatch(ctripBrowserCapture, /getUserHotelId:/);

  const ctripCookieCapture = sliceFrom(
    appMain,
    'const runCtripCookieApiCapture = async (options = {}) =>',
    '\n\n            const validateCtripEndpointEvidence',
  );
  assert.doesNotMatch(ctripCookieCapture, /getAutoFetchHotelId:/);
  assert.doesNotMatch(ctripCookieCapture, /getUserHotelId:/);

  const ctripConfigCreate = sliceFrom(
    appMain,
    'const openCtripCookieCreateFromHealth = () => {',
    '\n\n            const closeCtripCookieEditor',
  );
  assert.match(ctripConfigCreate, /hotel_id: selectedCtripHotelId\.value \|\| ''/);
  assert.doesNotMatch(ctripConfigCreate, /autoFetchHotelId/);

  const publicProfileSelection = sliceFrom(
    appMain,
    'const ensureCtripPublicProfileHotelSelected = async () => {',
    '\n            const loadCtripPublicProfiles',
  );
  assert.match(publicProfileSelection, /if \(!ctripConfigListLoaded\.value\)/);
  assert.match(publicProfileSelection, /await loadCtripConfigList\(/);
  assert.match(publicProfileSelection, /const options = ctripPublicProfileHotelOptions\.value/);
  assert.doesNotMatch(publicProfileSelection, /const options = ctripTargetHotelOptions\.value/);

  const meituanStartup = sliceFrom(
    appMain,
    'const scheduleMeituanEbookingDeferredStartupRefresh = () => {',
    '\n            const openCtripManualTab',
  );
  assert.match(meituanStartup, /await loadMeituanConfigList\([\s\S]*?ensureMeituanManualHotelSelected\(\)/);
  assert.ok(
    meituanStartup.indexOf('ensureMeituanManualHotelSelected();')
      > meituanStartup.indexOf('await loadMeituanConfigList('),
    'Meituan selection must be reconciled only after the configured-hotel list is available',
  );

  const meituanBrowserCapture = sliceFrom(
    appMain,
    'const runMeituanBrowserCapture = async (options = {}) =>',
    '\n\n            const loadMeituanOrderFlowData',
  );
  assert.match(meituanBrowserCapture, /formHotelId: meituanForm\.value\.hotelId/);
  assert.doesNotMatch(meituanBrowserCapture, /autoFetchHotelId/);
  assert.doesNotMatch(meituanBrowserCapture, /user\.value\?\.hotel_id/);

  const meituanSupplement = sliceFrom(
    appMain,
    'const runMeituanBrowserSupplementCapture = async () => {',
    '\n\n            const copyMeituanBrowserCaptureCommand',
  );
  assert.match(meituanSupplement, /formHotelId: meituanForm\.value\.hotelId/);
  assert.doesNotMatch(meituanSupplement, /autoFetchHotelId/);
  assert.doesNotMatch(meituanSupplement, /user\.value\?\.hotel_id/);
});

test('an unconfigured main hotel never falls through to an arbitrary platform hotel', () => {
  const sandbox = { window: {} };
  vm.runInNewContext(meituanStaticSource, sandbox, { filename: 'public/meituan-static.js' });
  const resolveMeituanHotel = sandbox.window.SUXI_MEITUAN_STATIC.resolveMeituanManualDefaultHotelIdFromState;
  assert.equal(resolveMeituanHotel({
    currentHotelId: '',
    storedHotelId: '',
    userHotelId: 'main-without-meituan',
    hotelPool: [{ id: 'meituan-only-store' }],
  }), '');

  const overviewResolver = sliceFrom(
    appMain,
    'const getCtripOverviewTargetHotelId = () => {',
    '\n\n            const syncCtripOverviewTargetHotel',
  );
  assert.match(overviewResolver, /return '';/);
  assert.doesNotMatch(overviewResolver, /ctripTargetHotelOptions\.value\?\.\[0\]/);

  const publicProfileSelection = sliceFrom(
    appMain,
    'const ensureCtripPublicProfileHotelSelected = async () => {',
    '\n            const loadCtripPublicProfiles',
  );
  assert.doesNotMatch(publicProfileSelection, /\|\| options\[0\]/);
});

test('general navigation inherits a hotel only after platform configuration validation', () => {
  const inheritance = sliceFrom(
    appMain,
    'const applyGeneralHotelToPlatformContext = async',
    '\n            const openDualOtaModule',
  );
  assert.match(inheritance, /await ensureHotelOtaConfigLists\(\{ includeDataSources: false \}\)/);
  assert.match(inheritance, /meituanTargetHotelOptions\.value/);
  assert.match(inheritance, /ctripTargetHotelOptions\.value/);
  assert.match(inheritance, /\.some\(hotel => String\(hotel\?\.id/);
  assert.match(inheritance, /已保留\$\{platformName\}当前门店/);

  const moduleNavigation = sliceFrom(
    appMain,
    'const openDualOtaModule = async',
    '\n            const dualOtaSystemMetricPlatform',
  );
  assert.match(moduleNavigation, /await applyGeneralHotelToPlatformContext\('ctrip', hotelId\)/);
  assert.match(moduleNavigation, /await applyGeneralHotelToPlatformContext\('meituan', hotelId\)/);
  assert.doesNotMatch(moduleNavigation, /selectedCtripHotelId\.value = hotelId/);
  assert.doesNotMatch(moduleNavigation, /meituanForm\.value\.hotelId = hotelId/);

  const metricNavigation = sliceFrom(
    appMain,
    'const openDualOtaSystemMetric = async',
    '\n            let dualOtaSystemMetricDrilldownHydrationTimer',
  );
  assert.match(metricNavigation, /await applyGeneralHotelToPlatformContext\('ctrip', hotelId\)/);
  assert.match(metricNavigation, /await applyGeneralHotelToPlatformContext\('meituan', hotelId\)/);
});

test('Meituan stored-data filter is not a general current-hotel binding', () => {
  const bindings = sliceFrom(
    appMain,
    'const unifiedHotelContextBindings = [',
    '\n            let unifiedHotelContextSyncing = false;',
  );
  assert.match(bindings, /key: 'online-data',[\s\S]*?pages: \['online-data'\]/);
  assert.doesNotMatch(bindings, /pages: \['online-data', 'meituan-ebooking'\]/);
});

test('Ctrip field recheck readiness depends only on the selected Ctrip hotel', () => {
  const recheckAction = sliceFrom(
    appMain,
    'const recheckCtripProfileMismatchedFields = async () => {',
    '\n            const editCtripProfileField',
  );
  assert.match(recheckAction, /selectedCtripHotelId: selectedCtripHotelId\.value/);
  assert.doesNotMatch(recheckAction, /autoFetchHotelId:/);
  assert.doesNotMatch(recheckAction, /userHotelId:/);

  const helper = sliceFrom(
    ctripStaticSource,
    'const buildCtripProfileRecheckRunContext = ({',
    '\n\n    const buildCtripProfileRecheckCaptureRefreshState',
  );
  assert.match(helper, /canRecapture = String\(selectedCtripHotelId \|\| ''\)\.trim\(\) !== ''/);
  assert.doesNotMatch(helper, /autoFetchHotelId/);
  assert.doesNotMatch(helper, /userHotelId/);
});
