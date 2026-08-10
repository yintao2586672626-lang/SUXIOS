[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$ProjectRoot,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$PhpPath,

    [ValidateSet('Daily', 'Realtime')]
    [string]$Mode = 'Daily',

    [ValidateRange(0, 2147483647)]
    [int]$HotelId = 0,

    [ValidatePattern('^(?:|[1-9][0-9]*(?:,[1-9][0-9]*)*)$')]
    [string]$SourceIds = '',

    [ValidateSet('', 'ctrip', 'meituan', 'ctrip,meituan', 'meituan,ctrip')]
    [string]$Platforms = '',

    [switch]$PreflightOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$utf8 = [System.Text.UTF8Encoding]::new($false)

$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$thinkPath = Join-Path $resolvedRoot 'think'
if (-not (Test-Path -LiteralPath $thinkPath -PathType Leaf)) {
    throw "Think entry was not found: $thinkPath"
}
$explicitScopeRequested = $HotelId -gt 0 -or $SourceIds -ne '' -or $Platforms -ne ''
$explicitScopeComplete = $HotelId -gt 0 -and $SourceIds -ne '' -and $Platforms -ne ''
if ($explicitScopeRequested -and -not $explicitScopeComplete) {
    throw 'Scoped OTA dispatcher requires HotelId, SourceIds, and Platforms together.'
}

$logDirectory = Join-Path $resolvedRoot 'runtime\dispatcher'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$dispatcherRunGuid = [guid]::NewGuid()
$runId = (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + $dispatcherRunGuid.ToString('N').ToLowerInvariant()
$logPath = Join-Path $logDirectory "ota_dispatcher_$runId.log"

$shanghaiTimeZone = [System.TimeZoneInfo]::FindSystemTimeZoneById('China Standard Time')
$shanghaiNow = [System.TimeZoneInfo]::ConvertTime([System.DateTimeOffset]::UtcNow, $shanghaiTimeZone)
$dailyTargetDate = ''
if ($Mode -eq 'Daily') {
    # Windows Task Scheduler runs in the host timezone, but the OTA business
    # date contract is explicitly Asia/Shanghai. Pin one exact previous-day
    # date so stale historical retry records cannot expand a daily run into
    # older business dates.
    $dailyTargetDate = $shanghaiNow.Date.AddDays(-1).ToString(
        'yyyy-MM-dd',
        [System.Globalization.CultureInfo]::InvariantCulture
    )
}
$provenanceTargetDate = if ($Mode -eq 'Daily') {
    $dailyTargetDate
} else {
    $shanghaiNow.Date.ToString('yyyy-MM-dd', [System.Globalization.CultureInfo]::InvariantCulture)
}

function ConvertTo-SafeDispatcherLine {
    param([AllowNull()][object]$Value)

    $line = [string]$Value
    # The scheduled command must never put authorization material in its
    # diagnostic log. Suppress a whole suspicious line instead of attempting
    # partial redaction that can leave a new platform key format exposed.
    if ($line -match '(?i)(?:cookie|spidertoken|authorization|password|secret|session|credential|profile)\s*(?:[:=]|[\"''])') {
        return '[sensitive dispatcher output suppressed]'
    }
    # Keep the task log machine-readable. Native PHP localized output has an
    # inconsistent legacy-console decode on this Windows host; the structured
    # receipt below is the authoritative diagnostic evidence and contains the
    # hotel/date/platform state without exposing a garbled display name.
    if ($line -notmatch '^SUXIOS_AUTO_FETCH_RECEIPT=' -and $line -match '[^\x00-\x7F]') {
        return '[localized dispatcher detail omitted; inspect the safe receipt]'
    }
    return $line
}

function Invoke-SafeDispatcherProcess {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$ArgumentList,
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][ValidatePattern('^[a-z0-9_]+$')][string]$Label,
        [Parameter(Mandatory = $true)][ValidateRange(1, 300)][int]$TimeoutSeconds
    )

    $safeStdoutPath = Join-Path $logDirectory "ota_dispatcher_$runId.$Label.stdout.tmp"
    $safeStderrPath = Join-Path $logDirectory "ota_dispatcher_$runId.$Label.stderr.tmp"
    $childProcess = $null
    try {
        $childProcess = Start-Process `
            -FilePath $FilePath `
            -ArgumentList $ArgumentList `
            -WorkingDirectory $WorkingDirectory `
            -RedirectStandardOutput $safeStdoutPath `
            -RedirectStandardError $safeStderrPath `
            -PassThru `
            -NoNewWindow
        # Windows PowerShell 5.1 can drop the native process handle when a
        # short-lived redirected child exits. In that case ExitCode becomes
        # $null and an integer cast would incorrectly report success (0).
        # Materialize the handle while the child is live and fail closed if an
        # exit code still cannot be obtained.
        $null = $childProcess.Handle
        $completed = $childProcess.WaitForExit($TimeoutSeconds * 1000)
        if (-not $completed) {
            try {
                $childProcess.Kill()
                $childProcess.WaitForExit()
            } catch {
                # The structured timeout result remains authoritative even when
                # the child exits between the timeout and termination attempt.
            }
            return [pscustomobject]@{
                exit_code = 124
                timed_out = $true
                exception_type = ''
                stdout_lines = @()
            }
        }
        # Flush redirected streams before their temporary files are removed.
        $childProcess.WaitForExit()
        $capturedExitCode = $childProcess.ExitCode
        if ($null -eq $capturedExitCode) {
            return [pscustomobject]@{
                exit_code = 125
                timed_out = $false
                exception_type = 'child_exit_code_unavailable'
                stdout_lines = @()
            }
        }
        $safeStdoutLines = if (Test-Path -LiteralPath $safeStdoutPath -PathType Leaf) {
            @([System.IO.File]::ReadAllLines($safeStdoutPath, $utf8))
        } else {
            @()
        }
        return [pscustomobject]@{
            exit_code = [int]$capturedExitCode
            timed_out = $false
            exception_type = ''
            stdout_lines = $safeStdoutLines
        }
    } catch {
        return [pscustomobject]@{
            exit_code = 125
            timed_out = $false
            exception_type = $_.Exception.GetType().FullName
            stdout_lines = @()
        }
    } finally {
        foreach ($temporaryPath in @($safeStdoutPath, $safeStderrPath)) {
            if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
                Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
            }
        }
    }
}

$provenanceHelperPath = Join-Path $resolvedRoot 'scripts\lib\ota_dispatcher_provenance.ps1'
$provenanceHelperLoaded = $false
$provenanceHelperFailure = ''
try {
    if (-not (Test-Path -LiteralPath $provenanceHelperPath -PathType Leaf)) {
        throw 'dispatcher_provenance_helper_missing'
    }
    . $provenanceHelperPath
    $provenanceHelperLoaded = $true
} catch {
    $provenanceHelperFailure = $_.Exception.GetType().Name
}

function Get-DispatcherTaskEventBatch {
    param(
        [Parameter(Mandatory = $true)][datetimeoffset]$ReferenceTime
    )

    try {
        $eventLog = Get-WinEvent -ListLog 'Microsoft-Windows-TaskScheduler/Operational' -ErrorAction Stop
        if (-not [bool]$eventLog.IsEnabled) {
            return [pscustomobject]@{ available = $false; records = @() }
        }
        $filter = @{
            LogName = 'Microsoft-Windows-TaskScheduler/Operational'
            Id = @(100, 107, 110, 129, 200)
            StartTime = $ReferenceTime.LocalDateTime.AddMinutes(-5)
        }
        $events = @(Get-WinEvent -FilterHashtable $filter -MaxEvents 512 -ErrorAction SilentlyContinue)
        $records = @()
        foreach ($event in $events) {
            $xml = [xml]$event.ToXml()
            $values = @{}
            foreach ($dataNode in @($xml.Event.EventData.Data)) {
                $values[[string]$dataNode.Name] = [string]$dataNode.'#text'
            }
            $instanceId = if ($values.ContainsKey('TaskInstanceId')) {
                [string]$values.TaskInstanceId
            } elseif ($values.ContainsKey('InstanceId')) {
                [string]$values.InstanceId
            } else {
                ''
            }
            $processId = if ($values.ContainsKey('EnginePID')) {
                [int]$values.EnginePID
            } elseif ($values.ContainsKey('ProcessID')) {
                [int]$values.ProcessID
            } else {
                0
            }
            $records += [pscustomobject]@{
                event_id = [int]$event.Id
                time_created = $event.TimeCreated.ToString('o')
                task_name = [string]$values.TaskName
                task_instance_id = $instanceId
                process_id = $processId
            }
        }
        return [pscustomobject]@{ available = $true; records = @($records) }
    } catch {
        return [pscustomobject]@{ available = $false; records = @() }
    }
}

function Get-DispatcherScheduledTaskEvidence {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('Daily', 'Realtime')][string]$DispatcherMode,
        [Parameter(Mandatory = $true)][ValidateRange(1, 2147483647)][int]$SystemHotelId,
        [Parameter(Mandatory = $true)][datetimeoffset]$ReferenceTime
    )

    $taskName = if ($DispatcherMode -eq 'Realtime') {
        "SUXIOS OTA Realtime Dispatcher H$SystemHotelId"
    } else {
        "SUXIOS OTA Dispatcher H$SystemHotelId"
    }
    try {
        $task = Get-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop
        $taskInfo = $task | Get-ScheduledTaskInfo -ErrorAction Stop
        $action = @($task.Actions)[0]
        $trigger = @($task.Triggers)[0]
        $lastRunOffset = [datetimeoffset]$taskInfo.LastRunTime
        $state = [string]$task.State
        $eventBatch = Get-DispatcherTaskEventBatch -ReferenceTime $ReferenceTime
        $correlation = Resolve-SuxiosDispatcherSchedulerCorrelation `
            -TaskName $taskName `
            -EngineProcessId $PID `
            -ReferenceTime $ReferenceTime.ToString('o') `
            -TaskState $state `
            -LastRunTime $lastRunOffset.ToString('o') `
            -EventRecords $eventBatch.records `
            -EventLogAvailable ([bool]$eventBatch.available) `
            -PreflightOnly:$([bool]$PreflightOnly)
        $contract = [ordered]@{
            task_name = $taskName
            task_path = '\'
            action_execute = [string]$action.Execute
            action_arguments = [string]$action.Arguments
            working_directory = [string]$action.WorkingDirectory
            trigger_start_boundary = [string]$trigger.StartBoundary
            trigger_interval = [string]$trigger.Repetition.Interval
            trigger_duration = [string]$trigger.Repetition.Duration
            principal_logon_type = [string]$task.Principal.LogonType
            principal_run_level = [string]$task.Principal.RunLevel
            multiple_instances = [string]$task.Settings.MultipleInstances
            start_when_available = [bool]$task.Settings.StartWhenAvailable
            wake_to_run = [bool]$task.Settings.WakeToRun
            execution_time_limit = [string]$task.Settings.ExecutionTimeLimit
        }
        return [pscustomobject]@{
            hash = Get-SuxiosDispatcherTaskContractHash -TaskContract $contract
            available = $true
            correlation = $correlation
        }
    } catch {
        return [pscustomobject]@{
            hash = Get-SuxiosStableSha256 -Value ([ordered]@{
                schema_version = 1
                task_name = $taskName
                status = 'unavailable'
            })
            available = $false
            correlation = [ordered]@{
                task_name = $taskName
                task_path = '\'
                state = ''
                last_run_time = ''
                last_run_delta_seconds = $null
                task_instance_id = $null
                engine_process_id = $null
                event_ids = @()
                reason = 'task_evidence_unavailable'
                status = 'unavailable'
            }
        }
    }
}

function Get-DispatcherFinishProvenance {
    param(
        [Parameter(Mandatory = $true)][object]$StartState,
        [Parameter(Mandatory = $true)][datetimeoffset]$FinishedAt,
        [Parameter(Mandatory = $true)][int]$ChildExitCode,
        [string[]]$ChildOutputLines = @()
    )

    if ([bool]$StartState.ready -ne $true) {
        return [pscustomobject]@{
            line = 'dispatcher_execution_provenance=unavailable;phase=finish'
            code_drifted = $false
            status = 'unavailable'
        }
    }
    try {
        $finishManifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot $resolvedRoot
        $finishTaskEvidence = Get-DispatcherScheduledTaskEvidence `
            -DispatcherMode $Mode `
            -SystemHotelId $HotelId `
            -ReferenceTime ([datetimeoffset]$StartState.started_at)
        $codeStable = [string]$finishManifest.sha256 -eq [string]$StartState.code_manifest.sha256
        $taskStable = [string]$finishTaskEvidence.hash -eq [string]$StartState.task_evidence.hash
        $manifestComplete = @($finishManifest.missing_roots).Count -eq 0
        $selectedSchedulerCorrelation = Select-SuxiosDispatcherTerminalSchedulerCorrelation `
            -StartCorrelation $StartState.task_evidence.correlation `
            -FinishCorrelation $finishTaskEvidence.correlation
        $schedulerCorrelated = [string]$selectedSchedulerCorrelation.status -eq 'correlated'
        $provenanceStatus = if (-not $codeStable) {
            'drifted'
        } elseif ($manifestComplete -and $taskStable -and $schedulerCorrelated) {
            'verified'
        } else {
            'unavailable'
        }
        $machineReceiptLines = @($ChildOutputLines | Where-Object { [string]$_ -match '^SUXIOS_AUTO_FETCH_RECEIPT=' })
        $childReceiptPresent = $machineReceiptLines.Count -gt 0
        $childReceiptHash = if ($childReceiptPresent) {
            Get-SuxiosStableSha256 -Value $machineReceiptLines
        } else {
            ''
        }
        $finishReceipt = New-SuxiosDispatcherProvenanceReceipt `
            -Phase finish `
            -RunId $dispatcherRunGuid `
            -StartedAt $StartState.started_at `
            -FinishedAt $FinishedAt.ToString('o') `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $StartState.source_ids `
            -Platforms $StartState.platforms `
            -RunnerSha256 $StartState.runner_sha256 `
            -CodeManifest $finishManifest `
            -EffectiveConfigSha256 $StartState.effective_config_sha256 `
            -TaskContractSha256 $finishTaskEvidence.hash `
            -SchedulerCorrelation $selectedSchedulerCorrelation `
            -ChildReceiptPresent $childReceiptPresent `
            -ChildReceiptCount $machineReceiptLines.Count `
            -ChildReceiptSha256 $childReceiptHash `
            -ChildExitCode $ChildExitCode `
            -CodeStableDuringRun $codeStable `
            -ProvenanceStatus $provenanceStatus
        return [pscustomobject]@{
            line = 'SUXIOS_OTA_DISPATCHER_PROVENANCE=' + $finishReceipt
            code_drifted = -not $codeStable
            status = $provenanceStatus
        }
    } catch {
        return [pscustomobject]@{
            line = 'dispatcher_execution_provenance=unavailable;phase=finish;reason=' + $_.Exception.GetType().Name
            code_drifted = $false
            status = 'unavailable'
        }
    }
}

$startedAtOffset = [datetimeoffset]::Now
$startedAt = $startedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
$lines = @("[$startedAt] SUXIOS OTA dispatcher started.")
$exitCode = 1
$stdoutPath = Join-Path $logDirectory "ota_dispatcher_$runId.stdout.tmp"
$stderrPath = Join-Path $logDirectory "ota_dispatcher_$runId.stderr.tmp"
$scheduleArgument = if ($Mode -eq 'Realtime') { '--realtime-only' } else { '--daily-only' }
$dispatcherArguments = @(
    ('"{0}"' -f $thinkPath),
    'online-data:auto-fetch',
    $scheduleArgument
)
if ($Mode -eq 'Daily') {
    $dispatcherArguments += "--target-date=$dailyTargetDate"
    $dispatcherArguments += "--dispatcher-run-id=$($dispatcherRunGuid.ToString('D').ToLowerInvariant())"
    $lines += "dispatcher_target_date=$dailyTargetDate;timezone=Asia/Shanghai"
}
if ($explicitScopeComplete) {
    $dispatcherArguments += @(
        "--hotel-id=$HotelId",
        "--source-ids=$SourceIds",
        "--platforms=$Platforms"
    )
    $lines += "dispatcher_scope=hotel:$HotelId;platforms:$Platforms;source_count:$(@($SourceIds.Split(',')).Count)"
}
if ($PreflightOnly) {
    $lines += 'dispatcher_run_mode=preflight_only;ota_collection_started=false'
}
$lines += 'dispatcher_terminal_status=started_without_terminal_receipt'
# Persist the safe scope/start receipt before the child process begins. If the
# Windows execution limit terminates this runner, the missing terminal status
# remains explicit instead of looking like a task that never started.
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

$lines += "dispatcher_run_id=$($dispatcherRunGuid.ToString('D').ToLowerInvariant());schema_version=1"
$provenanceState = [pscustomobject]@{
    ready = $false
    started_at = $startedAtOffset.ToString('o')
    source_ids = @()
    platforms = @()
    runner_sha256 = ''
    code_manifest = $null
    effective_config_sha256 = ''
    task_evidence = $null
}
if ($provenanceHelperLoaded -and $explicitScopeComplete) {
    try {
        $sourceIdValues = [int[]]@($SourceIds.Split(',') | ForEach-Object { [int]$_ })
        $platformValues = [string[]]@($Platforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() })
        $startManifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot $resolvedRoot
        $runnerSha256 = (Get-FileHash -LiteralPath $PSCommandPath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
        $effectiveConfigSha256 = Get-SuxiosDispatcherEffectiveConfigHash `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $sourceIdValues `
            -Platforms $platformValues `
            -PhpPath $resolvedPhp `
            -ThinkPath $thinkPath
        $startTaskEvidence = Get-DispatcherScheduledTaskEvidence `
            -DispatcherMode $Mode `
            -SystemHotelId $HotelId `
            -ReferenceTime $startedAtOffset
        $startReceipt = New-SuxiosDispatcherProvenanceReceipt `
            -Phase start `
            -RunId $dispatcherRunGuid `
            -StartedAt $startedAtOffset.ToString('o') `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $sourceIdValues `
            -Platforms $platformValues `
            -RunnerSha256 $runnerSha256 `
            -CodeManifest $startManifest `
            -EffectiveConfigSha256 $effectiveConfigSha256 `
            -TaskContractSha256 $startTaskEvidence.hash `
            -SchedulerCorrelation $startTaskEvidence.correlation `
            -ProvenanceStatus started
        $provenanceState = [pscustomobject]@{
            ready = $true
            started_at = $startedAtOffset.ToString('o')
            source_ids = $sourceIdValues
            platforms = $platformValues
            runner_sha256 = $runnerSha256
            code_manifest = $startManifest
            effective_config_sha256 = $effectiveConfigSha256
            task_evidence = $startTaskEvidence
        }
        $lines += 'SUXIOS_OTA_DISPATCHER_PROVENANCE=' + $startReceipt
    } catch {
        $lines += 'dispatcher_execution_provenance=unavailable;phase=start;reason=' + $_.Exception.GetType().Name
    }
} else {
    $provenanceReason = if (-not $provenanceHelperLoaded) {
        'helper_' + ($provenanceHelperFailure -replace '[^A-Za-z0-9_]', '')
    } else {
        'fixed_scope_required'
    }
    $lines += "dispatcher_execution_provenance=unavailable;phase=start;reason=$provenanceReason"
}
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

$databaseCheckArguments = @(
    ('"{0}"' -f $thinkPath),
    'db:check'
)
$initialDatabaseCheck = Invoke-SafeDispatcherProcess `
    -FilePath $resolvedPhp `
    -ArgumentList $databaseCheckArguments `
    -WorkingDirectory $resolvedRoot `
    -Label 'db_check_initial' `
    -TimeoutSeconds 30
$verifiedDatabaseCheck = $initialDatabaseCheck
$databasePreflightBlocked = $false
$databasePreflightReason = ''
$databasePreflightExitCode = 1

if ($initialDatabaseCheck.exit_code -eq 0) {
    $lines += 'dispatcher_database_preflight=ready;recovery_attempted=false;exit_code=0'
} elseif ($initialDatabaseCheck.exit_code -eq 2) {
    # Exit 2 is the schema-governance signal. Starting MySQL cannot repair it,
    # and automatic migration is intentionally outside this dispatcher.
    $databasePreflightBlocked = $true
    $databasePreflightReason = 'database_schema_upgrade_required'
    $databasePreflightExitCode = 2
    $lines += 'dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required;exit_code=2;recovery_attempted=false'
} elseif ($initialDatabaseCheck.exit_code -ne 0) {
    $databaseRecoveryScriptPath = Join-Path $resolvedRoot 'scripts\start_local_stack.ps1'
    if (Test-Path -LiteralPath $databaseRecoveryScriptPath -PathType Leaf) {
        $databaseRecoveryResult = Invoke-SafeDispatcherProcess `
            -FilePath (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') `
            -ArgumentList @(
                '-NoProfile',
                '-NonInteractive',
                '-WindowStyle',
                'Hidden',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                ('"{0}"' -f $databaseRecoveryScriptPath),
                '-DatabaseOnly',
                '-NoBrowser'
            ) `
            -WorkingDirectory $resolvedRoot `
            -Label 'database_recovery' `
            -TimeoutSeconds 60
    } else {
        $databaseRecoveryResult = [pscustomobject]@{
            exit_code = 127
            timed_out = $false
            exception_type = 'startup_script_missing'
        }
    }
    $lines += "dispatcher_database_recovery=attempted;exit_code=$($databaseRecoveryResult.exit_code);timed_out=$([bool]$databaseRecoveryResult.timed_out)"

    # Always re-check with ThinkPHP's authoritative schema probe. A startup
    # helper failure can still leave MySQL recovered, while a helper success
    # must not conceal a connection or schema mismatch.
    $verifiedDatabaseCheck = Invoke-SafeDispatcherProcess `
        -FilePath $resolvedPhp `
        -ArgumentList $databaseCheckArguments `
        -WorkingDirectory $resolvedRoot `
        -Label 'db_check_verified' `
        -TimeoutSeconds 30
    if ($verifiedDatabaseCheck.exit_code -eq 0) {
        $lines += 'dispatcher_database_preflight=ready;recovery_attempted=true;verified_exit_code=0'
    } elseif ($verifiedDatabaseCheck.exit_code -eq 2) {
        $databasePreflightBlocked = $true
        $databasePreflightReason = 'database_schema_upgrade_required'
        $databasePreflightExitCode = 2
        $lines += 'dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required;exit_code=2;recovery_attempted=true'
    } else {
        $databasePreflightBlocked = $true
        $databasePreflightReason = 'database_runtime_unavailable'
        $databasePreflightExitCode = 1
        $lines += "dispatcher_database_preflight=blocked;reason=database_runtime_unavailable;initial_exit_code=$($initialDatabaseCheck.exit_code);verified_exit_code=$($verifiedDatabaseCheck.exit_code)"
    }
}

if ($PreflightOnly -or $databasePreflightBlocked) {
    if ($databasePreflightBlocked) {
        $exitCode = $databasePreflightExitCode
        $lines += "dispatcher_preflight_result=blocked;reason=$databasePreflightReason;ota_collection_started=false"
    } else {
        $exitCode = 0
        $lines += 'dispatcher_preflight_result=ready;ota_collection_started=false'
    }
    $preflightExitCode = $exitCode
    $finishedAtOffset = [datetimeoffset]::Now
    $finishProvenance = Get-DispatcherFinishProvenance `
        -StartState $provenanceState `
        -FinishedAt $finishedAtOffset `
        -ChildExitCode $preflightExitCode `
        -ChildOutputLines @()
    $lines += $finishProvenance.line
    if ([bool]$finishProvenance.code_drifted) {
        $exitCode = 1
        $lines += "dispatcher_execution_provenance=drifted;preflight_exit_code=$preflightExitCode;final_exit_code=1"
    }
    $finishedAt = $finishedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
    $lines += "dispatcher_terminal_status=finished;exit_code=$exitCode"
    $lines += "[$finishedAt] SUXIOS OTA dispatcher preflight finished. exit_code=$exitCode"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    Write-Output "dispatcher_log=$logPath"
    Write-Output "dispatcher_exit_code=$exitCode"
    exit $exitCode
}

$lines += 'dispatcher_preflight_result=ready;ota_collection_started=pending'
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
$rawOutput = @()
try {
    $process = Start-Process `
        -FilePath $resolvedPhp `
        -ArgumentList $dispatcherArguments `
        -WorkingDirectory $resolvedRoot `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath `
        -PassThru `
        -NoNewWindow
    # Materialize the native handle before waiting. Without this, Windows
    # PowerShell 5.1 can expose ExitCode=$null for a short redirected process;
    # casting that value to int would create a false success (0).
    $null = $process.Handle
    $process.WaitForExit()
    $process.WaitForExit()
    $capturedDispatcherExitCode = $process.ExitCode
    if ($null -eq $capturedDispatcherExitCode) {
        $exitCode = 125
        $lines += 'dispatcher_child_exit_code=unavailable;fail_closed_exit_code=125'
    } else {
        $exitCode = [int]$capturedDispatcherExitCode
    }
    foreach ($capturePath in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $capturePath -PathType Leaf) {
            $rawOutput += [System.IO.File]::ReadAllLines($capturePath, $utf8)
        }
    }
    foreach ($entry in $rawOutput) {
        $lines += ConvertTo-SafeDispatcherLine -Value $entry
    }
} catch {
    $lines += ('dispatcher_exception=' + $_.Exception.GetType().FullName)
    $exitCode = 1
} finally {
    foreach ($capturePath in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $capturePath -PathType Leaf) {
            Remove-Item -LiteralPath $capturePath -Force -ErrorAction SilentlyContinue
        }
    }
}

$childExitCode = $exitCode
$finishedAtOffset = [datetimeoffset]::Now
$finishProvenance = Get-DispatcherFinishProvenance `
    -StartState $provenanceState `
    -FinishedAt $finishedAtOffset `
    -ChildExitCode $childExitCode `
    -ChildOutputLines $rawOutput
$lines += $finishProvenance.line
if ([bool]$finishProvenance.code_drifted) {
    $exitCode = 1
    $lines += "dispatcher_execution_provenance=drifted;child_exit_code=$childExitCode;final_exit_code=1"
}
$finishedAt = $finishedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
$lines += "dispatcher_terminal_status=finished;exit_code=$exitCode"
$lines += "[$finishedAt] SUXIOS OTA dispatcher finished. exit_code=$exitCode"
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

# A Daily-only, non-blocking sidecar now joins the natural Task Scheduler proof
# with the child receipt's exact save/readback/P0/four-check facts. It never
# changes the collection exit code and never starts another OTA request.
if ($Mode -eq 'Daily' -and $explicitScopeComplete) {
    $acceptanceScriptPath = Join-Path $resolvedRoot 'scripts\verify_canonical_ota_daily_natural_acceptance.php'
    $acceptanceLine = ''
    $acceptanceExitCode = 125
    if (Test-Path -LiteralPath $acceptanceScriptPath -PathType Leaf) {
        $acceptanceResult = Invoke-SafeDispatcherProcess `
            -FilePath $resolvedPhp `
            -ArgumentList @(
                ('"{0}"' -f $acceptanceScriptPath),
                "--hotel-id=$HotelId",
                "--target-date=$dailyTargetDate",
                "--source-ids=$SourceIds",
                "--platforms=$Platforms",
                ('--dispatcher-log="{0}"' -f $logPath)
            ) `
            -WorkingDirectory $resolvedRoot `
            -Label 'daily_acceptance' `
            -TimeoutSeconds 60
        $acceptanceExitCode = [int]$acceptanceResult.exit_code
        $acceptanceLines = @(
            $acceptanceResult.stdout_lines |
                Where-Object { [string]$_ -match '^SUXIOS_OTA_DAILY_ACCEPTANCE=\{.*\}$' }
        )
        if ($acceptanceLines.Count -eq 1) {
            try {
                $acceptanceJson = ([string]$acceptanceLines[0]).Substring(
                    'SUXIOS_OTA_DAILY_ACCEPTANCE='.Length
                ) | ConvertFrom-Json -ErrorAction Stop
                if ($acceptanceJson.sensitive_values_exposed -eq $false -and
                    [string]$acceptanceJson.status -in @('verified', 'blocked')) {
                    $acceptanceLine = [string]$acceptanceLines[0]
                }
            } catch {
                $acceptanceLine = ''
            }
        }
    }
    if ($acceptanceLine -eq '') {
        $fallbackAcceptance = [ordered]@{
            schema_version = 'suxios_ota_daily_natural_acceptance.v1'
            status = 'blocked'
            reason_codes = @('daily_acceptance_sidecar_unavailable')
            hotel_id = $HotelId
            target_date = $dailyTargetDate
            collection_triggered_by_acceptance = $false
            business_data_written_by_acceptance = $false
            external_action_triggered = $false
            business_outcome_claimed = $false
            causality_claimed = $false
            sensitive_values_exposed = $false
        }
        $acceptanceLine = 'SUXIOS_OTA_DAILY_ACCEPTANCE=' + (
            $fallbackAcceptance | ConvertTo-Json -Compress -Depth 8
        )
    }
    $lines += $acceptanceLine
    $lines += "dispatcher_daily_acceptance_sidecar=recorded;sidecar_exit_code=$acceptanceExitCode;non_blocking=true"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    $acceptanceReadbackLines = @(
        [System.IO.File]::ReadAllLines($logPath, $utf8) |
            Where-Object { [string]$_ -match '^SUXIOS_OTA_DAILY_ACCEPTANCE=' }
    )
    $acceptanceReadbackVerified = $acceptanceReadbackLines.Count -eq 1 -and
        [string]$acceptanceReadbackLines[0] -ceq $acceptanceLine
    $lines += "dispatcher_daily_acceptance_readback_verified=$($acceptanceReadbackVerified.ToString().ToLowerInvariant());receipt_count=$($acceptanceReadbackLines.Count)"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
}

Write-Output "dispatcher_log=$logPath"
Write-Output "dispatcher_exit_code=$exitCode"
exit $exitCode
