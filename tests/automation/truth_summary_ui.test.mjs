import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const appMain = readAppMainContractSource();
const revenueAiStatic = readFileSync('public/revenue-ai-static.js', 'utf8');
const fragments = [
  '08-shared-transfer-context.html',
  '13-page-opening-overview.html',
  '16-page-ai-daily-report.html',
  '23c-page-compass-detail.html',
  '35-page-online-data.html',
].map(name => readFileSync(`resources/frontend/templates/fragments/${name}`, 'utf8'));

test('truth cards default to a concise summary and fold technical trace', () => {
  assert.match(appMain, /const OnlineTruthSummary = \{/);
  assert.match(appMain, /onlineTruthSummaryText/);
  assert.match(appMain, /onlineTruthNextActionText/);
  assert.match(appMain, /h\('details'/);
  assert.match(appMain, /'查看详情'/);
  assert.match(appMain, /OnlineTruthSummary,/);

  for (const fragment of fragments) {
    assert.match(fragment, /<online-truth-summary/);
    assert.doesNotMatch(fragment, /onlineTruthDetailText\(|card\.truthLines|metric\.sourceRefsText|metric\.truthDetailText/);
  }
});

test('Revenue AI cards carry the truth envelope into the shared summary', () => {
  const start = revenueAiStatic.indexOf('const buildRevenueAiMetricCards');
  const end = revenueAiStatic.indexOf('const buildRevenueAiGapRows', start);
  assert.ok(start >= 0 && end > start, 'Revenue AI metric card builder must exist');
  const block = revenueAiStatic.slice(start, end);
  assert.match(block, /\btruth,\s*\n\s*truthStatus/);
});

const truthRenderContext = { window: {}, URLSearchParams };
for (const file of [
  'public/revenue-overview-contract-static.js',
  'public/revenue-cockpit-static.js',
  'public/revenue-ai-static.js',
  'public/data-health-static.js',
  'public/components/system/app-main-components.js',
]) {
  vm.runInNewContext(readFileSync(file, 'utf8'), truthRenderContext, { filename: file });
}
const h = (type, props, children) => ({ type, props, children });
const { OnlineTruthSummary } = truthRenderContext.window.SUXI_APP_MAIN_COMPONENTS.create({
  Vue: { h, defineAsyncComponent: () => ({}) }, h,
});

const failureDisplayCases = [
  ['source_update_time_invalid', '来源更新时间格式无效', '核对来源时间格式后重新采集并回读对应记录'],
  ['source_collection_time_missing', '部分来源采集时间未记录', '补齐对应记录的采集时间和回读证据'],
  ['source_collection_time_invalid', '来源采集时间格式无效', '核对来源时间格式后重新采集并回读对应记录'],
  ['cancel_room_nights_invalid', '取消间夜或间夜总数无效', '核对同范围取消间夜与总间夜，取消间夜不能为负或超过总间夜'],
  ['cancel_room_nights_denominator_zero', '间夜总数为 0，无法计算取消间夜率', '核对所选业务日期的间夜记录，分母为 0 时不计算比率'],
  ['collected_at_missing', '采集时间未记录', '补齐采集时间'],
  ['source_update_time_missing', '采集时间未记录', '补齐采集时间'],
  ['source_rows_missing', '目标日没有可用数据', '重新采集目标日期数据'],
  ['target_date_source_rows_missing', '目标日没有可用数据', '重新采集目标日期数据'],
  ['hotel_missing', '门店、平台或日期信息不完整', '补齐门店、平台和目标日期'],
  ['platform_missing', '门店、平台或日期信息不完整', '补齐门店、平台和目标日期'],
  ['data_date_missing', '门店、平台或日期信息不完整', '补齐门店、平台和目标日期'],
  ['source_method_or_trace_missing', '来源凭证不完整', '补齐来源和采集凭证'],
  ['source_trace_missing', '来源凭证不完整', '补齐来源和采集凭证'],
  ['binding_missing', '门店绑定不完整', '补齐门店、平台和目标日期'],
  ['hotel_binding_missing', '门店绑定不完整', '补齐门店、平台和目标日期'],
  ['current_session_unverified', '当天登录状态未验证', '先完成平台登录验证'],
  ['login_required', '需要先验证平台登录', '先完成平台登录验证'],
  ['upstream_new_reason', '采集或入库信息不完整', '补齐缺失信息后重新验证'],
];

for (const [code, reason, nextAction] of failureDisplayCases) {
  test(`Revenue AI shared truth render explains ${code} without changing evidence`, () => {
    const truth = Object.freeze({
      status: 'partial', failure_reason: code, evidence_gap_codes: Object.freeze([code]),
    });
    const card = truthRenderContext.window.SUXI_REVENUE_AI_STATIC.buildRevenueAiMetricCards({
      overview: { metrics: { ota_room_revenue: { display: '--', status: 'missing', truth } } },
    }).find(item => item.key === 'ota_room_revenue');
    const tree = OnlineTruthSummary.render.call({ truth: card.truth, testid: 'revenue-ai-metric-truth-ota_room_revenue' });
    assert.equal(tree.children[0].children, `部分可用：${reason}`);
    assert.equal(tree.children[1].children, `下一步：${nextAction}`);
    const detailRows = tree.children[2].children[1].children;
    assert.equal(detailRows.find(row => row.props.key === 'failure').children[1].children, reason);
    assert.strictEqual(card.truth, truth);
    assert.equal(truth.failure_reason, code);
    assert.deepEqual(truth.evidence_gap_codes, [code]);
    assert.equal(JSON.stringify(tree).includes(code), false);
  });
}

test('shared truth render preserves mixed reason and next-action priorities', () => {
  const truth = Object.freeze({
    status: 'partial',
    failure_reason: 'source_update_time_invalid; hotel_binding_missing; current_session_unverified; source_rows_missing',
  });
  const tree = OnlineTruthSummary.render.call({ truth });
  assert.equal(tree.children[0].children, '部分可用：目标日没有可用数据');
  assert.equal(tree.children[1].children, '下一步：先完成平台登录验证');
  const failureRow = tree.children[2].children[1].children.find(row => row.props.key === 'failure');
  assert.equal(failureRow.children[1].children, '目标日没有可用数据；门店绑定不完整；另有 2 项信息待补');
  assert.equal(truth.failure_reason, 'source_update_time_invalid; hotel_binding_missing; current_session_unverified; source_rows_missing');
});
