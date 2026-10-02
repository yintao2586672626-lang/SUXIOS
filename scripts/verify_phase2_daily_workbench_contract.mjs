import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { parse, tokenizer } from 'acorn';

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

// Use the JavaScript lexer so comments cannot satisfy a runnable contract, while
// URLs, templates and regular-expression literals keep their original meaning.
function executableJs(source) {
  try {
    const comments = [];
    const tokens = tokenizer(source, {
      ecmaVersion: 'latest', sourceType: 'module',
      onComment: (_block, _text, start, end) => comments.push({ start, end }),
    });
    while (tokens.getToken().type.label !== 'eof') { /* collect comment ranges */ }
    let cursor = 0;
    let result = '';
    for (const { start, end } of comments) {
      result += source.slice(cursor, start) + source.slice(start, end).replace(/[^\r\n]/g, ' ');
      cursor = end;
    }
    return result + source.slice(cursor);
  } catch {
    return '';
  }
}

function startupHelperSources(source) {
  try {
    const ast = parse(source, { ecmaVersion: 'latest', sourceType: 'module' });
    const declaration = ast.body.find((node) => node.type === 'ExportNamedDeclaration'
      && node.declaration?.declarations?.some((item) => item.id?.name === 'FRONTEND_STARTUP_HELPER_SOURCES'))
      ?.declaration.declarations.find((item) => item.id?.name === 'FRONTEND_STARTUP_HELPER_SOURCES');
    const init = declaration?.init;
    if (init?.type !== 'CallExpression' || init.callee?.object?.name !== 'Object'
        || init.callee?.property?.name !== 'freeze' || init.arguments?.[0]?.type !== 'ArrayExpression') return [];
    return init.arguments[0].elements.filter((item) => item?.type === 'Literal' && typeof item.value === 'string')
      .map((item) => item.value);
  } catch {
    return [];
  }
}

const route = read('route/app.php');
const otaActionHandler = read('app/service/Ota/OtaActionHandler.php');
const controller = read('app/controller/concern/OperationWorkbenchConcern.php');
const service = read('app/service/DailyWorkbenchPatrolService.php');
const operationService = [
  read('app/service/OperationManagementService.php'),
  read('app/service/operation/OperationExecutionTenantConcern.php'),
].join('\n');
const patrolCommand = read('app/command/DailyWorkbenchPatrol.php');
const consoleConfig = read('config/console.php');
const patrolCronScript = read('scripts/daily_workbench_patrol_cron.php');
const runtimeVerifier = read('scripts/verify_phase2_daily_workbench_runtime.php');
const acceptanceDoc = read('docs/phase2_daily_workbench_acceptance.md');
const packageJson = read('package.json');
const frontendTemplate = read('resources/frontend/app-template.html');
const frontendEntry = read('public/app-main.js');
const frontend = `${frontendTemplate}\n${frontendEntry}`;
const publicIndex = read('public/index.html');
const ctripStatic = read('public/ctrip-static.js');
const dataHealthStatic = read('public/data-health-static.js');
const manualFetchRoot = read('app/controller/concern/OnlineDataManualFetchConcern.php');
const ctripExecutionConcern = read('app/controller/concern/CtripManualFetchExecutionConcern.php');
const manualFetchConcern = [manualFetchRoot, ctripExecutionConcern].join('\n');
const frontendStartupBuild = read('scripts/lib/frontend_startup_helpers_build.mjs');
const frontendBootstrap = read('public/app-bootstrap.js');
const homeSecondaryTemplate = read('resources/frontend/templates/fragments/23e-home-shared-secondary.html');
const onlineDataTemplate = read('resources/frontend/templates/fragments/35-page-online-data.html');
const cookieEndpointConcern = read('app/controller/concern/CookieEndpointConcern.php');
const compassStatic = read('public/compass-static.js');
const fullAutomation = read('tests/automation/suxi_full_automation_test.mjs');

const dailyStart = controller.indexOf('public function dailyWorkbench(): Response');
const dailySlice = dailyStart >= 0 ? controller.slice(dailyStart) : '';

const frontendOnlineDataStart = frontend.indexOf("currentPage === 'online-data'");
const frontendDataHealthStart = frontend.indexOf('data-testid="online-data-health-panel"', frontendOnlineDataStart);
const frontendDataHealthEnd = frontend.indexOf("onlineDataTab === 'analysis'", frontendDataHealthStart);
const frontendDataHealthSlice = frontendDataHealthStart >= 0 && frontendDataHealthEnd > frontendDataHealthStart
  ? frontend.slice(frontendDataHealthStart, frontendDataHealthEnd)
  : '';
const frontendPanelStart = frontend.indexOf('data-testid="manual-one-click-fetch"');
const frontendPanelEnd = frontend.indexOf('data-testid="phase1-employee-six-question-summary"', frontendPanelStart);
const frontendPanelSlice = frontendPanelStart >= 0 && frontendPanelEnd > frontendPanelStart
  ? frontend.slice(frontendPanelStart, frontendPanelEnd)
  : '';

const frontendLoaderStart = frontend.indexOf('const loadDailyWorkbench = async');
const frontendLoaderEnd = frontend.indexOf('const loadDataHealthOperationLogs = async', frontendLoaderStart);
const frontendLoaderSlice = frontendLoaderStart >= 0 && frontendLoaderEnd > frontendLoaderStart
  ? frontend.slice(frontendLoaderStart, frontendLoaderEnd)
  : '';
const dataHealthRefreshStart = frontend.indexOf('const loadDataHealthPanel = async');
const dataHealthRefreshEnd = frontend.indexOf('const triggerAutoFetch = async', dataHealthRefreshStart);
const dataHealthRefreshSlice = dataHealthRefreshStart >= 0 && dataHealthRefreshEnd > dataHealthRefreshStart
  ? frontend.slice(dataHealthRefreshStart, dataHealthRefreshEnd)
  : '';

includesAll('route/app.php', 'daily workbench and patrol routes exist', route, [
  "Route::get('/daily-workbench', 'OnlineData/dailyWorkbench');",
  "Route::get('/daily-workbench-patrols', 'OnlineData/dailyWorkbenchPatrols');",
  "Route::get('/daily-workbench-patrols/report', 'OnlineData/dailyWorkbenchPatrolReport');",
  "Route::post('/daily-workbench-patrols/run', 'OnlineData/runDailyWorkbenchPatrol');",
  "Route::post('/daily-workbench-patrols/actions/update', 'OnlineData/updateDailyWorkbenchPatrolAction');",
  "Route::post('/daily-workbench-patrols/actions/review', 'OnlineData/reviewDailyWorkbenchPatrolAction');",
  "Route::get('api/online-data/daily-workbench-patrol-cron', 'OnlineData/dailyWorkbenchPatrolCron');",
]);

includesAll('app/service/Ota/OtaActionHandler.php', 'OTA handler composes the split daily workbench concern', otaActionHandler, [
  'use app\\controller\\concern\\OperationWorkbenchConcern;',
  'use OperationWorkbenchConcern;',
]);

includesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench endpoint shape exists', controller, [
  'public function dailyWorkbench(): Response',
  'private function buildDailyWorkbenchPayload(?int $hotelId, string $targetDate, ?int $limitOverride = null): array',
  'private function buildDailyWorkbenchRow(array $hotel, array $reliability, string $targetDate): array',
  'private function dailyWorkbenchWorkflowChain(',
  'private function dailyWorkbenchWorkflowStage(',
  'private function dailyWorkbenchAiExplanation(array $aiEvidence, string $questionStatus): array',
  'private function buildDailyWorkbenchSummary(array $rows): array',
  'private function buildDailyWorkbenchNextActions(array $rows): array',
]);

includesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench patrol endpoints exist', controller, [
  'public function dailyWorkbenchPatrols(): Response',
  'public function dailyWorkbenchPatrolReport(): Response',
  'public function runDailyWorkbenchPatrol(): Response',
  'public function updateDailyWorkbenchPatrolAction(): Response',
  'public function reviewDailyWorkbenchPatrolAction(): Response',
  'public function dailyWorkbenchPatrolCron(): Response',
  'private function splitDailyWorkbenchPatrolPayloadsByHotel(array $payload): array',
  'private function dailyWorkbenchPatrolActionContext(array $snapshot, array $data): array',
  'private function dailyWorkbenchPatrolTrackingKey(int $hotelId, string $actionCode, string $questionKey): string',
  'new DailyWorkbenchPatrolService()',
  '->healthForHotel($hotelId, $targetDate)',
  '->listForHotel($hotelId, $limit)',
  '->findByRunIdForHotel(',
  "requireOperationHotelCapability($hotelId, 'operation.execute')",
  'syncDailyWorkbenchPatrolAction(',
  'reviewExecutionTask($taskId, [$hotelId]',
  "'trigger_type' => 'manual'",
  "'trigger_type' => 'cron'",
  "'health_by_hotel' => $healthByHotel",
  '->healthForHotel($hotelId, $targetDate)',
  "checkPublicEndpointRateLimit('daily_workbench_patrol_cron'",
  "Env::get('CRON_TOKEN'",
  "'cron_token_not_configured'",
  "'invalid_cron_token'",
  "'audit_type' => 'phase2_daily_workbench_patrol'",
  "'audit_type' => 'phase2_daily_workbench_action_tracking'",
  "'audit_type' => 'phase2_daily_workbench_action_review'",
  "'audit_type' => 'phase2_daily_workbench_report_export'",
  "'Content-Type' => 'text/markdown; charset=UTF-8'",
]);

includesAll('app/command/DailyWorkbenchPatrol.php', 'daily workbench patrol command wraps protected cron endpoint', patrolCommand, [
  'class DailyWorkbenchPatrol extends Command',
  "setName('online-data:daily-workbench-patrol')",
  "Env::get('CRON_TOKEN'",
  "Env::get('DAILY_WORKBENCH_BASE_URL'",
  "Env::get('APP_URL'",
  "'/api/online-data/daily-workbench-patrol-cron?'",
  "'X-Cron-Token: ' . $token",
  'Boundary: read existing OTA evidence only; acquisition logic and fields are unchanged.',
]);

includesAll('config/console.php', 'daily workbench patrol command is registered', consoleConfig, [
  "'online-data:daily-workbench-patrol' => 'app\\command\\DailyWorkbenchPatrol'",
]);

includesAll('scripts/daily_workbench_patrol_cron.php', 'daily workbench patrol cron script delegates to command', patrolCronScript, [
  'php think online-data:daily-workbench-patrol',
  'online-data:daily-workbench-patrol',
  "'--base-url='",
  "'--target-date='",
  "'--limit='",
  "'--timeout='",
]);

includesAll('scripts/verify_phase2_daily_workbench_runtime.php', 'daily workbench runtime verifier covers the employee loop without real OTA collection', runtimeVerifier, [
  'new DailyWorkbenchPatrolService()',
  '$service->write(',
  '$service->health($targetDate)',
  '$service->updateActionStatus(',
  '$service->updateActionReview(',
  '$service->markdownReport($runId)',
  'setRuntimePath($runtimePath)',
  'runtime_isolated',
  'AI explanation exposes summary, missing evidence, next step, and boundary.',
  'Action review result is recorded and summarized.',
]);

includesAll('package.json', 'phase2 daily workbench runtime verifier is runnable through npm', packageJson, [
  '"verify:phase2-daily-workbench-runtime": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_phase2_daily_workbench_runtime.php"',
]);

includesAll('app/service/OperationManagementService.php', 'daily workbench patrol action syncs to operation execution loop', operationService, [
  'public function syncDailyWorkbenchPatrolAction(array $hotelIds, array $input, int $userId): array',
  'public function reviewExecutionTask(int $taskId, array $hotelIds, array $input = [], int $reviewerId = 0): array',
  'private function dailyWorkbenchPatrolSourceRecordId(string $runId, int $hotelId, string $actionCode, string $questionKey): int',
  'private function findDailyWorkbenchPatrolIntent(int $hotelId, int $sourceRecordId): ?array',
  'private function buildDailyWorkbenchPatrolExecutionIntentInput(array $input, int $sourceRecordId): array',
  "'source_module' => 'daily_workbench_patrol'",
  "'object_type' => 'data_collection'",
  "'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_only'",
  "'metric_scope' => 'ota_channel'",
  'syncSourceVerifiedMetricReadback(',
  'hasVerifiedExecutionSourceProvenance(',
  'approveExecutionIntent(',
  'executeExecutionTask(',
]);

includesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench reuses phase1 evidence without changing acquisition', dailySlice, [
  'buildCollectionReliabilityPayload($currentHotelId, $targetDate, $targetDate)',
  'withPhase1EmployeeQuestions',
  'loadDashboardHotels',
  "'metric_scope' => 'ota_channel'",
  "'source_policy' => 'read_existing_collection_reliability_only'",
  "'source_policy' => 'read_existing_online_daily_data_only'",
  "'source_policy' => 'read_existing_phase1_employee_question_rows_only'",
  'Do not change Ctrip/Meituan manual or automatic acquisition logic, fields, mappings, or storage.',
  'Failure is exposed as request_failed; no fallback success is generated.',
]);

includesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench exposes employee-ready statuses', dailySlice, [
  'today_ota_collected',
  'trusted_fields',
  'missing_fields',
  'revenue_traffic_conversion',
  'ai_evidence',
  'next_operation_action',
  "(new OperatingLoopKernelService())->currentForHotelDate(",
  "'workflow_chain' => (array)($operatingLoop['stages'] ?? [])",
  "'diagnostic_workflow_chain' => $workflowChain",
  "'source_policy' => 'hotel_operating_cycle_kernel_only'",
  'today_ota_data',
  'field_trust_and_gaps',
  'revenue_metrics',
  'ai_diagnosis',
  'operation_action',
  'missing_question_keys',
  'target_date_source_rows',
  'high_priority_action_count',
  "'explanation' => $aiExplanation",
  'AI suggestions must cite OTA evidence and data gaps',
  'Read-only workflow decomposition',
]);

check('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench canonical stages use the current tenant hotel and date',
  /\$operatingLoop\s*=\s*\(new OperatingLoopKernelService\(\)\)->currentForHotelDate\(\s*\$tenantId\s*,\s*\$hotelId\s*,\s*\$targetDate\s*\)/.test(dailySlice),
  'requires the same-scope operating loop kernel before exposing canonical stages');

excludesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench slice does not call OTA acquisition paths', dailySlice, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
  'captureCtrip',
  'captureMeituan',
  'syncDataSource(',
  'saveCookies',
  'saveDailyData(',
  'importDataSourceRows(',
  'updateData(',
  'deleteData(',
]);

includesAll('app/service/DailyWorkbenchPatrolService.php', 'patrol snapshot service is runtime-only and explicit about scope', service, [
  'final class DailyWorkbenchPatrolService',
  "private const SNAPSHOT_DIR = 'phase2_daily_workbench_patrol';",
  'runtime_path()',
  "'snapshot_type' => 'phase2_daily_workbench_patrol'",
  "'metric_scope' => 'ota_channel'",
  "'source_policy' => 'read_existing_collection_reliability_only'",
  "'storage_policy' => 'runtime_json_snapshot_only'",
  "'collection_logic_changed' => false",
  "'raw_data_exposed' => false",
  "'sensitive_credentials_exposed' => false",
  'public function health(?string $targetDate = null): array',
  'private function automationHealth(): array',
  "'status' => 'missing'",
  "$status = 'auto_ready';",
  "$status = 'manual_ready';",
  "$status = 'stale';",
  "'automation' => $automation",
  "'automation_configured' => (bool)($automation['cron_token_configured'] ?? false)",
  "'scheduler_status' => 'external_scheduler_unverified'",
  "'secret_exposed' => false",
  "'next_action' => 'run_patrol_now'",
  "$nextAction = 'review_actions';",
  'public function markdownReport(string $runId = \'\'): array',
  'private function buildMarkdownReport(array $snapshot): string',
  '## AI 建议解释',
  "'missing_codes'",
  "'next_step'",
  'OTA 渠道，不代表全酒店经营事实',
  '本报告不触发携程或美团采集',
  'public function updateActionStatus(array $input, ?int $userId = null): array',
  'public function updateActionReview(array $input, ?int $userId = null): array',
  "'source_policy' => 'operator_status_on_runtime_patrol_snapshot_only'",
  "'source_policy' => 'operator_review_on_runtime_patrol_snapshot_only'",
  "'review_state' => 'pending_review'",
  'summarizeReviewTracking',
]);

excludesAll('app/service/DailyWorkbenchPatrolService.php', 'patrol service does not contain OTA acquisition calls', service, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'syncDataSource(',
  'curl_exec',
  'file_get_contents("https://',
]);

excludesAll('app/command/DailyWorkbenchPatrol.php', 'daily workbench patrol command does not call OTA acquisition paths', patrolCommand, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
  'captureCtrip',
  'captureMeituan',
  'saveCookies',
]);

excludesAll('scripts/verify_phase2_daily_workbench_runtime.php', 'runtime verifier does not call OTA acquisition paths', runtimeVerifier, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
  'captureCtrip',
  'captureMeituan',
  'saveCookies',
]);

includesAll('public/index.html', 'focused online-data panel retains manual one-click OTA acquisition', frontendDataHealthSlice, [
  'data-testid="manual-one-click-fetch"',
  'manualOneClickFetchCards',
  'manualOneClickFetchScopeText',
  'runManualOneClickFetch',
  'manualOneClickFetchDisplayRows',
]);

includesAll('resources/frontend/app-template.html', 'focused online-data panel exposes the one-page operating loop', frontendDataHealthSlice, [
  'data-testid="core-operations-loop"',
  'data-testid="core-loop-yesterday-data"',
  'data-testid="core-loop-competitor-comparison"',
  'data-testid="core-loop-anomaly-judgment"',
  'data-testid="core-loop-ai-actions"',
  'data-testid="core-loop-ai-to-operation"',
  'data-testid="core-loop-operation-tasks"',
  'data-testid="core-loop-next-day-review"',
  'data-testid="phase2-daily-workbench"',
  'data-testid="daily-workbench-write-boundary"',
  'data-testid="phase3-operation-effect-loop"',
  "updateDailyWorkbenchPatrolAction(action, 'in_progress')",
  'createCoreOperationsDiagnosisIntent(item)',
  'approveOperationExecutionIntent',
  'recordOperationExecutionEvidence',
  'reviewOperationExecutionTask',
]);

includesAll('app/controller/concern/OnlineDataManualFetchConcern.php', 'Ctrip manual fetch composes and invokes its execution concern', manualFetchRoot, [
  'use CtripManualFetchExecutionConcern;',
  '$this->executeCtripManualFetch(',
]);

includesAll('app/controller/concern/OnlineDataManualFetchConcern.php', 'Ctrip manual fetch keeps zero Qunar visitors as a non-blocking field gap', manualFetchConcern, [
  "'saved_with_qunar_visitor_gap'",
  "'no_saved_with_qunar_visitor_gap'",
  'partial_qunar_visitor_gap',
  '仅作为字段缺口提示',
  '不阻断携程竞争圈获取和入库',
]);

includesAll('public/index.html', 'manual one-click Ctrip fetch delegates the non-blocking Qunar gap decision to the static helper', frontend, [
  'manualOneClickFetchQunarVisitorNeedsRetry',
  'summarizeManualOneClickFetchQunarVisitorQuality(ctripHotelsList.value)',
  "const summarizeManualOneClickFetchRows = requireDataHealthStatic('summarizeManualOneClickFetchRows')",
  'summarizeManualOneClickFetchRows(manualOneClickFetchRows.value)',
  'buildManualOneClickFetchRunningRow({',
]);

includesAll('public/data-health-static.js', 'manual one-click fetch display summary helper owns saved-count aggregation', dataHealthStatic, [
  'const summarizeManualOneClickFetchRows = (rows = []) =>',
  "if (row.status === 'success' || row.status === 'partial') summary.savedCount += Number(row.savedCount || 0);",
  'const buildManualOneClickFetchCards = ({',
  'const sortManualOneClickFetchRows = (rows = []) =>',
  'const summarizeManualOneClickFetchQunarVisitorQuality = (rows = []) =>',
  'const manualOneClickFetchQunarVisitorNeedsRetry = (quality = {}) =>',
  'Number(quality?.rowCount ?? quality?.row_count ?? 0) > 0',
  '&& Number(quality?.total ?? quality?.visitor_total ?? 0) <= 0',
  '&& quality?.ready !== true',
]);

includesAll('public/ctrip-static.js', 'single Ctrip fetch distinguishes persisted success from bounded display-only results', executableJs(ctripStatic), [
  "data.qunar_visitor_quality?.status === 'partial_qunar_visitor_gap'",
  "const saveBlocked = data.save_status === 'blocked'",
  'const ctripFetchReady = ctripRowsReturned',
  'setFetchSuccess(allHotels.length > 0 && isCtripVerifiedReportSource(currentFetchMeta))',
  '仅作为字段缺口提示，不阻断携程竞争圈获取和入库。',
  "status: (saveBlocked || temporaryDisplayOnly) ? 'display_only' : (persisted ? 'success' : 'no_saved')",
]);

const ctripReportSourceStart = ctripStatic.indexOf('const isCtripVerifiedReportSource =');
const ctripReportSourceEnd = ctripStatic.indexOf('const buildCtripFetchRawFailureResult =', ctripReportSourceStart);
const ctripReportSourceSlice = ctripReportSourceStart >= 0 && ctripReportSourceEnd > ctripReportSourceStart
  ? executableJs(ctripStatic.slice(ctripReportSourceStart, ctripReportSourceEnd)) : '';
includesAll('public/ctrip-static.js', 'Ctrip report sources require verified same-date evidence and exclude display-only data', ctripReportSourceSlice, [
  "return String(meta?.status || '') === 'success'",
  "&& String(meta?.response_date_status || '') === 'verified'",
  '&& datePattern.test(dataDate)',
  '&& sourceBusinessDate === dataDate',
  '&& (!datePattern.test(requestDate) || requestDate === dataDate)',
  'meta?.readback_verified === true',
  'meta?.ranking_cache_eligible === true',
  "&& String(meta?.verification_status || '') === 'source_verified'",
  '&& (verifiedFreshReadback || verifiedStoredSnapshot)',
]);
let ctripReportSourceBehavior = false;
try {
  const verifiedMeta = {
    status: 'success', response_date_status: 'verified', data_date: '2026-09-14',
    request_date: '2026-09-14', source_business_date: '2026-09-14', readback_verified: true,
  };
  const cases = [
    [verifiedMeta, true],
    [{ ...verifiedMeta, status: 'display_only' }, false],
    [{ status: 'display_only' }, false],
    [{ ...verifiedMeta, readback_verified: false }, false],
    [{ ...verifiedMeta, response_date_status: 'unverified' }, false],
    [{ ...verifiedMeta, data_date: '' }, false],
    [{ ...verifiedMeta, source_business_date: '2026-09-13' }, false],
    [{ ...verifiedMeta, request_date: '2026-09-13' }, false],
    [{ ...verifiedMeta, readback_verified: false, ranking_cache_eligible: true, verification_status: 'source_verified' }, true],
    [{ ...verifiedMeta, readback_verified: false, ranking_cache_eligible: true, verification_status: 'unverified' }, false],
  ];
  ctripReportSourceBehavior = vm.runInNewContext(
    `${ctripReportSourceSlice}\ncases.every(([meta, expected]) => isCtripVerifiedReportSource(meta) === expected)`,
    { cases }, { timeout: 1000 },
  ) === true;
} catch {
  // A missing or invalid helper cannot establish a verified report source.
}
check('public/ctrip-static.js', 'Ctrip report source behavior rejects display-only and mismatched evidence',
  ctripReportSourceBehavior, 'same-scope readback or a verified stored snapshot is required');

excludesAll('app/controller/concern/OnlineDataManualFetchConcern.php', 'Ctrip manual fetch no longer blocks success on the Qunar field gap', manualFetchConcern, [
  '需要自动重抓最多 3 次',
  '不能按整次成功处理',
  '携程和去哪儿都返回有效值才算成功',
]);

excludesAll('app/controller/concern/OnlineDataManualFetchConcern.php', 'Ctrip manual fetch no longer cancels the whole save on zero Qunar visitors', manualFetchConcern, [
  '判定为本次抓取失败，已取消入库，请自动重抓',
  "'reason' => 'ctrip_qunar_visitors_zero'",
]);

includesAll('public/index.html', 'manual one-click Ctrip fetch does not mark zero-Qunar retry exhaustion as success', frontend, [
  "const summarizeManualOneClickFetchResult = requireDataHealthStatic('summarizeManualOneClickFetchResult')",
  'summarizeManualOneClickFetchResult({',
  "qunarVisitorNeedsRetry: platform === 'ctrip' && manualOneClickFetchQunarVisitorNeedsRetry(ctripQunarQuality)",
  'buildManualOneClickFetchResultRow({',
]);

includesAll('public/data-health-static.js', 'manual one-click Ctrip fetch result helper keeps zero-Qunar as non-blocking field gap', dataHealthStatic, [
  'const summarizeManualOneClickFetchResult = ({',
  "normalizedPlatform === 'ctrip' && count > 0",
  'partial_qunar_visitor_gap',
]);

const authenticatedManifestMatch = publicIndex.match(/<script\b[^>]*\bid=["']suxi-authenticated-assets["'][^>]*>([\s\S]*?)<\/script>/i);
let authenticatedAssets = [];
try {
  const parsed = JSON.parse(authenticatedManifestMatch?.[1] || '[]');
  authenticatedAssets = Array.isArray(parsed) ? parsed : [];
} catch {
  // Invalid manifests fail the source-loading contract below.
}
const startupHelpersInManifest = authenticatedAssets.some((asset) => {
  const src = typeof asset === 'string' ? asset : asset?.src;
  const phase = typeof asset === 'string' ? 'startup' : (asset?.phase || 'startup');
  const type = typeof asset === 'string' ? 'script' : (asset?.type || 'script');
  return phase === 'startup' && type === 'script' && /^app-startup-helpers\.min\.js\?v=.+$/.test(src || '');
});
const startupSources = startupHelperSources(frontendStartupBuild);
check('public/index.html', 'home entry points to daily workbench data-health view',
  startupHelpersInManifest && startupSources.includes('compass-static.js')
    && homeSecondaryTemplate.includes("openHomeQuickEntry({ page: 'online-data', tab: 'data-health' })")
    && frontendEntry.includes('window.SUXI_COMPASS_STATIC'),
  'requires the home action and compass source in the authenticated startup bundle');
includesAll('public/app-bootstrap.js', 'authenticated startup loads the declared helper sources before the app entry', frontendBootstrap, [
  "const AUTH_ASSET_MANIFEST_ID = 'suxi-authenticated-assets'",
  'const assets = authenticatedAssets();',
  'const startupAssets = assets.filter((asset) => asset.phase === ASSET_PHASE_STARTUP);',
  'const startupScripts = startupAssets.filter((asset) => asset.type === ASSET_TYPE_SCRIPT);',
  'await Promise.all(prerequisites.map((src) => loadScript(src)));',
  'await loadScript(entry);',
]);

includesAll('public/compass-static.js', 'compass OTA sync card opens daily workbench first', compassStatic, [
  "page: 'online-data', tab: 'data-health'",
]);

includesAll('docs/phase2_daily_workbench_acceptance.md', 'phase2 acceptance doc captures deployable daily patrol requirements', acceptanceDoc, [
  '第二阶段不追求 AI 全自动管酒店',
  '不改变携程和美团手动获取逻辑',
  '不改变携程和美团自动获取逻辑',
  'php think online-data:daily-workbench-patrol',
  'php scripts/daily_workbench_patrol_cron.php',
  'CRON_TOKEN',
  '自动入口能通过命令或任务计划可复制运行',
]);

includesAll('public/index.html', 'daily workbench frontend loader uses read-only and patrol APIs', frontendLoaderSlice, [
  'request(`/online-data/daily-workbench?${params.toString()}`)',
  'dailyWorkbench.value = res.data || {}',
  'request(`/online-data/daily-workbench-patrols?${params.toString()}`)',
  'health: res.data?.health',
  "fetch(API_BASE + `/online-data/daily-workbench-patrols/report?${params.toString()}`",
  "request('/online-data/daily-workbench-patrols/run'",
  "request('/online-data/daily-workbench-patrols/actions/update'",
  "request('/online-data/daily-workbench-patrols/actions/review'",
  'target_date: item.targetDate',
  'platform: item.platform',
  'action_text: item.actionText',
  'result_status: resultStatus',
]);

const patrolRunStart = frontendEntry.indexOf('const openDailyWorkbenchPatrolConfirmation =');
const patrolExportStart = frontendEntry.indexOf('const exportDailyWorkbenchPatrolReport =', patrolRunStart);
const patrolExportEnd = frontendEntry.indexOf('const dailyWorkbenchPatrolActionKey =', patrolExportStart);
const patrolRunSlice = patrolRunStart >= 0 && patrolExportStart > patrolRunStart
  ? executableJs(frontendEntry.slice(patrolRunStart, patrolExportStart)) : '';
const patrolExportSlice = patrolExportStart >= 0 && patrolExportEnd > patrolExportStart
  ? executableJs(frontendEntry.slice(patrolExportStart, patrolExportEnd)) : '';
const runConfirmationGate = /if\s*\(!dailyWorkbenchPatrolConfirming\.value\)\s*\{\s*openDailyWorkbenchPatrolConfirmation\(confirmationScope\);\s*return;\s*\}/;
const changedScopeGate = /if\s*\(dailyWorkbenchPatrolConfirmationScope !== confirmationScope\)\s*\{\s*openDailyWorkbenchPatrolConfirmation\(confirmationScope\);\s*dailyWorkbenchPatrolError\.value = [^;]+;\s*return;\s*\}/;
const patrolRunPost = patrolRunSlice.indexOf("await request('/online-data/daily-workbench-patrols/run'");
const runConfirmationMatch = runConfirmationGate.exec(patrolRunSlice);
const changedScopeMatch = changedScopeGate.exec(patrolRunSlice);
check('public/app-main.js', 'exposed daily workbench writes retain explicit operator confirmation',
  patrolRunPost >= 0 && runConfirmationMatch && changedScopeMatch
    && runConfirmationMatch.index < patrolRunPost && changedScopeMatch.index < patrolRunPost
    && patrolRunSlice.includes('dailyWorkbenchPatrolConfirming.value = true;')
    && patrolRunSlice.includes("dailyWorkbenchPatrolConfirmationScope = String(confirmationScope || '');")
    && patrolRunSlice.includes('dailyWorkbenchPatrolConfirming.value = false;')
    && onlineDataTemplate.includes('@click="runDailyWorkbenchPatrol"')
    && onlineDataTemplate.includes('v-if="dailyWorkbenchPatrolConfirming"')
    && onlineDataTemplate.includes('dailyWorkbenchWriteBoundary.run.confirmText')
    && onlineDataTemplate.includes('@click="cancelDailyWorkbenchPatrolConfirmation"'),
  'requires visible run confirmation, cancellation and re-confirmation after a hotel/date change');
const exportConfirmationStart = patrolExportSlice.indexOf('const confirmation = await openWorkflowFormDialog({');
const exportCancellation = patrolExportSlice.indexOf('if (confirmation === null) return;');
const exportFetch = patrolExportSlice.indexOf('await fetch(API_BASE +');
check('public/app-main.js', 'patrol report export requires explicit confirmation before the authenticated download',
  exportConfirmationStart >= 0 && exportCancellation > exportConfirmationStart && exportFetch > exportCancellation
    && patrolExportSlice.slice(exportConfirmationStart, exportCancellation)
      .includes('description: dailyWorkbenchWriteBoundary.value.export.confirmText'),
  'requires confirmation text and cancellation before fetching the report');
includesAll('public/app-main.js', 'patrol report export uses the API base and validates the authenticated markdown response', frontendEntry, [
  "const API_BASE = '/api'",
]);
includesAll('public/app-main.js', 'patrol report export preserves same-session response and download guards', patrolExportSlice, [
  'fetch(API_BASE + `/online-data/daily-workbench-patrols/report?${params.toString()}`',
  'const requestSession = captureAuthSession();',
  'Authorization: requestSession.token',
  "Accept: 'text/markdown'",
  'if (!isAuthSessionCurrent(requestSession) || !isCurrentExportScope()) return;',
  "if (!contentType.includes('text/markdown'))",
  'downloadBlob(blob, filename)',
]);

includesAll('app/controller/concern/OperationWorkbenchConcern.php', 'daily workbench write responses disclose exact side effects', controller, [
  "'runtime_snapshot_written' => true",
  "'latest_index_written' => true",
  "'operation_log_written' => true",
  "'ota_collection_triggered' => false",
  "'business_table_written' => false",
  "'X-SUXIOS-Operation-Log-Written' => 'true'",
]);

check('public/app-main.js', 'focused data health refresh hydrates the one-page operating loop',
  /jobs\.push\(refreshCoreOperationsLoop\(\{[^}]*\bincludeDailyWorkbench\s*:\s*false\b[^}]*\}\)\)/.test(executableJs(dataHealthRefreshSlice)),
  'requires the core operating loop refresh job with daily workbench recursion disabled');

excludesAll('public/index.html', 'daily workbench frontend panel does not expose collection actions', frontendPanelSlice + frontendLoaderSlice, [
  '/online-data/fetch-ctrip',
  '/online-data/fetch-meituan',
  '/online-data/capture-ctrip-browser',
  '/online-data/capture-meituan-browser',
  '/online-data/save-daily-data',
  '/online-data/data-import',
  '/online-data/save-cookies',
  '/online-data/update-data',
  '/online-data/delete-data',
]);

includesAll('app/controller/concern/CookieEndpointConcern.php', 'legacy Cookie write and detail endpoints fail closed while list metadata is empty', cookieEndpointConcern, [
  'public function saveCookies(): Response',
  'Legacy Cookie storage is disabled.',
  'public function getCookiesList(): Response',
  'return $this->success([]);',
  'public function getCookiesDetail(): Response',
  'Legacy Cookie detail access is disabled.',
  'public function deleteCookies(): Response',
  'Legacy Cookie deletion is disabled.',
  'public function batchDeleteCookies(): Response',
  'Legacy Cookie batch deletion is disabled.',
  '410',
]);

excludesAll('public/index.html', 'frontend never calls legacy Cookie storage or plaintext-detail endpoints', frontend, [
  '/online-data/save-cookies',
  '/online-data/cookies-list',
  '/online-data/cookies-detail',
  '/online-data/delete-cookies',
  '/online-data/batch-delete-cookies',
]);

includesAll('public/index.html', 'legacy Cookie UI redirects operators to platform credential sources', frontend, [
  "cookiesList.value = [];",
  "throw new Error('旧 Cookie 明文详情已停用，请在平台采集源中更换凭据')",
  "showToast('旧 Cookie 保存已停用，请在平台采集源中更换凭据', 'warning')",
  'openPlatformSourcesTab();',
]);

includesAll('tests/automation/suxi_full_automation_test.mjs', 'full automation exits non-zero on blocking verification failures', fullAutomation, [
  "status: blockingFailureCount > 0 ? 'failed'",
  'return blockingFailureCount === 0;',
  'const passed = summarize();',
  'if (!passed) {',
  'process.exitCode = 1;',
]);

const failures = checks.filter((item) => !item.ok);
if (failures.length > 0) {
  console.error('phase2 daily workbench contract failed');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}${failure.detail ? ` (${failure.detail})` : ''}`);
  }
  process.exit(1);
}

console.log(`phase2 daily workbench contract passed (${checks.length} checks)`);
