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
    const labHash = createHash('sha256').update(componentSource).digest('hex').slice(0, 10);
    assert.match(
      appMainComponents,
      new RegExp(`components/system/operating-opportunity-lab\\.js\\?v=[^'"\\s]*h${labHash}`),
      'app-main component registry must pin the current operating-opportunity component hash',
    );
});

test('page exposes one complete card and no longer accepts manual candidate calculators', () => {
  for (const marker of [
    "'data-testid': 'daily-one-thing-card'",
    "'data-testid': 'daily-one-thing-problem'",
    '事实依据', '建议动作', '预期观察指标', '适用范围', '风险', '负责人和时间',
    '四维排序', '审批前外部写入：0 次', '等待同口径复盘',
    "'data-testid': 'daily-one-thing-lifecycle'",
    "'data-testid': 'daily-one-thing-explanation'",
    "'data-testid': 'daily-one-thing-why-now'",
    "'data-testid': 'daily-one-thing-why-recommended'",
    "'data-testid': 'daily-one-thing-personalization'",
    "'data-testid': 'daily-one-thing-personalized-preview'",
    "'data-testid': 'daily-one-thing-preview-feedback'",
    "'data-testid': 'daily-one-thing-history-integrity-warning'",
    "'aria-busy': this.feedbackSaving ? 'true' : 'false'",
    "'aria-live': 'polite'",
  ]) assert.ok(componentSource.includes(marker), marker);

  for (const forbidden of [
    'makeForms', 'service_promise_risk:', 'promotion_incrementality:',
    "'/operating-opportunities/evaluate'", 'buildPayload(featureKey)',
  ]) assert.ok(!componentSource.includes(forbidden), forbidden);
  assert.ok(componentSource.includes("'/operating-opportunities/priority'"));
  assert.ok(componentSource.includes("'/operating-opportunities/daily-preview/feedback'"));
  assert.ok(componentSource.includes('savingScope'));
  assert.ok(componentSource.includes('feedbackSavingScope'));
  assert.ok(componentSource.includes("if (this.savingScope === mutationScope) this.savingScope = ''"));
  assert.ok(componentSource.includes("if (this.feedbackSavingScope === mutationScope) this.feedbackSavingScope = ''"));
  assert.ok(componentSource.includes('normalizedExplanation(selected || {}, null)'));
  assert.ok(!componentSource.includes('selectedReceipt'));
  assert.ok(componentSource.includes("['not_saved', 'saved_without_lifecycle'].includes"));
  assert.ok(componentSource.includes('不会把系统故障伪装成数据缺口'));
  assert.ok(componentSource.includes("this.$emit('open-operations'"));
  assert.ok(appMainComponents.includes("onOpenOperations: payload => this.$emit('open-operations', payload)"));
});

test('personalized preview feedback is exact, user initiated, and cannot create a shared action', async () => {
  const definition = componentDefinition();
  const selectionDigest = 'a'.repeat(64);
  const contextDigest = 'b'.repeat(64);
  const decisionDigest = 'c'.repeat(64);
  const calls = [];
  const context = {
    ...definition.data(),
    ...definition.methods,
    hotelId: '80',
    businessDate: '2026-08-29',
    personalizedSelected: {
      candidate_key: 'gap:ctrip:core_facts',
      content_digest: selectionDigest,
    },
    personalizationReceipt: {
      contract_version: 'daily_one_thing_personalization.v1',
      context_digest: contextDigest,
      decision_digest: decisionDigest,
    },
    request: async (path, options) => {
      calls.push({ path, payload: JSON.parse(options.body) });
      return {
        code: 200,
        data: {
          readback_verified: true,
          system_hotel_id: 80,
          business_date: '2026-08-29',
          hotel_shared_daily_item_changed: false,
          execution_intent_created: false,
          external_write_count: 0,
        },
      };
    },
    notify: () => {},
  };

  const saved = await context.submitPreviewFeedback.call(context, 'accepted', 'useful');
  assert.equal(saved.readback_verified, true);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, '/operating-opportunities/daily-preview/feedback');
  assert.equal(calls[0].payload.expected_selection_digest, selectionDigest);
  assert.equal(calls[0].payload.expected_context_digest, contextDigest);
  assert.equal(calls[0].payload.expected_decision_digest, decisionDigest);
  assert.equal(calls[0].payload.feedback_status, 'accepted');
  assert.equal(calls[0].payload.reason_code, 'useful');
  assert.equal(context.feedbackStatus, 'useful');
  assert.ok(calls[0].payload.idempotency_key.length <= 96);
});

test('overview restores exact recorded feedback and keeps the stable slot locked after refresh', async () => {
  const definition = componentDefinition();
  const context = {
    ...definition.data(),
    ...definition.methods,
    hotelId: '80',
    businessDate: '2026-08-29',
    request: async () => ({
      code: 200,
      data: {
        system_hotel_id: 80,
        business_date: '2026-08-29',
        today_preview: {
          contract_version: 'daily_one_thing.v2',
          selection_policy: { full_candidate_list_exposed: false },
        },
        personalized_today_preview: {
          contract_version: 'daily_one_thing.v2',
          personalization_receipt: {
            contract_version: 'daily_one_thing_personalization.v1',
            scope: { hotel_id: 80 },
            facts_changed: false,
            eligibility_changed: false,
            external_write_authorized: false,
            current_feedback: {
              status: 'recorded',
              readback_verified: true,
              feedback_status: 'accepted',
              reason_code: 'useful',
            },
          },
        },
      },
    }),
  };

  const loaded = await context.loadOverview.call(context);
  assert.equal(loaded.system_hotel_id, 80);
  assert.equal(context.feedbackStatus, 'useful');
  assert.equal(context.feedbackReadbackBlocked, false);
});

test('an explicit feedback readback failure blocks writes while a legacy missing field stays compatible', async () => {
  const definition = componentDefinition();
  const response = currentFeedback => ({
    code: 200,
    data: {
      system_hotel_id: 80,
      business_date: '2026-08-29',
      today_preview: {
        contract_version: 'daily_one_thing.v2',
        selection_policy: { full_candidate_list_exposed: false },
      },
      personalized_today_preview: {
        contract_version: 'daily_one_thing.v2',
        personalization_receipt: {
          contract_version: 'daily_one_thing_personalization.v1',
          scope: { hotel_id: 80 },
          facts_changed: false,
          eligibility_changed: false,
          external_write_authorized: false,
          ...(currentFeedback === undefined ? {} : { current_feedback: currentFeedback }),
        },
      },
    },
  });
  const context = {
    ...definition.data(), ...definition.methods,
    hotelId: '80', businessDate: '2026-08-29',
    request: async () => response({
      status: 'unavailable',
      readback_verified: false,
      reason_code: 'daily_preview_feedback_readback_unavailable',
    }),
  };
  await context.loadOverview.call(context);
  assert.equal(context.feedbackReadbackBlocked, true);
  assert.equal(context.feedbackStatus, '');
  assert.equal(context.feedbackReadbackReason, 'daily_preview_feedback_readback_unavailable');

  context.request = async () => response(undefined);
  await context.loadOverview.call(context);
  assert.equal(context.feedbackReadbackBlocked, false, 'old API without the field remains compatible');
  assert.ok(componentSource.includes("'data-testid': 'daily-one-thing-feedback-readback-blocked'"));
});

test('stale saved facts cannot trigger a doomed second save and preserve entry to the original intent', () => {
  const definition = componentDefinition();
  const staleContext = {
    hotelId: '80',
    selected: { candidate_key: 'current-fact' },
    overview: { today_state: 'saved_stale' },
    saving: false,
    isSavedCurrent: false,
  };
  assert.equal(definition.computed.canSave.call(staleContext), false);
  assert.equal(definition.computed.currentStatus.call(staleContext), 'draft');

  const recoverableContext = {
    ...staleContext,
    overview: { today_state: 'saved_without_lifecycle' },
  };
  assert.equal(definition.computed.canSave.call(recoverableContext), true);
  assert.ok(componentSource.includes("this.intentId ? h('button'"));
  assert.ok(componentSource.includes('查看已保留的原任务'));
});

test('save and feedback locks are scoped so switching hotel never disables the new scope', async () => {
  const definition = componentDefinition();
  let resolveSave;
  const saveResponse = new Promise(resolve => { resolveSave = resolve; });
  const saveContext = {
    ...definition.data(),
    ...definition.methods,
    hotelId: '80', businessDate: '2026-08-29', canSave: true,
    request: async () => saveResponse,
    notify: () => {},
  };
  Object.defineProperty(saveContext, 'saving', {
    get() { return this.savingScope === `${Number(this.hotelId)}|${this.businessDate}`; },
  });
  const pendingSave = saveContext.savePriority.call(saveContext);
  await Promise.resolve();
  assert.equal(saveContext.saving, true);
  saveContext.hotelId = '81';
  assert.equal(saveContext.saving, false, 'old hotel save lock must not disable the new hotel');
  resolveSave({ code: 200, data: {} });
  await pendingSave;
  assert.equal(saveContext.savingScope, '');

  let resolveFeedback;
  const feedbackResponse = new Promise(resolve => { resolveFeedback = resolve; });
  const feedbackContext = {
    ...definition.data(),
    ...definition.methods,
    hotelId: '80', businessDate: '2026-08-29',
    personalizedSelected: { content_digest: 'a'.repeat(64) },
    personalizationReceipt: {
      context_digest: 'b'.repeat(64),
      decision_digest: 'c'.repeat(64),
    },
    request: async () => feedbackResponse,
    notify: () => {},
  };
  Object.defineProperty(feedbackContext, 'feedbackSaving', {
    get() { return this.feedbackSavingScope === `${Number(this.hotelId)}|${this.businessDate}`; },
  });
  const pendingFeedback = feedbackContext.submitPreviewFeedback.call(
    feedbackContext,
    'accepted',
    'useful',
  );
  await Promise.resolve();
  assert.equal(feedbackContext.feedbackSaving, true);
  feedbackContext.hotelId = '81';
  assert.equal(feedbackContext.feedbackSaving, false, 'old hotel feedback lock must not disable the new hotel');
  resolveFeedback({ code: 200, data: {} });
  await pendingFeedback;
  assert.equal(feedbackContext.feedbackSavingScope, '');
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
    'DailyOneThingPersonalizationService',
    "'personalized_today_preview' => $personalizedPriority",
    'recordDailyPreviewFeedback',
    "'source_digest' => (string)$sourceInput['source_digest']",
    "'selected_candidate_digest' => (string)$selected['content_digest']",
    'ensureDailyExecutionIntent',
    'assertDailyIntentCurrent',
    'assertDailySourceReady',
    "'strict_fact_status' => (string)($sourceInput['strict_fact_status'] ?? 'source_unavailable')",
    'buildDailyStrictFactCountReadback',
    "'external_write_count' => 0",
    "'today_execution_intent' => $dailyIntent",
    "'today_lifecycle_status'",
  ]) assert.ok(labService.includes(marker), marker);

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
