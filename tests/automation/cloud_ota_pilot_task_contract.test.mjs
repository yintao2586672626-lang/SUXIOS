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
  assert.match(runner, /name = 'application_health'; passed = \$healthPassed/);
  assert.match(runner, /name = 'source_binding_match'; passed = \$sourceIdsMatchBinding/);
  assert.match(runner, /run_ready = \$preflightFailures\.Count -eq 0/);
  assert.match(runner, /\[switch\]\$ForceRerun/);
  assert.match(runner, /\$collectorArguments = @\([\s\S]*?'online-data:auto-fetch'[\s\S]*?\"--hotel-id=\$HotelId\"[\s\S]*?\"--target-date=\$TargetDate\"[\s\S]*?\"--source-ids=\$normalizedSourceIds\"[\s\S]*?\)/);
  assert.match(runner, /if \(\$ForceRerun\) \{[\s\S]*?\$collectorArguments \+= '--force-rerun'/);
  assert.match(runner, /\$collectorOutput = & \$resolvedPhpPath \$thinkPath @collectorArguments/);
});

test('pilot runner exports only verified bundles and blocks credential-shaped fields', () => {
  const collectorIndex = runner.indexOf("'online-data:auto-fetch'");
  const collectionFailureIndex = runner.indexOf("status = 'collection_failed'");
  const exportIndex = runner.indexOf("'cloud-data-bridge:run'", collectorIndex);
  const uploadIndex = runner.indexOf('upload_cloud_ota_bundle.ps1');
  assert.ok(collectorIndex >= 0 && collectionFailureIndex > collectorIndex && exportIndex > collectionFailureIndex);
  assert.ok(uploadIndex >= 0);
  assert.match(runner, /cloud-data-bridge:run/);
  assert.match(runner, /SUXIOS_AUTO_FETCH_RECEIPT=/);
  assert.match(runner, /exportable_snapshot_complete/);
  assert.match(runner, /snapshot_exportable/);
  assert.match(runner, /collection_status/);
  assert.match(runner, /p0_status -ne 'ready'/);
  assert.match(runner, /required_platforms/);
  assert.match(runner, /Compare-Object -ReferenceObject @\('ctrip', 'meituan'\)/);
  assert.match(runner, /--sync-task-ids=\$syncTaskIds/);
  assert.match(runner, /cloud-data-bridge-binding\.pilot-h80\.json/);
  assert.match(runner, /suxios\.cloud_ota_bundle\.v1/);
  assert.match(runner, /cookie\|cookies\|authorization\|token\|password\|webhook\|secret/);
  assert.match(runner, /upload_cloud_ota_bundle\.ps1/);
  assert.match(runner, /already_uploaded/);
  assert.match(runner, /status = 'collection_failed'/);
  assert.doesNotMatch(runner, /uploaded_with_collection_failure/);
  assert.match(runner, /Cloud bundle package receipt validation failed/);
  assert.match(runner, /source_sync_task_id/);
  assert.match(runner, /snapshot_complete/);
  assert.match(runner, /collection\.status -eq 'success' -and -not \[bool\]\$_\.snapshot_complete/);
  assert.match(runner, /upload_complete = \$true/);
  assert.match(runner, /upload_complete = \$false/);
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
  assert.match(registration, /-SourceIds \"\{2\}\"/);
  assert.match(registration, /\$bindingSourceIds -join ','/);
  assert.match(registration, /credentials_in_arguments = \$false/);
  assert.doesNotMatch(registration, /-IdentityFile/);
  assert.doesNotMatch(registration, /\.pem/);
  assert.doesNotMatch(registration, /-ForceRerun/);
});
