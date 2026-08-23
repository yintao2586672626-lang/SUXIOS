import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const migration = read('database/migrations/20260802_extend_operating_intelligence.sql');
const questions = read('app/service/OperatingQuestionService.php');
const aiAnswers = read('app/service/OperatingQuestionAiAnswerService.php');
const knowledgeRetrieval = read('app/service/OperatingQuestionKnowledgeRetrievalService.php');
const systemGuidance = read('app/service/SystemUsageAssistantService.php');
const systemGuidanceController = read('app/controller/SystemGuidance.php');
const llmClient = read('app/service/LlmClient.php');
const agent = read('app/controller/Agent.php');
const agentBuild = read('app/controller/concern/AgentOtaDiagnosisBuildConcern.php');
const agentPersistence = read('app/controller/concern/AgentOtaDiagnosisPersistenceConcern.php');
const sops = read('app/service/OperatingSopService.php');
const controller = read('app/controller/OperatingIntelligence.php');
const routes = read('route/app.php');
const operatingIntelligenceComponents = read('public/components/system/operating-intelligence-components.js');
const appMain = read('public/app-main.js');
const frontend = `${appMain}\n${operatingIntelligenceComponents}`;
const agentPage = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const globalShell = read('resources/frontend/templates/fragments/46-global-toast.html');
const style = read('public/style.css');
const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0, `missing start marker: ${startMarker}`);
  assert.ok(end > start, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};
const systemUsageGuideHelpers = sliceBetween(
  operatingIntelligenceComponents,
  '// SYSTEM_USAGE_GUIDE_HELPERS_START',
  '// SYSTEM_USAGE_GUIDE_HELPERS_END',
);
const systemUsageGuideComponent = sliceBetween(
  operatingIntelligenceComponents,
  "const operatingQuestionConsultant = {",
  'return Object.freeze({ operatingQuestionPanel, operatingQuestionConsultant });',
);

test('unified Agent operating question saves and performs an exact second readback', () => {
  assert.match(routes, /agent[\s\S]*operating-questions/);
  assert.match(controller, /OperatingQuestionService/);
  assert.match(questions, /deterministic_saved_evidence/);
  assert.match(questions, /readback_verified/);
  assert.match(questions, /blocked_by_missing_facts/);
  assert.match(frontend, /\/agent\/operating-questions/);
  assert.match(routes, /operating-question-scopes/);
  assert.match(controller, /questionScopeOptions/);
  assert.match(questions, /operating_question_scope_options\.v1/);
  assert.match(frontend, /loadOperatingQuestionScopeOptions/);
  assert.match(frontend, /loadOperatingQuestionHistory/);
  assert.match(frontend, /operating-question-readback-error/);
  assert.match(frontend, /content_digest/);
  assert.match(agentPage, /<oq><\/oq>/);
  assert.match(frontend, /['"]data-testid['"]:\s*['"]operating-question-entry['"]/);
});

test('operating-question scope reads stay deferred until their Agent panel is active', () => {
  const activeScopeLoads = appMain.match(
    /if \(!?operatingQuestionPanelIsActive\(\)\)[\s\S]{0,120}loadOperatingQuestionScopeOptions/g,
  ) || [];
  assert.equal(activeScopeLoads.length, 3);
  assert.match(
    operatingIntelligenceComponents,
    /onMounted\(\(\) => \{[\s\S]{0,160}loadScopeOptions\?\.\(\{ applyRecommendation: true \}\)/,
  );
});

test('question evidence keeps facts, memory, knowledge, Agent and execution references separate', () => {
  for (const marker of [
    'fact_refs_json',
    'memory_refs_json',
    'knowledge_refs_json',
    'execution_refs_json',
  ]) {
    assert.ok(migration.includes(marker), `migration missing ${marker}`);
    assert.ok(questions.includes(marker.replace('_json', '')) || questions.includes(marker), `service missing ${marker}`);
  }
  assert.match(questions, /saved_verified_fact_missing/);
  assert.match(questions, /external_llm_called' => false/);
  assert.match(questions, /'ota_write' => false/);
  assert.match(questions, /'external_message' => false/);
});

test('professional operating questions remain evidence-gated while the global entry intelligently guides system use', () => {
  assert.match(controller, /OperatingQuestionAiAnswerService/);
  assert.match(questions, /grounded_ai_saved_evidence/);
  assert.match(questions, /not_called_missing_facts/);
  assert.match(aiAnswers, /allowed_evidence_refs/);
  assert.match(aiAnswers, /knowledge_context/);
  assert.match(aiAnswers, /createJsonResponseEnvelope/);
  assert.match(knowledgeRetrieval, /KnowledgeDecisionGateService/);
  assert.match(knowledgeRetrieval, /metadata_filtered_lexical_v1/);
  assert.match(knowledgeRetrieval, /globalSystemOwned/);
  assert.match(knowledgeRetrieval, /formalShared/);
  assert.match(llmClient, /provider_fallback_enabled' => false/);
  assert.match(llmClient, /response_cache_enabled' => false/);
  assert.match(llmClient, /'type' => 'json_object'/);
  assert.match(aiAnswers, /verified_ota_channel_only/);
  assert.match(aiAnswers, /missing_substantive_fact_coverage/);
  assert.match(aiAnswers, /metric_values/);
  assert.match(aiAnswers, /metric_units/);
  assert.match(aiAnswers, /isSubstantiveFact/);
  assert.match(aiAnswers, /count\(\$dates\) \* count\(\$platforms\) > 40/);
  assert.match(questions, /observedMetricFields/);
  assert.match(questions, /\(float\)\$value === 0\.0/);
  assert.match(aiAnswers, /unknown_after_client_attempt/);
  assert.match(aiAnswers, /不得改价、改库存、创建任务、外发消息/);
  assert.match(aiAnswers, /DIRECT_MODEL_KEY = 'deepseek_v4_pro'/);
  assert.match(aiAnswers, /PROMPT_VERSION = 'operating_question_grounded_ai\.zh-CN\.v4'/);
  assert.match(aiAnswers, /decision_frame 只是用户选择或问题关键词推断的分析组织框架/);
  assert.match(questions, /RevenueDecisionFrameService/);
  assert.match(controller, /decision_object/);
  assert.match(aiAnswers, /deepseek_v4_pro_not_confirmed/);
  assert.match(questions, /string \$modelKey = OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY/);
  assert.match(questions, /platform_date_fact_coverage_missing/);
  assert.match(questions, /whereIn\('platform', self::ALL_OTA_REQUIRED_PLATFORMS\)/);
  assert.match(questions, /whereIn\('i\.platform', self::ALL_OTA_REQUIRED_PLATFORMS\)/);
  assert.match(questions, /where\('quality_status', 'verified'\)/);
  assert.match(operatingIntelligenceComponents, /AI 行动草案 · 待人工确认/);
  assert.match(operatingIntelligenceComponents, /点击只提交本地待审批意图/);
  assert.match(operatingIntelligenceComponents, /不会自动批准、采集或写 OTA/);
  assert.doesNotMatch(operatingIntelligenceComponents, /提交独立评审|AI 独立评审已通过/);

  assert.match(globalShell, /<operating-question-consultant v-if="isLoggedIn" :ctx="\$root"><\/operating-question-consultant>/);
  assert.match(routes, /Route::post\('\/system-guidance', 'SystemGuidance\/guide'\)/);
  assert.match(systemGuidanceController, /SystemUsageAssistantService/);
  assert.match(systemGuidance, /createJsonResponseEnvelope/);
  assert.match(systemGuidance, /server_owned_feature_catalog_only/);
  assert.match(systemGuidance, /只能从 trusted_feature_catalog 选择 topic_key/);
  assert.match(systemGuidance, /journey_topic_keys/);
  assert.match(systemGuidance, /复合目标必须保留后续步骤/);
  assert.match(systemGuidance, /success_marker/);
  assert.match(systemGuidance, /fallbackResult/);
  assert.match(systemUsageGuideComponent, /name: 'IntelligentSystemUsageAssistant'/);
  assert.match(systemUsageGuideComponent, /system-guide-floating-launcher/);
  assert.match(systemUsageGuideComponent, /system-guide-input/);
  assert.match(systemUsageGuideComponent, /system-guide-submit/);
  assert.match(systemUsageGuideComponent, /system-guide-result/);
  assert.match(systemUsageGuideComponent, /说出目标，我带你找到入口并核对是否完成/);
  assert.match(frontend, /\/agent\/system-guidance/);
  assert.match(systemUsageGuideComponent, /history: conversationHistory\(\)/);
  assert.match(systemUsageGuideComponent, /current_scope:/);
  assert.match(systemUsageGuideComponent, /visible_topic_keys: visibleTopicKeys\(\)/);
  assert.match(systemUsageGuideComponent, /active_journey: activeJourneyContext\(\)/);
  assert.match(systemUsageGuideComponent, /system-guide-context/);
  assert.match(systemUsageGuideComponent, /继续当前任务/);
  assert.match(systemUsageGuideComponent, /system-guide-journey-goal/);
  assert.match(systemUsageGuideComponent, /system-guide-active-journey/);
  assert.match(systemUsageGuideComponent, /仅到达页面不会被算作完成/);
  assert.match(systemUsageGuideComponent, /suxios_system_usage_journey_v1/);
  assert.match(systemUsageGuideComponent, /suxios_system_usage_widget_v1/);
  assert.match(systemUsageGuideComponent, /system-guide-drag-handle/);
  assert.match(systemUsageGuideComponent, /startWidgetDrag/);
  assert.match(systemUsageGuideComponent, /clampWidgetPosition/);
  assert.match(systemUsageGuideComponent, /收起宿析智能使用助手/);
  assert.match(systemUsageGuideComponent, /打开宿析智能使用助手/);
  assert.doesNotMatch(systemUsageGuideComponent, /fa-chevron-up/);
  assert.match(systemUsageGuideComponent, /h\('span', '拖动'\)/);
  assert.match(systemUsageGuideComponent, /h\('span', '收起'\)/);
  assert.match(systemUsageGuideComponent, /已按目标生成系统路径 · 入口权限已核对/);
  assert.match(systemUsageGuideComponent, /SYSTEM_ASSISTANT_MODE_OPTIONS/);
  assert.match(systemUsageGuideComponent, /system-guide-mode-switcher/);
  assert.match(systemUsageGuideComponent, /runOperatingWorkflow/);
  assert.match(systemUsageGuideComponent, /form\.model_key = 'local_second_brain'/);
  assert.match(systemUsageGuideComponent, /operating_result/);
  assert.match(systemUsageGuideComponent, /system-guide-operating-result/);
  assert.match(systemUsageGuideComponent, /在页面中指给我看/);
  assert.match(systemUsageGuideComponent, /phase1EmployeeClosureSummary/);
  assert.match(systemUsageGuideComponent, /autoFetchCanonicalOperationStatus/);
  assert.match(systemUsageGuideComponent, /sx-system-guide-anchor-active/);
  assert.match(systemUsageGuideComponent, /导航目标来自当前账号可用的系统功能/);
  assert.doesNotMatch(systemUsageGuideComponent, /DeepSeek V4 Pro 正式版 · 教你使用 · 给出证据结论/);
  assert.doesNotMatch(systemUsageGuideComponent, /DeepSeek V4 Pro 正在理解目标/);
  assert.doesNotMatch(systemUsageGuideComponent, /DeepSeek V4 Pro直接生成 · 真实入口约束/);
  assert.match(systemUsageGuideComponent, /openOnlineDataTab/);
  assert.match(systemUsageGuideComponent, /openOnlinePlatformAutoTab/);
  assert.doesNotMatch(systemUsageGuideComponent, /request\('\/agent\/operating-questions/);
  assert.doesNotMatch(systemUsageGuideComponent, /operating-question-hotel|operating-question-platform/);
  assert.doesNotMatch(frontend, /const operatingQuestionPanel = \{[\s\S]{0,300}template:/);
  assert.match(style, /\.sx-ai-consultant-panel/);
  assert.match(style, /\.sx-ai-consultant-context/);
  assert.match(style, /\.sx-ai-consultant-journey-list/);
  assert.match(systemGuidance, /'key' => 'ctrip-data'/);
  assert.match(systemGuidance, /'key' => 'meituan-data'/);
  assert.match(systemGuidance, /'key' => 'operation-optimizer'/);
  assert.match(systemGuidance, /'key' => 'knowledge-search'/);
  assert.match(style, /\.sx-ai-consultant-header[\s\S]*cursor: grab/);
  assert.match(style, /\.sx-ai-consultant-launcher[\s\S]*touch-action: none/);
  assert.match(style, /\.sx-ai-consultant-answer-summary[\s\S]*font-size: 15px/);
  assert.match(style, /\.sx-ai-consultant-composer textarea[\s\S]*font-size: 14px/);
  assert.match(style, /\.sx-ai-consultant-mode-switcher/);
  assert.match(style, /\.sx-system-guide-coach/);
  assert.match(style, /\.sx-system-guide-anchor-active/);
  assert.match(style, /@media \(max-width: 640px\)/);
  assert.match(frontend, /operating-question-decision-object/);
  assert.match(frontend, /operating-question-decision-frame/);
});

test('system usage assistant maps common work to a real page and falls back to task navigation', () => {
  const { resolveSystemUsageGuideTopic, resolveSystemUsageGuideJourney, SYSTEM_USAGE_GUIDE_TOPICS, SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS } = new Function(
    `${systemUsageGuideHelpers}\nreturn { resolveSystemUsageGuideTopic, resolveSystemUsageGuideJourney, SYSTEM_USAGE_GUIDE_TOPICS, SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS };`,
  )();

  assert.equal(SYSTEM_USAGE_GUIDE_TOPICS.length, 25);
  assert.equal(Object.keys(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS).length, 25);
  assert.match(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS['data-health'], /精确回读/);
  assert.equal(resolveSystemUsageGuideTopic('我是第一次使用，今天应该先做什么').key, 'daily-workbench');
  assert.equal(resolveSystemUsageGuideTopic('携程数据缺失去哪里处理').key, 'data-health');
  assert.equal(resolveSystemUsageGuideTopic('在哪里看报告和经营结论').key, 'revenue-report');
  assert.equal(resolveSystemUsageGuideTopic('怎么给员工安排任务并复盘').key, 'operations');
  assert.equal(resolveSystemUsageGuideTopic('自动任务没运行').key, 'automation-monitor');
  assert.equal(resolveSystemUsageGuideTopic('怎么给新员工开账号并分配酒店权限').key, 'team-permissions');
  assert.equal(resolveSystemUsageGuideTopic('怎么生成今天的AI经营日报').key, 'ai-daily-report');
  assert.equal(resolveSystemUsageGuideTopic('美团订单和流量去哪里看').key, 'meituan-data');
  const ctripPlan = resolveSystemUsageGuideTopic('先看携程经营数据，再形成运营方案');
  assert.equal(ctripPlan.key, 'ctrip-data');
  assert.deepEqual(resolveSystemUsageGuideJourney('先看携程经营数据，再形成运营方案', ctripPlan), [
    'ctrip-data', 'revenue-report', 'operation-optimizer',
  ]);
  assert.equal(resolveSystemUsageGuideTopic('去哪里找系统功能说明').key, 'knowledge-search');
  assert.equal(resolveSystemUsageGuideTopic('怎么查看操作日志').key, 'operation-audit');
  assert.equal(resolveSystemUsageGuideTopic('这是一个没有目录的陌生请求').key, 'task-navigation');
  assert.equal(resolveSystemUsageGuideTopic('这是一个没有目录的陌生请求', 'compass').key, 'task-navigation');
});

test('all_ota diagnosis is explicit Ctrip plus Meituan current-date evidence and never whole-hotel fallback', () => {
  assert.match(agentPage, /<option value="all_ota">携程\+美团 OTA<\/option>/);
  assert.match(agentPage, /不包含 PMS，也不代表全酒店经营/);
  assert.match(agentPage, /同步携程[\s\S]*同步美团/);
  assert.match(agent, /\$platform !== 'all_ota' && \$hotelIdRaw === '' && \$configId !== ''/);
  assert.match(agentBuild, /ctrip_meituan_ota_channels_only/);
  assert.match(agentBuild, /cross_platform_totals_calculated' => false/);
  assert.match(agentBuild, /used_latest_available_data/);
  assert.match(agentPersistence, /readback_identity_digest/);
  assert.match(agentPersistence, /effective_date_range/);
  assert.match(questions, /all_ota_saved_diagnosis_not_current/);
  assert.match(questions, /diagnosis_used_latest_available_data/);
  assert.match(questions, /ALL_OTA_REQUIRED_PLATFORMS = \['ctrip', 'meituan'\]/);
});

test('SOP versions require repeated positive review memories and remain immutable', () => {
  assert.match(sops, /MIN_VERIFICATION_MEMORIES = 3/);
  assert.match(sops, /positive_outcome_verified/);
  assert.match(sops, /sop_candidate_ready/);
  assert.match(sops, /count\(\$businessDates\) < 2/);
  assert.match(sops, /previous_version_id/);
  assert.match(sops, /expected_candidate_digest/);
  assert.match(sops, /候选SOP已被处理或已不是当前有效候选/);
  assert.match(sops, /leaves the last verified/);
  assert.match(sops, /versionContent\(\$version\)/);
  assert.match(sops, /validation_status' => \$decision === 'verify' \? 'verified' : 'rejected'/);
  assert.match(routes, /operating-sops\/:id\/validate/);
});

test('cross-hotel replication is same-tenant draft-only and never reuses source facts', () => {
  assert.match(sops, /assertHotelIdentity\(\$tenantId, \$targetHotelId\)/);
  assert.match(sops, /reference_only_not_reused_as_target_fact/);
  assert.match(sops, /draft_pending_target_validation/);
  assert.match(sops, /blocked_missing_target_facts/);
  assert.match(sops, /target_hotel_comparable_fact_missing/);
  assert.match(sops, /whereBetween\('data_date'/);
  assert.match(sops, /whereIn\('data_type'/);
  assert.match(sops, /'target_verified' => false/);
  assert.match(sops, /'automatic_execution' => false/);
  assert.match(routes, /operating-sops\/:id\/replications/);
  assert.doesNotMatch(sops, /manual-notification|wecom|price-write|auto-fetch/i);
});
