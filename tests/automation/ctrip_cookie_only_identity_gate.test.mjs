import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';

const backend = readSourceAggregate('app/controller/concern/OnlineDataManualFetchConcern.php');
const otaConfig = readFileSync(new URL('../../app/controller/concern/OtaConfigConcern.php', import.meta.url), 'utf8');
const requestSanitizer = backend.match(/private function sanitizeCtripManualFetchRequestData[\s\S]*?(?=\n    private function sanitizeCtripTemporaryCookieRequestData)/);
const executionBoundary = backend.match(/public function fetchCtrip\(\): Response[\s\S]*?\n    private function executeCtripManualFetch/);
const validator = backend.match(/private function validateCtripManualBusinessHotelIdentity[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);
const metadataReader = backend.match(/private function readSafeCtripIdentityMetadataList\(\): array[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);
const bindingDelegator = backend.match(/private function persistCtripResolvedPlatformHotelIdForSystemHotel[\s\S]*?\n    \/\*\*/);
const verifiedBinding = otaConfig.match(/private function persistVerifiedCtripPlatformHotelBinding[\s\S]*?\n    \/\*\*/);

test('Ctrip manual fetch uses a vault locator and auto-binds only one verified exact config', () => {
  assert.ok(requestSanitizer, 'Ctrip request sanitizer should be present');
  assert.ok(executionBoundary, 'Ctrip credential execution boundary should be present');
  assert.ok(validator, 'Ctrip identity validator should be present');
  assert.ok(metadataReader, 'Ctrip safe identity metadata reader should be present');
  assert.ok(bindingDelegator, 'Ctrip inferred identity binding delegator should be present');
  assert.ok(verifiedBinding, 'Ctrip verified identity persistence boundary should be present');

  const sanitizerBody = requestSanitizer[0];
  assert.match(sanitizerBody, /'config_id'/);
  assert.match(sanitizerBody, /'system_hotel_id'/);
  assert.doesNotMatch(sanitizerBody, /'cookies?'|'auth_data'|'authorization'|'headers(?:_json)?'|'payload(?:_json)?'|'token'/);

  const boundaryBody = executionBoundary[0];
  assert.match(boundaryBody, /\$configId = trim\(\(string\)\(\$requestData\['config_id'\] \?\? ''\)\)/);
  assert.match(boundaryBody, /\$systemHotelId = \$this->strictPositiveOtaConfigHotelId\(\$requestData\['system_hotel_id'\] \?\? null\)/);
  assert.match(boundaryBody, /withOtaCredentialForExecution\(\s*'ctrip',\s*\$configId,\s*\$systemHotelId/);
  assert.match(boundaryBody, /请求包含不支持的执行字段或字段类型/);

  const identityBody = validator[0];
  assert.match(identityBody, /platform_hotel_id_incomplete/);
  assert.match(identityBody, /returned_current_hotel_id_missing/);
  assert.match(identityBody, /'ok' => false,[\s\S]{0,180}'status' => 'platform_hotel_id_incomplete'/);
  assert.match(identityBody, /本次未入库/);
  assert.match(identityBody, /captured_platform_hotel_id_ambiguous/);
  assert.match(identityBody, /findCtripSystemHotelMatchesByPlatformIds\(\$capturedIds\)/);
  assert.match(identityBody, /findCtripPlatformHotelIdConflicts\(\$capturedIds, \$systemHotelId\)/);
  assert.match(identityBody, /array_intersect\(\$expectedIds, \$capturedIds\) === \[\]/);
  assert.match(identityBody, /'status' => 'request_scoped_platform_hotel_id'/);
  assert.match(identityBody, /'auto_bound' => false/);
  assert.match(identityBody, /未改写携程凭据配置/);
  assert.match(identityBody, /'status' => 'auto_bound_platform_hotel_id'/);
  assert.match(identityBody, /'auto_bound' => true/);

  const delegatorBody = bindingDelegator[0];
  assert.match(delegatorBody, /persistVerifiedCtripPlatformHotelBinding\(/);
  assert.doesNotMatch(delegatorBody, /->update\(|->save\(|SystemConfig::setValue|Db::name/);

  const verifiedBindingBody = verifiedBinding[0];
  assert.match(verifiedBindingBody, /Db::transaction\(/);
  assert.match(verifiedBindingBody, /->lock\(true\)/);
  assert.match(verifiedBindingBody, /otaConfigBoundSystemHotelId\(\$candidate\) !== \$systemHotelId/);
  assert.match(verifiedBindingBody, /\(string\)\(\$candidate\['credential_status'\] \?\? ''\) !== 'ready'/);
  assert.match(verifiedBindingBody, /\(\$candidate\['has_cookies'\] \?\? false\) !== true/);
  assert.match(verifiedBindingBody, /count\(\$candidates\) !== 1/);
  assert.match(verifiedBindingBody, /assertUniqueOtaPlatformHotelBinding\(/);
  assert.match(verifiedBindingBody, /splitOtaConfigSecrets\(\$candidate\)/);
  assert.match(verifiedBindingBody, /Ctrip platform hotel identity readback failed/);

  const metadataBody = metadataReader[0];
  const allowedFields = metadataBody.match(/\$allowedFields = array_fill_keys\(\[[\s\S]*?\], true\);/);
  assert.ok(allowedFields, 'safe identity field whitelist should be explicit');
  assert.match(allowedFields[0], /'config_id'/);
  assert.match(allowedFields[0], /'system_hotel_id'/);
  assert.doesNotMatch(allowedFields[0], /'cookies?'|'auth_data'|'authorization'|'headers(?:_json)?'|'payload(?:_json)?'|'token'/);
  assert.match(metadataBody, /splitOtaConfigSecrets\(\$config\)/);
  assert.match(metadataBody, /Legacy Ctrip plaintext credential requires Task6 migration/);
});
