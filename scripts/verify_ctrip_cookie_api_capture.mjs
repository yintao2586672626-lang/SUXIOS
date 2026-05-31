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

console.log(JSON.stringify({
  status: 'pass',
  responses: payload.responses.length,
  catalog_facts: payload.catalog_facts.length,
  standard_rows: payload.standard_rows.length,
  sections: payload.requested_sections,
}, null, 2));
