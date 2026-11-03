import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
for (const file of ['public/revenue-overview-contract-static.js', 'public/revenue-ai-static.js', 'public/data-health-static.js']) {
  vm.runInNewContext(readFileSync(file, 'utf8'), context, { filename: file });
}
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const summary = context.window.SUXI_DATA_HEALTH_STATIC.onlineTruthSummaryText;
const fixture = (overrides = {}) => ({
  status: 'ok', value: 200, display: '¥200.00', reason: '',
  truth: { status: 'verified', platforms: ['ctrip'], hotels: [{ system_hotel_id: 20 }],
    date_range: { start: '2026-08-20', end: '2026-08-20' }, source_trace: 'local_synthetic_fixture_only' },
  ...overrides,
});
const card = (metric, overviewError = '') => helpers.buildRevenueAiMetricCards({
  overview: { hotel_id: 20, business_date: '2026-08-20', metrics: { ota_room_revenue: metric } }, overviewError,
}).find(item => item.key === 'ota_room_revenue');

test('failed current request hides retained numbers and cannot claim the historical proof is currently usable', () => {
  const original = fixture();
  const encoded = JSON.stringify(original);
  const failed = card(original, 'HTTP503');
  assert.equal(failed.display, '--');
  assert.equal(failed.truth.status, 'verified');
  assert.equal(failed.currentTruth.status, 'collection_failed');
  assert.equal(failed.currentTruth.source_trace, original.truth.source_trace);
  assert.equal(JSON.stringify(failed.currentTruth.hotels), JSON.stringify(original.truth.hotels));
  assert.equal(JSON.stringify(original), encoded);
  assert.match(summary(failed.currentTruth), /采集失败/);
  assert.doesNotMatch(summary(failed.currentTruth), /已验证，可用于当前指标/);
  assert.match(failed.truthSummary, /失败原因：Revenue AI 总览接口请求失败/);
});

test('current partial and rejected scope proofs cannot inherit historical current-usable claims', () => {
  for (const [status, reason, expected] of [
    ['partial', 'room_revenue_partial', 'partial'],
    ['unverified', 'metric_scope_mismatch', 'unverified'],
    ['failed', 'overview_request_failed', 'collection_failed'],
    ['missing', 'ota_revenue_metrics_missing', 'unverified'],
  ]) {
    const result = card(fixture({ status, reason }));
    assert.equal(result.currentTruth.status, expected);
    assert.equal(result.truth.status, 'verified');
    assert.doesNotMatch(summary(result.currentTruth), /已验证，可用于当前指标/);
    if (reason === 'metric_scope_mismatch') assert.match(result.currentTruth.failure_reason, /酒店、平台或业务日期不一致/);
  }
});

test('same-scope successful refresh restores normal and verified zero without mutating the historical receipt', () => {
  for (const metric of [fixture(), fixture({ value: 0, display: '¥0.00' })]) {
    assert.equal(card(metric, 'HTTP503').display, '--');
    const restored = card(metric);
    assert.equal(restored.display, metric.display);
    assert.equal(restored.currentTruth.status, 'verified');
    assert.equal(summary(restored.currentTruth), '已验证，可用于当前指标');
  }
});

test('production card summary uses the current projection with compatibility for old cards', () => {
  const template = readFileSync('resources/frontend/templates/fragments/23c-page-compass-detail.html', 'utf8');
  assert.match(template, /:truth="card\.currentTruth \|\| card\.truth"/);
  const legacy = card(fixture({ value: null, display: '--', status: 'missing', truth: {} }));
  assert.equal(legacy.display, '--');
  assert.doesNotMatch(summary(legacy.currentTruth), /已验证，可用于当前指标/);
});
