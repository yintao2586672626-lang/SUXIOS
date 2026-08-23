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
const approvalService = read('app/service/OperatingOpportunityApprovalService.php');
const protectedCapability = read('app/service/ProtectedCapabilityService.php');
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

test('frontend submits exact calculator contracts and cannot promote manual facts to trusted status', () => {
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
  assert.equal(promisePayload.source_quality, 'manual_unverified');
  assert.equal(promisePayload.source_quality_status, 'manual_unverified');

  const promotion = context.forms.promotion_incrementality;
  Object.assign(promotion, {
    promotion_name: '连住优惠', treated_before: 20, treated_after: 32,
    control_before: 18, control_after: 20, discount_cost: 200,
    treated_before_exposure: 100, treated_after_exposure: 120,
    control_before_exposure: 90, control_after_exposure: 100,
    contribution_per_room_night: 80, design_quality: 'validated_matched',
    pretrend_status: 'passed', sample_size: 60, source_quality: 'verified',
  });
  const promotionPayload = context.buildPayload('promotion_incrementality');
  assert.equal(promotionPayload.promotion_name, '连住优惠');
  assert.equal(promotionPayload.contribution_per_incremental_room_night, 80);
  assert.equal(promotionPayload.treated_before_exposure, 100);
  assert.equal(promotionPayload.treated_after_exposure, 120);
  assert.equal(promotionPayload.control_before_exposure, 90);
  assert.equal(promotionPayload.control_after_exposure, 100);
  assert.equal(promotionPayload.source_quality, 'manual_unverified');
  assert.equal(promotionPayload.source_quality_status, 'manual_unverified');
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
  assert.equal(bookabilityPayload.source_quality, 'manual_unverified');
  assert.equal(bookabilityPayload.source_quality_status, 'manual_unverified');
  assert.equal(bookabilityPayload.observations[0].source_quality, 'manual_unverified');

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
  assert.equal(aiPayload.source_quality, 'manual_unverified');
  assert.equal(aiPayload.source_quality_status, 'manual_unverified');
  assert.deepEqual(
    aiPayload.observations.map(row => row.source_quality),
    ['manual_unverified', 'manual_unverified', 'manual_unverified'],
  );
});

test('manual source status is fixed, non-editable and timestamped in Asia/Shanghai', () => {
  const context = loadComponent();
  const sourceFieldsStart = componentSource.indexOf('const sourceFields =');
  const sourceFieldsEnd = componentSource.indexOf('const latest =', sourceFieldsStart);
  const sourceFieldsBlock = componentSource.slice(sourceFieldsStart, sourceFieldsEnd);
  assert.ok(sourceFieldsStart >= 0 && sourceFieldsEnd > sourceFieldsStart);
  assert.match(sourceFieldsBlock, /用户提供 · 未核验/);
  assert.match(sourceFieldsBlock, /opportunity-source-status-/);
  assert.doesNotMatch(sourceFieldsBlock, /select\(form, 'source_quality'/);
  assert.doesNotMatch(sourceFieldsBlock, /manual_verified|readback_verified|\['verified'/);
  assert.match(componentSource, /timeZone: 'Asia\/Shanghai'/);
  assert.doesNotMatch(componentSource, /getTimezoneOffset\(\)/);
  assert.equal(context.sourceQualityText('manual_unverified'), '用户提供 · 未核验');
  assert.equal(context.sourceQualityText('readback_verified'), 'readback_verified');
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
      calculation_status: 'provisional_manual_estimate',
      gate_pass_rates: { hotel_identified: { pass_rate_percent: null } },
      provisional_metrics: {
        gate_pass_rates: { hotel_identified: { pass_rate_percent: 66.67 } },
      },
    })[0][1],
    '66.67%',
  );
  assert.equal(
    context.resultMetrics('promotion_incrementality', {
      verdict: 'indeterminate',
      provisional_metrics: { incremental_rate: 0.125, incremental_room_nights: 15 },
    })[0][1],
    '12.5%',
  );
  for (const marker of [
    '参与组·活动前可售间夜',
    '参与组·活动后可售间夜',
    '对照组·活动前可售间夜',
    '对照组·活动后可售间夜',
    '人工输入估算，仅供核对',
  ]) assert.ok(componentSource.includes(marker), marker);
});

test('backend keeps every run in tenant hotel date scope with append-only readback', () => {
  for (const marker of [
    "Route::get('/overview', 'OperatingOpportunity/overview')",
    "Route::post('/evaluate', 'OperatingOpportunity/evaluate')",
    "Route::post('/priority', 'OperatingOpportunity/priority')",
    "Route::post('/runs/:id/pending-approval', 'OperatingOpportunity/pendingApproval')",
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
    'assertReadbackIntegrity',
    'readbackInputDigest',
    'readbackResultDigest',
    "'external_write_allowed' => false",
    "'today_saved_run' => $savedPriorityRun",
    "'today_state' => $savedPriorityRun === null",
    'assertObservationSourceQualityMatches',
    'isDuplicateKeyConflict',
    'isRetryableWriteConflict',
    'for ($attempt = 1; $attempt <= 3; $attempt++)',
    "record_readback_status'] = 'readback_verified'",
  ]) assert.ok(labService.includes(marker), marker);

  for (const marker of [
    'CREATE TABLE IF NOT EXISTS `operating_opportunity_runs`',
    'UNIQUE KEY `uniq_operating_opportunity_idempotency`',
    '`input_digest` CHAR(64)',
    '`result_digest` CHAR(64)',
  ]) assert.ok(migration.includes(marker), marker);
  assert.doesNotMatch(migration.toUpperCase(), /\b(?:UPDATE|DELETE)\b/);
});

test('pending approval bridge is user-triggered, source-bound and stops before task or OTA execution', () => {
  for (const marker of [
    'operating_opportunity_pending_approval.v1',
    '$expectedInputDigest',
    '$expectedResultDigest',
    'assertCurrentRun',
    'record_readback_status',
    "'approval_purpose' => 'evidence_review_only'",
    "'operation_task_created' => false",
    "'automatic_approval' => false",
    "'automatic_execution' => false",
    "'ota_write' => false",
    "'pms_write' => false",
    "'external_message' => false",
  ]) assert.ok(approvalService.includes(marker), marker);

  assert.ok(protectedCapability.includes("api/operating-opportunities/runs/*/pending-approval"));
  assert.match(componentSource, /unknown_after_write_attempt/);
  assert.match(componentSource, /opportunity-pending-approval-/);
  assert.match(componentSource, /\/operation\/execution-intents\/\$\{intentId\}/);
  assert.match(componentSource, /intent\.tasks\.length !== 0/);
  assert.match(componentSource, /currentPage = 'ops-track'/);
  assert.doesNotMatch(componentSource, /execution-intents\/\$\{[^}]+\}\/approve/);
});

test('frontend independently reads back the same pending intent and keeps manual facts unverified', async () => {
  const context = loadComponent();
  const run = {
    id: 91,
    tenant_id: 10,
    system_hotel_id: 80,
    feature_key: 'service_promise_risk',
    business_date: '2026-08-22',
    source_quality_status: 'manual_unverified',
    input_digest: 'a'.repeat(64),
    result_digest: 'b'.repeat(64),
    record_readback_status: 'readback_verified',
    result: { calculation_status: 'provisional_manual_estimate' },
  };
  const intent = {
    id: 501,
    tenant_id: 10,
    hotel_id: 80,
    source_module: 'operating_loop_approval',
    source_record_id: 91,
    date_start: '2026-08-22',
    date_end: '2026-08-22',
    status: 'pending_approval',
    approved_by: 0,
    approved_at: null,
    tasks: [],
    target_value: { auto_write_ota: false },
    evidence: {
      boundaries: { automatic_approval: false, automatic_execution: false },
      evidence_refs: [{ table: 'operating_opportunity_runs', row_ids: [91], readback_verified: true }],
    },
  };
  context.overview = {
    tenant_id: 10,
    approval_readback_status: 'ready',
    approval_links: {},
  };
  context.notify = () => {};
  const calls = [];
  context.request = async (path, options = {}) => {
    calls.push([path, options]);
    if (path === '/operating-opportunities/runs/91/pending-approval') {
      return {
        code: 200,
        data: {
          contract_version: 'operating_opportunity_pending_approval.v1',
          status: 'pending_approval',
          persistence_status: 'readback_verified',
          execution_task_created: false,
          external_action_triggered: false,
          boundaries: { automatic_approval: false, operation_task_created: false, ota_write: false },
          execution_intent: intent,
        },
      };
    }
    if (path.startsWith('/operation/execution-intents/501?')) return { code: 200, data: intent };
    throw new Error(`unexpected request ${path}`);
  };
  context.loadOverview = async () => {
    context.overview.approval_links['91'] = {
      intent_id: 501,
      hotel_id: 80,
      persistence_status: 'readback_verified',
      status: 'pending_approval',
      fact_status: 'manual_unverified',
      task_count: 0,
    };
    return context.overview;
  };

  assert.equal(context.canCreatePendingApproval(run), true);
  await context.createPendingApproval(run, false);
  assert.equal(calls.length, 2);
  assert.equal(calls[0][0], '/operating-opportunities/runs/91/pending-approval');
  assert.match(calls[1][0], /^\/operation\/execution-intents\/501\?hotel_id=80$/);
  const body = JSON.parse(calls[0][1].body);
  assert.deepEqual(body, {
    hotel_id: 80,
    business_date: '2026-08-22',
    expected_input_digest: 'a'.repeat(64),
    expected_result_digest: 'b'.repeat(64),
  });
  assert.equal(context.overview.approval_links['91'].fact_status, 'manual_unverified');
  assert.equal(context.overview.approval_links['91'].task_count, 0);
});
