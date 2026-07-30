import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('cloud OTA readiness inspection is read-only and never prints credential material', async () => {
  const script = await readFile(
    new URL('../../scripts/inspect_tencent_cloud_ota_runtime.ps1', import.meta.url),
    'utf8',
  );
  assert.match(script, /online-data:auto-fetch --help/);
  assert.match(script, /--daily-only/);
  assert.match(script, /--realtime-only/);
  assert.match(script, /collector_scope_supported/);
  assert.match(script, /collector_scope_validation_supported/);
  assert.match(script, /collector_scope_binding_supported/);
  assert.match(script, /ExecStartPre=.*--validate-cloud-scope/);
  assert.match(script, /SUXIOS_OTA_CLOUD_USER_ID/);
  assert.match(script, /SUXIOS_OTA_CLOUD_DEVICE_ID/);
  assert.match(script, /SUXIOS_OTA_CLOUD_HOTEL_ID/);
  assert.match(script, /SUXIOS_OTA_CLOUD_SOURCE_IDS/);
  assert.match(script, /SUXIOS_OTA_CLOUD_PLATFORMS/);
  assert.match(script, /cloud_scope_configured/);
  assert.match(script, /cloud_scope_preflight_passed/);
  assert.match(script, /online-data:auto-fetch --validate-cloud-scope/);
  assert.doesNotMatch(script, /SUXIOS_OTA_CLOUD_PROFILE_READY/);
  assert.match(script, /systemctl cat/);
  assert.doesNotMatch(script, /cat\s+\/etc\/suxios\/ota-collector\.env/);
  assert.doesNotMatch(script, /scp\.exe/);
  assert.doesNotMatch(script, /systemctl enable|systemctl start|systemctl restart/);
});
