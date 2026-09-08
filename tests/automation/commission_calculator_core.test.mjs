import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

// The standalone calculator is the unchanged reference, not an OTA data fixture.
const html = readFileSync(new URL('../../public/tools/佣金调整测算器.html', import.meta.url), 'utf8');
const referenceSource = html.match(/<script id="calculator-core">([\s\S]*?)<\/script>/)?.[1];
assert.ok(referenceSource, 'standalone calculator core must remain available as the reference');
const reference = vm.runInNewContext(referenceSource + '\n({ calculateCommission, calculateForecast })');
const helperSource = readFileSync(new URL('../../public/components/revenue/commission-calculator-core.js', import.meta.url), 'utf8');
const sandbox = { window: {} };
vm.runInNewContext(helperSource, sandbox);
const api = sandbox.window.SUXI_COMMISSION_CALCULATOR_CORE;
const base = { price: '300', nights: '1000', oldRate: '10', newRate: '15', costEnabled: true, cost: '30' };
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-10, `${actual} != ${expected}`);
const plain = value => value === null ? null : { ...value };

function calculate(values = {}) {
  const input = { ...base, ...values };
  const before = { ...input };
  const actual = api.calculateCommission(input);
  assert.deepEqual(plain(actual), plain(reference.calculateCommission(input)));
  assert.deepEqual(input, before, 'calculation must not mutate its input');
  return actual;
}

function failureFrom(run) {
  try {
    run();
  } catch (error) {
    return { name: error.name, message: error.message };
  }
  assert.fail('expected invalid input to throw');
}

function assertCommissionError(values, message) {
  const input = { ...base, ...values };
  const actual = failureFrom(() => api.calculateCommission(input));
  assert.deepEqual(actual, failureFrom(() => reference.calculateCommission(input)));
  if (message) assert.match(actual.message, message);
}

function forecast(result, rawNights) {
  const before = { ...result };
  const actual = api.calculateForecast(result, rawNights);
  assert.deepEqual(plain(actual), plain(reference.calculateForecast(result, rawNights)));
  assert.deepEqual(plain(result), before, 'forecast must not mutate its input');
  return actual;
}

test('helper exports only a frozen two-function API without leaking internal functions', () => {
  assert.deepEqual(Object.keys(api).sort(), ['calculateCommission', 'calculateForecast']);
  assert.equal(Object.isFrozen(api), true);
  assert.deepEqual(Object.keys(sandbox), ['window']);
  assert.deepEqual(Object.keys(sandbox.window), ['SUXI_COMMISSION_CALCULATOR_CORE']);
  assert.throws(() => { api.calculateCommission = () => null; }, TypeError);
});

test('10 to 15 with 30-yuan cost preserves the full-precision 6.666 percent and 1067 nights', () => {
  const result = calculate();
  close(result.percent, 6.666666666666667);
  assert.notEqual(result.percent, 6.67, 'two decimal places are a display choice only');
  assert.equal(result.minimumNights, 1067);
  assert.equal(result.oldMargin, 240);
  assert.equal(result.newMargin, 225);
  assert.equal(result.direction, 1);
});

test('15 to 10 with 30-yuan cost preserves the 6.25 percent allowance and 938 nights', () => {
  const result = calculate({ oldRate: 15, newRate: 10 });
  assert.equal(result.percent, -6.25);
  assert.equal(result.minimumNights, 938);
  assert.equal(result.direction, -1);
});

test('disabled cost remains unknown, while explicit zero cost remains a provided value', () => {
  for (const costEnabled of [false, undefined, 'true']) {
    const result = calculate({ costEnabled, cost: '' });
    assert.equal(result.costEnabled, false);
    assert.equal(result.cost, null);
    close(result.percent, 5.882352941176471);
    assert.equal(result.minimumNights, 1059);
  }
  const zero = calculate({ cost: 0 });
  assert.equal(zero.costEnabled, true);
  assert.equal(zero.cost, 0);
  close(zero.percent, 5.882352941176471);
});

test('arbitrary prices and decimal commissions preserve all reference fields and fractional units', () => {
  for (const price of ['168', '399.50', '587.23']) {
    close(calculate({ price, costEnabled: false }).percent, 5.882352941176471);
    calculate({ price, oldRate: 12.3, newRate: 14.7 });
  }
  const decimal = calculate({ price: 387.29, oldRate: 12.3, newRate: 14.7 });
  assert.equal(decimal.oldUnits, 38729 * 877 - 3000000);
  assert.equal(decimal.newUnits, 38729 * 853 - 3000000);
  assert.notEqual(calculate({ price: 400 }).percent, calculate({ price: 500 }).percent);
});

test('identical commission rates preserve nights and totals exactly', () => {
  const result = calculate({ price: 387.29, oldRate: 12.7, newRate: 12.7 });
  assert.equal(result.percent, 0);
  assert.equal(result.direction, 0);
  assert.equal(result.minimumNights, 1000);
  assert.equal(result.minimumTotalUnits, result.oldTotalUnits);
});

test('blank, negative, malformed and out-of-range inputs preserve validation failures', () => {
  for (const values of [
    { price: '' }, { price: null }, { price: undefined }, { price: 0 }, { price: -1 },
    { price: 'NaN' }, { price: 'Infinity' }, { price: '1e3' }, { price: '1.001' },
    { price: 1000000.01 }, { nights: '' }, { nights: 0 }, { nights: -1 },
    { nights: 1.5 }, { nights: 1000001 }, { oldRate: '' }, { oldRate: 9.9 },
    { newRate: 15.1 }, { newRate: '10.01' }, { cost: '' }, { cost: -1 },
    { cost: '0.001' }, { cost: 1000000.01 },
  ]) assertCommissionError(values);
  assertCommissionError({ price: '   ' }, /空白不会按0计算/);
  assert.equal(calculate({ price: ' 300 ', nights: ' 1000 ' }).price, 300);
});

test('zero or negative net contribution never becomes an attainable threshold', () => {
  assertCommissionError({ cost: 270 }, /调整前/);
  assertCommissionError({ cost: 275 }, /调整前/);
  assertCommissionError({ cost: 255 }, /调整后/);
  assertCommissionError({ cost: 260 }, /调整后/);
});

test('exact divisibility and adjacent cases retain the minimum integer-night ceiling', () => {
  const exact = calculate({ nights: 15 });
  assert.equal(exact.minimumNights, 16);
  assert.equal(exact.minimumTotalUnits, exact.oldTotalUnits);
  for (const nights of [1, 14, 15, 16, 1000]) {
    const result = calculate({ nights });
    assert.ok(result.minimumTotalUnits >= result.oldTotalUnits);
    assert.ok(BigInt(result.minimumNights - 1) * BigInt(result.newUnits) < result.oldTotalUnits);
  }
});

test('large totals retain BigInt precision and near-zero positive margins retain exact ceilings', () => {
  const large = calculate({ price: 1000000, nights: 1000000, costEnabled: false });
  assert.equal(large.oldTotalUnits, 90000000000000000n);
  assert.ok(large.oldTotalUnits > BigInt(Number.MAX_SAFE_INTEGER));
  assert.equal(large.minimumNights, 1058824);
  const extreme = calculate({ price: 1000000, nights: 1000000, cost: 849999.99 });
  assert.equal(extreme.newUnits, 1000);
  assert.equal(extreme.minimumNights, 5000001000000);
  for (const result of [large, extreme]) {
    assert.equal(typeof result.minimumTotalUnits, 'bigint');
    assert.ok(result.minimumTotalUnits >= result.oldTotalUnits);
    assert.ok(BigInt(result.minimumNights - 1) * BigInt(result.newUnits) < result.oldTotalUnits);
  }
  assert.equal(forecast(large, 1000000).totalUnits, 85000000000000000n);
});

test('forecast preserves the distinction between missing input, zero, below-target and sufficient nights', () => {
  const result = calculate();
  for (const missing of ['', '   ', null, undefined]) assert.equal(forecast(result, missing), null);
  for (const zero of ['0', 0]) {
    const estimate = forecast(result, zero);
    assert.equal(estimate.nights, 0);
    assert.equal(estimate.totalUnits, 0n);
    assert.equal(estimate.differenceUnits, -result.oldTotalUnits);
  }
  assert.ok(forecast(result, 1066).differenceUnits < 0n);
  assert.equal(forecast(result, 1067).differenceUnits, 7500000n);
  for (const invalid of ['-1', '10.5', 'NaN', '1e3', 1000001]) {
    assert.deepEqual(failureFrom(() => api.calculateForecast(result, invalid)),
      failureFrom(() => reference.calculateForecast(result, invalid)));
  }
});
