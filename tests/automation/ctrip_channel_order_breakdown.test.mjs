import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');
const ctripTemplate = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const styleSource = readFileSync('public/style.css', 'utf8');
const dataHealthStaticSource = readFileSync('public/data-health-static.js', 'utf8');

const context = { window: {}, console };
vm.runInNewContext(ctripStaticSource, context, { filename: 'public/ctrip-static.js' });
const api = context.window.SUXI_CTRIP_STATIC;

test('Ctrip ecosystem orders derive an inclusive-cancellation total and distribution residual', () => {
  const result = api.buildCtripChannelOrderBreakdown({
    system_hotel_id: 80,
    platform_hotel_id: 'CTRIP-80',
    data_date: '2026-08-03',
    bookOrderNum: 74,
    totalDetailNum: 797,
    convertionRate: 5.40,
    qunarDetailVisitors: 308,
    qunarDetailCR: 7.47,
  });

  assert.equal(result.ctripOrders, 44);
  assert.equal(result.qunarOrders, 23);
  assert.equal(result.totalOrdersIncludingCancelled, 99);
  assert.equal(result.totalOrderConversionRatio, 0.75);
  assert.equal(result.ctripUndistributedOrders, 32);
  assert.equal(result.status, 'derived');
  assert.match(result.formulas.totalOrdersIncludingCancelled, /总平台订单 ÷ 0\.75/);
  assert.match(result.formulas.ctrip, /访客量 × 携程转化率/);
  assert.match(result.formulas.qunar, /四舍五入/);
  assert.match(result.formulas.qunar, /预订订单量之和 ÷ 详情页访客量之和/);
  assert.match(result.formulas.ctripUndistributed, /总订单（含取消）/);
  assert.match(result.sourceLabel, /总平台订单÷0\.75/);
  assert.match(result.sourceLabel, /同程艺龙和携程小程序以及其他分销渠道（含取消）/);
  assert.match(result.sourceLabel, /非平台返回明细/);
  assert.equal(result.provenance.totalOrdersIncludingCancelled.kind, 'derived');
  assert.equal(result.provenance.totalOrdersIncludingCancelled.scope, 'all_orders_including_cancelled_estimate');
  assert.equal(result.provenance.ctripOrders.kind, 'derived');
  assert.match(result.provenance.ctripOrders.caveat, /包含取消订单/);
  assert.equal(result.provenance.qunarOrders.kind, 'derived');
  assert.match(result.provenance.qunarOrders.caveat, /包含取消订单/);
  assert.match(result.provenance.qunarOrders.caveat, /不包含分销单、商旅单、机酒单和度假单/);
  assert.equal(result.provenance.ctripUndistributedOrders.kind, 'derived_residual_estimate');
  assert.equal(result.provenance.ctripUndistributedOrders.scope, 'inclusive_cancellation_residual_estimate');
  assert.equal(result.identity.source, 'ctrip');
  assert.equal(result.identity.systemHotelId, 80);
  assert.equal(result.identity.platformHotelId, 'CTRIP-80');
  assert.equal(result.identity.businessDate, '2026-08-03');
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
  assert.equal(attached.totalOrderIncludingCancelledEstimate, 72);
  assert.equal(attached.ctripUndistributedOrderEstimate, 24);
  assert.equal('tongchengDistributionOrderEstimate' in attached, false);
});

test('Ctrip channel order breakdown preserves missing state and adjusts only a negative hotel row', () => {
  const missing = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 74,
    totalDetailNum: 797,
    convertionRate: 5.40,
    qunarDetailVisitors: 308,
    qunarDetailCR: null,
  });
  assert.equal(missing.ctripOrders, 44);
  assert.equal(missing.qunarOrders, null);
  assert.equal(missing.totalOrdersIncludingCancelled, 99);
  assert.equal(missing.totalOrderConversionRatio, 0.75);
  assert.equal(missing.ctripUndistributedOrders, null);
  assert.equal(missing.ctripEstimateExcessOrders, null);
  assert.equal(missing.status, 'input_missing');
  assert.deepEqual([...missing.missingInputs], ['qunarRate']);
  assert.match(missing.sourceLabel, /未用 0 或旧数据补位/);

  const inclusiveResidual = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 64,
    totalDetailNum: 100,
    convertionRate: 30,
    qunarDetailVisitors: 100,
    qunarDetailCR: 42,
  });
  assert.equal(inclusiveResidual.totalOrdersIncludingCancelled, 85);
  assert.equal(inclusiveResidual.totalOrderConversionRatio, 0.75);
  assert.equal(inclusiveResidual.ctripOrders, 30);
  assert.equal(inclusiveResidual.qunarOrders, 42);
  assert.equal(inclusiveResidual.ctripUndistributedOrders, 13);
  assert.equal(inclusiveResidual.ctripEstimateExcessOrders, 0);
  assert.equal(inclusiveResidual.status, 'derived');
  assert.equal(inclusiveResidual.displayLabel, '同程及分销推算 +13 单');

  const negativeResidual = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 30,
    totalDetailNum: 100,
    convertionRate: 30,
    qunarDetailVisitors: 100,
    qunarDetailCR: 42,
  });
  assert.equal(negativeResidual.totalOrdersIncludingCancelled, 43);
  assert.equal(negativeResidual.totalOrderConversionRatio, 0.7);
  assert.equal(negativeResidual.ctripUndistributedOrders, -29);
  assert.equal(negativeResidual.ctripEstimateExcessOrders, 29);
  assert.equal(negativeResidual.displayLabel, '同程及分销推算 -29 单');

  const fallbackTo0725 = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 50,
    totalDetailNum: 100,
    convertionRate: 30,
    qunarDetailVisitors: 100,
    qunarDetailCR: 38,
  });
  assert.equal(fallbackTo0725.totalOrdersIncludingCancelled, 69);
  assert.equal(fallbackTo0725.totalOrderConversionRatio, 0.725);
  assert.equal(fallbackTo0725.ctripUndistributedOrders, 1);

  const fallbackTo07 = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 50,
    totalDetailNum: 100,
    convertionRate: 30,
    qunarDetailVisitors: 100,
    qunarDetailCR: 40,
  });
  assert.equal(fallbackTo07.totalOrdersIncludingCancelled, 71);
  assert.equal(fallbackTo07.totalOrderConversionRatio, 0.7);
  assert.equal(fallbackTo07.ctripUndistributedOrders, 1);

  const unaffectedNextHotel = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 64,
    totalDetailNum: 100,
    convertionRate: 30,
    qunarDetailVisitors: 100,
    qunarDetailCR: 42,
  });
  assert.equal(unaffectedNextHotel.totalOrdersIncludingCancelled, 85);
  assert.equal(unaffectedNextHotel.totalOrderConversionRatio, 0.75);
  assert.equal(unaffectedNextHotel.ctripUndistributedOrders, 13);

  const visibleRowConflict = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 75,
    totalDetailNum: 738,
    convertionRate: 5.01,
    qunarDetailVisitors: 599,
    qunarDetailCR: 7.85,
  });
  assert.equal(visibleRowConflict.ctripOrders, 37);
  assert.equal(visibleRowConflict.qunarOrders, 47);
  assert.equal(visibleRowConflict.totalOrdersIncludingCancelled, 100);
  assert.equal(visibleRowConflict.ctripUndistributedOrders, 16);
  assert.equal(visibleRowConflict.ctripEstimateExcessOrders, 0);
  assert.equal(visibleRowConflict.displayLabel, '同程及分销推算 +16 单');

  const zero = api.buildCtripChannelOrderBreakdown({
    bookOrderNum: 0,
    totalDetailNum: 0,
    convertionRate: 0,
    qunarDetailVisitors: 0,
    qunarDetailCR: 0,
  });
  assert.deepEqual(
    [zero.totalOrdersIncludingCancelled, zero.ctripOrders, zero.qunarOrders, zero.ctripUndistributedOrders],
    [0, 0, 0, 0],
  );
  assert.equal(zero.totalOrderConversionRatio, 0.75);

  const screenshotLikeRow = api.attachCtripChannelOrderBreakdown({
    bookOrderNum: 16,
    totalDetailNum: 0,
    convertionRate: 0,
    qunarDetailVisitors: 0,
    qunarDetailCR: 0,
  });
  assert.equal(screenshotLikeRow.ctripOrderEstimate, 0);
  assert.equal(screenshotLikeRow.qunarOrderEstimate, 0);
  assert.equal(screenshotLikeRow.totalOrderIncludingCancelledEstimate, 21);
  assert.equal(screenshotLikeRow.ctripUndistributedOrderEstimate, 21);
  assert.match(screenshotLikeRow.channelOrderBreakdownMeta.sourceLabel, /总订单（含取消）按总平台订单÷0\.75/);
});

test('Ctrip and Qunar order estimates stay unavailable during the 00:00-08:00 update window', () => {
  const row = {
    bookOrderNum: 16,
    totalDetailNum: 0,
    convertionRate: 0,
    qunarDetailVisitors: 0,
    qunarDetailCR: 0,
  };
  const beforeEight = api.attachCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T07:59:00+08:00',
    dataDate: '2026-08-03',
    targetDataDate: '2026-08-03',
    fetchedAt: '2026-08-03T07:59:00+08:00',
  });
  assert.equal(beforeEight.ctripOrderEstimate, null);
  assert.equal(beforeEight.qunarOrderEstimate, null);
  assert.equal(beforeEight.ctripUndistributedOrderEstimate, null);
  assert.equal(beforeEight.totalOrderIncludingCancelledEstimate, 21);
  assert.equal(beforeEight.channelOrderBreakdownMeta.status, 'traffic_pending_window');
  assert.equal(beforeEight.channelOrderBreakdownMeta.displayLabel, '00:00–08:00 数据待更新，暂不可推算');
  assert.equal(beforeEight.bookOrderNum, 16);

  const atMidnight = api.buildCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T00:00:00+08:00',
    dataDate: '2026-08-03',
    targetDataDate: '2026-08-03',
    fetchedAt: '2026-08-03T00:00:00+08:00',
  });
  assert.equal(atMidnight.status, 'traffic_pending_window');
  assert.deepEqual([atMidnight.totalOrdersIncludingCancelled, atMidnight.ctripOrders, atMidnight.qunarOrders, atMidnight.ctripUndistributedOrders], [21, null, null, null]);

  const staleEarlySnapshot = api.buildCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T09:00:00+08:00',
    dataDate: '2026-08-03',
    targetDataDate: '2026-08-03',
    fetchedAt: '2026-08-03T02:00:00+08:00',
  });
  assert.equal(staleEarlySnapshot.status, 'traffic_pending_window');
  assert.equal(staleEarlySnapshot.ctripUndistributedOrders, null);

  const missingFetchTime = api.buildCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T09:00:00+08:00',
    dataDate: '2026-08-03',
    targetDataDate: '2026-08-03',
  });
  assert.equal(missingFetchTime.status, 'traffic_pending_window');
  assert.equal(missingFetchTime.estimateAvailability.reason, 'snapshot_fetch_time_missing');

  const atEight = api.buildCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T08:00:00+08:00',
    dataDate: '2026-08-03',
    targetDataDate: '2026-08-03',
    fetchedAt: '2026-08-03T08:00:00+08:00',
  });
  assert.equal(atEight.status, 'derived');
  assert.deepEqual([atEight.totalOrdersIncludingCancelled, atEight.ctripOrders, atEight.qunarOrders, atEight.ctripUndistributedOrders], [21, 0, 0, 21]);

  const historical = api.buildCtripChannelOrderBreakdown(row, {
    enforceUpdateWindow: true,
    now: '2026-08-03T02:00:00+08:00',
    dataDate: '2026-08-02',
    targetDataDate: '2026-08-02',
    fetchedAt: '2026-08-03T02:00:00+08:00',
  });
  assert.equal(historical.status, 'derived');
});

test('Ctrip tables group order, traffic, and review columns by business meaning', () => {
  const salesStart = ctripTemplate.indexOf('<!-- 销售与订单表格 -->');
  const trafficStart = ctripTemplate.indexOf('<!-- 流量与转化表格 -->');
  const trafficEnd = ctripTemplate.indexOf('<!-- 榜单与排名表格 -->', trafficStart);
  const rankEnd = ctripTemplate.indexOf('</table>', trafficEnd);
  const salesTable = ctripTemplate.slice(salesStart, trafficStart);
  const trafficTable = ctripTemplate.slice(trafficStart, trafficEnd);
  const rankTable = ctripTemplate.slice(trafficEnd, rankEnd);

  assert.equal((ctripTemplate.match(/ctrip-sales-table-wrap/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-sales-groups"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-rank-groups"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-rank-head"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-rank-table/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/榜单与排名/g) || []).length, 4);
  assert.equal((ctripTemplate.match(/v-for="group in ctripSalesColumnGroups"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/v-for="column in ctripSalesMetricColumns"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/ctripTrafficChannelCellTitle\(hotel, column\)/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-sales-state">缺来源/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/<div :title="hotel\.hotelName">\{\{ hotel\.hotelName \}\}<\/div>/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/携程 eBooking 数据基石/g) || []).length, 1);
  assert.match(ctripTemplate, /class="ctrip-workspace-tabs[^\"]*flex-nowrap[^\"]*overflow-x-auto/);
  assert.match(ctripTemplate, /class="ctrip-workspace-status[^\"]*grid-cols-2[^\"]*xl:grid-cols-4/);
  assert.match(ctripTemplate, /class="ctrip-ranking-command-bar[^\"]*lg:flex-row/);
  assert.equal((ctripTemplate.match(/data-testid="ctrip-ranking-fetch-button"/g) || []).length, 1);
  assert.equal((ctripTemplate.match(/data-testid="ctrip-ranking-fetch-status"/g) || []).length, 1);
  assert.match(ctripTemplate, /data-testid="ctrip-ranking-fetch-status"[\s\S]*获取成功[\s\S]*已保存 \{\{ ctripSavedCount \}\} 条数据到数据库/);
  assert.equal((ctripTemplate.match(/data-testid="ctrip-business-download-button"/g) || []).length, 1);
  assert.equal((ctripTemplate.match(/data-testid="ctrip-business-table-toolbar"/g) || []).length, 1);
  assert.match(ctripTemplate, /data-testid="ctrip-business-table-toolbar"[\s\S]*data-testid="ctrip-business-table-tabs"[\s\S]*榜单与排名[\s\S]*data-testid="ctrip-business-download-button"/);
  assert.doesNotMatch(ctripTemplate, /目标日期数据已保存/);
  assert.doesNotMatch(ctripTemplate, />当前酒店配置</);
  assert.match(salesTable, /v-for="column in ctripSalesOrderColumns"/);
  assert.doesNotMatch(salesTable, /携程点评分|去哪儿点评分/);
  assert.doesNotMatch(salesTable, /平均房价指数\(ARI\)|综合竞争力指数\(SCI\)|hotel\.ariText|hotel\.sciText/);
  assert.equal((ctripTemplate.match(/<td v-for="column in ctripSalesOrderColumns"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/font-mono text-xs font-semibold text-gray-500/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/<td class="px-3 py-2 border text-center font-semibold">\{\{ formatOptionalNumber\(hotel\.bookOrderNum\) \}\}<\/td>/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/:class="\[ctripTrafficChannelCellClass\(hotel, column\), 'font-semibold'\]"/g) || []).length, 2);

  assert.match(rankTable, /竞争力[\s\S]*榜单排名[\s\S]*平均房价指数\(ARI\)[\s\S]*综合竞争力指数\(SCI\)/);
  assert.match(rankTable, /sortCtripTable\('ari'\)[\s\S]*sortCtripTable\('sci'\)/);
  assert.match(rankTable, /hotel\.ariText[\s\S]*hotel\.sciText/);

  const orderedLabels = ['携程APP访客量', '携程转化率', 'ctripTrafficChannelColumns', '预订转化率', '携程点评分', '去哪儿点评分'];
  let previous = -1;
  orderedLabels.forEach((label) => {
    const position = trafficTable.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding column`);
    previous = position;
  });
  assert.match(trafficTable, /v-for="column in ctripTrafficChannelColumns"/);
  assert.doesNotMatch(trafficTable, /ctripSalesOrderColumns/);
  assert.equal((trafficTable.match(/sortCtripTable\('commentScore'\)/g) || []).length, 1);
  assert.equal((trafficTable.match(/sortCtripTable\('qunarCommentScore'\)/g) || []).length, 1);
  assert.equal((ctripTemplate.match(/formatOptionalNumber\(hotel\.commentScore\)/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/formatOptionalNumber\(hotel\.qunarCommentScore\)/g) || []).length, 2);

  const salesColumnStart = appMain.indexOf('const ctripSalesOrderColumns');
  const salesColumnEnd = appMain.indexOf('const ctripTrafficChannelColumns', salesColumnStart);
  const salesColumnDefinitions = appMain.slice(salesColumnStart, salesColumnEnd);
  previous = -1;
  ['携程APP订单(含取消)', '去哪儿订单(含取消)', '同程等渠道'].forEach((label) => {
    const position = salesColumnDefinitions.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding derived column`);
    previous = position;
  });
  const groupDefinitions = appMain.slice(
    appMain.indexOf('const ctripSalesColumnGroups', salesColumnStart),
    appMain.indexOf('const ctripSalesMetricColumns', salesColumnStart),
  );
  assert.match(groupDefinitions, /大数据抓取[\s\S]*span: 4[\s\S]*AI推理[\s\S]*span: 4/);
  assert.doesNotMatch(groupDefinitions, /AI预计/);
  assert.doesNotMatch(groupDefinitions, /竞争力/);
  const metricDefinitions = appMain.slice(
    appMain.indexOf('const ctripSalesMetricColumns', salesColumnStart),
    salesColumnEnd,
  );
  previous = -1;
  ['离店销售额', '离店间夜', '平均卖价', '总平台订单', '总订单（含取消）', 'ctripSalesOrderColumns'].forEach((label) => {
    const position = metricDefinitions.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding sales metric`);
    previous = position;
  });
  assert.match(metricDefinitions, /field: 'totalOrderIncludingCancelledEstimate'/);
  assert.match(metricDefinitions, /总平台订单 ÷ 0\.75[\s\S]*0\.725[\s\S]*0\.7/);
  assert.doesNotMatch(metricDefinitions, /field: 'fullChannelRoomNightsEstimate'/);
  assert.doesNotMatch(metricDefinitions, /平均房价指数\(ARI\)|综合竞争力指数\(SCI\)/);
  const trafficColumnStart = appMain.indexOf('const ctripTrafficChannelColumns', salesColumnEnd);
  const trafficColumnEnd = appMain.indexOf('const ctripTrafficChannelText', trafficColumnStart);
  const trafficColumnDefinitions = appMain.slice(trafficColumnStart, trafficColumnEnd);
  previous = -1;
  ['去哪儿访客', '去哪儿转化率'].forEach((label) => {
    const position = trafficColumnDefinitions.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding traffic column`);
    previous = position;
  });
  assert.doesNotMatch(trafficColumnDefinitions, /label: '(?:携程订单|去哪儿订单|同程)'/);

  const downloadStart = appMain.indexOf('const ctripDownloadRows');
  const trafficDownloadStart = appMain.indexOf("title: '流量与转化'", downloadStart);
  const rankDownloadStart = appMain.indexOf("title: '榜单与排名'", trafficDownloadStart);
  const salesDownloadStart = appMain.indexOf("title: '销售与订单'", rankDownloadStart);
  const downloadEnd = appMain.indexOf('const buildCtripBusinessCanvas', salesDownloadStart);
  const trafficDownload = appMain.slice(trafficDownloadStart, rankDownloadStart);
  const rankDownload = appMain.slice(rankDownloadStart, salesDownloadStart);
  const salesDownload = appMain.slice(salesDownloadStart, downloadEnd);
  assert.match(trafficDownload, /label: '携程点评分'/);
  assert.match(trafficDownload, /label: '去哪儿点评分'/);
  assert.doesNotMatch(trafficDownload, /label: '(?:携程订单|去哪儿订单|同程)'/);
  assert.match(rankDownload, /label: '平均房价指数\(ARI\)'/);
  assert.match(rankDownload, /label: '综合竞争力指数\(SCI\)'/);
  assert.match(salesDownload, /label: '携程APP订单\(含取消\)'/);
  assert.match(salesDownload, /label: '去哪儿订单\(含取消\)'/);
  assert.match(salesDownload, /label: '离店间夜'/);
  assert.match(salesDownload, /label: '平均卖价'/);
  assert.match(salesDownload, /label: '总平台订单'/);
  assert.match(salesDownload, /label: '同程艺龙和携程小程序以及其他分销渠道（含取消）'/);
  assert.match(salesDownload, /label: '总订单（含取消）'/);
  previous = -1;
  ['总平台订单', '总订单（含取消）', '携程APP订单(含取消)', '去哪儿订单(含取消)', '同程艺龙和携程小程序以及其他分销渠道（含取消）'].forEach((label) => {
    const position = salesDownload.indexOf(label);
    assert.ok(position > previous, `${label} should follow the preceding exported sales column`);
    previous = position;
  });
  assert.doesNotMatch(salesDownload, /label: '(?:平均房价|携程间夜|携程离店间夜量|携程系预订订单数|同程订单数|总间夜|全渠道间夜推算)'/);
  assert.doesNotMatch(salesDownload, /label: '(?:携程点评分|去哪儿点评分)'/);

  assert.match(appMain, /channelOrderBreakdownMeta/);
  assert.match(appMain, /formatOptionalNumber\(-Math\.abs\(excessOrders\)\)/);
  assert.match(appMain, /const ctripTrafficChannelSecondaryText[\s\S]*?\? '口径冲突'/);
  const secondaryTextDefinition = appMain.slice(
    appMain.indexOf('const ctripTrafficChannelSecondaryText'),
    appMain.indexOf('const ctripTrafficChannelCellClass'),
  );
  assert.doesNotMatch(secondaryTextDefinition, /口径差值/);
  assert.equal((ctripTemplate.match(/ctripTrafficChannelSecondaryText\(hotel, column\)/g) || []).length, 4);
  assert.match(salesColumnDefinitions, /非平台返回订单明细/);
  assert.match(salesColumnDefinitions, /统计周期内预订订单量之和 ÷ 详情页访客量之和/);
  assert.match(salesColumnDefinitions, /label: '同程等渠道'/);
  assert.match(salesColumnDefinitions, /title: '同程艺龙和携程小程序以及其他分销渠道（含取消）'/);
  assert.doesNotMatch(salesColumnDefinitions, /同程等渠道（含取消）＝/);
  assert.doesNotMatch(salesColumnDefinitions, /携程订单（推算）|去哪儿订单（推算）|同程及其他分销渠道订单（差额推算）/);
  assert.match(appMain, /attachCtripChannelOrderBreakdown/);
  assert.equal((ctripTemplate.match(/hotel\.totalOrderIncludingCancelledEstimate/g) || []).length, 6);
  assert.doesNotMatch(ctripTemplate, /hotel\.fullChannelRoomNightsEstimate/);
  assert.match(appMain, /enforceUpdateWindow: !usesLastValidTraffic/);
  assert.match(appMain, /const ctripEarlyMorningTrafficText/);
  assert.match(appMain, /const ctripEarlyMorningTrafficNote/);
  assert.equal((ctripTemplate.match(/ctripEarlyMorningSourceNotice/g) || []).length, 4);
  assert.equal((ctripTemplate.match(/ctripEarlyMorningTrafficText\(hotel, 'totalDetailNum'\)/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/ctripEarlyMorningTrafficNote\(hotel,/g) || []).length, 0);
  assert.doesNotMatch(ctripTemplate, /上次有效值/);
  assert.match(appMain, /orderEstimateFetchedAt: snapshotModel\.rankFetchedAt/);
  assert.match(ctripStaticSource, /orderEstimateFetchedAt: data\.fetched_at/);
  assert.doesNotMatch(appMain, /同程订单（推算）/);
  assert.doesNotMatch(appMain, /tongchengDistributionOrderEstimate/);
  assert.doesNotMatch(ctripStaticSource, /tongchengDistributionOrders/);
  assert.doesNotMatch(appMain, /`≈ \$\{formatOptionalNumber\(value\)\}`/);
  assert.equal((ctripTemplate.match(/class="ctrip-sales-table[^\n]+style="width:100%;table-layout:fixed"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/class="ctrip-rank-table[^\n]+style="width:100%;table-layout:fixed"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/style="width:96px">酒店ID/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/style="width:96px">\{\{ hotel\.hotelId \}\}/g) || []).length, 2);
  assert.doesNotMatch(ctripTemplate, /min-width:1470px/);
  assert.equal((ctripTemplate.match(/style="position:sticky;left:0;width:54px;z-index:5"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/style="position:sticky;left:54px;width:248px;z-index:5">酒店名称/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/style="position:sticky;left:54px;width:248px;z-index:2;background:inherit"/g) || []).length, 2);
  assert.equal((ctripTemplate.match(/hasDisplayValue\(hotel\.(?:amount|adr)\) \? formatNumber\(Math\.round\(Number\(hotel\.(?:amount|adr)\)\)\) : '-'/g) || []).length, 4);
  assert.doesNotMatch(salesTable, /'¥' \+ formatNumber\(Math\.round\(Number\(hotel\.(?:amount|adr)\)\)\)/);
  assert.match(styleSource, /\.suxi-app-shell main \.ctrip-sales-head > th[\s\S]*?\.ctrip-rank-head > th[\s\S]*?white-space: normal[\s\S]*?overflow-wrap: anywhere/);
  assert.match(styleSource, /\.ctrip-sales-table :where\(th, td\)[\s\S]*?border-right: 1px solid #cfd9e5 !important/);
  assert.match(styleSource, /\.ctrip-sales-table tbody td \{[\s\S]*?text-align: center !important/);
  assert.match(styleSource, /\.ctrip-sales-table \.ctrip-sales-head > th:nth-child\(5\)[\s\S]*?\.ctrip-sales-table tbody td:nth-child\(8\)[\s\S]*?border-left: 1px solid #bcc8d5 !important/);
  assert.doesNotMatch(styleSource, /\.ctrip-sales-table \.ctrip-sales-head > th:nth-child\(4\)[\s\S]*?\.ctrip-sales-table tbody td:nth-child\(7\)/);
  assert.match(styleSource, /\.ctrip-sales-table \{[\s\S]*?min-width: 1380px;[\s\S]*?table-layout: auto !important/);
  assert.match(styleSource, /@media \(max-width: 1100px\)[\s\S]*?\.ctrip-sales-table \{ min-width: 1380px; \}[\s\S]*?\.ctrip-rank-table \{ min-width: 1040px; \}/);
  assert.match(styleSource, /\.ctrip-sales-table tbody td:nth-child\(2\) > div[\s\S]*?-webkit-line-clamp: 2/);
});

test('one-click Ctrip fetch keeps the separate Qunar retry window and bounded retry policy', () => {
  const fetchStart = appMain.indexOf('const runManualOneClickFetchForHotel = async');
  const fetchEnd = appMain.indexOf('const restoreManualOneClickFetchSelection', fetchStart);
  const fetchSource = appMain.slice(fetchStart, fetchEnd);
  assert.match(fetchSource, /manualOneClickFetchQunarVisitorNeedsRetry\(ctripQunarQuality\)/);
  assert.match(fetchSource, /manualOneClickFetchQunarAutoRetryAllowedAt\(\)/);
  assert.match(fetchSource, /CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT/);
  assert.match(dataHealthStaticSource, /00:00–05:59 去哪儿流量不可用时段/);
  assert.match(dataHealthStaticSource, /06:00 后可重新补采/);
});

test('current Ctrip fetch also retries a Qunar visitor gap and keeps the retry bounded', () => {
  const fetchStart = appMain.indexOf('const fetchCtripData = async');
  const fetchEnd = appMain.indexOf('// 美团ebooking数据获取', fetchStart);
  const fetchSource = appMain.slice(fetchStart, fetchEnd);
  assert.match(fetchSource, /manualOneClickFetchQunarVisitorNeedsRetry\(qunarQuality\(\)\)/);
  assert.match(fetchSource, /manualOneClickFetchQunarAutoRetryAllowedAt\(\)/);
  assert.match(fetchSource, /qunarRetryCount < CTRIP_QUNAR_VISITOR_AUTO_RETRY_LIMIT/);
  assert.match(fetchSource, /已标记为数据不足/);
  assert.match(dataHealthStaticSource, /quality\?\.rowCount \?\? quality\?\.row_count/);
  assert.match(dataHealthStaticSource, /quality\?\.total \?\? quality\?\.visitor_total/);
});
