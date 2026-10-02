import assert from 'node:assert/strict';
import { readFrontendTestFileSync as readFileSync } from './helpers/retired_frontend_source.mjs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => readFileSync(path, 'utf8');
const appMain = read('public/app-main.js');
const appStyle = read('public/style.css');
const dualOtaStatic = read('public/dual-ota-home-static.js');
const dualOtaPage = read('resources/frontend/templates/fragments/23b-page-ai-workbench.html');
const ctripStatic = read('public/ctrip-static.js');
const ctripPage = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const meituanStatic = read('public/meituan-static.js');
const meituanPage = read('resources/frontend/templates/fragments/26-page-meituan-ebooking.html');
const agentPage = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const revenueAiStatic = read('public/revenue-ai-static.js');
const researchStatic = read('public/revenue-research-static.js');
const researchPage = read('resources/frontend/templates/fragments/19-page-revenue-research-center.html');
const simulationStatic = read('public/simulation-static.js');
const collaborationPage = read('resources/frontend/templates/fragments/06-page-collaboration-efficiency.html');
const transferContextPage = read('resources/frontend/templates/fragments/08-shared-transfer-context.html');

const loadWindowApi = (source, key) => {
  const context = { window: {}, console };
  vm.runInNewContext(source, context);
  return context.window[key];
};

test('dual OTA values preserve authoritative zero and reject missing arithmetic', () => {
  const api = loadWindowApi(dualOtaStatic, 'SUXI_DUAL_OTA_HOME');

  assert.equal(api.parseDualOtaNumber(null), null);
  assert.equal(api.parseDualOtaNumber(''), null);
  assert.equal(api.parseDualOtaNumber('not-a-number'), null);
  assert.equal(api.parseDualOtaNumber(0), 0);
  assert.equal(api.parseDualOtaNumber('0'), 0);
  assert.equal(api.sumObservedDualOtaValues([0, 12]), 12);
  assert.equal(api.sumObservedDualOtaValues([null, 12]), null);
  assert.equal(api.firstObservedDualOtaValue(null, 0, 8), 0);

  assert.match(appMain, /const dualOtaNumberText = \(value, digits = 0\) => \{[\s\S]*number === null[\s\S]*toLocaleString/);
  assert.match(appMain, /every\(platform => platform\.revenueObserved\)/);
  assert.match(appMain, /dualOtaCombinedLossNode\('revenue', '收入', \[[\s\S]*platform: '携程'[\s\S]*platform: '美团'/);
  assert.match(appMain, /dataStatus: 'partial'[\s\S]*合计不完整/);
  assert.match(dualOtaPage, /:title="node\.note \|\| ''"/);
  assert.doesNotMatch(dualOtaPage, /nodeExplanations\[node\.id\].*description/);
  assert.doesNotMatch(dualOtaStatic, /美团昨日漏斗来自.*样例/);
});

test('dual OTA all-store scope uses current aggregate evidence instead of requiring one selected hotel', () => {
  const api = loadWindowApi(dualOtaStatic, 'SUXI_DUAL_OTA_HOME');

  assert.equal(api.hasDualOtaScopeCurrentData({
    hasSelectedHotel: false,
    scope: 'combined',
    ctripAggregateReady: true,
    meituanAggregateReady: false,
  }), true);
  assert.equal(api.hasDualOtaScopeCurrentData({
    hasSelectedHotel: false,
    scope: 'meituan',
    ctripAggregateReady: true,
    meituanAggregateReady: false,
  }), false);
  assert.equal(api.hasDualOtaScopeCurrentData({
    hasSelectedHotel: true,
    scope: 'ctrip',
    ctripSelectedReady: false,
    ctripAggregateReady: true,
  }), false);
  assert.match(appMain, /const dualOtaCtripAggregatePeriodDataReady = \(\) =>/);
  assert.match(appMain, /const dualOtaMeituanAggregatePeriodDataReady = \(\) =>/);
});

test('Ctrip field chain starts from its returned visitor stage without a duplicate browse gap', () => {
  const lossChainStart = appMain.indexOf('dualOtaCurrentLossNodes = () => {');
  const ctripStart = appMain.indexOf("if (scope === 'ctrip') {", lossChainStart);
  const meituanStart = appMain.indexOf("if (scope === 'meituan') {", ctripStart);
  assert.ok(lossChainStart >= 0 && ctripStart > lossChainStart && meituanStart > ctripStart, 'Ctrip field-chain branch is missing');

  const ctripBranch = appMain.slice(ctripStart, meituanStart);
  assert.match(ctripBranch, /dualOtaLossNode\('detailVisitors', '访客', ctripVisitors/);
  assert.doesNotMatch(ctripBranch, /dualOtaLossNode\('browse'/);
  assert.match(appMain, /const dualOtaLossChainSubtitle = computed\(\(\) => \{[\s\S]*携程曝光字段未返回/);
  assert.match(dualOtaPage, /\{\{ dualOtaLossChainSubtitle \}\}/);
  assert.match(dualOtaPage, /--dual-ota-loss-columns/);
  assert.doesNotMatch(appStyle, /main\[data-current-page="ai-workbench"\]/);
});

test('home temporal cards do not coerce null into zero or probability confidence', () => {
  assert.match(appMain, /if \(value === null \|\| value === undefined \|\| value === ''\) return null;/);
  assert.match(appMain, /return '区间未返回';/);
  assert.match(appMain, /if \(number === null\) return '待校准';/);
  assert.match(appMain, /规则置信指数（未校准）/);
  assert.match(appMain, /需满 14 个有效日才做两组 7 日对比/);
  assert.match(appMain, /最近 \$\{recentWindowDays\} 个有数据日均值/);
  assert.match(appMain, /预测运营结论已停用/);
  assert.match(appMain, /整体命中率 \$\{homeTemporalPercentText\(review\.range_hit_rate\)\}（仅诊断）/);
  assert.match(appMain, /按指标和 T\+周期分别回测；每个分组至少 \$\{policySamples\} 个到期样本/);
  assert.match(dualOtaPage, /data-testid="home-temporal-generate-inline"/);
  assert.match(dualOtaPage, /data-testid="home-temporal-backtest-matrix"/);
  assert.match(dualOtaPage, /审批通过后才生成运营任务[\s\S]*不自动调价/);
  assert.match(appMain, /status: 'blocked',[\s\S]*message: homeTemporalError\.value,[\s\S]*series: \[\]/);
  assert.doesNotMatch(appMain, /粗粒度区间 \$\{futureRange\}，置信度/);
});

test('agent review hides deltas without samples and labels competitor fields truthfully', () => {
  assert.match(agentPage, /v-if="!priceSuggestionReviewHasComparableSamples"/);
  assert.match(agentPage, /样本不足，不计算收入、间夜或 ADR 变化/);
  assert.match(agentPage, /priceSuggestionReviewMetricText\(priceSuggestionReview\.delta\?\.amount, '¥'\)/);
  assert.doesNotMatch(agentPage, /priceSuggestionReview\.delta\?\.(?:amount|quantity|adr) \|\| 0/);
  assert.match(revenueAiStatic, /7 日价差轨迹/);
  assert.match(agentPage, /同日房型证据/);
  assert.match(revenueAiStatic, /row\.price_gap_percent/);
  assert.doesNotMatch(agentPage, /价格波动 \{\{ item\.price_change_percent \|\| item\.price_index/);
  assert.match(appMain, /价格指数 \$\{priceIndex\}（非价格波动率）/);
});

test('OTA diagnosis exposes the persisted trust route without hiding unused layers', () => {
  assert.match(agentPage, /data-testid="ota-diagnosis-decision-route"/);
  assert.match(agentPage, /可信决策路由/);
  assert.match(agentPage, /真实证据优先；知识与模型只增强解释/);
  assert.match(agentPage, /stage\.status === 'fallback'/);
  assert.match(agentPage, /证据受阻/);
  assert.match(agentPage, /待人工确认/);
  assert.match(agentPage, /class="suxi-agent-center"/);
  assert.match(agentPage, /sx-agent-diagnosis-card__header/);
  assert.match(agentPage, /sx-agent-tab--active/);
  assert.match(agentPage, /sx-agent-range-button/);
  assert.match(appStyle, /\.suxi-agent-center \.btn-primary/);
  assert.match(appStyle, /linear-gradient\(135deg, #ead8ad 0%, #b9975b 58%, #8b6c36 100%\)/);
  assert.match(appStyle, /transition: transform 180ms ease/);
  assert.match(appStyle, /\.suxi-agent-center \.sx-agent-route/);
  assert.match(appStyle, /@media \(max-width: 640px\)/);
  assert.match(appMain, /request\(`\/agent\/ota-diagnosis\?\$\{query\.toString\(\)\}`\)/);
  assert.match(appMain, /readback\?\.data\?\.status !== 'ready'/);
  assert.match(appMain, /saved_record\?\.readback_verified !== true/);
  assert.match(appMain, /saved_diagnosis_readback_identity_mismatch/);
});

test('OTA collection result panels use explicit lifecycle states', async () => {
  for (const page of [ctripPage, meituanPage]) {
    assert.match(page, /otaFetchResultView\(/);
  }
  assert.match(appMain, /title: '后台执行中'/);
  assert.match(appMain, /title: status === 'business_failed' \? '业务处理失败' : '获取失败'/);
  assert.match(appMain, /const savedAndVerified = savedCount !== null && savedCount > 0 && readbackVerified/);
  assert.match(appMain, /if \(savedAndVerified\)[\s\S]*title: '已入库并回读验证'/);
  assert.match(appMain, /title: '已返回保存数量，回读未确认'/);
  assert.doesNotMatch(appMain, /title: readbackVerified \? '已入库并回读验证' : '已确认入库'/);
  assert.match(appMain, /title: savedCount === 0 \? '请求完成，未入库' : '入库状态未确认'/);
  assert.match(ctripStatic, /ui_flow_status: flowStatus/);
  assert.match(meituanStatic, /ui_flow_status: flowStatus/);

  const api = loadWindowApi(ctripStatic, 'SUXI_CTRIP_STATIC');
  let visibleResult = null;
  const outcome = await api.runCtripOverviewFetchFlow({
    getSystemHotelId: () => 7,
    getActiveCtripConfig: () => ({ id: 11, has_cookies: true, credential_status: 'ready' }),
    getForm: () => ({ requestUrls: 'https://ebooking.ctrip.com/api/example', dataDate: '2026-07-14' }),
    setResult: value => { visibleResult = value; },
    requestFetch: async () => ({
      code: 200,
      message: '业务校验失败',
      data: { status: 'failed', saved_count: 0, row_count: 0, error: '业务校验失败' },
    }),
  });

  assert.equal(outcome.status, 'business_failed');
  assert.equal(visibleResult.ui_flow_status, 'business_failed');
  assert.equal(visibleResult.saved_count, 0);
});

test('revenue research UI presents scenarios and study plans, not causal promises', () => {
  assert.match(researchPage, /已有信息研究/);
  assert.match(researchPage, /研究与情景分析/);
  assert.match(researchPage, /开始研究/);
  assert.match(researchPage, /情景可信等级（未概率校准）/);
  assert.match(researchStatic, /现有相关数据不直接证明调价影响/);
  assert.match(researchStatic, /不直接证明增量收入/);
  assert.doesNotMatch(researchStatic, /预测调价对收入、间夜和 ADR 的影响/);
  assert.doesNotMatch(researchStatic, /判断渠道动作是否带来增量收入/);
  assert.match(appMain, /未来7天 OTA收入情景/);
  assert.match(appMain, /研究输出已生成/);
});

test('retired collaboration preserves historical evidence labels without runtime state or helpers', () => {
  const api = loadWindowApi(simulationStatic, 'SUXI_SIMULATION_STATIC');
  assert.equal(api.createCollaborationProject, undefined);
  assert.equal(api.buildCollaborationTasks, undefined);
  assert.doesNotMatch(appMain, /collaborationProject|collaborationTasks|handleCollaborationEfficiency/);
  assert.match(collaborationPage, /v-model="collaborationProject\.source_evidence"/);
  assert.match(collaborationPage, /v-model="collaborationProject\.review_status"/);
  assert.match(collaborationPage, /示例值不能作为立项或执行依据/);
});

test('retired transfer UI preserves historical scope labels while its active runtime cannot fetch or write transfer data', () => {
  const api = loadWindowApi(simulationStatic, 'SUXI_SIMULATION_STATIC');
  for (const key of Object.keys(api)) assert.doesNotMatch(key, /^(?:transfer|buildTransfer|createTransfer|resolveTransfer)/);
  assert.doesNotMatch(appMain, /transferSourceSnapshot|buildTransferSourceMetricRows|loadTransferSource|handleTransferPricing|handleTransferTiming|handleTransferDashboard|request\(['"]\/transfer/);
  assert.match(transferContextPage, /全酒店经营日报与 OTA 渠道指标分开呈现/);
  assert.match(transferContextPage, /onlineTruthStatusText\(row\.truth\)/);
  assert.doesNotMatch(transferContextPage, /transferSourceSnapshot\.current\?\.(?:revenue|adr|occupancy_rate)/);
});
