import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  DEFAULT_FRONTEND_RUNTIME_BUDGETS,
  evaluateFrontendRuntimeBudget,
} from '../../scripts/lib/frontend_runtime_performance_budget.mjs';
import {
  FRONTEND_PERCENTILE_METHOD,
  summarizeFrontendPerformanceRuns,
} from '../../scripts/lib/frontend_performance_metrics.mjs';
import { evaluateFrontendPerformanceEvidence } from '../../scripts/lib/frontend_performance_evidence_identity.mjs';
import { extractGithubActionsJob } from './helpers/github_actions_workflow.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const workflow = readFileSync(path.join(repoRoot, '.github', 'workflows', 'php.yml'), 'utf8');
const measurement = readFileSync(path.join(repoRoot, 'scripts', 'measure_frontend_performance.mjs'), 'utf8');
const ciMeasurement = readFileSync(path.join(repoRoot, 'scripts', 'measure_frontend_performance_ci.mjs'), 'utf8');
const packageJson = JSON.parse(readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));

const metric = (p95) => ({ sample_count: 5, p50_ms: p95, p95_ms: p95, max_ms: p95 });

function passingReport(networkProfile = 'none') {
  const runs = Array.from({ length: 5 }, (_, index) => ({
    run: index + 1,
    authenticated: true,
    verification_status: 'verified',
    attempt_count: 1,
    metrics: { total_requests: 29, longest_task_ms: 150 },
    api: { sample_count: 4, repeated_routes: [] },
  }));
  return {
    schema_version: 2,
    percentile_method: FRONTEND_PERCENTILE_METHOD,
    authenticated_requested: true,
    authenticated: true,
    artifact_identity_stable: true,
    verification_status: 'verified',
    network_profile: networkProfile,
    aggregate: {
      percentile_method: FRONTEND_PERCENTILE_METHOD,
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
  assert.equal(assessment.observed.max_api_samples_per_run, 4);
  assert.equal(assessment.observed.max_repeated_api_requests_per_run, 0);
});

test('CI isolates static contracts from runtime performance and preserves authenticated evidence', () => {
  const contractsJob = extractGithubActionsJob(workflow, 'contracts');
  const performanceJob = extractGithubActionsJob(workflow, 'frontend_performance');
  const aggregateJob = extractGithubActionsJob(workflow, 'verify');
  const projectGuardStep = contractsJob.indexOf('- name: Run project guards');
  const structuralStep = contractsJob.indexOf(
    '- name: Run structural contract checks (not release approval)',
  );
  const staticPerformanceStep = contractsJob.indexOf(
    '- name: Verify frontend static performance budget',
  );
  const runtimePerformanceStep = performanceJob.indexOf(
    '- name: Measure and verify authenticated frontend runtime budget',
  );
  const preserveEvidenceStep = performanceJob.indexOf(
    '- name: Preserve authenticated frontend performance evidence',
  );

  assert.ok(projectGuardStep >= 0, 'CI must run project guards');
  assert.ok(
    projectGuardStep < structuralStep,
    'project guards must run before structural checks',
  );
  assert.ok(
    structuralStep < staticPerformanceStep,
    'critical regressions must finish before performance gates can fail',
  );
  assert.ok(
    runtimePerformanceStep < preserveEvidenceStep,
    'runtime measurement evidence must be preserved after the gate',
  );

  assert.match(contractsJob, /run: npm run verify:performance-budget/);
  assert.match(performanceJob, /SUXI_PHP: php/);
  assert.doesNotMatch(workflow, /PHP_CLI_SERVER_WORKERS/);
  assert.match(performanceJob, /SUXI_E2E_DB_NAME: hotelx_ci_test/);
  assert.match(performanceJob, /npm run measure:performance:ci/);
  assert.match(performanceJob, /npm run verify:performance-runtime-budget/);
  assert(
    performanceJob.indexOf('npm run measure:performance:ci')
      < performanceJob.indexOf('npm run verify:performance-runtime-budget'),
  );
  assert.doesNotMatch(performanceJob, /^\s+needs:/m);
  assert.match(aggregateJob, /needs:[\s\S]*-\s+contracts[\s\S]*-\s+frontend_performance/);
  assert.match(aggregateJob, /if:\s+\$\{\{\s*always\(\)\s*\}\}/);
  assert.equal(
    packageJson.scripts['measure:performance:ci'],
    'node scripts/measure_frontend_performance_ci.mjs',
  );
  assert.match(ciMeasurement, /const isolationRunCount = 5/);
  assert.match(ciMeasurement, /const isolationRunTimeoutMs = 120_000/);
  assert.match(ciMeasurement, /const isolationRunShutdownGraceMs = 45_000/);
  assert.match(ciMeasurement, /runIsolatedPerformanceChild/);
  assert.match(ciMeasurement, /requestIsolatedPerformanceShutdown/);
  assert.match(ciMeasurement, /forceStopChildTree\(child\)/);
  assert.match(ciMeasurement, /detached: process\.platform !== 'win32'/);
  assert.match(ciMeasurement, /stdio: \['ignore', 'pipe', 'pipe', 'ipc'\]/);
  assert.match(ciMeasurement, /suxi-isolated-shutdown-request/);
  assert.match(ciMeasurement, /suxi-isolated-shutdown-complete/);
  assert.match(ciMeasurement, /suxi-isolated-shutdown-release/);
  assert.match(ciMeasurement, /message\.cleanup_complete !== true/);
  assert.match(ciMeasurement, /cleanupAcknowledged = true/);
  assert.match(ciMeasurement, /pendingCloseResult/);
  assert.match(ciMeasurement, /process\.kill\(-child\.pid, signal\)/);
  assert.match(ciMeasurement, /activeIsolationShutdown/);
  assert.match(ciMeasurement, /process\.on\('SIGINT'/);
  assert.match(ciMeasurement, /process\.on\('SIGTERM'/);
  assert.match(ciMeasurement, /parentShutdownSignal === 'SIGINT' \? 130 : 143/);
  assert.match(ciMeasurement, /code: 'ETIMEDOUT'/);
  assert.doesNotMatch(ciMeasurement, /killSignal: 'SIGKILL'/);
  assert.match(ciMeasurement, /isolation_run=\$\{isolationRun\} phase=start/);
  assert.match(ciMeasurement, /--performance-iterations=1/);
  assert.match(ciMeasurement, /--performance-enforce-budget=0/);
  assert.match(ciMeasurement, /fresh_server_seed_and_browser_per_run/);
  assert.match(ciMeasurement, /summarizeFrontendPerformanceRuns\(runs\)/);
  assert.match(ciMeasurement, /evaluateFrontendRuntimeBudget\(result\)/);
  assert.doesNotMatch(
    ciMeasurement,
    /runtime_budget\.failures\.length[\s\S]*process\.exitCode\s*=\s*3/,
    'measurement must preserve the report so the independent verifier is the budget gate',
  );
  assert.match(ciMeasurement, /expectedArtifactDigest/);
  assert.match(measurement, /const maxMeasurementAttempts = 2/);
  assert.match(measurement, /browser = await chromium\.launch/);
  assert.match(measurement, /capturePendingLcp/);
  assert.match(measurement, /lcp_missing_before_input/);
  assert.match(measurement, /attempt_failures: attemptFailures/);
  assert.match(measurement, /retryableNavigationTimeout[\s\S]*startsWith\('page\.goto:'\)/);
  assert.match(measurement, /await measureRunWithRetry\(runIndex\)/);
  assert.match(measurement, /page\.on\('pageerror'/);
  assert.match(measurement, /text\.startsWith\('\[SUXIOS\]'\)/);
  assert.match(measurement, /startup_diagnostics: startupDiagnostics/);
  assert.match(measurement, /long_tasks: snapshot\.longTasks\.slice\(0, 100\)/);
  assert.match(measurement, /same_origin_asset_timings:/);
  assert.match(measurement, /browser_launch_ms: browserLaunchMs/);
  assert.match(measurement, /\|\| startupDiagnostics\.length > 0\s*\? 'unverified'/);
  assert.match(measurement, /artifactIdentityStarted = captureFrontendPerformanceIdentity\(\)/);
  assert.match(measurement, /artifact_identity_stable: artifactIdentityStarted\.digest === artifactIdentityCompleted\.digest/);

  const evidenceStep = performanceJob.slice(preserveEvidenceStep);
  assert.match(evidenceStep, /if:\s+always\(\)/);
  assert.match(evidenceStep, /uses:\s+actions\/upload-artifact@v4/);
  assert.match(evidenceStep, /output\/performance\/isolated-authenticated-baseline\.json/);
  assert.match(evidenceStep, /output\/performance\/ci-isolated-authenticated-\*\.json/);
  assert.match(evidenceStep, /if-no-files-found:\s+warn/);
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

test('runtime budget fails closed when a verified run has no LCP sample', () => {
  const report = passingReport();
  report.aggregate.metrics.lcp_ms.sample_count = 4;

  const failure = evaluateFrontendRuntimeBudget(report).failures.find(
    (entry) => entry.metric === 'lcp_sample_count'
  );
  assert.deepEqual(failure, {
    metric: 'lcp_sample_count',
    actual: 4,
    limit: 5,
    reason: 'incomplete_measurement_samples',
  });
});

test('runtime budget requires top-level and per-run authenticated evidence', () => {
  const report = passingReport();
  report.authenticated = false;
  report.runs[2].authenticated = false;
  report.runs[3].verification_status = 'unverified';
  report.artifact_identity_stable = false;
  const metrics = evaluateFrontendRuntimeBudget(report).failures.map((failure) => failure.metric);
  assert(metrics.includes('authenticated'));
  assert(metrics.includes('authenticated_run_count'));
  assert(metrics.includes('verified_status_run_count'));
  assert(metrics.includes('artifact_identity_stable'));
});

test('runtime evidence rejects stale or drifted artifact identities', () => {
  const digest = 'a'.repeat(64);
  const report = {
    completed_at: '2026-08-05T00:00:00.000Z',
    artifact_identity: { digest },
    artifact_identity_completed_digest: digest,
    artifact_identity_stable: true,
  };
  const fresh = evaluateFrontendPerformanceEvidence(report, {
    currentIdentity: { digest },
    now: Date.parse('2026-08-05T01:00:00.000Z'),
    maxAgeMinutes: 120,
  });
  assert.deepEqual(fresh.failures, []);

  const staleReasons = evaluateFrontendPerformanceEvidence(report, {
    currentIdentity: { digest: 'b'.repeat(64) },
    now: Date.parse('2026-08-05T04:00:00.000Z'),
    maxAgeMinutes: 120,
  }).failures.map((failure) => failure.reason);
  assert(staleReasons.includes('artifact_identity_stale'));
  assert(staleReasons.includes('performance_report_stale'));
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

test('login click-to-interactive tolerates bounded cold-runner jitter without hiding a real stall', () => {
  const report = passingReport();
  report.aggregate.metrics.login_click_to_interactive_ms = metric(1_900);
  assert.deepEqual(evaluateFrontendRuntimeBudget(report).failures, []);

  report.aggregate.metrics.login_click_to_interactive_ms = metric(2_001);
  assert(
    evaluateFrontendRuntimeBudget(report).failures
      .some((failure) => failure.metric === 'login_click_to_interactive_p95_ms'),
  );
});

test('longest task keeps the device target separate from the isolated CI ceiling', () => {
  const report = passingReport();
  report.aggregate.metrics.longest_task_ms = metric(422);
  const assessment = evaluateFrontendRuntimeBudget(report);
  assert.deepEqual(assessment.failures, []);
  assert.deepEqual(assessment.warnings, [{
    metric: 'longest_task_p95_ms',
    actual: 422,
    target: 200,
    reason: 'improvement_target_missed',
  }]);

  report.aggregate.metrics.longest_task_ms = metric(551);
  assert(
    evaluateFrontendRuntimeBudget(report).failures
      .some((failure) => failure.metric === 'longest_task_p95_ms'),
  );
});

function reportFromLongestTaskRuns(longestTasks) {
  const report = passingReport();
  report.runs = longestTasks.map((longestTask, index) => ({
    run: index + 1,
    authenticated: true,
    verification_status: 'verified',
    attempt_count: 1,
    metrics: {
      total_requests: 19,
      fcp_ms: 300,
      lcp_ms: 300,
      login_click_to_interactive_ms: 700,
      auth_to_interactive_ms: 320,
      longest_task_ms: longestTask,
    },
    api: {
      sample_count: 4,
      repeated_routes: [],
      samples: [
        { route: '/api/auth/login', duration_ms: 200, transfer_bytes: 1, status: 200 },
        { route: '/api/auth/info', duration_ms: 100, transfer_bytes: 1, status: 200 },
        { route: '/api/dashboard/revenue-facts', duration_ms: 150, transfer_bytes: 1, status: 200 },
        { route: '/api/compass', duration_ms: 180, transfer_bytes: 1, status: 200 },
      ],
    },
  }));
  report.aggregate = summarizeFrontendPerformanceRuns(report.runs);
  report.percentile_method = report.aggregate.percentile_method;
  return report;
}

test('linear P95 keeps an isolated cold-run max visible without hiding serious or repeated stalls', () => {
  const isolatedColdRun = evaluateFrontendRuntimeBudget(
    reportFromLongestTaskRuns([214, 217, 219, 226, 603]),
  );
  assert.deepEqual(isolatedColdRun.failures, []);
  assert.equal(isolatedColdRun.observed.longest_task_p95_ms, 528);
  assert.equal(isolatedColdRun.observed.longest_task_max_ms, 603);
  assert(isolatedColdRun.warnings.some((warning) => (
    warning.metric === 'longest_task_max_ms'
      && warning.actual === 603
      && warning.target === 550
      && warning.reason === 'isolated_run_outlier_above_p95_ceiling'
  )));

  const severeColdRun = evaluateFrontendRuntimeBudget(
    reportFromLongestTaskRuns([214, 217, 219, 226, 1_000]),
  );
  assert.equal(severeColdRun.observed.longest_task_p95_ms, 845);
  assert(severeColdRun.failures.some((failure) => failure.metric === 'longest_task_p95_ms'));

  const repeatedColdRuns = evaluateFrontendRuntimeBudget(
    reportFromLongestTaskRuns([214, 217, 219, 603, 603]),
  );
  assert.equal(repeatedColdRuns.observed.longest_task_p95_ms, 603);
  assert(repeatedColdRuns.failures.some((failure) => failure.metric === 'longest_task_p95_ms'));
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
