import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const runner = fs.readFileSync(
  path.join(root, 'scripts/run_hotel80_three_source_wecom.ps1'),
  'utf8',
);
const registrar = fs.readFileSync(
  path.join(root, 'scripts/register_hotel80_three_source_wecom_task.ps1'),
  'utf8',
);

test('runner is fixed to hotel 80 formal robot 1 and persists only a safe receipt', () => {
  assert.match(runner, /manual-notification:schedule/);
  assert.match(runner, /--dispatch/);
  assert.match(runner, /--mode=formal/);
  assert.match(runner, /--hotel-id=80/);
  assert.match(runner, /--robot-id=1/);
  assert.match(runner, /SUXIOS_THREE_SOURCE_WECOM=/);
  assert.match(runner, /raw_output_persisted\s*=\s*\$false/);
  assert.match(runner, /sensitive_values_exposed\s*=\s*\$false/);
  assert.doesNotMatch(runner, /config_json|secret_json|source_snapshot_refs_json/);
});

test('task repeats every 30 minutes without overlapping source collection', () => {
  assert.match(registrar, /SUXIOS Three Source WeCom H80/);
  assert.match(registrar, /Interval\s*=\s*'PT30M'/);
  assert.match(registrar, /Duration\s*=\s*'P1D'/);
  assert.match(registrar, /ExecutionTimeLimit \(New-TimeSpan -Minutes 28\)/);
  assert.match(registrar, /MultipleInstances IgnoreNew/);
  assert.match(registrar, /-LogonType Interactive/);
  assert.match(registrar, /-WakeToRun/);
  assert.match(registrar, /-StartWhenAvailable/);
  assert.match(registrar, /dingdandao_pms/);
  assert.match(registrar, /ctrip:25/);
  assert.match(registrar, /meituan:68/);
  assert.doesNotMatch(registrar, /Start-ScheduledTask/);
});
