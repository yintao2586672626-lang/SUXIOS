import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const repoRoot = process.cwd();
const publicEntry = fs.readFileSync(path.join(repoRoot, 'public/index.html'), 'utf8');
const templateSnapshot = fs.readFileSync(path.join(repoRoot, 'resources/frontend/app-template.html'), 'utf8');
const failures = [];
// Only renderable template pages belong in visual coverage. app-main.js also
// retains compatibility transitions for fragments intentionally excluded from
// the authenticated runtime, and those references are not visible pages.
const pageKeys = collectPageKeys(`${publicEntry}\n${templateSnapshot}`);
const screenshotsDir = path.join(repoRoot, 'output/playwright/taste-visual-smoke');

const menuGroupOnlyKeys = new Set([
  'ai-construction',
  'ai-expansion',
  'ai-opening',
  'ai-ops',
  'ai-transfer',
]);

const canonicalPageAliases = new Map([
  ['ai-workbench', 'compass'],
]);

const canonicalPageKey = (pageKey) => canonicalPageAliases.get(pageKey) || pageKey;
const requiredPageKeys = [...new Set([...pageKeys]
  .filter((key) => !menuGroupOnlyKeys.has(key))
  .map(canonicalPageKey))]
  .sort();

const requestedPageKeys = (process.env.E2E_TASTE_PAGES || '').split(',').map((key) => key.trim()).filter(Boolean);
for (const pageKey of requestedPageKeys) {
  if (!requiredPageKeys.includes(pageKey)) throw new Error(`Unknown visual smoke page: ${pageKey}`);
}
const visualStates = buildVisualStates(requiredPageKeys)
  .filter((state) => !requestedPageKeys.length || requestedPageKeys.includes(state.pageKey));
const testedPageCount = new Set(visualStates.map((state) => state.pageKey)).size;

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const username = process.env.E2E_USERNAME || 'admin';
const password = process.env.E2E_PASSWORD || 'admin123';
const screenshotMode = process.env.E2E_TASTE_SCREENSHOTS || 'failures';
const failOnConsole = process.env.E2E_TASTE_FAIL_ON_CONSOLE !== '0';
const failOnRequestFailure = process.env.E2E_TASTE_FAIL_ON_REQUEST_FAILURE === '1';
const authMode = process.env.E2E_TASTE_AUTH_MODE || 'mock';
const writeResults = process.env.E2E_TASTE_WRITE_RESULTS !== '0';
const viewport = {
  width: Number.parseInt(process.env.E2E_TASTE_VIEWPORT_WIDTH || '1440', 10),
  height: Number.parseInt(process.env.E2E_TASTE_VIEWPORT_HEIGHT || '1000', 10),
};

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
  const states = keys.map((pageKey) => ({
    label: pageKey,
    pageKey,
    expectedPageKey: canonicalPageAliases.get(pageKey) || pageKey,
  }));

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

function buildMockApiData(requestUrl) {
  const url = new URL(requestUrl);
  const apiPath = url.pathname.replace(/^.*\/api/, '');
  const hotel = { id: 1, tenant_id: 999001, name: '视觉验证门店', hotel_name: '视觉验证门店' };

  if (apiPath === '/auth/info') {
    return {
      id: 999001,
      username: 'taste_visual_probe',
      realname: 'Taste Visual Probe',
      role_id: 1,
      role_name: '超级管理员',
      hotel_id: 1,
      tenant_id: 999001,
      context: { tenantId: 999001, hotelId: 1, currentHotelName: hotel.name, permissionStatus: 'allowed', platform: 'ctrip' },
      hotel,
      is_super_admin: true,
      is_hotel_manager: true,
      permitted_hotels: [hotel],
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
    };
  }
  if (apiPath === '/hotels') {
    return {
      list: [hotel],
      pagination: { page: 1, page_size: 100, total: 1, total_page: 1 },
    };
  }
  if (apiPath === '/hotels/all') return [hotel];
  if (apiPath === '/dashboard/revenue-facts') return {
    hotel: { system_hotel_id: Number(url.searchParams.get('hotel_id') || hotel.id) },
    business_date: url.searchParams.get('business_date'),
    status: 'missing',
  };
  if (['/operation/execution-flow', '/operation/my-tasks'].includes(apiPath)) return {
    data_status: 'ok', list: [],
    capabilities: { hotel_id: Number(url.searchParams.get('hotel_id') || hotel.id) },
    scope: { hotel_id: Number(url.searchParams.get('hotel_id') || hotel.id) },
  };
  if (apiPath === '/operation/closure-overview') return {
    summary: { status: 'unverified', authoritative_state: 'not_started', readback_verified: false },
    modules: [], weak_modules: [], process_weak_modules: [], roi_weak_modules: [],
    data_status: 'data_gap', data_gaps: ['visual_smoke_fixture_only'],
    operating_loop: { authoritative_state: 'not_started', readback_verified: false },
    source_scope: 'hotel_operating_cycle_kernel_only',
  };
  if (apiPath === '/operation/action-tracking') return {
    actions: [], effect_validation: { status: 'data_gap', metrics: [], data_gaps: ['visual_smoke_fixture_only'], action_counts: {} },
  };
  if (apiPath === '/operation/goal-intervention-overview') return {
    hotel_id: Number(url.searchParams.get('hotel_id') || hotel.id),
    data_status: 'data_gap', current_goal_contract: null, goal_contracts: [], interventions: [], assessments: [],
    summary: {}, data_gaps: ['visual_smoke_fixture_only'],
  };
  if (apiPath === '/users') {
    return {
      list: [],
      pagination: { page: 1, page_size: 100, total: 0, total_page: 1 },
    };
  }
  if (apiPath === '/online-data/manual-fetch-evidence') {
    return {
      target_date: url.searchParams.get('target_date') || '',
      rows: [],
    };
  }
  return [];
}

async function login(page) {
  if (authMode === 'mock') {
    await page.route('**/api/**', async (route) => {
      const url = route.request().url();
      const pathname = new URL(url).pathname;
      const data = pathname.endsWith('/api/auth/info')
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
        : pathname.endsWith('/api/hotels')
          ? [{ id: 1, name: '视觉验证门店', hotel_name: '视觉验证门店' }]
          : pathname.endsWith('/api/online-data/manual-fetch-evidence')
            ? { rows: [] }
          : [];

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 200,
          message: 'ok',
          data: buildMockApiData(route.request().url()),
        }),
      });
    });
    await page.addInitScript(() => sessionStorage.setItem('token', 'taste-visual-probe-token'));
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
  await waitForVisualAssets(page);
  await page.waitForFunction(() => {
    const root = document.querySelector('#app');
    const proxy = (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
    return proxy?.authContext?.permissionStatus === 'allowed'
      && !proxy.homeOperatingScheduleLoading && !proxy.homeWeeklyOperatingPlanLoading
      && !proxy.homeRevenueFactLayerLoading && !proxy.revenueAiOverviewLoading;
  }, null, { timeout: 15000 });
}

async function waitForVisualAssets(page) {
  await page.waitForFunction(() => {
    const fullRenderReady = document.documentElement.dataset.suxiFullRenderReady === '1';
    const assets = JSON.parse(document.querySelector('#suxi-authenticated-assets')?.textContent || '[]');
    const requiredStyles = assets.filter((asset) => asset.type === 'style'
      && (asset.phase !== 'after-first-paint' || fullRenderReady));
    const styles = [...document.querySelectorAll('link[rel="stylesheet"]')];
    return requiredStyles.every((asset) => styles.some((link) => link.href === new URL(asset.src, location.href).href && link.sheet))
      && !!document.querySelector('link[data-suxi-fontawesome="1"]')?.sheet;
  }, null, { timeout: 15000 });
  await page.evaluate(async () => {
    let timeout;
    try {
      await Promise.race([
        document.fonts.ready,
        new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Visual smoke font loading timed out')), 5000); }),
      ]);
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    } finally {
      clearTimeout(timeout);
    }
  });
}

async function setCurrentState(page, state) {
  await page.waitForFunction(() => {
    const root = document.querySelector('#app');
    const proxy = (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
    return proxy?.authContext?.permissionStatus === 'allowed';
  }, null, { timeout: 10000 });
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

    await proxy.$nextTick();
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    return true;
  }, state);

  if (!changed) {
    throw new Error('Vue root proxy is not available for page switching');
  }
  await page.waitForFunction((targetState) => {
    const root = document.querySelector('#app');
    const proxy = (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
    const activePage = document.querySelector('[data-testid="app-main"]')?.getAttribute('data-current-page');
    if (activePage !== (targetState.expectedPageKey || targetState.pageKey)) return false;
    if (document.querySelector('[data-testid="deferred-page-loading"]')) return false;
    if (targetState.pageKey === 'ops-track' && (proxy?.operationLoading?.actions
      || !proxy?.operationExecutionFlow?.data_status || proxy.operationExecutionFlow.data_status === 'loading')) return false;
    return true;
  }, state, { timeout: 15000 });
  await waitForVisualAssets(page);
}

async function inspectPage(page, state) {
  await setCurrentState(page, state);

  const result = await page.evaluate((targetState) => {
    const root = document.querySelector('#app');
    const component = root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component;
    const toastState = component?.proxy?.toast;
    const operationReadError = targetState.pageKey === 'ops-track'
      ? [component?.proxy?.operationError?.actions, component?.proxy?.operatingGoalInterventionError].filter(Boolean).join(' | ')
      : '';
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
    const icons = [...document.querySelectorAll('.fa, .fas, .far, .fab, .fa-solid, .fa-regular')]
      .filter((element) => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden');

    return {
      label: targetState.label,
      pageKey: targetState.pageKey,
      expectedPageKey: targetState.expectedPageKey || targetState.pageKey,
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
      hasVisibleToast: toastState?.show === true,
      toastMessage: toastState?.show === true ? String(toastState.message || '') : '',
      visiblePanels: panels.length,
      visibleControls: controls.length,
      horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
      mainHorizontalOverflow: !!main && main.scrollWidth > main.clientWidth + 2,
      bodyHorizontalOverflow: !!body && body.scrollWidth > body.clientWidth + 2,
      fontAwesomeReady: icons.every((icon) => {
        const style = getComputedStyle(icon);
        return /Font\s*Awesome/i.test(style.fontFamily)
          && document.fonts.check(`${style.fontWeight} ${style.fontSize} ${style.fontFamily}`);
      }),
      operationReadError,
      homeStylesApplied: targetState.pageKey !== 'compass' || (
        getComputedStyle(document.querySelector('.home-workspace-toolbar')).display === 'flex'
        && Number.parseFloat(getComputedStyle(document.querySelector('.home-workspace-title h2')).fontSize) >= 24
      ),
    };
  }, state);

  if (state.pageKey === 'compass') {
    if (viewport.width >= 900) {
      const group = page.getByRole('button', { name: '运营自动化中心', exact: true });
      const initiallyOpen = await group.getAttribute('aria-expanded') === 'true';
      await group.focus();
      if (!initiallyOpen) await page.keyboard.press('Space');
      await page.keyboard.press('Tab');
      result.navigationFocus = await page.evaluate(() => {
        const current = document.activeElement;
        const style = getComputedStyle(current);
        return { childFocused: !!current.closest('.mobile-nav-submenu'),
          role: current.getAttribute('role'), focusVisible: current.matches(':focus-visible'),
          outlineStyle: style.outlineStyle, outlineColor: style.outlineColor };
      });
      if (!initiallyOpen) { await group.focus(); await page.keyboard.press('Space'); }
    }
    await page.getByRole('combobox', { name: '首页门店', exact: true }).focus();
    await page.keyboard.press('Tab');
    result.focusedControl = await page.evaluate(() => {
      const control = document.activeElement;
      const main = document.querySelector('[data-testid="app-main"]');
      const bounds = control.getBoundingClientRect();
      const mainBounds = main.getBoundingClientRect();
      const style = getComputedStyle(control);
      return {
        label: control.getAttribute('aria-label') || '',
        type: control.getAttribute('type') || '',
        focusVisible: control.matches(':focus-visible'),
        outlineStyle: style.outlineStyle,
        outlineWidth: Number.parseFloat(style.outlineWidth),
        visibleInMain: bounds.top >= Math.max(0, mainBounds.top) - 1
          && bounds.bottom <= Math.min(innerHeight, mainBounds.bottom) + 1,
      };
    });
  }

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
  if (result.activePage !== result.expectedPageKey) {
    issues.push(`expected active page ${result.expectedPageKey}, got ${result.activePage || '(empty)'}`);
  }
  if (!result.hasShell) issues.push('missing .suxi-app-shell');
  if (result.hasLogin) issues.push('login page is present during logged-in visual smoke');
  if (!result.hasPageBody) issues.push('missing .suxi-page-body');
  if (result.textLength < 20) issues.push(`page appears empty, textLength=${result.textLength}`);
  if (result.hasRawVueTemplate) issues.push('raw Vue template markers are visible');
  if (result.hasVisibleToast) issues.push(`transient toast leaked into page capture: ${result.toastMessage || '(empty)'}`);
  if (result.horizontalOverflow) issues.push('horizontal overflow detected');
  if (result.mainHorizontalOverflow || result.bodyHorizontalOverflow) issues.push('page content requires horizontal scrolling');
  if (!result.fontAwesomeReady) issues.push('FontAwesome glyphs are not ready for visual inspection');
  if (result.operationReadError) issues.push(`operation read failed: ${result.operationReadError}`);
  if (!result.homeStylesApplied) issues.push('homepage critical styles were not applied before inspection');
  if (result.pageKey === 'compass') {
    if (result.navigationFocus && (!result.navigationFocus.childFocused
      || result.navigationFocus.role !== 'button' || !result.navigationFocus.focusVisible
      || result.navigationFocus.outlineStyle === 'none')) {
      issues.push(`navigation keyboard focus is unavailable: ${JSON.stringify(result.navigationFocus)}`);
    }
    const focus = result.focusedControl;
    if (focus?.label !== '经营事实业务日期' || focus?.type !== 'date'
      || !focus?.focusVisible || !focus?.visibleInMain || focus?.outlineStyle === 'none' || !(focus?.outlineWidth > 0)) {
      issues.push(`home keyboard focus is not visible after hotel-to-date Tab: ${JSON.stringify(focus)}`);
    }
  }
  if (result.visiblePanels === 0 && result.visibleControls === 0) {
    issues.push('no visible panels or controls detected');
  }
  return issues;
}

async function main() {
  if (!Number.isInteger(viewport.width) || viewport.width < 320
    || !Number.isInteger(viewport.height) || viewport.height < 480) {
    throw new Error(`Invalid visual smoke viewport: ${viewport.width}x${viewport.height}`);
  }

  if (writeResults || screenshotMode !== 'none') {
    fs.mkdirSync(screenshotsDir, { recursive: true });
  }

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({ viewport });
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
        viewport,
        pageCount: testedPageCount,
        stateCount: visualStates.length,
        results,
        browserEvents,
        failures,
      }, null, 2));
    }
  } catch (error) {
    const state = await page.evaluate(() => {
      const root = document.querySelector('#app');
      const proxy = (root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component)?.proxy;
      return {
        page: proxy?.currentPage,
        permissionStatus: proxy?.authContext?.permissionStatus,
        loading: { home: proxy?.homeOperatingScheduleLoading, facts: proxy?.homeRevenueFactLayerLoading, operations: proxy?.operationLoading?.actions },
        operationStatus: proxy?.operationExecutionFlow?.data_status,
        styles: [...document.querySelectorAll('link[rel="stylesheet"]')].map(link => ({ path: new URL(link.href).pathname, ready: !!link.sheet })),
      };
    });
    console.error(JSON.stringify({ visualFailureState: state, pageErrors: browserEvents.pageErrors }, null, 2));
    throw error;
  } finally {
    await browser.close();
  }

  if (failures.length > 0) {
    console.error(failures.join('\n'));
    process.exit(1);
  }

  console.log(`Taste visual smoke passed (${testedPageCount} logged-in page keys, ${visualStates.length} visual states, auth=${authMode}).`);
}

main().catch((error) => {
  console.error(error?.message || error);
  process.exit(1);
});
