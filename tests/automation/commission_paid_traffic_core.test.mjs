import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const sandbox = { window: {} };
for (const file of ['commission-calculator-core.js', 'commission-paid-traffic-core.js']) {
  const source = readFileSync(new URL('../../public/components/revenue/' + file, import.meta.url), 'utf8');
  vm.runInNewContext(source, sandbox);
}
const commission = sandbox.window.SUXI_COMMISSION_CALCULATOR_CORE;
const api = sandbox.window.SUXI_COMMISSION_PAID_TRAFFIC_CORE;
const baseInputs = { price: '300', nights: '1000', oldRate: '10', newRate: '15', costEnabled: true, cost: '30' };
const paidInputs = { budgetMode: 'commission_gap', budget: '', historicalRoi: '3', incrementalityPercent: '100' };
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-10, `${actual} != ${expected}`);

function calculate(raw = {}, base = {}) {
  const baseResult = Object.freeze(commission.calculateCommission({ ...baseInputs, ...base }));
  const paidRaw = Object.freeze({ ...paidInputs, ...raw });
  return api.calculatePaidTraffic(baseResult, paidRaw);
}

test('paid traffic helper exposes only its frozen single-function API', () => {
  assert.deepEqual(Object.keys(api), ['calculatePaidTraffic']);
  assert.equal(Object.isFrozen(api), true);
  assert.deepEqual(Object.keys(sandbox), ['window']);
  assert.throws(() => { api.calculatePaidTraffic = () => null; }, TypeError);
});

test('equal commission-gap budget uses lower commission and yields the required complete contract', () => {
  assert.deepEqual({ ...calculate() }, {
    budget: 15000,
    budgetSource: 'commission_gap',
    commissionGap: 15000,
    paidCommissionRate: 10,
    historicalRoi: 3,
    incrementalityPercent: 100,
    attributedRevenue: 45000,
    attributedNights: 150,
    incrementalRevenue: 45000,
    incrementalNights: 150,
    unitAmount: 240,
    amountAfterAds: 21000,
    breakEvenRoi: 1.25,
    costIncluded: true,
    metricScope: 'net_contribution_after_ads',
  });
});

test('50 percent incrementality discounts attributed sales before deducting the full ad budget', () => {
  const result = calculate({ incrementalityPercent: '50' });
  assert.equal(result.attributedRevenue, 45000);
  assert.equal(result.attributedNights, 150);
  assert.equal(result.incrementalRevenue, 22500);
  assert.equal(result.incrementalNights, 75);
  assert.equal(result.amountAfterAds, 3000);
  assert.equal(result.breakEvenRoi, 2.5);
});

test('reversing the commission change keeps the same budget and lower-rate ad alternative', () => {
  assert.deepEqual({ ...calculate({}, { oldRate: 15, newRate: 10 }) }, { ...calculate() });
});

test('unknown fulfillment cost is excluded explicitly, while provided zero is included', () => {
  const unknown = calculate({}, { costEnabled: false, cost: '' });
  assert.equal(unknown.unitAmount, 270);
  assert.equal(unknown.amountAfterAds, 25500);
  assert.equal(unknown.costIncluded, false);
  assert.equal(unknown.metricScope, 'commission_revenue_after_ads_excludes_fulfillment');
  close(unknown.breakEvenRoi, 300 / 270);
  const knownZero = calculate({}, { cost: 0 });
  assert.equal(knownZero.unitAmount, 270);
  assert.equal(knownZero.costIncluded, true);
  assert.equal(knownZero.metricScope, 'net_contribution_after_ads');
});

test('ROI zero is valid, but blank or unknown ROI never silently becomes zero', () => {
  for (const historicalRoi of ['', '   ', null, undefined]) {
    assert.throws(() => calculate({ historicalRoi }), /未知值不会按0计算/);
  }
  const zero = calculate({ historicalRoi: '0' });
  assert.equal(zero.attributedRevenue, 0);
  assert.equal(zero.incrementalNights, 0);
  assert.equal(zero.amountAfterAds, -15000);
});

test('zero incrementality has no attainable finite break-even ROI', () => {
  const result = calculate({ incrementalityPercent: '0' });
  assert.equal(result.attributedRevenue, 45000);
  assert.equal(result.incrementalRevenue, 0);
  assert.equal(result.incrementalNights, 0);
  assert.equal(result.amountAfterAds, -15000);
  assert.equal(result.breakEvenRoi, null);
  assert.ok(Object.values(result).every(value => typeof value !== 'number' || Number.isFinite(value)));
});

test('manual zero budget and same-commission gap both stay explicit zero budgets', () => {
  const manual = calculate({ budgetMode: 'manual', budget: '0' });
  assert.equal(manual.budgetSource, 'manual');
  assert.equal(manual.budget, 0);
  assert.equal(manual.commissionGap, 15000);
  assert.equal(manual.attributedRevenue, 0);
  assert.equal(manual.amountAfterAds, 0);
  const same = calculate({}, { oldRate: 12.3, newRate: 12.3 });
  assert.equal(same.budgetSource, 'commission_gap');
  assert.equal(same.commissionGap, 0);
  assert.equal(same.budget, 0);
  assert.equal(same.amountAfterAds, 0);
  assert.equal(same.paidCommissionRate, 12.3);
  assert.throws(() => calculate({ historicalRoi: '' }, { oldRate: 12.3, newRate: 12.3 }), /未知值/);
});

test('fractional commission-gap budget and scenario outputs are not rounded for display', () => {
  const base = { price: 387.29, nights: 17, oldRate: 12.3, newRate: 14.7 };
  const gap = calculate({}, base);
  assert.equal(gap.commissionGap, 158.01432);
  assert.equal(gap.budget, 158.01432);
  assert.equal(gap.unitAmount, (38729 * 877 - 3000000) / 100000);
  const manual = calculate({ budgetMode: 'manual', budget: '123.45', historicalRoi: '2.3456', incrementalityPercent: '63.25' }, base);
  assert.equal(manual.budget, 123.45);
  assert.equal(manual.attributedRevenue, 123.45 * 2.3456);
  assert.equal(manual.incrementalRevenue, manual.attributedRevenue * 0.6325);
  assert.equal(manual.incrementalNights, manual.attributedNights * 0.6325);
  close(manual.amountAfterAds, manual.incrementalNights * manual.unitAmount - 123.45);
  close(manual.breakEvenRoi, 387.29 / (0.6325 * manual.unitAmount));
});

test('manual budget, ROAS, incrementality and budget mode enforce their exact input boundaries', () => {
  for (const budget of ['', ' ', null, undefined, '-1', '1.001', '1e3', 'Infinity']) {
    assert.throws(() => calculate({ budgetMode: 'manual', budget }), /广告预算/);
  }
  for (const historicalRoi of ['-1', '1000.0001', '1.00001', '1e2', 'NaN']) {
    assert.throws(() => calculate({ historicalRoi }), /ROI/);
  }
  for (const incrementalityPercent of ['', null, undefined, '-1', '100.01', '1.001', '1e2']) {
    assert.throws(() => calculate({ incrementalityPercent }), /真实增量比例/);
  }
  assert.throws(() => calculate({ budgetMode: 'unknown' }), /请选择/);
  assert.equal(calculate({ historicalRoi: '1000.0000', incrementalityPercent: '100.00' }).historicalRoi, 1000);
  assert.equal(calculate({ budgetMode: 'manual', budget: ' 12.30 ' }).budget, 12.3);
});

test('invalid base calculations and non-finite derived outputs fail instead of returning false success', () => {
  for (const base of [null, {}, { price: 0 }]) {
    assert.throws(() => api.calculatePaidTraffic(base, paidInputs), /有效的佣金测算/);
  }
  assert.throws(() => calculate({ budgetMode: 'manual', budget: '1' + '0'.repeat(308), historicalRoi: '1000' }), /超出可计算范围/);
});
