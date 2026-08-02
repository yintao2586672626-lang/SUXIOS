import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const ctripTemplate = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');

const context = { window: {}, console };
vm.runInNewContext(ctripStaticSource, context, { filename: 'public/ctrip-static.js' });
const api = context.window.SUXI_CTRIP_STATIC;

test('Ctrip ecosystem orders derive without attributing the residual to Tongcheng', () => {
  const result = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 74,
    totalDetailNum: 797,
    convertionRate: 5.40,
    qunarDetailVisitors: 308,
    qunarDetailCR: 7.47,
  });

  assert.equal(result.ctripOrders, 44);
  assert.equal(result.qunarOrders, 24);
  assert.equal(result.ctripUndistributedOrders, 6);
  assert.equal(result.status, 'derived');
  assert.match(result.formulas.ctrip, /访客量 × 携程转化率/);
  assert.match(result.formulas.ctripUndistributed, /携程系预订订单/);
  assert.match(result.sourceLabel, /未接入同程订单数据源/);
  assert.equal('tongchengDistributionOrders' in result, false);

  const attached = api.attachCtripChannelOrderBreakdown({
    bookOrderNum: 54,
    totalDetailNum: 377,
    convertionRate: 5.57,
    qunarDetailVisitors: 247,
    qunarDetailCR: 10.93,
  });
  assert.equal(attached.ctripOrderEstimate, 21);
  assert.equal(attached.qunarOrderEstimate, 27);
  assert.equal(attached.ctripUndistributedOrderEstimate, 6);
  assert.equal('tongchengDistributionOrderEstimate' in attached, false);
});

test('Ctrip channel order breakdown preserves missing and conflicting states', () => {
  const missing = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 74,
    totalDetailNum: 797,
    convertionRate: 5.40,
    qunarDetailVisitors: 308,
    qunarDetailCR: null,
  });
  assert.equal(missing.ctripOrders, 44);
  assert.equal(missing.qunarOrders, null);
  assert.equal(missing.ctripUndistributedOrders, null);
  assert.equal(missing.status, 'input_missing');

  const conflict = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 10,
    totalDetailNum: 100,
    convertionRate: 9,
    qunarDetailVisitors: 100,
    qunarDetailCR: 9,
  });
  assert.equal(conflict.ctripOrders, 9);
  assert.equal(conflict.qunarOrders, 9);
  assert.equal(conflict.ctripUndistributedOrders, null);
  assert.equal(conflict.ctripEstimateExcessOrders, 8);
  assert.equal(conflict.status, 'ctrip_ecosystem_total_conflict');
  assert.equal(conflict.displayLabel, '携程系推算超出总订单 8 单');

  const visibleRowConflict = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 75,
    totalDetailNum: 738,
    convertionRate: 5.01,
    qunarDetailVisitors: 599,
    qunarDetailCR: 7.85,
  });
  assert.equal(visibleRowConflict.ctripOrders, 37);
  assert.equal(visibleRowConflict.qunarOrders, 48);
  assert.equal(visibleRowConflict.ctripEstimateExcessOrders, 10);
  assert.equal(visibleRowConflict.displayLabel, '携程系推算超出总订单 10 单');

  const zero = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 0,
    totalDetailNum: 0,
    convertionRate: 0,
    qunarDetailVisitors: 0,
    qunarDetailCR: 0,
  });
  assert.deepEqual(
    [zero.ctripOrders, zero.qunarOrders, zero.ctripUndistributedOrders],
    [0, 0, 0],
  );

  const screenshotLikeRow = api.attachCtripChannelOrderBreakdown({
    bookOrderNum: 16,
    totalDetailNum: 0,
    convertionRate: 0,
    qunarDetailVisitors: 0,
    qunarDetailCR: 0,
  });
  assert.equal(screenshotLikeRow.ctripOrderEstimate, 0);
  assert.equal(screenshotLikeRow.qunarOrderEstimate, 0);
  assert.equal(screenshotLikeRow.ctripUndistributedOrderEstimate, 16);
  assert.match(screenshotLikeRow.channelOrderBreakdownMeta.sourceLabel, /未接入同程订单数据源/);
});

test('Ctrip tables expose the renamed total and ordered derived channel columns', () => {
  assert.equal((ctripTemplate.match(/携程系预订订单/g) || []).length >= 2, true);
  assert.doesNotMatch(ctripTemplate, />携程预订订单 /);

  const trafficStart = ctripTemplate.indexOf('<!-- 流量与转化表格 -->');
  const trafficEnd = ctripTemplate.indexOf('<!-- 榜单排名表格 -->', trafficStart);
  const trafficTable = ctripTemplate.slice(trafficStart, trafficEnd);
  const orderedLabels = ['携程APP访客量', '携程转化率', 'ctripTrafficChannelColumns', '预订转化率'];
  let previous = -1;
  orderedLabels.forEach((label) => {
    const position = trafficTable.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding column`);
    previous = position;
  });
  assert.match(trafficTable, /v-for="column in ctripTrafficChannelColumns"/);

  const columnStart = appMain.indexOf('const ctripTrafficChannelColumns');
  const columnEnd = appMain.indexOf('const ctripTrafficChannelText', columnStart);
  const columnDefinitions = appMain.slice(columnStart, columnEnd);
  previous = -1;
  ['携程订单', '去哪儿访客', '去哪儿转化率', '去哪儿订单', '携程系未拆分订单（推算）'].forEach((label) => {
    const position = columnDefinitions.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding derived column`);
    previous = position;
  });
  assert.match(appMain, /channelOrderBreakdownMeta/);
  assert.match(appMain, /attachCtripChannelOrderBreakdown/);
  assert.doesNotMatch(appMain, /同程及分销渠道订单/);
  assert.doesNotMatch(appMain, /tongchengDistributionOrderEstimate/);
  assert.doesNotMatch(ctripStaticSource, /tongchengDistributionOrders/);
  assert.doesNotMatch(appMain, /`≈ \$\{formatOptionalNumber\(value\)\}`/);
});

test('direct Ctrip fetch reuses the existing Qunar quiet-hours and bounded retry policy', () => {
  const fetchStart = appMain.indexOf('const fetchCtripData = async (options = {}) => {');
  const fetchEnd = appMain.indexOf('// 美团ebooking数据获取', fetchStart);
  const fetchSource = appMain.slice(fetchStart, fetchEnd);
  assert.match(fetchSource, /manualOneClickFetchQunarVisitorNeedsRetry\(normalizedQuality\)/);
  assert.match(fetchSource, /manualOneClickFetchQunarAutoRetryAllowedAt\(\)/);
  assert.match(fetchSource, /CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT/);
  assert.match(fetchSource, /00:00–05:59 去哪儿数据正常待更新时段/);
  assert.match(fetchSource, /去哪儿数据为 0，正在自动补抓/);
});
