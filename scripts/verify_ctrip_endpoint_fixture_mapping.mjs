import {
  buildCtripStandardRowsFromFacts,
  extractCtripCatalogFacts,
  findCtripEndpointByUrl,
} from './lib/ctrip_capture_catalog.mjs';

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function extract(url, payload, dataDate = '2026-05-31') {
  const endpoint = findCtripEndpointByUrl(url);
  assertContract(endpoint, `endpoint not mapped: ${url}`);
  const facts = extractCtripCatalogFacts(payload, {
    endpoint,
    section: endpoint.section,
    dataType: endpoint.dataType,
    hotelId: 'fixture-hotel',
    dataDate,
    capturedAt: '2026-05-31T12:00:00.000Z',
    url,
  });
  const rows = buildCtripStandardRowsFromFacts(facts, {
    systemHotelId: 1,
    hotelName: 'Fixture Hotel',
    profileId: 'fixture-hotel',
    dataDate,
  });
  return { endpoint, facts, rows };
}

function factKeys(facts) {
  return new Set(facts.map((fact) => fact.metric_key).filter(Boolean));
}

function assertFactKeys(label, facts, keys) {
  const actual = factKeys(facts);
  for (const key of keys) {
    assertContract(actual.has(key), `${label} missing fact key: ${key}`);
  }
}

function assertRow(label, rows, predicate, message) {
  assertContract(rows.some(predicate), `${label} missing standard row: ${message}`);
}

const cases = [
  {
    label: 'hot_calendar',
    url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo?_fxpcqlniredt=09031057118856912388',
    expectedEndpoint: 'hot_calendar',
    expectedSection: 'market_calendar',
    expectedFacts: ['hot_spot_name', 'start_date', 'end_date'],
    payload: {
      ResponseStatus: { Ack: 'Success' },
      otherDataList: [
        { hotSpotName: 'Pan Meichen concert', startDate: '2026-06-06', endDate: '2026-06-06' },
        { hotSpotName: 'Gaokao', startDate: '2026-06-07', endDate: '2026-06-08' },
      ],
      resStatus: { rcode: 200, rmsg: '' },
    },
    verify({ rows }) {
      assertRow('hot_calendar', rows, (row) => (
        row.data_type === 'business'
        && row.data_date === '2026-06-06'
        && row.amount === 0
        && row.quantity === 0
        && row.book_order_num === 0
        && row.raw_data?.fact_only === true
      ), 'fact-only market event row');
    },
  },
  {
    label: 'psi_overview',
    url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2?hostType=HE',
    expectedEndpoint: 'psi_overview',
    expectedSection: 'quality_psi',
    expectedFacts: ['psi_score', 'base_score', 'reward_score', 'deduct_score'],
    payload: {
      data: {
        psiScore: '4.54',
        baseScore: '4.06',
        rewardScore: '0.48',
        deductScore: '0.00',
      },
    },
    verify({ rows }) {
      assertRow('psi_overview', rows, (row) => (
        row.data_type === 'quality'
        && row.data_value === 4.54
        && row.raw_data?.metrics?.base_score === 4.06
        && row.raw_data?.metrics?.reward_score === 0.48
      ), 'PSI quality row');
    },
  },
  {
    label: 'business_service_quantity',
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportServerQuantity',
    expectedEndpoint: 'business_service_quantity',
    expectedSection: 'business_overview',
    expectedFacts: ['psi_score', 'service_score_rank', 'ctrip_rating', 'reply_rate', 'reply_rank', 'hotel_collect', 'hotel_collect_rank'],
    payload: {
      rcode: 0,
      data: {
        serviceScore: 4.96,
        serviceScoreRank: 20,
        ctripRatingall: 4.83,
        replyrate5m: 97.56,
        imScoreHtlrank: 6,
        hotelCollect: 4832,
        hotelCollectRank: 20,
      },
    },
    verify({ facts, rows }) {
      assertContract(!factKeys(facts).has('service_score'), 'business_service_quantity must not duplicate serviceScore as service_score');
      assertRow('business_service_quantity', rows, (row) => (
        row.data_type === 'quality'
        && row.data_value === 4.96
        && row.raw_data?.metrics?.psi_score === 4.96
        && row.raw_data?.rank_metrics?.service_score_rank === 20
        && row.raw_data?.metrics?.reply_rate === 97.56
      ), 'daily PSI service quality row');
    },
  },
  {
    label: 'business_market_overview',
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/sale/fetchMarketOverViewV2',
    expectedEndpoint: 'business_market_overview',
    expectedSection: 'business_overview',
    expectedFacts: ['order_amount', 'order_amount_last_week', 'amount_rank', 'room_nights', 'room_nights_last_week', 'quantity_rank', 'close_rate', 'close_rate_last_week', 'close_rate_rank', 'avg_price', 'avg_price_last_week', 'avg_price_rank'],
    payload: {
      rcode: 0,
      data: {
        amount: 429,
        synchronizationAmount: 330,
        rankOfAmount: 18,
        quantity: 6,
        synchronizationQuantity: 4,
        rankOfQuantity: 17,
        closeRate: 100,
        synchronizationCloseRate: 66.67,
        rankOfCloseRate: 1,
        averagePrice: 71.5,
        synchronizationAveragePrice: 82.5,
        rankOfAveragePrice: 20,
        bookAmount: 0,
      },
    },
    verify({ facts, rows }) {
      assertContract(!facts.some((fact) => fact.metric_key === 'order_amount' && fact.source_key === 'bookAmount'), 'bookAmount must not be treated as overview order_amount');
      assertRow('business_market_overview', rows, (row) => (
        row.data_type === 'business'
        && row.amount === 429
        && row.quantity === 6
        && row.raw_data?.metrics?.order_amount_last_week === 330
        && row.raw_data?.rank_metrics?.amount_rank === 18
        && row.raw_data?.metrics?.avg_price === 71.5
      ), 'market overview card row');
    },
  },
  {
    label: 'business_visitor_title',
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchVisitorTitleV2',
    expectedEndpoint: 'business_visitor_title',
    expectedSection: 'business_overview',
    expectedFacts: [
      'visitor_count',
      'visitor_rank',
      'visitor_count_last_week',
      'competitor_avg_visitor',
      'qunar_visitor_count',
      'qunar_visitor_rank',
      'qunar_visitor_count_last_week',
      'qunar_competitor_avg_visitor',
    ],
    payload: {
      visitorTotal: 2,
      visitorRank: 15,
      lastVisitorTotal: 16,
      competitorAvgNumber: 37,
      qunarVisitorTotal: 1,
      qunarCompetitorRank: 11,
      lastQunarVisitorTotal: 6,
      qunarCompetitorAvgNumber: 23,
    },
    verify({ rows }) {
      assertRow('business_visitor_title', rows, (row) => (
        row.data_type === 'traffic'
        && row.detail_exposure === 2
        && row.raw_data?.metrics?.visitor_count_last_week === 16
        && row.raw_data?.metrics?.qunar_visitor_count === 1
        && row.raw_data?.rank_metrics?.visitor_rank === 15
        && row.raw_data?.rank_metrics?.qunar_visitor_rank === 11
      ), 'visitor title row');
    },
  },
  {
    label: 'business_capacity',
    url: 'https://ebooking.ctrip.com/datacenter/api/dataCenter/current/fetchCapacityOverViewV4',
    expectedEndpoint: 'business_capacity',
    expectedSection: 'business_overview',
    expectedFacts: [
      'occupied_rooms',
      'occupied_rooms_sync',
      'occupied_rooms_rank',
      'competitor_avg_occupied_rooms',
      'occupancy_rate',
      'occupancy_rate_sync',
      'occupancy_rate_rank',
      'order_count',
      'order_count_sync',
      'order_count_rank',
      'competitor_avg_orders',
      'ctrip_order_count',
      'ctrip_order_count_sync',
      'ctrip_order_count_rank',
      'qunar_order_count',
      'qunar_order_count_sync',
      'qunar_order_count_rank',
      'elong_order_count',
      'elong_order_count_sync',
      'elong_order_count_rank',
    ],
    payload: {
      occupiedRooms: 2,
      synchronizationOccupiedRooms: 2,
      rankOfOccupiedRooms: 17,
      competitorsAverageOccupiedRooms: 36,
      occupancyRate: 5.71,
      synchronizationOccupancyRate: 5.71,
      rankOfOccupancyRate: 18,
      orderQuantity: 0,
      synchronizationOrderQuantity: 1,
      rankOfOrderQuantity: 15,
      competitorsAverageOrderQuantity: 3,
      ctripOrderQuantity: 0,
      ctripSynchronizationOrderQuantity: 1,
      ctripRankOfOrderQuantity: 13,
      qunarOrderQuantity: 0,
      qunarSynchronizationOrderQuantity: 0,
      qunarRankOfOrderQuantity: 9,
      elongOrderQuantity: 0,
      elongSynchronizationOrderQuantity: 0,
      elongRankOfOrderQuantity: 4,
    },
    verify({ rows }) {
      assertRow('business_capacity', rows, (row) => (
        row.data_type === 'business'
        && row.quantity === 2
        && row.book_order_num === 0
        && row.raw_data?.metrics?.occupancy_rate === 5.71
        && row.raw_data?.metrics?.order_count_sync === 1
        && row.raw_data?.metrics?.ctrip_order_count_sync === 1
        && row.raw_data?.rank_metrics?.occupied_rooms_rank === 17
        && row.raw_data?.rank_metrics?.order_count_rank === 15
      ), 'capacity overview row');
    },
  },
  {
    label: 'ads_summary_report',
    url: 'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport?hostType=HE',
    expectedEndpoint: 'ads_summary_report',
    expectedSection: 'ads_pyramid',
    expectedFacts: ['ad_impressions', 'ad_clicks', 'ad_cost', 'ad_order_amount', 'ad_orders', 'roas'],
    payload: {
      data: {
        summary: {
          impressions: 2635,
          clicks: 193,
          todayCost: '684.03',
          orderAmount: '3820.00',
          orderCount: 11,
          roas: '5.58',
        },
      },
    },
    verify({ rows }) {
      assertRow('ads_summary_report', rows, (row) => (
        row.data_type === 'advertising'
        && row.amount === 684.03
        && row.list_exposure === 2635
        && row.detail_exposure === 193
        && row.raw_data?.metrics?.ad_order_amount === 3820
        && row.raw_data?.metrics?.roas === 5.58
      ), 'ads performance row');
    },
  },
  {
    label: 'biztravel_bpi_table',
    url: 'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable',
    expectedEndpoint: 'biztravel_bpi_table',
    expectedSection: 'biztravel_bpi',
    expectedFacts: ['bpi_score', 'basis_score', 'plus_score', 'minus_score'],
    payload: {
      data: {
        bpiScore: '7.03',
        baseScore: '4.33',
        plusScore: '2.70',
        minusScore: '0.00',
      },
    },
    verify({ rows }) {
      assertRow('biztravel_bpi_table', rows, (row) => (
        row.data_type === 'quality'
        && row.data_value === 7.03
        && row.raw_data?.metrics?.basis_score === 4.33
      ), 'BPI quality row');
    },
  },
  {
    label: 'biztravel_business_report',
    url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail',
    expectedEndpoint: 'biztravel_business_report',
    expectedSection: 'biztravel_business_report',
    expectedFacts: ['business_room_nights', 'business_amount', 'order_count'],
    payload: {
      data: {
        rows: [
          { statDate: '2026-05-29', roomNights: 1, amount: '340.00', orderQuantity: 2 },
        ],
      },
    },
    verify({ rows }) {
      assertRow('biztravel_business_report', rows, (row) => (
        row.data_type === 'business'
        && row.amount === 340
        && row.quantity === 1
        && row.book_order_num === 2
      ), 'biztravel business row');
    },
  },
  {
    label: 'biztravel_competitor_report',
    url: 'https://bbk.ctripbiz.cn/api/dataCenterComparisonReportDetail',
    expectedEndpoint: 'biztravel_competitor_report',
    expectedSection: 'biztravel_competitor',
    expectedFacts: ['business_amount', 'order_count', 'list_exposure', 'conversion_rate'],
    payload: {
      data: {
        cards: [
          { orderAmount: '340.00', orderQuantity: 2, impressions: 48, conversionRate: '5.70%' },
        ],
      },
    },
    verify({ rows }) {
      assertRow('biztravel_competitor_report', rows, (row) => (
        row.amount === 340
        && row.book_order_num === 2
        && row.list_exposure === 48
        && row.flow_rate === 5.7
      ), 'biztravel competitor row');
    },
  },
];

const results = [];
for (const item of cases) {
  const result = extract(item.url, item.payload);
  assertContract(result.endpoint.id === item.expectedEndpoint, `${item.label} endpoint id mismatch`);
  assertContract(result.endpoint.section === item.expectedSection, `${item.label} section mismatch`);
  assertFactKeys(item.label, result.facts, item.expectedFacts);
  item.verify(result);
  results.push({
    label: item.label,
    endpoint_id: result.endpoint.id,
    section: result.endpoint.section,
    fact_count: result.facts.length,
    row_count: result.rows.length,
    fact_keys: [...factKeys(result.facts)].sort(),
  });
}

console.log(JSON.stringify({
  status: 'pass',
  verified_cases: results,
}, null, 2));
