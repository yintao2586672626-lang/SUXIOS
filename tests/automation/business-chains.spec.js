const { test, expect } = require('@playwright/test');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const {
  MODULE,
  classifyError,
  createSuiteOutput,
  ensureCleanDir,
  getConfig,
  goModule,
  installDiagnostics,
  login,
  shortError,
  summarize,
  writeLatestRunManifest,
  writeJsonCsv,
} = require('./e2e-helpers');

const config = getConfig();
const suiteOutput = createSuiteOutput('business-chains');
const { outputDir, screenshotDir } = suiteOutput;
const apiRequestTimeout = Number(process.env.E2E_API_REQUEST_TIMEOUT_MS || 30000);
const results = [];
const apiEvents = [];
const pageEvents = [];

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 10000,
});
test.setTimeout(Number(process.env.E2E_TEST_TIMEOUT_MS || 90000));

test.beforeAll(() => {
  ensureCleanDir(outputDir);
  fs.mkdirSync(screenshotDir, { recursive: true });
});

test.afterAll(() => {
  writeJsonCsv(outputDir, 'results', results);
  writeJsonCsv(outputDir, 'api-events', apiEvents);
  writeJsonCsv(outputDir, 'page-events', pageEvents);
  fs.writeFileSync(path.join(outputDir, 'summary.json'), JSON.stringify(
    summarize({ results, apiEvents, pageEvents }),
    null,
    2,
  ));
  writeLatestRunManifest(suiteOutput);
});

function apiUrl(pathname, params = {}) {
  const cleanPath = String(pathname).replace(/^\/+/, '');
  const url = new URL(cleanPath, config.baseURL.endsWith('/') ? config.baseURL : `${config.baseURL}/`);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });
  return url.toString();
}

async function parseJson(response) {
  return response.json().catch(async () => ({
    code: response.status(),
    message: (await response.text().catch(() => '')).slice(0, 300) || `HTTP ${response.status()}`,
    data: null,
  }));
}

function apiFailure(label, method, pathname, response, body) {
  const code = Number(body?.code || response.status());
  const error = new Error(`${label} failed: ${method.toUpperCase()} ${pathname} status=${response.status()} code=${code} message=${body?.message || ''}`);
  if (code === 422 || code === 400 || response.status() === 422 || response.status() === 400) {
    error.category = 'test-data-invalid';
  } else if (!response.ok() || code >= 500 || response.status() >= 500) {
    error.category = 'api-error';
  } else {
    error.category = 'product-bug';
  }
  error.responseBody = body;
  return error;
}

async function createApi(request) {
  let loginResponse;
  try {
    loginResponse = await request.post(apiUrl('/api/auth/login'), {
      data: { username: config.username, password: config.password },
      timeout: apiRequestTimeout,
    });
  } catch (error) {
    error.category = 'api-error';
    apiEvents.push({
      label: 'auth login',
      method: 'POST',
      path: '/api/auth/login',
      status: null,
      code: null,
      category: error.category,
      message: shortError(error),
      timestamp: new Date().toISOString(),
    });
    throw error;
  }
  const loginBody = await parseJson(loginResponse);
  apiEvents.push({
    label: 'auth login',
    method: 'POST',
    path: '/api/auth/login',
    status: loginResponse.status(),
    code: loginBody.code,
    category: loginResponse.ok() && loginBody.code === 200 ? null : 'api-error',
    timestamp: new Date().toISOString(),
  });
  if (!loginResponse.ok() || loginBody.code !== 200 || !loginBody.data?.token) {
    throw apiFailure('auth login', 'POST', '/api/auth/login', loginResponse, loginBody);
  }

  const token = loginBody.data.token;
  return {
    token,
    async call(method, pathname, options = {}) {
      const upper = method.toUpperCase();
      let response;
      try {
        response = await request[method.toLowerCase()](apiUrl(pathname, options.params), {
          data: options.data,
          headers: { Authorization: token },
          timeout: options.timeout || apiRequestTimeout,
        });
      } catch (error) {
        error.category = error.category || 'api-error';
        apiEvents.push({
          label: options.label || pathname,
          method: upper,
          path: pathname,
          status: null,
          code: null,
          category: error.category,
          message: shortError(error),
          timestamp: new Date().toISOString(),
        });
        throw error;
      }
      const body = await parseJson(response);
      const ok = response.ok() && body.code === (options.expectedCode || 200);
      apiEvents.push({
        label: options.label || pathname,
        method: upper,
        path: pathname,
        status: response.status(),
        code: body.code,
        category: ok ? null : apiFailure(options.label || pathname, method, pathname, response, body).category,
        message: ok ? null : body.message,
        timestamp: new Date().toISOString(),
      });
      if (!ok) {
        throw apiFailure(options.label || pathname, method, pathname, response, body);
      }
      return body.data;
    },
    get(pathname, options = {}) {
      return this.call('get', pathname, options);
    },
    post(pathname, data = {}, options = {}) {
      return this.call('post', pathname, { ...options, data });
    },
    delete(pathname, options = {}) {
      return this.call('delete', pathname, options);
    },
  };
}

async function resolveHotelContext(api) {
  const expectedHotelId = Number(config.hotelId || 0);
  const expectedHotelName = String(config.hotelName || '');
  const objectPrefix = String(config.objectPrefix || '');
  if (!/^codex_e2e_[a-z0-9_]+$/.test(objectPrefix)
    || expectedHotelId <= 0
    || expectedHotelName !== `${objectPrefix}_hotel`) {
    const error = new Error('Business-chain E2E requires an isolated codex_e2e_ hotel context');
    error.category = 'test-data-invalid';
    throw error;
  }

  const info = await api.get('/api/auth/info', { label: 'auth info' });
  const permitted = Array.isArray(info.permitted_hotels) ? info.permitted_hotels : [];
  const hotel = permitted.find((item) => Number(item?.id || 0) === expectedHotelId) || null;
  if (!hotel || String(hotel.name || '') !== expectedHotelName) {
    const error = new Error('Isolated E2E hotel is not available to the temporary test user');
    error.category = 'test-data-invalid';
    throw error;
  }

  return {
    hotelId: expectedHotelId,
    hotelName: expectedHotelName,
    objectPrefix,
    cleanup: [],
  };
}

async function cleanupAll(cleanups) {
  for (const cleanup of cleanups.reverse()) {
    await cleanup().catch((error) => {
      pageEvents.push({
        type: 'cleanup-fail',
        category: 'safe-skip',
        error: shortError(error),
        timestamp: new Date().toISOString(),
      });
    });
  }
}

function seedAiReportInputFixture(hotelContext) {
  if (process.env.SUXI_E2E_ISOLATED_RUNNER !== '1') {
    throw new Error('AI report traffic fixture requires the isolated E2E runner');
  }
  const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
  const helper = path.join(__dirname, 'e2e-isolation-helper.php');
  const result = spawnSync(php, [helper, 'seed-ai-report-inputs'], {
    cwd: path.resolve(__dirname, '..', '..'),
    env: {
      ...process.env,
      SUXI_E2E_PREFIX: hotelContext.objectPrefix,
      SUXI_E2E_HOTEL_ID: String(hotelContext.hotelId),
    },
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.error || result.status !== 0) {
    const detail = String(result.stderr || result.stdout || result.error?.message || '').trim().slice(0, 800);
    const error = new Error(`AI report input fixture failed${detail ? `: ${detail}` : ''}`);
    error.category = 'test-data-invalid';
    throw error;
  }
  try {
    return JSON.parse(String(result.stdout || '').trim());
  } catch {
    const error = new Error('AI report input fixture returned invalid JSON');
    error.category = 'test-data-invalid';
    throw error;
  }
}

async function assertPages(page, modules) {
  for (const mod of modules) {
    await goModule(page, mod);
  }
}

async function runBusinessCase(page, request, name, modules, runner) {
  installDiagnostics(page, { apiEvents, pageEvents });
  const started = Date.now();
  const cleanups = [];

  try {
    const api = await createApi(request);
    const hotelContext = await resolveHotelContext(api);
    cleanups.push(...hotelContext.cleanup);

    await login(page, config);
    await assertPages(page, modules);
    const assertions = await runner({ api, hotelContext, cleanups });

    const badApiEvents = apiEvents.filter((event) => event.category === 'api-error');
    expect(badApiEvents, JSON.stringify(badApiEvents, null, 2)).toHaveLength(0);
    const badPageEvents = pageEvents.filter((event) => event.category === 'page-error');
    expect(badPageEvents, JSON.stringify(badPageEvents, null, 2)).toHaveLength(0);

    results.push({
      chain: name,
      status: 'success',
      assertions: assertions.join('|'),
      ms: Date.now() - started,
      timestamp: new Date().toISOString(),
    });
  } catch (error) {
    const screenshot = path.join(screenshotDir, `${name}.png`);
    const html = path.join(screenshotDir, `${name}.html`);
    await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
    fs.writeFileSync(html, await page.content().catch(() => ''));
    results.push({
      chain: name,
      status: 'fail',
      category: error.category || classifyError(error, 'product-bug'),
      error: shortError(error),
      screenshot,
      html,
      ms: Date.now() - started,
      timestamp: new Date().toISOString(),
    });
    throw error;
  } finally {
    await cleanupAll(cleanups);
  }
}

test('business chain: OTA import to revenue, operation task, and tracking', async ({ page, request }) => {
  await runBusinessCase(
    page,
    request,
    'ota-operation',
    [MODULE.DATA_TRUST, MODULE.REVENUE_DIAGNOSIS, MODULE.AI_DAILY_REPORT, MODULE.EXECUTION_TRACKING],
    async ({ api, hotelContext, cleanups }) => {
      const dataDate = '2026-05-17';
      const baselineDate = '2026-05-16';
      const otaHotelId = `${hotelContext.objectPrefix}_ota`;
      const trafficOtaHotelId = `${hotelContext.objectPrefix}_traffic_ota`;

      const reportInputFixture = seedAiReportInputFixture(hotelContext);
      expect(reportInputFixture.readback_verified).toBe(true);
      expect(Number(reportInputFixture.hotel_id)).toBe(hotelContext.hotelId);
      expect(reportInputFixture.ota_hotel_id).toBe(trafficOtaHotelId);
      expect(reportInputFixture.business_ota_hotel_id).toBe(otaHotelId);
      expect(reportInputFixture.row_ids || []).toHaveLength(3);
      expect(reportInputFixture.data_dates || []).toEqual([baselineDate, dataDate]);

      const save = await api.post('/api/online-data/save-daily-data', {
        system_hotel_id: hotelContext.hotelId,
        data_date: dataDate,
        data: [{
          hotelId: otaHotelId,
          hotelName: hotelContext.hotelName,
          dataDate: dataDate,
          amount: 120000,
          quantity: 300,
          bookOrderNum: 120,
          commentScore: 4.8,
          qunarCommentScore: 4.7,
          listExposure: 10000,
          detailExposure: 2500,
          flowRate: 25,
          orderFillingNum: 250,
          orderSubmitNum: 120,
        }],
      }, { label: 'OTA daily import' });
      expect(save.saved_count).toBeGreaterThan(0);

      const imported = await api.get('/api/online-data/daily-data-list', {
        params: { hotel_id: otaHotelId, start_date: dataDate, end_date: dataDate, page_size: 5 },
        label: 'OTA imported list',
      });
      const row = (imported.list || []).find((item) => (
        String(item.hotel_id) === otaHotelId && String(item.data_type) === 'business'
      ));
      expect(row).toBeTruthy();
      expect(Number(row.system_hotel_id)).toBe(hotelContext.hotelId);
      expect(Number(row.readback_verified)).toBe(1);
      expect(String(row.readback_verified_at || '')).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
      cleanups.push(() => api.post('/api/online-data/delete-data', { id: row.id }, { label: 'cleanup OTA row' }).catch(() => null));

      const revenue = await api.get('/api/online-data/data-analysis', {
        params: { hotel_id: otaHotelId, start_date: dataDate, end_date: dataDate },
        label: 'revenue analysis reads OTA',
      });
      expect(Number(revenue.summary.total_amount)).toBeGreaterThanOrEqual(120000);
      expect(Number(revenue.summary.total_orders)).toBeGreaterThanOrEqual(120);

      const fullData = await api.get('/api/operation/full-data', {
        params: { hotel_id: hotelContext.hotelId, date: dataDate },
        label: 'operation full data reads OTA',
      });
      expect(fullData.summary.data_status).toBe('ok');
      expect(Number(fullData.summary.revenue)).toBeGreaterThanOrEqual(120000);
      expect(fullData.ota.data_status).toBe('ok');
      expect(Number(fullData.ota.exposure)).toBe(10000);

      const rootCause = await api.post('/api/operation/root-cause', {
        hotel_id: hotelContext.hotelId,
        date: dataDate,
        problem_type: 'orders_down',
      }, { label: 'operation root cause' });
      expect(rootCause.conclusion || rootCause.main_problem).toBeTruthy();
      expect(
        (rootCause.candidate_factors || []).some((item) => (item?.code || item?.type) === 'traffic_down'),
        JSON.stringify(rootCause),
      ).toBe(true);

      const strategy = await api.post('/api/operation/strategy-simulation', {
        hotel_id: hotelContext.hotelId,
        platform: 'ctrip',
        strategy_type: 'promotion',
        discount_rate: 8,
        start_date: dataDate,
        end_date: '2026-05-24',
        create_execution_order: true,
      }, { label: 'operation strategy suggestion' });
      expect(strategy.simulated).toBe(false);
      expect(strategy.status).toBe('insufficient_data');
      expect(strategy.forecast).toBeTruthy();
      expect(strategy.execution_intent).toBeNull();
      expect(strategy.execution_intent_status).toBe('blocked_by_insufficient_baseline');

      await goModule(page, MODULE.AI_DAILY_REPORT);
      const generationTask = await api.post('/api/ai-daily-reports/generate', {
        hotel_id: hotelContext.hotelId,
        report_date: dataDate,
        use_llm: false,
        background: true,
      }, { label: 'AI daily report background generation' });
      expect(String(generationTask.task_id || '')).toMatch(/^airpt_/);
      expect(Number(generationTask.hotel_id)).toBe(hotelContext.hotelId);
      expect(generationTask.report_date).toBe(dataDate);

      let completedGenerationTask = generationTask;
      await expect.poll(async () => {
        completedGenerationTask = await api.get(`/api/ai-daily-reports/tasks/${generationTask.task_id}`, {
          label: 'AI daily report background task polling',
        });
        return completedGenerationTask.done;
      }, {
        message: 'AI daily report background task should reach a terminal state',
        timeout: 45000,
        intervals: [100, 250, 500, 1000],
      }).toBe(true);
      expect(completedGenerationTask.status, JSON.stringify(completedGenerationTask)).toBe('succeeded');
      expect(completedGenerationTask.model_status).toBe('not_requested');
      const reportId = Number(completedGenerationTask.result_report_id || 0);
      expect(reportId).toBeGreaterThan(0);

      const report = await api.get(`/api/ai-daily-reports/${reportId}`, {
        label: 'AI daily report exact id and hotel readback',
      });
      expect(Number(report.id)).toBeGreaterThan(0);
      expect(Number(report.id)).toBe(reportId);
      expect(Number(report.hotel_id)).toBe(hotelContext.hotelId);
      expect(report.report_date).toBe(dataDate);
      expect(report.generation_mode).toBe('rule');
      expect(report.model_status).toBe('not_requested');
      const reportActions = report.recommended_actions || [];
      const executableActionIndex = reportActions.findIndex((item) => item?.can_create_execution_intent === true);
      expect(executableActionIndex, JSON.stringify(reportActions)).toBeGreaterThanOrEqual(0);

      const judgmentComment = `${hotelContext.objectPrefix}_ai_report_useful`;
      const judgedReport = await api.post(`/api/ai-daily-reports/${report.id}/human-judgments`, {
        target_type: 'report_usefulness',
        decision: 'accepted',
        comment: judgmentComment,
      }, { label: 'AI daily report human judgment save' });
      expect((judgedReport.human_judgments || []).some((item) => (
        item?.target_type === 'report_usefulness'
          && item?.decision === 'accepted'
          && item?.comment === judgmentComment
      ))).toBe(true);

      const judgedReadback = await api.get(`/api/ai-daily-reports/${report.id}`, {
        label: 'AI daily report human judgment readback',
      });
      expect(Number(judgedReadback.id)).toBe(reportId);
      expect(Number(judgedReadback.hotel_id)).toBe(hotelContext.hotelId);
      expect((judgedReadback.human_judgments || []).some((item) => (
        item?.target_type === 'report_usefulness'
          && item?.decision === 'accepted'
          && item?.comment === judgmentComment
      ))).toBe(true);

      const reportActionBridge = await api.post(
        `/api/ai-daily-reports/${report.id}/actions/${executableActionIndex}/execution-intent`,
        {},
        { label: 'AI daily report executable action intent' },
      );
      expect(Number(reportActionBridge.report_id)).toBe(reportId);
      expect(Number(reportActionBridge.action_index)).toBe(executableActionIndex);
      const reportActionIntent = reportActionBridge.execution_intent || {};
      expect(Number(reportActionIntent.id)).toBeGreaterThan(0);
      expect(Number(reportActionIntent.hotel_id)).toBe(hotelContext.hotelId);
      expect(reportActionIntent.source_module).toBe('ai_daily_report');
      expect(reportActionIntent.status).toBe('pending_approval');
      expect(reportActionIntent.tasks || []).toHaveLength(0);

      const intent = await api.post('/api/operation/execution-intents', {
        hotel_id: hotelContext.hotelId,
        source_module: 'e2e_manual_workflow',
        source_record_id: report.id,
        platform: 'internal',
        object_type: 'campaign',
        action_type: 'isolated_workflow_validation',
        date_start: dataDate,
        date_end: dataDate,
        target_value: {
          campaign_type: 'isolated_workflow_validation',
          target_metric: 'orders',
        },
        evidence: {
          source_policy: 'isolated_e2e_manual_evidence_no_ota_write',
          report_id: report.id,
          reviewer_decision: 'workflow_validation_only',
        },
        expected_metric: 'orders',
        expected_delta: 0,
        risk_level: 'low',
      }, { label: 'manual workflow intent after explicit review' });
      expect(Number(intent.id)).toBeGreaterThan(0);
      expect(intent.source_module).toBe('manual');
      expect(Number(intent.source_record_id || 0)).toBe(0);
      expect(intent.status).toBe('pending_approval');
      expect(intent.tasks || []).toHaveLength(0);

      const approved = await api.post(`/api/operation/execution-intents/${intent.id}/approve`, {
        approved: true,
        remark: `${hotelContext.objectPrefix}_manual_approval`,
      }, { label: 'human approval creates execution task' });
      expect(approved.status).toBe('approved');
      const task = (approved.tasks || [])[0] || {};
      expect(Number(task.id)).toBeGreaterThan(0);
      expect(task.status).toBe('pending_execute');

      const executed = await api.post(`/api/operation/execution-tasks/${task.id}/execute`, {
        status: 'executed',
        evidence_type: 'manual_finance',
        evidence: {
          before: { revenue: 120000, scope: 'ota_channel' },
          after: { revenue: 132000, cost: 3000, scope: 'ota_channel' },
          platform_response: {
            mode: 'manual',
            scope: 'ota_channel_manual_execution',
            evidence_boundary: 'local_manual_evidence_no_ota_write',
          },
          remark: `${hotelContext.objectPrefix}_execution_evidence`,
        },
      }, { label: 'manual execution evidence' });
      expect(executed.status).toBe('executed');
      expect(Number(executed.evidence_summary?.count || 0), JSON.stringify(executed)).toBeGreaterThan(0);

      const reviewed = await api.post(`/api/operation/execution-tasks/${task.id}/review`, {
        result_status: 'success',
        result_summary: `${hotelContext.objectPrefix}_manual_effect_review`,
        readback_evidence: {
          operator_attested: true,
          operator_attested_at: new Date().toISOString(),
          source_ref: `${hotelContext.objectPrefix}_isolated_readback_receipt`,
          remark: 'isolated local E2E readback proof; no OTA write',
        },
      }, { label: 'manual effect review' });
      expect(reviewed.result_status).toBe('success');

      const tracking = await api.get('/api/operation/action-tracking', {
        params: { hotel_id: hotelContext.hotelId },
        label: 'operation action tracking reads executed task',
      });
      expect((tracking.actions || []).some((item) => Number(item.id) === Number(executed.action_track_id))).toBe(true);

      const flow = await api.get('/api/operation/execution-flow', {
        params: { hotel_id: hotelContext.hotelId },
        label: 'execution flow readback',
      });
      const flowItem = (flow.list || []).find((item) => Number(item.id) === Number(intent.id));
      expect(flowItem).toBeTruthy();
      expect(flowItem.recommendation.source_module).toBe('manual');
      expect(flowItem.approval.status).toBe('approved');
      expect(flowItem.execution.status).toBe('executed');
      expect(Number(flowItem.evidence_summary?.count || 0)).toBeGreaterThan(0);
      expect(flowItem.review.status).toBe('success');
      expect(flowItem.roi.status).toBe('ready');
      expect(Number(flowItem.roi.incremental_revenue)).toBe(12000);
      expect(Number(flowItem.roi.cost)).toBe(3000);
      expect(Number(flowItem.roi.profit)).toBe(9000);
      expect(Number(flowItem.roi.value)).toBe(300);

      const reportReadback = await api.get(`/api/ai-daily-reports/${report.id}`, {
        label: 'AI daily report readback',
      });
      const actionReadback = reportReadback.recommended_actions || [];
      expect(actionReadback.length).toBeGreaterThan(0);
      expect(actionReadback[executableActionIndex]?.can_create_execution_intent).toBe(true);
      expect(Number(actionReadback[executableActionIndex]?.execution_intent_id || 0)).toBe(Number(reportActionIntent.id));
      expect(actionReadback[executableActionIndex]?.execution_status).toBe('pending_approval');

      const deletedForRestore = await api.post('/api/online-data/delete-data', {
        id: row.id,
        reason: `${hotelContext.objectPrefix}_ledger_restore_check`,
      }, { label: 'delete OTA row into correction ledger' });
      const correctionLedgerId = Number(deletedForRestore.ledger_id || 0);
      expect(correctionLedgerId).toBeGreaterThan(0);

      await goModule(page, MODULE.DATA_TRUST);
      await page.getByRole('button', { name: '记录与下载' }).click();
      await page.getByTestId('online-data-correction-ledger-toggle').click();
      const correctionLedgerRow = page.getByTestId(`online-data-correction-ledger-row-${correctionLedgerId}`);
      await expect(correctionLedgerRow).toBeVisible({ timeout: 5000 });
      await expect(correctionLedgerRow).toContainText('可恢复');
      await page.getByTestId(`online-data-correction-ledger-restore-${correctionLedgerId}`).click();
      const restoreDialog = page.getByTestId('workflow-form-dialog');
      await expect(restoreDialog).toBeVisible();
      await restoreDialog.getByRole('textbox').fill(`恢复 ${correctionLedgerId}`);
      await restoreDialog.getByRole('button', { name: '确认恢复' }).click();
      await expect(correctionLedgerRow).toContainText('已恢复', { timeout: 5000 });

      const ledgerReadback = await api.get('/api/online-data/correction-ledger', {
        params: { page: 1, page_size: 100 },
        label: 'correction ledger restore readback',
      });
      const restoredLedger = (ledgerReadback.list || []).find((item) => Number(item.id) === correctionLedgerId);
      expect(restoredLedger).toBeTruthy();
      expect(restoredLedger.can_restore).toBe(false);
      expect(String(restoredLedger.restored_at || '')).not.toBe('');

      const restoredData = await api.get('/api/online-data/daily-data-list', {
        params: { hotel_id: otaHotelId, start_date: dataDate, end_date: dataDate, page_size: 5 },
        label: 'restored OTA row readback',
      });
      expect((restoredData.list || []).some((item) => Number(item.id) === Number(row.id))).toBe(true);

      await goModule(page, MODULE.AI_DAILY_REPORT);
      await goModule(page, MODULE.EXECUTION_TRACKING);
      const closedLoopRow = page.getByTestId('page-ops-track').locator('tbody tr').filter({
        hasText: `${hotelContext.objectPrefix}_manual_effect_review`,
      }).first();
      await expect(closedLoopRow).toBeVisible({ timeout: 5000 });
      await expect(closedLoopRow).toContainText('300');

      return [
        '页面展示正确',
        '接口返回成功',
        'OTA数据已保存',
        '收益分析和运营模块读取上游数据',
        '策略动作可回显到效果追踪',
        '更正账本删除恢复完成回读',
      ];
    },
  );
});

test('business chain: market evaluation to transfer decision dashboard', async ({ page, request }) => {
  await runBusinessCase(
    page,
    request,
    'market-transfer',
    [MODULE.AI_WORKBENCH, MODULE.EXECUTION_TRACKING],
    async ({ api, hotelContext, cleanups }) => {
      const projectName = `${hotelContext.objectPrefix}_market`;
      const e2eFallbackModelKey = `${hotelContext.objectPrefix}_missing_model`;

      const market = await api.post('/api/expansion/market-evaluation', {
        project_name: projectName,
        city_tier: '一线',
        city: '上海',
        business_area: '浦东新区世纪大道',
        property_area: 3200,
        estimated_rent: 180000,
        target_room_count: 88,
        decoration_level: '中端精选-标准',
        primary_customer: '商务差旅',
        secondary_customer: '会议会展',
        expected_adr: 328,
        expected_occupancy_rate: 76,
        competitor_count: 12,
        ota_market_penetration_rate: 68,
        model_key: e2eFallbackModelKey,
      }, { label: 'market evaluation' });
      expect(Number(market.record_id)).toBeGreaterThan(0);
      expect(market.ai_evaluation?.source).toBe('fallback');
      cleanups.push(() => api.delete(`/api/expansion/records/${market.record_id}`, { label: 'archive market record' }).catch(() => null));

      const marketDetail = await api.get(`/api/expansion/records/${market.record_id}`, { label: 'market detail echo' });
      expect(marketDetail.input.city).toBe('上海');
      expect(marketDetail.result.decision).toBeTruthy();

      const benchmark = await api.post('/api/expansion/benchmark-model', {
        project_name: projectName,
        city: marketDetail.input.city,
        business_area: marketDetail.input.business_area,
        target_price_band: market.price_band_suggestion || '300-400',
        hotel_type: '中端商务',
        target_room_count: marketDetail.input.target_room_count,
        model_key: e2eFallbackModelKey,
      }, { label: 'benchmark model reads market input' });
      expect(Number(benchmark.record_id)).toBeGreaterThan(0);
      expect(benchmark.ai_evaluation?.source).toBe('fallback');
      cleanups.push(() => api.delete(`/api/expansion/records/${benchmark.record_id}`, { label: 'archive benchmark record' }).catch(() => null));
      expect((benchmark.recommended_benchmarks || []).length).toBeGreaterThan(0);

      const pricing = await api.post('/api/transfer/pricing', {
        hotel_id: hotelContext.hotelId,
        hotel_name: hotelContext.hotelName,
        location: `${marketDetail.input.city}${marketDetail.input.business_area}`,
        room_count: marketDetail.input.target_room_count,
        monthly_revenue: 120,
        monthly_rent: 18,
        labor_cost: 8,
        utility_cost: 2,
        ota_commission: 3,
        other_fixed_cost: 1,
        decoration_investment: 260,
        remaining_lease_months: 72,
        expected_transfer_price: 320,
        occupancy_rate: 78,
        adr: 320,
        rating: 4.7,
        order_count: 600,
        licenses_complete: true,
        has_data_anomaly: false,
      }, { label: 'asset pricing' });
      expect(Number(pricing.record_id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/transfer/records/${pricing.record_id}`, { label: 'archive pricing record' }).catch(() => null));
      expect(pricing.valuation.reasonable_valuation).toBeGreaterThan(0);

      const timing = await api.post('/api/transfer/timing', {
        hotel_id: hotelContext.hotelId,
        current_revenue: 120,
        previous_revenue: 110,
        current_orders: 600,
        previous_orders: 560,
        current_adr: 320,
        previous_adr: 300,
        current_occupancy_rate: 78,
        previous_occupancy_rate: 72,
        rating: 4.7,
        holiday_days: 21,
        is_peak_season: true,
        has_data_anomaly: false,
        has_data_gap: false,
      }, { label: 'transfer timing' });
      expect(Number(timing.record_id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/transfer/records/${timing.record_id}`, { label: 'archive timing record' }).catch(() => null));
      expect(timing.decision).toBeTruthy();

      const dashboard = await api.post('/api/transfer/dashboard', {
        hotel_id: hotelContext.hotelId,
        pricing,
        timing,
        metrics: { source: 'business-chain', risk_points: market.not_recommended_risks || [] },
      }, { label: 'transfer dashboard reads pricing and timing' });
      expect(Number(dashboard.record_id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/transfer/records/${dashboard.record_id}`, { label: 'archive dashboard record' }).catch(() => null));
      expect(dashboard.final_judgement).toBeTruthy();
      expect((dashboard.cards || []).some((card) => String(card.value).includes(String(timing.timing_score)))).toBe(true);

      return [
        '页面展示正确',
        '市场评估和标杆选模已保存并可回显',
        '资产定价和时机推演已保存',
        '数据看板读取资产定价与时机推演结果',
        '空值由服务端默认值兜底',
      ];
    },
  );
});

test('business chain: strategy, quant simulation, feasibility report, and investment decision', async ({ page, request }) => {
  await runBusinessCase(
    page,
    request,
    'investment-decision',
    [MODULE.AI_WORKBENCH, MODULE.EXECUTION_TRACKING],
    async ({ api, hotelContext, cleanups }) => {
      const projectName = `${hotelContext.objectPrefix}_investment`;
      const e2eFallbackModelKey = `${hotelContext.objectPrefix}_missing_model`;

      const strategy = await api.post('/api/strategy/simulate', {
        project_name: projectName,
        model_key: e2eFallbackModelKey,
        city: '上海',
        district: '浦东新区',
        address: '世纪大道链路测试物业',
        property_area: 3200,
        room_count: 88,
        monthly_rent: 180000,
        decoration_budget: 2200000,
        lease_years: 10,
        rent_free_months: 4,
        business_type: '核心商务区',
        primary_customer: '商务差旅',
        competitor_count: 8,
        target_grade: '中端精选',
      }, { label: 'strategy simulate' });
      expect(Number(strategy.record_id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/strategy/records/${strategy.record_id}`, { label: 'archive strategy record' }).catch(() => null));
      expect(strategy.recommendation.decision_direction || strategy.decision).toBeTruthy();

      const strategyDetail = await api.get(`/api/strategy/records/${strategy.record_id}`, { label: 'strategy detail echo' });
      expect(strategyDetail.input.project_name).toBe(projectName);

      const simulationInput = {
        roomCount: 88,
        decorationInvestment: 2200000,
        furnitureInvestment: 360000,
        openingCost: 220000,
        otherInvestment: 120000,
        adr: 328,
        occupancyRate: 76,
        otherIncome: 12000,
        monthlyRent: 180000,
        laborCost: 88000,
        utilityCost: 26000,
        otaCommissionRate: 12,
        consumableCost: 18000,
        maintenanceCost: 12000,
        otherFixedCost: 10000,
      };
      const simulation = await api.post('/api/simulation/calculate', {
        project_name: projectName,
        input: simulationInput,
      }, { label: 'quant simulation calculate' });
      expect(Number(simulation.id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/simulation/records/${simulation.id}`, { label: 'archive simulation record' }).catch(() => null));
      expect(simulation.result.revPAR).toBeGreaterThan(0);

      const feasibility = await api.post('/api/agent/feasibility-report/generate', {
        project_name: projectName,
        city: '上海',
        district: '浦东新区',
        address: '世纪大道链路测试物业',
        property_area: 3200,
        room_count: 88,
        monthly_rent: 180000,
        lease_years: 10,
        decoration_budget: 2200000,
        transfer_fee: 0,
        opening_cost: 220000,
        adr: simulationInput.adr,
        occ: simulationInput.occupancyRate,
        target_brand_level: '中端精选',
        target_customer: '商务差旅',
        notes: `读取战略记录 ${strategy.record_id} 与量化记录 ${simulation.id}`,
        model_key: e2eFallbackModelKey,
      }, { label: 'feasibility report generate with fallback' });
      expect(Number(feasibility.id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete(`/api/agent/feasibility-report/${feasibility.id}`, { label: 'archive feasibility report' }).catch(() => null));
      expect(feasibility.project_name).toBe(projectName);
      expect(['A', 'B', 'C', 'D']).toContain(feasibility.conclusion_grade);

      const feasibilityDetail = await api.get(`/api/agent/feasibility-report/detail/${feasibility.id}`, { label: 'feasibility detail echo' });
      expect(feasibilityDetail.input.project_name).toBe(projectName);
      expect((feasibilityDetail.report.financial_scenarios || []).length).toBe(3);
      expect(feasibilityDetail.report.summary.room_count).toBe(88);

      return [
        '页面展示正确',
        '战略推演保存并可回显',
        '量化模拟保存并可回显',
        '可行性报告保存并读取战略/量化输入',
        `投资决策结论=${feasibility.conclusion_grade}`,
      ];
    },
  );
});
