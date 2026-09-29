import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(
  process.env.SUXIOS_CTRIP_SEARCH_REFERENCE_SOURCE
    || new URL('../../public/ctrip-search-opportunity-static.js', import.meta.url),
  'utf8',
);

function buildView(payload) {
  const context = { window: {}, console };
  vm.runInNewContext(source, context, { filename: 'ctrip-search-opportunity-static.js' });
  return context.window.SUXI_CTRIP_SEARCH_OPPORTUNITY_STATIC.buildView(payload);
}

const plain = value => JSON.parse(JSON.stringify(value));
const current = (pv, uv, conversionRate, extra = {}) => ({
  pv, uv, conversion_rate: conversionRate, order_count: null,
  metric_status: 'captured', ...extra,
});
const historical = (pv, uv, conversionRate, referenceDate = '2026-07-11', extra = {}) => ({
  ...current(pv, uv, conversionRate),
  metric_status: 'historical_reference', reference_capture_date: referenceDate, ...extra,
});
const payloadFor = dates => ({
  status: 'partial', source_scope: 'ctrip_ota_channel', capture_date: '2026-07-12',
  captured_at: '2026-07-12 10:00:00', reference_capture_date: '2026-07-11',
  reference_covered_gap_count: 2, order_data_status: 'field_missing', dates,
});

function assertNoCurrentMetrics(scope) {
  for (const field of ['pv', 'uv', 'conversion_rate', 'order_count', 'estimated_order_count', 'browse_intensity']) {
    assert.equal(scope[field], null, `${field} must not promote a historical observation to a current fact`);
  }
}

test('a previous capture yesterday series remains dated reference evidence, not current yesterday facts', () => {
  // Mirrors the supported PHP fallback: a July 12 cumulative capture can carry
  // the July 11 capture's yesterday series. Its source date must survive display.
  const payload = payloadFor([{
    target_date: '2026-07-13',
    cumulative: { self: current(80, 60, 2), competitor_avg: current(100, 70, 3) },
    yesterday: { self: historical(3, 3, 1), competitor_avg: historical(7, 5, 2) },
  }]);
  const inputBefore = JSON.stringify(payload);
  const view = buildView(payload);
  const row = view.rows[0];

  assertNoCurrentMetrics(row.windows.yesterday.self);
  assertNoCurrentMetrics(row.windows.yesterday.competitor_avg);
  assert.equal(row.windows.yesterday.self.metric_status, 'historical_reference');
  assert.equal(row.windows.yesterday.self.reference_capture_date, '2026-07-11');
  assert.equal(row.windows.yesterday.self.reference_values.pv, 3);
  assert.equal(row.windows.yesterday.self.reference_values.uv, 3);
  assert.equal(row.windows.yesterday.self.reference_values.conversion_rate, 1);
  assert.equal(row.windows.yesterday.self.reference_values.order_count, null);
  assert.equal(row.windows.yesterday.self.reference_values.estimated_order_count, 0.03);
  assert.equal(row.windows.yesterday.self.reference_values.browse_intensity, 1);
  assert.equal(row.windows.yesterday.competitor_avg.reference_values.pv, 7);
  assert.equal(row.windows.yesterday.uv_gap_rate, null);
  assert.equal(row.windows.yesterday.conversion_gap, null);
  assert.equal(row.windows.yesterday.opportunity.key, 'insufficient');
  assert.equal(row.yesterday_uv_contribution, null);
  assert.equal(view.summary.windows.yesterday.self_uv, null);
  assert.equal(view.summary.windows.yesterday.competitor_uv, null);
  assert.equal(view.summary.windows.yesterday.self_days, 0);
  assert.equal(view.summary.windows.yesterday.competitor_days, 0);
  assert.equal(view.summary.yesterday_opportunity_days, 0);
  assert.equal(view.window_ranges.yesterday.day_count, 0);
  assert.equal(view.summary.horizons.seven_day.self_uv, 60);
  assert.equal(JSON.stringify(payload), inputBefore, 'projection must not rewrite the persisted/API payload');
});

test('a current true zero is retained when only the competing side is historical', () => {
  const view = buildView(payloadFor([{
    target_date: '2026-07-13',
    cumulative: { self: current(80, 60, 2), competitor_avg: current(100, 70, 3) },
    yesterday: {
      self: current(0, 0, 0, { order_count: 0 }),
      competitor_avg: historical(70, 50, 10, '2026-07-10'),
    },
  }]));
  const yesterday = view.rows[0].windows.yesterday;

  assert.equal(yesterday.self.pv, 0);
  assert.equal(yesterday.self.uv, 0);
  assert.equal(yesterday.self.conversion_rate, 0);
  assert.equal(yesterday.self.order_count, 0);
  assert.equal(yesterday.self.estimated_order_count, 0);
  assertNoCurrentMetrics(yesterday.competitor_avg);
  assert.equal(yesterday.competitor_avg.reference_capture_date, '2026-07-10');
  assert.equal(yesterday.competitor_avg.reference_values.uv, 50);
  assert.equal(yesterday.pv_gap_rate, null);
  assert.equal(yesterday.uv_gap_rate, null);
  assert.equal(yesterday.conversion_gap, null);
  assert.equal(yesterday.chase_space, null);
  assert.equal(yesterday.opportunity.key, 'insufficient');
  assert.equal(view.summary.windows.yesterday.self_uv, 0);
  assert.equal(view.summary.windows.yesterday.self_days, 1);
  assert.equal(view.summary.windows.yesterday.competitor_days, 0);
  assert.equal(view.summary.windows.yesterday.estimated_order_gap_rate, null);
  assert.equal(view.window_ranges.yesterday.day_count, 1);
});

test('historical cumulative scopes cannot inflate horizon totals, coverage, or chart maxima', () => {
  const view = buildView(payloadFor([
    {
      target_date: '2026-07-13',
      cumulative: { self: historical(900, 600, 20), competitor_avg: historical(1200, 800, 30) },
    },
    {
      target_date: '2026-07-14',
      cumulative: { self: current(10, 8, 5), competitor_avg: current(20, 10, 4) },
    },
  ]));
  const summary = view.summary.horizons.three_day;

  assertNoCurrentMetrics(view.rows[0].windows.cumulative.self);
  assert.equal(view.rows[0].windows.cumulative.self.reference_values.uv, 600);
  assert.equal(summary.self_pv, 10);
  assert.equal(summary.competitor_pv, 20);
  assert.equal(summary.self_uv, 8);
  assert.equal(summary.competitor_uv, 10);
  assert.equal(summary.uv_gap_rate, -20);
  assert.equal(summary.self_conversion, 5);
  assert.equal(summary.self_estimated_orders, 0.4);
  assert.equal(summary.self_days, 1);
  assert.equal(summary.competitor_days, 1);
  assert.equal(view.maxima.cumulative.pv, 20);
  assert.equal(view.window_ranges.cumulative.start_date, '2026-07-14');
  assert.equal(view.window_ranges.cumulative.day_count, 1);
});

test('historical zero and missing values remain distinguishable even when its source date is absent', () => {
  const view = buildView(payloadFor([{
    target_date: '2026-07-13',
    yesterday: {
      self: historical(0, 0, 0, '', { order_count: 0 }),
      competitor_avg: historical(null, null, null, '2026-07-10'),
    },
  }]));
  const yesterday = view.rows[0].windows.yesterday;

  assertNoCurrentMetrics(yesterday.self);
  assertNoCurrentMetrics(yesterday.competitor_avg);
  assert.equal(yesterday.self.reference_capture_date, '');
  assert.equal(yesterday.self.reference_values.pv, 0);
  assert.equal(yesterday.self.reference_values.uv, 0);
  assert.equal(yesterday.self.reference_values.conversion_rate, 0);
  assert.equal(yesterday.self.reference_values.order_count, 0);
  assert.equal(yesterday.self.reference_values.estimated_order_count, 0);
  assert.equal(yesterday.self.reference_values.browse_intensity, null);
  assert.equal(yesterday.competitor_avg.reference_values.pv, null);
  assert.equal(yesterday.competitor_avg.reference_values.uv, null);
  assert.equal(yesterday.competitor_avg.reference_values.conversion_rate, null);
  assert.equal(view.summary.windows.yesterday.self_pv, null);
  assert.equal(view.summary.windows.yesterday.self_days, 0);
});

test('current captured and partial observations keep the existing four-scope zero and comparison contract', () => {
  const view = buildView(payloadFor([{
    target_date: '2026-07-13',
    cumulative: {
      self: current(0, 0, 0, { order_count: 0, metric_status: 'partial' }),
      competitor_avg: current(20, 10, 4),
    },
    yesterday: {
      self: current(3, 2, 0, { metric_status: 'partial' }),
      competitor_avg: current(6, 4, 2),
    },
  }]));
  const row = view.rows[0];

  assert.equal(row.windows.cumulative.self.pv, 0);
  assert.equal(row.windows.cumulative.self.estimated_order_count, 0);
  assert.equal(row.windows.cumulative.self.order_count, 0);
  assert.equal(row.windows.cumulative.uv_gap_rate, -100);
  assert.equal(row.windows.cumulative.conversion_gap, -4);
  assert.equal(row.windows.yesterday.self.uv, 2);
  assert.equal(row.windows.yesterday.self.order_count, null);
  assert.equal(row.windows.yesterday.uv_gap_rate, -50);
  assert.equal(row.windows.yesterday.conversion_gap, -2);
  assert.equal(view.summary.windows.yesterday.self_uv, 2);
  assert.equal(view.summary.windows.yesterday.self_days, 1);
  assert.equal(view.summary.horizons.seven_day.self_uv, 0);
  assert.equal(view.summary.horizons.seven_day.self_days, 1);
});

test('cumulative delta estimates preserve their current status and missing conversion without becoming history', () => {
  const view = buildView(payloadFor([{
    target_date: '2026-07-13',
    cumulative: { self: current(249, 144, 2), competitor_avg: current(162, 107, 2) },
    yesterday: {
      self: current(5, 4, null, {
        metric_status: 'derived_from_cumulative_delta', reference_capture_date: '2026-07-11',
      }),
      competitor_avg: current(2, 2, null, {
        metric_status: 'derived_from_cumulative_delta', reference_capture_date: '2026-07-11',
      }),
    },
  }]));
  const yesterday = view.rows[0].windows.yesterday;

  assert.equal(yesterday.self.metric_status, 'derived_from_cumulative_delta');
  assert.equal(yesterday.self.reference_capture_date, '2026-07-11');
  assert.equal(yesterday.self.pv, 5);
  assert.equal(yesterday.self.uv, 4);
  assert.equal(yesterday.self.conversion_rate, null);
  assert.equal(yesterday.self.estimated_order_count, null);
  assert.equal(yesterday.self.order_count, null);
  assert.equal(yesterday.uv_gap_rate, 100);
  assert.equal(yesterday.conversion_gap, null);
  assert.equal(yesterday.opportunity.key, 'insufficient');
  assert.equal(view.summary.windows.yesterday.self_uv, 4);
  assert.equal(view.summary.windows.yesterday.self_days, 1);
});

test('legacy current scopes remain usable and the separate self_reference cannot alter comparisons', () => {
  const base = {
    target_date: '2026-07-13',
    cumulative: {
      self: { pv: '10', uv: '8', conversion_rate: '5', order_count: null },
      competitor_avg: { pv: '20', uv: '10', conversion_rate: '4', order_count: null },
    },
  };
  const withoutReference = buildView(payloadFor([base]));
  const withReference = buildView(payloadFor([{
    ...base,
    cumulative: { ...base.cumulative, self_reference: historical(9000, 8000, 50) },
  }]));

  assert.equal(withReference.rows[0].windows.cumulative.self.pv, 10);
  assert.equal(withReference.rows[0].windows.cumulative.self.uv, 8);
  assert.equal(withReference.rows[0].windows.cumulative.uv_gap_rate, -20);
  assert.deepEqual(plain(withReference.summary), plain(withoutReference.summary));
  assert.deepEqual(plain(withReference.maxima), plain(withoutReference.maxima));
  assert.deepEqual(plain(withReference.window_ranges), plain(withoutReference.window_ranges));
});

test('the explicit reference table retains source dates and removes only the duplicate self reference', () => {
  const oldSelf = historical(3, 3, 1, '2026-07-11');
  const view = buildView(payloadFor([{
    target_date: '2026-07-13',
    cumulative: {
      self: oldSelf,
      competitor_avg: current(20, 10, 4),
      self_reference: { ...oldSelf },
    },
    yesterday: {
      self: historical(3, 3, 1, '2026-07-10'),
      competitor_avg: historical(7, 5, 2, '2026-07-09'),
    },
  }]));

  assert.equal(view.reference_rows.length, 3);
  const cumulative = view.reference_rows.filter(row => row.window === 'cumulative');
  assert.equal(cumulative.length, 1, 'the duplicated self_reference is not a second observation');
  assert.equal(cumulative[0].target_date, '2026-07-13');
  assert.equal(cumulative[0].scope, 'self');
  assert.equal(cumulative[0].reference_capture_date, '2026-07-11');
  assert.equal(cumulative[0].values.pv, 3);
  assert.equal(cumulative[0].values.estimated_order_count, 0.03);
  const yesterdaySelf = view.reference_rows.find(row => row.window === 'yesterday' && row.scope === 'self');
  const yesterdayPeer = view.reference_rows.find(row => row.window === 'yesterday' && row.scope === 'competitor_avg');
  assert.equal(yesterdaySelf.reference_capture_date, '2026-07-10');
  assert.equal(yesterdaySelf.values.pv, 3, 'equal values on a different source date remain separate evidence');
  assert.equal(yesterdayPeer.reference_capture_date, '2026-07-09');
  assert.equal(yesterdayPeer.values.uv, 5);
});
