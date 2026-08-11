import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const scriptPath = path.join(repoRoot, 'scripts', 'register_ota_dispatcher_task.ps1');
const packagePath = path.join(repoRoot, 'package.json');
const source = readFileSync(scriptPath, 'utf8');
const packageJson = JSON.parse(readFileSync(packagePath, 'utf8'));

function isolatedPreflightFixture(initialDatabaseExitCode) {
  const root = mkdtempSync(path.join(tmpdir(), 'suxios-dispatcher-preflight-'));
  const scriptsDirectory = path.join(root, 'scripts');
  const runtimeDirectory = path.join(root, 'runtime', 'dispatcher');
  mkdirSync(scriptsDirectory, { recursive: true });
  mkdirSync(runtimeDirectory, { recursive: true });
  const statePath = path.join(root, 'database-ready.marker');
  const recoveryMarker = path.join(root, 'database-recovery.marker');
  const otaMarker = path.join(root, 'ota-command.marker');
  writeFileSync(path.join(root, 'think'), `<?php
$command = $argv[1] ?? '';
if ($command === 'db:check') {
    $statePath = (string)getenv('FAKE_DB_STATE_PATH');
    if ($statePath !== '' && is_file($statePath)) {
        exit(0);
    }
    exit((int)(getenv('FAKE_INITIAL_DB_EXIT') ?: 1));
}
if ($command === 'online-data:auto-fetch') {
    file_put_contents(
        (string)getenv('FAKE_OTA_MARKER'),
        json_encode($argv, JSON_UNESCAPED_SLASHES)
    );
    $options = [];
    foreach ($argv as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            continue;
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
    $dispatcherRunId = strtolower((string)($options['dispatcher-run-id'] ?? ''));
    $receiptDispatcherRunId = strtolower((string)(
        getenv('FAKE_RECEIPT_DISPATCHER_RUN_ID') ?: $dispatcherRunId
    ));
    $hotelId = (int)($options['hotel-id'] ?? 0);
    $targetDate = (string)($options['target-date'] ?? '');
    $sourceIds = array_values(array_filter(array_map(
        'intval',
        explode(',', (string)($options['source-ids'] ?? ''))
    )));
    sort($sourceIds, SORT_NUMERIC);
    $platforms = array_values(array_filter(array_map(
        static fn(string $value): string => strtolower(trim($value)),
        explode(',', (string)($options['platforms'] ?? ''))
    )));
    sort($platforms, SORT_STRING);
    $lifecycleStatus = strtolower((string)(getenv('FAKE_OTA_LIFECYCLE_STATUS') ?: 'failed'));
    $successEvidenceGap = trim((string)(getenv('FAKE_OTA_SUCCESS_EVIDENCE_GAP') ?: ''));
    $collectionSuccessEvidenceGap = trim((string)(
        getenv('FAKE_COLLECTION_RUN_SUCCESS_EVIDENCE_GAP') ?: ''
    ));
    $omitAutoReceipt = (string)getenv('FAKE_OTA_OMIT_AUTO_RECEIPT') === '1';
    $taskMix = strtolower(trim((string)(getenv('FAKE_OTA_TASK_MIX') ?: 'all_local')));
    $sourceReceipts = [];
    $sourceTasks = [];
    foreach ($platforms as $index => $platform) {
        $sourceReceipts[] = [
            'platform' => $platform,
            'data_source_id' => $sourceIds[$index] ?? 0,
        ];
        $browserProfileTask = in_array(
            $taskMix,
            ['mixed', 'mixed_browser_with_local_id'],
            true
        ) && $index === 0;
        $sourceTask = [
            'platform' => $platform,
            'data_source_id' => $sourceIds[$index] ?? 0,
            'sync_task_id' => 1001 + $index,
            'readback_verified' => true,
            'ingestion_method' => $browserProfileTask ? 'browser_profile' : 'local_collector',
            'trigger_type' => $browserProfileTask
                ? 'daily_profile_reuse'
                : 'local_collector_upload',
        ];
        if (!$browserProfileTask || $taskMix === 'mixed_browser_with_local_id') {
            $sourceTask['local_collector_task_id'] = 3271 + $index;
        }
        $sourceTasks[] = $sourceTask;
    }
    $anchorHash = str_repeat('a', 64);
    $trustReceiptDigest = str_repeat('b', 64);
    if ($lifecycleStatus !== 'missing') {
        $collectionSourceReceipts = [];
        foreach ($sourceTasks as $sourceTask) {
            $collectionSourceReceipts[] = [
                'platform' => $sourceTask['platform'],
                'data_source_id' => $sourceTask['data_source_id'],
                'ingestion_method' => $sourceTask['ingestion_method'],
                'platform_sync_task_id' => $sourceTask['sync_task_id'],
                'local_collector_task_id' => $sourceTask['local_collector_task_id'] ?? null,
                'status' => 'success',
                'saved_row_count' => 2,
                'readback_row_count' => 2,
                'readback_verified' => true,
                'finished_at' => '2026-08-10 08:31:00',
            ];
        }
        $collectionRunReceipt = [
            'dispatcher_run_id' => $receiptDispatcherRunId,
            'system_hotel_id' => $hotelId,
            'business_date' => $targetDate,
            'status' => $lifecycleStatus,
            'source_receipts' => $lifecycleStatus === 'succeeded'
                ? $collectionSourceReceipts
                : $sourceReceipts,
            'collection_anchor_hash' => $lifecycleStatus === 'succeeded' ? $anchorHash : null,
            'trust_receipt_digest' => $lifecycleStatus === 'succeeded' ? $trustReceiptDigest : null,
            'sensitive_values_exposed' => false,
        ];
        if ($lifecycleStatus === 'succeeded') {
            $collectionRunReceipt += [
                'ledger_structure_verified' => true,
                'readback_verified' => true,
                'finished_at' => '2026-08-10 08:31:00',
            ];
            if ($collectionSuccessEvidenceGap !== '') {
                unset($collectionRunReceipt[$collectionSuccessEvidenceGap]);
            }
        }
        echo 'SUXIOS_COLLECTION_RUN_RECEIPT=' . json_encode(
            $collectionRunReceipt,
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
        if (!$omitAutoReceipt && in_array(
            $lifecycleStatus,
            ['succeeded', 'partial', 'failed', 'blocked', 'skipped', 'deferred'],
            true
        )) {
            $autoStatus = match ($lifecycleStatus) {
                'succeeded' => 'success',
                'partial' => 'partial_success',
                default => $lifecycleStatus,
            };
            $autoReceipt = [
                'status' => $autoStatus,
                'sensitive_values_exposed' => false,
                'dispatcher_run_id' => $receiptDispatcherRunId,
                'hotel_id' => $hotelId,
                'target_date' => $targetDate,
                'source_ids' => $sourceIds,
                'required_platforms' => $platforms,
            ];
            if ($lifecycleStatus === 'succeeded') {
                $autoReceipt += [
                    'collection_complete' => true,
                    'authority_scope_complete' => true,
                    'dual_ota_p0_complete' => true,
                    'canonical_history_complete' => true,
                    'collection_run_readback_verified' => true,
                    'collection_anchor_hash' => $anchorHash,
                    'trust_receipt_digest' => $trustReceiptDigest,
                    'source_tasks' => $sourceTasks,
                ];
                if ($successEvidenceGap !== '') {
                    unset($autoReceipt[$successEvidenceGap]);
                }
            }
            echo 'SUXIOS_AUTO_FETCH_RECEIPT=' . json_encode(
                $autoReceipt,
                JSON_UNESCAPED_SLASHES
            ) . PHP_EOL;
        }
    }
    exit((int)(getenv('FAKE_OTA_EXIT') ?: 0));
}
exit(64);
`, 'utf8');
  writeFileSync(path.join(scriptsDirectory, 'start_local_stack.ps1'), `param(
    [switch]$DatabaseOnly,
    [switch]$NoBrowser
)
if (-not $DatabaseOnly) { exit 9 }
[System.IO.File]::WriteAllText($env:FAKE_DB_STATE_PATH, 'ready')
[System.IO.File]::WriteAllText($env:FAKE_RECOVERY_MARKER, 'attempted')
Write-Output 'password=leak-test'
[Console]::Error.WriteLine('token=leak-test')
exit 0
`, 'utf8');
  return {
    root,
    runtimeDirectory,
    statePath,
    recoveryMarker,
    otaMarker,
    environment: {
      ...process.env,
      FAKE_DB_STATE_PATH: statePath,
      FAKE_RECOVERY_MARKER: recoveryMarker,
      FAKE_OTA_MARKER: otaMarker,
      FAKE_INITIAL_DB_EXIT: String(initialDatabaseExitCode),
      FAKE_OTA_EXIT: '0',
      FAKE_OTA_LIFECYCLE_STATUS: 'failed',
    },
  };
}

function runIsolatedPreflight(fixture, phpPath, {
  preflightOnly = true,
  sourceIds = '25,68',
  platforms = 'ctrip,meituan',
} = {}) {
  const powershell = path.join(
    process.env.SystemRoot || 'C:\\Windows',
    'System32',
    'WindowsPowerShell',
    'v1.0',
    'powershell.exe',
  );
  const logsBefore = new Set(readdirSync(fixture.runtimeDirectory));
  const args = [
    '-NoProfile',
    '-NonInteractive',
    '-ExecutionPolicy',
    'Bypass',
    '-File',
    path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'),
    '-ProjectRoot',
    fixture.root,
    '-PhpPath',
    phpPath,
    '-Mode',
    'Daily',
    '-HotelId',
    '80',
    '-SourceIds',
    sourceIds,
    '-Platforms',
    platforms,
  ];
  if (preflightOnly) args.push('-PreflightOnly');
  const result = spawnSync(powershell, args, {
    cwd: fixture.root,
    encoding: 'utf8',
    env: fixture.environment,
    timeout: 30_000,
    windowsHide: true,
  });
  const logs = readdirSync(fixture.runtimeDirectory)
    .filter(name => /^ota_dispatcher_\d{8}_\d{6}_[a-f0-9]{32}\.log$/.test(name))
    .filter(name => !logsBefore.has(name));
  assert.equal(logs.length, 1, result.stderr || result.stdout);
  const logPath = path.join(fixture.runtimeDirectory, logs[0]);
  return {
    ...result,
    logPath,
    log: readFileSync(logPath, 'utf8'),
  };
}

function dispatcherIds(result) {
  const executionId = result.log.match(/dispatcher_execution_id=([a-f0-9-]{36});schema_version=1/)?.[1];
  const collectionRunId = result.log.match(/dispatcher_run_id=([a-f0-9-]{36});schema_version=1/)?.[1];
  assert.match(executionId || '', /^[a-f0-9-]{36}$/);
  assert.match(collectionRunId || '', /^[a-f0-9-]{36}$/);
  return { executionId, collectionRunId };
}

function collectionStateFiles(fixture) {
  return readdirSync(fixture.runtimeDirectory)
    .filter(name => /^ota_collection_run_[a-f0-9]{64}\.json$/.test(name))
    .map(name => path.join(fixture.runtimeDirectory, name));
}

function localPhpPath() {
  return [
    process.env.SUXIOS_TEST_PHP,
    'C:\\xampp\\php\\php.exe',
    'D:\\xampp\\php\\php.exe',
  ].find(candidate => candidate && existsSync(candidate));
}

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
  assert.match(source, /-WakeToRun/);
  assert.match(source, /New-TimeSpan -Minutes 40/);
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
  assert.match(runner, /FindSystemTimeZoneById\('China Standard Time'\)/);
  assert.match(runner, /\$dailyTargetDate = \$shanghaiNow\.Date\.AddDays\(-1\)\.ToString/);
  assert.match(runner, /--target-date=\$dailyTargetDate/);
  assert.match(runner, /--dispatcher-run-id=\$\(\$dispatcherRunGuid\.ToString\('D'\)\.ToLowerInvariant\(\)\)/);
  assert.match(runner, /dispatcher_target_date=\$dailyTargetDate;timezone=Asia\/Shanghai/);
  assert.match(runner, /--hotel-id=\$HotelId/);
  assert.match(runner, /--source-ids=\$effectiveSourceIds/);
  assert.match(runner, /--platforms=\$effectivePlatforms/);
  assert.match(runner, /Scoped OTA dispatcher requires HotelId, SourceIds, and Platforms together/);
});

test('runner separates per-process execution identity from exact collection identity', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  assert.match(runner, /\$executionGuid = \[guid\]::NewGuid\(\)/);
  assert.match(runner, /\$runId = \(Get-Date -Format 'yyyyMMdd_HHmmss'\) \+ '_' \+ \$executionGuid\.ToString\('N'\)/);
  assert.match(runner, /dispatcher_execution_id=\$\(\$executionGuid\.ToString\('D'\)/);
  assert.match(runner, /--dispatcher-run-id=\$\(\$dispatcherRunGuid\.ToString\('D'\)/);
  assert.match(runner, /-RunId \$dispatcherRunGuid/);
  assert.doesNotMatch(runner, /--dispatcher-run-id=\$\(\$executionGuid/);
});

test('collection state reuses only explicit active statuses and rotates every declared terminal status', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  assert.match(runner, /\$collectionActiveStatuses = @\('in_progress', 'started', 'collected'\)/);
  assert.match(
    runner,
    /\$collectionTerminalStatuses = @\([\s\S]*?'succeeded'[\s\S]*?'partial'[\s\S]*?'failed'[\s\S]*?'blocked'[\s\S]*?'skipped'[\s\S]*?'deferred'[\s\S]*?\)/,
  );
  assert.match(runner, /if \(\$priorCollectionStatus -in \$collectionActiveStatuses\)/);
  assert.match(runner, /\$collectionStateDecision = 'reused_active'/);
  assert.match(runner, /\$collectionStateDecision = 'rotated_terminal'/);
  assert.match(runner, /\$collectionStateDecision = 'rotated_invalid_state'/);
});

test('collection scope key is exact, sorted, integrity-checked, and carries an available plan fingerprint', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const scopeStart = runner.indexOf('function Get-DispatcherCollectionScope');
  const stateWriterStart = runner.indexOf('function Write-TrustedDispatcherCollectionState');
  const outputResolverStart = runner.indexOf('function Resolve-DispatcherCollectionOutputStatus');
  const scopeAndState = runner.slice(scopeStart, outputResolverStart);
  assert(scopeStart >= 0 && stateWriterStart > scopeStart && outputResolverStart > stateWriterStart);
  assert.match(scopeAndState, /Sort-Object -Unique/);
  assert.match(scopeAndState, /hotel_id=\$SystemHotelId/);
  assert.match(scopeAndState, /business_date=\$BusinessDate/);
  assert.match(scopeAndState, /source_ids=\$sourceIdsText/);
  assert.match(scopeAndState, /platforms=\$platformsText/);
  assert.match(scopeAndState, /SUXIOS_OTA_COLLECTION_PLAN_FINGERPRINT/);
  assert.match(scopeAndState, /plan_fingerprint=\$planFingerprint/);
  assert.match(scopeAndState, /integrity_sha256/);
  assert.doesNotMatch(scopeAndState, /cookie|password|authorization|profile_key|session/i);
});

test('runner accepts lifecycle success only with a zero exit and the complete exact AUTO receipt', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const verifierStart = runner.indexOf('function Test-DispatcherAutoFetchSuccessReceipt');
  const resolverStart = runner.indexOf('function Resolve-DispatcherCollectionOutputStatus');
  const verifier = runner.slice(verifierStart, resolverStart);
  assert(verifierStart >= 0 && resolverStart > verifierStart);
  assert.match(verifier, /\$ChildExitCode -ne 0/);
  for (const field of [
    'collection_complete',
    'authority_scope_complete',
    'dual_ota_p0_complete',
    'canonical_history_complete',
    'collection_run_readback_verified',
    'collection_anchor_hash',
    'trust_receipt_digest',
    'ingestion_method',
    'trigger_type',
    'local_collector_task_id',
    'sync_task_id',
    'readback_verified',
  ]) {
    assert.match(verifier, new RegExp(field));
  }
  assert.match(verifier, /\$sourceTasks\.Count -ne 2/);
  assert.match(verifier, /\^\[a-f0-9\]\{64\}\$/);
  assert.match(verifier, /local_collector_upload/);
  assert.match(verifier, /daily_profile_reuse/);
  assert.match(runner, /-ChildExitCode \$childExitCode/);
});

test('strict collection-run ledger can prove success only when no AUTO success receipt exists', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const verifierStart = runner.indexOf('function Test-DispatcherCollectionRunSuccessReceipt');
  const resolverStart = runner.indexOf('function Resolve-DispatcherCollectionOutputStatus');
  const verifier = runner.slice(verifierStart, resolverStart);
  assert(verifierStart >= 0 && resolverStart > verifierStart);
  for (const field of [
    'ledger_structure_verified',
    'readback_verified',
    'collection_anchor_hash',
    'trust_receipt_digest',
    'source_receipts',
    'platform_sync_task_id',
    'local_collector_task_id',
    'ingestion_method',
    'saved_row_count',
    'readback_row_count',
    'finished_at',
  ]) {
    assert.match(verifier, new RegExp(field));
  }
  assert.match(verifier, /\$sourceReceipts\.Count -ne 2/);
  assert.match(verifier, /\$ChildExitCode -ne 0/);
  assert.match(runner, /if \(\$autoFetchSuccessReceiptSeen\)/);
  assert.match(runner, /\$collectionRunSuccessReceiptSeen -and \$collectionRunSuccessReceiptsValid/);
});

test('replacing a fixed task cannot silently remove an existing source or platform', () => {
  assert.match(source, /\[switch\]\$AllowScopeReduction/);
  assert.match(source, /function Get-DispatcherTaskScopeFromArguments/);
  assert.match(source, /\$existingScope\.source_ids \| Where-Object \{ \$_ -notin \$requestedSourceIds \}/);
  assert.match(source, /\$existingScope\.platforms \| Where-Object \{ \$_ -notin \$requestedPlatforms \}/);
  assert.match(source, /New-PreflightCheck -Name 'scope_non_reduction'/);
  assert.match(source, /scope_reduction_requires_switch = '-AllowScopeReduction'/);
  assert.match(source, /use -AllowScopeReduction only after explicit review/);
});

test('runner persists a safe start receipt before the child process and a terminal receipt after it', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const startedStatus = runner.indexOf("dispatcher_terminal_status=started_without_terminal_receipt");
  const firstLogWrite = runner.indexOf('[System.IO.File]::WriteAllLines($logPath');
  const processStart = runner.indexOf('$process = Start-Process');
  const finishedStatus = runner.indexOf('dispatcher_terminal_status=finished;exit_code=$exitCode', processStart);
  const finalLogWrite = runner.lastIndexOf('[System.IO.File]::WriteAllLines($logPath');

  assert(startedStatus >= 0 && firstLogWrite > startedStatus && processStart > firstLogWrite);
  assert(finishedStatus > processStart && finalLogWrite > finishedStatus);
  assert.match(runner, /verify_canonical_ota_daily_natural_acceptance\.php/);
  assert.match(runner, /dispatcher_daily_acceptance_readback_verified=/);
  assert.match(runner, /non_blocking=true/);
});

test('runner fail-closes database readiness and exposes a no-OTA preflight mode', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const targetDatePinned = runner.indexOf("FindSystemTimeZoneById('China Standard Time')");
  const firstLogWrite = runner.indexOf('[System.IO.File]::WriteAllLines($logPath');
  const initialDatabaseCheck = runner.indexOf('$initialDatabaseCheck = Invoke-SafeDispatcherProcess');
  const schemaMismatchBranch = runner.indexOf('if ($initialDatabaseCheck.exit_code -eq 2)');
  const recoveryBranch = runner.indexOf('elseif ($initialDatabaseCheck.exit_code -ne 0)');
  const databaseOnlyAttempt = runner.indexOf("'-DatabaseOnly'", recoveryBranch);
  const verifiedDatabaseCheck = runner.indexOf('$verifiedDatabaseCheck = Invoke-SafeDispatcherProcess', databaseOnlyAttempt);
  const preflightTerminalBranch = runner.indexOf('if ($PreflightOnly -or $databasePreflightBlocked)');
  const otaProcessStart = runner.indexOf('$process = Start-Process');

  assert.match(runner, /\[switch\]\$PreflightOnly/);
  assert.match(runner, /dispatcher_run_mode=preflight_only;ota_collection_started=false/);
  assert.match(runner, /dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required/);
  assert.match(runner, /dispatcher_database_recovery=attempted/);
  assert.match(runner, /dispatcher_preflight_result=ready;ota_collection_started=false/);
  assert.match(runner, /dispatcher_preflight_result=blocked;reason=\$databasePreflightReason;ota_collection_started=false/);
  assert.match(
    runner,
    /Id\s*=\s*@\(100,\s*107,\s*110,\s*129,\s*200\)/,
    'natural correlation must read the bounded Task Scheduler event set',
  );
  assert.match(runner, /-EngineProcessId\s+\$PID/);
  assert.match(runner, /-PreflightOnly:\$\(\[bool\]\$PreflightOnly\)/);
  assert.match(
    runner,
    /\$finishTaskEvidence\s*=\s*Get-DispatcherScheduledTaskEvidence[\s\S]*?-ReferenceTime\s+\(\[datetimeoffset\]\$StartState\.started_at\)/,
    'finish provenance must re-query the exact start window even after a long OTA run',
  );
  assert.match(
    runner,
    /\$selectedSchedulerCorrelation\s*=\s*Select-SuxiosDispatcherTerminalSchedulerCorrelation[\s\S]*?-StartCorrelation\s+\$StartState\.task_evidence\.correlation[\s\S]*?-FinishCorrelation\s+\$finishTaskEvidence\.correlation/,
    'a frozen exact start correlation may survive only a transient unavailable finish read, never explicit not-correlated evidence',
  );
  assert.equal((runner.match(/'-DatabaseOnly'/g) || []).length, 1);
  assert(targetDatePinned >= 0 && firstLogWrite > targetDatePinned);
  assert(initialDatabaseCheck > firstLogWrite);
  assert(schemaMismatchBranch > initialDatabaseCheck && recoveryBranch > schemaMismatchBranch);
  assert(databaseOnlyAttempt > recoveryBranch && verifiedDatabaseCheck > databaseOnlyAttempt);
  assert(preflightTerminalBranch > verifiedDatabaseCheck && otaProcessStart > preflightTerminalBranch);
});

test('PreflightOnly never attempts recovery for schema exit 2 and never invokes OTA', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(2);
  try {
    const result = runIsolatedPreflight(fixture, phpPath);
    assert.equal(result.status, 2, `${result.stderr || result.stdout}\n${result.log}`);
    assert.equal(existsSync(fixture.recoveryMarker), false);
    assert.equal(existsSync(fixture.otaMarker), false);
    assert.match(result.log, /dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required/);
    assert.match(result.log, /dispatcher_preflight_result=blocked;reason=database_schema_upgrade_required;ota_collection_started=false/);
    assert.doesNotMatch(result.log, /dispatcher_database_recovery=attempted/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('PreflightOnly performs one DatabaseOnly recovery, rechecks, and suppresses child output', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(1);
  try {
    const result = runIsolatedPreflight(fixture, phpPath);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(existsSync(fixture.recoveryMarker), true, result.log);
    assert.equal(existsSync(fixture.statePath), true);
    assert.equal(existsSync(fixture.otaMarker), false);
    assert.match(result.log, /dispatcher_database_recovery=attempted;exit_code=0;timed_out=False/i);
    assert.match(result.log, /dispatcher_database_preflight=ready;recovery_attempted=true;verified_exit_code=0/);
    assert.match(result.log, /dispatcher_preflight_result=ready;ota_collection_started=false/);
    assert.doesNotMatch(result.log, /leak-test|password=|token=/i);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('real dispatcher child preserves a non-zero exit code and its safe machine receipt', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(1);
  fixture.environment.FAKE_OTA_EXIT = '1';
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  try {
    const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(result.status, 1, `${result.stderr || result.stdout}\n${result.log}`);
    assert.equal(existsSync(fixture.otaMarker), true, result.log);
    const childArguments = JSON.parse(readFileSync(fixture.otaMarker, 'utf8'));
    const dispatcherArgument = childArguments.find(value =>
      /^--dispatcher-run-id=[a-f0-9-]{36}$/.test(String(value)),
    );
    assert.ok(dispatcherArgument, childArguments);
    const dispatcherRunId = String(dispatcherArgument).split('=', 2)[1];
    assert.match(result.log, new RegExp(`dispatcher_run_id=${dispatcherRunId};schema_version=1`));
    assert.match(result.log, /SUXIOS_AUTO_FETCH_RECEIPT=\{"status":"failed","sensitive_values_exposed":false,/);
    assert.match(result.log, /dispatcher_terminal_status=finished;exit_code=1/);
    assert.doesNotMatch(result.log, /dispatcher_terminal_status=finished;exit_code=0/);
    assert.match(result.log, /SUXIOS_OTA_DAILY_ACCEPTANCE=\{"schema_version":"suxios_ota_daily_natural_acceptance\.v1","status":"blocked"/);
    assert.match(result.log, /dispatcher_daily_acceptance_readback_verified=true;receipt_count=1/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('active collection UUID is reused, execution UUID rotates, and terminal collection rotates next run', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'in_progress';
  try {
    const first = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(first.status, 1, `${first.stderr || first.stdout}\n${first.log}`);
    const firstIds = dispatcherIds(first);
    assert.notEqual(firstIds.executionId, firstIds.collectionRunId);
    assert.match(first.log, /decision=new;prior_status=none/);
    let statePaths = collectionStateFiles(fixture);
    assert.equal(statePaths.length, 1);
    let state = JSON.parse(readFileSync(statePaths[0], 'utf8'));
    assert.equal(state.status, 'in_progress');
    assert.equal(state.collection_run_id, firstIds.collectionRunId);
    assert.deepEqual(state.source_ids, [25, 68]);
    assert.deepEqual(state.platforms, ['ctrip', 'meituan']);

    fixture.environment.FAKE_OTA_EXIT = '0';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
    const second = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(second.status, 0, `${second.stderr || second.stdout}\n${second.log}`);
    const secondIds = dispatcherIds(second);
    assert.equal(secondIds.collectionRunId, firstIds.collectionRunId);
    assert.notEqual(secondIds.executionId, firstIds.executionId);
    assert.notEqual(secondIds.executionId, secondIds.collectionRunId);
    assert.match(second.log, /decision=reused_active;prior_status=in_progress/);
    state = JSON.parse(readFileSync(statePaths[0], 'utf8'));
    assert.equal(state.status, 'succeeded');
    assert.equal(state.collection_run_id, firstIds.collectionRunId);

    fixture.environment.FAKE_OTA_EXIT = '1';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'failed';
    const third = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(third.status, 1, `${third.stderr || third.stdout}\n${third.log}`);
    const thirdIds = dispatcherIds(third);
    assert.notEqual(thirdIds.collectionRunId, firstIds.collectionRunId);
    assert.notEqual(thirdIds.executionId, secondIds.executionId);
    assert.match(third.log, /decision=rotated_terminal;prior_status=succeeded/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('sorted equivalent scope reuses but changed source scope and damaged state rotate fail-closed', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'started';
  try {
    const first = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      sourceIds: '68,25',
      platforms: 'meituan,ctrip',
    });
    const firstIds = dispatcherIds(first);
    const second = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      sourceIds: '25,68',
      platforms: 'ctrip,meituan',
    });
    const secondIds = dispatcherIds(second);
    assert.equal(secondIds.collectionRunId, firstIds.collectionRunId);
    assert.match(second.log, /decision=reused_active;prior_status=started/);
    assert.match(second.log, /dispatcher_scope=hotel:80;platforms:ctrip,meituan;source_count:2/);

    const changedScope = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      sourceIds: '25,69',
      platforms: 'ctrip,meituan',
    });
    const changedScopeIds = dispatcherIds(changedScope);
    assert.notEqual(changedScopeIds.collectionRunId, firstIds.collectionRunId);
    assert.match(changedScope.log, /decision=new;prior_status=none/);

    const exactStatePath = collectionStateFiles(fixture).find(statePath => {
      const state = JSON.parse(readFileSync(statePath, 'utf8'));
      return state.collection_run_id === firstIds.collectionRunId;
    });
    assert.ok(exactStatePath);
    const damagedState = JSON.parse(readFileSync(exactStatePath, 'utf8'));
    damagedState.business_date = '2026-01-01';
    writeFileSync(exactStatePath, JSON.stringify(damagedState), 'utf8');

    const afterDamage = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      sourceIds: '25,68',
      platforms: 'ctrip,meituan',
    });
    const afterDamageIds = dispatcherIds(afterDamage);
    assert.notEqual(afterDamageIds.collectionRunId, firstIds.collectionRunId);
    assert.match(afterDamage.log, /decision=rotated_invalid_state;prior_status=none/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('available plan fingerprint participates in collection scope and forces rotation on change', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'in_progress';
  fixture.environment.SUXIOS_OTA_COLLECTION_PLAN_FINGERPRINT = 'a'.repeat(64);
  try {
    const first = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    const firstIds = dispatcherIds(first);
    const firstState = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(firstState.plan_fingerprint, 'a'.repeat(64));

    fixture.environment.SUXIOS_OTA_COLLECTION_PLAN_FINGERPRINT = 'b'.repeat(64);
    const second = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    const secondIds = dispatcherIds(second);
    assert.notEqual(secondIds.collectionRunId, firstIds.collectionRunId);
    assert.match(second.log, /decision=new;prior_status=none/);
    assert.equal(collectionStateFiles(fixture).length, 2);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('mismatched child dispatcher receipt is never adopted as active collection state', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'in_progress';
  fixture.environment.FAKE_RECEIPT_DISPATCHER_RUN_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  try {
    const mismatched = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.notEqual(mismatched.status, 0, mismatched.log);
    const mismatchedIds = dispatcherIds(mismatched);
    const statePath = collectionStateFiles(fixture)[0];
    const failedClosedState = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(failedClosedState.collection_run_id, mismatchedIds.collectionRunId);
    assert.equal(failedClosedState.status, 'started');
    assert.equal(failedClosedState.status_source, 'runner_child_start');
    assert.match(
      mismatched.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );

    delete fixture.environment.FAKE_RECEIPT_DISPATCHER_RUN_ID;
    const next = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    const nextIds = dispatcherIds(next);
    assert.equal(nextIds.collectionRunId, mismatchedIds.collectionRunId);
    assert.match(next.log, /decision=reused_active;prior_status=started/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('missing child receipt keeps started UUID recoverable and forces a non-zero runner exit', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'missing';
  try {
    const missing = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(missing.status, 125, missing.log);
    const missingIds = dispatcherIds(missing);
    const statePath = collectionStateFiles(fixture)[0];
    const preservedState = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(preservedState.collection_run_id, missingIds.collectionRunId);
    assert.equal(preservedState.status, 'started');
    assert.equal(preservedState.status_source, 'runner_child_start');
    assert.match(
      missing.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );
    assert.match(missing.log, /dispatcher_terminal_status=finished;exit_code=125/);

    fixture.environment.FAKE_OTA_EXIT = '1';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'in_progress';
    const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    const recoveredIds = dispatcherIds(recovered);
    assert.equal(recoveredIds.collectionRunId, missingIds.collectionRunId);
    assert.match(recovered.log, /decision=reused_active;prior_status=started/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('successful child missing one required evidence field preserves started UUID and exits 125', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_SUCCESS_EVIDENCE_GAP = 'trust_receipt_digest';
  try {
    const rejected = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(rejected.status, 125, rejected.log);
    const rejectedIds = dispatcherIds(rejected);
    const statePath = collectionStateFiles(fixture)[0];
    const preservedState = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(preservedState.collection_run_id, rejectedIds.collectionRunId);
    assert.equal(preservedState.status, 'started');
    assert.equal(preservedState.status_source, 'runner_child_start');
    assert.match(
      rejected.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );
    assert.match(rejected.log, /dispatcher_terminal_status=finished;exit_code=125/);

    delete fixture.environment.FAKE_OTA_SUCCESS_EVIDENCE_GAP;
    const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(recovered.status, 0, `${recovered.stderr || recovered.stdout}\n${recovered.log}`);
    const recoveredIds = dispatcherIds(recovered);
    assert.equal(recoveredIds.collectionRunId, rejectedIds.collectionRunId);
    const succeededState = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(succeededState.status, 'succeeded');
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('one browser-profile source and one local-collector source can complete the same exact run', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_TASK_MIX = 'mixed';
  try {
    const completed = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(completed.status, 0, `${completed.stderr || completed.stdout}\n${completed.log}`);
    const completedIds = dispatcherIds(completed);
    const statePath = collectionStateFiles(fixture)[0];
    const state = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(state.collection_run_id, completedIds.collectionRunId);
    assert.equal(state.status, 'succeeded');
    assert.match(
      completed.log,
      /dispatcher_collection_state=stored;status=succeeded;source=child_structured_terminal_receipt/,
    );
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('browser-profile source carrying a local collector task id is rejected and remains recoverable', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_TASK_MIX = 'mixed_browser_with_local_id';
  try {
    const rejected = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(rejected.status, 125, rejected.log);
    const rejectedIds = dispatcherIds(rejected);
    const statePath = collectionStateFiles(fixture)[0];
    let state = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(state.collection_run_id, rejectedIds.collectionRunId);
    assert.equal(state.status, 'started');
    assert.match(
      rejected.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );

    fixture.environment.FAKE_OTA_TASK_MIX = 'mixed';
    const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(recovered.status, 0, `${recovered.stderr || recovered.stdout}\n${recovered.log}`);
    const recoveredIds = dispatcherIds(recovered);
    assert.equal(recoveredIds.collectionRunId, rejectedIds.collectionRunId);
    state = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(state.status, 'succeeded');
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('strict collection-run-only success recovers the same UUID after a fake ledger success is rejected', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_OMIT_AUTO_RECEIPT = '1';
  fixture.environment.FAKE_COLLECTION_RUN_SUCCESS_EVIDENCE_GAP = 'ledger_structure_verified';
  try {
    const rejected = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(rejected.status, 125, rejected.log);
    const rejectedIds = dispatcherIds(rejected);
    const statePath = collectionStateFiles(fixture)[0];
    let state = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(state.collection_run_id, rejectedIds.collectionRunId);
    assert.equal(state.status, 'started');
    assert.match(
      rejected.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );

    delete fixture.environment.FAKE_COLLECTION_RUN_SUCCESS_EVIDENCE_GAP;
    const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(recovered.status, 0, `${recovered.stderr || recovered.stdout}\n${recovered.log}`);
    const recoveredIds = dispatcherIds(recovered);
    assert.equal(recoveredIds.collectionRunId, rejectedIds.collectionRunId);
    state = JSON.parse(readFileSync(statePath, 'utf8'));
    assert.equal(state.status, 'succeeded');
    assert.match(
      recovered.log,
      /dispatcher_collection_state=stored;status=succeeded;source=child_structured_terminal_receipt/,
    );
    assert.doesNotMatch(recovered.log, /SUXIOS_AUTO_FETCH_RECEIPT=/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('realtime dispatcher is an independent hourly task with the same credential boundary', () => {
  assert.match(source, /\[switch\]\$Realtime/);
  assert.match(source, /SUXIOS OTA Realtime Dispatcher/);
  assert.match(source, /-Mode \{3\}/);
  assert.match(source, /\$realtimePollIntervalMinutes = 15/);
  assert.match(source, /New-ScheduledTaskTrigger -Daily -At/);
  assert.match(source, /MSFT_TaskRepetitionPattern/);
  assert.match(source, /New-DispatcherRepetitionPattern -Interval 'PT15M' -Duration 'P1D'/);
  assert.match(source, /effective_runs_per_day = if \(\$Realtime\) \{ 96 \}/);
  assert.match(source, /realtime_retry_window/);
  assert.match(source, /\$RealtimeMinute -le 14/);
  assert.match(source, /without colliding with the 08:30 daily run/);
  assert.match(source, /-MultipleInstances IgnoreNew/);
});

test('daily dispatcher keeps a seventh trigger so all six exponential retry attempts remain reachable', () => {
  assert.match(source, /\$dailyRetryOffsetsMinutes = @\(0, 14, 28, 42, 56, 70, 84\)/);
  assert.match(source, /daily_retry_window/);
  assert.match(source, /DailyAt must leave 84 minutes before midnight/);
  assert.match(source, /New-DispatcherRepetitionPattern -Interval 'PT14M' -Duration 'PT85M'/);
  assert.match(source, /daily \$DailyAt with bounded retries \+14m\/\+28m\/\+42m\/\+56m\/\+70m\/\+84m/);
  assert.match(source, /execution_time_limit_minutes = if \(\$Realtime\) \{ 25 \} else \{ 40 \}/);
  assert.match(source, /final slot remains after the fifth exponential retry cooldown/);
  assert.match(source, /\$candidateStartBoundary\.Date -gt \[datetimeoffset\]::Now\.Date/);
  assert.match(source, /\$nextDailyStart -le \(Get-Date\)/);
  assert.match(source, /\$nextDailyStart = \$nextDailyStart\.AddDays\(1\)/);
  assert.match(source, /\$triggerTime = \$effectiveDailyStartBoundary\.LocalDateTime/);
  assert.match(source, /preserves_deferred_daily_start = \$true/);
  assert.match(source, /wake_to_run = \$true/);
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
