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
