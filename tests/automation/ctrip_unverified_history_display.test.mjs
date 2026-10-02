import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const context = { window: {}, console };
vm.runInNewContext(readFileSync('public/ctrip-static.js', 'utf8'), context);
const api = context.window.SUXI_CTRIP_STATIC;
const row = { hotelId: 'sample', amount: 1862, quantity: 3, bookOrderNum: 9,
  totalDetailNum: 20, convertionRate: 10, qunarDetailVisitors: 20, qunarDetailCR: 10 };

test('unverified saved competition rows stay auditable without generated channel orders', () => {
  const result = api.attachCtripChannelOrderBreakdown({ ...row, ctripOrderEstimate: 999 }, { sourceReady: false });
  for (const field of ['totalOrderIncludingCancelledEstimate', 'ctripOrderEstimate', 'qunarOrderEstimate', 'ctripUndistributedOrderEstimate']) {
    assert.equal(result[field], null, field);
  }
  assert.equal(result.bookOrderNum, 9);
  assert.equal(result.amount, 1862);
  assert.equal(result.channelOrderBreakdownMeta.status, 'source_unverified');
  assert.match(result.channelOrderBreakdownMeta.sourceLabel, /未核验/);
});

test('verified source retains the existing explicitly estimated order calculation', () => {
  const result = api.attachCtripChannelOrderBreakdown(row, { sourceReady: true });
  assert.equal(result.totalOrderIncludingCancelledEstimate, 12);
  assert.equal(result.ctripOrderEstimate, 2);
  assert.equal(result.qunarOrderEstimate, 2);
  assert.equal(result.ctripUndistributedOrderEstimate, 8);
});

test('summary cards and row estimates share the existing hotel/source-date readiness gate', () => {
  const start = source.indexOf('const ctripOrderSummaryCards = computed(');
  const end = source.indexOf('const ctripSummaryCardOrder = ref(', start);
  const gate = { value: false };
  const scope = {
    computed: fn => ({ get value() { return fn(); } }),
    ctripCompetitionReportSourceReady: gate,
    ctripSortedHotelsList: { value: [{ bookOrderNum: 9 }] },
    ctripOrderSummaryMetricDefinitions: [{ field: 'bookOrderNum', key: 'platform_orders' }],
    ctripBusinessSummary: { value: { cards: [{ key: 'ari', value: '100', level: '价格合理' }] } },
  };
  vm.runInNewContext(`${source.slice(start, end)}; result = () => ({ orders: ctripOrderSummaryCards.value, business: ctripBusinessSummaryCards.value });`, scope);
  assert.equal(scope.result().orders.length, 0);
  assert.equal(scope.result().business.length, 0);
  gate.value = true;
  assert.equal(scope.result().orders[0].value, '9');
  assert.equal(scope.result().business[0].level, '价格合理');
  const scenario = source.slice(source.indexOf('const ctripScenarioHotelsList ='), source.indexOf('const ctripSortedHotelsList ='));
  assert.match(scenario, /sourceReady: ctripCompetitionReportSourceReady\.value/);
});

test('unverified estimates have an explicit visible state in both saved-table entrances', () => {
  const template = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
  assert.equal((template.match(/data-testid="ctrip-unverified-derivation-notice"/g) || []).length, 2);
  const formatter = source.slice(source.indexOf('const ctripTrafficChannelText ='), source.indexOf('const ctripTrafficChannelSecondaryText ='));
  const scope = { formatOptionalNumber: value => value ?? '-', formatOptionalPercent: value => value ?? '-' };
  vm.runInNewContext(`${formatter}; result = ctripTrafficChannelText;`, scope);
  assert.equal(scope.result({ channelOrderBreakdownMeta: { status: 'source_unverified' } }, { field: 'ctripOrderEstimate' }), '未核验');
  assert.equal(scope.result({ bookOrderNum: 9, channelOrderBreakdownMeta: { status: 'source_unverified' } }, { field: 'bookOrderNum' }), 9);
});
