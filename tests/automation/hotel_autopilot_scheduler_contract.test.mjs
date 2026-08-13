import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '../..');
const provisioner = fs.readFileSync(
  path.join(root, 'scripts/provision_hotel_autopilot_dispatcher.ps1'),
  'utf8',
);
const coordinatorRunner = fs.readFileSync(
  path.join(root, 'scripts/run_hotel_autopilot_coordinator.ps1'),
  'utf8',
);
const coordinatorRegistration = fs.readFileSync(
  path.join(root, 'scripts/register_hotel_autopilot_coordinator_task.ps1'),
  'utf8',
);
const coordinatorCommand = fs.readFileSync(
  path.join(root, 'app/command/ReconcileHotelAutopilotLifecycle.php'),
  'utf8',
);

test('hotel dispatcher provisioner reuses the protected registration path and emits one strict safe receipt', () => {
  assert.equal(
    provisioner.match(/SUXIOS_HOTEL_AUTOPILOT_DISPATCHER=/g)?.length,
    1,
  );
  assert.match(provisioner, /suxios_hotel_autopilot_dispatcher\.v1/);
  assert.match(provisioner, /register_ota_dispatcher_task\.ps1/);
  assert.match(provisioner, /Enable\s*=\s*\$true/);
  assert.match(provisioner, /ReplaceExisting/);
  assert.doesNotMatch(provisioner, /AllowScopeReduction/);
  assert.match(provisioner, /Get-ScheduledTask -TaskName \$taskName -TaskPath \$taskPath/);
  assert.match(provisioner, /SUXIOS OTA Dispatcher H\$HotelId/);
  assert.match(provisioner, /scope_verified\s*=\s*\$false/);
  assert.match(provisioner, /action_verified\s*=\s*\$false/);
  assert.match(provisioner, /trigger_verified\s*=\s*\$false/);
  assert.match(provisioner, /principal_verified\s*=\s*\$false/);
  assert.match(provisioner, /enabled_verified\s*=\s*\$false/);
  assert.match(provisioner, /Test-LocalPrincipalEquals/);
  assert.match(provisioner, /Test-Iso8601DurationEquals/);
  assert.match(provisioner, /System\.Xml\.XmlConvert.*ToTimeSpan/);
  assert.match(
    provisioner,
    /Test-Iso8601DurationEquals -Actual \$repetitionDuration -Expected 'PT85M'/,
  );
  assert.doesNotMatch(provisioner, /\$repetitionDuration\s+-eq\s+'PT85M'/);
  assert.match(provisioner, /\$settings\s*=\s*\$task\.Settings/);
  assert.match(provisioner, /\[bool\]\$settings\.Enabled/);
  assert.match(provisioner, /automatic_repair_allowed/);
  assert.match(provisioner, /-and -not \$enabledVerified/);
  assert.match(provisioner, /existing_task_scope_mismatch/);
  assert.match(
    provisioner,
    /if \(-not \$automaticRepairAllowed -and -not \$ReplaceExisting\)/,
  );
  assert.match(provisioner, /\$receipt\.enabled\s*=\s*\$readback\.enabled_verified/);
  assert.match(provisioner, /readback_verified\s*=\s*\$false/);
  assert.match(provisioner, /sensitive_values_exposed\s*=\s*\$false/);
  assert.match(provisioner, /status\s*=\s*'ready'/);
  assert.match(provisioner, /status\s*=\s*'blocked'/);
  assert.match(provisioner, /Start-ScheduledTask -TaskName \$taskName -TaskPath \$taskPath/);
  assert.match(provisioner, /if \(\$StartNow\)/);
  assert.doesNotMatch(provisioner, /cookie|authorization\s*:|password\s*=|token\s*=/i);
});

test('hotel dispatcher readback compares ISO durations semantically and only auto-repairs an exact disabled scope', () => {
  assert.match(
    provisioner,
    /\$actualDuration\s*=\s*\[System\.Xml\.XmlConvert\]::ToTimeSpan\(\$Actual\)/,
  );
  assert.match(
    provisioner,
    /\$expectedDuration\s*=\s*\[System\.Xml\.XmlConvert\]::ToTimeSpan\(\$Expected\)/,
  );
  assert.match(
    provisioner,
    /Test-Iso8601DurationEquals -Actual \$repetitionInterval -Expected 'PT14M'/,
  );
  assert.match(
    provisioner,
    /Test-Iso8601DurationEquals -Actual \$repetitionDuration -Expected 'PT85M'/,
  );
  assert.match(
    provisioner,
    /\$automaticRepairAllowed\s*=\s*\$actionVerified[\s\S]{0,240}-and \$scopeVerified[\s\S]{0,240}-and \$triggerVerified[\s\S]{0,240}-and \$principalVerified[\s\S]{0,240}-and -not \$enabledVerified/,
  );
  assert.match(
    provisioner,
    /readback_verified\s*=\s*\[bool\]\(\$scopeVerified[\s\S]{0,240}-and \$actionVerified[\s\S]{0,240}-and \$triggerVerified[\s\S]{0,240}-and \$principalVerified[\s\S]{0,240}-and \$enabledVerified\)/,
  );
  assert.match(
    provisioner,
    /if \(-not \$initialReadback\.scope_verified\) \{\s*throw 'existing_task_scope_mismatch'/,
  );
  assert.match(
    provisioner,
    /if \(\$null -ne \$initialReadback\.task -and \(\$automaticRepairAllowed -or \$ReplaceExisting\)\)/,
  );
  assert.doesNotMatch(provisioner, /AllowScopeReduction/);
});

test('coordinator runner serializes by project and preserves the reconcile command exit code', () => {
  assert.match(coordinatorRunner, /System\.Threading\.Mutex/);
  assert.match(coordinatorRunner, /WaitOne\(0\)/);
  assert.match(coordinatorRunner, /hotel:autopilot-reconcile/);
  assert.match(coordinatorRunner, /--all-pages/);
  assert.match(coordinatorRunner, /--provision-dispatchers/);
  assert.match(coordinatorCommand, /addOption\('all-pages'/);
  assert.match(coordinatorCommand, /do \{/);
  assert.match(coordinatorCommand, /while \(\$allPages && \$hasNextPage\)/);
  assert.match(coordinatorCommand, /scanned_hotel_count/);
  assert.match(coordinatorRunner, /\$commandOutput\s*=\s*@\(& \$resolvedPhpPath/);
  assert.match(coordinatorRunner, /\$commandExitCode\s*=\s*\[int\]\$LASTEXITCODE/);
  assert.match(coordinatorRunner, /\$exitCode\s*=\s*\$commandExitCode/);
  assert.match(coordinatorRunner, /exit \$exitCode/);
  assert.match(coordinatorRunner, /sensitive_values_exposed\s*=\s*\$false/);
  assert.doesNotMatch(coordinatorRunner, /Start-Process/);
});

test('coordinator registration is opt-in, hidden, interactive, five-minute, and exact-readback gated', () => {
  assert.match(coordinatorRegistration, /\[switch\]\$Enable/);
  assert.match(coordinatorRegistration, /if \(-not \$Enable\)/);
  assert.match(coordinatorRegistration, /SUXIOS Hotel Autopilot Coordinator/);
  assert.match(coordinatorRegistration, /Interval\s*=\s*'PT5M'/);
  assert.match(coordinatorRegistration, /Duration\s*=\s*'P1D'/);
  assert.match(coordinatorRegistration, /New-ScheduledTaskTrigger -Daily/);
  assert.match(coordinatorRegistration, /MultipleInstances IgnoreNew/);
  assert.match(coordinatorRegistration, /-LogonType Interactive/);
  assert.match(coordinatorRegistration, /-RunLevel Limited/);
  assert.match(coordinatorRegistration, /-WindowStyle Hidden/);
  assert.match(coordinatorRegistration, /-Hidden/);
  assert.match(coordinatorRegistration, /Get-ScheduledTask -TaskName \$taskName -TaskPath \$taskPath/);
  assert.match(coordinatorRegistration, /\$settings\s*=\s*\$task\.Settings/);
  assert.match(coordinatorRegistration, /\[bool\]\$settings\.Enabled/);
  assert.match(coordinatorRegistration, /enabled_verified\s*=\s*\$false/);
  assert.match(
    coordinatorRegistration,
    /automatic_repair_allowed\s*=\s*\[bool\]\(\$structureVerified -and -not \$enabledVerified\)/,
  );
  assert.match(
    coordinatorRegistration,
    /if \(\$initialReadback\.automatic_repair_allowed\) \{\s*Enable-ScheduledTask/,
  );
  assert.match(
    coordinatorRegistration,
    /readback_verified\s*=\s*\[bool\]\(\$structureVerified -and \$enabledVerified\)/,
  );
  assert.match(coordinatorRegistration, /\$receipt\.enabled\s*=\s*\$readback\.enabled_verified/);
  assert.match(coordinatorRegistration, /readback_verified/);
  assert.match(coordinatorRegistration, /Test-LocalPrincipalEquals/);
  assert.match(coordinatorRegistration, /if \(\$StartNow\)/);
  assert.match(coordinatorRegistration, /Start-ScheduledTask -TaskName \$taskName -TaskPath \$taskPath/);
  assert.match(coordinatorRegistration, /sensitive_values_exposed\s*=\s*\$false/);
  assert.doesNotMatch(coordinatorRegistration, /cookie|authorization\s*:|password\s*=|token\s*=/i);
});
