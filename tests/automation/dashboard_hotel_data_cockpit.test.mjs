import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const dataHealthStatic = readFileSync('public/data-health-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataFragment = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const collectionReliabilityConcern = readFileSync('app/controller/concern/CollectionReliabilityConcern.php', 'utf8');
const businessDisplayConcern = readFileSync('app/controller/concern/BusinessDisplayConcern.php', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
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
  assert.match(onlinePage, /数据一键获取/);
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

test('online-data surface exposes the six-step operating loop and retains collection readiness tools', () => {
  assert.match(dataHealthPage, /data-testid="core-operations-loop"/);
  assert.match(dataHealthPage, /data-testid="core-loop-yesterday-data"/);
  assert.match(dataHealthPage, /data-testid="core-loop-competitor-comparison"/);
  assert.match(dataHealthPage, /data-testid="core-loop-anomaly-judgment"/);
  assert.match(dataHealthPage, /data-testid="core-loop-ai-actions"/);
  assert.match(dataHealthPage, /data-testid="core-loop-operation-tasks"/);
  assert.match(dataHealthPage, /data-testid="core-loop-next-day-review"/);
  assert.match(dataHealthPage, /refreshCoreOperationsLoop/);
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
  assert.match(appMain, /filter\(item => item\.truthStatus === 'verified'\)/);
  assert.doesNotMatch(metricCardSource, /sourceRows\s*>\s*0/);
  assert.doesNotMatch(metricCardSource, /totals\.(?:room_revenue|revenue|room_nights|adr|revpar)\s*\|\|\s*0/);
  assert.match(onlineDataFragment, /core-operations-metric-/);
  assert.match(onlineDataFragment, /core-operations-metric-calculation-status-/);
  assert.match(onlineDataFragment, /core-operations-metric-truth-status-/);
  assert.match(onlineDataFragment, /core-operations-metric-value-/);
  assert.match(onlineDataFragment, /core-operations-metric-truth-detail-/);
  assert.match(onlineDataFragment, /onlineTruthStatusText\(metric\.truth\)/);
  assert.match(onlineDataFragment, /onlineTruthStatusClass\(metric\.truth\)/);
  assert.match(onlineDataFragment, /onlineTruthDetailText\(metric\.truth\)/);
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
