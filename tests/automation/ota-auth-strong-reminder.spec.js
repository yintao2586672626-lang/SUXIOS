const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

test('OTA auth failure strongly reminds only the direct store submitter', async ({ page }) => {
  test.setTimeout(45000);
  let clearRequestCount = 0;
  const user = {
    id: 155,
    username: 'mock_submitter',
    realname: '门店提交人',
    role_name: '门店负责人',
    is_super_admin: true,
    permissions: { can_manage_own_hotels: true, can_fetch_online_data: true },
    permitted_hotels: [{ id: 80, name: '测试门店80', status: 1 }],
    context: { token_status: 'valid', permitted_hotel_ids: [80] },
  };

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'ui-test-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
  }, user);

  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    if (pathname === '/api/auth/info') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 200, data: user }) });
      return;
    }
    if (pathname === '/api/notifications' || pathname === '/api/notifications/') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 200,
          data: {
            list: [{
              id: 1834,
              notification_id: 'system-notification-1834',
              hotel_id: 80,
              hotel_name: '测试门店80',
              platform: 'meituan',
              category: 'ota_auth_required',
              category_label: '登录失效强提醒',
              severity: 'error',
              title: '美团登录授权已失效',
              detail: '该门店的美团登录授权已失效，请由提交人重新登录并验证。',
              is_read: false,
              updated_at: '2026-07-14 19:00:00',
              action_label: '重新登录并验证',
              target_page: 'online-data',
              target_tab: 'data-health',
              requires_resolution: true,
              reminder_level: 'strong',
              is_direct_recipient: true,
              reason_code: 'login_expired',
            }],
            total: 1,
            unread_count: 1,
            poll_interval_ms: 120000,
          },
        }),
      });
      return;
    }
    if (pathname === '/api/notifications/clear') {
      clearRequestCount++;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data: { list: [], items: [], total: 0 }, message: 'ok' }),
    });
  });

  await page.goto('http://127.0.0.1:8080/', { waitUntil: 'domcontentloaded' });

  const banner = page.getByTestId('ota-auth-strong-reminder-banner');
  const modal = page.getByTestId('ota-auth-strong-reminder-modal');
  await expect(modal).toBeVisible({ timeout: 15000 });
  await expect(banner).toBeVisible();
  await expect(modal).toContainText('测试门店80');
  await expect(modal).toContainText('美团');
  await expect(modal.getByRole('button', { name: '重新登录并验证' })).toBeVisible();

  const deferButton = page.getByTestId('ota-auth-strong-reminder-defer');
  await expect(deferButton).toBeDisabled();
  await expect(deferButton).toHaveText('请先查看 3 秒');
  await expect(deferButton).toBeEnabled({ timeout: 6000 });
  await deferButton.click();
  await expect(modal).toBeHidden();
  await expect(banner).toBeVisible();

  await page.locator('button[title*="采集通知"]').click();
  await page.getByRole('button', { name: '全部清空' }).click();
  await expect(banner).toBeVisible();
  await expect.poll(() => clearRequestCount).toBe(0);

  await banner.getByRole('button', { name: '立即处理' }).click();
  await expect(modal).toBeVisible();
});
