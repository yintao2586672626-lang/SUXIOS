import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Molanxin runner collects both OTA sources before PMS and stays preview only', async () => {
  const runner = await read('scripts/run_molanxin_three_source_collection.php');

  assert.match(runner, /MOLANXIN_THREE_SOURCE_HOTEL_ID = 5/);
  assert.match(runner, /MOLANXIN_THREE_SOURCE_PLATFORMS = \['ctrip', 'meituan'\]/);
  assert.match(runner, /'online-data:auto-fetch',[\s\S]*?'--realtime-only'/);
  assert.match(runner, /'--collector-mode=single_user_local'/);
  assert.match(runner, /'--collector-user-id=' \. \$otaCollectorUserId/);
  assert.match(runner, /'--collector-device-id=' \. \$otaCollectorDeviceId/);
  assert.match(runner, /'--hotel-id=' \. MOLANXIN_THREE_SOURCE_HOTEL_ID/);
  assert.match(runner, /'--source-ids=' \. \$sourceIdArgument/);
  assert.match(runner, /'--platforms=' \. \$platformArgument/);
  assert.match(runner, /SUXIOS_AUTO_FETCH_RECEIPT=/);
  assert.match(runner, /molanxinThreeSourceReceiptReady/);
  assert.match(runner, /collection_complete/);
  assert.match(runner, /dual_ota_p0_complete/);

  const otaIndex = runner.indexOf("'online-data:auto-fetch'");
  const pmsIndex = runner.indexOf("'/scripts/run_molanxin_collection_preview.php'");
  assert.ok(otaIndex >= 0 && pmsIndex > otaIndex, 'OTA must run before PMS');
  assert.match(runner, /PMS collection is deliberately attempted even when either OTA collection/);
  assert.match(runner, /'status' => \$overallStatus/);
  assert.match(runner, /'strict_ready' => \$strictReady/);
  assert.match(runner, /\$sourceReadiness\['pms'\]/);
  assert.match(runner, /\$sourceReadiness\['ctrip'\]/);
  assert.match(runner, /\$sourceReadiness\['meituan'\]/);
  assert.doesNotMatch(
    runner,
    /\$digestThreeSourcesReady = \(string\)\(\$pmsOutput\['digest_status'\]/,
  );
  assert.match(runner, /'partial'/);
  assert.match(runner, /run_readback_status/);
  assert.match(runner, /capture_id/);
  assert.match(runner, /'preview_only' => true/);
  assert.match(runner, /'dispatch_requested' => false/);
  assert.match(runner, /'message_sent' => false/);
  assert.match(runner, /'webhook_read' => false/);
  assert.doesNotMatch(
    runner,
    /WechatRobotDelivery|manual-notification:schedule|testPush|competitor_wechat_robot/,
  );
});

test('Molanxin systemd assets are unique and never operate existing units', async () => {
  const [service, timer, envExample, runner] = await Promise.all([
    read('deploy/systemd/suxios-molanxin-three-source-collection.service'),
    read('deploy/systemd/suxios-molanxin-three-source-collection.timer'),
    read('deploy/systemd/molanxin-three-source-collection.env.example'),
    read('scripts/run_molanxin_three_source_collection.php'),
  ]);
  const assets = `${service}\n${timer}\n${envExample}`;

  assert.match(service, /^Type=oneshot$/m);
  assert.match(
    service,
    /^WorkingDirectory=\/var\/www\/suxios\/molanxin-three-source-current$/m,
  );
  assert.doesNotMatch(service, /\/var\/www\/suxios\/current(?:\/|\s|$)/);
  assert.match(service, /^Environment=SUXIOS_OTA_CLOUD_COLLECTOR=1$/m);
  assert.match(service, /^RuntimeDirectory=suxios-molanxin-three-source-collection$/m);
  assert.match(service, /ExecStartPre=.*online-data:auto-fetch --validate-cloud-scope/);
  assert.match(service, /ExecStartPre=.*--hotel-id=\$\{SUXIOS_MOLANXIN_HOTEL_ID\}/);
  assert.match(service, /ExecStartPre=.*--source-ids=\$\{SUXIOS_MOLANXIN_OTA_SOURCE_IDS\}/);
  assert.match(service, /ExecStartPre=.*--platforms=ctrip,meituan/);
  assert.match(
    service,
    /LoadCredential=control-token:\/etc\/suxios-cloud-browser\/control-token/,
  );
  assert.match(
    service,
    /--control-token-file=\/run\/credentials\/suxios-molanxin-three-source-collection\.service\/control-token/,
  );
  assert.match(
    service,
    /--runtime-directory=\/run\/suxios-molanxin-three-source-collection/,
  );
  const serviceTimeoutMinutes = Number(
    service.match(/^TimeoutStartSec=(\d+)min$/m)?.[1] || 0,
  );
  const otaTimeoutSeconds = Number(
    (runner.match(
      /\$otaProcess\s*=\s*molanxinThreeSourceRunProcess\([\s\S]*?\], \$root, ([\d_]+)\);/,
    )?.[1] || '0').replaceAll('_', ''),
  );
  const pmsTimeoutSeconds = Number(
    (runner.match(
      /\$pmsProcess\s*=\s*molanxinThreeSourceRunProcess\([\s\S]*?\], \$root, ([\d_]+)\);/,
    )?.[1] || '0').replaceAll('_', ''),
  );
  assert.ok(serviceTimeoutMinutes >= 45);
  assert.ok(serviceTimeoutMinutes * 60 > otaTimeoutSeconds + pmsTimeoutSeconds);
  assert.match(service, /run_molanxin_three_source_collection\.php/);
  assert.doesNotMatch(service, /^\[Install\]$/m);
  assert.doesNotMatch(service, /^Requires=suxios-cloud-browser-gateway\.service$/m);

  assert.match(timer, /^Unit=suxios-molanxin-three-source-collection\.service$/m);
  assert.match(timer, /^Persistent=false$/m);
  assert.match(timer, /^OnCalendar=\*-\*-\* \*:50:00 Asia\/Shanghai$/m);
  assert.doesNotMatch(assets, /suxios-dingdandao-(?:collection|notification)/);
  assert.doesNotMatch(assets, /suxios-cloud-ota-(?:daily|realtime)/);
  assert.doesNotMatch(assets, /systemctl\s+(?:enable|start|stop|restart|disable|daemon-reload)/);

  assert.match(envExample, /^SUXIOS_MOLANXIN_HOTEL_ID=5$/m);
  assert.match(envExample, /^SUXIOS_MOLANXIN_OTA_SOURCE_IDS=5,6$/m);
  assert.match(envExample, /SUXIOS_MOLANXIN_OTA_COLLECTOR_USER_ID=/);
  assert.match(envExample, /SUXIOS_MOLANXIN_OTA_COLLECTOR_DEVICE_ID=/);
  assert.doesNotMatch(
    envExample,
    /^(?:.*(?:COOKIE|TOKEN|PASSWORD|SECRET|AUTHORIZATION|WEBHOOK).*)=/im,
  );
});
