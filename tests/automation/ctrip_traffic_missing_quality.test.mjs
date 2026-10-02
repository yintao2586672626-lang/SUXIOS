import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { loadSystemStaticApi } from './helpers/system_static_api.mjs';

const main = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const start = main.indexOf('            const ctripTrafficCoreMetricKeys = ');
const end = main.indexOf('            const getSelectedCtripHotelId = ', start);
assert.ok(start >= 0 && end > start, 'production Ctrip traffic quality model exists');
const source = main.slice(start, end);

function quality(row) {
  const scope = {
    ctripTrafficRows: { value: [row] },
    appSystemStatic: loadSystemStaticApi(),
    onlineDataResult: { value: { status: 'success' } },
    ctripTrafficHistoryResult: { value: { request_end_date: '2026-07-29', readback_verified: true, persisted: true, saved_count: 1 } },
    ctripTrafficForm: { value: { dateRange: 'custom', startDate: '2026-07-29', endDate: '2026-07-29' } },
    computed: fn => ({ get value() { return fn(); } }),
    formatDate: () => '2026-07-29',
    toFixedSafe: value => String(value),
    formatNumber: value => String(value),
  };
  return vm.runInNewContext(`${source}\nctripTrafficBusinessQuality.value`, scope);
}

const base = {
  date: '2026-07-29', compareType: 'self',
  listExposure: null, detailExposure: null, flowRate: null,
  orderFillingNum: null, orderSubmitNum: null,
};

test('stored self-traffic with missing capture fields is a data gap, not true zero', () => {
  const result = quality(base);
  assert.equal(result.status, 'partial');
  assert.match(result.title, /缺失/);
  assert.equal(result.zeroRows, 0);
});

test('explicit captured zero remains a zero-value state', () => {
  const result = quality(Object.fromEntries(Object.entries(base).map(([key, value]) => [key, value === null ? 0 : value])));
  assert.equal(result.status, 'zero_value_unverified');
  assert.equal(result.zeroRows, 1);
});

test('one nonzero captured field does not hide missing core fields', () => {
  const result = quality({ ...base, listExposure: 100 });
  assert.equal(result.status, 'partial');
  assert.equal(result.nonzeroRows, 1);
});

test('complete nonzero self-traffic retains ready quality state', () => {
  const result = quality({ ...base, listExposure: 100, detailExposure: 20, flowRate: 20, orderFillingNum: 5, orderSubmitNum: 1 });
  assert.equal(result.status, 'ready');
});

test('summary card displays missing separately from an observed zero', () => {
  const summary = { value: { self: { listExposure: null } } };
  const scope = {
    appSystemStatic: loadSystemStaticApi(),
    computed: fn => ({ get value() { return fn(); } }),
    ctripTrafficRows: { value: [{ compareType: 'self' }] },
    ctripTrafficSummary: summary,
    formatNumber: value => String(value),
  };
  const format = vm.runInNewContext(`${source}\nformatCtripTrafficSummaryMetric`, scope);
  assert.equal(format('self', { key: 'listExposure' }), '未返回');
  assert.equal(format('avg', { key: 'listExposure' }), '未返回');
  summary.value.self.listExposure = 0;
  assert.equal(format('self', { key: 'listExposure' }), '0');
});
