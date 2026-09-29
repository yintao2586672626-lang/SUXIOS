import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse } from 'acorn';
import { compile } from '@vue/compiler-dom';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outputDir = path.join(repoRoot, 'output/qa/five-feature-completion-20260908');
const outputPath = path.join(outputDir, 'visual-fixture.html');
const read = relative => readFile(path.join(repoRoot, relative), 'utf8');
const sources = {
  research: 'resources/frontend/templates/fragments/19-page-revenue-research-center.html',
  tasks: 'resources/frontend/templates/fragments/17-page-ops-track.html',
  contexts: 'tests/automation/research_tasks_template_behavior.test.mjs',
  components: 'public/components/system/app-main-components.js',
};
const sourceEntries = await Promise.all(Object.entries(sources).map(async ([key, file]) => [key, await read(file)]));
const text = Object.fromEntries(sourceEntries);
const contextAst = parse(text.contexts, { ecmaVersion: 'latest', sourceType: 'module' });
const extractContext = name => {
  const node = contextAst.body.find(entry => entry.type === 'FunctionDeclaration' && entry.id?.name === name)
    || contextAst.body.find(entry => entry.type === 'VariableDeclaration' && entry.declarations.some(item => item.id?.name === name));
  if (!node) throw new Error(`Missing tested fixture context: ${name}`);
  return text.contexts.slice(node.start, node.end);
};
const qualityStart = text.components.indexOf('const AiDecisionQualityDetails = {');
const qualityEnd = text.components.indexOf('const OnlineTruthSummary = {', qualityStart);
if (qualityStart < 0 || qualityEnd < qualityStart) throw new Error('Cannot isolate the existing quality details component');
const qualityComponent = text.components.slice(qualityStart, qualityEnd);
const renderCode = compile(text.research + '\n' + text.tasks, { mode: 'function', prefixIdentifiers: true }).code;
const vue = await read('public/vue.runtime.global.prod.js');
const [tailwind, productCss, rawIcons] = await Promise.all([
  read('public/tailwind.min.css'), read('public/style.min.css'), read('public/font-awesome.min.css'),
]);
let icons = rawIcons;
for (const match of [...rawIcons.matchAll(/url\(([^)]+)\)/g)]) {
  const url = match[1].replace(/^['"]|['"]$/g, '');
  const name = path.basename(url.split('?')[0]);
  let embedded = 'data:application/octet-stream;base64,';
  if (/^[a-zA-Z0-9_-]+\.woff2$/.test(name)) {
    try { embedded = `data:font/woff2;base64,${(await readFile(path.join(repoRoot, 'public/webfonts', name))).toString('base64')}`; } catch {}
  }
  icons = icons.replace(match[0], `url("${embedded}")`);
}
const sourceHashes = Object.fromEntries(sourceEntries.map(([key, value]) => [sources[key], createHash('sha256').update(value).digest('hex')]));
const script = `
${vue}
${extractContext('researchState')}
${extractContext('taskState')}
${extractContext('taskItem')}
${qualityComponent}
const sequence = label => Array.from({ length: 7 }, (_, index) => label + (index + 1));
const longSummary = [
  '【本地合成研究摘要】此夹具用于核验手机与桌面上的完整文本、长字段、缺口与任务操作布局。所有门店、日期、事实及建议均为合成内容。',
  ...sequence('研究段落 ').map((label, index) => label + '：当前仅讨论合成携程渠道数据。检查业务日期、样本范围、来源一致性与已保存记录后，再讨论行动可行性。第 ' + (index + 1) + ' 组样本保留缺失状态，不将未返回的信息视为零，也不外推全酒店收入。'),
  '【完整摘要结束标记】到这里表示长摘要末段可访问；展开与收起只改变显示。'
].join('\\n\\n');
const research = researchState({
  status: 'pending_data', model_key: '本地合成夹具',
  hotel_scope: { mode: 'single_hotel', hotel_id: 900080, hotel_name: '合成门店 · 演示样本' },
  local_sources: sequence('合成来源 ').map((label, index) => ({ label: label + ' · 具有较长中文字段名的数据来源', count: index === 0 ? 0 : index + 3, summary: label + '的完整说明：仅用于界面验收，字段缺失会保留为缺口。' })),
  gaps: sequence('信息缺口 ').map(label => ({ label, reason: '合成样本缺少同一门店、同一业务日期的精确回读，需核对。' })),
  readiness: { stage: 'research_data_gaps_pending', status_label: '合成缺口待补齐', score: 42,
    notice: '合成结果不具备真实执行条件', next_action: '核验各项缺口并保留证据',
    missing_evidence: sequence('就绪缺口 ').map(label => ({ label, next_action: '补充该项来源、日期与对应记录身份。' })) },
  web_sources: [{ title: '合成引用，仅检验长网址折行', url: 'https://example.test/synthetic/very-long-source-identity-for-responsive-layout-check-without-any-network-request' }],
  business_forecast: { method: '合成演示', confidence: '未校准' },
  result: { summary: longSummary, risk_signals: sequence('风险信号 ').map(label => label + '：样本尚不完整，不能据此归因或承诺经营改善。'),
    decision_recommendations: sequence('合成建议 ').map((title, index) => ({ title, priority: index < 2 ? 'P1' : 'P2',
      action: '核对第 ' + (index + 1) + ' 项已保存渠道来源并记录实际缺口。',
      data_basis: { summary: '完全合成的展示依据', scope: '渠道范围', platform: 'ctrip', date: '2026-09-08' },
      expected_effect: { summary: '仅验证页面可读性与可操作性', metric_label: '展示完整性', review_window: '人工验收时' },
      risk: { level: '待核验', summary: '演示样本不支持真实审批或业务执行' },
      blocked_reason: '本地合成演示，不可执行', can_create_execution_intent: false,
      decision_quality: { contract_version: 'ai_recommendation_quality.v2', complete: false, execution_ready: false },
    })),
    recommended_actions: sequence('原始文字建议 ').map(label => label + '：保留旧格式的原始建议全文。'),
    forecast_assumptions: sequence('情景假设 '), key_metrics: sequence('关键指标 '), data_gaps: sequence('研究缺口 '),
    confidence_note: '合成结果仅用于界面核验，未建立概率校准或经营因果结论。', next_review_date: '2026-09-10',
  },
});
research.state.revenueResearchHotelId = '900080';
research.state.revenueResearchHotelOptions = [{ id: 900080, name: '合成门店 · 演示样本' }, { id: 900081, name: '另一合成选择（不会改写已有结果身份）' }];
research.state.revenueResearchProducts[0].name = '完整结果与全部缺口 · 合成演示';
research.state.revenueResearchProducts[0].delivery = '长摘要、7项来源、7项风险、7项结构化建议及旧格式建议';
research.state.revenueResearchProducts[0].moduleLabel = '本地界面验收夹具';
const taskRows = [
  { ...taskItem(['ApproveExecution']), id: 900041, action: '【合成】核对价格与房型后提交人工审批', stage: 'approval', approval: { status: 'pending_approval' }, execution: { task_id: 900091, status: 'pending_approval' } },
  { ...taskItem(['StartExecution', 'RecordNodeCheck', 'ExecuteWithEvidence', 'CancelExecution', 'DefineIntervention', 'SaveMemo']), id: 900042, action: '【合成】复核渠道长名称房型及入住人数条件，记录执行证据', stage: 'execution', execution: { task_id: 900092, status: 'pending_execute' } },
  { ...taskItem(['ReviewExecution']), id: 900043, action: '【合成】复盘既有动作并保留非归因说明', stage: 'review', execution: { task_id: 900093, status: 'executed' } },
  { ...taskItem(['ReconcileExecution']), id: 900044, action: '【合成】读取同一日期与渠道的复盘事实', stage: 'review', execution: { task_id: 900094, status: 'executed' } },
].map(item => ({ ...item,
  recommendation: { platform: 'ctrip', date_start: '2026-09-08' },
  assignment: { status: 'scheduled', assignee_id: 900007, assignee_name: '演示负责人 · 合成用户', due_at: '2026-09-09 18:00', review_at: '2026-09-10 10:00' },
  action_management: { lifecycle: { status: item.stage }, action_card: { reason: '合成证据需要逐项核对', risk: { summary: '证据不足时保留观察，不做强制归因' }, fact_refs: ['synthetic-record-001', 'synthetic-record-002'], metric_contract: { metric_key: '渠道可售条件核验', unit: '项' } }, latest_review: { non_attribution_reasons: ['合成样本不可形成真实因果结论'] } },
}));
const tasks = taskState(taskRows);
tasks.state.operationHotelOptions = [{ id: 900080, name: '合成门店 · 演示样本' }];
tasks.state.operationFilters.hotel_id = '900080';
tasks.state.operationExecutionStages = [{ key: 'approval', label: '待审批', count: 1 }, { key: 'execution', label: '待执行', count: 1 }, { key: 'review', label: '待复盘', count: 2 }];
tasks.state.aiDailyReportTaskReturn = { reportId: 900015, reportDate: '2026-09-08', intentId: 900042 };
const statusLabels = { pending_approval: '待审批', approved: '已审批', pending_execute: '待执行', executed: '已执行', approval: '待审批', execution: '执行阶段', review: '复盘阶段' };
tasks.state.operationExecutionStatusLabel = value => statusLabels[value] || '状态待核验';
tasks.state.operationExecutionStatusClass = () => 'border border-emerald-200 bg-emerald-50 text-emerald-800';
const feedback = Vue.ref('选择页面查看实际模板。任务按钮仅产生本地演示反馈。');
const announce = label => { feedback.value = '合成演示反馈：' + label + '。没有调用后台接口或保存业务数据。'; };
for (const name of ['loadOperationActions', 'returnToAiDailyReport', 'approveOperationExecutionIntent', 'rejectOrCancelOperationApproval', 'startOperationExecutionTask', 'recordOperationRevenueNodeCheck', 'recordOperationExecutionEvidence', 'cancelOperationExecution', 'reconcileOperationExecutionReview', 'reviewOperationExecutionTask', 'openOperatingInterventionForm', 'saveMemo', 'openOperatingGoalContractForm']) {
  const original = tasks.state[name];
  tasks.state[name] = (...args) => { original(...args); announce(name); };
}
const filterTasks = () => {
  const stage = tasks.state.operationExecutionStageFilter;
  tasks.state.operationExecutionFilteredItems = taskRows.filter(item => !stage || item.stage === stage);
  tasks.state.operationExecutionStageFilterLabel = tasks.state.operationExecutionStages.find(item => item.key === stage)?.label || '全部阶段';
};
tasks.state.setOperationExecutionStageFilter = value => { tasks.state.operationExecutionStageFilter = value; filterTasks(); };
tasks.state.openOperationPendingReviews = () => { tasks.state.operationExecutionStageFilter = 'review'; filterTasks(); };
tasks.state.setOperationExecutionViewMode = value => { tasks.state.operationExecutionViewMode = value; filterTasks(); };
research.state.runRevenueResearchProduct = () => announce('开始研究（保留现有合成长摘要）');
research.state.openRevenueResearchModule = () => announce('进入模块');
const page = Vue.ref('revenue-research-center');
const render = (function(Vue) { ${renderCode} })(Vue);
const app = Vue.createApp({ setup: () => ({ ...Vue.toRefs(research.state), ...Vue.toRefs(tasks.state), currentPage: page }), render });
app.component('ai-decision-quality-details', AiDecisionQualityDetails);
app.component('manager-capability-panel', { props: ['hotelId', 'request'], render: () => Vue.h('div', { class: 'rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800' }, '本地合成占位：经理能力面板保持可访问；未加载或请求真实管理数据。') });
app.component('term-help', { props: ['term'], render: () => Vue.h('span', 'ROI') });
app.config.errorHandler = error => { announce('渲染错误：' + error.message); document.getElementById('fixture-feedback').dataset.error = 'true'; };
app.mount('#fixture-product');
Vue.watch(feedback, value => { document.getElementById('fixture-feedback').textContent = value; });
document.querySelectorAll('[data-fixture-page]').forEach(button => button.addEventListener('click', () => {
  page.value = button.dataset.fixturePage;
  document.querySelectorAll('[data-fixture-page]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
}));
document.getElementById('fixture-expand').addEventListener('click', async () => {
  await Vue.nextTick();
  const details = document.querySelector('[data-testid="revenue-research-full-result-sample"]');
  if (details) { details.open = !details.open; details.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
});
window.__fiveFeatureFixture = Object.freeze({ synthetic: true, sourceHashes: ${JSON.stringify(sourceHashes)}, researchSummaryLength: longSummary.length, sourceCount: 7, riskCount: 7, taskCount: taskRows.length });
`;
const scriptSafe = value => value.replace(/<\/script/gi, '<\\/script');
parse(script, { ecmaVersion: 'latest', sourceType: 'script' });
const html = `<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; font-src data:; img-src data:; connect-src 'none'">
<title>宿析OS · 本地合成界面演示</title>
<style>${tailwind}\n${productCss}\n${icons}</style>
<style>html{background:#f5f4ed}body{margin:0;min-width:0;background:#f5f4ed;color:#193b31}.fixture-shell{width:100%;max-width:1320px;margin:auto;padding:16px;box-sizing:border-box;min-width:0}.fixture-notice{padding:20px;border:2px solid #b58b42;border-radius:20px;background:#fffaf0}.fixture-notice h1{margin:0;font-size:22px;font-weight:750}.fixture-notice p{margin:8px 0 0;line-height:1.65;overflow-wrap:anywhere}.fixture-controls{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.fixture-controls button{min-height:44px;padding:10px 14px;border:1px solid #315d50;border-radius:12px;background:#fff;color:#315d50;font-weight:650;cursor:pointer}.fixture-controls button[aria-pressed=true]{background:#315d50;color:#fff}.fixture-feedback{margin:12px 0 18px;padding:10px 14px;border:1px solid #d8e4df;border-radius:12px;background:#f6faf8;color:#315d50;overflow-wrap:anywhere;font-size:13px;line-height:1.6}#fixture-product{min-width:0}.fixture-footer{margin-top:20px;font-size:12px;line-height:1.7;color:#647166;overflow-wrap:anywhere}@media(max-width:480px){.fixture-shell{padding:10px}.fixture-notice{padding:14px}.fixture-notice h1{font-size:19px}}</style>
</head><body><main class="fixture-shell">
<header class="fixture-notice"><h1>本地合成演示 · 宿析OS界面验收</h1><p>所有门店、数字、研究与任务均为合成样本。页面使用实际研究和任务模板；交互只展示本地反馈，不请求后台、不保存业务数据。</p>
<div class="fixture-controls"><button type="button" data-fixture-page="revenue-research-center" aria-pressed="true">研究完整结果</button><button type="button" data-fixture-page="ops-track" aria-pressed="false">任务卡片与桌面细表</button><button type="button" id="fixture-expand">展开／收起研究全文</button></div></header>
<p id="fixture-feedback" class="fixture-feedback" role="status" aria-live="polite">选择页面查看实际模板。可将浏览器宽度调整为 390px 查看手机布局。</p>
<div id="fixture-product"></div><footer class="fixture-footer">这是本地可视夹具，不能作为真实账号、业务写入或持续出数的验收证据。经理能力面板使用合成占位；结构化建议质量详情使用真实组件。模板 SHA-256：研究 ${sourceHashes[sources.research]}；任务 ${sourceHashes[sources.tasks]}。</footer>
</main><script>${scriptSafe(script)}</script></body></html>`;
await mkdir(outputDir, { recursive: true });
await writeFile(outputPath, html, 'utf8');
await writeFile(path.join(outputDir, 'visual-fixture-manifest.json'), JSON.stringify({ synthetic: true, generatedAt: new Date().toISOString(), outputPath, sourceHashes, externalRequests: false }, null, 2) + '\n');
console.log(JSON.stringify({ outputPath, bytes: Buffer.byteLength(html), sourceCount: 7, riskCount: 7, taskCount: 4, sourceHashes }, null, 2));
