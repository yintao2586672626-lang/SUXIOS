import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

// Exercise the shipped component methods against synthetic, scoped responses.
// No local account, database, or OTA data is read or written.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = fs.readFileSync(path.join(root, 'public/components/system/operating-finance-control-center.js'), 'utf8');
const sandbox = { window: {}, URLSearchParams, Date, Intl };
vm.runInNewContext(source, sandbox);
const component = sandbox.window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
const hex = character => character.repeat(64);
const scope = (hotelId = 7, platform = 'ctrip', month = '2026-09') => ({
  tenant_id: 70, hotel_id: hotelId, platform,
  period_start: `${month}-01`, period_end: month === '2026-09' ? '2026-09-30' : '2026-10-31',
});
const item = (batchId = 501, status = 'partial') => ({
  batch_id: batchId, batch_fingerprint: hex('b'), batch_status: status,
  imported_at: '2026-09-15 12:00:00', supersedes_batch_id: null,
  source: { source_method: 'manual_export', source_quality_status: 'operator_attested',
    file_sha256: hex('a'), parser_version: 'canonical_settlement_json.v1' },
  counts: { line_count: 1, available: 0, partial: status === 'invalid' ? 0 : 1,
    invalid: status === 'invalid' ? 1 : 0 },
  totals: { net_revenue: { value: null, basis: 'missing' } },
});
const history = (items = [item()], overrides = {}) => ({
  contract_version: 'ota_settlement_history.v1', scope: scope(),
  read_status: items.length ? 'available' : 'empty', total: items.length,
  page: 1, page_size: 20, pages: items.length ? 1 : 0, items, ...overrides,
});
const detail = (selected = item(), overrides = {}) => ({
  contract_version: 'ota_settlement_reconciliation.v1', batch_id: selected.batch_id,
  batch_fingerprint: selected.batch_fingerprint, batch_status: selected.batch_status,
  imported_at: selected.imported_at, read_status: 'available', readback_verified: true,
  scope: { ...scope(), source_hotel_id: 88 }, source: { ...selected.source },
  counts: { ...selected.counts }, totals: { net_revenue: { ...selected.totals.net_revenue } },
  lines: [{ batch_id: selected.batch_id, source_line_no: 1, business_date: '2026-09-01',
    quality_status: selected.batch_status, gap_codes: ['net_revenue_missing'] }],
  authorization: { external_write_authorized: false, ota_write_authorized: false,
    pms_write_authorized: false, accounting_write_authorized: false }, ...overrides,
});
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; });
  return { promise, resolve }; };
function fixture(request) {
  const state = { ...component.data(), hotelId: '7', periodMonth: '2026-09', platform: 'ctrip',
    overview: { tenant_id: 70, hotel_id: 7, settlement: { batch_id: 999 } }, request };
  Object.assign(state, component.methods);
  for (const [name, computed] of Object.entries(component.computed)) {
    Object.defineProperty(state, name, { get: () => computed.call(state) });
  }
  return state;
}

test('history list and exact ID detail preserve the selected hotel, source and latest projection', async () => {
  const calls = [];
  const selected = item(501, 'invalid');
  const state = fixture(async (route, options) => {
    calls.push({ route, hotelId: options.businessContext.hotelId });
    return { code: 200, data: route.includes('/history?') ? history([selected]) : detail(selected) };
  });
  const latest = state.overview.settlement;
  await state.loadSettlementHistory(1);
  assert.equal(state.settlementHistoryState, 'available');
  assert.equal(state.settlementHistory.items[0].batch_status, 'invalid');
  assert.match(calls[0].route, /period_month=2026-09/);
  assert.match(calls[0].route, /hotel_id=7/);
  assert.match(calls[0].route, /page_size=20/);
  await state.readSettlementBatch(501);
  assert.equal(calls[1].hotelId, 7);
  assert.match(calls[1].route, /\/settlements\/501\?/);
  assert.equal(state.settlementDetailState, 'available');
  assert.equal(state.settlementDetail.scope.source_hotel_id, 88, 'migration source hotel is retained');
  assert.equal(state.settlementDetail.totals.net_revenue.value, null, 'missing net is not zero-filled');
  assert.strictEqual(state.overview.settlement, latest, 'historical view never replaces latest projection');
});

test('empty, failed and mismatched history remain distinct and recover on retry', async () => {
  let reply = { code: 200, data: history([]) };
  const state = fixture(async () => reply);
  await state.loadSettlementHistory(1);
  assert.equal(state.settlementHistoryState, 'empty');
  reply = { code: 500, message: 'synthetic failure' };
  await state.loadSettlementHistory(1);
  assert.equal(state.settlementHistoryState, 'error');
  reply = { code: 200, data: history([item()], { scope: scope(8) }) };
  await state.loadSettlementHistory(1);
  assert.equal(state.settlementHistoryState, 'error', 'cross-hotel list is rejected');
  reply = { code: 200, data: history([item()]) };
  await state.loadSettlementHistory(1);
  assert.equal(state.settlementHistoryState, 'available');
  reply = { code: 200, data: detail(item(), { batch_id: 999 }) };
  await state.readSettlementBatch(501);
  assert.equal(state.settlementDetailState, 'error', 'wrong ID detail is rejected');
  reply = { code: 200, data: detail(item()) };
  await state.readSettlementBatch(501);
  assert.equal(state.settlementDetailState, 'available');
});

test('late responses after scope and selection changes cannot overwrite current history', async () => {
  const first = deferred(), oldDetail = deferred();
  let reply = first.promise;
  const state = fixture(async route => route.includes('/history?') ? reply : oldDetail.promise);
  const pendingList = state.loadSettlementHistory(1);
  state.hotelId = '8';
  state.resetSettlementHistory();
  first.resolve({ code: 200, data: history([item()]) });
  await pendingList;
  assert.equal(state.settlementHistoryState, 'idle');
  assert.equal(state.settlementHistory, null);

  state.hotelId = '7';
  reply = Promise.resolve({ code: 200, data: history([item()]) });
  await state.loadSettlementHistory(1);
  const pendingDetail = state.readSettlementBatch(501);
  state.periodMonth = '2026-10';
  state.resetSettlementHistory();
  oldDetail.resolve({ code: 200, data: detail(item()) });
  await pendingDetail;
  assert.equal(state.settlementDetailState, 'idle');
  assert.equal(state.settlementSelectedBatchId, 0);
});

test('out-of-range empty page retains total for recovery to prior page', async () => {
  const state = fixture(async () => ({ code: 200, data: history([], {
    read_status: 'empty', total: 21, page: 3, pages: 2,
  }) }));
  await state.loadSettlementHistory(3);
  assert.equal(state.settlementHistoryState, 'empty');
  assert.equal(state.settlementHistory.total, 21);
  assert.equal(state.settlementHistory.page, 3);
});
