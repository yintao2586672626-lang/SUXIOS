import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { loadFrontendTemplateSource } from '../../scripts/lib/frontend_template_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const appMain = fs.readFileSync(path.join(repoRoot, 'public/app-main.js'), 'utf8');
const routes = fs.readFileSync(path.join(repoRoot, 'route/app.php'), 'utf8');
const template = loadFrontendTemplateSource(repoRoot).template;

const sliceBetween = (source, start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.ok(startIndex >= 0 && endIndex > startIndex, `missing source slice: ${start} -> ${end}`);
  return source.slice(startIndex, endIndex);
};

test('Meituan traffic analysis keeps the existing navigation and exposes the three temporal sections', () => {
  const meituanStart = template.indexOf('<div v-if="onlineDataTab === \'meituan-download\'">');
  assert.ok(meituanStart >= 0, 'missing Meituan download center');
  const meituanDownload = template.slice(meituanStart);
  const nav = sliceBetween(meituanDownload, '<!-- 子Tab导航 -->', '<!-- 筛选条件 -->');
  assert.match(nav, /switchDownloadTab\('overview'\)[\s\S]*同行榜单明细/);
  assert.match(nav, /switchDownloadTab\('traffic'\)[\s\S]*流量分析/);
  assert.equal((nav.match(/switchDownloadTab\('/g) || []).length, 5);

  const traffic = sliceBetween(meituanDownload, '<!-- 流量分析模块 -->', '<!-- 订单线索模块 -->');
  const trafficCopy = sliceBetween(appMain, 'const meituanTemporalCopy =', 'const meituanTemporalUiClass =');
  const futureFlow = sliceBetween(appMain, 'const normalizeMeituanFutureFlowRows =', 'let suxiApp = null;');
  const renderedContract = `${traffic}\n${trafficCopy}\n${futureFlow}`;
  for (const text of ['美团流量分析', '更新本页数据', '设置定时推送', '今日实时', '昨日复盘', '未来30天', '3天', '7天', '15天', '30天', '提前订订单量', '上次验证参考', '采集明细（原始存储记录）']) {
    assert.match(renderedContract, new RegExp(text));
  }
  assert.match(traffic, /<meituan-future-flow :rows="mtFutureRows"/);
  assert.match(futureFlow, /meituan-future-daily-chart/);
  assert.match(futureFlow, /meituan-future-daily-table/);
  assert.match(appMain, /app\.component\('MeituanFutureFlow', MeituanFutureFlow\)/);
  assert.match(renderedContract, /不代表全酒店经营数据/);
  assert.match(renderedContract, /— \/ 未返回/);
  assert.match(renderedContract, /不使用历史成功数据替代昨日当前值/);
  assert.match(renderedContract, /到点后先采集本店美团数据并完成数据库回读/);
  assert.match(renderedContract, /默认生成每日 09:15 的固定时间计划/);
  assert.doesNotMatch(renderedContract, /每 4 小时/);
});

test('Meituan future daily view sorts dates, limits horizons, and keeps zero distinct from missing', () => {
  const logic = sliceBetween(appMain, 'const normalizeMeituanFutureFlowRows =', 'const MeituanFutureFlow =');
  const sandbox = {};
  vm.runInNewContext(`${logic}
this.__api = {
  normalizeMeituanFutureFlowRows,
  meituanFutureFlowMetricValue,
  meituanFutureFlowMetricText,
};`, sandbox);
  const rows = [
    {
      target_date: '2026-08-02',
      metrics: {
        pv: { value: 0, status: 'verified' },
        pv_peer_avg: { value: null, status: 'missing' },
      },
    },
    {
      target_date: '2026-07-31',
      metrics: {
        pv: { value: 40, status: 'verified' },
        pv_peer_avg: { value: 9, status: 'verified' },
      },
    },
    { target_date: 'not-a-date', metrics: {} },
  ];

  const normalized = sandbox.__api.normalizeMeituanFutureFlowRows(rows, 3);
  assert.deepEqual(Array.from(normalized, row => row.target_date), ['2026-07-31', '2026-08-02']);
  assert.equal(sandbox.__api.meituanFutureFlowMetricValue(rows[0], 'pv'), 0);
  assert.equal(sandbox.__api.meituanFutureFlowMetricValue(rows[0], 'pv', true), null);
  assert.equal(sandbox.__api.meituanFutureFlowMetricText(0), '0');
  assert.equal(sandbox.__api.meituanFutureFlowMetricText(null), '— / 未返回');
});

test('Meituan temporal UI calls only the dedicated summary and manual refresh endpoints', () => {
  const summaryLoader = sliceBetween(appMain, 'const loadMeituanTemporalSummary = async', 'const refreshMeituanTemporal = async');
  const refresher = sliceBetween(appMain, 'const refreshMeituanTemporal = async', 'const meituanTemporalMetricText =');
  assert.match(summaryLoader, /\/online-data\/meituan-temporal-summary/);
  assert.match(summaryLoader, /system_hotel_id/);
  assert.match(summaryLoader, /as_of_date/);
  assert.match(refresher, /\/online-data\/meituan-temporal-refresh/);
  assert.match(refresher, /loadMeituanTemporalSummary\(\{ force: true \}\)/);
  assert.match(refresher, /loadOnlineDataList\(\{ force: true \}\)/);
  assert.doesNotMatch(refresher, /timer|wechat|wecom|schedule/i);
  assert.match(routes, /Route::get\('\/meituan-temporal-summary'/);
  assert.match(routes, /Route::post\('\/meituan-temporal-refresh'/);
});

test('Meituan schedule shortcut creates a disabled daily fixed-time WeCom preset', () => {
  const opener = sliceBetween(
    appMain,
    'const openMeituanTemporalSchedule = async',
    'const previewManualNotification = async'
  );
  assert.match(opener, /currentPage\.value = 'wechat-notification'/);
  assert.match(opener, /source_scope:\s*'meituan'/);
  assert.match(opener, /\['meituan_traffic', 'meituan_conversion'\]/);
  assert.match(opener, /send_method:\s*'wecom_formal'/);
  assert.match(opener, /trigger_type:\s*'daily_fixed_time'/);
  assert.match(opener, /planned_send_at:\s*`\$\{operationToday\}T09:15`/);
  assert.doesNotMatch(opener, /trigger_type:\s*'interval_minutes'/);
  assert.match(opener, /hourly_start_time:\s*'09:15'/);
  assert.match(opener, /enabled:\s*false/);
  assert.match(opener, /applyManualNotificationRecord\(existing\)/);
});

test('current values and last verified references remain separate', () => {
  const cards = sliceBetween(appMain, 'const buildMeituanTemporalCards =', 'const meituanTemporalSourceMessage =');
  assert.match(cards, /section\?\.metrics/);
  assert.match(cards, /latest_verified_reference/);
  assert.match(cards, /filter\(section => !!section\.reference\)/);
  assert.doesNotMatch(cards, /section\?\.metrics\s*\|\|\s*section\?\.latest_verified_reference/);
  assert.match(appMain, /traffic: \['business', 'traffic', 'traffic_analysis', 'traffic_forecast'\]/);
});

test('peer ranking remains routed and rendered by its existing isolated path', () => {
  assert.match(appMain, /overview: \['peer_rank'\]/);
  const peer = sliceBetween(template, '<!-- 同行榜单入库明细 -->', '<!-- 流量分析模块 -->');
  assert.match(peer, /meituanDownloadData\.overviewRows/);
  assert.match(peer, /meituanDownloadData\.overviewRowsCount/);
  assert.match(peer, /sort|榜单名次|平台百分比/);
});
