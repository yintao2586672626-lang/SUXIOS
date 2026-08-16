import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relative => readFileSync(path.join(repoRoot, relative), 'utf8');
const service = read('app/service/DualOtaContinuousTrustService.php');
const pageVerificationService = read('app/service/DualOtaPageVerificationService.php');
const operationLog = read('app/model/OperationLog.php');
const controller = read('app/controller/concern/CollectionReliabilityConcern.php');
const routes = read('route/app.php');
const command = read('app/command/AutoFetchOnlineData.php');
const cloud = read('app/service/CloudAutomationService.php');
const app = readAppMainContractSource();
const template = read('resources/frontend/templates/fragments/35-page-online-data.html');

test('continuous trust contract requires the exact eight-step dual-OTA loop', () => {
  for (const step of ['source', 'hotel', 'date', 'field_facts', 'save', 'readback', 'page_status', 'p0']) {
    assert.match(service, new RegExp(`'${step}'`));
  }
  assert.match(service, /private const PLATFORMS = \['ctrip', 'meituan'\]/);
  assert.match(service, /\$readbackReady = \$hasReadbackColumn/);
  assert.match(service, /'page_status' => 'field_fact_projection_contract'/);
  assert.match(service, /'live_page_verification_status' => 'not_evaluated'/);
  assert.match(service, /it does not claim that a live browser render was observed/);
  assert.match(service, /'collection_failed'/);
  assert.match(service, /consecutive_verified_days/);
  assert.match(controller, /'dual_ota_continuous_trust' => \$this->buildDualOtaContinuousTrust/);
});

test('scheduler and cloud report gate reject old or incomplete receipts', () => {
  assert.match(command, /machineReceiptDailyTrustReady/);
  assert.match(command, /cached receipt is incomplete, recollection remains due/);
  assert.match(command, /p0_status/);
  assert.match(cloud, /applyContinuousTrustGate/);
  assert.match(cloud, /dual_ota_collection_failed/);
  assert.match(cloud, /\$health\['can_generate_report'\] = false/);
});

test('page targets the selected date and exposes verified blocked partial or unverified acceptance state', () => {
  assert.match(app, /params\.append\('end_date', targetDate\)/);
  assert.match(app, /dualOtaContinuousStatusText/);
  assert.match(app, /\['verified', 'blocked', 'partial', 'unverified'\]/);
  assert.match(app, /page_status: '页面字段就绪'/);
  assert.match(template, /data-testid="dual-ota-continuous-trust"/);
  assert.match(template, /data-testid="dual-ota-current-target-date"/);
  assert.match(template, /data-testid="dual-ota-continuous-history"/);
  assert.match(template, /v-for="day in dualOtaCurrentTargetDays"/);
  assert.match(template, /dualOtaContinuousStatusClass\(dualOtaCurrentAcceptanceStatus\)/);
  assert.doesNotMatch(template, /v-for="day in dualOtaContinuousDays\.slice/);
  assert.match(template, /<dual-ota-acceptance-receipt/);
  const acceptanceSurface = `${template}\n${app}`;
  for (const marker of [
    'dual-ota-acceptance-card-',
    'dual-ota-complete-fields-',
    'dual-ota-missing-fields-',
    'dual-ota-run-readback-scope-',
  ]) {
    assert.match(acceptanceSurface, new RegExp(marker));
  }
  assert.match(app, /'data-testid': `dual-ota-\$\{key\}-\$\{platform\}`/);
  assert.match(app, /cell\('platform-hotel'/);
  assert.match(app, /cell\('task-counts'/);
  assert.match(app, /cell\('target-counts'/);
  assert.match(app, /receipt_identity_mismatch_count/);
  assert.match(app, /当前身份一致/);
  assert.match(app, /身份漂移/);
  assert.match(app, /if \(!Array\.isArray\(value\)\) return '未返回'/);
  assert.match(app, /if \(value\.length === 0\) return '无'/);
  assert.match(app, /return normalized\.length \? normalized\.join\('、'\) : '未返回'/);
  assert.match(app, /critical_fields_present: Array\.isArray\(receipt\?\.critical_fields\?\.complete\)/);
  assert.match(app, /Array\.isArray\(receipt\?\.critical_fields\?\.missing\)/);
  assert.match(app, /row\.critical_fields_present === true/);
  assert.match(pageVerificationService, /!is_array\(\$fields\['complete'\] \?\? null\)/);
  assert.match(pageVerificationService, /!is_array\(\$fields\['missing'\] \?\? null\)/);
  assert.match(pageVerificationService, /missing critical-field evidence/);
  assert.match(template, /旧数据、空值或数值 0 不替代缺失证据/);

  const start = template.indexOf('data-testid="dual-ota-continuous-trust"');
  const end = template.indexOf('data-testid="core-operations-loop"', start);
  const panel = template.slice(start, end);
  assert.doesNotMatch(panel, /\|\|\s*0\b/);
});

test('receipt truth uses latest exact task id, aggregate Ctrip task rows, and exact tenant raw-save scope', () => {
  assert.match(service, /\(int\)\(\$right\['id'\] \?\? 0\) <=> \(int\)\(\$left\['id'\] \?\? 0\)/);
  assert.match(service, /\$uiStatusReady = \$platform === 'ctrip'/);
  assert.match(service, /\(int\)\(\$record\['tenant_id'\] \?\? 0\) === \$tenantId/);
});

test('backend projects one exact-task acceptance receipt without exposing profile secrets', () => {
  for (const marker of [
    "'acceptance_status'",
    "'acceptance_receipt'",
    "'platform_hotel_id'",
    "'target_date_status'",
    "'saved_readback_match'",
    "'target_saved_readback_match'",
    "'critical_fields'",
    "'claim_allowed'",
    "'live_page_verification_status' => 'not_evaluated'",
  ]) {
    assert.ok(service.includes(marker), `missing backend receipt marker: ${marker}`);
  }
  assert.match(service, /CollectionResultContractService\(\)/);
  assert.match(service, /Counts absent from the task remain null/);
  const receiptStart = service.indexOf('private static function buildAcceptanceReceipt');
  const receiptEnd = service.indexOf('private static function nullableTaskCount', receiptStart);
  const receiptProjector = service.slice(receiptStart, receiptEnd);
  assert.ok(receiptStart > 0 && receiptEnd > receiptStart);
  assert.doesNotMatch(receiptProjector, /'profile_key_hash'\s*=>/);
});

test('core-loop refresh reloads continuous trust with the same explicit hotel and target date', () => {
  assert.match(app, /loadCollectionReliability\('light', \{[\s\S]*hotelId,[\s\S]*targetDate,[\s\S]*force: options\.forceCollectionReliability === true/);
  assert.match(app, /options\?\.hotelId \|\| getAutoFetchHotelId\(\)/);
  assert.match(app, /options\?\.targetDate \|\| coreOperationsTargetDate\.value/);
  assert.match(app, /targetDate !== String\(coreOperationsTargetDate\.value \|\| coreOperationsMaxDate\)\.trim\(\)/);
  assert.match(app, /data_date: targetDate,[\s\S]*target_date: targetDate/);
  assert.match(app, /params\.append\('force', '1'\)/);
  assert.match(app, /refreshCoreOperationsLoop\(\{ hotelId, targetDate, forceCollectionReliability: true \}\)/);
});

test('core-loop success is gated by both exact current-run acceptance receipts', () => {
  const start = app.indexOf('const coreOperationsDualOtaAcceptanceOutcome');
  const end = app.indexOf('const finalizeCoreOperationsSourceFetchReadback', start);
  const gate = app.slice(start, end);
  assert.ok(start > 0 && end > start);
  assert.match(gate, /receipt\?\.claim_allowed === true/);
  assert.match(gate, /counts\.saved_readback_match === true/);
  assert.match(gate, /counts\.target_saved_readback_match === true/);
  assert.match(gate, /Number\(receipt\?\.sync_task_id \|\| 0\) === Number\(runRow\.syncTaskId\)/);
  assert.match(gate, /Number\(receipt\?\.data_source_id \|\| 0\) === Number\(runRow\.dataSourceId\)/);
  assert.match(app, /verifiedNewWrites = verifiedPlatforms === 2[\s\S]*dualOtaAcceptance\.allVerified[\s\S]*strictBackendSucceeded[\s\S]*allPlatformsWrote/);
  assert.match(app, /status: dualOtaAcceptance\.hasBlocked \? 'blocked'/);
});

test('page verification requires an explicit user click and exact persisted readback', () => {
  assert.match(routes, /Route::post\('\/dual-ota-page-verification', 'OnlineData\/confirmDualOtaPageVerification'\)/);
  assert.match(controller, /confirmDualOtaPageVerification\(\)/);
  assert.match(controller, /checkHotelActionPermission\(\(int\)\$hotelId, 'can_view_online_data'\)/);
  assert.match(controller, /DualOtaPageVerificationService\(\)/);
  assert.match(controller, /HotelCollectionRunReceiptService\(\)/);
  assert.match(controller, /->recordPageAcceptance\(/);
  assert.match(controller, /'collection_run_attachment' => \$collectionRunAttachment/);
  assert.match(controller, /'collection_run_receipt' => \$collectionRunReceipt/);
  assert.match(controller, /hotel_collection_page_run_attachment_failed/);
  assert.match(controller, /\$collectionRunReceipt\['ledger_structure_verified'\]/);
  assert.match(controller, /\$collectionRunReceipt\['readback_verified'\]/);
  assert.match(controller, /\$collectionRunReceipt\['page_acceptance'\]\['readback_verified'\]/);
  assert.match(controller, /force-read|force-read it|cache\(\$this->collectionReliabilityCacheKey/);

  assert.match(pageVerificationService, /suxios\.dual_ota_page_verification\.v2/);
  assert.match(pageVerificationService, /'run_readback_scope'/);
  assert.match(pageVerificationService, /'p0_status'/);
  assert.match(pageVerificationService, /'missing_metric_keys'/);
  assert.match(pageVerificationService, /'failure_reason_digest'/);
  assert.match(pageVerificationService, /Db::transaction/);
  assert.match(pageVerificationService, /->lock\(true\)/);
  assert.match(pageVerificationService, /OperationLog::record/);
  assert.match(pageVerificationService, /'contract_hash'/);
  assert.doesNotMatch(pageVerificationService, /'verification_key'/);
  assert.match(pageVerificationService, /stale_page_confirmation/);
  assert.match(pageVerificationService, /invalid_page_confirmation_evidence/);
  assert.match(pageVerificationService, /page_confirmation_evidence_unavailable/);
  assert.match(operationLog, /isPrevalidatedSuperAdminPageVerificationAudit/);
  assert.match(operationLog, /authoritativeTenantId === \$explicitTenantId/);
  assert.match(pageVerificationService, /never promotes the[\s\S]*acceptance_status or claim_allowed/);

  const pageSurface = `${template}\n${app}`;
  assert.match(template, /<dual-ota-page-verification-panel/);
  assert.match(template, /@confirm="confirmDualOtaPageVerification"/);
  assert.match(pageSurface, /dual-ota-confirm-page-verification/);
  assert.match(app, /request\('\/online-data\/dual-ota-page-verification', \{/);
  assert.match(app, /body: JSON\.stringify\(\{[\s\S]*system_hotel_id:[\s\S]*target_date:[\s\S]*contract_hash:[\s\S]*platforms/);
  assert.match(app, /loadCollectionReliability\('light', \{[\s\S]*force: true/);

  const loadStart = app.indexOf('const loadCollectionReliability');
  const loadEnd = app.indexOf('const loadDailyWorkbench', loadStart);
  const loader = app.slice(loadStart, loadEnd);
  assert.ok(loadStart > 0 && loadEnd > loadStart);
  assert.doesNotMatch(loader, /confirmDualOtaPageVerification\(\)/);
  assert.match(loader, /return \{ ok: true, payload, hotelId, targetDate \}/);

  const confirmStart = app.indexOf('const confirmDualOtaPageVerification');
  const confirmEnd = app.indexOf('const loadDailyWorkbench', confirmStart);
  const confirm = app.slice(confirmStart, confirmEnd);
  assert.ok(confirmStart > 0 && confirmEnd > confirmStart);
  assert.match(confirm, /const freshReadback = await loadCollectionReliability/);
  assert.match(confirm, /freshReadback\?\.ok === true/);
  assert.match(confirm, /freshContractHash === contractHash/);
  assert.match(confirm, /freshReceiptId === savedReceiptId/);
  assert.match(confirm, /collectionRunAttachment\.status \|\| ''/);
  assert.match(confirm, /collectionRunAttachment\.readback_verified === true/);
  assert.match(confirm, /collectionRunReceipt\.ledger_structure_verified === true/);
  assert.match(confirm, /collectionRunReceipt\.readback_verified === true/);
  assert.match(confirm, /Number\(collectionRunReceipt\.system_hotel_id \|\| 0\) === hotelId/);
  assert.match(confirm, /String\(collectionRunReceipt\.business_date \|\| ''\)\.trim\(\) === targetDate/);
  assert.match(confirm, /String\(runPageAcceptance\.status \|\| ''\)[\s\S]*=== 'verified'/);
  assert.match(confirm, /runPageAcceptance\.readback_verified === true/);
  assert.match(confirm, /页面回执已保存并回读，但未附着本次运行记录/);
  assert.match(confirm, /showToast\(partialMessage, 'warning'\)/);
  const attachmentGateAt = confirm.indexOf('if (!collectionRunAttached)');
  const successToastAt = confirm.indexOf("showToast('当前双 OTA 页面已核对，精确回读通过', 'success')");
  assert.ok(attachmentGateAt > 0 && successToastAt > attachmentGateAt);
  assert.doesNotMatch(confirm, /dualOtaPageVerificationStatus\.value/);
});

test('page exposes stable scope controls and normalizes preflight login expiry to blocked', () => {
  assert.match(template, /data-testid="core-loop-hotel"/);
  assert.match(template, /data-testid="core-loop-target-date"/);
  assert.match(template, /@click="refreshCoreOperationsLoop\(\)"/);
  assert.match(template, /coreOperationsSourceFetchDisplayStatus/);
  assert.match(app, /\['login_required', 'blocked', 'failed'\]\.includes\(status\)\) return 'blocked'/);
  assert.match(app, /\['login_required', 'blocked', 'failed'\]\.includes\(status\)\) return 'border-red-200/);
});
