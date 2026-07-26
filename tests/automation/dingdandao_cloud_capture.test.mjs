import assert from 'node:assert/strict';
import test from 'node:test';
import {
  buildCaptureFromDingdandaoResponses,
  buildCaptureFromSnapshot,
  DINGDANDAO_API_PATHS,
  DINGDANDAO_DETAIL_TYPES,
  DINGDANDAO_TREND_TYPES,
  extractNetworkCandidate,
} from '../../scripts/dingdandao_cloud_capture.mjs';

const hotelName = '\u6566\u714c\u6f20\u84dd\u65b0';

test('visible same-day facts map to a sanitized Dingdandao capture without defaults', () => {
  const rows = Array.from({ length: 16 }, (_, index) => [
    '\u5927\u5e8a\u623f',
    `R${index + 1}`,
    String(index === 15 ? 633.39 : 633.46),
  ]);
  rows.push(['', '\u5408\u8ba1', '10135.29']);
  const capture = buildCaptureFromSnapshot({
    bodyText: [
      '\u7ecf\u8425\u6307\u6807',
      '\u603b\u623f\u8d39', '10135.29',
      'ADR', '633.46',
      '\u5165\u4f4f\u7387', '100%',
      'RevPAR', '633.46',
      '\u7d2f\u8ba1\u552e\u51fa\u95f4\u591c', '16',
      '\u5e73\u5747\u6bcf\u65e5\u95f4\u591c', '16',
      '\u7edf\u8ba1\u65e5\u671f\uff1a2026-07-27',
    ].join('\n'),
    controls: [{
      label: '\u5f53\u524d\u95e8\u5e97',
      context: '\u95e8\u5e97\u9009\u62e9',
      selectedText: hotelName,
      optionValue: 'provider-hotel-5',
      authoritative: true,
    }],
    tables: [{
      headers: ['\u623f\u578b', '\u623f\u95f4', '\u623f\u8d39'],
      rows,
    }],
  }, {
    expectedHotelName: hotelName,
    targetDate: '2026-07-27',
    capturedAt: '2026-07-27T08:00:00+08:00',
  });

  assert.equal(capture.business_date, '2026-07-27');
  assert.equal(capture.provider_hotel_id, 'provider-hotel-5');
  assert.equal(capture.provider_hotel_name, hotelName);
  assert.equal(capture.identity_evidence_type, 'platform_store_selector');
  assert.deepEqual(capture.summary, {
    total_room_fee: 10135.29,
    adr: 633.46,
    occupancy_rate_percent: 100,
    revpar: 633.46,
    sold_room_nights: 16,
    average_daily_room_nights: 16,
  });
  assert.equal(capture.room_fee_details.filter((row) => row.row_kind === 'room').length, 16);
  assert.equal(capture.room_fee_details.at(-1).row_kind, 'grand_total');
  assert.equal(capture.target_date_matches, true);
  assert.equal(capture.capture_method, 'browser_assist_dom');
  assert.equal(Object.keys(capture.field_trace).length, 6);
});

test('structured same-origin response is accepted only when one object contains all facts', () => {
  const incomplete = extractNetworkCandidate({
    data: { totalRoomFee: 10135.29, adr: 633.46, hotelName },
  }, '/api/incomplete');
  assert.equal(incomplete, null);

  const complete = extractNetworkCandidate({
    data: {
      totalRoomFee: 10135.29,
      adr: 633.46,
      occupancyRate: 100,
      revpar: 633.46,
      soldRoomNights: 16,
      averageDailyRoomNights: 16,
      hotelId: 'provider-hotel-5',
      hotelName,
      businessDate: '2026-07-27',
    },
  }, '/api/verified-read');
  assert.equal(complete.present, 6);
  assert.equal(complete.provider_hotel_id, 'provider-hotel-5');
  assert.equal(complete.business_date, '2026-07-27');
  assert.match(complete.field_trace.total_room_fee, /^API:\/api\/verified-read#/);
});

test('real Dingdandao response shapes preserve zero room facts and hierarchy totals', () => {
  const targetDate = '2026-07-27';
  const ok = (path, data, queryType = undefined) => ({
    method: 'POST',
    path,
    status: 200,
    ...(queryType === undefined ? {} : { query_type: queryType }),
    payload: { code: '1', msg: '\u6210\u529f', data },
  });
  const records = [
    ok(DINGDANDAO_API_PATHS.identity, {
      id: 'provider-hotel-5',
      name: hotelName,
    }),
    ok(`${DINGDANDAO_API_PATHS.total}/county`, {
      totalRoomFee: 4573.08,
      adr: 411.18,
      occ: 44.1,
      revPar: 181.33,
      totalSalesNight: 11.12,
      adn: 11.12,
    }),
    ok(DINGDANDAO_API_PATHS.total, {
      totalRoomFee: 6450.14,
      adr: 645.01,
      occ: 66.67,
      revPar: 430.01,
      totalSalesNight: 10,
      adn: 10,
    }),
    ok(DINGDANDAO_API_PATHS.sumDetail, {
      list: [{
        roomTypeId: 'wrong-room-type',
        roomTypeName: '\u95f4\u591c\u660e\u7ec6',
      }],
    }, DINGDANDAO_DETAIL_TYPES.roomNights),
    ok(DINGDANDAO_API_PATHS.sumDetail, {
      list: [{
        roomTypeId: 'room-type-1',
        roomTypeName: '\u666f\u89c2\u5927\u5e8a\u623f',
      }],
    }, DINGDANDAO_DETAIL_TYPES.roomFee),
    ok(DINGDANDAO_API_PATHS.dailyDetail, {
      list: [{
        roomTypeId: 'wrong-room-type',
        roomId: 'wrong-room',
        roomName: 'wrong-room',
        dailyRoomRate: [{ date: targetDate, price: 10 }],
      }],
    }, DINGDANDAO_DETAIL_TYPES.roomNights),
    ok(DINGDANDAO_API_PATHS.dailyDetail, {
      list: [
        {
          roomTypeId: 'room-type-1',
          roomId: 'room-1',
          roomName: 'V21',
          dailyRoomRate: [{ date: targetDate, price: 0 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: 'room-2',
          roomName: 'V22',
          dailyRoomRate: [{ date: targetDate, price: 1038 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: '0',
          roomName: '\u672a\u6392\u623f',
          dailyRoomRate: [{ date: targetDate, price: 0 }],
        },
        {
          roomTypeId: 'room-type-1',
          roomId: null,
          roomName: '\u666f\u89c2\u5927\u5e8a\u623f\u5c0f\u8ba1',
          dailyRoomRate: [{ date: targetDate, price: 1038 }],
        },
        {
          roomTypeId: null,
          roomId: null,
          roomName: '\u5408\u8ba1',
          dailyRoomRate: [{ date: targetDate, price: 6450.14 }],
        },
      ],
    }, DINGDANDAO_DETAIL_TYPES.roomFee),
    ok(`${DINGDANDAO_API_PATHS.trend}/county`, {
      list: [
        { date: '2026-07-26', value: 5456.66 },
        { date: targetDate, value: 4573.08 },
      ],
    }, DINGDANDAO_TREND_TYPES.totalRoomFee),
    ok(DINGDANDAO_API_PATHS.trend, {
      list: [{ date: targetDate, value: 10 }],
    }, 0),
    ok(DINGDANDAO_API_PATHS.trend, {
      list: [
        { date: '2026-07-26', value: 10679.29 },
        { date: targetDate, value: 6450.14 },
        { date: '2026-07-28', value: 99999 },
      ],
    }, DINGDANDAO_TREND_TYPES.totalRoomFee),
  ];

  const capture = buildCaptureFromDingdandaoResponses(records, {
    targetDate,
    capturedAt: '2026-07-27T08:00:00+08:00',
  });

  assert.equal(capture.capture_method, 'network_response');
  assert.equal(capture.source_api_path, DINGDANDAO_API_PATHS.total);
  assert.equal(capture.business_date, targetDate);
  assert.equal(capture.provider_hotel_name, hotelName);
  assert.equal(capture.identity_evidence_type, 'verified_api_store_identity');
  assert.deepEqual(capture.summary, {
    total_room_fee: 6450.14,
    adr: 645.01,
    occupancy_rate_percent: 66.67,
    revpar: 430.01,
    sold_room_nights: 10,
    average_daily_room_nights: 10,
  });
  assert.equal(capture.room_fee_details.length, 5);
  assert.deepEqual(capture.room_fee_details.map((row) => row.row_kind), [
    'room',
    'room',
    'unassigned',
    'room_type_total',
    'grand_total',
  ]);
  assert.equal(capture.room_fee_details[0].room_fee, 0);
  assert.equal(capture.room_fee_details[0].room_type, '\u666f\u89c2\u5927\u5e8a\u623f');
  assert.equal(capture.room_fee_details[2].room_fee, 0);
  assert.equal(capture.room_fee_details[2].room_number, '\u672a\u6392\u623f');
  assert.deepEqual(capture.trend.total_room_fee, [
    { date: '2026-07-26', value: 10679.29 },
    { date: targetDate, value: 6450.14 },
  ]);
  assert.match(capture.field_trace.total_room_fee, /businessIndicatorsTotal/);
  assert.match(capture.field_trace.provider_hotel_identity, /\/v2\/ntw\/web\/ntw\/get/);
  assert.match(capture.field_trace.room_type_names, /businessIndicatorsSumDetail/);
  assert.match(capture.field_trace.room_type_names, /type=0/);
  assert.match(capture.field_trace.room_fee_details, /businessIndicatorsDailyDetail/);
  assert.match(capture.field_trace.room_fee_details, /type=0/);
  assert.match(capture.field_trace.trend, /businessIndicatorsTrend/);
  assert.match(capture.field_trace.trend, /type=5/);
  assert.equal(capture.target_date_matches, true);
});
