import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const fragment = read('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html');
const hotelDialog = read('resources/frontend/templates/fragments/40-dialog-hotel.html');
const operatingTargetFragment = read('resources/frontend/templates/fragments/15aa-page-operating-targets.html');
const appMain = read('public/app-main.js');
const routes = read('route/app.php');
const service = read('app/service/DingdandaoPmsIntegrationService.php');
const targetSyncService = read('app/service/DingdandaoOperatingTargetSyncService.php');
const runner = read('scripts/run_dingdandao_cloud_collection.php');
const controller = read('app/controller/OperatingTarget.php');

test('PMS operating data page shows only the hotel-selected PMS while binding lives in hotel management', () => {
  assert.match(fragment, /currentPage === 'pms-operating-data'/);
  assert.match(fragment, /data-testid="pms-selected-source"/);
  assert.match(fragment, /当前门店唯一 PMS/);
  assert.match(fragment, /data-testid="pms-selected-source-metrics"/);
  assert.match(fragment, /data-testid="pms-selected-source-blockers"/);
  assert.match(fragment, /前往门店管理配置/);
  assert.doesNotMatch(fragment, /data-testid="dingdandao-pms-integration"/);
  assert.doesNotMatch(fragment, /data-testid="dingdandao-pms-robot"/);
  assert.doesNotMatch(fragment, /data-testid="dingdandao-pms-auto-push"/);
  assert.doesNotMatch(fragment, /推送当前已验证数据/);
  assert.doesNotMatch(fragment, /企业微信[^<\n]{0,20}Webhook[^<\n]{0,20}<input/);
  assert.match(hotelDialog, /data-testid="hotel-pms-configuration"/);
  assert.match(hotelDialog, /data-testid="hotel-pms-provider"/);
  assert.match(hotelDialog, /aria-label="当前使用的 PMS"/);
  assert.match(hotelDialog, /value="dingdandao_pms"/);
  assert.doesNotMatch(hotelDialog, /一家门店只启用一套 PMS/);
  assert.doesNotMatch(hotelDialog, /hotelPmsBinding\.binding_status_label/);
  assert.doesNotMatch(hotelDialog, /data-testid="hotel-pms-provider-hotel-id"/);
  assert.doesNotMatch(hotelDialog, /data-testid="hotel-pms-provider-hotel-name"/);
  assert.doesNotMatch(hotelDialog, /切换 PMS 会停用另一来源/);
  assert.match(hotelDialog, />最近登录<\/div>/);
  assert.doesNotMatch(hotelDialog, />最近采集<\/div>/);
  assert.doesNotMatch(hotelDialog, />可用模块<\/div>/);
  assert.doesNotMatch(hotelDialog, /\{\{ account\.reasonText \}\}/);
  assert.doesNotMatch(hotelDialog, /hotelPlatformBlockingIssueText\(account\)/);
  assert.doesNotMatch(operatingTargetFragment, /data-testid="dingdandao-pms-integration"/);
});

test('frontend saves and reads the integration then requires confirmation for real push', () => {
  assert.match(appMain, /\/operating-targets\/pms\/dingdandao\/integration/);
  assert.match(appMain, /\/operating-targets\/pms\/dingdandao\/push/);
  assert.match(appMain, /confirmed:\s*true/);
  assert.match(appMain, /dingdandaoPmsPushReady/);
  assert.match(appMain, /window\.confirm\(/);
});

test('PMS sync preserves the selected business date and labels historical recovery truthfully', () => {
  const syncMethod = appMain.slice(
    appMain.indexOf('const syncOperatingPmsRealtime'),
    appMain.indexOf('const prefillOperatingTargetFromDailyReport'),
  );
  assert.match(appMain, /补采所选业务日 PMS/);
  assert.match(fragment, /正在获取并核验/);
  assert.match(syncMethod, /target_date:\s*context\.targetDate/);
  assert.doesNotMatch(
    syncMethod,
    /operatingTargetForm\.value\.target_date\s*=\s*operationToday/,
  );
});

test('backend reuses the existing WeCom sender behind verified and idempotent gates', () => {
  assert.match(routes, /pms\/dingdandao\/integration/);
  assert.match(routes, /pms\/dingdandao\/push/);
  assert.match(service, /new WechatRobotDeliveryService\(\)/);
  assert.match(service, /->deliverToPlanRobot\(\s*\$tenantId,\s*\$hotelId,\s*\$expectedRobotId,\s*\$expectedRobotName,\s*0,\s*'formal'/);
  const durableClaim = service.slice(
    service.indexOf('private function claimDispatchDurably'),
    service.indexOf('private function deliverUnderLockedPolicy'),
  );
  const lockedPolicy = service.slice(
    service.indexOf('private function deliverUnderLockedPolicy'),
    service.indexOf('private function markClaimOutcomeUnknown'),
  );
  assert.match(durableClaim, /return Db::transaction/);
  assert.match(durableClaim, /'delivery_status'\s*=>\s*'pending'/);
  assert.match(lockedPolicy, /return Db::transaction/);
  const integrationLock = lockedPolicy.indexOf('Db::name(self::INTEGRATION_TABLE)');
  const robotLock = lockedPolicy.indexOf("Db::name('competitor_wechat_robot')");
  const finalSender = lockedPolicy.indexOf('->deliverToPlanRobot(');
  assert.ok(integrationLock >= 0);
  assert.ok(robotLock > integrationLock);
  assert.ok(finalSender > robotLock);
  assert.ok(lockedPolicy.indexOf("'delivery_status' => $deliveryStatus") > finalSender);
  assert.match(lockedPolicy, /lockedRobotMatchesSharedScope\(\$lockedRobot,\s*\$tenantId,\s*\$hotelId\)/);
  assert.match(service, /readback_status'\s*=>\s*'readback_verified'/);
  assert.match(service, /captureCanAutofillProviderId/);
  assert.match(service, /dynamic_fields_excluded/);
  assert.doesNotMatch(service, /ctrip_public_profile/);
  assert.doesNotMatch(service, /latestCtripSelfProfile/);
  assert.match(service, /pms_wecom_robot_test_required/);
  assert.match(service, /uq_dingdandao_pms_capture_robot|integration_id.*capture_id.*robot_id/s);
  assert.doesNotMatch(service, /curl_init|file_get_contents\(\$url/);
});

test('cloud collection attempts orchestration only after verified save and database readback', () => {
  const readbackIndex = runner.indexOf('dingdandao_collection_prefill_readback_failed');
  const syncIndex = runner.indexOf('->syncVerifiedCapture(');
  const dispatchIndex = runner.indexOf('dispatchVerifiedCapture');
  assert.ok(readbackIndex >= 0);
  assert.ok(syncIndex > readbackIndex);
  assert.ok(dispatchIndex > syncIndex);
  assert.match(runner, /\$integrationService->prefill\(/);
  assert.match(runner, /push_orchestration/);
  assert.match(runner, /message_sent/);
});

test('Dingdandao prefill and target sync stay behind the current PMS identity binding', () => {
  const syncMethod = service.slice(
    service.indexOf('public function syncVerifiedCapture'),
    service.indexOf('public function dispatchVerifiedCapture'),
  );
  assert.match(service, /public function prefill\(/);
  assert.match(service, /private function factGate\(/);
  assert.match(syncMethod, /DingdandaoOperatingTargetSyncService/);
  assert.match(targetSyncService, /return Db::transaction/);
  assert.match(targetSyncService, /factGateForCapture\(/);
  assert.match(targetSyncService, /true\s*\)/);

  const prefillMethod = controller.slice(
    controller.indexOf('public function prefillDingdandao'),
    controller.indexOf('public function saveDingdandaoCapture'),
  );
  assert.match(prefillMethod, /new DingdandaoPmsIntegrationService/);

  const manualPushMethod = controller.slice(
    controller.indexOf('public function pushDingdandaoVerifiedCapture'),
    controller.indexOf('public function save(): Response'),
  );
  const gateIndex = manualPushMethod.indexOf('->prefill(');
  const targetSyncIndex = manualPushMethod.indexOf('->syncVerifiedCapture(');
  const sendIndex = manualPushMethod.indexOf('->dispatchVerifiedCapture(');
  assert.ok(gateIndex >= 0);
  assert.ok(targetSyncIndex > gateIndex);
  assert.ok(sendIndex > targetSyncIndex);
});
