import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = (path) => fs.readFileSync(path, 'utf8');

test('three-source collectors finish before the exact-hour formal dispatch', () => {
  const ding = read('deploy/systemd/suxios-dingdandao-collection.timer');
  const ota = read('deploy/systemd/suxios-cloud-ota-profile-collection.timer');
  const formal = read('deploy/systemd/suxios-manual-notification-formal-dispatch.timer');
  const dingService = read('deploy/systemd/suxios-dingdandao-collection.service');
  const otaService = read('deploy/systemd/suxios-cloud-ota-profile-collection.service');
  const installer = read('deploy/systemd/install_cloud_three_source_hourly.sh');
  const formalVerifier = read('deploy/systemd/verify_manual_notification_formal_dispatch.php');

  assert.match(ding, /OnCalendar=\*-\*-\* \*:35:00 Asia\/Shanghai/);
  assert.match(ota, /OnCalendar=\*-\*-\* \*:40:00 Asia\/Shanghai/);
  assert.match(formal, /OnCalendar=\*-\*-\* \*:\*:00/);
  assert.match(ding, /Persistent=false/);
  assert.match(ota, /Persistent=false/);
  assert.match(dingService, /run_dingdandao_cloud_collection\.php[\s\S]*--no-push/);
  assert.match(otaService, /run_cloud_ota_profile_collection_batch\.php/);
  assert.equal(fs.existsSync('scripts/run_dingdandao_cloud_collection.php'), true);
  assert.equal(fs.existsSync('scripts/run_cloud_ota_profile_collection.php'), true);
  assert.equal(fs.existsSync('scripts/run_cloud_ota_profile_collection_batch.php'), true);
  assert.match(installer, /scripts\/run_dingdandao_cloud_collection\.php/);
  assert.match(installer, /scripts\/run_cloud_ota_profile_collection\.php/);
  assert.match(installer, /scripts\/run_cloud_ota_profile_collection_batch\.php/);
  assert.match(installer, /install_manual_notification_formal_dispatch\.sh/);
  assert.match(installer, /systemctl disable --now "\$LEGACY_TIMER"/);
  assert.match(installer, /suxios-cloud-ota-profile-collection\.timer/);
  assert.match(formalVerifier, /isStrictThreeSourceHourlyPlan/);
});

test('OTA batch retries only explicit transient gateway failures with bounded backoff', () => {
  const batch = read('scripts/run_cloud_ota_profile_collection_batch.php');

  assert.match(batch, /OTA_BATCH_MAX_TRANSIENT_RETRIES = 2/);
  assert.match(batch, /OTA_BATCH_RETRY_DELAYS_SECONDS = \[30, 90\]/);
  assert.match(batch, /gateway_collection_capacity_busy/);
  assert.match(batch, /gateway_temporarily_unavailable/);
  assert.match(batch, /gateway_connection_timeout/);
  assert.match(batch, /\$reason !== 'cloud_ota_gateway_failed'/);
  assert.match(batch, /connection\\s\+\(\?:timed\\s\*out\|refused\)/);
  assert.match(batch, /\$receipt\['business_data_persisted'\][\s\S]{0,80}return false/);
  assert.match(batch, /sleep\(\$delay\)/);
  assert.match(batch, /'execution_mode' => 'serial'/);
  assert.match(batch, /'message_sent' => false/);

  const transientCodes = batch.match(
    /const OTA_BATCH_TRANSIENT_REASON_CODES = \[([\s\S]*?)\];/,
  )?.[1] || '';
  for (const forbidden of [
    'session_expired',
    'login',
    'identity',
    'hotel_mismatch',
    'policy',
    'readback',
    'save_failed',
  ]) {
    assert.doesNotMatch(transientCodes, new RegExp(forbidden));
  }
});

test('hourly payload rejects any stale contributing OTA row', () => {
  const service = read('app/service/CloudThreeSourceHourlyPayloadService.php');
  assert.match(
    service,
    /private function taskRows\([\s\S]*DateTimeImmutable \$now/,
  );
  assert.match(
    service,
    /\$this->recentTimestamp\(\s*\$this->storedRowCapturedAt\(\$row\),\s*\$now/,
  );
  assert.match(service, /\$row\['snapshot_time'\]/);
  assert.match(service, /formal_readback_rows_missing_or_stale/);
});
