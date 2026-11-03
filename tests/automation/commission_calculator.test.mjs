import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';

const html = readFileSync(new URL('../../public/tools/佣金调整测算器.html', import.meta.url), 'utf8');
const core = html.match(/<script id="calculator-core">([\s\S]*?)<\/script>/)[1];
const api = vm.runInNewContext(core + '\n({ calculateCommission, calculateForecast })');
const base = { price:'300', nights:'1000', oldRate:'10', newRate:'15', costEnabled:false, cost:'' };
const calc = values => api.calculateCommission({ ...base, ...values });
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-10, `${actual} != ${expected}`);

test('unknown cost is explicit revenue-only mode, not a fabricated actual zero cost', () => {
  const result = calc();
  assert.equal(result.cost, null);
  assert.equal(result.costEnabled, false);
  assert.equal(result.oldMargin, 270);
  assert.equal(result.newMargin, 255);
  close(result.percent, 5.882352941176471);
  assert.equal(result.minimumNights, 1059);
});

test('15 to 10 allows 5.5555 percent revenue-only decline, not the reverse percentage', () => {
  const result = calc({ oldRate:15, newRate:10 });
  close(result.percent, -5.555555555555555);
  assert.equal(result.minimumNights, 945);
});

test('provided 30-yuan cost reproduces both original examples', () => {
  const up = calc({ costEnabled:true, cost:30 });
  assert.equal(up.oldMargin, 240); assert.equal(up.newMargin, 225);
  close(up.percent, 6.666666666666667); assert.equal(up.minimumNights, 1067);
  const down = calc({ costEnabled:true, cost:30, oldRate:15, newRate:10 });
  close(down.percent, -6.25); assert.equal(down.minimumNights, 938);
});

test('arbitrary room prices change amounts; price cancels only in revenue mode', () => {
  for (const price of ['168','399.50','587.23']) close(calc({ price }).percent, calc().percent);
  assert.notEqual(calc({ price:400, costEnabled:true, cost:30 }).percent,
    calc({ price:500, costEnabled:true, cost:30 }).percent);
});

test('each commission point and decimal rate is calculated against its own denominator', () => {
  close(calc({ newRate:11 }).percent, 100 / 89);
  close(calc({ oldRate:11, newRate:12 }).percent, 100 / 88);
  close(calc({ oldRate:12.3, newRate:14.7 }).percent, 2.4 / 85.3 * 100);
});

test('identical rates preserve quantities exactly', () => {
  const result = calc({ oldRate:12.7, newRate:12.7, price:387.29 });
  assert.equal(result.percent, 0); assert.equal(result.minimumNights, 1000);
});

test('empty, malformed, out-of-range and fractional-count inputs fail closed', () => {
  for (const values of [ { price:'' }, { price:0 }, { price:-1 }, { price:'NaN' },
    { price:'Infinity' }, { price:'1e3' }, { price:'1.001' }, { nights:'' },
    { nights:0 }, { nights:1.5 }, { oldRate:9.9 }, { newRate:15.1 },
    { newRate:'10.01' }, { oldRate:'' }, { costEnabled:true, cost:'' },
    { costEnabled:true, cost:-1 } ]) assert.throws(() => calc(values));
  assert.equal(calc({ costEnabled:true, cost:0 }).cost, 0);
});

test('zero/negative contribution is not shown as an attainable growth target', () => {
  assert.throws(() => calc({ costEnabled:true, cost:270 }), /调整前/);
  assert.throws(() => calc({ costEnabled:true, cost:255 }), /调整后/);
  assert.throws(() => calc({ costEnabled:true, cost:260 }), /调整后/);
});

test('integer ceiling guarantees preservation; one fewer night cannot preserve it', () => {
  for (const price of [0.01,168.37,300,999999.99]) {
    for (const nights of [1,17,1000,1000000]) {
      for (const [oldRate,newRate] of [[10,15],[15,10],[12.3,14.7],[14.9,14.9]]) {
        const result = calc({ price,nights,oldRate,newRate });
        assert.ok(result.minimumTotalUnits >= result.oldTotalUnits);
        assert.ok(BigInt(result.minimumNights - 1) * BigInt(result.newUnits) < result.oldTotalUnits);
      }
    }
  }
  const extreme = calc({ price:1000000, nights:1000000, costEnabled:true, cost:849999.99 });
  assert.ok(extreme.minimumTotalUnits >= extreme.oldTotalUnits);
});

test('optional forecast distinguishes missing input, zero nights, growth and decline', () => {
  const result = calc({ costEnabled:true, cost:30 });
  assert.equal(api.calculateForecast(result, ''), null);
  assert.equal(api.calculateForecast(result, '0').differenceUnits, -result.oldTotalUnits);
  assert.equal(api.calculateForecast(result, '1067').differenceUnits, 7500000n);
  assert.ok(api.calculateForecast(result, '1066').differenceUnits < 0n);
  assert.throws(() => api.calculateForecast(result, '10.5'));
  assert.throws(() => api.calculateForecast(result, '-1'));
});

test('standalone document has no network or storage dependencies', () => {
  assert.doesNotMatch(html, /<script[^>]+src=|<link[^>]+href=|@import|url\(https?:|\bfetch\(|XMLHttpRequest|localStorage|sessionStorage/);
  assert.match(html, /10%—15%并非对任何平台当前政策/);
  assert.match(html, /不是真实保本线/);
  assert.match(html, /<meta name="viewport"/);
  assert.match(html, /buildDownloadHtml/);
});

test('percentage displays keep exactly two decimals without rounding calculation inputs', () => {
  const expression = html.match(/const percentText = (.*);/)[1];
  const format = vm.runInNewContext('(' + expression + ')');
  for (const [value, expected] of [[0,'0.00'],[1,'1.00'],[1.2,'1.20'],[6.666666,'6.67'],[5.882353,'5.88'],[-6.25,'6.25']]) {
    assert.equal(format(value), expected);
  }
  const result = calc({ costEnabled:true, cost:30 });
  close(result.percent, 6.666666666666667);
  assert.equal(result.minimumNights, 1067);
  assert.match(html, /id="threshold-value">5\.88</);
});
