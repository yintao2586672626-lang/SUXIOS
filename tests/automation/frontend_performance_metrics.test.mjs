import assert from 'node:assert/strict';
import test from 'node:test';
import {
  percentile,
  resolveFrontendNetworkProfile,
  summarizeApiPerformance,
  summarizeFrontendPerformance,
  summarizeFrontendPerformanceRuns,
} from '../../scripts/lib/frontend_performance_metrics.mjs';

test('percentile uses the nearest-rank value from finite samples', () => {
  assert.equal(percentile([50, 10, 30, 20, 40], 0.95), 50);
  assert.equal(percentile([10, Number.NaN, 20], 0.5), 10);
  assert.equal(percentile([null, undefined, '', 20], 0.5), 20);
  assert.equal(percentile([], 0.95), null);
});

test('slow-4g profile is explicit and unknown profiles fail closed', () => {
  assert.deepEqual(resolveFrontendNetworkProfile(), { name: 'none', conditions: null });
  assert.deepEqual(resolveFrontendNetworkProfile('slow-4g'), {
    name: 'slow-4g',
    conditions: {
      offline: false,
      latency: 150,
      downloadThroughput: 200_000,
      uploadThroughput: 93_750,
    },
  });
  assert.throws(() => resolveFrontendNetworkProfile('mystery-network'), /Unknown frontend network profile/);
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
    loginHandoff: { status: 'interactive', auth_to_interactive_ms: 612.7 },
    longTasks: [{ duration: 58.2 }, { duration: 91.6 }],
    resources: [
      { initiatorType: 'script', transferSize: 1200, duration: 30 },
      { initiatorType: 'link', name: 'http://localhost/style.css', transferSize: 800, duration: 20 },
      { initiatorType: 'link', name: 'http://localhost/app-main.min.js?v=1', transferSize: 2000, duration: 25 },
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
    total_requests: 4,
    total_transfer_bytes: 4500,
    js_transfer_bytes: 3200,
    css_transfer_bytes: 800,
    long_task_count: 2,
    long_task_total_ms: 150,
    longest_task_ms: 92,
    login_handoff_status: 'interactive',
    auth_to_interactive_ms: 613,
  });
});

test('summarizeApiPerformance keeps only post-boundary API timings and hides query values', () => {
  const summary = summarizeApiPerformance([
    {
      name: 'http://127.0.0.1:8080/api/health',
      initiatorType: 'fetch',
      startTime: 25,
      duration: 80,
      transferSize: 300,
      responseStatus: 200,
    },
    {
      name: 'http://127.0.0.1:8080/api/revenue/analysis?hotel_id=80&date=2026-07-24',
      initiatorType: 'fetch',
      startTime: 150,
      duration: 210,
      transferSize: 1200,
      responseStatus: 200,
    },
    {
      name: 'http://127.0.0.1:8080/api/revenue/analysis?date=2026-07-23&hotel_id=80',
      initiatorType: 'xmlhttprequest',
      startTime: 190,
      duration: 410,
      transferSize: 1300,
      responseStatus: 200,
    },
    {
      name: 'http://127.0.0.1:8080/style.css',
      initiatorType: 'link',
      startTime: 200,
      duration: 15,
      transferSize: 900,
    },
  ], { min_start_time_ms: 100 });

  assert.deepEqual({
    sample_count: summary.sample_count,
    p50_ms: summary.p50_ms,
    p95_ms: summary.p95_ms,
    max_ms: summary.max_ms,
    repeated_routes: summary.repeated_routes,
  }, {
    sample_count: 2,
    p50_ms: 210,
    p95_ms: 410,
    max_ms: 410,
    repeated_routes: [{
      route: '/api/revenue/analysis?date&hotel_id',
      count: 2,
    }],
  });
  assert.equal(summary.samples[0].route, '/api/revenue/analysis?date&hotel_id');
  assert.doesNotMatch(JSON.stringify(summary), /2026-07-24|hotel_id=80/);
});

test('summarizeFrontendPerformanceRuns reports verified counts and P95 distributions', () => {
  const aggregate = summarizeFrontendPerformanceRuns([
    {
      verification_status: 'verified',
      metrics: {
        ttfb_ms: 10,
        fcp_ms: 100,
        lcp_ms: 200,
        login_click_to_interactive_ms: 450,
        auth_to_interactive_ms: 300,
        long_task_total_ms: 60,
        longest_task_ms: 60,
      },
      api: {
        samples: [{ route: '/api/auth/login', duration_ms: 120, transfer_bytes: 500, status: 200 }],
      },
    },
    {
      verification_status: 'unverified',
      metrics: {
        ttfb_ms: 20,
        fcp_ms: 150,
        lcp_ms: 250,
        login_click_to_interactive_ms: null,
        auth_to_interactive_ms: null,
        long_task_total_ms: 90,
        longest_task_ms: 90,
      },
      api: {
        samples: [{ route: '/api/auth/login', duration_ms: 320, transfer_bytes: 500, status: 401 }],
      },
    },
  ]);

  assert.equal(aggregate.run_count, 2);
  assert.equal(aggregate.verified_run_count, 1);
  assert.equal(aggregate.unverified_run_count, 1);
  assert.equal(aggregate.metrics.fcp_ms.p95_ms, 150);
  assert.equal(aggregate.metrics.login_click_to_interactive_ms.p95_ms, 450);
  assert.equal(aggregate.metrics.login_click_to_interactive_ms.sample_count, 1);
  assert.equal(aggregate.metrics.auth_to_interactive_ms.sample_count, 1);
  assert.equal(aggregate.api.p95_ms, 320);
});
