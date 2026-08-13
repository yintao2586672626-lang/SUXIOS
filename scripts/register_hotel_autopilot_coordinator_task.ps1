[CmdletBinding()]
param(
    [switch]$Enable,

    [switch]$ReplaceExisting,

    [switch]$StartNow,

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$receiptPrefix = 'SUXIOS_HOTEL_AUTOPILOT_COORDINATOR_TASK='
$taskName = 'SUXIOS Hotel Autopilot Coordinator'
$taskPath = '\'
$receipt = [ordered]@{
    schema_version = 'suxios_hotel_autopilot_coordinator_task.v1'
    status = 'blocked'
    reason_code = 'registration_not_started'
    task_name = $taskName
    task_exists = $false
    enabled = $false
    task_started = $false
    interval_minutes = 5
    action_verified = $false
    trigger_verified = $false
    principal_verified = $false
    enabled_verified = $false
    readback_verified = $false
    sensitive_values_exposed = $false
}

function Write-CoordinatorTaskReceipt {
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Value)

    Write-Output ($receiptPrefix + ($Value | ConvertTo-Json -Depth 6 -Compress))
}

function Test-OrdinalPathEquals {
    param(
        [Parameter(Mandatory = $true)][string]$Left,
        [Parameter(Mandatory = $true)][string]$Right
    )

    return [string]::Equals(
        $Left.TrimEnd('\', '/'),
        $Right.TrimEnd('\', '/'),
        [System.StringComparison]::OrdinalIgnoreCase
    )
}

function Test-LocalPrincipalEquals {
    param(
        [Parameter(Mandatory = $true)][string]$Actual,
        [Parameter(Mandatory = $true)][string]$Expected
    )

    if ([string]::Equals($Actual, $Expected, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }
    $parts = @($Expected.Split('\'))
    return $parts.Count -eq 2 `
        -and [string]::Equals($parts[0], [string]$env:COMPUTERNAME, [System.StringComparison]::OrdinalIgnoreCase) `
        -and [string]::Equals($Actual, $parts[1], [System.StringComparison]::OrdinalIgnoreCase)
}

function New-FiveMinuteRepetitionPattern {
    return New-CimInstance `
        -Namespace Root/Microsoft/Windows/TaskScheduler `
        -ClassName MSFT_TaskRepetitionPattern `
        -ClientOnly `
        -Property @{
            Interval = 'PT5M'
            Duration = 'P1D'
            StopAtDurationEnd = $false
        }
}

function Get-ExactCoordinatorTaskReadback {
    param(
        [Parameter(Mandatory = $true)][string]$ExpectedPowerShell,
        [Parameter(Mandatory = $true)][string]$ExpectedArguments,
        [Parameter(Mandatory = $true)][string]$ExpectedWorkingDirectory,
        [Parameter(Mandatory = $true)][string]$ExpectedUser
    )

    $task = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
    if ($null -eq $task) {
        return [pscustomobject]@{
            task = $null
            action_verified = $false
            trigger_verified = $false
            principal_verified = $false
            enabled_verified = $false
            automatic_repair_allowed = $false
            readback_verified = $false
        }
    }

    $actions = @($task.Actions)
    $actionVerified = $actions.Count -eq 1 `
        -and (Test-OrdinalPathEquals -Left ([string]$actions[0].Execute) -Right $ExpectedPowerShell) `
        -and [string]::Equals([string]$actions[0].Arguments, $ExpectedArguments, [System.StringComparison]::Ordinal) `
        -and (Test-OrdinalPathEquals -Left ([string]$actions[0].WorkingDirectory) -Right $ExpectedWorkingDirectory)

    $triggers = @($task.Triggers)
    $triggerVerified = $triggers.Count -eq 1 `
        -and [string]$triggers[0].Repetition.Interval -eq 'PT5M' `
        -and [string]$triggers[0].Repetition.Duration -eq 'P1D'

    $principal = $task.Principal
    $principalVerified = $null -ne $principal `
        -and (Test-LocalPrincipalEquals -Actual ([string]$principal.UserId) -Expected $ExpectedUser) `
        -and [string]$principal.LogonType -eq 'Interactive' `
        -and [string]$principal.RunLevel -eq 'Limited'

    $settings = $task.Settings
    $enabledVerified = $null -ne $settings -and [bool]$settings.Enabled
    $structureVerified = $actionVerified -and $triggerVerified -and $principalVerified

    return [pscustomobject]@{
        task = $task
        action_verified = [bool]$actionVerified
        trigger_verified = [bool]$triggerVerified
        principal_verified = [bool]$principalVerified
        enabled_verified = [bool]$enabledVerified
        automatic_repair_allowed = [bool]($structureVerified -and -not $enabledVerified)
        readback_verified = [bool]($structureVerified -and $enabledVerified)
    }
}

$exitCode = 1
try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $ProjectRoot = Split-Path -Parent $PSScriptRoot
    }
    $resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    $resolvedPhpPath = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
    $runnerPath = Join-Path $resolvedProjectRoot 'scripts\run_hotel_autopilot_coordinator.ps1'
    $composerPath = Join-Path $resolvedProjectRoot 'composer.json'
    if (-not (Test-Path -LiteralPath $runnerPath -PathType Leaf) `
        -or -not (Test-Path -LiteralPath $composerPath -PathType Leaf)
    ) {
        throw 'project_identity_invalid'
    }

    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    if (-not [Environment]::UserInteractive `
        -or [System.Diagnostics.Process]::GetCurrentProcess().SessionId -le 0 `
        -or -not [string]::Equals($RunAsUser, $currentUser, [System.StringComparison]::OrdinalIgnoreCase)
    ) {
        throw 'interactive_user_required'
    }

    $requiredCommands = @(
        'Get-ScheduledTask',
        'New-ScheduledTaskAction',
        'New-ScheduledTaskTrigger',
        'New-ScheduledTaskPrincipal',
        'New-ScheduledTaskSettingsSet',
        'Enable-ScheduledTask',
        'Register-ScheduledTask'
    )
    if (@($requiredCommands | Where-Object { $null -eq (Get-Command -Name $_ -ErrorAction SilentlyContinue) }).Count -gt 0) {
        throw 'scheduled_tasks_module_unavailable'
    }

    $powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powershellPath -PathType Leaf)) {
        throw 'powershell_binary_missing'
    }
    $expectedArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}"' -f `
        $runnerPath, $resolvedProjectRoot, $resolvedPhpPath

    $initialReadback = Get-ExactCoordinatorTaskReadback `
        -ExpectedPowerShell $powershellPath `
        -ExpectedArguments $expectedArguments `
        -ExpectedWorkingDirectory $resolvedProjectRoot `
        -ExpectedUser $RunAsUser
    $receipt.task_exists = $null -ne $initialReadback.task

    if (-not $Enable) {
        $receipt.action_verified = $initialReadback.action_verified
        $receipt.trigger_verified = $initialReadback.trigger_verified
        $receipt.principal_verified = $initialReadback.principal_verified
        $receipt.enabled_verified = $initialReadback.enabled_verified
        $receipt.readback_verified = $initialReadback.readback_verified
        $receipt.enabled = $initialReadback.enabled_verified
        $receipt.status = if ($initialReadback.readback_verified) { 'ready' } else { 'blocked' }
        $receipt.reason_code = if ($initialReadback.readback_verified) { 'task_already_enabled' } else { 'enable_required' }
        $exitCode = if ($initialReadback.readback_verified) { 0 } else { 1 }
    } else {
        if ($null -ne $initialReadback.task `
            -and -not $initialReadback.readback_verified `
            -and -not $initialReadback.automatic_repair_allowed `
            -and -not $ReplaceExisting
        ) {
            throw 'existing_task_mismatch_requires_replace'
        }

        if ($initialReadback.automatic_repair_allowed) {
            Enable-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop | Out-Null
        } elseif ($null -eq $initialReadback.task -or -not $initialReadback.readback_verified) {
            $nextStart = (Get-Date).AddMinutes(5)
            $taskAction = New-ScheduledTaskAction `
                -Execute $powershellPath `
                -Argument $expectedArguments `
                -WorkingDirectory $resolvedProjectRoot
            # A daily trigger plus a one-day repetition window keeps the
            # five-minute cadence durable across restarts and future days.
            $taskTrigger = New-ScheduledTaskTrigger -Daily -At $nextStart
            $taskTrigger.Repetition = New-FiveMinuteRepetitionPattern
            $taskPrincipal = New-ScheduledTaskPrincipal `
                -UserId $RunAsUser `
                -LogonType Interactive `
                -RunLevel Limited
            $taskSettings = New-ScheduledTaskSettingsSet `
                -MultipleInstances IgnoreNew `
                -StartWhenAvailable `
                -WakeToRun `
                -Hidden `
                -ExecutionTimeLimit (New-TimeSpan -Minutes 4) `
                -AllowStartIfOnBatteries `
                -DontStopIfGoingOnBatteries

            $registrationParameters = @{
                TaskName = $taskName
                TaskPath = $taskPath
                Action = $taskAction
                Trigger = $taskTrigger
                Principal = $taskPrincipal
                Settings = $taskSettings
                Description = 'SUXIOS hotel autopilot lifecycle coordinator. Reconciles lifecycle state and provisions only exact, verified per-hotel dispatchers every five minutes.'
            }
            if ($null -ne $initialReadback.task) {
                $registrationParameters['Force'] = $true
            }
            Register-ScheduledTask @registrationParameters | Out-Null
        }

        $readback = Get-ExactCoordinatorTaskReadback `
            -ExpectedPowerShell $powershellPath `
            -ExpectedArguments $expectedArguments `
            -ExpectedWorkingDirectory $resolvedProjectRoot `
            -ExpectedUser $RunAsUser
        $receipt.task_exists = $null -ne $readback.task
        $receipt.action_verified = $readback.action_verified
        $receipt.trigger_verified = $readback.trigger_verified
        $receipt.principal_verified = $readback.principal_verified
        $receipt.enabled_verified = $readback.enabled_verified
        $receipt.readback_verified = $readback.readback_verified
        $receipt.enabled = $readback.enabled_verified
        if (-not $readback.readback_verified) {
            throw 'scheduled_task_readback_mismatch'
        }

        if ($StartNow) {
            Start-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
            $receipt.task_started = $true
        }

        $receipt.status = 'ready'
        $receipt.reason_code = if ($StartNow) { 'task_enabled_and_started' } else { 'task_enabled' }
        $exitCode = 0
    }
} catch {
    $safeReason = [string]$_.Exception.Message
    if ($safeReason -notmatch '^[a-z0-9_]+$') {
        $safeReason = 'coordinator_task_registration_failed'
    }
    $receipt.status = 'blocked'
    $receipt.reason_code = $safeReason
    $receipt.enabled = $receipt.enabled_verified
}

Write-CoordinatorTaskReceipt -Value $receipt
exit $exitCode
