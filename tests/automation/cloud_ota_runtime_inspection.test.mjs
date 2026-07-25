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
  assert.match(script, /SUXIOS_OTA_CLOUD_PROFILE_READY=1/);
  assert.match(script, /systemctl cat/);
  assert.doesNotMatch(script, /cat\s+\/etc\/suxios\/ota-collector\.env/);
  assert.doesNotMatch(script, /scp\.exe/);
  assert.doesNotMatch(script, /systemctl enable|systemctl start|systemctl restart/);
});
