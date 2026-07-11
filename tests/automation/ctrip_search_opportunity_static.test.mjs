import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/ctrip-search-opportunity-static.js', 'utf8');
const html = readFileSync('public/index.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');

const loadApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(source, context, { filename: 'public/ctrip-search-opportunity-static.js' });
  return context.window.SUXI_CTRIP_SEARCH_OPPORTUNITY_STATIC;
};

const fourScopePayload = () => ({
  status: 'ready',
  source_scope: 'ctrip_ota_channel',
  capture_date: '2026-07-11',
  captured_at: '2026-07-11 13:39:25',
  window_start_date: '2026-07-12',
  window_end_date: '2026-08-10',
  reference_capture_date: '2026-07-10',
  ingestion_methods: ['ctrip_cookie_api'],
  order_data_status: 'field_missing',
  dates: [
    {
      target_date: '2026-07-12',
      cumulative: {
        self: { pv: 0, uv: 0, conversion_rate: 11.76, order_count: null },
        competitor_avg: { pv: 312, uv: 205, conversion_rate: 3.15, order_count: null },
        self_reference: { pv: 66, uv: 51, conversion_rate: 11.76, order_count: null },
      },
      yesterday: {
        self: { pv: 3, uv: 3, conversion_rate: 0, order_count: null },
        competitor_avg: { pv: 7, uv: 5, conversion_rate: 7.52, order_count: null },
      },
    },
  ],
});

test('future search view preserves real zero values and missing order values', () => {
  const view = loadApi().buildView(fourScopePayload());
  assert.equal(view.status, 'ready');
  assert.equal(view.rows[0].windows.cumulative.self.pv, 0);
  assert.equal(view.rows[0].windows.cumulative.self.order_count, null);
  assert.equal(view.order_data_status, 'field_missing');
  assert.equal(view.summary.cumulative_self_pv, 0);
  assert.equal(view.rows[0].windows.cumulative.self_reference.pv, 66);
  assert.equal(view.reference_capture_date, '2026-07-10');
  assert.equal(view.summary.windows.cumulative.self_pv, 0);
  assert.equal(view.summary.windows.cumulative.competitor_pv, 312);
  assert.equal(view.summary.windows.yesterday.self_pv, 3);
  assert.equal(view.summary.windows.yesterday.competitor_pv, 7);
});

test('future search capture time is displayed in China local time', () => {
  assert.match(loadApi().formatCapturedAt('2026-07-11T12:38:17.374Z'), /2026-07-11 20:38:17/);
});

test('future search gap stays missing when competitor denominator is zero', () => {
  const api = loadApi();
  assert.equal(api.gapRate(10, 0), null);
  assert.equal(api.gapRate(0, 10), -100);
});

test('future search opportunity classification creates operational actions', () => {
  const api = loadApi();
  assert.equal(api.classifyOpportunity(
    { uv: 10, conversion_rate: 5 },
    { uv: 20, conversion_rate: 4 },
  ).key, 'traffic_opportunity');
  assert.equal(api.classifyOpportunity(
    { uv: 30, conversion_rate: 2 },
    { uv: 20, conversion_rate: 4 },
  ).key, 'conversion_repair');
  assert.equal(api.classifyOpportunity(
    { uv: 10, conversion_rate: 2 },
    { uv: 20, conversion_rate: 4 },
  ).key, 'double_low');
  assert.equal(api.classifyOpportunity(
    { uv: 30, conversion_rate: 5 },
    { uv: 20, conversion_rate: 4 },
  ).key, 'advantage_hold');
});

test('traffic tab exposes one-click four-scope search opportunity panel', () => {
  const trafficTabStart = html.indexOf('<!-- 流量数据获取 -->');
  const trafficTab = html.slice(
    trafficTabStart,
    html.indexOf('onlineDataTab === \'ctrip-ads\'', trafficTabStart),
  );
  assert.match(trafficTab, /data-testid="ctrip-search-opportunity-panel"/);
  assert.match(trafficTab, /获取流量数据/);
  assert.match(trafficTab, /@change="handleCtripTrafficHotelChange"/);
  assert.doesNotMatch(trafficTab, /一键获取流量（含未来搜索）|日期口径|临时 Cookie\/API 辅助内容|刷新已入库数据/);
  assert.match(html, /\/online-data\/ctrip\/search-opportunity\?system_hotel_id=/);
  assert.match(html, /fetchCtripTrafficAndSearchData/);
  assert.match(html, /runCtripTrafficManualCapture/);
  assert.doesNotMatch(trafficTab, /日常 Profile/);
  assert.match(trafficTab, /点击一次，直接获取累计\/昨日 × 我的酒店\/竞争圈四路数据/);
  assert.doesNotMatch(trafficTab, /bg-slate-800 text-white/);
  assert.equal((trafficTab.match(/bg-slate-900 text-white/g) || []).length, 2);
  assert.doesNotMatch(trafficTab, /max-h-96/);
  assert.match(trafficTab, /上次本店参考/);
  assert.match(trafficTab, /self_reference/);
  assert.match(trafficTab, /formatCtripSearchOpportunityCapturedAt/);
  assert.match(trafficTab, /我的 PV/);
  assert.match(trafficTab, /竞争圈 PV/);
  assert.match(trafficTab, /我的 UV/);
  assert.match(trafficTab, /竞争圈 UV/);
  assert.match(trafficTab, /我的转化率/);
  assert.match(trafficTab, /竞争圈转化率/);
  assert.doesNotMatch(trafficTab, /建议动作/);
  assert.match(trafficTab, /ctripSearchOpportunityActiveSummary/);
});

test('one-click traffic capture is manual Cookie API only and submits the trusted traffic preset', () => {
  const start = html.indexOf('const runCtripTrafficManualCapture');
  const end = html.indexOf('const ctripOverviewFetchActionMap', start);
  const manualCapture = html.slice(start, end);

  assert.ok(start > 0, 'manual traffic capture function must exist');
  assert.doesNotMatch(manualCapture, /runCtripBrowserCapture/);
  assert.match(manualCapture, /requestSource:\s*'traffic_report'/);
  assert.match(manualCapture, /cookieData\.is_ready !== false/);
  assert.match(manualCapture, /Number\(cookieData\.saved_count \|\| 0\) > 0/);
  assert.match(ctripStaticSource, /request_source:\s*String\(requestSource \|\| form\.requestSource \|\| ''\)\.trim\(\)/);
});
