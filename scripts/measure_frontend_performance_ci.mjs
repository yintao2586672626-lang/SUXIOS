import { spawn, spawnSync } from 'node:child_process';
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
const isolationRunTimeoutMs = 120_000;
const isolationRunShutdownGraceMs = 45_000;
const childOutputLimit = 10 * 1024 * 1024;
const controllerShutdownRequestType = 'suxi-isolated-shutdown-request';
const controllerShutdownCompleteType = 'suxi-isolated-shutdown-complete';
const controllerShutdownReleaseType = 'suxi-isolated-shutdown-release';
const reports = [];
const runs = [];
let expectedNetworkProfile = '';
let expectedUrl = '';
let expectedArtifactDigest = '';
let activeIsolationShutdown = null;
let parentShutdownSignal = null;

fs.mkdirSync(outputDir, { recursive: true });

function appendBoundedOutput(current, chunk) {
  const combined = `${current}${String(chunk || '')}`;
  return combined.length > childOutputLimit ? combined.slice(-childOutputLimit) : combined;
}

function signalChildTree(child, signal) {
  if (!child || !Number.isInteger(child.pid)) return;
  try {
    process.kill(-child.pid, signal);
  } catch (error) {
    if (error?.code !== 'ESRCH') throw error;
  }
}

function forceStopChildTree(child) {
  if (!child || !Number.isInteger(child.pid)) return;
  if (process.platform === 'win32') {
    if (child.exitCode !== null) return;
    spawnSync('taskkill.exe', ['/PID', String(child.pid), '/T', '/F'], {
      windowsHide: true,
      stdio: 'ignore',
    });
    return;
  }
  signalChildTree(child, 'SIGKILL');
}

function requestIsolatedPerformanceShutdown(child, signal = 'SIGTERM') {
  if (child?.connected && typeof child.send === 'function') {
    try {
      child.send({
        type: controllerShutdownRequestType,
        signal: signal === 'SIGINT' ? 'SIGINT' : 'SIGTERM',
      }, (error) => {
        if (error) forceStopChildTree(child);
      });
      return true;
    } catch (error) {
      // Fall through to the process-group safety net on POSIX.
    }
  }
  if (process.platform !== 'win32') {
    signalChildTree(child, signal);
    return true;
  }
  return false;
}

function runIsolatedPerformanceChild(args) {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, args, {
      cwd: root,
      env: process.env,
      detached: process.platform !== 'win32',
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe', 'ipc'],
    });
    let stdout = '';
    let stderr = '';
    let settled = false;
    let timedOut = false;
    let forceTimer = null;
    let cleanupAcknowledged = false;
    let pendingCloseResult = null;
    let shutdownStarted = false;
    child.stdout?.on('data', (chunk) => {
      stdout = appendBoundedOutput(stdout, chunk);
    });
    child.stderr?.on('data', (chunk) => {
      stderr = appendBoundedOutput(stderr, chunk);
    });
    child.on('message', (message) => {
      if (!shutdownStarted || message?.type !== controllerShutdownCompleteType) return;
      if (message.cleanup_complete !== true) {
        forceStopChildTree(child);
        if (forceTimer !== null) {
          clearTimeout(forceTimer);
          forceTimer = null;
        }
        return;
      }
      cleanupAcknowledged = true;
      if (forceTimer !== null) {
        clearTimeout(forceTimer);
        forceTimer = null;
      }
      try {
        child.send({ type: controllerShutdownReleaseType }, (error) => {
          if (error) forceStopChildTree(child);
        });
      } catch (error) {
        forceStopChildTree(child);
      }
      if (pendingCloseResult !== null) {
        const result = pendingCloseResult;
        pendingCloseResult = null;
        finish(result);
      }
    });
    const beginShutdown = (signal, timeoutFailure = false) => {
      if (shutdownStarted) return;
      shutdownStarted = true;
      timedOut = timeoutFailure;
      if (!requestIsolatedPerformanceShutdown(child, signal)) {
        forceStopChildTree(child);
        return;
      }
      forceTimer = setTimeout(() => {
        forceTimer = null;
        forceStopChildTree(child);
        if (pendingCloseResult !== null) {
          const result = pendingCloseResult;
          pendingCloseResult = null;
          finish(result);
        }
      }, isolationRunShutdownGraceMs);
    };
    activeIsolationShutdown = (signal) => beginShutdown(signal, false);
    const timeout = setTimeout(() => beginShutdown('SIGTERM', true), isolationRunTimeoutMs);
    const finish = (result) => {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      if (forceTimer !== null) clearTimeout(forceTimer);
      if (activeIsolationShutdown !== null) activeIsolationShutdown = null;
      resolve({ ...result, stdout, stderr, cleanupAcknowledged });
    };
    child.once('error', (error) => finish({ error, status: null, signal: null }));
    child.once('close', (status, signal) => {
      const error = timedOut
        ? Object.assign(new Error(`isolated child exceeded ${isolationRunTimeoutMs} ms`), { code: 'ETIMEDOUT' })
        : null;
      const result = { error, status, signal };
      if (shutdownStarted && !cleanupAcknowledged && forceTimer !== null) {
        pendingCloseResult = result;
        return;
      }
      finish(result);
    });
  });
}

function handleParentShutdownSignal(signal) {
  parentShutdownSignal ||= signal;
  process.exitCode = parentShutdownSignal === 'SIGINT' ? 130 : 143;
  activeIsolationShutdown?.(parentShutdownSignal);
}

process.on('SIGINT', () => handleParentShutdownSignal('SIGINT'));
process.on('SIGTERM', () => handleParentShutdownSignal('SIGTERM'));

performanceMeasurement: {
for (let isolationRun = 1; isolationRun <= isolationRunCount; isolationRun += 1) {
  if (parentShutdownSignal) {
    process.exitCode = parentShutdownSignal === 'SIGINT' ? 130 : 143;
    break performanceMeasurement;
  }
  const fragmentLabel = `ci-isolated-authenticated-${isolationRun}`;
  console.log(`[frontend-performance-ci] isolation_run=${isolationRun} phase=start`);
  const child = await runIsolatedPerformanceChild([
    'tests/automation/run-quick-e2e-isolated.mjs',
    '--performance-only',
    '--performance-iterations=1',
    `--performance-label=${fragmentLabel}`,
    '--performance-enforce-budget=0',
  ]);
  if (parentShutdownSignal) {
    process.exitCode = parentShutdownSignal === 'SIGINT' ? 130 : 143;
    break performanceMeasurement;
  }
  if (child.error || child.signal || child.status !== 0) {
    const detail = `${child.stderr || ''}\n${child.stdout || ''}`.trim().slice(-4_000);
    throw new Error(
      `Fresh isolated frontend performance run ${isolationRun} failed`
      + (child.error ? `: ${child.error.message}` : '')
      + (child.signal ? `: signal ${child.signal}` : '')
      + (child.error?.code === 'ETIMEDOUT' ? ` after ${isolationRunTimeoutMs} ms` : '')
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

if (parentShutdownSignal) {
  process.exitCode = parentShutdownSignal === 'SIGINT' ? 130 : 143;
  break performanceMeasurement;
}

const aggregate = summarizeFrontendPerformanceRuns(runs);
const firstReport = reports[0] || {};
const firstRun = runs[0] || {};
const result = {
  schema_version: 2,
  percentile_method: aggregate.percentile_method,
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
}
}
