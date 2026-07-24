import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context, {
  filename: 'public/revenue-ai-static.js',
});

const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const appMain = readFileSync('public/app-main.js', 'utf8');
const alertPage = readFileSync('resources/frontend/templates/fragments/15c-page-ops-insight.html', 'utf8');
const competitorPage = readFileSync('resources/frontend/templates/fragments/27-page-agent-center.html', 'utf8');
const routes = readFileSync('route/app.php', 'utf8');
const controller = readFileSync('app/controller/OperationManagement.php', 'utf8');
const service = readFileSync('app/service/OperationManagementService.php', 'utf8');
const competitorModel = readFileSync('app/model/CompetitorAnalysis.php', 'utf8');
const agentController = readFileSync('app/controller/Agent.php', 'utf8');

test('threshold alerts expose an idempotent pending-task bridge without automatic OTA execution', () => {
  assert.match(routes, /Route::post\('\/alerts\/:id\/execution-intent', 'OperationManagement\/alertExecutionIntent'\)/);
  assert.match(controller, /createExecutionIntentFromAlert/);
  assert.match(service, /operation_alert_.*md5\('v1\|'/s);
  assert.match(service, /pending_human_approval_no_automatic_ota_write/);
  assert.match(service, /'object_type' => 'operation_checklist'/);
  assert.match(service, /'auto_write_ota' => false/);
  assert.match(appMain, /apiRequest\(`\/operation\/alerts\/\$\{alertId\}\/execution-intent`/);
  assert.match(appMain, /loadOperationActions\(\{ focusIntentId: intentId \}\)/);
  assert.match(appMain, /data-operation-execution-intent-id="\$\{intentId\}"/);
  assert.match(alertPage, /data-testid="operation-alert-create-task"/);
  assert.match(alertPage, /直接转任务/);
  assert.match(alertPage, /查看任务 #/);
  assert.match(alertPage, /operationCanMarkAlertsRead/);
  assert.match(controller, /请选择单个酒店查看运营预警/);
  assert.match(service, /'scope' => 'single_hotel'/);
  assert.match(service, /'can_execute' => \$canExecute/);
  assert.match(service, /\$filters\['intent_id'\]/);
});

test('competitor microscope prioritizes the largest absolute current gap and preserves source truth', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    price_matrix: {
      大床房: {
        竞对甲: {
          id: 1,
          competitor_hotel_id: 11,
          competitor_name: '竞对甲',
          room_type_name: '大床房',
          our_price: 360,
          competitor_price: 300,
          diff_percent: 20,
          ota_platform: 1,
          competitor_data: { evidence_status: 'operator_provided' },
        },
        竞对乙: {
          id: 2,
          competitor_hotel_id: 12,
          competitor_name: '竞对乙',
          room_type_name: '大床房',
          our_price: 310,
          competitor_price: 300,
          diff_percent: 3.33,
          ota_platform: 1,
          competitor_data: {
            validation_status: 'verified',
            readback_verified: true,
            source_method: 'browser_profile',
            source_ref: 'ctrip-public-price#2',
            captured_at: '2026-07-19 09:00:00',
          },
        },
      },
    },
    trends: {
      11: [
        { analysis_date: '2026-07-18', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 350, competitor_price: 300 },
        { analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 360, competitor_price: 300 },
      ],
    },
  });

  assert.equal(result.status, 'partial');
  assert.equal(result.selectedKey, 'id:11|platform:ctrip');
  assert.equal(result.detail.name, '竞对甲');
  assert.equal(result.detail.platformLabel, '携程');
  assert.equal(result.detail.sourceStatus, 'operator_provided');
  assert.equal(result.detail.priceGap, 60);
  assert.equal(result.detail.priceGapPercent, 20);
  assert.equal(result.detail.trend.length, 7);
  assert.equal(result.detail.trendCoverageDays, 2);
  assert.equal(result.detail.trendChange, null);
  assert.ok(result.detail.dataGaps.includes('source_operator_provided'));
  assert.ok(result.detail.dataGaps.includes('seven_day_trend_incomplete'));
});

test('competitor microscope filters unknown-id trends by competitor name instead of mixing samples', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    price_matrix: {
      双床房: {
        竞对甲: { competitor_hotel_id: 0, competitor_name: '竞对甲', ota_platform: 1, our_price: 200, competitor_price: 180 },
      },
    },
    trends: {
      0: [
        { analysis_date: '2026-07-18', competitor_hotel_id: 0, competitor_data: { competitor_name: '竞对甲' }, room_type_name: '双床房', ota_platform: 1, our_price: 190, competitor_price: 180 },
        { analysis_date: '2026-07-18', competitor_hotel_id: 0, competitor_data: { competitor_name: '竞对乙' }, room_type_name: '双床房', ota_platform: 1, our_price: 190, competitor_price: 100 },
      ],
    },
  });

  const populated = result.detail.trend.filter(point => !point.missing);
  assert.equal(result.detail.trend.length, 7);
  assert.equal(populated.length, 1);
  assert.equal(populated[0].sampleCount, 1);
  assert.equal(populated[0].competitorPrice, 180);
});

test('competitor microscope isolates the same competitor by OTA platform', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    price_matrix: {
      大床房: {
        ctripSample: { competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 360, competitor_price: 300 },
        meituanSample: { competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 2, our_price: 280, competitor_price: 280 },
      },
    },
    trends: {
      11: [
        { analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 360, competitor_price: 300 },
        { analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 2, our_price: 280, competitor_price: 280 },
      ],
    },
  }, 'id:11|platform:ctrip');

  assert.equal(result.options.length, 2);
  assert.equal(result.selectedKey, 'id:11|platform:ctrip');
  assert.equal(result.detail.rows.length, 1);
  assert.equal(result.detail.avgOurPrice, 360);
  assert.equal(result.detail.avgCompetitorPrice, 300);
  assert.equal(result.detail.trend.filter(point => !point.missing)[0].competitorPrice, 300);
});

test('competitor microscope treats zero or invalid price as missing evidence', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    price_matrix: {
      大床房: {
        invalidSample: { competitor_hotel_id: 11, competitor_name: '竞对甲', ota_platform: 1, our_price: 360, competitor_price: 0 },
      },
    },
    trends: {},
  });

  assert.equal(result.detail.rows[0].competitor_price, null);
  assert.equal(result.detail.priceGap, null);
  assert.equal(result.detail.priceGapPercent, null);
  assert.ok(result.detail.dataGaps.includes('current_comparable_price_missing'));
});

test('competitor microscope averages only same-row paired prices for current and trend evidence', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    price_matrix: {
      大床房: {
        valid: { hotel_id: 80, analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 100, competitor_price: 100 },
      },
      双床房: {
        oneSided: { hotel_id: 80, analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '双床房', ota_platform: 1, our_price: 200, competitor_price: 0 },
      },
    },
    trends: { 11: [
      { hotel_id: 80, analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '大床房', ota_platform: 1, our_price: 100, competitor_price: 100 },
      { hotel_id: 80, analysis_date: '2026-07-19', competitor_hotel_id: 11, competitor_name: '竞对甲', room_type_name: '双床房', ota_platform: 1, our_price: 200, competitor_price: 0 },
    ] },
  });

  assert.equal(result.detail.avgOurPrice, 100);
  assert.equal(result.detail.avgCompetitorPrice, 100);
  assert.equal(result.detail.priceGap, 0);
  assert.equal(result.detail.trend[6].sampleCount, 1);
  assert.equal(result.detail.trend[6].ourPrice, 100);
  assert.equal(result.detail.trend[6].competitorPrice, 100);
  assert.ok(result.detail.dataGaps.includes('current_comparable_rows_incomplete'));
});

test('competitor microscope rejects cross-scope price rows and keeps an exact seven-day window', () => {
  const dates = ['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17', '2026-07-18', '2026-07-19'];
  const evidence = {
    breakfast: '含早',
    cancellation_policy: '可取消',
    validation_status: 'verified',
    readback_verified: true,
    source_method: 'browser_profile',
    source_ref: 'ctrip-public-price#window',
    captured_at: '2026-07-19 09:00:00',
  };
  const trendRows = dates.map((analysis_date, index) => ({
    hotel_id: 80,
    analysis_date,
    competitor_hotel_id: 11,
    competitor_name: '竞对甲',
    room_type_name: '大床房',
    ota_platform: 1,
    our_price: 110 + index,
    competitor_price: 100,
    competitor_data: evidence,
  }));
  const twinTrendRows = dates.map((analysis_date, index) => ({
    ...trendRows[index],
    room_type_name: '双床房',
    our_price: 210 + index,
    competitor_price: 200,
  }));
  trendRows.push(...twinTrendRows);
  trendRows.push(
    { ...trendRows[6], hotel_id: 81, our_price: 999, competitor_price: 1, competitor_data: { evidence_status: 'operator_provided' } },
    { ...trendRows[6], hotel_id: '', our_price: 999 },
    { ...trendRows[6], analysis_date: '', our_price: 999 },
    { ...trendRows[0], analysis_date: '2026-07-12', our_price: 999, competitor_data: { evidence_status: 'operator_provided' } },
    { ...trendRows[6], analysis_date: '2026-07-20', our_price: 999 },
  );
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    price_matrix: {
      大床房: {
        valid: { ...trendRows[6] },
        wrongHotel: { ...trendRows[6], hotel_id: 81, competitor_hotel_id: 12, competitor_name: '错酒店竞对' },
        wrongDate: { ...trendRows[6], analysis_date: '2026-07-18', competitor_hotel_id: 13, competitor_name: '错日期竞对' },
        missingHotel: { ...trendRows[6], hotel_id: '', competitor_hotel_id: 14, competitor_name: '缺酒店范围' },
        missingDate: { ...trendRows[6], analysis_date: '', competitor_hotel_id: 15, competitor_name: '缺日期范围' },
      },
      双床房: {
        valid: { ...twinTrendRows[6] },
      },
    },
    trends: { 11: trendRows },
  });

  assert.equal(result.options.length, 1);
  assert.equal(result.detail.name, '竞对甲');
  assert.equal(result.detail.trendCoverageDays, 7);
  assert.equal(result.detail.trendChange, 4);
  assert.equal(result.detail.trend[6].competitorPrice, 150);
  assert.equal(result.detail.trendComparableKeyCount, 2);
  assert.equal(result.detail.dataGaps.includes('trend_room_mix_reduced'), false);
  assert.equal(result.detail.dataGaps.includes('trend_source_unverified'), false);
  assert.deepEqual(Array.from(result.detail.trend, row => row.date), dates);
  assert.ok(result.detail.dataGaps.includes('price_scope_rows_rejected'));
});

test('competitor microscope consumes stored Meituan competition-circle rows without inventing price evidence', () => {
  const currentRankHistory = (ranks) => [
    { rankType: 'P_RZ', dateRange: '1', rankTypeLabel: '入住榜', rank: ranks[0], metric: 'roomNights', value: 18, sourceLabel: '美团榜单入库' },
    { rankType: 'P_XS', dateRange: '1', rankTypeLabel: '销售榜', rank: ranks[1], metric: 'sales', value: 4200, sourceLabel: '美团榜单入库' },
    { rankType: 'P_LL', dateRange: '1', rankTypeLabel: '流量榜', rank: ranks[2], metric: 'exposure', value: 980, sourceLabel: '美团榜单入库' },
    { rankType: 'P_ZH', dateRange: '1', rankTypeLabel: '转化榜', rank: ranks[3], metric: 'payConversion', value: 0.085, sourceLabel: '美团榜单入库' },
  ];
  const previousRankHistory = (ranks) => currentRankHistory(ranks).map(row => ({ ...row, value: row.value - 1 }));
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19', metric_scope: 'ota_channel' },
    price_matrix: {},
    trends: {},
    meituan_competition_circle: {
      data_status: 'success',
      source: 'online_daily_data',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      latest_fetched_at: '2026-07-19 09:30:00',
      readiness: { status: 'ok', label: '可用于快捷判断' },
      source_notice: '美团竞争圈 OTA 渠道数据；部分数值可能由平台百分比推导。',
      display_hotels: [
        { poiId: 'SELF', hotelName: '本店', isSelf: true, rankHistory: currentRankHistory([3, 4, 5, 6]) },
        { poiId: 'RIVAL', hotelName: '竞对甲', isSelf: false, rankHistory: currentRankHistory([1, 2, 4, 3]), platformTags: ['VIP'] },
      ],
      comparison: {
        system_hotel_id: 80,
        data_date: '2026-07-18',
        display_hotels: [
          { poiId: 'SELF', hotelName: '本店', isSelf: true, rankHistory: previousRankHistory([4, 4, 6, 6]) },
          { poiId: 'RIVAL', hotelName: '竞对甲', isSelf: false, rankHistory: previousRankHistory([2, 3, 5, 3]) },
        ],
      },
    },
  }, 'poi:RIVAL|platform:meituan');

  assert.equal(result.selectedKey, 'poi:RIVAL|platform:meituan');
  assert.equal(result.options.length, 1);
  assert.equal(result.detail.kind, 'meituan_competition_circle');
  assert.equal(result.detail.platformLabel, '美团');
  assert.equal(result.detail.name, '竞对甲');
  assert.equal(result.detail.rows.length, 4);
  assert.deepEqual(Array.from(result.detail.rows, row => row.room_type_name), ['入住榜', '销售榜', '流量榜', '转化榜']);
  assert.equal(result.detail.rows[0].our_value_text, '第3名');
  assert.equal(result.detail.rows[0].competitor_value_text, '第1名');
  assert.equal(result.detail.rows[0].gap_text, '竞对领先2名');
  assert.equal(result.detail.rows[0].trend_text, '较前日上升1名');
  assert.match(result.detail.rows[3].price_signal_readiness.status_label, /8\.5%/);
  assert.equal(result.detail.priceGap, null);
  assert.ok(result.detail.dataGaps.includes('same_product_price_not_returned'));
  assert.equal(result.detail.latestDataDate, '2026-07-19');
  assert.equal(result.detail.sourceStatus, 'stored_unverified');
  assert.ok(result.detail.dataGaps.includes('source_validation_status_not_returned'));
  assert.match(result.scopeNotice, /美团竞争圈/);

  const percentScaleResult = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [{
        poiId: 'RIVAL-PERCENT', hotelName: '百分数竞对', rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', rank: 2, metric: 'payConversion', value: 8.5 },
        ],
      }],
    },
  });
  assert.match(percentScaleResult.detail.rows[3].price_signal_readiness.status_label, /8\.5%/);

  const zeroScaleResult = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [{
        poiId: 'RIVAL-ZERO', hotelName: '零转化竞对', rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', rank: 3, metric: 'payConversion', value: 0 },
        ],
      }],
    },
  });
  assert.match(zeroScaleResult.detail.rows[3].price_signal_readiness.status_label, /0%/);

  const negativeScaleResult = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [{
        poiId: 'RIVAL-NEGATIVE', hotelName: '负值竞对', rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', rank: 4, metric: 'payConversion', value: -0.05 },
        ],
      }],
    },
  });
  assert.match(negativeScaleResult.detail.rows[3].price_signal_readiness.status_label, /数值未返回/);
});

test('Meituan microscope never mixes rank windows and counts only returned ranks', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [
        { poiId: 'SELF', hotelName: '本店', isSelf: true, rankHistory: [
          { rankType: 'P_RZ', dateRange: '7', rank: 8 },
          { rankType: 'P_XS', dateRange: '1', rank: 4 },
        ] },
        { poiId: 'RIVAL', hotelName: '竞对甲', rankHistory: [
          { rankType: 'P_RZ', dateRange: '1', rank: 1 },
          { rankType: 'P_XS', dateRange: 'yesterday', rank: 2 },
          { rankType: 'P_LL', dateRange: '1', rank: 0 },
          { rankType: 'P_ZH', dateRange: '1', rank: null },
        ] },
      ],
      comparison: {
        system_hotel_id: 80,
        data_date: '2026-07-18',
        display_hotels: [{ poiId: 'RIVAL', rankHistory: [
          { rankType: 'P_RZ', dateRange: '7', rank: 5 },
          { rankType: 'P_XS', dateRange: '1', rank: 3 },
        ] }],
      },
    },
  });

  assert.equal(result.options[0].optionSummary, '四榜 2/4');
  assert.equal(result.detail.rows[0].our_rank, null);
  assert.equal(result.detail.rows[0].gap_text, '差距未知');
  assert.equal(result.detail.rows[0].trend_text, '前日未返回');
  assert.match(result.detail.rows[3].price_signal_readiness.status_label, /数值未返回/);
  assert.equal(result.detail.rows[1].our_rank, 4);
  assert.equal(result.detail.rows[1].gap_text, '竞对领先2名');
  assert.equal(result.detail.rows[1].trend_text, '较前日上升1名');

  const sevenDayOnly = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [
        { poiId: 'SELF', hotelName: '本店', isSelf: true, rankHistory: [{ rankType: 'P_RZ', dateRange: '7', rank: 8 }] },
        { poiId: 'RIVAL', hotelName: '仅七日榜竞对', rankHistory: [{ rankType: 'P_RZ', dateRange: '7', rank: 1 }] },
      ],
    },
  });
  assert.equal(sevenDayOnly.options[0].optionSummary, '四榜 0/4');
  assert.equal(sevenDayOnly.detail.rows[0].our_rank, null);
  assert.equal(sevenDayOnly.detail.rows[0].competitor_rank, null);

  const metricStrict = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19' },
    meituan_competition_circle: {
      data_status: 'success',
      system_hotel_id: 80,
      latest_data_date: '2026-07-19',
      display_hotels: [
        { poiId: 'SELF', hotelName: '本店', isSelf: true, rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', metric: 'viewConversion', rank: 9 },
          { rankType: 'P_ZH', dateRange: '1', metric: 'payConversion', rank: 5 },
        ] },
        { poiId: 'RIVAL', hotelName: '竞对甲', rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', metric: 'payConversion', rank: 2 },
        ] },
      ],
      comparison: {
        system_hotel_id: 80,
        data_date: '2026-07-18',
        display_hotels: [{ poiId: 'RIVAL', rankHistory: [
          { rankType: 'P_ZH', dateRange: '1', metric: 'viewConversion', rank: 7 },
        ] }],
      },
    },
  });
  assert.equal(metricStrict.detail.rows[3].our_rank, 5);
  assert.equal(metricStrict.detail.rows[3].competitor_rank, 2);
  assert.equal(metricStrict.detail.rows[3].gap_text, '竞对领先3名');
  assert.equal(metricStrict.detail.rows[3].trend_text, '前日未返回');
});

test('Meituan scope normalizer rejects hotel/date mismatches and discards non-D-1 comparison', () => {
  const base = {
    data_status: 'success',
    system_hotel_id: 80,
    latest_data_date: '2026-07-19',
    display_hotels: [{ poiId: 'RIVAL', hotelName: '竞对甲', rankHistory: [] }],
  };
  const wrongHotel = helpers.normalizeMeituanCompetitionCircle({ ...base, system_hotel_id: 81 }, 80, '2026-07-19');
  const wrongDate = helpers.normalizeMeituanCompetitionCircle({ ...base, latest_data_date: '2026-07-18' }, 80, '2026-07-19');
  const staleComparison = helpers.normalizeMeituanCompetitionCircle({
    ...base,
    comparison: { system_hotel_id: 80, data_date: '2026-07-17', display_hotels: [] },
  }, 80, '2026-07-19');
  const crossHotelComparison = helpers.normalizeMeituanCompetitionCircle({
    ...base,
    comparison: { system_hotel_id: 81, data_date: '2026-07-18', display_hotels: [] },
  }, 80, '2026-07-19');

  assert.equal(wrongHotel.accepted, false);
  assert.equal(wrongHotel.status, 'scope_mismatch');
  assert.equal(wrongDate.accepted, false);
  assert.equal(wrongDate.status, 'scope_mismatch');
  assert.equal(staleComparison.accepted, true);
  assert.equal(staleComparison.payload.comparison, null);
  assert.equal(staleComparison.payload.comparison_scope_mismatch, true);
  assert.equal(crossHotelComparison.accepted, true);
  assert.equal(crossHotelComparison.payload.comparison, null);
  assert.equal(crossHotelComparison.payload.comparison_scope_mismatch, true);

  const wrongMissingScope = helpers.normalizeMeituanCompetitionCircle({
    data_status: 'missing',
    hotel_id: '81',
    target_date: '2026-07-18',
  }, 80, '2026-07-19');
  const exactMissingScope = helpers.normalizeMeituanCompetitionCircle({
    data_status: 'missing',
    hotel_id: '80',
    target_date: '2026-07-19',
  }, 80, '2026-07-19');
  assert.equal(wrongMissingScope.accepted, false);
  assert.equal(wrongMissingScope.status, 'scope_mismatch');
  assert.equal(exactMissingScope.accepted, true);
});

test('Meituan microscope preserves unavailable quality states instead of calling them not loaded', () => {
  for (const [data_status, expected] of [
    ['stale', 'stale'],
    ['permission_denied', 'permission_denied'],
    ['collection_failed', 'collection_failed'],
  ]) {
    const result = helpers.buildCompetitorMicroscope({
      date: '2026-07-19',
      query_scope: { hotel_id: 80, date: '2026-07-19' },
      meituan_competition_circle: { data_status, message: data_status },
    });
    assert.equal(result.platformStatuses.find(row => row.platformKey === 'meituan').status, expected);
  }
});

test('competitor microscope rejects stale or cross-hotel Meituan competition-circle payloads', () => {
  const result = helpers.buildCompetitorMicroscope({
    date: '2026-07-19',
    query_scope: { hotel_id: 80, date: '2026-07-19', metric_scope: 'ota_channel' },
    price_matrix: {},
    trends: {},
    meituan_competition_circle: {
      data_status: 'success',
      source: 'online_daily_data',
      system_hotel_id: 81,
      latest_data_date: '2026-07-18',
      display_hotels: [{ poiId: 'RIVAL', hotelName: '错误门店竞对', rankHistory: [] }],
    },
  });

  assert.equal(result.detail, null);
  assert.equal(result.options.length, 0);
  assert.equal(result.platformStatuses.find(row => row.platformKey === 'meituan').status, 'scope_mismatch');
});

test('competitor microscope UI and backend trend use the selected analysis date', () => {
  assert.match(competitorPage, /data-testid="competitor-microscope"/);
  assert.match(competitorPage, /data-testid="competitor-microscope-selector"/);
  assert.match(competitorPage, /同日房型证据/);
  assert.match(competitorPage, /不以 0 或旧数据代替/);
  assert.match(competitorPage, /competitorAnalysisLoading/);
  assert.match(competitorPage, /competitorAnalysisError/);
  assert.match(appMain, /revenueAiBuildCompetitorMicroscope/);
  assert.match(appMain, /competitorAnalysisRequestSeq/);
  assert.match(appMain, /responseHotelId !== hotelId \|\| responseDate !== date/);
  assert.match(competitorModel, /getPriceTrend\(int \$hotelId, int \$competitorId, int \$roomTypeId = 0, \?string \$endDate = null\)/);
  assert.match(competitorModel, /getPriceTrends\(int \$hotelId, array \$competitorIds = \[\], int \$roomTypeId = 0, \?string \$endDate = null\): array/);
  assert.match(competitorModel, /getAlertCompetitors\(int \$hotelId, float \$threshold = 20, \?string \$date = null\)/);
  assert.match(agentController, /getPriceTrends\(\$hotelId, \[\], 0, \$date\)/);
  assert.doesNotMatch(agentController, /foreach \(\$competitors as \$competitorId\)[\s\S]*getPriceTrend/);
  assert.match(agentController, /'metric_scope' => 'ota_channel'/);
  assert.match(appMain, /\/online-data\/competitor-summary/);
  assert.match(appMain, /target_date/);
  assert.match(appMain, /meituan_competition_circle/);
  assert.match(competitorPage, /competitorMicroscopeDetail\.tableTitle/);
  assert.match(competitorPage, /our_value_text/);
  assert.match(competitorPage, /sameProductPriceNotice/);
});
