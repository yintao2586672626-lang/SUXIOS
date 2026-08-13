import assert from 'node:assert/strict';
import test from 'node:test';
import {
  buildCompetitorPublicUrl,
  classifyCtripPageUrl,
  classifyCtripRoomResponse,
  competitorCaptureScopeHash,
  ctripRequestScopeMatchesTask,
  extractCtripComparableRate,
  normalizeCollectorServer,
  normalizeCompetitorPlatform,
  sanitizedCollectorStatus,
  validateCompetitorTask,
} from '../../scripts/lib/competitor_collection_client.mjs';
import {
  loadCollectorConfig,
  reportCompetitorRate,
  runCollectorCycle,
} from '../../scripts/competitor_auto_collector.mjs';

function task(overrides = {}) {
  const captureScope = {
    ota_hotel_id: '1056408',
    check_in_date: '2026-08-13',
    check_out_date: '2026-08-14',
    adults: 2,
    children: 0,
    currency: 'CNY',
    price_basis: 'per_room_per_night',
    availability_values: ['available', 'bookable', 'unavailable', 'sold_out'],
    ...(overrides.capture_scope || {}),
  };
  return {
    task_id: '0123456789abcdef0123456789abcdef',
    capture_scope_hash: competitorCaptureScopeHash(captureScope),
    store_id: 80,
    hotel_id: 91,
    hotel_name: 'Fixture competitor',
    city: 'Xi An',
    platform: 'xc',
    ota_hotel_id: '1056408',
    ...overrides,
    capture_scope: captureScope,
  };
}

function ctripRoomResponse() {
  return {
    ResponseStatus: { Ack: 'Success' },
    data: {
      roomGroups: [{
        roomName: 'Deluxe King',
        ratePlanName: 'Flexible breakfast rate',
        displayPrice: '¥348.00',
        bookable: true,
        breakfastName: '1 breakfast',
        cancellationPolicy: 'Free cancellation before 18:00',
        paymentMode: 'Pay online',
        taxFeeIncluded: true,
        productId: 'product-348',
        packageName: 'Standard package',
      }, {
        roomName: 'Deluxe Twin',
        ratePlanName: 'Flexible breakfast rate',
        displayPrice: 328,
        stockCount: 2,
        breakfastName: '1 breakfast',
        cancellationPolicy: 'Free cancellation before 18:00',
        paymentMode: 'Pay online',
        taxFeeIncluded: true,
        productId: 'product-328',
        packageName: 'Standard package',
      }],
    },
  };
}

test('normalizes platform aliases and keeps non-loopback HTTP servers fail closed', () => {
  assert.equal(normalizeCompetitorPlatform('ctrip'), 'xc');
  assert.equal(normalizeCompetitorPlatform('meituan'), 'mt');
  assert.equal(normalizeCollectorServer('http://127.0.0.1:8080/'), 'http://127.0.0.1:8080');
  assert.throws(() => normalizeCollectorServer('http://example.com'), /https_required/u);
  assert.throws(() => normalizeCollectorServer('https://user:pass@example.com'), /server_invalid/u);
});

test('validates the immutable task scope and builds an exact Ctrip stay URL', () => {
  const normalized = validateCompetitorTask(task());
  assert.equal(normalized.platform, 'xc');
  assert.equal(normalized.capture_scope.check_in_date, '2026-08-13');
  const url = new URL(buildCompetitorPublicUrl(normalized));
  assert.equal(url.hostname, 'hotels.ctrip.com');
  assert.equal(url.pathname, '/hotels/1056408.html');
  assert.equal(url.searchParams.get('checkIn'), '2026-08-13');
  assert.equal(url.searchParams.get('checkOut'), '2026-08-14');

  const mismatched = task({ capture_scope_hash: 'f'.repeat(64) });
  mismatched.capture_scope_hash = 'f'.repeat(64);
  assert.throws(() => validateCompetitorTask(mismatched), /task_scope_invalid/u);
  assert.throws(() => validateCompetitorTask(task({
    capture_scope: { check_out_date: '2026-08-15' },
  })), /task_scope_invalid/u);
});

test('requires the OTA ID and exact stay window in the captured room request', () => {
  const assigned = task();
  assert.equal(ctripRequestScopeMatchesTask(JSON.stringify({
    hotelId: 1056408,
    checkIn: '2026-08-13',
    checkOut: '2026-08-14',
  }), assigned), true);
  assert.equal(ctripRequestScopeMatchesTask(JSON.stringify({
    hotelId: 1056408,
    checkIn: '2026-08-14',
    checkOut: '2026-08-15',
  }), assigned), false);
  assert.equal(ctripRequestScopeMatchesTask(JSON.stringify({
    hotelId: 9999999,
    checkIn: '2026-08-14',
    checkOut: '2026-08-15',
    previousHotelId: 1056408,
    previousCheckIn: '2026-08-13',
    previousCheckOut: '2026-08-14',
  }), assigned), false);
  assert.equal(ctripRequestScopeMatchesTask(new URLSearchParams({
    data: JSON.stringify({ hotelId: 1056408, checkIn: '2026-08-13', checkOut: '2026-08-14' }),
  }).toString(), assigned), true);
  assert.equal(ctripRequestScopeMatchesTask(
    'hotelId=9999999&hotelId=1056408&checkIn=2026-08-13&checkOut=2026-08-14',
    assigned,
  ), false);
});

test('keeps platform verification responses separate from zero rows', () => {
  assert.deepEqual(classifyCtripPageUrl('https://passport.ctrip.com/user/login'), {
    status: 'login_required',
    reason: 'ctrip_profile_login_required',
  });
  assert.deepEqual(classifyCtripPageUrl('https://hotels.ctrip.com/challenge/verify'), {
    status: 'verification_required',
    reason: 'ctrip_browser_verification_required',
  });
  assert.deepEqual(classifyCtripRoomResponse({
    data: { htlSpiderActionErrorCode: 4030 },
    ResponseStatus: { Ack: 'Success' },
  }), {
    status: 'verification_required',
    reason: 'ctrip_public_room_response_blocked',
    platform_code: 4030,
  });
  assert.equal(extractCtripComparableRate({ ResponseStatus: { Ack: 'Success' }, data: {} }, task(), {
    requestScopeMatched: true,
    pageIdentityMatched: true,
  }).status, 'zero_rows');
});

test('extracts only a complete comparable room rate and preserves task scope in report', () => {
  const response = ctripRoomResponse();
  response.data.recommendedHotels = [{
    roomName: 'Other hotel room',
    ratePlanName: 'Other hotel rate',
    displayPrice: 99,
    bookable: true,
    breakfastName: '1 breakfast',
    cancellationPolicy: 'Free cancellation',
    paymentMode: 'Pay online',
    taxFeeIncluded: true,
  }];
  const result = extractCtripComparableRate(response, task(), {
    responseBytes: 4096,
    requestScopeMatched: true,
    pageIdentityMatched: true,
    sourceRef: 'https://hotels.ctrip.com/hotels/1056408.html?checkIn=2026-08-13&checkOut=2026-08-14',
    collectedAt: '2026-08-12T08:00:00.000Z',
    deviceId: 'frontdesk-01',
  });
  assert.equal(result.status, 'collected');
  assert.equal(result.candidate_count, 2);
  assert.equal(result.report.price_text, '¥328.00');
  assert.equal(result.report.room_type_key, 'Deluxe Twin');
  assert.equal(result.report.task_id, task().task_id);
  assert.equal(result.report.check_in_date, '2026-08-13');
  assert.equal(result.report.check_out_date, '2026-08-14');
  assert.equal(result.report.source_method, 'local_browser_profile_response_json');
});

test('rejects explicit hotel or stay identity conflicts inside the target room response', () => {
  const response = ctripRoomResponse();
  response.data.hotelId = 9999999;
  response.data.checkIn = '2026-08-14';
  response.data.checkOut = '2026-08-15';
  response.data.roomGroups[0].hotelId = 9999999;
  const rejected = extractCtripComparableRate(response, task(), {
    responseBytes: 4096,
    requestScopeMatched: true,
    pageIdentityMatched: true,
    sourceRef: buildCompetitorPublicUrl(task()),
    collectedAt: '2026-08-12T08:00:00.000Z',
    deviceId: 'frontdesk-01',
  });
  assert.deepEqual(rejected, {
    status: 'identity_mismatch',
    reason: 'room_response_scope_mismatch',
  });

  const candidateConflict = ctripRoomResponse();
  candidateConflict.data.roomGroups[0].hotelId = 9999999;
  assert.equal(extractCtripComparableRate(candidateConflict, task(), {
    requestScopeMatched: true,
    pageIdentityMatched: true,
  }).status, 'identity_mismatch');
});

test('runs poll to capture to report as one automatic readback-verified cycle', async () => {
  const assigned = task();
  const calls = [];
  const config = await loadCollectorConfig({
    server: 'http://127.0.0.1:8080',
    deviceId: 'frontdesk-01',
    platform: 'xc',
    storeId: '80',
    headless: 'true',
  }, {
    SUXIOS_COMPETITOR_DEVICE_TOKEN: 'test-token-that-is-long-enough-123456',
  });
  const fakeFetch = async (url, options) => {
    calls.push({ url, options });
    if (url.endsWith('/api/competitor/task')) {
      return jsonResponse({ code: 200, data: [assigned] });
    }
    const body = new URLSearchParams(options.body);
    assert.equal(body.get('task_id'), assigned.task_id);
    assert.equal(body.get('check_in_date'), '2026-08-13');
    return jsonResponse({
      code: 200,
      data: {
        readback_verified: true,
        validation_status: 'valid',
        collection_status: 'collected',
        id: 901,
      },
    });
  };
  let contextClosed = false;
  const result = await runCollectorCycle(config, {
    fetchImpl: fakeFetch,
    launchContext: async () => ({ close: async () => { contextClosed = true; } }),
    collectTask: async (_context, cycleConfig, cycleTask) => extractCtripComparableRate(
      ctripRoomResponse(),
      cycleTask,
      {
        requestScopeMatched: true,
        pageIdentityMatched: true,
        sourceRef: buildCompetitorPublicUrl(cycleTask),
        collectedAt: '2026-08-12T08:00:00.000Z',
        deviceId: cycleConfig.deviceId,
      },
    ),
  });
  assert.deepEqual(result, {
    status: 'success',
    reason: '',
    task_count: 1,
    reported_count: 1,
    failed_count: 0,
  });
  assert.equal(calls.length, 2);
  assert.equal(calls[0].options.headers['X-Task-Token'], config.token);
  assert.equal(calls[1].options.headers['X-Report-Token'], config.token);
  assert.equal(contextClosed, true);
  assert.doesNotMatch(JSON.stringify(sanitizedCollectorStatus(result.status, result)), /test-token/u);
});

test('reports verification and zero-row outcomes as failed facts instead of fabricated prices', async () => {
  const assigned = task();
  let reportedBody = null;
  const config = await loadCollectorConfig({
    server: 'http://127.0.0.1:8080',
    deviceId: 'frontdesk-01',
    platform: 'xc',
    storeId: '80',
  }, {
    SUXIOS_COMPETITOR_DEVICE_TOKEN: 'test-token-that-is-long-enough-123456',
  });
  const result = await runCollectorCycle(config, {
    fetchImpl: async (url, options) => {
      if (url.endsWith('/api/competitor/task')) return jsonResponse({ code: 200, data: [assigned] });
      reportedBody = new URLSearchParams(options.body);
      return jsonResponse({
        code: 200,
        data: {
          readback_verified: true,
          validation_status: 'failed',
          collection_status: 'verification_required',
        },
      });
    },
    launchContext: async () => ({ close: async () => {} }),
    collectTask: async () => ({
      status: 'verification_required',
      reason: 'ctrip_public_room_response_blocked',
    }),
  });
  assert.equal(result.status, 'verification_required');
  assert.equal(result.reported_count, 0);
  assert.equal(result.failed_count, 1);
  assert.equal(reportedBody.get('collection_status'), 'verification_required');
  assert.equal(reportedBody.get('failure_reason'), 'ctrip_public_room_response_blocked');
  assert.equal(reportedBody.get('price_text'), null);
  assert.equal(reportedBody.get('availability'), null);
  assert.equal(reportedBody.get('check_in_date'), '2026-08-13');
});

test('rejects idempotent replay unless persisted readback and status are explicit', async () => {
  const config = await loadCollectorConfig({
    server: 'http://127.0.0.1:8080',
    deviceId: 'frontdesk-01',
    platform: 'xc',
    storeId: '80',
  }, {
    SUXIOS_COMPETITOR_DEVICE_TOKEN: 'test-token-that-is-long-enough-123456',
  });
  const report = extractCtripComparableRate(ctripRoomResponse(), task(), {
    requestScopeMatched: true,
    pageIdentityMatched: true,
    sourceRef: buildCompetitorPublicUrl(task()),
    collectedAt: '2026-08-12T08:00:00.000Z',
    deviceId: config.deviceId,
  }).report;

  await assert.rejects(reportCompetitorRate(config, report, async () => jsonResponse({
    code: 200,
    data: { id: 901, idempotent_replay: true },
  })), /readback_not_verified/u);
  await assert.rejects(reportCompetitorRate(config, report, async () => jsonResponse({
    code: 200,
    data: {
      id: 901,
      idempotent_replay: true,
      readback_verified: true,
      validation_status: 'failed',
      collection_status: 'collected',
    },
  })), /receipt_status_mismatch/u);
});

test('does not lease tasks when the local platform adapter is unavailable', async () => {
  const config = await loadCollectorConfig({
    server: 'http://127.0.0.1:8080',
    deviceId: 'frontdesk-01',
    platform: 'mt',
    storeId: '80',
  }, {
    SUXIOS_COMPETITOR_DEVICE_TOKEN: 'test-token-that-is-long-enough-123456',
  });
  let fetchCalled = false;
  const result = await runCollectorCycle(config, {
    fetchImpl: async () => {
      fetchCalled = true;
      throw new Error('unsupported adapter must not poll or lease a task');
    },
  });
  assert.deepEqual(result, {
    status: 'partial',
    reason: 'competitor_platform_adapter_unavailable',
    task_count: 0,
    reported_count: 0,
    failed_count: 0,
  });
  assert.equal(fetchCalled, false);
});

function jsonResponse(payload, status = 200) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
  };
}
