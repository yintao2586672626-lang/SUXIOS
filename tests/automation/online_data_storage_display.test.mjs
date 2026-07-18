import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const historyPage = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const onlineDataPage = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const meituanPage = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const analyticsConcern = readFileSync('app/controller/concern/OnlineDataAnalyticsConcern.php', 'utf8');
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
