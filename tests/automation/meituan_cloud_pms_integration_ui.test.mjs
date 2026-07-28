import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const root = new URL('../../', import.meta.url);
const source = async (path) => readFile(new URL(path, root), 'utf8');

test('Meituan Cloud PMS is an independent real PMS source with save/readback UI', async () => {
  const [
    routes,
    controller,
    captureService,
    integrationService,
    reconciliationService,
    migration,
    pmsTemplate,
    targetTemplate,
    app,
    runner,
    collector,
    profileService,
    gateway,
    reconciliationReference,
  ] = await Promise.all([
    source('route/app.php'),
    source('app/controller/OperatingTarget.php'),
    source('app/service/MeituanCloudPmsCaptureService.php'),
    source('app/service/MeituanCloudPmsIntegrationService.php'),
    source('app/service/PmsFactReconciliationService.php'),
    source('database/migrations/20260728_create_meituan_cloud_pms_source.sql'),
    source('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html'),
    source('resources/frontend/templates/fragments/15aa-page-operating-targets.html'),
    source('public/app-main.js'),
    source('scripts/run_meituan_cloud_pms_collection.php'),
    source('scripts/meituan_cloud_pms_capture.mjs'),
    source('app/service/CloudBrowserProfileService.php'),
    source('deploy/remote-browser/cloud_browser_gateway.mjs'),
    source('docs/pms-independent-source-reconciliation.md'),
  ]);

  assert.match(routes, /\/pms\/meituan-cloud\/integration/);
  assert.match(routes, /\/pms\/meituan-cloud\/captures/);
  assert.match(routes, /\/prefill\/meituan-cloud/);
  assert.match(controller, /saveMeituanCloudIntegration/);
  assert.match(controller, /saveMeituanCloudCapture/);
  assert.match(controller, /prefillMeituanCloud/);

  assert.match(captureService, /public const PROVIDER = 'meituan_cloud_pms'/);
  assert.match(captureService, /meituan_cloud_trusted_collection_required/);
  assert.match(captureService, /availability_tolerance/);
  assert.match(captureService, /readback_verified/);
  assert.match(captureService, /estimated_room_revenue/);
  assert.match(integrationService, /independent_real_pms_source/);
  assert.match(integrationService, /collection_gate/);
  assert.match(integrationService, /fact_gate/);
  const recordCaptureSource = integrationService.slice(
    integrationService.indexOf('public function recordCapture'),
    integrationService.indexOf('private function configRow'),
  );
  assert.match(recordCaptureSource, /->lock\(true\)/);
  assert.match(recordCaptureSource, /whereNull\('provider_hotel_id'\)/);
  assert.match(recordCaptureSource, /factGate\(\$config, \$capture\)/);
  assert.match(controller, /new MeituanCloudPmsIntegrationService\(\)\)\s*->prefill\(/s);
  assert.match(runner, /\$integration->prefill\(/);

  assert.match(migration, /CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_integrations`/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_captures`/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `meituan_cloud_pms_room_type_details`/);
  assert.doesNotMatch(
    migration,
    /`(?:cookie|authorization_header|guest_name|mobile|webhook_url)`/i,
  );

  assert.doesNotMatch(targetTemplate, /data-testid="meituan-cloud-pms-integration"/);
  assert.match(pmsTemplate, /独立真实 PMS 数据源/);
  assert.match(pmsTemplate, /data-testid="pms-unified-reconciliation"/);
  assert.match(pmsTemplate, /data-testid="pms-source-snapshot-deltas"/);
  assert.match(pmsTemplate, /同源相邻快照/);
  assert.match(pmsTemplate, /净拾取不等于毛预订/);
  assert.match(targetTemplate, /data-testid="operating-target-prefill-meituan-cloud"/);
  assert.match(pmsTemplate, /不用0、历史记录、OTA或另一家PMS替代/);
  assert.match(pmsTemplate, /data-testid="meituan-cloud-pms-integration"/);
  assert.match(app, /loadMeituanCloudPmsIntegration/);
  assert.match(app, /saveMeituanCloudPmsIntegration/);
  assert.match(app, /prefillOperatingTargetFromMeituanCloud/);
  assert.match(app, /meituan_cloud_pms/);
  assert.match(app, /operatingTargetPmsReconciliation/);
  assert.match(app, /pmsDeltaStatusClass/);

  assert.match(controller, /PmsFactReconciliationService/);
  assert.match(controller, /pms_reconciliation/);
  assert.match(captureService, /public function history/);
  assert.match(reconciliationService, /pms_independent_source_reconciliation\.v1/);
  assert.match(reconciliationService, /PMS_DELTA_REVERSAL_UNKNOWN/);
  assert.match(reconciliationService, /dual_source_needs_review/);
  assert.match(reconciliationService, /preferred_source'\s*=>\s*null/);
  assert.match(reconciliationReference, /不合并原始记录/);
  assert.match(reconciliationReference, /只并列展示，不相减/);

  assert.match(profileService, /'meituan_cloud_pms'/);
  assert.match(profileService, /validatePmsCollectionProfile/);
  assert.match(gateway, /MEITUAN_CLOUD_PMS_READ_ONLY_POST_PATHS/);
  assert.match(gateway, /validate_pms_collection/);
  assert.match(collector, /businessOverview/);
  assert.match(collector, /workbench\/room/);
  assert.match(collector, /raw_response_exposed:\s*false/);
  assert.match(runner, /saved_and_readback_verified/);
  assert.match(runner, /prefill_readback_failed/);
  assert.match(runner, /recordCapture/);
});
