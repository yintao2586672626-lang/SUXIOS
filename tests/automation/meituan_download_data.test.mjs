import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/meituan-static.js', 'utf8');
const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataQualityConcern = readFileSync('app/controller/concern/OnlineDataQualityConcern.php', 'utf8');
const meituanTemplate = readFileSync('resources/frontend/templates/fragments/26-page-meituan-ebooking.html', 'utf8');
const sandbox = { console, window: {} };
vm.runInNewContext(`${source}\nthis.__api = window.SUXI_MEITUAN_STATIC;`, sandbox);
const api = sandbox.__api;

test('Meituan missing sort metrics stay below real zero in both directions', () => {
  const rows = [
    { poiId: 'missing', sales: null },
    { poiId: 'zero', sales: 0 },
    { poiId: 'positive', sales: 10 },
    { poiId: 'invalid', sales: 'not-a-number' },
  ];
  assert.deepEqual(Array.from(api.buildMeituanDisplayedHotelsList(rows, 'sales', 'asc'), row => row.poiId), ['zero', 'positive', 'missing', 'invalid']);
  assert.deepEqual(Array.from(api.buildMeituanDisplayedHotelsList(rows, 'sales', 'desc'), row => row.poiId), ['positive', 'zero', 'missing', 'invalid']);
});

test('Meituan ranks only rows with a real selected metric and keeps missing gaps null', () => {
  const rows = [
    { poiId: 'positive', hotelName: 'A', sales: 10 },
    { poiId: 'missing', hotelName: 'B', sales: null },
    { poiId: 'zero', hotelName: 'C', sales: 0 },
  ];
  const ranked = api.buildMeituanRankDisplayRows(rows, 'sales');
  const byPoi = Object.fromEntries(Array.from(ranked, row => [row.poiId, row]));
  assert.equal(byPoi.positive.currentPlatformRank, 1);
  assert.equal(byPoi.zero.currentPlatformRank, 2, 'a genuine zero remains rankable');
  assert.equal(byPoi.zero.gapToLeader, 10);
  assert.equal(byPoi.missing.currentPlatformRank, null);
  assert.equal(byPoi.missing.circlePositionText, '指标未取得');
  assert.equal(byPoi.missing.gapToPrev, null);
  assert.equal(byPoi.missing.gapToNext, null);
  assert.equal(byPoi.missing.gapToLeader, null);
  assert.deepEqual(Array.from(api.buildMeituanDisplayedHotelsList(ranked, 'sales', 'desc'), row => row.poiId), ['positive', 'zero', 'missing']);
});

test('Meituan stored-data download keeps each data type in its own tab', () => {
  const rows = [
    { id: 1, source: 'meituan', data_type: 'peer_rank', hotel_name: '同行 A', data_date: '2026-07-14', data_value: 800, rank: 1, rank_percent: 100 },
    { id: 2, source: 'meituan', data_type: 'order_flow', data_value: 99999, amount: 99999 },
    { id: 3, source: 'meituan', data_type: 'business', data_value: 77777 },
    { id: 4, source: 'meituan', data_type: 'traffic', list_exposure: 0, detail_exposure: 0, flow_rate: 0 },
    { id: 5, source: 'meituan', data_type: 'traffic_analysis', list_exposure: 100, detail_exposure: 20 },
    { id: 6, source: 'meituan', data_type: 'order', book_order_num: 0, quantity: 0, amount: 0 },
    { id: 7, source: 'meituan', data_type: 'advertising', exposure_count: 10, click_count: 0 },
    { id: 8, source: 'meituan', data_type: 'search_keyword', dimension: '机场酒店', data_value: null, list_exposure: 0, detail_exposure: 0 },
    { id: 9, source: 'ctrip', data_type: 'traffic', list_exposure: 5000, detail_exposure: 1000 },
  ];

  const result = api.buildMeituanDownloadData(rows);

  assert.deepEqual(Array.from(result.allRows, row => row.id), [1, 2, 3, 4, 5, 6, 7, 8]);
  assert.equal(result.allRowsCount, 8);
  assert.deepEqual(Array.from(result.overviewRows, row => row.id), [1]);
  assert.deepEqual(Array.from(result.trafficRows, row => row.id), [4, 5]);
  assert.deepEqual(Array.from(result.orderRows, row => row.id), [6]);
  assert.deepEqual(Array.from(result.adsRows, row => row.id), [7]);
  assert.deepEqual(Array.from(result.keywordRows, row => row.id), [8]);
  assert.equal(result.trafficExposure, 100);
  assert.equal(result.trafficClick, 20);
  assert.equal(result.trafficAvgFlowRate, 10);
  assert.equal(result.orderBookOrder, 0);
  assert.equal(result.orderQuantity, 0);
  assert.equal(result.orderAmount, 0);
  assert.equal(result.adsExposure, 10);
  assert.equal(result.adsClick, 0);
  assert.equal(result.adsClickRate, 0);
  assert.equal(result.keywordRowsCount, 1);
  assert.equal(result.keywordRows[0].keyword_value, null);
  assert.equal(result.keywordRows[0].keyword_impressions, 0);
});

test('Meituan keyword dedup keeps distinct keywords with identical metrics', () => {
  const result = api.buildMeituanDownloadData([
    { id: 10, source: 'meituan', data_type: 'search_keyword', system_hotel_id: 80, data_date: '2026-07-11', dimension: '机场酒店', data_value: 5, list_exposure: 20, detail_exposure: 2 },
    { id: 11, source: 'meituan', data_type: 'search_keyword', system_hotel_id: 80, data_date: '2026-07-11', dimension: '敦煌酒店', data_value: 5, list_exposure: 20, detail_exposure: 2 },
    { id: 12, source: 'meituan', data_type: 'search_keyword', system_hotel_id: 80, data_date: '2026-07-11', dimension: '机场酒店', data_value: 5, list_exposure: 20, detail_exposure: 2 },
  ]);
  assert.deepEqual(Array.from(result.keywordRows, row => row.keyword_label), ['机场酒店', '敦煌酒店']);
});

test('Meituan current-page CSV separates ads and keywords while preserving null versus zero', () => {
  const result = api.buildMeituanDownloadData([
    {
      id: 1,
      source: 'meituan',
      data_type: 'advertising',
      hotel_name: '敦煌漠蓝新',
      data_date: '2026-07-11',
      dimension: 'campaign-a',
      list_exposure: 100,
      detail_exposure: 0,
      amount: 20,
      data_value: 5,
      readback_verified: 1,
    },
    {
      id: 2,
      source: 'meituan',
      data_type: 'search_keyword',
      hotel_name: '敦煌漠蓝新',
      data_date: '2026-07-11',
      dimension: '机场酒店',
      data_value: null,
      list_exposure: 0,
      detail_exposure: null,
      readback_verified: 1,
    },
  ]);

  const ads = api.buildMeituanStoredPageCsvPayload('ads', result, { hotelId: 80, startDate: '2026-07-11', page: 2 });
  const keywords = api.buildMeituanStoredPageCsvPayload('keywords', result, { hotelId: 80, startDate: '2026-07-11', page: 2 });

  assert.equal(ads.ok, true);
  assert.equal(keywords.ok, true);
  assert.equal(ads.rows.length, 1);
  assert.equal(keywords.rows.length, 1);
  assert.ok(ads.csv.startsWith('\uFEFF'));
  assert.match(ads.csv, /campaign-a/);
  assert.doesNotMatch(ads.csv, /机场酒店/);
  assert.match(keywords.csv, /机场酒店,,0,,/);
  assert.doesNotMatch(keywords.csv, /campaign-a/);
  assert.equal(keywords.fileName, 'meituan-search-keywords-80-2026-07-11-page-2.csv');
});

test('Meituan CSV neutralizes spreadsheet formula prefixes from OTA text', () => {
  const data = api.buildMeituanDownloadData([
    { id: 20, source: 'meituan', data_type: 'search_keyword', system_hotel_id: 80, hotel_name: '+恶意酒店', data_date: '2026-07-11', dimension: '=HYPERLINK("https://example.invalid")', data_value: 1 },
    { id: 21, source: 'meituan', data_type: 'advertising', system_hotel_id: 80, hotel_name: '@酒店', data_date: '2026-07-11', dimension: ' -2+3', list_exposure: 1 },
  ]);
  const keywords = api.buildMeituanStoredPageCsvPayload('keywords', data, { hotelId: 80, startDate: '2026-07-11', page: 1 });
  const ads = api.buildMeituanStoredPageCsvPayload('ads', data, { hotelId: 80, startDate: '2026-07-11', page: 1 });
  assert.match(keywords.csv, /'\+恶意酒店/);
  assert.match(keywords.csv, /'=HYPERLINK/);
  assert.match(ads.csv, /'@酒店/);
  assert.match(ads.csv, /' -2\+3/);
  assert.doesNotMatch(keywords.csv, /(?:^|,)\s*[=+\-@]/m);
  assert.doesNotMatch(ads.csv, /(?:^|,)\s*[=+\-@]/m);
});

test('Meituan owner page exposes search-keyword view and current-page CSV action', () => {
  assert.match(appMain, /keywords: \['search_keyword'\]/);
  assert.match(appMain, /downloadMeituanCurrentPageCsv/);
  assert.match(meituanTemplate, /data-testid="meituan-search-keyword-tab"/);
  assert.match(meituanTemplate, /data-testid="meituan-search-keyword-workbench"/);
  assert.match(meituanTemplate, /data-testid="meituan-download-current-page-csv"/);
  assert.match(appMain, /\['all', 'overview', 'traffic', 'orders', 'reviews', 'ads', 'keywords'\]/);
});

test('generic data_value never becomes Meituan traffic and missing metrics stay missing', () => {
  const peerRank = { source: 'meituan', data_type: 'peer_rank', data_value: 12345 };
  const orderFlow = { source: 'meituan', data_type: 'order_flow', data_value: 67890 };

  assert.equal(api.isMeituanTrafficDataRow(peerRank), false);
  assert.equal(api.isMeituanTrafficDataRow(orderFlow), false);
  assert.equal(api.hasMeituanExposureMetric(peerRank), false);

  const result = api.buildMeituanDownloadData([
    { source: 'meituan', data_type: 'traffic_analysis' },
    { source: 'meituan', data_type: 'order' },
    { source: 'meituan', data_type: 'advertising' },
  ]);
  assert.equal(result.trafficExposure, null);
  assert.equal(result.trafficClick, null);
  assert.equal(result.trafficAvgFlowRate, null);
  assert.equal(result.trafficClickRate, null);
  assert.equal(result.orderBookOrder, null);
  assert.equal(result.orderQuantity, null);
  assert.equal(result.orderAmount, null);
  assert.equal(result.adsExposure, null);
  assert.equal(result.adsClick, null);
  assert.equal(result.adsClickRate, null);
  assert.equal(result.trafficRows.length, 0);
});

test('stored display removes blank traffic-analysis rows and exact duplicate module facts', () => {
  const rows = [
    { id: 50408, source: 'meituan', system_hotel_id: 80, data_date: '2026-07-18', data_type: 'traffic_analysis' },
    { id: 50616, source: 'meituan', system_hotel_id: 80, data_date: '2026-07-18', data_type: 'traffic', list_exposure: 91, detail_exposure: 16, flow_rate: 17.58, order_submit_num: 2 },
    { id: 36779, source: 'meituan', system_hotel_id: 80, data_date: '2026-07-11', data_type: 'review', dimension: 'review:meituan', quantity: 5, comment_score: 5, data_value: 0 },
    { id: 33704, source: 'meituan', system_hotel_id: 80, data_date: '2026-07-11', data_type: 'review', dimension: '', quantity: 5, comment_score: 5, data_value: 0 },
  ];

  const result = api.buildMeituanDownloadData(rows);

  assert.deepEqual(Array.from(result.trafficRows, row => row.id), [50616]);
  assert.deepEqual(Array.from(result.reviewRows, row => row.id), [36779]);
  assert.equal(result.visibleRowsCountByTab.traffic, 1);
  assert.equal(result.visibleRowsCountByTab.reviews, 1);
  assert.equal(result.reviewTotalCount, 5);
  assert.equal(result.reviewAverageScore, 5);
});

test('stored myHotel funnel row renders the exact live traffic metrics without filling a missing stage', () => {
  const row = {
    id: 50616,
    source: 'meituan',
    system_hotel_id: 80,
    data_date: '2026-07-18',
    data_type: 'traffic',
    list_exposure: 91,
    detail_exposure: 16,
    flow_rate: 17.58,
    order_filling_num: null,
    order_submit_num: 2,
  };

  const result = api.buildMeituanDownloadData([row]);

  assert.equal(result.trafficRows.length, 1);
  assert.equal(result.trafficExposure, 91);
  assert.equal(result.trafficClick, 16);
  assert.equal(result.trafficAvgFlowRate, 17.58);
  assert.equal(api.getMeituanSubmitMetricValue(row), 2);
  assert.equal(result.trafficRows[0].order_filling_num, null);
});

test('daily data API displays the bound system hotel name and preserves the captured source label', () => {
  assert.match(onlineDataQualityConcern, /Db::name\('hotels'\)->whereIn\('id', \$systemHotelIds\)->column\('name', 'id'\)/);
  assert.match(onlineDataQualityConcern, /\$item\['captured_hotel_name'\] = \$capturedHotelName/);
  assert.match(onlineDataQualityConcern, /\$item\['system_hotel_name'\] = \$systemHotelName/);
  assert.match(onlineDataQualityConcern, /\$item\['hotel_name'\] = \$systemHotelName/);
  assert.match(onlineDataQualityConcern, /isset\(\$rawData\['row'\]\) && is_array\(\$rawData\['row'\]\)/);
  assert.match(onlineDataQualityConcern, /\$item\['rank'\] = \$displayRawData\['rank'\]/);
  assert.match(onlineDataQualityConcern, /\$item\['rank_percent'\] = \$displayRawData\['percent'\]/);
});

test('peer-rank rows keep captured competitor names separate from the bound system hotel', () => {
  const result = api.buildMeituanDownloadData([
    { id: 1, source: 'meituan', data_type: 'peer_rank', system_hotel_id: 80, hotel_name: '敦煌漠蓝新', captured_hotel_name: '同行 A', rank: 1 },
    { id: 2, source: 'meituan', data_type: 'peer_rank', system_hotel_id: 80, hotel_name: '敦煌漠蓝新', captured_hotel_name: '同行 B', rank: 2 },
  ]);

  assert.equal(result.overviewRowsCount, 2);
  assert.equal(result.overviewHotelCount, 2);
  assert.deepEqual(Array.from(result.overviewRows, row => row.overview_hotel_name), ['同行 A', '同行 B']);
  assert.deepEqual(Array.from(result.overviewRows, row => row.hotel_name), ['敦煌漠蓝新', '敦煌漠蓝新']);
});

test('stored ads empty state loads and checks all Profile evidence for the selected hotel', () => {
  assert.match(appMain, /context\.source === 'meituan'[\s\S]*loadPlatformDataSources\(\{ cacheMs: PLATFORM_SOURCE_PANEL_CACHE_TTL_MS \}\)/);
  assert.match(appMain, /resolveMeituanAdsApplicability\(platformDataSources\.value, hotelId\)/);
  assert.match(meituanTemplate, /onlineDataFilter\.hotel_id \|\| meituanForm\.hotelId/);
  assert.match(meituanTemplate, /isMeituanAdsNotApplicableForHotel/);
  assert.match(meituanTemplate, /当前酒店未开通美团广告服务（不适用）/);
  assert.match(meituanTemplate, /源记录共[\s\S]*本页显示[\s\S]*条去重事实/);
});

test('ads applicability uses the newest explicit Profile evidence and ignores blank projections', () => {
  const closed = {
    id: 68,
    platform: 'meituan',
    ingestion_method: 'browser_profile',
    system_hotel_id: 80,
    enabled: 1,
    config: { ads_status_reason: 'ads_service_not_opened', ads_url: 'https://ads.example.test/' },
  };
  const blankProjection = {
    id: 101,
    platform: 'meituan',
    ingestion_method: 'browser_profile',
    system_hotel_id: 80,
    enabled: 1,
    config: {},
  };
  assert.equal(api.resolveMeituanAdsApplicability([blankProjection, closed], 80).status, 'not_applicable');

  const reopened = {
    ...blankProjection,
    id: 102,
    config: { ads_url: 'https://ads.example.test/' },
  };
  assert.equal(api.resolveMeituanAdsApplicability([closed, reopened], 80).status, 'available');

  const newerFailure = {
    ...blankProjection,
    id: 103,
    config: { module_states: { ads: { status: 'blocked', reason: 'ads_collection_failed' } } },
  };
  assert.equal(api.resolveMeituanAdsApplicability([closed, newerFailure], 80).status, 'unknown');
});
