import assert from 'node:assert/strict';
import test from 'node:test';
import {
  percentile,
  summarizeFrontendPerformance,
} from '../../scripts/lib/frontend_performance_metrics.mjs';

test('percentile uses the nearest-rank value from finite samples', () => {
  assert.equal(percentile([50, 10, 30, 20, 40], 0.95), 50);
  assert.equal(percentile([10, Number.NaN, 20], 0.5), 10);
  assert.equal(percentile([], 0.95), null);
});

test('summarizeFrontendPerformance reports navigation, paint, resources, and long tasks', () => {
  const summary = summarizeFrontendPerformance({
    navigation: {
      requestStart: 10,
      responseStart: 42.4,
      domInteractive: 310.2,
      domComplete: 520.8,
      loadEventEnd: 560.6,
    },
    paints: [
      { name: 'first-paint', startTime: 125.1 },
      { name: 'first-contentful-paint', startTime: 140.7 },
    ],
    lcp: 420.2,
    longTasks: [{ duration: 58.2 }, { duration: 91.6 }],
    resources: [
      { initiatorType: 'script', transferSize: 1200, duration: 30 },
      { initiatorType: 'link', name: 'http://localhost/style.css', transferSize: 800, duration: 20 },
      { initiatorType: 'fetch', transferSize: 500, duration: 45 },
    ],
  });

  assert.deepEqual(summary, {
    ttfb_ms: 32,
    fcp_ms: 141,
    lcp_ms: 420,
    dom_interactive_ms: 310,
    dom_complete_ms: 521,
    full_load_ms: 561,
    total_requests: 3,
    total_transfer_bytes: 2500,
    js_transfer_bytes: 1200,
    css_transfer_bytes: 800,
    long_task_count: 2,
    long_task_total_ms: 150,
    longest_task_ms: 92,
  });
});
