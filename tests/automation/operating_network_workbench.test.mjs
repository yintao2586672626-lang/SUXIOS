import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readRouteContractSource } from '../../scripts/lib/route_contract_source.mjs';

const appMainComponents = readFileSync('public/components/system/app-main-components.js', 'utf8');
const operationStatic = readFileSync('public/operation-static.js', 'utf8');
const appMain = [
  readFileSync('public/components/system/knowledge-center-domain.js', 'utf8'),
  readFileSync('public/app-main.js', 'utf8'),
].join('\n');
const pageTemplate = readFileSync('resources/frontend/templates/fragments/20-page-knowledge-center.html', 'utf8');
const routes = readRouteContractSource(process.cwd());
const controller = readFileSync('app/controller/OperatingIntelligence.php', 'utf8');
const networkService = readFileSync('app/service/OperatingNetworkService.php', 'utf8');

const dimensions = [
  'hotel_type_and_scale',
  'city_district_demand',
  'price_band',
  'room_type_structure',
  'platform_channel_structure',
  'seasonality',
  'data_quality',
  'pre_action_state',
];

test('operating network exposes a real knowledge-center entry and all applicability dimensions', () => {
  assert.match(pageTemplate, /data-testid="operating-network-workbench"/);
  assert.match(pageTemplate, /data-testid="operating-network-profile-editor"/);
  assert.match(pageTemplate, /data-testid="operating-network-replication-draft"/);
  assert.match(pageTemplate, /data-testid="operating-network-assessment"/);
  assert.match(pageTemplate, /data-testid="operating-network-review"/);
  assert.match(pageTemplate, /data-testid="operating-network-asset-ledger"/);

  for (const dimension of dimensions) {
    assert.match(networkService, new RegExp(`'${dimension}'\\s*=>`));
    assert.match(appMain, new RegExp(`key:\\s*'${dimension}'`));
    assert.match(appMain, new RegExp(`${dimension}:\\s*operatingNetworkItems`));
  }
});

test('profile, draft and review flows require strict readback and keep external writes disabled', () => {
  assert.match(routes, /Route::get\('\/operating-network'/);
  assert.match(routes, /Route::post\('\/operating-profiles'/);
  assert.match(routes, /Route::post\('\/operating-sop-replications\/:id\/execution-intent'/);
  assert.match(routes, /Route::post\('\/operating-sop-replications\/:id\/reviews'/);
  assert.match(controller, /function createReplicationExecutionIntent\(/);
  assert.match(appMain, /const createOperatingNetworkExecutionIntent = async/);
  assert.match(appMain, /const openOperatingNetworkExecutionIntent = async/);
  assert.match(appMain, /operating_network_replication/);
  assert.match(pageTemplate, /data-testid="operating-network-execution-intent"/);
  assert.match(pageTemplate, /转为待审批验证任务/);
  assert.match(pageTemplate, /同一执行任务/);
  assert.match(appMain, /persistence_status\s*!==\s*'readback_verified'/);
  assert.match(appMain, /recommendation\s*!==\s*'validation_draft_only'/);
  assert.match(appMain, /for \(const field of \['automatic_execution', 'ota_write', 'external_message'\]\)/);
  assert.match(pageTemplate, /不触发自动执行、不写 OTA、不外发消息/);
  assert.match(pageTemplate, /未满足来源值/);
  assert.match(pageTemplate, /相关成功复盘/);
  assert.match(pageTemplate, /画像不相近或证据未核验/);
  assert.match(pageTemplate, /operation_effect_reviews#ID/);
  assert.match(pageTemplate, /hotel_operating_cycles/);
  assert.match(appMain, /\^operation_effect_reviews#/);
  assert.match(appMain, /\^operation_execution_evidence#/);
  assert.match(networkService, /evidence_verification/);
  assert.match(networkService, /target_authoritative_operating_loop_missing/);
  assert.match(networkService, /verified_operating_cycle_refs/);
  assert.match(networkService, /verified_execution_intent_refs/);
  assert.match(networkService, /verified_effect_review_content_digests/);
  assert.match(networkService, /assertReplicationExecutionIntentCurrent/);
  assert.match(networkService, /network_asset_summary/);
  assert.match(networkService, /field_validated'\s*=>\s*false/);
});

test('saved replication drafts remain discoverable and recoverable after a hard refresh', () => {
  assert.match(networkService, /\$replications\s*=\s*\$this->targetReplicationList/);
  assert.match(networkService, /function targetReplicationList\(/);
  assert.match(networkService, /where\('target_hotel_id', \$targetHotelId\)/);
  assert.match(pageTemplate, /data-testid="operating-network-existing-replications"/);
  assert.match(pageTemplate, /:data-status="operatingNetworkData\?\.replications\?\.data_status \|\| 'unavailable'"/);
  assert.match(appMainComponents, /operating-network-replication-gap/);
  assert.match(pageTemplate, /@restore="restoreOperatingNetworkReplication"/);
  assert.match(appMain, /const restoreOperatingNetworkReplication = async/);
  assert.match(operationStatic, /\/operation\/operating-sop-replications\/\$\{replicationId\}/);
  assert.match(operationStatic, /复制草稿恢复身份或摘要不一致/);
  assert.match(operationStatic, /await loadReviews\(replicationId\)/);

  const loadStart = appMain.indexOf('const loadOperatingNetwork = async');
  const loadEnd = appMain.indexOf('const changeOperatingNetworkHotel =', loadStart);
  assert.ok(loadStart >= 0 && loadEnd > loadStart);
  const loadFunction = appMain.slice(loadStart, loadEnd);
  assert.doesNotMatch(loadFunction, /operatingNetworkLastReplication\.value = null/);
  assert.doesNotMatch(loadFunction, /operatingNetworkReviews\.value = \[\]/);
});

test('replication recovery downgrades only the explicit not-found domain failure', () => {
  const start = networkService.indexOf('private function targetReplicationList');
  const end = networkService.indexOf('private function emptyReplicationList', start);
  assert.ok(start >= 0 && end > start);
  const targetRead = networkService.slice(start, end);
  assert.match(targetRead, /catch \(RuntimeException \$exception\)/);
  assert.match(targetRead, /isDegradableReplicationReadbackFailure\(\$exception\)/);
  assert.match(targetRead, /throw \$exception/);
  assert.doesNotMatch(targetRead, /catch \(\\Throwable/);
  assert.match(networkService, /return \$exception->getMessage\(\) === 'operating SOP replication not found'/);
});

test('profile preview is read-only, hotel-scoped and remains unverified until explicit save', () => {
  assert.match(routes, /Route::get\('\/operating-profiles\/preview',\s*'OperatingIntelligence\/previewOperatingProfile'/);
  assert.match(controller, /function previewOperatingProfile\(\)/);
  assert.match(controller, /accessibleHotels\('operation\.view'\)/);
  assert.match(networkService, /function previewProfileDraft\(/);
  assert.match(networkService, /'preview_only'\s*=>\s*true/);
  assert.match(networkService, /'persistence_status'\s*=>\s*'not_persisted'/);
  assert.match(networkService, /'automatic_verification'\s*=>\s*false/);
  assert.match(networkService, /'quality_status'\s*=>\s*'unverified'/);
  assert.match(networkService, /OnlineDataFieldFactService::buildStatus/);
  assert.match(networkService, /OnlineDataTrustStatusService::truthEnvelope/);
  assert.match(networkService, /\$effectiveDate\s*=\s*\$truthFacts\['verified_business_date_end'\]/);
  assert.match(pageTemplate, /data-testid="operating-network-generate-profile-preview"/);
  assert.match(pageTemplate, /data-testid="operating-network-profile-preview"/);
  assert.match(pageTemplate, /data-testid="operating-network-apply-profile-preview"/);
  assert.match(pageTemplate, /<select[^>]+:disabled="!!operatingNetworkAction"[^>]+data-testid="operating-network-hotel"/);
  assert.match(pageTemplate, /候选不等于已验证/);
  assert.match(pageTemplate, /只读生成，不保存、不自动核验，也不会覆盖编辑器/);

  const previewStart = appMain.indexOf('const generateOperatingNetworkProfilePreview = async');
  const previewEnd = appMain.indexOf('const applyOperatingNetworkProfilePreview =', previewStart);
  assert.ok(previewStart >= 0 && previewEnd > previewStart);
  const previewFunction = appMain.slice(previewStart, previewEnd);
  assert.match(previewFunction, /\/operation\/operating-profiles\/preview\?hotel_id=/);
  assert.match(previewFunction, /Number\(preview\.hotel_id \|\| 0\) !== hotelId/);
  assert.match(previewFunction, /preview\.preview_only !== true/);
  assert.match(previewFunction, /preview\.automatic_verification !== false/);
  assert.doesNotMatch(previewFunction, /method:\s*'POST'/);

  const applyStart = previewEnd;
  const applyEnd = appMain.indexOf('const saveOperatingNetworkProfile = async', applyStart);
  const applyFunction = appMain.slice(applyStart, applyEnd);
  assert.match(applyFunction, /quality_status = 'unverified'/);
  assert.match(applyFunction, /evidence_valid_until = ''/);

  const saveStart = applyEnd;
  const saveEnd = appMain.indexOf('const loadOperatingNetworkReviews = async', saveStart);
  assert.ok(saveStart >= 0 && saveEnd > saveStart);
  const saveFunction = appMain.slice(saveStart, saveEnd);
  assert.ok(
    (saveFunction.match(/Number\(operatingNetworkHotelId\.value \|\| 0\) !== hotelId/g) || []).length >= 2,
    'save and independent readback must both fail closed after a hotel switch',
  );
  assert.match(saveFunction, /Number\(operatingNetworkHotelId\.value \|\| 0\) === hotelId/);
  assert.match(saveFunction, /operatingNetworkAction\.value === 'profile'/);
});

test('new-hotel onboarding remains an ordered seven-stage evidence gate', () => {
  const labels = [
    '身份确认',
    '数据源绑定',
    '房型价型映射',
    '指标口径确认',
    '首次可信采集',
    '首次经营闭环',
    '可比酒店识别',
  ];
  let previous = -1;
  for (const label of labels) {
    const index = pageTemplate.indexOf(label);
    assert.ok(index > previous, `${label} should appear in onboarding order`);
    previous = index;
  }
});

test('formal knowledge revisions persist replication profile, parameters, outcomes and expiry', () => {
  assert.match(pageTemplate, /data-testid="knowledge-promotion-applicability-editor"/);
  assert.match(appMain, /applicability_profile:\s*\{/);
  assert.match(appMain, /action_parameters:\s*knowledgePromotionLines/);
  assert.match(appMain, /success_conditions:\s*knowledgePromotionLines/);
  assert.match(appMain, /failure_samples:\s*knowledgePromotionLines/);
  assert.match(appMain, /evidence_valid_until:\s*String\(knowledgePromotionForm\.value\.evidence_valid_until/);
});
