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
const labelStartMarker = 'const automationMonitorManualActionLabel = (row = {}, source = \'\') => {';
const labelEndMarker = '\n            const automationMonitorManualActionClass';
const labelStart = appMain.indexOf(labelStartMarker);
const labelEnd = appMain.indexOf(labelEndMarker, labelStart + labelStartMarker.length);
assert.notEqual(labelStart, -1, 'automation monitor manual action label must exist');
assert.notEqual(labelEnd, -1, 'automation monitor manual action label end must exist');
const labelSource = appMain.slice(labelStart, labelEnd);

const createHarness = ({captureResult, refreshedMonitor} = {}) => {
  const actionUpdates = [];
  const toasts = [];
  const busyKey = {value: ''};
  const meituanForm = {value: {hotelId: '64'}};
  const monitorDate = {value: '2026-09-28'};
  let monitorLoadCount = 0;
  const sandbox = {
    automationMonitorDate: monitorDate,
    shanghaiBusinessToday: '2026-09-29',
    automationMonitorManualActionKey: (row, source) => `${row.hotel_id}:${source}`,
    automationMonitorManualBusyKey: busyKey,
    updateAutomationMonitorManualAction: (row, source, patch) => actionUpdates.push({row, source, patch}),
    showToast: (message, level) => toasts.push({message, level}),
    meituanForm,
    loadMeituanConfigList: async () => [],
    applyMeituanHotelConfig: async () => ({hotel_id: '64'}),
    syncMeituanBrowserCaptureFromSelectedConfig: async () => true,
    runMeituanBrowserCapture: async () => captureResult || {
      status: 'success',
      response: {code: 200, data: {readback_verified: false}},
    },
    automationMonitor: {value: {business_date: '2026-09-28', rows: []}},
    automationMonitorCaptureReadbackVerified: result => result?.response?.data?.readback_verified === true,
    loadAutomationMonitor: async () => {
      monitorLoadCount += 1;
      monitorDate.value = refreshedMonitor?.business_date || monitorDate.value;
      sandbox.automationMonitor.value = refreshedMonitor || {business_date: monitorDate.value, rows: []};
    },
    operationErrorMessage: error => error?.message || 'failed',
  };
  const trigger = vm.runInNewContext(
    `(() => { ${triggerSource}; return triggerAutomationMonitorSource; })()`,
    sandbox,
  );
  return {trigger, actionUpdates, toasts, busyKey, get monitorLoadCount() { return monitorLoadCount; }};
};

const targetRow = {hotel_id: '64', business_date: '2026-09-28'};

test('a different-day monitor row cannot verify the original manual capture', async () => {
  const harness = createHarness({
    refreshedMonitor: {
      business_date: '2026-09-27',
      rows: [{hotel_id: '64', business_date: '2026-09-27', meituan: {status: 'readback_verified'}}],
    },
  });

  await harness.trigger(targetRow, 'meituan');

  assert.equal(harness.monitorLoadCount, 1);
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'partial', 'same-hotel readback on another business day is not evidence for this row');
  assert.match(harness.actionUpdates.at(-1).patch.message, /尚未确认/);
  assert.equal(harness.busyKey.value, '');
});

test('same-hotel same-day monitor history cannot verify this capture without a direct receipt', async () => {
  const harness = createHarness({
    refreshedMonitor: {
      business_date: '2026-09-28',
      rows: [{hotel_id: '64', business_date: '2026-09-28', meituan: {status: 'readback_verified'}}],
    },
  });

  await harness.trigger(targetRow, 'meituan');

  assert.equal(harness.actionUpdates.at(-1).patch.status, 'partial');
  assert.match(harness.actionUpdates.at(-1).patch.message, /同日已有回读数据.*本次抓取回执尚未确认/);
  const actionLabel = vm.runInNewContext(
    `(() => { ${labelSource}; return automationMonitorManualActionLabel; })()`,
    {automationMonitorManualAction: () => harness.actionUpdates.at(-1).patch},
  );
  assert.equal(actionLabel(targetRow, 'meituan'), '再次抓取');
  assert.equal(harness.toasts.at(-1).level, 'warning');
  assert.match(harness.toasts.at(-1).message, /同日已有回读数据.*本次抓取结果尚未确认入库/);
});

test('direct capture readback remains valid when the monitor refresh has moved to another day', async () => {
  const harness = createHarness({
    captureResult: {status: 'success', response: {code: 200, data: {readback_verified: true}}},
    refreshedMonitor: {
      business_date: '2026-09-27',
      rows: [{hotel_id: '64', business_date: '2026-09-27', meituan: {status: 'readback_verified'}}],
    },
  });

  await harness.trigger(targetRow, 'meituan');

  assert.equal(harness.actionUpdates.at(-1).patch.status, 'success', 'the capture result itself is scoped to the original request');
});
