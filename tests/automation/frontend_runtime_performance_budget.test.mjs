import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  DEFAULT_FRONTEND_RUNTIME_BUDGETS,
  evaluateFrontendRuntimeBudget,
} from '../../scripts/lib/frontend_runtime_performance_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const workflow = readFileSync(path.join(repoRoot, '.github', 'workflows', 'php.yml'), 'utf8');
const measurement = readFileSync(path.join(repoRoot, 'scripts', 'measure_frontend_performance.mjs'), 'utf8');
const ciMeasurement = readFileSync(path.join(repoRoot, 'scripts', 'measure_frontend_performance_ci.mjs'), 'utf8');
const packageJson = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));

const metric = (p95) => ({ sample_count: 5, p50_ms: p95, p95_ms: p95, max_ms: p95 });

function passingReport(networkProfile = 'none') {
  const runs = Array.from({ length: 5 }, (_, index) => ({
    run: index + 1,
    verification_status: 'verified',
    attempt_count: 1,
    metrics: { total_requests: 29 },
    api: { sample_count: 3, repeated_routes: [] },
  }));
  return {
    schema_version: 2,
    authenticated_requested: true,
    verification_status: 'verified',
    network_profile: networkProfile,
    aggregate: {
      run_count: 5,
      verified_run_count: 5,
      unverified_run_count: 0,
      metrics: {
        fcp_ms: metric(700),
        lcp_ms: metric(900),
        login_click_to_interactive_ms: metric(1_400),
        auth_to_interactive_ms: metric(300),
        longest_task_ms: metric(150),
      },
      api: { p95_ms: 400 },
    },
    runs,
  };
}

test('verified five-run authenticated report passes the default local runtime budget', () => {
  const assessment = evaluateFrontendRuntimeBudget(passingReport());
  assert.equal(assessment.network_profile, 'none');
  assert.deepEqual(assessment.budget, DEFAULT_FRONTEND_RUNTIME_BUDGETS.none);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, []);
  assert.equal(assessment.observed.max_total_requests_per_run, 29);
});

test('CI enforces the static budget and measures the authenticated app before the runtime budget', () => {
  assert.match(workflow, /run: npm run verify:performance-budget/);
  assert.match(workflow, /SUXI_PHP: php/);
  assert.doesNotMatch(workflow, /PHP_CLI_SERVER_WORKERS/);
  assert.match(workflow, /SUXI_E2E_DB_NAME: hotelx_ci_test/);
  assert.match(workflow, /npm run measure:performance:ci/);
  assert.match(workflow, /npm run verify:performance-runtime-budget/);
  assert(
    workflow.indexOf('npm run measure:performance:ci')
      < workflow.indexOf('npm run verify:performance-runtime-budget'),
  );
  assert.equal(
    packageJson.scripts['measure:performance:ci'],
    'node scripts/measure_frontend_performance_ci.mjs',
  );
  assert.match(ciMeasurement, /const isolationRunCount = 5/);
  assert.match(ciMeasurement, /--performance-iterations=1/);
  assert.match(ciMeasurement, /--performance-enforce-budget=0/);
  assert.match(ciMeasurement, /fresh_server_seed_and_browser_per_run/);
  assert.match(ciMeasurement, /summarizeFrontendPerformanceRuns\(runs\)/);
  assert.match(ciMeasurement, /evaluateFrontendRuntimeBudget\(result\)/);
  assert.match(measurement, /const maxMeasurementAttempts = 2/);
  assert.match(measurement, /browser = await chromium\.launch/);
  assert.match(measurement, /attempt_failures: attemptFailures/);
  assert.match(measurement, /retryableNavigationTimeout[\s\S]*startsWith\('page\.goto:'\)/);
  assert.match(measurement, /await measureRunWithRetry\(runIndex\)/);
});

test('runtime budget fails closed on duplicate startup APIs and request growth', () => {
  const report = passingReport();
  report.runs[0].metrics.total_requests = 31;
  report.runs[0].api.sample_count = 5;
  report.runs[0].api.repeated_routes = [
    { route: '/api/auth/info', count: 2 },
    { route: '/api/notifications?page&page_size', count: 2 },
  ];
  const assessment = evaluateFrontendRuntimeBudget(report);
  const metrics = assessment.failures.map((failure) => failure.metric);
  assert(metrics.includes('max_total_requests_per_run'));
  assert(metrics.includes('max_api_samples_per_run'));
  assert(metrics.includes('max_repeated_api_requests_per_run'));
});

test('runtime budget fails closed on missing or unverified measurements', () => {
  const report = passingReport();
  report.verification_status = 'unverified';
  report.aggregate.verified_run_count = 4;
  report.aggregate.unverified_run_count = 1;
  report.aggregate.metrics.lcp_ms = { sample_count: 0, p95_ms: null };
  const assessment = evaluateFrontendRuntimeBudget(report);
  const metrics = assessment.failures.map((failure) => failure.metric);
  assert(metrics.includes('verification_status'));
  assert(metrics.includes('unverified_run_count'));
  assert(metrics.includes('verified_run_count'));
  assert(metrics.includes('lcp_p95_ms'));
});

test('unthrottled auth handoff keeps the measured hard ceiling separate from the improvement target', () => {
  const report = passingReport();
  report.aggregate.metrics.auth_to_interactive_ms = metric(900);
  const assessment = evaluateFrontendRuntimeBudget(report);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, [{
    metric: 'auth_to_interactive_p95_ms',
    actual: 900,
    target: 350,
    reason: 'improvement_target_missed',
  }]);

  report.aggregate.metrics.auth_to_interactive_ms = metric(1_001);
  assert(
    evaluateFrontendRuntimeBudget(report).failures
      .some((failure) => failure.metric === 'auth_to_interactive_p95_ms'),
  );
});

test('a bounded measurement retry is retained as a warning instead of disappearing', () => {
  const report = passingReport();
  report.runs[2].attempt_count = 2;
  const assessment = evaluateFrontendRuntimeBudget(report);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, [{
    metric: 'measurement_retry_count',
    actual: 1,
    target: 0,
    reason: 'transient_measurement_retry',
  }]);
});

test('slow-4g reports use the explicit throttled-network budget', () => {
  const assessment = evaluateFrontendRuntimeBudget(passingReport('slow-4g'));
  assert.deepEqual(assessment.budget, DEFAULT_FRONTEND_RUNTIME_BUDGETS['slow-4g']);
  assert.deepEqual(assessment.failures, []);
});

test('slow-4g keeps the measured hard ceiling separate from the improvement target', () => {
  const report = passingReport('slow-4g');
  report.aggregate.metrics.auth_to_interactive_ms = metric(4_265);
  const assessment = evaluateFrontendRuntimeBudget(report);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, [{
    metric: 'auth_to_interactive_p95_ms',
    actual: 4_265,
    target: 2_500,
    reason: 'improvement_target_missed',
  }]);
});

test('local API P95 can warn above target without weakening the regression ceiling', () => {
  const report = passingReport();
  report.aggregate.api.p95_ms = 599;
  const assessment = evaluateFrontendRuntimeBudget(report);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, [{
    metric: 'api_p95_ms',
    actual: 599,
    target: 500,
    reason: 'improvement_target_missed',
  }]);

  report.aggregate.api.p95_ms = 751;
  assert(
    evaluateFrontendRuntimeBudget(report).failures
      .some((failure) => failure.metric === 'api_p95_ms'),
  );
});
