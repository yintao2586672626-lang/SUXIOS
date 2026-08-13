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
  test.setTimeout(45000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  const deferredAssetNames = [
    'ctrip-search-opportunity-static.js',
    'user-admin-static.js',
    'app-render.min.js',
  ];
  const deferredRequestCounts = new Map(deferredAssetNames.map(name => [name, 0]));
  page.on('request', request => {
    const assetName = new URL(request.url()).pathname.split('/').at(-1);
    if (deferredRequestCounts.has(assetName)) {
      deferredRequestCounts.set(assetName, deferredRequestCounts.get(assetName) + 1);
    }
  });

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
      '自动采集任务': '昨日经营闭环',
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
    { label: '自动采集任务', heading: '昨日经营闭环', groups: ['OTA数据与采集'] },
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
    }
    await expect(page.getByRole('heading', { name: '今日经营看板', exact: true }).first()).toBeVisible({ timeout: 15000 });
    await openTarget(target);
    await expect.poll(() => page.evaluate(() => document.documentElement.dataset.suxiRenderPhase)).toBe('full');
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
