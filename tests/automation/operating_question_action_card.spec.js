const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

const appUrl = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const businessDate = '2026-08-12';
const questionDigest = 'a'.repeat(64);
const actionDigest = 'b'.repeat(64);
const user = {
  id: 801,
  username: 'operating_question_action_probe',
  realname: '经营问答行动卡验收账号',
  role_name: 'Administrator',
  is_super_admin: true,
  permissions: {
    can_manage_own_hotels: true,
    can_fetch_online_data: true,
    can_view_online_data: true,
    can_view_report: true,
    can_fill_daily_report: true,
  },
  capabilities: ['all'],
  hotel_id: 7,
  tenant_id: 7,
  permitted_hotels: [{ id: 7, name: '经营问答行动卡验收酒店', tenant_id: 7, status: 1 }],
  context: {
    token_status: 'valid',
    tenantId: 7,
    hotelId: 7,
    permitted_hotel_ids: [7],
    permissionStatus: 'allowed',
  },
};

const action = {
  contract_version: 'operating_question_action_draft.v2',
  title: '复核携程曝光到详情访问链路',
  action: '人工复核目标日携程列表曝光、详情曝光和页面展示配置，并保存核对记录。',
  action_object: '携程曝光到详情访问链路',
  execution_steps: ['核对目标日列表曝光与详情曝光', '人工检查页面展示配置并记录差异'],
  priority: 'P1',
  expected_metric: 'list_exposure',
  review_window: '完成复核后按同酒店同渠道同业务日口径再次回读',
  risk_level: 'medium',
  risk: {
    level: 'medium',
    summary: '单日流量波动可能受外部因素影响，不能直接归因为页面配置。',
    controls: ['人工确认对象和日期，不在本流程修改 OTA 配置'],
  },
  expected_effect: {
    summary: '按同酒店、同渠道、同日期口径复核 list_exposure；当前不承诺提升幅度。',
  },
  stop_conditions: ['发现酒店、渠道或业务日身份不一致时停止'],
  evidence_refs: ['online_daily_data#1201'],
  scope: {
    tenant_id: 7,
    hotel_id: 7,
    platform: 'ctrip',
    date_start: businessDate,
    date_end: businessDate,
    source_scope: 'ota_channel',
  },
  status: 'ready_for_human_review',
  can_create_execution_intent: true,
  decision_quality: {
    contract_version: 'ai_recommendation_quality.v2',
    complete: true,
    execution_ready: true,
  },
  boundaries: {
    human_confirmation_required: true,
    automatic_collection: false,
    automatic_execution: false,
    ota_write: false,
    external_message: false,
  },
  action_digest: actionDigest,
};

const question = {
  id: 71,
  tenant_id: 7,
  hotel_id: 7,
  question_text: '今天应复核哪条携程流量链路？',
  platform: 'ctrip',
  date_start: businessDate,
  date_end: businessDate,
  answer_status: 'answered_by_grounded_ai',
  answer_summary: '目标日携程流量事实已严格回读，可先进行人工链路复核。',
  answer: {
    status: 'answered_by_grounded_ai',
    confidence: 'medium',
    ai_runtime: {
      status: 'ready',
      provider: 'deepseek',
      model_key: 'deepseek_v4_pro',
      model: 'deepseek-v4-pro',
      configured_model: 'deepseek-v4-pro',
      response_model: 'deepseek-v4-pro',
      provider_response_id: 'chatcmpl-action-proof-0001',
      provider_created_at: Math.floor(Date.now() / 1000),
      provider_response_fresh: true,
      provider_endpoint_origin: 'https://api.deepseek.com',
      provider_endpoint_host: 'api.deepseek.com',
      provider_endpoint_official: true,
      provider_config_digest: 'c'.repeat(64),
      direct_call_nonce: 'oq_action_proof_0001',
      transport_request_id: 'oq_action_proof_0001',
      transport_retry_attempts: 0,
      upstream_idempotency_key_sent: false,
      http_status: 200,
      provider_attempt_count: 1,
      idempotent_replay: false,
      direct_request_proof: true,
      thinking_mode: 'enabled',
      reasoning_effort: 'high',
      prompt_version: 'operating_question_grounded_ai.zh-CN.v4',
      finish_reason: 'stop',
      external_llm_called: true,
      external_llm_call_status: 'confirmed_direct_deepseek_v4_pro',
      fallback_used: false,
      cache_hit: false,
      degraded: false,
    },
    evidence_counts: { facts: 1, knowledge_chunks: 0, operating_memories: 0, execution_reviews: 0 },
    key_points: ['列表曝光与详情曝光均有同日事实。'],
    action_drafts: [action],
  },
  fact_refs: ['online_daily_data#1201'],
  memory_refs: [],
  knowledge_refs: [],
  execution_refs: [],
  data_gaps: [],
  content_digest: questionDigest,
  readback_verified: true,
};

const intent = {
  id: 901,
  tenant_id: 7,
  hotel_id: 7,
  source_module: 'operating_question',
  source_record_id: 71,
  platform: 'ctrip',
  object_type: 'operation_checklist',
  action_type: 'human_reviewed_operating_check',
  date_start: businessDate,
  date_end: businessDate,
  expected_metric: 'list_exposure',
  blocked_reason: '',
  status: 'pending_approval',
  evidence: {
    question_content_digest: questionDigest,
    action_index: 0,
    action_draft_digest: actionDigest,
    boundaries: action.boundaries,
  },
  tasks: [],
};

const installAuthenticatedMocks = async (page, calls, { questionResponse = question } = {}) => {
  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'operating-question-action-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({ saved_at: Date.now(), user: profile }));
  }, user);
  await page.route('**/api/**', async route => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    calls.push({ method: request.method(), pathname, body: request.postDataJSON?.() || null });
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') data = { list: user.permitted_hotels, total: 1 };
    if (pathname === '/api/agent/operating-questions' && request.method() === 'POST') {
      data = { question: questionResponse, created: true, persistence_status: 'readback_verified' };
    }
    if (pathname === '/api/agent/operating-questions/71' && request.method() === 'GET') data = questionResponse;
    if (pathname === '/api/agent/operating-questions/71/action-drafts/0/execution-intent' && request.method() === 'POST') {
      await new Promise(resolve => setTimeout(resolve, 80));
      data = { execution_intent: intent, reused_existing_intent: false };
    }
    if (pathname === '/api/operation/execution-intents/901' && request.method() === 'GET') data = intent;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });
};

test('grounded operating answer submits one evidence-locked action for human approval only', async ({ page }) => {
  test.setTimeout(45000);
  const calls = [];
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await installAuthenticatedMocks(page, calls);

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await expect(page.getByTestId('operating-question-entry')).toBeVisible({ timeout: 15000 });

  await page.getByPlaceholder('例如：这家店今天最需要复核什么？').fill(question.question_text);
  await page.getByRole('button', { name: '提交并回读' }).click();
  const card = page.getByTestId('operating-question-action-card');
  await expect(card).toBeVisible();
  await expect(card).toContainText('AI 行动草案 · 待人工确认');
  await expect(card).toContainText('证据门已通过');
  await expect(card).toContainText('复核指标：list_exposure');
  await expect(card).toContainText('不会自动批准、采集或写 OTA');

  await page.getByTestId('operating-question-action-submit').click();
  await expect(page.getByTestId('operating-question-action-open')).toBeVisible();
  await expect(page.getByTestId('operating-question-action-open')).toContainText('pending_approval');

  const postPaths = calls.filter(call => call.method === 'POST').map(call => call.pathname);
  expect(postPaths).toEqual([
    '/api/agent/operating-questions',
    '/api/agent/operating-questions/71/action-drafts/0/execution-intent',
  ]);
  expect(calls.find(call => call.pathname === '/api/agent/operating-questions').body.model_key)
    .toBe('deepseek_v4_pro');
  expect(calls.some(call => /approve|collect|fetch|apply/.test(call.pathname) && call.method === 'POST')).toBe(false);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('stale or low-confidence action remains blocked in the UI and never posts an intent', async ({ page }) => {
  test.setTimeout(45000);
  const calls = [];
  const staleQuestion = structuredClone(question);
  staleQuestion.answer.confidence = 'low';
  staleQuestion.answer.ai_runtime.prompt_version = 'operating_question_grounded_ai.zh-CN.v2';
  await installAuthenticatedMocks(page, calls, { questionResponse: staleQuestion });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await page.getByPlaceholder('例如：这家店今天最需要复核什么？').fill(staleQuestion.question_text);
  await page.getByRole('button', { name: '提交并回读' }).click();

  const card = page.getByTestId('operating-question-action-card');
  await expect(card).toBeVisible();
  await expect(card).toContainText('需补齐后提交');
  await expect(card).not.toContainText('证据门已通过');
  await expect(page.getByTestId('operating-question-action-submit')).toHaveCount(0);
  expect(calls.some(call => call.pathname.includes('/execution-intent') && call.method === 'POST')).toBe(false);
});

for (const [label, mutate] of [
  ['Flash response model', runtime => { runtime.response_model = 'deepseek-v4-flash'; runtime.direct_request_proof = false; }],
  ['cache replay', runtime => { runtime.cache_hit = true; runtime.direct_request_proof = false; }],
  ['provider fallback', runtime => { runtime.fallback_used = true; runtime.direct_request_proof = false; }],
  ['unofficial endpoint', runtime => { runtime.provider_endpoint_origin = 'https://gateway.example.com'; runtime.provider_endpoint_official = false; runtime.direct_request_proof = false; }],
  ['stale provider response', runtime => { runtime.provider_response_fresh = false; runtime.direct_request_proof = false; }],
  ['retry or idempotency', runtime => { runtime.transport_retry_attempts = 1; runtime.upstream_idempotency_key_sent = true; runtime.direct_request_proof = false; }],
]) {
  test(`${label} proof never exposes the pending-action submit`, async ({ page }) => {
    const calls = [];
    const rejected = structuredClone(question);
    mutate(rejected.answer.ai_runtime);
    await installAuthenticatedMocks(page, calls, { questionResponse: rejected });
    await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
    await page.getByTestId('nav-lean-more').click();
    await page.getByTestId('nav-agent-center').click();
    await page.getByPlaceholder('例如：这家店今天最需要复核什么？').fill(rejected.question_text);
    await page.getByRole('button', { name: '提交并回读' }).click();
    await expect(page.getByTestId('operating-question-action-card')).toContainText('需补齐后提交');
    await expect(page.getByTestId('operating-question-action-submit')).toHaveCount(0);
    expect(calls.some(call => call.pathname.includes('/execution-intent') && call.method === 'POST')).toBe(false);
  });
}
