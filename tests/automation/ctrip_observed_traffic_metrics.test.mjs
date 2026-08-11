import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeObservedCtripTrafficMetrics,
  observedCtripTrafficMetricKeys,
} from '../../scripts/lib/ctrip_observed_traffic_metrics.mjs';

test('rank-only Ctrip responses do not acquire synthetic zero funnel metrics', () => {
  assert.deepEqual(normalizeObservedCtripTrafficMetrics({
    rank: 604,
    competitorRank: 23,
  }), {});
});

test('generic impression aliases do not impersonate Ctrip list-exposure users', () => {
  assert.deepEqual(normalizeObservedCtripTrafficMetrics({
    impressions: 321,
    exposureCount: 456,
    PV: 789,
  }), {});
});

test('explicit captured zero funnel metrics remain observable facts', () => {
  assert.deepEqual(normalizeObservedCtripTrafficMetrics({
    listExposure: 0,
    detailExposure: 0,
    flowRate: 0,
    orderFillingNum: 0,
    orderSubmitNum: 0,
  }), {
    listExposure: 0,
    detailExposure: 0,
    flowRate: 0,
    orderFillingNum: 0,
    orderSubmitNum: 0,
  });
});

test('observed authoritative and non-exposure aliases normalize without fabricating absent fields', () => {
  const normalized = normalizeObservedCtripTrafficMetrics({
    listExposure: '1,234',
    detailUv: '56',
    conversionRate: '4.54%',
  });
  assert.deepEqual(normalized, {
    listExposure: 1234,
    detailExposure: 56,
    flowRate: 4.54,
  });
  assert.deepEqual(observedCtripTrafficMetricKeys(normalized), [
    'detail_exposure',
    'flow_rate',
    'list_exposure',
  ]);
});

test('presence marker is empty for rank-only rows and complete for explicit zero funnel facts', () => {
  assert.deepEqual(observedCtripTrafficMetricKeys(
    normalizeObservedCtripTrafficMetrics({ rank: 604 })
  ), []);
  assert.deepEqual(observedCtripTrafficMetricKeys(
    normalizeObservedCtripTrafficMetrics({
      listExposure: 0,
      detailExposure: 0,
      flowRate: 0,
      orderFillingNum: 0,
      orderSubmitNum: 0,
    })
  ), [
    'detail_exposure',
    'flow_rate',
    'list_exposure',
    'order_filling_num',
    'order_submit_num',
  ]);
});
