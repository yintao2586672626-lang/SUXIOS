[CmdletBinding()]
param(
    [ValidateSet('inspect', 'enable')]
    [string]$Mode = 'inspect',

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidatePattern('^(?:|[a-fA-F0-9]{64})$')]
    [string]$ExpectedContractDigest = '',

    [string]$ProjectRoot = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$prefix = 'SUXIOS_OTA_WINDOWS_SCHEDULER='
$schemaVersion = 'suxios_windows_ota_dispatcher.v1'
$taskName = 'SUXIOS OTA Dispatcher H80'
$taskPath = '\'
$expectedHotelId = 80
$expectedSourceIds = @(25, 68)
$expectedPlatforms = @('ctrip', 'meituan')

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$ProjectRoot = [System.IO.Path]::GetFullPath($ProjectRoot)
$expectedRunnerPath = [System.IO.Path]::GetFullPath(
    (Join-Path $ProjectRoot 'scripts\run_ota_dispatcher.ps1')
)
$expectedPhpPath = [System.IO.Path]::GetFullPath('C:\xampp\php\php.exe')
$expectedWorkingDirectory = $ProjectRoot
$expectedPowerShellPath = [System.IO.Path]::GetFullPath(
    (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe')
)
$expectedArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass' `
    + ' -File "' + $expectedRunnerPath + '"' `
    + ' -ProjectRoot "' + $ProjectRoot + '"' `
    + ' -PhpPath "' + $expectedPhpPath + '"' `
    + ' -Mode Daily -HotelId 80 -SourceIds "25,68" -Platforms "ctrip,meituan"'

function Get-Sha256Hex {
    param([Parameter(Mandatory = $true)][string]$Value)

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
        return ([System.BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha.Dispose()
    }
}

function Convert-DateValue {
    param([object]$Value)

    if ($null -eq $Value) {
        return $null
    }
    try {
        $date = [datetime]$Value
        if ($date.Year -lt 2000) {
            return $null
        }
        return $date.ToString('o')
    } catch {
        return $null
    }
}

function Write-SchedulerReceipt {
    param(
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$Receipt,
        [int]$ExitCode = 0
    )

    Write-Output ($prefix + ($Receipt | ConvertTo-Json -Compress -Depth 12))
    exit $ExitCode
}

function Read-SchedulerState {
    $task = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
    if ($null -eq $task) {
        return [ordered]@{
            task = $null
            info = $null
            exists = $false
            enabled = $false
            scope_verified = $false
            action_verified = $false
            trigger_verified = $false
            principal_verified = $false
            settings_verified = $false
            catch_up_disabled = $false
            task_state_active = $false
            contract_digest = $null
            reason_code = 'scheduler_task_missing'
        }
    }

    $actions = @($task.Actions)
    $triggers = @($task.Triggers)
    $action = if ($actions.Count -eq 1) { $actions[0] } else { $null }
    $trigger = if ($triggers.Count -eq 1) { $triggers[0] } else { $null }
    $arguments = if ($null -ne $action) { [string]$action.Arguments } else { '' }
    $executePath = if ($null -ne $action) { [string]$action.Execute } else { '' }
    $workingDirectory = if ($null -ne $action) { [string]$action.WorkingDirectory } else { '' }

    $executeVerified = $false
    try {
        $executeVerified = [System.IO.Path]::GetFullPath($executePath).Equals(
            $expectedPowerShellPath,
            [System.StringComparison]::OrdinalIgnoreCase
        )
    } catch {
        $executeVerified = $false
    }
    $argumentsVerified = $arguments.Equals(
        $expectedArguments,
        [System.StringComparison]::OrdinalIgnoreCase
    )
    $workingDirectoryVerified = $false
    if ($null -ne $action) {
        try {
            $workingDirectoryVerified = [System.IO.Path]::GetFullPath(
                $workingDirectory
            ).Equals($expectedWorkingDirectory, [System.StringComparison]::OrdinalIgnoreCase)
        } catch {
            $workingDirectoryVerified = $false
        }
    }
    $actionVerified = $actions.Count -eq 1 -and $executeVerified `
        -and $argumentsVerified -and $workingDirectoryVerified

    $startBoundary = [datetimeoffset]::MinValue
    $startBoundaryValid = $null -ne $trigger -and [datetimeoffset]::TryParse(
        [string]$trigger.StartBoundary,
        [ref]$startBoundary
    )
    $triggerVerified = $triggers.Count -eq 1 `
        -and [string]$trigger.CimClass.CimClassName -eq 'MSFT_TaskDailyTrigger' `
        -and [bool]$trigger.Enabled `
        -and $startBoundaryValid `
        -and $startBoundary.ToString('HH:mm:ss') -eq '08:30:00' `
        -and [int]$trigger.DaysInterval -eq 1 `
        -and [string]$trigger.EndBoundary -eq '' `
        -and [string]$trigger.RandomDelay -eq '' `
        -and [string]$trigger.Repetition.Interval -eq 'PT14M' `
        -and [string]$trigger.Repetition.Duration -eq 'PT1H25M' `
        -and -not [bool]$trigger.Repetition.StopAtDurationEnd

    $principalVerified = [string]$task.Principal.UserId -eq 'Administrator' `
        -and [string]$task.Principal.LogonType -eq 'Interactive' `
        -and [string]$task.Principal.RunLevel -eq 'Limited'
    $settingsBaseVerified = [string]$task.Settings.MultipleInstances -eq 'IgnoreNew' `
        -and [string]$task.Settings.ExecutionTimeLimit -eq 'PT40M' `
        -and [bool]$task.Settings.AllowDemandStart `
        -and [bool]$task.Settings.WakeToRun `
        -and [bool]$task.Settings.Hidden `
        -and -not [bool]$task.Settings.DisallowStartIfOnBatteries `
        -and -not [bool]$task.Settings.StopIfGoingOnBatteries `
        -and -not [bool]$task.Settings.RunOnlyIfIdle `
        -and -not [bool]$task.Settings.RunOnlyIfNetworkAvailable `
        -and [int]$task.Settings.RestartCount -eq 0 `
        -and [string]$task.Settings.RestartInterval -eq '' `
        -and [bool]$task.Settings.AllowHardTerminate `
        -and [string]$task.Settings.Compatibility -eq 'Win7' `
        -and [string]$task.Settings.DeleteExpiredTaskAfter -eq '' `
        -and [int]$task.Settings.Priority -eq 7 `
        -and -not [bool]$task.Settings.Volatile
    $catchUpDisabled = -not [bool]$task.Settings.StartWhenAvailable
    $settingsVerified = $settingsBaseVerified
    $scopeVerified = $actionVerified -and $triggerVerified -and $principalVerified -and $settingsVerified
    $enabled = [bool]$task.Settings.Enabled -and [string]$task.State -ne 'Disabled'
    $taskState = [string]$task.State
    $taskStateActive = $taskState -in @('Running', 'Queued')
    $info = Get-ScheduledTaskInfo -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop

    $contract = [ordered]@{
        schema_version = 1
        task_name = $taskName
        task_path = $taskPath
        hotel_id = $expectedHotelId
        source_ids = $expectedSourceIds
        platforms = $expectedPlatforms
        mode = 'Daily'
        action = [ordered]@{
            execute = $executePath.ToLowerInvariant()
            arguments = $arguments
            working_directory = $workingDirectory
            verified = $actionVerified
        }
        trigger = [ordered]@{
            count = $triggers.Count
            type = if ($null -ne $trigger) { [string]$trigger.CimClass.CimClassName } else { '' }
            start_boundary = if ($startBoundaryValid) { $startBoundary.ToString('o') } else { '' }
            end_boundary = if ($null -ne $trigger) { [string]$trigger.EndBoundary } else { '' }
            random_delay = if ($null -ne $trigger) { [string]$trigger.RandomDelay } else { '' }
            days_interval = if ($null -ne $trigger) { [int]$trigger.DaysInterval } else { 0 }
            interval = if ($null -ne $trigger) { [string]$trigger.Repetition.Interval } else { '' }
            duration = if ($null -ne $trigger) { [string]$trigger.Repetition.Duration } else { '' }
            stop_at_duration_end = if ($null -ne $trigger) { [bool]$trigger.Repetition.StopAtDurationEnd } else { $false }
            verified = $triggerVerified
        }
        principal = [ordered]@{
            user_id = [string]$task.Principal.UserId
            logon_type = [string]$task.Principal.LogonType
            run_level = [string]$task.Principal.RunLevel
            verified = $principalVerified
        }
        settings = [ordered]@{
            multiple_instances = [string]$task.Settings.MultipleInstances
            execution_time_limit = [string]$task.Settings.ExecutionTimeLimit
            allow_demand_start = [bool]$task.Settings.AllowDemandStart
            start_when_available = [bool]$task.Settings.StartWhenAvailable
            wake_to_run = [bool]$task.Settings.WakeToRun
            hidden = [bool]$task.Settings.Hidden
            disallow_start_if_on_batteries = [bool]$task.Settings.DisallowStartIfOnBatteries
            stop_if_going_on_batteries = [bool]$task.Settings.StopIfGoingOnBatteries
            run_only_if_idle = [bool]$task.Settings.RunOnlyIfIdle
            run_only_if_network_available = [bool]$task.Settings.RunOnlyIfNetworkAvailable
            restart_count = [int]$task.Settings.RestartCount
            restart_interval = [string]$task.Settings.RestartInterval
            allow_hard_terminate = [bool]$task.Settings.AllowHardTerminate
            compatibility = [string]$task.Settings.Compatibility
            delete_expired_task_after = [string]$task.Settings.DeleteExpiredTaskAfter
            priority = [int]$task.Settings.Priority
            volatile = [bool]$task.Settings.Volatile
            verified = $settingsVerified
        }
        task_state = $taskState
        enabled = $enabled
    }
    $contractJson = $contract | ConvertTo-Json -Compress -Depth 10
    $contractDigest = Get-Sha256Hex -Value $contractJson

    return [ordered]@{
        task = $task
        info = $info
        exists = $true
        enabled = $enabled
        scope_verified = $scopeVerified
        action_verified = $actionVerified
        trigger_verified = $triggerVerified
        principal_verified = $principalVerified
        settings_verified = $settingsVerified
        catch_up_disabled = $catchUpDisabled
        task_state_active = $taskStateActive
        contract_digest = $contractDigest
        reason_code = if (-not $scopeVerified) {
            'scheduler_scope_mismatch'
        } elseif ($taskStateActive) {
            'scheduler_task_active'
        } elseif ($enabled -and -not $catchUpDisabled) {
            'scheduler_catch_up_enabled'
        } elseif (-not $enabled) {
            if ($catchUpDisabled) { 'scheduler_disabled' } else { 'scheduler_disabled_catch_up_enabled' }
        } else {
            'scheduler_ready'
        }
    }
}

function New-PublicReceipt {
    param(
        [Parameter(Mandatory = $true)][System.Collections.IDictionary]$State,
        [bool]$EnableActionPerformed = $false,
        [bool]$SettingsActionPerformed = $false,
        [bool]$LastRunUnchanged = $true,
        [bool]$TaskStarted = $false,
        [bool]$StartsTaskImmediately = $false
    )

    $task = $State.task
    $info = $State.info
    $stateText = if ($null -ne $task) { [string]$task.State } else { 'Missing' }
    $nextRun = if ($null -ne $info) { Convert-DateValue $info.NextRunTime } else { $null }
    $lastRun = if ($null -ne $info) { Convert-DateValue $info.LastRunTime } else { $null }
    $lastResult = if ($null -ne $info) { [int64]$info.LastTaskResult } else { $null }

    return [ordered]@{
        schema_version = $schemaVersion
        status = if ($State.scope_verified -and $State.enabled -and $State.catch_up_disabled -and -not $State.task_state_active) { 'ready' } else { 'blocked' }
        reason_code = [string]$State.reason_code
        local_only = $true
        production_ready = $false
        hotel_id = $expectedHotelId
        task_name = $taskName
        task_path = $taskPath
        task_exists = [bool]$State.exists
        task_state = $stateText
        enabled = [bool]$State.enabled
        scope = [ordered]@{
            hotel_id = $expectedHotelId
            source_ids = $expectedSourceIds
            platforms = $expectedPlatforms
            mode = 'Daily'
        }
        action_verified = [bool]$State.action_verified
        trigger_verified = [bool]$State.trigger_verified
        principal_verified = [bool]$State.principal_verified
        settings_verified = [bool]$State.settings_verified
        scope_verified = [bool]$State.scope_verified
        catch_up_disabled = [bool]$State.catch_up_disabled
        safe_enable_transition_required = [bool](-not $State.catch_up_disabled)
        task_state_active = [bool]$State.task_state_active
        trigger = [ordered]@{
            count = if ($null -ne $task) { @($task.Triggers).Count } else { 0 }
            start_boundary = if ($null -ne $task -and @($task.Triggers).Count -eq 1) { [string]$task.Triggers[0].StartBoundary } else { $null }
            retry_interval = if ($null -ne $task -and @($task.Triggers).Count -eq 1) { [string]$task.Triggers[0].Repetition.Interval } else { $null }
            retry_duration = if ($null -ne $task -and @($task.Triggers).Count -eq 1) { [string]$task.Triggers[0].Repetition.Duration } else { $null }
        }
        last_run_time = $lastRun
        last_task_result = $lastResult
        next_run_time = $nextRun
        contract_digest = $State.contract_digest
        can_enable = [bool]($State.exists -and $State.scope_verified -and -not $State.enabled -and -not $State.task_state_active -and $stateText -eq 'Disabled')
        control_state_verified = $true
        enable_action_performed = $EnableActionPerformed
        settings_action_performed = $SettingsActionPerformed
        last_run_unchanged = $LastRunUnchanged
        task_started = $TaskStarted
        starts_task_immediately = $StartsTaskImmediately
        sensitive_values_exposed = $false
    }
}

if ($HotelId -ne $expectedHotelId) {
    Write-SchedulerReceipt -Receipt ([ordered]@{
        schema_version = $schemaVersion
        status = 'blocked'
        reason_code = 'scheduler_hotel_scope_unsupported'
        local_only = $true
        production_ready = $false
        hotel_id = $HotelId
        task_name = $taskName
        task_exists = $false
        enabled = $false
        scope_verified = $false
        can_enable = $false
        control_state_verified = $false
        enable_action_performed = $false
        settings_action_performed = $false
        task_started = $false
        starts_task_immediately = $false
        sensitive_values_exposed = $false
    }) -ExitCode 2
}

$enableActionPerformed = $false
$settingsActionPerformed = $false
try {
    $before = Read-SchedulerState
    if ($Mode -eq 'inspect') {
        Write-SchedulerReceipt -Receipt (New-PublicReceipt -State $before)
    }

    if (-not $before.exists -or -not $before.scope_verified -or $before.task_state_active) {
        Write-SchedulerReceipt -Receipt (New-PublicReceipt -State $before) -ExitCode 2
    }
    if ($ExpectedContractDigest -eq '' -or $ExpectedContractDigest -ne $before.contract_digest) {
        $stale = New-PublicReceipt -State $before
        $stale.reason_code = 'scheduler_status_stale'
        $stale.status = 'blocked'
        $stale.can_enable = $false
        Write-SchedulerReceipt -Receipt $stale -ExitCode 2
    }
    if ($before.enabled) {
        if (-not $before.catch_up_disabled) {
            Write-SchedulerReceipt -Receipt (New-PublicReceipt -State $before) -ExitCode 2
        }
        $alreadyEnabled = New-PublicReceipt -State $before
        $alreadyEnabled.reason_code = 'scheduler_already_enabled_waiting_natural_run'
        Write-SchedulerReceipt -Receipt $alreadyEnabled
    }

    $nextRun = $before.info.NextRunTime
    if ($null -ne $nextRun -and ([datetime]$nextRun) -le (Get-Date).AddMinutes(5)) {
        $tooClose = New-PublicReceipt -State $before
        $tooClose.reason_code = 'scheduler_enable_window_too_close'
        $tooClose.status = 'blocked'
        $tooClose.can_enable = $false
        Write-SchedulerReceipt -Receipt $tooClose -ExitCode 2
    }

    $lastRunBefore = Convert-DateValue $before.info.LastRunTime
    if (-not $before.catch_up_disabled) {
        $safeSettings = $before.task.Settings
        $safeSettings.StartWhenAvailable = $false
        Set-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Settings $safeSettings -ErrorAction Stop | Out-Null
        $settingsActionPerformed = $true
        $afterSettings = Read-SchedulerState
        $lastRunAfterSettings = Convert-DateValue $afterSettings.info.LastRunTime
        $settingsReadbackVerified = $afterSettings.exists `
            -and $afterSettings.scope_verified `
            -and $afterSettings.catch_up_disabled `
            -and -not $afterSettings.enabled `
            -and -not $afterSettings.task_state_active `
            -and [string]$afterSettings.task.State -eq 'Disabled' `
            -and $lastRunBefore -eq $lastRunAfterSettings
        if (-not $settingsReadbackVerified) {
            $unexpectedRun = $afterSettings.task_state_active -or $lastRunBefore -ne $lastRunAfterSettings
            $settingsFailed = New-PublicReceipt -State $afterSettings -SettingsActionPerformed $true -LastRunUnchanged ($lastRunBefore -eq $lastRunAfterSettings) -TaskStarted $unexpectedRun -StartsTaskImmediately $unexpectedRun
            $settingsFailed.status = 'blocked'
            $settingsFailed.reason_code = 'scheduler_safe_settings_readback_failed'
            $settingsFailed.can_enable = $false
            Write-SchedulerReceipt -Receipt $settingsFailed -ExitCode 2
        }
        $before = $afterSettings
    }

    Enable-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop | Out-Null
    $enableActionPerformed = $true
    Start-Sleep -Milliseconds 1000
    $after = Read-SchedulerState
    $lastRunAfter = Convert-DateValue $after.info.LastRunTime
    $lastRunUnchanged = $lastRunBefore -eq $lastRunAfter
    if (-not $after.scope_verified -or -not $after.enabled -or -not $after.catch_up_disabled -or $after.task_state_active -or -not $lastRunUnchanged) {
        $unexpectedRun = $after.task_state_active -or -not $lastRunUnchanged
        $failed = New-PublicReceipt -State $after -EnableActionPerformed $enableActionPerformed -SettingsActionPerformed $settingsActionPerformed -LastRunUnchanged $lastRunUnchanged -TaskStarted $unexpectedRun -StartsTaskImmediately $unexpectedRun
        $failed.status = 'blocked'
        $failed.reason_code = if ($after.task_state_active -or -not $lastRunUnchanged) { 'scheduler_enable_triggered_unexpected_run' } else { 'scheduler_enable_readback_failed' }
        $failed.can_enable = $false
        Write-SchedulerReceipt -Receipt $failed -ExitCode 2
    }

    $receipt = New-PublicReceipt -State $after -EnableActionPerformed $enableActionPerformed -SettingsActionPerformed $settingsActionPerformed -LastRunUnchanged $true
    $receipt.reason_code = 'scheduler_enabled_waiting_natural_run'
    $receipt.can_enable = $false
    Write-SchedulerReceipt -Receipt $receipt
} catch {
    Write-SchedulerReceipt -Receipt ([ordered]@{
        schema_version = $schemaVersion
        status = 'blocked'
        reason_code = 'scheduler_control_unavailable'
        local_only = $true
        production_ready = $false
        hotel_id = $expectedHotelId
        task_name = $taskName
        task_exists = $null
        enabled = $null
        scope_verified = $null
        can_enable = $false
        control_state_verified = $false
        enable_action_performed = $enableActionPerformed
        settings_action_performed = $settingsActionPerformed
        last_run_unchanged = $null
        task_state_active = $null
        catch_up_disabled = $null
        safe_enable_transition_required = $null
        task_started = $null
        starts_task_immediately = $null
        sensitive_values_exposed = $false
    }) -ExitCode 2
}
