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

const service = read('app/service/Phase3OperationEffectLoopService.php');
const controller = read('app/controller/OnlineData.php');
const route = read('route/app.php');
const docs = read('docs/phase3_operation_effect_loop_acceptance.md');
const runtimeVerifier = read('scripts/verify_phase3_operation_effect_loop_runtime.php');
const packageJson = read('package.json');
const frontend = read('public/index.html');

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service exposes six-stage loop contract', service, [
  'final class Phase3OperationEffectLoopService',
  "public function build(array $options = []): array",
  'public function ledger(int $limit = 50): array',
  'public function publishSop(array $input, ?int $userId = null): array',
  'public function createReplicationPlan(array $input, ?int $userId = null): array',
  'public function buildFromSnapshot(array $snapshot, array $options = []): array',
  "'phase' => 'phase3_operation_effect_loop'",
  "'anomaly' => $anomaly",
  "'operation_action' => $operationAction",
  "'execution_evidence' => $executionEvidence",
  "'effect_review' => $effectReview",
  "'sop' => $sop",
  "'replication' => $replication",
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service is explicit about OTA scope and protected boundaries', service, [
  "'metric_scope' => 'ota_channel'",
  "'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_and_online_daily_data_only'",
  "'collection_logic_changed' => false",
  "'collection_fields_changed' => false",
  "'manual_collection_logic_changed' => false",
  "'automatic_collection_logic_changed' => false",
  "'raw_data_exposed' => false",
  "'auto_decision_enabled' => false",
  'it does not change Ctrip or Meituan acquisition logic, fields, routes, or storage mappings',
  "'causality_claimed' => false",
  "'auto_publish_enabled' => false",
  "'auto_apply_enabled' => false",
  'runtime_phase3_sop_ledger_from_reviewed_patrol_action',
  'runtime_phase3_replication_plan_from_reviewed_sop_candidate',
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service keeps missing states visible', service, [
  "'execution_missing'",
  "'operation_execution_missing'",
  "'execution_evidence_missing'",
  "'review_missing'",
  "'metric_window_missing'",
  "'sop_candidate_missing'",
  "'similar_hotel_missing_in_snapshot'",
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service reads existing patrol snapshots and online_daily_data only', service, [
  'new DailyWorkbenchPatrolService()',
  '->findByRunId($runId)',
  '->latest()',
  "Db::name('online_daily_data')",
  "'read_existing_online_daily_data_only_without_raw_data'",
]);

excludesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service does not call OTA acquisition or mutation paths', service, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
  'captureCtrip',
  'captureMeituan',
  'saveDailyData(',
  'importDataSourceRows(',
  'updateData(',
  'deleteData(',
  'saveCookies',
  "'raw_data' =>",
  '"raw_data"',
  'cookie',
  'usertoken',
  'usersign',
  'spidertoken',
]);

includesAll('app/controller/OnlineData.php', 'phase3 endpoint is registered in OnlineData as read-only GET handler', controller, [
  'use app\\service\\Phase3OperationEffectLoopService;',
  'public function phase3OperationEffectLoop(): Response',
  'public function phase3OperationEffectLoopLedger(): Response',
  'public function publishPhase3OperationSop(): Response',
  'public function createPhase3ReplicationPlan(): Response',
  '$this->checkPermission();',
  'new Phase3OperationEffectLoopService()',
  "'run_id' => (string)$this->request->get('run_id', '')",
  "'target_date' => (string)$this->request->get('target_date', '')",
  "'limit' => $this->request->get('limit', 100)",
]);

includesAll('route/app.php', 'phase3 route exists under online-data group', route, [
  "Route::get('/phase3-operation-effect-loop', 'OnlineData/phase3OperationEffectLoop');",
  "Route::get('/phase3-operation-effect-loop/ledger', 'OnlineData/phase3OperationEffectLoopLedger');",
  "Route::post('/phase3-operation-effect-loop/sops/publish', 'OnlineData/publishPhase3OperationSop');",
  "Route::post('/phase3-operation-effect-loop/replications/create', 'OnlineData/createPhase3ReplicationPlan');",
]);

includesAll('docs/phase3_operation_effect_loop_acceptance.md', 'phase3 acceptance doc describes goal, boundaries, statuses, and verification', docs, [
  '巡检异常 -> 运营动作 -> 执行证据 -> 效果复盘 -> SOP沉淀 -> 多店复制',
  'GET /api/online-data/phase3-operation-effect-loop',
  'POST /api/online-data/phase3-operation-effect-loop/sops/publish',
  'POST /api/online-data/phase3-operation-effect-loop/replications/create',
  '不改变携程、美团手动或自动数据获取逻辑',
  '`scope.collection_logic_changed`',
  '`execution_missing`',
  '`review_missing`',
  '`metric_window_missing`',
  '`sop.status=candidate`',
  '`replication.status=candidate`',
  'npm.cmd run verify:phase3-operation-effect-loop',
]);

includesAll('scripts/verify_phase3_operation_effect_loop_runtime.php', 'phase3 runtime verifier covers candidate and missing states', runtimeVerifier, [
  'new Phase3OperationEffectLoopService()',
  'buildFromSnapshot(',
  'phase3_fixture_snapshot(',
  'metric_window',
  'publishSopFromLoopRow(',
  'createReplicationPlanFromLoopRow(',
  'ledger(',
  'executed_evidence_recorded',
  'reviewed',
  'candidate',
  'execution_missing',
  'raw_data',
]);

includesAll('package.json', 'phase3 verifier is exposed through npm', packageJson, [
  '"verify:phase3-operation-effect-loop": "node scripts/verify_phase3_operation_effect_loop_contract.mjs && C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_phase3_operation_effect_loop_runtime.php"',
]);

includesAll('public/index.html', 'phase3 operation effect loop is visible in the employee workbench UI', frontend, [
  'data-testid="phase3-operation-effect-loop"',
  '第三阶段运营闭环',
  'phase3OperationEffectLoop',
  'phase3OperationEffectLoopLedger',
  'phase3OperationEffectLoopLoading',
  'phase3OperationEffectLoopError',
  'phase3OperationEffectLoopActionUpdating',
  'loadPhase3OperationEffectLoop',
  'loadPhase3OperationEffectLoopLedger',
  'publishPhase3OperationSop',
  'createPhase3ReplicationPlan',
  "request(`/online-data/phase3-operation-effect-loop?",
  "request('/online-data/phase3-operation-effect-loop/ledger?limit=50')",
  "request('/online-data/phase3-operation-effect-loop/sops/publish'",
  "request('/online-data/phase3-operation-effect-loop/replications/create'",
  'phase3OperationEffectLoopRows',
  'phase3OperationEffectLoopCards',
  'phase3OperationEffectLoopLedgerText',
  'phase3OperationEffectLoopStatusText',
  'phase3OperationEffectLoopStatusClass',
  'phase3OperationEffectLoopBoundaryText',
  '巡检异常',
  '执行证据',
  '效果复盘',
  'SOP候选',
  '多店复制',
  '沉淀SOP',
  '生成计划',
]);

includesAll('public/index.html', 'phase3 frontend keeps missing states and OTA boundary visible', frontend, [
  '缺执行',
  '缺任务证据',
  '待复盘',
  '指标不足',
  '只读巡检快照/执行证据/指标窗口',
  '不触发携程或美团采集',
  '尚无可复盘的巡检动作；先生成每日巡检快照。',
]);

const failed = checks.filter((item) => !item.ok);
for (const item of checks) {
  const status = item.ok ? 'PASS' : 'FAIL';
  const detail = item.detail ? ` (${item.detail})` : '';
  console.log(`${status} ${item.file} - ${item.label}${detail}`);
}

if (failed.length > 0) {
  console.error(`Phase 3 operation effect loop contract failed ${failed.length}/${checks.length} checks.`);
  process.exit(1);
}

console.log(`Phase 3 operation effect loop contract passed ${checks.length} checks.`);
