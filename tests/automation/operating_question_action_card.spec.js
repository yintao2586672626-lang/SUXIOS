const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  headless: true,
  viewport: { width: 1440, height: 1000 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

const appUrl = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const currentDate = new Date();
const businessDate = [
  currentDate.getFullYear(),
  String(currentDate.getMonth() + 1).padStart(2, '0'),
  String(currentDate.getDate()).padStart(2, '0'),
].join('-');
const questionDigest = 'a'.repeat(64);
const actionDigest = 'b'.repeat(64);
const actionCardDigest = 'c'.repeat(64);
const approvalTargetDigest = 'd'.repeat(64);
const metricDefinitionDigest = 'e'.repeat(64);
const reviewDate = (() => {
  const value = new Date(`${businessDate}T12:00:00`);
  value.setDate(value.getDate() + 1);
  return [
    value.getFullYear(),
    String(value.getMonth() + 1).padStart(2, '0'),
    String(value.getDate()).padStart(2, '0'),
  ].join('-');
})();
const workflowSchedule = {
  assignee_id: 801,
  due_at: `${reviewDate} 12:00:00`,
  review_at: `${reviewDate} 18:00:00`,
};
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
    status: 'verification_target',
    direction: 'verify',
    metric: 'list_exposure',
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
  status: 'ready_for_ai_review',
  can_create_execution_intent: true,
  decision_quality: {
    contract_version: 'ai_recommendation_quality.v2',
    complete: true,
    execution_ready: true,
  },
  boundaries: {
    human_confirmation_required: false,
    independent_ai_review_required: true,
    automatic_collection: false,
    automatic_execution: false,
    ota_write: false,
    external_message: false,
  },
  action_digest: actionDigest,
};

const actionCard = {
  contract_version: 'operation_action_card.v1',
  content_digest: actionCardDigest,
  title: action.title,
  action: action.action,
  approval: { mode: 'human_confirmation' },
  responsibility: { owner_id: user.id, owner_name: user.realname },
  metric_contract: {
    metric_key: 'list_exposure',
    unit: 'count',
    target_type: 'observation',
    expected_direction: 'observe',
  },
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
      prompt_version: 'operating_question_grounded_ai.zh-CN.v5',
      finish_reason: 'stop',
      external_llm_called: true,
      external_llm_call_status: 'confirmed_success',
      fallback_used: false,
      cache_hit: false,
      degraded: false,
    },
    evidence_counts: { facts: 1, knowledge_chunks: 0, operating_memories: 0, execution_reviews: 0 },
    decision_frame: {
      contract_version: 'revenue_decision_frame.v1',
      framework_name: '收益决策八维框架',
      requested_object: '',
      classification_status: 'inferred',
      selection_basis: 'question_keyword_match',
      primary_object: 'channel',
      primary_label: '渠道',
      candidate_objects: [{ key: 'channel', label: '渠道', score: 2 }],
      matched_terms: ['携程', '流量'],
      key_inputs: ['漏斗', '间夜', '收入', '佣金', '取消'],
      core_boundary: '毛收入和订单量不等于净贡献。',
      method_refs: {
        primary: ['RM-M06'],
        supporting: ['RM-M02', 'RM-M08'],
        definition_status: 'source_codes_only_definitions_not_provided',
      },
      evidence_gate: {
        status: 'fact_packet_available_inputs_not_assessed',
        fact_count: 1,
        key_input_coverage: 'not_assessed',
        key_inputs_verified: false,
        can_execute: false,
        message: '已存在严格回读事实包，但尚未证明关键输入逐项齐全；仍需按输入清单核对。',
      },
      framework_boundary: '该框架只组织分析，不生成经营事实；RM代码仅保留来源索引，因定义未提供，不执行或解释未知方法。',
    },
    key_points: ['列表曝光与详情曝光均有同日事实。'],
    action_drafts: [action],
  },
  fact_refs: ['online_daily_data#1201'],
  memory_refs: [],
  knowledge_refs: [],
  execution_refs: [],
  data_gaps: [],
  content_digest: questionDigest,
};

const pendingIntent = {
  id: 901,
  tenant_id: 7,
  hotel_id: 7,
  source_module: 'operating_question',
  source_record_id: 71,
  platform: 'ctrip',
  object_type: 'operation_checklist',
  action_type: 'ai_reviewed_operating_check',
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
    ai_independent_review: {
      contract_version: 'operation_action_ai_independent_review.v1',
      status: 'approved',
      decision: 'approve',
      automatic_ota_write: false,
      automatic_execution: false,
    },
  },
  target_value: {
    action_card: actionCard,
    workflow_schedule: workflowSchedule,
  },
  action_management: {
    contract_version: 'operation_action_card.v1',
    lifecycle: { status: 'pending_approval' },
    action_card: actionCard,
  },
  tasks: [],
};

const buildApprovedIntent = (approvalPayload = {}) => {
  const approvalTarget = {
    expected_metric: approvalPayload.expected_metric || 'list_exposure',
    expected_direction: approvalPayload.expected_direction || 'observe',
    target_type: approvalPayload.target_type || 'observation',
    review_business_date: approvalPayload.review_business_date || reviewDate,
    metric_definition: { metric_key: 'list_exposure', unit: 'count' },
    metric_definition_digest: metricDefinitionDigest,
    content_digest: approvalTargetDigest,
  };
  return {
    ...structuredClone(pendingIntent),
    status: 'approved',
    evidence: {
      ...structuredClone(pendingIntent.evidence),
      approval_target: approvalTarget,
    },
    action_management: {
      ...structuredClone(pendingIntent.action_management),
      lifecycle: { status: 'approved' },
      action_card: {
        ...structuredClone(actionCard),
        responsibility: {
          owner_id: Number(approvalPayload.assignee_id || user.id),
          owner_name: user.realname,
        },
      },
    },
    tasks: [{
      id: 902,
      intent_id: 901,
      tenant_id: 7,
      hotel_id: 7,
      execution_mode: 'manual',
      status: 'pending_execute',
      result_status: '',
      target_value: { approval_target_digest: approvalTargetDigest },
      evidence: [],
      action_management: { lifecycle: { status: 'approved' } },
    }],
  };
};

const buildExecutionFlowItem = (intent) => {
  const task = Array.isArray(intent?.tasks) ? intent.tasks[0] : null;
  const lifecycleStatus = String(intent?.action_management?.lifecycle?.status || intent?.status || 'pending_approval');
  const executionStatus = String(task?.status || 'not_created');
  return {
    id: Number(intent.id),
    tenant_id: Number(intent.tenant_id),
    hotel_id: Number(intent.hotel_id),
    stage: lifecycleStatus,
    recommendation: {
      source_module: intent.source_module,
      source_record_id: intent.source_record_id,
      platform: intent.platform,
      object_type: intent.object_type,
      action_type: intent.action_type,
      expected_metric: intent.expected_metric,
      date_start: intent.date_start,
      date_end: intent.date_end,
      target_value: structuredClone(intent.target_value),
      evidence: {
        ...structuredClone(intent.evidence),
        decision_recommendation: { expected_effect: structuredClone(action.expected_effect) },
      },
    },
    approval: { status: intent.status, blocked_reason: intent.blocked_reason || '' },
    assignment: task ? {
      status: 'scheduled',
      assignee_id: user.id,
      due_at: workflowSchedule.due_at,
      review_at: workflowSchedule.review_at,
    } : null,
    execution: {
      status: executionStatus,
      task_id: task ? Number(task.id) : 0,
      hotel_id: Number(intent.hotel_id),
      mode: 'manual',
    },
    action_management: structuredClone(intent.action_management),
    evidence_summary: {
      count: Array.isArray(task?.evidence) ? task.evidence.length : 0,
      types: Array.isArray(task?.evidence) ? task.evidence.map(row => row.evidence_type) : [],
    },
    evidence_truth: { source_verified: false, operator_attested: Array.isArray(task?.evidence) && task.evidence.length > 0 },
    review: { status: task?.result_status || 'not_started', is_available: false },
    roi: { status: 'not_available' },
    next_action: lifecycleStatus === 'pending_approval'
      ? { key: 'approve', label: '待人工审批' }
      : executionStatus === 'pending_execute'
        ? { key: 'start_execution', label: '开始任务' }
        : executionStatus === 'executing'
          ? { key: 'record_evidence', label: '记录执行证据' }
          : { key: 'review', label: '等待效果复盘' },
  };
};

const installAuthenticatedMocks = async (page, calls, {
  questionResponse = question,
  scopeResponse = null,
  historyResponse = [],
  initialIntent = null,
} = {}) => {
  const mockState = {
    created: Boolean(initialIntent),
    intent: structuredClone(initialIntent || pendingIntent),
    approvalRequests: [],
    executionRequests: [],
    questionReadbacks: [],
  };
  const currentQuestion = () => {
    const exact = structuredClone(questionResponse);
    if (mockState.created) {
      exact.action_intent_readback = {
        data_status: 'ok',
        list: [{ action_index: 0, execution_intent: structuredClone(mockState.intent) }],
        data_gaps: [],
      };
    } else {
      delete exact.action_intent_readback;
    }
    return exact;
  };
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
    if (pathname === '/api/agent/operating-question-scopes' && request.method() === 'GET') {
      data = scopeResponse || {
        contract_version: 'operating_question_scope_options.v1',
        data_status: 'empty',
        hotel_id: 7,
        recommended: null,
        platforms: [],
        boundary: { silent_date_fallback: false, source_scope: 'ota_channel' },
        data_gaps: [{ code: 'strict_readback_fact_scope_missing' }],
      };
    }
    if (pathname === '/api/agent/operating-questions' && request.method() === 'GET') {
      data = { data_status: 'ok', list: historyResponse, count: historyResponse.length, data_gaps: [] };
    }
    if (pathname === '/api/agent/operating-questions' && request.method() === 'POST') {
      data = { question: currentQuestion(), created: true, persistence_status: 'readback_verified' };
    }
    if (pathname === '/api/agent/operating-questions/71' && request.method() === 'GET') {
      data = currentQuestion();
      mockState.questionReadbacks.push({
        intent_id: Number(mockState.intent?.id || 0),
        status: String(mockState.intent?.status || ''),
        task_count: Array.isArray(mockState.intent?.tasks) ? mockState.intent.tasks.length : -1,
      });
    }
    if (pathname === '/api/agent/operating-questions/71/action-drafts/0/execution-intent' && request.method() === 'POST') {
      await new Promise(resolve => setTimeout(resolve, 80));
      const reusedExistingIntent = mockState.created;
      if (!mockState.created) {
        mockState.created = true;
        mockState.intent = structuredClone(pendingIntent);
      }
      data = {
        execution_intent: structuredClone(mockState.intent),
        reused_existing_intent: reusedExistingIntent,
      };
    }
    if (pathname === '/api/operation/execution-intents/901/approve' && request.method() === 'POST') {
      const approvalPayload = request.postDataJSON?.() || {};
      mockState.approvalRequests.push(structuredClone(approvalPayload));
      if (approvalPayload.approved === true && mockState.intent.status === 'pending_approval') {
        mockState.intent = buildApprovedIntent(approvalPayload);
      }
      data = structuredClone(mockState.intent);
    }
    if (pathname === '/api/operation/execution-tasks/902/execute' && request.method() === 'POST') {
      const executionPayload = request.postDataJSON?.() || {};
      mockState.executionRequests.push(structuredClone(executionPayload));
      const task = mockState.intent.tasks[0];
      if (task && executionPayload.status === 'executing') {
        task.status = 'executing';
        task.action_management = { lifecycle: { status: 'in_progress' } };
        mockState.intent.action_management.lifecycle = { status: 'in_progress' };
      } else if (task && ['executed', 'failed'].includes(String(executionPayload.status || ''))) {
        task.status = String(executionPayload.status);
        task.evidence = [{
          evidence_type: String(executionPayload.evidence_type || ''),
          evidence: structuredClone(executionPayload.evidence || {}),
        }];
        task.action_management = { lifecycle: { status: executionPayload.status === 'executed' ? 'executed' : 'failed' } };
        mockState.intent.action_management.lifecycle = structuredClone(task.action_management.lifecycle);
      }
      data = structuredClone(task || {});
    }
    if (pathname === '/api/operation/execution-tasks/902' && request.method() === 'GET') {
      data = structuredClone(mockState.intent.tasks[0] || {});
    }
    if (pathname === '/api/operation/execution-flow' && request.method() === 'GET') {
      const list = mockState.created ? [buildExecutionFlowItem(mockState.intent)] : [];
      data = {
        data_status: 'ok',
        summary: { total: list.length, stage_counts: {} },
        stages: [],
        list,
        data_gaps: [],
      };
    }
    if (pathname === '/api/operation/execution-intents/901' && request.method() === 'GET') {
      data = structuredClone(mockState.intent);
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });
  return mockState;
};

test('operating question action stays pending until double-confirmed approval, restores exactly, and records only manual evidence', async ({ page }) => {
  test.setTimeout(60000);
  const calls = [];
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  const mockState = await installAuthenticatedMocks(page, calls, { historyResponse: [question] });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await expect(page.getByTestId('operating-question-entry')).toBeVisible({ timeout: 15000 });

  await page.getByPlaceholder('例如：这家店今天最需要复核什么？').fill(question.question_text);
  await page.getByRole('button', { name: '提交并回读' }).click();
  await expect(page.getByTestId('operating-question-decision-frame')).toContainText('渠道');
  await expect(page.getByTestId('operating-question-decision-frame')).toContainText('毛收入和订单量不等于净贡献');
  const card = page.getByTestId('operating-question-action-card');
  await expect(card).toBeVisible();
  await expect(card).toContainText('AI 行动草案 · 独立评审');
  await expect(card).toContainText('证据门已通过');
  await expect(card).toContainText('复核指标：list_exposure');
  await expect(card).toContainText('只创建本地人工执行任务，不采集或写 OTA');

  await page.getByTestId('operating-question-action-submit').click();
  await expect(page.getByTestId('operating-question-action-open')).toBeVisible();
  await expect(page.getByTestId('operating-question-action-open')).toContainText('pending_approval');
  expect(mockState.intent.status).toBe('pending_approval');
  expect(mockState.intent.tasks).toEqual([]);

  expect(calls.filter(call => call.method === 'POST').map(call => call.pathname)).toEqual([
    '/api/agent/operating-questions',
    '/api/agent/operating-questions/71/action-drafts/0/execution-intent',
  ]);
  expect(calls.some(call => /approve|collect|fetch|apply|price|inventory|message/.test(call.pathname) && call.method === 'POST')).toBe(false);

  const flowReadsBeforeOpen = calls.filter(call => call.pathname === '/api/operation/execution-flow').length;
  await page.getByTestId('operating-question-action-open').click();
  await expect.poll(() => pageErrors, { timeout: 5000 }).toEqual([]);
  await expect.poll(
    () => calls.filter(call => call.pathname === '/api/operation/execution-flow').length,
    { timeout: 5000 },
  ).toBeGreaterThan(flowReadsBeforeOpen);
  expect(consoleErrors, `console errors: ${consoleErrors.join(' | ')}`).toEqual([]);
  const actionRow = page.locator('[data-operation-execution-intent-id="901"]');
  await expect(actionRow).toBeVisible({ timeout: 15000 });
  const approveButton = actionRow.getByTestId('operation-approve');
  await expect(approveButton).toHaveText('审批');

  await approveButton.click();
  await expect(approveButton).toHaveText('确认审批');
  expect(mockState.approvalRequests).toHaveLength(0);

  await approveButton.click();
  const approvalDialog = page.getByTestId('workflow-form-dialog');
  await expect(approvalDialog).toBeVisible();
  await expect(approvalDialog).toContainText('确认观察口径并批准');
  expect(mockState.approvalRequests).toHaveLength(0);
  await approvalDialog.getByRole('button', { name: '冻结观察口径并批准' }).click();

  await expect(actionRow.getByTestId('operation-start-task')).toBeVisible({ timeout: 15000 });
  expect(mockState.approvalRequests).toHaveLength(1);
  expect(mockState.approvalRequests[0]).toMatchObject({
    approved: true,
    confirmed: true,
    confirmation_version: 'operation_action_approval_confirmation.v1',
    confirmed_intent_id: 901,
    confirmed_action_digest: actionCardDigest,
    expected_metric: 'list_exposure',
    expected_direction: 'observe',
    target_type: 'observation',
    review_business_date: reviewDate,
    assignee_id: user.id,
  });
  expect(mockState.intent.status).toBe('approved');
  expect(mockState.intent.tasks).toHaveLength(1);
  expect(mockState.intent.tasks[0].id).toBe(902);
  await expect(actionRow.getByTestId('operation-approve')).toHaveCount(0);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await page.getByTestId('operating-question-history').locator('summary').click();
  await page.getByTestId('operating-question-history-71').click();
  const restoredAction = page.getByTestId('operating-question-action-open');
  await expect(restoredAction).toContainText('approved');
  await expect(restoredAction).toContainText('#901');
  expect(mockState.questionReadbacks.at(-1)).toEqual({
    intent_id: 901,
    status: 'approved',
    task_count: 1,
  });
  expect(mockState.approvalRequests).toHaveLength(1);

  await restoredAction.click();
  const restoredRow = page.locator('[data-operation-execution-intent-id="901"]');
  await expect(restoredRow).toBeVisible({ timeout: 15000 });
  await expect(restoredRow.getByTestId('operation-approve')).toHaveCount(0);
  expect(mockState.intent.tasks).toHaveLength(1);

  await restoredRow.getByTestId('operation-start-task').click();
  const evidenceButton = restoredRow.getByRole('button', { name: '录证据', exact: true });
  await expect(evidenceButton).toBeVisible({ timeout: 15000 });
  await evidenceButton.click();

  const evidenceModal = page.getByTestId('operation-evidence-modal');
  await expect(evidenceModal).toBeVisible();
  const completedAction = '值班经理已人工核对携程列表曝光、详情访问与页面展示配置';
  const executedBy = '夜班值班经理（人工）';
  const executedAtInput = `${businessDate}T10:30`;
  await evidenceModal.getByTestId('operation-execution-status-field').locator('select').selectOption('executed');
  await evidenceModal.getByLabel('操作说明 *').fill(completedAction);
  await evidenceModal.getByLabel('执行人 *').fill(executedBy);
  await evidenceModal.locator('input[type="datetime-local"]').fill(executedAtInput);
  await evidenceModal.getByRole('button', { name: '保存执行证据' }).click();
  await expect(evidenceModal).toBeHidden({ timeout: 15000 });

  const startRequest = mockState.executionRequests.find(payload => payload.status === 'executing');
  const evidenceRequest = mockState.executionRequests.find(payload => payload.evidence_type === 'manual_operation_execution');
  expect(startRequest).toMatchObject({
    status: 'executing',
    system_hotel_id: '7',
    tenant_id: '7',
  });
  expect(evidenceRequest).toMatchObject({
    status: 'executed',
    evidence_type: 'manual_operation_execution',
    evidence: {
      platform_response: {
        mode: 'manual_operation_execution',
        execution_status: 'executed',
        completed_action: completedAction,
        executed_by: executedBy,
        executed_at: `${businessDate} 10:30:00`,
        effect_status: 'pending_observation',
        evidence_boundary: 'local_manual_evidence_no_ota_write',
      },
      remark: completedAction,
    },
  });
  expect(JSON.stringify(evidenceRequest)).not.toMatch(/automatic_/i);
  expect(mockState.intent.tasks).toHaveLength(1);
  expect(mockState.intent.tasks[0]).toMatchObject({
    id: 902,
    status: 'executed',
  });
  expect(mockState.approvalRequests).toHaveLength(1);
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

test('latest strict scope and saved question restore without creating a new intent', async ({ page }) => {
  test.setTimeout(45000);
  const calls = [];
  const approvedIntent = buildApprovedIntent({
    review_business_date: reviewDate,
    assignee_id: user.id,
  });
  const historyQuestion = structuredClone(question);
  historyQuestion.action_intent_readback = {
    data_status: 'ok',
    list: [{ action_index: 0, execution_intent: approvedIntent }],
    data_gaps: [],
  };
  await installAuthenticatedMocks(page, calls, {
    questionResponse: historyQuestion,
    historyResponse: [question],
    initialIntent: approvedIntent,
    scopeResponse: {
      contract_version: 'operating_question_scope_options.v1',
      data_status: 'ready',
      hotel_id: 7,
      recommended: {
        hotel_id: 7,
        platform: 'meituan',
        date_start: '2026-08-09',
        date_end: '2026-08-09',
        verified_fact_count: 1,
        selection_reason: 'latest_strict_readback',
        is_today: false,
      },
      platforms: [{
        platform: 'meituan',
        latest_verified_date: '2026-08-09',
        verified_fact_count: 1,
        available_dates: ['2026-08-09'],
        available_date_count: 1,
      }],
      boundary: { silent_date_fallback: false, source_scope: 'ota_channel' },
      data_gaps: [],
    },
  });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await expect(page.getByTestId('operating-question-scope-status')).toContainText('美团 · 2026-08-09（不是今天）');
  await expect(page.getByTestId('operating-question-platform')).toHaveValue('meituan');
  await expect(page.getByTestId('operating-question-date-start')).toHaveValue('2026-08-09');

  await page.getByTestId('operating-question-history').locator('summary').click();
  await page.getByTestId('operating-question-history-71').click();
  await expect(page.getByTestId('operating-question-readback')).toBeVisible();
  await expect(page.getByTestId('operating-question-action-open')).toContainText('approved');
  await expect(page.getByTestId('operating-question-platform')).toHaveValue('ctrip');
  expect(calls.filter(call => call.method === 'POST')).toEqual([]);
});

test('grounded operating answer submits one evidence-locked action for human approval only', async ({ page }) => {
  test.setTimeout(45000);
  const calls = [];
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await installAuthenticatedMocks(page, calls);

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('nav-lean-more')).toBeVisible({ timeout: 15000 });
  await page.waitForTimeout(300);
  const scopeReadCount = () => calls.filter(
    call => call.method === 'GET' && call.pathname === '/api/agent/operating-question-scopes',
  ).length;
  expect(scopeReadCount(), 'Compass startup must not prefetch the inactive Agent scope').toBe(0);
  await page.getByTestId('nav-lean-more').click();
  await page.getByTestId('nav-agent-center').click();
  await expect(page.getByTestId('operating-question-entry')).toBeVisible({ timeout: 15000 });
  await expect.poll(scopeReadCount, { timeout: 5000 }).toBe(1);
  await page.waitForTimeout(300);
  expect(scopeReadCount(), 'Agent scope must remain a single read after the panel settles').toBe(1);
  await setFixedQuestionBusinessDate(page);

  await page.getByPlaceholder('例如：这家店今天最需要复核什么？').fill(question.question_text);
  await page.getByRole('button', { name: '提交并回读' }).click();
  await expect(page.getByTestId('operating-question-decision-frame')).toContainText('渠道');
  await expect(page.getByTestId('operating-question-decision-frame')).toContainText('毛收入和订单量不等于净贡献');
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
  expect(calls.some(call => /approve|collect|fetch|apply|price|inventory|message/.test(call.pathname) && call.method === 'POST')).toBe(false);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
