import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const historyPage = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const onlineDataPage = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const meituanPage = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const analyticsConcern = readFileSync('app/controller/concern/OnlineDataAnalyticsConcern.php', 'utf8');
const summaryConcern = readFileSync('app/controller/concern/OnlineDataSummaryConcern.php', 'utf8');
const qualityConcern = readFileSync('app/controller/concern/OnlineDataQualityConcern.php', 'utf8');
const trustStatusService = readFileSync('app/service/OnlineDataTrustStatusService.php', 'utf8');
const dataHealthStatic = readFileSync('public/data-health-static.js', 'utf8');

test('stored OTA data types remain selectable in history', () => {
  for (const option of [
    '<option value="ranking">排名数据</option>',
    '<option value="peer_rank">竞对榜单</option>',
    '<option value="search_keyword">搜索词</option>',
    '<option value="traffic_analysis">流量分析</option>',
    '<option value="traffic_forecast">未来预测</option>',
    '<option value="quality">服务质量</option>',
    '<option value="review">点评数据</option>',
    '<option value="order">订单数据</option>',
    '<option value="order_flow">订单流转</option>',
  ]) {
    assert.match(historyPage, new RegExp(option.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
});

test('online data tables expose persistence readback state', () => {
  assert.match(onlineDataPage, />入库状态</);
  assert.match(onlineDataPage, /onlineStorageStatusClass\(item\)/);
  assert.match(onlineDataPage, /onlineStorageStatusText\(item\)/);
  assert.match(dataHealthStatic, /item\?\.storage_status/);
  assert.match(dataHealthStatic, /item\?\.storage_status_label/);
  assert.match(dataHealthStatic, /未回读验证/);
  assert.match(onlineDataPage, /excluded_untrusted_count/);
});

test('analysis and Meituan stored-data views expose all real types and readback state', () => {
  for (const type of ['competitor', 'order', 'order_flow', 'ranking']) {
    assert.match(onlineDataPage, new RegExp(`<option value="${type}">`));
  }
  assert.match(meituanPage, /全部已存/);
  assert.match(meituanPage, /meituanDownloadData\.allRows/);
  assert.match(meituanPage, /onlineStorageStatusText\(item\)/);
  assert.match(analyticsConcern, /where\('readback_verified', 1\)/);
  assert.match(analyticsConcern, /blockingValidationStatuses/);
  assert.match(analyticsConcern, /excluded_untrusted_count/);
});

test('every OTA analysis number exposes a four-state truth envelope and full provenance', () => {
  for (const label of ['已验证', '部分数据', '未验证', '采集失败']) {
    assert.match(trustStatusService, new RegExp(`'${label}'`));
  }
  for (const key of ['system_hotel_id', 'platform', 'data_date', 'method', 'collected_at', 'readback_verified', 'failure_reason']) {
    assert.match(trustStatusService, new RegExp(`'${key}'`));
  }
  assert.match(trustStatusService, /metric_scope' => 'ota_channel'/);
  assert.match(trustStatusService, /不代表全酒店经营/);
  assert.match(qualityConcern, /truthEnvelope\(\$item, \$item\['field_fact_status'\]\)/);
  assert.match(analyticsConcern, /summarizeTruthEnvelopes/);
  assert.match(analyticsConcern, /truth_context/);
  assert.match(summaryConcern, /dailySummaryTruthUsable/);
  assert.match(summaryConcern, /summarizeTruthEnvelopes/);
  assert.match(summaryConcern, /'spend' => null/);
  assert.match(summaryConcern, /'avg_psi_score' => null/);

  assert.match(onlineDataPage, /data-testid="truth-metric"/);
  assert.match(onlineDataPage, />真实性凭证</);
  assert.match(onlineDataPage, /<online-truth-summary :truth="card\.truth"/);
  assert.match(onlineDataPage, /<online-truth-summary :truth="item"/);
  assert.match(dataHealthStatic, /OTA渠道数据，不代表全酒店经营/);
  assert.match(dataHealthStatic, /onlineTruthStatusText/);
  assert.match(dataHealthStatic, /onlineTruthDetailText/);
  assert.match(dataHealthStatic, /truth\.source\?\.methods/);
  assert.match(dataHealthStatic, /truth\.source\?\.table/);
  assert.match(dataHealthStatic, /onlineMetricTruthContext/);
  assert.match(dataHealthStatic, /if \(value === null \|\| value === undefined \|\| value === ''\) return '-';/);
  assert.match(dataHealthStatic, /buildOnlineAnalysisRowMetricCells/);
  assert.match(onlineDataPage, /buildOnlineAnalysisRowMetricCells\(item, formatNumber\)/);
  assert.match(onlineDataPage, /advertising\?\.truth_context/);
  assert.match(onlineDataPage, /service_quality\?\.truth_context/);
  assert.match(onlineDataPage, /analysisData\.summary\?\.truth_context/);
  assert.doesNotMatch(onlineDataPage, /hotel\.quantity \|\| 0/);
});

test('shared truth labels keep internal/manual evidence separate from OTA collection', () => {
  assert.match(dataHealthStatic, /internal:\s*'内部项目'/);
  assert.match(dataHealthStatic, /not_applicable:\s*'不适用'/);
  assert.match(dataHealthStatic, /user_input:\s*'人工录入（未外部验证）'/);
  assert.match(dataHealthStatic, /manual_project_tracking:\s*'内部人工跟踪'/);
  assert.match(dataHealthStatic, /not_applicable_internal_manual_tracking/);
  assert.match(dataHealthStatic, /不适用（内部人工记录，非采集数据）/);
  assert.match(dataHealthStatic, /hotel\?\.system_hotel_id \?\? hotel\?\.id \?\? hotel\?\.hotel_id/);
  assert.match(dataHealthStatic, /入库或回读数量未记录/);
  assert.match(dataHealthStatic, /scope_label:\s*'口径未记录'/);
});
