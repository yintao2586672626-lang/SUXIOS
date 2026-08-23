import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');
const contentHash = value => crypto.createHash('sha256').update(value).digest('hex').slice(0, 10);

const template = read('resources/frontend/templates/fragments/17-page-ops-track.html');
const appMain = read('public/app-main.js');
const components = read('public/components/system/app-main-components.js');
const componentsLoader = read('public/components/system/app-main-components-loader.js');
const operatingComponents = read('public/components/system/operating-intelligence-components.js');
const operatingLoader = read('public/components/system/operating-intelligence-loader.js');
const indexHtml = read('public/index.html');
const routes = read('route/app.php');
const controller = read('app/controller/ManagerCapability.php');
const service = read('app/service/ManagerCapabilityScoringService.php');
const protectedCapabilities = read('app/service/ProtectedCapabilityService.php');
const followupMigration = read('database/migrations/20260822_zz_create_manager_capability_followups.sql');
const optimizationMigration = read('database/migrations/20260822_zzz_optimize_manager_capability.sql');
const eventTimestampMigration = read('database/migrations/20260822_zzzz_refine_manager_capability_event_timestamps.sql');
const followupTimestampMigration = read('database/migrations/20260822_zzzzz_refine_manager_capability_followup_event_timestamp.sql');

test('operations page exposes a scoped three-question manager score loop', () => {
  for (const marker of [
    '<manager-capability-panel',
    ':hotel-id="operationFilters.hotel_id"',
    ':request="managerCapabilityRequest"',
  ]) {
    assert.ok(template.includes(marker), marker);
  }
  for (const marker of [
    "'data-testid': 'manager-capability-panel'",
    "'data-testid': 'manager-capability-manager'",
    "'data-testid': 'manager-capability-dimensions'",
    "'data-testid': 'manager-capability-form'",
    "'data-testid': 'manager-capability-followup-form'",
    "'data-testid': 'manager-capability-followup-queue'",
    "'data-testid': 'manager-capability-adjustment-form'",
    "'data-testid': 'manager-capability-score-review-form'",
    "'data-testid': 'manager-capability-pilot-readiness'",
    "textArea('problem_facts'",
    "textArea('action_taken'",
    'this.form.verification_status',
    "textArea('verification_text'",
    '保存案例并评分',
    '追加复查',
    '保存本次复查',
    '原始三问不会被覆盖',
    '不用于跨店排名、处罚或自动触发运营动作',
  ]) {
    assert.ok(components.includes(marker), marker);
  }
});

test('frontend persists and verifies exact hotel manager formula and digests', () => {
  for (const marker of [
    '/operation/manager-capability/managers',
    '/operation/manager-capability/profile',
    '/operation/manager-capability/cases',
    '/followups',
    '/followup-queue',
    '/adjustments',
    '/score-reviews',
    'manager_capability_evidence_v1',
    'res.data?.readback_verified !== true',
    'Number(saved.hotel_id || 0) !== hotelId',
    'Number(saved.manager_user_id || 0) !== managerUserId',
    'saved.input_digest',
    'score_snapshot?.evidence_digest',
    'score_snapshot?.scoring_version',
    'followup.input_digest',
    'followup.score_snapshot?.evidence_digest',
    'linkedCase?.parent_case_id',
    'linkedCase?.origin_followup_id',
  ]) {
    assert.ok(components.includes(marker), marker);
  }
  for (const marker of [
    'ManagerCapabilityPanel',
    'managerCapabilityRequest: apiRequest',
  ]) {
    assert.ok(appMain.includes(marker), marker);
  }
  assert.ok(componentsLoader.includes("'ManagerCapabilityPanel'"));
  assert.ok(componentsLoader.includes('const OperatingLoopAuthority = {'));
  const loaderVersion = `20260822-manager-capability-h${contentHash(components)}`;
  assert.ok(componentsLoader.includes(`components/system/app-main-components.js?v=${loaderVersion}`));
  assert.ok(indexHtml.includes(`components/system/app-main-components.js?v=${loaderVersion}`));
});

test('deferred component bridge resolves the manager score panel and loop authority', async () => {
  const runtimeWindow = {
    SUXI_ONLINE_DATA_COMPONENTS: {},
    SUXI_SYSTEM_COMPONENTS: {},
  };
  const h = () => ({});
  const Vue = {
    h,
    defineAsyncComponent: loader => ({ loader }),
  };
  new Function('window', components)(runtimeWindow);
  assert.equal(typeof runtimeWindow.SUXI_APP_MAIN_COMPONENTS_FULL?.create, 'function');
  new Function('window', 'document', componentsLoader)(runtimeWindow, {});
  const facade = runtimeWindow.SUXI_APP_MAIN_COMPONENTS.create({ Vue, h });
  const panel = await facade.ManagerCapabilityPanel.loader();
  assert.equal(panel?.name, 'ManagerCapabilityPanel');
  assert.equal(facade.OperatingLoopAuthority?.name, 'OperatingLoopAuthority');
});

test('authenticated page dependency receives Vue ref and pins the repaired asset', () => {
  assert.ok(operatingComponents.includes('const create = ({ ref, computed, inject, h, nextTick, onMounted, onUnmounted })'));
  const loaderVersion = `20260822-human-review-h${contentHash(operatingComponents)}`;
  assert.ok(operatingLoader.includes(`components/system/operating-intelligence-components.js?v=${loaderVersion}`));
  assert.ok(!indexHtml.includes('components/system/operating-intelligence-components.js'));
});

test('backend keeps the score within operation permissions and tenant hotel scope', () => {
  for (const marker of [
    "Route::get('/manager-capability/managers'",
    "Route::get('/manager-capability/profile'",
    "Route::get('/manager-capability/followup-queue'",
    "Route::get('/manager-capability/cases/:id'",
    "Route::post('/manager-capability/cases/:id/followups'",
    "Route::post('/manager-capability/cases/:id/adjustments'",
    "Route::post('/manager-capability/cases/:id/score-reviews'",
    "Route::post('/manager-capability/cases'",
  ]) {
    assert.ok(routes.includes(marker), marker);
  }
  for (const marker of [
    "resolveSingleHotelScope('operation.view')",
    "'operation.execute'",
    'getPermittedHotelIds()',
    'hasHotelPermission($hotelId, $capability)',
    'hotelTenantId($hotelId)',
    'createFollowup(',
  ]) {
    assert.ok(controller.includes(marker), marker);
  }
  for (const marker of [
    "->where('tenant_id', $tenantId)",
    "->where('hotel_id', $hotelId)",
    "throw new RuntimeException('所选店长不属于当前租户和酒店')",
    "'unknown_policy' => '无案例证据时留空，不按0分计算'",
    "'minimum_samples_per_dimension' => self::MINIMUM_PROFILE_SAMPLES",
    "public const FOLLOWUP_TABLE = 'manager_capability_case_followups'",
    "public const ADJUSTMENT_TABLE = 'manager_capability_case_adjustments'",
    "public const REVIEW_TABLE = 'manager_capability_score_reviews'",
    "'followup_policy' => '原始三问不覆盖",
    "'recurred' => 'recurred'",
    "'linked_recurrence_case_id'",
    "'evidence_confidence_policy'",
    "'privacy_scope' => $includePrivateDetails ? 'evidence_detail' : 'aggregate_only'",
    "'automation_boundary' => '只提供人工复查工作台",
    'mutableCaseDigest($currentCase)',
    '->lock(true)',
    'mutableCaseDigest($lockedCurrentCase)',
    "案例状态已变化，请刷新后重新修正",
    "案例状态已变化，请刷新后重新复查",
    "评分已变化，请刷新后重新复核",
  ]) {
    assert.ok(service.includes(marker), marker);
  }
  for (const marker of [
    "api/operation/manager-capability/cases', 'methods' => ['POST']",
    "api/operation/manager-capability/cases/*/followups', 'methods' => ['POST']",
    "api/operation/manager-capability/cases/*/adjustments', 'methods' => ['POST']",
    "api/operation/manager-capability/cases/*/score-reviews', 'methods' => ['POST']",
  ]) {
    assert.ok(protectedCapabilities.includes(marker), marker);
  }
  for (const marker of [
    'manager_capability_case_followups',
    '`parent_case_id`',
    '`origin_followup_id`',
    '`followup_outcome`',
    '`linked_recurrence_case_id`',
    'uniq_manager_capability_followup_idempotency',
  ]) {
    assert.ok(followupMigration.includes(marker), marker);
  }
  for (const marker of [
    'manager_capability_case_adjustments',
    'manager_capability_score_reviews',
    '`evidence_confidence`',
    '`effective_payload_json`',
    '`source_score_digest`',
    'uniq_manager_capability_adjustment_idempotency',
    'uniq_manager_capability_score_review_idempotency',
  ]) {
    assert.ok(optimizationMigration.includes(marker), marker);
  }
  for (const marker of ['manager_capability_case_adjustments', 'manager_capability_score_reviews', 'DATETIME(6)']) {
    assert.ok(eventTimestampMigration.includes(marker), marker);
  }
  assert.ok(!eventTimestampMigration.includes('manager_capability_case_followups'));
  assert.ok(followupTimestampMigration.includes('manager_capability_case_followups'));
  assert.ok(followupTimestampMigration.includes('DATETIME(6)'));
  assert.ok(!followupTimestampMigration.includes('manager_capability_case_adjustments'));
  assert.ok(!followupTimestampMigration.includes('manager_capability_score_reviews'));
});
