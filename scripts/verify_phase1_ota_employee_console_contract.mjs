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

function includesAll(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function packageScript(name, command) {
  let actual = '';
  try {
    actual = JSON.parse(read('package.json')).scripts?.[name] ?? '';
  } catch {
    actual = '';
  }
  check('package.json', `package script ${name}`, actual === command, `${name}: ${command}`);
}

includesAll('docs/phase1_ota_employee_console_acceptance.md', 'employee six questions and explicit non-completion rules are documented', [
  '员工视角必须回答六个问题',
  '今天携程、美团 OTA 数据有没有采到',
  '哪些字段可信',
  '哪些字段缺失、失败、未授权或未采集',
  'AI 建议依据',
  '下一步该执行什么动作',
  'verify:public-entry',
  'verify:e2e-contracts',
  '真实当天携程/美团采集样例',
  'evidence_sources',
  'data_gaps',
  '不允许用空值、默认值、成功文案或本地兜底分析替代',
]);

includesAll('route/app.php', 'employee workflow routes exist', [
  "Route::get('/collection-reliability', 'OnlineData/collectionReliability');",
  "Route::get('/data-analysis', 'OnlineData/dataAnalysis');",
  "Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics');",
  "Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');",
  "Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');",
  "Route::get('/execution-flow', 'OperationManagement/executionFlow');",
]);

includesAll('public/index.html', 'data health UI exposes collection state, field assets, and next actions', [
  'collectionHealthSummaryCards',
  'collectionHealthStatusText',
  'collectionHealthFieldAssetCards',
  'collectionHealthFieldAssetListText',
  'collectionHealthFailureReasonRanking',
  'collectionHealthPendingActions',
  'collectionHealthCtripMissingActionRows',
]);

includesAll('public/index.html', 'AI diagnosis and operation UI bindings exist', [
  'otaDiagnosisMetricCards',
  'otaDiagnosisResultSections',
  'otaDiagnosisResult',
  'operationExecutionFlow',
  'operationExecutionItems',
  'operationExecutionNextActionClass',
  '/operation/execution-flow',
  '/operation/execution-intents',
]);

includesAll('app/controller/OnlineData.php', 'collection reliability backend keeps explicit states and actions', [
  'public function collectionReliability',
  'data_quality',
  'missing_count',
  'not_collected',
  'auth_failed',
  'field_missing',
  'next_action',
  '/api/online-data/collection-reliability.field_definitions',
  '/api/online-data/collection-reliability.data_quality',
]);

includesAll('app/service/OtaRevenueMetricService.php', 'revenue metrics expose data gaps and trust state', [
  "'data_gaps' => $dataGaps",
  "'metric_trust' => $metricTrust",
  "'traffic' =>",
  "'channel_metrics' => $this->channelMetrics",
]);

includesAll('app/controller/Agent.php', 'OTA diagnosis attaches evidence and actionable next steps', [
  'public function otaDiagnosis',
  'evidence_sources',
  'action_items',
  'source_policy',
  'data_gaps',
  'next_action',
]);

includesAll('app/service/OperationManagementService.php', 'operation execution flow requires approval and evidence', [
  'public function createExecutionIntent',
  'public function executionFlow',
  'blocked execution intent cannot be approved',
  'execution evidence is required',
  'next_action',
]);

includesAll('docs/phase1_ota_trusted_loop_goal.md', 'goal document includes employee console verifier', [
  'npm.cmd run verify:phase1-employee-console',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit document references employee console contract', [
  'verify:phase1-employee-console',
  '员工视角验收',
]);

packageScript('verify:phase1-employee-console', 'node scripts/verify_phase1_ota_employee_console_contract.mjs');

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 employee console contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-employee-console] ${checks.length} checks passed`);
