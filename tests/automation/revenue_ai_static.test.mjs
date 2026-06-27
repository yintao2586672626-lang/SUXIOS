import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context, {
  filename: 'public/revenue-ai-static.js',
});

const helpers = context.window.SUXI_REVENUE_AI_STATIC;

test('Revenue AI static helper exposes the required display contract', () => {
  assert.equal(typeof helpers, 'object');
  for (const key of [
    'revenueAiStatusClass',
    'revenueAiStatusLabel',
    'revenueAiSeverityClass',
    'buildRevenueAiOverviewQuery',
    'buildRevenueAiOverviewEndpoint',
    'resolveRevenueAiBusinessDate',
    'resolveRevenueAiOverviewRequest',
    'resolveRevenueAiOverviewResponse',
    'buildRevenueAiMetricCards',
    'buildRevenueAiGapRows',
    'buildRevenueAiGapSummary',
    'resolveRevenueAiGapTarget',
    'resolveRevenueAiDecisionBasisNavigation',
    'buildRevenueAiStatusRows',
    'buildRevenueAiSignalRows',
    'buildRevenueAiReviewQueueItems',
    'buildRevenueAiActionRows',
    'buildRevenueAiPricingGateRows',
    'buildRevenueAiAgentActivitySummary',
    'buildRevenueAiAgentActivityRows',
    'buildRevenueAiExecutionSummary',
    'buildRevenueAiExecutionRows',
    'buildRevenueAiEffectReviewRows',
    'revenueAiExecutionNeedsRoiEvidence',
    'revenueAiExecutionResolvedActionKey',
    'revenueAiExecutionTaskActionItem',
    'resolveRevenueAiExecutionNavigation',
    'resolveRevenueAiExecutionAction',
    'revenueAiReviewActionKey',
    'isRevenueAiReviewActionLoadingState',
    'buildRevenueAiReviewActionLoadingState',
    'normalizeRevenueAiApiPath',
    'revenueAiReviewActionText',
    'revenueAiReviewEndpoint',
    'resolveRevenueAiReviewActionDraft',
    'validateRevenueAiApprovedPrice',
    'buildRevenueAiReviewConfirmText',
    'buildRevenueAiReviewRequestBody',
    'buildRevenueAiExecutionIntentOpenRow',
    'resolveRevenueAiReviewNavigation',
    'buildRevenueAiReviewNavigationState',
  ]) {
    assert.equal(typeof helpers[key], 'function', `${key} must be exported`);
  }
  assert.equal(helpers.revenueAiStatusLabel('ok'), '正常');
  assert.equal(helpers.revenueAiStatusLabel('not_loaded'), '未接入');
  assert.equal(helpers.revenueAiStatusLabel('not_calculable'), '不可计算');
  assert.equal(helpers.revenueAiStatusLabel('missing'), '缺失');
  assert.equal(helpers.revenueAiStatusLabel('warning'), '需复核');
  assert.match(helpers.revenueAiStatusClass('missing'), /amber/);
  assert.match(helpers.revenueAiStatusClass('warning'), /amber/);
  assert.equal(helpers.revenueAiReasonText('ZERO_CONFIRMED'), '渠道明确确认目标经营日期无数据。');
  assert.match(helpers.revenueAiReasonText('competitor_price_above_competitor'), /高于竞对均价/);
  assert.match(helpers.revenueAiReasonText('floor_price_missing'), /最低保护价/);
  assert.match(helpers.revenueAiReasonText('manual_review_workflow_not_connected'), /人工审核工作流/);
  assert.match(helpers.revenueAiReasonText('agent_logs_error_present'), /错误日志/);
  assert.match(helpers.revenueAiReasonText('operation_execution_review_needed'), /效果复盘/);
  assert.match(helpers.revenueAiReasonText('operation_roi_missing'), /ROI/);
  assert.match(helpers.revenueAiReasonText('demand_forecasts_high_demand'), /高需求/);
  assert.match(helpers.revenueAiReasonText('holiday_event_nearby'), /节假日窗口/);
});

test('Revenue AI overview endpoint builder keeps query scope explicit', () => {
  assert.equal(
    helpers.buildRevenueAiOverviewEndpoint({ businessDate: '2026-06-25', hotelId: '58' }),
    '/revenue-ai/overview?business_date=2026-06-25&hotel_id=58',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewEndpoint({ businessDate: '2026-06-25', hotelId: '' }),
    '/revenue-ai/overview?business_date=2026-06-25',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewQuery({ businessDate: ' 2026-06-25 ', hotelId: ' 58 ' }),
    'business_date=2026-06-25&hotel_id=58',
  );
  assert.equal(
    helpers.resolveRevenueAiBusinessDate({ overview: { business_date: '2026-06-24' }, now: new Date('2026-06-27T10:00:00') }),
    '2026-06-24',
  );
  assert.equal(
    helpers.resolveRevenueAiBusinessDate({ now: new Date('2026-06-27T10:00:00') }),
    '2026-06-26',
  );
  const request = helpers.resolveRevenueAiOverviewRequest({
    hasToken: true,
    currentPage: 'compass',
    businessDate: '2026-06-25',
    hotelId: '58',
  });
  assert.equal(request.shouldLoad, true);
  assert.equal(request.endpoint, '/revenue-ai/overview?business_date=2026-06-25&hotel_id=58');
  assert.equal(helpers.resolveRevenueAiOverviewRequest({ hasToken: false, currentPage: 'compass' }).reason, 'token_missing');
  assert.equal(helpers.resolveRevenueAiOverviewRequest({ hasToken: true, currentPage: 'online-data' }).reason, 'not_compass_page');
  const success = helpers.resolveRevenueAiOverviewResponse({ response: { code: 200, data: { data_status: 'ok' } } });
  assert.equal(success.ok, true);
  assert.equal(success.overview.data_status, 'ok');
  assert.equal(success.errorMessage, '');
  const failed = helpers.resolveRevenueAiOverviewResponse({ response: { code: 500, message: '接口异常' } });
  assert.equal(failed.ok, false);
  assert.equal(failed.overview, null);
  assert.equal(failed.errorMessage, '接口异常');
  const emptyFailed = helpers.resolveRevenueAiOverviewResponse({ response: { code: 500 } });
  assert.equal(emptyFailed.errorMessage, 'Revenue AI 总览接口返回失败');
  const thrown = helpers.resolveRevenueAiOverviewResponse({ error: new Error('网络异常') });
  assert.equal(thrown.errorMessage, '网络异常');
});

test('Revenue AI gap target resolver defaults to the existing data health entry', () => {
  const defaultTarget = helpers.resolveRevenueAiGapTarget({});
  assert.equal(defaultTarget.targetPage, 'online-data');
  assert.equal(defaultTarget.targetTab, 'data-health');
  assert.equal(defaultTarget.targetPlatform, '');

  const snakeCaseTarget = helpers.resolveRevenueAiGapTarget({
    target_page: 'online-data',
    target_tab: 'quality',
    target_platform: 'ctrip',
  });
  assert.equal(snakeCaseTarget.targetPage, 'online-data');
  assert.equal(snakeCaseTarget.targetTab, 'quality');
  assert.equal(snakeCaseTarget.targetPlatform, 'ctrip');

  const camelCaseTarget = helpers.resolveRevenueAiGapTarget({
    targetPage: 'legacy-page',
    targetTab: 'legacy-tab',
    targetPlatform: 'meituan',
  });
  assert.equal(camelCaseTarget.targetPage, 'legacy-page');
  assert.equal(camelCaseTarget.targetTab, 'legacy-tab');
  assert.equal(camelCaseTarget.targetPlatform, 'meituan');
});

test('Revenue AI decision basis navigation resolver keeps target parsing pure', () => {
  const onlineData = helpers.resolveRevenueAiDecisionBasisNavigation({
    target_page: 'online-data',
    target_tab: 'data-health',
    label: '最低保护价',
  });
  assert.equal(onlineData.targetPage, 'online-data');
  assert.equal(onlineData.targetTab, 'data-health');
  assert.equal(onlineData.label, '最低保护价');

  const opsTrack = helpers.resolveRevenueAiDecisionBasisNavigation({
    targetPage: 'ops-track',
    nextAction: '补录 ROI 证据',
  });
  assert.equal(opsTrack.targetPage, 'ops-track');
  assert.equal(opsTrack.nextAction, '补录 ROI 证据');

  const empty = helpers.resolveRevenueAiDecisionBasisNavigation({});
  assert.equal(empty.targetPage, '');
  assert.equal(empty.targetTab, '');
  assert.equal(empty.nextAction, '');
});

test('Revenue AI metric cards keep missing data explicit and scoped', () => {
  const unloadedCards = helpers.buildRevenueAiMetricCards();
  assert.equal(unloadedCards.length, 5);
  assert.ok(unloadedCards.every((card) => card.display === '--'));
  assert.ok(unloadedCards.every((card) => card.statusLabel === '未接入'));
  assert.ok(unloadedCards.every((card) => card.scopeLabel === 'OTA渠道口径'));

  const cards = helpers.buildRevenueAiMetricCards({
    overview: {
      scope: 'ota',
      date_basis: 'data_date',
      metrics: {
        ota_room_revenue: {
          display: '¥1,200',
          status: 'ok',
          scope: 'ota',
          date_basis: 'data_date',
        },
        ota_contribution_revpar: {
          value: null,
          display: '--',
          status: 'not_calculable',
          reason: 'available_room_nights_missing',
          scope: 'hotel_required',
          date_basis: 'data_date',
        },
      },
    },
  });
  const revenue = cards.find((card) => card.key === 'ota_room_revenue');
  const revpar = cards.find((card) => card.key === 'ota_contribution_revpar');
  assert.equal(revenue.display, '¥1,200');
  assert.equal(revenue.statusLabel, '正常');
  assert.equal(revenue.scopeLabel, 'OTA渠道口径');
  assert.equal(revpar.display, '--');
  assert.equal(revpar.statusLabel, '不可计算');
  assert.equal(revpar.scopeLabel, '需全酒店口径');
  assert.match(revpar.reasonText, /暂缺可信全酒店可售房数据/);

  const emptyConfirmedCards = helpers.buildRevenueAiMetricCards({
    overview: {
      scope: 'ota',
      date_basis: 'data_date',
      metrics: {
        ota_room_revenue: {
          value: null,
          display: '--',
          status: 'empty_confirmed',
          reason: 'ZERO_CONFIRMED',
          display_reason: '携程明确确认目标经营日期无数据。',
        },
      },
    },
  });
  const emptyConfirmedRevenue = emptyConfirmedCards.find((card) => card.key === 'ota_room_revenue');
  assert.equal(emptyConfirmedRevenue.statusLabel, '确认无数据');
  assert.equal(emptyConfirmedRevenue.reasonText, '携程明确确认目标经营日期无数据。');
});

test('Revenue AI gap rows expose request failures and source quality issues', () => {
  const failedRows = helpers.buildRevenueAiGapRows({
    overviewError: '接口返回401',
  });
  assert.equal(failedRows.length, 1);
  assert.equal(failedRows[0].key, 'overview_request_failed');
  assert.equal(failedRows[0].statusLabel, '失败');
  assert.equal(failedRows[0].reasonText, '接口返回401');
  assert.equal(failedRows[0].target_tab, 'data-health');

  const issueRows = helpers.buildRevenueAiGapRows({
    overview: {
      missing_datasets: [{
        key: 'ctrip_missing',
        type: 'missing_dataset',
        label: '携程经营概况',
        target_platform: 'ctrip',
        status: 'missing',
        reason: 'DATE_NOT_AVAILABLE',
        severity: 'high',
      }],
      quality_issues: [{
        key: 'meituan_auth',
        label: '美团授权',
        channel: 'meituan',
        status: 'unauthorized',
        reason: 'AUTH_EXPIRED',
        severity: 'medium',
        next_action: '重新登录美团。',
      }],
    },
  });
  assert.equal(issueRows.length, 2);
  assert.equal(issueRows[0].channelLabel, '携程');
  assert.equal(issueRows[0].statusLabel, '缺失');
  assert.equal(issueRows[0].severityLabel, '高优先级');
  assert.match(issueRows[0].reasonText, /目标经营日期未命中可用入库数据/);
  assert.equal(issueRows[1].channelLabel, '美团');
  assert.equal(issueRows[1].statusLabel, '未授权');
  assert.equal(issueRows[1].nextAction, '重新登录美团。');
});

test('Revenue AI status rows preserve OTA and whole-hotel scope boundaries', () => {
  const rows = helpers.buildRevenueAiStatusRows({
    readiness: { percent: 60, summaryText: '3/5', missingText: '缺竞对价格' },
    overview: {
      scope: 'ota',
      date_basis: 'data_date',
      date_basis_note: 'data_date 不等于入住日。',
      data_status: 'partial',
      last_success_at: '2026-06-25 08:10:00',
      data_completeness: { display: '60%', status: 'partial', reason: 'data_not_complete' },
      channel_statuses: {
        ctrip: { label: '已同步', status: 'ok' },
        meituan: { label: '--', status: 'unauthorized', reason: 'AUTH_EXPIRED' },
      },
    },
    hotelName: '测试门店',
    hasHotelFilter: true,
    businessDate: '2026-06-25',
  });
  const scope = rows.find((row) => row.key === 'scope');
  const businessDate = rows.find((row) => row.key === 'business-date');
  const meituan = rows.find((row) => row.key === 'meituan');
  assert.equal(scope.value, 'OTA渠道口径');
  assert.equal(scope.status, '非全酒店');
  assert.match(scope.detail, /不包装成全酒店经营结论/);
  assert.equal(businessDate.status, 'data_date');
  assert.match(businessDate.detail, /不等于入住日/);
  assert.equal(meituan.status, '未授权');
  assert.match(meituan.detail, /登录或授权已失效/);
});

test('Revenue AI signal rows display competitor price position without pricing advice', () => {
  const rows = helpers.buildRevenueAiSignalRows({
    overview: {
      signals: {
        holiday_event: {
          label: '事件/节假日影响',
          value: '测试节日 T-5',
          status: 'warning',
          reason: 'holiday_event_nearby',
        },
        demand_7d: {
          label: '未来7天需求信号',
          value: '高需求 1天',
          status: 'warning',
          reason: 'demand_forecasts_high_demand',
        },
        competitor_price_warning: {
          label: '竞对价格倒挂预警',
          value: '本店高于竞对 ¥20.00',
          status: 'warning',
          reason: 'competitor_price_above_competitor',
          detail: '本店均价高于竞对均价，需人工复核。',
        },
        pricing_advice: {
          label: '今日调价建议',
          value: '--',
          status: 'blocked',
          reason: 'phase1a_readonly_no_pricing_model',
        },
      },
    },
  });

  const competitor = rows.find((row) => row.key === 'competitor_price_warning');
  const holiday = rows.find((row) => row.key === 'holiday_event');
  const demand = rows.find((row) => row.key === 'demand_7d');
  const pricing = rows.find((row) => row.key === 'pricing_advice');
  assert.equal(holiday.value, '测试节日 T-5');
  assert.match(holiday.reasonText, /节假日窗口/);
  assert.equal(demand.value, '高需求 1天');
  assert.match(demand.reasonText, /高需求/);
  assert.equal(competitor.value, '本店高于竞对 ¥20.00');
  assert.equal(competitor.statusLabel, '需复核');
  assert.match(competitor.reasonText, /人工复核/);
  assert.equal(pricing.value, '--');
  assert.match(pricing.reasonText, /未生成调价建议/);
});

test('Revenue AI readonly actions never fabricate pricing recommendations', () => {
  const unloaded = helpers.buildRevenueAiActionRows();
  assert.equal(unloaded.length, 1);
  assert.equal(unloaded[0].title, '暂无可审核调价建议');
  assert.match(unloaded[0].reasonText, /总览接口尚未返回/);

  const loadedWithoutActions = helpers.buildRevenueAiActionRows({
    overview: { actions: [] },
  });
  assert.equal(loadedWithoutActions.length, 1);
  assert.equal(loadedWithoutActions[0].title, '暂无可审核调价建议');
  assert.match(loadedWithoutActions[0].reasonText, /只读总览，未生成调价建议/);

  const blocked = helpers.buildRevenueAiActionRows({
    overview: {
      actions: [{
        key: 'pricing_review',
        title: '暂无可审核调价建议',
        status: 'blocked',
        reason: 'floor_price_missing',
        detail: '暂不生成调价建议：最低保护价未满足。',
        next_actions: ['补齐房型/价格计划级最低保护价后再允许生成可审核调价建议。'],
        decision_basis_summary: {
          status: 'blocked',
          display: '判断依据 可用 3 / 待补 5',
          ready_count: 3,
          blocked_count: 5,
          items: [
            { key: 'ota_metrics', label: '昨日 OTA 收入和间夜', status: 'ok', display_reason: '已命中 OTA 指标。' },
            { key: 'operation_feedback_input', label: '上一轮调价效果输入', status: 'blocked', severity: 'medium', reason: 'operation_roi_missing', display_reason: '缺少 ROI 证据。', target_page: 'ops-track', target_platform: 'hotel' },
            { key: 'floor_price', label: '最低保护价', status: 'blocked', severity: 'high', reason: 'floor_price_missing', display_reason: '暂缺最低保护价。', next_action: '补齐最低保护价。', target_page: 'online-data', target_tab: 'data-health', target_platform: 'hotel' },
            { key: 'demand_signal_7d', label: '未来 7 天需求信号', status: 'blocked', severity: 'medium', reason: 'demand_forecasts_not_loaded', display_reason: '未来 7 天需求预测尚未读取。' },
            { key: 'manual_review_workflow', label: '人工审核工作流', status: 'blocked', severity: 'high', reason: 'manual_review_workflow_not_connected', display_reason: '暂未接入人工审核工作流。' },
            { key: 'revpar_denominator', label: '全酒店可售房晚', status: 'blocked', severity: 'medium', reason: 'available_room_nights_missing', display_reason: '暂缺可信全酒店可售房晚数据。' },
          ],
        },
        manual_review_required: true,
        auto_write_ota: false,
        readiness: {
          can_generate_recommendation: false,
          can_auto_write_ota: false,
        },
      }],
    },
  });
  assert.equal(blocked[0].statusLabel, '待补数据');
  assert.match(blocked[0].reasonText, /暂不生成调价建议/);
  assert.deepEqual(blocked[0].nextActions, ['补齐房型/价格计划级最低保护价后再允许生成可审核调价建议。']);
  assert.equal(blocked[0].autoWriteOta, false);
  assert.equal(blocked[0].manualReviewRequired, true);
  assert.equal(blocked[0].decisionBasisDisplay, '判断依据 可用 3 / 待补 5');
  assert.equal(blocked[0].decisionBasisStatusLabel, '待补数据');
  assert.equal(blocked[0].decisionBasisReadyCount, 3);
  assert.equal(blocked[0].decisionBasisBlockedCount, 5);
  assert.equal(blocked[0].decisionBasisHiddenBlockedCount, 1);
  assert.equal(blocked[0].decisionBasisHiddenDisplay, '另有 1 项待补未展示');
  assert.equal(blocked[0].decisionBasisItems.length, 4);
  assert.equal(blocked[0].decisionBasisItems[0].label, '最低保护价');
  assert.equal(blocked[0].decisionBasisItems[0].nextAction, '补齐最低保护价。');
  assert.equal(blocked[0].decisionBasisItems[0].targetPage, 'online-data');
  assert.equal(blocked[0].decisionBasisItems[0].targetTab, 'data-health');
  assert.equal(blocked[0].decisionBasisItems[0].targetPlatform, 'hotel');
  assert.equal(blocked[0].decisionBasisItems[0].canOpenTarget, true);
  assert.equal(blocked[0].decisionBasisItems[1].label, '人工审核工作流');
  assert.equal(blocked[0].decisionBasisItems[2].label, '上一轮调价效果输入');
});

test('Revenue AI action rows expose readonly price suggestion review queue', () => {
  const rows = helpers.buildRevenueAiActionRows({
    overview: {
      review_queue: {
        status: 'pending_review',
        display: '待审核 2 / 已批准 1 / 已拒绝 0 / 已应用 0',
        pending_count: 2,
        pending_items: [{
          id: 11,
          room_type_id: 3,
          suggestion_type_label: '竞对跟价',
          status: 'pending_review',
          status_label: '待审核',
          suggestion_date: '2026-06-25',
          current_price: 280,
          current_price_display: '280元',
          suggested_price: 318,
          suggested_price_display: '318元',
          min_price: 220,
          min_price_display: '220元',
          confidence_display: '82%',
          price_change_display: '+38元',
          expected_revpar_impact_display: '+12.5元',
          expected_revpar_impact_status: 'partial',
          expected_revpar_impact_reason: '',
          competitor_summary: '竞对均价 330元',
          factors_summary: '高需求 / 周末',
          reason: '竞对价格偏高，需人工复核。',
          manual_review_required: true,
          auto_write_ota: false,
          can_review: true,
          action_entry: {
            label: '去审核',
            target_page: 'compass',
            target_agent_tab: '',
            target_revenue_tab: '',
            target_filter: { hotel_id: 7, date: '2026-06-25', status: 1, suggestion_id: 11 },
            requires_super_admin: false,
            requires_hotel_permission: true,
            homepage_read_only: true,
            allowed_endpoint: '/api/revenue-ai/price-suggestions/11/review',
            allowed_endpoints: {
              review: '/api/revenue-ai/price-suggestions/11/review',
              execution_intent: '/api/revenue-ai/price-suggestions/11/execution-intent',
            },
            manual_actions: ['approve', 'approve_with_changes', 'reject'],
            forbidden_actions: ['apply_price', 'ota_write'],
          },
        }],
        recent_items: [{
          id: 11,
          status: 'pending_review',
        }, {
          id: 12,
          room_type_id: 4,
          suggestion_type_label: '动态定价',
          status: 'approved',
          status_label: '已批准',
          suggestion_date: '2026-06-25',
          current_price_display: '300元',
          suggested_price_display: '336元',
          min_price_display: '260元',
          manual_review_required: true,
          auto_write_ota: false,
          can_review: false,
          action_entry: {
            label: '去转单',
            target_page: 'compass',
            target_filter: { hotel_id: 7, date: '2026-06-25', status: 2, suggestion_id: 12 },
            requires_super_admin: false,
            requires_hotel_permission: true,
            homepage_read_only: true,
            allowed_endpoint: '/api/revenue-ai/price-suggestions/12/execution-intent',
            allowed_endpoints: {
              review: '/api/revenue-ai/price-suggestions/12/review',
              execution_intent: '/api/revenue-ai/price-suggestions/12/execution-intent',
            },
            manual_actions: ['create_execution_intent'],
            forbidden_actions: ['apply_price', 'ota_write'],
          },
        }],
      },
      actions: [{
        key: 'pricing_review',
        title: '待人工审核调价建议',
        status: 'pending_review',
        reason: 'price_suggestions_pending_review',
        detail: '已有 2 条来自 price_suggestions 的待人工审核调价建议；可在首页批准、修改后批准或拒绝，但不写 OTA。',
        review_queue_summary: '待审核 2 / 已批准 1 / 已拒绝 0 / 已应用 0',
        review_queue: {
          status: 'pending_review',
          display: '待审核 2 / 已批准 1 / 已拒绝 0 / 已应用 0',
          pending_count: 2,
          pending_items: [{
            id: 11,
            room_type_id: 3,
            suggestion_type_label: '竞对跟价',
            status: 'pending_review',
            status_label: '待审核',
            suggestion_date: '2026-06-25',
            current_price: 280,
            current_price_display: '280元',
            suggested_price: 318,
            suggested_price_display: '318元',
            min_price: 220,
            min_price_display: '220元',
            confidence_display: '82%',
            price_change_display: '+38元',
            expected_revpar_impact_display: '+12.5元',
            expected_revpar_impact_status: 'partial',
            expected_revpar_impact_reason: '',
            competitor_summary: '竞对均价 330元',
            factors_summary: '高需求 / 周末',
            reason: '竞对价格偏高，需人工复核。',
            manual_review_required: true,
            auto_write_ota: false,
            can_review: true,
            action_entry: {
              label: '去审核',
              target_page: 'compass',
              target_agent_tab: '',
              target_revenue_tab: '',
              target_filter: { hotel_id: 7, date: '2026-06-25', status: 1, suggestion_id: 11 },
              requires_super_admin: false,
              requires_hotel_permission: true,
              homepage_read_only: true,
              allowed_endpoint: '/api/revenue-ai/price-suggestions/11/review',
              allowed_endpoints: {
                review: '/api/revenue-ai/price-suggestions/11/review',
                execution_intent: '/api/revenue-ai/price-suggestions/11/execution-intent',
              },
              manual_actions: ['approve', 'approve_with_changes', 'reject'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
          recent_items: [{
            id: 11,
            status: 'pending_review',
          }, {
            id: 12,
            room_type_id: 4,
            suggestion_type_label: '动态定价',
            status: 'approved',
            status_label: '已批准',
            suggestion_date: '2026-06-25',
            current_price_display: '300元',
            suggested_price_display: '336元',
            min_price_display: '260元',
            manual_review_required: true,
            auto_write_ota: false,
            can_review: false,
            action_entry: {
              label: '去转单',
              target_page: 'compass',
              target_filter: { hotel_id: 7, date: '2026-06-25', status: 2, suggestion_id: 12 },
              requires_super_admin: false,
              requires_hotel_permission: true,
              homepage_read_only: true,
              allowed_endpoint: '/api/revenue-ai/price-suggestions/12/execution-intent',
              allowed_endpoints: {
                review: '/api/revenue-ai/price-suggestions/12/review',
                execution_intent: '/api/revenue-ai/price-suggestions/12/execution-intent',
              },
              manual_actions: ['create_execution_intent'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
        },
        next_actions: ['进入定价建议列表完成人工批准、修改后批准、拒绝或转执行；Revenue AI 首页不自动写 OTA。'],
        decision_basis_summary: {
          status: 'blocked',
          display: '判断依据 可用 5 / 待补 3',
          ready_count: 5,
          blocked_count: 3,
          items: [
            { key: 'operation_feedback_input', label: '上一轮调价效果输入', status: 'ok', display_reason: '已具备 ROI/增量收入证据。', target_page: 'ops-track', target_platform: 'hotel' },
          ],
        },
        manual_review_required: true,
        auto_write_ota: false,
      }],
    },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].title, '待人工审核调价建议');
  assert.equal(rows[0].statusLabel, '待人工审核');
  assert.match(rows[0].reasonText, /修改后批准/);
  assert.equal(rows[0].reviewQueueSummary, '待审核 2 / 已批准 1 / 已拒绝 0 / 已应用 0');
  assert.equal(rows[0].reviewQueueStatusLabel, '待人工审核');
  assert.equal(rows[0].decisionBasisDisplay, '判断依据 可用 5 / 待补 3');
  assert.equal(rows[0].decisionBasisItems[0].label, '上一轮调价效果输入');
  assert.equal(rows[0].decisionBasisItems[0].targetPage, 'ops-track');
  assert.equal(rows[0].pendingReviewCount, 2);
  assert.equal(rows[0].approvedExecutionPendingCount, 1);
  assert.equal(rows[0].executionPendingDisplay, '已批准待转执行 1');
  assert.match(rows[0].executionPendingReasonText, /人工执行和复盘证据/);
  assert.equal(rows[0].reviewQueueItems.length, 2);
  assert.equal(rows[0].reviewQueueItems[0].title, '房型 #3 · 竞对跟价');
  assert.equal(rows[0].reviewQueueItems[0].priceLine, '当前 280元 / 建议 318元 / 最低保护 220元');
  assert.match(rows[0].reviewQueueItems[0].evidenceLine, /可信度 82%/);
  assert.equal(rows[0].reviewQueueItems[0].impactLine, '预计RevPAR影响 +12.5元');
  assert.equal(rows[0].reviewQueueItems[0].revparImpactDisplay, '+12.5元');
  assert.equal(rows[0].reviewQueueItems[0].revparImpactStatus, 'partial');
  assert.equal(rows[0].reviewQueueItems[0].factorLine, '高需求 / 周末');
  assert.equal(rows[0].reviewQueueItems[0].autoWriteOta, false);
  assert.equal(rows[0].reviewQueueItems[0].manualReviewRequired, true);
  assert.equal(rows[0].reviewQueueItems[0].canReview, true);
  assert.equal(rows[0].reviewQueueItems[0].actionLabel, '审核');
  assert.equal(rows[0].reviewQueueItems[0].canApprove, true);
  assert.equal(rows[0].reviewQueueItems[0].canApproveWithChanges, true);
  assert.equal(rows[0].reviewQueueItems[0].canReject, true);
  assert.equal(rows[0].reviewQueueItems[0].canCreateExecutionIntent, false);
  assert.equal(rows[0].reviewQueueItems[0].actionHelpText, '首页人工审核，可修改后批准；不写 OTA');
  assert.equal(rows[0].reviewQueueItems[0].suggestedPrice, 318);
  assert.equal(rows[0].reviewQueueItems[0].minPrice, 220);
  assert.equal(rows[0].reviewQueueItems[0].requiresSuperAdmin, false);
  assert.equal(rows[0].reviewQueueItems[0].requiresHotelPermission, true);
  assert.equal(rows[0].reviewQueueItems[0].allowedEndpoint, '/api/revenue-ai/price-suggestions/11/review');
  assert.equal(rows[0].reviewQueueItems[0].allowedEndpoints.execution_intent, '/api/revenue-ai/price-suggestions/11/execution-intent');
  assert.deepEqual(rows[0].reviewQueueItems[0].actionEntry.forbidden_actions, ['apply_price', 'ota_write']);
  assert.equal(rows[0].reviewQueueItems[1].title, '房型 #4 · 动态定价');
  assert.equal(rows[0].reviewQueueItems[1].actionLabel, '转执行');
  assert.equal(rows[0].reviewQueueItems[1].canApprove, false);
  assert.equal(rows[0].reviewQueueItems[1].canReject, false);
  assert.equal(rows[0].reviewQueueItems[1].canCreateExecutionIntent, true);
  assert.equal(rows[0].reviewQueueItems[1].allowedEndpoint, '/api/revenue-ai/price-suggestions/12/execution-intent');
  assert.match(rows[0].reviewQueueItems[1].impactLine, /暂缺可信预计 RevPAR 影响数据/);
  assert.equal(rows[0].autoWriteOta, false);
});

test('Revenue AI pricing gate rows expose blockers without suggestions', () => {
  const unloaded = helpers.buildRevenueAiPricingGateRows();
  assert.equal(unloaded.length, 1);
  assert.equal(unloaded[0].statusLabel, '未接入');
  assert.match(unloaded[0].reasonText, /总览接口尚未返回/);

  const rows = helpers.buildRevenueAiPricingGateRows({
    overview: {
      pricing_readiness: {
        overall_status: 'blocked',
        can_generate_recommendation: false,
        can_auto_write_ota: false,
        gates: [
          { key: 'ota_metrics', label: '昨日 OTA 收入和间夜', status: 'ok', reason: '', detail: '已命中 OTA 指标。' },
          { key: 'floor_price', label: '最低保护价', status: 'blocked', reason: 'floor_price_missing', display_reason: '暂缺最低保护价。', next_action: '补齐最低保护价。', category: 'pricing_guard', severity: 'high' },
          { key: 'manual_review_workflow', label: '人工审核工作流', status: 'blocked', reason: 'manual_review_workflow_not_connected', next_action: '接入审核流。' },
          { key: 'operation_feedback_input', label: '上一轮调价效果输入', status: 'ok', reason: '', detail: '已具备 1 条 ROI/增量收入证据，可作为明日人工调价判断输入。' },
        ],
      },
    },
  });

  const otaMetrics = rows.find((row) => row.key === 'ota_metrics');
  const floorPrice = rows.find((row) => row.key === 'floor_price');
  const review = rows.find((row) => row.key === 'manual_review_workflow');
  const operationFeedback = rows.find((row) => row.key === 'operation_feedback_input');
  assert.equal(otaMetrics.statusLabel, '正常');
  assert.match(otaMetrics.reasonText, /已命中/);
  assert.equal(floorPrice.statusLabel, '待补数据');
  assert.equal(floorPrice.reasonText, '暂缺最低保护价。');
  assert.equal(floorPrice.nextAction, '补齐最低保护价。');
  assert.equal(floorPrice.category, 'pricing_guard');
  assert.equal(review.statusLabel, '待补数据');
  assert.match(review.reasonText, /人工审核工作流/);
  assert.equal(review.nextAction, '接入审核流。');
  assert.equal(operationFeedback.statusLabel, '正常');
  assert.match(operationFeedback.reasonText, /明日人工调价判断输入/);
});

test('Revenue AI agent activity helpers expose readonly logs without success fallback', () => {
  const unloadedSummary = helpers.buildRevenueAiAgentActivitySummary();
  assert.equal(unloadedSummary.statusLabel, '未接入');
  assert.equal(unloadedSummary.display, '--');
  assert.match(unloadedSummary.reasonText, /总览接口尚未返回/);

  const emptyRows = helpers.buildRevenueAiAgentActivityRows({
    overview: {
      agent_activity: {
        status: 'empty',
        reason: 'agent_logs_empty',
        business_date: '2026-06-25',
        recent_logs: [],
      },
    },
  });
  assert.equal(emptyRows.length, 1);
  assert.equal(emptyRows[0].statusLabel, '无数据');
  assert.match(emptyRows[0].message, /暂无收益管理 Agent 操作日志/);

  const summary = helpers.buildRevenueAiAgentActivitySummary({
    overview: {
      agent_activity: {
        status: 'failed',
        reason: 'agent_logs_error_present',
        agent_type_label: '收益管理Agent',
        display: '日志 2 / 错误 1 / 警告 0',
        total_count: 2,
        error_count: 1,
        warning_count: 0,
        date_basis: 'create_time',
        read_only: true,
        next_action: '先处理错误日志。',
      },
    },
  });
  assert.equal(summary.label, '收益管理Agent');
  assert.equal(summary.statusLabel, '失败');
  assert.equal(summary.display, '日志 2 / 错误 1 / 警告 0');
  assert.equal(summary.errorCount, 1);
  assert.equal(summary.dateBasisLabel, 'create_time');
  assert.equal(summary.readOnly, true);

  const rows = helpers.buildRevenueAiAgentActivityRows({
    overview: {
      agent_activity: {
        recent_logs: [{
          id: 51,
          action: 'pricing_failed',
          message: '最低保护价缺失',
          create_time: '2026-06-25 11:00:00',
          status: 'failed',
          level_label: '错误',
        }],
      },
    },
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].action, 'pricing_failed');
  assert.equal(rows[0].message, '最低保护价缺失');
  assert.equal(rows[0].statusLabel, '错误');
  assert.match(rows[0].className, /slate|red|amber/);
});

test('Revenue AI execution helpers keep process and effect review separate', () => {
  const unloaded = helpers.buildRevenueAiExecutionSummary();
  assert.equal(unloaded.statusLabel, '未接入');
  assert.equal(unloaded.display, '--');
  assert.equal(unloaded.readOnly, true);
  assert.equal(unloaded.autoWriteOta, false);
  assert.match(unloaded.reasonText, /总览接口尚未返回/);

  const summary = helpers.buildRevenueAiExecutionSummary({
    overview: {
      execution_summary: {
        status: 'review_needed',
        reason: 'operation_execution_review_needed',
        display: '执行单 1 / 已执行 1 / 证据 1 / 待复盘 1',
        total_count: 1,
        approved_count: 1,
        executed_count: 1,
        evidence_ready_count: 1,
        review_needed_count: 1,
        reviewed_count: 0,
        roi_ready_count: 0,
        date_basis: 'operation_execution_intents.date_start/date_end',
        read_only: true,
        auto_write_ota: false,
        process: {
          status: 'review_needed',
          reason: 'operation_execution_review_needed',
          display: '执行单 1 / 已执行 1 / 证据 1',
        },
        effect_review: {
          status: 'review_needed',
          reason: 'operation_effect_review_pending',
          display: '复盘 0 / ROI 0',
          input_display: '明日输入 可用 0 / 待补 1 / 缺失 0',
          input_ready_count: 0,
          input_partial_count: 1,
          input_missing_count: 0,
          next_day_input_ready: false,
        },
        next_action: '进入运营执行页触发效果复盘。',
      },
    },
  });
  assert.equal(summary.statusLabel, '待复盘');
  assert.equal(summary.processStatusLabel, '待复盘');
  assert.equal(summary.effectReviewStatusLabel, '待复盘');
  assert.equal(summary.effectReviewDisplay, '复盘 0 / ROI 0');
  assert.equal(summary.effectReviewInputDisplay, '明日输入 可用 0 / 待补 1 / 缺失 0');
  assert.equal(summary.effectReviewInputReadyCount, 0);
  assert.equal(summary.effectReviewInputPartialCount, 1);
  assert.equal(summary.effectReviewInputMissingCount, 0);
  assert.equal(summary.nextDayInputReady, false);
  assert.equal(summary.dateBasisLabel, '执行意图日期');
  assert.equal(summary.autoWriteOta, false);

  const rows = helpers.buildRevenueAiExecutionRows({
    overview: {
      execution_summary: {
        business_date: '2026-06-25',
        recent_items: [{
          id: 71,
          intent_id: 71,
          hotel_id: 7,
          task_id: 91,
          stage: 'review',
          stage_label: '效果复盘',
          platform: 'ctrip',
          platform_label: '携程',
          action_type: 'price_adjust',
          date_start: '2026-06-25',
          date_end: '2026-06-25',
          approval_status: 'approved',
          execution_status: 'executed',
          evidence_count: 1,
          next_action: { key: 'review_effect', label: '触发效果复盘', target_id: 91 },
          next_action_label: '触发效果复盘',
          target_page: 'ops-track',
          target_action: 'review_effect',
          target_id: 91,
          target_kind: 'task',
        }],
      },
    },
  });
  assert.equal(rows.length, 1);
  assert.equal(rows[0].title, '携程 · price_adjust');
  assert.equal(rows[0].stageLabel, '效果复盘');
  assert.match(rows[0].detail, /证据 1/);
  assert.equal(rows[0].meta, '2026-06-25');
  assert.equal(rows[0].nextAction, '触发效果复盘');
  assert.equal(rows[0].actionLabel, '触发效果复盘');
  assert.equal(rows[0].nextActionKey, 'review_effect');
  assert.equal(rows[0].targetPage, 'ops-track');
  assert.equal(rows[0].targetId, 91);
  assert.equal(rows[0].targetKind, 'task');
  assert.equal(rows[0].intentId, 71);
  assert.equal(rows[0].taskId, 91);
  assert.equal(rows[0].hotelId, 7);
  assert.equal(rows[0].canOpenExecution, true);
});

test('Revenue AI execution helpers expose empty and request-failed states without fake closure', () => {
  const emptyRows = helpers.buildRevenueAiExecutionRows({
    overview: {
      execution_summary: {
        status: 'empty',
        reason: 'operation_execution_empty',
        business_date: '2026-06-25',
        next_action: '暂无目标日期调价执行记录。',
        recent_items: [],
      },
    },
  });
  assert.equal(emptyRows.length, 1);
  assert.equal(emptyRows[0].stageLabel, '无数据');
  assert.match(emptyRows[0].detail, /暂无调价执行记录/);
  assert.equal(emptyRows[0].targetPage, 'ops-track');
  assert.equal(emptyRows[0].canOpenExecution, true);
  assert.equal(emptyRows[0].actionLabel, '查看运营执行');

  const failedSummary = helpers.buildRevenueAiExecutionSummary({ overviewError: '接口返回500' });
  assert.equal(failedSummary.statusLabel, '失败');
  assert.equal(failedSummary.reasonText, '接口返回500');
  assert.equal(failedSummary.autoWriteOta, false);

  const failedRows = helpers.buildRevenueAiExecutionRows({ overviewError: '接口返回500' });
  assert.equal(failedRows.length, 1);
  assert.equal(failedRows[0].title, 'Revenue AI 总览接口');
  assert.equal(failedRows[0].stageLabel, '失败');
  assert.equal(failedRows[0].canOpenExecution, false);
});

test('Revenue AI effect review rows expose next-day inputs without fake ROI', () => {
  const rows = helpers.buildRevenueAiEffectReviewRows({
    overview: {
      hotel_id: 7,
      execution_summary: {
        business_date: '2026-06-25',
        effect_review: {
          input_status: 'ready',
          input_reason: 'operation_effect_review_ready',
          inputs: [{
            id: 71,
            intent_id: 71,
            hotel_id: 7,
            task_id: 91,
            input_status: 'ready',
            input_reason: 'operation_effect_review_ready',
            input_action_key: 'use_next_day_input',
            input_action_label: '可作明日输入',
            input_next_action: '将 ROI/增量收入证据作为明日调价判断输入。',
            platform: 'ctrip',
            platform_label: '携程',
            action_type: 'price_adjust',
            date_start: '2026-06-25',
            date_end: '2026-06-25',
            review_status: 'success',
            review_summary: 'ADR lifted after price adjustment',
            evidence_count: 2,
            roi_status: 'ready',
            roi_display: '¥180.50',
            target_page: 'ops-track',
            target_action: 'review_effect',
            target_id: 91,
            target_kind: 'task',
          }],
        },
      },
    },
  });

  assert.equal(rows.length, 1);
  assert.equal(rows[0].title, '携程 · price_adjust');
  assert.equal(rows[0].statusLabel, '可作为输入');
  assert.equal(rows[0].roiDisplay, '¥180.50');
  assert.equal(rows[0].reviewSummary, 'ADR lifted after price adjustment');
  assert.equal(rows[0].targetPage, 'ops-track');
  assert.equal(rows[0].targetId, 91);
  assert.equal(rows[0].inputActionKey, 'use_next_day_input');
  assert.equal(rows[0].nextActionKey, 'use_next_day_input');
  assert.equal(rows[0].actionLabel, '可作明日输入');
  assert.equal(rows[0].canOpenExecution, true);

  const partialRows = helpers.buildRevenueAiEffectReviewRows({
    overview: {
      hotel_id: 7,
      execution_summary: {
        business_date: '2026-06-25',
        effect_review: {
          input_status: 'partial',
          input_reason: 'operation_roi_missing',
          inputs: [{
            id: 72,
            intent_id: 72,
            hotel_id: 7,
            task_id: 92,
            input_status: 'partial',
            input_reason: 'operation_roi_missing',
            input_action_key: 'record_roi_evidence',
            input_action_label: '补录ROI证据',
            input_next_action: '补齐执行前后收入、成本或平台回执后再判断效果。',
            platform: 'meituan',
            platform_label: '美团',
            action_type: 'price_adjust',
            date_start: '2026-06-25',
            date_end: '2026-06-25',
            review_status: 'observing',
            evidence_count: 1,
            latest_evidence_type: 'manual_price_execution',
            latest_evidence_at: '2026-06-25 11:10:00',
            has_revenue_evidence: false,
            has_cost_evidence: false,
            evidence_ready_for_next_day: false,
            evidence_summary: '最新证据 manual_price_execution / 缺收入 / 有回执 / 待补ROI',
            roi_status: 'data_gap',
            roi_display: '--',
            target_page: 'ops-track',
            target_action: 'review_effect',
            target_id: 92,
            target_kind: 'task',
          }],
        },
      },
    },
  });
  assert.equal(partialRows[0].statusLabel, '部分可用');
  assert.match(partialRows[0].reasonText, /ROI/);
  assert.match(partialRows[0].detail, /manual_price_execution/);
  assert.match(partialRows[0].detail, /缺收入/);
  assert.equal(partialRows[0].roiDisplay, '--');
  assert.equal(partialRows[0].reviewSummary, '补齐执行前后收入、成本或平台回执后再判断效果。');
  assert.equal(partialRows[0].evidenceSummary, '最新证据 manual_price_execution / 缺收入 / 有回执 / 待补ROI');
  assert.equal(partialRows[0].latestEvidenceType, 'manual_price_execution');
  assert.equal(partialRows[0].hasRevenueEvidence, false);
  assert.equal(partialRows[0].evidenceReadyForNextDay, false);
  assert.equal(partialRows[0].inputActionKey, 'record_roi_evidence');
  assert.equal(partialRows[0].nextActionKey, 'record_roi_evidence');
  assert.equal(partialRows[0].inputNextAction, '补齐执行前后收入、成本或平台回执后再判断效果。');
  assert.equal(partialRows[0].actionLabel, '补录ROI证据');
  assert.equal(partialRows[0].canOpenExecution, true);

  const emptyRows = helpers.buildRevenueAiEffectReviewRows({
    overview: {
      execution_summary: {
        business_date: '2026-06-25',
        effect_review: {
          input_status: 'missing',
          input_reason: 'operation_execution_evidence_needed',
          inputs: [],
        },
      },
    },
  });
  assert.equal(emptyRows.length, 1);
  assert.equal(emptyRows[0].title, '明日调价判断输入');
  assert.equal(emptyRows[0].statusLabel, '缺失');
  assert.equal(emptyRows[0].roiDisplay, '--');

  const failedRows = helpers.buildRevenueAiEffectReviewRows({ overviewError: '接口返回500' });
  assert.equal(failedRows[0].statusLabel, '失败');
  assert.equal(failedRows[0].canOpenExecution, false);
});

test('Revenue AI execution action helpers stay pure and local-only', () => {
  const row = {
    targetKind: 'task',
    targetId: 92,
    targetAction: 'review_effect',
    raw: {
      input_reason: 'operation_roi_missing',
      roi_status: 'data_gap',
      recommendation: {
        object_type: 'room_type',
        action_type: 'price_adjust',
        platform: 'meituan',
        current_value: { price: 280 },
        target_value: { price: 318 },
      },
    },
  };

  assert.equal(helpers.revenueAiExecutionNeedsRoiEvidence(row), true);
  assert.equal(helpers.revenueAiExecutionResolvedActionKey(row), 'review_effect');
  const taskItem = helpers.revenueAiExecutionTaskActionItem(row);
  assert.equal(taskItem.execution.task_id, 92);
  assert.equal(taskItem.recommendation.object_type, 'room_type');
  assert.equal(taskItem.recommendation.action_type, 'price_adjust');
  assert.equal(taskItem.recommendation.platform, 'meituan');
  assert.equal(taskItem.recommendation.current_value.price, 280);
  assert.equal(taskItem.recommendation.target_value.price, 318);
  assert.equal(helpers.revenueAiReviewActionKey({ id: 11 }, 'approve_with_changes'), '11:approve_with_changes');
  const baseLoadingState = {};
  const loadingState = helpers.buildRevenueAiReviewActionLoadingState({
    state: baseLoadingState,
    item: { id: 11 },
    action: 'approve',
    loading: true,
  });
  assert.deepEqual(baseLoadingState, {});
  assert.equal(loadingState['11:approve'], true);
  assert.equal(helpers.isRevenueAiReviewActionLoadingState({ state: loadingState, item: { id: 11 }, action: 'approve' }), true);
  const idleState = helpers.buildRevenueAiReviewActionLoadingState({
    state: loadingState,
    item: { id: 11 },
    action: 'approve',
    loading: false,
  });
  assert.equal(idleState['11:approve'], false);
  assert.equal(helpers.normalizeRevenueAiApiPath('/api/revenue-ai/price-suggestions/11/review'), '/revenue-ai/price-suggestions/11/review');
  assert.equal(helpers.normalizeRevenueAiApiPath('/revenue-ai/price-suggestions/11/review'), '/revenue-ai/price-suggestions/11/review');
  assert.equal(helpers.normalizeRevenueAiApiPath(''), '');

  const navigation = helpers.resolveRevenueAiExecutionNavigation({ row, fallbackHotelId: 7 });
  assert.equal(navigation.targetPage, 'ops-track');
  assert.equal(navigation.hotelId, 7);
  assert.equal(navigation.taskId, 92);
  assert.equal(navigation.nextActionKey, 'review_effect');
  assert.equal(navigation.focus.taskId, 92);
  assert.equal(navigation.focus.targetId, 92);
  assert.equal(navigation.focus.targetAction, 'review_effect');
  assert.equal(navigation.focus.label, '查看运营执行');
  const roiAction = helpers.resolveRevenueAiExecutionAction({ row, fallbackHotelId: 7 });
  assert.equal(roiAction.action, 'record_roi_evidence');
  assert.equal(roiAction.reloadOverview, true);
  assert.match(roiAction.confirmText, /不写入携程\/美团价格/);
  const executionAction = helpers.resolveRevenueAiExecutionAction({
    row: { ...row, targetAction: 'record_execution_evidence', raw: { ...row.raw, input_reason: '', roi_status: '' } },
  });
  assert.equal(executionAction.action, 'record_execution_evidence');
  const reviewAction = helpers.resolveRevenueAiExecutionAction({
    row: { ...row, targetAction: 'record_effect_review', raw: { ...row.raw, input_reason: '', roi_status: '' } },
  });
  assert.equal(reviewAction.action, 'record_effect_review');
  const missingAction = helpers.resolveRevenueAiExecutionAction({ row: {} });
  assert.equal(missingAction.action, 'missing_entry');
});

test('Revenue AI manual review helpers build local-only review requests', () => {
  const item = {
    id: 11,
    suggestedPrice: 318,
    minPrice: 220,
    maxPrice: 400,
    allowedEndpoint: '/api/revenue-ai/price-suggestions/11/review',
    allowedEndpoints: {
      review: '/api/revenue-ai/price-suggestions/11/review',
      execution_intent: '/api/revenue-ai/price-suggestions/11/execution-intent',
    },
  };

  assert.equal(helpers.revenueAiReviewActionText('approve'), '批准该调价建议');
  assert.equal(helpers.revenueAiReviewActionText('approve_with_changes'), '修改后批准该调价建议');
  assert.equal(helpers.revenueAiReviewActionText('reject'), '拒绝该调价建议');
  assert.equal(helpers.revenueAiReviewActionText('execution_intent'), '转为运营执行意图');
  assert.equal(helpers.revenueAiReviewActionText('apply_price'), '');
  assert.equal(helpers.revenueAiReviewEndpoint(item, 'approve'), '/revenue-ai/price-suggestions/11/review');
  assert.equal(helpers.revenueAiReviewEndpoint(item, 'execution_intent'), '/revenue-ai/price-suggestions/11/execution-intent');
  const approveDraft = helpers.resolveRevenueAiReviewActionDraft({ item, action: 'approve' });
  assert.equal(approveDraft.ok, true);
  assert.equal(approveDraft.suggestionId, 11);
  assert.equal(approveDraft.action, 'approve');
  assert.equal(approveDraft.endpoint, '/revenue-ai/price-suggestions/11/review');
  assert.equal(approveDraft.actionText, '批准该调价建议');
  assert.equal(helpers.resolveRevenueAiReviewActionDraft({ item: {}, action: 'approve' }).message, '定价建议ID缺失，无法审核');
  assert.equal(helpers.resolveRevenueAiReviewActionDraft({ item: { id: 11, autoWriteOta: true }, action: 'approve' }).message, '异常：当前建议声明会写 OTA，已阻止首页操作');
  assert.equal(helpers.resolveRevenueAiReviewActionDraft({ item: { id: 11 }, action: 'approve' }).message, '定价建议审核接口缺失，无法操作');
  assert.equal(helpers.resolveRevenueAiReviewActionDraft({ item, action: 'apply_price' }).message, '不支持的审核动作');

  assert.equal(helpers.validateRevenueAiApprovedPrice('219', item).message, '修改后批准价低于最低保护价 220');
  assert.equal(helpers.validateRevenueAiApprovedPrice('401', item).message, '修改后批准价高于最高限制价 400');
  assert.equal(helpers.validateRevenueAiApprovedPrice('abc', item).message, '修改后批准价必须是大于 0 的数字');
  const priceCheck = helpers.validateRevenueAiApprovedPrice('318.126', item);
  assert.equal(priceCheck.ok, true);
  assert.equal(priceCheck.approvedPrice, 318.13);

  assert.equal(
    helpers.buildRevenueAiReviewConfirmText({ action: 'execution_intent' }),
    '确认转为运营执行意图？该动作不会写入携程/美团价格，仍需人工执行和复盘。',
  );
  assert.equal(
    helpers.buildRevenueAiReviewConfirmText({ action: 'approve_with_changes', approvedPrice: 318.13 }),
    '确认以 318.13 元修改后批准？该动作只更新本地审核状态，不写入携程/美团价格。',
  );
  assert.equal(
    helpers.buildRevenueAiReviewConfirmText({ action: 'reject', actionText: '拒绝该调价建议' }),
    '确认拒绝该调价建议？该动作只更新本地审核状态，不写入携程/美团价格。',
  );

  const approveBody = helpers.buildRevenueAiReviewRequestBody({ action: 'approve', item });
  assert.equal(approveBody.action, 'approve');
  assert.equal(approveBody.remark, 'Revenue AI 首页人工批准；未写 OTA。');
  const rejectBody = helpers.buildRevenueAiReviewRequestBody({ action: 'reject', item });
  assert.equal(rejectBody.remark, 'Revenue AI 首页人工拒绝；未写 OTA。');
  const changedBody = helpers.buildRevenueAiReviewRequestBody({ action: 'approve_with_changes', item, approvedPrice: 318.13 });
  assert.equal(changedBody.action, 'approve_with_changes');
  assert.equal(changedBody.approved_price, 318.13);
  assert.match(changedBody.remark, /未写 OTA/);
  const intentBody = helpers.buildRevenueAiReviewRequestBody({ action: 'execution_intent', item });
  assert.equal(intentBody.source, 'revenue_ai_homepage');
  assert.equal(intentBody.expected_metric, 'orders');
});

test('Revenue AI execution intent open row helper keeps execution navigation local', () => {
  const row = helpers.buildRevenueAiExecutionIntentOpenRow({
    payload: {
      target_id: 72,
      target_page: 'ops-track',
      target_action: 'approve_intent',
      target_kind: 'intent',
      execution_intent_existing: false,
      execution_intent: { id: 72, hotel_id: 7 },
    },
    item: { hotelId: 3 },
  });
  assert.equal(row.canOpenExecution, true);
  assert.equal(row.targetPage, 'ops-track');
  assert.equal(row.targetAction, 'approve_intent');
  assert.equal(row.targetId, 72);
  assert.equal(row.targetKind, 'intent');
  assert.equal(row.intentId, 72);
  assert.equal(row.hotelId, 7);
  assert.equal(row.actionLabel, '审批执行意图');
  assert.equal(row.nextActionKey, 'approve_intent');

  const existingRow = helpers.buildRevenueAiExecutionIntentOpenRow({
    payload: {
      execution_intent_existing: true,
      execution_intent: { id: 73 },
    },
    item: { hotel_id: 9 },
  });
  assert.equal(existingRow.canOpenExecution, true);
  assert.equal(existingRow.targetPage, 'ops-track');
  assert.equal(existingRow.targetAction, 'approve_intent');
  assert.equal(existingRow.targetId, 73);
  assert.equal(existingRow.hotelId, 9);
  assert.equal(existingRow.actionLabel, '查看执行意图');
});

test('Revenue AI review navigation helper keeps target parsing outside the entry file', () => {
  const blocked = helpers.resolveRevenueAiReviewNavigation({
    isSuperAdmin: false,
    item: {
      actionEntry: {
        requires_super_admin: true,
        target_page: 'agent-center',
      },
    },
  });
  assert.equal(blocked.action, 'blocked');
  assert.match(blocked.message, /无权进入超级管理员审核页/);
  assert.equal(blocked.level, 'warning');

  const gap = helpers.resolveRevenueAiReviewNavigation({
    isSuperAdmin: true,
    item: {
      actionEntry: {
        target_page: 'online-data',
      },
    },
  });
  assert.equal(gap.action, 'gap');
  assert.equal(gap.gapTarget.target_tab, 'data-health');

  const navigation = helpers.resolveRevenueAiReviewNavigation({
    isSuperAdmin: false,
    item: {
      suggestionDate: '2026-06-25',
      actionEntry: {
        target_page: 'agent-center',
        target_agent_tab: 'revenue',
        target_revenue_tab: 'suggestions',
        target_filter: { hotel_id: 7, status: 2 },
      },
    },
  });
  assert.equal(navigation.action, 'agent-center');
  assert.equal(navigation.hotelId, '7');
  assert.equal(navigation.date, '2026-06-25');
  assert.equal(navigation.status, 2);
  assert.equal(navigation.agentTab, 'revenue');
  assert.equal(navigation.revenueAgentTab, 'suggestions');
  const state = helpers.buildRevenueAiReviewNavigationState(navigation);
  assert.equal(state.shouldOpen, true);
  assert.equal(state.hotelId, '7');
  assert.equal(state.date, '2026-06-25');
  assert.equal(state.status, 2);
  assert.equal(state.currentPage, 'agent-center');
  assert.equal(state.agentTab, 'revenue');
  assert.equal(state.revenueAgentTab, 'suggestions');
  assert.equal(helpers.buildRevenueAiReviewNavigationState(gap).shouldOpen, false);
});
