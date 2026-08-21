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
    apiCalls.push({ method: request.method(), pathname });
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') {
      data = { list: user.permitted_hotels, total: user.permitted_hotels.length };
    }
    if (pathname === '/api/agent/operating-questions' && request.method() === 'POST') {
      const payload = request.postDataJSON();
      apiCalls[apiCalls.length - 1].payload = payload;
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
    if (pathname === '/api/agent/system-guidance' && request.method() === 'POST') {
      const payload = request.postDataJSON();
      guidanceRequests.push(payload);
      await new Promise(resolve => setTimeout(resolve, 80));
      if (String(payload.query || '').includes('报告')) {
        data = intelligentResult({
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
        data = intelligentResult({
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
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });
};

test('intelligent system assistant understands natural language and opens the real data-health page', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.setViewportSize({ width: 393, height: 734 });

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  const launcher = page.getByTestId('system-guide-floating-launcher');
  await expect(page.getByTestId('system-guide-floating-entry')).toHaveCount(1);
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await launcher.click();

  const panel = page.getByTestId('system-guide-floating-panel');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('宿析智能使用助手');
  await expect(panel).toContainText('说出目标，我带你找到入口并核对是否完成');
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
  await expect(result).toContainText('已理解目标');
  await expect(result).toContainText('你现在不是要看经营结论');
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

  await page.getByTestId('system-guide-journey-open-data-health').click();
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'online-data');
  await expect(page.getByTestId('system-guide-page-coach')).toBeVisible();

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await launcher.click();
  const restoredJourney = page.getByTestId('system-guide-active-journey');
  await expect(restoredJourney).toBeVisible();
  await expect(restoredJourney).toContainText('继续上次任务');
  await expect(restoredJourney).toContainText('恢复携程数据后生成一份给店长查看的 AI 经营日报');
  await expect(restoredJourney).toContainText('生成和查看 AI 经营日报');
  await expect(panel.getByRole('button', { name: '继续当前任务' })).toBeVisible();
  expect(apiCalls.filter(call => call.pathname === '/api/agent/operating-questions')).toEqual([]);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('assistant exposes explicit guide, report and action modes', async ({ page }) => {
  test.setTimeout(45000);
  const apiCalls = [];
  const guidanceRequests = [];
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await page.getByTestId('system-guide-floating-launcher').click();

  await expect(page.getByTestId('system-guide-mode-auto')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('system-guide-mode-report').click();
  await expect(page.getByTestId('system-guide-mode-report')).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('system-guide-input').fill('给我今天的经营报告');
  await page.getByTestId('system-guide-submit').click();

  await expect(page.getByTestId('system-guide-mode')).toContainText('证据结论');
  expect(guidanceRequests[0].requested_mode).toBe('report');
  expect(apiCalls.find(call => call.pathname === '/api/agent/operating-questions' && call.method === 'POST')?.payload?.model_key).toBe('deepseek_v4_pro');

  await page.getByTestId('system-guide-mode-action').click();
  await expect(page.getByTestId('system-guide-mode-action')).toHaveAttribute('aria-pressed', 'true');
});

test('intelligent system assistant keeps conversation context and changes the next route', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);

  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  const launcher = page.getByTestId('system-guide-floating-launcher');
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await launcher.click();

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
  expect(apiCalls.filter(call => call.method === 'POST').map(call => call.pathname)).toEqual([
    '/api/agent/system-guidance',
    '/api/agent/operating-questions',
    '/api/agent/system-guidance',
  ]);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});

test('system assistant can be dragged, kept on screen, hidden and restored', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  const apiCalls = [];
  const guidanceRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page, apiCalls, guidanceRequests);
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-suxi-authenticated-interactive-ready', '1', { timeout: 15000 });

  const entry = page.getByTestId('system-guide-floating-entry');
  const launcher = page.getByTestId('system-guide-floating-launcher');
  const panel = page.getByTestId('system-guide-floating-panel');
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await expect(launcher).toHaveAttribute('aria-label', '打开宿析智能使用助手');
  await expect(launcher).toContainText('打开助手');
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

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('html')).toHaveAttribute('data-suxi-authenticated-interactive-ready', '1', { timeout: 15000 });
  await expect(launcher).toBeVisible({ timeout: 15000 });
  const restoredLauncher = await launcher.boundingBox();
  expect(restoredLauncher).toBeTruthy();
  expect(Math.abs(restoredLauncher.x - draggedLauncher.x)).toBeLessThanOrEqual(2);
  expect(Math.abs(restoredLauncher.y - draggedLauncher.y)).toBeLessThanOrEqual(2);

  await launcher.click();
  await expect(entry).toHaveAttribute('open', '');
  await expect(panel).toBeVisible();
  await expect(launcher).toHaveAttribute('aria-label', '收起宿析智能使用助手');
  await expect(launcher).toContainText('收起');
  await expect(page.getByTestId('system-guide-drag-handle')).toContainText('拖动');

  const handle = page.getByTestId('system-guide-drag-handle');
  const handleBox = await handle.boundingBox();
  expect(handleBox).toBeTruthy();
  await page.mouse.move(handleBox.x + handleBox.width / 2, handleBox.y + handleBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(-1200, -900, { steps: 10 });
  await page.mouse.up();

  const draggedPanel = await entry.boundingBox();
  expect(draggedPanel.x).toBeGreaterThanOrEqual(7);
  expect(draggedPanel.y).toBeGreaterThanOrEqual(7);
  expect(draggedPanel.x + draggedPanel.width).toBeLessThanOrEqual(1273);
  expect(draggedPanel.y + draggedPanel.height).toBeLessThanOrEqual(793);

  await launcher.click();
  await expect(entry).not.toHaveAttribute('open', '');
  await expect(panel).not.toBeVisible();
  await expect(launcher).toBeVisible();
  await expect(launcher).toHaveAttribute('aria-label', '打开宿析智能使用助手');
  await expect(launcher).toContainText('打开助手');

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await expect(entry).not.toHaveAttribute('open', '');
  await expect(launcher).toHaveAttribute('aria-label', '打开宿析智能使用助手');
  await launcher.click();
  await expect(panel).toBeVisible();
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
