import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../..', import.meta.url);
const read = (name) => readFileSync(new URL(`deploy/systemd/${name}`, root), 'utf8');

test('formal hourly monitor is opt-in and cannot reuse the test-group route', () => {
  const service = read('suxios-cloud-hotel-monitor-formal.service');
  const timer = read('suxios-cloud-hotel-monitor-formal.timer');

  assert.match(service, /SUXIOS_MONITOR_FORMAL_ENABLED/);
  assert.match(service, /SUXIOS_MONITOR_FORMAL_ROBOT_ID/);
  assert.match(service, /--robot-id=\$\$\{SUXIOS_MONITOR_FORMAL_ROBOT_ID\}/);
  assert.doesNotMatch(service, /--test-robot-id/);
  assert.match(service, /--with-visual-card/);
  assert.match(service, /CPUQuota=30%/);
  assert.match(timer, /OnCalendar=hourly/);
  assert.match(timer, /Persistent=true/);
});
