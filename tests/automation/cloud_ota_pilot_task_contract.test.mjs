import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_cloud_ota_pilot.ps1'), 'utf8');
const registration = readFileSync(path.join(repoRoot, 'scripts', 'register_cloud_ota_pilot_task.ps1'), 'utf8');

test('pilot runner defaults to a non-mutating plan and fixes hotel/date scope', () => {
  assert.match(runner, /DefaultParameterSetName = 'Plan'/);
  assert.match(runner, /\[int\]\$HotelId = 80/);
  assert.match(runner, /\[string\]\$SourceIds = '25,68'/);
  assert.match(runner, /\$expectedTargetDate/);
  assert.match(runner, /--hotel-id=\$HotelId/);
  assert.match(runner, /--target-date=\$TargetDate/);
  assert.match(runner, /if \(-not \$Run\)[\s\S]*?return/);
  assert.match(
    runner,
    /\$collectorOutput = & \$resolvedPhpPath \$thinkPath 'online-data:auto-fetch' \"--hotel-id=\$HotelId\" \"--target-date=\$TargetDate\" \"--source-ids=\$SourceIds\"/,
  );
});

test('pilot runner exports only verified bundles and blocks credential-shaped fields', () => {
  assert.match(runner, /cloud-data-bridge:run/);
  assert.match(runner, /cloud-data-bridge-binding\.pilot-h80\.json/);
  assert.match(runner, /suxios\.cloud_ota_bundle\.v1/);
  assert.match(runner, /cookie\|cookies\|authorization\|token\|password\|webhook\|secret/);
  assert.match(runner, /upload_cloud_ota_bundle\.ps1/);
  assert.match(runner, /already_uploaded/);
  assert.match(runner, /collector_exit_code/);
});

test('pilot task requires explicit enable and registers three bounded triggers without starting', () => {
  assert.match(registration, /DefaultParameterSetName = 'Plan'/);
  assert.match(registration, /\$triggerTimes = @\('06:00', '06:15', '06:30'\)/);
  assert.match(registration, /New-ScheduledTaskTrigger -Daily -At \$_/);
  assert.match(registration, /-MultipleInstances IgnoreNew/);
  assert.match(registration, /-ExecutionTimeLimit \(New-TimeSpan -Hours 2\)/);
  assert.match(registration, /starts_task_immediately = \$false/);
  assert.doesNotMatch(registration, /Start-ScheduledTask/);
  assert.match(registration, /Register-ScheduledTask @registrationParameters/);
});

test('pilot task arguments contain fixed hotel scope but no credential locator', () => {
  assert.match(registration, /-Run -HotelId \{1\}/);
  assert.match(registration, /credentials_in_arguments = \$false/);
  assert.doesNotMatch(registration, /-IdentityFile/);
  assert.doesNotMatch(registration, /\.pem/);
});
