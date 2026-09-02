import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = path => readFileSync(path, 'utf8');
const componentSource = read('public/components/system/operating-opportunity-lab.js');
const pageFragment = read('resources/frontend/templates/fragments/19b-page-operating-opportunities.html');
const appMain = read('public/app-main.js');
const appMainComponents = read('public/components/system/app-main-components.js');
const appMainComponentsLoader = read('public/components/system/app-main-components-loader.js');
const indexHtml = read('public/index.html');
const systemStatic = read('public/system-static.js');
const routes = readRouteContractSource(process.cwd());
const controller = read('app/controller/OperatingOpportunity.php');
const labService = read('app/service/OperatingOpportunityLabService.php');
const selector = read('app/service/DailyOneThingService.php');
const inputService = read('app/service/DailyOneThingInputService.php');
const lifecycle = read('app/service/OperationActionLifecycleService.php');

const componentDefinition = () => {
  const runtimeWindow = { SUXI_SYSTEM_COMPONENTS: {} };
  const Vue = { h: () => ({}) };
  new Function('window', 'Vue', componentSource)(runtimeWindow, Vue);
  return runtimeWindow.SUXI_SYSTEM_COMPONENTS.OperatingOpportunityLabBody;
};

test('daily one thing has a findable authenticated entry and current lazy assets', () => {
  for (const marker of [
    "currentPage === 'operating-opportunities'",
    '<operating-opportunity-lab',
    ':hotels="hotels"',
    ':request="managerCapabilityRequest"',
    ':open-task="openHomeOperatingScheduleItem"',
    '@open-operations="openHomeOperatingScheduleItem"',
  ]) assert.ok(pageFragment.includes(marker), marker);

  for (const marker of [
    "path: 'operating-opportunities'",
    "'经营机会': 'operating-opportunities'",
  ]) assert.ok(systemStatic.includes(marker), marker);
  assert.ok(appMain.includes('openHomeOperatingScheduleItem'));
  assert.ok(appMainComponents.includes("components/system/operating-opportunity-lab.js?v="));
  assert.ok(appMainComponents.includes("requireSystemComponent('OperatingOpportunityLabBody')"));

  const componentHash = createHash('sha256').update(appMainComponents).digest('hex').slice(0, 10);
  const loaderAsset = appMainComponentsLoader.match(/components\/system\/app-main-components\.js\?v=([^'"\s]+)/)?.[0] || '';
  assert.match(loaderAsset, new RegExp(`h${componentHash}$`), 'loader must end with the current component source hash');
  assert.ok(indexHtml.includes(loaderAsset), 'authenticated entry must pin the same component asset');
});

test('page exposes one complete card and no longer accepts manual candidate calculators', () => {
  for (const marker of [
    "'data-testid': 'daily-one-thing-card'",
    "'data-testid': 'daily-one-thing-problem'",
    '事实依据', '建议动作', '预期观察指标', '适用范围', '风险', '负责人和时间',
    '四维排序', '审批前外部写入：0 次', '等待同口径复盘',
    "'data-testid': 'daily-one-thing-lifecycle'",
  ]) assert.ok(componentSource.includes(marker), marker);

  for (const forbidden of [
    'makeForms', 'service_promise_risk:', 'promotion_incrementality:',
    "'/operating-opportunities/evaluate'", 'buildPayload(featureKey)',
  ]) assert.ok(!componentSource.includes(forbidden), forbidden);
  assert.ok(componentSource.includes("'/operating-opportunities/priority'"));
  assert.ok(componentSource.includes("this.$emit('open-operations'"));
  assert.ok(appMainComponents.includes("onOpenOperations: payload => this.$emit('open-operations', payload)"));
});

test('overview rejects cross-scope or non-v2 responses and clears stale scope', async () => {
  const definition = componentDefinition();
  assert.ok(definition);
  const context = {
    ...definition.data(),
    ...definition.methods,
    hotelId: '80',
    businessDate: '2026-08-26',
    loadedScope: '79|2026-08-25',
    overview: { stale: true },
    request: async () => ({
      code: 200,
      data: {
        system_hotel_id: 81,
        business_date: '2026-08-26',
        today_preview: {
          contract_version: 'daily_one_thing.v2',
          selection_policy: { full_candidate_list_exposed: false },
        },
      },
    }),
  };
  const result = await context.loadOverview.call(context);
  assert.equal(result, null);
  assert.equal(context.overview, null);
  assert.match(context.error, /当前酒店、日期和唯一选择合同/);
});

test('save requires run, intent, v2 lifecycle, zero writes and exact refresh recovery', async () => {
  const definition = componentDefinition();
  const run = {
    id: 901, system_hotel_id: 80, business_date: '2026-08-26', feature_key: 'daily_one_thing',
    input_digest: 'a'.repeat(64), result_digest: 'b'.repeat(64),
  };
  const intent = {
    id: 301, source_module: 'daily_one_thing', tasks: [],
    action_management: { contract_version: 'operation_action_card.v2' },
  };
  const context = {
    ...definition.data(), ...definition.methods,
    hotelId: '80', businessDate: '2026-08-26', canSave: true,
    request: async () => ({
      code: 200,
      data: {
        readback_verified: true, run, execution_intent: intent,
        external_action_triggered: false, external_write_count: 0,
      },
    }),
    loadOverview: async () => ({
      today_saved_run: run, today_execution_intent_id: 301, today_state: 'saved_current',
    }),
    notify: () => {},
  };
  const saved = await context.savePriority.call(context);
  assert.equal(saved.id, 301);
  assert.equal(context.error, '');
});

test('a stale fact preview cannot replace or duplicate an existing original action', () => {
  const definition = componentDefinition();
  const canSave = definition.computed.canSave.call({
    hotelId: '80',
    selected: { candidate_key: 'gap:ctrip:target_date_source_rows' },
    saving: false,
    isSavedCurrent: false,
    intentId: 106,
  });

  assert.equal(canSave, false);
  assert.ok(componentSource.includes("this.intentId ? h('button'"));
  assert.ok(componentSource.includes('继续原任务'));
});

test('backend selection, persistence and lifecycle are bound to approved source contracts', () => {
  for (const marker of [
    "Route::get('/overview', 'OperatingOpportunity/overview')",
    "Route::post('/priority', 'OperatingOpportunity/priority')",
  ]) assert.ok(routes.includes(marker), marker);
  for (const marker of [
    "resolveSingleHotelScope('operation.view')",
    "'operation.execute'",
    '(int)($this->currentUser->id ?? 0)',
  ]) assert.ok(controller.includes(marker), marker);

  for (const marker of [
    'DailyOneThingInputService::CONTRACT_VERSION',
    "'source_digest' => (string)$sourceInput['source_digest']",
    "'selected_candidate_digest' => (string)$selected['content_digest']",
    'ensureDailyExecutionIntent',
    'assertDailyIntentCurrent',
    'buildDailyStrictFactCountReadback',
    "'external_write_count' => 0",
    "'today_execution_intent' => $dailyIntent",
    "'today_lifecycle_status'",
    '$savedPriorityIsCurrent = $strictFactReady && $savedPriorityRun !== null',
    '$dailyIntent = $savedPriorityRun === null',
  ]) assert.ok(labService.includes(marker), marker);

  for (const marker of [
    "['not_saved', 'saved_without_lifecycle'].includes(String(this.overview?.today_state || ''))",
    '&& !this.isSavedCurrent',
    "this.intentId ? h('button'",
    '旧快照已保留；当前事实身份已变化，不能静默改写原任务。',
  ]) assert.ok(componentSource.includes(marker), marker);

  for (const marker of [
    "private const SOURCE_TYPES = ['strict_fact_signal', 'saved_question', 'explicit_data_gap']",
    "'impact',", "'urgency',", "'evidence_strength',", "'execution_cost',",
    "'full_candidate_list_exposed' => false",
  ]) assert.ok(selector.includes(marker), marker);
  assert.ok(!selector.includes("'candidates' => array_values"));

  for (const marker of [
    'DualOtaFieldClosureService', 'OperatingQuestionService',
    'ctrip_target_date_source_rows_missing', 'ctrip_core_facts_missing',
    'gap:meituan:traffic_only_scope', 'ota_channel_data_quality',
  ]) assert.ok(inputService.includes(marker), marker);
  for (const marker of [
    "DAILY_CARD_CONTRACT_VERSION = 'operation_action_card.v2'",
    "'evidence_recorded'", "'review_pending'", 'buildDailyOneThingPendingCard',
  ]) assert.ok(lifecycle.includes(marker), marker);
});
