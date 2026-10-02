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

const createHarness = () => {
  const configLoad = deferred();
  const configLoadStarted = deferred();
  const selectedCtripHotelId = { value: '90' };
  const autoFetchHotelId = { value: '90' };
  const ctripOverviewForm = { value: { dataDate: '2026-09-27' } };
  const automationMonitorManualBusyKey = { value: '' };
  const actionUpdates = [];
  const toasts = [];
  const applyTargets = [];
  const captureTargets = [];
  let monitorLoadCount = 0;
  const sandbox = {
    automationMonitorManualActionKey: (row, source) => `${row.hotel_id}:${source}`,
    automationMonitorManualBusyKey,
    updateAutomationMonitorManualAction: (row, source, patch) => actionUpdates.push({ row, source, patch }),
    showToast: (message, level) => toasts.push({ message, level }),
    selectedCtripHotelId,
    ctripTargetHotelManuallySelected: { value: false },
    autoFetchHotelId,
    ctripOverviewForm,
    loadCtripConfigList: () => {
      configLoadStarted.resolve();
      return configLoad.promise;
    },
    applyCtripHotelConfig: async () => {
      applyTargets.push(selectedCtripHotelId.value);
    },
    runCtripBrowserCapture: async () => {
      captureTargets.push(selectedCtripHotelId.value);
      return { code: 200, data: { saved_count: 1, readback_verified: true } };
    },
    automationMonitor: { value: { rows: [{ hotel_id: '64', ctrip: { status: 'readback_verified' } }] } },
    automationMonitorCaptureReadbackVerified: result => result?.data?.readback_verified === true,
    loadAutomationMonitor: async () => { monitorLoadCount += 1; },
    operationErrorMessage: error => error?.message || 'failed',
  };
  const trigger = vm.runInNewContext(
    `(() => { ${triggerSource}; return triggerAutomationMonitorSource; })()`,
    sandbox,
  );
  return {
    trigger,
    configLoad,
    configLoadStarted,
    selectedCtripHotelId,
    autoFetchHotelId,
    ctripOverviewForm,
    automationMonitorManualBusyKey,
    actionUpdates,
    toasts,
    applyTargets,
    captureTargets,
    get monitorLoadCount() { return monitorLoadCount; },
  };
};

test('automation monitor cancels Ctrip capture when hotel changes during config loading', async () => {
  const harness = createHarness();
  const pending = harness.trigger({ hotel_id: '64', business_date: '2026-09-28' }, 'ctrip');

  await harness.configLoadStarted.promise;
  harness.selectedCtripHotelId.value = '80';
  harness.configLoad.resolve();
  await pending;

  assert.deepEqual(harness.applyTargets, [], 'do not apply the old monitor row against the new selection');
  assert.deepEqual(harness.captureTargets, [], 'do not capture whichever hotel is selected after config loading');
  assert.equal(harness.selectedCtripHotelId.value, '80', 'preserve the user\'s later hotel selection');
  assert.equal(harness.autoFetchHotelId.value, '90', 'restore the prior automatic-fetch scope on cancellation');
  assert.equal(harness.ctripOverviewForm.value.dataDate, '2026-09-27', 'restore the prior Ctrip business date on cancellation');
  assert.deepEqual(harness.actionUpdates.at(-1).patch.status, 'failed', 'leave the original row retryable');
  assert.match(harness.actionUpdates.at(-1).patch.message, /已切换|取消/);
  assert.equal(harness.toasts.at(-1).level, 'warning');
  assert.equal(harness.monitorLoadCount, 0, 'do not report an old-row readback after the request was cancelled');
  assert.equal(harness.automationMonitorManualBusyKey.value, '');
});

test('automation monitor keeps the original Ctrip row capture when its hotel stays selected', async () => {
  const harness = createHarness();
  const pending = harness.trigger({ hotel_id: '64', business_date: '2026-09-28' }, 'ctrip');

  await harness.configLoadStarted.promise;
  harness.configLoad.resolve();
  await pending;

  assert.deepEqual(harness.applyTargets, ['64']);
  assert.deepEqual(harness.captureTargets, ['64']);
  assert.equal(harness.monitorLoadCount, 1);
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'success');
  assert.equal(harness.automationMonitorManualBusyKey.value, '');
});

test('automation monitor treats config-load failure after a Ctrip hotel switch as cancellation', async () => {
  const harness = createHarness();
  const pending = harness.trigger({ hotel_id: '64', business_date: '2026-09-28' }, 'ctrip');

  await harness.configLoadStarted.promise;
  harness.selectedCtripHotelId.value = '80';
  harness.configLoad.reject(new Error('synthetic old-hotel config failure'));
  await pending;

  assert.deepEqual(harness.applyTargets, []);
  assert.deepEqual(harness.captureTargets, []);
  assert.equal(harness.selectedCtripHotelId.value, '80');
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'failed');
  assert.match(harness.actionUpdates.at(-1).patch.message, /已切换|取消/);
  assert.equal(harness.toasts.at(-1).level, 'warning');
  assert.equal(harness.monitorLoadCount, 0);
  assert.equal(harness.automationMonitorManualBusyKey.value, '');
});
