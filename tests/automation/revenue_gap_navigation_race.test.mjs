import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appSource = readFileSync('public/app-main.js', 'utf8');
const extract = (from, to) => {
  const start = appSource.indexOf(from);
  const end = appSource.indexOf(to, start);
  assert.ok(start >= 0 && end > start, `${from} must remain testable`);
  return appSource.slice(start, end);
};
const deferred = () => {
  let resolve;
  const promise = new Promise(done => { resolve = done; });
  return { promise, resolve };
};
const row = (hotel = 80, platform = 'ctrip', page = `${platform}-ebooking`) => ({
  hotel_id: hotel, business_date: hotel === 80 ? '2026-09-08' : '2026-09-09',
  target_platform: platform, target_page: page, target_tab: 'data-health',
});
const harness = (options = {}) => {
  const context = { window: {}, URLSearchParams, options, console: { warn() {} } };
  for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
    vm.runInNewContext(readFileSync(`public/${file}`, 'utf8'), context);
  }
  return vm.runInNewContext(`(() => {
    const ref = value => ({ value });
    const currentPage = ref('revenue-ai');
    const filterReportHotel = ref('999'), autoFetchHotelId = ref('999'), coreOperationsHotelId = ref('999');
    const coreOperationsTargetDate = ref('2026-01-01');
    const onlineDataFilter = ref({ hotel_id: '999', source: 'all', start_date: '2026-01-01', end_date: '2026-01-01' });
    const selectedCtripHotelId = ref('999'), ctripTargetHotelManuallySelected = ref(false);
    const meituanForm = ref({ hotelId: '999' });
    let suppressNextMeituanHotelConfigApply = false;
    const ctripTargetHotelOptions = ref([{ id: 80 }, { id: 81 }]);
    const meituanTargetHotelOptions = ctripTargetHotelOptions;
    const permittedHotels = ref([]), hotels = ref([]);
    const revenueAiStaticReady = ref(true), revenueAiStaticError = ref('');
    const revenueAiStaticNotLoadedText = 'not loaded';
    const revenueAiResolveGapTarget = window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiGapTarget;
    const agentTab = ref(''), revenueAgentTab = ref('');
    const events = [], notices = [];
    let configCalls = 0, revenueAiGapNavigationSeq = 0;
    const ensureHotelOtaConfigLists = async () => { await options.config?.(configCalls++); };
    const ensureRevenueAiStaticReady = async () => true;
    const showToast = message => notices.push(message);
    const resetCoreOperationsScopedState = () => {};
    let coreOperationsRequestSeq = 0, dailyWorkbenchRequestSeq = 0, dailyWorkbenchPatrolRequestSeq = 0,
      phase3OperationEffectLoopRequestSeq = 0, competitorSummaryRequestSeq = 0;
    const dailyWorkbenchLoading = ref(false), dailyWorkbenchPatrolLoading = ref(false),
      phase3OperationEffectLoopLoading = ref(false), competitorSummaryLoading = ref(false);
    const nextTick = async callback => { await options.tick?.(); return callback?.(); };
    const openCtripManualTab = () => events.push('ctrip');
    const openMeituanManualTab = () => events.push('meituan');
    const openOnlineDataEntryTab = () => { currentPage.value = 'online-data'; events.push('online-data'); };
    const loadPriceSuggestionWorkbench = async () => events.push('suggestions');
    const loadRoomTypes = async () => events.push('config');
    const loadRevenueAnalysisBundle = async () => events.push('analysis');
    ${extract('const applyGeneralHotelToPlatformContext =', 'const openDualOtaModule =')}
    ${extract('const invalidateCoreOperationsScopedState =', 'const refreshCoreOperationsLoop =')}
    ${extract('const applyRevenueAiEvidenceScope =', 'let revenueAiGapNavigationSeq =')}
    ${extract('const openRevenueAiGap =', 'const openRevenueAiMetric =')}
    return { openRevenueAiGap, currentPage, filterReportHotel, coreOperationsTargetDate, onlineDataFilter, selectedCtripHotelId, meituanForm, events, notices };
  })()`, context);
};

test('latest gap wins when two platform configuration reads finish out of order', async () => {
  const first = deferred(), second = deferred();
  const view = harness({ config: index => [first.promise, second.promise][index] });
  const oldNavigation = view.openRevenueAiGap(row());
  const newNavigation = view.openRevenueAiGap(row(81, 'meituan'));
  second.resolve();
  assert.equal(await newNavigation, true);
  first.resolve();
  assert.equal(await oldNavigation, false);
  assert.equal(view.currentPage.value, 'meituan-ebooking');
  assert.equal(view.onlineDataFilter.value.hotel_id, '81');
  assert.equal(view.onlineDataFilter.value.start_date, '2026-09-09');
  assert.equal(view.selectedCtripHotelId.value, '999', 'obsolete configuration must not change the hidden platform selection');
  assert.equal(view.meituanForm.value.hotelId, '81');
  assert.deepEqual(Array.from(view.events), ['meituan']);
});

test('an immediate data-health jump cancels an older platform configuration read', async () => {
  const pending = deferred();
  const view = harness({ config: () => pending.promise });
  const oldNavigation = view.openRevenueAiGap(row());
  assert.equal(await view.openRevenueAiGap(row(81, 'meituan', 'online-data')), true);
  pending.resolve();
  assert.equal(await oldNavigation, false);
  assert.equal(view.currentPage.value, 'online-data');
  assert.equal(view.onlineDataFilter.value.hotel_id, '81');
  assert.equal(view.selectedCtripHotelId.value, '999');
  assert.deepEqual(Array.from(view.events), ['online-data']);
});

test('leaving the originating page cancels a pending gap without changing platform or filters', async () => {
  const pending = deferred();
  const view = harness({ config: () => pending.promise });
  const navigation = view.openRevenueAiGap(row());
  view.currentPage.value = 'home';
  pending.resolve();
  assert.equal(await navigation, false);
  assert.equal(view.currentPage.value, 'home');
  assert.equal(view.onlineDataFilter.value.hotel_id, '999');
  assert.equal(view.selectedCtripHotelId.value, '999');
  assert.deepEqual(Array.from(view.events), []);
});

test('an obsolete next-tick callback cannot open its tab after a newer data-health jump', async () => {
  const tickEntered = deferred(), tickDone = deferred();
  const view = harness({ tick: () => { tickEntered.resolve(); return tickDone.promise; } });
  const oldNavigation = view.openRevenueAiGap(row());
  await tickEntered.promise;
  assert.equal(await view.openRevenueAiGap(row(81, 'meituan', 'online-data')), true);
  tickDone.resolve();
  assert.equal(await oldNavigation, false);
  assert.equal(view.currentPage.value, 'online-data');
  assert.equal(view.onlineDataFilter.value.hotel_id, '81');
  assert.deepEqual(Array.from(view.events), ['online-data']);
});

for (const field of ['hotel', 'date', 'platform']) {
  test(`changing the ${field} on the same page supersedes a pending gap`, async () => {
    const pending = deferred();
    const view = harness({ config: () => pending.promise });
    const navigation = view.openRevenueAiGap(row());
    if (field === 'hotel') view.filterReportHotel.value = '81';
    if (field === 'date') view.coreOperationsTargetDate.value = '2026-09-10';
    if (field === 'platform') view.onlineDataFilter.value.source = 'meituan';
    pending.resolve();
    assert.equal(await navigation, false);
    assert.equal(view.currentPage.value, 'revenue-ai');
    assert.equal(view.selectedCtripHotelId.value, '999');
    assert.equal(view.onlineDataFilter.value.hotel_id, '999');
    if (field === 'hotel') assert.equal(view.filterReportHotel.value, '81');
    if (field === 'date') assert.equal(view.coreOperationsTargetDate.value, '2026-09-10');
    if (field === 'platform') assert.equal(view.onlineDataFilter.value.source, 'meituan');
    assert.deepEqual(Array.from(view.events), []);
  });
}
