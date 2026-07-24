export const DEFAULT_FRONTEND_RUNTIME_BUDGETS = Object.freeze({
  none: Object.freeze({
    min_verified_runs: 5,
    max_fcp_p95_ms: 1_800,
    max_lcp_p95_ms: 2_500,
    max_login_click_to_interactive_p95_ms: 1_800,
    target_auth_to_interactive_p95_ms: 350,
    max_auth_to_interactive_p95_ms: 500,
    max_longest_task_p95_ms: 200,
    target_api_p95_ms: 500,
    max_api_p95_ms: 750,
    max_total_requests_per_run: 30,
    max_api_samples_per_run: 3,
    max_repeated_api_requests_per_run: 0,
  }),
  'slow-4g': Object.freeze({
    min_verified_runs: 5,
    max_fcp_p95_ms: 3_000,
    max_lcp_p95_ms: 4_000,
    max_login_click_to_interactive_p95_ms: 6_000,
    target_auth_to_interactive_p95_ms: 2_500,
    max_auth_to_interactive_p95_ms: 4_500,
    max_longest_task_p95_ms: 200,
    target_api_p95_ms: 800,
    max_api_p95_ms: 1_600,
    max_total_requests_per_run: 30,
    max_api_samples_per_run: 3,
    max_repeated_api_requests_per_run: 0,
  }),
});

const finiteOrNull = (value) => {
  if (value === null || value === undefined || value === '') return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
};

const maxFinite = (values = []) => {
  const samples = values.map(finiteOrNull).filter((value) => value !== null);
  return samples.length ? Math.max(...samples) : null;
};

function repeatedApiRequestCount(run = {}) {
  return (Array.isArray(run?.api?.repeated_routes) ? run.api.repeated_routes : [])
    .reduce((total, entry) => total + Math.max(0, Number(entry?.count || 0) - 1), 0);
}

export function evaluateFrontendRuntimeBudget(report = {}, budgetOverride = null) {
  const networkProfile = String(report?.network_profile || 'none');
  const budget = budgetOverride || DEFAULT_FRONTEND_RUNTIME_BUDGETS[networkProfile];
  if (!budget) {
    return {
      network_profile: networkProfile,
      budget: null,
      observed: {},
      failures: [{
        metric: 'network_profile',
        actual: networkProfile,
        limit: Object.keys(DEFAULT_FRONTEND_RUNTIME_BUDGETS),
        reason: 'unsupported_network_profile',
      }],
      warnings: [],
    };
  }

  const aggregate = report?.aggregate || {};
  const runs = Array.isArray(report?.runs) ? report.runs : [];
  const metricP95 = (name) => finiteOrNull(aggregate?.metrics?.[name]?.p95_ms);
  const observed = {
    schema_version: finiteOrNull(report?.schema_version),
    authenticated_requested: report?.authenticated_requested === true,
    verification_status: String(report?.verification_status || ''),
    run_count: finiteOrNull(aggregate?.run_count),
    verified_run_count: finiteOrNull(aggregate?.verified_run_count),
    unverified_run_count: finiteOrNull(aggregate?.unverified_run_count),
    fcp_p95_ms: metricP95('fcp_ms'),
    lcp_p95_ms: metricP95('lcp_ms'),
    login_click_to_interactive_p95_ms: metricP95('login_click_to_interactive_ms'),
    auth_to_interactive_p95_ms: metricP95('auth_to_interactive_ms'),
    longest_task_p95_ms: metricP95('longest_task_ms'),
    api_p95_ms: finiteOrNull(aggregate?.api?.p95_ms),
    max_total_requests_per_run: maxFinite(runs.map((run) => run?.metrics?.total_requests)),
    max_api_samples_per_run: maxFinite(runs.map((run) => run?.api?.sample_count)),
    max_repeated_api_requests_per_run: maxFinite(runs.map(repeatedApiRequestCount)),
  };
  const failures = [];
  const warnings = [];
  const fail = (metric, actual, limit, reason) => failures.push({
    metric,
    actual,
    limit,
    reason,
  });
  const requireValueAtMost = (metric, limitKey) => {
    const actual = observed[metric];
    const limit = finiteOrNull(budget[limitKey]);
    if (actual === null) {
      fail(metric, null, limit, 'missing_measurement');
    } else if (limit !== null && actual > limit) {
      fail(metric, actual, limit, 'budget_exceeded');
    }
  };

  if (observed.schema_version !== 2) fail('schema_version', observed.schema_version, 2, 'unsupported_schema');
  if (!observed.authenticated_requested) {
    fail('authenticated_requested', false, true, 'authenticated_measurement_required');
  }
  if (observed.verification_status !== 'verified') {
    fail('verification_status', observed.verification_status || null, 'verified', 'unverified_report');
  }
  if (observed.unverified_run_count !== 0) {
    fail('unverified_run_count', observed.unverified_run_count, 0, 'unverified_runs_present');
  }
  const minVerifiedRuns = finiteOrNull(budget.min_verified_runs);
  if (observed.verified_run_count === null || observed.verified_run_count < minVerifiedRuns) {
    fail('verified_run_count', observed.verified_run_count, minVerifiedRuns, 'insufficient_verified_runs');
  }
  if (observed.run_count !== runs.length) {
    fail('run_count', observed.run_count, runs.length, 'aggregate_run_count_mismatch');
  }

  for (const [metric, limitKey] of [
    ['fcp_p95_ms', 'max_fcp_p95_ms'],
    ['lcp_p95_ms', 'max_lcp_p95_ms'],
    ['login_click_to_interactive_p95_ms', 'max_login_click_to_interactive_p95_ms'],
    ['auth_to_interactive_p95_ms', 'max_auth_to_interactive_p95_ms'],
    ['longest_task_p95_ms', 'max_longest_task_p95_ms'],
    ['api_p95_ms', 'max_api_p95_ms'],
    ['max_total_requests_per_run', 'max_total_requests_per_run'],
    ['max_api_samples_per_run', 'max_api_samples_per_run'],
    ['max_repeated_api_requests_per_run', 'max_repeated_api_requests_per_run'],
  ]) {
    requireValueAtMost(metric, limitKey);
  }
  for (const [metric, targetKey] of [
    ['auth_to_interactive_p95_ms', 'target_auth_to_interactive_p95_ms'],
    ['api_p95_ms', 'target_api_p95_ms'],
  ]) {
    const actual = observed[metric];
    const target = finiteOrNull(budget[targetKey]);
    if (actual !== null && target !== null && actual > target) {
      warnings.push({
        metric,
        actual,
        target,
        reason: 'improvement_target_missed',
      });
    }
  }

  return {
    network_profile: networkProfile,
    budget,
    observed,
    failures,
    warnings,
  };
}
