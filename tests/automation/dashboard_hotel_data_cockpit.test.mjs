import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const dataHealthStatic = readFileSync('public/data-health-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const operationStatic = readFileSync('public/operation-static.js', 'utf8');
const onlineDataFragment = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const aiWorkbenchFragment = readFileSync('resources/frontend/templates/fragments/23b-page-ai-workbench.html', 'utf8');
const collectionReliabilityConcern = readFileSync('app/controller/concern/CollectionReliabilityConcern.php', 'utf8');
const businessDisplayConcern = readFileSync('app/controller/concern/BusinessDisplayConcern.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const autoFetchConcern = readFileSync('app/controller/concern/AutoFetchConcern.php', 'utf8');
const platformDataSyncService = readFileSync('app/service/PlatformDataSyncService.php', 'utf8');
const autoFetchOnceCommand = readFileSync('app/command/AutoFetchOnlineDataOnce.php', 'utf8');
const operationController = readFileSync('app/controller/OperationManagement.php', 'utf8');
const onlinePageStart = html.indexOf("currentPage === 'online-data'");
const onlinePageEnd = html.indexOf('<!-- 下载中心 -->', onlinePageStart);
const onlinePage = onlinePageEnd > onlinePageStart
  ? html.slice(onlinePageStart, onlinePageEnd)
  : html.slice(onlinePageStart);
const dataHealthStart = html.indexOf('data-testid="online-data-health-panel"', onlinePageStart);
const dataHealthEnd = html.indexOf("onlineDataTab === 'analysis'", dataHealthStart);
const dataHealthPage = dataHealthStart >= 0 && dataHealthEnd > dataHealthStart
  ? html.slice(dataHealthStart, dataHealthEnd)
  : '';

test('core operations loop is the first online data surface and keeps manual collection available', () => {
  assert.ok(onlinePageStart > 0, 'online-data page section must exist');
  assert.match(onlinePage, /昨日经营闭环/);
  assert.match(onlinePage, /data-testid="core-operations-loop"/);
  assert.ok(
    onlinePage.indexOf('data-testid="core-operations-loop"') < onlinePage.indexOf('data-testid="manual-one-click-fetch"'),
    'core operations loop must appear before manual collection details',
  );
  assert.match(onlinePage, /手动一键获取/);
  assert.match(onlinePage, /manualOneClickFetchRows/);
  assert.match(onlinePage, /manualOneClickFetchCards/);
  assert.match(onlinePage, /一键获取携程/);
  assert.match(onlinePage, /一键获取美团|更新美团竞争圈/);
  assert.match(onlinePage, /双平台一键获取/);
  assert.match(onlinePage, /runManualOneClickFetch/);
  assert.match(onlinePage, /fetchCtripData/);
  assert.match(onlinePage, /fetchMeituanData/);
  assert.match(onlinePage, /const manualOneClickFetchSavedCount = requireDataHealthStatic\('manualOneClickFetchSavedCount'\)/);
  assert.match(onlinePage, /manualOneClickFetchSavedCount\(result\)/);
  assert.match(dataHealthStatic, /const manualOneClickFetchSavedCount = \(result = \{\}\) => \{/);
  assert.match(dataHealthStatic, /result\?\.response\?\.data\?\.saved_count/);
  assert.match(dataHealthStatic, /result\?\.totalSavedCount/);
  assert.match(onlinePage, /no_saved/);
  assert.match(dataHealthStatic, /本次入库 0 条，不等于入库成功/);
  assert.doesNotMatch(onlinePage, /triggerCookieConfigAutoFetchGroup\(group\.hotelIds\)/);
  assert.doesNotMatch(onlinePage, /当前卡点/);
  assert.doesNotMatch(onlinePage, /今天先处理的问题/);
  assert.doesNotMatch(onlinePage, /优先处理动作/);
  assert.match(onlinePage, /完整诊断/);
  assert.match(onlinePage, /账号级驾驶舱/);
  assert.match(onlinePage, /单店 OTA 数据画像/);
  assert.match(onlinePage, /数据源状态 \/ 证据链/);
  assert.match(onlinePage, /dataHealthDetailPanelsReady && dataHealthFullDiagnosticsLoaded/);
  assert.match(onlinePage, /data-health-full-diagnostics-detail/);
  assert.match(onlinePage, /门店数/);
  assert.match(onlinePage, /画像完成数/);
  assert.match(onlinePage, /异常门店/);
  assert.match(onlinePage, /同步状态/);
});

test('AI workbench nests the yesterday loop and does not render missing current-period facts as zero cards', () => {
  const navigationStart = appMain.indexOf('const BOSS_VISIBLE_NAVIGATION_CONFIG');
  const navigationEnd = appMain.indexOf('const buildLeanNavigationEntry', navigationStart);
  const navigationConfig = appMain.slice(navigationStart, navigationEnd);
  assert.ok(navigationStart > 0 && navigationEnd > navigationStart, 'boss navigation config must exist');
  assert.ok(
    navigationConfig.indexOf("testid: 'nav-lean-business-loop'") < navigationConfig.indexOf("testid: 'nav-core-operations-loop'"),
    'yesterday operations loop must be nested under the business-loop group',
  );
  assert.match(appMain, /const dualOtaSelectedHotelHasCurrentData = computed/);
  assert.match(appMain, /hasDualOtaScopeCurrentData\(\{/);
  assert.doesNotMatch(appMain, /const dualOtaSelectedHotelHasCurrentData = computed\(\(\) => \{\s*if \(!dualOtaSelectedHotel\.value\) return false;/);
  assert.match(appMain, /const dualOtaWorkbenchReadInProgress = computed/);
  assert.match(appMain, /dualOtaDataDateMatchesSelectedRange\(ctripLatestMeta\.value\?\.data_date\)/);
  assert.match(appMain, /dualOtaDataDateMatchesSelectedRange\(competitorSummary\.value\?\.latest_data_date\)/);
  assert.match(appMain, /const dualOtaMeituanActualMetricSources = new Set/);
  assert.match(appMain, /dualOtaMeituanActualMetricValue\(row, \['roomRevenue', 'sales'\]\)/);
  assert.match(appMain, /dualOtaMeituanActualMetricValue\(row, \['roomNights', 'salesRoomNights'\]\)/);
  assert.match(appMain, /dualOtaMeituanActualMetricValue\(row, \['orderCount'\]\)/);
  assert.match(appMain, /美团仅有榜单或竞对数据，本店营收、间夜和订单未返回/);
  const actualSourceGate = appMain.slice(
    appMain.indexOf('const dualOtaMeituanActualMetricSources'),
    appMain.indexOf('const dualOtaMeituanCurrentCoreDataReady'),
  );
  assert.doesNotMatch(actualSourceGate, /美团榜单入库|美团榜单返回/);
  assert.match(aiWorkbenchFragment, /data-testid="dual-ota-current-store-empty"/);
  assert.match(aiWorkbenchFragment, /页面不会用 0 代替缺失数据|dualOtaSelectedHotelDataGapText/);
  assert.match(aiWorkbenchFragment, /<template v-if="!dualOtaWorkbenchReadInProgress &amp;&amp; dualOtaSelectedHotelHasCurrentData">/);
});

test('online-data surface exposes the six-step operating loop and retains collection readiness tools', () => {
  assert.match(dataHealthPage, /data-testid="core-operations-loop"/);
  assert.match(dataHealthPage, /data-testid="core-loop-yesterday-data"/);
  assert.match(dataHealthPage, /data-testid="core-loop-yesterday-fetch"/);
  assert.match(dataHealthPage, /data-testid="core-loop-yesterday-fetch-status"/);
  assert.match(dataHealthPage, /data-testid="core-loop-profile-session-gate"/);
  assert.match(dataHealthPage, /prepareCoreOperationsProfileSession\(row\)/);
  assert.match(dataHealthPage, /runCoreOperationsYesterdayFetch\(\)/);
  assert.match(dataHealthPage, /platform\.evidenceStatusText/);
  assert.match(appMain, /其他采集任务运行中/);
  assert.match(appMain, /双平台 Profile/);
  assert.match(appMain, /target_date_present:\s*'目标日有数据'/);
  assert.match(dataHealthPage, /data-testid="core-loop-competitor-comparison"/);
  assert.match(dataHealthPage, /data-testid="core-loop-anomaly-judgment"/);
  assert.match(dataHealthPage, /data-testid="core-loop-ai-actions"/);
  assert.match(dataHealthPage, /data-testid="core-loop-generate-diagnosis"/);
  assert.match(dataHealthPage, /data-testid="core-loop-diagnosis-generation-status"/);
  assert.match(dataHealthPage, /data-testid="core-loop-ai-to-operation"/);
  assert.match(dataHealthPage, /data-testid="core-loop-operation-tasks"/);
  assert.match(dataHealthPage, /data-testid="core-loop-next-day-review"/);
  assert.match(dataHealthPage, /refreshCoreOperationsLoop/);
  assert.match(dataHealthPage, /createCoreOperationsDiagnosisIntent\(item\)/);
  assert.match(dataHealthPage, /item\.ready && coreOperationsCanExecute/);
  assert.match(dataHealthPage, /coreOperationsCanExecute && operationCanApproveExecution/);
  assert.match(dataHealthPage, /coreOperationsCanExecute && operationCanReviewExecution/);
  assert.match(dataHealthPage, /updateDailyWorkbenchPatrolAction\(action, 'in_progress'\)/);
  assert.match(dataHealthPage, /approveOperationExecutionIntent/);
  assert.match(dataHealthPage, /recordOperationExecutionEvidence/);
  assert.match(dataHealthPage, /reviewOperationExecutionTask/);
  assert.match(dataHealthPage, /data-testid="ota-direct-view-overview"/);
  assert.match(dataHealthPage, /data-testid="manual-one-click-fetch"/);
  assert.match(dataHealthPage, /manualOneClickFetchCards/);
  assert.match(dataHealthPage, /manualOneClickFetchDisplayRows/);
  assert.match(dataHealthPage, /data-testid="phase2-daily-workbench"/);
  assert.match(dataHealthPage, /data-testid="daily-workbench-write-boundary"/);
  assert.match(dataHealthPage, /data-testid="phase3-operation-effect-loop"/);
  assert.doesNotMatch(dataHealthPage, /employeeOtaChecklistRows/);
  assert.doesNotMatch(dataHealthPage, /source: '手动完整诊断'/);
  assert.equal((dataHealthPage.match(/data-testid="ota-config-refresh"/g) || []).length, 1);
  assert.equal((dataHealthPage.match(/@click="refreshOtaConfigOverviewStatus\(\)"/g) || []).length, 1);
  assert.match(appMain, /const loadCoreOperationsDiagnoses = async/);
  assert.match(appMain, /\/agent\/ota-diagnosis/);
  assert.match(appMain, /const readSavedOtaDiagnosis =/);
  assert.match(appMain, /return request\(`\/agent\/ota-diagnosis\?\$\{params\.toString\(\)\}`\)/);
  assert.match(appMain, /const generateCoreOperationsDiagnoses = async/);
  assert.match(appMain, /analysis_mode: 'rules_only'/);
  assert.match(routes, /Route::get\('\/ota-diagnosis', 'Agent\/latestOtaDiagnosis'\)/);
  assert.match(appMain, /dataPeriod: 'historical_daily'/);
  assert.match(appMain, /binding_contract\?\.current_session_verified === true/);
  assert.match(appMain, /今天的 Profile 会话尚未验证/);
  assert.match(appMain, /未发起正式采集/);
  assert.match(appMain, /ctrip_auto_fetch_mode: ctripMode/);
  assert.match(appMain, /meituan_auto_fetch_mode: meituanMode/);
  assert.match(appMain, /后台采集中；当前不计成功/);
  assert.match(appMain, /completedTaskId !== expectedTaskId/);
  assert.match(appMain, /completedDataDate !== String\(state\.targetDate \|\| ''\)/);
  assert.match(appMain, /completedDataPeriod !== 'historical_daily'/);
  assert.match(appMain, /const coreOperationsFetchPlatformOutcome = \(platformResults = \[\]\) =>/);
  assert.match(appMain, /row\?\.success === true && row\?\.skipped !== true/);
  assert.match(appMain, /strictBackendSucceeded = backendSucceeded === true && runOutcome\.allSucceeded/);
  assert.match(appMain, /platformResults: autoFetchStatus\.value\?\.last_result\?\.platform_results \|\| \[\]/);
  assert.match(appMain, /allPlatformsReported = runOutcome\.results\.every\(item => item\.present\)/);
  assert.match(appMain, /allPlatformsWrote = allPlatformsReported && runOutcome\.results\.every\(item => item\.savedCount > 0\)/);
  assert.match(appMain, /noPlatformsWrote = allPlatformsReported && runOutcome\.results\.every\(item => item\.savedCount === 0\)/);
  assert.match(appMain, /allReadbackBound: results\.every\(item => item\.readbackBound === true\)/);
  assert.match(appMain, /verifiedNewWrites = verifiedPlatforms === 2[\s\S]*strictBackendSucceeded[\s\S]*allPlatformsWrote[\s\S]*runOutcome\.allReadbackBound/);
  assert.match(appMain, /verified_mixed/);
  assert.match(appMain, /written_unbound/);
  assert.match(appMain, /不宣称本次采集成功/);
  assert.match(appMain, /不能按双平台本次新采集成功处理/);
  assert.match(appMain, /不冒充本次新采集/);
  assert.match(autoFetchConcern, /'task_id' => \$backgroundTaskId/);
  assert.match(autoFetchConcern, /\$status\['last_result'\]\['task_id'\] = \$taskId/);
  assert.match(autoFetchConcern, /\$allRequestedPlatformsSucceeded = \$requestedPlatformCount > 0/);
  assert.match(autoFetchConcern, /'success' => \$allRequestedPlatformsSucceeded/);
  assert.match(platformDataSyncService, /buildRunReadbackReceipt/);
  assert.match(platformDataSyncService, /where\('sync_task_id', \$taskId\)/);
  assert.match(platformDataSyncService, /where\('data_source_id', \$sourceId\)/);
  assert.match(platformDataSyncService, /\$receipt\['verified_metric_keys'\] = \$this->verifiedCoreMetricKeysFromRunRows/);
  assert.match(autoFetchConcern, /autoFetchRunReadbackCoreVerified/);
  assert.match(autoFetchConcern, /'success' => \$this->autoFetchPlatformRunSucceeded\(\$savedCount, \$runReadback\)/);
  assert.doesNotMatch(autoFetchConcern, /'success' => true, 'message' => \$message, 'saved_count' => \$savedCount/);
  assert.match(autoFetchConcern, /syncMeituanBrowserProfileDataSourcesForAutoFetch/);
  assert.match(autoFetchOnceCommand, /'platform_results' => is_array\(\$details\['platform_results'\]/);
  assert.match(appMain, /await refreshCoreOperationsLoop\(\{ hotelId, targetDate \}\)/);
  assert.match(appMain, /verifiedPlatforms === 2/);
  const truthfulClassificationIndex = appMain.indexOf('if (verifiedNewWrites || verifiedExisting || verifiedMixed || writtenUnbound)');
  const strictFailureIndex = appMain.indexOf('if (!strictBackendSucceeded)', truthfulClassificationIndex);
  assert.ok(truthfulClassificationIndex > 0 && strictFailureIndex > truthfulClassificationIndex,
    'truthful readback states must be classified before the strict backend failure fallback');
  assert.doesNotMatch(appMain, /均返回成功，但未新增写入/);
  assert.doesNotMatch(appMain, /双平台均返回成功，但只有部分平台产生新写入/);
  assert.match(appMain, /真实经营证据仍未补齐，不能进入成功态/);
  assert.match(appMain, /\/agent\/ota-diagnoses\/\$\{recordId\}\/actions\/\$\{actionIndex\}\/execution-intent/);
  assert.match(appMain, /const persistedIntent = await readOperationExecutionIntent\(intent\.id\)/);
  assert.match(appMain, /persistedIntent\.source_module \|\| ''\) !== 'ota_diagnosis_saved'/);
  assert.match(appMain, /persistedIntent\.source_record_id \|\| 0\) !== recordId/);
  assert.match(appMain, /persistedEvidence\.action_item_id \|\| ''\)\.trim\(\) !== actionItemId/);
  assert.match(appMain, /requiredActionKeys\.has\(key\)/);
  assert.match(appMain, /latestAiExecutionByActionKey/);
  assert.match(appMain, /requiredActionKeys\.size === requiredActionCount/);
  assert.match(appMain, /'failed', 'failure'/);
  assert.doesNotMatch(appMain, /return rows\.sort\(\(left, right\) => Number\(right\.ready\) - Number\(left\.ready\)\)\.slice\(0, 6\)/);
  assert.match(appMain, /String\(capability\.hotel_id \|\| ''\) === hotelId/);
  assert.match(appMain, /capability\.can_execute === true/);
  assert.match(appMain, /capability\.can_generate_diagnosis === true/);
  assert.match(appMain, /capability\.can_collect_ota === true/);
  assert.match(operationController, /hasHotelPermission\(\$hotelId, 'operation\.execute'\)/);
  assert.match(operationController, /'can_generate_diagnosis' => \$canView/);
  assert.match(operationController, /hasHotelPermission\(\$hotelId, 'can_fetch_online_data'\)/);
  assert.match(onlineDataFragment, /v-if="coreOperationsCanCollect" data-testid="core-loop-yesterday-fetch"/);
  assert.match(onlineDataFragment, /data-testid="core-loop-competitor-source-evidence"/);
  assert.match(appMain, /keepCurrentSurface: true/);
  assert.equal((onlineDataFragment.match(/refreshCoreOperationsLoop\(\{ resetScope: true \}\)/g) || []).length, 2);
  assert.match(appMain, /options\.resetScope === true/);
  assert.match(appMain, /Promise\.allSettled\(\['ctrip', 'meituan'\]/);
  assert.match(appMain, /const currentPage = ref\(initialPageOverride \|\| 'online-data'\)/);
  assert.match(appMain, /testid: 'nav-core-operations-loop'/);
  assert.match(operationStatic, /OTA诊断行动/);
  assert.match(operationStatic, /巡检补证任务/);
});

test('dashboard frontend calls dedicated dashboard APIs while old collection reliability remains available', () => {
  for (const endpoint of [
    '/dashboard/account-overview',
    '/dashboard/hotel-portrait',
    '/dashboard/data-sources',
  ]) {
    assert.match(dataHealthStatic, new RegExp(endpoint.replaceAll('/', '\\/')));
  }
  assert.match(html, /\/online-data\/collection-reliability/);

  assert.match(routes, /Route::group\('api\/dashboard'/);
  assert.match(routes, /account-overview/);
  assert.match(routes, /hotel-portrait/);
  assert.match(routes, /data-sources/);
});

test('core loop reads exact target-day OTA evidence without zero fallbacks', () => {
  const metricCardStart = appMain.indexOf('const coreOperationsMetricCardValueText =');
  const metricCardEnd = appMain.indexOf('const coreOperationsMeituanComparableValue =', metricCardStart);
  const metricCardSource = metricCardStart >= 0 && metricCardEnd > metricCardStart
    ? appMain.slice(metricCardStart, metricCardEnd)
    : '';
  assert.match(html, /coreOperationsTargetDate = ref\(ctripCompetitiveLocalDate\(-1\)\)/);
  assert.match(html, /\/ota-standard\/revenue-metrics/);
  assert.match(html, /source: platform/);
  assert.match(html, /start_date: targetDate/);
  assert.match(html, /end_date: targetDate/);
  assert.match(html, /startDate: coreOperationsOffsetDate\(targetDate, -29\)/);
  assert.match(html, /loadCompetitorSummary\(\{/);
  assert.match(html, /targetDate,/);
  assert.match(metricCardSource, /data\?\.metric_trust/);
  assert.match(metricCardSource, /metricTrust\?\.\[candidate\?\.trustKey\]\?\.truth/);
  for (const trustKey of ['totals.room_revenue', 'totals.revenue', 'totals.room_nights', 'totals.adr', 'totals.revpar']) {
    assert.match(metricCardSource, new RegExp(trustKey.replace('.', '\\.')));
  }
  assert.match(metricCardSource, /failure_reason: '指标可信证据未返回'/);
  assert.match(metricCardSource, /return '—'/);
  assert.match(metricCardSource, /calculationStatus/);
  assert.match(metricCardSource, /truthStatus/);
  assert.match(metricCardSource, /requiredForLoop: definition\.requiredForLoop !== false/);
  assert.match(metricCardSource, /requiredRows = rows\.filter/);
  assert.match(metricCardSource, /requiredForLoop: false/);
  assert.match(appMain, /filter\(item => item\.truthStatus === 'verified'\)/);
  assert.doesNotMatch(metricCardSource, /sourceRows\s*>\s*0/);
  assert.doesNotMatch(metricCardSource, /totals\.(?:room_revenue|revenue|room_nights|adr|revpar)\s*\|\|\s*0/);
  assert.doesNotMatch(appMain, /Number\(ctripCoverage\.(?:business_row_count|traffic_row_count|decision_eligible_row_count) \|\| 0\)/);
  assert.doesNotMatch(appMain, /Number\(competitorSummary\.value\?\.(?:record_count|display_hotel_count) \|\| 0\)/);
  assert.match(onlineDataFragment, /core-operations-metric-/);
  assert.match(onlineDataFragment, /core-operations-metric-calculation-status-/);
  assert.match(onlineDataFragment, /core-operations-metric-truth-status-/);
  assert.match(onlineDataFragment, /core-operations-metric-value-/);
  assert.match(onlineDataFragment, /core-operations-metric-truth-detail-/);
  assert.match(onlineDataFragment, /onlineTruthStatusText\(metric\.truth\)/);
  assert.match(onlineDataFragment, /onlineTruthStatusClass\(metric\.truth\)/);
  assert.match(onlineDataFragment, /<online-truth-summary :truth="metric\.truth"/);
  assert.match(onlineDataFragment, /计算：/);
  assert.match(onlineDataFragment, /真值：/);
  assert.match(html, /String\(recommendation\.date_start \|\| ''\) === targetDate/);
  assert.match(businessDisplayConcern, /get\('target_date', ''\)/);
  assert.match(businessDisplayConcern, /target_date must use YYYY-MM-DD/);
});

test('dashboard UI exposes required portrait sections, diagnostics and explicit data states', () => {
  for (const label of [
    '基础',
    '经营',
    '流量',
    '转化',
    '价格房态',
    '竞争',
    '点评服务',
    'IM',
    '广告',
    '客群',
    '数据健康',
  ]) {
    assert.match(html, new RegExp(label), `missing portrait section label: ${label}`);
  }

  for (const key of ['problem', 'evidence', 'impact', 'action']) {
    assert.match(html, new RegExp(key), `missing diagnosis key: ${key}`);
  }

  for (const state of ['zero', 'null', 'not_collected', 'auth_failed', 'request_failed', 'field_missing']) {
    assert.match(`${dataHealthStatic}\n${collectionReliabilityConcern}`, new RegExp(state), `missing explicit dashboard state: ${state}`);
  }
});
