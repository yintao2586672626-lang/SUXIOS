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
  id: 801,
  username: 'system_guide_probe',
  realname: '智能使用助手验收账号',
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
  permitted_hotels: [{ id: 7, name: '智能使用助手验收酒店', tenant_id: 7, status: 1 }],
  context: {
    token_status: 'valid',
    tenantId: 7,
    hotelId: 7,
    permitted_hotel_ids: [7],
    permissionStatus: 'allowed',
  },
};

const intelligentResult = (overrides = {}) => ({
  status: 'ready',
  mode: 'intelligent',
  assistant_mode: 'guide',
  assistant_message: '你现在不是要看经营结论，而是要先判断携程数据为什么没有进入系统。我建议先确认门店身份，再检查采集和回读。',
  intent_summary: '排查携程数据缺失',
  goal: '恢复携程数据后生成一份给店长查看的 AI 经营日报',
  topic_key: 'data-health',
  topic: { key: 'data-health', title: '检查数据为什么不能用', category: '数据与采集' },
  journey: [
    {
      index: 1,
      key: 'data-health',
      title: '检查数据为什么不能用',
      category: '数据与采集',
      summary: '核对酒店、平台、业务日期、来源、质量状态、保存和回读结果。',
      success_marker: '已明确数据停在身份、采集、保存还是精确回读阶段。',
      action: { key: 'data-health', label: '打开数据健康', target_page: 'online-data', action_key: 'data-health' },
    },
    {
      index: 2,
      key: 'ai-daily-report',
      title: '生成和查看 AI 经营日报',
      category: '经营报告',
      summary: '基于已验证数据生成日报草稿，预览事实、建议和缺口。',
      success_marker: '日报草稿已生成并预览；是否外发仍由人工确认。',
      action: { key: 'ai-daily-report', label: '打开 AI 经营日报', target_page: 'ai-daily-report', action_key: 'page' },
    },
  ],
  steps: ['先确认当前系统酒店与携程门店绑定一致', '再核对今天的采集、保存和回读状态'],
  clarifying_question: '',
  follow_up_questions: ['如果显示登录过期，下一步怎么处理？'],
  confidence: 'high',
  boundary: '数据行存在不等于事实可用，不能用历史值、其他酒店或默认值补齐。',
  action: {
    key: 'data-health',
    label: '打开数据健康',
    target_page: 'online-data',
    action_key: 'data-health',
  },
  runtime: {
    status: 'ready',
    provider: 'deepseek',
    model_key: 'deepseek_v4_pro',
    model: 'deepseek-v4-pro',
    finish_reason: 'stop',
    fallback_used: false,
    cache_hit: false,
    degraded: false,
    external_llm_called: true,
    thinking_mode: 'enabled',
    reasoning_effort: 'high',
  },
  ...overrides,
});

const mockAuthenticatedApi = async (page, apiCalls, guidanceRequests) => {
  let operatingQuestionReadback = null;
  let nextPreciseQueryId = 9900;
  const preciseQueryReadbacks = new Map();
  let serverJourney = null;
  let preferences = [
    {
      id: 41,
      tenant_id: user.tenant_id,
      user_id: user.id,
      hotel_id: null,
      scope: 'global',
      preference_key: 'response_detail',
      value: 'concise',
      learning_status: 'inferred',
      lifecycle_status: 'active',
      consumable: false,
      candidate: true,
      source_type: 'behavioral_signal',
      source_context: {
        reason_code: 'too_long',
        signal_count: 3,
        source_ref: 'ai_suggestion_feedback#703',
      },
    },
    {
      id: 42,
      tenant_id: user.tenant_id,
      user_id: user.id,
      hotel_id: null,
      scope: 'global',
      preference_key: 'answer_order',
      value: 'conclusion_first',
      learning_status: 'insufficient',
      lifecycle_status: 'active',
      consumable: false,
      candidate: true,
      source_type: 'behavioral_signal',
      source_context: {
        reason_code: 'repeated_order_choice',
        signal_count: 2,
        source_ref: 'system_observation#42',
      },
    },
  ];
  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'system-guide-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({
      saved_at: Date.now(),
      user: profile,
    }));
  }, user);

  await page.route('**/api/**', async route => {
    const request = route.request();
    const pathname = new URL(request.url()).pathname;
    const payload = request.method() === 'POST' ? request.postDataJSON?.() || null : null;
    apiCalls.push({ method: request.method(), pathname, payload });
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = { list: user.permitted_hotels, total: user.permitted_hotels.length };
    }
    if (pathname === '/api/agent/system-guidance/context' && request.method() === 'GET') {
      data = {
        contract_version: 'system_user_learning_context.v1',
        status: 'ready',
        scope: {
          tenant_id: user.tenant_id,
          user_id: user.id,
          hotel_id: user.hotel_id,
        },
        preferences: {
          status: 'ready',
          count: preferences.length,
          items: preferences,
          consumable_count: preferences.filter(item => item.consumable).length,
          consumable_items: preferences.filter(item => item.consumable),
          candidate_count: preferences.filter(item => item.candidate).length,
          candidate_items: preferences.filter(item => item.candidate),
          ready_candidate_count: preferences.filter(item => item.learning_status === 'inferred').length,
          ready_candidate_items: preferences.filter(item => item.learning_status === 'inferred'),
        },
        journey: serverJourney
          ? { data_status: 'ready', journey: structuredClone(serverJourney) }
          : { data_status: 'empty', journey: null },
        resume_card: serverJourney
          ? {
            contract_version: 'user_guidance_resume_card.v1',
            data_status: 'ready',
            scope: { type: 'hotel', hotel_id: user.hotel_id },
            card: {
              journey_id: Number(serverJourney.id),
              journey_key: String(serverJourney.journey_key),
              version_no: Number(serverJourney.version_no || 1),
              content_digest: String(serverJourney.content_digest),
              goal_summary: String(serverJourney.goal),
              next_step: {
                topic_key: String(serverJourney.active_key),
                status: String(serverJourney.current_step_status || 'in_progress'),
                blocker_code: String(serverJourney.blocker_code || ''),
                blocker_summary: String(serverJourney.blocker_summary || ''),
              },
              journey_keys: structuredClone(serverJourney.journey_keys),
              saved_at: String(serverJourney.created_at),
              readback_verified: true,
            },
          }
          : { contract_version: 'user_guidance_resume_card.v1', data_status: 'empty', card: null },
        calibration: {
          status: 'descriptive_only',
          minimum_samples: 3,
          counts: {
            feedback_sample_count: 24,
            accepted: 18,
            modified: 3,
            rejected: 2,
            deferred: 0,
            needs_more_evidence: 1,
          },
          feedback_ranking: {
            status: 'ready',
            minimum_samples_per_topic: 20,
            items: [
              { topic_key: 'data-health', sample_count: 20, eligible: true, adjustment: 1 },
              { topic_key: 'daily-workbench', sample_count: 20, eligible: true, adjustment: -1 },
              { topic_key: 'task-navigation', sample_count: 20, eligible: true, adjustment: 1 },
            ],
          },
        },
        learning_policy: {
          candidate_minimum_repeated_signals: 3,
          candidate_requires_explicit_confirmation: true,
          external_write_authorized: false,
        },
      };
    }
    if (pathname === '/api/agent/system-guidance/preferences' && request.method() === 'POST') {
      const existing = preferences.filter(item => item.preference_key !== payload.preference_key);
      const preference = {
        id: preferences.length + 1,
        tenant_id: user.tenant_id,
        user_id: user.id,
        hotel_id: payload.scope === 'hotel' ? Number(payload.hotel_id || user.hotel_id) : null,
        scope: String(payload.scope || 'global'),
        preference_key: String(payload.preference_key || ''),
        value: payload.value,
        learning_status: 'explicit_confirmed',
        lifecycle_status: 'active',
        consumable: true,
        candidate: false,
        source_type: 'explicit_user',
        source_context: { reason_code: 'explicit_user_confirmation' },
      };
      preferences = [...existing, preference];
      data = { status: 'exact_readback_verified', projection: preference };
    }
    if (pathname === '/api/agent/system-guidance/preferences/revoke' && request.method() === 'POST') {
      preferences = preferences.filter(item => item.preference_key !== payload.preference_key);
      data = { status: 'exact_readback_verified' };
    }
    if (pathname === '/api/agent/system-guidance/preferences/reset' && request.method() === 'POST') {
      preferences = [];
      data = { status: 'exact_readback_verified' };
    }
    if (pathname === '/api/agent/system-guidance/journey' && request.method() === 'POST') {
      const journey = payload.journey || payload;
      serverJourney = {
        id: 501,
        journey_key: '1'.repeat(64),
        version_no: 1,
        goal: String(journey.goal || ''),
        original_query_digest: '2'.repeat(64),
        active_key: String(journey.active_key || ''),
        journey_keys: Array.isArray(journey.journey_keys) ? journey.journey_keys : [],
        current_step_status: String(journey.current_step_status || 'in_progress'),
        blocker_code: String(journey.blocker_code || ''),
        blocker_summary: String(journey.blocker_summary || ''),
        lifecycle_status: 'active',
        content_digest: '3'.repeat(64),
        created_at: '2026-08-29 09:30:00',
        readback_verified: true,
      };
      data = { persistence_status: 'readback_verified', journey: structuredClone(serverJourney) };
    }
    if (pathname === '/api/agent/system-guidance/journey/archive' && request.method() === 'POST') {
      serverJourney = null;
      data = { persistence_status: 'readback_verified' };
    }
    if (pathname === '/api/agent/system-guidance/journey/transition' && request.method() === 'POST') {
      const transitioned = {
        ...(serverJourney || {}),
        id: 502,
        version_no: 2,
        lifecycle_status: payload.action === 'complete' ? 'completed' : 'archived',
        current_step_status: payload.action === 'complete' ? 'completed' : String(serverJourney?.current_step_status || 'in_progress'),
        content_digest: '4'.repeat(64),
        readback_verified: true,
      };
      serverJourney = null;
      data = {
        status: 'exact_readback_verified',
        action: payload.action,
        journey: transitioned,
      };
    }
    if (pathname === '/api/agent/system-guidance/feedback' && request.method() === 'POST') {
      data = {
        feedback: { id: 701, readback_verified: true, feedback_status: payload.feedback_status },
        calibration: { status: 'insufficient_samples', minimum_samples: 3, counts: { feedback_sample_count: 1 } },
      };
    }
    if (pathname === '/api/agent/operating-questions' && request.method() === 'POST') {
      const digest = 'a'.repeat(64);
      operatingQuestionReadback = {
        id: 901,
        tenant_id: user.tenant_id,
        hotel_id: Number(payload.hotel_id),
        platform: String(payload.platform),
        date_start: String(payload.date_start),
        date_end: String(payload.date_end),
        question_text: String(payload.question),
        content_digest: digest,
        answer_status: 'blocked_by_missing_facts',
        answer_summary: '当前范围缺少同酒店、同平台、同日期的严格保存回读事实，暂不能给出确定经营结论。',
        data_gaps: [{ code: 'verified_fact_missing', message: '缺少当前范围的可信 OTA 事实。' }],
        answer: {
          key_points: [],
          missing_information: ['先到数据健康核对采集、保存和精确回读。'],
          evidence_counts: { facts: 0, knowledge_chunks: 0, operating_memories: 0, execution_reviews: 0 },
          action_drafts: [],
        },
      };
      data = {
        question: operatingQuestionReadback,
        persistence_status: 'readback_verified',
      };
    }
    if (pathname === '/api/agent/operating-questions/901' && request.method() === 'GET') {
      data = operatingQuestionReadback;
    }
    if (pathname === '/api/agent/precise-queries' && request.method() === 'POST') {
      guidanceRequests.push(payload);
      await new Promise(resolve => setTimeout(resolve, 80));
      let answer;
      if (String(payload.query || '').includes('报告')) {
        answer = intelligentResult({
          assistant_mode: 'report',
          assistant_message: '你要的是经营报告，不需要在浮窗里拼结论。先进入收益分析中心核对酒店、渠道和日期，缺数时页面会直接告诉你卡在哪里。',
          intent_summary: '查看经营报告',
          goal: '查看当前酒店的经营报告和证据缺口',
          topic_key: 'revenue-report',
          topic: { key: 'revenue-report', title: '查看报告和经营结论', category: '收益分析' },
          journey: [{
            index: 1,
            key: 'revenue-report',
            title: '查看报告和经营结论',
            category: '收益分析',
            summary: '查看事实、异常信号和证据缺口。',
            success_marker: '报告已明确区分事实、缺口和建议。',
            action: { key: 'revenue-report', label: '打开收益分析中心', target_page: 'revenue-research-center', action_key: 'page' },
          }],
          steps: ['先确认酒店、渠道和业务日期', '再查看事实、异常信号和证据缺口', '人工确认建议后再进入任务执行'],
          follow_up_questions: ['那数据还没准备好怎么办？'],
          boundary: 'AI建议不等于已执行，也不能单独证明原因、收益或ROI。',
          action: {
            key: 'revenue-report',
            label: '打开收益分析中心',
            target_page: 'revenue-research-center',
            action_key: 'page',
          },
        });
      } else {
        answer = intelligentResult({
          assistant_message: payload.history?.length
            ? '接着刚才的报告问题：既然数据还没准备好，先不要生成结论，回到数据健康查清缺的是采集、保存还是回读。'
            : intelligentResult().assistant_message,
          goal: payload.history?.length ? '先恢复可用数据，再查看经营报告' : intelligentResult().goal,
          journey: payload.history?.length
            ? [intelligentResult().journey[0], {
              index: 2,
              key: 'revenue-report',
              title: '查看报告和经营结论',
              category: '收益分析',
              summary: '查看事实、异常信号和证据缺口。',
              success_marker: '报告已明确区分事实、缺口和建议。',
              action: { key: 'revenue-report', label: '打开收益分析中心', target_page: 'revenue-research-center', action_key: 'page' },
            }]
            : intelligentResult().journey,
        });
      }
      const concisePreference = preferences.find(item => (
        item.preference_key === 'response_detail'
          && item.value === 'concise'
          && item.consumable === true
      ));
      answer.personalization = concisePreference
        ? {
          status: 'applied',
          response_detail: 'concise',
          preference_refs: [`user_learning_preference#${concisePreference.id}`],
          recognized_preference_refs: [`user_learning_preference#${concisePreference.id}`],
          applied_preferences: [{
            preference_key: 'response_detail',
            preference_value: 'concise',
            scope: 'global',
            source_ref: `user_learning_preference#${concisePreference.id}`,
          }],
          effect_scope: 'presentation_only',
          explanation: {
            status: 'preference_applied',
            summary: '按你已确认的“回答简洁”偏好压缩了表达。',
            source_refs: [`user_learning_preference#${concisePreference.id}`],
            effect_scope: 'presentation_only',
            facts_changed: false,
            permissions_changed: false,
            approval_changed: false,
            external_write_authorized: false,
          },
        }
        : { status: 'not_configured' };
      const id = ++nextPreciseQueryId;
      data = {
        id,
        tenant_id: user.tenant_id,
        hotel_id: Number(payload.current_scope?.hotel_id || user.hotel_id),
        question: String(payload.query || ''),
        route_type: 'system_navigation',
        status: 'navigation_ready',
        answer_summary: String(answer.assistant_message || ''),
        answer,
        parsed_scope: {
          hotel_id: Number(payload.current_scope?.hotel_id || user.hotel_id),
          hotel_name: String(payload.current_scope?.hotel_name || ''),
          platform: String(payload.current_scope?.platform || ''),
          date_start: String(payload.current_scope?.date_start || ''),
          date_end: String(payload.current_scope?.date_end || ''),
        },
        lexicon: { runtime_extracted_term_count: 110, business_fact_eligible: false },
        knowledge_refs: [],
        fact_refs: [],
        content_digest: String(id).padStart(64, '0'),
        persistence_status: 'readback_verified',
      };
      preciseQueryReadbacks.set(id, structuredClone(data));
    }
    const preciseReadMatch = pathname.match(/^\/api\/agent\/precise-queries\/(\d+)$/);
    if (preciseReadMatch && request.method() === 'GET') {
      data = structuredClone(preciseQueryReadbacks.get(Number(preciseReadMatch[1])) || null);
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });
};

const openDemandLoadedSystemGuide = async (page) => {
  const loadEntry = page.getByTestId('operating-question-consultant-load');
  await expect(loadEntry).toBeVisible({ timeout: 15000 });
  await expect(page.getByTestId('system-guide-floating-entry')).toHaveCount(0);
  await loadEntry.click();

  const entry = page.getByTestId('system-guide-floating-entry');
  const launcher = page.getByTestId('system-guide-floating-launcher');
  const panel = page.getByTestId('system-guide-floating-panel');
  await expect(entry).toHaveCount(1, { timeout: 15000 });
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await expect(panel).toBeVisible({ timeout: 15000 });
  return { entry, launcher, panel };
};

test('precise query entry understands natural language and opens the real data-health page', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  const fullComponentRequests = [];
  const fullComponentResponses = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  page.on('request', (request) => {
    if (new URL(request.url()).pathname.endsWith('/components/system/operating-intelligence-components.js')) {
      fullComponentRequests.push(request.url());
    }
  });
  page.on('response', (response) => {
    if (new URL(response.url()).pathname.endsWith('/components/system/operating-intelligence-components.js')) {
      fullComponentResponses.push(response);
    }
  });
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.setViewportSize({ width: 393, height: 734 });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  expect(fullComponentRequests).toHaveLength(0);
  const { panel } = await openDemandLoadedSystemGuide(page);
  expect(fullComponentRequests).toHaveLength(1);
  expect(fullComponentResponses).toHaveLength(1);
  expect(fullComponentResponses[0].status()).toBe(200);
  expect(fullComponentResponses[0].headers()['content-type']).toMatch(/javascript/i);
  await expect(panel).toContainText('宿析精准查数');
  await expect(panel).toContainText('查经营事实 · 解释缺失 · 找功能 · 查术语');
  await expect(page.getByTestId('system-guide-learning-memory')).toBeVisible();
  await expect(page.getByTestId('system-guide-learning-memory')).toContainText('学习中心');
  await expect(page.getByTestId('system-guide-learning-memory')).toContainText('候选 1');
  await page.getByTestId('system-guide-learning-toggle').click();
  await expect(page.getByTestId('system-guide-learning-confirmed')).toBeVisible();
  await expect(page.getByTestId('system-guide-learning-candidates')).toContainText('待你确认，尚未应用');
  await expect(page.getByTestId('system-guide-learning-candidates')).toContainText('观察中 2/3');
  await expect(page.getByTestId('system-guide-preference-reset')).toBeEnabled();
  await expect(page.getByTestId('system-guide-learning-calibration')).toContainText('反馈 24 条');
  await expect(page.getByTestId('system-guide-learning-journey')).toContainText('当前没有需要跨会话续办的事项');
  await expect(page.getByTestId('system-guide-learning-management')).toBeVisible();
  await page.getByTestId('system-guide-candidate-confirm-response_detail').click();
  await expect.poll(() => apiCalls.some(call => (
    call.pathname === '/api/agent/system-guidance/preferences'
      && call.payload?.preference_key === 'response_detail'
      && call.payload?.value === 'concise'
  ))).toBe(true);
  await expect(page.getByTestId('system-guide-learning-memory')).toContainText('回答保持简洁');
  await expect(page.getByRole('button', { name: '数据为什么没进来 · 更常用' })).toBeVisible();
  await expect(page.getByRole('button', { name: '今天先做什么' })).toBeVisible();
  await expect(page.getByRole('button', { name: '查找项目功能入口 · 更常用' })).toHaveCount(0);
  await expect(page.getByTestId('system-guide-context')).toBeVisible();
  await expect(page.getByTestId('system-guide-context')).toContainText('当前页面');
  await expect(panel.getByRole('button', { name: '这个页面怎么用？' })).toBeVisible();
  await expect(page.getByTestId('system-guide-mode-switcher')).toBeVisible();
  await expect(page.getByTestId('operating-question-hotel')).toHaveCount(0);
  await expect(page.getByTestId('operating-question-platform')).toHaveCount(0);
  await expect(page.getByTestId('operating-question-date-start')).toHaveCount(0);

  await page.getByTestId('system-guide-input').fill('我刚接手这家店，携程数据一直没进来，处理好后还要生成一份给店长看的日报。');
  await page.getByTestId('system-guide-submit').click();
  await expect(page.getByTestId('system-guide-loading')).toBeVisible();

  const result = page.getByTestId('system-guide-result');
  await expect(result).toBeVisible();
  await expect(result).toContainText('统一路由');
  await expect(result).toContainText('你现在不是要看经营结论');
  await expect(page.getByTestId('system-guide-personalization-receipt')).toContainText('为什么这样回答');
  await expect(page.getByTestId('system-guide-personalization-receipt')).toContainText('回答简洁');
  await expect(page.getByTestId('system-guide-personalization-receipt')).toContainText('没有改变酒店事实、权限、审批或外部写入');
  await expect(page.getByTestId('system-guide-journey-goal')).toContainText('恢复携程数据后生成一份给店长查看的 AI 经营日报');
  await expect(page.getByTestId('system-guide-journey-step-data-health')).toContainText('检查数据为什么不能用');
  await expect(page.getByTestId('system-guide-journey-step-ai-daily-report')).toContainText('生成和查看 AI 经营日报');
  await expect(page.getByTestId('system-guide-journey-step-data-health')).toContainText('确认标准');
  await expect(result).toContainText('1. 先确认当前系统酒店与携程门店绑定一致');
  await expect(result).toContainText('操作边界');
  await expect(result).toContainText('助手不会虚构页面或在这里写入业务数据');
  await expect(panel).not.toContainText(/DeepSeek|deepseek-v4-pro|模型|深度思考/);

  expect(guidanceRequests).toHaveLength(1);
  expect(guidanceRequests[0].current_page).toBe('compass');
  expect(guidanceRequests[0].history).toEqual([]);
  expect(guidanceRequests[0].visible_topic_keys).toContain('data-health');
  expect(guidanceRequests[0].visible_topic_keys).toContain('ctrip-data');
  expect(guidanceRequests[0].visible_topic_keys).toContain('meituan-data');
  expect(guidanceRequests[0].visible_topic_keys).toContain('operation-optimizer');
  expect(guidanceRequests[0].active_journey).toBeNull();
  expect(guidanceRequests[0].current_scope.hotel_id).toBe(7);
  expect(guidanceRequests[0].current_scope.platform).toBe('ctrip');
  expect(guidanceRequests[0]).not.toHaveProperty('preference_context');

  const readableSizes = await panel.evaluate((element) => ({
    title: getComputedStyle(element.querySelector('.sx-ai-consultant-title')).fontSize,
    answer: getComputedStyle(element.querySelector('.sx-ai-consultant-answer')).fontSize,
    suggestion: getComputedStyle(element.querySelector('.sx-ai-consultant-suggestions button')).fontSize,
    input: getComputedStyle(element.querySelector('.sx-ai-consultant-composer textarea')).fontSize,
    overflow: element.scrollWidth - element.clientWidth,
  }));
  expect(parseFloat(readableSizes.title)).toBeGreaterThanOrEqual(18);
  expect(parseFloat(readableSizes.answer)).toBeGreaterThanOrEqual(14);
  expect(parseFloat(readableSizes.suggestion)).toBeGreaterThanOrEqual(12);
  expect(parseFloat(readableSizes.input)).toBeGreaterThanOrEqual(14);
  expect(readableSizes.overflow).toBeLessThanOrEqual(1);

  await expect(page.getByTestId('system-guide-feedback')).toBeVisible();
  await page.getByTestId('system-guide-feedback-useful').click();
  await expect.poll(() => apiCalls.some(call => (
    call.pathname === '/api/agent/system-guidance/feedback'
      && call.payload?.feedback_status === 'accepted'
  ))).toBe(true);
  await expect(page.getByTestId('system-guide-feedback-wrong_focus')).toBeDisabled();
  expect(apiCalls.filter(call => call.pathname === '/api/agent/system-guidance/feedback')).toHaveLength(1);

  await page.getByTestId('system-guide-journey-open-data-health').click();
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'online-data');
  await expect(page.getByTestId('system-guide-page-coach')).toBeVisible();
  await expect.poll(() => apiCalls.some(call => call.pathname === '/api/agent/system-guidance/journey')).toBe(true);

  await page.evaluate(() => {
    localStorage.removeItem('suxios_system_usage_journey_v1:801:7');
  });

  await page.reload({ waitUntil: 'domcontentloaded' });
  const restoredGuide = await openDemandLoadedSystemGuide(page);
  const restoredJourney = restoredGuide.panel.getByTestId('system-guide-active-journey');
  await expect(restoredJourney).toBeVisible();
  await expect(restoredJourney).toContainText('继续上次任务');
  await expect(restoredJourney).toContainText('恢复携程数据后生成一份给店长查看的 AI 经营日报');
  await expect(restoredJourney).toContainText('生成和查看 AI 经营日报');
  await expect(restoredJourney.getByTestId('system-guide-resume-continue')).toBeVisible();
  await expect(restoredJourney.getByTestId('system-guide-resume-complete')).toBeVisible();
  await expect(restoredJourney.getByTestId('system-guide-resume-ignore')).toBeVisible();
  await expect(restoredJourney).toContainText('不代表酒店经营结果');
  await expect(restoredGuide.panel.getByRole('button', { name: '继续当前任务' })).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await restoredJourney.getByTestId('system-guide-resume-ignore').click();
  await expect.poll(() => apiCalls.some(call => (
    call.pathname === '/api/agent/system-guidance/journey/transition'
      && call.payload?.action === 'ignore'
  ))).toBe(true);
  await expect(restoredGuide.panel.getByTestId('system-guide-active-journey')).toHaveCount(0);
  expect(apiCalls.filter(call => call.pathname === '/api/agent/operating-questions')).toEqual([]);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('precise query entry exposes explicit guide, report and action modes', async ({ page }) => {
  test.setTimeout(45000);
  const apiCalls = [];
  const guidanceRequests = [];
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await openDemandLoadedSystemGuide(page);

  await expect(page.getByTestId('system-guide-mode-auto')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('system-guide-mode-report').click();
  await expect(page.getByTestId('system-guide-mode-report')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('system-guide-input').fill('给我今天的经营报告');
  await page.getByTestId('system-guide-submit').click();

  await expect(page.getByTestId('system-guide-mode')).toContainText('证据结论');
  expect(guidanceRequests[0].requested_mode).toBe('report');
  expect(apiCalls.filter(call => call.method === 'POST' && call.pathname === '/api/agent/precise-queries').map(call => call.pathname)).toEqual([
    '/api/agent/precise-queries',
  ]);
  expect(apiCalls.some(call => call.pathname === '/api/agent/operating-questions')).toBe(false);

  await page.getByTestId('system-guide-mode-action').click();
  await expect(page.getByTestId('system-guide-mode-action')).toHaveAttribute('aria-pressed', 'true');
});

test('precise query entry keeps conversation context and changes the next route', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await openDemandLoadedSystemGuide(page);

  await page.getByTestId('system-guide-input').fill('我想看今天的经营报告，该从哪里开始？');
  await page.getByTestId('system-guide-submit').click();
  await expect(page.getByTestId('system-guide-result')).toContainText('你要的是经营报告');
  await expect(page.getByTestId('system-guide-result')).toContainText('查看报告和经营结论');

  await page.getByRole('button', { name: '那数据还没准备好怎么办？' }).click();
  await expect(page.getByTestId('system-guide-input')).toHaveValue('那数据还没准备好怎么办？');
  await page.getByTestId('system-guide-submit').click();

  await expect(page.getByTestId('system-guide-result')).toContainText('接着刚才的报告问题');
  await expect(page.getByTestId('system-guide-result')).toContainText('回到数据健康');
  expect(guidanceRequests).toHaveLength(2);
  expect(guidanceRequests[1].history).toEqual([
    { role: 'user', content: '我想看今天的经营报告，该从哪里开始？' },
    {
      role: 'assistant',
      content: '你要的是经营报告，不需要在浮窗里拼结论。先进入收益分析中心核对酒店、渠道和日期，缺数时页面会直接告诉你卡在哪里。',
    },
  ]);
  expect(guidanceRequests[1].active_journey.goal).toBe('查看当前酒店的经营报告和证据缺口');
  expect(guidanceRequests[1].active_journey.active_key).toBe('revenue-report');
  expect(guidanceRequests[1].active_journey.journey_keys).toEqual(['revenue-report']);
  expect(['pending', 'in_progress', 'checking', 'blocked', 'completed']).toContain(
    guidanceRequests[1].active_journey.current_step_status,
  );

  const latestJourney = page.getByTestId('system-guide-result').getByTestId('system-guide-journey');
  await expect(latestJourney).toContainText('先恢复可用数据，再查看经营报告');
  await latestJourney.getByTestId('system-guide-journey-open-data-health').click();
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'online-data');
  expect(apiCalls.filter(call => call.method === 'POST' && call.pathname === '/api/agent/precise-queries').map(call => call.pathname)).toEqual([
    '/api/agent/precise-queries',
    '/api/agent/precise-queries',
  ]);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('precise query entry can be dragged, kept on screen, hidden and restored', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-suxi-authenticated-interactive-ready', '1', { timeout: 15000 });

  const { entry, launcher, panel } = await openDemandLoadedSystemGuide(page);
  await launcher.click();
  await expect(entry).not.toHaveAttribute('open', '');
  await expect(panel).not.toBeVisible();
  await expect(launcher).toHaveAttribute('aria-label', '打开宿析精准查数');
  await expect(launcher).toContainText('精准查数');
  await expect(launcher.locator('.fa-chevron-up')).toHaveCount(0);

  const initial = await launcher.boundingBox();
  const launcherBox = await launcher.boundingBox();
  expect(initial).toBeTruthy();
  expect(launcherBox).toBeTruthy();
  await page.mouse.move(launcherBox.x + launcherBox.width / 2, launcherBox.y + launcherBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(
    launcherBox.x + launcherBox.width / 2 - 240,
    launcherBox.y + launcherBox.height / 2 - 160,
    { steps: 8 },
  );
  await page.mouse.up();

  await expect(entry).not.toHaveAttribute('open', '');
  const draggedLauncher = await launcher.boundingBox();
  expect(draggedLauncher.x).toBeLessThan(initial.x - 150);
  expect(draggedLauncher.y).toBeLessThan(initial.y - 90);
  expect(draggedLauncher.x).toBeGreaterThanOrEqual(7);
  expect(draggedLauncher.y).toBeGreaterThanOrEqual(7);
  expect(draggedLauncher.x + draggedLauncher.width).toBeLessThanOrEqual(1273);
  expect(draggedLauncher.y + draggedLauncher.height).toBeLessThanOrEqual(793);
  const storedPosition = await page.evaluate(() => JSON.parse(
    localStorage.getItem('suxios_system_usage_widget_v1:801') || 'null',
  ));
  expect(storedPosition).toMatchObject({
    version: 1,
    open: false,
    right: expect.any(Number),
    bottom: expect.any(Number),
  });

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-suxi-authenticated-interactive-ready', '1', { timeout: 15000 });
  const storedPositionBeforeDemand = await page.evaluate(() => JSON.parse(
    localStorage.getItem('suxios_system_usage_widget_v1:801') || 'null',
  ));
  expect(storedPositionBeforeDemand).toEqual(storedPosition);
  const restoredGuide = await openDemandLoadedSystemGuide(page);
  await restoredGuide.launcher.click();
  await expect(restoredGuide.entry).not.toHaveAttribute('open', '');
  const restoredLauncher = await restoredGuide.launcher.boundingBox();
  expect(restoredLauncher).toBeTruthy();
  expect(restoredLauncher.x).toBeGreaterThanOrEqual(7);
  expect(restoredLauncher.y).toBeGreaterThanOrEqual(7);
  expect(restoredLauncher.x + restoredLauncher.width).toBeLessThanOrEqual(1273);
  expect(restoredLauncher.y + restoredLauncher.height).toBeLessThanOrEqual(793);

  await restoredGuide.launcher.click();
  await expect(restoredGuide.entry).toHaveAttribute('open', '');
  await expect(restoredGuide.panel).toBeVisible();
  await expect(restoredGuide.launcher).toHaveAttribute('aria-label', '收起宿析精准查数');
  await expect(restoredGuide.launcher).toContainText('收起');
  await expect(page.getByTestId('system-guide-drag-handle')).toContainText('拖动');

  const handle = page.getByTestId('system-guide-drag-handle');
  const handleBox = await handle.boundingBox();
  expect(handleBox).toBeTruthy();
  await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(-1200, -900, { steps: 10 });
  await page.mouse.up();

  const draggedPanel = await restoredGuide.entry.boundingBox();
  expect(draggedPanel.x).toBeGreaterThanOrEqual(7);
  expect(draggedPanel.y).toBeGreaterThanOrEqual(7);
  expect(draggedPanel.x + draggedPanel.width).toBeLessThanOrEqual(1273);
  expect(draggedPanel.y + draggedPanel.height).toBeLessThanOrEqual(793);

  await restoredGuide.launcher.click();
  await expect(restoredGuide.entry).not.toHaveAttribute('open', '');
  await expect(restoredGuide.panel).not.toBeVisible();
  await expect(restoredGuide.launcher).toBeVisible();
  await expect(restoredGuide.launcher).toHaveAttribute('aria-label', '打开宿析精准查数');
  await expect(restoredGuide.launcher).toContainText('精准查数');

  await page.reload({ waitUntil: 'domcontentloaded' });
  const finalGuide = await openDemandLoadedSystemGuide(page);
  await expect(finalGuide.panel).toBeVisible();
  await finalGuide.launcher.click();
  await expect(finalGuide.entry).not.toHaveAttribute('open', '');
  await finalGuide.launcher.click();
  await expect(finalGuide.panel).toBeVisible();
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
