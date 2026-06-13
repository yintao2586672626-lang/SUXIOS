import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function add(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function requireFile(file) {
  add(file, 'file exists', fs.existsSync(path.join(root, file)), file);
}

function requireIncludes(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  add(file, label, missing.length === 0, missing.join(', '));
}

function requirePackageScript(name, command) {
  let ok = false;
  try {
    ok = JSON.parse(read('package.json')).scripts?.[name] === command;
  } catch {
    ok = false;
  }
  add('package.json', `package script ${name}`, ok, `${name}: ${command}`);
}

requireFile('docs/phase1_ota_trusted_loop_goal.md');
requireFile('scripts/verify_phase1_ota_trusted_loop_contract.mjs');

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'phase-one objective is explicit', [
  '第一阶段目标',
  '不是让 AI 全自动管酒店',
  '携程、美团 OTA 经营数据',
  '经营诊断闭环',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'OTA source and scope boundaries stay explicit', [
  '不改变携程和美团手动获取、自动获取逻辑',
  '不改变现有获取字段、字段映射和历史入库兼容口径',
  '不把 OTA 渠道数据包装成全酒店经营事实',
  '不用兜底值、假成功、空数据默认值或宽泛 catch 掩盖采集失败、字段缺失和授权失败',
  '点评明文必须显式授权',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'trusted loop chain is documented', [
  '原始响应证据',
  'source path',
  'metric key',
  'online_daily_data',
  'UI 字段状态',
  'ota-standard 收益指标',
  'AI 经营诊断',
  '运营执行意图',
  '审批、执行证据、复盘',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'priority resources and statuses are documented', [
  'businessData',
  'flowData',
  'tradeData',
  'peerRank',
  'searchKeywords',
  'roomTypes',
  'advertising',
  'reviewData',
  'partial_success',
  'waiting_auth',
  'auth_failed',
  'api_not_hit',
  'field_missing',
  'parse_failed',
  'not_collected',
  'manual_intervention_required',
]);

requirePackageScript('verify:phase1-ota-loop', 'node scripts/verify_phase1_ota_trusted_loop_contract.mjs');
requirePackageScript('verify:phase1-ota-audit', 'node scripts/verify_phase1_ota_trusted_loop_audit.mjs');
requirePackageScript('verify:phase1-employee-console', 'node scripts/verify_phase1_ota_employee_console_contract.mjs');
requirePackageScript('verify:phase1-gap-explanations', 'node scripts/verify_phase1_ota_gap_explanations.mjs');
requirePackageScript('verify:phase1-live-closure-contract', 'node scripts/verify_phase1_ota_live_closure_contract.mjs');
requirePackageScript('verify:phase1-live-action-queue', 'node scripts/verify_phase1_live_action_queue_runtime.mjs');
requirePackageScript('inspect:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php');
requirePackageScript('verify:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php --strict');

requireIncludes('docs/release_functional_acceptance_matrix.md', 'functional acceptance includes phase-one gate', [
  'verify:phase1-ota-loop',
  'verify:phase1-ota-audit',
  'verify:phase1-employee-console',
  'verify:phase1-gap-explanations',
  'verify:phase1-live-closure-contract',
  'verify:phase1-live-action-queue',
  'OTA trusted loop',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'phase-one goal includes audit, employee console, and gap verifiers', [
  'npm.cmd run verify:phase1-ota-audit',
  'npm.cmd run verify:phase1-employee-console',
  'npm.cmd run verify:phase1-gap-explanations',
  'npm.cmd run verify:phase1-live-closure-contract',
  'npm.cmd run verify:phase1-live-action-queue',
]);

requireIncludes('docs/phase1_ota_live_closure_evidence.md', 'phase-one live closure evidence gate is documented', [
  'capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution',
  'npm.cmd run inspect:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure',
  'npm.cmd run verify:phase1-live-action-queue',
]);

requireIncludes('route/app.php', 'OTA acquisition, diagnosis, revenue, AI, and execution routes exist', [
  "Route::get('/collection-resources', 'OnlineData/collectionResourceCatalog');",
  "Route::get('/collection-reliability', 'OnlineData/collectionReliability');",
  "Route::get('/data-analysis', 'OnlineData/dataAnalysis');",
  "Route::post('/ai-analysis', 'OnlineData/aiAnalysis');",
  "Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics');",
  "Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');",
  "Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');",
  "Route::get('/execution-flow', 'OperationManagement/executionFlow');",
]);

requireIncludes('app/service/PlatformDataSyncService.php', 'platform data sync keeps raw evidence, normalized rows, and explicit states', [
  'final class PlatformDataSyncService',
  'storeRawRecord($source, $taskId, $payload',
  'saveNormalizedRows($rows)',
  "'last_sync_status' => $status",
  'partial_success',
  'manual_intervention_required',
]);

requireIncludes('app/service/OtaRevenueMetricService.php', 'revenue metric layer exposes gaps and trust', [
  "'data_gaps' => $dataGaps",
  "'metric_trust' => $metricTrust",
]);

requireIncludes('app/service/OtaInsightAnalysisService.php', 'AI insight layer remains gap-aware', [
  "'data_gaps' => $metrics['data_gaps'] ?? []",
  'traffic_conversion',
  'competitor_price_gap',
]);

requireIncludes('app/controller/Agent.php', 'Agent OTA diagnosis keeps evidence policy and gaps visible', [
  'public function otaDiagnosis()',
  'source_policy',
  'data_gaps',
  'database_only_no_synthetic_conclusion',
  'database_only_latest_available_reference_not_execution_ready',
  'blocked_by_missing_ota_data',
  'blocked_by_non_target_date_data',
  'ota_latest_available_not_target_date',
]);

requireIncludes('app/service/OperationManagementService.php', 'operation loop requires execution evidence', [
  'public function executionFlow',
  'execution evidence is required',
  'data_gaps',
  'data_collection',
  'evidence_refs',
  'source_policy',
  'protected_boundary',
]);

requireIncludes('docs/revenue_agent_api.md', 'revenue recommendations remain advisory', [
  'advisory-only',
  'It does not write OTA rates.',
  'manual review',
]);

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA trusted loop contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) {
      console.error(`  missing/expected: ${failure.detail}`);
    }
  }
  process.exit(1);
}

console.log(`[verify:phase1-ota-loop] ${checks.length} checks passed`);
