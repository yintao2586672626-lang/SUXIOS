import { runCtripCookieApiCapture } from './ctrip_cookie_api_capture.mjs';

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const fixtures = new Map([
  ['queryHotCalendarInfo', {
    ResponseStatus: { Ack: 'Success' },
    otherDataList: [
      { hotSpotName: 'Gaokao', startDate: '2026-06-07', endDate: '2026-06-08' },
    ],
    resStatus: { rcode: 200, rmsg: '' },
  }],
  ['getHotelPsiV2', {
    data: { psiScore: '4.54', baseScore: '4.06', rewardScore: '0.48', deductScore: '0.00' },
  }],
  ['queryCampaignSummaryReport', {
    data: { summary: { impressions: 2635, clicks: 193, todayCost: '684.03', orderAmount: '3820.00', orderCount: 11, roas: '5.58' } },
  }],
  ['getBbkComprehensiveTable', {
    data: { bpiScore: '7.03', baseScore: '4.33', plusScore: '2.70', minusScore: '0.00' },
  }],
  ['dataCenterBusinessReportDetail', {
    data: { rows: [{ statDate: '2026-05-29', roomNights: 1, amount: '340.00', orderQuantity: 2 }] },
  }],
]);

const fetchImpl = async (url, init) => {
  const matched = [...fixtures.entries()].find(([keyword]) => url.includes(keyword));
  assertContract(matched, `unexpected fixture request: ${url}`);
  assertContract(String(init.headers.Cookie || '').includes('usertoken='), 'request must include supplied Cookie');
  return {
    ok: true,
    status: 200,
    async text() {
      return JSON.stringify(matched[1]);
    },
  };
};

const payload = await runCtripCookieApiCapture({
  profile_id: 'hotel_001',
  hotel_id: '24588',
  hotel_name: 'Fixture Hotel',
  system_hotel_id: 1,
  data_date: '2026-05-31',
  endpoints: [
    { request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo', method: 'GET' },
    { request_url: 'https://ebooking.ctrip.com/psi/api/getHotelPsiV2?hostType=HE', method: 'GET' },
    { request_url: 'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport?hostType=HE', method: 'POST', payload: { startDate: '2026-05-25', endDate: '2026-05-31' } },
    { request_url: 'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable', method: 'POST', payload: { date: '2026-05-31' } },
    { request_url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail', method: 'POST', payload: { startDate: '2026-05-29', endDate: '2026-05-29' } },
  ],
}, {
  cookies: 'usertoken=fixture; usersign=fixture',
  capturedAt: '2026-05-31T12:00:00.000Z',
  fetchImpl,
});

const metricKeys = new Set(payload.catalog_facts.map((fact) => fact.metric_key));
for (const key of [
  'hot_spot_name',
  'psi_score',
  'ad_impressions',
  'ad_cost',
  'roas',
  'bpi_score',
  'business_amount',
  'order_count',
]) {
  assertContract(metricKeys.has(key), `missing metric key ${key}`);
}

assertContract(payload.auth_status.ok === true, 'valid cookie/API capture must mark auth ok');
assertContract(payload.responses.length === 5, 'must keep each API response');
assertContract(payload.catalog_facts.length >= 20, 'must extract catalog facts from fixture responses');
assertContract(payload.standard_rows.length >= 5, 'must build standard rows from fixture responses');
assertContract(!JSON.stringify(payload).includes('usertoken=fixture'), 'output must not expose raw Cookie');

const autoPayloadRequests = [];
const autoPayloadPayload = await runCtripCookieApiCapture({
  profile_id: 'hotel_001',
  hotel_id: '24588',
  hotel_name: 'Fixture Hotel',
  system_hotel_id: 1,
  data_date: '2026-05-31',
  endpoints: [
    { request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2', method: 'POST' },
    { request_url: 'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable', method: 'POST' },
  ],
}, {
  cookies: 'usertoken=fixture; usersign=fixture',
  capturedAt: '2026-05-31T12:00:00.000Z',
  fetchImpl: async (url, init) => {
    autoPayloadRequests.push({
      url,
      payload: init.body ? JSON.parse(String(init.body)) : {},
    });
    return {
      ok: true,
      status: 200,
      async text() {
        if (url.includes('queryScanFlowDetailsV2')) {
          return JSON.stringify({
            data: {
              flowSources: [],
            },
          });
        }
        return JSON.stringify({
          data: {
            bpiScore: '7.03',
            baseScore: '4.33',
            plusScore: '2.70',
            minusScore: '0.00',
          },
        });
      },
    };
  },
});

assertContract(autoPayloadRequests.length === 2, 'auto payload endpoints should all be requested');
assertContract(autoPayloadPayload.responses.length === 2, 'auto payload capture should return both endpoint responses');
const [flowPayload, bpiPayload] = autoPayloadRequests;
assertContract(String(flowPayload.payload.startDate) === '2026-05-31', 'queryScanFlowDetailsV2 should get startDate default');
assertContract(String(flowPayload.payload.endDate) === '2026-05-31', 'queryScanFlowDetailsV2 should get endDate default');
assertContract(flowPayload.payload.hostType === 'HE', 'queryScanFlowDetailsV2 should get hostType default');
assertContract(String(bpiPayload.payload.date || '') === '2026-05-31', 'getBbkComprehensiveTable should get date default');

const coverageEndpoints = [
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryMarketDetails', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryOrderTrendV1', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotelOccupiedRoomTrendV1', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryRoomTensitiesV1', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowTransformNewV1', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryFlowSource', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryCityHotKeywords', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/querySearchFlowDetails', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/pyramidad/api/queryCampaignSummaryReport', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', pageIndex: 1, pageSize: 20, startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryUserSex', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getImIndex', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getManagementData', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getFlowSource', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getTripartiteOrderLoss', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getCompetingRank', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/getLossOrderCompeteHotel', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryScanFlowDetailsV2', method: 'POST', expects: { hostType: 'HE', platform: 'EBK', startDate: '2026-05-31', endDate: '2026-05-31' } },
  { url: 'https://bbk.ctripbiz.cn/api/getBbkComprehensiveTable', method: 'POST', expects: { hostType: 'HE', date: '2026-05-31', reportDate: '2026-05-31' } },
  { url: 'https://bbk.ctripbiz.cn/api/dataCenterBusinessReportDetail', method: 'POST', expects: { hostType: 'HE', platform: 'BBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
  { url: 'https://bbk.ctripbiz.cn/api/dataCenterComparisonReportDetail', method: 'POST', expects: { hostType: 'HE', platform: 'BBK', startDate: '2026-05-31', endDate: '2026-05-31', hotelId: '24588', nodeId: '24588' } },
];

const coverageRequests = [];
const modulePayloadCapture = await runCtripCookieApiCapture({
  profile_id: 'hotel_001',
  hotel_id: '24588',
  hotel_name: 'Fixture Hotel',
  system_hotel_id: 1,
  data_date: '2026-05-31',
  endpoints: coverageEndpoints,
}, {
  cookies: 'usertoken=fixture; usersign=fixture',
  capturedAt: '2026-05-31T12:00:00.000Z',
  fetchImpl: async (url, init) => {
    const payload = init.body ? JSON.parse(String(init.body)) : {};
    coverageRequests.push({ url, payload });
    return {
      ok: true,
      status: 200,
      async text() {
        return JSON.stringify({
          ResponseStatus: { Ack: 'Success' },
          resStatus: { rcode: 200, rmsg: '' },
          data: { ok: true },
        });
      },
    };
  },
});

assertContract(coverageRequests.length >= 20, 'module coverage should include >= 20 requests');
assertContract(modulePayloadCapture.responses.length >= 20, 'module coverage should return >= 20 responses');
let matchedCount = 0;
for (let i = 0; i < coverageRequests.length; i += 1) {
  const request = coverageRequests[i];
  const matched = coverageEndpoints.find((candidate) => {
    const expected = String(candidate.url);
    const current = String(request.url);
    return current === expected || current.startsWith(`${expected}?`);
  });
  if (!matched) {
    continue;
  }
  matchedCount += 1;
  for (const [key, value] of Object.entries(matched.expects)) {
    assertContract(String(request.payload[key] ?? '') === String(value), `${matched.url} should default ${key}`);
  }
}
assertContract(matchedCount === coverageEndpoints.length, 'module coverage capture should hit every preset endpoint');

assertContract(Array.isArray(modulePayloadCapture.responses), 'module coverage capture should return response list');

const expiredPayload = await runCtripCookieApiCapture({
  profile_id: 'hotel_001',
  hotel_id: '24588',
  hotel_name: 'Fixture Hotel',
  system_hotel_id: 1,
  data_date: '2026-05-31',
  endpoints: [
    { request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo', method: 'GET' },
  ],
}, {
  cookies: 'usertoken=expired; usersign=expired',
  capturedAt: '2026-05-31T12:00:00.000Z',
  fetchImpl: async () => ({
    ok: true,
    status: 200,
    async text() {
      return JSON.stringify({
        resStatus: { rcode: 402, rmsg: 'key.common.bff.token_expired' },
        ResponseStatus: { Ack: 'Success', Errors: [] },
      });
    },
  }),
});

assertContract(expiredPayload.auth_status.ok === false, 'token-expired JSON must not mark auth ok');
assertContract(expiredPayload.auth_status.status === 'no_business_data', 'token-expired JSON must be classified as no business data');
assertContract(expiredPayload.catalog_facts.length === 0, 'token-expired JSON must not extract catalog facts');
assertContract(expiredPayload.standard_rows.length === 0, 'token-expired JSON must not build standard rows');
assertContract(expiredPayload.errors.some((item) => item.error === 'cookie_or_permission_failed'), 'token-expired JSON must produce permission error');

const missingTokenPayload = await runCtripCookieApiCapture({
  profile_id: 'hotel_001',
  hotel_id: '24588',
  hotel_name: 'Fixture Hotel',
  system_hotel_id: 1,
  data_date: '2026-05-31',
  endpoints: [
    { request_url: 'https://ebooking.ctrip.com/restapi/soa2/24588/queryHotCalendarInfo', method: 'GET' },
  ],
}, {
  cookies: 'foo=bar',
  capturedAt: '2026-05-31T12:00:00.000Z',
  fetchImpl: async () => ({
    ok: true,
    status: 200,
    async text() {
      return JSON.stringify({
        resStatus: { rcode: 401, rmsg: 'key.common.bff.no_token' },
        ResponseStatus: { Ack: 'Success', Errors: [] },
      });
    },
  }),
});

assertContract(missingTokenPayload.auth_status.ok === false, 'no-token JSON must not mark auth ok');
assertContract(missingTokenPayload.errors.some((item) => item.error === 'cookie_or_permission_failed'), 'no-token JSON must produce permission error');

console.log(JSON.stringify({
  status: 'pass',
  responses: payload.responses.length,
  catalog_facts: payload.catalog_facts.length,
  standard_rows: payload.standard_rows.length,
  sections: payload.requested_sections,
}, null, 2));
