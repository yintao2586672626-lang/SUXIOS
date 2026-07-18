import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';
import {
  resolveFrontendNetworkProfile,
  summarizeFrontendPerformance,
} from './lib/frontend_performance_metrics.mjs';

const options = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const [key, ...rest] = argument.replace(/^--/, '').split('=');
  return [key, rest.join('=') || '1'];
}));
const baseURL = options.url || process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const label = String(options.label || 'frontend').replace(/[^a-zA-Z0-9._-]+/g, '-');
const authenticated = options.authenticated === '1';
const networkProfile = resolveFrontendNetworkProfile(options.network || 'none');
const outputDir = path.resolve('output', 'performance');

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
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

const startedAt = new Date().toISOString();
await page.goto(baseURL, { waitUntil: 'domcontentloaded', timeout: 30_000 });
let authTransitionMs = null;
let authenticationStatus = authenticated ? 'pending' : 'not_requested';
let authenticationBlocker = null;
if (authenticated) {
  const username = page.locator('input[name="username"]').first();
  if (await username.count()) {
    await username.fill(process.env.E2E_USERNAME || 'admin');
    await page.locator('input[name="password"]').first().fill(process.env.E2E_PASSWORD || 'admin123');
    const authStarted = Date.now();
    await page.locator('button[type="submit"]').first().click();
    const outcome = await Promise.race([
      username.waitFor({ state: 'detached', timeout: 10_000 }).then(() => 'verified'),
      page.locator('.login-error').waitFor({ state: 'visible', timeout: 10_000 }).then(() => 'blocked'),
    ]).catch(() => 'blocked');
    if (outcome === 'verified') {
      authenticationStatus = 'verified';
      authTransitionMs = Date.now() - authStarted;
    } else {
      authenticationStatus = 'blocked';
      authenticationBlocker = String(
        await page.locator('.login-error').textContent().catch(() => '')
          || '登录状态在超时前未切换'
      ).trim();
    }
  } else if (await page.locator('[data-testid="app-nav"]').count()) {
    authenticationStatus = 'already_authenticated';
    authTransitionMs = 0;
  } else {
    authenticationStatus = 'blocked';
    authenticationBlocker = '未找到登录入口或已认证应用导航';
  }
}
await page.waitForTimeout(2500);

const snapshot = await page.evaluate(() => ({
  navigation: performance.getEntriesByType('navigation')[0]?.toJSON() || {},
  paints: performance.getEntriesByType('paint').map((entry) => entry.toJSON()),
  resources: performance.getEntriesByType('resource').map((entry) => ({
    name: entry.name,
    initiatorType: entry.initiatorType,
    transferSize: entry.transferSize,
    duration: entry.duration,
  })),
  lcp: window.__SUXI_PERFORMANCE?.lcp ?? null,
  longTasks: window.__SUXI_PERFORMANCE?.longTasks || [],
  loginHandoff: window.SUXI_LOGIN_HANDOFF_METRICS || null,
}));
const result = {
  schema_version: 1,
  label,
  url: baseURL,
  authenticated_requested: authenticated,
  authenticated: ['verified', 'already_authenticated'].includes(authenticationStatus),
  verification_status: authenticated && authenticationStatus === 'blocked' ? 'unverified' : 'verified',
  authentication_status: authenticationStatus,
  authentication_blocker: authenticationBlocker,
  started_at: startedAt,
  network_profile: networkProfile.name,
  auth_transition_ms: authTransitionMs,
  login_handoff: snapshot.loginHandoff,
  metrics: summarizeFrontendPerformance(snapshot),
  largest_resources: [...snapshot.resources]
    .sort((left, right) => Number(right.transferSize || 0) - Number(left.transferSize || 0))
    .slice(0, 15),
};

fs.mkdirSync(outputDir, { recursive: true });
const outputPath = path.join(outputDir, `${label}.json`);
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify({ output: outputPath, ...result }, null, 2));
await browser.close();
