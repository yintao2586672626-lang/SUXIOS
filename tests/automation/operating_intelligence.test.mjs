import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const migration = read('database/migrations/20260802_extend_operating_intelligence.sql');
const responseRegistryMigration = read('database/migrations/20260816_create_operating_question_model_response_registry.sql');
const questions = read('app/service/OperatingQuestionService.php');
const aiAnswers = read('app/service/OperatingQuestionAiAnswerService.php');
const executionBridge = read('app/service/OperatingQuestionExecutionBridgeService.php');
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
  assert.match(controller, /OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY/);
  assert.match(questions, /deterministic_saved_evidence/);
  assert.match(questions, /readback_verified/);
  assert.match(questions, /blocked_by_missing_facts/);
  assert.match(frontend, /\/agent\/operating-questions/);
  assert.match(frontend, /operating-question-readback-error/);
  assert.match(frontend, /content_digest/);
  assert.match(frontend, /readback_verified !== true/);
  assert.match(frontend, /model_key:\s*modelKey/);
  assert.match(frontend, /const modelKey = 'deepseek_v4_pro'/);
  assert.doesNotMatch(operatingIntelligenceComponents, /deepseek_v4_default|deepseek_v4_flash/);
  assert.match(agentPage, /<oq><\/oq>/);
  assert.match(frontend, /['"]data-testid['"]:\s*['"]operating-question-entry['"]/);
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
  assert.match(llmClient, /max_retries' => 0/);
  assert.match(llmClient, /send_idempotency_key' => false/);
  assert.match(llmClient, /\$thinkingMode = \$deepSeekProRequest \? 'enabled' : 'disabled'/);
  assert.match(llmClient, /\$reasoningEffort = \$deepSeekProRequest \? 'high' : ''/);
  assert.match(llmClient, /'deepseek_thinking' => \$thinkingMode/);
  assert.match(llmClient, /api\.deepseek\.com/);
  assert.match(llmClient, /response_model/);
  assert.match(llmClient, /provider_config_digest/);
  assert.match(aiAnswers, /DIRECT_MODEL_KEY = 'deepseek_v4_pro'/);
  assert.match(aiAnswers, /DIRECT_MODEL_NAME = 'deepseek-v4-pro'/);
  assert.match(aiAnswers, /directCallProofReady/);
  assert.match(questions, /directCallProofReady/);
  assert.match(executionBridge, /directCallProofReady/);
  assert.match(llmClient, /'type' => 'json_object'/);
  assert.match(aiAnswers, /verified_ota_channel_only/);
  assert.match(aiAnswers, /missing_substantive_fact_coverage/);
  assert.match(aiAnswers, /metric_values/);
  assert.match(aiAnswers, /metric_units/);
  assert.match(aiAnswers, /fact_claims/);
  assert.match(aiAnswers, /validateFactClaims/);
  assert.match(aiAnswers, /metric_definition_id/);
  assert.match(aiAnswers, /source_path_digest/);
  assert.match(aiAnswers, /field_fact_digest/);
  assert.match(aiAnswers, /source_data_type/);
  assert.match(aiAnswers, /source_key/);
  assert.match(aiAnswers, /claims_digest/);
  assert.match(aiAnswers, /renderClaimSummary/);
  assert.doesNotMatch(aiAnswers, /'required' => \['answer_summary'/);
  assert.match(aiAnswers, /isSubstantiveFact/);
  assert.match(aiAnswers, /count\(\$dates\) \* count\(\$platforms\) > 40/);
  assert.match(questions, /observedMetricFields/);
  assert.match(questions, /\(float\)\$value === 0\.0/);
  assert.match(aiAnswers, /unknown_after_client_attempt/);
  assert.match(aiAnswers, /不得改价、改库存、创建任务、外发消息/);
  assert.match(questions, /platform_date_fact_coverage_missing/);
  assert.match(questions, /whereIn\('platform', self::ALL_OTA_REQUIRED_PLATFORMS\)/);
  assert.match(questions, /whereIn\('i\.platform', self::ALL_OTA_REQUIRED_PLATFORMS\)/);
  assert.match(questions, /where\('quality_status', 'verified'\)/);
  assert.match(questions, /requested_metric_fact_missing/);
  assert.match(questions, /requested_metric_unit_missing/);
  assert.match(questions, /requested_metric_out_of_scope/);
  assert.match(questions, /question_metric_ambiguous/);
  assert.match(questions, /operating_question_metric_intent\.v2/);
  assert.match(questions, /question_scope_platform_mismatch/);
  assert.match(questions, /question_scope_date_mismatch/);
  assert.match(questions, /UNSUPPORTED_QUESTION_SEMANTIC_PATTERNS/);
  assert.match(questions, /LIST_EXPOSURE_VISITOR_SOURCE_KEYS/);
  assert.match(questions, /DETAIL_EXPOSURE_VISITOR_SOURCE_KEYS/);
  assert.match(questions, /action_draft_allowed/);
  assert.match(aiAnswers, /model_fact_claim_not_requested/);

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
  assert.match(systemUsageGuideComponent, /教你使用 · 给出证据结论 · 生成行动草案/);
  assert.match(frontend, /\/agent\/system-guidance/);
  assert.match(systemUsageGuideComponent, /history: conversationHistory\(\)/);
  assert.match(systemUsageGuideComponent, /visible_topic_keys: visibleTopicKeys\(\)/);
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
  assert.match(systemUsageGuideComponent, /DeepSeek直接生成 · 真实入口约束/);
  assert.match(systemUsageGuideComponent, /SYSTEM_ASSISTANT_MODE_OPTIONS/);
  assert.match(systemUsageGuideComponent, /system-guide-mode-switcher/);
  assert.match(systemUsageGuideComponent, /runOperatingWorkflow/);
  assert.match(systemUsageGuideComponent, /operating_result/);
  assert.match(systemUsageGuideComponent, /system-guide-operating-result/);
  assert.match(systemUsageGuideComponent, /在页面中指给我看/);
  assert.match(systemUsageGuideComponent, /phase1EmployeeClosureSummary/);
  assert.match(systemUsageGuideComponent, /autoFetchCanonicalOperationStatus/);
  assert.match(systemUsageGuideComponent, /sx-system-guide-anchor-active/);
  assert.match(systemUsageGuideComponent, /模型只负责理解和说明；导航目标来自系统白名单/);
  assert.match(systemUsageGuideComponent, /openOnlineDataTab/);
  assert.match(systemUsageGuideComponent, /openOnlinePlatformAutoTab/);
  assert.doesNotMatch(systemUsageGuideComponent, /request\('\/agent\/operating-questions/);
  assert.doesNotMatch(systemUsageGuideComponent, /operating-question-hotel|operating-question-platform/);
  assert.doesNotMatch(frontend, /const operatingQuestionPanel = \{[\s\S]{0,300}template:/);
  assert.match(style, /\.sx-ai-consultant-panel/);
  assert.match(style, /\.sx-ai-consultant-journey-list/);
  assert.match(style, /\.sx-ai-consultant-header[\s\S]*cursor: grab/);
  assert.match(style, /\.sx-ai-consultant-launcher[\s\S]*touch-action: none/);
  assert.match(style, /\.sx-ai-consultant-answer-summary[\s\S]*font-size: 15px/);
  assert.match(style, /\.sx-ai-consultant-composer textarea[\s\S]*font-size: 14px/);
  assert.match(style, /\.sx-ai-consultant-mode-switcher/);
  assert.match(style, /\.sx-system-guide-coach/);
  assert.match(style, /\.sx-system-guide-anchor-active/);
  assert.match(style, /@media \(max-width: 640px\)/);
});

test('frontend rejects every incomplete DeepSeek V4 Pro proof before exposing an action', () => {
  const proofSource = sliceBetween(
    appMain,
    'const operatingQuestionDirectCallReady =',
    'const operatingQuestionActionIsCurrent =',
  );
  const operatingQuestionDirectCallReady = new Function(
    `${proofSource}\nreturn operatingQuestionDirectCallReady;`,
  )();
  const nonce = 'oq_node_proof_0001';
  const valid = {
    provider: 'deepseek', model_key: 'deepseek_v4_pro', model: 'deepseek-v4-pro',
    configured_model: 'deepseek-v4-pro', response_model: 'deepseek-v4-pro',
    provider_response_id: 'chatcmpl-node-proof-0001', provider_created_at: Math.floor(Date.now() / 1000),
    provider_response_fresh: true, provider_endpoint_origin: 'https://api.deepseek.com',
    provider_endpoint_host: 'api.deepseek.com', provider_endpoint_official: true,
    provider_config_digest: 'd'.repeat(64), direct_call_nonce: nonce, transport_request_id: nonce,
    transport_retry_attempts: 0, upstream_idempotency_key_sent: false, http_status: 200,
    provider_attempt_count: 1, idempotent_replay: false, direct_request_proof: true,
    thinking_mode: 'enabled', reasoning_effort: 'high', finish_reason: 'stop',
    fallback_used: false, cache_hit: false, degraded: false,
  };
  assert.equal(operatingQuestionDirectCallReady(valid), true);
  for (const mutation of [
    { response_model: 'deepseek-v4-flash' },
    { cache_hit: true },
    { fallback_used: true },
    { provider_endpoint_origin: 'https://gateway.example.com', provider_endpoint_official: false },
    { provider_response_fresh: false },
    { provider_created_at: Math.floor(Date.now() / 1000) - 3600 },
    { transport_retry_attempts: 1 },
    { upstream_idempotency_key_sent: true },
    { direct_request_proof: false },
  ]) {
    assert.equal(operatingQuestionDirectCallReady({ ...valid, ...mutation }), false);
  }
});

test('operating question claims use an immutable global provider response registry', () => {
  assert.match(responseRegistryMigration, /CREATE TABLE IF NOT EXISTS `hotel_operating_question_model_responses`/);
  assert.match(responseRegistryMigration, /provider_response_id` VARCHAR\(191\) CHARACTER SET ascii COLLATE ascii_bin NOT NULL/);
  assert.match(responseRegistryMigration, /UNIQUE KEY `uniq_operating_question_provider_response` \(`provider_response_id`\)/);
  assert.match(responseRegistryMigration, /UNIQUE KEY `uniq_operating_question_response_question` \(`question_id`\)/);
  assert.match(responseRegistryMigration, /KEY `idx_operating_question_response_scope` \(`tenant_id`, `hotel_id`, `question_id`\)/);
  assert.doesNotMatch(responseRegistryMigration, /ON DUPLICATE KEY|REPLACE INTO|UPDATE `hotel_operating_question_model_responses`/i);
  assert.match(questions, /operating-question:v4:/);
  assert.match(questions, /hotel_operating_question\.v2/);
  assert.match(questions, /provider_response_replay_rejected/);
  assert.match(questions, /Db::transaction/);
  assert.match(questions, /模型响应登记严格回读失败/);
  assert.match(executionBridge, /operating_question_execution_bridge\.v2/);
  assert.match(executionBridge, /basis_claim_ids/);
  assert.match(executionBridge, /basis_claims_digest/);
  assert.match(executionBridge, /currentActionEvidenceMatches/);
  assert.match(executionBridge, /METRIC_INTENT_CONTRACT_VERSION/);
  assert.match(executionBridge, /modelResponseRegistryMatches/);
  assert.match(executionBridge, /operating-question:v4:/);
  assert.match(executionBridge, /MODEL_RESPONSE_REGISTRY_TABLE/);
});

test('system usage assistant maps common work to a real page and falls back to task navigation', () => {
  const { resolveSystemUsageGuideTopic, SYSTEM_USAGE_GUIDE_TOPICS, SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS } = new Function(
    `${systemUsageGuideHelpers}\nreturn { resolveSystemUsageGuideTopic, SYSTEM_USAGE_GUIDE_TOPICS, SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS };`,
  )();

  assert.equal(SYSTEM_USAGE_GUIDE_TOPICS.length, 15);
  assert.equal(Object.keys(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS).length, 15);
  assert.match(SYSTEM_USAGE_GUIDE_SUCCESS_MARKERS['data-health'], /精确回读/);
  assert.equal(resolveSystemUsageGuideTopic('我是第一次使用，今天应该先做什么').key, 'daily-workbench');
  assert.equal(resolveSystemUsageGuideTopic('携程数据缺失去哪里处理').key, 'data-health');
  assert.equal(resolveSystemUsageGuideTopic('在哪里看报告和经营结论').key, 'revenue-report');
  assert.equal(resolveSystemUsageGuideTopic('怎么给员工安排任务并复盘').key, 'operations');
  assert.equal(resolveSystemUsageGuideTopic('自动任务没运行').key, 'automation-monitor');
  assert.equal(resolveSystemUsageGuideTopic('怎么给新员工开账号并分配酒店权限').key, 'team-permissions');
  assert.equal(resolveSystemUsageGuideTopic('怎么生成今天的AI经营日报').key, 'ai-daily-report');
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
