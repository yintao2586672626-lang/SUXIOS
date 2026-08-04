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

test('ordinary hotel selectors share one system context while OTA project hotels stay independent', () => {
  for (const expectedBinding of [
    'coreOperationsHotelId.value',
    'dashboardHotelId.value',
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

  for (const platformScopedBinding of [
    'autoFetchHotelId.value',
    'selectedCtripHotelId.value',
    'meituanForm.value.hotelId',
  ]) {
    assert.ok(!bindings.includes(platformScopedBinding), `platform hotel leaked into unified context: ${platformScopedBinding}`);
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

test('ordinary page defaults use only the explicit system current hotel', () => {
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
  const operatingTargetDefault = sliceBetween(
    'const operatingTargetContext = () => {',
    'const applyOperatingTargetRecord =',
  );
  const manualNotificationContext = sliceBetween(
    'const manualNotificationContext = () => {',
    'const manualNotificationTemplateCards =',
  );
  const homeTemporalDefault = sliceBetween(
    'const homeTemporalSelectedHotelId = computed(() => {',
    'const homeTemporalPastMetric = computed',
  );
  const transferDefault = sliceBetween(
    'const defaultTransferHotelId = () => {',
    'const resolveTransferHotelId =',
  );
  const dashboardLoad = sliceBetween(
    'const loadHotelDataDashboard = async () => {',
    'const dataHealthLightCacheKey =',
  );
  const optimizerDefault = sliceBetween(
    'watch(operationOptimizerHotelOptions, (options) => {',
    'const operationOptimizerKeywordRows = computed',
  );
  const optimizerLoad = sliceBetween(
    'const loadOperationOptimizer = async (options = {}) => {',
    'const openOperationOptimizerRecovery =',
  );
  const revenueDefault = sliceBetween(
    'watch(revenueResearchHotelOptions, (options) => {',
    'const revenueResearchSteps = ref',
  );
  const feasibilityDefault = sliceBetween(
    'watch(aiFeasibilityHotelOptions, (options) => {',
    'watch(aiFeasibilityHotelId,',
  );
  const otaPlatformWatch = sliceBetween(
    'watch(() => otaDiagnosisForm.value.platform',
    'const setOtaDiagnosisRange =',
  );

  assert.match(onlineDefault, /return String\(filterReportHotel\.value \|\| ''\)\.trim\(\)/);
  assert.doesNotMatch(onlineDefault, /autoFetchHotelId|hotels\.value.*\[0\]|auto-fetch-records/);
  assert.match(autoFetchDefault, /if \(filterReportHotel\.value\) return filterReportHotel\.value/);
  assert.match(operationDefault, /isOperationHotelPermitted\(systemHotelId\)[\s\S]*\? systemHotelId[\s\S]*: ''/);
  assert.doesNotMatch(operationDefault, /firstOperationHotelId|operationHotelOptions\.value\[0\]/);
  assert.match(operatingTargetDefault, /isOperationHotelPermitted\(systemHotelId\)[\s\S]*hotelId = systemHotelId/);
  assert.doesNotMatch(operatingTargetDefault, /operationHotelOptions\.value\[0\]|length === 1/);
  assert.doesNotMatch(manualNotificationContext, /operationHotelOptions\.value\[0\]|length === 1/);
  assert.match(homeTemporalDefault, /return String\(filterReportHotel\.value \|\| ''\)\.trim\(\)/);
  assert.doesNotMatch(homeTemporalDefault, /permittedHotels|hotels\.value|\[0\]|length === 1/);
  assert.match(transferDefault, /filterReportHotel\.value/);
  assert.match(transferDefault, /transferHotelOptions\.value[\s\S]*\.some\(/);
  assert.doesNotMatch(transferDefault, /\[0\]|length === 1/);
  assert.match(dashboardLoad, /dashboardHotelId\.value \|\| filterReportHotel\.value \|\| ''/);
  assert.doesNotMatch(dashboardLoad, /getAutoFetchHotelId\(\)/);
  assert.doesNotMatch(optimizerDefault, /autoFetchHotelId|options\[0\]/);
  assert.doesNotMatch(optimizerLoad, /optionsList\[0\]/);
  assert.doesNotMatch(revenueDefault, /options\[0\]/);
  assert.doesNotMatch(feasibilityDefault, /normalized\[0\]/);
  assert.match(otaPlatformWatch, /reportHotelOptionExists\(systemHotelId\) \? systemHotelId : ''/);
});
