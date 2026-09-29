import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const appMain = readAppMainContractSource();
const startMarker = 'const triggerAutomationMonitorSource = async (row = {}, source = \'\') => {';
const endMarker = '\n            const openAutomationMonitorDrilldown';
const start = appMain.indexOf(startMarker);
const end = appMain.indexOf(endMarker, start + startMarker.length);
assert.notEqual(start, -1, 'automation monitor source trigger must exist');
assert.notEqual(end, -1, 'automation monitor source trigger end must exist');
const triggerSource = appMain.slice(start, end);

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise;
    reject = rejectPromise;
  });
  return { promise, resolve, reject };
};

const createHarness = ({deferAt = '', rejectConfig = false} = {}) => {
  const meituanForm = {value: {hotelId: '90'}};
  const automationMonitorManualBusyKey = {value: ''};
  const actionUpdates = [];
  const toasts = [];
  const applyTargets = [];
  const syncTargets = [];
  const captureTargets = [];
  const waiters = {
    config: deferred(),
    apply: deferred(),
    sync: deferred(),
  };
  const started = {
    config: deferred(),
    apply: deferred(),
    sync: deferred(),
  };
  let monitorLoadCount = 0;
  const waitAt = key => {
    started[key].resolve();
    if (key !== deferAt) return Promise.resolve();
    if (key === 'config' && rejectConfig) return waiters[key].promise;
    return waiters[key].promise;
  };
  const sandbox = {
    automationMonitorDate: {value: '2026-09-28'},
    shanghaiBusinessToday: '2026-09-28',
    automationMonitorManualActionKey: (row, source) => `${row.hotel_id}:${source}`,
    automationMonitorManualBusyKey,
    updateAutomationMonitorManualAction: (row, source, patch) => actionUpdates.push({row, source, patch}),
    showToast: (message, level) => toasts.push({message, level}),
    meituanForm,
    loadMeituanConfigList: async () => {
      await waitAt('config');
      if (rejectConfig) throw new Error('synthetic old-hotel config failure');
      return true;
    },
    applyMeituanHotelConfig: async () => {
      applyTargets.push(meituanForm.value.hotelId);
      await waitAt('apply');
      return true;
    },
    syncMeituanBrowserCaptureFromSelectedConfig: async () => {
      syncTargets.push(meituanForm.value.hotelId);
      await waitAt('sync');
      return true;
    },
    runMeituanBrowserCapture: async () => {
      captureTargets.push(meituanForm.value.hotelId);
      return {
        status: 'success',
        response: {code: 200, data: {readback_verified: true}},
      };
    },
    automationMonitor: {value: {rows: [{hotel_id: '64', meituan: {status: 'readback_verified'}}]}},
    automationMonitorCaptureReadbackVerified: result => result?.response?.data?.readback_verified === true,
    loadAutomationMonitor: async () => { monitorLoadCount += 1; },
    operationErrorMessage: error => error?.message || 'failed',
  };
  const trigger = vm.runInNewContext(
    `(() => { ${triggerSource}; return triggerAutomationMonitorSource; })()`,
    sandbox,
  );
  return {
    trigger,
    waiters,
    started,
    meituanForm,
    automationMonitorManualBusyKey,
    actionUpdates,
    toasts,
    applyTargets,
    syncTargets,
    captureTargets,
    get monitorLoadCount() { return monitorLoadCount; },
  };
};

const startCapture = harness => harness.trigger({hotel_id: '64', business_date: '2026-09-28'}, 'meituan');

const assertCancelled = harness => {
  assert.deepEqual(harness.captureTargets, [], 'do not capture the newly selected hotel for the original monitor row');
  assert.equal(harness.meituanForm.value.hotelId, '80', 'preserve the user\'s later hotel selection');
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'failed', 'leave the original monitor row retryable');
  assert.match(harness.actionUpdates.at(-1).patch.message, /已切换|取消/);
  assert.equal(harness.toasts.at(-1).level, 'warning');
  assert.equal(harness.monitorLoadCount, 0, 'do not report an old-row readback after cancellation');
  assert.equal(harness.automationMonitorManualBusyKey.value, '');
};

test('automation monitor cancels Meituan capture when hotel changes during config-list loading', async () => {
  const harness = createHarness({deferAt: 'config'});
  const pending = startCapture(harness);

  await harness.started.config.promise;
  harness.meituanForm.value.hotelId = '80';
  harness.waiters.config.resolve();
  await pending;

  assert.deepEqual(harness.applyTargets, [], 'do not apply the original row config after a hotel switch');
  assert.deepEqual(harness.syncTargets, []);
  assertCancelled(harness);
});

test('automation monitor cancels Meituan capture when hotel changes during config application', async () => {
  const harness = createHarness({deferAt: 'apply'});
  const pending = startCapture(harness);

  await harness.started.apply.promise;
  harness.meituanForm.value.hotelId = '80';
  harness.waiters.apply.resolve();
  await pending;

  assert.deepEqual(harness.applyTargets, ['64']);
  assert.deepEqual(harness.syncTargets, []);
  assertCancelled(harness);
});

test('automation monitor cancels Meituan capture when hotel changes during capture-form sync', async () => {
  const harness = createHarness({deferAt: 'sync'});
  const pending = startCapture(harness);

  await harness.started.sync.promise;
  harness.meituanForm.value.hotelId = '80';
  harness.waiters.sync.resolve();
  await pending;

  assert.deepEqual(harness.applyTargets, ['64']);
  assert.deepEqual(harness.syncTargets, ['64']);
  assertCancelled(harness);
});

test('automation monitor keeps Meituan capture and readback when its hotel stays selected', async () => {
  const harness = createHarness();
  await startCapture(harness);

  assert.deepEqual(harness.applyTargets, ['64']);
  assert.deepEqual(harness.syncTargets, ['64']);
  assert.deepEqual(harness.captureTargets, ['64']);
  assert.equal(harness.monitorLoadCount, 1);
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'success');
  assert.equal(harness.automationMonitorManualBusyKey.value, '');
});

test('automation monitor treats config-load failure after a Meituan hotel switch as cancellation', async () => {
  const harness = createHarness({deferAt: 'config', rejectConfig: true});
  const pending = startCapture(harness);

  await harness.started.config.promise;
  harness.meituanForm.value.hotelId = '80';
  harness.waiters.config.reject(new Error('synthetic stale config failure'));
  await pending;

  assert.deepEqual(harness.applyTargets, []);
  assert.deepEqual(harness.syncTargets, []);
  assertCancelled(harness);
});
