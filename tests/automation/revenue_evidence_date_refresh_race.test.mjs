import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const source = readFileSync(process.env.SUXIOS_REVENUE_EVIDENCE_RACE_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const statics = ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']
  .map(name => readFileSync(new URL(`../../public/${name}`, import.meta.url), 'utf8')).join('\n');
const part = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `actual production function: ${start}`);
  return source.slice(a, b);
};
const production = [
  part('const postFetchRefreshTimers =', 'const normalizeCanonicalPage ='),
  part('const resetCtripCompetitiveOperations =', 'const ctripCompetitiveOperationsCoverage ='),
  part('const scheduleDataHealthPanelRefresh =', 'const PLATFORM_PROFILE_STATUS_PANEL_CACHE_TTL_MS ='),
  part('const loadDailyWorkbench =', 'const generateCoreOperationsDiagnoses ='),
  part('const resetCoreOperationsScopedState =', 'const loadPhase3OperationEffectLoop ='),
  part('const loadPhase3OperationEffectLoop =', 'const loadPhase3OperationEffectLoopLedger ='),
  part('const loadCompetitorSummary =', 'homeRevenueFactLayerController ='),
  part('const applyGeneralHotelToPlatformContext =', 'const openDualOtaModule ='),
  ...(source.includes('const applyRevenueAiEvidenceScope =')
    ? [part('const applyRevenueAiEvidenceScope =', 'let revenueAiGapNavigationSeq =')] : []),
  part('const openRevenueAiGap =', 'const openRevenueAiMetric ='),
  part('const scheduleOnlineDataTabLoad =', 'const openPlatformAutoTab ='),
  part('const openOnlineDataEntryTab =', 'const openHotelCollectionDeviceOnboarding ='),
].join('\n');
const oldDate = '2026-09-26', reportDate = '2026-09-12', hotelId = '81';
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const loaderNames = ['metrics', 'diagnoses', 'workbench', 'patrol', 'phase3', 'competitor'];
const busyRefs = { workbench: 'dailyWorkbenchLoading', patrol: 'dailyWorkbenchPatrolLoading',
  phase3: 'phase3OperationEffectLoopLoading', competitor: 'competitorSummaryLoading' };
const resultRefs = { metrics: 'coreOperationsMetrics', diagnoses: 'coreOperationsDiagnoses',
  workbench: 'dailyWorkbench', patrol: 'dailyWorkbenchPatrol', phase3: 'phase3OperationEffectLoop',
  competitor: 'competitorSummary' };

// Actual gap -> entry -> tab -> deferred refresh, reset and loaders run in memory.
// Requests are deferred promises; no server, credential or storage is consulted.
function harness() {
  const effects = [], requests = [], timers = new Map(), uiTasks = [], refreshed = [], platformTabs = [];
  let timerId = 0;
  const ref = (name, initial = null) => {
    let value = initial;
    return { get value() { return value; }, set value(next) {
      value = next; effects.push([name, copy(next)]);
    } };
  };
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) {
    refs[match[1]] ??= ref(match[1]);
  }
  const initial = {
    coreOperationsHotelId: hotelId, coreOperationsTargetDate: oldDate,
    currentPage: 'online-data', onlineDataTab: 'data-health', filterReportHotel: hotelId,
    onlineDataFilter: { hotel_id: hotelId, source: 'all', start_date: oldDate, end_date: oldDate },
    revenueAiStaticReady: true, revenueAiStaticError: '', coreOperationsHasAccessibleHotel: true,
    selectedCtripHotelId: hotelId, ctripTargetHotelOptions: [{ id: Number(hotelId) }],
    meituanTargetHotelOptions: [{ id: Number(hotelId) }],
    token: true, meituanForm: { hotelId }, operationFilters: {},
    operationHotelOptions: [{ id: Number(hotelId) }], hotelCompetitorSummaries: {},
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = ref(name, value);
  refs.dailyWorkbenchPatrolLatest = { get value() { return refs.dailyWorkbenchPatrol.value?.latest || null; } };
  const context = vm.createContext({
    ...refs, window: {}, URLSearchParams,
    console: { error() {}, warn() {} }, coreOperationsMaxDate: '2026-09-26',
    coreOperationsRequestSeq: 7, collectionReliabilityRequestSeq: 0,
    dailyWorkbenchRequestSeq: 0, dailyWorkbenchPatrolRequestSeq: 0,
    phase3OperationEffectLoopRequestSeq: 0, ctripCompetitiveOperationsRequestSeq: 0,
    competitorSummaryRequestSeq: 0, competitorSummaryRequestPromises: new Map(),
    competitorSummaryResultCache: new Map(),
    revenueAiGapNavigationSeq: 0, revenueAiStaticNotLoadedText: 'synthetic not loaded',
    suppressNextOnlineDataTabWatcherLoad: false, suppressNextDataHealthTabLoad: false,
    suppressNextMeituanHotelConfigApply: false, pendingOnlineDataEntryTab: '',
    captureAuthSession: () => ({ epoch: 1 }), isAuthSessionCurrent: () => true,
    isCompassDataPage: () => false, currentPageReadPolicy: () => ({}),
    readRequestCache: () => false, writeRequestCache() {}, syncMeituanRankingDisplayFromCompetitorSummary() {},
    coreOperationsOffsetDate: date => date,
    loadCtripCompetitiveOperations: async () => null, loadCollectionReliability: async () => null,
    loadPlatformProfileStatus: async () => null, loadLocalCollectorStatus: async () => null,
    loadOperationActions: async () => null,
    showToast: (...args) => effects.push(['toast', args]),
    scheduleDataHealthSecondaryPanelsReady() {}, scheduleDataHealthDetailPanelsReady() {},
    scheduleDataHealthEmployeePanelsReady() {}, isDataHealthPanelVisible: () => true,
    clearDataHealthSecondaryPanelsReadyTimer() {}, clearDataHealthDetailPanelsReadyTimer() {},
    clearDataHealthEmployeePanelsReadyTimer() {}, clearPlatformAutoSettingsPanelsReadyTimer() {},
    clearPlatformAutoSecondaryPanelsReadyTimer() {}, clearPlatformSourceGuidePanelsReadyTimer() {},
    clearManualOnlineFetchConfigPrewarmTimer() {}, shouldPrewarmManualOnlineFetchConfig: () => false,
    isVisibleOnlineDataTab: tab => refs.currentPage.value === 'online-data' && refs.onlineDataTab.value === tab,
    ensureRevenueAiStaticReady: async () => true, ensureHotelOtaConfigLists: async () => true,
    nextTick: callback => Promise.resolve().then(() => callback?.()),
    openCtripManualTab: tab => platformTabs.push({ platform: 'ctrip', tab }),
    openMeituanManualTab: tab => platformTabs.push({ platform: 'meituan', tab }),
    loadDataHealthPanel: (mode, options) => refreshed.push({ mode, options: copy(options),
      hotel: refs.coreOperationsHotelId.value, date: refs.coreOperationsTargetDate.value }),
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id), deferUiTask: (callback, delay) => uiTasks.push({ callback, delay }),
    request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  });
  vm.runInContext(`${statics}\nconst revenueAiResolveGapTarget = window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiGapTarget;\n${production}\nglobalThis.api = {
    open: openRevenueAiGap, startLoop: refreshCoreOperationsLoop,
    start: (name) => {
      const date = coreOperationsTargetDate.value;
      const hotel = coreOperationsHotelId.value;
      if (name === 'metrics') return loadCoreOperationsMetrics(hotel, date, coreOperationsRequestSeq);
      if (name === 'diagnoses') return loadCoreOperationsDiagnoses(hotel, date, coreOperationsRequestSeq);
      if (name === 'workbench') return loadDailyWorkbench({hotelId:hotel,endDate:date});
      if (name === 'patrol') return loadDailyWorkbenchPatrols({hotelId:hotel,targetDate:date});
      if (name === 'phase3') return loadPhase3OperationEffectLoop({hotelId:hotel,targetDate:date,runId:'synthetic-run'});
      return loadCompetitorSummary({hotelId:hotel,targetDate:date,force:true,includeByHotel:false,syncDisplay:false});
    }
  };`, context);
  const navigate = async ({ platform = 'ctrip', page = 'online-data', tab = 'data-health' } = {}) => {
    refs.currentPage.value = 'agent-center';
    const opened = await context.api.open({ hotel_id: hotelId, business_date: reportDate,
      target_platform: platform, target_page: page, target_tab: tab });
    await Promise.resolve();
    return opened;
  };
  const finish = (outcome, date = oldDate) => {
    for (const row of requests) {
      if (outcome === 'exception') row.reject(new Error('synthetic prior date failure'));
      else if (outcome === 'failed') row.resolve({ code: 503, message: 'synthetic prior date refused' });
      else row.resolve({ code: 200, data: { status: 'ready', target_date: date,
        scope: { hotel_id: hotelId, target_date: date },
        diagnosis: { decision_status: 'ready', target_date: date },
        latest: { run_id: 'synthetic-run', scope: { hotel_id: hotelId, target_date: date } },
        health: { status: 'ready' },
      } });
    }
  };
  return { context, refs, effects, requests, timers, uiTasks, refreshed, platformTabs, navigate, finish,
    start: name => context.api.start(name), startLoop: () => context.api.startLoop() };
}

for (const name of loaderNames) {
  for (const outcome of ['success', 'failed', 'exception']) {
    test(`revenue evidence navigation rejects previous-date ${name} ${outcome} before the scheduled refresh`, async () => {
      const h = harness(), pending = h.start(name);
      assert.ok(h.requests.length > 0);
      assert.ok(h.requests.every(row => row.url.includes(oldDate)), 'actual loader requests the old date');
      assert.equal(await h.navigate(), true);
      assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
      assert.equal(h.refs.currentPage.value, 'online-data');
      assert.equal(h.refs.onlineDataTab.value, 'data-health');
      assert.equal(h.timers.size, 0);
      assert.deepEqual(h.uiTasks.map(row => row.delay).sort((a, b) => a - b), [80, 120]);
      assert.equal(h.refreshed.length, 0);
      const busyAfterNavigation = busyRefs[name] ? h.refs[busyRefs[name]].value : false;
      const stateAfterNavigation = copy(h.refs[resultRefs[name]].value);
      h.effects.length = 0;
      h.finish(outcome);
      await pending;
      assert.deepEqual(h.effects, [], 'stale success, error and finally must not write into the new date');
      assert.deepEqual(copy(h.refs[resultRefs[name]].value), stateAfterNavigation);
      assert.equal(busyAfterNavigation, false, 'navigation releases only the stale loader busy state');
      assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
    });
  }

  test(`current-date ${name} still applies after revenue evidence navigation`, async () => {
    const h = harness();
    assert.equal(await h.navigate(), true);
    const pending = h.start(name);
    assert.ok(h.requests.every(row => row.url.includes(reportDate)));
    h.finish('success', reportDate);
    await pending;
    const result = h.refs[resultRefs[name]].value;
    if (name === 'metrics' || name === 'diagnoses') {
      assert.equal(result.ctrip.data.target_date, reportDate);
      assert.equal(result.meituan.data.target_date, reportDate);
    } else if (name === 'patrol') assert.equal(result.latest.scope.target_date, reportDate);
    else assert.equal(result.target_date, reportDate);
    if (busyRefs[name]) assert.equal(h.refs[busyRefs[name]].value, false);
  });
}

test('actual gap, entry and tab navigation preserve platform/date and schedule a forced refresh', async () => {
  const h = harness();
  assert.equal(await h.navigate({ platform: 'meituan' }), true);
  assert.deepEqual(copy(h.refs.onlineDataFilter.value), {
    hotel_id: hotelId, source: 'meituan', start_date: reportDate, end_date: reportDate,
  });
  const initialTasks = h.uiTasks.splice(0);
  for (const row of initialTasks.sort((a, b) => a.delay - b.delay)) await row.callback();
  assert.deepEqual([...h.timers.values()].map(row => row.delay), [560]);
  for (const row of h.timers.values()) row.callback();
  assert.equal(h.refreshed.length, 0);
  assert.equal(h.uiTasks.length, 1);
  await h.uiTasks[0].callback();
  assert.deepEqual(h.refreshed, [{ mode: 'light', options: { force: true }, hotel: hotelId, date: reportDate }]);
});

test('old core refresh does not start another previous-date phase3 read after its jobs settle', async () => {
  const h = harness(), pending = h.startLoop();
  assert.ok(h.requests.length > 0);
  assert.equal(await h.navigate(), true);
  h.finish('success');
  await new Promise(resolve => setImmediate(resolve));
  const phase3Requests = h.requests.filter(row => row.url.includes('/phase3-operation-effect-loop?'));
  h.finish('success');
  await pending;
  assert.equal(phase3Requests.length, 0, 'the obsolete core loop must stop before the follow-up phase3 read');
  assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
});

for (const platform of ['ctrip', 'meituan']) {
  test(`existing ${platform} gap navigation keeps its configured hotel, date and platform destination`, async () => {
    const h = harness();
    const tab = platform === 'ctrip' ? 'ctrip-traffic' : 'meituan-ranking';
    assert.equal(await h.navigate({ platform, page: `${platform}-ebooking`, tab }), true);
    assert.equal(h.refs.currentPage.value, `${platform}-ebooking`);
    assert.equal(h.refs.coreOperationsHotelId.value, hotelId);
    assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
    assert.deepEqual(copy(h.refs.onlineDataFilter.value), {
      hotel_id: hotelId, source: platform, start_date: reportDate, end_date: reportDate,
    });
    assert.deepEqual(h.platformTabs, [{ platform, tab }]);
    assert.equal(h.refreshed.length, 0);
  });
}

test('same-hotel revenue date navigation clears already displayed prior-date facts before any refresh', async () => {
  const h = harness();
  const pending = loaderNames.map(name => h.start(name));
  h.finish('success');
  await Promise.all(pending);
  assert.equal(h.refs.coreOperationsMetrics.value.ctrip.data.target_date, oldDate);
  assert.equal(h.refs.dailyWorkbench.value.target_date, oldDate);
  const requestCount = h.requests.length;
  assert.equal(await h.navigate(), true);
  assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
  assert.equal(h.requests.length, requestCount, 'the new scheduled refresh has not started');
  for (const name of ['coreOperationsMetrics', 'coreOperationsDiagnoses']) {
    for (const platform of ['ctrip', 'meituan']) {
      assert.equal(h.refs[name].value[platform].status, 'not_loaded');
      assert.equal(h.refs[name].value[platform].data, null);
    }
  }
  for (const name of ['workbench', 'patrol', 'phase3', 'competitor']) {
    assert.equal(h.refs[resultRefs[name]].value, null, `${name} must not display D2 under D1`);
    assert.equal(h.refs[busyRefs[name]].value, false);
  }
});

test('same-hotel same-date evidence navigation preserves displayed facts and pending reads while forcing refresh', async () => {
  const h = harness();
  h.refs.coreOperationsTargetDate.value = reportDate;
  const metricsPending = h.start('metrics');
  h.finish('success', reportDate);
  await metricsPending;
  const displayedMetrics = copy(h.refs.coreOperationsMetrics.value);
  const workbenchPending = h.start('workbench');
  assert.equal(h.refs.dailyWorkbenchLoading.value, true);
  assert.equal(await h.navigate(), true);
  assert.equal(h.refs.coreOperationsHotelId.value, hotelId);
  assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
  assert.deepEqual(copy(h.refs.coreOperationsMetrics.value), displayedMetrics,
    'navigation within the same hotel/date must preserve the displayed facts');
  assert.equal(h.refs.dailyWorkbenchLoading.value, true,
    'an active read for the unchanged scope must retain its busy state');
  h.finish('success', reportDate);
  await workbenchPending;
  assert.equal(h.refs.dailyWorkbench.value.target_date, reportDate);
  assert.equal(h.refs.dailyWorkbenchError.value, '');
  assert.equal(h.refs.dailyWorkbenchLoading.value, false);
  assert.deepEqual(copy(h.refs.coreOperationsMetrics.value), displayedMetrics);
  const initialTasks = h.uiTasks.splice(0);
  for (const row of initialTasks.sort((a, b) => a.delay - b.delay)) await row.callback();
  assert.deepEqual([...h.timers.values()].map(row => row.delay), [560]);
  for (const row of h.timers.values()) row.callback();
  assert.equal(h.refreshed.length, 0);
  assert.equal(h.uiTasks.length, 1);
  await h.uiTasks[0].callback();
  assert.deepEqual(h.refreshed, [{ mode: 'light', options: { force: true }, hotel: hotelId, date: reportDate }]);
});
