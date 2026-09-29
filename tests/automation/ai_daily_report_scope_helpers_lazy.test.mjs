import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { computed, ref } from 'vue';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const main = read('public/app-main.js');
const fullStatic = read('public/revenue-ai-static.js');
const dateContract = read('public/revenue-overview-contract-static.js');
const dataHealth = read('public/data-health-static.js');
const template = read('resources/frontend/templates/fragments/16-page-ai-daily-report.html');
const keys = [
  'aiDailyReportExpandScope', 'aiDailyReportMetricScopeMembers', 'aiDailyReportSourceRefKey',
  'aiDailyReportSourceScope', 'aiDailyReportMetricScopeContext',
];
const plain = value => JSON.parse(JSON.stringify(value));
const extract = (source, from, to) => {
  const start = source.indexOf(from), end = source.indexOf(to, start);
  assert.ok(start >= 0 && end > start, `actual source block: ${from}`);
  return source.slice(start, end);
};

function harness() {
  const scripts = [];
  const sandbox = {
    window: {}, ref, computed, URLSearchParams,
    ensureHomeSecondaryStaticRuntimeReady: async () => {},
    aiDailyReport: ref(null), permittedHotels: ref([{ id: 81, name: '合成酒店' }]), hotels: ref([]),
    aiDailyReportForm: ref({ hotel_id: '81', report_date: '2026-09-12' }),
    aiDailyFactGateLoading: ref(false), aiDailyFactGateState: ref({}), aiDailyReportAiInterpretation: ref({}),
    aiDailyReportSendScopeCurrent: () => Number(sandbox.aiDailyReport.value?.hotel_id) === 81
      && sandbox.aiDailyReport.value?.report_date === sandbox.aiDailyReportForm.value.report_date,
    aiDailyReportActionIsInvestigationOnly: () => false,
    aiDailyReportModelIsLimited: () => false, aiDailyReportReadinessClass: () => '',
    operationMoney: value => String(value), operationValue: value => String(value),
    revenueAiBuildDailyFactGate: () => ({ platformRows: [] }), revenueAiDailyReportActionExecutionReady: () => false,
    document: {
      createElement: tag => ({ tag, dataset: {} }),
      head: { appendChild: script => scripts.push(script) },
      querySelector: () => null,
    },
  };
  const context = vm.createContext(sandbox);
  vm.runInContext(dateContract, context, { filename: 'public/revenue-overview-contract-static.js' });
  vm.runInContext(dataHealth, context, { filename: 'public/data-health-static.js' });
  vm.runInContext(read('public/ai-daily-report-static.js'), context, { filename: 'public/ai-daily-report-static.js' });
  sandbox.onlineTruthDetailText = sandbox.window.SUXI_DATA_HEALTH_STATIC.onlineTruthDetailText;
  vm.runInContext(`
    ${extract(main, 'const revenueAiStaticScript =', 'const revenueAiStatusClass =')}
    ${extract(main, 'const aiDailyReportList =', 'const aiDailyReportGenerationOutcome =')}
    ${extract(main, 'let aiDailyReportPresentation =', 'const aiDailyReportEvidenceTarget =')}
    globalThis.api = {
      ensureRevenueAiStaticReady, requireRevenueAiStatic,
      revenueAiStaticReady, revenueAiStaticLoading, revenueAiStaticError, revenueAiStaticRevision,
      aiDailyReportMetricCards, aiDailyReportMetricTruth,
      aliases: { ${keys.join(', ')} },
    };
  `, context, { filename: 'actual-ai-daily-report-main-slices.js' });
  const installFull = () => {
    vm.runInContext(fullStatic, context, { filename: 'public/revenue-ai-static.js' });
    return sandbox.window.SUXI_REVENUE_AI_STATIC;
  };
  const completeLoad = () => {
    const script = scripts.at(-1);
    assert.ok(script, 'actual loader appended a script');
    installFull();
    script.onload();
  };
  return { ...sandbox, scripts, installFull, completeLoad };
}

test('full helper preserves scope aliases, unknown values, and mixed membership', () => {
  const h = harness(), helper = h.installFull();
  for (const [input, expected] of [
    [undefined, []], ['', []], ['unknown', []],
    ['mixed_whole_hotel_and_ota_channel', ['whole_hotel_daily_report', 'ota_channel']],
    [' WHOLE_HOTEL ', ['whole_hotel_daily_report']],
    ['whole_hotel_daily_report', ['whole_hotel_daily_report']],
    ['OTA', ['ota_channel']], ['ota channel traffic', ['ota_channel']],
    ['manual_input', ['manual_input']], ['user_input', ['manual_input']],
    ['local_operating_source', ['local_operating_source']],
    ['derived_metric', ['derived']], ['derived', ['derived']], ['custom_scope', ['custom_scope']],
  ]) assert.deepEqual(plain(helper.aiDailyReportExpandScope(input)), expected, String(input));

  assert.deepEqual(plain(helper.aiDailyReportMetricScopeMembers({
    metric_scopes: ['ota', 'whole_hotel', 'ota_channel', 'unknown', 'derived'],
  })), ['ota_channel', 'whole_hotel_daily_report', 'derived']);
  assert.deepEqual(plain(helper.aiDailyReportMetricScopeMembers({ metric_scope: 'user_input' })), ['manual_input']);
  assert.deepEqual(plain(helper.aiDailyReportMetricScopeMembers({ metric_scopes: [], metric_scope: 'ota' })), [],
    'an explicit empty scope array keeps its existing precedence');
});

test('source reference priority and record-based scope remain independent of misleading fallback fields', () => {
  const helper = harness().installFull();
  assert.equal(helper.aiDailyReportSourceRefKey({ ref: ' online_daily_data#4 ', key: 'daily_reports#2', source: 'manual' }), 'online_daily_data#4');
  assert.equal(helper.aiDailyReportSourceRefKey({ key: 'daily_reports#2', source_ref: 'other', source: 'manual' }), 'daily_reports#2');
  assert.equal(helper.aiDailyReportSourceRefKey({ source_ref: 'custom#3', source: 'manual' }), 'custom#3');
  assert.equal(helper.aiDailyReportSourceRefKey({ source: ' local_source ' }), 'local_source');
  assert.equal(helper.aiDailyReportSourceRefKey({}), '');
  for (const [source, expected] of [
    [{ ref: 'online_daily_data#4', scope: 'whole_hotel' }, 'ota_channel'],
    [{ ref: 'daily_reports#2', platform: 'ctrip' }, 'whole_hotel_daily_report'],
    [{ data_type: 'whole_hotel_daily_report' }, 'whole_hotel_daily_report'],
    [{ ingestion_method: 'manual_import', platform: 'meituan' }, 'manual_input'],
    [{ source: 'local_daily' }, 'local_operating_source'],
    [{ metric_scope: 'mixed_whole_hotel_and_ota_channel' }, 'mixed_whole_hotel_and_ota_channel'],
    [{ scope: 'derived_metric' }, 'derived'],
    [{ platform: 'ctrip' }, 'ota_channel'], [{ platform: 'meituan' }, 'ota_channel'],
    [{ platform: 'qunar' }, 'ota_channel'], [{}, 'unknown'],
  ]) assert.equal(helper.aiDailyReportSourceScope(source), expected, JSON.stringify(source));
});

test('scope context keeps explicit scope priority and does not promote unknown or derived scope', () => {
  const helper = harness().installFull();
  for (const [metric, sources, expected] of [
    [{ metric_scope: 'whole_hotel' }, [], 'whole_hotel'],
    [{ metric_scope: 'ota' }, [{ ref: 'daily_reports#2' }], 'ota_channel'],
    [{ metric_scopes: ['whole_hotel', 'ota'] }, [], 'mixed'],
    [{ metric_scope: 'manual_input' }, [], 'user_input'],
    [{ metric_scope: 'local_operating_source' }, [], 'local_operating_source'],
    [{ metric_scope: 'derived' }, [], 'unprovided'],
    [{ metric_scope: 'unknown' }, [], 'unprovided'],
    [{}, [{ ref: 'daily_reports#2' }, { ref: 'online_daily_data#4' }], 'mixed'],
    [{}, [{ ingestion_method: 'manual' }], 'user_input'],
  ]) {
    const result = helper.aiDailyReportMetricScopeContext(metric, sources);
    assert.equal(result.code, expected);
    assert.ok(result.text && result.label, 'every scope result remains displayable');
  }
});

test('the same main aliases move from conservative fallbacks to full helpers and invalidate Vue computed state', async () => {
  const h = harness(), held = { ...h.api.aliases };
  const scope = computed(() => held.aiDailyReportMetricScopeContext({ metric_scope: 'ota_channel' }).code);
  assert.deepEqual(plain(held.aiDailyReportExpandScope('ota')), []);
  assert.deepEqual(plain(held.aiDailyReportMetricScopeMembers({ metric_scope: 'ota' })), []);
  assert.equal(held.aiDailyReportSourceRefKey({ ref: 'online_daily_data#4' }), '');
  assert.equal(held.aiDailyReportSourceScope({ ref: 'online_daily_data#4' }), 'unknown');
  assert.equal(scope.value, 'unprovided');

  const pending = h.api.ensureRevenueAiStaticReady();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(h.api.revenueAiStaticLoading.value, true);
  assert.equal(h.scripts.length, 1);
  assert.match(h.scripts[0].src, /^revenue-ai-static\.js\?v=/);
  assert.equal(h.scripts[0].dataset.suxiRevenueAiStatic, '1');
  h.completeLoad();
  await pending;
  assert.equal(h.api.revenueAiStaticReady.value, true);
  assert.equal(h.api.revenueAiStaticLoading.value, false);
  assert.equal(h.api.revenueAiStaticRevision.value, 1);
  for (const key of keys) assert.equal(h.api.aliases[key], held[key], 'main keeps its original callable reference');
  assert.deepEqual(plain(held.aiDailyReportExpandScope('ota')), ['ota_channel']);
  assert.deepEqual(plain(held.aiDailyReportMetricScopeMembers({ metric_scope: 'whole_hotel' })), ['whole_hotel_daily_report']);
  assert.equal(held.aiDailyReportSourceRefKey({ ref: 'online_daily_data#4' }), 'online_daily_data#4');
  assert.equal(held.aiDailyReportSourceScope({ ref: 'online_daily_data#4' }), 'ota_channel');
  assert.equal(scope.value, 'ota_channel', 'actual Vue cache is invalidated when the loader revision changes');
});

test('not-ready metric cards remain empty beside the original loading notice, then show scoped true-zero facts', async () => {
  const h = harness();
  h.aiDailyReport.value = {
    hotel_id: 81, report_date: '2026-09-12',
    source_refs: [
      { ref: 'online_daily_data#4', platform: 'meituan', metric_keys: ['exposure', 'amount'], data_date: '2026-09-12', validation_status: 'verified', readback_verified: true },
      { ref: 'daily_reports#2', metric_keys: ['amount'], data_date: '2026-09-12', validation_status: 'recorded', readback_verified: true },
    ],
    yesterday_result: { metrics: [
      { key: 'exposure', value: 0, metric_scope: 'ota_channel' },
      { key: 'revenue', value: 0, metric_scope: 'whole_hotel_daily_report' },
      { key: 'revenue', value: 0, metric_scopes: ['whole_hotel_daily_report', 'ota_channel'] },
      { key: 'orders', value: null, metric_scope: 'manual_input' },
    ] },
  };
  assert.match(template, /v-if="!revenueAiStaticReady"[^>]*data-testid="revenue-ai-static-daily-report-status"/);
  assert.match(template, /revenueAiStaticLoading[\s\S]*revenueAiStaticError/);
  assert.deepEqual(plain(h.api.aiDailyReportMetricCards.value), []);
  const pending = h.api.ensureRevenueAiStaticReady();
  await new Promise(resolve => setImmediate(resolve));
  assert.deepEqual(plain(h.api.aiDailyReportMetricCards.value), []);
  h.completeLoad();
  await pending;
  const cards = plain(h.api.aiDailyReportMetricCards.value);
  assert.deepEqual(cards.map(card => card.scopeCode), ['ota_channel', 'whole_hotel', 'mixed', 'user_input']);
  assert.deepEqual(cards.map(card => card.truth.status), ['verified', 'unverified', 'partial', 'unverified']);
  assert.deepEqual(cards.map(card => card.value), [0, 0, 0, null]);
  assert.equal(cards[0].truth.persistence.readback_verified_count, 1);
  assert.equal(cards[3].calculationStatus, 'missing');
});

for (const missingKey of keys) {
  test(`loader rejects a partial full module missing ${missingKey} and accepts a complete retry`, async () => {
    const h = harness(), full = h.installFull();
    const incomplete = { ...full };
    delete incomplete[missingKey];
    h.window.SUXI_REVENUE_AI_STATIC = Object.freeze(incomplete);
    await assert.rejects(h.api.ensureRevenueAiStaticReady(), error => error.message.includes(missingKey));
    assert.equal(h.api.revenueAiStaticReady.value, false);
    assert.equal(h.api.revenueAiStaticLoading.value, false);
    assert.ok(h.api.revenueAiStaticError.value.includes(missingKey));
    assert.deepEqual(plain(h.api.aiDailyReportMetricCards.value), []);
    h.window.SUXI_REVENUE_AI_STATIC = full;
    await h.api.ensureRevenueAiStaticReady();
    assert.equal(h.api.revenueAiStaticReady.value, true);
    assert.equal(h.api.revenueAiStaticError.value, '');
    assert.equal(h.api.aliases.aiDailyReportSourceScope({ ref: 'online_daily_data#4' }), 'ota_channel');
  });
}

test('a failed helper download preserves the not-ready state and a new loader attempt recovers', async () => {
  const h = harness();
  const failed = h.api.ensureRevenueAiStaticReady();
  await new Promise(resolve => setImmediate(resolve));
  h.scripts[0].onerror();
  await assert.rejects(failed, /Revenue AI 展示工具加载失败/);
  assert.equal(h.api.revenueAiStaticReady.value, false);
  assert.equal(h.api.revenueAiStaticLoading.value, false);
  assert.deepEqual(plain(h.api.aiDailyReportMetricCards.value), []);
  const retry = h.api.ensureRevenueAiStaticReady();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(h.scripts.length, 2, 'the rejected load promise is not reused');
  h.completeLoad();
  await retry;
  assert.equal(h.api.revenueAiStaticReady.value, true);
  assert.equal(h.api.revenueAiStaticError.value, '');
  assert.equal(h.api.aliases.aiDailyReportMetricScopeContext({ metric_scope: 'whole_hotel' }).code, 'whole_hotel');
});
