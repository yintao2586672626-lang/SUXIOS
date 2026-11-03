import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
for (const file of ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js', 'revenue-ai-static.js']) {
  vm.runInNewContext(readFileSync(`public/${file}`, 'utf8'), context, { filename: file });
}
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const overview = () => ({
  hotel_id: 20, business_date: '2026-08-20',
  three_source_fact_layer: {
    contract_version: 'revenue_fact_layer.v1', hotel: { tenant_id: 10, system_hotel_id: 20 },
    business_date: '2026-08-20', source_completeness: {},
    sources: { ctrip_ota: { data_status: 'readback_verified' }, meituan_ota: { data_status: 'readback_verified' } },
    facts: { ota_channel: { combined: { revenue: 200, orders: 2, room_nights: 2 },
      combined_status: { fact_statuses: { orders: { status: 'readback_verified', reason: '' } } } } },
  },
  metrics: { ota_room_revenue: { value: 200, status: 'ok', unit: 'CNY', truth: {
    status: 'verified', hotels: [{ system_hotel_id: 20, name: 'local_synthetic_fixture_only' }],
    platforms: ['ctrip', 'meituan'], date_range: { start: '2026-08-20', end: '2026-08-20' },
    source: { table: 'online_daily_data', row_ids: [1, 2], methods: ['local_synthetic_fixture_only'] },
    persistence: { record_count: 2, stored_count: 2, readback_verified_count: 2 },
    failure_reason: '', evidence_gap_codes: [],
  } } },
});
const orderChip = (input) => helpers.buildRevenueAiBusinessClosure({ overview: input }).rows
  .find((row) => row.stage === '收益分析').metrics.find((metric) => metric.key === 'orders');

test('independent readback orders retain their own truth when combined income overflows and recovers', () => {
  const input = overview();
  const normal = orderChip(input);
  input.metrics.ota_room_revenue.value = null;
  input.metrics.ota_room_revenue.status = 'not_calculable';
  input.metrics.ota_room_revenue.truth.status = 'unverified';
  input.metrics.ota_room_revenue.truth.failure_reason = 'numeric_aggregate_nonfinite';
  input.metrics.ota_room_revenue.truth.evidence_gap_codes = ['numeric_aggregate_nonfinite'];
  const original = JSON.stringify(input);
  const failedIncome = orderChip(input);
  assert.equal(failedIncome.value, '2单');
  assert.equal(failedIncome.truthStatus, 'verified');
  assert.equal(failedIncome.statusLabel, '已验证');
  assert.doesNotMatch(failedIncome.truthSummary, /numeric_aggregate_nonfinite/);
  assert.match(failedIncome.truthSummary, /2026-08-20.*local_synthetic_fixture_only/);
  assert.equal(JSON.stringify(input), original);
  assert.equal(normal.truthSummary, failedIncome.truthSummary);
  assert.equal(helpers.buildRevenueAiBusinessClosure({ overview: input }).calculationAllowed, false);
  input.metrics.ota_room_revenue = overview().metrics.ota_room_revenue;
  assert.equal(orderChip(input).truthStatus, 'verified');
});

test('orders missing, failed or legacy field evidence never borrow verified income truth', () => {
  for (const status of [null, { status: 'not_verified', reason: 'orders_field_missing' },
    { status: 'not_calculable', reason: 'numeric_aggregate_nonfinite' }, { status: 'derived_verified' }]) {
    const input = overview();
    input.three_source_fact_layer.facts.ota_channel.combined_status.fact_statuses.orders = status;
    const chip = orderChip(input);
    assert.equal(chip.truthStatus, 'unverified');
    assert.notEqual(chip.status, 'ok');
    assert.notEqual(chip.reasonText, '');
  }
});

test('a verified zero remains zero while invalid and missing order values remain unavailable', () => {
  const input = overview();
  input.three_source_fact_layer.facts.ota_channel.combined.orders = 0;
  assert.equal(orderChip(input).value, '0单');
  assert.equal(orderChip(input).truthStatus, 'verified');
  for (const value of [null, undefined, Infinity, NaN, -1, 1.5, '2', Number.MAX_SAFE_INTEGER + 1]) {
    input.three_source_fact_layer.facts.ota_channel.combined.orders = value;
    assert.equal(orderChip(input).value, '--');
    assert.equal(orderChip(input).truthStatus, 'unverified');
  }
});

test('wrong nested hotel or date clears the order value and does not promote its field status', () => {
  for (const [key, value] of [['hotel', { tenant_id: 10, system_hotel_id: 21 }], ['business_date', '2026-08-21']]) {
    const input = overview();
    input.three_source_fact_layer[key] = value;
    const chip = orderChip(input);
    assert.equal(chip.value, '--');
    assert.equal(chip.truthStatus, 'unverified');
    assert.match(chip.reasonText, /范围|日期|门店/);
  }
});

test('legacy scope omissions retain the candidate order count without claiming verification', () => {
  for (const missing of ['outer_hotel', 'nested_hotel', 'outer_date', 'nested_date']) {
    const input = overview();
    if (missing === 'outer_hotel') delete input.hotel_id;
    if (missing === 'nested_hotel') delete input.three_source_fact_layer.hotel;
    if (missing === 'outer_date') delete input.business_date;
    if (missing === 'nested_date') delete input.three_source_fact_layer.business_date;
    const chip = orderChip(input);
    assert.equal(chip.value, '2单');
    assert.equal(chip.truthStatus, 'unverified');
    assert.notEqual(chip.reasonText, '');
  }
});
