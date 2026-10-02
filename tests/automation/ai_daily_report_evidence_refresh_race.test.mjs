import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const source = readFileSync(process.env.SUXIOS_AI_DAILY_EVIDENCE_RACE_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const dateContract = readFileSync(new URL('../../public/revenue-overview-contract-static.js', import.meta.url), 'utf8');
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
  part('const openAiDailyReportDataHealthTarget =', 'const openAiDailyReportEvidenceTarget ='),
].join('\n');
const oldDate = '2026-09-26', reportDate = '2026-09-12', hotelId = '81';
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const loaderNames = ['metrics', 'diagnoses', 'workbench', 'patrol', 'phase3', 'competitor'];
const busyRefs = { workbench: 'dailyWorkbenchLoading', patrol: 'dailyWorkbenchPatrolLoading',
  phase3: 'phase3OperationEffectLoopLoading', competitor: 'competitorSummaryLoading' };
const resultRefs = { metrics: 'coreOperationsMetrics', diagnoses: 'coreOperationsDiagnoses',
  workbench: 'dailyWorkbench', patrol: 'dailyWorkbenchPatrol', phase3: 'phase3OperationEffectLoop',
  competitor: 'competitorSummary' };

// The actual navigation, reset, loaders and two-stage scheduler run in memory.
// Requests are deferred promises; no server, credential or storage is consulted.
function harness() {
  const effects = [], requests = [], timers = new Map(), uiTasks = [], refreshed = [];
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
    aiDailyReport: { hotel_id: Number(hotelId), report_date: reportDate },
    coreOperationsHotelId: hotelId, coreOperationsTargetDate: oldDate,
    currentPage: 'online-data', onlineDataTab: 'data-health', filterReportHotel: hotelId,
    token: true, meituanForm: { hotelId }, operationFilters: {},
    operationHotelOptions: [{ id: Number(hotelId) }], hotelCompetitorSummaries: {},
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = ref(name, value);
  refs.dailyWorkbenchPatrolLatest = { get value() { return refs.dailyWorkbenchPatrol.value?.latest || null; } };
  const context = vm.createContext({
    ...refs, window: {}, URLSearchParams,
    console: { error() {} }, coreOperationsMaxDate: '2026-09-26',
    coreOperationsRequestSeq: 7, collectionReliabilityRequestSeq: 0,
    dailyWorkbenchRequestSeq: 0, dailyWorkbenchPatrolRequestSeq: 0,
    phase3OperationEffectLoopRequestSeq: 0, ctripCompetitiveOperationsRequestSeq: 0,
    competitorSummaryRequestSeq: 0, competitorSummaryRequestPromises: new Map(),
    competitorSummaryResultCache: new Map(),
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
    loadDataHealthPanel: (mode, options) => refreshed.push({ mode, options: copy(options),
      hotel: refs.coreOperationsHotelId.value, date: refs.coreOperationsTargetDate.value }),
    setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout: id => timers.delete(id), deferUiTask: callback => uiTasks.push(callback),
    request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  });
  vm.runInContext(`${dateContract}\n${production}\nglobalThis.api = {
    open: openAiDailyReportDataHealthTarget, startLoop: refreshCoreOperationsLoop,
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
  const navigate = () => {
    refs.currentPage.value = 'ai-daily-report';
    context.api.open();
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
  return { context, refs, effects, requests, timers, uiTasks, refreshed, navigate, finish,
    start: name => context.api.start(name), startLoop: () => context.api.startLoop() };
}

for (const name of loaderNames) {
  for (const outcome of ['success', 'failed', 'exception']) {
    test(`report date navigation rejects previous-date ${name} ${outcome} before the scheduled refresh`, async () => {
      const h = harness(), pending = h.start(name);
      assert.ok(h.requests.length > 0);
      assert.ok(h.requests.every(row => row.url.includes(oldDate)), 'actual loader requests the old date');
      h.navigate();
      assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
      assert.equal(h.refs.currentPage.value, 'online-data');
      assert.equal(h.refs.onlineDataTab.value, 'data-health');
      assert.deepEqual([...h.timers.values()].map(row => row.delay), [560]);
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

  test(`current-date ${name} still applies after report navigation`, async () => {
    const h = harness();
    h.navigate();
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

test('the actual two-stage scheduler refreshes the selected report date with force', async () => {
  const h = harness();
  h.navigate();
  for (const row of h.timers.values()) row.callback();
  assert.equal(h.refreshed.length, 0);
  assert.equal(h.uiTasks.length, 1);
  await h.uiTasks[0]();
  assert.deepEqual(h.refreshed, [{ mode: 'light', options: { force: true }, hotel: hotelId, date: reportDate }]);
});

test('old core refresh does not start another previous-date phase3 read after its jobs settle', async () => {
  const h = harness(), pending = h.startLoop();
  assert.ok(h.requests.length > 0);
  h.navigate();
  h.finish('success');
  await new Promise(resolve => setImmediate(resolve));
  const phase3Requests = h.requests.filter(row => row.url.includes('/phase3-operation-effect-loop?'));
  h.finish('success');
  await pending;
  assert.equal(phase3Requests.length, 0, 'the obsolete core loop must stop before the follow-up phase3 read');
  assert.equal(h.refs.coreOperationsTargetDate.value, reportDate);
});
