import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import {
  DINGDANDAO_API_PATHS,
} from '../../scripts/dingdandao_cloud_capture.mjs';
import {
  normalizeDingdandaoIabCapture,
} from '../../scripts/normalize_dingdandao_iab_capture.mjs';

const targetDate = '2026-08-29';
const capturedAt = '2026-08-31T01:48:00+08:00';
const hotelName = '\u6566\u714c\u6f20\u84dd';
const hotelId = '5206408';
const networkId = 'network_5206408';
const script = fileURLToPath(new URL(
  '../../scripts/normalize_dingdandao_iab_capture.mjs',
  import.meta.url,
));

function responseData(path) {
  if (path === DINGDANDAO_API_PATHS.identity) {
    return { id: hotelId, name: hotelName };
  }
  if (path === DINGDANDAO_API_PATHS.total) {
    return {
      totalRoomFee: 6450.14,
      adr: 645.01,
      occ: 50,
      revPar: 322.51,
      totalSalesNight: 10,
      adn: 10,
    };
  }
  if (path === DINGDANDAO_API_PATHS.revenueOverview) {
    return {
      totalConsume: 6450.14,
      subjects: [{
        type: -1,
        subjectTypeName: '\u5408\u8ba1',
        singleDayTotalConsume: 6450.14,
        subjectTypeTotalConsume: null,
        percent: 100,
        subjectTypeDates: [{ date: targetDate, consume: 6450.14 }],
      }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.sumDetail) {
    return {
      list: [{
        roomTypeId: 'room-type-1',
        roomTypeName: '\u666f\u89c2\u5927\u5e8a\u623f',
        roomList: [{ roomId: 'room-1', roomName: 'V21', sum: 6450.14 }],
      }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.dailyDetail) {
    return {
      list: [{
        roomTypeId: 'room-type-1',
        roomId: 'room-1',
        roomName: 'V21',
        dailyRoomRate: [{ date: targetDate, price: 6450.14 }],
      }],
    };
  }
  if (path === DINGDANDAO_API_PATHS.trend) {
    return { list: [{ date: targetDate, value: 6450.14 }] };
  }
  throw new Error('unexpected_fixture_path');
}

function record(path, requestBody) {
  return {
    url: `https://www.dingdandao.com${path}`,
    method: 'POST',
    status: 200,
    request_body: requestBody,
    response_json: {
      code: 1,
      errorDetail: null,
      data: responseData(path),
    },
  };
}

function inputFixture() {
  const identityBody = { TIMEZONEOFFSET: -480, ntwNum: networkId };
  const datedBody = {
    TIMEZONEOFFSET: -480,
    ntwNum: networkId,
    startDate: targetDate,
    endDate: targetDate,
  };
  return {
    captured_at: capturedAt,
    records: [
      record(DINGDANDAO_API_PATHS.identity, identityBody),
      record(DINGDANDAO_API_PATHS.total, {
        ...datedBody,
        festivalType: -999999,
      }),
      record(DINGDANDAO_API_PATHS.revenueOverview, {
        ...datedBody,
        festivalType: -999999,
      }),
      record(DINGDANDAO_API_PATHS.sumDetail, { ...datedBody, type: 0 }),
      record(DINGDANDAO_API_PATHS.dailyDetail, { ...datedBody, type: 0 }),
      record(DINGDANDAO_API_PATHS.trend, { ...datedBody, type: 5 }),
    ],
  };
}

function options() {
  return {
    targetDate,
    expectedHotelName: hotelName,
    expectedProviderHotelId: hotelId,
    collectionMode: 'operating_indicators',
    now: new Date('2026-08-31T02:00:00.000Z'),
  };
}

test('normalizes six operator-supplied IAB responses as an unverified supplement', () => {
  const result = normalizeDingdandaoIabCapture(inputFixture(), options());

  assert.equal(result.status, 'normalized_browser_response_supplement');
  assert.equal(result.record_count, 6);
  assert.equal(result.raw_response_exposed, false);
  assert.equal(result.session_material_exposed, false);
  assert.equal(result.sensitive_values_exposed, false);
  assert.equal(result.capture.provider_hotel_id, hotelId);
  assert.equal(result.capture.provider_hotel_name, hotelName);
  assert.equal(result.capture.business_date, targetDate);
  assert.equal(result.capture.capture_method, 'network_response');
  assert.equal(
    result.capture.capture_evidence.capture_source,
    'operator_supplied_browser_response',
  );
  assert.equal(
    result.capture.capture_evidence.capture_strategy,
    'browser_response_supplement',
  );
  assert.equal(result.capture.capture_evidence.source_method, 'manual_browser_import');
  assert.equal(result.capture.capture_evidence.response_evidence_type, 'structured_json');
  assert.equal(result.capture.capture_evidence.recipe_count, 6);
  assert.match(result.capture.capture_evidence.recipe_plan_hash, /^[a-f0-9]{64}$/);
  assert.equal(result.capture.summary.total_room_fee, 6450.14);
  assert.equal(result.capture.revenue_overview.data_status, 'verified');
  assert.equal(result.capture.revenue_overview.subjects[0].period_total, null);
  const serialized = JSON.stringify(result.capture);
  assert.doesNotMatch(
    serialized,
    /request_body|response_json|"payload"|ntwNum|network_5206408|headers|cookie|token/i,
  );
});
test('rejects date drift, cross-network context, missing recipes, and extra request fields', () => {
  const dateDrift = inputFixture();
  dateDrift.records[1].request_body.startDate = '2026-08-28';
  dateDrift.records[1].request_body.endDate = '2026-08-28';
  assert.throws(
    () => normalizeDingdandaoIabCapture(dateDrift, options()),
    /dingdandao_iab_request_scope_invalid/,
  );

  const crossNetwork = inputFixture();
  crossNetwork.records[5].request_body.ntwNum = 'other_network';
  assert.throws(
    () => normalizeDingdandaoIabCapture(crossNetwork, options()),
    /dingdandao_iab_network_context_mismatch/,
  );

  const missing = inputFixture();
  missing.records.pop();
  assert.throws(
    () => normalizeDingdandaoIabCapture(missing, options()),
    /dingdandao_iab_record_count_invalid/,
  );

  const extraField = inputFixture();
  extraField.records[0].request_body.extra = true;
  assert.throws(
    () => normalizeDingdandaoIabCapture(extraField, options()),
    /dingdandao_iab_request_scope_invalid/,
  );
});

test('rejects provider identity mismatch and any supplied sensitive key', () => {
  const mismatch = inputFixture();
  mismatch.records[0].response_json.data.id = '5206409';
  assert.throws(
    () => normalizeDingdandaoIabCapture(mismatch, options()),
    /dingdandao_iab_hotel_identity_mismatch/,
  );

  const sensitive = inputFixture();
  sensitive.records[0].headers = { cookie: 'must-not-be-accepted' };
  assert.throws(
    () => normalizeDingdandaoIabCapture(sensitive, options()),
    /dingdandao_iab_sensitive_key_forbidden/,
  );
});

test('rejects a null period total for non-total revenue subjects', () => {
  const invalid = inputFixture();
  const overview = invalid.records.find(
    (candidate) => candidate.url.endsWith(DINGDANDAO_API_PATHS.revenueOverview),
  );
  overview.response_json.data.subjects.push({
    type: 7,
    subjectTypeName: '\u9000\u6b3e',
    singleDayTotalConsume: -0.2,
    subjectTypeTotalConsume: null,
    percent: 0,
    subjectTypeDates: [{ date: targetDate, consume: -0.2 }],
  });
  assert.throws(
    () => normalizeDingdandaoIabCapture(invalid, options()),
    /dingdandao_iab_revenue_overview_incomplete/,
  );
});

test('CLI writes one sanitized success JSON or one safe blocked JSON', () => {
  const args = [
    script,
    `--target-date=${targetDate}`,
    `--expected-hotel-name=${hotelName}`,
    `--expected-provider-hotel-id=${hotelId}`,
    '--collection-mode=operating_indicators',
  ];
  const success = spawnSync(process.execPath, args, {
    input: JSON.stringify(inputFixture()),
    encoding: 'utf8',
    maxBuffer: 3_000_000,
  });
  assert.equal(success.status, 0, success.stderr);
  assert.equal(success.stderr, '');
  const successLines = success.stdout.trim().split(/\r?\n/);
  assert.equal(successLines.length, 1);
  const normalized = JSON.parse(successLines[0]);
  assert.equal(normalized.status, 'normalized_browser_response_supplement');
  assert.equal(normalized.record_count, 6);

  const rejected = inputFixture();
  rejected.records[0].authorization = 'must-not-be-accepted';
  const failure = spawnSync(process.execPath, args, {
    input: JSON.stringify(rejected),
    encoding: 'utf8',
    maxBuffer: 3_000_000,
  });
  assert.equal(failure.status, 1);
  assert.equal(failure.stdout, '');
  const failureLines = failure.stderr.trim().split(/\r?\n/);
  assert.equal(failureLines.length, 1);
  assert.deepEqual(JSON.parse(failureLines[0]), {
    status: 'blocked',
    reason: 'dingdandao_iab_sensitive_key_forbidden',
  });
});
