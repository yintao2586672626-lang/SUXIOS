const { test, expect } = require('@playwright/test');

test('super administrator can review the security monitoring board', async ({ page }) => {
    const username = String(process.env.E2E_USERNAME || 'admin').trim();
    const password = String(process.env.E2E_PASSWORD || '').trim();
    expect(password).not.toBe('');

    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    await page.goto('http://127.0.0.1:8080/', { waitUntil: 'networkidle' });
    await page.locator('input[name="username"]').fill(username);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForResponse(response => response.url().includes('/api/auth/login') && response.request().method() === 'POST'),
        page.getByRole('button', { name: /进入决策中心/ }).click(),
    ]);
    await expect(page.getByTestId('app-main')).toBeVisible();

    const overviewResponse = page.waitForResponse(response => (
        response.url().includes('/api/operation-logs/security-overview')
        && response.request().method() === 'GET'
    ));
    await page.getByTestId('nav-lean-more').click();
    await page.getByText('系统与权限', { exact: true }).click();
    await page.getByText('安全监测', { exact: true }).click();

    const response = await overviewResponse;
    expect(response.status()).toBe(200);
    await expect(page.getByTestId('security-monitor-overview')).toBeVisible();
    await expect(page.getByText('账号与危险操作监测', { exact: true })).toBeVisible();
    const activityPanel = page.getByTestId('account-login-activity');
    await expect(activityPanel).toBeVisible();
    await expect(activityPanel.getByText('账号登录活跃度', { exact: true })).toBeVisible();
    await expect(activityPanel.getByText('已启用员工账号', { exact: true })).toBeVisible();
    await expect(activityPanel.getByText('本期活跃', { exact: true }).first()).toBeVisible();
    await expect(activityPanel.getByText('本期未活跃', { exact: true }).first()).toBeVisible();
    await expect(activityPanel.getByText('其中从未成功登录', { exact: true })).toBeVisible();
    await expect(activityPanel.getByText('VIP020', { exact: false }).first()).toBeVisible();
    await expect(page.getByText('登录频率排行', { exact: true })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'VIP006' }).first()).toBeVisible();
    await expect(page.getByText('IP证据不可用', { exact: true })).toBeVisible();
    await expect(page.getByText('不会自动封号、改密或处罚', { exact: false })).toBeVisible();

    for (const [label, days] of [['近7天', 7], ['今天', 1], ['近30天', 30]]) {
        const windowResponse = page.waitForResponse(candidate => (
            candidate.url().includes('/api/operation-logs/security-overview')
            && new URL(candidate.url()).searchParams.get('days') === String(days)
            && candidate.request().method() === 'GET'
        ));
        await page.getByRole('button', { name: label, exact: true }).click();
        const selectedResponse = await windowResponse;
        expect(selectedResponse.status()).toBe(200);
        const payload = await selectedResponse.json();
        expect(payload.data.account_activity.summary.enabled_accounts).toBeGreaterThan(0);
        expect(payload.data.account_activity.definition.active).toContain('至少一次成功登录');
        expect(payload.data.account_activity.definition.visibility).toContain('仅超级管理员可见');
    }

    await page.screenshot({ path: 'test-results/security-monitoring-admin.png', fullPage: true });
    expect(pageErrors).toEqual([]);
});
