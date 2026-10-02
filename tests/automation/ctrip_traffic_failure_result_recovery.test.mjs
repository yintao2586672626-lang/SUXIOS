import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/ctrip-static.js', 'utf8');
const sandbox = { window: {}, console };
vm.runInNewContext(source, sandbox, { filename: 'public/ctrip-static.js' });
const flow = sandbox.window.SUXI_CTRIP_STATIC.runCtripTrafficFetchFlow;

for (const throws of [false, true]) {
  test(`Ctrip traffic ${throws ? 'thrown' : 'returned'} readback failure replaces old success after history recovery`, async () => {
    let visible = { ui_flow_status: 'success', saved_count: 5, readback_verified: true };
    const failure = { code: 500, message: '流量数据库回读不完整', data: {
      saved_count: 1, row_count: 2, readback_verified: false, persistence_status: 'readback_not_verified' } };
    const outcome = await flow({
      getSelectedCtripHotelId: () => '80',
      getActiveCtripConfig: () => ({ id: 'synthetic-80', has_cookies: true, credential_status: 'ready' }),
      getForm: () => ({ dateRange: 'custom', startDate: '2026-09-27', endDate: '2026-09-27' }),
      requestFetch: async () => {
        if (!throws) return failure;
        throw Object.assign(new Error(failure.message), { data: failure });
      },
      setOnlineDataResult: value => { visible = value; },
      getOnlineDataResult: () => visible,
      handleFetchFailure: async () => {
        visible = { ui_flow_status: 'success', saved_count: 4, readback_verified: true,
          status: 'historical_snapshot' };
      },
    });
    assert.equal(outcome.status, throws ? 'exception' : 'failed');
    assert.equal(visible.ui_flow_status, throws ? 'exception' : 'failed');
    assert.equal(visible.saved_count, 1);
    assert.equal(visible.readback_verified, false);
    assert.match(visible.error, /回读不完整/);
    assert.equal(visible.system_hotel_id, '80');
    assert.equal(visible.request_end_date, '2026-09-27');
  });
}

test('Ctrip traffic network failure keeps historical provenance without borrowing old row counts', async () => {
  let visible = { source: 'latest', saved_count: 7, row_count: 7, data: [{ old: true }] };
  const outcome = await flow({
    getSelectedCtripHotelId: () => '80',
    getActiveCtripConfig: () => ({ id: 'synthetic-80', has_cookies: true, credential_status: 'ready' }),
    getForm: () => ({ dateRange: 'custom', startDate: '2026-09-27', endDate: '2026-09-27' }),
    requestFetch: async () => { throw new Error('网络不可用'); },
    setOnlineDataResult: value => { visible = value; },
    getOnlineDataResult: () => visible,
    handleFetchFailure: async () => {},
  });
  assert.equal(outcome.status, 'exception');
  assert.equal(visible.source, 'latest');
  assert.equal(visible.ui_flow_status, 'exception');
  assert.equal(visible.persistence_status, 'not_confirmed');
  assert.equal(visible.saved_count, null);
  assert.equal(visible.row_count, null);
  assert.equal(visible.data, undefined);
});
