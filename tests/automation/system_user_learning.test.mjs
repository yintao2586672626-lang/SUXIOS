import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');
const routes = readRouteContractSource(process.cwd());
const memoryMigration = read('database/migrations/20260829_create_user_learning_memory.sql');
const calibrationMigration = read('database/migrations/20260829_z_create_ai_suggestion_calibration.sql');
const journeyMigration = read('database/migrations/20260829_zz_create_user_guidance_journeys.sql');
const memory = read('app/service/UserLearningMemoryService.php');
const calibration = read('app/service/AiSuggestionCalibrationService.php');
const journey = read('app/service/UserGuidanceJourneyService.php');
const assistant = read('app/service/SystemUsageAssistantService.php');
const controller = read('app/controller/SystemLearning.php');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');

test('user learning memory is append-only, scoped, revocable, and never an authority store', () => {
  for (const marker of [
    'user_learning_memory_events',
    'user_learning_memory_preferences',
    'tenant_id',
    'user_id',
    'memory_scope',
    'learning_status',
    'lifecycle_status',
    'request_digest',
  ]) assert.match(memoryMigration, new RegExp(marker));

  for (const method of [
    'recordFeedback',
    'recordRepeatedSignal',
    'confirmPreference',
    'listPreferences',
    'revokePreference',
    'resetScope',
    'readExact',
  ]) assert.match(memory, new RegExp(`function ${method}\\(`));
  assert.match(memory, /explicit_confirmed/);
  assert.match(memory, /user_learning_sensitive_preference_rejected/);
  assert.match(memory, /user_learning_business_fact_rejected/);
  assert.match(memory, /candidate_ready/);
  assert.match(memory, /requires_confirmation/);
  assert.match(memory, /minimumSignals < 2/);
});

test('calibration freezes evidence and keeps every strategy comparison offline or shadow-only', () => {
  for (const table of [
    'ai_suggestion_calibration_snapshots',
    'ai_suggestion_calibration_feedback_events',
    'ai_suggestion_calibration_observation_events',
    'ai_suggestion_strategy_comparisons',
  ]) assert.match(calibrationMigration, new RegExp(table));
  assert.match(calibrationMigration, /idempotency_hash/);
  assert.doesNotMatch(calibrationMigration, /`idempotency_key`/);
  for (const marker of [
    "'insufficient_samples'",
    "'not_activated'",
    "'not_called'",
    "'business_write_status'",
    "'causal_claim'",
  ]) assert.ok(calibration.includes(marker), `calibration contract missing ${marker}`);
  assert.match(calibration, /function freezeSuggestion\(/);
  assert.match(calibration, /function appendFeedback\(/);
  assert.match(calibration, /function summarize\(/);
  assert.match(calibration, /user_feedback_ranking\.v1/);
  assert.match(calibration, /ranking_minimum_samples/);
  assert.match(calibration, /base_order_tie_break_only/);
  assert.match(calibration, /existing_quick_suggestion_order_only/);
  assert.match(calibration, /function recordStrategyComparison\(/);
});

test('cross-session journey remains user and hotel scoped and rejects credential material', () => {
  assert.match(journeyMigration, /user_guidance_journeys/);
  assert.match(journeyMigration, /previous_journey_id/);
  assert.match(journeyMigration, /lifecycle_status/);
  assert.match(journeyMigration, /original_query_digest/);
  assert.doesNotMatch(journeyMigration, /`original_query`/);
  assert.match(journey, /function readActive\(/);
  assert.match(journey, /function readResumeCard\(/);
  assert.match(journey, /function transitionExact\(/);
  assert.match(journey, /function archiveActive\(/);
  assert.match(journey, /containsSensitiveValue/);
  assert.match(journey, /hotel_fact_write' => false/);
  assert.match(journey, /permission_change' => false/);
  assert.match(journey, /external_message' => false/);
  assert.match(journey, /business_completion_claimed' => false/);
  assert.match(journey, /stale_resume_card/);
});

test('authenticated APIs expose context, preference controls, journey continuity, and suggestion feedback', () => {
  for (const route of [
    '/system-guidance/context',
    '/system-guidance/preferences',
    '/system-guidance/preferences/revoke',
    '/system-guidance/preferences/reset',
    '/system-guidance/journey',
    '/system-guidance/journey/transition',
    '/system-guidance/journey/archive',
    '/system-guidance/feedback',
  ]) assert.ok(routes.includes(route), `missing route ${route}`);
  assert.ok(routes.indexOf('/system-guidance/preferences/revoke') < routes.indexOf("Route::post('/system-guidance',"));
  assert.ok(routes.indexOf('/system-guidance/preferences/reset') < routes.indexOf("Route::post('/system-guidance',"));
  assert.ok(routes.indexOf('/system-guidance/journey/archive') < routes.indexOf("Route::post('/system-guidance',"));
  assert.ok(routes.indexOf('/system-guidance/journey/transition') < routes.indexOf("Route::post('/system-guidance',"));
  assert.ok(routes.indexOf('/system-guidance/feedback') < routes.indexOf("Route::post('/system-guidance',"));
  assert.match(controller, /class SystemLearning extends Base/);
  assert.match(controller, /UserLearningMemoryService/);
  assert.match(controller, /AiSuggestionCalibrationService/);
  assert.match(controller, /UserGuidanceJourneyService/);
  assert.match(controller, /Db::name\(OperatingQuestionService::TABLE\)/);
  assert.match(controller, /precise query stored digest does not match exact readback/);
  assert.match(controller, /context_hotel_id/);
  assert.match(controller, /memoryWriteResponse/);
  assert.match(controller, /个人学习数据表未就绪，请先执行数据库迁移/);
  assert.match(controller, /learningPolicy/);
  assert.match(controller, /automatic_model_fine_tuning' => false/);
  assert.match(controller, /candidate_requires_explicit_confirmation' => true/);
  assert.match(controller, /candidate_minimum_repeated_signals' => 3/);
  assert.match(controller, /external_write_authorized' => false/);
});

test('existing system guide consumes only confirmed preferences and shows scrutable controls', () => {
  assert.match(assistant, /confirmed_user_preference_context/);
  assert.match(assistant, /explicit_confirmed/);
  assert.match(assistant, /overridden_by_current_request/);
  assert.match(assistant, /recognized_not_applied/);
  assert.match(assistant, /为什么这样回答|按你已确认的/);
  assert.match(assistant, /fact_changed' => false/);
  assert.match(assistant, /permission_changed' => false/);
  assert.doesNotMatch(appMain, /loadSystemLearningContext/);
  assert.match(appMain, /Vue, ref, computed, inject, h/);
  assert.match(component, /loadSystemLearningContext/);
  assert.match(component, /saveSystemLearningPreference/);
  assert.match(component, /submitSystemGuidanceFeedbackRequest/);
  assert.match(component, /system-guide-learning-memory/);
  assert.match(component, /system-guide-learning-center/);
  assert.match(component, /system-guide-learning-candidates/);
  assert.match(component, /system-guide-learning-calibration/);
  assert.match(component, /system-guide-learning-journey/);
  assert.match(component, /system-guide-personalization-receipt/);
  assert.match(component, /system-guide-candidate-confirm-/);
  assert.match(component, /\['response_detail', 'concise', '回答简洁'\]/);
  assert.match(component, /system-guide-preference-\$\{key\}-\$\{value\}/);
  assert.match(component, /'aria-pressed': active \? 'true' : 'false'/);
  assert.match(component, /'aria-label': '已确认的回答与每日重点偏好'/);
  assert.match(component, /consumablePreferences\(\)\.some\(\(item\) =>/);
  assert.match(component, /system-guide-preference-revoke-\$\{String\(item\.preference_key\)\}/);
  assert.match(component, /system-guide-preference-reset/);
  assert.match(component, /\['accepted', 'useful', '有用'\]/);
  assert.match(component, /system-guide-feedback-\$\{reason\}/);
  assert.match(component, /system-guide-resume-continue/);
  assert.match(component, /system-guide-resume-complete/);
  assert.match(component, /system-guide-resume-ignore/);
  assert.match(component, /transitionSystemLearningJourney/);
  assert.match(component, /data-feedback-adjustment/);
  assert.match(component, /const targetHotelId = currentLearningHotelId\(\)/);
  assert.match(component, /system_guidance_feedback_\$\{preciseQueryId\}/);
  assert.match(component, /savedFeedback !== 'error'/);
  assert.match(component, /!activePreferences\.length && !candidates\.length/);
  assert.doesNotMatch(component, /preference_context: \{ items: consumablePreferences\(\) \}/);
  assert.match(read('app/controller/PreciseQuery.php'), /UserPreferenceContextService/);
  assert.match(read('app/controller/PreciseQuery.php'), /UserGuidanceJourneyService/);
  assert.match(read('app/controller/PreciseQuery.php'), /\$input\['active_journey'\] = \$this->serverActiveJourney/);
  assert.match(read('app/controller/SystemGuidance.php'), /UserPreferenceContextService/);
  assert.match(read('app/controller/SystemGuidance.php'), /UserGuidanceJourneyService/);
  assert.match(read('app/controller/SystemGuidance.php'), /\$input\['active_journey'\] = \$this->serverActiveJourney/);
  assert.match(component, /suxios_system_usage_journey_v1:\$\{userId > 0 \? userId : 'session'\}:\$\{hotelId > 0 \? hotelId : 'global'\}/);
  assert.match(component, /window\.Vue\.watch\(\(\) => currentLearningHotelId\(\)/);
  assert.match(component, /let learningRequestId = 0/);
  assert.match(component, /requestId !== learningRequestId \|\| currentLearningHotelId\(\) !== targetHotelId/);
  assert.match(component, /system_user_learning_context\.v1/);
  assert.match(component, /validateLearningContext/);
  assert.match(component, /Number\(scope\.user_id \|\| 0\) !== expectedUserId/);
  assert.match(component, /Number\(scope\.hotel_id \|\| 0\) !== Number\(targetHotelId \|\| 0\)/);
  assert.match(component, /个人学习上下文返回的用户、租户或酒店身份不一致/);
  assert.match(component, /学习上下文读取失败/);
  assert.match(component, /反馈 \$\{feedbackCount === null \? '未取得'/);
  assert.match(component, /disabled: state\.value\.learning_loading \|\| !learningContext/);
  assert.match(controller, /calibration_feedback_precise_query_/);
  assert.match(controller, /source_ref' => 'precise_query#'/);
  assert.doesNotMatch(component, /localStorage\.setItem\(journeyStorageKey\(\), JSON\.stringify\(\{[\s\S]{0,260}original_query/);
  assert.doesNotMatch(component, /saveSystemLearningJourney\(\{[\s\S]{0,360}original_query/);
});
