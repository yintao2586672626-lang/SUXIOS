import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
const researchStatic = read('public/revenue-research-static.js');
const researchPage = read('resources/frontend/templates/fragments/19-page-revenue-research-center.html');
const simulationStatic = read('public/simulation-static.js');
const collaborationPage = read('resources/frontend/templates/fragments/06-page-collaboration-efficiency.html');

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
  assert.match(appMain, /dualOtaSumObservedValues\(\[ctripRevenue, meituanRevenue\]\)/);
  assert.match(dualOtaPage, /:title="node\.note \|\| ''"/);
  assert.doesNotMatch(dualOtaPage, /nodeExplanations\[node\.id\].*description/);
  assert.doesNotMatch(dualOtaStatic, /美团昨日漏斗来自.*样例/);
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
  assert.match(appStyle, /repeat\(var\(--dual-ota-loss-columns, 5\), minmax\(82px, 1fr\)\)/);
});

test('home temporal cards do not coerce null into zero or probability confidence', () => {
  assert.match(appMain, /if \(value === null \|\| value === undefined \|\| value === ''\) return null;/);
  assert.match(appMain, /return '区间未返回';/);
  assert.match(appMain, /if \(number === null\) return '待校准';/);
  assert.match(appMain, /规则置信指数（未校准）/);
  assert.match(appMain, /较前7日变化未返回/);
  assert.match(appMain, /区间命中 \$\{homeTemporalPercentText\(review\.range_hit_rate\)\}/);
  assert.doesNotMatch(appMain, /粗粒度区间 \$\{futureRange\}，置信度/);
});

test('agent review hides deltas without samples and labels competitor fields truthfully', () => {
  assert.match(agentPage, /v-if="!priceSuggestionReviewHasComparableSamples"/);
  assert.match(agentPage, /样本不足，不计算收入、间夜或 ADR 变化/);
  assert.match(agentPage, /priceSuggestionReviewMetricText\(priceSuggestionReview\.delta\?\.amount, '¥'\)/);
  assert.doesNotMatch(agentPage, /priceSuggestionReview\.delta\?\.(?:amount|quantity|adr) \|\| 0/);
  assert.match(agentPage, /competitorAlertPriceText\(item\)/);
  assert.doesNotMatch(agentPage, /价格波动 \{\{ item\.price_change_percent \|\| item\.price_index/);
  assert.match(appMain, /价格指数 \$\{priceIndex\}（非价格波动率）/);
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

test('expansion collaboration starts unverified and exposes the evidence needed for execution readiness', () => {
  const api = loadWindowApi(simulationStatic, 'SUXI_SIMULATION_STATIC');
  const project = api.createCollaborationProject('2026-08-31');
  const tasks = api.buildCollaborationTasks('2026-08-31');

  assert.equal(project.source_evidence, '');
  assert.equal(project.review_status, 'pending');
  assert.deepEqual(Array.from(tasks, task => task.name), ['市场调研', '物业评估', '合同谈判', '装修筹建', '证照办理', 'OTA上线', '运营交接']);
  assert.ok(tasks.every(task => task.status === '待确认' && task.owner === '待分配' && task.due_date === ''));
  assert.doesNotMatch(simulationStatic, /【示例】市场调研/);
  assert.match(collaborationPage, /v-model="collaborationProject\.source_evidence"/);
  assert.match(collaborationPage, /v-model="collaborationProject\.review_status"/);
  assert.match(collaborationPage, /示例值不能作为立项或执行依据/);
  assert.match(appMain, /source_evidence: input\.source_evidence/);
  assert.match(appMain, /review_status: input\.review_status/);
});
