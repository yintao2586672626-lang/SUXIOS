import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const runner = readFileSync(
  new URL('../../scripts/run_dingdandao_local_collection.php', import.meta.url),
  'utf8',
);

test('local Dingdandao runner uses loopback CDP without cloud gateway credentials', () => {
  assert.match(runner, /execution_mode'\s*=>\s*'local_cdp'/);
  assert.match(runner, /http:\/\/127\\\.0\\\.0\\\.1:/);
  assert.match(runner, /localValidCdpUrl/);
  assert.match(runner, /--collection-mode=full_diagnostic/);
  assert.match(runner, /\['bypass_shell'\s*=>\s*true\]/);
  assert.doesNotMatch(runner, /gatewayRequest|control-token|profile-id/);
});

test('local Dingdandao runner requires an explicit provider binding', () => {
  assert.match(runner, /captureExpectation/);
  assert.match(runner, /configured.*!==\s*true/s);
  assert.match(runner, /dingdandao_local_provider_binding_missing/);
  assert.match(runner, /expectedProviderHotelId/);
  assert.match(runner, /dingdandao_local_provider_identity_incomplete/);
});

test('local Dingdandao runner saves and reads back before optional manual push', () => {
  const saveIndex = runner.indexOf('DingdandaoOperatingTargetCaptureService())->save');
  const readbackIndex = runner.indexOf('dingdandao_local_collection_readback_not_verified');
  const prefillIndex = runner.indexOf('$integrationService->prefill');
  const syncIndex = runner.indexOf('$integrationService->syncVerifiedCapture');
  const pushIndex = runner.indexOf('$integrationService->dispatchVerifiedCapture');

  assert.ok(saveIndex >= 0);
  assert.ok(readbackIndex > saveIndex);
  assert.ok(prefillIndex > readbackIndex);
  assert.ok(syncIndex > prefillIndex);
  assert.ok(pushIndex > syncIndex);
  assert.match(runner, /array_key_exists\('push',\s*\$options\)/);
  assert.match(runner, /\$pushRequested[\s\S]+dispatchVerifiedCapture/);
  assert.match(runner, /\$capture,\s*'manual'/);
  assert.match(runner, /\['sent',\s*'already_sent'\]/);
});

test('local Dingdandao runner reports regional and trend coverage without secrets', () => {
  assert.match(runner, /trend_point_counts/);
  assert.match(runner, /regional_benchmark/);
  assert.match(runner, /raw_response_exposed'\s*=>\s*false/);
  assert.match(runner, /session_material_exposed'\s*=>\s*false/);
  assert.match(runner, /sensitive_values_exposed'\s*=>\s*false/);
  assert.doesNotMatch(runner, /cookieHeader|Authorization:\s*Bearer/);
});
