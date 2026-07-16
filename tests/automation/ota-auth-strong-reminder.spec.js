const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

test('OTA Cookie reminder auto-closes while keeping the top banner', async ({ page }) => {
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
  const reminderRows = Array.from({ length: 6 }, (_, index) => ({
    id: 1834 + index,
    notification_id: `system-notification-${1834 + index}`,
    hotel_id: 80 + index,
    hotel_name: `测试门店${80 + index}`,
    platform: index % 2 === 0 ? 'meituan' : 'ctrip',
    category: 'ota_auth_required',
    category_label: '登录失效强提醒',
    severity: 'error',
    title: index % 2 === 0 ? '美团登录授权已失效' : '携程登录授权已失效',
    detail: '该门店的 Cookie 可能已失效，请更新并验证后再采集。',
    is_read: false,
    updated_at: `2026-07-14 19:0${index}:00`,
    action_label: '更新 Cookie',
    target_page: 'online-data',
    target_tab: 'data-health',
    requires_resolution: true,
    reminder_level: 'strong',
    is_direct_recipient: true,
    reason_code: 'login_expired',
    authorization_source_label: `运营账号授权 ${index + 1}`,
    authorization_source_type: index % 2 === 0 ? 'cookie_api' : 'profile',
    authorization_source_state: 'exact',
    authorization_source_note: '来源详情不应在强提醒卡片中展开显示。',
    data_source_id: 208 + index,
  }));

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
            list: reminderRows,
            strong_reminders: reminderRows,
            total: reminderRows.length,
            unread_count: reminderRows.length,
            poll_interval_ms: 120000,
          },
        }),
      });
      return;
    }
    if (pathname === '/api/notifications/clear') {
      clearRequestCount += 1;
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
  const footer = page.getByTestId('ota-auth-strong-reminder-footer');
  const list = page.getByTestId('ota-auth-strong-reminder-list');
  const countdown = page.getByTestId('ota-auth-auto-dismiss-countdown');

  await expect(modal).toBeVisible({ timeout: 15000 });
  await expect(banner).toBeVisible();
  await expect(banner).toContainText('6 项 OTA Cookie 状态需处理');
  await expect(banner).toContainText('Cookie 可能已失效');

  await expect(modal).toContainText('部分账号 Cookie 可能已失效');
  await expect(modal).toContainText('测试门店80');
  await expect(modal).toContainText('美团');
  await expect(modal).toContainText('Cookie 可能已失效');
  await expect(modal.getByRole('button', { name: '更新 Cookie' })).toHaveCount(6);
  await expect(modal.locator('details')).toHaveCount(0);
  await expect(modal.getByText('查看详情')).toHaveCount(0);
  await expect(modal.getByText('候选授权来源')).toHaveCount(0);
  await expect(modal.getByText('运营账号授权 1')).toHaveCount(0);

  await expect(footer).toBeVisible();
  await expect(list).toBeVisible();
  await expect(countdown).toContainText('未操作将在');
  await expect(countdown).toContainText('秒后自动关闭，顶部提醒仍会保留。');

  const panelBox = await modal.locator('section').boundingBox();
  const footerBox = await footer.boundingBox();
  expect(panelBox).not.toBeNull();
  expect(footerBox).not.toBeNull();
  expect(panelBox.y).toBeGreaterThanOrEqual(0);
  expect(panelBox.y + panelBox.height).toBeLessThanOrEqual(1000);
  expect(footerBox.y + footerBox.height).toBeLessThanOrEqual(1000);

  const deferButton = page.getByTestId('ota-auth-strong-reminder-defer');
  const snoozeButton = page.getByTestId('ota-auth-strong-reminder-snooze-24h');
  const closeButton = page.getByTestId('ota-auth-strong-reminder-close');
  await expect(deferButton).toBeEnabled();
  await expect(snoozeButton).toBeEnabled();
  await expect(closeButton).toBeEnabled();
  await expect(deferButton).toHaveText('下次登录再提醒');
  await expect(snoozeButton).toHaveText('24 小时内不再弹窗');

  await expect(modal).toBeHidden({ timeout: 7000 });
  await expect(banner).toBeVisible();

  await banner.getByRole('button', { name: '立即处理' }).click();
  await expect(modal).toBeVisible();
  await closeButton.click();
  await expect(modal).toBeHidden();
  await expect(banner).toBeVisible();

  await banner.getByRole('button', { name: '立即处理' }).click();
  await expect(modal).toBeVisible();
  await snoozeButton.click();
  await expect(modal).toBeHidden();
  await expect(banner).toBeVisible();

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(banner).toBeVisible({ timeout: 15000 });
  await expect(modal).toBeHidden();

  await page.getByTestId('header-notification-trigger').click();
  await page.getByRole('button', { name: '清空普通提醒' }).click();
  await expect(banner).toBeVisible();
  await expect.poll(() => clearRequestCount).toBe(0);
});
