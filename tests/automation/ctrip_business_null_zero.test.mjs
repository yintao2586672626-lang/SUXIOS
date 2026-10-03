import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Execute only pure production normalizers; never start the browser collector.
const source = readFileSync('scripts/ctrip_browser_capture.mjs', 'utf8');
const names = ['normalizeBusinessRow', 'firstValue', 'numberValue', 'stringValue',
  'ctripPlatformHotelId', 'normalizeScore', 'normalizePercent', 'normalizeDate'];
const code = names.map(name => {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0);
  return source.slice(start, source.indexOf('\nfunction ', start + 1));
}).join('\n');
const normalize = vm.runInNewContext(`${code}\nnormalizeBusinessRow`, { hotelId: '', args: {} });
const base = { hotelId: 'synthetic-hotel', dataDate: '2026-09-01' };

test('legacy business preserves observed zero sales and missing metrics', () => {
  const row = normalize({ ...base, amount: 0, quantity: 0, bookOrderNum: 0 }, 'synthetic://business');
  assert.ok(row);
  for (const key of ['amount', 'quantity', 'bookOrderNum']) assert.equal(row[key], 0);
  for (const key of ['totalDetailNum', 'commentScore', 'avgPrice', 'convertionRate', 'competitorUv',
    'competitorOrderNum', 'competitorAmount', 'psi', 'replyRate', 'favoriteCount', 'visitorRank']) assert.equal(row[key], null, key);
});

test('legacy partial business does not fabricate missing numbers or ADR', () => {
  const row = normalize({ ...base, quantity: 2, amount: '-', uv: ' ', psi: null }, 'synthetic://business');
  assert.equal(row.quantity, 2);
  for (const key of ['amount', 'bookOrderNum', 'totalDetailNum', 'avgPrice', 'psi']) assert.equal(row[key], null, key);
  assert.equal(normalize(base, 'synthetic://business'), null);
});

test('legacy explicit zero optional metrics and measured ADR remain valid', () => {
  assert.equal(normalize({ ...base, replyRate: 0 }, 'synthetic://business').replyRate, 0);
  const row = normalize({ ...base, amount: 300, quantity: 2, conversionRate: 0.1, commentScore: 48 }, 'synthetic://business');
  assert.equal(row.avgPrice, 150);
  assert.equal(row.convertionRate, 10);
  assert.equal(row.commentScore, 4.8);
  assert.equal(normalize({ amount: 10 }, 'synthetic://business'), null);
});

test('legacy formatting-only values are missing while numeric zero stays observed', () => {
  for (const value of ['%', ',', ' , % ', false, [], {}, null, undefined, '']) {
    const row = normalize({ ...base, quantity: 1, amount: value }, 'synthetic://business');
    assert.equal(row.amount, null, JSON.stringify(value));
    assert.equal(row.avgPrice, null, JSON.stringify(value));
  }
  for (const value of [0, '0', '0.00']) {
    assert.equal(normalize({ ...base, amount: value }, 'synthetic://business').amount, 0);
  }
});
