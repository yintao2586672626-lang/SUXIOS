import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('Dingdandao collection timer runs one collection-only three-source instance', async () => {
  const [service, timer, runner, envExample] = await Promise.all([
    readFile(new URL('../../deploy/systemd/suxios-dingdandao-collection.service', import.meta.url), 'utf8'),
    readFile(new URL('../../deploy/systemd/suxios-dingdandao-collection.timer', import.meta.url), 'utf8'),
    readFile(new URL('../../scripts/run_molanxin_collection_preview.php', import.meta.url), 'utf8'),
    readFile(new URL('../../deploy/systemd/dingdandao-collection.env.example', import.meta.url), 'utf8'),
  ]);

  assert.match(service, /^Type=oneshot$/m);
  assert.match(service, /^LoadCredential=control-token:/m);
  assert.match(service, /^RuntimeDirectory=suxios-dingdandao-collection$/m);
  assert.match(service, /^StandardOutput=journal$/m);
  assert.match(service, /^StandardError=journal$/m);
  assert.match(service, /verify_dingdandao_collection\.php/);
  assert.match(service, /run_molanxin_collection_preview\.php/);
  assert.doesNotMatch(service, /^\[Install\]$/m);
  assert.match(timer, /^Persistent=false$/m);
  assert.match(timer, /^OnCalendar=\*-\*-\* \*:35:00 Asia\/Shanghai$/m);
  assert.match(timer, /^Unit=suxios-dingdandao-collection\.service$/m);
  assert.doesNotMatch(`${service}\n${timer}\n${runner}`, /systemctl\s+(?:enable|start|restart|daemon-reload)/);

  assert.match(runner, /scripts\/run_dingdandao_cloud_collection\.php/);
  assert.match(runner, /'--collection-only'/);
  assert.match(runner, /saved_capture_and_base_facts_ready/);
  assert.match(runner, /skipped_collection_only/);
  assert.match(runner, /SingleHotelOperatingDigestService/);
  assert.match(runner, /SingleHotelCollectionPreviewRunService/);
  assert.match(runner, /'source_readiness' => \$sourceReadiness/);
  assert.match(runner, /'source_lineage' => \$sourceLineage/);
  assert.match(runner, /ctrip_source_trace_ids/);
  assert.match(runner, /meituan_source_trace_ids/);
  assert.match(runner, /--control-token-file=/);
  assert.match(runner, /\/run\/credentials\/suxios-dingdandao-collection\.service\/control-token/);
  assert.match(runner, /\/run\/credentials\/suxios-molanxin-three-source-collection\.service\/control-token/);
  assert.match(runner, /--runtime-directory=/);
  assert.match(runner, /\/run\/suxios-molanxin-three-source-collection/);
  assert.match(runner, /'message_sent' => false/);
  assert.match(runner, /'dispatch_requested' => false/);
  assert.doesNotMatch(runner, /manual-notification:schedule|WechatRobotDelivery|testPush|competitor_wechat_robot/);

  assert.match(envExample, /^SUXIOS_DINGDANDAO_HOTEL_ID=5$/m);
  assert.match(envExample, /^SUXIOS_DINGDANDAO_OWNER_USER_ID=1$/m);
  assert.match(envExample, /^SUXIOS_DINGDANDAO_PROFILE_ID=cbp_[A-Za-z0-9_-]{16,64}$/m);
  assert.doesNotMatch(envExample, /TOKEN|COOKIE|PASSWORD|SECRET/i);
});
