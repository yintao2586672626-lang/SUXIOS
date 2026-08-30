import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = path => readFileSync(path, 'utf8');
const base = read('app/service/DailyOneThingService.php');
const personalization = read('app/service/DailyOneThingPersonalizationService.php');
const calibration = read('app/service/AiSuggestionCalibrationService.php');
const lab = read('app/service/OperatingOpportunityLabService.php');
const controller = read('app/controller/OperatingOpportunity.php');
const lifecycle = read('app/service/OperationActionLifecycleService.php');
const opportunityUi = read('public/components/system/operating-opportunity-lab.js');
const home = read('public/home-static.js');
const learningUi = read('public/components/system/operating-intelligence-components.js');
const routes = readRouteContractSource(process.cwd());

test('personalization starts only after the v2 fact gate and exact four-dimensional tie', () => {
  assert.match(base, /function prepare\(/);
  assert.match(base, /function baseRankKey\(/);
  assert.match(base, /function sameBaseRank\(/);
  assert.match(base, /daily_one_thing_explanation\.v1/);
  assert.match(base, /hotel_shared_base_selection/);
  assert.doesNotMatch(base, /'candidates'\s*=>\s*array_values/);

  for (const marker of [
    'base_rank_exact_tie_break_only',
    'DailyOneThingService::sameBaseRank',
    "'preferred_platform'",
    "['ctrip', 'meituan']",
    'preference_adjustment',
    'feedback_adjustment',
    "['selection_policy']['base_order']",
    "['selection_policy']['effective_order']",
    'candidate_preferences_consumed',
    "'facts_changed' => false",
    "'eligibility_changed' => false",
    "'business_rank_changed' => false",
    "'permissions_changed' => false",
    "'approval_changed' => false",
    "'external_write_authorized' => false",
  ]) assert.ok(personalization.includes(marker), marker);
});

test('feedback needs twenty exact user-hotel-feature samples and remains non-authoritative', () => {
  assert.match(calibration, /function buildDailyRankingAdjustments\(/);
  assert.match(calibration, /daily_one_thing_selection/);
  assert.match(calibration, /daily_one_thing_input/);
  assert.match(calibration, /MINIMUM_RANKING_SAMPLES/);
  assert.match(calibration, /accepted' && \$reason === 'useful'/);
  assert.match(calibration, /rejected' && \$reason === 'wrong_focus'/);
  assert.match(calibration, /base_rank_exact_tie_break_only/);
  assert.match(calibration, /one_latest_feedback_per_feature_and_business_date/);
  assert.match(calibration, /duplicate_sample_count/);
  assert.match(calibration, /unique_business_date_count/);
  assert.match(personalization, /function recordFeedback\(/);
  assert.match(personalization, /function feedbackSuggestionKey\(/);
  assert.match(personalization, /one_user_hotel_business_date_feature_material\.v1/);
  assert.match(personalization, /FEEDBACK_SLOT_IDEMPOTENCY_KEY/);
  assert.match(personalization, /maximum_feedback_events' => 1/);
  assert.match(calibration, /MAX_DAILY_RANKING_SNAPSHOT_SCAN/);
  assert.match(calibration, /history_scan_limit_exceeded/);
  assert.match(calibration, /'history_truncated' => false/);
  assert.match(personalization, /preview is never a hotel-shared run/);
  assert.doesNotMatch(personalization, /automatic_approval\s*=>\s*true|external_write_authorized\s*=>\s*true/);
});

test('overview exposes a per-user preview while the shared hotel item remains on the base selector', () => {
  assert.match(lab, /\$priority = \$this->dailyOneThing->select/);
  assert.match(lab, /\$personalizedPriority = \$this->dailyPersonalization->select/);
  assert.match(lab, /'today_preview' => \$priority/);
  assert.match(lab, /'personalized_today_preview' => \$personalizedPriority/);
  assert.match(lab, /function recordDailyPreviewFeedback\(/);
  assert.match(lab, /'hotel_shared_daily_item_changed' => false/);
  assert.match(lab, /'execution_intent_created' => false/);
  assert.match(lab, /'external_write_count' => 0/);
  assert.match(lifecycle, /'recommendation_explanation'/);
  assert.ok(routes.includes("Route::post('/daily-preview/feedback', 'OperatingOpportunity/dailyPreviewFeedback')"));
  assert.match(controller, /resolveSingleHotelScope\(\s*'operation\.view'/);
});

test('existing opportunity and home cards explain the recommendation without adding navigation', () => {
  for (const marker of [
    'daily-one-thing-personalized-preview',
    'daily-one-thing-personalized-why-you',
    'daily-one-thing-preview-feedback',
    'daily-one-thing-explanation',
    'daily-one-thing-why-now',
    'daily-one-thing-why-recommended',
    'daily-one-thing-personalization',
  ]) assert.ok(opportunityUi.includes(marker), marker);
  for (const marker of [
    'home-daily-one-thing-explanation',
    'home-daily-one-thing-why-now',
    'home-daily-one-thing-why-recommended',
    'home-daily-one-thing-personalization',
  ]) assert.ok(home.includes(marker), marker);
  assert.match(learningUi, /\['preferred_platform', 'ctrip', '每日重点优先携程'\]/);
  assert.match(learningUi, /\['preferred_platform', 'meituan', '每日重点优先美团'\]/);
  assert.match(personalization, /preferred_platform_all_ota_is_neutral/);
});
