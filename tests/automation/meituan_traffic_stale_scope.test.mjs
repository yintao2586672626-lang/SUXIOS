import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(source, sandbox, { filename: 'public/meituan-static.js' });
const flow = sandbox.window.SUXI_MEITUAN_STATIC.runMeituanTrafficFetchFlow;

for (const failure of [false, true]) {
  test(`Meituan traffic ${failure ? 'rejection' : 'completion'} from prior hotel cannot overwrite current hotel`, async () => {
    let hotel = '80';
    let resolveRequest;
    let rejectRequest;
    const visible = [];
    const latest = [];
    const fetching = [];
    const notices = [];
    const pending = flow({
      captureRequestContext: () => ({ hotel }),
      isRequestContextCurrent: context => context.hotel === hotel,
      getForm: () => ({ url: 'https://example.invalid/traffic', partnerId: 'p', poiId: 'h',
        startDate: '2026-09-27', endDate: '2026-09-27' }),
      getConfigId: () => 'synthetic-config',
      getSystemHotelId: () => hotel,
      requestFetch: () => new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; }),
      setFetching: value => fetching.push(value),
      setOnlineDataResult: value => visible.push(value),
      setLatestTrafficData: value => latest.push(value),
      notify: (...args) => notices.push(args),
    });
    hotel = '81';
    if (failure) rejectRequest(new Error('old hotel failed'));
    else resolveRequest({ code: 200, data: { data: [{ hotel_id: '80' }], saved_count: 1,
      readback_verified: true, persistence_status: 'readback_verified' } });
    const outcome = await pending;
    assert.equal(outcome.status, 'stale');
    assert.equal(visible.length, 1);
    assert.equal(visible[0], null);
    assert.equal(latest.length, 0);
    assert.equal(fetching.at(-1), true);
    assert.equal(notices.length, 0);
  });
}

test('Meituan traffic date change and a replacement request keep the newer loading owner', async () => {
  let date = '2026-09-27';
  const pending = [];
  const visible = [];
  const fetching = [];
  const options = {
    captureRequestContext: () => ({ hotel: '80' }),
    isRequestContextCurrent: () => true,
    getForm: () => ({ url: 'https://example.invalid/traffic', partnerId: 'p', poiId: 'h',
      startDate: date, endDate: date }),
    getConfigId: () => 'synthetic-config',
    getSystemHotelId: () => '80',
    requestFetch: () => new Promise(resolve => pending.push(resolve)),
    setFetching: value => fetching.push(value),
    setOnlineDataResult: value => visible.push(value),
  };
  const oldRequest = flow(options);
  date = '2026-09-28';
  const newRequest = flow(options);
  pending[0]({ code: 200, data: { data: [{ old: true }], saved_count: 1,
    readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal((await oldRequest).status, 'stale');
  assert.equal(fetching.at(-1), true);
  assert.equal(visible.length, 2);
  pending[1]({ code: 200, data: { data: [{ current: true }], saved_count: 1,
    readback_verified: true, persistence_status: 'readback_verified' } });
  assert.equal((await newRequest).status, 'success');
  assert.equal(fetching.at(-1), false);
  assert.equal(visible.at(-1)[0].current, true);
});
