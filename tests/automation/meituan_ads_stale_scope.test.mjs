import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const sandbox = { console, window: {} };
vm.runInNewContext(source, sandbox, { filename: 'public/meituan-static.js' });
const flow = sandbox.window.SUXI_MEITUAN_STATIC.runMeituanAdsFetchFlow;

for (const failure of [false, true]) {
  test(`Meituan ads ${failure ? 'rejection' : 'completion'} from old hotel is stale`, async () => {
    let hotel = '80';
    let resolveRequest;
    let rejectRequest;
    const ads = [];
    const visible = [];
    const fetching = [];
    const notices = [];
    const pending = flow({
      captureRequestContext: () => ({ hotel }),
      isRequestContextCurrent: context => context.hotel === hotel,
      getForm: () => ({ url: 'https://example.invalid/ads', partnerId: 'p', poiId: 'h', shopId: 's',
        startDate: '2026-09-27', endDate: '2026-09-27' }),
      getConfigId: () => 'synthetic-config',
      getSystemHotelId: () => hotel,
      requestFetch: () => new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; }),
      setFetching: value => fetching.push(value),
      setAdsResult: value => ads.push(value),
      setOnlineDataResult: value => visible.push(value),
      notify: (...args) => notices.push(args),
    });
    hotel = '81';
    if (failure) rejectRequest(new Error('old hotel failed'));
    else resolveRequest({ code: 200, data: { ads: [{ hotel_id: '80' }], saved_count: 1,
      readback_verified: true, persistence_status: 'readback_verified' } });
    const outcome = await pending;
    assert.equal(outcome.status, 'stale');
    assert.equal(ads.length, 1);
    assert.equal(visible.length, 1);
    assert.equal(ads[0], null);
    assert.equal(visible[0], null);
    assert.equal(fetching.at(-1), true);
    assert.equal(notices.length, 0);
  });
}
