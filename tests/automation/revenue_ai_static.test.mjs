import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context, {
  filename: 'public/revenue-ai-static.js',
});
vm.runInNewContext(readFileSync('public/data-health-static.js', 'utf8'), context, {
  filename: 'public/data-health-static.js',
});

const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const dataHealthHelpers = context.window.SUXI_DATA_HEALTH_STATIC;
const indexHtml = readFileSync('public/index.html', 'utf8');
const systemStatic = readFileSync('public/system-static.js', 'utf8');
const appMain = `${readFileSync('public/components/system/app-main-components.js', 'utf8')}\n${readFileSync('public/app-main.js', 'utf8')}`;
const appTemplate = readFileSync('resources/frontend/app-template.html', 'utf8');
const aiDailyReportFragment = readFileSync('resources/frontend/templates/fragments/16-page-ai-daily-report.html', 'utf8');
const routeApp = readFileSync('route/app.php', 'utf8');
const html = `${indexHtml}\n${appTemplate}\n${appMain}`;

const createRevenueAiGapNavigationHarness = (initialFilter = {}, harnessOptions = {}) => {
  const start = appMain.indexOf('            const openRevenueAiGap =');
  const end = appMain.indexOf('            const openRevenueAiMetric =', start);
  assert.ok(start >= 0 && end > start, 'openRevenueAiGap source must remain extractable for behavior tests');
  const openRevenueAiGapSource = appMain.slice(start, end);
  const factory = vm.runInNewContext([
    '(resolveGapTarget, initialFilter, harnessOptions) => {',
    "  const revenueAiStaticReady = { value: true };",
    "  const revenueAiStaticError = { value: '' };",
    "  const revenueAiStaticNotLoadedText = 'not loaded';",
    "  const filterReportHotel = { value: harnessOptions.filterReportHotel || '999' };",
    "  const onlineDataFilter = { value: { hotel_id: '999', source: 'all', start_date: '2026-01-01', end_date: '2026-08-09', ...initialFilter } };",
    "  const autoFetchHotelId = { value: harnessOptions.autoFetchHotelId || '999' };",
    "  const coreOperationsHotelId = { value: harnessOptions.coreOperationsHotelId || '999' };",
    "  const coreOperationsTargetDate = { value: harnessOptions.coreOperationsTargetDate || '2026-08-09' };",
    "  const currentPage = { value: harnessOptions.currentPage || 'revenue-ai' };",
    "  const platformHotelId = { value: harnessOptions.platformHotelId || '999' };",
    "  const agentTab = { value: '' };",
    "  const revenueAgentTab = { value: '' };",
    '  const events = [];',
    '  const scopeSnapshot = () => ({',
    '    hotel_id: onlineDataFilter.value.hotel_id,',
    '    source: onlineDataFilter.value.source,',
    '    start_date: onlineDataFilter.value.start_date,',
    '    end_date: onlineDataFilter.value.end_date,',
    '  });',
    "  const ensureRevenueAiStaticReady = async () => true;",
    "  const showToast = () => {};",
    "  const resetCoreOperationsScopedState = () => events.push({ kind: 'reset-core-scope' });",
    '  const applyGeneralHotelToPlatformContext = async (platform, hotelId) => {',
    "    events.push({ kind: 'platform-context', platform, hotelId, scope: scopeSnapshot(), page: currentPage.value });",
    '    if (harnessOptions.platformContextResult === false) return false;',
    '    platformHotelId.value = hotelId;',
    '    return true;',
    '  };',
    '  const nextTick = async (callback) => (typeof callback === \'function\' ? callback() : undefined);',
    "  const openCtripManualTab = (tab) => events.push({ kind: 'ctrip', tab, scope: scopeSnapshot() });",
    "  const openMeituanManualTab = (tab) => events.push({ kind: 'meituan', tab, scope: scopeSnapshot() });",
    '  const openOnlineDataEntryTab = (tab, options) => {',
    "    events.push({ kind: 'online-data', tab, options, scope: scopeSnapshot() });",
    "    currentPage.value = 'online-data';",
    '  };',
    "  const loadPriceSuggestionWorkbench = async () => {};",
    "  const loadRoomTypes = async () => {};",
    "  const loadRevenueAnalysisBundle = async () => {};",
    '  const revenueAiResolveGapTarget = resolveGapTarget;',
    openRevenueAiGapSource,
    '  return {',
    '    openRevenueAiGap, events, filterReportHotel, onlineDataFilter, autoFetchHotelId,',
    '    coreOperationsHotelId, coreOperationsTargetDate, currentPage, platformHotelId,',
    '  };',
    '}',
  ].join('\n'), {}, { filename: 'revenue-ai-gap-navigation-harness.js' });
  return factory(helpers.resolveRevenueAiGapTarget, initialFilter, harnessOptions);
};

const aiDailyTaskHelpersStart = '// AI_DAILY_REPORT_TASK_HELPERS_START';
const aiDailyTaskHelpersEnd = '// AI_DAILY_REPORT_TASK_HELPERS_END';
const aiDailyTaskHelpersSource = appMain.slice(
  appMain.indexOf(aiDailyTaskHelpersStart) + aiDailyTaskHelpersStart.length,
  appMain.indexOf(aiDailyTaskHelpersEnd),
);
const aiDailyTaskHelpers = vm.runInNewContext(`(() => {
  ${aiDailyTaskHelpersSource}
  return {
    normalizeAiDailyReportGenerationTask,
    resolveAiDailyReportGenerationOutcome,
    pollAiDailyReportGenerationTask,
    aiDailyReportModelIsLimited,
  };
})()`, {}, { filename: 'ai-daily-report-task-helpers.js' });

test('Revenue AI static helper exposes the required display contract', () => {
  assert.equal(typeof helpers, 'object');
  for (const key of [
    'revenueAiStatusClass',
    'revenueAiStatusLabel',
    'revenueAiTruthStatusLabel',
    'revenueAiMetricTruthLines',
    'revenueAiMetricTruthSummary',
    'revenueAiSeverityClass',
    'buildRevenueAiOverviewQuery',
    'buildRevenueAiOverviewEndpoint',
    'resolveRevenueAiBusinessDate',
    'resolveRevenueAiOverviewRequest',
    'resolveRevenueAiOverviewResponse',
    'buildRevenueAiBusinessClosure',
    'buildRevenueAiMetricCards',
    'buildRevenueAiGapRows',
    'buildRevenueAiGapSummary',
    'resolveRevenueAiGapTarget',
    'resolveRevenueAiDecisionBasisNavigation',
    'buildRevenueAiStatusRows',
    'buildRevenueAiSignalRows',
    'buildRevenueAiReviewQueueItems',
    'buildRevenueAiResolutionPlanSummary',
    'buildRevenueAiPricingGenerationPreflightSummary',
    'buildRevenueAiPriceSuggestionGenerateResult',
    'resolveRevenueCockpitScope',
    'buildRevenueCockpitModel',
    'buildRevenueCockpitDownloadRows',
    'buildRevenueCockpitCsv',
    'buildRevenueCockpitDownloadPayload',
    'buildRevenueCockpitQuestionDraft',
    'buildRevenueCockpitOverviewEndpoint',
    'resolveRevenueCockpitOverviewResponse',
    'resolveRevenueCockpitScopeResponse',
    'loadRevenueCockpitSnapshot',
    'resolveRevenueDecisionSnapshot',
    'saveRevenueDecisionSnapshotWithReadback',
    'restoreRevenueDecisionSnapshotWithReadback',
    'createRevenueOpportunityPendingApprovalWithReadback',
    'resolveRevenueCockpitPendingApprovalSave',
    'resolveRevenueCockpitPendingApprovalReadback',
    'restoreRevenueCockpitPendingApprovalWithReadback',
    'buildRevenueAiActionRows',
    'buildRevenueAiEvidenceWorkbenchRows',
    'buildRevenueAiEvidenceWorkbenchSummary',
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
    'aiDailyReportActionSources',
    'aiDailyReportEvidenceTarget',
    'aiDailyReportActionIsInvestigationOnly',
    'aiDailyReportActionExecutionReady',
    'aiDailyReportActionBlockedText',
    'aiDailyReportActionStatusText',
    'aiDailyReportActionButtonText',
    'buildAiDailyReportBlockingRows',
    'summarizeAiDailyReportBlockingRows',
    'buildAiDailyReportEvidenceRows',
    'buildAiDailyFactGate',
    'buildRevenueAiExecutionIntentOpenRow',
    'resolveRevenueAiReviewNavigation',
    'buildRevenueAiReviewNavigationState',
  ]) {
    assert.equal(typeof helpers[key], 'function', `${key} must be exported`);
  }
  assert.equal(helpers.revenueAiStatusLabel('ok'), '正常');
  assert.equal(helpers.revenueAiStatusLabel('not_loaded'), '未加载');
  assert.equal(helpers.revenueAiStatusLabel('not_calculable'), '不可计算');
  assert.equal(helpers.revenueAiStatusLabel('unverified'), '未验证');
  assert.equal(helpers.revenueAiStatusLabel('missing'), '缺失');
  assert.equal(helpers.revenueAiStatusLabel('warning'), '需复核');
  assert.equal(helpers.revenueAiTruthStatusLabel('verified'), '已验证');
  assert.equal(helpers.revenueAiTruthStatusLabel('partial'), '部分数据');
  assert.equal(helpers.revenueAiTruthStatusLabel('unverified'), '未验证');
  assert.equal(helpers.revenueAiTruthStatusLabel('collection_failed'), '采集失败');
  assert.match(helpers.revenueAiStatusClass('missing'), /amber/);
  assert.match(helpers.revenueAiStatusClass('warning'), /amber/);
  assert.equal(helpers.revenueAiReasonText('ZERO_CONFIRMED'), '渠道明确确认目标经营日期无数据。');
  assert.match(helpers.revenueAiReasonText('metric_scope_mismatch'), /酒店、平台或业务日期/);
  assert.match(helpers.revenueAiReasonText('metric_truth_unverified'), /未完成.*验证/);
  assert.match(helpers.revenueAiReasonText('metric_truth_partial'), /部分来源事实/);
  assert.match(helpers.revenueAiReasonText('metric_truth_collection_failed'), /采集失败/);
  assert.match(helpers.revenueAiReasonText('overview_scope_mismatch'), /Revenue AI 总览/);
  assert.match(helpers.revenueAiReasonText('competitor_price_above_competitor'), /高于竞对均价/);
  assert.match(helpers.revenueAiReasonText('floor_price_missing'), /最低保护价/);
  assert.match(helpers.revenueAiReasonText('manual_review_workflow_not_connected'), /人工审核工作流/);
  assert.match(helpers.revenueAiReasonText('agent_logs_error_present'), /错误日志/);
  assert.match(helpers.revenueAiReasonText('operation_execution_review_needed'), /效果复盘/);
  assert.match(helpers.revenueAiReasonText('operation_roi_missing'), /ROI/);
  assert.match(helpers.revenueAiReasonText('demand_forecasts_high_demand'), /高需求/);
  assert.match(helpers.revenueAiReasonText('holiday_event_nearby'), /节假日窗口/);
});

test('Revenue AI entry lazy-loads the versioned helper outside the startup chain', () => {
  const helperHash = crypto.createHash('sha256')
    .update(readFileSync('public/revenue-ai-static.js'))
    .digest('hex')
    .slice(0, 10);
  assert.doesNotMatch(indexHtml, /<script[^>]+src="revenue-ai-static\.js(?:\?[^"']*)?"/);
  assert.match(appMain, /const revenueAiStaticScript = 'revenue-ai-static\.js';/);
  assert.match(appMain, new RegExp(`const revenueAiStaticVersion = '[^']*-h${helperHash}';`));
  assert.match(appMain, /if \(revenueAiStaticLoadPromise\) \{\s*return revenueAiStaticLoadPromise;\s*\}/);
  assert.match(appMain, /revenueAiStaticLoadPromise = null;[\s\S]*data-suxi-revenue-ai-static/);
  assert.match(appMain, /script\.src = `\$\{revenueAiStaticScript\}\?v=\$\{revenueAiStaticVersion\}`;/);
  assert.match(appMain, /revenueAiStaticRevision\.value \+= 1;/);
  assert.match(appMain, /buildRevenueAiGapSummary: \(\) => \(\{ status: 'not_loaded', total: null/);
  assert.match(appMain, /buildAiDailyFactGate: \(\) => \(\{[\s\S]*status: 'not_loaded'[\s\S]*configuredCount: null/);
  assert.match(appMain, /aiDailyReportActionExecutionReady: \(\) => false/);
  assert.match(appMain, /const HOME_SECONDARY_PANEL_DELAY_MS = 4200;/);
  assert.match(appMain, /homeSecondaryPanelsReady\.value = true;[\s\S]*ensureRevenueAiStaticReady\(\)[\s\S]*loadRevenueAiOverview\(\)/);
  assert.match(appMain, /if \(newPage === 'agent-center'\) \{[\s\S]*runPageLoadOnce\(newPage, 'revenue-ai-static', \(\) => ensureRevenueAiStaticReady\(\)\)/);
  assert.match(appMain, /if \(newPage === 'ai-daily-report'\) \{[\s\S]*await ensureRevenueAiStaticReady\(\);[\s\S]*return loadAiDailyReport\(\);/);
  assert.match(html, /requireRevenueAiStatic\('buildRevenueAiBusinessClosure'\)/);
  assert.match(html, /data-testid="revenue-ai-pricing-generation-preflight"/);
  assert.match(html, /data-testid="revenue-ai-trusted-decision"/);
  assert.match(html, /item\.trustedDecisionRows/);
  assert.match(html, /转运营任务/);
  assert.match(appMain, /operation_task_created/);
  assert.match(appMain, /persistedTask\.status !== 'pending_execute'/);
  assert.match(html, /data-testid="agent-pricing-generation-preflight-summary"/);
  assert.match(html, /data-testid="agent-pricing-generation-preflight-gaps"/);
  assert.match(html, /data-testid="agent-price-suggestion-generate-result"/);
  assert.match(html, /data-testid="agent-price-suggestion-skipped-items"/);
  assert.match(html, /data-testid="agent-room-type-pricing-guard"/);
  assert.match(html, /requireRevenueAiStatic\('buildRevenueAiPricingGenerationPreflightSummary'\)/);
  assert.match(html, /requireRevenueAiStatic\('buildRevenueAiPriceSuggestionGenerateResult'\)/);
  assert.doesNotMatch(html, /已生成 \$\{res\.data\?\.created_count \|\| 0\} 条建议/);
});

test('AI daily report generation uses background tasks, exact readback, and sync compatibility', () => {
  assert.match(appMain, /background: true/);
  assert.match(appMain, /`\/ai-daily-reports\/tasks\/\$\{encodeURIComponent\(taskId\)\}`/);
  assert.match(appMain, /`\/ai-daily-reports\/\$\{normalizedReportId\}`/);
  assert.match(appMain, /pollResult\.task\.resultReportId/);
  assert.match(appMain, /AI经营日报回读酒店范围不一致/);
  assert.match(appMain, /if \(!responseTaskId\) \{/);
  assert.match(appMain, /responseData\?\.report \|\| responseData/);
  assert.doesNotMatch(appMain, /aiDailyReport\.value = res\.data \|\| null;\s*showToast\('AI经营日报已生成'\)/);
  assert.match(aiDailyReportFragment, /data-testid="ai-daily-report-generation-task"/);
  assert.match(aiDailyReportFragment, /data-testid="ai-daily-report-generation-progress"/);
  assert.match(aiDailyReportFragment, /不表示 AI 已形成完整经营结论/);
});

test('AI daily explanation stays optional and separate from the rule summary', () => {
  assert.match(appMain, /const aiDailyReportAiExplanation = computed\(\(\) => String\(aiDailyReport\.value\?\.ai_explanation \|\| ''\)\.trim\(\)\);/);
  assert.match(aiDailyReportFragment, /v-if="aiDailyReportAiExplanation \|\| aiDailyReportAiInterpretation\.status"/);
  assert.match(aiDailyReportFragment, /data-testid="ai-daily-report-ai-explanation"/);
  assert.match(aiDailyReportFragment, /aiDailyReportAiInterpretation\.possible_explanations/);
  assert.match(aiDailyReportFragment, /aiDailyReportAiInterpretation\.conflicting_evidence/);
  assert.match(aiDailyReportFragment, /aiDailyReportAiInterpretation\.missing_information/);
  assert.match(aiDailyReportFragment, /aiDailyReportAiInterpretation\.boundary/);
  assert.match(aiDailyReportFragment, /\{\{ aiDailyReport\.summary \|\| '暂无摘要' \}\}/);
  assert.doesNotMatch(aiDailyReportFragment, /aiDailyReport\.summary\s*\|\|\s*aiDailyReportAiExplanation/);
});

test('AI daily report metric cards bind per-metric truth without global OTA promotion', () => {
  const truthStart = appMain.indexOf('const aiDailyReportTruthStatusLabel');
  const truthEnd = appMain.indexOf('const aiDailyReportActions', truthStart);
  assert.ok(truthStart >= 0 && truthEnd > truthStart, 'AI daily metric truth block must exist');
  const truthBlock = appMain.slice(truthStart, truthEnd);

  assert.match(truthBlock, /metric\.truth[\s\S]*metric\.truth_context/);
  assert.match(truthBlock, /aiDailyReport\.value\?\.source_refs/);
  assert.match(truthBlock, /metric_keys/);
  assert.match(truthBlock, /metric\.metric_scopes/);
  assert.match(truthBlock, /daily_reports#\\d\+/);
  assert.match(truthBlock, /online_daily_data#\\d\+/);
  assert.match(truthBlock, /readback_verified/);
  assert.doesNotMatch(truthBlock, /snapshot\?\.(?:source_trust|input_trust)|snapshot\[['"](?:source_trust|input_trust)['"]\]/);

  for (const label of ['已验证', '部分数据', '未验证', '采集失败']) {
    assert.match(truthBlock, new RegExp(label));
  }
  for (const scope of ['ota_channel', 'whole_hotel', 'mixed', 'user_input', 'derived']) {
    assert.match(truthBlock, new RegExp(scope));
  }
  assert.match(appMain, /const onlineTruthDetailText = requireDataHealthStatic\('onlineTruthDetailText'\);/);
  assert.match(truthBlock, /truthDetailText: onlineTruthDetailText\(truth\)/);
  assert.match(appMain, /const aiDailyReportMetricValue = \(metric\) => \{[\s\S]*?return '—';/);
  assert.doesNotMatch(truthBlock, /\|\|\s*0/);

  assert.match(aiDailyReportFragment, /ai-daily-report-metric-\$\{metric\.key\}-truth-status/);
  assert.match(aiDailyReportFragment, /onlineTruthStatusText\(metric\.truth\)/);
  assert.match(aiDailyReportFragment, /metric\.calculationStatusText/);
  assert.match(aiDailyReportFragment, /metric\.scopeText/);
  assert.match(aiDailyReportFragment, /metric\.resultTypeCode === 'derived'/);
  assert.match(aiDailyReportFragment, /<online-truth-summary/);
  assert.match(aiDailyReportFragment, /:truth="metric\.truth"/);
  assert.doesNotMatch(aiDailyReportFragment, /metric\.sourceRefsText|metric\.truthDetailText/);

  const metricHelpers = vm.runInNewContext(`(() => {
    const aiDailyReport = { value: null };
    const permittedHotels = { value: [{ id: 7, name: '测试酒店' }] };
    const hotels = { value: [] };
    const computed = (factory) => ({ get value() { return factory(); } });
    const aiDailyReportList = (value) => Array.isArray(value) ? value : [];
    const aiDailyReportObjectList = (value) => aiDailyReportList(value)
      .filter(item => item && typeof item === 'object' && !Array.isArray(item));
    ${truthBlock}
    return { aiDailyReport, aiDailyReportMetricTruth, aiDailyReportMetricCards };
  })()`, {
    onlineTruthDetailText: dataHealthHelpers.onlineTruthDetailText,
  }, { filename: 'ai-daily-report-metric-truth.js' });
  metricHelpers.aiDailyReport.value = {
    hotel_id: 7,
    report_date: '2026-07-18',
    source_refs: [{
      key: 'daily_reports#11',
      source: 'daily_reports',
      scope: 'whole_hotel_daily_report',
      data_date: '2026-07-18',
      validation_status: 'recorded',
      ingestion_method: 'daily_report',
      updated_at: '2026-07-19 07:30:00',
      metric_keys: ['revenue'],
    }, {
      key: 'online_daily_data#22',
      source: 'ctrip',
      platform: 'ctrip',
      scope: 'ota_channel',
      data_date: '2026-07-18',
      validation_status: 'verified',
      ingestion_method: 'browser_profile',
      snapshot_time: '2026-07-19 08:30:00',
      readback_verified: true,
      metric_keys: ['amount', 'list_exposure'],
    }, {
      key: 'manual_inputs#33',
      source: 'manual_import',
      scope: 'manual_input',
      data_date: '2026-07-18',
      validation_status: 'verified',
      ingestion_method: 'manual_import',
      metric_keys: ['orders'],
    }, {
      key: 'online_daily_data#44',
      source: 'meituan',
      platform: 'meituan',
      scope: 'ota_channel',
      data_date: '2026-07-18',
      validation_status: 'collection_failed',
      failure_reason: '采集任务失败',
      metric_keys: ['detail_exposure'],
    }],
    yesterday_result: {
      metrics: [{
        key: 'exposure', value: null, data_status: 'missing', result_layer: 'source_fact', metric_scopes: ['ota_channel'],
      }],
    },
  };

  const wholeHotel = metricHelpers.aiDailyReportMetricTruth({
    key: 'revenue', value: 1200, metric_scopes: ['whole_hotel_daily_report'], result_layer: 'source_fact',
  });
  assert.equal(wholeHotel.truth.status, 'unverified');
  assert.equal(wholeHotel.scopeCode, 'whole_hotel');
  assert.equal(wholeHotel.sources.length, 1);
  assert.equal(wholeHotel.sourceRefsText, 'daily_reports#11');

  const ota = metricHelpers.aiDailyReportMetricTruth({
    key: 'exposure', value: 800, metric_scopes: ['ota_channel'], result_layer: 'source_fact',
  });
  assert.equal(ota.truth.status, 'verified');
  assert.equal(ota.scopeCode, 'ota_channel');

  const mixed = metricHelpers.aiDailyReportMetricTruth({
    key: 'revenue', value: 1600, metric_scopes: ['whole_hotel_daily_report', 'ota_channel'], result_layer: 'source_fact',
  });
  assert.equal(mixed.truth.status, 'partial');
  assert.equal(mixed.scopeCode, 'mixed');

  const userInput = metricHelpers.aiDailyReportMetricTruth({
    key: 'orders', value: 9, metric_scopes: ['manual_input'], result_layer: 'source_fact',
  });
  assert.equal(userInput.truth.status, 'unverified');
  assert.equal(userInput.scopeCode, 'user_input');
  assert.match(userInput.truthDetailText, /未验证/);
  assert.match(userInput.truthDetailText, /入库回读证据/);

  const failed = metricHelpers.aiDailyReportMetricTruth({
    key: 'visitors',
    value: null,
    data_status: 'collection_failed',
    metric_scopes: ['ota_channel'],
    source_refs: ['online_daily_data#44'],
    result_layer: 'source_fact',
  });
  assert.equal(failed.truth.status, 'collection_failed');
  assert.equal(failed.sources.length, 1);
  assert.equal(failed.sourceRefsText, 'online_daily_data#44');
  assert.match(failed.truthDetailText, /采集任务失败/);

  const missingCard = metricHelpers.aiDailyReportMetricCards.value[0];
  assert.equal(metricHelpers.aiDailyReportMetricCards.value.length, 1);
  assert.equal(missingCard.value, null);
  assert.equal(missingCard.calculationStatus, 'missing');
  assert.equal(missingCard.truth.status, 'partial');
});

test('AI daily report task helpers keep hotel scope and terminal truthfulness', () => {
  const queued = aiDailyTaskHelpers.normalizeAiDailyReportGenerationTask({
    task_id: 'daily-7',
    hotel_id: 7,
    status: 'queued',
    stage: 'queued',
    progress_percent: 0,
    done: false,
  }, 7, 'daily-7');
  assert.equal(queued.hotelId, 7);
  assert.equal(queued.progressPercent, 0);
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome(queued).kind, 'pending');

  assert.throws(() => aiDailyTaskHelpers.normalizeAiDailyReportGenerationTask({
    task_id: 'daily-7', hotel_id: 8, status: 'queued', progress_percent: 0,
  }, 7, 'daily-7'), /酒店范围不一致/);
  assert.throws(() => aiDailyTaskHelpers.normalizeAiDailyReportGenerationTask({
    task_id: 'daily-other', hotel_id: 7, status: 'queued', progress_percent: 0,
  }, 7, 'daily-7'), /任务标识不一致/);

  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'succeeded', stage: 'completed', resultReportId: 81,
  }).kind, 'succeeded');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'succeeded', stage: 'completed_with_data_gap', resultReportId: 82,
  }).kind, 'limited');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'blocked', resultReportId: 83, errorMessage: '可信数据不足',
  }).kind, 'limited');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'partial', resultReportId: 84,
  }).kind, 'limited');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'blocked', resultReportId: null,
  }).kind, 'failed');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'failed', errorMessage: '生成器失败', resultReportId: null,
  }).message, '生成器失败');
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'future_terminal', done: true, resultReportId: 85,
  }).kind, 'failed');
  for (const status of ['blocked_by_data_quality', 'failed', 'invalid_output', 'partial']) {
    assert.equal(aiDailyTaskHelpers.aiDailyReportModelIsLimited(status), true, status);
  }
  assert.equal(aiDailyTaskHelpers.aiDailyReportModelIsLimited('ok'), false);
  assert.equal(aiDailyTaskHelpers.aiDailyReportModelIsLimited('not_requested'), false);
  assert.equal(aiDailyTaskHelpers.resolveAiDailyReportGenerationOutcome({
    status: 'succeeded', stage: 'completed', modelStatus: 'invalid_output', resultReportId: 88,
  }).kind, 'limited');
  assert.match(appMain, /数据或模型受限，规则版仅供核验/);
  assert.match(appMain, /aiDailyReportModelIsLimited\(report\.model_status\).*bg-amber-50 text-amber-700/s);
});

test('AI daily report polling stops on a terminal task without real timers', async () => {
  const responses = [{
    code: 200,
    data: {
      task_id: 'daily-7', hotel_id: 7, status: 'running', stage: 'generating', progress_percent: 60, done: false,
    },
  }, {
    code: 200,
    data: {
      task_id: 'daily-7', hotel_id: 7, status: 'blocked', stage: 'completed_with_data_gap', progress_percent: 100,
      result_report_id: 91, model_status: 'blocked_by_data_quality', done: true,
    },
  }];
  const waits = [];
  const progress = [];
  const result = await aiDailyTaskHelpers.pollAiDailyReportGenerationTask({
    taskId: 'daily-7',
    expectedHotelId: 7,
    initialTask: {
      task_id: 'daily-7', hotel_id: 7, status: 'queued', stage: 'queued', progress_percent: 0, done: false,
    },
    requestTask: async () => responses.shift(),
    wait: async (delay) => { waits.push(delay); },
    intervalMs: 25,
    maxAttempts: 5,
    onProgress: task => progress.push(task.progressPercent),
  });

  assert.equal(result.outcome.kind, 'limited');
  assert.equal(result.task.resultReportId, 91);
  assert.deepEqual(progress, [0, 60, 100]);
  assert.deepEqual(waits, [25, 25]);
  assert.equal(responses.length, 0);
});

test('AI daily report blocking helpers keep data gaps out of execution orders', () => {
  const rows = helpers.buildAiDailyReportBlockingRows({
    readinessMissing: [{
      code: 'data_gaps',
      label: 'data gaps',
      next_action: 'resolve data gaps first',
      source_ref: 'operation.execution_flow.data_gaps',
    }],
    actions: [{
      title: 'Repair field evidence',
      source_refs: ['operation.execution_flow.data_gaps'],
      can_create_execution_intent: false,
      blocked_reason: 'Data repair is checklist work, not an OTA execution order.',
    }, {
      title: 'Track execution',
      source_refs: ['operation.execution_flow.actions'],
      action_readiness: {
        stage: 'blocked',
        notice: 'Execution evidence missing.',
        next_action: 'Complete execution review.',
      },
    }],
  });

  assert.equal(rows.length, 3);
  assert.equal(rows[0].target.page, 'ops-track');
  assert.equal(rows[1].type, 'action');
  assert.match(rows[1].nextAction, /checklist work/);
  assert.equal(helpers.aiDailyReportActionButtonText({
    action_type: 'manual_review',
    can_create_execution_intent: false,
    blocked_reason: 'Fallback manual review is investigation-only until stronger evidence is selected.',
  }), '查看证据');
  assert.equal(helpers.aiDailyReportActionIsInvestigationOnly({
    action_type: 'manual_review',
    can_create_execution_intent: false,
    blocked_reason: 'Fallback manual review is investigation-only until stronger evidence is selected.',
  }), true);
  assert.equal(helpers.aiDailyReportActionStatusText({
    recommendation_type: 'investigation',
    can_create_execution_intent: false,
  }), '调查项 / 不可执行');
  assert.equal(helpers.aiDailyReportActionButtonText({ can_create_execution_intent: false }), '处理缺口');

  const summary = helpers.summarizeAiDailyReportBlockingRows(rows);
  assert.equal(summary.total, 3);
  assert.equal(summary.readinessCount, 1);
  assert.equal(summary.actionCount, 2);
  assert.equal(summary.investigationCount, 0);
  assert.equal(summary.opsCount, 3);
  assert.match(summary.gateText, /运营执行门禁 3/);

  const evidenceRows = helpers.buildAiDailyReportEvidenceRows({
    sourceRefs: [{ source_ref: 'operation.execution_flow', label: 'operation flow' }],
    dataGaps: [{ code: 'missing_table', message: 'missing table', source_ref: 'schema.ai_daily_reports' }],
    actions: rows,
  });
  assert.equal(evidenceRows.some(row => row.type === '缺口'), true);
  assert.equal(evidenceRows.some(row => row.className.includes('amber')), true);
});

test('AI daily report fallback rows are investigation items rather than execution blockers', () => {
  const action = {
    title: 'Investigate daily operating signal 1',
    action_type: 'manual_review',
    recommendation_type: 'investigation',
    is_investigation_only: true,
    execution_policy: 'forbidden',
    can_create_execution_intent: false,
    blocked_reason: 'Fallback investigation item is evidence review only and cannot create an execution intent.',
    source_refs: ['operation.full_data'],
  };
  const rows = helpers.buildAiDailyReportBlockingRows({ actions: [action] });
  const summary = helpers.summarizeAiDailyReportBlockingRows(rows);

  assert.equal(rows.length, 1);
  assert.equal(rows[0].type, 'investigation');
  assert.equal(rows[0].label, '调查项：Investigate daily operating signal 1');
  assert.equal(summary.investigationCount, 1);
  assert.equal(summary.actionCount, 0);
  assert.match(summary.detail, /调查项 1/);
  assert.match(summary.gateText, /调查项不进入执行门禁/);
  assert.equal(helpers.aiDailyReportActionButtonText(action), '查看证据');
  assert.equal(helpers.aiDailyReportActionStatusText(action), '调查项 / 不可执行');
  assert.match(helpers.aiDailyReportActionBlockedText(action), /仅用于查看证据/);
});

test('AI daily fact gate uses only applicable OTA platforms and never promotes failures to ready', () => {
  const ready = helpers.buildAiDailyFactGate({
    hotelId: 58,
    targetDate: '2026-07-09',
    collectionStatus: {
      generated_at: '2026-07-10 09:00:00',
      dataScope: 'ota_channel',
      platforms: {
        ctrip: {
          platform: 'ctrip',
          platformName: '携程',
          platformLoginStatus: 'logged_in',
          collectionStatus: 'collected',
          targetDateRows: 3,
          fieldFactsReady: 4,
          fieldFactsMissing: 0,
          fieldFactStatus: 'ready',
          sourceSummary: { configuredCount: 1 },
        },
        meituan: {
          platform: 'meituan',
          platformName: '美团',
          platformLoginStatus: 'unconfigured',
          collectionStatus: 'not_collected',
          targetDateRows: 0,
          fieldFactsReady: 0,
          fieldFactsMissing: 0,
          fieldFactStatus: 'not_loaded',
          sourceSummary: { configuredCount: 0 },
          profile: { profileExists: false },
        },
      },
    },
    profileStatus: {
      items: [
        { platform: 'ctrip', status_code: 'logged_in' },
        { platform: 'meituan', status_code: 'unconfigured' },
      ],
    },
  });

  assert.equal(ready.status, 'ready');
  assert.equal(ready.configuredCount, 1);
  assert.equal(ready.readyCount, 1);
  assert.equal(ready.platformRows.find(row => row.platform === 'meituan').applicable, false);
  assert.equal(ready.chain[0].status, 'ready');
  assert.equal(ready.chain[1].status, 'pending_validation');
  assert.match(ready.scopeText, /OTA渠道事实/);
  assert.match(ready.scopeText, /不代表全酒店/);

  const blocked = helpers.buildAiDailyFactGate({
    hotelId: 58,
    targetDate: '2026-07-09',
    collectionStatus: {
      dataScope: 'ota_channel',
      platforms: {
        ctrip: {
          platform: 'ctrip',
          collectionStatus: 'partial',
          targetDateRows: 1,
          fieldFactsReady: 1,
          fieldFactsMissing: 2,
          fieldFactStatus: 'missing',
          sourceSummary: { configuredCount: 1 },
        },
      },
    },
    profileStatus: { items: [{ platform: 'ctrip', status_code: 'logged_in' }] },
  });
  assert.equal(blocked.status, 'blocked');
  assert.equal(blocked.fieldGapCount, 2);
  assert.equal(blocked.chain[0].status, 'blocked');
  assert.equal(blocked.chain[1].status, 'blocked_upstream');

  const unverified = helpers.buildAiDailyFactGate({
    hotelId: 58,
    targetDate: '2026-07-09',
    errors: ['collection_status_failed'],
  });
  assert.equal(unverified.status, 'unverified');
  assert.equal(unverified.readyCount, 0);
  assert.match(unverified.statusText, /未核验/);
});

test('AI daily report page exposes the read-only OTA fact gate and source endpoints', () => {
  assert.match(html, /data-testid="ai-daily-fact-gate"/);
  assert.match(html, /\/online-data\/collection-status\?/);
  assert.match(html, /\/online-data\/platform-profile-status\?/);
  assert.match(html, /只读取现有状态，不触发OTA采集、不写业务数据/);
  assert.match(html, /const loadAiDailyFactGate = async \(options = \{\}\) => \{[\s\S]*?const requestSeq = \+\+aiDailyFactGateRequestSeq;[\s\S]*?if \(hotelId === null \|\| !hotelId\) \{[\s\S]*?aiDailyFactGateLoading\.value = false;/);
});

test('Agent pricing suggestion workbench exposes manual room type pricing guard input', () => {
  assert.match(html, /\/agent\/room-types\?\$\{params\}/);
  assert.match(html, /request\('\/agent\/room-types'/);
  assert.match(html, /const loadPriceSuggestionWorkbench = async \(\) => \{/);
  assert.match(html, /const loadPriceSuggestionWorkbench = async \(\) => \{\s*return loadRevenueAnalysisBundle\(\);\s*\}/s);
  assert.match(html, /request\(`\/agent\/revenue-bundle\?\$\{params\}`\)/);
  assert.match(html, /人工配置项，仅用于携程调价预检；不是 OTA 自动采集事实，不写 OTA。/);
});

test('Agent pricing suggestion workbench exposes manual Ctrip demand and competitor inputs', () => {
  assert.match(html, /data-testid="agent-pricing-generation-preflight-summary"/);
  assert.match(html, /agentPricingGenerationPreflightSummary/);
  assert.match(html, /revenueAiBuildPricingGenerationPreflightSummary\(\{\s*overview: revenueAiOverview\.value,\s*\}\)/s);
  assert.match(html, /agentPricingGenerationPreflightSummary\.autoWriteOta/);
  assert.match(html, /agentPricingGenerationPreflightSummary\.candidateSkipReasons/);
  assert.match(html, /agentPricingGenerationPreflightSummary\.candidateDataGaps/);
  assert.match(html, /data-testid="agent-price-suggestion-ctrip-preflight-inputs"/);
  assert.match(html, /data-testid="agent-suggestion-demand-forecast-manual-input"/);
  assert.match(html, /data-testid="agent-suggestion-ctrip-competitor-price-manual-input"/);
  assert.match(html, /data-testid="agent-demand-forecast-manual-input"/);
  assert.match(html, /data-testid="agent-ctrip-competitor-price-manual-input"/);
  assert.match(html, /manualCtripPricingInputMeta/);
  assert.match(html, /source_scope: 'ctrip_ota_channel'/);
  assert.match(html, /target_workflow: 'ctrip_revenue_ai_pricing_generation'/);
  assert.match(html, /evidence_status: 'operator_provided'/);
  assert.match(html, /auto_write_ota: false/);
  assert.match(html, /request\('\/agent\/demand-forecasts'/);
  assert.match(html, /input_type: 'manual_demand_forecast'/);
  assert.match(html, /request\('\/agent\/competitor-analysis'/);
  assert.match(html, /ota_platform: 1/);
  assert.match(html, /input_type: 'manual_ctrip_competitor_price_sample'/);
  assert.match(html, /handlePriceSuggestionDateChange/);
  assert.match(html, /priceSuggestionFilter\.end_date/);
  assert.match(html, /price_start_date/);
  assert.match(html, /price_end_date/);
  assert.match(html, /目标入住日/);
  assert.match(html, /已保存并回读/);
  assert.match(html, /changePriceSuggestionPage/);
  assert.match(html, /最多 31 天/);
  assert.match(html, /demandForecastForm\.value\.forecast_date = date/);
  assert.match(html, /competitorPriceForm\.value\.analysis_date = date/);
  assert.match(html, /syncRevenuePricingInputDate\(forecastDate\)/);
  assert.match(html, /syncRevenuePricingInputDate\(analysisDate\)/);
});

test('Agent pricing suggestion workbench uses Revenue AI manual review bridge', () => {
  assert.match(html, /request\(`\/revenue-ai\/price-suggestions\/\$\{id\}\/review`/);
  assert.match(html, /Agent 定价建议工作台人工/);
  assert.match(html, /未写 OTA/);
  assert.match(html, /request\(`\/revenue-ai\/price-suggestions\/\$\{id\}\/execution-intent`/);
  assert.match(html, /source: 'agent_pricing_suggestions'/);
  assert.match(html, /loadRevenueAiOverview\(\)/);
  assert.doesNotMatch(html, /\/agent\/price-suggestions\/\$\{id\}\/approve\?action=\$\{action\}/);
  assert.doesNotMatch(html, /\/agent\/price-suggestions\/\$\{id\}\/execution-intent`/);
});

test('Revenue AI overview endpoint builder keeps query scope explicit', () => {
  assert.equal(
    helpers.buildRevenueAiOverviewEndpoint({ businessDate: '2026-06-25', hotelId: '58' }),
    '/revenue-ai/overview?business_date=2026-06-25&hotel_id=58&platform=ctrip',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewEndpoint({ businessDate: '2026-06-25', hotelId: '' }),
    '/revenue-ai/overview?business_date=2026-06-25&platform=ctrip',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewEndpoint({ businessDate: '2026-06-25', hotelId: '', platform: '' }),
    '/revenue-ai/overview?business_date=2026-06-25',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewQuery({ businessDate: ' 2026-06-25 ', hotelId: ' 58 ' }),
    'business_date=2026-06-25&hotel_id=58&platform=ctrip',
  );
  assert.equal(
    helpers.buildRevenueAiOverviewQuery({ businessDate: '2026-06-25', platform: ' Meituan ' }),
    'business_date=2026-06-25&platform=meituan',
  );
  assert.equal(
    helpers.resolveRevenueAiBusinessDate({ overview: { business_date: '2026-06-24' }, now: new Date('2026-06-27T10:00:00') }),
    '2026-06-24',
  );
  assert.equal(
    helpers.resolveRevenueAiBusinessDate({
      selectedDate: '2026-06-27',
      now: new Date('2026-06-27T10:00:00'),
    }),
    '2026-06-27',
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
  assert.equal(request.endpoint, '/revenue-ai/overview?business_date=2026-06-25&hotel_id=58&platform=ctrip');
  assert.equal(helpers.resolveRevenueAiOverviewRequest({ hasToken: false, currentPage: 'compass' }).reason, 'token_missing');
  const agentCenterRequest = helpers.resolveRevenueAiOverviewRequest({
    hasToken: true,
    currentPage: 'agent-center',
    businessDate: '2026-06-25',
    hotelId: '58',
  });
  assert.equal(agentCenterRequest.shouldLoad, true);
  assert.equal(agentCenterRequest.endpoint, '/revenue-ai/overview?business_date=2026-06-25&hotel_id=58&platform=ctrip');
  assert.equal(helpers.resolveRevenueAiOverviewRequest({ hasToken: true, currentPage: 'online-data' }).reason, 'not_revenue_ai_surface');
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

test('Revenue AI business closure preserves OTA scope and P1 metric split', () => {
  const closure = helpers.buildRevenueAiBusinessClosure({
    overview: {
      scope: 'ota',
      data_status: 'warning',
      metric_summary: {
        credibility_gate: {
          decision_use: {
            ai_decision_support: { allowed: true, status: 'allowed_with_governance' },
            investment_decision: { allowed: false, status: 'blocked_scope' },
          },
        },
      },
      p1_revenue_closure: {
        status: 'warning',
        scope: 'ota_channel',
        scope_statement: 'P1 只使用已验证 OTA 渠道事实，不代表全酒店经营口径。',
        calculation_allowed: true,
        sections: {
          revenue: { value: 1200, unit: 'CNY', status: 'ok' },
          orders: { value: 4, unit: 'orders', status: 'ok' },
          room_nights: { value: 6, unit: 'room_nights', status: 'ok' },
          adr_conversion: {
            metrics: {
              adr: { value: 200, unit: 'CNY', status: 'ok' },
              flow_rate: { value: null, unit: '%', status: 'not_calculable', failure_reasons: ['source_rows_missing'] },
              submit_rate: { value: null, unit: '%', status: 'not_calculable', failure_reasons: ['source_rows_missing'] },
            },
          },
        },
        missing_items: {
          items: [{
            code: 'traffic.avg_flow_rate:source_rows_missing',
            message: 'source_rows_missing',
            affected_metrics: ['flow_rate'],
          }],
        },
        anomaly_judgment: {
          items: [{
            code: 'data_gaps_present',
            message: 'Revenue analysis is allowed only with the warning visible.',
            severity: 'medium',
          }],
        },
        whole_hotel_guard: { allowed: false, reason: 'whole_hotel_scope_not_proved' },
      },
      execution_summary: { status: 'not_loaded', reason: 'operation_execution_not_loaded' },
    },
  });

  assert.equal(closure.scopeText, 'OTA渠道口径');
  assert.equal(closure.calculationAllowed, true);
  assert.equal(closure.summaryChips.length, 3);
  assert.match(closure.nextAction, /异常判断|缺失项/);
  assert.equal(
    JSON.stringify(Array.from(closure.rows, (row) => row.stage)),
    JSON.stringify(['OTA数据', '收益分析', 'AI决策', '运营执行']),
  );
  const revenueRow = closure.rows[1];
  assert.equal(revenueRow.title, '收入 / 订单 / 间夜 / ADR / 转化');
  assert.equal(revenueRow.statusLabel, '部分可用');
  assert.equal(revenueRow.metrics.length, 6);
  assert.equal(revenueRow.metrics[0].value, '¥1200.00');
  assert.equal(revenueRow.metrics[1].value, '4单');
  assert.equal(revenueRow.metrics[2].value, '6.00间夜');
  assert.equal(revenueRow.metrics[3].value, '¥200.00');
  assert.equal(revenueRow.metrics[4].statusLabel, '不可计算');
  assert.equal(closure.missingRows.length, 1);
  assert.match(closure.missingRows[0].code, /traffic\.avg_flow_rate/);
  assert.equal(closure.anomalyRows.length, 1);
});

test('Revenue AI gap target resolver defaults to the existing data health entry', () => {
  const defaultTarget = helpers.resolveRevenueAiGapTarget({});
  assert.equal(defaultTarget.targetPage, 'online-data');
  assert.equal(defaultTarget.targetTab, 'data-health');
  assert.equal(defaultTarget.targetPlatform, '');
  assert.equal(defaultTarget.targetAgentTab, '');
  assert.equal(defaultTarget.targetRevenueTab, '');
  assert.equal(defaultTarget.hotelId, '');
  assert.equal(defaultTarget.businessDate, '');

  const snakeCaseTarget = helpers.resolveRevenueAiGapTarget({
    target_page: 'agent-center',
    target_tab: 'config',
    target_platform: '',
    target_agent_tab: 'revenue',
    target_revenue_tab: 'config',
    hotel_id: 80,
    business_date: '2026-08-01',
  });
  assert.equal(snakeCaseTarget.targetPage, 'agent-center');
  assert.equal(snakeCaseTarget.targetTab, 'config');
  assert.equal(snakeCaseTarget.targetPlatform, '');
  assert.equal(snakeCaseTarget.targetAgentTab, 'revenue');
  assert.equal(snakeCaseTarget.targetRevenueTab, 'config');
  assert.equal(snakeCaseTarget.hotelId, '80');
  assert.equal(snakeCaseTarget.businessDate, '2026-08-01');

  const camelCaseTarget = helpers.resolveRevenueAiGapTarget({
    targetPage: 'legacy-page',
    targetTab: 'legacy-tab',
    targetPlatform: 'meituan',
    targetAgentTab: 'overview',
    targetRevenueTab: 'analysis',
    hotelId: '81',
    businessDate: '2026-08-02',
  });
  assert.equal(camelCaseTarget.targetPage, 'legacy-page');
  assert.equal(camelCaseTarget.targetTab, 'legacy-tab');
  assert.equal(camelCaseTarget.targetPlatform, 'meituan');
  assert.equal(camelCaseTarget.targetAgentTab, 'overview');
  assert.equal(camelCaseTarget.targetRevenueTab, 'analysis');
  assert.equal(camelCaseTarget.hotelId, '81');
  assert.equal(camelCaseTarget.businessDate, '2026-08-02');
});

test('Revenue AI gap navigation aligns hotel platform and exact business date before every data jump', async () => {
  const cases = [
    { target_page: 'ctrip-ebooking', target_platform: 'ctrip', expectedEvent: 'ctrip' },
    { target_page: 'meituan-ebooking', target_platform: 'meituan', expectedEvent: 'meituan' },
    { target_page: 'online-data', target_platform: 'meituan', expectedEvent: 'online-data' },
  ];

  for (const target of cases) {
    const harness = createRevenueAiGapNavigationHarness({
      hotel_id: '998',
      source: 'ctrip',
      start_date: '2026-07-01',
      end_date: '2026-08-09',
    });
    const opened = await harness.openRevenueAiGap({
      ...target,
      target_tab: 'data-health',
      hotel_id: 80,
      business_date: '2026-08-01',
    });
    const expectedScope = {
      hotel_id: '80',
      source: target.target_platform,
      start_date: '2026-08-01',
      end_date: '2026-08-01',
    };

    assert.equal(opened, true);
    assert.deepEqual({
      hotel_id: harness.onlineDataFilter.value.hotel_id,
      source: harness.onlineDataFilter.value.source,
      start_date: harness.onlineDataFilter.value.start_date,
      end_date: harness.onlineDataFilter.value.end_date,
    }, expectedScope);
    assert.equal(harness.filterReportHotel.value, '80');
    assert.equal(harness.autoFetchHotelId.value, '80');
    assert.equal(harness.coreOperationsHotelId.value, '80');
    assert.equal(harness.coreOperationsTargetDate.value, '2026-08-01');

    const jump = harness.events.find((event) => event.kind === target.expectedEvent);
    assert.ok(jump, `${target.target_page} must execute its real navigation branch`);
    assert.deepEqual({ ...jump.scope }, expectedScope, 'scope must be exact before navigation starts');
    if (target.target_page !== 'online-data') {
      const platformContext = harness.events.find((event) => event.kind === 'platform-context');
      assert.equal(platformContext.platform, target.target_platform);
      assert.equal(platformContext.hotelId, '80');
      assert.equal(platformContext.page, 'revenue-ai');
      assert.deepEqual({ ...platformContext.scope }, {
        hotel_id: '998',
        source: 'ctrip',
        start_date: '2026-07-01',
        end_date: '2026-08-09',
      }, 'general filters must remain untouched until the platform hotel is accepted');
      assert.equal(harness.platformHotelId.value, '80');
    }
  }
});

test('Revenue AI gap navigation refuses incomplete or contradictory scope without changing the page', async () => {
  const incompleteHarness = createRevenueAiGapNavigationHarness({
    hotel_id: '999',
    source: 'all',
    start_date: '2026-07-01',
    end_date: '2026-08-09',
  });
  const incompleteOpened = await incompleteHarness.openRevenueAiGap({
    target_page: 'online-data',
    target_tab: 'data-health',
    target_platform: 'meituan',
  });

  assert.equal(incompleteOpened, false);
  assert.equal(incompleteHarness.currentPage.value, 'revenue-ai');
  assert.deepEqual({
    hotel_id: incompleteHarness.onlineDataFilter.value.hotel_id,
    source: incompleteHarness.onlineDataFilter.value.source,
    start_date: incompleteHarness.onlineDataFilter.value.start_date,
    end_date: incompleteHarness.onlineDataFilter.value.end_date,
  }, {
    hotel_id: '999',
    source: 'all',
    start_date: '2026-07-01',
    end_date: '2026-08-09',
  });
  assert.equal(incompleteHarness.events.length, 0);

  const contradictoryHarness = createRevenueAiGapNavigationHarness();
  const contradictoryOpened = await contradictoryHarness.openRevenueAiGap({
    target_page: 'ctrip-ebooking',
    target_tab: 'data-health',
    target_platform: 'meituan',
    hotel_id: 80,
    business_date: '2026-08-01',
  });
  assert.equal(contradictoryOpened, false);
  assert.equal(contradictoryHarness.currentPage.value, 'revenue-ai');
  assert.equal(contradictoryHarness.events.length, 0);
});

test('Revenue AI gap navigation rolls back general scope when platform hotel alignment fails', async () => {
  const harness = createRevenueAiGapNavigationHarness({
    hotel_id: '999',
    source: 'all',
    start_date: '2026-07-01',
    end_date: '2026-08-09',
  }, {
    currentPage: 'ctrip-ebooking',
    platformContextResult: false,
    platformHotelId: '999',
  });
  const opened = await harness.openRevenueAiGap({
    target_page: 'ctrip-ebooking',
    target_tab: 'data-health',
    target_platform: 'ctrip',
    hotel_id: 80,
    business_date: '2026-08-01',
  });

  assert.equal(opened, false);
  assert.equal(harness.currentPage.value, 'ctrip-ebooking');
  assert.equal(harness.platformHotelId.value, '999');
  assert.equal(harness.filterReportHotel.value, '999');
  assert.equal(harness.autoFetchHotelId.value, '999');
  assert.equal(harness.coreOperationsHotelId.value, '999');
  assert.equal(harness.coreOperationsTargetDate.value, '2026-08-09');
  assert.deepEqual({
    hotel_id: harness.onlineDataFilter.value.hotel_id,
    source: harness.onlineDataFilter.value.source,
    start_date: harness.onlineDataFilter.value.start_date,
    end_date: harness.onlineDataFilter.value.end_date,
  }, {
    hotel_id: '999',
    source: 'all',
    start_date: '2026-07-01',
    end_date: '2026-08-09',
  });
  assert.equal(harness.events.filter((event) => event.kind === 'platform-context').length, 1);
  assert.equal(harness.events.some((event) => ['ctrip', 'online-data'].includes(event.kind)), false);
});

test('Revenue AI decision basis navigation resolver keeps target parsing pure', () => {
  const onlineData = helpers.resolveRevenueAiDecisionBasisNavigation({
    target_page: 'online-data',
    target_tab: 'data-health',
    target_agent_tab: 'revenue',
    target_revenue_tab: 'suggestions',
    label: '最低保护价',
  });
  assert.equal(onlineData.targetPage, 'online-data');
  assert.equal(onlineData.targetTab, 'data-health');
  assert.equal(onlineData.targetAgentTab, 'revenue');
  assert.equal(onlineData.targetRevenueTab, 'suggestions');
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
  assert.equal(empty.targetAgentTab, '');
  assert.equal(empty.targetRevenueTab, '');
  assert.equal(empty.nextAction, '');
});

test('Revenue AI metric cards keep missing data explicit and scoped', () => {
  const unloadedCards = helpers.buildRevenueAiMetricCards();
  assert.equal(unloadedCards.length, 8);
  assert.ok(unloadedCards.every((card) => card.display === '--'));
  assert.ok(unloadedCards.every((card) => card.statusLabel === '未加载'));
  assert.equal(
    unloadedCards.find((card) => card.key === 'ota_room_revenue').scopeLabel,
    'OTA渠道口径',
  );
  assert.equal(
    unloadedCards.find((card) => card.key === 'whole_hotel_room_revenue').scopeLabel,
    'PMS全酒店住宿口径',
  );
  assert.equal(
    unloadedCards.find((card) => card.key === 'ota_contribution_revpar').scopeLabel,
    '跨源分层指标',
  );

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
          scope: 'ota_channel',
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
  assert.equal(revpar.scopeLabel, 'OTA渠道口径');
  assert.match(revpar.reasonText, /暂缺可信 OTA 渠道可售房晚分母/);

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

test('Revenue AI surfaces the canonical three-source facts without promoting OTA to whole-hotel revenue', () => {
  const overview = {
    scope: 'three_source_layered',
    revenue_analysis_status: 'ready',
    data_status: 'partial',
    date_basis: 'same_date_key_distinct_source_semantics',
    date_basis_note: '同一日期键分层比较，不混同来源语义。',
    metrics: {
      ota_room_revenue: {
        value: 1032.39,
        unit: 'CNY',
        status: 'ok',
        scope: 'ota_channel',
        date_basis: 'data_date',
      },
      ota_room_nights: {
        value: 1,
        unit: 'room_nights',
        status: 'ok',
        scope: 'ota_channel',
        date_basis: 'data_date',
      },
      ota_adr: {
        value: 1032.39,
        unit: 'CNY',
        status: 'ok',
        scope: 'ota_channel',
        date_basis: 'data_date',
      },
      whole_hotel_room_revenue: {
        value: 7930.11,
        unit: 'CNY',
        status: 'ok',
        scope: 'whole_hotel_accommodation',
        date_basis: 'pms_business_date',
      },
      whole_hotel_sellable_room_nights: {
        value: 15,
        unit: 'room_nights',
        status: 'ok',
        scope: 'whole_hotel_accommodation',
        date_basis: 'pms_business_date',
      },
      whole_hotel_revpar: {
        value: 528.67,
        unit: 'CNY',
        status: 'ok',
        scope: 'whole_hotel_accommodation',
        date_basis: 'pms_business_date',
      },
      ota_contribution_revpar: {
        value: 68.83,
        unit: 'CNY',
        status: 'ok',
        scope: 'cross_source_comparison',
        date_basis: 'same_date_key_distinct_source_semantics',
      },
    },
    three_source_fact_layer: {
      all_three_sources_readback_verified: true,
      analysis_gaps: [],
      ai_review_gaps: [{
        code: 'floor_price_missing',
        source: 'pricing_guard',
        status: 'missing',
        category: 'room_type_floor_price',
      }],
      unique_remaining_gap: {
        code: 'floor_price_missing',
        source: 'pricing_guard',
        status: 'missing',
        category: 'room_type_floor_price',
      },
      sources: {
        dingdandao_pms: { data_status: 'readback_verified' },
      },
      facts: {
        ota_channel: {
          combined: {
            revenue: 1032.39,
            orders: 1,
            room_nights: 1,
          },
        },
      },
    },
    missing_datasets: [
      { key: 'legacy-denominator', reason: 'available_room_nights_missing' },
      { key: 'traffic-gap', reason: 'source_rows_missing' },
    ],
    p1_revenue_closure: {
      status: 'partial',
      calculation_allowed: false,
      scope: 'ota_channel',
      sections: {
        revenue: { value: 3200.39, unit: 'CNY', status: 'ok' },
        orders: { value: 6, unit: 'orders', status: 'ok' },
        room_nights: { value: 6, unit: 'room_nights', status: 'ok' },
        adr_conversion: { metrics: {} },
      },
      missing_items: {
        items: [
          { code: 'available_room_nights_missing' },
          { code: 'traffic.avg_flow_rate:source_rows_missing' },
        ],
      },
      anomaly_judgment: { items: [] },
    },
  };

  const cards = helpers.buildRevenueAiMetricCards({ overview });
  assert.equal(
    cards.find((card) => card.key === 'whole_hotel_room_revenue').scopeLabel,
    'PMS全酒店住宿口径',
  );
  assert.equal(
    cards.find((card) => card.key === 'ota_contribution_revpar').scopeLabel,
    '跨源分层指标',
  );

  const closure = helpers.buildRevenueAiBusinessClosure({ overview });
  assert.equal(closure.status, 'ready');
  assert.equal(closure.calculationAllowed, true);
  assert.equal(closure.scopeText, '三源分层口径');
  assert.match(closure.summary, /PMS 仅承载全酒店住宿事实/);
  assert.equal(closure.rows[0].stage, '三源数据');
  assert.equal(closure.rows[1].statusLabel, '可作为输入');
  assert.equal(closure.rows[1].metrics[0].value, '¥1032.39');
  assert.equal(closure.rows[1].metrics[1].value, '1单');
  assert.equal(closure.rows[1].metrics[2].value, '1.00间夜');
  assert.equal(closure.missingRows.length, 1);
  assert.equal(closure.missingRows[0].code, 'floor_price_missing');
  const gaps = helpers.buildRevenueAiGapRows({ overview });
  assert.equal(gaps.length, 1);
  assert.equal(gaps[0].key, 'fact-layer-pricing_guard-floor_price_missing');
  assert.equal(gaps[0].raw.code, 'floor_price_missing');
  assert.equal(gaps[0].target_page, 'agent-center');
  assert.equal(gaps[0].target_tab, 'suggestions');
  assert.equal(gaps[0].target_agent_tab, 'revenue');
  assert.equal(gaps[0].target_revenue_tab, 'suggestions');
  assert.deepEqual({ ...helpers.resolveRevenueAiGapTarget(gaps[0]) }, {
    targetPage: 'agent-center',
    targetTab: 'suggestions',
    targetPlatform: '',
    targetAgentTab: 'revenue',
    targetRevenueTab: 'suggestions',
    hotelId: '',
    businessDate: '',
  });

  const statusRows = helpers.buildRevenueAiStatusRows({
    overview,
    hotelName: '敦煌漠蓝新',
    hasHotelFilter: true,
    businessDate: '2026-07-30',
  });
  const scope = statusRows.find((row) => row.key === 'scope');
  const pms = statusRows.find((row) => row.key === 'pms');
  const api = statusRows.find((row) => row.key === 'overview');
  assert.equal(scope.value, '三源分层口径');
  assert.equal(scope.status, 'PMS全酒店住宿 + OTA渠道');
  assert.match(scope.detail, /PMS 不与 OTA 收入相加/);
  assert.equal(pms.value, '已保存并回读');
  assert.match(pms.detail, /全酒店住宿收入/);
  assert.equal(api.status, '可作为输入');
});

test('Revenue AI keeps verified dual-OTA facts visible while a PMS source gap blocks cross-source analysis', () => {
  const overview = {
    scope: 'three_source_layered',
    revenue_analysis_status: 'blocked',
    metrics: {
      ota_room_revenue: {
        value: 4354.39,
        unit: 'CNY',
        status: 'ok',
        scope: 'ota_channel',
      },
      ota_room_nights: {
        value: 5,
        unit: 'room_nights',
        status: 'ok',
        scope: 'ota_channel',
      },
      ota_adr: {
        value: 870.88,
        unit: 'CNY',
        status: 'ok',
        scope: 'ota_channel',
      },
      whole_hotel_room_revenue: {
        value: null,
        unit: 'CNY',
        status: 'not_calculable',
        scope: 'whole_hotel_accommodation',
      },
      whole_hotel_sellable_room_nights: {
        value: null,
        unit: 'room_nights',
        status: 'not_calculable',
        scope: 'whole_hotel_accommodation',
      },
      ota_contribution_revpar: {
        value: null,
        unit: 'CNY',
        status: 'not_calculable',
        scope: 'cross_source_comparison',
      },
    },
    three_source_fact_layer: {
      source_completeness: {
        dingdandao_pms: 'not_verified',
        ctrip_ota: 'readback_verified',
        meituan_ota: 'readback_verified',
      },
      all_three_sources_readback_verified: false,
      sources: {
        dingdandao_pms: { data_status: 'not_verified' },
        ctrip_ota: { data_status: 'readback_verified' },
        meituan_ota: { data_status: 'readback_verified' },
      },
      facts: {
        ota_channel: {
          combined: {
            revenue: 4354.39,
            orders: 3,
            room_nights: 5,
          },
        },
      },
      analysis_gaps: [{
        code: 'dingdandao_pms_not_readback_verified',
        source: 'dingdandao_pms',
        status: 'not_verified',
        category: 'source_identity_or_readback',
      }],
      ai_review_gaps: [{
        code: 'dingdandao_pms_not_readback_verified',
        source: 'dingdandao_pms',
        status: 'not_verified',
        category: 'source_identity_or_readback',
      }],
      unique_remaining_gap: {
        code: 'dingdandao_pms_not_readback_verified',
        source: 'dingdandao_pms',
        status: 'not_verified',
        category: 'source_identity_or_readback',
      },
    },
    p1_revenue_closure: {
      status: 'partial',
      calculation_allowed: true,
      scope_statement: 'legacy Ctrip-only closure',
      sections: {
        revenue: { value: null, unit: 'CNY', status: 'not_calculable' },
        orders: { value: null, unit: 'orders', status: 'not_calculable' },
        room_nights: { value: null, unit: 'room_nights', status: 'not_calculable' },
        adr_conversion: { metrics: {} },
      },
      missing_items: { items: [{ code: 'legacy_missing' }] },
      anomaly_judgment: {
        items: [
          { code: 'ota_collection_quality:unverified' },
          { code: 'critical_metric_untrusted:totals.adr' },
          { code: 'p0_ota_gate_not_ready' },
          { code: 'p0_ota_gate_missing:ctrip_p0_not_ready' },
        ],
      },
    },
  };

  const closure = helpers.buildRevenueAiBusinessClosure({ overview });
  assert.equal(closure.status, 'blocked');
  assert.equal(closure.calculationAllowed, false);
  assert.equal(closure.rows[0].stage, '三源数据');
  assert.match(closure.summary, /携程、美团 OTA 渠道事实已回读/);
  assert.match(closure.summary, /PMS 与跨源指标保持为空/);
  assert.equal(closure.rows[1].metrics[0].value, '¥4354.39');
  assert.equal(closure.rows[1].metrics[1].value, '3单');
  assert.equal(closure.rows[1].metrics[2].value, '5.00间夜');
  assert.equal(closure.rows[1].metrics[3].value, '¥870.88');
  assert.match(closure.rows[1].metrics[4].reasonText, /当前三源收益事实层不承载/);
  assert.match(closure.rows[1].metrics[5].reasonText, /当前三源收益事实层不承载/);
  assert.equal(closure.missingRows.length, 1);
  assert.equal(
    closure.missingRows[0].code,
    'dingdandao_pms_not_readback_verified',
  );
  assert.equal(closure.anomalyRows.length, 0);
  assert.match(closure.summaryChips[0].detail, /PMS 全酒店住宿事实尚未完成同店同日回读验证/);
  assert.doesNotMatch(closure.summaryChips[0].detail, /P0|OTA.*门/);
  assert.doesNotMatch(closure.nextAction, /补齐已验证 OTA/);
});

test('Revenue AI closure prefers the absorbed validation diagnostic contract', () => {
  const overview = {
    scope: 'three_source_layered',
    revenue_analysis_status: 'ready',
    metrics: {
      ota_room_revenue: { value: 1032.39, unit: 'CNY', status: 'ok' },
      ota_room_nights: { value: 1, unit: 'room_nights', status: 'ok' },
      ota_adr: { value: 1032.39, unit: 'CNY', status: 'ok' },
    },
    three_source_fact_layer: {
      all_three_sources_readback_verified: true,
      sources: {
        dingdandao_pms: { data_status: 'readback_verified' },
        ctrip_ota: { data_status: 'readback_verified' },
        meituan_ota: { data_status: 'readback_verified' },
      },
      facts: {
        ota_channel: {
          combined: { revenue: 1032.39, orders: 1, room_nights: 1 },
        },
      },
      analysis_gaps: [],
      ai_review_gaps: [{
        code: 'floor_price_missing',
        source: 'pricing_guard',
        status: 'missing',
      }],
      analysis_diagnostics: {
        overall_assessment: 'share_with_caveats',
        summary: '三源事实可用于收益分析，但需带限制说明：最低保护价缺失。',
        next_action: '为启用房型配置最低保护价，保存回显后重新进入人工调价审核。',
        decision_use: {
          revenue_analysis: { allowed: true },
        },
        checks: [{
          key: 'source_readback',
          label: '三源保存与精确回读',
          status: 'passed',
        }],
        issues: [{
          code: 'floor_price_missing',
          source: 'pricing_guard',
          status: 'missing',
          severity: 'medium',
          message: '最低保护价缺失，不能进入调价审核。',
          next_action: '为启用房型配置最低保护价。',
        }],
      },
    },
    p1_revenue_closure: {
      status: 'ready',
      calculation_allowed: true,
      sections: { adr_conversion: { metrics: {} } },
      anomaly_judgment: { items: [] },
    },
  };

  const closure = helpers.buildRevenueAiBusinessClosure({ overview });
  assert.equal(closure.calculationAllowed, true);
  assert.equal(closure.validationAssessment, 'share_with_caveats');
  assert.equal(closure.qualityChecks.length, 1);
  assert.equal(
    closure.summary,
    '三源事实可用于收益分析，但需带限制说明：最低保护价缺失。',
  );
  assert.equal(
    closure.nextAction,
    '为启用房型配置最低保护价，保存回显后重新进入人工调价审核。',
  );
  assert.equal(closure.missingRows.length, 1);
  assert.equal(closure.missingRows[0].severity, 'medium');
  assert.equal(closure.missingRows[0].detail, '最低保护价缺失，不能进入调价审核。');
});

test('Revenue AI metric cards expose the complete four-state truth envelope for each number', () => {
  const cards = helpers.buildRevenueAiMetricCards({
    overview: {
      scope: 'ota',
      date_basis: 'data_date',
      metrics: {
        ota_room_revenue: {
          display: '¥1,200.00',
          status: 'ok',
          truth: {
            status: 'verified',
            status_label: '已验证',
            scope_label: 'OTA渠道指标，不代表全酒店经营',
            hotels: [{ system_hotel_id: 7, name: '测试酒店' }],
            platforms: ['ctrip'],
            date_range: { start: '2026-07-18', end: '2026-07-18' },
            source: { table: 'online_daily_data', methods: ['browser_profile'] },
            collected_at_range: { start: '2026-07-19 08:30:00', end: '2026-07-19 08:30:00' },
            persistence: { record_count: 2, stored_count: 2, readback_verified_count: 2 },
            failure_reason: '',
          },
        },
      },
    },
  });
  const revenue = cards.find((card) => card.key === 'ota_room_revenue');
  assert.equal(revenue.statusLabel, '已验证');
  assert.equal(revenue.metricStatusLabel, '正常');
  assert.deepEqual(
    Array.from(revenue.truthLines, (line) => line.label),
    ['门店', '平台', '日期', '来源', '采集时间', '入库', '失败原因', '口径'],
  );
  assert.match(revenue.truthSummary, /门店：测试酒店/);
  assert.match(revenue.truthSummary, /平台：携程/);
  assert.match(revenue.truthSummary, /来源：online_daily_data \/ browser_profile/);
  assert.match(revenue.truthSummary, /采集时间：2026-07-19 08:30:00/);
  assert.match(revenue.truthSummary, /入库：入库 2\/2；回读 2\/2/);
  assert.match(revenue.truthSummary, /失败原因：无/);
  assert.match(revenue.truthSummary, /OTA渠道指标，不代表全酒店经营/);

  const partial = helpers.buildRevenueAiMetricCards({
    overview: {
      metrics: {
        ota_room_revenue: {
          display: '¥800.00',
          status: 'ok',
          truth: { status: 'partial', failure_reason: 'collected_at_missing' },
        },
      },
    },
  }).find((card) => card.key === 'ota_room_revenue');
  assert.equal(partial.statusLabel, '部分数据');
  assert.equal(partial.reasonText, 'collected_at_missing');
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

test('Revenue AI gap rows keep platform metrics separate and expose every source action', () => {
  const channelMetricGaps = ['ctrip', 'meituan'].flatMap((platform) => (
    Array.from({ length: 11 }, (_, index) => ({
      key: `${platform}_metric_gap_${index}`,
      type: 'channel_metric_gap',
      channel: platform,
      target_platform: platform,
      label: `${platform === 'ctrip' ? '携程' : '美团'}指标${index + 1}`,
      status: 'missing',
      reason: index === 0 ? 'room_revenue_missing' : `${platform}_metric_${index}_missing`,
      severity: index < 3 ? 'high' : 'medium',
      next_action: `补齐${platform === 'ctrip' ? '携程' : '美团'}指标${index + 1}`,
      acceptance_check: `验收${platform === 'ctrip' ? '携程' : '美团'}指标${index + 1}`,
      forbidden_shortcut: 'cross_platform_fill',
      completion_state: 'source_fact_required',
      target_page: platform === 'ctrip' ? 'ctrip-ebooking' : 'online-data',
      target_tab: 'data-health',
      hotel_id: 80,
      business_date: '2026-08-01',
    }))
  ));
  const rows = helpers.buildRevenueAiGapRows({
    overview: {
      channel_metric_gaps: channelMetricGaps,
      missing_datasets: [{
        key: 'global_room_revenue_missing',
        reason: 'room_revenue_missing',
        status: 'missing',
      }],
      quality_issues: [],
    },
  });

  assert.equal(rows.length, 22, 'the gap list must not hide either platform\'s complete metric set');
  assert.equal(helpers.buildRevenueAiGapSummary(rows).total, 22, 'the summary must use the complete gap list');
  assert.equal(helpers.buildRevenueAiGapSummary(rows).high, 6);
  assert.equal(rows[0].channelLabel, '携程');
  assert.equal(rows[0].target_page, 'ctrip-ebooking');
  assert.equal(rows[0].acceptanceCheck, '验收携程指标1');
  assert.equal(rows[0].forbiddenShortcut, 'cross_platform_fill');
  assert.equal(rows[0].completionState, 'source_fact_required');
  assert.match(rows[0].reasonText, /房费收入/);
  assert.equal(rows[21].channelLabel, '美团');
  assert.equal(rows[21].target_page, 'online-data');
  assert.equal(rows[21].hotel_id, 80);
  assert.equal(rows[21].business_date, '2026-08-01');
  assert.deepEqual({ ...helpers.resolveRevenueAiGapTarget(rows[21]) }, {
    targetPage: 'online-data',
    targetTab: 'data-health',
    targetPlatform: 'meituan',
    targetAgentTab: '',
    targetRevenueTab: '',
    hotelId: '80',
    businessDate: '2026-08-01',
  });
  assert.equal(rows.some((row) => row.key === 'global_room_revenue_missing'), false);
  assert.match(appMain, /targetPage === 'ctrip-ebooking'/);
  assert.match(appMain, /openCtripManualTab\(targetTab \|\| 'data-health'\)/);
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
        booking_window_adr: {
          label: '提前期房费结构',
          value: '当天 ¥300.00 · 8-14天 ¥200.00',
          status: 'ok',
          reason: 'booking_window_adr_structure_available',
        },
        channel_booking_window_month: {
          label: '渠道预售窗口',
          value: '2026-07 携程 8-14天 62.5%',
          status: 'ok',
          reason: 'channel_booking_window_month_structure_available',
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
  const bookingWindow = rows.find((row) => row.key === 'booking_window_adr');
  const channelWindow = rows.find((row) => row.key === 'channel_booking_window_month');
  const pricing = rows.find((row) => row.key === 'pricing_advice');
  assert.equal(holiday.value, '测试节日 T-5');
  assert.match(holiday.reasonText, /节假日窗口/);
  assert.equal(demand.value, '高需求 1天');
  assert.match(demand.reasonText, /高需求/);
  assert.equal(competitor.value, '本店高于竞对 ¥20.00');
  assert.equal(competitor.statusLabel, '需复核');
  assert.match(competitor.reasonText, /人工复核/);
  assert.equal(bookingWindow.value, '当天 ¥300.00 · 8-14天 ¥200.00');
  assert.equal(bookingWindow.statusLabel, '正常');
  assert.match(bookingWindow.reasonText, /历史结构/);
  assert.equal(channelWindow.value, '2026-07 携程 8-14天 62.5%');
  assert.equal(channelWindow.statusLabel, '正常');
  assert.match(channelWindow.reasonText, /预售窗口/);
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
            { key: 'revpar_denominator', label: 'OTA渠道可售房晚分母', status: 'blocked', severity: 'medium', reason: 'available_room_nights_missing', display_reason: '暂缺可信 OTA 渠道可售房晚分母，不能计算或外推全酒店 RevPAR。' },
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

test('Revenue AI action rows expose AI decision resolution plan as operator evidence checklist', () => {
  const resolutionPlan = {
    status: 'has_pending_evidence',
    source_scope: 'ctrip_ota_channel',
    source_channels: ['ctrip'],
    item_count: 2,
    pending_count: 2,
    items: [
      {
        order: 1,
        code: 'revpar_denominator',
        input_type: 'revenue_metric',
        evidence_code: 'available_room_nights_missing',
        status: 'pending_evidence',
        severity: 'high',
        target_page: 'online-data',
        target_tab: 'data-health',
        target_platform: 'hotel',
        target_agent_tab: 'revenue',
        target_revenue_tab: 'suggestions',
        resolution_action: 'provide_available_room_nights_or_mark_metric_unusable',
        acceptance_check: 'available_room_nights is proved or metric is explicitly unusable',
        unblocks: 'ai_decision_review_contract.approval_allowed',
        forbidden_shortcut: 'fill_missing_evidence_with_defaults',
      },
      {
        order: 2,
        code: 'manual_review_workflow',
        input_type: 'manual_review',
        evidence_code: 'manual_review_workflow_not_connected',
        status: 'pending_evidence',
        severity: 'medium',
        resolution_action: 'persist_or_attach_manual_review_record',
        acceptance_check: 'manual review record exists before operation intake',
        unblocks: 'ai_decision_review_contract.operation_intake_allowed',
        forbidden_shortcut: 'auto_create_operation_execution_intent',
      },
    ],
  };

  const summary = helpers.buildRevenueAiResolutionPlanSummary({
    action: { ai_decision_resolution_plan: resolutionPlan },
  });
  assert.equal(summary.visible, true);
  assert.equal(summary.status, 'has_pending_evidence');
  assert.equal(summary.sourceScope, 'ctrip_ota_channel');
  assert.deepEqual(summary.sourceChannels, ['ctrip']);
  assert.equal(summary.itemCount, 2);
  assert.equal(summary.pendingCount, 2);
  assert.equal(summary.items.length, 2);
  assert.equal(summary.items[0].resolutionAction, 'provide_available_room_nights_or_mark_metric_unusable');
  assert.equal(summary.items[0].acceptanceCheck, 'available_room_nights is proved or metric is explicitly unusable');
  assert.equal(summary.items[0].targetPage, 'online-data');
  assert.equal(summary.items[0].targetTab, 'data-health');
  assert.equal(summary.items[0].targetAgentTab, 'revenue');
  assert.equal(summary.items[0].targetRevenueTab, 'suggestions');
  assert.equal(summary.items[0].canOpenTarget, true);
  assert.equal(summary.items[1].forbiddenShortcut, 'auto_create_operation_execution_intent');

  const rows = helpers.buildRevenueAiActionRows({
    overview: {
      actions: [{
        key: 'pricing_review',
        title: 'pricing review',
        status: 'blocked',
        reason: 'available_room_nights_missing',
        ai_decision_resolution_plan: resolutionPlan,
      }],
    },
  });
  assert.equal(rows[0].resolutionPlanVisible, true);
  assert.equal(rows[0].resolutionPlanItems[0].unblocks, 'ai_decision_review_contract.approval_allowed');
  assert.equal(rows[0].resolutionPlanItems[0].forbiddenShortcut, 'fill_missing_evidence_with_defaults');
  assert.match(html, /data-testid="revenue-ai-resolution-plan"/);
});

test('Revenue AI evidence workbench keeps OTA to operation gates explicit', () => {
  const overview = {
    source_scope: 'ctrip_ota_channel',
    data_status: 'blocked',
    p0_downstream_gate: {
      status: 'blocked_by_p0_ota_gate',
      reason: 'source_rows_missing',
      required_gate_command: 'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-04 --system-hotel-id=58',
      source_scope: 'ctrip_ota_channel',
    },
    review_queue: {
      status: 'pending_review',
      display: '待审核 1 / 已批准 0',
      pending_count: 1,
      approved_count: 0,
      target_page: 'agent-center',
      target_agent_tab: 'revenue',
      target_revenue_tab: 'suggestions',
      next_action: '人工审核调价建议',
    },
    ai_to_operation_handoff: {
      status: 'operation_intake_blocked_by_manual_review',
      target_entry: '/api/operation/execution-intents',
      can_create_operation_execution: false,
      auto_create_operation_execution: false,
      protected_boundary: 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
      operation_intake_packet: {
        status: 'blocked_by_manual_review_packet',
        candidate_blocked_reason: 'manual_review_required',
        operation_intake_preflight_contract: {
          status: 'blocked_by_ai_review_contract',
          create_allowed: false,
          would_call_create_endpoint: false,
          protected_boundary: 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
        },
      },
    },
    execution_summary: {
      status: 'empty',
      reason: 'operation_execution_empty',
      total_count: 0,
      evidence_ready_count: 0,
      roi_ready_count: 0,
    },
  };

  const rows = helpers.buildRevenueAiEvidenceWorkbenchRows({ overview });
  assert.equal(rows.length, 4);
  assert.equal(rows[0].key, 'ota_evidence_gate');
  assert.equal(rows[0].statusLabel, 'P0门禁未过');
  assert.match(rows[0].nextActionText, /verify:p0-ota-field-loop/);
  assert.equal(rows[1].canOpenTarget, true);
  assert.equal(rows[2].metaText, 'can_create=false / auto_create=false');
  assert.match(rows[2].policyText, /operation_intake_requires_approved_ai_review/);

  const summary = helpers.buildRevenueAiEvidenceWorkbenchSummary(rows);
  assert.equal(summary.status, 'blocked');
  assert.match(summary.statusLabel, /门禁阻断/);
  assert.match(summary.detailText, /已读 4 个环节/);
  assert.match(html, /data-testid="revenue-ai-evidence-workbench"/);
});

test('Revenue AI action rows expose readonly price suggestion review queue', () => {
  const trustedDecision = ({ canConfirm = false, canTransfer = false } = {}) => ({
    contract_version: 'revenue_ai_trusted_decision.v1',
    scope: 'ota_channel',
    store: { hotel_id: 7, hotel_name: '测试酒店', display: '测试酒店 (#7)' },
    platform: { key: 'ctrip', label: '携程', scope: 'ota_channel' },
    date: { value: '2026-06-25', basis: 'suggestion_date', status: 'available' },
    sources: {
      status: 'verified',
      summary: '携程历史经营事实已通过数据库回读',
      items: [{ ref: 'online_daily_data#pricing_history:7:2026-06-01:2026-06-25' }],
      ref_count: 1,
    },
    metric_formula: {
      metric: 'price_change_rate',
      expression: '(建议价 - 当前价) ÷ 当前价 × 100%',
      status: 'calculable',
      display: '+13.57%',
    },
    data_quality: { status: 'verified', label: '已验证', decision_eligible: true, note: '数据库回读已验证' },
    confidence: { score: 0.82, display: '82%', status: 'available' },
    gaps: [],
    recommended_action: { summary: '人工复核后调整携程目标价；不自动写 OTA。', auto_write_ota: false },
    expected_effect: { status: 'verification_target', display: '执行后按同口径回读渠道收入、订单与 ADR' },
    human_confirmation: {
      required: true,
      confirmed: canTransfer,
      can_confirm: canConfirm,
      can_transfer_to_operation_task: canTransfer,
      auto_write_ota: false,
    },
  });
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
          can_confirm: true,
          can_transfer_to_operation_task: false,
          trusted_decision: trustedDecision({ canConfirm: true }),
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
          can_confirm: false,
          can_transfer_to_operation_task: true,
          trusted_decision: trustedDecision({ canTransfer: true }),
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
          target_page: 'agent-center',
          target_tab: 'suggestions',
          target_agent_tab: 'revenue',
          target_revenue_tab: 'suggestions',
          target_filter: { hotel_id: 7, date: '2026-06-25', status: 1 },
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
            can_confirm: true,
            can_transfer_to_operation_task: false,
            trusted_decision: trustedDecision({ canConfirm: true }),
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
            can_confirm: false,
            can_transfer_to_operation_task: true,
            trusted_decision: trustedDecision({ canTransfer: true }),
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
  assert.equal(rows[0].reviewQueueCanOpenTarget, true);
  assert.equal(rows[0].reviewQueueTarget.targetPage, 'agent-center');
  assert.equal(rows[0].reviewQueueTarget.targetAgentTab, 'revenue');
  assert.equal(rows[0].reviewQueueTarget.targetRevenueTab, 'suggestions');
  assert.deepEqual(rows[0].reviewQueueTarget.targetFilter, { hotel_id: 7, date: '2026-06-25', status: 1 });
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
  assert.equal(rows[0].reviewQueueItems[0].actionButtons.map(button => button.key).join('|'), 'approve|approve_with_changes|reject');
  assert.equal(rows[0].reviewQueueItems[0].actionHelpText, '仅在可信输入通过后人工审核；不写 OTA');
  assert.equal(rows[0].reviewQueueItems[0].trustedContractValid, true);
  assert.equal(rows[0].reviewQueueItems[0].trustedDecisionRows.length, 10);
  assert.equal(
    rows[0].reviewQueueItems[0].trustedDecisionRows.map(field => field.label).join('|'),
    '门店|平台|日期|来源|指标公式|数据质量|置信度|缺口|建议动作|预期效果',
  );
  assert.equal(rows[0].reviewQueueItems[0].trustedDecisionRows[0].value, '测试酒店 (#7)');
  assert.equal(rows[0].reviewQueueItems[0].trustedDecisionRows[4].value, '(建议价 - 当前价) ÷ 当前价 × 100% = +13.57%');
  assert.equal(rows[0].reviewQueueItems[0].trustedDecisionRows[7].value, '无输入缺口');
  assert.equal(rows[0].reviewQueueItems[0].suggestedPrice, 318);
  assert.equal(rows[0].reviewQueueItems[0].minPrice, 220);
  assert.equal(rows[0].reviewQueueItems[0].requiresSuperAdmin, false);
  assert.equal(rows[0].reviewQueueItems[0].requiresHotelPermission, true);
  assert.equal(rows[0].reviewQueueItems[0].allowedEndpoint, '/api/revenue-ai/price-suggestions/11/review');
  assert.equal(rows[0].reviewQueueItems[0].allowedEndpoints.execution_intent, '/api/revenue-ai/price-suggestions/11/execution-intent');
  assert.deepEqual(rows[0].reviewQueueItems[0].actionEntry.forbidden_actions, ['apply_price', 'ota_write']);
  assert.equal(rows[0].reviewQueueItems[1].title, '房型 #4 · 动态定价');
  assert.equal(rows[0].reviewQueueItems[1].actionLabel, '转运营任务');
  assert.equal(rows[0].reviewQueueItems[1].canApprove, false);
  assert.equal(rows[0].reviewQueueItems[1].canReject, false);
  assert.equal(rows[0].reviewQueueItems[1].canCreateExecutionIntent, true);
  assert.equal(rows[0].reviewQueueItems[1].actionButtons.map(button => button.key).join('|'), 'execution_intent');
  assert.match(rows[0].reviewQueueItems[1].actionHelpText, /待执行运营任务/);
  assert.equal(rows[0].reviewQueueItems[1].allowedEndpoint, '/api/revenue-ai/price-suggestions/12/execution-intent');
  assert.match(rows[0].reviewQueueItems[1].impactLine, /暂缺可信预计 RevPAR 影响数据/);
  assert.equal(rows[0].autoWriteOta, false);
});

test('Revenue AI trusted decision displays not calculable and blocks approval when denominator is missing', () => {
  const items = helpers.buildRevenueAiReviewQueueItems({
    pending_items: [{
      id: 31,
      hotel_id: 7,
      status: 'pending_review',
      suggestion_date: '2026-06-25',
      suggested_price: 318,
      can_review: true,
      can_confirm: false,
      auto_write_ota: false,
      action_entry: {
        allowed_endpoints: { review: '/api/revenue-ai/price-suggestions/31/review' },
        manual_actions: ['reject'],
      },
      trusted_decision: {
        contract_version: 'revenue_ai_trusted_decision.v1',
        store: { display: '测试酒店 (#7)' },
        platform: { label: '携程' },
        date: { value: '2026-06-25' },
        sources: { status: 'verified', items: [{ ref: 'online_daily_data#31' }], ref_count: 1 },
        metric_formula: {
          expression: '(建议价 - 当前价) ÷ 当前价 × 100%',
          status: 'not_calculable',
          display: '不可计算',
          reason: 'current_price_denominator_missing',
        },
        data_quality: { status: 'verified', label: '已验证', decision_eligible: true },
        confidence: { status: 'available', score: 0.82, display: '82%' },
        gaps: [{ code: 'current_price_denominator_missing', message: '当前价分母缺失，价格调整率不可计算' }],
        recommended_action: { summary: '先补齐当前价分母' },
        expected_effect: { display: '不可计算' },
        human_confirmation: { can_confirm: false, can_transfer_to_operation_task: false },
      },
    }],
  });

  assert.equal(items.length, 1);
  assert.equal(items[0].canApprove, false);
  assert.equal(items[0].canApproveWithChanges, false);
  assert.equal(items[0].canReject, true);
  assert.equal(items[0].trustedDecisionRows.find(field => field.key === 'formula').value, '(建议价 - 当前价) ÷ 当前价 × 100% = 不可计算');
  assert.match(items[0].trustedDecisionRows.find(field => field.key === 'gaps').value, /分母缺失/);
});

test('Revenue AI action rows expose pricing generation preflight without OTA write', () => {
  const overview = {
    pricing_generation_preflight: {
      status: 'blocked',
      reason: 'room_types_empty',
      detail: '携程目标酒店暂无启用房型，生成入口会产生 0 条待审调价建议。',
      next_action: '为携程目标酒店配置启用房型和最低保护价。',
      source_scope: 'ctrip_ota_channel',
      source_channels: ['ctrip'],
      target_hotel_ids: [60, 64],
      target_hotel_count: 2,
      target_date_rows: 27,
      room_type_count: 0,
      pending_suggestion_count: 0,
      create_candidate_count: 0,
      skipped_candidate_count: 0,
      candidate_skip_reasons: ['primary_signal_count_insufficient', 'floor_price_missing', 'manual_review_required', 'price_change_too_small', 'risk_guard_failed'],
      candidate_data_gaps: ['demand_forecast_missing', 'competitor_price_missing', 'inventory_demand_signal_missing', 'elasticity_sample_lt_10', 'pickup_curve_on_books_snapshot_missing_or_short_history', 'ota_room_rate_source_missing'],
      hotel_checks: [{
        hotel_id: 64,
        target_date_rows: 27,
        room_type_count: 2,
        pending_suggestions: 0,
        demand_forecasts: 1,
        competitor_analysis_recent: 0,
        create_candidate_count: 0,
        skipped_candidate_count: 2,
        skip_reasons: ['primary_signal_count_insufficient', 'competitor_price_missing', 'manual_review_required', 'risk_guard_failed'],
      }],
      required_inputs: [
        { code: 'room_types_enabled', source: 'room_types', next_action: '配置启用房型。' },
        { code: 'floor_price_or_min_rate_guard', source: 'room_types', next_action: '配置最低保护价。' },
      ],
      can_generate_pending_suggestions: false,
      read_only: true,
      advisory_only: true,
      auto_write_ota: false,
      target_page: 'agent-center',
      target_tab: 'suggestions',
      target_agent_tab: 'revenue',
      target_revenue_tab: 'suggestions',
      target_filter: { hotel_id: 0, date: '2026-06-28', status: 0 },
    },
    actions: [{
      key: 'pricing_review',
      title: '暂无可审核调价建议',
      status: 'blocked',
      reason: 'room_types_empty',
      pricing_generation_preflight: {
        status: 'blocked',
        reason: 'room_types_empty',
        detail: '携程目标酒店暂无启用房型，生成入口会产生 0 条待审调价建议。',
        source_scope: 'ctrip_ota_channel',
        source_channels: ['ctrip'],
        target_hotel_ids: [60, 64],
        target_date_rows: 27,
        room_type_count: 0,
        pending_suggestion_count: 0,
        create_candidate_count: 0,
        required_inputs: [
          { code: 'room_types_enabled', source: 'room_types', next_action: '配置启用房型。' },
        ],
        can_generate_pending_suggestions: false,
        read_only: true,
        advisory_only: true,
        auto_write_ota: false,
        target_page: 'agent-center',
        target_tab: 'suggestions',
        target_agent_tab: 'revenue',
        target_revenue_tab: 'suggestions',
        target_filter: { hotel_id: 0, date: '2026-06-28', status: 0 },
      },
    }],
  };

  const summary = helpers.buildRevenueAiPricingGenerationPreflightSummary({ overview });
  assert.equal(summary.visible, true);
  assert.equal(summary.status, 'blocked');
  assert.equal(summary.statusLabel, '生成受阻');
  assert.equal(summary.sourceScope, 'ctrip_ota_channel');
  assert.deepEqual(summary.targetHotelIds, [60, 64]);
  assert.equal(summary.roomTypeCount, 0);
  assert.equal(summary.createCandidateCount, 0);
  assert.deepEqual(summary.candidateSkipReasons, ['primary_signal_count_insufficient', 'floor_price_missing', 'manual_review_required', 'price_change_too_small']);
  assert.equal(summary.hiddenCandidateSkipReasonCount, 1);
  assert.deepEqual(summary.candidateDataGaps, ['demand_forecast_missing', 'competitor_price_missing', 'inventory_demand_signal_missing', 'elasticity_sample_lt_10', 'pickup_curve_on_books_snapshot_missing_or_short_history']);
  assert.equal(summary.hiddenCandidateDataGapCount, 1);
  assert.equal(summary.hotelChecks[0].hotelId, 64);
  assert.equal(summary.hotelChecks[0].createCandidateCount, 0);
  assert.equal(summary.hotelChecks[0].skippedCandidateCount, 2);
  assert.deepEqual(summary.hotelChecks[0].skipReasons, ['primary_signal_count_insufficient', 'competitor_price_missing', 'manual_review_required']);
  assert.equal(summary.hotelChecks[0].hiddenSkipReasonCount, 1);
  assert.equal(summary.canGeneratePendingSuggestions, false);
  assert.equal(summary.autoWriteOta, false);
  assert.equal(summary.target.targetPage, 'agent-center');
  assert.equal(summary.target.targetAgentTab, 'revenue');
  assert.equal(summary.target.targetRevenueTab, 'suggestions');
  assert.deepEqual(summary.target.targetFilter, { hotel_id: 0, date: '2026-06-28', status: 0 });

  const rows = helpers.buildRevenueAiActionRows({ overview });
  assert.equal(rows[0].pricingGenerationPreflightVisible, true);
  assert.equal(rows[0].pricingGenerationPreflightSummary.requiredInputs[0].code, 'room_types_enabled');
  assert.equal(rows[0].pricingGenerationPreflightSummary.readOnly, true);
  assert.equal(rows[0].pricingGenerationPreflightSummary.autoWriteOta, false);
});

test('Revenue AI static helpers normalize unverified legacy operator-skip state to a blocked input gap', () => {
  const overview = {
    pricing_generation_preflight: {
      status: 'skipped_by_operator_policy',
      reason: 'missing_pricing_inputs_skipped_by_operator_policy',
      detail: '已按人工策略暂时跳过房型、保护价、需求预测和竞对价格样本缺口。',
      source_scope: 'ctrip_ota_channel',
      source_channels: ['ctrip'],
      target_hotel_ids: [64],
      target_date_rows: 27,
      room_type_count: 0,
      pending_suggestion_count: 0,
      create_candidate_count: 0,
      skipped_candidate_count: 0,
      required_inputs: [
        { code: 'room_types_enabled', source: 'room_types', status: 'skipped_by_operator_policy' },
        { code: 'floor_price_or_min_rate_guard', source: 'room_types', status: 'skipped_by_operator_policy' },
      ],
      can_generate_pending_suggestions: false,
      read_only: true,
      advisory_only: true,
      auto_write_ota: false,
    },
  };

  const summary = helpers.buildRevenueAiPricingGenerationPreflightSummary({ overview });
  assert.equal(summary.visible, true);
  assert.equal(summary.status, 'blocked');
  assert.equal(summary.statusLabel, '生成受阻');
  assert.match(summary.reasonText, /缺少可核验的操作者、确认时间和持久化记录/);
  assert.equal(summary.canGeneratePendingSuggestions, false);
  assert.equal(summary.autoWriteOta, false);
  assert.equal(summary.requiredInputs[0].status, 'missing_or_blocked');
});

test('Revenue AI generate result exposes blocked Ctrip-only preconditions', () => {
  const blocked = helpers.buildRevenueAiPriceSuggestionGenerateResult({
    response: {
      code: 200,
      data: {
        status: 'blocked',
        reason: 'room_types_empty',
        detail: '携程目标酒店暂无启用房型，不能生成待审调价建议。',
        source_scope: 'ctrip_ota_channel',
        source_channels: ['ctrip'],
        target_hotel_ids: [64],
        target_filter: { hotel_id: 64, date: '2026-06-28', status: 0 },
        created_count: 0,
        skipped_count: 0,
        can_generate_pending_suggestions: false,
        advisory_only: true,
        manual_review_required: true,
        auto_write_ota: false,
        skipped: [{
          room_type_id: 12,
          room_type_name: 'Deluxe King',
          reason: 'primary_signal_count_insufficient',
          primary_signal_count: 1,
          price_change_rate: 0,
          risk_level: 'high',
          data_gaps: ['demand_forecast_missing', 'competitor_price_missing', 'inventory_demand_signal_missing', 'elasticity_sample_lt_10', 'pickup_curve_on_books_snapshot_missing_or_short_history'],
          review_checklist: ['Do not approve until blocking data gaps are resolved.', 'Add Ctrip competitor sample.', 'Add demand forecast.', 'Review elasticity.'],
        }],
        required_inputs: [
          { code: 'room_types_enabled', source: 'room_types', next_action: '配置启用房型。' },
          { code: 'floor_price_or_min_rate_guard', source: 'room_types', next_action: '配置最低保护价。' },
        ],
        next_action: '为携程目标酒店配置启用房型和最低保护价。',
      },
    },
  });

  assert.equal(blocked.status, 'blocked');
  assert.equal(blocked.reason, 'room_types_empty');
  assert.equal(blocked.level, 'warning');
  assert.equal(blocked.sourceScope, 'ctrip_ota_channel');
  assert.deepEqual(blocked.sourceChannels, ['ctrip']);
  assert.deepEqual(blocked.targetHotelIds, [64]);
  assert.equal(blocked.createdCount, 0);
  assert.equal(blocked.skippedCount, 0);
  assert.equal(blocked.canGeneratePendingSuggestions, false);
  assert.equal(blocked.autoWriteOta, false);
  assert.equal(blocked.requiredInputs[0].code, 'room_types_enabled');
  assert.equal(blocked.requiredInputs[1].code, 'floor_price_or_min_rate_guard');
  assert.equal(blocked.skippedItems[0].roomTypeName, 'Deluxe King');
  assert.equal(blocked.skippedItems[0].reason, 'primary_signal_count_insufficient');
  assert.equal(blocked.skippedItems[0].primarySignalCount, 1);
  assert.equal(blocked.skippedItems[0].dataGaps.length, 4);
  assert.equal(blocked.skippedItems[0].hiddenDataGapCount, 1);
  assert.equal(blocked.skippedItems[0].reviewChecklist.length, 3);
  assert.equal(blocked.skippedItems[0].hiddenReviewChecklistCount, 1);

  const created = helpers.buildRevenueAiPriceSuggestionGenerateResult({
    response: {
      code: 200,
      data: {
        status: 'created',
        reason: 'price_suggestions_pending_review',
        created_count: 2,
        skipped_count: 1,
        can_generate_pending_suggestions: true,
        date_range: { start_date: '2026-06-28', end_date: '2026-06-29', day_count: 2 },
        created_row_ids: [101, 102],
        readback_verified_count: 2,
        readback_verified: true,
        auto_write_ota: false,
        skipped: [{
          suggestion_date: '2026-06-29',
          room_type_id: 12,
          room_type_name: 'Deluxe King',
          reason: 'pending_suggestion_exists',
          price_change_rate: null,
        }],
      },
    },
  });
  assert.equal(created.level, 'success');
  assert.equal(created.createdCount, 2);
  assert.equal(created.readbackVerifiedCount, 2);
  assert.equal(created.readbackVerified, true);
  assert.deepEqual(created.createdRowIds, [101, 102]);
  assert.equal(created.dateRange.day_count, 2);
  assert.equal(created.skippedItems[0].targetDate, '2026-06-29');
  assert.equal(created.skippedItems[0].primarySignalCount, null);
  assert.equal(created.skippedItems[0].priceChangeRate, null);
  assert.match(created.message, /回读 2 条/);
  assert.equal(created.canGeneratePendingSuggestions, true);
  assert.equal(created.autoWriteOta, false);

  const exactTargetBlocked = helpers.buildRevenueAiPriceSuggestionGenerateResult({
    response: {
      code: 200,
      data: {
        status: 'blocked',
        reason: 'exact_target_signals_missing',
        created_count: 0,
        skipped_count: 1,
        auto_write_ota: false,
        skipped: [{
          suggestion_date: '2026-06-30',
          reason: 'exact_target_signals_missing',
          data_gaps: ['exact_target_room_type_competitor_price_missing'],
        }],
      },
    },
  });
  assert.match(exactTargetBlocked.message, /不使用旧日或酒店级样本补齐/);
  assert.equal(exactTargetBlocked.autoWriteOta, false);
});

test('pricing input date sync preserves the selected future ledger range', () => {
  const start = appMain.indexOf('            const syncRevenuePricingInputDate =');
  const end = appMain.indexOf('            const priceSuggestionRangeError =', start);
  assert.ok(start >= 0 && end > start, 'pricing input date sync source must remain extractable');
  const source = appMain.slice(start, end);
  const run = vm.runInNewContext([
    '() => {',
    "  const priceSuggestionFilter = { value: { date: '2026-08-12', end_date: '2026-08-20' } };",
    "  const demandForecastForm = { value: { forecast_date: '2026-08-12' } };",
    "  const competitorPriceForm = { value: { analysis_date: '2026-08-12' } };",
    "  const competitorFilter = { value: { date: '2026-08-12' } };",
    "  const forecastFilter = { value: { start_date: '2026-08-12', end_date: '2026-08-20' } };",
    source,
    "  syncRevenuePricingInputDate('2026-08-15');",
    '  return {',
    '    priceRange: { ...priceSuggestionFilter.value },',
    '    demandDate: demandForecastForm.value.forecast_date,',
    '    competitorInputDate: competitorPriceForm.value.analysis_date,',
    '    competitorViewDate: competitorFilter.value.date,',
    '    forecastRange: { ...forecastFilter.value },',
    '  };',
    '}',
  ].join('\n'));

  const state = run();
  assert.equal(state.priceRange.date, '2026-08-12');
  assert.equal(state.priceRange.end_date, '2026-08-20');
  assert.equal(state.demandDate, '2026-08-15');
  assert.equal(state.competitorInputDate, '2026-08-15');
  assert.equal(state.competitorViewDate, '2026-08-15');
  assert.equal(state.forecastRange.start_date, '2026-08-15');
  assert.equal(state.forecastRange.end_date, '2026-08-20');
  assert.match(appTemplate, /精确身份已回读/);
  assert.match(appTemplate, /已载入，身份字段不完整/);
});

test('Revenue AI pricing gate rows expose blockers without suggestions', () => {
  const unloaded = helpers.buildRevenueAiPricingGateRows();
  assert.equal(unloaded.length, 1);
  assert.equal(unloaded[0].statusLabel, '未加载');
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
  assert.equal(unloadedSummary.statusLabel, '未加载');
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
  assert.equal(unloaded.statusLabel, '未加载');
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
  assert.equal(helpers.revenueAiReviewActionText('execution_intent'), '转为运营任务');
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
    '确认转为本地待执行运营任务？该动作不会写入携程/美团价格，仍需人工执行、留证和复盘。',
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
  assert.equal(intentBody.approve_to_task, true);
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

  const taskRow = helpers.buildRevenueAiExecutionIntentOpenRow({
    payload: {
      target_id: 91,
      target_page: 'ops-track',
      target_action: 'record_execution',
      target_kind: 'task',
      execution_intent: { id: 73, hotel_id: 7, status: 'approved' },
      operation_task: { id: 91, status: 'pending_execute' },
      operation_task_created: true,
    },
  });
  assert.equal(taskRow.canOpenExecution, true);
  assert.equal(taskRow.targetAction, 'record_execution');
  assert.equal(taskRow.targetId, 91);
  assert.equal(taskRow.targetKind, 'task');
  assert.equal(taskRow.intentId, 73);
  assert.equal(taskRow.taskId, 91);
  assert.equal(taskRow.actionLabel, '查看待执行运营任务');
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

test('daily revenue cockpit scope defaults to the latest strict readback and keeps platform dates isolated', async () => {
  const scopePayload = {
    contract_version: 'operating_question_scope_options.v1',
    hotel_id: 80,
    data_status: 'ready',
    recommended: {
      platform: 'ctrip',
      date_start: '2026-08-20',
      selection_reason: 'latest_strict_readback',
    },
    platforms: [
      {
        platform: 'ctrip',
        latest_verified_date: '2026-08-20',
        available_dates: ['2026-08-20', '2026-08-18', '2026-08-17'],
      },
      {
        platform: 'all_ota',
        latest_verified_date: '2026-08-18',
        available_dates: ['2026-08-18', '2026-08-16'],
      },
    ],
    boundary: {
      strict_gate: 'history_success+validation_verified+readback_verified',
      silent_date_fallback: false,
      pms_included: false,
    },
  };

  const initial = helpers.resolveRevenueCockpitScope({
    scopePayload,
    today: '2026-08-21',
  });
  assert.equal(initial.selectedPlatform, 'ctrip');
  assert.equal(initial.selectedDate, '2026-08-20');
  assert.equal(initial.previousDate, '2026-08-18');
  assert.equal(initial.dateDistance, 1);
  assert.match(initial.notice, /比今天早 1 天/);

  const switched = helpers.resolveRevenueCockpitScope({
    scopePayload,
    requestedPlatform: 'all_ota',
    requestedDate: '2026-08-20',
    resetDate: true,
    today: '2026-08-21',
  });
  assert.equal(switched.selectedPlatform, 'all_ota');
  assert.equal(switched.selectedDate, '2026-08-18');
  assert.equal(switched.previousDate, '2026-08-16');
  assert.deepEqual(Array.from(switched.dateOptions, (row) => row.value), ['2026-08-18', '2026-08-16']);

  const overviewReads = [];
  const snapshot = await helpers.loadRevenueCockpitSnapshot({
    hotelId: '80',
    reloadScope: true,
    today: '2026-08-21',
    readScope: async () => ({ code: 200, data: scopePayload }),
    readOverview: async (hotelId, businessDate, platform) => {
      overviewReads.push({ hotelId, businessDate, platform });
      return { hotel_id: Number(hotelId), business_date: businessDate };
    },
  });
  assert.equal(snapshot.status, 'ready');
  assert.equal(snapshot.selection.selectedDate, '2026-08-20');
  assert.deepEqual(Array.from(overviewReads, (row) => row.businessDate), ['2026-08-20', '2026-08-18']);
});

test('daily revenue cockpit shows verified zero, withholds unverified zero, and downloads the exact visible model', async () => {
  const source = ({ platform, date, facts, statuses, rowIds, dataStatus = 'readback_verified' }) => ({
    data_status: dataStatus,
    business_date: date,
    actual_business_date: date,
    facts,
    fact_statuses: statuses,
    source: {
      table: platform === 'dingdandao_pms' ? 'dingdandao_operating_target_captures' : 'online_daily_data',
      data_date: date,
      platform: platform === 'dingdandao_pms' ? undefined : platform,
      row_ids: platform === 'dingdandao_pms' ? undefined : rowIds,
      record_id: platform === 'dingdandao_pms' ? rowIds[0] : undefined,
      readback_status: dataStatus,
      source_trace_ids: rowIds.map((id) => `${platform}:${id}`),
    },
  });
  const ready = (keys) => Object.fromEntries(keys.map((key) => [key, { status: 'readback_verified' }]));
  const otaFacts = (revenue, orders, roomNights) => ({
    revenue,
    orders,
    room_nights: roomNights,
    adr: roomNights > 0 ? revenue / roomNights : null,
    list_exposure: 0,
    detail_exposure: 25,
    flow_rate_percent: 0,
    submit_rate_percent: 4,
    cancellation_rate_percent: 0,
  });
  const otaStatuses = {
    ...ready(['revenue', 'orders', 'room_nights', 'list_exposure', 'detail_exposure', 'flow_rate_percent', 'submit_rate_percent', 'cancellation_rate_percent']),
    adr: { status: 'derived_verified', formula: 'ota_room_revenue / ota_room_nights' },
  };
  const strictPlatform = (rowIds) => ({
    source_strict_readback: true,
    accepted_row_ids: rowIds,
    rejected_row_ids: [],
    metrics: Object.fromEntries([
      'revenue', 'orders', 'room_nights', 'adr', 'list_exposure',
      'detail_exposure', 'flow_rate_percent', 'submit_rate_percent',
      'cancellation_rate_percent',
    ].map((key) => [key, {
      strict_readback: true,
      accepted_row_ids: rowIds,
      rejected_row_ids: [],
    }])),
  });
  const overviewFor = (date, ctripRevenue, meituanRevenue) => ({
    hotel_id: 80,
    business_date: date,
    cockpit_strict_evidence: {
      contract_version: 'revenue_cockpit_strict_evidence.v1',
      strict_gate: 'history_success+validation_verified+readback_verified',
      tenant_id: 9,
      hotel_id: 80,
      business_date: date,
      platforms: {
        ctrip: strictPlatform([101, 102]),
        meituan: strictPlatform([201]),
      },
    },
    three_source_fact_layer: {
      business_date: date,
      hotel: { tenant_id: 9, system_hotel_id: 80, name: '真实验收酒店' },
      sources: {
        dingdandao_pms: source({
          platform: 'dingdandao_pms',
          date,
          facts: { room_revenue: 0, sold_room_nights: 0, occupancy_rate_percent: 0, adr: 0, revpar: 0 },
          statuses: Object.fromEntries(['room_revenue', 'sold_room_nights', 'occupancy_rate_percent', 'adr', 'revpar'].map((key) => [key, {
            status: 'not_verified',
            reason: 'dingdandao_pms_not_readback_verified',
          }])),
          rowIds: [],
          dataStatus: 'not_verified',
        }),
        ctrip_ota: source({
          platform: 'ctrip',
          date,
          facts: otaFacts(ctripRevenue, 0, 2),
          statuses: otaStatuses,
          rowIds: [101, 102],
        }),
        meituan_ota: source({
          platform: 'meituan',
          date,
          facts: otaFacts(meituanRevenue, 2, 3),
          statuses: otaStatuses,
          rowIds: [201],
        }),
      },
      analysis_gaps: [{
        code: 'dingdandao_pms_not_readback_verified',
        source: 'dingdandao_pms',
        status: 'not_verified',
        next_action: '补齐 PMS 同店同日事实并严格回读。',
      }],
    },
  });
  const scope = {
    selectedPlatform: 'all_ota',
    selectedDate: '2026-08-20',
    previousDate: '2026-08-18',
    sameWeekdayDate: '2026-08-13',
    notice: '携程 + 美团当前业务日 2026-08-20，比今天早 1 天。',
  };
  const model = helpers.buildRevenueCockpitModel({
    overview: overviewFor('2026-08-20', 200, 300),
    comparisonOverview: overviewFor('2026-08-18', 150, 280),
    sameWeekdayOverview: overviewFor('2026-08-13', 180, 260),
    scope,
    selectedPlatform: 'all_ota',
    businessDate: '2026-08-20',
    today: '2026-08-21',
  });

  assert.equal(model.status, 'partial');
  assert.equal(model.contractVersion, 'revenue_daily_cockpit.v2');
  assert.equal(model.canSaveSnapshot, true);
  assert.match(model.dateNotice, /比今天早 1 天/);
  assert.match(model.scopeBoundary, /不同来源收入不相加/);
  const allCards = model.visibleSections.flatMap((section) => section.cards);
  assert.equal(allCards.find((card) => card.key === 'dingdandao_pms:room_revenue').display, '—');
  assert.equal(allCards.find((card) => card.key === 'ctrip_ota:orders').display, '0 单');
  assert.equal(allCards.find((card) => card.key === 'ctrip_ota:list_exposure').display, '0 次');
  assert.equal(allCards.find((card) => card.key === 'ctrip_ota:revenue').display, '¥200.00');
  assert.equal(allCards.find((card) => card.key === 'meituan_ota:revenue').display, '¥300.00');
  assert.equal(allCards.some((card) => card.key.includes('combined')), false);
  assert.equal(allCards.find((card) => card.key === 'compare:ctrip_ota:revenue').display, '+¥50.00（+33.33%）');
  assert.match(allCards.find((card) => card.key === 'compare:ctrip_ota:revenue').reasonText, /同一指标与同一单位/);
  assert.equal(allCards.find((card) => card.key === 'compare-same_weekday:ctrip_ota:revenue').display, '+¥20.00（+11.11%）');
  assert.equal(model.comparisonFrames.find((frame) => frame.key === 'same_campaign_stage').status, 'missing_campaign_identity');
  assert.deepEqual(Array.from(model.opportunities, (item) => item.opportunityKey).sort(), [
    'bookability_gap',
    'cancellation_anomaly',
    'detail_conversion_shortage',
    'price_competition_position',
    'promotion_incrementality_evidence',
    'service_promise_risk',
    'submit_payment_conversion_shortage',
    'traffic_entry_shortage',
  ]);
  assert.deepEqual(Array.from(model.opportunities, (item) => item.rank), [1, 2, 3, 4, 5, 6, 7, 8]);
  assert.equal(model.opportunities.every((item) => item.causalityClaimed === false), true);
  assert.equal(model.opportunities.find((item) => item.opportunityKey === 'promotion_incrementality_evidence').priorityScore, null);
  assert.equal(model.canCreatePendingApproval, true);

  const expectedLabels = Array.from(model.visibleSections).flatMap(
    (section) => Array.from(section.cards, (card) => card.label),
  );
  const rows = helpers.buildRevenueCockpitDownloadRows(model);
  assert.deepEqual(Array.from(rows, (row) => row.card), expectedLabels);
  assert.equal(rows.every((row) => row.source && row.business_date && row.verification_status && row.missing_state), true);
  const csv = helpers.buildRevenueCockpitCsv(model);
  assert.match(csv, /"页面显示"/);
  assert.match(csv, /"携程渠道订单金额"/);
  assert.match(csv, /"¥200.00"/);
  assert.equal(csv.split('\r\n').length, rows.length + 1);
  const download = helpers.buildRevenueCockpitDownloadPayload(model, '备用酒店');
  assert.equal(download.ok, true);
  assert.deepEqual(Array.from(download.rows, (row) => row.card), expectedLabels);
  assert.match(download.fileName, /^经营驾驶舱_真实验收酒店_2026-08-20_all_ota\.csv$/);
  const question = helpers.buildRevenueCockpitQuestionDraft(model, 80);
  assert.equal(question.ok, true);
  assert.equal(question.hotelId, '80');
  assert.equal(question.platform, 'all_ota');
  assert.equal(question.decisionObject, 'channel');
  assert.match(question.question, /存在数据缺口/);
  assert.match(
    helpers.buildRevenueCockpitOverviewEndpoint(80, '2026-08-20', 'all_ota'),
    /hotel_id=80&business_date=2026-08-20&cockpit=1&platform=all_ota&enabled_channels=ctrip%2Cmeituan/,
  );
  const overviewResponse = helpers.resolveRevenueCockpitOverviewResponse({
    code: 200,
    data: overviewFor('2026-08-20', 200, 300),
  }, { hotelId: 80, businessDate: '2026-08-20', platform: 'all_ota' });
  assert.equal(overviewResponse.ok, true);
  assert.equal(helpers.resolveRevenueCockpitOverviewResponse({
    code: 200,
    data: overviewFor('2026-08-18', 200, 300),
  }, { hotelId: 80, businessDate: '2026-08-20', platform: 'all_ota' }).ok, false);
  assert.equal(helpers.resolveRevenueCockpitScopeResponse({
    code: 200,
    data: {
      contract_version: 'operating_question_scope_options.v1',
      hotel_id: 80,
      boundary: { silent_date_fallback: false },
    },
  }, 80).ok, true);

  const decisionSnapshot = {
    id: 777,
    tenant_id: 9,
    contract_version: 'revenue_decision_snapshot.v1',
    persistence_status: 'readback_verified',
    evidence_identity_status: 'matched_current',
    system_hotel_id: 80,
    business_date: '2026-08-20',
    platform: 'all_ota',
    visible_model: model,
    visible_model_digest: 'a'.repeat(64),
    evidence_digest: 'b'.repeat(64),
    content_digest: 'c'.repeat(64),
  };
  const snapshotRequests = [];
  const savedSnapshot = await helpers.saveRevenueDecisionSnapshotWithReadback({
    model,
    hotelId: 80,
    request: async (url, options = {}) => {
      snapshotRequests.push({ url, method: options.method || 'GET' });
      return options.method === 'POST'
        ? { code: 200, data: decisionSnapshot }
        : { code: 200, data: { found: true, persistence_status: 'readback_verified', snapshot: decisionSnapshot } };
    },
  });
  assert.equal(savedSnapshot.ok, true);
  assert.equal(savedSnapshot.snapshot.id, 777);
  assert.deepEqual(Array.from(snapshotRequests, (item) => item.method), ['POST', 'GET']);
  assert.equal(snapshotRequests[0].url, '/revenue-ai/cockpit/decision-snapshots');
  assert.match(snapshotRequests[1].url, /snapshot_id=777/);
  const restoredSnapshot = await helpers.restoreRevenueDecisionSnapshotWithReadback({
    model,
    hotelId: 80,
    request: async () => ({
      code: 200,
      data: { found: true, persistence_status: 'readback_verified', snapshot: decisionSnapshot },
    }),
  });
  assert.equal(restoredSnapshot.ok, true);
  assert.equal(restoredSnapshot.status, 'matched_current');

  const opportunityKey = 'promotion_incrementality_evidence';
  const selectedOpportunity = model.opportunities.find((item) => item.opportunityKey === opportunityKey);
  const recommendationDigest = 'd'.repeat(64);
  const managedActionCard = {
    contract_version: 'operation_action_card.v1',
    content_digest: 'e'.repeat(64),
    metric_contract: { target_type: 'observation' },
    action: {
      title: selectedOpportunity.title,
      description: selectedOpportunity.recommendedCheckAction,
    },
    trace: {
      decision_snapshot_id: 777,
      decision_snapshot_digest: decisionSnapshot.content_digest,
      opportunity_key: opportunityKey,
      opportunity_digest: recommendationDigest,
    },
  };
  const genericManagedActionCard = {
    contract_version: 'operation_action_card.v1',
    content_digest: 'f'.repeat(64),
    metric_contract: { target_type: 'observation' },
    action: { title: '核查经营事实', description: '由负责人核查同店同日事实' },
    trace: {},
  };
  const opportunityRequests = [];
  const opportunityApproval = await helpers.createRevenueOpportunityPendingApprovalWithReadback({
    snapshot: decisionSnapshot,
    opportunityKey,
    request: async (url) => {
      opportunityRequests.push(url);
      return url.includes('/pending-approval')
        ? {
            code: 200,
            data: {
              status: 'pending_approval',
              persistence_status: 'readback_verified',
              execution_task_created: false,
              execution_task_count: 0,
              external_action_triggered: false,
              execution_intent: { id: 990, status: 'pending_approval', tasks: [] },
              snapshot: { id: 777, content_digest: decisionSnapshot.content_digest },
              opportunity: {
                opportunity_key: opportunityKey,
                recommendation_digest: recommendationDigest,
                title: selectedOpportunity.title,
                action_text: selectedOpportunity.recommendedCheckAction,
              },
            },
          }
        : {
            code: 200,
            data: {
              id: 990,
              tenant_id: 9,
              hotel_id: 80,
              platform: 'all_ota',
              source_module: 'revenue_cockpit_action',
              object_type: 'operation_checklist',
              action_type: 'human_reviewed_operating_check',
              date_start: '2026-08-20',
              date_end: '2026-08-20',
              status: 'pending_approval',
              tasks: [],
              target_value: {
                decision_context: {
                  snapshot_id: 777,
                  snapshot_digest: decisionSnapshot.content_digest,
                  opportunity_key: opportunityKey,
                  recommendation_digest: recommendationDigest,
                },
                action_card: managedActionCard,
              },
            },
          };
    },
  });
  assert.equal(opportunityApproval.ok, true);
  assert.equal(opportunityApproval.intent.id, 990);
  assert.deepEqual(Array.from(opportunityRequests), [
    '/revenue-ai/cockpit/decision-snapshots/777/pending-approval',
    '/operation/execution-intents/990',
  ]);
  assert.equal(opportunityApproval.intent.tasks.length, 0);
  assert.equal(helpers.resolveRevenueDecisionSnapshot({
    ...decisionSnapshot,
    content_digest: 'invalid',
  }, { hotelId: 80, businessDate: '2026-08-20', platform: 'all_ota' }).ok, false);

  const savedApproval = helpers.resolveRevenueCockpitPendingApprovalSave({
    status: 'pending_approval',
    persistence_status: 'readback_verified',
    execution_task_created: false,
    execution_task_count: 0,
    external_action_triggered: false,
    execution_intent: { id: 901, status: 'pending_approval', tasks: [] },
  });
  assert.equal(savedApproval.ok, true);
  assert.equal(savedApproval.intentId, 901);
  const exactApproval = helpers.resolveRevenueCockpitPendingApprovalReadback({
    id: 901,
    tenant_id: 9,
    hotel_id: 80,
    platform: 'all_ota',
    source_module: 'operating_loop_approval',
    object_type: 'operation_checklist',
    action_type: 'human_reviewed_operating_check',
    date_start: '2026-08-20',
    date_end: '2026-08-20',
    status: 'pending_approval',
    tasks: [],
    target_value: { action_card: genericManagedActionCard },
  }, {
    intentId: 901,
    tenantId: 9,
    hotelId: 80,
    platform: 'all_ota',
    businessDate: '2026-08-20',
  });
  assert.equal(exactApproval.ok, true);
  assert.equal(helpers.revenueCockpitTaskCardinalityIsValid('pending_approval', []), true);
  assert.equal(helpers.revenueCockpitTaskCardinalityIsValid('pending_approval', [{ id: 1 }]), false);
  assert.equal(helpers.revenueCockpitTaskCardinalityIsValid('approved', [{ id: 1 }]), true);
  assert.equal(helpers.revenueCockpitTaskCardinalityIsValid('approved', []), false);
  assert.equal(helpers.revenueCockpitTaskCardinalityIsValid('approved', [{ id: 1 }, { id: 2 }]), false);
  assert.equal(helpers.resolveRevenueCockpitPendingApprovalSave({ execution_intent: { id: 902 } }).ok, false);
  assert.equal(helpers.resolveRevenueCockpitPendingApprovalReadback({
    id: 901,
    tenant_id: 9,
    hotel_id: 80,
    platform: 'all_ota',
    source_module: 'operating_loop_approval',
    object_type: 'operation_checklist',
    action_type: 'human_reviewed_operating_check',
    date_start: '2026-08-20',
    date_end: '2026-08-20',
    status: 'pending_approval',
    tasks: [{ id: 1 }],
    target_value: { action_card: genericManagedActionCard },
  }, {
    intentId: 901,
    tenantId: 9,
    hotelId: 80,
    platform: 'all_ota',
    businessDate: '2026-08-20',
  }).ok, false);
  assert.equal(helpers.resolveRevenueCockpitPendingApprovalReadback({
    id: 901,
    tenant_id: 9,
    hotel_id: 80,
    platform: 'all_ota',
    source_module: 'revenue_cockpit_action',
    object_type: 'operation_checklist',
    action_type: 'human_reviewed_operating_check',
    date_start: '2026-08-20',
    date_end: '2026-08-20',
    status: 'approved',
  }, { intentId: 901, hotelId: 80, businessDate: '2026-08-20', requirePending: false }).ok, false);
  assert.equal(helpers.resolveRevenueCockpitPendingApprovalReadback({
    id: 901,
    tenant_id: 9,
    hotel_id: 80,
    platform: 'all_ota',
    source_module: 'revenue_cockpit_action',
    object_type: 'operation_checklist',
    action_type: 'human_reviewed_operating_check',
    date_start: '2026-08-20',
    date_end: '2026-08-20',
    status: 'approved',
    tasks: [{ id: 501, status: 'pending_execute', result_status: 'observing' }],
    target_value: { action_card: genericManagedActionCard },
  }, {
    intentId: 901,
    hotelId: 80,
    businessDate: '2026-08-20',
    requirePending: false,
    status: 'approved',
    taskCount: 1,
    taskSignatures: [{ id: 999, status: 'pending_execute', result_status: 'observing' }],
  }).ok, false);

  const restoreRequests = [];
  const restoredApproval = await helpers.restoreRevenueCockpitPendingApprovalWithReadback({
    model,
    hotelId: 80,
    request: async (url) => {
      restoreRequests.push(url);
      if (url.startsWith('/revenue-ai/cockpit/pending-approval?')) {
        return {
          code: 200,
          data: {
            found: true,
            status: 'approved',
            persistence_status: 'readback_verified',
            execution_task_count: 1,
            execution_intent_count: 2,
            cockpit_scope: {
              tenant_id: 9,
              hotel_id: 80,
              business_date: '2026-08-20',
              platform: 'all_ota',
            },
            execution_intent: {
              id: 901,
              tenant_id: 9,
              hotel_id: 80,
              platform: 'all_ota',
              source_module: 'revenue_cockpit_action',
              object_type: 'operation_checklist',
              action_type: 'human_reviewed_operating_check',
              date_start: '2026-08-20',
              date_end: '2026-08-20',
              status: 'approved',
              tasks: [{ id: 501, status: 'pending_execute', result_status: 'observing' }],
              target_value: { action_card: genericManagedActionCard },
            },
            execution_intents: [
              {
                id: 901,
                tenant_id: 9,
                hotel_id: 80,
                platform: 'all_ota',
                source_module: 'revenue_cockpit_action',
                object_type: 'operation_checklist',
                action_type: 'human_reviewed_operating_check',
                date_start: '2026-08-20',
                date_end: '2026-08-20',
                status: 'approved',
                tasks: [{ id: 501, status: 'pending_execute', result_status: 'observing' }],
                target_value: { action_card: genericManagedActionCard },
              },
              {
                id: 902,
                tenant_id: 9,
                hotel_id: 80,
                platform: 'all_ota',
                source_module: 'operating_question',
                object_type: 'operation_checklist',
                action_type: 'human_reviewed_operating_check',
                date_start: '2026-08-20',
                date_end: '2026-08-20',
                status: 'cancelled',
                tasks: [],
                target_value: { action_card: genericManagedActionCard },
              },
            ],
          },
        };
      }
      const readbackId = Number(url.split('/').pop());
      return {
        code: 200,
        data: readbackId === 902 ? {
          id: 902,
          tenant_id: 9,
          hotel_id: 80,
          platform: 'all_ota',
          source_module: 'operating_question',
          object_type: 'operation_checklist',
          action_type: 'human_reviewed_operating_check',
          date_start: '2026-08-20',
          date_end: '2026-08-20',
          status: 'cancelled',
          tasks: [],
          target_value: { action_card: genericManagedActionCard },
        } : {
          id: 901,
          tenant_id: 9,
          hotel_id: 80,
          platform: 'all_ota',
          source_module: 'revenue_cockpit_action',
          object_type: 'operation_checklist',
          action_type: 'human_reviewed_operating_check',
          date_start: '2026-08-20',
          date_end: '2026-08-20',
          status: 'approved',
          tasks: [{ id: 501, status: 'pending_execute', result_status: 'observing' }],
          target_value: { action_card: genericManagedActionCard },
        },
      };
    },
  });
  assert.equal(restoredApproval.ok, true);
  assert.equal(restoredApproval.status, 'readback_verified');
  assert.equal(restoredApproval.intent.status, 'approved');
  assert.equal(restoredApproval.intent.tasks.length, 1);
  assert.equal(restoredApproval.intents.length, 2);
  assert.equal(restoredApproval.intents[1].status, 'cancelled');
  assert.match(restoreRequests[0], /^\/revenue-ai\/cockpit\/pending-approval\?/);
  assert.match(restoreRequests[0], /hotel_id=80/);
  assert.match(restoreRequests[0], /business_date=2026-08-20/);
  assert.match(restoreRequests[0], /platform=all_ota/);
  assert.equal(restoreRequests[1], '/operation/execution-intents/901');
  assert.equal(restoreRequests[2], '/operation/execution-intents/902');

  const notSavedApproval = await helpers.restoreRevenueCockpitPendingApprovalWithReadback({
    model,
    hotelId: 80,
    request: async () => ({
      code: 200,
      data: {
        found: false,
        status: 'not_saved',
        persistence_status: 'not_saved',
        execution_task_count: 0,
        execution_intent_count: 0,
        cockpit_scope: { tenant_id: 9, hotel_id: 80, business_date: '2026-08-20', platform: 'all_ota' },
        execution_intent: null,
        execution_intents: [],
      },
    }),
  });
  assert.equal(notSavedApproval.ok, true);
  assert.equal(notSavedApproval.status, 'not_saved');
  assert.equal(notSavedApproval.intent, null);

  const rejectedOverview = overviewFor('2026-08-20', 200, 300);
  rejectedOverview.cockpit_strict_evidence.platforms.meituan = {
    ...rejectedOverview.cockpit_strict_evidence.platforms.meituan,
    source_strict_readback: false,
    accepted_row_ids: [],
    rejected_row_ids: [201],
    metrics: Object.fromEntries(Object.entries(
      rejectedOverview.cockpit_strict_evidence.platforms.meituan.metrics,
    ).map(([key, item]) => [key, {
      ...item,
      strict_readback: false,
      accepted_row_ids: [],
      rejected_row_ids: [201],
    }])),
  };
  const rejectedModel = helpers.buildRevenueCockpitModel({
    overview: rejectedOverview,
    comparisonOverview: null,
    scope,
    selectedPlatform: 'meituan',
    businessDate: '2026-08-20',
    today: '2026-08-21',
  });
  const rejectedCards = rejectedModel.visibleSections.flatMap((section) => section.cards);
  const rejectedRevenue = rejectedCards.find((card) => card.key === 'meituan_ota:revenue');
  assert.equal(rejectedRevenue.display, '—');
  assert.equal(rejectedRevenue.value, null);
  assert.equal(rejectedRevenue.statusLabel, '未验证');
  assert.match(rejectedRevenue.reasonText, /严格事实闸门/);
  assert.match(rejectedRevenue.evidenceLines.join('；'), /拒绝 #201/);
  assert.equal(rejectedCards.some((card) => card.key.includes('ctrip_ota')), false);
  assert.deepEqual(Array.from(rejectedModel.sourceRecords, (record) => record.platform), ['meituan']);
  assert.equal(rejectedModel.opportunities.every((item) => (
    item.platformSignals.every((signal) => signal.platform === 'meituan')
  )), true);
  assert.equal(rejectedModel.canCreatePendingApproval, false);

  const loadingModel = helpers.buildRevenueCockpitModel({ loading: true });
  assert.equal(loadingModel.status, 'loading');
  assert.equal(loadingModel.visibleSections.length, 0);
  assert.equal(loadingModel.canCreatePendingApproval, false);
  const failedModel = helpers.buildRevenueCockpitModel({ error: 'strict readback failed' });
  assert.equal(failedModel.status, 'failed');
  assert.match(failedModel.summary, /strict readback failed/);
  assert.equal(failedModel.visibleSections.length, 0);
  const emptyModel = helpers.buildRevenueCockpitModel({
    scope: { notice: '没有严格可用日期' },
    selectedPlatform: 'meituan',
  });
  assert.equal(emptyModel.status, 'empty');
  assert.match(emptyModel.summary, /没有严格可用日期/);
  assert.equal(emptyModel.canAskQuestion, false);
});

test('daily revenue cockpit template exposes unified context, evidence, download and human-gated handoffs', () => {
  const cockpitRuntime = readFileSync('public/revenue-ai-static.js', 'utf8');
  for (const marker of [
    'data-testid="revenue-daily-cockpit"',
    'data-testid="revenue-cockpit-hotel"',
    'data-testid="revenue-cockpit-platform"',
    'data-testid="revenue-cockpit-business-date"',
    'data-testid="revenue-cockpit-download"',
    'data-testid="revenue-cockpit-loading"',
    'data-testid="revenue-cockpit-error"',
    'data-testid="revenue-cockpit-empty"',
    'data-testid="revenue-cockpit-evidence"',
    'data-testid="revenue-cockpit-save-snapshot"',
  ]) {
    assert.ok(appTemplate.includes(marker), `${marker} must remain in the generated template snapshot`);
  }
  for (const marker of [
    "'data-testid': 'revenue-cockpit-restored-action'",
    "'data-testid': 'revenue-cockpit-restored-action-open'",
    "'data-testid': 'revenue-cockpit-restored-action-error'",
  ]) {
    assert.ok(appMain.includes(marker), `${marker} must remain in the registered restore-status component`);
  }
  assert.match(cockpitRuntime, /buildRevenueCockpitCsv/);
  assert.match(appMain, /loadRevenueCockpitSnapshot/);
  assert.match(cockpitRuntime, /buildRevenueCockpitOverviewEndpoint/);
  assert.match(cockpitRuntime, /cockpit:\s*'1'/);
  assert.match(appMain, /saveRevenueDecisionSnapshotWithReadback/);
  assert.match(appMain, /restoreRevenueDecisionSnapshotWithReadback/);
  assert.match(appMain, /createRevenueOpportunityPendingApprovalWithReadback/);
  assert.match(appMain, /restoreRevenueCockpitPendingApprovalWithReadback/);
  assert.match(
    appTemplate,
    /loadRevenueCockpit\(\{ reloadScope: true, ignoreSavedSnapshot: true \}\)/,
    'refresh facts must bypass a restored snapshot and expose the current live model',
  );
  assert.match(
    appMain,
    /const matchedCurrent = String\(snapshot\?\.evidence_identity_status \|\| ''\) === 'matched_current'\s*&& revenueCockpitSnapshotReadbackStatus\.value === 'matched_current'/,
    'only a snapshot matched to current evidence may replace the live visible model',
  );
  assert.match(appMain, /const captureRevenueCockpitMutationContext = \(\) => \(\{/);
  assert.match(appMain, /const isRevenueCockpitMutationCurrent = \(context = \{\}\) => \(/);
  assert.match(
    appMain,
    /if \(!isRevenueCockpitMutationCurrent\(mutationContext\)\) return null;/,
    'snapshot save and opportunity handoff must ignore responses from an obsolete scope',
  );
  assert.match(
    appMain,
    /const ignoreSavedSnapshot = options\.ignoreSavedSnapshot === true\s*\|\| options\.ignoreSavedSnapshot === 1;/,
    'compiled template event booleans must be normalized because the render build emits numeric 1',
  );
  assert.match(
    appMain,
    /if \(ignoreSavedSnapshot\) \{\s*revenueCockpitDecisionSnapshot\.value = null;\s*revenueCockpitSnapshotError\.value = '';\s*revenueCockpitSnapshotReadbackStatus\.value = 'not_saved';/,
  );
  assert.match(
    appMain,
    /if \(ignoreSavedSnapshot\) \{\s*revenueCockpitPendingApproval\.value = null;\s*revenueCockpitOpportunityApprovals\.value = \{\};\s*revenueCockpitPendingApprovalError\.value = '';\s*revenueCockpitPendingApprovalReadbackStatus\.value = 'idle';/,
  );
  assert.match(appMain, /resolveRevenueCockpitIntentLifecycle/);
  assert.match(appMain, /let suppressNextOpsTrackAutoLoad = false/);
  assert.match(appMain, /if \(changingPage\) suppressNextOpsTrackAutoLoad = true/);
  assert.match(appMain, /loadOperationActions\(\{ focusIntentId: intentId \}\)/);
  assert.match(appMain, /action_management\?\.latest_review/);
  assert.match(appMain, /runPageLoadOnce\(newPage, 'revenue-cockpit-lifecycle'/);
  assert.match(cockpitRuntime, /execution_task_created !== false/);
  assert.match(cockpitRuntime, /external_action_triggered !== false/);
  assert.match(cockpitRuntime, /requirePending && tasks\.length !== 0/);
  assert.match(appTemplate, /<revenue-cockpit-snapshot-status/);
  assert.match(appTemplate, /<revenue-cockpit-opportunity-details/);
  assert.match(appMain, /'data-testid': 'revenue-cockpit-snapshot-readback'/);
  assert.match(appMain, /'data-testid': 'revenue-cockpit-opportunity-chain'/);
  assert.match(appMain, /card\.causalityClaimed \? '已声明' : '未声明'/);
  assert.match(appMain, /自动审批\/调价\/OTA 写入：否/);
  assert.match(systemStatic, /name: '可信收益分析', path: 'trusted-revenue-analysis'/);
  assert.match(appMain, /item\?\.path === 'trusted-revenue-analysis'/);
  assert.match(appMain, /sourcePath: 'trusted-revenue-analysis', overrides: \{ name: '可信收益分析' \}/);
  assert.ok(
    routeApp.indexOf("Route::post('/cockpit/decision-snapshots/:id/pending-approval'")
      < routeApp.indexOf("Route::post('/cockpit/decision-snapshots', 'RevenueAi/createCockpitDecisionSnapshot'") ,
    'the parameterized pending-approval route must be registered before the generic snapshot POST route',
  );
  assert.match(
    appTemplate,
    /data-testid="revenue-cockpit-hotel"[\s\S]{0,600}\{\{ item\.name \}\}/,
    'cockpit hotel selector must render the actual hotel option name',
  );
});
