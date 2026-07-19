import test from 'node:test';
import assert from 'node:assert/strict';
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
  assert.match(template, /ctrip-public-profile-hotel-select[^>]*:disabled="ctripPublicProfileLoading \|\| ctripPublicProfileSaving \|\| ctripPublicProfileRefreshing"/);
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
  assert.match(template, /数据库回读与 OTA 来源验证分开显示/);
  assert.match(template, /诊断评分/);
  assert.match(template, /来源已验证/);
  assert.match(template, /不计算/);
  assert.match(template, /data-testid="ota-public-page-create-task"/);
  assert.match(template, /一键转任务/);
  assert.match(template, /只创建待审批的运营任务草稿/);
  assert.match(template, /data-testid="ota-public-page-task-readback"/);
  assert.match(template, /data-testid="ota-public-page-task-schedule"/);
  assert.match(source, /const otaPublicPageDiagnosisTaskSchedule = ref/);
  assert.match(source, /const otaPublicPageDiagnosisTaskScheduleText = computed/);
  assert.match(source, /due_at: otaPublicPageTaskDateTime\(1, 18\)/);
  assert.match(source, /review_at: otaPublicPageTaskDateTime\(2, 10\)/);
  assert.match(source, /const createOtaPublicPageDiagnosisExecutionIntent = async/);
  assert.match(source, /request\('\/online-data\/public-page-diagnosis\/execution-intent'/);
  assert.match(source, /const persistedIntent = res\.data\.execution_intent/);
  assert.match(source, /execution_intent_readback_status \|\| ''\) !== 'readback_verified'/);
  assert.match(source, /operation_surface_accessible === true/);
  assert.match(template, /重新回读任务/);
  assert.match(source, /persistedIntent\.source_module \|\| ''\) !== 'ota_diagnosis'/);
  assert.match(source, /const intentStatus = String\(persistedIntent\?\.status \|\| ''\)/);
  assert.match(source, /existing_schedule_preserved/);
  assert.match(source, /已打开现有运营记录/);
  assert.match(source, /currentPage\.value = 'ops-track'/);
  assert.match(source, /assignee_id: assigneeId/);
  assert.match(source, /due_at: dueAt/);
  assert.match(source, /review_at: reviewAt/);
});

test('public-page diagnosis task bridge rebuilds server evidence and enforces operation scope', () => {
  const routes = read('route/app.php');
  const concern = read('app/controller/concern/CtripCompetitiveOperationsConcern.php');
  const service = read('app/service/OtaPublicPageDiagnosisService.php');

  assert.match(routes, /Route::post\('\/public-page-diagnosis\/execution-intent', 'OnlineData\/createOtaPublicPageDiagnosisExecutionIntent'\)/);
  assert.match(concern, /hasHotelPermission\(\$systemHotelId, 'operation\.execute'\)/);
  assert.match(concern, /\$diagnosisService->build\(\$systemHotelId, \$platform, \$businessDate, \$profiles\)/);
  assert.match(concern, /\$diagnosisService->buildExecutionIntentDraft\(\$diagnosis, \$schedule\)/);
  assert.match(concern, /hasHotelPermission\(\$systemHotelId, 'operation\.execute'\)/);
  assert.match(concern, /'assignment_readback_status' => \$assignmentReadbackStatus/);
  assert.match(concern, /'execution_intent_is_pending_approval' => \$intentStatus === 'pending_approval'/);
  assert.match(concern, /ProtectedCapabilityService/);
  assert.match(concern, /'operation_surface_accessible' =>/);
  assert.match(concern, /new OperationManagementService\(\)\)->createExecutionIntent/);
  assert.match(concern, /'execution_intent_readback_status' => 'readback_verified'/);
  assert.match(service, /'object_type' => 'data_collection'/);
  assert.match(service, /'source_policy' =>/);
  assert.match(service, /no_default_score_no_ota_write/);
  assert.match(service, /'workflow_schedule' => \$workflowSchedule/);
  assert.match(service, /'diagnosis_fingerprint' => \$diagnosisFingerprint/);
  assert.match(service, /'action_contract' => 'ota_public_page_evidence_v1'/);
  assert.match(service, /ota_diagnosis_action_' \. substr\(\$idempotencyIdentity, 0, 32\) \. ':attempt:1/);
});
