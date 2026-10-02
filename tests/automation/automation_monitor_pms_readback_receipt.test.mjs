import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const appMain = readAppMainContractSource();
const receiptStartMarker = 'const automationMonitorPmsReadbackReceiptVerified = (result = {}, expected = {}) => {';
const startMarker = 'const triggerAutomationMonitorSource = async (row = {}, source = \'\') => {';
const endMarker = '\n            const openAutomationMonitorDrilldown';
const receiptStart = appMain.indexOf(receiptStartMarker);
const start = appMain.indexOf(startMarker);
const end = appMain.indexOf(endMarker, start + startMarker.length);
assert.notEqual(receiptStart, -1, 'PMS readback receipt validator must exist');
assert.notEqual(start, -1, 'automation monitor source trigger must exist');
assert.notEqual(end, -1, 'automation monitor source trigger end must exist');
const receiptSource = appMain.slice(receiptStart, start);
const triggerSource = appMain.slice(start, end);

const captureReadback = {
  id: 42,
  tenant_id: 12,
  hotel_id: 64,
  provider: 'dingdandao_pms',
  business_date: '2026-09-28',
  capture_status: 'verified',
  quality_status: 'verified',
  readback_status: 'readback_verified',
  captured_at: '2026-09-28 23:58:00',
};

const verifiedPrefill = {
  target_date: '2026-09-28',
  fact_scope: 'accommodation_room_fee',
  source_type: 'pms',
  quality_status: 'verified',
  source_reference: '订单来了住宿数据中心 / capture:42',
  fact_captured_at: '2026-09-28 23:58:00',
};

const createHarness = ({pmsResult, monitorRow} = {}) => {
  const actionUpdates = [];
  const toasts = [];
  const busyKey = {value: ''};
  let monitorLoadCount = 0;
  const sandbox = {
    automationMonitorDate: {value: '2026-09-28'},
    shanghaiBusinessToday: '2026-09-29',
    automationMonitorManualActionKey: (row, source) => `${row.hotel_id}:${source}:${row.business_date}`,
    automationMonitorManualBusyKey: busyKey,
    updateAutomationMonitorManualAction: (row, source, patch) => actionUpdates.push({row, source, patch}),
    showToast: (message, level) => toasts.push({message, level}),
    apiRequest: async () => pmsResult || {
      code: 200,
      data: {status: 'verified', prefill: verifiedPrefill, capture: captureReadback},
    },
    automationMonitor: {value: {business_date: '2026-09-28', rows: []}},
    automationMonitorCaptureReadbackVerified: result => result?.data?.readback_verified === true,
    loadAutomationMonitor: async () => {
      monitorLoadCount += 1;
      sandbox.automationMonitor.value = {
        business_date: '2026-09-28',
        rows: monitorRow ? [{hotel_id: '64', business_date: '2026-09-28', pms: monitorRow}] : [],
      };
    },
    operationErrorMessage: error => error?.message || 'failed',
  };
  const trigger = vm.runInNewContext(
    `(() => { ${receiptSource}; ${triggerSource}; return triggerAutomationMonitorSource; })()`,
    sandbox,
  );
  return {trigger, actionUpdates, toasts, busyKey, get monitorLoadCount() { return monitorLoadCount; }};
};

const pmsRow = {tenant_id: 12, hotel_id: '64', business_date: '2026-09-28', pms: {key: 'dingdandao_pms'}};

test('a scoped verified PMS prefill receipt confirms the manual read even when the monitor row is not refreshed yet', async () => {
  const harness = createHarness({monitorRow: {status: 'pending_readback'}});
  await harness.trigger(pmsRow, 'pms');

  assert.equal(harness.monitorLoadCount, 1);
  assert.equal(harness.actionUpdates.at(-1).patch.status, 'success');
  assert.match(harness.actionUpdates.at(-1).patch.message, /本次 PMS.*已精确回读/);
  assert.equal(harness.busyKey.value, '');
});

test('an old verified monitor row cannot certify a PMS prefill for another date', async () => {
  const harness = createHarness({
    pmsResult: {
      code: 200,
      data: {
        status: 'verified',
        prefill: {...verifiedPrefill, target_date: '2026-09-27'},
        capture: {...captureReadback, business_date: '2026-09-27'},
      },
    },
    monitorRow: {status: 'readback_verified'},
  });
  await harness.trigger(pmsRow, 'pms');

  assert.equal(harness.actionUpdates.at(-1).patch.status, 'failed');
  assert.match(harness.actionUpdates.at(-1).patch.message, /门店、租户、日期或质量状态不匹配/);
  assert.equal(harness.busyKey.value, '');
});

test('PMS prefill needs the same verified hotel, tenant, provider, capture, and fact scope', async t => {
  const invalidReceipts = [
    {name: 'hotel mismatch', capture: {hotel_id: 80}},
    {name: 'tenant mismatch', capture: {tenant_id: 13}},
    {name: 'provider mismatch', capture: {provider: 'meituan_cloud_pms'}},
    {name: 'capture readback missing', capture: {readback_status: 'pending'}},
    {name: 'fact scope mismatch', prefill: {fact_scope: 'whole_hotel_daily_operating'}},
  ];

  for (const item of invalidReceipts) {
    await t.test(item.name, async () => {
      const capture = {...captureReadback, ...item.capture};
      const prefill = {...verifiedPrefill, ...item.prefill};
      const harness = createHarness({
        pmsResult: {code: 200, data: {status: 'verified', capture, prefill}},
        monitorRow: {status: 'readback_verified'},
      });
      await harness.trigger(pmsRow, 'pms');

      assert.equal(harness.actionUpdates.at(-1).patch.status, 'failed');
      assert.match(harness.actionUpdates.at(-1).patch.message, /门店、租户、日期或质量状态不匹配/);
      assert.equal(harness.busyKey.value, '');
    });
  }
});

test('Meituan PMS uses the provider-specific direct receipt and does not require a monitor-row readback status', async () => {
  const capture = {...captureReadback, provider: 'meituan_cloud_pms'};
  const prefill = {...verifiedPrefill, source_reference: '美团云 PMS 当日经营概览 / capture:42'};
  const harness = createHarness({
    pmsResult: {code: 200, data: {status: 'verified', capture, prefill}},
    monitorRow: {status: 'pending_readback'},
  });
  await harness.trigger({...pmsRow, pms: {key: 'meituan_cloud_pms'}}, 'pms');

  assert.equal(harness.actionUpdates.at(-1).patch.status, 'success');
  assert.match(harness.actionUpdates.at(-1).patch.message, /本次 PMS.*已精确回读/);
});
