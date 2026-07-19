import test from 'node:test';
import assert from 'node:assert/strict';
import {
  buildMeituanOrderFlowReplayUrls,
  isImportableMeituanTrafficRow,
  normalizeMeituanFlowAnalysisRows,
  normalizeMeituanOrderRows,
  normalizeMeituanOrderFlowRows,
  normalizeMeituanPeerRankRows,
  normalizeMeituanSearchKeywordRows,
  normalizeMeituanTrafficCardRows,
  normalizeMeituanTrafficForecastRows,
} from '../../scripts/lib/meituan_browser_capture_normalize.mjs';

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
  assert.equal(rows[0].flowRate, 12.5);
  assert.equal(rows[0].orderSubmitNum, 5);
  assert.equal(rows[0].orderFillingNum, 5);
  assert.equal(rows[0]._order_filling_source_policy, 'meituan_metric_cards_no_separate_order_filling_step_pay_order_count_used');
  assert.equal(rows[0]._source_path, 'data.data.cards');
  assert.equal(rows[0]._meituan_card_metric_sources.list_exposure.card_id, 'EXPOSE_PV_CNT');
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
  assert.equal(rows[0].flowRate, 6.25);
  assert.equal(rows[0].orderSubmitNum, 5);
  assert.equal(rows[0].orderFillingNum, 5);
  assert.equal(rows[0]._meituan_card_metric_sources.list_exposure.source_path, 'data.cards.0.valueText');
  assert.equal(rows[0]._meituan_card_metric_sources.detail_exposure.source_path, 'data.cards.1.displayValue');
  assert.equal(rows[0]._meituan_card_metric_sources.flow_rate.source_path, 'data.cards.2.dataValue');
  assert.equal(rows[0]._meituan_card_metric_sources.order_submit_num.source_path, 'data.cards.3.currentValue');
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

test('Meituan flow forecast keeps forecast rows separate from actual traffic', () => {
  const rows = normalizeMeituanTrafficForecastRows({
    data: {
      detail: [
        { dateTime: '20260701', current: 88, peerAvg: 120 },
      ],
    },
  }, {
    forecastType: '2',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'traffic_forecast');
  assert.equal(rows[0].data_period, 'next_30_days');
  assert.equal(rows[0].forecast_type, '2');
  assert.equal(rows[0].dataDate, '2026-07-01');
  assert.equal(rows[0].date_source, 'row.dateTime');
  assert.equal(rows[0].data_value, 88);
  assert.equal(rows[0].peer_avg, 120);
  assert.equal(isImportableMeituanTrafficRow(rows[0]), false);
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
  assert.equal(rows[0].flowRate, 10);
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
    dateRange: '0',
    defaultDataDate: '2026-07-18',
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].data_type, 'traffic');
  assert.equal(rows[0]._source_path, 'data.myHotel');
  assert.equal(rows[0].exposureUV, 81);
  assert.equal(rows[0].intentionUV, 14);
  assert.equal(rows[0].payOrderCnt, 2);
  assert.equal(rows[0].intentionPerExposure, '17.28%');
  assert.equal(rows[0].browse_pay_rate, 14.29);
  assert.equal(rows[0].order_filling_num, undefined);
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
