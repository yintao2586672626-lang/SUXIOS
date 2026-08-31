const { test, expect } = require('@playwright/test');
const path = require('node:path');
const { getConfig, login } = require('./e2e-helpers');

const root = path.resolve(__dirname, '../..');

test('operating finance control center renders all seven truthful modules with runtime-only Vue', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.setContent('<div id="app"></div>');
  await page.addScriptTag({ path: path.join(root, 'public/vue.runtime.global.prod.js') });
  await page.addScriptTag({ path: path.join(root, 'public/components/system/operating-finance-control-center.min.js') });
  await page.evaluate(() => {
    const request = async (url) => {
      if (String(url).startsWith('/operating-finance/settlements/import')) {
        const batchStatus = String(window.__settlementImportStatus || 'partial');
        const invalid = batchStatus === 'invalid';
        return {
          code: 200,
          message: invalid
            ? '结算失败尝试已留痕并精确回读；未形成可用净收入事实，也未写入OTA、PMS或财务系统'
            : '结算批次已保存并精确回读，但仅部分可用；请按缺口修正后再用于经营判断',
          data: {
            readback_verified: true,
            request_status: 'saved_and_readback_verified',
            business_result_status: batchStatus,
            business_success: false,
            batch_status: batchStatus,
            totals: { net_revenue: { value: invalid ? null : 850 } },
            lines: invalid ? [{ gap_codes: ['commission_amount_basis_invalid'] }] : [],
          },
        };
      }
      if (!String(url).startsWith('/operating-finance/overview')) {
        return { code: 200, data: { id: 901, readback_verified: true } };
      }
      const selectedHotelId = Number(new URL(String(url), 'http://local.test').searchParams.get('hotel_id') || 80);
      return {
        code: 200,
        data: {
          contract_version: 'operating_finance_control_center.v1',
          tenant_id: 7,
          hotel_id: selectedHotelId,
          hotel_name: selectedHotelId === 81 ? '验收二店' : '验收酒店',
          business_date: '2026-08-30',
          period_month: '2026-08',
          stay_date: '2026-08-31',
          platform: 'ctrip',
          settlement: {
            batch_status: 'available',
            totals: {
              gross_amount: { value: 1000 }, commission_amount: { value: 150 }, net_revenue: { value: 850 },
            },
            basis_ledger: { components: {
              order_gross_amount: { value: 1000, basis: 'source_direct' },
              commission_amount: { value: 150, basis: 'source_direct' },
              refund_amount: { value: 0, basis: 'source_direct' },
              adjustment: { value: 0, basis: 'source_direct', component_scope: 'platform_subsidy_only' },
              settlement_amount: { value: 850, basis: 'source_direct' },
              net_revenue: { value: 850, basis: 'derived_gross_minus_commission' },
            } },
            recovery_blocker: { status: 'blocked', selected: { reason_code: 'settlement_reconciliation_discrepancy', title: '复核当前账期金额差异最大的结算行' } },
            ranked_discrepancies: [{ source_line_no: 1, discrepancy_basis: 'source_direct_net_revenue', discrepancy_amount: 50 }],
          },
          recovery: {
            status: 'blocked',
            selected: {
              source_label: '主 PMS', category_label: '需要人工登录或验证', business_impact: 'critical',
              reason: '当前授权会话已失效。', next_action: '请在原设备完成登录后重跑原只读检查。', resumable: true,
            },
          },
          booking_pace: {
            status: 'ready', net_pickup_room_nights: 2, gross_pickup_room_nights: 3,
            pickup_room_nights_per_hour: 1, cancellation_rate_percent: 15.38, data_gaps: [],
          },
          booking_demand_plan: {
            contract_version: 'booking_demand_plan.v1', status: 'partial', requested_horizons: [1, 3, 7],
            windows: [
              { window_key: 'tomorrow', label: '明天', status: 'ready', start_date: '2026-08-31', end_date: '2026-08-31', day_count: 1, snapshot_coverage_days: 1, pickup_coverage_days: 1, on_books_room_nights_total: 12, observed_on_books_room_nights: 12, net_pickup_room_nights_total: 2, observed_net_pickup_room_nights: 2, on_books_room_revenue_total: 1260, event_count: 0, data_gaps: [] },
              { window_key: 'next_3_days', label: '未来3天', status: 'partial', start_date: '2026-08-31', end_date: '2026-09-02', day_count: 3, snapshot_coverage_days: 2, pickup_coverage_days: 1, on_books_room_nights_total: null, observed_on_books_room_nights: 20, net_pickup_room_nights_total: null, observed_net_pickup_room_nights: 2, on_books_room_revenue_total: null, event_count: 1, data_gaps: ['window_snapshot_coverage_incomplete'] },
              { window_key: 'next_7_days', label: '未来7天', status: 'partial', start_date: '2026-08-31', end_date: '2026-09-06', day_count: 7, snapshot_coverage_days: 2, pickup_coverage_days: 1, on_books_room_nights_total: null, observed_on_books_room_nights: 20, net_pickup_room_nights_total: null, observed_net_pickup_room_nights: 2, on_books_room_revenue_total: null, event_count: 1, data_gaps: ['window_snapshot_coverage_incomplete'] },
            ],
          },
          demand_calendar: {
            status: 'ready', events: [{ id: 1, event_name: '会展', event_start_date: '2026-09-01', event_end_date: '2026-09-02', area_label: '周边', source_status: 'reference_only' }],
          },
          wecom_task_receipt: { status: 'sender_mapping_and_verified_event_required', receipt_count: 0 },
          monthly_finance: {
            status: 'ready', source: { source_quality_status: 'operator_attested', currency: 'CNY', tax_basis: 'tax_inclusive' }, results: { fact_scope: 'whole_hotel', recognized_revenue: 12000, total_operating_revenue: 12000, gop: 7000, gop_margin_percent: 58.33, owner_cash_proxy_before_tax_capex_and_financing: 5500, budget_total_operating_revenue_variance: 1000, budget_gop_variance: 500 }, missing_items: [],
          },
          portfolio: {
            status: 'ready', ranking_status: 'same_scope_manual_snapshot_comparable', items: [
              { hotel_id: 80, hotel_name: '验收酒店', fact_scope: 'whole_hotel', source_quality_status: 'operator_attested', tax_basis: 'tax_inclusive', status: 'ready', gop: 7000, gop_margin_percent: 58.33, rank: 1 },
              { hotel_id: 81, hotel_name: '验收二店', fact_scope: 'whole_hotel', source_quality_status: 'operator_attested', tax_basis: 'tax_inclusive', status: 'ready', gop: 5000, gop_margin_percent: 42.5, rank: 2 },
            ],
          },
          boundaries: { automatic_approval: false, automatic_external_send: false, automatic_ota_write: false, automatic_pms_write: false, external_write_count: 0 },
        },
      };
    };
    const component = window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody;
    const app = Vue.createApp({
      render() {
        return Vue.h(component, {
          hotels: [{ id: 80, name: '验收酒店' }, { id: 81, name: '验收二店' }],
          request,
          selectedHotelId: 80,
          canExecute: true,
        });
      },
    });
    window.__financeRequest = request;
    window.__financeComponent = component;
    window.__financeApp = app;
    app.mount('#app');
  });

  await expect(page.getByTestId('operating-finance-control-center')).toBeVisible();
  await expect(page.getByText('经营财务与恢复中心')).toBeVisible();
  await expect(page.getByTestId('operating-finance-settlement')).toContainText('¥850');
  await expect(page.getByTestId('operating-finance-settlement')).toContainText('结算金额不自动等于净收入');
  await expect(page.getByTestId('operating-finance-settlement')).toContainText('仅当前OTA渠道，不代表全酒店GOP');
  await expect(page.getByTestId('operating-finance-settlement-recovery-candidate')).toContainText('唯一恢复事项');
  await page.getByTestId('operating-finance-tab-recovery').click();
  await expect(page.getByTestId('operating-finance-recovery')).toContainText('原设备完成登录');
  await page.getByTestId('operating-finance-tab-booking').click();
  await expect(page.getByTestId('operating-finance-booking-demand-plan')).toContainText('明天');
  await expect(page.getByTestId('operating-finance-booking-demand-plan')).toContainText('未来3天');
  await expect(page.getByTestId('operating-finance-booking-demand-plan')).toContainText('未来7天');
  await expect(page.getByTestId('operating-finance-booking-demand-plan')).toContainText('快照 1/1天');
  await expect(page.getByTestId('operating-finance-booking-demand-plan')).toContainText('未形成完整合计');
  await expect(page.getByTestId('operating-finance-booking')).toContainText('3 间夜');
  await page.getByTestId('operating-finance-tab-demand').click();
  await expect(page.getByTestId('operating-finance-demand')).toContainText('仅作参考');
  await page.getByTestId('operating-finance-tab-wecom').click();
  await expect(page.getByTestId('operating-finance-wecom')).toContainText('回执不等于审批');
  await page.getByTestId('operating-finance-tab-finance').click();
  await expect(page.getByTestId('operating-finance-monthly')).toContainText('¥7,000');
  await expect(page.getByTestId('operating-finance-monthly')).toContainText('收入较预算');
  const roomRevenue = page.getByPlaceholder('输入客房经营收入');
  await roomRevenue.fill('12345');
  await page.getByTestId('operating-finance-control-center').locator('select').first().selectOption('81');
  await expect(roomRevenue).toHaveValue('');
  await page.getByTestId('operating-finance-tab-portfolio').click();
  await expect(page.getByTestId('operating-finance-portfolio')).toContainText('同口径人工快照可比');

  await page.getByTestId('operating-finance-tab-settlement').click();
  const settlement = page.getByTestId('operating-finance-settlement');
  await settlement.locator('input[type="file"]').setInputFiles({
    name: 'settlement.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('业务日期,金额口径,结算金额\n2026-08-01,settlement,800\n'),
  });
  await expect(settlement).toContainText('当前按文件导入：settlement.csv');
  await settlement.locator('textarea').fill('[{"business_date":"2026-08-01","amount_scope":"settlement","settlement_amount":800}]');
  await expect(settlement).not.toContainText('当前按文件导入：settlement.csv');

  await settlement.locator('input[type="file"]').setInputFiles({
    name: 'invalid-settlement.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('业务日期,金额口径,结算金额\n2026-08-01,settlement,invalid\n'),
  });
  await page.evaluate(() => { window.__settlementImportStatus = 'invalid'; });
  await settlement.getByRole('button', { name: '导入结算批次' }).click();
  await expect(page.getByTestId('operating-finance-settlement-import-notice')).toContainText('未形成可用净收入事实');
  await expect(page.getByTestId('operating-finance-settlement-import-notice')).toContainText('commission_amount_basis_invalid');

  await settlement.locator('input[type="file"]').setInputFiles({
    name: 'partial-settlement.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from('业务日期,金额口径,结算金额\n2026-08-01,settlement,800\n'),
  });
  await page.evaluate(() => { window.__settlementImportStatus = 'partial'; });
  await settlement.getByRole('button', { name: '导入结算批次' }).click();
  await expect(page.getByTestId('operating-finance-settlement-import-notice')).toContainText('结算数据仅部分可用');

  await page.getByTestId('operating-finance-tab-portfolio').click();
  await page.setViewportSize({ width: 393, height: 734 });
  await expect(page.getByTestId('operating-finance-portfolio')).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);

  await page.evaluate(() => {
    window.__financeApp.unmount();
    document.querySelector('#app').innerHTML = '';
    const app = Vue.createApp({
      render() {
        return Vue.h(window.__financeComponent, {
          hotels: [{ id: 80, name: '验收酒店' }],
          request: window.__financeRequest,
          selectedHotelId: 80,
          canExecute: false,
        });
      },
    });
    window.__financeApp = app;
    app.mount('#app');
  });
  await expect(page.getByTestId('operating-finance-view-only')).toContainText('只有查看权限');
  await expect(page.getByTestId('operating-finance-settlement').locator('form')).toHaveCount(0);
  await page.getByTestId('operating-finance-tab-booking').click();
  await expect(page.getByTestId('operating-finance-booking').locator('form')).toHaveCount(0);
  await page.getByTestId('operating-finance-tab-demand').click();
  await expect(page.getByTestId('operating-finance-demand').locator('form')).toHaveCount(0);
  await page.getByTestId('operating-finance-tab-finance').click();
  await expect(page.getByTestId('operating-finance-monthly').locator('form')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('isolated authenticated app opens operating finance and reads the scoped overview', async ({ page }) => {
  test.setTimeout(60000);
  test.skip(
    process.env.SUXI_E2E_ISOLATED_RUNNER !== '1',
    'live acceptance requires the dedicated isolated runner',
  );
  const config = getConfig();
  const errors = [];
  const warnings = [];
  const overviewResponses = [];
  page.on('pageerror', error => errors.push(`page:${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error') {
      errors.push(`console-${message.type()}:${message.text()}`);
    } else if (['warning', 'warn'].includes(message.type())) {
      warnings.push(`console-${message.type()}:${message.text()}`);
    }
  });
  page.on('response', response => {
    if (new URL(response.url()).pathname === '/api/operating-finance/overview') {
      overviewResponses.push(response);
    }
  });

  await login(page, config);
  const nav = page.getByTestId('app-nav');
  const target = nav.getByTestId('nav-operating-finance');
  if (!(await target.isVisible().catch(() => false))) {
    await nav.getByRole('button', { name: '经营分析', exact: true }).click();
  }
  await target.waitFor({ state: 'visible', timeout: 5000 });
  await target.click();
  try {
    await page.getByTestId('operating-finance-control-center').waitFor({
      state: 'visible',
      timeout: 20000,
    });
  } catch (error) {
    const renderState = await page.evaluate(() => ({
      current_page: document.querySelector('[data-testid="app-main"]')?.dataset.currentPage || '',
      render_phase: document.documentElement.dataset.suxiRenderPhase || '',
      full_render_ready: document.documentElement.dataset.suxiFullRenderReady || '',
      interactive_error: document.documentElement.dataset.suxiAuthenticatedInteractiveError || '',
      startup_error: document.getElementById('app')?.dataset.startupErrorRendered || '',
      asset_error: document.getElementById('app')?.dataset.assetErrorRendered || '',
      app_render: typeof window.SUXI_APP_RENDER,
      registered_component: Boolean(
        document.getElementById('app')?.__vue_app__?._component?.components?.OperatingFinanceControlCenter,
      ),
      component_factory: typeof window.SUXI_APP_MAIN_COMPONENTS_FULL?.create,
      component_source: Boolean(window.SUXI_SYSTEM_COMPONENTS?.OperatingFinanceControlCenterBody),
      main_html_tail: String(document.querySelector('[data-testid="app-main"]')?.innerHTML || '').slice(-1200),
    }));
    throw new Error(
      `operating finance full render did not mount: ${JSON.stringify(renderState)}; errors=${JSON.stringify(errors)}; warnings=${JSON.stringify(warnings)}`,
      { cause: error },
    );
  }
  await expect.poll(() => overviewResponses.length, { timeout: 10000 }).toBeGreaterThan(0);
  const overviewResponse = overviewResponses.at(-1);
  expect(new URL(overviewResponse.url()).pathname).toBe('/api/operating-finance/overview');
  expect(overviewResponse.request().method()).toBe('GET');
  expect(overviewResponse.status()).toBe(200);
  const overview = await overviewResponse.json();
  expect(overview.code).toBe(200);
  expect(overview.data?.contract_version).toBe('operating_finance_control_center.v1');
  expect(Number(overview.data?.hotel_id || 0)).toBe(config.hotelId);
  expect(Number(overview.data?.boundaries?.external_write_count ?? -1)).toBe(0);

  await expect(page.getByTestId('app-main')).toHaveAttribute(
    'data-current-page',
    'operating-finance',
    { timeout: 10000 },
  );
  await expect(page.getByTestId('operating-finance-control-center')).toBeVisible();
  await expect(page.getByText('经营财务与恢复中心')).toBeVisible();
  for (const tabName of ['settlement', 'recovery', 'booking', 'demand', 'wecom', 'finance', 'portfolio']) {
    const tabButton = page.getByTestId(`operating-finance-tab-${tabName}`);
    await expect(tabButton).toBeVisible();
    await tabButton.click();
    const panelTestId = tabName === 'finance'
      ? 'operating-finance-monthly'
      : `operating-finance-${tabName}`;
    await expect(page.getByTestId(panelTestId)).toBeVisible();
  }
  await expect(page.locator('#app[data-asset-error-rendered="1"]')).toHaveCount(0);
  expect(errors).toEqual([]);
  expect(warnings.filter(message => /operating-finance|经营财务|净收与恢复/i.test(message))).toEqual([]);
});
