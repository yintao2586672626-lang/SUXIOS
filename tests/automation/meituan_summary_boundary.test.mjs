import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {} };
const source = readFileSync('public/meituan-static.js', 'utf8');
vm.runInNewContext(source
  .replace('const meituanRankMaxAttempts =', 'const meituanRankMaxAttempts = window.__maxAttempts =')
  .replace('const isMeituanNonRetryableFetchError =', 'const isMeituanNonRetryableFetchError = window.__nonRetryable ='), context);
const api = context.window.SUXI_MEITUAN_STATIC;
const row = (date, values = {}) => ({ source: 'meituan', system_hotel_id: 80, data_date: date, data_type: 'traffic', ...values });

test('Meituan retry is bounded and rate-limit responses stop immediate retries', () => {
  assert.equal(context.window.__maxAttempts(), 3);
  for (const error of [{ status: 429 }, { code: 429 }, { message: 'HTTP 429 Too many requests' }, { message: '请求过于频繁，请稍后重试' }]) {
    assert.equal(context.window.__nonRetryable(error), true);
  }
  assert.equal(context.window.__nonRetryable({ message: 'temporary network failure' }), false);
});

test('Meituan visible multi-day conversion is weighted by aligned counts, not daily percentages', () => {
  const result = api.buildMeituanDownloadData([
    row('2026-09-01', { list_exposure: 100, detail_exposure: 10, flow_rate: 10 }),
    row('2026-09-02', { list_exposure: 1000, detail_exposure: 900, flow_rate: 90 }),
  ]);
  assert.equal(result.trafficAvgFlowRate, 910 / 1100 * 100);
  assert.equal(result.trafficClickRate, result.trafficAvgFlowRate);
});

test('Meituan visible rates cannot borrow missing counts from another day or hotel', () => {
  for (const rows of [
    [row('2026-09-01', { list_exposure: 100, flow_rate: 10 }), row('2026-09-02', { detail_exposure: 90, flow_rate: 90 })],
    [row('2026-09-01', { list_exposure: 100, detail_exposure: 10 }), row('2026-09-02', { system_hotel_id: 81, list_exposure: 1000, detail_exposure: 900 })],
    [row('2026-09-01', { list_exposure: 100, detail_exposure: 10 }), row('2026-09-01', { list_exposure: 200, detail_exposure: 30 })],
  ]) {
    const result = api.buildMeituanDownloadData(rows);
    assert.equal(result.trafficAvgFlowRate, null);
    assert.equal(result.trafficClickRate, null);
  }
});

test('Meituan ads rates also require matched count pairs', () => {
  const result = api.buildMeituanDownloadData([
    row('2026-09-01', { data_type: 'advertising', list_exposure: 100 }),
    row('2026-09-02', { data_type: 'advertising', detail_exposure: 90 }),
  ]);
  assert.equal(result.adsClickRate, null);
});

test('Meituan counts preserve anomalies but reject malformed numbers and percent-as-count', () => {
  for (const value of ['1,2', '12%', ' ', Infinity, NaN, [], true]) {
    assert.equal(api.getMeituanExposureMetricValue({ list_exposure: value }), null, String(value));
  }
  assert.equal(api.getMeituanExposureMetricValue({ list_exposure: -12 }), -12);
  assert.equal(api.getMeituanExposureMetricValue({ list_exposure: '1,200' }), 1200);
  assert.equal(api.getMeituanFlowRateMetricValue({ flow_rate: '0.5%' }), 0.5);
  assert.equal(api.getMeituanClickMetricValue({ order_filling_num: 10 }), null);
});

test('Meituan direct source rate stays direct, while invalid count-derived rates stay unavailable', () => {
  const direct = api.buildMeituanDownloadData([row('2026-09-01', { flow_rate: 4.55 })]);
  assert.equal(direct.trafficAvgFlowRate, 4.55);
  assert.equal(direct.trafficClickRate, null);
  for (const counts of [{ list_exposure: 0, detail_exposure: 0 }, { list_exposure: -10, detail_exposure: 2 }]) {
    const result = api.buildMeituanDownloadData([row('2026-09-01', counts)]);
    assert.equal(result.trafficAvgFlowRate, null);
    assert.equal(result.trafficClickRate, null);
  }
});
