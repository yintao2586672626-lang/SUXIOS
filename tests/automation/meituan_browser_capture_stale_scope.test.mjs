import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readStaticContractSource('public/meituan-static.js');
const appMain = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const sandbox = { console, window: {} };
vm.runInNewContext(source, sandbox, { filename: 'public/meituan-static.js' });
const flow = sandbox.window.SUXI_MEITUAN_STATIC.runMeituanBrowserCaptureFlow;

test('Meituan browser capture page binding supplies session and hotel context', () => {
  const start = appMain.indexOf('const runMeituanBrowserCapture = async (options = {}) => runMeituanBrowserCaptureFlow({');
  const end = appMain.indexOf('\n            });', start);
  assert.ok(start >= 0 && end > start);
  const binding = appMain.slice(start, end);
  assert.match(binding, /captureRequestContext:/);
  assert.match(binding, /isRequestContextCurrent:/);
});

for (const failure of [false, true]) {
  test(`Meituan browser capture ${failure ? 'rejection' : 'completion'} from old hotel is stale`, async () => {
    let hotel = '80';
    let resolveRequest;
    let rejectRequest;
    const captured = [];
    const visible = [];
    const running = [];
    const fetching = [];
    const notices = [];
    const pending = flow({
      captureRequestContext: () => ({ hotel }),
      isRequestContextCurrent: context => context.hotel === hotel,
      getForm: () => ({ storeId: 'poi-1', poiId: 'poi-1', captureSections: ['orders'] }),
      getSystemHotelId: () => hotel,
      requestCapture: () => new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; }),
      setRunning: value => running.push(value),
      setFetching: value => fetching.push(value),
      setCaptureResult: value => captured.push(value),
      setOnlineDataResult: value => visible.push(value),
      notify: (...args) => notices.push(args),
    });
    hotel = '81';
    if (failure) rejectRequest(new Error('old hotel failed'));
    else resolveRequest({ code: 200, data: { saved_count: 1, readback_verified: true,
      persistence_status: 'readback_verified' } });
    const outcome = await pending;
    assert.equal(outcome.status, 'stale');
    assert.equal(captured.length, 1);
    assert.equal(captured[0], null);
    assert.equal(visible.length, 0);
    assert.equal(running.at(-1), true);
    assert.equal(fetching.at(-1), true);
    assert.equal(notices.length, 0);
  });
}
