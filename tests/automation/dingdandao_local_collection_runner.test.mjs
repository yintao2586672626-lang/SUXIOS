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
  assert.match(runner, /'collection-mode::'/);
  assert.match(runner, /localCollectionMode/);
  assert.match(runner, /'full_diagnostic'/);
  assert.match(runner, /--collection-mode='\s*\.\s*\$collectionMode/);
  assert.match(runner, /'collection_mode'\s*=>\s*\$collectionMode/);
  assert.match(runner, /\['bypass_shell'\s*=>\s*true\]/);
  assert.doesNotMatch(runner, /gatewayRequest|control-token|profile-id/);
});

test('scheduled Dingdandao collection can require one explicit shared-browser sandbox', () => {
  assert.match(runner, /'sandbox-id::'/);
  assert.match(runner, /'require-sandbox'/);
  assert.match(runner, /localValidSandboxId/);
  assert.match(runner, /--sandbox-id=/);
  assert.match(runner, /'sandbox_selection'\s*=>\s*\$sandboxId\s*!==\s*''/);
  assert.match(runner, /'explicit_marker'/);
  assert.match(runner, /'sandbox_isolated'\s*=>\s*\$sandboxId\s*!==\s*''/);
});

test('local Dingdandao runner requires a named binding and learns its ID only from trusted capture', () => {
  assert.match(runner, /captureExpectation/);
  assert.match(runner, /configured.*!==\s*true/s);
  assert.match(runner, /dingdandao_local_provider_binding_missing/);
  assert.match(runner, /expectedProviderHotelId/);
  assert.match(runner, /dingdandao_local_provider_identity_incomplete/);
  assert.match(
    runner,
    /\$expectedProviderHotelId\s*=\s*trim\([\s\S]+\$collector\['capture'\]\['provider_hotel_id'\]/,
  );
  assert.ok(
    runner.indexOf("$collector['capture']['provider_hotel_id']")
      < runner.indexOf('DingdandaoOperatingTargetCaptureService())->save'),
  );
});

test('local Dingdandao runner limits historical recovery to exact operating facts', () => {
  assert.match(runner, /\$targetDate\s*>\s*\$today/);
  assert.match(runner, /dingdandao_local_target_date_in_future/);
  assert.match(runner, /\$historicalCollection[\s\S]+operating_indicators/);
  assert.match(runner, /dingdandao_local_historical_collection_mode_invalid/);
  assert.match(runner, /dingdandao_local_historical_direct_push_not_allowed/);
  assert.match(
    runner,
    /HISTORICAL_SOURCE_SCOPE[\s\S]+SOURCE_SCOPE/,
  );
  assert.match(
    runner,
    /\$collector\['capture'\]\['source_scope'\][\s\S]+!==\s*\$expectedSourceScope/,
  );
  assert.match(
    runner,
    /'source_scope'\s*=>\s*\$expectedSourceScope/,
  );
});

test('local Dingdandao runner saves and reads back before optional manual push', () => {
  const saveIndex = runner.indexOf('DingdandaoOperatingTargetCaptureService())->save');
  const readbackIndex = runner.indexOf('dingdandao_local_collection_readback_not_verified');
  const contractIndex = runner.indexOf('validateDingdandaoCaptureClaim');
  const prefillIndex = runner.indexOf('$integrationService->prefill');
  const syncIndex = runner.indexOf('$integrationService->syncVerifiedCapture');
  const pushIndex = runner.indexOf('$integrationService->dispatchVerifiedCapture');

  assert.ok(saveIndex >= 0);
  assert.ok(readbackIndex > saveIndex);
  assert.ok(contractIndex > readbackIndex);
  assert.ok(prefillIndex > contractIndex);
  assert.ok(syncIndex > prefillIndex);
  assert.ok(pushIndex > syncIndex);
  assert.match(runner, /array_key_exists\('push',\s*\$options\)/);
  assert.match(runner, /\$pushRequested[\s\S]+dispatchVerifiedCapture/);
  assert.match(runner, /\$capture,\s*'manual'/);
  assert.match(runner, /\['sent',\s*'already_sent'\]/);
  assert.match(runner, /User::where\('id',\s*\$ownerUserId\)->find\(\)/);
  assert.match(runner, /PermissionService\(\)\)->authorize\([\s\S]*'ota\.collect'/);
  assert.match(runner, /dingdandao_local_owner_permission_denied/);
  assert.match(runner, /'collection_success'\s*=>\s*true/);
  assert.match(runner, /'business_data_persisted'\s*=>\s*true/);
  assert.match(runner, /'component_coverage'\s*=>/);
  assert.match(runner, /'collection_contract_status'\s*=>\s*'verified'/);
  assert.match(runner, /dingdandao_local_collection_contract_not_verified/);
  assert.match(runner, /'saved_downstream_blocked'/);
  assert.match(runner, /'failure_stage'\s*=>\s*\$failureStage/);
  assert.match(runner, /'capture_id'\s*=>\s*\(int\)\(\$persistedCapture\['id'\]/);
  assert.match(runner, /'delivery_satisfied'\s*=>\s*!\$pushRequested\s*\|\|\s*\$messageSent/);
  assert.doesNotMatch(runner, /dingdandao_local_push_(?:blocked|failed)/);
});

test('local Dingdandao runner reports regional and trend coverage without secrets', () => {
  assert.match(runner, /trend_point_counts/);
  assert.match(runner, /regional_benchmark/);
  assert.match(runner, /localForwardRoomStatusSummary/);
  assert.match(runner, /'forward_room_status'\s*=>\s*localForwardRoomStatusSummary/);
  assert.match(runner, /'display_horizons'/);
  assert.match(runner, /'readback_status'/);
  assert.match(runner, /'oversold_room_nights'/);
  assert.match(runner, /'anomaly_count'/);
  assert.match(runner, /'anomalies'/);
  assert.match(runner, /raw_response_exposed'\s*=>\s*false/);
  assert.match(runner, /session_material_exposed'\s*=>\s*false/);
  assert.match(runner, /sensitive_values_exposed'\s*=>\s*false/);
  assert.doesNotMatch(runner, /cookieHeader|Authorization:\s*Bearer/);
});
