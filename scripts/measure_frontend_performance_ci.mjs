import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import {
  summarizeFrontendPerformanceRuns,
} from './lib/frontend_performance_metrics.mjs';
import {
  evaluateFrontendRuntimeBudget,
} from './lib/frontend_runtime_performance_budget.mjs';

const root = process.cwd();
const outputDir = path.resolve(root, 'output', 'performance');
const aggregateLabel = 'isolated-authenticated-baseline';
const isolationRunCount = 5;
const reports = [];
const runs = [];
let expectedNetworkProfile = '';
let expectedUrl = '';
let expectedArtifactDigest = '';

fs.mkdirSync(outputDir, { recursive: true });

for (let isolationRun = 1; isolationRun <= isolationRunCount; isolationRun += 1) {
  const fragmentLabel = `ci-isolated-authenticated-${isolationRun}`;
  const child = spawnSync(process.execPath, [
    'tests/automation/run-quick-e2e-isolated.mjs',
    '--performance-only',
    '--performance-iterations=1',
    `--performance-label=${fragmentLabel}`,
    '--performance-enforce-budget=0',
  ], {
    cwd: root,
    env: process.env,
    encoding: 'utf8',
    maxBuffer: 10 * 1024 * 1024,
    windowsHide: true,
  });
  if (child.error || child.signal || child.status !== 0) {
    const detail = `${child.stderr || ''}\n${child.stdout || ''}`.trim().slice(-4_000);
    throw new Error(
      `Fresh isolated frontend performance run ${isolationRun} failed`
      + (child.error ? `: ${child.error.message}` : '')
      + (child.signal ? `: signal ${child.signal}` : '')
      + (detail ? `\n${detail}` : ''),
    );
  }

  const fragmentPath = path.join(outputDir, `${fragmentLabel}.json`);
  const fragment = JSON.parse(fs.readFileSync(fragmentPath, 'utf8'));
  const fragmentRun = fragment?.runs?.[0];
  if (fragment?.schema_version !== 2
    || fragment?.label !== fragmentLabel
    || fragment?.authenticated_requested !== true
    || fragment?.authenticated !== true
    || fragment?.verification_status !== 'verified'
    || fragment?.artifact_identity_stable !== true
    || !/^[a-f0-9]{64}$/.test(String(fragment?.artifact_identity?.digest || ''))
    || fragment?.artifact_identity_completed_digest !== fragment?.artifact_identity?.digest
    || fragment?.runs?.length !== 1
    || fragmentRun?.authenticated !== true
    || fragmentRun?.verification_status !== 'verified') {
    throw new Error(`Fresh isolated frontend performance run ${isolationRun} is not verified`);
  }
  expectedNetworkProfile ||= String(fragment.network_profile || '');
  expectedUrl ||= String(fragment.url || '');
  expectedArtifactDigest ||= String(fragment.artifact_identity.digest || '');
  if (fragment.network_profile !== expectedNetworkProfile || fragment.url !== expectedUrl) {
    throw new Error(`Fresh isolated frontend performance run ${isolationRun} changed its measurement scope`);
  }
  if (fragment.artifact_identity.digest !== expectedArtifactDigest) {
    throw new Error(`Fresh isolated frontend performance run ${isolationRun} changed its artifact identity`);
  }
  reports.push(fragment);
  runs.push({
    ...fragmentRun,
    run: isolationRun,
    isolation_run: isolationRun,
    isolation_started_at: fragment.started_at || null,
  });
  console.log(
    `[frontend-performance-ci] isolation_run=${isolationRun}`
    + ` attempts=${fragmentRun.attempt_count || 1}`
    + ` verification=${fragmentRun.verification_status}`,
  );
}

const aggregate = summarizeFrontendPerformanceRuns(runs);
const firstReport = reports[0] || {};
const firstRun = runs[0] || {};
const result = {
  schema_version: 2,
  label: aggregateLabel,
  url: firstReport.url || null,
  iterations: isolationRunCount,
  isolation_strategy: 'fresh_server_seed_and_browser_per_run',
  authenticated_requested: true,
  authenticated: aggregate.verified_run_count === isolationRunCount,
  verification_status: aggregate.unverified_run_count > 0 ? 'unverified' : 'verified',
  authentication_status: firstRun.authentication_status || null,
  authentication_blocker: firstRun.authentication_blocker || null,
  started_at: firstReport.started_at || new Date().toISOString(),
  completed_at: new Date().toISOString(),
  artifact_identity: firstReport.artifact_identity || null,
  artifact_identity_completed_digest: expectedArtifactDigest,
  artifact_identity_stable: reports.every(
    (report) => report.artifact_identity_stable === true
      && report.artifact_identity_completed_digest === expectedArtifactDigest
      && report.artifact_identity?.digest === expectedArtifactDigest,
  ),
  network_profile: firstReport.network_profile || 'none',
  auth_transition_ms: firstRun.auth_transition_ms ?? null,
  login_handoff: firstRun.login_handoff || null,
  metrics: firstRun.metrics || null,
  api: firstRun.api || null,
  largest_resources: firstRun.largest_resources || [],
  aggregate,
  runs,
};
result.runtime_budget = evaluateFrontendRuntimeBudget(result);

const outputPath = path.join(outputDir, `${aggregateLabel}.json`);
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify({
  output: outputPath,
  isolation_strategy: result.isolation_strategy,
  aggregate: result.aggregate,
  runtime_budget: result.runtime_budget,
}, null, 2));

if (aggregate.unverified_run_count > 0) {
  process.exitCode = 2;
} else if (result.runtime_budget.failures.length > 0) {
  process.exitCode = 3;
}
