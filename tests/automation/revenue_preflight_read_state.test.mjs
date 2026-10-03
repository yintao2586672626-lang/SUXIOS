import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';
import * as Vue from 'vue';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_PREFLIGHT_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const fullSource = readFileSync(process.env.SUXIOS_PREFLIGHT_STATIC_SOURCE
  || new URL('../../public/revenue-ai-static.js', import.meta.url), 'utf8');
const contract = readFileSync(new URL('../../public/revenue-overview-contract-static.js', import.meta.url), 'utf8');
const start = main.indexOf('\n            const agentPricingGenerationPreflightSummary =') + 1;
const end = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
assert.ok(start > 0 && end, 'actual agent preflight computed must be extractable');
const computedSource = main.slice(start, start + 1 + end.index);
const copy = value => JSON.parse(JSON.stringify(value));
const countKeys = ['targetHotelCount', 'targetDateRows', 'roomTypeCount', 'createCandidateCount',
  'skippedCandidateCount', 'pendingSuggestionCount'];

function preflight(overrides = {}) {
  return {
    status: 'ready_for_manual_generation', reason: 'pricing_generation_candidates_ready',
    target_hotel_ids: [81], target_hotel_count: 1, target_date_rows: 12, room_type_count: 3,
    create_candidate_count: 5, skipped_candidate_count: 2, pending_suggestion_count: 0,
    source_scope: 'ctrip_ota_channel', source_channels: ['ctrip'],
    can_generate_pending_suggestions: true, read_only: true, auto_write_ota: false, advisory_only: true,
    target_page: 'agent-center', target_agent_tab: 'revenue', target_revenue_tab: 'suggestions',
    target_filter: { hotel_id: 81, business_date: '2026-09-12', source: 'ctrip' },
    next_action: '人工核对后再生成待审建议。',
    required_inputs: [{ code: 'room_types', source: 'manual', status: 'ready', next_action: '' }],
    ...overrides,
  };
}

// Actual full helper and actual main computed, with reactive Vue inputs. No
// fetch, clock, source-status implementation or template logic is mirrored.
function harness(initialOverview = { pricing_generation_preflight: preflight() }) {
  const refs = { revenueAiOverview: Vue.ref(initialOverview), revenueAiOverviewError: Vue.ref(''), revenueAiOverviewLoading: Vue.ref(false), revenueAiStaticLoading: Vue.ref(false) };
  const context = vm.createContext({ window: {}, URLSearchParams, computed: Vue.computed, ...refs });
  vm.runInContext(contract, context);
  vm.runInContext(fullSource, context);
  const helper = context.window.SUXI_REVENUE_AI_STATIC.buildRevenueAiPricingGenerationPreflightSummary;
  assert.equal(typeof helper, 'function');
  context.revenueAiBuildPricingGenerationPreflightSummary = helper;
  vm.runInContext(`${computedSource}\nglobalThis.summaryRef=agentPricingGenerationPreflightSummary;`, context);
  return { refs, helper, summary: () => context.summaryRef.value };
}

function assertUnavailableFacts(summary, status) {
  assert.equal(summary.visible, true);
  assert.equal(summary.factsAvailable, false);
  assert.equal(summary.status, status);
  assert.match(summary.title, /预检/);
  assert.ok(summary.className);
  for (const key of countKeys) assert.equal(summary[key], null, `${key} must not turn unavailable evidence into stale counts or zero`);
  assert.notEqual(summary.canGeneratePendingSuggestions, true);
  assert.equal(summary.autoWriteOta, false);
  if (status === 'loading') {
    assert.match(summary.statusLabel, /读取|加载/);
    assert.match(`${summary.detailText || ''} ${summary.reasonText || ''}`, /读取|加载|等待/);
  } else {
    assert.match(summary.statusLabel, /失败|异常|不可用/);
    assert.match(summary.nextAction, /远期定价台账/);
    assert.match(summary.nextAction, /重新读取/);
  }
}

test('actual computed replaces retained ready counts with loading during a current overview read', () => {
  const h = harness(), ready = h.summary();
  assert.equal(ready.status, 'ready_for_manual_generation'); assert.equal(ready.targetDateRows, 12);
  h.refs.revenueAiOverviewLoading.value = true;
  assertUnavailableFacts(h.summary(), 'loading');
  assert.notEqual(h.summary(), ready);
  assert.equal(h.refs.revenueAiOverview.value.pricing_generation_preflight.target_date_rows, 12, 'stored overview is retained, but not presented as current facts');
});

test('actual computed reports a current overview failure instead of showing retained ready preflight', () => {
  const h = harness(); h.summary();
  h.refs.revenueAiOverviewError.value = '合成总览读取失败 503';
  assertUnavailableFacts(h.summary(), 'failed');
  assert.match(`${h.summary().detailText || ''} ${h.summary().reasonText || ''}`, /合成总览读取失败 503/);
  assert.equal(h.refs.revenueAiOverview.value.pricing_generation_preflight.status, 'ready_for_manual_generation');
});

test('reactive retry transitions ready to loading to failed and back to verified zero counts', () => {
  const h = harness(); assert.equal(h.summary().createCandidateCount, 5);
  h.refs.revenueAiOverviewLoading.value = true; assertUnavailableFacts(h.summary(), 'loading');
  h.refs.revenueAiOverviewError.value = '合成暂时不可用'; h.refs.revenueAiOverviewLoading.value = false;
  assertUnavailableFacts(h.summary(), 'failed');
  h.refs.revenueAiOverviewLoading.value = true; assertUnavailableFacts(h.summary(), 'loading');
  h.refs.revenueAiOverview.value = { pricing_generation_preflight: preflight({
    target_hotel_ids: [], target_hotel_count: 0, target_date_rows: 0, room_type_count: 0,
    create_candidate_count: 0, skipped_candidate_count: 0, pending_suggestion_count: 0,
    status: 'partial', can_generate_pending_suggestions: false,
  }) };
  h.refs.revenueAiOverviewError.value = ''; h.refs.revenueAiOverviewLoading.value = false;
  assert.equal(h.summary().status, 'partial');
  assert.notEqual(h.summary().factsAvailable, false);
  for (const key of countKeys) assert.equal(h.summary()[key], 0, key);
});

for (const state of ['loading', 'failed']) {
  test(`full helper exposes ${state} even before any overview or preflight has returned`, () => {
    const h = harness(null);
    const result = h.helper({ overview: null, overviewLoading: state === 'loading', overviewError: state === 'failed' ? '合成未读取成功' : '' });
    assertUnavailableFacts(result, state);
  });

  test(`full helper ${state} overrides an action's retained preflight when explicit read state is supplied`, () => {
    const h = harness();
    const result = h.helper({ action: { pricing_generation_preflight: preflight({ target_date_rows: 77 }) },
      overview: h.refs.revenueAiOverview.value, overviewLoading: state === 'loading', overviewError: state === 'failed' ? '合成当前失败' : '' });
    assertUnavailableFacts(result, state);
  });
}

test('loading takes precedence over a previous error during retry', () => {
  const h = harness();
  const result = h.helper({ overview: h.refs.revenueAiOverview.value, overviewLoading: true, overviewError: '上一轮错误不应代替当前读取状态' });
  assertUnavailableFacts(result, 'loading');
  assert.doesNotMatch(`${result.detailText || ''} ${result.reasonText || ''}`, /上一轮错误不应代替/);
});

test('explicit clear read state is identical to omitted parameters for existing full-helper consumers', () => {
  const h = harness();
  for (const overview of [null, {}, { pricing_generation_preflight: preflight() },
    { pricing_readiness: { pricing_generation_preflight: preflight({ status: 'blocked', create_candidate_count: 0 }) } }]) {
    assert.deepEqual(copy(h.helper({ overview, overviewError: '', overviewLoading: false })), copy(h.helper({ overview })));
  }
});

test('normal successful overview retains its existing counts, channel scope, target and advisory flags', () => {
  const h = harness(), summary = h.summary();
  assert.equal(summary.visible, true); assert.equal(summary.statusLabel, '可生成待审');
  assert.notEqual(summary.factsAvailable, false);
  assert.deepEqual(countKeys.map(key => summary[key]), [1, 12, 3, 5, 2, 0]);
  assert.equal(summary.sourceScope, 'ctrip_ota_channel'); assert.deepEqual(copy(summary.sourceChannels), ['ctrip']);
  assert.equal(summary.autoWriteOta, false); assert.equal(summary.readOnly, true); assert.equal(summary.advisoryOnly, true);
  assert.equal(summary.target.targetPage, 'agent-center'); assert.equal(summary.target.targetRevenueTab, 'suggestions');
  assert.deepEqual(copy(summary.target.targetFilter), { hotel_id: 81, business_date: '2026-09-12', source: 'ctrip' });
  assert.equal(summary.requiredInputs[0].code, 'room_types');
});

test('unread or absent preflight stays hidden when there is no active read state', () => {
  const h = harness(null);
  for (const overview of [null, {}, { pricing_generation_preflight: {} }]) {
    h.refs.revenueAiOverview.value = overview;
    assert.deepEqual(copy(h.summary()), { visible: false });
  }
  h.refs.revenueAiOverview.value = { pricing_generation_preflight: preflight({ status: 'not_loaded' }) };
  assert.equal(h.summary().visible, false); assert.equal(h.summary().status, 'not_loaded');
});

test('legacy skipped policy remains blocked without inventing operator verification', () => {
  const h = harness({ pricing_generation_preflight: preflight({ status: 'skipped_by_operator_policy',
    can_generate_pending_suggestions: false,
    required_inputs: [{ code: 'forecast', status: 'skipped_by_operator_policy', source: 'manual' }],
  }) });
  const summary = h.summary();
  assert.equal(summary.status, 'blocked'); assert.equal(summary.statusLabel, '生成受阻');
  assert.match(summary.reasonText, /缺少可核验的操作者|持久化记录/);
  assert.equal(summary.requiredInputs[0].status, 'missing_or_blocked');
  assert.equal(summary.autoWriteOta, false);
});

test('existing action then overview then readiness candidate precedence is preserved without read-state parameters', () => {
  const h = harness();
  const overview = { pricing_generation_preflight: preflight({ target_date_rows: 12 }),
    pricing_readiness: { pricing_generation_preflight: preflight({ target_date_rows: 23 }) } };
  assert.equal(h.helper({ overview, action: { pricing_generation_preflight: preflight({ target_date_rows: 34 }) } }).targetDateRows, 34);
  assert.equal(h.helper({ overview }).targetDateRows, 12);
  assert.equal(h.helper({ overview: { pricing_readiness: overview.pricing_readiness } }).targetDateRows, 23);
});

for (const status of ['pending_review_exists', 'partial', 'blocked', 'failed']) {
  test(`a successfully read preflight business status ${status} retains its real counts`, () => {
    const h = harness({ pricing_generation_preflight: preflight({ status }) });
    assert.equal(h.summary().status, status); assert.equal(h.summary().visible, true);
    assert.notEqual(h.summary().factsAvailable, false);
    assert.equal(h.summary().targetDateRows, 12, 'business preflight outcome is distinct from transport availability');
  });
}
