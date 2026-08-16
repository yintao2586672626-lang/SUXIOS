import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const queueService = readFileSync('app/service/CloudThreeSourceCollectionQueueService.php', 'utf8');
const queueRunner = readFileSync('scripts/run_cloud_three_source_collection_queue.php', 'utf8');
const systemdService = readFileSync('deploy/systemd/suxios-cloud-three-source-queue.service', 'utf8');
const systemdTimer = readFileSync('deploy/systemd/suxios-cloud-three-source-queue.timer', 'utf8');
const installer = readFileSync('deploy/systemd/install_cloud_three_source_queue.sh', 'utf8');
const otaRunner = readFileSync('scripts/run_cloud_ota_profile_collection.php', 'utf8');
const pmsRunner = readFileSync('scripts/run_dingdandao_cloud_collection.php', 'utf8');
const meituanCloudPmsRunner = readFileSync('scripts/run_meituan_cloud_pms_collection.php', 'utf8');

test('three-source queue enumerates only active ready plans and executes one serial source order', () => {
  assert.match(queueService, /where\('enabled', 1\)/);
  assert.match(queueService, /where\('active_slot', 1\)/);
  assert.match(queueService, /where\('plan_status', 'active'\)/);
  assert.match(queueService, /where\('validation_status', 'ready'\)/);
  assert.match(queueService, /SOURCE_ORDER\s*=\s*\['pms', 'ctrip', 'meituan'\]/);
  assert.match(queueService, /PROVIDER_DINGDANDAO\s*=>\s*'dingdandao'/);
  assert.match(queueService, /PROVIDER_MEITUAN_CLOUD\s*=>\s*'meituan_cloud_pms'/);
  assert.match(queueService, /run_meituan_cloud_pms_collection\.php/);
  assert.match(queueService, /authorizeExecutionScope/);
  assert.match(queueService, /execution_owner_user_id/);
  assert.match(queueService, /CloudBrowserProfileService::READY_TO_COLLECT/);
  assert.match(queueService, /session_expires_at/);
  assert.match(queueService, /--no-push/);
  assert.match(queueService, /\$pmsCommand\[\] = '--fresh-observation'/);
  assert.match(queueService, /message_sent' => false/);
  assert.doesNotMatch(queueService, /manual-notification:schedule|wechat|webhook/i);
});

test('queue runner holds one nonblocking process-lifetime flock and bounds every child', () => {
  assert.match(queueRunner, /CLOUD_THREE_SOURCE_QUEUE_LOCK/);
  assert.match(queueRunner, /flock\(\$lock, LOCK_EX \| LOCK_NB\)/);
  assert.match(queueRunner, /try \{[\s\S]*CloudThreeSourceCollectionQueueService[\s\S]*\} finally \{[\s\S]*LOCK_UN/);
  assert.match(queueService, /proc_open\(/);
  assert.match(queueService, /SETSID_BINARY\s*=\s*'\/usr\/bin\/setsid'/);
  assert.match(queueService, /posix_kill\(-\$processGroupId, 15\)/);
  assert.match(queueService, /posix_kill\(-\$processGroupId, 9\)/);
  assert.match(queueService, /process_group_cleanup_verified/);
  assert.match(queueService, /\/v1\/collection\/abort/);
  assert.match(queueService, /previous_timeout_cleanup_unverified/);
  assert.match(queueService, /\$timeoutSeconds/);
  assert.match(queueService, /queue_deadline_reached/);
  assert.match(queueService, /continue;/);
});

test('new systemd timer is standalone and does not replace or invoke message dispatch', () => {
  assert.match(systemdService, /Type=oneshot/);
  assert.match(systemdService, /run_cloud_three_source_collection_queue\.php/);
  assert.match(systemdService, /LoadCredential=control-token:/);
  assert.match(systemdService, /RuntimeDirectory=suxios-cloud-three-source-queue/);
  assert.match(systemdService, /RuntimeDirectory=.*suxios-dingdandao-collection/);
  assert.match(systemdService, /RuntimeDirectory=.*suxios-meituan-cloud-pms-collection/);
  assert.match(systemdService, /TimeoutStartSec=30min/);
  assert.doesNotMatch(systemdService, /manual-notification|wechat|webhook/i);
  assert.match(systemdTimer, /OnCalendar=\*-\*-\* \*:30:00 Asia\/Shanghai/);
  assert.match(systemdTimer, /Persistent=false/);
  assert.match(systemdTimer, /Unit=suxios-cloud-three-source-queue\.service/);
});

test('queue installer makes legacy collector shutdown explicit and never starts collection during install', () => {
  const activation = installer.slice(
    installer.indexOf('if ! systemctl enable --now "$TIMER_NAME"'),
    installer.indexOf('echo "INSTALLED_AND_ENABLED')
  );
  const enableQueue = activation.indexOf('systemctl enable --now "$TIMER_NAME"');
  const verifyEnabled = activation.indexOf('systemctl is-enabled --quiet "$TIMER_NAME"');
  const verifyActive = activation.indexOf('systemctl is-active --quiet "$TIMER_NAME"');
  const disableLegacy = activation.indexOf('! disable_legacy_collectors');

  assert.match(installer, /--disable-legacy-collectors/);
  assert.match(installer, /--disable-legacy-collectors requires --enable/);
  assert.match(installer, /systemd-analyze verify/);
  assert.match(installer, /systemctl enable --now "\$TIMER_NAME"/);
  assert.match(installer, /suxios-dingdandao-collection\.timer/);
  assert.match(installer, /suxios-cloud-ota-profile-collection\.timer/);
  assert.ok(enableQueue >= 0 && enableQueue < disableLegacy);
  assert.ok(verifyEnabled > enableQueue && verifyEnabled < disableLegacy);
  assert.ok(verifyActive > enableQueue && verifyActive < disableLegacy);
  assert.match(activation, /systemctl disable --now "\$TIMER_NAME"/);
  assert.match(installer, /restore_legacy_collectors/);
  assert.doesNotMatch(installer, /systemctl start "\$SERVICE_NAME"/);
});

test('both existing collection children accept only the dedicated queue credential path', () => {
  const credentialPath = '/run/credentials/suxios-cloud-three-source-queue.service/control-token';
  assert.match(otaRunner, new RegExp(credentialPath.replaceAll('/', '\\/')));
  assert.match(pmsRunner, new RegExp(credentialPath.replaceAll('/', '\\/')));
  assert.match(meituanCloudPmsRunner, new RegExp(credentialPath.replaceAll('/', '\\/')));
  assert.match(meituanCloudPmsRunner, /'no-push'/);
  assert.match(meituanCloudPmsRunner, /'message_sent' => false/);
  assert.match(meituanCloudPmsRunner, /'disabled_by_invocation' => \$noPush/);
});

test('OTA child reports success only after exact gateway close and profile sealing', () => {
  const closeIndex = otaRunner.indexOf("'/v1/collection/close'");
  const successOutputIndex = otaRunner.lastIndexOf('echo json_encode($result');
  assert.ok(closeIndex > 0);
  assert.ok(successOutputIndex > closeIndex);
  assert.match(otaRunner, /\$closed\['status'\][\s\S]{0,120}'collection_closed'/);
  assert.match(otaRunner, /\$closed\['profile_sealed'\][\s\S]{0,80}true/);
  assert.match(otaRunner, /\$closed\['browser_started'\][\s\S]{0,80}false/);
  assert.match(otaRunner, /cloud_ota_collection_profile_close_unverified/);
  assert.match(otaRunner, /\$result = null/);
});

test('OTA child prefers the managed cloud profile binding over legacy platform identifiers', () => {
  const helper = otaRunner.slice(
    otaRunner.indexOf('function profileBindingKey'),
    otaRunner.indexOf('function firstValue'),
  );
  const branches = [...helper.matchAll(/\[([^\]]+)\]/g)].map((match) => (
    [...match[1].matchAll(/'([^']+)'/g)].map((entry) => entry[1])
  ));
  const managedKeys = ['profile_binding_key', 'profileBindingKey', 'stable_profile_id', 'stableProfileId'];

  assert.equal(branches.length, 2);
  assert.deepEqual(branches[0].slice(0, managedKeys.length), managedKeys);
  assert.deepEqual(branches[1].slice(0, managedKeys.length), managedKeys);
  for (const legacyKey of ['store_id', 'poi_id', 'profile_id']) {
    assert.ok(branches[0].indexOf(legacyKey) > branches[0].indexOf('stableProfileId'));
  }
  for (const legacyKey of ['profile_id', 'browser_profile_id']) {
    assert.ok(branches[1].indexOf(legacyKey) > branches[1].indexOf('stableProfileId'));
  }
});
