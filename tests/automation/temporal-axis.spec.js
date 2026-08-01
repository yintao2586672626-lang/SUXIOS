function defineTemporalAxisPlaywrightSuite() {
const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const {
  createSuiteOutput,
  ensureCleanDir,
  getConfig,
  installDiagnostics,
  login,
  writeLatestRunManifest,
} = require('./e2e-helpers');

const config = getConfig();
const suiteOutput = createSuiteOutput('temporal-axis');
const { outputDir, screenshotDir } = suiteOutput;

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});
test.setTimeout(60000);

test.beforeAll(() => {
  ensureCleanDir(outputDir);
  fs.mkdirSync(screenshotDir, { recursive: true });
});

test.afterAll(() => {
  writeLatestRunManifest(suiteOutput);
});

function apiUrl(pathname, params = {}) {
  const url = new URL(String(pathname).replace(/^\/+/, ''), config.baseURL.endsWith('/') ? config.baseURL : `${config.baseURL}/`);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
  return url.toString();
}

async function apiData(response, label) {
  const body = await response.json().catch(() => null);
  expect(response.ok(), `${label} HTTP ${response.status()}`).toBeTruthy();
  expect(body?.code, `${label} response code`).toBe(200);
  return body.data;
}

function chinaDate(offsetDays = 0) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  const base = new Date(`${values.year}-${values.month}-${values.day}T12:00:00+08:00`);
  base.setUTCDate(base.getUTCDate() + offsetDays);
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(base);
}

async function authenticatedToken(request) {
  const response = await request.post(apiUrl('/api/auth/login'), {
    data: { username: config.username, password: config.password },
  });
  const data = await apiData(response, 'temporary login');
  expect(data?.token).toBeTruthy();
  return data.token;
}

async function post(request, token, pathname, data) {
  const response = await request.post(apiUrl(pathname), {
    data,
    headers: { Authorization: token },
  });
  return apiData(response, pathname);
}

async function get(request, token, pathname, params = {}) {
  const response = await request.get(apiUrl(pathname, params), {
    headers: { Authorization: token },
  });
  return apiData(response, pathname);
}

function verifyTemporalInputFixture() {
  if (process.env.SUXI_E2E_ISOLATED_RUNNER !== '1') {
    throw new Error('Temporal input verification requires the isolated E2E runner');
  }
  const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
  const helper = path.join(__dirname, 'e2e-isolation-helper.php');
  const result = spawnSync(php, [helper, 'verify-temporal-inputs'], {
    cwd: path.resolve(__dirname, '..', '..'),
    env: {
      ...process.env,
      SUXI_E2E_PREFIX: config.objectPrefix,
      SUXI_E2E_HOTEL_ID: String(config.hotelId),
    },
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.error || result.status !== 0) {
    const detail = String(result.stderr || result.stdout || result.error?.message || '').trim().slice(0, 800);
    throw new Error(`Temporal input verification failed${detail ? `: ${detail}` : ''}`);
  }
  return JSON.parse(String(result.stdout || '').trim());
}

test('isolated page shows past, present, future and forecast review entry', async ({ page, request }) => {
  expect(config.objectPrefix).toMatch(/^codex_e2e_[a-z0-9_]+$/);
  expect(config.hotelId).toBeGreaterThan(0);

  const token = await authenticatedToken(request);
  const info = await get(request, token, '/api/auth/info');
  expect(info.is_super_admin).toBe(false);
  expect((info.permitted_hotels || []).map((hotel) => Number(hotel.id))).toEqual([config.hotelId]);

  const platformHotelId = `${config.objectPrefix}_ota`;
  const historicalRows = [];
  for (let offset = -14; offset <= -1; offset += 1) {
    const sequence = offset + 15;
    historicalRows.push({
      hotelId: platformHotelId,
      hotelName: config.hotelName,
      dataDate: chinaDate(offset),
      amount: 8000 + sequence * 180,
      quantity: 36 + sequence,
      bookOrderNum: 24 + sequence,
      commentScore: 4.7,
      listExposure: 12000 + sequence * 260,
      detailExposure: 2600 + sequence * 80,
      orderFillingNum: 280 + sequence * 8,
      orderSubmitNum: 120 + sequence * 5,
    });
  }

  const historySave = await post(request, token, '/api/online-data/save-daily-data', {
    system_hotel_id: config.hotelId,
    data_date: chinaDate(-1),
    data: historicalRows,
  });
  expect(historySave.saved_count).toBe(14);

  const todaySave = await post(request, token, '/api/online-data/save-daily-data', {
    system_hotel_id: config.hotelId,
    data_date: chinaDate(0),
    data: [{
      hotelId: platformHotelId,
      hotelName: config.hotelName,
      dataDate: chinaDate(0),
      amount: 10600,
      quantity: 51,
      bookOrderNum: 38,
      commentScore: 4.7,
      listExposure: 16100,
      detailExposure: 3740,
      orderFillingNum: 390,
      orderSubmitNum: 190,
    }],
  });
  expect(todaySave.saved_count).toBe(1);

  const inputFixture = verifyTemporalInputFixture();
  expect(inputFixture.historical_days).toBe(14);
  expect(inputFixture.realtime_days).toBe(1);
  expect(inputFixture.readback_verified).toBe(true);
  expect(inputFixture.source_scope).toBe('synthetic_isolated_e2e_ctrip_channel_fixture');

  const generated = await post(request, token, '/api/temporal-insights/forecasts', {
    hotel_id: config.hotelId,
    future_days: 7,
  });
  expect(generated.status).toBe('generated');
  expect(generated.saved_count).toBeGreaterThan(0);
  expect(generated.readback_count).toBe(generated.saved_count);

  const overview = await get(request, token, '/api/temporal-insights/overview', {
    hotel_id: config.hotelId,
    history_days: 30,
    future_days: 7,
  });
  expect(overview.past.status).not.toBe('empty');
  expect(overview.present.status).not.toBe('empty');
  expect(overview.future.status).toBe('ready');

  const pageErrors = [];
  const pageEvents = [];
  installDiagnostics(page, { apiEvents: [], pageEvents });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await login(page, config);
  await expect.poll(
    () => page.evaluate(() => document.documentElement.dataset.suxiRenderPhase || ''),
    { timeout: 15000 },
  ).toBe('full');
  const overviewResponse = page.waitForResponse(
    (response) => response.url().includes('/api/temporal-insights/overview'),
    { timeout: 30000 },
  );
  const analysisGroup = page.getByTestId('nav-lean-business-loop');
  await expect(analysisGroup).toBeVisible({ timeout: 10000 });
  await analysisGroup.click();
  const homeEntry = page.getByTestId('nav-compass');
  await expect(homeEntry).toBeVisible({ timeout: 10000 });
  await homeEntry.click();
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'compass', { timeout: 10000 });
  expect((await overviewResponse).status()).toBe(200);

  const axis = page.getByTestId('home-temporal-axis');
  await expect(axis).toBeVisible({ timeout: 10000 });
  const axisToggle = axis.getByTestId('home-temporal-toggle');
  await expect(axisToggle).toBeVisible();
  await expect(axis).toHaveAttribute('open', '');
  await expect(axis.locator('article')).toHaveCount(3);
  await expect(axis).toContainText('昨天事实');
  await expect(axis).toContainText('今天状态');
  await expect(axis).toContainText('未来 AI 研判');
  await expect(axis.getByTestId('home-today-acquired-status')).toContainText('实时快照');
  await expect(axis.getByTestId('home-today-acquired-status')).not.toContainText('OTA收入');
  await expect(axis.getByTestId('home-future-ai-judgement')).toContainText('不自动执行');
  await expect(axis.getByRole('button')).toBeEnabled();
  await expect(axis).not.toContainText('执行价格');
  expect(pageErrors).toEqual([]);
  expect(pageEvents.filter((event) => event.category === 'page-error')).toEqual([]);

  await axis.screenshot({ path: path.join(screenshotDir, 'temporal-axis.png') });
});
}

if (process.env.NODE_TEST_CONTEXT) {
  const { test: nodeTest } = require('node:test');
  nodeTest.skip('Temporal-axis browser coverage runs through npm run test:e2e:temporal', () => {});
} else {
  defineTemporalAxisPlaywrightSuite();
}
