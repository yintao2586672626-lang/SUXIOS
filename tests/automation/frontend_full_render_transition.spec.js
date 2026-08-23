const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

function isolatedAppUrl(value) {
  const parsed = new URL(value);
  const allowedHosts = new Set(['127.0.0.1', 'localhost', '[::1]', '::1']);
  if (parsed.protocol !== 'http:'
    || !allowedHosts.has(parsed.hostname.toLowerCase())
    || parsed.username
    || parsed.password
    || parsed.pathname !== '/'
    || parsed.search
    || parsed.hash) {
    throw new Error('Full-render transition verification only permits an unauthenticated HTTP loopback root URL');
  }
  return parsed.toString();
}

const appUrl = isolatedAppUrl(process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/');
const FULL_RENDER_PROMOTION_TIMEOUT_MS = 15000;

const user = {
  id: 701,
  username: 'transition_probe',
  realname: 'Transition Probe',
  role_name: 'Administrator',
  is_super_admin: true,
  permissions: { can_manage_own_hotels: true, can_fetch_online_data: true },
  capabilities: ['all'],
  hotel_id: 7,
  tenant_id: 7,
  permitted_hotels: [{ id: 7, name: 'Transition Probe Hotel', tenant_id: 7, status: 1 }],
  context: {
    token_status: 'valid',
    tenantId: 7,
    hotelId: 7,
    permitted_hotel_ids: [7],
    permissionStatus: 'allowed',
  },
};

test('five focus pages paint their heading within 300ms on first switch and revisit', async ({ page }) => {
  test.setTimeout(90000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  const deferredAssetNames = [
    'ctrip-search-opportunity-static.js',
    'user-admin-static.js',
    'app-render.min.js',
  ];
  const deferredRequestCounts = new Map(deferredAssetNames.map(name => [name, 0]));
  const deferredManifestAssetNames = new Set();
  const deferredAssetLifecycle = [];
  const transitionStartedAt = Date.now();
  const recordDeferredAssetEvent = (type, request, detail = {}) => {
    const assetName = new URL(request.url()).pathname.split('/').at(-1);
    if (!deferredManifestAssetNames.has(assetName)) return;
    deferredAssetLifecycle.push({
      type,
      assetName,
      elapsedMs: Date.now() - transitionStartedAt,
      ...detail,
    });
  };
  page.on('request', request => {
    const assetName = new URL(request.url()).pathname.split('/').at(-1);
    if (deferredRequestCounts.has(assetName)) {
      deferredRequestCounts.set(assetName, deferredRequestCounts.get(assetName) + 1);
    }
    recordDeferredAssetEvent('request', request);
  });
  page.on('response', response => recordDeferredAssetEvent(
    'response',
    response.request(),
    { status: response.status() },
  ));
  page.on('requestfinished', request => {
    const timing = request.timing();
    recordDeferredAssetEvent('requestfinished', request, {
      responseEndMs: Number(timing?.responseEnd ?? -1),
    });
  });
  page.on('requestfailed', request => recordDeferredAssetEvent(
    'requestfailed',
    request,
    { failure: request.failure()?.errorText || 'unknown' },
  ));

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'transition-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));

    const normalize = value => String(value || '').replace(/\s+/g, ' ').trim();
    const expectedHeadings = {
      '今日经营看板': '今日经营看板',
      '门店管理': '酒店管理',
      '自动采集任务': '线上数据与采集',
      '员工管理': '员工管理',
      '系统配置': '系统配置',
    };
    window.__suxiTransitionMarks = [];
    document.addEventListener('click', event => {
      const control = event.target?.closest?.('button,a,[role=button],[role=menuitem]');
      const label = Object.keys(expectedHeadings).find(item => normalize(control?.textContent).includes(item));
      if (!label) return;
      const mark = { label, startedAt: performance.now(), headingMs: null };
      window.__suxiTransitionMarks.push(mark);
      const scan = () => {
        if (mark.headingMs !== null) return true;
        if (normalize(document.querySelector('main h1')?.textContent) !== expectedHeadings[label]) return false;
        mark.headingMs = Number((performance.now() - mark.startedAt).toFixed(2));
        return true;
      };
      if (scan()) return;
      const observer = new MutationObserver(() => {
        if (scan()) observer.disconnect();
      });
      observer.observe(document.documentElement, { subtree: true, childList: true, characterData: true });
      window.setTimeout(() => observer.disconnect(), 5000);
    }, true);
  }, user);

  await page.route('**/api/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = {
        list: [{ id: 7, name: 'Transition Probe Hotel', tenant_id: 7, status: 1 }],
        total: 1,
      };
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });

  const targets = [
    { label: '门店管理', heading: '酒店管理' },
    { label: '自动采集任务', heading: '线上数据与采集', groups: ['OTA数据与采集'] },
    { label: '员工管理', heading: '员工管理', groups: ['系统与工具', '系统与权限'] },
    { label: '系统配置', heading: '系统配置', groups: ['系统与工具', '系统与权限'] },
  ];
  const evidence = [];
  const visibleTextControl = async label => {
    const controls = page.getByText(label, { exact: true });
    for (let index = 0; index < await controls.count(); index += 1) {
      const candidate = controls.nth(index);
      if (await candidate.isVisible().catch(() => false)) return candidate;
    }
    return null;
  };
  const openTarget = async target => {
    for (const group of target.groups || []) {
      if (await visibleTextControl(target.label)) break;
      const groupControl = await visibleTextControl(group);
      expect(groupControl, `${group} menu group must be visible`).not.toBeNull();
      await groupControl.click();
    }
    const targetControl = await visibleTextControl(target.label);
    expect(targetControl, `${target.label} menu control must be visible`).not.toBeNull();
    await targetControl.click();
    await expect(page.getByRole('heading', { name: target.heading, exact: true }).first()).toBeVisible({ timeout: 5000 });
  };
  const readFullRenderDiagnostics = async () => page.evaluate(() => ({
    renderPhase: document.documentElement.dataset.suxiRenderPhase || '',
    fullRenderReady: document.documentElement.dataset.suxiFullRenderReady || '',
    interactiveError: document.documentElement.dataset.suxiAuthenticatedInteractiveError || '',
    assets: Array.from(document.querySelectorAll('[data-suxi-authenticated-asset]'), node => ({
      name: node.dataset.suxiAuthenticatedAsset || '',
      loaded: node.dataset.suxiAssetLoaded || '',
      failed: node.dataset.suxiAssetFailed || '',
      async: node.tagName === 'SCRIPT' ? Boolean(node.async) : null,
    })),
  })).catch(() => ({ unavailable: true }));
  const waitForFullRender = async () => {
    try {
      await page.waitForFunction(() => (
        document.documentElement.dataset.suxiRenderPhase === 'full'
        || Boolean(document.documentElement.dataset.suxiAuthenticatedInteractiveError)
      ), null, { timeout: FULL_RENDER_PROMOTION_TIMEOUT_MS });
    } catch (error) {
      const diagnostics = await readFullRenderDiagnostics();
      throw new Error(`${error.message}\nfull-render diagnostics: ${JSON.stringify({
        ...diagnostics,
        lifecycle: deferredAssetLifecycle,
      })}`);
    }
    const diagnostics = await readFullRenderDiagnostics();
    if (diagnostics.renderPhase !== 'full') {
      throw new Error(`full-render failed: ${JSON.stringify({
        ...diagnostics,
        lifecycle: deferredAssetLifecycle,
      })}`);
    }
  };

  for (const target of targets) {
    deferredRequestCounts.forEach((_, assetName) => deferredRequestCounts.set(assetName, 0));
    await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
    if (evidence.length === 0) {
      await page.waitForTimeout(1500);
      for (const assetName of deferredAssetNames) {
        expect(deferredRequestCounts.get(assetName), `${assetName} must stay off the startup landing page`).toBe(0);
      }
      const deferredPreloads = await page.evaluate(names => Array.from(
        document.querySelectorAll('link[rel="preload"]'),
        link => new URL(link.href).pathname.split('/').at(-1),
      ).filter(name => names.includes(name)), deferredAssetNames);
      expect(deferredPreloads).toEqual([]);
      const manifestNames = await page.evaluate(() => JSON.parse(
        document.getElementById('suxi-authenticated-assets')?.textContent || '[]',
      ).filter(asset => typeof asset === 'object' && asset?.phase === 'after-first-paint')
        .map(asset => new URL(asset.src, document.baseURI).pathname.split('/').at(-1)));
      manifestNames.forEach(name => deferredManifestAssetNames.add(name));
    }
    await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 15000 });
    await openTarget(target);
    await waitForFullRender();
    const ctripRuntime = await page.evaluate(() => ({
      fullRegistered: Boolean(window.SUXI_CTRIP_STATIC_FULL),
      fullIsActive: window.SUXI_CTRIP_STATIC === window.SUXI_CTRIP_STATIC_FULL,
      fetchFlowType: typeof window.SUXI_CTRIP_STATIC?.runCtripFetchDataFlow,
    }));
    expect(ctripRuntime).toEqual({
      fullRegistered: true,
      fullIsActive: true,
      fetchFlowType: 'function',
    });
    for (const assetName of deferredAssetNames) {
      expect(deferredRequestCounts.get(assetName), `${assetName} must load for the first full-render page`).toBeGreaterThan(0);
    }
    const firstFullRenderRequestCounts = new Map(deferredRequestCounts);
    await page.getByRole('button', { name: '今日经营看板', exact: true }).click();
    await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 5000 });
    await openTarget(target);
    for (const assetName of deferredAssetNames) {
      expect(
        deferredRequestCounts.get(assetName),
        `${assetName} must not be requested again on a same-document revisit`,
      ).toBe(firstFullRenderRequestCounts.get(assetName));
    }

    const marks = await page.evaluate(label => (
      (window.__suxiTransitionMarks || []).filter(mark => mark.label === label)
    ), target.label);
    expect(marks, `${target.label} transition marks`).toHaveLength(2);
    expect(marks[0].headingMs, `${target.label} cold heading: ${JSON.stringify(marks)}`).toBeLessThanOrEqual(300);
    expect(marks[1].headingMs, `${target.label} cached heading: ${JSON.stringify(marks)}`).toBeLessThanOrEqual(300);
    evidence.push({ label: target.label, coldMs: marks[0].headingMs, cachedMs: marks[1].headingMs });
  }

  await page.getByRole('button', { name: '今日经营看板', exact: true }).click();
  await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 5000 });
  const dashboardMark = await page.evaluate(() => (
    (window.__suxiTransitionMarks || []).filter(mark => mark.label === '今日经营看板').at(-1)
  ));
  expect(dashboardMark?.headingMs, `dashboard heading: ${JSON.stringify(dashboardMark)}`).toBeLessThanOrEqual(300);
  evidence.push({ label: '今日经营看板', cachedMs: dashboardMark.headingMs });
  test.info().annotations.push({ type: 'transition-evidence', description: JSON.stringify(evidence) });
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('new-hotel three-source wizard loads after demand and renders the truthful first step', async ({ page }) => {
  test.setTimeout(30000);
  const pageErrors = [];
  let onboardingBundleRequests = 0;
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  page.on('request', request => {
    if (new URL(request.url()).pathname.endsWith('/components/system/app-main-components.js')) {
      onboardingBundleRequests += 1;
    }
  });

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'transition-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
  }, user);

  await page.route('**/api/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = {
        list: [{ id: 7, name: 'Transition Probe Hotel', tenant_id: 7, status: 1 }],
        total: 1,
      };
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 15000 });
  expect(onboardingBundleRequests, 'onboarding component bundle must stay off the landing-page startup path').toBe(0);

  await page.getByText('门店管理', { exact: true }).first().click();
  await expect(page.getByRole('heading', { name: '酒店管理', exact: true }).first()).toBeVisible({ timeout: 5000 });
  expect(onboardingBundleRequests, 'hotel list alone must not load the onboarding component bundle').toBe(0);

  await page.getByRole('button', { name: '新增门店', exact: true }).first().click();
  await expect(page.getByRole('heading', { name: '新增门店接入', exact: true })).toBeVisible({ timeout: 5000 });
  await expect(page.getByTestId('hotel-onboarding-steps')).toContainText('1 门店资料');
  await expect(page.getByTestId('hotel-onboarding-steps')).toContainText('4 完成');
  await expect(page.getByTestId('hotel-onboarding-hotel-step')).toBeVisible();
  await expect(page.getByTestId('hotel-onboarding-hotel-step')).toContainText('创建、授权或保存身份不会自动采集，也不会向企业微信发送消息。');
  await expect(page.getByTestId('hotel-onboarding-create')).toHaveText('创建门店并继续');
  expect(onboardingBundleRequests, 'onboarding component bundle should be requested exactly once on demand').toBe(1);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('a user can retry an exhausted deferred manifest from the visible error surface', async ({ page }) => {
  test.setTimeout(30000);
  const pageErrors = [];
  let failedAssetRequests = 0;
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'transition-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
  }, user);

  await page.route('**/user-admin-static.js*', async route => {
    failedAssetRequests += 1;
    if (failedAssetRequests <= 2) {
      await route.abort('failed');
      return;
    }
    await route.continue();
  });
  await page.route('**/api/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = {
        list: [{ id: 7, name: 'Transition Probe Hotel', tenant_id: 7, status: 1 }],
        total: 1,
      };
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 15000 });
  await page.getByText('门店管理', { exact: true }).first().click();
  await expect(page.getByRole('button', { name: '重试完整资源', exact: true })).toBeVisible({ timeout: 15000 });
  await expect(page.getByText('完整资源加载失败，可在当前页面重试。', { exact: true })).toBeVisible();
  expect(failedAssetRequests).toBe(2);

  await page.getByRole('button', { name: '重试完整资源', exact: true }).click();
  await expect(page.getByRole('heading', { name: '酒店管理', exact: true }).first()).toBeVisible({ timeout: 15000 });
  await expect.poll(
    () => page.evaluate(() => document.documentElement.dataset.suxiRenderPhase || ''),
  ).toBe('full');
  expect(failedAssetRequests).toBe(3);
  expect(await page.evaluate(() => document.documentElement.dataset.suxiAuthenticatedInteractiveError || '')).toBe('');
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('a late classic script executes once when the user recovers after its soft timeout', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  let delayedAssetRequests = 0;
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'transition-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
    window.__suxiLateClassicScriptExecCount = 0;
  }, user);

  await page.route('**/user-admin-static.js*', async route => {
    delayedAssetRequests += 1;
    const response = await route.fetch();
    const body = await response.text();
    await new Promise(resolve => setTimeout(resolve, 6000));
    await route.fulfill({
      response,
      body: `window.__suxiLateClassicScriptExecCount += 1;\n${body}`,
    });
  });
  await page.route('**/api/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = {
        list: [{ id: 7, name: 'Transition Probe Hotel', tenant_id: 7, status: 1 }],
        total: 1,
      };
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 15000 });
  await page.getByText('门店管理', { exact: true }).first().click();
  await expect(page.getByRole('button', { name: '重试完整资源', exact: true })).toBeVisible({ timeout: 15000 });
  await page.getByRole('button', { name: '重试完整资源', exact: true }).click();
  await expect(page.getByRole('heading', { name: '酒店管理', exact: true }).first()).toBeVisible({ timeout: 15000 });
  await expect.poll(
    () => page.evaluate(() => document.documentElement.dataset.suxiRenderPhase || ''),
  ).toBe('full');

  expect(delayedAssetRequests, 'a timeout recovery must reuse the pending canonical request').toBe(1);
  expect(await page.evaluate(() => window.__suxiLateClassicScriptExecCount)).toBe(1);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
