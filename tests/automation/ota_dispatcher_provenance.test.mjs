import assert from 'node:assert/strict';
import {
  appendFileSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const helperPath = path.join(repoRoot, 'scripts', 'lib', 'ota_dispatcher_provenance.ps1');
const powershell = process.platform === 'win32' ? 'powershell.exe' : 'pwsh';

function psQuote(value) {
  return `'${String(value).replaceAll("'", "''")}'`;
}

function runPowerShell(source, { env = {}, expectSuccess = true } = {}) {
  const script = [
    '[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)',
    "$ErrorActionPreference = 'Stop'",
    `. ${psQuote(helperPath)}`,
    source,
  ].join('; ');
  const encoded = Buffer.from(script, 'utf16le').toString('base64');
  const result = spawnSync(powershell, [
    '-NoProfile',
    '-NonInteractive',
    '-ExecutionPolicy',
    'Bypass',
    '-EncodedCommand',
    encoded,
  ], {
    cwd: repoRoot,
    encoding: 'utf8',
    env: { ...process.env, ...env },
    timeout: 30_000,
  });

  assert.equal(result.error, undefined, `PowerShell could not start: ${result.error?.message || 'unknown error'}`);

  if (expectSuccess) {
    assert.equal(result.status, 0, `PowerShell failed:\n${result.stdout}\n${result.stderr}`);
  } else {
    assert.notEqual(result.status, 0, 'PowerShell was expected to reject the input');
  }
  return result;
}

function normalizePowerShellOutput(result) {
  return `${result.stdout || ''}\n${result.stderr || ''}`
    .replace(/_x([0-9a-f]{4})_/gi, (_match, hex) => String.fromCharCode(Number.parseInt(hex, 16)))
    .replace(/<[^>]+>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function parsePowerShellJson(source, options) {
  const result = runPowerShell(source, options);
  return JSON.parse(result.stdout.trim());
}

function makeFixture() {
  const root = mkdtempSync(path.join(tmpdir(), 'suxios-dispatcher-provenance-'));
  for (const directory of [
    'app/domain',
    'app/runtime',
    'config',
    'route',
    'scripts/lib',
    'scripts/tests',
    'scripts/node_modules/pkg',
    'scripts/reports',
    'storage',
    'output',
  ]) {
    mkdirSync(path.join(root, directory), { recursive: true });
  }
  const files = {
    'think': '#!/usr/bin/env php\n',
    'app/Command.php': '<?php echo "app";\n',
    'app/domain/model.js': 'export const value = 1;\n',
    'config/console.php': '<?php return [];\n',
    'route/app.php': '<?php return true;\n',
    'scripts/job.ps1': "Write-Output 'job'\n",
    'scripts/lib/untracked_source.mjs': 'export const pending = true;\n',
    'scripts/ignored.txt': 'extension excluded\n',
    'app/runtime/ignore.php': '<?php echo "runtime";\n',
    'scripts/tests/ignore.mjs': 'export const testOnly = true;\n',
    'scripts/node_modules/pkg/ignore.js': 'module.exports = 1;\n',
    'scripts/reports/ignore.php': '<?php echo "report";\n',
    'storage/ignore.php': '<?php echo "storage";\n',
    'output/ignore.js': 'console.log("output");\n',
  };
  for (const [relativePath, content] of Object.entries(files)) {
    writeFileSync(path.join(root, relativePath), content, 'utf8');
  }
  return root;
}

test('canonical SHA-256 is stable across property order', () => {
  const result = parsePowerShellJson(`
    $left = Get-SuxiosStableSha256 -Value ([ordered]@{ b = 2; a = 1 })
    $right = Get-SuxiosStableSha256 -Value ([ordered]@{ a = 1; b = 2 })
    [ordered]@{ left = $left; right = $right } | ConvertTo-Json -Compress
  `);
  assert.match(result.left, /^[a-f0-9]{64}$/);
  assert.equal(result.left, result.right);
});

test('code manifest includes filesystem-only source and ignores excluded directories and extensions', (t) => {
  const fixture = makeFixture();
  t.after(() => rmSync(fixture, { recursive: true, force: true }));
  const command = `
    $manifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot ${psQuote(fixture)}
    $manifest | ConvertTo-Json -Depth 8 -Compress
  `;

  const first = parsePowerShellJson(command);
  const second = parsePowerShellJson(command);
  assert.equal(first.sha256, second.sha256);
  assert.equal(first.file_count, 7);
  assert.deepEqual(
    first.files.map((entry) => entry.path),
    [
      'app/Command.php',
      'app/domain/model.js',
      'config/console.php',
      'route/app.php',
      'scripts/job.ps1',
      'scripts/lib/untracked_source.mjs',
      'think',
    ],
  );
  assert.ok(first.files.some((entry) => entry.path === 'scripts/lib/untracked_source.mjs'));
  assert.ok(first.files.every((entry) => !/(?:^|\/)(?:runtime|storage|output|reports|tests|vendor|node_modules)(?:\/|$)/i.test(entry.path)));

  appendFileSync(path.join(fixture, 'app', 'Command.php'), 'x', 'utf8');
  const includedChange = parsePowerShellJson(command);
  assert.notEqual(includedChange.sha256, first.sha256);

  appendFileSync(path.join(fixture, 'app', 'runtime', 'ignore.php'), 'excluded change', 'utf8');
  appendFileSync(path.join(fixture, 'scripts', 'ignored.txt'), 'excluded extension change', 'utf8');
  const excludedChange = parsePowerShellJson(command);
  assert.equal(excludedChange.sha256, includedChange.sha256);
});

test('scope, business date, effective config and task allowlist changes have distinct hashes', (t) => {
  const fixture = makeFixture();
  t.after(() => rmSync(fixture, { recursive: true, force: true }));
  const phpPath = path.join(fixture, 'php.exe');
  const thinkPath = path.join(fixture, 'think');
  const result = parsePowerShellJson(`
    $base = @{
      Mode = 'Daily'; Timezone = 'Asia/Shanghai'; TargetDate = '2026-08-09'; HotelId = 80;
      SourceIds = @(25, 68); Platforms = @('ctrip', 'meituan');
      PhpPath = ${psQuote(phpPath)}; ThinkPath = ${psQuote(thinkPath)}
    }
    $reordered = $base.Clone(); $reordered.SourceIds = @(68, 25); $reordered.Platforms = @('meituan', 'ctrip')
    $dateChanged = $base.Clone(); $dateChanged.TargetDate = '2026-08-10'
    $scopeChanged = $base.Clone(); $scopeChanged.SourceIds = @(25)
    $task = [ordered]@{
      task_name = 'SUXIOS OTA Dispatcher H80'; task_path = '\\';
      action_execute = 'powershell.exe'; action_arguments = '-File runner.ps1 -HotelId 80 -SourceIds "25,68"';
      working_directory = 'D:\\hotel'; trigger_start_boundary = '2026-08-10T08:30:00+08:00';
      trigger_interval = 'PT14M'; trigger_duration = 'PT29M'; principal_logon_type = 'InteractiveToken';
      principal_run_level = 'Limited'; multiple_instances = 'IgnoreNew'; start_when_available = $true;
      wake_to_run = $true; execution_time_limit = 'PT2H'; ignored_secret = $env:SUXIOS_PROVENANCE_TEST_SECRET
    }
    $taskChanged = @{}; foreach ($key in $task.Keys) { $taskChanged[$key] = $task[$key] }
    $taskChanged.trigger_interval = 'PT15M'
    [ordered]@{
      scope = Get-SuxiosDispatcherScopeHash -HotelId 80 -SourceIds @(25, 68) -Platforms @('ctrip', 'meituan')
      scope_reordered = Get-SuxiosDispatcherScopeHash -HotelId 80 -SourceIds @(68, 25) -Platforms @('meituan', 'ctrip')
      scope_changed = Get-SuxiosDispatcherScopeHash -HotelId 80 -SourceIds @(25) -Platforms @('ctrip', 'meituan')
      config = Get-SuxiosDispatcherEffectiveConfigHash @base
      config_reordered = Get-SuxiosDispatcherEffectiveConfigHash @reordered
      config_date_changed = Get-SuxiosDispatcherEffectiveConfigHash @dateChanged
      config_scope_changed = Get-SuxiosDispatcherEffectiveConfigHash @scopeChanged
      task = Get-SuxiosDispatcherTaskContractHash -TaskContract $task
      task_changed = Get-SuxiosDispatcherTaskContractHash -TaskContract $taskChanged
    } | ConvertTo-Json -Compress
  `, { env: { SUXIOS_PROVENANCE_TEST_SECRET: 'DO_NOT_ECHO_PROVENANCE_SECRET' } });

  assert.equal(result.scope, result.scope_reordered);
  assert.notEqual(result.scope, result.scope_changed);
  assert.equal(result.config, result.config_reordered);
  assert.notEqual(result.config, result.config_date_changed);
  assert.notEqual(result.config, result.config_scope_changed);
  assert.notEqual(result.task, result.task_changed);
  assert.ok(Object.values(result).every((value) => /^[a-f0-9]{64}$/.test(value)));
  assert.ok(!JSON.stringify(result).includes('DO_NOT_ECHO_PROVENANCE_SECRET'));
});

test('start and finish receipts expose only bounded hashes and safe scope fields', (t) => {
  const fixture = makeFixture();
  t.after(() => rmSync(fixture, { recursive: true, force: true }));
  const secret = 'DO_NOT_ECHO_CHILD_OR_TASK_SECRET';
  const result = parsePowerShellJson(`
    $manifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot ${psQuote(fixture)}
    $runnerHash = Get-SuxiosStableSha256 -Value 'runner bytes'
    $configHash = Get-SuxiosDispatcherEffectiveConfigHash -Mode Daily -Timezone 'Asia/Shanghai' ` +
      `-TargetDate '2026-08-09' -HotelId 80 -SourceIds @(25, 68) -Platforms @('ctrip', 'meituan') ` +
      `-PhpPath ${psQuote(path.join(fixture, 'php.exe'))} -ThinkPath ${psQuote(path.join(fixture, 'think'))}
    $task = [ordered]@{
      task_name = 'SUXIOS OTA Dispatcher H80'; task_path = '\\'; action_execute = 'powershell.exe';
      action_arguments = '-File runner.ps1 -HotelId 80'; working_directory = 'D:\\hotel';
      trigger_start_boundary = '2026-08-10T08:30:00+08:00'; trigger_interval = 'PT14M';
      trigger_duration = 'PT29M'; principal_logon_type = 'InteractiveToken'; principal_run_level = 'Limited';
      multiple_instances = 'IgnoreNew'; start_when_available = $true; wake_to_run = $true;
      execution_time_limit = 'PT2H'; ignored_secret = $env:SUXIOS_PROVENANCE_TEST_SECRET
    }
    $taskHash = Get-SuxiosDispatcherTaskContractHash -TaskContract $task
    $childHash = Get-SuxiosStableSha256 -Value $env:SUXIOS_PROVENANCE_TEST_SECRET
    $runId = [guid]'019fe32a-d67d-77f2-8256-3b6787d71ef7'
    $schedulerCorrelation = [ordered]@{
      task_name = 'SUXIOS OTA Dispatcher H80'; task_path = '\\'; state = 'Running';
      last_run_time = '2026-08-10T08:30:30+08:00'; last_run_delta_seconds = 0;
      task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07'; engine_process_id = 38228;
      event_ids = @(100, 107, 129, 200); manual_run_event_absent = $true;
      reason = 'exact_task_instance_events'; status = 'correlated'
    }
    $common = @{
      RunId = $runId; StartedAt = '2026-08-10T08:30:30+08:00'; Mode = 'Daily';
      Timezone = 'Asia/Shanghai'; TargetDate = '2026-08-09'; HotelId = 80;
      SourceIds = @(68, 25); Platforms = @('meituan', 'ctrip'); RunnerSha256 = $runnerHash;
      CodeManifest = $manifest; EffectiveConfigSha256 = $configHash; TaskContractSha256 = $taskHash;
      SchedulerCorrelation = $schedulerCorrelation
    }
    $start = New-SuxiosDispatcherProvenanceReceipt -Phase start @common
    $finish = New-SuxiosDispatcherProvenanceReceipt -Phase finish @common ` +
      `-FinishedAt '2026-08-10T08:31:00+08:00' -ChildReceiptPresent $true ` +
      `-ChildReceiptCount 1 -ChildReceiptSha256 $childHash -ChildExitCode 0 -CodeStableDuringRun $true -ProvenanceStatus verified
    $ambiguous = New-SuxiosDispatcherProvenanceReceipt -Phase finish @common ` +
      `-FinishedAt '2026-08-10T08:31:00+08:00' -ChildReceiptPresent $true ` +
      `-ChildReceiptCount 2 -ChildReceiptSha256 $childHash -ChildExitCode 0 -CodeStableDuringRun $true -ProvenanceStatus verified
    $failed = New-SuxiosDispatcherProvenanceReceipt -Phase finish @common ` +
      `-FinishedAt '2026-08-10T08:31:00+08:00' -ChildReceiptPresent $true ` +
      `-ChildReceiptCount 1 -ChildReceiptSha256 $childHash -ChildExitCode 1 -CodeStableDuringRun $true -ProvenanceStatus verified
    [ordered]@{
      start = ($start | ConvertFrom-Json); finish = ($finish | ConvertFrom-Json);
      ambiguous = ($ambiguous | ConvertFrom-Json); failed = ($failed | ConvertFrom-Json)
    } | ConvertTo-Json -Depth 8 -Compress
  `, { env: { SUXIOS_PROVENANCE_TEST_SECRET: secret } });

  assert.equal(result.start.phase, 'start');
  assert.equal(result.finish.phase, 'finish');
  assert.deepEqual(result.start.scope.source_ids, [25, 68]);
  assert.deepEqual(result.start.scope.platforms, ['ctrip', 'meituan']);
  assert.equal(result.start.sensitive_values_exposed, false);
  assert.equal(result.finish.sensitive_values_exposed, false);
  assert.equal(result.finish.child_receipt_present, true);
  assert.equal(result.finish.child_receipt_count, 1);
  assert.equal(result.finish.child_exit_code, 0);
  assert.equal(result.finish.code_stable_during_run, true);
  assert.equal(result.start.scheduler_correlation.status, 'correlated');
  assert.equal(result.finish.scheduler_correlation.status, 'correlated');
  assert.equal(result.finish.provenance_status, 'verified');
  assert.equal(result.finish.natural_run_ready, true);
  assert.equal(result.finish.natural_run_reason, 'verified');
  assert.equal(result.ambiguous.natural_run_ready, false);
  assert.equal(result.ambiguous.natural_run_reason, 'child_receipt_ambiguous');
  assert.equal(result.failed.natural_run_ready, false);
  assert.equal(result.failed.natural_run_reason, 'child_exit_nonzero');
  assert.deepEqual(result.finish.scheduler_correlation.event_ids, [100, 107, 129, 200]);
  assert.equal(result.finish.scheduler_correlation.manual_run_event_absent, true);
  assert.match(result.finish.child_receipt_sha256, /^[a-f0-9]{64}$/);
  assert.deepEqual(Object.keys(result.start.code_manifest).sort(), ['algorithm', 'file_count', 'sha256']);
  assert.ok(!JSON.stringify(result).includes(secret));
  assert.ok(!JSON.stringify(result).match(/cookie|spidertoken|authorization|password/i));
});

test('scheduler correlation requires exact task instance and current process events', () => {
  const result = parsePowerShellJson(`
    $events = @(
      [ordered]@{ event_id = 100; time_created = '2026-08-10T08:30:30+08:00'; task_name = '\\SUXIOS OTA Dispatcher H80'; task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07'; process_id = 0 },
      [ordered]@{ event_id = 107; time_created = '2026-08-10T08:30:30+08:00'; task_name = '\\SUXIOS OTA Dispatcher H80'; task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07'; process_id = 0 },
      [ordered]@{ event_id = 129; time_created = '2026-08-10T08:30:30+08:00'; task_name = '\\SUXIOS OTA Dispatcher H80'; task_instance_id = ''; process_id = 38228 },
      [ordered]@{ event_id = 200; time_created = '2026-08-10T08:30:30+08:00'; task_name = '\\SUXIOS OTA Dispatcher H80'; task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07'; process_id = 38228 }
    )
    $base = @{
      TaskName = 'SUXIOS OTA Dispatcher H80'; EngineProcessId = 38228;
      ReferenceTime = '2026-08-10T08:30:31+08:00'; TaskState = 'Running';
      LastRunTime = '2026-08-10T08:30:30+08:00'; EventRecords = $events; EventLogAvailable = $true
    }
    $natural = Resolve-SuxiosDispatcherSchedulerCorrelation @base
    $manualArgs = @{
      TaskName = $base.TaskName; EngineProcessId = 38229; ReferenceTime = $base.ReferenceTime;
      TaskState = $base.TaskState; LastRunTime = $base.LastRunTime; EventRecords = $events; EventLogAvailable = $true
    }
    $manual = Resolve-SuxiosDispatcherSchedulerCorrelation @manualArgs
    $manualTaskEvents = @($events) + @(
      [ordered]@{ event_id = 110; time_created = '2026-08-10T08:30:30+08:00'; task_name = '\\SUXIOS OTA Dispatcher H80'; task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07'; process_id = 0 }
    )
    $manualTaskArgs = @{
      TaskName = $base.TaskName; EngineProcessId = $base.EngineProcessId; ReferenceTime = $base.ReferenceTime;
      TaskState = $base.TaskState; LastRunTime = $base.LastRunTime; EventRecords = $manualTaskEvents; EventLogAvailable = $true
    }
    $manualTask = Resolve-SuxiosDispatcherSchedulerCorrelation @manualTaskArgs
    $preflight = Resolve-SuxiosDispatcherSchedulerCorrelation @base -PreflightOnly
    $unavailableArgs = @{
      TaskName = $base.TaskName; EngineProcessId = $base.EngineProcessId; ReferenceTime = $base.ReferenceTime;
      TaskState = $base.TaskState; LastRunTime = $base.LastRunTime; EventRecords = @(); EventLogAvailable = $false
    }
    $unavailable = Resolve-SuxiosDispatcherSchedulerCorrelation @unavailableArgs
    [ordered]@{ natural = $natural; manual = $manual; manual_task = $manualTask; preflight = $preflight; unavailable = $unavailable } | ConvertTo-Json -Depth 7 -Compress
  `);

  assert.equal(result.natural.status, 'correlated');
  assert.equal(result.natural.reason, 'exact_task_instance_events');
  assert.equal(result.natural.engine_process_id, 38228);
  assert.deepEqual(result.natural.event_ids, [100, 107, 129, 200]);
  assert.equal(result.natural.manual_run_event_absent, true);
  assert.equal(result.manual.status, 'not_correlated');
  assert.equal(result.manual.reason, 'exact_task_instance_events_missing');
  assert.equal(result.manual_task.status, 'not_correlated');
  assert.equal(result.manual_task.reason, 'manual_task_run_event_detected');
  assert.equal(result.preflight.status, 'not_correlated');
  assert.equal(result.preflight.reason, 'preflight_only');
  assert.equal(result.unavailable.status, 'unavailable');
  assert.equal(result.unavailable.reason, 'operational_event_log_unavailable');
});

test('terminal correlation never lets a correlated start hide late manual-run evidence', () => {
  const result = parsePowerShellJson(`
    $start = [ordered]@{ status = 'correlated'; reason = 'exact_task_instance_events'; task_instance_id = '39353d49-b18b-4807-ae2a-2c66a8ce3e07' }
    $lateManual = [ordered]@{ status = 'not_correlated'; reason = 'manual_task_run_event_detected'; task_instance_id = $null }
    $unavailable = [ordered]@{ status = 'unavailable'; reason = 'operational_event_log_unavailable'; task_instance_id = $null }
    $finishExact = [ordered]@{ status = 'correlated'; reason = 'exact_task_instance_events'; task_instance_id = '62db1768-bddf-43b8-8136-0d44b43e7136' }
    [ordered]@{
      late_manual = Select-SuxiosDispatcherTerminalSchedulerCorrelation -StartCorrelation $start -FinishCorrelation $lateManual
      unavailable = Select-SuxiosDispatcherTerminalSchedulerCorrelation -StartCorrelation $start -FinishCorrelation $unavailable
      finish_exact = Select-SuxiosDispatcherTerminalSchedulerCorrelation -StartCorrelation $start -FinishCorrelation $finishExact
    } | ConvertTo-Json -Depth 5 -Compress
  `);

  assert.equal(result.late_manual.status, 'not_correlated');
  assert.equal(result.late_manual.reason, 'manual_task_run_event_detected');
  assert.equal(result.unavailable.status, 'correlated');
  assert.equal(result.unavailable.task_instance_id, '39353d49-b18b-4807-ae2a-2c66a8ce3e07');
  assert.equal(result.finish_exact.status, 'correlated');
  assert.equal(result.finish_exact.task_instance_id, '62db1768-bddf-43b8-8136-0d44b43e7136');
});

test('verified finish provenance rejects missing scheduler correlation', () => {
  const fixture = makeFixture();
  try {
    const result = runPowerShell(`
      $manifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot ${psQuote(fixture)}
      $hash = Get-SuxiosStableSha256 -Value 'bounded fixture'
      New-SuxiosDispatcherProvenanceReceipt -Phase finish ` +
        `-RunId ([guid]'019fe32a-d67d-77f2-8256-3b6787d71ef7') ` +
        `-StartedAt '2026-08-10T08:30:30+08:00' -FinishedAt '2026-08-10T08:31:00+08:00' ` +
        `-Mode Daily -Timezone 'Asia/Shanghai' -TargetDate '2026-08-09' -HotelId 80 ` +
        `-SourceIds @(25,68) -Platforms @('ctrip','meituan') -RunnerSha256 $hash ` +
        `-CodeManifest $manifest -EffectiveConfigSha256 $hash -TaskContractSha256 $hash ` +
        `-ChildReceiptPresent $false -ChildReceiptCount 0 -ChildExitCode 0 -CodeStableDuringRun $true -ProvenanceStatus verified
    `, { expectSuccess: false });
    assert.match(normalizePowerShellOutput(result), /requires correlated scheduling and stable code/i);
  } finally {
    rmSync(fixture, { recursive: true, force: true });
  }
});

test('credential-shaped task arguments are rejected without echoing their value', () => {
  const secret = 'DO_NOT_ECHO_REJECTED_TOKEN';
  const result = runPowerShell(`
    $task = [ordered]@{ action_arguments = ('--token ' + $env:SUXIOS_PROVENANCE_TEST_SECRET) }
    Get-SuxiosDispatcherTaskContractHash -TaskContract $task
  `, {
    env: { SUXIOS_PROVENANCE_TEST_SECRET: secret },
    expectSuccess: false,
  });
  const output = normalizePowerShellOutput(result);
  assert.ok(!output.includes(secret));
  assert.match(output, /credential-shaped arguments/i);
});

test('helper remains a pure dot-source library with no task, database, or OTA command calls', () => {
  const source = readFileSync(helperPath, 'utf8');
  assert.doesNotMatch(source, /Get-ScheduledTask|Set-ScheduledTask|Register-ScheduledTask|Start-ScheduledTask/);
  assert.doesNotMatch(source, /Invoke-WebRequest|Invoke-RestMethod|Start-Process|\bthink\b.*online-data:auto-fetch/i);
  assert.doesNotMatch(source, /\bDb::|mysql|mysqli|PDO\b/i);
});
