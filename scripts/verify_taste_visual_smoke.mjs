import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const repoRoot = process.cwd();
const html = fs.readFileSync(path.join(repoRoot, 'public/index.html'), 'utf8');
const failures = [];
const pageKeys = collectPageKeys(html);
const screenshotsDir = path.join(repoRoot, 'output/playwright/taste-visual-smoke');

const menuGroupOnlyKeys = new Set([
  'ai-construction',
  'ai-expansion',
  'ai-opening',
  'ai-ops',
  'ai-transfer',
]);

const requiredPageKeys = [...pageKeys]
  .filter((key) => !menuGroupOnlyKeys.has(key))
  .sort();

const visualStates = buildVisualStates(requiredPageKeys);

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const username = process.env.E2E_USERNAME || 'admin';
const password = process.env.E2E_PASSWORD || 'admin123';
const screenshotMode = process.env.E2E_TASTE_SCREENSHOTS || 'failures';
const failOnConsole = process.env.E2E_TASTE_FAIL_ON_CONSOLE !== '0';
const failOnRequestFailure = process.env.E2E_TASTE_FAIL_ON_REQUEST_FAILURE === '1';
const authMode = process.env.E2E_TASTE_AUTH_MODE || 'mock';
const writeResults = process.env.E2E_TASTE_WRITE_RESULTS !== '0';

function collectPageKeys(source) {
  const keys = new Set();
  addMatches(keys, source, /currentPage\s*===\s*['"]([^'"]+)['"]/g);
  addMatches(keys, source, /currentPage\s*=\s*['"]([^'"]+)['"]/g);
  addMatches(keys, source, /currentPage\.value\s*=\s*['"]([^'"]+)['"]/g);

  for (const match of source.matchAll(/\[([^\]]*?)\]\.includes\(currentPage\)/g)) {
    for (const item of match[1].matchAll(/['"]([^'"]+)['"]/g)) {
      keys.add(item[1]);
    }
  }

  return keys;
}

function addMatches(set, source, pattern) {
  let match;
  while ((match = pattern.exec(source))) {
    set.add(match[1]);
  }
}

function safeName(value) {
  return String(value).replace(/[^a-z0-9_.-]+/gi, '_');
}

function buildVisualStates(keys) {
  const states = keys.map((pageKey) => ({ label: pageKey, pageKey }));

  for (const onlineDataTab of [
    'data-health',
    'platform-auto',
    'analysis',
    'data',
    'cookies',
    'profile-fields',
    'platform-sources',
    'quick',
    'custom',
  ]) {
    states.push({ label: `online-data:${onlineDataTab}`, pageKey: 'online-data', onlineDataTab });
  }

  for (const onlineDataTab of [
    'ctrip-ranking',
    'ctrip-flow-overview',
    'ctrip-traffic',
    'ctrip-ads',
    'ctrip-download',
    'ctrip-fetch-settings',
  ]) {
    states.push({ label: `ctrip-ebooking:${onlineDataTab}`, pageKey: 'ctrip-ebooking', onlineDataTab });
  }

  for (const ctripTableTab of ['sales', 'traffic', 'rank']) {
    states.push({
      label: `ctrip-ebooking:ctrip-ranking:${ctripTableTab}`,
      pageKey: 'ctrip-ebooking',
      onlineDataTab: 'ctrip-ranking',
      ctripTableTab,
    });
  }

  for (const downloadCenterTab of ['fetched', 'overview', 'ai']) {
    states.push({
      label: `ctrip-ebooking:ctrip-download:${downloadCenterTab}`,
      pageKey: 'ctrip-ebooking',
      onlineDataTab: 'ctrip-download',
      downloadCenterTab,
    });
  }

  for (const onlineDataTab of [
    'meituan-ranking',
    'meituan-traffic',
    'meituan-orders',
    'meituan-ads',
    'meituan-download',
    'meituan-config',
  ]) {
    states.push({ label: `meituan-ebooking:${onlineDataTab}`, pageKey: 'meituan-ebooking', onlineDataTab });
  }

  for (const downloadCenterTab of ['overview', 'traffic', 'orders', 'ads']) {
    states.push({
      label: `meituan-ebooking:meituan-download:${downloadCenterTab}`,
      pageKey: 'meituan-ebooking',
      onlineDataTab: 'meituan-download',
      downloadCenterTab,
    });
  }

  const seen = new Set();
  return states.filter((state) => {
    if (seen.has(state.label)) return false;
    seen.add(state.label);
    return true;
  });
}

function shouldIgnoreRequestFailure(url) {
  return /\.(?:png|jpg|jpeg|webp|gif|svg|ico|woff2?|ttf)(?:\?|$)/i.test(url);
}

async function login(page) {
  if (authMode === 'mock') {
    await page.route('**/api/**', async (route) => {
      const url = route.request().url();
      const data = url.includes('/api/auth/info')
        ? {
            id: 999001,
            username: 'taste_visual_probe',
            realname: 'Taste Visual Probe',
            role_id: 1,
            role_name: '超级管理员',
            hotel_id: 1,
            hotel: { id: 1, name: '视觉验证门店' },
            is_super_admin: true,
            is_hotel_manager: true,
            permitted_hotels: [{ id: 1, name: '视觉验证门店' }],
            permissions: {
              can_view_report: true,
              can_fill_daily_report: true,
              can_fill_monthly_task: true,
              can_edit_report: true,
              can_delete_report: true,
              can_view_online_data: true,
              can_fetch_online_data: true,
              can_delete_online_data: true,
            },
          }
        : url.includes('/api/hotels')
          ? [{ id: 1, name: '视觉验证门店', hotel_name: '视觉验证门店' }]
          : [];

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 200,
          message: 'ok',
          data,
        }),
      });
    });
    await page.addInitScript(() => localStorage.setItem('token', 'taste-visual-probe-token'));
  } else if (authMode !== 'real') {
    throw new Error(`Unsupported E2E_TASTE_AUTH_MODE: ${authMode}`);
  }

  await page.goto(baseURL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const usernameInput = page.getByTestId('login-username');
  if (authMode === 'real' && await usernameInput.count()) {
    await usernameInput.fill(username);
    await page.getByTestId('login-password').fill(password);
    await page.getByTestId('login-submit').click();
  }
  await page.getByTestId('app-main').waitFor({ state: 'visible', timeout: 15000 });
}

async function setCurrentState(page, state) {
  const changed = await page.evaluate(async (targetState) => {
    const root = document.querySelector('#app');
    const component = root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component;
    const proxy = component?.proxy;
    if (!proxy) return false;

    proxy.currentPage = targetState.pageKey;
    if (targetState.onlineDataTab) {
      proxy.onlineDataTab = targetState.onlineDataTab;
    } else if (targetState.pageKey === 'online-data') {
      proxy.onlineDataTab = proxy.onlineDataTab || 'platform-auto';
    }
    if (targetState.downloadCenterTab) {
      proxy.downloadCenterTab = targetState.downloadCenterTab;
    }
    if (targetState.ctripTableTab) {
      proxy.ctripTableTab = targetState.ctripTableTab;
    }

    await new Promise((resolve) => setTimeout(resolve, 450));
    return true;
  }, state);

  if (!changed) {
    throw new Error('Vue root proxy is not available for page switching');
  }
}

async function inspectPage(page, state) {
  await setCurrentState(page, state);

  const result = await page.evaluate((targetState) => {
    const main = document.querySelector('[data-testid="app-main"]');
    const shell = document.querySelector('.suxi-app-shell');
    const body = document.querySelector('.suxi-page-body');
    const activePage = main?.getAttribute('data-current-page') || '';
    const visibleText = (body?.innerText || '').replace(/\s+/g, ' ').trim();
    const panels = [...document.querySelectorAll(
      'main .suxi-panel, main .card, main .table-container, main .bg-white.rounded-xl, main .bg-white.rounded-lg, main .bg-white.rounded-2xl',
    )].filter((element) => {
      const style = getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length;
    });
    const controls = [...document.querySelectorAll('main button, main input, main textarea, main select, main a')].filter((element) => {
      const style = getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length;
    });

    return {
      label: targetState.label,
      pageKey: targetState.pageKey,
      onlineDataTab: targetState.onlineDataTab || '',
      downloadCenterTab: targetState.downloadCenterTab || '',
      ctripTableTab: targetState.ctripTableTab || '',
      activePage,
      title: document.querySelector('header h1')?.textContent?.trim() || '',
      hasShell: !!shell,
      hasLogin: !!document.querySelector('.login-bg'),
      hasPageBody: !!body,
      textLength: visibleText.length,
      hasRawVueTemplate: visibleText.includes('{{') || visibleText.includes('}}'),
      visiblePanels: panels.length,
      visibleControls: controls.length,
      horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
    };
  }, state);

  if (screenshotMode === 'all') {
    await page.screenshot({
      path: path.join(screenshotsDir, `${safeName(state.label)}.png`),
      fullPage: false,
    });
  }

  return result;
}

function validatePageResult(result) {
  const issues = [];
  if (result.activePage !== result.pageKey) {
    issues.push(`expected active page ${result.pageKey}, got ${result.activePage || '(empty)'}`);
  }
  if (!result.hasShell) issues.push('missing .suxi-app-shell');
  if (result.hasLogin) issues.push('login page is present during logged-in visual smoke');
  if (!result.hasPageBody) issues.push('missing .suxi-page-body');
  if (result.textLength < 20) issues.push(`page appears empty, textLength=${result.textLength}`);
  if (result.hasRawVueTemplate) issues.push('raw Vue template markers are visible');
  if (result.horizontalOverflow) issues.push('horizontal overflow detected');
  if (result.visiblePanels === 0 && result.visibleControls === 0) {
    issues.push('no visible panels or controls detected');
  }
  return issues;
}

async function main() {
  if (writeResults || screenshotMode !== 'none') {
    fs.mkdirSync(screenshotsDir, { recursive: true });
  }

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const browserEvents = {
    consoleErrors: [],
    pageErrors: [],
    requestFailures: [],
  };

  page.on('console', (message) => {
    if (message.type() === 'error') {
      browserEvents.consoleErrors.push(message.text().slice(0, 300));
    }
  });
  page.on('pageerror', (error) => {
    browserEvents.pageErrors.push(String(error?.message || error).slice(0, 300));
  });
  page.on('requestfailed', (request) => {
    const url = request.url();
    if (!shouldIgnoreRequestFailure(url)) {
      browserEvents.requestFailures.push({
        url: url.slice(0, 240),
        failure: request.failure()?.errorText || '',
      });
    }
  });

  try {
    await login(page);

    const results = [];
    for (const state of visualStates) {
      const result = await inspectPage(page, state);
      results.push(result);
      const issues = validatePageResult(result);
      for (const issue of issues) {
        failures.push(`${state.label}: ${issue}`);
      }

      if (issues.length > 0 && screenshotMode !== 'none') {
        await page.screenshot({
          path: path.join(screenshotsDir, `${safeName(state.label)}.failure.png`),
          fullPage: false,
        }).catch(() => {});
      }
    }

    if (failOnConsole && browserEvents.consoleErrors.length > 0) {
      failures.push(`console errors detected: ${browserEvents.consoleErrors.slice(0, 5).join(' | ')}`);
    }
    if (browserEvents.pageErrors.length > 0) {
      failures.push(`page errors detected: ${browserEvents.pageErrors.slice(0, 5).join(' | ')}`);
    }
    if (failOnRequestFailure && browserEvents.requestFailures.length > 0) {
      failures.push(`request failures detected: ${JSON.stringify(browserEvents.requestFailures.slice(0, 5))}`);
    }

    if (writeResults) {
      fs.writeFileSync(path.join(screenshotsDir, 'results.json'), JSON.stringify({
        baseURL,
        authMode,
        pageCount: requiredPageKeys.length,
        stateCount: visualStates.length,
        results,
        browserEvents,
        failures,
      }, null, 2));
    }
  } finally {
    await browser.close();
  }

  if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
  }

  console.log(`Taste visual smoke passed (${requiredPageKeys.length} logged-in page keys, ${visualStates.length} visual states, auth=${authMode}).`);
}

main().catch((error) => {
  console.error(error?.message || error);
  process.exit(1);
});
