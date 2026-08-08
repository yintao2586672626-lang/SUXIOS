import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import {
  resolveFrontendNetworkProfile,
  summarizeApiPerformance,
  summarizeFrontendPerformance,
  summarizeFrontendPerformanceRuns,
} from './lib/frontend_performance_metrics.mjs';
import { evaluateFrontendRuntimeBudget } from './lib/frontend_runtime_performance_budget.mjs';
import { captureFrontendPerformanceIdentity } from './lib/frontend_performance_evidence_identity.mjs';

const options = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...rest] = argument.replace(/^--/, '').split('=');
  return [key, rest.join('=') || '1'];
}));
const baseURL = options.url || process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const label = String(options.label || 'frontend').replace(/[^a-zA-Z0-9._-]+/g, '-');
const authenticated = options.authenticated === '1';
const networkProfile = resolveFrontendNetworkProfile(options.network || 'none');
const iterations = Math.max(1, Math.min(30, Number.parseInt(options.iterations || '1', 10) || 1));
const interactiveTimeoutMs = Math.max(
  1_000,
  Math.min(120_000, Number.parseInt(options['interactive-timeout-ms'] || '30000', 10) || 30_000),
);
const settleMs = Math.max(
  0,
  Math.min(10_000, Number.parseInt(options['settle-ms'] || '2500', 10) || 2_500),
);
const requireVerified = options['require-verified'] === '1';
const enforceBudget = options['enforce-budget'] === '1';
const debugDomPatches = options['debug-dom-patches'] === '1'
  || process.env.SUXI_PERFORMANCE_DEBUG_DOM_PATCHES === '1';
const maxMeasurementAttempts = 2;
const outputDir = path.resolve('output', 'performance');
const credentialUsername = String(process.env.E2E_USERNAME || '').trim();
const credentialPassword = String(process.env.E2E_PASSWORD || '');
const baseOrigin = new URL(baseURL).origin;

function safeResourceName(value) {
  try {
    const parsed = new URL(String(value || ''), baseURL);
    return parsed.origin === baseOrigin
      ? parsed.pathname
      : `${parsed.origin}${parsed.pathname}`;
  } catch (_error) {
    return String(value || '').split('?')[0];
  }
}

async function measureRun(browser, runIndex) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  const startupDiagnostics = [];
  const recordStartupDiagnostic = (type, value) => {
    if (startupDiagnostics.length >= 8) return;
    const message = String(value || '').trim().slice(0, 800);
    if (!message) return;
    startupDiagnostics.push({ type, message });
  };
  page.on('pageerror', (error) => {
    recordStartupDiagnostic('pageerror', `${error?.name || 'Error'}: ${error?.message || error}`);
  });
  page.on('console', (message) => {
    const text = String(message?.text?.() || '');
    if (message?.type?.() === 'error' && text.startsWith('[SUXIOS]')) {
      recordStartupDiagnostic('console', text);
    }
  });
  try {
    if (networkProfile.conditions) {
      const cdp = await context.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', networkProfile.conditions);
    }
    await page.addInitScript(({ debugDomPatches }) => {
      window.__SUXI_PERFORMANCE = { lcp: null, longTasks: [] };
      if (debugDomPatches) {
        const originalSetAttribute = Element.prototype.setAttribute;
        Element.prototype.setAttribute = function setAttributeWithDiagnostics(name, value) {
          try {
            return originalSetAttribute.call(this, name, value);
          } catch (error) {
            const details = {
              tag: String(this?.tagName || '').slice(0, 80),
              id: String(this?.id || '').slice(0, 120),
              class_name: typeof this?.className === 'string' ? this.className.slice(0, 240) : '',
              attribute: String(name || '').slice(0, 120),
              value_type: typeof value,
              null_prototype: false,
              value_keys: [],
              value_json: '',
            };
            try {
              details.null_prototype = Boolean(value && typeof value === 'object' && Object.getPrototypeOf(value) === null);
            } catch (_error) {}
            try {
              details.value_keys = value && typeof value === 'object' ? Object.keys(value).slice(0, 20) : [];
            } catch (_error) {}
            try {
              details.value_json = JSON.stringify(value)?.slice(0, 400) || '';
            } catch (_error) {}
            console.error('[SUXIOS] DOM attribute patch failure:', JSON.stringify(details));
            throw error;
          }
        };
      }
      try {
        new PerformanceObserver((list) => {
          const entries = list.getEntries();
          const latest = entries[entries.length - 1];
          if (latest) window.__SUXI_PERFORMANCE.lcp = latest.startTime;
        }).observe({ type: 'largest-contentful-paint', buffered: true });
      } catch (_error) {}
      try {
        new PerformanceObserver((list) => {
          window.__SUXI_PERFORMANCE.longTasks.push(...list.getEntries().map((entry) => ({
            startTime: entry.startTime,
            duration: entry.duration,
          })));
        }).observe({ type: 'longtask', buffered: true });
      } catch (_error) {}
    }, { debugDomPatches });

    await page.goto(baseURL, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    let authTransitionMs = null;
    let loginClickToInteractiveMs = null;
    let authStartPerformanceMs = 0;
    let authenticationStatus = authenticated ? 'pending' : 'not_requested';
    let authenticationBlocker = null;
    if (authenticated) {
      if (!credentialUsername || !credentialPassword) {
        authenticationStatus = 'blocked';
        authenticationBlocker = 'E2E_USERNAME and E2E_PASSWORD are required for authenticated measurement';
      } else {
        const username = page.locator('input[name="username"]').first();
        if (await username.count()) {
          await username.fill(credentialUsername);
          await page.locator('input[name="password"]').first().fill(credentialPassword);
          authStartPerformanceMs = await page.evaluate(() => performance.now());
          const authStarted = Date.now();
          await page.locator('button[type="submit"]').first().click();
          const outcome = await Promise.race([
            username.waitFor({ state: 'detached', timeout: 10_000 }).then(() => 'authenticated'),
            page.locator('.login-error').waitFor({ state: 'visible', timeout: 10_000 }).then(() => 'blocked'),
          ]).catch(() => 'blocked');
          if (outcome === 'authenticated') {
            authTransitionMs = Date.now() - authStarted;
            const interactive = await page.waitForFunction(
              () => window.SUXI_LOGIN_HANDOFF_METRICS?.status === 'interactive',
              null,
              { timeout: interactiveTimeoutMs },
            ).then(() => true).catch(() => false);
            if (interactive) {
              authenticationStatus = 'verified';
              loginClickToInteractiveMs = Date.now() - authStarted;
            } else {
              authenticationStatus = 'authenticated_not_interactive';
              authenticationBlocker = `Authenticated shell did not become interactive within ${interactiveTimeoutMs}ms`;
            }
          } else {
            authenticationStatus = 'blocked';
            authenticationBlocker = String(
              await page.locator('.login-error').textContent().catch(() => '')
                || 'Login state did not switch before timeout'
            ).trim();
          }
        } else if (await page.locator('[data-testid="app-nav"]').count()) {
          authenticationStatus = 'already_authenticated';
          authTransitionMs = 0;
        } else {
          authenticationStatus = 'blocked';
          authenticationBlocker = 'Login entry or authenticated application navigation was not found';
        }
      }
    }
    if (settleMs > 0) await page.waitForTimeout(settleMs);

    const snapshot = await page.evaluate(() => ({
      navigation: performance.getEntriesByType('navigation')[0]?.toJSON() || {},
      paints: performance.getEntriesByType('paint').map((entry) => entry.toJSON()),
      resources: performance.getEntriesByType('resource').map((entry) => ({
        name: entry.name,
        initiatorType: entry.initiatorType,
        startTime: entry.startTime,
        transferSize: entry.transferSize,
        duration: entry.duration,
        responseStatus: entry.responseStatus,
      })),
      lcp: window.__SUXI_PERFORMANCE?.lcp ?? null,
      longTasks: window.__SUXI_PERFORMANCE?.longTasks || [],
      loginHandoff: window.SUXI_LOGIN_HANDOFF_METRICS || null,
    }));
    const api = summarizeApiPerformance(snapshot.resources, {
      min_start_time_ms: authenticated ? authStartPerformanceMs : 0,
    });
    const metrics = summarizeFrontendPerformance(snapshot);
    metrics.login_click_to_interactive_ms = loginClickToInteractiveMs;
    const verificationStatus = (
      authenticated && !['verified', 'already_authenticated'].includes(authenticationStatus)
    ) || startupDiagnostics.length > 0
      ? 'unverified'
      : 'verified';

    return {
      run: runIndex,
      authenticated: ['verified', 'already_authenticated'].includes(authenticationStatus),
      verification_status: verificationStatus,
      authentication_status: authenticationStatus,
      authentication_blocker: authenticationBlocker,
      startup_diagnostics: startupDiagnostics,
      auth_transition_ms: authTransitionMs,
      login_handoff: snapshot.loginHandoff,
      metrics,
      api,
      largest_resources: [...snapshot.resources]
        .sort((left, right) => Number(right.transferSize || 0) - Number(left.transferSize || 0))
        .slice(0, 15)
        .map((entry) => ({ ...entry, name: safeResourceName(entry.name) })),
    };
  } finally {
    await context.close();
  }
}

async function measureRunWithRetry(runIndex) {
  const attemptFailures = [];
  for (let attempt = 1; attempt <= maxMeasurementAttempts; attempt += 1) {
    let browser = null;
    try {
      browser = await chromium.launch({ channel: 'chrome', headless: true });
      const run = await measureRun(browser, runIndex);
      return {
        ...run,
        attempt_count: attempt,
        attempt_failures: attemptFailures,
      };
    } catch (error) {
      const failureName = String(error?.name || 'Error').replace(/[^a-zA-Z0-9_.-]+/g, '').slice(0, 80) || 'Error';
      attemptFailures.push({ attempt, name: failureName });
      const retryableNavigationTimeout = failureName === 'TimeoutError'
        && String(error?.message || '').startsWith('page.goto:');
      if (!retryableNavigationTimeout || attempt >= maxMeasurementAttempts) throw error;
      console.warn(`[frontend-performance] run=${runIndex} attempt=${attempt} failed=${failureName}; retrying`);
      await new Promise((resolve) => setTimeout(resolve, 250));
    } finally {
      if (browser) await browser.close().catch(() => {});
    }
  }
  throw new Error(`Frontend performance run ${runIndex} exhausted its bounded attempts`);
}

const startedAt = new Date().toISOString();
const artifactIdentityStarted = captureFrontendPerformanceIdentity();
const runs = [];
for (let runIndex = 1; runIndex <= iterations; runIndex += 1) {
  const run = await measureRunWithRetry(runIndex);
  runs.push(run);
  console.log(
    `[frontend-performance] run=${runIndex} attempts=${run.attempt_count} verification=${run.verification_status}`,
  );
}

const aggregate = summarizeFrontendPerformanceRuns(runs);
const firstRun = runs[0] || {};
const completedAt = new Date().toISOString();
const artifactIdentityCompleted = captureFrontendPerformanceIdentity();
const result = {
  schema_version: 2,
  label,
  url: baseURL,
  iterations,
  authenticated_requested: authenticated,
  authenticated: authenticated
    ? aggregate.verified_run_count === iterations
    : true,
  verification_status: aggregate.unverified_run_count > 0 ? 'unverified' : 'verified',
  authentication_status: firstRun.authentication_status || null,
  authentication_blocker: firstRun.authentication_blocker || null,
  startup_diagnostics: firstRun.startup_diagnostics || [],
  started_at: startedAt,
  completed_at: completedAt,
  artifact_identity: artifactIdentityStarted,
  artifact_identity_completed_digest: artifactIdentityCompleted.digest,
  artifact_identity_stable: artifactIdentityStarted.digest === artifactIdentityCompleted.digest,
  network_profile: networkProfile.name,
  auth_transition_ms: firstRun.auth_transition_ms ?? null,
  login_handoff: firstRun.login_handoff || null,
  metrics: firstRun.metrics || null,
  api: firstRun.api || null,
  largest_resources: firstRun.largest_resources || [],
  aggregate,
  runs,
};
result.runtime_budget = evaluateFrontendRuntimeBudget(result);

fs.mkdirSync(outputDir, { recursive: true });
const outputPath = path.join(outputDir, `${label}.json`);
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify({ output: outputPath, ...result }, null, 2));
if (requireVerified && aggregate.unverified_run_count > 0) {
  process.exitCode = 2;
} else if (enforceBudget && result.runtime_budget.failures.length > 0) {
  process.exitCode = 3;
}
