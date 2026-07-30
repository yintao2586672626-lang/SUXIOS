import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('collection-only installer scopes one hotel and cannot dispatch WeCom', async () => {
  const [installer, verifier, runner] = await Promise.all([
    readFile(new URL('../../deploy/systemd/install_dingdandao_collection.sh', import.meta.url), 'utf8'),
    readFile(new URL('../../deploy/systemd/verify_dingdandao_collection.php', import.meta.url), 'utf8'),
    readFile(new URL('../../scripts/run_molanxin_collection_preview.php', import.meta.url), 'utf8'),
  ]);

  assert.match(installer, /--hotel-id/);
  assert.match(installer, /--owner-user-id/);
  assert.match(installer, /--profile-id/);
  assert.match(installer, /--enable requires --install/);
  assert.match(installer, /verify_dingdandao_collection\.php/);
  assert.match(installer, /systemctl enable --now "\$TIMER_NAME"/);
  assert.doesNotMatch(installer, /manual-notification:schedule|test-push|WechatRobotDelivery/);

  assert.match(verifier, /validateDingdandaoCollectionProfile/);
  assert.match(verifier, /ctrip_binding_ready/);
  assert.match(verifier, /meituan_binding_ready/);
  assert.match(verifier, /dingdandao_binding_ready/);
  assert.match(verifier, /'database_write' => false/);
  assert.match(verifier, /'webhook_read' => false/);
  assert.match(verifier, /'message_sent' => false/);
  assert.doesNotMatch(verifier, /WechatRobotDelivery|manual-notification:schedule|testPush/);

  assert.match(runner, /SingleHotelOperatingDigestService/);
  assert.match(runner, /SingleHotelCollectionPreviewRunService/);
  assert.match(runner, /'--collection-only'/);
  assert.doesNotMatch(runner, /DingdandaoOperatingTargetSyncService/);
  assert.doesNotMatch(runner, /manual-notification:schedule|test-push|WechatRobotDelivery/);
});
