import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');
const page = read('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html');
const appMain = read('public/app-main.js');
const service = read('app/service/PmsRealtimeSyncService.php');

test('PMS page separates saved readback from explicit realtime synchronization', () => {
  assert.match(page, /data-testid="pms-operating-data-load"[\s\S]*读取已保存数据/);
  assert.match(page, /data-testid="pms-operating-data-live-sync"[\s\S]*syncOperatingPmsRealtime/);
  assert.match(page, /data-testid="pms-realtime-sync-result"/);
  assert.match(appMain, /const operatingPmsRealtimeActionText = computed[\s\S]*切到今日并实时同步/);
  assert.match(appMain, /apiRequest\('\/operating-targets\/pms\/realtime-sync'/);
  assert.match(appMain, /loadOperatingTarget\(\{ preserveRealtimeResult: true \}\)/);
});

test('realtime PMS sync requires isolated session and never triggers push delivery', () => {
  assert.match(service, /--sandbox-id=/);
  assert.match(service, /--require-sandbox/);
  assert.match(service, /readback_status[\s\S]*readback_verified/);
  assert.doesNotMatch(service, /'--push'/);
});
