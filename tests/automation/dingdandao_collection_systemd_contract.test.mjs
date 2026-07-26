import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('Dingdandao collection timer is prepared but disabled and runs one sanitized instance', async () => {
  const [service, timer, runner, envExample] = await Promise.all([
    readFile(new URL('../../deploy/systemd/suxios-dingdandao-collection.service', import.meta.url), 'utf8'),
    readFile(new URL('../../deploy/systemd/suxios-dingdandao-collection.timer', import.meta.url), 'utf8'),
    readFile(new URL('../../scripts/run_dingdandao_collection_once.sh', import.meta.url), 'utf8'),
    readFile(new URL('../../deploy/systemd/dingdandao-collection.env.example', import.meta.url), 'utf8'),
  ]);

  assert.match(service, /^Type=oneshot$/m);
  assert.match(service, /^LoadCredential=control-token:/m);
  assert.match(service, /^RuntimeDirectory=suxios-dingdandao-collection$/m);
  assert.match(service, /^StandardOutput=journal$/m);
  assert.match(service, /^StandardError=journal$/m);
  assert.match(service, /run_dingdandao_collection_once\.sh/);
  assert.match(timer, /^Persistent=false$/m);
  assert.match(timer, /^Unit=suxios-dingdandao-collection\.service$/m);
  assert.doesNotMatch(`${service}\n${timer}\n${runner}`, /systemctl\s+(?:enable|start|restart|daemon-reload)/);

  assert.match(runner, /^set -Eeuo pipefail$/m);
  assert.match(runner, /\bflock\s+-n\b/);
  assert.match(runner, /scripts\/run_dingdandao_cloud_collection\.php/);
  assert.match(runner, /control-token-file=\/run\/credentials\/suxios-dingdandao-collection\.service\/control-token/);
  assert.doesNotMatch(runner, /set\s+-x|cat\s+.*control-token|echo\s+.*PROFILE_ID/);
  assert.match(runner, /dingdandao_collection_runner_failed/);

  assert.match(envExample, /^SUXIOS_DINGDANDAO_HOTEL_ID=5$/m);
  assert.match(envExample, /^SUXIOS_DINGDANDAO_OWNER_USER_ID=1$/m);
  assert.match(envExample, /^SUXIOS_DINGDANDAO_PROFILE_ID=cbp_[A-Za-z0-9_-]{16,64}$/m);
  assert.doesNotMatch(envExample, /TOKEN|COOKIE|PASSWORD|SECRET/i);
});
