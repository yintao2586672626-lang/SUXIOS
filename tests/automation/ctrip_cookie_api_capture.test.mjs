import test from 'node:test';
import assert from 'node:assert/strict';

import { runCtripCookieApiCapture } from '../../scripts/ctrip_cookie_api_capture.mjs';

function fakeJsonFetch(body = { ok: true }) {
  return async () => ({
    ok: true,
    status: 200,
    headers: {
      get(name) {
        return String(name).toLowerCase() === 'content-type' ? 'application/json' : '';
      },
    },
    async text() {
      return JSON.stringify(body);
    },
  });
}

test('Ctrip Cookie/API capture does not use profile_id as hotel_id', async () => {
  const payload = await runCtripCookieApiCapture({
    profile_id: 'profile-58',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/custom/api/not-cataloged',
      method: 'GET',
    }],
  }, {
    cookies: 'session=redacted',
    fetchImpl: fakeJsonFetch(),
    capturedAt: '2026-06-15T00:00:00.000Z',
  });

  assert.equal(payload.profile_id, 'profile-58');
  assert.equal(payload.hotel_id, '');
});

test('Ctrip Cookie/API capture accepts explicit platform hotel id aliases', async () => {
  const payload = await runCtripCookieApiCapture({
    profile_id: 'profile-58',
    ctrip_hotel_id: 'ctrip-58',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/custom/api/not-cataloged',
      method: 'GET',
    }],
  }, {
    cookies: 'session=redacted',
    fetchImpl: fakeJsonFetch(),
    capturedAt: '2026-06-15T00:00:00.000Z',
  });

  assert.equal(payload.profile_id, 'profile-58');
  assert.equal(payload.hotel_id, 'ctrip-58');
});

test('Ctrip Cookie/API future-search capture stores sanitized request shape and Cookie ingestion method', async () => {
  const payload = await runCtripCookieApiCapture({
    system_hotel_id: 58,
    hotel_id: 'ctrip-58',
    data_date: '2026-07-11',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/querySearchFlowDetails?hostType=Ebooking',
      method: 'POST',
      section: 'traffic_report',
      payload: {
        platform: 'Ctrip',
        dataType: 0,
        searchType: '1',
        spiderVersion: '2.0',
        spiderkey: 'must-not-be-stored',
      },
    }],
  }, {
    cookies: 'session=redacted',
    fetchImpl: fakeJsonFetch({
      rcode: 0,
      data: {
        effectDateList: ['07-12'],
        pvDataList: [0],
        uvDataList: [0],
        orderDataList: [null],
        conversionsRatesDataList: [0],
      },
    }),
    capturedAt: '2026-07-11T08:00:00.000Z',
  });

  assert.equal(payload.standard_rows.length, 1);
  assert.equal(payload.standard_rows[0].ingestion_method, 'ctrip_cookie_api');
  assert.equal(JSON.stringify(payload).includes('must-not-be-stored'), false);
  assert.deepEqual(payload.responses[0].request_payload, {
    platform: 'Ctrip',
    dataType: 0,
    searchType: '1',
    spiderVersion: '2.0',
  });
});

test('Ctrip Cookie/API future-search capture reports dynamic signature rejection truthfully', async () => {
  const payload = await runCtripCookieApiCapture({
    hotel_id: 'ctrip-58',
    data_date: '2026-07-11',
    endpoints: [{
      request_url: 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/querySearchFlowDetails?hostType=Ebooking',
      method: 'POST',
      section: 'traffic_report',
      payload: { platform: 'Ctrip', dataType: 0, searchType: '0', spiderVersion: '2.0' },
    }],
  }, {
    cookies: 'session=redacted',
    fetchImpl: fakeJsonFetch({ rcode: 4011, msg: 'spiderkey signature invalid' }),
    capturedAt: '2026-07-11T08:00:00.000Z',
  });

  assert.equal(payload.standard_rows.length, 0);
  assert.equal(payload.errors[0].error, 'request_signature_required');
});
