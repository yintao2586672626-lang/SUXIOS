import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const read = (path) => readFileSync(path, 'utf8');
const routes = readRouteContractSource(process.cwd());
const controller = read('app/controller/PreciseQuery.php');
const router = read('app/service/PreciseQueryRouterService.php');
const lexicon = read('app/service/PreciseQueryLexicon.php');
const questions = read('app/service/OperatingQuestionService.php');
const appMain = read('public/app-main.js');
const component = read('public/components/system/operating-intelligence-components.js');

test('precise query API has create, exact-id readback and lexicon metadata routes', () => {
  assert.match(routes, /Route::post\('\/precise-queries', 'PreciseQuery\/create'\)/);
  assert.match(routes, /Route::get\('\/precise-queries\/:id', 'PreciseQuery\/read'\)/);
  assert.match(routes, /Route::get\('\/precise-query-lexicon', 'PreciseQuery\/lexicon'\)/);
  assert.match(controller, /PreciseQueryRouterService/);
  assert.match(controller, /ApiExceptionMapper::response/);
  assert.doesNotMatch(controller, /safeMessage\(|getMessage\(\)/);
  assert.match(controller, /accessibleHotels\('operation\.view'\)/);
  assert.match(component, /const askPreciseQuery = async/);
  assert.match(component, /\/agent\/precise-queries\/\$\{questionId\}/);
  assert.match(component, /宿析精准查数保存与按编号回读不一致/);
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
    'Hotel 80 8月23日美团曝光多少？',
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
  assert.match(component, /const summary = window\.SUXI_DATA_HEALTH_STATIC \? ctx\.phase1EmployeeClosureSummary : null/);
  assert.match(component, /items\.unshift\(\{\s*key: 'continue-task'/);
  assert.match(component, /reference_only/);
  assert.match(component, /不进入经营事实/);
});

test('system navigation includes trusted broadcast copy and Typeless maintenance', () => {
  assert.match(component, /可信播报/);
  assert.match(component, /复制播报稿/);
  assert.match(component, /key: 'typeless-dictionary'/);
  assert.match(component, /打开词库维护说明/);
  assert.match(component, /单列、UTF-8 BOM、无表头 CSV/);
});
