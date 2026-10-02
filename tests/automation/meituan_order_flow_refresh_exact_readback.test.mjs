import { readSourceAggregate as readStaticContractSource } from '../../scripts/lib/source_aggregate.mjs';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const start = appMain.indexOf('const refreshMeituanOrderFlowData = async () => {');
const end = appMain.indexOf('\n\n            const runMeituanBrowserProfileLoginOnly', start);
assert.ok(start >= 0 && end > start);
const refreshSource = appMain.slice(start, end);
const staticSource = readStaticContractSource('public/meituan-static.js');
const staticWindow = {};
vm.runInNewContext(staticSource, { window: staticWindow });
const buildView = staticWindow.SUXI_MEITUAN_STATIC.buildMeituanOrderFlowView;
const captureRunAt = '2026-09-29T01:20:00.000001Z';

function harness(readback, receiptOverrides = {}) {
  const toasts = [];
  const readOptions = [];
  const sandbox = {
    meituanOrderFlowRefreshRequestSeq: 0,
    meituanForm: { value: { hotelId: '80' } },
    meituanOrderFlowPeriod: { value: 'last_7_days' },
    meituanOrderFlowError: { value: '' },
    meituanOrderFlowFetching: { value: false },
    MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS: 0,
    selectedMeituanHotelConfig: { value: { id: 5 } },
    captureAuthSession: () => ({ epoch: 1 }),
    isAuthSessionCurrent: () => true,
    showToast: (message, level) => toasts.push({ message, level }),
    loadMeituanConfigList: async () => {},
    isMeituanExecutionConfigReady: () => true,
    resolveMeituanExecutionConfigId: () => '5',
    resolveMeituanOrderFlowDateRange: () => ({ startDate: '2026-09-23', endDate: '2026-09-29' }),
    buildMeituanOrderFlowView: buildView,
    request: async () => ({
      code: 200,
      data: {
        status: 'complete', persisted: true, persistence_status: 'readback_verified',
        saved_count: 2, row_count: 2, order_flow_period: 'last_7_days',
        request_start_date: '2026-09-23', request_end_date: '2026-09-29',
        order_flow_capture_run_at: captureRunAt,
        ...receiptOverrides,
      },
    }),
    loadMeituanOrderFlowData: async options => { readOptions.push(options); return readback; },
  };
  vm.runInNewContext(`${refreshSource}\nthis.refresh = refreshMeituanOrderFlowData;`, sandbox);
  return { sandbox, toasts, readOptions };
}

test('POST success with an empty saved-period GET remains unverified', async () => {
  const h = harness({ ok: true, status: 'verified', hotelId: '80', period: 'last_7_days', rows: [] });
  const result = await h.sandbox.refresh();
  assert.equal(result.readback_status, 'unverified');
  assert.equal(h.toasts.at(-1).level, 'warning');
  assert.match(h.sandbox.meituanOrderFlowError.value, /回读|未验证/);
});

test('a complete saved result rereads directly and requires both same-period direction summaries', async () => {
  const rows = ['loss', 'inflow'].map((direction, index) => ({
    id: index + 1, system_hotel_id: 80, source: 'meituan', data_type: 'order_flow', data_date: '2026-09-29',
    raw_data: JSON.stringify({ order_flow_row_type: 'summary', order_flow_direction: direction,
      order_flow_period: 'last_7_days', order_flow_capture_run_at: captureRunAt,
      period_start: '2026-09-23', period_end: '2026-09-29', order_count: 1, room_nights: 1, amount: 100 }),
  }));
  const h = harness({ ok: true, status: 'verified', hotelId: '80', period: 'last_7_days', rows });
  const result = await h.sandbox.refresh();
  assert.equal(h.readOptions[0].fresh, true, 'post-save GET must not join a pre-save in-flight read');
  assert.equal(result.status, 'complete');
  assert.equal(h.toasts.at(-1).level, 'success');
});

test('a saved receipt cannot verify an earlier capture run from the same period', async () => {
  const rows = ['loss', 'inflow'].map(direction => ({
    data_type: 'order_flow', data_date: '2026-09-29',
    raw_data: JSON.stringify({ order_flow_row_type: 'summary', order_flow_direction: direction,
      order_flow_period: 'last_7_days', order_flow_capture_run_at: '2026-09-29T01:19:59.000001Z',
      period_start: '2026-09-23', period_end: '2026-09-29', order_count: 1, room_nights: 1, amount: 100 }),
  }));
  const h = harness({ ok: true, status: 'verified', hotelId: '80', period: 'last_7_days', rows });
  const result = await h.sandbox.refresh();
  assert.equal(result.readback_status, 'unverified');
  assert.equal(h.toasts.at(-1).level, 'warning');
});

test('a successful HTTP response without an exact saved receipt cannot announce an update', async () => {
  const h = harness({ ok: true, status: 'verified', hotelId: '80', period: 'last_7_days', rows: [] }, {
    persisted: false, persistence_status: 'display_only', saved_count: 0,
  });
  const result = await h.sandbox.refresh();
  assert.equal(result, null);
  assert.equal(h.readOptions.length, 0);
  assert.equal(h.toasts.at(-1).level, 'error');
  assert.match(h.sandbox.meituanOrderFlowError.value, /回执.*未验证/);
});
