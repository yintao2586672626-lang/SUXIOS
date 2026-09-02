const { test, expect } = require('@playwright/test');

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8134/';

test('operating finance lazy component renders inside the authenticated SUXIOS shell', async ({ page }) => {
  const consoleErrors = [];
  const failedRequests = [];
  page.on('pageerror', error => consoleErrors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('requestfailed', request => failedRequests.push(`${request.url()} :: ${request.failure()?.errorText || 'failed'}`));

  await page.route('**/api/**', async route => {
    const pathname = new URL(route.request().url()).pathname;
    let data = [];
    if (pathname.endsWith('/api/auth/info')) {
      data = {
        id: 999001,
        username: 'operating_finance_shell_probe',
        realname: 'Operating Finance Shell Probe',
        role_id: 1,
        role_name: '超级管理员',
        hotel_id: 1,
        hotel: { id: 1, name: '视觉验证门店' },
        is_super_admin: true,
        is_hotel_manager: true,
        permitted_hotels: [{ id: 1, name: '视觉验证门店' }],
        permissions: { can_view_online_data: true, can_fetch_online_data: true },
        context: {
          hotelId: 1,
          tenantId: 1,
          platform: 'all',
          currentHotelName: '视觉验证门店',
          tokenStatus: 'valid',
          permissionStatus: 'allowed',
          fetchPermissionStatus: 'allowed',
        },
      };
    } else if (pathname.endsWith('/api/hotels')) {
      data = [{ id: 1, name: '视觉验证门店', hotel_name: '视觉验证门店' }];
    } else if (pathname.endsWith('/api/operating-finance/overview')) {
      data = {
        contract_version: 'operating_finance_control_center.v1',
        tenant_id: 1,
        hotel_id: 1,
        hotel_name: '视觉验证门店',
        business_date: '2026-08-31',
        period_month: '2026-08',
        stay_date: '2026-09-01',
        platform: 'ctrip',
        settlement: { status: 'missing', batch_status: 'missing', totals: {}, ranked_discrepancies: [] },
        recovery: { status: 'evidence_missing', selected: null },
        booking_pace: { status: 'blocked', data_gaps: ['verified_on_books_snapshot_missing'] },
        booking_demand_plan: { contract_version: 'booking_demand_plan.v1', status: 'blocked', requested_horizons: [1, 3, 7], windows: [] },
        demand_calendar: { status: 'empty', events: [] },
        wecom_task_receipt: { status: 'sender_mapping_and_verified_event_required', receipt_count: 0 },
        monthly_finance: { status: 'missing', source: {}, results: {}, missing_items: [] },
        portfolio: { status: 'blocked', ranking_status: 'blocked_incomplete_or_mixed_scope', items: [] },
        boundaries: {
          automatic_approval: false,
          automatic_external_send: false,
          automatic_ota_write: false,
          automatic_pms_write: false,
          external_write_count: 0,
        },
      };
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, message: 'ok', data }),
    });
  });
  await page.addInitScript(() => sessionStorage.setItem('token', 'operating-finance-shell-probe-token'));
  await page.goto(baseURL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.getByTestId('app-main').waitFor({ state: 'visible', timeout: 15000 });
  const switched = await page.evaluate(async () => {
    const root = document.querySelector('#app');
    const component = root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component;
    const proxy = component?.proxy;
    if (!proxy) return false;
    proxy.currentPage = 'operating-finance';
    await new Promise(resolve => setTimeout(resolve, 100));
    return true;
  });
  expect(switched).toBe(true);

  const outcome = await Promise.race([
    page.getByTestId('operating-finance-control-center').waitFor({ state: 'visible', timeout: 10000 }).then(() => 'ready'),
    page.getByTestId('operating-finance-control-center-load-error').waitFor({ state: 'visible', timeout: 10000 }).then(() => 'load_error'),
  ]).catch(() => 'timeout');
  if (outcome !== 'ready') {
    const diagnostics = await page.evaluate(() => {
      const root = document.querySelector('#app');
      const component = root?._vnode?.component || root?.__vue_app__?._container?._vnode?.component;
      return ({
      renderPhase: document.documentElement.dataset.suxiRenderPhase || '',
      fullRenderReady: document.documentElement.dataset.suxiFullRenderReady || '',
      loadingCount: document.querySelectorAll('[data-testid="operating-finance-control-center-loading"]').length,
      errorCount: document.querySelectorAll('[data-testid="operating-finance-control-center-load-error"]').length,
      customElementCount: document.querySelectorAll('operating-finance-control-center').length,
      pageBodyHtml: document.querySelector('.suxi-page-body')?.innerHTML?.slice(0, 1200) || '',
      scriptRows: [...document.querySelectorAll('script[src*="operating-finance"]')].map(script => ({
        src: script.getAttribute('src'),
        loaded: script.dataset.loaded || '',
      })),
      registered: !!window.SUXI_SYSTEM_COMPONENTS?.OperatingFinanceControlCenterBody,
      rootRegistered: !!document.querySelector('#app')?.__vue_app__?._context?.components?.OperatingFinanceControlCenter,
      componentRegistered: !!component?.type?.components?.OperatingFinanceControlCenter,
      componentRegisteredType: typeof component?.type?.components?.OperatingFinanceControlCenter,
      componentKeys: Object.keys(component?.type?.components || {}).filter(key => /Operating|Finance/i.test(key)),
      factoryHasComponent: !!window.SUXI_APP_MAIN_COMPONENTS?.create?.({ Vue, h: Vue.h })?.OperatingFinanceControlCenter,
      factoryComponentType: typeof window.SUXI_APP_MAIN_COMPONENTS?.create?.({ Vue, h: Vue.h })?.OperatingFinanceControlCenter,
      defineAsyncComponentType: typeof Vue?.defineAsyncComponent,
      factoryKeys: Object.keys(window.SUXI_APP_MAIN_COMPONENTS?.create?.({ Vue, h: Vue.h }) || {}).filter(key => /Operating|Finance/i.test(key)),
    });
    });
    throw new Error(JSON.stringify({ outcome, diagnostics, consoleErrors, failedRequests }, null, 2));
  }
  await expect(page.getByTestId('operating-finance-control-center')).toBeVisible();
  await expect(page.getByTestId('operating-finance-control-center-load-error')).toHaveCount(0);
  await expect(page.getByText('经营财务与恢复中心')).toBeVisible();
  expect(failedRequests.filter(row => row.includes('operating-finance-control-center'))).toEqual([]);
  expect(consoleErrors.filter(message => /operating-finance|OperatingFinance|经营财务与恢复中心|主应用完整领域组件缺少/.test(message))).toEqual([]);
});
