import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/ctrip-static.js', import.meta.url), 'utf8');
const sandbox = { window: {}, console };
vm.runInNewContext(source, sandbox, { filename: 'public/ctrip-static.js' });
const flow = sandbox.window.SUXI_CTRIP_STATIC.runCtripTrafficFetchFlow;

for (const [verified, expectedStatus] of [[false, 'readback_unverified'], [true, 'success']]) {
  test(`Ctrip traffic ${verified ? 'verified' : 'unverified'} save has truthful flow and page status`, async () => {
    let visible = null;
    const notices = [];
    const outcome = await flow({
      getSelectedCtripHotelId: () => '80',
      getActiveCtripConfig: () => ({ id: 'synthetic-80', has_cookies: true, credential_status: 'ready' }),
      getForm: () => ({ dateRange: 'custom', startDate: '2026-09-27', endDate: '2026-09-27' }),
      requestFetch: async () => ({ code: 200, data: {
        platform: 'ctrip', request_start_date: '2026-09-27', request_end_date: '2026-09-27',
        saved_count: 1, readback_verified: verified,
        persistence_status: verified ? 'readback_verified' : 'persisted',
        display_traffic_rows: [{ hotel_id: '80', business_date: '2026-09-27' }],
      } }),
      setOnlineDataResult: value => { visible = value; },
      notify: (message, level) => notices.push({ message, level }),
    });
    assert.equal(outcome.status, expectedStatus);
    assert.equal(outcome.readback_verified, verified);
    assert.equal(visible.ui_flow_status, expectedStatus);
    assert.equal(visible.readback_verified, verified);
    assert.equal(visible.saved_count, 1);
    assert.equal(notices.at(-1).level, verified ? 'success' : 'warning');
  });
}

for (const [savedCount, businessStatus, expectedStatus] of [
  [0, '', 'display_only'],
  [1, 'failed', 'business_failed'],
]) {
  test(`Ctrip traffic ${expectedStatus} stays distinct from a verified save`, async () => {
    let visible = null;
    const outcome = await flow({
      getSelectedCtripHotelId: () => '80',
      getActiveCtripConfig: () => ({ id: 'synthetic-80', has_cookies: true, credential_status: 'ready' }),
      getForm: () => ({ dateRange: 'custom', startDate: '2026-09-27', endDate: '2026-09-27' }),
      requestFetch: async () => ({ code: 200, data: {
        saved_count: savedCount, status: businessStatus,
        display_traffic_rows: [{ hotel_id: '80', business_date: '2026-09-27' }],
      } }),
      setOnlineDataResult: value => { visible = value; },
    });
    assert.equal(outcome.status, expectedStatus);
    assert.equal(visible.ui_flow_status, expectedStatus);
    assert.equal(visible.readback_verified, false);
  });
}
