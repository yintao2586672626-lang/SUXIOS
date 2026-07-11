import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, source, needles) {
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function excludesAll(file, label, source, needles) {
  const present = needles.filter((needle) => source.includes(needle));
  check(file, label, present.length === 0, present.join(', '));
}

const service = read('app/service/InvestmentDecisionSupportService.php');
const controller = read('app/controller/InvestmentDecision.php');
const route = read('route/app.php');
const frontend = read('public/index.html');
const systemStatic = read('public/system-static.js');
const homeStatic = read('public/home-static.js');
const packageJson = read('package.json');

includesAll('route/app.php', 'P4 route exposes read-only overview endpoint', route, [
  "Route::group('api/investment-decision'",
  "Route::get('/overview', 'InvestmentDecision/overview')",
  'Auth::class',
]);

includesAll('app/controller/InvestmentDecision.php', 'P4 controller resolves authorized hotel scope and calls overview service', controller, [
  'class InvestmentDecision',
  'public function overview(): Response',
  'new InvestmentDecisionSupportService()',
  'resolveHotelScope',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service keeps the five requested decision sections', service, [
  'single_store_quality',
  'competitor_comparison',
  'investment_calculation',
  'risk_alerts',
  'decision_records',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service exposes the full business closure chain', service, [
  'business_closure_chain',
  'buildBusinessClosureChain',
  'ota_data',
  'revenue_analysis',
  'ai_decision',
  'operation_management',
  'investment_decision',
  'OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service exposes an evidence-first action queue', service, [
  'action_queue',
  'buildActionQueue',
  'actionItem',
  '下一步动作队列',
  '行动队列只汇总缺口与下一步，不自动创建执行单，不替代人工复核。',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service requires closed operating ROI before judgement', service, [
  'closed_operating_data_only',
  'operation_execution.roi_ready',
  'can_use_for_investment_judgement',
  'closed_operating_roi_missing',
  'closed_operating_data_missing',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service consumes P0 OTA downstream gate before investment judgement', service, [
  'p0_downstream_gate',
  'P0OtaDownstreamGateService',
  'blocked_by_p0_ota_gate',
  'p0_ota_gate_not_ready',
  'p0_ota_field_loop.ready + operation_execution.roi_ready',
  'p0_ota_field_loop.ready + operation_execution.roi_ready + decision_record.readiness_ready',
  '先完成授权浏览器 Profile 登录态、目标日 OTA/流量入库和 P0 field-loop verifier ready',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service reads existing expansion, transfer, feasibility and competitor evidence', service, [
  'readExpansionRecords',
  'readTransferRecords',
  'readFeasibilityRecords',
  'readCompetitorEvidence',
  'expansion_records',
  'transfer_records',
  'feasibility_reports',
  'competitor_analysis',
  'competitor_price_log',
  'online_daily_data',
]);

includesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service exposes explicit formula inventory', service, [
  'formula_inventory',
  'closed_operating_data = operation_execution.roi_ready_count > 0',
  'payback_months = expected_transfer_price / monthly_net_profit',
  'rent_per_room = estimated_rent / target_room_count',
  'RevPAR = ADR * OCC; payback_months from base scenario net cashflow',
]);

includesAll('public/index.html', 'P4 frontend page loads the overview endpoint and displays all five sections', frontend, [
  "currentPage === 'investment-decision'",
  'loadInvestmentDecisionOverview',
  "request('/investment-decision/overview')",
  'investmentDecisionSummaryCards',
  'investmentDecisionBusinessChainRows',
  'investmentDecisionActionQueueRows',
  'investmentDecisionPriorityClass',
  'investmentDecisionSectionRows',
  'investmentDecisionRiskRows',
  'investmentDecisionRecordRows',
  'investmentDecisionFormulaRows',
  '业务闭环拆解',
  '下一步动作队列',
  'business_closure_chain',
  'action_queue',
]);

includesAll('public/index.html', 'P4 frontend renders P0 gate as an explicit blocking state', frontend, [
  "blocked_by_p0_ota_gate: 'P0未就绪'",
  "'blocked_by_p0_ota_gate'",
  'investmentDecisionOverview?.operating_data_gate?.status',
  'investmentDecisionOverview?.business_closure_chain?.judgement_gate',
]);

includesAll('public/index.html', 'P4 frontend exposes backend action queue with fallback evidence gaps', frontend, [
  'const investmentDecisionGapTitle = (gap) =>',
  'const investmentDecisionGapAction = (gap) =>',
  'const investmentDecisionActionQueueRows = computed(() =>',
  'investmentDecisionOverview.value?.action_queue?.items',
  'investmentDecisionOverview.value?.operating_data_gate?.missing_evidence',
  'investmentDecisionRiskRows.value.forEach',
  'sourceLabel: \'业务闭环\'',
  'sourceLabel: \'经营准入\'',
  'sourceLabel: \'风险提示\'',
]);

excludesAll('public/system-static.js', 'P4 menu entry stays frozen while backend compatibility remains', systemStatic, [
  "{ name: 'P4·投决辅助', path: 'investment-decision'",
]);

excludesAll('public/home-static.js', 'home core loop does not bypass the frozen P4 navigation', homeStatic, [
  "key: 'investment-decision'",
  "entry: { page: 'investment-decision' }",
]);

includesAll('package.json', 'P4 verifier is registered as npm script', packageJson, [
  '"verify:investment-decision": "node scripts/verify_investment_decision_support_contract.mjs"',
]);

includesAll('tests/InvestmentDecisionSupportServiceTest.php', 'P4 tests block P0 gate bypass even when ROI and records look ready', read('tests/InvestmentDecisionSupportServiceTest.php'), [
  'testP0GateBlocksInvestmentJudgementEvenWhenOperatingRoiAndRecordsLookReady',
  'blocked_by_p0_ota_gate',
  'p0_ota_gate_not_ready',
  'closed_operating_data_missing',
  'p0_ota_field_loop.ready + operation_execution.roi_ready + decision_record.readiness_ready',
]);

excludesAll('app/service/InvestmentDecisionSupportService.php', 'P4 service does not create or mutate persistence', service, [
  'CREATE TABLE',
  'insertGetId',
  '->insert(',
  '->update(',
  '->delete(',
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
]);

const failures = checks.filter((item) => !item.ok);
if (failures.length > 0) {
  console.error('Investment decision support contract verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}${failure.detail ? ` (${failure.detail})` : ''}`);
  }
  process.exit(1);
}

console.log('Investment decision support contract verification passed.');
