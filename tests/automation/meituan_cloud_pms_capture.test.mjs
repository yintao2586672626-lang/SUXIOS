import test from 'node:test';
import assert from 'node:assert/strict';

import {
  buildCaptureFromResponses,
  IDENTITY_API,
  OVERVIEW_API,
  ROOM_API,
} from '../../scripts/meituan_cloud_pms_capture.mjs';
import {
  isPmsReadOnlyRequestAllowed,
} from '../../deploy/remote-browser/cloud_browser_gateway.mjs';

function responses(overrides = {}) {
  return {
    identityResponse: {
      status: 200,
      body: {
        code: 10000,
        data: {
          hotelName: '敦煌漠蓝新',
          hotelId: 'mt-hotel-80',
          ...(overrides.identity || {}),
        },
      },
    },
    overviewResponse: {
      status: 200,
      body: {
        code: 10000,
        data: {
          estimatedRoomAmt: 600,
          estimatedAvgRoomPrice: 100,
          estimatedRevPAR: 50,
          estimatedRoomNights: 6,
          saleNum: 6,
          businessDate: '2026-07-28',
          hotelId: 'mt-hotel-80',
          estimatedOrderNum: 5,
          ...(overrides.overview || {}),
        },
      },
    },
    roomResponse: {
      status: 200,
      body: {
        code: 10000,
        data: overrides.rooms || [
          { roomName: '大床房', roomCount: 8, saledRoomCount: 4 },
          { roomName: '双床房', roomCount: 4, saledRoomCount: 2 },
        ],
      },
    },
    expectedHotelName: '敦煌漠蓝新',
    targetDate: '2026-07-28',
    capturedAt: '2026-07-28 12:00:00',
  };
}

test('maps reviewed Meituan Cloud PMS fields into sanitized hotel facts', () => {
  const capture = buildCaptureFromResponses(responses());

  assert.equal(capture.provider_hotel_id, 'mt-hotel-80');
  assert.equal(capture.provider_hotel_name, '敦煌漠蓝新');
  assert.equal(capture.identity_evidence_type, 'verified_api_hotel_identity');
  assert.equal(capture.date_evidence_type, 'verified_api_business_date');
  assert.deepEqual(capture.summary, {
    estimated_room_revenue: 600,
    adr: 100,
    revpar: 50,
    sold_room_nights: 6,
    total_rooms: 12,
    available_rooms: 6,
    room_type_available_rooms: 6,
    occupancy_rate_percent: 50,
    sale_order_count: 5,
  });
  assert.equal(capture.collector_checks.detail_sold_matches_overview, true);
  assert.equal(capture.collector_checks.availability_difference, 0);
  assert.match(capture.field_trace.provider_hotel_identity, new RegExp(IDENTITY_API));
  assert.match(capture.field_trace.estimated_room_revenue, new RegExp(OVERVIEW_API));
  assert.match(capture.field_trace.total_rooms, new RegExp(ROOM_API));
  assert.equal(Object.hasOwn(capture, 'raw_response'), false);
  assert.equal(Object.hasOwn(capture, 'cookie'), false);
  assert.equal(Object.hasOwn(capture, 'token'), false);
});

test('keeps an availability difference visible for the server-side tolerance gate', () => {
  const capture = buildCaptureFromResponses(responses({
    overview: { saleNum: 1 },
  }));

  assert.equal(capture.summary.available_rooms, 1);
  assert.equal(capture.summary.room_type_available_rooms, 6);
  assert.equal(capture.collector_checks.availability_difference, 5);
  assert.equal(capture.collector_checks.availability_tolerance, 2);
  assert.match(capture.validation_warnings[0], /相差5间/);
});

test('fails closed when the authenticated identity API returns another hotel', () => {
  assert.throws(
    () => buildCaptureFromResponses(responses({
      identity: { hotelName: '另一家酒店' },
    })),
    /meituan_cloud_hotel_identity_mismatch/,
  );
});

test('fails closed when the identity API is unavailable', () => {
  assert.throws(
    () => buildCaptureFromResponses({
      ...responses(),
      identityResponse: { status: 500, body: null },
    }),
    /meituan_cloud_identity_api_failed/,
  );
});

test('read-only gateway allows the identity GET and two reviewed query POST paths', () => {
  assert.equal(isPmsReadOnlyRequestAllowed({
    url: `https://pms.meituan.com${IDENTITY_API}`,
    method: 'GET',
    resourceType: 'Fetch',
  }, 'meituan_cloud_pms'), true);
  for (const path of [OVERVIEW_API, ROOM_API]) {
    assert.equal(isPmsReadOnlyRequestAllowed({
      url: `https://pms.meituan.com${path}`,
      method: 'POST',
      resourceType: 'Fetch',
    }, 'meituan_cloud_pms'), true);
  }
  assert.equal(isPmsReadOnlyRequestAllowed({
    url: 'https://pms.meituan.com/hotelpms/api/v1/order/create',
    method: 'POST',
    resourceType: 'Fetch',
  }, 'meituan_cloud_pms'), false);
  assert.equal(isPmsReadOnlyRequestAllowed({
    url: `https://example.com${OVERVIEW_API}`,
    method: 'POST',
    resourceType: 'Fetch',
  }, 'meituan_cloud_pms'), false);
});
