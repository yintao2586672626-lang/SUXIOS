import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import {
  copyFileSync,
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
  const descendantMarker = path.join(root, 'ota-descendant-survived.marker');
  const descendantStartedMarker = path.join(root, 'ota-descendant-started.marker');
  writeFileSync(path.join(scriptsDirectory, 'fake_ota_descendant.ps1'), `
Start-Sleep -Seconds 4
[System.IO.File]::WriteAllText($env:FAKE_OTA_DESCENDANT_MARKER, 'survived')
`, 'utf8');
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
    $omitCollectionReceipt = (string)getenv('FAKE_OTA_OMIT_COLLECTION_RECEIPT') === '1';
    $taskMix = strtolower(trim((string)(getenv('FAKE_OTA_TASK_MIX') ?: 'all_local')));
    $swapAllBindings = (string)getenv('FAKE_OTA_SWAP_ALL_BINDINGS') === '1';
    $sourceReceipts = [];
    $sourceTasks = [];
    foreach ($platforms as $index => $platform) {
        $bindingIndex = $swapAllBindings ? (count($sourceIds) - 1 - $index) : $index;
        $sourceReceipts[] = [
            'platform' => $platform,
            'data_source_id' => $sourceIds[$bindingIndex] ?? 0,
        ];
        $browserProfileTask = in_array(
            $taskMix,
            ['mixed', 'mixed_browser_with_local_id'],
            true
        ) && $index === 0;
        $sourceTask = [
            'platform' => $platform,
            'data_source_id' => $sourceIds[$bindingIndex] ?? 0,
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
    $sensitiveValuesExposed = (string)getenv('FAKE_OTA_SENSITIVE_VALUES_EXPOSED') === '1';
    $receiptStream = strtolower(trim((string)getenv('FAKE_OTA_RECEIPT_STREAM')));
    $emitReceipt = static function (string $line) use ($receiptStream): void {
        if ($receiptStream === 'stderr') {
            fwrite(STDERR, $line . PHP_EOL);
            return;
        }
        echo $line . PHP_EOL;
    };
    $scopeHash = str_repeat('c', 64);
    if ($dispatcherRunId !== '') {
        if ((string)getenv('FAKE_OTA_EMIT_INITIAL_COLLECTION_RECEIPT') === '1') {
            $emitReceipt('SUXIOS_COLLECTION_RUN_RECEIPT=' . json_encode([
                'dispatcher_run_id' => $receiptDispatcherRunId,
                'system_hotel_id' => $hotelId,
                'business_date' => $targetDate,
                'status' => 'started',
                'source_receipts' => $sourceReceipts,
                'scope_hash' => $scopeHash,
                'sensitive_values_exposed' => false,
            ], JSON_UNESCAPED_SLASHES));
        }
        $planGate = [
            'schema_version' => 1,
            'status' => 'ready',
            'collection_allowed' => true,
            'tenant_id' => 1,
            'system_hotel_id' => $hotelId,
            'business_date' => $targetDate,
            'run_mode' => 'daily',
            'dispatcher_run_id' => $receiptDispatcherRunId,
            'plan_id' => 91,
            'plan_version' => 3,
            'plan_hash' => str_repeat('d', 64),
            'plan_readback_verified' => true,
            'binding_digest_matches' => true,
            'execution_owner_bound' => true,
            'execution_owner_user_id' => 7,
            'sources' => [
                'ctrip' => ['data_source_id' => $sourceIds[0] ?? 0],
                'meituan' => ['data_source_id' => $sourceIds[1] ?? 0],
            ],
            'expected_source_ids' => $sourceIds,
            'actual_source_ids' => $sourceIds,
            'expected_platforms' => $platforms,
            'actual_platforms' => $platforms,
            'scope_hash' => $scopeHash,
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
        if ((string)getenv('FAKE_OTA_PLAN_GATE_BLOCKED') === '1') {
            $planGate['binding_digest_matches'] = false;
        }
        $emitReceipt('SUXIOS_COLLECTION_PLAN_GATE=' . json_encode(
            $planGate,
            JSON_UNESCAPED_SLASHES
        ));
    }
    $outputLines = json_decode((string)getenv('FAKE_OTA_OUTPUT_LINES'), true);
    if (is_array($outputLines)) {
        foreach ($outputLines as $outputLine) {
            echo (string)$outputLine . PHP_EOL;
        }
    }
    $descendantMarker = trim((string)getenv('FAKE_OTA_DESCENDANT_MARKER'));
    if ($descendantMarker !== '') {
        $powershell = (string)getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $descendantScript = __DIR__ . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'fake_ota_descendant.ps1';
        $commandLine = escapeshellarg($powershell)
            . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
            . escapeshellarg($descendantScript);
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'a'],
            2 => ['file', 'NUL', 'a'],
        ];
        $descendantProcess = proc_open($commandLine, $descriptors, $descendantPipes);
        if (is_resource($descendantProcess)) {
            file_put_contents(
                (string)getenv('FAKE_OTA_DESCENDANT_STARTED_MARKER'),
                'started'
            );
        }
    }
    $holdSeconds = max(0, (int)getenv('FAKE_OTA_HOLD_SECONDS'));
    if ($holdSeconds > 0) {
        sleep($holdSeconds);
    }
    if ($lifecycleStatus !== 'missing') {
        $legacyTrustFinalization = (string)getenv('FAKE_OTA_TRUST_FINALIZATION_LEGACY') === '1';
        $terminalEvidenceGap = trim((string)getenv('FAKE_OTA_TERMINAL_EVIDENCE_GAP'));
        $parentFailureStage = match ($lifecycleStatus) {
            'partial', 'failed' => $legacyTrustFinalization ? 'trust_finalization' : 'collection',
            'blocked' => 'plan_gate',
            'skipped' => 'scheduler_cache',
            'deferred' => 'scheduler_retry',
            default => '',
        };
        $parentFailureCode = match ($lifecycleStatus) {
            'partial' => $legacyTrustFinalization
                ? 'collection_trust_not_ready'
                : 'collection_partial',
            'failed' => $legacyTrustFinalization
                ? 'collection_trust_not_ready'
                : 'collection_failed',
            'blocked' => 'plan_not_execution_ready',
            'skipped' => 'verified_cache_reused',
            'deferred' => 'retry_cooldown',
            default => '',
        };
        $collectionSourceReceipts = [];
        foreach ($sourceTasks as $index => $sourceTask) {
            $sourceStatus = match (true) {
                $lifecycleStatus === 'succeeded' || $legacyTrustFinalization => 'success',
                $lifecycleStatus === 'partial' && $index === 0 => 'success',
                in_array($lifecycleStatus, ['partial', 'failed'], true) => 'failed',
                in_array($lifecycleStatus, ['blocked', 'skipped', 'deferred'], true) => $lifecycleStatus,
                default => 'declared',
            };
            $sourceSucceeded = $sourceStatus === 'success';
            $collectionAttempted = in_array($lifecycleStatus, ['succeeded', 'partial', 'failed'], true)
                || $legacyTrustFinalization;
            $collectionSourceReceipts[] = [
                'platform' => $sourceTask['platform'],
                'data_source_id' => $sourceTask['data_source_id'],
                'ingestion_method' => $sourceTask['ingestion_method'],
                'platform_sync_task_id' => $collectionAttempted
                    ? $sourceTask['sync_task_id']
                    : null,
                'local_collector_task_id' => $collectionAttempted
                    ? ($sourceTask['local_collector_task_id'] ?? null)
                    : null,
                'status' => $sourceStatus,
                'failure_stage' => $sourceSucceeded ? '' : $parentFailureStage,
                'failure_code' => $sourceSucceeded ? '' : $parentFailureCode,
                'saved_row_count' => $sourceSucceeded ? 2 : 0,
                'readback_row_count' => $sourceSucceeded ? 2 : 0,
                'readback_verified' => $sourceSucceeded,
                'finished_at' => '2026-08-10 08:31:00',
                'automatic_device_substitution' => false,
                'sensitive_values_exposed' => false,
            ];
        }
        $collectionRunReceipt = [
            'dispatcher_run_id' => $receiptDispatcherRunId,
            'system_hotel_id' => $hotelId,
            'business_date' => $targetDate,
            'status' => $lifecycleStatus,
            'source_receipts' => in_array(
                $lifecycleStatus,
                ['succeeded', 'partial', 'failed', 'blocked', 'skipped', 'deferred'],
                true
            )
                ? $collectionSourceReceipts
                : $sourceReceipts,
            'collection_anchor_hash' => $lifecycleStatus === 'succeeded' ? $anchorHash : null,
            'trust_receipt_digest' => $lifecycleStatus === 'succeeded' ? $trustReceiptDigest : null,
            'scope_hash' => $scopeHash,
            'sensitive_values_exposed' => $sensitiveValuesExposed,
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
        } elseif (in_array(
            $lifecycleStatus,
            ['partial', 'failed', 'blocked', 'skipped', 'deferred'],
            true
        )) {
            $collectionRunReceipt += [
                'failure_stage' => $parentFailureStage,
                'failure_code' => $parentFailureCode,
                'ledger_structure_verified' => true,
                'readback_verified' => true,
                'finished_at' => '2026-08-10 08:31:00',
            ];
            if ($terminalEvidenceGap === 'parent_readback_verified') {
                unset($collectionRunReceipt['readback_verified']);
            } elseif ($terminalEvidenceGap === 'parent_finished_at') {
                unset($collectionRunReceipt['finished_at']);
            } elseif ($terminalEvidenceGap === 'parent_failure_code') {
                unset($collectionRunReceipt['failure_code']);
            } elseif ($terminalEvidenceGap === 'source_readback_verified') {
                unset($collectionRunReceipt['source_receipts'][1]['readback_verified']);
            } elseif ($terminalEvidenceGap === 'source_finished_at') {
                unset($collectionRunReceipt['source_receipts'][1]['finished_at']);
            } elseif ($terminalEvidenceGap === 'source_failure_code') {
                unset($collectionRunReceipt['source_receipts'][1]['failure_code']);
            }
        }
        if (!$omitCollectionReceipt) {
            $emitReceipt('SUXIOS_COLLECTION_RUN_RECEIPT=' . json_encode(
                $collectionRunReceipt,
                JSON_UNESCAPED_SLASHES
            ));
        }
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
                'sensitive_values_exposed' => $sensitiveValuesExposed,
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
                if ((string)getenv('FAKE_OTA_SWAP_AUTO_BINDINGS') === '1') {
                    foreach ($autoReceipt['source_tasks'] as $index => &$sourceTask) {
                        $sourceTask['data_source_id'] = $sourceIds[
                            count($sourceIds) - 1 - $index
                        ] ?? 0;
                    }
                    unset($sourceTask);
                }
                if ((string)getenv('FAKE_OTA_DRIFT_AUTO_EXECUTION_BINDING') === '1') {
                    $autoReceipt['source_tasks'][0]['sync_task_id'] += 5000;
                    $autoReceipt['source_tasks'][0]['local_collector_task_id'] += 5000;
                }
            }
            $autoStatusOverride = strtolower(trim((string)getenv('FAKE_OTA_AUTO_STATUS_OVERRIDE')));
            if ($autoStatusOverride !== '') {
                $autoReceipt['status'] = $autoStatusOverride;
            }
            $emitReceipt('SUXIOS_AUTO_FETCH_RECEIPT=' . json_encode(
                $autoReceipt,
                JSON_UNESCAPED_SLASHES
            ));
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
    descendantMarker,
    descendantStartedMarker,
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
  collectionTimeoutSeconds = 0,
  mode = 'Daily',
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
    mode,
    '-HotelId',
    '80',
    '-SourceIds',
    sourceIds,
    '-Platforms',
    platforms,
  ];
  if (preflightOnly) args.push('-PreflightOnly');
  if (collectionTimeoutSeconds > 0) {
    args.push('-CollectionTimeoutSeconds', String(collectionTimeoutSeconds));
  }
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

test('strict collection-run ledger can prove success when no AUTO receipt exists', () => {
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
  assert.match(runner, /\$collectionRunReceiptStatus -in \$collectionTerminalStatuses/);
  assert.match(runner, /\$collectionRunSuccessReceiptSeen/);
  assert.match(runner, /\$collectionRunSuccessReceiptsValid/);
});

test('success receipts bind each platform to one exact source and COLLECTION is authoritative', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const verifierStart = runner.indexOf('function Test-DispatcherAutoFetchSuccessReceipt');
  const resolverStart = runner.indexOf('function Resolve-DispatcherCollectionOutputStatus');
  const successContract = runner.slice(verifierStart, resolverStart);
  const resolverEnd = runner.indexOf('$collectionStateEnabled', resolverStart);
  const resolver = runner.slice(resolverStart, resolverEnd);

  assert.match(successContract, /sensitive_values_exposed/);
  assert.match(successContract, /platform_source_bindings_text/);
  assert.match(resolver, /collection_run_authoritative/);
  assert.match(resolver, /dispatcher_collection_output_receipt_disagreement/);
});

test('runner has a process-lifetime hotel lock and bounded child execution with tree termination', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  assert.match(runner, /FileShare\]::None/);
  assert.match(runner, /scope=hotel/);
  assert.match(runner, /lease=process_lifetime/);
  assert.match(runner, /function Stop-DispatcherProcessTree/);
  assert.match(runner, /function Initialize-DispatcherPrivateCaptureFile/);
  assert.match(runner, /SetAccessRuleProtection\(\$true, \$false\)/);
  assert.match(runner, /FileSystemRights\]::FullControl/);
  assert.match(runner, /Initialize-DispatcherPrivateCaptureFile -Path \$stdoutPath/);
  assert.match(runner, /Initialize-DispatcherPrivateCaptureFile -Path \$stderrPath/);
  assert.match(runner, /\/T/);
  assert.match(runner, /CollectionTimeoutSeconds/);
  assert.match(runner, /CollectionTimeoutSeconds -gt \$maximumCollectionTimeoutSeconds/);
  assert.match(runner, /dispatcher_child=timed_out/);
  assert.doesNotMatch(runner, /Remove-Item[^\n]*dispatcher.*\.lock/i);
});

test('dispatcher log redaction covers credential aliases, bearer values, and URL queries', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const redactorStart = runner.indexOf('function ConvertTo-SafeDispatcherLine');
  const processStart = runner.indexOf('function Invoke-SafeDispatcherProcess');
  const redactor = runner.slice(redactorStart, processStart);
  for (const credentialName of [
    'token',
    'access',
    'refresh',
    'api',
    'cookie',
    'auth',
    'password',
    'secret',
    'session',
    'signature',
    'mtgsig',
    'spiderkey',
    'ticket',
  ]) {
    assert.match(redactor, new RegExp(credentialName, 'i'));
  }
  assert.match(redactor, /bearer/i);
  assert.match(redactor, /https\?/i);
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
  const firstLogWrite = runner.indexOf('[System.IO.File]::WriteAllLines($logPath', startedStatus);
  const processStart = runner.indexOf('$process = Start-Process');
  const finishedStatus = runner.indexOf('dispatcher_terminal_status=finished;exit_code=$exitCode', processStart);
  const finalLogWrite = runner.lastIndexOf('[System.IO.File]::WriteAllLines($logPath');

  assert(startedStatus >= 0 && firstLogWrite > startedStatus && processStart > firstLogWrite);
  assert(finishedStatus > processStart && finalLogWrite > finishedStatus);
  assert.match(runner, /verify_canonical_ota_daily_natural_acceptance\.php/);
  assert.match(runner, /dispatcher_daily_acceptance_readback_verified=/);
  assert.match(runner, /non_blocking=true/);
});

test('finish provenance selects one terminal collection receipt when AUTO is absent', () => {
  const runner = readFileSync(path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'), 'utf8');
  const start = runner.indexOf('function Get-DispatcherFinishProvenance');
  const end = runner.indexOf('function Write-DispatcherLogAtomically', start);
  const resolver = runner.slice(start, end > start ? end : undefined);
  assert.ok(start >= 0);
  assert.match(resolver, /\^SUXIOS_AUTO_FETCH_RECEIPT=/);
  assert.match(resolver, /\^SUXIOS_COLLECTION_RUN_RECEIPT=/);
  assert.match(resolver, /\$collectionRunStatus -in \$collectionTerminalStatuses/);
  assert.match(resolver, /if \(\$autoFetchReceiptLines\.Count -gt 0\)/);
  assert.match(resolver, /\$terminalCollectionRunReceiptLines/);
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

test('blocked COLLECTION-only child is one exact provenance receipt, never ambiguous', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  const provenanceDirectory = path.join(fixture.root, 'scripts', 'lib');
  mkdirSync(provenanceDirectory, { recursive: true });
  copyFileSync(
    path.join(repoRoot, 'scripts', 'lib', 'ota_dispatcher_provenance.ps1'),
    path.join(provenanceDirectory, 'ota_dispatcher_provenance.ps1'),
  );
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '78';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'blocked';
  fixture.environment.FAKE_OTA_OMIT_AUTO_RECEIPT = '1';
  try {
    const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(result.status, 78, `${result.stderr || result.stdout}\n${result.log}`);
    const collectionLines = result.log
      .split(/\r?\n/)
      .filter(line => line.startsWith('SUXIOS_COLLECTION_RUN_RECEIPT='));
    assert.equal(collectionLines.length, 1, result.log);
    assert.doesNotMatch(result.log, /SUXIOS_AUTO_FETCH_RECEIPT=/);
    const finishLine = result.log
      .split(/\r?\n/)
      .find(line => line.startsWith('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
        && line.includes('"phase":"finish"'));
    assert.ok(finishLine, result.log);
    const finishReceipt = JSON.parse(finishLine.slice(
      'SUXIOS_OTA_DISPATCHER_PROVENANCE='.length,
    ));
    assert.equal(finishReceipt.child_receipt_present, true);
    assert.equal(finishReceipt.child_receipt_count, 1);
    assert.match(String(finishReceipt.child_receipt_sha256 || ''), /^[a-f0-9]{64}$/);
    assert.equal(finishReceipt.child_exit_code, 78);
    assert.notEqual(finishReceipt.natural_run_reason, 'child_receipt_missing');
    assert.notEqual(finishReceipt.natural_run_reason, 'child_receipt_ambiguous');
    assert.match(result.log, /dispatcher_terminal_status=finished;exit_code=78/);
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
    assert.equal(secondIds.collectionRunId, firstIds.collectionRunId, second.log);
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

test('durable failed source receipts are terminal and rotate only on the following run', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'failed';
  try {
    const first = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(first.status, 1, `${first.stderr || first.stdout}\n${first.log}`);
    const firstIds = dispatcherIds(first);
    let state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'failed');
    assert.equal(state.collection_run_id, firstIds.collectionRunId);
    assert.match(first.log, /dispatcher_collection_state=stored;status=failed;source=collection_run_authoritative/);

    const second = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(second.status, 1, `${second.stderr || second.stdout}\n${second.log}`);
    const secondIds = dispatcherIds(second);
    assert.notEqual(secondIds.collectionRunId, firstIds.collectionRunId);
    assert.match(second.log, /decision=rotated_terminal;prior_status=failed/);
    state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.collection_run_id, secondIds.collectionRunId);
    assert.equal(state.status, 'failed');
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('non-success terminal gaps preserve started state and the exact UUID', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  for (const evidenceGap of [
    'collection_receipt_missing',
    'source_readback_verified',
    'parent_finished_at',
    'parent_failure_code',
  ]) {
    const fixture = isolatedPreflightFixture(0);
    writeFileSync(fixture.statePath, 'ready', 'utf8');
    fixture.environment.FAKE_OTA_EXIT = '1';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'failed';
    if (evidenceGap === 'collection_receipt_missing') {
      fixture.environment.FAKE_OTA_OMIT_COLLECTION_RECEIPT = '1';
    } else {
      fixture.environment.FAKE_OTA_TERMINAL_EVIDENCE_GAP = evidenceGap;
    }
    try {
      const rejected = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
      assert.equal(rejected.status, 1, `${evidenceGap}\n${rejected.log}`);
      const rejectedIds = dispatcherIds(rejected);
      let state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
      assert.equal(state.status, 'started', evidenceGap);
      assert.equal(state.collection_run_id, rejectedIds.collectionRunId, evidenceGap);
      assert.match(
        rejected.log,
        /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
      );

      delete fixture.environment.FAKE_OTA_TERMINAL_EVIDENCE_GAP;
      delete fixture.environment.FAKE_OTA_OMIT_COLLECTION_RECEIPT;
      const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
      assert.equal(recovered.status, 1, `${evidenceGap}\n${recovered.log}`);
      const recoveredIds = dispatcherIds(recovered);
      assert.equal(recoveredIds.collectionRunId, rejectedIds.collectionRunId, evidenceGap);
      assert.match(recovered.log, /decision=reused_active;prior_status=started/);
      state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
      assert.equal(state.status, 'failed', evidenceGap);
    } finally {
      rmSync(fixture.root, { recursive: true, force: true });
    }
  }
});

test('legacy trust-finalization terminal receipt remains collected on the same UUID', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'partial';
  fixture.environment.FAKE_OTA_TRUST_FINALIZATION_LEGACY = '1';
  try {
    const legacy = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(legacy.status, 1, `${legacy.stderr || legacy.stdout}\n${legacy.log}`);
    const legacyIds = dispatcherIds(legacy);
    let state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'collected');
    assert.equal(state.collection_run_id, legacyIds.collectionRunId);
    assert.match(
      legacy.log,
      /dispatcher_collection_state=stored;status=collected;source=collection_run_recoverable_trust_finalization/,
    );

    fixture.environment.FAKE_OTA_EXIT = '0';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
    delete fixture.environment.FAKE_OTA_TRUST_FINALIZATION_LEGACY;
    const recovered = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(recovered.status, 0, `${recovered.stderr || recovered.stdout}\n${recovered.log}`);
    const recoveredIds = dispatcherIds(recovered);
    assert.equal(recoveredIds.collectionRunId, legacyIds.collectionRunId);
    assert.match(recovered.log, /decision=reused_active;prior_status=collected/);
    state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'succeeded');
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

test('zero child exit cannot turn failed or active collection receipts into dispatcher success', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  for (const lifecycleStatus of ['failed', 'in_progress']) {
    const fixture = isolatedPreflightFixture(0);
    writeFileSync(fixture.statePath, 'ready', 'utf8');
    fixture.environment.FAKE_OTA_EXIT = '0';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = lifecycleStatus;
    try {
      const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
      assert.notEqual(result.status, 0, `${lifecycleStatus}\n${result.log}`);
      assert.doesNotMatch(result.log, /dispatcher_terminal_status=finished;exit_code=0/);
      assert.match(result.log, /dispatcher_child_zero_exit_rejected=/);
    } finally {
      rmSync(fixture.root, { recursive: true, force: true });
    }
  }
});

test('stderr machine receipts are diagnostic only and cannot authorize success', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_RECEIPT_STREAM = 'stderr';
  try {
    const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(result.status, 125, result.log);
    const state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'started');
    assert.match(
      result.log,
      /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/,
    );
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('realtime zero exit is rejected when stdout declares a non-success receipt', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'failed';
  try {
    const result = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      mode: 'Realtime',
    });
    assert.equal(result.status, 1, result.log);
    assert.match(result.log, /dispatcher_child_zero_exit_rejected=status:failed;final_exit_code=1/);
    assert.doesNotMatch(result.log, /dispatcher_terminal_status=finished;exit_code=0/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('success rejects swapped bindings, AUTO disagreement, COLLECTION evidence gaps, and exposed values', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const cases = [
    ['all bindings swapped', { FAKE_OTA_SWAP_ALL_BINDINGS: '1' }],
    ['AUTO binding disagrees', { FAKE_OTA_SWAP_AUTO_BINDINGS: '1' }],
    ['AUTO execution task binding disagrees', {
      FAKE_OTA_DRIFT_AUTO_EXECUTION_BINDING: '1',
    }],
    ['AUTO status disagrees', { FAKE_OTA_AUTO_STATUS_OVERRIDE: 'failed' }],
    ['COLLECTION evidence incomplete', {
      FAKE_COLLECTION_RUN_SUCCESS_EVIDENCE_GAP: 'ledger_structure_verified',
    }],
    ['plan gate is not ready', { FAKE_OTA_PLAN_GATE_BLOCKED: '1' }],
    ['sensitive values reported exposed', { FAKE_OTA_SENSITIVE_VALUES_EXPOSED: '1' }],
  ];
  for (const [label, environment] of cases) {
    const fixture = isolatedPreflightFixture(0);
    writeFileSync(fixture.statePath, 'ready', 'utf8');
    fixture.environment.FAKE_OTA_EXIT = '0';
    fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
    Object.assign(fixture.environment, environment);
    try {
      const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
      assert.equal(result.status, 125, `${label}\n${result.stderr || result.stdout}\n${result.log}`);
      const state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
      assert.equal(state.status, 'started', label);
      assert.match(result.log, /dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted/);
    } finally {
      rmSync(fixture.root, { recursive: true, force: true });
    }
  }
});

test('daily success accepts one initial durable receipt followed by the exact terminal receipt', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_EMIT_INITIAL_COLLECTION_RECEIPT = '1';
  try {
    const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(result.status, 0, `${result.stderr || result.stdout}\n${result.log}`);
    const state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'succeeded');
    assert.match(result.log, /dispatcher_collection_state=stored;status=succeeded/);
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('dispatcher final log fail-closes every credential family and URL query', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '1';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'failed';
  const secrets = [
    'TOKEN_VALUE_1', 'ACCESS_VALUE_2', 'REFRESH_VALUE_3', 'API_KEY_VALUE_4',
    'BEARER_VALUE_5', 'QUERY_VALUE_6', 'COOKIE_VALUE_7', 'AUTH_VALUE_8',
    'PASSWORD_VALUE_9', 'SECRET_VALUE_10', 'SESSION_VALUE_11', 'SIGNATURE_VALUE_12',
    'MTGSIG_VALUE_13', 'SPIDERKEY_VALUE_14', 'TICKET_VALUE_15', 'CLI_CREDENTIAL_ZZZ',
    'COOKIE_JAR_VALUE_17', 'URL_USERINFO_VALUE_18',
    'ESCAPED_COOKIE_VALUE_19', 'ESCAPED_JAR_VALUE_20', 'ESCAPED_USERINFO_VALUE_21',
    'TRIPLE_COOKIE_VALUE_22', 'TRIPLE_USERINFO_VALUE_23',
    'UNICODE_COOKIE_VALUE_24', 'UNICODE_USERINFO_VALUE_25', 'UNICODE_FULL_URL_VALUE_26',
  ];
  const nestedEscape = value => value
    .replaceAll('\\', '\\\\')
    .replaceAll('"', '\\"')
    .replaceAll('/', '\\/');
  let tripleEscapedCookie = `{"cookies":[{"value":"${secrets[21]}"}]}`;
  let tripleEscapedUserinfo = `https://user:${secrets[22]}@example.invalid/path`;
  for (let pass = 0; pass < 3; pass += 1) {
    tripleEscapedCookie = nestedEscape(tripleEscapedCookie);
    tripleEscapedUserinfo = nestedEscape(tripleEscapedUserinfo);
  }
  fixture.environment.FAKE_OTA_OUTPUT_LINES = JSON.stringify([
    `token=${secrets[0]}`,
    `access_token: ${secrets[1]}`,
    `{"refresh_token":"${secrets[2]}"}`,
    `api_key='${secrets[3]}'`,
    `Authorization: Bearer ${secrets[4]}`,
    `https://example.invalid/callback?code=${secrets[5]}`,
    `Cookie: sid=${secrets[6]}`,
    `auth=${secrets[7]}`,
    `password=${secrets[8]}`,
    `secret=${secrets[9]}`,
    `session=${secrets[10]}`,
    `signature=${secrets[11]}`,
    `mtgsig=${secrets[12]}`,
    `spiderkey=${secrets[13]}`,
    `ticket=${secrets[14]}`,
    `--refresh-token ${secrets[15]}`,
    `{"cookies":[{"name":"foo","value":"${secrets[16]}"}]}`,
    `https://user:${secrets[17]}@example.invalid/path`,
    String.raw`{\"cookies\":[{\"name\":\"foo\",\"value\":\"${secrets[18]}\"}]}`,
    String.raw`{\"cookieJar\":{\"value\":\"${secrets[19]}\"}}`,
    String.raw`https:\/\/user:${secrets[20]}@example.invalid/path`,
    tripleEscapedCookie,
    tripleEscapedUserinfo,
    String.raw`{"\u0063\u006f\u006f\u006b\u0069\u0065\u0073":[{"value":"${secrets[23]}"}]}`,
    String.raw`https\u003a\u002f\u002fuser\u003a${secrets[24]}\u0040example.invalid\u002fpath`,
    String.raw`\u0068\u0074\u0074\u0070\u0073\u003a\u002f\u002fuser\u003a${secrets[25]}\u0040example.invalid`,
  ]);
  try {
    const result = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(result.status, 1, result.log);
    for (const secret of secrets) assert.ok(!result.log.includes(secret), secret);
    assert.ok(
      (result.log.match(/\[sensitive dispatcher output suppressed\]/g) || []).length >= secrets.length,
      result.log,
    );
  } finally {
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('same-hotel Daily and Realtime runners cannot overlap or delete another owner lock', {
  skip: process.platform !== 'win32',
}, async (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  // Leave enough time for a second Windows PowerShell process to load the
  // full runner and reach lock acquisition on slower hosts.
  fixture.environment.FAKE_OTA_HOLD_SECONDS = '10';
  const powershell = path.join(
    process.env.SystemRoot || 'C:\\Windows',
    'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe',
  );
  const firstArgs = [
    '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
    path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'),
    '-ProjectRoot', fixture.root, '-PhpPath', phpPath, '-Mode', 'Daily',
    '-HotelId', '80', '-SourceIds', '25,68', '-Platforms', 'ctrip,meituan',
  ];
  const first = spawn(powershell, firstArgs, {
    cwd: fixture.root,
    env: fixture.environment,
    windowsHide: true,
    stdio: 'ignore',
  });
  const firstClosed = new Promise(resolve => first.once('close', resolve));
  try {
    const deadline = Date.now() + 10_000;
    while (!existsSync(fixture.otaMarker) && Date.now() < deadline) {
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert.equal(existsSync(fixture.otaMarker), true, 'first dispatcher did not enter the child window');

    // The first process already owns a copied environment. Keep a wrongly
    // admitted second child fast so a lock regression fails promptly.
    fixture.environment.FAKE_OTA_HOLD_SECONDS = '0';
    const second = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      mode: 'Realtime',
    });
    assert.equal(second.status, 125, second.log);
    assert.match(second.log, /dispatcher_scope_lock=blocked;scope=hotel:80/);
    assert.equal(existsSync(path.join(fixture.runtimeDirectory, 'ota_dispatcher_hotel_80.lock')), true);

    const firstStatus = await firstClosed;
    assert.equal(firstStatus, 0);
    assert.equal(existsSync(path.join(fixture.runtimeDirectory, 'ota_dispatcher_hotel_80.lock')), true);
  } finally {
    if (first.exitCode === null) first.kill();
    await firstClosed;
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('legacy global runner and a hotel-scoped runner cannot overlap', {
  skip: process.platform !== 'win32',
}, async (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  // Leave an unambiguous overlap window across Windows process startup.
  fixture.environment.FAKE_OTA_HOLD_SECONDS = '10';
  const powershell = path.join(
    process.env.SystemRoot || 'C:\\Windows',
    'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe',
  );
  const globalArgs = [
    '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
    path.join(repoRoot, 'scripts', 'run_ota_dispatcher.ps1'),
    '-ProjectRoot', fixture.root, '-PhpPath', phpPath, '-Mode', 'Daily',
  ];
  const first = spawn(powershell, globalArgs, {
    cwd: fixture.root,
    env: fixture.environment,
    windowsHide: true,
    stdio: 'ignore',
  });
  const firstClosed = new Promise(resolve => first.once('close', resolve));
  try {
    const deadline = Date.now() + 10_000;
    while (!existsSync(fixture.otaMarker) && Date.now() < deadline) {
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert.equal(existsSync(fixture.otaMarker), true, 'global dispatcher did not enter child window');

    fixture.environment.FAKE_OTA_HOLD_SECONDS = '0';
    const scoped = runIsolatedPreflight(fixture, phpPath, { preflightOnly: false });
    assert.equal(scoped.status, 125, scoped.log);
    assert.match(scoped.log, /dispatcher_scope_lock=blocked;scope=hotel:80/);

    const firstStatus = await firstClosed;
    assert.equal(firstStatus, 0);
    assert.equal(
      readdirSync(fixture.runtimeDirectory).some(name => name.endsWith('.tmp')),
      false,
      'dispatcher raw capture files must be removed after the owner exits',
    );
  } finally {
    if (first.exitCode === null) first.kill();
    await firstClosed;
    rmSync(fixture.root, { recursive: true, force: true });
  }
});

test('collection child timeout is internal, non-zero, and leaves the exact run recoverable', {
  skip: process.platform !== 'win32',
}, (t) => {
  const phpPath = localPhpPath();
  if (!phpPath) return t.skip('local PHP runtime is unavailable');
  const fixture = isolatedPreflightFixture(0);
  writeFileSync(fixture.statePath, 'ready', 'utf8');
  fixture.environment.FAKE_OTA_EXIT = '0';
  fixture.environment.FAKE_OTA_LIFECYCLE_STATUS = 'succeeded';
  fixture.environment.FAKE_OTA_HOLD_SECONDS = '5';
  fixture.environment.FAKE_OTA_DESCENDANT_MARKER = fixture.descendantMarker;
  fixture.environment.FAKE_OTA_DESCENDANT_STARTED_MARKER = fixture.descendantStartedMarker;
  try {
    const started = Date.now();
    const result = runIsolatedPreflight(fixture, phpPath, {
      preflightOnly: false,
      collectionTimeoutSeconds: 2,
    });
    const elapsed = Date.now() - started;
    assert.equal(result.status, 124, result.log);
    // This measures the complete Windows PowerShell runner, including two PHP
    // database probes, provenance hashing and antivirus/process startup. The
    // child itself remains bounded at two seconds and must return exit 124;
    // keep a generous outer ceiling so host load does not make the safety
    // contract flaky while a genuinely stuck runner still fails promptly.
    assert.ok(elapsed < 20_000, `timeout took ${elapsed}ms`);
    assert.ok(elapsed < 8_000, `timeout took ${elapsed}ms`);
    assert.match(result.log, /dispatcher_child=timed_out;timeout_seconds=2;exit_code=124/);
    const state = JSON.parse(readFileSync(collectionStateFiles(fixture)[0], 'utf8'));
    assert.equal(state.status, 'started');
    assert.equal(existsSync(fixture.descendantStartedMarker), true, 'descendant fixture did not start');
    const descendantDeadline = Date.now() + 4_500;
    while (Date.now() < descendantDeadline) {
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 100);
    }
    assert.equal(existsSync(fixture.descendantMarker), false, 'timed-out child process tree survived');
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
      /dispatcher_collection_state=stored;status=succeeded;source=collection_run_authoritative/,
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
      /dispatcher_collection_state=stored;status=succeeded;source=collection_run_authoritative/,
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
