const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

const appUrl = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';

const user = {
  id: 702,
  username: 'security_monitor_probe',
  realname: 'Security Monitor Probe',
  role_name: 'Administrator',
  is_super_admin: true,
  permissions: { can_manage_own_hotels: true, can_fetch_online_data: true },
  capabilities: ['all'],
  hotel_id: 7,
  tenant_id: 7,
  permitted_hotels: [{ id: 7, name: 'Security Probe Hotel', tenant_id: 7, status: 1 }],
  context: { token_status: 'valid', permitted_hotel_ids: [7] },
};

function overviewForDays(days) {
  return {
    summary: {
      critical_users: 0,
      high_users: 1,
      needs_review_users: 1,
      rate_limited_events: 0,
      access_denied_events: 1,
      destructive_events: 0,
      successful_destructive_events: 0,
      failed_logins: days,
      automated_logins: 0,
    },
    account_activity: {
      complete: true,
      summary: {
        enabled_accounts: 3,
        active_accounts: 1,
        inactive_accounts: 2,
        never_logged_in_accounts: 1,
      },
      active_accounts: [{
        user_id: 20,
        username: 'VIP020',
        realname: 'Active Employee',
        successful_logins: days,
        failed_logins: 0,
        last_successful_login_at: '2026-08-05 08:00:00',
      }],
      inactive_accounts: [{
        user_id: 22,
        username: 'VIP022',
        realname: 'Inactive Employee',
        successful_logins: 0,
        failed_logins: 1,
        never_logged_in: true,
        last_successful_login_at: '',
      }],
      definition: {
        active: '所选时间范围内至少一次成功登录',
        visibility: '仅超级管理员可见；本名单不返回IP地址',
      },
    },
    risk_users: [{
      user_id: 6,
      username: 'VIP006',
      realname: 'Review Employee',
      risk_level: 'high',
      risk_score: 70,
      signals: ['access_denied'],
      failed_logins: days,
      automated_logins: 0,
      destructive_count: 0,
      successful_destructive_count: 0,
      access_denied_count: 1,
      rate_limited_count: 0,
    }],
    login_activity: [{
      user_id: 6,
      username: 'VIP006',
      realname: 'Review Employee',
      login_count: days,
      successful_logins: Math.max(1, days - 1),
      failed_logins: 1,
      automated_logins: 0,
      distinct_ips: null,
      last_login_at: '2026-08-05 08:00:00',
    }],
    latest_events: [],
    ip_evidence: {
      quality: 'unavailable',
      note: 'IP证据不可用：代理未透传可信来源地址。',
    },
    coverage: {
      complete: true,
      days,
      login_rows_scanned: days,
      operation_rows_scanned: 1,
      source_errors: [],
    },
  };
}

test('super administrator can review the security monitoring board without stale range data', async ({ page }) => {
  test.setTimeout(30000);
  const requestedDays = [];
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));

  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'security-monitor-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
  }, user);

  await page.route('**/api/**', async route => {
    const url = new URL(route.request().url());
    let data = { list: [], items: [], total: 0 };
    if (url.pathname === '/api/auth/info') {
      data = user;
    } else if (url.pathname === '/api/operation-logs/security-overview') {
      const days = Number(url.searchParams.get('days') || 30);
      requestedDays.push(days);
      data = overviewForDays(days);
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('app-main')).toBeVisible({ timeout: 15000 });
  await page.getByTestId('nav-lean-more').click();
  await page.getByText('系统与权限', { exact: true }).click();
  await page.getByText('安全监测', { exact: true }).click();

  await expect(page.getByTestId('security-monitor-overview')).toBeVisible({ timeout: 10000 });
  await expect(page.getByText('账号与高风险操作监测', { exact: true })).toBeVisible();
  const activityPanel = page.getByTestId('account-login-activity');
  await expect(activityPanel).toBeVisible();
  await expect(activityPanel.getByText('账号登录活跃度', { exact: true })).toBeVisible();
  await expect(activityPanel.getByText('VIP020', { exact: false }).first()).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'VIP006' }).first()).toBeVisible();
  await expect(page.getByText('IP证据不可用', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('系统不会自动封号、改密或处罚', { exact: false })).toBeVisible();

  for (const [label, days] of [['近7天', 7], ['今天', 1], ['近30天', 30]]) {
    await page.getByRole('button', { name: label, exact: true }).click();
    await expect.poll(() => requestedDays.at(-1)).toBe(days);
    await expect(activityPanel.getByText('VIP020', { exact: false }).first()).toBeVisible();
  }

  expect(requestedDays).toEqual(expect.arrayContaining([1, 7, 30]));
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
