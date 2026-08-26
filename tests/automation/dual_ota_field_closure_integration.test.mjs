import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = file => readFileSync(file, 'utf8');
const concern = read('app/controller/concern/CollectionReliabilityConcern.php');
const startup = read('scripts/lib/frontend_startup_helpers_build.mjs');
const panelLoader = read('public/components/system/dual-ota-field-closure-loader.js');
const panel = read('public/components/system/dual-ota-field-closure-panel.js');
const appMain = read('public/app-main.js');
const dataHealth = read('resources/frontend/templates/fragments/35-page-online-data.html');
const revenueCockpit = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const revenueController = read('app/controller/RevenueAi.php');
const strictEvidence = read('app/service/RevenueCockpitStrictEvidenceService.php');
const approvalService = read('app/service/RevenueCockpitApprovalService.php');
const intentProvenance = read('app/service/RevenueCockpitIntentProvenanceService.php');

test('collection reliability returns the same exact-date closure on cached, light and full reads', () => {
  assert.match(concern, /use app\\service\\DualOtaFieldClosureService;/);
  assert.match(concern, /private function withDualOtaFieldClosure\(/);
  assert.match(concern, /\$cached = \$this->withDualOtaFieldClosure\(/);
  assert.match(concern, /buildCollectionReliabilityLightPayload[\s\S]*withDualOtaFieldClosure\(\$payload, \$hotelId, \$endDate\)/);
  assert.match(concern, /buildCollectionReliabilityPayload[\s\S]*withDualOtaFieldClosure\(\$payload, \$hotelId, \$endDate\)/);
  assert.match(concern, /\(new DualOtaFieldClosureService\(\)\)->build\(\$hotelId, \$targetDate\)/);
});

test('startup registers an async field-table bridge before the app component loader', () => {
  const panelIndex = startup.indexOf("'components/system/dual-ota-field-closure-loader.js'");
  const loaderIndex = startup.indexOf("'components/system/app-main-components-loader.js'");
  assert.ok(panelIndex >= 0, 'field closure loader missing from startup sources');
  assert.ok(loaderIndex > panelIndex, 'field closure loader must register before app component loader');
  assert.match(panelLoader, /Vue\.defineAsyncComponent/);
  assert.match(panelLoader, /dual-ota-field-closure-panel\.js\?v=/);
  assert.match(panelLoader, /systemComponents\.DualOtaFieldClosurePanel/);
  assert.match(panel, /systemComponents\.DualOtaFieldClosurePanel/);
});

test('data health and revenue cockpit mount one shared scoped field-table contract', () => {
  assert.match(dataHealth, /<dual-ota-field-closure-panel/);
  assert.match(dataHealth, /collectionReliabilityError \? null : \(collectionReliability\?\.dual_ota_field_closure \|\| null\)/);
  assert.match(dataHealth, /:hotel-id="coreOperationsHotelId \|\| dashboardHotelId \|\| filterReportHotel"/);
  assert.match(dataHealth, /:business-date="coreOperationsTargetDate \|\| coreOperationsMaxDate"/);
  assert.match(dataHealth, /surface="data_health"/);

  assert.match(revenueCockpit, /<dual-ota-field-closure-panel/);
  assert.match(revenueCockpit, /revenueCockpitOverview\?\.dual_ota_field_closure \|\| null/);
  assert.match(revenueCockpit, /:hotel-id="filterReportHotel"/);
  assert.match(revenueCockpit, /:business-date="revenueCockpitBusinessDate \|\| coreOperationsTargetDate \|\| coreOperationsMaxDate"/);
  assert.match(revenueCockpit, /surface="revenue_cockpit"/);

  for (const page of [dataHealth, revenueCockpit]) {
    assert.match(page, /:request="managerCapabilityRequest"/);
    assert.match(page, /:force-read="true"/);
  }
  assert.match(panel, /dual_ota_field_closure\.v1/);
  assert.match(panel, /\/online-data\/collection-reliability\?/);
  assert.match(panel, /data-closure-identity/);
  assert.match(panel, /data-business-date/);
  assert.match(panel, /dual-ota-field-download-/);
  assert.match(panel, /buildClosureDownloadPayload/);
});

test('revenue and operation gates consume the canonical closure instead of rereading a second metric map', () => {
  assert.match(revenueController, /\$closure = \(new DualOtaFieldClosureService\(\)\)->build\(\$hotelId, \$businessDate\)/);
  assert.match(revenueController, /\$overview\['dual_ota_field_closure'\] = \$closure/);
  assert.match(revenueController, /RevenueCockpitStrictEvidenceService\(\)\)->build\([\s\S]*\$platform,[\s\S]*\$closure/);
  assert.match(approvalService, /RevenueCockpitIntentProvenanceService\(\)\)->assertIntentCurrent\(\$intent\)/);
  assert.match(intentProvenance, /\$closure = \(new DualOtaFieldClosureService\(\)\)->build\(\$hotelId, \$businessDate\)/);
  assert.match(intentProvenance, /\$overview\['dual_ota_field_closure'\] = \$closure/);
  assert.match(intentProvenance, /RevenueCockpitStrictEvidenceService\(\)\)->build\([\s\S]*\$platform,[\s\S]*\$closure/);
  assert.match(strictEvidence, /field_source' => 'dual_ota_field_closure'/);
  assert.match(strictEvidence, /consumer_metric_keys/);
  assert.doesNotMatch(strictEvidence, /readCurrentVerifiedFactsForRefs/);
  assert.doesNotMatch(strictEvidence, /\$factLayer\['sources'\]/);
});

test('legacy health cards cannot contradict the strict field table with unverified numbers', () => {
  assert.match(appMain, /include_missing_state: '1',[\s\S]*strict_readback_only: '1'/);
  assert.match(appMain, /refreshCoreOperationsLoop\(\{[\s\S]*forceCollectionReliability: force/);
  assert.match(appMain, /const truthStatus = String\(truth\.status \|\| 'unverified'\)\.toLowerCase\(\);/);
  assert.match(appMain, /const verifiedValue = truthStatus === 'verified' \? rawValue : null;/);
  assert.match(appMain, /rawValue: verifiedValue,[\s\S]*coreOperationsMetricCardValueText\(verifiedValue/);
});
