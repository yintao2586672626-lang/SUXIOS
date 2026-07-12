import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/ctrip-search-opportunity-static.js', 'utf8');
const html = readFileSync('public/index.html', 'utf8');
const ctripStaticSource = readFileSync('public/ctrip-static.js', 'utf8');

test('future search helper cache version follows the current helper content', () => {
  const hash = createHash('sha256').update(source).digest('hex').slice(0, 10);
  const version = html.match(/<script\s+src="ctrip-search-opportunity-static\.js\?v=([^"]+)"/)?.[1] || '';

  assert.match(version, new RegExp(`h${hash}`));
});

const loadApi = () => {
  const context = { window: {}, console };
  vm.runInNewContext(source, context, { filename: 'public/ctrip-search-opportunity-static.js' });
  return context.window.SUXI_CTRIP_SEARCH_OPPORTUNITY_STATIC;
};

test('future search axis uses orderly adaptive ticks for rates and counts', () => {
  const { buildAxisScale } = loadApi();

  assert.deepEqual(Array.from(buildAxisScale(4.94, 'conversion_rate').ticks), [0, 1, 2, 3, 4, 5]);
  assert.deepEqual(Array.from(buildAxisScale(11.18, 'conversion_rate').ticks), [0, 3, 6, 9, 12]);
  assert.deepEqual(Array.from(buildAxisScale(319, 'pv').ticks), [0, 100, 200, 300, 400]);
  assert.deepEqual(Array.from(buildAxisScale(1500, 'pv').ticks), [0, 300, 600, 900, 1200, 1500]);
});

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
        self: { pv: 3, uv: 3, conversion_rate: 0, order_count: null, metric_status: 'derived_from_cumulative_delta' },
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
  assert.equal(view.rows[0].windows.cumulative.self.estimated_order_count, 0);
  assert.equal(view.rows[0].windows.cumulative.competitor_avg.estimated_order_count, 6.4575);
  assert.equal(view.rows[0].windows.yesterday.self.metric_status, 'derived_from_cumulative_delta');
});

test('future search view exposes 3, 7, 15 and 30 day cumulative horizons', () => {
  const payload = fourScopePayload();
  payload.dates = Array.from({ length: 20 }, (_, index) => ({
    target_date: `2026-07-${String(index + 12).padStart(2, '0')}`,
    cumulative: {
      self: { pv: 10, uv: 8, conversion_rate: 5, order_count: null },
      competitor_avg: { pv: 20, uv: 10, conversion_rate: 4, order_count: null },
    },
    yesterday: {
      self: { pv: 1, uv: 1, conversion_rate: 5, order_count: null },
      competitor_avg: { pv: 2, uv: 2, conversion_rate: 4, order_count: null },
    },
  }));

  const view = loadApi().buildView(payload);
  assert.equal(view.summary.horizons.three_day.self_pv, 30);
  assert.equal(view.summary.horizons.seven_day.self_pv, 70);
  assert.equal(view.summary.horizons.seven_day.competitor_pv, 140);
  assert.equal(view.summary.horizons.seven_day.pv_gap_rate, -50);
  assert.equal(view.summary.horizons.fifteen_day.self_pv, 150);
  assert.equal(view.summary.horizons.thirty_day.self_pv, 200);
  assert.equal(view.summary.horizons.thirty_day.competitor_pv, 400);
});

test('future search panel remains render-safe before its computed payload is ready', () => {
  const view = loadApi().buildView();
  for (const horizon of ['three_day', 'seven_day', 'fifteen_day', 'thirty_day']) {
    assert.equal(typeof view.summary.horizons[horizon], 'object');
    assert.ok(Object.hasOwn(view.summary.horizons[horizon], 'pv_gap_rate'));
  }

  const panelStart = html.indexOf('data-testid="ctrip-search-opportunity-panel"');
  const panel = html.slice(panelStart, html.indexOf('onlineDataTab === \'ctrip-ads\'', panelStart));
  assert.doesNotMatch(panel, /ctripSearchOpportunityHorizonSummary\.(?:pv_gap_rate|uv_gap_rate|conversion_gap|self_pv|competitor_pv|self_uv|competitor_uv|self_conversion|competitor_conversion|self_estimated_orders|competitor_estimated_orders|self_days|competitor_days)/);
  assert.match(panel, /ctripSearchOpportunityHorizonSummary\?\.pv_gap_rate/);
  assert.doesNotMatch(panel, /ctripSearchOpportunityView\.(?:capture_date|captured_at|status)/);
  assert.doesNotMatch(panel, /ctripSearchOpportunityActiveRange\.(?:start_date|end_date|day_count)/);
});

test('future search view keeps cumulative and yesterday date ranges separate', () => {
  const payload = fourScopePayload();
  payload.dates = [
    {
      target_date: '2026-07-11',
      cumulative: { self: { pv: 6 }, competitor_avg: { pv: 14 } },
    },
    {
      target_date: '2026-07-12',
      yesterday: { self: { pv: 3 }, competitor_avg: { pv: 10 } },
    },
  ];

  const view = loadApi().buildView(payload);
  assert.equal(JSON.stringify(view.window_ranges.cumulative), JSON.stringify({ start_date: '2026-07-11', end_date: '2026-07-11', day_count: 1 }));
  assert.equal(JSON.stringify(view.window_ranges.yesterday), JSON.stringify({ start_date: '2026-07-12', end_date: '2026-07-12', day_count: 1 }));
});

test('future search window range caps carried history plus current results to 30 days', () => {
  const payload = fourScopePayload();
  payload.dates = Array.from({ length: 31 }, (_, index) => {
    const target = new Date(Date.UTC(2026, 6, 12 + index)).toISOString().slice(0, 10);
    return {
      target_date: target,
      yesterday: { self: { pv: index }, competitor_avg: { pv: index + 1 } },
    };
  });

  const view = loadApi().buildView(payload);
  assert.equal(view.window_ranges.yesterday.start_date, '2026-07-12');
  assert.equal(view.window_ranges.yesterday.end_date, '2026-08-10');
  assert.equal(view.window_ranges.yesterday.day_count, 30);
});

test('future search capture time is displayed in China local time', () => {
  assert.match(loadApi().formatCapturedAt('2026-07-11T12:38:17.374Z'), /2026-07-11 20:38:17/);
});

test('future search gap stays missing when competitor denominator is zero', () => {
  const api = loadApi();
  assert.equal(api.gapRate(10, 0), null);
  assert.equal(api.gapRate(0, 10), -100);
});

test('future search comparisons state the direction and use percent-symbol wording', () => {
  const api = loadApi();

  assert.equal(api.formatRelativeComparison(100), '高 100.00%');
  assert.equal(api.formatRelativeComparison(-18.18), '低 18.18%');
  assert.equal(api.formatRelativeComparison(0), '持平');
  assert.equal(api.formatPercentagePointGap(7.78), '高 7.78%');
  assert.equal(api.formatPercentagePointGap(-4), '低 4.00%');
  assert.equal(api.formatPercentagePointGap(null), '-');
});

test('future search series legend toggles one series without hiding the last visible series', () => {
  const api = loadApi();
  assert.equal(JSON.stringify(api.toggleSeriesVisibility({ self: true, competitor_avg: true }, 'self')), JSON.stringify({
    self: false,
    competitor_avg: true,
  }));
  assert.equal(JSON.stringify(api.toggleSeriesVisibility({ self: false, competitor_avg: true }, 'self')), JSON.stringify({
    self: true,
    competitor_avg: true,
  }));
  assert.equal(JSON.stringify(api.toggleSeriesVisibility({ self: false, competitor_avg: true }, 'competitor_avg')), JSON.stringify({
    self: false,
    competitor_avg: true,
  }));
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
  assert.match(trafficTab, /ctripTrafficView === 'history'/);
  assert.match(trafficTab, /ctripTrafficView === 'realtime'/);
  assert.match(trafficTab, /ctripTrafficView === 'future'/);
  assert.match(trafficTab, />过去30天</);
  assert.match(trafficTab, />今日实时</);
  assert.match(trafficTab, />未来搜索</);
  assert.match(trafficTab, /暂无已验证的今日实时流量/);
  assert.match(ctripStaticSource, /traffic_rank.*seq_rank.*app_detail_uv_rank/s);
  assert.match(trafficTab, /获取流量数据/);
  assert.match(trafficTab, /@change="handleCtripTrafficHotelChange"/);
  assert.doesNotMatch(trafficTab, /一键获取流量（含未来搜索）|日期口径|临时 Cookie\/API 辅助内容|刷新已入库数据/);
  assert.match(html, /\/online-data\/ctrip\/search-opportunity\?system_hotel_id=/);
  assert.match(html, /fetchCtripTrafficAndSearchData/);
  assert.match(html, /runCtripTrafficManualCapture/);
  assert.doesNotMatch(trafficTab, /日常 Profile/);
  assert.match(trafficTab, /点击一次，直接获取累计\/昨日 × 我的酒店\/竞争圈四路数据/);
  assert.doesNotMatch(trafficTab, /bg-slate-800 text-white/);
  assert.equal((trafficTab.match(/bg-slate-900 text-white/g) || []).length, 12);
  assert.doesNotMatch(trafficTab, /max-h-96/);
  assert.doesNotMatch(trafficTab, /上次本店参考|历史参考不参与|当前仅有部分数据|订单量为缺失字段/);
  assert.doesNotMatch(trafficTab, /self_reference/);
  assert.match(trafficTab, /formatCtripSearchOpportunityCapturedAt/);
  assert.match(trafficTab, />7天</);
  assert.match(trafficTab, />3天</);
  assert.match(trafficTab, />15天</);
  assert.match(trafficTab, /30天/);
  assert.match(trafficTab, /ctripSearchOpportunityHorizon/);
  assert.match(trafficTab, /ctripSearchOpportunityHorizonLabel.*PV 差距/s);
  assert.match(trafficTab, /label: '预计订单'/);
  assert.match(trafficTab, /ctripSearchOpportunityHorizonLabel }}预计订单/);
  assert.doesNotMatch(trafficTab, /推算订单/);
  assert.match(trafficTab, /按UV×转化率估算/);
  assert.match(trafficTab, /data-series="self"/);
  assert.match(trafficTab, /data-series="competitor_avg"/);
  assert.doesNotMatch(trafficTab, /rounded-t bg-indigo-500|rounded-t bg-orange-500/);
  assert.match(trafficTab, /#4f46e5/);
  assert.match(trafficTab, /#f59e0b/);
  assert.equal((trafficTab.match(/w-2\/5/g) || []).length, 2);
  assert.doesNotMatch(trafficTab, /w-\[45%\]/);
  assert.match(trafficTab, /formatCtripSearchOpportunityValue\(ctripSearchOpportunityMetricValue\(row, 'self'\)/);
  assert.match(trafficTab, /formatCtripSearchOpportunityValue\(ctripSearchOpportunityMetricValue\(row, 'competitor_avg'\)/);
  assert.match(trafficTab, /toggleCtripSearchOpportunitySeries\('self'\)/);
  assert.match(trafficTab, /toggleCtripSearchOpportunitySeries\('competitor_avg'\)/);
  assert.match(trafficTab, /aria-pressed/);
  assert.match(trafficTab, /ctripSearchOpportunitySeriesVisibility\.self/);
  assert.match(trafficTab, /ctripSearchOpportunitySeriesVisibility\.competitor_avg/);
  assert.doesNotMatch(trafficTab, /建议动作/);
  assert.match(trafficTab, /ctripSearchOpportunityHorizonSummary/);
  assert.match(trafficTab, /本店.*self_days.*圈.*competitor_days/s);
  assert.match(trafficTab, /data-testid="ctrip-search-opportunity-value-legend"/);
  assert.match(trafficTab, /蓝\/绿数字：累计（本店 \/ 竞争圈）/);
  assert.match(trafficTab, /红色数字：昨日新增（本店 \/ 竞争圈）/);
  assert.match(trafficTab, /text-xs font-medium text-red-600/);
  assert.doesNotMatch(trafficTab, /text-sm font-semibold text-red-600/);
  assert.match(trafficTab, />日期</);
  assert.match(trafficTab, /累计 PV（本店 \/ 竞争圈）/);
  assert.match(trafficTab, /累计 UV（本店 \/ 竞争圈）/);
  assert.match(trafficTab, /累计转化率（本店 \/ 竞争圈）/);
  assert.doesNotMatch(trafficTab, /累计 我的 PV|累计 竞争圈 PV|累计 我的 UV|累计 竞争圈 UV/);
  assert.doesNotMatch(trafficTab, /h-10 shrink-0 flex flex-col items-center justify-center/);
  assert.doesNotMatch(trafficTab, /data-series="self"[^\n]*<span/);
  assert.doesNotMatch(trafficTab, /data-series="competitor_avg"[^\n]*<span/);
  assert.doesNotMatch(trafficTab, /-top-5/);
  assert.match(trafficTab, /data-testid="ctrip-search-opportunity-tooltip"/);
  assert.doesNotMatch(trafficTab, /v-if="ctripSearchOpportunityHorizonDays > 15"\s+data-testid="ctrip-search-opportunity-tooltip"/);
  assert.match(trafficTab, /group-hover:opacity-100/);
  assert.match(trafficTab, /ctripSearchOpportunityTooltipStyle\(row\)/);
  assert.match(trafficTab, /data-testid="ctrip-search-opportunity-axis"/);
  assert.match(trafficTab, /v-for="tick in ctripSearchOpportunityAxisTicks"/);
  assert.match(trafficTab, /w-12 shrink-0 pr-2 text-right text-\[10px\] font-medium tabular-nums/);
  assert.match(trafficTab, /formatCtripSearchOpportunityAxisTick\(tick\.value\)/);
  assert.doesNotMatch(trafficTab, /formatCtripSearchOpportunityValue\(tick\.value\)/);
  assert.match(html, /const ctripSearchOpportunityAxisTicks = computed/);
  assert.match(html, /buildCtripSearchOpportunityAxisScale/);
  assert.match(html, /ctripSearchOpportunityAxisScale\.value\.max/);
  assert.match(trafficTab, /v-for="row in ctripSearchOpportunityVisibleRows"/);
  assert.match(html, /ctripSearchOpportunityRows\.value\.slice\(0, ctripSearchOpportunityHorizonDays\.value\)/);
  assert.match(html, /Math\.min\(72, ratio \* 72\)/);
});

test('past traffic exposes preset and custom ranges and is hydrated by the one-click action', () => {
  const trafficTabStart = html.indexOf(`<div v-if="onlineDataTab === 'ctrip-traffic'">`);
  const trafficTab = html.slice(
    trafficTabStart,
    html.indexOf("onlineDataTab === 'ctrip-ads'", trafficTabStart),
  );
  const fetchStart = html.indexOf('const fetchCtripTrafficAndSearchData');
  const fetchEnd = html.indexOf('const handleCtripTrafficHotelChange', fetchStart);
  const fetchFlow = html.slice(fetchStart, fetchEnd);
  const hotelChangeStart = html.indexOf('const handleCtripTrafficHotelChange');
  const hotelChangeEnd = html.indexOf('const runCtripOverviewCoreFetchAction', hotelChangeStart);
  const hotelChangeFlow = html.slice(hotelChangeStart, hotelChangeEnd);

  assert.match(trafficTab, /data-testid="ctrip-history-range-controls"/);
  assert.match(trafficTab, /ctripTrafficForm\.dateRange = 'last_7_days'/);
  assert.match(trafficTab, /ctripTrafficForm\.dateRange = 'last_30_days'/);
  assert.match(trafficTab, /ctripTrafficForm\.dateRange = 'custom'/);
  assert.match(trafficTab, /data-testid="ctrip-history-start-date"/);
  assert.match(trafficTab, /data-testid="ctrip-history-end-date"/);
  assert.match(trafficTab, /data-testid="ctrip-history-fetch"/);
  assert.match(trafficTab, /ctripTrafficView === 'history' && !ctripTrafficRows\.length/);
  assert.match(trafficTab, /ctripTrafficView === 'history' && ctripTrafficRows\.length/);
  assert.doesNotMatch(trafficTab, /ctripTrafficView === 'history' && !onlineDataResult/);
  assert.match(fetchFlow, /await fetchCtripTrafficData\(\);/);
  assert.match(fetchFlow, /await runCtripOverviewFetchAction\('ctrip-traffic'\);/);
  assert.match(fetchFlow, /await loadCtripSearchOpportunity\(\);/);
  assert.match(fetchFlow, /await loadLatestCtripData\(\{[\s\S]*range:\s*'realtime'[\s\S]*hydrateDisplay:\s*false/);
  assert.match(html, /onlineDataResult\.value\?\.traffic\?\.rows/);
  assert.match(hotelChangeFlow, /ctripTrafficRows\.value = \[\];/);
  assert.match(hotelChangeFlow, /ctripTrafficSummary\.value = null;/);
  assert.match(hotelChangeFlow, /ctripTrafficAnalysis\.value = null;/);
});

test('traffic preset includes the verified current-hotel realtime rank endpoint', () => {
  const context = { window: {}, console };
  vm.runInNewContext(ctripStaticSource, context, { filename: 'public/ctrip-static.js' });
  const ctripApi = context.window.SUXI_CTRIP_STATIC;
  const trafficEndpoints = ctripApi.getCtripCookieApiCorePresetEndpoints()
    .filter(endpoint => endpoint.section === 'traffic_report');
  const realtimeEndpoint = trafficEndpoints.find(endpoint => endpoint.request_url.includes('fetchCurrentHotelSeqInfoV1'));

  assert.ok(realtimeEndpoint);
  assert.equal(realtimeEndpoint.request_url, 'https://ebooking.ctrip.com/datacenter/api/biddingajax/fetchCurrentHotelSeqInfoV1');
  assert.equal(realtimeEndpoint.method, 'POST');
});

test('realtime traffic rank extraction prefers the current hotel rank over competitor rank', () => {
  const context = { window: {}, console };
  vm.runInNewContext(ctripStaticSource, context, { filename: 'public/ctrip-static.js' });
  const extractRank = context.window.SUXI_CTRIP_STATIC.extractCtripRealtimeTrafficRank;

  const storedRank = extractRank({
    raw_data: {
      endpoint_id: 'traffic_hotel_seq',
      captured_at: '2026-07-12T14:32:56.095Z',
      facts: [
        { metric_key: 'traffic_rank', source_key: 'rank', value: 46 },
        { metric_key: 'traffic_rank', source_key: 'competitorRank', value: 10 },
        { metric_key: 'traffic_rank', source_key: 'qunarRank', value: null },
      ],
      metrics: { traffic_rank: null },
    },
  });
  const directResponseRank = extractRank({
    endpoint_id: 'traffic_hotel_seq',
    url: 'https://ebooking.ctrip.com/datacenter/api/biddingajax/fetchCurrentHotelSeqInfoV1',
    data: { rcode: 0, data: { rank: 46, competitorRank: 10 } },
  });

  assert.equal(storedRank.rank, 46);
  assert.equal(storedRank.captured_at, '2026-07-12T14:32:56.095Z');
  assert.equal(directResponseRank.rank, 46);
});

test('future search panel saves the complete thirty-day dataset', () => {
  const panelStart = html.indexOf('data-testid="ctrip-search-opportunity-panel"');
  const panel = html.slice(panelStart, html.indexOf('onlineDataTab === \'ctrip-ads\'', panelStart));
  const handlerStart = html.indexOf('const downloadCtripSearchOpportunityImage = async');
  const handler = html.slice(handlerStart, html.indexOf('\n            const ', handlerStart + 20));

  assert.ok(panelStart > 0, 'future search panel must exist');
  assert.match(panel, /data-testid="ctrip-search-opportunity-save"/);
  assert.match(panel, /@click="downloadCtripSearchOpportunityImage"/);
  assert.match(panel, /ctripSearchOpportunitySaving/);
  assert.match(panel, /data-testid="ctrip-search-opportunity-save"[^>]*class="[^"]*bg-green-500/);
  assert.ok(handlerStart > 0, 'save handler must exist');
  assert.match(handler, /ctripSearchOpportunityRows\.value/);
  assert.doesNotMatch(handler, /ctripSearchOpportunityVisibleRows\.value/);
  assert.match(handler, /buildCtripBusinessCanvasStatic/);
  assert.match(handler, /ctrip-future-search-/);
  assert.match(handler, /downloadBlob/);
});

test('future search detail table keeps only interpretable comparison columns', () => {
  const tableStart = html.indexOf('<table data-testid="ctrip-search-opportunity-table"');
  const table = html.slice(tableStart, html.indexOf('</table>', tableStart));

  assert.ok(tableStart > 0, 'future search detail table must exist');
  assert.match(table, /table-fixed/);
  assert.match(table, /text-sm/);
  assert.match(table, /font-variant-numeric:tabular-nums/);
  assert.match(table, /<colgroup>/);
  assert.match(table, /text-center/);
  assert.doesNotMatch(table, /text-right/);
  assert.match(table, /预计订单（本店 \/ 竞争圈）/);
  assert.doesNotMatch(table, /UV×转化率/);
  assert.match(table, /UV差异/);
  assert.match(table, /转化率差异/);
  assert.equal((table.match(/本店对比竞争圈/g) || []).length, 2);
  assert.doesNotMatch(table, /本店较圈|个百分点/);
  assert.match(table, /累计.*formatCtripSearchOpportunityRelativeComparison/s);
  assert.match(table, /昨日.*formatCtripSearchOpportunityRelativeComparison/s);
  assert.match(table, /formatCtripSearchOpportunityPercentagePointGap/);
  assert.doesNotMatch(table, /追赶空间|chase_space/);
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

test('stored traffic data is loaded when the tab opens or the selected hotel changes', () => {
  const tabSwitchStart = html.indexOf('const openCtripManualTab');
  const tabSwitchEnd = html.indexOf('const openMeituanManualTab', tabSwitchStart);
  const tabSwitchFlow = html.slice(tabSwitchStart, tabSwitchEnd);
  const hotelChangeStart = html.indexOf('const handleCtripTrafficHotelChange');
  const hotelChangeEnd = html.indexOf('const runCtripOverviewCoreFetchAction', hotelChangeStart);
  const hotelChangeFlow = html.slice(hotelChangeStart, hotelChangeEnd);
  const fetchStart = html.indexOf('const fetchCtripTrafficAndSearchData');
  const fetchEnd = html.indexOf('const handleCtripTrafficHotelChange', fetchStart);
  const fetchFlow = html.slice(fetchStart, fetchEnd);

  assert.ok(tabSwitchStart > 0 && tabSwitchEnd > tabSwitchStart);
  assert.match(tabSwitchFlow, /tab === 'ctrip-traffic'[\s\S]*loadCtripSearchOpportunity\(\{/);
  assert.ok(hotelChangeStart > 0 && hotelChangeEnd > hotelChangeStart);
  assert.match(hotelChangeFlow, /loadCtripSearchOpportunity\(\{ systemHotelId \}\)/);
  assert.match(hotelChangeFlow, /ctripSearchOpportunityPayload\.value\s*=\s*null/);
  assert.match(hotelChangeFlow, /ctripSearchOpportunityError\.value\s*=\s*''/);
  assert.match(hotelChangeFlow, /const systemHotelId\s*=\s*String\(event\?\.target\?\.value\s*\|\|\s*selectedCtripHotelId\.value\s*\|\|\s*''\)/);
  assert.match(hotelChangeFlow, /autoFetchHotelId\.value\s*=\s*systemHotelId/);
  assert.match(fetchFlow, /await loadCtripSearchOpportunity\(\)/);
});
