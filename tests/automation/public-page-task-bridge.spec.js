const { test, expect } = require('@playwright/test');
const { getConfig, login } = require('./e2e-helpers');

const config = getConfig();

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 8000,
  navigationTimeout: 30000,
});
test.setTimeout(Number(process.env.E2E_TEST_TIMEOUT_MS || 120000));

function pad(value) {
  return String(value).padStart(2, '0');
}

function localDate(value = new Date()) {
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

function localDateTime(value = new Date()) {
  return `${localDate(value)}T${pad(value.getHours())}:${pad(value.getMinutes())}`;
}

function futureLocalDateTime(hours) {
  return localDateTime(new Date(Date.now() + hours * 60 * 60 * 1000));
}

function apiPath(response) {
  return new URL(response.url()).pathname;
}

async function successfulJson(response, label) {
  const body = await response.json().catch(() => null);
  expect(response.ok(), `${label}: HTTP ${response.status()} ${JSON.stringify(body)}`).toBe(true);
  expect(Number(body?.code), `${label}: ${JSON.stringify(body)}`).toBe(200);
  return body;
}

async function openPublicPage(page) {
  await page.waitForTimeout(500);
  const otaGroup = page.getByTestId('nav-lean-ota-data');
  const ctripNav = page.getByTestId('nav-ctrip-ebooking');
  await expect(otaGroup).toBeVisible();
  if ((await otaGroup.getAttribute('aria-expanded')) !== 'true') {
    await otaGroup.click();
  }
  await expect(otaGroup).toHaveAttribute('aria-expanded', 'true');
  await expect(ctripNav).toHaveCount(1);
  // The shell can collapse this menu while background bootstrap requests settle.
  // Dispatch the real DOM click handler so this feature test is not coupled to that animation race.
  await ctripNav.evaluate((element) => element.click());
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'ctrip-ebooking');
  await page.getByTestId('ctrip-public-profile-tab').click();
  await expect(page.getByTestId('ctrip-public-profile-panel')).toBeVisible();

  const hotelSelect = page.getByTestId('ctrip-public-profile-hotel-select');
  await expect(hotelSelect.locator(`option[value="${config.hotelId}"]`)).toHaveCount(1);
  await expect(hotelSelect).toBeEnabled({ timeout: 30000 });
  await hotelSelect.selectOption(String(config.hotelId));
}

async function readDiagnosis(page, platform, businessDate) {
  const platformSelect = page.getByTestId('ota-public-page-platform');
  const businessDateInput = page.getByTestId('ota-public-page-business-date');
  await expect(platformSelect).toBeEnabled({ timeout: 30000 });
  await expect(businessDateInput).toBeEnabled({ timeout: 30000 });
  await platformSelect.selectOption(platform);
  await businessDateInput.fill(businessDate);
  const diagnoseButton = page.getByTestId('ota-public-page-diagnose');
  await expect(diagnoseButton).toBeEnabled({ timeout: 30000 });
  const responsePromise = page.waitForResponse((response) => (
    response.request().method() === 'GET'
      && apiPath(response) === '/api/online-data/public-page-diagnosis'
      && new URL(response.url()).searchParams.get('platform') === platform
      && new URL(response.url()).searchParams.get('business_date') === businessDate
  ), { timeout: 30000 });
  await diagnoseButton.click();
  const body = await successfulJson(await responsePromise, 'public-page diagnosis');
  await expect(page.getByTestId('ota-public-page-diagnosis-loading')).toHaveCount(0);
  return body.data;
}

function dialogField(dialog, label) {
  return dialog.locator('label').filter({ hasText: label }).locator('input, textarea, select').first();
}

async function submitDialogForResponse(page, buttonText, responsePredicate) {
  const responsePromise = page.waitForResponse(responsePredicate, { timeout: 30000 });
  await page.getByTestId('workflow-form-dialog').getByRole('button', { name: buttonText, exact: true }).click();
  return successfulJson(await responsePromise, buttonText);
}

test('authenticated public-page evidence closes into an exact, reschedulable and retryable task', async ({ page }) => {
  const pageErrors = [];
  const exactFlowIntentIds = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (request.method() === 'GET' && url.pathname === '/api/operation/execution-flow') {
      const intentId = Number(url.searchParams.get('intent_id') || 0);
      if (intentId > 0) exactFlowIntentIds.push(intentId);
    }
  });

  await login(page, config);
  await openPublicPage(page);

  const businessDate = localDate();
  const initialDiagnosis = await readDiagnosis(page, 'meituan', businessDate);
  expect(initialDiagnosis?.diagnosis?.platform || initialDiagnosis?.platform).toBe('meituan');
  await expect(page.getByTestId('ota-public-page-evidence-action')).toHaveText('录入公开证据');

  await page.getByTestId('ota-public-page-evidence-action').click();
  const evidenceDialog = page.getByTestId('workflow-form-dialog');
  await expect(evidenceDialog).toBeVisible();
  await expect(evidenceDialog).toHaveAccessibleName('录入美团消费者公开页证据');

  const poiId = String(Date.now()).slice(-11);
  await dialogField(evidenceDialog, '对象').selectOption('self');
  await dialogField(evidenceDialog, '美团公开酒店/POI ID').fill(poiId);
  await dialogField(evidenceDialog, '消费者公开页 HTTPS 地址').fill(
    `https://i.meituan.com/awp/hfe/block/hotel.html?poiId=${poiId}`,
  );
  await dialogField(evidenceDialog, '页面核对时间').fill(localDateTime());
  await dialogField(evidenceDialog, '公开页字段 JSON').fill(JSON.stringify({
    name: `${config.objectPrefix}_public_hotel`,
    rating: 4.8,
  }));
  await dialogField(evidenceDialog, '字段定位 JSON').fill(JSON.stringify({
    name: '页面标题',
    rating: '评分区域',
  }));
  await dialogField(evidenceDialog, '截图引用').fill(`${config.objectPrefix}_screenshot_ref`);

  const evidenceBody = await submitDialogForResponse(
    page,
    '保存并回读',
    (response) => response.request().method() === 'POST'
      && apiPath(response) === '/api/online-data/public-page-evidence',
  );
  expect(evidenceBody.data?.status).toBe('saved_readback_verified');
  expect(evidenceBody.data?.profile?.source_validation_status).toBe('source_observed');
  expect(evidenceBody.data?.profile?.persistence_readback_status).toBe('readback_verified');
  expect(evidenceBody.data?.profile?.platform).toBe('meituan');
  expect(evidenceBody.data?.profile?.data_date).toBe(businessDate);
  const evidenceRef = String(evidenceBody.data?.profile?.response_ref || '');
  expect(evidenceRef).toMatch(/^online_daily_data#[1-9][0-9]*$/);
  await expect(page.getByTestId('ota-public-page-sources')).toContainText(evidenceRef, { timeout: 15000 });
  await expect(page.getByTestId('ota-public-page-sources')).toContainText('来源 已观察（未验证）');

  const taskButton = page.getByTestId('ota-public-page-create-task');
  await expect(taskButton).toBeEnabled();
  await expect(taskButton).toHaveText('创建待审批任务');
  const createResponsePromise = page.waitForResponse((response) => response.request().method() === 'POST'
    && apiPath(response) === '/api/online-data/public-page-diagnosis/execution-intent', { timeout: 30000 });
  await taskButton.click();
  const createBody = await successfulJson(await createResponsePromise, 'create pending-approval task');
  expect(createBody.data?.create_performed).toBe(true);
  expect(createBody.data?.retry_performed).toBe(false);
  expect(createBody.data?.schedule_updated).toBe(false);
  expect(createBody.data?.execution_intent_status).toBe('pending_approval');
  expect(Number(createBody.data?.intent_attempt)).toBe(1);
  const firstIntentId = Number(createBody.data?.execution_intent?.id || 0);
  expect(firstIntentId).toBeGreaterThan(0);

  const firstRow = page.locator(`[data-operation-execution-intent-id="${firstIntentId}"]`);
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'ops-track');
  await expect(firstRow).toBeVisible({ timeout: 15000 });
  await expect(firstRow.getByTestId('operation-execution-schedule-readback')).toBeVisible();
  expect(exactFlowIntentIds).toContain(firstIntentId);

  await openPublicPage(page);
  await readDiagnosis(page, 'meituan', businessDate);
  await expect(taskButton).toHaveText('调整排期并打开');
  await taskButton.click();
  const scheduleDialog = page.getByTestId('workflow-form-dialog');
  await expect(scheduleDialog).toHaveAccessibleName('调整待审批任务排期');
  const dueAt = futureLocalDateTime(48);
  const reviewAt = futureLocalDateTime(72);
  await dialogField(scheduleDialog, '截止时间').fill(dueAt);
  await dialogField(scheduleDialog, '复核时间').fill(reviewAt);
  const rescheduleBody = await submitDialogForResponse(
    page,
    '确认排期',
    (response) => response.request().method() === 'POST'
      && apiPath(response) === '/api/online-data/public-page-diagnosis/execution-intent',
  );
  expect(rescheduleBody.data?.create_performed).toBe(false);
  expect(rescheduleBody.data?.schedule_updated).toBe(true);
  expect(Number(rescheduleBody.data?.execution_intent?.id || 0)).toBe(firstIntentId);
  expect(String(rescheduleBody.data?.execution_intent?.target_value?.workflow_schedule?.due_at || '')).toContain(dueAt.replace('T', ' '));
  await expect(firstRow).toBeVisible({ timeout: 15000 });
  expect(exactFlowIntentIds.filter((id) => id === firstIntentId).length).toBeGreaterThanOrEqual(2);

  await firstRow.getByRole('button', { name: '驳回', exact: true }).click();
  const rejectDialog = page.getByTestId('workflow-form-dialog');
  await expect(rejectDialog).toHaveAccessibleName('驳回执行意图');
  await dialogField(rejectDialog, '驳回原因').fill('公开页证据需重新核对，验证终态重试。');
  const rejectBody = await submitDialogForResponse(
    page,
    '确认驳回',
    (response) => response.request().method() === 'POST'
      && apiPath(response) === `/api/operation/execution-intents/${firstIntentId}/approve`,
  );
  expect(rejectBody.data?.status).toBe('rejected');

  await openPublicPage(page);
  await readDiagnosis(page, 'meituan', businessDate);
  await expect(taskButton).toHaveText('重新创建待审批任务');
  const retryResponsePromise = page.waitForResponse((response) => response.request().method() === 'POST'
    && apiPath(response) === '/api/online-data/public-page-diagnosis/execution-intent', { timeout: 30000 });
  await taskButton.click();
  const retryBody = await successfulJson(await retryResponsePromise, 'retry terminal public-page task');
  expect(retryBody.data?.create_performed).toBe(true);
  expect(retryBody.data?.retry_performed).toBe(true);
  expect(retryBody.data?.schedule_updated).toBe(false);
  expect(Number(retryBody.data?.intent_attempt)).toBe(2);
  const retrySummary = retryBody.data?.task_bridge?.execution_intent || {};
  expect(Number(retrySummary.retry_of_intent_id || 0)).toBe(firstIntentId);
  const retryIntentId = Number(retrySummary.id || 0);
  expect(retryIntentId).toBeGreaterThan(firstIntentId);
  await expect(page.locator(`[data-operation-execution-intent-id="${retryIntentId}"]`)).toBeVisible({ timeout: 15000 });
  expect(exactFlowIntentIds).toContain(retryIntentId);
  expect(pageErrors).toEqual([]);
});
