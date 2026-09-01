import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');
const routes = readRouteContractSource();
const controller = read('app/controller/PreciseQuery.php');
const router = read('app/service/PreciseQueryRouterService.php');
const lexicon = read('app/service/PreciseQueryLexicon.php');
const questions = read('app/service/OperatingQuestionService.php');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');
const sliceBetween = (source, startMarker, endMarker) => {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start + startMarker.length);
  assert.ok(start >= 0, `missing start marker: ${startMarker}`);
  assert.ok(end > start, `missing end marker: ${endMarker}`);
  return source.slice(start, end);
};
const preciseMetricSetHelpers = sliceBetween(
  component,
  '// PRECISE_METRIC_SET_HELPERS_START',
  '// PRECISE_METRIC_SET_HELPERS_END',
);
const { normalizePreciseMetricSet, preciseMetricUnitLabel } = new Function(
  `${preciseMetricSetHelpers}\nreturn { normalizePreciseMetricSet, preciseMetricUnitLabel };`,
)();

test('precise query API has create, exact-id readback and lexicon metadata routes', () => {
  assert.match(routes, /Route::post\('\/precise-queries', 'PreciseQuery\/create'\)/);
  assert.match(routes, /Route::get\('\/precise-queries\/:id', 'PreciseQuery\/read'\)/);
  assert.match(routes, /Route::get\('\/precise-query-lexicon', 'PreciseQuery\/lexicon'\)/);
  assert.match(controller, /PreciseQueryRouterService/);
  assert.match(controller, /accessibleHotels\('operation\.view'\)/);
  assert.match(appMain, /const askPreciseQuery = async/);
  assert.match(appMain, /\/agent\/precise-queries\/\$\{id\}/);
  assert.match(appMain, /宿析精准查数保存与按编号回读不一致/);
});

test('runtime lexicon records the 2990-term source fingerprint and remains reference-only', () => {
  assert.match(lexicon, /SOURCE_TOTAL_TERMS = 2990/);
  assert.match(lexicon, /e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53/);
  assert.match(lexicon, /recognition_and_routing_reference_only/);
  assert.match(lexicon, /business_fact_eligible' => false/);
  assert.match(lexicon, /'Openness'/);
  assert.match(lexicon, /不是宿析OS酒店经营指标/);
});

test('numeric route is deterministic, fact-scoped and saves the exact answer packet', () => {
  assert.match(router, /database_or_deterministic_calculation_only/);
  assert.match(router, /model_number_generation_allowed' => false/);
  assert.match(router, /deterministicOperatingAnswer/);
  assert.match(router, /latestFactWithFields/);
  assert.match(router, /stored_rate_semantic_mismatch/);
  assert.match(router, /没有可信曝光字段，因此不能计算曝光到访问率/);
  assert.match(router, /OTA成交金额、支付金额或结算金额不能替代收入/);
  assert.match(questions, /deterministicAnswerFinalizer/);
  assert.match(questions, /确定性查数引用了范围外事实/);
  assert.match(questions, /deterministic_precise_query/);
});

test('production numeric lookup consumes canonical closure fields and refuses platform winners', () => {
  assert.match(router, /new DualOtaFieldClosureService\(\)/);
  assert.match(router, /trusted_ota_daily_fact_consumer\.v1/);
  assert.match(router, /singleCanonicalMetricResult/);
  assert.match(router, /answered_from_canonical_closure/);
  assert.match(router, /blocked_by_cross_platform_comparison/);
  assert.match(router, /closure_identity/);
});

test('floating entry is visibly unified and renders every required evidence field', () => {
  for (const marker of [
    '宿析精准查数',
    '查经营事实 · 解释缺失 · 找功能 · 查术语',
    'Hotel 80 8月23日美团曝光人数多少？',
    '可信播报怎么复制？',
    'precise-query-fact-card',
    '酒店',
    '平台',
    '业务日期',
    '指标名称',
    '数值与单位',
    '来源记录',
    '采集时间',
    '验证状态',
    '回读状态',
    '数据范围',
  ]) assert.ok(component.includes(marker), `missing precise-query UI marker: ${marker}`);
  assert.match(component, /suxios_precise_query_last_v1/);
  assert.match(component, /restorePreciseQueryReadback/);
  assert.match(component, /reference_only/);
  assert.match(component, /不进入经营事实/);
});

test('shared precise metric-set adapter keeps legacy values for audit and requires strict proof for readiness', () => {
  assert.equal(preciseMetricUnitLabel('people'), '人');
  assert.equal(preciseMetricUnitLabel('users'), '人');
  assert.equal(preciseMetricUnitLabel('impressions'), '次');
  assert.equal(preciseMetricUnitLabel('percent'), '%');
  assert.equal(preciseMetricUnitLabel('orders'), '单');
  assert.equal(preciseMetricUnitLabel('room_nights'), '间夜');
  assert.equal(preciseMetricUnitLabel('CNY'), 'CNY', 'unknown units must remain unchanged');
  const legacy = normalizePreciseMetricSet({
    precise_result: {
      kind: 'operating_metric',
      status: 'answered_deterministically',
      metric: { key: 'list_exposure', name: '曝光人数' },
      value: 0,
      unit: '人',
      source_record: 'online_daily_data#1',
    },
  });
  assert.equal(legacy.isMetricSet, false);
  assert.equal(legacy.totalCount, 1);
  assert.equal(legacy.readyCount, 0, 'legacy zero remains visible but lacks strict verification metadata');
  assert.equal(legacy.blockedCount, 1);
  assert.equal(legacy.items[0].value, 0, 'zero remains available for audit display');
  assert.equal(legacy.items[0].blocked, true);
  assert.match(legacy.items[0].blockedReason, /verified\/derived_verified/);

  const mixed = normalizePreciseMetricSet({
    status: 'answered_by_precise_query_partial',
    precise_result: {
      metric_set: {
        contract_version: 'suxios.precise_metric_set.v1',
        kind: 'operating_metric_set',
        items: [
          {
            kind: 'operating_metric',
            status: 'readback_verified',
            metric: { key: 'list_exposure', name: '曝光人数' },
            value: 1422,
            unit: '人',
            source_record: 'online_daily_data#102476',
            collected_at: '2026-08-23 23:59:00',
            verification_status: 'verified',
            readback_status: 'readback_verified',
            formula: '来源字段直接回读',
            calculation_inputs: [{ metric_key: 'list_exposure', value: 1422, unit: '人' }],
            data_gaps: [],
          },
          {
            kind: 'operating_metric',
            status: 'blocked_by_missing_metric',
            metric: { key: 'book_order_num', name: '订单量' },
            value: null,
            unit: '单',
            blocked_reason: '订单字段缺失',
            data_gaps: [{ code: 'book_order_num_missing', message: '缺少可信订单字段。' }],
          },
        ],
      },
    },
  });
  assert.equal(mixed.contractVersion, 'suxios.precise_metric_set.v1');
  assert.equal(mixed.kind, 'operating_metric_set');
  assert.equal(mixed.isMetricSet, true);
  assert.equal(mixed.totalCount, 2);
  assert.equal(mixed.readyCount, 1);
  assert.equal(mixed.blockedCount, 1);
  assert.equal(mixed.isPartial, true);
  assert.equal(mixed.allBlocked, false, 'partial must not be rendered as a blanket block');
  assert.deepEqual(mixed.items[0].sourceRecords, ['online_daily_data#102476']);

  const directSet = normalizePreciseMetricSet({
    precise_result: {
      contract_version: 'suxios.precise_metric_set.v1',
      kind: 'operating_metric_set',
      items: mixed.items.map((item) => item.raw),
    },
  });
  assert.equal(directSet.totalCount, 2, 'standalone precise_result.items must share the same adapter');
  assert.equal(directSet.isPartial, true);
});

test('professional question and global precise entry share the multi-metric evidence renderer', () => {
  for (const marker of [
    'renderPreciseMetricEvidence',
    'operating-question-precise-results',
    'operating-question-precise-metric-set',
    'precise-query-metric-set',
    'precise-query-metric-item',
    'suxios.precise_metric_set.v1',
    'operating_metric_set',
    '可核对多指标结果',
    '识别 ${normalized.totalCount} 项',
    '可用 ${normalized.readyCount} 项',
    '阻塞 ${normalized.blockedCount} 项',
    '结果状态',
    '计算输入',
    '分项缺口',
    '部分指标可用',
    '补齐阻塞指标',
  ]) assert.ok(component.includes(marker), `missing multi-metric UI marker: ${marker}`);
  assert.doesNotMatch(component, /Hotel 80 8月23日美团曝光多少/);
});

test('system navigation includes trusted broadcast copy and Typeless maintenance', () => {
  assert.match(component, /可信播报/);
  assert.match(component, /复制播报稿/);
  assert.match(component, /key: 'typeless-dictionary'/);
  assert.match(component, /打开词库维护说明/);
  assert.match(component, /单列、UTF-8 BOM、无表头 CSV/);
});
