const { test, expect } = require('@playwright/test');
const { getConfig, login } = require('./e2e-helpers');

const config = getConfig();
const shanghaiDate = date => {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(date);
  const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
};
const businessDate = shanghaiDate(new Date());
const reviewDate = shanghaiDate(new Date(Date.now() + 86_400_000));

test.use({
  browserName: 'chromium',
  channel: 'chrome',
  headless: true,
  viewport: { width: 393, height: 734 },
  actionTimeout: 8000,
  navigationTimeout: 15000,
});
test.setTimeout(60000);

const selected = (hotelId, key = 'gap:ctrip:core_facts', platform = 'ctrip') => ({
  candidate_key: key,
  source_type: 'explicit_data_gap',
  problem: `${platform === 'ctrip' ? '携程' : '美团'}目标日期核心事实仍缺失`,
  fact_basis: [{
    statement: '当前营业日的核心事实缺口正在阻断后续收益判断。',
    evidence_ref: 'dual_ota_field_closure#daily',
    quality_status: 'gap_readback_verified',
  }],
  recommended_action: {
    type: 'collect_trusted_ota_facts',
    object: `${platform}_target_date_strict_facts`,
    title: `补齐${platform === 'ctrip' ? '携程' : '美团'}可信事实`,
    description: '只补齐事实，不调价、不改房态。',
    steps: ['读取目标日期。', '保存并精确回读。'],
  },
  expected_observation_metric: {
    key: `${platform}_strict_core_fact_count`,
    label: '严格核心事实数',
    unit: 'verified_fields',
    baseline_value: 0,
  },
  scope: {
    tenant_id: 1,
    hotel_id: hotelId,
    platform,
    business_date: businessDate,
    metric_scope: 'ota_channel_data_quality',
    scope_note: '仅限当前酒店、平台与营业日。',
  },
  risk: { level: 'low', summary: '防止误用旧数据。' },
  responsibility: {
    owner_id: 1,
    owner_label: '当前确认人',
    due_at: `${businessDate} 23:00:00`,
    review_at: `${reviewDate} 10:00:00`,
  },
  ranking: {
    impact: 100,
    urgency: 100,
    evidence_strength: 100,
    execution_cost: 18,
  },
  source: {
    record_ref: 'dual_ota_field_closure#daily',
    snapshot_digest: 'a'.repeat(64),
    fact_refs: ['dual_ota_field_closure#daily'],
  },
  external_write_boundary: {
    causality_claimed: false,
  },
  material_identity_digest: 'b'.repeat(64),
  content_digest: key.includes('meituan') ? 'd'.repeat(64) : 'c'.repeat(64),
  recommendation_explanation: {
    contract_version: 'daily_one_thing_explanation.v1',
    why_now: {
      summary: '当前营业日的核心事实缺口正在阻断后续收益判断。',
      source_refs: ['dual_ota_field_closure#daily'],
    },
    why_recommended: {
      summary: '该事项位于最高四维业务并列组。',
      source_refs: ['dual_ota_field_closure#daily'],
    },
    personalization: {
      status: 'not_applied',
      summary: '酒店共享正式事项未使用个人偏好改写。',
    },
  },
});

test('daily one thing shows explainable personal preview and keeps feedback non-authoritative', async ({ page }) => {
  const feedbackCalls = [];
  await page.route('**/api/operation/execution-flow**', async route => {
    const requestUrl = new URL(route.request().url());
    const hotelId = Number(
      requestUrl.searchParams.get('hotel_id')
        || requestUrl.searchParams.get('system_hotel_id')
        || config.hotelId
        || 0
    );
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, message: 'ok', data: {
        data_status: 'ok',
        data_gaps: [],
        capabilities: { hotel_id: hotelId, can_view: true, can_execute: true },
        list: [{
          id: 901,
          hotel_id: hotelId,
          stage: 'approval',
          recommendation: {
            source: 'operating_opportunity_runs#701',
            source_module: 'daily_one_thing',
            source_record_id: 701,
            platform: 'ctrip',
            object_type: 'data_collection',
            action_type: 'collect_trusted_ota_facts',
            date_start: businessDate,
            date_end: businessDate,
            created_at: `${businessDate} 08:30:00`,
          },
          approval: { status: 'pending_approval', approved_at: '', blocked_reason: '' },
          execution: { status: 'pending_create', executed_at: '', blocked_reason: '' },
          assignment: { status: 'scheduled', due_at: `${businessDate} 10:00:00`, review_at: '2026-08-30 10:00:00' },
          review: { status: 'observing', available_at: '2026-08-30 10:00:00', is_available: false },
          next_action: { key: 'approve_intent', label: '审批执行意图' },
          action_management: {
            lifecycle: { status: 'pending_approval' },
            action_card: {
              contract_version: 'operation_action_card.v2',
              problem: '携程目标日期核心事实仍缺失',
              fact_refs: ['operating_opportunity_runs#701', 'dual_ota_field_closure#daily'],
              recommendation_explanation: selected(hotelId).recommendation_explanation,
            },
          },
        }],
      } }),
    });
  });

  await page.route('**/api/operating-opportunities/overview**', async route => {
    const url = new URL(route.request().url());
    const hotelId = Number(url.searchParams.get('hotel_id') || config.hotelId || 0);
    const base = selected(hotelId);
    const personal = selected(hotelId, 'gap:meituan:core_facts', 'meituan');
      const receipt = {
      contract_version: 'daily_one_thing_personalization.v1',
      experience_version: 'daily_one_thing.personalized_preview.v3',
      status: 'applied',
      application_mode: 'base_rank_exact_tie_break_only',
      scope: { tenant_id: 1, user_id: 1, hotel_id: hotelId },
      base_selected_candidate_key: base.candidate_key,
      selected_candidate_key: personal.candidate_key,
      selection_changed: true,
      base_tie_group_size: 2,
      why_you: {
        summary: '只在四维基础完全并列的合格事项中，按你已确认的平台偏好调整了个人预览顺序。',
      },
      context_digest: 'e'.repeat(64),
      decision_digest: 'f'.repeat(64),
      facts_changed: false,
      eligibility_changed: false,
      permissions_changed: false,
      approval_changed: false,
      external_write_authorized: false,
      current_feedback: {
        status: 'not_recorded',
        readback_verified: true,
        feedback_status: null,
        reason_code: null,
        feedback_ref: null,
      },
    };
    delete personal.recommendation_explanation.personalization;
    personal.recommendation_explanation.personalization_receipt_authoritative = true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, message: 'ok', data: {
        contract_version: 'operating_opportunity_lab.v2',
        tenant_id: 1,
        system_hotel_id: hotelId,
        business_date: businessDate,
        today: {
          contract_version: 'daily_one_thing.v2',
          status: 'draft',
          selected: base,
          selection_policy: { full_candidate_list_exposed: false },
        },
        today_preview: {
          contract_version: 'daily_one_thing.v2',
          status: 'draft',
          selected: base,
          selection_policy: { full_candidate_list_exposed: false },
        },
        personalized_today_preview: {
          contract_version: 'daily_one_thing.v2',
          status: 'draft',
          selected: personal,
          selection_policy: { full_candidate_list_exposed: false },
          personalization_receipt: receipt,
        },
        personalization_receipt: receipt,
        today_saved_run: null,
        today_execution_intent: null,
        today_state: 'not_saved',
      } }),
    });
  });

  await page.route('**/api/operating-opportunities/daily-preview/feedback', async route => {
    const payload = route.request().postDataJSON();
    feedbackCalls.push(payload);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 200, message: 'ok', data: {
        readback_verified: true,
        system_hotel_id: Number(payload.hotel_id),
        business_date: payload.business_date,
        hotel_shared_daily_item_changed: false,
        execution_intent_created: false,
        external_write_count: 0,
      } }),
    });
  });

  await login(page, config);

  const homePanel = page.getByTestId('home-daily-one-thing-panel');
  await expect(homePanel).toBeVisible({ timeout: 15000 });
  const homeExplanation = page.getByTestId('home-daily-one-thing-explanation');
  await homeExplanation.locator('summary').click();
  await expect(page.getByTestId('home-daily-one-thing-why-now')).toContainText('当前营业日');
  await expect(page.getByTestId('home-daily-one-thing-why-recommended')).toContainText('最高四维');
  await expect(page.getByTestId('home-daily-one-thing-personalization')).toContainText('个性化未应用');

  const nav = page.getByTestId('app-nav');
  const opportunityNav = nav.getByTestId('nav-operating-opportunities');
  await expect(opportunityNav).toHaveCount(1);
  await opportunityNav.evaluate(element => element.click());
  await expect(page.getByTestId('page-operating-opportunities')).toBeVisible({ timeout: 15000 });
  await expect(page.getByTestId('daily-one-thing-personalized-preview')).toBeVisible({ timeout: 15000 });
  await expect(page.getByTestId('daily-one-thing-personalized-why-you')).toContainText('基础完全并列');
  await expect(page.getByTestId('daily-one-thing-why-now')).toContainText('当前营业日');
  await expect(page.getByTestId('daily-one-thing-personalization')).toContainText('个性化未应用');

  await page.getByTestId('daily-one-thing-feedback-useful').click();
  await expect.poll(() => feedbackCalls.length).toBe(1);
  expect(feedbackCalls[0].feedback_status).toBe('accepted');
  expect(feedbackCalls[0].reason_code).toBe('useful');
  await expect(page.getByTestId('daily-one-thing-feedback-wrong-focus')).toBeDisabled();

  const overflow = await page.getByTestId('daily-one-thing-workbench').evaluate(node => node.scrollWidth - node.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
});
