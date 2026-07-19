import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relativePath => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

test('Ctrip public profile UI exposes ID-only add and truthful static-data boundary', () => {
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const panel = template.split('<!-- 携程公开酒店档案 -->')[1]?.split('<!-- 流量概要 -->')[0] || '';

  assert.match(template, /data-testid="ctrip-public-profile-tab"/);
  assert.match(panel, /data-testid="ctrip-public-profile-panel"/);
  assert.match(panel, /data-testid="ctrip-public-profile-id-input"/);
  assert.match(panel, /data-testid="ctrip-public-profile-add"/);
  assert.match(panel, /添加并自动补全/);
  assert.match(panel, /<option value="self">本店<\/option>/);
  assert.match(panel, /<option value="competitor">竞品酒店<\/option>/);
  assert.match(panel, /客房总数是酒店静态资料，不等于任一日期的可售库存/);
  assert.match(panel, /不采集动态价格、订单、流量或 PMS 数据/);
  assert.doesNotMatch(panel, /Cookie|spidertoken|Authorization/);
});

test('Ctrip public profile UI calls scoped add, read, and refresh endpoints', () => {
  const source = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');

  assert.match(source, /const normalizeCtripPublicHotelIdInput =/);
  assert.match(source, /hostname === 'hotels\.ctrip\.com'/);
  assert.match(source, /\/online-data\/ctrip\/public-profiles\?system_hotel_id=/);
  assert.match(source, /request\('\/online-data\/ctrip\/public-profiles\/add'/);
  assert.match(source, /request\('\/online-data\/ctrip\/public-profiles\/sync'/);
  assert.match(source, /role,\s+replace,/);
  assert.match(source, /businessContext: \{ hotelId: systemHotelId, platform: 'ctrip' \}/);
  assert.match(source, /binding_saved_collection_failed/);
  assert.match(source, /mutationSeq !== ctripPublicProfileMutationSeq[\s\S]*systemHotelId !== String\(selectedCtripHotelId\.value/);
  assert.match(template, /ctrip-public-profile-hotel-select[^>]*:disabled="ctripPublicProfileLoading \|\| ctripPublicProfileSaving \|\| ctripPublicProfileRefreshing[^"]*"/);
});

test('Ctrip competition-circle operations are reachable from the public-profile page with truthful evidence states', () => {
  const source = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const panel = template.split('<!-- 携程公开酒店档案 -->')[1]?.split('<!-- 流量概要 -->')[0] || '';

  assert.match(panel, /data-testid="ctrip-competitive-operations-panel"/);
  assert.match(panel, /data-testid="ctrip-competitive-operations-refresh"/);
  assert.match(panel, /携程 OTA 竞争圈口径/);
  assert.match(panel, /v-for="card in ctripCompetitiveOperationsCoverageCards"/);
  assert.match(source, /label: '可用于诊断'/);
  assert.match(source, /label: '排除记录'/);
  assert.match(source, /const loadCtripCompetitiveOperations = async/);
  assert.match(source, /\/online-data\/ctrip\/competitive-operations\?system_hotel_id=/);
  assert.match(source, /start_date=\$\{encodeURIComponent\(startDate\)\}/);
  assert.match(source, /end_date=\$\{encodeURIComponent\(endDate\)\}/);
  assert.match(source, /businessContext: \{ hotelId: systemHotelId, platform: 'ctrip' \}/);
  assert.match(source, /ctripCompetitiveOperationsStatusText/);
});

test('ID-only backend route persists a dedicated public binding and retains competitors for refresh', () => {
  const routes = read('route/app.php');
  const concern = read('app/controller/concern/CtripCompetitiveOperationsConcern.php');
  const service = read('app/service/CtripPublicHotelProfileService.php');

  assert.match(routes, /Route::post\('\/ctrip\/public-profiles\/add', 'OnlineData\/addCtripPublicProfile'\)/);
  assert.match(concern, /checkActionPermission\('can_fetch_online_data'\)/);
  assert.match(concern, /currentUserCanMaintainOtaConfig\(\$systemHotelId\)/);
  assert.match(service, /PUBLIC_BINDING_CONFIG_KEY = 'ctrip_public_hotel_bindings'/);
  assert.match(service, /public function addByHotelId\(/);
  assert.match(service, /'binding_saved_collection_failed'/);
  assert.match(service, /Db::name\('competitor_hotel'\)/);
  assert.match(service, /'room_count_semantics' => self::ROOM_COUNT_SEMANTICS/);
  assert.match(service, /archived_self/);
  assert.match(service, /isAllowedFinalUrl\(\$finalUrl, \$otaHotelId\)/);
  assert.match(service, /if \(\$this->looksBlocked\(\$body\)\)/);
});

test('public-page diagnosis exposes platform/date controls and truthful twelve-dimensional evidence states', () => {
  const source = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
  const opsTrackTemplate = read('resources/frontend/templates/fragments/17-page-ops-track.html');
  const operationStatic = read('public/operation-static.js');

  assert.match(source, /\/online-data\/public-page-diagnosis\?system_hotel_id=/);
  assert.match(template, /data-testid="ota-public-page-diagnosis-panel"/);
  assert.match(template, /data-testid="ota-public-page-platform"/);
  assert.match(template, /data-testid="ota-public-page-business-date"/);
  assert.match(template, /data-testid="ota-public-page-diagnose"/);
  assert.match(template, /data-testid="ota-public-page-diagnosis-loading"/);
  assert.match(template, /data-testid="ota-public-page-diagnosis-error"/);
  assert.match(template, /data-testid="ota-public-page-diagnosis-empty"/);
  assert.match(template, /data-testid="ota-public-page-dimension"/);
  assert.match(template, /data-testid="ota-public-page-sources"/);
  assert.match(template, /保存回读 ≠ 来源验证/);
  assert.match(template, /来源已验证/);
  assert.match(template, /不评分/);
  assert.match(template, /data-testid="ota-public-page-create-task"/);
  assert.match(template, /\{\{ otaPublicPageDiagnosisTaskActionText \}\}/);
  assert.match(source, /return '创建待审批任务'/);
  assert.match(template, /待审批，不写 OTA，不代表全酒店/);
  assert.match(template, /data-testid="ota-public-page-task-readback"/);
  assert.match(template, /data-testid="ota-public-page-task-schedule"/);
  assert.match(source, /const otaPublicPageDiagnosisTaskSchedule = ref/);
  assert.match(source, /const otaPublicPageDiagnosisTaskScheduleText = computed/);
  assert.match(source, /otaPublicPageDiagnosisExecutionIntent\.value\?\.target_value\?\.workflow_schedule/);
  assert.match(source, /const otaPublicPageDiagnosisOperationSurfaceText = computed/);
  assert.match(source, /due_at: otaPublicPageTaskDateTime\(1, 18\)/);
  assert.match(source, /review_at: otaPublicPageTaskDateTime\(2, 10\)/);
  assert.match(source, /const createOtaPublicPageDiagnosisExecutionIntent = async/);
  assert.match(source, /request\('\/online-data\/public-page-diagnosis\/execution-intent'/);
  assert.match(source, /const applyOtaPublicPageDiagnosisTaskBridge = \(bridge, expectedScope\)/);
  assert.match(source, /applyOtaPublicPageDiagnosisTaskBridge\(res\.data\.task_bridge/);
  assert.match(source, /String\(bridge\.readback_status \|\| ''\) !== 'readback_verified'/);
  assert.match(source, /operationSurface\.accessible === true/);
  assert.match(source, /String\(operationSurface\.status \|\| 'operation_capability_unavailable'\)/);
  assert.match(source, /return '重新读取状态'/);
  assert.match(template, /otaPublicPageDiagnosisTaskBridgeNoticeText/);
  assert.match(template, /otaPublicPageDiagnosisTaskActionDisabled/);
  assert.match(template, /aria-live="polite"/);
  assert.match(source, /state: 'readback_mismatch'/);
  assert.match(source, /bridgeState === 'create_blocked'/);
  assert.match(source, /module_not_entitled: '当前租户未开通运营模块/);
  assert.match(source, /identity_version.*legacy_v1/);
  assert.match(source, /requestSeq !== otaPublicPageDiagnosisExecutionRequestSeq/);
  assert.match(source, /Number\(summary\?\.hotel_id \|\| 0\) !== expectedHotelId/);
  assert.match(source, /String\(summary\?\.platform \|\| ''\)\.toLowerCase\(\) !== expectedPlatform/);
  assert.match(source, /status: String\(summary\.approval_status \|\| ''\)/);
  assert.match(source, /已定位执行意图/);
  assert.match(source, /loadOperationActions\(\{ focusIntentId: intentId \}\)/);
  assert.match(source, /const operationExecutionAssignedToCurrentUser = \(item\) =>/);
  assert.match(source, /assigneeId === Number\(user\.value\?\.id \|\| 0\)/);
  assert.match(source, /currentPage\.value = 'ops-track'/);
  assert.match(source, /revenueAiExecutionFocus\.value = \{ intentId \}/);
  assert.match(source, /operationExecutionItems\.value\.some/);
  assert.match(source, /scrollIntoView/);
  assert.match(opsTrackTemplate, /data-operation-execution-intent-id/);
  assert.match(opsTrackTemplate, /任务 #\{\{ item\.id \}\}/);
  assert.match(operationStatic, /data_collection: '证据采集'/);
  assert.match(operationStatic, /complete_public_page_evidence: '补齐公开页证据'/);
  assert.match(operationStatic, /review_public_page_evidence: '复核公开页证据'/);
  const operationStaticHash = createHash('sha256').update(operationStatic).digest('hex').slice(0, 10);
  assert.match(source, new RegExp(`operationStaticScriptVersion = '[^']*-h${operationStaticHash}'`));
  assert.match(source, /assignee_id: assigneeId/);
  assert.match(source, /due_at: dueAt/);
  assert.match(source, /review_at: reviewAt/);
  assert.match(source, /return '调整排期并打开'/);
  assert.match(source, /return '重新创建待审批任务'/);
  assert.match(source, /const editOtaPublicPageDiagnosisTaskSchedule = async/);
  assert.match(template, /data-testid="ota-public-page-evidence-action"/);
  assert.match(source, /otaPublicPageDiagnosisLatestDate/);
  assert.match(source, /return refreshCtripPublicProfiles\(\)/);
  assert.match(source, /request\('\/online-data\/public-page-evidence'/);
  assert.match(source, /source_observed: '已观察（未验证）'/);
  assert.match(template, /otaPublicPageEvidenceStatusText\(source\.source_validation_status\)/);
});

test('public-page diagnosis task bridge rebuilds server evidence and enforces operation scope', () => {
  const routes = read('route/app.php');
  const concern = read('app/controller/concern/CtripCompetitiveOperationsConcern.php');
  const service = read('app/service/OtaPublicPageDiagnosisService.php');
  const operationService = read('app/service/OperationManagementService.php');
  const protectedCapabilities = read('app/service/ProtectedCapabilityService.php');

  assert.match(routes, /Route::post\('\/public-page-diagnosis\/execution-intent', 'OnlineData\/createOtaPublicPageDiagnosisExecutionIntent'\)/);
  assert.match(concern, /hasHotelPermission\(\$systemHotelId, 'operation\.execute'\)/);
  assert.match(concern, /\$diagnosisService->build\(\$systemHotelId, \$platform, \$businessDate, \$profiles\)/);
  assert.match(concern, /\$diagnosisService->buildExecutionIntentDraft\(\$diagnosis, \$schedule\)/);
  assert.match(concern, /hasHotelPermission\(\$systemHotelId, 'operation\.execute'\)/);
  assert.match(concern, /'assignment_readback_status' => \$assignmentReadbackStatus/);
  assert.match(concern, /'execution_intent_is_pending_approval' => \$intentStatus === 'pending_approval'/);
  assert.match(concern, /ProtectedCapabilityService/);
  assert.match(concern, /otaPublicPageOperationAuthorization\(\$systemHotelId\)/);
  assert.match(concern, /当前运营模块不可用，未创建任务/);
  assert.match(concern, /'task_bridge' => \$taskBridge/);
  assert.match(concern, /findOtaPublicPageExecutionIntent/);
  assert.match(concern, /otaPublicPageExecutionIntentMismatchFields/);
  assert.match(concern, /otaPublicPageExecutionLifecycle/);
  assert.match(concern, /\$operationService->createExecutionIntent\(/);
  assert.match(concern, /'execution_intent_readback_status' => 'readback_verified'/);
  assert.match(concern, /'existing_schedule_preserved'/);
  assert.match(concern, /'retry_performed' => \$retryPerformed/);
  assert.match(concern, /'schedule_updated' => \$scheduleUpdated/);
  assert.match(concern, /'retry_of_intent_id'/);
  assert.match(operationService, /public function readExecutionIntentByIdempotencyKey/);
  assert.match(operationService, /public function readLatestOtaDiagnosisExecutionIntentAttempt/);
  assert.match(operationService, /public function reschedulePendingExecutionIntent/);
  assert.match(protectedCapabilities, /public-page-diagnosis\/execution-intent/);
  assert.match(protectedCapabilities, /'permission' => 'operation\.view'/);
  assert.match(service, /'object_type' => 'data_collection'/);
  assert.match(service, /'source_policy' =>/);
  assert.match(service, /no_default_score_no_ota_write/);
  assert.match(service, /'workflow_schedule' => \$workflowSchedule/);
  assert.match(service, /'diagnosis_fingerprint' => \$diagnosisFingerprint/);
  assert.match(service, /PUBLIC_PAGE_SOURCE_RECORD_OFFSET = 4294967296/);
  assert.match(service, /substr\(\$taskIdentityFingerprint, 0, 13\)/);
  assert.match(service, /'task_identity_fingerprint' => \$taskIdentityFingerprint/);
  assert.match(service, /'full_evidence_fingerprint' => \$fullEvidenceFingerprint/);
  assert.match(service, /'action_contract' => 'ota_public_page_evidence_v3'/);
  assert.match(service, /'action_contract' => 'ota_public_page_evidence_v2'/);
  assert.match(service, /'action_contract' => 'ota_public_page_evidence_v1'/);
  assert.match(service, /ota_diagnosis_action_' \. substr\(\$idempotencyIdentity, 0, 32\) \. ':attempt:1/);
});

test('Meituan public-page evidence is a persisted manual consumer-page observation, not merchant data', () => {
  const routes = read('route/app.php');
  const concern = read('app/controller/concern/CtripCompetitiveOperationsConcern.php');
  const service = read('app/service/MeituanPublicPageEvidenceService.php');
  const protectedCapabilities = read('app/service/ProtectedCapabilityService.php');
  const template = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');

  assert.match(routes, /Route::post\('\/public-page-evidence', 'OnlineData\/saveOtaPublicPageEvidence'\)/);
  assert.match(concern, /new MeituanPublicPageEvidenceService\(\)/);
  assert.match(concern, /checkActionPermission\('can_fetch_online_data'\)/);
  assert.match(service, /SOURCE = 'meituan_public_page'/);
  assert.match(service, /source_observed/);
  assert.match(service, /online_daily_data#/);
  assert.match(service, /markRowsReadbackVerified/);
  assert.match(service, /ebooking\.meituan\.com/);
  assert.match(service, /must not contain credentials/);
  assert.doesNotMatch(service, /source_validation_status' => 'source_verified'/);
  assert.match(protectedCapabilities, /public-page-evidence/);
  assert.match(template, /不借用商家后台/);
});
