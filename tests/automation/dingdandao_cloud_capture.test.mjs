import assert from 'node:assert/strict';
import test from 'node:test';
import {
  buildCaptureFromSnapshot,
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
