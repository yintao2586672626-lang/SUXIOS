import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');

const agentPage = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const appMain = read('public/app-main.js');
const systemStatic = read('public/system-static.js');
const operatingIntelligenceComponents = read('public/components/system/operating-intelligence-components.js');
const hotelDataAnalystComponents = read('public/components/system/hotel-data-analyst-components.js');
const operatingComponents = `${operatingIntelligenceComponents}\n${hotelDataAnalystComponents}`;
const operatingLoader = read('public/components/system/operating-intelligence-loader.js');
const questionService = read('app/service/OperatingQuestionService.php');
const answerService = read('app/service/OperatingQuestionAiAnswerService.php');
const qualityService = read('app/service/HotelDataAnalystQualityReceiptService.php');
const feedbackService = read('app/service/HotelDataAnalystFeedbackService.php');
const feedbackProjectionService = read('app/service/HotelDataAnalystFeedbackProjectionService.php');
const feedbackController = read('app/controller/HotelDataAnalystFeedback.php');
const routes = `${read('route/app.php')}\n${read('route/domain/agent_guidance.php')}`;

const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0 && end > start, `missing helper markers: ${startMarker}`);
  return source.slice(start, end);
};

const preciseMetricHelpers = sliceBetween(
  operatingComponents,
  '// PRECISE_METRIC_SET_HELPERS_START',
  '// PRECISE_METRIC_SET_HELPERS_END',
);
const { normalizePreciseMetricSet } = new Function(
  `${preciseMetricHelpers}\nreturn { normalizePreciseMetricSet };`,
)();
const qualityUiHelpers = sliceBetween(
  operatingComponents,
  '// HOTEL_DATA_ANALYST_QUALITY_UI_START',
  '// HOTEL_DATA_ANALYST_QUALITY_UI_END',
);
const { normalizeHotelDataAnalystQualityReceipt } = new Function(
  `${qualityUiHelpers}\nreturn { normalizeHotelDataAnalystQualityReceipt };`,
)();

test('hotel data analyst is a findable role backed by the existing saved-evidence entry', () => {
  assert.match(systemStatic, /key:\s*'overview',\s*name:\s*'酒店数据分析师'/);
  assert.match(agentPage, /<hotel-data-analyst-profile/);
  assert.match(appMain, /app\.component\('HotelDataAnalystProfile', hotelDataAnalystProfile\)/);
  assert.match(operatingLoader, /hotelDataAnalystProfile:\s*buildLazyComponent\('hotelDataAnalystProfile'/);
  assert.match(operatingLoader, /hotel-data-analyst-components\.js\?v=/);
  assert.match(operatingLoader, /operating-intelligence-components\.js\?v=[^'"]+/);
  assert.match(operatingComponents, /data-testid': 'hotel-data-analyst-role'/);
  assert.match(operatingComponents, /data-role-key': 'hotel_data_analyst'/);
  assert.match(operatingComponents, /data-contract-version': 'hotel_data_analyst\.v1'/);
  assert.match(operatingComponents, /经营指标诊断/);
  assert.match(operatingComponents, /趋势与渠道对比/);
  assert.match(operatingComponents, /异常与缺口识别/);
  assert.match(operatingComponents, /管理层分析摘要/);
  assert.match(agentPage, /<oq><\/oq>/);
});

test('role examples fill the same hotel-scoped analysis composer instead of a demo surface', () => {
  for (const marker of [
    '当前选择范围最需要复核的经营指标是什么？请列出证据和缺口。',
    '分析当前选择日期的曝光、浏览到下单链路，缺失指标不要补零。',
    '对比当前范围的关键指标，区分事实、异常信号和可能解释。',
    '基于当前可信事实生成一份管理层可读的简短酒店数据分析。',
  ]) {
    assert.match(operatingComponents, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
  }
  assert.match(agentPage, /<hotel-data-analyst-profile><\/hotel-data-analyst-profile>/);
  assert.match(operatingComponents, /HOTEL_DATA_ANALYST_SUGGESTIONS\.map/);
  assert.match(operatingComponents, /selectSuggestion\(question\)/);
  assert.match(operatingComponents, /hotel-data-analyst-question-input/);
  assert.match(operatingComponents, /scrollIntoView\?\.\(\{ behavior: 'smooth', block: 'center' \}\)/);
  assert.match(operatingComponents, /hotel-data-analyst-question-input/);
  assert.match(operatingComponents, /hotel-data-analyst-submit/);
  assert.match(operatingComponents, /ui\?\.ask\?\.\(\)/);
  assert.match(operatingComponents, /key:\s*'report',\s*label:\s*'数据分析师'/);
  assert.match(operatingComponents, /option\.key === 'report' \? 'hotel_data_analyst'/);
  assert.match(operatingComponents, /data-analysis-quality-status/);
  assert.match(operatingComponents, /operating-question-quality-receipt/);
  assert.match(operatingComponents, /system-guide-analysis-quality-receipt/);
});

test('role output remains evidence-gated, saved, exactly readable and non-executing', () => {
  assert.match(questionService, /blocked_by_missing_facts/);
  assert.match(questionService, /persistence_status'\s*=>\s*'readback_verified'/);
  assert.match(questionService, /public function read\(/);
  assert.match(questionService, /source_scope'\s*=>\s*'ota_channel'/);
  assert.match(questionService, /analysis_quality_receipt/);
  assert.match(appMain, /状态未识别，结论受限/);
  assert.match(qualityService, /hotel_data_analyst_quality_receipt\.v1/);
  assert.match(qualityService, /external_action_authorized'\s*=>\s*false/);
  assert.match(answerService, /只能使用输入中同一租户、同一酒店、同一平台和日期范围内的已保存证据/);
  assert.match(answerService, /不得补造指标、确定原因、全酒店结论、竞对结论、执行结果或ROI/);
  assert.match(answerService, /不得改价、改库存、创建任务、外发消息/);
  assert.match(operatingComponents, /缺失值不补零，未验证数值只供审计且不进入结论/);
  assert.match(operatingComponents, /OTA渠道事实不扩大为全酒店结论/);
  assert.match(operatingComponents, /建议仅供人工确认，不自动执行/);
});

test('human feedback is append-only, exactly readable and detached from formal evaluation or actions', () => {
  assert.match(routes, /operating-questions\/:questionId\/feedbacks\/mine/);
  assert.match(routes, /operating-questions\/:questionId\/feedbacks\/:feedbackId/);
  assert.match(routes, /operating-questions\/:questionId\/feedbacks/);
  assert.match(feedbackService, /hotel_data_analyst_feedback\.v1/);
  assert.match(feedbackService, /eval_candidate_only_no_training/);
  assert.match(feedbackService, /source_content_digest/);
  assert.match(feedbackService, /quality_receipt_digest/);
  assert.match(feedbackService, /analysis_snapshot_drift/);
  assert.ok(
    feedbackService.indexOf('$existing = $this->findByIdempotency')
      < feedbackService.indexOf("throw new RuntimeException('analysis_snapshot_drift'"),
    'an exact idempotent retry must replay before a new snapshot freshness check',
  );
  assert.match(feedbackService, /original_analysis_mutated'\s*=>\s*false/);
  assert.match(feedbackProjectionService, /review_status'\s*=>\s*'candidate_only'/);
  assert.match(feedbackProjectionService, /formal_evaluation_case_created'\s*=>\s*false/);
  assert.match(feedbackProjectionService, /model_training_triggered'\s*=>\s*false/);
  assert.match(feedbackProjectionService, /external_model_called'\s*=>\s*false/);
  assert.match(feedbackProjectionService, /blocked_by_sensitive_replay_input/);
  assert.match(feedbackProjectionService, /php\[_-\]\?sessid/);
  assert.doesNotMatch(feedbackService, /->update\s*\(/);
  assert.doesNotMatch(feedbackService, /->delete\s*\(/);
  assert.match(feedbackController, /OperationLog::record/);
  assert.doesNotMatch(feedbackController, /correction_text/);
  assert.match(appMain, /hotelDataAnalystFeedbackRequest:\s*apiRequest/);
  assert.match(operatingComponents, /loadOperatingQuestionQualityFeedback/);
  assert.match(operatingComponents, /saveOperatingQuestionQualityFeedback/);
  assert.match(operatingComponents, /反馈保存后原分析记录发生漂移/);
  assert.match(operatingComponents, /operating-question-quality-feedback/);
  assert.match(operatingComponents, /system-guide-analysis-quality-feedback/);
  assert.match(operatingComponents, /interactive:\s*isLatest\s*&&\s*widgetOpen\.value/);
  assert.match(operatingComponents, /只追加反馈，不改原文，不自动执行/);
  assert.match(operatingComponents, /aria-label': '分析质量反馈'/);
  assert.match(operatingComponents, /aria-live': 'polite'/);
  assert.match(operatingComponents, /查看最近反馈记录/);
  assert.match(operatingComponents, /data-original-analysis-mutated/);
  assert.match(operatingComponents, /data-formal-evaluation-case-created/);
  assert.match(operatingComponents, /data-model-training-triggered/);
  assert.match(operatingComponents, /data-external-action-authorized/);
});

test('hotel data analyst blocks a numeric card without strict verification and readback proof', () => {
  const unverified = normalizePreciseMetricSet({
    precise_result: {
      metric_set: {
        contract_version: 'suxios.precise_metric_set.v1',
        kind: 'operating_metric_set',
        items: [{
          metric: { key: 'list_exposure', name: '曝光人数' },
          status: 'ready',
          value: 1422,
          unit: 'people',
          source_record: 'online_daily_data#102476',
          verification_status: 'unverified',
          readback_status: 'readback_verified',
        }],
      },
    },
  });
  assert.equal(unverified.readyCount, 0);
  assert.equal(unverified.blockedCount, 1);
  assert.equal(unverified.items[0].value, 1422, 'the observed value stays visible for audit');
  assert.equal(unverified.items[0].blocked, true, 'unverified value must not be promoted to a ready fact');
  assert.match(unverified.items[0].blockedReason, /readback_verified/);

  const verified = normalizePreciseMetricSet({
    precise_result: {
      metric_set: {
        contract_version: 'suxios.precise_metric_set.v1',
        kind: 'operating_metric_set',
        items: [{
          metric: { key: 'list_exposure', name: '曝光人数' },
          status: 'readback_verified',
          value: 1422,
          unit: 'people',
          source_record: 'online_daily_data#102476',
          verification_status: 'verified',
          readback_status: 'readback_verified',
        }],
      },
    },
  });
  assert.equal(verified.readyCount, 1);
  assert.equal(verified.blockedCount, 0);
});

test('analysis quality receipt is fail-closed and keeps claim readiness separate from contract quality', () => {
  const missing = normalizeHotelDataAnalystQualityReceipt(null);
  assert.equal(missing.status, 'blocked');
  assert.equal(missing.qualityStatus, 'failed');
  assert.equal(missing.claimStatus, 'blocked');

  const receipt = {
    contract_version: 'hotel_data_analyst_quality_receipt.v1',
    role_key: 'hotel_data_analyst',
    quality_status: 'passed',
    claim_status: 'limited',
    status: 'partial',
    status_label: '部分结果可用',
    summary: '已有严格可用部分，但仍有明确缺口。',
    readback_verified: true,
    external_action_authorized: false,
    subject_digest: 'a'.repeat(64),
    scope_digest: 'c'.repeat(64),
    evidence_digest: 'd'.repeat(64),
    receipt_digest: 'b'.repeat(64),
    check_count: 2,
    passed_count: 1,
    partial_count: 1,
    blocked_count: 0,
    checks: [
      { key: 'scope_identity', label: '范围身份', status: 'passed', message: '范围一致。' },
      { key: 'metric_integrity', label: '指标资格', status: 'partial', message: '一项待补证。' },
    ],
    next_actions: ['补齐指标来源。'],
    usage_policy: {
      verified_portion_usable: true,
      external_action_authorized: false,
      ota_write: false,
      pms_write: false,
      external_message: false,
      automatic_execution: false,
    },
  };
  const partial = normalizeHotelDataAnalystQualityReceipt(receipt);
  assert.equal(partial.status, 'partial');
  assert.equal(partial.qualityStatus, 'passed');
  assert.equal(partial.claimStatus, 'limited');
  assert.equal(partial.verifiedPortionUsable, true);

  const contradictory = normalizeHotelDataAnalystQualityReceipt({
    ...receipt,
    status: 'ready',
    external_action_authorized: true,
  });
  assert.equal(contradictory.status, 'blocked');
  assert.equal(contradictory.qualityStatus, 'failed');
  assert.equal(contradictory.invalidReason, 'analysis_quality_receipt_contract_invalid');
});
