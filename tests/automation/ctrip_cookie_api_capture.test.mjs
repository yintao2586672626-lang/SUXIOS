import test from 'node:test';
import assert from 'node:assert/strict';

import { runCtripCookieApiCapture } from '../../scripts/ctrip_cookie_api_capture.mjs';

function fakeJsonFetch(body = { ok: true }) {
  return async () => ({
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
