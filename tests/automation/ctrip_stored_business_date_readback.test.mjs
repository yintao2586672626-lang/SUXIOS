import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const start = main.indexOf('            const loadSelectedCtripStoredBusinessDate = async () => {');
const end = /\r?\n            };/.exec(main.slice(start));
assert.ok(start >= 0 && end, 'production stored-date handler exists');
const ownerStart = main.lastIndexOf('            let ctripStoredBusinessDateRequestSeq = 0;', start);
const handler = main.slice(ownerStart >= 0 ? ownerStart : start, start + end.index + end[0].length);

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

const flush = () => new Promise(resolve => setImmediate(resolve));

function harness({ deferredGate = false } = {}) {
  const requests = [], gateCalls = [], notices = [];
  const scope = { authEpoch: 1, hotelEpoch: 1 };
  const context = {
    selectedCtripHotelId: { value: '80' },
    ctripTrafficForm: { value: { startDate: '2026-07-29', endDate: '2026-07-29' } },
    ctripTrafficView: { value: 'history' },
    ctripTrafficRows: { value: [{ date: '2026-07-29', role: 'self' }] },
    ctripTrafficHistoryResult: { value: { saved_count: 3 } },
    currentPage: { value: 'ctrip-ebooking' },
    onlineDataTab: { value: 'ctrip-traffic' },
    captureAuthSession: () => ({ epoch: scope.authEpoch }),
    isAuthSessionCurrent: session => session.epoch === scope.authEpoch,
    capturePlatformHotelRequestContext: () => ({ hotelId: context.selectedCtripHotelId.value, epoch: scope.hotelEpoch }),
    isPlatformHotelRequestContextCurrent: captured => captured.hotelId === context.selectedCtripHotelId.value && captured.epoch === scope.hotelEpoch,
    showToast: (...args) => notices.push(args),
    ctripTrafficRowRole: row => row.role,
    useCtripTrafficDisplayRows: rows => { context.ctripTrafficRows.value = rows; },
    readStoredOtaTrafficGate: () => {
      const task = deferred();
      gateCalls.push(task);
      if (!deferredGate) task.resolve({ status: 'ready' });
      return task.promise;
    },
    loadLatestCtripData: options => {
      const task = deferred();
      requests.push({ options, task });
      return task.promise;
    },
  };
  vm.createContext(context);
  vm.runInContext(`${handler}\nglobalThis.runStoredDate = loadSelectedCtripStoredBusinessDate;`, context);
  function settleRead(value, rows = null) {
    const request = requests.at(-1);
    if (rows && (!request.options.isActive || request.options.isActive())) {
      context.ctripTrafficRows.value = rows;
    }
    request.task.resolve(value);
  }
  return { context, scope, requests, gateCalls, notices, settleRead };
}

const selfRow = { date: '2026-07-29', role: 'self' };
const payload = { available: false, status: 'source_unverified', payload: {
  metadata: { hotel_id: 80, status: 'source_unverified' },
  traffic: { data_date: '2026-07-29', status: 'success' },
} };

test('invalid calendar date clears prior traffic without reading another business day', async () => {
  const h = harness();
  h.context.ctripTrafficForm.value.startDate = '2026-02-30';
  h.context.ctripTrafficForm.value.endDate = '2026-02-30';
  assert.equal(await h.context.runStoredDate(), false);
  assert.equal(h.requests.length, 0);
  assert.equal(h.gateCalls.length, 0);
  assert.equal(h.context.ctripTrafficRows.value.length, 0);
  assert.equal(h.context.ctripTrafficHistoryResult.value, null);
  assert.equal(h.notices.at(-1)?.[1], 'warning');
});

test('failed stored-date request cannot reuse previous rows or reach the trust gate', async () => {
  const h = harness();
  const pending = h.context.runStoredDate();
  h.settleRead(false);
  assert.equal(await pending, false);
  assert.equal(h.gateCalls.length, 0);
  assert.equal(h.context.ctripTrafficRows.value.length, 0);
  assert.equal(h.context.ctripTrafficHistoryResult.value, null);
  assert.equal(h.notices.some(([message, level]) => level === 'success'), false);
});

test('valid same-date traffic may pass its own gate when unrelated ranking metadata is unverified', async () => {
  const h = harness();
  const pending = h.context.runStoredDate();
  h.settleRead(payload, [selfRow]);
  await flush();
  assert.equal(h.gateCalls.length, 1);
  assert.equal(await pending, true);
  assert.equal(h.notices.at(-1)[1], 'success');
});

test('old hotel, date, account or tab response cannot write the current scope', async () => {
  for (const change of ['hotel-roundtrip', 'date', 'account', 'tab']) {
    const h = harness();
    const pending = h.context.runStoredDate();
    if (change === 'hotel-roundtrip') h.scope.hotelEpoch++;
    if (change === 'date') h.context.ctripTrafficForm.value.startDate = '2026-07-30';
    if (change === 'account') h.scope.authEpoch++;
    if (change === 'tab') h.context.ctripTrafficView.value = 'realtime';
    h.settleRead(payload, [selfRow]);
    assert.equal(await pending, false, change);
    assert.equal(h.gateCalls.length, 0, change);
    assert.equal(h.notices.some(([, level]) => level === 'success'), false, change);
  }
});

test('a newer same-scope read owns the final result', async () => {
  const h = harness();
  const oldRead = h.context.runStoredDate();
  const currentRead = h.context.runStoredDate();
  assert.equal(h.requests.length, 2);
  h.requests[0].task.resolve(payload);
  assert.equal(await oldRead, false);
  assert.equal(h.gateCalls.length, 0);
  h.settleRead(payload, [selfRow]);
  assert.equal(await currentRead, true);
  assert.equal(h.gateCalls.length, 1);
  assert.equal(h.notices.filter(([, level]) => level === 'success').length, 1);
});

test('trust-gate failure and old gate completion do not announce success', async () => {
  const failure = harness({ deferredGate: true });
  const failed = failure.context.runStoredDate();
  failure.settleRead(payload, [selfRow]);
  await flush();
  failure.gateCalls[0].reject(new Error('synthetic unavailable gate'));
  assert.equal(await failed, false);
  assert.equal(failure.notices.some(([, level]) => level === 'success'), false);

  const stale = harness({ deferredGate: true });
  const pending = stale.context.runStoredDate();
  stale.settleRead(payload, [selfRow]);
  await flush();
  stale.context.ctripTrafficForm.value.endDate = '2026-07-30';
  stale.gateCalls[0].resolve({ status: 'ready' });
  assert.equal(await pending, false);
  assert.equal(stale.notices.some(([, level]) => level === 'success'), false);
});
