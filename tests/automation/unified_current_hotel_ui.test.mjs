import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');

const sliceBetween = (start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

const bindings = sliceBetween(
  'const unifiedHotelContextBindings = [',
  'let unifiedHotelContextSyncing = false;',
);
const resetResults = sliceBetween(
  'const resetUnifiedHotelScopedResults = () => {',
  'const syncUnifiedHotelContexts =',
);
const syncContract = sliceBetween(
  'const syncUnifiedHotelContexts =',
  'return {',
);

test('ordinary hotel selectors share the one system current-hotel context', () => {
  for (const expectedBinding of [
    'coreOperationsHotelId.value',
    'dashboardHotelId.value',
    'autoFetchHotelId.value',
    'selectedCtripHotelId.value',
    'meituanForm.value.hotelId',
    'onlineDataFilter.value.hotel_id',
    'operationFilters.value.hotel_id',
    'operatingTargetForm.value.hotel_id',
    'strategyForm.value.hotel_id',
    'aiDailyReportForm.value.hotel_id',
    'operationOptimizerFilter.value.hotel_id',
    'revenueResearchHotelId.value',
    'aiFeasibilityHotelId.value',
    'transferSelectedHotelId.value',
    'otaDiagnosisForm.value.hotel_id',
  ]) {
    assert.ok(bindings.includes(expectedBinding), `missing unified hotel binding: ${expectedBinding}`);
  }

  assert.match(syncContract, /watch\(filterReportHotel,[\s\S]*syncUnifiedHotelContexts\(hotelId, previousHotelId\)/);
  assert.match(syncContract, /\{ immediate: true, flush: 'sync' \}/);
  assert.match(syncContract, /unifiedHotelContextBindings\.forEach\(binding =>/);
  assert.match(syncContract, /binding\.write\(normalizedHotelId\)/);
});

test('a visible page hotel choice promotes only an accessible non-empty hotel', () => {
  assert.match(syncContract, /binding\.pages\.includes\(currentPage\.value\)/);
  assert.match(syncContract, /!normalizedHotelId/);
  assert.match(syncContract, /!reportHotelOptionExists\(normalizedHotelId\)/);
  assert.match(syncContract, /filterReportHotel\.value = normalizedHotelId/);
  assert.match(syncContract, /\{ flush: 'sync' \}/);
});

test('hotel drilldown buttons also adopt the system current hotel before navigation', () => {
  const drilldown = sliceBetween(
    'const openAutomationMonitorDrilldown =',
    'const automationMonitorSourceClass =',
  );

  assert.match(drilldown, /\['ctrip', 'meituan', 'pms', 'tasks'\]\.includes\(target\)/);
  assert.match(drilldown, /!reportHotelOptionExists\(hotelId\)/);
  assert.match(drilldown, /filterReportHotel\.value = hotelId/);
  assert.doesNotMatch(drilldown, /\['ctrip', 'meituan', 'pms', 'tasks', 'wechat'\]/);
});

test('explicit actions, editing, comparison and admin scopes remain independent', () => {
  for (const explicitTarget of [
    'manualNotificationForm',
    'wechatNotificationHotelId',
    'hotelMergeForm',
    'ctripConfigForm',
    'meituanConfigForm',
    'ctripCookieEditorForm',
    'ctripBrowserCaptureForm',
    'ctripReviewMatchForm',
    'platformDataSourceForm',
    'onlineDataEditForm',
    'localCollectorAccountForm',
    'localCollectorBindingForm',
    'browserAssistImportForm',
    'onlineHistoryFilter',
    'actionForm',
    'openingProjectForm',
    'knowledgeCenterForm',
    'knowledgeCenterImportForm',
    'aiFeasibilityExecutionHotelId',
    'expansionExecutionHotelId',
    'competitorPriceForm',
    'aiSelectedHotels',
    'meituanAiSelectedHotels',
    'filterUserHotelId',
    'logFilter',
  ]) {
    assert.doesNotMatch(bindings, new RegExp(explicitTarget), `${explicitTarget} must stay outside automatic hotel synchronization`);
  }
});

test('switching the current hotel invalidates old-store results instead of displaying stale cards', () => {
  assert.match(resetResults, /resetCoreOperationsScopedState\(\)/);
  assert.match(resetResults, /dashboardHotelPortrait\.value = null/);
  assert.match(resetResults, /ctripPublicProfiles\.value = \[\]/);
  assert.match(resetResults, /operationFullData\.value = null/);
  assert.match(resetResults, /aiDailyReport\.value = null/);
  assert.match(resetResults, /operatingTargetResult\.value = null/);
  assert.match(resetResults, /operationOptimizerData\.value = null/);
  assert.match(resetResults, /otaDiagnosisResult\.value = null/);
  assert.match(resetResults, /aiFeasibilityResult\.value = null/);
  assert.match(resetResults, /transferSourceSnapshot\.value = null/);
  assert.match(resetResults, /transferPricingResult\.value = null/);
});

test('legacy page defaults now prefer the system current hotel', () => {
  const onlineDefault = sliceBetween(
    'const resolveDefaultOnlineAnalysisHotelId = async () => {',
    'const loadCompetitorEventFeed = async',
  );
  const autoFetchDefault = sliceBetween(
    'const getAutoFetchHotelId = () => {',
    'const AUTO_FETCH_PANEL_CACHE_TTL_MS',
  );
  const operationDefault = sliceBetween(
    'const normalizeOperationHotelSelection =',
    'const operationDisplayFormatters =',
  );
  const dashboardLoad = sliceBetween(
    'const loadHotelDataDashboard = async () => {',
    'const dataHealthLightCacheKey =',
  );
  const otaPlatformWatch = sliceBetween(
    'watch(() => otaDiagnosisForm.value.platform',
    'const setOtaDiagnosisRange =',
  );

  assert.match(onlineDefault, /if \(filterReportHotel\.value\) return filterReportHotel\.value/);
  assert.match(autoFetchDefault, /if \(filterReportHotel\.value\) return filterReportHotel\.value/);
  assert.match(operationDefault, /isOperationHotelPermitted\(systemHotelId\)[\s\S]*firstOperationHotelId\(\)/);
  assert.match(dashboardLoad, /dashboardHotelId\.value \|\| filterReportHotel\.value \|\| getAutoFetchHotelId\(\)/);
  assert.match(otaPlatformWatch, /reportHotelOptionExists\(systemHotelId\) \? systemHotelId : ''/);
});
