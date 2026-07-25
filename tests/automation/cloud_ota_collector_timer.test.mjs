import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const files = {
  dailyService: '../../deploy/systemd/suxios-cloud-ota-daily.service',
  dailyTimer: '../../deploy/systemd/suxios-cloud-ota-daily.timer',
  realtimeService: '../../deploy/systemd/suxios-cloud-ota-realtime.service',
  realtimeTimer: '../../deploy/systemd/suxios-cloud-ota-realtime.timer',
};

async function source(name) {
  return readFile(new URL(files[name], import.meta.url), 'utf8');
}

test('cloud OTA daily collection runs yesterday only once at 08:30', async () => {
  const [service, timer] = await Promise.all([source('dailyService'), source('dailyTimer')]);
  assert.match(service, /online-data:auto-fetch --daily-only --no-interaction/);
  assert.doesNotMatch(service, /realtime-only/);
  assert.match(timer, /OnCalendar=\*-\*-\* 08:30:00 Asia\/Shanghai/);
  assert.match(timer, /Persistent=false/);
});

test('cloud OTA realtime collection is hourly, isolated, and credential-free', async () => {
  const [service, timer] = await Promise.all([source('realtimeService'), source('realtimeTimer')]);
  assert.match(service, /online-data:auto-fetch --realtime-only --no-interaction/);
  assert.doesNotMatch(service, /daily-only/);
  assert.match(service, /Conflicts=suxios-cloud-ota-daily\.service/);
  assert.match(service, /CPUQuota=60%/);
  assert.match(service, /MemoryMax=512M/);
  assert.match(service, /TimeoutStartSec=25min/);
  assert.doesNotMatch(service, /(cookie|token|password|authorization|webhook)\s*=/i);
  assert.match(timer, /OnCalendar=\*-\*-\* \*:05:00 Asia\/Shanghai/);
  assert.match(timer, /Persistent=false/);
});
