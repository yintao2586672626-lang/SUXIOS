import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeMeituanAdvertisingRows,
  attachVerifiedMeituanCaptureScope,
  buildMeituanOrderFlowReplayUrls,
  isImportableMeituanTrafficRow,
  normalizeMeituanBusinessRows,
  normalizeMeituanFlowAnalysisRows,
  normalizeMeituanOrderRows,
  normalizeMeituanOrderFlowRows,
  normalizeMeituanPeerRankRows,
  normalizeMeituanSearchKeywordRows,
  normalizeMeituanTrafficCardRows,
  normalizeMeituanTrafficDomText,
  normalizeMeituanTrafficForecastRows,
} from '../../scripts/lib/meituan_browser_capture_normalize.mjs';

test('Meituan verified capture scope fills only missing own-hotel identifiers', () => {
  const validation = {
    status: 'matched',
    source_validation: true,
    sensitive_values_exposed: false,
    validated_identifier: '1029642156589279',
  };
  const existing = { poi_id: 'different-hotel', data_type: 'traffic' };
  const rows = attachVerifiedMeituanCaptureScope([
    { data_type: 'traffic', dataDate: '2026-08-03' },
    existing,
  ], validation, '1029642156589279');

  assert.equal(rows[0].poi_id, '1029642156589279');
  assert.equal(rows[0].store_id, '1029642156589279');
  assert.equal(rows[0]._platform_hotel_identifier_source, 'capture_scope_default');
  assert.equal(rows[1], existing);
  assert.equal(rows[1].poi_id, 'different-hotel');
});

test('Meituan capture scope stays fail-closed without a verified identity match', () => {
  const row = { data_type: 'traffic', dataDate: '2026-08-03' };
  const rows = attachVerifiedMeituanCaptureScope([row], {
    status: 'matched',
    source_validation: false,
    validated_identifier: '1029642156589279',
  }, '1029642156589279');

  assert.equal(rows[0], row);
  assert.equal(rows[0].poi_id, undefined);
});

test('Meituan business data is normalized independently and preserves zero versus missing', () => {
  const rows = normalizeMeituanBusinessRows({
    data: {
      businessData: {
        cards: [
          { id: 'LEAD_PRICE', title: '\u5f15\u6d41\u4ef7', value: '868.00' },
          { id: 'PAY_ROOMNIGHT', title: '\u9500\u552e\u95f4\u591c', value: '0' },
          { id: 'PAY_AMT', title: '\u9500\u552e\u989d', value: '0.00' },
          { id: 'EXPOSE_PV_CNT', title: '\u66dd\u5149\u4eba\u6570', value: '750' },
          { id: 'INTENTION_UV', title: '\u6d4f\u89c8\u4eba\u6570', value: '117' },
          { id: 'PAY_ORDER_CNT', title: '\u652f\u4ed8\u8ba2\u5355\u6570', value: '0' },
          { id: 'PAY_ORDER_CNT_UV', title: '\u6d4f\u89c8-\u652f\u4ed8\u8f6c\u5316\u7387', value: '0%' },
        ],
      },
    },
  }, {
    requestDateEvidence: { date: '2026-07-29', date_source: 'request.query.date' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'business');
  assert.equal(rows[0].lead_price, 868);
  assert.equal(rows[0].sales_room_nights, 0);
  assert.equal(rows[0].sales_amount, 0);
  assert.equal(rows[0].sales_avg_price, null);
  assert.equal(rows[0].exposure_users, 750);
  assert.equal(rows[0].detail_visitors, 117);
  assert.equal(rows[0].paid_order_count, 0);
  assert.equal(rows[0].browse_to_pay_rate, 0);
  assert.equal(rows[0]._meituan_business_metric_missing.includes('sales_avg_price'), true);
  assert.equal(rows[0].dataDate, '2026-07-29');
});

test('Meituan business field aliases keep explicit platform values', () => {
  const rows = normalizeMeituanBusinessRows({
    data: {
      businessData: {
        startingPrice: 542.24,
        salesRoomNights: 2,
        salesAmount: 2026.78,
        salesAvgPrice: 1013.39,
        exposureUV: 81,
        intentionUV: 66,
        payOrderCnt: 1,
        payOrderPerIntention: 1.52,
        dataDate: '2026-07-29',
      },
    },
  });

  assert.equal(rows.length, 1);
  assert.deepEqual({
    lead_price: rows[0].lead_price,
    sales_room_nights: rows[0].sales_room_nights,
    sales_amount: rows[0].sales_amount,
    sales_avg_price: rows[0].sales_avg_price,
    exposure_users: rows[0].exposure_users,
    detail_visitors: rows[0].detail_visitors,
    paid_order_count: rows[0].paid_order_count,
    browse_to_pay_rate: rows[0].browse_to_pay_rate,
  }, {
    lead_price: 542.24,
    sales_room_nights: 2,
    sales_amount: 2026.78,
    sales_avg_price: 1013.39,
    exposure_users: 81,
    detail_visitors: 66,
    paid_order_count: 1,
    browse_to_pay_rate: 1.52,
  });
});

test('Meituan current home cards map the platform-native lead price and ADR ids', () => {
  const rows = normalizeMeituanBusinessRows({
    data: {
      data: {
        rtDataUpdateTime: '数据更新时间：2026/07/30 01:59',
        cards: [
          { id: 'DAY_ROOM_LOWEST_PRICE_AVG', value: '1158.00' },
          { id: 'EXPOSE_PV_CNT', value: '-' },
          { id: 'INTENTION_UV', value: '8' },
          { id: 'PAY_ORDER_CNT_UV', value: '12.50' },
          { id: 'PAY_ORDER_CNT', value: '1' },
          { id: 'PAY_ROOMNIGHT', value: '1' },
          { id: 'PAY_ADR', value: '1032.39' },
          { id: 'PAY_AMT', value: '1032.39' },
        ],
      },
    },
  }, {
    requestDateEvidence: { date: '2026-07-30', date_source: 'request.bound_business_period' },
    businessCaptureEpoch: 7,
    businessRelativeRange: '今日实时',
    businessEvidenceSource: 'page.business_period_selection.readback',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].lead_price, 1158);
  assert.equal(rows[0].sales_room_nights, 1);
  assert.equal(rows[0].sales_amount, 1032.39);
  assert.equal(rows[0].sales_avg_price, 1032.39);
  assert.equal(rows[0].exposure_users, null);
  assert.equal(rows[0].detail_visitors, 8);
  assert.equal(rows[0].paid_order_count, 1);
  assert.equal(rows[0].browse_to_pay_rate, 12.5);
  assert.equal(rows[0].business_capture_epoch, 7);
  assert.equal(rows[0].business_relative_range, '今日实时');
  assert.equal(rows[0]._meituan_business_metric_missing.includes('exposure_users'), true);
});

test('Meituan order API aggregates sale price and room nights without promoting floor or guarantee money', () => {
  const rows = normalizeMeituanOrderRows({
    data: {
      total: 3,
      results: [
        {
          price: 81563,
          floorPrice: 65359,
          totalFee: 74605,
          roomCount: 1,
          checkInDateString: '2026-07-20',
          checkOutDateString: '2026-07-21',
          partRefundInfo: { totalRoomNightCount: 1 },
          orderBasePriceModel: { salePrice: { price: 81563 }, floorPrice: { price: 65359 } },
        },
        {
          price: 86395,
          floorPrice: 69231,
          totalFee: 75225,
          roomCount: 1,
          checkInDateString: '2026-07-21',
          checkOutDateString: '2026-07-22',
          partRefundInfo: { totalRoomNightCount: 1 },
          orderBasePriceModel: { salePrice: { price: 86395 }, floorPrice: { price: 69231 } },
        },
        {
          price: 86395,
          floorPrice: 69231,
          totalFee: 75225,
          roomCount: 1,
          checkInDateString: '2026-07-22',
          checkOutDateString: '2026-07-23',
          partRefundInfo: { totalRoomNightCount: 1 },
          orderBasePriceModel: { salePrice: { price: 86395 }, floorPrice: { price: 69231 } },
        },
      ],
    },
  }, {
    endpointPath: '/api/v1/ebooking/orders',
    requestDateEvidence: { date: '2026-07-19', date_source: 'request.query.startTime' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].amount, 2543.53);
  assert.equal(rows[0].quantity, 3);
  assert.equal(rows[0].book_order_num, 3);
  assert.equal(rows[0].date_basis, 'order_date');
  assert.equal(rows[0].date_source, 'request.query.startTime');
  assert.equal(rows[0].order_count_basis, 'listed_orders');
  assert.equal(rows[0].room_nights_basis, 'booked_room_nights');
  assert.equal(rows[0].record_kind, 'order_daily_aggregate');
  assert.equal(rows[0].amount_scope, 'meituan_sale_price_total');
  assert.equal(rows[0].amount_source_unit, 'cent');
  assert.equal(rows[0].floor_price_used_as_revenue, false);
  assert.equal(rows[0].guarantee_amount_used_as_revenue, false);
  assert.equal(rows[0].dataDate, '2026-07-19');
});

test('Meituan order API refuses to promote an incomplete page into a daily total', () => {
  const rows = normalizeMeituanOrderRows({
    data: {
      total: 2,
      results: [{
        price: 10000,
        roomCount: 1,
        checkInDateString: '2026-07-20',
        checkOutDateString: '2026-07-21',
      }],
    },
  }, {
    endpointPath: '/api/v1/ebooking/orders',
    requestDateEvidence: { date: '2026-07-19', date_source: 'request.query.startTime' },
  });

  assert.deepEqual(rows, []);
});

test('Meituan order flow replay keeps the verified period and requests both directions', () => {
  const urls = buildMeituanOrderFlowReplayUrls(
    'https://eb.meituan.com/api/v1/ebooking/peerRank/order/loss/query?partnerId=42&lossType=0&startDate=20260707&endDate=20260713',
  );
  assert.equal(urls.length, 2);
  assert.deepEqual(urls.map(value => new URL(value).searchParams.get('lossType')), ['0', '1']);
  urls.forEach(value => {
    const url = new URL(value);
    assert.equal(url.hostname, 'eb.meituan.com');
    assert.equal(url.pathname, '/api/v1/ebooking/peerRank/order/loss/query');
    assert.equal(url.searchParams.get('partnerId'), '42');
    assert.equal(url.searchParams.get('startDate'), '20260707');
    assert.equal(url.searchParams.get('endDate'), '20260713');
  });
  assert.deepEqual(buildMeituanOrderFlowReplayUrls('https://example.com/api/v1/ebooking/peerRank/order/loss/query?startDate=20260707&endDate=20260713'), []);
});

test('Meituan traffic card response maps to P0 traffic fields', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      data: {
        rtDataUpdateTime: 'updated at 2026/06/15 04:30',
        cards: [
          { id: 'EXPOSE_PV_CNT', title: 'exposure', value: '120' },
          { id: 'INTENTION_UV', title: 'visitors', value: '40' },
          { id: 'PAY_ORDER_CNT_UV', title: 'conversion', value: '12.5' },
          { id: 'PAY_ORDER_CNT', title: 'orders', value: '5' },
        ],
      },
    },
  }, {
    defaultDataDate: '2026-06-15',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].dataDate, '2026-06-15');
  assert.equal(rows[0].date_source, 'data.data.cards.rtDataUpdateTime');
  assert.equal(rows[0].listExposure, 120);
  assert.equal(rows[0].detailExposure, 40);
  assert.equal(rows[0].flowRate, undefined);
  assert.equal(rows[0].browsePayRate, 12.5);
  assert.equal(rows[0].orderSubmitNum, 5);
  assert.equal(rows[0].orderFillingNum, undefined);
  assert.equal(rows[0]._order_filling_source_policy, undefined);
  assert.equal(rows[0]._source_path, 'data.data.cards');
  assert.equal(rows[0]._meituan_card_metric_sources.list_exposure.card_id, 'EXPOSE_PV_CNT');
  assert.deepEqual(rows[0]._observed_traffic_metric_keys, [
    'list_exposure',
    'detail_exposure',
  ]);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), true);
});

test('Meituan traffic card response maps title aliases and non-value fields', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      cards: [
        { title: '\u66dd\u5149\u4eba\u6570', valueText: '320' },
        { title: '\u8be6\u60c5\u9875\u6d4f\u89c8\u4eba\u6570\uff08UV\uff09', displayValue: '80' },
        { title: '\u6d4f\u89c8-\u652f\u4ed8\u8f6c\u5316\u7387', dataValue: '6.25%' },
        { title: '\u652f\u4ed8\u8ba2\u5355\u6570', currentValue: '5' },
      ],
    },
  }, {
    requestDateEvidence: { date: '2026-07-04', date_source: 'request.query.date' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].dataDate, '2026-07-04');
  assert.equal(rows[0].date_source, 'request.query.date');
  assert.equal(rows[0].listExposure, 320);
  assert.equal(rows[0].detailExposure, 80);
  assert.equal(rows[0].flowRate, undefined);
  assert.equal(rows[0].browsePayRate, 6.25);
  assert.equal(rows[0].orderSubmitNum, 5);
  assert.equal(rows[0].orderFillingNum, undefined);
  assert.equal(rows[0]._meituan_card_metric_sources.list_exposure.source_path, 'data.cards.0.valueText');
  assert.equal(rows[0]._meituan_card_metric_sources.detail_exposure.source_path, 'data.cards.1.displayValue');
  assert.equal(rows[0]._meituan_card_metric_sources.browse_to_pay_rate.source_path, 'data.cards.2.dataValue');
  assert.equal(rows[0]._meituan_card_metric_sources.order_submit_num.source_path, 'data.cards.3.currentValue');
  assert.equal(isImportableMeituanTrafficRow(rows[0]), true);
});

test('Meituan traffic cards do not assign an unqualified conversion rate to either funnel stage', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      cards: [
        { id: 'EXPOSE_PV_CNT', value: '120' },
        { id: 'INTENTION_UV', value: '40' },
        { id: 'CONVERSION_RATE', title: '\u8f6c\u5316\u7387', value: '12.5%' },
        { id: 'PAY_ORDER_CNT', value: '5' },
      ],
    },
  }, {
    requestDateEvidence: { date: '2026-07-04', date_source: 'request.query.date' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].flowRate, undefined);
  assert.equal(rows[0].browsePayRate, undefined);
  assert.deepEqual(rows[0]._observed_traffic_metric_keys, ['list_exposure', 'detail_exposure']);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), true);
});

test('Meituan traffic card placeholders remain non-importable', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      cards: [
        { id: 'EXPOSE_PV_CNT', title: 'exposure', value: '-' },
        { id: 'INTENTION_UV', title: 'visitors', value: 'data updating' },
        { id: 'PAY_ORDER_CNT_UV', title: 'conversion', value: '--' },
        { id: 'PAY_ORDER_CNT', title: 'orders', value: '' },
      ],
    },
  }, {
    requestDateEvidence: { date: '2026-06-15', date_source: 'request.query.date' },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].dataDate, '2026-06-15');
  assert.equal(rows[0].date_source, 'request.query.date');
  assert.equal(rows[0]._meituan_card_metric_missing.length, 4);
  assert.equal(Object.hasOwn(rows[0], '_observed_traffic_metric_keys'), false);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), false);
});

test('Meituan non-metric cards are ignored instead of becoming empty traffic rows', () => {
  const rows = normalizeMeituanTrafficCardRows({
    data: {
      cards: [
        { title: '\u95e8\u5e97\u5065\u5eb7', valueText: '\u6b63\u5e38' },
      ],
    },
  }, {
    requestDateEvidence: { date: '2026-07-04', date_source: 'request.query.date' },
  });

  assert.deepEqual(rows, []);
});

test('Meituan traffic importability requires exposure, detail visits and paid orders', () => {
  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    flowRate: 20,
    orderFillingNum: 10,
  }), false);

  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    orderSubmitNum: 3,
  }), true);
});

test('Meituan peer rank response expands peerRankData round rows', () => {
  const rows = normalizeMeituanPeerRankRows({
    data: {
      peerRankData: [
        {
          dimName: '入住间夜',
          aiMetricName: 'checkin room nights',
          roundRanks: [
            { poiId: '1001', poiName: 'Peer A', rank: 1, percent: '35.5' },
          ],
        },
      ],
    },
  }, {
    defaultDataDate: '2026-06-26',
    dateRange: '1',
    rankType: 'P_RZ',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'peer_rank');
  assert.equal(rows[0].dimension, '入住间夜');
  assert.equal(rows[0].rankType, 'P_RZ');
  assert.equal(rows[0].dataDate, '2026-06-26');
  assert.equal(rows[0]._source_path, 'data.peerRankData.0.roundRanks.0');
});

test('Meituan search keyword cards expand to search_keyword rows', () => {
  const rows = normalizeMeituanSearchKeywordRows({
    data: {
      cards: [
        {
          title: '热门搜索',
          itemList: [
            { name: '机场酒店', value: 320 },
          ],
        },
      ],
    },
  }, {
    defaultDataDate: '2026-06-26',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'search_keyword');
  assert.equal(rows[0].keyword, '机场酒店');
  assert.equal(rows[0].dimension, '机场酒店');
  assert.equal(rows[0].data_value, 320);
});

test('Meituan advertising rows preserve campaign metrics and source evidence', () => {
  const rows = normalizeMeituanAdvertisingRows({
    data: {
      cureShops: [
        {
          campaignId: 'campaign-1',
          impressions: 1200,
          clicks: 96,
          todayCost: 240,
          orderAmount: 1440,
          orderCount: 8,
        },
      ],
    },
  }, {
    defaultDataDate: '2026-06-26',
    sourcePath: 'response',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'advertising');
  assert.equal(rows[0].campaignId, 'campaign-1');
  assert.equal(rows[0].todayCost, 240);
  assert.equal(rows[0].orderAmount, 1440);
  assert.equal(rows[0]._source_path, 'response.advertising_rows.0');
});

test('Meituan flow forecast keeps semantically verified rows separate from actual traffic', () => {
  const rows = normalizeMeituanTrafficForecastRows({
    data: {
      detail: [
        { dateTime: '20260701', current: 88, peerAvg: 120 },
      ],
    },
  }, {
    forecastType: 'pv',
    forecastCaptureEpoch: 3,
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'traffic_forecast');
  assert.equal(rows[0].data_period, 'next_30_days');
  assert.equal(rows[0].forecast_type, 'pv');
  assert.equal(rows[0].dimension, 'flow_forecast_pv');
  assert.equal(rows[0].forecast_capture_epoch, 3);
  assert.equal(rows[0].dataDate, '2026-07-01');
  assert.equal(rows[0].date_source, 'row.dateTime');
  assert.equal(rows[0].data_value, 88);
  assert.equal(rows[0].peer_avg, 120);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), false);
});

test('Meituan flow forecast refuses opaque numeric metric enums', () => {
  const rows = normalizeMeituanTrafficForecastRows({
    data: {
      detail: [
        { dateTime: '20260701', current: 88, peerAvg: 120 },
      ],
    },
  }, {
    forecastType: '2',
  });

  assert.equal(rows[0].forecast_type, '');
  assert.equal(rows[0].dimension, 'flow_forecast');
});

test('Meituan flow forecast keeps an explicit empty detail list as missing, not a placeholder row', () => {
  assert.deepEqual(normalizeMeituanTrafficForecastRows({
    data: {
      titleRemark: '未来30天流量',
      detail: [],
    },
  }, {
    forecastType: 'uv',
    forecastCaptureEpoch: 4,
  }), []);
});

test('Meituan traffic DOM keeps funnel and direct source exposure facts separate', () => {
  const rows = normalizeMeituanTrafficDomText([
    '数据更新时间：2026/07/29 18:08',
    '我的酒店 同行均值 曝光人数 浏览人数 支付订单数',
    '720 500 121 100 3 2',
    '曝光-浏览 转化率 16.81% 20.00%',
    '浏览-支付 转化率 2.48%',
    '流量来源 整体曝光 = 非广告曝光 + 广告曝光',
    '流量类型 曝光量 占比 操作',
    '整体曝光 1187',
    '非广告曝光 103 9%',
    '广告曝光 1084 91%',
  ].join(' '));

  const funnel = rows.find(row => row._capture_source === 'dom:traffic:flow_funnel');
  assert.equal(funnel.listExposure, 720);
  assert.equal(funnel.detailExposure, 121);
  assert.equal(funnel.orderSubmitNum, 3);
  assert.equal(funnel.flowRate, 16.81);
  assert.equal(funnel.exposure_to_browse_rate, 16.81);
  assert.equal(funnel.browsePayRate, 2.48);
  assert.equal(funnel.dataDate, '2026-07-29');
  assert.deepEqual(funnel._observed_traffic_metric_keys, [
    'list_exposure',
    'detail_exposure',
    'flow_rate',
  ]);

  const sourceRows = rows.filter(row => row._capture_source === 'dom:traffic:source_breakdown');
  assert.deepEqual(
    Object.fromEntries(sourceRows.map(row => [row.dimension, row.data_value])),
    {
      total_exposure: 1187,
      organic_exposure: 103,
      ad_exposure: 1084,
    },
  );
  assert.equal(sourceRows.every(row => row.dataDate === undefined), true);
});

test('Meituan traffic DOM never treats a conversion percentage as exposure', () => {
  const rows = normalizeMeituanTrafficDomText(
    '曝光-浏览 转化率 16.81% 浏览-支付 转化率 2.48% 支付订单数 3 单',
  );
  assert.equal(rows.some(row => row._capture_source === 'dom:traffic:home_summary'), false);
});

test('Meituan flow conversion becomes traffic_analysis supplemental data', () => {
  const rows = normalizeMeituanFlowAnalysisRows({
    data: {
      exposeCount: '1000',
      visitCount: '200',
      orderCount: '20',
      exposeVisitRate: '20',
      visitOrderRate: '10',
    },
  }, {
    analysisType: 'conversion',
    defaultDataDate: '2026-06-26',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'traffic_analysis');
  assert.equal(rows[0].analysis_type, 'conversion_funnel');
  assert.equal(rows[0].listExposure, 1000);
  assert.equal(rows[0].detailExposure, 200);
  assert.equal(rows[0].orderSubmitNum, 20);
  assert.equal(rows[0].flowRate, 20);
  assert.equal(rows[0].browsePayRate, 10);
  assert.notEqual(rows[0].data_type, 'traffic');
});

test('Meituan myHotel funnel response becomes a truthful core traffic row', () => {
  const rows = normalizeMeituanFlowAnalysisRows({
    data: {
      indexName: {
        exposureUV: '曝光人数',
        intentionUV: '浏览人数',
        payOrderCnt: '支付订单数',
      },
      myHotel: {
        exposureUV: 81,
        intentionUV: 14,
        payOrderCnt: 2,
        intentionPerExposure: '17.28%',
        payOrderPerIntention: '14.29%',
      },
    },
  }, {
    defaultDataDate: '2026-07-18',
    trafficCaptureEpoch: 12,
    trafficRelativeRange: '\u6628\u65e5',
    trafficEvidenceSource: 'page.traffic_period_selection.readback',
    trafficMarker: 'meituan_traffic_yesterday_tab',
    trafficRequestSequence: 22,
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'traffic');
  assert.equal(rows[0]._source_path, 'data.myHotel');
  assert.equal(rows[0].exposureUV, 81);
  assert.equal(rows[0].intentionUV, 14);
  assert.equal(rows[0].payOrderCnt, 2);
  assert.equal(rows[0].intentionPerExposure, '17.28%');
  assert.equal(rows[0].exposure_to_browse_rate, 17.28);
  assert.equal(rows[0].browse_pay_rate, 14.29);
  assert.equal(rows[0].flowRate, 17.28);
  assert.equal(rows[0].order_filling_num, undefined);
  assert.equal(rows[0].date_source, 'capture_context.default_data_date');
  assert.equal(rows[0].traffic_capture_epoch, 12);
  assert.equal(rows[0].traffic_relative_range, '\u6628\u65e5');
  assert.equal(rows[0].traffic_evidence_source, 'page.traffic_period_selection.readback');
  assert.equal(rows[0].traffic_marker, 'meituan_traffic_yesterday_tab');
  assert.equal(rows[0].traffic_request_sequence, 22);
  assert.deepEqual(rows[0]._observed_traffic_metric_keys, [
    'list_exposure',
    'detail_exposure',
    'flow_rate',
  ]);
});

test('Meituan myHotel observed marker cannot be forged by normalized aliases', () => {
  const rows = normalizeMeituanFlowAnalysisRows({
    data: {
      myHotel: {
        exposureUV: 81,
        listExposure: 999,
        _observed_traffic_metric_keys: [
          'list_exposure',
          'detail_exposure',
          'flow_rate',
        ],
      },
    },
  }, {
    defaultDataDate: '2026-07-18',
  });

  assert.equal(rows.length, 1);
  assert.deepEqual(rows[0]._observed_traffic_metric_keys, ['list_exposure']);
});

test('Meituan dateRange=1 becomes historical yesterday evidence only for Shanghai yesterday', () => {
  const response = {
    data: {
      myHotel: {
        exposureUV: 66,
        intentionUV: 9,
        payOrderCnt: 0,
        intentionPerExposure: '13.64%',
        payOrderPerIntention: '0.00%',
      },
    },
  };
  const verified = normalizeMeituanFlowAnalysisRows(response, {
    dateRange: '1',
    defaultDataDate: '2026-07-27',
    capturedAt: '2026-07-27T17:48:00.000Z',
  });
  const mismatch = normalizeMeituanFlowAnalysisRows(response, {
    dateRange: '1',
    defaultDataDate: '2026-07-26',
    capturedAt: '2026-07-27T17:48:00.000Z',
  });

  assert.equal(verified.length, 1);
  assert.equal(verified[0].dataDate, '2026-07-27');
  assert.equal(verified[0].dateRange, '1');
  assert.equal(verified[0].date_source, 'request.query.dateRange=1');
  assert.equal(verified[0].data_period, 'historical_daily');
  assert.equal(mismatch[0].date_source, 'capture_context.default_data_date');
  assert.equal(mismatch[0].data_period, undefined);
});

test('Meituan order flow response expands verified summary and hotel detail rows', () => {
  const rows = normalizeMeituanOrderFlowRows({
    status: 0,
    data: {
      lossTotalCnt: 83,
      lossTotalPayRoomNight: 111,
      lossTotalPayAmount: '42047.7400',
      poiStar: '经济型',
      orderLossPeerDetails: [{
        poiId: 9001,
        poiName: '同行酒店',
        frontImg: 'https://example.test/hotel.jpg',
        lossPoiStar: '高档型',
        distance: 3560,
        score: 4.9,
        lowestPrice: 571,
        circleName: '商圈',
        vipTag: true,
        lossOrderCount: 7,
        lossOrderRatio: '0.0686',
        lossSinglePayAmount: '5234.0000',
        lossRoomList: [{ lossRoomName: '大床房', lossRoomCnt: 4 }],
      }],
    },
  }, {
    orderFlowDirection: 'loss',
    periodStart: '20260707',
    periodEnd: '20260713',
  });

  assert.equal(rows.length, 2);
  assert.equal(rows[0].data_type, 'order_flow');
  assert.equal(rows[0].order_flow_row_type, 'summary');
  assert.equal(rows[0].order_flow_period, 'last_7_days');
  assert.equal(rows[0].dataDate, '2026-07-13');
  assert.equal(rows[0].date_source, 'request.query.endDate');
  assert.equal(rows[0].order_count, 83);
  assert.equal(rows[0].room_nights, 111);
  assert.equal(rows[0].amount, 42047.74);
  assert.equal(rows[1].order_flow_row_type, 'hotel_detail');
  assert.equal(rows[1].order_count, 7);
  assert.equal(rows[1].order_ratio, 0.0686);
  assert.equal(rows[1].amount, 5234);
  assert.deepEqual(rows[1].lossRoomList, [{ lossRoomName: '大床房', lossRoomCnt: 4 }]);
});

test('Meituan order flow preserves authoritative zero values and rejects incomplete envelopes', () => {
  const zeroRows = normalizeMeituanOrderFlowRows({
    data: {
      lossTotalCnt: 0,
      lossTotalPayRoomNight: 0,
      lossTotalPayAmount: '0.0000',
      orderLossPeerDetails: [],
    },
  }, {
    orderFlowDirection: 'inflow',
    periodStart: '2026-07-13',
    periodEnd: '2026-07-13',
  });
  assert.equal(zeroRows.length, 1);
  assert.equal(zeroRows[0].order_flow_period, 'yesterday');
  assert.equal(zeroRows[0].order_count, 0);
  assert.equal(zeroRows[0].room_nights, 0);
  assert.equal(zeroRows[0].amount, 0);

  assert.deepEqual(normalizeMeituanOrderFlowRows({ data: { lossTotalCnt: 2 } }, {
    orderFlowDirection: 'loss',
    periodStart: '2026-07-13',
    periodEnd: '2026-07-13',
  }), []);
});

test('Meituan traffic importability requires every P0 field group', () => {
  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    flowRate: 20,
    orderFillingNum: 10,
  }), false);

  assert.equal(isImportableMeituanTrafficRow({
    listExposure: 100,
    detailExposure: 50,
    flowRate: 20,
    orderFillingNum: 10,
    orderSubmitNum: 3,
  }), true);
});
