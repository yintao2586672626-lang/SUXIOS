import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test as nodeTest } from 'node:test';
import vm from 'node:vm';

const test = (name, callback) => nodeTest(name, { timeout: 5000 }, callback);
const main = readFileSync(process.env.SUXIOS_REVENUE_BASIS_MAIN_SOURCE
  || new URL('../../public/app-main.js', import.meta.url), 'utf8');
const staticSource = readFileSync(process.env.SUXIOS_REVENUE_BASIS_STATIC_SOURCE
  || new URL('../../public/revenue-ai-static.js', import.meta.url), 'utf8');
const dependencies = ['revenue-overview-contract-static.js', 'revenue-cockpit-static.js']
  .map(name => readFileSync(new URL(`../../public/${name}`, import.meta.url), 'utf8')).join('\n');
const declaration = (name, optional = false) => {
  const start = main.indexOf(`            const ${name} =`);
  if (optional && start < 0) return '';
  assert.ok(start >= 0, `actual production declaration: ${name}`);
  const next = /\r?\n            (?:const|let) /.exec(main.slice(start + 1));
  assert.ok(next, `bounded declaration: ${name}`);
  return main.slice(start, start + 1 + next.index);
};
const production = [
  declaration('resetCtripCompetitiveOperations'), declaration('resetCoreOperationsScopedState'),
  declaration('invalidateCoreOperationsScopedState'), declaration('applyRevenueAiEvidenceScope', true),
  declaration('loadDailyWorkbench'), declaration('openRevenueAiDecisionBasis'),
].join('\n');
const hotelId = '81', targetDate = '2026-09-12', oldDate = '2026-09-26';
const copy = value => value === undefined ? undefined : JSON.parse(JSON.stringify(value));
const validFilter = () => ({ hotel_id: Number(hotelId), business_date: targetDate, source: 'ctrip' });
const target = filter => ({ label: '目标日 OTA 证据', target_page: 'online-data', target_tab: 'data-health',
  ...(filter === undefined ? {} : { target_filter: filter }) });

// Execute the real mapper/resolver/handler and shared reset/invalidation helper.
// Entry navigation and requests are observable in-memory boundaries, with no I/O.
function harness({ date = oldDate, source = 'meituan' } = {}) {
  const effects = [], entries = [], notices = [], loads = [], requests = [];
  const ref = (name, initial = null) => {
    let value = initial;
    return { get value() { return value; }, set value(next) {
      value = next; effects.push([name, copy(next)]);
    } };
  };
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) refs[match[1]] ??= ref(match[1]);
  const initial = {
    filterReportHotel: hotelId, autoFetchHotelId: hotelId, coreOperationsHotelId: hotelId,
    coreOperationsTargetDate: date, onlineDataFilter: { hotel_id: hotelId, source, start_date: date, end_date: date },
    currentPage: 'agent-center', agentTab: 'revenue', revenueAgentTab: 'analysis',
    priceSuggestionFilter: { date: oldDate, end_date: oldDate, status: 0 },
    dailyWorkbenchLoading: false, dailyWorkbenchError: '',
  };
  for (const [name, value] of Object.entries(initial)) refs[name] = ref(name, value);
  const snapshot = () => copy({ hotel: refs.coreOperationsHotelId.value, date: refs.coreOperationsTargetDate.value,
    globalHotel: refs.filterReportHotel.value, autoHotel: refs.autoFetchHotelId.value,
    filter: refs.onlineDataFilter.value, page: refs.currentPage.value });
  const context = vm.createContext({ ...refs, window: {}, URLSearchParams, console: { error() {} },
    coreOperationsMaxDate: oldDate, coreOperationsRequestSeq: 4,
    dailyWorkbenchRequestSeq: 0, dailyWorkbenchPatrolRequestSeq: 0, phase3OperationEffectLoopRequestSeq: 0,
    competitorSummaryRequestSeq: 0, collectionReliabilityRequestSeq: 0, ctripCompetitiveOperationsRequestSeq: 0,
    ensureRevenueAiStaticReady: async () => true,
    nextTick: callback => Promise.resolve().then(() => callback?.()),
    showToast: (message, level) => notices.push({ message, level }),
    openOnlineDataEntryTab: (tab, options) => entries.push({ tab, options: copy(options), scope: snapshot() }),
    loadPriceSuggestionWorkbench: async () => loads.push('suggestions'),
    loadRevenueAnalysisBundle: async () => loads.push('analysis'),
    loadAgentOverview: async () => loads.push('overview'), loadOperationActions: async () => loads.push('operations'),
    openHomeQuickEntry: value => loads.push(copy(value)),
    request: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  });
  vm.runInContext(`${dependencies}\n${staticSource}\nconst revenueAiResolveDecisionBasisNavigation = window.SUXI_REVENUE_AI_STATIC.resolveRevenueAiDecisionBasisNavigation;\n${production}\nglobalThis.handlers = {
    open: openRevenueAiDecisionBasis,
    read: () => loadDailyWorkbench({ hotelId: coreOperationsHotelId.value, endDate: coreOperationsTargetDate.value })
  };`, context);
  return { refs, context, effects, entries, notices, loads, requests, snapshot,
    api: context.window.SUXI_REVENUE_AI_STATIC, open: context.handlers.open, read: context.handlers.read };
}

function mappedTarget(h, kind, filter) {
  const item = { key: 'ota_evidence', code: 'ota_evidence', label: '目标日 OTA 证据', status: 'blocked',
    ...target(filter) };
  if (kind === 'basis') return h.api.buildRevenueAiActionRows({ overview: { actions: [{
    key: 'pricing_review', decision_basis_summary: { items: [item] },
  }] } })[0].decisionBasisItems[0];
  return h.api.buildRevenueAiResolutionPlanSummary({ action: {
    ai_decision_resolution_plan: { status: 'has_pending_evidence', items: [item] },
  } }).items[0];
}

function assertApplied(h, filter) {
  const hotel = String(filter.hotel_id);
  assert.equal(h.entries.length, 1);
  assert.deepEqual(h.entries[0], { tab: 'data-health', options: { force: true }, scope: {
    hotel, date: filter.business_date, globalHotel: hotel, autoHotel: hotel,
    filter: { hotel_id: hotel, source: filter.source, start_date: filter.business_date, end_date: filter.business_date },
    page: 'agent-center',
  } });
}

for (const kind of ['basis', 'plan']) {
  test(`${kind} mapping reaches the real handler with its exact hotel/date/source instead of the previous date`, async () => {
    const h = harness(), filter = validFilter(), mapped = mappedTarget(h, kind, filter);
    await h.open(mapped);
    assertApplied(h, filter);
    assert.deepEqual(copy(mapped.targetFilter), filter);
    assert.deepEqual(copy(h.api.resolveRevenueAiDecisionBasisNavigation(mapped).dataHealthScope), {
      hotelId, businessDate: targetDate, source: 'ctrip',
    });
  });
}

for (const source of ['ctrip', 'meituan', 'all']) {
  test(`data-health source ${source} retains the one exact hotel`, async () => {
    const h = harness(), filter = { ...validFilter(), source };
    await h.open(target(filter));
    assertApplied(h, filter);
    assert.equal(h.refs.filterReportHotel.value, hotelId, 'all combines channels, not hotels');
  });
}

for (const date of ['2024-02-29', '2026-09-25', oldDate]) {
  test(`valid calendar date ${date} at or before the limit is applied`, async () => {
    const h = harness(), filter = { ...validFilter(), business_date: date };
    await h.open(target(filter));
    assertApplied(h, filter);
  });
}

const invalidCases = [
  ['missing filter', undefined], ['empty filter', {}],
  ['missing hotel', { business_date: targetDate, source: 'ctrip' }],
  ['zero hotel', { ...validFilter(), hotel_id: 0 }],
  ['negative hotel', { ...validFilter(), hotel_id: -81 }],
  ['decimal hotel', { ...validFilter(), hotel_id: 81.5 }],
  ['malformed hotel', { ...validFilter(), hotel_id: '81x' }],
  ['missing date', { hotel_id: 81, source: 'ctrip' }],
  ['old date alias alone', { hotel_id: 81, date: targetDate, source: 'ctrip' }],
  ['invalid leap date', { ...validFilter(), business_date: '2026-02-29' }],
  ['invalid calendar day', { ...validFilter(), business_date: '2026-04-31' }],
  ['non-ISO date', { ...validFilter(), business_date: '2026/09/12' }],
  ['timestamp date', { ...validFilter(), business_date: '2026-09-12T00:00:00Z' }],
  ['missing source', { hotel_id: 81, business_date: targetDate }],
  ['unknown source', { ...validFilter(), source: 'booking' }],
];
for (const [name, filter] of invalidCases) {
  test(`invalid ${name} warns and refuses to borrow the existing scope`, async () => {
    const h = harness(), before = h.snapshot(), input = target(filter);
    assert.equal(await h.open(input), false);
    assert.deepEqual(h.snapshot(), before);
    assert.equal(h.entries.length, 0);
    assert.equal(h.notices.at(-1)?.level, 'warning');
    assert.equal(h.api.resolveRevenueAiDecisionBasisNavigation(input).dataHealthScope, null);
  });
}

test('a valid future calendar date is refused by the handler maximum-date boundary', async () => {
  const h = harness(), before = h.snapshot();
  const input = target({ ...validFilter(), business_date: '2026-09-27' });
  assert.equal(await h.open(input), false);
  assert.deepEqual(h.snapshot(), before);
  assert.equal(h.entries.length, 0);
  assert.equal(h.notices.at(-1)?.level, 'warning');
});

test('same hotel/date keeps current facts and allows its pending read to finish', async () => {
  const h = harness({ date: targetDate, source: 'ctrip' });
  const existing = { ctrip: { status: 'ready', data: { business_date: targetDate, value: 0 } } };
  h.refs.coreOperationsMetrics.value = existing;
  const pending = h.read();
  assert.equal(h.refs.dailyWorkbenchLoading.value, true);
  await h.open(target(validFilter()));
  assertApplied(h, validFilter());
  assert.deepEqual(copy(h.refs.coreOperationsMetrics.value), existing);
  assert.equal(h.refs.dailyWorkbenchLoading.value, true);
  h.requests[0].resolve({ code: 200, data: { target_date: targetDate } });
  await pending;
  assert.equal(h.refs.dailyWorkbench.value.target_date, targetDate);
  assert.equal(h.refs.dailyWorkbenchLoading.value, false);
});

for (const outcome of ['success', 'failed', 'exception']) {
  test(`changed date rejects the previous workbench ${outcome} and its finally write`, async () => {
    const h = harness(), pending = h.read();
    assert.ok(h.requests[0].url.includes(oldDate));
    await h.open(target(validFilter()));
    assertApplied(h, validFilter());
    assert.equal(h.refs.dailyWorkbench.value, null);
    assert.equal(h.refs.dailyWorkbenchLoading.value, false);
    h.effects.length = 0;
    if (outcome === 'exception') h.requests[0].reject(new Error('synthetic old scope failure'));
    else h.requests[0].resolve(outcome === 'failed' ? { code: 503, message: 'synthetic old scope refused' }
      : { code: 200, data: { target_date: oldDate } });
    await pending;
    assert.deepEqual(h.effects, []);
    assert.equal(h.refs.dailyWorkbench.value, null);
  });
}

test('the new date workbench recovers after its scoped navigation', async () => {
  const h = harness();
  await h.open(target(validFilter()));
  const pending = h.read();
  assert.ok(h.requests[0].url.includes(targetDate));
  h.requests[0].resolve({ code: 200, data: { target_date: targetDate } });
  await pending;
  assert.equal(h.refs.dailyWorkbench.value.target_date, targetDate);
  assert.equal(h.refs.dailyWorkbenchLoading.value, false);
});

for (const tab of ['platform-sources', 'config']) {
  test(`existing online-data ${tab} navigation does not require a data-health scope`, async () => {
    const h = harness(), before = h.snapshot();
    await h.open({ target_page: 'online-data', target_tab: tab });
    assert.deepEqual(h.entries, [{ tab, options: { force: true }, scope: before }]);
    assert.deepEqual(h.snapshot(), before);
    assert.equal(h.notices.length, 0);
  });
}

test('preflight Agent navigation keeps its existing filter and load behavior', async () => {
  const h = harness();
  const filter = { hotel_id: 82, date: '2026-08-15', end_date: '2026-08-20', status: 2, suggestion_id: 123 };
  const preflight = h.api.buildRevenueAiPricingGenerationPreflightSummary({ action: {
    pricing_generation_preflight: { status: 'blocked', target_page: 'agent-center', target_agent_tab: 'revenue',
      target_revenue_tab: 'suggestions', target_filter: filter },
  } });
  assert.deepEqual(copy(preflight.target.targetFilter), filter);
  await h.open(preflight.target);
  assert.equal(h.refs.filterReportHotel.value, '82');
  assert.equal(h.refs.coreOperationsTargetDate.value, oldDate);
  assert.deepEqual(copy(h.refs.priceSuggestionFilter.value), { date: filter.date, end_date: filter.end_date, status: 2 });
  assert.equal(h.refs.agentTab.value, 'revenue');
  assert.equal(h.refs.revenueAgentTab.value, 'suggestions');
  assert.deepEqual(h.loads, ['suggestions']);
  assert.equal(h.entries.length, 0);
});

test('the resolver preserves existing navigation fields while adding the parsed data-health scope', () => {
  const h = harness(), filter = validFilter();
  const input = { targetPage: 'online-data', targetTab: 'data-health', targetAgentTab: 'revenue',
    targetRevenueTab: 'analysis', targetFilter: filter, label: '证据', nextAction: '先补证据' };
  const before = copy(input), result = copy(h.api.resolveRevenueAiDecisionBasisNavigation(input));
  for (const [key, value] of Object.entries(before)) assert.deepEqual(result[key], value);
  assert.deepEqual(result.dataHealthScope, { hotelId, businessDate: targetDate, source: 'ctrip' });
  assert.deepEqual(input, before);
});
