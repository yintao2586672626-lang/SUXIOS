import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');

const componentSource = read('public/components/system/operating-opportunity-lab.js');
const pageFragment = read('resources/frontend/templates/fragments/19b-page-operating-opportunities.html');
const appMain = read('public/app-main.js');
const appMainComponents = read('public/components/system/app-main-components.js');
const appMainComponentsLoader = read('public/components/system/app-main-components-loader.js');
const indexHtml = read('public/index.html');
const systemStatic = read('public/system-static.js');
const routes = read('route/app.php');
const controller = read('app/controller/OperatingOpportunity.php');
const labService = read('app/service/OperatingOpportunityLabService.php');
const migration = read('database/migrations/20260822_zzz_create_operating_opportunity_runs.sql');

const loadComponent = () => {
  const runtimeWindow = { SUXI_SYSTEM_COMPONENTS: {} };
  const Vue = { h: () => ({}) };
  new Function('window', 'Vue', componentSource)(runtimeWindow, Vue);
  const definition = runtimeWindow.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody;
  assert.ok(definition, 'component must register itself');
  const context = { ...definition.data(), ...definition.methods };
  context.hotelId = '80';
  context.businessDate = '2026-08-22';
  return context;
};

test('five selling points have a findable authenticated entry and lazy component', () => {
  for (const marker of [
    "currentPage === 'operating-opportunities'",
    '<operating-opportunity-lab',
    ':hotels="hotels"',
    ':request="managerCapabilityRequest"',
  ]) assert.ok(pageFragment.includes(marker), marker);

  for (const marker of [
    "path: 'operating-opportunities'",
    "'经营机会': 'operating-opportunities'",
  ]) assert.ok(systemStatic.includes(marker), marker);

  for (const marker of [
    "newPage === 'operating-opportunities'",
    "sourcePath: 'operating-opportunities'",
    'OperatingOpportunityLab',
  ]) assert.ok(appMain.includes(marker), marker);

  assert.ok(appMainComponents.includes("components/system/operating-opportunity-lab.js?v="));
  assert.ok(appMainComponents.includes("requireSystemComponent('OperatingOpportunityLabBody')"));
  assert.ok(appMainComponentsLoader.includes("'ManagerCapabilityPanel', 'OperatingOpportunityLab'"));
  const componentHash = createHash('sha256').update(appMainComponents).digest('hex').slice(0, 10);
  const componentAsset = `components/system/app-main-components.js?v=20260822-manager-capability-h${componentHash}`;
  assert.ok(appMainComponentsLoader.includes(componentAsset), 'loader must pin the current full component source');
  assert.ok(indexHtml.includes(componentAsset), 'authenticated asset manifest must pin the current full component source');
});

test('frontend submits the exact calculator contracts instead of display-only aliases', () => {
  const context = loadComponent();

  const promise = context.forms.service_promise_risk;
  Object.assign(promise, {
    benefit_type: '早餐', promised_quantity: 9, fulfillable_capacity: 6,
    breach_cost_per_unit: 30, source_quality: 'verified', source_reference: 'receipt#promise',
  });
  const promisePayload = context.buildPayload('service_promise_risk');
  assert.equal(promisePayload.promised_quantity, 9);
  assert.equal(promisePayload.fulfillable_capacity, 6);
  assert.equal(promisePayload.source_references[0], 'receipt#promise');

  const promotion = context.forms.promotion_incrementality;
  Object.assign(promotion, {
    promotion_name: '连住优惠', treated_before: 20, treated_after: 32,
    control_before: 18, control_after: 20, discount_cost: 200,
    contribution_per_room_night: 80, design_quality: 'validated_matched',
    pretrend_status: 'passed', sample_size: 60, source_quality: 'verified',
  });
  const promotionPayload = context.buildPayload('promotion_incrementality');
  assert.equal(promotionPayload.promotion_name, '连住优惠');
  assert.equal(promotionPayload.contribution_per_incremental_room_night, 80);
  assert.ok(!Object.hasOwn(promotionPayload, 'campaign_name'));

  const bookability = context.forms.bookability_gap;
  Object.assign(bookability, {
    pms_expected_sellable: 6, adults: 2, children: 1, benefits: '含早、可取消',
    search_status: 'bookable', detail_status: 'bookable', pre_checkout_status: 'unavailable',
    real_demand_estimate: 4, source_quality: 'verified', source_reference: 'journey#1',
  });
  const bookabilityPayload = context.buildPayload('bookability_gap');
  assert.equal(bookabilityPayload.pms_expected_sellable, 6);
  assert.equal(bookabilityPayload.real_demand_estimate, 4);
  assert.equal(bookabilityPayload.observations.length, 1);
  assert.deepEqual(bookabilityPayload.observations[0].benefits, ['含早', '可取消']);
  assert.equal(bookabilityPayload.observations[0].pre_checkout, 'unavailable');
  assert.match(bookabilityPayload.observations[0].observed_at, /T\d{2}:\d{2}:\d{2}$/);

  const ai = context.forms.ai_guest_acquisition;
  Object.assign(ai, {
    intent: '凌晨到店且有停车位的酒店', source_quality: 'manual_verified',
    source_reference: 'ai-observation-packet#1-3',
  });
  ai.repeats.forEach((row, index) => Object.assign(row, {
    observed_at: `2026-08-22T09:0${index + 1}`,
    evidence_ref: `ai-observation#${index + 1}`,
  }));
  Object.assign(ai.repeats[0], { hotel_identified: true, facts_checked: true, facts_correct: true });
  const aiPayload = context.buildPayload('ai_guest_acquisition');
  assert.equal(aiPayload.observations.length, 3);
  assert.equal(typeof aiPayload.observations[0].facts_checked, 'boolean');
  assert.equal(typeof aiPayload.observations[0].facts_correct, 'boolean');
  assert.equal(aiPayload.observations[0].observed_at, '2026-08-22T09:01:00');
  assert.deepEqual(aiPayload.observations.map(row => row.evidence_ref), [
    'ai-observation#1', 'ai-observation#2', 'ai-observation#3',
  ]);
  assert.equal(new Set(aiPayload.observations.map(row => row.observed_at)).size, 3);
});

test('frontend understands each engine canonical result status and metric names', () => {
  const context = loadComponent();
  assert.equal(context.status({ verdict: 'supported' }), 'supported');
  assert.equal(context.status({ gap_detected: true }), 'gap_detected');
  assert.equal(context.status({ aligned: true }), 'aligned');
  assert.equal(context.status({ blocked_by_missing_evidence: true }), 'blocked_by_missing_evidence');

  assert.deepEqual(
    context.resultMetrics('service_promise_risk', {
      shortage_quantity: 3, surplus_quantity: null, risk_amount: 90,
    }).map(row => row[0]),
    ['短缺数量', '剩余容量', '预计风险金额'],
  );
  assert.equal(
    context.resultMetrics('bookability_gap', { potential_loss: 4 })[2][1],
    '4 间夜',
  );
  assert.equal(
    context.resultMetrics('ai_guest_acquisition', {
      gate_pass_rates: { hotel_identified: { pass_rate_percent: 66.67 } },
    })[0][1],
    '66.67%',
  );
});

test('backend keeps every run in tenant hotel date scope with append-only readback', () => {
  for (const marker of [
    "Route::get('/overview', 'OperatingOpportunity/overview')",
    "Route::post('/evaluate', 'OperatingOpportunity/evaluate')",
    "Route::post('/priority', 'OperatingOpportunity/priority')",
  ]) assert.ok(routes.includes(marker), marker);

  for (const marker of [
    "resolveSingleHotelScope('operation.view')",
    "'operation.execute'",
    'getPermittedHotelIds()',
    'hasHotelPermission($hotelId, $capability)',
  ]) assert.ok(controller.includes(marker), marker);

  for (const marker of [
    "->where('tenant_id', $tenantId)",
    "->where('system_hotel_id', $hotelId)",
    "->where('business_date', $businessDate)",
    "'readback_verified' => true",
    'hash_equals($inputDigest',
    'hash_equals($resultDigest',
    "'external_write_allowed' => false",
    "'today_saved_run' => $savedPriorityRun",
    "'today_state' => $savedPriorityRun === null",
    'assertObservationSourceQualityMatches',
    'isDuplicateKeyConflict',
    'isRetryableWriteConflict',
    'for ($attempt = 1; $attempt <= 3; $attempt++)',
  ]) assert.ok(labService.includes(marker), marker);

  for (const marker of [
    'CREATE TABLE IF NOT EXISTS `operating_opportunity_runs`',
    'UNIQUE KEY `uniq_operating_opportunity_idempotency`',
    '`input_digest` CHAR(64)',
    '`result_digest` CHAR(64)',
  ]) assert.ok(migration.includes(marker), marker);
  assert.doesNotMatch(migration.toUpperCase(), /\b(?:UPDATE|DELETE)\b/);
});
