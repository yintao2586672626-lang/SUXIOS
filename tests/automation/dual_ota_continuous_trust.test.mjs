import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = relative => readFileSync(path.join(repoRoot, relative), 'utf8');
const service = read('app/service/DualOtaContinuousTrustService.php');
const controller = read('app/controller/concern/CollectionReliabilityConcern.php');
const command = read('app/command/AutoFetchOnlineData.php');
const cloud = read('app/service/CloudAutomationService.php');
const app = read('public/app-main.js');
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
  ]) {
    assert.match(acceptanceSurface, new RegExp(marker));
  }
  assert.match(app, /'data-testid': `dual-ota-\$\{key\}-\$\{platform\}`/);
  assert.match(app, /cell\('platform-hotel'/);
  assert.match(app, /cell\('task-counts'/);
  assert.match(app, /cell\('target-counts'/);
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
