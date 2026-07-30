import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const scriptPath = path.join(repoRoot, 'scripts', 'register_ota_dispatcher_task.ps1');
const packagePath = path.join(repoRoot, 'package.json');
const source = readFileSync(scriptPath, 'utf8');
const packageJson = JSON.parse(readFileSync(packagePath, 'utf8'));

test('OTA dispatcher registration defaults to a non-mutating plan', () => {
  assert.match(source, /DefaultParameterSetName = 'Plan'/);
  assert.match(source, /if \(-not \$Enable\) \{[\s\S]*?Write-DispatcherPlan -Plan \$plan[\s\S]*?return[\s\S]*?\}/);
  assert.match(source, /mutation_requested = \[bool\]\(\$Enable -or \$Unregister\)/);
  assert.match(source, /starts_task_immediately = \$false/);
  assert.doesNotMatch(source, /Start-ScheduledTask/);
});

test('registration requires explicit enable and all safety preflights', () => {
  const planGuard = source.indexOf('if (-not $Enable) {');
  const preflightGuard = source.indexOf('if ($preflightFailures.Count -gt 0) {');
  const registerCall = source.indexOf('Register-ScheduledTask @registrationParameters');

  assert(planGuard >= 0 && preflightGuard > planGuard && registerCall > preflightGuard);
  assert.match(source, /\[Parameter\(Mandatory = \$true, ParameterSetName = 'Enable'\)\][\s\S]*?\[switch\]\$Enable/);
  assert.match(source, /Test-Path -LiteralPath \$thinkPath -PathType Leaf/);
  assert.match(source, /'online-data:auto-fetch' => 'app\\command\\AutoFetchOnlineData'/);
  assert.match(source, /Invoke-WebRequest -Uri \$HealthUrl -Method Get -UseBasicParsing -TimeoutSec 5/);
  assert.match(source, /\$uri\.IsLoopback/);
  assert.match(source, /credential_free_arguments/);
});

test('task runs only as the current interactive user with bounded execution', () => {
  assert.match(source, /\[Environment\]::UserInteractive/);
  assert.match(source, /GetCurrentProcess\(\)\.SessionId -le 0/);
  assert.match(source, /return \$normalized -eq \$currentUser\.Trim\(\)\.ToUpperInvariant\(\)/);
  assert.match(source, /\^\(NT AUTHORITY\|NT SERVICE\|BUILTIN\)\\\\/);
  assert.match(source, /SYSTEM\|LOCAL SYSTEM\|LOCAL SERVICE\|NETWORK SERVICE/);
  assert.match(source, /-LogonType Interactive/);
  assert.match(source, /-RunLevel Limited/);
  assert.match(source, /-MultipleInstances IgnoreNew/);
  assert.match(source, /New-TimeSpan -Hours 2/);
  assert.match(source, /New-TimeSpan -Minutes 25/);
  assert.doesNotMatch(source, /\[string\]\$Password|\[securestring\]|-Password\b/i);
});

test('task arguments are fixed and credential-shaped values are rejected', () => {
  assert.match(source, /\$dispatcherCommand = 'online-data:auto-fetch'/);
  assert.match(source, /\$actionArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File/);
  assert.match(source, /\$dispatcherRunnerPath/);
  assert.match(source, /-HotelId \{0\} -SourceIds "\{1\}" -Platforms "\{2\}"/);
  assert.match(source, /scope_boundary/);
  assert.match(source, /external_delivery = \$false/);
  assert.match(source, /visible_window_expected = \$false/);
  assert.match(source, /-Hidden/);
  assert.match(source, /cookie\|token\|password\|authorization\|spidertoken\|secret\|session\|credential/i);
  assert.match(source, /Authorized local-profile OTA (?:realtime |daily )?dispatcher/);
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  assert.match(runner, /ValidateSet\('Daily', 'Realtime'\)/);
  assert.match(runner, /\$scheduleArgument = if \(\$Mode -eq 'Realtime'\) \{ '--realtime-only' \} else \{ '--daily-only' \}/);
  assert.match(runner, /--hotel-id=\$HotelId/);
  assert.match(runner, /--source-ids=\$SourceIds/);
  assert.match(runner, /--platforms=\$Platforms/);
  assert.match(runner, /Scoped OTA dispatcher requires HotelId, SourceIds, and Platforms together/);
});

test('realtime dispatcher is an independent hourly task with the same credential boundary', () => {
  assert.match(source, /\[switch\]\$Realtime/);
  assert.match(source, /SUXIOS OTA Realtime Dispatcher/);
  assert.match(source, /-Mode \{3\}/);
  assert.match(source, /0\.\.23 \| ForEach-Object/);
  assert.match(source, /New-ScheduledTaskTrigger -Daily -At/);
  assert.match(source, /hourly at/);
  assert.match(source, /-MultipleInstances IgnoreNew/);
});

test('unregistration is fixed-scope and requires explicit double confirmation', () => {
  const confirmationGuard = source.indexOf('if (-not $ConfirmUnregister) {');
  const unregisterCall = source.indexOf('Unregister-ScheduledTask -TaskName $taskName');

  assert(confirmationGuard >= 0 && unregisterCall > confirmationGuard);
  assert.match(source, /\[Parameter\(Mandatory = \$true, ParameterSetName = 'Unregister'\)\][\s\S]*?\[switch\]\$Unregister/);
  assert.match(source, /\[switch\]\$ConfirmUnregister/);
  assert.match(source, /SUXIOS OTA Dispatcher/);
  assert.match(source, /SUXIOS OTA Realtime Dispatcher/);
  assert.match(source, /ShouldProcess\("\$taskPath\$taskName", 'Unregister scheduled task'\)/);
});

test('package exposes the dry-run and focused verification commands', () => {
  assert.equal(
    packageJson.scripts['dry-run:ota-dispatcher'],
    'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/register_ota_dispatcher_task.ps1',
  );
  assert.equal(
    packageJson.scripts['verify:ota-dispatcher-registration'],
    'node --test tests/automation/ota_dispatcher_registration_contract.test.mjs',
  );
});
