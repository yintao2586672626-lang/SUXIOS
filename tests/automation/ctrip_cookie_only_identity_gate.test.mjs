import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const backend = readFileSync(new URL('../../app/controller/concern/OnlineDataManualFetchConcern.php', import.meta.url), 'utf8');
const requestSanitizer = backend.match(/private function sanitizeCtripManualFetchRequestData[\s\S]*?\n    private function sanitizeMeituanManualFetchRequestData/);
const executionBoundary = backend.match(/public function fetchCtrip\(\): Response[\s\S]*?\n    private function executeCtripManualFetch/);
const validator = backend.match(/private function validateCtripManualBusinessHotelIdentity[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);
const metadataReader = backend.match(/private function readSafeCtripIdentityMetadataList\(\): array[\s\S]*?\n    private function resolveCtripManualBusinessIdentityConfig/);
const noWriteBinding = backend.match(/private function persistCtripResolvedPlatformHotelIdForSystemHotel[\s\S]*?\n    \/\*\*/);

test('Ctrip manual fetch uses a vault locator and keeps inferred hotel identity request-scoped', () => {
  assert.ok(requestSanitizer, 'Ctrip request sanitizer should be present');
  assert.ok(executionBoundary, 'Ctrip credential execution boundary should be present');
  assert.ok(validator, 'Ctrip identity validator should be present');
  assert.ok(metadataReader, 'Ctrip safe identity metadata reader should be present');
  assert.ok(noWriteBinding, 'Ctrip inferred identity no-write helper should be present');

  const sanitizerBody = requestSanitizer[0];
  assert.match(sanitizerBody, /'config_id'/);
  assert.match(sanitizerBody, /'system_hotel_id'/);
  assert.doesNotMatch(sanitizerBody, /'cookies?'|'auth_data'|'authorization'|'headers(?:_json)?'|'payload(?:_json)?'|'token'/);

  const boundaryBody = executionBoundary[0];
  assert.match(boundaryBody, /\$configId = trim\(\(string\)\(\$requestData\['config_id'\] \?\? ''\)\)/);
  assert.match(boundaryBody, /\$systemHotelId = \$this->strictPositiveOtaConfigHotelId\(\$requestData\['system_hotel_id'\] \?\? null\)/);
  assert.match(boundaryBody, /withOtaCredentialForExecution\(\s*'ctrip',\s*\$configId,\s*\$systemHotelId/);
  assert.match(boundaryBody, /请仅提供 config_id 与 system_hotel_id/);

  const identityBody = validator[0];
  assert.match(identityBody, /expected_platform_hotel_id_missing/);
  assert.match(identityBody, /captured_platform_hotel_id_ambiguous/);
  assert.match(identityBody, /findCtripSystemHotelMatchesByPlatformIds\(\$capturedIds\)/);
  assert.match(identityBody, /findCtripPlatformHotelIdConflicts\(\$capturedIds, \$systemHotelId\)/);
  assert.match(identityBody, /array_intersect\(\$expectedIds, \$capturedIds\) === \[\]/);
  assert.match(identityBody, /'status' => 'request_scoped_platform_hotel_id'/);
  assert.match(identityBody, /'auto_bound' => false/);
  assert.match(identityBody, /未改写携程凭据配置/);
  assert.doesNotMatch(identityBody, /platform_hotel_id_auto_bind_failed|auto_bound_platform_hotel_id/);

  const noWriteBody = noWriteBinding[0];
  assert.match(noWriteBody, /return false;/);
  assert.doesNotMatch(noWriteBody, /->update\(|->save\(|SystemConfig::setValue|Db::name/);

  const metadataBody = metadataReader[0];
  const allowedFields = metadataBody.match(/\$allowedFields = array_fill_keys\(\[[\s\S]*?\], true\);/);
  assert.ok(allowedFields, 'safe identity field whitelist should be explicit');
  assert.match(allowedFields[0], /'config_id'/);
  assert.match(allowedFields[0], /'system_hotel_id'/);
  assert.doesNotMatch(allowedFields[0], /'cookies?'|'auth_data'|'authorization'|'headers(?:_json)?'|'payload(?:_json)?'|'token'/);
  assert.match(metadataBody, /splitOtaConfigSecrets\(\$config\)/);
  assert.match(metadataBody, /Legacy Ctrip plaintext credential requires Task6 migration/);
});
