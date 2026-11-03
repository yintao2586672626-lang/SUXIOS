import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Pure consumer fixture matching task040's saved cross-platform public output.
const context = { window: {}, URLSearchParams };
for (const path of ['public/revenue-overview-contract-static.js', 'public/revenue-ai-static.js']) {
  vm.runInNewContext(readFileSync(path, 'utf8'), context, { filename: path });
}
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const metric = (overrides = {}) => ({
  key: 'ota_room_revenue', value: 100, display: '¥100.00', status: 'ok', reason: '',
  scope: 'ota_channel', date_basis: 'data_date', source_channels: ['ctrip'],
  truth: { status: 'verified', platforms: ['ctrip'], date_range: { start: '2026-07-28', end: '2026-07-28' },
    hotels: [{ system_hotel_id: 80 }], source_tables: ['fact_ota_daily'], source_trace: 'synthetic-test-only' },
  ...overrides,
});
const card = (row, options = {}) => helpers.buildRevenueAiMetricCards({
  overview: { hotel_id: 80, business_date: '2026-07-28', metrics: { ota_room_revenue: row } }, ...options,
}).find((item) => item.key === 'ota_room_revenue');

test('current scope rejection overrides a verified foreign proof on the primary card badge', () => {
  const row = metric({ value: null, display: '--', status: 'unverified', reason: 'metric_scope_mismatch',
    source_channels: ['meituan'], truth: { status: 'verified', platforms: ['meituan'] } });
  const original = JSON.stringify(row);
  const result = card(row);
  assert.equal(result.display, '--');
  assert.equal(result.statusLabel, '未验证');
  assert.match(result.className, /amber/);
  assert.match(result.reasonText, /酒店、平台或业务日期不一致/);
  assert.equal(result.truth.status, 'verified', 'original source proof remains visible as evidence');
  assert.equal(JSON.stringify(row), original, 'card consumption must not mutate source evidence');
  assert.equal(result.target_page, 'online-data');
  assert.equal(result.target_tab, 'data-health');
});

test('current partial coverage cannot inherit a fully verified badge from the contributing rows', () => {
  const result = card(metric({ status: 'partial', reason: 'room_revenue_partial' }));
  assert.equal(result.statusLabel, '部分数据');
  assert.match(result.className, /amber/);
  assert.equal(result.display, '¥100.00');
});

test('matching verified current metric and a known zero keep their precise usable display', () => {
  for (const row of [metric(), metric({ value: 0, display: '¥0.00' })]) {
    const result = card(row);
    assert.equal(result.statusLabel, '已验证');
    assert.match(result.className, /emerald/);
    assert.equal(result.display, row.display);
  }
});

test('original partial and collection failure evidence remain distinguishable', () => {
  for (const [status, label] of [['partial', '部分数据'], ['collection_failed', '采集失败']]) {
    const result = card(metric({ status, truth: { status } }));
    assert.equal(result.statusLabel, label);
    assert.doesNotMatch(result.className, /emerald/);
  }
});

test('current missing stale blocked and failed states cannot acquire a verified green badge', () => {
  for (const status of ['missing', 'stale', 'blocked', 'failed', 'unauthorized', 'unknown']) {
    const result = card(metric({ status, reason: 'ota_revenue_metrics_missing' }));
    assert.equal(result.statusLabel, helpers.revenueAiStatusLabel(status), status);
    assert.doesNotMatch(result.className, /emerald/, status);
    assert.equal(result.truth.status, 'verified');
  }
});

test('a current scope mismatch remains the main reason instead of an old source explanation', () => {
  const result = card(metric({ value: null, display: '--', status: 'unverified', reason: 'metric_scope_mismatch',
    truth: { status: 'verified', platforms: ['meituan'], failure_reason: '旧回读记录曾存在来源缺口。' } }));
  assert.match(result.reasonText, /酒店、平台或业务日期不一致/);
  assert.equal(result.truth.failure_reason, '旧回读记录曾存在来源缺口。');
});

test('an overview request failure remains a failure and retains evidence without a green badge', () => {
  const result = card(metric(), { overviewError: '接口返回500' });
  assert.equal(result.statusLabel, '采集失败');
  assert.doesNotMatch(result.className, /emerald/);
  assert.equal(result.truth.status, 'verified');
});

test('legacy cards without source proof retain current metric status and the missing placeholder', () => {
  const result = card(metric({ value: null, display: '--', status: 'missing', truth: {} }));
  assert.equal(result.statusLabel, '缺失');
  assert.equal(result.display, '--');
  assert.doesNotMatch(result.className, /emerald/);
});

test('a partial foreign source does not relabel a rejected current scope as partially usable', () => {
  const result = card(metric({ value: null, display: '--', status: 'unverified', reason: 'metric_scope_mismatch',
    truth: { status: 'partial', platforms: ['meituan'] } }));
  assert.equal(result.statusLabel, '未验证');
  assert.match(result.reasonText, /酒店、平台或业务日期不一致/);
  assert.equal(result.truth.status, 'partial');
});

test('current read failure overrides partial source evidence while original failure remains distinguishable', () => {
  const failed = card(metric({ status: 'failed', reason: 'overview_request_failed', truth: { status: 'partial' } }));
  assert.equal(failed.statusLabel, '失败');
  assert.doesNotMatch(failed.className, /amber|emerald/);
  const sourceFailed = card(metric({ status: 'partial', truth: { status: 'collection_failed' } }));
  assert.equal(sourceFailed.statusLabel, '采集失败');
  const unverified = card(metric({ status: 'partial', truth: { status: 'unverified' } }));
  assert.equal(unverified.statusLabel, '未验证');
});

test('legacy unavailable metrics without a reason cannot claim the current evidence matched', () => {
  for (const status of ['failed', 'unknown']) {
    const result = card(metric({ status, reason: '' }));
    assert.doesNotMatch(result.reasonText, /已命中当前口径/);
    assert.match(result.reasonText, /当前/);
  }
});
