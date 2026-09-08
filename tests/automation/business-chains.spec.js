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
      const expectedStatus = Number(options.expectedStatus || 200);
      const expectedCode = Number(options.expectedCode || 200);
      const ok = response.status() === expectedStatus && Number(body.code) === expectedCode;
      apiEvents.push({
        label: options.label || pathname,
        method: upper,
        path: pathname,
        status: response.status(),
        code: body.code,
        category: ok ? null : apiFailure(options.label || pathname, method, pathname, response, body).category,
        expectedStatus,
        expectedCode,
        outcome: ok && expectedStatus >= 400 ? 'expected-rejection' : ok ? 'success' : 'unexpected-response',
        message: ok ? null : body.message,
        timestamp: new Date().toISOString(),
      });
      if (!ok) {
        throw apiFailure(options.label || pathname, method, pathname, response, body);
      }
      return options.returnEnvelope
        ? {
            http_status: response.status(),
            code: Number(body.code),
            message: String(body.message || ''),
            data: body.data,
          }
        : body.data;
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

function runIsolationFixture(action, label, hotelContext) {
  if (process.env.SUXI_E2E_ISOLATED_RUNNER !== '1') {
    throw new Error(`${label} requires the isolated E2E runner`);
  }
  const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
  const helper = path.join(__dirname, 'e2e-isolation-helper.php');
  const result = spawnSync(php, [helper, action], {
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
    const error = new Error(`${label} failed${detail ? `: ${detail}` : ''}`);
    error.category = 'test-data-invalid';
    throw error;
  }
  try {
    return JSON.parse(String(result.stdout || '').trim());
  } catch {
    const error = new Error(`${label} returned invalid JSON`);
    error.category = 'test-data-invalid';
    throw error;
  }
}

function seedAiReportInputFixture(hotelContext) {
  return runIsolationFixture('seed-ai-report-inputs', 'AI report input fixture', hotelContext);
}

function seedSyntheticAiReportFixture(hotelContext) {
  return runIsolationFixture('seed-synthetic-ai-report', 'Synthetic non-formal AI report fixture', hotelContext);
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
      expect(Object.values(reportInputFixture.data_source_ids || {})).toHaveLength(2);
      expect(Object.values(reportInputFixture.data_source_ids || {}).every((id) => Number(id) > 0)).toBe(true);
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
      expect(save.analysis_eligible_count).toBe(0);
      expect(save.ingestion_method).toBe('user_provided_unverified');

      const imported = await api.get('/api/online-data/daily-data-list', {
        params: { system_hotel_id: hotelContext.hotelId, ota_hotel_id: otaHotelId, start_date: dataDate, end_date: dataDate, page_size: 5 },
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
        params: { system_hotel_id: hotelContext.hotelId, start_date: dataDate, end_date: dataDate },
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
      const formalGenerationGate = await api.post('/api/ai-daily-reports/generate', {
        hotel_id: hotelContext.hotelId,
        report_date: dataDate,
        use_llm: false,
        background: true,
      }, {
        label: 'formal AI daily report rejects synthetic non-P0 data',
        expectedStatus: 409,
        expectedCode: 409,
        returnEnvelope: true,
      });
      expect(formalGenerationGate.http_status).toBe(409);
      expect(formalGenerationGate.message).toContain('P0 verifier');
      expect(formalGenerationGate.data?.status).toBe('blocked_by_p0_ota_gate');
      expect(formalGenerationGate.data?.formal_report_generated).toBe(false);
      expect(Number(formalGenerationGate.data?.hotel_id || 0)).toBe(hotelContext.hotelId);
      expect(formalGenerationGate.data?.target_date).toBe(dataDate);
      expect(formalGenerationGate.data?.p0_downstream_gate?.status).not.toBe('ready');

      const syntheticReportFixture = seedSyntheticAiReportFixture(hotelContext);
      expect(syntheticReportFixture.persistence_readback_verified).toBe(true);
      expect(syntheticReportFixture.formal_report_generated).toBe(false);
      expect(syntheticReportFixture.input_trust_readback_verified).toBe(false);
      expect(Number(syntheticReportFixture.hotel_id)).toBe(hotelContext.hotelId);
      expect(syntheticReportFixture.report_date).toBe(dataDate);
      expect(syntheticReportFixture.generation_mode).toBe('synthetic_e2e');
      const reportId = Number(syntheticReportFixture.report_id || 0);
      expect(reportId).toBeGreaterThan(0);

      const report = await api.get(`/api/ai-daily-reports/${reportId}`, {
        label: 'synthetic non-formal AI report exact id and hotel readback',
      });
      expect(Number(report.id)).toBeGreaterThan(0);
      expect(Number(report.id)).toBe(reportId);
      expect(Number(report.hotel_id)).toBe(hotelContext.hotelId);
      expect(report.report_date).toBe(dataDate);
      expect(report.generation_mode).toBe('synthetic_e2e');
      expect(report.model_status).toBe('not_requested');
      expect(report.snapshot?.synthetic).toBe(true);
      expect(report.snapshot?.formal_report_generated).toBe(false);
      expect(report.snapshot?.input_trust?.readback_verified).toBe(false);
      const reportActions = report.recommended_actions || [];
      expect(reportActions).toHaveLength(1);
      const blockedActionIndex = 0;
      expect(reportActions[blockedActionIndex]?.can_create_execution_intent).toBe(false);
      expect(reportActions[blockedActionIndex]?.blocked_reason).toContain('Synthetic E2E input');

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

      const reportActionGate = await api.post(
        `/api/ai-daily-reports/${report.id}/actions/${blockedActionIndex}/execution-intent`,
        {},
        {
          label: 'synthetic AI daily report action remains non-executable',
          expectedStatus: 422,
          expectedCode: 422,
          returnEnvelope: true,
        },
      );
      expect(reportActionGate.http_status).toBe(422);
      expect(reportActionGate.message).toContain('Trusted OTA readback verification is required');

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
        hotel_id: hotelContext.hotelId,
        system_hotel_id: hotelContext.hotelId,
        approved: true,
        remark: `${hotelContext.objectPrefix}_manual_approval`,
      }, { label: 'human approval creates execution task' });
      expect(approved.status).toBe('approved');
      const task = (approved.tasks || [])[0] || {};
      expect(Number(task.id)).toBeGreaterThan(0);
      expect(task.status).toBe('pending_execute');

      const executed = await api.post(`/api/operation/execution-tasks/${task.id}/execute`, {
        hotel_id: hotelContext.hotelId,
        system_hotel_id: hotelContext.hotelId,
        status: 'executed',
        evidence_type: 'manual_execution',
        evidence: {
          before: { status: 'approved_pending_manual_execution', scope: 'isolated_workflow' },
          after: { status: 'executed_by_human', scope: 'isolated_workflow' },
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

      const rejectedSuccessReview = await api.post(`/api/operation/execution-tasks/${task.id}/review`, {
        hotel_id: hotelContext.hotelId,
        system_hotel_id: hotelContext.hotelId,
        result_status: 'success',
        result_summary: `${hotelContext.objectPrefix}_manual_effect_review`,
        readback_evidence: {
          operator_attested: true,
          operator_attested_at: new Date().toISOString(),
          source_ref: `${hotelContext.objectPrefix}_isolated_readback_receipt`,
          remark: 'isolated local E2E readback proof; no OTA write',
        },
      }, {
        label: 'manual evidence cannot claim successful effect review',
        expectedStatus: 422,
        expectedCode: 422,
        returnEnvelope: true,
      });
      expect(rejectedSuccessReview.http_status).toBe(422);
      expect(rejectedSuccessReview.message).toContain('source-verified business metric readback is required');

      const observingSummary = `${hotelContext.objectPrefix}_awaiting_source_verified_readback`;
      const reviewed = await api.post(`/api/operation/execution-tasks/${task.id}/review`, {
        hotel_id: hotelContext.hotelId,
        system_hotel_id: hotelContext.hotelId,
        result_status: 'observing',
        result_summary: observingSummary,
        readback_evidence: {
          operator_attested: true,
          operator_attested_at: new Date().toISOString(),
          source_ref: `${hotelContext.objectPrefix}_isolated_readback_receipt`,
          remark: 'isolated local E2E operator attestation; no source-verified OTA outcome',
        },
      }, { label: 'manual effect remains observing pending source-verified readback' });
      expect(reviewed.result_status).toBe('observing');
      expect(reviewed.evidence_truth?.source_verified).toBe(false);
      expect(reviewed.evidence_truth?.operator_attested).toBe(true);

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
      expect(flowItem.stage).toBe('evidence');
      expect(flowItem.evidence_truth?.source_verified).toBe(false);
      expect(flowItem.evidence_truth?.operator_attested).toBe(true);
      expect(flowItem.truth_context?.status).toBe('partial');
      expect(flowItem.truth_context?.failure_reason).toBe('operator_attested_only');
      expect(flowItem.review.status).toBe('observing');
      expect(flowItem.review.reported_status).toBe('observing');
      expect(flowItem.roi.status).toBe('partial');
      expect(flowItem.roi.failure_reason).toBe('operator_attested_only');
      expect(flowItem.roi.incremental_revenue).toBeNull();
      expect(flowItem.roi.cost).toBeNull();
      expect(flowItem.roi.profit).toBeNull();
      expect(flowItem.roi.value).toBeNull();

      const reportReadback = await api.get(`/api/ai-daily-reports/${report.id}`, {
        label: 'synthetic non-formal AI report readback',
      });
      const actionReadback = reportReadback.recommended_actions || [];
      expect(actionReadback.length).toBeGreaterThan(0);
      expect(actionReadback[blockedActionIndex]?.can_create_execution_intent).toBe(false);
      expect(Number(actionReadback[blockedActionIndex]?.execution_intent_id || 0)).toBe(0);
      expect(actionReadback[blockedActionIndex]?.blocked_reason).toContain('Synthetic E2E input');

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
      const restoreResponsePromise = page.waitForResponse((response) => (
        response.request().method() === 'POST'
          && new URL(response.url()).pathname === '/api/online-data/restore-data'
      ));
      await restoreDialog.getByRole('button', { name: '确认恢复' }).click();
      const restoreResponse = await restoreResponsePromise;
      const restoreEnvelope = await restoreResponse.json();
      expect(restoreResponse.status(), JSON.stringify(restoreEnvelope)).toBe(200);
      expect(restoreEnvelope.code, JSON.stringify(restoreEnvelope)).toBe(200);
      expect(Number(restoreEnvelope.data?.id || 0), JSON.stringify(restoreEnvelope)).toBe(Number(row.id));
      expect(Number(restoreEnvelope.data?.ledger?.id || 0), JSON.stringify(restoreEnvelope)).toBe(correctionLedgerId);
      expect(restoreEnvelope.data?.ledger?.can_restore, JSON.stringify(restoreEnvelope)).toBe(false);
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
        params: { system_hotel_id: hotelContext.hotelId, ota_hotel_id: otaHotelId, start_date: dataDate, end_date: dataDate, page_size: 5 },
        label: 'restored OTA row readback',
      });
      expect((restoredData.list || []).some((item) => Number(item.id) === Number(row.id))).toBe(true);

      await goModule(page, MODULE.AI_DAILY_REPORT);
      await goModule(page, MODULE.EXECUTION_TRACKING);
      // This manual fixture is unassigned; the page now correctly starts in "my tasks".
      const taskScope = page.getByTestId('page-ops-track').getByLabel('任务范围', { exact: true });
      await expect(taskScope).toHaveValue('mine');
      await taskScope.selectOption('all');
      const closedLoopRow = page.getByTestId('page-ops-track').locator('tbody tr').filter({
        hasText: observingSummary,
      }).first();
      await expect(closedLoopRow).toBeVisible({ timeout: 5000 });
      await expect(closedLoopRow).toContainText(observingSummary);
      await expect(closedLoopRow).not.toContainText('300%');

      return [
        '页面展示正确',
        '接口返回成功',
        'OTA数据已保存',
        '收益分析和运营模块读取上游数据',
        '合成数据未绕过正式 P0 日报门禁',
        '显式手工动作可回显且未伪造成功复盘或ROI',
        '更正账本删除恢复完成回读',
      ];
    },
  );
});

// Requirement revision: retired modules reject writes and preserve history.
// The active OTA/operations chain above and the calculator below remain covered.
test('business chain: retired modules preserve history and reject generation or execution', async ({ page, request }) => {
  await runBusinessCase(page, request, 'retired-history', [MODULE.AI_WORKBENCH, MODULE.EXECUTION_TRACKING],
    async ({ api, hotelContext }) => {
      const fixture = runIsolationFixture('seed-retired-history', 'Retired historical records', hotelContext);
      const modules = [
        { key: 'expansion', list: '/api/expansion/records', detail: '/api/expansion/records/', writes: ['/api/expansion/market-evaluation', '/api/expansion/benchmark-model', '/api/expansion/collaboration-efficiency'] },
        { key: 'transfer', list: '/api/transfer/records', detail: '/api/transfer/records/', writes: ['/api/transfer/pricing', '/api/transfer/timing', '/api/transfer/dashboard'] },
        { key: 'strategy', list: '/api/strategy/records', detail: '/api/strategy/records/', writes: ['/api/strategy/simulate'] },
        { key: 'feasibility', list: '/api/agent/feasibility-report/list', detail: '/api/agent/feasibility-report/detail/', writes: ['/api/agent/feasibility-report/generate'] },
      ];
      for (const mod of modules) {
        const saved = fixture[mod.key];
        expect(Number(saved.id)).toBeGreaterThan(0);
        const before = await api.get(mod.list, { label: mod.key + ' history before' });
        expect(before.list.some((row) => Number(row.id) === Number(saved.id))).toBe(true);
        const detailPath = mod.detail + saved.id;
        const detail = await api.get(detailPath, { label: mod.key + ' historical detail' });
        expect(Number(detail.id)).toBe(Number(saved.id));
        expect((detail.input || detail.input_json).project_name).toBe(saved.project_name);
        const mutationPath = mod.key === 'feasibility'
          ? '/api/agent/feasibility-report/' + saved.id
          : detailPath;
        const writes = [...mod.writes, mutationPath + '/execution-intent'];
        if (mod.key === 'feasibility') writes.push('/api/agent/feasibility-report/regenerate/' + saved.id);
        const expectedRejection = { expectedStatus: 410, expectedCode: 410, returnEnvelope: true };
        for (const pathname of writes) {
          const rejection = await api.post(pathname, {
            hotel_id: hotelContext.hotelId, project_name: saved.project_name,
          }, { ...expectedRejection, label: mod.key + ' retired write rejected' });
          expect(rejection.data).toEqual({
            status: 'retired_read_only', history_preserved: true, next_entry: 'ops-track',
          });
        }
        const deletion = await api.delete(mutationPath, {
          ...expectedRejection, label: mod.key + ' history deletion rejected',
        });
        expect(deletion.data.history_preserved).toBe(true);
        const after = await api.get(mod.list, { label: mod.key + ' history unchanged' });
        expect(after.list.map((row) => Number(row.id))).toEqual(before.list.map((row) => Number(row.id)));
        const readback = await api.get(detailPath, { label: mod.key + ' exact historical readback' });
        expect(readback.input || readback.input_json).toEqual(detail.input || detail.input_json);
      }
      return ['四类停用模块均拒绝生成、执行和删除', '历史列表及原始输入保持可回读', '合成历史夹具由隔离运行器清理'];
    });
});

test('business chain: quantitative calculator remains available with saved readback', async ({ page, request }) => {
  await runBusinessCase(page, request, 'quantitative-calculator', [MODULE.AI_WORKBENCH, MODULE.EXECUTION_TRACKING],
    async ({ api, hotelContext, cleanups }) => {
      const projectName = hotelContext.objectPrefix + '_investment';
      const simulationInput = {
        roomCount: 88, decorationInvestment: 2200000, furnitureInvestment: 360000,
        openingCost: 220000, otherInvestment: 120000, adr: 328, occupancyRate: 76,
        otherIncome: 12000, monthlyRent: 180000, laborCost: 88000, utilityCost: 26000,
        otaCommissionRate: 12, consumableCost: 18000, maintenanceCost: 12000, otherFixedCost: 10000,
      };
      const simulation = await api.post('/api/simulation/calculate', {
        project_name: projectName, input: simulationInput,
      }, { label: 'quant simulation calculate' });
      expect(Number(simulation.id)).toBeGreaterThan(0);
      cleanups.push(() => api.delete('/api/simulation/records/' + simulation.id, { label: 'archive simulation record' }));
      expect(simulation.result.revPAR).toBeCloseTo(328 * 0.76, 2);
      const readback = await api.get('/api/simulation/records/' + simulation.id, { label: 'quant simulation saved readback' });
      expect(Number(readback.id)).toBe(Number(simulation.id));
      expect(readback.project_name).toBe(projectName);
      expect(readback.result.revPAR).toBeCloseTo(simulation.result.revPAR, 2);
      return ['量化计算仍可使用', '独立假设计算结果保存并精确回读', '不生成停用模块报告或经营执行'];
    });
});
