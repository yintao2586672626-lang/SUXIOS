import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
const runner = read('scripts/run_cloud_ota_profile_collection.php');
const adapter = read('app/service/platform/TrustedCloudProfileDataSourceAdapter.php');
const service = read('deploy/systemd/suxios-cloud-ota-profile-collection.service');
const timer = read('deploy/systemd/suxios-cloud-ota-profile-collection.timer');
const batch = read('scripts/run_cloud_ota_profile_collection_batch.php');

test('cloud OTA runner binds one source/Profile/date and persists only after current capture proof', () => {
  assert.match(runner, /validateOtaCollectionProfile/);
  assert.match(runner, /set_exception_handler/);
  assert.match(runner, /'collection_kind'\s*=>\s*'ota_channel_profile'/);
  assert.match(runner, /'data_source_id'\s*=>\s*\$sourceId/);
  assert.match(runner, /'cdp_url'\s*=>\s*\$cdpUrl/);
  const capture = runner.indexOf('$captureAdapter->fetch(');
  const proof = runner.indexOf('recordCollectionPreflightVerified(');
  const sync = runner.indexOf('->syncDataSource(');
  assert.ok(capture >= 0);
  assert.ok(proof > capture);
  assert.ok(sync > proof);
  assert.match(runner, /isCurrentVerified\(\$proofSource\)/);
  assert.match(runner, /current_session_proof_readback_verified/);
  assert.match(runner, /BrowserProfileProcessOutputSanitizer::sanitizeMessage/);
  assert.match(runner, /\['capture_sections'\]\s*=\s*'traffic'/);
  assert.match(runner, /\['capture_mode'\]\s*=\s*'temporal_summary'/);
  assert.match(runner, /'require_current_run_session_probe'\s*=>\s*true/);
  assert.match(runner, /unset\(\$syncOptions\['cdp_url'\]\)/);
  assert.match(runner, /ota_request_or_own_response/);
  assert.match(runner, /\$readbackCount !== \$savedCount/);
  assert.match(runner, /'readback_verified'\s*=>\s*true/);
  assert.match(runner, /'message_sent'\s*=>\s*false/);
  assert.doesNotMatch(runner, /dispatchVerifiedCapture|WeCom|webhook/i);
});

test('one-shot adapter refuses stale, mismatched, or reusable capture payloads', () => {
  assert.match(adapter, /final class TrustedCloudProfileDataSourceAdapter/);
  assert.match(adapter, /private bool \$consumed = false/);
  assert.match(adapter, /trusted_cloud_profile_adapter_reuse_blocked/);
  assert.match(adapter, /http_cache_disabled/);
  assert.match(adapter, /service_worker_bypassed/);
  assert.match(adapter, /ota_request_or_own_response/);
  assert.match(adapter, /validated_identifier/);
  assert.match(adapter, /hash_equals\(\$this->expectedPlatformHotelId/);
});

test('cloud OTA service serializes Ctrip then Meituan and cannot send messages', () => {
  const execStarts = service.split(/\r?\n/).filter((line) => line.startsWith('ExecStart='));
  assert.equal(execStarts.length, 1);
  assert.match(execStarts[0], /run_cloud_ota_profile_collection_batch\.php/);
  assert.ok(batch.indexOf("'platform' => 'ctrip'")
    < batch.indexOf("'platform' => 'meituan'"));
  assert.match(batch, /foreach \(\$scopes as \$scope\)/);
  assert.match(batch, /partial_or_blocked/);
  assert.match(batch, /exit\(\$allVerified \? 0 : 1\)/);
  assert.match(service, /LoadCredential=control-token:/);
  assert.match(service, /Requires=suxios-cloud-browser-gateway\.service/);
  assert.match(service, /RuntimeDirectory=suxios-cloud-ota-profile-collection/);
  assert.doesNotMatch(execStarts.join('\n') + batch, /WeCom|webhook|dispatch|push/i);
  assert.match(timer, /OnCalendar=\*-\*-\* \*:35:00 Asia\/Shanghai/);
  assert.match(timer, /Persistent=false/);
});
