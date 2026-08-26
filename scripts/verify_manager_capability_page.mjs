#!/usr/bin/env node
import { chromium } from '@playwright/test';

const baseUrl = String(process.env.SUXIOS_LOCAL_BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const token = String(process.env.SUXIOS_MANAGER_CAPABILITY_TOKEN || '');
const hotelId = Number(process.env.SUXIOS_MANAGER_CAPABILITY_HOTEL_ID || 0);
const errors = [];
const summary = {
  hotel_id: hotelId,
  panel_visible: false,
  dimension_count: 0,
  manager_option_count: 0,
  managers_http_status: 0,
  profile_http_status: 0,
  daily_status_visible: false,
  daily_status_truth_boundary_visible: false,
  queue_visible: false,
  due_queue_outside_recent_opened: false,
  manager_write_request_count: 0,
  followup_ui_fixture_only: true,
  followup_form_visible: false,
  followup_outcome_option_count: 0,
  narrow_followup_form_visible: false,
  narrow_followup_form_overflow: false,
  adjustment_form_visible: false,
  score_review_form_visible: false,
  page_error_count: 0,
};

if (!token || hotelId <= 0) {
  console.log(JSON.stringify({ status: 'fail', summary, errors: ['missing_temporary_scope'] }));
  process.exit(1);
}

let browser;
try {
  browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  page.on('pageerror', error => errors.push(`page_error:${error.message}`));
  page.on('request', request => {
    if (request.url().includes('/api/operation/manager-capability/') && request.method() !== 'GET') {
      summary.manager_write_request_count += 1;
    }
  });
  page.on('response', response => {
    const url = response.url();
    if (url.includes('/api/operation/manager-capability/managers')) {
      summary.managers_http_status = response.status();
    }
    if (url.includes('/api/operation/manager-capability/profile')) {
      summary.profile_http_status = response.status();
    }
  });
  await page.addInitScript(value => sessionStorage.setItem('token', value), token);
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 30_000 });

  await page.waitForFunction(() => {
    const root = document.querySelector('#app');
    return Boolean(root?.__vue_app__?._instance?.proxy || root?._vnode?.component?.proxy);
  }, null, { timeout: 30_000 });
  const navigationTriggered = await page.evaluate(async () => {
    const root = document.querySelector('#app');
    const proxy = root?.__vue_app__?._instance?.proxy || root?._vnode?.component?.proxy;
    if (!proxy) return false;
    proxy.currentPage = 'ops-track';
    await proxy.$nextTick?.();
    if (typeof proxy.loadOperationActions === 'function') await proxy.loadOperationActions();
    return proxy.currentPage === 'ops-track';
  });
  if (!navigationTriggered) throw new Error('operation_navigation_not_triggered');

  const panel = page.locator('[data-testid="manager-capability-panel"]');
  try {
    await panel.waitFor({ state: 'visible', timeout: 30_000 });
  } catch {
    const diagnostics = await page.evaluate(() => ({
      render_phase: document.documentElement.dataset.suxiRenderPhase || '',
      interactive_error: document.documentElement.dataset.suxiAuthenticatedInteractiveError || '',
      initial_page: window.SUXI_INITIAL_PAGE_OVERRIDE || '',
      body_excerpt: String(document.body?.innerText || '').replace(/\s+/g, ' ').slice(0, 240),
    }));
    throw new Error(`manager_panel_not_visible:${JSON.stringify(diagnostics)}`);
  }
  summary.panel_visible = true;

  const hotelSelect = page.locator('[data-testid="operation-scope-hotel"]');
  await hotelSelect.selectOption(String(hotelId));
  const managerSelect = page.locator('[data-testid="manager-capability-manager"]');
  await managerSelect.waitFor({ state: 'visible', timeout: 20_000 });
  await page.waitForFunction(() => {
    const select = document.querySelector('[data-testid="manager-capability-manager"]');
    return select instanceof HTMLSelectElement && select.options.length > 1;
  }, null, { timeout: 20_000 });
  summary.manager_option_count = await managerSelect.locator('option').count() - 1;
  for (let attempt = 0; attempt < 40 && summary.profile_http_status === 0; attempt += 1) {
    await page.waitForTimeout(250);
  }

  const dimensions = page.locator('[data-testid="manager-capability-dimensions"] > div');
  await dimensions.first().waitFor({ state: 'visible', timeout: 20_000 });
  summary.dimension_count = await dimensions.count();

  await page.route('**/api/operation/manager-capability/profile?**', async route => {
    const response = await route.fetch();
    const payload = await response.json();
    if (response.status() !== 200 || Number(payload?.code || 0) !== 200 || !payload?.data) {
      await route.fulfill({ response });
      return;
    }
    const requestUrl = new URL(route.request().url());
    const managerUserId = Number(requestUrl.searchParams.get('manager_user_id') || 0);
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date());
    payload.data.daily_submission = {
      business_date: today,
      timezone: 'Asia/Shanghai',
      status: 'submitted',
      label: '今日已提交',
      case_count: 1,
      case_ids: [987654321],
      last_submission_date: today,
      consecutive_missing_days: 0,
      attention_status: 'none',
      history_status: 'available',
      active_case_scan_count: 1,
      invalid_business_date_count: 0,
      source_quality_status: 'manual_declared',
      independent_verification: false,
      closure_inferred: false,
      closure_note: '已提交不等于已闭环；仍以复查事件和可核对的验证结果为准',
      automation_policy: '状态只供人工查看，不自动提醒、建任务、处罚或外发',
    };
    payload.data.recent_cases = [{
      id: 987654321,
      tenant_id: Number(payload.data.tenant_id || 0),
      hotel_id: Number(payload.data.hotel_id || 0),
      manager_user_id: managerUserId,
      business_date: today,
      problem_facts: 'test-only：用于打开追加复查表单，不提交也不保存。',
      action_taken: 'test-only：只验证本地页面交互分支。',
      verification_status: 'planned_verification',
      verification_text: 'test-only：等待复查。',
      followup_due_date: today,
      current_followup_due_date: today,
      case_status: 'pending_verification',
      is_voided: false,
      evidence: { type: 'signed_checklist', type_label: '签字清单/台账', reference: 'test-only:recent', date: today, confidence: 'high', confidence_label: '较高' },
      latest_followup: null,
      score_snapshot: {
        scoring_version: 'manager_capability_evidence_v1',
        dimensions: Array.isArray(payload.data.dimensions) ? payload.data.dimensions : [],
        case_score: null,
        scored_dimension_count: 0,
        score_status: 'pending_verification',
        evidence_digest: 'a'.repeat(64),
      },
    }];
    await route.fulfill({
      response,
      headers: { ...response.headers(), 'content-type': 'application/json; charset=utf-8' },
      body: JSON.stringify(payload),
    });
  });
  await page.route('**/api/operation/manager-capability/followup-queue?**', async route => {
    const requestUrl = new URL(route.request().url());
    const managerUserId = Number(requestUrl.searchParams.get('manager_user_id') || 0);
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai' }).format(new Date());
    await route.fulfill({
      status: 200,
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        code: 200, message: 'ok', data: {
          tenant_id: 1, hotel_id: hotelId, manager_user_id: managerUserId,
          business_date: today, horizon_end: today, data_status: 'ready',
          counts: { overdue: 0, today: 1, upcoming: 0, all: 1 },
          rows: [{
            id: 987654322, tenant_id: 1, hotel_id: hotelId, manager_user_id: managerUserId,
            manager_name: 'test-only', business_date: today,
            problem_facts: 'test-only：该案例不在最近10条中，用于验证队列仍能直接打开复查。',
            action_taken: 'test-only：只验证本地页面交互。',
            verification_status: 'planned_verification', verification_text: 'test-only：等待复查。',
            followup_due_date: today, current_followup_due_date: today,
            case_status: 'pending_verification', is_voided: false,
            due_bucket: 'today', days_offset: 0,
            evidence: { type: 'system_record', type_label: '系统记录/报表', reference: 'test-only:queue', date: today, confidence: 'high', confidence_label: '较高' },
            score_snapshot: { scoring_version: 'manager_capability_evidence_v1', dimensions: [], case_score: null, scored_dimension_count: 0, score_status: 'pending_verification', evidence_digest: 'b'.repeat(64) },
          }],
        },
      }),
    });
  });
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  await page.waitForFunction(() => {
    const root = document.querySelector('#app');
    return Boolean(root?.__vue_app__?._instance?.proxy || root?._vnode?.component?.proxy);
  }, null, { timeout: 30_000 });
  await page.evaluate(async () => {
    const root = document.querySelector('#app');
    const proxy = root?.__vue_app__?._instance?.proxy || root?._vnode?.component?.proxy;
    if (!proxy) return false;
    proxy.currentPage = 'ops-track';
    await proxy.$nextTick?.();
    if (typeof proxy.loadOperationActions === 'function') await proxy.loadOperationActions();
    return true;
  });
  await page.locator('[data-testid="manager-capability-panel"]').waitFor({ state: 'visible', timeout: 30_000 });
  await page.locator('[data-testid="operation-scope-hotel"]').selectOption(String(hotelId));
  const dailyStatus = page.locator('[data-testid="manager-capability-daily-status"]');
  await dailyStatus.waitFor({ state: 'visible', timeout: 20_000 });
  summary.daily_status_visible = await dailyStatus.isVisible();
  summary.daily_status_truth_boundary_visible = (await dailyStatus.textContent())?.includes('已提交不等于已闭环') === true;
  const queue = page.locator('[data-testid="manager-capability-followup-queue"]');
  await queue.waitFor({ state: 'visible', timeout: 20_000 });
  summary.queue_visible = await queue.isVisible();
  const followupOpen = page.locator('[data-testid="manager-capability-due-followup-987654322"]');
  await followupOpen.waitFor({ state: 'visible', timeout: 20_000 });
  await followupOpen.click();
  const followupForm = page.locator('[data-testid="manager-capability-followup-form"]');
  await followupForm.waitFor({ state: 'visible', timeout: 10_000 });
  summary.followup_form_visible = await followupForm.isVisible();
  summary.due_queue_outside_recent_opened = (await followupForm.textContent())?.includes('#987654322') === true;
  summary.followup_outcome_option_count = await followupForm.locator('[data-testid="manager-capability-followup-outcome"] option').count();
  await followupForm.getByRole('button', { name: '取消' }).click();
  const caseCard = page.locator('[data-testid="manager-capability-case-987654321"]');
  await caseCard.getByRole('button', { name: '纠错' }).click();
  const adjustmentForm = page.locator('[data-testid="manager-capability-adjustment-form"]');
  await adjustmentForm.waitFor({ state: 'visible', timeout: 10_000 });
  summary.adjustment_form_visible = await adjustmentForm.isVisible();
  await adjustmentForm.getByRole('button', { name: '取消' }).click();
  await caseCard.getByRole('button', { name: '人工复核' }).click();
  const reviewForm = page.locator('[data-testid="manager-capability-score-review-form"]');
  await reviewForm.waitFor({ state: 'visible', timeout: 10_000 });
  summary.score_review_form_visible = await reviewForm.isVisible();
  await reviewForm.getByRole('button', { name: '取消' }).click();
  await followupOpen.click();
  await followupForm.waitFor({ state: 'visible', timeout: 10_000 });
  await page.setViewportSize({ width: 390, height: 844 });
  summary.narrow_followup_form_visible = await followupForm.isVisible();
  summary.narrow_followup_form_overflow = await followupForm.evaluate(element => element.scrollWidth > element.clientWidth + 1);
  summary.page_error_count = errors.length;

  if (summary.dimension_count !== 6) errors.push(`dimension_count:${summary.dimension_count}`);
  if (summary.manager_option_count <= 0) errors.push('manager_options_empty');
  if (summary.managers_http_status !== 200) errors.push(`managers_http:${summary.managers_http_status}`);
  if (summary.profile_http_status !== 200) errors.push(`profile_http:${summary.profile_http_status}`);
  if (!summary.daily_status_visible) errors.push('daily_status_not_visible');
  if (!summary.daily_status_truth_boundary_visible) errors.push('daily_status_truth_boundary_missing');
  if (!summary.queue_visible) errors.push('followup_queue_not_visible');
  if (!summary.due_queue_outside_recent_opened) errors.push('due_queue_outside_recent_not_opened');
  if (!summary.followup_form_visible) errors.push('followup_form_not_visible');
  if (summary.followup_outcome_option_count !== 3) errors.push(`followup_outcome_options:${summary.followup_outcome_option_count}`);
  if (!summary.narrow_followup_form_visible) errors.push('narrow_followup_form_not_visible');
  if (summary.narrow_followup_form_overflow) errors.push('narrow_followup_form_overflow');
  if (!summary.adjustment_form_visible) errors.push('adjustment_form_not_visible');
  if (!summary.score_review_form_visible) errors.push('score_review_form_not_visible');
  if (summary.manager_write_request_count !== 0) errors.push('unexpected_manager_write_request');
} catch (error) {
  errors.push(`exception:${error?.message || String(error)}`);
} finally {
  if (browser) await browser.close();
}

summary.page_error_count = errors.filter(error => error.startsWith('page_error:')).length;
console.log(JSON.stringify({ status: errors.length === 0 ? 'pass' : 'fail', summary, errors }));
process.exit(errors.length === 0 ? 0 : 1);
