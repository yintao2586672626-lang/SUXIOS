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
  try {
    if (networkProfile.conditions) {
      const cdp = await context.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', networkProfile.conditions);
    }
    await page.addInitScript(() => {
      window.__SUXI_PERFORMANCE = { lcp: null, longTasks: [] };
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
    });

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
    const verificationStatus = authenticated
      && !['verified', 'already_authenticated'].includes(authenticationStatus)
      ? 'unverified'
      : 'verified';

    return {
      run: runIndex,
      authenticated: ['verified', 'already_authenticated'].includes(authenticationStatus),
      verification_status: verificationStatus,
      authentication_status: authenticationStatus,
      authentication_blocker: authenticationBlocker,
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

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const startedAt = new Date().toISOString();
const runs = [];
try {
  for (let runIndex = 1; runIndex <= iterations; runIndex += 1) {
    runs.push(await measureRun(browser, runIndex));
  }
} finally {
  await browser.close();
}

const aggregate = summarizeFrontendPerformanceRuns(runs);
const firstRun = runs[0] || {};
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
  started_at: startedAt,
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
