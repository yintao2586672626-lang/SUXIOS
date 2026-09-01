const { test, expect } = require('@playwright/test');

test.use({
  browserName: 'chromium',
  headless: true,
  viewport: { width: 1280, height: 900 },
  actionTimeout: 5000,
  navigationTimeout: 15000,
});

const appUrl = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080/';
const user = {
  id: 829,
  username: 'hotel_data_analyst_probe',
  realname: '酒店数据分析师验收账号',
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
  permitted_hotels: [{ id: 7, name: '酒店数据分析师验收酒店', tenant_id: 7, status: 1 }],
  context: {
    token_status: 'valid', tenantId: 7, hotelId: 7,
    permitted_hotel_ids: [7], permissionStatus: 'allowed',
  },
};

const mockAuthenticatedApi = async (page) => {
  let operatingQuestionReadback = null;
  let preciseQueryReadback = null;
  const feedbacks = [];
  let nextFeedbackId = 1200;
  await page.addInitScript((profile) => {
    sessionStorage.setItem('token', 'hotel-data-analyst-probe-token');
    localStorage.setItem('suxios_auth_user_cache_v1', JSON.stringify({ saved_at: Date.now(), user: profile }));
  }, user);
  await page.route('**/api/**', async (route) => {
    const pathname = new URL(route.request().url()).pathname;
    let data = { list: [], items: [], total: 0 };
    if (pathname === '/api/auth/info') data = user;
    if (pathname === '/api/hotels') data = { list: user.permitted_hotels, total: 1 };
    if (pathname === '/api/agent/overview') data = { agents: {}, recent_logs: [] };
    if (pathname === '/api/agent/local-ai/capabilities') {
      data = {
        status: 'not_configured',
        boundaries: { local_only: true, external_message: false, automatic_execution: false, ota_write: false },
      };
    }
    if (pathname === '/api/agent/operating-question-scopes') {
      data = {
        contract_version: 'operating_question_scope_options.v1',
        data_status: 'empty', hotel_id: 7, recommended: null, platforms: [],
        boundary: { source_scope: 'ota_channel', whole_hotel_conclusion: false },
        data_gaps: [{ code: 'saved_verified_fact_missing' }],
      };
    }
    if (pathname === '/api/agent/operating-questions' && route.request().method() === 'POST') {
      const payload = route.request().postDataJSON();
      const digest = 'c'.repeat(64);
      operatingQuestionReadback = {
        id: 990,
        tenant_id: 7,
        hotel_id: Number(payload.hotel_id),
        platform: String(payload.platform),
        date_start: String(payload.date_start),
        date_end: String(payload.date_end),
        question_text: String(payload.question),
        content_digest: digest,
        persistence_status: 'readback_verified',
        answer_status: 'evidence_ready',
        answer_summary: '已读取严格事实，但当前仅形成证据摘要。',
        fact_refs: ['online_daily_data#7001'],
        memory_refs: [], knowledge_refs: [], execution_refs: [],
        data_gaps: [{ code: 'saved_agent_diagnosis_missing', message: '缺少同范围已保存诊断。' }],
        answer: {
          mode: 'deterministic_saved_evidence',
          status: 'evidence_ready',
          summary: '已读取严格事实，但当前仅形成证据摘要。',
          scope: {
            tenant_id: 7, hotel_id: Number(payload.hotel_id), platform: String(payload.platform),
            date_start: String(payload.date_start), date_end: String(payload.date_end), source_scope: 'ota_channel',
          },
          evidence_counts: { facts: 1, knowledge_chunks: 0, operating_memories: 0, execution_reviews: 0 },
          data_gaps: [{ code: 'saved_agent_diagnosis_missing', message: '缺少同范围已保存诊断。' }],
          missing_information: [], action_drafts: [],
          ai_runtime: { status: 'not_called' },
          boundaries: { ota_write: false, external_message: false, automatic_execution: false },
        },
        analysis_quality_receipt: {
          contract_version: 'hotel_data_analyst_quality_receipt.v1',
          role_key: 'hotel_data_analyst',
          quality_status: 'passed',
          claim_status: 'limited',
          readback_verified: true,
          external_action_authorized: false,
          subject_digest: digest,
          scope_digest: 'e'.repeat(64),
          evidence_digest: 'f'.repeat(64),
          receipt_digest: 'd'.repeat(64),
          status: 'partial',
          status_label: '部分结果可用',
          summary: '已有严格可用部分，但仍有明确缺口；只使用已验证部分。',
          check_count: 2, passed_count: 1, partial_count: 1, blocked_count: 0,
          checks: [
            { key: 'scope_identity', label: '范围身份', status: 'passed', message: '范围一致。', reason_code: '' },
            { key: 'evidence_quality', label: '证据资格', status: 'partial', message: '只有证据摘要。', reason_code: 'verified_evidence_partial' },
          ],
          next_actions: ['补齐同范围已保存诊断。'],
          usage_policy: {
            verified_portion_usable: true, analysis_claim_allowed: false,
            whole_hotel_conclusion_allowed: false, human_confirmation_required: true,
            external_action_authorized: false, ota_write: false, pms_write: false,
            external_message: false, automatic_execution: false,
          },
        },
      };
      data = { question: operatingQuestionReadback, persistence_status: 'readback_verified' };
    }
    if (pathname === '/api/agent/operating-questions/990' && route.request().method() === 'GET') {
      data = operatingQuestionReadback;
    }
    if (pathname === '/api/agent/operating-questions/990/feedbacks/mine' && route.request().method() === 'GET') {
      data = {
        contract_version: 'hotel_data_analyst_feedback.v1',
        data_status: 'ready',
        question_id: 990,
        list: feedbacks,
        latest: feedbacks[0] || null,
        summary: {
          total: feedbacks.length,
          useful: feedbacks.filter(item => item.feedback_kind === 'useful').length,
          needs_correction: feedbacks.filter(item => item.feedback_kind === 'needs_correction').length,
        },
        boundaries: {
          usage_policy: 'eval_candidate_only_no_training',
          original_analysis_mutated: false,
          formal_evaluation_case_created: false,
          model_training_triggered: false,
          external_model_called: false,
          ota_write: false,
          pms_write: false,
          external_message: false,
          automatic_execution: false,
          external_action_authorized: false,
        },
      };
    }
    if (pathname === '/api/agent/operating-questions/990/feedbacks' && route.request().method() === 'POST') {
      const payload = route.request().postDataJSON();
      expect(payload.source_content_digest).toBe('c'.repeat(64));
      expect(payload.quality_receipt_digest).toBe('d'.repeat(64));
      expect(payload.idempotency_key).toMatch(/^analyst-feedback:990:/);
      const feedbackKind = String(payload.feedback_kind || '');
      const correction = feedbackKind === 'needs_correction'
        ? { summary: String(payload.correction_text || ''), issue_codes: payload.issue_codes || [] }
        : {};
      const projection = feedbackKind === 'needs_correction'
        ? {
            contract_version: 'hotel_data_analyst_feedback_projection.v1',
            persistence_status: 'not_persisted',
            formal_evaluation_case_created: false,
            review_status: 'candidate_only',
            replay_status: 'blocked',
            blockers: ['blocked_by_missing_frozen_replay_input'],
            case: null,
            external_model_called: false,
            model_training_triggered: false,
            external_action_authorized: false,
          }
        : {
            contract_version: 'hotel_data_analyst_feedback_projection.v1',
            persistence_status: 'not_persisted',
            formal_evaluation_case_created: false,
            review_status: 'candidate_only',
            replay_status: 'not_applicable',
            blockers: ['useful_feedback_is_not_gold_answer'],
            case: null,
            external_model_called: false,
            model_training_triggered: false,
            external_action_authorized: false,
          };
      const feedback = {
        id: nextFeedbackId++,
        contract_version: 'hotel_data_analyst_feedback.v1',
        tenant_id: 7,
        hotel_id: 7,
        question_id: 990,
        source_content_digest: 'c'.repeat(64),
        quality_receipt_digest: 'd'.repeat(64),
        feedback_kind: feedbackKind,
        correction,
        usage_policy: 'eval_candidate_only_no_training',
        evaluation_projection: projection,
        content_digest: String(nextFeedbackId).padStart(64, 'a').slice(-64),
        readback_verified: true,
        persistence_status: 'readback_verified',
        created_at: `2026-08-29 12:00:0${feedbacks.length}.000000`,
        formal_evaluation_case_created: false,
        model_training_triggered: false,
        external_action_authorized: false,
        boundaries: {
          original_analysis_mutated: false,
          formal_evaluation_case_created: false,
          model_training_triggered: false,
          external_action_authorized: false,
        },
      };
      feedbacks.unshift(feedback);
      data = feedback;
    }
    const feedbackMatch = pathname.match(/^\/api\/agent\/operating-questions\/990\/feedbacks\/(\d+)$/);
    if (feedbackMatch && route.request().method() === 'GET') {
      data = feedbacks.find(item => item.id === Number(feedbackMatch[1])) || null;
    }
    if (pathname === '/api/agent/precise-queries' && route.request().method() === 'POST') {
      const payload = route.request().postDataJSON();
      preciseQueryReadback = {
        id: 991,
        question: String(payload.query || ''),
        content_digest: '9'.repeat(64),
        persistence_status: 'readback_verified',
        status: 'ready',
        route_type: 'operating_query',
        answer_status: operatingQuestionReadback?.answer_status || 'evidence_ready',
        answer_summary: operatingQuestionReadback?.answer_summary || '',
        answer: operatingQuestionReadback?.answer || {},
        parsed_scope: {
          hotel_id: 7, hotel_name: '酒店数据分析师验收酒店', platform: 'ctrip', business_date: '2026-08-29',
        },
        lexicon: {}, knowledge_refs: [], fact_refs: operatingQuestionReadback?.fact_refs || [],
        operating_question: operatingQuestionReadback,
      };
      data = preciseQueryReadback;
    }
    if (pathname === '/api/agent/precise-queries/991' && route.request().method() === 'GET') {
      data = preciseQueryReadback;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, data, message: 'ok' }),
    });
  });
};

test('hotel data analyst is globally summonable and opens the real scoped composer', async ({ page }) => {
  test.setTimeout(45000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await mockAuthenticatedApi(page);
  await page.goto(appUrl, { waitUntil: 'domcontentloaded' });

  const launcher = page.getByTestId('system-guide-floating-launcher');
  await expect(launcher).toBeVisible({ timeout: 15000 });
  await launcher.click();
  const globalRole = page.getByTestId('system-guide-mode-report');
  await expect(globalRole).toBeVisible({ timeout: 15000 });
  await expect(globalRole).toContainText('数据分析师');
  await expect(globalRole).toHaveAttribute('data-role-key', 'hotel_data_analyst');
  await globalRole.click();
  await expect(globalRole).toHaveAttribute('aria-pressed', 'true');
  await launcher.click();

  await page.getByText('系统与工具', { exact: true }).click();
  await page.getByText('高级AI工具箱', { exact: true }).click();
  await expect(page.getByTestId('app-main')).toHaveAttribute('data-current-page', 'agent-center');

  const role = page.getByTestId('hotel-data-analyst-role');
  await expect(role).toBeVisible({ timeout: 15000 });
  await expect(role).toHaveAttribute('data-role-key', 'hotel_data_analyst');
  await expect(role).toContainText('经营指标诊断');
  await expect(role).toContainText('OTA渠道事实不扩大为全酒店结论');

  const example = '当前选择范围最需要复核的经营指标是什么？请列出证据和缺口。';
  await role.getByRole('button', { name: new RegExp(example.slice(0, 12)) }).click();
  const input = page.getByTestId('hotel-data-analyst-question-input');
  await expect(input).toHaveValue(example);
  expect(await input.evaluate(element => document.activeElement === element)).toBe(true);
  await page.setViewportSize({ width: 393, height: 734 });
  await page.getByTestId('hotel-data-analyst-submit').click();
  const receipt = page.getByTestId('operating-question-quality-receipt');
  await expect(receipt).toBeVisible();
  await expect(receipt).toHaveAttribute('data-analysis-quality-status', 'partial');
  await expect(receipt).toHaveAttribute('data-analysis-quality-result', 'passed');
  await expect(receipt).toHaveAttribute('data-analysis-claim-status', 'limited');
  await expect(receipt).toContainText('只使用已验证部分');
  await expect(receipt).toContainText('自检合同通过');

  const feedback = page.getByTestId('operating-question-quality-feedback');
  await expect(feedback).toBeVisible();
  await expect(feedback).toHaveAttribute('data-original-analysis-mutated', 'false');
  await expect(feedback).toHaveAttribute('data-formal-evaluation-case-created', 'false');
  await expect(feedback).toHaveAttribute('data-model-training-triggered', 'false');
  await expect(feedback).toHaveAttribute('data-external-action-authorized', 'false');

  await page.getByTestId('operating-question-quality-feedback-useful').click();
  await page.getByTestId('operating-question-quality-feedback-save').click();
  await expect(page.getByTestId('operating-question-quality-feedback-saved')).toContainText('原分析记录保持不变');
  await expect(page.getByTestId('operating-question-quality-feedback-latest')).toContainText('有用');

  await page.getByTestId('operating-question-quality-feedback-needs-correction').click();
  await page.getByTestId('operating-question-quality-feedback-issue-metric_definition').click();
  const correction = '这里应保持携程 OTA 渠道口径，不能扩大成全酒店经营结论。';
  await page.getByTestId('operating-question-quality-feedback-correction-text').fill(correction);
  await page.getByTestId('operating-question-quality-feedback-save').click();
  await expect(page.getByTestId('operating-question-quality-feedback-latest')).toContainText(correction);
  await expect(page.getByTestId('operating-question-quality-feedback-latest')).toContainText('纠错候选');
  await expect(page.getByTestId('operating-question-quality-feedback-history')).toBeVisible();
  await expect(page.getByText('已读取严格事实，但当前仅形成证据摘要。', { exact: true }).first()).toBeVisible();

  const overflow = await receipt.evaluate(element => element.scrollWidth - element.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);

  await launcher.click();
  await page.getByTestId('system-guide-input').fill('请以酒店数据分析师角色复核当前携程经营事实。');
  await page.getByTestId('system-guide-submit').click();
  const globalFeedback = page.getByTestId('system-guide-analysis-quality-feedback');
  await expect(globalFeedback).toBeVisible({ timeout: 15000 });
  await expect(globalFeedback).toContainText(correction);
  await expect(globalFeedback).toHaveAttribute('data-original-analysis-mutated', 'false');
  await page.getByTestId('system-guide-analysis-quality-feedback-useful').click();
  await page.getByTestId('system-guide-analysis-quality-feedback-save').click();
  await expect(page.getByTestId('system-guide-analysis-quality-feedback-latest')).toContainText('有用');
  const globalOverflow = await globalFeedback.evaluate(element => element.scrollWidth - element.clientWidth);
  expect(globalOverflow).toBeLessThanOrEqual(1);
  expect(pageErrors, `page errors: ${pageErrors.join(' | ')}`).toEqual([]);
});
